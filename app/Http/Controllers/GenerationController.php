<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PixelforgeService;
use App\Services\GenerationPricingService;
use App\Jobs\SubmitGeneration;
use App\Services\GenerationSubmissionService;

class GenerationController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        // Only return generations belonging to
        // the currently authenticated user.
        $generations = $request->user()
            ->generations()
            ->latest('id')
            ->paginate(20, [
                'id',
                'prompt',
                'size',
                'quality',
                'status',
                'estimated_cost_usd',
                'actual_cost_usd',
                'image_url',
                'error_code',
                'error_message',
                'created_at',
                'completed_at',
            ]);

        return response()->json($generations);
    }

    public function estimate(
        Request $request,
        PixelforgeService $pixelforge,
        GenerationPricingService $calculator
    ): JsonResponse {
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'size' => 'required|in:1024x1024,1536x1024,1024x1536',
            'quality' => 'required|in:low,medium,high',
            'reference_images' => 'sometimes|array|max:4',
            'reference_images.*' => 'required|url:http,https',
        ]);

        $pricing = $pixelforge->pricing();

        $estimate = $calculator->estimate(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            $validated['reference_images'] ?? [],
            $pricing
        );

        return response()->json([
            'currency' => 'USD',
            'estimated_usage' => $estimate['estimated_usage'],
            'estimated_provider_cost_usd' =>
                $estimate['estimated_provider_cost_usd'],
            'estimated_user_cost_usd' =>
                $estimate['estimated_user_cost_usd'],
        ]);
    }


    public function store(
        Request $request,
        GenerationSubmissionService $submissionService
        ): JsonResponse {
        // Validate the user's image-generation request.
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'size' => 'required|in:1024x1024,1536x1024,1024x1536',
            'quality' => 'required|in:low,medium,high',
            'reference_images' => 'sometimes|array|max:4',
            'reference_images.*' => 'required|url:http,https',
        ]);

        // Prepare and save the generation in MySQL.
        // The service calculates the estimate and
        // creates a unique idempotency key.
        $generation = $submissionService->prepare(
            $request->user(),
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            $validated['reference_images'] ?? []
        );

        // Submit asynchronously using the database queue.
        SubmitGeneration::dispatch($generation->id)
            ->afterCommit();

        // Return immediately without waiting for Pixelforge.
        return response()->json([
            'message' => 'Generation queued successfully.',
            'generation' => [
                'id' => $generation->id,
                'status' => $generation->status,
                'estimated_cost_usd' =>
                    $generation->estimated_cost_usd,
            ],
        ], 202);
    }

}
