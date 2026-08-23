<?php

namespace App\Actions\Exam;

use App\Enums\ExamQuestionType;
use App\Models\Book;
use App\Models\Exam;
use App\Models\Hadith;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds an exam from stored question templates and the selected book's
 * content only. Gemini is never used to invent questions.
 *
 * A hadith never gets more than one question, and the question templates
 * rotate so the types stay varied. Without an explicit question count the
 * exam stays short: it covers the hadiths added to the book today, so long as
 * there is at least one, or otherwise the first
 * `athar.exams.default_question_count` hadiths of the book — never the whole
 * book by default. Passing a question count explicitly always narrows the
 * exam to that many hadiths of the book instead.
 */
class GenerateExam
{
    public function execute(User $user, Book $book, ?int $questionCount = null): Exam
    {
        $templates = QuestionTemplate::where('is_active', true)->get();

        abort_if($templates->isEmpty(), 422, 'No active question templates are configured.');

        $hadiths = $book->hadiths()
            ->where('is_active', true)
            ->with('narrator')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        abort_if($hadiths->isEmpty(), 422, 'The selected book has no active hadiths.');

        $hadiths = $this->selectHadiths($hadiths, $questionCount);

        // Rotating a shuffled template list keeps every type in play while
        // still giving each hadith exactly one question.
        $templates = $templates->shuffle()->values();

        return DB::transaction(function () use ($user, $book, $templates, $hadiths) {
            $exam = Exam::create([
                'user_id' => $user->id,
                'book_id' => $book->id,
                'question_count' => $hadiths->count(),
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            foreach ($hadiths->values() as $index => $hadith) {
                $template = $this->templateFor($templates, $hadith, $index);

                $exam->questions()->create([
                    'hadith_id' => $hadith->id,
                    'question_template_id' => $template->id,
                    'type' => $template->type,
                    'question_text' => $this->renderPrompt($template->prompt_template, $hadith),
                    'correct_answer' => $this->deriveAnswer($template, $hadith) ?: $hadith->text,
                    'sort_order' => $index,
                ]);
            }

            $exam->update(['question_count' => $exam->questions()->count()]);

            return $exam->load('questions');
        });
    }

    /**
     * The rotating template for this position, skipped forward while it would
     * ask about something this hadith does not carry (no narrator, no takhrij,
     * no intro) so a question is never unanswerable.
     *
     * @param  Collection<int,QuestionTemplate>  $templates
     */
    private function templateFor(Collection $templates, Hadith $hadith, int $index): QuestionTemplate
    {
        $count = $templates->count();

        for ($offset = 0; $offset < $count; $offset++) {
            /** @var QuestionTemplate $candidate */
            $candidate = $templates[($index + $offset) % $count];

            if ($this->deriveAnswer($candidate, $hadith) !== '') {
                return $candidate;
            }
        }

        return $templates[$index % $count];
    }

    /**
     * An explicit count narrows the book to that many distinct hadiths. Left
     * unset, the exam stays short: the hadiths added today if there are any,
     * else a small default slice of the book — the whole book is never the
     * default.
     *
     * @param  Collection<int,Hadith>  $hadiths
     * @return Collection<int,Hadith>
     */
    private function selectHadiths(Collection $hadiths, ?int $questionCount): Collection
    {
        if ($questionCount !== null) {
            return $this->limitTo($hadiths, $questionCount);
        }

        $today = $hadiths->filter(fn (Hadith $hadith) => $hadith->created_at?->isToday());

        $defaultCount = (int) config('athar.exams.default_question_count', 6);

        return $this->limitTo($today->isNotEmpty() ? $today->values() : $hadiths, $defaultCount);
    }

    /**
     * @param  Collection<int,Hadith>  $hadiths
     * @return Collection<int,Hadith>
     */
    private function limitTo(Collection $hadiths, int $count): Collection
    {
        if ($count >= $hadiths->count()) {
            return $hadiths->values();
        }

        return $hadiths
            ->shuffle()
            ->take($count)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function renderPrompt(string $template, Hadith $hadith): string
    {
        return strtr($template, [
            '{title}' => $hadith->title,
            '{source}' => (string) $hadith->source,
            '{narrator}' => (string) $hadith->narrator?->name,
            '{intro}' => (string) $hadith->intro,
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

        if (Str::contains($prompt, ['مصدر', 'المصدر', 'مخرج', 'التخريج', 'source'])) {
            return (string) $hadith->source;
        }

        if (Str::contains($prompt, ['مقدمة', 'مقدم', 'intro'])) {
            return (string) $hadith->intro;
        }

        // Complete-the-hadith and similar recall questions.
        return $hadith->text;
    }
}
