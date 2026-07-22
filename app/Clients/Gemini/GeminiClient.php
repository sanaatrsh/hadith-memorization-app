<?php

namespace App\Clients\Gemini;

use App\Contracts\Ai\HadithEvaluator;
use App\Data\Ai\HadithEvaluationData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiClient implements HadithEvaluator
{
    private const SYSTEM_INSTRUCTION = 'You evaluate Arabic hadith memorization. Use only the supplied canonical text and transcript. Never invent or correct the canonical hadith. Return Arabic feedback using the required JSON schema.';

    public function __construct(private readonly GeminiResponseParser $parser) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function evaluate(array $context): HadithEvaluationData
    {
        $model = (string) config('services.gemini.model');

        if (empty(config('services.gemini.api_key'))) {
            return HadithEvaluationData::unavailable('missing_api_key', 'Gemini API key is not configured.');
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
            return HadithEvaluationData::unavailable('gemini_timeout', 'Gemini request timed out.');
        } catch (Throwable $e) {
            Log::warning('Gemini request failed', ['message' => $e->getMessage()]);

            return HadithEvaluationData::unavailable('gemini_error', 'Gemini request failed.');
        }

        if ($response->status() === 429) {
            return HadithEvaluationData::unavailable('gemini_rate_limited', 'Gemini rate limit reached.');
        }

        if ($response->failed()) {
            return HadithEvaluationData::unavailable('gemini_http_error', 'Gemini returned an error status: '.$response->status());
        }

        return $this->parser->parse($response->json() ?? [], $model);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function payload(array $context, string $model): array
    {
        $comparison = $context['comparison'] ?? [];

        $input = collect([
            'Reference: '.($context['canonical_text'] ?? ''),
            'Title: '.($context['title'] ?? ''),
            'Narrator: '.($context['narrator'] ?? ''),
            'Source: '.($context['source'] ?? ''),
            'Session type: '.($context['session_type'] ?? ''),
            'Current SRS level: '.($context['srs_level'] ?? 0),
            'Transcript: '.($context['transcript'] ?? ''),
            'Objective comparison: '.json_encode($comparison, JSON_UNESCAPED_UNICODE),
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
                'is_textually_correct' => ['type' => 'boolean'],
                'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'verdict' => ['type' => 'string', 'enum' => ['correct', 'acceptable', 'needs_review', 'incorrect']],
                'missing_words' => ['type' => 'array', 'items' => ['type' => 'string']],
                'extra_words' => ['type' => 'array', 'items' => ['type' => 'string']],
                'incorrect_segments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'expected' => ['type' => 'string'],
                            'received' => ['type' => 'string'],
                            'explanation_ar' => ['type' => 'string'],
                        ],
                        'required' => ['expected', 'received', 'explanation_ar'],
                    ],
                ],
                'feedback_ar' => ['type' => 'string'],
                'recommended_action' => ['type' => 'string', 'enum' => ['continue', 'repeat_now', 'review_later']],
            ],
            'required' => [
                'is_textually_correct', 'score', 'verdict', 'missing_words',
                'extra_words', 'incorrect_segments', 'feedback_ar', 'recommended_action',
            ],
        ];
    }
}
