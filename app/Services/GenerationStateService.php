<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerationStateService
{
    public function __construct(
        private GenerationPricingService $calculator
    ) {}

    public function sync(
        int $localGenerationId,
        array $data
    ): Generation {
        $providerId = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $metadataId = $data['metadata']['generation_id'] ?? null;

        if (($data['object'] ?? null) !== 'generation' ||
            !is_string($providerId) ||
            !in_array($status, [
                'queued', 'processing', 'succeeded', 'failed',
            ], true)) {
            throw new InvalidArgumentException(
                'Invalid Pixelforge generation response.'
            );
        }

        if ((string) $metadataId !== (string) $localGenerationId) {
            throw new InvalidArgumentException(
                'Generation metadata does not match.'
            );
        }

        return DB::transaction(function () use (
            $localGenerationId,
            $providerId,
            $status,
            $data
        ) {
            $record = Generation::query()
                ->lockForUpdate()
                ->findOrFail($localGenerationId);

            if ($record->provider_generation_id !== null &&
                $record->provider_generation_id !== $providerId) {
                throw new InvalidArgumentException(
                    'Provider generation ID mismatch.'
                );
            }

            // Terminal states must never be overwritten,
            // even by a duplicate or older webhook.
            if (in_array(
                $record->status,
                ['succeeded', 'failed'],
                true
            )) {
                return $record;
            }

            $record->provider_generation_id = $providerId;

            if (is_array($data['rates'] ?? null)) {
                $record->rates = $data['rates'];
            }

            if (!empty($data['started_at'])) {
                $record->started_at = Carbon::parse(
                    $data['started_at']
                );
            }

            if ($status === 'queued') {
                // Don't move processing backward to queued.
                if ($record->status !== 'processing') {
                    $record->status = 'queued';
                }
            }

            if ($status === 'processing') {
                $record->status = 'processing';
            }

            if ($status === 'succeeded') {
                if (!is_array($data['usage'] ?? null) ||
                    !is_array($data['rates'] ?? null) ||
                    !is_string($data['image_url'] ?? null) ||
                    empty($data['completed_at'])) {
                    throw new InvalidArgumentException(
                        'Successful generation is missing required data.'
                    );
                }

                $cost = $this->calculator->actualCost(
                    $data['usage'],
                    $data['rates']
                );

                $record->status = 'succeeded';
                $record->usage = $data['usage'];
                $record->rates = $data['rates'];

                $record->provider_cost_usd =
                    $cost['provider_cost_usd'];

                $record->actual_cost_usd =
                    $cost['user_cost_usd'];

                $record->image_url = $data['image_url'];

                $record->image_url_expires_at =
                    !empty($data['image_url_expires_at'])
                        ? Carbon::parse($data['image_url_expires_at'])
                        : null;

                $record->completed_at = Carbon::parse(
                    $data['completed_at']
                );

                $record->error_code = null;
                $record->error_message = null;
            }

            if ($status === 'failed') {
                if (empty($data['completed_at'])) {
                    throw new InvalidArgumentException(
                        'Failed generation has no completion time.'
                    );
                }

                $record->status = 'failed';
                $record->provider_cost_usd = '0';
                $record->actual_cost_usd = '0';

                $record->error_code =
                    $data['error']['code'] ?? 'generation_failed';

                $record->error_message =
                    $data['error']['message'] ??
                    'Image generation failed.';

                $record->completed_at = Carbon::parse(
                    $data['completed_at']
                );
            }

            $record->save();

            return $record;
        });
    }
}
