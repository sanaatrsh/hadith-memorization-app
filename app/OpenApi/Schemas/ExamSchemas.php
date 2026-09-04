<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExamItem',
    description: 'One item of an exam: a question put about a specific hadith. This is what the learner answers, so its `id` is the id to send back — the reference answer and the result live here, not on the question.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 240),
        new OA\Property(property: 'exam_question_id', type: 'integer', example: 55),
        new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_answered', type: 'boolean', example: true),
        new OA\Property(property: 'answer_text', description: 'What the learner submitted; null until they answer.', type: 'string', nullable: true, example: 'عمر بن الخطاب'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'type', description: 'The wording\'s type, copied from the question so a flat list of items renders on its own.', type: 'string', enum: ['written', 'voice'], nullable: true, example: 'written'),
        new OA\Property(property: 'question_text', description: 'The wording, copied from the question for the same reason. Absent when the item is already nested under its question.', type: 'string', nullable: true, example: 'من هو راوي هذا الحديث؟'),
        new OA\Property(
            property: 'hadith',
            description: 'Which hadith the question is being asked about.',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 18),
                new OA\Property(property: 'number_in_book', description: 'Its number inside the collection — الحديث الرابع من الأربعين — not the row id.', type: 'integer', nullable: true, example: 4),
                new OA\Property(property: 'title', type: 'string', example: 'إنما الأعمال بالنيات'),
                new OA\Property(property: 'intro', description: 'مقدمة الحديث: the isnad/context line it opens with.', type: 'string', nullable: true),
            ],
            type: 'object',
            nullable: true,
        ),
        new OA\Property(property: 'correct_answer', description: 'Released only once the exam is completed.', type: 'string', nullable: true, example: 'عمر بن الخطاب'),
        new OA\Property(property: 'is_correct', description: 'Released only once the exam is completed.', type: 'boolean', nullable: true, example: false),
        new OA\Property(property: 'score', description: 'Released only once the exam is completed.', type: 'integer', nullable: true, example: 0),
        new OA\Property(property: 'evaluation_report', description: 'Released only once the exam is completed. Says which grader ran (`mode`: gemini or the deterministic fallback) and what it found.', type: 'object', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ExamQuestion',
    description: 'A question of the exam: its wording, stored once, plus one answer row per hadith it is asked about. The wording names no hadith — that is what makes it reusable across them.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 55),
        new OA\Property(property: 'type', type: 'string', enum: ['written', 'voice'], example: 'written'),
        new OA\Property(property: 'question_text', type: 'string', example: 'من هو راوي هذا الحديث؟'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'answers', description: 'The items under this question — one per hadith.', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExamItem')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Exam',
    description: "A book's exam. It is exposed twice over from the same rows: `questions` is the stored structure (each wording once, with its per-hadith answers) and `items` is that flattened into the list the learner works through.",
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'book_title', type: 'string', nullable: true, example: 'الأربعون النووية'),
        new OA\Property(property: 'status', type: 'string', enum: ['in_progress', 'completed'], example: 'in_progress'),
        new OA\Property(property: 'question_count', description: "The exam's length: how many items are graded.", type: 'integer', example: 6),
        new OA\Property(property: 'distinct_question_count', description: 'How many distinct wordings those items draw on.', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'results_released', description: 'True once the exam is completed; scores and correct answers are withheld before that.', type: 'boolean', example: false),
        new OA\Property(property: 'score', description: 'Null until the exam is completed.', type: 'integer', nullable: true, example: 88),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'questions', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExamQuestion')),
        new OA\Property(property: 'items', description: 'Every item of the exam, in the order to work through them.', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExamItem')),
        new OA\Property(
            property: 'summary',
            description: 'Released with the results when the exam is completed. Counts are over items, not wordings.',
            properties: [
                new OA\Property(property: 'total_questions', description: 'The number of items.', type: 'integer', example: 6),
                new OA\Property(property: 'distinct_question_count', type: 'integer', example: 3),
                new OA\Property(property: 'answered_count', type: 'integer', example: 6),
                new OA\Property(property: 'unanswered_count', type: 'integer', example: 0),
                new OA\Property(property: 'correct_count', type: 'integer', example: 5),
                new OA\Property(property: 'incorrect_count', type: 'integer', example: 1),
                new OA\Property(property: 'passing_score', type: 'integer', example: 90),
            ],
            type: 'object',
            nullable: true,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StoreExamRequest',
    required: ['book_id'],
    properties: [
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'question_count', description: 'Optional, and counted in hadiths: each selected hadith is asked about exactly once. Omit for a short default exam — the hadiths added to the book today if there are any, else athar.exams.default_question_count (6) hadiths from the book; never the whole book. Given explicitly, it narrows the exam to that many distinct hadiths of the whole book instead.', type: 'integer', minimum: 1, maximum: 200, nullable: true, example: 6),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StoreExamAnswerRequest',
    description: "One answer, submitted against one exam item. Name the item by its own id — `exam_answer_id` (or `item_id`), the `id` of an entry in the exam's `items` — or by `exam_question_id` together with `hadith_id`. A bare question id works only while that question covers a single hadith; otherwise it is refused as ambiguous rather than guessed at. `question_id` and `answer` are accepted as aliases.",
    required: ['answer_text'],
    properties: [
        new OA\Property(property: 'exam_answer_id', description: 'The item being answered. Alias: `item_id`.', type: 'integer', nullable: true, example: 240),
        new OA\Property(property: 'exam_question_id', description: 'The question being answered. Alias: `question_id`. Needs `hadith_id` unless the question covers a single hadith.', type: 'integer', nullable: true, example: 55),
        new OA\Property(property: 'hadith_id', description: 'Which hadith of that question is being answered.', type: 'integer', nullable: true, example: 18),
        new OA\Property(property: 'answer_text', description: 'Alias: `answer`.', type: 'string', example: 'عمر بن الخطاب'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CompleteExamRequest',
    description: 'Optional body for completing an exam. Submit every answer at once instead of one at a time; each entry names its item the same way StoreExamAnswerRequest does.',
    properties: [
        new OA\Property(
            property: 'answers',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'exam_answer_id', description: 'The item being answered. Alias: `item_id`.', type: 'integer', nullable: true, example: 240),
                new OA\Property(property: 'exam_question_id', description: 'The question being answered. Alias: `question_id`.', type: 'integer', nullable: true, example: 55),
                new OA\Property(property: 'hadith_id', type: 'integer', nullable: true, example: 18),
                new OA\Property(property: 'answer_text', description: "The learner's typed answer. Alias: `answer`.", type: 'string', example: 'أبو هريرة'),
            ], type: 'object'),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ExamAnswerResult',
    description: 'Acknowledgement only — the score and the correct answer are released when the exam is completed.',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'تم استلام الإجابة. تظهر النتيجة بعد إنهاء جميع الأسئلة.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'exam_answer_id', type: 'integer', example: 240),
                new OA\Property(property: 'exam_question_id', type: 'integer', example: 55),
                new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
                new OA\Property(property: 'is_answered', type: 'boolean', example: true),
                new OA\Property(property: 'answered_count', type: 'integer', example: 3),
                new OA\Property(property: 'total_questions', description: 'The number of items in the exam.', type: 'integer', example: 6),
                new OA\Property(property: 'remaining_count', type: 'integer', example: 3),
                new OA\Property(property: 'results_released', type: 'boolean', example: false),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ExamSchemas {}
