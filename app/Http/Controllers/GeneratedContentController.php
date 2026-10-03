<?php

namespace App\Http\Controllers;

use App\Actions\EditGeneratedContentVersion;
use App\Actions\GenerateAndPersistContent;
use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Http\Requests\EditGeneratedContentRequest;
use App\Http\Requests\GenerateContentRequest;
use App\Models\GeneratedContent;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use App\Services\OpenAI\OpenAiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GeneratedContentController extends Controller
{
    public function create(Project $project, ContentTypeRegistry $contentTypes): View
    {
        Gate::authorize('view', $project);

        return view('generated-content.create', [
            'project' => $project,
            'contentTypes' => $contentTypes->all(),
        ]);
    }

    public function store(
        GenerateContentRequest $request,
        Project $project,
        GenerateAndPersistContent $generateAndPersistContent,
    ): RedirectResponse {
        try {
            $result = $generateAndPersistContent->handle(
                $project,
                $request->validated('content_type'),
                $request->validated('prompt'),
            );
        } catch (OpenAiException|StructuredContentGenerationException) {
            return redirect()
                ->route('projects.generated-content.create', ['project' => $project])
                ->withInput($request->safe()->only(['content_type', 'prompt']))
                ->withErrors(['generation' => 'We could not generate content right now. Please try again.']);
        }

        return redirect()->route('projects.generated-content.show', [
            'project' => $project,
            'generatedContent' => $result->generatedContent,
        ]);
    }

    public function show(
        Project $project,
        GeneratedContent $generatedContent,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $versions = $this->versionHistory($generatedContent);
        $version = $versions->first();

        abort_if($version === null, 404);

        return $this->showVersionContent($project, $generatedContent, $version, $versions, $contentTypes);
    }

    public function showVersion(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $version = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $version->loadMissing('basedOnVersion:id,version_number');

        return $this->showVersionContent(
            $project,
            $generatedContent,
            $version,
            $this->versionHistory($generatedContent),
            $contentTypes,
        );
    }

    public function editVersion(
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        ContentTypeRegistry $contentTypes,
    ): View {
        Gate::authorize('view', $project);

        $version = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $definition = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $editingView = $definition?->editingView();

        abort_unless($editingView !== null && view()->exists($editingView), 404);

        return view('generated-content.edit', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'version' => $version,
            'structuredContent' => is_array($version->content) ? $version->content : [],
            'contentTypeLabel' => $definition->label(),
            'editingView' => $editingView,
        ]);
    }

    public function storeVersionEdit(
        EditGeneratedContentRequest $request,
        Project $project,
        GeneratedContent $generatedContent,
        int $versionNumber,
        EditGeneratedContentVersion $editVersion,
    ): RedirectResponse {
        $sourceVersion = $generatedContent->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $newVersion = $editVersion->handle($sourceVersion, $request->validated('content'));

        return redirect()
            ->route('projects.generated-content.versions.show', [
                'project' => $project,
                'generatedContent' => $generatedContent,
                'versionNumber' => $newVersion->version_number,
            ])
            ->with('status', 'Version '.$newVersion->version_number.' created from version '.$sourceVersion->version_number.'.');
    }

    /**
     * @return Collection<int, GeneratedContentVersion>
     */
    private function versionHistory(GeneratedContent $generatedContent): Collection
    {
        return $generatedContent->versions()
            ->with('basedOnVersion:id,version_number')
            ->reorder()
            ->orderByDesc('version_number')
            ->get();
    }

    /**
     * @param  Collection<int, GeneratedContentVersion>  $versions
     */
    private function showVersionContent(
        Project $project,
        GeneratedContent $generatedContent,
        GeneratedContentVersion $version,
        Collection $versions,
        ContentTypeRegistry $contentTypes,
    ): View {
        $contentType = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $structuredContent = is_array($version->content) ? $version->content : [];
        $presentationView = $contentType?->presentationView();
        $editingView = $contentType?->editingView();
        $latestVersion = $versions->first();

        return view('generated-content.show', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'version' => $version,
            'structuredContent' => $structuredContent,
            'contentTypeLabel' => $contentType?->label() ?? $generatedContent->content_type,
            'presentationView' => $presentationView !== null && view()->exists($presentationView)
                ? $presentationView
                : null,
            'editingView' => $editingView !== null && view()->exists($editingView)
                ? $editingView
                : null,
            'versionTitle' => $contentType?->titleFromContent($structuredContent),
            'versionHistory' => $versions,
            'isLatestVersion' => $latestVersion !== null && $latestVersion->is($version),
        ]);
    }
}
