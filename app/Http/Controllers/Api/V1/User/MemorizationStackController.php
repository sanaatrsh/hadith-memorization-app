<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\StackSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemorizationStackItemRequest;
use App\Http\Resources\MemorizationQueueItemResource;
use App\Models\Hadith;
use App\Services\MemorizationStackService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The memorization stack (قائمة الحفظ): the hadiths the user is going to
 * memorize next, top of the stack first.
 */
class MemorizationStackController extends Controller
{
    public function __construct(private readonly MemorizationStackService $stack) {}

    #[OA\Get(
        path: '/user/memorization/stack',
        operationId: 'memorizationStack',
        summary: 'List the hadiths the user should memorize next (stack, top first)',
        description: 'Layered queue: (1) hadiths pushed onto the stack — the user\'s own review requests first, then the ones Gemini or the evaluator flagged, newest push first; (2) reviews due now, earliest first; (3) hadiths not memorized yet from the books the user is already working in (any book they pushed a hadith of or recited from), most recent activity first, then the book\'s own order. Pass book_id to scope the whole queue to one book.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'How many entries to return (1-100, default 20).', schema: new OA\Schema(type: 'integer', example: 20)),
            new OA\Parameter(name: 'book_id', in: 'query', required: false, description: 'Scope the queue to one book.', schema: new OA\Schema(type: 'integer', example: 2)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The memorization stack.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'total', type: 'integer', example: 8),
                    new OA\Property(property: 'pushed_count', type: 'integer', example: 2),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/MemorizationQueueItem')),
                ], type: 'object'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function index(Request $request)
    {
        $limit = max(1, min((int) $request->integer('limit', 20) ?: 20, 100));
        $bookId = $request->filled('book_id') ? $request->integer('book_id') : null;

        $queue = $this->stack->queue($request->user(), $limit, $bookId);

        return ApiResponse::success([
            'total' => $queue->count(),
            'pushed_count' => $queue->where('queue_reason', MemorizationStackService::REASON_PUSHED)->count(),
            'items' => MemorizationQueueItemResource::collection($queue),
        ], 'Memorization stack retrieved successfully.');
    }

    #[OA\Post(
        path: '/user/memorization/stack',
        operationId: 'pushMemorizationStack',
        summary: 'Push a hadith onto the top of the memorization stack',
        description: 'Marks a hadith as needing review. Any active hadith of any active book can be pushed — no book selection is involved. Pushing a hadith already on the stack moves it back to the top instead of duplicating it.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PushMemorizationStackRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Hadith pushed onto the stack.', content: new OA\JsonContent(ref: '#/components/schemas/MemorizationStackItemResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 422, description: 'Validation failed or hadith inactive.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreMemorizationStackItemRequest $request)
    {
        $user = $request->user();
        $hadith = Hadith::findOrFail($request->integer('hadith_id'));

        if (! $hadith->is_active) {
            return ApiResponse::error('This hadith is not available.', 422);
        }

        $item = $this->stack->push(
            user: $user,
            hadith: $hadith,
            source: StackSource::User,
            reason: $request->input('reason'),
        );

        return ApiResponse::success([
            'hadith_id' => $item->hadith_id,
            'source' => $item->source->value,
            'reason' => $item->reason,
            'pushed_at' => $item->pushed_at,
        ], 'تمت إضافة الحديث إلى قائمة المراجعة.', 201);
    }

    #[OA\Delete(
        path: '/user/memorization/stack/{hadith}',
        operationId: 'popMemorizationStack',
        summary: 'Remove a hadith from the memorization stack',
        description: 'Pops the hadith off the stack. Progress and attempt history are kept.',
        tags: ['Memorization'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'hadith', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Removed.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 404, description: 'The hadith is not on the stack.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Request $request, Hadith $hadith)
    {
        $resolved = $this->stack->resolve($request->user(), $hadith);

        abort_unless($resolved, 404, 'This hadith is not on your memorization stack.');

        return ApiResponse::success(null, 'تمت إزالة الحديث من قائمة المراجعة.');
    }
}
