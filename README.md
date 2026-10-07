# Fictional Internet

Fictional Internet is a Laravel application for creating fictional online
content for stories and settings. Users can organize projects, maintain
reusable Project Context, generate structured documents with their own OpenAI
API key, edit immutable versions, continue discussion threads, and export
documents or complete project archives.

## Capabilities

- Registered content types: News Article, Forum Thread, and SchreckNet Thread.
- Immutable, numbered versions with source lineage and captured generation
  context.
- Optional references to exact historical versions in the same project.
- Per-document PDF and full-document PNG export.
- Project-level JSON export format version 1 for portability. Import/restore
  is not currently provided.
- Per-user OpenAI credentials (BYOK). Credentials are encrypted with Laravel's
  `APP_KEY`; no shared server API-key fallback is used.

## Development setup

Requirements and detailed commands are in [Installation](docs/installation.md).
In short, install the locked PHP and Node dependencies, configure a local
environment, run migrations, and build the frontend:

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
```

Then serve the application with Laravel's local development server or the
project's Composer development script. The `.env.example` values are for local
development, not production.

## Architecture and runtime

Content types are registered through `ContentTypeRegistry` and provide their
own schemas, instructions, validation, and presentation. Shared actions handle
generation, references, immutable version persistence, continuation, BYOK
resolution, and export. Production uses MySQL; the default automated test
suite uses in-memory SQLite. PDF/PNG export has separate runtime requirements
for Node.js, standalone Firefox, and geckodriver.

## Tests and operations

```sh
php artisan test
vendor/bin/pint --dirty --format agent
node node_modules/vite/bin/vite.js build
```

The guarded MySQL test profile and renderer smoke instructions are in the
[Release test matrix](docs/release-test-matrix.md). Production setup, nginx,
PHP-FPM, mail, renderer binaries, and verification are covered by
[Installation](docs/installation.md) and [Deployment](docs/deployment.md).
Generation rate limits and attempt cleanup are described in
[Operational safety](docs/operational-safety.md), and recovery/upgrade
procedures are in [Backup, restore, and upgrade](docs/backup-restore-upgrade.md).

## Security and project data

Each user must configure a personal OpenAI API key before generating content.
Do not commit API keys, `APP_KEY`, or the `.env` file to source control. OpenAI
API keys are configured per user and must not be stored as a global application
credential in `.env`.
Project JSON archives contain project-owned context, artifacts, versions,
and captured provenance; they intentionally exclude credentials and
account/authentication data. See [Project export format v1](docs/project-export-format-v1.md).

## License

Fictional Internet is licensed under the GNU Affero General Public License
version 3.0 only ([AGPL-3.0-only](LICENSE)). AGPL is a strong copyleft license
that includes conditions for modified versions used over a network. See the
license text for the complete terms.

Copyright © 2026 github.com/mahriman. The canonical source repository is
[github.com/mahriman/fictional-internet](https://github.com/mahriman/fictional-internet).
