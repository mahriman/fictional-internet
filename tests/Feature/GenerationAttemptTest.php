<?php

use App\Actions\GenerationAttemptManager;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'attempt-server-key');
    config()->set('services.openai.allow_server_key_fallback', true);
    config()->set('services.openai.model', 'attempt-test-model');
    config()->set('services.openai.timeout', 30);
});

function generationAttemptSuccessResponse(): array
{
    return [
        'id' => 'resp_attempt_success',
        'status' => 'completed',
        'model' => 'actual-attempt-model',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode([
                    'headline' => 'A Quiet Harbor',
                    'publication' => 'The Harbor Ledger',
                    'published_at' => '2026-10-04T10:00:00Z',
                    'body' => 'The harbor was quiet before dawn.',
                ], JSON_THROW_ON_ERROR),
            ]],
        ]],
    ];
}

function generationAttemptTokenFromForm(string $html): string
{
    preg_match('/name="attempt_token" value="([a-f0-9]{64})"/', $html, $matches);

    return $matches[1] ?? '';
}

test('the generation form issues a random opaque attempt token distinct from csrf', function () {
    $project = Project::factory()->create();

    $response = $this->actingAs($project->user)
        ->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('name="attempt_token"', false)
        ->assertSee('aria-live="polite"', false)
        ->assertSee('data-generation-form', false);

    $token = generationAttemptTokenFromForm($response->getContent());

    expect($token)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($token)->not->toBe(csrf_token())
        ->and(GenerationAttempt::query()->sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(GenerationAttempt::query()->sole()->user_id)->toBe($project->user_id)
        ->and(GenerationAttempt::query()->sole()->project_id)->toBe($project->id)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
});

test('invalid fields do not claim the attempt and preserve its valid token', function () {
    $project = Project::factory()->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(generationAttemptSuccessResponse())]);

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => 'unknown_type',
            'prompt' => 'Keep this prompt.',
            'api_key' => 'must-not-be-flashed',
            'unexpected' => 'must-not-be-flashed',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('content_type')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionHas('_old_input.prompt', 'Keep this prompt.')
        ->assertSessionMissing('_old_input.api_key')
        ->assertSessionMissing('_old_input.unexpected');

    $form = $this->get(route('projects.generated-content.create', $project))->assertOk();

    expect(generationAttemptTokenFromForm($form->getContent()))->toBe($token)
        ->and(GenerationAttempt::query()->count())->toBe(1)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
});

test('missing and malformed attempt tokens are rejected before credential or provider use', function () {
    $project = Project::factory()->create();
    Http::fake();

    $this->actingAs($project->user)
        ->post(route('projects.generated-content.store', $project), [
            'content_type' => 'news_article',
            'prompt' => 'A valid prompt.',
        ])
        ->assertSessionHasErrors('attempt_token');

    $this->post(route('projects.generated-content.store', $project), [
        'attempt_token' => 'not-a-valid-token',
        'content_type' => 'news_article',
        'prompt' => 'A valid prompt.',
    ])->assertSessionHasErrors('attempt_token');

    expect(GenerationAttempt::query()->count())->toBe(0)
        ->and($project->generatedContents()->count())->toBe(0);
    Http::assertNothingSent();
});

test('missing personal credentials leave the attempt issued until a key is configured', function () {
    $project = Project::factory()->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    config()->set('services.openai.allow_server_key_fallback', false);
    config()->set('services.openai.api_key', null);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(generationAttemptSuccessResponse())]);

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => 'news_article',
            'prompt' => 'Keep this prompt while I configure a key.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionHas('_old_input.prompt', 'Keep this prompt while I configure a key.');

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued)
        ->and($project->generatedContents()->exists())->toBeFalse();
    Http::assertNothingSent();

    OpenAiCredential::factory()->for($project->user)->create(['api_key' => 'new-personal-test-key']);

    $this->post(route('projects.generated-content.store', $project), [
        'attempt_token' => $token,
        'content_type' => 'news_article',
        'prompt' => 'Keep this prompt while I configure a key.',
    ])->assertRedirect();

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Completed)
        ->and($project->generatedContents()->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer new-personal-test-key'));
});

test('a completed attempt redirects duplicate submissions to the original content without another provider request', function () {
    $project = Project::factory()->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(generationAttemptSuccessResponse())]);

    $payload = [
        ...personalGenerationAttemptFields($project, $token),
        'content_type' => 'news_article',
        'prompt' => 'Write one article.',
    ];

    $firstResponse = $this->actingAs($project->user)
        ->post(route('projects.generated-content.store', $project), $payload);
    $generatedContent = $project->generatedContents()->sole();
    $detailUrl = route('projects.generated-content.show', [$project, $generatedContent]);
    $firstResponse->assertRedirect($detailUrl);

    config()->set('services.openai.allow_server_key_fallback', false);
    config()->set('services.openai.api_key', null);

    $this->post(route('projects.generated-content.store', $project), $payload)
        ->assertRedirect($detailUrl);

    Http::assertSentCount(1);
    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Completed)
        ->and(GenerationAttempt::query()->sole()->generated_content_id)->toBe($generatedContent->id)
        ->and($generatedContent->versions()->count())->toBe(1);
});

test('an in-progress or stale attempt stays consumed and the form issues a fresh token', function () {
    $project = Project::factory()->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    $claim = app(GenerationAttemptManager::class)->claim($project->user, $project, $token);
    $attempt = $claim->attempt;
    GenerationAttempt::query()->whereKey($attempt->getKey())->update(['claimed_at' => now()->subDays(3)]);
    Http::fake();

    $response = $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => 'news_article',
            'prompt' => 'Preserve this prompt.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('generation')
        ->assertSessionHas('_old_input.prompt', 'Preserve this prompt.')
        ->assertSessionMissing('_old_input.attempt_token');

    $freshForm = $this->get(route('projects.generated-content.create', $project))->assertOk();

    expect(generationAttemptTokenFromForm($freshForm->getContent()))->not->toBe($token)
        ->and($attempt->fresh()->status)->toBe(GenerationAttemptStatus::InProgress);
    Http::assertNothingSent();
});

test('failed attempts cannot be replayed and a new token permits an explicit retry', function () {
    $project = Project::factory()->create();
    $failedToken = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    $retryToken = bin2hex(random_bytes(32));
    Http::fakeSequence()
        ->push(['error' => ['message' => 'raw provider error']], 429)
        ->push(generationAttemptSuccessResponse());

    $failedPayload = [
        ...personalGenerationAttemptFields($project, $failedToken),
        'content_type' => 'news_article',
        'prompt' => 'Retain this prompt.',
    ];

    $this->actingAs($project->user)
        ->post(route('projects.generated-content.store', $project), $failedPayload)
        ->assertSessionHasErrors('generation');

    $this->post(route('projects.generated-content.store', $project), $failedPayload)
        ->assertSessionHasErrors('generation')
        ->assertSessionMissing('_old_input.attempt_token');

    expect(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Failed->value)->count())->toBe(1)
        ->and($project->generatedContents()->count())->toBe(0);
    Http::assertSentCount(1);

    $this->post(route('projects.generated-content.store', $project), [
        ...personalGenerationAttemptFields($project, $retryToken),
        'content_type' => 'news_article',
        'prompt' => 'Retain this prompt.',
    ])->assertRedirect();

    Http::assertSentCount(2);
    expect($project->generatedContents()->count())->toBe(1)
        ->and(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Completed->value)->count())->toBe(1);
});

test('attempt tokens cannot be reused across users or projects', function () {
    $user = User::factory()->create();
    $firstProject = Project::factory()->for($user)->create();
    $secondProject = Project::factory()->for($user)->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($user, $firstProject, null);
    Http::fake();

    $this->actingAs($user)
        ->post(route('projects.generated-content.store', $secondProject), [
            'attempt_token' => $token,
            'content_type' => 'news_article',
            'prompt' => 'A valid prompt.',
        ])
        ->assertSessionHasErrors('generation');

    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->for($otherUser)->create();
    $this->actingAs($otherUser)
        ->post(route('projects.generated-content.store', $otherProject), [
            'attempt_token' => $token,
            'content_type' => 'news_article',
            'prompt' => 'A valid prompt.',
        ])
        ->assertSessionHasErrors('generation');

    expect(GenerationAttempt::query()->count())->toBe(1);
    Http::assertNothingSent();
});

test('the attempt claim uses a unique database constraint and stores only a token hash', function () {
    $project = Project::factory()->create();
    $manager = app(GenerationAttemptManager::class);
    $token = $manager->tokenForForm($project->user, $project, null);
    $first = $manager->claim($project->user, $project, $token);
    $second = $manager->claim($project->user, $project, $token);

    expect($first->claimed)->toBeTrue()
        ->and($second->claimed)->toBeFalse()
        ->and(GenerationAttempt::query()->count())->toBe(1)
        ->and($first->attempt->token_hash)->toBe(hash('sha256', $token))
        ->and($first->attempt->toArray())->not->toHaveKey('token_hash');

    expect(fn () => GenerationAttempt::query()->create([
        'token_hash' => hash('sha256', $token),
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'status' => GenerationAttemptStatus::InProgress,
        'claimed_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('the attempt claim transaction is closed before the provider request', function () {
    $project = Project::factory()->create();
    $baselineTransactionLevel = DB::transactionLevel();
    $completionTransactionLevel = null;
    DB::listen(function (QueryExecuted $query) use (&$completionTransactionLevel): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'update')
            && str_contains($sql, 'generation_attempts')
            && str_contains($sql, 'generated_content_id')) {
            $completionTransactionLevel = DB::transactionLevel();
        }
    });
    Http::fake(function () use ($baselineTransactionLevel) {
        expect(DB::transactionLevel())->toBe($baselineTransactionLevel)
            ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::InProgress);

        return Http::response(generationAttemptSuccessResponse());
    });

    $this->actingAs($project->user)
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Check transaction boundaries.',
        ])
        ->assertRedirect();

    expect($completionTransactionLevel)->toBeGreaterThan($baselineTransactionLevel);
});

test('provider status failures have safe actionable classifications for personal keys', function (
    int $status,
    string $expectedMessage,
    bool $personalKey,
) {
    $project = Project::factory()->create();

    if ($personalKey) {
        OpenAiCredential::factory()->for($project->user)->create(['api_key' => 'personal-attempt-test-key']);
    }

    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'error' => ['message' => 'provider body must stay private'],
    ], $status)]);

    $response = $this->actingAs($project->user)
        ->followingRedirects()
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Preserve on failure.',
        ])
        ->assertOk()
        ->assertSee('Preserve on failure.');

    $response->assertSee($expectedMessage)
        ->assertDontSee('provider body must stay private');

    expect($project->generatedContents()->exists())->toBeFalse()
        ->and(GeneratedContentVersion::query()->exists())->toBeFalse();

    $expectedKey = $personalKey ? 'personal-attempt-test-key' : 'personal-test-openai-key';
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer '.$expectedKey,
    ));

    Http::assertSentCount(1);
})->with([
    'personal 401' => [401, 'rejected your personal API key', true],
    'personal 403' => [403, 'denied access', true],
    'rate limit' => [429, 'temporarily limiting', false],
    'temporary provider failure' => [503, 'temporarily unavailable', false],
]);

test('network failures do not expose exception details and require a new attempt', function () {
    $project = Project::factory()->create();
    Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection('connection includes private diagnostics')]);

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Retry safely.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('generation');

    $message = session('errors')->getBag('default')->first('generation');
    expect($message)->toContain('could not confirm')
        ->and($message)->not->toContain('private diagnostics')
        ->and($project->generatedContents()->exists())->toBeFalse();
    Http::assertSentCount(1);
});

test('incomplete and malformed provider responses become safe feedback without records', function (array|string $body) {
    $project = Project::factory()->create();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($body)]);

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Try valid content.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('generation');

    expect(session('errors')->getBag('default')->first('generation'))->toContain('valid content')
        ->and($project->generatedContents()->exists())->toBeFalse()
        ->and(GeneratedContentVersion::query()->exists())->toBeFalse();
    Http::assertSentCount(1);
})->with([
    'incomplete' => [[
        'id' => 'resp_incomplete',
        'status' => 'incomplete',
        'model' => 'attempt-test-model',
        'output' => [],
    ]],
    'malformed json body' => ['not-json'],
]);

test('reference-size validation happens before claiming so the token remains unconsumed', function () {
    $project = Project::factory()->create();
    $source = GeneratedContent::factory()->for($project)->create();
    $source->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => [
            'headline' => 'Large source',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2026-10-04T10:00:00Z',
            'body' => str_repeat('x', 31000),
        ],
    ]);
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    Http::fake();

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project, $token),
            'content_type' => 'news_article',
            'prompt' => 'Keep this prompt.',
            'references' => [$source->uuid.':1'],
        ])
        ->assertSessionHasErrors('references');

    expect(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Issued)->count())->toBe(1)
        ->and($project->generatedContents()->count())->toBe(1);
    Http::assertNothingSent();
});

test('version persistence failure rolls back content and leaves the attempt non-replayable', function () {
    $project = Project::factory()->create();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(generationAttemptSuccessResponse())]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'insert into')
            && str_contains(strtolower($query->sql), 'generated_content_versions')) {
            throw new RuntimeException('Simulated version persistence failure.');
        }
    });

    $thrown = null;

    try {
        $this->withoutExceptionHandling()
            ->actingAs($project->user)
            ->post(route('projects.generated-content.store', $project), [
                ...personalGenerationAttemptFields($project, 'a'.str_repeat('b', 63)),
                'content_type' => 'news_article',
                'prompt' => 'Persist atomically.',
            ]);
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($project->generatedContents()->exists())->toBeFalse()
        ->and(GeneratedContentVersion::query()->exists())->toBeFalse()
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Failed);
    Http::assertSentCount(1);
});

test('failure to mark an attempt failed does not replace the original persistence exception', function () {
    $project = Project::factory()->create();
    OpenAiCredential::factory()->for($project->user)->create(['api_key' => 'attempt-persistence-test-key']);
    $token = app(GenerationAttemptManager::class)->tokenForForm($project->user, $project, null);
    $attempts = Mockery::mock(GenerationAttemptManager::class)->makePartial();
    $attempts->shouldReceive('fail')
        ->once()
        ->andThrow(new LogicException('Secondary attempt update failure.'));
    app()->instance(GenerationAttemptManager::class, $attempts);

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(generationAttemptSuccessResponse())]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'insert into')
            && str_contains(strtolower($query->sql), 'generated_content_versions')) {
            throw new RuntimeException('Original version persistence failure.');
        }
    });

    $thrown = null;

    try {
        $this->withoutExceptionHandling()
            ->actingAs($project->user)
            ->post(route('projects.generated-content.store', $project), [
                'attempt_token' => $token,
                'content_type' => 'news_article',
                'prompt' => 'Preserve the original exception.',
            ]);
    } catch (RuntimeException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown->getMessage())->toBe('Original version persistence failure.')
        ->and($project->generatedContents()->exists())->toBeFalse()
        ->and(GeneratedContentVersion::query()->exists())->toBeFalse()
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::InProgress);
    Http::assertSentCount(1);
});
