<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    public const CREATED_AT = 'started_at';

    protected $fillable = [
        'patient_id',
        'context_summary',
        'context_updated_at',
        'summary_from_message_id',
    ];

    protected $casts = [
        'context_updated_at' => 'datetime',
        'started_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function summaryFromMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'summary_from_message_id');
    }
}
