# API Contracts - wp-audit-trail

There is no REST API. The public contracts are: the **event catalog** (what gets written to
`wpat_events` and what its payload looks like, which exports and integrators depend on), two
**admin-ajax** actions for verify progress, one **admin-post** action for CSV export, the
**WP-CLI** commands, and the **CSV format**. All are agreed here before any code is written.

All timestamps are UTC `Y-m-d H:i:s`. All examples omit `prev_hash`/`entry_hash` unless relevant;
every real row has both.

## Error format (used everywhere)

Internal failures are `WP_Error` objects with codes prefixed `wpat_`:

| Code | Meaning |
|---|---|
| `wpat_forbidden` | Capability or nonce check failed |
| `wpat_invalid_input` | A parameter failed validation (message names the field) |
| `wpat_chain_write_failed` | Lock timeout or DB error while writing the chain |
| `wpat_verify_failed` | Verification found a link, seal, or count failure |
| `wpat_not_found` | Referenced run/row does not exist or expired |
| `wpat_server_error` | Unexpected error (details logged, never returned) |

admin-ajax responses use the WordPress envelope, HTTP status matching the failure:

```json
{ "success": false, "data": { "code": "wpat_forbidden", "message": "You are not allowed to do that." } }
```

Success:

```json
{ "success": true, "data": { } }
```

WP-CLI maps `WP_Error` to `WP_CLI::error($message)` and exits non-zero: **0** success/pass,
**1** operation ran and failed (verification failed), **2** usage or permission error. No stack
traces or raw database errors in any surface.

---

## Event catalog

Severity: 1 info, 2 notice, 3 warning, 4 critical. Severity >= 3 is written synchronously at
capture and included in the daily digest. `payload` examples show the JSON stored in the column.

### Auth

| event_type | Severity | object_type/object_id | Payload |
|---|---|---|---|
| `auth.login` | 1 | `user` / user id | null |
| `auth.logout` | 1 | `user` / user id | null |
| `auth.login_failed` | 3 | `user` / attempted login (not an id) | `{"username": "admin"}` |

`auth.login_failed` has `actor_id` 0 (nobody authenticated); the attempted username lands in
`object_id`/`object_label` and the payload, sanitized, capped at 60 chars.

Example row (`auth.login`):

```json
{
  "occurred_at": "2026-07-27 09:12:44", "actor_id": 3, "actor_login": "editor_jane",
  "actor_ip": "203.0.113.9", "actor_ua": "Mozilla/5.0 ...", "event_type": "auth.login",
  "severity": 1, "object_type": "user", "object_id": "3", "object_label": "editor_jane",
  "summary": "editor_jane logged in", "payload": null
}
```

### Users

| event_type | Severity | Payload |
|---|---|---|
| `user.created` | 2 | `{"fields": {"user_login": "newbie", "user_email": "n@example.com", "roles": ["subscriber"]}}` |
| `user.updated` | 2 | `{"changed": {"user_email": {"before": "a@example.com", "after": "b@example.com"}}}` |
| `user.deleted` | 4 | `{"user_login": "gone", "user_email": "g@example.com", "roles": ["author"], "reassigned_to": 1}` |
| `user.role_changed` | 4 | `{"roles": {"before": ["author"], "after": ["administrator"]}}` |

`user_pass` never appears in any user payload, in any state, hashed or plain; a password change
appears in `user.updated` as `{"changed": {"user_pass": "[redacted]"}}`.

### Content

Tracked post types: `post`, `page` (filter `wpat_tracked_post_types`). `object_id` is the post
id, `object_label` the title at event time.

| event_type | Severity | Payload |
|---|---|---|
| `content.published` | 2 | `{"post_type": "post", "status": {"before": "draft", "after": "publish"}}` |
| `content.updated` | 1 | `{"changed_fields": ["post_title", "post_content"], "content_sha256": {"before": "9f8...", "after": "a11..."}}` |
| `content.trashed` | 2 | `{"post_type": "page"}` |
| `content.deleted` | 2 | `{"post_type": "page", "was_status": "trash"}` |

Post bodies are never stored; `content_sha256` proves whether content changed without retaining
it (size and privacy, see `docs/architecture.md`).

### Extensions and core

`object_id` is the plugin basename (`akismet/akismet.php`), theme slug, or `core`.

| event_type | Severity | Payload |
|---|---|---|
| `plugin.installed` | 4 | `{"name": "Akismet", "version": "5.3"}` |
| `plugin.activated` | 4 | `{"name": "Akismet", "version": "5.3"}` |
| `plugin.deactivated` | 4 | `{"name": "Akismet", "version": "5.3"}` |
| `plugin.updated` | 3 | `{"name": "Akismet", "version": {"before": "5.3", "after": "5.4"}}` |
| `plugin.deleted` | 4 | `{"name": "Akismet", "version": "5.4"}` |
| `theme.installed` | 4 | `{"name": "Twenty Twenty-Five", "version": "1.2"}` |
| `theme.switched` | 4 | `{"stylesheet": {"before": "twentytwentyfour", "after": "twentytwentyfive"}}` |
| `theme.updated` | 3 | `{"name": "Twenty Twenty-Five", "version": {"before": "1.2", "after": "1.3"}}` |
| `core.updated` | 4 | `{"version": {"before": "6.5.2", "after": "6.5.3"}}` |

### Options

Default whitelist (filter `wpat_tracked_options`): `siteurl`, `home`, `admin_email`, `blogname`,
`users_can_register`, `default_role`, `WPLANG`, `permalink_structure`.

| event_type | Severity | Payload |
|---|---|---|
| `option.updated` | 3 | `{"option": "default_role", "value": {"before": "subscriber", "after": "editor"}}` |

Values matching the redaction patterns are stored as `"[redacted]"` on both sides; values longer
than 1,024 chars are stored as `{"sha256": "..."}` instead of the value.

### Files

`object_id` is `sha256(relative path)`, `object_label` the relative path (ellipsized to 255).

| event_type | Severity | Payload |
|---|---|---|
| `file.added` | 4 | `{"path": "plugins/evil/backdoor.php", "sha256": "d1c...", "size": 4096}` |
| `file.modified` | 4 | `{"path": "themes/x/functions.php", "sha256": {"before": "9e2...", "after": "77b..."}, "size": {"before": 8210, "after": 9544}}` |
| `file.deleted` | 4 | `{"path": "plugins/old/old.php", "sha256": "41a...", "size": 1024}` |

### Audit (internal)

| event_type | Severity | Payload |
|---|---|---|
| `audit.anchor` | 2 | `{"pruned_through_id": 4200, "pruned_through_hash": "8c1...", "pruned_count": 4180, "cumulative_count": 4180, "range_first_occurred_at": "2026-01-02 00:11:09", "range_last_occurred_at": "2026-04-27 23:58:41"}` |

Anchors are ordinary chained rows written by pruning; verification starts from the newest one.
`cumulative_count` is the lifetime total of pruned entries across all anchors.

---

## Hash chain contract (summary)

Full specification in `docs/architecture.md`; the invariants integrators may rely on:

- `entry_hash = sha256(prev_hash . '|' . canonical_json(event))`, canonical field order
  `occurred_at, actor_id, actor_login, actor_ip, actor_ua, event_type, severity, object_type,
  object_id, object_label, summary, payload`; genesis `prev_hash` is 64 zeros.
- An exported CSV slice starting at a row whose `prev_hash` you trust can be re-verified
  offline with ~10 lines of any language: hash each row, compare to the next row's `prev_hash`.
- The sealed head is internal; external verification uses the digest email's published head hash.

---

## admin-ajax actions

Both require an authenticated `manage_options` user and the `wpat_verify` nonce; anything else
gets the 403 envelope. Registered for logged-in users only (no `nopriv`).

### POST /wp-admin/admin-ajax.php  action=wpat_verify_start

Request: `action=wpat_verify_start`, `_wpnonce`.

Response 200:

```json
{ "success": true, "data": { "run_id": "vr_6f2a9c", "total_rows": 12400 } }
```

Starts a server-side run: cursor and running hash live in a transient keyed by `run_id`
(15-minute TTL, refreshed per step). Starting a new run invalidates the previous one.

### POST /wp-admin/admin-ajax.php  action=wpat_verify_step

Request: `action=wpat_verify_step`, `_wpnonce`, `run_id`.

Each call verifies up to 1,000 rows server-side. Running:

```json
{ "success": true, "data": { "state": "running", "verified_rows": 5000, "total_rows": 12400 } }
```

Terminal pass:

```json
{ "success": true, "data": { "state": "passed",
  "report": { "verified_rows": 12400, "anchors_seen": 2, "entry_count": 16580,
              "head_hash": "8c1f...", "duration_ms": 3120 } } }
```

Terminal fail:

```json
{ "success": true, "data": { "state": "failed",
  "report": { "kind": "link", "first_bad_id": 4812,
              "expected": "77b4...", "actual": "d900...",
              "message": "Row 4812 does not match the chain. The row or an earlier row was altered or removed." } } }
```

`kind` is `link`, `seal`, or `count` (meanings in `docs/architecture.md`). An expired or unknown
`run_id` returns the `wpat_not_found` envelope with HTTP 404; the UI restarts the run.

---

## admin-post action

### POST /wp-admin/admin-post.php  action=wpat_export

Request fields: `_wpnonce` (`wpat_export`), plus the log screen's current filters:
`type`, `severity`, `actor`, `after`, `before`, `object_q` (all optional, same validation as the
browser). Requires `manage_options`.

Response: `Content-Type: text/csv; charset=utf-8`,
`Content-Disposition: attachment; filename="audit-trail-{site}-{Ymd-His}.csv"`, streamed in
1,000-row batches. Invalid filters redirect back to the log screen with an admin notice (no
partial file). Failed nonce/capability: standard WordPress death screen with the
`wpat_forbidden` message.

### CSV format

Header row, then one row per event, chain order (ascending id):

```
id,occurred_at,event_type,severity,actor_id,actor_login,actor_ip,actor_ua,
object_type,object_id,object_label,summary,payload,prev_hash,entry_hash
```

- `payload` is the raw JSON string (RFC 4180 quoted); empty when null.
- Cells beginning with `=`, `+`, `-`, or `@` are prefixed with `'` (formula injection guard).
- The hash columns make any contiguous slice externally verifiable per the chain contract.

---

## WP-CLI commands

All commands live under `wp audit`, require the plugin active, and print machine-readable output
only when `--format=json` is passed (human tables/lines otherwise).

### wp audit verify

```
$ wp audit verify
Verifying 12,400 rows in 13 chunks...
.............
Chain intact. 12,400 rows verified, 2 anchors, lifetime entries 16,580.
Head: 8c1f0a92... (sealed 2026-07-27 09:14:02 UTC)
$ echo $?
0
```

Failure:

```
$ wp audit verify
Verifying 12,400 rows in 13 chunks...
.....
Error: Row 4812 does not match the chain. The row or an earlier row was altered or removed.
  kind:     link
  expected: 77b4a0...
  actual:   d900fe...
$ echo $?
1
```

`--format=json` prints the same report object as the ajax terminal response, one JSON document
on STDOUT, nothing else.

### wp audit export

```
$ wp audit export --after=2026-07-01 --type=user.role_changed --format=csv > roles.csv
$ wp audit export --severity=3 --format=json | jq '.[0].event_type'
"auth.login_failed"
$ wp audit export --output=/tmp/audit.csv
Exported 12,400 rows to /tmp/audit.csv
```

Flags: `--after=<Y-m-d[ H:i:s]>`, `--before=...`, `--type=<event_type>` (repeatable,
comma-separated), `--severity=<1-4>` (minimum), `--format=csv|json` (default csv),
`--output=<file>` (default STDOUT; the row-count summary goes to STDERR so pipes stay clean).
Invalid dates or types: usage error, exit 2, nothing written.

### wp audit prune

```
$ wp audit prune --days=90 --dry-run
Would prune 4,180 rows (id <= 4200, occurred 2026-01-02 to 2026-04-27).
Anchor to be written: pruned_through_hash 8c1f0a92..., cumulative_count 4,180.
Dry run: nothing deleted.

$ wp audit prune --days=90 --yes
Wrote anchor id 12,401. Deleted 4,180 rows in 5 batches.
Chain verifies: yes.
```

Flags: `--days=<n>` (default: the retention setting; 0 refuses with exit 2), `--dry-run`,
`--yes` (skip confirmation; required when no TTY). Prune always runs a post-delete verify and
reports it; a failed post-verify exits 1 and the output says exactly what to inspect. Running
concurrently with site traffic is safe (chain lock); running twice is idempotent.

---

## Scheduled (WP-Cron) events

| Hook | Schedule | Work |
|---|---|---|
| `wpat_retention_daily` | daily | Prune per retention setting (no-op at 0) |
| `wpat_digest_daily` | daily | Digest email when warning+ events exist in the last 24h |
| `wpat_scan_start` | daily | Begin a scan run (no-op when disabled) |
| `wpat_scan_tick` | self-scheduled +60s | Continue a time-budgeted scan until complete |

All handlers are idempotent and safe to trigger manually via `wp cron event run <hook>`.
