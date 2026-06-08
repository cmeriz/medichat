<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class UrineExam extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'exam_date',
        'lab_name',
        'file_path',
        'file_original_name',
        'color',
        'appearance',
        'specific_gravity',
        'ph',
        'protein',
        'glucose',
        'ketones',
        'bilirubin',
        'urobilinogen',
        'blood',
        'nitrite',
        'leukocyte_esterase',
        'wbc',
        'rbc',
        'epithelial_cells',
        'bacteria',
        'casts',
        'crystals',
        'mucus',
        'yeast',
        'ai_extraction_raw',
        'notes',
    ];

    protected $casts = [
        'exam_date' => 'date',
        'ai_extraction_raw' => 'array',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function messages(): MorphMany
    {
        return $this->morphMany(Message::class, 'examable');
    }
}
