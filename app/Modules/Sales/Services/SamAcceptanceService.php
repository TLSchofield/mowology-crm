<?php
/**
 * SamAcceptanceService — a client email that looks like it accepts quotes becomes a "Looks like an acceptance" item
 * on Sam's desk (2026-10-15: VML's Alena returned 9 signed quotes; Sam kept chasing them).
 * Plan: docs/plans/2026-10-15-signed-quotes-from-email.md. Migration 1366.
 *
 * detect($messageKey) — after the readers keep an inbound email (and its files):
 *   signal   words (signed, approved, accepted, authorised, go ahead, proceed) in the subject or text, or a file named
 *            "…SIGNED…" / "…APPROVED…";
 *   quotes   the sender's open quotes (sent / viewed): the sender is the quote's contact, or works at the quote's
 *            company, or writes from the same organisation's mail domain (vml.ca ↔ vml.bc.ca; free mail never counts);
 *   match    a QUO-YYYY-NNNN in the text or a file name, or a 3–5 digit number in a file name equal to a candidate's
 *            number ("SNOW 0060" → QUO-2026-0060, that file filed as its signed copy); with no number at all and one
 *            open quote, that one.
 * Nothing is accepted here. Tim taps Accept (QuoteAcceptService::acceptSigned) or Not this one.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SamAcceptanceService
{
    private const WORDS = '/\b(signed|approved|accepted|accept|authori[sz]ed|go ahead|proceed)\b/i';
    private const FILE_WORDS = '/(signed|approved|accepted)/i';
    /** Mail providers: a shared domain says nothing about who someone works for. */
    private const FREE_MAIL = ['gmail', 'hotmail', 'outlook', 'live', 'yahoo', 'icloud', 'me', 'shaw', 'telus', 'msn', 'aol', 'protonmail', 'mac'];
    private const TWO_PART_SUFFIX = ['bc.ca', 'ab.ca', 'on.ca', 'qc.ca', 'mb.ca', 'sk.ca', 'ns.ca', 'nb.ca', 'co.uk', 'com.au', 'co.nz'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM sam_acceptances LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;   // migration 1366 not run
        }
    }

    // ── Pure ──────────────────────────────────────────────────────────────────

    /**
     * The organisation behind a mail address: its registrable domain, with a Canadian provincial domain folded into
     * .ca ("alena@vml.ca" and "rharvie@vml.bc.ca" → "vml.ca"). acme.com and acme.net stay apart (Codex review
     * 2026-10-15). Free mail → null. Pure.
     */
    public static function orgOf(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));
        if (!preg_match('/@([a-z0-9.-]+\.[a-z]{2,})$/', $email, $m)) return null;
        $host = preg_replace('/^(mail|email|www)\./', '', $m[1]);
        $tld = null;
        foreach (self::TWO_PART_SUFFIX as $sfx) {
            if (str_ends_with($host, '.' . $sfx)) {
                $host = substr($host, 0, -strlen($sfx) - 1);
                $tld = str_ends_with($sfx, '.ca') ? 'ca' : $sfx;   // a province's .ca is the same organisation's .ca
                break;
            }
        }
        $parts = explode('.', $host);
        if ($tld === null) {
            if (count($parts) < 2) return null;
            $tld = array_pop($parts);
        }
        $label = (string)end($parts);
        return ($label === '' || in_array($label, self::FREE_MAIL, true)) ? null : $label . '.' . $tld;
    }

    /** Does the email read like an acceptance? Pure. */
    public static function hasSignal(string $subject, string $text, array $filenames): bool
    {
        if (preg_match(self::WORDS, $subject . "\n" . $text)) return true;
        foreach ($filenames as $f) if (preg_match(self::FILE_WORDS, (string)$f)) return true;
        return false;
    }

    /**
     * Match quotes. @param list<array{id:int, number:string}> $candidates  @param array<int,string> $files  attachment id => filename
     * @return list<array{quote_id:int, attachment_id:?int, evidence:string}>  Pure.
     */
    public static function match(array $candidates, string $subject, string $text, array $files): array
    {
        $byNumber = [];
        foreach ($candidates as $c) $byNumber[strtoupper($c['number'])] = (int)$c['id'];
        $out = [];
        $add = function (int $qid, ?int $att, string $why) use (&$out) {
            if (!isset($out[$qid]) || ($att !== null && $out[$qid]['attachment_id'] === null)) {
                $out[$qid] = ['quote_id' => $qid, 'attachment_id' => $att, 'evidence' => mb_substr($why, 0, 250)];
            }
        };
        // Full quote numbers anywhere.
        foreach (array_merge([$subject, $text], array_values($files)) as $i => $t) {
            if (preg_match_all('/\bQUO-\d{4}-\d{3,5}\b/i', (string)$t, $m)) {
                foreach ($m[0] as $n) {
                    $qid = $byNumber[strtoupper($n)] ?? null;
                    if ($qid) $add($qid, null, strtoupper($n) . ' in the email');
                }
            }
        }
        // Each file: its full number, or a short number that ends one candidate's number ("SNOW 0060").
        foreach ($files as $attId => $name) {
            $hit = null;
            if (preg_match('/\bQUO-\d{4}-\d{3,5}\b/i', (string)$name, $m)) $hit = $byNumber[strtoupper($m[0])] ?? null;
            if ($hit === null && preg_match_all('/(?<!\d)(\d{3,5})(?!\d)/', (string)$name, $m)) {
                foreach ($m[1] as $num) {
                    if (preg_match('/^(19|20)\d{2}$/', $num)) continue;   // a year ("Signed 2026.pdf"), not a quote (Codex review)
                    $found = array_values(array_filter($candidates, fn($c) => preg_match('/-0*' . ltrim($num, '0') . '$/', $c['number'])
                                                                              && strlen(ltrim($num, '0')) >= 2));
                    if (count($found) === 1) { $hit = (int)$found[0]['id']; break; }
                }
            }
            if ($hit !== null) $add($hit, (int)$attId, (string)$name);
        }
        // No number at all, one open quote: that one.
        if (!$out && count($candidates) === 1) $add((int)$candidates[0]['id'], $files ? (int)array_key_first($files) : null, 'the only open quote');
        return array_values($out);
    }

    // ── Detect ────────────────────────────────────────────────────────────────

    /** @return int acceptance items added */
    public function detect(string $messageKey): int
    {
        if (!$this->ready()) return 0;
        $s = $this->db->prepare("SELECT message_key, contact_id, from_addr, subject, snippet FROM sales_messages WHERE message_key = ? AND direction = 'inbound' LIMIT 1");
        $s->execute([$messageKey]);
        $m = $s->fetch(PDO::FETCH_ASSOC);
        if (!$m) return 0;
        $files = [];
        $a = $this->db->prepare("SELECT id, filename FROM inbound_message_attachments WHERE message_key = ? ORDER BY id");
        try { $a->execute([$messageKey]); foreach ($a->fetchAll(PDO::FETCH_ASSOC) as $r) $files[(int)$r['id']] = (string)$r['filename']; }
        catch (Throwable $e) { /* before migration 1283 */ }
        if (!self::hasSignal((string)$m['subject'], (string)$m['snippet'], array_values($files))) return 0;

        $matches = self::match($this->candidates((int)($m['contact_id'] ?? 0), (string)$m['from_addr']), (string)$m['subject'], (string)$m['snippet'], $files);
        $ins = $this->db->prepare("INSERT IGNORE INTO sam_acceptances (message_key, quote_id, attachment_id, evidence, status, created_at)
                                   VALUES (?, ?, ?, ?, 'open', NOW())");
        $upd = $this->db->prepare("UPDATE sam_acceptances SET attachment_id = ? WHERE message_key = ? AND quote_id = ? AND attachment_id IS NULL AND status = 'open'");
        $n = 0;
        foreach ($matches as $x) {
            $ins->execute([$messageKey, $x['quote_id'], $x['attachment_id'], $x['evidence']]);
            if ($ins->rowCount() > 0) { $n++; continue; }
            if ($x['attachment_id'] !== null) $upd->execute([$x['attachment_id'], $messageKey, $x['quote_id']]);   // its file arrived later
        }
        return $n;
    }

    /** The sender's open quotes: their own, their company's, or their organisation's (same mail domain). */
    public function candidates(int $contactId, string $fromAddr): array
    {
        $email = '';
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fromAddr, $em)) $email = strtolower($em[0]);
        $companyId = 0;
        if ($contactId) {
            $c = $this->db->prepare("SELECT company_id FROM contacts WHERE id = ?");
            $c->execute([$contactId]);
            $companyId = (int)$c->fetchColumn();
        }
        $org = self::orgOf($email);
        $rows = $this->db->query("
            SELECT q.id, q.quote_number AS number, q.company_id, COALESCE(q.contact_id, p.site_contact_id) AS contact_id,
                   ct.email AS contact_email, ct.company_id AS contact_company, co.billing_email
            FROM quotes q
            LEFT JOIN properties p ON p.id = q.property_id
            LEFT JOIN contacts ct ON ct.id = COALESCE(q.contact_id, p.site_contact_id)
            LEFT JOIN companies co ON co.id = q.company_id
            WHERE q.status IN ('sent', 'viewed')
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $mine = ($contactId && (int)$r['contact_id'] === $contactId)
                 || ($companyId && ((int)$r['company_id'] === $companyId || (int)$r['contact_company'] === $companyId))
                 || ($org !== null && (self::orgOf($r['contact_email']) === $org || self::orgOf($r['billing_email']) === $org));
            if ($mine) $out[] = ['id' => (int)$r['id'], 'number' => (string)$r['number']];
        }
        return $out;
    }

    // ── Sam's desk ────────────────────────────────────────────────────────────

    /** Open items grouped by email, newest first. */
    public function open(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        $rows = $this->db->query("
            SELECT a.id, a.message_key, a.quote_id, a.attachment_id, a.evidence, a.created_at,
                   m.from_name, m.from_addr, m.subject, m.sent_at,
                   q.quote_number, q.title, q.amount, q.status AS quote_status, p.address,
                   att.filename
            FROM sam_acceptances a
            JOIN quotes q ON q.id = a.quote_id
            LEFT JOIN properties p ON p.id = q.property_id
            LEFT JOIN sales_messages m ON m.message_key = a.message_key
            LEFT JOIN inbound_message_attachments att ON att.id = a.attachment_id
            WHERE a.status = 'open'
            ORDER BY m.sent_at DESC, q.quote_number
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $r) {
            $k = (string)$r['message_key'];
            if (!isset($groups[$k])) {
                if (count($groups) >= $limit) continue;
                $groups[$k] = ['message_key' => $k, 'from' => (string)($r['from_name'] ?: $r['from_addr']), 'subject' => (string)$r['subject'],
                               'sent_at' => $r['sent_at'], 'quotes' => []];
            }
            $groups[$k]['quotes'][] = [
                'id' => (int)$r['id'], 'quote_id' => (int)$r['quote_id'], 'number' => (string)$r['quote_number'],
                'title' => (string)($r['title'] ?? ''), 'address' => (string)($r['address'] ?? ''), 'amount' => (float)$r['amount'],
                'still_open' => in_array($r['quote_status'], ['sent', 'viewed'], true),
                'file' => $r['attachment_id'] ? ['id' => (int)$r['attachment_id'], 'name' => (string)$r['filename']] : null,
                'evidence' => (string)$r['evidence'],
            ];
        }
        return array_values($groups);
    }

    /** Tim's Accept on one item. */
    public function accept(int $id, array $user): array
    {
        $r = $this->row($id);
        if (!$r || $r['status'] !== 'open') return ['ok' => false, 'message' => 'Already decided.'];
        $who = trim((string)($r['from_name'] ?: $r['from_addr']));
        require_once dirname(__DIR__, 2) . '/Quotes/Services/QuoteAcceptService.php';
        $res = (new QuoteAcceptService($this->db))->acceptSigned((int)$r['quote_id'], $who, (string)($r['sent_at'] ?: date('Y-m-d H:i:s')),
            $r['attachment_id'] ? (int)$r['attachment_id'] : null, $user, (string)$r['subject']);
        if ($res['ok'] || ($res['status'] ?? '') === 'accepted') {
            $this->db->prepare("UPDATE sam_acceptances SET status = 'accepted', decided_by = ?, decided_at = NOW() WHERE id = ?")
               ->execute([(int)($user['id'] ?? 0) ?: null, $id]);
        }
        return $res;
    }

    /** Tim's "Not this one". */
    public function dismiss(int $id, array $user): array
    {
        $s = $this->db->prepare("UPDATE sam_acceptances SET status = 'dismissed', decided_by = ?, decided_at = NOW() WHERE id = ? AND status = 'open'");
        $s->execute([(int)($user['id'] ?? 0) ?: null, $id]);
        return ['ok' => $s->rowCount() > 0, 'message' => $s->rowCount() > 0 ? 'OK — not that one.' : 'Already decided.'];
    }

    private function row(int $id): ?array
    {
        $s = $this->db->prepare("SELECT a.*, m.from_name, m.from_addr, m.subject, m.sent_at FROM sam_acceptances a
                                 LEFT JOIN sales_messages m ON m.message_key = a.message_key WHERE a.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
