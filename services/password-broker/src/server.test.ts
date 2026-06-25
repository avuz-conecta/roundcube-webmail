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
