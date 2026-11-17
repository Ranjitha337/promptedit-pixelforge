<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        GenerationStateService $stateService
    ): Response {
        $secret = config('services.pixelforge.webhook_secret');

        if (!is_string($secret) || $secret === '') {
            abort(503, 'Webhook secret is not configured.');
        }

        // Verify the signature using the raw request body.
        $payload = $request->getContent();
        $parts = [];

        foreach (explode(',', (string) $request->header(
            'Pixelforge-Signature'
        )) as $part) {
            [$key, $value] = array_pad(
                explode('=', trim($part), 2),
                2,
                ''
            );

            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (!ctype_digit($timestamp) ||
            abs(time() - (int) $timestamp) > 300 ||
            !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            abort(400, 'Invalid webhook signature.');
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            $secret
        );

        if (!hash_equals($expected, $signature)) {
            abort(400, 'Invalid webhook signature.');
        }

        $event = json_decode($payload, true);

        if (!is_array($event) ||
            !is_string($event['id'] ?? null) ||
            !is_string($event['type'] ?? null)) {
            abort(400, 'Invalid webhook payload.');
        }

        // Pixelforge test event.
        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        $expectedStatuses = [
            'generation.processing' => 'processing',
            'generation.succeeded' => 'succeeded',
            'generation.failed' => 'failed',
        ];

        $expectedStatus = $expectedStatuses[$event['type']]
            ?? null;

        $generationData = $event['data']['object'] ?? null;

        if ($expectedStatus === null ||
            !is_array($generationData) ||
            ($generationData['status'] ?? null) !== $expectedStatus) {
            abort(400, 'Invalid generation event.');
        }

        // Our internal ID was included in provider metadata.
        $metadataId = $generationData['metadata']['generation_id']
            ?? null;

        if (!is_string($metadataId) ||
            !ctype_digit($metadataId) ||
            (int) $metadataId < 1) {
            abort(400, 'Missing local generation ID.');
        }

        $localId = (int) $metadataId;

        if (!Generation::whereKey($localId)->exists()) {
            // Do not acknowledge an event we cannot process.
            abort(503, 'Local generation not found.');
        }

        DB::transaction(function () use (
            $event,
            $generationData,
            $localId,
            $stateService
        ) {
            // Unique provider_event_id prevents duplicate
            // webhook records, even with concurrent deliveries.
            $inserted = DB::table('webhook_events')
                ->insertOrIgnore([
                    'provider_event_id' => $event['id'],
                    'generation_id' => $localId,
                    'event_type' => $event['type'],
                    'payload' => json_encode(
                        $event,
                        JSON_THROW_ON_ERROR
                    ),
                    'provider_created_at' =>
                        isset($event['created_at'])
                            ? Carbon::parse($event['created_at'])
                            : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            // Already received and processed this event.
            if ($inserted === 0) {
                return;
            }

            // Update the generation and calculate actual
            // charges using our existing state service.
            $stateService->sync(
                $localId,
                $generationData
            );

            DB::table('webhook_events')
                ->where('provider_event_id', $event['id'])
                ->update([
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return response()->noContent();
    }
}
