<?php

namespace App\Actions;

use App\Exceptions\ProjectExportException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Support\Str;
use JsonException;

class ExportProject
{
    public const FORMAT = 'fictional-internet-project';

    public const FORMAT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const SAFE_GENERATION_METADATA_FIELDS = [
        'provider',
        'model',
        'response_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'content_type',
        'operation',
        'source_version_number',
        'requested_entry_count',
        'quotes_preserved',
        'quotes_normalized',
        'quotes_dropped',
    ];

    public function handle(Project $project): ProjectExportArchive
    {
        $project->load([
            'context',
            'generatedContents' => static fn ($query) => $query
                ->select(['id', 'project_id', 'uuid', 'content_type', 'title', 'created_at', 'updated_at'])
                ->orderBy('uuid'),
            'generatedContents.versions' => static fn ($query) => $query
                ->select([
                    'id', 'generated_content_id', 'based_on_version_id', 'version_number', 'origin',
                    'content', 'context_snapshot', 'generation_metadata', 'created_at',
                ])
                ->reorder()
                ->orderBy('version_number'),
            'generatedContents.versions.basedOnVersion' => static fn ($query) => $query
                ->select(['id', 'generated_content_id', 'version_number']),
        ]);

        $data = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'description' => $project->description,
                'created_at' => $project->created_at?->toIso8601String(),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ],
            'project_context' => $project->context === null ? null : $project->context->generationFields(),
            'generated_contents' => $project->generatedContents
                ->map(fn (GeneratedContent $generatedContent): array => $this->serializeGeneratedContent($generatedContent))
                ->values()
                ->all(),
        ];

        try {
            $body = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new ProjectExportException('The project archive could not be encoded.');
        }

        $projectName = Str::limit(Str::slug($project->name), 80, '');

        return new ProjectExportArchive(
            filename: ($projectName !== '' ? $projectName : 'project').'.json',
            body: $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGeneratedContent(GeneratedContent $generatedContent): array
    {
        return [
            'uuid' => $generatedContent->uuid,
            'content_type' => $generatedContent->content_type,
            'title' => $generatedContent->title,
            'created_at' => $generatedContent->created_at?->toIso8601String(),
            'updated_at' => $generatedContent->updated_at?->toIso8601String(),
            'versions' => $generatedContent->versions
                ->map(fn (GeneratedContentVersion $version): array => $this->serializeVersion($version, $generatedContent))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVersion(GeneratedContentVersion $version, GeneratedContent $generatedContent): array
    {
        $basedOnVersion = $version->basedOnVersion;
        $portableParentVersion = $basedOnVersion !== null
            && (int) $basedOnVersion->generated_content_id === (int) $generatedContent->getKey()
                ? (int) $basedOnVersion->version_number
                : null;

        return [
            'version_number' => (int) $version->version_number,
            'origin' => $version->getRawOriginal('origin'),
            'content' => $version->content,
            'based_on_version' => $portableParentVersion,
            'context_snapshot' => $version->context_snapshot,
            'generation_metadata' => $this->safeGenerationMetadata($version->generation_metadata),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeGenerationMetadata(mixed $metadata): ?array
    {
        if (! is_array($metadata)) {
            return null;
        }

        return array_intersect_key($metadata, array_flip(self::SAFE_GENERATION_METADATA_FIELDS));
    }
}
