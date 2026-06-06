<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'patient_id',
        'conversation_id',
        'model',
        'endpoint',
        'request_type',
        'prompt_messages',
        'response_text',
        'response_id',
        'finish_reason',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'total_tokens',
        'input_cost_per_million',
        'cached_input_cost_per_million',
        'output_cost_per_million',
        'estimated_cost_usd',
        'error',
    ];

    protected $casts = [
        'prompt_messages' => 'array',
        'input_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'input_cost_per_million' => 'decimal:4',
        'cached_input_cost_per_million' => 'decimal:4',
        'output_cost_per_million' => 'decimal:4',
        'estimated_cost_usd' => 'decimal:8',
        'created_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
