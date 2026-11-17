
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Pixelforge submission
            $table->string('provider_generation_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();

            // Image request
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 20);
            $table->json('reference_images')->nullable();

            // Generation lifecycle
            $table->string('status', 30)->default('pending_submission');

            // All amounts in USD, preserving fractional cents
            $table->decimal('estimated_cost_usd', 20, 12)->nullable();
            $table->decimal('provider_cost_usd', 20, 12)->nullable();
            $table->decimal('actual_cost_usd', 20, 12)->nullable();

            // Provider token accounting
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();

            // Result
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            // Errors
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
