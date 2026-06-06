<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BloodExam extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'exam_date',
        'lab_name',
        'file_path',
        'file_original_name',
        'rbc',
        'hemoglobin',
        'hematocrit',
        'mcv',
        'mch',
        'mchc',
        'rdw',
        'wbc',
        'neutrophils_pct',
        'neutrophils_abs',
        'lymphocytes_pct',
        'lymphocytes_abs',
        'monocytes_pct',
        'monocytes_abs',
        'eosinophils_pct',
        'eosinophils_abs',
        'basophils_pct',
        'basophils_abs',
        'platelets',
        'mpv',
        'glucose',
        'bun',
        'creatinine',
        'egfr',
        'sodium',
        'potassium',
        'chloride',
        'co2',
        'calcium',
        'total_cholesterol',
        'hdl_cholesterol',
        'ldl_cholesterol',
        'vldl_cholesterol',
        'triglycerides',
        'total_bilirubin',
        'direct_bilirubin',
        'indirect_bilirubin',
        'ast',
        'alt',
        'alp',
        'ggt',
        'total_protein',
        'albumin',
        'tsh',
        'free_t4',
        'free_t3',
        'serum_iron',
        'tibc',
        'transferrin_saturation',
        'ferritin',
        'crp',
        'esr',
        'hba1c',
        'fasting_insulin',
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
