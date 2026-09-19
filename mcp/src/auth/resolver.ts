// The credential -> outbound-headers seam, per §2 and §4 of the interface spec.
//
// This is the one place that knows the bearer token a client presents to the sidecar
// *is* the Victual API key, forwarded unchanged. The household IdP future state (spec
// §4.1) replaces the body of this function — validate the IdP's token, map its subject
// to a Victual user, attach whatever server-to-server credential that design lands on —
// and touches nothing else in this tree.

export interface ResolvedCredential {
  /** Headers to attach to every REST call made on behalf of this request. */
  headers: Record<string, string>;
}

export class UnauthenticatedError extends Error {}

type HeaderSource = Headers | Record<string, string | string[] | undefined>;

function header(source: HeaderSource, name: string): string | undefined {
  if (source instanceof Headers) return source.get(name) ?? undefined;
  const value = source[name];
  return Array.isArray(value) ? value[0] : value;
}

/**
 * `Authorization: Bearer <key>` is canonical; the raw `VICTUAL-API-KEY: <key>` header is
 * also accepted for parity with Victual itself (§4.1). A request with neither throws —
 * the caller answers 401 before any JSON-RPC processing. Never a query-string key
 * (issue #86, sweep finding S11).
 */
export function resolveCredential(headers: HeaderSource): ResolvedCredential {
  const authorization = header(headers, "authorization");
  const key = authorization?.toLowerCase().startsWith("bearer ")
    ? authorization.slice("bearer ".length).trim()
    : header(headers, "victual-api-key")?.trim();

  if (!key) {
    throw new UnauthenticatedError("no bearer token or VICTUAL-API-KEY header");
  }

  // VICTUAL-API-KEY-TYPE asks Victual to accept this key only if it is an MCP-type key
  // (issue #208, spec §4.2): MCP access is then granted and revoked on its own, and a
  // regular key handed to an assistant does not work through here. A Victual without
  // #208 ignores the header, so this is safe to send to either.
  return { headers: { "VICTUAL-API-KEY": key, "VICTUAL-API-KEY-TYPE": "mcp" } };
}
