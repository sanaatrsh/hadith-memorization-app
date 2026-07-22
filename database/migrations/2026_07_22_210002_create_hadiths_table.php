<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hadiths', function (Blueprint $table) {
            $table->id();
            // Every hadith belongs to exactly one book.
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('narrator_id')->nullable()->constrained('narrators')->nullOnDelete();
            $table->string('title');
            $table->text('text');
            $table->string('source')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hadiths');
    }
};
