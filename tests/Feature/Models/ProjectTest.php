<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('projects belong to their user and users can retrieve their projects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    expect($project->user->is($user))->toBeTrue()
        ->and($user->projects)->toHaveCount(1)
        ->and($user->projects->first()->is($project))->toBeTrue();
});
