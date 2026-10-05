<?php

namespace App\Actions;

use App\Enums\GenerationAttemptStatus;
use App\Exceptions\GenerationAttemptException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Issued hashes bind opaque form tokens to one owner and project. A conditional update
 * claims an attempt once; failed or interrupted attempts are never reclaimed. Content,
 * its first version, and completion are committed together after the provider request. A
 * crash after provider completion but before that transaction starts leaves an in-progress
 * attempt, which is never replayed because the provider outcome cannot be confirmed.
 */
class GenerationAttemptManager
{
    public function isIssuedFor(
        User $user,
        Project $project,
        string $token,
        ?GeneratedContent $targetContent = null,
        ?GeneratedContentVersion $sourceVersion = null,
    ): bool {
        $query = GenerationAttempt::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->where('status', GenerationAttemptStatus::Issued->value);

        $this->constrainContinuation($query, $targetContent, $sourceVersion);

        return $query->exists();
    }

    public function completedContinuationVersionFor(
        User $user,
        Project $project,
        string $token,
        GeneratedContent $targetContent,
        GeneratedContentVersion $sourceVersion,
    ): ?GeneratedContentVersion {
        $this->validateContinuationBinding($project, $targetContent, $sourceVersion);

        return GenerationAttempt::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->where('target_generated_content_id', $targetContent->getKey())
            ->where('source_version_id', $sourceVersion->getKey())
            ->where('status', GenerationAttemptStatus::Completed->value)
            ->whereHas('generatedContentVersion', static fn (Builder $query) => $query
                ->where('generated_content_id', $targetContent->getKey())
                ->where('based_on_version_id', $sourceVersion->getKey()))
            ->first()
            ?->generatedContentVersion;
    }

    public function tokenForForm(
        User $user,
        Project $project,
        ?string $candidate,
        ?GeneratedContent $targetContent = null,
        ?GeneratedContentVersion $sourceVersion = null,
    ): string {
        if ((int) $project->user_id !== (int) $user->getKey()) {
            throw new GenerationAttemptException('A generation attempt can only be issued to its project owner.');
        }

        $this->validateContinuationBinding($project, $targetContent, $sourceVersion);

        if (is_string($candidate)
            && preg_match('/\A[a-f0-9]{64}\z/', $candidate) === 1
            && GenerationAttempt::query()
                ->where('token_hash', hash('sha256', $candidate))
                ->where('user_id', $user->getKey())
                ->where('project_id', $project->getKey())
                ->where('target_generated_content_id', $targetContent?->getKey())
                ->where('source_version_id', $sourceVersion?->getKey())
                ->where('status', GenerationAttemptStatus::Issued->value)
                ->exists()) {
            return $candidate;
        }

        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($user, $project, $token, $targetContent, $sourceVersion): void {
            GenerationAttempt::query()->create([
                'token_hash' => hash('sha256', $token),
                'user_id' => $user->getKey(),
                'project_id' => $project->getKey(),
                'target_generated_content_id' => $targetContent?->getKey(),
                'source_version_id' => $sourceVersion?->getKey(),
                'status' => GenerationAttemptStatus::Issued,
                'claimed_at' => null,
            ]);
        });

        return $token;
    }

    public function claim(
        User $user,
        Project $project,
        string $token,
        ?GeneratedContent $targetContent = null,
        ?GeneratedContentVersion $sourceVersion = null,
    ): GenerationAttemptClaim {
        $this->validateContinuationBinding($project, $targetContent, $sourceVersion);

        $attempt = GenerationAttempt::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($attempt === null
            || (int) $attempt->user_id !== (int) $user->getKey()
            || (int) $attempt->project_id !== (int) $project->getKey()
            || (int) $attempt->target_generated_content_id !== (int) ($targetContent?->getKey())
            || (int) $attempt->source_version_id !== (int) ($sourceVersion?->getKey())) {
            throw new GenerationAttemptException('This generation attempt is not available for this project.');
        }

        $query = GenerationAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GenerationAttemptStatus::Issued->value);
        $this->constrainContinuation($query, $targetContent, $sourceVersion);

        $claimed = DB::transaction(fn (): int => $query
            ->update([
                'status' => GenerationAttemptStatus::InProgress->value,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]));

        $attempt = GenerationAttempt::query()->find($attempt->getKey());

        if ($attempt === null) {
            throw new GenerationAttemptException('This generation attempt is not available for this project.');
        }

        return new GenerationAttemptClaim($attempt, $claimed === 1);
    }

    public function complete(
        GenerationAttempt $attempt,
        GeneratedContent $generatedContent,
        ?GeneratedContentVersion $version = null,
    ): void {
        if ((int) $attempt->project_id !== (int) $generatedContent->project_id) {
            throw new LogicException('A generation attempt can only complete with content from its project.');
        }

        if ($attempt->target_generated_content_id !== null
            && (int) $attempt->target_generated_content_id !== (int) $generatedContent->getKey()) {
            throw new LogicException('A continuation attempt can only complete for its bound content.');
        }

        if ($attempt->source_version_id !== null
            && ($version === null || (int) $version->based_on_version_id !== (int) $attempt->source_version_id)) {
            throw new LogicException('A continuation attempt can only complete from its bound source version.');
        }

        if ($version !== null && (int) $version->generated_content_id !== (int) $generatedContent->getKey()) {
            throw new LogicException('A generation attempt can only complete with a version from its generated content.');
        }

        $updated = DB::transaction(fn (): int => GenerationAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GenerationAttemptStatus::InProgress->value)
            ->update([
                'status' => GenerationAttemptStatus::Completed->value,
                'generated_content_id' => $generatedContent->getKey(),
                'generated_content_version_id' => $version?->getKey(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]));

        if ($updated !== 1) {
            throw new LogicException('The generation attempt could not be completed from its current state.');
        }
    }

    public function fail(GenerationAttempt $attempt): void
    {
        DB::transaction(fn (): int => GenerationAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GenerationAttemptStatus::InProgress->value)
            ->update([
                'status' => GenerationAttemptStatus::Failed->value,
                'updated_at' => now(),
            ]));
    }

    private function validateContinuationBinding(
        Project $project,
        ?GeneratedContent $targetContent,
        ?GeneratedContentVersion $sourceVersion,
    ): void {
        if (($targetContent === null) !== ($sourceVersion === null)) {
            throw new GenerationAttemptException('A continuation attempt must bind both content and source version.');
        }

        if ($targetContent === null) {
            return;
        }

        if (! $targetContent->exists
            || ! $sourceVersion?->exists
            || (int) $targetContent->project_id !== (int) $project->getKey()
            || (int) $sourceVersion->generated_content_id !== (int) $targetContent->getKey()) {
            throw new GenerationAttemptException('A continuation attempt is not available for this source version.');
        }
    }

    private function constrainContinuation(
        Builder $query,
        ?GeneratedContent $targetContent,
        ?GeneratedContentVersion $sourceVersion,
    ): void {
        $query->where('target_generated_content_id', $targetContent?->getKey())
            ->where('source_version_id', $sourceVersion?->getKey());
    }
}
