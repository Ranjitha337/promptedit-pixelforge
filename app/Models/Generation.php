<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $fillable = [
        'user_id',
        'provider_generation_id',
        'idempotency_key',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'status',
        'estimated_cost_usd',
        'provider_cost_usd',
        'actual_cost_usd',
        'usage',
        'rates',
        'image_url',
        'image_url_expires_at',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',

            'estimated_cost_usd' => 'decimal:12',
            'provider_cost_usd' => 'decimal:12',
            'actual_cost_usd' => 'decimal:12',

            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
