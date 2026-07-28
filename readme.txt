=== WP Audit Trail ===
Contributors: thealirazadev
Tags: audit log, activity log, security, tamper evident, wp-cli
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tamper-evident audit logging. Events are hash-chained and the chain head is sealed, so silent log tampering is detectable.

== Description ==

Most activity log plugins store plain database rows, so an attacker who owns the database owns the
log: rows get edited or deleted and the record looks clean. WP Audit Trail assumes the log itself
is a target.

Every recorded event is linked into a hash chain, where each row carries the hash of the row before
it. Editing or deleting any past row breaks verification. The current chain head is additionally
sealed with an HMAC keyed by a value kept in `wp-config.php` rather than the database, so an
attacker with database access alone cannot rewrite history and re-sign the result.

Verification either passes, or it names the first row where history stopped adding up.

= What is recorded =

* Authentication: successful logins, failed logins, logouts.

More event sources (users and roles, content, plugins, themes, core, tracked options, and a
resumable file integrity scan of wp-content) ship in later releases.

= Configuration =

Define a chain key in `wp-config.php` so the seal does not depend on WordPress salts:

`define( 'WPAT_CHAIN_KEY', 'a long random string' );`

Without it the plugin falls back to `wp_salt( 'auth' )`, which works but means rotating salts
invalidates the seal. The plugin shows a generated value to paste in when the constant is missing.

= WP-CLI =

* `wp audit verify` walks the chain, checks the seal, and exits 0 on pass, 1 on failure.

= Privacy =

Passwords are never stored in any form. Only `REMOTE_ADDR` is recorded as the actor IP;
`X-Forwarded-For` is not trusted. The plugin makes no external HTTP requests.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through Plugins > Add New.
2. Activate it. Multisite is not supported in this version and activation is blocked with a notice.
3. Optionally define `WPAT_CHAIN_KEY` in `wp-config.php` as shown above.

== Frequently Asked Questions ==

= What does the seal protect against? =

Database-level tampering. An attacker who can write to the database can edit a row and recompute
every later hash, but cannot produce a valid seal without the key, which lives in the filesystem.

= What does it not protect against? =

Full filesystem compromise, since the key is readable there. It also cannot detect a rollback where
both the events table and the sealed head are restored from a consistent older backup; off-site
exports are the mitigation for that.

= Does it slow the site down? =

Informational events buffer in memory and are written once on `shutdown`, so a page view that
generates no events performs no extra queries. Warning and critical events are written immediately
so they survive a request that dies.

== Changelog ==

= 0.1.0 =
* Initial release: hash-chained event table with a sealed head, authentication event listeners, and `wp audit verify`.
