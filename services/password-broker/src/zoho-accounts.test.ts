import { describe, expect, test, vi } from "vitest";
import { findAccountByEmail, resetZohoPassword, ZohoResetError } from "./zoho-accounts.js";

// Mirrors the real Zoho org-users shape: accountId, zuid,
// emailAddress as an array of { mailId, ... }.
const page = (users: Array<{ accountId: string; zuid: number; emailAddress: Array<{ mailId: string }> }>) =>
  new Response(JSON.stringify({ data: users }), { status: 200 });

const user = (accountId: string, zuid: number, mail: string) => ({ accountId, zuid, emailAddress: [{ mailId: mail }] });

const TOKEN = "oauth-token-do-not-leak";
const NEW_PASS = "N3w-Pass-do-not-leak";
const account = { accountId: "5834474000000008002", zuid: 928684132 };

const resetWith = (response: Response) => {
  const fetchImpl = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => response);
  const deps = { zoid: "9", getToken: async () => TOKEN, fetchImpl };
  return { fetchImpl, run: () => resetZohoPassword(deps, account, NEW_PASS) };
};

const zohoJson = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

const rejection = async (run: () => Promise<unknown>): Promise<ZohoResetError> => {
  const error = await run().then(
    () => null,
    (caught: unknown) => caught,
  );
  if (!(error instanceof ZohoResetError)) throw new Error("expected a ZohoResetError");
  return error;
};

describe("zoho accounts", () => {
  describe("findAccountByEmail", () => {
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

    test("keeps only whitelisted fields of an error body", async () => {
      const body = { status: { code: 401, description: "Invalid OAuthToken" }, echo: TOKEN };
      const fetchImpl = vi.fn().mockResolvedValueOnce(zohoJson(body, 401));
      const deps = { zoid: "9", getToken: async () => TOKEN, fetchImpl };
      const error = await findAccountByEmail(deps, "a@x.com").catch((caught: unknown) => caught);
      expect(String(error)).toContain("zoho users http 401 status 401: Invalid OAuthToken");
      expect(String(error)).not.toContain(TOKEN);
    });
  });

  describe("resetZohoPassword", () => {
    describe("request", () => {
      test("PUTs to the documented zuid path", async () => {
        const { fetchImpl, run } = resetWith(zohoJson({ status: { code: 200, description: "success" } }));
        await run();
        const [url, init] = fetchImpl.mock.calls[0] ?? [];
        expect(String(url)).toBe("https://mail.zoho.com/api/organization/9/accounts/928684132");
        expect(init?.method).toBe("PUT");
      });

      test("sends zuid, a permanent password and signs out other sessions", async () => {
        const { fetchImpl, run } = resetWith(zohoJson({ status: { code: 200, description: "success" } }));
        await run();
        const [, init] = fetchImpl.mock.calls[0] ?? [];
        expect(JSON.parse(String(init?.body))).toEqual({
          zuid: 928684132,
          password: NEW_PASS,
          mode: "resetPassword",
          oneTimePassword: false,
          resetAuthToken: true,
        });
      });
    });

    describe("success", () => {
      test("returns the whitelisted reply when status.code is 200", async () => {
        const { run } = resetWith(zohoJson({ status: { code: 200, description: "success" } }));
        expect(await run()).toEqual({ httpStatus: 200, zohoCode: 200, description: "success", errorCode: null, moreInfo: null });
      });
    });

    describe("rejection", () => {
      test("throws when HTTP 200 carries status.code 500", async () => {
        const { run } = resetWith(zohoJson({ status: { code: 500, description: "Internal Error" } }));
        const error = await rejection(run);
        expect(error.reply).toMatchObject({ httpStatus: 200, zohoCode: 500, description: "Internal Error" });
      });

      test("throws when HTTP 200 carries status.code 400 with error data", async () => {
        const body = { status: { code: 400, description: "Invalid Input" }, data: { errorCode: "INVALID_INPUT", moreInfo: "zuid is null" } };
        const { run } = resetWith(zohoJson(body));
        const error = await rejection(run);
        expect(error.reply).toEqual({ httpStatus: 200, zohoCode: 400, description: "Invalid Input", errorCode: "INVALID_INPUT", moreInfo: "zuid is null" });
      });

      test("throws when the body is not JSON", async () => {
        const { run } = resetWith(new Response("<html>gateway</html>", { status: 200 }));
        const error = await rejection(run);
        expect(error.reply).toMatchObject({ httpStatus: 200, zohoCode: null });
      });

      test("throws when the body is empty", async () => {
        const { run } = resetWith(new Response("", { status: 200 }));
        const error = await rejection(run);
        expect(error.reply.zohoCode).toBeNull();
      });

      test("throws when the body has no status", async () => {
        const { run } = resetWith(zohoJson({ data: {} }));
        const error = await rejection(run);
        expect(error.reply.zohoCode).toBeNull();
      });

      test("throws on non-2xx even when the body claims success", async () => {
        const { run } = resetWith(zohoJson({ status: { code: 200, description: "success" } }, 500));
        const error = await rejection(run);
        expect(error.reply.httpStatus).toBe(500);
      });

      test("never echoes the raw body, password or token in the error", async () => {
        const body = { status: { code: 400, description: "Bad" }, data: { errorCode: "X" }, raw: `${NEW_PASS} ${TOKEN}` };
        const { run } = resetWith(zohoJson(body, 400));
        const error = await rejection(run);
        const serialized = `${error.message} ${JSON.stringify(error.reply)}`;
        expect(serialized).not.toContain(NEW_PASS);
        expect(serialized).not.toContain(TOKEN);
      });
    });
  });
});
