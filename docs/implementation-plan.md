# First domain-model milestone: implementation plan

This plan follows the existing Laravel 13 and Pest conventions: migrations define schema, Eloquent models expose typed relationships and casts, and factories supply related test records. Boost’s Laravel 13 documentation supports the proposed relationship, factory, JSON cast, and database-test approaches. No files were changed, and no migrations, tests, or package installations were run.

## Proposed database schema

### `projects`

| Column | Type | Notes |
|---|---|---|
| `id` | big integer, primary key | Laravel default |
| `user_id` | foreign ID | References `users.id`; indexed |
| `name` | string(255) | Required |
| `description` | text, nullable | Optional project description |
| `created_at`, `updated_at` | timestamps | Laravel defaults |

**Foreign key:** `user_id → users.id`, cascade on delete. No setting-specific fields or reusable context fields yet; those can be added when project context is designed.

### `generated_contents`

| Column | Type | Notes |
|---|---|---|
| `id` | big integer, primary key | Laravel default |
| `project_id` | foreign ID | References `projects.id`; indexed |
| `content_type` | string(100) | Stable code key, such as `news_article` |
| `title` | string(255), nullable | Optional display label |
| `created_at`, `updated_at` | timestamps | Laravel defaults |

**Foreign key:** `project_id → projects.id`, cascade on delete.

**Index:** composite `(project_id, content_type)` for listing a project’s content by type. Do not make this unique; a project can contain multiple pieces of the same type.

The content type is deliberately a string instead of a database foreign key: the registry is code-backed in this milestone.

### `generated_content_versions`

| Column | Type | Notes |
|---|---|---|
| `id` | big integer, primary key | Laravel default |
| `generated_content_id` | foreign ID | References `generated_contents.id`; indexed |
| `version_number` | unsigned integer | Starts at 1 for each content item |
| `origin` | string(32) | `ai_generated` or `user_edited` |
| `content` | JSON | Structured content |
| `context_snapshot` | JSON, nullable | User and project context used for this version |
| `generation_metadata` | JSON, nullable | Reproducibility and inspection data |
| `created_at` | timestamp | Creation time; omit `updated_at` to signal version immutability |

**Foreign key:** `generated_content_id → generated_contents.id`, cascade on delete.

**Unique index:** `(generated_content_id, version_number)`. This prevents duplicate version numbers per content item and supports ordered version history without another index.

`generation_metadata` can later hold provider, model, prompt/schema revision, non-secret request settings, provider response ID, and usage data. It should never contain credentials.

## Proposed models and relationships

- `User`
  - `projects(): HasMany`

- `Project`
  - `user(): BelongsTo`
  - `generatedContents(): HasMany`

- `GeneratedContent`
  - `project(): BelongsTo`
  - `versions(): HasMany`
  - `contentType` stores the stable registry key.

- `GeneratedContentVersion`
  - `generatedContent(): BelongsTo`
  - `origin` casts to a backed enum.
  - `version_number` casts to integer.
  - `content`, `context_snapshot`, and `generation_metadata` cast to arrays.

For `origin`, use an enum such as `GeneratedContentVersionOrigin` with cases `AiGenerated` and `UserEdited`, backed by `ai_generated` and `user_edited`. This keeps the persisted values clear while avoiding a database enum with less flexibility across supported database drivers.

I recommend **not adding a current-version pointer in this milestone**. The current version can be determined by the highest version number until a feature requires selecting an older version as current. A pointer would introduce an additional foreign key and a same-content consistency concern; add it with that selection behavior.

## Content type registry structure

Keep the registry independent of generation services and models:

- `App\ContentTypes\Contracts\ContentTypeDefinition`
  - Defines a stable key, label, prompt instructions, structured-output schema, validation rules, and presentation strategy.
- `App\ContentTypes\ContentTypeRegistry`
  - Looks up definitions by key and lists registered definitions.
  - Rejects duplicate keys and clearly handles unknown keys.
- `App\ContentTypes\Definitions\...`
  - One class per content type, implementing the definition contract.
- `App\ContentTypes\Contracts\ContentPresenter`
  - Optional boundary for type-specific presentation. Add concrete presenters when a UI/rendering use case exists.

A registry lookup should select the type-specific definition; the shared generation workflow should consume that definition and must not contain `if`/`switch` branches for particular content types. Keep the registry code-backed, not stored in the database. The registry can initially contain one example definition to prove the contract, without implementing any OpenAI calls.

## Factories

Add conventional factories for:

- `ProjectFactory`, defaulting `user_id` to `User::factory()`.
- `GeneratedContentFactory`, defaulting `project_id` to `Project::factory()` and using a registered stable content key.
- `GeneratedContentVersionFactory`, defaulting `generated_content_id` to `GeneratedContent::factory()`, with structured content, a valid origin, and version number 1.

If factory states help tests read clearly, add named states for AI-generated and user-edited versions. Avoid making the factory responsible for calculating version numbers; version numbering belongs to the later creation workflow, where it can be coordinated safely.

## Tests to add

Use Pest feature tests with database isolation, following the existing Pest style and Laravel’s `RefreshDatabase` guidance.

- `ProjectTest`: a project belongs to its user; a user can retrieve their projects.
- `GeneratedContentTest`: content belongs to its project; a project can retrieve its content; the type key persists.
- `GeneratedContentVersionTest`: a content item has many versions; versions belong to content; structured JSON casts round-trip; both origin values cast correctly; distinct versions persist without overwriting.
- Cascade behavior: deleting a user removes owned projects, content, and versions through the configured foreign keys.
- `ContentTypeRegistryTest`: registered keys resolve to their definitions, unknown keys are handled as specified, and duplicate keys are rejected.

These tests should verify this application’s relationships, casts, registry behavior, and persistence rules—not Laravel’s framework behavior in isolation. No generation or HTTP-provider tests belong in this milestone.

## Suggested file structure

```text
app/
  ContentTypes/
    ContentTypeRegistry.php
    Contracts/
      ContentTypeDefinition.php
      ContentPresenter.php
    Definitions/
      ExampleContentType.php
  Enums/
    GeneratedContentVersionOrigin.php
  Models/
    Project.php
    GeneratedContent.php
    GeneratedContentVersion.php

database/
  factories/
    ProjectFactory.php
    GeneratedContentFactory.php
    GeneratedContentVersionFactory.php
  migrations/
    ..._create_projects_table.php
    ..._create_generated_contents_table.php
    ..._create_generated_content_versions_table.php

tests/
  Feature/
    Models/
      ProjectTest.php
      GeneratedContentTest.php
      GeneratedContentVersionTest.php
    ContentTypes/
      ContentTypeRegistryTest.php
```

`User.php` would be updated with its `projects()` relationship.

## Design choices and future-proofing

- **Immutable versions:** Store each AI result or user edit as a separate row. Editing a version in place would undermine history and reproducibility.
- **Structured JSON:** A JSON column accommodates different content shapes without adding per-type columns. The registry owns validation and schema expectations for each shape.
- **Context snapshot separation:** Keep the context used for a particular version separate from the generated content and from future reusable project context.
- **Generic projects:** Project fields describe ownership and grouping, not a fictional setting or franchise.
- **Future content relationships:** Do not add a self-referencing relation table or polymorphic association now. When a real relationship workflow exists, a generic edge table can record source content, target content, and a stable relation key such as `derived_from` or `response_to`.
- **Multiple representations of one event:** Do not bake a fictional-event model into this milestone. Later, a generic event/concept entity or shared event identifier can link different content records without tying projects to a particular setting.
- **No premature OpenAI abstractions:** No SDK, API call, job, or provider interface is needed to establish these domain tables and registry boundary.

## Suggested first implementation milestone

Implement the three tables, models, enum, factories, and relationship/cast/registry tests. Add a small code-backed example type to exercise registry lookup. Keep generation itself, version-creation orchestration, current-version selection, UI, and OpenAI integration for later milestones.
