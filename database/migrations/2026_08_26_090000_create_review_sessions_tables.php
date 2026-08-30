<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A review session is one sitting: the hadiths due today, fixed at the
     * moment it starts so the list cannot shift under the learner while they
     * work through it, and a result once they finish.
     *
     * The set is snapshotted into review_session_items rather than recomputed
     * per request, because reciting a hadith reschedules it — a live query
     * would drop each hadith out of the session the moment it is answered.
     */
    public function up(): void
    {
        Schema::create('review_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('in_progress'); // in_progress | completed
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('review_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_session_id')->constrained('review_sessions')->cascadeOnDelete();
            $table->foreignId('hadith_id')->constrained('hadiths')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            // Set when the learner recites this hadith; the attempt carries the
            // score, so the session never stores a second copy of it.
            $table->foreignId('memorization_attempt_id')->nullable()
                ->constrained('memorization_attempts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['review_session_id', 'hadith_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_session_items');
        Schema::dropIfExists('review_sessions');
    }
};
