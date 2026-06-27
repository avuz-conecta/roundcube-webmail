export type AccountsDeps = { zoid: string; getToken: () => Promise<string>; fetchImpl?: typeof fetch };

const BASE = "https://mail.zoho.com/api/organization";
const PAGE_SIZE = 200;

const authHeaders = async (deps: AccountsDeps): Promise<Record<string, string>> => ({
  Authorization: `Zoho-oauthtoken ${await deps.getToken()}`,
  "Content-Type": "application/json",
});

// Zoho's reset endpoint keys the account by accountId (the long numeric string),
// NOT zuid. Passing zuid in the path yields 400 "zuid is null". So we look up and
// return the accountId.
export const findAccountIdByEmail = async (deps: AccountsDeps, email: string): Promise<string | null> => {
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
      data?: Array<{ accountId: string; emailAddress?: Array<{ mailId: string }> }>;
    };
    const users = json.data ?? [];
    if (users.length === 0) return null;

    const match = users.find((user) =>
      (user.emailAddress ?? []).some((entry) => entry.mailId.toLowerCase() === target)
    );
    if (match) return match.accountId;
  }
};

export const resetZohoPassword = async (deps: AccountsDeps, accountId: string, newPass: string): Promise<void> => {
  const fetchImpl = deps.fetchImpl ?? fetch;
  const url = `${BASE}/${deps.zoid}/accounts/${accountId}`;
  const response = await fetchImpl(url, {
    method: "PUT",
    headers: await authHeaders(deps),
    body: JSON.stringify({ password: newPass, mode: "resetPassword" }),
  });
  if (!response.ok) {
    throw new Error(`zoho reset http ${response.status}: ${(await response.text()).slice(0, 300)}`);
  }
};
