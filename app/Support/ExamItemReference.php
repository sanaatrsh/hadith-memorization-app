<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes however a client names the exam item it is answering.
 *
 * An answer belongs to one item — a question asked about one hadith — so the
 * item's own id identifies it outright. A bare question id no longer does,
 * since one question covers several hadiths; it is still accepted, paired with
 * a hadith id or on its own when the question happens to cover a single
 * hadith, and refused as ambiguous otherwise rather than guessed at.
 *
 * The key names the app already sends (`question_id` / `answer`) are accepted
 * alongside the canonical ones.
 */
final class ExamItemReference
{
    /**
     * @param  array<string,mixed>  $input
     * @return array{exam_answer_id:?int, exam_question_id:?int, hadith_id:?int, answer_text:string}
     */
    public static function normalize(array $input): array
    {
        return [
            'exam_answer_id' => self::intOrNull($input, ['exam_answer_id', 'item_id', 'exam_item_id']),
            'exam_question_id' => self::intOrNull($input, ['exam_question_id', 'question_id']),
            'hadith_id' => self::intOrNull($input, ['hadith_id']),
            'answer_text' => (string) ($input['answer_text'] ?? $input['answer'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<int,string>  $keys
     */
    private static function intOrNull(array $input, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $input[$key] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
