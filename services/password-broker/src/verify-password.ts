import { ImapFlow } from "imapflow";

type ImapClient = { logout: () => Promise<void> };
export type ImapConnect = (opts: { host: string; port: number; secure: boolean; auth: { user: string; pass: string } }) => Promise<ImapClient>;
export type VerifyDeps = { host: string; port: number; connect?: ImapConnect };

const defaultConnect: ImapConnect = async (opts) => {
  const client = new ImapFlow({ ...opts, logger: false });
  await client.connect();
  return { logout: () => client.logout() };
};

export const verifyImapPassword = async (deps: VerifyDeps, email: string, password: string): Promise<boolean> => {
  const connect = deps.connect ?? defaultConnect;
  try {
    const client = await connect({ host: deps.host, port: deps.port, secure: true, auth: { user: email, pass: password } });
    await client.logout();
    return true;
  } catch {
    return false;
  }
};
