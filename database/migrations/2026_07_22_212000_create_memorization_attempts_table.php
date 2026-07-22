<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorization_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_attempt_uuid');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hadith_id')->constrained('hadiths')->cascadeOnDelete();
            $table->string('type');
            $table->string('status');
            $table->text('recognized_text');
            $table->text('normalized_recognized_text');
            $table->text('normalized_reference_text');
            $table->unsignedTinyInteger('deterministic_score')->default(0);
            $table->unsignedTinyInteger('ai_score')->nullable();
            $table->unsignedTinyInteger('final_score')->default(0);
            $table->string('verdict')->nullable();
            $table->json('comparison_report')->nullable();
            $table->json('ai_report')->nullable();
            $table->string('gemini_model')->nullable();
            $table->string('gemini_interaction_id')->nullable();
            $table->unsignedInteger('processing_ms')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_attempt_uuid']);
            $table->index(['user_id', 'hadith_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorization_attempts');
    }
};
