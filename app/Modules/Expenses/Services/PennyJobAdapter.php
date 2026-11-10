<?php
declare(strict_types=1);

/**
 * Penny's duplicate-receipt job as the generic "job strip" the Ittai desktop shell prints at the top of a
 * head's pages (public/crm/includes/ittai-job.php). A new face on existing money logic only: the groups come
 * from DuplicateReceiptService::presentGroups() (the same ones the web Penny card shows) and the buttons carry
 * the exact requests bookkeeper-card.js sends to /crm/api/bookkeeper.php (dupe_merge, not_dupe, unmerge).
 *
 * Her second job (2026-11-10): the pending Interac e-Transfer to record. The reading is EtransferDeskService::read()
 * (the same one the Invoices page's "Pending e-Transfers" card makes) and Record payment carries the exact form
 * fields that card posts to /crm/api/etransfer-confirm.php (action=record, notification_id, invoice_numbers[],
 * amounts[]). The job also carries `pieces` {matched, total: 3} — the "2/3" fraction: how many of the e-Transfer
 * email, the invoice match and the bank deposit are already in hand — and a `label` naming the match.
 * Nothing here writes anything.
 */
class PennyJobAdapter
{
    /** Cheap gate: does Penny have an open possible-twin question? Any failure means no. */
    /**
     * Cheap gate before the real check: two waiting receipts with the same total within three days (the coarse
     * rule ExpenseLookupService::findDuplicates starts from). Penny's "possible twin" questions are NOT the
     * source — they cover approved receipts only, so a waiting pair never showed (seen 2026-11-10).
     */
    public static function hasWork(PDO $db): bool
    {
        try {
            self::load();
            $in = "'" . implode("','", DuplicateReceiptService::WAITING) . "'";
            // Bounded like pairsInLine(): the newest 200 waiting receipts, so a big backlog cannot make every
            // Penny page pay for an unindexed self-join (review, 2026-11-10).
            $s = $db->query("SELECT 1 FROM (SELECT id, total, expense_date FROM expenses WHERE status IN ($in) ORDER BY id DESC LIMIT 200) e1
                             JOIN (SELECT id, total, expense_date FROM expenses WHERE status IN ($in) ORDER BY id DESC LIMIT 200) e2
                               ON e2.id > e1.id AND ABS(e2.total - e1.total) < 0.01
                              AND ABS(DATEDIFF(e2.expense_date, e1.expense_date)) <= 3
                             LIMIT 1");
            return (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::hasWork: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * Penny's queue: the waiting duplicate pairs first (in the card's order), then the pending e-Transfers with a
     * proposed invoice ($withEtransfers: the owner can record payments — billing.edit). [] on any failure.
     */
    public static function jobs(PDO $db, array $user, bool $withEtransfers = false): array
    {
        $out = [];
        try {
            self::load();
            $svc = new DuplicateReceiptService($db);
            $groups = $svc->presentGroups(DuplicateReceiptService::groups($svc->pairsInLine(60)));
            $groups = array_values(array_filter($groups, fn($g) => !empty($g['keep_id']) && !empty($g['copy_ids'])));
            foreach ($groups as $g) $out[] = self::fromGroup($g, 0, 0);
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::jobs: ' . $e->getMessage());
            $out = [];
        }
        if ($withEtransfers) {
            foreach (self::etransferJobs($db) as $j) $out[] = $j;
        }
        $n = count($out);
        foreach ($out as $i => $j) { $out[$i]['index'] = $i + 1; $out[$i]['total'] = $n; }
        return $out;
    }

    /** Cheap gate for the e-Transfer job: is any notification still waiting to be recorded? Any failure means no. */
    public static function hasEtransferWork(PDO $db): bool
    {
        try {
            self::loadEtransfer();
            return (new EtransferInboxService($db))->countPending() > 0;
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::hasEtransferWork: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * The pending e-Transfers Penny can propose an invoice for, as jobs (index/total filled by jobs()). A transfer
     * with no invoice to propose, or one she thinks is already recorded, stays on the card alone: the strip never
     * offers Record without a proposal. [] on any failure.
     */
    public static function etransferJobs(PDO $db): array
    {
        try {
            self::loadEtransfer();
            $inbox = new EtransferInboxService($db);
            $desk = new EtransferDeskService($db);
            $rows = $inbox->listPending();
            $out = [];
            foreach (array_slice($rows, 0, 10) as $et) {   // the desk queue's cap: each read costs a few queries
                $read = $desk->read($et);
                if (!self::proposes($read)) continue;
                $facts = $inbox->invoiceFacts(array_map(fn($l) => (string)$l['invoice'], $read['lines']));
                $out[] = self::fromTransfer($et, $read, $facts, 0, 0, fn(string $sender, string $payer): bool => $inbox->paysFor($sender, $payer),
                    fn(string $sender, string $payer): int => EtransferInboxService::samePayer($sender, $payer) ? $inbox->timesRecordedFrom($sender) : $inbox->timesPaidFor($sender, $payer));
            }
            return $out;
        } catch (Throwable $e) {
            error_log('PennyJobAdapter::etransferJobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Pure: does Penny's reading of a transfer carry an invoice the strip may offer Record for, in one press?
     * Not when she thinks it is already recorded (duplicate), has nothing to propose (leftover, none), when the memo
     * names invoices that do not add up or are unclear (named_check: the card shows what is over, short or ambiguous),
     * or when the transfer is more than the one invoice owes (credit.with_record: the card offers "record and keep
     * the rest as credit"; a plain record would leave it part-recorded). Those stay on the card (review, 2026-11-10).
     */
    public static function proposes(array $read): bool
    {
        if (empty($read['lines']) || !is_array($read['lines'])) return false;
        if (in_array((string)($read['kind'] ?? ''), ['duplicate', 'leftover', 'none', 'named_check'], true)) return false;
        if (!empty($read['credit']['with_record'])) return false;
        return true;
    }

    /**
     * One pending e-Transfer → the generic job shape. Pure.
     * $et is the listPending() row (sender, amount, memo, email_date, bank columns); $read is EtransferDeskService::read()
     * of it (kind, lines, confirmed, say); $facts is invoiceFacts() for the lines, keyed by invoice number.
     * $paysFor(sender, payer) is what Penny has learned about who pays for whom (a strata plan paying an owner's bill):
     * the Payer column reads "same" for a pair the owner has recorded before, as the matcher itself does.
     * $record(sender, payer) is how many times the owner has recorded this sender's transfers for this payer: it earns the
     * knowledge triangle (sureOfTransfer). None given = no record.
     * The e-Transfer is drawn ABOVE the rail and the invoice(s) BELOW it; the discs on the rail compare them.
     */
    public static function fromTransfer(array $et, array $read, array $facts, int $index, int $total, ?callable $paysFor = null, ?callable $record = null): array
    {
        $id = (int)($et['id'] ?? $read['id'] ?? 0);
        $sender = trim((string)($et['sender_name'] ?? '')) !== '' ? trim((string)$et['sender_name']) : 'Unknown sender';
        $active = round((float)($read['active'] ?? ($et['amount'] ?? 0)), 2);
        $lines = array_values((array)($read['lines'] ?? []));
        $numbers = array_values(array_filter(array_map(fn($l) => (string)($l['invoice'] ?? ''), $lines), fn($n) => $n !== ''));
        $n = count($numbers);
        $kind = (string)($read['kind'] ?? 'none');

        // the invoice side, from the facts we have for the proposed lines
        $owing = 0.0; $dates = []; $payers = [];
        foreach ($numbers as $no) {
            $f = (array)($facts[$no] ?? []);
            $owing += (float)($f['balance_due'] ?? 0);
            if (!empty($f['invoice_date']) && strtotime((string)$f['invoice_date'])) $dates[] = (string)$f['invoice_date'];
            $p = trim((string)($f['payer_name'] ?? ''));
            if ($p !== '') $payers[$p] = true;
        }
        $owing = round($owing, 2);
        $payers = array_keys($payers);
        $client = count($payers) === 1 ? $payers[0] : (count($payers) > 1 ? count($payers) . ' clients' : '');
        sort($dates);
        $knowOwing = $n > 0 && count(array_intersect_key($facts, array_fill_keys($numbers, 1))) === $n;

        $d = static fn(?string $v, string $f = 'M j'): string => ($v && strtotime($v)) ? date($f, strtotime($v)) : '';
        $money = static fn(float $v): string => '$' . number_format($v, 2);

        // Column 1: the amount — the transfer against what the invoice(s) owe
        $amountState = $knowOwing ? (abs($owing - $active) < 0.005 ? 'same' : 'differs') : 'info';
        // Column 2: who sent it against who the invoice is for
        $payerState = $client === '' || count($payers) !== 1 ? 'info' : (EtransferInboxService::samePayer($sender, $client) || ($paysFor !== null && $paysFor($sender, $client)) ? 'same' : 'differs');
        // Column 3: when it was banked against when the invoice was issued (facts, not a judgement)
        $bankTop = !empty($et['bank_transaction_id']) ? 'Banked ' . $d($et['bank_transaction_date'] ?? null)
                 : (!empty($et['likely_deposit_date']) ? 'Likely ' . $d((string)$et['likely_deposit_date']) : 'Not banked yet');
        $issued = $dates ? 'Issued ' . $d($dates[0]) . (count($dates) > 1 && $d(end($dates)) !== $d($dates[0]) ? ' – ' . $d(end($dates)) : '') : '–';
        // Column 4: the bank memo against the invoice list — same when the number is on the transfer
        $memo = trim((string)($et['memo'] ?? ''));
        $memoTop = $memo !== '' ? (mb_strlen($memo) > 26 ? mb_substr($memo, 0, 25) . '…' : $memo) : 'No memo';
        $shortNos = array_map(fn($no) => preg_match('/(\d{3,})$/', $no, $m) ? $m[1] : $no, $numbers);
        $list = $n > 3 ? implode(', ', array_slice($shortNos, 0, 2)) . ' +' . ($n - 2) : implode(', ', $shortNos);
        $memoState = in_array($kind, ['named', 'named_check'], true) || (string)($et['match_method'] ?? '') === 'invoice_number' ? 'same' : 'info';

        $columns = [
            ['label' => 'Amount', 'top' => $money($active), 'bottom' => $knowOwing ? $money($owing) . ' owing' : '–', 'state' => $amountState],
            ['label' => 'Payer', 'top' => $sender, 'bottom' => $client !== '' ? $client : '–', 'state' => $payerState],
            ['label' => 'Dates', 'top' => $bankTop, 'bottom' => $issued, 'state' => 'info'],
            ['label' => 'Memo', 'top' => $memoTop, 'bottom' => $list !== '' ? ($n > 1 ? 'Invoices ' : 'Invoice ') . $list : '–', 'state' => $memoState],
        ];

        $invoiceNumbers = []; $amounts = [];
        foreach ($lines as $l) {
            $no = trim((string)($l['invoice'] ?? ''));
            if ($no === '') continue;
            $invoiceNumbers[] = $no;
            $amounts[] = number_format((float)($l['amount'] ?? 0), 2, '.', '');
        }
        $matched = max(1, min(3, (int)($read['confirmed'] ?? 1)));
        $emailAt = $d($et['email_date'] ?? null, 'M j, g:i a');

        return [
            'head'       => 'penny',
            'kind'       => 'etransfer',
            'key'        => 'et:' . $id,
            'title'      => 'e-Transfer to record — Penny has matched it',
            'confidence' => trim((string)($read['say'] ?? '')),
            'sure'       => self::sureOfTransfer($kind, count($payers) === 1 && $record !== null ? (int)$record($sender, $client) : 0, $amountState === 'differs' || $payerState === 'differs'),
            'pieces'     => ['matched' => $matched, 'total' => 3],
            'label'      => $n . ' invoice' . ($n === 1 ? '' : 's') . ' + payment',
            // the two sides: the e-Transfer above the rail, the invoice(s) below it
            'left'       => ['label' => 'e-Transfer', 'who' => $sender, 'when' => $emailAt !== '' ? 'Interac email ' . $emailAt : '', 'amount' => $money($active)],
            'right'      => ['label' => $n === 1 ? $numbers[0] : $n . ' invoices', 'who' => $client, 'when' => $issued !== '–' ? $issued : '', 'amount' => $knowOwing ? $money($owing) . ' owing' : ''],
            'columns'    => $columns,
            // the amount is already on the rail: the button names only what it pays
            'primary'    => ['label' => 'Record payment', 'sub' => $n === 1 ? $numbers[0] : $n . ' invoices',
                             'request' => ['action' => 'record', 'notification_id' => $id, 'invoice_numbers' => $invoiceNumbers, 'amounts' => $amounts]],
            'secondary'  => ['label' => 'Not this invoice', 'sub' => 'pick on the card',
                             'request' => ['mode' => 'pick', 'notification_id' => $id, 'href' => '/crm/invoices/#etransfers']],
            'undo'       => null,
            // Record payment posts the card's own form to the card's own endpoint
            'api'        => '/crm/api/etransfer-confirm.php',
            'transport'  => 'form',
            'start'      => ['label' => 'Bank', 'href' => '/crm/invoices/#etransfers', 'icon' => 'bank'],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    /**
     * The knowledge triangle for an e-Transfer (SPEC-v1.2 "Confidence"; Tim, 2026-11-10). Pure.
     * $recorded: times the owner has recorded this sender's transfers for this payer (an undone recording no longer counts).
     * $differs: a ≠ disc is showing (amount or payer). One strikes it back to the broken triangle, however well known.
     * forming (three loose lines): never recorded and only a reading by amount, name or history;
     * held (broken triangle): noticed, or something differs;
     * known (solid blue): the invoice number is on the transfer, or recorded twice or more;
     * mastered (gold): recorded five times or more with nothing differing.
     */
    public static function sureOfTransfer(string $kind, int $recorded, bool $differs): string
    {
        $strong = in_array($kind, ['match', 'named'], true);
        if ($differs) return (!$strong && $recorded <= 0) ? 'forming' : 'held';
        if ($recorded >= self::MASTERED_AT) return 'mastered';
        if ($strong || $recorded >= 2) return 'known';
        return $recorded <= 0 ? 'forming' : 'held';
    }

    /** Right-every-time recordings that earn the gold triangle (Tim, 2026-11-10: five). */
    public const MASTERED_AT = 5;

    /**
     * One presentGroup() group → the generic job shape. Pure. The first copy is the one compared; a group with
     * more copies still merges them all (copy_ids), as the web card does.
     * undo.requests: one unmerge per copy, sent in turn (bookkeeper-card.js undoDupe()).
     */
    public static function fromGroup(array $group, int $index, int $total): array
    {
        $members = array_values((array)($group['members'] ?? []));
        $keepId = (int)($group['keep_id'] ?? 0);
        $copyIds = array_values(array_map('intval', (array)($group['copy_ids'] ?? [])));
        $copyId = $copyIds[0] ?? 0;

        $keepAt = $copyAt = null;
        foreach ($members as $i => $m) {
            if ((int)($m['id'] ?? 0) === $keepId) $keepAt = $i;
            if ((int)($m['id'] ?? 0) === $copyId) $copyAt = $i;
        }
        $side = fn(?int $at, int $id): array => self::side($at !== null ? (array)$members[$at] : [], $id);

        $columns = [];
        foreach ((array)($group['facts'] ?? []) as $row) {
            $label = (string)($row['label'] ?? '');
            if ($label === '' || in_array($label, ['Status', 'Sent in by'], true)) continue;   // shown under the photos
            $vals = (array)($row['values'] ?? []);
            $state = (string)($row['state'] ?? 'info');
            $columns[] = [
                'label'  => $label,
                'top'    => self::text($keepAt !== null ? ($vals[$keepAt] ?? null) : null),
                'bottom' => self::text($copyAt !== null ? ($vals[$copyAt] ?? null) : null),
                'state'  => in_array($state, ['same', 'differs', 'missing', 'info'], true) ? $state : 'info',
            ];
        }

        $mergeLabel = (string)($group['merge_label'] ?? '');
        $pairs = [];
        foreach ((array)($group['pairs'] ?? []) as $p) $pairs[] = [(int)($p[0] ?? 0), (int)($p[1] ?? 0)];
        $undo = [];
        foreach ($copyIds as $id) $undo[] = ['mode' => 'unmerge', 'copy_id' => $id, 'kind' => 'undo'];

        return [
            'head'       => 'penny',
            'kind'       => 'duplicate',
            'key'        => 'dup:' . $keepId . ':' . implode('-', $copyIds),
            'title'      => 'Duplicate receipt? Penny thinks so',
            'verdict'    => (string)($group['verdict'] ?? ''),
            // Penny's confidence sentence, or her verdict when she has no track record with this vendor yet
            'confidence' => trim((string)($group['confidence'] ?? '')) !== '' ? trim((string)$group['confidence']) : trim((string)($group['verdict'] ?? '')),
            // How whole the approval triangle is drawn: Penny's record with this vendor (Tim, 2026-11-10)
            'sure'       => self::sure((array)($group['record'] ?? [])),
            'keep'       => $side($keepAt, $keepId),
            'copy'       => $side($copyAt, $copyId),
            'columns'    => $columns,
            // The strip shows the first copy; when the merge would set aside more, the label says how many (review, 2026-11-10).
            'primary'    => ['label' => 'Merge', 'sub' => ($mergeLabel !== '' ? $mergeLabel : 'into #' . $keepId) . (count($copyIds) > 1 ? ' · ' . count($copyIds) . ' copies' : ''),
                             'request' => ['mode' => 'dupe_merge', 'keep_id' => $keepId, 'copy_ids' => $copyIds]],
            'secondary'  => ['label' => 'Different', 'sub' => count($members) > 2 ? 'keep them all' : 'keep both',
                             'request' => ['mode' => 'not_dupe', 'pairs' => $pairs]],
            'undo'       => ['requests' => $undo],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    /**
     * The triangle's state from her record with the vendor. Pure.
     * forming: no call yet (a new vendor, a new business) — three loose lines;
     * held: one call, or ever wrong — the broken triangle;
     * known: right every time over two to four calls — solid blue;
     * mastered: right every time over five or more — gold.
     */
    public static function sure(array $record): string
    {
        $called = (int)($record['called'] ?? 0);
        $agreed = (int)($record['agreed'] ?? 0);
        if ($called <= 0) return 'forming';
        if ($agreed < $called) return 'held';
        return $called >= self::MASTERED_AT ? 'mastered' : ($called >= 2 ? 'known' : 'held');
    }

    /** One receipt of the pair for the strip: id, "#278 · in the books", who sent it, photo. */
    private static function side(array $m, int $id): array
    {
        $waiting = in_array((string)($m['status'] ?? ''), ['draft', 'pending_approval'], true);
        $path = (string)($m['receipt_path'] ?? '');
        if ($path === '' && !empty($m['receipt_media_id'])) $path = '/crm/api/serve-receipt.php?id=' . (int)$m['receipt_media_id'];
        return [
            'id'    => $id,
            'label' => '#' . $id . ' · ' . ($waiting ? 'waiting' : 'in the books'),
            'who'   => self::shortName((string)($m['submitted_by'] ?? '')),
            'image' => $path !== '' ? $path : null,
        ];
    }

    /** "Nigel Carter" → "Nigel C."; one word stays as it is; nobody → ''. */
    private static function shortName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if (!$parts) return '';
        if (count($parts) === 1) return $parts[0];
        $last = (string)end($parts);
        return $parts[0] . ' ' . mb_substr($last, 0, 1) . '.';
    }

    private static function text($v): string
    {
        $s = trim((string)($v ?? ''));
        return $s !== '' ? $s : '–';
    }

    private static function load(): void
    {
        if (!defined('APP_ROOT')) return;   // tests load the services through bootstrap.php
        foreach (['PennyQuestionService', 'DuplicateReceiptService'] as $c) {
            if (!class_exists($c, false)) require_once APP_ROOT . '/Modules/Expenses/Services/' . $c . '.php';
        }
    }

    private static function loadEtransfer(): void
    {
        if (!defined('APP_ROOT')) return;   // tests load the services through bootstrap.php
        foreach (['EtransferInboxService', 'EtransferDeskService'] as $c) {
            if (!class_exists($c, false)) require_once APP_ROOT . '/Modules/Accounting/Services/' . $c . '.php';
        }
    }
}
