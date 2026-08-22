<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The per-user book list is gone: every active book belongs to every user,
     * and memorization is driven by the memorization stack and the progress
     * records instead. Databases created before this migration still carry the
     * table, so it is dropped here rather than by removing its creation.
     */
    public function up(): void
    {
        Schema::dropIfExists('user_books');
    }

    public function down(): void
    {
        // The feature was removed; there is nothing to restore.
    }
};
