<?php
/**
 * PennyQuestionService — questions Penny asks the owner, starting with unbilled materials.
 *
 * "Did you forget to raise an invoice for X, or is the product from Lawnboy included in
 * the contract?" (Tim, 2026-10-05). An approved Materials / Disposal / Subcontractor
 * receipt assigned to a job, 3–60 days old, with no invoice for that job or property
 * showing it, becomes a question. The answers teach her:
 *   invoice      → counts toward "found to bill"; opens a pre-filled invoice
 *   contract     → never asks again for this job (or contract) + vendor
 *   not_billable → never asks again for this job + vendor (shop stock, warranty, own use)
 *   billed       → it is on an invoice she didn't connect; closes this one question only
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennyQuestionService
{
    public const BILLABLE_CATEGORIES = ['Materials', 'Disposal/Dump', 'Subcontractors'];
    /** 'billed' (2026-10-12): it is already on an invoice Penny didn't connect (Marc Nelitz's hedge disposal). */
    public const ANSWERS = ['invoice', 'contract', 'not_billable', 'billed', 'account'];
    /** Give the owner time to invoice before asking; stop asking about old receipts. */
    public const MIN_AGE_DAYS = 3;
    public const MAX_AGE_DAYS = 60;
    /** An invoice dated this close to the purchase can be the one that billed it. */
    public const INVOICE_BEFORE_DAYS = 7;
    public const INVOICE_AFTER_DAYS = 45;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'penny_questions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Create questions for newly-unbilled receipts. Cheap SQL — safe to run on card load. */
    public function scan(int $limit = 10): int
    {
        if (!$this->ready()) return 0;
        $in = implode(',', array_fill(0, count(self::BILLABLE_CATEGORIES), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.job_id, e.property_id, e.vendor_id, e.expense_date, e.amount, e.total, e.accounting_category,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor_name,
                   jp.property_id AS plan_property_id, jp.contract_id
            FROM expenses e
            JOIN job_plans jp ON jp.id = e.job_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE e.status IN ('approved', 'forwarded')
              AND e.accounting_category IN ({$in})
              AND e.expense_date BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM penny_questions q WHERE q.kind = 'unbilled_materials' AND q.expense_id = e.id)
            ORDER BY e.expense_date DESC
            LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute(array_merge(self::BILLABLE_CATEGORIES, [
            date('Y-m-d', strtotime('-' . self::MAX_AGE_DAYS . ' days')),
            date('Y-m-d', strtotime('-' . self::MIN_AGE_DAYS . ' days')),
        ]));

        $made = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $propertyId = $e['property_id'] ?: $e['plan_property_id'];
            if ($this->alreadySettled((int)$e['job_id'], $e['contract_id'] ? (int)$e['contract_id'] : null, $e['vendor_id'] ? (int)$e['vendor_id'] : null, (string)$e['vendor_name'])) {
                continue;
            }
            $items = $this->itemNames((int)$e['id']);
            if ($this->looksBilled((int)$e['job_id'], $propertyId ? (int)$propertyId : null, (string)$e['expense_date'], $items, (string)$e['accounting_category'])) {
                continue;
            }
            $this->db->prepare("
                INSERT IGNORE INTO penny_questions
                    (kind, expense_id, plan_id, property_id, contract_id, vendor_id, vendor_name, category, amount, question)
                VALUES ('unbilled_materials', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                (int)$e['id'], (int)$e['job_id'], $propertyId ?: null, $e['contract_id'] ?: null,
                $e['vendor_id'] ?: null, $e['vendor_name'], $e['accounting_category'], (float)($e['amount'] ?: $e['total']),
                '',   // worded when shown (open()), so it always reads from today's data
            ]);
            $made++;
        }
        return $made;
    }

    /**
     * Income with no account yet: one question per service type ("which income account
     * do maintenance jobs go to?"). The answer sets service_revenue_accounts, which the
     * ledger and the books re-post use. Needs migration 1130.
     */
    public function scanServiceAccounts(): int
    {
        if (!$this->ready()) return 0;
        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/LedgerAccountMap.php';
            $map = new LedgerAccountMap($this->db);
            if (!$map->ready()) return 0;
            $made = 0;
            foreach ($map->unmappedServiceTypes() as $u) {
                $key = LedgerAccountMap::serviceKey($u['service_type']);
                $s = $this->db->prepare("SELECT 1 FROM penny_questions WHERE kind = 'service_account' AND subject = ? AND status = 'open'");
                $s->execute([$key]);
                if ($s->fetchColumn()) continue;
                $this->db->prepare("INSERT INTO penny_questions (kind, subject, expense_id, amount, question) VALUES ('service_account', ?, NULL, ?, ?)")
                   ->execute([$key, $u['amount'], self::serviceWording($u['service_type'], $u['invoices'], $u['amount'])]);
                $made++;
            }
            return $made;
        } catch (Throwable $e) {
            return 0;   // before migration 1130 (no subject column)
        }
    }

    public static function serviceWording(string $serviceType, int $invoices, float $amount, string $name = ''): string
    {
        return sprintf('%s %d invoice%s ($%s) %s for "%s" jobs and still land%s on Other Services. Which income account should "%s" go to? I\'ll use your answer from now on, and for the books re-post.',
            $name !== '' ? "Hey {$name} —" : 'Hey —', $invoices, $invoices === 1 ? '' : 's', number_format($amount, 0),
            $invoices === 1 ? 'is' : 'are', $serviceType, $invoices === 1 ? 's' : '', $serviceType);
    }

    /** The owner already said this job/contract + vendor is covered or not billable. */
    private function alreadySettled(int $planId, ?int $contractId, ?int $vendorId, string $vendorName): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM penny_questions
            WHERE status = 'answered' AND answer IN ('contract', 'not_billable')
              AND (plan_id = ? OR (? IS NOT NULL AND contract_id = ?))
              AND (vendor_id = ? OR (? IS NULL AND vendor_name = ?))
            LIMIT 1
        ");
        $stmt->execute([$planId, $contractId, $contractId, $vendorId, $vendorId, $vendorName]);
        return (bool)$stmt->fetchColumn();
    }

    /** A split receipt's allocations (ExpenseSplitService), or [] when it isn't split or before migration 1233. */
    private function allocations(int $expenseId): array
    {
        try {
            $s = $this->db->prepare("SELECT a.job_id, a.is_stock, a.net_amount, li.name
                                     FROM expense_line_allocations a JOIN expense_line_items li ON li.id = a.line_item_id
                                     WHERE a.expense_id = ? ORDER BY a.sort_order, a.id");
            $s->execute([$expenseId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Pure: the part of a split receipt that sits on this job — its line names and net amount.
     * Null when the receipt isn't split (ask about the whole receipt, as before).
     * @return ?array{names: list<string>, amount: float}
     */
    public static function splitPart(array $allocations, int $planId): ?array
    {
        if (!$allocations) return null;
        $names = [];
        $amount = 0.0;
        foreach ($allocations as $a) {
            if ((int)($a['job_id'] ?? 0) !== $planId || $planId <= 0 || !empty($a['is_stock'])) continue;
            $names[] = (string)$a['name'];
            $amount += (float)$a['net_amount'];
        }
        return ['names' => $names, 'amount' => round($amount, 2)];
    }

    private function itemNames(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $s->execute([$expenseId]);
        return self::realItems($s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** An invoice for this job or property, near the purchase date, has a line that mentions it. */
    private function looksBilled(int $planId, ?int $propertyId, string $date, array $items, string $category): bool
    {
        $s = $this->db->prepare("
            SELECT li.description
            FROM invoices i JOIN invoice_line_items li ON li.invoice_id = i.id
            WHERE (i.plan_id = ? OR (? IS NOT NULL AND i.property_id = ?))
              AND COALESCE(i.invoice_date, i.issue_date) BETWEEN ? AND ?
              AND COALESCE(i.status, '') NOT IN ('void', 'cancelled')
        ");
        $s->execute([
            $planId, $propertyId, $propertyId,
            date('Y-m-d', strtotime($date . ' -' . self::INVOICE_BEFORE_DAYS . ' days')),
            date('Y-m-d', strtotime($date . ' +' . self::INVOICE_AFTER_DAYS . ' days')),
        ]);
        return self::lineMentions($s->fetchAll(PDO::FETCH_COLUMN), $items, $category);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure helpers (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Words worth matching on an invoice line: 4+ letters, not packaging/noise. */
    public static function keywords(array $items, string $category): array
    {
        static $noise = ['each', 'bags', 'pack', 'with', 'from', 'item', 'total', 'regular', 'large', 'small', 'black', 'brown', 'green'];
        $words = [];
        foreach ($items as $name) {
            foreach (preg_split('/[^a-z]+/', strtolower($name)) as $w) {
                if (strlen($w) >= 4 && !in_array($w, $noise, true)) $words[$w] = true;
            }
        }
        $generic = ['Materials' => ['material', 'materials', 'supplies'], 'Disposal/Dump' => ['dump', 'disposal', 'haul', 'green waste'], 'Subcontractors' => ['subcontract']];
        foreach ($generic[$category] ?? [] as $w) $words[$w] = true;
        return array_keys($words);
    }

    /** Does any invoice line mention the purchase? */
    public static function lineMentions(array $lineDescriptions, array $items, string $category): bool
    {
        $kw = self::keywords($items, $category);
        foreach ($lineDescriptions as $d) {
            $d = strtolower((string)$d);
            foreach ($kw as $w) {
                if (str_contains($d, $w)) return true;
            }
        }
        return false;
    }

    /** Line-item names that are things bought — not totals, taxes or payment lines the reader kept. */
    public static function realItems(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string)$n);
            if ($n === '' || preg_match('/^(sub\s*-?\s*total|total|gst|pst|hst|tax(es)?|change|cash|visa|mastercard|debit|amex|balance( due)?|amount( due)?|tendered|rounding|discount|savings?)\b/i', $n)) continue;
            if (!in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    /** "HUNTERS GARDEN CENTRE" → "Hunters Garden Centre"; mixed case is left as typed. */
    public static function tidy(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return $s !== '' && strtoupper($s) === $s ? ucwords(strtolower($s)) : $s;
    }

    /** The name Penny calls the owner: first name, else the first word of the full name. */
    public static function firstName(array $user): string
    {
        $n = trim((string)($user['first_name'] ?? '')) ?: strtok(trim((string)($user['full_name'] ?? '')), ' ');
        return $n ? self::tidy((string)$n) : '';
    }

    /**
     * Penny's voice: friendly, first-name, plain. A job title shorter than 4 letters
     * ("chk") is a placeholder, so she just says "the job at <address>".
     */
    public static function wording(float $amount, array $items, string $vendor, string $jobTitle, string $address, string $date, bool $hasContract, string $name = ''): string
    {
        $vendor = $vendor !== '' ? self::tidy($vendor) : 'a supplier';
        $what = $items
            ? 'picked up ' . implode(', ', array_map([self::class, 'tidy'], array_slice($items, 0, 2))) . (count($items) > 2 ? ' and a few more things' : '') . ' ($' . number_format($amount, 2) . ')'
            : 'spent $' . number_format($amount, 2);
        $jobTitle = strlen(trim($jobTitle)) >= 4 ? self::tidy($jobTitle) : '';
        $for = $jobTitle !== '' ? $jobTitle . ($address !== '' ? ' at ' . $address : '') : 'the job' . ($address !== '' ? ' at ' . $address : '');
        return sprintf('%s you %s at %s on %s for %s, and I can\'t find it on any invoice. Did it slip through, or %s?',
            $name !== '' ? "Hey {$name} —" : 'Hey —', $what, $vendor, date('M j', strtotime($date)), $for,
            $hasContract ? 'is it covered by their contract' : 'isn\'t it billable');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Card
    // ─────────────────────────────────────────────────────────────────────────

    public function open(int $limit = 5, string $name = ''): array
    {
        if (!$this->ready()) return [];
        $service = [];
        try {
            $service = $this->db->query("SELECT id, kind, subject, amount, question FROM penny_questions
                                         WHERE status = 'open' AND kind = 'service_account' ORDER BY amount DESC, id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            if ($service) {
                $accts = $this->db->query("SELECT id, code, name FROM chart_of_accounts WHERE type = 'revenue' AND is_active = 1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($service as &$q) {
                    $q['question'] = $name !== '' ? preg_replace('/^Hey —/', "Hey {$name} —", $q['question']) : $q['question'];
                    // Questions saved before the singular fix.
                    $q['question'] = preg_replace('/\b1 invoice (\(\$[\d,]+\)) are (.*?) still land on/', '1 invoice $1 is $2 still lands on', $q['question']);
                    $q['choices'] = $accts;
                }
                unset($q);
            }
        } catch (Throwable $e) { /* before migration 1130 */ }
        // The receipt as it is NOW, not as it was when Penny asked (2026-10-12, Lawnboy #412): the
        // owner moved its mulch to Oakridge and its seed to shop stock, but the question still read
        // "$200 for 998 West 19th". The job comes from the expense; a split receipt asks only about
        // the lines on that job, and a receipt with none left on a job is no longer a question.
        $rows = $this->db->query("
            SELECT q.id, q.expense_id, COALESCE(e.job_id, q.plan_id) AS plan_id, e.job_id AS live_job_id, jp.contract_id, q.amount, q.vendor_name, e.expense_date,
                   COALESCE(jp.title, jp.service_type, '') AS job_title, COALESCE(p.address, '') AS address
            FROM penny_questions q
            JOIN expenses e ON e.id = q.expense_id
            LEFT JOIN job_plans jp ON jp.id = COALESCE(e.job_id, q.plan_id)
            LEFT JOIN properties p ON p.id = jp.property_id
            WHERE q.status = 'open' AND e.status <> 'rejected' ORDER BY q.amount DESC, q.id LIMIT " . max(1, min(40, $limit * 2))
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $part = self::splitPart($this->allocations((int)$r['expense_id']), (int)$r['plan_id']);
            if ($part !== null && $part['amount'] <= 0) continue;   // nothing on a job any more
            // Moved off the job (no job now, no split line on it): no longer a question (review round 2).
            if ($part === null && empty($r['live_job_id'])) continue;
            unset($r['live_job_id']);
            $items = $part !== null ? self::realItems($part['names']) : $this->itemNames((int)$r['expense_id']);
            $amount = $part !== null ? $part['amount'] : (float)$r['amount'];
            $r['amount'] = $amount;
            $r['question'] = self::wording($amount, $items, (string)$r['vendor_name'],
                (string)$r['job_title'], (string)$r['address'], (string)$r['expense_date'], (bool)$r['contract_id'], $name);
            unset($r['vendor_name'], $r['expense_date'], $r['job_title'], $r['address']);
            $r['kind'] = 'unbilled_materials';
            $out[] = $r;
        }
        usort($out, fn($a, $b) => [(float)$b['amount'], $a['id']] <=> [(float)$a['amount'], $b['id']]);
        return array_slice(array_merge($service, $out), 0, max(1, $limit));
    }

    /** @return array{ok: bool, message: string, invoice_url?: string} */
    public function answer(int $id, string $answer, int $userId, string $name = '', ?int $accountId = null): array
    {
        if (!in_array($answer, self::ANSWERS, true)) {
            return ['ok' => false, 'message' => 'Unknown answer'];
        }
        if ($answer === 'account') {
            return $this->answerAccount($id, (int)$accountId, $userId, $name);
        }
        $s = $this->db->prepare("SELECT COALESCE(e.job_id, q.plan_id) AS plan_id, q.status, q.kind FROM penny_questions q LEFT JOIN expenses e ON e.id = q.expense_id WHERE q.id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open') {
            return ['ok' => false, 'message' => 'Already answered'];
        }
        // "Which income account?" is answered with an account, nothing else — closing it as
        // "contract" left the account never set (review 2026-10-12).
        if (($q['kind'] ?? '') === 'service_account') {
            return ['ok' => false, 'message' => 'Pick the income account for this one'];
        }
        $this->db->prepare("UPDATE penny_questions SET status = 'answered', answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ?")
           ->execute([$answer, $userId, $id]);
        $hi = $name !== '' ? ", {$name}" : '';
        $msg = ['invoice'      => "Thanks{$hi} — I'll open a fresh invoice for that job.",
                'contract'     => "Got it{$hi} — covered by the contract. I won't ask about this supplier on this job again.",
                'not_billable' => "Got it{$hi} — not billable. I won't ask about this supplier on this job again.",
                'billed'       => "Thanks{$hi} — it's already on an invoice. Marked as billed."][$answer];
        $out = ['ok' => true, 'message' => $msg];
        if ($answer === 'invoice' && $q['plan_id']) {
            $out['invoice_url'] = '/crm/invoices/create.php?plan_id=' . (int)$q['plan_id'];
        }
        return $out;
    }

    private function answerAccount(int $id, int $accountId, int $userId, string $name): array
    {
        $s = $this->db->prepare("SELECT kind, subject, status FROM penny_questions WHERE id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open' || $q['kind'] !== 'service_account') return ['ok' => false, 'message' => 'Already answered'];
        $a = $this->db->prepare("SELECT code, name FROM chart_of_accounts WHERE id = ? AND type = 'revenue' AND is_active = 1");
        $a->execute([$accountId]);
        $acct = $a->fetch(PDO::FETCH_ASSOC);
        if (!$acct) return ['ok' => false, 'message' => 'Pick an income account'];
        require_once APP_ROOT . '/Modules/Accounting/Services/LedgerAccountMap.php';
        (new LedgerAccountMap($this->db))->setServiceAccount((string)$q['subject'], $accountId, $userId);
        $this->db->prepare("UPDATE penny_questions SET status = 'answered', answer = 'account', answered_by = ?, answered_at = NOW() WHERE id = ?")
           ->execute([$userId, $id]);
        $hi = $name !== '' ? ", {$name}" : '';
        return ['ok' => true, 'message' => "Thanks{$hi} — \"{$q['subject']}\" income goes to {$acct['code']} {$acct['name']} from now on. Re-post the books to move the old ones."];
    }

    /**
     * Penny's "found to bill" this month: questions answered "forgot — invoice it" whose job then
     * really got an invoice (issued, not void) — the click alone used to count (review round 2,
     * 2026-10-12).
     */
    public function foundToBillMonth(): float
    {
        if (!$this->ready()) return 0.0;
        $s = $this->db->prepare("
            SELECT COALESCE(SUM(q.amount), 0) FROM penny_questions q
            LEFT JOIN expenses e ON e.id = q.expense_id
            WHERE q.answer = 'invoice' AND q.answered_at >= ?
              AND EXISTS (SELECT 1 FROM invoices i
                          WHERE i.plan_id = COALESCE(e.job_id, q.plan_id)
                            AND i.created_at >= q.answered_at - INTERVAL 1 DAY
                            AND i.status NOT IN ('draft', 'void', 'cancelled'))
        ");
        $s->execute([date('Y-m-01')]);
        return round((float)$s->fetchColumn(), 2);
    }
}
