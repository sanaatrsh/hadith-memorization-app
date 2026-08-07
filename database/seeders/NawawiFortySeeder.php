<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\HadithAid;
use App\Models\HadithTerm;
use App\Models\Narrator;
use Illuminate\Database\Seeder;

/**
 * Seeds the full text of "الأربعون النووية" — the forty-two hadiths collected by
 * Imam an-Nawawi, together with their companions (narrators), the explanation of
 * their uncommon words, and memorization aids.
 *
 * The catalogue lives in database/seeders/data/nawawi_forty.php so this class
 * stays about the persistence rules.
 *
 * It is idempotent: hadiths are keyed by (book, title), terms by (hadith, term)
 * and aids by (hadith, title), so rerunning db:seed refreshes the same rows
 * instead of adding a second copy of the collection.
 */
class NawawiFortySeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{book:array<string,mixed>, narrators:array<string,string>, hadiths:array<int,array<string,mixed>>} $catalogue */
        $catalogue = require __DIR__.'/data/nawawi_forty.php';

        $book = Book::updateOrCreate(
            ['title' => $catalogue['book']['title']],
            $catalogue['book'],
        );

        $narrators = [];

        foreach ($catalogue['narrators'] as $name => $biography) {
            $narrators[$name] = Narrator::updateOrCreate(
                ['name' => $name],
                ['biography' => $biography],
            );
        }

        foreach ($catalogue['hadiths'] as $entry) {
            $narrator = $narrators[$entry['narrator']]
                ?? Narrator::updateOrCreate(['name' => $entry['narrator']], []);

            $hadith = Hadith::updateOrCreate(
                ['book_id' => $book->id, 'title' => $entry['title']],
                [
                    'narrator_id' => $narrator->id,
                    'text' => $entry['text'],
                    'source' => $entry['source'],
                    'is_active' => true,
                    // The collection is numbered, so its own numbering is the order.
                    'sort_order' => $entry['number'],
                ],
            );

            foreach ($entry['terms'] as $term => $explanation) {
                HadithTerm::updateOrCreate(
                    ['hadith_id' => $hadith->id, 'term' => $term],
                    ['explanation' => $explanation],
                );
            }

            $sortOrder = 1;

            foreach ($entry['aids'] as $title => $content) {
                HadithAid::updateOrCreate(
                    ['hadith_id' => $hadith->id, 'title' => $title],
                    ['content' => $content, 'sort_order' => $sortOrder++],
                );
            }
        }
    }
}
