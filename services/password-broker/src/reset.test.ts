import { describe, expect, test, vi } from "vitest";
import { createResetPassword } from "./reset.js";
import type { ZohoOrg } from "./config.js";

const org: ZohoOrg = { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "1" };
const createBase = () => ({
  resolveOrg: (_email: string): ZohoOrg | null => org,
  verify: vi.fn(async () => true),
  findZuid: vi.fn(async () => "7"),
  reset: vi.fn(async () => {}),
});
const input = { email: "a@x.com", currentPass: "cur", newPass: "new" };

describe("reset flow", () => {
  test("200 on full success", async () => {
    const run = createResetPassword({ ...createBase() });
    expect((await run(input)).status).toBe(200);
  });

  test("422 when domain has no tenant", async () => {
    const run = createResetPassword({ ...createBase(), resolveOrg: () => null });
    expect((await run(input)).status).toBe(422);
  });

  test("403 when current password wrong", async () => {
    const base = createBase();
    const run = createResetPassword({ ...base, verify: vi.fn(async () => false) });
    const result = await run(input);
    expect(result.status).toBe(403);
    expect(base.reset).not.toHaveBeenCalled();
  });

  test("404 when account not found", async () => {
    const run = createResetPassword({ ...createBase(), findZuid: vi.fn(async () => null) });
    expect((await run(input)).status).toBe(404);
  });

  test("502 when zoho throws", async () => {
    const run = createResetPassword({ ...createBase(), reset: vi.fn(async () => { throw new Error("boom"); }) });
    expect((await run(input)).status).toBe(502);
  });
});
