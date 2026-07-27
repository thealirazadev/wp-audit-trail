# Engineering Rules - wp-audit-trail

These rules extend the workspace `CLAUDE.md` and are binding for every change in this repository.

## Conventions

- **Prefix everything**: classes `WPAT_PascalCase`, functions/hooks/options/transients
  `wpat_snake_case`, constants `WPAT_UPPER_SNAKE`. Table names `{$wpdb->prefix}wpat_*`. Text
  domain `wp-audit-trail`. No unprefixed symbols in the global namespace, ever.
- **WordPress coding standards**: PHPCS with WordPress-Extra + WordPress-Docs must pass clean.
  Yoda conditions, tabs, snake_case per the ruleset; do not hand-fight PHPCBF.
- **One class per file**, `class-wpat-{name}.php`, all under `includes/`. Listeners are thin: a
  listener callback maps hook args to an event array and hands it to the recorder; no queries, no
  business logic in listeners.
- **Append-only log**: nothing in this codebase may UPDATE a `wpat_events` row or DELETE one
  outside `WPAT_Retention`. Any new code path that writes chain rows must go through
  `WPAT_Recorder` so the lock and seal discipline cannot be bypassed.
- **Canonical form is frozen**: the field order and flags in `WPAT_Chain::canonical_json()` are a
  compatibility contract with every existing row. Changing them breaks verification of history
  and requires an owner-approved, versioned migration strategy; do not touch casually.
- **Commit format**: Conventional Commits, short imperative subject, e.g.
  `feat: add role change listener`, `fix: cap user agent at 255 chars`.
- **One commit per task**: the commit lists in `docs/phases.md` are the intended order; never
  batch features, never fragment one small task.
- **Pin exact dependency versions**: dev dependencies only (PHPCS, PHPUnit, test lib); exact
  versions in `composer.json`, `composer.lock` committed. Runtime dependencies: none, and adding
  one requires owner approval first.
- **DB migration rule**: every schema change is a new numbered step in `WPAT_Migrations` keyed by
  `wpat_db_version`; applied steps are never edited. dbDelta quirks (two spaces after PRIMARY
  KEY, no backticks in KEY lines) are respected and tested.

## Error handling & logging

- **Every fallible call handles failure**: `$wpdb` writes (check the return, roll back the
  transaction), `GET_LOCK` (timeout path), filesystem reads in the scanner (unreadable file =
  skip + count, not fatal), `wp_mail` (log false returns), `wp_json_encode` (false = spill raw).
- **The plugin must never break the host site**: every listener body is wrapped so an internal
  failure degrades to the spill path and a logged error, never an uncaught exception in a
  customer-facing request. Auditing is important; the site staying up is more important.
- **Loud loss only**: an event that cannot be chained is JSON-encoded to `error_log` and counted
  in `wpat_dropped_events`, which drives a persistent admin notice. Silent drops are a defect.
- **One error format**: internal failures are `WP_Error` with codes prefixed `wpat_` and human
  messages; admin-ajax responds via `wp_send_json_error(['code' => ..., 'message' => ...],
  $status)`; WP-CLI maps them to `WP_CLI::error()` and exit codes per `docs/api-contracts.md`.
  No stack traces or raw `$wpdb->last_error` in any user-facing output.
- **Structured logging**: `wpat_log($event_key, $context)` writes a single-line
  `wpat.{area}.{action}` entry with a JSON context to `error_log` (WP_DEBUG_LOG aware). Keys used:
  `wpat.flush.spilled`, `wpat.flush.committed`, `wpat.verify.completed`, `wpat.verify.failed`,
  `wpat.prune.completed`, `wpat.scan.completed`, `wpat.scan.resumed`, `wpat.digest.sent`,
  `wpat.digest.mail_failed`. Never log secrets, payload bodies, or the chain key.

## Security

- **Capability checks**: every admin screen, admin-ajax action, and admin-post action checks
  `manage_options` and verifies a nonce before doing anything. CSV export and verify are
  state-reading but still nonce-gated (they reveal audit data).
- **Queries**: `$wpdb->prepare()` for every value interpolation, including ORDER BY/LIMIT paths
  built from user filters (whitelist columns, cast integers). dbDelta strings are the only
  unprepared SQL allowed.
- **Input**: all filter/settings input goes through `sanitize_key`, `absint`, explicit
  whitelists, and date parsing with rejection, server-side. Settings use the Settings API
  sanitize callbacks.
- **Output**: every echo is escaped (`esc_html`, `esc_attr`, `esc_url`); payload JSON is
  pretty-printed inside `esc_html` in a `<pre>`; stored user agents and object labels are
  attacker-influenced and must never render unescaped. CSV cells starting with `= + - @` are
  prefixed with `'` to block spreadsheet formula injection.
- **Secrets**: the chain key is read from `WPAT_CHAIN_KEY`/salts at runtime, never stored in the
  database, never logged, never echoed after the one-time activation notice.
- **Redaction is fail-closed**: when the redactor cannot parse a payload it replaces the whole
  payload with `"[redaction failed]"` rather than storing it raw.
- **No external requests**: the plugin makes zero HTTP calls. Adding one is a scope change
  requiring owner approval, not a code review comment.

## Performance

- **Zero added queries on a quiet request**: no autoloaded heavy options (`wpat_chain_head`,
  `wpat_scan_state` are autoload off), listeners register but do not query, buffered flush only
  runs when the buffer is non-empty.
- **Frontend footprint**: no scripts, styles, or output on the public site; admin assets enqueue
  only on the plugin's own screens.
- **Bounded work everywhere**: verification, prune deletes, scans, and exports are all chunked
  with documented batch sizes; no unbounded `SELECT *` on the events table anywhere.

## Simplicity / YAGNI-KISS

- Build only what the current phase requires; the settings surface is exactly the five documented
  settings, no more toggles.
- No abstraction until three real use cases exist. The verifier and exporter are shared by admin
  and CLI, which is their justification; nothing else gets an interface in v1.
- No new wrapper classes, managers, or utils files beyond the layout in `docs/architecture.md`
  without owner approval first.
- If a solution exceeds roughly 150 lines, pause and justify it before continuing.

## Boundaries - never do without asking the owner first

- No wholesale delete/rewrite of working files; targeted edits, flag destructive changes first.
- Do not change `docs/PRD.md` or `docs/architecture.md` without flagging the change and its
  reason and getting sign-off; they are the source of truth.
- No new dependency (runtime or dev) without approval: what, why, version, size, then wait.
- Ask when ambiguous rather than guessing at product behavior.
- Stop after two failed fix attempts on the same problem; report what was tried instead of
  thrashing.
- Scope discipline: any mid-phase request not in `docs/PRD.md` gets classified with the owner as
  current phase, new phase, or Backlog in `docs/phases.md`. Never silently absorb scope.
