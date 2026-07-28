# Project Memory - wp-audit-trail

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases, design,
  testing, api-contracts, launch-checklist, memory). No code yet; docs await owner review before
  Phase 1 starts.
- 2026-07-28 - Phase 1 implemented: plugin skeleton (GPL-2.0-or-later header, constants, multisite
  activation block, readme.txt, LICENSE), PHPCS/PHPUnit tooling, `WPAT_Migrations` step 1 creating
  `wpat_events`, `WPAT_Chain` (canonical serializer, entry hashing, chain key resolution, sealed
  head), `WPAT_Recorder` (buffer, severity-gated sync flush, `GET_LOCK` writer with transaction,
  retry-once-then-spill), auth listeners (`wp_login`, `wp_login_failed`, `wp_logout`),
  `WPAT_Verifier` (chunked walk, link/seal/count classification), `WPAT_Cli` (`wp audit verify`,
  `--format=table|json`, exit codes 0/1/2), and the one-time activation notice showing a generated
  `WPAT_CHAIN_KEY`. 40 PHPUnit tests, PHPCS clean.

## Project status

- Phase 1 complete and verified. Phase 2 (full event coverage, redaction, buffering polish) has not
  started and needs owner approval first.

## Phase 1 verification (2026-07-28)

Run against a real WordPress 6.8.2 install (PHP built-in server via `wp server`) on MariaDB 11.4,
plus the PHPUnit suite against the wordpress-develop test library. What was actually observed:

- `composer run lint` clean (15 files); `composer run test` green, 40 tests, 131 assertions.
- Activation on single site: no notices, `wp_wpat_events` created, `wpat_db_version` = 1.
- Real HTTP login, failed login, and logout produced exactly three rows with the catalog's event
  types, severities, actor snapshot, IP, and payloads. The failed login was present immediately,
  written at capture time rather than at shutdown.
- `wp audit verify` exit 0 on an intact chain. Editing a row's `summary` by SQL made it exit 1 with
  `kind: link` naming that id; restoring the value made it pass again; deleting the newest row made
  it exit 1 with `kind: seal`. Defining a fresh `WPAT_CHAIN_KEY` after the fact reproduced the
  documented rotated-key case: `kind: seal` with intact links.
- Two parallel `wp eval-file` processes each writing 60 synchronous events produced 120 rows, zero
  spills, and a chain that verifies with `entry_count` 120. The lock holds.
- With the events table renamed away, a failed login over HTTP rendered the normal login page with
  no fatal and no database error visible to the visitor, wrote one `wpat.flush.spilled` line with
  the full event JSON to the error log, and incremented `wpat_dropped_events` to 1.
- Activation on a multisite install was blocked with the documented message.
- The one-time chain key notice rendered once with the generated value escaped, and was gone on
  reload.

Not verified in this pass: nothing in the Phase 1 checklist. wp-env itself was not used; the
equivalent checks ran against a hand-provisioned WordPress install, which is the documented local
pattern in this workspace (`WP_TESTS_DIR` plus a MariaDB container, no `bin/install-wp-tests.sh`).

## Decisions log

- 2026-07-28 - The sealed head is read straight from the options table inside the writer instead of
  through `get_option()`. Found by the parallel-insert checklist item: options are cached per PHP
  process, so two concurrent writers each kept sealing an `entry_count` derived from whichever
  snapshot they loaded first. The links stayed sound and the head still pointed at the real tail,
  but the lifetime count drifted below the row count (118 sealed for 120 rows) and verification
  failed with `kind: count`. The head is read once per flush and once per verification, so one
  uncached query is a cheap price for a correct count. Covered by a regression test that poisons
  the object cache and asserts the database value wins. Committed as
  `fix: read the sealed head past the options cache`.
- 2026-07-28 - Two `fix:` commits beyond the ten listed for Phase 1: the cache bug above, and
  `fix: omit the head line when the chain is empty` (verify printed `Head: 000...0 (sealed  UTC)`
  on an empty log). Both came out of running the phase checklist rather than from new scope; each
  is one discrete change with its own test.
- 2026-07-28 - `WPAT_Chain::canonical_json()` casts every field explicitly (ints for `actor_id` and
  `severity`, strings elsewhere, null for an empty payload). The same event is serialized twice in
  its life: once from PHP values at insert and once from a `$wpdb` row where every column comes
  back as a string. Without the casts those two serializations differ and an untouched chain fails
  verification. This is part of the frozen canonical form, not an implementation detail.
- 2026-07-28 - The spill path logs the whole event, payload included, which is the one place the
  "never log payload bodies" rule is deliberately not applied. At that point the spilled line is
  the only surviving copy of the record, and payloads have already passed the redactor before
  reaching the recorder, so nothing secret is added to the log by spilling one.
- 2026-07-28 - `WPAT_Recorder` is around 300 lines, past the 150-line guidance. It is the single
  writer, and the lock, transaction, retry, seal, and spill paths are what make the chain
  unforkable; splitting them across classes would create exactly the bypass route `docs/rules.md`
  forbids. Kept as one class deliberately.
- 2026-07-28 - `WPAT_Cli` takes injectable output and error writers rather than calling `WP_CLI`
  statics directly, so the handlers and their exit codes are testable without a WP-CLI harness, as
  `docs/architecture.md` requires. WP-CLI registration is a thin closure that turns the returned
  exit code into `WP_CLI::halt()`.
- 2026-07-28 - Recorder, verifier, listener, and CLI tests truncate the events table in `set_up()`
  instead of relying on the test suite's transaction rollback. The recorder issues its own
  `COMMIT`, which ends the suite's outer transaction, so those tests have to clean up after
  themselves.
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
