# Security policy

This is a hard fork of [grocy](https://github.com/grocy/grocy) with a different threat
model from upstream's, so it has a different policy.

Upstream's position is that grocy "is not an enterprise application and neither one you
(should) host publicly", and that unless something can be abused *unauthenticated* it is
"practically irrelevant" and not worth reporting. That is a reasonable call for upstream's
stated use case. It is not the call here.

## Threat model

This fork runs behind a tailnet, reachable only from trusted endpoints. That is the
perimeter, and it is a good one. Two things sit inside it anyway:

**The home network.** A tailnet endpoint is a device on a LAN that also holds IoT
hardware, guest devices, and whatever a family member installed last week. "Behind the
perimeter" is not "on a trusted host". Anything that requires only network reachability to
exploit is in scope.

**The household is multi-user, and the users are not peers.** This is a household ERP with
chore tracking, which means real accounts for family members — including children. Victual
has a user system and thirty permission constants precisely because those accounts are
meant to have different capabilities. That makes the *authenticated* trust boundary a real
one, not a formality: a low-privilege account escalating to admin, reading another user's
data, or reaching configuration is a genuine finding here.

This is exactly the class upstream's policy waves away, and this fork has already found
two live examples of it in a single review pass:

- `/api/system/config` returned `DB_PASSWORD`, `DB_USER`, `DB_HOST` and `LDAP_BIND_PW` to
  **any authenticated API key**.
- Any authenticated user could delete **any other user's API key** by id, with no
  ownership check and sequential ids.

Both were fixed (`36650cd`), and neither would have been reportable under upstream's
policy.

## What is in scope

Anything that crosses a boundary the application is supposed to enforce. Concretely, and
not exhaustively:

- Privilege escalation between users, or across the permission constants
- One user reading, modifying or deleting another user's data
- Authentication or session handling flaws — fixation, forgery, tokens that are guessable
  or that outlive what they should
- Injection of any kind reaching the database, the filesystem, the label-printer webhook,
  or an external barcode-lookup service
- Anything that leaks configuration, credentials, or another user's data into a response,
  a log, or a URL
- Anything exploitable purely by network reachability, authenticated or not
- Dependency vulnerabilities that are actually reachable from this codebase

**Authenticated issues are in scope.** So are issues that require an account a child would
have. If you are unsure whether something qualifies, report it — sorting that out is the
maintainer's job, not yours.

## What is not

- **Demo and dev modes disable authentication by design.** `MODE` set to `dev`, `demo` or
  `prerelease` intentionally bypasses login and defines a default user, as does the
  documented `DISABLE_AUTH` setting and embedded-install mode. Running one of those and
  observing that there is no authentication is the feature working.
- **The default `admin` / `admin` credentials**, which the installation instructions tell
  you to change immediately.
- Findings that require an attacker who already has the database, the config file, or
  shell on the host.

## Already known

These are real weaknesses, already found, already tracked. Reporting them again is not
useful, but corrections to the analysis or a working exploit that shows one is worse than
believed very much are:

| Weakness | Tracked in |
|---|---|
| API keys stored and compared in plaintext; accepted via query parameter, so they land in access logs | [plan 11](../docs/plans/11-api-error-handling.md) |
| Session cookie set with no `HttpOnly`, `SameSite` or `Secure`, and no expiry | [plan 15](../docs/plans/landed/15-deliberate-cleanup.md) |
| No error logging at all in production, so an attack leaves no trace | [plan 11](../docs/plans/11-api-error-handling.md) |
| LDAP backend is unmaintained here and pending removal; its filter-injection fix was verified by inspection only | [plan 15](../docs/plans/landed/15-deliberate-cleanup.md) |
| Generic CRUD allows mass assignment of `id` and timestamp columns | [plan 11](../docs/plans/11-api-error-handling.md) |

The full picture is in [docs/architecture-review.md](../docs/architecture-review.md).

## How to report

Use GitHub's **private vulnerability reporting** on this repository — the Security tab,
"Report a vulnerability". That keeps the report private until there is a fix, and it does
not require knowing an email address.

Please include what you would want to receive: what the impact is, the account or position
an attacker needs to start from, and the steps to reproduce. A proof of concept is welcome
and will not be treated as hostile.

**Expectations, honestly.** This fork has one maintainer. There is no bounty, no SLA, and
no security team — there is one person who will read your report and take it seriously. Assume days, not hours, and say so in the report if you think
something warrants faster.

## Issues that also affect upstream

If the same flaw exists in [grocy/grocy](https://github.com/grocy/grocy), please report it
there as well — but note that upstream's policy may decline it if it requires
authentication. That does not change anything here: report it, and it will be fixed in
this fork regardless of what upstream decides.
