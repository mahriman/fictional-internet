<?php

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\ContentTypes\Definitions\NewsArticleType;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'configured-test-key');
    config()->set('services.openai.model', 'configured-test-model');
    config()->set('services.openai.timeout', 30);
});

test('guests cannot access project content generation routes', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();

    $this->get(route('projects.generated-content.create', $project))->assertRedirect(route('login'));
    $this->post(route('projects.generated-content.store', $project), [])->assertRedirect(route('login'));
    $this->get(route('projects.generated-content.show', [$project, $generatedContent]))->assertRedirect(route('login'));

    Http::assertNothingSent();
});

test('owners can open the form and content type options come from the registry', function () {
    $project = Project::factory()->create();
    $otherContentType = new class implements ContentTypeDefinition
    {
        public function key(): string
        {
            return 'forum_thread';
        }

        public function label(): string
        {
            return 'Forum thread';
        }

        public function presentationView(): ?string
        {
            return null;
        }

        public function editingView(): ?string
        {
            return null;
        }

        public function promptInstructions(): string
        {
            return 'Write a fictional forum thread.';
        }

        public function titleFromContent(array $content): ?string
        {
            return null;
        }

        public function outputSchema(): array
        {
            return [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['body' => ['type' => 'string']],
                'required' => ['body'],
            ];
        }

        public function validationRules(): array
        {
            return ['body' => ['required', 'string']];
        }

        public function semanticValidationErrors(array $content): array
        {
            return [];
        }

        public function editingValidationRules(array $sourceContent): array
        {
            return $this->validationRules();
        }

        public function prepareEditedContent(array $submittedContent, array $sourceContent): array
        {
            return $submittedContent;
        }
    };

    app()->instance(ContentTypeRegistry::class, new ContentTypeRegistry(new NewsArticleType, $otherContentType));

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('News article')
        ->assertSee('Forum thread')
        ->assertSee('name="content_type"', false)
        ->assertSee('name="prompt"', false)
        ->assertSee('data-generation-form', false)
        ->assertSee('name="attempt_token"', false)
        ->assertSee('role="status" aria-live="polite" aria-atomic="true"', false)
        ->assertSee('Generation can take some time; keep this page open while it runs.');

    Http::assertNothingSent();
});

test('another user cannot open or submit a content generation request', function () {
    $project = Project::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('projects.generated-content.create', $project))
        ->assertForbidden();

    $this->post(route('projects.generated-content.store', $project), [
        'content_type' => 'news_article',
        'prompt' => 'Write a story about a lighthouse.',
    ])->assertForbidden();

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('unregistered content type is rejected without sending a provider request', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);

    $this->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'unregistered_type',
            'prompt' => 'Write a story about a lighthouse.',
            'api_key' => 'sensitive-browser-provided-key',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('content_type')
        ->assertSessionHas('_old_input.prompt', 'Write a story about a lighthouse.')
        ->assertSessionMissingInput('api_key');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('empty and whitespace prompts are rejected', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);

    $this->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => " \t\n",
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('prompt')
        ->assertSessionHas('_old_input.content_type', 'news_article');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('prompt length is limited at the application boundary', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);

    $this->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => str_repeat('a', 10001),
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('prompt');

    Http::assertNothingSent();
});

test('successful generation persists one first version and redirects to a uuid detail url', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);
    $prompt = 'Write a fictional report about a silent ferry arriving at North Harbor.';
    $article = [
        'headline' => '<script>alert(1)</script>',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'A silent ferry arrived before dawn.',
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_content_ui_success',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($article, JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 44, 'output_tokens' => 83, 'total_tokens' => 127],
        ]),
    ]);

    $response = $this->post(route('projects.generated-content.store', $project), [
        ...personalGenerationAttemptFields($project),
        'content_type' => 'news_article',
        'prompt' => $prompt,
        'api_key' => 'browser-provided-secret',
    ]);

    $generatedContent = $project->generatedContents()->sole();
    $version = $generatedContent->versions()->sole();
    $detailUrl = route('projects.generated-content.show', [$project, $generatedContent]);

    expect(Str::isUuid($generatedContent->uuid))->toBeTrue()
        ->and($generatedContent->getRouteKeyName())->toBe('uuid')
        ->and($generatedContent->project->is($project))->toBeTrue()
        ->and($generatedContent->content_type)->toBe('news_article')
        ->and($generatedContent->title)->toBe('<script>alert(1)</script>')
        ->and($version->version_number)->toBe(1)
        ->and($version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and($version->based_on_version_id)->toBeNull()
        ->and(canonicalizeJsonStructure($version->content))->toBe(canonicalizeJsonStructure($article))
        ->and($generatedContent->versions()->count())->toBe(1);

    $response->assertRedirect($detailUrl);
    expect(parse_url($detailUrl, PHP_URL_PATH))
        ->toBe('/projects/'.$project->uuid.'/generated-content/'.$generatedContent->uuid);

    Http::assertSent(function (Request $request) use ($prompt): bool {
        return $request->hasHeader('Authorization', 'Bearer personal-test-openai-key')
            && $request['input'] === $prompt
            && $request['text']['format']['type'] === 'json_schema';
    });

    $this->get($detailUrl)
        ->assertOk()
        ->assertSee('News article')
        ->assertSee('Version 1')
        ->assertSee('The Harbor Ledger')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('generated content cannot be viewed through another project url', function () {
    $ownerProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($ownerProject)->create();
    $otherUser = User::factory()->create();

    $this->actingAs($ownerProject->user)
        ->get(route('projects.generated-content.show', [$otherProject, $generatedContent]))
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->get(route('projects.generated-content.show', [$ownerProject, $generatedContent]))
        ->assertForbidden()
        ->assertDontSee($generatedContent->title);

    $this->assertDatabaseHas('generated_contents', [
        'id' => $generatedContent->id,
        'project_id' => $ownerProject->id,
    ]);
});

test('project detail lists its generated content newest first and omits other projects', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $olderContent = GeneratedContent::factory()->for($project)->create([
        'title' => 'Earlier story',
        'created_at' => now()->subDay(),
    ]);
    $newerContent = GeneratedContent::factory()->for($project)->create([
        'title' => 'Latest story',
        'created_at' => now(),
    ]);
    GeneratedContent::factory()->for($otherProject)->create(['title' => 'Private other story']);

    $this->actingAs($project->user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeInOrder(['Latest story', 'Earlier story'])
        ->assertSee(route('projects.generated-content.show', [$project, $newerContent]))
        ->assertDontSee('Private other story')
        ->assertDontSee(route('projects.generated-content.show', [$otherProject, $otherProject->generatedContents()->first()]));
});

test('provider failure returns safely to the form and preserves submitted values', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);
    $prompt = 'Write a story that must remain available after a provider failure.';

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => ['message' => 'Sensitive provider response body must not be shown.'],
        ], 503),
    ]);
    Log::spy();

    $this->followingRedirects()
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => $prompt,
            'api_key' => 'sensitive-browser-provided-key',
        ])
        ->assertOk()
        ->assertSee('OpenAI is temporarily unavailable. Please try again later.')
        ->assertSee($prompt)
        ->assertDontSee('Sensitive provider response body must not be shown.')
        ->assertSessionMissingInput('api_key');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => $message === 'OpenAI generation request failed.'
            && $context === ['failure_kind' => 'temporary_provider', 'http_status' => 503]);

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});

test('structured generation failure creates no records and preserves form input', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->user);
    $prompt = 'Write an article, even if the provider returns invalid structure.';

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_content_ui_invalid',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['headline' => 'Missing required properties'], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);
    Log::spy();

    $this->followingRedirects()
        ->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            ...personalGenerationAttemptFields($project),
            'content_type' => 'news_article',
            'prompt' => $prompt,
        ])
        ->assertOk()
        ->assertSee('OpenAI did not return valid structured content. Submit again to start a new attempt.')
        ->assertSee($prompt);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($prompt): bool {
            $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

            return $message === 'Structured content generation failed.'
                && $context['category'] === 'schema_validation'
                && is_array($context['field_paths'])
                && ! str_contains($serializedContext, $prompt)
                && ! str_contains($serializedContext, 'Missing required properties');
        });

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});
