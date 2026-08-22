# Athar API

Arabic-first Laravel API for the **Athar** hadith memorization application.

Laravel is the source of truth. Flutter performs speech-to-text and submits the
recognized Arabic text; the backend evaluates recall with a **deterministic**
Arabic word-comparison (the authoritative score) and uses **Gemini** for
structured Arabic feedback only. A simple spaced-repetition schedule, written &
voice exams, CSV/Excel import, and admin statistics are included.

The whole book catalogue belongs to every user — there is no per-user book list
and nothing has to be selected before memorizing. What to memorize next comes
from a **stack**: hadiths the user asks to review — or that the evaluator and
Gemini flag — sit on top, then reviews that are due, then the untouched hadiths
of the book the user touched most recently. Exams cover a whole book (one
question per hadith) and release every result, including the correct answers,
only once all the questions are done. Answers are typed, so short factual ones
are matched token by token — «تميم بن أوس الداري» answers «تميم الداري».

## Stack

- Laravel 13, PHP 8.3+
- Sanctum (token auth for Flutter)
- Spatie Media Library (book covers, official hadith audio)
- maatwebsite/excel (hadith import)
- Gemini via Laravel HTTP client (no SDK), behind a `HadithEvaluator` interface

## Requirements

PHP and Composer are provided by **Laravel Herd** on this machine and are on the
PowerShell PATH. Run `php`, `composer`, `artisan`, and Pint from PowerShell.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure the database in `.env` (MySQL default: `athar_api`, user `root`), then:

```bash
php artisan migrate --seed
php artisan storage:link
```

Seeded accounts: `admin@athar.test` / `password` (admin), `user@athar.test` / `password`.

## Running & Testing

```bash
php artisan serve         # http://localhost:8000
php artisan test          # full suite
./vendor/bin/pint         # format
```

## Key Directories

```text
app/
  Actions/Memorization/EvaluateMemorizationAttempt.php   # core evaluation flow
  Actions/Exam/GenerateExam.php
  Clients/Gemini/                                        # Gemini client + parser
  Contracts/Ai/HadithEvaluator.php                       # AI provider interface
  Data/Ai/HadithEvaluationData.php                       # validated AI result DTO
  Services/ArabicTextNormalizer.php
  Services/HadithTextComparisonService.php               # authoritative scoring
  Services/SpacedRepetitionService.php
  Services/ExamAnswerEvaluator.php
  Services/MemorizationStackService.php                   # the memorization queue (stack)
  Enums/                                                 # finite states
  Http/Controllers/Api/V1/                               # thin controllers
config/athar.php                                         # scoring, normalization, SRS
docs/API.md                                              # full endpoint reference
```

## Configuration Notes

- Scoring thresholds, Arabic normalization rules, and SRS intervals live in
  `config/athar.php`.
- Gemini credentials are server-only; see `docs/API.md` → Gemini Configuration.
- Transcript retention: `attempts:prune-transcripts` (scheduled daily) removes
  raw transcript text older than `ATTEMPT_TRANSCRIPT_RETENTION_DAYS` while
  keeping scores and reports.

## API Documentation (Swagger / OpenAPI)

Interactive Swagger UI (L5-Swagger) with the Sanctum bearer scheme:

```text
GET /api/documentation      # Swagger UI
GET /openapi                # raw OpenAPI JSON
```

Regenerate the OpenAPI document:

```bash
php artisan l5-swagger:generate
```

- `L5_SWAGGER_GENERATE_ALWAYS=true` regenerates on each request (local only).
- OpenAPI attributes live on `app/OpenApi/**` (definition + reusable schemas)
  and directly on the `app/Http/Controllers/Api/V1` methods.
- Swagger UI is open in `local`/`testing`; elsewhere it requires an authenticated
  admin (`App\Http\Middleware\EnsureSwaggerAccess`).
- To authorize in the UI: log in via `/auth/login`, copy the token, click
  **Authorize**, and paste it (sent as `Authorization: Bearer <token>`).
- Gemini keys, headers, prompts, and raw payloads are never documented.

CI / deployment:

```bash
php artisan test
php artisan l5-swagger:generate
php artisan config:cache
php artisan route:cache
```

See [docs/API.md](docs/API.md) for the complete API reference, error codes,
import template, and deployment checklist.
