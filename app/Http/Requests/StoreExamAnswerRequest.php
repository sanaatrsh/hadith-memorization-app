<?php

namespace App\Http\Requests;

use App\Support\ExamItemReference;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One answer, submitted against one exam item.
 *
 * The item is named by its own id (`exam_answer_id`, or `item_id`), or by the
 * question plus the hadith it is being asked about. See ExamItemReference for
 * the accepted key names.
 */
class StoreExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ExamItemReference::normalize($this->all()));
    }

    public function rules(): array
    {
        return [
            'exam_answer_id' => ['nullable', 'integer'],
            'exam_question_id' => ['nullable', 'integer'],
            'hadith_id' => ['nullable', 'integer'],
            'answer_text' => ['required', 'string', 'max:'.(int) config('athar.attempts.max_recognized_text_length', 10000)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('exam_answer_id') === null && $this->input('exam_question_id') === null) {
                    $validator->errors()->add(
                        'exam_answer_id',
                        'Name the item being answered: exam_answer_id, or exam_question_id (with hadith_id when the question covers several hadiths).',
                    );
                }
            },
        ];
    }

    /**
     * @return array{exam_answer_id:?int, exam_question_id:?int, hadith_id:?int, answer_text:string}
     */
    public function reference(): array
    {
        return ExamItemReference::normalize($this->validated());
    }
}
