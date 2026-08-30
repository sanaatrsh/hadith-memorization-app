<?php

namespace Database\Seeders\Concerns;

use App\Models\Book;

/**
 * Attaches the shipped cover image for a seeded book.
 *
 * The files live in database/seeders/assets/covers so seeding never depends on
 * the network. Re-seeding replaces the cover rather than stacking copies: the
 * collection is single-file, but the media rows would otherwise pile up.
 */
trait SeedsBookCovers
{
    /**
     * @var array<string,string> book title => file name
     */
    private array $coverFiles = [
        'الأربعون النووية' => 'nawawi-forty.png',
        'رياض الصالحين' => 'riyad-as-salihin.png',
        'جوامع الإسلام' => 'jawami-al-islam.png',
    ];

    protected function attachCover(Book $book): void
    {
        $file = $this->coverFiles[$book->title] ?? null;

        if ($file === null) {
            return;
        }

        $path = database_path('seeders/assets/covers/'.$file);

        if (! is_file($path)) {
            return;
        }

        // Idempotent: keep the existing cover when it is already this file.
        $existing = $book->getFirstMedia('book_cover');

        if ($existing !== null && $existing->file_name === $file) {
            return;
        }

        $book->addMedia($path)
            ->preservingOriginal()
            ->usingFileName($file)
            ->toMediaCollection('book_cover');
    }
}
