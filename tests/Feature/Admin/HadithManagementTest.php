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

    public function test_admin_can_set_and_update_the_hadith_intro(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $book = Book::factory()->create();

        $hadithId = $this->postJson('/api/v1/admin/hadiths', [
            'book_id' => $book->id,
            'title' => 'إنما الأعمال بالنيات',
            'intro' => 'عن عمر بن الخطاب رضي الله عنه قال',
            'text' => 'إنما الأعمال بالنيات',
        ])
            ->assertCreated()
            ->assertJsonPath('data.intro', 'عن عمر بن الخطاب رضي الله عنه قال')
            ->json('data.id');

        $this->patchJson("/api/v1/admin/hadiths/{$hadithId}", [
            'intro' => 'بينما نحن جلوس عند رسول الله صلى الله عليه وسلم',
        ])
            ->assertOk()
            ->assertJsonPath('data.intro', 'بينما نحن جلوس عند رسول الله صلى الله عليه وسلم');

        $this->assertDatabaseHas('hadiths', [
            'id' => $hadithId,
            'intro' => 'بينما نحن جلوس عند رسول الله صلى الله عليه وسلم',
        ]);
    }

    public function test_a_hadith_is_numbered_inside_its_own_book(): void
    {
        // The row id is catalogue-wide, so it cannot be shown to the learner:
        // hadith #100 in the table may be the 4th of الأربعون النووية.
        Sanctum::actingAs(User::factory()->admin()->create());
        $book = Book::factory()->create();
        $other = Book::factory()->create();

        // Numbering continues per book when it is not given.
        $first = $this->postJson('/api/v1/admin/hadiths', [
            'book_id' => $book->id,
            'title' => 'الأول',
            'text' => 'متن الأول',
        ])->assertCreated()->assertJsonPath('data.number_in_book', 1)->json('data.id');

        $this->postJson('/api/v1/admin/hadiths', [
            'book_id' => $book->id,
            'title' => 'الثاني',
            'text' => 'متن الثاني',
        ])->assertCreated()->assertJsonPath('data.number_in_book', 2);

        // Each book numbers from one, independently.
        $this->postJson('/api/v1/admin/hadiths', [
            'book_id' => $other->id,
            'title' => 'أول كتاب آخر',
            'text' => 'متن',
        ])->assertCreated()->assertJsonPath('data.number_in_book', 1);

        // And it can be set explicitly — the published number of a numbered
        // collection is not always the insertion order.
        $this->patchJson("/api/v1/admin/hadiths/{$first}", ['number_in_book' => 40])
            ->assertOk()
            ->assertJsonPath('data.number_in_book', 40);

        $this->assertDatabaseHas('hadiths', ['id' => $first, 'number_in_book' => 40]);
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
