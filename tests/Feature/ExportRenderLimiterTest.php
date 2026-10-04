<?php

use App\Exceptions\ContentExportException;
use App\Exceptions\ExportRenderCapacityException;
use App\Services\Export\ExportRenderLimiter;
use App\Services\Export\ExportRenderSlot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

test('two render slots can be acquired and a released slot can be reused', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $limiter = new ExportRenderLimiter(2, $directory);
    $first = null;
    $second = null;
    $reused = null;

    try {
        $first = $limiter->acquire();
        $second = $limiter->acquire();

        expect($first)->toBeInstanceOf(ExportRenderSlot::class)
            ->and($second)->toBeInstanceOf(ExportRenderSlot::class)
            ->and($limiter->acquire())->toBeNull();
        expect(fileperms($directory) & 0070)->toBe(0070)
            ->and(fileperms($directory.'/slot-1.lock') & 0060)->toBe(0060);

        $first->release();
        expect(is_file($directory.'/slot-1.lock'))->toBeTrue();
        $reused = $limiter->acquire();

        expect($reused)->toBeInstanceOf(ExportRenderSlot::class);
    } finally {
        $first?->release();
        $second?->release();
        $reused?->release();
        File::deleteDirectory($directory);
    }
});

test('a render slot is released after an exception', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $limiter = new ExportRenderLimiter(1, $directory);
    $slot = null;
    $reacquiredSlot = null;

    try {
        expect(fn () => $limiter->run(fn () => throw new RuntimeException('render failed')))
            ->toThrow(RuntimeException::class, 'render failed');

        $reacquiredSlot = $limiter->acquire();

        expect($reacquiredSlot)->toBeInstanceOf(ExportRenderSlot::class);
    } finally {
        $slot?->release();
        $reacquiredSlot?->release();
        File::deleteDirectory($directory);
    }
});

test('render slots are shared across independent php processes', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $autoload = base_path('vendor/autoload.php');
    $script = sprintf(
        'require %s; $limiter = new \\App\\Services\\Export\\ExportRenderLimiter(2, %s); $slot = $limiter->acquire(); if ($slot === null) { exit(2); } fwrite(STDOUT, "locked\\n"); fflush(STDOUT); sleep(30); $slot->release();',
        var_export($autoload, true),
        var_export($directory, true),
    );
    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $secondSlot = null;
    $process->setTimeout(10);
    $process->start();

    try {
        $childAcquiredSlot = $process->waitUntil(
            fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, 'locked'),
        );
        $limiter = new ExportRenderLimiter(2, $directory);
        $secondSlot = $limiter->acquire();

        expect($childAcquiredSlot)->toBeTrue()
            ->and($secondSlot)->toBeInstanceOf(ExportRenderSlot::class)
            ->and($limiter->acquire())->toBeNull();
    } finally {
        $secondSlot?->release();
        $process->stop(1);
        File::deleteDirectory($directory);
    }
});

test('invalid concurrency values safely use the two-slot default', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $invalidLimits = [0, -1, 2.5, '2.5', 'invalid', null, false];

    try {
        foreach ($invalidLimits as $index => $invalidLimit) {
            $limiter = new ExportRenderLimiter($invalidLimit, $directory.'/case-'.$index);
            $first = $limiter->acquire();
            $second = $limiter->acquire();

            try {
                expect($first)->toBeInstanceOf(ExportRenderSlot::class)
                    ->and($second)->toBeInstanceOf(ExportRenderSlot::class)
                    ->and($limiter->acquire())->toBeNull();
            } finally {
                $first?->release();
                $second?->release();
            }
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

test('the application binding does not cast an invalid configured limit', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    config([
        'exports.render_concurrency' => '1.5',
        'exports.render_lock_directory' => $directory,
    ]);
    app()->forgetInstance(ExportRenderLimiter::class);
    $limiter = app(ExportRenderLimiter::class);
    $first = null;
    $second = null;

    try {
        $first = $limiter->acquire();
        $second = $limiter->acquire();

        expect($first)->toBeInstanceOf(ExportRenderSlot::class)
            ->and($second)->toBeInstanceOf(ExportRenderSlot::class)
            ->and($limiter->acquire())->toBeNull();
    } finally {
        $first?->release();
        $second?->release();
        File::deleteDirectory($directory);
    }
});

test('lock permissions honor restrictive umask and preserve inherited setgid group access', function () {
    $parentDirectory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $lockDirectory = $parentDirectory.'/slots';
    mkdir($parentDirectory, 0770, true);
    chmod($parentDirectory, 02770);
    $limiter = new ExportRenderLimiter(2, $lockDirectory);
    $slot = null;
    $previousUmask = umask(0077);

    try {
        $slot = $limiter->acquire();
        expect($slot)->toBeInstanceOf(ExportRenderSlot::class)
            ->and(fileperms($lockDirectory) & 07000)->toBe(02000)
            ->and(fileperms($lockDirectory) & 0030)->toBe(0030)
            ->and(fileperms($lockDirectory.'/slot-1.lock') & 0060)->toBe(0060)
            ->and(filegroup($lockDirectory.'/slot-1.lock'))->toBe(filegroup($lockDirectory));
    } finally {
        umask($previousUmask);
        $slot?->release();
        File::deleteDirectory($parentDirectory);
    }
});

test('capacity exhaustion and lock infrastructure failure use distinct safe errors', function () {
    $directory = sys_get_temp_dir().'/fictional-internet-export-lock-'.bin2hex(random_bytes(8));
    $capacityLimiter = new ExportRenderLimiter(1, $directory.'/capacity');
    $slot = $capacityLimiter->acquire();
    $invalidLockPath = $directory.'/not-a-directory';
    file_put_contents($invalidLockPath, '');
    Log::spy();

    try {
        expect(fn () => $capacityLimiter->run(fn (): string => 'unreachable'))
            ->toThrow(ExportRenderCapacityException::class, 'Export capacity is temporarily busy.');
        expect(fn () => (new ExportRenderLimiter(1, $invalidLockPath))->acquire())
            ->toThrow(ContentExportException::class, 'Export rendering is temporarily unavailable.');

        Log::shouldHaveReceived('warning')->once()->with(
            'Export render capacity lock is unavailable.',
            Mockery::on(fn (array $context): bool => $context === ['failure_stage' => 'lock_directory_unavailable']),
        );
    } finally {
        $slot?->release();
        File::deleteDirectory($directory);
    }
});
