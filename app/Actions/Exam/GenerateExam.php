<?php

namespace App\Actions\Exam;

use App\Enums\ExamQuestionType;
use App\Models\Book;
use App\Models\Exam;
use App\Models\Hadith;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds an exam from stored question templates and the selected book's
 * content only. Gemini is never used to invent questions.
 */
class GenerateExam
{
    public function execute(User $user, Book $book, int $questionCount): Exam
    {
        $templates = QuestionTemplate::where('is_active', true)->get();

        abort_if($templates->isEmpty(), 422, 'No active question templates are configured.');

        $hadiths = $book->hadiths()
            ->where('is_active', true)
            ->with('narrator')
            ->get();

        abort_if($hadiths->isEmpty(), 422, 'The selected book has no active hadiths.');

        return DB::transaction(function () use ($user, $book, $questionCount, $templates, $hadiths) {
            $exam = Exam::create([
                'user_id' => $user->id,
                'book_id' => $book->id,
                'question_count' => $questionCount,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            for ($i = 0; $i < $questionCount; $i++) {
                /** @var Hadith $hadith */
                $hadith = $hadiths->random();
                /** @var QuestionTemplate $template */
                $template = $templates->random();

                $exam->questions()->create([
                    'hadith_id' => $hadith->id,
                    'question_template_id' => $template->id,
                    'type' => $template->type,
                    'question_text' => $this->renderPrompt($template->prompt_template, $hadith),
                    'correct_answer' => $this->deriveAnswer($template, $hadith),
                    'sort_order' => $i,
                ]);
            }

            $exam->update(['question_count' => $exam->questions()->count()]);

            return $exam->load('questions');
        });
    }

    private function renderPrompt(string $template, Hadith $hadith): string
    {
        return strtr($template, [
            '{title}' => $hadith->title,
            '{source}' => (string) $hadith->source,
            '{narrator}' => (string) $hadith->narrator?->name,
            '{opening}' => Str::words($hadith->text, 5, '…'),
        ]);
    }

    private function deriveAnswer(QuestionTemplate $template, Hadith $hadith): string
    {
        if ($template->type === ExamQuestionType::Voice) {
            return $hadith->text;
        }

        $prompt = $template->prompt_template;

        if (Str::contains($prompt, ['راوي', 'الراوي', 'narrator'])) {
            return (string) $hadith->narrator?->name;
        }

        if (Str::contains($prompt, ['مصدر', 'المصدر', 'source'])) {
            return (string) $hadith->source;
        }

        // Complete-the-hadith and similar recall questions.
        return $hadith->text;
    }
}
