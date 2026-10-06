# Backup, restore, and upgrade

This guide covers operator-managed disaster recovery and release upgrades for
Fictional Internet 1.0. It is not an in-app backup tool.

## Three different recovery layers

1. **Project JSON export** is a user-facing, project-scoped archive for
   portability and human inspection. It contains project context, artifacts,
   immutable versions, and captured provenance. It excludes account and
   authentication data and credentials. Version 1 has no importer, cannot
   recreate an account, and is not a production backup. See
   [Project export format v1](project-export-format-v1.md).
2. **Production backup** is operator-managed disaster recovery. It includes a
   consistent production database backup and separately protected persistent
   deployment secrets/configuration, especially the original `APP_KEY`.
3. **Application release/source** identifies the code and runtime to restore:
   release tag/commit, migrations, `composer.lock`, `package-lock.json`, built
   assets or the means to rebuild them, and deployment configuration. See
   [Installation](installation.md) and [Deployment](deployment.md).

## Persistent-state inventory

| State | Recovery treatment | Reason |
| --- | --- | --- |
| Production MySQL database | **Essential.** Back up schema and data. | Contains accounts, password hashes, credential ciphertext, projects, Project Context, artifacts, all versions, lineage, snapshots, cache/session rows, and attempts. |
| Original `APP_KEY` and any configured `APP_PREVIOUS_KEYS` | **Essential, secret.** Keep a secure copy with the matching database backup. | Laravel's encrypted credential cast needs the key used to encrypt each stored value. |
| `.env` and secret-manager values | **Essential configuration, secret.** Preserve securely or record a reliable re-provisioning source. | Includes database/mail credentials and environment configuration. Do not treat all values as equivalent to `APP_KEY`; most can be rotated or reconfigured. |
| Application release/tag and Composer/npm lockfiles | **Essential to reproduce the matching app; reproducible from source control/artifact storage.** | Use the same release first for recovery and retain both lockfiles. |
| `public/build` assets | **Reproducible** from committed frontend source and `package-lock.json`; retaining the built release artifact is useful. | Vite output is not the only copy of project data. |
| `storage/logs` | **Optional operational history.** Archive separately only when needed for incident investigation or retention policy. | Logs are not the durable project record and may contain sensitive operational/account information. |
| `storage/framework/{cache,sessions,views}` | **Reproducible or disposable.** Cache and compiled views can be rebuilt. Sessions may be deliberately invalidated on recovery. | No unique project data is stored there by the application defaults. Database-backed cache/session rows are in the database dump. |
| `storage/framework/locks/export-rendering` | **Ephemeral.** Do not restore active lock state. | `flock` files are coordination artifacts and are recreated as needed. Never remove active production lock files during routine operation. |
| Renderer profiles under the system temp directory | **Ephemeral.** Do not back them up. | Firefox/geckodriver profiles are request-scoped and removed after rendering; they are not user documents. |
| `storage/app` | **No essential user files currently.** | Inspection found no application upload or permanent generated-export workflow. Reassess if that changes. |
| `GenerationAttempt` rows | Included in a full database backup; **operational/idempotency state**, not durable history. | They can be restored as-is; normal pruning can resume after recovery. `GeneratedContentVersion` is the durable immutable history. |

A full database backup also contains authentication data such as password
hashes and reset tokens, encrypted API credential values, generated text,
Project Context, captured references, and possibly cache/session state. Treat
it as sensitive even though API credential columns are encrypted.

## `APP_KEY` and encrypted credentials

`OpenAiCredential::$api_key` uses Laravel's Eloquent `encrypted` cast. The
account settings action assigns the submitted personal key to that cast;
`OpenAiCredentialResolver` reads the cast value before provider use. Laravel
uses the configured AES-256-CBC encrypter and `APP_KEY` to encrypt and decrypt
it.

A database backup alone is not enough to restore personal credentials. If the
database is restored with a different or newly generated `APP_KEY`, Laravel
cannot decrypt ciphertext created with the original key. By default this
application has no previous encryption key configured. If an operator
intentionally uses Laravel's `APP_PREVIOUS_KEYS` for a key rotation, preserve
that configuration and its old keys too.

**Restore the original `APP_KEY` with the database.** `php artisan
key:generate` is for a new installation. Never run it to “repair” an existing
restored database: it replaces the key and can strand every existing
credential ciphertext. Do not print, commit, or store the key alongside an
unprotected dump.

Database passwords, mail transport credentials, and other secret values in
`.env` are also sensitive and must be securely restored or re-provisioned.
They can generally be rotated/reconfigured after recovery; they do not decrypt
the database's OpenAI credentials. `APP_URL`, logging level/channel, and
renderer executables/configuration are replaceable deployment settings. This
renderer currently has no binary-path environment override: provision its
standalone binaries at the paths described in [Deployment](deployment.md).

## Backup cadence and protection

Choose frequency from the service's acceptable data-loss window (RPO), and
choose restore time targets (RTO) appropriate to the deployment. Keep multiple
backup generations, retain at least one protected off-host copy, and regularly
test restores. A successful dump command alone does not prove recoverability.

Record a small operator-side backup note alongside each backup containing:
UTC backup time, application tag/commit, MySQL version, database name, and
`php artisan migrate:status` output or equivalent migration-state record. Do
not include secret values in this note.

Use a dedicated least-privilege backup account and secure MySQL client option
file or equivalent secret mechanism; do not put a database password in shell
arguments or shell history. Restrict dump-file access, encrypt backups at rest
and in transit according to the operator's security policy, protect off-host
copies, and securely expire old copies. A dump contains private user and
fictional project data.

## Logical MySQL backup

The following example backs up the production database's schema and data. The
client option file must be outside source control, readable only by the backup
operator, and contain the appropriate `[client]` connection fields. The
`--defaults-extra-file` option is intentionally first; it keeps the password
out of the process arguments.

```sh
umask 077
stamp=$(date -u +%Y%m%dT%H%M%SZ)
set -o pipefail
mysqldump --defaults-extra-file=/secure/path/fictional-internet-backup.cnf \
  --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 \
  fictional_internet | gzip > "/secure/backup/path/fictional-internet-${stamp}.sql.gz"
```

`--single-transaction` provides a consistent snapshot for InnoDB tables. Do
not run schema migrations concurrently with the dump. If the database uses
non-transactional tables or your MySQL setup requires additional options or
privileges, coordinate a consistent backup method with the database operator.
`--no-tablespaces` avoids requiring the MySQL `PROCESS` privilege solely to
read tablespace metadata; confirm the option is supported by the installed
MySQL client.
The dump is compressed, not encrypted by this command; protect/encrypt the
result before storing or transferring it. Do not run this command against
`fictional_internet_test` as a production backup or against an unrelated
server/database by mistake.

## Restore into a recovery database

First restore to a separate, empty recovery database. Do not overwrite the
live production database as an exploratory restore. Provision the recovery
database with `utf8mb4` / `utf8mb4_unicode_ci` and a suitable application
account before importing.

**Restore the backup to the same application release that created it first.**
Validate that recovery before attempting an upgrade. This separates data
recovery problems from code/schema changes and makes failures easier to
identify.

1. Obtain the exact source release/artifact and its `composer.lock` and
   `package-lock.json`.
2. Restore the deployment configuration, including the original `APP_KEY`
   (and any configured `APP_PREVIOUS_KEYS`), into a protected environment
   file/secret store. Configure credentials for the recovery database, not
   production.
3. Confirm the target is the empty recovery database. Use a fresh database
   name and verify it independently before importing.
4. Import the dump. This example assumes the dump command above omitted
   `--databases`, so the target database name is selected by the `mysql`
   client:

   ```sh
   set -o pipefail
   gzip -dc /secure/backup/path/fictional-internet-YYYYMMDDTHHMMSSZ.sql.gz \
     | mysql --defaults-extra-file=/secure/path/fictional-internet-recovery.cnf \
       fictional_internet_recovery
   ```

   The recovery client option file should point to the recovery server/account.
   Review the target database and dump identity before running the import; do
   not point this command at the live database casually.
5. Install the matching release dependencies and assets as described in
   [Deployment](deployment.md). Check connectivity and migration state with
   `php artisan migrate:status`. Do not run `migrate:fresh`, `migrate:refresh`,
   or `db:wipe` during recovery.
6. For a same-release restore, do not replay migrations already recorded in
   the restored `migrations` table. If the desired application release is
   newer, run only its forward migrations with `php artisan migrate --force`
   after the restored same-release app has been verified and a backup of the
   recovery database has been taken.
7. Clear/rebuild configuration, route, and view caches using the deployment
   guide. Run `php artisan cache:clear` to discard restored database cache and
   rate-limit entries; they are reproducible. Choose explicitly whether to
   retain database-backed sessions. Retaining them may preserve some
   pre-backup logins; clearing all rows from `sessions` will log every user
   out. If clearing, do it only after verifying that the application is
   connected to the recovery database, and record that consequence.

   To deliberately invalidate all database sessions on the recovery database:

   ```sh
   mysql --defaults-extra-file=/secure/path/fictional-internet-recovery.cnf \
     fictional_internet_recovery -e 'DELETE FROM sessions;'
   ```
8. Verify login, project access, content/version rendering, project JSON
   export, and decryption of an existing credential before allowing users back.
   A credential check must never print the decrypted value or make a provider
   call; see the isolated verification pattern below.
9. Resume normal operations only after application and data checks pass.
   GenerationAttempt rows restored from backup may then be pruned using the
   normal command. Run its `--dry-run` first; do not prune before restore
   validation.

An operator can verify that a known user's stored credential decrypts without
printing it or contacting OpenAI by invoking the existing resolver from a
restricted CLI session. Substitute an account identifier known to the
operator:

```sh
php artisan tinker --execute '$user = App\Models\User::where("email", "operator@example.invalid")->firstOrFail(); $credential = app(App\Services\OpenAI\OpenAiCredentialResolver::class)->forUser($user); $ok = is_string($credential) && $credential !== ""; unset($credential); echo $ok ? "Credential decryption succeeded" : "Credential decryption failed";'
```

Do not enable shell tracing or log the command's process environment. This
check tests decryptability only; it does not validate the key with OpenAI.

## Sessions and caches during recovery

The default production session and cache drivers are database-backed. The
full database dump includes `sessions`, `cache`, and `cache_locks` rows.
After recovery, clearing the cache removes stale cache/rate-limit state and
Laravel can repopulate it. Session handling is a separate decision: retaining
the restored rows may preserve logins that existed at backup time; clearing
the session table deliberately invalidates all sessions. Neither cache nor
sessions are durable project history.

Filesystem cache/session files are not configured by default. If an operator
changes to file-backed drivers, reassess those directories and their recovery
policy. Compiled Blade views and framework/bootstrap caches are always
reproducible and should be rebuilt, not treated as project data.

## Upgrade an existing installation

Before every release upgrade:

1. Read release notes and migration notes. Record the current release and
   migration state.
2. Create a fresh database backup and preserve the matching `APP_KEY` and
   deployment configuration. Verify the backup file and its off-host copy.
3. Prefer a brief maintenance window for this single-server 1.0 deployment.
   The application uses Laravel's file maintenance driver by default; verify
   the maintenance file is available to the serving release. Use the normal
   `php artisan down` and `php artisan up` commands; do not leave the app in
   maintenance mode if an upgrade step fails without recording that state.
   With versioned release directories, share the configured storage path so
   the maintenance marker remains visible after switching releases.
4. Deploy the new application source/release, retaining its lockfiles. Install
   locked dependencies and build assets:

   ```sh
   composer install --no-dev --optimize-autoloader --no-interaction
   npm ci
   npm run build
   ```

5. Apply only forward migrations:

   ```sh
   php artisan migrate --force
   ```

6. Clear and rebuild Laravel configuration, route, and view caches as
   documented in [Deployment](deployment.md). If PHP-FPM uses OPcache or a
   persistent worker model, reload/restart the application workers using the
   host's normal controlled procedure so they load the new release.
7. Leave maintenance mode, then smoke-test application pages, authentication,
   project access, versions, a controlled BYOK generation, and exports. A
   generation acceptance check can incur OpenAI charges; it is an operator
   check, not part of automated verification.

If a migration or deployment step fails, keep user traffic paused while the
failure is assessed. Do not blindly mark maintenance mode up against a
partially migrated database; follow the rollback/recovery procedure below.

A brief maintenance period is acceptable; this application makes no
zero-downtime deployment guarantee. Do not run `migrate:fresh`,
`migrate:refresh`, or `db:wipe` on a production database.

## Rollback limitations and migration review

Do not treat `php artisan migrate:rollback` as a complete recovery strategy.
Some migration `down()` methods intentionally remove data-bearing schema:
rolling back the `based_on_version_id` migration loses branch lineage;
rolling back the UUID migrations removes public identifiers, and reapplying
them generates new UUIDs that invalidate existing public links; rolling back
the continuation-binding migration removes attempt source/result references.
Creation-migration rollbacks drop their tables. Restoring a pre-upgrade
production backup and the matching old application release is the reliable
rollback path.

Current forward migrations are additive apart from backfilling UUIDs for
existing projects/artifacts in batches of 100 before enforcing non-null and
unique constraints. The continuation-binding migration adds nullable foreign
keys. No current `up()` migration deletes generated content or immutable
versions. UUID backfills and schema alteration can scan/lock tables, so take a
backup and use a maintenance window if upgrading a deployment with material
existing data. No migration is designed as a large-dataset online migration;
review future migration notes and test against a production-like copy.

## Isolated recovery verification

Before 1.0 launch, exercise the recovery process using a disposable database
and a clearly fake OpenAI credential. Verify that a database copy restored
under the same temporary `APP_KEY` can decrypt the value through the model
cast/resolver, and that a different temporary key fails to decrypt it. Never
use a real OpenAI key, production `APP_KEY`, or the normal development
`fictional_internet` database for this exercise.
