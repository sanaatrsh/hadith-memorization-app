<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Narrator',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'عمر بن الخطاب'),
        new OA\Property(property: 'biography', type: 'string', nullable: true, example: 'أمير المؤمنين.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'HadithTerm',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 7),
        new OA\Property(property: 'term', type: 'string', example: 'النية'),
        new OA\Property(property: 'explanation', type: 'string', example: 'القصد والعزم على الفعل.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'HadithAid',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4),
        new OA\Property(property: 'title', type: 'string', example: 'تلميح'),
        new OA\Property(property: 'content', type: 'string', example: 'ركّز على الكلمة الأولى.'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UserProgress',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 12),
        new OA\Property(property: 'hadith_id', type: 'integer', example: 18),
        new OA\Property(property: 'status', type: 'string', enum: ['not_started', 'memorizing', 'reviewing', 'memorized'], example: 'memorizing'),
        new OA\Property(property: 'srs_level', type: 'integer', example: 1),
        new OA\Property(property: 'best_score', type: 'integer', example: 86),
        new OA\Property(property: 'attempts_count', type: 'integer', example: 3),
        new OA\Property(property: 'last_attempt_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'next_review_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'memorized_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Hadith',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 18),
        new OA\Property(property: 'book_id', type: 'integer', example: 2),
        new OA\Property(property: 'number_in_book', description: "The hadith's number inside its own collection — unique within the book, and what the app displays. Row id 100 may be hadith 4 of الأربعون النووية.", type: 'integer', nullable: true, example: 4),
        new OA\Property(property: 'narrator_id', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'title', type: 'string', example: 'إنما الأعمال بالنيات'),
        new OA\Property(property: 'intro', description: 'مقدمة الحديث — the isnad/context line the hadith opens with.', type: 'string', nullable: true, example: 'عن عمر بن الخطاب رضي الله عنه قال'),
        new OA\Property(property: 'text', type: 'string', example: 'إنما الأعمال بالنيات وإنما لكل امرئ ما نوى'),
        new OA\Property(property: 'source', type: 'string', nullable: true, example: 'صحيح البخاري'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 1),
        new OA\Property(property: 'audio_url', type: 'string', nullable: true, example: 'http://localhost/storage/1/recite.mp3'),
        new OA\Property(property: 'attachment_url', type: 'string', nullable: true),
        new OA\Property(property: 'narrator', ref: '#/components/schemas/Narrator', nullable: true),
        new OA\Property(property: 'terms', type: 'array', items: new OA\Items(ref: '#/components/schemas/HadithTerm')),
        new OA\Property(property: 'aids', type: 'array', items: new OA\Items(ref: '#/components/schemas/HadithAid')),
        new OA\Property(property: 'progress', ref: '#/components/schemas/UserProgress', nullable: true),
        new OA\Property(property: 'on_memorization_stack', description: 'Present when authenticated: the hadith sits on the memorization stack.', type: 'boolean', nullable: true, example: true),
        new OA\Property(property: 'stack_source', type: 'string', enum: ['user', 'evaluation', 'ai'], nullable: true, example: 'user'),
    ],
    type: 'object',
)]
final class HadithSchemas {}
