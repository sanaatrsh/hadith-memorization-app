<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\ExamAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Shows how each item of an exam was actually graded.
 *
 * The app renders the answer the learner typed from its own state, so a wrong
 * verdict on screen can mean three different things: the answer never reached
 * the server, it was graded by an older build, or the grader really did reject
 * it. The stored row tells them apart — `mode` says which grader ran, and
 * `answer_text` says what the server received.
 */
class InspectExamAnswers extends Command
{
    protected $signature = 'athar:exam-answers {exam? : Exam id; defaults to the most recent}';

    protected $description = 'Show how an exam\'s answers were graded, and by what';

    public function handle(): int
    {
        $exam = $this->argument('exam')
            ? Exam::find($this->argument('exam'))
            : Exam::latest('id')->first();

        if ($exam === null) {
            $this->components->error('No exam found.');

            return self::FAILURE;
        }

        $exam->load(['user', 'book']);

        /** @var Collection<int,ExamAnswer> $items */
        $items = $exam->items()->with(['question', 'hadith'])->get();

        $this->components->info("Exam #{$exam->id}");
        $this->components->twoColumnDetail('User', (string) $exam->user?->email);
        $this->components->twoColumnDetail('Book', (string) $exam->book?->title);
        $this->components->twoColumnDetail('Status', $exam->status);
        $this->components->twoColumnDetail('Score', (string) ($exam->score ?? '—'));
        $this->components->twoColumnDetail('Questions', (string) $exam->questions()->count());
        $this->components->twoColumnDetail('Items', (string) $items->count());
        $this->components->twoColumnDetail('Created', (string) $exam->created_at);
        $this->newLine();

        $answered = 0;
        $graders = [];

        foreach ($items as $item) {
            $hadith = $item->hadith?->number_in_book ?? '?';

            $this->line("<fg=cyan>#{$item->id}</> Q{$item->exam_question_id} · حديث {$hadith} — {$item->question?->question_text}");
            $this->components->twoColumnDetail('  hadith', $this->short((string) $item->hadith?->title));
            $this->components->twoColumnDetail('  stored correct_answer', $this->short((string) $item->correct_answer));

            if (! $item->isAnswered()) {
                $this->components->twoColumnDetail('  answer', '<fg=red>none stored — the app never sent one</>');
                $this->newLine();

                continue;
            }

            $answered++;
            $report = $item->evaluation_report ?? [];
            $mode = $report['mode'] ?? '<none>';
            $graders[$mode] = ($graders[$mode] ?? 0) + 1;

            $text = (string) $item->answer_text;
            $this->components->twoColumnDetail(
                '  answer_text as received',
                trim($text) === '' ? '<fg=red>EMPTY — nothing reached the server</>' : $this->short($text),
            );
            $this->components->twoColumnDetail(
                '  verdict',
                ($item->is_correct ? '<fg=green>correct</>' : '<fg=red>wrong</>')." (score {$item->score})",
            );
            $this->components->twoColumnDetail('  graded by', $this->describeMode($mode));

            if (($report['ai_available'] ?? null) === false) {
                $this->components->twoColumnDetail(
                    '  ai failure',
                    ($report['failure_code'] ?? '?').' — '.($report['failure_message'] ?? ''),
                );
            }

            if (isset($report['feedback_ar'])) {
                $this->components->twoColumnDetail('  feedback_ar', $this->short((string) $report['feedback_ar']));
            }

            $this->components->twoColumnDetail('  graded at', (string) $item->updated_at);
            $this->newLine();
        }

        $this->components->info('Summary');
        $this->components->twoColumnDetail('Answers stored', $answered.' of '.$items->count());

        foreach ($graders as $mode => $count) {
            $this->components->twoColumnDetail("Graded by {$mode}", (string) $count);
        }

        if (isset($graders['exact_match']) || isset($graders['<none>'])) {
            $this->newLine();
            $this->components->warn('Some answers carry no grading mode, or the retired exact_match one: those rows were written by an older build. Their stored result never changes — open the exam again to see current grading.');
        }

        return self::SUCCESS;
    }

    private function describeMode(string $mode): string
    {
        return match ($mode) {
            'gemini' => '<fg=green>gemini</> (AI judged it)',
            'token_match' => '<fg=yellow>token_match</> (deterministic fallback; Gemini was unavailable)',
            'exact_match' => '<fg=red>exact_match</> (retired build — stale row)',
            '<none>' => '<fg=red>unknown</> (word-sequence report, or an older build)',
            default => $mode,
        };
    }

    private function short(string $value, int $limit = 70): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
