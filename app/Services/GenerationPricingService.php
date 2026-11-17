<?php

namespace App\Services;

use InvalidArgumentException;

class GenerationPricingService
{
    private const MARKUP_MULTIPLIER = '1.25';
    private const PRECISION = 12;

    /**
     * Calculate the exact provider cost and user charge.
     * All monetary values are decimal strings.
     */
    public function actualCost(array $usage, array $rates): array
    {
        $providerCost = '0';

        $tokenTypes = [
            'input_text_tokens',
            'input_image_tokens',
            'output_tokens',
            'reasoning_tokens',
        ];

        foreach ($tokenTypes as $type) {
            if (!isset($usage[$type], $rates[$type])) {
                throw new InvalidArgumentException(
                    "Missing usage or rate for {$type}"
                );
            }

            $count = $usage[$type];
            $rate = (string) $rates[$type];

            if (!is_int($count) || $count < 0) {
                throw new InvalidArgumentException(
                    "Invalid token count for {$type}"
                );
            }

            if (!preg_match('/^\d+(\.\d+)?$/', $rate)) {
                throw new InvalidArgumentException(
                    "Invalid rate for {$type}"
                );
            }

            $tokenCost = bcdiv(
                bcmul((string) $count, $rate, self::PRECISION),
                '1000000',
                self::PRECISION
            );

            $providerCost = bcadd(
                $providerCost,
                $tokenCost,
                self::PRECISION
            );
        }

        $userCost = bcmul(
            $providerCost,
            self::MARKUP_MULTIPLIER,
            self::PRECISION
        );

        return [
            'provider_cost_usd' => $providerCost,
            'user_cost_usd' => $userCost,
        ];
    }

    /**
     * Estimate generation cost using Pixelforge pricing data.
     */
    public function estimate(
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        array $pricing
    ): array {
        $tokens = $pricing['tokens'];
        $charactersPerToken = (int) $tokens['characters_per_text_token'];

        if ($charactersPerToken < 1) {
            throw new InvalidArgumentException(
                'Invalid characters-per-token setting'
            );
        }

        // Approximate prompt token count.
        $characters = mb_strlen($prompt, 'UTF-8');

        $textTokens = intdiv(
            $characters + $charactersPerToken - 1,
            $charactersPerToken
        );

        // Each reference image has a fixed token count.
        $imageTokens = count($referenceImages)
            * (int) $tokens['per_reference_image'];

        // Output tokens depend on quality and image size.
        $outputTokens = $tokens['output'][$quality][$size]
            ?? throw new InvalidArgumentException(
                'Invalid image size or quality'
            );

        // Use the midpoint of the reasoning-token range.
        $range = $tokens['reasoning'][$quality]
            ?? throw new InvalidArgumentException(
                'Invalid image quality'
            );

        $reasoningTokens = intdiv(
            (int) $range['min'] + (int) $range['max'],
            2
        );

        $estimatedUsage = [
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $imageTokens,
            'output_tokens' => (int) $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
        ];

        $cost = $this->actualCost(
            $estimatedUsage,
            $pricing['rates']
        );

        return [
            'estimated_usage' => $estimatedUsage,
            'estimated_provider_cost_usd' => $cost['provider_cost_usd'],
            'estimated_user_cost_usd' => $cost['user_cost_usd'],
        ];
    }
}
