<?php

namespace App\Services\Export;

use App\Exceptions\ContentExportException;
use App\Exceptions\ExportRenderCapacityException;
use Illuminate\Support\Facades\Log;

class ExportRenderLimiter
{
    private const DEFAULT_CONCURRENCY_LIMIT = 2;

    private readonly int $concurrencyLimit;

    public function __construct(
        mixed $concurrencyLimit,
        private readonly string $lockDirectory,
    ) {
        if (! is_int($concurrencyLimit) || $concurrencyLimit < 1) {
            Log::warning('Invalid export render concurrency configuration; using the safe default.', [
                'failure_stage' => 'invalid_concurrency_configuration',
            ]);
            $this->concurrencyLimit = self::DEFAULT_CONCURRENCY_LIMIT;

            return;
        }

        $this->concurrencyLimit = $concurrencyLimit;
    }

    public function acquire(): ?ExportRenderSlot
    {
        if ($this->concurrencyLimit < 1 || ! $this->ensureLockDirectory()) {
            $this->unavailable('lock_directory_unavailable');
        }

        for ($slotNumber = 1; $slotNumber <= $this->concurrencyLimit; $slotNumber++) {
            $lockPath = $this->lockDirectory.'/slot-'.$slotNumber.'.lock';
            $previousUmask = umask(0007);

            try {
                $handle = @fopen($lockPath, 'c');
            } finally {
                umask($previousUmask);
            }

            if (! is_resource($handle)) {
                $this->unavailable('lock_file_open_failed');
            }

            $permissions = @fileperms($lockPath);

            if ($permissions === false || (($permissions & 0020) !== 0020 && ! @chmod($lockPath, 0660))) {
                fclose($handle);
                $this->unavailable('lock_file_permissions_failed');
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new ExportRenderSlot($handle);
            }

            fclose($handle);
        }

        return null;
    }

    public function run(callable $render): mixed
    {
        $slot = $this->acquire();

        if ($slot === null) {
            throw new ExportRenderCapacityException;
        }

        try {
            return $render();
        } finally {
            $slot->release();
        }
    }

    private function ensureLockDirectory(): bool
    {
        if (! is_dir($this->lockDirectory) && ! @mkdir($this->lockDirectory, 0770, true) && ! is_dir($this->lockDirectory)) {
            return false;
        }

        $permissions = @fileperms($this->lockDirectory);

        if ($permissions === false) {
            return false;
        }

        $directoryMode = ($permissions & 07000) | 0770;

        if (($permissions & 0030) !== 0030 && ! @chmod($this->lockDirectory, $directoryMode)) {
            return false;
        }

        return is_writable($this->lockDirectory);
    }

    private function unavailable(string $failureStage): never
    {
        Log::warning('Export render capacity lock is unavailable.', [
            'failure_stage' => $failureStage,
        ]);

        throw new ContentExportException('Export rendering is temporarily unavailable. Please try again shortly.');
    }
}
