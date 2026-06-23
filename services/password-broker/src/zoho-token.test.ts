import { describe, expect, test, vi } from "vitest";
import { createTokenProvider } from "./zoho-token.js";

const org = { clientId: "id", clientSecret: "sec", refreshToken: "ref", zoid: "1" };

describe("token provider", () => {
  test("fetches then caches the access token", async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: "abc", expires_in: 3600 }), { status: 200 }));
    const getToken = createTokenProvider(org, { fetchImpl, now: () => 0 });
    expect(await getToken()).toBe("abc");
    expect(await getToken()).toBe("abc");
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  test("refreshes after expiry", async () => {
    let token = "first";
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ access_token: token, expires_in: 3600 }), { status: 200 }));
    let clock = 0;
    const getToken = createTokenProvider(org, { fetchImpl, now: () => clock });
    expect(await getToken()).toBe("first");
    token = "second";
    clock = 3600_000;
    expect(await getToken()).toBe("second");
    expect(fetchImpl).toHaveBeenCalledTimes(2);
  });
});
