<?php
/**
 * QuoteAcceptService — accept a quote the client signed and sent back (a PDF or an email saying yes), the one place
 * (2026-10-15: VML's 9 signed snow quotes were accepted by hand; Sam's "Looks like an acceptance" Accept uses this).
 *
 * acceptSigned(): a sent / viewed quote → accepted by the signer on the email's date; status change tracked, activity
 * logged, a note on the quote, the signed copy filed (quote_documents, migration 1366), Sam's approval summary
 * (QuoteApprovalService — a tray job, or "contract: set up from the contract"), and a seasonal snow contract set up
 * (SnowContractService). One accept at a time per quote; a second one answers "already accepted".
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QuoteService.php';

class QuoteAcceptService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        if (!function_exists('trackFieldChange')) require_once APP_ROOT . '/Services/CrmFunctions.php';
    }

    /** Signed copies filed on a quote, oldest first: [{attachment_id, filename, note, created_at}]. */
    public function documents(int $quoteId): array
    {
        try {
            $s = $this->db->prepare("SELECT d.attachment_id, d.note, d.created_at, a.filename FROM quote_documents d
                                     JOIN inbound_message_attachments a ON a.id = d.attachment_id WHERE d.quote_id = ? ORDER BY d.id");
            $s->execute([$quoteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];   // before migration 1366
        }
    }

    /** @return array{ok: bool, message: string, status?: string, contract?: ?string} */
    public function acceptSigned(int $quoteId, string $signer, string $signedAt, ?int $attachmentId, array $user, string $subject = ''): array
    {
        $lock = 'mw_quote_accept_' . $quoteId;
        $held = false;
        try { $g = $this->db->prepare("SELECT GET_LOCK(?, 15)"); $g->execute([$lock]); $held = (int)$g->fetchColumn() === 1; }
        catch (Throwable $e) { /* SQLite tests */ }
        if (!$held && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') return ['ok' => false, 'message' => 'Already accepting this quote — one moment.'];
        try {
            return $this->acceptLocked($quoteId, $signer, $signedAt, $attachmentId, $user, $subject);
        } finally {
            if ($held) $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }
    }

    private function acceptLocked(int $quoteId, string $signer, string $signedAt, ?int $attachmentId, array $user, string $subject): array
    {
        $s = $this->db->prepare("SELECT id, quote_number, status, is_contract FROM quotes WHERE id = ?");
        $s->execute([$quoteId]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q) return ['ok' => false, 'message' => 'Quote not found.'];
        if ($q['status'] === 'accepted') return ['ok' => false, 'status' => 'accepted', 'message' => $q['quote_number'] . ' is already accepted.'];
        if (!in_array($q['status'], ['sent', 'viewed'], true)) return ['ok' => false, 'message' => $q['quote_number'] . ' is ' . $q['status'] . ' — open it to decide.'];

        $userId = (int)($user['id'] ?? 0) ?: null;
        $when = strtotime($signedAt) ? date('Y-m-d H:i:s', strtotime($signedAt)) : date('Y-m-d H:i:s');
        $who = mb_substr(trim($signer) . ' (signed copy by email, ' . substr($when, 0, 10) . ')', 0, 250);
        $file = null;
        if ($attachmentId) {
            $f = $this->db->prepare("SELECT filename FROM inbound_message_attachments WHERE id = ?");
            $f->execute([$attachmentId]);
            $file = $f->fetchColumn() ?: null;
        }

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $svc = new QuoteService($this->db);
            $svc->approveVerbal($quoteId, $signer, (string)($user['name'] ?? ''), '');   // sets accepted + the pipeline stage
            $this->db->prepare("UPDATE quotes SET accepted_by_name = ?, accepted_at = ? WHERE id = ?")->execute([$who, $when, $quoteId]);
            trackFieldChange('quote', $quoteId, 'status', $q['status'], 'accepted', $userId);
            logActivityExtended($userId, 'Quote accepted (signed copy)', 'Accepted from ' . $who . ($file ? ' — ' . $file : '') . ($subject !== '' ? " — email '{$subject}'" : ''), null, null, $quoteId);
            if (function_exists('addQuoteNote')) {
                addQuoteNote($quoteId, 'Signed copy received by email from ' . trim($signer) . ' on ' . date('M j, Y', strtotime($when))
                    . ($file ? ': "' . $file . '"' : '') . ($subject !== '' ? " (subject '{$subject}')" : '') . '.', 'general', $userId);
            }
            if ($attachmentId) {
                try {
                    $this->db->prepare("INSERT IGNORE INTO quote_documents (quote_id, kind, attachment_id, note, added_by, created_at) VALUES (?, 'signed', ?, ?, ?, NOW())")
                       ->execute([$quoteId, $attachmentId, mb_substr('From ' . trim($signer), 0, 250), $userId]);
                } catch (Throwable $e) { error_log('Quote document (' . $quoteId . '): ' . $e->getMessage()); }   // before migration 1366
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        // After the yes (each step logs its own failure; the acceptance stands).
        $contract = null;
        try {
            require_once dirname(__DIR__, 2) . '/Sales/Services/QuoteApprovalService.php';
            (new QuoteApprovalService($this->db))->afterApproval($quoteId);
        } catch (Throwable $e) { error_log('[quote accept] approval ' . $quoteId . ': ' . $e->getMessage()); }
        if (!empty($q['is_contract'])) {
            try {
                if (defined('CRM_INCLUDES')) { require_once CRM_INCLUDES . '/functions.php'; require_once CRM_INCLUDES . '/plan-functions.php'; }
                require_once dirname(__DIR__, 2) . '/Contracts/Services/SnowContractService.php';
                $r = (new SnowContractService($this->db))->setupFromSignedQuote($quoteId);
                if (!empty($r['contract_id'])) {
                    $c = $this->db->prepare("SELECT contract_number FROM contracts WHERE id = ?");
                    $c->execute([(int)$r['contract_id']]);
                    $contract = $c->fetchColumn() ?: null;
                }
            } catch (Throwable $e) { error_log('[quote accept] snow setup ' . $quoteId . ': ' . $e->getMessage()); }
        }
        return ['ok' => true, 'status' => 'accepted', 'contract' => $contract,
                'message' => $q['quote_number'] . ' accepted' . ($contract ? " — contract {$contract} set up" : '') . '.'];
    }
}
