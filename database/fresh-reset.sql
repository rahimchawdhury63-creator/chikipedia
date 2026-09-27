-- BanglaVerseWiki content reset
-- Run once in phpMyAdmin AFTER importing schema.sql when a completely fresh
-- encyclopedia is required. This intentionally removes every old article,
-- revision, draft, category, media record, discussion, report, and analytics
-- event. User accounts, administrator roles, settings, and synonyms remain.

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE article_attributions;
TRUNCATE TABLE article_links;
TRUNCATE TABLE article_protections;
TRUNCATE TABLE protection_log;
TRUNCATE TABLE page_redirects;
TRUNCATE TABLE article_categories;
TRUNCATE TABLE article_likes;
TRUNCATE TABLE discussions;
TRUNCATE TABLE drafts;
TRUNCATE TABLE watchlist;
TRUNCATE TABLE reports;
TRUNCATE TABLE revisions;
TRUNCATE TABLE search_documents;
TRUNCATE TABLE search_queries;
TRUNCATE TABLE remote_imports;
TRUNCATE TABLE media_sources;
TRUNCATE TABLE images;
TRUNCATE TABLE activity_log;
TRUNCATE TABLE indexing_submissions;
TRUNCATE TABLE bot_runs;
TRUNCATE TABLE bot_jobs;
TRUNCATE TABLE articles;
TRUNCATE TABLE categories;
SET FOREIGN_KEY_CHECKS = 1;

UPDATE scheduled_tasks
SET next_run_at = UTC_TIMESTAMP(), locked_at = NULL, last_started_at = NULL,
    last_finished_at = NULL, status = 'idle', last_message = 'Fresh encyclopedia reset completed.', run_count = 0
WHERE task_name IN ('indexnow_full_refresh', 'bot_article_queue');
