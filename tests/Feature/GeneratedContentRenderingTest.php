<?php

use App\ContentTypes\ContentTypeRegistry;
use App\ContentTypes\Definitions\NewsArticleType;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('registered news articles use the dedicated presentation and retain structured data inspection', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'news_article',
        'title' => 'The harbor falls silent',
    ]);
    $content = [
        'headline' => 'The harbor falls silent',
        'publication' => 'The Harbor Ledger',
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => "At dawn, the ferry arrived.\n\nAt  08:00, café — 朋友 reported: no one was aboard.",
    ];
    $version = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => $content,
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('News article')
        ->assertSee('The Harbor Ledger')
        ->assertSee('Fictional publication')
        ->assertSee('The harbor falls silent')
        ->assertSee('2025-06-15T10:30:00Z')
        ->assertSee('At dawn, the ferry arrived.')
        ->assertSee('At  08:00, café — 朋友 reported: no one was aboard.')
        ->assertSee('whitespace-pre-wrap', false)
        ->assertSee('Back to '.$project->name)
        ->assertSee('Version 1')
        ->assertSee('Created '.$version->created_at->format('M j, Y · g:i A'))
        ->assertSee('View structured data')
        ->assertSee('&quot;headline&quot;', false);

    expect($generatedContent->versions()->sole()->content)->toBe($content);
    Http::assertNothingSent();
});

test('news article text and structured data escape generated html and scripts', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'news_article',
    ]);
    $payload = '<script>alert("fiction")</script>';

    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => [
            'headline' => $payload,
            'publication' => $payload,
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => $payload,
        ],
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;fiction&quot;)&lt;/script&gt;', false)
        ->assertDontSee($payload, false)
        ->assertSee('View structured data');

    Http::assertNothingSent();
});

test('unregistered legacy content types remain available through the escaped json fallback', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'retired_type',
        'title' => 'Legacy record',
    ]);
    $payload = '<img src=x onerror=alert(1)>';
    $content = [
        'nested' => [
            'value' => $payload,
            'unicode' => 'Café — 朋友',
        ],
    ];

    GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $content]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('retired_type')
        ->assertSee('No dedicated presentation is available for this content type.')
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertSee('Café — 朋友')
        ->assertDontSee($payload, false);

    Http::assertNothingSent();
});

test('registered content with an unavailable presentation uses the safe json fallback', function () {
    $definition = new class extends NewsArticleType
    {
        public function presentationView(): ?string
        {
            return 'generated-content.missing-presentation';
        }
    };
    app()->instance(ContentTypeRegistry::class, new ContentTypeRegistry($definition));

    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'news_article',
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => [
            'headline' => 'Readable fallback',
            'publication' => 'The Harbor Ledger',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => 'Stored content remains accessible.',
        ],
    ]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.show', [$project, $generatedContent]))
        ->assertOk()
        ->assertSee('News article')
        ->assertSee('No dedicated presentation is available for this content type.')
        ->assertSee('Stored content remains accessible.');

    Http::assertNothingSent();
});
