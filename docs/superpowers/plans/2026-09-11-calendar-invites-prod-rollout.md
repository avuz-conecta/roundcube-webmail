# Calendar Invites — Production Rollout Checklist

Status: draft · Date: 2026-09-11 · Owner: Patrick

Feature: accept a Google/Outlook email invite from the Roundcube reading pane →
sends the iTip REPLY over SMTP → writes the event into the user's AvuzConecta
(Nextcloud) calendar via the `conectamail` HMAC-gated endpoint.

Validated end-to-end on staging (shared RC `avuz-mail-roundcube-2` +
`avuz-conecta-2` / image `staging-2`, domain `avuz.cloud`).

---

## Prod topology (why this is phased)

- **One shared Roundcube** — prod stack **36**, endpoint **5** (`avuz-mail-roundcube`).
  Serves every customer via `nc_token` SSO. (Stack **65** / endpoint **9** is a
  second, unidentified RC — leave untouched; confirm it's not customer-facing.)
- **Many separate customer Nextcloud instances** — one stack per customer,
  spread across endpoints (arkua, cfm-advogados, comprev, consultt-agro,
  digrepal, eco-ambiental, endopasso, grupo-vidalar, progetti, ramires,
  app360, office01–04, …). All share the same `ROUNDCUBE_SSO_SECRET` as RC.
- Consequence: the RC image is global (all customers), but each customer's NC
  stack must be redeployed to get the `conectamail` calendar endpoint. Until a
  customer's NC has it, their users must NOT see a working-looking card.

**Control mechanism:** `AVUZ_NC_INSTANCES` (JSON map `email-domain → NC base URL`)
on RC. With the M1 gate (below), the card renders **only** for domains present
in the map. So a customer is "live" the moment their domain is added to the map —
and you add it only *after* their NC is upgraded. The map is the rollout switch.

---

## Phase 0 — Preconditions (do before any prod deploy)

- [ ] **M1 gate merged**: card renders only when the user's email-domain is in
      `avuz_nc_instances`. Without it, every customer on the shared RC sees the
      card before their NC is ready. This is the gate that makes phased rollout
      safe. (RC change; small.)
- [ ] Confirm `ROUNDCUBE_SSO_SECRET` is identical on RC stack 36 and on each
      target customer NC (it already is — same secret powers existing SSO).
- [ ] Confirm the second RC (stack 65 / endpoint 9) is not customer-facing, or
      include it in the plan.
- [ ] `git log` clean: RC `avuz-customization` pushed (done: `4c2d9e8a2`),
      avuz-server `avuz-customization` pushed (done: `2ce79c0bc1c`).

## Phase 1 — Build prod images

- [ ] **avuz-server**: `protocol_log` / debug OFF; then
      `./scripts/build-push.sh latest prod` → pushes `avuzconecta:latest`
      (contains `conectamail` calendar endpoint). One image, used by all NC stacks.
- [ ] **roundcube**: `ROUNDCUBE_DEBUG` unset in prod (no IMAP/SMTP wire logs —
      they capture passwords); then `./scripts/build-push.sh <ver> prod` →
      pushes `avuz-roundcube:latest` (contains `avuz_calendar` plugin).
- [ ] Do NOT redeploy anything yet.

## Phase 2 — Pilot ONE customer

Pick a low-risk pilot with a known-good calendar (candidate: `eco-ambiental` or
`grupo-vidalar`). Need: pilot **email-domain** + pilot **NC base URL**.

- [ ] Redeploy the pilot's **NC** stack to pull `avuzconecta:latest`
      (`conectamail` now serves `POST /api/calendar/events`).
- [ ] Set `AVUZ_NC_INSTANCES` on **RC stack 36** to include ONLY the pilot:
      `{"pilotdomain.com":"https://pilot-nc.avuz.app"}` (Portainer → stack 36 →
      env; preserve all other env; PullImage true).
- [ ] Redeploy **RC stack 36** onto `avuz-roundcube:latest`.
- [ ] Smoke test as a pilot user:
  - [ ] Real Google invite → card renders (three readable buttons).
  - [ ] Accept → organizer's reply lands in **Inbox** (not spam); their event
        shows the attendee accepted.
  - [ ] Event appears on the pilot user's AvuzConecta calendar, already accepted,
        no second prompt, no duplicate reply.
  - [ ] A user on a **non-pilot** domain sees **no card** (M1 gate holds).
- [ ] Watch RC + pilot NC logs for errors for ~1 day.

## Phase 3 — Fan out

For each remaining customer, in batches:

- [ ] Redeploy the customer's NC stack to `avuzconecta:latest`.
- [ ] Add the customer's `domain → NC URL` to `AVUZ_NC_INSTANCES` on RC stack 36.
- [ ] Redeploy RC stack 36 (or one final redeploy after the full map is built).
- [ ] Spot-check one invite per customer.

Keep a table here of `customer | email-domain | NC base URL | NC redeployed | in map | verified`.

## Rollback

- **Per-customer:** remove their domain from `AVUZ_NC_INSTANCES` + redeploy RC →
  card stops showing for them; nothing else affected.
- **Whole feature:** redeploy RC stack 36 on the previous `avuz-roundcube` image;
  the `conectamail` endpoint on NC is inert without RC calling it (safe to leave).
- The endpoint is public but HMAC-gated (shared secret, 300s replay window,
  body-hash bound) — no auth surface added for end users.

## Open items / notes

- **M1** (card on unknown domain) — must land in Phase 0.
- Deliverability: replies are `multipart/alternative` + threaded (In-Reply-To)
  and avuz.cloud passes SPF/DKIM/DMARC. Each customer domain should already have
  SPF+DKIM for Zoho (their normal mail flows); if a customer's outbound isn't
  DKIM-signed, RSVP replies may spam for that customer — verify per domain.
- The worktree-synced `apps/*` + `3rdparty` in the calendar-invites worktree are
  untracked and were NOT merged (correct) — the prod NC image is built normally
  from the primary checkout.
