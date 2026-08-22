<?php

namespace Tests\Unit;

use App\Enums\ExamQuestionType;
use App\Models\ExamQuestion;
use App\Services\ExamAnswerEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

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
    public function test_factual_answers_are_matched_token_by_token(string $correct, string $answer, bool $expected): void
    {
        $result = $this->evaluate($correct, $answer);

        $this->assertSame(
            $expected,
            $result['is_correct'],
            sprintf('«%s» answering «%s» should be %s', $answer, $correct, $expected ? 'correct' : 'wrong'),
        );

        $this->assertSame('token_match', $result['report']['mode']);
        $this->assertSame($expected ? 100 : $result['score'], $result['score']);
    }

    public function test_a_correct_factual_answer_scores_full_marks(): void
    {
        $result = $this->evaluate('تميم الداري', 'تميم بن أوس الداري');

        $this->assertSame(100, $result['score']);
        $this->assertTrue($result['is_correct']);
        $this->assertSame(2, $result['report']['matched_words']);
        $this->assertSame(2, $result['report']['total_reference_words']);
        // «أوس» is extra, which is fine.
        $this->assertSame(['اوس'], $result['report']['extra_words']);
    }

    public function test_a_half_right_factual_answer_keeps_its_partial_score(): void
    {
        $result = $this->evaluate('عمر بن الخطاب', 'عمر بن عبد العزيز');

        $this->assertFalse($result['is_correct']);
        $this->assertSame(50, $result['score']);
        $this->assertSame(['الخطاب'], $result['report']['missing_words']);
    }

    public function test_recall_answers_still_need_the_whole_matn(): void
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

    public function test_voice_answers_are_never_treated_as_factual(): void
    {
        // A recitation is compared word by word whatever its length, so the
        // report is the comparison report and a missing word is reported as
        // missing rather than tolerated as a shorter wording.
        $result = $this->evaluate('تميم الداري', 'تميم', ExamQuestionType::Voice);

        $this->assertArrayNotHasKey('mode', $result['report']);
        $this->assertSame(['الداري'], $result['report']['missing_words']);
        $this->assertFalse($result['is_correct']);
    }
}
