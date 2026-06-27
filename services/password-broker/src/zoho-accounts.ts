export type AccountsDeps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch };

const BASE = "https://mail.zoho.com/api/organization";
const PAGE_SIZE = 200;

const authHeaders = async (deps: AccountsDeps): Promise<Record<string, string>> => ({
  Authorization: `Zoho-oauthtoken ${await deps.getToken()}`,
  "Content-Type": "application/json",
});

// Zoho's reset needs the accountId in the path AND the zuid in the body — passing
// zuid in the path with no body zuid returns 400 "zuid is null". So we look up both.
export type ZohoAccount = { accountId: string; zuid: number };

export const findAccountByEmail = async (deps: AccountsDeps, email: string): Promise<ZohoAccount | null> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const target = email.toLowerCase();

  for (let start = 0; ; start += PAGE_SIZE) {
    const url = `${BASE}/${deps.zoid}/accounts?start=${start}&limit=${PAGE_SIZE}`;
    const response = await fetchImpl(url, { headers: await authHeaders(deps) });
    if (!response.ok) {
      throw new Error(`zoho users http ${response.status}: ${(await response.text()).slice(0, 300)}`);
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

export const resetZohoPassword = async (deps: AccountsDeps, account: ZohoAccount, newPass: string): Promise<void> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const url = `${BASE}/${deps.zoid}/accounts/${account.accountId}`;
  const response = await fetchImpl(url, {
    method: "PUT",
    headers: await authHeaders(deps),
    body: JSON.stringify({ password: newPass, mode: "resetPassword", zuid: account.zuid }),
  });
  if (!response.ok) {
    throw new Error(`zoho reset http ${response.status}: ${(await response.text()).slice(0, 300)}`);
  }
};
