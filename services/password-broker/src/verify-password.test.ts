import { describe, expect, test, vi } from "vitest";
import { verifyImapPassword, type ImapConnect } from "./verify-password.js";

const failingConnect = (fields: Record<string, unknown>, message = "Command failed") =>
  vi.fn(async () => {
    throw Object.assign(new Error(message), fields);
  });

const verifyWith = (connect: ImapConnect, password = "secret") =>
  verifyImapPassword({ host: "imap.zoho.com", port: 993, connect }, "a@x.com", password);

describe("verify imap password", () => {
  describe("accepted", () => {
    test("returns true when connect succeeds", async () => {
      const connect = vi.fn(async () => ({ logout: async () => {} }));
      expect(await verifyWith(connect)).toBe(true);
    });

    test("returns true when connect succeeds but logout throws", async () => {
      const connect = vi.fn(async () => ({
        logout: async () => {
          throw new Error("logout failed");
        },
      }));
      expect(await verifyWith(connect)).toBe(true);
    });
  });

  describe("server rejection", () => {
    test("returns false on a tagged NO reply", async () => {
      const connect = failingConnect({ authenticationFailed: true, responseStatus: "NO" });
      expect(await verifyWith(connect)).toBe(false);
    });

    test("returns false on an AUTHENTICATIONFAILED response code", async () => {
      const connect = failingConnect({ authenticationFailed: true, responseStatus: "NO", serverResponseCode: "AUTHENTICATIONFAILED" });
      expect(await verifyWith(connect)).toBe(false);
    });
  });

  describe("connection drop", () => {
    test("throws when authenticationFailed comes without a server reply", async () => {
      const connect = failingConnect({ authenticationFailed: true, code: "NoConnection" }, "Connection not available");
      await expect(verifyWith(connect)).rejects.toThrow("Connection not available");
    });

    test("throws when authenticationFailed comes with a BAD reply", async () => {
      const connect = failingConnect({ authenticationFailed: true, responseStatus: "BAD" });
      await expect(verifyWith(connect)).rejects.toThrow("Command failed");
    });

    test("throws on ETIMEDOUT", async () => {
      const connect = failingConnect({ code: "ETIMEDOUT" }, "ETIMEDOUT");
      await expect(verifyWith(connect)).rejects.toThrow("ETIMEDOUT");
    });

    test("throws on ECONNRESET", async () => {
      const connect = failingConnect({ code: "ECONNRESET" }, "read ECONNRESET");
      await expect(verifyWith(connect)).rejects.toThrow("ECONNRESET");
    });
  });
});
