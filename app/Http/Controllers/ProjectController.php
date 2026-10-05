<?php

namespace App\Http\Controllers;

use App\ContentTypes\ContentTypeRegistry;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\GeneratedContentVersion;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Project::class);

        $projects = $request->user()->projects()->latest('updated_at')->get();

        return view('projects.index', ['projects' => $projects]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        Gate::authorize('create', Project::class);

        return view('projects.create', ['project' => null]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $project = $request->user()->projects()->create($request->validated());

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Project $project, ContentTypeRegistry $contentTypes): View
    {
        Gate::authorize('view', $project);
        $project->load('context');

        $registeredContentTypes = $contentTypes->all();
        $searchInput = $request->query('search');
        $searchError = null;
        $searchTerm = '';

        if ($searchInput !== null && ! is_string($searchInput)) {
            $searchError = 'Enter a title search as text.';
        } else {
            $searchTerm = trim($searchInput ?? '');

            if (mb_strlen($searchTerm) > 255) {
                $searchError = 'Title search must be 255 characters or fewer.';
            }
        }

        $contentTypeInput = $request->query('content_type');
        $contentTypeFilter = null;
        $contentTypeError = null;

        if ($contentTypeInput !== null && $contentTypeInput !== '') {
            if (is_string($contentTypeInput) && array_key_exists($contentTypeInput, $registeredContentTypes)) {
                $contentTypeFilter = $contentTypeInput;
            } else {
                $contentTypeError = 'Choose a registered content type or clear the filters.';
            }
        }

        $generatedContentsQuery = $project->generatedContents()
            ->select([
                'generated_contents.id',
                'generated_contents.uuid',
                'generated_contents.project_id',
                'generated_contents.content_type',
                'generated_contents.title',
                'generated_contents.created_at',
                'generated_contents.updated_at',
            ])
            ->addSelect([
                'latest_version_number' => GeneratedContentVersion::query()
                    ->select('version_number')
                    ->whereColumn('generated_content_id', 'generated_contents.id')
                    ->orderByDesc('version_number')
                    ->limit(1),
                'latest_version_activity_at' => GeneratedContentVersion::query()
                    ->select('created_at')
                    ->whereColumn('generated_content_id', 'generated_contents.id')
                    ->orderByDesc('version_number')
                    ->limit(1),
            ])
            ->withCasts(['latest_version_activity_at' => 'datetime']);

        if ($searchError !== null || $contentTypeError !== null) {
            $generatedContentsQuery->whereRaw('1 = 0');
        } else {
            if ($searchTerm !== '') {
                $generatedContentsQuery->where('title', 'like', '%'.$searchTerm.'%');
            }

            if ($contentTypeFilter !== null) {
                $generatedContentsQuery->where('content_type', $contentTypeFilter);
            }
        }

        $generatedContents = $generatedContentsQuery
            ->orderByDesc('latest_version_activity_at')
            ->orderByDesc('generated_contents.created_at')
            ->orderByDesc('generated_contents.id')
            ->paginate(20)
            ->withQueryString();

        return view('projects.show', [
            'project' => $project,
            'generatedContents' => $generatedContents,
            'contentTypes' => $registeredContentTypes,
            'hasAnyGeneratedContent' => $project->generatedContents()->exists(),
            'searchTerm' => $searchTerm,
            'searchError' => $searchError,
            'contentTypeFilter' => $contentTypeFilter,
            'contentTypeError' => $contentTypeError,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', ['project' => $project]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Project deleted.');
    }
}
