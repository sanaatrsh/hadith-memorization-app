<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HadithManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_hadith_belonging_to_one_book(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $book = Book::factory()->create();

        $this->postJson('/api/v1/admin/hadiths', [
            'book_id' => $book->id,
            'title' => 'إنما الأعمال بالنيات',
            'text' => 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى',
            'source' => 'صحيح البخاري',
        ])
            ->assertCreated()
            ->assertJsonPath('data.book_id', $book->id);
    }

    public function test_admin_can_manage_nested_terms_and_aids(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $hadith = Hadith::factory()->create();

        $this->postJson("/api/v1/admin/hadiths/{$hadith->id}/terms", [
            'term' => 'النية',
            'explanation' => 'القصد',
        ])->assertCreated();

        $this->postJson("/api/v1/admin/hadiths/{$hadith->id}/aids", [
            'title' => 'تلميح',
            'content' => 'ركز على الكلمة الأولى',
        ])->assertCreated();

        $this->assertDatabaseHas('hadith_terms', ['hadith_id' => $hadith->id, 'term' => 'النية']);
        $this->assertDatabaseHas('hadith_aids', ['hadith_id' => $hadith->id, 'title' => 'تلميح']);
    }

    public function test_admin_can_upload_and_delete_official_audio(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());
        $hadith = Hadith::factory()->create();

        $this->postJson("/api/v1/admin/hadiths/{$hadith->id}/audio", [
            'audio' => UploadedFile::fake()->create('recite.mp3', 100, 'audio/mpeg'),
        ])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['audio_url']]);

        $this->assertNotNull($hadith->refresh()->audioUrl());

        $this->deleteJson("/api/v1/admin/hadiths/{$hadith->id}/audio")->assertOk();
        $this->assertNull($hadith->refresh()->audioUrl());
    }
}
