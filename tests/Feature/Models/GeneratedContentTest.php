<?php

use App\Models\GeneratedContent;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generated content belongs to a project and persists its stable content type key', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'news_article',
    ]);

    $persistedContent = $generatedContent->fresh();

    expect($persistedContent->project->is($project))->toBeTrue()
        ->and($project->generatedContents)->toHaveCount(1)
        ->and($persistedContent->content_type)->toBe('news_article');
});
