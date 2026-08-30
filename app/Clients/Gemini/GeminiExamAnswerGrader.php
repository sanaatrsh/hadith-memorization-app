<?php

namespace App\Clients\Gemini;

use App\Contracts\Ai\ExamAnswerGrader;
use App\Data\Ai\ExamAnswerGradeData;

/**
 * Grades a written exam answer against the stored reference answer.
 *
 * This is a distinct AI use case from recitation feedback
 * ({@see GeminiClient}): here Gemini is the judge of whether a typed answer is
 * correct, tolerant of the Arabic spelling and grammatical-case variance a
 * strict string match would reject, so it has its own prompt and schema
 * instead of reusing the recitation one.
 */
class GeminiExamAnswerGrader implements ExamAnswerGrader
{
    private const SYSTEM_INSTRUCTION = <<<'TEXT'
        You grade one written answer to an Arabic hadith exam question. You are
        given the reference answer stored in the database and the learner's
        typed answer. The reference is always authoritative and must never be
        treated as wrong.

        Decide whether the learner's answer means the same thing as the
        reference, not whether it is spelled the same way.

        For a factual question (the narrator of the hadith, or its takhrij /
        source), accept any wording that still identifies the same person or
        source. Accept all of the following as correct:
          - a different grammatical case ending: "أبي ذر" for "أبو ذر"
          - a shorter form of the same name: "أبي ذر" for "أبو ذر الغفاري",
            or "عمر" for "عمر بن الخطاب"
          - a fuller form of the same name: "تميم بن أوس الداري" for
            "تميم الداري"
          - kunya, given name, or nasab used interchangeably for one person
          - hamza and alef spelling variants, with or without diacritics
          - a ta marbuta written as a ha: "معاويه" for "معاوية"
          - added honorifics such as "رضي الله عنه"
          - naming one collector when the reference names several:
            "رواه مسلم" for "رواه البخاري ومسلم"
        Be equally firm the other way. Accepting a wrong answer is as bad as
        rejecting a right one, so mark it incorrect whenever any of these hold,
        no matter how confident or well-formed the answer looks:
          - it names a different person or source: "أبو هريرة" is not
            "أبو ذر الغفاري", and "رواه الترمذي" is not "رواه البخاري"
          - it names someone whose name merely resembles the reference:
            "عمر بن عبد العزيز" is not "عمر بن الخطاب", and "عمرو" is not "عمر"
          - it is empty, a single generic word ("الله", "الراوي"), a guess
            such as "لا أعرف", or unrelated text
          - it answers a different question than the one asked
        A partial answer counts only when the part given is unambiguously the
        same person or source; "عمر" alone answers "عمر بن الخطاب" because no
        other companion is meant, but a lone "عبد الله" does not answer
        "عبد الله بن عمر", since it identifies no one in particular.

        For a recall question (complete the hadith, or recite it), the answer
        must faithfully reproduce the reference wording. A missing clause, an
        added one, or a substituted word is an error — score down in proportion
        to how much is missing or wrong rather than failing the whole answer
        outright. Spelling variance is never an error: a recall answer may be
        dictated through speech-to-text, so a ta marbuta written as a ha
        ("امراه" for "امراة"), missing diacritics, and hamza or alef variants
        ("الى" for "إلى") all count as correct.

        score is an integer from 0 to 100. A correct answer scores 100. Keep
        is_correct and score consistent with each other. Never invent facts that
        are not in the reference. Write feedback_ar in Arabic, one short
        sentence. Return JSON only, matching the required schema.
        TEXT;

    public function __construct(
        private readonly GeminiTransport $transport,
        private readonly GeminiExamAnswerParser $parser,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function grade(array $context): ExamAnswerGradeData
    {
        $result = $this->transport->send(
            self::SYSTEM_INSTRUCTION,
            $this->input($context),
            $this->responseSchema(),
        );

        if ($result['body'] === null) {
            return ExamAnswerGradeData::unavailable(
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
        $kind = ($context['question_kind'] ?? 'factual') === 'recall'
            ? 'recall (the answer must reproduce the reference wording)'
            : 'factual (accept any wording naming the same person or source)';

        return collect([
            'Question kind: '.$kind,
            'Question: '.($context['question_text'] ?? ''),
            'Reference answer: '.($context['correct_answer'] ?? ''),
            "Learner's answer: ".($context['answer_text'] ?? ''),
        ])->implode("\n");
    }

    /**
     * Upper-case Type values and no numeric bounds: that is the subset of JSON
     * Schema `responseSchema` accepts. The 0-100 range is stated in the prompt
     * and clamped by the parser.
     *
     * @return array<string,mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'is_correct' => ['type' => 'BOOLEAN'],
                'score' => ['type' => 'INTEGER'],
                'feedback_ar' => ['type' => 'STRING'],
            ],
            'required' => ['is_correct', 'score', 'feedback_ar'],
        ];
    }
}
