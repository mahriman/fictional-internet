<?php

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('guests are redirected to sign in from project management routes', function () {
    $project = Project::factory()->create();

    $this->get(route('projects.index'))->assertRedirect(route('login'));
    $this->get(route('projects.create'))->assertRedirect(route('login'));
    $this->post(route('projects.store'), ['name' => 'Private project'])->assertRedirect(route('login'));
    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
    $this->get(route('projects.edit', $project))->assertRedirect(route('login'));
    $this->put(route('projects.update', $project), ['name' => 'Changed'])->assertRedirect(route('login'));
    $this->delete(route('projects.destroy', $project))->assertRedirect(route('login'));
});

test('a visitor can register and then manage projects in an authenticated session', function () {
    $this->get(route('register'))->assertOk()->assertSee('Create an account');

    $this->post(route('register'), [
        'name' => 'Morgan Reed',
        'email' => 'morgan@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('projects.index'));

    $user = User::query()->where('email', 'morgan@example.test')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

test('registered users can sign in and sign out', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $this->get(route('login'))->assertOk()->assertSee('Sign in');
    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('projects.index'));

    $this->assertAuthenticatedAs($user);
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('project creation validates name and description and preserves submitted values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->from(route('projects.create'))
        ->post(route('projects.store'), ['name' => str_repeat('x', 256), 'description' => ['invalid']])
        ->assertRedirect(route('projects.create'))
        ->assertSessionHasErrors(['name', 'description'])
        ->assertSessionHas('_old_input.name', str_repeat('x', 256));

    expect($user->projects()->count())->toBe(0);
});

test('authenticated users can create projects with unique uuids and are redirected to the uuid url', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('projects.store'), [
        'name' => 'North Harbor Dispatch',
        'description' => 'A fictional local news publication.',
    ]);

    $project = $user->projects()->sole();

    expect(Str::isUuid($project->uuid))->toBeTrue()
        ->and($project->getRouteKeyName())->toBe('uuid');

    $this->assertDatabaseHas('projects', [
        'uuid' => $project->uuid,
        'user_id' => $user->id,
        'name' => 'North Harbor Dispatch',
    ]);

    $response->assertRedirect(route('projects.show', $project));
});

test('project index shows only the current users projects ordered by recent updates', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $olderProject = Project::factory()->for($user)->create(['name' => 'Older project']);
    $recentProject = Project::factory()->for($user)->create(['name' => 'Recently updated project']);
    Project::factory()->for($otherUser)->create(['name' => 'Another users private project']);

    $olderProject->forceFill(['updated_at' => now()->subDay()])->save();

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSeeInOrder(['Recently updated project', 'Older project'])
        ->assertDontSee('Another users private project')
        ->assertSee(route('projects.show', $recentProject));
});

test('project index shows a useful empty state', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertOk()
        ->assertSee('No projects yet')
        ->assertSee('Create your first project');
});

test('project uuid resolves through public route binding instead of the numeric id', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['name' => 'UUID route project']);
    $this->actingAs($user);

    $this->get(route('projects.show', $project))->assertOk()->assertSee('UUID route project');
    $this->get('/projects/'.$project->getKey())->assertNotFound();
});

test('owners can view edit and update a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['name' => 'Draft name', 'description' => 'Draft description']);
    $this->actingAs($user);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Draft name')
        ->assertSee('No content has been generated for this project yet.');
    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('value="Draft name"', false)
        ->assertSee('Draft description');
    $this->put(route('projects.update', $project), [
        'name' => 'Updated name',
        'description' => 'Updated description',
    ])->assertRedirect(route('projects.show', $project))->assertSessionHas('status', 'Project updated.');

    expect($project->fresh()->name)->toBe('Updated name')
        ->and($project->fresh()->description)->toBe('Updated description');
});

test('another user cannot view edit update or delete a project', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['name' => 'Owners private project']);
    $this->actingAs($intruder);

    $this->get(route('projects.show', $project))->assertForbidden()->assertDontSee('Owners private project');
    $this->get(route('projects.edit', $project))->assertForbidden();
    $this->put(route('projects.update', $project), ['name' => 'Changed by intruder'])->assertForbidden();
    $this->delete(route('projects.destroy', $project))->assertForbidden();

    expect($project->fresh()->name)->toBe('Owners private project');
    $this->assertModelExists($project);
});

test('deleting a project cascades through generated content and version history', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'origin' => GeneratedContentVersionOrigin::AiGenerated,
    ]);

    $this->actingAs($user)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'))
        ->assertSessionHas('status', 'Project deleted.');

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('generated_contents', ['id' => $generatedContent->id]);
    $this->assertDatabaseMissing('generated_content_versions', ['id' => $version->id]);
});

test('project names and descriptions are rendered as escaped text', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'name' => '<script>alert(1)</script>',
        'description' => '<b>Text</b>',
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('&lt;b&gt;Text&lt;/b&gt;', false)
        ->assertDontSee('<script>', false);
});
