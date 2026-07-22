<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Http\Resources\HadithResource;
use App\Models\Book;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class BookController extends Controller
{
    #[OA\Get(
        path: '/books',
        operationId: 'listBooks',
        summary: 'List active books',
        tags: ['Books'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated active books.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Book')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
        ],
    )]
    public function index()
    {
        $books = Book::where('is_active', true)
            ->withCount(['hadiths' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return BookResource::collection($books);
    }

    #[OA\Get(
        path: '/books/{book}',
        operationId: 'showBook',
        summary: 'Get an active book',
        tags: ['Books'],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Book detail.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Book'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'Not found or inactive.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(Book $book)
    {
        abort_unless($book->is_active, 404);

        $book->loadCount(['hadiths' => fn ($q) => $q->where('is_active', true)]);

        return ApiResponse::success(new BookResource($book), 'Book retrieved successfully.');
    }

    #[OA\Get(
        path: '/books/{book}/hadiths',
        operationId: 'listBookHadiths',
        summary: 'List active hadiths in a book',
        tags: ['Books'],
        parameters: [
            new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated hadiths.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Hadith')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 404, description: 'Book not found or inactive.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function hadiths(Book $book)
    {
        abort_unless($book->is_active, 404);

        $hadiths = $book->hadiths()
            ->where('is_active', true)
            ->with('narrator')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return HadithResource::collection($hadiths);
    }
}
