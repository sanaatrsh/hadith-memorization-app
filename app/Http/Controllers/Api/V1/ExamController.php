<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exam\GenerateExam;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExamAnswerRequest;
use App\Http\Requests\StoreExamRequest;
use App\Http\Resources\ExamResource;
use App\Models\Book;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\UserBook;
use App\Services\ExamAnswerEvaluator;
use App\Support\ApiResponse;
use OpenApi\Attributes as OA;

class ExamController extends Controller
{
    #[OA\Post(
        path: '/exams',
        operationId: 'createExam',
        summary: 'Create a final exam',
        description: 'Generates questions from stored templates and the selected book\'s content only. Correct answers are never returned before submission.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExamRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Exam created.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Exam'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Book not selected by the user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Validation failed / no templates or hadiths.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreExamRequest $request, GenerateExam $action)
    {
        $user = $request->user();
        $book = Book::where('is_active', true)->findOrFail($request->integer('book_id'));

        $ownsBook = UserBook::where('user_id', $user->id)->where('book_id', $book->id)->exists();
        if (! $ownsBook) {
            return ApiResponse::error('You have not selected this book.', 403);
        }

        $exam = $action->execute($user, $book, $request->integer('question_count'));

        return ApiResponse::success(new ExamResource($exam), 'Exam created successfully.', 201);
    }

    #[OA\Get(
        path: '/exams/{exam}',
        operationId: 'showExam',
        summary: 'Get an exam with its questions',
        description: 'Correct answers are hidden. Each question includes the submitted answer when present.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Exam.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Exam'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Exam belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 404, description: 'Not found.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function show(Exam $exam)
    {
        $this->authorizeExam($exam);

        $exam->load(['questions.answer']);

        return ApiResponse::success(new ExamResource($exam), 'Exam retrieved successfully.');
    }

    #[OA\Post(
        path: '/exams/{exam}/answers',
        operationId: 'submitExamAnswer',
        summary: 'Submit an answer to an exam question',
        description: 'Written factual answers are exact-matched after Arabic normalization; recall and voice recitation answers reuse the deterministic transcript comparison. Rate limited to 30 requests/minute.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExamAnswerRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Answer evaluated.', content: new OA\JsonContent(ref: '#/components/schemas/ExamAnswerResult')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Exam belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Exam already completed / validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function answer(StoreExamAnswerRequest $request, Exam $exam, ExamAnswerEvaluator $evaluator)
    {
        $this->authorizeExam($exam);

        abort_unless($exam->status === 'in_progress', 422, 'This exam is already completed.');

        /** @var ExamQuestion $question */
        $question = $exam->questions()->findOrFail($request->integer('exam_question_id'));

        $result = $evaluator->evaluate($question, (string) $request->input('answer_text'));

        $answer = ExamAnswer::updateOrCreate(
            ['exam_question_id' => $question->id],
            [
                'answer_text' => $request->input('answer_text'),
                'score' => $result['score'],
                'is_correct' => $result['is_correct'],
                'evaluation_report' => $result['report'],
            ]
        );

        return ApiResponse::success([
            'exam_question_id' => $question->id,
            'score' => $answer->score,
            'is_correct' => $answer->is_correct,
            'evaluation_report' => $answer->evaluation_report,
        ], 'Answer submitted successfully.');
    }

    #[OA\Post(
        path: '/exams/{exam}/complete',
        operationId: 'completeExam',
        summary: 'Complete an exam and compute the final score',
        description: 'Finalizes the exam; the score is the average of all answer scores.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Exam completed.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Exam'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Exam belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Exam already completed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function complete(Exam $exam)
    {
        $this->authorizeExam($exam);

        abort_unless($exam->status === 'in_progress', 422, 'This exam is already completed.');

        $scores = ExamAnswer::whereIn('exam_question_id', $exam->questions()->pluck('id'))->pluck('score');
        $finalScore = $scores->isEmpty() ? 0 : (int) round($scores->avg());

        $exam->update([
            'status' => 'completed',
            'completed_at' => now(),
            'score' => $finalScore,
        ]);

        $exam->load(['questions.answer']);

        return ApiResponse::success(new ExamResource($exam), 'Exam completed successfully.');
    }

    private function authorizeExam(Exam $exam): void
    {
        abort_unless($exam->user_id === request()->user()->id, 403, 'This exam does not belong to you.');
    }
}
