# BanglaVerseWiki 4

A modern, dependency-free Wikipedia-style publishing platform for free PHP/MySQL shared hosting. It uses plain PHP 8.1+, MySQL/MariaDB, CSS, and JavaScript—no Composer, Node build, paid search service, or paid editor is required.

## What is included

- MediaWiki-style source editor with live preview, toolbar, keyboard shortcuts, browser recovery, and server-side private autosave
- Licensed encyclopedia importer for Wikipedia/MediaWiki and other public HTTPS encyclopedias, with source attribution, license records, SSRF protection, automatic bounded image discovery, and mandatory ImgBB transfer
- Rich-paste image detection: pasting licensed wiki HTML keeps the text and offers to fetch up to three detected images, re-host them on ImgBB, and store their provenance automatically
- Safe wiki parser: headings, bold/italic, internal/external links, lists, citations and automatic references, images, categories, tables, infoboxes, notes, warnings, quotes, and hatnotes
- Revision history, line-by-line diff, rollback, edit summaries, minor edits, conflict detection, and transparent recent changes
- Drafts, review workflow, featured pages, discussions, likes, watchlists, profiles, categories, search, random pages, and trending ranking
- Responsive, accessible Wikipedia-inspired interface optimized from 280 px mobile screens through ultrawide desktops, plus dark mode and print styles
- Administrator control center for article moderation, roles, user blocking, site settings, media, reports, search intelligence, health checks, and activity auditing
- Smart hybrid search with exact/prefix matching, MySQL FULLTEXT relevance, popularity/freshness/quality weighting, live suggestions, synonym expansion, zero-result opportunities, and private aggregate analytics
- Technical SEO: per-page canonical/meta/Open Graph/Twitter tags, Article/Profile/Collection/Breadcrumb/SearchAction/SearchResultsPage JSON-LD, dynamic split sitemaps, RSS, semantic HTML, contextual internal links, clean URLs, robots rules, and fast dependency-free assets
- Pure-PHP IndexNow automation: publication events submit immediately, while a database-leased traffic scheduler refreshes discovery endpoints and a bounded public-URL batch every 50 minutes without cron, workers, or daemons
- Image SEO: responsive recreated brand artwork, article social cards, Media RSS, and an image sitemap for hosted article media
- Installable PWA with a conservative public-page cache and offline fallback
- Security: prepared queries, output escaping, CSRF protection, safe wiki rendering, secure sessions, login throttling, upload validation, role checks, edit throttling, CSP/security headers, and secrets outside Git

> No website can honestly guarantee “100% instant indexing.” Search engines decide when and whether to index a URL. BanglaVerseWiki provides the strongest standards-based free setup: discoverable internal links, fresh XML sitemaps, RSS, structured data, canonical URLs, and instant IndexNow submission to participating crawlers.

## Requirements

- PHP 8.1 or newer with PDO MySQL, mbstring, fileinfo, JSON, and preferably cURL/DOM
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` (the supplied `.htaccess` is ready for typical shared hosting)
- HTTPS in production

## Deployment on free shared hosting

1. Upload the repository contents to the public web root.
2. Copy `config/local.example.php` to `config/local.php`.
3. Fill in database credentials, the canonical HTTPS `site_url`, and the provided ImgBB API key. `config/local.php` is ignored by Git.
4. Create an empty MySQL database. The app applies idempotent migrations automatically on first request. If the host blocks schema changes from PHP, import `database/schema.sql` once through phpMyAdmin.
5. For the requested one-time clean launch, set `fresh_content_reset` to `'1'` before the first upgraded request. It clears legacy encyclopedia content and media records while preserving accounts, roles, settings, and synonyms, then writes the `fresh_content_reset_v1` completion marker so later requests cannot repeat the deletion. For an explicit phpMyAdmin operation, import `database/fresh-reset.sql` instead. Do not enable this option on a database whose content must be retained.
6. Create the first administrator using one of these secure options:
   - Preferred: set `admin_bootstrap_email` and a bcrypt `admin_bootstrap_password_hash` in `config/local.php`. The deployment workflow uses this method, so no plaintext administrator password is placed on the server; or
   - Open `/setup` immediately after deployment. It is available only until the first administrator exists. Use `rrc@bsdc.info.bd` and the private password supplied for that account.
7. Log in, open **Administration → Site settings**, review the publishing workflow, remote imports, and IndexNow status, then change the initial administrator password policy/credentials as appropriate.
8. Submit `/sitemap.xml` and `/sitemap-images.xml` in Google Search Console and Bing Webmaster Tools. Configure `indexnow_key` to enable immediate and scheduled IndexNow submissions.
9. After HTTPS is confirmed, uncomment the HTTPS redirect block in `.htaccess`.

Environment variables can be used instead of `config/local.php`:

```text
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
SITE_URL, SITE_NAME, SITE_LANGUAGE
IMGBB_API_KEY, INDEXNOW_KEY, FRESH_CONTENT_RESET
ADMIN_BOOTSTRAP_EMAIL, ADMIN_BOOTSTRAP_PASSWORD_HASH
```

## Deployment automation template

Production deployment is intentionally manual for this repository because the connected GitHub App cannot install workflows or secrets. `deployment/github-actions-deploy.yml` remains an optional, ready-to-install template for a repository administrator with Actions permission. It validates PHP, builds ignored `config/local.php` from encrypted secrets, deploys over explicit TLS FTP, and verifies the production URL without writing credentials to Git history.

## ImgBB upload contract

Every new local upload and licensed imported image goes server-to-server to ImgBB through `includes/ImageService.php`; there is no local-upload fallback. The service validates MIME, signature, dimensions, and size, deduplicates remote sources, records provenance, and sends exactly:

```text
POST https://api.imgbb.com/1/upload?key=${IMGBB_API_KEY}
Content-Type: application/x-www-form-urlencoded

image=<base64 encoded file>&name=<safe name>
```

The API key is in the URL, and the base64 image is in the request body. Only ImgBB's returned HTTPS URL is stored in MySQL and inserted into wiki source; remote source/license/credit records are stored separately for auditing. The key is never exposed to browser JavaScript. Remote downloads are HTTPS-only, DNS/IP checked against private networks, size/time bounded, and revalidated after redirects.

## Wiki source quick reference

```text
A lead paragraph with '''bold''', ''italic'', and [[Internal article|a label]].
An external source: [https://example.org Source name]

== History ==
A sourced statement.<ref>Author, title, publisher, date, URL</ref>

* Bullet item
# Numbered item

{{Infobox
| title = Example
| image = https://i.ibb.co/example/image.jpg
| caption = A useful description
| location = Bangladesh
}}

[[File:https://i.ibb.co/example/image.jpg|alt=Accessible description|Caption|right|420px]]
{{Note|Neutral context for readers.}}
{{Quote|Quoted words|Attribution}}
{{Main|Related article}}

{|
|+ Table title
|-
! Column one !! Column two
|-
| Value one || Value two
|}

[[Category:History]]
```

Raw user HTML is never trusted. Existing articles created by the old TinyMCE version are passed through a strict legacy HTML sanitizer while new pages use wiki source.

## Licensed article imports

In the editor, choose **Import article**, enter a public HTTPS Wikipedia/MediaWiki or encyclopedia article URL, select the applicable reuse license, and confirm that reuse is allowed. The server fetches and converts the source into editable wiki text; it never publishes automatically. MediaWiki imports use the official API and attempt file-specific license/artist/credit lookup. Generic pages use a constrained semantic extractor. The import URL, canonical source, license, status, image transfer outcome, and errors remain visible in **Administration → Content imports**.

When formatted encyclopedia material containing images is pasted, the editor asks for reuse-rights confirmation before any transfer. Confirmed images are fetched by the server and re-hosted on ImgBB. Images without confirmed reuse rights must not be imported.

## Database architecture

`database/schema.sql` is the complete, importable version 7 production schema. Its 21 purposeful normalized tables cover users, articles, immutable revisions, drafts, categories, media/provenance, source attribution, imports, reactions, watchlists, discussions, reports, settings, activity auditing, search analytics, scheduled tasks, and IndexNow submission history. Composite, covering, and FULLTEXT indexes follow real query paths. The schema deliberately avoids hundreds of empty or duplicated tables: on constrained shared hosting, normalized tables and correct indexes are safer and substantially faster than artificial table-count inflation.

## SEO and crawler automation

- `/sitemap.xml` automatically lists every published article and category. Above 45,000 articles it becomes a sitemap index and exposes `/sitemap-1.xml`, `/sitemap-2.xml`, and so on.
- `/sitemap-images.xml` lists hosted featured and inline wiki images with article context and splits automatically above 45,000 image-bearing articles; scalable article/social imagery is generated from real content rather than shipping duplicate filler files.
- `/feed.xml` publishes the newest 50 articles as RSS 2.0 with Media RSS images.
- Publishing and rollback events notify IndexNow immediately when `INDEXNOW_KEY` is configured.
- A request-triggered, database-leased scheduler submits the discovery endpoints plus a deduplicated public-URL batch every configured 50 minutes, respecting IndexNow's 10,000-URL request limit. Shared hosting needs no cron or daemon; if the site receives no traffic, a due run waits safely until the next request.
- Draft, edit, admin, diff, login, and discussion surfaces are `noindex` and excluded in `robots.txt`.
- Article dates, descriptions, author, publisher, image, breadcrumbs, language, and free-access status are represented in Schema.org JSON-LD.

Update the absolute Sitemap URL in `robots.txt` if the production domain differs from the default.

## Administration and workflow

Roles are **editor**, **moderator**, and **administrator**. Administrators can promote another user to administrator from **Administration → Users & roles**. If **Require moderator review** is enabled, new editor articles enter `pending`; moderators/administrators publish them from the article queue. Existing public-page edits remain revisioned and immediately visible, matching a collaborative wiki workflow.

Never commit `config/local.php`, database passwords, ImgBB keys, bootstrap passwords, or the ImgBB delete URLs. Rotate any credential that was previously included in an uploaded source archive.
