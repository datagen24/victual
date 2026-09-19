// §9: logs to stdout/stderr only; no MCP Logging (deprecated), no metrics endpoint.
const LEVELS = ["error", "warn", "info", "debug"] as const;
export type LogLevel = (typeof LEVELS)[number];

export interface Logger {
  error(message: string): void;
  warn(message: string): void;
  info(message: string): void;
  debug(message: string): void;
}

export function createLogger(level: LogLevel): Logger {
  const threshold = LEVELS.indexOf(level);
  const at = (name: LogLevel) => (message: string) => {
    if (LEVELS.indexOf(name) > threshold) return;
    const line = `${new Date().toISOString()} ${name} ${message}`;
    if (name === "error" || name === "warn") console.error(line);
    else console.log(line);
  };
  return { error: at("error"), warn: at("warn"), info: at("info"), debug: at("debug") };
}
