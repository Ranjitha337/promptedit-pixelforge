<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $fillable = [
        'provider_event_id',
        'generation_id',
        'event_type',
        'payload',
        'provider_created_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'provider_created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
