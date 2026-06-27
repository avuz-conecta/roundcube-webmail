import type { ZohoOrg } from "./config.js";
import type { ZohoAccount } from "./zoho-accounts.js";
import type { ResetInput, ResetResult } from "./server.js";

export type ResetDeps = {
  resolveOrg: (email: string) => ZohoOrg | null;
  verify: (email: string, pass: string) => Promise<boolean>;
  findAccount: (org: ZohoOrg, email: string) => Promise<ZohoAccount | null>;
  reset: (org: ZohoOrg, account: ZohoAccount, newPass: string) => Promise<void>;
};

export const createResetPassword = (deps: ResetDeps): ((input: ResetInput) => Promise<ResetResult>) => async (input) => {
  const org = deps.resolveOrg(input.email);
  if (org === null) return { status: 422, body: { ok: false, error: "unknown tenant domain" } };

  try {
    const valid = await deps.verify(input.email, input.currentPass);
    if (!valid) return { status: 403, body: { ok: false, error: "current password incorrect" } };

    const account = await deps.findAccount(org, input.email);
    if (account === null) return { status: 404, body: { ok: false, error: "account not found" } };

    await deps.reset(org, account, input.newPass);
    return { status: 200, body: { ok: true } };
  } catch (error) {
    // Log the cause (no secrets in these messages) so a 502 isn't a black box.
    const reason = error instanceof Error ? error.message : String(error);
    console.error(`reset failed for ${input.email}: ${reason}`);
    return { status: 502, body: { ok: false, error: "upstream error" } };
  }
};
