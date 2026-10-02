<?php

use App\Actions\AppendGeneratedContentVersion;
use App\Actions\CreateGeneratedContent;
use App\Enums\GeneratedContentVersionOrigin;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

uses(RefreshDatabase::class);

test('content can be created in a project without creating an initial version', function () {
    $project = Project::factory()->create();

    $generatedContent = app(CreateGeneratedContent::class)->handle($project, 'news_article', 'Harbor Lights');

    expect($generatedContent->project->is($project))->toBeTrue()
        ->and($generatedContent->content_type)->toBe('news_article')
        ->and($generatedContent->title)->toBe('Harbor Lights')
        ->and($generatedContent->versions)->toBeEmpty();
});

test('content creation rejects an unregistered content type', function () {
    $project = Project::factory()->create();

    expect(fn () => app(CreateGeneratedContent::class)->handle($project, 'unknown_type'))
        ->toThrow(InvalidArgumentException::class, 'Content type key [unknown_type] is not registered.');
});

test('content creation rejects an unsaved project', function () {
    expect(fn () => app(CreateGeneratedContent::class)->handle(new Project, 'news_article'))
        ->toThrow(InvalidArgumentException::class, 'Generated content must belong to an existing project.');
});

test('content creation validates an optional title against the database column size', function () {
    $project = Project::factory()->create();

    expect(fn () => app(CreateGeneratedContent::class)->handle($project, 'news_article', str_repeat('a', 256)))
        ->toThrow(ValidationException::class);
});

test('the first appended version is numbered one and persists structured content and metadata', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $content = ['headline' => 'Midnight Signal', 'body' => 'A strange broadcast aired.'];
    $contextSnapshot = ['location' => 'North Harbor'];
    $generationMetadata = ['prompt_revision' => 'news-article-v1'];

    $version = app(AppendGeneratedContentVersion::class)->handle(
        $generatedContent,
        $content,
        GeneratedContentVersionOrigin::AiGenerated,
        $contextSnapshot,
        $generationMetadata,
    );

    expect($version)->toBeInstanceOf(GeneratedContentVersion::class)
        ->and($version->generated_content_id)->toBe($generatedContent->id)
        ->and($version->version_number)->toBe(1)
        ->and($version->origin)->toBe(GeneratedContentVersionOrigin::AiGenerated)
        ->and($version->fresh()->content)->toBe($content)
        ->and($version->fresh()->context_snapshot)->toBe($contextSnapshot)
        ->and($version->fresh()->generation_metadata)->toBe($generationMetadata);
});

test('subsequent versions receive sequential numbers and preserve previous versions', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $appendVersion = app(AppendGeneratedContentVersion::class);
    $firstVersion = $appendVersion->handle(
        $generatedContent,
        ['headline' => 'First'],
        GeneratedContentVersionOrigin::AiGenerated,
    );

    $secondVersion = $appendVersion->handle(
        $generatedContent,
        ['headline' => 'Second'],
        GeneratedContentVersionOrigin::UserEdited,
    );

    expect($firstVersion->fresh()->version_number)->toBe(1)
        ->and($firstVersion->fresh()->content)->toBe(['headline' => 'First'])
        ->and($secondVersion->version_number)->toBe(2)
        ->and($secondVersion->origin)->toBe(GeneratedContentVersionOrigin::UserEdited)
        ->and($generatedContent->versions)->toHaveCount(2);
});

test('appending after a version-number gap continues after the highest number', function () {
    $generatedContent = GeneratedContent::factory()->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 1]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 3]);

    $version = app(AppendGeneratedContentVersion::class)->handle(
        $generatedContent,
        ['headline' => 'After the gap'],
        GeneratedContentVersionOrigin::AiGenerated,
    );

    expect($version->version_number)->toBe(4);
});

test('version history is ordered by version number', function () {
    $generatedContent = GeneratedContent::factory()->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 3]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 1]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 2]);

    expect($generatedContent->versions->pluck('version_number')->all())->toBe([1, 2, 3]);
});

test('the parent lookup, version calculation, and insert run inside the append transaction', function () {
    $generatedContent = GeneratedContent::factory()->create();
    $transactionLevelBeforeAppend = DB::transactionLevel();
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'generated_contents') || str_contains($query->sql, 'generated_content_versions')) {
            $queries[] = [
                'sql' => $query->sql,
                'transaction_level' => DB::transactionLevel(),
            ];
        }
    });

    app(AppendGeneratedContentVersion::class)->handle(
        $generatedContent,
        ['headline' => 'Locked append'],
        GeneratedContentVersionOrigin::AiGenerated,
    );

    expect($queries)->toHaveCount(3)
        ->and($queries[0]['sql'])->toContain('generated_contents')
        ->and($queries[1]['sql'])->toContain('order by "version_number" desc')
        ->and($queries[2]['sql'])->toContain('insert into')
        ->and(array_column($queries, 'transaction_level'))
        ->toBe(array_fill(0, 3, $transactionLevelBeforeAppend + 1));
});

test('appending a version rejects an unsaved generated content record', function () {
    expect(fn () => app(AppendGeneratedContentVersion::class)->handle(
        new GeneratedContent,
        ['headline' => 'Orphan'],
        GeneratedContentVersionOrigin::AiGenerated,
    ))->toThrow(InvalidArgumentException::class, 'A version must belong to an existing generated content record.');
});
