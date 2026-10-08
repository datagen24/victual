import type { z } from "zod";
import type { ResolvedCredential } from "../auth/resolver.js";
import type { VictualClient } from "../victual/client.js";

export interface ToolContext {
  client: VictualClient;
  credential: ResolvedCredential;
}

/**
 * What a handler returns: the `structuredContent` (validated against `outputSchema` by
 * the SDK) and the one-sentence `text` block summarizing it — counts, not rows (§5).
 */
export interface ToolResult<Output> {
  data: Output;
  text: string;
}

export interface ToolDefinition<Input extends z.ZodTypeAny, Output extends z.ZodTypeAny> {
  name: string;
  title: string;
  description: string;
  /**
   * The controllers/Users/User.php permission the equivalent REST route checks — the §5
   * tools/list capability filter. Plan 19's `*_VIEW` leaves, not the spec's original
   * `STOCK`/`SHOPPINGLIST`/`RECIPES` parents: those were written before plan 19 split
   * reads from writes, and the rule the spec states ("exactly as the equivalent REST
   * route does") is the one that holds.
   */
  permission: string;
  /**
   * Further permissions the tool's REST calls check. `consume` and `add` answer with the
   * booked `stock_log` rows through `StockTransactions`, which needs STOCK_VIEW: a key
   * without it would book the change and then see a 403, and a model told "forbidden"
   * retries the booking.
   */
  alsoRequires?: readonly string[];
  /** Write tools are listed only to a key that is not `read_only` (§6) and carry no readOnlyHint. */
  write?: boolean;
  /** Hint to clients that the call changes or removes existing data (consumption does). */
  destructive?: boolean;
  inputSchema: Input;
  outputSchema: Output;
  handler: (input: z.infer<Input>, ctx: ToolContext) => Promise<ToolResult<z.infer<Output>>>;
}
