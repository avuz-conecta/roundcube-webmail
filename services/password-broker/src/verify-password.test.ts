import { describe, expect, test, vi } from "vitest";
import { verifyImapPassword } from "./verify-password.js";

describe("verify imap password", () => {
  test("true when connect succeeds", async () => {
    const connect = vi.fn(async () => ({ logout: async () => {} }));
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "right");
    expect(ok).toBe(true);
  });

  test("false when connect rejects with an authentication failure", async () => {
    const connect = vi.fn(async () => {
      const error = new Error("Authentication failed") as Error & { authenticationFailed: true };
      error.authenticationFailed = true;
      throw error;
    });
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "wrong");
    expect(ok).toBe(false);
  });

  test("throws when connect rejects with a non-auth (network/transport) error", async () => {
    const connect = vi.fn(async () => {
      throw new Error("ETIMEDOUT");
    });
    await expect(verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "right")).rejects.toThrow("ETIMEDOUT");
  });

  test("true when connect succeeds but logout throws", async () => {
    const connect = vi.fn(async () => ({
      logout: async () => {
        throw new Error("logout failed");
      },
    }));
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "right");
    expect(ok).toBe(true);
  });
});
