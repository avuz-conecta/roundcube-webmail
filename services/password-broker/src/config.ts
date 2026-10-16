export type ZohoOrg = {
  clientId: string;
  clientSecret: string;
  refreshToken: string;
  zoid: string;
  // Per-tenant toggle for the forced first-login password change.
  // Absent = true (force), for backward compatibility.
  forcePasswordChange?: boolean;
  // E-mail of the account that owns this tenant's Zoho API client.
  // Zoho accepts its reset but does not apply it, and revokes the refresh token.
  tokenOwner?: string;
};

export type BrokerConfig = {
  port: number;
  sharedSecret: string;
  imap: { host: string; port: number };
  tenants: Map<string, ZohoOrg>;
};

const parseTenants = (raw: string): Map<string, ZohoOrg> => {
  const parsed = JSON.parse(raw) as Record<string, ZohoOrg>;
  return new Map(Object.entries(parsed).map(([domain, org]) => [domain.toLowerCase(), org]));
};

export const loadConfig = (env: NodeJS.ProcessEnv): BrokerConfig => {
  const required = (key: string): string => {
    const value = env[key];
    if (!value) throw new Error(`missing env ${key}`);
    return value;
  };

  return {
    port: Number(env.PORT ?? 9000),
    sharedSecret: required("BROKER_SHARED_SECRET"),
    imap: { host: env.ZOHO_IMAP_HOST ?? "imap.zoho.com", port: Number(env.ZOHO_IMAP_PORT ?? 993) },
    tenants: parseTenants(required("ZOHO_TENANTS")),
  };
};

export const resolveTenant = (tenants: Map<string, ZohoOrg>, email: string): ZohoOrg | null => {
  const domain = email.split("@")[1]?.toLowerCase();
  if (!domain) return null;
  return tenants.get(domain) ?? null;
};

// Whether a first-login password change should be forced for this email:
// the domain must be a tenant AND its forcePasswordChange flag must be true
// (absent flag defaults to true). Non-tenant domains are never forced.
export const shouldForcePasswordChange = (tenants: Map<string, ZohoOrg>, email: string): boolean => {
  const org = resolveTenant(tenants, email);
  return org !== null && (org.forcePasswordChange ?? true);
};

const normaliseEmail = (email: string): string => email.trim().toLowerCase();

// Whether this email owns the tenant's Zoho API client. Unset, empty or blank tokenOwner = no owner.
export const isTokenOwner = (org: ZohoOrg, email: string): boolean => {
  if (typeof org.tokenOwner !== "string") return false;
  const owner = normaliseEmail(org.tokenOwner);
  return owner !== "" && owner === normaliseEmail(email);
};
