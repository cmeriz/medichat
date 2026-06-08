<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('urine_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('exam_date')->nullable();
            $table->string('lab_name')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_original_name')->nullable();
            $table->string('color')->nullable();
            $table->string('appearance')->nullable();
            $table->decimal('specific_gravity', 5, 3)->nullable();
            $table->decimal('ph', 4, 2)->nullable();
            $table->string('protein')->nullable();
            $table->string('glucose')->nullable();
            $table->string('ketones')->nullable();
            $table->string('bilirubin')->nullable();
            $table->string('urobilinogen')->nullable();
            $table->string('blood')->nullable();
            $table->string('nitrite')->nullable();
            $table->string('leukocyte_esterase')->nullable();
            $table->string('wbc')->nullable();
            $table->string('rbc')->nullable();
            $table->string('epithelial_cells')->nullable();
            $table->string('bacteria')->nullable();
            $table->string('casts')->nullable();
            $table->string('crystals')->nullable();
            $table->string('mucus')->nullable();
            $table->string('yeast')->nullable();
            $table->json('ai_extraction_raw')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('urine_exams');
    }
};
