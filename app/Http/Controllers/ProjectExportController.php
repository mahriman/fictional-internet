<?php

namespace App\Http\Controllers;

use App\Actions\ExportProject;
use App\Exceptions\ProjectExportException;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ProjectExportController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Project $project, ExportProject $export): Response
    {
        Gate::authorize('view', $project);

        try {
            $archive = $export->handle($project);
        } catch (ProjectExportException $exception) {
            return response()->view('projects.export-error', [
                'project' => $project,
                'message' => $exception->getMessage(),
            ], 500);
        }

        return response()->streamDownload(
            static function () use ($archive): void {
                echo $archive->body;
            },
            $archive->filename,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
