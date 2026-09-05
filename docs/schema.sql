-- =============================================================================
--  أثر (Athar) — مخطط قاعدة البيانات
--  Athar — database schema (MySQL 8 / InnoDB, utf8mb4)
-- =============================================================================
--
--  هذا الملف هو الصياغة النصية (DDL) للمخطط المرسوم في docs/erd.md، وهو مقسوم
--  إلى الجزأين نفسيهما:
--
--    الجزء الأول — Core (10 جداول): الهوية (1) + المحتوى (3) + الحفظ (3)
--      + السبر (3). هذه هي الجداول التي يجري عليها تدفّق الحفظ والسبر فعلاً،
--      وهي المخطط المعروض في المناقشة. الجزء الأول **قابل للتنفيذ وحده**:
--      لا يشير أيٌّ من جداوله إلى جدول خارجه.
--
--    الجزء الثاني — بقية النطاق (7 جداول): إثراء المحتوى، وجلسة المراجعة
--      اليومية، وبنك الأسئلة، وسجلَّا الإدارة.
--
--  الاستثناء الوحيد الذي يعبر بين الجزأين هو exam_questions.question_template_id
--  (اختياري)، فمفتاحه الأجنبي مؤجَّل إلى ALTER TABLE في نهاية الجزء الثاني،
--  حتى يبقى الجزء الأول مكتفياً بنفسه.
--
--  جداول الإطار (sessions, cache, cache_locks, jobs, job_batches, failed_jobs,
--  password_reset_tokens, migrations) وجدولا media (Spatie) و
--  personal_access_tokens (Sanctum) ينشئها `php artisan migrate` وهي خارج
--  المخطط. المجموع في قاعدة البيانات 27 جدولاً، منها 17 جدول نطاق.
--
--  ملاحظة على المصدر: هذا الملف مستخرَج من المخطط الفعلي بعد تطبيق كل
--  الهجرات (migrations)، فهو مطابق لما ينتجه `php artisan migrate`.
-- =============================================================================

SET NAMES utf8mb4;

-- بالترتيب العكسي للتبعية، حتى يمكن إعادة تنفيذ الملف على قاعدة موجودة.
-- (question_templates بعد exam_questions لأن الأخير يشير إليه.)
DROP TABLE IF EXISTS `hadith_imports`;
DROP TABLE IF EXISTS `progress_audits`;
DROP TABLE IF EXISTS `review_session_items`;
DROP TABLE IF EXISTS `review_sessions`;
DROP TABLE IF EXISTS `hadith_aids`;
DROP TABLE IF EXISTS `hadith_terms`;
DROP TABLE IF EXISTS `exam_answers`;
DROP TABLE IF EXISTS `exam_questions`;
DROP TABLE IF EXISTS `question_templates`;
DROP TABLE IF EXISTS `exams`;
DROP TABLE IF EXISTS `memorization_stack_items`;
DROP TABLE IF EXISTS `memorization_attempts`;
DROP TABLE IF EXISTS `user_hadith_progress`;
DROP TABLE IF EXISTS `hadiths`;
DROP TABLE IF EXISTS `narrators`;
DROP TABLE IF EXISTS `books`;
DROP TABLE IF EXISTS `users`;


-- #############################################################################
--
--   الجزء الأول — Core (10 جداول)
--
-- #############################################################################


-- =============================================================================
--  1) الهوية — Identity  (جدول واحد)
-- =============================================================================

-- المستخدمون: متعلّمون ومشرفون في جدول واحد، يفصل بينهم عمود role.
CREATE TABLE `users` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(255)    NOT NULL,
  `email`             VARCHAR(255)    NOT NULL,
  `email_verified_at` TIMESTAMP       NULL,
  `password`          VARCHAR(255)    NOT NULL,
  `birth_date`        DATE            NULL,
  -- user | admin
  `role`              VARCHAR(255)    NOT NULL DEFAULT 'user',
  -- تعطيل الحساب دون حذفه: الوسيط `active` يمنع الوصول
  `is_active`         TINYINT(1)      NOT NULL DEFAULT 1,
  `remember_token`    VARCHAR(100)    NULL,
  `created_at`        TIMESTAMP       NULL,
  `updated_at`        TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_index` (`role`),
  KEY `users_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  2) المحتوى — Catalogue  (3 جداول)
-- =============================================================================

-- الكتب: الأربعون النووية، رياض الصالحين...
CREATE TABLE `books` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(255)    NOT NULL,
  `description` TEXT            NULL,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP       NULL,
  `updated_at`  TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `books_is_active_index` (`is_active`),
  KEY `books_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الرواة (الصحابة) وتراجمهم.
CREATE TABLE `narrators` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255)    NOT NULL,
  `biography`  TEXT            NULL,
  `created_at` TIMESTAMP       NULL,
  `updated_at` TIMESTAMP       NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الأحاديث. الجدول المحوري الذي تشير إليه بقية الجداول.
--
--   number_in_book : رقم الحديث داخل كتابه، ومتفرّد داخله. المعرّف id عالمي
--                    على مستوى الفهرس كله، فالحديث رقم 100 في الجدول قد يكون
--                    الرابع في الأربعين النووية؛ هذا العمود هو الرقم المنشور
--                    الذي يُعرَض في التطبيق ويشير إليه بند السبر.
--   intro / text   : مفصولان عمداً. التسميع يُقارَن بالمتن (text) وحده، فلا
--                    يُحاسَب المتعلّم على سياق الرواية والإسناد (intro).
CREATE TABLE `hadiths` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `book_id`        BIGINT UNSIGNED NOT NULL,
  `number_in_book` INT UNSIGNED    NULL,
  `narrator_id`    BIGINT UNSIGNED NULL,
  `title`          VARCHAR(255)    NOT NULL,
  `intro`          TEXT            NULL,
  `text`           TEXT            NOT NULL,
  `source`         VARCHAR(255)    NULL,
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `sort_order`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP       NULL,
  `updated_at`     TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hadiths_book_id_number_in_book_unique` (`book_id`, `number_in_book`),
  KEY `hadiths_narrator_id_foreign` (`narrator_id`),
  KEY `hadiths_is_active_index` (`is_active`),
  KEY `hadiths_sort_order_index` (`sort_order`),
  CONSTRAINT `hadiths_book_id_foreign`
    FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `hadiths_narrator_id_foreign`
    FOREIGN KEY (`narrator_id`) REFERENCES `narrators` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  3) الحفظ والتقدم — Memorization  (3 جداول)
-- =============================================================================

-- حالة كل حديث لكل مستخدم: صفٌّ واحد لكل (مستخدم، حديث).
--
--   next_review_at : هو ما يحدد ما يظهر في جلسة اليوم
--   srs_level      : 0..4، يضبط طول الفاصل التالي (1، 3، 7، 14، 30 يوماً)
CREATE TABLE `user_hadith_progress` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `hadith_id`       BIGINT UNSIGNED NOT NULL,
  -- not_started | memorizing | reviewing | memorized
  `status`          VARCHAR(255)    NOT NULL DEFAULT 'not_started',
  `srs_level`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `best_score`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `attempts_count`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `last_attempt_at` TIMESTAMP       NULL,
  `next_review_at`  TIMESTAMP       NULL,
  `memorized_at`    TIMESTAMP       NULL,
  `created_at`      TIMESTAMP       NULL,
  `updated_at`      TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_hadith_progress_user_id_hadith_id_unique` (`user_id`, `hadith_id`),
  KEY `user_hadith_progress_hadith_id_foreign` (`hadith_id`),
  KEY `user_hadith_progress_status_index` (`status`),
  KEY `user_hadith_progress_next_review_at_index` (`next_review_at`),
  CONSTRAINT `user_hadith_progress_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_hadith_progress_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل كل محاولة تسميع أو مراجعة، لا يُحذف منه شيء.
--
--   deterministic_score : درجة المقارنة الحتمية
--   ai_score            : درجة Gemini، تشخيصية فقط
--   final_score         : الدرجة المعتمدة — لا تتغير بتغيّر نموذج لغوي
--   client_attempt_uuid : قيد التفرّد معه يجعل إعادة الإرسال من التطبيق عند
--                         انقطاع الشبكة لا تُنشئ سجلاً مكرراً (Idempotency)
CREATE TABLE `memorization_attempts` (
  `id`                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_attempt_uuid`       CHAR(36)        NOT NULL,
  `user_id`                   BIGINT UNSIGNED NOT NULL,
  `hadith_id`                 BIGINT UNSIGNED NOT NULL,
  -- memorization | review
  `type`                      VARCHAR(255)    NOT NULL,
  `status`                    VARCHAR(255)    NOT NULL,
  `recognized_text`           TEXT            NOT NULL,
  `normalized_recognized_text` TEXT           NOT NULL,
  `normalized_reference_text` TEXT            NOT NULL,
  `deterministic_score`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ai_score`                  TINYINT UNSIGNED NULL,
  `final_score`               TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `verdict`                   VARCHAR(255)    NULL,
  `comparison_report`         JSON            NULL,
  `ai_report`                 JSON            NULL,
  `gemini_model`              VARCHAR(255)    NULL,
  `gemini_interaction_id`     VARCHAR(255)    NULL,
  `processing_ms`             INT UNSIGNED    NULL,
  `failure_code`              VARCHAR(255)    NULL,
  `failure_message`           TEXT            NULL,
  `created_at`                TIMESTAMP       NULL,
  `updated_at`                TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `memorization_attempts_user_id_client_attempt_uuid_unique` (`user_id`, `client_attempt_uuid`),
  KEY `memorization_attempts_user_id_hadith_id_index` (`user_id`, `hadith_id`),
  KEY `memorization_attempts_hadith_id_foreign` (`hadith_id`),
  CONSTRAINT `memorization_attempts_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memorization_attempts_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- قائمة "يحتاج مراجعة" (stack): من دفع الحديث إليها ولماذا.
-- قيد التفرّد على (user_id, hadith_id) يجعل إعادة الدفع تُحدِّث pushed_at
-- (تُعيد العنصر إلى القمة) بدل أن تُنشئ صفاً مكرراً.
CREATE TABLE `memorization_stack_items` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `hadith_id`     BIGINT UNSIGNED NOT NULL,
  -- user | evaluation | ai
  `source`        VARCHAR(255)    NOT NULL DEFAULT 'user',
  `reason`        TEXT            NULL,
  `trigger_score` TINYINT UNSIGNED NULL,
  `pushed_at`     TIMESTAMP       NOT NULL,
  `resolved_at`   TIMESTAMP       NULL,
  `created_at`    TIMESTAMP       NULL,
  `updated_at`    TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `memorization_stack_items_user_id_hadith_id_unique` (`user_id`, `hadith_id`),
  KEY `memorization_stack_items_hadith_id_foreign` (`hadith_id`),
  KEY `memorization_stack_items_source_index` (`source`),
  KEY `memorization_stack_items_pushed_at_index` (`pushed_at`),
  KEY `memorization_stack_items_resolved_at_index` (`resolved_at`),
  CONSTRAINT `memorization_stack_items_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memorization_stack_items_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  4) الاختبارات / السبر — Exams  (3 جداول)
-- =============================================================================
--
--  التطبيع هنا هو محور تصميم السبر:
--
--    exam_questions : نص السؤال فقط، مخزَّن مرة واحدة، ولا يذكر حديثاً بعينه
--                     («من هو راوي هذا الحديث؟»، «أكمل نص هذا الحديث»)
--    exam_answers   : بند السبر — السؤال مطروحاً عن حديث محدد. يحمل إجابة ذلك
--                     الحديث المرجعية، وإجابة المتعلّم، ونتيجتها
--
--  فالعلاقة بينهما واحد-لمتعدد: السؤال واحد، والإجابات تتعدد بتعدّد الأحاديث.
--  «طول السبر» = عدد بنود exam_answers، لا عدد الأسئلة.
-- =============================================================================

-- سبر الكتاب. قيد التفرّد على (user_id, book_id): لكل كتاب سبر واحد لكل
-- متعلّم، وإعادة فتحه تُصفّره ولا تُنشئ سبراً ثانياً على الكتاب نفسه.
CREATE TABLE `exams` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `book_id`        BIGINT UNSIGNED NOT NULL,
  -- عدد البنود (exam_answers)، وهو طول السبر
  `question_count` INT UNSIGNED    NOT NULL DEFAULT 0,
  -- in_progress | completed
  `status`         VARCHAR(255)    NOT NULL DEFAULT 'in_progress',
  `started_at`     TIMESTAMP       NULL,
  `completed_at`   TIMESTAMP       NULL,
  -- تبقى NULL حتى إنهاء السبر: النتيجة لا تُفشى قبل إتمام كل البنود
  `score`          TINYINT UNSIGNED NULL,
  `created_at`     TIMESTAMP       NULL,
  `updated_at`     TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exams_user_id_book_id_unique` (`user_id`, `book_id`),
  KEY `exams_book_id_foreign` (`book_id`),
  KEY `exams_status_index` (`status`),
  CONSTRAINT `exams_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exams_book_id_foreign`
    FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سؤال السبر: نصّه فقط. لا hadith_id هنا ولا correct_answer — كلاهما ينتمي
-- إلى البند، لأنه ما يتغيّر من حديث إلى آخر.
--
-- question_template_id اختياري (القالب الذي وُلِّد منه النص)، ومفتاحه الأجنبي
-- مؤجَّل إلى نهاية الجزء الثاني حتى يبقى هذا الجزء مكتفياً بنفسه.
CREATE TABLE `exam_questions` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`              BIGINT UNSIGNED NOT NULL,
  `question_template_id` BIGINT UNSIGNED NULL,
  -- written | voice
  `type`                 VARCHAR(255)    NOT NULL,
  `question_text`        TEXT            NOT NULL,
  `sort_order`           INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`           TIMESTAMP       NULL,
  `updated_at`           TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `exam_questions_exam_id_foreign` (`exam_id`),
  KEY `exam_questions_question_template_id_foreign` (`question_template_id`),
  CONSTRAINT `exam_questions_exam_id_foreign`
    FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- بند السبر: سؤال مطروح عن حديث محدد.
--
--   correct_answer    : الإجابة المرجعية لهذا الحديث تحديداً — وهي ما يجعل
--                       صفاً واحداً لكل حديث ضرورياً
--   answer_text       : ما كتبه أو سمّعه المتعلّم؛ NULL قبل الإجابة
--   answered_at       : يفصل "بندٌ أُنشئ فارغاً" عن "بندٌ أُجيب عنه"
--   evaluation_report : تقرير التصحيح، وفيه mode: gemini أو المقارنة الحتمية
--
-- قيد التفرّد على (exam_question_id, hadith_id): السؤال قد يُطرح عن أحاديث
-- كثيرة، لكن لا يُطرح مرتين عن الحديث نفسه.
CREATE TABLE `exam_answers` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_question_id`  BIGINT UNSIGNED NOT NULL,
  `hadith_id`         BIGINT UNSIGNED NOT NULL,
  `correct_answer`    TEXT            NULL,
  `answer_text`       TEXT            NULL,
  `score`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `is_correct`        TINYINT(1)      NOT NULL DEFAULT 0,
  `evaluation_report` JSON            NULL,
  `answered_at`       TIMESTAMP       NULL,
  `sort_order`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`        TIMESTAMP       NULL,
  `updated_at`        TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_answers_exam_question_id_hadith_id_unique` (`exam_question_id`, `hadith_id`),
  KEY `exam_answers_hadith_id_foreign` (`hadith_id`),
  CONSTRAINT `exam_answers_exam_question_id_foreign`
    FOREIGN KEY (`exam_question_id`) REFERENCES `exam_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_answers_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ← نهاية الجزء الأول. قاعدة بيانات صالحة للعمل بجداولها العشرة.


-- #############################################################################
--
--   الجزء الثاني — بقية جداول النطاق (7 جداول)
--
-- #############################################################################


-- =============================================================================
--  5) إثراء المحتوى — Content enrichment  (جدولان)
-- =============================================================================

-- شرح المفردات الغريبة لكل حديث.
CREATE TABLE `hadith_terms` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hadith_id`   BIGINT UNSIGNED NOT NULL,
  `term`        VARCHAR(255)    NOT NULL,
  `explanation` TEXT            NOT NULL,
  `created_at`  TIMESTAMP       NULL,
  `updated_at`  TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `hadith_terms_hadith_id_foreign` (`hadith_id`),
  CONSTRAINT `hadith_terms_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مفاتيح ومعينات الحفظ لكل حديث.
CREATE TABLE `hadith_aids` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `hadith_id`  BIGINT UNSIGNED NOT NULL,
  `title`      VARCHAR(255)    NOT NULL,
  `content`    TEXT            NOT NULL,
  `sort_order` INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP       NULL,
  `updated_at` TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `hadith_aids_hadith_id_foreign` (`hadith_id`),
  KEY `hadith_aids_sort_order_index` (`sort_order`),
  CONSTRAINT `hadith_aids_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  6) جلسة المراجعة اليومية — Review session  (جدولان)
-- =============================================================================

-- جلسة واحدة = جِلسة مراجعة واحدة، بنتيجة عند إنهائها.
CREATE TABLE `review_sessions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  -- in_progress | completed
  `status`       VARCHAR(255)    NOT NULL DEFAULT 'in_progress',
  `started_at`   TIMESTAMP       NOT NULL,
  `completed_at` TIMESTAMP       NULL,
  `score`        TINYINT UNSIGNED NULL,
  `created_at`   TIMESTAMP       NULL,
  `updated_at`   TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `review_sessions_user_id_status_index` (`user_id`, `status`),
  CONSTRAINT `review_sessions_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- لقطة (snapshot) لأحاديث الجلسة وقت بدئها، لا استعلام حيّ: تسميع الحديث
-- يعيد جدولته، فاستعلامٌ حيّ كان سيُسقطه من الجلسة لحظة الإجابة عليه.
-- الدرجة لا تُخزَّن هنا: المحاولة تحملها، فلا نسخة ثانية منها.
CREATE TABLE `review_session_items` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `review_session_id`       BIGINT UNSIGNED NOT NULL,
  `hadith_id`               BIGINT UNSIGNED NOT NULL,
  `sort_order`              INT UNSIGNED    NOT NULL DEFAULT 0,
  `memorization_attempt_id` BIGINT UNSIGNED NULL,
  `created_at`              TIMESTAMP       NULL,
  `updated_at`              TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `review_session_items_review_session_id_hadith_id_unique` (`review_session_id`, `hadith_id`),
  KEY `review_session_items_hadith_id_foreign` (`hadith_id`),
  KEY `review_session_items_memorization_attempt_id_foreign` (`memorization_attempt_id`),
  CONSTRAINT `review_session_items_review_session_id_foreign`
    FOREIGN KEY (`review_session_id`) REFERENCES `review_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `review_session_items_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE,
  CONSTRAINT `review_session_items_memorization_attempt_id_foreign`
    FOREIGN KEY (`memorization_attempt_id`) REFERENCES `memorization_attempts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  7) بنك الأسئلة — Question bank  (جدول واحد)
-- =============================================================================

-- بنك قوالب الأسئلة، منه يُبنى السبر. النص عامّ لأنه يُشارَك بين كل الأحاديث
-- التي يُسأل عنها.
CREATE TABLE `question_templates` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- written | voice
  `type`            VARCHAR(255)    NOT NULL,
  `prompt_template` TEXT            NOT NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP       NULL,
  `updated_at`      TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `question_templates_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  8) سجلات الإدارة — Admin logs  (جدولان)
-- =============================================================================

-- تدقيق تعديلات المشرف اليدوية على تقدّم متعلّم.
-- يرتبط بجدول users مرتين: admin_id (من عدّل) و user_id (على من).
CREATE TABLE `progress_audits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `hadith_id`  BIGINT UNSIGNED NOT NULL,
  -- القيم قبل التعديل وبعده
  `changes`    JSON            NOT NULL,
  `reason`     TEXT            NULL,
  `created_at` TIMESTAMP       NULL,
  `updated_at` TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `progress_audits_admin_id_foreign` (`admin_id`),
  KEY `progress_audits_user_id_foreign` (`user_id`),
  KEY `progress_audits_hadith_id_foreign` (`hadith_id`),
  CONSTRAINT `progress_audits_admin_id_foreign`
    FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `progress_audits_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `progress_audits_hadith_id_foreign`
    FOREIGN KEY (`hadith_id`) REFERENCES `hadiths` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سجل عمليات الاستيراد الجماعي للأحاديث، وأخطاء كل صف.
CREATE TABLE `hadith_imports` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`          BIGINT UNSIGNED NOT NULL,
  `original_filename` VARCHAR(255)    NOT NULL,
  -- completed | completed_with_errors | failed
  `status`            VARCHAR(255)    NOT NULL DEFAULT 'completed',
  `total_rows`        INT UNSIGNED    NOT NULL DEFAULT 0,
  `imported_rows`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `failed_rows`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `errors`            JSON            NULL,
  `created_at`        TIMESTAMP       NULL,
  `updated_at`        TIMESTAMP       NULL,
  PRIMARY KEY (`id`),
  KEY `hadith_imports_admin_id_foreign` (`admin_id`),
  CONSTRAINT `hadith_imports_admin_id_foreign`
    FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
--  المفتاح الأجنبي الوحيد العابر بين الجزأين
-- =============================================================================

-- يُضاف هنا لا في تعريف exam_questions، حتى يبقى الجزء الأول (Core) قابلاً
-- للتنفيذ وحده دون جدول question_templates.
ALTER TABLE `exam_questions`
  ADD CONSTRAINT `exam_questions_question_template_id_foreign`
  FOREIGN KEY (`question_template_id`) REFERENCES `question_templates` (`id`) ON DELETE SET NULL;
