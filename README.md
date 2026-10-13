# WP Civi Username Migration

Migrates WordPress users whose `user_login` is an email address to unique, partly obfuscated usernames, and stops new registrations from using email as the username.

## Username format

| Source | Pattern | Example |
|--------|---------|---------|
| CiviCRM Individual with first + last name | `{first initial}{first 3 of surname}{contact_id}` | Jack Freedman, contact 4821 → `jfre4821` |
| Fallback (no usable Civi name) | `{email fragment}w{wp_user_id}` | `jack.freedman@…`, user 42 → `jfrew42` |
| New registration (no IDs yet) | `{name or email fragment}{4-char token}` | → `jfrex7k2` |

Users whose `user_login` does **not** contain `@` are never touched.

Related fields cleaned only when they still hold the email-derived value:

- `user_nicename` (author URL slug)
- `nickname` usermeta
- `display_name` (only if it contains `@` or equals the old login)

## Commands

```bash
# Activate
wp plugin activate wp-civi-username-migration

# Dry-run (default) — writes a mapping CSV, no DB changes
wp civi usernames migrate
wp civi usernames migrate --limit=20 --csv=/tmp/wp-civi-usernames.csv

# Apply
wp civi usernames migrate --apply --csv=/tmp/wp-civi-usernames.csv

# Skip staff accounts
wp civi usernames migrate --apply --exclude-roles=administrator

# Verify counts
wp civi usernames verify

# Rollback from a migrate CSV
wp civi usernames rollback --csv=/tmp/wp-civi-usernames.csv
wp civi usernames rollback --csv=/tmp/wp-civi-usernames.csv --apply
```

The mapping CSV contains PII. Prefer a path outside the webroot (default tries `dirname(ABSPATH)/wp-civi-username-migrations/`, else `uploads/wp-civi-username-migrations/`).

## Rollout

1. Full DB backup (UpdraftPlus / mysqldump).
2. Restore a production copy to staging; run dry-run; review CSV for fallback rate, odd names, collisions.
3. `--apply` on staging. Smoke-test: login by email, password reset, checkout registration, Civi sync, Moodle enrolment (Edwiser).
4. Production in a quiet window. **All sessions are invalidated** (auth cookies embed the username).

## Pitfalls

- **Sessions / reset links** — everyone is logged out; emailed password-reset links that carry `login=` break.
- **Email login still works** — the form accepts username or email, so most users notice nothing.
- **Edwiser / Moodle** — existing links use `moodle_user_id` meta and should keep working; Moodle usernames themselves stay as the old email until a separate Moodle pass.
- **Imports keyed on `user_login`** — re-key WP All Import (and any custom scripts) to email.
- **Obfuscation is partial** — `jfre4821` plus a sequential Civi ID is identifiable to staff who know the person.
- **Staff accounts** — `@scl.org` admins with email logins are renamed unless `--exclude-roles=administrator`.
- **Object cache** — the command flushes WP object cache and W3TC when applying.
- **Keep the CSV** until verified; it is required for rollback.

## Forward-fix

The theme no longer forces `user_login = user_email` on WooCommerce registration. This plugin’s `pre_user_login` filter rewrites any new email-style login across every `wp_insert_user()` path.
