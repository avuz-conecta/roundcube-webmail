# Calendar Invites — Production Rollout Checklist

Status: draft · Date: 2026-09-11 · Owner: Patrick

Feature: accept a Google/Outlook email invite from the Roundcube reading pane →
sends the iTip REPLY over SMTP → writes the event into the user's AvuzConecta
(Nextcloud) calendar via the `conectamail` HMAC-gated endpoint.

Validated end-to-end on staging (shared RC `avuz-mail-roundcube-2` +
`avuz-conecta-2` / image `staging-2`, domain `avuz.cloud`).

---

## Prod topology (why this is phased)

- **Main shared Roundcube** — prod stack **36**, endpoint **5** (`avuz-mail-roundcube`).
  Serves customers via `nc_token` SSO (stored-password path — NOT limited to
  `ZOHO_TENANTS`). The prod map goes here. Runs the `:latest`/versioned image.
- **Second Roundcube** — stack **65**, endpoint **9**, running the **`:staging`**
  image, `ZOHO_TENANTS` = grupovidalar/auster/raiven only. Looks vidalar-specific
  and possibly stale. **CONFIRM** whether any customer still hits it before rollout;
  if it serves live users, it needs the same 1.7.0 image + map, otherwise leave it.
- **AvuzConecta customer Nextcloud instances live ONLY on two hosts:**
  `avuz-conecta-app1` and `avuz-conecta-app2`. Every other stack in the
  Portainer list (arkua, cfm-advogados, comprev, guacamole, rclone, talk, …)
  is a different product/service and is **out of scope** — do not touch.
  All in-scope NC instances share the same `ROUNDCUBE_SSO_SECRET` as RC.
  Build the exact target list from the stacks on those two endpoints only.
- Consequence: the RC image is global (all customers), but each customer's NC
  stack must be redeployed to get the `conectamail` calendar endpoint. Until a
  customer's NC has it, their users must NOT see a working-looking card.

**Control mechanism:** `AVUZ_NC_INSTANCES` (JSON map `email-domain → NC base URL`)
on RC. With the M1 gate (below), the card renders **only** for domains present
in the map. So a customer is "live" the moment their domain is added to the map —
and you add it only *after* their NC is upgraded. The map is the rollout switch.

---

## Prod instance map (resolved 2026-09-13)

Built from `occ user:list` on every NC on app1/app2 (uids are the users' emails).
Public providers (gmail/hotmail/yahoo/terra) are excluded — they are not customer
domains. NC base URL = `https://<NEXTCLOUD_TRUSTED_DOMAIN>`.

| Customer mail domain(s) | NC instance (stack) | NC base URL |
|---|---|---|
| grupovidalar.com.br | grupo-vidalar (60, app2) | https://vidalar.avuz.app |
| austerengenharia.com.br | grupo-vidalar (60, app2 — branded host) | https://auster.avuz.app |
| raiven.com.br | grupo-vidalar (60, app2 — branded host) | https://raiven.avuz.app |
| progetticonstrutora.com.br | progetti (92) | https://progetti.avuz.app |
| digrepal.com.br | digrepal (51) | https://digrepal.avuz.app |
| consulttagro.com | consultt-agro (57) | https://consulttagro.avuz.app |
| ecoambiental-consultoria.com.br, ecoepi.com.br | eco-ambiental (61) | https://eco-ambiental.avuz.app |
| cfmadvocacia.com, ctmadvocacia.com | cfm-advogados (59) | https://cfm.avuz.app |
| arkua.com.br | arkua (81) | https://arkua.avuz.app |
| dellannopassofundo.com.br, agfeleuterio.com.br, tiagoguerreiro.com.br, eggies.com.br | ramires (70) | https://ramires.avuz.app |
| comprev.seg.br | comprev (69) | https://comprev.avuz.app |

16 domains → 9 instances. The `AVUZ_NC_INSTANCES` JSON value:

```json
{"grupovidalar.com.br":"https://vidalar.avuz.app","austerengenharia.com.br":"https://auster.avuz.app","raiven.com.br":"https://raiven.avuz.app","progetticonstrutora.com.br":"https://progetti.avuz.app","digrepal.com.br":"https://digrepal.avuz.app","consulttagro.com":"https://consulttagro.avuz.app","ecoambiental-consultoria.com.br":"https://eco-ambiental.avuz.app","ecoepi.com.br":"https://eco-ambiental.avuz.app","cfmadvocacia.com":"https://cfm.avuz.app","ctmadvocacia.com":"https://cfm.avuz.app","arkua.com.br":"https://arkua.avuz.app","dellannopassofundo.com.br":"https://ramires.avuz.app","agfeleuterio.com.br":"https://ramires.avuz.app","tiagoguerreiro.com.br":"https://ramires.avuz.app","eggies.com.br":"https://ramires.avuz.app","comprev.seg.br":"https://comprev.avuz.app"}
```

Note: grupovidalar/auster/raiven are one NC instance (grupo-vidalar, stack 60)
reached via three branded `*.avuz.app` hosts — each mail domain maps to its own
branded host (all verified HTTP 200, NC 33.0.8.2). The calendar POST works to any
of them since they hit the same container + user DB.

**Not mapped (no org domain — every user is on a public provider):** `endopasso`
(gmail/terra), `cartorio-veranopolis` (yahoo). The M1 gate means their users see
no card — safe. Revisit if they migrate to an org domain. `avuz-app3` = internal
(`avuz.cloud`), add only if you want the feature there.

**Rollout order (phased via the map):** deploy the NC 1.7.0 image to each instance,
then add that instance's domain(s) to the map. You do NOT have to add all 16 at
once — the map is per-line, so add rows as each NC is confirmed on the new image.

## Phase 0 — Preconditions (do before any prod deploy)

- [x] **M1 gate merged** (`49102ac0a`): card renders only when the user's
      email-domain is in `avuz_nc_instances`. This is the gate that makes phased
      rollout safe. Deployed + verified on staging.
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
