# Testing - wp-audit-trail

## Strategy

- **PHPUnit against the WordPress test suite** (wordpress-develop test lib + MariaDB), the same
  pattern proven in the sibling `wp-ai-writer` repo. Tests run in the suite's isolated database,
  never against a development site. There is no JS test rig: the only JavaScript is the verify
  polling file, covered by manual QA.
- **Real hooks, real tables.** Listener tests fire the actual WordPress actions
  (`wp_insert_user`, `wp_update_user`, `wp_trash_post`, `do_action('activated_plugin', ...)`)
  and assert on the resulting `wpat_events` rows, so the hook wiring itself is under test, not
  just the normalizers. Chain assertions always recompute hashes independently in the test
  rather than trusting plugin code twice.
- **No network, no mail, no real cron.** `wp_mail` is intercepted via the `pre_wp_mail` filter;
  cron callbacks are invoked directly; the scanner runs against a fixture directory created in
  the test's temp space. Time-dependent logic takes a clock through an overridable `now()` so
  retention boundaries are tested exactly.
- **Failure paths are first-class.** The spill path (lock timeout, missing table), redactor
  fail-closed behavior, interrupted prune, and interrupted scan each have dedicated tests; the
  happy path alone proves nothing for an audit tool.

### Unit-level (isolated classes)

- `WPAT_Chain`: canonical field order and flags frozen by a golden-vector test (fixed input,
  expected exact sha256); genesis; seal build/verify; key fallback order; float rejection.
- `WPAT_Redactor`: pattern matches, `user_pass` stripping, fail-closed replacement, filter
  extension.
- `WPAT_Verifier`: pass, link failure at first/middle/last row, seal failure, count failure,
  chunk boundary exactness (tamper at row 1000 vs 1001), anchor-start behavior.
- `WPAT_Exporter`: CSV shape, hash columns, formula-injection prefixing, batch streaming.

### Integration-level (through WordPress)

- Recorder: buffered events flush once on shutdown in capture order; warning+ events flush
  immediately; duplicate consecutive events collapse; lock contention (second connection holds
  the lock) triggers retry then spill with counter increment; chain verifies after concurrent
  inserts from two connections.
- Listeners: one test per event source area asserting event_type, severity, actor snapshot,
  object fields, and payload shape against the catalog in `docs/api-contracts.md`.
- Retention: anchor payload correctness, cumulative counts, verify-after-prune, idempotent
  re-run, crash between anchor and delete (simulated by stopping after the anchor commit).
- Scanner: fixture tree add/modify/delete detection, resume from cursor, sweep gated on
  completed walks, oversize file handling, orphaned-run restart.
- Admin: settings sanitization matrix, log query filter whitelisting, ajax verify permission
  denial for non-admins, export honors filters.
- CLI: command classes invoked directly with a `WP_CLI` output shim; exit codes and `--dry-run`
  behavior asserted.
- Uninstall: tables and options removed, or kept when the setting says so.

### Manual QA (per phase checklist in docs/phases.md)

Real wp-env walkthroughs: hand-performed admin actions producing rows, SQL tampering making
verify fail with the right report, the verify screen's progress UX, scan behavior under a small
time budget, digest arrival via a mail-catcher, and keyboard/screen-reader passes.

## Commands

```
composer install
composer run lint        # PHPCS (WordPress-Extra + WordPress-Docs)
composer run lint:fix    # PHPCBF
composer run test        # PHPUnit against the WP test suite

npx wp-env start         # local WordPress at http://localhost:8888
npx wp-env logs          # PHP error log (watch for wpat.* lines)

# Test suite setup (CI mirrors this): wordpress-develop lib + MariaDB service,
# WP_TESTS_DIR pointing at the checked-out suite, wp-tests-config.php with the DB creds.
```

## CI plan

GitHub Actions on push and pull request to `main`: one job matrix over PHP 8.1 and 8.3 with a
MariaDB 10.6 service container, steps: checkout, install PHP + composer cache, install the
wordpress-develop test lib (pinned tag), `composer run lint`, `composer run test`. A red check
blocks merge; there is no deploy step (distribution is a tagged zip).

## Definition of done for a feature

1. `composer run lint` clean.
2. `composer run test` green, new tests included in the same commit series.
3. The feature's manual checklist items in `docs/phases.md` pass in wp-env.

After creating or editing any file, run lint and tests and fix all errors before reporting done.
