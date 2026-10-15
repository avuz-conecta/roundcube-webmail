import type { ZohoOrg } from "./config.js";
import { ZohoResetError, type ZohoAccount, type ZohoReply } from "./zoho-accounts.js";
import type { ResetInput, ResetResult } from "./server.js";

// Wait before each IMAP confirm attempt. Total sleep stays far below webmail's 30 s broker timeout.
export const CONFIRM_DELAYS_MS: readonly number[] = [1000, 2000, 4000];
const REDACTED = "<redacted>";

export type ResetDeps = {
  resolveOrg: (email: string) => ZohoOrg | null;
  verify: (email: string, pass: string) => Promise<boolean>;
  findAccount: (org: ZohoOrg, email: string) => Promise<ZohoAccount | null>;
  reset: (org: ZohoOrg, account: ZohoAccount, newPass: string) => Promise<ZohoReply>;
  confirmDelaysMs?: readonly number[];
  sleep?: (ms: number) => Promise<void>;
  now?: () => number;
};

type ResetLog = {
  event: "broker.reset";
  email: string;
  zoid: string | null;
  accountId: string | null;
  zuid: number | null;
  role: string | null;
  primary: boolean | null;
  zoho: ZohoReply | null;
  zohoMs: number | null;
  confirmAttempts: number;
  confirmMs: number | null;
  confirmFailure: string | null;
  failure: string | null;
};

type ConfirmOutcome = { confirmed: boolean; attempts: number; failure: string | null };

const sleepFor = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

const describeError = (error: unknown): string => {
  if (!(error instanceof Error)) return String(error);
  const code = "code" in error ? error.code : undefined;
  return typeof code === "string" ? `${code}: ${error.message}` : error.message;
};

const redact = (line: string, secrets: string[]): string =>
  secrets
    .filter((secret) => secret.length > 0)
    .flatMap((secret) => [secret, JSON.stringify(secret).slice(1, -1)])
    .reduce((text, secret) => text.split(secret).join(REDACTED), line);

const confirmNewPassword = async (deps: ResetDeps, email: string, newPass: string): Promise<ConfirmOutcome> => {
  const delays = deps.confirmDelaysMs ?? CONFIRM_DELAYS_MS;
  const sleep = deps.sleep ?? sleepFor;
  let failure: string | null = null;

  for (const [index, delayMs] of delays.entries()) {
    await sleep(delayMs);
    try {
      if (await deps.verify(email, newPass)) return { confirmed: true, attempts: index + 1, failure: null };
      failure = "new password rejected";
    } catch (error) {
      failure = describeError(error);
    }
  }
  return { confirmed: false, attempts: delays.length, failure };
};

export const createResetPassword = (deps: ResetDeps): ((input: ResetInput) => Promise<ResetResult>) => async (input) => {
  const now = deps.now ?? Date.now;
  const startedAt = now();
  const log: ResetLog = {
    event: "broker.reset",
    email: input.email,
    zoid: null,
    accountId: null,
    zuid: null,
    role: null,
    primary: null,
    zoho: null,
    zohoMs: null,
    confirmAttempts: 0,
    confirmMs: null,
    confirmFailure: null,
    failure: null,
  };

  const finish = (status: ResetResult["status"], error?: string): ResetResult => {
    const outcome = error ?? "ok";
    const line = JSON.stringify({ ...log, status, outcome, durationMs: now() - startedAt });
    const write = status === 200 ? console.log : console.error;
    write(redact(line, [input.currentPass, input.newPass]));
    return error === undefined ? { status, body: { ok: true } } : { status, body: { ok: false, error } };
  };

  const org = deps.resolveOrg(input.email);
  if (org === null) return finish(422, "unknown tenant domain");
  log.zoid = org.zoid;
  let zohoStartedAt = startedAt;

  try {
    const valid = await deps.verify(input.email, input.currentPass);
    if (!valid) return finish(403, "current password incorrect");

    const account = await deps.findAccount(org, input.email);
    if (account === null) return finish(404, "account not found");
    log.accountId = account.accountId;
    log.zuid = account.zuid;
    log.role = account.role;
    log.primary = account.primary;

    zohoStartedAt = now();
    log.zoho = await deps.reset(org, account, input.newPass);
    log.zohoMs = now() - zohoStartedAt;

    const confirmStartedAt = now();
    const confirm = await confirmNewPassword(deps, input.email, input.newPass);
    log.confirmMs = now() - confirmStartedAt;
    log.confirmAttempts = confirm.attempts;
    log.confirmFailure = confirm.failure;
    if (!confirm.confirmed) return finish(502, "reset not confirmed");

    return finish(200);
  } catch (error) {
    log.failure = describeError(error);
    if (!(error instanceof ZohoResetError)) return finish(502, "upstream error");

    log.zoho = error.reply;
    log.zohoMs = now() - zohoStartedAt;
    return finish(502, "zoho rejected reset");
  }
};
