# ADR-0007: Authentication rate-limit state lives outside the process

- **Status:** **Accepted** 2026-08-29.
- **Decider:** datagen24 (maintainer), retrospectively — see [the lifecycle](README.md#lifecycle).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [security sweep](../security-sweep.md) S12,
  [11](../plans/11-api-error-handling.md), [10](../plans/landed/10-cold-start-statelessness.md).

## Context

At the time of the security sweep, `DefaultAuthMiddleware::ProcessLogin` verified every
password attempt without a counter or delay. The application also seeded the default
`admin`/`admin` account. S12 called for login throttling and a required password change.

The deployment scales application pods to zero. A throttle held in process memory or
APCu would disappear at shutdown, allowing repeated attempts after each idle period.
Its state must survive application restarts.

## Decision

Store login throttle state in always-on Redis or a database table. Do not store it in
process memory or APCu.

Apply the same persistence requirement to other state needed between requests, as
specified in [plan 10](../plans/landed/10-cold-start-statelessness.md). Pure caches may remain
in memory when losing them only requires recomputation.

## Consequences

- Throttling requires either Redis or a schema change. S12 remained open until that
  dependency and the throttle were implemented.
- Cache loss is acceptable only when it does not weaken a security or correctness
  guarantee. A cached database query can be rerun; a lost attempt counter cannot be
  reconstructed from the next request.
- Persistent security state also needs appropriate write permissions. Users must not be
  able to clear a restriction through a general settings endpoint.

## Implementation

Implemented 2026-09-04 in `login_attempts` (migration 0262, a per-engine pair), through
`LoginThrottleService` and `middleware/Auth/PasswordLogin.php`. A table avoids another
service dependency and requires one write per failed attempt at household scale.

A per-address counter was removed during review. Behind a reverse proxy, `REMOTE_ADDR`
identified the proxy, so ten failed attempts could lock out the whole instance. The
application keeps the per-username counter; address-based limiting belongs at the proxy.

The required default-password change is persisted in `users.must_change_password`
(migration 0265). It was initially a user setting, but review of PR #68 found that deleting
that setting bypassed the restriction. A dedicated column prevents that bypass. The login
path records the result while the plaintext password is available, avoiding an Argon2id
verification on every request.
