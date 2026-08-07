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

        $this->assertSame(11, QuestionTemplate::count());
        $this->assertSame(11, QuestionTemplate::where('is_active', true)->count());
        $this->assertSame(3, QuestionTemplate::where('type', ExamQuestionType::Voice)->count());

        $learner = User::where('email', 'user@athar.test')->sole();

        $this->assertSame(2, Exam::where('user_id', $learner->id)->count());
        $this->assertSame(9, ExamQuestion::count());

        // Every question is in Arabic, points at a hadith and carries its answer.
        foreach (ExamQuestion::with('hadith')->get() as $question) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $question->question_text);
            $this->assertStringNotContainsString('{', $question->question_text);
            $this->assertNotEmpty($question->correct_answer);
            $this->assertSame('الأربعون النووية', $question->hadith->book->title);
        }
    }

    public function test_the_completed_exam_is_scored_the_way_the_api_scores_it(): void
    {
        $this->seed(ArabicExamSeeder::class);

        $exam = Exam::where('status', 'completed')->sole();

        $this->assertSame(5, $exam->question_count);
        $this->assertSame(5, $exam->questions()->count());
        $this->assertNotNull($exam->completed_at);

        $scores = ExamAnswer::whereIn('exam_question_id', $exam->questions()->pluck('id'))->pluck('score');

        $this->assertCount(5, $scores);
        $this->assertSame((int) round($scores->avg()), $exam->score);

        // The two factual questions are exact-matched, so they score 100.
        $narrator = $exam->questions()->where('sort_order', 0)->sole();
        $this->assertSame('عمر بن الخطاب', $narrator->correct_answer);
        $this->assertSame(100, $narrator->answer->score);
        $this->assertTrue($narrator->answer->is_correct);
        $this->assertSame(['mode' => 'exact_match'], $narrator->answer->evaluation_report);

        // The last recall answer drops several words, so it falls below the
        // passing mark and its report lists what was missing.
        $recall = $exam->questions()->where('sort_order', 4)->sole();
        $this->assertFalse($recall->answer->is_correct);
        $this->assertLessThan((int) config('athar.scoring.passing'), $recall->answer->score);
        $this->assertContains('لمرضك', $recall->answer->evaluation_report['missing_words']);
    }

    public function test_the_in_progress_exam_has_unanswered_questions_and_no_score(): void
    {
        $this->seed(ArabicExamSeeder::class);

        $exam = Exam::where('status', 'in_progress')->sole();

        $this->assertNull($exam->score);
        $this->assertNull($exam->completed_at);
        $this->assertSame(4, $exam->questions()->count());
        $this->assertSame(2, $exam->questions()->has('answer')->count());
        $this->assertSame(2, $exam->questions()->doesntHave('answer')->count());
    }
}
