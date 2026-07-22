<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'سناء'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@athar.test'),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true, example: '1998-05-20'),
        new OA\Property(property: 'role', type: 'string', enum: ['admin', 'user'], example: 'user'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'RegisterRequest',
    required: ['name', 'email', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'سناء'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@athar.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password123!'),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'Password123!'),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true, example: '1998-05-20'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'LoginRequest',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@athar.test'),
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
        new OA\Property(property: 'device_name', type: 'string', nullable: true, example: 'pixel-8'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AuthResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Logged in successfully.'),
        new OA\Property(
            property: 'data',
            properties: [
                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                new OA\Property(property: 'token', type: 'string', example: '1|xxxxxxxxxxxxxxxxxxxx'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class AuthSchemas {}
