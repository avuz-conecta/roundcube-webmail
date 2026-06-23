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
- Create/Modify: `docker-compose.yml` — add broker service (if compose tracked here)

---

## Task 1: Broker scaffold + shared-secret guard

**Files:**
- Create: `services/password-broker/package.json`
- Create: `services/password-broker/tsconfig.json`
- Create: `services/password-broker/src/config.ts`
- Create: `services/password-broker/src/server.ts`
- Test: `services/password-broker/src/server.test.ts`

**Interfaces:**
- Produces: `createServer(deps: ServerDeps): http.Server`; `ServerDeps = { resetPassword: (input: ResetInput) => Promise<ResetResult> }`; `ResetInput = { email: string; currentPass: string; newPass: string }`; `ResetResult = { status: 200 | 401 | 403 | 404 | 502; body: { ok: boolean; error?: string } }`.
- Produces: `loadConfig(env: NodeJS.ProcessEnv): BrokerConfig` with `{ port, sharedSecret, zoho: { clientId, clientSecret, refreshToken, zoid } }`.

- [ ] **Step 1: Write package.json**

```json
{
  "name": "avuz-password-broker",
  "private": true,
  "type": "module",
  "scripts": {
    "build": "tsc",
    "start": "node dist/server.js",
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
export type BrokerConfig = {
  port: number;
  sharedSecret: string;
  zoho: { clientId: string; clientSecret: string; refreshToken: string; zoid: string };
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
    zoho: {
      clientId: required("ZOHO_CLIENT_ID"),
      clientSecret: required("ZOHO_CLIENT_SECRET"),
      refreshToken: required("ZOHO_REFRESH_TOKEN"),
      zoid: required("ZOHO_ZOID"),
    },
  };
};
```

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
export type ResetResult = { status: 200 | 401 | 403 | 404 | 502; body: { ok: boolean; error?: string } };
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
git add services/password-broker/package.json services/password-broker/tsconfig.json services/password-broker/src/config.ts services/password-broker/src/server.ts services/password-broker/src/server.test.ts
git commit -m "feat(broker): scaffold node service with shared-secret guard"
```

---

## Task 2: Zoho access-token cache

**Files:**
- Create: `services/password-broker/src/zoho-token.ts`
- Test: `services/password-broker/src/zoho-token.test.ts`

**Interfaces:**
- Consumes: `BrokerConfig.zoho` from Task 1.
- Produces: `createTokenProvider(zoho, opts?): () => Promise<string>` where `opts = { fetchImpl?: typeof fetch; now?: () => number }`. Returns a cached access token, refreshing when within 60s of expiry.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { createTokenProvider } from "./zoho-token.js";

const zoho = { clientId: "id", clientSecret: "sec", refreshToken: "ref", zoid: "1" };

describe("token provider", () => {
  test("fetches then caches the access token", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: "abc", expires_in: 3600 }), { status: 200 }));
    const getToken = createTokenProvider(zoho, { fetchImpl, now: () => 0 });
    expect(await getToken()).toBe("abc");
    expect(await getToken()).toBe("abc");
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  test("refreshes after expiry", async () => {
    let token = "first";
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: token, expires_in: 3600 }), { status: 200 }));
    let clock = 0;
    const getToken = createTokenProvider(zoho, { fetchImpl, now: () => clock });
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
import type { BrokerConfig } from "./config.js";

type TokenOpts = { fetchImpl?: typeof fetch; now?: () => number };

const TOKEN_URL = "https://accounts.zoho.com/oauth/v2/token";
const EXPIRY_SKEW_MS = 60_000;

export const createTokenProvider = (zoho: BrokerConfig["zoho"], opts: TokenOpts = {}): (() => Promise<string>) => {
  const fetchImpl = opts.fetchImpl ?? fetch;
  const now = opts.now ?? Date.now;
  let cached: { token: string; expiresAt: number } | null = null;

  return async () => {
    if (cached && now() < cached.expiresAt - EXPIRY_SKEW_MS) return cached.token;

    const params = new URLSearchParams({
      refresh_token: zoho.refreshToken,
      client_id: zoho.clientId,
      client_secret: zoho.clientSecret,
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
- Modify: `services/password-broker/src/server.ts` (no change to exports; reused)
- Modify: `services/password-broker/src/config.ts` (add IMAP host/port constants)
- Create: `services/password-broker/src/main.ts`
- Test: `services/password-broker/src/reset.test.ts`

**Interfaces:**
- Consumes: `verifyImapPassword` (Task 4), `findZuidByEmail` + `resetZohoPassword` (Task 3).
- Produces: `createResetPassword(deps): (input: ResetInput) => Promise<ResetResult>` where `deps = { verify: (email, pass) => Promise<boolean>; findZuid: (email) => Promise<string | null>; reset: (zuid, newPass) => Promise<void> }`. Mapping: bad current → 403; zuid null → 404; verify/reset throw → 502; success → 200.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, test, vi } from "vitest";
import { createResetPassword } from "./reset.js";

const base = {
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

  test("403 when current password wrong", async () => {
    const run = createResetPassword({ ...base, verify: vi.fn(async () => false) });
    const result = await run(input);
    expect(result.status).toBe(403);
    expect(base.reset).not.toHaveBeenCalledWith("7", "new");
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
import type { ResetInput, ResetResult } from "./server.js";

export type ResetDeps = {
  verify: (email: string, pass: string) => Promise<boolean>;
  findZuid: (email: string) => Promise<string | null>;
  reset: (zuid: string, newPass: string) => Promise<void>;
};

export const createResetPassword = (deps: ResetDeps): ((input: ResetInput) => Promise<ResetResult>) => async (input) => {
  try {
    const valid = await deps.verify(input.email, input.currentPass);
    if (!valid) return { status: 403, body: { ok: false, error: "current password incorrect" } };

    const zuid = await deps.findZuid(input.email);
    if (zuid === null) return { status: 404, body: { ok: false, error: "account not found" } };

    await deps.reset(zuid, input.newPass);
    return { status: 200, body: { ok: true } };
  } catch {
    return { status: 502, body: { ok: false, error: "upstream error" } };
  }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd services/password-broker && npm test reset`
Expected: PASS.

- [ ] **Step 5: Add IMAP constants to config.ts**

Append to `BrokerConfig` and `loadConfig`:

```ts
// in BrokerConfig type, add:
//   imap: { host: string; port: number };
// in loadConfig return, add:
//   imap: { host: env.ZOHO_IMAP_HOST ?? "imap.zoho.com", port: Number(env.ZOHO_IMAP_PORT ?? 993) },
```

Apply both edits to `src/config.ts`.

- [ ] **Step 6: Write main.ts (composition root)**

```ts
import { loadConfig } from "./config.js";
import { createServer } from "./server.js";
import { createTokenProvider } from "./zoho-token.js";
import { findZuidByEmail, resetZohoPassword } from "./zoho-accounts.js";
import { verifyImapPassword } from "./verify-password.js";
import { createResetPassword } from "./reset.js";

const config = loadConfig(process.env);
const getToken = createTokenProvider(config.zoho);
const accountsDeps = { zoid: config.zoho.zoid, getToken };

const resetPassword = createResetPassword({
  verify: (email, pass) => verifyImapPassword(config.imap, email, pass),
  findZuid: (email) => findZuidByEmail(accountsDeps, email),
  reset: (zuid, newPass) => resetZohoPassword(accountsDeps, zuid, newPass),
});

createServer({ sharedSecret: config.sharedSecret, resetPassword }).listen(config.port, () => {
  console.log(`password-broker listening on ${config.port}`);
});
```

- [ ] **Step 7: Build + run full test suite**

Run: `cd services/password-broker && npm run build && npm test`
Expected: `tsc` succeeds (no type errors), all tests PASS.

- [ ] **Step 8: Commit**

```bash
git add services/password-broker/src/reset.ts services/password-broker/src/reset.test.ts services/password-broker/src/config.ts services/password-broker/src/main.ts
git commit -m "feat(broker): compose reset flow + composition root"
```

---

## Task 6: Broker Dockerfile

**Files:**
- Create: `services/password-broker/Dockerfile`
- Create: `services/password-broker/.dockerignore`

**Interfaces:** none (produces an image running `node dist/server.js` → wait, entry is `main.ts` → `dist/main.js`).

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
- Test: `plugins/password/tests/ZohoBroker.php`

**Interfaces:**
- Consumes: broker `POST /reset` contract (Task 1/5): JSON `{email,current_pass,new_pass}`, header `X-Broker-Secret`, responses 200/403/404/502.
- Produces: `class rcube_zoho_broker_password` with `save($curpass, $newpass, $username): int` returning Roundcube codes. Static helper `rcube_zoho_broker_password::map_status(int $http): int` for testability.

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

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit plugins/password/tests/ZohoBroker.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write zoho_broker.php**

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

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit plugins/password/tests/ZohoBroker.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add plugins/password/drivers/zoho_broker.php plugins/password/tests/ZohoBroker.php
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

## Task 9: Compose service, build script, customizations

**Files:**
- Modify: `scripts/build-push.sh` (build/push broker image)
- Modify: `customizations.json`
- Modify/Create: `docker-compose.yml` (broker service) — only if compose is tracked in this repo; otherwise document in `customizations.json` for the Portainer stack.

**Interfaces:** none (deployment wiring).

- [ ] **Step 1: Inspect build-push.sh**

Run: `cat scripts/build-push.sh`
Note how the Roundcube image tag/registry are built so the broker follows the same pattern.

- [ ] **Step 2: Add broker build/push to build-push.sh**

After the Roundcube image build/push, add (adapt variable names to the existing script):

```bash
# Build + push the password-broker image
docker build -t "${REGISTRY}/avuz-password-broker:${TAG}" services/password-broker
docker push "${REGISTRY}/avuz-password-broker:${TAG}"
```

- [ ] **Step 3: Add the broker service to the compose/stack**

Add to the stack (alongside `roundcube`, `redis`), and add the two broker env vars to the `roundcube` service:

```yaml
  roundcube:
    environment:
      - AVUZ_BROKER_URL=http://broker:9000
      - AVUZ_BROKER_SECRET=$AVUZ_BROKER_SECRET

  broker:
    image: registry.avuz.app/admin/avuz-password-broker:staging
    restart: unless-stopped
    environment:
      - ZOHO_CLIENT_ID=$ZOHO_CLIENT_ID
      - ZOHO_CLIENT_SECRET=$ZOHO_CLIENT_SECRET
      - ZOHO_REFRESH_TOKEN=$ZOHO_REFRESH_TOKEN
      - ZOHO_ZOID=$ZOHO_ZOID
      - BROKER_SHARED_SECRET=$AVUZ_BROKER_SECRET
      - PORT=9000
```

(No `ports:` — internal only.)

- [ ] **Step 4: Record customizations**

Add entries to `customizations.json` for: `plugins/password/drivers/zoho_broker.php`, `services/password-broker/`, the password block in `config/config.inc.php`, and the broker compose service. Match the file's existing JSON shape.

- [ ] **Step 5: Verify JSON + shell**

Run: `python3 -m json.tool customizations.json > /dev/null && bash -n scripts/build-push.sh`
Expected: no output (both valid).

- [ ] **Step 6: Commit**

```bash
git add scripts/build-push.sh customizations.json docker-compose.yml
git commit -m "build: ship password-broker image + compose service"
```

---

## Task 10: End-to-end manual verification

**Files:** none (verification).

- [ ] **Step 1: Set up a Zoho Self Client**

In `api-console.zoho.com` → Self Client → scopes `ZohoMail.organization.accounts.READ,ZohoMail.organization.accounts.UPDATE` → generate code → exchange for a refresh token. Record `ZOHO_*` values + `ZOHO_ZOID`.

- [ ] **Step 2: Boot the stack locally with broker**

Run: `docker compose up -d` (with all env vars set incl. `BROKER_SHARED_SECRET`, `ZOHO_*`).
Expected: `broker` logs `password-broker listening on 9000`; not reachable from host (no published port).

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
