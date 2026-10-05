<?php

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\OpenAiCredential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function projectExportResponse(Project $project): TestResponse
{
    return test()->get(route('projects.export', $project));
}

function projectExportData(Project $project): array
{
    return json_decode(projectExportResponse($project)->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
}

test('project export downloads a versioned archive with stable project data and a safe filename', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create([
        'name' => '../Café Harbor <Archive>',
        'description' => 'Ångström project description',
    ]);
    $project->context()->create([
        'setting' => 'Coastal Sweden',
        'time_period' => null,
        'locations' => 'Helsingborg',
        'people' => null,
        'organizations' => 'The harbour office',
        'canon_notes' => 'Keep the lighthouse intact.',
    ]);
    $content = GeneratedContent::factory()->for($project)->create([
        'uuid' => '00000000-0000-4000-8000-000000000011',
        'content_type' => 'forum_thread',
        'title' => 'Stable artifact title',
    ]);
    $first = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 1,
        'content' => ['thread_title' => 'Äldre tråd', 'posts' => [['body' => "Första raden\nAndra raden"]]],
        'context_snapshot' => ['prompt' => 'Original instructions', 'references' => []],
        'generation_metadata' => ['provider' => 'openai', 'model' => 'model-x', 'output_tokens' => 41],
    ]);
    GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'based_on_version_id' => $first->getKey(),
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'content' => ['thread_title' => 'Edited title', 'posts' => [['body' => 'Changed']]],
        'context_snapshot' => ['prompt' => 'Original instructions', 'references' => []],
        'generation_metadata' => null,
    ]);

    $this->actingAs($owner);
    $response = projectExportResponse($project);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Disposition', 'attachment; filename=cafe-harbor-archive.json');

    $archive = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($archive['format'])->toBe('fictional-internet-project')
        ->and($archive['format_version'])->toBe(1)
        ->and($archive['exported_at'])->toBeString()
        ->and($archive['project'])->toBe([
            'uuid' => $project->uuid,
            'name' => '../Café Harbor <Archive>',
            'description' => 'Ångström project description',
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ])
        ->and($archive['project_context'])->toBe([
            'setting' => 'Coastal Sweden',
            'time_period' => null,
            'locations' => 'Helsingborg',
            'people' => null,
            'organizations' => 'The harbour office',
            'canon_notes' => 'Keep the lighthouse intact.',
        ])
        ->and($archive['generated_contents'])->toHaveCount(1)
        ->and($archive['generated_contents'][0]['uuid'])->toBe($content->uuid)
        ->and($archive['generated_contents'][0]['content_type'])->toBe('forum_thread')
        ->and($archive['generated_contents'][0]['title'])->toBe('Stable artifact title')
        ->and($archive['generated_contents'][0]['versions'])->toHaveCount(2)
        ->and($archive['generated_contents'][0]['versions'][0]['version_number'])->toBe(1)
        ->and($archive['generated_contents'][0]['versions'][0]['origin'])->toBe('ai_generated')
        ->and($archive['generated_contents'][0]['versions'][0]['based_on_version'])->toBeNull()
        ->and($archive['generated_contents'][0]['versions'][0]['content']['thread_title'])->toBe('Äldre tråd')
        ->and($archive['generated_contents'][0]['versions'][0]['content']['posts'][0]['body'])->toBe("Första raden\nAndra raden")
        ->and($archive['generated_contents'][0]['versions'][0]['context_snapshot'])->toBe(['prompt' => 'Original instructions', 'references' => []])
        ->and($archive['generated_contents'][0]['versions'][0]['generation_metadata'])->toBe([
            'provider' => 'openai', 'model' => 'model-x', 'output_tokens' => 41,
        ])
        ->and($archive['generated_contents'][0]['versions'][1]['based_on_version'])->toBe(1)
        ->and($archive['generated_contents'][0]['versions'][1]['origin'])->toBe('user_edited');

    expect($response->streamedContent())->not->toContain('user_id', (string) $owner->getKey());
    expect($project->fresh()->name)->toBe('../Café Harbor <Archive>')
        ->and(canonicalizeJsonStructure($first->fresh()->content))
        ->toBe(canonicalizeJsonStructure([
            'thread_title' => 'Äldre tråd',
            'posts' => [['body' => "Första raden\nAndra raden"]],
        ]))
        ->and($first->fresh()->context_snapshot)->toBe(['prompt' => 'Original instructions', 'references' => []]);
    Http::assertNothingSent();
});

test('project export preserves branches and deterministic artifact and version ordering without internal ids', function () {
    $project = Project::factory()->create();
    $laterUuid = GeneratedContent::factory()->for($project)->create([
        'uuid' => '00000000-0000-4000-8000-000000000022',
        'title' => 'Second artifact',
    ]);
    $firstUuid = GeneratedContent::factory()->for($project)->create([
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'title' => 'First artifact',
    ]);
    $v1 = GeneratedContentVersion::factory()->for($firstUuid)->create(['version_number' => 1]);
    $v2 = GeneratedContentVersion::factory()->for($firstUuid)->create([
        'version_number' => 2,
        'based_on_version_id' => $v1->getKey(),
    ]);
    $v3 = GeneratedContentVersion::factory()->for($firstUuid)->create([
        'version_number' => 3,
        'based_on_version_id' => $v1->getKey(),
    ]);
    GeneratedContentVersion::factory()->for($firstUuid)->create([
        'version_number' => 4,
        'based_on_version_id' => $v2->getKey(),
    ]);
    GeneratedContentVersion::factory()->for($laterUuid)->create();

    $this->actingAs($project->user);
    $firstExport = projectExportResponse($project)->assertOk()->streamedContent();
    $secondExport = projectExportResponse($project)->assertOk()->streamedContent();
    $archive = json_decode($firstExport, true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($archive['generated_contents'], 'uuid'))
        ->toBe([$firstUuid->uuid, $laterUuid->uuid])
        ->and(array_column($archive['generated_contents'][0]['versions'], 'version_number'))
        ->toBe([1, 2, 3, 4])
        ->and(array_column($archive['generated_contents'][0]['versions'], 'based_on_version'))
        ->toBe([null, 1, 1, 2])
        ->and($firstExport)->toBe($secondExport);

    foreach (['id', 'project_id', 'generated_content_id', 'based_on_version_id', 'user_id'] as $internalField) {
        expect($archive)->not->toHaveKey($internalField);
        expect($firstExport)->not->toContain('"'.$internalField.'"');
    }

    Http::assertNothingSent();
});

test('project export preserves captured reference snapshots after the referenced artifact is deleted', function () {
    $project = Project::factory()->create();
    $referenced = GeneratedContent::factory()->for($project)->create(['title' => 'Captured title']);
    GeneratedContentVersion::factory()->for($referenced)->create([
        'version_number' => 1,
        'content' => ['headline' => 'Captured historical headline'],
    ]);
    $target = GeneratedContent::factory()->for($project)->create(['title' => 'Retained artifact']);
    $targetVersion = GeneratedContentVersion::factory()->for($target)->create([
        'context_snapshot' => [
            'references' => [[
                'content_uuid' => $referenced->uuid,
                'content_type' => 'news_article',
                'version_number' => 1,
                'title' => 'Captured title · Captured historical headline',
                'content' => ['headline' => 'Captured historical headline'],
            ]],
        ],
    ]);
    $capturedSnapshot = $targetVersion->context_snapshot;
    $referenced->delete();

    $this->actingAs($project->user);
    $archive = projectExportData($project);

    expect($archive['generated_contents'])->toHaveCount(1)
        ->and($archive['generated_contents'][0]['versions'][0]['context_snapshot'])
        ->toBe($capturedSnapshot)
        ->and($archive['generated_contents'][0]['versions'][0]['context_snapshot']['references'][0]['title'])
        ->toBe('Captured title · Captured historical headline');
    Http::assertNothingSent();
});

test('project export excludes account credentials attempts and unknown generation metadata', function () {
    $owner = User::factory()->create([
        'name' => 'Account Name Sentinel',
        'email' => 'private-owner-sentinel@example.test',
        'password' => 'private-password-sentinel',
        'remember_token' => 'private-remember-token-sentinel',
    ]);
    $project = Project::factory()->for($owner)->create();
    $unrelatedProject = Project::factory()->create(['name' => 'Unrelated project sentinel']);
    $unrelatedContent = GeneratedContent::factory()->for($unrelatedProject)->create(['title' => 'Unrelated artifact sentinel']);
    GeneratedContentVersion::factory()->for($unrelatedContent)->create([
        'content' => ['body' => 'Unrelated content sentinel'],
    ]);
    $credential = new OpenAiCredential;
    $credential->api_key = 'personal-openai-secret-sentinel';
    $owner->openAiCredential()->save($credential);
    $ciphertext = $credential->getRawOriginal('api_key');
    $content = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($content)->create([
        'generation_metadata' => [
            'provider' => 'openai',
            'model' => 'configured-model',
            'api_key' => 'unexpected-key-sentinel',
            'authorization' => 'unexpected-auth-sentinel',
            'raw_response' => ['secret' => 'unexpected-provider-body-sentinel'],
        ],
    ]);
    $tokenHash = str_repeat('a', 64);
    GenerationAttempt::factory()->create([
        'project_id' => $project->getKey(),
        'user_id' => $owner->getKey(),
        'token_hash' => $tokenHash,
    ]);

    $this->actingAs($owner);
    $response = projectExportResponse($project)->assertOk();
    $json = $response->streamedContent();
    $archive = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($json)
        ->not->toContain(
            'personal-openai-secret-sentinel',
            $ciphertext,
            'open_ai_credentials',
            (string) $credential->getKey(),
            'private-owner-sentinel@example.test',
            'Account Name Sentinel',
            'private-password-sentinel',
            'private-remember-token-sentinel',
            $tokenHash,
            'unexpected-key-sentinel',
            'unexpected-auth-sentinel',
            'unexpected-provider-body-sentinel',
            'Unrelated project sentinel',
            'Unrelated artifact sentinel',
            'Unrelated content sentinel',
        )
        ->and($archive['generated_contents'][0]['versions'][0]['generation_metadata'])
        ->toBe(['provider' => 'openai', 'model' => 'configured-model']);
    Http::assertNothingSent();
});

test('project export uses a frozen timestamp and does not require or create project context', function () {
    $timestamp = now()->setDate(2026, 2, 3)->setTime(4, 5, 6);
    $this->travelTo($timestamp);
    $project = Project::factory()->create();

    $this->actingAs($project->user);
    $firstResponse = projectExportResponse($project)->assertOk();
    $archive = json_decode($firstResponse->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    $secondExport = projectExportResponse($project)->assertOk()->streamedContent();

    expect($archive['exported_at'])->toBe($timestamp->toIso8601String())
        ->and($archive['project_context'])->toBeNull()
        ->and($archive['generated_contents'])->toBe([])
        ->and($firstResponse->streamedContent())->toBe($secondExport);
    $this->assertDatabaseCount('project_contexts', 0);
    $this->assertDatabaseCount('generated_contents', 0);
    Http::assertNothingSent();
});

test('owner can export legacy malformed stored content as persisted without schema validation', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['content_type' => 'retired_content_type']);
    $legacyContent = ['legacy' => ['unrecognized' => '<p>Stored as-is</p>'], 'partial' => true];
    GeneratedContentVersion::factory()->for($content)->create([
        'content' => $legacyContent,
        'context_snapshot' => ['legacy_snapshot' => ['captured' => 'unchanged']],
    ]);

    $this->actingAs($project->user);
    $archive = projectExportData($project);

    expect(canonicalizeJsonStructure($archive['generated_contents'][0]['versions'][0]['content']))
        ->toBe(canonicalizeJsonStructure($legacyContent))
        ->and($archive['generated_contents'][0]['versions'][0]['context_snapshot'])
        ->toBe(['legacy_snapshot' => ['captured' => 'unchanged']]);
    Http::assertNothingSent();
});

test('guests nonowners and unknown project uuids cannot export projects', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    projectExportResponse($project)->assertRedirect(route('login'));
    $this->actingAs($otherUser)
        ->get(route('projects.export', $project))
        ->assertForbidden();
    $this->get('/projects/00000000-0000-4000-8000-000000000099/export')
        ->assertNotFound();
    $this->get('/projects/'.$project->getKey().'/export')
        ->assertNotFound();
    Http::assertNothingSent();
});

test('project detail page explains and links to the separate project json export', function () {
    $project = Project::factory()->create();

    $this->actingAs($project->user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Export project')
        ->assertSee('complete immutable version history as JSON')
        ->assertSee('Account information and OpenAI credentials are excluded.')
        ->assertSee(route('projects.export', $project));

    Http::assertNothingSent();
});
