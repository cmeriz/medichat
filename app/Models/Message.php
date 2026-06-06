<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'included_in_summary',
        'examable_id',
        'examable_type',
    ];

    protected $casts = [
        'included_in_summary' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function examable(): MorphTo
    {
        return $this->morphTo();
    }
}
