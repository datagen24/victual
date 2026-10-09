// ADR-0015 prerequisite 2 and docs/mcp-interface-spec.md section 10.1: the tool set is fixed in
// code, no tool reaches the private consumption or refill routes (ADR-0040, ADR-0041, ADR-0042),
// and no tool description makes a clinical claim.
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { test } from "node:test";
import { fileURLToPath } from "node:url";

const toolsDir = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "src", "tools");
const sources = readdirSync(toolsDir)
  .filter((name) => name.endsWith(".ts"))
  .map((name) => ({ name, text: readFileSync(join(toolsDir, name), "utf8") }));

test("no tool source names a consumption or refill route", () => {
  for (const { name, text } of sources) {
    assert.doesNotMatch(text, /\/api\/(consumption|refills)\b/, `${name} reaches a private medication route`);
  }
});

test("no tool description uses clinical or dosing language", () => {
  const clinical = /\b(dose|dosage|dosing|prescri|medicat|interaction|contraindicat|adverse|overdose|take your)/i;
  let checked = 0;
  for (const { name, text } of sources) {
    for (const match of text.matchAll(/description:\s*\n?\s*"((?:[^"\\]|\\.)*)"/g)) {
      assert.doesNotMatch(match[1], clinical, `${name} description: ${match[1]}`);
      checked += 1;
    }
  }
  assert.ok(checked >= 9, `expected to read at least 9 tool descriptions, read ${checked}`);
});
