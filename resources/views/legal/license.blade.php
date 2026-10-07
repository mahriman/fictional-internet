@extends('layouts.app')

@section('title', 'License · Fictional Internet')

@section('content')
    <section class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-3xl font-bold tracking-tight text-slate-950">License and source</h1>
            <p class="mt-3 text-slate-700">
                Fictional Internet is licensed under the GNU Affero General Public License version 3.0 only
                (<span class="font-medium">AGPL-3.0-only</span>).
            </p>
            <p class="mt-2 text-sm text-slate-600">Copyright © 2026 github.com/mahriman.</p>
        </div>

        <p class="text-slate-700">
            The license permits redistribution and modification under its terms and provides the software without warranty to the extent stated in the license.
        </p>

        <p>
            <a href="{{ route('license.text') }}" class="font-medium text-indigo-700 underline underline-offset-2 hover:text-indigo-900">Read the complete LICENSE</a>
        </p>

        <div id="source" class="space-y-2 rounded-lg border border-slate-200 bg-white p-4">
            <h2 class="font-semibold text-slate-950">Corresponding source</h2>
            <p class="text-sm text-slate-700">
                The canonical source repository is private during release-candidate testing and is planned to become public for the 1.0 release. Before deploying the network-accessible 1.0 release, the operator must ensure that the Corresponding Source for the running version is accessible as required by the license.
            </p>
            <p>
                <a href="https://github.com/mahriman/fictional-internet" class="font-medium text-indigo-700 underline underline-offset-2 hover:text-indigo-900">Canonical source repository</a>
            </p>
        </div>
    </section>
@endsection
