<?php
declare(strict_types=1);

final class BotJobException extends RuntimeException {}

/**
 * Deterministic, source-backed article builder. It does not invent facts or call
 * a paid AI service: approved operators provide facts or pinned authority IDs;
 * the bot builds consistent wiki source and records every run and provenance link.
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
        $approval = $this->approvedBot($botId);
        if(!$approval)throw new BotJobException('This bot cannot run until its BRFA is approved and the bot is active.');
        if(empty($approval['allow_manual_payload']))throw new BotJobException('This BRFA does not permit operator-supplied structured payloads.');
        $this->assertQueueCapacity($botId,(int)$approval['daily_limit']);
        if($mode==='published'&&($approval['approval_status']!=='approved'||empty($approval['allow_direct_publish']))){
            $mode='pending';
        }
        $duplicate = $this->pdo->prepare('SELECT id FROM articles WHERE LOWER(title) = LOWER(?) LIMIT 1');
        $duplicate->execute([$title]);
        if($duplicate->fetchColumn())throw new BotJobException('An article with that title already exists.');
        $queuedDuplicate=$this->pdo->prepare("SELECT 1 FROM bot_jobs WHERE LOWER(title)=LOWER(?) AND status IN ('queued','running') LIMIT 1");$queuedDuplicate->execute([$title]);if($queuedDuplicate->fetchColumn())throw new BotJobException('An article job with that title is already queued.');
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
        $stmt = $this->pdo->prepare("INSERT INTO bot_jobs (bot_id,requested_by,approval_request_id,title,payload_json,publication_mode,status,scheduled_for) VALUES (?,?,?,?,?,?,'queued',?)");
        $stmt->execute([$botId,$administratorId,$approval['approval_id'],$title,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$mode,$scheduled]);
        $jobId = (int) $this->pdo->lastInsertId();
        log_activity($this->pdo, 'bot.job_queued', 'bot_job', $jobId, ['bot_id' => $botId, 'mode' => $mode, 'scheduled_for' => $scheduled]);
        return $jobId;
    }

    public function queueAuthority(int $botId, int $operatorId, int $sourceId, string $identifier, string $mode = 'pending'): int
    {
        $approval = $this->approvedBot($botId);
        if(!$approval)throw new BotJobException('An approved BRFA and active bot are required.');
        $allowedSourceIds=array_values(array_filter(array_map('intval',explode(',',(string)($approval['allowed_source_ids']??'')))));if(!in_array($sourceId,$allowedSourceIds,true))throw new BotJobException('This authority connector is outside the approved BRFA scope.');
        $this->assertQueueCapacity($botId,(int)$approval['daily_limit']);
        if($mode==='published'&&($approval['approval_status']!=='approved'||empty($approval['allow_direct_publish'])))$mode='pending';
        if (!in_array($mode, ['draft', 'pending', 'published'], true)) $mode = 'pending';
        $source = $this->pdo->prepare("SELECT id,name,publication_policy FROM authority_sources WHERE id=? AND status='active'");
        $source->execute([$sourceId]);
        $authority = $source->fetch();
        if (!$authority) throw new BotJobException('Select an active verified authority source.');
        if($mode==='published'&&$authority['publication_policy']!=='direct')$mode='pending';
        $identifier = mb_substr(trim($identifier), 0, 255);
        if ($identifier === '') throw new BotJobException('Enter an external record identifier.');
        $existing = $this->pdo->prepare('SELECT article_id FROM article_external_identifiers WHERE authority_source_id = ? AND external_identifier = ?');
        $existing->execute([$sourceId, $identifier]);
        if ($existing->fetchColumn()) throw new BotJobException('That authority record already has an article.');
        $duplicate = $this->pdo->prepare("SELECT id FROM bot_jobs WHERE authority_source_id = ? AND external_identifier = ? AND status IN ('queued','running','completed') LIMIT 1");
        $duplicate->execute([$sourceId, $identifier]);
        if ($duplicate->fetchColumn()) throw new BotJobException('That authority record is already queued or completed.');
        $payload = json_encode(['authority_record' => true], JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare("INSERT INTO bot_jobs (bot_id,requested_by,approval_request_id,title,payload_json,publication_mode,status,authority_source_id,external_identifier,scheduled_for) VALUES (?,?,?,?,?,?,'queued',?,?,UTC_TIMESTAMP())");
        $stmt->execute([$botId,$operatorId,$approval['approval_id'],$authority['name'].' record '.$identifier,$payload,$mode,$sourceId,$identifier]);
        $jobId = (int) $this->pdo->lastInsertId();
        log_activity($this->pdo, 'bot.authority_record_queued', 'bot_job', $jobId, ['source_id' => $sourceId, 'external_identifier' => $identifier]);
        return $jobId;
    }

    private function assertQueueCapacity(int $botId,int $dailyLimit): void
    {
        $pending=$this->pdo->prepare("SELECT COUNT(*) FROM bot_jobs WHERE bot_id=? AND status IN ('queued','running')");$pending->execute([$botId]);if((int)$pending->fetchColumn()>=max(20,min(300,$dailyLimit*3)))throw new BotJobException('This bot queue has reached its safety backlog limit.');
        $burst=$this->pdo->prepare('SELECT COUNT(*) FROM bot_jobs WHERE bot_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)');$burst->execute([$botId]);if((int)$burst->fetchColumn()>=20)throw new BotJobException('Bot queue burst limit reached. Wait one minute.');
    }

    private function approvedBot(int $botId,bool $forUpdate=false): ?array
    {
        $sql="SELECT b.id, b.daily_limit, br.id AS approval_id,br.allow_direct_publish,br.allow_manual_payload,br.allowed_source_ids,br.status AS approval_status
            FROM bots b JOIN bot_approval_requests br ON br.id=(SELECT MAX(br2.id) FROM bot_approval_requests br2 WHERE br2.bot_id=b.id)
            WHERE b.id = ? AND b.status = 'active' AND br.status IN ('approved','trial')
              AND (br.status='approved' OR (br.status='trial' AND br.trial_expires_at>UTC_TIMESTAMP()))
            ORDER BY br.id DESC LIMIT 1".($forUpdate?' FOR UPDATE':'');
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute([$botId]);
        return $stmt->fetch() ?: null;
    }

    public function runNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE bot_runs SET status = 'failed', message = 'Worker lease expired before completion.', finished_at = UTC_TIMESTAMP() WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
            $this->pdo->exec("UPDATE bot_jobs SET status = CASE WHEN attempts < 3 THEN 'queued' ELSE 'failed' END, scheduled_for = UTC_TIMESTAMP(), error_message = 'Recovered after an interrupted worker.', completed_at = CASE WHEN attempts >= 3 THEN UTC_TIMESTAMP() ELSE NULL END WHERE status = 'running' AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)");
            $stmt = $this->pdo->query("SELECT j.*,b.name AS bot_name,b.daily_limit,b.user_id AS bot_user_id,br.id AS current_approval_id,br.allow_direct_publish,br.allow_manual_payload,br.allowed_source_ids,br.status AS approval_status,src.publication_policy AS source_publication_policy
                FROM bot_jobs j JOIN bots b ON b.id=j.bot_id
                LEFT JOIN authority_sources src ON src.id=j.authority_source_id
                JOIN bot_approval_requests br ON br.id = (SELECT MAX(br2.id) FROM bot_approval_requests br2 WHERE br2.bot_id = b.id)
                WHERE j.status = 'queued' AND br.status IN ('approved','trial') AND j.scheduled_for <= UTC_TIMESTAMP() AND b.status = 'active'
                  AND (br.status='approved' OR (br.status='trial' AND br.trial_expires_at>UTC_TIMESTAMP()))
                ORDER BY j.scheduled_for, j.id LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch();
            if(!$job){$this->pdo->commit();return null;}
            $scopeIds=array_values(array_filter(array_map('intval',explode(',',(string)($job['allowed_source_ids']??'')))));$scopeAllowed=!empty($job['authority_source_id'])?in_array((int)$job['authority_source_id'],$scopeIds,true):!empty($job['allow_manual_payload']);
            if(!$scopeAllowed){$this->pdo->prepare("UPDATE bot_jobs SET status='failed',error_message='Job is outside the current BRFA scope.',completed_at=UTC_TIMESTAMP() WHERE id=?")->execute([$job['id']]);$this->pdo->commit();return ['status'=>'failed','job_id'=>(int)$job['id'],'message'=>'Job is outside the current BRFA scope.'];}
            $directAllowed=$job['approval_status']==='approved'&&!empty($job['allow_direct_publish'])&&(empty($job['authority_source_id'])||$job['source_publication_policy']==='direct');
            if($job['publication_mode']==='published'&&!$directAllowed){$job['publication_mode']='pending';$this->pdo->prepare("UPDATE bot_jobs SET publication_mode='pending',error_message='Direct publication permission is not active; downgraded to review.' WHERE id=?")->execute([$job['id']]);}
            $daily = $this->pdo->prepare("SELECT COUNT(*) FROM bot_jobs WHERE bot_id = ? AND status = 'completed' AND completed_at >= UTC_DATE()");
            $daily->execute([$job['bot_id']]);
            if ((int) $daily->fetchColumn() >= (int) $job['daily_limit']) {
                $this->pdo->prepare("UPDATE bot_jobs SET scheduled_for = DATE_ADD(UTC_DATE(), INTERVAL 1 DAY), error_message = 'Daily safety limit reached; deferred.' WHERE id = ?")->execute([$job['id']]);
                $this->pdo->commit();
                return ['status' => 'deferred', 'job_id' => (int) $job['id'], 'message' => 'Daily safety limit reached.'];
            }
            $this->pdo->prepare("UPDATE bot_jobs SET status='running',execution_approval_request_id=?,started_at=UTC_TIMESTAMP(),attempts=attempts+1,error_message=NULL WHERE id=?")->execute([$job['current_approval_id'],$job['id']]);
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
            if (!empty($job['authority_source_id']) && !empty($job['external_identifier'])) {
                require_once __DIR__ . '/AuthorityDataService.php';
                $record=(new AuthorityDataService($this->pdo))->fetch((int)$job['authority_source_id'],(string)$job['external_identifier']);
                if($job['publication_mode']==='published'&&empty($record['direct_publication_eligible'])){$job['publication_mode']='pending';$this->pdo->prepare("UPDATE bot_jobs SET publication_mode='pending' WHERE id=?")->execute([$job['id']]);}
                $job['title'] = $record['title'];
                $job['payload_json'] = json_encode([
                    'short_description' => $record['description'], 'article_type' => $record['article_type'],
                    'overview' => $record['overview'], 'background' => '', 'significance' => '',
                    'facts' => implode("\n", array_map(static fn($key, $value): string => $key . ': ' . $value, array_keys($record['facts']), array_values($record['facts']))),
                    'sources' => $record['sources'], 'categories' => $record['categories'], 'authority' => $record,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $this->pdo->prepare('UPDATE bot_jobs SET title = ?, payload_json = ? WHERE id = ?')->execute([$job['title'], $job['payload_json'], $job['id']]);
            }
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

    private function publish(array &$job): int
    {
        $currentApproval=$this->approvedBot((int)$job['bot_id']);if(!$currentApproval)throw new BotJobException('Bot approval was withdrawn before publication.');
        $currentScopeIds=array_values(array_filter(array_map('intval',explode(',',(string)($currentApproval['allowed_source_ids']??'')))));if(!empty($job['authority_source_id'])&&!in_array((int)$job['authority_source_id'],$currentScopeIds,true))throw new BotJobException('Authority scope was withdrawn before publication.');if(empty($job['authority_source_id'])&&empty($currentApproval['allow_manual_payload']))throw new BotJobException('Structured payload scope was withdrawn before publication.');
        if(!empty($job['authority_source_id'])){$sourceState=$this->pdo->prepare('SELECT status,publication_policy FROM authority_sources WHERE id=?');$sourceState->execute([$job['authority_source_id']]);$sourceState=$sourceState->fetch();if(!$sourceState||$sourceState['status']!=='active')throw new BotJobException('Authority connector was suspended before publication.');if($job['publication_mode']==='published'&&$sourceState['publication_policy']!=='direct')$job['publication_mode']='pending';}
        if($job['publication_mode']==='published'&&($currentApproval['approval_status']!=='approved'||empty($currentApproval['allow_direct_publish'])))$job['publication_mode']='pending';
        $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new BotJobException('The bot job payload is invalid.');
        }
        if(!empty($job['authority_source_id'])&&$job['publication_mode']==='published'&&empty($payload['authority']['direct_publication_eligible']))$job['publication_mode']='pending';
        $this->pdo->prepare('UPDATE bot_jobs SET publication_mode=? WHERE id=?')->execute([$job['publication_mode'],$job['id']]);
        $duplicate = $this->pdo->prepare('SELECT id FROM articles WHERE LOWER(title) = LOWER(?) LIMIT 1');
        $duplicate->execute([$job['title']]);
        if ($duplicate->fetchColumn()) {
            throw new BotJobException('An article with this title was created before the job ran.');
        }
        $content = $this->buildWikiSource((string) $job['title'], $payload, (string) $job['bot_name']);
        $status=in_array($job['publication_mode'],['draft','pending','published'],true)?$job['publication_mode']:'pending';
        $slug=unique_slug($this->pdo,(string)$job['title']);
        $this->pdo->beginTransaction();
        try {
            $lockedApproval=$this->approvedBot((int)$job['bot_id'],true);if(!$lockedApproval)throw new BotJobException('Bot approval was withdrawn during publication.');$lockedScopes=array_values(array_filter(array_map('intval',explode(',',(string)($lockedApproval['allowed_source_ids']??'')))));if(!empty($job['authority_source_id'])&&!in_array((int)$job['authority_source_id'],$lockedScopes,true))throw new BotJobException('Authority scope was withdrawn during publication.');if(empty($job['authority_source_id'])&&empty($lockedApproval['allow_manual_payload']))throw new BotJobException('Structured payload scope was withdrawn during publication.');
            if(!empty($job['authority_source_id'])){$lockedSource=$this->pdo->prepare('SELECT status,publication_policy FROM authority_sources WHERE id=? FOR UPDATE');$lockedSource->execute([$job['authority_source_id']]);$lockedSource=$lockedSource->fetch();if(!$lockedSource||$lockedSource['status']!=='active')throw new BotJobException('Authority connector was suspended during publication.');if($status==='published'&&$lockedSource['publication_policy']!=='direct')$status='pending';}
            if($status==='published'&&($lockedApproval['approval_status']!=='approved'||empty($lockedApproval['allow_direct_publish'])))$status='pending';if($status==='published'&&!empty($job['authority_source_id'])&&empty($payload['authority']['direct_publication_eligible']))$status='pending';$job['publication_mode']=$status;$this->pdo->prepare('UPDATE bot_jobs SET publication_mode=? WHERE id=?')->execute([$status,$job['id']]);$publishedAt=$status==='published'?gmdate('Y-m-d H:i:s'):null;
            $insert = $this->pdo->prepare('INSERT INTO articles (title, slug, content, excerpt, status, author_id, seo_description, edit_count, created_by_bot_id, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $botUserId=(int)($job['bot_user_id']??0);if(!$botUserId)throw new BotJobException('The approved bot does not have a dedicated user account.');
            $insert->execute([$job['title'], $slug, $content, excerpt($content, 220), $status, $botUserId, $payload['short_description'], $job['bot_id'], $publishedAt]);
            $articleId = (int) $this->pdo->lastInsertId();
            $revision = $this->pdo->prepare('INSERT INTO revisions (article_id, user_id, title, content, edit_summary) VALUES (?, ?, ?, ?, ?)');
            $revision->execute([$articleId, $botUserId, $job['title'], $content, 'Structured article created by approved bot ' . $job['bot_name'] . ' from recorded sources']);
            sync_article_categories($this->pdo, $articleId, extract_categories($content));
            sync_search_document($this->pdo, $articleId);
            sync_article_links($this->pdo, $articleId, $content);
            if (!empty($payload['authority']['authority_source_id'])) {
                $authority = $payload['authority'];
                $this->pdo->prepare('INSERT INTO article_external_identifiers (article_id,authority_source_id,external_identifier,record_url,record_hash,adapter_version,retrieved_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())')
                    ->execute([$articleId,$authority['authority_source_id'],$authority['external_identifier'],$authority['record_url'],$authority['record_hash'],$authority['adapter_version']??null]);
            }
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
        $authority = $payload['authority'] ?? null;
        $provenance = $authority
            ? 'This structured article was generated by ' . $this->cleanInline($botName) . ' from the verified ' . $this->cleanInline((string) $authority['authority_name']) . ' record ' . $this->cleanInline((string) $authority['external_identifier']) . '. License: ' . $this->cleanInline((string) ($authority['authority_license'] ?: 'source terms')) . '. Human review remains required.'
            : 'This structured article was generated by ' . $this->cleanInline($botName) . ' from operator-supplied facts and reliable-source links. Human review, neutral wording, and citation verification remain required.';
        $blocks = ['{{Note|' . $provenance . '}}'];
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
