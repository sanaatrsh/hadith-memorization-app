<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hadith's own number inside its collection ("رقم الحديث في الكتاب").
 *
 * `id` is the row's identity across the whole catalogue and `sort_order` is an
 * internal ordering hint, so neither can be shown to the learner: hadith #100
 * in the table is the fourth hadith of الأربعون النووية. `number_in_book` is
 * that published number — unique within its book, and what the app displays
 * and the exam refers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->unsignedInteger('number_in_book')->nullable()->after('book_id');
        });

        $this->backfill();

        Schema::table('hadiths', function (Blueprint $table) {
            $table->unique(['book_id', 'number_in_book']);
        });
    }

    public function down(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->dropUnique(['book_id', 'number_in_book']);
            $table->dropColumn('number_in_book');
        });
    }

    /**
     * Number the existing rows the way each book already reads: by its own
     * ordering hint first, then by insertion order. Numbering per book keeps
     * the unique index satisfiable.
     */
    private function backfill(): void
    {
        foreach (DB::table('hadiths')->distinct()->pluck('book_id') as $bookId) {
            $ids = DB::table('hadiths')
                ->where('book_id', $bookId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $index => $id) {
                DB::table('hadiths')->where('id', $id)->update(['number_in_book' => $index + 1]);
            }
        }
    }
};
