# Product Requirements - wp-audit-trail

## What we're building

A tamper-evident audit logging plugin for WordPress. It records security-relevant events (logins,
failed logins, user and role changes, content publish/edit/delete, plugin/theme/core changes,
whitelisted option changes, and file modifications under wp-content) into a custom table. Every
entry is linked into a hash chain: `entry_hash = sha256(prev_entry_hash + canonical_serialized_entry)`,
so editing or deleting any past row breaks verification. A sealed chain head signed with a key kept
outside the database makes truncation detectable, not just row edits. Admins get a filterable log
browser, a one-click chain verification report, retention pruning that preserves verifiability via
anchor records, and CSV export. Operators get WP-CLI commands (`wp audit verify`, `wp audit export`,
`wp audit prune`) and an optional daily digest email of high-severity events.

## Target user

An agency, freelancer, or site owner running one or more standard WordPress sites (often on shared
hosting) who needs to answer "who did what, when" after an incident, and needs the answer to be
trustworthy: a log that an attacker with database access cannot silently rewrite. Single site,
self-contained; not a SaaS, not a SIEM.

## Core features (prioritized)

1. **Hash-chained event recording** - Every captured event becomes an immutable row carrying
   timestamp, actor (user id, login snapshot, IP, user agent), event type, severity, object
   reference, a redacted before/after payload, `prev_hash`, and `entry_hash` computed per the
   chain formula. Inserts are serialized through a MySQL named lock (`GET_LOCK`) so concurrent
   requests can never fork the chain.

2. **Event coverage** - Auth (login, failed login, logout), users (create, update, delete, role
   change), content (publish, update, trash, delete for posts and pages), extensions (plugin
   install/activate/deactivate/update/delete, theme install/switch/update, core update), and
   whitelisted option changes with before/after values, redacted by pattern.

3. **Non-blocking writes** - Events buffer in memory and flush once on the `shutdown` hook so
   logging never adds per-event queries to page rendering. Events at severity warning or above
   flush synchronously at capture time so a later fatal cannot lose them. A write failure spills
   the event to the PHP error log and raises an admin notice; nothing is ever dropped silently.

4. **Chain verification** - An admin screen button and `wp audit verify` walk the chain, recompute
   every hash, check the sealed head, and produce a clear pass/fail report naming the first broken
   link. Verification is chunked so large tables verify without timeouts.

5. **Retention with chain-preserving pruning** - A daily job deletes rows older than the retention
   window. Before deleting, it writes an anchor record (itself a chained event) sealing the pruned
   range, so verification still passes end to end after pruning.

6. **Log browser and CSV export** - A `WP_List_Table` screen with filters (event type, severity,
   actor, date range, object search), an expandable detail view showing the payload diff, and a
   streaming CSV export of the filtered set that includes the hash columns so exports are
   externally verifiable.

7. **File integrity scan** - A WP-Cron driven checksum scan of wp-content (uploads excluded by
   default) that hashes files in resumable, time-budgeted chunks, compares against a stored
   baseline, and emits `file.added` / `file.modified` / `file.deleted` events.

8. **Daily digest email** - Optional summary of the last 24 hours of warning-and-above events sent
   via `wp_mail`, including the current chain head hash so the mailbox doubles as an external
   anchor for the chain.

## Non-goals

- SIEM integrations, syslog forwarding, or any outbound push of events.
- Multisite support in v1: activation on multisite is blocked with an explanatory notice.
- Real-time alerting beyond the daily digest (no webhooks, no Slack, no per-event email).
- Tracking every option change: only the documented whitelist (filterable) is recorded.
- Full post-content diffs: content changes store field names plus content hashes, not bodies.
- Protection against an attacker with filesystem access: the chain key lives in `wp-config.php`,
  so filesystem compromise defeats the seal. The threat model is database-level tampering.
- Rollback detection without an external reference: restoring the whole table plus the sealed
  head from an old backup verifies clean. The digest email and off-site export are the mitigations.
- A REST API surface: admin screens, admin-ajax for verify progress, and WP-CLI only.

## Success criteria per core feature

- **Recording** - Each covered action performed in a test site produces exactly one row with the
  documented event type, actor fields, object reference, and payload; two concurrent requests
  writing simultaneously produce a chain that still verifies (the named lock held).
- **Chain integrity** - `wp audit verify` passes on an untouched table; manually editing any
  column of any past row, deleting any row, or truncating the tail makes verification fail and
  the report names the first affected id.
- **Non-blocking writes** - A page request that generates only info-level events performs zero
  audit-table queries before `shutdown`; a failed login is present in the table even when the
  request fatals immediately after the hook fires.
- **Redaction** - No `user_pass` value and no option value matching the redaction patterns ever
  appears in a stored payload; the payload still records that the value changed.
- **Retention** - After pruning with a 90-day window, rows older than the window are gone, an
  anchor row seals the pruned range, and `wp audit verify` still passes; running prune twice is
  idempotent.
- **Browser and export** - Every filter narrows correctly and combines with pagination; the CSV
  of a filtered set opens in a spreadsheet with one row per event and includes `prev_hash` and
  `entry_hash`; exporting 100k rows completes without exhausting memory.
- **File scan** - Adding, editing, and deleting a file under wp-content produces the matching
  event on the next completed scan; a scan interrupted mid-run resumes from its saved position
  instead of restarting.
- **Digest** - With qualifying events present, one email arrives listing counts by type and the
  chain head hash; with none, no email is sent.
