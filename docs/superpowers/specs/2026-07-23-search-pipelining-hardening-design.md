# Making pipelined search safe enough to enable

**Date**: 2026-07-23
**Status**: design, not implemented
**Builds on**: `2026-07-22-search-latency-design.md` (the original candidate analysis)
**Subject**: the pipelined multi-folder search shipped in `1.0.4` and **disabled** via
`AVUZ_PIPELINED_SEARCH=0`

## Where this starts

Pipelined `SELECT`+`SEARCH` is in the `1.0.4` prod image but switched off. It was enabled on
staging for roughly two hours on 2026-07-22 and, in that window, produced two distinct user-visible
errors and corrupted shared infrastructure. Prod has never run it.

Nothing here argues against pipelining. The measured win is real — a spike against the production
imapproxy build at a simulated 198ms RTT took a 107-folder search from 44.96s to 0.52s, with
identical result sets. The problem is that the implementation is unsafe in four specific ways, and
three of them were invisible until it met a real mailbox, a real proxy, and a Portuguese-speaking
user.

## Requirement

All-folder search must be materially faster than the serial path, and must never: return a wrong or
silently-incomplete answer, surface an internal fallback to the user as a server error, or leave
shared state worse than it found it.

The last clause is new. It was not in the original design, and it is the one that matters most.

## The four defects

### D1 — A desync poisons imapproxy's connection pool, across users

**Severity: highest. This is the one that changes the risk class of the whole feature.**

When `readPipelined()` detects an out-of-order tag it calls `closeSocket()` and returns false. That
closes *Roundcube's* side. The upstream Zoho connection stays in imapproxy's cache **with unread
replies still buffered on it**, and imapproxy hands it to whoever asks next.

Captured on staging, on a connection that had done nothing wrong:

    C: A0001 CAPABILITY
    S: * CAPABILITY IMAP4rev1 ... LITERAL- ... XIMAPPROXY
    S: A0001 OK Completed
    C: A0002 LOGIN ******
    S: * OK [XPROXYREUSE] IMAP connection reused by imapproxy
    S: A0002 OK User logged in
    C: A0003 ID ("name" "Roundcube" ...)
    S: A0005 BAD [CLIENTBUG] syntax: expecting 'c', found 'u'

An `ID` command answered with tag `A0005` and someone else's search error. Tag mismatches were
observed on multiple connections (`sent=A0003 got=A0005`), and **four distinct sessions** read BAD
replies to commands they never sent.

Consequences are arbitrary, not confined to search: any command can receive a reply meant for
another. A user can see an error, an empty folder, or wrong data with no connection to anything
they did.

Confirmed by the remedy: restarting **only** the imapproxy sidecar cleared every symptom. The
corruption lives in the proxy's cache, not in Roundcube.

**Design.** A desynchronised connection must be resynchronised or destroyed before it can be
reused — never merely abandoned.

1. On desync, do not close immediately. Send a uniquely-tagged `NOOP` and read-and-discard until
   that tag appears, with a bounded read budget (a byte cap and a time cap; exceeding either means
   give up and go to step 3).
2. If the tag is reached, the stream is aligned again. Issue `LOGOUT` so imapproxy caches a clean
   connection.
3. If it is not reached, the connection cannot be made safe. It must be destroyed in a way that
   imapproxy will not cache. **Open question, must be verified against the real proxy, not
   assumed:** does an abrupt client close cause `up-imapproxy` to discard the upstream connection,
   or cache it anyway? The observed poisoning suggests it caches. If it does, find a mechanism that
   forces a discard, or accept that the pool must be treated as contaminated and escalate — a
   deliberate `LOGOUT` on a desynced stream is not safe either, since the reply cannot be trusted.

**This defence belongs outside pipelining.** Roundcube reading a tag it did not send is a fault
regardless of cause. The resync should sit at the connection layer and run whenever a tag mismatch
is seen, so a future bug in any other command path cannot poison the pool either.

### D2 — A desync returns empty results marked complete

After `closeSocket()`, `$this->fp` is null. Every remaining job in the batch reaches
`rcube_imap_search_job::search_index()`, which checks `$imap->connected()`, finds false, and returns
a fresh `rcube_result_index` with `incomplete` defaulting to **false**.

So the user is told "no matches" for **every folder in the batch**, as a complete and successful
answer. That is worse than the error toast in D4: an error is visible, a silent empty result is not.
Nothing in that path reconnects.

**Design.** A batch that fails must mark its jobs `incomplete`, never complete-and-empty. Roundcube
already has machinery for incomplete searches (`search.php:125` skips listing, emits
`continue_search`, and `app.js:5550` re-issues), so the correct behaviour is to hand those jobs back
unanswered and let the serial path or the continuation loop answer them. Whatever D1 concludes about
connection recovery determines whether the retry can reuse the connection or must reconnect.

### D3 — Non-ASCII search terms send a synchronising literal

An accented term (`reunião` — Portuguese, so routine here, not an edge case) is sent as an IMAP
literal by `rcube_imap_generic::escape()`:

    return sprintf("{%d}\r\n%s", strlen($string), $string);

`putLineC()` rewrites `{8}` to the non-synchronising `{8+}` only when `prefs['literal+']` or
`prefs['literal-']` is set; otherwise it **blocks reading a `+` continuation**. Inside a batch that
read consumes a reply belonging to a later command, which is what produced D1 and D4.

Observed on the wire:

    C: A0005 UID SEARCH RETURN (ALL) CHARSET UTF-8 OR HEADER SUBJECT {8} reunião HEADER FROM {8} reunião
    S: + Ready for additional text

**What is known, and what is not.** A first fix added `getCapability('LITERAL-')` to `searchMulti()`
on the theory that a reused proxy connection had never parsed the capability. That theory is now
**unproven and probably wrong**:

- Instrumentation on a live staging connection recorded `lminus=true`, `hasLM=y`, `capcount=16` —
  the pref *was* set.
- All 30 capability responses in the log advertise `LITERAL-`.
- `parseCapability()` was simulated against Zoho's exact capability string and yields
  `literal- => true`, and `putLineC` would then emit `{8+}`.
- `clearCapability()` does not clear those prefs.

Yet the wire showed `{8}`. The measurement was taken on a **serial** search, where a synchronising
literal is legal and harmless, so it does not describe the state inside a batch. **We still do not
know why the rewrite did not happen.** No fix should be written until an instrumented run inside an
actual pipelined batch shows whether `preg_split` produced the delimiter and what the pref was at
that moment.

Do not "fix" this by stripping accents from the query. That changes what search matches and turns a
slow correct answer into a fast wrong one.

### D4 — The fallback reports itself to the user as a server error

`readPipelined()` calls `setError(self::ERROR_COMMAND, "Pipelined reply out of order: ...")`, which
reaches the UI as a red toast. Falling back to the serial path is an internally handled condition
and must be logged, not reported. (Partially addressed in `0b331f341`; re-verify once D1 and D2
change the surrounding flow.)

## The guard chain — why none of this was reproducible on demand

`rcube_imap_search::run_pipelined()` declines when:

```php
if (empty($this->jobs) || $threading || $sort_field || getenv('AVUZ_PIPELINED_SEARCH') === '0') {
    return;
}
```

plus a later requirement that every job share identical criteria.

On 2026-07-23 the path was exercised repeatedly and **engaged zero times**: peak 7 IMAP commands per
second against the ~100/s a batch produces. That included an all-folders search, with the sort
preference removed, on a 23-folder account, which still ran serially in 15.38s. Which guard declined
is **not known**.

Two consequences:

1. **The optimisation is much narrower than believed.** It cannot run for any user with a sort
   column set. Of ~97 users, 11 have `arrival` and 1 has an explicit empty value; the other 88 have
   no stored preference. Whether those 88 resolve to an empty `$sort_field` at search time is
   unverified — and the fact that a 23-folder all-folders search with no sort still declined
   suggests the guard chain is not understood.
2. **A feature that silently disables itself on an unrelated UI preference is a bad design**,
   independent of the bugs. Nobody — user or operator — can tell which mode a given search used.
   Whatever the fix, the decision must be observable: log which path a search took and why.

## Testing

The reproduction must move off staging. Three manual attempts produced three non-reproductions, each
costing a human round trip, because the trigger cannot be set reliably from the UI.

The spike harness already has Dovecot, the production `up-imapproxy` Debian build, and a 198ms RTT
shim (`docs/superpowers/spikes/2026-07-22-search-pipelining/`). Extend it to cover:

- a **reused** proxy connection (`XPROXYREUSE`), not only a fresh one — the case the original spike
  never exercised and where every one of these defects lives
- a **non-ASCII** search term
- a **deliberately desynced** connection returned to the pool, asserting the next client to take
  that connection gets clean replies — the regression test for D1
- a batch failure asserting jobs come back `incomplete`, never complete-and-empty — D2
- an assertion that no user-facing error is raised on fallback — D4

Unit coverage should also pin the guard chain: given a set of jobs and options, which path is chosen
and why.

## Rollout

`AVUZ_PIPELINED_SEARCH` stays `0` on prod until every item above is closed and the harness
reproduces then proves each fix. Enabling is an env change plus redeploy — no rebuild.

When enabling: staging first, with an accented all-folders search and a deliberate desync, then prod
during a window where someone is watching. **Runbook entry, valid today:** if pooled connections are
ever poisoned, restarting the imapproxy sidecar clears them — verified on staging 2026-07-23. The
symptom is unrelated commands returning `BAD` or wrong replies, which does not look like a search
problem at all.

## Open questions

1. Does `up-imapproxy` cache a connection whose client vanished abruptly? Determines whether D1's
   step 3 is achievable. Must be tested against the real build.
2. Why did `putLineC` not rewrite the literal when the pref was set? Blocks D3.
3. Which guard declines in practice, and how often would pipelining actually engage for real users?
   Determines whether this is worth finishing at all.
4. Given Zoho charges real server time for `TEXT`/body searches — 69s observed on staging, and 5 of
   11 observed all-folder searches were body searches — how much of the real-world win survives?
   Pipelining removes round trips, not Zoho's compute.

Question 3 and question 4 together are worth answering **before** the engineering in D1-D4. If
pipelining engages rarely and cannot help body searches, the honest conclusion may be that this
feature is not worth its risk, and that the deferred Postgres index — or simply accepting serial
search — is the better answer.
