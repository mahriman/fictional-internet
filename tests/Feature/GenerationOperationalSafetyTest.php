<?php

use App\Actions\GenerationAttemptManager;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Models\GeneratedContent;
use App\Models\GenerationAttempt;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function operationalAttemptToken(Project $project): string
{
    return app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
}

function operationalForumSource(): array
{
    return [
        'forum_name' => 'The Harbor Board',
        'thread_title' => 'Signal at the pier',
        'category' => 'Local',
        'started_at' => '2026-10-05T10:00:00+00:00',
        'posts' => [[
            'post_number' => 1,
            'author' => 'Mica',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => 'The signal blinked twice.',
            'reply_to_post_number' => null,
            'quote' => null,
        ]],
    ];
}

test('ordinary generation and continuation share a per-user limit and a different user has a separate allowance', function () {
    $project = Project::factory()->create();
    $sourceContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = $sourceContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => operationalForumSource(),
    ]);
    $this->actingAs($project->user);

    for ($requestNumber = 0; $requestNumber < 10; $requestNumber++) {
        $this->post(route('projects.generated-content.store', $project), [
            'attempt_token' => operationalAttemptToken($project),
            'content_type' => 'news_article',
            'prompt' => 'A valid prompt.',
        ])->assertRedirect(route('projects.generated-content.create', $project));
    }

    $continuationToken = app(GenerationAttemptManager::class)->tokenForForm(
        $project->user,
        $project,
        null,
        $sourceContent,
        $source,
    );

    $this->post(route('projects.generated-content.versions.continuations.store', [$project, $sourceContent, 1]), [
        'attempt_token' => $continuationToken,
        'continuation_instructions' => 'Continue cautiously.',
        'entry_count' => 1,
        'api_key' => 'rate-limit-private-key-sentinel',
    ])->assertStatus(429)
        ->assertSee('Please wait a minute before trying again.')
        ->assertHeader('Retry-After')
        ->assertDontSee('rate-limit-private-key-sentinel')
        ->assertDontSee('api_key');

    expect(GenerationAttempt::query()->where('user_id', $project->user_id)->count())->toBe(11)
        ->and($project->generatedContents()->count())->toBe(1)
        ->and($sourceContent->versions()->count())->toBe(1)
        ->and(GenerationAttempt::query()->where('token_hash', hash('sha256', $continuationToken))->sole()->status)
        ->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();

    $otherProject = Project::factory()->create();
    $this->actingAs($otherProject->user)
        ->post(route('projects.generated-content.store', $otherProject), [
            'attempt_token' => operationalAttemptToken($otherProject),
            'content_type' => 'news_article',
            'prompt' => 'A valid prompt.',
        ])->assertRedirect(route('projects.generated-content.create', $otherProject));

    $this->get(route('projects.generated-content.create', $otherProject))->assertOk();
});

test('continuation reference choices use stable artifact metadata without loading version JSON', function () {
    $project = Project::factory()->create();
    $sourceContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'forum_thread',
        'title' => 'Stable reference artifact',
    ]);
    $sourceContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => operationalForumSource(),
    ]);
    $target = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'forum_thread',
        'title' => 'Continuation target',
    ]);
    $targetVersion = $target->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => operationalForumSource(),
    ]);
    $selectorVersionColumns = [];

    DB::listen(function (QueryExecuted $query) use (&$selectorVersionColumns): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'generated_content_versions')
            && str_contains($sql, 'generated_content_id')
            && str_contains($sql, ' in (')) {
            preg_match('/select\s+(.*?)\s+from/s', $sql, $matches);
            $selectorVersionColumns[] = $matches[1] ?? '';
        }
    });

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.continuations.create', [$project, $target, 1]))
        ->assertOk()
        ->assertSee('Forum Thread · '.$sourceContent->title.' · Version 1');

    expect($selectorVersionColumns)->not->toBeEmpty();

    foreach ($selectorVersionColumns as $selectedColumns) {
        expect($selectedColumns)
            ->not->toContain('context_snapshot')
            ->not->toContain('generation_metadata')
            ->not->toMatch('/(^|[,\s])content([,\s]|$)/');
    }

    Http::assertNothingSent();
});

test('generation forms are not rate limited and unauthenticated submissions remain protected by authentication', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->user);

    for ($requestNumber = 0; $requestNumber < 12; $requestNumber++) {
        $this->get(route('projects.generated-content.create', $project))->assertOk();
    }

    auth()->logout();

    $this->post(route('projects.generated-content.store', $project), [])->assertRedirect(route('login'));
    Http::assertNothingSent();
});

test('attempt pruning applies status-specific retention boundaries and supports a safe dry run', function () {
    $now = now()->startOfSecond();
    $this->travelTo($now);
    $project = Project::factory()->create();

    $createAttempt = function (GenerationAttemptStatus $status, array $timestamps = []) use ($project): GenerationAttempt {
        return GenerationAttempt::factory()->for($project->user)->create([
            'project_id' => $project->getKey(),
            'status' => $status,
            ...$timestamps,
        ]);
    };

    $recentIssued = $createAttempt(GenerationAttemptStatus::Issued, [
        'created_at' => $now->copy()->subHours(2),
        'updated_at' => $now->copy()->subHours(2),
    ]);
    $oldIssued = $createAttempt(GenerationAttemptStatus::Issued, [
        'created_at' => $now->copy()->subDays(1)->subSecond(),
        'updated_at' => $now->copy()->subDays(1)->subSecond(),
    ]);
    $issuedBoundary = $createAttempt(GenerationAttemptStatus::Issued, [
        'created_at' => $now->copy()->subDay(),
        'updated_at' => $now->copy()->subDay(),
    ]);
    $recentFailed = $createAttempt(GenerationAttemptStatus::Failed, [
        'updated_at' => $now->copy()->subDays(2),
    ]);
    $oldFailed = $createAttempt(GenerationAttemptStatus::Failed, [
        'updated_at' => $now->copy()->subDays(7)->subSecond(),
    ]);
    $recentCompleted = $createAttempt(GenerationAttemptStatus::Completed, [
        'completed_at' => $now->copy()->subDays(20),
        'updated_at' => $now->copy()->subDays(20),
    ]);
    $oldCompleted = $createAttempt(GenerationAttemptStatus::Completed, [
        'completed_at' => $now->copy()->subDays(30)->subSecond(),
        'updated_at' => $now->copy()->subDays(30)->subSecond(),
    ]);
    $activeInProgress = $createAttempt(GenerationAttemptStatus::InProgress, [
        'claimed_at' => $now->copy()->subHours(3),
        'updated_at' => $now->copy()->subHours(3),
    ]);
    $staleInProgress = $createAttempt(GenerationAttemptStatus::InProgress, [
        'claimed_at' => $now->copy()->subDays(1)->subSecond(),
        'updated_at' => $now->copy()->subDays(1)->subSecond(),
    ]);
    $unclaimedInProgress = $createAttempt(GenerationAttemptStatus::InProgress, [
        'claimed_at' => null,
    ]);

    $this->artisan('generation-attempts:prune', ['--dry-run' => true])
        ->expectsOutputToContain('Eligible attempts total: 4')
        ->assertExitCode(0);

    expect(GenerationAttempt::query()->count())->toBe(10);

    $this->artisan('generation-attempts:prune')
        ->expectsOutputToContain('Pruned attempts total: 4')
        ->doesntExpectOutputToContain($oldIssued->token_hash)
        ->assertExitCode(0);

    expect(GenerationAttempt::query()->count())->toBe(6)
        ->and($recentIssued->fresh())->not->toBeNull()
        ->and($issuedBoundary->fresh())->not->toBeNull()
        ->and($recentFailed->fresh())->not->toBeNull()
        ->and($recentCompleted->fresh())->not->toBeNull()
        ->and($activeInProgress->fresh())->not->toBeNull()
        ->and($unclaimedInProgress->fresh())->not->toBeNull()
        ->and($oldIssued->fresh())->toBeNull()
        ->and($oldFailed->fresh())->toBeNull()
        ->and($oldCompleted->fresh())->toBeNull()
        ->and($staleInProgress->fresh())->toBeNull();

    $this->artisan('generation-attempts:prune')
        ->expectsOutputToContain('Pruned attempts total: 0')
        ->assertExitCode(0);
});

test('pruning completed ordinary and continuation attempts preserves their content and versions', function () {
    $now = now();
    $this->travelTo($now);
    $project = Project::factory()->create();
    $ordinaryContent = GeneratedContent::factory()->for($project)->create();
    $ordinaryVersion = $ordinaryContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => ['headline' => 'Durable ordinary result'],
    ]);
    $sourceContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $sourceVersion = $sourceContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => operationalForumSource(),
    ]);
    $continuationVersion = $sourceContent->versions()->create([
        'based_on_version_id' => $sourceVersion->getKey(),
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => operationalForumSource(),
    ]);

    foreach ([
        [
            'generated_content_id' => $ordinaryContent->getKey(),
            'generated_content_version_id' => $ordinaryVersion->getKey(),
        ],
        [
            'target_generated_content_id' => $sourceContent->getKey(),
            'source_version_id' => $sourceVersion->getKey(),
            'generated_content_id' => $sourceContent->getKey(),
            'generated_content_version_id' => $continuationVersion->getKey(),
        ],
    ] as $binding) {
        GenerationAttempt::factory()->for($project->user)->create([
            'project_id' => $project->getKey(),
            'status' => GenerationAttemptStatus::Completed,
            'completed_at' => $now->copy()->subDays(31),
            'updated_at' => $now->copy()->subDays(31),
            ...$binding,
        ]);
    }

    $this->artisan('generation-attempts:prune')->assertExitCode(0);

    expect(GenerationAttempt::query()->count())->toBe(0)
        ->and($project->generatedContents()->count())->toBe(2)
        ->and($ordinaryContent->versions()->count())->toBe(1)
        ->and($ordinaryContent->versions()->sole()->content['headline'])->toBe('Durable ordinary result')
        ->and($sourceContent->versions()->count())->toBe(2)
        ->and($continuationVersion->fresh()->based_on_version_id)->toBe($sourceVersion->getKey());
});
