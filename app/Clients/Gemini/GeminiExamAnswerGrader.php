<?php

namespace App\Clients\Gemini;

use App\Contracts\Ai\ExamAnswerGrader;
use App\Data\Ai\ExamAnswerGradeData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grades a written exam answer against the stored reference answer.
 *
 * This is a distinct AI use case from recitation feedback
 * ({@see GeminiClient}): here Gemini is the judge of
 * whether a typed answer is correct, tolerant of the Arabic spelling and
 * grammatical-case variance a strict string match would reject, so it has
 * its own minimal prompt and schema instead of reusing the recitation one.
 */
class GeminiExamAnswerGrader implements ExamAnswerGrader
{
    private const SYSTEM_INSTRUCTION = <<<'TEXT'
        You grade one written answer to an Arabic hadith exam question. You are
        given the reference answer and the learner's answer; the reference is
        always authoritative and must never be treated as wrong.

        For a factual question (the narrator of the hadith, or its takhrij /
        source), accept ordinary Arabic variation that still names the same
        person or source: a different grammatical case ending, kunya vs. given
        name vs. nasab, hamza spelling, added honorifics (رضي الله عنه), or an
        incomplete lineage. Reject a different person or source entirely.

        For a recall question (complete the hadith, or recite it), the answer
        must faithfully reproduce the reference wording. Minor spelling
        normalization is fine, but a missing clause, an added one, or a
        substituted word is an error — score down for how much is missing or
        wrong rather than failing the whole answer outright.

        Never invent facts that are not in the reference. Write feedback_ar in
        Arabic. Return JSON only, matching the required schema.
        TEXT;

    public function __construct(private readonly GeminiExamAnswerParser $parser) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function grade(array $context): ExamAnswerGradeData
    {
        $model = (string) config('services.gemini.model');

        if (empty(config('services.gemini.api_key'))) {
            return ExamAnswerGradeData::unavailable('missing_api_key', 'Gemini API key is not configured.');
        }

        try {
            $response = Http::baseUrl(config('services.gemini.base_url'))
                ->acceptJson()
                ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
                ->connectTimeout((int) config('services.gemini.connect_timeout'))
                ->timeout((int) config('services.gemini.timeout'))
                ->retry((int) config('services.gemini.retry_times'), 500, throw: false)
                ->post('/interactions', $this->payload($context, $model));
        } catch (ConnectionException $e) {
            return ExamAnswerGradeData::unavailable('gemini_timeout', 'Gemini request timed out.');
        } catch (Throwable $e) {
            Log::warning('Gemini exam grading request failed', ['message' => $e->getMessage()]);

            return ExamAnswerGradeData::unavailable('gemini_error', 'Gemini request failed.');
        }

        if ($response->status() === 429) {
            return ExamAnswerGradeData::unavailable('gemini_rate_limited', 'Gemini rate limit reached.');
        }

        if ($response->failed()) {
            return ExamAnswerGradeData::unavailable('gemini_http_error', 'Gemini returned an error status: '.$response->status());
        }

        return $this->parser->parse($response->json() ?? [], $model);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function payload(array $context, string $model): array
    {
        $input = collect([
            'Question kind: '.($context['question_kind'] ?? 'factual'),
            'Question: '.($context['question_text'] ?? ''),
            'Reference answer: '.($context['correct_answer'] ?? ''),
            "Learner's answer: ".($context['answer_text'] ?? ''),
        ])->implode("\n");

        return [
            'model' => $model,
            'store' => (bool) config('services.gemini.store'),
            'system_instruction' => self::SYSTEM_INSTRUCTION,
            'input' => $input,
            'generation_config' => [
                'temperature' => 0.1,
                'thinking_level' => 'low',
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->responseSchema(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_correct' => ['type' => 'boolean'],
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'feedback_ar' => ['type' => 'string'],
            ],
            'required' => ['is_correct', 'score', 'feedback_ar'],
        ];
    }
}
