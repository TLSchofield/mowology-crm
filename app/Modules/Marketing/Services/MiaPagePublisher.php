<?php
/**
 * MiaPagePublisher — turns an APPROVED generated page into an UNPUBLISHED page in the website editor (the CMS, `blog/<slug>`).
 *
 * Nothing Mia does makes the page public: it is saved with status 'draft', the owner reads it in the website editor and publishes it
 * there, or never. Rules (each has a test):
 *   - only an approved page_draft whose approval hash matches the page as it stands now (title, description, body, and the real
 *     projects that will be appended), via MiaActionService::verifyApproval;
 *   - the body is turned into HTML only by MiaPageMarkup (everything escaped, no tags can be written);
 *   - one page per action;
 *   - Undo deletes the page only while it is still a draft and still exactly what Mia wrote (title, description and body unchanged);
 *     once it was edited or published Undo refuses and says where to go;
 *   - every apply and undo is a row in mia_executions (connector 'cms_article') and an audit event.
 * Same four methods as MiaPublishService (preview, apply, rollback, executionsFor) so the API layer treats them alike.
 * Global namespace, no autoloader. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/MiaPageMarkup.php';
require_once __DIR__ . '/MiaPageExtras.php';
require_once __DIR__ . '/MiaStoryPublisher.php';
require_once __DIR__ . '/MiaPublishService.php';       // MiaPublishConflict
require_once __DIR__ . '/MiaPageWriter.php';
require_once __DIR__ . '/../../CMS/Services/ArticleService.php';

final class MiaPagePublisher
{
    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaActionService */
    private $actions;

    public function __construct(PDO $db, int $siteId, MiaActionService $actions)
    {
        $this->db = $db; $this->siteId = $siteId; $this->actions = $actions;
    }

    private function fail(string $code, string $error): array { return ['ok' => false, 'code' => $code, 'error' => $error]; }

    private function base(int $websiteId): string
    {
        try {
            $s = $this->db->prepare("SELECT base_url FROM mia_websites WHERE id = ? AND site_id = ?");
            $s->execute([$websiteId, $this->siteId]);
            $u = rtrim((string)$s->fetchColumn(), '/');
            return preg_match('#^https://[a-z0-9.-]+$#i', $u) ? $u : '';
        } catch (Throwable $e) { return ''; }
    }

    private function businessName(): string
    {
        try {
            $m = (new MiaMemoryService($this->db, $this->siteId))->recall('brand', 'short_name');
            if (is_string($m) && trim($m) !== '') { return trim($m); }
            return trim((string)$this->db->query("SELECT company_name FROM business_settings WHERE id = 1")->fetchColumn());
        } catch (Throwable $e) { return ''; }
    }

    /** The one string an approval is bound to: wording, page text and the real projects appended. */
    public static function approvalContent(string $title, string $desc, string $body, array $projects): string
    {
        return MiaRecommendationWriter::draftText($title, $desc) . "\nBODY:\n" . MiaPageMarkup::normalise($body) . "\n" . MiaPageExtras::signature($projects);
    }

    /** The action, its page draft and what would be appended, for the API and for apply. */
    public function inspect(int $actionId): array
    {
        $a = $this->actions->get($actionId);
        if (!$a) { return $this->fail('not_found', 'No such action'); }
        if (!MiaPageWriter::wantsNewPage($a)) { return $this->fail('not_supported', 'This is not a request for a new page.'); }
        if ($a['draft_ref_table'] !== 'mia_content_drafts' || empty($a['draft_ref_id'])) { return $this->fail('no_draft', 'There is no page text yet. Ask Mia to write the page first.'); }
        $s = $this->db->prepare("SELECT * FROM mia_content_drafts WHERE id = ? AND site_id = ? AND action_id = ? AND kind = 'page' AND status = 'draft'");
        $s->execute([(int)$a['draft_ref_id'], $this->siteId, $actionId]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return $this->fail('no_draft', 'The page text is no longer available.'); }
        if (trim((string)$d['title']) === '' || trim((string)$d['body']) === '') { return $this->fail('no_draft', 'The page is incomplete.'); }
        $ev = json_decode((string)$a['evidence'], true);
        $query = (string)($ev[0]['query'] ?? '');
        $projects = MiaPageExtras::projects($this->db, $query);
        $can = true; $why = null;
        if ($this->base((int)$d['website_id']) === '') { $can = false; $why = 'The website address is not verified, so links cannot be added.'; }
        elseif ($this->existingExecution($actionId)) { $can = false; $why = 'A page for this already exists. Open it in the website editor.'; }
        return ['ok' => true, 'action' => $a, 'draft' => $d, 'query' => $query, 'projects' => $projects, 'can_apply' => $can, 'why_not' => $why,
                'content' => self::approvalContent((string)$d['title'], (string)$d['meta_desc'], (string)$d['body'], $projects)];
    }

    private function existingExecution(int $actionId): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM mia_executions WHERE action_id = ? AND site_id = ? AND connector = 'cms_article' AND status IN ('applied','verified','unverified') ORDER BY id DESC LIMIT 1");
            $s->execute([$actionId, $this->siteId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) { return null; }                                  // without the table the insert below fails and the page is removed again
    }

    public function preview(int $actionId): array
    {
        $c = $this->inspect($actionId);
        if (!$c['ok']) { return $c; }
        $d = $c['draft'];
        return ['ok' => true, 'page_url' => 'Website editor (saved as an unpublished page)', 'can_apply' => $c['can_apply'], 'why_not' => $c['why_not'],
                'before' => ['title' => null, 'description' => null], 'after' => ['title' => $d['title'], 'description' => $d['meta_desc']]];
    }

    /** @return array{ok:bool, code?:string, error?:string, execution_id?:int, status?:string, note?:string, page_id?:int} */
    public function apply(int $actionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $c = $this->inspect($actionId);
        if (!$c['ok']) { return $c; }
        if ($c['action']['status'] !== 'approved') { return $this->fail('not_approved', 'Approve it first. It is ' . $c['action']['status'] . '.'); }
        $d = $c['draft'];
        $v = $this->actions->verifyApproval($actionId, $c['content'], $now);
        if (empty($v['ok'])) { return $this->fail($v['code'] ?? 'not_approved', $v['error'] ?? 'Not approved'); }
        if (!$c['can_apply']) { return $this->fail('cannot_apply', (string)$c['why_not']); }

        $base = $this->base((int)$d['website_id']);
        $r = MiaPageMarkup::render((string)$d['body'], MiaPageExtras::html($c['projects'], $base, $this->businessName()));
        $bodyHtml = $r['html'];
        try {
            $saved = (new ArticleService($this->db, $this->siteId))->save([
                'title' => trim((string)$d['title']), 'body_html' => $bodyHtml, 'meta_description' => trim((string)$d['meta_desc']), 'keyword' => $c['query'],
                'faq_items' => $r['faq'], 'status' => 'draft',
            ], $userId);
        } catch (Throwable $e) {
            error_log('MiaPagePublisher save: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not save the page. Nothing was created.');
        }
        $pageId = (int)$saved['page_id'];
        $snapshot = ['page_id' => $pageId, 'slug' => $saved['slug'], 'title' => trim((string)$d['title']), 'meta_description' => trim((string)$d['meta_desc']), 'body_sha1' => sha1($bodyHtml)];
        $execId = 0;
        try {
            $this->db->beginTransaction();
            $this->db->prepare("INSERT INTO mia_executions (site_id, action_id, draft_id, website_id, connector, target_ref, page_url, before_json, after_json, status, verify_note, applied_by, applied_at)
                                VALUES (?, ?, ?, ?, 'cms_article', ?, ?, '{}', ?, 'applied', ?, ?, ?)")
                ->execute([$this->siteId, $actionId, (int)$d['id'], (int)$d['website_id'], 'cms_pages:' . $pageId, '/' . $saved['slug'], json_encode($snapshot),
                           'Saved as an unpublished page. It is not public until you publish it in the website editor.', $userId, $now->format('Y-m-d H:i:s')]);
            $execId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE mia_content_drafts SET status = 'applied' WHERE id = ? AND site_id = ?")->execute([(int)$d['id'], $this->siteId]);
            if (!$this->actions->markExecuted($actionId, $userId, 'Saved as unpublished page /' . $saved['slug'] . ' (execution #' . $execId . ')', $now)) {
                throw new MiaPublishConflict('This action was just changed by someone else. The page was removed again.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $this->deletePage($pageId);                                         // compensate: no orphan page when the record of it failed
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaPagePublisher apply: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not save the page. Nothing was created.');
        }
        return ['ok' => true, 'execution_id' => $execId, 'status' => 'applied', 'page_id' => $pageId,
                'note' => 'Saved as an unpublished page (/' . $saved['slug'] . '). Open it in the website editor to read it and publish it.'];
    }

    private function deletePage(int $pageId): void
    {
        try {
            $this->db->prepare("DELETE FROM cms_blocks WHERE page_id = ?")->execute([$pageId]);
            $this->db->prepare("DELETE FROM cms_pages WHERE id = ? AND site_id = ? AND status = 'draft'")->execute([$pageId, $this->siteId]);
        } catch (Throwable $e) { error_log('MiaPagePublisher deletePage: ' . $e->getMessage()); }
    }

    /** @return array{ok:bool, code?:string, error?:string, execution_id?:int, status?:string} */
    public function rollback(int $executionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $s = $this->db->prepare("SELECT * FROM mia_executions WHERE id = ? AND site_id = ? AND connector = 'cms_article'");
        $s->execute([$executionId, $this->siteId]);
        $x = $s->fetch(PDO::FETCH_ASSOC);
        if (!$x) { return $this->fail('not_found', 'No such change'); }
        if (!in_array($x['status'], ['applied', 'verified', 'unverified'], true)) { return $this->fail('not_undoable', 'That change is already ' . $x['status'] . '.'); }
        $snap = json_decode((string)$x['after_json'], true);
        $pageId = (int)preg_replace('/^cms_pages:/', '', (string)$x['target_ref']);
        if (!is_array($snap) || $pageId <= 0) { return $this->fail('no_snapshot', 'The saved copy of the page is missing, so it cannot be removed here.'); }
        try {
            $this->db->beginTransaction();
            $cur = $this->db->prepare("SELECT status, title, meta_description FROM cms_pages WHERE id = ? AND site_id = ?" . ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $cur->execute([$pageId, $this->siteId]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $body = (new ArticleService($this->db, $this->siteId))->body($pageId);
                if ($row['status'] !== 'draft') { throw new MiaPublishConflict('The page has been published, so it was not removed. Unpublish or delete it in the website editor.'); }
                if ((string)$row['title'] !== (string)$snap['title'] || (string)$row['meta_description'] !== (string)$snap['meta_description'] || sha1($body) !== (string)$snap['body_sha1']) {
                    throw new MiaPublishConflict('The page was edited after Mia made it, so it was not removed. Delete it in the website editor if you do not want it.');
                }
                $this->db->prepare("DELETE FROM cms_blocks WHERE page_id = ?")->execute([$pageId]);
                $del = $this->db->prepare("DELETE FROM cms_pages WHERE id = ? AND site_id = ? AND status = 'draft'");
                $del->execute([$pageId, $this->siteId]);
                if ($del->rowCount() !== 1) { throw new MiaPublishConflict('The page just changed, so it was not removed.'); }
            }
            $u = $this->db->prepare("UPDATE mia_executions SET status = 'rolled_back', rolled_back_by = ?, rolled_back_at = ? WHERE id = ? AND site_id = ? AND status IN ('applied','verified','unverified')");
            $u->execute([$userId, $now->format('Y-m-d H:i:s'), $executionId, $this->siteId]);
            if ($u->rowCount() !== 1) { throw new MiaPublishConflict('That change was just undone by someone else.'); }
            $this->actions->markRolledBack((int)$x['action_id'], $userId, 'Undone: unpublished page removed (execution #' . $executionId . ')', $now);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaPagePublisher rollback: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not undo the change. Nothing was changed.');
        }
        return ['ok' => true, 'execution_id' => $executionId, 'status' => 'rolled_back'];
    }

    /** @return array[] newest first, with links to the page in the website editor while it exists */
    public function executionsFor(int $actionId): array
    {
        $s = $this->db->prepare("SELECT id, status, page_url, verify_note, applied_at, rolled_back_at, target_ref FROM mia_executions WHERE action_id = ? AND site_id = ? ORDER BY id DESC");
        $s->execute([$actionId, $this->siteId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $pid = (int)preg_replace('/^cms_pages:/', '', (string)$r['target_ref']);
            $live = in_array($r['status'], ['applied', 'verified', 'unverified'], true) && $pid > 0;
            $r['open_url'] = $live ? '/crm/cms/cms-page-edit.php?id=' . $pid : null;
            $r['edit_url'] = $r['open_url'];
            unset($r['target_ref']);
        }
        unset($r);
        return $rows;
    }
}
