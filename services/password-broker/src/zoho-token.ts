import type { ZohoOrg } from "./config.js";

type TokenOpts = { fetchImpl?: typeof fetch; now?: () => number };

const TOKEN_URL = "https://accounts.zoho.com/oauth/v2/token";
const EXPIRY_SKEW_MS = 60_000;

export const createTokenProvider = (org: ZohoOrg, opts: TokenOpts = {}): (() => Promise<string>) => {
  const fetchImpl = opts.fetchImpl ?? fetch;
  const now = opts.now ?? Date.now;
  let cached: { token: string; expiresAt: number } | null = null;

  return async () => {
    if (cached && now() < cached.expiresAt - EXPIRY_SKEW_MS) return cached.token;

    const params = new URLSearchParams({
      refresh_token: org.refreshToken,
      client_id: org.clientId,
      client_secret: org.clientSecret,
      grant_type: "refresh_token",
    });
    const response = await fetchImpl(`${TOKEN_URL}?${params.toString()}`, { method: "POST" });
    if (!response.ok) throw new Error(`zoho token http ${response.status}`);

    const json = (await response.json()) as { access_token?: string; expires_in?: number };
    if (!json.access_token) throw new Error("zoho token missing access_token");

    cached = { token: json.access_token, expiresAt: now() + (json.expires_in ?? 3600) * 1000 };
    return cached.token;
  };
};
