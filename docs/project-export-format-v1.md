# Fictional Internet Project Export Format, Version 1

The authenticated project export is a portable JSON archive identified by
`format: "fictional-internet-project"` and `format_version: 1`. The format
version describes this archive contract, not the Laravel application or its
database migrations.

## Structure

The top-level object contains:

- `format`, `format_version`, and timezone-aware `exported_at`;
- `project`: the project's public UUID, name, description, and creation and
  update timestamps;
- `project_context`: either `null` when no Project Context record exists, or
  the six fields `setting`, `time_period`, `locations`, `people`,
  `organizations`, and `canon_notes` (each may itself be null);
- `generated_contents`: the project's artifacts.

Artifacts are sorted by public UUID in ascending lexical order. Each artifact
contains its UUID, `content_type`, stable user-managed `title`, creation and
update timestamps, and all immutable `versions`.

Versions are sorted by ascending `version_number`. Each version contains its
number, `origin`, complete persisted structured `content`, `based_on_version`,
`context_snapshot`, safe `generation_metadata`, and creation timestamp.
`based_on_version` is the parent version number within that same artifact, or
`null` for a root version. Internal database IDs are never part of this
format. Branches are represented by each version's own parent number; version
number adjacency does not imply lineage.

## Provenance and exclusions

Context snapshots are preserved as stored, including captured historical
reference snapshots. They are not refreshed from current project or artifact
records, so references remain interpretable when their source has changed or
been deleted. Structured content is archived as persisted and is not
revalidated, normalized, rendered, or regenerated during export.

Generation metadata is restricted to fields written by the generation
workflow: provider, model, response ID, token counts, content type, operation,
source version number, requested entry count, and aggregate quote-normalization
counters. Unknown metadata keys are omitted.

Operational GenerationAttempt records are excluded. The archive also excludes
owner/account identifiers and details, credentials and encrypted credential
values, authentication/session data, and unrelated projects. No database
primary keys are exported.

## Compatibility

Consumers should check both `format` and `format_version`. Version 1 field
meaning, null handling, and ordering are explicit above. A future incompatible
archive contract must use a deliberately incremented format version rather
than silently changing version 1 semantics. This application currently
provides export only; it does not import or restore archives.
