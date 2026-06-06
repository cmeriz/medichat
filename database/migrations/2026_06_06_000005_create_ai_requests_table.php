<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 100);
            $table->string('endpoint', 100)->default('chat.completions');
            $table->string('request_type', 50);
            $table->json('prompt_messages')->nullable();
            $table->mediumText('response_text')->nullable();
            $table->string('response_id')->nullable();
            $table->string('finish_reason', 100)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('input_cost_per_million', 10, 4)->default(0);
            $table->decimal('cached_input_cost_per_million', 10, 4)->default(0);
            $table->decimal('output_cost_per_million', 10, 4)->default(0);
            $table->decimal('estimated_cost_usd', 12, 8)->default(0);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index(['model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
