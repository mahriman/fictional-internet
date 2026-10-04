<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectContextRequest;
use App\Models\Project;
use App\Models\ProjectContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectContextController extends Controller
{
    public function edit(Project $project): View
    {
        Gate::authorize('update', $project);
        $project->load('context');

        return view('projects.context.edit', ['project' => $project]);
    }

    public function update(UpdateProjectContextRequest $request, Project $project): RedirectResponse
    {
        $sections = array_replace(
            array_fill_keys(ProjectContext::SECTION_FIELDS, null),
            $request->validated('context'),
        );

        if (collect($sections)->every(static fn (?string $value): bool => blank($value))) {
            $project->context()->delete();

            return redirect()->route('projects.show', $project)->with('status', 'Project context cleared.');
        }

        $project->context()->updateOrCreate([], $sections);

        return redirect()->route('projects.show', $project)->with('status', 'Project context saved.');
    }
}
