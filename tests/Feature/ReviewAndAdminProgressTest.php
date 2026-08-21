<?php

namespace Tests\Feature;

use App\Enums\MemorizationStatus;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewAndAdminProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_attempt_reuses_evaluation_and_updates_progress(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output_text' => 'x'], 200)]); // AI unavailable path is fine

        Sanctum::actingAs($user = User::factory()->create());
        $book = Book::factory()->create();
        $hadith = Hadith::factory()->create(['book_id' => $book->id, 'text' => 'انما الاعمال بالنيات']);

        $this->postJson("/api/v1/reviews/{$hadith->id}/attempts", [
            'client_attempt_uuid' => (string) Str::uuid(),
            'recognized_text' => 'انما الاعمال بالنيات',
        ])
            ->assertOk()
            ->assertJsonPath('data.score', 100);

        $this->assertDatabaseHas('memorization_attempts', [
            'hadith_id' => $hadith->id,
            'type' => 'review',
        ]);
        $this->assertDatabaseHas('user_hadith_progress', ['user_id' => $user->id, 'hadith_id' => $hadith->id]);
    }

    public function test_each_completed_attempt_updates_progress_once(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output_text' => 'x'], 200)]);

        Sanctum::actingAs($user = User::factory()->create());
        $book = Book::factory()->create();
        $hadith = Hadith::factory()->create(['book_id' => $book->id, 'text' => 'انما الاعمال بالنيات']);

        $this->postJson('/api/v1/memorization/attempts', [
            'client_attempt_uuid' => (string) Str::uuid(),
            'hadith_id' => $hadith->id,
            'recognized_text' => 'انما الاعمال بالنيات',
        ])->assertOk();

        $progress = UserHadithProgress::where('user_id', $user->id)->where('hadith_id', $hadith->id)->first();
        $this->assertSame(1, $progress->attempts_count);
        $this->assertSame(1, $progress->srs_level); // passed => advanced
    }

    public function test_admin_manual_progress_change_is_auditable(): void
    {
        Sanctum::actingAs($admin = User::factory()->admin()->create());
        $user = User::factory()->create();
        $hadith = Hadith::factory()->create();

        $this->patchJson("/api/v1/admin/users/{$user->id}/hadiths/{$hadith->id}/progress", [
            'status' => MemorizationStatus::Memorized->value,
            'srs_level' => 4,
            'reason' => 'Verified in person',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'memorized');

        $this->assertDatabaseHas('user_hadith_progress', [
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'status' => 'memorized',
            'srs_level' => 4,
        ]);

        $this->assertDatabaseHas('progress_audits', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'reason' => 'Verified in person',
        ]);
    }

    public function test_regular_user_cannot_manually_change_progress(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $user = User::factory()->create();
        $hadith = Hadith::factory()->create();

        $this->patchJson("/api/v1/admin/users/{$user->id}/hadiths/{$hadith->id}/progress", [
            'srs_level' => 4,
        ])->assertStatus(403);
    }
}
