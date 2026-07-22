# Athar API Documentation

Arabic-first Laravel API for hadith memorization, review, evaluation, and exams.

- Base URL: `/api`
- Versioned routes: `/api/v1/...`
- Health check: `GET /api/health`

## Response Envelope

Every business endpoint returns a consistent envelope:

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

Errors:

```json
{
  "success": false,
  "message": "This action is unauthorized.",
  "errors": { "field": ["..."] }
}
```

Paginated list endpoints return Laravel's standard resource-collection shape (`data`, `links`, `meta`).

## Authentication

Sanctum personal access tokens. Flutter sends:

```http
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json
```

| Method | Endpoint | Auth | Notes |
|---|---|---|---|
| POST | `/api/v1/auth/register` | public | Creates a `user`-role account, returns token. Rate limited 10/min. |
| POST | `/api/v1/auth/login` | public | Returns token. Rate limited 10/min. |
| POST | `/api/v1/auth/logout` | token | Revokes current token. |
| GET | `/api/v1/auth/me` | token | Current user profile. |

Register / login example:

```json
POST /api/v1/auth/login
{ "email": "user@athar.test", "password": "password", "device_name": "pixel-8" }

200 -> { "success": true, "data": { "user": {...}, "token": "1|abc..." } }
```

## Public / User Content (active content only)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/books` | Active books (paginated). |
| GET | `/api/v1/books/{book}` | Book detail. |
| GET | `/api/v1/books/{book}/hadiths` | Active hadiths in a book. |
| GET | `/api/v1/hadiths/{hadith}` | Hadith detail: text, narrator, source, audio URL, terms, aids, and the current user's progress when authenticated. |

## Learning Selection & Progress (token + active)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/user/books` | User's active learning books. |
| POST | `/api/v1/user/books/{book}/start` | Add a book (idempotent). |
| DELETE | `/api/v1/user/books/{book}` | Remove from learning list (keeps progress history). |
| GET | `/api/v1/user/progress` | Dashboard aggregation (counts, reviews due). |
| GET | `/api/v1/user/reviews/due` | Reviews due now. |

## Memorization & Review Evaluation (token + active, 30/min)

```json
POST /api/v1/memorization/attempts
{
  "client_attempt_uuid": "7d969c10-33ea-4e7e-8f20-181d0b180ea1",
  "hadith_id": 15,
  "recognized_text": "النص المستخرج من صوت المستخدم",
  "locale": "ar",
  "started_at": "2026-07-22T12:00:00Z"
}
```

Response `data`:

```json
{
  "attempt_id": 101,
  "hadith_id": 15,
  "score": 86,
  "verdict": "needs_review",
  "is_correct": false,
  "missing_words": ["إنما"],
  "extra_words": [],
  "incorrect_segments": [],
  "feedback": "…",
  "ai_feedback_available": true,
  "progress_status": "memorizing",
  "next_review_at": "2026-07-23T08:00:00Z"
}
```

- `POST /api/v1/reviews/{hadith}/attempts` — same flow tagged as a review (no `hadith_id` in body).
- The **deterministic comparison is the authoritative score**. Gemini feedback is diagnostic and never overrides it.
- Duplicate `client_attempt_uuid` returns the existing attempt (idempotent).

## Final Exams (token + active)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/exams` | `{ book_id, question_count }` — generates questions from stored templates + book content. |
| GET | `/api/v1/exams/{exam}` | Exam with questions (correct answers hidden). |
| POST | `/api/v1/exams/{exam}/answers` | `{ exam_question_id, answer_text }`. Written factual = exact match; recall/voice = word comparison. |
| POST | `/api/v1/exams/{exam}/complete` | Finalizes; `score` = average of answer scores. |

## Administrator (token + admin role)

Books, narrators, hadiths CRUD:

```text
GET|POST|GET|PUT|DELETE  /api/v1/admin/books[/{book}]
GET|POST|PUT|DELETE      /api/v1/admin/narrators[/{narrator}]
GET|POST|GET|PUT|DELETE  /api/v1/admin/hadiths[/{hadith}]
```

Hadith official audio (Spatie Media Library):

```text
POST   /api/v1/admin/hadiths/{hadith}/audio           (field: audio)
POST   /api/v1/admin/hadiths/{hadith}/audio/replace
DELETE /api/v1/admin/hadiths/{hadith}/audio
```

Nested terms & aids:

```text
GET|POST                 /api/v1/admin/hadiths/{hadith}/terms
PUT|DELETE               /api/v1/admin/hadiths/{hadith}/terms/{term}
GET|POST                 /api/v1/admin/hadiths/{hadith}/aids
PUT|DELETE               /api/v1/admin/hadiths/{hadith}/aids/{aid}
```

Users & manual progress (auditable):

```text
GET    /api/v1/admin/users
GET    /api/v1/admin/users/{user}
PATCH  /api/v1/admin/users/{user}/status                       { is_active }
PATCH  /api/v1/admin/users/{user}/hadiths/{hadith}/progress    { status?, srs_level?, next_review_at?, reason? }
```

Imports & statistics:

```text
GET  /api/v1/admin/imports/hadiths/template   (CSV download)
POST /api/v1/admin/imports/hadiths            (field: file; 5/min)
GET  /api/v1/admin/imports/{import}
GET  /api/v1/admin/statistics/overview
GET  /api/v1/admin/statistics/memorization
GET  /api/v1/admin/statistics/users
```

## Import Template

CSV/XLSX columns (UTF-8, heading row required):

```text
book_title, hadith_title, hadith_text, narrator_name, source, terms_json, assistance_notes, sort_order, is_active
```

- `terms_json`: JSON array, e.g. `[{"term":"النية","explanation":"القصد"}]`.
- Books/narrators are matched by title/name. Hadiths are always created (duplicates are never guessed from text).
- Response reports `imported_rows`, `failed_rows`, and per-row `errors`.

## Error Codes

| HTTP | Meaning |
|---|---|
| 401 | Missing/invalid token. |
| 403 | Not admin / inactive account / book not selected / not your exam. |
| 404 | Missing or inactive resource. |
| 422 | Validation failure (invalid transcript, missing fields, no templates/hadiths). |
| 429 | Rate limit exceeded. |

Gemini failure codes stored on attempts (fallback still returns a deterministic result): `missing_api_key`, `gemini_timeout`, `gemini_rate_limited`, `gemini_http_error`, `gemini_error`, `empty_ai_response`, `invalid_ai_json`, `missing_ai_field`, `invalid_ai_field`.

## Gemini Configuration

Server-only environment (never sent to Flutter):

```dotenv
GEMINI_API_KEY=
GEMINI_API_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_MODEL=gemini-3.6-flash
GEMINI_TIMEOUT_SECONDS=30
GEMINI_CONNECT_TIMEOUT_SECONDS=5
GEMINI_RETRY_TIMES=2
GEMINI_STORE=false
```

- Requests authenticate with the `x-goog-api-key` header (never `Authorization: Bearer`).
- Structured JSON output is requested and re-validated server-side.
- On any Gemini failure the API returns the deterministic report with `ai_feedback_available: false`.

## Deployment Checklist

- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, run `php artisan key:generate`.
- [ ] Configure MySQL/Postgres credentials; run `php artisan migrate --force`.
- [ ] Set `GEMINI_API_KEY` (server env only).
- [ ] Configure `MEDIA_DISK` (e.g. `s3`) and run `php artisan storage:link` for the public disk.
- [ ] Run the scheduler (`php artisan schedule:work` / cron) for transcript pruning.
- [ ] Run a queue worker if evaluation is moved to jobs later.
- [ ] `php artisan config:cache route:cache` for production.
- [ ] Confirm HTTPS and CORS for the Flutter origin.
- [ ] Verify logs contain no secrets (only ids, model, status, latency).
