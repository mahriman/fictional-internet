<?php

use App\Actions\AppendGeneratedContentVersion;
use App\Actions\EditGeneratedContentVersion;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

test('editing a version appends an immutable user-edited descendant with inherited context', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => ['headline' => 'Original'],
        'context_snapshot' => ['location' => 'North Harbor'],
        'generation_metadata' => ['model' => 'test-model'],
    ]);

    $editedVersion = app(EditGeneratedContentVersion::class)->handle($sourceVersion, [
        'headline' => 'Edited headline',
    ]);

    expect($sourceVersion->fresh()->content)->toBe(['headline' => 'Original'])
        ->and($editedVersion->generated_content_id)->toBe($generatedContent->id)
        ->and($editedVersion->version_number)->toBe(2)
        ->and($editedVersion->content)->toBe(['headline' => 'Edited headline'])
        ->and($editedVersion->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($editedVersion->based_on_version_id)->toBe($sourceVersion->id)
        ->and($editedVersion->based_on_version_id)->not->toBe($editedVersion->id)
        ->and($editedVersion->context_snapshot)->toBe(['location' => 'North Harbor'])
        ->and($editedVersion->generation_metadata)->toBeNull()
        ->and($editedVersion->basedOnVersion->is($sourceVersion))->toBeTrue()
        ->and($sourceVersion->descendantVersions->sole()->is($editedVersion))->toBeTrue();
});

test('editing an older version appends after newer versions', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $appendVersion = app(AppendGeneratedContentVersion::class);
    $firstVersion = $appendVersion->handle(
        $generatedContent,
        ['headline' => 'First'],
        GeneratedContentVersionOrigin::AiGenerated,
    );
    $appendVersion->handle($generatedContent, ['headline' => 'Second'], GeneratedContentVersionOrigin::AiGenerated);
    $appendVersion->handle($generatedContent, ['headline' => 'Third'], GeneratedContentVersionOrigin::AiGenerated);

    $editedVersion = app(EditGeneratedContentVersion::class)->handle($firstVersion, ['headline' => 'Edit of first']);

    expect($editedVersion->version_number)->toBe(4)
        ->and($editedVersion->based_on_version_id)->toBe($firstVersion->id)
        ->and($generatedContent->versions->pluck('version_number')->all())->toBe([1, 2, 3, 4]);
});

test('a source version can have multiple edited descendants', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $sourceVersion = GeneratedContentVersion::factory()->for($generatedContent)->create();
    $editVersion = app(EditGeneratedContentVersion::class);

    $firstDescendant = $editVersion->handle($sourceVersion, ['headline' => 'First edit']);
    $secondDescendant = $editVersion->handle($sourceVersion, ['headline' => 'Second edit']);

    expect($sourceVersion->descendantVersions->pluck('id')->all())
        ->toBe([$firstDescendant->id, $secondDescendant->id])
        ->and($firstDescendant->version_number)->toBe(2)
        ->and($secondDescendant->version_number)->toBe(3);
});

test('appending rejects a source version from another content without persisting a version', function () {
    $targetContent = GeneratedContent::factory()->create();
    $otherContent = GeneratedContent::factory()->create();
    $sourceVersion = GeneratedContentVersion::factory()->for($otherContent)->create();

    expect(fn () => app(AppendGeneratedContentVersion::class)->handle(
        $targetContent,
        ['headline' => 'Invalid lineage'],
        GeneratedContentVersionOrigin::UserEdited,
        null,
        null,
        $sourceVersion,
    ))->toThrow(InvalidArgumentException::class, 'A source version must belong to the same generated content record.');

    expect($targetContent->versions()->count())->toBe(0)
        ->and($otherContent->versions()->count())->toBe(1);
});

test('editing rejects an unsaved source version', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $unsavedSourceVersion = new GeneratedContentVersion;

    expect(fn () => app(EditGeneratedContentVersion::class)->handle(
        $unsavedSourceVersion,
        ['headline' => 'No source'],
    ))->toThrow(InvalidArgumentException::class, 'An edit must be based on an existing version.');

    expect(fn () => app(AppendGeneratedContentVersion::class)->handle(
        $generatedContent,
        ['headline' => 'No source'],
        GeneratedContentVersionOrigin::UserEdited,
        basedOnVersion: $unsavedSourceVersion,
    ))->toThrow(InvalidArgumentException::class, 'A version can only be based on an existing version.');
});

test('based-on version ids must reference an existing version', function () {
    $generatedContent = GeneratedContent::factory()->create();

    expect(fn () => GeneratedContentVersion::factory()->for($generatedContent)->create([
        'based_on_version_id' => 999999,
    ]))->toThrow(QueryException::class);
});
