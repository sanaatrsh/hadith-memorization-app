<?php

namespace Tests\Feature\Database;

use App\Enums\ExamQuestionType;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\QuestionTemplate;
use App\Models\User;
use Database\Seeders\ArabicExamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArabicExamSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_arabic_question_bank_and_two_exams_idempotently(): void
    {
        $this->seed(ArabicExamSeeder::class);
        $this->seed(ArabicExamSeeder::class);

        $this->assertSame(9, QuestionTemplate::count());
        $this->assertSame(9, QuestionTemplate::where('is_active', true)->count());
        $this->assertSame(2, QuestionTemplate::where('type', ExamQuestionType::Voice)->count());

        // Each book has one exam per learner, so the two demo exams belong to
        // two demo learners.
        $this->assertSame(2, Exam::count());
        $this->assertSame(1, Exam::where('user_id', User::where('email', 'user@athar.test')->sole()->id)->count());
        $this->assertSame(1, Exam::where('user_id', User::where('email', 'learner2@athar.test')->sole()->id)->count());

        // 3 wordings in the completed exam, 2 in the one under way.
        $this->assertSame(5, ExamQuestion::count());
        // 5 items in the completed exam, 4 in the one under way.
        $this->assertSame(9, ExamAnswer::count());

        // Every wording is in Arabic and names no hadith: it is shared by all
        // the items under it.
        foreach (ExamQuestion::all() as $question) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $question->question_text);
            $this->assertStringNotContainsString('{', $question->question_text);
        }

        // Every item points at a hadith of the collection and carries that
        // hadith's own reference answer.
        foreach (ExamAnswer::with('hadith.book')->get() as $item) {
            $this->assertNotEmpty($item->correct_answer);
            $this->assertSame('الأربعون النووية', $item->hadith->book->title);
        }
    }

    public function test_one_wording_carries_a_different_answer_for_each_hadith(): void
    {
        $this->seed(ArabicExamSeeder::class);

        $narratorQuestion = ExamQuestion::where('question_text', 'من هو راوي هذا الحديث؟')->sole();

        $answers = $narratorQuestion->answers()->with('hadith')->get();

        $this->assertCount(2, $answers, 'One wording, asked about two hadiths.');
        $this->assertSame(
            ['إنما الأعمال بالنيات', 'الدين النصيحة'],
            $answers->pluck('hadith.title')->sort()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['عمر بن الخطاب', 'تميم الداري'],
            $answers->pluck('correct_answer')->all(),
        );
    }

    public function test_the_completed_exam_is_scored_the_way_the_api_scores_it(): void
    {
        $this->seed(ArabicExamSeeder::class);

        $exam = Exam::where('status', 'completed')->sole();

        // The exam's length is its number of items, not of wordings.
        $this->assertSame(5, $exam->question_count);
        $this->assertSame(5, $exam->items()->count());
        $this->assertSame(3, $exam->questions()->count());
        $this->assertNotNull($exam->completed_at);

        $scores = $exam->items()->pluck('score');

        $this->assertCount(5, $scores);
        $this->assertSame((int) round($scores->avg()), $exam->score);

        // The factual answers are matched token by token, and each carries its
        // hadith's stored answer in full, so they score 100.
        $narrator = ExamAnswer::whereRelation('hadith', 'title', 'إنما الأعمال بالنيات')->sole();
        $this->assertSame('عمر بن الخطاب', $narrator->correct_answer);
        $this->assertSame(100, $narrator->score);
        $this->assertTrue($narrator->is_correct);
        $this->assertSame('token_match', $narrator->evaluation_report['mode']);
        $this->assertSame([], $narrator->evaluation_report['missing_words']);
        $this->assertEquals(1, $narrator->evaluation_report['coverage']);

        // The fuller lineage «تميم بن أوس الداري» is still «تميم الداري».
        $lineage = ExamAnswer::where('correct_answer', 'تميم الداري')->sole();
        $this->assertTrue($lineage->is_correct);

        // The last recall answer drops several words, so it falls below the
        // passing mark and its report lists what was missing.
        $recall = ExamAnswer::whereRelation('hadith', 'title', 'كن في الدنيا كأنك غريب')->sole();
        $this->assertFalse($recall->is_correct);
        $this->assertLessThan((int) config('athar.scoring.passing'), $recall->score);
        $this->assertContains('لمرضك', $recall->evaluation_report['missing_words']);
    }

    public function test_the_in_progress_exam_has_unanswered_items_and_no_score(): void
    {
        $this->seed(ArabicExamSeeder::class);

        $exam = Exam::where('status', 'in_progress')->sole();

        $this->assertNull($exam->score);
        $this->assertNull($exam->completed_at);
        $this->assertSame(2, $exam->questions()->count());
        $this->assertSame(4, $exam->items()->count());

        // One item of each question is answered, the other is still open —
        // which is what an unfinished exam looks like once items, not
        // wordings, are what get answered.
        $items = $exam->items()->get();
        $this->assertSame(2, $items->filter(fn (ExamAnswer $item) => $item->isAnswered())->count());
        $this->assertSame(2, $items->reject(fn (ExamAnswer $item) => $item->isAnswered())->count());
    }
}
