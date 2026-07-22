<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBookRequest;
use App\Http\Requests\Admin\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class BookController extends Controller
{
    #[OA\Get(
        path: '/admin/books',
        operationId: 'adminListBooks',
        summary: 'List books (admin)',
        tags: ['Admin - Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated books.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Book')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
        ],
    )]
    public function index()
    {
        $books = Book::withCount('hadiths')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(20);

        return BookResource::collection($books);
    }

    #[OA\Post(
        path: '/admin/books',
        operationId: 'adminCreateBook',
        summary: 'Create a book (admin)',
        tags: ['Admin - Books'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BookRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Book'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreBookRequest $request)
    {
        $book = Book::create($request->validated());

        return ApiResponse::success(new BookResource($book), 'Book created successfully.', 201);
    }

    #[OA\Get(
        path: '/admin/books/{book}',
        operationId: 'adminShowBook',
        summary: 'Get a book (admin)',
        tags: ['Admin - Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Book.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Book'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(Book $book)
    {
        $book->loadCount('hadiths');

        return ApiResponse::success(new BookResource($book), 'Book retrieved successfully.');
    }

    #[OA\Put(
        path: '/admin/books/{book}',
        operationId: 'adminUpdateBook',
        summary: 'Update a book (admin)',
        tags: ['Admin - Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BookRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Book'),
            ], type: 'object')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function update(UpdateBookRequest $request, Book $book)
    {
        $book->update($request->validated());

        return ApiResponse::success(new BookResource($book), 'Book updated successfully.');
    }

    #[OA\Delete(
        path: '/admin/books/{book}',
        operationId: 'adminDeleteBook',
        summary: 'Delete a book (admin)',
        tags: ['Admin - Books'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted.', content: new OA\JsonContent(ref: '#/components/schemas/SuccessMessage')),
            new OA\Response(response: 403, description: 'Admin only.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function destroy(Book $book)
    {
        $book->delete();

        return ApiResponse::success(null, 'Book deleted successfully.');
    }
}
