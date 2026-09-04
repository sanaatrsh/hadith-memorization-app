<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalizes the exam so a question is stored once and its answers vary by
 * hadith.
 *
 * Before: one `exam_questions` row per hadith, carrying both the wording and
 * that hadith's correct answer, and exactly one `exam_answers` row per
 * question (a one-to-one enforced by a unique index). The wording «من هو راوي
 * هذا الحديث؟» was therefore duplicated for every hadith it was asked about.
 *
 * After: `exam_questions` holds the wording only — «من هو الراوي؟», «أكمل
 * الحديث»، «اتل الحديث» — and `exam_answers` holds one row per hadith the
 * question is asked about, carrying that hadith's reference answer alongside
 * what the learner submitted. The relationship is one-to-many.
 *
 * `exams` also gains a unique (user_id, book_id): every book has one exam, and
 * retaking it resets that exam rather than piling up a new one.
 *
 * Exam content is fully derived from the books and the question bank —
 * GenerateExam rebuilds it on demand — so the rows are cleared rather than
 * reshaped in place. Re-run `php artisan db:seed --class=ArabicExamSeeder` for
 * the demo exams.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->clearExams();

        Schema::dropIfExists('exam_answers');

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->constrained('exam_questions')->cascadeOnDelete();
            // Which hadith this question is being asked about. The reference
            // answer lives here because it is what varies per hadith.
            $table->foreignId('hadith_id')->constrained('hadiths')->cascadeOnDelete();
            $table->text('correct_answer')->nullable();
            $table->text('answer_text')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->boolean('is_correct')->default(false);
            $table->json('evaluation_report')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per (question, hadith): a question may be asked about
            // many hadiths, but never twice about the same one.
            $table->unique(['exam_question_id', 'hadith_id']);
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            // Both moved to exam_answers: the wording is now hadith-agnostic.
            $table->dropConstrainedForeignId('hadith_id');
            $table->dropColumn('correct_answer');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->unique(['user_id', 'book_id']);
        });
    }

    public function down(): void
    {
        $this->clearExams();

        Schema::table('exams', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'book_id']);
        });

        Schema::table('exam_questions', function (Blueprint $table) {
            $table->foreignId('hadith_id')->nullable()->after('exam_id')->constrained('hadiths')->cascadeOnDelete();
            $table->text('correct_answer')->nullable()->after('question_text');
        });

        Schema::dropIfExists('exam_answers');

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

    /**
     * Questions and answers cascade from the exam, and clearing the exams is
     * also what makes the new unique (user_id, book_id) satisfiable on a
     * database that already holds several exams for the same book.
     */
    private function clearExams(): void
    {
        DB::table('exams')->delete();
    }
};
