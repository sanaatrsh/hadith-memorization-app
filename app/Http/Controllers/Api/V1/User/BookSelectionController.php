<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserBookResource;
use App\Models\Book;
use App\Models\UserBook;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class BookSelectionController extends Controller
{
    #[OA\Get(
        path: '/user/books',
        operationId: 'listMyBooks',
        summary: 'List the user\'s active learning books',
        tags: ['My Books'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Selected books.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/UserBook')),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function index(Request $request)
    {
        $books = UserBook::where('user_id', $request->user()->id)
            ->whereNull('completed_at')
            ->with(['book' => fn ($q) => $q->withCount('hadiths')])
            ->latest('started_at')
            ->get();

        return UserBookResource::collection($books);
    }

    #[OA\Post(
        path: '/user/books/{book}/start',
        operationId: 'startBook',
        summary: 'Add a book to the learning list (idempotent)',
        tags: ['My Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'Book added.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/UserBook'),
            ], type: 'object')),
            new OA\Response(response: 200, description: 'Book was already selected.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/UserBook'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 404, description: 'Book not found or inactive.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function start(Request $request, Book $book)
    {
        abort_unless($book->is_active, 404);

        $userBook = UserBook::firstOrCreate(
            ['user_id' => $request->user()->id, 'book_id' => $book->id],
            ['started_at' => now()]
        );

        $userBook->load('book');

        return ApiResponse::success(
            new UserBookResource($userBook),
            $userBook->wasRecentlyCreated ? 'Book added to your learning list.' : 'Book already selected.',
            $userBook->wasRecentlyCreated ? 201 : 200
        );
    }

    /**
     * Stop presenting the book as active learning content. This does NOT
     * delete attempt history or progress, per the plan.
     */
    #[OA\Delete(
        path: '/user/books/{book}',
        operationId: 'removeBook',
        summary: 'Remove a book from the learning list (progress history is kept)',
        tags: ['My Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Removed.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 404, description: 'Book is not in the learning list.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Request $request, Book $book)
    {
        $deleted = UserBook::where('user_id', $request->user()->id)
            ->where('book_id', $book->id)
            ->delete();

        abort_if($deleted === 0, 404, 'Book is not in your learning list.');

        return ApiResponse::success(null, 'Book removed from your learning list.');
    }
}
