<?php
/**
 * MiaStoryPublisher — turns an APPROVED project story into an unpublished portfolio card (Phase 2.5, 2026-10-26).
 *
 * It creates one portfolio_projects row with status 'draft'. Drafts are not shown on the public portfolio page: the
 * owner reads the card in the CRM's Portfolio page and publishes it there, or never. Nothing Mia does makes it public.
 *
 * Rules (each has a test):
 *   - only an approved project_story whose approval hash matches the wording as it stands now (verifyApproval);
 *   - the photo pair is checked again: still approved, consent covered, visit completed, with a web photo;
 *   - one card per action: a second Apply is refused while one exists;
 *   - Undo deletes the card only if it is still a draft and still says what Mia wrote; once the owner has edited or
 *     published it, Undo refuses and says where to go;
 *   - every apply and undo is a row in mia_executions (connector 'portfolio') and an audit event.
 * It has the same four methods as MiaPublishService (preview, apply, rollback, executionsFor) so the API layer treats them alike.
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/MiaActionService.php';
require_once __DIR__ . '/MiaRecommendationWriter.php';
require_once __DIR__ . '/MiaFeeds.php';
require_once __DIR__ . '/MiaPublishService.php';      // MiaPublishConflict

final class MiaStoryPublisher
{
    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaActionService */
    private $actions;

    public function __construct(PDO $db, int $siteId, MiaActionService $actions)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->actions = $actions;
    }

    private function fail(string $code, string $error): array { return ['ok' => false, 'code' => $code, 'error' => $error]; }

    /** @return array{ok:bool,...} */
    private function load(int $actionId, bool $mustBeApproved): array
    {
        $a = $this->actions->get($actionId);
        if (!$a) { return $this->fail('not_found', 'No such action'); }
        if ($a['type'] !== 'project_story') { return $this->fail('not_supported', 'This is not a project story.'); }
        if ($mustBeApproved && $a['status'] !== 'approved') { return $this->fail('not_approved', 'Approve it first. It is ' . $a['status'] . '.'); }
        if ($a['draft_ref_table'] !== 'mia_content_drafts' || empty($a['draft_ref_id'])) { return $this->fail('no_draft', 'There is no wording for this story yet.'); }
        $s = $this->db->prepare("SELECT * FROM mia_content_drafts WHERE id = ? AND site_id = ? AND action_id = ? AND kind = 'story' AND status = 'draft'");
        $s->execute([(int)$a['draft_ref_id'], $this->siteId, $actionId]);
        $d = $s->fetch(PDO::FETCH_ASSOC);
        if (!$d) { return $this->fail('no_draft', 'The wording for this story is no longer available.'); }
        if (trim((string)$d['title']) === '' || trim((string)$d['meta_desc']) === '') { return $this->fail('no_draft', 'The wording is incomplete.'); }
        $pairId = (int)preg_replace('/^portfolio:pair:/', '', (string)$d['page_url']);
        $pair = $this->usablePair($pairId, false);
        $why = null; $can = true;
        if (!$pair) { $can = false; $why = 'The photos are no longer approved with consent covered, so no card can be made.'; }
        elseif (!self::safeImage((string)$pair['web_after_path'])) { $can = false; $why = 'The approved pair has no web photo yet.'; }
        elseif ($this->liveExecution($actionId)) { $can = false; $why = 'A card for this story already exists. Open the Portfolio page to publish it.'; }
        return ['ok' => true, 'action' => $a, 'draft' => $d, 'pair' => $pair, 'can_apply' => $can, 'why_not' => $why];
    }

    /** The pair, only if it is still approved with consent covered on a completed visit. $lock takes a row lock (MySQL, inside a transaction). */
    private function usablePair(int $pairId, bool $lock): ?array
    {
        $in = implode(',', array_fill(0, count(MiaFeeds::CONSENT_OK), '?'));
        $p = $this->db->prepare("SELECT p.id, p.area, p.category, p.web_before_path, p.web_after_path FROM ba_pairs p JOIN job_visits v ON v.id = p.visit_id
                                  WHERE p.id = ? AND p.status = 'approved' AND p.consent_state IN ($in) AND v.status = 'completed'" . ($lock && $this->mysql() ? ' FOR UPDATE' : ''));
        $p->execute(array_merge([$pairId], MiaFeeds::CONSENT_OK));
        $r = $p->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function mysql(): bool { return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }

    /** A site-relative web image from the media folder, nothing else. */
    public static function safeImage(string $path): bool
    {
        return (bool)preg_match('#^/(?:_media|assets)/[A-Za-z0-9/_\-.]{1,200}\.(?:jpe?g|png|webp)$#', $path) && strpos($path, '..') === false;
    }

    private function liveExecution(int $actionId): ?array
    {
        $s = $this->db->prepare("SELECT * FROM mia_executions WHERE action_id = ? AND site_id = ? AND connector = 'portfolio' AND status IN ('applied','verified','unverified') ORDER BY id DESC LIMIT 1");
        $s->execute([$actionId, $this->siteId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function preview(int $actionId): array
    {
        $ctx = $this->load($actionId, false);
        if (!$ctx['ok']) { return $ctx; }
        $d = $ctx['draft'];
        return ['ok' => true, 'page_url' => 'Portfolio (saved as a draft, not public)', 'can_apply' => $ctx['can_apply'], 'why_not' => $ctx['why_not'],
                'before' => ['title' => null, 'description' => null], 'after' => ['title' => $d['title'], 'description' => $d['meta_desc']]];
    }

    /** @return array{ok:bool, code?:string, error?:string, execution_id?:int, status?:string, note?:string} */
    public function apply(int $actionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $ctx = $this->load($actionId, true);
        if (!$ctx['ok']) { return $ctx; }
        $d = $ctx['draft'];
        $v = $this->actions->verifyApproval($actionId, MiaRecommendationWriter::draftText($d['title'], $d['meta_desc']), $now);
        if (empty($v['ok'])) { return $this->fail($v['code'] ?? 'not_approved', $v['error'] ?? 'Not approved'); }
        if (!$ctx['can_apply']) { return $this->fail('cannot_apply', (string)$ctx['why_not']); }

        $pair = $ctx['pair'];
        $images = [(string)$pair['web_after_path']];
        if (self::safeImage((string)$pair['web_before_path'])) { $images[] = (string)$pair['web_before_path']; }
        $cat = strtolower(trim((string)$pair['category']));
        $row = [
            'project_name' => trim((string)$d['title']), 'description' => trim((string)$d['meta_desc']), 'location' => mb_substr(trim((string)$pair['area']), 0, 255),
            'categories' => json_encode($cat !== '' ? [ucfirst($cat)] : []), 'gallery_images' => json_encode($images, JSON_UNESCAPED_SLASHES),
            // The Portfolio view page shows these two (the public page prefers the gallery, which starts with the same after photo).
            'after_image_path' => $images[0], 'before_image_path' => $images[1] ?? null,
        ];
        try {
            $this->db->beginTransaction();
            // Consent and approval can change between the check above and now: look again, holding the row, before anything is created.
            if ($this->usablePair((int)$pair['id'], true) === null) { throw new MiaPublishConflict('The photos are no longer approved with consent covered, so no card was made.'); }
            $pid = 0; $number = '';
            for ($try = 0; $try < 3 && $pid === 0; $try++) {          // two applies at once can pick the same number: the unique key refuses one, so take the next
                $number = $this->nextNumber($now);
                try {
                    $this->db->prepare("INSERT INTO portfolio_projects (project_number, project_name, description, location, status, featured, display_order, categories, tags, gallery_images, after_image_path, before_image_path, created_by, created_at)
                                        VALUES (?, ?, ?, ?, 'draft', 0, 999, ?, '[]', ?, ?, ?, ?, ?)")
                        ->execute([$number, $row['project_name'], $row['description'], $row['location'], $row['categories'], $row['gallery_images'], $row['after_image_path'], $row['before_image_path'], $userId, $now->format('Y-m-d H:i:s')]);
                    $pid = (int)$this->db->lastInsertId();
                } catch (PDOException $dup) {
                    if ($try === 2 || strpos($dup->getMessage(), 'project_number') === false && $dup->getCode() !== '23000') { throw $dup; }
                }
            }
            $row['featured'] = 0; $row['display_order'] = 999; $row['tags'] = '[]';
            $this->db->prepare("INSERT INTO mia_executions (site_id, action_id, draft_id, website_id, connector, target_ref, page_url, before_json, after_json, status, verify_note, applied_by, applied_at)
                                VALUES (?, ?, ?, ?, 'portfolio', ?, ?, ?, ?, 'applied', ?, ?, ?)")
                ->execute([$this->siteId, $actionId, (int)$d['id'], (int)$d['website_id'], 'portfolio_projects:' . $pid, 'Portfolio ' . $number, '{}', json_encode($row),
                           'Saved as a draft card. It is not public until you publish it in the Portfolio page.', $userId, $now->format('Y-m-d H:i:s')]);
            $execId = (int)$this->db->lastInsertId();
            $this->db->prepare("UPDATE mia_content_drafts SET status = 'applied' WHERE id = ? AND site_id = ?")->execute([(int)$d['id'], $this->siteId]);
            if (!$this->actions->markExecuted($actionId, $userId, 'Saved as draft portfolio card ' . $number . ' (execution #' . $execId . ')', $now)) {
                throw new MiaPublishConflict('This action was just changed by someone else. Nothing was created.');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaStoryPublisher apply: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not save the card. Nothing was created.');
        }
        return ['ok' => true, 'execution_id' => $execId, 'status' => 'applied', 'note' => 'Saved as a draft card ' . $number . '. It is not public: open the Portfolio page to read it and publish it.'];
    }

    /** PORT-YYYY-NNNN, the same format the Portfolio page uses. The unique key on project_number is the backstop. */
    private function nextNumber(DateTimeImmutable $now): string
    {
        $year = $now->format('Y');
        $s = $this->db->prepare("SELECT MAX(CAST(SUBSTR(project_number, 11) AS SIGNED)) FROM portfolio_projects WHERE project_number LIKE ?");
        $s->execute(['PORT-' . $year . '-%']);
        return sprintf('PORT-%s-%04d', $year, (int)$s->fetchColumn() + 1);
    }

    /** @return array{ok:bool, code?:string, error?:string, execution_id?:int, status?:string} */
    public function rollback(int $executionId, int $userId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $s = $this->db->prepare("SELECT * FROM mia_executions WHERE id = ? AND site_id = ? AND connector = 'portfolio'");
        $s->execute([$executionId, $this->siteId]);
        $x = $s->fetch(PDO::FETCH_ASSOC);
        if (!$x) { return $this->fail('not_found', 'No such change'); }
        if (!in_array($x['status'], ['applied', 'verified', 'unverified'], true)) { return $this->fail('not_undoable', 'That change is already ' . $x['status'] . '.'); }
        $after = json_decode((string)$x['after_json'], true);
        $pid = (int)preg_replace('/^portfolio_projects:/', '', (string)$x['target_ref']);
        if (!is_array($after) || $pid <= 0) { return $this->fail('no_snapshot', 'The saved copy of the card is missing, so it cannot be removed here.'); }
        try {
            $this->db->beginTransaction();
            // The row is locked while it is compared and deleted (MySQL), so an edit cannot slip in between.
            $c = $this->db->prepare("SELECT status, project_name, description, location, categories, tags, featured, display_order, gallery_images, after_image_path, before_image_path FROM portfolio_projects WHERE id = ?" . ($this->mysql() ? ' FOR UPDATE' : ''));
            $c->execute([$pid]);
            $cur = $c->fetch(PDO::FETCH_ASSOC);
            if ($cur !== false) {
                if ($cur['status'] !== 'draft') { throw new MiaPublishConflict('The card has been published, so it was not removed. Unpublish or delete it in the Portfolio page.'); }
                foreach (['project_name', 'description', 'location'] as $f) {
                    if ((string)$cur[$f] !== (string)$after[$f]) { throw new MiaPublishConflict('The card was edited after Mia made it, so it was not removed. Delete it in the Portfolio page if you do not want it.'); }
                }
                foreach (['categories', 'tags', 'gallery_images'] as $f) {       // JSON columns: compare the data, not MySQL's spacing
                    if (json_decode((string)$cur[$f], true) !== json_decode((string)($after[$f] ?? '[]'), true)) { throw new MiaPublishConflict('The card was edited after Mia made it, so it was not removed. Delete it in the Portfolio page if you do not want it.'); }
                }
                foreach (['after_image_path', 'before_image_path'] as $f) {      // cards made before these were written do not have them in the snapshot
                    if (array_key_exists($f, $after) && (string)$cur[$f] !== (string)$after[$f]) { throw new MiaPublishConflict('The card was edited after Mia made it, so it was not removed. Delete it in the Portfolio page if you do not want it.'); }
                }
                if ((int)$cur['featured'] !== (int)($after['featured'] ?? 0) || (int)$cur['display_order'] !== (int)($after['display_order'] ?? 999)) {
                    throw new MiaPublishConflict('The card was edited after Mia made it, so it was not removed. Delete it in the Portfolio page if you do not want it.');
                }
                $del = $this->db->prepare("DELETE FROM portfolio_projects WHERE id = ? AND status = 'draft'");
                $del->execute([$pid]);
                if ($del->rowCount() !== 1) { throw new MiaPublishConflict('The card just changed, so it was not removed.'); }
            }   // already gone: nothing to delete, the undo is still recorded
            $u = $this->db->prepare("UPDATE mia_executions SET status = 'rolled_back', rolled_back_by = ?, rolled_back_at = ? WHERE id = ? AND site_id = ? AND status IN ('applied','verified','unverified')");
            $u->execute([$userId, $now->format('Y-m-d H:i:s'), $executionId, $this->siteId]);
            if ($u->rowCount() !== 1) { throw new MiaPublishConflict('That change was just undone by someone else.'); }
            $this->actions->markRolledBack((int)$x['action_id'], $userId, 'Undone: draft card removed (execution #' . $executionId . ')', $now);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof MiaPublishConflict) { return $this->fail('conflict', $e->getMessage()); }
            error_log('MiaStoryPublisher rollback: ' . get_class($e) . ' ' . $e->getMessage());
            return $this->fail('db', 'Mia could not undo the change. Nothing was changed.');
        }
        return ['ok' => true, 'execution_id' => $executionId, 'status' => 'rolled_back'];
    }

    /** @return array[] newest first */
    public function executionsFor(int $actionId): array
    {
        $s = $this->db->prepare("SELECT id, status, page_url, verify_note, applied_at, rolled_back_at, target_ref FROM mia_executions WHERE action_id = ? AND site_id = ? ORDER BY id DESC");
        $s->execute([$actionId, $this->siteId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $pid = (int)preg_replace('/^portfolio_projects:/', '', (string)$r['target_ref']);
            // Where the owner reads, edits and publishes the card: the CRM's own Portfolio pages. A card that is gone has no link.
            $live = in_array($r['status'], ['applied', 'verified', 'unverified'], true) && $pid > 0;
            $r['open_url'] = $live ? '/crm/portfolio/view.php?id=' . $pid : null;
            $r['edit_url'] = $live ? '/crm/portfolio/edit.php?id=' . $pid : null;
            unset($r['target_ref']);
        }
        unset($r);
        return $rows;
    }
}
