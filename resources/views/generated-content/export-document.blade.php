<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src 'none'; connect-src 'none'; script-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'">
    <meta name="referrer" content="no-referrer">
    <title>{{ $documentTitle }}</title>
    <style>{!! $stylesheet !!}
        @page {
            size: A4 portrait;
            margin: 14mm 16mm;
        }

        html,
        body {
            box-sizing: border-box;
            margin: 0;
            min-width: 960px;
            background: #ffffff;
            color: #0f172a;
            font-family: Arial, Helvetica, sans-serif;
            print-color-adjust: exact;
            -webkit-print-color-adjust: exact;
        }

        *,
        *::before,
        *::after {
            box-sizing: inherit;
        }

        body {
            width: 960px;
            min-width: 960px;
        }

        .export-document {
            width: 960px;
            min-height: 100vh;
            margin: 0 auto;
            background: #ffffff;
        }

        .export-document article {
            break-inside: auto;
        }

        .export-document ol > li {
            break-inside: avoid-page;
            page-break-inside: avoid;
        }

        @media print {
            html,
            body,
            .export-document {
                width: 960px;
                min-width: 960px;
            }
        }
    </style>
</head>
<body>
    <main class="export-document" aria-label="{{ $contentTypeLabel }}">
        @if ($presentationView !== null)
            @include($presentationView, ['content' => $content])
        @else
            <article class="p-8">
                <p class="text-sm font-semibold uppercase tracking-wide text-slate-600">{{ $contentTypeLabel }}</p>
                <h1 class="mt-3 break-words text-3xl font-bold text-slate-950">{{ $documentTitle }}</h1>
                <pre class="mt-6 whitespace-pre-wrap break-words font-mono text-sm leading-6 text-slate-800">{{ $fallbackJson }}</pre>
            </article>
        @endif
    </main>
</body>
</html>
