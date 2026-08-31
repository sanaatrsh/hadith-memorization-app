<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Completing an exam may carry the whole set of answers at once.
 *
 * The app collects the answers locally — results are withheld until the exam
 * ends anyway — and submits them with the completion request, so grading and
 * finalizing happen in one round trip. Submitting them one at a time through
 * POST /exams/{exam}/answers still works and is unchanged.
 *
 * Both key namings are accepted: `exam_question_id`/`answer_text` as the rest
 * of the API names them, and `question_id`/`answer` as the app sends them.
 * They are normalized to the first shape before validation.
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
                fn ($answer) => is_array($answer)
                    ? [
                        'exam_question_id' => $answer['exam_question_id'] ?? $answer['question_id'] ?? null,
                        'answer_text' => $answer['answer_text'] ?? $answer['answer'] ?? '',
                    ]
                    : $answer,
                $answers,
            )),
        ]);
    }

    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'array', 'max:200'],
            'answers.*.exam_question_id' => ['required', 'integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
        ];
    }

    public function messages(): array
    {
        return [
            'answers.*.exam_question_id.required' => 'Each answer needs a question_id (or exam_question_id).',
        ];
    }

    /**
     * The submitted answers, normalized.
     *
     * @return array<int,array{exam_question_id:int, answer_text:string}>
     */
    public function answers(): array
    {
        return array_map(
            fn (array $answer) => [
                'exam_question_id' => (int) $answer['exam_question_id'],
                'answer_text' => (string) ($answer['answer_text'] ?? ''),
            ],
            $this->input('answers', []),
        );
    }
}
