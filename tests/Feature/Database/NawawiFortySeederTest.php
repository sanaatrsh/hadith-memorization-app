<?php

namespace Tests\Feature\Database;

use App\Models\Book;
use App\Models\Hadith;
use Database\Seeders\ArabicDemoSeeder;
use Database\Seeders\NawawiFortySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NawawiFortySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_full_nawawi_collection_idempotently(): void
    {
        $this->seed(NawawiFortySeeder::class);
        $this->seed(NawawiFortySeeder::class);

        $book = Book::where('title', 'الأربعون النووية')->sole();

        $this->assertTrue($book->is_active);
        $this->assertSame(42, $book->hadiths()->count());

        // Every hadith carries its number, a narrator, a takhrij and full text.
        $this->assertSame(
            range(1, 42),
            $book->hadiths()->orderBy('sort_order')->pluck('sort_order')->all(),
        );
        $this->assertSame(0, $book->hadiths()->whereNull('narrator_id')->count());
        $this->assertSame(0, $book->hadiths()->whereNull('source')->count());

        $first = $book->hadiths()->where('sort_order', 1)->sole();
        $this->assertSame('إنما الأعمال بالنيات', $first->title);
        $this->assertStringStartsWith('إنما الأعمال بالنيات، وإنما لكل امرئ ما نوى', $first->text);
        $this->assertSame('عمر بن الخطاب', $first->narrator->name);
        $this->assertSame('رواه البخاري ومسلم', $first->source);

        $last = $book->hadiths()->where('sort_order', 42)->sole();
        $this->assertSame('سعة مغفرة الله', $last->title);
        $this->assertSame('أنس بن مالك', $last->narrator->name);

        $this->assertDatabaseHas('hadiths', ['title' => 'لا ضرر ولا ضرار', 'text' => 'لا ضرر ولا ضرار']);
        $this->assertDatabaseHas('narrators', ['name' => 'أبو هريرة']);
        $this->assertDatabaseHas('hadith_terms', ['term' => 'عنان السماء']);

        // Terms and aids are attached to every hadith, and reseeding does not duplicate them.
        $hadithIds = $book->hadiths()->pluck('id');
        $this->assertSame(0, Hadith::whereIn('id', $hadithIds)->doesntHave('terms')->count());
        $this->assertSame(0, Hadith::whereIn('id', $hadithIds)->doesntHave('aids')->count());
    }

    public function test_it_reuses_the_book_seeded_by_the_arabic_demo_catalogue(): void
    {
        $this->seed(ArabicDemoSeeder::class);
        $this->seed(NawawiFortySeeder::class);

        $this->assertSame(1, Book::where('title', 'الأربعون النووية')->count());

        // The demo seeder stores an abbreviated matn; the collection replaces it
        // with the authentic wording instead of creating a second row.
        $this->assertSame(
            1,
            Hadith::where('title', 'إنما الأعمال بالنيات')->count(),
        );
        $this->assertStringContainsString(
            'فمن كانت هجرته إلى الله ورسوله',
            Hadith::where('title', 'إنما الأعمال بالنيات')->value('text'),
        );
    }
}
