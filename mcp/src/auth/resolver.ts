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

/**
 * `Authorization: Bearer <key>` is canonical; the raw `VICTUAL-API-KEY: <key>` header is
 * also accepted for parity with Victual itself (§4.1). A request with neither throws —
 * the caller answers 401 before any JSON-RPC processing.
 */
export function resolveCredential(headers: Headers): ResolvedCredential {
  const authorization = headers.get("authorization");
  const key = authorization?.toLowerCase().startsWith("bearer ")
    ? authorization.slice("bearer ".length).trim()
    : headers.get("victual-api-key");

  if (!key) {
    throw new UnauthenticatedError("no bearer token or VICTUAL-API-KEY header");
  }

  return { headers: { "VICTUAL-API-KEY": key } };
}
