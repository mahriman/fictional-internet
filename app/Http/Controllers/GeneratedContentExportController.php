<?php

namespace App\Http\Controllers;

use App\Actions\ExportGeneratedContent;
use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use App\Models\GeneratedContent;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class GeneratedContentExportController extends Controller
{
    public function latest(
        Project $project,
        GeneratedContent $generatedContent,
        string $format,
        ExportGeneratedContent $export,
    ): Response {
        return $this->download($project, $generatedContent, null, $format, $export);
    }

    public function version(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        string $format,
        ExportGeneratedContent $export,
    ): Response {
        return $this->download($project, $generatedContent, $versionNumber, $format, $export);
    }

    private function download(
        Project $project,
        GeneratedContent $generatedContent,
        ?int $versionNumber,
        string $format,
        ExportGeneratedContent $export,
    ): Response {
        Gate::authorize('view', $project);
        $exportFormat = ContentExportFormat::tryFrom($format);
        abort_if($exportFormat === null, 404);

        try {
            $document = $export->handle($generatedContent, $versionNumber, $exportFormat);
        } catch (ContentExportException $exception) {
            return response()->view('generated-content.export-error', [
                'project' => $project,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document->body;
            },
            $document->filename,
            [
                'Content-Type' => $document->mimeType,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
