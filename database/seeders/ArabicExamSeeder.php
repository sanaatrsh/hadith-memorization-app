<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ExamQuestionType;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\Hadith;
use App\Models\QuestionTemplate;
use App\Models\User;
use App\Services\ExamAnswerEvaluator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the Arabic question bank and two worked exams over الأربعون النووية:
 * one finished with a score, one still in progress.
 *
 * The structure mirrors what GenerateExam produces at runtime: a question is
 * stored once for its wording, and gets one answer row per hadith it is asked
 * about, each carrying that hadith's own reference answer. Every book has one
 * exam per learner, so the two exams belong to two different learners.
 *
 * Submitted answers are scored by the real ExamAnswerEvaluator — no score or
 * evaluation report is hand-written, so the demo data cannot drift from what
 * the API actually produces.
 *
 * It is idempotent: templates are keyed by their prompt, an exam by
 * (user, book), its questions by (exam, sort_order) and each item by
 * (question, hadith).
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
        $learners = [
            'primary' => $this->learner('user@athar.test', 'سناء أحمد', '1998-05-20'),
            'secondary' => $this->learner('learner2@athar.test', 'يوسف حمود', '2001-11-02'),
        ];

        $hadiths = $book->hadiths()->with('narrator')->get()->keyBy('title');

        foreach ($catalogue['exams'] as $entry) {
            $learner = $learners[$entry['learner']];

            $exam = Exam::updateOrCreate(
                ['user_id' => $learner->id, 'book_id' => $book->id],
                [
                    'status' => $entry['status'],
                    'started_at' => now()->subDays($entry['started_days_ago']),
                    'completed_at' => $entry['completed_days_ago'] === null
                        ? null
                        : now()->subDays($entry['completed_days_ago']),
                ],
            );

            $itemOrder = 0;

            foreach (array_values($entry['questions']) as $sortOrder => $question) {
                $template = $templates[$question['template']];

                $examQuestion = ExamQuestion::updateOrCreate(
                    ['exam_id' => $exam->id, 'sort_order' => $sortOrder],
                    [
                        'question_template_id' => $template->id,
                        'type' => $template->type,
                        // The stored wording names no hadith — it is shared by
                        // every item under it.
                        'question_text' => $template->prompt_template,
                    ],
                );

                foreach ($question['answers'] as $item) {
                    /** @var Hadith $hadith */
                    $hadith = $hadiths[$item['hadith']];

                    $answer = ExamAnswer::updateOrCreate(
                        ['exam_question_id' => $examQuestion->id, 'hadith_id' => $hadith->id],
                        [
                            'correct_answer' => $this->answer($template, $hadith),
                            'sort_order' => $itemOrder++,
                        ],
                    );

                    if ($item['submitted'] === null) {
                        continue;
                    }

                    $result = $evaluator->evaluateAnswer(
                        $examQuestion->question_text,
                        $template->type,
                        (string) $answer->correct_answer,
                        $item['submitted'],
                    );

                    $answer->update([
                        'answer_text' => $item['submitted'],
                        'score' => $result['score'],
                        'is_correct' => $result['is_correct'],
                        'evaluation_report' => $result['report'],
                        'answered_at' => $exam->started_at,
                    ]);
                }
            }

            // The exam's length is its number of items, and the final score is
            // their average — exactly as ExamController computes both.
            $exam->update(['question_count' => $exam->items()->count()]);

            if ($exam->status === 'completed') {
                $items = max($exam->question_count, 1);

                $exam->update(['score' => (int) round((int) $exam->items()->sum('score') / $items)]);
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
     * The demo learners. The first is the one ArabicDemoSeeder owns; both are
     * created here only so this seeder stands alone.
     */
    private function learner(string $email, string $name, string $birthDate): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'birth_date' => $birthDate,
                'role' => UserRole::User,
                'is_active' => true,
            ],
        );
    }

    /**
     * Mirrors GenerateExam::deriveAnswer: the wording decides which part of the
     * hadith answers it, and the answer is per hadith.
     */
    private function answer(QuestionTemplate $template, Hadith $hadith): string
    {
        if ($template->type === ExamQuestionType::Voice) {
            return $hadith->text;
        }

        $prompt = $template->prompt_template;

        return match (true) {
            str_contains($prompt, 'راوي') => (string) $hadith->narrator?->name,
            str_contains($prompt, 'مصدر') => (string) $hadith->source,
            str_contains($prompt, 'مقدمة') => (string) $hadith->intro,
            default => $hadith->text,
        };
    }
}
