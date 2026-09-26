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
    if ($version >= 6) {
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
        INDEX idx_activity_entity (entity_type, entity_id)
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
            'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'published_at' => 'DATETIME NULL',
        ],
        'drafts' => [
            'seo_title' => 'VARCHAR(255) NULL',
            'seo_description' => 'VARCHAR(320) NULL',
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
        'schema_version' => '6',
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value, is_public) VALUES (?, ?, 1)');
    foreach ($defaults as $key => $value) {
        $insert->execute([$key, $value]);
    }
    $pdo->prepare("UPDATE settings SET setting_value = '6' WHERE setting_key = 'schema_version'")->execute();
}

function database_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
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
