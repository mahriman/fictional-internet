# Installation

This guide covers a fresh Fictional Internet installation. For a production
server, continue with [Deployment](deployment.md). The repository's
[Release test matrix](release-test-matrix.md) describes test-only database
safety. Never point destructive test commands at a production database.

## Runtime requirements

### Required for the web application

- PHP **8.3 or newer**. The dependency baseline is PHP `^8.3`; this release was
  tested on PHP 8.5.10. Use the same PHP version and enabled extensions for CLI
  Artisan commands and PHP-FPM.
- Composer 2.2 or newer (the lockfile was verified with Composer 2.10.3).
- MySQL 8.0; the release baseline was verified with MySQL 8.0.46. Other MySQL
  major versions and MariaDB are not the production release-test target.
- A web server and PHP-FPM (the deployment example uses nginx).
- A writable Laravel runtime filesystem as described in
  [Deployment: permissions](deployment.md#filesystem-ownership-and-permissions).
- Outbound HTTPS access from PHP to the OpenAI Responses API for generation.
  The application itself does not require a server-side API key.

Composer's production platform checks require PHP and the extensions
`dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `openssl`,
`pcre`, `session`, and `tokenizer`. Laravel's Composer dependencies provide
polyfills for `ctype` and `mbstring` where needed; installing the native
extensions is preferable. MySQL deployments also need PDO and `pdo_mysql`.
The export subprocess uses PHP `proc_open` through Symfony Process; ensure that
function is not disabled. PHP cURL is recommended for outbound HTTP. SQLite
development/tests additionally need PDO and `pdo_sqlite`.

Ubuntu/Debian package names depend on the PHP release and repository. A typical
set is `php8.x-fpm`, `php8.x-cli`, `php8.x-mysql`, `php8.x-sqlite3`,
`php8.x-curl`, `php8.x-mbstring`, `php8.x-xml`, and `php8.x-zip`; check the
actual package names and enabled modules for the chosen distribution. The app
needs the production PDO MySQL driver; SQLite support is only needed for local
and test use.

### Build-time requirements

- Node.js `^20.19.0` or `>=22.12.0` for the locked Vite 8 toolchain; this
  repository was tested with Node 24.21.0 and npm 11.19.0.
- npm capable of running `npm ci` from `package-lock.json`.

Node/npm are needed to build Vite assets. After assets are built, they are
served as static files and are not needed by PHP for ordinary page rendering.
Node is separately required at runtime for PDF/PNG export; see below.

### Required only for PDF/PNG export

- Node.js with `globalThis.WebSocket` available; Node 24.21.0 is the tested
  runtime.
- A standalone Mozilla Firefox binary.
- Standalone geckodriver.
- Permission for the PHP-FPM user to execute these binaries and create/remove
  temporary browser profiles under the system temporary directory.

HTML export does not start a browser. PDF/PNG export has two server-wide
concurrent rendering slots by default. See [Deployment: PDF/PNG renderer](deployment.md#pdfpng-renderer-runtime).

### Development/test-only

- SQLite with PDO SQLite for the default in-memory Pest suite.
- Pest and other development Composer dependencies installed by ordinary
  `composer install` (omit `--no-dev` for development).
- The isolated MySQL database `fictional_internet_test` for the guarded
  release profile. It is never the production database.
- A real browser toolchain only when running the renderer integration smoke;
  the test reports an intentional skip when its required standalone binaries
  are absent.

## Fresh local development install

From the repository root:

```sh
composer install
cp .env.example .env
```

Set `DB_CONNECTION=sqlite` in `.env`, then create and migrate the local
SQLite database:

```sh
touch database/database.sqlite
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan test
```

For Vite hot reload, use `npm run dev` in a separate terminal. Do not reuse
the local SQLite settings as a production database configuration.

## Fresh MySQL application install

Create an empty database with UTF-8 support and a dedicated application user.
For example, using an appropriately privileged MySQL administrator:

```sql
CREATE DATABASE fictional_internet
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER 'fictional_internet_app'@'localhost' IDENTIFIED BY 'set-a-secret-outside-source-control';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON fictional_internet.* TO 'fictional_internet_app'@'localhost';
```

The migration account needs schema-creation/alteration and data privileges.
A deployment may use a separate, more restricted runtime account after
migrations, provided it can read/write application, cache, and session data.
Do not use root credentials for the application.

Configure the environment (use the real secret values only in the deployment's
secret store or untracked `.env`):

```dotenv
APP_NAME="Fictional Internet"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fictional.example

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=fictional_internet
DB_USERNAME=fictional_internet_app
DB_PASSWORD=<managed-secret>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Keep the **production** database name separate from
`fictional_internet_test`, which is reserved for the guarded test profile.

For an initial deployment, install PHP dependencies, create/provision the
environment and a persistent `APP_KEY`, build frontend assets, then apply
migrations:

```sh
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
```

Do not run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe` on
production. Upgrades use the new forward migrations only:

```sh
php artisan down   # optional maintenance window
php artisan migrate --force
php artisan up
```

Take the normal production backup before an upgrade according to your
operations policy; backup/restore procedures are outside this installation
guide.

## Environment and secrets

`.env.example` is a **local-development template**. For production, set at
least `APP_ENV=production`, `APP_DEBUG=false`, a public HTTPS `APP_URL`, MySQL
connection details, log policy, mail transport, and a persistent application
key. Keep `.env` outside source control and restrict its access.

Generate a key once on a new installation with `php artisan key:generate`, or
provision a secure random Laravel key through the deployment secret manager.
`APP_KEY` is persistent deployment state: Laravel uses it for encrypted
application data, including each user's encrypted OpenAI API credential.
Losing or changing it can make stored credentials unreadable. Do not generate
a replacement as part of routine releases.

The application has no `OPENAI_API_KEY` server fallback. `OPENAI_MODEL` and
`OPENAI_TIMEOUT` configure the provider request, but are optional and have
code defaults. Each user adds their own personal key in Account settings; a
missing or undecryptable personal key prevents generation. Export, viewing,
and manual editing do not require an OpenAI key.

Password reset uses Laravel's configured password broker and notification
system. Registration/login do not require mail, but forgot-password delivery
does. Configure `MAIL_MAILER`, the selected transport's `MAIL_HOST`/`MAIL_PORT`
and authentication/security settings, and a valid `MAIL_FROM_ADDRESS` and
`MAIL_FROM_NAME`. Test password-reset delivery before launch. Email
verification is not enabled.
