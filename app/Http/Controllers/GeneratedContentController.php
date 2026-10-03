<?php

namespace App\Http\Controllers;

use App\Actions\GenerateAndPersistContent;
use App\ContentTypes\ContentTypeRegistry;
use App\Exceptions\StructuredContentGenerationException;
use App\Http\Requests\GenerateContentRequest;
use App\Models\GeneratedContent;
use App\Models\Project;
use App\Services\OpenAI\OpenAiException;
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

        $version = $generatedContent->versions()->firstOrFail();
        $contentType = $contentTypes->all()[$generatedContent->content_type] ?? null;
        $presentationView = $contentType?->presentationView();

        return view('generated-content.show', [
            'project' => $project,
            'generatedContent' => $generatedContent,
            'version' => $version,
            'structuredContent' => is_array($version->content) ? $version->content : [],
            'contentTypeLabel' => $contentType?->label() ?? $generatedContent->content_type,
            'presentationView' => $presentationView !== null && view()->exists($presentationView)
                ? $presentationView
                : null,
        ]);
    }
}
