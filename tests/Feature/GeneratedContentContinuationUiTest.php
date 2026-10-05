<?php

use App\Actions\GenerationAttemptManager;
use App\Enums\GeneratedContentVersionOrigin;
use App\Enums\GenerationAttemptStatus;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->artisan('migrate:fresh')->assertExitCode(0);
    Http::preventStrayRequests();
    config()->set('services.openai.model', 'continuation-ui-test-model');
    config()->set('services.openai.timeout', 30);
});

/** @return array<string, mixed> */
function continuationUiSource(string $type, string $body = 'The eastern relay went dark at midnight.'): array
{
    $entry = [
        'posted_at' => '2026-10-05T10:00:00+00:00',
        'body' => $body,
    ];

    if ($type === 'forum_thread') {
        return [
            'forum_name' => 'Harbor Board',
            'thread_title' => 'Relay outage',
            'category' => 'Local',
            'started_at' => '2026-10-05T09:59:00+00:00',
            'posts' => [[
                'post_number' => 1,
                'author' => 'Mica',
                ...$entry,
                'reply_to_post_number' => null,
                'quote' => null,
            ]],
        ];
    }

    return [
        'network' => 'SchreckNet',
        'channel' => 'harbor/quiet',
        'thread_title' => 'Relay outage',
        'started_at' => '2026-10-05T09:59:00+00:00',
        'messages' => [[
            'message_number' => 1,
            'handle' => 'Mica',
            ...$entry,
            'reply_to_message_number' => null,
            'quote' => null,
        ]],
    ];
}

/** @return array<string, mixed> */
function continuationUiProposal(string $type, string $body = 'A second relay just came back online.'): array
{
    if ($type === 'forum_thread') {
        return ['entries' => [[
            'author' => 'Rook',
            'posted_at' => '2026-10-05T10:01:00+00:00',
            'body' => $body,
            'reply_to_post_number' => 1,
            'quote' => null,
        ]]];
    }

    return ['entries' => [[
        'handle' => 'Rook',
        'posted_at' => '2026-10-05T10:01:00+00:00',
        'body' => $body,
        'reply_to_message_number' => 1,
        'quote' => null,
    ]]];
}

/** @return array<string, mixed> */
function continuationUiProviderResponse(array $proposal, string $id = 'resp_continuation_ui'): array
{
    return [
        'id' => $id,
        'status' => 'completed',
        'model' => 'continuation-ui-actual-model',
        'usage' => ['input_tokens' => 30, 'output_tokens' => 25, 'total_tokens' => 55],
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]],
        ]],
    ];
}

function continuationUiRoute(Project $project, GeneratedContent $content, int $versionNumber, string $action): string
{
    return route('projects.generated-content.versions.continuations.'.$action, [$project, $content, $versionNumber]);
}

function continuationUiAttempt(Project $project, GeneratedContent $content, GeneratedContentVersion $source): string
{
    return app(GenerationAttemptManager::class)->tokenForForm(
        $project->user,
        $project,
        null,
        $content,
        $source,
    );
}

function continuationUiCredential(Project $project): void
{
    OpenAiCredential::factory()->for($project->user)->create(['api_key' => 'continuation-ui-personal-key']);
}

test('owners can open Forum Thread and SchreckNet continuation forms with source-bound opaque attempts', function (string $type) {
    $project = Project::factory()->create();
    continuationUiCredential($project);
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => $type]);
    $source = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 1,
        'content' => continuationUiSource($type),
    ]);
    $newer = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $source->id,
        'content' => continuationUiSource($type, 'A later branch appeared.'),
    ]);

    $response = $this->actingAs($project->user)
        ->get(continuationUiRoute($project, $content, 1, 'create'))
        ->assertOk()
        ->assertSee('Continue '.($type === 'forum_thread' ? 'Forum Thread' : 'SchreckNet Thread').' from version 1')
        ->assertSee('Current entries')
        ->assertSee('1 of '.($type === 'forum_thread' ? '20' : '30'))
        ->assertSee('Newer versions already exist')
        ->assertSee('branch from this selected version')
        ->assertSee('Continuation source:')
        ->assertSee('exact base document being extended')
        ->assertSee('Project Context')
        ->assertSee('supplied automatically with the continuation when configured')
        ->assertSee('separate from the selected source version')
        ->assertSee('Review Project Context')
        ->assertSee('name="references[]"', false)
        ->assertSee('optional, up to five versions')
        ->assertSee('exact immutable versions')
        ->assertSee('order shown here')
        ->assertSee('not appended as discussion entries')
        ->assertSee('may charge your account')
        ->assertSee('data-generation-form', false)
        ->assertSee('data-generation-submit', false)
        ->assertSee('data-progress-label="Continuing…"', false)
        ->assertSee('role="status" aria-live="polite" aria-atomic="true"', false)
        ->assertSee($content->uuid.':2', false);

    $token = $response->viewData('attemptToken');
    $attempt = GenerationAttempt::query()->sole();

    expect($token)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($token)->not->toBe(session()->token())
        ->and($attempt->target_generated_content_id)->toBe($content->id)
        ->and($attempt->source_version_id)->toBe($source->id)
        ->and($attempt->status)->toBe(GenerationAttemptStatus::Issued)
        ->and($newer->version_number)->toBe(2);

    $response->assertSee('name="attempt_token" value="'.$token.'"', false)
        ->assertDontSee('continuation-ui-personal-key')
        ->assertDontSee('name="api_key"', false);

    $latestForm = $this->get(continuationUiRoute($project, $content, 2, 'create'))
        ->assertOk()
        ->assertSee('Continue '.($type === 'forum_thread' ? 'Forum Thread' : 'SchreckNet Thread').' from version 2')
        ->assertDontSee('Newer versions already exist');
    $latestToken = $latestForm->viewData('attemptToken');

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $latestToken))->sole()->source_version_id)
        ->toBe($newer->id);
})->with(['forum_thread', 'schrecknet_thread']);

test('selected version detail links to its own continuation and a full discussion has no usable action', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = continuationUiSource('forum_thread');
    $full = $source;
    $full['posts'] = [];
    for ($number = 1; $number <= 20; $number++) {
        $full['posts'][] = [
            'post_number' => $number,
            'author' => 'User '.$number,
            'posted_at' => '2026-10-05T10:00:00+00:00',
            'body' => 'Post '.$number,
            'reply_to_post_number' => $number === 1 ? null : 1,
            'quote' => null,
        ];
    }
    $first = GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1, 'content' => $source]);
    GeneratedContentVersion::factory()->for($content)->create(['version_number' => 2, 'content' => $full]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $content, 1]))
        ->assertOk()
        ->assertSee('Continue from version 1');

    $this->get(route('projects.generated-content.versions.show', [$project, $content, 2]))
        ->assertOk()
        ->assertSee('maximum supported size')
        ->assertDontSee('Continue from version 2');

    $this->get(continuationUiRoute($project, $content, 2, 'create'))
        ->assertOk()
        ->assertSee('cannot be continued from this version')
        ->assertDontSee('name="continuation_instructions"', false);

    expect($first->version_number)->toBe(1);
    Http::assertNothingSent();
});

test('entry count options stop at remaining capacity and the server rejects an impossible requested count', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = continuationUiSource('forum_thread');
    $source['posts'] = [];

    for ($number = 1; $number <= 19; $number++) {
        $source['posts'][] = [
            'post_number' => $number,
            'author' => 'Writer '.$number,
            'posted_at' => '2026-10-05T10:00:00+00:00',
            'body' => 'Existing entry '.$number,
            'reply_to_post_number' => $number === 1 ? null : 1,
            'quote' => null,
        ];
    }
    $version = GeneratedContentVersion::factory()->for($content)->create(['content' => $source]);
    $formUrl = continuationUiRoute($project, $content, 1, 'create');
    $form = $this->actingAs($project->user)->get($formUrl)
        ->assertOk()
        ->assertSee('Remaining capacity')
        ->assertSee('19 of 20')
        ->assertSee('<option value="1"', false)
        ->assertDontSee('<option value="2"', false);
    $token = $form->viewData('attemptToken');
    Http::fake();

    $this->from($formUrl)
        ->post(continuationUiRoute($project, $content, 1, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Add another response.',
            'entry_count' => '2',
        ])
        ->assertRedirect($formUrl)
        ->assertSessionHasErrors('entry_count')
        ->assertSessionHas('_old_input.attempt_token', $token);

    expect($version->fresh()->version_number)->toBe(1)
        ->and($content->versions()->count())->toBe(1)
        ->and(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)
        ->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
});

test('guests, nonowners, cross-project content and cross-content versions cannot reach continuation', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $otherProject = Project::factory()->create();
    $foreignContent = GeneratedContent::factory()->for($otherProject)->create(['content_type' => 'forum_thread']);
    $foreignVersion = GeneratedContentVersion::factory()->for($foreignContent)->create([
        'version_number' => 7,
        'content' => continuationUiSource('forum_thread'),
    ]);
    $otherContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $otherVersion = GeneratedContentVersion::factory()->for($otherContent)->create([
        'version_number' => 3,
        'content' => continuationUiSource('forum_thread'),
    ]);

    $this->get(continuationUiRoute($project, $content, 1, 'create'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(continuationUiRoute($project, $content, 1, 'create'))
        ->assertForbidden();

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.continuations.create', [$otherProject, $foreignContent, 7]))
        ->assertForbidden();

    $this->get(route('projects.generated-content.versions.continuations.create', [$project, $foreignContent, 7]))
        ->assertNotFound();

    $this->get(continuationUiRoute($project, $content, 3, 'create'))->assertNotFound();
    $this->get(route('projects.generated-content.versions.continuations.create', [$project, $otherContent, 1]))
        ->assertNotFound();

    expect($source->version_number)->toBe(1)
        ->and($foreignVersion->generated_content_id)->toBe($foreignContent->id)
        ->and($otherVersion->generated_content_id)->toBe($otherContent->id);
    Http::assertNothingSent();
});

test('unsupported legacy content remains readable but cannot open continuation routes', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'legacy_forum']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => ['old' => '<script>unsafe</script>']]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $content]))
        ->assertOk()
        ->assertDontSee('Continue from version 1')
        ->assertSee('&lt;script&gt;', false);

    $this->get(continuationUiRoute($project, $content, 1, 'create'))->assertNotFound();
    $this->post(continuationUiRoute($project, $content, 1, 'store'), [
        'attempt_token' => continuationUiAttempt($project, $content, $source),
        'continuation_instructions' => 'Try to continue legacy content.',
        'entry_count' => 1,
    ])->assertNotFound();
    Http::assertNothingSent();
});

test('continuation appends a branch from the exact historical version and redirects to the result version', function (string $type) {
    $project = Project::factory()->create();
    continuationUiCredential($project);
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => $type]);
    $sourceContent = continuationUiSource($type);
    $source = GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1, 'content' => $sourceContent]);
    $middle = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $source->id,
        'content' => continuationUiSource($type, 'A separate branch body.'),
    ]);
    GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 3,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $middle->id,
        'content' => continuationUiSource($type, 'A newer branch body.'),
    ]);
    $reference = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $referenceVersion = GeneratedContentVersion::factory()->for($reference)->create([
        'content' => continuationUiSource('forum_thread', 'Reference version body.'),
    ]);
    $token = continuationUiAttempt($project, $content, $source);
    $instructions = 'Let the discussion investigate the outage, carefully.';
    $proposal = continuationUiProposal($type);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationUiProviderResponse($proposal))]);

    $response = $this->actingAs($project->user)->post(continuationUiRoute($project, $content, 1, 'store'), [
        'attempt_token' => $token,
        'continuation_instructions' => $instructions,
        'entry_count' => '1',
        'references' => [$reference->uuid.':1'],
        'api_key' => 'browser-secret-must-be-ignored',
        'source_version_id' => $middle->id,
    ]);

    $newVersion = $content->versions()->where('version_number', 4)->sole();
    $expectedUrl = route('projects.generated-content.versions.show', [$project, $content, 4]);
    $entryField = $type === 'forum_thread' ? 'posts' : 'messages';

    $response->assertRedirect($expectedUrl)
        ->assertSessionHas('status', 'Version 4 created from version 1.');

    expect($newVersion->generated_content_id)->toBe($content->id)
        ->and($newVersion->based_on_version_id)->toBe($source->id)
        ->and($newVersion->version_number)->toBe(4)
        ->and(canonicalizeJsonStructure($newVersion->content[$entryField][0]))->toBe(canonicalizeJsonStructure($sourceContent[$entryField][0]))
        ->and(canonicalizeJsonStructure($source->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and(canonicalizeJsonStructure($middle->fresh()->content))->toBe(canonicalizeJsonStructure(continuationUiSource($type, 'A separate branch body.')))
        ->and($newVersion->context_snapshot['operation'])->toBe('continuation')
        ->and($newVersion->context_snapshot['prompt'])->toBe($instructions)
        ->and($newVersion->context_snapshot['source'])->toBe(['content_uuid' => $content->uuid, 'version_number' => 1])
        ->and(canonicalizeJsonStructure($newVersion->context_snapshot['references'][0]['content']))->toBe(canonicalizeJsonStructure($referenceVersion->content))
        ->and($newVersion->generation_metadata['operation'])->toBe('continuation')
        ->and($newVersion->generation_metadata)->not->toHaveKey('api_key')
        ->and(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)->toBe(GenerationAttemptStatus::Completed)
        ->and(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->generated_content_version_id)->toBe($newVersion->id);

    Http::assertSent(function (Request $request) use ($instructions, $sourceContent, $reference): bool {
        return $request->hasHeader('Authorization', 'Bearer continuation-ui-personal-key')
            && str_contains($request['input'], $instructions)
            && str_contains($request['input'], $sourceContent['posts'][0]['body'] ?? $sourceContent['messages'][0]['body'])
            && str_contains($request['input'], $reference->uuid)
            && $request['text']['format']['schema']['properties']['entries']['minItems'] === 1
            && $request['text']['format']['schema']['properties']['entries']['maxItems'] === 1;
    });
    Http::assertSentCount(1);

    $this->get($expectedUrl)->assertOk()->assertSee('Based on version 1');
})->with(['forum_thread', 'schrecknet_thread']);

test('a completed duplicate redirects to the exact result without another provider request', function () {
    $project = Project::factory()->create();
    continuationUiCredential($project);
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $token = continuationUiAttempt($project, $content, $source);
    $formUrl = continuationUiRoute($project, $content, 1, 'store');
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(continuationUiProviderResponse(continuationUiProposal('forum_thread')))]);
    $payload = [
        'attempt_token' => $token,
        'continuation_instructions' => 'Continue the discussion.',
        'entry_count' => 1,
    ];

    $firstResponse = $this->actingAs($project->user)->post($formUrl, $payload);
    $resultUrl = route('projects.generated-content.versions.show', [$project, $content, 2]);
    $firstResponse->assertRedirect($resultUrl);
    $this->post($formUrl, $payload)->assertRedirect($resultUrl);

    expect($content->versions()->count())->toBe(2);
    Http::assertSentCount(1);
});

test('preflight validation preserves its issued token and safe fields while rejecting unsafe input before OpenAI', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $token = continuationUiAttempt($project, $content, $source);
    $referenceProject = Project::factory()->create();
    $foreignReference = GeneratedContent::factory()->for($referenceProject)->create(['content_type' => 'forum_thread']);
    $foreignReference->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => continuationUiSource('forum_thread'),
    ]);
    $formUrl = continuationUiRoute($project, $content, 1, 'create');

    $this->actingAs($project->user)
        ->from($formUrl)
        ->post(continuationUiRoute($project, $content, 1, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Continue after the next outage.',
            'entry_count' => '0',
            'references' => [$foreignReference->uuid.':1'],
            'api_key' => 'never-flash-this',
            'unexpected' => 'never-flash-this-either',
        ])
        ->assertRedirect($formUrl)
        ->assertSessionHasErrors('entry_count')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionHas('_old_input.continuation_instructions', 'Continue after the next outage.')
        ->assertSessionHas('_old_input.entry_count', '0')
        ->assertSessionMissingInput('api_key')
        ->assertSessionMissingInput('unexpected');

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status->value)
        ->toBe(GenerationAttemptStatus::Issued->value);
    Http::assertNothingSent();
});

test('cross-project references fail during preflight without consuming the attempt or calling OpenAI', function () {
    $project = Project::factory()->create();
    continuationUiCredential($project);
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $token = continuationUiAttempt($project, $content, $source);
    $foreignProject = Project::factory()->create();
    $foreign = GeneratedContent::factory()->for($foreignProject)->create(['content_type' => 'forum_thread']);
    $foreign->versions()->create([
        'version_number' => 1,
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
        'content' => continuationUiSource('forum_thread'),
    ]);
    Http::fake();

    $this->actingAs($project->user)
        ->from(continuationUiRoute($project, $content, 1, 'create'))
        ->post(continuationUiRoute($project, $content, 1, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Continue safely.',
            'entry_count' => 1,
            'references' => [$foreign->uuid.':1'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('references')
        ->assertSessionHas('_old_input.attempt_token', $token);

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)
        ->toBe(GenerationAttemptStatus::Issued);
    Http::assertNothingSent();
});

test('missing personal credentials block continuation without consuming the attempt', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $formUrl = continuationUiRoute($project, $content, 1, 'create');
    $form = $this->actingAs($project->user)
        ->get($formUrl)
        ->assertSee('personal OpenAI API key is required')
        ->assertSee(route('account.settings'))
        ->assertSee('Add a personal key to continue')
        ->assertDontSee('data-generation-submit', false)
        ->assertSee('After you add a personal key, submitting')
        ->assertSee('may charge your account');
    $token = $form->viewData('attemptToken');

    $this->from($formUrl)
        ->post(continuationUiRoute($project, $content, 1, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Continue carefully.',
            'entry_count' => 1,
        ])
        ->assertRedirect($formUrl)
        ->assertSessionHasErrors('credentials')
        ->assertSessionHas('_old_input.attempt_token', $token)
        ->assertSessionHas('_old_input.continuation_instructions', 'Continue carefully.');

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)
        ->toBe(GenerationAttemptStatus::Issued)
        ->and($content->versions()->count())->toBe(1);
    Http::assertNothingSent();
});

test('an attempt for one source version cannot be submitted against another source version', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $first = GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1, 'content' => continuationUiSource('forum_thread')]);
    $second = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'based_on_version_id' => $first->id,
        'content' => continuationUiSource('forum_thread', 'Second source version.'),
    ]);
    $token = continuationUiAttempt($project, $content, $first);
    Http::fake();

    $this->actingAs($project->user)
        ->from(continuationUiRoute($project, $content, 2, 'create'))
        ->post(continuationUiRoute($project, $content, 2, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Continue from the wrong source.',
            'entry_count' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('continuation')
        ->assertSessionMissingInput('attempt_token');

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status->value)
        ->toBe(GenerationAttemptStatus::Issued->value)
        ->and($second->fresh()->based_on_version_id)->toBe($first->id);
    Http::assertNothingSent();
});

test('provider failure consumes the continuation attempt and a fresh form visit issues a retry token', function () {
    $project = Project::factory()->create();
    continuationUiCredential($project);
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['content' => continuationUiSource('forum_thread')]);
    $formUrl = continuationUiRoute($project, $content, 1, 'create');
    $token = continuationUiAttempt($project, $content, $source);
    Http::fakeSequence('https://api.openai.com/v1/responses')
        ->push(['error' => ['message' => 'private provider body']], 500)
        ->push(continuationUiProviderResponse(continuationUiProposal('forum_thread')));

    $this->actingAs($project->user)
        ->from($formUrl)
        ->post(continuationUiRoute($project, $content, 1, 'store'), [
            'attempt_token' => $token,
            'continuation_instructions' => 'Continue without leaking provider details.',
            'entry_count' => 1,
        ])
        ->assertRedirect($formUrl)
        ->assertSessionHasErrors('generation')
        ->assertSessionHas('_old_input.continuation_instructions', 'Continue without leaking provider details.')
        ->assertSessionMissingInput('attempt_token');

    expect(GenerationAttempt::query()->where('token_hash', hash('sha256', $token))->sole()->status)
        ->toBe(GenerationAttemptStatus::Failed);
    $freshForm = $this->get($formUrl)->assertOk();
    $retryToken = $freshForm->viewData('attemptToken');

    expect($retryToken)->not->toBe($token);
    $this->post(continuationUiRoute($project, $content, 1, 'store'), [
        'attempt_token' => $retryToken,
        'continuation_instructions' => 'Continue without leaking provider details.',
        'entry_count' => 1,
    ])->assertRedirect(route('projects.generated-content.versions.show', [$project, $content, 2]));

    expect($content->versions()->count())->toBe(2);
    Http::assertSentCount(2);
});
