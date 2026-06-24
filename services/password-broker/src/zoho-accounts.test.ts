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
