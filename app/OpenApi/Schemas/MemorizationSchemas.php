<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MemorizationAttemptRequest',
    required: ['client_attempt_uuid', 'hadith_id', 'recognized_text'],
    properties: [
        new OA\Property(property: 'client_attempt_uuid', description: 'Flutter-generated UUID used for idempotency (retries return the same attempt).', type: 'string', format: 'uuid', example: '7d969c10-33ea-4e7e-8f20-181d0b180ea1'),
        new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
        new OA\Property(property: 'recognized_text', description: 'Arabic speech-to-text transcript extracted by Flutter.', type: 'string', maxLength: 10000, example: 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى'),
        new OA\Property(property: 'locale', type: 'string', enum: ['ar'], example: 'ar'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ReviewAttemptRequest',
    required: ['client_attempt_uuid', 'recognized_text'],
    properties: [
        new OA\Property(property: 'client_attempt_uuid', type: 'string', format: 'uuid', example: '7d969c10-33ea-4e7e-8f20-181d0b180ea1'),
        new OA\Property(property: 'recognized_text', type: 'string', maxLength: 10000, example: 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى'),
        new OA\Property(property: 'locale', type: 'string', enum: ['ar'], example: 'ar'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'MemorizationEvaluationResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'تم تقييم التسميع بنجاح.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'attempt_id', type: 'integer', example: 101),
                new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
                new OA\Property(property: 'score', description: 'Authoritative deterministic score (0-100).', type: 'integer', example: 86),
                new OA\Property(property: 'verdict', type: 'string', enum: ['correct', 'acceptable', 'needs_review', 'incorrect'], example: 'needs_review'),
                new OA\Property(property: 'is_correct', type: 'boolean', example: false),
                new OA\Property(property: 'missing_words', type: 'array', items: new OA\Items(type: 'string'), example: ['إنما']),
                new OA\Property(property: 'extra_words', type: 'array', items: new OA\Items(type: 'string'), example: []),
                new OA\Property(property: 'incorrect_segments', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'feedback', description: 'Arabic feedback (Gemini when available, else a generic message).', type: 'string', example: 'التسميع جيد، أعد المقطع الأول مع التركيز على الكلمة الناقصة.'),
                new OA\Property(property: 'ai_feedback_available', description: 'False when Gemini was unavailable and the deterministic fallback was used.', type: 'boolean', example: true),
                new OA\Property(property: 'progress_status', type: 'string', enum: ['not_started', 'memorizing', 'reviewing', 'memorized'], example: 'memorizing'),
                new OA\Property(property: 'next_review_at', type: 'string', format: 'date-time', nullable: true, example: '2026-07-24T08:00:00Z'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Dashboard retrieved successfully.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'active_books', description: 'The books the user is working in — the ones they pushed a hadith of onto the stack or recited from.', type: 'array', items: new OA\Items(ref: '#/components/schemas/Book')),
                new OA\Property(property: 'total_books', type: 'integer', example: 2),
                new OA\Property(property: 'total_hadiths', description: 'Active hadiths in the whole catalogue — every user has every book.', type: 'integer', example: 40),
                new OA\Property(property: 'not_started', type: 'integer', example: 30),
                new OA\Property(property: 'memorizing', type: 'integer', example: 6),
                new OA\Property(property: 'reviewing', type: 'integer', example: 3),
                new OA\Property(property: 'memorized', type: 'integer', example: 1),
                new OA\Property(property: 'reviews_due_today', type: 'integer', example: 2),
                new OA\Property(property: 'stack_count', description: 'Hadiths currently pushed onto the memorization stack.', type: 'integer', example: 3),
                new OA\Property(property: 'recent_attempts', type: 'array', items: new OA\Items(type: 'object')),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'MemorizationQueueItem',
    description: 'One entry of the memorization stack: what to memorize, and why it is at this position.',
    properties: [
        new OA\Property(property: 'position', description: '1 is the top of the stack.', type: 'integer', example: 1),
        new OA\Property(
            property: 'queue_reason',
            description: 'pushed = put back on the stack by the user, Gemini, or the evaluator; due_review = the spaced-repetition date has arrived; new = not memorized yet.',
            type: 'string',
            enum: ['pushed', 'due_review', 'new'],
            example: 'pushed',
        ),
        new OA\Property(property: 'source', description: 'Who pushed it (pushed entries only).', type: 'string', enum: ['user', 'evaluation', 'ai'], nullable: true, example: 'user'),
        new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'درجة التسميع أقل من الحد المطلوب، الحديث بحاجة إلى مراجعة.'),
        new OA\Property(property: 'pushed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'hadith', ref: '#/components/schemas/Hadith'),
        new OA\Property(property: 'progress', ref: '#/components/schemas/UserProgress', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PushMemorizationStackRequest',
    required: ['hadith_id'],
    properties: [
        new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true, example: 'أحتاج مراجعة هذا الحديث.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'MemorizationStackItemResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'تمت إضافة الحديث إلى قائمة المراجعة.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
                new OA\Property(property: 'source', type: 'string', enum: ['user', 'evaluation', 'ai'], example: 'user'),
                new OA\Property(property: 'reason', type: 'string', nullable: true),
                new OA\Property(property: 'pushed_at', type: 'string', format: 'date-time'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class MemorizationSchemas {}
