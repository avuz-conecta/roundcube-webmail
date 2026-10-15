export type AccountsDeps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch };

const BASE = "https://mail.zoho.com/api/organization";
const PAGE_SIZE = 200;
const ZOHO_SUCCESS_CODE = 200;
const REPLY_TEXT_LIMIT = 200;
const SIGN_OUT_OTHER_SESSIONS = true;

const authHeaders = async (deps: AccountsDeps): Promise<Record<string, string>> => ({
  Authorization: `Zoho-oauthtoken ${await deps.getToken()}`,
  "Content-Type": "application/json",
});

// accountId is kept for logging; the reset itself is addressed by zuid.
export type ZohoAccount = { accountId: string; zuid: number };

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
      data?: Array<{ accountId: string; zuid: number; emailAddress?: Array<{ mailId: string }> }>;
    };
    const users = json.data ?? [];
    if (users.length === 0) return null;

    const match = users.find((user) =>
      (user.emailAddress ?? []).some((entry) => entry.mailId.toLowerCase() === target)
    );
    if (match) return { accountId: match.accountId, zuid: match.zuid };
  }
};

// https://www.zoho.com/mail/help/api/put-reset-user-password.html
export const resetZohoPassword = async (deps: AccountsDeps, account: ZohoAccount, newPass: string): Promise<ZohoReply> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const response = await fetchImpl(`${BASE}/${deps.zoid}/accounts/${account.zuid}`, {
    method: "PUT",
    headers: await authHeaders(deps),
    body: JSON.stringify({
      zuid: account.zuid,
      password: newPass,
      mode: "resetPassword",
      oneTimePassword: false,
      resetAuthToken: SIGN_OUT_OTHER_SESSIONS,
    }),
  });

  const reply = await readZohoReply(response);
  if (!response.ok || reply.zohoCode !== ZOHO_SUCCESS_CODE) throw new ZohoResetError(reply);
  return reply;
};
