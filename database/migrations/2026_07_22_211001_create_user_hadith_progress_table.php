<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_hadith_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hadith_id')->constrained('hadiths')->cascadeOnDelete();
            $table->string('status')->default('not_started')->index();
            $table->unsignedTinyInteger('srs_level')->default(0);
            $table->unsignedTinyInteger('best_score')->default(0);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_review_at')->nullable()->index();
            $table->timestamp('memorized_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'hadith_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hadith_progress');
    }
};
