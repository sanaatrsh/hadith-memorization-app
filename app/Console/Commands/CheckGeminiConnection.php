<?php

namespace App\Console\Commands;

use App\Contracts\Ai\ExamAnswerGrader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Answers one question: does Gemini actually work in this environment?
 *
 * Exam grading degrades to a deterministic comparison when Gemini is
 * unreachable, which is the right behaviour but hides a misconfiguration. This
 * command makes the real call and prints exactly what came back — including the
 * usable model names, since a wrong GEMINI_MODEL is rejected with HTTP 404 and
 * is otherwise indistinguishable from any other outage.
 */
class CheckGeminiConnection extends Command
{
    protected $signature = 'athar:gemini-check
                            {--reference=أبو ذر الغفاري : The stored answer to grade against}
                            {--answer=أبي ذر : The answer to submit as the learner\'s}';

    protected $description = 'Verify the Gemini connection and grade one sample exam answer';

    public function handle(ExamAnswerGrader $grader): int
    {
        $this->components->info('Gemini configuration');

        $key = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.model');

        $this->components->twoColumnDetail(
            'GEMINI_API_KEY',
            $key === ''
                ? '<fg=red>not set</>'
                : '<fg=green>set</> ('.mb_strlen($key).' chars, ends '.mb_substr($key, -4).')',
        );
        $this->components->twoColumnDetail('GEMINI_MODEL', $model !== '' ? $model : '<fg=red>not set</>');
        $this->components->twoColumnDetail('GEMINI_API_BASE_URL', (string) config('services.gemini.base_url'));

        if ($key === '') {
            $this->newLine();
            $this->components->error('No API key, so every AI call falls back to the deterministic comparison. Set GEMINI_API_KEY in .env.');

            return self::FAILURE;
        }

        if (! $this->listModels($model)) {
            return self::FAILURE;
        }

        return $this->gradeSample($grader);
    }

    /**
     * The models this key may call, and whether the configured one is among
     * them. GET /models is the cheapest call that proves the key works.
     */
    private function listModels(string $configured): bool
    {
        $this->newLine();
        $this->components->info('Available models (GET /models)');

        $response = Http::baseUrl(config('services.gemini.base_url'))
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
            ->connectTimeout((int) config('services.gemini.connect_timeout'))
            ->timeout((int) config('services.gemini.timeout'))
            ->get('/models');

        if ($response->failed()) {
            $this->components->error(sprintf(
                'HTTP %d — %s',
                $response->status(),
                $response->json('error.message') ?? 'no error message returned',
            ));

            return false;
        }

        $names = collect($response->json('models') ?? [])
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => str_replace('models/', '', (string) ($m['name'] ?? '')))
            ->filter()
            ->sort()
            ->values();

        if ($names->isEmpty()) {
            $this->components->warn('The key works but no model advertises generateContent.');

            return false;
        }

        foreach ($names as $name) {
            $this->line($name === $configured
                ? "  <fg=green>* {$name}</>  <fg=gray>(configured)</>"
                : "    {$name}");
        }

        if (! $names->contains($configured)) {
            $this->newLine();
            $this->components->error("GEMINI_MODEL='{$configured}' is not in the list above, so every call returns HTTP 404. Set GEMINI_MODEL in .env to one of these names.");

            return false;
        }

        return true;
    }

    /**
     * The case that matters: a shorter form of the same narrator's name has to
     * be graded correct.
     */
    private function gradeSample(ExamAnswerGrader $grader): int
    {
        $reference = (string) $this->option('reference');
        $answer = (string) $this->option('answer');

        $this->newLine();
        $this->components->info('Grading one sample answer');
        $this->components->twoColumnDetail('Reference (in the database)', $reference);
        $this->components->twoColumnDetail("Learner's answer", $answer);

        $grade = $grader->grade([
            'question_text' => 'من هو راوي هذا الحديث؟',
            'correct_answer' => $reference,
            'answer_text' => $answer,
            'question_kind' => 'factual',
        ]);

        $this->newLine();

        if (! $grade->available) {
            $this->components->error(sprintf(
                'Gemini did not grade it: [%s] %s',
                $grade->failureCode,
                $grade->failureMessage,
            ));
            $this->line('  <fg=gray>Exam answers are still graded, but by the deterministic comparison.</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('is_correct', $grade->isCorrect ? '<fg=green>true</>' : '<fg=red>false</>');
        $this->components->twoColumnDetail('score', (string) $grade->score);
        $this->components->twoColumnDetail('feedback_ar', (string) $grade->feedbackAr);
        $this->components->twoColumnDetail('model', (string) $grade->model);

        $this->newLine();

        if ($grade->isCorrect) {
            $this->components->info('Gemini is grading exam answers.');

            return self::SUCCESS;
        }

        $this->components->warn('Gemini answered, but judged this correct answer wrong. Review the prompt in GeminiExamAnswerGrader.');

        return self::FAILURE;
    }
}
