<?php

use App\Actions\GenerateAndPersistContent;
use App\Actions\GenerateStructuredContent;
use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'normalization-test-key');
    config()->set('services.openai.model', 'normalization-test-model');
    config()->set('services.openai.timeout', 30);
});

function quoteNormalizationFixture(string $contentType): array
{
    if ($contentType === 'forum_thread') {
        return [
            'forum_name' => 'The Board',
            'thread_title' => 'A thread',
            'category' => 'General',
            'started_at' => '2025-06-15T10:00:00+00:00',
            'posts' => [
                ['post_number' => 1, 'author' => 'First', 'posted_at' => '2025-06-15T10:00:00+00:00', 'body' => 'The café was quiet... then the lights appeared.', 'reply_to_post_number' => null, 'quote' => null],
                ['post_number' => 2, 'author' => 'Second', 'posted_at' => '2025-06-15T10:01:00+00:00', 'body' => 'That sounds strange.', 'reply_to_post_number' => 1, 'quote' => ['post_number' => 1, 'text' => ' “The café was quiet… then the lights appeared.” ']],
            ],
        ];
    }

    return [
        'network' => 'SchreckNet',
        'channel' => 'harbor/whispers',
        'thread_title' => 'A thread',
        'started_at' => '2025-06-15T10:00:00+00:00',
        'messages' => [
            ['message_number' => 1, 'handle' => 'First', 'posted_at' => '2025-06-15T10:00:00+00:00', 'body' => 'The café was quiet... then the lights appeared.', 'reply_to_message_number' => null, 'quote' => null],
            ['message_number' => 2, 'handle' => 'Second', 'posted_at' => '2025-06-15T10:01:00+00:00', 'body' => 'That sounds strange.', 'reply_to_message_number' => 1, 'quote' => ['message_number' => 1, 'text' => ' “The café was quiet… then the lights appeared.” ']],
        ],
    ];
}

function quoteNormalizationFields(string $contentType): array
{
    return $contentType === 'forum_thread'
        ? ['posts', 'post_number', 'reply_to_post_number']
        : ['messages', 'message_number', 'reply_to_message_number'];
}

function quoteNormalizationResponse(array $content): array
{
    return [
        'id' => 'resp_quote_normalization',
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]],
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
    ];
}

test('generated discussion quotes recover only a unique exact source excerpt for both types', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    [$collection, $numberField, $replyField] = quoteNormalizationFields($contentType);
    $content = quoteNormalizationFixture($contentType);
    $nullVariant = $content;
    $nullVariant[$collection][1]['quote'] = null;
    $content[$collection][1][$replyField] = null;

    $normalization = $definition->normalizeGeneratedContent($content);
    $nullNormalization = $definition->normalizeGeneratedContent($nullVariant);

    expect($normalization['content'][$collection][1]['quote']['text'])
        ->toBe('The café was quiet... then the lights appeared.')
        ->and($normalization['content'][$collection][1]['quote'][$numberField])->toBe(1)
        ->and($normalization['content'][$collection][1][$replyField])->toBeNull()
        ->and($normalization['quotes_normalized'])->toBe(1)
        ->and($normalization['quotes_preserved'])->toBe(0)
        ->and($normalization['quotes_dropped'])->toBe(0)
        ->and($definition->semanticValidationErrors($normalization['content']))->toBe([])
        ->and($nullNormalization['content'][$collection][1]['quote'])->toBeNull()
        ->and($nullNormalization['quotes_preserved'])->toBe(0)
        ->and($nullNormalization['quotes_normalized'])->toBe(0)
        ->and($nullNormalization['quotes_dropped'])->toBe(0);

    $alreadyValid = quoteNormalizationFixture($contentType);
    $alreadyValid[$collection][1]['quote']['text'] = 'café was quiet';
    $alreadyValidNormalization = $definition->normalizeGeneratedContent($alreadyValid);

    expect($alreadyValidNormalization['content'][$collection][1]['quote']['text'])
        ->toBe('café was quiet')
        ->and($alreadyValidNormalization['quotes_preserved'])->toBe(1)
        ->and($alreadyValidNormalization['quotes_normalized'])->toBe(0);
})->with(['forum_thread', 'schrecknet_thread']);

test('ambiguous and unrecoverable generated quote text is dropped without losing a valid reply', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    [$collection, $numberField, $replyField] = quoteNormalizationFields($contentType);
    $content = quoteNormalizationFixture($contentType);
    $content[$collection][0]['body'] = '‘café’ café';
    $content[$collection][1][$replyField] = 1;
    $content[$collection][1]['quote']['text'] = ' ‘café’ ';

    $ambiguous = $definition->normalizeGeneratedContent($content);

    expect($ambiguous['content'][$collection][1]['quote'])->toBeNull()
        ->and($ambiguous['content'][$collection][1][$replyField])->toBe(1)
        ->and($ambiguous['quotes_dropped'])->toBe(1)
        ->and($definition->semanticValidationErrors($ambiguous['content']))->toBe([]);

    $content[$collection][0]['body'] = 'The source message is unrelated.';
    $content[$collection][1]['quote']['text'] = 'a plausible paraphrase';
    $unrecoverable = $definition->normalizeGeneratedContent($content);

    expect($unrecoverable['content'][$collection][1]['quote'])->toBeNull()
        ->and($unrecoverable['content'][$collection][1][$replyField])->toBe(1)
        ->and($unrecoverable['quotes_dropped'])->toBe(1)
        ->and($definition->semanticValidationErrors($unrecoverable['content']))->toBe([]);
})->with(['forum_thread', 'schrecknet_thread']);

test('blank generated quote text is dropped when its source reference is valid', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    [$collection, , $replyField] = quoteNormalizationFields($contentType);
    $content = quoteNormalizationFixture($contentType);
    $content[$collection][1]['quote']['text'] = " \t";

    $normalization = $definition->normalizeGeneratedContent($content);

    expect($normalization['content'][$collection][1]['quote'])->toBeNull()
        ->and($normalization['content'][$collection][1][$replyField])->toBe(1)
        ->and($normalization['quotes_dropped'])->toBe(1)
        ->and($definition->semanticValidationErrors($normalization['content']))->toBe([]);
})->with(['forum_thread', 'schrecknet_thread']);

test('invalid quote references numbering and chronology remain semantic errors', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    [$collection, $numberField] = quoteNormalizationFields($contentType);
    $content = quoteNormalizationFixture($contentType);
    $content[$collection][1]['quote'][$numberField] = 2;

    $invalidReference = $definition->normalizeGeneratedContent($content);

    expect($invalidReference['content'][$collection][1]['quote'])
        ->toBe($content[$collection][1]['quote'])
        ->and($invalidReference['quotes_dropped'])->toBe(0)
        ->and($definition->semanticValidationErrors($invalidReference['content']))
        ->toHaveKey($collection.'.1.quote.'.$numberField);

    $content = quoteNormalizationFixture($contentType);
    $content[$collection][1][$numberField] = 3;
    $content[$collection][1]['quote']['text'] = 'not an exact quote';
    $content[$collection][1]['posted_at'] = '2025-06-15T09:59:00+00:00';

    $invalidOtherSemantics = $definition->normalizeGeneratedContent($content);

    expect($invalidOtherSemantics['content'][$collection][1]['quote'])
        ->toBe($content[$collection][1]['quote'])
        ->and($invalidOtherSemantics['quotes_dropped'])->toBe(0)
        ->and($definition->semanticValidationErrors($invalidOtherSemantics['content']))
        ->toHaveKeys([$collection.'.1.'.$numberField, $collection.'.1.posted_at']);
})->with(['forum_thread', 'schrecknet_thread']);

test('structured generation persists only validated normalized quote content and logs aggregate counts', function (string $contentType) {
    $content = quoteNormalizationFixture($contentType);
    [$collection] = quoteNormalizationFields($contentType);
    $privateQuote = $content[$collection][1]['quote']['text'];
    $privateSource = $content[$collection][0]['body'];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(quoteNormalizationResponse($content))]);
    Log::spy();
    $project = Project::factory()->create();
    $historicalContent = GeneratedContent::factory()->for($project)->create(['content_type' => $contentType]);
    $historicalVersion = GeneratedContentVersion::factory()->for($historicalContent)->create([
        'content' => $content,
        'context_snapshot' => ['prompt' => 'historical context'],
    ]);
    $originalHistoricalContent = $historicalVersion->content;

    $result = app(GenerateAndPersistContent::class)->handle(
        $project,
        $contentType,
        'PRIVATE PROMPT',
        'private-test-key',
    );

    $normalizedText = 'The café was quiet... then the lights appeared.';

    expect($result->version->fresh()->content[$collection][1]['quote']['text'])->toBe($normalizedText)
        ->and(app(ContentTypeRegistry::class)->get($contentType)->semanticValidationErrors($result->version->fresh()->content))->toBe([])
        ->and(GeneratedContentVersion::query()->count())->toBe(2)
        ->and($historicalVersion->fresh()->content)->toBe($originalHistoricalContent)
        ->and($historicalVersion->fresh()->context_snapshot)->toBe(['prompt' => 'historical context']);

    Log::shouldHaveReceived('info')->once()->with('Generated discussion quote normalization completed.', [
        'content_type' => $contentType,
        'quotes_preserved' => 0,
        'quotes_normalized' => 1,
        'quotes_dropped' => 0,
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer private-test-key'));

    $persistedContent = json_encode($result->version->fresh()->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($persistedContent)
        ->not->toContain('PRIVATE PROMPT')
        ->and($persistedContent)->not->toContain($privateQuote)
        ->and($persistedContent)->toContain($privateSource);
})->with(['forum_thread', 'schrecknet_thread']);

test('unrecoverable generated quote text is dropped before the immutable version is persisted', function (string $contentType) {
    $content = quoteNormalizationFixture($contentType);
    [$collection, , $replyField] = quoteNormalizationFields($contentType);
    $content[$collection][1]['quote']['text'] = 'a plausible paraphrase';
    $content[$collection][1][$replyField] = 1;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(quoteNormalizationResponse($content))]);
    Log::spy();

    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        $contentType,
        'Write a discussion.',
        'private-test-key',
    );

    $persisted = $result->version->fresh()->content;

    expect($persisted[$collection][1]['quote'])->toBeNull()
        ->and($persisted[$collection][1][$replyField])->toBe(1)
        ->and(app(ContentTypeRegistry::class)->get($contentType)->semanticValidationErrors($persisted))->toBe([]);

    Log::shouldHaveReceived('info')->once()->with('Generated discussion quote normalization completed.', [
        'content_type' => $contentType,
        'quotes_preserved' => 0,
        'quotes_normalized' => 0,
        'quotes_dropped' => 1,
    ]);
    Http::assertSentCount(1);
})->with(['forum_thread', 'schrecknet_thread']);

test('failed final semantic validation reports safe quote normalization counters', function (string $contentType) {
    $content = quoteNormalizationFixture($contentType);
    [$collection, $numberField] = quoteNormalizationFields($contentType);
    $content[$collection][1]['quote']['text'] = 'not an exact quote';
    $privateQuote = $content[$collection][1]['quote']['text'];
    $privateSource = $content[$collection][0]['body'];
    $content[$collection][1][$numberField] = 8;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(quoteNormalizationResponse($content))]);

    try {
        app(GenerateStructuredContent::class)->handle($contentType, 'PRIVATE PROMPT', 'private-test-key');
        test()->fail('Invalid numbering should still fail structured generation.');
    } catch (StructuredContentGenerationException $exception) {
        expect($exception->diagnosticCategory)->toBe('semantic_validation')
            ->and($exception->diagnosticContext)->toMatchArray([
                'quotes_preserved' => 0,
                'quotes_normalized' => 0,
                'quotes_dropped' => 0,
            ])
            ->and(json_encode($exception->diagnosticContext))->not->toContain('PRIVATE')
            ->and(json_encode($exception->diagnosticContext))->not->toContain($privateQuote)
            ->and(json_encode($exception->diagnosticContext))->not->toContain($privateSource);
    }
})->with(['forum_thread', 'schrecknet_thread']);
