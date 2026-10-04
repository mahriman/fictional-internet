<?php

use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use App\Services\Export\ContentDocumentRenderer;
use App\Services\Export\FirefoxWebDriverBiDiRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

test('the worker categorizes a missing WebDriver executable and removes its temporary profile', function () {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = sys_get_temp_dir().'/fictional-internet-export-test-'.Str::random(12);
    mkdir($temporaryRoot, 0700);
    $profilesBefore = glob($temporaryRoot.'/fictional-internet-export-*') ?: [];

    try {
        $process = runExportWorker($temporaryRoot, $temporaryRoot.'/missing-geckodriver');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe('EXPORT_RENDERER_FAILED:webdriver_launch_failure')
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe($profilesBefore);
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
});

test('the worker categorizes an unsuccessful WebDriver exit and removes its temporary profile', function () {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = sys_get_temp_dir().'/fictional-internet-export-test-'.Str::random(12);
    mkdir($temporaryRoot, 0700);
    $driverPath = $temporaryRoot.'/fake-geckodriver';
    file_put_contents($driverPath, "#!/bin/sh\nexit 9\n");
    chmod($driverPath, 0700);

    try {
        $process = runExportWorker($temporaryRoot, $driverPath);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe('EXPORT_RENDERER_FAILED:webdriver_exit_9')
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe([]);
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
});

test('a successful export survives failed session deletion when process cleanup succeeds', function (string $format, string $signature) {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = createFakeWebDriverRoot();

    try {
        $driverPath = writeFakeWebDriver($temporaryRoot);
        $process = runFakeWebDriverExport($temporaryRoot, $driverPath, $format);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toStartWith($signature)
            ->and($process->getErrorOutput())->toBe('EXPORT_RENDERER_CLEANUP_WARNING:webdriver_session_cleanup_failure')
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe([]);
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
})->with([
    'PDF' => ['pdf', '%PDF-'],
    'PNG' => ['png', "\x89PNG\r\n\x1a\n"],
]);

test('a primary render failure remains primary when session deletion also fails', function () {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = createFakeWebDriverRoot();

    try {
        $driverPath = writeFakeWebDriver($temporaryRoot, failRendering: true);
        $process = runFakeWebDriverExport($temporaryRoot, $driverPath, 'pdf');

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe("EXPORT_RENDERER_FAILED:pdf_render\nEXPORT_RENDERER_CLEANUP_FAILED:webdriver_session_cleanup_failure")
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe([]);
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
});

test('an already terminated WebDriver process group does not invalidate completed output', function () {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = createFakeWebDriverRoot();

    try {
        $driverPath = writeFakeWebDriver($temporaryRoot, terminateAfterRender: true);
        $process = runFakeWebDriverExport($temporaryRoot, $driverPath, 'pdf');

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toStartWith('%PDF-')
            ->and($process->getErrorOutput())->toBe('EXPORT_RENDERER_CLEANUP_WARNING:webdriver_session_cleanup_failure')
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe([]);
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
});

test('the worker escalates process-group cleanup and removes the isolated profile', function () {
    if (! workerSupportsWebSocket()) {
        test()->markTestSkipped('Node.js with native WebSocket support is required for worker lifecycle tests.');
    }

    $temporaryRoot = createFakeWebDriverRoot();
    $childPidPath = $temporaryRoot.'/child.pid';

    try {
        $driverPath = writeFakeWebDriver($temporaryRoot, ignoreTermChildPidPath: $childPidPath);
        $process = runFakeWebDriverExport($temporaryRoot, $driverPath, 'pdf');
        $childPid = (int) file_get_contents($childPidPath);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toStartWith('%PDF-')
            ->and(glob($temporaryRoot.'/fictional-internet-export-*') ?: [])->toBe([])
            ->and(waitForExportTestProcessToExit($childPid))->toBeTrue();
    } finally {
        File::deleteDirectory($temporaryRoot);
    }
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

test('the installed Firefox renderer exports a 55-message document completely to PDF and PNG', function () {
    if (! is_executable('/usr/bin/node') || ! is_executable('/usr/local/bin/geckodriver') || ! is_executable('/usr/local/bin/firefox')) {
        test()->markTestSkipped('Standalone Node.js, Firefox and geckodriver are required for the local renderer smoke test.');
    }

    $renderer = app(ContentDocumentRenderer::class);
    $messages = collect(range(1, 55))
        ->map(fn (int $number): string => '<article class="message"><header>Message #'.$number.' · Handle '.$number.'</header><p>Long discussion content with Unicode café — 朋友. This message remains in chronological order.</p></article>')
        ->implode('');
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0}body{width:960px;font-family:Arial,sans-serif}.long-document{padding:40px}.message{min-height:78px;padding:12px;border-bottom:1px solid #aaa}.last-marker{margin-top:24px}</style></head><body><main class="long-document"><h1>SchreckNet thread — 朋友</h1>'.$messages.'<p class="last-marker">The final message is included.</p></main></body></html>';
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

function workerSupportsWebSocket(): bool
{
    if (! is_executable('/usr/bin/node')) {
        return false;
    }

    $capability = new Process(['/usr/bin/node', '-p', 'typeof globalThis.WebSocket']);
    $capability->run();

    return $capability->isSuccessful() && trim($capability->getOutput()) === 'function';
}

function runExportWorker(string $temporaryRoot, string $geckoDriver): Process
{
    $process = new Process(
        ['/usr/bin/node', app_path('Services/Export/render-document.mjs')],
        base_path(),
        [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $temporaryRoot,
            'TMPDIR' => $temporaryRoot,
        ],
    );
    $process->setTimeout(10);
    $process->setInput(json_encode([
        'format' => 'pdf',
        'html' => '<!doctype html><html><body>Lifecycle test</body></html>',
        'geckodriver' => $geckoDriver,
        'firefox' => '/usr/local/bin/firefox',
    ], JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}

function createFakeWebDriverRoot(): string
{
    $temporaryRoot = sys_get_temp_dir().'/fictional-internet-export-test-'.Str::random(12);
    mkdir($temporaryRoot, 0700);

    return $temporaryRoot;
}

function writeFakeWebDriver(
    string $temporaryRoot,
    bool $failRendering = false,
    bool $terminateAfterRender = false,
    ?string $ignoreTermChildPidPath = null,
): string {
    $driverPath = $temporaryRoot.'/fake-geckodriver';
    $source = <<<'JS'
#!/usr/bin/node
import { createHash } from 'node:crypto';
import { spawn } from 'node:child_process';
import http from 'node:http';
import { writeFileSync } from 'node:fs';

const failRendering = __FAIL_RENDERING__;
const terminateAfterRender = __TERMINATE_AFTER_RENDER__;
const childPidPath = __CHILD_PID_PATH__;
const pdf = Buffer.from('%PDF-1.4\nLifecycle fixture', 'utf8');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/ZQAAAABJRU5ErkJggg==', 'base64');

if (childPidPath !== null) {
    const child = spawn(process.execPath, ['-e', 'process.on("SIGTERM", () => {}); setInterval(() => {}, 1000);'], { stdio: 'ignore' });
    writeFileSync(childPidPath, String(child.pid));
}

const portIndex = process.argv.indexOf('--port');
const port = Number(process.argv[portIndex + 1]);
const server = http.createServer((request, response) => {
    if (request.url === '/status') {
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end('{"value":{"ready":true}}');
        return;
    }

    if (request.method === 'POST' && request.url === '/session') {
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end(JSON.stringify({ value: { sessionId: 'fixture-session', capabilities: { webSocketUrl: `ws://127.0.0.1:${port}/bidi` } } }));
        return;
    }

    if (request.method === 'DELETE' && request.url === '/session/fixture-session') {
        response.writeHead(500, { 'content-type': 'application/json' });
        response.end('{"value":{"error":"unknown error"}}');
        return;
    }

    response.writeHead(404);
    response.end();
});

server.on('upgrade', (request, socket) => {
    const accept = createHash('sha1').update(`${request.headers['sec-websocket-key']}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`).digest('base64');
    socket.write(`HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: ${accept}\r\n\r\n`);
    let buffered = Buffer.alloc(0);

    socket.on('data', (chunk) => {
        buffered = Buffer.concat([buffered, chunk]);

        while (buffered.length >= 2) {
            let length = buffered[1] & 0x7f;
            let offset = 2;

            if (length === 126) {
                if (buffered.length < 4) return;
                length = buffered.readUInt16BE(2);
                offset = 4;
            }

            const masked = (buffered[1] & 0x80) !== 0;
            const maskOffset = offset;
            if (masked) offset += 4;
            if (buffered.length < offset + length) return;

            let payload = buffered.subarray(offset, offset + length);
            if (masked) {
                const mask = buffered.subarray(maskOffset, maskOffset + 4);
                payload = Buffer.from(payload.map((byte, index) => byte ^ mask[index % 4]));
            }
            buffered = buffered.subarray(offset + length);

            let command;
            try {
                command = JSON.parse(payload.toString('utf8'));
            } catch {
                continue;
            }

            if (command.method === 'browsingContext.print' && failRendering) {
                socket.write(websocketFrame(JSON.stringify({ type: 'error', id: command.id, error: 'unknown error', message: 'fixture render failure' })));
                continue;
            }

            const result = command.method === 'browsingContext.create'
                ? { context: 'fixture-context' }
                : command.method === 'script.evaluate'
                    ? { result: { type: 'string', value: JSON.stringify({ width: 1, height: 1 }) } }
                    : command.method === 'browsingContext.print'
                        ? { data: pdf.toString('base64') }
                        : command.method === 'browsingContext.captureScreenshot'
                            ? { data: png.toString('base64') }
                            : {};
            const frame = websocketFrame(JSON.stringify({ type: 'success', id: command.id, result }));

            if (terminateAfterRender && command.method === 'browsingContext.print') {
                socket.write(frame, () => process.exit(0));
            } else {
                socket.write(frame);
            }
        }
    });
});

function websocketFrame(value) {
    const payload = Buffer.from(value);
    if (payload.length < 126) {
        return Buffer.concat([Buffer.from([0x81, payload.length]), payload]);
    }

    const header = Buffer.alloc(4);
    header[0] = 0x81;
    header[1] = 126;
    header.writeUInt16BE(payload.length, 2);
    return Buffer.concat([header, payload]);
}

server.listen(port, '127.0.0.1');
JS;
    $source = str_replace(
        ['__FAIL_RENDERING__', '__TERMINATE_AFTER_RENDER__', '__CHILD_PID_PATH__'],
        [
            $failRendering ? 'true' : 'false',
            $terminateAfterRender ? 'true' : 'false',
            $ignoreTermChildPidPath === null ? 'null' : json_encode($ignoreTermChildPidPath, JSON_THROW_ON_ERROR),
        ],
        $source,
    );
    file_put_contents($driverPath, $source);
    chmod($driverPath, 0700);

    return $driverPath;
}

function runFakeWebDriverExport(string $temporaryRoot, string $driverPath, string $format): Process
{
    $process = new Process(
        ['/usr/bin/node', app_path('Services/Export/render-document.mjs')],
        base_path(),
        [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $temporaryRoot,
            'TMPDIR' => $temporaryRoot,
        ],
    );
    $process->setTimeout(12);
    $process->setInput(json_encode([
        'format' => $format,
        'html' => '<!doctype html><html><body>Lifecycle fixture</body></html>',
        'geckodriver' => $driverPath,
        'firefox' => '/usr/local/bin/firefox',
    ], JSON_THROW_ON_ERROR));
    $process->run();

    return $process;
}

function waitForExportTestProcessToExit(int $processId): bool
{
    if (! function_exists('posix_kill')) {
        return true;
    }

    $deadline = microtime(true) + 3;

    do {
        if (! @posix_kill($processId, 0)) {
            return true;
        }

        usleep(50_000);
    } while (microtime(true) < $deadline);

    return false;
}
