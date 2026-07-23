# Pipelined multi-folder search desync on non-ASCII terms — root cause

## TL;DR

- **Root cause:** `program/actions/mail/search.php:76`
  `$search_str = preg_replace('/[\r\n]+/', ' ', $search_str);`
  This runs *after* the search term has already been encoded as an IMAP
  literal at line 66, so it rewrites the literal's mandatory `{8}\r\n`
  into `{8} ` (CR LF → a single space). The literal is malformed from that
  point on.
- **Why serial survives but the pipeline desyncs:** both paths hand
  `putLineC()` a *byte-identical, already-broken* string — the criteria is
  **not** built differently. The divergence is purely at the transport
  layer: the synchronous serial `execute()` reads one command's reply to
  completion (absorbing Zoho's spurious `+ Ready for additional text`
  continuation for the malformed literal within that single exchange),
  whereas `searchMulti()` writes every command in the batch before reading
  any reply, so that stray `+` continuation consumes a reply slot and every
  following tag is attributed to the wrong command → desync → fallback.
- **The fix:** sanitise the only raw, unescaped user input (`$filter`) at
  the point it is read, and delete the post-assembly `preg_replace` that
  clobbers the literal. The search term itself is already injection-safe
  because `escape()` length-counts it as a literal.

---

## The wire evidence (given)

```
C: A0097 UID SEARCH RETURN (ALL) CHARSET UTF-8 OR HEADER SUBJECT {8} reunião HEADER FROM {8} reunião
S: + Ready for additional text
S: A0005 BAD [CLIENTBUG] syntax: expecting 'c', found 'u'
```

`{8} reunião` — the literal count `{8}` is followed by a **space**, not the
`\r\n` an RFC 3501 literal requires (`{8}\r\nreunião`). `A0097`'s reply is
answered by a line tagged `A0005`: the batch is desynchronised.

## The chain (each function read)

1. `search.php:66` — `... . rcube_imap_generic::escape($search)`.
   `escape("reunião")` hits the literal branch
   (`rcube_imap_generic.php:4507`, `sprintf("{%d}\r\n%s", ...)`) because
   `ã`/`õ` are `\x80-\xFF` bytes. Output: `{8}\r\nreunião` — **correct
   literal, `\r\n` present**. (`reunião` is 8 bytes in UTF-8.)
2. `search.php:76` — `preg_replace('/[\r\n]+/', ' ', $search_str)`. This is
   applied to the **fully assembled** `$search_str`, which now contains the
   escaped literal. It rewrites `{8}\r\nreunião` → `{8} reunião`. **This is
   the exact point `\r\n` becomes a space.** (Introduced upstream by the
   security commit `b18a8fa8e` "Fix IMAP Injection + CSRF bypass in mail
   search"; the ordering — flatten *after* escape — is the defect and is
   identical in upstream, which is why ASCII searches never trip it: ASCII
   terms take `escape()`'s atom/quoted branch and never produce a literal.)
3. `search.php:106` → `rcube_imap::search($mboxes, $search_str, ...)`. The
   flattened string is what enters storage. The all/sub scope multi-folder
   branch (`rcube_imap.php:1645`) builds `rcube_imap_search` and calls
   `exec($folder, $search, ...)`.
4. `rcube_imap_search_job::get_criteria()` prepends `CHARSET UTF-8 ` (and
   `UNDELETED `). It does **not** touch the literal — it is already
   flattened. Both the serial job (`search_index()` →
   `$imap->search($folder, $this->get_criteria(), true)`) and the pipeline
   (`run_pipelined()` → `$criteria = $job->get_criteria()` →
   `searchMulti($folders, $criteria, true)`) read from the **same**
   `get_criteria()`.
5. `searchMulti()` (`rcube_imap_generic.php:2135`) →
   `searchParams()` (`:2034`, only `trim()`s — does not alter a mid-string
   `\r\n`) → `putLineC($search.' '.$command.' '.$params)`.
6. Serial `search()` (`:1999`) → same `searchParams()` →
   `execute('UID SEARCH', [$params])` (`:4138`). `r_implode()` (`:4292`) on
   a *string* argument returns it unchanged.
7. `putLineC()` (`:145`) recognises a literal **only** via
   `preg_split('/(\{[0-9]+\}\r\n)/m', ...)`. `{8} ` does not match, so the
   `literal-`/`literal+` conversion and the synchronising handshake are both
   skipped and the line is written raw — matching the instrumentation
   (0 `{8+}`, 300 `{8}` on the wire).

## Standalone proof (no server)

`escape()` + line-76 flatten + `putLineC` detection, using the repo's real
class:

```
strlen("reunião")                      = 8 bytes
escape() output                        = {8}\r\nreunião   (7b 38 7d 0d 0a ...)
after line-76 preg_replace             = {8} reunião      (7b 38 7d 20 ...)   <-- 0d 0a -> 20
putLineC split matches unflattened?    = YES (literal handled)
putLineC split matches flattened?      = NO  (written RAW -> desync)
```

Criteria construction is identical on both paths (same script, real
`searchParams`/`r_implode`):

```
SERIAL   execute()   -> A0097 UID SEARCH RETURN (ALL) CHARSET UTF-8 UNDELETED OR HEADER SUBJECT {8} reunião HEADER FROM {8} reunião
PIPELINE searchMulti -> A0098 UID SEARCH RETURN (ALL) CHARSET UTF-8 UNDELETED OR HEADER SUBJECT {8} reunião HEADER FROM {8} reunião
Byte-identical (minus tag)?            = IDENTICAL
```

So the two paths do **not** build criteria differently — they both receive
the already-broken literal. The serial path only *appears* to work because
Zoho tolerantly reads the 8 inline bytes after the malformed `{8} ` as the
literal payload, and the synchronous `execute()` read loop swallows the
stray `+` continuation within its single request/reply. In the pipeline that
same stray `+` shifts the reply stream and desyncs every later tag.

## Why serial keeps working and pipeline does not (one sentence)

Both paths send the identical malformed literal; the synchronous serial
`execute()` contains Zoho's `+ Ready` continuation inside one
request/reply exchange, while `searchMulti()` has already written the rest
of the batch, so that continuation is misread as a later command's reply and
the tags desynchronise.

## The fix (report only — not applied)

The `\r\n` must never have been stripped from the literal. The security
commit's real target is `$filter` (the `_filter` GET param), the only raw,
**unescaped** user input placed into `$search_str` (line 57). The search
term (`$search`) is already injection-safe: `escape()` encodes it as a
length-counted literal, so any CR/LF inside it becomes literal payload the
server reads as exactly N bytes, never as command syntax.

So sanitise `$filter` at read time and drop the post-assembly flatten:

`program/actions/mail/search.php:50` — change:

```php
$filter = trim((string) $filter);
```
to:
```php
// Strip CR/LF from the only raw, unescaped user input that reaches the
// SEARCH command; the search term is already neutralised by escape() as a
// length-counted literal, so it must not be flattened afterwards.
$filter = preg_replace('/[\r\n]+/', ' ', trim((string) $filter));
```

`program/actions/mail/search.php:74-76` — **delete**:

```php
// We pass the filter as-is into IMAP SEARCH command. A newline could be used
// to inject extra commands, so we remove these.
$search_str = preg_replace('/[\r\n]+/', ' ', $search_str);
```

This preserves the injection protection (no raw user CR/LF reaches the
command) while leaving legitimate `{n}\r\n` literals intact, fixing both the
pipelined and the serial path (serial currently relies on Zoho's lenient
inline-literal parsing and would return wrong/empty results on a stricter
server).

Note: it is two small edits, not a single certain one-liner, and it changes
the scope of an upstream security fix — so it is reported here rather than
applied. A literal-aware single-line rewrite of line 76 (e.g. a
`(?<!\})` lookbehind) is **not** recommended: an attacker can append `}`
before injected CRLF to slip past it.
