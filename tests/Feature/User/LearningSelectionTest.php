<?php

namespace Tests\Feature\User;

use App\Enums\MemorizationStatus;
use App\Models\Book;
use App\Models\Hadith;
use App\Models\User;
use App\Models\UserHadithProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * There is no per-user book list any more: every active book is open to every
 * user, and progress is what the dashboard reports.
 */
class LearningSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_learning_list_endpoints_are_gone(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = Book::factory()->create();

        $this->getJson('/api/v1/user/books')->assertNotFound();
        $this->postJson("/api/v1/user/books/{$book->id}/start")->assertNotFound();
        $this->deleteJson("/api/v1/user/books/{$book->id}")->assertNotFound();
    }

    public function test_the_catalogue_reports_how_far_the_user_got_in_each_book(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $started = Book::factory()->create(['sort_order' => 1]);
        Book::factory()->create(['sort_order' => 2]);
        $hadith = Hadith::factory()->create(['book_id' => $started->id]);

        UserHadithProgress::factory()->create([
            'user_id' => $user->id,
            'hadith_id' => $hadith->id,
            'status' => MemorizationStatus::Memorized,
        ]);

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonPath('data.0.is_started', true)
            ->assertJsonPath('data.0.progress_count', 1)
            ->assertJsonPath('data.0.memorized_count', 1)
            ->assertJsonPath('data.1.is_started', false)
            ->assertJsonPath('data.1.progress_count', 0);
    }

    public function test_dashboard_aggregates_progress_counts(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = Book::factory()->create();
        $hadiths = Hadith::factory(4)->create(['book_id' => $book->id]);

        UserHadithProgress::factory()->create([
            'user_id' => $user->id,
            'hadith_id' => $hadiths[0]->id,
            'status' => MemorizationStatus::Memorized,
            'next_review_at' => null,
        ]);
        UserHadithProgress::factory()->create([
            'user_id' => $user->id,
            'hadith_id' => $hadiths[1]->id,
            'status' => MemorizationStatus::Reviewing,
            'next_review_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.total_hadiths', 4)
            ->assertJsonPath('data.total_books', 1)
            ->assertJsonPath('data.memorized', 1)
            ->assertJsonPath('data.reviewing', 1)
            ->assertJsonPath('data.not_started', 2)
            ->assertJsonPath('data.reviews_due_today', 1)
            ->assertJsonPath('data.stack_count', 0)
            // The book counts as active because the user has worked in it.
            ->assertJsonCount(1, 'data.active_books')
            ->assertJsonPath('data.active_books.0.id', $book->id);
    }

    public function test_the_dashboard_is_empty_for_a_user_who_has_not_started(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Hadith::factory(3)->create(['book_id' => Book::factory()->create()->id]);

        $this->getJson('/api/v1/user/progress')
            ->assertOk()
            ->assertJsonPath('data.total_hadiths', 3)
            ->assertJsonPath('data.not_started', 3)
            ->assertJsonPath('data.active_books', []);
    }
}
