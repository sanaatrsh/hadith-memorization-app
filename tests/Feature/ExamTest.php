<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\Narrator;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamTest extends TestCase
{
    use RefreshDatabase;

    private function setupBook(int $hadiths = 3): Book
    {
        $book = Book::factory()->create();
        $narrator = Narrator::factory()->create(['name' => 'عمر بن الخطاب']);
        Hadith::factory($hadiths)->create([
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
        $book = $this->setupBook();
        QuestionTemplate::factory()->create();

        $olderId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');
        $newerId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');

        $otherBook = $this->setupBook();
        Sanctum::actingAs(User::factory()->create());
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

    public function test_it_never_asks_about_the_same_hadith_twice(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 4);
        QuestionTemplate::factory()->create();
        QuestionTemplate::factory()->narrator()->create();

        // No question_count: all 4 hadiths were added today, and that is
        // under the default cap, so the exam covers all of them.
        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.question_count', 4)
            ->assertJsonCount(4, 'data.questions');

        $hadithIds = collect($response->json('data.questions'))->pluck('hadith_id');

        $this->assertCount(4, $hadithIds->unique(), 'Every hadith must be asked about at most once.');
        $this->assertEqualsCanonicalizing(
            $book->hadiths()->pluck('id')->all(),
            $hadithIds->all(),
        );
    }

    public function test_the_default_exam_size_is_capped_when_nothing_stands_out_as_todays_work(): void
    {
        Sanctum::actingAs(User::factory()->create());
        // All 9 hadiths were added on an earlier day, so none of them count
        // as "today's" and the exam falls back to the default cap.
        $book = $this->setupBook(hadiths: 9);
        $book->hadiths()->update(['created_at' => now()->subDay()]);
        QuestionTemplate::factory()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 6)
            ->assertJsonCount(6, 'data.questions');

        $hadithIds = collect($response->json('data.questions'))->pluck('hadith_id');
        $this->assertCount(6, $hadithIds->unique());
    }

    public function test_the_default_exam_prefers_hadiths_added_today_over_older_ones(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 4);
        $book->hadiths()->update(['created_at' => now()->subDays(3)]);

        // Only these two were added today.
        $today = Hadith::factory(2)->create([
            'book_id' => $book->id,
            'narrator_id' => Narrator::factory()->create()->id,
            'text' => 'انما الاعمال بالنيات',
        ]);
        QuestionTemplate::factory()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 2);

        $hadithIds = collect($response->json('data.questions'))->pluck('hadith_id');
        $this->assertEqualsCanonicalizing($today->pluck('id')->all(), $hadithIds->all());
    }

    public function test_todays_hadiths_are_also_capped_at_the_default_size(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        $book->hadiths()->update(['created_at' => now()->subDay()]);

        // 8 more added today: still capped at 6, and none of the old 2 leak in.
        $today = Hadith::factory(8)->create([
            'book_id' => $book->id,
            'narrator_id' => Narrator::factory()->create()->id,
            'text' => 'انما الاعمال بالنيات',
        ]);
        QuestionTemplate::factory()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 6)
            ->assertJsonCount(6, 'data.questions');

        $hadithIds = collect($response->json('data.questions'))->pluck('hadith_id');
        $this->assertCount(6, $hadithIds->unique());
        $this->assertEmpty($hadithIds->diff($today->pluck('id')), 'Only hadiths added today should be picked.');
    }

    public function test_an_explicit_question_count_ignores_the_todays_hadiths_default(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 5);
        QuestionTemplate::factory()->create();

        // An explicit count draws from the whole book, not just today's slice.
        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 5])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 5);
    }

    public function test_a_question_count_narrows_the_exam_without_repeating_a_hadith(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 5);
        QuestionTemplate::factory()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 3])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonCount(3, 'data.questions');

        $hadithIds = collect($response->json('data.questions'))->pluck('hadith_id');

        $this->assertCount(3, $hadithIds->unique());
    }

    public function test_a_count_larger_than_the_book_is_capped_at_its_hadiths(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 3);
        QuestionTemplate::factory()->create();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 25])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonCount(3, 'data.questions');

        $this->assertDatabaseCount('exam_questions', 3);
    }

    public function test_an_exam_can_be_taken_on_any_active_book(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = Book::factory()->create();
        Hadith::factory(2)->create(['book_id' => $book->id, 'text' => 'انما الاعمال بالنيات']);
        QuestionTemplate::factory()->create();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 2);
    }

    public function test_exam_generation_fails_without_templates(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 3])
            ->assertStatus(422);
    }

    public function test_submitting_an_answer_does_not_disclose_its_result(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $questionId = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions.0.id');

        $response = $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questionId,
            'answer_text' => 'عمر بن الخطاب',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_answered', true)
            ->assertJsonPath('data.answered_count', 1)
            ->assertJsonPath('data.total_questions', 2)
            ->assertJsonPath('data.remaining_count', 1)
            ->assertJsonPath('data.results_released', false);

        $this->assertArrayNotHasKey('score', $response->json('data'));
        $this->assertArrayNotHasKey('is_correct', $response->json('data'));

        // The score is stored even though it is not disclosed yet.
        $this->assertDatabaseHas('exam_answers', ['exam_question_id' => $questionId, 'score' => 100]);
    }

    public function test_an_exam_in_progress_hides_scores_and_correct_answers(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $questionId = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions.0.id');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questionId,
            'answer_text' => 'أبو هريرة', // wrong on purpose
        ])->assertOk();

        $question = $this->getJson("/api/v1/exams/{$examId}")
            ->assertOk()
            ->assertJsonPath('data.results_released', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.questions.0.is_answered', true)
            ->json('data.questions.0');

        $this->assertArrayNotHasKey('correct_answer', $question);
        $this->assertArrayNotHasKey('is_correct', $question);
        $this->assertArrayNotHasKey('score', $question);
        $this->assertArrayNotHasKey('score', $question['answer']);
    }

    public function test_completing_the_exam_releases_the_results_with_the_correct_answers(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $questions = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questions[0]['id'],
            'answer_text' => 'عمر بن الخطاب', // correct
        ])->assertOk();
        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questions[1]['id'],
            'answer_text' => 'أبو هريرة', // wrong
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results_released', true)
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.correct_count', 1)
            ->assertJsonPath('data.summary.incorrect_count', 1)
            ->assertJsonPath('data.summary.unanswered_count', 0)
            // The correct answer is released for every question, right or wrong.
            ->assertJsonPath('data.questions.0.is_correct', true)
            ->assertJsonPath('data.questions.0.correct_answer', 'عمر بن الخطاب')
            ->assertJsonPath('data.questions.1.is_correct', false)
            ->assertJsonPath('data.questions.1.correct_answer', 'عمر بن الخطاب')
            ->assertJsonPath('data.questions.1.score', 0);
    }

    public function test_voice_answers_reuse_the_transcript_comparison(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $questionId = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions.0.id');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questionId,
            'answer_text' => 'انما الاعمال بالنيات', // exact recitation
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.questions.0.is_correct', true);
    }

    public function test_unanswered_questions_count_as_zero_in_the_final_score(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $questions = $this->getJson("/api/v1/exams/{$examId}")->json('data.questions');

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $questions[0]['id'],
            'answer_text' => 'انما الاعمال بالنيات',
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.unanswered_count', 1);
    }

    public function test_user_cannot_access_another_users_exam(): void
    {
        $owner = User::factory()->create();
        $book = $this->setupBook();
        QuestionTemplate::factory()->create();
        Sanctum::actingAs($owner);
        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 1])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/exams/{$examId}")->assertStatus(403);
    }
}
