# Design - wp-audit-trail

All screens live inside wp-admin and use WordPress admin styles as-is: `WP_List_Table`, core
buttons, notices, and form tables. `admin/css/admin.css` adds only severity badges, the diff
table, and the verify progress bar. No frameworks, no build step, one hand-written JS file for
verify polling. The design goal is that the plugin looks like WordPress, not like a product
squatting inside it.

## Screens

Top-level menu **Audit Trail** (shield dashicon), capability `manage_options`, three subpages.

### Log (default screen)

- Filter bar above the table: event type dropdown (grouped by area), severity dropdown, actor
  search (login or id), date range (two date inputs), object search, Filter + Clear buttons.
  Filters are GET parameters, so views are bookmarkable and the CSV export inherits them.
- Table columns: Time (UTC, full value in a `title` attribute), Event (type as monospace),
  Severity (badge), Actor (login linking to the user, or "system"), Object (label, ellipsized),
  Summary. Newest first. Standard pagination.
- Each row expands (details/summary disclosure, no JS) to the detail panel: all actor fields,
  the payload rendered as a two-column before/after table (or pretty-printed JSON for
  non-diff payloads), and `prev_hash` / `entry_hash` in monospace.
- Header actions: **Export CSV** (submits current filters to the admin-post endpoint) and a
  compact chain status line: "Last verify: passed, 2026-07-27 09:14 UTC" or "never run".
- Empty states: no rows at all ("No events recorded yet."); filtered to nothing ("No events
  match these filters." with a Clear filters link).

### Verify

- One primary button: **Verify chain**. Below it, the last report if any.
- Running state: progress bar (rows verified / total), driven by `verify.js` polling the step
  endpoint; the button disables; leaving the page abandons the run harmlessly (server-side
  transient expires).
- Pass state: green core notice "Chain intact." with rows verified, anchors seen, head entry
  count, duration.
- Fail state: red core notice naming the failure kind in plain language:
  - link: "Row 4,812 does not match the chain. The row or an earlier row was altered or removed."
  - seal: "The chain links are intact but the head seal does not verify. The chain key changed,
    or the head was replaced."
  - count: "The sealed head expects 12,400 entries but 12,388 exist. Rows were removed from the
    tail."
  Each shows expected vs actual hash in monospace plus a link to the docs section explaining
  next steps.

### Settings

- Standard Settings API form table: Retention days (number, 0 = keep forever, help text warns
  pruning is irreversible), Daily digest (checkbox + recipient email), File scan (checkbox +
  time budget seconds), Keep data on uninstall (checkbox).
- A read-only status box: chain key source (constant vs salts fallback, with a recommendation
  notice when on the fallback), events count, oldest row date, last completed scan, dropped
  events counter with a reset button.

## Severity badges

Text label plus color, never color alone. Colors are WP-admin-adjacent and AA-compliant on white:

| Severity | Label | Background / text |
|---|---|---|
| 1 | info | `#f0f0f1` / `#3c434a` |
| 2 | notice | `#d5e5f5` / `#1d4f8c` |
| 3 | warning | `#fcf3cd` / `#8a6116` |
| 4 | critical | `#facfd2` / `#8a1f2c` |

## Admin notices

- Dropped events (`wpat_dropped_events > 0`): persistent warning notice on plugin screens with
  the count and a link to the settings status box. Dismiss = reset the counter deliberately.
- Chain key recommendation: info notice on plugin screens while `WPAT_CHAIN_KEY` is undefined,
  showing the generated constant line to paste into `wp-config.php`, and nothing sensitive after
  that (the value renders only until the constant exists).
- Multisite activation block: error notice explaining v1 is single-site only.

## WP-CLI UX

- `wp audit verify [--format=table|json]` - progress dots per chunk on TTY; final one-line
  verdict; failures print the same three plain-language explanations as the Verify screen.
  Exit 0 pass, 1 fail, 2 usage error.
- `wp audit export [--after=] [--before=] [--type=] [--severity=] [--format=csv|json]
  [--output=<file>]` - streams to STDOUT by default so it pipes; `--output` writes a file and
  prints the row count to STDERR.
- `wp audit prune [--days=<n>] [--dry-run] [--yes]` - dry run prints the boundary id, row count,
  and anchor preview; without `--yes` on a TTY it asks for confirmation; `--yes` required in
  scripts. Full invocation and output examples live in `docs/api-contracts.md`.

## Accessibility baseline

- Core admin components inherit WP accessibility; the additions keep it: badges are real text,
  the disclosure rows are native `<details>`, the progress bar has `role="progressbar"` with
  `aria-valuenow`, and verify state changes are announced via `wp.a11y.speak`.
- All filters are `<label>`-associated; the screens are fully keyboard-operable; focus is
  visible everywhere (core styles, not overridden).
- Hashes and technical values are monospace with full values available via `title` attributes;
  nothing depends on hover alone (values also appear in the expanded detail).
