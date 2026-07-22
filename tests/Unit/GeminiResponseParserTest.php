<?php

namespace Tests\Unit;

use App\Clients\Gemini\GeminiResponseParser;
use App\Enums\EvaluationVerdict;
use Tests\TestCase;

class GeminiResponseParserTest extends TestCase
{
    private GeminiResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GeminiResponseParser;
    }

    private function validPayload(): string
    {
        return json_encode([
            'is_textually_correct' => false,
            'score' => 86,
            'verdict' => 'needs_review',
            'missing_words' => ['إنما'],
            'extra_words' => [],
            'incorrect_segments' => [],
            'feedback_ar' => 'جيد',
            'recommended_action' => 'review_later',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_it_parses_valid_structured_output(): void
    {
        $body = ['id' => 'int_123', 'output_text' => $this->validPayload()];

        $result = $this->parser->parse($body, 'gemini-test');

        $this->assertTrue($result->available);
        $this->assertSame(86, $result->score);
        $this->assertSame(EvaluationVerdict::NeedsReview, $result->verdict);
        $this->assertSame('int_123', $result->interactionId);
        $this->assertSame('gemini-test', $result->model);
    }

    public function test_it_reads_candidates_style_envelope(): void
    {
        $body = ['candidates' => [['content' => ['parts' => [['text' => $this->validPayload()]]]]]];

        $this->assertTrue($this->parser->parse($body)->available);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $result = $this->parser->parse(['output_text' => 'not json']);

        $this->assertFalse($result->available);
        $this->assertSame('invalid_ai_json', $result->failureCode);
    }

    public function test_missing_field_is_rejected(): void
    {
        $partial = json_encode(['score' => 90]);
        $result = $this->parser->parse(['output_text' => $partial]);

        $this->assertFalse($result->available);
        $this->assertSame('missing_ai_field', $result->failureCode);
    }

    public function test_empty_response_is_rejected(): void
    {
        $result = $this->parser->parse([]);

        $this->assertFalse($result->available);
        $this->assertSame('empty_ai_response', $result->failureCode);
    }
}
