
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Unique Pixelforge event ID
            $table->string('provider_event_id')->unique();

            // Associated generation (nullable for ping events)
            $table->foreignId('generation_id')
                ->nullable()
                ->constrained('generations')
                ->nullOnDelete();

            // Event details
            $table->string('event_type', 100);
            $table->json('payload');

            // Processing audit
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['generation_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
