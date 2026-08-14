<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\Narrator;
use App\Models\QuestionTemplate;
use App\Models\User;
use App\Models\UserBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    private function setupBookForUser(User $user): Book
    {
        $book = Book::factory()->create();
        UserBook::create(['user_id' => $user->id, 'book_id' => $book->id, 'started_at' => now()]);
        $narrator = Narrator::factory()->create(['name' => 'عمر بن الخطاب']);
        Hadith::factory(3)->create([
            'book_id' => $book->id,
            'narrator_id' => $narrator->id,
            'text' => 'انما الاعمال بالنيات',
            'source' => 'صحيح البخاري',
        ]);

        return $book;
    }

    public function test_it_lists_only_the_authenticated_users_exams_newest_first(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);
        QuestionTemplate::factory()->create();

        $olderId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');
        $newerId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');

        $otherBook = $this->setupBookForUser($other = User::factory()->create());
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/exams', ['book_id' => $otherBook->id, 'question_count' => 1]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/exams')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newerId)
            ->assertJsonPath('data.1.id', $olderId)
            ->assertJsonPath('data.0.book_title', $book->title);

        $this->assertArrayNotHasKey('questions', $response->json('data.0'));
    }

    public function test_exam_generation_uses_only_stored_templates_and_book_content(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);
        QuestionTemplate::factory()->create();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 5])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.question_count', 5)
            ->assertJsonCount(5, 'data.questions');

        $this->assertDatabaseCount('exam_questions', 5);
    }

    public function test_exam_generation_fails_without_templates(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 3])
            ->assertStatus(422);
    }

    public function test_written_narrator_answer_is_checked_deterministically(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])
            ->json('data.id');
        $questionId = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions.0.id');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questionId,
            'answer_text' => 'عمر بن الخطاب',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_correct', true)
            ->assertJsonPath('data.score', 100);
    }

    public function test_voice_answer_reuses_transcript_comparison(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])
            ->json('data.id');
        $questionId = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions.0.id');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questionId,
            'answer_text' => 'انما الاعمال بالنيات', // exact recitation
        ])
            ->assertOk()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.is_correct', true);
    }

    public function test_exam_completion_averages_answer_scores(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBookForUser($user);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 2])
            ->json('data.id');
        $questions = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions');

        // One perfect, one empty-ish answer.
        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questions[0]['id'],
            'answer_text' => 'انما الاعمال بالنيات',
        ])->assertOk();
        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questions[1]['id'],
            'answer_text' => 'كلمة خاطئة',
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_user_cannot_access_another_users_exam(): void
    {
        $owner = User::factory()->create();
        $book = $this->setupBookForUser($owner);
        QuestionTemplate::factory()->create();
        Sanctum::actingAs($owner);
        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/exams/{$examId}")->assertStatus(403);
    }
}
