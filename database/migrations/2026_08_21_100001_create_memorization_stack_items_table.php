<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The memorization stack: hadiths explicitly pushed to the top of the
     * user's queue, either by the user asking to review one again or by the
     * evaluation flow (deterministic score / Gemini recommendation).
     *
     * One row per (user, hadith): pushing an existing hadith again refreshes
     * `pushed_at` so it moves back to the top instead of duplicating.
     */
    public function up(): void
    {
        Schema::create('memorization_stack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('hadith_id')->constrained('hadiths')->cascadeOnDelete();
            // user | evaluation | ai
            $table->string('source')->default('user')->index();
            $table->text('reason')->nullable();
            $table->unsignedTinyInteger('trigger_score')->nullable();
            $table->timestamp('pushed_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'hadith_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorization_stack_items');
    }
};
