export type AccountsDeps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch };

const BASE = "https://mail.zoho.com/api/organization";
const PAGE_SIZE = 200;
const ZOHO_SUCCESS_CODE = 200;
const REPLY_TEXT_LIMIT = 200;

const authHeaders = async (deps: AccountsDeps): Promise<Record<string, string>> => ({
  Authorization: `Zoho-oauthtoken ${await deps.getToken()}`,
  "Content-Type": "application/json",
});

// Reset needs accountId in the path AND zuid in the body. role and primary are
// logged only: they tell an admin/owner account or an alias match apart.
export type ZohoAccount = { accountId: string; zuid: number; role: string | null; primary: boolean | null };

// Whitelisted fields of a Zoho response. Never carries the raw body.
export type ZohoReply = {
  httpStatus: number;
  zohoCode: number | null;
  description: string | null;
  errorCode: string | null;
  moreInfo: string | null;
};

export class ZohoResetError extends Error {
  constructor(readonly reply: ZohoReply) {
    super(`zoho reset http ${reply.httpStatus} status ${reply.zohoCode ?? "none"}: ${reply.description ?? "no description"}`);
    this.name = "ZohoResetError";
  }
}

const field = (value: unknown, key: string): unknown => {
  if (typeof value !== "object" || value === null) return undefined;
  return (value as Record<string, unknown>)[key];
};

const shortText = (value: unknown): string | null => (typeof value === "string" ? value.slice(0, REPLY_TEXT_LIMIT) : null);

const parseJson = (text: string): unknown => {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
};

const readZohoReply = async (response: Response): Promise<ZohoReply> => {
  const json = parseJson(await response.text().catch(() => ""));
  const status = field(json, "status");
  const data = field(json, "data");
  const code = field(status, "code");

  return {
    httpStatus: response.status,
    zohoCode: typeof code === "number" ? code : null,
    description: shortText(field(status, "description")),
    errorCode: shortText(field(data, "errorCode")),
    moreInfo: shortText(field(data, "moreInfo")),
  };
};

export const findAccountByEmail = async (deps: AccountsDeps, email: string): Promise<ZohoAccount | null> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const target = email.toLowerCase();

  for (let start = 0; ; start += PAGE_SIZE) {
    const url = `${BASE}/${deps.zoid}/accounts?start=${start}&limit=${PAGE_SIZE}`;
    const response = await fetchImpl(url, { headers: await authHeaders(deps) });
    if (!response.ok) {
      const reply = await readZohoReply(response);
      throw new Error(`zoho users http ${reply.httpStatus} status ${reply.zohoCode ?? "none"}: ${reply.description ?? "no description"}`);
    }

    // emailAddress is an array of { mailId, isPrimary, ... }; match any mailId.
    const json = (await response.json()) as {
      data?: Array<{ accountId: string; zuid: number; role?: unknown; emailAddress?: Array<{ mailId: string; isPrimary?: unknown }> }>;
    };
    const users = json.data ?? [];
    if (users.length === 0) return null;

    for (const user of users) {
      const matched = (user.emailAddress ?? []).find((entry) => entry.mailId.toLowerCase() === target);
      if (!matched) continue;
      return {
        accountId: user.accountId,
        zuid: user.zuid,
        role: shortText(user.role),
        primary: typeof matched.isPrimary === "boolean" ? matched.isPrimary : null,
      };
    }
  }
};

// https://www.zoho.com/mail/help/api/put-reset-user-password.html
// The doc shows {zuid} in the path. Prod resets for every existing tenant work
// with {accountId} in the path and the prod broker 1.8.0 body, so that is what we send.
// With oneTimePassword and resetAuthToken added, Zoho replied EXTRA_KEY_FOUND_IN_JSON.
export const resetZohoPassword = async (deps: AccountsDeps, account: ZohoAccount, newPass: string): Promise<ZohoReply> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const response = await fetchImpl(`${BASE}/${deps.zoid}/accounts/${account.accountId}`, {
    method: "PUT",
    headers: await authHeaders(deps),
    body: JSON.stringify({ password: newPass, mode: "resetPassword", zuid: account.zuid }),
  });

  const reply = await readZohoReply(response);
  if (!response.ok || reply.zohoCode !== ZOHO_SUCCESS_CODE) throw new ZohoResetError(reply);
  return reply;
};
