# Athar — ERD

The database has 27 tables, but 10 of them are not domain tables: Laravel's own
`migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`,
`sessions`, `password_reset_tokens`, plus `media` (Spatie, polymorphic) and
`personal_access_tokens` (Sanctum). The remaining 17 are below.

## Core

The nine tables the memorization and exam flows actually run on.

```mermaid
erDiagram
    BOOKS ||--o{ HADITHS : contains

    USERS ||--o{ USER_HADITH_PROGRESS : tracks
    HADITHS ||--o{ USER_HADITH_PROGRESS : "tracked in"

    USERS ||--o{ MEMORIZATION_ATTEMPTS : submits
    HADITHS ||--o{ MEMORIZATION_ATTEMPTS : "recited in"

    USERS ||--o{ MEMORIZATION_STACK_ITEMS : queues
    HADITHS ||--o{ MEMORIZATION_STACK_ITEMS : "queued as"

    USERS ||--o{ EXAMS : takes
    BOOKS ||--o| EXAMS : "examined by one"
    EXAMS ||--o{ EXAM_QUESTIONS : contains
    EXAM_QUESTIONS ||--o{ EXAM_ANSWERS : "asked once per hadith"
    HADITHS ||--o{ EXAM_ANSWERS : "asked about"

    USERS {
        bigint id PK
        string email UK
        string role "user | admin"
        boolean is_active
    }

    BOOKS {
        bigint id PK
        string title
        boolean is_active
        int sort_order
    }

    HADITHS {
        bigint id PK
        bigint book_id FK
        int number_in_book "UK with book_id"
        bigint narrator_id FK "nullable"
        string title
        text intro "nullable"
        text text
        string source "nullable"
        boolean is_active
        int sort_order
    }

    USER_HADITH_PROGRESS {
        bigint id PK
        bigint user_id FK
        bigint hadith_id FK "UK with user_id"
        string status
        tinyint srs_level
        timestamp next_review_at "nullable"
        timestamp memorized_at "nullable"
    }

    MEMORIZATION_ATTEMPTS {
        bigint id PK
        uuid client_attempt_uuid "UK with user_id"
        bigint user_id FK
        bigint hadith_id FK
        string type "memorization | review"
        tinyint final_score
        string verdict "nullable"
    }

    MEMORIZATION_STACK_ITEMS {
        bigint id PK
        bigint user_id FK
        bigint hadith_id FK "UK with user_id"
        string source "user | evaluation | ai"
        timestamp pushed_at
        timestamp resolved_at "nullable"
    }

    EXAMS {
        bigint id PK
        bigint user_id FK "UK with book_id"
        bigint book_id FK
        int question_count "number of items"
        string status "in_progress | completed"
        tinyint score "nullable"
    }

    EXAM_QUESTIONS {
        bigint id PK
        bigint exam_id FK
        bigint question_template_id FK "nullable"
        string type "written | voice"
        text question_text "names no hadith"
        int sort_order
    }

    EXAM_ANSWERS {
        bigint id PK
        bigint exam_question_id FK "UK with hadith_id"
        bigint hadith_id FK
        text correct_answer "nullable"
        text answer_text "nullable"
        tinyint score
        boolean is_correct
        timestamp answered_at "nullable"
    }
```

One question, many answers. The wording is stored once in `exam_questions` and
names no hadith; `exam_answers` holds one row per hadith the question is asked
about, carrying that hadith's own reference answer alongside what the learner
submitted. A row of `exam_answers` is therefore one *item* of the exam: it is
what gets answered and scored, and the exam's length is its number of items,
not of wordings.

`exams` is unique on (`user_id`, `book_id`): every book has one exam per
learner, and retaking it resets that exam rather than piling up another.

## Full

Adds content enrichment (`narrators`, `hadith_terms`, `hadith_aids`), the exam
question bank (`question_templates`), the daily review sitting
(`review_sessions`, `review_session_items`), and the two admin logs
(`progress_audits`, `hadith_imports`).

```mermaid
erDiagram
    BOOKS ||--o{ HADITHS : contains
    NARRATORS |o--o{ HADITHS : narrates
    HADITHS ||--o{ HADITH_TERMS : "explained by"
    HADITHS ||--o{ HADITH_AIDS : "aided by"

    USERS ||--o{ USER_HADITH_PROGRESS : tracks
    HADITHS ||--o{ USER_HADITH_PROGRESS : "tracked in"

    USERS ||--o{ MEMORIZATION_ATTEMPTS : submits
    HADITHS ||--o{ MEMORIZATION_ATTEMPTS : "recited in"

    USERS ||--o{ MEMORIZATION_STACK_ITEMS : queues
    HADITHS ||--o{ MEMORIZATION_STACK_ITEMS : "queued as"

    USERS ||--o{ PROGRESS_AUDITS : "audited (learner)"
    USERS ||--o{ PROGRESS_AUDITS : "recorded by (admin)"
    HADITHS ||--o{ PROGRESS_AUDITS : "audited on"

    USERS ||--o{ EXAMS : takes
    BOOKS ||--o| EXAMS : "examined by one"
    EXAMS ||--o{ EXAM_QUESTIONS : contains
    QUESTION_TEMPLATES |o--o{ EXAM_QUESTIONS : words
    EXAM_QUESTIONS ||--o{ EXAM_ANSWERS : "asked once per hadith"
    HADITHS ||--o{ EXAM_ANSWERS : "asked about"

    USERS ||--o{ REVIEW_SESSIONS : sits
    REVIEW_SESSIONS ||--o{ REVIEW_SESSION_ITEMS : snapshots
    HADITHS ||--o{ REVIEW_SESSION_ITEMS : "reviewed in"
    MEMORIZATION_ATTEMPTS |o--o| REVIEW_SESSION_ITEMS : "scored by"

    USERS ||--o{ HADITH_IMPORTS : uploads

    USERS {
        bigint id PK
        string name
        string email UK
        string password
        string role "user | admin"
        boolean is_active
    }

    BOOKS {
        bigint id PK
        string title
        text description
        boolean is_active
        int sort_order
    }

    NARRATORS {
        bigint id PK
        string name
        text biography
    }

    HADITHS {
        bigint id PK
        bigint book_id FK
        int number_in_book "UK with book_id"
        bigint narrator_id FK "nullable"
        string title
        text intro "nullable"
        text text
        string source "nullable"
        boolean is_active
        int sort_order
    }

    HADITH_TERMS {
        bigint id PK
        bigint hadith_id FK
        string term
        text explanation
    }

    HADITH_AIDS {
        bigint id PK
        bigint hadith_id FK
        string title
        text content
        int sort_order
    }

    USER_HADITH_PROGRESS {
        bigint id PK
        bigint user_id FK
        bigint hadith_id FK "UK with user_id"
        string status
        tinyint srs_level
        tinyint best_score
        int attempts_count
        timestamp next_review_at "nullable"
        timestamp memorized_at "nullable"
    }

    MEMORIZATION_ATTEMPTS {
        bigint id PK
        uuid client_attempt_uuid "UK with user_id"
        bigint user_id FK
        bigint hadith_id FK
        string type "memorization | review"
        string status
        tinyint deterministic_score
        tinyint ai_score "nullable"
        tinyint final_score
        string verdict "nullable"
    }

    MEMORIZATION_STACK_ITEMS {
        bigint id PK
        bigint user_id FK
        bigint hadith_id FK "UK with user_id"
        string source "user | evaluation | ai"
        text reason "nullable"
        timestamp pushed_at
        timestamp resolved_at "nullable"
    }

    PROGRESS_AUDITS {
        bigint id PK
        bigint admin_id FK "-> users"
        bigint user_id FK "-> users"
        bigint hadith_id FK
        json changes
        text reason "nullable"
    }

    QUESTION_TEMPLATES {
        bigint id PK
        string type "written | voice"
        text prompt_template
        boolean is_active
    }

    EXAMS {
        bigint id PK
        bigint user_id FK "UK with book_id"
        bigint book_id FK
        int question_count "number of items"
        string status "in_progress | completed"
        timestamp started_at "nullable"
        timestamp completed_at "nullable"
        tinyint score "nullable"
    }

    EXAM_QUESTIONS {
        bigint id PK
        bigint exam_id FK
        bigint question_template_id FK "nullable"
        string type "written | voice"
        text question_text "names no hadith"
        int sort_order
    }

    EXAM_ANSWERS {
        bigint id PK
        bigint exam_question_id FK "UK with hadith_id"
        bigint hadith_id FK
        text correct_answer "nullable"
        text answer_text "nullable"
        tinyint score
        boolean is_correct
        json evaluation_report "nullable"
        timestamp answered_at "nullable"
        int sort_order
    }

    REVIEW_SESSIONS {
        bigint id PK
        bigint user_id FK
        string status "in_progress | completed"
        timestamp started_at
        timestamp completed_at "nullable"
        tinyint score "nullable"
    }

    REVIEW_SESSION_ITEMS {
        bigint id PK
        bigint review_session_id FK "UK with hadith_id"
        bigint hadith_id FK
        bigint memorization_attempt_id FK "nullable"
        int sort_order
    }

    HADITH_IMPORTS {
        bigint id PK
        bigint admin_id FK "-> users"
        string original_filename
        string status
        int total_rows
        int imported_rows
        int failed_rows
    }
```

Not shown: `media` (polymorphic, attaches to `books`/`hadiths`), `personal_access_tokens` (polymorphic, attaches to `users`), and Laravel's own `sessions`/`cache`/`jobs`/`password_reset_tokens`.
