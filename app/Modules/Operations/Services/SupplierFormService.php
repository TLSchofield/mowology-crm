<?php
/**
 * SupplierFormService — a supplier's account application, filled by Otto, sent on the owner's
 * yes (owner, 2026-10-10; plan docs/plans/2026-10-10-supplier-account-forms.md).
 *
 *   Yui (supplier_inbox_poll) finds a fillable PDF in mail from a directory supplier → capture():
 *   the form is filled from the company profile (CompanyProfileService + PdfFormFiller), the
 *   original and filled copies are kept in STORAGE_ROOT/supplier-forms, and Otto shows it as a
 *   suggestion (kind 'supplier_form') with the answers, the blanks and who it goes to.
 *   "Send it" → send(): a reply to the supplier (their other recipients copied — e.g. their
 *   accounting), with the filled form and, when the form asks for it, the business licence.
 *   Nothing is ever sent without that tap.
 *
 * Never filled: bank details, credit references, guarantees, signatures, personal ID
 * (CompanyProfileService 'yours') — such a form is shown as needing the owner.
 * A supplier we applied to that hasn't written back in 7 days → Otto 'supplier_chase'.
 *
 * Global-namespace service: require_once the file. Pure rules are static + tested.
 */
declare(strict_types=1);

require_once __DIR__ . '/CompanyProfileService.php';
require_once __DIR__ . '/CompanyDocumentService.php';
require_once __DIR__ . '/SupplierDirectoryService.php';
if (!class_exists('PdfFormFiller')) require_once dirname(__DIR__, 3) . '/Services/Pdf/PdfFormFiller.php';

class SupplierFormService
{
    public const CHASE_DAYS = 7;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** A fillable PDF that reads as an account / credit / customer application. */
    public static function looksLikeForm(string $fileName, string $subject, string $body, int $fieldCount): bool
    {
        if ($fieldCount <= 0) return false;
        if (preg_match('/\b(invoice|statement|receipt|quote|estimate|packing slip|price ?list|catalog(ue)?)\b/i', $fileName)) return false;
        // The file name or subject may say it in a word ("Credit Application.pdf", "Account form")…
        $name = $fileName . ' ' . $subject;
        if (preg_match('/\bapplication\b|\bacc(?:ou)?n?t\b|\bacc ?app|\bcredit\b|new customer|customer (?:info|set ?up)|\bset ?up\b|registration|\bforms?\b/i', $name)) return true;
        // …but the body only counts with a phrase: a lone "account", "applicable", "credit card"
        // or "platform" in a covering note made any fillable PDF read as an application (2026-10-12).
        if (preg_match('/\b(?:credit|account|customer)\s+(?:application|form|set ?up)\b|\bnew account\b|\bfill (?:it|this|the form|them) (?:out|in)\b/i', $body)) return true;
        return $fieldCount >= 5;
    }

    /** By its name or subject, an application — even when it is a flat PDF nobody can type into. */
    public static function looksLikeApplication(string $fileName, string $subject): bool
    {
        return (bool)preg_match('/applic|credit|new account|account (form|set ?up)/i', $fileName . ' ' . $subject)
            && !preg_match('/\b(invoice|statement|receipt)\b/i', $fileName);
    }

    /** What on a form only the owner can give — named so he knows before he opens it. */
    public static function ownerAsks(string $text): array
    {
        $t = ' ' . strtolower((string)preg_replace('/\s+/', ' ', $text)) . ' ';
        $asks = [
            'your SIN'             => '/\bs\.?i\.?n\.?\b|social insurance/',
            'a bank reference'     => '/bank reference|\bbank:|a\/c #|account #|transit/',
            'a credit card'        => '/credit card/',
            'trade references'     => '/trade references?/',
            'a personal guarantee' => '/guarantee|guarantor/',
            'a signature'          => '/signature|signed/',
        ];
        $out = [];
        foreach ($asks as $label => $re) if (preg_match($re, $t)) $out[] = $label;
        return $out;
    }

    /** The form or the email asks for a copy of the business licence. */
    public static function wantsLicence(string $text): bool
    {
        return (bool)preg_match('/business\s+licen[cs]e|copy of (your|the) licen[cs]e|licen[cs]e (copy|number)/i', $text);
    }

    /**
     * Who our reply goes to: the sender, with their other recipients copied — never ourselves.
     * With $supplier (a supplier_accounts row), the sender must be that supplier's address
     * (else `to` is '' and nothing may be sent) and only copies on the supplier's own domain
     * are kept: our filled credit application and business licence go to the supplier, never
     * to an address that merely appeared on the email (2026-10-12).
     * @return array{to: string, cc: string[]}
     */
    public static function recipients(string $from, string $toAndCc, array $ours, ?array $supplier = null): array
    {
        $ours = array_map('strtolower', $ours);
        $isOurs = function (string $a) use ($ours): bool {
            $a = strtolower($a);
            if (in_array($a, $ours, true)) return true;
            foreach ($ours as $o) if ($o !== '' && $o[0] === '@' && str_ends_with($a, $o)) return true;
            return false;
        };
        $to = SupplierDirectoryService::addressOf($from);
        if ($to === '') {
            preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $from, $f);
            $to = strtolower($f[0][0] ?? '');
        }
        if ($supplier !== null && !SupplierDirectoryService::senderMatches($to, $supplier)) {
            $to = '';
        }
        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $toAndCc, $m);
        $cc = [];
        foreach ($m[0] as $a) {
            $a = strtolower($a);
            if ($a === $to || $isOurs($a) || in_array($a, $cc, true)) continue;
            if ($supplier !== null && !SupplierDirectoryService::senderMatches($a, $supplier)) continue;
            $cc[] = $a;
        }
        return ['to' => $to, 'cc' => $to === '' ? [] : $cc];
    }

    /** The covering note. */
    public static function note(bool $withLicence, bool $newsletter, string $email, array $sign): string
    {
        $att = $withLicence ? 'our completed account application and a copy of our business licence'
                            : 'our completed account application';
        $use = 'Please use ' . htmlspecialchars($email) . ' for our account' . ($newsletter ? ' and your newsletter' : '') . '.';
        $lines = array_filter([$sign['contact'] ?? '', $sign['company'] ?? '', $sign['phone'] ?? '']);
        return '<p>Hello,</p><p>Please find ' . $att . ' attached. ' . $use . '</p><p>Thanks,<br>'
             . implode('<br>', array_map('htmlspecialchars', $lines)) . '</p>';
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM supplier_forms LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function dir(): string
    {
        $d = rtrim(STORAGE_ROOT, '/') . '/supplier-forms';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    public function get(int $id): ?array
    {
        $s = $this->db->prepare("SELECT f.*, s.name AS supplier_name FROM supplier_forms f JOIN supplier_accounts s ON s.id = f.supplier_id WHERE f.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * File a supplier's PDF if it is an account form. Returns the form id, null when it is not a
     * form (or already filed).
     * $msg: message_key, mailbox, from, to_cc, subject, body, received_at.
     */
    public function capture(array $supplier, array $msg, string $fileName, string $bytes): ?int
    {
        if (!$this->ready() || $bytes === '' || strncmp($bytes, '%PDF-', 5) !== 0) return null;
        $s = $this->db->prepare("SELECT id FROM supplier_forms WHERE message_key = ? AND original_name = ?");
        $s->execute([(string)$msg['message_key'], mb_substr($fileName, 0, 160)]);
        if ($s->fetchColumn()) return null;
        try {
            $filler = new PdfFormFiller($bytes);
            $fields = $filler->fields();
        } catch (Throwable $e) {
            $filler = null;
            $fields = [];
        }
        if ($filler === null || !self::looksLikeForm($fileName, (string)($msg['subject'] ?? ''), (string)($msg['body'] ?? ''), count($fields))) {
            // A flat (print-and-sign) application: nothing to fill, but the owner must know it came
            // and what it asks for (Foreshore's credit application, 2026-10-10: SIN, bank, card, guarantee).
            if (!self::looksLikeApplication($fileName, (string)($msg['subject'] ?? ''))) return null;
            return $this->record($supplier, $msg, $fileName, $bytes, null, ['answers' => [], 'blanks' => [],
                                 'yours' => self::ownerAsks(CompanyDocumentService::pdfText($bytes))], 'unfillable', null);
        }

        $profile = (new CompanyProfileService($this->db))->profile();
        $a = CompanyProfileService::answer($fields, $profile);
        $status = 'ready';
        $filled = null;
        $err = null;
        try {
            $filled = $filler->fill($a['answers']);
        } catch (Throwable $e) {
            $status = 'failed';
            $err = mb_substr($e->getMessage(), 0, 255);
        }
        self::dir();
        return $this->record($supplier, $msg, $fileName, $bytes, $filled, $a, $status, $err);
    }

    /** Keep the original (and filled) copy and the row Otto reads. */
    private function record(array $supplier, array $msg, string $fileName, string $bytes, ?string $filled, array $a, string $status, ?string $err): int
    {
        self::dir();
        $stamp = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $safe = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName);
        $orig = 'supplier-forms/' . $stamp . '-original-' . $safe;
        file_put_contents(rtrim(STORAGE_ROOT, '/') . '/' . $orig, $bytes);
        $filledRel = null;
        if ($filled !== null) {
            $filledRel = 'supplier-forms/' . $stamp . '-filled-' . $safe;
            file_put_contents(rtrim(STORAGE_ROOT, '/') . '/' . $filledRel, $filled);
        }
        $wants = self::wantsLicence((string)($msg['body'] ?? '') . ' ' . CompanyDocumentService::pdfText($bytes)) ? 1 : 0;
        $this->db->prepare("
            INSERT INTO supplier_forms (supplier_id, message_key, mailbox, from_addr, cc_addrs, subject, received_at,
                                        original_path, original_name, filled_path, answers_json, blanks_json, wants_licence, status, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            (int)$supplier['id'], (string)$msg['message_key'], (string)($msg['mailbox'] ?? ''), (string)$msg['from'],
            mb_substr((string)($msg['to_cc'] ?? ''), 0, 500), mb_substr((string)($msg['subject'] ?? ''), 0, 255),
            (string)($msg['received_at'] ?? date('Y-m-d H:i:s')), $orig, mb_substr($fileName, 0, 160), $filledRel,
            json_encode($a['answers']), json_encode(['blank' => $a['blanks'], 'yours' => $a['yours']]), $wants, $status, $err,
        ]);
        $id = (int)$this->db->lastInsertId();   // read before the next statement: an UPDATE resets it to 0
        (new SupplierDirectoryService($this->db))->heardFrom((int)$supplier['id'], (string)($msg['received_at'] ?? date('Y-m-d H:i:s')));
        return $id;
    }

    /** Otto's suggestions: forms ready to send, and applications nobody has answered. */
    public function ottoItems(string $today): array
    {
        if (!$this->ready()) return [];
        $out = [];
        $ours = self::ours();
        $dir = new SupplierDirectoryService($this->db);
        $docs = new CompanyDocumentService($this->db);
        foreach ($this->db->query("SELECT f.*, s.name AS supplier_name FROM supplier_forms f JOIN supplier_accounts s ON s.id = f.supplier_id WHERE f.status IN ('ready', 'unfillable') ORDER BY f.received_at")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($f['status'] === 'unfillable') {
                $asks = (json_decode((string)$f['blanks_json'], true) ?: [])['yours'] ?? [];
                $what = preg_match('/credit/i', $f['original_name'] . ' ' . $f['subject']) ? 'a credit application' : 'an account application';
                $out[] = [
                    'kind' => 'supplier_form', 'subject_type' => 'supplier_form', 'subject_id' => (int)$f['id'],
                    'for_date' => substr((string)$f['received_at'], 0, 10), 'priority' => 2,
                    'key' => 'otto:supplier_form:' . (int)$f['id'],
                    'text' => $f['supplier_name'] . ' sent ' . $what . ' — it needs you (a print-and-sign form, nothing to type into).',
                    'detail' => ($asks ? 'It asks for ' . self::listing($asks) . '. ' : '') . 'From ' . $f['from_addr'] . '.',
                    'url' => '/crm/suppliers/form-pdf.php?id=' . (int)$f['id'],
                    'since' => substr((string)$f['received_at'], 0, 10),
                    'propose' => ['buttons' => [['label' => "I'll handle it", 'choice' => 'dismiss', 'main' => true]]],
                ];
                continue;
            }
            $r = self::recipients((string)$f['from_addr'], (string)$f['cc_addrs'], $ours, $dir->get((int)$f['supplier_id']) ?? []);
            $b = json_decode((string)$f['blanks_json'], true) ?: [];
            $answers = json_decode((string)$f['answers_json'], true) ?: [];
            $licence = (int)$f['wants_licence'] === 1;
            $onFile = $licence ? $docs->current('business_licence') : null;
            $hasLicence = $licence && $docs->usable('business_licence', $today) !== null;
            $parts = [$r['to'] !== '' ? 'Goes to ' . $r['to'] . ($r['cc'] ? ' (copy to ' . implode(', ', $r['cc']) . ')' : '')
                                      : 'The sender (' . $f['from_addr'] . ') is not an address on file for ' . $f['supplier_name'] . ' — it will not be sent'];
            $parts[] = 'Attached: the filled form' . ($licence ? ($hasLicence ? ' + your business licence'
                : ($onFile ? ' — they want your business licence, and the one on file expired ' . date('M j', strtotime((string)$onFile['expires_on']))
                           : ' — they want your business licence, and none is on file')) : '');
            $lines = [];
            foreach ($answers as $k => $v) $lines[] = str_replace('_', ' ', (string)$k) . ': ' . ($v === true ? 'ticked' : (string)$v);
            if (!empty($b['blank'])) $parts[] = 'Left blank: ' . implode(', ', array_map(fn($x) => str_replace('_', ' ', $x), $b['blank']));
            if (!empty($b['yours'])) $parts[] = 'Yours to fill: ' . implode(', ', array_map(fn($x) => str_replace('_', ' ', $x), $b['yours']));
            $canSend = empty($b['yours']) && (!$licence || $hasLicence) && $r['to'] !== '';
            $out[] = [
                'kind' => 'supplier_form', 'subject_type' => 'supplier_form', 'subject_id' => (int)$f['id'],
                'for_date' => substr((string)$f['received_at'], 0, 10), 'priority' => 2,
                'key' => 'otto:supplier_form:' . (int)$f['id'],
                'text' => $f['supplier_name'] . ' sent an account application — ' . ($canSend ? 'filled in and ready to send.' : 'filled in, but it needs you first.'),
                'detail' => implode(' · ', $parts),
                'url' => '/crm/suppliers/form-pdf.php?id=' . (int)$f['id'],
                'since' => substr((string)$f['received_at'], 0, 10),
                'propose' => ['answers' => $lines, 'to' => $r['to'], 'cc' => $r['cc'],
                              'buttons' => $canSend ? [['label' => 'Send it', 'choice' => 'send', 'main' => true], ['label' => "I'll do it myself", 'choice' => 'dismiss', 'main' => false]]
                                                    : [['label' => "I'll do it myself", 'choice' => 'dismiss', 'main' => true]]],
            ];
        }
        try {
            $s = $this->db->prepare("
                SELECT * FROM supplier_accounts
                WHERE status = 'applied' AND applied_at IS NOT NULL AND applied_at < ?
                  AND (last_heard_at IS NULL OR last_heard_at < applied_at)
            ");
            $s->execute([date('Y-m-d H:i:s', strtotime($today . ' -' . self::CHASE_DAYS . ' days'))]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $out[] = [
                    'kind' => 'supplier_chase', 'subject_type' => 'supplier', 'subject_id' => (int)$a['id'],
                    'for_date' => substr((string)$a['applied_at'], 0, 10), 'priority' => 3,
                    'key' => 'otto:supplier_chase:' . (int)$a['id'],
                    'text' => $a['name'] . " hasn't answered our account application (sent " . date('M j', strtotime((string)$a['applied_at'])) . ').',
                    'detail' => 'A call or a one-line email usually moves it' . ($a['phone'] ? ' — ' . $a['phone'] : '') . '.',
                    'url' => null, 'since' => substr((string)$a['applied_at'], 0, 10),
                    'propose' => ['buttons' => [['label' => "I'll chase them", 'choice' => 'ack', 'main' => true],
                                                ['label' => 'Account is open', 'choice' => 'open', 'main' => false],
                                                ['label' => 'Forget it', 'choice' => 'dismiss', 'main' => false]]],
                ];
            }
        } catch (Throwable $e) { /* additive */ }
        return $out;
    }

    /** Our own addresses (never copied on our reply). */
    public static function ours(): array
    {
        $o = ['@mowology.ca', 'mowology@icloud.com'];
        if (defined('ICLOUD_IMAP_USER')) $o[] = strtolower((string)ICLOUD_IMAP_USER);
        return $o;
    }

    /**
     * Send a ready form (the owner tapped "Send it"). Returns ['ok' => bool, 'message' => string].
     */
    public function send(int $id): array
    {
        $f = $this->get($id);
        if (!$f) return ['ok' => false, 'message' => 'That form is gone.'];
        if ($f['status'] !== 'ready') return ['ok' => false, 'message' => 'Already ' . $f['status'] . '.'];
        $b = json_decode((string)$f['blanks_json'], true) ?: [];
        if (!empty($b['yours'])) return ['ok' => false, 'message' => 'This form needs you (' . implode(', ', $b['yours']) . ') — fill it yourself.'];
        $filled = rtrim(STORAGE_ROOT, '/') . '/' . (string)$f['filled_path'];
        if (empty($f['filled_path']) || !is_file($filled)) return ['ok' => false, 'message' => 'The filled form is missing — not sent.'];
        $files = [$filled => (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', 'Completed-' . $f['original_name'])];
        // The reply goes only to the supplier: the From ADDRESS must be theirs (a forged sender
        // would otherwise receive the filled form and the licence), copies only on their domain.
        $supplier = (new SupplierDirectoryService($this->db))->get((int)$f['supplier_id']);
        $r = self::recipients((string)$f['from_addr'], (string)$f['cc_addrs'], self::ours(), $supplier ?? []);
        if ($r['to'] === '') {
            return ['ok' => false, 'message' => 'The sender (' . $f['from_addr'] . ') is not an address on file for ' . ($f['supplier_name'] ?? 'this supplier') . ' — not sent. Check it and reply yourself.'];
        }
        $docs = new CompanyDocumentService($this->db);
        $withLicence = false;
        if ((int)$f['wants_licence'] === 1) {
            $lic = $docs->usable('business_licence', date('Y-m-d'));
            if (!$lic || !is_file(CompanyDocumentService::absolutePath($lic))) {
                return ['ok' => false, 'message' => 'They want a copy of the business licence and no current (unexpired) one is on file — not sent.'];
            }
            $files[CompanyDocumentService::absolutePath($lic)] = 'Business-licence' . ($lic['doc_number'] ? '-' . $lic['doc_number'] : '') . '.pdf';
            $withLicence = true;
        }
        $p = (new CompanyProfileService($this->db))->profile();
        $answers = json_decode((string)$f['answers_json'], true) ?: [];
        $html = self::note($withLicence, in_array(true, $answers, true), $p['email'] ?: 'office@mowology.ca',
                           ['contact' => $p['contact'], 'company' => $p['trade_name'] ?: $p['legal_name'], 'phone' => $p['phone']]);
        if (!function_exists('sendEmail')) require_once CRM_INCLUDES . '/messaging.php';
        $subject = trim((string)$f['subject']);
        $subject = $subject === '' ? 'Account application – ' . ($p['trade_name'] ?: $p['legal_name']) : (stripos($subject, 're:') === 0 ? $subject : 'Re: ' . $subject);
        $res = sendEmail($r['to'], $subject, $html, null, trim(($p['contact'] ? $p['contact'] . ', ' : '') . ($p['trade_name'] ?: 'Mowology')), $files, $r['cc']);
        if (empty($res['success'])) {
            $this->db->prepare("UPDATE supplier_forms SET error = ? WHERE id = ?")->execute([mb_substr((string)($res['error'] ?? 'send failed'), 0, 255), $id]);
            return ['ok' => false, 'message' => 'The email did not go: ' . ($res['error'] ?? 'send failed')];
        }
        $this->db->prepare("UPDATE supplier_forms SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")->execute([$id]);
        (new SupplierDirectoryService($this->db))->setStatus((int)$f['supplier_id'], 'applied');
        return ['ok' => true, 'message' => 'Sent to ' . $r['to'] . ($r['cc'] ? ' (copy to ' . implode(', ', $r['cc']) . ')' : '') . '.'];
    }

    public function decline(int $id): void
    {
        $this->db->prepare("UPDATE supplier_forms SET status = 'declined' WHERE id = ? AND status IN ('ready', 'unfillable')")->execute([$id]);
    }

    /** "a, b and c" */
    public static function listing(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) return (string)($items[0] ?? '');
        return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }
}
