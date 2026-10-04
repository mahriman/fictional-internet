<?php

namespace App\Actions;

use App\Enums\GenerationAttemptStatus;
use App\Exceptions\GenerationAttemptException;
use App\Models\GeneratedContent;
use App\Models\GenerationAttempt;
use App\Models\Project;
use App\Models\User;
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
    public function tokenForForm(User $user, Project $project, ?string $candidate): string
    {
        if ((int) $project->user_id !== (int) $user->getKey()) {
            throw new GenerationAttemptException('A generation attempt can only be issued to its project owner.');
        }

        if (is_string($candidate)
            && preg_match('/\A[a-f0-9]{64}\z/', $candidate) === 1
            && GenerationAttempt::query()
                ->where('token_hash', hash('sha256', $candidate))
                ->where('user_id', $user->getKey())
                ->where('project_id', $project->getKey())
                ->where('status', GenerationAttemptStatus::Issued->value)
                ->exists()) {
            return $candidate;
        }

        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($user, $project, $token): void {
            GenerationAttempt::query()->create([
                'token_hash' => hash('sha256', $token),
                'user_id' => $user->getKey(),
                'project_id' => $project->getKey(),
                'status' => GenerationAttemptStatus::Issued,
                'claimed_at' => null,
            ]);
        });

        return $token;
    }

    public function claim(User $user, Project $project, string $token): GenerationAttemptClaim
    {
        $attempt = GenerationAttempt::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($attempt === null
            || (int) $attempt->user_id !== (int) $user->getKey()
            || (int) $attempt->project_id !== (int) $project->getKey()) {
            throw new GenerationAttemptException('This generation attempt is not available for this project.');
        }

        $claimed = DB::transaction(fn (): int => GenerationAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GenerationAttemptStatus::Issued->value)
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

    public function complete(GenerationAttempt $attempt, GeneratedContent $generatedContent): void
    {
        if ((int) $attempt->project_id !== (int) $generatedContent->project_id) {
            throw new LogicException('A generation attempt can only complete with content from its project.');
        }

        $updated = DB::transaction(fn (): int => GenerationAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', GenerationAttemptStatus::InProgress->value)
            ->update([
                'status' => GenerationAttemptStatus::Completed->value,
                'generated_content_id' => $generatedContent->getKey(),
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
}
