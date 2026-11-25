# Design decisions

## 1. Each row is hash-linked to the previous one through a frozen canonical form

Every event row stores `prev_hash` and `entry_hash = sha256( prev_hash . '|' . canonical_json( event ) )`, and the first row links to a genesis value of 64 zeros. `WPAT_Chain::canonical_json()` serializes twelve fields in a fixed order (`WPAT_Chain::FIELDS`) with fixed JSON flags, so editing any column of any past row changes its hash and breaks every link after it. The canonical form is a compatibility contract with all existing history: changing the order, the casts or the flags would make every untouched chain fail verification. A golden-vector test pins the exact JSON and hash for a known event.

## 2. Values are cast explicitly before hashing

The same event is serialized twice in its life: once from PHP values when it is inserted, and once from a `$wpdb` row, where every column comes back as a string, when it is verified. `actor_id` and `severity` are therefore always cast to integers, the other fields to strings, and an empty payload to `null`. Without those casts an untouched chain would fail verification. Floats are rejected outright because their JSON encoding is not byte-stable across PHP versions, and arrays or objects are rejected because a field must be a scalar; the payload is embedded as its already-encoded JSON string so the canonical form never depends on re-encoding it.

## 3. A sealed head, keyed outside the database, makes truncation detectable

A hash chain alone does not stop someone with database access: they can edit a row and recompute every later hash, or delete rows from the end. After every write the plugin stores a head record in `wpat_chain_head` with the last row id, its hash and the lifetime entry count, signed with HMAC-SHA256. The key comes from `WPAT_CHAIN_KEY` in `wp-config.php`, falling back to `wp_salt( 'auth' )`, so it lives on the filesystem and cannot be re-signed by a database-only attacker. Rotating the salts with no dedicated key invalidates the seal, which verification reports as a seal failure rather than accepting. On activation without a key, the plugin generates one, keeps it in a one-hour transient and shows it once in an admin notice so it can be pasted into `wp-config.php`. The seal does not protect against an attacker who can also read the filesystem, or against restoring the table and head together from an older consistent backup.

## 4. One writer, one lock, one transaction

`WPAT_Recorder` is the only code path that inserts chain rows. A flush takes the MySQL named lock `wpat_chain_<prefix>` with `GET_LOCK` (5 seconds, retried once), starts a transaction, reads the current tail, hashes and inserts the buffered events in capture order, writes the new sealed head, and commits. Because the head option is updated inside the same transaction, the rows and the seal either both land or neither does; on rollback the cached head is deleted so a stale copy is not reused. A named lock gives a single-writer section across connections without a lock table, so concurrent requests cannot fork the chain. The class is longer than the rest of the plugin on purpose: splitting the lock, transaction, seal and spill paths apart would create ways to write a row outside the lock.

## 5. The head is read past the options cache

`WPAT_Chain::read_head()` selects `wpat_chain_head` straight from the options table instead of calling `get_option()`. Options are cached per PHP process, so two concurrent writers each kept sealing an entry count derived from the snapshot they loaded first. The links stayed sound, but a test with two processes writing 60 events each sealed a count of 118 for 120 rows and verification failed with `kind: count`. The head is read once per flush and once per verification, so one uncached query is cheap. A regression test poisons the object cache and checks that the database value wins.

## 6. Severity decides when an event is written

Events at severity 3 or higher (warning and critical) flush the whole buffer at capture time, so a failed login survives a request that ends in an error page or a fatal right afterwards. Lower severities wait for a single flush on `shutdown` at priority 100, so an ordinary page view costs no audit queries until the end. `record()` and `flush()` catch every `Throwable`: auditing matters, but the site staying up matters more. The threshold is a constant, not a setting.

## 7. An event that cannot be chained is spilled, never dropped silently

If the lock times out, the table is missing or an insert fails, the recorder writes each event as a `wpat.flush.spilled` line to the PHP error log, payload included, and increments the `wpat_dropped_events` option. The full event goes to the log on purpose because that line is the only remaining copy of the record. Visitors see the normal page; no database error reaches them.

## 8. Verification walks the chain in chunks and names the failure

`WPAT_Verifier` reads 1,000 rows at a time by id, compares each `prev_hash` with the running hash and recomputes each `entry_hash`, using `hash_equals()` throughout. A mismatch is reported as `kind: link` with the first bad row id. After the walk it checks the seal: a missing head while rows exist, a bad signature, or a head that does not point at the last row is `kind: seal` (rows removed from the end, or a changed key); a lifetime count that differs from the rows walked is `kind: count` (rows deleted). Each failure carries a plain-language message, expected and actual values, and the number of rows verified. Running in steps keeps a large log from hitting a time limit and lets the CLI show progress.

## 9. The CLI returns exit codes instead of halting

`wp audit verify` supports `--format=table|json` and exits 0 when the chain is intact, 1 when verification fails and 2 for a usage error. `WPAT_Cli` takes injectable output and error writers and returns the exit code; only the thin closure registered with WP-CLI calls `WP_CLI::halt()`. That makes the handler and its exit codes testable without a WP-CLI harness. Running it on an empty log used to print a blank head line (`Head: 000...0 (sealed  UTC)`); the head line is now omitted when there is nothing to report.

## 10. Listeners are thin, and attacker input is treated as such

`WPAT_Listeners` maps `wp_login`, `wp_login_failed` and `wp_logout` to `auth.login`, `auth.login_failed` and `auth.logout` and hands each event to the recorder with no queries of its own. On `wp_login` the actor is passed explicitly because the current user is not yet set when that hook fires. The username on a failed login is attacker-controlled, so it is sanitized and cut to 60 characters before it is stored. The client IP is taken only from `REMOTE_ADDR`, validated with `FILTER_VALIDATE_IP`; `X-Forwarded-For` can be forged, so sites behind a reverse proxy supply the real address through the `wpat_client_ip` filter. Every text field is truncated to its column width before hashing, so the database never silently shortens a value and invalidates its hash.

## 11. The log is an append-only custom table with versioned migrations

Events live in `{prefix}wpat_events`, created by `dbDelta` in migration step 1 and tracked by `wpat_db_version`; later schema changes are new steps, never edits to old ones. Indexes cover `occurred_at`, `event_type`, `actor_id`, `severity` and an `(object_type, object_id)` prefix. Post meta and options are wrong for a log that must be append-only, indexed and prunable in bulk. `dbDelta` is whitespace-sensitive (two spaces after `PRIMARY KEY`, one `KEY` per line, no backticks), otherwise it recreates indexes on every run.

## 12. Multisite activation is refused

A network-wide chain and per-site chains give different verification guarantees, and neither has been designed, so activating on multisite deactivates the plugin and explains why instead of silently producing a log whose meaning is undefined.

## 13. Tests clean up after the recorder's own commit

The recorder issues `COMMIT`, which ends the WordPress test suite's outer transaction, so recorder, verifier, listener and CLI tests truncate the events table in `set_up()` instead of relying on rollback. Chain assertions recompute hashes independently in the test rather than trusting plugin code to check itself.
