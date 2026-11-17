<?php

namespace App\Console\Commands;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverUnknownSubmissions extends Command
{
    protected $signature = 'pixelforge:recover-unknown';

    protected $description =
        'Safely retry submissions with unknown provider status';

    private const MAX_RECOVERY_ATTEMPTS = 3;

    public function handle(): int
    {
        $ids = Generation::query()
            ->where('status', 'submission_unknown')
            ->whereNull('provider_generation_id')
            ->where(
                'recovery_attempts',
                '<',
                self::MAX_RECOVERY_ATTEMPTS
            )
            ->where(function ($query) {
                $query->whereNull('last_recovery_at')
                    ->orWhere(
                        'last_recovery_at',
                        '<=',
                        now()->subMinutes(2)
                    );
            })
            ->orderBy('id')
            ->limit(10)
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $queued = DB::transaction(function () use ($id) {
                $generation = Generation::query()
                    ->lockForUpdate()
                    ->find($id);

                if (!$generation ||
                    $generation->status !== 'submission_unknown' ||
                    $generation->provider_generation_id !== null ||
                    $generation->recovery_attempts >=
                        self::MAX_RECOVERY_ATTEMPTS) {
                    return false;
                }

                // Prevent concurrent scheduler runs from
                // dispatching the same recovery immediately.
                if ($generation->last_recovery_at &&
                    \Illuminate\Support\Carbon::parse(
                        $generation->last_recovery_at
                    )->gt(now()->subMinutes(2))) {
                    return false;
                }

                $generation->recovery_attempts =
                    (int) $generation->recovery_attempts + 1;

                $generation->last_recovery_at = now();

                $generation->save();

                // Reuse the original submission job.
                // It reads the unchanged idempotency key
                // and original request from the database.
                SubmitGeneration::dispatch($generation->id)
                    ->afterCommit();

                return true;
            });

            if ($queued) {
                $dispatched++;
            }
        }

        $this->info(
            "Queued {$dispatched} unknown submission(s) for recovery."
        );

        return self::SUCCESS;
    }
}
