<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Hadith;
use App\Models\Narrator;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An exam is normalized: a question is stored once for its wording and gets
 * one answer row — one item — per hadith it is asked about. An item is what
 * the learner answers, so `items[].id` is what an answer is submitted against,
 * and the exam's length is its number of items.
 */
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function items(int $examId): array
    {
        return $this->getJson("/api/v1/exams/{$examId}")->json('data.items');
    }

    public function test_it_lists_only_the_authenticated_users_exams_newest_first(): void
    {
        Sanctum::actingAs($user = User::factory()->create());
        $first = $this->setupBook();
        $second = $this->setupBook();
        QuestionTemplate::factory()->create();

        // A book has one exam, so two exams means two books.
        $olderId = $this->postJson('/api/v1/exams', ['book_id' => $first->id, 'question_count' => 1])->json('data.id');
        $newerId = $this->postJson('/api/v1/exams', ['book_id' => $second->id, 'question_count' => 1])->json('data.id');

        $otherBook = $this->setupBook();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/exams', ['book_id' => $otherBook->id, 'question_count' => 1]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/exams')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newerId)
            ->assertJsonPath('data.1.id', $olderId)
            ->assertJsonPath('data.0.book_title', $second->title);

        $this->assertArrayNotHasKey('questions', $response->json('data.0'));
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
    }

    public function test_a_book_has_one_exam_which_opening_it_again_resets(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[0]['id'],
            'answer_text' => 'عمر بن الخطاب',
        ])->assertOk();

        // Opening it again is the same exam, emptied out — not a second one.
        $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $examId)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseMissing('exam_answers', ['id' => $items[0]['id']]);
        $this->assertDatabaseCount('exam_answers', 2);
    }

    public function test_one_question_covers_many_hadiths_each_with_its_own_answer(): void
    {
        // The point of the structure: «من هو راوي هذا الحديث؟» is stored once,
        // and the correct answer varies by hadith.
        Sanctum::actingAs(User::factory()->create());
        $book = Book::factory()->create();

        $narrators = ['عمر بن الخطاب', 'أبو هريرة', 'تميم الداري'];

        foreach ($narrators as $index => $name) {
            Hadith::factory()->create([
                'book_id' => $book->id,
                'number_in_book' => $index + 1,
                'narrator_id' => Narrator::factory()->create(['name' => $name])->id,
                'text' => 'انما الاعمال بالنيات',
            ]);
        }

        QuestionTemplate::factory()->narrator()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            // Three items drawn from a single wording.
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonPath('data.distinct_question_count', 1)
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonCount(3, 'data.questions.0.answers')
            ->assertJsonCount(3, 'data.items');

        $this->assertDatabaseCount('exam_questions', 1);
        $this->assertDatabaseCount('exam_answers', 3);

        $questionId = $response->json('data.questions.0.id');
        $items = collect($response->json('data.items'));

        // Every item hangs off that one question, and names its own hadith.
        $this->assertSame([$questionId], $items->pluck('exam_question_id')->unique()->all());
        $this->assertCount(3, $items->pluck('hadith_id')->unique());
        $this->assertEqualsCanonicalizing([1, 2, 3], $items->pluck('hadith.number_in_book')->all());

        // The wording names no hadith — that is what lets it be shared.
        $wording = $response->json('data.questions.0.question_text');
        $this->assertStringNotContainsString('{', $wording);

        foreach ($narrators as $name) {
            $this->assertStringNotContainsString($name, $wording);
        }

        // ...and each hadith's own answer is stored against its item.
        $this->assertEqualsCanonicalizing(
            $narrators,
            $this->completeAndRead($response->json('data.id'))->pluck('correct_answer')->all(),
        );
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function completeAndRead(int $examId): Collection
    {
        return collect(
            $this->postJson("/api/v1/exams/{$examId}/complete")->json('data.items')
        );
    }

    public function test_it_never_asks_about_the_same_hadith_twice(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 4);
        QuestionTemplate::factory()->create();
        QuestionTemplate::factory()->narrator()->create();

        // No question_count: all 4 hadiths were added today, and that is
        // under the default cap, so the exam covers all of them.
        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.question_count', 4)
            ->assertJsonCount(4, 'data.items');

        $hadithIds = collect($response->json('data.items'))->pluck('hadith_id');

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
            ->assertJsonCount(6, 'data.items');

        $hadithIds = collect($response->json('data.items'))->pluck('hadith_id');
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

        $hadithIds = collect($response->json('data.items'))->pluck('hadith_id');
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
            ->assertJsonCount(6, 'data.items');

        $hadithIds = collect($response->json('data.items'))->pluck('hadith_id');
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
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 5);
        QuestionTemplate::factory()->create();

        $response = $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 3])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonCount(3, 'data.items');

        $hadithIds = collect($response->json('data.items'))->pluck('hadith_id');

        $this->assertCount(3, $hadithIds->unique());
    }

    public function test_a_count_larger_than_the_book_is_capped_at_its_hadiths(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 3);
        QuestionTemplate::factory()->create();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 25])
            ->assertCreated()
            ->assertJsonPath('data.question_count', 3)
            ->assertJsonCount(3, 'data.items');

        $this->assertDatabaseCount('exam_answers', 3);
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

    public function test_a_books_exam_can_be_reached_by_the_book(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        // Nothing opened yet.
        $this->getJson("/api/v1/books/{$book->id}/exam")->assertStatus(404);

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');

        $this->getJson("/api/v1/books/{$book->id}/exam")
            ->assertOk()
            ->assertJsonPath('data.id', $examId)
            ->assertJsonCount(2, 'data.items');

        // Another learner has no exam on that book yet.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/books/{$book->id}/exam")->assertStatus(404);
    }

    public function test_exam_generation_fails_without_templates(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook();

        $this->postJson('/api/v1/exams', ['book_id' => $book->id, 'question_count' => 3])
            ->assertStatus(422);
    }

    public function test_submitting_an_answer_does_not_disclose_its_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $response = $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_answer_id' => $items[0]['id'],
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
        $this->assertDatabaseHas('exam_answers', ['id' => $items[0]['id'], 'score' => 100]);
    }

    public function test_an_item_can_be_named_by_its_question_and_hadith(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $items[1]['exam_question_id'],
            'hadith_id' => $items[1]['hadith_id'],
            'answer_text' => 'عمر بن الخطاب',
        ])
            ->assertOk()
            ->assertJsonPath('data.exam_answer_id', $items[1]['id']);

        $this->assertDatabaseHas('exam_answers', ['id' => $items[1]['id'], 'score' => 100]);
        $this->assertDatabaseHas('exam_answers', ['id' => $items[0]['id'], 'answer_text' => null]);
    }

    public function test_a_bare_question_id_is_refused_when_it_covers_several_hadiths(): void
    {
        // Guessing which hadith was meant would look exactly like a wrong
        // grade, which is the failure this whole flow was stuck on.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 3);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'exam_question_id' => $items[0]['exam_question_id'],
            'answer_text' => 'عمر بن الخطاب',
        ])->assertStatus(422);

        $this->assertDatabaseHas('exam_answers', ['id' => $items[0]['id'], 'answer_text' => null]);
    }

    public function test_an_exam_in_progress_hides_scores_and_correct_answers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[0]['id'],
            'answer_text' => 'أبو هريرة', // wrong on purpose
        ])->assertOk();

        $item = $this->getJson("/api/v1/exams/{$examId}")
            ->assertOk()
            ->assertJsonPath('data.results_released', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.items.0.is_answered', true)
            ->json('data.items.0');

        $this->assertArrayNotHasKey('correct_answer', $item);
        $this->assertArrayNotHasKey('is_correct', $item);
        $this->assertArrayNotHasKey('score', $item);
        $this->assertArrayNotHasKey('evaluation_report', $item);
        // What they typed is echoed back — only the verdict is withheld.
        $this->assertSame('أبو هريرة', $item['answer_text']);
    }

    public function test_completing_the_exam_releases_the_results_with_the_correct_answers(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[0]['id'],
            'answer_text' => 'عمر بن الخطاب', // correct
        ])->assertOk();
        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[1]['id'],
            'answer_text' => 'أبو هريرة', // wrong
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.results_released', true)
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.total_questions', 2)
            ->assertJsonPath('data.summary.correct_count', 1)
            ->assertJsonPath('data.summary.incorrect_count', 1)
            ->assertJsonPath('data.summary.unanswered_count', 0)
            // The correct answer is released for every item, right or wrong.
            ->assertJsonPath('data.items.0.is_correct', true)
            ->assertJsonPath('data.items.0.correct_answer', 'عمر بن الخطاب')
            ->assertJsonPath('data.items.1.is_correct', false)
            ->assertJsonPath('data.items.1.correct_answer', 'عمر بن الخطاب')
            ->assertJsonPath('data.items.1.score', 0)
            // ...and through the nested view of the same rows.
            ->assertJsonPath('data.questions.0.answers.0.correct_answer', 'عمر بن الخطاب');
    }

    public function test_voice_answers_reuse_the_transcript_comparison(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[0]['id'],
            'answer_text' => 'انما الاعمال بالنيات', // exact recitation
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 100)
            ->assertJsonPath('data.items.0.is_correct', true);
    }

    public function test_unanswered_items_count_as_zero_in_the_final_score(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->voice()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/answers", [
            'item_id' => $items[0]['id'],
            'answer_text' => 'انما الاعمال بالنيات',
        ])->assertOk();

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.unanswered_count', 1);
    }

    public function test_all_answers_can_be_submitted_with_the_completion_request(): void
    {
        // How the app submits: one POST to /complete carrying every answer.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/complete", [
            'answers' => [
                ['item_id' => $items[0]['id'], 'answer' => 'عمر بن الخطاب'],
                ['item_id' => $items[1]['id'], 'answer' => 'أبو هريرة'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.score', 50)
            ->assertJsonPath('data.summary.answered_count', 2)
            ->assertJsonPath('data.summary.unanswered_count', 0)
            ->assertJsonPath('data.summary.correct_count', 1)
            ->assertJsonPath('data.items.0.is_correct', true)
            ->assertJsonPath('data.items.1.is_correct', false);
    }

    public function test_the_canonical_key_names_are_accepted_on_completion_too(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/complete", [
            'answers' => [
                ['exam_answer_id' => $items[0]['id'], 'answer_text' => 'عمر بن الخطاب'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.score', 100);
    }

    public function test_a_question_id_that_covers_one_hadith_still_resolves_on_completion(): void
    {
        // What a client built against the previous shape sends: the id it read
        // off the exam, under the old key name.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/complete", [
            'answers' => [
                ['question_id' => $items[0]['exam_question_id'], 'answer' => 'عمر بن الخطاب'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.score', 100);
    }

    public function test_an_answer_naming_an_item_outside_the_exam_is_refused(): void
    {
        // Dropping it silently would look exactly like a wrong grade, which is
        // the failure this whole flow was stuck on.
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');

        $this->postJson("/api/v1/exams/{$examId}/complete", [
            'answers' => [
                ['item_id' => 99999, 'answer' => 'عمر بن الخطاب'],
            ],
        ])->assertStatus(422);

        // The exam is untouched: nothing graded, nothing finalized.
        $this->assertDatabaseHas('exams', ['id' => $examId, 'status' => 'in_progress']);
        $this->assertDatabaseHas('exam_answers', ['exam_question_id' => $this->items($examId)[0]['exam_question_id'], 'answer_text' => null]);
    }

    public function test_one_bad_reference_grades_none_of_the_batch(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 2);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');
        $items = $this->items($examId);

        $this->postJson("/api/v1/exams/{$examId}/complete", [
            'answers' => [
                ['item_id' => $items[0]['id'], 'answer' => 'عمر بن الخطاب'],
                ['item_id' => 99999, 'answer' => 'أبو هريرة'],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('exams', ['id' => $examId, 'status' => 'in_progress']);
        $this->assertDatabaseHas('exam_answers', ['id' => $items[0]['id'], 'answer_text' => null]);
    }

    public function test_completing_without_a_body_still_works(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $book = $this->setupBook(hadiths: 1);
        QuestionTemplate::factory()->narrator()->create();

        $examId = $this->postJson('/api/v1/exams', ['book_id' => $book->id])->json('data.id');

        $this->postJson("/api/v1/exams/{$examId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.score', 0)
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
