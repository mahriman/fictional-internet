<?php

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function newsArticleContent(string $headline, string $body = 'Article body.'): array
{
    return [
        'publication' => 'The Harbor Ledger',
        'headline' => $headline,
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => $body,
    ];
}

function versionShowRoute(Project $project, GeneratedContent $generatedContent, int $versionNumber): string
{
    return route('projects.generated-content.versions.show', [
        'project' => $project,
        'generatedContent' => $generatedContent,
        'versionNumber' => $versionNumber,
    ]);
}

function versionEditRoute(Project $project, GeneratedContent $generatedContent, int $versionNumber): string
{
    return route('projects.generated-content.versions.edit', [
        'project' => $project,
        'generatedContent' => $generatedContent,
        'versionNumber' => $versionNumber,
    ]);
}

function versionEditStoreRoute(Project $project, GeneratedContent $generatedContent, int $versionNumber): string
{
    return route('projects.generated-content.versions.edits.store', [
        'project' => $project,
        'generatedContent' => $generatedContent,
        'versionNumber' => $versionNumber,
    ]);
}

test('ordinary detail shows the latest version and history is ordered by version number with branch sources', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['title' => 'Original stored title']);
    $root = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => newsArticleContent('Root version'),
    ]);
    $branch = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $root->id,
        'content' => newsArticleContent('Branch version'),
    ]);
    $latestBranch = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 3,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $root->id,
        'content' => newsArticleContent('Latest branch'),
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('Latest branch')
        ->assertSee('Version 3')
        ->assertSee('AI-generated')
        ->assertSee('Currently viewing')
        ->assertSee('Latest version')
        ->assertSee('Based on version 1')
        ->assertSeeInOrder(['Version 3', 'Version 2', 'Version 1'])
        ->assertSee(versionShowRoute($project, $generatedContent, 1))
        ->assertSee(versionShowRoute($project, $generatedContent, 2))
        ->assertSee(versionShowRoute($project, $generatedContent, 3));

    expect($branch->basedOnVersion->is($root))->toBeTrue()
        ->and($latestBranch->basedOnVersion->is($root))->toBeTrue()
        ->and($generatedContent->fresh()->title)->toBe('Original stored title');

    Http::assertNothingSent();
});

test('version history uses persisted provenance and links directly to each actual branch source', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $otherContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($otherContent)->create(['version_number' => 1]);
    $initial = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'context_snapshot' => [
            'content_type' => 'news_article',
            'prompt' => 'Create an article.',
            'instructions' => 'Use a fictional editorial voice.',
            'project_context' => null,
            'references' => [],
        ],
        'content' => newsArticleContent('Initial headline'),
    ]);
    $firstBranch = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'based_on_version_id' => $initial->id,
        'context_snapshot' => ['operation' => 'continuation', 'content_type' => 'news_article'],
        'content' => newsArticleContent('First continuation'),
    ]);
    $siblingBranch = GeneratedContentVersion::factory()->for($generatedContent)->userEdited()->create([
        'version_number' => 3,
        'based_on_version_id' => $initial->id,
        'context_snapshot' => ['content_type' => 'news_article', 'prompt' => 'Inherited context.'],
        'content' => newsArticleContent('Manual sibling'),
    ]);
    $latestBranch = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 4,
        'based_on_version_id' => $firstBranch->id,
        'context_snapshot' => null,
        'content' => newsArticleContent('Legacy AI branch'),
    ]);

    $response = $this->actingAs($project->user)
        ->get(versionShowRoute($project, $generatedContent, 3))
        ->assertOk()
        ->assertSee('Manual edit')
        ->assertSee('Currently viewing')
        ->assertSee('Latest is version 4')
        ->assertSee('Initial AI generation')
        ->assertSee('AI continuation')
        ->assertSee('AI-generated')
        ->assertSee('Based on version 1')
        ->assertSee('version 2')
        ->assertSee('Latest version')
        ->assertSee('Version 4');

    $response->assertSee('href="'.versionShowRoute($project, $generatedContent, 1).'"', false)
        ->assertSee('href="'.versionShowRoute($project, $generatedContent, 2).'"', false)
        ->assertSee('href="'.versionShowRoute($project, $generatedContent, 4).'"', false)
        ->assertSee('aria-current="page"', false)
        ->assertDontSee('href="'.versionShowRoute($project, $otherContent, (int) $initial->id).'"', false);

    expect(str_contains(versionShowRoute($project, $generatedContent, 1), '/versions/'.$initial->id))->toBeFalse()
        ->and($firstBranch->fresh()->based_on_version_id)->toBe($initial->id)
        ->and($siblingBranch->fresh()->based_on_version_id)->toBe($initial->id)
        ->and($latestBranch->fresh()->based_on_version_id)->toBe($firstBranch->id);

    Http::assertNothingSent();
});

test('historical version url displays the selected version and its lineage', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $root = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => newsArticleContent('Original headline'),
    ]);
    $historical = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $root->id,
        'content' => newsArticleContent('Historical headline'),
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 3,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $root->id,
        'content' => newsArticleContent('Newer headline'),
    ]);

    $this->actingAs($project->user)
        ->get(versionShowRoute($project, $generatedContent, 2))
        ->assertOk()
        ->assertSee('Historical headline')
        ->assertSee('Version 2')
        ->assertSee('Manual edit')
        ->assertSee('Based on version 1')
        ->assertSee('Currently viewing')
        ->assertSee('Latest is version 3')
        ->assertSee('Edit this version');

    expect(parse_url(versionShowRoute($project, $generatedContent, 2), PHP_URL_PATH))
        ->toBe('/projects/'.$project->uuid.'/generated-content/'.$generatedContent->uuid.'/versions/2');
    expect($historical->basedOnVersion->is($root))->toBeTrue();
    Http::assertNothingSent();
});

test('a malformed cross-content source relationship is not rendered as a lineage link', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $otherContent = GeneratedContent::factory()->for($project)->create();
    $foreignSource = GeneratedContentVersion::factory()->for($otherContent)->create([
        'version_number' => 1,
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'based_on_version_id' => $foreignSource->id,
    ]);

    $this->actingAs($project->user)
        ->get(versionShowRoute($project, $generatedContent, 1))
        ->assertOk()
        ->assertDontSee('Based on version')
        ->assertDontSee('href="'.versionShowRoute($project, $otherContent, 1).'"', false);

    Http::assertNothingSent();
});

test('guests and users without project ownership cannot browse or edit versions', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => newsArticleContent('Private headline'),
    ]);
    $versionUrl = versionShowRoute($project, $generatedContent, $version->version_number);
    $editUrl = versionEditRoute($project, $generatedContent, $version->version_number);
    $storeUrl = versionEditStoreRoute($project, $generatedContent, $version->version_number);
    $content = newsArticleContent('Attempted edit');

    $this->get($versionUrl)->assertRedirect(route('login'));
    $this->get($editUrl)->assertRedirect(route('login'));
    $this->post($storeUrl, ['content' => $content])->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get($versionUrl)
        ->assertForbidden();
    $this->get($editUrl)->assertForbidden();
    $this->post($storeUrl, ['content' => $content])->assertForbidden();

    expect($generatedContent->versions()->count())->toBe(1);
    Http::assertNothingSent();
});

test('a version number from another generated content cannot resolve in the requested content', function () {
    $project = Project::factory()->create();
    $firstContent = GeneratedContent::factory()->for($project)->create();
    $secondContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($firstContent)->create(['version_number' => 1]);
    $secondVersion = GeneratedContentVersion::factory()->for($secondContent)->create(['version_number' => 2]);

    $this->actingAs($project->user)
        ->get(versionShowRoute($project, $firstContent, $secondVersion->version_number))
        ->assertNotFound();

    $this->get(versionEditRoute($project, $firstContent, $secondVersion->version_number))
        ->assertNotFound();
    $this->post(versionEditStoreRoute($project, $firstContent, $secondVersion->version_number), [
        'content' => newsArticleContent('Cross-content edit'),
    ])->assertNotFound();

    expect($firstContent->versions()->count())->toBe(1)
        ->and($secondContent->versions()->count())->toBe(1);

    Http::assertNothingSent();
});

test('the editor is prefilled from the selected older version and escapes its values', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $sourceContent = newsArticleContent('<script>alert("old")</script>', "An earlier body.\nWith a second paragraph.");
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $sourceContent,
        'context_snapshot' => ['prompt' => 'Context stays on the source branch.'],
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'content' => newsArticleContent('Newest version headline'),
    ]);

    $this->actingAs($project->user)
        ->get(versionEditRoute($project, $generatedContent, 1))
        ->assertOk()
        ->assertSee('value="The Harbor Ledger"', false)
        ->assertSee('value="&lt;script&gt;alert(&quot;old&quot;)&lt;/script&gt;"', false)
        ->assertSee('value="'.$sourceContent['published_at'].'"', false)
        ->assertSee('An earlier body.')
        ->assertSee('second paragraph.')
        ->assertDontSee('Newest version headline')
        ->assertDontSee('<script>alert("old")</script>', false);

    expect(canonicalizeJsonStructure($sourceVersion->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent));
    Http::assertNothingSent();
});

test('invalid edits preserve supported fields, reject extra fields and do not flash credentials', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create();
    $editUrl = versionEditRoute($project, $generatedContent, 1);
    $submittedContent = newsArticleContent(str_repeat('x', 256), 'Submitted body remains visible.');
    $submittedContent['published_at'] = 'not a date';
    $submittedContent['unexpected'] = 'must not be persisted';

    $this->actingAs($project->user)
        ->from($editUrl)
        ->post(versionEditStoreRoute($project, $generatedContent, 1), [
            'content' => $submittedContent,
            'api_key' => 'must-not-be-flashed',
            'origin' => 'ai_generated',
            'version_number' => 900,
            'based_on_version_id' => 900,
            'context_snapshot' => ['forged' => true],
            'generation_metadata' => ['forged' => true],
        ])
        ->assertRedirect($editUrl)
        ->assertSessionHasErrors(['content', 'content.headline', 'content.published_at'])
        ->assertSessionHas('_old_input.content.headline', str_repeat('x', 256))
        ->assertSessionHas('_old_input.content.published_at', 'not a date')
        ->assertSessionHas('_old_input.content.body', 'Submitted body remains visible.')
        ->assertSessionMissingInput('api_key')
        ->assertSessionMissingInput('content.unexpected')
        ->assertSessionMissingInput('generation_metadata');

    expect($generatedContent->versions()->count())->toBe(1)
        ->and(canonicalizeJsonStructure($sourceVersion->fresh()->content))->toBe(canonicalizeJsonStructure($sourceVersion->content));
    Http::assertNothingSent();
});

test('editing an older version appends a new branch without changing its source or parent title', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'title' => 'Initial parent title',
    ]);
    $sourceContent = newsArticleContent('Original headline', "Original body.\nSecond paragraph.");
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $sourceContent,
        'context_snapshot' => ['prompt' => 'Keep this context.'],
        'generation_metadata' => ['model' => 'original-model'],
    ]);
    $secondVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $sourceVersion->id,
        'content' => newsArticleContent('Other branch'),
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 3,
        'origin' => GeneratedContentVersionOrigin::UserEdited,
        'based_on_version_id' => $secondVersion->id,
        'content' => newsArticleContent('Second-generation descendant'),
    ]);
    $editedContent = newsArticleContent('Edited old headline', "Edited body.\n\nA new paragraph with  two spaces.");

    $response = $this->actingAs($project->user)
        ->post(versionEditStoreRoute($project, $generatedContent, 1), [
            'content' => $editedContent,
        ]);

    $newVersion = $generatedContent->versions()->where('version_number', 4)->sole();
    $expectedUrl = versionShowRoute($project, $generatedContent, 4);

    $response->assertRedirect($expectedUrl)
        ->assertSessionHas('status', 'Version 4 created from version 1.');

    expect(canonicalizeJsonStructure($newVersion->content))->toBe(canonicalizeJsonStructure($editedContent))
        ->and($newVersion->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($newVersion->based_on_version_id)->toBe($sourceVersion->id)
        ->and($newVersion->context_snapshot)->toBe(['prompt' => 'Keep this context.'])
        ->and($newVersion->generation_metadata)->toBeNull()
        ->and(canonicalizeJsonStructure($sourceVersion->fresh()->content))->toBe(canonicalizeJsonStructure($sourceContent))
        ->and($sourceVersion->fresh()->context_snapshot)->toBe(['prompt' => 'Keep this context.'])
        ->and($sourceVersion->fresh()->generation_metadata)->toBe(['model' => 'original-model'])
        ->and($generatedContent->fresh()->title)->toBe('Initial parent title')
        ->and($generatedContent->versions()->count())->toBe(4);

    $this->get($expectedUrl)
        ->assertOk()
        ->assertSee('Edited old headline')
        ->assertSee('Initial parent title')
        ->assertSee('This name is separate from the headline or title inside each immutable version.')
        ->assertSee('Based on version 1')
        ->assertSee('version 2')
        ->assertSee('Manual edit');

    Http::assertNothingSent();
});

test('unsupported legacy content remains readable and cannot be edited', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'retired_type']);
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => ['legacy' => 'Readable stored content'],
    ]);

    $this->actingAs($project->user)
        ->get(versionShowRoute($project, $generatedContent, $version->version_number))
        ->assertOk()
        ->assertSee('Readable stored content')
        ->assertSee('A dedicated editor is unavailable for this content type.')
        ->assertDontSee('Edit this version');

    $this->get(versionEditRoute($project, $generatedContent, $version->version_number))->assertNotFound();
    $this->post(versionEditStoreRoute($project, $generatedContent, $version->version_number), [
        'content' => ['legacy' => 'Attempted edit'],
    ])->assertNotFound();

    expect($generatedContent->versions()->count())->toBe(1);
    Http::assertNothingSent();
});
