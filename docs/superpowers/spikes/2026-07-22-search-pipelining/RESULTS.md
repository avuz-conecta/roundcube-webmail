# All-folder search: re-measurement + pipelining spike — RESULTS

**Date**: 2026-07-22
**Branch**: `avuz-customization`
**Answers steps 1 and 2 of** `../../specs/2026-07-22-search-latency-design.md`
**Nothing was deployed. Nothing on prod or staging was modified. No product code was changed.**

---

## Headline

1. **All-folder search is NOT half-fixed by 1.0.3. It got worse.** Measured on prod, live traffic:
   median **15.5s**, worst observed **212.3s** for a single user search. The stale figure being
   replaced was 12.82s.
2. **Pipelining works.** Proven end-to-end with the real `rcube_imap_generic` class through the real
   `up-imapproxy` build, at a simulated 198ms RTT: **107 folders, 44.96s serial → 0.52s pipelined,
   identical result sets, tags returned in strict send order.**
3. **Confirmed against real Zoho.** Candidate C is now implemented and the ordering gate has passed
   on a live 107-folder account: **55.2s → 6.4s (8.6x), 200 matched messages in exactly the same
   folders as a serial run.** See "Post-implementation" below.
4. **Recommendation: build candidate C. Do not build B. Do not build A.**

---

## STEP 1 — Re-measurement (prod, read-only)

Source: `/var/www/roundcube/logs/php-perf.log` on `avuz-mail-roundcube-roundcube-1` (endpoint 5),
9.2-hour window, epoch 1784734154 → 1784767251. Read only; no writes, no config changes.

### All-folder search is used today, and it is slow

`_scope=all` appears **11 times** from **4 distinct sessions** in 9.2 hours. It is not the default
and is still reached often enough to matter.

PHP wall time per HTTP request, `_scope=all` with a query term (n=10, sorted):

    6.083  7.896  10.074  15.547  31.678  50.457  52.293  59.568  60.127  60.890

Requests are not the right unit — one *user* search can span several requests via the
`_continue` mechanism. Grouped into **logical searches** (n=7):

| Session | Wall time | Requests | Search type |
|---|---|---|---|
| `e27b332c` | 6.1s | 1 | subject+from |
| `45a49216` | 7.9s | 1 | `text` (whole message) |
| `45a49216` | 10.1s | 1 | `text` (whole message) |
| `719b26f3` | 15.5s | 1 | subject+from+to+cc+bcc+body |
| `719b26f3` | 50.5s | 1 | subject+from+to+cc+bcc+body |
| `719b26f3` | 52.3s | 1 | subject+from+to+cc+bcc+body |
| `03fe47ca` | **212.3s** | **4** | subject+from |

**Median 15.5s. p95 ≈ 212s.** Versus the 12.82s figure the design doc marked stale. Wave 1/1.5,
gzip, `refresh_interval=120` and the imapproxy cache change did not touch this path — they attack
connection warm-up and refresh volume, and all-folder search is neither.

### The 212s case, in full

Session `03fe47ca`, one search for `SOMOS`, `_headers=subject,from`, `_scope=all`:

    1784741908.448  60.890  search  ..._q=SOMOS..._scope=all
    1784741969.495  60.127  search  ..._q=SOMOS..._scope=all&_continue=0b9bd3b9...
    1784742029.878  59.568  search  ..._q=SOMOS..._scope=all&_continue=0b9bd3b9...
    1784742089.594  31.678  search  ..._q=SOMOS..._scope=all&_continue=0b9bd3b9...

Three consecutive 60s ceilings then a 31.7s tail. That shape is exactly `N_folders × 2 RTT`
against the 60s cap, and it is consistent with a ~107-folder account (the handoff records
`auxadm@grupovidalar.com.br` at 107 IMAP folders).

### Single-folder search, for calibration

`_scope=base` with a query term (n=22, sorted):

    0.039 0.045 0.050 0.799 0.819 0.819 0.874 0.903 0.920 0.932 0.942 0.947 0.968
    1.387 1.569 1.774 2.340 2.660 15.102 15.493 15.561 15.566

Median **0.92s** for a whole HTTP request that does SELECT + SEARCH + list the first page.
Two IMAP round trips at ~198ms ≈ 0.40s of that. **Zoho's per-folder SEARCH compute is small; the
cost is round-trip count.** This is the premise the pipelining candidate rests on, and it now has
current evidence rather than the 2026-07-20 figure.

**Open anomaly, not resolved here:** the four base-scope outliers cluster inside 464ms of each
other (15.102 / 15.493 / 15.561 / 15.566) across two different users and two different folders.
Organic Zoho search cost would not land that tightly. That looks like a fixed ~15.5s timeout
somewhere in the path, not slow search. Worth a separate look; it is a different bug from this one.

### Fresh Zoho CAPABILITY (the design doc's "could not verify" #1)

Captured twice today. Through imapproxy, from prod `logs/imap.log`, 22:46 UTC:

    IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE MOVE ID UIDPLUS ESEARCH
    LIST-EXTENDED LIST-STATUS WITHIN LITERAL- ACL CONDSTORE XIMAPPROXY

Direct to `imap.zoho.com:993`, no proxy, pre-auth:

    IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE IDLE MOVE ID AUTH=PLAIN SASL-IR
    AUTH=XOAUTH2 UIDPLUS ESEARCH LIST-EXTENDED LIST-STATUS WITHIN LITERAL- ACL CONDSTORE

Still **no `SORT`, no `THREAD`, no `MULTISEARCH`, no `QRESYNC`, no `COMPRESS=DEFLATE`**. Nothing
changed; `MULTISEARCH` stays rejected. One item that *is* new and load-bearing: **`LITERAL-`**
(RFC 7888) is advertised on both sides, so literals ≤4096 bytes are non-synchronizing — a
non-ASCII search term does not force a continuation round trip mid-pipeline. Portuguese search
terms would otherwise have broken the whole idea.

---

## STEP 2 — Pipelining spike

**Binary question: does pipelined SELECT+SEARCH work against Zoho through imapproxy?**
**Answer: yes.** One leg — Zoho's *authenticated* ordering — was still open when this section was
written; it was closed the same day and is recorded under "Post-implementation".

Three unknowns had to fall. They were attacked separately.

### 2a. Can `rcube_imap_generic` express it? — Yes, and it needs no invasive surgery

`execute()` (`program/lib/Roundcube/rcube_imap_generic.php:3945`) is one `putLineC()` write followed
by a `do { readFullLine() } while (!startsWith($line, $tag))` read loop. Write and read are already
separate; nothing between them holds state. A pipelined variant reuses both halves unchanged:
write every tagged command first, then run the same read loop once per tag, in send order.

The spike proved this **without editing a single repository file** — it subclasses
`rcube_imap_generic` and uses only its existing `protected` I/O (`nextTag`, `putLineC`,
`readFullLine`, `startsWith`, `parseResult`). See `php_spike.php`; the core is ~30 lines.

`putLineC` already implements the `LITERAL-` non-synchronizing path
(`rcube_imap_generic.php:150-158`), so it is pipeline-safe as-is for literals ≤4096.

Two things a production patch must handle that the spike deliberately did not:
- `$this->selected` and `$this->data[...]` (EXISTS/UIDNEXT/UIDVALIDITY/HIGHESTMODSEQ) are left
  pointing at whatever was selected last. They must be reset, or a later `select()` will short-
  circuit on a stale value.
- `search()`'s empty-folder optimisation (`:1999-2002`, skip SEARCH when `EXISTS` is 0) cannot
  apply — the pipeline commits to the SEARCH before it has seen the SELECT reply. That costs
  nothing in round trips; it costs Zoho one trivial extra SEARCH per empty folder.

### 2b. Does `up-imapproxy` relay pipelined commands unmangled? — Yes

Built the **same Debian package the production sidecar uses** (`imapproxy`,
`1.2.8~svn20171105-2+b2`, from `debian:12-slim` — identical to `docker/imapproxy-sidecar/Dockerfile`),
in front of a local Dovecot, with a 99ms-each-way shim to simulate the Brazil↔Zoho RTT.

All `N × (SELECT, UID SEARCH)` pairs written in **one** `write()`. Results, real `rcube_imap_generic`:

| Folders | Serial | Pipelined | Speedup | Result sets |
|---|---|---|---|---|
| 5 | 2,274 ms | 414 ms | 5.5× | identical |
| 26 | 11,056 ms | 422 ms | **26×** | identical |
| 107 | 44,961 ms | 517 ms | **87×** | identical |

Tag order returned was strictly the send order in every run (`A0217 A0218 … A0430` for the
107-folder case). No mangling, no reordering, no desync, no dropped untagged data.

Note how well the serial column reproduces prod: 26 folders → 11.1s against prod's 10.1s and 15.5s
observations; 107 folders → 45.0s against prod's 50.5s / 52.3s / 60s-capped. **The model
`t = N × 2 × RTT` is confirmed, and pipelining collapses it to ~1 RTT.**

### 2c. Does Zoho process pipelined commands in order? — Yes, on the evidence obtainable without a mailbox

Direct TLS to `imap.zoho.com:993`, three commands in a single `sendall()`:

    z1 CAPABILITY / z2 NOOP / z3 CAPABILITY

All three replies came back **in strict tag order, in a single 234ms round trip** (`zoho_probe.py`).
Zoho parses multiple commands out of one TCP segment, does not require a reply between them, and
does not reorder. That is the server's command loop behaving serially.

**What this does not prove:** it is pre-auth. It does not prove the *authenticated, selected-state*
machine keeps SELECT and SEARCH ordered, which is the case RFC 3501 §5.5 actually warns about.
Closing that needed one authenticated run against Zoho with a real mailbox password, which this work
did not have at the time. **It was run later the same day and passed** — see "Against real Zoho,
authenticated" below.

### 2d. The nasty failure mode, tested

If a `SELECT` in the middle of a pipeline fails, does the following `SEARCH` silently return the
*previous* folder's results? Tested (`failmode.py`):

    f1s NO  Mailbox doesn't exist: DoesNotExist
    f1q BAD No mailbox selected

**No.** RFC 3501 requires a failed SELECT to leave no mailbox selected, so the dependent SEARCH
fails loudly instead of answering from the wrong folder, and the pipeline stays in sync — the
remaining tags (`f2s`, `f2q`) still arrived in order and returned correct data. A production patch
must map that `BAD` to "this folder failed" rather than "this folder has no matches", and must
force the connection closed on any tag that arrives out of order.

---

## The 60s time limit — status: still there, and the design doc mis-describes it

`rcube_imap.php:1655` still sets `set_timelimit(60)`. Confirmed present on this branch.

**But it does not silently return partial results.** Reading the actual path:

- `rcube_imap_search::exec()` runs jobs until the limit, then adds the un-run jobs' results with
  `incomplete = true`.
- `program/actions/mail/search.php:125` skips `list_messages()` entirely when `incomplete` is set.
- `search.php:155` sets `$count = 0` — the comment says *keep UI locked* — and emits
  `continue_search`.
- `app.js:5550` re-issues the same search with `_continue=<request_id>`, unbounded, every 100ms
  after each reply, showing the `stillsearching` busy lock.
- Completed folders are carried forward via `$_SESSION['search']` + `set_results()`, so folders are
  not re-searched and the final answer is complete and correct.

So the real failure mode is **not lost mail — it is an unbounded, invisible retry loop**. Session
`03fe47ca` spent 212.3s locked behind a spinner for one search. That is worse for the user than a
truncated result and better for correctness.

**Consequence for the design doc:** the claim that `set_timelimit(60)` is a correctness blocker for
all-folder-default should be corrected. It is a *latency* blocker, and candidate C removes it by
construction — at ~0.5s per all-folder search, the 60s limit is never reached and the continuation
loop never runs. No UI work is needed for truncation, because there is no truncation.

---

## Recommendation

**Proceed to candidate C (pipelined SELECT+SEARCH). Do not build B. Do not build A.**

Against the standing constraint "designs must reduce backend work; more concurrency against Zoho
makes things worse", C is the only candidate that is strictly neutral-to-better:

| | Zoho work | Zoho concurrency | New state | Serves `body`/`TEXT` | Measured effect |
|---|---|---|---|---|---|
| **C — pipelining** | unchanged (+1 trivial SEARCH per empty folder) | **unchanged — one connection** | none | yes | 45.0s → 0.52s @107 folders |
| B — progressive fan-out | unchanged | **3× peak** | none | yes | first result ~0.5s, completion ~2.7s |
| A — Postgres index | near zero at steady state, huge backfill | +backfill | large, permanent | **no** | unmeasured |

C wins on every column that the handoff says matters, and it is the smallest change of the three.
B's only remaining advantage was time-to-first-result; at a 0.52s completion that advantage is
gone, and its concurrency cost is not. A is not justified on any number measured today: search
volume is ~7 all-folder searches per 9 hours, and 5 of the 11 observed `_scope=all` requests were
`body`/`TEXT` searches that A structurally cannot serve.

**Sequencing:**

1. ~~Run the one authenticated Zoho ordering test (2c above).~~ **DONE 2026-07-22, passed.**
2. Implement C as `rcube_imap_search::exec()` calling a new pipelined method, chunked (~25 command
   pairs per batch, so one enormous write can't stall on a socket buffer), with a hard rule: any
   tag out of order ⇒ close the connection and fall back to the existing serial path. The fallback
   makes it safe to ship even if Zoho misbehaves on some future day.
3. Keep `set_timelimit(60)` as-is. It becomes unreachable rather than removed.
4. Measure on staging before prod: p50/p95 of `_action=search&_scope=all` in 5-minute buckets, and
   **result-set equality against a serial run** as a correctness gate, not a performance one.
5. Revisit all-folder-as-default only after that measurement.

**Defer A indefinitely.** Revisit only if search volume rises by an order of magnitude *and* the
body/`TEXT` share turns out to be small. Neither is true today.

---

## What is left unproven

1. ~~**Zoho's authenticated SELECT→SEARCH ordering.**~~ **CLOSED 2026-07-22** — 107 folders,
   200 matches, result sets identical to a serial run. No technical gate remains for C.
2. **The ~15.5s single-folder cluster.** Four samples inside 464ms across two users and two
   folders. Smells like a fixed timeout, not search cost. Separate investigation.
3. **Real per-user folder counts.** The 107 figure is carried from the handoff's wire capture, not
   re-derived. `cache_index` counts only opened folders and has already produced one wrong
   conclusion — do not use it.
4. **Why 1.0.3 left this path untouched.** Expected, since none of its changes are on the search
   path, but it was assumed rather than instrumented.

## Post-implementation (2026-07-22, branch `claude/search-pipelining`)

Candidate C is implemented. `integration_test.php` drives the real
`rcube_imap_search::exec()` — not a bespoke spike class — against the harness at a simulated
198ms RTT, once with `AVUZ_PIPELINED_SEARCH=1` and once with `=0`, and compares the two answers:

    folders   : 107
    serial    :  44806.9 ms
    pipelined :   1465.1 ms
    speedup   : 30.6x
    IDENTICAL : yes

**Batch size was measured, not guessed.** `measure_bytes.py` puts reply volume at **455 bytes per
folder** — the fixed `SELECT` reply dominates, since `ESEARCH RETURN (ALL)` compacts the result to
ranges. A batch is written before anything is read, so a batch's replies must fit the socket
buffers; `SEARCH_PIPELINE_CHUNK = 50` buffers ~23 kB, a wide margin under a 64 kB receive buffer.

| Batch size | 107 folders | Identical |
|---|---|---|
| 25 | 2,397.7 ms | yes |
| **50** | **1,465.1 ms** | yes |
| 107 (unbatched) | 627.4 ms | yes |

Unbatched is fastest and is the number the pre-implementation spike reported, but it buffers
~48 kB for a 107-folder account — too close to a 64 kB buffer to be safe on an account with large
result sets. 50 keeps most of the win with room to spare.

Two things learned while implementing:

- **A stalled write cannot hang forever.** `rcube_imap_generic::connect()` calls
  `stream_set_timeout()` (`:1080`), which covers writes as well as reads, so an oversized batch
  degrades to a timeout and then the serial fallback. That is a backstop, not a licence to skip
  batching — a timeout stall is the exact pathology this work removes.
- **A unix socket pair holds only ~8 kB on macOS.** The unit tests pre-load the server's replies
  before the call, so a large canned payload deadlocks the *test harness*. Multi-batch behaviour is
  therefore asserted by the write boundary (no replies needed) plus this end-to-end run.

### Against real Zoho, authenticated (2026-07-22)

`zoho_ordering_gate.php`, direct TLS to `imap.zoho.com:993`, a real 107-folder account, the real
`rcube_imap_search::exec()` run twice — pipelined off, then on:

Run 1, a term matching nothing:

    folders   : 107
    matches   : 0
    serial    :  48441.0 ms
    pipelined :   6879.5 ms
    speedup   : 7.0x
    IDENTICAL : yes

Run 2, `nota` — a term that actually hits mail:

    folders   : 107
    matches   : 200
    serial    :  55150.8 ms
    pipelined :   6434.9 ms
    speedup   : 8.6x
    IDENTICAL : yes

**GATE PASSED. Zoho keeps a pipelined batch in order on an authenticated, selected-state
connection.** 107 `SELECT`+`UID SEARCH` pairs across 3 batches, every tag in send order, no desync,
no fallback. That was the last open question from the pre-implementation spike.

Both runs were needed, and run 1 alone would have been misleading: with zero matches, `IDENTICAL`
only compares 107 empty sets against 107 empty sets, so a bug attributing folder N's hits to folder
N-1 stays invisible. **Run 2 is the real correctness evidence** — 200 matched messages landing in
exactly the same folders as a serial run. Any future re-run of this gate must use a term that
matches; check the `matches` line before trusting `IDENTICAL`.

**The production expectation is ~6.5s, not the 1.5s the local harness suggested.** Pipelining
removes round trips, not Zoho's own per-folder SEARCH compute, and dovecot's search is effectively
free while Zoho's is not. Note the pipelined time barely moved between the two runs (6.88s → 6.43s)
while the serial time rose with the extra work (48.4s → 55.2s): what is left after pipelining is
almost entirely Zoho-side search compute, and it is now the floor. The serial figures also bracket
the 50-61s observed on prod, so the account and the method are representative.

Shipped, **not deployed**:

| Piece | Where |
|---|---|
| `searchParams()`, `readPipelined()`, `searchMulti()`, `SEARCH_PIPELINE_CHUNK` | `program/lib/Roundcube/rcube_imap_generic.php` |
| `run_pipelined()` + job accessors, serial fallback | `program/lib/Roundcube/rcube_imap_search.php` |
| 26 behaviour tests | `tests/Framework/ImapGenericPipelined.php`, `tests/Framework/ImapSearchPipelined.php` |
| Kill switch | `AVUZ_PIPELINED_SEARCH=0` in the stack — no rebuild |

`rcube_imap.php` is untouched: `set_timelimit(60)` at `:1655` stays, and simply stops being
reachable at ~1.5s per search.

## Reproducing

    docker compose up -d --build          # dovecot + the production up-imapproxy build
    python3 pipe_test.py --seed
    python3 seed_many.py                  # 107 folders
    python3 latency_proxy.py 1144 1143 99 &   # 198ms simulated RTT
    php php_spike.php 1144                # real rcube_imap_generic, serial vs pipelined
    python3 failmode.py                   # failed SELECT mid-pipeline
    python3 zoho_probe.py                 # Zoho pre-auth pipelining, no credentials needed
    python3 measure_bytes.py              # reply bytes per folder, which sizes the batch
    php integration_test.php 1144         # real rcube_imap_search::exec(), pipelined vs serial

All local. `php_spike.php` reads the repository's `rcube_imap_generic.php` but never writes to it.
