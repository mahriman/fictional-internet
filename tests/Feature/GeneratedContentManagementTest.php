<?php

use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\GenerationAttempt;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function artifactManagementUpdateUrl(Project $project, GeneratedContent $content, ?int $versionNumber = null): string
{
    return route('projects.generated-content.update', array_filter([
        'project' => $project,
        'generatedContent' => $content,
        'version' => $versionNumber,
    ], static fn (mixed $value): bool => $value !== null));
}

function artifactManagementDeleteUrl(Project $project, GeneratedContent $content): string
{
    return route('projects.generated-content.destroy', [
        'project' => $project,
        'generatedContent' => $content,
    ]);
}

function managementNewsContent(string $headline, string $body = 'Article body.'): array
{
    return [
        'publication' => 'Harbor Ledger',
        'headline' => $headline,
        'published_at' => '2026-10-05T10:00:00Z',
        'body' => $body,
    ];
}

test('owners can rename the stable artifact title without changing immutable versions', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Stable project name']);
    $source = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 1,
        'content' => managementNewsContent('Version headline one'),
        'context_snapshot' => ['prompt' => 'captured prompt'],
        'generation_metadata' => ['model' => 'configured-model'],
    ]);
    $second = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'based_on_version_id' => $source->getKey(),
        'content' => managementNewsContent('Version headline two'),
    ]);

    $response = $this->actingAs($project->user)
        ->from(route('projects.generated-content.versions.show', [$project, $content, 1]))
        ->patch(artifactManagementUpdateUrl($project, $content, 1), [
            'title' => '  Helsingborgs Dagblad – försvinnanden  ',
            'project_id' => 999,
            'user_id' => 999,
            'content' => ['headline' => 'Injected headline'],
        ]);

    $response->assertRedirect(route('projects.generated-content.versions.show', [$project, $content, 1]))
        ->assertSessionHas('status', 'Artifact title updated. Version titles are unchanged.');

    expect($content->fresh()->title)->toBe('Helsingborgs Dagblad – försvinnanden')
        ->and($content->versions()->count())->toBe(2)
        ->and($source->fresh()->content)->toBe(managementNewsContent('Version headline one'))
        ->and($source->fresh()->context_snapshot)->toBe(['prompt' => 'captured prompt'])
        ->and($second->fresh()->based_on_version_id)->toBe($source->getKey())
        ->and($second->fresh()->content)->toBe(managementNewsContent('Version headline two'));

    $this->get(route('projects.generated-content.versions.show', [$project, $content, 2]))
        ->assertOk()
        ->assertSee('Version headline two')
        ->assertSee('Helsingborgs Dagblad – försvinnanden');

    Http::assertNothingSent();
});

test('artifact title validation trims whitespace and rejects empty or overlong values', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Original title']);
    GeneratedContentVersion::factory()->for($content)->create();
    $this->actingAs($project->user);

    $this->from(route('projects.generated-content.show', [$project, $content]))
        ->patch(artifactManagementUpdateUrl($project, $content), [
            'title' => " \t ",
            'api_key' => 'must-not-be-flashed',
            'project_id' => 999,
            'user_id' => 999,
        ])
        ->assertRedirect(route('projects.generated-content.show', [$project, $content]))
        ->assertSessionHasErrors('title')
        ->assertSessionHas('_old_input.title', '')
        ->assertSessionMissing('_old_input.api_key')
        ->assertSessionMissing('_old_input.project_id')
        ->assertSessionMissing('_old_input.user_id');

    $this->from(route('projects.generated-content.show', [$project, $content]))
        ->patch(artifactManagementUpdateUrl($project, $content), ['title' => str_repeat('x', 256)])
        ->assertSessionHasErrors('title');

    expect($content->fresh()->title)->toBe('Original title');
});

test('artifact rename form distinguishes its title from the selected version headline', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Stable artifact title']);
    GeneratedContentVersion::factory()->for($content)->create([
        'content' => managementNewsContent('Immutable version headline'),
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $content]))
        ->assertOk()
        ->assertSee('Project artifact')
        ->assertSee('Stable artifact title')
        ->assertSee('This name is separate from the headline or title inside each immutable version.')
        ->assertSee('Immutable version headline')
        ->assertSee('name="title"', false)
        ->assertSee('value="Stable artifact title"', false);
});

test('renamed artifact titles are escaped when displayed', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($content)->create();

    $this->actingAs($project->user)
        ->patch(artifactManagementUpdateUrl($project, $content), ['title' => '<script>alert(1)</script>'])
        ->assertRedirect(route('projects.generated-content.show', [$project, $content]));

    $this->get(route('projects.generated-content.show', [$project, $content]))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('guests and non-owners cannot rename or delete generated content', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Private artifact']);
    GeneratedContentVersion::factory()->for($content)->create();

    $this->patch(artifactManagementUpdateUrl($project, $content), ['title' => 'Guest change'])
        ->assertRedirect(route('login'));
    $this->delete(artifactManagementDeleteUrl($project, $content))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->patch(artifactManagementUpdateUrl($project, $content), ['title' => 'Unauthorized change'])
        ->assertForbidden();
    $this->delete(artifactManagementDeleteUrl($project, $content))->assertForbidden();

    expect($content->fresh()->title)->toBe('Private artifact');
    $this->assertModelExists($content);
});

test('a generated content uuid cannot be managed through a different project url', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $otherProject = Project::factory()->for($user)->create();
    $content = GeneratedContent::factory()->for($otherProject)->create(['title' => 'Other project artifact']);
    GeneratedContentVersion::factory()->for($content)->create();

    $this->actingAs($user)
        ->patch(artifactManagementUpdateUrl($project, $content), ['title' => 'Changed'])
        ->assertNotFound();
    $this->delete(artifactManagementDeleteUrl($project, $content))->assertNotFound();
    $this->patch('/projects/'.$project->getKey().'/generated-content/'.$content->uuid, ['title' => 'Numeric route'])
        ->assertNotFound();
    $this->patch('/projects/'.$project->uuid.'/generated-content/not-a-uuid', ['title' => 'Malformed route'])
        ->assertNotFound();

    expect($content->fresh()->title)->toBe('Other project artifact');
    $this->assertModelExists($content);
});

test('deleting one artifact cascades its versions while preserving its project and captured references elsewhere', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Delete me']);
    $source = GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1]);
    $descendant = GeneratedContentVersion::factory()->for($content)->create([
        'version_number' => 2,
        'based_on_version_id' => $source->getKey(),
    ]);
    $otherContent = GeneratedContent::factory()->for($project)->create(['title' => 'Keep me']);
    $captured = [
        'content_uuid' => $content->uuid,
        'content_type' => $content->content_type,
        'version_number' => 1,
        'title' => 'Captured source title',
        'content' => ['headline' => 'Snapshot remains authoritative'],
    ];
    $otherVersion = GeneratedContentVersion::factory()->for($otherContent)->create([
        'context_snapshot' => ['references' => [$captured]],
    ]);
    $attempt = GenerationAttempt::factory()->create([
        'user_id' => $project->user_id,
        'project_id' => $project->getKey(),
        'target_generated_content_id' => $content->getKey(),
        'source_version_id' => $source->getKey(),
    ]);
    $ordinaryAttempt = GenerationAttempt::factory()->create([
        'user_id' => $project->user_id,
        'project_id' => $project->getKey(),
        'generated_content_id' => $content->getKey(),
    ]);

    $this->actingAs($project->user)
        ->delete(artifactManagementDeleteUrl($project, $content))
        ->assertRedirect(route('projects.show', $project))
        ->assertSessionHas('status', 'Generated content and its complete version history were deleted.');

    $this->assertModelMissing($content);
    $this->assertModelMissing($source);
    $this->assertModelMissing($descendant);
    $this->assertModelMissing($attempt);
    $this->assertModelExists($ordinaryAttempt);
    expect($ordinaryAttempt->fresh()->generated_content_id)->toBeNull()
        ->and($otherVersion->fresh()->context_snapshot['references'][0])->toBe($captured)
        ->and($project->fresh())->not->toBeNull();
    $this->assertModelExists($otherContent);
    $this->get(route('projects.generated-content.show', [$project, $content]))->assertNotFound();
    $this->get(route('projects.generated-content.versions.show', [$project, $content, 1]))->assertNotFound();
    $this->get(route('projects.generated-content.export', [$project, $content, 'html']))->assertNotFound();
    $this->get(route('projects.generated-content.show', [$project, $otherContent]))->assertOk();
    Http::assertNothingSent();
});

test('project listing shows version activity order rather than changing for a parent rename', function () {
    $project = Project::factory()->create();
    $olderActivity = GeneratedContent::factory()->for($project)->create([
        'title' => 'Older activity artifact',
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);
    GeneratedContentVersion::factory()->for($olderActivity)->create([
        'version_number' => 1,
        'created_at' => now()->subDays(3),
    ]);
    GeneratedContentVersion::factory()->for($olderActivity)->create([
        'version_number' => 2,
        'created_at' => now()->subDays(2),
    ]);
    $newerActivity = GeneratedContent::factory()->for($project)->create([
        'title' => 'Newer activity artifact',
        'content_type' => 'forum_thread',
        'created_at' => now()->subDays(8),
        'updated_at' => now()->subDays(8),
    ]);
    GeneratedContentVersion::factory()->for($newerActivity)->create([
        'version_number' => 1,
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeInOrder(['Newer activity artifact', 'Forum Thread', 'Version 1', 'Older activity artifact', 'News article', 'Version 2'])
        ->assertSee(now()->subDay()->format('M j, Y'));

    $this->patch(artifactManagementUpdateUrl($project, $olderActivity), ['title' => 'Renamed but old activity']);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeInOrder(['Newer activity artifact', 'Renamed but old activity']);
});

test('project listing filters by stable title and registry content type without searching version bodies', function () {
    $project = Project::factory()->create();
    $news = GeneratedContent::factory()->for($project)->create([
        'title' => 'Harbor morning report',
        'content_type' => 'news_article',
    ]);
    GeneratedContentVersion::factory()->for($news)->create([
        'content' => managementNewsContent('Hidden unique body needle', 'Body only searchable elsewhere'),
    ]);
    $forum = GeneratedContent::factory()->for($project)->create([
        'title' => 'Harbor discussion board',
        'content_type' => 'forum_thread',
    ]);
    GeneratedContentVersion::factory()->for($forum)->create();
    $schreckNet = GeneratedContent::factory()->for($project)->create([
        'title' => 'Night exchange',
        'content_type' => 'schrecknet_thread',
    ]);
    GeneratedContentVersion::factory()->for($schreckNet)->create();
    $this->actingAs($project->user);

    $this->get(route('projects.show', ['project' => $project, 'search' => '  Harbor ', 'content_type' => 'forum_thread']))
        ->assertOk()
        ->assertSee('Harbor discussion board')
        ->assertDontSee('Harbor morning report')
        ->assertSee('value="Harbor"', false)
        ->assertSee('value="forum_thread" selected', false)
        ->assertSee('Forum Thread');

    $this->get(route('projects.show', ['project' => $project, 'search' => 'Hidden unique body needle']))
        ->assertOk()
        ->assertSee('No content matches these filters.')
        ->assertDontSee('Harbor morning report');

    $this->get(route('projects.show', ['project' => $project, 'content_type' => 'unregistered']))
        ->assertOk()
        ->assertSee('Choose a registered content type or clear the filters.')
        ->assertSee('No content matches these filters.')
        ->assertDontSee('Harbor morning report');

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Harbor morning report')
        ->assertSee('Night exchange');
});

test('empty and filtered states differ and listing version metadata uses a bounded query count', function () {
    $emptyProject = Project::factory()->create();
    $this->actingAs($emptyProject->user)
        ->get(route('projects.show', $emptyProject))
        ->assertOk()
        ->assertSee('No content has been generated for this project yet.');

    $project = Project::factory()->create();
    $this->actingAs($project->user);
    foreach (range(1, 24) as $number) {
        $content = GeneratedContent::factory()->for($project)->create(['title' => 'Artifact '.$number]);
        GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1]);
        GeneratedContentVersion::factory()->for($content)->create(['version_number' => 2]);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->get(route('projects.show', [
        'project' => $project,
        'search' => 'Artifact',
        'content_type' => 'news_article',
    ]))
        ->assertOk()
        ->assertSee('Version 2')
        ->assertSee('Next')
        ->assertSee('search=Artifact', false)
        ->assertSee('content_type=news_article', false)
        ->assertSee('page=2', false);

    $versionQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'generated_content_versions'));
    expect(count($versionQueries))->toBe(1);
    Http::assertNothingSent();
});

test('renamed artifacts retain version access, reference selection and HTML export', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create(['title' => 'Old artifact name']);
    $version = GeneratedContentVersion::factory()->for($content)->create([
        'content' => managementNewsContent('Version headline remains'),
    ]);

    $this->actingAs($project->user)
        ->patch(artifactManagementUpdateUrl($project, $content), ['title' => 'New artifact name']);

    $this->get(route('projects.generated-content.versions.show', [$project, $content, $version->version_number]))
        ->assertOk()
        ->assertSee('Version headline remains');
    $this->get(route('projects.generated-content.create', $project))
        ->assertOk()
        ->assertSee('Version headline remains')
        ->assertSee($content->uuid.':1');
    $this->get(route('projects.generated-content.versions.export', [$project, $content, $version->version_number, 'html']))
        ->assertOk();

    Http::assertNothingSent();
});
