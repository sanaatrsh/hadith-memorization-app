<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\ExamAnswer;
use Illuminate\Console\Command;

/**
 * Shows how each answer of an exam was actually graded.
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

        $exam->load(['questions.answer', 'user']);

        $this->components->info("Exam #{$exam->id}");
        $this->components->twoColumnDetail('User', (string) $exam->user?->email);
        $this->components->twoColumnDetail('Status', $exam->status);
        $this->components->twoColumnDetail('Score', (string) ($exam->score ?? '—'));
        $this->components->twoColumnDetail('Questions', (string) $exam->questions->count());
        $this->components->twoColumnDetail('Created', (string) $exam->created_at);
        $this->newLine();

        $answered = 0;
        $graders = [];

        foreach ($exam->questions as $question) {
            /** @var ?ExamAnswer $answer */
            $answer = $question->answer;

            $this->line("<fg=cyan>Q{$question->sort_order}</> {$question->question_text}");
            $this->components->twoColumnDetail('  stored correct_answer', $this->short((string) $question->correct_answer));

            if ($answer === null) {
                $this->components->twoColumnDetail('  answer', '<fg=red>none stored — the app never sent one</>');
                $this->newLine();

                continue;
            }

            $answered++;
            $report = $answer->evaluation_report ?? [];
            $mode = $report['mode'] ?? '<none>';
            $graders[$mode] = ($graders[$mode] ?? 0) + 1;

            $text = (string) $answer->answer_text;
            $this->components->twoColumnDetail(
                '  answer_text as received',
                trim($text) === '' ? '<fg=red>EMPTY — nothing reached the server</>' : $this->short($text),
            );
            $this->components->twoColumnDetail(
                '  verdict',
                ($answer->is_correct ? '<fg=green>correct</>' : '<fg=red>wrong</>')." (score {$answer->score})",
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

            $this->components->twoColumnDetail('  graded at', (string) $answer->updated_at);
            $this->newLine();
        }

        $this->components->info('Summary');
        $this->components->twoColumnDetail('Answers stored', $answered.' of '.$exam->questions->count());

        foreach ($graders as $mode => $count) {
            $this->components->twoColumnDetail("Graded by {$mode}", (string) $count);
        }

        if (isset($graders['exact_match']) || isset($graders['<none>'])) {
            $this->newLine();
            $this->components->warn('Some answers carry no grading mode, or the retired exact_match one: those rows were written by an older build. Their stored result never changes — create a new exam to see current grading.');
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
