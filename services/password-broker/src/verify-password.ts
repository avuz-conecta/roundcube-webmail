import { ImapFlow } from "imapflow";

type ImapClient = { logout: () => Promise<void> };
export type ImapConnect = (opts: { host: string; port: number; secure: boolean; auth: { user: string; pass: string } }) => Promise<ImapClient>;
export type VerifyDeps = { host: string; port: number; connect?: ImapConnect };

const defaultConnect: ImapConnect = async (opts) => {
  const client = new ImapFlow({ ...opts, logger: false });
  await client.connect();
  return { logout: () => client.logout() };
};

// imapflow sets authenticationFailed on ANY failed LOGIN/AUTHENTICATE, including a socket
// closing mid-command (lib/commands/login.js, lib/commands/authenticate.js handleAuthError).
// Only a tagged server reply sets responseStatus (lib/imap-flow.js, NO/BAD branch) and the
// [AUTHENTICATIONFAILED] code (tools.getStatusCode). Without those, the server never answered.
const isServerRejection = (error: unknown): boolean => {
  if (typeof error !== "object" || error === null) return false;
  const candidate = error as Record<string, unknown>;
  if (candidate.authenticationFailed !== true) return false;
  return candidate.responseStatus === "NO" || candidate.serverResponseCode === "AUTHENTICATIONFAILED";
};

export const verifyImapPassword = async (deps: VerifyDeps, email: string, password: string): Promise<boolean> => {
  const connect = deps.connect ?? defaultConnect;

  let client: ImapClient;
  try {
    client = await connect({ host: deps.host, port: deps.port, secure: true, auth: { user: email, pass: password } });
  } catch (error) {
    if (isServerRejection(error)) return false;
    throw error;
  }

  try {
    await client.logout();
  } catch {
    // connect() already succeeded, so the password is valid; logout failure is irrelevant.
  }
  return true;
};
