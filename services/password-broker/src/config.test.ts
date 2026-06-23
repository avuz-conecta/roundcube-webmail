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
