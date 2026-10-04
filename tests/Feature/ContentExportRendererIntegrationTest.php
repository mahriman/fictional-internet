<?php

use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use App\Services\Export\ContentDocumentRenderer;
use App\Services\Export\FirefoxWebDriverBiDiRenderer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

test('the renderer uses standalone system executables and does not search snap locations', function () {
    $renderer = new FirefoxWebDriverBiDiRenderer;
    $findExecutable = new ReflectionMethod(FirefoxWebDriverBiDiRenderer::class, 'findExecutable');

    expect($findExecutable->invoke($renderer, 'node'))->toBe('/usr/bin/node')
        ->and($findExecutable->invoke($renderer, 'geckodriver'))->toBe('/usr/local/bin/geckodriver')
        ->and($findExecutable->invoke($renderer, 'firefox', ['/usr/local/bin']))->toBe('/usr/local/bin/firefox');
});

test('the worker rejects snap executable paths before starting a subprocess', function () {
    $process = new Process(
        ['/usr/bin/node', app_path('Services/Export/render-document.mjs')],
        base_path(),
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => '/tmp', 'TMPDIR' => '/tmp'],
    );
    $process->setInput(json_encode([
        'format' => 'pdf',
        'html' => '<!doctype html><html></html>',
        'geckodriver' => '/snap/bin/geckodriver',
        'firefox' => '/usr/local/bin/firefox',
    ], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toBe('EXPORT_RENDERER_FAILED:unsupported_snap_executable');
});

test('the renderer logs a safe diagnostic when Node lacks native WebSocket support', function () {
    if (! is_executable('/usr/bin/node')) {
        test()->markTestSkipped('The system Node.js executable is unavailable.');
    }

    $capability = new Process(['/usr/bin/node', '-p', 'typeof globalThis.WebSocket']);
    $capability->run();

    if (! $capability->isSuccessful() || trim($capability->getOutput()) !== 'undefined') {
        test()->markTestSkipped('The system Node.js runtime already provides WebSocket.');
    }

    Log::spy();

    $renderer = new FirefoxWebDriverBiDiRenderer;

    expect(fn () => $renderer->render('<!doctype html><html></html>', ContentExportFormat::Pdf))
        ->toThrow(ContentExportException::class, 'The document could not be rendered.');

    Log::shouldHaveReceived('warning')->once()->with(
        'Content export renderer returned an unsuccessful result.',
        Mockery::on(fn (array $context): bool => $context === [
            'failure_stage' => 'node_websocket_api_unavailable',
            'exit_code' => 1,
            'format' => 'pdf',
        ]),
    );
});

test('the installed Firefox renderer creates a valid multipage PDF and complete document PNG', function () {
    if (! is_executable('/usr/bin/node') || ! is_executable('/usr/local/bin/geckodriver') || ! is_executable('/usr/local/bin/firefox')) {
        test()->markTestSkipped('Standalone Node.js, Firefox and geckodriver are required for the local renderer smoke test.');
    }

    $renderer = app(ContentDocumentRenderer::class);
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:960px;font-family:Arial,sans-serif}.long-document{height:2200px;padding:40px}.last-marker{position:absolute;top:2100px}</style></head><body><main class="long-document"><h1>Fictional export café — 朋友</h1><p>Document content beyond the initial viewport.</p><p class="last-marker">The final section is included.</p></main></body></html>';
    $temporaryDirectoriesBeforeRender = glob(sys_get_temp_dir().'/fictional-internet-export-*') ?: [];

    $pdf = $renderer->render($html, ContentExportFormat::Pdf);
    $png = $renderer->render($html, ContentExportFormat::Png);
    $image = getimagesizefromstring($png);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000)
        ->and($png)->toStartWith("\x89PNG\r\n\x1a\n")
        ->and($image)->not->toBeFalse()
        ->and($image[0])->toBe(960)
        ->and($image[1])->toBeGreaterThan(768)
        ->and(glob(sys_get_temp_dir().'/fictional-internet-export-*') ?: [])->toBe($temporaryDirectoriesBeforeRender);

    $pdfInfo = is_executable('/usr/bin/pdfinfo') ? '/usr/bin/pdfinfo' : null;

    if ($pdfInfo !== null) {
        $metadata = new Process([$pdfInfo, '-']);
        $metadata->setInput($pdf);
        $metadata->mustRun();

        expect($metadata->getOutput())->toMatch('/Pages:\s+(?:[2-9]|[1-9][0-9]+)/');
    }
});

test('the Firefox renderer clearly rejects a PNG larger than its safe full-document limit', function () {
    if (! is_executable('/usr/bin/node') || ! is_executable('/usr/local/bin/geckodriver') || ! is_executable('/usr/local/bin/firefox')) {
        test()->markTestSkipped('Standalone Node.js, Firefox and geckodriver are required for the local renderer smoke test.');
    }

    $renderer = app(ContentDocumentRenderer::class);
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:960px;height:9000px}</style></head><body>Complete document</body></html>';

    expect(fn () => $renderer->render($html, ContentExportFormat::Png))
        ->toThrow(ContentExportException::class, 'too tall to export as one PNG');
});
