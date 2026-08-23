<?php

namespace App\Clients\Gemini;

use App\Data\Ai\ExamAnswerGradeData;

/**
 * Extracts and validates the structured JSON payload of an exam-answer
 * grading response. Mirrors GeminiResponseParser's envelope handling, but
 * against the much smaller schema {@see GeminiExamAnswerGrader::responseSchema()}.
 */
class GeminiExamAnswerParser
{
    /**
     * @param  array<string,mixed>  $body
     */
    public function parse(array $body, ?string $model = null): ExamAnswerGradeData
    {
        $interactionId = $this->extractInteractionId($body);
        $text = $this->extractText($body);

        if ($text === null) {
            return ExamAnswerGradeData::unavailable('empty_ai_response', 'The AI response contained no output.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return ExamAnswerGradeData::unavailable('invalid_ai_json', 'The AI response was not valid JSON.');
        }

        return $this->validate($decoded, $model, $interactionId);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function validate(array $data, ?string $model, ?string $interactionId): ExamAnswerGradeData
    {
        foreach (['is_correct', 'score', 'feedback_ar'] as $key) {
            if (! array_key_exists($key, $data)) {
                return ExamAnswerGradeData::unavailable('missing_ai_field', "The AI response is missing '{$key}'.");
            }
        }

        if (! is_bool($data['is_correct']) || ! is_numeric($data['score']) || ! is_string($data['feedback_ar'])) {
            return ExamAnswerGradeData::unavailable('invalid_ai_field', 'The AI response had invalid field types.');
        }

        return new ExamAnswerGradeData(
            available: true,
            isCorrect: $data['is_correct'],
            score: max(0, min(100, (int) $data['score'])),
            feedbackAr: $data['feedback_ar'],
            model: $model,
            interactionId: $interactionId,
        );
    }

    /**
     * @param  array<string,mixed>  $body
     */
    private function extractText(array $body): ?string
    {
        if (isset($body['output_text']) && is_string($body['output_text'])) {
            return $body['output_text'];
        }

        foreach (($body['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }

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
}
