<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonStructure(['data' => ['title', 'text', 'narrator', 'terms', 'aids', 'audio_url']]);
    }
}
