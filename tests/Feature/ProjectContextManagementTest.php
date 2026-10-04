<?php

use App\Http\Requests\UpdateProjectContextRequest;
use App\Models\Project;
use App\Models\ProjectContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owners can view and update reusable context sections', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Project context')
        ->assertSee('No project context has been added yet.')
        ->assertSee(route('projects.context.edit', $project));

    $this->get(route('projects.context.edit', $project))
        ->assertOk()
        ->assertSee('Time period')
        ->assertSee('Other established facts');

    $this->put(route('projects.context.update', $project), [
        'context' => [
            'setting' => '  The city floats above a dark ocean.  ',
            'time_period' => '   ',
            'locations' => 'North Pier',
        ],
        'project_id' => 999,
        'user_id' => 999,
        'api_key' => 'must-not-persist',
    ])->assertRedirect(route('projects.show', $project))
        ->assertSessionHas('status', 'Project context saved.');

    $context = $project->context()->firstOrFail();

    expect($context->generationFields())->toBe([
        'setting' => 'The city floats above a dark ocean.',
        'time_period' => null,
        'locations' => 'North Pier',
        'people' => null,
        'organizations' => null,
        'canon_notes' => null,
    ])->and($context->project_id)->toBe($project->getKey())
        ->and($context->getAttributes())->not->toHaveKeys(['api_key', 'user_id']);

    $this->assertDatabaseCount('project_contexts', 1);
    $this->assertDatabaseMissing('project_contexts', ['setting' => 'must-not-persist']);
});

test('guests and other users cannot access or update project context', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $project->context()->create(['setting' => 'Private world details.']);

    $this->get(route('projects.context.edit', $project))->assertRedirect(route('login'));
    $this->put(route('projects.context.update', $project), ['context' => ['setting' => 'Changed.']])
        ->assertRedirect(route('login'));

    $this->actingAs($otherUser)
        ->get(route('projects.context.edit', $project))
        ->assertForbidden();

    $this->put(route('projects.context.update', $project), ['context' => ['setting' => 'Changed.']])
        ->assertForbidden();

    expect($project->context()->firstOrFail()->setting)->toBe('Private world details.');
});

test('context validation preserves only supported text and rejects unexpected nested fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $this->actingAs($user);

    $this->from(route('projects.context.edit', $project))
        ->put(route('projects.context.update', $project), [
            'context' => [
                'setting' => ['api_key' => 'nested-secret'],
                'canon_notes' => 'Keep this supported value.',
                'unexpected' => 'Do not flash this.',
            ],
            'api_key' => 'top-level-secret',
            'user_id' => 999,
        ])
        ->assertRedirect(route('projects.context.edit', $project))
        ->assertSessionHasErrors(['context', 'context.setting'])
        ->assertSessionHas('_old_input.context', ['canon_notes' => 'Keep this supported value.'])
        ->assertSessionMissing('_old_input.api_key')
        ->assertSessionMissing('_old_input.user_id');

    expect($project->context()->exists())->toBeFalse();

    $tooLong = str_repeat('x', UpdateProjectContextRequest::MAX_SECTION_LENGTH + 1);

    $this->from(route('projects.context.edit', $project))
        ->put(route('projects.context.update', $project), ['context' => ['setting' => $tooLong]])
        ->assertRedirect(route('projects.context.edit', $project))
        ->assertSessionHasErrors('context.setting')
        ->assertSessionHas('_old_input.context.setting', $tooLong);

    expect($project->context()->exists())->toBeFalse();
});

test('owners can clear individual sections and remove an entirely empty context record', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $this->actingAs($user);
    $project->context()->create([
        'setting' => 'A floating city.',
        'time_period' => 'Year 2110.',
        'locations' => 'Cloud Harbor.',
    ]);

    $this->put(route('projects.context.update', $project), [
        'context' => ['setting' => '', 'time_period' => 'Year 2110.', 'locations' => 'Cloud Harbor.'],
    ])->assertRedirect(route('projects.show', $project));

    expect($project->context()->firstOrFail()->generationFields())->toBe([
        'setting' => null,
        'time_period' => 'Year 2110.',
        'locations' => 'Cloud Harbor.',
        'people' => null,
        'organizations' => null,
        'canon_notes' => null,
    ]);

    $this->put(route('projects.context.update', $project), [
        'context' => array_fill_keys(ProjectContext::SECTION_FIELDS, ''),
    ])->assertRedirect(route('projects.show', $project))
        ->assertSessionHas('status', 'Project context cleared.');

    expect($project->context()->exists())->toBeFalse();
});

test('project context is deleted when its project is deleted', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $context = $project->context()->create(['canon_notes' => 'Nothing survives the project.']);

    $this->actingAs($user)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect(route('projects.index'));

    $this->assertDatabaseMissing('project_contexts', ['id' => $context->id]);
});

test('a project has one context and the factory associates it correctly', function () {
    $project = Project::factory()->create();
    $context = ProjectContext::factory()->for($project)->create();

    expect($context->project_id)->toBe($project->getKey())
        ->and($project->context()->first()->is($context))->toBeTrue();

    expect(fn () => $project->context()->create(['setting' => 'Second context.']))
        ->toThrow(QueryException::class);
});

test('project context supports long escaped text without a short database limit', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $longText = str_repeat('A setting with Unicode — 世界. ', 500);

    $this->actingAs($user)
        ->put(route('projects.context.update', $project), ['context' => ['setting' => $longText]])
        ->assertRedirect(route('projects.show', $project));

    expect($project->context()->firstOrFail()->setting)->toBe(trim($longText));
});

test('combined context accepts exactly 40000 unicode characters and rejects one more', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $this->actingAs($user);

    $sections = [
        'setting' => str_repeat('界', 10000),
        'time_period' => str_repeat('é', 10000),
        'locations' => str_repeat('ñ', 10000),
        'people' => str_repeat('Ж', 10000),
    ];

    $this->put(route('projects.context.update', $project), ['context' => $sections])
        ->assertRedirect(route('projects.show', $project));

    $savedContext = $project->context()->firstOrFail();
    $savedLength = array_sum(array_map(
        static fn (?string $value): int => $value === null ? 0 : mb_strlen($value, 'UTF-8'),
        $savedContext->generationFields(),
    ));

    expect($savedLength)->toBe(UpdateProjectContextRequest::MAX_TOTAL_LENGTH);

    $sections['canon_notes'] = '界';

    $this->from(route('projects.context.edit', $project))
        ->put(route('projects.context.update', $project), ['context' => $sections])
        ->assertRedirect(route('projects.context.edit', $project))
        ->assertSessionHasErrors([
            'context' => 'The combined project context may not exceed 40,000 characters.',
        ])
        ->assertSessionHas('_old_input.context.canon_notes', '界');

    expect($savedContext->fresh()->generationFields())->toBe(array_replace(
        array_fill_keys(ProjectContext::SECTION_FIELDS, null),
        $sections,
        ['canon_notes' => null],
    ));
});

test('project context is escaped when shown on the project page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $project->context()->create(['canon_notes' => '<script>alert("fictional")</script>']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;fictional&quot;)&lt;/script&gt;', false)
        ->assertDontSee('<script>', false);
});
