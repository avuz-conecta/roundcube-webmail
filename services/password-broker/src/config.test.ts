import { describe, expect, test } from "vitest";
import { isTokenOwner, loadConfig, resolveTenant, shouldForcePasswordChange, type ZohoOrg } from "./config.js";

const tenantsJson = '{"Client-A.com":{"clientId":"i","clientSecret":"s","refreshToken":"r","zoid":"111"}}';
const env = { BROKER_SHARED_SECRET: "x", ZOHO_TENANTS: tenantsJson } as NodeJS.ProcessEnv;

const multiTenantEnv = {
  BROKER_SHARED_SECRET: "x",
  ZOHO_TENANTS: JSON.stringify({
    "force-on.com": { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "1", forcePasswordChange: true },
    "force-off.com": { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "2", forcePasswordChange: false },
    "no-flag.com": { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "3" },
  }),
} as NodeJS.ProcessEnv;

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

describe("shouldForcePasswordChange", () => {
  test("forces when the tenant flag is true", () => {
    const { tenants } = loadConfig(multiTenantEnv);
    expect(shouldForcePasswordChange(tenants, "user@force-on.com")).toBe(true);
  });

  test("does not force when the tenant flag is false", () => {
    const { tenants } = loadConfig(multiTenantEnv);
    expect(shouldForcePasswordChange(tenants, "user@force-off.com")).toBe(false);
  });

  test("defaults to force when the flag is absent (backward compatible)", () => {
    const { tenants } = loadConfig(multiTenantEnv);
    expect(shouldForcePasswordChange(tenants, "user@no-flag.com")).toBe(true);
  });

  test("never forces a non-tenant domain", () => {
    const { tenants } = loadConfig(multiTenantEnv);
    expect(shouldForcePasswordChange(tenants, "user@stranger.com")).toBe(false);
  });
});

describe("tokenOwner", () => {
  const tokenOwnerEnv = {
    BROKER_SHARED_SECRET: "x",
    ZOHO_TENANTS: JSON.stringify({
      "owned.com": { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "1", tokenOwner: " Admin@Owned.com " },
      "unowned.com": { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "2" },
    }),
  } as NodeJS.ProcessEnv;
  const baseOrg: ZohoOrg = { clientId: "i", clientSecret: "s", refreshToken: "r", zoid: "1" };

  test("parses tokenOwner from ZOHO_TENANTS", () => {
    const { tenants } = loadConfig(tokenOwnerEnv);
    expect(resolveTenant(tenants, "user@owned.com")?.tokenOwner).toBe(" Admin@Owned.com ");
  });

  test("keeps tokenOwner optional", () => {
    const { tenants } = loadConfig(tokenOwnerEnv);
    expect(resolveTenant(tenants, "user@unowned.com")?.tokenOwner).toBeUndefined();
  });

  test("matches the owner trimmed and case-insensitively", () => {
    const { tenants } = loadConfig(tokenOwnerEnv);
    const org = resolveTenant(tenants, "admin@owned.com");
    expect(org && isTokenOwner(org, "  ADMIN@owned.COM ")).toBe(true);
  });

  test("does not match another account on the same tenant", () => {
    expect(isTokenOwner({ ...baseOrg, tokenOwner: "admin@owned.com" }, "user@owned.com")).toBe(false);
  });

  test.each([undefined, "", "   "])("matches nobody when tokenOwner is %j", (tokenOwner) => {
    expect(isTokenOwner({ ...baseOrg, tokenOwner }, "")).toBe(false);
    expect(isTokenOwner({ ...baseOrg, tokenOwner }, "   ")).toBe(false);
  });
});
