-- ReSoK Blog CMS + Content Analytics schema.
--
-- Applies on top of schema.sql; it reuses `users` for authentication and authorship and
-- adds nothing that duplicates it.
--
-- Two design decisions run through the whole file and are worth stating once:
--
-- 1. Raw engagement is stored per SESSION, not per page load. A "read" is a session that
--    showed real engagement (dwell time or scroll depth), so a refresh cannot inflate it.
--    Aggregates are rolled up nightly into blog_daily_stats, so dashboards never scan the
--    raw table - that is what keeps the numbers fast once there are millions of rows.
--
-- 2. No raw IP addresses are stored anywhere. Geo is resolved at write time and only the
--    country/region is kept; the visitor is identified by a salted hash of
--    (ip + user-agent + daily salt), which is enough to de-duplicate a reader within a day
--    without being able to re-identify them later, and rotates itself every day. That is
--    what makes "unique readers" possible under Kenya's Data Protection Act without
--    holding personal data you would then have to justify, secure, and delete.

-- ---------------------------------------------------------------------------------------
-- Roles. schema.sql has ENUM('member','admin'); the spec needs finer editorial roles.
-- ---------------------------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN role ENUM('member','author','editor','content_manager','analytics_manager','admin')
  NOT NULL DEFAULT 'member';

-- ---------------------------------------------------------------------------------------
-- Authorship
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_authors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,                    -- NULL for guest authors with no login
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  job_title VARCHAR(160) NULL,
  organization VARCHAR(200) NULL,
  credentials VARCHAR(200) NULL,
  biography TEXT NULL,
  photo_path VARCHAR(255) NULL,
  social_links JSON NULL,                       -- {"linkedin":"...","x":"..."}
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY blog_authors_slug (slug),
  KEY blog_authors_user (user_id),
  CONSTRAINT blog_authors_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Taxonomy
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description VARCHAR(400) NULL,
  colour CHAR(7) NULL,                          -- optional chip colour, e.g. #00932e
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY blog_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blog_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY blog_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Articles
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_articles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(200) NOT NULL,                   -- drives /blog/<slug>
  title VARCHAR(300) NOT NULL,
  subtitle VARCHAR(400) NULL,
  excerpt TEXT NULL,
  body_html MEDIUMTEXT NULL,                    -- sanitised on write, never on read
  featured_image VARCHAR(255) NULL,
  featured_image_alt VARCHAR(300) NULL,

  category_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,

  status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,    -- the one lead story on /blog
  comments_enabled TINYINT(1) NOT NULL DEFAULT 1,

  published_at DATETIME NULL,                   -- also the scheduled time while 'scheduled'
  updated_content_at DATETIME NULL,             -- "Last updated" shown to readers
  reading_minutes SMALLINT UNSIGNED NULL,       -- computed on save from body word count

  -- SEO (§19). Kept on the article so an editor sets them in one place.
  seo_title VARCHAR(300) NULL,
  seo_description VARCHAR(400) NULL,
  canonical_url VARCHAR(400) NULL,
  focus_keywords VARCHAR(300) NULL,
  og_title VARCHAR(300) NULL,
  og_description VARCHAR(400) NULL,
  social_image VARCHAR(255) NULL,

  -- Denormalised counters. The truth lives in the events tables; these exist so a listing
  -- page can show read counts without aggregating on every request. Rebuilt by the cron.
  read_count INT UNSIGNED NOT NULL DEFAULT 0,
  unique_reader_count INT UNSIGNED NOT NULL DEFAULT 0,
  reaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  share_count INT UNSIGNED NOT NULL DEFAULT 0,
  comment_count INT UNSIGNED NOT NULL DEFAULT 0,

  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY blog_articles_slug (slug),
  KEY blog_articles_status_pub (status, published_at),   -- the public listing query
  KEY blog_articles_category (category_id, status, published_at),
  KEY blog_articles_author (author_id, status),
  KEY blog_articles_featured (is_featured, status),
  FULLTEXT KEY blog_articles_search (title, subtitle, excerpt, body_html),
  CONSTRAINT blog_articles_category_fk FOREIGN KEY (category_id) REFERENCES blog_categories (id) ON DELETE SET NULL,
  CONSTRAINT blog_articles_author_fk FOREIGN KEY (author_id) REFERENCES blog_authors (id) ON DELETE SET NULL,
  CONSTRAINT blog_articles_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blog_article_tags (
  article_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (article_id, tag_id),
  KEY blog_article_tags_tag (tag_id),
  CONSTRAINT blog_article_tags_article_fk FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
  CONSTRAINT blog_article_tags_tag_fk FOREIGN KEY (tag_id) REFERENCES blog_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Reading sessions - the raw engagement record (§5, §32)
--
-- One row per visitor per article per session. `qualified_read` is what the public read
-- count counts, and it is only set once the session clears the engagement thresholds, so
-- refreshing a page can never raise it. visitor_hash rotates daily (salted), which
-- de-duplicates a reader within a day without storing anything that identifies them.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_read_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id INT UNSIGNED NOT NULL,
  session_key CHAR(36) NOT NULL,                -- opaque id issued to the browser
  visitor_hash CHAR(64) NOT NULL,               -- sha256(ip + ua + daily salt)
  user_id INT UNSIGNED NULL,                    -- set only for a signed-in reader (§9)

  qualified_read TINYINT(1) NOT NULL DEFAULT 0,
  dwell_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  scroll_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed TINYINT(1) NOT NULL DEFAULT 0,      -- reached the end of the article
  is_returning TINYINT(1) NOT NULL DEFAULT 0,

  -- Aggregate-safe context. No IP, no precise location.
  country_code CHAR(2) NULL,
  region VARCHAR(120) NULL,
  city VARCHAR(120) NULL,
  device_type ENUM('desktop','mobile','tablet','bot','other') NOT NULL DEFAULT 'other',
  browser VARCHAR(60) NULL,
  os VARCHAR(60) NULL,

  -- Acquisition (§10)
  source VARCHAR(120) NULL,                     -- google | linkedin | whatsapp | direct ...
  referrer_host VARCHAR(200) NULL,
  utm_source VARCHAR(120) NULL,
  utm_medium VARCHAR(120) NULL,
  utm_campaign VARCHAR(160) NULL,
  utm_content VARCHAR(160) NULL,
  utm_term VARCHAR(160) NULL,

  is_excluded TINYINT(1) NOT NULL DEFAULT 0,    -- admin traffic and detected bots
  started_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY blog_sessions_unique (article_id, session_key),
  KEY blog_sessions_article_time (article_id, started_at),
  KEY blog_sessions_qualified (article_id, qualified_read, started_at),
  KEY blog_sessions_visitor (visitor_hash, started_at),
  KEY blog_sessions_user (user_id, started_at),
  KEY blog_sessions_country (country_code, started_at),
  KEY blog_sessions_source (source, started_at),
  KEY blog_sessions_campaign (utm_campaign, started_at),
  CONSTRAINT blog_sessions_article_fk FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
  CONSTRAINT blog_sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Interaction events (§13) - shares, copy-link, downloads, video plays, outbound clicks.
-- Deliberately one thin table rather than one per type: the list of things worth tracking
-- will grow, and a new event type should not need a migration.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id INT UNSIGNED NULL,
  session_key CHAR(36) NULL,
  user_id INT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,              -- share | copy_link | download | video_play ...
  event_label VARCHAR(200) NULL,                -- e.g. which network, which file
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY blog_events_article_type (article_id, event_type, created_at),
  KEY blog_events_type_time (event_type, created_at),
  CONSTRAINT blog_events_article_fk FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blog_reactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id INT UNSIGNED NOT NULL,
  visitor_hash CHAR(64) NOT NULL,
  user_id INT UNSIGNED NULL,
  reaction VARCHAR(30) NOT NULL DEFAULT 'like',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY blog_reactions_once (article_id, visitor_hash, reaction),
  CONSTRAINT blog_reactions_article_fk FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blog_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  author_name VARCHAR(160) NOT NULL,
  author_email VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending',
  moderated_by INT UNSIGNED NULL,
  moderated_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY blog_comments_article_status (article_id, status, created_at),
  CONSTRAINT blog_comments_article_fk FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Nightly aggregates (§36). Dashboards read only from here, so their cost stays flat no
-- matter how large blog_read_sessions grows. One row per article per day per dimension
-- value, which keeps country/source/device breakdowns answerable without a scan.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_daily_stats (
  stat_date DATE NOT NULL,
  article_id INT UNSIGNED NOT NULL,
  dimension ENUM('total','country','source','device','campaign') NOT NULL DEFAULT 'total',
  dimension_value VARCHAR(160) NOT NULL DEFAULT '',
  reads INT UNSIGNED NOT NULL DEFAULT 0,
  unique_readers INT UNSIGNED NOT NULL DEFAULT 0,
  returning_readers INT UNSIGNED NOT NULL DEFAULT 0,
  completions INT UNSIGNED NOT NULL DEFAULT 0,
  total_dwell_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  total_scroll_percent INT UNSIGNED NOT NULL DEFAULT 0,
  reactions INT UNSIGNED NOT NULL DEFAULT 0,
  shares INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (stat_date, article_id, dimension, dimension_value),
  KEY blog_daily_article (article_id, stat_date),
  KEY blog_daily_dimension (dimension, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------------------
-- Newsletter capture (§15), so a signup can be attributed to the article that caused it.
-- ---------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blog_newsletter_subscribers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(200) NOT NULL,
  source_article_id INT UNSIGNED NULL,
  confirmed TINYINT(1) NOT NULL DEFAULT 0,
  confirm_token CHAR(48) NULL,
  unsubscribed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY blog_newsletter_email (email),
  KEY blog_newsletter_article (source_article_id),
  CONSTRAINT blog_newsletter_article_fk FOREIGN KEY (source_article_id) REFERENCES blog_articles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the categories from the spec. Editable from the admin panel afterwards.
INSERT IGNORE INTO blog_categories (name, slug, sort_order) VALUES
  ('Lung Health','lung-health',1), ('Tuberculosis','tuberculosis',2), ('Asthma','asthma',3),
  ('COPD','copd',4), ('Research','research',5), ('Clinical Practice','clinical-practice',6),
  ('Public Health','public-health',7), ('Policy','policy',8), ('Events','events',9),
  ('News & Updates','news-updates',10), ('Publications','publications',11), ('Other','other',12);
