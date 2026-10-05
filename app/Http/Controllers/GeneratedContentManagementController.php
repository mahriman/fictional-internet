<?php

namespace App\Http\Controllers;

use App\Http\Requests\RenameGeneratedContentRequest;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GeneratedContentManagementController extends Controller
{
    public function update(
        RenameGeneratedContentRequest $request,
        Project $project,
        GeneratedContent $generatedContent,
    ): RedirectResponse {
        $generatedContent->update(['title' => $request->validated('title')]);

        return $this->redirectToSelectedVersion($request, $project, $generatedContent)
            ->with('status', 'Artifact title updated. Version titles are unchanged.');
    }

    public function destroy(Request $request, Project $project, GeneratedContent $generatedContent): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $generatedContent->delete();

        return redirect()->route('projects.show', $project)
            ->with('status', 'Generated content and its complete version history were deleted.');
    }

    private function redirectToSelectedVersion(
        Request $request,
        Project $project,
        GeneratedContent $generatedContent,
    ): RedirectResponse {
        $versionNumber = filter_var($request->query('version'), FILTER_VALIDATE_INT);
        $versionExists = is_int($versionNumber)
            && $versionNumber > 0
            && GeneratedContentVersion::query()
                ->where('generated_content_id', $generatedContent->getKey())
                ->where('version_number', $versionNumber)
                ->exists();

        if ($versionExists) {
            return redirect()->route('projects.generated-content.versions.show', [
                'project' => $project,
                'generatedContent' => $generatedContent,
                'versionNumber' => $versionNumber,
            ]);
        }

        return redirect()->route('projects.generated-content.show', [
            'project' => $project,
            'generatedContent' => $generatedContent,
        ]);
    }
}
