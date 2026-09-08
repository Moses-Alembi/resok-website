-- One real course, so the catalogue has something in it before any clinical material exists.
--
-- Deliberately a course about the academy itself rather than invented respiratory content.
-- Writing plausible-looking clinical lessons and publishing them under a medical society's
-- name would be the wrong kind of placeholder: someone would eventually read them as ReSoK's
-- position on something. This course only describes how the platform works, which is true,
-- useful to a first learner, and safe to leave published.
--
-- It also exercises every part of the engine - two modules, text and link lessons, a real
-- duration - so the catalogue, the contents page and the player all have something to show.
--
-- Safe to run more than once: matched on the slug.

INSERT INTO academy_courses
  (slug, title, summary, description, category, level, estimated_minutes,
   members_only, certificate_enabled, pass_mark, status, published_at)
VALUES (
  'using-the-resok-virtual-academy',
  'Using the ReSoK Virtual Academy',
  'How courses, progress and certificates work here - the shortest thing to read before you start your first real course.',
  'A short orientation to the ReSoK Virtual Academy. It covers how a course is put together, what counts as finishing one, and what a ReSoK certificate does and does not say. Take it once and the rest of the catalogue will make sense.',
  'Getting started',
  'introductory',
  0,
  0, 1, 70,
  'published', NOW()
)
ON DUPLICATE KEY UPDATE
  title = VALUES(title), summary = VALUES(summary), description = VALUES(description),
  category = VALUES(category), status = VALUES(status);

-- Modules ---------------------------------------------------------------------------------
INSERT INTO academy_modules (course_id, title, summary, position)
SELECT id, 'How a course works', 'What you are looking at, and what finishing means.', 1
FROM academy_courses WHERE slug = 'using-the-resok-virtual-academy'
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_modules) m
                  WHERE m.course_id = academy_courses.id AND m.position = 1);

INSERT INTO academy_modules (course_id, title, summary, position)
SELECT id, 'Certificates and CPD', 'What a certificate here says, and what it does not.', 2
FROM academy_courses WHERE slug = 'using-the-resok-virtual-academy'
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_modules) m
                  WHERE m.course_id = academy_courses.id AND m.position = 2);

-- Lessons ---------------------------------------------------------------------------------
INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
SELECT m.id, 'Courses, modules and lessons', 'text',
'A course here is made of modules, and each module is made of lessons. The contents page you saw before enrolling lists every lesson and how long it takes, so you can judge the commitment before you start rather than after.\n\nLessons come in a few forms. Some are written, like this one. Others are a recorded session, a slide deck, or a link to something worth reading elsewhere. The form is chosen to suit the material, not to fill a template.\n\nYou can leave at any point. The academy remembers which lesson you were on and how long you have spent, so coming back tomorrow puts you where you stopped.',
6, 1
FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
WHERE c.slug = 'using-the-resok-virtual-academy' AND m.position = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_lessons) l
                  WHERE l.module_id = m.id AND l.position = 1);

INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
SELECT m.id, 'What counts as finishing', 'text',
'Progress is measured in lessons completed, not time spent. A course is finished when every lesson in it is marked done - not when you have watched enough of it, and not when you say so.\n\nThat has one consequence worth knowing in advance. If an author adds a lesson to a course you have already finished, your progress goes back down and the course stops counting as complete until you have taken the new lesson. This is on purpose: there is now material you have not seen, and a certificate that ignored it would be saying something untrue.\n\nNothing you have already done is lost when that happens. The lessons you finished stay finished.',
5, 2
FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
WHERE c.slug = 'using-the-resok-virtual-academy' AND m.position = 1
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_lessons) l
                  WHERE l.module_id = m.id AND l.position = 2);

INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
SELECT m.id, 'What a ReSoK certificate says', 'text',
'Finishing a course here earns a ReSoK certificate carrying your name, the course title, and a verification code. The code is the part that matters: anyone can check a certificate is genuine without telephoning the office, and a forged one fails that check.\n\nA certificate records that you completed this course to the standard the course set. It is a statement about the course, not a licence, a qualification, or a claim about your competence in practice.',
4, 1
FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
WHERE c.slug = 'using-the-resok-virtual-academy' AND m.position = 2
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_lessons) l
                  WHERE l.module_id = m.id AND l.position = 1);

INSERT INTO academy_lessons (module_id, title, kind, body, duration_minutes, position)
SELECT m.id, 'The academy is not the CPD system', 'text',
'These are two separate things and it is worth being clear about which is which.\n\nCPD points are awarded by the Kenya Medical Practitioners and Dentists Council for accredited activities - ReSoK conferences, CMEs and webinars - and are claimed with a KMPDC token after you attend one. That happens in the members'' portal, not here.\n\nThe academy issues its own certificates for its own courses. Finishing a course here does not add CPD points to your KMPDC record, and no course here should be described as though it does. Where a course is ever accredited, it will say so on its own page and the points will come through the CPD system as they do for everything else.',
5, 2
FROM academy_modules m JOIN academy_courses c ON c.id = m.course_id
WHERE c.slug = 'using-the-resok-virtual-academy' AND m.position = 2
  AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM academy_lessons) l
                  WHERE l.module_id = m.id AND l.position = 2);

-- The catalogue shows the summed length, so it has to be summed after the lessons exist.
UPDATE academy_courses c
SET estimated_minutes = (
  SELECT COALESCE(SUM(l.duration_minutes), 0)
  FROM academy_lessons l JOIN academy_modules m ON m.id = l.module_id
  WHERE m.course_id = c.id)
WHERE c.slug = 'using-the-resok-virtual-academy';
