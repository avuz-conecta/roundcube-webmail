import { afterEach, beforeEach, describe, expect, test, vi, type MockInstance } from "vitest";
import { createResetPassword, type ResetDeps } from "./reset.js";
import { resetZohoPassword, ZohoResetError, type ZohoAccount, type ZohoReply } from "./zoho-accounts.js";
import type { ZohoOrg } from "./config.js";

const CURRENT_PASS = "Curr3nt-do-not-leak";
const NEW_PASS = "N3w-Pass-do-not-leak";
const CLIENT_SECRET = "client-secret-do-not-leak";
const REFRESH_TOKEN = "refresh-token-do-not-leak";
const ACCESS_TOKEN = "access-token-do-not-leak";

const org: ZohoOrg = { clientId: "i", clientSecret: CLIENT_SECRET, refreshToken: REFRESH_TOKEN, zoid: "1" };
const input = { email: "a@x.com", currentPass: CURRENT_PASS, newPass: NEW_PASS };
const success: ZohoReply = { httpStatus: 200, zohoCode: 200, description: "success", errorCode: null, moreInfo: null };

const passwordsAccepted = (accepted: string[]) => vi.fn(async (_email: string, pass: string) => accepted.includes(pass));

const createDeps = (overrides: Partial<ResetDeps> = {}) => {
  const deps = {
    resolveOrg: (_email: string): ZohoOrg | null => org,
    verify: passwordsAccepted([CURRENT_PASS, NEW_PASS]),
    findAccount: vi.fn(async () => ({ accountId: "7", zuid: 77, role: "member", primary: true })),
    reset: vi.fn(async (): Promise<ZohoReply> => success),
    confirmDelaysMs: [10, 20, 40],
    sleep: vi.fn(async (_ms: number) => {}),
    now: () => 0,
    ...overrides,
  };
  return { deps, run: createResetPassword(deps) };
};

const newPassChecks = (verify: MockInstance) => verify.mock.calls.filter(([, pass]) => pass === NEW_PASS).length;

let logSpy: MockInstance;
let errorSpy: MockInstance;

const loggedLines = (): string[] => [...logSpy.mock.calls, ...errorSpy.mock.calls].map((args) => args.map(String).join(" "));
const lastLog = () => JSON.parse(loggedLines().at(-1) ?? "{}");

beforeEach(() => {
  logSpy = vi.spyOn(console, "log").mockImplementation(() => {});
  errorSpy = vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("reset flow", () => {
  describe("success", () => {
    test("returns 200 when Zoho confirms and the new password opens IMAP", async () => {
      const { run } = createDeps();
      expect(await run(input)).toEqual({ status: 200, body: { ok: true } });
    });

    test("confirms with the new password over IMAP", async () => {
      const { deps, run } = createDeps();
      await run(input);
      expect(deps.verify).toHaveBeenCalledWith(input.email, NEW_PASS);
    });

    test("returns 200 when the new password is accepted on the 2nd attempt", async () => {
      const verify = vi
        .fn(async (_email: string, _pass: string) => true)
        .mockResolvedValueOnce(true)
        .mockResolvedValueOnce(false)
        .mockResolvedValueOnce(true);
      const { deps, run } = createDeps({ verify });
      expect((await run(input)).status).toBe(200);
      expect(newPassChecks(verify)).toBe(2);
      expect(deps.sleep).toHaveBeenNthCalledWith(1, 10);
      expect(deps.sleep).toHaveBeenNthCalledWith(2, 20);
    });
  });

  describe("early exits", () => {
    test("returns 422 when the domain has no tenant", async () => {
      const { run } = createDeps({ resolveOrg: () => null });
      expect((await run(input)).status).toBe(422);
    });

    test("returns 403 when the current password is wrong", async () => {
      const { deps, run } = createDeps({ verify: passwordsAccepted([]) });
      expect(await run(input)).toEqual({ status: 403, body: { ok: false, error: "current password incorrect" } });
      expect(deps.reset).not.toHaveBeenCalled();
    });

    test("returns 502 when verifying the current password drops the connection", async () => {
      const verify = vi.fn(async () => {
        throw Object.assign(new Error("Connection not available"), { code: "NoConnection" });
      });
      const { deps, run } = createDeps({ verify });
      expect(await run(input)).toEqual({ status: 502, body: { ok: false, error: "upstream error" } });
      expect(deps.reset).not.toHaveBeenCalled();
    });

    test("returns 404 when the account is not found", async () => {
      const { run } = createDeps({ findAccount: vi.fn(async () => null) });
      expect((await run(input)).status).toBe(404);
    });
  });

  describe("zoho failure", () => {
    test("returns 502 'zoho rejected reset' when Zoho rejects", async () => {
      const reply: ZohoReply = { httpStatus: 200, zohoCode: 500, description: "Internal Error", errorCode: null, moreInfo: null };
      const verify = passwordsAccepted([CURRENT_PASS, NEW_PASS]);
      const { run } = createDeps({ verify, reset: vi.fn(async () => { throw new ZohoResetError(reply); }) });
      expect(await run(input)).toEqual({ status: 502, body: { ok: false, error: "zoho rejected reset" } });
      expect(newPassChecks(verify)).toBe(0);
    });

    test("returns 502 'upstream error' on any other Zoho failure", async () => {
      const { run } = createDeps({ reset: vi.fn(async () => { throw new Error("fetch failed"); }) });
      expect(await run(input)).toEqual({ status: 502, body: { ok: false, error: "upstream error" } });
    });
  });

  describe("confirm failure", () => {
    test("returns 502 'reset not confirmed' when IMAP rejects the new password on every attempt", async () => {
      const verify = passwordsAccepted([CURRENT_PASS]);
      const { run } = createDeps({ verify });
      expect(await run(input)).toEqual({ status: 502, body: { ok: false, error: "reset not confirmed" } });
      expect(newPassChecks(verify)).toBe(3);
    });

    test("returns 502 'reset not confirmed' when the confirm step hits connection errors", async () => {
      const verify = vi.fn(async (_email: string, pass: string) => {
        if (pass === CURRENT_PASS) return true;
        throw Object.assign(new Error("Socket timeout"), { code: "ETIMEOUT" });
      });
      const { run } = createDeps({ verify });
      expect(await run(input)).toEqual({ status: 502, body: { ok: false, error: "reset not confirmed" } });
    });

    test("logs an unconfirmed reset as an error with the last failure", async () => {
      const { run } = createDeps({ verify: passwordsAccepted([CURRENT_PASS]) });
      await run(input);
      expect(errorSpy).toHaveBeenCalledTimes(1);
      expect(lastLog()).toMatchObject({ outcome: "reset not confirmed", confirmAttempts: 3, confirmFailure: "new password rejected" });
    });
  });

  describe("logging", () => {
    test("writes one structured line per attempt with ids, Zoho status and timings", async () => {
      const { run } = createDeps();
      await run(input);
      expect(loggedLines()).toHaveLength(1);
      expect(lastLog()).toMatchObject({
        event: "broker.reset",
        email: "a@x.com",
        zoid: "1",
        accountId: "7",
        zuid: 77,
        role: "member",
        primary: true,
        zoho: { httpStatus: 200, zohoCode: 200, description: "success" },
        confirmAttempts: 1,
        status: 200,
        outcome: "ok",
      });
      expect(lastLog()).toHaveProperty("durationMs");
      expect(lastLog()).toHaveProperty("zohoMs");
      expect(lastLog()).toHaveProperty("confirmMs");
    });

    test("logs a 403 attempt too", async () => {
      const { run } = createDeps({ verify: passwordsAccepted([]) });
      await run(input);
      expect(lastLog()).toMatchObject({ status: 403, outcome: "current password incorrect" });
    });

    describe("when Zoho replies 404 EXTRA_KEY_FOUND_IN_JSON", () => {
      const extraKeyReply = {
        status: { code: 404, description: "Invalid Input" },
        data: { errorCode: "EXTRA_KEY_FOUND_IN_JSON", moreInfo: "fake-more-info" },
        echo: `${NEW_PASS} ${ACCESS_TOKEN}`,
      };
      const zohoReset = (fetchImpl: typeof fetch) => (resetOrg: ZohoOrg, account: ZohoAccount, newPass: string) =>
        resetZohoPassword({ zoid: resetOrg.zoid, getToken: async () => ACCESS_TOKEN, fetchImpl }, account, newPass);
      const runExtraKeyReset = async () => {
        const fetchImpl = vi.fn(async (_input: RequestInfo | URL, _init?: RequestInit) => new Response(JSON.stringify(extraKeyReply), { status: 404 }));
        const { run } = createDeps({ findAccount: vi.fn(async () => ({ accountId: "7", zuid: 77, role: "admin", primary: true })), reset: zohoReset(fetchImpl) });
        return run(input);
      };

      test("returns 502 zoho rejected reset", async () => {
        expect(await runExtraKeyReset()).toEqual({ status: 502, body: { ok: false, error: "zoho rejected reset" } });
      });

      test("names the errorCode and moreInfo in the logged line", async () => {
        await runExtraKeyReset();
        expect(errorSpy).toHaveBeenCalledTimes(1);
        expect(loggedLines().at(-1)).toContain('"errorCode":"EXTRA_KEY_FOUND_IN_JSON"');
        expect(lastLog()).toMatchObject({
          role: "admin",
          primary: true,
          zoho: { httpStatus: 404, zohoCode: 404, description: "Invalid Input", errorCode: "EXTRA_KEY_FOUND_IN_JSON", moreInfo: "fake-more-info" },
          status: 502,
          outcome: "zoho rejected reset",
        });
      });

      test("logs no password or token", async () => {
        await runExtraKeyReset();
        const output = loggedLines().join("\n");
        for (const secret of [CURRENT_PASS, NEW_PASS, ACCESS_TOKEN, CLIENT_SECRET, REFRESH_TOKEN]) expect(output).not.toContain(secret);
      });
    });

    test("never logs passwords or tenant secrets, even when Zoho echoes them", async () => {
      const reply: ZohoReply = { httpStatus: 400, zohoCode: 400, description: `bad ${NEW_PASS}`, errorCode: null, moreInfo: `${CURRENT_PASS}` };
      const outcomes = [
        createDeps(),
        createDeps({ verify: passwordsAccepted([CURRENT_PASS]) }),
        createDeps({ reset: vi.fn(async () => { throw new ZohoResetError(reply); }) }),
        createDeps({ reset: vi.fn(async () => { throw new Error(`boom ${NEW_PASS}`); }) }),
      ];
      for (const { run } of outcomes) await run(input);

      const output = loggedLines().join("\n");
      expect(loggedLines()).toHaveLength(outcomes.length);
      for (const secret of [CURRENT_PASS, NEW_PASS, CLIENT_SECRET, REFRESH_TOKEN]) expect(output).not.toContain(secret);
    });
  });
});
