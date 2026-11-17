<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class GenerationSubmissionService
{
    public function __construct(
        private PixelforgeService $pixelforge,
        private GenerationPricingService $calculator
    ) {}

    public function prepare(
        User $user,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages = []
    ): Generation {
        Validator::make([
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
        ], [
            'prompt' => 'required|string|max:4000',
            'size' => 'required|in:1024x1024,1536x1024,1024x1536',
            'quality' => 'required|in:low,medium,high',
            'reference_images' => 'array|max:4',
            'reference_images.*' => 'required|url:http,https',
        ])->validate();

        // Retrieve current provider pricing.
        $pricing = $this->pixelforge->pricing();

        // Calculate the estimated user charge.
        $estimate = $this->calculator->estimate(
            $prompt,
            $size,
            $quality,
            $referenceImages,
            $pricing
        );

        // Save locally BEFORE submitting to Pixelforge.
        return $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'status' => 'pending_submission',
            'estimated_cost_usd' =>
                $estimate['estimated_user_cost_usd'],
        ]);
    }
}
