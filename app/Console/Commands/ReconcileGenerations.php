<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\GenerationStateService;
use App\Services\PixelforgeService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description =
        'Recover unfinished Pixelforge generations by polling';

    public function handle(
        PixelforgeService $pixelforge,
        GenerationStateService $stateService
    ): int {
        $generations = Generation::query()
            ->whereNotNull('provider_generation_id')
            ->whereNotIn('status', ['succeeded', 'failed'])
            ->where('updated_at', '<=', now()->subSeconds(30))
            ->orderBy('updated_at')
            ->limit(15)
            ->get();

        if ($generations->isEmpty()) {
            $this->info('No unfinished generations to reconcile.');
            return self::SUCCESS;
        }

        $errors = 0;

        foreach ($generations as $generation) {
            try {
                $response = $pixelforge->getGeneration(
                    $generation->provider_generation_id
                );

                if ($response->status() === 429) {
                    $this->warn('Provider rate limit reached.');
                    return self::FAILURE;
                }

                if (!$response->successful()) {
                    $this->warn(
                        "Generation {$generation->id}: HTTP " .
                        $response->status()
                    );

                    $errors++;
                    continue;
                }

                $data = $response->json();

                if (!is_array($data)) {
                    throw new \RuntimeException(
                        'Invalid provider response.'
                    );
                }

                $updated = $stateService->sync(
                    $generation->id,
                    $data
                );

                $this->info(
                    "Generation {$updated->id}: {$updated->status}"
                );
            } catch (Throwable $e) {
                report($e);

                $this->error(
                    "Generation {$generation->id}: reconciliation failed."
                );

                $errors++;
            }
        }

        return $errors > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
