<?php
/**
 * MiaScheduledJobs — the two weekly jobs behind the crons mia_audit_weekly and mia_search_weekly (Phase 1.11).
 *
 *   auditWeekly   for every VERIFIED website: crawl it (polite, bounded, change-tracking) and run the audit rules.
 *   searchWeekly  for every verified website: score the opportunities (Search Console + audit findings), put the best
 *                 in the owner's queue, write the AI wording for the best title/description proposals, and expire
 *                 proposals nobody looked at for 45 days.
 *
 * Both are safe to run twice: each website is done at most once per ISO week (ops_settings key
 * mia_<job>_week_<site>_<website>), unless $force. Both stop starting new work when the time budget is nearly used
 * and say so, so one slow site cannot starve the others or run the cron past its limit. A failure on one website
 * never stops the rest, and every failure is reported with its cause (class, file:line, message) because a bare
 * "Cron failed" cost hours once.
 *
 * Nothing here publishes or sends anything: the output is findings, proposals and drafts for the owner to decide.
 *
 * The services are built through $factories (tests replace them); the defaults are the real classes.
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaWebsiteService.php';
require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaLlmGateway.php';
require_once __DIR__ . '/MiaOpportunityScorer.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/Site/SiteCrawler.php';
require_once __DIR__ . '/Site/SiteAuditRules.php';

final class MiaScheduledJobs
{
    /** Total seconds a cron run may use. The crons set a hard PHP limit above this. */
    public const BUDGET_SECONDS = 270;
    /** Do not START another website with less than this left. */
    public const MIN_SECONDS_TO_START = 60;
    public const TABLES_AUDIT = ['ops_settings', 'mia_websites', 'mia_site_pages', 'mia_site_links', 'mia_audits', 'mia_audit_findings'];
    public const TABLES_SEARCH = ['mia_websites', 'mia_site_pages', 'mia_audit_findings', 'mia_actions', 'mia_action_events', 'mia_decisions', 'mia_memory', 'mia_llm_usage', 'mia_content_drafts', 'ops_settings'];

    /** @var PDO */
    private $db;
    /** @var array<string,callable> */
    private $factories;
    /** @var callable fn(): float */
    private $clock;

    /**
     * Factories (all optional):
     *   crawl(int $site, int $website, int $maxSeconds): array      SiteCrawler::crawl() result
     *   audit(int $site, int $website, DateTimeImmutable $now): array   SiteAuditRules::run() result
     *   propose(int $site, int $website, DateTimeImmutable $now): array  MiaOpportunityScorer::proposeTop() result
     *   draft(int $site, int $website, DateTimeImmutable $now): array    MiaRecommendationWriter::prepareOpen() result
     *   expire(int $site, DateTimeImmutable $now): int
     */
    public function __construct(PDO $db, array $factories = [], ?callable $clock = null)
    {
        $this->db        = $db;
        $this->factories = $factories;
        $this->clock     = $clock ?: function (): float { return microtime(true); };
    }

    /** Which of these tables are missing? (portable: works on MySQL and SQLite) @return string[] */
    public function missingTables(array $tables): array
    {
        $missing = [];
        foreach ($tables as $t) {
            try { $this->db->query("SELECT 1 FROM {$t} LIMIT 0"); } catch (Throwable $e) { $missing[] = $t; }
        }
        return $missing;
    }

    /** One line saying exactly what failed and where. */
    public static function cause(Throwable $e): string
    {
        return get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    // ---- audit --------------------------------------------------------------------------------------

    /**
     * @return array{status:string,summary:string,websites:int,done:int,skipped:int,deferred:int,failed:int,errors:string[]}
     */
    public function auditWeekly(DateTimeImmutable $now, bool $force = false): array
    {
        return $this->locked(function () use ($now, $force) { return $this->doAudit($now, $force); });
    }

    private function doAudit(DateTimeImmutable $now, bool $force): array
    {
        $r = $this->blank();
        $t0 = ($this->clock)();
        foreach ($this->websites() as $w) {
            $r['websites']++;
            $site = (int)$w['site_id']; $web = (int)$w['id'];
            if (!$force && $this->doneThisWeek('audit', $site, $web, $now)) { $r['skipped']++; continue; }
            $left = self::BUDGET_SECONDS - (($this->clock)() - $t0);
            if ($left < self::MIN_SECONDS_TO_START) { $r['deferred']++; continue; }
            try {
                $crawl = isset($this->factories['crawl'])
                    ? call_user_func($this->factories['crawl'], $site, $web, (int)min(240, $left - 20))
                    : (new SiteCrawler($this->db, $site, new MiaWebsiteService($this->db, $site)))->crawl($web, ['max_seconds' => (int)min(240, $left - 20)]);
                if (!$crawl['ok']) {
                    $r['failed']++;
                    $r['errors'][] = $w['host'] . ': ' . ($crawl['code'] === 'not_verified' ? 'needs to be verified again (proof expired or removed)' : (string)($crawl['error'] ?? $crawl['code']));
                    continue;
                }
                $rules = isset($this->factories['audit'])
                    ? call_user_func($this->factories['audit'], $site, $web, $now)
                    : (new SiteAuditRules($this->db, $site))->run($web, $now);
                if (!$rules['ok']) {
                    $r['failed']++;
                    $r['errors'][] = $w['host'] . ': checks failed (' . (string)($rules['error'] ?? $rules['code']) . ')';
                    continue;
                }
                $this->markDone('audit', $site, $web, $now);
                $r['done']++;
                $r['lines'][] = sprintf('%s: %d pages (%s), %d findings (%d high), %d new, %d resolved',
                    $w['host'], (int)$crawl['pages'], (string)$crawl['status'], (int)$rules['total'], (int)($rules['by_severity']['high'] ?? 0), (int)$rules['new'], (int)$rules['resolved']);
            } catch (Throwable $e) {
                $r['failed']++;
                $r['errors'][] = $w['host'] . ': ' . self::cause($e);
                error_log('[mia_audit_weekly] ' . $w['host'] . ' ' . self::cause($e));
            }
        }
        return $this->finish($r, 'audited');
    }

    // ---- search -------------------------------------------------------------------------------------

    /**
     * @return array{status:string,summary:string,websites:int,done:int,skipped:int,deferred:int,failed:int,errors:string[]}
     */
    public function searchWeekly(DateTimeImmutable $now, bool $force = false): array
    {
        return $this->locked(function () use ($now, $force) { return $this->doSearch($now, $force); });
    }

    private function doSearch(DateTimeImmutable $now, bool $force): array
    {
        $r = $this->blank();
        $t0 = ($this->clock)();
        $expired = [];
        foreach ($this->websites() as $w) {
            $r['websites']++;
            $site = (int)$w['site_id']; $web = (int)$w['id'];
            if (!$force && $this->doneThisWeek('search', $site, $web, $now)) { $r['skipped']++; continue; }
            if ((self::BUDGET_SECONDS - (($this->clock)() - $t0)) < self::MIN_SECONDS_TO_START) { $r['deferred']++; continue; }
            try {
                $p = isset($this->factories['propose'])
                    ? call_user_func($this->factories['propose'], $site, $web, $now)
                    : $this->defaultPropose($site, $web, $now);
                if (!empty($p['failed']) && empty($p['proposed']) && empty($p['existing'])) {
                    $r['failed']++;
                    $r['errors'][] = $w['host'] . ': the opportunities could not be worked out (is the website still verified?)';
                    continue;
                }
                if (!empty($p['failed'])) {                      // some saved, some not: try again next run (saving is idempotent)
                    $r['failed']++;
                    $r['errors'][] = $w['host'] . ': ' . (int)$p['failed'] . ' proposals could not be saved';
                    continue;
                }
                $d = isset($this->factories['draft'])
                    ? call_user_func($this->factories['draft'], $site, $web, $now)
                    : $this->defaultDraft($site, $web, $now);
                if (!isset($expired[$site])) {
                    $expired[$site] = isset($this->factories['expire'])
                        ? (int)call_user_func($this->factories['expire'], $site, $now)
                        : (int)(new MiaActionService($this->db, $site))->expireStale($now);
                }
                $this->markDone('search', $site, $web, $now);
                $r['done']++;
                $line = sprintf('%s: %d new proposals (%d already open, %d held back), %d drafts written',
                    $w['host'], (int)($p['proposed'] ?? 0), (int)($p['existing'] ?? 0), (int)($p['held_back'] ?? 0), (int)($d['drafted'] ?? 0));
                if (!empty($d['stopped'])) { $line .= ' — AI limit reached (' . $d['stopped'] . '), plain wording kept'; $r['warn'] = true; }
                $r['lines'][] = $line;
            } catch (Throwable $e) {
                $r['failed']++;
                $r['errors'][] = $w['host'] . ': ' . self::cause($e);
                error_log('[mia_search_weekly] ' . $w['host'] . ' ' . self::cause($e));
            }
        }
        if (array_sum($expired) > 0) { $r['lines'][] = array_sum($expired) . ' old proposals expired'; }
        return $this->finish($r, 'processed');
    }

    /**
     * One weekly job at a time, whether it was started by cron or by an admin's web click: the two jobs share a MySQL
     * named lock, so a second start (or the search job while the audit still runs) waits for nothing and reports
     * "already running" instead of doing the same work twice.
     */
    private function locked(callable $fn): array
    {
        $name = 'mia_weekly_jobs';
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            try {
                $s = $this->db->prepare("SELECT GET_LOCK(?, 0)");
                $s->execute([$name]);
                if ((int)$s->fetchColumn() !== 1) {
                    $r = $this->blank();
                    $r['status'] = 'warning';
                    $r['summary'] = 'Another Mia weekly job is still running, so this one was skipped';
                    return $r;
                }
            } catch (Throwable $e) {
                $r = $this->blank();
                $r['status'] = 'warning';
                $r['summary'] = 'Could not take the job lock: ' . self::cause($e);
                return $r;
            }
        }
        try {
            return $fn();
        } finally {
            if ($mysql) { try { $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]); } catch (Throwable $e) { /* released on disconnect */ } }
        }
    }

    // ---- defaults -----------------------------------------------------------------------------------

    private function defaultPropose(int $site, int $web, DateTimeImmutable $now): array
    {
        $scorer = new MiaOpportunityScorer($this->db, $site, new MiaWebsiteService($this->db, $site));
        return $scorer->proposeTop($web, new MiaActionService($this->db, $site), 5, 20.0, $now);
    }

    private function defaultDraft(int $site, int $web, DateTimeImmutable $now): array
    {
        $actions = new MiaActionService($this->db, $site);
        $writer = new MiaRecommendationWriter($this->db, $site, new MiaLlmGateway($this->db, $site), $actions);
        return $writer->prepareOpen($web, 3, $now);
    }

    // ---- helpers ------------------------------------------------------------------------------------

    /** @return array<int,array{id:int,site_id:int,host:string}> */
    private function websites(): array
    {
        $s = $this->db->query("SELECT id, site_id, host FROM mia_websites WHERE status = 'verified' ORDER BY site_id, id");
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function key(string $job, int $site, int $web): string
    {
        return 'mia_' . $job . '_week_' . $site . '_' . $web;
    }

    private function doneThisWeek(string $job, int $site, int $web, DateTimeImmutable $now): bool
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::key($job, $site, $web)]);
            return (string)$s->fetchColumn() === $now->format('o-\WW');
        } catch (Throwable $e) {
            return false;
        }
    }

    private function markDone(string $job, int $site, int $web, DateTimeImmutable $now): void
    {
        $k = self::key($job, $site, $web);
        $v = $now->format('o-\WW');
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
        $u->execute([$v, $k]);
        if ($u->rowCount() === 0) {
            $chk = $this->db->prepare("SELECT 1 FROM ops_settings WHERE setting_key = ?");
            $chk->execute([$k]);
            if ($chk->fetchColumn() === false) {
                try {
                    $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Mia weekly job: last ISO week done')")->execute([$k, $v]);
                } catch (Throwable $dup) {                       // another process inserted it first: just set the value
                    $u->execute([$v, $k]);
                }
            }
        }
    }

    private function blank(): array
    {
        return ['status' => 'success', 'summary' => '', 'websites' => 0, 'done' => 0, 'skipped' => 0, 'deferred' => 0, 'failed' => 0, 'errors' => [], 'lines' => [], 'warn' => false];
    }

    private function finish(array $r, string $verb): array
    {
        if ($r['websites'] === 0) {
            $r['summary'] = 'No verified websites to work on';
            $r['status'] = 'success';
            return $r;
        }
        $parts = ["{$r['done']} of {$r['websites']} websites $verb"];
        if ($r['skipped']) { $parts[] = "{$r['skipped']} already done this week"; }
        if ($r['deferred']) { $parts[] = "{$r['deferred']} left for next run (time limit)"; }
        if ($r['failed']) { $parts[] = "{$r['failed']} failed"; }
        $summary = implode(', ', $parts);
        if ($r['lines']) { $summary .= '. ' . implode('; ', $r['lines']); }
        if ($r['errors']) { $summary .= '. Problems: ' . implode('; ', $r['errors']); }
        $r['summary'] = $summary;
        if ($r['failed'] > 0 && $r['done'] === 0) { $r['status'] = 'error'; }                  // nothing this run attempted worked
        elseif ($r['failed'] > 0 || $r['deferred'] > 0 || $r['warn']) { $r['status'] = 'warning'; }
        return $r;
    }
}
