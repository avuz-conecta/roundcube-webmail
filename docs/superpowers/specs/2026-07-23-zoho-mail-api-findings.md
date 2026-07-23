# Zoho Mail REST API — can it replace IMAP search for us, and at what cost?

**Date**: 2026-07-23
**Branch**: `avuz-customization`
**Status**: Research only. No decision taken, no code written.
**Follows**: `2026-07-23-search-alternatives-research.md`, which ranked this option #2 and flagged two
unknowns — UID mapping and plan/consent. This document answers them.

---

## Verdict up front

**Viable with conditions, and the conditions are heavy.**

The API does what we hoped on the search side: one HTTP call searches bodies *and attachment
contents* across all folders, answered from Zoho's own index. It does **not** return anything that
identifies an IMAP message. Bridging its results back to folder+UID is possible but requires a
second, heuristic step that nobody has documented and we would have to invent. Separately, we found
no path to one-admin authorisation: **each of the 97 users must consent individually**, and the only
admin-side workaround (mailbox delegation) is hard-capped at 10 mailboxes.

The two facts that decide it:

1. **A search result carries `messageId` and `folderId` — both Zoho-internal 64-bit ids — and no
   IMAP UID and no RFC822 `Message-ID`.** `folderId` maps cleanly to an IMAP path. `messageId` does
   not map to anything, and the only endpoint that would recover the RFC822 `Message-ID` downloads
   the entire message, one call per result, against a 30-requests-per-minute ceiling.
2. **No documented org-wide admin consent for mail *content*.** Zoho's organisation APIs cover
   account administration only; the message APIs live exclusively under `/api/accounts/{accountId}/`.

## Evidence conventions

- **[VERIFIED-DOC]** — read directly in Zoho documentation, linked, fetched 2026-07-23.
- **[VERIFIED-REPORT]** — a named third party's first-hand account, linked.
- **[INFERRED]** — my reasoning. Not observed. Treat as a hypothesis needing a spike.
- **[UNVERIFIED]** — I looked and could not establish it. Called out explicitly.

---

# A. The search endpoint, precisely

## Request

```
GET https://mail.zoho.com/api/accounts/{accountId}/messages/search
Authorization: Zoho-oauthtoken <access_token>
```

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-search-emails.html

Verbatim sample request from the docs:

```
https://mail.zoho.com/api/accounts/123456789/messages/search?searchKey=newMails&receivedTime=1609459200000&start=1&limit=10&includeto=true
```

**Scope**: `ZohoMail.messages.ALL` or `ZohoMail.messages.READ` **[VERIFIED-DOC]**

## Query parameters — the complete set

| Param | Type | Mandatory | Default | Range / notes |
|---|---|---|---|---|
| `searchKey` | string | **yes** | — | Must follow Zoho Mail search syntax |
| `receivedTime` | long | no | *current time − 2 minutes* | Unix ms. "Specifies the time **before which** emails were received." |
| `start` | int | no | `1` | Starting sequence number |
| `limit` | int | no | `10` | "Min. value: 1 and max. value: **200**" |
| `includeto` | boolean | no | `false` | Include To details in the response |

**[VERIFIED-DOC]**, all five, same page.

### `receivedTime` is a correctness trap — flag this loudly

Verbatim: *"By default, the API retrieves emails received **before 2 minutes from the current
time** unless a specific timestamp is provided."* **[VERIFIED-DOC]**

This is an upper bound on received time, not a window. So the default silently excludes the most
recent ~2 minutes of mail, and any value you pass excludes everything newer than it. There is **no
lower bound** parameter — the corpus searched runs from the beginning of the mailbox up to
`receivedTime`.

Consequence for us: a user who searches for a message that arrived 90 seconds ago gets nothing, with
no error. To search "everything", pass `receivedTime` = now + a margin. **[INFERRED]** that passing a
future timestamp is accepted; **[UNVERIFIED]** — Zoho does not document the behaviour of a future
value, and it may be clamped or rejected. Test in the spike.

## How we obtain `accountId`

Two documented routes **[VERIFIED-DOC]**
(https://www.zoho.com/mail/help/api/getting-started-with-api.html,
https://www.zoho.com/mail/help/api/):

| Route | Endpoint | Scope | Who can call it |
|---|---|---|---|
| Per-user | `GET https://mail.zoho.com/api/accounts` | `ZohoMail.accounts.READ` | the authorised user, returns their own accounts |
| Org-wide | `GET https://mail.zoho.com/api/organization/{zoid}/accounts` | `ZohoMail.organization.accounts.READ` | org admin, returns **every** user's `accountId` |

Note the asymmetry that shapes section E: an admin *can* enumerate all 97 `accountId` values with one
token. That does not mean the admin can then read those accounts' messages.

`zoid` (org id) and `zuid` (user id) are prerequisites; `zuid` comes from
`GET https://{accounts-domain}/oauth/user/info` **[VERIFIED-REPORT]**
(https://www.aurinko.io/blog/zoho-mail-oauth-flow/, 2024-02-28).

## `searchKey` syntax — the full operator set

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/search-syntax.html

| Operator | Searches |
|---|---|
| `entire:` | anywhere in the email — **this is the body search we want** |
| `content:` | email body only |
| `subject:` | Subject line |
| `sender:` | From field (note: **`sender:`**, not `from:`) |
| `to:` | To field |
| `cc:` | Cc field |
| `fileName:` | attachment filenames |
| `fileContent:` | **inside attachment contents** — IMAP cannot do this at all |
| `has:` | `attachment`, `flags`, `convo` |
| `in:` | folder scope, e.g. `in:Marketing` |
| `label:` | label / tag |
| `fromDate:` / `toDate:` | date range, format `DD-MMM-YYYY` |
| `inclspamtrash:` | include Spam and Trash when `true` |
| `groupResult:` | group by conversation when `true` |

**Combination**:

- `::` — separates conditions, **AND** by default. Example from the docs:
  `subject:Conta::sender:customercare@creditcard.com::has:attachment`
- `:or:` — explicit OR.
- Double quotes — exact phrase. Example: `entire:"Olá pessoal"`.
- **No wildcards and no regex are documented.** **[VERIFIED-DOC]** — the syntax page lists none.
  Treat wildcard support as absent.

**Folder scoping**: yes, both ways. *"By default, searches scan across all folders"*; `in:<folder>`
restricts to one. **[VERIFIED-DOC]** Caveat: the documented examples use a folder **name**
(`in:Marketing`), not a path. Our accounts have ~107 folders, many nested. Whether `in:` accepts
`Parent/Child` or a leading slash is **[UNVERIFIED]**.

### Non-ASCII / accented terms — pt-BR

Zoho's own documentation examples are in Portuguese and use accents directly:
`entire:Olá::entire:pessoal` and `entire:"Olá pessoal"` **[VERIFIED-DOC]**. So accented terms are
expected and supported at the syntax level.

**How they must be encoded on the wire is not documented.** **[UNVERIFIED]** The only safe
assumption is: UTF-8 bytes, percent-encoded as a normal query-string value
(`entire%3AOl%C3%A1`). Note that `:` and `::` are structural in `searchKey`, so a naive
`urlencode()` of the whole `searchKey` will encode the colons too — Zoho does not say whether it
accepts `%3A` as a separator. **[INFERRED]** the separators must be left literal and only the
*terms* encoded. This is exactly the class of bug that bit us at the IMAP layer with `LITERAL-`, and
it must be the second thing the spike tests, right after the identity mapping.

Also unaddressed: Unicode normalisation (NFC vs NFD) and accent-folding. Does `açao` match `ação`?
Does `Olá` match `ola`? **[UNVERIFIED]** — nothing in Zoho's docs. Our users will assume it does.

## Pagination

- `start` (1-based) and `limit` (1–200) **[VERIFIED-DOC]**.
- **No total count is returned.** The response has only `status` and `data`. **[VERIFIED-DOC]** —
  the documented sample response contains no `totalCount`, `hasMore` or similar field.
- **Maximum total results is undocumented.** **[UNVERIFIED]** — no stated ceiling on `start`.
  Whether `start=5000` works is unknown.

Consequence: Roundcube's message list shows "N results" and needs a count to page. With no total, a
plugin must either page until a short page comes back (up to 30 calls/min ceiling) or lie about the
count. **[INFERRED]**

---

# B. THE DECISIVE QUESTION — response schema and IMAP mapping

## The documented response, verbatim

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-search-emails.html

```json
{
  "status": { "code": 200, "description": "success" },
  "data": [
    {
      "URI": "https://mail.zoho.com/api/accounts/123456789/folders/9000000000905/messages/9000000019029",
      "hasAttachment": 0,
      "fromAddress": "rebecca@zylker.com",
      "folderId": 9000000000905,
      "messageId": 9000000019029,
      "sender": "Maria Daniel",
      "summary": "It is extremely important for us to focus on",
      "status2": "reply",
      "sentDateInGMT": 1270171976000,
      "size": 540,
      "status": "read",
      "priority": 3,
      "threadCount": 0,
      "flagid": 2,
      "subject": "Marketing Strategy",
      "threadId": 1,
      "receivedtime": 1425388373920
    }
  ]
}
```

That is the complete field list. **There is no `uid`, no `imapUid`, no `messageIdInHeader`, no
RFC822 `Message-ID`.**

## Is `messageId` the IMAP UID? No — and this is provable without a spike

Zoho's own documented `messageId` values are 64-bit internal record ids. The search doc shows
`9000000019029` (13 digits); the folders doc shows sibling ids of the form `2560636000000008014`
(19 digits) **[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-all-folder-details.html.

An IMAP UID is a 32-bit unsigned integer — maximum `4294967295`, ten digits
(RFC 3501 §2.3.1.1). **`9000000019029` and `2560636000000008014` both exceed that by three and nine
orders of magnitude respectively.** They cannot be IMAP UIDs, and no arithmetic derivation is
plausible: these are Zoho's org-scoped sequence ids, shared across folders and accounts.

**[INFERRED]** from documented values plus the RFC, but the inference is arithmetic, not judgement.
I searched Zoho's docs, the Zoho community forum and third-party integrator writeups for any
statement relating `messageId` to an IMAP UID and found **nothing**. **[UNVERIFIED]** that they are
unrelated in some hidden way, but treat "messageId is not a UID" as settled.

## Does `folderId` map to an IMAP folder path? Yes — cheaply

```
GET https://mail.zoho.com/api/accounts/{accountId}/folders
scope: ZohoMail.folders.ALL or ZohoMail.folders.READ
```

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-all-folder-details.html — documented
response:

```json
{
  "status": { "code": 200, "description": "success" },
  "data": [
    {
      "path": "/Inbox",
      "isArchived": 1,
      "folderName": "Inbox",
      "imapAccess": true,
      "folderType": "Inbox",
      "URI": "https://mail.zoho.com/api/accounts/2560636000000008002/folders/2560636000000008014",
      "folderId": "2560636000000008014"
    }
  ]
}
```

Two fields matter enormously here:

- **`path`** — `/Inbox`, `/Drafts`, `/Sent`. This is a direct, documented `folderId → IMAP folder
  path` map. One call per account, cacheable for hours.
- **`imapAccess`** — a boolean. **Folders can exist in the API and be invisible over IMAP.** Any
  search result whose folder has `imapAccess: false` is unrepresentable in Roundcube. That is a
  real divergence risk and must be filtered.

So half the mapping problem is solved cleanly. The other half is not.

## Is there any endpoint returning the RFC822 `Message-ID`? Yes, but the cost is fatal

```
GET https://mail.zoho.com/api/accounts/{accountId}/messages/{messageId}/originalmessage
scope: ZohoMail.messages.ALL or ZohoMail.messages.READ
```

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-original-message.html — returns the
message in MIME format, "the MIME representation of an email message", complete with RFC 822
headers. The `Message-ID` header is in there.

The metadata endpoint
(`/api/accounts/{accountId}/folders/{folderId}/messages/{messageId}/details`) does **not** carry it —
its documented fields are `summary`, `sentDateInGMT`, `subject`, `messageId`, `toAddress`,
`fromAddress`, `ccAddress`, `hasAttachment`, `size`, `sender`, `receivedTime`, `status`
**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-email-meta-data.html.

**Cost analysis.** `originalmessage` is one call *per message*, and it downloads the **entire
message including attachments**. For a 25-result page that is 25 additional API calls plus tens of
megabytes. Against the documented **30 requests/minute** ceiling (section C), a single 25-result
search consumes 26 of the 30 calls available to — probably — the whole organisation for that minute.
A 200-result page is arithmetically impossible: 200 calls at 30/min is **6 minutes 40 seconds**
before the first result renders.

**There is no batch variant.** I checked the full Email Messages API index (21 endpoints,
https://www.zoho.com/mail/help/api/email-api.html) — every message-level read is singular. **This
is the answer to the coordinator's question: the per-result cost is per-result, not per-batch, and
it is therefore fatal.**

## Is there a bridge that survives the rate limit?

Yes, one, and it is unproven. **[INFERRED throughout — this is a hypothesis, not a finding.]**

Don't ask the API for identity. Ask IMAP, using the API's metadata as the query:

1. Call the search API once. Get N results, each carrying `folderId`, `subject`, `fromAddress`,
   `sentDateInGMT`.
2. Resolve `folderId → path` from the cached folders map. Group results by folder — a typical page
   of 25 spans a handful of folders, not 107.
3. For each distinct folder, issue **one** IMAP `UID SEARCH` combining that folder's results:
   `UID SEARCH OR (SENTON <d1> FROM <a1> SUBJECT <s1>) (SENTON <d2> FROM <a2> SUBJECT <s2>) ...`
4. Union the UIDs. Hand them to Roundcube via `imap_search_before`.

Why this could work: the expensive part (body and attachment content, across all folders) is done by
Zoho's index in one HTTP call. The IMAP part is a *header* search restricted to one folder with a
tight date predicate — the cheap kind, not the 69-second `TEXT` kind. With Roundcube's existing
pipelining, ~5 folders is ~5 round trips, roughly 2 seconds at 198ms RTT, against 15.5s median /
69s body-search today.

Why it might not:

- **Ambiguity.** Two messages with the same subject, sender and date in one folder — newsletters,
  automated notifications, "Re:" chains — are indistinguishable. We would return both. Tolerable for
  search; not tolerable if a plugin ever acts on the result.
- **Subject encoding.** Zoho's API returns a decoded subject; IMAP `SUBJECT` matches against the
  encoded header. Accented pt-BR subjects go through RFC 2047 encoded-words. Roundcube's IMAP layer
  handles this, but it is the same accent-handling surface that already produced one bug here.
- **Empty subjects** have no discriminator at all.
- **`sentDateInGMT` vs IMAP `SENTON`.** `SENTON` matches the `Date:` header at day granularity in
  the *server's* interpretation; timezone edges will occasionally miss. Widening to
  `SENTSINCE d-1 SENTBEFORE d+1` costs nothing and is safer.
- **`imapAccess: false` folders** produce results with no IMAP counterpart at all.

This bridge is the entire feasibility of the option. **It must be spiked before anything else is
costed.** One account, one search, measure: does the per-folder IMAP re-identification recover the
right UIDs, and how often is it ambiguous?

## Plain statement, as requested

Nothing in the search response maps to an IMAP message directly. That does not *kill* the option —
`folderId → path` is documented and clean, and a metadata-based re-identification over IMAP is
plausible — but it does mean **there is no cheap, exact bridge, and the exact bridge that exists
(`originalmessage` per result) is unusable under the rate limit.** The option now depends on an
approximate mapping that nobody has published and we would own.

---

# C. Limits, quotas and throttling

## There is no API-credit system for Zoho Mail

Zoho's credit model is a **Zoho CRM** concept (https://www.zoho.com/crm/developer/docs/api/v8/api-limits.html).
Zoho Mail does not use it. I checked the Mail rates-and-limits page, the Mail API getting-started
page and the Mail API index: **no mention of credits anywhere.** **[VERIFIED-DOC]**

## The real limit: 30 requests per minute

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/adminconsole/rates-and-limits.html

> "If you exceed 30 API requests per minute, subsequent requests will be blocked for a locking
> period."

And on the duration of that block: *"The duration is not publicly disclosed for security reasons."*

The Mail API getting-started page adds only: *"Each Zoho Mail's REST API has its own rate limit,
which may vary depending on the specific API. If you exceed the API usage limit, the response will
notify you."* **[VERIFIED-DOC]** — so per-endpoint limits may be tighter than 30/min, and are not
published.

**Is 30/min per user, per org, or per client?** The rates-and-limits page presents it in an
organisation-scoped table but does not say. **[UNVERIFIED]** — and it is the difference between
comfortable and impossible. Assume **per organisation** until proven otherwise; that is the
pessimistic reading and the one that must be planned against.

The page recommends exponential back-off, response caching, even request distribution rather than
bursts, and webhooks instead of polling **[VERIFIED-DOC]**.

No HTTP status code for the throttled response is documented. **[UNVERIFIED]** whether it is 429,
or a 200 with an error body — Zoho Mail APIs commonly return 200 with a `status.code` in the body,
so a plugin must inspect the body, not the status line.

**Plan-tier differences**: none stated. The 30/min figure is presented without qualification.
**[VERIFIED-DOC]**, though absence of a statement is not proof of uniformity.

## Does 97 interactive users fit?

Assumptions, stated so they can be challenged:

- The prior measurement observed **~7 logical searches in a 9.2-hour production window**. Search is
  rare here today — partly *because* it is unusable, so this number will rise if we fix it.
- Assume a 10× uplift once search becomes fast: **~70 searches/day** across 97 users.
- Per search: **1** search call + **0** folder-list calls (cached) + IMAP re-identification (not an
  API call). Under the `originalmessage` bridge instead: **1 + 25** calls.

| Bridge | Calls/search | Calls/day at 70 searches | Peak minute (3 concurrent searchers) |
|---|---|---|---|
| Metadata + IMAP re-identification | ~1 | ~70 | **3** — comfortable |
| `originalmessage` per result (25/page) | ~26 | ~1,820 | **78** — exceeds 30/min ceiling by 2.6× |

Plus, one-off: `GET /api/accounts/{id}/folders` per user per cache-TTL. At 97 users and a 4-hour TTL
that is ~582 calls/day, ~0.4/min amortised — negligible, but a **cold start after a deploy would
issue 97 folder-list calls at once and immediately trip a 30/min org limit**. That needs a paced
warm-up. **[INFERRED]**

Conclusion: **the metadata bridge fits the quota with enormous headroom; the exact bridge does
not.** This is a second, independent reason the `originalmessage` route is dead.

---

# D. Plan and licensing

**Honest answer: I could not verify which plans include REST API access, and Zoho does not appear to
state it anywhere.**

What is verified:

- Zoho Mail's public pricing / feature-comparison page (https://www.zoho.com/mail/zohomail-pricing.html)
  **does not mention API, REST API or Email API at all** in the feature matrix. **[VERIFIED-DOC]**,
  fetched 2026-07-23. The page carries a promotional cutoff date of 10 November 2023, so parts of it
  are stale.
- The same page **does** state, verbatim (pt-BR): *"IMAP/POP/Active Sync não inclusos no plano
  Gratuito"* — IMAP/POP/ActiveSync are **excluded from the Free plan**. **[VERIFIED-DOC]**
- Plans and list prices as shown: **Mail Lite $1/user/month**, **Mail Premium $4/user/month**,
  **Workplace $3/user/month**, plus a Free plan and a trial. **[VERIFIED-DOC]**
- Nothing in the API documentation set (overview, getting-started, index) states a plan
  prerequisite. **[VERIFIED-DOC]**

**[INFERRED]**, weakly: because the Free plan excludes IMAP/POP and Zoho gates protocol access by
tier, the API is plausibly also gated to paid tiers. **This is inference, not fact.** Do not build a
plan on it.

**How an administrator verifies their own tier**: Zoho Mail Admin Console → **Subscription**, which
shows the active plan and user count. The per-account API
`GET /api/accounts/{accountId}` returns `planStorage`, `allowedStorage` and `imapAccessEnabled`
**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/get-user-account-details.html — `imapAccessEnabled`
is a useful per-user sanity check and is worth reading regardless, since a user with IMAP disabled
breaks the bridge in section B.

**The only reliable answer is empirical**: register an OAuth client against our actual org and call
the search endpoint. That is a ~30-minute test and it settles D completely. Given how cheap it is,
further desk research here is not worth doing.

---

# E. OAuth — and the admin-consent question

## Scopes

| Purpose | Scope | Source |
|---|---|---|
| Search / read messages | `ZohoMail.messages.READ` (or `.ALL`) | **[VERIFIED-DOC]** search API page |
| List folders (for `folderId → path`) | `ZohoMail.folders.READ` (or `.ALL`) | **[VERIFIED-DOC]** folders API page |
| List the user's own accounts | `ZohoMail.accounts.READ` | **[VERIFIED-DOC]** accounts API page |
| List **all org users'** accountIds | `ZohoMail.organization.accounts.READ` | **[VERIFIED-DOC]** org users API page |

A narrower variant exists: **`ZohoMail.messages.READOWNER`**, described as like
`ZohoMail.messages.READ` but restricted to emails owned by the user. Noted for completeness; we want
the broader `READ`.

## Can one admin authorisation reach all 97 mailboxes? Almost certainly not.

This is the operationally decisive question, and every line of evidence points the same way.

**1. The message APIs have no organisation-scoped variant.** The complete Email Messages API index
lists 21 endpoints, all under `/api/accounts/{accountId}/...`. Zoho *does* publish
`/api/organization/{zoid}/...` variants — for enabling/disabling external IMAP/POP accounts, for
admin-added signatures, for user administration — so the pattern exists and is used deliberately.
**It is not offered for messages, folders, or search.** **[VERIFIED-DOC]**
https://www.zoho.com/mail/help/api/email-api.html and https://www.zoho.com/mail/help/api/

This is the strongest single piece of evidence: Zoho knows how to expose an admin variant and chose
not to for mail content.

**2. Zoho's OAuth model has no tenant concept for Mail.** Aurinko, a commercial email-API
aggregator that ships a Zoho Mail integration, states plainly **[VERIFIED-REPORT]**
(https://www.aurinko.io/blog/zoho-mail-api/, 2023-05-25):

> "an authorized user does not belong to any specific Zoho organization, there is no one primary
> OrgId or TenantId"

— explicitly contrasting this with Office 365 and Google, where exactly this admin-consent
delegation exists. Whoever built the closest thing to our integration commercially concluded there
is no tenant-level grant.

**3. Instance-level OAuth exists but does not rescue us.** Zoho does document an admin-grants-once
flow: *"an administrator grants consent once on behalf of the entire instance"* **[VERIFIED-DOC]**
https://www.zoho.com/developer/oauth/instance-level-oauth.html. The worked examples are Zoho CRM
(Organizations) and Zoho Desk (Portals). **Zoho Mail is mentioned on that page only as an example of
a single-instance app**, and the flow's own auth-code documentation lists no supported-service
matrix. **[UNVERIFIED]** whether `ZohoMail.messages.READ` is even an allowed instance-level scope. I
found no example, no forum post and no third-party report of anyone obtaining an instance-level
token for Zoho Mail message content.

**This is the single fact most worth testing before writing this option off** — see the closing
section.

**4. The admin workaround Zoho actually documents is mailbox delegation, and it caps at 10.**
**[VERIFIED-DOC]** https://www.zoho.com/mail/help/mailbox-delegation.html:

> "you can delegate your inbox to a maximum of **10** users, and you can receive access to up to
> **10** delegated inboxes"

Ten. We have 97. Even if delegated mailboxes surfaced through `GET /api/accounts` — itself
**[UNVERIFIED]**, the delegation documentation describes web-interface behaviour only and never
mentions API or IMAP — the cap makes it arithmetically useless. **This closes the delegation
workaround permanently.**

**5. Zoho's own answer to "can an admin read users' mail" is not the API.** Zoho's KB article on
admin monitoring offers only outgoing email-policy forwarding: *"you can set up outgoing email
forwarding from the Email Policy section in the Control Panel"* **[VERIFIED-DOC]**
(https://help.zoho.com/portal/en/kb/mail/adminconsole/articles/can-i-monitor-the-emails-that-my-users-send-using-their-organization-accounts).
A community thread asking directly for admin access to users' email has **no Zoho staff reply**
**[VERIFIED-REPORT]** (https://help.zoho.com/portal/en/community/topic/admin-access-to-users-email),
matching the unanswered thread the prior research pass already found.

## Conclusion for E

**Plan for 97 individual OAuth consents.** Verified: no org-scoped message endpoint, delegation
capped at 10, no tenant concept per a commercial integrator, no documented admin path. Unverified
and worth one experiment: instance-level OAuth with a `ZohoMail.messages` scope.

The consolation from the prior research still stands and is worth restating: **Zoho advertises
`AUTH=XOAUTH2` on IMAP**, and Roundcube 1.6 has generic OAuth2 support
(`oauth_provider`, `oauth_auth_uri`, `oauth_token_uri`, `oauth_scope`). A single consent per user
could serve *both* IMAP login and REST search, and retire the stored-credential design. If we are
paying the 97-consent cost anyway, we should collect both benefits in one flow.

## Token lifetimes and limits

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/using-oauth-2.html:

> "Access tokens have a limited validity period and, in most cases, expire after one hour (3,600
> seconds)."

Response carries `"expires_in": 3600`. Refresh tokens: *"Refresh tokens typically have a longer
lifespan—ranging from days to months or even years."* Zoho does not commit to a number.

Refresh-token limits **[VERIFIED-DOC]** https://www.zoho.com/accounts/protocol/oauth/token-limits.html:

| Limit | Value |
|---|---|
| Active refresh tokens per user per client | **20** |
| Refresh tokens generated per minute | **5** |
| Active access tokens per refresh token | **30** |

The **5/minute** figure is the operational one: onboarding or re-consenting 97 users cannot be done
in a burst. 97 ÷ 5 = **at least 20 minutes** of wall-clock, and that assumes users complete consent
on cue. In practice this is a rollout campaign, not a maintenance window.

The **Self Client** flow avoids the consent screen but issues a token *for your own account only*
(*"For personal scripts and automation tools accessing your own Zoho ... account"*, *"No user-facing
consent screen is involved"*) **[VERIFIED-DOC]**. Useful for the spike. Useless for production.

## Data centres

**[VERIFIED-DOC]** https://www.zoho.com/mail/help/api/getting-started-with-api.html:

| DC | Mail API base |
|---|---|
| US | `https://mail.zoho.com` |
| EU | `https://mail.zoho.eu` |
| India | `https://mail.zoho.in` |
| Australia | `https://mail.zoho.com.au` |
| Japan | `https://mail.zoho.jp` |
| Canada | `https://mail.zohocloud.ca` |
| China | `https://mail.zoho.com.cn` |
| UAE | `https://mail.zoho.ae` |
| Saudi Arabia | `https://mail.zoho.sa` |

**Note there is no Brazil or LATAM data centre.** We are on `.com` (US) — consistent with
`imap.zoho.com` and with the 198ms RTT that started all of this. So the REST API will also be a
~198ms round trip. That is fine: the whole point is that it is **one** round trip instead of 214.

**How to determine which applies**: the OAuth authorization callback returns a `location` /
`accounts-server` parameter identifying the user's region; resolve it via
`GET https://accounts.zoho.com/oauth/serverinfo`, which returns account URLs for `eu`, `au`, `in`,
`jp`, `uk`, `ca`, `sa`, `us`. **[VERIFIED-REPORT]** https://www.aurinko.io/blog/zoho-mail-oauth-flow/
(2024-02-28) — with the gotcha that integrator calls out verbatim:

> "Except, for the ZohoMail API you need to replace 'accounts.' with 'mail.'"

i.e. `serverinfo` gives you `https://accounts.zoho.eu`; the Mail API lives at `https://mail.zoho.eu`.
And: *"Before exchanging the code for an access token you need to construct a proper
location/domain based server URL!"*

**Does the wrong domain fail silently?** **[UNVERIFIED]**. The integrator emphasises getting it
right but states no failure mode. **[INFERRED]** that a token minted at the wrong accounts domain
fails at token exchange (loudly), while a *correct* token used against the wrong `mail.zoho.*` host
would return an auth error rather than empty results — but empty-result behaviour is exactly the
kind of thing worth confirming, because a silently-empty search is indistinguishable from "no
matches" and would be a miserable bug to diagnose.

Since all 97 of our users are in one org on the US DC, this is low risk for us — but the resolution
logic should still be written, not hardcoded.

---

# F. Gotchas and real-world reports

## From a commercial integrator who shipped it

Aurinko (https://www.aurinko.io/blog/zoho-mail-api/, 2023-05-25, updated Sept 2023)
**[VERIFIED-REPORT]** — they build a unified email API and support Zoho Mail in production. Their
assessment:

- **No sync / delta capability at all**: *"Zoho Mail API does not provide any way to monitor mailbox
  changes, like messages moving to another folder, or changing read/unread status."* Messages carry
  no `updatedAt`, so *"you can't even use the search method to receive updates."*
  Not directly our problem — we are only replacing search, and IMAP remains the source of truth for
  state — but it confirms the API is a read-oriented veneer, not a mailbox protocol.
- **Threads API is incomplete**: `threadId` is returned but *"there is no API methods to request a
  list of all threads or to request a list of messages in a thread"*, and *"a single new email is
  not a thread yet and Zoho Mail API won't return 'threadId' for it."* Partially mitigated Sept 2023
  by a `&threadId=` filter on list-messages.
- **Webhooks cannot be subscribed programmatically**: *"a product team needing this would have to
  ask each client's Zoho Mail admin to set up those outgoing webhooks manually."*
- **No tenant/org identity** — quoted in section E.
- **And, notably, they prefer it to IMAP**: *"We definitely prefer to use this OAuth2 based REST API
  over IMAP which Zoho Mail provides too."* From a company with production traffic on both. That is
  a meaningful endorsement of the API's *reliability*, separate from whether it fits our
  UID-mapping constraint.

## Indexing lag — how soon is a message searchable?

**Not documented, and not answered by any report I found.** **[UNVERIFIED]** The nearest thing is
the `receivedTime` default of *now − 2 minutes*, which **[INFERRED]** may be a deliberate hedge
against index lag: Zoho excluding the last two minutes by default suggests very recent messages are
not reliably indexed. If that inference is right, the practical answer is "roughly 2 minutes", and
the parameter is documenting the lag rather than causing it. Worth measuring in the spike — send a
mail, poll search, time it.

This matters for us specifically: a user who searches for the message that *just arrived* is a
common pattern, and it is precisely the case where Zoho's index will be coldest and IMAP would have
been correct.

## Search quality complaints

Zoho's community carries recurring first-hand complaints about Mail search in the **web UI** —
"search shows results very slow", "wrong search results", searches returning nothing for a known
sender or subject **[VERIFIED-REPORT]**
(https://help.zoho.com/portal/en/community/topic/search-mail-is-not-working-properly-5-1-2017 and
https://help.zoho.com/portal/en/community/topic/search-function-in-emails-not-working).

The API and the web UI are presumed to hit the same index, so **these complaints likely apply to the
API too** **[INFERRED]**. That undercuts the premise this whole option rests on — that Zoho's index
is fast and correct because their web UI feels instant. Some of their users disagree. Any spike must
measure not just latency but **recall**: run the same query through the API and through IMAP `TEXT`
on a folder with known content, and compare result sets.

## `folderId` / `messageId` correctness bugs in the wild

A Zoho community thread reports **"ZohoMail's outbound webhook sends incorrect folderId and
messageId"**, producing "invalid message id" errors on subsequent API calls **[VERIFIED-REPORT]**
(https://help.zoho.com/portal/en/community/topic/zohomails-outbound-webhook-sends-incorrect-folderid-and-messageid).
That is the webhook path, not search, but it is a direct report of Zoho's own identifiers being
unreliable across surfaces — relevant when the entire integration hinges on those identifiers.

## Attachment-content search in practice

`fileContent:` is documented **[VERIFIED-DOC]** and is genuinely something IMAP cannot do. **I found
no first-hand report of anyone using it** — no blog post, no forum thread, no complaint. **Its real
behaviour is completely unverified**: which file types are indexed (PDF? DOCX? scanned images with
OCR?), size limits, how far back historical attachments were indexed. For a client with a ~1 TB
mailbox this is the difference between a marquee feature and a placebo.

## People who tried and abandoned it

**I found none, in either direction.** No "we moved off the Zoho Mail API" writeups, and no
production case studies beyond Aurinko and generic integration-platform listings (Rollout, Truto).
The API has a thin public footprint. As with the Dovecot `imapc`+FTS option in the prior document,
the absence cuts both ways — no accumulated warnings, and no accumulated confidence.

---

# Verdict

**Viable with conditions.** The conditions are:

| # | Condition | Status | Cost if it fails |
|---|---|---|---|
| 1 | A metadata-based IMAP re-identification (folder + subject + sender + date) recovers correct UIDs at acceptable ambiguity | **Unproven — must spike first** | Option dead. Nothing else bridges within the rate limit. |
| 2 | Our Zoho plan includes REST API access | **Unverified** | Option dead, or a plan upgrade for 97 seats |
| 3 | 97 individual OAuth consents are acceptable to the business | Verified as *required* (no admin path) | Option dead operationally |
| 4 | `searchKey` handles pt-BR accented terms correctly over the wire | **Unverified** | Feature is useless for our users |
| 5 | Zoho's index recall matches IMAP's | **Unverified**, with contrary user reports | Users lose trust in search permanently |

Confirmed dead ends, for the record — do not spend time re-investigating:

- `messageId` is **not** an IMAP UID (magnitude proof, section B).
- `originalmessage` per result is **not** a usable bridge (30 req/min, section B and C).
- **Mailbox delegation is capped at 10** and cannot cover 97 users (section E).
- There is **no** organisation-scoped message or search endpoint (section E).
- There is **no** API-credit system for Zoho Mail; the limit is 30 requests/minute (section C).

## Where this leaves the ranking

The prior document ranked this option #2, behind progressive/bounded search. **That ranking still
holds, and this research strengthens the case for doing #1 first**: progressive search needs no
OAuth, no consent campaign, no plan verification, no vendor dependency, and no identifier bridge. It
is entirely within our control.

This option should now be treated as **contingent on a one-day spike**, not as a plan.

## The single fact most likely to change the decision

**Whether instance-level OAuth accepts a `ZohoMail.messages` scope.**

If an administrator can grant `ZohoMail.messages.READ` once for the whole Zoho Mail instance, the
97-consent problem — the largest operational objection in this document — evaporates, and this
option jumps to clearly-best. Zoho documents the mechanism
(https://www.zoho.com/developer/oauth/instance-level-oauth.html) but names only CRM and Desk, and
mentions Zoho Mail solely as a single-instance app. Nobody has published an attempt.

It is a **one-hour test**: register a client, request an instance-level auth code with
`scope=ZohoMail.messages.READ`, see whether Zoho issues it, then call
`/api/accounts/{someOtherUserId}/messages/search` with the resulting token.

Run that before the UID-mapping spike. It costs an hour and it decides the shape of everything else.

## Recommended spike order

1. **Instance-level OAuth with `ZohoMail.messages.READ`** (1 hour) — decides section E.
2. **Plan check**: Self Client token against our real org, call `messages/search` (30 min) —
   decides section D empirically and settles what desk research could not.
3. **Identifier bridge** (half a day) — API search → `folderId → path` → per-folder IMAP
   `UID SEARCH`. Measure UID recovery rate and ambiguity rate on a real 20k-message mailbox.
4. **pt-BR encoding** (1 hour) — `entire:ação`, `entire:"Olá pessoal"`, accent-folding, NFC vs NFD.
5. **Recall and lag** (1 hour) — API vs IMAP `TEXT` on known content; send-then-poll to time the
   indexing delay.

Steps 1 and 2 are cheap and either of them can end the investigation. Do not start step 3 until both
have passed.

---

# Sources

All fetched 2026-07-23 unless noted.

**Zoho Mail API**
- Search endpoint: https://www.zoho.com/mail/help/api/get-search-emails.html
- Search syntax: https://www.zoho.com/mail/help/search-syntax.html
- API index: https://www.zoho.com/mail/help/api/
- API overview: https://www.zoho.com/mail/help/api/overview.html
- Getting started (data centres, accountId, rate limits): https://www.zoho.com/mail/help/api/getting-started-with-api.html
- Email Messages API index (21 endpoints): https://www.zoho.com/mail/help/api/email-api.html
- Get all folders: https://www.zoho.com/mail/help/api/get-all-folder-details.html
- Get email metadata: https://www.zoho.com/mail/help/api/get-email-meta-data.html
- Get original message (MIME): https://www.zoho.com/mail/help/api/get-original-message.html
- Get specific account details: https://www.zoho.com/mail/help/api/get-user-account-details.html
- Get all accounts of a user: https://www.zoho.com/mail/help/api/get-all-users-accounts.html
- Fetch all org users: https://www.zoho.com/mail/help/api/get-org-users-details.html
- List emails: https://www.zoho.com/mail/help/api/get-emails-list.html

**Auth**
- Zoho Mail OAuth 2.0 guide: https://www.zoho.com/mail/help/api/using-oauth-2.html
- OAuth token limits: https://www.zoho.com/accounts/protocol/oauth/token-limits.html
- Instance-level OAuth: https://www.zoho.com/developer/oauth/instance-level-oauth.html
- Instance-level OAuth, auth code: https://www.zoho.com/accounts/protocol/oauth/instance-level-oauth/get-auth-code.html

**Limits, plans, admin**
- Zoho Mail rates and limits (30 req/min): https://www.zoho.com/mail/help/adminconsole/rates-and-limits.html
- Zoho Mail pricing / feature matrix: https://www.zoho.com/mail/zohomail-pricing.html
- Mailbox delegation (10-mailbox cap): https://www.zoho.com/mail/help/mailbox-delegation.html
- Admin monitoring of user email: https://help.zoho.com/portal/en/kb/mail/adminconsole/articles/can-i-monitor-the-emails-that-my-users-send-using-their-organization-accounts
- Community, admin access to users' email (no staff reply): https://help.zoho.com/portal/en/community/topic/admin-access-to-users-email
- Zoho CRM API credits, for contrast: https://www.zoho.com/crm/developer/docs/api/v8/api-limits.html

**Third-party reports**
- Aurinko, "Zoho Mail API: ... Areas for Improvement", 2023-05-25: https://www.aurinko.io/blog/zoho-mail-api/
- Aurinko, "ZohoMail OAuth Flow with Multi-Region Support", 2024-02-28: https://www.aurinko.io/blog/zoho-mail-oauth-flow/
- Community, incorrect folderId/messageId in webhooks: https://help.zoho.com/portal/en/community/topic/zohomails-outbound-webhook-sends-incorrect-folderid-and-messageid
- Community, search not working properly: https://help.zoho.com/portal/en/community/topic/search-mail-is-not-working-properly-5-1-2017
- Community, search function not working: https://help.zoho.com/portal/en/community/topic/search-function-in-emails-not-working
