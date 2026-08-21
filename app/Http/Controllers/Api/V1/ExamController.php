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
use App\Services\ExamAnswerEvaluator;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ExamController extends Controller
{
    #[OA\Get(
        path: '/exams',
        operationId: 'listExams',
        summary: "List the authenticated user's exams",
        description: 'Newest first. Questions and answers are not included; fetch a single exam for its questions.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated exams.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Exam')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
        ],
    )]
    public function index(Request $request)
    {
        $exams = Exam::where('user_id', $request->user()->id)
            ->with('book')
            ->orderByDesc('id')
            ->paginate(20);

        return ExamResource::collection($exams);
    }

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
            new OA\Response(response: 404, description: 'Book not found or inactive.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
            new OA\Response(response: 422, description: 'Validation failed / no templates or hadiths.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function store(StoreExamRequest $request, GenerateExam $action)
    {
        $user = $request->user();

        // Every user has the whole catalogue: an exam can be taken on any
        // active book, whether or not it is in the learning list.
        $book = Book::where('is_active', true)->findOrFail($request->integer('book_id'));

        // No count given means: every hadith in the book.
        $questionCount = $request->filled('question_count')
            ? $request->integer('question_count')
            : null;

        $exam = $action->execute($user, $book, $questionCount);

        return ApiResponse::success(new ExamResource($exam), 'Exam created successfully.', 201);
    }

    #[OA\Get(
        path: '/exams/{exam}',
        operationId: 'showExam',
        summary: 'Get an exam with its questions',
        description: 'While the exam is in progress each question only shows whether it has been answered. Once the exam is completed the same endpoint returns the score, the per-question result, and the correct answer for every question.',
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

        $exam->load(['questions.answer', 'book']);

        return ApiResponse::success(new ExamResource($exam), 'Exam retrieved successfully.');
    }

    #[OA\Post(
        path: '/exams/{exam}/answers',
        operationId: 'submitExamAnswer',
        summary: 'Submit an answer to an exam question',
        description: 'The answer is evaluated and stored, but the result is withheld: scores and correct answers are released only when the exam is completed. Written factual answers are exact-matched after Arabic normalization; recall and voice recitation answers reuse the deterministic transcript comparison. Rate limited to 30 requests/minute.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExamAnswerRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Answer received (result withheld until completion).', content: new OA\JsonContent(ref: '#/components/schemas/ExamAnswerResult')),
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

        ExamAnswer::updateOrCreate(
            ['exam_question_id' => $question->id],
            [
                'answer_text' => $request->input('answer_text'),
                'score' => $result['score'],
                'is_correct' => $result['is_correct'],
                'evaluation_report' => $result['report'],
            ]
        );

        // The score is stored but not disclosed: the learner sees every result
        // at once after finishing the whole exam.
        $questionIds = $exam->questions()->pluck('id');
        $answeredCount = ExamAnswer::whereIn('exam_question_id', $questionIds)->count();

        return ApiResponse::success([
            'exam_question_id' => $question->id,
            'is_answered' => true,
            'answered_count' => $answeredCount,
            'total_questions' => $questionIds->count(),
            'remaining_count' => max($questionIds->count() - $answeredCount, 0),
            'results_released' => false,
        ], 'تم استلام الإجابة. تظهر النتيجة بعد إنهاء جميع الأسئلة.');
    }

    #[OA\Post(
        path: '/exams/{exam}/complete',
        operationId: 'completeExam',
        summary: 'Complete an exam and compute the final score',
        description: 'Finalizes the exam and releases the results: the overall score (the average over all questions, unanswered ones counting as zero) plus, for every question, its score and the correct answer.',
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

        $questionIds = $exam->questions()->pluck('id');
        $scores = ExamAnswer::whereIn('exam_question_id', $questionIds)->pluck('score');

        // Unanswered questions count as zero so the score always reflects the
        // whole exam.
        $totalQuestions = max($questionIds->count(), 1);
        $finalScore = (int) round($scores->sum() / $totalQuestions);

        $exam->update([
            'status' => 'completed',
            'completed_at' => now(),
            'score' => $finalScore,
        ]);

        $exam->load(['questions.answer', 'book']);

        return ApiResponse::success(new ExamResource($exam), 'Exam completed successfully.');
    }

    private function authorizeExam(Exam $exam): void
    {
        abort_unless($exam->user_id === request()->user()->id, 403, 'This exam does not belong to you.');
    }
}
