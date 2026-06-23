export type ZohoOrg = { clientId: string; clientSecret: string; refreshToken: string; zoid: string };

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
