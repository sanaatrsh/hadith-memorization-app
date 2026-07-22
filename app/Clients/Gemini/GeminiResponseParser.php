<?php

namespace App\Clients\Gemini;

use App\Data\Ai\HadithEvaluationData;
use App\Enums\EvaluationVerdict;

/**
 * Extracts the structured JSON payload from a Gemini response envelope and
 * validates it against the required schema. Even though structured output is
 * requested, the decoded response is validated again here before use.
 */
class GeminiResponseParser
{
    private const RECOMMENDED_ACTIONS = ['continue', 'repeat_now', 'review_later'];

    /**
     * @param  array<string,mixed>  $body
     */
    public function parse(array $body, ?string $model = null): HadithEvaluationData
    {
        $interactionId = $this->extractInteractionId($body);
        $text = $this->extractText($body);

        if ($text === null) {
            return HadithEvaluationData::unavailable('empty_ai_response', 'The AI response contained no output.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return HadithEvaluationData::unavailable('invalid_ai_json', 'The AI response was not valid JSON.');
        }

        return $this->validate($decoded, $model, $interactionId);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function validate(array $data, ?string $model, ?string $interactionId): HadithEvaluationData
    {
        $required = [
            'is_textually_correct', 'score', 'verdict',
            'missing_words', 'extra_words', 'incorrect_segments',
            'feedback_ar', 'recommended_action',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                return HadithEvaluationData::unavailable('missing_ai_field', "The AI response is missing '{$key}'.");
            }
        }

        $verdict = EvaluationVerdict::tryFrom((string) $data['verdict']);
        if ($verdict === null) {
            return HadithEvaluationData::unavailable('invalid_ai_field', 'The AI verdict was not a recognised value.');
        }

        if (! is_bool($data['is_textually_correct']) || ! is_numeric($data['score'])) {
            return HadithEvaluationData::unavailable('invalid_ai_field', 'The AI response had invalid field types.');
        }

        $action = (string) $data['recommended_action'];
        if (! in_array($action, self::RECOMMENDED_ACTIONS, true)) {
            return HadithEvaluationData::unavailable('invalid_ai_field', 'The AI recommended action was invalid.');
        }

        $score = max(0, min(100, (int) $data['score']));

        return new HadithEvaluationData(
            available: true,
            isTextuallyCorrect: (bool) $data['is_textually_correct'],
            score: $score,
            verdict: $verdict,
            missingWords: $this->stringList($data['missing_words']),
            extraWords: $this->stringList($data['extra_words']),
            incorrectSegments: $this->segments($data['incorrect_segments']),
            feedbackAr: (string) $data['feedback_ar'],
            recommendedAction: $action,
            model: $model,
            interactionId: $interactionId,
        );
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function extractText(array $body): ?string
    {
        // Preferred: a plain output_text convenience field.
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        // Interactions-style: output[].content[].text
        foreach (($body['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }

        // GenerateContent-style: candidates[0].content.parts[0].text
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                return $part['text'];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function extractInteractionId(array $body): ?string
    {
        foreach (['id', 'interaction_id', 'responseId'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return $body[$key];
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(fn ($v) => (string) $v, array_filter($value, 'is_scalar')));
    }

    /**
     * @return array<int,array{expected:string,received:string,explanation_ar:string}>
     */
    private function segments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $segments = [];
        foreach ($value as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $segments[] = [
                'expected' => (string) ($segment['expected'] ?? ''),
                'received' => (string) ($segment['received'] ?? ''),
                'explanation_ar' => (string) ($segment['explanation_ar'] ?? ''),
            ];
        }

        return $segments;
    }
}
