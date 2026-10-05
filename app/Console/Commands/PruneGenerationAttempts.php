<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('generation-attempts:prune {--dry-run : Report eligible attempts without deleting them}')]
#[Description('Prune old operational generation attempts while preserving durable content and versions')]
class PruneGenerationAttempts extends Command
{
    public function handle(): int
    {
        $now = now();
        $windows = config('generation_attempts.retention_days');
        $rules = [
            'issued' => ['created_at', $now->copy()->subDays($windows['issued'])],
            'failed' => ['updated_at', $now->copy()->subDays($windows['failed'])],
            'completed' => ['completed_at', $now->copy()->subDays($windows['completed'])],
            'in_progress' => ['claimed_at', $now->copy()->subDays($windows['in_progress'])],
        ];
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach ($rules as $status => [$timestampColumn, $cutoff]) {
            $query = DB::table('generation_attempts')
                ->where('status', $status)
                ->whereNotNull($timestampColumn)
                ->where($timestampColumn, '<', $cutoff);
            $count = $query->count();

            if (! $dryRun && $count > 0) {
                $query->delete();
            }

            $this->line(($dryRun ? 'Eligible' : 'Pruned')." {$status} attempts: {$count}");
            $total += $count;
        }

        $this->info(($dryRun ? 'Eligible' : 'Pruned')." attempts total: {$total}");

        return self::SUCCESS;
    }
}
