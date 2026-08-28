<?php

namespace App\Clients\Gemini;

use App\Contracts\Ai\HadithEvaluator;
use App\Data\Ai\HadithEvaluationData;

/**
 * Structured Arabic feedback on a recitation attempt.
 *
 * The score this returns is diagnostic only: the deterministic word comparison
 * stays the authoritative score, so a Gemini outage never changes a learner's
 * mark — see EvaluateMemorizationAttempt.
 */
class GeminiClient implements HadithEvaluator
{
    private const SYSTEM_INSTRUCTION = <<<'TEXT'
        You evaluate Arabic hadith memorization. Use only the supplied canonical
        text and transcript. Never invent or correct the canonical hadith.

        The transcript comes from speech-to-text, so it carries recognizer
        artefacts that are not memorization mistakes. Never report these as
        errors:
          - a ta marbuta written as a ha: "امراه" for "امراة", "الامه" for
            "الامة" — the two are indistinguishable in speech
          - missing or different diacritics (tashkeel)
          - hamza and alef spelling variants: "الى" for "إلى", "اعمال" for
            "أعمال"
        Report only real recall errors: a missing word or clause, an added one,
        a substituted word, or a reordered passage.

        score is an integer from 0 to 100. Return Arabic feedback using the
        required JSON schema.
        TEXT;

    public function __construct(
        private readonly GeminiTransport $transport,
        private readonly GeminiResponseParser $parser,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function evaluate(array $context): HadithEvaluationData
    {
        $result = $this->transport->send(
            self::SYSTEM_INSTRUCTION,
            $this->input($context),
            $this->responseSchema(),
        );

        if ($result['body'] === null) {
            return HadithEvaluationData::unavailable(
                (string) $result['failure_code'],
                (string) $result['failure_message'],
            );
        }

        return $this->parser->parse($result['body'], $result['model']);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function input(array $context): string
    {
        return collect([
            'Reference: '.($context['canonical_text'] ?? ''),
            'Title: '.($context['title'] ?? ''),
            'Narrator: '.($context['narrator'] ?? ''),
            'Source: '.($context['source'] ?? ''),
            'Session type: '.($context['session_type'] ?? ''),
            'Current SRS level: '.($context['srs_level'] ?? 0),
            'Transcript: '.($context['transcript'] ?? ''),
            'Objective comparison: '.json_encode($context['comparison'] ?? [], JSON_UNESCAPED_UNICODE),
        ])->implode("\n");
    }

    /**
     * Upper-case Type values and no numeric bounds — the subset
     * `responseSchema` accepts. The 0-100 range is stated in the prompt and
     * clamped by the parser.
     *
     * @return array<string,mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'is_textually_correct' => ['type' => 'BOOLEAN'],
                'score' => ['type' => 'INTEGER'],
                'verdict' => [
                    'type' => 'STRING',
                    'enum' => ['correct', 'acceptable', 'needs_review', 'incorrect'],
                ],
                'missing_words' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING'],
                ],
                'extra_words' => [
                    'type' => 'ARRAY',
                    'items' => ['type' => 'STRING'],
                ],
                'incorrect_segments' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'expected' => ['type' => 'STRING'],
                            'received' => ['type' => 'STRING'],
                            'explanation_ar' => ['type' => 'STRING'],
                        ],
                        'required' => ['expected', 'received', 'explanation_ar'],
                    ],
                ],
                'feedback_ar' => ['type' => 'STRING'],
                'recommended_action' => [
                    'type' => 'STRING',
                    'enum' => ['continue', 'repeat_now', 'review_later'],
                ],
            ],
            'required' => [
                'is_textually_correct', 'score', 'verdict', 'missing_words',
                'extra_words', 'incorrect_segments', 'feedback_ar', 'recommended_action',
            ],
        ];
    }
}
