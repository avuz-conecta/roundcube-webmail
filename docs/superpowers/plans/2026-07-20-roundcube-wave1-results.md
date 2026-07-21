# Roundcube Wave 1 — Staging Results

**Date**: 2026-07-21
**Stack**: `avuz-mail-roundcube-2` (Portainer endpoint 3, port 8091)
**Verdict**: Wave 1 works. Repeat traffic eliminated; first-warm cost unchanged, as designed.

## Deployment

| Item | Verified |
|---|---|
| App image | `avuz-roundcube:staging` (was pinned to `:filters` — see Trap 1) |
| New plugin code | `prefetch_cache.php`, `run_guard.php` present in container |
| `skip_deleted` | reverted — comment only, setting absent |
| Redis ceiling | `maxmemory = 536870912` (512mb) |
| imapproxy | `cache_expiration_time 1800` |
| gzip | `on`, `comp_level 5`, `min_length 1024` |

## Headline numbers

| Measurement | Before (prod, 2026-07-20) | After — first visit | After — revisit |
|---|---|---|---|
| `_action=list` | **84 cmds / 6s** | — | **7 cmds / 1s** |
| `plugin.avuz_prefetch` | 54 cmds / 3s | 336 cmds / 69s | **10 cmds / 3s** |
| Worst list request | **13.34s** | — | 1s |

**~12× fewer IMAP commands on the message-list path for any folder visited more than once.**

The revisit command mix contains **zero `BODYSTRUCTURE` and zero `BODY.PEEK[N.MIME]`** — only
handshake, `SELECT`, `STATUS` and `UID SEARCH`. That is the intended end state.

## Why it did not *feel* faster

The first visit to a folder is unchanged, and that is by design — Wave 1 removes *repeat*
traffic, not the one-time warm. A cold batch of 8 messages cost 336 commands / 69s.

Evening testing was almost entirely first visits, so almost none of the win was exercised. This
is the single most important thing to understand about the result: **the win is real and
measured, but invisible to anyone opening folders for the first time.**

## Corrected diagnosis: the structure walk is one-time, not permanent

An earlier reading of the wire log attributed the 275 `BODY.PEEK[N.MIME]` fetches to a permanent
per-request cost, and a Wave 1.5 rewrite (parsing `BODYSTRUCTURE` ourselves to avoid
`get_structure()`) was nearly specced on that basis. **That was wrong**, twice over:

1. A `LIKE '%structure%'` query against `cache_messages` returned zero rows, suggesting structure
   was never persisted. False negative — `rcube_imap_cache::add_message()` stores
   `$this->db->encode($msg, true)`, i.e. serialize **plus base64**, so the literal string can
   never appear in the column.
2. Decoding an actual row shows `s:9:"structure"` present with 6 `rcube_message_part` objects.
   Structure **is** persisted, so `rcube_imap.php:1943` short-circuits on later encounters.

Confirmed empirically: revisiting a warmed folder issues no `BODYSTRUCTURE` and no `.MIME`
fetches at all. The rewrite would have bought nothing on revisits and was dropped.

## Traps caught during deployment

**Trap 1 — the staging stack was pinned to `:filters`.** `build-push.sh` hardcodes `:staging`
for the staging environment, so the push would have landed on a tag nothing was running. Every
measurement afterwards would have described unchanged software while looking legitimate. Fixed
by moving the stack to `:staging`.

**Trap 2 — `deploy/stack.reference.yml` does not deploy.** `scripts/deploy.sh:100-103` fetches
the stack's *current* file from Portainer and re-sends it unchanged, so the Redis `512mb` edit
never reaches the running stack. Applied via the Portainer API instead, carrying all 6 stack env
vars. Verified `CONFIG GET maxmemory` before trusting any eviction figure.

## Safety checks

| Check | Result |
|---|---|
| Redis `evicted_keys` | **0** (16.77M used of 512M — no pressure) |
| Session keys lost | none — no evictions at all |
| Zoho connection-block errors | **0** |
| Prefetch sentinel integrity | ✅ verified: `…:2427:#done` → `["1.1.1","1.1.2"]`, both body keys present |

The sentinel fix from the final review is confirmed working in production conditions: the value
is the cached mime-id list, not a bare `'1'`, and its bodies are present.

## What remains

The one-time warm is spent badly. `sendBatches()` fires ~4 concurrent POSTs per page, each
performing heavy structure walks, contending with the user's foreground clicks for imapproxy
connections and PHP-FPM workers. A 69-second background request competes with the folder the
user just opened.

That is Wave 1.5 — see `docs/superpowers/specs/2026-07-21-roundcube-prefetch-pacing-design.md`.
It is about *pacing* the one-time cost, not reducing it.

Wave 2 (local search index) is unaffected by these results: search was never touched by Wave 1,
and all-folder search remains ~13s.
