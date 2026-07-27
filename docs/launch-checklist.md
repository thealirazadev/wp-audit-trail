# Launch Checklist - wp-audit-trail

Work top to bottom before tagging a release or installing on a production site. Nothing is
checked until verified in the target environment.

## Packaging & licensing

- [ ] Plugin header and `readme.txt` both declare GPL-2.0-or-later; stable tag matches
      `WPAT_VERSION`; tested-up-to matches the actually tested WordPress version.
- [ ] Release zip contains no `docs/`, `tests/`, `composer.lock`, or dotfiles; build the zip
      from a clean export and diff its file list against the expected manifest.
- [ ] `composer.lock` committed in the repo with pinned dev dependency versions.

## Configuration

- [ ] `WPAT_CHAIN_KEY` defined in `wp-config.php` on the target site (not the salts fallback);
      the activation notice no longer shows; the key is backed up somewhere that is not the
      database or the web root.
- [ ] Retention days agreed with the owner before the first prune runs; digest recipient set
      and confirmed deliverable (test mail received, not in spam).
- [ ] Real cron hitting `wp-cron.php` (or `DISABLE_WP_CRON` + system cron) confirmed on the
      host; all four `wpat_*` events listed by `wp cron event list`.

## Integrity verification in production conditions

- [ ] `wp audit verify` passes on the live table after at least one day of real traffic.
- [ ] SQL-tamper drill on a staging copy: edit one row, delete one row, truncate the tail;
      each is caught with the correct report kind (link / link / count).
- [ ] Concurrency drill: parallel login loops while pruning runs; chain still verifies.
- [ ] Digest email received with the head hash; hash matches `wp audit verify` output that day.
- [ ] File scan completed at least once on the real wp-content within the time budget; a planted
      test file was reported added, modified, and deleted across scans.

## Security & privacy

- [ ] No secrets in the repo; the chain key appears nowhere in the database or logs.
- [ ] Redaction spot-checked on the live table: no `$P$`/`$2y$` hash prefixes, no values for
      pattern-matched options (`SELECT` for the patterns, expect zero rows).
- [ ] A subscriber account cannot reach the log screen, ajax actions, or export (403s verified).
- [ ] Stored XSS drill: log entry containing `<script>` in the user agent and object label
      renders escaped in browser and detail views.
- [ ] CSV opened in a spreadsheet: formula-looking cells are inert.

## Performance

- [ ] Query monitor on a quiet front-end page: zero audit queries before shutdown, at most one
      flush after.
- [ ] Log browser and filters responsive with the real row count; EXPLAIN confirms the
      `occurred_at` and `event_type` indexes are used.
- [ ] Export of the full table completes without memory growth (watch the PHP process).

## Quality gates

- [ ] `composer run lint` and `composer run test` green in CI on PHP 8.1 and 8.3.
- [ ] Every phase's manual checklist in `docs/phases.md` passed on a fresh install.
- [ ] `docs/memory.md` updated with the release entry and any deviations found during launch.
