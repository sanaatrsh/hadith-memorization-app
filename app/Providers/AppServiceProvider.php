<?php

namespace App\Providers;

use App\Clients\Gemini\GeminiClient;
use App\Clients\Gemini\GeminiExamAnswerGrader;
use App\Contracts\Ai\ExamAnswerGrader;
use App\Contracts\Ai\HadithEvaluator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HadithEvaluator::class, GeminiClient::class);
        $this->app->bind(ExamAnswerGrader::class, GeminiExamAnswerGrader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
