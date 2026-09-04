<?php

namespace App\Http\Requests;

use App\Support\ExamItemReference;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completing an exam may carry the whole set of answers at once.
 *
 * The app collects the answers locally — results are withheld until the exam
 * ends anyway — and submits them with the completion request, so grading and
 * finalizing happen in one round trip. Submitting them one at a time through
 * POST /exams/{exam}/answers still works and is unchanged.
 *
 * Each entry names the exam item it answers; see ExamItemReference for the
 * accepted key names.
 */
class CompleteExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');

        if (! is_array($answers)) {
            return;
        }

        $this->merge([
            'answers' => array_values(array_map(
                fn ($answer) => is_array($answer) ? ExamItemReference::normalize($answer) : $answer,
                $answers,
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'array', 'max:200'],
            'answers.*.exam_answer_id' => ['nullable', 'integer'],
            'answers.*.exam_question_id' => ['nullable', 'integer'],
            'answers.*.hadith_id' => ['nullable', 'integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
        ];
    }

    /**
     * The submitted answers, normalized.
     *
     * @return array<int,array{exam_answer_id:?int, exam_question_id:?int, hadith_id:?int, answer_text:string}>
     */
    public function answers(): array
    {
        return array_map(
            fn (array $answer) => ExamItemReference::normalize($answer),
            $this->input('answers', []),
        );
    }
}
