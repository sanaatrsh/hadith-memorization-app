<?php

namespace Tests\Unit;

use App\Enums\ExamQuestionType;
use App\Models\ExamQuestion;
use App\Services\ExamAnswerEvaluator;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gemini is the primary judge of an exam answer (no Gemini key configured in
 * these tests, so most cases below exercise the deterministic fallback that
 * takes over whenever Gemini is unavailable — see the "Gemini-graded
 * answers" group for the primary path).
 */
class ExamAnswerEvaluatorTest extends TestCase
{
    private function evaluate(string $correct, string $answer, ExamQuestionType $type = ExamQuestionType::Written): array
    {
        $question = new ExamQuestion([
            'type' => $type,
            'question_text' => 'من هو راوي هذا الحديث؟',
            'correct_answer' => $correct,
        ]);

        return app(ExamAnswerEvaluator::class)->evaluate($question, $answer);
    }

    private function fakeGeminiGrade(bool $isCorrect, int $score, string $feedback = 'ملاحظة'): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        Http::fake([
            '*' => Http::response([
                'id' => 'int_1',
                'output_text' => json_encode([
                    'is_correct' => $isCorrect,
                    'score' => $score,
                    'feedback_ar' => $feedback,
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);
    }

    public static function factualAnswers(): array
    {
        return [
            'exact' => ['تميم الداري', 'تميم الداري', true],
            // The learner writes the fuller lineage.
            'fuller lineage' => ['تميم الداري', 'تميم بن أوس الداري', true],
            'fuller lineage, no hamza' => ['تميم الداري', 'تميم بن اوس الداري', true],
            'with honorifics' => ['تميم الداري', 'تميم بن أوس الداري رضي الله عنه', true],
            // A shorter but wholly correct answer.
            'shorter answer' => ['عمر بن الخطاب', 'عمر', true],
            'kunya only is enough when the name is a kunya' => ['أبو هريرة', 'هريرة', true],
            'definite article dropped' => ['تميم الداري', 'تميم داري', true],
            // Takhrij answers: «رواه» carries no weight.
            'takhrij without the verb' => ['رواه البخاري ومسلم', 'البخاري ومسلم', true],
            'takhrij, one collector of two' => ['رواه البخاري ومسلم', 'رواه مسلم', true],
            // Wrong answers stay wrong.
            'different companion' => ['عمر بن الخطاب', 'أبو هريرة', false],
            'similar but different name' => ['عمر بن الخطاب', 'عمر بن عبد العزيز', false],
            'عمرو is not عمر' => ['عبد الله بن عمرو', 'عمر', false],
            'a generic word is not an answer' => ['عبد الله بن عمر', 'الله', false],
            'connectives only' => ['رواه البخاري ومسلم', 'رواه', false],
            'empty' => ['تميم الداري', '', false],
        ];
    }

    #[DataProvider('factualAnswers')]
    public function test_the_fallback_matches_factual_answers_token_by_token(string $correct, string $answer, bool $expected): void
    {
        $result = $this->evaluate($correct, $answer);

        $this->assertSame(
            $expected,
            $result['is_correct'],
            sprintf('«%s» answering «%s» should be %s', $answer, $correct, $expected ? 'correct' : 'wrong'),
        );

        $this->assertSame('token_match', $result['report']['mode']);
        $this->assertFalse($result['report']['ai_available']);
        $this->assertSame($expected ? 100 : $result['score'], $result['score']);
    }

    public function test_a_correct_fallback_factual_answer_scores_full_marks(): void
    {
        $result = $this->evaluate('تميم الداري', 'تميم بن أوس الداري');

        $this->assertSame(100, $result['score']);
        $this->assertTrue($result['is_correct']);
        $this->assertSame(2, $result['report']['matched_words']);
        $this->assertSame(2, $result['report']['total_reference_words']);
        // «أوس» is extra, which is fine.
        $this->assertSame(['اوس'], $result['report']['extra_words']);
    }

    public function test_a_half_right_fallback_factual_answer_keeps_its_partial_score(): void
    {
        $result = $this->evaluate('عمر بن الخطاب', 'عمر بن عبد العزيز');

        $this->assertFalse($result['is_correct']);
        $this->assertSame(50, $result['score']);
        $this->assertSame(['الخطاب'], $result['report']['missing_words']);
    }

    public function test_fallback_recall_answers_still_need_the_whole_matn(): void
    {
        // Long answers are recall, not factual: the word-sequence comparison
        // applies and a partial recitation does not pass.
        $matn = 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله';

        $partial = $this->evaluate($matn, 'إنما الأعمال بالنيات');

        $this->assertFalse($partial['is_correct']);
        $this->assertArrayHasKey('missing_words', $partial['report']);
        $this->assertArrayNotHasKey('mode', $partial['report']);

        $full = $this->evaluate($matn, $matn);

        $this->assertTrue($full['is_correct']);
        $this->assertSame(100, $full['score']);
    }

    public function test_fallback_voice_answers_are_never_treated_as_factual(): void
    {
        // A recitation is compared word by word whatever its length, so the
        // report is the comparison report and a missing word is reported as
        // missing rather than tolerated as a shorter wording.
        $result = $this->evaluate('تميم الداري', 'تميم', ExamQuestionType::Voice);

        $this->assertArrayNotHasKey('mode', $result['report']);
        $this->assertSame(['الداري'], $result['report']['missing_words']);
        $this->assertFalse($result['is_correct']);
    }

    public function test_an_empty_answer_is_never_sent_to_gemini(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake();

        $this->evaluate('تميم الداري', '');

        Http::assertNothingSent();
    }

    // --- Gemini-graded answers (the primary path) --------------------------

    public function test_gemini_accepts_a_grammatical_case_variant_the_fallback_would_reject(): void
    {
        // «أبي» is the genitive/accusative case of «أبو» — the same person,
        // but not a substring of one another, so the deterministic fallback
        // cannot recognise it. Gemini can.
        $this->fakeGeminiGrade(isCorrect: true, score: 100, feedback: 'إجابة صحيحة، نفس الاسم بصيغة إعرابية مختلفة.');

        $result = $this->evaluate('أبو هريرة', 'أبي هريرة');

        $this->assertTrue($result['is_correct']);
        $this->assertSame(100, $result['score']);
        $this->assertSame('gemini', $result['report']['mode']);
        $this->assertSame('إجابة صحيحة، نفس الاسم بصيغة إعرابية مختلفة.', $result['report']['feedback_ar']);
    }

    public function test_gemini_can_mark_a_factual_answer_wrong(): void
    {
        $this->fakeGeminiGrade(isCorrect: false, score: 0, feedback: 'اسم راوٍ مختلف تماما.');

        $result = $this->evaluate('عمر بن الخطاب', 'أبو هريرة');

        $this->assertFalse($result['is_correct']);
        $this->assertSame(0, $result['score']);
        $this->assertSame('gemini', $result['report']['mode']);
    }

    public function test_gemini_grades_a_recall_answer_with_partial_credit(): void
    {
        $matn = 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى فمن كانت هجرته إلى الله ورسوله';
        $this->fakeGeminiGrade(isCorrect: false, score: 55, feedback: 'ذكرت الجملة الأولى فقط، ونقص باقي الحديث.');

        $result = $this->evaluate($matn, 'إنما الأعمال بالنيات', ExamQuestionType::Voice);

        $this->assertFalse($result['is_correct']);
        $this->assertSame(55, $result['score']);
        $this->assertSame('gemini', $result['report']['mode']);
    }

    public function test_an_unconfigured_gemini_falls_back_to_the_deterministic_comparison(): void
    {
        // No API key set: the grader must report unavailable rather than the
        // exam failing to be gradable at all.
        $result = $this->evaluate('تميم الداري', 'تميم بن أوس الداري');

        $this->assertTrue($result['is_correct']);
        $this->assertSame('token_match', $result['report']['mode']);
        $this->assertFalse($result['report']['ai_available']);
        $this->assertSame('missing_api_key', $result['report']['failure_code']);
    }

    public function test_a_malformed_gemini_response_falls_back_to_the_deterministic_comparison(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['output_text' => 'not-json'], 200)]);

        $result = $this->evaluate('تميم الداري', 'تميم بن أوس الداري');

        $this->assertTrue($result['is_correct']);
        $this->assertSame('token_match', $result['report']['mode']);
        $this->assertFalse($result['report']['ai_available']);
        $this->assertSame('invalid_ai_json', $result['report']['failure_code']);
    }

    public function test_a_gemini_timeout_falls_back_to_the_deterministic_comparison(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response([], 503)]);

        $result = $this->evaluate('تميم الداري', 'تميم بن أوس الداري');

        $this->assertTrue($result['is_correct']);
        $this->assertSame('token_match', $result['report']['mode']);
        $this->assertFalse($result['report']['ai_available']);
        $this->assertSame('gemini_http_error', $result['report']['failure_code']);
    }
}
