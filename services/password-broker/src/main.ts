import { loadConfig, resolveTenant, type ZohoOrg } from "./config.js";
import { createServer } from "./server.js";
import { createTokenProvider } from "./zoho-token.js";
import { findAccountIdByEmail, resetZohoPassword } from "./zoho-accounts.js";
import { verifyImapPassword } from "./verify-password.js";
import { createResetPassword } from "./reset.js";

const config = loadConfig(process.env);

const tokenProviders = new Map<string, () => Promise<string>>();
const accountsDepsFor = (org: ZohoOrg) => {
  let getToken = tokenProviders.get(org.zoid);
  if (!getToken) {
    getToken = createTokenProvider(org);
    tokenProviders.set(org.zoid, getToken);
  }
  return { zoid: org.zoid, getToken };
};

const resetPassword = createResetPassword({
  resolveOrg: (email) => resolveTenant(config.tenants, email),
  verify: (email, pass) => verifyImapPassword(config.imap, email, pass),
  findAccountId: (org, email) => findAccountIdByEmail(accountsDepsFor(org), email),
  reset: (org, accountId, newPass) => resetZohoPassword(accountsDepsFor(org), accountId, newPass),
});

createServer({ sharedSecret: config.sharedSecret, resetPassword }).listen(config.port, () => {
  console.log(`password-broker listening on ${config.port}`);
});
