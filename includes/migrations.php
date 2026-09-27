<?php
declare(strict_types=1);

/**
 * Lightweight, idempotent migrations for hosts without Composer or shell access.
 * The version check keeps normal requests to one inexpensive query.
 */
function run_migrations(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        is_public TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $version = (int) ($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn() ?: 0);
    if ($version >= 8) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(30) NOT NULL DEFAULT 'editor',
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        bio VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(190) NOT NULL UNIQUE,
        content MEDIUMTEXT NOT NULL,
        excerpt TEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'published',
        author_id BIGINT UNSIGNED NULL,
        featured_image VARCHAR(1000) NULL,
        seo_title VARCHAR(255) NULL,
        seo_description VARCHAR(320) NULL,
        views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        likes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        edit_count INT UNSIGNED NOT NULL DEFAULT 0,
        score DECIMAL(14,4) NOT NULL DEFAULT 0,
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        created_by_bot_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        published_at DATETIME NULL,
        INDEX idx_articles_status_updated (status, updated_at),
        INDEX idx_articles_score (score),
        FULLTEXT KEY ft_articles_search (title, content)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS revisions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        content MEDIUMTEXT NOT NULL,
        title VARCHAR(255) NULL,
        edit_summary VARCHAR(255) NULL,
        is_minor TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_revision_article (article_id, created_at),
        INDEX idx_revision_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS drafts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        article_id BIGINT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        content MEDIUMTEXT NOT NULL,
        edit_summary VARCHAR(255) NULL,
        seo_title VARCHAR(255) NULL,
        seo_description VARCHAR(320) NULL,
        remote_import_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_draft_user (user_id, updated_at),
        INDEX idx_draft_article (article_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(150) NOT NULL UNIQUE,
        description TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_categories (
        article_id BIGINT UNSIGNED NOT NULL,
        category_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (article_id, category_id),
        INDEX idx_category_article (category_id, article_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        imgbb_id VARCHAR(190) NULL,
        alt_text VARCHAR(255) NOT NULL DEFAULT '',
        file_path VARCHAR(1000) NOT NULL,
        delete_url VARCHAR(1000) NULL,
        mime_type VARCHAR(100) NULL,
        width INT UNSIGNED NULL,
        height INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_image_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_likes (
        article_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (article_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS watchlist (
        article_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (article_id, user_id),
        INDEX idx_watch_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS discussions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        parent_id BIGINT UNSIGNED NULL,
        body TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'visible',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_discussion_article (article_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        article_id BIGINT UNSIGNED NULL,
        reason VARCHAR(100) NOT NULL,
        details TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NULL,
        entity_id BIGINT UNSIGNED NULL,
        metadata TEXT NULL,
        ip_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_created (created_at),
        INDEX idx_activity_entity (entity_type, entity_id),
        INDEX idx_activity_user_action_time (user_id, action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_documents (
        article_id BIGINT UNSIGNED PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        normalized_title VARCHAR(255) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        excerpt TEXT NULL,
        language VARCHAR(12) NOT NULL DEFAULT 'bn',
        quality_score DECIMAL(8,3) NOT NULL DEFAULT 0,
        popularity_score DECIMAL(12,3) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FULLTEXT KEY ft_search_document (title, normalized_title, body),
        INDEX idx_search_title (normalized_title(190), updated_at),
        INDEX idx_search_quality (quality_score, popularity_score)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_queries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        query_text VARCHAR(190) NOT NULL,
        normalized_query VARCHAR(190) NOT NULL,
        query_hash CHAR(64) NOT NULL,
        result_count INT UNSIGNED NOT NULL DEFAULT 0,
        clicked_article_id BIGINT UNSIGNED NULL,
        session_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_search_query_time (created_at),
        INDEX idx_search_query_normalized (normalized_query, created_at),
        INDEX idx_search_query_zero (result_count, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS search_synonyms (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        term VARCHAR(120) NOT NULL,
        synonym VARCHAR(120) NOT NULL,
        weight DECIMAL(4,2) NOT NULL DEFAULT 0.75,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_search_synonym (term, synonym),
        INDEX idx_synonym_lookup (term, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scheduled_tasks (
        task_name VARCHAR(100) PRIMARY KEY,
        interval_seconds INT UNSIGNED NOT NULL,
        next_run_at DATETIME NOT NULL,
        locked_at DATETIME NULL,
        last_started_at DATETIME NULL,
        last_finished_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'idle',
        last_message VARCHAR(500) NULL,
        run_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_scheduled_due (next_run_at, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS indexing_submissions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(40) NOT NULL DEFAULT 'indexnow',
        url_count INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL,
        http_status SMALLINT UNSIGNED NULL,
        response_excerpt VARCHAR(500) NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        INDEX idx_indexing_status_time (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS remote_imports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NULL,
        article_id BIGINT UNSIGNED NULL,
        source_url VARCHAR(1000) NOT NULL,
        source_host VARCHAR(190) NOT NULL,
        source_title VARCHAR(255) NULL,
        source_license VARCHAR(100) NULL,
        source_image_url VARCHAR(1000) NULL,
        imported_image_url VARCHAR(1000) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'started',
        error_message VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_import_user_time (user_id, created_at),
        INDEX idx_import_status_time (status, created_at),
        INDEX idx_import_host (source_host)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS media_sources (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT UNSIGNED NOT NULL,
        source_url VARCHAR(1000) NOT NULL,
        source_page_url VARCHAR(1000) NULL,
        source_host VARCHAR(190) NOT NULL,
        attribution VARCHAR(500) NULL,
        license_name VARCHAR(100) NULL,
        imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_media_source_image (image_id),
        INDEX idx_media_source_host (source_host, imported_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_attributions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id BIGINT UNSIGNED NOT NULL,
        source_url VARCHAR(1000) NOT NULL,
        source_title VARCHAR(255) NULL,
        license_name VARCHAR(100) NULL,
        attribution_text VARCHAR(1000) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attribution_article (article_id),
        INDEX idx_attribution_source (source_url(190))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_protections (
        article_id BIGINT UNSIGNED PRIMARY KEY,
        protection_level VARCHAR(30) NOT NULL DEFAULT 'administrator',
        reason VARCHAR(500) NOT NULL,
        protected_by BIGINT UNSIGNED NOT NULL,
        expires_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_protection_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS protection_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        article_id BIGINT UNSIGNED NOT NULL,
        administrator_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(30) NOT NULL,
        reason VARCHAR(500) NULL,
        expires_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_protection_log_article (article_id, created_at),
        INDEX idx_protection_log_admin (administrator_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(500) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_by BIGINT UNSIGNED NULL,
        daily_limit INT UNSIGNED NOT NULL DEFAULT 25,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_bot_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id BIGINT UNSIGNED NOT NULL,
        requested_by BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        payload_json MEDIUMTEXT NOT NULL,
        publication_mode VARCHAR(20) NOT NULL DEFAULT 'pending',
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        article_id BIGINT UNSIGNED NULL,
        error_message VARCHAR(500) NULL,
        scheduled_for DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        INDEX idx_bot_job_due (status, scheduled_for),
        INDEX idx_bot_job_bot_time (bot_id, created_at),
        INDEX idx_bot_job_requester (requested_by, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bot_id BIGINT UNSIGNED NOT NULL,
        job_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL,
        message VARCHAR(500) NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        INDEX idx_bot_run_bot_time (bot_id, started_at),
        INDEX idx_bot_run_status (status, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS page_redirects (
        source_slug VARCHAR(190) PRIMARY KEY,
        target_article_id BIGINT UNSIGNED NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_redirect_target (target_article_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS article_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_article_id BIGINT UNSIGNED NOT NULL,
        target_article_id BIGINT UNSIGNED NULL,
        target_title VARCHAR(255) NOT NULL,
        target_key CHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_article_link (source_article_id, target_key),
        INDEX idx_link_target (target_article_id, source_article_id),
        INDEX idx_link_missing (target_article_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Upgrade the original BanglaVerseWiki tables without destroying existing data.
    $columns = [
        'users' => [
            'role' => "VARCHAR(30) NOT NULL DEFAULT 'editor'",
            'status' => "VARCHAR(30) NOT NULL DEFAULT 'active'",
            'bio' => 'VARCHAR(500) NULL',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'last_login_at' => 'DATETIME NULL',
        ],
        'articles' => [
            'excerpt' => 'TEXT NULL',
            'status' => "VARCHAR(30) NOT NULL DEFAULT 'published'",
            'author_id' => 'BIGINT UNSIGNED NULL',
            'featured_image' => 'VARCHAR(1000) NULL',
            'seo_title' => 'VARCHAR(255) NULL',
            'seo_description' => 'VARCHAR(320) NULL',
            'views' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'likes' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'edit_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'score' => 'DECIMAL(14,4) NOT NULL DEFAULT 0',
            'is_featured' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_by_bot_id' => 'BIGINT UNSIGNED NULL',
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'published_at' => 'DATETIME NULL',
        ],
        'drafts' => [
            'seo_title' => 'VARCHAR(255) NULL',
            'seo_description' => 'VARCHAR(320) NULL',
            'remote_import_id' => 'BIGINT UNSIGNED NULL',
        ],
        'revisions' => [
            'title' => 'VARCHAR(255) NULL',
            'edit_summary' => 'VARCHAR(255) NULL',
            'is_minor' => 'TINYINT(1) NOT NULL DEFAULT 0',
        ],
        'images' => [
            'imgbb_id' => 'VARCHAR(190) NULL',
            'delete_url' => 'VARCHAR(1000) NULL',
            'mime_type' => 'VARCHAR(100) NULL',
            'width' => 'INT UNSIGNED NULL',
            'height' => 'INT UNSIGNED NULL',
        ],
    ];

    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $definition) {
            if (!database_column_exists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
    if (!database_index_exists($pdo, 'activity_log', 'idx_activity_user_action_time')) {
        $pdo->exec('ALTER TABLE activity_log ADD INDEX idx_activity_user_action_time (user_id, action, created_at)');
    }
    if (!database_index_exists($pdo, 'search_documents', 'idx_search_title')) {
        $pdo->exec('ALTER TABLE search_documents ADD INDEX idx_search_title (normalized_title(190), updated_at)');
    }

    // Preserve attribution and publication dates when upgrading the original schema.
    $pdo->exec("UPDATE articles a SET a.author_id = (SELECT r.user_id FROM revisions r WHERE r.article_id = a.id ORDER BY r.id ASC LIMIT 1) WHERE a.author_id IS NULL");
    $pdo->exec("UPDATE articles SET published_at = created_at WHERE status = 'published' AND published_at IS NULL");
    $pdo->exec("INSERT INTO search_documents (article_id, title, normalized_title, body, excerpt, language, quality_score, popularity_score, updated_at)
        SELECT id, title, LOWER(title), content, excerpt, 'bn',
            LEAST(100, LEAST(30, CHAR_LENGTH(content) / 500) + LEAST(50, (CHAR_LENGTH(content) - CHAR_LENGTH(REPLACE(content, '<ref', ''))) / 4 * 10)),
            (LOG10(views + 10) * 8 + likes * 4 + edit_count), updated_at
        FROM articles
        ON DUPLICATE KEY UPDATE title = VALUES(title), normalized_title = VALUES(normalized_title), body = VALUES(body), excerpt = VALUES(excerpt), quality_score = VALUES(quality_score), popularity_score = VALUES(popularity_score), updated_at = VALUES(updated_at)");

    $defaults = [
        'site_tagline' => 'A free, community-built encyclopedia for everyone.',
        'homepage_notice' => '',
        'allow_registration' => '1',
        'require_review' => '0',
        'default_meta_description' => 'BanglaVerseWiki is a free, community-built encyclopedia for Bengali knowledge, culture, history and ideas.',
        'remote_import_enabled' => '1',
        'indexnow_interval_minutes' => '50',
        'bot_scheduler_enabled' => '1',
        'bot_default_mode' => 'pending',
        'schema_version' => '8',
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, is_public) VALUES (?, ?, ?)');
    $privateSettings = ['bot_scheduler_enabled', 'bot_default_mode', 'schema_version'];
    foreach ($defaults as $key => $value) {
        $insert->execute([$key, $value, in_array($key, $privateSettings, true) ? 0 : 1]);
    }
    $pdo->prepare("UPDATE settings SET setting_value = '8' WHERE setting_key = 'schema_version'")->execute();
    $pdo->exec("INSERT IGNORE INTO bots (id, name, slug, description, status, daily_limit) VALUES (1, 'BanglaVerseBot', 'banglaversebot', 'Creates administrator-approved, source-backed structured encyclopedia drafts and articles.', 'active', 25)");
    $pdo->exec("INSERT IGNORE INTO scheduled_tasks (task_name, interval_seconds, next_run_at, status) VALUES ('indexnow_full_refresh', 3000, UTC_TIMESTAMP(), 'idle'), ('bot_article_queue', 300, UTC_TIMESTAMP(), 'idle')");
}

function reset_content_if_requested(PDO $pdo): void
{
    if (FRESH_CONTENT_RESET !== '1' || setting($pdo, 'fresh_content_reset_v1', '') !== '') {
        return;
    }
    $tables = [
        'article_attributions', 'article_links', 'article_protections', 'protection_log', 'page_redirects',
        'article_categories', 'article_likes', 'discussions', 'drafts', 'watchlist', 'reports', 'revisions',
        'search_documents', 'search_queries', 'remote_imports', 'media_sources', 'images', 'activity_log',
        'indexing_submissions', 'bot_runs', 'bot_jobs', 'articles', 'categories',
    ];
    try {
        $pdo->beginTransaction();
        foreach ($tables as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
        $pdo->exec("UPDATE scheduled_tasks SET next_run_at = UTC_TIMESTAMP(), locked_at = NULL, last_started_at = NULL, last_finished_at = NULL, status = 'idle', last_message = 'Fresh encyclopedia initialized.', run_count = 0 WHERE task_name IN ('indexnow_full_refresh', 'bot_article_queue')");
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value, is_public) VALUES ('fresh_content_reset_v1', UTC_TIMESTAMP(), 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Fresh content reset failed: ' . $exception->getMessage());
        throw $exception;
    }
}

function database_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function database_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (bool) $stmt->fetchColumn();
}

function bootstrap_administrator(PDO $pdo): void
{
    if (ADMIN_BOOTSTRAP_EMAIL === '') {
        return;
    }
    $passwordHash = ADMIN_BOOTSTRAP_PASSWORD_HASH;
    if ($passwordHash === '' && ADMIN_BOOTSTRAP_PASSWORD !== '') {
        $passwordHash = password_hash(ADMIN_BOOTSTRAP_PASSWORD, PASSWORD_DEFAULT);
    }
    if ($passwordHash === '' || !preg_match('/^\$2[aby]\$\d{2}\$/', substr($passwordHash, 0, 7))) {
        error_log('Administrator bootstrap skipped: configure a valid bcrypt password hash.');
        return;
    }
    $fingerprint = hash('sha256', mb_strtolower(ADMIN_BOOTSTRAP_EMAIL) . '|' . $passwordHash);
    if (setting($pdo, 'admin_bootstrap_complete', '') === $fingerprint) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([ADMIN_BOOTSTRAP_EMAIL]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'administrator', status = 'active' WHERE id = ?")->execute([$passwordHash, (int) $id]);
        save_setting($pdo, 'admin_bootstrap_complete', $fingerprint, false);
        return;
    }
    $base = preg_replace('/[^a-z0-9_]+/i', '', strstr(ADMIN_BOOTSTRAP_EMAIL, '@', true) ?: 'administrator') ?: 'administrator';
    $username = $base;
    $suffix = 1;
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    do {
        $check->execute([$username]);
        if (!$check->fetchColumn()) {
            break;
        }
        $username = $base . ++$suffix;
    } while ($suffix < 100);
    $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'administrator', 'active')");
    $insert->execute([$username, ADMIN_BOOTSTRAP_EMAIL, $passwordHash]);
    save_setting($pdo, 'admin_bootstrap_complete', $fingerprint, false);
}
