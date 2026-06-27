import { describe, expect, test, vi } from "vitest";
import { findAccountIdByEmail, resetZohoPassword } from "./zoho-accounts.js";

// Mirrors the real Zoho org-users shape: accountId is the long id string used by
// the reset endpoint; emailAddress is an array of { mailId, ... }.
const page = (users: Array<{ accountId: string; emailAddress: Array<{ mailId: string }> }>) =>
  new Response(JSON.stringify({ data: users }), { status: 200 });

const user = (accountId: string, mail: string) => ({ accountId, emailAddress: [{ mailId: mail }] });

describe("zoho accounts", () => {
  test("finds accountId across pages", async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(page([user("100", "a@x.com")]))
      .mockResolvedValueOnce(page([user("200", "b@x.com")]))
      .mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountIdByEmail(deps, "B@X.com")).toBe("200");
  });

  test("matches a non-primary mailId on the account", async () => {
    const acct = { accountId: "700", emailAddress: [{ mailId: "primary@x.com" }, { mailId: "alias@x.com" }] };
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([acct]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountIdByEmail(deps, "alias@x.com")).toBe("700");
  });

  test("returns null when not found", async () => {
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountIdByEmail(deps, "missing@x.com")).toBeNull();
  });

  test("reset issues PUT to accountId path with resetPassword mode", async () => {
    const fetchImpl = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ status: { code: 200 } }), { status: 200 }));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    await resetZohoPassword(deps, "5834474000000008002", "newpass");
    const call = fetchImpl.mock.calls[0];
    if (!call) throw new Error("expected fetchImpl to have been called");
    const [url, init] = call;
    if (!init) throw new Error("expected fetchImpl to receive a RequestInit");
    expect(url).toContain("/organization/9/accounts/5834474000000008002");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(String(init.body))).toEqual({ password: "newpass", mode: "resetPassword" });
  });
});
