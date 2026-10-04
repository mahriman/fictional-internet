<?php

namespace App\Services\Export;

use App\Enums\ContentExportFormat;
use App\Exceptions\ContentExportException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class FirefoxWebDriverBiDiRenderer implements ContentDocumentRenderer
{
    private const EXECUTABLE_DIRECTORIES = ['/usr/local/bin', '/usr/bin', '/bin'];

    public function render(string $html, ContentExportFormat $format): string
    {
        if (! in_array($format, [ContentExportFormat::Pdf, ContentExportFormat::Png], true)) {
            throw new ContentExportException('The requested export format is not supported.', 422);
        }

        $node = $this->findExecutable('node');
        $geckoDriver = $this->findExecutable('geckodriver');
        $firefox = $this->findExecutable('firefox', ['/usr/local/bin']);
        $script = app_path('Services/Export/render-document.mjs');

        if ($node === null || $geckoDriver === null || $firefox === null || ! is_file($script)) {
            throw new ContentExportException('PDF and PNG export requires Node.js, Firefox and geckodriver on the application server.');
        }

        $environment = array_fill_keys(array_keys(getenv()), false);
        $environment['PATH'] = implode(PATH_SEPARATOR, self::EXECUTABLE_DIRECTORIES);
        $environment['HOME'] = sys_get_temp_dir();
        $environment['TMPDIR'] = sys_get_temp_dir();

        $process = new Process([$node, $script], base_path(), $environment);
        try {
            $process->setTimeout(60);
            $process->setInput(json_encode([
                'format' => $format->value,
                'html' => $html,
                'geckodriver' => $geckoDriver,
                'firefox' => $firefox,
            ], JSON_THROW_ON_ERROR));
            $process->run();
        } catch (Throwable $exception) {
            Log::warning('Content export renderer process failed.', [
                'failure_stage' => $exception instanceof ProcessTimedOutException ? 'php_process_timeout' : 'process_launch_failure',
                'exception' => $exception::class,
                'format' => $format->value,
            ]);

            throw new ContentExportException('The document could not be rendered. Please try the export again.');
        }

        if ($process->getExitCode() === 42) {
            throw new ContentExportException(
                'This document is too tall to export as one PNG. Download a PDF to preserve the complete document.',
                422,
            );
        }

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            $failureStage = preg_match('/\AEXPORT_RENDERER_FAILED:([a-z0-9_]+)\z/', $errorOutput, $matches) === 1
                ? $matches[1]
                : 'unknown_renderer_failure';

            Log::warning('Content export renderer returned an unsuccessful result.', [
                'failure_stage' => $failureStage,
                'exit_code' => $process->getExitCode(),
                'format' => $format->value,
            ]);

            throw new ContentExportException('The document could not be rendered. Please try the export again.');
        }

        $body = $process->getOutput();
        $signature = $format === ContentExportFormat::Pdf ? '%PDF-' : "\x89PNG\r\n\x1a\n";

        if (strlen($body) > 64_000_000 || ! str_starts_with($body, $signature)) {
            throw new ContentExportException('The renderer returned an invalid document. Please try the export again.');
        }

        if ($format === ContentExportFormat::Png) {
            $image = getimagesizefromstring($body);

            if ($image === false || ($image['mime'] ?? null) !== 'image/png' || $image[0] * $image[1] > 8_000_000) {
                throw new ContentExportException('The renderer returned an invalid image. Please try the export again.');
            }
        }

        return $body;
    }

    /**
     * @param  array<int, string>|null  $directories
     */
    private function findExecutable(string $name, ?array $directories = null): ?string
    {
        foreach ($directories ?? self::EXECUTABLE_DIRECTORIES as $directory) {
            $path = $directory.'/'.$name;
            $resolvedPath = realpath($path);

            if (is_file($path) && is_executable($path) && $resolvedPath !== false && ! str_starts_with($resolvedPath, '/snap/')) {
                return $path;
            }
        }

        return null;
    }
}
