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
  | "outcome_unknown"
  | "victual_error";

export class VictualApiError extends Error {
  /**
   * The `request_id` a keyed booking was sent with (ADR-0046), set by the booking helper so
   * the tool error can say whether a retry is safe. Absent on every other call.
   */
  requestId?: string;

  constructor(
    public readonly category: ToolErrorCategory,
    message: string,
    public readonly victualStatus?: number,
    /** Victual's machine-readable `code`, when its error body carries one (`idempotency_conflict`). */
    public readonly code?: string,
  ) {
    super(message);
  }
}

/** How much of Victual's `error_message` a tool error repeats; enough for any message it writes. */
const MAX_ERROR_MESSAGE = 300;

/**
 * Victual's `{error_message, code}` from a refused request's body, for the tool error's text.
 * The category still comes from the status code alone (§7); this only stops a model being
 * told "400" when Victual said which field was wrong or which name is taken.
 */
function errorDetail(text: string): { message?: string; code?: string } {
  try {
    const body = JSON.parse(text) as { error_message?: unknown; code?: unknown };
    return {
      ...(typeof body.error_message === "string" && body.error_message !== ""
        ? { message: body.error_message.slice(0, MAX_ERROR_MESSAGE) }
        : {}),
      ...(typeof body.code === "string" ? { code: body.code } : {}),
    };
  } catch {
    return {};
  }
}

/** A 2xx answer: the parsed body, its status, and the headers a booking reads (`Idempotent-Replayed`). */
export interface VictualResponse<T> {
  status: number;
  body: T;
  headers: Headers;
}

function categorize(status: number, method: string): ToolErrorCategory {
  if (status === 401) return "unauthorized";
  if (status === 403) return "forbidden";
  if (status === 404) return "not_found";
  if (status >= 400 && status < 500) return "invalid_request";
  // 503 is what SchemaVersionMiddleware answers before any handler runs (rolling update,
  // schema mismatch): nothing was applied, so retrying is safe for every method.
  if (status === 503) return "victual_unavailable";
  // A gateway in front of Victual answering 502/504 says nothing about whether the
  // upstream finished the request, so a write may have been applied.
  if ((status === 502 || status === 504) && method !== "GET") return "outcome_unknown";
  if (status === 502 || status === 504) return "victual_unavailable";
  return "victual_error";
}

/**
 * Error codes fetch reports when the TCP connection was never established, so the request
 * cannot have reached Victual. Anything else that fails a write (abort or timeout, reset,
 * a socket closed mid-flight) may have happened after Victual started processing it.
 */
const NEVER_SENT_CODES = new Set([
  "ECONNREFUSED",
  "ENOTFOUND",
  "EAI_AGAIN",
  "ENETUNREACH",
  "EHOSTUNREACH",
  "UND_ERR_CONNECT_TIMEOUT",
]);

function neverSent(error: unknown, depth = 0): boolean {
  if (typeof error !== "object" || error === null || depth > 4) return false;
  const { code, cause, errors } = error as { code?: unknown; cause?: unknown; errors?: unknown };
  if (typeof code === "string" && NEVER_SENT_CODES.has(code)) return true;
  // Node tries each resolved address and reports the failures as an AggregateError.
  if (Array.isArray(errors) && errors.length > 0 && errors.every((inner) => neverSent(inner, depth + 1))) return true;
  return neverSent(cause, depth + 1);
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
    return (await this.send<T>(url, path, { method: "GET", headers: { ...credential.headers, accept: "application/json" } })).body;
  }

  /**
   * POST a JSON body. Victual answers some write routes with 204 and no body
   * (`shoppinglist/add-product`), so an empty response resolves to `undefined` rather than
   * a JSON parse error.
   */
  async post<T = undefined>(path: string, credential: ResolvedCredential, body: Record<string, unknown>): Promise<T> {
    return (await this.postWithStatus<T>(path, credential, body)).body;
  }

  /**
   * POST a JSON body and keep the 2xx status beside the parsed answer, for a caller that has
   * to report an answer it cannot use without losing the status Victual gave it (#937).
   */
  async postWithStatus<T = undefined>(
    path: string,
    credential: ResolvedCredential,
    body: Record<string, unknown>,
    extraHeaders: Record<string, string> = {},
  ): Promise<VictualResponse<T>> {
    return this.sendJson<T>("POST", path, credential, body, extraHeaders);
  }

  /** PUT a JSON body. The generic object route answers 204, so this resolves to `undefined`. */
  async put<T = undefined>(path: string, credential: ResolvedCredential, body: Record<string, unknown>): Promise<T> {
    return (await this.sendJson<T>("PUT", path, credential, body, {})).body;
  }

  /** Send a JSON body with the credential's headers and any extra ones the call needs. */
  private sendJson<T>(
    method: "POST" | "PUT",
    path: string,
    credential: ResolvedCredential,
    body: Record<string, unknown>,
    extraHeaders: Record<string, string>,
  ): Promise<VictualResponse<T>> {
    const url = new URL(path, this.config.VICTUAL_BASE_URL);
    return this.send<T>(url, path, {
      method,
      // The credential last: no extra header can replace the key this request forwards.
      headers: { ...extraHeaders, accept: "application/json", "content-type": "application/json", ...credential.headers },
      body: JSON.stringify(body),
    });
  }

  private async send<T>(url: URL, path: string, init: RequestInit): Promise<VictualResponse<T>> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.config.MCP_REQUEST_TIMEOUT_MS);

    const write = (init.method ?? "GET") !== "GET";
    try {
      let response: Response;
      try {
        response = await fetch(url, { ...init, signal: controller.signal });
      } catch (cause) {
        const category = write && !neverSent(cause) ? "outcome_unknown" : "victual_unavailable";
        throw new VictualApiError(category, `could not reach Victual: ${String(cause)}`);
      }

      if (!response.ok) {
        // A body that cannot be read changes nothing: the status alone decides the category.
        const detail = errorDetail(await response.text().catch(() => ""));
        throw new VictualApiError(
          categorize(response.status, init.method ?? "GET"),
          `Victual answered ${response.status} for ${path}${detail.message ? `: ${detail.message}` : ""}`,
          response.status,
          detail.code,
        );
      }

      // The timer keeps running through the body: a server that sends headers and then stalls,
      // or cuts the body short, must not hang the call or surface as a raw TypeError. The
      // headers said 2xx, so a write may well have been applied.
      try {
        const text = await response.text();
        return { status: response.status, body: (text === "" ? undefined : JSON.parse(text)) as T, headers: response.headers };
      } catch (cause) {
        throw new VictualApiError(
          write ? "outcome_unknown" : "victual_unavailable",
          `could not read Victual's ${response.status} answer for ${path}: ${String(cause)}`,
          response.status,
        );
      }
    } finally {
      clearTimeout(timeout);
    }
  }
}
