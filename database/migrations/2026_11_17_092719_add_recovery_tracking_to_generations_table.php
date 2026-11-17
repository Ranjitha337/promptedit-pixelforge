<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->unsignedTinyInteger('recovery_attempts')
                ->default(0);

            $table->timestamp('last_recovery_at')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn([
                'recovery_attempts',
                'last_recovery_at',
            ]);
        });
    }
};
