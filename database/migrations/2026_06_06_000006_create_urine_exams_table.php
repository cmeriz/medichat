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
