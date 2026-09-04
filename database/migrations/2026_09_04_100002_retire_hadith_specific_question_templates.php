<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the question templates that interpolate one hadith.
 *
 * A question row is now shared by every hadith it is asked about, so its
 * wording cannot name one: «اكتب نص الحديث الذي عنوانه: {title}» has no single
 * answer once the same row covers three hadiths. GenerateExam strips such
 * placeholders defensively, but the stripped wording reads as a fragment and
 * duplicates the generic template it was meant to become.
 *
 * They are deactivated rather than deleted: the rows are still referenced by
 * exam_questions.question_template_id, and an administrator may want to reword
 * one instead of losing it. Deactivating is what keeps them out of newly
 * generated exams.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('question_templates')
            ->where('is_active', true)
            ->where('prompt_template', 'like', '%{%}%')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('question_templates')
            ->where('is_active', false)
            ->where('prompt_template', 'like', '%{%}%')
            ->update(['is_active' => true, 'updated_at' => now()]);
    }
};
