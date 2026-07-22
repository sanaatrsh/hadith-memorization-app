<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('is_correct')->default(false);
            $table->json('evaluation_report')->nullable();
            $table->timestamps();

            $table->unique('exam_question_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
