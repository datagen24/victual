import type { ResolvedCredential } from "../auth/resolver.js";
import { VictualApiError, type VictualClient } from "./client.js";

/** GET /api/user/capabilities (spec §4.2 item 5): what the acting credential may do. */
export interface Capabilities {
  key_type: string;
  read_only: boolean;
  permissions: string[];
}

/**
 * One probe per tools/list request, nothing cached across requests (§5).
 *
 * Returns `null` when this Victual does not serve the endpoint yet (404): issue #208 is
 * what adds it, and until it lands the list is served unfiltered. That is a UX
 * degradation, not a security one — the filter only hides tools, and every tools/call
 * is still permission-checked by Victual on the forwarded request, surfacing as an
 * honest `forbidden` (§7). Every other failure propagates.
 */
export async function probeCapabilities(
  client: VictualClient,
  credential: ResolvedCredential,
): Promise<Capabilities | null> {
  try {
    return await client.get<Capabilities>("/api/user/capabilities", credential);
  } catch (error) {
    if (error instanceof VictualApiError && error.category === "not_found") return null;
    throw error;
  }
}
