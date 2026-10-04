<?php

use App\Actions\GenerateStructuredContent;
use App\Actions\StructuredContentGenerationResult;
use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Services\OpenAI\OpenAiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'configured-test-key');
    config()->set('services.openai.model', 'configured-test-model');
    config()->set('services.openai.timeout', 30);
});

test('registered content is generated as strictly structured and locally validated output', function () {
    $contentType = app(ContentTypeRegistry::class)->get('news_article');
    $prompt = 'Write a short article about an unusual radio signal.';
    $article = [
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_article_123',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($article, JSON_THROW_ON_ERROR),
                    'annotations' => [],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ]),
    ]);

    $result = app(GenerateStructuredContent::class)->handle('news_article', $prompt);

    Http::assertSent(function (Request $request) use ($contentType, $prompt): bool {
        $schema = $contentType->outputSchema();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer configured-test-key')
            && $request['instructions'] === $contentType->promptInstructions()
            && $request['input'] === $prompt
            && $request['instructions'] !== $prompt
            && $request['store'] === false
            && $request['stream'] === false
            && $request['text']['format'] === [
                'type' => 'json_schema',
                'name' => 'generated_content',
                'strict' => true,
                'schema' => $schema,
            ]
            && ! isset($request['response_format']);
    });

    expect($result)->toBeInstanceOf(StructuredContentGenerationResult::class)
        ->and($result->content)->toBe($article)
        ->and($result->response->responseId)->toBe('resp_article_123')
        ->and($result->response->model)->toBe('actual-test-model')
        ->and($result->response->inputTokens)->toBe(120)
        ->and($result->response->outputTokens)->toBe(80)
        ->and($result->response->totalTokens)->toBe(200);

    expect($contentType->outputSchema()['type'])->toBe('object')
        ->and($contentType->outputSchema()['additionalProperties'])->toBeFalse()
        ->and($contentType->outputSchema()['required'])->toBe(array_keys($contentType->outputSchema()['properties']))
        ->and(array_keys($contentType->validationRules()))->toBe($contentType->outputSchema()['required'])
        ->and($contentType->outputSchema()['properties']['published_at']['format'])->toBe('date-time')
        ->and($contentType->validationRules()['published_at'])->toBe(['required', 'date']);
});

test('an explicitly supplied api key is forwarded to the OpenAI client', function () {
    $article = [
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ];

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_user_key',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($article, JSON_THROW_ON_ERROR)]],
            ]],
        ]),
    ]);

    app(GenerateStructuredContent::class)->handle('news_article', 'Write an article.', 'user-specific-test-key');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer user-specific-test-key'));
});

test('unknown content types are rejected without making a request', function () {
    expect(fn () => app(GenerateStructuredContent::class)->handle('unknown_type', 'Write an article.'))
        ->toThrow(InvalidArgumentException::class, 'Content type key [unknown_type] is not registered.');

    Http::assertNothingSent();
});

test('empty generation prompts are rejected without making a request', function () {
    expect(fn () => app(GenerateStructuredContent::class)->handle('news_article', '   '))
        ->toThrow(InvalidArgumentException::class, 'A generation prompt is required.');

    Http::assertNothingSent();
});

test('malformed generated json is rejected without exposing the response text', function () {
    $malformedJson = '{"headline":';

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_malformed_json',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => $malformedJson]],
            ]],
        ]),
    ]);

    try {
        app(GenerateStructuredContent::class)->handle('news_article', 'PRIVATE PROMPT');
        test()->fail('Malformed JSON should be rejected.');
    } catch (StructuredContentGenerationException $exception) {
        expect($exception->getMessage())->toBe('The generated response was not valid JSON.')
            ->and($exception->diagnosticCategory)->toBe('json_decode')
            ->and($exception->fieldPaths)->toBe([])
            ->and($exception->getMessage())->not->toContain($malformedJson);
    }
});

test('generated json must have an object root', function (string $json) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_wrong_root',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => $json]],
            ]],
        ]),
    ]);

    expect(fn () => app(GenerateStructuredContent::class)->handle('news_article', 'Write an article.'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated response must be a JSON object.');
})->with([
    'a list' => '[]',
    'a string' => '"text"',
    'a number' => '42',
    'a boolean' => 'true',
    'null' => 'null',
]);

test('generated objects are rejected when they fail the content type validation rules', function (array $article) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_invalid_article',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($article, JSON_THROW_ON_ERROR)]],
            ]],
        ]),
    ]);

    expect(fn () => app(GenerateStructuredContent::class)->handle('news_article', 'Write an article.'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated content failed validation.');
})->with([
    'missing required field' => [[
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
    ]],
    'invalid string field type' => [[
        'headline' => 42,
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ]],
    'invalid date field' => [[
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => 'not-a-date',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ]],
    'headline exceeds the domain length limit' => [[
        'headline' => str_repeat('a', 256),
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ]],
    'publication exceeds the domain length limit' => [[
        'headline' => 'Signal Over North Harbor',
        'publication' => str_repeat('a', 256),
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
    ]],
    'unexpected additional property' => [[
        'headline' => 'Signal Over North Harbor',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => 'Residents reported hearing a strange signal overnight.',
        'unapproved' => 'extra value',
    ]],
]);

test('provider refusals and incomplete responses propagate as safe OpenAI errors', function (array $providerResponse, string $message) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response($providerResponse),
    ]);

    expect(fn () => app(GenerateStructuredContent::class)->handle('news_article', 'Write an article.'))
        ->toThrow(OpenAiException::class, $message);
})->with([
    'provider refusal' => [[
        'id' => 'resp_refusal',
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'refusal', 'refusal' => 'private refusal text']],
        ]],
    ], 'The OpenAI response was refused.'],
    'incomplete provider response' => [[
        'id' => 'resp_incomplete',
        'status' => 'incomplete',
    ], 'The OpenAI response was incomplete.'],
]);
