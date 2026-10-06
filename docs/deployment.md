# Production deployment

This guide describes a single-server nginx/PHP-FPM deployment with MySQL.
Use the [installation guide](installation.md) to provision dependencies,
secrets, and the database. TLS termination and certificates are operator-
specific; serve the application over HTTPS in production.

## Production environment

Use a secret store or a carefully permissioned, untracked `.env`. Set
`APP_ENV=production`, `APP_DEBUG=false`, the externally reachable HTTPS
`APP_URL`, MySQL connection settings, production logging, mail delivery, and a
persistent `APP_KEY`. Never commit `.env`, API keys, database passwords, or
`APP_KEY`.

The application does not use a global OpenAI API key. Users configure their
own credential. `OPENAI_MODEL` and `OPENAI_TIMEOUT` are optional settings in
`config/services.php`.

## Database, sessions, and cache

The production MySQL connection uses `utf8mb4`/`utf8mb4_unicode_ci` and strict
mode. The migrations create the `sessions`, `cache`, and `cache_locks` tables.
The configured default session driver is `database`; the default cache store
is also `database`. Consequently, PHP-FPM workers sharing this MySQL database
share login sessions and rate-limit state; sticky sessions and Redis are not
required for this deployment shape. Database cache is suitable when all
workers use the same healthy database. Do not configure an in-process `array`
cache in production: it would give workers inconsistent per-user generation
limits.

The shared generation limiter allows ten provider-capable POST submissions
per authenticated user per minute across ordinary generation and
continuation. A user receives a safe 429 response and `Retry-After` when over
limit. The throttle conservatively counts invalid submissions and completed
idempotent replays too. See [operational safety](operational-safety.md).

The application currently has no queued generation jobs and requires no queue
worker. It also has no registered Laravel scheduled tasks. GenerationAttempt
cleanup is an explicit command; until a scheduler is introduced, invoke it
from a systemd timer or cron as described below.

## Attempt cleanup

Run a dry run before enabling the maintenance schedule:

```sh
php artisan generation-attempts:prune --dry-run
```

Then run the command daily. Example crontab entry (adjust paths, PHP binary,
service user, and logging to the host):

```cron
17 3 * * * cd /srv/fictional-internet/current && /usr/bin/php artisan generation-attempts:prune >> /var/log/fictional-internet-attempt-prune.log 2>&1
```

The command prunes only GenerationAttempt operational rows: issued after 1
day, failed after 7 days, completed after 30 days, and in-progress after 1 day
from `claimed_at`. An in-progress row with no claim timestamp is retained. It
never removes generated artifacts or immutable versions. Aggregate counts are
safe to retain; tokens, prompts, content, credentials, and failure diagnostics
are not printed.

## PHP-FPM and execution timeouts

Use PHP-FPM for the production web runtime and make its PHP version/extensions
match CLI. PDF/PNG rendering has a Symfony Process timeout of 75 seconds and a
Node worker watchdog of 45 seconds. Set PHP `max_execution_time` to at least
90 seconds so PHP does not routinely terminate the request before Symfony
Process reports its bounded result. If the pool uses `request_terminate_timeout`,
set it above 90 seconds (for example 100 seconds); configure nginx's
`fastcgi_read_timeout` with additional margin (for example 120 seconds).
These are deployment recommendations, not application code changes. The
renderer has bounded browser process-group cleanup; it does not use a daemon or
external supervisor.

## Filesystem ownership and permissions

Keep application code, `vendor/`, and built assets readable but not writable
by PHP-FPM. PHP-FPM needs write access only to Laravel runtime locations used
by the app:

- `storage/logs` for application logs;
- `storage/framework/cache/data` if file cache is selected;
- `storage/framework/sessions` if file sessions are selected;
- `storage/framework/views` for compiled Blade views;
- `storage/framework/locks/export-rendering` for the two PDF/PNG flock slots;
- `bootstrap/cache` for framework-generated caches.

Database cache/session are the defaults, but Laravel still needs writable
compiled views, logs, bootstrap cache, and export lock directory. The renderer
uses the system temporary directory (normally `/tmp`) for private browser
profiles and temporary files. Ensure the PHP-FPM user can create and remove
its own temporary directories there and can execute the browser binaries.
Executable access is separate from write permission.

Grant write permission to the PHP-FPM user or a narrowly scoped shared group;
use restrictive group-write modes (the lock directory is created with mode
`0770`, lock files with `0660`). Do not make the repository world-writable.
For example, after choosing the correct deployment owner and FPM group:

```sh
install -d -o deploy -g www-data -m 2770 storage/framework/locks/export-rendering
chgrp -R www-data storage/logs storage/framework/views bootstrap/cache
chmod -R g+rwX storage/logs storage/framework/views bootstrap/cache
```

Apply corresponding ownership/modes to any runtime cache/session directories
if file-backed drivers are deliberately used. Do not use `chmod -R 777`.

## PDF/PNG renderer runtime

The renderer starts a Node worker through Symfony Process, then starts
geckodriver and a headless Firefox session using WebDriver BiDi. It sends the
standalone document to the worker on standard input, blocks browser network
access except local loopback, captures the complete document, terminates the
browser process group, and removes the temporary profile. It never requires
OpenAI credentials. PDF pagination is handled by the exported document CSS;
PNG is a single full-document image and is rejected with a safe limitation
message when it exceeds the renderer's 12,000-pixel height or 8-million-pixel
area limit.

The current renderer discovers binaries as follows:

- `node`: `/usr/local/bin/node`, `/usr/bin/node`, then `/bin/node`;
- `geckodriver`: `/usr/local/bin/geckodriver`, `/usr/bin/geckodriver`, then
  `/bin/geckodriver`;
- Firefox: `/usr/local/bin/firefox` only.

The resolved Firefox and geckodriver binaries must be standalone executables;
Snap paths are rejected. There is no `.env` path override. Install a tested
Node.js 24 runtime with native `globalThis.WebSocket`, standalone Mozilla
Firefox, and standalone geckodriver at the supported paths. For example, a
standalone Firefox installation may live under `/opt/firefox` with an
operator-managed `/usr/local/bin/firefox` symlink to its executable. Do not
run Firefox/geckodriver as root. Confirm all executables can run as the
PHP-FPM service account. NVM-only paths are not searched.

The renderer's subprocess environment is sanitized. The Node process gets a
short explicit `PATH` and system temporary directory. It creates a unique
temporary profile root; geckodriver and Firefox use that root as `HOME`, and
Firefox is passed by its resolved absolute path. Snap is not a fallback. On
missing dependencies the export page reports that rendering is unavailable;
other runtime failures show a generic safe rendering error. Check sanitized
server logs for `failure_stage`, `format`, and `exit_code` categories—never
turn on raw subprocess output logging.

## nginx example

Point nginx's document root to the deployment's `public/` directory, never the
repository root. Replace the server name, release path, and PHP-FPM socket for
the host. This is an HTTP virtual host; configure TLS separately before
production traffic.

```nginx
server {
    listen 80;
    server_name fictional.example;
    root /srv/fictional-internet/current/public;
    index index.php;
    charset utf-8;
    client_max_body_size 1m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ^~ /build/assets/ {
        try_files $uri =404;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\. {
        deny all;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

Because only `public/` is exposed, `.env`, `vendor`, application source,
`storage`, and `bootstrap/cache` are outside the web root. The dotfile denial
is defense in depth. Do not add a public symlink to private storage unless a
specific, reviewed feature requires it.

## Production asset and Laravel caches

Install exactly the locked dependencies and build the hashed Vite assets:

```sh
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
```

The build writes the Vite manifest and compiled assets under `public/build/`.
Node/npm can be omitted from the web host only if assets were built in the
same release artifact elsewhere. The runtime PDF/PNG Node binary remains
required on the app server regardless.

After environment values and database connectivity are correct, apply
forward-only migrations and build caches:

```sh
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

These three cache commands were verified against this application. On a
release that changes environment/configuration or route/view code, clear and
rebuild the relevant bootstrap caches:

```sh
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`php artisan optimize:clear` is also available to clear Laravel optimization
caches. Do not use destructive migration commands to clear application
caches.

## Password-reset email

Working Laravel mail transport is required for forgot-password delivery.
Configure the chosen `MAIL_MAILER`, transport host/port and authentication,
security scheme, and sender name/address. Registration and sign-in themselves
do not send mail. Test a real password reset in a controlled pre-launch
mailbox; email verification is not enabled. Never place mail passwords in
source control.

## Post-deployment checklist

Use a test account/project and controlled provider account. Provider and mail
checks below may incur charges or send email; they are operator acceptance
steps, not part of automated deployment scripts.

- [ ] Application loads over HTTPS with `APP_DEBUG=false`.
- [ ] Registration, login, logout, Account settings, and password-reset email
      work.
- [ ] Create a project and save Project Context.
- [ ] Configure a user's personal OpenAI key; confirm generation is blocked
      safely without a key and uses BYOK when configured.
- [ ] Generate a News Article and a discussion; edit a version; continue a
      discussion from a selected historical version.
- [ ] Select an exact historical reference and confirm the resulting snapshot.
- [ ] Export the project JSON and verify that it contains project data but no
      account or credential data.
- [ ] Export a document as HTML and PDF; export a short document as PNG.
- [ ] Verify long-document PDF pagination and the safe PNG size limitation.
- [ ] Confirm non-owners cannot access another project or its exports.
- [ ] Confirm generation POSTs share the documented per-user rate limit.
- [ ] Run `php artisan generation-attempts:prune --dry-run`, review counts,
      then verify the chosen daily maintenance invocation.
- [ ] Inspect sanitized renderer failure categories if PDF/PNG setup fails;
      do not enable raw process output logging.
