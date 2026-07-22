<?php

namespace App\Console\Commands;

use App\Models\MemorizationAttempt;
use Illuminate\Console\Command;

/**
 * Removes raw and normalized transcript text from old attempts while keeping
 * scores, verdicts, and reports. Enforces the transcript retention policy.
 */
class PruneAttemptTranscripts extends Command
{
    protected $signature = 'attempts:prune-transcripts';

    protected $description = 'Prune raw transcript text from attempts older than the configured retention window.';

    public function handle(): int
    {
        $days = config('athar.attempts.transcript_retention_days');

        if (empty($days)) {
            $this->info('Transcript retention is disabled; nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);

        $count = MemorizationAttempt::where('created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('recognized_text')
                    ->orWhereNotNull('normalized_recognized_text');
            })
            ->update([
                'recognized_text' => '',
                'normalized_recognized_text' => '',
                'normalized_reference_text' => '',
            ]);

        $this->info("Pruned transcript text from {$count} attempt(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
