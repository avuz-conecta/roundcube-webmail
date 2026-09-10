# Email calendar invites → AvuzConecta calendar (RSVP + add)

**Date**: 2026-09-09
**Status**: design, approved — ready to plan
**Scope**: let a Roundcube user respond to a meeting invite (RSVP) and add it to their
AvuzConecta (Nextcloud) calendar, working both inside the Nextcloud iframe and standalone.

## Problem

Roundcube (Avuz Conecta branded webmail) has **no** calendar/iTip handling — a meeting
invite (e.g. Google Meet) shows only as a raw `.ics` attachment. There is no way to RSVP or
to add the event to the user's AvuzConecta calendar. AvuzConecta is a **white-label Nextcloud,
one instance per customer/org, each on its own subdomain**; the webmail is one shared
deployment embedded in each instance's iframe AND also used standalone.

## Goal

Full RSVP (iTip) experience:
- Detect a `text/calendar; method=REQUEST` invite and show **Aceitar / Talvez / Recusar**.
- Send an iTip `REPLY` to the organizer (so Google marks the user attending).
- On Accept/Tentative, add the event to the user's AvuzConecta calendar — to the **correct
  customer instance**, identically in-iframe and standalone.

## Approach (chosen: light custom plugin + companion-app write)

Rejected alternatives:
- **Roundcube `calendar` + CalDAV per instance** — the Kolab calendar plugin is heavy (full
  calendar UI we don't need) and needs each user's NC credentials (app-passwords) wired
  per-tenant. Duplicates NC's own calendar.
- **Deep-link to NC's calendar import** — context switch out of the mail view, RSVP happens in
  NC, awkward standalone auth. Weak UX.

Chosen: a small Roundcube plugin using only the iTip **library**, RSVP over the SMTP we already
have, and the calendar write done server-to-server through each instance's Nextcloud companion
app — reusing the existing SSO trust model.

## Components

**A. `avuz_calendar` — new Roundcube plugin** (this repo)
- Depends on `libcalendaring` (Kolab's iCal/iTip *library* only — NOT the `calendar` UI plugin).
- Message-view hook: for a `text/calendar; method=REQUEST` part, parse the VEVENT and render an
  invite card — summary, start/end, organizer, location, Google Meet link, and Aceitar / Talvez
  / Recusar.
- On RSVP: (1) generate the iTip `REPLY` with the user's `PARTSTAT`, send via Zoho SMTP;
  (2) on Accept/Tentative, POST the event to the user's NC instance to add it.

**B. Calendar-import endpoint — Nextcloud companion app** (`avuz-server`, `apps/roundcube`)
- `POST /apps/roundcube/calendar/import`.
- Verify HMAC (shared `ROUNDCUBE_SSO_SECRET`), map email → NC user, upsert the VEVENT into that
  user's default calendar via Nextcloud's own Calendar API.

**C. Config — instance map** (this repo)
- New env `AVUZ_NC_INSTANCES`: JSON `{ "<email-domain>": "https://<subdomain>" }`, same pattern
  as `ZOHO_TENANTS`. Resolves which companion app to call, in-iframe and standalone alike.

## Data flow

**Read:** open invite → plugin parses VEVENT → renders card with Aceitar / Talvez / Recusar.

**Respond (Aceitar/Talvez):**
1. Build iTip `REPLY` (`PARTSTAT` = ACCEPTED/TENTATIVE) → email organizer over Zoho SMTP.
   *Instance-independent; the organizer sees the response.*
2. Resolve NC instance from the logged-in email's domain via `AVUZ_NC_INSTANCES`.
3. POST `{ ics, email, signature }` to `<instance>/apps/roundcube/calendar/import`.
4. NC app verifies signature, maps email → NC user, upserts event into the default calendar.
5. Toast per outcome; a calendar-add failure is reported separately from the (already-sent) reply.

**Recusar:** send the `DECLINED` reply; **skip** the calendar write.

Two independent outcomes per action — **reply** (SMTP, always) and **calendar add** (NC,
accept/tentative only) — reported separately so one failing never sinks the other.

## Instance resolution, auth & security

- **Resolution:** email → domain → `AVUZ_NC_INSTANCES[domain]` → NC base URL. Unknown domain →
  no calendar button (RSVP reply still works). No reliance on the parent window.
- **Signing (Roundcube→NC):** HMAC-SHA256 over `email` + SHA-256(`.ics`) + issued-at + nonce,
  keyed by the shared `ROUNDCUBE_SSO_SECRET`; sent as a header, raw `.ics` in the body.
- **Verification (NC):** recompute HMAC (constant-time); reject if timestamp older than ~5 min
  (replay window; nonce optional); map email → NC user (404 if none); write ONLY to that user's
  own default calendar.
- **Cross-user safety:** Roundcube signs ONLY for the authenticated session's own email — it
  never takes a target email from the client. Forged requests can't cross users; unsigned
  requests can't be made at all.
- **Transport:** HTTPS to the NC subdomain. The `.ics` is meeting data, no credentials; the
  secret stays server-side on both ends.
- **Failure isolation:** NC unreachable / user-not-found / write error → card shows the add
  failed; the RSVP reply is untouched.

## Scope

**v1 in:**
- `text/calendar; method=REQUEST` detection + invite card (Aceitar / Talvez / Recusar).
- iTip `REPLY` over SMTP for all three responses.
- Add to NC **default** calendar on Accept/Tentative.
- **Upsert by `UID`** — a re-sent/updated invite updates the event, never duplicates.
- Single AND recurring events — pass VEVENT (incl. `RRULE`) through; NC stores recurrence.
- Surface the Google Meet link.

**Out (later):**
- `CANCEL` auto-remove; choosing a non-default calendar; organizer-side `REPLY` aggregation;
  `COUNTER` (propose new time); plain non-invite `.ics` attachments.

## Testing

**Roundcube plugin (unit, real `.ics` fixtures):** Google Meet invite → correct card + a
`REPLY` with the right `PARTSTAT` addressed to the organizer; recurring invite passes `RRULE`
through; known/unknown domain → button present/absent; HMAC matches a known vector.

**NC endpoint:** valid signature writes; tampered/expired → 401; unknown user → 404; upsert
(same UID twice → one updated event); write lands only in the authenticated user's default
calendar.

**End-to-end on staging:** send a real Google Meet invite to a staging account → Accept →
confirm the organizer receives the reply AND the event appears in that user's AvuzConecta
calendar on the correct instance. Repeat with Roundcube opened standalone (not in the iframe)
to prove instance resolution works without the parent window.

## Open items for the plan

- Confirm the Nextcloud Calendar write path in `apps/roundcube` (CalendarManager / CalDAV
  backend API) and how "default calendar" is chosen for a user.
- Confirm `libcalendaring` can be vendored/enabled without pulling the full `calendar` plugin.
- Decide nonce store (skip for v1 if the 5-min window is deemed enough).
