-- ReSoK Virtual Academy.
--
-- A course platform in the Alison mould: a public catalogue, courses built from modules and
-- lessons, an assessment with a pass mark, and a certificate on completion. Deliberately
-- separate from the CPD system - a course here earns a ReSoK certificate, not KMPDC points.
-- Nothing in this file touches cpd_events, cpd_tokens or anything else CPD owns.
--
-- Learners are rows in the existing users table rather than a second account system, so
-- login, password reset, two-factor and rate limiting all come for free and a member does
-- not register twice. academy_learners holds only what the academy itself needs.
--
-- Run once in phpMyAdmin, or apply it from Threat Assessment. Safe to run more than once.

-- ---------------------------------------------------------------------------------------
-- Catalogue
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academy_courses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(90) NOT NULL,
  title VARCHAR(200) NOT NULL,
  summary VARCHAR(500) NULL,
  description TEXT NULL,
  category VARCHAR(80) NULL,
  level ENUM('introductory','intermediate','advanced') NOT NULL DEFAULT 'introductory',

  -- Shown in the catalogue so a learner can judge the commitment before enrolling. Summed
  -- from the lessons rather than typed by hand, so it cannot drift from reality.
  estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  hero_image VARCHAR(255) NULL,
  -- Open by default. A course can be restricted to active members where the material is a
  -- membership benefit rather than public education.
  members_only TINYINT(1) NOT NULL DEFAULT 0,
  certificate_enabled TINYINT(1) NOT NULL DEFAULT 1,
  -- Percentage needed on the final assessment. Per course, because a short awareness course
  -- and a clinical one should not have to share a bar.
  pass_mark TINYINT UNSIGNED NOT NULL DEFAULT 80,

  status ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY academy_courses_slug (slug),
  KEY academy_courses_listing (status, category),
  KEY academy_courses_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_modules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  summary VARCHAR(400) NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY academy_modules_course (course_id, position),
  CONSTRAINT academy_modules_course_fk FOREIGN KEY (course_id)
    REFERENCES academy_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_lessons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,

  -- The existing material is YouTube embeds, slide decks and recordings, so those are the
  -- kinds that exist. 'text' covers written lessons authored in the admin panel.
  kind ENUM('video','text','slides','document','link') NOT NULL DEFAULT 'video',
  -- A YouTube id, a path under assets, or a URL, depending on kind.
  source VARCHAR(500) NULL,
  body TEXT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY academy_lessons_module (module_id, position),
  CONSTRAINT academy_lessons_module_fk FOREIGN KEY (module_id)
    REFERENCES academy_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Learners and progress
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academy_learners (
  user_id INT UNSIGNED NOT NULL,
  display_name VARCHAR(160) NULL,
  -- What appears on the certificate. Held separately because the name someone wants printed
  -- is not always the name on their membership record.
  certificate_name VARCHAR(160) NULL,
  country VARCHAR(80) NULL,
  profession VARCHAR(120) NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT academy_learners_user_fk FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_enrolments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NOT NULL,
  enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Recomputed from lesson progress rather than incremented, so it cannot drift out of step
  -- with what the learner has actually finished.
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_lesson_id INT UNSIGNED NULL,
  last_active_at DATETIME NULL,
  completed_at DATETIME NULL,

  PRIMARY KEY (id),
  -- Enrolling twice is the same enrolment, not a second one.
  UNIQUE KEY academy_enrolments_unique (user_id, course_id),
  KEY academy_enrolments_course (course_id, completed_at),
  CONSTRAINT academy_enrolments_user_fk FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT academy_enrolments_course_fk FOREIGN KEY (course_id)
    REFERENCES academy_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_lesson_progress (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrolment_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  seconds_spent MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY academy_progress_unique (enrolment_id, lesson_id),
  KEY academy_progress_lesson (lesson_id),
  CONSTRAINT academy_progress_enrolment_fk FOREIGN KEY (enrolment_id)
    REFERENCES academy_enrolments(id) ON DELETE CASCADE,
  -- A deleted lesson must take its progress rows with it. Without this the rows survive,
  -- and progress - which counts completed rows against the lessons that still exist - reads
  -- a learner who had finished the deleted lesson as having finished the whole course.
  CONSTRAINT academy_progress_lesson_fk FOREIGN KEY (lesson_id)
    REFERENCES academy_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Assessment
--
-- One assessment per module for a knowledge check, or one per course for the final. Which
-- it is depends on module_id: a row with module_id NULL is the course's final assessment.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academy_assessments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_id INT UNSIGNED NOT NULL,
  module_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  -- NULL means the course pass mark applies.
  pass_mark TINYINT UNSIGNED NULL,
  -- Zero means unlimited. Alison allows repeated attempts; a clinical course may not want to.
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- How many questions to draw from the bank. Zero means all of them, in order.
  question_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY academy_assessments_course (course_id, module_id),
  CONSTRAINT academy_assessments_course_fk FOREIGN KEY (course_id)
    REFERENCES academy_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_questions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id INT UNSIGNED NOT NULL,
  prompt TEXT NOT NULL,
  kind ENUM('single','multiple','truefalse') NOT NULL DEFAULT 'single',
  -- Shown after the attempt, so a wrong answer teaches something rather than only scoring.
  explanation TEXT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY academy_questions_assessment (assessment_id, position),
  CONSTRAINT academy_questions_assessment_fk FOREIGN KEY (assessment_id)
    REFERENCES academy_assessments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_options (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id INT UNSIGNED NOT NULL,
  label VARCHAR(500) NOT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY academy_options_question (question_id, position),
  CONSTRAINT academy_options_question_fk FOREIGN KEY (question_id)
    REFERENCES academy_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academy_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrolment_id INT UNSIGNED NOT NULL,
  assessment_id INT UNSIGNED NOT NULL,
  score_percent TINYINT UNSIGNED NULL,
  passed TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  submitted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY academy_attempts_enrolment (enrolment_id, assessment_id),
  CONSTRAINT academy_attempts_enrolment_fk FOREIGN KEY (enrolment_id)
    REFERENCES academy_enrolments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was chosen, kept so a disputed result can be looked at rather than argued about.
CREATE TABLE IF NOT EXISTS academy_answers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id INT UNSIGNED NOT NULL,
  question_id INT UNSIGNED NOT NULL,
  option_id INT UNSIGNED NULL,
  was_correct TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY academy_answers_attempt (attempt_id, question_id),
  CONSTRAINT academy_answers_attempt_fk FOREIGN KEY (attempt_id)
    REFERENCES academy_attempts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------
-- Certificates
--
-- The verification code is the point: an employer can check a certificate is genuine without
-- telephoning the office, and a forged one fails that check.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS academy_certificates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrolment_id INT UNSIGNED NOT NULL,
  code VARCHAR(24) NOT NULL,
  -- The name and title as they were when issued. A course renamed next year must not change
  -- what an already-issued certificate says.
  learner_name VARCHAR(160) NOT NULL,
  course_title VARCHAR(200) NOT NULL,
  score_percent TINYINT UNSIGNED NULL,
  issued_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY academy_certificates_code (code),
  UNIQUE KEY academy_certificates_enrolment (enrolment_id),
  CONSTRAINT academy_certificates_enrolment_fk FOREIGN KEY (enrolment_id)
    REFERENCES academy_enrolments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
