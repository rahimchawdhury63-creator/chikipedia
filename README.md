# BanglaVerseWiki 4

A modern, dependency-free Wikipedia-style publishing platform for free PHP/MySQL shared hosting. It uses plain PHP 8.1+, MySQL/MariaDB, CSS, and JavaScript—no Composer, Node build, paid search service, or paid editor is required.

## What is included

- MediaWiki-style source editor with live preview, toolbar, keyboard shortcuts, browser recovery, and server-side private autosave
- Safe wiki parser: headings, bold/italic, internal/external links, lists, citations and automatic references, images, categories, tables, infoboxes, notes, warnings, quotes, and hatnotes
- Revision history, line-by-line diff, rollback, edit summaries, minor edits, conflict detection, and transparent recent changes
- Drafts, review workflow, featured pages, discussions, likes, watchlists, profiles, categories, search, random pages, and trending ranking
- Responsive, accessible Wikipedia-inspired interface optimized from 280 px mobile screens through ultrawide desktops, plus dark mode and print styles
- Administrator control center for article moderation, roles, user blocking, site settings, media, reports, search intelligence, health checks, and activity auditing
- Smart hybrid search with exact/prefix matching, MySQL FULLTEXT relevance, popularity/freshness/quality weighting, live suggestions, synonym expansion, zero-result opportunities, and private aggregate analytics
- Technical SEO: per-page canonical/meta/Open Graph/Twitter tags, Article/Profile/Collection/Breadcrumb/SearchAction/SearchResultsPage JSON-LD, dynamic split sitemaps, RSS, semantic HTML, contextual internal links, clean URLs, robots rules, and fast dependency-free assets
- IndexNow notifications after publishing (when a free IndexNow key is configured)
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
5. Create the first administrator using one of these secure options:
   - Preferred: set `admin_bootstrap_email` and a bcrypt `admin_bootstrap_password_hash` in `config/local.php`. The deployment workflow uses this method, so no plaintext administrator password is placed on the server; or
   - Open `/setup` immediately after deployment. It is available only until the first administrator exists. Use `rrc@bsdc.info.bd` and the private password supplied for that account.
6. Log in, open **Administration → Site settings**, review the publishing workflow, and then change the initial administrator password policy/credentials as appropriate.
7. Submit `/sitemap.xml` in Google Search Console and Bing Webmaster Tools. Optionally create a free IndexNow key and configure `indexnow_key`.
8. After HTTPS is confirmed, uncomment the HTTPS redirect block in `.htaccess`.

Environment variables can be used instead of `config/local.php`:

```text
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
SITE_URL, SITE_NAME, SITE_LANGUAGE
IMGBB_API_KEY, INDEXNOW_KEY
ADMIN_BOOTSTRAP_EMAIL, ADMIN_BOOTSTRAP_PASSWORD_HASH
```

## Automated production deployment

`deployment/github-actions-deploy.yml` is a ready-to-install workflow template that validates every PHP file, builds the ignored `config/local.php` from encrypted GitHub Actions secrets, deploys to `/htdocs` over explicit TLS FTP, and verifies the production URL. The workflow never writes FTP, database, ImgBB, or administrator credentials to Git history. Configure the documented repository secrets before running it.

## ImgBB upload contract

Every new editor image goes server-to-server to ImgBB. There is no local-upload fallback. `pages/upload_image.php` validates the file, reads it as binary, and sends exactly:

```text
POST https://api.imgbb.com/1/upload?key=${IMGBB_API_KEY}
Content-Type: application/x-www-form-urlencoded

image=<base64 encoded file>&name=<safe name>
```

The API key is in the URL, and the base64 image is in the request body. Only ImgBB's returned HTTPS URL is stored in the MySQL `images.file_path` field and inserted into wiki source. The key is never exposed to browser JavaScript.

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

## Database architecture

`database/schema.sql` is the complete, importable production schema. Versioned runtime migrations preserve the original database while adding normalized, indexed subsystems for users, articles, immutable revisions, drafts, categories, media, reactions, watchlists, discussions, reports, settings, activity auditing, search documents, query analytics, and synonyms. Composite, covering, and FULLTEXT indexes are defined around real query paths. The schema deliberately avoids hundreds of empty or duplicated tables: on constrained shared hosting, normalized tables and correct indexes are safer and substantially faster than artificial table-count inflation.

## SEO and crawler automation

- `/sitemap.xml` automatically lists every published article and category. Above 45,000 articles it becomes a sitemap index and exposes `/sitemap-1.xml`, `/sitemap-2.xml`, and so on.
- `/feed.xml` publishes the newest 50 articles as RSS 2.0.
- Publishing and rollback events notify IndexNow when `INDEXNOW_KEY` is configured.
- Draft, edit, admin, diff, login, and discussion surfaces are `noindex` and excluded in `robots.txt`.
- Article dates, descriptions, author, publisher, image, breadcrumbs, language, and free-access status are represented in Schema.org JSON-LD.

Update the absolute Sitemap URL in `robots.txt` if the production domain differs from the default.

## Administration and workflow

Roles are **editor**, **moderator**, and **administrator**. Administrators can promote another user to administrator from **Administration → Users & roles**. If **Require moderator review** is enabled, new editor articles enter `pending`; moderators/administrators publish them from the article queue. Existing public-page edits remain revisioned and immediately visible, matching a collaborative wiki workflow.

Never commit `config/local.php`, database passwords, ImgBB keys, bootstrap passwords, or the ImgBB delete URLs. Rotate any credential that was previously included in an uploaded source archive.
