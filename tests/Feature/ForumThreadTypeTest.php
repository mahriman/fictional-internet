<?php

use App\Actions\GenerateAndPersistContent;
use App\Actions\GenerateStructuredContent;
use App\Actions\GenerationAttemptManager;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\ForumThreadType;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('services.openai.api_key', 'configured-test-key');
    config()->set('services.openai.model', 'configured-test-model');
    config()->set('services.openai.timeout', 30);
});

/** @return array{forum_name: string, thread_title: string, category: string, started_at: string, posts: list<array{post_number: int, author: string, posted_at: string, body: string, reply_to_post_number: int|null, quote: array{post_number: int, text: string}|null}>} */
function validForumThread(): array
{
    return [
        'forum_name' => 'The Lantern Board',
        'thread_title' => 'Unusual lights over the inlet',
        'category' => 'Local observations',
        'started_at' => '2025-06-15T10:00:00+00:00',
        'posts' => [
            ['post_number' => 1, 'author' => 'MossCedar', 'posted_at' => '2025-06-15T10:02:00+00:00', 'body' => "I saw three lights above the inlet.\nThey moved west.", 'reply_to_post_number' => null, 'quote' => null],
            ['post_number' => 2, 'author' => 'TideClock', 'posted_at' => '2025-06-15T10:07:00+00:00', 'body' => 'Could have been reflections from the ferry.', 'reply_to_post_number' => null, 'quote' => null],
        ],
    ];
}

function legacyForumThread(): array
{
    $thread = validForumThread();

    foreach ($thread['posts'] as &$post) {
        unset($post['reply_to_post_number'], $post['quote']);
    }
    unset($post);

    return $thread;
}

function editableForumThread(array $thread): array
{
    foreach ($thread['posts'] as &$post) {
        unset($post['post_number']);
    }
    unset($post);

    return $thread;
}

function fakeForumThreadResponse(array $thread, string $responseId = 'resp_forum_thread'): array
{
    return [
        'id' => $responseId,
        'status' => 'completed',
        'model' => 'actual-test-model',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($thread, JSON_THROW_ON_ERROR),
            ]],
        ]],
        'usage' => ['input_tokens' => 40, 'output_tokens' => 90, 'total_tokens' => 130],
    ];
}

test('forum thread is registered alongside news article and exposes its complete definition', function () {
    $definition = app(ContentTypeRegistry::class)->get('forum_thread');
    $schema = $definition->outputSchema();

    expect($definition)->toBeInstanceOf(ForumThreadType::class)
        ->and($definition->key())->toBe('forum_thread')
        ->and($definition->label())->toBe('Forum Thread')
        ->and($definition->presentationView())->toBe('generated-content.types.forum-thread')
        ->and($definition->editingView())->toBe('generated-content.editors.forum-thread')
        ->and($definition->promptInstructions())->toContain('actual discussion')
        ->and($definition->promptInstructions())->toContain('numeric timezone offset')
        ->and($definition->promptInstructions())->toContain('exact, contiguous passage')
        ->and($definition->titleFromContent(validForumThread()))->toBe('Unusual lights over the inlet')
        ->and(array_keys(app(ContentTypeRegistry::class)->all()))->toBe(['news_article', 'forum_thread', 'schrecknet_thread'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['forum_name', 'thread_title', 'category', 'started_at', 'posts'])
        ->and($schema['properties']['posts']['minItems'])->toBe(1)
        ->and($schema['properties']['posts']['maxItems'])->toBe(ForumThreadType::MAX_POSTS)
        ->and($schema['properties']['posts']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['posts']['items']['required'])->toBe(['post_number', 'author', 'posted_at', 'body', 'reply_to_post_number', 'quote'])
        ->and($schema['properties']['posts']['items']['properties']['reply_to_post_number']['anyOf'][1]['type'])->toBe('null')
        ->and($schema['properties']['posts']['items']['properties']['quote']['anyOf'][0]['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['posts']['items']['properties']['quote']['anyOf'][0]['required'])->toBe(['post_number', 'text'])
        ->and($schema['properties']['posts']['items']['properties']['quote']['anyOf'][1]['type'])->toBe('null')
        ->and($schema['properties']['posts']['items']['properties']['post_number']['minimum'])->toBe(1);
});

test('the generation selector reads forum thread from the content type registry', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('value="forum_thread"', false)
        ->assertSee('Forum Thread');
});

test('forum threads generate and persist using generic project context and reference composition', function () {
    $project = Project::factory()->create();
    $project->context()->create([
        'setting' => 'Bellweather is a foggy harbor town.',
        'time_period' => null,
        'locations' => null,
        'people' => null,
        'organizations' => null,
        'canon_notes' => null,
    ]);
    $reference = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'forum_thread',
        'title' => 'Original title',
    ]);
    $referenceVersion = GeneratedContentVersion::factory()->for($reference)->create([
        'version_number' => 1,
        'content' => validForumThread(),
    ]);
    $thread = validForumThread();

    Http::fake(function (Request $request) use ($thread) {
        $definition = app(ContentTypeRegistry::class)->get('forum_thread');

        expect($request['instructions'])->toBe(app(ContentTypeRegistry::class)->get('forum_thread')->promptInstructions())
            ->and($request['text']['format']['type'])->toBe('json_schema')
            ->and($request['text']['format']['strict'])->toBeTrue()
            ->and($request['text']['format']['schema'])->toBe($definition->outputSchema())
            ->and($request['input'])->toContain('Bellweather is a foggy harbor town.')
            ->and($request['input'])->toContain('<<<GENERATED_CONTENT_REFERENCE_DATA>>>')
            ->and($request['input'])->toContain('Unusual lights over the inlet')
            ->and($request['input'])->toContain('TideClock')
            ->and($request['input'])->not->toContain('configured-test-key');

        return Http::response(fakeForumThreadResponse($thread));
    });

    $result = app(GenerateAndPersistContent::class)->handle(
        $project,
        'forum_thread',
        'Write a plausible discussion about the lights.',
        apiKey: 'forum-direct-test-key',
        references: [$reference->uuid.':'.$referenceVersion->version_number],
    );

    expect($result->generatedContent->content_type)->toBe('forum_thread')
        ->and($result->generatedContent->title)->toBe($thread['thread_title'])
        ->and($result->version->version_number)->toBe(1)
        ->and($result->version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and(canonicalizeJsonStructure($result->version->fresh()->content))->toBe(canonicalizeJsonStructure($thread))
        ->and(canonicalizeJsonStructure($result->version->fresh()->context_snapshot['references']))->toBe(canonicalizeJsonStructure([
            [
                'content_uuid' => $reference->uuid,
                'content_type' => 'forum_thread',
                'version_number' => 1,
                'title' => 'Unusual lights over the inlet',
                'content' => $referenceVersion->fresh()->content,
            ],
        ]));
});

test('a legacy forum version remains usable as a version-specific generated-content reference', function () {
    $project = Project::factory()->create();
    $source = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $legacyThread = legacyForumThread();
    $sourceVersion = GeneratedContentVersion::factory()->for($source)->create(['content' => $legacyThread]);
    Http::fake(function (Request $request) {
        expect($request['input'])->toContain('Unusual lights over the inlet')
            ->and($request['input'])->toContain('Could have been reflections from the ferry.');

        return Http::response(fakeForumThreadResponse(validForumThread()));
    });

    $result = app(GenerateAndPersistContent::class)->handle(
        $project,
        'forum_thread',
        'Write a follow-up forum discussion.',
        apiKey: 'forum-direct-test-key',
        references: [$source->uuid.':'.$sourceVersion->version_number],
    );

    expect(canonicalizeJsonStructure($result->version->context_snapshot['references'][0]['content']))
        ->toBe(canonicalizeJsonStructure($legacyThread));
});

test('thread chronology compares timestamps as instants rather than lexically', function () {
    $thread = validForumThread();
    $thread['started_at'] = '2025-06-15T07:00:00+00:00';
    $thread['posts'][0]['posted_at'] = '2025-06-15T10:00:00+02:00';
    $thread['posts'][1]['posted_at'] = '2025-06-15T09:30:00+01:00';

    expect($thread['posts'][1]['posted_at'])->toBeLessThan($thread['posts'][0]['posted_at'])
        ->and(app(ContentTypeRegistry::class)->get('forum_thread')->semanticValidationErrors($thread))->toBe([]);

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);

    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        'forum_thread',
        'Write a thread with a timezone change.',
        apiKey: 'forum-direct-test-key',
    );

    expect($result->version->fresh()->content['posts'][0]['posted_at'])->toBe('2025-06-15T10:00:00+02:00')
        ->and($result->version->fresh()->content['posts'][1]['posted_at'])->toBe('2025-06-15T09:30:00+01:00');
});

test('equal post timestamps and the maximum thread size are accepted', function () {
    $thread = validForumThread();
    $thread['posts'] = [];

    for ($postNumber = 1; $postNumber <= ForumThreadType::MAX_POSTS; $postNumber++) {
        $thread['posts'][] = [
            'post_number' => $postNumber,
            'author' => 'Poster'.$postNumber,
            'posted_at' => '2025-06-15T10:02:00+00:00',
            'body' => 'Post '.$postNumber,
            'reply_to_post_number' => null,
            'quote' => null,
        ];
    }

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);

    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        'forum_thread',
        'Write a twenty-post thread.',
        apiKey: 'forum-direct-test-key',
    );

    expect($result->version->fresh()->content['posts'])->toHaveCount(ForumThreadType::MAX_POSTS);
});

test('forum thread requests use the existing generation attempt idempotency flow', function () {
    $project = Project::factory()->create();
    $user = $project->user;
    $this->actingAs($user);
    OpenAiCredential::factory()->for($user)->create(['api_key' => 'forum-personal-test-key']);
    config()->set('services.openai.allow_server_key_fallback', true);
    $token = app(GenerationAttemptManager::class)->tokenForForm($user, $project, null);
    $requestFields = [
        'attempt_token' => $token,
        'content_type' => 'forum_thread',
        'prompt' => 'Write a short discussion about a lost signal.',
    ];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse(validForumThread()))]);

    $firstResponse = $this->post(route('projects.generated-content.store', $project), $requestFields);
    $createdContent = $project->generatedContents()->sole();
    $firstResponse->assertRedirect(route('projects.generated-content.show', [$project, $createdContent]));

    $this->post(route('projects.generated-content.store', $project), $requestFields)
        ->assertRedirect(route('projects.generated-content.show', [$project, $createdContent]));

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)
        ->toBe(GenerationAttemptStatus::Completed)
        ->and($project->generatedContents()->count())->toBe(1)
        ->and($createdContent->versions()->count())->toBe(1);

    Http::assertSentCount(1);
});

test('forum thread generation rejects invalid post numbering and chronology before persistence', function (array $thread) {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);
    $project = Project::factory()->create();

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'forum_thread', 'Write a forum discussion.', 'forum-direct-test-key'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated content failed validation.');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
})->with([
    'nonsequential number' => [array_replace_recursive(validForumThread(), ['posts' => [['post_number' => 1], ['post_number' => 3]]])],
    'duplicate number' => [array_replace_recursive(validForumThread(), ['posts' => [['post_number' => 1], ['post_number' => 1]]])],
    'zero number' => [array_replace_recursive(validForumThread(), ['posts' => [['post_number' => 0]]])],
    'negative number' => [array_replace_recursive(validForumThread(), ['posts' => [['post_number' => -1]]])],
    'out of order' => [array_replace_recursive(validForumThread(), ['posts' => [['post_number' => 2], ['post_number' => 1]]])],
    'invalid timestamp' => [array_replace_recursive(validForumThread(), ['started_at' => 'yesterday'])],
    'invalid calendar date' => [array_replace_recursive(validForumThread(), ['started_at' => '2025-02-30T10:00:00+00:00'])],
    'invalid timezone offset' => [array_replace_recursive(validForumThread(), ['started_at' => '2025-06-15T10:00:00+25:00'])],
    'invalid post timezone offset' => [array_replace_recursive(validForumThread(), [
        'posts' => [['posted_at' => '2025-06-15T10:02:00+25:00']],
    ])],
    'decreasing timestamps' => [array_replace_recursive(validForumThread(), [
        'posts' => [
            ['posted_at' => '2025-06-15T10:08:00+00:00'],
            ['posted_at' => '2025-06-15T10:07:00+00:00'],
        ],
    ])],
    'opening post before thread start' => [array_replace_recursive(validForumThread(), ['started_at' => '2025-06-15T10:03:00+00:00'])],
    'empty posts' => [array_replace(validForumThread(), ['posts' => []])],
    'unexpected nested field' => [array_replace_recursive(validForumThread(), ['posts' => [['extra' => 'nope']]])],
    'too many posts' => [array_replace(validForumThread(), ['posts' => array_fill(0, ForumThreadType::MAX_POSTS + 1, validForumThread()['posts'][0])])],
]);

test('forum thread generation preserves valid reply-only quote-only and independent reply and quote targets', function () {
    $thread = validForumThread();
    $thread['posts'][0]['body'] = 'I saw café lights over the inlet, and the ferry was nearby.';
    $thread['posts'][1]['reply_to_post_number'] = 1;
    $thread['posts'][1]['body'] = 'Could have been reflections from the ferry.';
    $thread['posts'][2] = [
        'post_number' => 3,
        'author' => 'NorthWindow',
        'posted_at' => '2025-06-15T10:09:00+00:00',
        'body' => 'That still does not explain the timing.',
        'reply_to_post_number' => null,
        'quote' => ['post_number' => 1, 'text' => 'café lights over the inlet'],
    ];
    $thread['posts'][3] = [
        'post_number' => 4,
        'author' => 'TideClock',
        'posted_at' => '2025-06-15T10:11:00+00:00',
        'body' => 'I was answering NorthWindow, but the ferry was still my first thought.',
        'reply_to_post_number' => 1,
        'quote' => ['post_number' => 2, 'text' => 'reflections from the ferry'],
    ];

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);

    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        'forum_thread',
        'Write a conversation with one reply, one quote, and a later reply that quotes a different post.',
        apiKey: 'forum-direct-test-key',
    );

    expect(canonicalizeJsonStructure($result->version->fresh()->content))->toBe(canonicalizeJsonStructure($thread))
        ->and($result->version->content['posts'][1]['reply_to_post_number'])->toBe(1)
        ->and($result->version->content['posts'][1]['quote'])->toBeNull()
        ->and($result->version->content['posts'][2]['reply_to_post_number'])->toBeNull()
        ->and($result->version->content['posts'][2]['quote']['post_number'])->toBe(1)
        ->and($result->version->content['posts'][3]['reply_to_post_number'])->toBe(1)
        ->and($result->version->content['posts'][3]['quote']['post_number'])->toBe(2);
});

test('forum generation rejects invalid reply and quote references before persistence', function (string $case) {
    $thread = validForumThread();
    $thread['posts'][] = [
        'post_number' => 3,
        'author' => 'NorthWindow',
        'posted_at' => '2025-06-15T10:09:00+00:00',
        'body' => 'The lights were very bright.',
        'reply_to_post_number' => null,
        'quote' => null,
    ];

    match ($case) {
        'opening reply' => $thread['posts'][0]['reply_to_post_number'] = 1,
        'opening quote' => $thread['posts'][0]['quote'] = ['post_number' => 1, 'text' => 'three lights'],
        'current reply' => $thread['posts'][1]['reply_to_post_number'] = 2,
        'future reply' => $thread['posts'][1]['reply_to_post_number'] = 3,
        'nonexistent reply' => $thread['posts'][1]['reply_to_post_number'] = 9,
        'current quote' => $thread['posts'][1]['quote'] = ['post_number' => 2, 'text' => 'reflections'],
        'future quote' => $thread['posts'][1]['quote'] = ['post_number' => 3, 'text' => 'bright'],
        'nonexistent quote' => $thread['posts'][1]['quote'] = ['post_number' => 9, 'text' => 'bright'],
        'paraphrased quote' => $thread['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'I saw some lights'],
        'noninteger reply' => $thread['posts'][1]['reply_to_post_number'] = '1',
        'boolean reply' => $thread['posts'][1]['reply_to_post_number'] = true,
        'noninteger quote target' => $thread['posts'][1]['quote'] = ['post_number' => '1', 'text' => 'three lights'],
        'boolean quote target' => $thread['posts'][1]['quote'] = ['post_number' => true, 'text' => 'three lights'],
        'quote without text' => $thread['posts'][1]['quote'] = ['post_number' => 1],
        'empty quote text' => $thread['posts'][1]['quote'] = ['post_number' => 1, 'text' => " \t"],
        'unexpected quote property' => $thread['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'three lights', 'html' => '<b>forged</b>'],
    };

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);
    $project = Project::factory()->create();

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'forum_thread', 'Write a thread.', 'forum-direct-test-key'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated content failed validation.');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
})->with([
    'opening post cannot reply' => ['opening reply'],
    'opening post cannot quote' => ['opening quote'],
    'reply cannot target current post' => ['current reply'],
    'reply cannot target a future post' => ['future reply'],
    'reply cannot target a nonexistent post' => ['nonexistent reply'],
    'quote cannot target current post' => ['current quote'],
    'quote cannot target a future post' => ['future quote'],
    'quote cannot target a nonexistent post' => ['nonexistent quote'],
    'paraphrased quote is rejected' => ['paraphrased quote'],
    'reply reference must be an integer' => ['noninteger reply'],
    'boolean reply reference is rejected' => ['boolean reply'],
    'quote reference must be an integer' => ['noninteger quote target'],
    'boolean quote reference is rejected' => ['boolean quote target'],
    'quote object must contain text' => ['quote without text'],
    'quote text cannot be empty' => ['empty quote text'],
    'unexpected quote fields are rejected' => ['unexpected quote property'],
]);

test('semantic quote failures expose only a safe category and field path for diagnostics', function () {
    $thread = validForumThread();
    $thread['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'The lights were definitely red.'];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);

    try {
        app(GenerateStructuredContent::class)->handle('forum_thread', 'Write a thread with a quotation.', 'forum-test-key');
        test()->fail('A paraphrased quotation should be rejected.');
    } catch (StructuredContentGenerationException $exception) {
        expect($exception->diagnosticCategory)->toBe('semantic_validation')
            ->and($exception->fieldPaths)->toContain('content.posts.1.quote.text')
            ->and($exception->getMessage())->toBe('The generated content failed validation.')
            ->and($exception->fieldPaths)->not->toContain($thread['posts'][1]['quote']['text']);
    }
});

test('newly generated forum posts must explicitly contain nullable reply and quote fields', function () {
    $thread = validForumThread();
    unset($thread['posts'][1]['quote']);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);
    $project = Project::factory()->create();

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'forum_thread', 'Write a thread.', 'forum-direct-test-key'))
        ->toThrow(StructuredContentGenerationException::class, 'The generated content failed validation.');

    expect($project->generatedContents()->count())->toBe(0)
        ->and(GeneratedContentVersion::query()->count())->toBe(0);
});

test('forum thread presentation escapes values and preserves multiline post bodies', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $thread = validForumThread();
    $thread['thread_title'] = '<script>alert("title")</script>';
    $thread['posts'][0]['body'] = "<img src=x onerror=alert(1)>\nA second line.";
    $thread['posts'][1]['reply_to_post_number'] = 1;
    $thread['posts'][1]['quote'] = ['post_number' => 1, 'text' => '<img src=x onerror=alert(1)>'];
    $version = GeneratedContentVersion::factory()->for($content)->create(['content' => $thread]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $content, $version->version_number]))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('whitespace-pre-wrap', false)
        ->assertSee('Replying to <a href="#forum-post-1"', false)
        ->assertSee('Quoting <a href="#forum-post-1"', false)
        ->assertDontSee('<script>alert("title")</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
});

test('legacy forum versions without reply fields remain readable and can be edited into the 7B shape', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $legacyContent = legacyForumThread();
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $legacyContent]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('Unusual lights over the inlet')
        ->assertDontSee('Replying to')
        ->assertDontSee('Quoting');

    $this->get(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('No reply target')
        ->assertSee('No quote');

    $edited = editableForumThread($legacyContent);
    $this->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), ['content' => $edited])
        ->assertRedirect(route('projects.generated-content.versions.show', [$project, $generatedContent, 2]));

    $newVersion = $generatedContent->versions()->where('version_number', 2)->firstOrFail();

    expect(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($legacyContent))
        ->and($newVersion->content['posts'][0]['reply_to_post_number'])->toBeNull()
        ->and($newVersion->content['posts'][0]['quote'])->toBeNull()
        ->and($newVersion->content['posts'][1]['reply_to_post_number'])->toBeNull()
        ->and($newVersion->content['posts'][1]['quote'])->toBeNull()
        ->and($newVersion->based_on_version_id)->toBe($source->id)
        ->and($newVersion->generation_metadata)->toBeNull();

    Http::assertNothingSent();
});

test('malformed historical reply and quote values render and prefill safely', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $thread = legacyForumThread();
    $thread['posts'][1]['reply_to_post_number'] = ['unexpected'];
    $thread['posts'][1]['quote'] = ['post_number' => ['invalid'], 'text' => ['invalid']];
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $thread]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $generatedContent, $version->version_number]))
        ->assertOk()
        ->assertDontSee('href="#forum-post-')
        ->assertDontSee('Quoting');

    $this->get(route('projects.generated-content.versions.edit', [$project, $generatedContent, $version->version_number]))
        ->assertOk()
        ->assertSee('content_posts_1_reply_to_post_number', false)
        ->assertSee('content_posts_1_quote_post_number', false);

    Http::assertNothingSent();
});

test('editing validates quote excerpts against final submitted source post bodies', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $sourceContent = validForumThread();
    $sourceContent['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'three lights above the inlet'];
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $sourceContent]);
    $editedContent = editableForumThread($sourceContent);
    $editedContent['posts'][0]['body'] = 'I saw lanterns over the inlet.';

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), ['content' => $editedContent])
        ->assertRedirect(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->assertSessionHasErrors('content.posts.1.quote.text')
        ->assertSessionHas('_old_input.content.posts.0.body', 'I saw lanterns over the inlet.')
        ->assertSessionHas('_old_input.content.posts.1.quote.text', 'three lights above the inlet');

    expect($generatedContent->versions()->count())->toBe(1)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent));

    Http::assertNothingSent();
});

test('editing a selected historical thread creates an immutable descendant and preserves post numbers', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'forum_thread',
        'title' => 'Original project title',
    ]);
    $sourceContent = validForumThread();
    $sourceContent['posts'][0]['post_number'] = 1;
    $sourceContent['posts'][1]['post_number'] = 2;
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $sourceContent,
        'context_snapshot' => [
            'content_type' => 'forum_thread',
            'prompt' => 'original request',
            'instructions' => 'original instructions',
            'project_context' => ['setting' => 'Old project context.'],
            'references' => [['content_uuid' => (string) Str::uuid(), 'version_number' => 7, 'content' => ['body' => 'Captured reference.']]],
        ],
        'generation_metadata' => ['model' => 'original-model'],
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 2]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 3]);

    $edit = $sourceContent;
    unset($edit['posts'][0]['post_number'], $edit['posts'][1]['post_number']);
    $edit['thread_title'] = 'Edited thread title';
    $edit['posts'][0]['body'] = "Edited safely\nwith another line.";

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('name="content[posts][0][author]"', false)
        ->assertSee('value="2025-06-15T10:02:00+00:00"', false)
        ->assertDontSee('name="content[posts][0][post_number]"', false);

    $this->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), ['content' => $edit])
        ->assertRedirect(route('projects.generated-content.versions.show', [$project, $generatedContent, 4]));

    $newVersion = $generatedContent->versions()->where('version_number', 4)->firstOrFail();

    expect(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and($newVersion->content['thread_title'])->toBe('Edited thread title')
        ->and($newVersion->content['posts'][0]['post_number'])->toBe(1)
        ->and($newVersion->content['posts'][1]['post_number'])->toBe(2)
        ->and($newVersion->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($newVersion->based_on_version_id)->toBe($source->id)
        ->and(canonicalizeJsonStructure($newVersion->context_snapshot))->toBe(canonicalizeJsonStructure($source->context_snapshot))
        ->and($newVersion->generation_metadata)->toBeNull();

    Http::assertNothingSent();
});

test('editing can change reply and quote relationships while preserving immutable post order', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $sourceContent = validForumThread();
    $sourceContent['posts'][1]['reply_to_post_number'] = 1;
    $sourceContent['posts'][2] = [
        'post_number' => 3,
        'author' => 'NorthWindow',
        'posted_at' => '2025-06-15T10:09:00+00:00',
        'body' => 'I agree with the earlier observation.',
        'reply_to_post_number' => 2,
        'quote' => ['post_number' => 1, 'text' => 'three lights above the inlet'],
    ];
    $sourceContent['posts'][3] = [
        'post_number' => 4,
        'author' => 'TideClock',
        'posted_at' => '2025-06-15T10:11:00+00:00',
        'body' => 'A final thought for the thread.',
        'reply_to_post_number' => 3,
        'quote' => ['post_number' => 1, 'text' => 'three lights above the inlet'],
    ];
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $sourceContent]);
    $submitted = editableForumThread($sourceContent);
    $submitted['posts'][1]['reply_to_post_number'] = null;
    $submitted['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'three lights above the inlet'];
    $submitted['posts'][2]['reply_to_post_number'] = 1;
    $submitted['posts'][2]['quote'] = ['post_number' => 2, 'text' => 'reflections from the ferry'];
    $submitted['posts'][3]['reply_to_post_number'] = null;
    $submitted['posts'][3]['quote'] = ['post_number' => null, 'text' => null];

    $editUrl = route('projects.generated-content.versions.edit', [$project, $generatedContent, 1]);
    $this->actingAs($project->user)
        ->get($editUrl)
        ->assertOk()
        ->assertSee('content[posts][1][reply_to_post_number]', false)
        ->assertSee('content[posts][1][quote][post_number]', false)
        ->assertSee('exact continuous excerpt');

    $this->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, 1]), ['content' => $submitted])
        ->assertRedirect(route('projects.generated-content.versions.show', [$project, $generatedContent, 2]));

    $editedVersion = $generatedContent->versions()->where('version_number', 2)->firstOrFail();

    expect($editedVersion->content['posts'][1]['reply_to_post_number'])->toBeNull()
        ->and(canonicalizeJsonStructure($editedVersion->content['posts'][1]['quote']))->toBe(canonicalizeJsonStructure(['post_number' => 1, 'text' => 'three lights above the inlet']))
        ->and($editedVersion->content['posts'][2]['reply_to_post_number'])->toBe(1)
        ->and(canonicalizeJsonStructure($editedVersion->content['posts'][2]['quote']))->toBe(canonicalizeJsonStructure(['post_number' => 2, 'text' => 'reflections from the ferry']))
        ->and($editedVersion->content['posts'][3]['reply_to_post_number'])->toBeNull()
        ->and($editedVersion->content['posts'][3]['quote'])->toBeNull()
        ->and($editedVersion->content['posts'][0]['post_number'])->toBe(1)
        ->and($editedVersion->content['posts'][2]['post_number'])->toBe(3)
        ->and($editedVersion->based_on_version_id)->toBe($source->id)
        ->and($editedVersion->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($editedVersion->generation_metadata)->toBeNull();

    Http::assertNothingSent();
});

test('historical thread detail derives its title from the selected version', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'forum_thread',
        'title' => 'Initial project overview title',
    ]);
    $olderContent = validForumThread();
    $olderContent['thread_title'] = 'Title from version one';
    $olderVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $olderContent,
    ]);
    $newerContent = validForumThread();
    $newerContent['thread_title'] = 'Title from version two';
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'content' => $newerContent,
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $generatedContent, $olderVersion->version_number]))
        ->assertOk()
        ->assertSee('<title>Title from version one · '.$project->name.'</title>', false)
        ->assertSee('Title from version one');

    Http::assertNothingSent();
});

test('forum editor rejects post-number tampering, changed post shape, and chronological violations', function (string $case) {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $thread = validForumThread();
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $thread]);
    $submitted = $thread;
    unset($submitted['posts'][0]['post_number'], $submitted['posts'][1]['post_number']);
    match ($case) {
        'post_number' => $submitted['posts'][0]['post_number'] = 77,
        'extra_field' => $submitted['posts'][0]['moderator_note'] = 'forged',
        'extra_quote_field' => $submitted['posts'][1]['quote'] = ['post_number' => 1, 'text' => 'three lights', 'private_note' => 'do not flash'],
        'extra_root_field' => $submitted['forged'] = 'forged',
        'remove_post' => array_pop($submitted['posts']),
        'add_post' => $submitted['posts'][] = $submitted['posts'][1],
        'sparse_posts' => $submitted['posts'] = [0 => $submitted['posts'][0], 2 => $submitted['posts'][1]],
        'reordered_indices' => $submitted['posts'] = [1 => $submitted['posts'][0], 0 => $submitted['posts'][1]],
        'decreasing_posts' => $submitted['posts'][1]['posted_at'] = '2025-06-15T10:01:00+00:00',
        'opening_before_start' => $submitted['started_at'] = '2025-06-15T10:03:00+00:00',
        'empty_author' => $submitted['posts'][0]['author'] = '',
        'empty_body' => $submitted['posts'][0]['body'] = '',
        'invalid_timestamp' => $submitted['posts'][0]['posted_at'] = 'tomorrow',
    };

    $response = $this->actingAs($project->user)
        ->from(route('projects.generated-content.versions.edit', [$project, $generatedContent, $source->version_number]))
        ->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, $source->version_number]), [
            'content' => $submitted,
            'api_key' => 'must-not-flash',
            'version_number' => 99,
        ])
        ->assertRedirect(route('projects.generated-content.versions.edit', [$project, $generatedContent, $source->version_number]))
        ->assertSessionHasErrors()
        ->assertSessionMissingInput('api_key')
        ->assertSessionMissingInput('version_number')
        ->assertSessionMissingInput('content.posts.0.post_number')
        ->assertSessionMissingInput('content.posts.0.moderator_note')
        ->assertSessionMissingInput('content.posts.1.quote.private_note')
        ->assertSessionMissingInput('content.forged');

    if (array_is_list($submitted['posts']) && count($submitted['posts']) === count($thread['posts'])) {
        $response->assertSessionHas('_old_input.content.posts.0.author', $submitted['posts'][0]['author']);
    } else {
        $response->assertSessionMissing('_old_input.content.posts');
    }

    expect($generatedContent->versions()->count())->toBe(1)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($thread));
})->with([
    'post numbers are rejected as browser fields' => ['post_number'],
    'additional post properties are rejected' => ['extra_field'],
    'additional quote properties are rejected and not flashed' => ['extra_quote_field'],
    'additional thread properties are rejected' => ['extra_root_field'],
    'a post cannot be removed' => ['remove_post'],
    'a post cannot be added' => ['add_post'],
    'sparse post indexes are rejected' => ['sparse_posts'],
    'reordered post indexes are rejected' => ['reordered_indices'],
    'post timestamps cannot decrease' => ['decreasing_posts'],
    'opening post cannot precede thread start' => ['opening_before_start'],
    'empty author is rejected' => ['empty_author'],
    'empty body is rejected' => ['empty_body'],
    'invalid timestamp is rejected' => ['invalid_timestamp'],
]);

test('forum edit rejects request-level credentials and metadata fields without flashing them', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $thread = validForumThread();
    $source = GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $thread]);
    $submitted = $thread;
    unset($submitted['posts'][0]['post_number'], $submitted['posts'][1]['post_number']);

    $this->actingAs($project->user)
        ->from(route('projects.generated-content.versions.edit', [$project, $generatedContent, $source->version_number]))
        ->post(route('projects.generated-content.versions.edits.store', [$project, $generatedContent, $source->version_number]), [
            'content' => $submitted,
            'api_key' => 'must-not-be-used-or-flashed',
            'origin' => 'ai_generated',
            'version_number' => 99,
            'context_snapshot' => ['forged' => true],
            'generation_metadata' => ['forged' => true],
        ])
        ->assertRedirect(route('projects.generated-content.versions.edit', [$project, $generatedContent, $source->version_number]))
        ->assertSessionHasErrors(['api_key', 'origin', 'version_number', 'context_snapshot', 'generation_metadata'])
        ->assertSessionMissingInput('api_key')
        ->assertSessionMissingInput('origin')
        ->assertSessionMissingInput('version_number')
        ->assertSessionMissingInput('context_snapshot')
        ->assertSessionMissingInput('generation_metadata');

    expect($generatedContent->versions()->count())->toBe(1)
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($thread));

    Http::assertNothingSent();
});
