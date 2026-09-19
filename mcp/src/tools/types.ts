import type { z } from "zod";
import type { ResolvedCredential } from "../auth/resolver.js";
import type { VictualClient } from "../victual/client.js";

export interface ToolContext {
  client: VictualClient;
  credential: ResolvedCredential;
}

export interface ToolDefinition<Input extends z.ZodTypeAny, Output extends z.ZodTypeAny> {
  name: string;
  /** A controllers/Users/User.php permission constant — the §5 tools/list capability filter. */
  permission: string;
  inputSchema: Input;
  outputSchema: Output;
  handler: (input: z.infer<Input>, ctx: ToolContext) => Promise<z.infer<Output>>;
}
