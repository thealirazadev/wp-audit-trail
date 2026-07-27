# Architecture - wp-audit-trail

## App flow

```
WordPress core fires a hook (wp_login, profile_update, activated_plugin, updated_option, ...)
        |
        v
WPAT_Listeners (one thin callback per hook)
        |- normalize hook args into an event array
        |     {event_type, severity, object_type, object_id, object_label, summary, payload}
        |- WPAT_Redactor strips/replaces sensitive values in the payload
        |- collapse an identical consecutive event fired twice in the same request
        v
WPAT_Recorder
        |- severity >= warning  -> flush the whole buffer synchronously now
        |- severity <  warning  -> append to the in-memory buffer
        |- 'shutdown' priority 100 -> flush remaining buffer once
        v
Flush (single writer)
        |- GET_LOCK('wpat_chain_{table_prefix}', 5) -> on timeout: retry once,
        |     then spill events to error_log + increment wpat_dropped_events
        |- START TRANSACTION
        |- read chain tail (last entry_hash by id DESC, or genesis)
        |- per event: entry_hash = sha256(prev_hash . '|' . canonical_json(event)); INSERT
        |- update sealed head option (last_id, last_hash, entry_count, HMAC sig)
        |- COMMIT; RELEASE_LOCK
        v
{prefix}wpat_events  (append-only; rows are never UPDATEd, only pruned via anchors)

Readers (never take the lock):
        - Admin log browser / detail (WP_List_Table)
        - Verify (admin-ajax chunked walk, or wp audit verify)
        - CSV export (streaming, admin-post or wp audit export)
        - Daily digest (WP-Cron, wp_mail)
Writers besides listeners (take the lock):
        - Retention prune (anchor insert + batched DELETE)
        - File scanner emits file.* events through the same recorder
```

## Tech stack with rationale

- **PHP 8.1+, WordPress 6.5+** - Plain WordPress plugin, no framework. 8.1 gives enums-free
  readable typed code (`readonly` properties, first-class callable syntax) while matching common
  shared-hosting floors. 6.5 is the oldest version still receiving security updates at planning
  time.
- **GPL-2.0-or-later** - Declared in the plugin header and `readme.txt`. WordPress plugins are
  derivative of GPL core; MIT is not an option here.
- **Custom tables via dbDelta** - The event log must be append-only, indexable, and prunable in
  bulk; the postmeta/options tables are wrong for all three. Schema is versioned through a
  `wpat_db_version` option and every change ships as a new dbDelta migration step (see
  `docs/rules.md`).
- **MySQL 5.7+/MariaDB 10.4+ with InnoDB** - `GET_LOCK` named locks give a cross-connection
  single-writer section without a lock table; InnoDB transactions make insert-plus-seal atomic.
- **PHPUnit + the WordPress test suite (wordpress-develop lib + MariaDB)** - Same pattern already
  proven in the sibling `wp-ai-writer` repo; listeners are tested by firing real WP hooks.
- **WP-CLI** - `wp audit verify|export|prune` for operators and cron-driven automation. Command
  classes are plain PHP, testable without a WP-CLI harness via a thin output shim.
- **No Node toolchain** - Admin screens use WordPress admin styles; the only JavaScript is one
  hand-written `admin/js/verify.js` (fetch polling for verify progress). No build step, no npm.
- **No runtime HTTP calls** - The plugin never phones home. The digest uses `wp_mail`; the
  scanner reads the local filesystem.

## Data model

Table names are the contract; the coding agent must not rename them. All tables use the site's
`$wpdb->prefix`. All datetimes are UTC `Y-m-d H:i:s`.

### {prefix}wpat_events

Append-only. Rows are never updated. Deletes happen only through chain-preserving pruning.

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK auto_increment | Chain order is ascending id |
| occurred_at | datetime | UTC, second precision; ties ordered by id |
| actor_id | bigint unsigned, default 0 | 0 = anonymous or system (cron, CLI) |
| actor_login | varchar(60), default '' | Snapshot; survives later user deletion |
| actor_ip | varchar(45), default '' | IPv4 or IPv6 text; see IP handling below |
| actor_ua | varchar(255), default '' | Truncated user agent |
| event_type | varchar(64) | Catalog in `docs/api-contracts.md` |
| severity | tinyint unsigned | 1 info, 2 notice, 3 warning, 4 critical |
| object_type | varchar(32), default '' | `user`, `post`, `plugin`, `theme`, `core`, `option`, `file`, `audit` |
| object_id | varchar(64), default '' | Numeric id, slug, option name, or path hash |
| object_label | varchar(255), default '' | Human-readable name shown in the browser |
| summary | varchar(255) | One-line description, pre-rendered at capture time |
| payload | longtext, nullable | JSON: redacted before/after diff or anchor metadata |
| prev_hash | char(64) | entry_hash of the previous row; genesis = 64 zeros |
| entry_hash | char(64) | See hash chain below |

Indexes: PK `id`; `occurred_at`; `event_type`; `actor_id`; `severity`;
composite `(object_type, object_id(20))`.

### {prefix}wpat_file_hashes

Baseline for the file integrity scan. Not part of the chain; a tampered baseline causes false
`file.modified` events, never hidden ones (see failure modes).

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK auto_increment | |
| path_hash | char(64), unique | sha256 of the path relative to WP_CONTENT_DIR |
| path | text | Relative path; too long to index directly, hence path_hash |
| sha256 | char(64) | Content hash from the last completed scan |
| file_size | bigint unsigned | Recorded for the event payload, not for short-circuiting |
| last_seen_run | bigint unsigned | Scan run id; rows not seen by a completed run are deletions |
| updated_at | datetime | |

### Options (wp_options)

| Option | Contents |
|---|---|
| wpat_db_version | Integer schema version consumed by migrations |
| wpat_settings | Array: retention_days (default 90, 0 = keep forever), digest_enabled, digest_recipient (default admin_email), scan_enabled, scan_time_budget (default 20s) |
| wpat_chain_head | Sealed head: `{last_id, last_hash, entry_count, sealed_at, sig}` (autoload off) |
| wpat_scan_state | Resumable scan cursor: `{run_id, phase, resume_after_path_hash, files_seen, started_at}` (autoload off) |
| wpat_dropped_events | Counter of events spilled to error_log; > 0 raises an admin notice |

## Hash chain

### Canonical serialization

`canonical_json(event)` is `wp_json_encode` of an array with exactly this key order:

```
occurred_at, actor_id, actor_login, actor_ip, actor_ua, event_type, severity,
object_type, object_id, object_label, summary, payload
```

with flags `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. `payload` is the already-encoded
JSON string (or null), embedded as a string, so the canonical form never depends on re-encoding.
All values are strings, integers, or null; floats are forbidden anywhere in an event because their
JSON encoding is not byte-stable across PHP versions. `id` is not part of the canonical form (it
is assigned by auto-increment at insert); linkage, not id arithmetic, defines the chain, so id
gaps from crashed transactions are harmless.

```
entry_hash = sha256( prev_hash . '|' . canonical_json(event) )
genesis prev_hash = str_repeat('0', 64)
```

### Sealed head

A plain sha256 chain is recomputable by anyone who can write to the database: edit a row, rehash
everything after it, done. The seal is what makes the chain tamper-evident against a
database-level attacker. After every flush, the head option is rewritten as:

```
sig = hmac_sha256( chain_key, last_id . '|' . last_hash . '|' . entry_count )
```

- `chain_key` is the constant `WPAT_CHAIN_KEY` from `wp-config.php` when defined (activation
  shows a generated value to paste there), else `wp_salt('auth')` as a fallback. The key lives in
  the filesystem, not the database, so a DB-only attacker cannot re-sign a rewritten chain.
- `entry_count` is the lifetime number of chained entries including pruned ones (monotonic,
  carried forward by anchors), so deleting tail rows and re-signing is impossible without the key,
  and even replaying an old option value fails the count check against surviving anchors.
- Verification distinguishes **link failure** (a recomputed entry_hash mismatch: tampering) from
  **seal failure** (sig mismatch with intact links: key changed, e.g. rotated salts, or a forged
  head). Both fail the run; the report says which.

### Concurrency: single writer

All chain writes (event flush, anchor insert during prune) happen inside
`GET_LOCK('wpat_chain_{table_prefix}', 5)`. The lock name includes the table prefix so two sites
sharing one MySQL server never contend. Inside the lock, tail read + inserts + seal update run in
one InnoDB transaction. Invariants:

- **No forks**: prev_hash of every row equals entry_hash of the row before it (by id order),
  because only the lock holder may read the tail and insert.
- **Seal is an ancestor**: the sealed head always refers to a row at or before the current tail.
  A crash after COMMIT of a previous flush but before a later seal write cannot occur (same
  transaction); a seal *behind* the tail can only mean the head option was restored from backup
  independently of the table, and verify reports it as a seal failure.
- **Readers never block**: verification, browsing, and export use plain SELECTs and never take
  the lock; a verify run concurrent with inserts verifies up to the tail it observed.
- **Idempotent capture**: WordPress fires some hooks twice per action (`profile_update`);
  identical consecutive events within one request are collapsed in the buffer before flush.

### Buffered vs synchronous writes

- Severity 1-2 (info, notice): buffered in memory, flushed once on `shutdown` priority 100. A
  normal page view with no events touches the audit table zero times.
- Severity 3-4 (warning, critical): the entire buffer flushes synchronously at capture time,
  preserving in-request ordering. Rationale: a failed login or role change must survive even if
  the request dies before shutdown; content edits are not worth a mid-request write.
- `shutdown` flush also runs for WP-CLI and cron contexts (actor_id 0, actor_ip '').

## Key flows

### Event capture (example: role change)

1. `set_user_role` fires; the listener builds the event array with `event_type: user.role_changed`,
   `severity: 4`, payload `{"roles": {"before": ["author"], "after": ["administrator"]}}`.
2. `WPAT_Redactor` scans the payload; nothing matches the patterns; it passes through.
3. Severity 4 => `WPAT_Recorder::flush()` now: lock, read tail, hash, insert, reseal, commit.
4. The buffered info events captured earlier in the request flush first, in capture order, so the
   chain reflects real ordering within the request.

### Verification

1. Load the sealed head; verify `sig` with the chain key. Record seal status.
2. Determine the starting point: the newest `audit.anchor` row, whose payload carries
   `pruned_through_hash`, or genesis when no anchor exists.
3. Walk rows in id order in chunks of 1,000: assert `prev_hash` equals the running hash, recompute
   `entry_hash` from the stored columns, advance. First mismatch aborts with the failing id,
   expected hash, and actual hash.
4. At the tail: assert the sealed head matches the final id/hash and that
   `entry_count == pruned_count_from_anchors + surviving_rows`.
5. Report: pass, or fail with {kind: link|seal|count, first_bad_id, expected, actual}.
   Admin screen runs steps 3-4 via repeated admin-ajax calls; the cursor and running hash live in
   a server-side transient keyed by run id, never round-tripped through the client.

### Chain-preserving prune

1. Acquire the chain lock (serializes against event flushes).
2. Select the boundary: the last row with `occurred_at < now - retention_days`, capped so anchor
   rows newer than the newest anchor's boundary are never orphaned.
3. Insert an anchor event (`event_type: audit.anchor`, severity 2, object_type `audit`) whose
   payload records `{pruned_through_id, pruned_through_hash, pruned_count, cumulative_count,
   range_first_occurred_at, range_last_occurred_at}`. It is hashed and sealed like any event.
4. Commit the anchor. Then DELETE rows with `id <= pruned_through_id` in batches of 1,000
   (each batch its own statement, still inside the lock, outside long transactions).
5. Release the lock. Crash safety: if the process dies after step 4 starts, remaining old rows
   still verify (verification starts at the newest anchor and treats surviving pre-boundary rows
   as pending deletion, verifying them against the previous anchor or genesis); the next daily
   run deletes the rest. Prune is idempotent.

### File integrity scan

1. A daily WP-Cron event starts run N: `wpat_scan_state` gets `{run_id: N, phase: 'hash',
   resume_after_path_hash: '', started_at}`.
2. Each tick walks `WP_CONTENT_DIR` (uploads and the plugin's own directory excluded by default;
   paths filterable via `wpat_scan_paths`) in stable sorted order, hashing files until the time
   budget (default 20s) is spent, then persists the cursor and reschedules itself +60s.
   Files over 10 MB are recorded by size only, flagged `oversize` in the baseline.
3. Every file is content-hashed every run. There is deliberately no mtime/size short-circuit: an
   attacker can `touch` a modified file back to its old mtime, and a scanner that trusts mtime is
   a placebo. The time budget, not skipping, is the shared-hosting concession.
4. Differences against the baseline emit events through the normal recorder: `file.added`,
   `file.modified` (payload: old/new sha256, sizes), then the baseline row is upserted with
   `last_seen_run = N`.
5. Phase 'sweep': when the walk completes, rows with `last_seen_run < N` are deleted files; emit
   `file.deleted` per row and remove it. Then clear `wpat_scan_state`.
6. A run whose `started_at` is older than 24h is considered orphaned (host killed the cron); the
   next daily event starts fresh. Partial runs never emit `file.deleted` (sweep only runs after a
   complete walk), so an interrupted scan can miss events until the next run but never fabricates
   deletions.

### Daily digest

1. A daily WP-Cron event queries severity >= 3 events from the last 24h.
2. None: do nothing. Some: send one `wp_mail` to the configured recipient with counts by
   event_type, the 10 most recent qualifying rows, the current sealed head hash and entry count,
   and the last verify result. The head hash in the mailbox is the off-site anchor that makes
   backup-rollback attacks detectable.
3. `wp_mail` returning false is logged via `wpat_log()`; no retry queue, next digest covers a
   fresh window.

## Failure modes

| Failure | Handling |
|---|---|
| GET_LOCK timeout (5s) | Retry once; then JSON-encode the events to error_log, increment `wpat_dropped_events`, show a persistent admin notice. Loud loss, never silent. |
| INSERT/COMMIT error (deadlock, server gone) | Same spill path as lock timeout; transaction rolls back so the chain never half-commits. |
| Fatal after a critical event | Already durable: severity >= 3 flushed synchronously at capture. Buffered info events from that request are lost; accepted trade-off, documented. |
| Events table dropped or unwritable | Flush fails -> spill path; verify fails loudly; admin notice on every screen load while the table is missing. |
| Head option restored from old backup without the table | Count/tail mismatch -> seal failure reported by verify. |
| Salts rotated while relying on the fallback key | Seal failure with intact links; report explains the distinction and recommends defining `WPAT_CHAIN_KEY`, then resealing via a fresh verify-confirmed flush. |
| Prune dies mid-DELETE | Idempotent: verification tolerates surviving pre-anchor rows; next run finishes the delete. |
| Scan interrupted / cron starved | Resumes from the stored cursor; orphaned runs (> 24h) restart; `file.deleted` only ever emitted after a complete walk. |
| Baseline table tampered | Worst case is false `file.modified`/`file.added` noise on the next scan, which itself lands in the chained log; tampering cannot suppress a future detection without also modifying files back. |
| `wp_mail` failure | Logged, skipped; digest is best-effort by design. |
| Clock skew / occurred_at going backwards | Harmless: chain order and verification are id-ordered; occurred_at is display metadata. |
| CSV export of huge tables | Streams row batches of 1,000 with output flushing; memory is O(batch), never O(table). |

## IP and privacy handling

- `actor_ip` is `REMOTE_ADDR` only. `X-Forwarded-For` is attacker-controlled and is not trusted;
  sites behind a proxy can supply the real client IP via the `wpat_client_ip` filter.
- `WPAT_Redactor` removes `user_pass` from every user payload unconditionally, and replaces the
  before/after values of any option whose name matches
  `/password|secret|key|token|salt|auth|nonce/i` with `"[redacted]"` while keeping the fact of
  the change. Patterns extendable via the `wpat_redact_patterns` filter.
- Post content is never stored; content changes record the changed field names plus sha256 of the
  before/after content.

## Directory layout

```
wp-audit-trail/
  wp-audit-trail.php               Plugin header (GPL-2.0-or-later), constants (WPAT_VERSION,
                                   WPAT_PATH, WPAT_URL), requires, boots WPAT_Plugin; blocks
                                   activation on multisite with an explanatory notice
  uninstall.php                    Drops both tables and deletes all wpat_* options unless the
                                   keep-data setting is on
  readme.txt                       WordPress.org readme, GPL-2.0-or-later license field
  composer.json                    Dev deps + scripts (lint, lint:fix, test)
  phpcs.xml.dist                   WordPress-Extra + WordPress-Docs, wpat prefix rules
  phpunit.xml.dist                 PHPUnit config for the WP test suite

  includes/
    class-wpat-plugin.php          Orchestrator: instantiates everything, registers hooks/cron
    class-wpat-migrations.php      dbDelta steps keyed by wpat_db_version
    class-wpat-chain.php           canonical_json(), hashing, seal build/verify, chain key
    class-wpat-recorder.php        Buffer, sync/deferred flush, GET_LOCK writer, spill path
    class-wpat-listeners.php       All WP hook subscriptions; thin normalizers only
    class-wpat-redactor.php        Payload redaction rules
    class-wpat-verifier.php        Chunked verification engine (shared by admin and CLI)
    class-wpat-retention.php       Anchor insert + batched prune
    class-wpat-scanner.php         Chunked resumable file scan
    class-wpat-digest.php          Daily digest assembly + wp_mail
    class-wpat-exporter.php        Streaming CSV writer (shared by admin-post and CLI)
    class-wpat-admin.php           Menu, settings (Settings API), verify screen, export action,
                                   admin notices (dropped events, missing chain key)
    class-wpat-log-table.php       WP_List_Table for the log browser
    class-wpat-cli.php             wp audit verify|export|prune command handlers
    wpat-functions.php             wpat_log() structured logger, wpat_settings(), wpat_client_ip()

  admin/
    css/admin.css                  Minor styles on top of WP admin defaults
    js/verify.js                   Verify progress polling (hand-written, no build)

  languages/wp-audit-trail.pot
  tests/
    bootstrap.php                  WP test suite + plugin loader
    test-chain.php                 Canonicalization, hashing, seal, key fallback
    test-recorder.php              Buffering, sync flush, ordering, lock, spill path
    test-listeners.php             Real hooks -> expected rows (auth, user, content, extensions, options)
    test-redactor.php              Password/option redaction
    test-verifier.php              Pass, link/seal/count failures, chunk boundaries
    test-retention.php             Anchor correctness, idempotent prune, verify-after-prune
    test-scanner.php               Add/modify/delete detection, resume, sweep gating
    test-exporter.php              CSV shape, filters, streaming batches
    test-cli.php                   Command handlers via output shim
  docs/                            Planning docs (this folder)
  README.md
```
