<?php

use App\Actions\ContinueGeneratedContent;
use App\Actions\GenerationAttemptManager;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\GenerationAttemptException;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->artisan('migrate:fresh')->assertExitCode(0);
    Http::preventStrayRequests();
    config()->set('services.openai.model', 'continuation-test-model');
    config()->set('services.openai.timeout', 30);
});

function continuationGenerationSource(string $type): array
{
    $entry = ['posted_at' => '2026-10-05T10:00:00+00:00', 'body' => 'The old signal read café closed.'];

    if ($type === 'forum_thread') {
        return [
            'forum_name' => 'Harbor Board',
            'thread_title' => 'The old signal',
            'category' => 'Local',
            'started_at' => '2026-10-05T09:59:00+00:00',
            'posts' => [
                ['post_number' => 1, 'author' => 'Mica', ...$entry, 'reply_to_post_number' => null, 'quote' => null],
                ['post_number' => 2, 'author' => 'Rook', 'posted_at' => '2026-10-05T10:01:00+00:00', 'body' => 'The old signal read café closed.', 'reply_to_post_number' => 1, 'quote' => null],
            ],
        ];
    }

    return [
        'network' => 'SchreckNet',
        'channel' => 'harbor/quiet',
        'thread_title' => 'The old signal',
        'started_at' => '2026-10-05T09:59:00+00:00',
        'messages' => [
            ['message_number' => 1, 'handle' => 'Mica', ...$entry, 'reply_to_message_number' => null, 'quote' => null],
            ['message_number' => 2, 'handle' => 'Rook', 'posted_at' => '2026-10-05T10:01:00+00:00', 'body' => 'The old signal read café closed.', 'reply_to_message_number' => 1, 'quote' => null],
        ],
    ];
}

/** @return array<string, mixed> */
function continuationGenerationSourceWithEntries(string $type, int $entryCount): array
{
    $source = continuationGenerationSource($type);
    $collection = $type === 'forum_thread' ? 'posts' : 'messages';
    $numberField = $type === 'forum_thread' ? 'post_number' : 'message_number';
    $replyField = $type === 'forum_thread' ? 'reply_to_post_number' : 'reply_to_message_number';
    $authorField = $type === 'forum_thread' ? 'author' : 'handle';
    $source[$collection] = [];

    for ($number = 1; $number <= $entryCount; $number++) {
        $source[$collection][] = [
            $numberField => $number,
            $authorField => 'Writer '.$number,
            'posted_at' => '2026-10-05T10:00:00+00:00',
            'body' => 'Existing entry '.$number,
            $replyField => $number === 1 ? null : 1,
            'quote' => null,
        ];
    }

    return $source;
}

function continuationGenerationProposal(string $type, bool $withSecondEntry = false): array
{
    if ($type === 'forum_thread') {
        $entries = [[
            'author' => 'Ash',
            'posted_at' => '2026-10-05T10:02:00+00:00',
            'body' => 'I checked the west entrance.',
            'reply_to_post_number' => 2,
            'quote' => ['post_number' => 1, 'text' => 'The old signal read café closed.'],
        ]];
        if ($withSecondEntry) {
            $entries[] = [
                'author' => 'Mica',
                'posted_at' => '2026-10-05T10:03:00+00:00',
                'body' => 'That is not what I saw.',
                'reply_to_post_number' => 3,
                'quote' => ['post_number' => 3, 'text' => 'I checked the west entrance.'],
            ];
        }

        return ['entries' => $entries];
    }

    $entries = [[
        'handle' => 'Ash',
        'posted_at' => '2026-10-05T10:02:00+00:00',
        'body' => 'I checked the west entrance.',
        'reply_to_message_number' => 2,
        'quote' => ['message_number' => 1, 'text' => 'The old signal read café closed.'],
    ]];
    if ($withSecondEntry) {
        $entries[] = [
            'handle' => 'Mica',
            'posted_at' => '2026-10-05T10:03:00+00:00',
            'body' => 'That is not what I saw.',
            'reply_to_message_number' => 3,
            'quote' => ['message_number' => 3, 'text' => 'I checked the west entrance.'],
        ];
    }

    return ['entries' => $entries];
}

function continuationProviderResponse(array $proposal, string $id = 'resp_continuation'): array
{
    return [
        'id' => $id,
        'status' => 'completed',
        'model' => 'actual-continuation-model',
        'usage' => ['input_tokens' => 123, 'output_tokens' => 45, 'total_tokens' => 168],
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]],
        ]],
    ];
}

function issueContinuationAttempt(GeneratedContent $content, GeneratedContentVersion $source): string
{
    return app(GenerationAttemptManager::class)->tokenForForm(
        $content->project->user,
        $content->project,
        null,
        $content,
        $source,
    );
}

function continueContent(GeneratedContent $content, GeneratedContentVersion $source, string $token, array $overrides = []): mixed
{
    return app(ContinueGeneratedContent::class)->handle(
        $content->project->user,
        $content,
        $source,
        $overrides['instructions'] ?? 'Let the discussion continue as the signal changes.',
        $overrides['count'] ?? 1,
        $token,
        $overrides['api_key'] ?? 'personal-continuation-key',
        $overrides['references'] ?? [],
    );
}

function createContinuationContent(string $type, array $source = []): array
{
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => $type]);
    $sourceVersion = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 1,
        'content' => $source === [] ? continuationGenerationSource($type) : $source,
    ]);

    return [$project, $content, $sourceVersion];
}

test('continuation generates only requested additions and persists immutable provenance for both types', function (string $type) {
    [$project, $content, $source] = createContinuationContent($type);
    $stableArtifactTitle = $content->title;
    $sourceValue = $source->content;
    $token = issueContinuationAttempt($content, $source);
    $project->context()->create(['setting' => 'Bellweather has no west exit.']);
    $reference = GeneratedContent::factory()->for($project)->create(['content_type' => $type]);
    $referenceVersion = $reference->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => continuationGenerationSource($type),
    ]);
    $proposal = continuationGenerationProposal($type, true);
    $contextReads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$contextReads): void {
        if (str_contains($query->sql, 'project_contexts')) {
            $contextReads++;
        }
    });
    Http::beforeSending(static function (Request $request, array $options): void {
        expect(DB::transactionLevel())->toBe(0);
    });
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse($proposal))]);

    $result = continueContent($content, $source, $token, ['count' => 2, 'references' => [$reference->uuid.':1']]);
    $entryKey = $type === 'forum_thread' ? 'posts' : 'messages';
    $numberKey = $type === 'forum_thread' ? 'post_number' : 'message_number';
    $replyKey = $type === 'forum_thread' ? 'reply_to_post_number' : 'reply_to_message_number';
    $quoteNumberKey = $numberKey;
    $stored = $result->version->fresh();

    expect($result->alreadyCompleted)->toBeFalse()
        ->and($stored->version_number)->toBe(2)
        ->and($stored->based_on_version_id)->toBe($source->id)
        ->and($stored->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and(canonicalizeJsonStructure($stored->content[$entryKey][0]))->toBe(canonicalizeJsonStructure($sourceValue[$entryKey][0]))
        ->and(canonicalizeJsonStructure($stored->content[$entryKey][1]))->toBe(canonicalizeJsonStructure($sourceValue[$entryKey][1]))
        ->and($stored->content[$entryKey][2][$numberKey])->toBe(3)
        ->and($stored->content[$entryKey][2][$replyKey])->toBe(2)
        ->and($stored->content[$entryKey][2]['quote'][$quoteNumberKey])->toBe(1)
        ->and($stored->content[$entryKey][3][$numberKey])->toBe(4)
        ->and($stored->content[$entryKey][3][$replyKey])->toBe(3)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceValue))
        ->and($content->versions()->count())->toBe(2)
        ->and($stored->generation_metadata['operation'])->toBe('continuation')
        ->and($stored->generation_metadata['model'])->toBe('actual-continuation-model')
        ->and($stored->generation_metadata['input_tokens'])->toBe(123)
        ->and($stored->generation_metadata['quotes_dropped'])->toBe(0)
        ->and($stored->context_snapshot['operation'])->toBe('continuation')
        ->and($stored->context_snapshot['source'])->toBe(['content_uuid' => $content->uuid, 'version_number' => 1])
        ->and($stored->context_snapshot['project_context']['setting'])->toBe('Bellweather has no west exit.')
        ->and(canonicalizeJsonStructure($stored->context_snapshot['references'][0]['content']))->toBe(canonicalizeJsonStructure($referenceVersion->content))
        ->and($stored->context_snapshot)->not->toHaveKey('source_document')
        ->and($content->fresh()->title)->toBe($stableArtifactTitle)
        ->and($contextReads)->toBe(1)
        ->and(json_encode([$stored->content, $stored->context_snapshot, $stored->generation_metadata], JSON_THROW_ON_ERROR))
        ->not->toContain('personal-continuation-key')
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Completed)
        ->and(GenerationAttempt::query()->sole()->generated_content_id)->toBe($content->id)
        ->and(GenerationAttempt::query()->sole()->generated_content_version_id)->toBe($stored->id);

    Http::assertSent(function (Request $request) use ($token, $proposal, $sourceValue, $project): bool {
        return $request->hasHeader('Authorization', 'Bearer personal-continuation-key')
            && $request['text']['format']['strict'] === true
            && array_keys($request['text']['format']['schema']['properties']) === ['entries']
            && $request['text']['format']['schema']['properties']['entries']['minItems'] === 2
            && $request['text']['format']['schema']['properties']['entries']['maxItems'] === 2
            && $request['text']['format']['schema']['properties']['entries']['items']['additionalProperties'] === false
            && str_contains($request['instructions'], 'requested number of new entries')
            && str_contains($request['input'], '"source_document"')
            && str_contains($request['input'], $sourceValue['posts'][0]['body'] ?? $sourceValue['messages'][0]['body'])
            && str_contains($request['input'], 'Bellweather has no west exit.')
            && str_contains($request['input'], $token) === false
            && str_contains($request['input'], $project->uuid) === false
            && $proposal['entries'] !== [];
    });
})->with(['forum_thread', 'schrecknet_thread']);

test('twenty-entry Forum and SchreckNet versions can be continued repeatedly above twenty entries', function (string $type) {
    [$project, $content, $source] = createContinuationContent(
        $type,
        continuationGenerationSourceWithEntries($type, 20),
    );
    $originalContent = $source->content;
    $entryField = $type === 'forum_thread' ? 'posts' : 'messages';
    $numberField = $type === 'forum_thread' ? 'post_number' : 'message_number';
    $replyField = $type === 'forum_thread' ? 'reply_to_post_number' : 'reply_to_message_number';
    $entry = $type === 'forum_thread'
        ? [
            'author' => 'Ash',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => 'I checked the west entrance.',
            'reply_to_post_number' => 20,
            'quote' => ['post_number' => 20, 'text' => 'Existing entry 20'],
        ]
        : [
            'handle' => 'Ash',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => 'I checked the west entrance.',
            'reply_to_message_number' => 20,
            'quote' => ['message_number' => 20, 'text' => 'Existing entry 20'],
        ];
    $proposal = ['entries' => [$entry]];
    Http::fakeSequence()
        ->push(continuationProviderResponse($proposal, 'resp_long_continuation_1'))
        ->push(continuationProviderResponse($proposal, 'resp_long_continuation_2'));

    $firstResult = continueContent($content, $source, issueContinuationAttempt($content, $source));
    $firstVersion = $firstResult->version->fresh();
    $secondResult = continueContent($content, $firstVersion, issueContinuationAttempt($content, $firstVersion));
    $secondVersion = $secondResult->version->fresh();

    expect($firstVersion->content[$entryField])->toHaveCount(21)
        ->and($firstVersion->content[$entryField][20][$numberField])->toBe(21)
        ->and($firstVersion->content[$entryField][20][$replyField])->toBe(20)
        ->and($firstVersion->content[$entryField][20]['quote'][$numberField])->toBe(20)
        ->and($secondVersion->content[$entryField])->toHaveCount(22)
        ->and($secondVersion->content[$entryField][21][$numberField])->toBe(22)
        ->and($secondVersion->based_on_version_id)->toBe($firstVersion->id)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($originalContent))
        ->and($content->versions()->orderBy('version_number')->pluck('version_number')->all())->toBe([1, 2, 3]);

    Http::assertSentCount(2);
})->with(['forum_thread', 'schrecknet_thread']);

test('continuation can persist the final supported entry and refuses a further provider request', function (string $type) {
    [$project, $content, $source] = createContinuationContent(
        $type,
        continuationGenerationSourceWithEntries($type, 199),
    );
    $collection = $type === 'forum_thread' ? 'posts' : 'messages';
    $numberField = $type === 'forum_thread' ? 'post_number' : 'message_number';
    $replyField = $type === 'forum_thread' ? 'reply_to_post_number' : 'reply_to_message_number';
    $entry = $type === 'forum_thread'
        ? [
            'author' => 'Ash',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => 'The final supported post.',
            'reply_to_post_number' => 199,
            'quote' => ['post_number' => 199, 'text' => 'Existing entry 199'],
        ]
        : [
            'handle' => 'Ash',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => 'The final supported message.',
            'reply_to_message_number' => 199,
            'quote' => ['message_number' => 199, 'text' => 'Existing entry 199'],
        ];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(
        continuationProviderResponse(['entries' => [$entry]], 'resp_final_discussion_entry'),
    )]);

    $result = continueContent($content, $source, issueContinuationAttempt($content, $source));
    $version = $result->version->fresh();

    expect($version->content[$collection])->toHaveCount(200)
        ->and($version->content[$collection][199][$numberField])->toBe(200)
        ->and($version->content[$collection][199][$replyField])->toBe(199)
        ->and($version->content[$collection][199]['quote'][$numberField])->toBe(199)
        ->and($version->based_on_version_id)->toBe($source->id);

    expect(fn () => continueContent($content, $version, issueContinuationAttempt($content, $version)))
        ->toThrow(ValidationException::class);

    Http::assertSentCount(1);
    expect($content->versions()->count())->toBe(2);
})->with(['forum_thread', 'schrecknet_thread']);

test('unrecoverable new quote is omitted while its valid reply remains and source quotes are untouched', function () {
    $type = 'forum_thread';
    $sourceValue = continuationGenerationSource($type);
    $sourceValue['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'old signal read café closed.'];
    [$project, $content, $source] = createContinuationContent($type, $sourceValue);
    $token = issueContinuationAttempt($content, $source);
    $proposal = continuationGenerationProposal($type);
    $proposal['entries'][0]['quote']['text'] = 'a completely invented excerpt';
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse($proposal))]);

    $result = continueContent($content, $source, $token);
    $stored = $result->version->fresh();

    expect($stored->content['posts'][1]['quote']['text'])->toBe('old signal read café closed.')
        ->and($stored->content['posts'][2]['reply_to_post_number'])->toBe(2)
        ->and($stored->content['posts'][2]['quote'])->toBeNull()
        ->and($stored->generation_metadata['quotes_preserved'])->toBe(0)
        ->and($stored->generation_metadata['quotes_dropped'])->toBe(1)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceValue));
});

test('completed continuation duplicate returns its exact version without another provider request', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse(continuationGenerationProposal('forum_thread')))]);

    $first = continueContent($content, $source, $token);
    $duplicate = continueContent($content, $source, $token);

    expect($duplicate->alreadyCompleted)->toBeTrue()
        ->and($duplicate->version->id)->toBe($first->version->id)
        ->and($content->versions()->count())->toBe(2);
    Http::assertSentCount(1);
});

test('continuation from an older version sends and branches from exactly that source', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $sourceValue = $source->content;
    $laterContent = continuationGenerationSource('forum_thread');
    $laterContent['posts'][0]['body'] = 'A newer branch only.';
    $laterContent['posts'][1]['body'] = 'It changed later.';
    $later = $content->versions()->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'content' => $laterContent,
    ]);
    $token = issueContinuationAttempt($content, $source);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse(continuationGenerationProposal('forum_thread')))]);

    $result = continueContent($content, $source, $token);

    expect($result->version->version_number)->toBe(3)
        ->and($result->version->based_on_version_id)->toBe($source->id)
        ->and(canonicalizeJsonStructure($result->version->content['posts'][0]))->toBe(canonicalizeJsonStructure($sourceValue['posts'][0]))
        ->and(canonicalizeJsonStructure($result->version->content['posts'][1]))->toBe(canonicalizeJsonStructure($sourceValue['posts'][1]))
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceValue))
        ->and(canonicalizeJsonStructure($later->fresh()->content))->toBe(canonicalizeJsonStructure($laterContent));
    Http::assertSent(fn (Request $request): bool => str_contains($request['input'], 'The old signal read café closed.')
        && ! str_contains($request['input'], 'A newer branch only.'));
});

test('invalid requested count, full source, or cross-bound content token fail before OpenAI', function (string $case) {
    $sourceContent = continuationGenerationSource('forum_thread');
    if ($case === 'full source') {
        $sourceContent['posts'] = [];
        for ($number = 1; $number <= 200; $number++) {
            $sourceContent['posts'][] = [
                'post_number' => $number,
                'author' => 'Mica',
                'posted_at' => '2026-10-05T10:01:00+00:00',
                'body' => 'A post.',
                'reply_to_post_number' => null,
                'quote' => null,
            ];
        }
    }
    [$project, $content, $source] = createContinuationContent('forum_thread', $sourceContent);
    $token = issueContinuationAttempt($content, $source);
    Http::fake();

    if ($case === 'cross content') {
        $otherContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
        $otherSource = $otherContent->versions()->create([
            'version_number' => 1,
            'origin' => GeneratedContentVersionOrigin::AiGenerated,
            'content' => continuationGenerationSource('forum_thread'),
        ]);

        expect(fn () => continueContent($otherContent, $otherSource, $token))->toThrow(GenerationAttemptException::class);
    } else {
        $count = $case === 'zero' ? 0 : ($case === 'negative' ? -1 : 21);
        expect(fn () => continueContent($content, $source, $token, ['count' => $count]))
            ->toThrow(ValidationException::class);
    }

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued)
        ->and($content->versions()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['zero', 'negative', 'full source', 'excessive per-request count', 'cross content']);

test('continuation reuses the five-reference limit and rejects duplicate selected versions before claiming', function (string $case) {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    $references = [];

    for ($index = 0; $index < 6; $index++) {
        $reference = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
        $reference->versions()->create([
            'version_number' => 1,
            'origin' => GeneratedContentVersionOrigin::AiGenerated,
            'content' => continuationGenerationSource('forum_thread'),
        ]);
        $references[] = $reference->uuid.':1';
    }

    Http::fake();

    if ($case === 'duplicate') {
        $references = [$references[0], $references[0]];
    }

    expect(fn () => continueContent($content, $source, $token, ['references' => $references]))
        ->toThrow(ValidationException::class);

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued)
        ->and($content->versions()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['duplicate', 'over five']);

test('continuation provider failure consumes only its bound attempt and retry needs a new token', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $failedToken = issueContinuationAttempt($content, $source);
    Http::fakeSequence('https://api.openai.com/v1/responses')
        ->push(['error' => ['message' => 'sensitive body']], 500)
        ->push(continuationProviderResponse(continuationGenerationProposal('forum_thread'), 'resp_retry'));

    try {
        continueContent($content, $source, $failedToken);
    } catch (Throwable) {
    }

    $retryToken = issueContinuationAttempt($content, $source);
    $result = continueContent($content, $source, $retryToken);

    expect($result->version->version_number)->toBe(2)
        ->and(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Failed)->count())->toBe(1)
        ->and(GenerationAttempt::query()->where('status', GenerationAttemptStatus::Completed)->count())->toBe(1)
        ->and(GenerationAttempt::query()->where('status', GenerationAttemptStatus::InProgress)->count())->toBe(0);

    expect(fn () => continueContent($content, $source, $failedToken))->toThrow(GenerationAttemptException::class);
    Http::assertSentCount(2);
});

test('a continuation attempt cannot be claimed by another user', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    $otherUser = Project::factory()->create()->user;
    Http::fake();

    expect(fn () => app(ContinueGeneratedContent::class)->handle(
        $otherUser,
        $content,
        $source,
        'Continue safely.',
        1,
        $token,
        'personal-continuation-key',
    ))->toThrow(GenerationAttemptException::class);

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
});

test('a continuation attempt is bound to the exact source version', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    $otherSource = $content->versions()->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'content' => continuationGenerationSource('forum_thread'),
    ]);
    Http::fake();

    expect(fn () => continueContent($content, $otherSource, $token))->toThrow(GenerationAttemptException::class);

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
});

test('malformed continuation proposal creates no version and safe diagnostics omit source and generated text', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse([
        'entries' => [[
            'author' => 'Private Author',
            'posted_at' => '2026-10-05T09:00:00+00:00',
            'body' => 'Private generated body.',
            'reply_to_post_number' => 2,
            'quote' => null,
        ]],
    ]))]);
    Log::spy();

    expect(fn () => continueContent($content, $source, $token))->toThrow(StructuredContentGenerationException::class);

    expect($content->versions()->count())->toBe(1)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Failed);
    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $serialized = json_encode($context, JSON_THROW_ON_ERROR);

        return $message === 'Structured continuation generation failed.'
            && $context['operation'] === 'continuation'
            && ! str_contains($serialized, 'Private generated body.')
            && ! str_contains($serialized, 'Private Author')
            && ! str_contains($serialized, 'The old signal read café closed.');
    })->once();
});

test('provider supplied entry numbers are rejected rather than trusted', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    $proposal = continuationGenerationProposal('forum_thread');
    $proposal['entries'][0]['post_number'] = 900;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse($proposal))]);

    expect(fn () => continueContent($content, $source, $token))->toThrow(StructuredContentGenerationException::class);

    expect($content->versions()->count())->toBe(1)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Failed);
});

test('attempt finalization failure rolls back the appended version', function () {
    [$project, $content, $source] = createContinuationContent('forum_thread');
    $token = issueContinuationAttempt($content, $source);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationProviderResponse(continuationGenerationProposal('forum_thread')))]);
    $attempts = Mockery::mock(GenerationAttemptManager::class)->makePartial();
    $attempts->shouldReceive('complete')->once()->andThrow(new RuntimeException('injected finalization error'));
    app()->instance(GenerationAttemptManager::class, $attempts);

    expect(fn () => continueContent($content, $source, $token))->toThrow(RuntimeException::class, 'injected finalization error');

    expect($content->versions()->count())->toBe(1)
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Failed)
        ->and(GenerationAttempt::query()->sole()->generated_content_version_id)->toBeNull();
});
