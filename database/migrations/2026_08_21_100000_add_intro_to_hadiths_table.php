<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The isnad/context line a hadith opens with ("مقدمة الحديث"), e.g.
     * «عن عمر بن الخطاب رضي الله عنه قال» or «بينما نحن جلوس عند رسول الله ﷺ».
     * It is kept out of `text` so the matn stays the only thing recall is
     * scored against.
     */
    public function up(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->text('intro')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('hadiths', function (Blueprint $table) {
            $table->dropColumn('intro');
        });
    }
};
