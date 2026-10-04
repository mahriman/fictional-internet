<?php

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
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

test('generation uses the personal key before the server fallback and never persists credentials', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $personalKey = 'personal-precedence-test-secret';
    OpenAiCredential::factory()->for($user)->create(['api_key' => $personalKey]);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(openAiCredentialSuccessPayload())]);

    $this->actingAs($user)->post(route('projects.generated-content.store', $project), [
        ...generationAttemptFields($project),
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
});

test('generation uses the server fallback only when enabled and no personal key exists', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(openAiCredentialSuccessPayload())]);

    $this->actingAs($user)->post(route('projects.generated-content.store', $project), [
        ...generationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => 'Write a fictional story.',
    ])->assertRedirect();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer server-fallback-test-secret',
    ));
    Http::assertSentCount(1);
});

test('disabled or missing server fallback prevents provider calls and preserves safe form fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $reference = GeneratedContent::factory()->for($project)->create();
    $reference->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => [
            'headline' => 'Source title',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Source body.',
        ],
    ]);
    config()->set('services.openai.allow_server_key_fallback', false);
    $this->actingAs($user);

    $this->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...generationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Preserve this safe prompt.',
            'references' => [$reference->uuid.':1'],
            'api_key' => 'browser-submitted-test-secret',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.content_type', 'news_article')
        ->assertSessionHas('_old_input.prompt', 'Preserve this safe prompt.')
        ->assertSessionHas('_old_input.references', [$reference->uuid.':1'])
        ->assertSessionMissing('_old_input.api_key');

    $this->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('Account settings')
        ->assertSee(route('account.settings'), false);

    expect($project->generatedContents()->count())->toBe(1)
        ->and(GeneratedContentVersion::query()->count())->toBe(1);
    Http::assertNothingSent();

    config()->set('services.openai.allow_server_key_fallback', true);
    config()->set('services.openai.api_key', null);
    $this->post(route('projects.generated-content.store', $project), [
        ...generationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => 'Still unavailable.',
    ])->assertSessionHasErrors('credentials');
    Http::assertNothingSent();
});

test('false zero and off config values all disable the server fallback', function (mixed $disabledValue) {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    config()->set('services.openai.allow_server_key_fallback', $disabledValue);
    Http::fake();

    $this->actingAs($user)
        ->get(route('account.settings'))
        ->assertOk()
        ->assertSee('The server key fallback is disabled.');

    $this->post(route('projects.generated-content.store', $project), [
        ...generationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => 'Fallback must remain disabled.',
    ])->assertSessionHasErrors('credentials');

    expect($project->generatedContents()->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([false, 0, 'off']);

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
            ...generationAttemptFields($project),
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
            ...generationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => 'Do not make a provider call.',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionMissing('_old_input.api_key');

    $safeError = session('errors')->getBag('default')->first('credentials');
    expect($safeError)->toContain('could not be decrypted')
        ->and($safeError)->not->toContain('invalid-ciphertext')
        ->and($project->generatedContents()->exists())->toBeFalse();
    Http::assertNothingSent();
});
