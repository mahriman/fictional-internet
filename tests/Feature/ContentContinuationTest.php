<?php

use App\Actions\AppendGeneratedContentVersion;
use App\Actions\ComposeGeneratedContentContinuation;
use App\ContentTypes\ContentContinuationComposer;
use App\ContentTypes\ContentTypeRegistry;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function continuationSourceContent(string $contentType): array
{
    if ($contentType === 'forum_thread') {
        return [
            'forum_name' => 'The Lantern Board',
            'thread_title' => 'Light over the café',
            'category' => 'Local observations',
            'started_at' => '2025-06-15T10:00:00+00:00',
            'posts' => [
                ['post_number' => 1, 'author' => 'Cedar', 'posted_at' => '2025-06-15T10:01:00+00:00', 'body' => 'I saw café lights flicker twice.', 'reply_to_post_number' => null, 'quote' => null],
                ['post_number' => 2, 'author' => 'Tide', 'posted_at' => '2025-06-15T10:03:00+00:00', 'body' => 'Could be a timer.', 'reply_to_post_number' => 1, 'quote' => null],
            ],
        ];
    }

    return [
        'network' => 'SchreckNet',
        'channel' => 'harbor/whispers',
        'thread_title' => 'Light over the café',
        'started_at' => '2025-06-15T10:00:00+00:00',
        'messages' => [
            ['message_number' => 1, 'handle' => 'Cedar', 'posted_at' => '2025-06-15T10:01:00+00:00', 'body' => 'I saw café lights flicker twice.', 'reply_to_message_number' => null, 'quote' => null],
            ['message_number' => 2, 'handle' => 'Tide', 'posted_at' => '2025-06-15T10:03:00+00:00', 'body' => 'Could be a timer.', 'reply_to_message_number' => 1, 'quote' => null],
        ],
    ];
}

function continuationEntry(string $contentType, array $overrides = []): array
{
    $entry = $contentType === 'forum_thread'
        ? [
            'author' => 'NorthWindow',
            'posted_at' => '2025-06-15T10:05:00+00:00',
            'body' => 'The café sign is dark now.',
            'reply_to_post_number' => 1,
            'quote' => ['post_number' => 1, 'text' => 'café lights flicker'],
        ]
        : [
            'handle' => 'NorthWindow',
            'posted_at' => '2025-06-15T10:05:00+00:00',
            'body' => 'The café sign is dark now.',
            'reply_to_message_number' => 1,
            'quote' => ['message_number' => 1, 'text' => 'café lights flicker'],
        ];

    return array_replace($entry, $overrides);
}

function continuationCollection(string $contentType): string
{
    return $contentType === 'forum_thread' ? 'posts' : 'messages';
}

function continuationNumberField(string $contentType): string
{
    return $contentType === 'forum_thread' ? 'post_number' : 'message_number';
}

function continuationReplyField(string $contentType): string
{
    return $contentType === 'forum_thread' ? 'reply_to_post_number' : 'reply_to_message_number';
}

function continuationProposal(array ...$entries): array
{
    return ['entries' => $entries];
}

function persistContinuation(GeneratedContent $content, GeneratedContentVersion $source, array $composed): GeneratedContentVersion
{
    return app(AppendGeneratedContentVersion::class)->handle(
        $content,
        $composed,
        GeneratedContentVersionOrigin::AiGenerated,
        ['continuation_source_version' => $source->version_number],
        ['provider' => 'openai'],
        $source,
    );
}

test('continuation proposal schema contains only strictly shaped new entries', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    $source = continuationSourceContent($contentType);
    $schema = app(ContentContinuationComposer::class)->proposalSchema($definition, $source);
    $collection = continuationCollection($contentType);
    $numberField = continuationNumberField($contentType);
    $itemSchema = $schema['properties']['entries']['items'];

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['entries'])
        ->and($schema['properties']['entries']['minItems'])->toBe(1)
        ->and($schema['properties']['entries']['maxItems'])->toBe($definition->outputSchema()['properties'][$collection]['maxItems'] - 2)
        ->and($itemSchema['additionalProperties'])->toBeFalse()
        ->and($itemSchema['properties'])->not->toHaveKey($numberField)
        ->and($itemSchema['required'])->not->toContain($numberField);
})->with(['forum_thread', 'schrecknet_thread']);

test('continuation appends numbered entries and reuses immutable version lineage for both types', function (string $contentType) {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => $contentType]);
    $sourceContent = continuationSourceContent($contentType);
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $sourceContent,
        'context_snapshot' => ['original' => 'snapshot'],
    ]);
    $proposal = continuationProposal(continuationEntry($contentType));

    $composed = app(ComposeGeneratedContentContinuation::class)->handle($generatedContent, $sourceVersion, $proposal);
    $version = persistContinuation($generatedContent, $sourceVersion, $composed);
    $collection = continuationCollection($contentType);
    $numberField = continuationNumberField($contentType);

    expect(canonicalizeJsonStructure($composed[$collection][0]))->toBe(canonicalizeJsonStructure($sourceContent[$collection][0]))
        ->and(canonicalizeJsonStructure($composed[$collection][1]))->toBe(canonicalizeJsonStructure($sourceContent[$collection][1]))
        ->and($composed[$collection][2][$numberField])->toBe(3)
        ->and($version->version_number)->toBe(2)
        ->and($version->based_on_version_id)->toBe($sourceVersion->id)
        ->and($version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and(canonicalizeJsonStructure($version->fresh()->content))->toBe(canonicalizeJsonStructure($composed))
        ->and($version->context_snapshot)->toBe(['continuation_source_version' => 1])
        ->and($version->generation_metadata)->toBe(['provider' => 'openai'])
        ->and(canonicalizeJsonStructure($sourceVersion->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and($sourceVersion->fresh()->context_snapshot)->toBe(['original' => 'snapshot']);

    Http::assertNothingSent();
})->with(['forum_thread', 'schrecknet_thread']);

test('continuation from an older source branches from it and appends after the latest version', function (string $contentType) {
    $generatedContent = GeneratedContent::factory()->create(['content_type' => $contentType]);
    $sourceContent = continuationSourceContent($contentType);
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $sourceContent,
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 2]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 3]);
    $composed = app(ComposeGeneratedContentContinuation::class)->handle(
        $generatedContent,
        $source,
        continuationProposal(continuationEntry($contentType)),
    );

    $continued = persistContinuation($generatedContent, $source, $composed);

    expect($continued->version_number)->toBe(4)
        ->and($continued->based_on_version_id)->toBe($source->id)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and($generatedContent->versions()->orderBy('version_number')->pluck('version_number')->all())->toBe([1, 2, 3, 4]);
})->with(['forum_thread', 'schrecknet_thread']);

test('new replies and exact unicode quotes may reference entries from the selected source', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    $source = continuationSourceContent($contentType);
    $entry = continuationEntry($contentType, [
        'body' => 'I agree with Cedar.',
        continuationReplyField($contentType) => 1,
    ]);
    $composed = app(ContentContinuationComposer::class)->compose(
        $definition,
        $source,
        continuationProposal($entry),
    );
    $collection = continuationCollection($contentType);

    expect($composed[$collection][2][continuationReplyField($contentType)])->toBe(1)
        ->and($composed[$collection][2]['quote'])->toBe($entry['quote'])
        ->and($definition->semanticValidationErrors($composed))->toBe([]);
})->with(['forum_thread', 'schrecknet_thread']);

test('continuation permits replies and quotes between proposed entries using assigned sequence numbers', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    $source = continuationSourceContent($contentType);
    $entryNumber = continuationNumberField($contentType);
    $collection = continuationCollection($contentType);
    $secondEntry = continuationEntry($contentType, [
        'body' => 'That is not what I observed.',
        continuationReplyField($contentType) => 3,
        'quote' => [$entryNumber => 3, 'text' => 'The café sign is dark now.'],
        'posted_at' => '2025-06-15T10:06:00+00:00',
    ]);
    $composed = app(ContentContinuationComposer::class)->compose(
        $definition,
        $source,
        continuationProposal(continuationEntry($contentType), $secondEntry),
    );

    expect($composed[$collection][2][$entryNumber])->toBe(3)
        ->and($composed[$collection][3][$entryNumber])->toBe(4)
        ->and($composed[$collection][3][continuationReplyField($contentType)])->toBe(3)
        ->and($composed[$collection][3]['quote'][$entryNumber])->toBe(3)
        ->and($definition->semanticValidationErrors($composed))->toBe([]);
})->with(['forum_thread', 'schrecknet_thread']);

test('invalid self and forward references and cross-boundary chronology are rejected', function (string $contentType, string $case) {
    $collection = continuationCollection($contentType);
    $entryNumber = continuationNumberField($contentType);
    $replyField = continuationReplyField($contentType);
    $entry = continuationEntry($contentType);

    match ($case) {
        'self reply' => $entry[$replyField] = 3,
        'future reply' => $entry[$replyField] = 4,
        'self quote' => $entry['quote'] = [$entryNumber => 3, 'text' => 'The café sign is dark now.'],
        'future quote' => $entry['quote'] = [$entryNumber => 4, 'text' => 'The café sign is dark now.'],
        'before source chronology' => $entry['posted_at'] = '2025-06-15T10:02:00+00:00',
    };

    expect(fn () => app(ContentContinuationComposer::class)->compose(
        app(ContentTypeRegistry::class)->get($contentType),
        continuationSourceContent($contentType),
        continuationProposal($entry),
    ))->toThrow(ValidationException::class);
})->with([
    'forum self reply' => ['forum_thread', 'self reply'],
    'forum future reply' => ['forum_thread', 'future reply'],
    'forum self quote' => ['forum_thread', 'self quote'],
    'forum future quote' => ['forum_thread', 'future quote'],
    'forum source chronology boundary' => ['forum_thread', 'before source chronology'],
    'SchreckNet self reply' => ['schrecknet_thread', 'self reply'],
    'SchreckNet future reply' => ['schrecknet_thread', 'future reply'],
    'SchreckNet self quote' => ['schrecknet_thread', 'self quote'],
    'SchreckNet future quote' => ['schrecknet_thread', 'future quote'],
    'SchreckNet source chronology boundary' => ['schrecknet_thread', 'before source chronology'],
]);

test('empty additions and attempts to submit source numbers or document fields are rejected', function (string $case) {
    $contentType = 'forum_thread';
    $proposal = match ($case) {
        'empty' => ['entries' => []],
        'entry number' => continuationProposal(continuationEntry($contentType, ['post_number' => 9])),
        'document identity' => ['entries' => [continuationEntry($contentType)], 'thread_title' => 'Changed title'],
        'entry order field' => continuationProposal(continuationEntry($contentType, ['unexpected' => 'value'])),
    };

    expect(fn () => app(ContentContinuationComposer::class)->compose(
        app(ContentTypeRegistry::class)->get($contentType),
        continuationSourceContent($contentType),
        $proposal,
    ))->toThrow(ValidationException::class);
})->with(['empty', 'entry number', 'document identity', 'entry order field']);

test('a full source is rejected rather than truncating any source entries', function (string $contentType) {
    $definition = app(ContentTypeRegistry::class)->get($contentType);
    $source = continuationSourceContent($contentType);
    $collection = continuationCollection($contentType);
    $numberField = continuationNumberField($contentType);
    $replyField = continuationReplyField($contentType);
    $maximum = $definition->outputSchema()['properties'][$collection]['maxItems'];
    $source[$collection] = [];

    for ($entryNumber = 1; $entryNumber <= $maximum; $entryNumber++) {
        $entry = continuationEntry($contentType, [
            'posted_at' => '2025-06-15T10:05:00+00:00',
            $replyField => null,
            'quote' => null,
        ]);
        $entry[$numberField] = $entryNumber;
        $source[$collection][] = $entry;
    }

    expect(fn () => app(ContentContinuationComposer::class)->proposalSchema($definition, $source))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ContentContinuationComposer::class)->compose(
            $definition,
            $source,
            continuationProposal(continuationEntry($contentType)),
        ))->toThrow(ValidationException::class)
        ->and(count($source[$collection]))->toBe($maximum);
})->with(['forum_thread', 'schrecknet_thread']);

test('unsupported content types unsaved sources and sources outside the requested content are rejected', function (string $case) {
    $content = GeneratedContent::factory()->create([
        'content_type' => match ($case) {
            'unsupported' => 'news_article',
            'unknown' => 'unregistered_type',
            default => 'forum_thread',
        },
    ]);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationSourceContent('forum_thread')]);
    $otherContent = GeneratedContent::factory()->create(['content_type' => 'forum_thread']);
    $otherSource = GeneratedContentVersion::factory()->for($otherContent)->create(['content' => continuationSourceContent('forum_thread')]);
    $action = app(ComposeGeneratedContentContinuation::class);

    if ($case === 'unsaved source') {
        $source = new GeneratedContentVersion;
    } elseif ($case === 'wrong parent') {
        $source = $otherSource;
    }

    expect(fn () => $action->handle($content, $source, continuationProposal(continuationEntry('forum_thread'))))
        ->toThrow(InvalidArgumentException::class);
})->with(['unsupported', 'unknown', 'unsaved source', 'wrong parent']);

test('a historical source that fails current strict validation is not normalized or rewritten', function () {
    $generatedContent = GeneratedContent::factory()->create(['content_type' => 'forum_thread']);
    $legacySource = continuationSourceContent('forum_thread');
    unset($legacySource['posts'][1]['quote'], $legacySource['posts'][1]['reply_to_post_number']);
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $legacySource]);

    expect(fn () => app(ComposeGeneratedContentContinuation::class)->handle(
        $generatedContent,
        $source,
        continuationProposal(continuationEntry('forum_thread')),
    ))->toThrow(ValidationException::class);

    expect(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($legacySource))
        ->and($generatedContent->versions()->count())->toBe(1);
    Http::assertNothingSent();
});

test('proposal schema is not issued for a source that fails current strict validation', function () {
    $source = continuationSourceContent('forum_thread');
    unset($source['posts'][1]['quote']);

    expect(fn () => app(ContentContinuationComposer::class)->proposalSchema(
        app(ContentTypeRegistry::class)->get('forum_thread'),
        $source,
    ))->toThrow(ValidationException::class);
});
