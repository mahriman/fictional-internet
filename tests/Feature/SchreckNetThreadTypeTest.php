<?php

use App\Actions\GenerateAndPersistContent;
use App\Actions\GenerationAttemptManager;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\SchreckNetThreadType;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.model', 'schrecknet-test-model');
    config()->set('services.openai.timeout', 30);
});

/** @return array{network: string, channel: string, thread_title: string, started_at: string, messages: list<array{message_number: int, handle: string, posted_at: string, body: string, reply_to_message_number: int|null, quote: array{message_number: int, text: string}|null}>} */
function validSchreckNetThread(): array
{
    return [
        'network' => 'SchreckNet',
        'channel' => 'harbor/whispers',
        'thread_title' => 'Relay lights beneath the quay',
        'started_at' => '2025-06-15T10:00:00+00:00',
        'messages' => [
            ['message_number' => 1, 'handle' => 'Morrow', 'posted_at' => '2025-06-15T10:02:00+00:00', 'body' => 'I found a trace near café-sector; the signal had a sharp ∆ pulse.', 'reply_to_message_number' => null, 'quote' => null],
            ['message_number' => 2, 'handle' => 'NullRoute', 'posted_at' => '2025-06-15T10:05:00+00:00', 'body' => 'Could be an old relay waking up. Do not touch it yet.', 'reply_to_message_number' => 1, 'quote' => null],
            ['message_number' => 3, 'handle' => 'AshIndex', 'posted_at' => '2025-06-15T10:08:00+00:00', 'body' => 'The pulse came from somewhere else.', 'reply_to_message_number' => 2, 'quote' => ['message_number' => 1, 'text' => 'café-sector; the signal had a sharp ∆ pulse']],
        ],
    ];
}

function schreckNetResponse(array $thread): array
{
    return [
        'id' => 'resp_schrecknet_test',
        'status' => 'completed',
        'model' => 'actual-schrecknet-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($thread, JSON_THROW_ON_ERROR),
            ]],
        ]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 150, 'total_tokens' => 250],
    ];
}

function schreckNetEditInput(array $thread): array
{
    return [
        'channel' => $thread['channel'],
        'thread_title' => $thread['thread_title'],
        'started_at' => $thread['started_at'],
        'messages' => array_map(static function (array $message): array {
            return [
                'handle' => $message['handle'],
                'posted_at' => $message['posted_at'],
                'body' => $message['body'],
                'reply_to_message_number' => $message['reply_to_message_number'] ?? '',
                'quote' => [
                    'message_number' => $message['quote']['message_number'] ?? '',
                    'text' => $message['quote']['text'] ?? '',
                ],
            ];
        }, $thread['messages']),
    ];
}

test('SchreckNet definition exposes the strict registered structure and setting instructions', function () {
    $definition = app(ContentTypeRegistry::class)->get('schrecknet_thread');
    $schema = $definition->outputSchema();
    $messageSchema = $schema['properties']['messages']['items'];

    expect($definition)->toBeInstanceOf(SchreckNetThreadType::class)
        ->and($definition->key())->toBe('schrecknet_thread')
        ->and($definition->label())->toBe('SchreckNet Thread')
        ->and($definition->presentationView())->toBe('generated-content.types.schrecknet-thread')
        ->and($definition->editingView())->toBe('generated-content.editors.schrecknet-thread')
        ->and($definition->titleFromContent(validSchreckNetThread()))->toBe('Relay lights beneath the quay')
        ->and($definition->promptInstructions())->toContain('clandestine SchreckNet thread')
        ->and($definition->promptInstructions())->toContain('campaign-specific fictional context')
        ->and($definition->promptInstructions())->toContain('exact contiguous excerpt')
        ->and(array_keys(app(ContentTypeRegistry::class)->all()))->toBe(['news_article', 'forum_thread', 'schrecknet_thread'])
        ->and($schema['required'])->toBe(['network', 'channel', 'thread_title', 'started_at', 'messages'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['network']['enum'])->toBe(['SchreckNet'])
        ->and($schema['properties']['messages']['minItems'])->toBe(1)
        ->and($schema['properties']['messages']['maxItems'])->toBe(SchreckNetThreadType::MAX_MESSAGES)
        ->and($messageSchema['additionalProperties'])->toBeFalse()
        ->and($messageSchema['required'])->toBe(['message_number', 'handle', 'posted_at', 'body', 'reply_to_message_number', 'quote'])
        ->and($messageSchema['properties']['reply_to_message_number']['anyOf'][1]['type'])->toBe('null')
        ->and($messageSchema['properties']['quote']['anyOf'][0]['additionalProperties'])->toBeFalse()
        ->and($messageSchema['properties']['quote']['anyOf'][1]['type'])->toBe('null');
});

test('SchreckNet appears in the generic registry-driven generation selector', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('value="schrecknet_thread"', false)
        ->assertSee('SchreckNet Thread');
});

test('SchreckNet structured generation sends the registered schema and persists its exact content and metadata', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'personal-schrecknet-test-key']);
    $project->context()->create([
        'setting' => 'A sealed harbor district is used by the local Kindred.',
        'time_period' => null,
        'locations' => null,
        'people' => null,
        'organizations' => null,
        'canon_notes' => 'In this chronicle the relay is newly built.',
    ]);
    $source = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $sourceVersion = $source->versions()->create([
        'version_number' => 4,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => ['thread_title' => 'The exact old trace', 'posts' => []],
    ]);
    $thread = validSchreckNetThread();
    Http::fake(function (Request $request) use ($thread) {
        $definition = app(ContentTypeRegistry::class)->get('schrecknet_thread');

        expect($request['instructions'])->toBe($definition->promptInstructions())
            ->and($request['text']['format']['strict'])->toBeTrue()
            ->and($request['text']['format']['schema'])->toBe($definition->outputSchema())
            ->and($request['input'])->toContain('A sealed harbor district')
            ->and($request['input'])->toContain('In this chronicle the relay is newly built.')
            ->and($request['input'])->toContain('The exact old trace')
            ->and($request['input'])->toContain('content_uuid')
            ->and($request->hasHeader('Authorization', 'Bearer personal-schrecknet-test-key'))->toBeTrue();

        return Http::response(schreckNetResponse($thread));
    });

    $attemptToken = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    $this->actingAs($user)->post(route('projects.generated-content.store', $project), [
        'attempt_token' => $attemptToken,
        'content_type' => 'schrecknet_thread',
        'prompt' => 'Write a wary discussion about a strange relay trace.',
        'references' => [$source->uuid.':4'],
    ])->assertRedirect();

    $generatedContent = $project->generatedContents()->where('content_type', 'schrecknet_thread')->sole();
    $version = $generatedContent->versions()->sole();

    expect($generatedContent->title)->toBe($thread['thread_title'])
        ->and($version->version_number)->toBe(1)
        ->and($version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and($version->based_on_version_id)->toBeNull()
        ->and(canonicalizeJsonStructure($version->content))->toBe(canonicalizeJsonStructure($thread))
        ->and($version->generation_metadata)->toMatchArray([
            'provider' => 'openai',
            'model' => 'actual-schrecknet-test-model',
            'response_id' => 'resp_schrecknet_test',
            'input_tokens' => 100,
            'output_tokens' => 150,
            'total_tokens' => 250,
        ])
        ->and(canonicalizeJsonStructure($version->context_snapshot['references'][0]['content']))
        ->toBe(canonicalizeJsonStructure($sourceVersion->fresh()->content))
        ->and(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Completed);

    $this->post(route('projects.generated-content.store', $project), [
        'attempt_token' => $attemptToken,
        'content_type' => 'schrecknet_thread',
        'prompt' => 'Write a wary discussion about a strange relay trace.',
        'references' => [$source->uuid.':4'],
    ])->assertRedirect(route('projects.generated-content.show', [$project, $generatedContent]));
    Http::assertSentCount(1);
});

test('reply-only, quote-only and independent reply and quote targets are valid', function () {
    $definition = app(ContentTypeRegistry::class)->get('schrecknet_thread');
    $thread = validSchreckNetThread();
    $thread['messages'][1]['reply_to_message_number'] = 1;
    $thread['messages'][2]['reply_to_message_number'] = null;

    expect($definition->semanticValidationErrors($thread))->toBe([]);

    $thread['messages'][2]['reply_to_message_number'] = 1;
    expect($definition->semanticValidationErrors($thread))->toBe([]);
});

test('SchreckNet accepts the maximum of thirty chronologically valid messages', function () {
    $thread = validSchreckNetThread();
    $thread['messages'] = [];

    for ($number = 1; $number <= SchreckNetThreadType::MAX_MESSAGES; $number++) {
        $thread['messages'][] = [
            'message_number' => $number,
            'handle' => 'handle-'.$number,
            'posted_at' => '2025-06-15T10:00:00+00:00',
            'body' => 'Message '.$number,
            'reply_to_message_number' => null,
            'quote' => null,
        ];
    }

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(schreckNetResponse($thread))]);
    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        'schrecknet_thread',
        'Write a long thread.',
        'direct-schrecknet-test-key',
    );

    expect($result->version->content['messages'])->toHaveCount(30);
});

test('invalid SchreckNet structure is rejected before content or version persistence', function (array $thread) {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(schreckNetResponse($thread))]);
    $project = Project::factory()->create();

    expect(fn () => app(GenerateAndPersistContent::class)->handle(
        $project,
        'schrecknet_thread',
        'Generate a thread.',
        'direct-schrecknet-test-key',
    ))->toThrow(StructuredContentGenerationException::class);

    expect($project->generatedContents()->exists())->toBeFalse()
        ->and(GeneratedContentVersion::query()->exists())->toBeFalse();
})->with([
    'unrelated network' => [array_replace(validSchreckNetThread(), ['network' => 'OtherNet'])],
    'invalid nullable field type' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['reply_to_message_number' => 'remove']]])],
    'unexpected root property' => [array_merge(validSchreckNetThread(), ['extra' => 'not allowed'])],
    'zero message number' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['message_number' => 0]]])],
    'nonsequential message number' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['message_number' => 1], ['message_number' => 4]]])],
    'reply to current message' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], [], ['reply_to_message_number' => 3]]])],
    'reply to nonexistent message' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], [], ['reply_to_message_number' => 8]]])],
    'opening message quote' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['quote' => ['message_number' => 1, 'text' => 'not earlier']]]])],
    'nonexistent quote source' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], [], ['quote' => ['message_number' => 8, 'text' => 'not earlier']]]])],
    'future quote' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], [], ['quote' => ['message_number' => 3, 'text' => 'anything']]]])],
    'paraphrased quote' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], [], ['quote' => ['message_number' => 1, 'text' => 'a close paraphrase']]]])],
    'first message reply' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['reply_to_message_number' => 1]]])],
    'bad calendar date' => [array_replace(validSchreckNetThread(), ['started_at' => '2025-02-30T10:00:00+00:00'])],
    'bad timezone offset' => [array_replace(validSchreckNetThread(), ['started_at' => '2025-06-15T10:00:00+25:00'])],
    'message before start' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [['posted_at' => '2025-06-15T09:59:00+00:00']]])],
    'decreasing chronology' => [array_replace_recursive(validSchreckNetThread(), ['messages' => [[], ['posted_at' => '2025-06-15T10:01:00+00:00']]])],
    'no messages' => [array_replace(validSchreckNetThread(), ['messages' => []])],
    'too many messages' => [array_replace(validSchreckNetThread(), ['messages' => array_fill(0, 31, validSchreckNetThread()['messages'][0])])],
]);

test('SchreckNet semantic diagnostics identify chronology without logging message content', function () {
    $thread = validSchreckNetThread();
    $thread['messages'][2]['posted_at'] = '2025-06-15T10:04:00+00:00';
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(schreckNetResponse($thread))]);

    try {
        app(GenerateAndPersistContent::class)->handle(
            Project::factory()->create(),
            'schrecknet_thread',
            'PRIVATE GENERATION PROMPT',
            'private-schrecknet-api-key',
        );
        test()->fail('Decreasing timestamps must fail semantic validation.');
    } catch (StructuredContentGenerationException $exception) {
        expect($exception->diagnosticCategory)->toBe('semantic_validation')
            ->and($exception->diagnosticCodes)->toBe(['semantic_validation_failed', 'discussion_timestamp_invalid'])
            ->and($exception->fieldPaths)->toContain('content.messages.2.posted_at')
            ->and($exception->diagnosticContext)->toMatchArray([
                'provider_status' => 'completed',
                'output_tokens' => 150,
                'entry_collection' => 'messages',
                'entry_count' => 3,
            ])
            ->and(json_encode($exception->diagnosticContext))->not->toContain('PRIVATE');
    }
});

test('SchreckNet editing creates an immutable version with preserved numbers, lineage and snapshot', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'schrecknet_thread',
        'title' => 'Stale overview title',
    ]);
    $sourceContent = validSchreckNetThread();
    $source = $generatedContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => $sourceContent,
        'context_snapshot' => ['prompt' => 'Original generation context', 'references' => [['title' => 'Captured source']]],
        'generation_metadata' => ['model' => 'original-model'],
    ]);
    $this->actingAs($user)
        ->get(route('projects.generated-content.versions.show', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee($sourceContent['thread_title'])
        ->assertDontSee('Stale overview title');

    $submitted = schreckNetEditInput($sourceContent);
    $submitted['thread_title'] = 'Edited clandestine thread';
    $submitted['messages'][0]['body'] = 'A changed report from the quay.';
    $submitted['messages'][2]['quote'] = ['message_number' => '1', 'text' => 'A changed report'];

    Http::fake();
    $this->actingAs($user)->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), [
        'content' => $submitted,
    ])->assertRedirect(route('projects.generated-content.versions.show', [$project, $generatedContent, 2]));

    $edited = $generatedContent->versions()->where('version_number', 2)->firstOrFail();
    expect(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and($edited->content['network'])->toBe('SchreckNet')
        ->and($edited->content['messages'][0]['message_number'])->toBe(1)
        ->and($edited->content['messages'][2]['message_number'])->toBe(3)
        ->and($edited->content['messages'][2]['quote']['message_number'])->toBe(1)
        ->and($edited->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($edited->based_on_version_id)->toBe($source->id)
        ->and($edited->context_snapshot)->toBe($source->context_snapshot)
        ->and($edited->generation_metadata)->toBeNull();
    Http::assertNothingSent();
});

test('SchreckNet editor rejects altered shape and quotes invalidated by final edited bodies', function (string $case) {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'schrecknet_thread']);
    $sourceContent = validSchreckNetThread();
    $generatedContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => $sourceContent,
    ]);
    $submitted = schreckNetEditInput($sourceContent);

    if ($case === 'altered message number') {
        $submitted['messages'][1]['message_number'] = 9;
    } elseif ($case === 'sparse list') {
        unset($submitted['messages'][1]);
        $submitted['messages'][2] = $submitted['messages'][2];
    } elseif ($case === 'unexpected quote field') {
        $submitted['messages'][2]['quote']['untrusted'] = 'rejected';
    } elseif ($case === 'quote invalidated by edited source body') {
        $submitted['messages'][0]['body'] = 'The message no longer contains the quoted excerpt.';
    } elseif ($case === 'unrelated network') {
        $submitted['network'] = 'OtherNet';
    }

    Http::fake();
    $response = $this->actingAs($user)->from(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), ['content' => $submitted]);

    $response->assertRedirect(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]));

    if ($case === 'quote invalidated by edited source body') {
        $response->assertSessionHasErrors('content.messages.2.quote.text');
    } else {
        $response->assertSessionHasErrors();
    }

    expect($generatedContent->versions()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['altered message number', 'sparse list', 'unexpected quote field', 'quote invalidated by edited source body', 'unrelated network']);

test('SchreckNet presentation escapes message content and links safe earlier message anchors', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'schrecknet_thread']);
    $thread = validSchreckNetThread();
    $thread['messages'][0]['body'] = "<script>alert('x')</script>\nA second line & more.";
    $thread['messages'][2]['quote'] = ['message_number' => 1, 'text' => "<script>alert('x')</script>"];
    $generatedContent->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => $thread,
    ]);
    Http::fake();

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('SchreckNet')
        ->assertSee('harbor/whispers')
        ->assertSee('Relay lights beneath the quay')
        ->assertSee('Morrow')
        ->assertSee('href="#schrecknet-message-1"', false)
        ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
        ->assertSee('A second line &amp; more.', false)
        ->assertDontSee("<script>alert('x')</script>", false);

    $this->get(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('Network identity:')
        ->assertSee('name="content[messages][2][quote][message_number]"', false)
        ->assertSee('name="content[messages][2][quote][text]"', false)
        ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false);
    Http::assertNothingSent();
});

test('a user without a personal key cannot start SchreckNet generation through a crafted request', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    config()->set('services.openai.api_key', 'legacy-server-secret');
    config()->set('services.openai.allow_server_key_fallback', true);
    $token = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    Http::fake();

    $this->actingAs($user)->from(route('projects.generated-content.create', $project))
        ->post(route('projects.generated-content.store', $project), [
            'attempt_token' => $token,
            'content_type' => 'schrecknet_thread',
            'prompt' => 'Keep my prompt.',
            'api_key' => 'attacker-controlled-key',
        ])
        ->assertRedirect(route('projects.generated-content.create', $project))
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionMissing('_old_input.api_key');

    expect(GenerationAttempt::query()->sole()->status)->toBe(GenerationAttemptStatus::Issued)
        ->and($project->generatedContents()->exists())->toBeFalse();
    Http::assertNothingSent();
});
