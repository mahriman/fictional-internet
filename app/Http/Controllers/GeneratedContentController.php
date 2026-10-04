<?php

namespace App\Http\Controllers;

use App\Actions\EditGeneratedContentVersion;
use App\Actions\GenerateAndPersistContent;
use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Http\Requests\EditGeneratedContentRequest;
use App\Http\Requests\GenerateContentRequest;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Services\OpenAI\OpenAiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GeneratedContentController extends Controller
{
    public function create(Project $project, ContentTypeRegistry $contentTypes): View
    {
        Gate::authorize('view', $project);

        $generatedContents = $project->generatedContents()
            ->select(['id', 'project_id', 'uuid', 'content_type', 'title'])
            ->with(['versions' => static fn (Relation $query) => $query
                ->select(['id', 'generated_content_id', 'version_number', 'content'])
                ->reorder()
                ->orderByDesc('version_number')])
            ->latest('created_at')
            ->latest('id')
            ->get();

        return view('generated-content.create', [
            'project' => $project,
            'contentTypes' => $contentTypes->all(),
            'generatedContents' => $generatedContents,
        ]);
    }

    public function store(
        GenerateContentRequest $request,
        Project $project,
        GenerateAndPersistContent $generateAndPersistContent,
    ): RedirectResponse {
        try {
            $result = $generateAndPersistContent->handle(
                $project,
                $request->validated('content_type'),
                $request->validated('prompt'),
                references: $request->validated('references', []),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('projects.generated-content.create', ['project' => $project])
                ->withInput($request->safe()->only(['content_type', 'prompt', 'references']))
                ->withErrors($exception->errors());
        } catch (OpenAiException|StructuredContentGenerationException) {
            return redirect()
                ->route('projects.generated-content.create', ['project' => $project])
                ->withInput($request->safe()->only(['content_type', 'prompt', 'references']))
                ->withErrors(['generation' => 'We could not generate content right now. Please try again.']);
        }

        return redirect()->route('projects.generated-content.show', [
            'project' => $project,
            'generatedContent' => $result->generatedContent,
        ]);
    }

    public function show(
        Project $project,
        GeneratedContent $generatedContent,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $versions = $this->versionHistory($generatedContent);
        $version = $versions->first();

        abort_if($version === null, 404);

        return $this->showVersionContent($project, $generatedContent, $version, $versions, $contentTypes);
    }

    public function showVersion(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $version = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $version->loadMissing('basedOnVersion:id,version_number');

        return $this->showVersionContent(
            $project,
            $generatedContent,
            $version,
            $this->versionHistory($generatedContent),
            $contentTypes,
        );
    }

    public function editVersion(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $version = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $definition = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $editingView = $definition?->editingView();

        abort_unless($editingView !== null && view()->exists($editingView), 404);

        return view('generated-content.edit', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'version' => $version,
            'structuredContent' => is_array($version->content) ? $version->content : [],
            'contentTypeLabel' => $definition->label(),
            'editingView' => $editingView,
        ]);
    }

    public function storeVersionEdit(
        EditGeneratedContentRequest $request,
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        EditGeneratedContentVersion $editVersion,
    ): RedirectResponse {
        $sourceVersion = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $newVersion = $editVersion->handle($sourceVersion, $request->validated('content'));

        return redirect()
            ->route('projects.generated-content.versions.show', [
                'project' => $project,
                'generatedContent' => $generatedContent,
                'versionNumber' => $newVersion->version_number,
            ])
            ->with('status', 'Version '.$newVersion->version_number.' created from version '.$sourceVersion->version_number.'.');
    }

    /**
     * @return Collection<int, GeneratedContentVersion>
     */
    private function versionHistory(GeneratedContent $generatedContent): Collection
    {
        return $generatedContent->versions()
            ->with('basedOnVersion:id,version_number')
            ->reorder()
            ->orderByDesc('version_number')
            ->get();
    }

    /**
     * @param  Collection<int, GeneratedContentVersion>  $versions
     */
    private function showVersionContent(
        Project $project,
        GeneratedContent $generatedContent,
        GeneratedContentVersion $version,
        Collection $versions,
        ContentTypeRegistry $contentTypes,
    ): View {
        $contentType = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $structuredContent = is_array($version->content) ? $version->content : [];
        $presentationView = $contentType?->presentationView();
        $editingView = $contentType?->editingView();
        $latestVersion = $versions->first();

        return view('generated-content.show', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'version' => $version,
            'structuredContent' => $structuredContent,
            'contentTypeLabel' => $contentType?->label() ?? $generatedContent->content_type,
            'presentationView' => $presentationView !== null && view()->exists($presentationView)
                ? $presentationView
                : null,
            'editingView' => $editingView !== null && view()->exists($editingView)
                ? $editingView
                : null,
            'versionTitle' => $contentType?->titleFromContent($structuredContent),
            'versionHistory' => $versions,
            'isLatestVersion' => $latestVersion !== null && $latestVersion->is($version),
            'referenceSummaries' => $this->referenceSummaries($project, $version, $contentTypes),
        ]);
    }

    /**
     * Resolve captured reference links only through this version's owning project.
     *
     * @return list<array{title: string, content_type: string, version_number: int|string|null, url: string|null}>
     */
    private function referenceSummaries(
        Project $project,
        GeneratedContentVersion $version,
        ContentTypeRegistry $contentTypes,
    ): array {
        $snapshot = $version->context_snapshot;

        if (! is_array($snapshot)) {
            return [];
        }

        $snapshotReferences = $snapshot['references'] ?? [];

        if (! is_array($snapshotReferences)) {
            return [];
        }

        $references = [];

        foreach ($snapshotReferences as $snapshotReference) {
            if (! is_array($snapshotReference)) {
                continue;
            }

            $contentUuid = $snapshotReference['content_uuid'] ?? null;
            $rawVersionNumber = $snapshotReference['version_number'] ?? null;
            $versionNumber = is_int($rawVersionNumber) && $rawVersionNumber > 0
                ? $rawVersionNumber
                : (is_string($rawVersionNumber) && ctype_digit($rawVersionNumber)
                    ? filter_var($rawVersionNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false);

            $references[] = [
                'content_uuid' => is_string($contentUuid) && Str::isUuid($contentUuid) ? Str::lower($contentUuid) : null,
                'content_type' => is_string($snapshotReference['content_type'] ?? null)
                    ? $snapshotReference['content_type']
                    : 'Unknown content type',
                'title' => is_string($snapshotReference['title'] ?? null)
                    ? $snapshotReference['title']
                    : 'Untitled reference',
                'version_number' => $versionNumber === false ? null : $versionNumber,
            ];
        }

        if ($references === []) {
            return [];
        }

        $contentUuids = array_values(array_unique(array_filter(array_column($references, 'content_uuid'))));
        $contents = $project->generatedContents()
            ->whereIn('uuid', $contentUuids)
            ->get(['id', 'uuid'])
            ->keyBy('uuid');
        $referencesWithContent = array_filter($references, static fn (array $reference): bool => $reference['content_uuid'] !== null
            && $reference['version_number'] !== null
            && $contents->has($reference['content_uuid'])
        );
        $existingVersions = collect();

        if ($referencesWithContent !== []) {
            $existingVersions = GeneratedContentVersion::query()
                ->where(function (Builder $query) use ($referencesWithContent, $contents): void {
                    foreach ($referencesWithContent as $reference) {
                        $contentId = $contents->get($reference['content_uuid'])->getKey();

                        $query->orWhere(function (Builder $pairQuery) use ($contentId, $reference): void {
                            $pairQuery->where('generated_content_id', $contentId)
                                ->where('version_number', $reference['version_number']);
                        });
                    }
                })
                ->get(['generated_content_id', 'version_number'])
                ->mapWithKeys(static fn (GeneratedContentVersion $selectedVersion): array => [
                    $selectedVersion->generated_content_id.':'.$selectedVersion->version_number => true,
                ]);
        }

        return array_map(function (array $reference) use ($project, $contentTypes, $contents, $existingVersions): array {
            $definition = $contentTypes->all()[$reference['content_type']] ?? null;
            $targetContent = $reference['content_uuid'] === null
                ? null
                : $contents->get($reference['content_uuid']);
            $pairKey = $targetContent === null || $reference['version_number'] === null
                ? null
                : $targetContent->getKey().':'.$reference['version_number'];

            return [
                'title' => $reference['title'],
                'content_type' => $definition?->label() ?? $reference['content_type'],
                'version_number' => $reference['version_number'],
                'url' => $pairKey !== null && $existingVersions->has($pairKey)
                    ? route('projects.generated-content.versions.show', [
                        'project' => $project,
                        'generatedContent' => $targetContent,
                        'versionNumber' => $reference['version_number'],
                    ])
                    : null,
            ];
        }, $references);
    }
}
