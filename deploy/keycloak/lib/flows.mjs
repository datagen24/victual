// Authentication flows: read a flow as a tree, compare it with the declared tree, and rebuild it
// through the admin REST API without leaving any binding pointing at a missing or half-built flow.
//
// Keycloak's admin API can add executions and change requirements, but it cannot reorder a subflow
// into another or change an execution's provider in place. The reconciler therefore treats a flow as
// a value: when the tree differs, it builds a complete copy under "<alias>--next", moves every
// binding to the copy, deletes the old flow, builds the flow again under its own alias, moves the
// bindings back and deletes the copy. At every point each binding names a complete flow.

export const NEXT = "--next";
/** Realm fields that name a flow by alias. */
export const REALM_BINDINGS = ["browserFlow", "registrationFlow", "directGrantFlow", "resetCredentialsFlow", "clientAuthenticationFlow", "dockerAuthenticationFlow", "firstBrokerLoginFlow"];

/** Normalises a declared execution list into the comparable shape (strings for config values). */
export function normalizeDeclared(executions = []) {
  return executions.map((e) => {
    const n = e.flow ? { flow: e.flow, requirement: e.requirement, executions: normalizeDeclared(e.executions) }
      : { authenticator: e.authenticator, requirement: e.requirement };
    if (e.config) n.config = Object.fromEntries(Object.entries(e.config).map(([k, v]) => [k, String(v)]).sort());
    return n;
  });
}

/** Reads flow `alias` as a tree of executions, with each execution's config. */
export async function readFlow(kc, alias) {
  const flat = await kc.call("GET", `/authentication/flows/${encodeURIComponent(alias)}/executions`);
  const root = { executions: [] };
  const stack = [root];
  for (const e of flat) {
    const node = e.authenticationFlow
      ? { flow: e.displayName, requirement: e.requirement, executions: [] }
      : { authenticator: e.providerId, requirement: e.requirement };
    if (e.authenticationConfig) {
      const c = await kc.call("GET", `/authentication/config/${e.authenticationConfig}`);
      node.config = Object.fromEntries(Object.entries(c.config ?? {}).map(([k, v]) => [k, String(v)]).sort());
    }
    stack.length = e.level + 1;
    stack[e.level].executions.push(node);
    if (node.flow) stack[e.level + 1] = node;
  }
  return root.executions;
}

/** Returns a copy of a declared tree with every subflow alias suffixed, for the temporary build. */
function suffixed(executions, suffix) {
  return executions.map((e) => (e.flow ? { ...e, flow: e.flow + suffix, executions: suffixed(e.executions ?? [], suffix) } : e));
}

/** Adds `executions` to the flow `parent`, in order, with requirements and configs. */
async function addExecutions(kc, parent, executions, configPrefix) {
  for (const [i, e] of executions.entries()) {
    const p = encodeURIComponent(parent);
    if (e.flow) await kc.call("POST", `/authentication/flows/${p}/executions/flow`, { alias: e.flow, type: "basic-flow", provider: "registration-page-form", description: "" });
    else await kc.call("POST", `/authentication/flows/${p}/executions/execution`, { provider: e.authenticator });
    const mine = (await kc.call("GET", `/authentication/flows/${p}/executions`)).filter((x) => x.level === 0);
    const added = mine[mine.length - 1];
    await kc.call("PUT", `/authentication/flows/${p}/executions`, { ...added, requirement: e.requirement });
    if (e.config) await kc.call("POST", `/authentication/executions/${added.id}/config`, { alias: `${configPrefix}-${i}`, config: e.config });
    if (e.flow) await addExecutions(kc, e.flow, e.executions ?? [], `${configPrefix}-${i}`);
  }
}

/** Creates top-level flow `alias` with the declared executions. */
export async function buildFlow(kc, alias, decl, suffix = "") {
  await kc.call("POST", "/authentication/flows", { alias, description: decl.description ?? "", providerId: "basic-flow", topLevel: true, builtIn: false });
  await addExecutions(kc, alias, suffixed(decl.executions ?? [], suffix), alias);
}

/** Moves every realm, identity-provider and client binding from flow `from` to flow `to`. */
export async function repoint(kc, from, to) {
  const realm = await kc.call("GET", "");
  const patch = Object.fromEntries(REALM_BINDINGS.filter((k) => realm[k] === from).map((k) => [k, to]));
  if (Object.keys(patch).length) await kc.call("PUT", "", patch);
  for (const idp of await kc.call("GET", "/identity-provider/instances")) {
    const up = {};
    if (idp.firstBrokerLoginFlowAlias === from) up.firstBrokerLoginFlowAlias = to;
    if (idp.postBrokerLoginFlowAlias === from) up.postBrokerLoginFlowAlias = to;
    if (Object.keys(up).length) await kc.call("PUT", `/identity-provider/instances/${encodeURIComponent(idp.alias)}`, { ...idp, ...up });
  }
  const flows = await kc.call("GET", "/authentication/flows");
  const fromId = flows.find((f) => f.alias === from)?.id, toId = flows.find((f) => f.alias === to)?.id;
  if (fromId && toId) {
    for (const c of await kc.call("GET", "/clients?max=10000")) {
      const o = c.authenticationFlowBindingOverrides ?? {};
      const keys = Object.keys(o).filter((k) => o[k] === fromId);
      if (keys.length) await kc.call("PUT", `/clients/${c.id}`, { ...c, authenticationFlowBindingOverrides: { ...o, ...Object.fromEntries(keys.map((k) => [k, toId])) } });
    }
  }
}

/** Deletes top-level flow `alias` (Keycloak deletes its subflows with it). */
export async function deleteFlow(kc, alias) {
  const f = (await kc.call("GET", "/authentication/flows")).find((x) => x.alias === alias);
  if (f) await kc.call("DELETE", `/authentication/flows/${f.id}`);
}

/** Replaces an existing flow with the declared tree, keeping every binding on a complete flow. */
export async function rebuildFlow(kc, alias, decl) {
  const tmp = alias + NEXT;
  await deleteFlow(kc, tmp); // left over from an interrupted earlier run
  await buildFlow(kc, tmp, decl, NEXT);
  await repoint(kc, alias, tmp);
  await deleteFlow(kc, alias);
  await buildFlow(kc, alias, decl);
  await repoint(kc, tmp, alias);
  await deleteFlow(kc, tmp);
}
