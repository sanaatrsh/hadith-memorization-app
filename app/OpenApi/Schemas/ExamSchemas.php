<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExamQuestion',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 55),
        new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
        new OA\Property(property: 'type', type: 'string', enum: ['written', 'voice'], example: 'written'),
        new OA\Property(property: 'question_text', type: 'string', example: 'من هو راوي هذا الحديث؟'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_answered', type: 'boolean', nullable: true, example: true),
        new OA\Property(property: 'correct_answer', description: 'Released only once the exam is completed.', type: 'string', nullable: true, example: 'عمر بن الخطاب'),
        new OA\Property(property: 'is_correct', description: 'Released only once the exam is completed.', type: 'boolean', nullable: true, example: false),
        new OA\Property(property: 'score', description: 'Released only once the exam is completed.', type: 'integer', nullable: true, example: 0),
        new OA\Property(property: 'answer', type: 'object', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Exam',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'user_id', type: 'integer', example: 1),
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'status', type: 'string', enum: ['in_progress', 'completed'], example: 'in_progress'),
        new OA\Property(property: 'question_count', type: 'integer', example: 5),
        new OA\Property(property: 'results_released', description: 'True once the exam is completed; scores and correct answers are withheld before that.', type: 'boolean', example: false),
        new OA\Property(property: 'score', description: 'Null until the exam is completed.', type: 'integer', nullable: true, example: 88),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'questions', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExamQuestion')),
        new OA\Property(
            property: 'summary',
            description: 'Released with the results when the exam is completed.',
            properties: [
                new OA\Property(property: 'total_questions', type: 'integer', example: 42),
                new OA\Property(property: 'answered_count', type: 'integer', example: 42),
                new OA\Property(property: 'unanswered_count', type: 'integer', example: 0),
                new OA\Property(property: 'correct_count', type: 'integer', example: 37),
                new OA\Property(property: 'incorrect_count', type: 'integer', example: 5),
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
        new OA\Property(property: 'question_count', description: 'Optional. Omit for a short default exam — the hadiths added to the book today if there are any, else athar.exams.default_question_count (6) hadiths from the book; never the whole book. Given explicitly, it narrows the exam to that many distinct hadiths of the whole book instead.', type: 'integer', minimum: 1, maximum: 200, nullable: true, example: 6),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StoreExamAnswerRequest',
    required: ['exam_question_id', 'answer_text'],
    properties: [
        new OA\Property(property: 'exam_question_id', type: 'integer', example: 55),
        new OA\Property(property: 'answer_text', type: 'string', example: 'عمر بن الخطاب'),
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
                new OA\Property(property: 'exam_question_id', type: 'integer', example: 55),
                new OA\Property(property: 'is_answered', type: 'boolean', example: true),
                new OA\Property(property: 'answered_count', type: 'integer', example: 3),
                new OA\Property(property: 'total_questions', type: 'integer', example: 42),
                new OA\Property(property: 'remaining_count', type: 'integer', example: 39),
                new OA\Property(property: 'results_released', type: 'boolean', example: false),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ExamSchemas {}
