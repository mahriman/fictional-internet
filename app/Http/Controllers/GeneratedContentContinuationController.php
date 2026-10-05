<?php

namespace App\Http\Controllers;

use App\Actions\ContinueGeneratedContent;
use App\Actions\GenerationAttemptManager;
use App\ContentTypes\ContentContinuationComposer;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContinuableContentType;
use App\Exceptions\GenerationAttemptException;
use App\Exceptions\OpenAiCredentialException;
use App\Exceptions\StructuredContentGenerationException;
use App\Http\Requests\StoreGeneratedContentContinuationRequest;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use App\Services\OpenAI\OpenAiCredentialResolver;
use App\Services\OpenAI\OpenAiException;
use App\Services\OpenAI\OpenAiFailureKind;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class GeneratedContentContinuationController extends Controller
{
    public function create(
        Request $request,
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
        ContentContinuationComposer $composer,
        GenerationAttemptManager $attempts,
    ): View {
        Gate::authorize('view', $project);
        $sourceVersion = $this->sourceVersion($generatedContent, $versionNumber);
        $definition = $contentTypes->all()[$generatedContent->content_type] ?? null;
        abort_unless($definition instanceof ContinuableContentType, 404);

        $sourceContent = is_array($sourceVersion->content) ? $sourceVersion->content : [];
        $collection = $definition->continuationCollectionField();
        $sourceEntries = $sourceContent[$collection] ?? null;
        $entryCount = is_array($sourceEntries) && array_is_list($sourceEntries) ? count($sourceEntries) : 0;
        $collectionSchema = $definition->outputSchema()['properties'][$collection] ?? [];
        $maximumEntryCount = is_array($collectionSchema) && is_int($collectionSchema['maxItems'] ?? null)
            ? $collectionSchema['maxItems']
            : 0;
        $remainingCapacity = null;

        try {
            $remainingCapacity = $composer->remainingCapacity($definition, $sourceContent);
        } catch (ValidationException|InvalidArgumentException) {
            // Legacy or malformed source versions stay readable but cannot be continued.
        }

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
        $latestVersionNumber = $generatedContent->versions()->max('version_number');
        $versionTitle = $definition->titleFromContent($sourceContent);

        return view('generated-content.continue', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'sourceVersion' => $sourceVersion,
            'sourceTitle' => filled($versionTitle) ? $versionTitle : $definition->label(),
            'contentTypeLabel' => $definition->label(),
            'entryCount' => $entryCount,
            'maximumEntryCount' => $maximumEntryCount,
            'remainingCapacity' => $remainingCapacity,
            'hasNewerVersions' => $latestVersionNumber !== null && $latestVersionNumber > $versionNumber,
            'contentTypes' => $contentTypes->all(),
            'generatedContents' => $generatedContents,
            'hasPersonalKey' => $user->openAiCredential()->exists(),
            'attemptToken' => $remainingCapacity > 0
                ? $attempts->tokenForForm(
                    $user,
                    $project,
                    is_string(old('attempt_token')) ? old('attempt_token') : null,
                    $generatedContent,
                    $sourceVersion,
                )
                : null,
        ]);
    }

    public function store(
        StoreGeneratedContentContinuationRequest $request,
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
        ContinueGeneratedContent $continueContent,
        GenerationAttemptManager $attempts,
        OpenAiCredentialResolver $credentialResolver,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $sourceVersion = $this->sourceVersion($generatedContent, $versionNumber);
        abort_unless(($contentTypes->all()[$generatedContent->content_type] ?? null) instanceof ContinuableContentType, 404);
        $attemptToken = $request->validated('attempt_token');

        if (! $attempts->isIssuedFor($user, $project, $attemptToken, $generatedContent, $sourceVersion)) {
            $completedVersion = $attempts->completedContinuationVersionFor(
                $user,
                $project,
                $attemptToken,
                $generatedContent,
                $sourceVersion,
            );

            if ($completedVersion !== null) {
                return $this->versionRedirect($project, $generatedContent, $completedVersion);
            }

            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                ['continuation' => 'This continuation attempt is no longer available. A new attempt is ready.'],
            );
        }

        try {
            $preparedContinuation = $continueContent->prepare(
                $user,
                $generatedContent,
                $sourceVersion,
                $request->validated('continuation_instructions'),
                (int) $request->validated('entry_count'),
                $request->validated('references', []),
            );
        } catch (ValidationException $exception) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                $exception->errors(),
                preserveAttemptToken: $attempts->isIssuedFor($user, $project, $attemptToken, $generatedContent, $sourceVersion),
            );
        } catch (GenerationAttemptException) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                ['continuation' => 'This continuation attempt is no longer available. A new attempt is ready.'],
            );
        }

        try {
            $apiKey = $credentialResolver->forUser($user);
        } catch (OpenAiCredentialException $exception) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                ['credentials' => $exception->getMessage()],
                preserveAttemptToken: true,
            );
        }

        try {
            $result = $continueContent->handlePrepared(
                $user,
                $preparedContinuation,
                $attemptToken,
                $apiKey,
            );
        } catch (ValidationException $exception) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                $exception->errors(),
                preserveAttemptToken: $attempts->isIssuedFor($user, $project, $attemptToken, $generatedContent, $sourceVersion),
            );
        } catch (OpenAiException $exception) {
            $errorKey = in_array($exception->failureKind, [OpenAiFailureKind::Authentication, OpenAiFailureKind::Authorization], true)
                ? 'credentials'
                : 'generation';

            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                [$errorKey => $this->openAiFailureMessage($exception)],
            );
        } catch (StructuredContentGenerationException) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                ['generation' => 'OpenAI did not return valid structured content. Submit again to start a new attempt.'],
            );
        } catch (GenerationAttemptException) {
            return $this->continuationFormRedirect(
                $request,
                $project,
                $generatedContent,
                $versionNumber,
                ['continuation' => 'This continuation attempt is already used or unavailable. A new attempt is ready.'],
            );
        }

        return $this->versionRedirect($project, $generatedContent, $result->version)
            ->with('status', 'Version '.$result->version->version_number.' created from version '.$sourceVersion->version_number.'.');
    }

    private function sourceVersion(GeneratedContent $generatedContent, int $versionNumber): GeneratedContentVersion
    {
        return $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
    }

    private function versionRedirect(
        Project $project,
        GeneratedContent $generatedContent,
        GeneratedContentVersion $version,
    ): RedirectResponse {
        return redirect()->route('projects.generated-content.versions.show', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'versionNumber' => $version->version_number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function continuationFormRedirect(
        StoreGeneratedContentContinuationRequest $request,
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        array $errors,
        bool $preserveAttemptToken = false,
    ): RedirectResponse {
        return redirect()
            ->route('projects.generated-content.versions.continuations.create', [
                'project' => $project,
                'generatedContent' => $generatedContent,
                'versionNumber' => $versionNumber,
            ])
            ->withInput($request->safeContinuationInput($preserveAttemptToken))
            ->withErrors($errors);
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
}
