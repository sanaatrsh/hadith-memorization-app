# Athar API reference

Athar is an Arabic-first API for memorizing hadiths, reviewing them with spaced repetition, and completing exams. The interactive OpenAPI reference is served at `/api/documentation`; its JSON definition is served at `/docs`.

## Quick start

Seed the deterministic Arabic demo catalogue and start the application:

```bash
php artisan migrate:fresh --seed
php artisan l5-swagger:generate
```

Demo accounts are for local development only. Both use the password `password`.

| Account | Email | Role | Use |
| --- | --- | --- | --- |
| مدير أثر | `admin@athar.test` | `admin` | Admin-only content, import, user, and statistics endpoints. |
| سناء أحمد | `user@athar.test` | `user` | Book selection, memorization, reviews, and exams. |

The seeded catalogue has two active books, four Arabic hadiths, narrators, vocabulary terms, memory aids, question templates, selected books, and progress for the learner. IDs are database-generated; retrieve them from `GET /api/v1/books` rather than assuming fixed IDs.

## Swagger access

Swagger UI needs to load the OpenAPI JSON before a bearer token can be entered in the UI. Therefore the Swagger UI, assets, and JSON are public by default. Set `L5_SWAGGER_RESTRICT_ACCESS=true` only when an upstream access control layer or the application's `EnsureSwaggerAccess` middleware is configured for all four Swagger routes. After changing the setting in production, run:

```bash
php artisan config:clear
php artisan l5-swagger:generate
php artisan config:cache
```

## Base URL and headers

All application routes below start with `/api/v1`. The unauthenticated health route is `GET /api/health`.

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <sanctum-token>
```

`Authorization` is required only where the reference says **Token** or **Admin**. In Swagger, click **Authorize** and enter the token returned by register or login; Swagger sends the bearer prefix automatically.

## Response formats

Most write and detail endpoints use the following envelope:

```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": {}
}
```

Paginated resource lists deliberately use Laravel's resource-collection format instead:

```json
{
  "data": [],
  "links": {
    "first": "https://example.test/api/v1/books?page=1",
    "last": "https://example.test/api/v1/books?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 2
  }
}
```

Validation failures are Laravel JSON validation responses (`422`):

```json
{
  "message": "The recognized text field is required.",
  "errors": {
    "recognized_text": ["The recognized text field is required."]
  }
}
```

`401` means a token is absent or invalid. `403` means the authenticated user is inactive, lacks the admin role, has not selected the relevant book, or does not own the requested exam. `404` can also mean an inactive public book or hadith. Auth, memorization, review, exam, and import endpoints are rate limited as described below.

## Authentication

| Method | Endpoint | Access | Request body | Result |
| --- | --- | --- | --- | --- |
| POST | `/auth/register` | Public | `name`, `email`, `password`, `password_confirmation`; optional `birth_date` | Creates a user and returns a Sanctum token (`201`). |
| POST | `/auth/login` | Public | `email`, `password`; optional `device_name` | Returns a Sanctum token (`200`). |
| POST | `/auth/logout` | Token | None | Revokes the current token. |
| GET | `/auth/me` | Token | None | Returns the current user. |

Register and login are limited to 10 requests per minute.

### Login example

```http
POST /api/v1/auth/login
Accept: application/json
Content-Type: application/json

{
  "email": "user@athar.test",
  "password": "password",
  "device_name": "swagger-ui"
}
```

```json
{
  "success": true,
  "message": "Logged in successfully.",
  "data": {
    "user": {
      "id": 2,
      "name": "سناء أحمد",
      "email": "user@athar.test",
      "birth_date": "1998-05-20",
      "role": "user",
      "is_active": true,
      "created_at": "2026-07-23T00:00:00.000000Z",
      "updated_at": "2026-07-23T00:00:00.000000Z"
    },
    "token": "2|example-token"
  }
}
```

Store the token securely in the client and send it on later requests. A failed password returns `422` with an `errors.email` entry; an inactive account returns `403`.

## Public catalogue

| Method | Endpoint | Access | Request | Response |
| --- | --- | --- | --- | --- |
| GET | `/books?page=1` | Public | Optional `page`. | Paginated active `Book` resources. |
| GET | `/books/{book}` | Public | Book ID path parameter. | Envelope containing one active `Book`. |
| GET | `/books/{book}/hadiths?page=1` | Public | Book ID and optional `page`. | Paginated active `Hadith` resources with narrator. |
| GET | `/hadiths/{hadith}` | Public; token optional | Hadith ID path parameter. | Envelope containing canonical text, narrator, terms, aids, media URLs, and authenticated user's progress when a token is supplied. |

### Book-list response example

```json
{
  "data": [
    {
      "id": 1,
      "title": "الأربعون النووية",
      "description": "مجموعة مختارة من جوامع كلم النبي صلى الله عليه وسلم للتدرّب على الحفظ والمراجعة.",
      "is_active": true,
      "sort_order": 1,
      "cover_url": null,
      "hadiths_count": 2,
      "created_at": "2026-07-23T00:00:00.000000Z",
      "updated_at": "2026-07-23T00:00:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 2 }
}
```

## Learning, memorization, and review

All endpoints in this section require an active token. The learner must first select a book; selection is idempotent and is required before attempting a hadith or generating an exam from that book.

| Method | Endpoint | Request body | Response / explanation |
| --- | --- | --- | --- |
| GET | `/user/books` | None | Resource collection of selected, unfinished books. |
| POST | `/user/books/{book}/start` | None | Selected `UserBook` envelope; `201` on first selection and `200` if already selected. |
| DELETE | `/user/books/{book}` | None | Removes the book selection but preserves attempts and progress. |
| GET | `/user/progress` | None | Dashboard envelope: active books, total/status counts, due review count, and current empty `recent_attempts` list. |
| GET | `/user/reviews/due?page=1` | Optional `page` | Paginated due `UserProgress` resources. |
| POST | `/memorization/attempts` | See example below. | Deterministic transcript evaluation, with optional Gemini feedback. 30/min. |
| POST | `/reviews/{hadith}/attempts` | Same as memorization without `hadith_id`. | Same evaluation, tagged as a review. 30/min. |

### Select a book

```http
POST /api/v1/user/books/1/start
Authorization: Bearer <token>
Accept: application/json
```

```json
{
  "success": true,
  "message": "Book added to your learning list.",
  "data": {
    "id": 1,
    "book_id": 1,
    "started_at": "2026-07-23T00:00:00.000000Z",
    "completed_at": null,
    "book": {
      "id": 1,
      "title": "الأربعون النووية",
      "is_active": true,
      "sort_order": 1,
      "cover_url": null
    }
  }
}
```

### Submit a memorization attempt

```http
POST /api/v1/memorization/attempts
Authorization: Bearer <token>
Content-Type: application/json

{
  "client_attempt_uuid": "7d969c10-33ea-4e7e-8f20-181d0b180ea1",
  "hadith_id": 1,
  "recognized_text": "إنما الأعمال بالنيات وإنما لكل امرئ ما نوى",
  "locale": "ar",
  "started_at": "2026-07-23T08:00:00Z"
}
```

```json
{
  "success": true,
  "message": "تم تقييم التسميع بنجاح.",
  "data": {
    "attempt_id": 1,
    "hadith_id": 1,
    "score": 100,
    "verdict": "correct",
    "is_correct": true,
    "missing_words": [],
    "extra_words": [],
    "incorrect_segments": [],
    "feedback": "تم تقييم التسميع بناءً على المقارنة النصية. حاول تحسين الكلمات الناقصة.",
    "ai_feedback_available": false,
    "progress_status": "reviewing",
    "next_review_at": "2026-07-30T08:00:00.000000Z",
    "comparison": {
      "score": 100,
      "missing_words": [],
      "extra_words": [],
      "substitutions": []
    }
  }
}
```

`client_attempt_uuid` is required and makes retries idempotent for the same user. The deterministic word comparison controls `score` and `verdict`; Gemini feedback is additional only. If Gemini is unavailable, the request still succeeds with `ai_feedback_available: false` and a deterministic report. The API compares text only; it does not assess pronunciation.

## Exams

| Method | Endpoint | Request body | Result |
| --- | --- | --- | --- |
| POST | `/exams` | `book_id`, `question_count` (1–50). | Creates an in-progress exam (`201`) from active templates and active hadiths in a selected book. |
| GET | `/exams/{exam}` | None. | Returns the caller's exam and questions; correct answers are not exposed. |
| POST | `/exams/{exam}/answers` | `exam_question_id`, `answer_text`. | Evaluates and upserts one answer. |
| POST | `/exams/{exam}/complete` | None. | Marks the caller's exam complete and stores the average answer score. |

### Create-exam request and response

```json
POST /api/v1/exams
{ "book_id": 1, "question_count": 3 }
```

```json
{
  "success": true,
  "message": "Exam created successfully.",
  "data": {
    "id": 1,
    "user_id": 2,
    "book_id": 1,
    "status": "in_progress",
    "question_count": 3,
    "score": null,
    "started_at": "2026-07-23T00:00:00.000000Z",
    "completed_at": null,
    "questions": [
      {
        "id": 1,
        "hadith_id": 1,
        "type": "written",
        "question_text": "من هو راوي هذا الحديث؟",
        "sort_order": 0,
        "answer": null
      }
    ]
  }
}
```

The database chooses the question IDs and generated questions. Use the ID in the response when submitting an answer. An answer request such as `{"exam_question_id":1,"answer_text":"عمر بن الخطاب"}` returns `exam_question_id`, `score`, `is_correct`, and `evaluation_report` inside `data`.

## Administrator endpoints

All administrator endpoints require a valid token for a user whose `role` is `admin`.

| Resource | Endpoints | Write request fields | Response |
| --- | --- | --- | --- |
| Books | `GET, POST /admin/books`; `GET, PUT, PATCH, DELETE /admin/books/{book}` | Create: `title` required; optional `description`, `is_active`, `sort_order`. Update: same fields, all optional. | Collection for index; `Book` envelope for create/show/update; success envelope for delete. |
| Narrators | `GET, POST /admin/narrators`; `PUT, PATCH, DELETE /admin/narrators/{narrator}` | Create: `name` required; optional `biography`. Update: same fields optional. | Collection or `Narrator` envelope; success envelope for delete. There is no show route. |
| Hadiths | `GET, POST /admin/hadiths`; `GET, PUT, PATCH, DELETE /admin/hadiths/{hadith}` | Create: `book_id`, `title`, `text` required; optional `narrator_id`, `source`, `is_active`, `sort_order`. Update: same fields optional. | Collection or full `Hadith` envelope; success envelope for delete. |
| Terms | `GET, POST /admin/hadiths/{hadith}/terms`; `PUT, DELETE /admin/hadiths/{hadith}/terms/{term}` | Create: `term`, `explanation`; update: either or both. | Collection or `HadithTerm` envelope. |
| Aids | `GET, POST /admin/hadiths/{hadith}/aids`; `PUT, DELETE /admin/hadiths/{hadith}/aids/{aid}` | Create: `title`, `content`; optional `sort_order`. Update: same fields optional. | Collection or `HadithAid` envelope. |
| Audio | `POST /admin/hadiths/{hadith}/audio`; `POST /admin/hadiths/{hadith}/audio/replace`; `DELETE /admin/hadiths/{hadith}/audio` | Multipart form field `audio`; allowed audio types, maximum 20 MB. | `audio_url` envelope after upload/replace; success envelope after delete. |
| Users | `GET /admin/users`; `GET /admin/users/{user}`; `PATCH /admin/users/{user}/status` | Status body: `is_active` required boolean. | User collection or `User` envelope. |
| Manual progress | `PATCH /admin/users/{user}/hadiths/{hadith}/progress` | Optional `status` (`not_started`, `memorizing`, `reviewing`, `memorized`), `srs_level` (0–10), nullable `next_review_at`, optional `reason` up to 500 chars. | Progress envelope; an audit row is recorded. |
| Import | `GET /admin/imports/hadiths/template`; `POST /admin/imports/hadiths`; `GET /admin/imports/{import}` | Upload `file` as CSV, TXT, XLSX, or XLS; 10 MB maximum. | CSV download; import result envelope; import-status envelope. Upload is limited to 5/min. |
| Statistics | `GET /admin/statistics/overview`, `/memorization`, `/users` | None. | Envelopes with aggregate counts and reports. |

### Create an Arabic hadith

```http
POST /api/v1/admin/hadiths
Authorization: Bearer <admin-token>
Content-Type: application/json

{
  "book_id": 1,
  "narrator_id": 1,
  "title": "إنما الأعمال بالنيات",
  "text": "إنما الأعمال بالنيات وإنما لكل امرئ ما نوى",
  "source": "صحيح البخاري وصحيح مسلم",
  "is_active": true,
  "sort_order": 1
}
```

```json
{
  "success": true,
  "message": "Hadith created successfully.",
  "data": {
    "id": 5,
    "book_id": 1,
    "narrator_id": 1,
    "title": "إنما الأعمال بالنيات",
    "text": "إنما الأعمال بالنيات وإنما لكل امرئ ما نوى",
    "source": "صحيح البخاري وصحيح مسلم",
    "is_active": true,
    "sort_order": 1,
    "audio_url": null,
    "attachment_url": null
  }
}
```

For imports, download the template first. It contains the required heading row:

```text
book_title,hadith_title,hadith_text,narrator_name,source,terms_json,assistance_notes,sort_order,is_active
```

`terms_json` is an array such as `[ {"term":"النيات","explanation":"المقاصد التي يقصدها الإنسان بعمله."} ]`. The import response reports `import_id`, `status`, `total_rows`, `imported_rows`, `failed_rows`, and row-level `errors`; a partial upload returns `201` with `status: "completed_with_errors"`.

## Operational notes

- Generate the documentation after annotation changes with `php artisan l5-swagger:generate`.
- Do not put Gemini credentials or Sanctum tokens into Swagger examples, source control, or client logs.
- Audio and import endpoints require `multipart/form-data`, not JSON.
- Preserve IDs returned by this API. Demo IDs are not a permanent external contract.
