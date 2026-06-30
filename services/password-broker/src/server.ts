import http from "node:http";

export type ResetInput = { email: string; currentPass: string; newPass: string };
export type ResetResult = { status: 200 | 401 | 403 | 404 | 422 | 502; body: { ok: boolean; error?: string } };
export type ServerDeps = {
  sharedSecret: string;
  resetPassword: (input: ResetInput) => Promise<ResetResult>;
  isTenant: (email: string) => boolean;
};

const readJson = (request: http.IncomingMessage): Promise<unknown> =>
  new Promise((resolve, reject) => {
    let raw = "";
    request.on("data", (chunk) => (raw += chunk));
    request.on("end", () => {
      try {
        resolve(JSON.parse(raw || "{}"));
      } catch (error) {
        reject(error);
      }
    });
  });

const isResetInput = (value: unknown): value is ResetInput => {
  if (typeof value !== "object" || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return typeof candidate.email === "string" && typeof candidate.currentPass === "string" && typeof candidate.newPass === "string";
};

export const createServer = (deps: ServerDeps): http.Server =>
  http.createServer(async (request, response) => {
    const send = (status: number, body: unknown) => {
      response.writeHead(status, { "content-type": "application/json" });
      response.end(JSON.stringify(body));
    };

    const url = request.url ?? "";
    const secretOk = request.headers["x-broker-secret"] === deps.sharedSecret;

    if (request.method === "GET" && url.startsWith("/is-tenant")) {
      if (!secretOk) return send(401, { ok: false, error: "unauthorized" });
      const email = new URL(url, "http://localhost").searchParams.get("email");
      if (!email) return send(400, { ok: false, error: "bad request" });
      return send(200, { ok: true, tenant: deps.isTenant(email) });
    }

    if (request.method !== "POST" || url !== "/reset") return send(404, { ok: false, error: "not found" });
    if (!secretOk) return send(401, { ok: false, error: "unauthorized" });

    const payload = await readJson(request).catch(() => null);
    if (!isResetInput(payload)) return send(400, { ok: false, error: "bad request" });

    const result = await deps.resetPassword(payload);
    send(result.status, result.body);
  });
