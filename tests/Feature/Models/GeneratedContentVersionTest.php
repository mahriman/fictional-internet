<?php

use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;

uses(RefreshDatabase::class);

test('generated content has separately persisted versions', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $firstVersion = GeneratedContentVersion::factory()->for($generatedContent)->create();
    $secondVersion = GeneratedContentVersion::factory()->for($generatedContent)->userEdited()->create([
        'version_number' => 2,
    ]);

    expect($firstVersion->generatedContent->is($generatedContent))->toBeTrue()
        ->and($generatedContent->versions)->toHaveCount(2)
        ->and($generatedContent->versions->pluck('version_number')->all())->toBe([1, 2]);
});

test('structured content and context metadata cast to arrays', function () {
    $version = GeneratedContentVersion::factory()->create([
        'content' => ['headline' => 'Midnight Signal', 'body' => 'A strange broadcast aired.'],
        'context_snapshot' => ['location' => 'North Harbor'],
        'generation_metadata' => ['prompt_revision' => 'news-article-v1'],
    ])->fresh();

    expect($version->content)->toBe([
        'headline' => 'Midnight Signal',
        'body' => 'A strange broadcast aired.',
    ])
        ->and($version->context_snapshot)->toBe(['location' => 'North Harbor'])
        ->and($version->generation_metadata)->toBe(['prompt_revision' => 'news-article-v1']);
});

test('version origin values cast to their backed enum cases', function () {
    $aiVersion = GeneratedContentVersion::factory()->create();
    $userVersion = GeneratedContentVersion::factory()->userEdited()->create([
        'version_number' => 2,
    ]);

    expect($aiVersion->fresh()->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and($userVersion->fresh()->origin)->toBe(GeneratedContentVersionOrigin::UserEdited);
});

test('version numbers are unique for each generated content record', function () {
    $generatedContent = GeneratedContent::factory()->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 1]);

    expect(fn () => GeneratedContentVersion::factory()
        ->for($generatedContent)
        ->create(['version_number' => 1]))
        ->toThrow(QueryException::class);
});

test('existing versions cannot be updated or deleted individually', function () {
    $version = GeneratedContentVersion::factory()->create();

    expect(fn () => $version->update(['content' => ['headline' => 'Changed']]))
        ->toThrow(LogicException::class, 'Generated content versions are immutable.')
        ->and(fn () => $version->delete())
        ->toThrow(LogicException::class, 'Generated content versions cannot be deleted individually.');
});

test('deleting a user cascades through projects content and versions', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create();

    $user->delete();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('generated_contents', ['id' => $generatedContent->id]);
    $this->assertDatabaseMissing('generated_content_versions', ['id' => $version->id]);
});
