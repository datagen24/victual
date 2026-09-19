import type { ResolvedCredential } from "../auth/resolver.js";
import type { Config } from "../config.js";

/**
 * The §7 error model's tool-error categories. Mapped from Victual's HTTP status codes,
 * never parsed from response prose — the mapping is only as good as the codes, which is
 * why plan 11 gates this work.
 */
export type ToolErrorCategory =
  | "unauthorized"
  | "forbidden"
  | "not_found"
  | "invalid_request"
  | "victual_unavailable"
  | "victual_error";

export class VictualApiError extends Error {
  constructor(
    public readonly category: ToolErrorCategory,
    message: string,
    public readonly victualStatus?: number,
  ) {
    super(message);
  }
}

function categorize(status: number): ToolErrorCategory {
  if (status === 401) return "unauthorized";
  if (status === 403) return "forbidden";
  if (status === 404) return "not_found";
  if (status >= 400 && status < 500) return "invalid_request";
  return "victual_error";
}

/**
 * REST client for the sidecar's one outbound dependency: Victual's own API. No state is
 * kept between calls (§2) — a fresh credential is resolved and forwarded on every
 * request.
 */
export class VictualClient {
  constructor(private readonly config: Config) {}

  async get<T>(
    path: string,
    credential: ResolvedCredential,
    searchParams?: URLSearchParams,
  ): Promise<T> {
    const url = new URL(path, this.config.VICTUAL_BASE_URL);
    if (searchParams) url.search = searchParams.toString();

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.config.MCP_REQUEST_TIMEOUT_MS);

    let response: Response;
    try {
      response = await fetch(url, {
        headers: { ...credential.headers, accept: "application/json" },
        signal: controller.signal,
      });
    } catch (cause) {
      throw new VictualApiError("victual_unavailable", `could not reach Victual: ${String(cause)}`);
    } finally {
      clearTimeout(timeout);
    }

    if (!response.ok) {
      throw new VictualApiError(
        categorize(response.status),
        `Victual answered ${response.status} for ${path}`,
        response.status,
      );
    }

    return (await response.json()) as T;
  }
}
