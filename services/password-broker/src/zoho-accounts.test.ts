import { describe, expect, test, vi } from "vitest";
import { findZuidByEmail, resetZohoPassword } from "./zoho-accounts.js";

// Mirrors the real Zoho org-users shape: zuid is a number, emailAddress is an
// array of { mailId, ... }.
const page = (users: Array<{ zuid: number; emailAddress: Array<{ mailId: string }> }>) =>
  new Response(JSON.stringify({ data: users }), { status: 200 });

const user = (zuid: number, mail: string) => ({ zuid, emailAddress: [{ mailId: mail }] });

describe("zoho accounts", () => {
  test("finds zuid across pages (returns zuid as string)", async () => {
    const fetchImpl = vi
      .fn()
      .mockResolvedValueOnce(page([user(1, "a@x.com")]))
      .mockResolvedValueOnce(page([user(2, "b@x.com")]))
      .mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findZuidByEmail(deps, "B@X.com")).toBe("2");
  });

  test("matches a non-primary mailId on the account", async () => {
    const acct = { zuid: 7, emailAddress: [{ mailId: "primary@x.com" }, { mailId: "alias@x.com" }] };
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([acct]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findZuidByEmail(deps, "alias@x.com")).toBe("7");
  });

  test("returns null when not found", async () => {
    const fetchImpl = vi.fn().mockResolvedValueOnce(page([]));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    expect(await findZuidByEmail(deps, "missing@x.com")).toBeNull();
  });

  test("reset issues PUT with resetPassword mode", async () => {
    const fetchImpl = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify({ status: { code: 200 } }), { status: 200 }));
    const deps = { zoid: "9", getToken: async () => "tok", fetchImpl };
    await resetZohoPassword(deps, "2", "newpass");
    const call = fetchImpl.mock.calls[0];
    if (!call) throw new Error("expected fetchImpl to have been called");
    const [url, init] = call;
    if (!init) throw new Error("expected fetchImpl to receive a RequestInit");
    expect(url).toContain("/organization/9/accounts/2");
    expect(init.method).toBe("PUT");
    expect(JSON.parse(String(init.body))).toEqual({ password: "newpass", mode: "resetPassword" });
  });
});
