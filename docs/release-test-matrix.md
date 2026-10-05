# Release test matrix

This document records the repository's supported local release checks. The
default test suite remains fast and portable; the MySQL profile adds coverage
for production-database behavior.

## Default test suite

The default PHPUnit environment in `phpunit.xml` uses an in-memory SQLite
database, array cache/mail/session drivers, and synchronous queues. Run:

```sh
php artisan test
```

This is the complete application regression suite. It makes no live OpenAI
requests or real email deliveries.

## MySQL integration profile

The MySQL release profile runs selected tests covering migrations, foreign
keys and deletion cascades, version allocation/branching, continuation,
attempt idempotency, JSON content, project export, and project-local search and
filter behavior. The two continuation provider/UI suites run in separate Pest
processes after the transaction-wrapped profile. They refresh schema between
cases because the continuation backend rejects provider calls inside database
transactions.

Run it from the repository root:

```sh
bash scripts/test-mysql.sh
```

The script fixes the target to `fictional_internet_test`, clears `DB_URL`,
checks `SELECT DATABASE()` before starting Pest, and refuses an explicitly
configured different `DB_DATABASE`. The Laravel feature-test bootstrap repeats
the check against the active MySQL connection before tests can invoke
`RefreshDatabase`. A marker also makes the harness fail if PHPUnit silently
selects SQLite instead. The first `RefreshDatabase` test performs a clean
schema migration inside the already existing test database; the unwrapped
continuation suites refresh that schema between cases. The script never creates
or drops a database.

**Never point this or any destructive test workflow at the normal
`fictional_internet` database.** The MySQL guard accepts only
`fictional_internet_test`.

## Firefox renderer integration

The renderer integration suite includes worker lifecycle tests and a real
Firefox PDF/PNG smoke test. It requires:

- Node.js with native `globalThis.WebSocket` support (the tested runtime is
  Node.js 24.21.0);
- standalone Firefox and geckodriver executables (Snap paths are rejected);
- writable temporary profile storage and permission to launch/terminate the
  browser process group.

The real-browser case is skipped if the standalone executables are
unavailable. One diagnostic test is also intentionally skipped when Node
already exposes native `globalThis.WebSocket`; that test exercises only the
unsupported-runtime failure path. Run just the renderer suite with:

```sh
php artisan test tests/Feature/ContentExportRendererIntegrationTest.php
```

The PHP Symfony Process timeout is 75 seconds and the Node worker watchdog is
45 seconds; WebDriver session creation, BiDi connection, and protocol
operations also have shorter request/command bounds. Browser shutdown and
process-group cleanup have their own bounded cleanup deadline. The tests
validate temporary profile cleanup and categorized worker failures using disposable fake WebDriver
processes. They do not access the normal application database or OpenAI.

## Frontend production build

```sh
node node_modules/vite/bin/vite.js build
```

The optional `fontaine` optimization warning can appear when that optional
package is not installed; it does not prevent the production bundle from
building.

## Style and static checks

```sh
vendor/bin/pint --dirty --format agent
git diff --check
composer validate --no-check-publish
npm pkg get scripts
```

## Tested runtime baseline

The current repository/environment baseline is:

- PHP `^8.3` (tested here with PHP 8.5.10);
- Laravel Framework `^13.17` (installed 13.34.0);
- Composer 2.10.3;
- Node.js 24.21.0 and npm 11.19.0;
- MySQL 8.0.46;
- standalone Firefox 157.0 and geckodriver 0.37.1 for the browser smoke.

The renderer requires a Node runtime exposing native `globalThis.WebSocket`.
It explicitly selects `/usr/local/bin/firefox` and
`/usr/local/bin/geckodriver` when present and rejects Snap paths.

Run the complete default suite and MySQL profile before release. Do not use
`migrate:fresh`, `migrate:refresh`, or `db:wipe` against
`fictional_internet`.
