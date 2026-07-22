<?php

use App\Http\Controllers\Api\V1\Admin\BookController as AdminBookController;
use App\Http\Controllers\Api\V1\Admin\HadithAidController;
use App\Http\Controllers\Api\V1\Admin\HadithAudioController;
use App\Http\Controllers\Api\V1\Admin\HadithController as AdminHadithController;
use App\Http\Controllers\Api\V1\Admin\HadithImportController;
use App\Http\Controllers\Api\V1\Admin\HadithTermController;
use App\Http\Controllers\Api\V1\Admin\NarratorController;
use App\Http\Controllers\Api\V1\Admin\StatisticsController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\UserProgressController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\HadithController;
use App\Http\Controllers\Api\V1\MemorizationAttemptController;
use App\Http\Controllers\Api\V1\ReviewAttemptController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\User\BookSelectionController;
use App\Http\Controllers\Api\V1\User\DashboardController;
use App\Http\Controllers\Api\V1\User\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Service is healthy.',
        'data' => [
            'status' => 'ok',
            'app' => config('app.name'),
        ],
    ]);
});

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    // --- System ---
    Route::get('/health', [SystemController::class, 'health']);

    // --- Authentication (public, rate limited) ---
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    // --- Authenticated ---
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });

    // --- Authenticated user: learning selection & progress ---
    Route::middleware(['auth:sanctum', 'active'])->prefix('user')->group(function () {
        Route::get('/books', [BookSelectionController::class, 'index']);
        Route::post('/books/{book}/start', [BookSelectionController::class, 'start']);
        Route::delete('/books/{book}', [BookSelectionController::class, 'destroy']);
        Route::get('/progress', [DashboardController::class, 'show']);
        Route::get('/reviews/due', [ReviewController::class, 'due']);
    });

    // --- Memorization & review evaluation (rate limited) ---
    Route::middleware(['auth:sanctum', 'active', 'throttle:30,1'])->group(function () {
        Route::post('/memorization/attempts', [MemorizationAttemptController::class, 'store']);
        Route::post('/reviews/{hadith}/attempts', [ReviewAttemptController::class, 'store']);
        Route::post('/exams/{exam}/answers', [ExamController::class, 'answer']);
    });

    // --- Exam lifecycle ---
    Route::middleware(['auth:sanctum', 'active', 'throttle:20,1'])->group(function () {
        Route::post('/exams', [ExamController::class, 'store']);
        Route::get('/exams/{exam}', [ExamController::class, 'show']);
        Route::post('/exams/{exam}/complete', [ExamController::class, 'complete']);
    });

    // --- Public / user content (active only) ---
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{book}', [BookController::class, 'show']);
    Route::get('/books/{book}/hadiths', [BookController::class, 'hadiths']);
    Route::get('/hadiths/{hadith}', [HadithController::class, 'show']);

    // --- Administrator ---
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'admin'])
        ->group(function () {
            Route::apiResource('books', AdminBookController::class);
            Route::apiResource('narrators', NarratorController::class)->except('show');
            Route::apiResource('hadiths', AdminHadithController::class);

            // Hadith official audio
            Route::post('hadiths/{hadith}/audio', [HadithAudioController::class, 'store']);
            Route::post('hadiths/{hadith}/audio/replace', [HadithAudioController::class, 'replace']);
            Route::delete('hadiths/{hadith}/audio', [HadithAudioController::class, 'destroy']);

            // Nested vocabulary terms
            Route::get('hadiths/{hadith}/terms', [HadithTermController::class, 'index']);
            Route::post('hadiths/{hadith}/terms', [HadithTermController::class, 'store']);
            Route::put('hadiths/{hadith}/terms/{term}', [HadithTermController::class, 'update']);
            Route::delete('hadiths/{hadith}/terms/{term}', [HadithTermController::class, 'destroy']);

            // Nested memorization aids
            Route::get('hadiths/{hadith}/aids', [HadithAidController::class, 'index']);
            Route::post('hadiths/{hadith}/aids', [HadithAidController::class, 'store']);
            Route::put('hadiths/{hadith}/aids/{aid}', [HadithAidController::class, 'update']);
            Route::delete('hadiths/{hadith}/aids/{aid}', [HadithAidController::class, 'destroy']);

            // Users
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::patch('users/{user}/status', [UserController::class, 'updateStatus']);
            Route::patch('users/{user}/hadiths/{hadith}/progress', [UserProgressController::class, 'update']);

            // Imports
            Route::get('imports/hadiths/template', [HadithImportController::class, 'template']);
            Route::post('imports/hadiths', [HadithImportController::class, 'store'])->middleware('throttle:5,1');
            Route::get('imports/{import}', [HadithImportController::class, 'show']);

            // Statistics
            Route::get('statistics/overview', [StatisticsController::class, 'overview']);
            Route::get('statistics/memorization', [StatisticsController::class, 'memorization']);
            Route::get('statistics/users', [StatisticsController::class, 'users']);
        });
});
