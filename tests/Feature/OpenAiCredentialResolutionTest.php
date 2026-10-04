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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'server-fallback-test-secret');
    config()->set('services.openai.allow_server_key_fallback', true);
    config()->set('services.openai.model', 'credential-test-model');
    config()->set('services.openai.timeout', 30);
});

function openAiCredentialSuccessPayload(): array
{
    return [
        'id' => 'resp_user_credential_success',
        'status' => 'completed',
        'model' => 'actual-credential-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode([
                    'headline' => 'Harbor Signal Returns',
                    'publication' => 'The Harbor Ledger',
                    'published_at' => '2025-06-15T10:30:00Z',
                    'body' => 'A signal was heard again after midnight.',
                ], JSON_THROW_ON_ERROR),
            ]],
        ]],
        'usage' => ['input_tokens' => 11, 'output_tokens' => 17, 'total_tokens' => 28],
    ];
}

test('generation uses only the saved personal key and never persists credentials', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $personalKey = 'personal-precedence-test-secret';
    OpenAiCredential::factory()->for($user)->create(['api_key' => $personalKey]);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(openAiCredentialSuccessPayload())]);

    $this->actingAs($user)->post(route('projects.generated-content.store', $project), [
        ...personalGenerationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => 'Write a fictional follow-up.',
        'api_key' => 'browser-submitted-test-secret',
    ])->assertRedirect();

    Http::assertSent(function (Request $request) use ($personalKey): bool {
        return $request->hasHeader('Authorization', 'Bearer '.$personalKey);
    });
    Http::assertSentCount(1);

    $version = $project->generatedContents()->sole()->versions()->sole();
    $persistedData = json_encode([
        $version->content,
        $version->context_snapshot,
        $version->generation_metadata,
    ], JSON_THROW_ON_ERROR);

    expect($persistedData)->not->toContain($personalKey)
        ->and($persistedData)->not->toContain('server-fallback-test-secret')
        ->and($persistedData)->not->toContain('browser-submitted-test-secret')
        ->and(DB::table('open_ai_credentials')->value('api_key'))->not->toBe($personalKey);

    $this->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertDontSee($personalKey)
        ->assertDontSee('data-personal-key', false);
});

test('a configured server key and legacy fallback flag cannot bypass the personal-key requirement', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    $reference = GeneratedContent::factory()->for($project)->create(['content_type' => 'news_article']);
    $reference->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => [
            'headline' => 'Existing content remains available',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Existing body.',
        ],
    ]);
    Http::fake();

    $this->actingAs($user)->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => 'news_article',
            'prompt' => 'Preserve this prompt.',
            'references' => [$reference->uuid.':1'],
            'api_key' => 'browser-supplied-secret',
        ])->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionHas('_old_input.prompt', 'Preserve this prompt.')
        ->assertSessionHas('_old_input.references', [$reference->uuid.':1'])
        ->assertSessionMissing('_old_input.api_key');

    $this->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('A personal OpenAI API key is required before you can generate content.')
        ->assertSee(route('account.settings'), false)
        ->assertSee('Add a personal key to generate')
        ->assertDontSee('data-generation-submit', false);

    expect($project->generatedContents()->count())->toBe(1)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
    $this->get(route('projects.generated-content.show', [$project, $reference]))
        ->assertOk()
        ->assertSee('Existing content remains available');
    Http::assertNothingSent();
});

test('missing personal credentials block all registered content types and preserve existing project content access', function (string $contentType) {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $token = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    Http::fake();

    $this->actingAs($user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => $contentType,
            'prompt' => 'Preserve this safe prompt.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.content_type', $contentType)
        ->assertSessionHas('_old_input.prompt', 'Preserve this safe prompt.')
        ->assertSessionHas('_old_input.attempt_token', $token);

    $this->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('Account settings')
        ->assertSee(route('account.settings'), false);

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
})->with(['news_article', 'forum_thread']);

test('personal authentication failures do not retry with the server fallback', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $reference = GeneratedContent::factory()->for($project)->create();
    $reference->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => [
            'headline' => 'Reference title',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Reference body.',
        ],
    ]);
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'invalid-personal-test-secret']);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'private provider detail']], 401)]);

    $this->actingAs($user)
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Keep this prompt.',
            'references' => [$reference->uuid.':1'],
            'api_key' => 'browser-submitted-test-secret',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.references', [$reference->uuid.':1'])
        ->assertSessionMissing('_old_input.api_key');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer invalid-personal-test-secret',
    ));
    Http::assertSentCount(1);
    $safeError = session('errors')->getBag('default')->first('credentials');
    expect($safeError)->not->toContain('invalid-personal-test-secret')
        ->and($safeError)->not->toContain('server-fallback-test-secret')
        ->and($safeError)->not->toContain('private provider detail');
    expect($project->generatedContents()->count())->toBe(1)
        ->and(GeneratedContentVersion::query()->count())->toBe(1);
});

test('a credential decryption failure is safe and never falls back to the server key', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'temporary-test-secret']);
    DB::table('open_ai_credentials')->where('user_id', $user->id)->update(['api_key' => 'invalid-ciphertext']);
    Http::fake();

    $this->actingAs($user)
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Do not make a provider call.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionMissing('_old_input.api_key');

    $safeError = session('errors')->getBag('default')->first('credentials');
    expect($safeError)->toContain('could not be decrypted')
        ->and($safeError)->not->toContain('invalid-ciphertext')
        ->and($project->generatedContents()->exists())->toBeFalse()
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued)
        ->and(session()->get('_old_input.attempt_token'))->not->toBeNull();
    Http::assertNothingSent();
});

test('replacing or removing a personal key changes the next generation credential immediately', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'old-personal-key']);
    $this->actingAs($user)
        ->put(route('account.settings.openai-credential.store'), ['api_key' => 'replacement-personal-key'])
        ->assertRedirect(route('account.settings'));
    Http::fakeSequence('https://api.openai.com/v1/responses')
        ->push(openAiCredentialSuccessPayload())
        ->push(openAiCredentialSuccessPayload());

    $this->post(route('projects.generated-content.store', $project), [
        ...personalGenerationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => 'Use the replacement key.',
    ])->assertRedirect();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer replacement-personal-key'));

    $this->delete(route('account.settings.openai-credential.destroy'))->assertRedirect(route('account.settings'));
    $newToken = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    $this->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $newToken,
            'content_type' => 'news_article',
            'prompt' => 'A removed key cannot generate.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials');

    Http::assertSentCount(1);
    expect(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Issued->value)->count())->toBe(1)
        ->and($project->generatedContents()->count())->toBe(1);
});

test('users without a personal key can still read and manually edit existing content', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'news_article',
        'title' => 'Existing article',
    ]);
    $source = $generatedContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => [
            'headline' => 'Existing article',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Existing body.',
        ],
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('Existing article');

    $this->get(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('Existing body.');

    $this->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), [
        'content' => [
            'headline' => 'Manually revised article',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Revised body.',
        ],
    ])->assertRedirect(route('projects.generated-content.versions.show', [$project, $generatedContent, 2]));

    expect($source->fresh()->content['headline'])->toBe('Existing article')
        ->and($generatedContent->versions()->count())->toBe(2)
        ->and($generatedContent->versions()->where('version_number', 2)->firstOrFail()->content['headline'])->toBe('Manually revised article');
    Http::assertNothingSent();
});
