# Phases - wp-audit-trail

**Rule: phase N+1 does not start until the owner approves phase N.** Phases are ordered
smallest-useful-shippable first; each ends green (plugin activates cleanly in wp-env, tests pass,
PHPCS clean, logs quiet). One commit per task, Conventional Commits, in the listed order.

The senior differentiators are hard requirements placed early: the canonical serialization, the
locked single-writer chain, and the sealed head all land in Phase 1 and are never revisited.
Chain-preserving pruning lands in Phase 4 before the scanner, because retention correctness is a
chain property and the scanner is just another event source.

---

## Phase 1 - Chain core, auth events, and CLI verify

**Scope**: A site with the plugin active records logins, failed logins, and logouts into a
hash-chained, sealed table, and `wp audit verify` proves the chain intact or names the first
broken row. Smallest slice that already delivers the product's core promise: a log you can trust.

### Tasks

- Plugin skeleton: header (GPL-2.0-or-later), constants, activation/deactivation hooks,
  multisite activation block, `readme.txt`.
- Tooling: composer dev deps (PHPCS WordPress standards, PHPUnit), `phpcs.xml.dist`,
  `phpunit.xml.dist`, WP test suite bootstrap per the wp-ai-writer MariaDB pattern.
- `WPAT_Migrations` with step 1: create `wpat_events` exactly per `docs/architecture.md`.
- `WPAT_Chain`: `canonical_json()`, `entry_hash()`, genesis constant, chain key resolution
  (`WPAT_CHAIN_KEY` then `wp_salt('auth')`), seal build and seal check.
- `WPAT_Recorder`: buffer, severity-gated sync flush, shutdown flush, `GET_LOCK` writer with
  transaction, retry-once-then-spill path, `wpat_dropped_events` counter.
- `WPAT_Listeners` (auth only): `wp_login`, `wp_login_failed`, `wp_logout` mapped per the
  event catalog; `wpat_client_ip()` with the filter.
- `WPAT_Verifier`: chunked walk (1,000 rows), link/seal/count classification, report shape.
- `WPAT_Cli`: `wp audit verify` with `--format=table|json`, exit codes 0/1/2.
- Activation notice showing a generated `WPAT_CHAIN_KEY` value when the constant is undefined.

### Commits

1. `chore: scaffold plugin skeleton with gpl header`
2. `chore: add phpcs and phpunit tooling with wp test suite`
3. `feat: add events table migration`
4. `feat: add canonical serializer and hash chain`
5. `feat: add sealed chain head`
6. `feat: add single writer recorder with named lock`
7. `feat: add auth event listeners`
8. `feat: add chunked chain verifier`
9. `feat: add wp cli verify command`
10. `test: cover chain recorder verifier and auth listeners`

### Verification checklist

- [ ] Plugin activates in wp-env with zero notices; tables created; `wpat_db_version` set.
- [ ] Log in, log out, fail a login: three rows with correct event_type, actor fields, severity;
      failed login present even though the request ends in a login error page.
- [ ] `wp audit verify` passes; edit any column of any row via SQL and it fails naming that id;
      restore the row, delete the newest row, verify fails with a count/seal report.
- [ ] Two parallel `wp shell` loops inserting events produce a chain that verifies (lock holds).
- [ ] With the events table renamed away, a failed login lands in `error_log` as JSON,
      `wpat_dropped_events` increments, and no fatal reaches the visitor.
- [ ] Activation on a multisite install is blocked with the documented notice.
- [ ] `composer run lint` and `composer run test` clean; no `wpat.*` ERROR lines during manual runs.

---

## Phase 2 - Full event coverage, redaction, and buffering polish

**Scope**: All PRD event sources except files: users, content, plugins/themes/core, whitelisted
options. Redaction guarantees hold. Info events cost nothing until shutdown.

### Tasks

- User listeners: `user_register`, `profile_update` (field diff, `user_pass` stripped),
  `deleted_user`, `set_user_role`.
- Content listeners: `transition_post_status` (publish), `post_updated` (changed field names +
  content sha256s), `wp_trash_post`, `before_delete_post`; posts and pages only, filterable via
  `wpat_tracked_post_types`.
- Extension listeners: `upgrader_process_complete` (plugin/theme/core install + update),
  `activated_plugin`, `deactivated_plugin`, `deleted_plugin`, `switch_theme`,
  `_core_updated_successfully`.
- Option listener: `updated_option` against the documented whitelist, filterable via
  `wpat_tracked_options`, values through the redactor.
- `WPAT_Redactor`: pattern list, fail-closed behavior, `wpat_redact_patterns` filter.
- Duplicate-fire collapse in the buffer (identical consecutive events in one request).
- Severity map finalized in one place and covered by tests.

### Commits

1. `feat: add user lifecycle listeners`
2. `feat: add role change listener`
3. `feat: add content listeners with hash based diffs`
4. `feat: add plugin and theme listeners`
5. `feat: add core update listener`
6. `feat: add tracked option listener`
7. `feat: add payload redactor`
8. `feat: collapse duplicate events in request buffer`
9. `test: cover listeners redaction and buffering`

### Verification checklist

- [ ] Each covered admin action performed by hand yields exactly one row matching the catalog in
      `docs/api-contracts.md` (spot-check ids, labels, payload shapes).
- [ ] `profile_update` firing twice for one save produces one row.
- [ ] A password change row exists with no `user_pass` value anywhere in the table
      (`SELECT ... LIKE` for the hash prefix `$P$` and the plaintext).
- [ ] Changing a whitelisted option shows before/after; changing an option matching the redaction
      patterns shows `[redacted]` both sides; changing an untracked option produces no row.
- [ ] A page view generating only info events runs zero audit queries before shutdown (assert via
      query logging), then one flush.
- [ ] Plugin activate/deactivate, theme switch, and a plugin update via the upgrader each produce
      their documented events; `wp audit verify` still passes after all of it.
- [ ] Lint and tests clean.

---

## Phase 3 - Admin UI: log browser, detail, verify screen, settings

**Scope**: An administrator can browse and filter the log, inspect any entry's payload, run a
chunked verification with a clear pass/fail report, and configure the plugin, all inside wp-admin.

### Tasks

- `WPAT_Admin`: top-level menu "Audit Trail" (`manage_options`), Log / Verify / Settings pages,
  admin notices (dropped events counter, chain key recommendation).
- `WPAT_Log_Table`: `WP_List_Table` with columns (time, event, severity badge, actor, object,
  summary), filters (event type, severity, actor, date range, object search), pagination,
  expandable detail row rendering the payload diff and both hashes.
- Verify screen: start button, progress via `admin/js/verify.js` polling `wpat_verify_step`
  admin-ajax (server-side cursor transient), final report with link/seal/count distinction.
- Settings page (Settings API): retention days, digest enabled + recipient, scan enabled, scan
  time budget; sanitize callbacks; uninstall keep-data toggle.
- Empty states, capability + nonce checks on every action.

### Commits

1. `feat: add admin menu and settings page`
2. `feat: add log browser list table`
3. `feat: add log filters and pagination`
4. `feat: add entry detail with payload diff`
5. `feat: add chunked verify screen with progress`
6. `feat: add admin notices for dropped events and chain key`
7. `test: cover settings sanitization and log queries`

### Verification checklist

- [ ] Every filter narrows correctly, combines with others, and survives pagination; nonsense
      filter values yield an empty state, never an error.
- [ ] Detail view renders a script-tag payload harmlessly escaped (stored XSS check).
- [ ] Verify on a clean 10k-row table completes via multiple ajax steps with visible progress;
      tamper with a row mid-table and the report names it; a subscriber hitting the ajax endpoint
      gets the error envelope, not data.
- [ ] Settings reject a negative retention and a malformed email with field messages.
- [ ] `wpat_dropped_events > 0` shows the notice; resetting clears it.
- [ ] Screens keyboard-navigable; badges carry text, not just color; lint and tests clean.

---

## Phase 4 - Retention with anchors, CSV export, CLI export and prune

**Scope**: The log can run forever on finite disk: pruning preserves verifiability through anchor
records, and the log (or any filtered slice) can leave the site as a verifiable CSV.

### Tasks

- `WPAT_Retention`: boundary selection, anchor event insert (cumulative counts), batched deletes
  under the chain lock, idempotency; daily WP-Cron schedule honoring retention_days (0 = off).
- Verifier update: start from the newest anchor, tolerate pending-deletion rows, count check
  incorporating anchor cumulative counts.
- `WPAT_Exporter`: streaming CSV (1,000-row batches, formula-injection guard, hash columns),
  shared by the admin-post download (current filters, nonce-gated) and CLI.
- `WPAT_Cli`: `wp audit export` (filters, csv|json, file or STDOUT) and `wp audit prune`
  (`--days`, `--dry-run`, `--yes`).

### Commits

1. `feat: add anchor records`
2. `feat: add chain preserving prune`
3. `feat: schedule daily retention run`
4. `feat: start verification from newest anchor`
5. `feat: add streaming csv exporter`
6. `feat: add export action to log screen`
7. `feat: add wp cli export command`
8. `feat: add wp cli prune command`
9. `test: cover pruning anchors export and cli`

### Verification checklist

- [ ] Seed 1,000 backdated rows; `wp audit prune --days=30 --dry-run` reports without deleting;
      with `--yes` it prunes, writes one anchor, and `wp audit verify` passes; running prune
      again changes nothing.
- [ ] Kill prune between anchor and deletes (breakpoint or SIGKILL): verify still passes; the
      next run completes the deletion.
- [ ] Prune racing a burst of inserts (parallel loop) leaves a verifiable chain.
- [ ] Admin export of a filtered set matches the on-screen rows; a cell beginning with `=` is
      prefixed; export of 100k seeded rows stays under a flat memory ceiling.
- [ ] `wp audit export --format=json | jq` round-trips; exit codes match the contract.
- [ ] Lint and tests clean.

---

## Phase 5 - File integrity scan, digest email, uninstall, release polish

**Scope**: Everything remaining in the PRD: the resumable checksum scan, the daily digest with
head anchoring, a clean uninstall, and release-ready docs.

### Tasks

- Migration step: `wpat_file_hashes` table.
- `WPAT_Scanner`: sorted walk, time-budgeted chunks, resumable state, oversize handling, sweep
  phase gated on complete walks, orphaned-run recovery, `wpat_scan_paths` filter; daily WP-Cron
  scheduling plus self-rescheduling ticks.
- Emit `file.added` / `file.modified` / `file.deleted` through the recorder; baseline upserts.
- `WPAT_Digest`: daily query, mail assembly (counts, recent rows, head hash, last verify
  result), `wp_mail` failure logging.
- `uninstall.php` honoring keep-data; final `README.md` (real install/usage replacing planned
  wording) and `readme.txt` polish.

### Commits

1. `feat: add file hash baseline table`
2. `feat: add chunked resumable file scanner`
3. `feat: emit file change events from scan`
4. `feat: schedule scan via wp cron`
5. `feat: add daily digest email`
6. `feat: add uninstall routine`
7. `docs: finalize readme for release`
8. `test: cover scanner digest and uninstall`

### Verification checklist

- [ ] Add, edit, and delete a file under wp-content: the next completed scan emits exactly the
      three matching events with correct hashes; uploads changes emit nothing by default.
- [ ] Set the time budget to 2s on a large wp-content: the scan spans multiple ticks, resumes
      after `wp cron event run` interruptions, and never emits `file.deleted` mid-walk.
- [ ] An orphaned run (backdate `started_at` 25h) restarts cleanly on the next daily event.
- [ ] With warning+ events present, the digest arrives once with counts and the head hash; with
      none, no email; `wp_mail` forced to fail logs `wpat.digest.mail_failed`.
- [ ] Uninstall with keep-data off removes both tables and every `wpat_*` option; with it on,
      data survives reinstall and the chain still verifies.
- [ ] Full manual pass of every earlier phase checklist on a fresh install; lint and tests clean.

---

## Backlog

- `wp audit scan` CLI trigger for the file scan (v1 relies on WP-Cron and `wp cron event run`).
- Always-send digest mode so the mailbox anchors the head even on quiet days.
- Multisite support (network-wide chain vs per-site chains needs a design decision).
- Object-level filter deep links from user/post edit screens into the log browser.
- Configurable sync-flush severity threshold (fixed at warning in v1 per YAGNI).
