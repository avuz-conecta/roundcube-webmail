import { describe, expect, test, vi } from "vitest";
import { verifyImapPassword } from "./verify-password.js";

describe("verify imap password", () => {
  test("true when connect succeeds", async () => {
    const connect = vi.fn(async () => ({ logout: async () => {} }));
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "right");
    expect(ok).toBe(true);
  });

  test("false when connect rejects (bad auth)", async () => {
    const connect = vi.fn(async () => {
      throw new Error("auth failed");
    });
    const ok = await verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", "wrong");
    expect(ok).toBe(false);
  });
});
