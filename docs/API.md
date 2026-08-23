# Athar API reference

Athar is an Arabic-first API for memorizing hadiths, reviewing them with spaced repetition, and completing exams. The interactive OpenAPI reference is served at `/api/documentation`; its JSON definition is served at `/openapi`.

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
| GET | `/books?page=1` | Public; token optional | Optional `page`. | Paginated active `Book` resources — the whole catalogue. With a token each book also carries `is_started`, `progress_count`, and `memorized_count`. |
| GET | `/books/{book}` | Public; token optional | Book ID path parameter. | Envelope containing one active `Book` (with `is_started` / `progress_count` / `memorized_count` when a token is supplied). |
| GET | `/books/{book}/hadiths?page=1` | Public | Book ID and optional `page`. | Paginated active `Hadith` resources with narrator. |
| GET | `/hadiths/{hadith}` | Public; token optional | Hadith ID path parameter. | Envelope containing `intro` (مقدمة الحديث), canonical text, narrator, terms, aids, media URLs, and — with a token — the user's progress and whether the hadith is on their memorization stack. |

**There is no per-user book list.** Every active book and hadith is open to
every user, and nothing has to be "selected" first: any active hadith can be
recited (`POST /memorization/attempts`) or pushed onto the memorization stack
straight from the catalogue. A book simply starts counting as one the user works
in — `is_started`, and `active_books` on the dashboard — as soon as they push one
of its hadiths or recite one.

`intro` is the isnad/context line a hadith opens with — «عن عمر بن الخطاب رضي
الله عنه قال» or «بينما نحن جلوس عند رسول الله ﷺ». It is stored apart from
`text` so recall is only ever scored against the matn.

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
      "is_started": true,
      "progress_count": 18,
      "memorized_count": 12,
      "created_at": "2026-07-23T00:00:00.000000Z",
      "updated_at": "2026-07-23T00:00:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 2 }
}
```

## Learning, memorization, and review

All endpoints in this section require an active token. Nothing requires picking
a book first: any active hadith can be recited or pushed onto the stack, and
exams may be taken on any active book.

| Method | Endpoint | Request body | Response / explanation |
| --- | --- | --- | --- |
| GET | `/user/progress` | None | Dashboard envelope: the books the user is working in, catalogue totals, status counts, `reviews_due_today`, `stack_count`, and the current empty `recent_attempts` list. |
| GET | `/user/reviews/due?page=1` | Optional `page` | Paginated due `UserProgress` resources. |
| GET | `/user/memorization/stack?limit=20&book_id=1` | Optional `limit` (1–100, default 20) and `book_id` | The memorization stack: what to memorize next, top first. `book_id` scopes the queue to one book. |
| POST | `/user/memorization/stack` | `hadith_id`, optional `reason` | Pushes any active hadith onto the top of the stack (`201`) — no book selection involved. Pushing one already on it moves it back to the top instead of duplicating. |
| DELETE | `/user/memorization/stack/{hadith}` | None | Pops the hadith off the stack; progress and attempt history are kept. |
| POST | `/memorization/attempts` | See example below. | Deterministic transcript evaluation, with optional Gemini feedback. 30/min. |
| POST | `/reviews/{hadith}/attempts` | Same as memorization without `hadith_id`. | Same evaluation, tagged as a review. 30/min. |

### The memorization stack

`GET /user/memorization/stack` is the queue the app reads from. It is layered,
top first:

1. **Pushed items** — hadiths explicitly put back on the stack, LIFO. The user's
   own review requests (`source: user`) sit above the ones the evaluator
   (`source: evaluation`) or Gemini (`source: ai`) flagged; within a source the
   newest push is on top.
2. **Due reviews** — spaced-repetition dates that have arrived, earliest first.
3. **Not memorized yet** — from the books the user is already working in, the
   most recently touched first, then that book's own order (`sort_order`).

A book counts as one the user works in as soon as they push one of its hadiths
onto the stack or recite one, so the queue stays empty until the user starts
something instead of dumping the whole catalogue into it. Memorized hadiths drop
out until their review comes due. Pass `book_id` to get the queue for one
book — handy while browsing a book the user has not started yet.

Pushes happen three ways:

- The user asks for it: `POST /user/memorization/stack` (any active hadith).
- The evaluation scores a recitation below `athar.scoring.passing` — the hadith
  is pushed with `source: evaluation`.
- Gemini recommends `repeat_now` / `review_later`, or returns a
  `needs_review` / `incorrect` verdict — pushed with `source: ai` and Gemini's
  Arabic feedback as `reason`. This applies even when the deterministic score
  passed.

A recitation at or above the passing score with no Gemini objection pops the
hadith off the stack automatically.

```json
GET /api/v1/user/memorization/stack?limit=20

{
  "success": true,
  "message": "Memorization stack retrieved successfully.",
  "data": {
    "total": 8,
    "pushed_count": 2,
    "items": [
      {
        "position": 1,
        "queue_reason": "pushed",
        "source": "user",
        "reason": "أحتاج مراجعة هذا الحديث.",
        "pushed_at": "2026-08-21T09:00:00.000000Z",
        "hadith": { "id": 18, "title": "إنما الأعمال بالنيات", "intro": "عن عمر بن الخطاب رضي الله عنه قال", "text": "..." },
        "progress": { "status": "reviewing", "srs_level": 2, "best_score": 86, "next_review_at": "2026-08-24T09:00:00.000000Z" }
      },
      {
        "position": 2,
        "queue_reason": "due_review",
        "source": null,
        "reason": null,
        "pushed_at": null,
        "hadith": { "id": 21, "title": "بني الإسلام على خمس", "intro": null, "text": "..." },
        "progress": { "status": "reviewing", "srs_level": 1, "best_score": 92, "next_review_at": "2026-08-20T09:00:00.000000Z" }
      }
    ]
  }
}
```

`queue_reason` is `pushed`, `due_review`, or `new`; `source` and `reason` are
present on pushed entries only.

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
| POST | `/exams` | `book_id`, optional `question_count` (1–200). | Creates an in-progress exam (`201`) from active templates and the book's own content. Any active book may be examined. |
| GET | `/exams/{exam}` | None. | Returns the caller's exam and questions. While it is in progress each question only shows whether it has been answered; once completed the same endpoint returns the results. |
| POST | `/exams/{exam}/answers` | `exam_question_id`, `answer_text`. | Evaluates and stores one answer but withholds the result — the response is an acknowledgement with the answered/remaining counts. |
| POST | `/exams/{exam}/complete` | None. | Completes the exam and releases the results: the overall score plus, for every question, its score and the correct answer. |

**Question count.** Omitting `question_count` builds a short exam: the
hadiths added to the book **today** if there are any — a "test what you added
today" exam — otherwise `athar.exams.default_question_count` (6) hadiths from
the start of the book. It never defaults to the whole book. Passing
`question_count` explicitly always narrows the exam to that many distinct
hadiths of the whole book instead (capped at the book's hadith count), ignoring
the "today" preference. Question templates rotate so the types stay varied, and
a template that would ask about something a hadith does not carry (no narrator,
no takhrij, no `intro`) is skipped for that hadith.

**How a written answer is judged.** Answers are typed, never picked from a
list, so **Gemini grades every answer** against the stored reference — tolerant
of ordinary Arabic spelling and grammatical-case variance for a factual answer
(narrator / takhrij), such as «أبي هريرة» for «أبو هريرة» or «تميم بن أوس
الداري» for «تميم الداري», while still holding a recall answer (complete /
recite the matn) to the full wording, with partial credit for a partial
recitation. The report is `{"mode": "gemini", "feedback_ar": "…", …}`.

If Gemini is unavailable — no API key, a timeout, a rate limit, an invalid
response — the exam is still graded: evaluation falls back to a deterministic
comparison so it is never blocked on Gemini being reachable. For a factual
answer that is a token-by-token comparison (`athar.answers.*` configures which
words are ignored, e.g. «بن»، «رضي الله عنه»، «رواه», and how much of the
stored answer must be covered); for a recall answer it is the same
word-sequence comparison the live memorization flow uses. The fallback report
carries `"mode": "token_match"` or the word-sequence fields, plus
`"ai_available": false` and a `failure_code`.

**Deferred results.** Nothing about correctness is disclosed until the exam is
completed: `results_released` is `false`, `score` is `null`, and questions carry
neither `correct_answer` nor a per-question score. `POST /exams/{exam}/complete`
flips `results_released` to `true` and returns, for **every** question — right or
wrong — its `score`, `is_correct`, `evaluation_report`, and `correct_answer`,
alongside a `summary` (`total_questions`, `answered_count`, `unanswered_count`,
`correct_count`, `incorrect_count`, `passing_score`). Unanswered questions count
as zero in the overall score.

### Create-exam request and response

```json
POST /api/v1/exams
{ "book_id": 1 }
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
    "question_count": 6,
    "results_released": false,
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
        "is_answered": false
      }
    ]
  }
}
```

The database chooses the question IDs and generated questions. Use the ID in the
response when submitting an answer. An answer request such as
`{"exam_question_id":1,"answer_text":"عمر بن الخطاب"}` returns only an
acknowledgement — `exam_question_id`, `is_answered`, `answered_count`,
`total_questions`, `remaining_count`, `results_released: false` — because the
result is released when the exam is completed:

```json
POST /api/v1/exams/1/complete

{
  "success": true,
  "message": "Exam completed successfully.",
  "data": {
    "id": 1,
    "status": "completed",
    "results_released": true,
    "score": 76,
    "summary": {
      "total_questions": 2,
      "answered_count": 2,
      "unanswered_count": 0,
      "correct_count": 1,
      "incorrect_count": 1,
      "passing_score": 90
    },
    "questions": [
      {
        "id": 1,
        "hadith_id": 1,
        "type": "written",
        "question_text": "من هو راوي هذا الحديث؟",
        "sort_order": 0,
        "is_answered": true,
        "correct_answer": "عمر بن الخطاب",
        "is_correct": true,
        "score": 100,
        "answer": { "answer_text": "عمر بن الخطاب رضي الله عنه", "score": 100, "is_correct": true, "evaluation_report": { "mode": "gemini", "feedback_ar": "إجابة صحيحة، نفس الاسم بصيغة إعرابية مختلفة." } }
      },
      {
        "id": 2,
        "hadith_id": 2,
        "type": "voice",
        "question_text": "اذكر الحديث كاملاً من عنوانه: بني الإسلام على خمس",
        "sort_order": 1,
        "is_answered": true,
        "correct_answer": "بني الإسلام على خمس ...",
        "is_correct": false,
        "score": 52,
        "answer": { "answer_text": "...", "score": 52, "is_correct": false, "evaluation_report": { "mode": "gemini", "feedback_ar": "ذكر جزءا من الحديث ونقص الباقي." } }
      }
    ]
  }
}
```

## Administrator endpoints

All administrator endpoints require a valid token for a user whose `role` is `admin`.

| Resource | Endpoints | Write request fields | Response |
| --- | --- | --- | --- |
| Books | `GET, POST /admin/books`; `GET, PUT, PATCH, DELETE /admin/books/{book}` | Create: `title` required; optional `description`, `is_active`, `sort_order`. Update: same fields, all optional. | Collection for index; `Book` envelope for create/show/update; success envelope for delete. |
| Narrators | `GET, POST /admin/narrators`; `PUT, PATCH, DELETE /admin/narrators/{narrator}` | Create: `name` required; optional `biography`. Update: same fields optional. | Collection or `Narrator` envelope; success envelope for delete. There is no show route. |
| Hadiths | `GET, POST /admin/hadiths`; `GET, PUT, PATCH, DELETE /admin/hadiths/{hadith}` | Create: `book_id`, `title`, `text` required; optional `intro` (مقدمة الحديث, up to 2000 chars), `narrator_id`, `source`, `is_active`, `sort_order`. Update: same fields optional. | Collection or full `Hadith` envelope; success envelope for delete. |
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
  "intro": "عن عمر بن الخطاب رضي الله عنه قال: سمعت رسول الله صلى الله عليه وسلم يقول",
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
    "intro": "عن عمر بن الخطاب رضي الله عنه قال: سمعت رسول الله صلى الله عليه وسلم يقول",
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
book_title,hadith_title,hadith_intro,hadith_text,narrator_name,source,terms_json,assistance_notes,sort_order,is_active
```

`hadith_intro` is optional and holds مقدمة الحديث. `terms_json` is an array such as `[ {"term":"النيات","explanation":"المقاصد التي يقصدها الإنسان بعمله."} ]`. The import response reports `import_id`, `status`, `total_rows`, `imported_rows`, `failed_rows`, and row-level `errors`; a partial upload returns `201` with `status: "completed_with_errors"`.

## Operational notes

- Generate the documentation after annotation changes with `php artisan l5-swagger:generate`.
- Do not put Gemini credentials or Sanctum tokens into Swagger examples, source control, or client logs.
- Audio and import endpoints require `multipart/form-data`, not JSON.
- Preserve IDs returned by this API. Demo IDs are not a permanent external contract.
