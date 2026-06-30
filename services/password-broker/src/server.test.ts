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
  isTenant: (email: string): boolean => email.endsWith("tenant.com"),
  forcePasswordChange: (email: string): boolean => email.endsWith("tenant.com") && !email.endsWith("noforce.tenant.com"),
};

const getIsTenant = async (server: ReturnType<typeof createServer>, headers: Record<string, string>, query: string) => {
  await new Promise<void>((resolve) => server.listen(0, resolve));
  const address = server.address();
  if (address === null || typeof address === "string") throw new Error("no port");
  const response = await fetch(`http://127.0.0.1:${address.port}/is-tenant${query}`, { headers });
  const body = await response.json();
  server.close();
  return { status: response.status, body };
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

  test("accepts the camelCase wire payload sent by the PHP driver", async () => {
    const response = await post(
      createServer(okDeps),
      { "x-broker-secret": "secret" },
      { email: "a@b.com", currentPass: "current-secret", newPass: "new-secret" },
    );
    expect(response.status).toBe(200);
  });

  test("rejects a snake_case payload with 400", async () => {
    const response = await post(
      createServer(okDeps),
      { "x-broker-secret": "secret" },
      { email: "a@b.com", current_pass: "current-secret", new_pass: "new-secret" },
    );
    expect(response.status).toBe(400);
  });
});

describe("broker /is-tenant", () => {
  test("rejects missing shared secret with 401", async () => {
    const { status } = await getIsTenant(createServer(okDeps), {}, "?email=user@tenant.com");
    expect(status).toBe(401);
  });

  test("returns tenant:true and forcePasswordChange:true for a forcing tenant", async () => {
    const { status, body } = await getIsTenant(createServer(okDeps), { "x-broker-secret": "secret" }, "?email=user@tenant.com");
    expect(status).toBe(200);
    expect(body).toEqual({ ok: true, tenant: true, forcePasswordChange: true });
  });

  test("returns forcePasswordChange:false for a tenant with forcing disabled", async () => {
    const { status, body } = await getIsTenant(createServer(okDeps), { "x-broker-secret": "secret" }, "?email=user@noforce.tenant.com");
    expect(status).toBe(200);
    expect(body).toEqual({ ok: true, tenant: true, forcePasswordChange: false });
  });

  test("returns tenant:false and forcePasswordChange:false for a non-tenant", async () => {
    const { status, body } = await getIsTenant(createServer(okDeps), { "x-broker-secret": "secret" }, "?email=user@other.com");
    expect(status).toBe(200);
    expect(body).toEqual({ ok: true, tenant: false, forcePasswordChange: false });
  });

  test("returns 400 when email query is missing", async () => {
    const { status } = await getIsTenant(createServer(okDeps), { "x-broker-secret": "secret" }, "");
    expect(status).toBe(400);
  });
});
