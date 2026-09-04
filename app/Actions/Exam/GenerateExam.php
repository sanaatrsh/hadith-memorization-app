<?php

namespace App\Actions\Exam;

use App\Enums\ExamQuestionType;
use App\Models\Book;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Hadith;
use App\Models\QuestionTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds a book's exam from the stored question bank and the book's own
 * content. Gemini is never used to invent questions.
 *
 * The exam is normalized: a question is stored once for its wording («من هو
 * راوي هذا الحديث؟», «أكمل نص هذا الحديث», «اتل هذا الحديث من حفظك») and gets
 * one answer row per hadith it is asked about, each carrying that hadith's own
 * reference answer. The wording is therefore hadith-agnostic — the item names
 * the hadith, by its number inside the book.
 *
 * Every book has one exam per learner: generating it again resets that exam
 * rather than piling up another one.
 *
 * A hadith is asked about exactly once, and the question bank rotates so the
 * wordings stay varied. Without an explicit question count the exam stays
 * short: the hadiths added to the book today if there are any, else the
 * `athar.exams.default_question_count` slice of the book — never the whole
 * book. Passing a count explicitly narrows it to that many hadiths instead.
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
            ->orderBy('number_in_book')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        abort_if($hadiths->isEmpty(), 422, 'The selected book has no active hadiths.');

        $hadiths = $this->selectHadiths($hadiths, $questionCount);

        // Rotating a shuffled bank keeps every wording in play while still
        // giving each hadith exactly one question.
        $templates = $templates->shuffle()->values();

        return DB::transaction(function () use ($user, $book, $templates, $hadiths) {
            $exam = $this->resetExam($user, $book);

            $this->fill($exam, $templates, $hadiths);

            $exam->update(['question_count' => $exam->items()->count()]);

            return $exam->load(['questions.answers.hadith', 'book']);
        });
    }

    /**
     * The book's exam for this learner, emptied out. Retaking a book's exam
     * reuses the same row — a book has one exam — so the previous questions
     * and answers are cleared instead of a second exam being created.
     */
    private function resetExam(User $user, Book $book): Exam
    {
        $exam = Exam::firstOrNew([
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);

        $exam->fill([
            'question_count' => 0,
            'status' => 'in_progress',
            'started_at' => now(),
            'completed_at' => null,
            'score' => null,
        ])->save();

        // Answers cascade from their question.
        ExamQuestion::where('exam_id', $exam->id)->delete();

        return $exam;
    }

    /**
     * Writes the questions and their per-hadith items.
     *
     * Hadiths are grouped by the wording they draw, so a wording asked about
     * three hadiths is one question row with three answer rows.
     *
     * @param  Collection<int,QuestionTemplate>  $templates
     * @param  Collection<int,Hadith>  $hadiths
     */
    private function fill(Exam $exam, Collection $templates, Collection $hadiths): void
    {
        /** @var Collection<int,array{template:QuestionTemplate, hadiths:array<int,Hadith>}> $grouped */
        $grouped = collect();

        foreach ($hadiths->values() as $index => $hadith) {
            $template = $this->templateFor($templates, $hadith, $index);

            $bucket = $grouped->get($template->id, ['template' => $template, 'hadiths' => []]);
            $bucket['hadiths'][] = $hadith;

            $grouped->put($template->id, $bucket);
        }

        $itemOrder = 0;

        foreach ($grouped->values() as $questionOrder => $bucket) {
            /** @var QuestionTemplate $template */
            $template = $bucket['template'];

            $question = $exam->questions()->create([
                'question_template_id' => $template->id,
                'type' => $template->type,
                'question_text' => $this->questionText($template->prompt_template),
                'sort_order' => $questionOrder,
            ]);

            foreach ($bucket['hadiths'] as $hadith) {
                $question->answers()->create([
                    'hadith_id' => $hadith->id,
                    'correct_answer' => $this->deriveAnswer($template, $hadith) ?: $hadith->text,
                    'sort_order' => $itemOrder++,
                ]);
            }
        }
    }

    /**
     * The rotating question for this position, skipped forward while it would
     * ask about something this hadith does not carry (no narrator, no takhrij,
     * no intro) so an item is never unanswerable.
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
            ->sortBy([['number_in_book', 'asc'], ['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * The stored wording, hadith-agnostic.
     *
     * The question is shared by every hadith it is asked about, so a template
     * that still interpolates one hadith ({title}, {opening}, …) cannot keep
     * those placeholders: they are dropped along with the fragment that
     * introduced them («الذي عنوانه:»), which reads as dangling without one.
     * Which hadith an item is about travels with the item, not the wording.
     */
    private function questionText(string $template): string
    {
        $text = preg_replace(
            '/\s*(?:من|الذي|التي)?\s*(?:عنوانه|مطلعه|يبدأ|تبدأ|رواه)?\s*(?:بـ)?\s*:?\s*\{[a-z_]+\}/iu',
            '',
            $template,
        ) ?? $template;

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        // Trimmed by pattern, not by trim()'s byte-wise charlist, which would
        // slice a multibyte «،» in half and hand back broken UTF-8.
        $text = preg_replace('/^[\s:،]+|[\s:،]+$/u', '', $text) ?? $text;

        return $text === '' ? trim($template) : $text;
    }

    private function deriveAnswer(QuestionTemplate $template, Hadith $hadith): string
    {
        if ($template->type === ExamQuestionType::Voice) {
            return $hadith->text;
        }

        // Match on the wording only. The placeholders are part of the
        // sentence, not the question: "اكتب الحديث الذي رواه {narrator}" asks
        // for the matn, but the literal "narrator" inside the placeholder made
        // it answer with the narrator's name instead.
        $prompt = preg_replace('/\{[a-z_]+\}/i', ' ', $template->prompt_template) ?? '';

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
