# Manual shared-hosting deployment

BanglaVerseWiki targets PHP 8.1+ and MySQL/MariaDB on Apache shared hosting. Production deployment is manual because this repository's GitHub connection cannot install Actions workflows or repository secrets.

## Before upload

1. Export a complete MySQL backup and download the current `config/local.php` from the host.
2. Confirm PHP has PDO MySQL, mbstring, fileinfo, JSON, DOM, and cURL enabled.
3. Confirm the canonical site uses HTTPS.
4. Prepare a bcrypt hash for the first administrator password. Never put the plaintext password in this repository.
5. Decide whether the deployment is a normal upgrade or the requested one-time fresh-content launch.

## One-time fresh-content launch

Set this only when legacy encyclopedia content is intentionally being discarded:

```php
'fresh_content_reset' => '1',
```

On the first upgraded request, the app clears article/content, draft, media, moderation, search-event, remote-import, and IndexNow run data while preserving users, roles, settings, and synonyms. It then records `fresh_content_reset_v1`; leaving the configuration value at `1` cannot trigger that version of the reset again.

Alternatively, after backing up the database, run `database/fresh-reset.sql` once in phpMyAdmin. Do not combine both methods unnecessarily. For a content-preserving upgrade, leave the option unset or set it to `0`.

## Upload and configure

1. Upload the repository contents to the web root, normally `/htdocs`, without uploading `.git` or a development `config/local.php`.
2. Copy `config/local.example.php` to `config/local.php` on the host.
3. Configure the database, exact canonical HTTPS `site_url`, ImgBB key, IndexNow key, and administrator bootstrap hash.
4. Keep `admin_bootstrap_email` set to `rrc@bsdc.info.bd` for the initial administrator. The app stores the bcrypt hash and never requires the plaintext password in source.
5. Ensure `config/local.php` is not publicly downloadable. The supplied `.htaccess` blocks it, but host behavior should still be tested.
6. Open the site once. The bootstrap applies idempotent schema version 7 migrations and, if deliberately enabled, the one-time content reset.

If runtime `CREATE`/`ALTER` privileges are unavailable, import `database/schema.sql` for a new database. Existing databases should be upgraded by the runtime migrations rather than importing the full schema over their tables.

## Smoke test

- Open `/health` and verify that database access and required extensions report correctly.
- Sign in as the administrator and open `/admin`.
- Verify **System health**, **SEO automation**, **Content imports**, and **Media provenance**.
- Create and preview a draft, upload a small JPEG/PNG/WebP image, and confirm the returned URL is on `https://i.ibb.co/`.
- Import a license-compatible test article and review its source attribution before publishing.
- Open a published article and verify canonical, Open Graph, and attribution output.
- Validate `/sitemap.xml`, `/sitemap-images.xml`, `/feed.xml`, `/robots.txt`, and the IndexNow key URL.
- Use **Run next request** in SEO automation, then load a public page and inspect the IndexNow run history.

## IndexNow scheduler behavior

The scheduler is pure PHP and is registered on ordinary requests. It atomically leases a due task in MySQL, releases the user's response, and submits a bounded batch afterward. The default interval is 3,000 seconds (50 minutes). No cron job, queue worker, or daemon is needed. On a zero-traffic site, a due run waits until the next request; this is the unavoidable tradeoff of cron-free shared hosting.

## Security and operations

- Never commit or share `config/local.php`, database credentials, ImgBB keys/delete URLs, FTP credentials, or administrator password material.
- Remote article/image fetching is HTTPS-only and protected against private/reserved network access; keep cURL enabled so DNS-pinned requests can be used.
- Keep imports restricted to trusted users. Every import requires explicit reuse-rights confirmation and is rate limited.
- Rotate keys if they were ever included in an archive or chat message.
- Review `activity_log`, remote import errors, media provenance, and IndexNow history regularly from the admin dashboard.

## Rollback

1. Put the site in the host's maintenance mode if available.
2. Restore the previous web files and `config/local.php`.
3. Restore the pre-deployment database backup if the new schema or fresh reset ran.
4. Clear browser/service-worker caches after restoring; this release uses cache namespace `bvwiki-v5`.

A file-only rollback is not sufficient after an intentional fresh-content reset; the database backup is required to recover deleted legacy content.
