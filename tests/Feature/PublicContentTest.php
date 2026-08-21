<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_book_list_returns_only_active_books(): void
    {
        Book::factory()->create(['title' => 'Active']);
        Book::factory()->inactive()->create(['title' => 'Hidden']);

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Active'])
            ->assertJsonMissing(['title' => 'Hidden']);
    }

    public function test_inactive_hadith_is_not_publicly_visible(): void
    {
        $hadith = Hadith::factory()->inactive()->create();

        $this->getJson("/api/v1/hadiths/{$hadith->id}")->assertNotFound();
    }

    public function test_public_hadith_detail_includes_content(): void
    {
        $hadith = Hadith::factory()->create();

        $this->getJson("/api/v1/hadiths/{$hadith->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $hadith->id)
            ->assertJsonStructure(['data' => ['title', 'intro', 'text', 'narrator', 'terms', 'aids', 'audio_url']]);
    }

    public function test_public_hadith_detail_exposes_the_hadith_intro(): void
    {
        $hadith = Hadith::factory()->create([
            'intro' => 'عن عمر بن الخطاب رضي الله عنه قال',
        ]);

        $this->getJson("/api/v1/hadiths/{$hadith->id}")
            ->assertOk()
            ->assertJsonPath('data.intro', 'عن عمر بن الخطاب رضي الله عنه قال');
    }

    public function test_an_authenticated_user_sees_the_whole_catalogue_with_the_books_they_added(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $added = Book::factory()->create(['title' => 'مضاف', 'sort_order' => 1]);
        Book::factory()->create(['title' => 'غير مضاف', 'sort_order' => 2]);
        UserBook::create(['user_id' => $user->id, 'book_id' => $added->id, 'started_at' => now()]);

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'مضاف')
            ->assertJsonPath('data.0.is_added', true)
            ->assertJsonPath('data.1.title', 'غير مضاف')
            ->assertJsonPath('data.1.is_added', false);
    }
}
