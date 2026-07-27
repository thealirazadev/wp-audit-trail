# wp-audit-trail

Tamper-evident audit logging for WordPress. Every security-relevant event on the site (logins,
failed logins, user and role changes, content changes, plugin/theme/core changes, whitelisted
option edits, and file modifications under wp-content) is recorded into a custom table where each
entry is linked into a hash chain: `entry_hash = sha256(prev_entry_hash + canonical_serialized_entry)`.
Editing or deleting any past row breaks verification, and a sealed chain head signed with a key
kept outside the database makes silent truncation detectable too. The result is an audit log you
can actually trust after an incident, not just a list of rows anyone with database access could
have rewritten.

## The problem

When a WordPress site is compromised, the first question is "who did what, when". Most activity
log plugins answer it with plain database rows, and the attacker who owns the database owns the
log: rows get edited or deleted and the record looks clean. wp-audit-trail is built around the
assumption that the log itself is a target. Verification either passes, or it tells you exactly
where history was altered.

## Planned features

All features below are planned; implementation has not started and follows `docs/phases.md`.

- Hash-chained, append-only event log with a sealed head (HMAC keyed via `WPAT_CHAIN_KEY`).
- Coverage: auth, users and roles, posts/pages, plugins/themes/core, whitelisted options, and a
  resumable checksum scan of wp-content for file changes.
- Non-blocking writes: buffered flush on shutdown, synchronous for warning/critical events.
- Redaction guarantees: passwords never stored, secret-like option values masked by pattern.
- Admin screens: filterable log browser with payload diffs, one-click chunked chain
  verification with a plain-language pass/fail report, settings, streaming CSV export.
- Chain-preserving retention: pruning writes anchor records so old rows can be deleted while
  the remaining chain still verifies end to end.
- WP-CLI: `wp audit verify`, `wp audit export`, `wp audit prune`.
- Optional daily digest email of high-severity events that also publishes the current chain
  head hash as an off-site anchor.

## Stack

PHP 8.1+, WordPress 6.5+, custom tables via dbDelta migrations, MySQL/MariaDB named locks for
single-writer chain inserts, PHPUnit with the WordPress test suite, WP-CLI. No Node toolchain,
no runtime dependencies, no external requests. License: GPL-2.0-or-later (declared in the plugin
header and `readme.txt`).

## Documentation

| Document | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | Problem, target user, core features, non-goals, success criteria |
| [docs/architecture.md](docs/architecture.md) | Stack rationale, data model, hash chain and seal, key flows, failure modes, layout |
| [docs/rules.md](docs/rules.md) | Project-specific engineering rules extending the workspace rules |
| [docs/phases.md](docs/phases.md) | Five implementation phases with commit lists and verification checklists |
| [docs/design.md](docs/design.md) | Admin screen and WP-CLI UX design |
| [docs/testing.md](docs/testing.md) | Test strategy, coverage map, commands, CI plan |
| [docs/api-contracts.md](docs/api-contracts.md) | Event catalog, ajax/export contracts, WP-CLI commands, error format |
| [docs/launch-checklist.md](docs/launch-checklist.md) | Pre-release verification checklist |
| [docs/memory.md](docs/memory.md) | Working log and decisions record |

## Status

Planning stage: documentation only, no implementation code yet. Implementation proceeds phase by
phase per [docs/phases.md](docs/phases.md), one approved phase at a time.
