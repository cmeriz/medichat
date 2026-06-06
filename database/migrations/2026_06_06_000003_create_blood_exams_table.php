<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('exam_date')->nullable();
            $table->string('lab_name')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_original_name')->nullable();

            $table->decimal('rbc', 6, 2)->nullable();
            $table->decimal('hemoglobin', 5, 2)->nullable();
            $table->decimal('hematocrit', 5, 2)->nullable();
            $table->decimal('mcv', 6, 2)->nullable();
            $table->decimal('mch', 5, 2)->nullable();
            $table->decimal('mchc', 5, 2)->nullable();
            $table->decimal('rdw', 5, 2)->nullable();
            $table->decimal('wbc', 6, 2)->nullable();
            $table->decimal('neutrophils_pct', 5, 2)->nullable();
            $table->decimal('neutrophils_abs', 6, 2)->nullable();
            $table->decimal('lymphocytes_pct', 5, 2)->nullable();
            $table->decimal('lymphocytes_abs', 6, 2)->nullable();
            $table->decimal('monocytes_pct', 5, 2)->nullable();
            $table->decimal('monocytes_abs', 6, 2)->nullable();
            $table->decimal('eosinophils_pct', 5, 2)->nullable();
            $table->decimal('eosinophils_abs', 6, 2)->nullable();
            $table->decimal('basophils_pct', 5, 2)->nullable();
            $table->decimal('basophils_abs', 6, 2)->nullable();
            $table->decimal('platelets', 7, 2)->nullable();
            $table->decimal('mpv', 5, 2)->nullable();

            $table->decimal('glucose', 6, 2)->nullable();
            $table->decimal('bun', 6, 2)->nullable();
            $table->decimal('creatinine', 5, 3)->nullable();
            $table->decimal('egfr', 6, 2)->nullable();
            $table->decimal('sodium', 6, 2)->nullable();
            $table->decimal('potassium', 5, 2)->nullable();
            $table->decimal('chloride', 6, 2)->nullable();
            $table->decimal('co2', 5, 2)->nullable();
            $table->decimal('calcium', 5, 2)->nullable();

            $table->decimal('total_cholesterol', 6, 2)->nullable();
            $table->decimal('hdl_cholesterol', 6, 2)->nullable();
            $table->decimal('ldl_cholesterol', 6, 2)->nullable();
            $table->decimal('vldl_cholesterol', 6, 2)->nullable();
            $table->decimal('triglycerides', 6, 2)->nullable();

            $table->decimal('total_bilirubin', 5, 2)->nullable();
            $table->decimal('direct_bilirubin', 5, 2)->nullable();
            $table->decimal('indirect_bilirubin', 5, 2)->nullable();
            $table->decimal('ast', 6, 2)->nullable();
            $table->decimal('alt', 6, 2)->nullable();
            $table->decimal('alp', 6, 2)->nullable();
            $table->decimal('ggt', 6, 2)->nullable();
            $table->decimal('total_protein', 5, 2)->nullable();
            $table->decimal('albumin', 5, 2)->nullable();

            $table->decimal('tsh', 7, 4)->nullable();
            $table->decimal('free_t4', 6, 3)->nullable();
            $table->decimal('free_t3', 6, 3)->nullable();

            $table->decimal('serum_iron', 6, 2)->nullable();
            $table->decimal('tibc', 6, 2)->nullable();
            $table->decimal('transferrin_saturation', 5, 2)->nullable();
            $table->decimal('ferritin', 8, 2)->nullable();

            $table->decimal('crp', 6, 3)->nullable();
            $table->decimal('esr', 6, 2)->nullable();

            $table->decimal('hba1c', 5, 2)->nullable();
            $table->decimal('fasting_insulin', 7, 3)->nullable();

            $table->json('ai_extraction_raw')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_exams');
    }
};
