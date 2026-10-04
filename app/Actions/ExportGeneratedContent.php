<?php

namespace App\Actions;

use App\ContentTypes\ContentTypeRegistry;
use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use App\Models\GeneratedContent;
use App\Services\Export\ContentDocumentRenderer;
use App\Services\Export\ContentExportDocument;
use App\Services\Export\ExportRenderLimiter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExportGeneratedContent
{
    public function __construct(
        private ContentTypeRegistry $contentTypes,
        private ContentDocumentRenderer $renderer,
        private ExportRenderLimiter $renderLimiter,
    ) {}

    public function handle(
        GeneratedContent $generatedContent,
        ?int $versionNumber,
        ContentExportFormat $format,
    ): ContentExportDocument {
        $versionQuery = $generatedContent->versions()->reorder();
        $version = $versionNumber === null
            ? $versionQuery->orderByDesc('version_number')->firstOrFail()
            : $versionQuery->where('version_number', $versionNumber)->firstOrFail();

        $content = is_array($version->content) ? $version->content : [];
        $definition = $this->contentTypes->all()[$generatedContent->content_type] ?? null;
        $presentationView = $definition?->presentationView();

        if ($presentationView !== null && ! view()->exists($presentationView)) {
            $presentationView = null;
        }

        $fallbackJson = json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $html = view('generated-content.export-document', [
            'content' => $content,
            'contentTypeLabel' => $definition?->label() ?? $generatedContent->content_type,
            'documentTitle' => $definition?->titleFromContent($content) ?: ($definition?->label() ?? 'Fictional document'),
            'fallbackJson' => $fallbackJson === false ? 'Stored structured content is unavailable.' : $fallbackJson,
            'presentationView' => $presentationView,
            'stylesheet' => $this->stylesheet(),
        ])->render();

        if (strlen($html) > 2_000_000 && $format !== ContentExportFormat::Html) {
            throw new ContentExportException(
                'This document is too large for the PDF or PNG exporter. Shorten its content and try again.',
                422,
            );
        }

        if ($format === ContentExportFormat::Html) {
            $body = $html;
        } else {
            $body = $this->renderLimiter->run(fn (): string => $this->renderer->render($html, $format));
        }

        $contentType = Str::limit(preg_replace('/[^a-z0-9-]+/', '-', Str::lower($generatedContent->content_type)) ?: 'content', 48, '');
        $contentUuid = is_string($generatedContent->uuid) && preg_match('/^[0-9a-f-]{36}$/i', $generatedContent->uuid) === 1
            ? Str::lower($generatedContent->uuid)
            : 'document';
        $filename = "fictional-internet-{$contentType}-{$contentUuid}-v{$version->version_number}.{$format->extension()}";

        return new ContentExportDocument(
            filename: $filename,
            mimeType: $format->mimeType(),
            body: $body,
        );
    }

    private function stylesheet(): string
    {
        $manifestPath = public_path('build/manifest.json');
        $manifest = File::exists($manifestPath) ? json_decode(File::get($manifestPath), true) : null;
        $asset = is_array($manifest) ? ($manifest['resources/css/app.css']['file'] ?? null) : null;
        $buildPath = realpath(public_path('build'));

        if (! is_string($asset) || ! str_starts_with($asset, 'assets/') || str_contains($asset, '..') || $buildPath === false) {
            throw new ContentExportException('Export styling is unavailable because application assets have not been built.');
        }

        $stylesheetPath = realpath($buildPath.DIRECTORY_SEPARATOR.$asset);

        if ($stylesheetPath === false || ! str_starts_with($stylesheetPath, $buildPath.DIRECTORY_SEPARATOR) || ! is_file($stylesheetPath)) {
            throw new ContentExportException('Export styling is unavailable because application assets have not been built.');
        }

        $stylesheet = File::get($stylesheetPath);

        if (preg_match('/<\/style|@import|url\s*\(/i', $stylesheet) === 1) {
            throw new ContentExportException('Export styling contains an unsupported external asset.');
        }

        return $stylesheet;
    }
}
