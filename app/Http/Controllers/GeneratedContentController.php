<?php

namespace App\Http\Controllers;

use App\Actions\EditGeneratedContentVersion;
use App\Actions\GenerateAndPersistContent;
use App\Actions\GenerationAttemptManager;
use App\ContentTypes\ContentContinuationComposer;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContinuableContentType;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\GenerationAttemptException;
use App\Exceptions\OpenAiCredentialException;
use App\Exceptions\StructuredContentGenerationException;
use App\Http\Requests\EditGeneratedContentRequest;
use App\Http\Requests\GenerateContentRequest;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\Project;
use App\Models\User;
use App\Services\OpenAI\OpenAiCredentialResolver;
use App\Services\OpenAI\OpenAiException;
use App\Services\OpenAI\OpenAiFailureKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class GeneratedContentController extends Controller
{
    public function create(
        Request $request,
        Project $project,
        ContentTypeRegistry $contentTypes,
        GenerationAttemptManager $attempts,
    ): View {
        Gate::authorize('view', $project);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

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
            'hasPersonalKey' => $user->openAiCredential()->exists(),
            'attemptToken' => $attempts->tokenForForm(
                $user,
                $project,
                is_string(old('attempt_token')) ? old('attempt_token') : null,
            ),
        ]);
    }

    public function store(
        GenerateContentRequest $request,
        Project $project,
        GenerateAndPersistContent $generateAndPersistContent,
        OpenAiCredentialResolver $credentialResolver,
        GenerationAttemptManager $attempts,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $preparedGeneration = $generateAndPersistContent->prepare(
                $project,
                $request->validated('content_type'),
                $request->validated('prompt'),
                $request->validated('references', []),
            );
        } catch (ValidationException $exception) {
            return $this->generationFormRedirect(
                $request,
                $project,
                $exception->errors(),
                includeAttemptToken: true,
            );
        }

        $apiKey = null;

        if ($attempts->isIssuedFor($user, $project, $request->validated('attempt_token'))) {
            try {
                $apiKey = $credentialResolver->forUser($user);
            } catch (OpenAiCredentialException $exception) {
                return $this->generationFormRedirect(
                    $request,
                    $project,
                    ['credentials' => $exception->getMessage()],
                    includeAttemptToken: true,
                );
            }
        }

        try {
            $claim = $attempts->claim($user, $project, $request->validated('attempt_token'));
        } catch (GenerationAttemptException) {
            return $this->generationFormRedirect(
                $request,
                $project,
                'This generation attempt is not available for this project. A new attempt is ready.',
                includeAttemptToken: false,
            );
        }

        $attempt = $claim->attempt;

        if (! $claim->claimed) {
            if ($attempt->status === GenerationAttemptStatus::Completed) {
                $existingContent = $attempt->generatedContent()
                    ->where('project_id', $project->getKey())
                    ->first();

                if ($existingContent !== null) {
                    return redirect()->route('projects.generated-content.show', [
                        'project' => $project,
                        'generatedContent' => $existingContent,
                    ]);
                }
            }

            $message = match ($attempt->status) {
                GenerationAttemptStatus::InProgress => 'This attempt is already processing or its outcome could not be confirmed. Its token cannot be reused. Submit again to start a new attempt.',
                GenerationAttemptStatus::Failed => 'This attempt has already failed and cannot be replayed. Submit again to start a new attempt.',
                GenerationAttemptStatus::Completed => 'This attempt is complete, but its result is no longer available. Submit again to start a new attempt.',
            };

            return $this->generationFormRedirect($request, $project, $message, includeAttemptToken: false);
        }

        try {
            $result = $generateAndPersistContent->handlePrepared(
                $preparedGeneration,
                apiKey: $apiKey,
                afterPersistence: fn (GeneratedContent $generatedContent) => $attempts->complete($attempt, $generatedContent),
            );
        } catch (OpenAiException $exception) {
            $this->markAttemptFailed($attempts, $attempt);
            Log::warning('OpenAI generation request failed.', [
                'content_type' => $request->validated('content_type'),
                'failure_kind' => $exception->failureKind->value,
                'http_status' => $exception->statusCode,
                ...$exception->diagnosticContext,
            ]);
            $message = $this->openAiFailureMessage($exception);
            $errorKey = in_array($exception->failureKind, [OpenAiFailureKind::Authentication, OpenAiFailureKind::Authorization], true)
                ? 'credentials'
                : 'generation';

            return $this->generationFormRedirect($request, $project, [$errorKey => $message]);
        } catch (StructuredContentGenerationException $exception) {
            $this->markAttemptFailed($attempts, $attempt);
            Log::warning('Structured content generation failed.', [
                'content_type' => $request->validated('content_type'),
                'category' => $exception->diagnosticCategory,
                'diagnostic_codes' => $exception->diagnosticCodes,
                'field_paths' => $exception->fieldPaths,
                ...$exception->diagnosticContext,
            ]);

            return $this->generationFormRedirect(
                $request,
                $project,
                ['generation' => 'OpenAI did not return valid structured content. Submit again to start a new attempt.'],
            );
        } catch (Throwable $exception) {
            $this->markAttemptFailed($attempts, $attempt);

            throw $exception;
        }

        return redirect()->route('projects.generated-content.show', [
            'project' => $project,
            'generatedContent' => $result->generatedContent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeGenerationInput(GenerateContentRequest $request, bool $includeAttemptToken = false): array
    {
        $fields = ['content_type', 'prompt', 'references'];

        if ($includeAttemptToken) {
            $attemptToken = $request->validated('attempt_token');
            $user = $request->user();

            if (is_string($attemptToken)
                && is_string($request->input('attempt_token'))
                && $user instanceof User
                && GenerationAttempt::query()
                    ->where('token_hash', hash('sha256', $attemptToken))
                    ->where('user_id', $user->getKey())
                    ->where('project_id', $request->route('project')?->getKey())
                    ->where('status', GenerationAttemptStatus::Issued->value)
                    ->exists()) {
                $fields[] = 'attempt_token';
            }
        }

        return $request->safe()->only($fields);
    }

    private function markAttemptFailed(GenerationAttemptManager $attempts, GenerationAttempt $attempt): void
    {
        try {
            $attempts->fail($attempt);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, string>|string  $errors
     */
    private function generationFormRedirect(
        GenerateContentRequest $request,
        Project $project,
        array|string $errors,
        bool $includeAttemptToken = false,
    ): RedirectResponse {
        return redirect()
            ->route('projects.generated-content.create', ['project' => $project])
            ->withInput($this->safeGenerationInput($request, $includeAttemptToken))
            ->withErrors(is_array($errors) ? $errors : ['generation' => $errors]);
    }

    private function openAiFailureMessage(OpenAiException $exception): string
    {
        return match ($exception->failureKind) {
            OpenAiFailureKind::Authentication => 'OpenAI rejected your personal API key. Check or replace it in Account settings.',
            OpenAiFailureKind::Authorization => 'OpenAI denied access for your personal API key. Check its permissions or replace it in Account settings.',
            OpenAiFailureKind::RateLimited => 'OpenAI is temporarily limiting requests. Please try again later.',
            OpenAiFailureKind::Network => 'We could not confirm whether OpenAI completed the request because of a connection problem or timeout. Submit again to start a new attempt if needed.',
            OpenAiFailureKind::TemporaryProvider => 'OpenAI is temporarily unavailable. Please try again later.',
            OpenAiFailureKind::MalformedResponse, OpenAiFailureKind::IncompleteResponse, OpenAiFailureKind::Refusal => 'OpenAI did not return valid content. Submit again to start a new attempt.',
            OpenAiFailureKind::Configuration, OpenAiFailureKind::Other => 'We could not generate content right now. Submit again to start a new attempt.',
        };
    }

    public function show(
        Project $project,
        GeneratedContent $generatedContent,
        ContentTypeRegistry $contentTypes,
        ContentContinuationComposer $composer,
    ): View {
        Gate::authorize('view', $project);

        $versions = $this->versionHistory($generatedContent);
        $version = $versions->first();

        abort_if($version === null, 404);

        return $this->showVersionContent($project, $generatedContent, $version, $versions, $contentTypes, $composer);
    }

    public function showVersion(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
        ContentContinuationComposer $composer,
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
            $composer,
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
        ContentContinuationComposer $composer,
    ): View {
        $contentType = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $structuredContent = is_array($version->content) ? $version->content : [];
        $presentationView = $contentType?->presentationView();
        $editingView = $contentType?->editingView();
        $latestVersion = $versions->first();
        $continuationSupported = $contentType instanceof ContinuableContentType;
        $continuationCapacity = null;

        if ($continuationSupported) {
            try {
                $continuationCapacity = $composer->remainingCapacity($contentType, $structuredContent);
            } catch (ValidationException|InvalidArgumentException) {
                // Invalid legacy versions remain readable but cannot be continued.
            }
        }

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
            'continuationSupported' => $continuationSupported,
            'continuationCapacity' => $continuationCapacity,
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
