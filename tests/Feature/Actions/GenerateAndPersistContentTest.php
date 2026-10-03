<?php

use App\Actions\GenerateAndPersistContent;
use App\Actions\GeneratedContentGenerationResult;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Contracts\ContentTypeDefinition;
use App\Enums\GeneratedContentVersionOrigin;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Services\OpenAI\OpenAiException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'configured-test-key');
    config()->set('services.openai.model', 'configured-test-model');
    config()->set('services.openai.timeout', 30);
});

test('generation is persisted as the first immutable ai generated version', function () {
    $project = Project::factory()->create();
    $prompt = 'Write a short article about an unusual radio signal.';
    $article = [
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ];
    $baselineTransactionLevel = DB::transactionLevel();
    $insertTransactionLevels = [];

    DB::listen(function (QueryExecuted $query) use (&$insertTransactionLevels): void {
        if (str_starts_with(strtolower($query->sql), 'insert into')
            && (str_contains($query->sql, 'generated_contents') || str_contains($query->sql, 'generated_content_versions'))) {
            $insertTransactionLevels[] = DB::transactionLevel();
        }
    });

    Http::fake(function (Request $request) use ($baselineTransactionLevel, $project, $prompt): PromiseInterface {
        expect(DB::transactionLevel())->toBe($baselineTransactionLevel)
            ->and($project->generatedContents()->count())->toBe(0)
            ->and($request->hasHeader('Authorization', 'Bearer configured-test-key'))->toBeTrue()
            ->and($request['instructions'])->toBe(app(ContentTypeRegistry::class)->get('news_article')->promptInstructions())
            ->and($request['input'])->toBe($prompt);

        return Http::response([
            'id' => 'resp_persisted_article',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'headline' => 'Signal Over North Harbor',
                        'publication' => 'The Harbor Ledger',
                        'published_at' => '2025-06-15T10:30:00Z',
                        'body' => 'Residents reported hearing a strange signal overnight.',
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ]);
    });

    $result = app(GenerateAndPersistContent::class)->handle($project, 'news_article', $prompt);

    expect($result)->toBeInstanceOf(GeneratedContentGenerationResult::class)
        ->and($result->generatedContent->project->is($project))->toBeTrue()
        ->and($result->generatedContent->content_type)->toBe('news_article')
        ->and($result->generatedContent->title)->toBe('Signal Over North Harbor')
        ->and($result->version->generated_content_id)->toBe($result->generatedContent->id)
        ->and($result->version->version_number)->toBe(1)
        ->and($result->version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and($result->version->based_on_version_id)->toBeNull()
        ->and($result->version->fresh()->content)->toBe($article)
        ->and($result->generatedContent->versions)->toHaveCount(1)
        ->and($insertTransactionLevels)->toHaveCount(2)
        ->and($insertTransactionLevels[0])->toBeGreaterThan($baselineTransactionLevel)
        ->and($insertTransactionLevels[1])->toBeGreaterThan($baselineTransactionLevel);

    $persistedInputs = json_encode([
        $result->version->fresh()->context_snapshot,
        $result->version->fresh()->generation_metadata,
    ], JSON_THROW_ON_ERROR);

    expect($result->version->fresh()->context_snapshot)->toBe([
        'content_type' => 'news_article',
        'prompt' => $prompt,
        'instructions' => 'Write a fictional news article with a clear headline, publication, publication date, and article body.',
    ])->and($result->version->fresh()->generation_metadata)->toBe([
        'provider' => 'openai',
        'model' => 'actual-test-model',
        'response_id' => 'resp_persisted_article',
        'input_tokens' => 120,
        'output_tokens' => 80,
        'total_tokens' => 200,
        'content_type' => 'news_article',
    ])->and($result->generationMetadata)->toBe($result->version->generation_metadata)
        ->and($persistedInputs)->not->toContain('configured-test-key');
});

test('a content type may omit its generated content title', function () {
    $project = Project::factory()->create();
    $contentType = new class implements ContentTypeDefinition
    {
        public function key(): string
        {
            return 'untitled_content';
        }

        public function label(): string
        {
            return 'Untitled content';
        }

        public function presentationView(): ?string
        {
            return null;
        }

        public function promptInstructions(): string
        {
            return 'Write untitled content.';
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
    };

    app()->instance(ContentTypeRegistry::class, new ContentTypeRegistry($contentType));

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_untitled_content',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['body' => 'Generated body.'], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    $result = app(GenerateAndPersistContent::class)->handle($project, 'untitled_content', 'Write content.');

    expect($result->generatedContent->title)->toBeNull()
        ->and($result->version->fresh()->content)->toBe(['body' => 'Generated body.']);
});

test('generation metadata stores null for unavailable token usage', function () {
    $project = Project::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_without_usage',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'headline' => 'Signal Over North Harbor',
                        'publication' => 'The Harbor Ledger',
                        'published_at' => '2025-06-15T10:30:00Z',
                        'body' => 'Residents reported hearing a strange signal overnight.',
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    $result = app(GenerateAndPersistContent::class)->handle($project, 'news_article', 'Write an article.');

    expect($result->generationMetadata)->toMatchArray([
        'input_tokens' => null,
        'output_tokens' => null,
        'total_tokens' => null,
    ]);
});

test('an explicit per request api key overrides configured credentials', function () {
    $project = Project::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_explicit_key',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'headline' => 'Signal Over North Harbor',
                        'publication' => 'The Harbor Ledger',
                        'published_at' => '2025-06-15T10:30:00Z',
                        'body' => 'Residents reported hearing a strange signal overnight.',
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    app(GenerateAndPersistContent::class)->handle($project, 'news_article', 'Write an article.', 'request-specific-key');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer request-specific-key'));
});

test('unknown content type is rejected before a request or persistence', function () {
    $project = Project::factory()->create();

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'unknown_type', 'Write something.'))
        ->toThrow(InvalidArgumentException::class, 'Content type key [unknown_type] is not registered.');

    Http::assertNothingSent();
    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});

test('an unsaved project is rejected before making a provider request', function () {
    expect(fn () => app(GenerateAndPersistContent::class)->handle(new Project, 'news_article', 'Write something.'))
        ->toThrow(InvalidArgumentException::class, 'Generated content must belong to an existing project.');

    Http::assertNothingSent();
});

test('provider failure creates no content or version', function () {
    $project = Project::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'unavailable']], 503),
    ]);

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'news_article', 'Write something.'))
        ->toThrow(OpenAiException::class, 'The OpenAI request failed with HTTP status 503.');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});

test('structured validation failure creates no content or version', function () {
    $project = Project::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_invalid_structured_content',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['headline' => 'Missing fields'], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'news_article', 'Write something.'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated content failed validation.');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});

test('version persistence failure after content creation rolls back both records', function () {
    $project = Project::factory()->create();

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_persistence_failure',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'headline' => 'Signal Over North Harbor',
                        'publication' => 'The Harbor Ledger',
                        'published_at' => '2025-06-15T10:30:00Z',
                        'body' => 'Residents reported hearing a strange signal overnight.',
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    $shouldFailVersionInsert = true;
    $generatedContentExistedBeforeVersionInsert = false;
    DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (
        &$shouldFailVersionInsert,
        &$generatedContentExistedBeforeVersionInsert,
        $project,
    ): void {
        if ($shouldFailVersionInsert
            && str_starts_with(strtolower($query), 'insert into')
            && str_contains($query, 'generated_content_versions')) {
            $shouldFailVersionInsert = false;
            $generatedContentExistedBeforeVersionInsert = GeneratedContent::query()
                ->where('project_id', $project->getKey())
                ->exists();

            throw new RuntimeException('Simulated version persistence failure.');
        }
    });

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'news_article', 'Write something.'))
        ->toThrow(RuntimeException::class, 'Simulated version persistence failure.');

    expect($generatedContentExistedBeforeVersionInsert)->toBeTrue()
        ->and($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContent::query()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});
