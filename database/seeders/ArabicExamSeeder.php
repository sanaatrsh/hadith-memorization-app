<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\Hadith;
use App\Models\QuestionTemplate;
use App\Models\User;
use App\Models\UserBook;
use App\Services\ExamAnswerEvaluator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the Arabic question bank and two worked exams over الأربعون النووية:
 * one finished with a score, one still in progress.
 *
 * Questions are rendered from the stored templates exactly the way
 * GenerateExam renders them at runtime, and the submitted answers are scored by
 * the real ExamAnswerEvaluator — no score or evaluation report is hand-written,
 * so the demo data cannot drift from what the API actually produces.
 *
 * It is idempotent: templates are keyed by their prompt, an exam by
 * (user, book, status), its questions by (exam, sort_order) and each answer by
 * its question.
 */
class ArabicExamSeeder extends Seeder
{
    public function run(ExamAnswerEvaluator $evaluator): void
    {
        /** @var array{book:string, templates:array<int,array<string,string>>, exams:array<int,array<string,mixed>>} $catalogue */
        $catalogue = require __DIR__.'/data/arabic_exams.php';

        $templates = [];

        foreach ($catalogue['templates'] as $template) {
            $templates[$template['prompt']] = QuestionTemplate::updateOrCreate(
                ['prompt_template' => $template['prompt']],
                ['type' => $template['type'], 'is_active' => true],
            );
        }

        $book = $this->book($catalogue['book']);
        $learner = $this->learner();

        UserBook::updateOrCreate(
            ['user_id' => $learner->id, 'book_id' => $book->id],
            ['started_at' => now()->subDays(7)],
        );

        $hadiths = $book->hadiths()->with('narrator')->get()->keyBy('title');

        foreach ($catalogue['exams'] as $entry) {
            $exam = Exam::updateOrCreate(
                ['user_id' => $learner->id, 'book_id' => $book->id, 'status' => $entry['status']],
                [
                    'question_count' => count($entry['questions']),
                    'started_at' => now()->subDays($entry['started_days_ago']),
                    'completed_at' => $entry['completed_days_ago'] === null
                        ? null
                        : now()->subDays($entry['completed_days_ago']),
                ],
            );

            foreach (array_values($entry['questions']) as $sortOrder => $question) {
                /** @var Hadith $hadith */
                $hadith = $hadiths[$question['hadith']];
                $template = $templates[$question['template']];

                $examQuestion = ExamQuestion::updateOrCreate(
                    ['exam_id' => $exam->id, 'sort_order' => $sortOrder],
                    [
                        'hadith_id' => $hadith->id,
                        'question_template_id' => $template->id,
                        'type' => $template->type,
                        'question_text' => $this->renderPrompt($template->prompt_template, $hadith),
                        'correct_answer' => $this->answer($question['answer_from'], $hadith),
                    ],
                );

                if ($question['submitted'] === null) {
                    continue;
                }

                $result = $evaluator->evaluate($examQuestion, $question['submitted']);

                ExamAnswer::updateOrCreate(
                    ['exam_question_id' => $examQuestion->id],
                    [
                        'answer_text' => $question['submitted'],
                        'score' => $result['score'],
                        'is_correct' => $result['is_correct'],
                        'evaluation_report' => $result['report'],
                    ],
                );
            }

            // The final score is the average of the answers, as ExamController
            // computes it when the learner completes the exam.
            if ($exam->status === 'completed') {
                $scores = ExamAnswer::whereIn('exam_question_id', $exam->questions()->pluck('id'))->pluck('score');

                $exam->update(['score' => $scores->isEmpty() ? 0 : (int) round($scores->avg())]);
            }
        }
    }

    /**
     * The exams need the collection in place; seed it when it is missing so this
     * seeder can also be run on its own.
     */
    private function book(string $title): Book
    {
        $book = Book::where('title', $title)->first();

        if ($book === null || $book->hadiths()->doesntExist()) {
            $this->call(NawawiFortySeeder::class);

            $book = Book::where('title', $title)->sole();
        }

        return $book;
    }

    /**
     * The same demo learner ArabicDemoSeeder owns; created here only so this
     * seeder stands alone.
     */
    private function learner(): User
    {
        return User::firstOrCreate(
            ['email' => 'user@athar.test'],
            [
                'name' => 'سناء أحمد',
                'password' => Hash::make('password'),
                'birth_date' => '1998-05-20',
                'role' => UserRole::User,
                'is_active' => true,
            ],
        );
    }

    /** Mirrors GenerateExam::renderPrompt so seeded questions read like generated ones. */
    private function renderPrompt(string $template, Hadith $hadith): string
    {
        return strtr($template, [
            '{title}' => $hadith->title,
            '{source}' => (string) $hadith->source,
            '{narrator}' => (string) $hadith->narrator?->name,
            '{opening}' => Str::words($hadith->text, 5, '…'),
        ]);
    }

    private function answer(string $answerFrom, Hadith $hadith): string
    {
        return match ($answerFrom) {
            'narrator' => (string) $hadith->narrator?->name,
            'source' => (string) $hadith->source,
            default => $hadith->text,
        };
    }
}
