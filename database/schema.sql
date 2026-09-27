-- BanglaVerseWiki 4 fresh-install schema
-- MySQL 5.7+ / MariaDB 10.3+, utf8mb4, shared-hosting friendly.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE,
 email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(30) NOT NULL DEFAULT 'editor', status VARCHAR(30) NOT NULL DEFAULT 'active', bio VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, slug VARCHAR(190) NOT NULL UNIQUE,
 content MEDIUMTEXT NOT NULL, excerpt TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'published', author_id BIGINT UNSIGNED NULL,
 featured_image VARCHAR(1000) NULL, seo_title VARCHAR(255) NULL, seo_description VARCHAR(320) NULL,
 views BIGINT UNSIGNED NOT NULL DEFAULT 0, likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 edit_count INT UNSIGNED NOT NULL DEFAULT 0, score DECIMAL(14,4) NOT NULL DEFAULT 0, is_featured TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 published_at DATETIME NULL, INDEX idx_articles_status_updated (status, updated_at), INDEX idx_articles_score (score),
 FULLTEXT KEY ft_articles_search (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL,
 content MEDIUMTEXT NOT NULL, title VARCHAR(255) NULL, edit_summary VARCHAR(255) NULL, is_minor TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_revision_article (article_id, created_at), INDEX idx_revision_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drafts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, article_id BIGINT UNSIGNED NULL,
 title VARCHAR(255) NOT NULL DEFAULT '', content MEDIUMTEXT NOT NULL, edit_summary VARCHAR(255) NULL,
 seo_title VARCHAR(255) NULL, seo_description VARCHAR(320) NULL, remote_import_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_draft_user (user_id, updated_at), INDEX idx_draft_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, slug VARCHAR(150) NOT NULL UNIQUE,
 description TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS article_categories (
 article_id BIGINT UNSIGNED NOT NULL, category_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY (article_id, category_id), INDEX idx_category_article (category_id, article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS images (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, imgbb_id VARCHAR(190) NULL,
 alt_text VARCHAR(255) NOT NULL DEFAULT '', file_path VARCHAR(1000) NOT NULL, delete_url VARCHAR(1000) NULL,
 mime_type VARCHAR(100) NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_image_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_likes (
 article_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (article_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS watchlist (
 article_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (article_id, user_id), INDEX idx_watch_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS discussions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 parent_id BIGINT UNSIGNED NULL, body TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'visible',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_discussion_article (article_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, article_id BIGINT UNSIGNED NULL,
 reason VARCHAR(100) NOT NULL, details TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_report_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT NULL, is_public TINYINT(1) NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activity_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(50) NULL, entity_id BIGINT UNSIGNED NULL, metadata TEXT NULL, ip_hash CHAR(64) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_activity_created (created_at),
 INDEX idx_activity_entity (entity_type, entity_id), INDEX idx_activity_user_action_time (user_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS search_documents (
 article_id BIGINT UNSIGNED PRIMARY KEY, title VARCHAR(255) NOT NULL, normalized_title VARCHAR(255) NOT NULL,
 body MEDIUMTEXT NOT NULL, excerpt TEXT NULL, language VARCHAR(12) NOT NULL DEFAULT 'bn',
 quality_score DECIMAL(8,3) NOT NULL DEFAULT 0, popularity_score DECIMAL(12,3) NOT NULL DEFAULT 0,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FULLTEXT KEY ft_search_document (title, normalized_title, body), INDEX idx_search_quality (quality_score, popularity_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS search_queries (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, query_text VARCHAR(190) NOT NULL, normalized_query VARCHAR(190) NOT NULL,
 query_hash CHAR(64) NOT NULL, result_count INT UNSIGNED NOT NULL DEFAULT 0, clicked_article_id BIGINT UNSIGNED NULL,
 session_hash CHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_search_query_time (created_at), INDEX idx_search_query_normalized (normalized_query, created_at), INDEX idx_search_query_zero (result_count, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS search_synonyms (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, term VARCHAR(120) NOT NULL, synonym VARCHAR(120) NOT NULL,
 weight DECIMAL(4,2) NOT NULL DEFAULT 0.75, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_search_synonym (term, synonym), INDEX idx_synonym_lookup (term, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS scheduled_tasks (
 task_name VARCHAR(100) PRIMARY KEY, interval_seconds INT UNSIGNED NOT NULL, next_run_at DATETIME NOT NULL,
 locked_at DATETIME NULL, last_started_at DATETIME NULL, last_finished_at DATETIME NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'idle', last_message VARCHAR(500) NULL, run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 INDEX idx_scheduled_due (next_run_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS indexing_submissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(40) NOT NULL DEFAULT 'indexnow',
 url_count INT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL, http_status SMALLINT UNSIGNED NULL,
 response_excerpt VARCHAR(500) NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL,
 INDEX idx_indexing_status_time (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS remote_imports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, article_id BIGINT UNSIGNED NULL,
 source_url VARCHAR(1000) NOT NULL, source_host VARCHAR(190) NOT NULL, source_title VARCHAR(255) NULL,
 source_license VARCHAR(100) NULL, source_image_url VARCHAR(1000) NULL, imported_image_url VARCHAR(1000) NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'started', error_message VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL,
 INDEX idx_import_user_time (user_id, created_at), INDEX idx_import_status_time (status, created_at), INDEX idx_import_host (source_host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media_sources (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, image_id BIGINT UNSIGNED NOT NULL, source_url VARCHAR(1000) NOT NULL,
 source_page_url VARCHAR(1000) NULL, source_host VARCHAR(190) NOT NULL, attribution VARCHAR(500) NULL,
 license_name VARCHAR(100) NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_media_source_image (image_id), INDEX idx_media_source_host (source_host, imported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS article_attributions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, article_id BIGINT UNSIGNED NOT NULL, source_url VARCHAR(1000) NOT NULL,
 source_title VARCHAR(255) NULL, license_name VARCHAR(100) NULL, attribution_text VARCHAR(1000) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_attribution_article (article_id), INDEX idx_attribution_source (source_url(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value, is_public) VALUES
 ('site_tagline', 'A free, community-built encyclopedia for everyone.', 1),
 ('homepage_notice', '', 1), ('allow_registration', '1', 1), ('require_review', '0', 1),
 ('default_meta_description', 'BanglaVerseWiki is a free, community-built encyclopedia for Bengali knowledge, culture, history and ideas.', 1),
 ('remote_import_enabled', '1', 1), ('indexnow_interval_minutes', '50', 1),
 ('schema_version', '7', 0);

INSERT IGNORE INTO scheduled_tasks (task_name, interval_seconds, next_run_at, status)
VALUES ('indexnow_full_refresh', 3000, UTC_TIMESTAMP(), 'idle');

INSERT INTO search_documents (article_id, title, normalized_title, body, excerpt, language, quality_score, popularity_score, updated_at)
SELECT id, title, LOWER(title), content, excerpt, 'bn',
 LEAST(100, LEAST(30, CHAR_LENGTH(content) / 500) + LEAST(50, (CHAR_LENGTH(content) - CHAR_LENGTH(REPLACE(content, '<ref', ''))) / 4 * 10)),
 (LOG10(views + 10) * 8 + likes * 4 + edit_count), updated_at
FROM articles
ON DUPLICATE KEY UPDATE title = VALUES(title), normalized_title = VALUES(normalized_title), body = VALUES(body), excerpt = VALUES(excerpt), quality_score = VALUES(quality_score), popularity_score = VALUES(popularity_score), updated_at = VALUES(updated_at);
