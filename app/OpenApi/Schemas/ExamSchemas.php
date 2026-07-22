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
        new OA\Property(property: 'score', type: 'integer', nullable: true, example: 88),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'questions', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExamQuestion')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StoreExamRequest',
    required: ['book_id', 'question_count'],
    properties: [
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'question_count', type: 'integer', minimum: 1, maximum: 50, example: 5),
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
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Answer submitted successfully.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'exam_question_id', type: 'integer', example: 55),
                new OA\Property(property: 'score', type: 'integer', example: 100),
                new OA\Property(property: 'is_correct', type: 'boolean', example: true),
                new OA\Property(property: 'evaluation_report', type: 'object', nullable: true),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ExamSchemas {}
