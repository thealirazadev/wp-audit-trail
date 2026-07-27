# Project Memory - wp-audit-trail

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases, design,
  testing, api-contracts, launch-checklist, memory). No code yet; docs await owner review before
  Phase 1 starts.

## Project status

- Planning stage. Implementation follows `docs/phases.md` (five phases); Phase 1 is the chain
  core, auth listeners, and `wp audit verify`.

## Decisions log

- 2026-07-27 - The sha256 hash chain alone is not tamper-evident against a database-level
  attacker, who can edit a row and recompute every later hash. Added a sealed head: an HMAC over
  (last_id, last_hash, lifetime entry_count) keyed by `WPAT_CHAIN_KEY` from `wp-config.php`
  (fallback `wp_salt('auth')`). The key lives in the filesystem, so DB-only tampering cannot
  re-sign. Documented honestly what the seal does not cover: filesystem compromise, and rollback
  of table plus head to a consistent old backup; the digest email publishing the head hash is
  the external anchor for the latter.
- 2026-07-27 - Anchor records for chain-preserving pruning are ordinary chained event rows
  (`audit.anchor`) rather than a separate table. One chain, one verifier, one writer path; the
  anchor's payload carries the pruned range boundary hash and cumulative counts, and
  verification simply starts from the newest anchor. A second table would need its own
  integrity story for no benefit.
- 2026-07-27 - The file scanner hashes every file every run; no mtime/size short-circuit. An
  attacker can `touch` a modified file back to its old mtime, so a short-circuiting scanner is
  a placebo exactly in the case that matters. Shared-hosting cost is handled by time-budgeted
  resumable chunks instead, and `file.deleted` is only emitted after a fully completed walk so
  interruptions can delay detection but never fabricate deletions.
- 2026-07-27 - Post content is never stored in payloads; content changes record changed field
  names plus before/after sha256 of the content. Full bodies would bloat the table, duplicate
  data WordPress already keeps in revisions, and drag private draft content into a log that is
  exported and emailed around.
- 2026-07-27 - Severity threshold decides write timing: warning and critical flush synchronously
  at capture (a failed login must survive a request that fatals right after), info and notice
  buffer to one `shutdown` flush (content edits are not worth a mid-request write). The
  threshold is fixed, not a setting, per the YAGNI rule; made configurable only if a real need
  appears (backlogged).
