<?php

use App\Services\OpenAI\OpenAiClient;
use App\Services\OpenAI\OpenAiException;
use App\Services\OpenAI\OpenAiResponseResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'test-api-key');
    config()->set('services.openai.model', 'configured-test-model');
    config()->set('services.openai.timeout', 30);
});

test('a non-streaming response request uses the configured model and server authentication', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_test_123',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Generated ',
                    'annotations' => [],
                ], [
                    'type' => 'output_text',
                    'text' => 'text.',
                    'annotations' => [],
                ]],
            ], [
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => ' Another item.',
                    'annotations' => [],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 8,
                'total_tokens' => 20,
            ],
        ]),
    ]);

    $result = app(OpenAiClient::class)->createResponse('Follow these instructions.', 'Use this input.');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['model'] === 'configured-test-model'
            && $request['instructions'] === 'Follow these instructions.'
            && $request['input'] === 'Use this input.'
            && $request['store'] === false
            && $request['stream'] === false
            && ! isset($request['text']);
    });

    expect($result)->toBeInstanceOf(OpenAiResponseResult::class)
        ->and($result->text)->toBe('Generated text. Another item.')
        ->and($result->responseId)->toBe('resp_test_123')
        ->and($result->model)->toBe('actual-test-model')
        ->and($result->inputTokens)->toBe(12)
        ->and($result->outputTokens)->toBe(8)
        ->and($result->totalTokens)->toBe(20);
});

test('an explicitly supplied api key uses the same request flow', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_user_key',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Text.']],
            ]],
        ]),
    ]);

    app(OpenAiClient::class)->createResponse('Instructions.', 'Input.', 'user-specific-key');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer user-specific-key'));
});

test('token usage fields may be absent', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_no_usage',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => 'Text.']],
            ]],
        ]),
    ]);

    $result = app(OpenAiClient::class)->createResponse('Instructions.', 'Input.');

    expect($result->inputTokens)->toBeNull()
        ->and($result->outputTokens)->toBeNull()
        ->and($result->totalTokens)->toBeNull();
});

test('a missing api key is rejected before sending a request', function () {
    config()->set('services.openai.api_key', '');

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class, 'The OpenAI API key is not configured.');

    Http::assertNothingSent();
});

test('empty instructions or input are rejected before sending a request', function (string $instructions, string $input) {
    expect(fn () => app(OpenAiClient::class)->createResponse($instructions, $input))
        ->toThrow(InvalidArgumentException::class, 'Instructions and input must not be empty.');

    Http::assertNothingSent();
})->with([
    'empty instructions' => ['', 'Input.'],
    'empty input' => ['Instructions.', '  '],
]);

test('authentication, rate limit, and server errors do not expose response bodies', function (int $statusCode) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => ['message' => 'sensitive provider response body'],
        ], $statusCode),
    ]);

    try {
        app(OpenAiClient::class)->createResponse('Instructions.', 'Input.');
        test()->fail('Expected the OpenAI request to fail.');
    } catch (OpenAiException $exception) {
        expect($exception->statusCode)->toBe($statusCode)
            ->and($exception->getMessage())->toContain("HTTP status {$statusCode}")
            ->and($exception->getMessage())->not->toContain('sensitive provider response body');
    }

    Http::assertSentCount(1);
})->with([
    'authentication failure' => 401,
    'rate limiting' => 429,
    'server error' => 500,
]);

test('network errors are wrapped without retrying the request', function () {
    $attempts = 0;

    Http::fake([
        'https://api.openai.com/v1/responses' => function () use (&$attempts): never {
            $attempts++;

            throw new ConnectionException('network unavailable');
        },
    ]);

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class, 'The OpenAI request failed due to a network error.');

    expect($attempts)->toBe(1);
});

test('incomplete responses are rejected', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_incomplete',
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
        ]),
    ]);

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class, 'The OpenAI response was incomplete.');
});

test('provider refusals are rejected without exposing refusal text', function () {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_refusal',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'refusal',
                    'refusal' => 'sensitive refusal detail',
                ]],
            ]],
        ]),
    ]);

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class, 'The OpenAI response was refused.');
});

test('empty output text is rejected', function (string $text) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_empty_text',
            'status' => 'completed',
            'model' => 'actual-test-model',
            'output' => [[
                'type' => 'message',
                'content' => [['type' => 'output_text', 'text' => $text]],
            ]],
        ]),
    ]);

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class, 'The OpenAI response did not contain generated text.');
})->with([
    'empty string' => '',
    'whitespace only' => '     ',
]);

test('malformed response bodies are rejected', function (mixed $body) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response($body, 200, ['Content-Type' => 'application/json']),
    ]);

    expect(fn () => app(OpenAiClient::class)->createResponse('Instructions.', 'Input.'))
        ->toThrow(OpenAiException::class);
})->with([
    'invalid json' => 'not-json',
    'missing output text' => [[
        'id' => 'resp_missing_text',
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [],
    ]],
    'missing text field' => [[
        'id' => 'resp_missing_text_field',
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text']],
        ]],
    ]],
    'malformed usage' => [[
        'id' => 'resp_bad_usage',
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [['type' => 'output_text', 'text' => 'Text.']],
        ]],
        'usage' => ['input_tokens' => '12'],
    ]],
]);
