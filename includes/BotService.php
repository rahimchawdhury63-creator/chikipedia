<?php
declare(strict_types=1);

final class BotJobException extends RuntimeException {}

/**
 * Deterministic, source-backed article builder. It does not invent facts or call
 * a paid AI service: administrators provide the facts and sources, then the bot
 * turns them into consistent wiki source and records every run.
 */
final class BotService
{
    public function __construct(private PDO $pdo) {}

    public function queue(int $botId, int $administratorId, array $input): int
    {
        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, 255);
        $description = mb_substr(trim((string) ($input['short_description'] ?? '')), 0, 600);
        $mode = in_array($input['publication_mode'] ?? '', ['draft', 'pending', 'published'], true)
            ? (string) $input['publication_mode'] : 'pending';
        $scheduledFor = trim((string) ($input['scheduled_for'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2) {
            throw new BotJobException('Enter a clear article title.');
        }
        if (mb_strlen($description) < 30) {
            throw new BotJobException('Add a factual short description of at least 30 characters.');
        }
        $sources = $this->parseSources((string) ($input['sources'] ?? ''));
        if (!$sources) {
            throw new BotJobException('At least one public HTTPS reliable source is required.');
        }
        $bot = $this->pdo->prepare("SELECT id FROM bots WHERE id = ? AND status = 'active' LIMIT 1");
        $bot->execute([$botId]);
        if (!$bot->fetchColumn()) {
            throw new BotJobException('The selected bot is not active.');
        }
        $duplicate = $this->pdo->prepare('SELECT id FROM articles WHERE LOWER(title) = LOWER(?) LIMIT 1');
        $duplicate->execute([$title]);
        if ($duplicate->fetchColumn()) {
            throw new BotJobException('An article with that title already exists.');
        }
        $payload = [
            'short_description' => $description,
            'article_type' => mb_substr(trim((string) ($input['article_type'] ?? 'Topic')), 0, 80) ?: 'Topic',
            'overview' => mb_substr(trim((string) ($input['overview'] ?? '')), 0, 10000),
            'background' => mb_substr(trim((string) ($input['background'] ?? '')), 0, 10000),
            'significance' => mb_substr(trim((string) ($input['significance'] ?? '')), 0, 10000),
            'facts' => mb_substr(trim((string) ($input['facts'] ?? '')), 0, 6000),
            'sources' => $sources,
            'categories' => array_slice(array_values(array_filter(array_map('trim', preg_split('/[,\n]+/u', (string) ($input['categories'] ?? '')) ?: []))), 0, 12),
        ];
        $scheduledDate = $scheduledFor !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledFor, new DateTimeZone('UTC')) : false;
        $scheduled = $scheduledDate && $scheduledDate->getTimestamp() > time() ? $scheduledDate->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("INSERT INTO bot_jobs (bot_id, requested_by, title, payload_json, publication_mode, status, scheduled_for) VALUES (?, ?, ?, ?, ?, 'queued', ?)");
        $stmt->execute([$botId, $administratorId, $title, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $mode, $scheduled]);
        $jobId = (int) $this->pdo->lastInsertId();
        log_activity($this->pdo, 'bot.job_queued', 'bot_job', $jobId, ['bot_id' => $botId, 'mode' => $mode, 'scheduled_for' => $scheduled]);
        return $jobId;
    }

    public function runNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE bot_runs SET status = 'failed', message = 'Worker lease expired before completion.', finished_at = UTC_TIMESTAMP() WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
            $this->pdo->exec("UPDATE bot_jobs SET status = CASE WHEN attempts < 3 THEN 'queued' ELSE 'failed' END, scheduled_for = UTC_TIMESTAMP(), error_message = 'Recovered after an interrupted worker.', completed_at = CASE WHEN attempts >= 3 THEN UTC_TIMESTAMP() ELSE NULL END WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
            $stmt = $this->pdo->query("SELECT j.*, b.name AS bot_name, b.daily_limit
                FROM bot_jobs j JOIN bots b ON b.id = j.bot_id
                WHERE j.status = 'queued' AND j.scheduled_for <= UTC_TIMESTAMP() AND b.status = 'active'
                ORDER BY j.scheduled_for, j.id LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch();
            if (!$job) {
                $this->pdo->commit();
                return null;
            }
            $daily = $this->pdo->prepare("SELECT COUNT(*) FROM bot_jobs WHERE bot_id = ? AND status = 'completed' AND completed_at >= UTC_DATE()");
            $daily->execute([$job['bot_id']]);
            if ((int) $daily->fetchColumn() >= (int) $job['daily_limit']) {
                $this->pdo->prepare("UPDATE bot_jobs SET scheduled_for = DATE_ADD(UTC_DATE(), INTERVAL 1 DAY), error_message = 'Daily safety limit reached; deferred.' WHERE id = ?")->execute([$job['id']]);
                $this->pdo->commit();
                return ['status' => 'deferred', 'job_id' => (int) $job['id'], 'message' => 'Daily safety limit reached.'];
            }
            $this->pdo->prepare("UPDATE bot_jobs SET status = 'running', started_at = UTC_TIMESTAMP(), attempts = attempts + 1, error_message = NULL WHERE id = ?")->execute([$job['id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $started = gmdate('Y-m-d H:i:s');
        $run = $this->pdo->prepare("INSERT INTO bot_runs (bot_id, job_id, status, message, started_at) VALUES (?, ?, 'running', ?, ?)");
        $run->execute([$job['bot_id'], $job['id'], 'Building structured wiki source.', $started]);
        $runId = (int) $this->pdo->lastInsertId();
        try {
            $articleId = $this->publish($job);
            $message = 'Created article #' . $articleId . ' as ' . $job['publication_mode'] . '.';
            $this->pdo->prepare("UPDATE bot_jobs SET status = 'completed', article_id = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$articleId, $job['id']]);
            $this->pdo->prepare("UPDATE bot_runs SET status = 'completed', message = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$message, $runId]);
            log_activity($this->pdo, 'bot.article_created', 'article', $articleId, ['job_id' => (int) $job['id'], 'bot_id' => (int) $job['bot_id']]);
            return ['status' => 'completed', 'job_id' => (int) $job['id'], 'article_id' => $articleId, 'message' => $message];
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $this->pdo->prepare("UPDATE bot_jobs SET status = 'failed', error_message = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$message, $job['id']]);
            $this->pdo->prepare("UPDATE bot_runs SET status = 'failed', message = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$message, $runId]);
            error_log('Bot job failed: ' . $exception->getMessage());
            return ['status' => 'failed', 'job_id' => (int) $job['id'], 'message' => $message];
        }
    }

    private function publish(array $job): int
    {
        $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new BotJobException('The bot job payload is invalid.');
        }
        $duplicate = $this->pdo->prepare('SELECT id FROM articles WHERE LOWER(title) = LOWER(?) LIMIT 1');
        $duplicate->execute([$job['title']]);
        if ($duplicate->fetchColumn()) {
            throw new BotJobException('An article with this title was created before the job ran.');
        }
        $content = $this->buildWikiSource((string) $job['title'], $payload, (string) $job['bot_name']);
        $status = in_array($job['publication_mode'], ['draft', 'pending', 'published'], true) ? $job['publication_mode'] : 'pending';
        $slug = unique_slug($this->pdo, (string) $job['title']);
        $publishedAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO articles (title, slug, content, excerpt, status, author_id, seo_description, edit_count, created_by_bot_id, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $insert->execute([$job['title'], $slug, $content, excerpt($content, 220), $status, $job['requested_by'], $payload['short_description'], $job['bot_id'], $publishedAt]);
            $articleId = (int) $this->pdo->lastInsertId();
            $revision = $this->pdo->prepare('INSERT INTO revisions (article_id, user_id, title, content, edit_summary) VALUES (?, ?, ?, ?, ?)');
            $revision->execute([$articleId, $job['requested_by'], $job['title'], $content, 'Structured article created by ' . $job['bot_name'] . ' from administrator-supplied facts and sources']);
            sync_article_categories($this->pdo, $articleId, extract_categories($content));
            sync_search_document($this->pdo, $articleId);
            sync_article_links($this->pdo, $articleId, $content);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        if ($status === 'published') {
            notify_indexnow([site_url('/wiki/' . rawurlencode($slug)), site_url('/'), site_url('/feed.xml')]);
        }
        return $articleId;
    }

    private function buildWikiSource(string $title, array $payload, string $botName): string
    {
        $inlineTitle = $this->cleanInline($title);
        $type = $this->cleanInline((string) ($payload['article_type'] ?? 'Topic'));
        $description = trim((string) ($payload['short_description'] ?? ''));
        $blocks = [
            '{{Note|This structured article was generated by ' . $this->cleanInline($botName) . ' from administrator-supplied facts and reliable-source links. Human review, neutral wording, and citation verification remain required.}}',
        ];
        $facts = $this->parseFacts((string) ($payload['facts'] ?? ''));
        $infobox = ["{{Infobox", '| title = ' . $inlineTitle, '| type = ' . $type];
        foreach (array_slice($facts, 0, 8, true) as $label => $value) {
            $infobox[] = '| ' . slugify($label) . ' = ' . $this->cleanInline($value);
        }
        $infobox[] = '}}';
        $blocks[] = implode("\n", $infobox);
        $leadCitations = [];
        foreach (array_slice($payload['sources'] ?? [], 0, 5) as $source) {
            $leadCitations[] = '<ref>[' . $source['url'] . ' ' . $this->cleanInline($source['label']) . ']</ref>';
        }
        $blocks[] = "'''{$inlineTitle}''' — " . $description . implode('', $leadCitations);
        foreach (['overview' => 'Overview', 'background' => 'Background', 'significance' => 'Significance'] as $key => $heading) {
            $body = trim((string) ($payload[$key] ?? ''));
            if ($body !== '') {
                $blocks[] = '== ' . $heading . " ==\n" . $body;
            }
        }
        if ($facts) {
            $table = ["== Key facts ==", '{|', '|+ Structured facts', '|-', '! Fact !! Detail'];
            foreach ($facts as $label => $value) {
                $table[] = '|-';
                $table[] = '| ' . $this->cleanInline($label) . ' || ' . $this->cleanInline($value);
            }
            $table[] = '|}';
            $blocks[] = implode("\n", $table);
        }
        $sourceLines = ["== References ==", '{{reflist}}', '', '== Source list =='];
        foreach ($payload['sources'] ?? [] as $source) {
            $sourceLines[] = '* [' . $source['url'] . ' ' . $this->cleanInline($source['label']) . ']';
        }
        $blocks[] = implode("\n", $sourceLines);
        foreach (array_slice($payload['categories'] ?? [], 0, 12) as $category) {
            $category = $this->cleanInline((string) $category);
            if ($category !== '') {
                $blocks[] = '[[Category:' . $category . ']]';
            }
        }
        return implode("\n\n", $blocks);
    }

    private function parseSources(string $sourceText): array
    {
        $sources = [];
        foreach (preg_split('/\r?\n/u', $sourceText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$url, $label] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            $privateIp = filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if (!filter_var($url, FILTER_VALIDATE_URL) || mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $host === '' || $host === 'localhost' || $host === '[::1]' || preg_match('/[\s\[\]]/', $url) || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $privateIp) {
                throw new BotJobException('Every bot source must be a public HTTPS URL, optionally followed by “| label”.');
            }
            $sources[] = ['url' => mb_substr($url, 0, 1000), 'label' => mb_substr($label ?: $host, 0, 255)];
            if (count($sources) >= 20) {
                break;
            }
        }
        return $sources;
    }

    private function parseFacts(string $factsText): array
    {
        $facts = [];
        foreach (preg_split('/\r?\n/u', $factsText) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $line, 2));
            if ($label !== '' && $value !== '') {
                $facts[mb_substr($label, 0, 80)] = mb_substr($value, 0, 500);
            }
        }
        return $facts;
    }

    private function cleanInline(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['|', '}}', '[[', ']]', "\r", "\n"], ['—', '', '', '', ' ', ' '], $value)) ?? '');
    }
}
