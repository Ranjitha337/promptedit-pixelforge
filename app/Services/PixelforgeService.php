<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class PixelforgeService
{
    private function client(): PendingRequest
    {
        return Http::withToken(
            config('services.pixelforge.api_key')
        )
            ->acceptJson()
            ->timeout(20);
    }

    private function url(string $endpoint): string
    {
        return rtrim(
            config('services.pixelforge.base_url'),
            '/'
        ) . '/' . ltrim($endpoint, '/');
    }

    public function pricing(): array
    {
        return $this->client()
            ->get($this->url('pricing'))
            ->throw()
            ->json();
    }

    public function usage(): array
    {
        return $this->client()
            ->get($this->url('usage'))
            ->throw()
            ->json();
    }

    
    /**
     * Submit a new image generation to Pixelforge.
     *
     * Return the full response so callers can handle
     * 202, 429, 503 and validation errors separately.
     */
    public function createGeneration(
        array $payload,
        string $idempotencyKey
    ): Response {
        return $this->client()
            ->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post(
                $this->url('generations'),
                $payload
            );
    }

    /**
     * Retrieve the latest status of a generation.
     */
    public function getGeneration(string $providerId): Response
    {
        return $this->client()
            ->get(
                $this->url('generations/' . rawurlencode($providerId))
            );
    }

}
