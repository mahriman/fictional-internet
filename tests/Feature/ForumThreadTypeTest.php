<?php

use App\Actions\GenerateAndPersistContent;
use App\Actions\GenerationAttemptManager;
use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\ForumThreadType;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Exceptions\StructuredContentGenerationException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
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

/** @return array{forum_name: string, thread_title: string, category: string, started_at: string, posts: list<array{post_number: int, author: string, posted_at: string, body: string}>} */
function validForumThread(): array
{
    return [
        'forum_name' => 'The Lantern Board',
        'thread_title' => 'Unusual lights over the inlet',
        'category' => 'Local observations',
        'started_at' => '2025-06-15T10:00:00+00:00',
        'posts' => [
            ['post_number' => 1, 'author' => 'MossCedar', 'posted_at' => '2025-06-15T10:02:00+00:00', 'body' => "I saw three lights above the inlet.\nThey moved west."],
            ['post_number' => 2, 'author' => 'TideClock', 'posted_at' => '2025-06-15T10:07:00+00:00', 'body' => 'Could have been reflections from the ferry.'],
        ],
    ];
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
        ->and($definition->titleFromContent(validForumThread()))->toBe('Unusual lights over the inlet')
        ->and(array_keys(app(ContentTypeRegistry::class)->all()))->toBe(['news_article', 'forum_thread'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toBe(['forum_name', 'thread_title', 'category', 'started_at', 'posts'])
        ->and($schema['properties']['posts']['minItems'])->toBe(1)
        ->and($schema['properties']['posts']['maxItems'])->toBe(ForumThreadType::MAX_POSTS)
        ->and($schema['properties']['posts']['items']['additionalProperties'])->toBeFalse()
        ->and($schema['properties']['posts']['items']['required'])->toBe(['post_number', 'author', 'posted_at', 'body'])
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
        expect($request['instructions'])->toBe(app(ContentTypeRegistry::class)->get('forum_thread')->promptInstructions())
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
        ];
    }

    Http::fake(['https://api.openai.com/v1/responses' => Http::response(fakeForumThreadResponse($thread))]);

    $result = app(GenerateAndPersistContent::class)->handle(
        Project::factory()->create(),
        'forum_thread',
        'Write a twenty-post thread.',
    );

    expect($result->version->fresh()->content['posts'])->toHaveCount(ForumThreadType::MAX_POSTS);
});

test('forum thread requests use the existing generation attempt idempotency flow', function () {
    $project = Project::factory()->create();
    $user = $project->user;
    $this->actingAs($user);
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

    expect(fn () => app(GenerateAndPersistContent::class)->handle($project, 'forum_thread', 'Write a forum discussion.'))
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

test('forum thread presentation escapes values and preserves multiline post bodies', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $thread = validForumThread();
    $thread['thread_title'] = '<script>alert("title")</script>';
    $thread['posts'][0]['body'] = "<img src=x onerror=alert(1)>\nA second line.";
    $version = GeneratedContentVersion::factory()->for($content)->create(['content' => $thread]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $content, $version->version_number]))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('whitespace-pre-wrap', false)
        ->assertDontSee('<script>alert("title")</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);
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
