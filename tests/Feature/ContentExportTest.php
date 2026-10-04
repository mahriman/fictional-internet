<?php

use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Models\User;
use App\Services\Export\ContentDocumentRenderer;
use App\Services\Export\ExportRenderLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function contentExportUrl(Project $project, GeneratedContent $generatedContent, string $format): string
{
    return route('projects.generated-content.export', compact('project', 'generatedContent', 'format'));
}

function contentVersionExportUrl(Project $project, GeneratedContent $generatedContent, int $versionNumber, string $format): string
{
    return route('projects.generated-content.versions.export', compact('project', 'generatedContent', 'versionNumber', 'format'));
}

test('authenticated owners can download standalone html for the highest numbered version', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => articleExportContent('Old version title', 'Old version body.'),
    ]);
    $latestVersion = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'content' => articleExportContent('Latest version title', 'Latest version body.'),
    ]);

    $response = $this->actingAs($project->user)->get(contentExportUrl($project, $generatedContent, 'html'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $html = $response->streamedContent();

    expect($html)
        ->toContain('<!doctype html>', '<meta charset="utf-8">', 'Latest version title', 'Latest version body.')
        ->not->toContain('Old version title', 'Version history', 'Edit this version', 'Download PDF');

    expect(canonicalizeJsonStructure($latestVersion->fresh()->content))
        ->toBe(canonicalizeJsonStructure(articleExportContent('Latest version title', 'Latest version body.')));
    Http::assertNothingSent();
});

test('historical exports use only the selected immutable version and safe deterministic filenames', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'news_article']);
    $selected = GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 1,
        'content' => articleExportContent('Historical title', 'Historical body.'),
        'context_snapshot' => ['prompt' => 'private prompt must not be exported'],
        'generation_metadata' => ['provider_response_id' => 'private-metadata'],
    ]);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'version_number' => 2,
        'content' => articleExportContent('Newer title', 'Newer body.'),
    ]);
    $renderer = Mockery::mock(ContentDocumentRenderer::class);
    $renderer->shouldReceive('render')
        ->once()
        ->with(Mockery::on(fn (string $html): bool => str_contains($html, 'Historical body.')
            && ! str_contains($html, 'Newer body.')
            && ! str_contains($html, 'private prompt must not be exported')
            && ! str_contains($html, 'private-metadata')), ContentExportFormat::Pdf)
        ->andReturn('%PDF-test-bytes');
    $this->app->instance(ContentDocumentRenderer::class, $renderer);

    $response = $this->actingAs($project->user)
        ->get(contentVersionExportUrl($project, $generatedContent, 1, 'pdf'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Content-Disposition', 'attachment; filename=fictional-internet-news-article-'.$generatedContent->uuid.'-v1.pdf');

    expect($response->streamedContent())->toBe('%PDF-test-bytes')
        ->and(canonicalizeJsonStructure($selected->fresh()->content))
        ->toBe(canonicalizeJsonStructure(articleExportContent('Historical title', 'Historical body.')))
        ->and(canonicalizeJsonStructure($selected->fresh()->context_snapshot))
        ->toBe(['prompt' => 'private prompt must not be exported'])
        ->and(canonicalizeJsonStructure($selected->fresh()->generation_metadata))
        ->toBe(['provider_response_id' => 'private-metadata']);
    Http::assertNothingSent();
});

test('the version page exposes export links for the version currently being viewed', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 1]);
    GeneratedContentVersion::factory()->for($generatedContent)->create(['version_number' => 2]);

    $this->actingAs($project->user)
        ->get(route('projects.generated-content.versions.show', [$project, $generatedContent, 1]))
        ->assertOk()
        ->assertSee('Download PDF')
        ->assertSee('Download PNG')
        ->assertSee(contentVersionExportUrl($project, $generatedContent, 1, 'pdf'))
        ->assertSee(contentVersionExportUrl($project, $generatedContent, 1, 'png'));

    Http::assertNothingSent();
});

test('registered content types select their own escaped standalone presentation', function () {
    $project = Project::factory()->create();
    $documents = [
        'news_article' => [
            'publication' => 'The Fictional Ledger',
            'headline' => 'Editorial headline',
            'published_at' => '2025-06-15T10:30:00Z',
            'body' => "First paragraph.\n\nSecond paragraph café.",
        ],
        'forum_thread' => [
            'forum_name' => 'Harbor Forum',
            'category' => 'Local news',
            'thread_title' => 'Forum conversation',
            'started_at' => '2025-06-15T10:30:00+00:00',
            'posts' => [
                ['post_number' => 1, 'author' => 'First poster', 'posted_at' => '2025-06-15T10:30:00+00:00', 'body' => "Opening post.\nMore text.", 'reply_to_post_number' => null, 'quote' => null],
                ['post_number' => 2, 'author' => 'Second poster', 'posted_at' => '2025-06-15T10:31:00+00:00', 'body' => 'Response with <script>bad()</script>.', 'reply_to_post_number' => 1, 'quote' => null],
            ],
        ],
        'schrecknet_thread' => [
            'network' => 'SchreckNet',
            'channel' => 'harbor-watch',
            'thread_title' => 'Encrypted discussion',
            'started_at' => '2025-06-15T10:30:00+00:00',
            'messages' => [
                ['message_number' => 1, 'handle' => 'relay', 'posted_at' => '2025-06-15T10:30:00+00:00', 'body' => 'First message.', 'reply_to_message_number' => null, 'quote' => null],
                ['message_number' => 2, 'handle' => 'watcher', 'posted_at' => '2025-06-15T10:31:00+00:00', 'body' => 'Response café <script>bad()</script>.', 'reply_to_message_number' => 1, 'quote' => null],
            ],
        ],
    ];

    foreach ($documents as $contentType => $content) {
        $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => $contentType]);
        GeneratedContentVersion::factory()->for($generatedContent)->create(['content' => $content]);

        $response = $this->actingAs($project->user)->get(contentExportUrl($project, $generatedContent, 'html'));
        $response->assertOk();
        $html = $response->streamedContent();
        expect($html)->toContain('<main class="export-document"');

        foreach (array_filter($content, 'is_string') as $value) {
            expect($html)->toContain(e($value));
        }

        if ($contentType === 'forum_thread') {
            expect($html)->toContain('#1', '#2', 'Second poster', 'More text.');
        }

        if ($contentType === 'schrecknet_thread') {
            expect($html)->toContain('[1]', '[2]', 'watcher', 'Encrypted discussion');
        }

        expect($html)
            ->not->toContain('<script>bad()</script>', 'Version history', 'View structured data');
    }

    Http::assertNothingSent();
});

test('unknown and legacy content types remain exportable through escaped structured json', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create([
        'content_type' => 'legacy_unregistered_type',
    ]);
    $hostileValue = '<script>window.stolen=true</script>';
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => ['nested' => ['body' => $hostileValue], 'other' => 'café — 朋友'],
        'context_snapshot' => ['should_not_export' => 'private context'],
    ]);

    $response = $this->actingAs($project->user)->get(contentExportUrl($project, $generatedContent, 'html'));
    $response->assertOk();
    $html = $response->streamedContent();

    expect($html)
        ->toContain('legacy_unregistered_type', '&quot;body&quot;', 'café — 朋友', '&lt;script&gt;window.stolen=true&lt;/script&gt;')
        ->not->toContain('<script>window.stolen=true</script>', 'private context', '<nav');

    Http::assertNothingSent();
});

test('long forum and schrecknet documents retain every ordered post and message', function () {
    $project = Project::factory()->create();
    $forum = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    $posts = [];

    for ($number = 1; $number <= 20; $number++) {
        $posts[] = [
            'post_number' => $number,
            'author' => "Forum author {$number}",
            'posted_at' => sprintf('2025-06-15T10:%02d:00+00:00', $number),
            'body' => "Forum body {$number} with a newline.\nNext line.",
            'reply_to_post_number' => $number > 1 ? $number - 1 : null,
            'quote' => null,
        ];
    }

    GeneratedContentVersion::factory()->for($forum)->create(['content' => [
        'forum_name' => 'Harbor Forum', 'category' => 'Local', 'thread_title' => 'A long conversation',
        'started_at' => '2025-06-15T10:01:00+00:00', 'posts' => $posts,
    ]]);

    $schrecknet = GeneratedContent::factory()->for($project)->create(['content_type' => 'schrecknet_thread']);
    $messages = [];

    for ($number = 1; $number <= 30; $number++) {
        $messages[] = [
            'message_number' => $number,
            'handle' => "handle-{$number}",
            'posted_at' => sprintf('2025-06-15T10:%02d:00+00:00', $number),
            'body' => "SchreckNet message {$number} — café.\nSecond line.",
            'reply_to_message_number' => $number > 1 ? $number - 1 : null,
            'quote' => null,
        ];
    }

    GeneratedContentVersion::factory()->for($schrecknet)->create(['content' => [
        'network' => 'SchreckNet', 'channel' => 'harbor-watch', 'thread_title' => 'Long exchange',
        'started_at' => '2025-06-15T10:01:00+00:00', 'messages' => $messages,
    ]]);

    $forumHtml = $this->actingAs($project->user)->get(contentExportUrl($project, $forum, 'html'))->assertOk()->streamedContent();
    expect($forumHtml)->toContain('Forum author 20', 'Forum body 20 with a newline.', 'Next line.');
    $schreckHtml = $this->actingAs($project->user)->get(contentExportUrl($project, $schrecknet, 'html'))->assertOk()->streamedContent();
    expect($schreckHtml)->toContain('handle-30', 'SchreckNet message 30 — café.', 'Second line.');

    Http::assertNothingSent();
});

test('guests, other users and cross-project identifiers cannot export content', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($generatedContent)->create();

    $this->get(contentExportUrl($project, $generatedContent, 'html'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())
        ->get(contentExportUrl($project, $generatedContent, 'html'))
        ->assertForbidden();
    $this->actingAs($project->user)
        ->get(contentExportUrl($otherProject, $generatedContent, 'html'))
        ->assertNotFound();
    $this->get(route('projects.generated-content.export', [$project, '00000000-0000-4000-8000-000000000000', 'html']))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('export rejects unsupported formats and historical versions outside the requested content', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($content)->create(['version_number' => 1]);
    $anotherContent = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($anotherContent)->create(['version_number' => 8]);

    $this->actingAs($project->user)
        ->get(contentExportUrl($project, $content, 'exe'))
        ->assertNotFound();
    $this->get(contentVersionExportUrl($project, $content, 8, 'pdf'))->assertNotFound();
    $this->get(route('projects.generated-content.versions.export', [$project, $content, '0', 'png']))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('pdf and png downloads use the matching renderer format and attachment filename', function () {
    $project = Project::factory()->create();
    $generatedContent = GeneratedContent::factory()->for($project)->create(['content_type' => 'forum_thread']);
    GeneratedContentVersion::factory()->for($generatedContent)->create([
        'content' => ['forum_name' => 'Export Forum', 'category' => 'Test', 'thread_title' => 'Export title', 'started_at' => '2025-06-15T10:30:00+00:00', 'posts' => []],
    ]);
    $renderer = Mockery::mock(ContentDocumentRenderer::class);
    $renderer->shouldReceive('render')->once()->with(Mockery::on(fn (string $html): bool => str_contains($html, 'Export Forum')), ContentExportFormat::Pdf)->andReturn('%PDF-fake');
    $renderer->shouldReceive('render')->once()->with(Mockery::on(fn (string $html): bool => str_contains($html, 'Export title')), ContentExportFormat::Png)->andReturn("\x89PNG\r\n\x1a\n-fake");
    $this->app->instance(ContentDocumentRenderer::class, $renderer);

    $pdf = $this->actingAs($project->user)->get(contentExportUrl($project, $generatedContent, 'pdf'));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename=fictional-internet-forum-thread-'.$generatedContent->uuid.'-v1.pdf');
    expect($pdf->streamedContent())->toBe('%PDF-fake');

    $png = $this->get(contentExportUrl($project, $generatedContent, 'png'));
    $png->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'attachment; filename=fictional-internet-forum-thread-'.$generatedContent->uuid.'-v1.png');
    expect($png->streamedContent())->toBe("\x89PNG\r\n\x1a\n-fake");

    Http::assertNothingSent();
});

test('a safe renderer failure does not disclose its implementation details', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($content)->create();
    $renderer = Mockery::mock(ContentDocumentRenderer::class);
    $renderer->shouldReceive('render')->once()->andThrow(new ContentExportException('The document could not be rendered. Please try the export again.'));
    $this->app->instance(ContentDocumentRenderer::class, $renderer);
    $lockDirectory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $limiter = new ExportRenderLimiter(1, $lockDirectory);
    $this->app->instance(ExportRenderLimiter::class, $limiter);

    try {
        $response = $this->actingAs($project->user)->get(contentExportUrl($project, $content, 'pdf'));

        $response->assertServiceUnavailable()->assertSee('The document could not be rendered. Please try the export again.');
        $slot = $limiter->acquire();

        expect($slot)->not->toBeNull();
        $slot?->release();
        Http::assertNothingSent();
    } finally {
        File::deleteDirectory($lockDirectory);
    }
});

test('html bypasses render slots while pdf and png share busy capacity feedback', function () {
    $project = Project::factory()->create();
    $content = GeneratedContent::factory()->for($project)->create();
    GeneratedContentVersion::factory()->for($content)->create();
    $renderer = Mockery::mock(ContentDocumentRenderer::class);
    $renderer->shouldNotReceive('render');
    $this->app->instance(ContentDocumentRenderer::class, $renderer);
    $lockDirectory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $limiter = new ExportRenderLimiter(1, $lockDirectory);
    $heldSlot = $limiter->acquire();
    $this->app->instance(ExportRenderLimiter::class, $limiter);

    try {
        $this->actingAs($project->user)
            ->get(contentExportUrl($project, $content, 'html'))
            ->assertOk();

        $this->get(contentExportUrl($project, $content, 'pdf'))
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '10')
            ->assertSee('Export capacity is temporarily busy. Please try again shortly.')
            ->assertDontSee($lockDirectory);

        $this->get(contentExportUrl($project, $content, 'png'))
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '10')
            ->assertSee('Export capacity is temporarily busy. Please try again shortly.');

        Http::assertNothingSent();
    } finally {
        $heldSlot?->release();
        File::deleteDirectory($lockDirectory);
    }
});

/**
 * @return array{publication: string, headline: string, published_at: string, body: string}
 */
function articleExportContent(string $headline, string $body): array
{
    return [
        'publication' => 'The Fictional Ledger',
        'headline' => $headline,
        'published_at' => '2025-06-15T10:30:00Z',
        'body' => $body,
    ];
}
