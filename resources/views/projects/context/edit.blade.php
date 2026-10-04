@extends('layouts.app')

@section('title', 'Project context · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-3xl">
        <a href="{{ route('projects.show', $project) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">← Back to project</a>
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ $project->name }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Project context</h1>
            <p class="mt-2 text-sm leading-6 text-slate-600">Keep reusable facts about this fictional world here. These notes guide future generations as reference material; they are separate from the project description.</p>

            @error('context')
                <p role="alert" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800">{{ $message }}</p>
            @enderror

            @php($contextSections = [
                'setting' => ['label' => 'Setting', 'description' => 'The overall fictional world, its tone, and how it works.'],
                'time_period' => ['label' => 'Time period', 'description' => 'The timeframe, chronology, and important dates.'],
                'locations' => ['label' => 'Locations', 'description' => 'Geography, neighborhoods, landmarks, and relevant places.'],
                'people' => ['label' => 'People', 'description' => 'Recurring people or characters and details that should stay consistent.'],
                'organizations' => ['label' => 'Organizations', 'description' => 'Groups, institutions, businesses, and how they relate to the setting.'],
                'canon_notes' => ['label' => 'Canon notes', 'description' => 'Other established facts, constraints, and continuity rules.'],
            ])

            <form method="POST" action="{{ route('projects.context.update', $project) }}" class="mt-8 space-y-6">
                @csrf
                @method('PUT')

                @foreach ($contextSections as $field => $section)
                    @php($submittedValue = old('context.'.$field, $project->context?->{$field} ?? ''))
                    <div>
                        <label for="context-{{ $field }}" class="block text-sm font-medium text-slate-700">{{ $section['label'] }}</label>
                        <p id="context-{{ $field }}-help" class="mt-1 text-xs leading-5 text-slate-500">{{ $section['description'] }}</p>
                        <textarea id="context-{{ $field }}" name="context[{{ $field }}]" rows="4" maxlength="{{ \App\Http\Requests\UpdateProjectContextRequest::MAX_SECTION_LENGTH }}" aria-describedby="context-{{ $field }}-help" aria-invalid="{{ $errors->has('context.'.$field) ? 'true' : 'false' }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-slate-950 shadow-sm focus:border-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-200">{{ is_string($submittedValue) ? $submittedValue : '' }}</textarea>
                        @error('context.'.$field)
                            <p class="mt-2 text-sm font-medium text-rose-700">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                <p class="text-xs leading-5 text-slate-500">Each section supports up to {{ number_format(\App\Http\Requests\UpdateProjectContextRequest::MAX_SECTION_LENGTH) }} characters, with up to {{ number_format(\App\Http\Requests\UpdateProjectContextRequest::MAX_TOTAL_LENGTH) }} characters combined. Leave a section blank to clear it; saving all sections blank removes the context record.</p>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
                    <a href="{{ route('projects.show', $project) }}" class="rounded-lg border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Cancel</a>
                    <button type="submit" class="rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-700">Save context</button>
                </div>
            </form>
        </div>
    </section>
@endsection
