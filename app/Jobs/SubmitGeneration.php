<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\PixelforgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 45;

    public function __construct(
        public int $generationId
    ) {}

    public function handle(
        PixelforgeService $pixelforge,
        \App\Services\GenerationStateService $stateService
    ): void
    {
        $generation = Generation::findOrFail(
            $this->generationId
        );

        // Don't submit a generation that is already known
        // to the provider or has reached a final state.
        if ($generation->provider_generation_id ||
            in_array($generation->status, ['succeeded', 'failed'])) {
            return;
        }

        // Always construct the same payload for retries.
        $payload = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?? [],
            'metadata' => [
                'generation_id' => (string) $generation->id,
            ],
        ];

        try {
            $response = $pixelforge->createGeneration(
                $payload,
                $generation->idempotency_key
            );
        } catch (ConnectionException $e) {
            // The request might have reached Pixelforge.
            // Never assume it was rejected.
            $this->retryOrMarkUnknown(
                'connection_error',
                $e->getMessage(),
                5
            );
            return;
        }

        if ($response->status() === 202) {
            $data = $response->json();

            if (!is_array($data) ||
                !is_string($data['id'] ?? null)) {
                $this->retryOrMarkUnknown(
                    'invalid_provider_response',
                    'Accepted response has no valid generation ID.',
                    5
                );
                return;
            }

            // Synchronize using the full provider response.
            // Handles queued, processing, succeeded and failed.
            // Also handles an idempotent replay that has
            // already completed.
            $stateService->sync(
                $this->generationId,
                $data
            );

            return;
        }

        $code = $response->json('error.code')
            ?? 'http_' . $response->status();

        $message = $response->json('error.message')
            ?? 'Pixelforge request failed.';

        if (in_array($code, [
            'rate_limit_exceeded',
            'concurrency_limit_exceeded',
            'overloaded',
        ], true)) {
            $retryAfter = $response->header('Retry-After');
            $delay = is_numeric($retryAfter)
                ? max(1, min(120, (int) $retryAfter))
                : 5;

            $this->retryOrReject($code, $message, $delay);
            return;
        }

        if ($response->status() >= 500) {
            $this->retryOrMarkUnknown($code, $message, 10);
            return;
        }

        // Definite rejection: validation, content policy,
        // invalid API key, quota, or idempotency conflict.
        $this->markRejected($code, $message);
    }

    private function retryOrReject(
        string $code,
        string $message,
        int $delay
    ): void {
        if ($this->attempts() >= $this->tries) {
            $this->markRejected($code, $message);
            return;
        }

        $this->release($delay);
    }

    private function retryOrMarkUnknown(
        string $code,
        string $message,
        int $delay
    ): void {
        if ($this->attempts() >= $this->tries) {
            $this->updateUnresolved($code, $message);
            return;
        }

        $this->release($delay);
    }

    private function markRejected(
        string $code,
        string $message
    ): void {
        DB::transaction(function () use ($code, $message) {
            $record = Generation::lockForUpdate()
                ->findOrFail($this->generationId);

            if ($record->provider_generation_id ||
                in_array($record->status, ['succeeded', 'failed'])) {
                return;
            }

            $record->status = 'failed';
            $record->error_code = $code;
            $record->error_message = $message;
            $record->provider_cost_usd = '0';
            $record->actual_cost_usd = '0';
            $record->completed_at = now();
            $record->save();
        });
    }

    private function updateUnresolved(
        string $code,
        string $message
    ): void {
        DB::transaction(function () use ($code, $message) {
            $record = Generation::lockForUpdate()
                ->findOrFail($this->generationId);

            if ($record->provider_generation_id ||
                in_array($record->status, ['succeeded', 'failed'])) {
                return;
            }

            // An ambiguous network failure cannot safely
            // be treated as a free, failed generation.
            $record->status = 'submission_unknown';
            $record->error_code = $code;
            $record->error_message = $message;
            $record->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->updateUnresolved(
            'submission_job_failed',
            $exception?->getMessage() ?? 'Submission job failed.'
        );
    }
}
