<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Exam\GenerateExam;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteExamRequest;
use App\Http\Requests\StoreExamAnswerRequest;
use App\Http\Requests\StoreExamRequest;
use App\Http\Resources\ExamResource;
use App\Models\Book;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Services\ExamAnswerEvaluator;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

class ExamController extends Controller
{
    #[OA\Get(
        path: '/exams',
        operationId: 'listExams',
        summary: "List the authenticated user's exams",
        description: 'One exam per book, newest first. Questions, items and answers are not included; fetch a single exam for those.',
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
        summary: "Open a book's exam",
        description: 'Every book has one exam per learner. The first call builds it from the stored question bank and the book\'s own content; calling it again on the same book resets that exam — the previous questions and answers are cleared — rather than creating a second one. '
            .'Without question_count the exam stays short: hadiths added to the book today if there are any, else athar.exams.default_question_count (6) hadiths from the book — never the whole book. Correct answers and scores are never returned before the exam is completed.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExamRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Exam opened (created, or reset if the book already had one).', content: new OA\JsonContent(properties: [
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

        $questionCount = $request->filled('question_count')
            ? $request->integer('question_count')
            : null;

        $exam = $action->execute($user, $book, $questionCount);

        return ApiResponse::success(
            new ExamResource($this->loadForResponse($exam)),
            'Exam created successfully.',
            201,
        );
    }

    #[OA\Get(
        path: '/books/{book}/exam',
        operationId: 'showBookExam',
        summary: "Get the authenticated user's exam for a book",
        description: 'A book has one exam per learner; this is it, with its questions and items. 404 while the learner has not opened it yet — POST /exams with the book_id to open it.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'book', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Exam.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Exam'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 404, description: 'The book has no exam for this learner yet.', content: new OA\JsonContent(ref: '#/components/schemas/NotFoundError')),
        ],
    )]
    public function forBook(Request $request, Book $book)
    {
        $exam = Exam::where('user_id', $request->user()->id)
            ->where('book_id', $book->id)
            ->firstOrFail();

        return ApiResponse::success(
            new ExamResource($this->loadForResponse($exam)),
            'Exam retrieved successfully.',
        );
    }

    #[OA\Get(
        path: '/exams/{exam}',
        operationId: 'showExam',
        summary: 'Get an exam with its questions and items',
        description: 'Returns the exam twice over from the same rows: `questions` is the stored structure — each wording once, with one answer row per hadith it is asked about — and `items` is that flattened into the list the learner works through. An item\'s `id` is what an answer is submitted against. '
            .'While the exam is in progress each item only shows whether it has been answered. Once the exam is completed the same endpoint returns the score, the per-item result, and the correct answer for every item.',
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

        return ApiResponse::success(
            new ExamResource($this->loadForResponse($exam)),
            'Exam retrieved successfully.',
        );
    }

    #[OA\Post(
        path: '/exams/{exam}/answers',
        operationId: 'submitExamAnswer',
        summary: 'Submit an answer to one exam item',
        description: 'The item is named by its own id (`exam_answer_id`, or `item_id`) — the `id` of an entry in the exam\'s `items` — or by `exam_question_id` together with `hadith_id`. A bare question id is accepted only while that question covers a single hadith, and refused as ambiguous otherwise. '
            .'The answer is evaluated and stored, but the result is withheld: scores and correct answers are released only when the exam is completed. Answers are typed, never chosen from a list, so Gemini judges each one against the item\'s stored reference — tolerant of ordinary Arabic spelling and grammatical-case variance for a factual answer (narrator / takhrij), while a recall answer (complete / recite the matn) is still held to the full wording. If Gemini is unavailable, evaluation falls back to a deterministic comparison so the exam is never blocked. Rate limited to 30 requests/minute.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExamAnswerRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Answer received (result withheld until completion).', content: new OA\JsonContent(ref: '#/components/schemas/ExamAnswerResult')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Exam belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Exam already completed / the item is unknown or ambiguous / validation failed.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function answer(StoreExamAnswerRequest $request, Exam $exam, ExamAnswerEvaluator $evaluator)
    {
        $this->authorizeExam($exam);

        abort_unless($exam->status === 'in_progress', 422, 'This exam is already completed.');

        $items = $this->items($exam);
        $reference = $request->reference();
        $resolved = $this->resolveItem($items, $reference);

        if (is_string($resolved)) {
            return ApiResponse::error($resolved, 422);
        }

        $this->recordAnswer($resolved, $reference['answer_text'], $evaluator);

        // The score is stored but not disclosed: the learner sees every result
        // at once after finishing the whole exam.
        $answeredCount = $this->items($exam)->filter(fn (ExamAnswer $item) => $item->isAnswered())->count();

        return ApiResponse::success([
            'exam_answer_id' => $resolved->id,
            'exam_question_id' => $resolved->exam_question_id,
            'hadith_id' => $resolved->hadith_id,
            'is_answered' => true,
            'answered_count' => $answeredCount,
            'total_questions' => $items->count(),
            'remaining_count' => max($items->count() - $answeredCount, 0),
            'results_released' => false,
        ], 'تم استلام الإجابة. تظهر النتيجة بعد إنهاء جميع الأسئلة.');
    }

    #[OA\Post(
        path: '/exams/{exam}/complete',
        operationId: 'completeExam',
        summary: 'Complete an exam and compute the final score',
        description: 'Finalizes the exam and releases the results: the overall score (the average over all items, unanswered ones counting as zero) plus, for every item, its score and the correct answer. '
            .'The whole set of answers may be submitted with this request instead of one at a time — pass `answers` as a list of entries naming the item (`exam_answer_id` / `item_id`, or `exam_question_id` with `hadith_id`) and its text (`answer_text`, or `answer`). They are graded before the exam is finalized. Every entry must resolve to exactly one item of this exam, so a mistyped or ambiguous id fails loudly rather than being dropped.',
        tags: ['Exams'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'exam', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/CompleteExamRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Exam completed.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Exam'),
            ], type: 'object')),
            new OA\Response(response: 401, description: 'Unauthenticated.', content: new OA\JsonContent(ref: '#/components/schemas/UnauthenticatedError')),
            new OA\Response(response: 403, description: 'Exam belongs to another user.', content: new OA\JsonContent(ref: '#/components/schemas/ForbiddenError')),
            new OA\Response(response: 422, description: 'Exam already completed, or an answer names an item outside this exam.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function complete(CompleteExamRequest $request, Exam $exam, ExamAnswerEvaluator $evaluator)
    {
        $this->authorizeExam($exam);

        abort_unless($exam->status === 'in_progress', 422, 'This exam is already completed.');

        $items = $this->items($exam);

        if ($submitted = $request->answers()) {
            $error = $this->gradeSubmittedAnswers($items, $submitted, $evaluator);

            if ($error !== null) {
                return $error;
            }
        }

        // Unanswered items count as zero so the score always reflects the
        // whole exam.
        $totalItems = max($items->count(), 1);
        $finalScore = (int) round($this->items($exam)->sum('score') / $totalItems);

        $exam->update([
            'status' => 'completed',
            'completed_at' => now(),
            'score' => $finalScore,
        ]);

        return ApiResponse::success(
            new ExamResource($this->loadForResponse($exam)),
            'Exam completed successfully.',
        );
    }

    /**
     * Grade a whole set of answers submitted with the completion request.
     *
     * A reference that does not resolve to exactly one item of this exam is
     * refused rather than skipped: silently dropping an answer is
     * indistinguishable, from the app's side, from a wrong grade. Nothing is
     * graded until every entry has resolved.
     *
     * @param  Collection<int,ExamAnswer>  $items
     * @param  array<int,array{exam_answer_id:?int, exam_question_id:?int, hadith_id:?int, answer_text:string}>  $submitted
     */
    private function gradeSubmittedAnswers(Collection $items, array $submitted, ExamAnswerEvaluator $evaluator): ?JsonResponse
    {
        $resolved = [];
        $errors = [];

        foreach ($submitted as $answer) {
            $item = $this->resolveItem($items, $answer);

            if (is_string($item)) {
                $errors[] = $item;

                continue;
            }

            $resolved[] = [$item, $answer['answer_text']];
        }

        if ($errors !== []) {
            return ApiResponse::error(implode(' ', array_unique($errors)), 422);
        }

        foreach ($resolved as [$item, $text]) {
            $this->recordAnswer($item, $text, $evaluator);
        }

        return null;
    }

    /**
     * The exam item a submitted answer belongs to, or the reason it could not
     * be pinned down to exactly one.
     *
     * @param  Collection<int,ExamAnswer>  $items
     * @param  array{exam_answer_id:?int, exam_question_id:?int, hadith_id:?int, answer_text:string}  $reference
     */
    private function resolveItem(Collection $items, array $reference): ExamAnswer|string
    {
        if ($reference['exam_answer_id'] !== null) {
            return $items->firstWhere('id', $reference['exam_answer_id'])
                ?? "Item {$reference['exam_answer_id']} does not belong to this exam.";
        }

        $questionId = $reference['exam_question_id'];

        if ($questionId === null) {
            return 'Name the item being answered: exam_answer_id, or exam_question_id with hadith_id.';
        }

        $candidates = $items->where('exam_question_id', $questionId);

        if ($reference['hadith_id'] !== null) {
            if ($candidates->isEmpty()) {
                return "Question {$questionId} does not belong to this exam.";
            }

            return $candidates->firstWhere('hadith_id', $reference['hadith_id'])
                ?? "Question {$questionId} is not asked about hadith {$reference['hadith_id']} in this exam.";
        }

        // A bare id could be either, and a client built against the previous
        // one-question-per-hadith shape sends an item id under this key. Both
        // sets are searched, and an id that hits both is refused: resolving it
        // by guesswork would silently grade the wrong item.
        $asItem = $items->firstWhere('id', $questionId);

        if ($candidates->isNotEmpty() && $asItem !== null
            && ! ($candidates->count() === 1 && $candidates->first()->id === $asItem->id)) {
            // Both readings are live and they disagree.
            return "Id {$questionId} names both a question and an item of this exam; send exam_answer_id, or exam_question_id with hadith_id.";
        }

        if ($asItem !== null) {
            return $asItem;
        }

        if ($candidates->isEmpty()) {
            return "Question {$questionId} does not belong to this exam.";
        }

        // A question covering several hadiths cannot be answered by naming the
        // question alone — guessing which hadith was meant would look exactly
        // like a wrong grade.
        return $candidates->count() === 1
            ? $candidates->first()
            : "Question {$questionId} covers {$candidates->count()} hadiths in this exam; send hadith_id, or the item's own exam_answer_id.";
    }

    private function recordAnswer(ExamAnswer $item, string $answerText, ExamAnswerEvaluator $evaluator): void
    {
        $result = $evaluator->evaluate($item, $answerText);

        $item->update([
            'answer_text' => $answerText,
            'score' => $result['score'],
            'is_correct' => $result['is_correct'],
            'evaluation_report' => $result['report'],
            'answered_at' => now(),
        ]);
    }

    /**
     * The exam's items, each with the question it words and the hadith it is
     * about — everything grading and rendering need.
     *
     * @return Collection<int,ExamAnswer>
     */
    private function items(Exam $exam): Collection
    {
        return $exam->items()->with(['question', 'hadith'])->get();
    }

    /**
     * Loads the exam the way ExamResource expects: its questions with their
     * answers, and each answer pointed back at its question so the flat
     * `items` view can carry the wording without a second query.
     */
    private function loadForResponse(Exam $exam): Exam
    {
        $exam->load(['book', 'questions.answers.hadith']);

        foreach ($exam->questions as $question) {
            foreach ($question->answers as $answer) {
                $answer->setRelation('question', $question);
            }
        }

        return $exam;
    }

    private function authorizeExam(Exam $exam): void
    {
        abort_unless($exam->user_id === request()->user()->id, 403, 'This exam does not belong to you.');
    }
}
