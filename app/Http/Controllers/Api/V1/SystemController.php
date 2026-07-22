<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class SystemController extends Controller
{
    #[OA\Get(
        path: '/health',
        operationId: 'health',
        summary: 'Service health check',
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Service is healthy.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
        ],
    )]
    public function health()
    {
        return ApiResponse::success(
            ['status' => 'ok', 'app' => config('app.name')],
            'Service is healthy.'
        );
    }
}
