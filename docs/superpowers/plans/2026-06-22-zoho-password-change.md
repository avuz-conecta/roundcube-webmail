# Zoho Password Change Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a Roundcube user change their real Zoho mailbox password, forced on first login, via a separate internal broker service that holds the org-admin Zoho credentials.

**Architecture:** A Node/TS `password-broker` container holds the Zoho org refresh token and exposes one internal-only `POST /reset` endpoint. A Roundcube `password` plugin driver (`zoho_broker`) calls it. The broker verifies the user's current password over IMAP, then resets via the Zoho Mail Admin API. Forced-first-login, the hard-lock, current-password confirmation, and provider gating are all native `password` plugin behavior driven by config.

**Tech Stack:** Node 20 + TypeScript (broker, `fetch` + `imapflow`); PHP 8.2 (Roundcube driver, Guzzle via `password::get_http_client()`); Docker Compose.

## Global Constraints

- Broker holds all Zoho secrets; **Roundcube never holds the org token** — only the broker URL + shared secret.
- Broker has **no public port**; reachable only on the internal compose network.
- Broker requires header `X-Broker-Secret` equal to `BROKER_SHARED_SECRET` on every request.
- Least-privilege Zoho scopes: `ZohoMail.organization.accounts.READ` + `ZohoMail.organization.accounts.UPDATE`.
- Logs must truncate secrets (follow existing `nextcloud_sso` key-logging pattern).
- Zoho reset endpoint: `PUT https://mail.zoho.com/api/organization/{zoid}/accounts/{zuid}`, body `{"password","mode":"resetPassword"}`, header `Authorization: Zoho-oauthtoken <access>`.
- Zoho users list: `GET https://mail.zoho.com/api/organization/{zoid}/accounts?start=<n>&limit=<n>` (paginated; default limit 10).
- Driver class naming: `rcube_zoho_broker_password` in `plugins/password/drivers/zoho_broker.php`.
- Node code follows user CLAUDE.md: named exports only, no `any`, no `as`, early return, camelCase functions, kebab-case files, descriptive names.

---

## File Structure

- Create: `services/password-broker/package.json` — deps + scripts
- Create: `services/password-broker/tsconfig.json` — TS config
- Create: `services/password-broker/src/config.ts` — env loading
- Create: `services/password-broker/src/zoho-token.ts` — access-token cache
- Create: `services/password-broker/src/zoho-accounts.ts` — email→zuid + reset
- Create: `services/password-broker/src/verify-password.ts` — IMAP current-pass check
- Create: `services/password-broker/src/server.ts` — HTTP server + `/reset`
- Create: `services/password-broker/src/*.test.ts` — vitest tests
- Create: `services/password-broker/Dockerfile`
- Create: `plugins/password/drivers/zoho_broker.php` — Roundcube driver
- Create: `plugins/password/tests/ZohoBroker.php` — driver test
- Modify: `config/config.inc.php` — enable + configure password plugin
- Modify: `scripts/build-push.sh` — build/push broker image
- Modify: `customizations.json` — record new files
- Create: `deploy/stack.reference.yml` — reference copy of the Portainer stack (broker service)

---

## Task 0: Pre-flight feasibility (BLOCKER — do before any code)

**Files:** none (manual verification).

The whole flow assumes a freshly-created Zoho mailbox can authenticate over **IMAP** with the
admin-set temp password, with no prior web login. If IMAP is off by default or first web login
is required, the user can't reach the forced-change screen and the broker can't verify. Confirm
before building.

- [ ] **Step 1: Enable IMAP org-wide**

Zoho Mail Admin Console → Security & Compliance / Email Policy (or Mail → IMAP/POP/ActiveSync
access) → enable **IMAP access** for the org. New orgs often have it off.

- [ ] **Step 2: Create a throwaway Zoho mailbox WITHOUT the force-change flag**

Create a user with a known temp password and **leave "force password change at next login"
UNCHECKED**. That Zoho flag blocks IMAP until a web password change and conflicts with our
Roundcube forced-change — we force it ourselves via `password_force_new_user`.

- [ ] **Step 3: Attempt IMAP login with no prior web login**

Run: `openssl s_client -connect imap.zoho.com:993 -crlf -quiet`
then type: `a LOGIN newuser@client.com "TempPass"`
Expected: `a OK ...` (authenticated). If `NO`/auth failure → IMAP still gated; recheck Steps 1-2.

Only proceed to Task 1 once IMAP login with a fresh temp password (no force flag) succeeds.
Record "IMAP enabled org-wide + create mailboxes without the force-change flag" as the
per-client onboarding rule.

---

## Task 1: Broker scaffold + shared-secret guard

**Files:**
- Create: `services/password-broker/package.json`
- Create: `services/password-broker/tsconfig.json`
- Create: `services/password-broker/src/config.ts`
- Create: `services/password-broker/src/server.ts`
- Test: `services/password-broker/src/config.test.ts`
- Test: `services/password-broker/src/server.test.ts`

**Interfaces:**
- Produces: `createServer(deps: ServerDeps): http.Server`; `ServerDeps = { resetPassword: (input: ResetInput) => Promise<ResetResult> }`; `ResetInput = { email: string; currentPass: string; newPass: string }`; `ResetResult = { status: 200 | 401 | 403 | 404 | 422 | 502; body: { ok: boolean; error?: string } }`.
- Produces: `loadConfig(env): BrokerConfig` with `{ port, sharedSecret, imap: {host,port}, tenants: Map<domain, ZohoOrg> }`; `resolveTenant(tenants, email): ZohoOrg | null` (by email domain); `ZohoOrg = { clientId, clientSecret, refreshToken, zoid }`.

- [ ] **Step 1: Write package.json**

```json
{
  "name": "avuz-password-broker",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "tsc",
    "start": "node dist/main.js",
    "test": "vitest run",
    "dev": "tsx src/server.ts"
  },
  "dependencies": {
    "imapflow": "^1.0.164"
  },
  "devDependencies": {
    "@types/node": "^20.14.0",
    "tsx": "^4.16.0",
    "typescript": "^5.5.0",
    "vitest": "^2.0.0"
  }
}
```

- [ ] **Step 2: Write tsconfig.json**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ES2022",
    "moduleResolution": "bundler",
    "strict": true,
    "outDir": "dist",
    "rootDir": "src",
    "skipLibCheck": true
  },
  "include": ["src"]
}
```

- [ ] **Step 3: Install deps**

Run: `cd services/password-broker && npm install`
Expected: `node_modules/` created, no errors.

- [ ] **Step 4: Write config.ts**

```ts
export type ZohoOrg = { clientId: string; clientSecret: string; refreshToken: string; zoid: string };

export type BrokerConfig = {
  port: number;
  sharedSecret: string;
  imap: { host: string; port: number };
  tenants: Map<string, ZohoOrg>;
};

const parseTenants = (raw: string): Map<string, ZohoOrg> => {
  const parsed = JSON.parse(raw) as Record<string, ZohoOrg>;
  return new Map(Object.entries(parsed).map(([domain, org]) => [domain.toLowerCase(), org]));
};

export const loadConfig = (env: NodeJS.ProcessEnv): BrokerConfig => {
  const required = (key: string): string => {
    const value = env[key];
    if (!value) throw new Error(`missing env ${key}`);
    return value;
  };

  return {
    port: Number(env.PORT ?? 9000),
    sharedSecret: required("BROKER_SHARED_SECRET"),
    imap: { host: env.ZOHO_IMAP_HOST ?? "imap.zoho.com", port: Number(env.ZOHO_IMAP_PORT ?? 993) },
    tenants: parseTenants(required("ZOHO_TENANTS")),
  };
};

export const resolveTenant = (tenants: Map<string, ZohoOrg>, email: string): ZohoOrg | null => {
  const domain = email.split("@")[1]?.toLowerCase();
  if (!domain) return null;
  return tenants.get(domain) ?? null;
};
```

- [ ] **Step 4b: Write + run the config resolver test**

```ts
import { describe, expect, test } from "vitest";
import { loadConfig, resolveTenant } from "./config.js";

const tenantsJson = '{"Client-A.com":{"clientId":"i","clientSecret":"s","refreshToken":"r","zoid":"111"}}';
const env = { BROKER_SHARED_SECRET: "x", ZOHO_TENANTS: tenantsJson } as NodeJS.ProcessEnv;

describe("config", () => {
  test("resolves tenant by domain case-insensitively", () => {
    const { tenants } = loadConfig(env);
    expect(resolveTenant(tenants, "user@CLIENT-a.com")?.zoid).toBe("111");
  });

  test("returns null for unknown domain", () => {
    const { tenants } = loadConfig(env);
    expect(resolveTenant(tenants, "user@other.com")).toBeNull();
  });

  test("returns null for malformed email", () => {
    const { tenants } = loadConfig(env);
    expect(resolveTenant(tenants, "noatsign")).toBeNull();
  });
});
```

Run: `cd services/password-broker && npm test config`
Expected: PASS (after Step 4's config.ts exists).

- [ ] **Step 5: Write the failing server test**

```ts
import { describe, expect, test } from "vitest";
import { createServer, type ResetInput, type ResetResult } from "./server.js";

const post = async (server: ReturnType<typeof createServer>, headers: Record<string, string>, body: unknown) => {
  await new Promise<void>((resolve) => server.listen(0, resolve));
  const address = server.address();
  if (address === null || typeof address === "string") throw new Error("no port");
  const response = await fetch(`http://127.0.0.1:${address.port}/reset`, {
    method: "POST",
    headers: { "content-type": "application/json", ...headers },
    body: JSON.stringify(body),
  });
  server.close();
  return response;
};

const okDeps = {
  sharedSecret: "secret",
  resetPassword: async (_input: ResetInput): Promise<ResetResult> => ({ status: 200, body: { ok: true } }),
};

describe("broker server", () => {
  test("rejects missing shared secret with 401", async () => {
    const response = await post(createServer(okDeps), {}, { email: "a@b.com", currentPass: "x", newPass: "y" });
    expect(response.status).toBe(401);
  });

  test("accepts valid shared secret", async () => {
    const response = await post(createServer(okDeps), { "x-broker-secret": "secret" }, { email: "a@b.com", currentPass: "x", newPass: "y" });
    expect(response.status).toBe(200);
  });
});
```

- [ ] **Step 6: Run test to verify it fails**

Run: `cd services/password-broker && npm test`
Expected: FAIL — `createServer` not found.

- [ ] **Step 7: Write server.ts**

```ts
import http from "node:http";

export type ResetInput = { email: string; currentPass: string; newPass: string };
export type ResetResult = { status: 200 | 401 | 403 | 404 | 422 | 502; body: { ok: boolean; error?: string } };
export type ServerDeps = { sharedSecret: string; resetPassword: (input: ResetInput) => Promise<ResetResult> };

const readJson = (request: http.IncomingMessage): Promise<unknown> =>
  new Promise((resolve, reject) => {
    let raw = "";
    request.on("data", (chunk) => (raw += chunk));
    request.on("end", () => {
      try {
        resolve(JSON.parse(raw || "{}"));
      } catch (error) {
        reject(error);
      }
    });
  });

const isResetInput = (value: unknown): value is ResetInput => {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return typeof candidate.email === "string" && typeof candidate.currentPass === "string" && typeof candidate.newPass === "string";
};

export const createServer = (deps: ServerDeps): http.Server =>
  http.createServer(async (request, response) => {
    const send = (status: number, body: unknown) => {
      response.writeHead(status, { "content-type": "application/json" });
      response.end(JSON.stringify(body));
    };

    if (request.method !== "POST" || request.url !== "/reset") return send(404, { ok: false, error: "not found" });
    if (request.headers["x-broker-secret"] !== deps.sharedSecret) return send(401, { ok: false, error: "unauthorized" });

    const payload = await readJson(request).catch(() => null);
    if (!isResetInput(payload)) return send(400, { ok: false, error: "bad request" });

    const result = await deps.resetPassword(payload);
    send(result.status, result.body);
  });
```

- [ ] **Step 8: Run test to verify it passes**

Run: `cd services/password-broker && npm test`
Expected: PASS (both tests).

- [ ] **Step 9: Commit**

```bash
git add services/password-broker/package.json services/password-broker/package-lock.json services/password-broker/tsconfig.json services/password-broker/src/config.ts services/password-broker/src/config.test.ts services/password-broker/src/server.ts services/password-broker/src/server.test.ts
git commit -m "feat(broker): scaffold node service with shared-secret guard"
```

---

## Task 2: Zoho access-token cache

**Files:**
- Create: `services/password-broker/src/zoho-token.ts`
- Test: `services/password-broker/src/zoho-token.test.ts`

**Interfaces:**
- Consumes: `ZohoOrg` from Task 1.
- Produces: `createTokenProvider(org: ZohoOrg, opts?): () => Promise<string>` where `opts = { fetchImpl?: typeof fetch; now?: () => number }`. Returns a cached access token for that org, refreshing when within 60s of expiry. One provider per tenant (main builds the map).

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { createTokenProvider } from "./zoho-token.js";

const org = { clientId: "id", clientSecret: "sec", refreshToken: "ref", zoid: "1" };

describe("token provider", () => {
  test("fetches then caches the access token", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: "abc", expires_in: 3600 }), { status: 200 }));
    const getToken = createTokenProvider(org, { fetchImpl, now: () => 0 });
    expect(await getToken()).toBe("abc");
    expect(await getToken()).toBe("abc");
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  test("refreshes after expiry", async () => {
    let token = "first";
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: token, expires_in: 3600 }), { status: 200 }));
    let clock = 0;
    const getToken = createTokenProvider(org, { fetchImpl, now: () => clock });
    expect(await getToken()).toBe("first");
    token = "second";
    clock = 3600_000;
    expect(await getToken()).toBe("second");
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd services/password-broker && npm test zoho-token`
Expected: FAIL — `createTokenProvider` not found.

- [ ] **Step 3: Write zoho-token.ts**

```ts
import type { ZohoOrg } from "./config.js";

type TokenOpts = { fetchImpl?: typeof fetch; now?: () => number };

const TOKEN_URL = "https://accounts.zoho.com/oauth/v2/token";
const EXPIRY_SKEW_MS = 60_000;

export const createTokenProvider = (org: ZohoOrg, opts: TokenOpts = {}): (() => Promise<string>) => {
  const fetchImpl = opts.fetchImpl ?? fetch;
  const now = opts.now ?? Date.now;
  let cached: { token: string; expiresAt: number } | null = null;

  return async () => {
    if (cached && now() < cached.expiresAt - EXPIRY_SKEW_MS) return cached.token;

    const params = new URLSearchParams({
      refresh_token: org.refreshToken,
      client_id: org.clientId,
      client_secret: org.clientSecret,
      grant_type: "refresh_token",
    });
    const response = await fetchImpl(`${TOKEN_URL}?${params.toString()}`, { method: "POST" });
    if (!response.ok) throw new Error(`zoho token http ${response.status}`);

    const json = (await response.json()) as { access_token?: string; expires_in?: number };
    if (!json.access_token) throw new Error("zoho token missing access_token");

    cached = { token: json.access_token, expiresAt: now() + (json.expires_in ?? 3600) * 1000 };
    return cached.token;
  };
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd services/password-broker && npm test zoho-token`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/password-broker/src/zoho-token.ts services/password-broker/src/zoho-token.test.ts
git commit -m "feat(broker): cached zoho access-token provider"
```

---

## Task 3: Resolve zuid by email + reset password

**Files:**
- Create: `services/password-broker/src/zoho-accounts.ts`
- Test: `services/password-broker/src/zoho-accounts.test.ts`

**Interfaces:**
- Consumes: token provider from Task 2.
- Produces: `findZuidByEmail(deps, email): Promise<string | null>` and `resetZohoPassword(deps, zuid, newPass): Promise<void>` where `deps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch }`. `findZuidByEmail` pages with `limit=200` until a match or an empty page.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { findZuidByEmail, resetZohoPassword } from "./zoho-accounts.js";

const page = (users: Array<{ zuid: string; emailAddress: string }>) =>
  new Response(JSON.stringify({ data: users }), { status: 200 });

describe("zoho accounts", () => {
  test("finds zuid across pages", async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(page([{ zuid: "1", emailAddress: "a@x.com" }]))
      .mockResolvedValueOnce(page([{ zuid: "2", emailAddress: "b@x.com" }]))
      .mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findZuidByEmail(deps, "b@x.com")).toBe("2");
  });

  test("returns null when not found", async () => {
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findZuidByEmail(deps, "missing@x.com")).toBeNull();
  });

  test("reset issues PUT with resetPassword mode", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ status: { code: 200 } }), { status: 200 }));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    await resetZohoPassword(deps, "2", "newpass");
    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toContain("/organization/9/accounts/2");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(init.body)).toEqual({ password: "newpass", mode: "resetPassword" });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd services/password-broker && npm test zoho-accounts`
Expected: FAIL — functions not found.

- [ ] **Step 3: Write zoho-accounts.ts**

```ts
export type AccountsDeps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch };

const BASE = "https://mail.zoho.com/api/organization";
const PAGE_SIZE = 200;

const authHeaders = async (deps: AccountsDeps): Promise<Record<string, string>> => ({
  Authorization: `Zoho-oauthtoken ${await deps.getToken()}`,
  "Content-Type": "application/json",
});

export const findZuidByEmail = async (deps: AccountsDeps, email: string): Promise<string | null> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const target = email.toLowerCase();

  for (let start = 0; ; start += PAGE_SIZE) {
    const url = `${BASE}/${deps.zoid}/accounts?start=${start}&limit=${PAGE_SIZE}`;
    const response = await fetchImpl(url, { headers: await authHeaders(deps) });
    if (!response.ok) throw new Error(`zoho users http ${response.status}`);

    const json = (await response.json()) as { data?: Array<{ zuid: string; emailAddress: string }> };
    const users = json.data ?? [];
    if (users.length === 0) return null;

    const match = users.find((user) => user.emailAddress.toLowerCase() === target);
    if (match) return match.zuid;
  }
};

export const resetZohoPassword = async (deps: AccountsDeps, zuid: string, newPass: string): Promise<void> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const url = `${BASE}/${deps.zoid}/accounts/${zuid}`;
  const response = await fetchImpl(url, {
    method: "PUT",
    headers: await authHeaders(deps),
    body: JSON.stringify({ password: newPass, mode: "resetPassword" }),
  });
  if (!response.ok) throw new Error(`zoho reset http ${response.status}`);
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd services/password-broker && npm test zoho-accounts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/password-broker/src/zoho-accounts.ts services/password-broker/src/zoho-accounts.test.ts
git commit -m "feat(broker): zoho zuid lookup + password reset"
```

---

## Task 4: Verify current password over IMAP

**Files:**
- Create: `services/password-broker/src/verify-password.ts`
- Test: `services/password-broker/src/verify-password.test.ts`

**Interfaces:**
- Produces: `verifyImapPassword(deps, email, password): Promise<boolean>` where `deps = { host: string; port: number; connect?: ImapConnect }` and `ImapConnect = (opts: { host: string; port: number; secure: boolean; auth: { user: string; pass: string } }) => Promise<{ logout: () => Promise<void> }>`. Returns true if login succeeds, false on auth failure. Default `connect` uses `imapflow`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { verifyImapPassword } from "./verify-password.js";

describe("verify imap password", () => {
  test("true when connect succeeds", async () => {
    const connect = vi.fn(async () => ({ logout: async () => {} }));
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "right");
    expect(ok).toBe(true);
  });

  test("false when connect rejects (bad auth)", async () => {
    const connect = vi.fn(async () => {
      throw new Error("auth failed");
    });
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "wrong");
    expect(ok).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd services/password-broker && npm test verify-password`
Expected: FAIL — `verifyImapPassword` not found.

- [ ] **Step 3: Write verify-password.ts**

```ts
import { ImapFlow } from "imapflow";

type ImapClient = { logout: () => Promise<void> };
export type ImapConnect = (opts: { host: string; port: number; secure: boolean; auth: { user: string; pass: string } }) => Promise<ImapClient>;
export type VerifyDeps = { host: string; port: number; connect?: ImapConnect };

const defaultConnect: ImapConnect = async (opts) => {
  const client = new ImapFlow({ ...opts, logger: false });
  await client.connect();
  return { logout: () => client.logout() };
};

export const verifyImapPassword = async (deps: VerifyDeps, email: string, password: string): Promise<boolean> => {
  const connect = deps.connect ?? defaultConnect;
  try {
    const client = await connect({ host: deps.host, port: deps.port, secure: true, auth: { user: email, pass: password } });
    await client.logout();
    return true;
  } catch {
    return false;
  }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd services/password-broker && npm test verify-password`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add services/password-broker/src/verify-password.ts services/password-broker/src/verify-password.test.ts
git commit -m "feat(broker): verify current password over imap"
```

---

## Task 5: Compose the reset flow + wire main

**Files:**
- Create: `services/password-broker/src/reset.ts`
- Create: `services/password-broker/src/main.ts`
- Test: `services/password-broker/src/reset.test.ts`

**Interfaces:**
- Consumes: `resolveTenant` + `ZohoOrg` (Task 1), `verifyImapPassword` (Task 4), `findZuidByEmail` + `resetZohoPassword` (Task 3), `createTokenProvider` (Task 2).
- Produces: `createResetPassword(deps): (input: ResetInput) => Promise<ResetResult>` where `deps = { resolveOrg: (email) => ZohoOrg | null; verify: (email, pass) => Promise<boolean>; findZuid: (org, email) => Promise<string | null>; reset: (org, zuid, newPass) => Promise<void> }`. Mapping: unknown domain → 422; bad current → 403; zuid null → 404; verify/reset throw → 502; success → 200.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { createResetPassword } from "./reset.js";
import type { ZohoOrg } from "./config.js";

const org: ZohoOrg = { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "1" };
const base = {
  resolveOrg: (_email: string): ZohoOrg | null => org,
  verify: vi.fn(async () => true),
  findZuid: vi.fn(async () => "7"),
  reset: vi.fn(async () => {}),
};
const input = { email: "a@x.com", currentPass: "cur", newPass: "new" };

describe("reset flow", () => {
  test("200 on full success", async () => {
    const run = createResetPassword({ ...base });
    expect((await run(input)).status).toBe(200);
  });

  test("422 when domain has no tenant", async () => {
    const run = createResetPassword({ ...base, resolveOrg: () => null });
    expect((await run(input)).status).toBe(422);
  });

  test("403 when current password wrong", async () => {
    const run = createResetPassword({ ...base, verify: vi.fn(async () => false) });
    const result = await run(input);
    expect(result.status).toBe(403);
    expect(base.reset).not.toHaveBeenCalled();
  });

  test("404 when account not found", async () => {
    const run = createResetPassword({ ...base, findZuid: vi.fn(async () => null) });
    expect((await run(input)).status).toBe(404);
  });

  test("502 when zoho throws", async () => {
    const run = createResetPassword({ ...base, reset: vi.fn(async () => { throw new Error("boom"); }) });
    expect((await run(input)).status).toBe(502);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd services/password-broker && npm test reset`
Expected: FAIL — `createResetPassword` not found.

- [ ] **Step 3: Write reset.ts**

```ts
import type { ZohoOrg } from "./config.js";
import type { ResetInput, ResetResult } from "./server.js";

export type ResetDeps = {
  resolveOrg: (email: string) => ZohoOrg | null;
  verify: (email: string, pass: string) => Promise<boolean>;
  findZuid: (org: ZohoOrg, email: string) => Promise<string | null>;
  reset: (org: ZohoOrg, zuid: string, newPass: string) => Promise<void>;
};

export const createResetPassword = (deps: ResetDeps): ((input: ResetInput) => Promise<ResetResult>) => async (input) => {
  const org = deps.resolveOrg(input.email);
  if (org === null) return { status: 422, body: { ok: false, error: "unknown tenant domain" } };

  try {
    const valid = await deps.verify(input.email, input.currentPass);
    if (!valid) return { status: 403, body: { ok: false, error: "current password incorrect" } };

    const zuid = await deps.findZuid(org, input.email);
    if (zuid === null) return { status: 404, body: { ok: false, error: "account not found" } };

    await deps.reset(org, zuid, input.newPass);
    return { status: 200, body: { ok: true } };
  } catch {
    return { status: 502, body: { ok: false, error: "upstream error" } };
  }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd services/password-broker && npm test reset`
Expected: PASS (all five).

- [ ] **Step 5: Write main.ts (composition root)**

Per-tenant token providers cached by `zoid`; tenant resolved per request by email domain.

```ts
import { loadConfig, resolveTenant, type ZohoOrg } from "./config.js";
import { createServer } from "./server.js";
import { createTokenProvider } from "./zoho-token.js";
import { findZuidByEmail, resetZohoPassword } from "./zoho-accounts.js";
import { verifyImapPassword } from "./verify-password.js";
import { createResetPassword } from "./reset.js";

const config = loadConfig(process.env);

const tokenProviders = new Map<string, () => Promise<string>>();
const accountsDepsFor = (org: ZohoOrg) => {
  let getToken = tokenProviders.get(org.zoid);
  if (!getToken) {
    getToken = createTokenProvider(org);
    tokenProviders.set(org.zoid, getToken);
  }
  return { zoid: org.zoid, getToken };
};

const resetPassword = createResetPassword({
  resolveOrg: (email) => resolveTenant(config.tenants, email),
  verify: (email, pass) => verifyImapPassword(config.imap, email, pass),
  findZuid: (org, email) => findZuidByEmail(accountsDepsFor(org), email),
  reset: (org, zuid, newPass) => resetZohoPassword(accountsDepsFor(org), zuid, newPass),
});

createServer({ sharedSecret: config.sharedSecret, resetPassword }).listen(config.port, () => {
  console.log(`password-broker listening on ${config.port}`);
});
```

- [ ] **Step 6: Build + run full test suite**

Run: `cd services/password-broker && npm run build && npm test`
Expected: `tsc` succeeds (no type errors), all tests PASS.

- [ ] **Step 7: Commit**

```bash
git add services/password-broker/src/reset.ts services/password-broker/src/reset.test.ts services/password-broker/src/main.ts
git commit -m "feat(broker): tenant-aware reset flow + composition root"
```

---

## Task 6: Broker Dockerfile

**Files:**
- Create: `services/password-broker/Dockerfile`
- Create: `services/password-broker/.dockerignore`

**Interfaces:** none (produces an image running `node dist/main.js`).

- [ ] **Step 1: Write .dockerignore**

```
node_modules
dist
*.test.ts
```

- [ ] **Step 2: Write Dockerfile**

```dockerfile
FROM node:20-alpine AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

FROM node:20-alpine
WORKDIR /app
ENV NODE_ENV=production
COPY package.json package-lock.json* ./
RUN npm install --omit=dev
COPY --from=build /app/dist ./dist
USER node
CMD ["node", "dist/main.js"]
```

- [ ] **Step 3: Build the image**

Run: `cd services/password-broker && docker build -t avuz-password-broker:test .`
Expected: build succeeds.

- [ ] **Step 4: Smoke-test it fails fast without env**

Run: `docker run --rm avuz-password-broker:test`
Expected: exits non-zero with `missing env BROKER_SHARED_SECRET` (proves config guard).

- [ ] **Step 5: Commit**

```bash
git add services/password-broker/Dockerfile services/password-broker/.dockerignore
git commit -m "feat(broker): dockerfile for password-broker"
```

---

## Task 7: Roundcube zoho_broker driver

**Files:**
- Create: `plugins/password/drivers/zoho_broker.php`
- Create: `plugins/password/tests/ZohoBroker.php`
- Modify: `tests/phpunit.xml` (register the new test file in the Plugins testsuite)

**Interfaces:**
- Consumes: broker `POST /reset` contract (Task 1/5): JSON `{email,current_pass,new_pass}`, header `X-Broker-Secret`, responses 200/403/404/502.
- Produces: `class rcube_zoho_broker_password` with `save($curpass, $newpass, $username): int` returning Roundcube codes. Static helper `rcube_zoho_broker_password::map_status(int $http): int` for testability.

**Note on coverage:** the existing `plugins/password/tests/Password.php::test_all_drivers()`
globs `drivers/*.php` and load-tests each — so `zoho_broker.php` gets free load/syntax
coverage (and must load cleanly, referencing `rcmail`/`password`/`rcube` only inside `save()`,
not at class-definition time). `map_status` is the unit-tested seam. The HTTP payload/header
in `save()` is verified end-to-end in Task 10 Step 5, not in a unit test (it depends on the
`rcmail` singleton + Guzzle client, not worth mocking here).

- [ ] **Step 1: Write the failing test**

```php
<?php

class ZohoBroker_Plugin extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('PASSWORD_SUCCESS')) {
            define('PASSWORD_SUCCESS', 0);
            define('PASSWORD_ERROR', 1);
            define('PASSWORD_CONNECT_ERROR', 2);
        }
        include_once __DIR__ . '/../drivers/zoho_broker.php';
    }

    public function test_maps_200_to_success()
    {
        $this->assertSame(PASSWORD_SUCCESS, rcube_zoho_broker_password::map_status(200));
    }

    public function test_maps_403_to_error()
    {
        $this->assertSame(PASSWORD_ERROR, rcube_zoho_broker_password::map_status(403));
    }

    public function test_maps_502_to_connect_error()
    {
        $this->assertSame(PASSWORD_CONNECT_ERROR, rcube_zoho_broker_password::map_status(502));
    }

    public function test_maps_unknown_to_error()
    {
        $this->assertSame(PASSWORD_ERROR, rcube_zoho_broker_password::map_status(418));
    }
}
```

- [ ] **Step 2: Register the test in tests/phpunit.xml**

Add inside `<testsuite name="Plugins">` (next to the `nextcloud_sso` line):

```xml
      <file>./../plugins/password/tests/ZohoBroker.php</file>
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter ZohoBroker`
Expected: FAIL — class `rcube_zoho_broker_password` not found.

- [ ] **Step 4: Write zoho_broker.php**

```php
<?php

/**
 * Roundcube password driver for the Avuz Zoho password-broker.
 *
 * Sends the change to an internal broker service that verifies the current
 * password over IMAP and resets the Zoho mailbox password via the Zoho Mail
 * Admin API. Roundcube holds no Zoho org secret — only the broker URL and a
 * shared secret.
 *
 * Config:
 *   $config['avuz_broker_url']    = 'http://broker:9000';
 *   $config['avuz_broker_secret'] = '...';
 */
class rcube_zoho_broker_password
{
    public static function map_status(int $http): int
    {
        $map = [
            200 => PASSWORD_SUCCESS,
            403 => PASSWORD_ERROR,
            404 => PASSWORD_ERROR,
            502 => PASSWORD_CONNECT_ERROR,
        ];

        return $map[$http] ?? PASSWORD_ERROR;
    }

    public function save($curpass, $newpass, $username)
    {
        $rcmail = rcmail::get_instance();
        $url    = $rcmail->config->get('avuz_broker_url');
        $secret = $rcmail->config->get('avuz_broker_secret');

        if (empty($url) || empty($secret)) {
            return PASSWORD_ERROR;
        }

        try {
            $client = password::get_http_client();
            $response = $client->post($url . '/reset', [
                'headers' => ['X-Broker-Secret' => $secret],
                'json'    => ['email' => $username, 'current_pass' => $curpass, 'new_pass' => $newpass],
                'http_errors' => false,
            ]);

            return self::map_status($response->getStatusCode());
        } catch (Exception $e) {
            rcube::write_log('errors', 'zoho_broker: ' . $e->getMessage());
            return PASSWORD_CONNECT_ERROR;
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter ZohoBroker`
Expected: PASS (all four).

- [ ] **Step 6: Commit**

```bash
git add plugins/password/drivers/zoho_broker.php plugins/password/tests/ZohoBroker.php tests/phpunit.xml
git commit -m "feat(password): zoho_broker driver calling internal broker"
```

---

## Task 8: Roundcube config wiring

**Files:**
- Modify: `config/config.inc.php` (plugins list + password block)

**Interfaces:**
- Consumes: driver `zoho_broker` (Task 7), broker URL/secret env.

- [ ] **Step 1: Verify the Zoho storage_host value**

Run (after a Zoho login on the running stack):
`docker exec <roundcube> sh -c 'grep -r storage_host /var/www/roundcube/temp/ 2>/dev/null' || true`
Note the exact value(s). Standalone uses `default_host` = `ssl://imap.zoho.com`; SSO-zoho uses `ssl://imap.zoho.com:993`. Set `password_hosts` to include every value actually stored. If uncertain, include both forms.

- [ ] **Step 2: Add `password` to the plugins list**

In `config/config.inc.php`, change:

```php
$config['plugins'] = [
    'nextcloud_sso',
    'archive',
    'zipdownload',
    'managesieve',
    'password',
];
```

- [ ] **Step 3: Add the password config block**

Add after the plugins list:

```php
// -- Password change (Zoho via internal broker) --
$config['password_driver']           = 'zoho_broker';
$config['password_force_new_user']   = true;
$config['password_confirm_current']  = true;
$config['password_minimum_length']   = 8;
$config['password_strength_driver']  = 'zxcvbn';
$config['password_minimum_score']    = 2;
$config['password_hosts']            = ['ssl://imap.zoho.com', 'ssl://imap.zoho.com:993'];
$config['avuz_broker_url']           = getenv('AVUZ_BROKER_URL') ?: 'http://broker:9000';
$config['avuz_broker_secret']        = getenv('AVUZ_BROKER_SECRET') ?: '';
```

- [ ] **Step 4: Verify PHP syntax**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add config/config.inc.php
git commit -m "feat(config): enable password plugin, zoho_broker driver, zoho host gating"
```

---

## Task 9: Build script, reference stack, customizations

**Deployment model:** the live stack is **hand-edited in Portainer** (not a git-backed
compose). So this task: (a) builds/pushes the broker image, (b) commits a **reference**
`deploy/stack.reference.yml` documenting the full stack (so the repo records the intended
topology), and (c) the actual Portainer edit is a **manual step in Task 10**. No task edits a
live `docker-compose.yml` — there isn't one.

**Files:**
- Modify: `scripts/build-push.sh` (build/push broker image)
- Create: `deploy/stack.reference.yml` (documentation of the Portainer stack)
- Modify: `customizations.json`

**Interfaces:** none (deployment wiring).

- [ ] **Step 1: Inspect build-push.sh**

Run: `cat scripts/build-push.sh`
Note how the Roundcube image tag/registry variables are set so the broker follows the same pattern.

- [ ] **Step 2: Add broker build/push to build-push.sh**

After the Roundcube image build/push, add (adapt variable names to the existing script):

```bash
# Build + push the password-broker image (same tag/registry as roundcube)
docker build -t "${REGISTRY}/avuz-password-broker:${TAG}" services/password-broker
docker push "${REGISTRY}/avuz-password-broker:${TAG}"
```

- [ ] **Step 3: Create deploy/stack.reference.yml**

Write the full intended stack (kept in-repo as the source-of-truth reference; Portainer is
updated by hand from it):

```yaml
# Reference copy of the Portainer stack. Portainer is hand-edited; keep this in sync.
version: '3.8'

services:
  roundcube:
    image: registry.avuz.app/admin/avuz-roundcube:staging
    restart: unless-stopped
    ports:
      - "8081:80"
    depends_on:
      - redis
      - broker
    volumes:
      - roundcube_temp:/var/www/roundcube/temp
      - roundcube_logs:/var/www/roundcube/logs
    environment:
      - ROUNDCUBE_DB_DSN=
      - ROUNDCUBE_DES_KEY=$ROUNDCUBE_DES_KEY
      - ROUNDCUBE_SSO_SECRET=$ROUNDCUBE_SSO_SECRET
      - ROUNDCUBE_CREDENTIAL_KEY=$ROUNDCUBE_CREDENTIAL_KEY
      - AVUZ_BROKER_URL=http://broker:9000
      - AVUZ_BROKER_SECRET=$AVUZ_BROKER_SECRET
      - REDIS_HOST=redis
      - REDIS_PORT=6379

  broker:
    image: registry.avuz.app/admin/avuz-password-broker:staging
    restart: unless-stopped
    # NO ports — internal compose network only
    environment:
      - PORT=9000
      - BROKER_SHARED_SECRET=$AVUZ_BROKER_SECRET
      - ZOHO_TENANTS=$ZOHO_TENANTS
      - ZOHO_IMAP_HOST=imap.zoho.com
      - ZOHO_IMAP_PORT=993

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server --maxmemory 128mb --maxmemory-policy allkeys-lru
    volumes:
      - roundcube_redis:/data

volumes:
  roundcube_temp:
  roundcube_logs:
  roundcube_redis:
```

- [ ] **Step 4: Record customizations**

Add entries to `customizations.json` for: `plugins/password/drivers/zoho_broker.php`,
`services/password-broker/`, the password block in `config/config.inc.php`, and
`deploy/stack.reference.yml`. Match the file's existing JSON shape.

- [ ] **Step 5: Verify JSON + shell + YAML**

Run: `python3 -m json.tool customizations.json > /dev/null && bash -n scripts/build-push.sh && python3 -c "import yaml,sys; yaml.safe_load(open('deploy/stack.reference.yml'))"`
Expected: no output (all valid).

- [ ] **Step 6: Commit**

```bash
git add scripts/build-push.sh deploy/stack.reference.yml customizations.json
git commit -m "build: ship password-broker image + reference stack"
```

---

## Task 10: End-to-end manual verification

**Files:** none (verification).

- [ ] **Step 1: Set up a Zoho Self Client per client org**

For **each** client's Zoho org (you are admin in each): `api-console.zoho.com` → Self Client →
scopes `ZohoMail.organization.accounts.READ,ZohoMail.organization.accounts.UPDATE` → generate
code → exchange for a refresh token. Record `{clientId, clientSecret, refreshToken, zoid}` and
the client's email domain. Assemble the `ZOHO_TENANTS` JSON map (one entry per domain):

```json
{"client-a.com":{"clientId":"...","clientSecret":"...","refreshToken":"...","zoid":"111"},
 "client-b.com":{"clientId":"...","clientSecret":"...","refreshToken":"...","zoid":"222"}}
```

- [ ] **Step 2: Update the Portainer stack (manual)**

In Portainer, edit the roundcube stack from `deploy/stack.reference.yml`: add the `broker`
service, the two `AVUZ_BROKER_*` env vars on `roundcube`, and `depends_on: broker`. Add stack
env vars: `AVUZ_BROKER_SECRET` (generate `openssl rand -base64 32 | tr -d '='`) and
`ZOHO_TENANTS` (the JSON map from Step 1, as a single-line string). Redeploy.
Expected: `broker` logs `password-broker listening on 9000`; broker has no published port.

- [ ] **Step 3: Verify gating — non-Zoho session shows no Password tab**

Log in via SSO with a non-Zoho provider key → Settings → no "Password" entry. Confirms `password_hosts` gating.

- [ ] **Step 4: Verify forced first login for a Zoho user**

Create a fresh Zoho mailbox with a temp password → open standalone Roundcube → log in. Expected: bounced to the change-password screen (`?_first=1`), other navigation redirected back.

- [ ] **Step 5: Verify the change hits Zoho**

Enter correct current (temp) password + a new password → success message. Then: log out, log in with the **new** password (proves Zoho updated). Entering a wrong current password → error, no change.

- [ ] **Step 6: Final commit (docs/notes if any)**

```bash
git commit --allow-empty -m "test: verified forced zoho password change end-to-end"
```

---

## Self-Review Notes

- **Spec coverage:** broker isolation (T1,T6,T9), least-privilege scopes (T10), current-pass verify (T4,T5), token cache (T2), zuid+reset (T3), driver (T7), native force + gating + in-place via config (T8), compose/build (T9), Zoho API contract (T3 verbatim), error mapping (T5,T7). All spec sections map to a task.
- **Gating depends on exact `storage_host`** — T8 Step 1 verifies it before relying on `password_hosts`.
- **No forced logout** — native in-place update, per decision.
