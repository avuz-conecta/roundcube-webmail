import { describe, expect, test, vi } from "vitest";
import { findAccountByEmail, resetZohoPassword } from "./zoho-accounts.js";

// Mirrors the real Zoho org-users shape: accountId (path id), zuid (body id),
// emailAddress as an array of { mailId, ... }.
const page = (users: Array<{ accountId: string; zuid: number; emailAddress: Array<{ mailId: string }> }>) =>
  new Response(JSON.stringify({ data: users }), { status: 200 });

const user = (accountId: string, zuid: number, mail: string) => ({ accountId, zuid, emailAddress: [{ mailId: mail }] });

describe("zoho accounts", () => {
  test("finds account across pages", async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(page([user("100", 11, "a@x.com")]))
      .mockResolvedValueOnce(page([user("200", 22, "b@x.com")]))
      .mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountByEmail(deps, "B@X.com")).toEqual({ accountId: "200", zuid: 22 });
  });

  test("matches a non-primary mailId on the account", async () => {
    const acct = { accountId: "700", zuid: 77, emailAddress: [{ mailId: "primary@x.com" }, { mailId: "alias@x.com" }] };
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([acct]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountByEmail(deps, "alias@x.com")).toEqual({ accountId: "700", zuid: 77 });
  });

  test("returns null when not found", async () => {
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findAccountByEmail(deps, "missing@x.com")).toBeNull();
  });

  test("reset PUTs to accountId path with zuid+password+mode in body", async () => {
    const fetchImpl = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ status: { code: 200 } }), { status: 200 }));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    await resetZohoPassword(deps, { accountId: "5834474000000008002", zuid: 928684132 }, "newpass");
    const call = fetchImpl.mock.calls[0];
    if (!call) throw new Error("expected fetchImpl to have been called");
    const [url, init] = call;
    if (!init) throw new Error("expected fetchImpl to receive a RequestInit");
    expect(url).toContain("/organization/9/accounts/5834474000000008002");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(String(init.body))).toEqual({ password: "newpass", mode: "resetPassword", zuid: 928684132 });
  });
});
