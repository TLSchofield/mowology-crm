<?php
/**
 * PayerIdentityService — who pays as whom (migration 1362, plan docs/plans/2026-10-14-payer-identity.md).
 *
 * The bank, Interac and clients name a payer in their own words: "VML EFT" is Vancouver Management Ltd,
 * the e-Transfer sender "STRATA PLAN BCS-2106" pays Alexandra Bee's invoices, the Interac alert "Jo Gal"
 * is Joseph Gallentine. One table (payer_aliases) holds every such wording; this is its only reader and
 * writer. It turns a wording into the payer's name on file, and the existing name matchers
 * (InvoiceReconciliationService::namesAgree, invoicesForDeposit…) do the rest — they keep their own
 * strictness on purpose (payroll, strata plan numbers), so they are not replaced.
 *
 * Kinds: bank_memo (whole words found in a bank memo), etransfer_sender (the Interac email's sender
 * name), sms_name (the name on an Interac alert text), email, phone.
 * Source: 'tim' (an owner said so) always ranks above 'learned' (Penny saw it confirmed), whatever
 * the counts. A removed alias keeps its row (removed_at) so it is never learned back.
 *
 * Before 1362 runs it reads the two old tables (bank_memo_payers, etransfer_sender_payers), and
 * while they live it writes them too, so nothing that still reads them goes stale.
 * Tenant-private: never shared through the vendor knowledge commons.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PayerIdentityService
{
    public const KINDS = ['bank_memo', 'etransfer_sender', 'sms_name', 'email', 'phone'];
    public const SOURCES = ['tim', 'learned', 'import'];

    private PDO $db;
    private ?bool $ready = null;
    /** @var array<string, array>|null kind => rows (cache per request) */
    private ?array $cache = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure ─────────────────────────────────────────────────────────────────

    /** The comparable form of a wording. Pure (tested). */
    public static function key(string $kind, string $raw): string
    {
        $raw = trim($raw);
        switch ($kind) {
            case 'bank_memo':
                return trim(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', ' ', $raw)));
            case 'email':
                return strtolower($raw);
            case 'phone':
                $d = (string)preg_replace('/\D/', '', $raw);
                return strlen($d) > 10 ? substr($d, -10) : $d;
            default:   // etransfer_sender, sms_name — the e-Transfer matcher's long-standing key
                return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $raw));
        }
    }

    /** A payer name's key (the same rule EtransferInboxService has always used). */
    public static function payerKey(string $name): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    /**
     * Pure (tested): the payer names these alias rows give for a wording, best first — owner-said
     * before learned, then most seen. bank_memo rows match as whole words anywhere in the memo
     * (longest first); every other kind matches its key exactly.
     * @param array<int, array{kind:string, alias_key:string, payer_name:string, source?:string, times?:int}> $rows
     * @return string[]
     */
    public static function pick(string $kind, string $raw, array $rows): array
    {
        $k = self::key($kind, $raw);
        if ($k === '') return [];
        $memo = ' ' . $k . ' ';
        $hits = [];
        foreach ($rows as $r) {
            if (($r['kind'] ?? '') !== $kind || !empty($r['removed_at'])) continue;
            $ak = (string)$r['alias_key'];
            if ($ak === '') continue;
            $ok = $kind === 'bank_memo' ? strpos($memo, ' ' . $ak . ' ') !== false : $ak === $k;
            if ($ok) $hits[] = $r;
        }
        usort($hits, function ($a, $b) {
            $ta = ($a['source'] ?? 'learned') === 'tim' ? 1 : 0;
            $tb = ($b['source'] ?? 'learned') === 'tim' ? 1 : 0;
            if ($ta !== $tb) return $tb <=> $ta;
            $la = strlen((string)$a['alias_key']); $lb = strlen((string)$b['alias_key']);
            if ($la !== $lb) return $lb <=> $la;
            return (int)($b['times'] ?? 1) <=> (int)($a['times'] ?? 1);
        });
        $out = [];
        foreach ($hits as $h) {
            $n = trim((string)$h['payer_name']);
            if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /** Payer names for a wording, best first. */
    public function payersFor(string $kind, ?string $raw, int $limit = 3): array
    {
        if ($raw === null || trim($raw) === '' || !in_array($kind, self::KINDS, true)) return [];
        return array_slice(self::pick($kind, $raw, $this->rows($kind)), 0, max(1, $limit));
    }

    /** How many confirmations stand behind this wording (any payer). */
    public function timesFor(string $kind, string $raw): int
    {
        $k = self::key($kind, $raw);
        $n = 0;
        foreach ($this->rows($kind) as $r) {
            if ((string)$r['alias_key'] === $k && empty($r['removed_at'])) $n += (int)($r['times'] ?? 1);
        }
        return $n;
    }

    /** Every live alias for a payer (the client page's "Also pays as"). */
    public function aliasesFor(string $payerName, ?int $contactId = null, ?int $companyId = null): array
    {
        if (!$this->ready()) return [];
        $where = ['payer_key = ?'];
        $args = [self::payerKey($payerName)];
        if ($contactId) { $where[] = 'contact_id = ?'; $args[] = $contactId; }
        if ($companyId) { $where[] = 'company_id = ?'; $args[] = $companyId; }
        $s = $this->db->prepare("SELECT id, kind, alias_raw, payer_name, source, times, last_seen, note FROM payer_aliases
                                 WHERE removed_at IS NULL AND (" . implode(' OR ', $where) . ") ORDER BY kind, alias_raw");
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /**
     * Teach one wording → payer. 'tim' rows are the owner's word (a learned row for the same pair
     * is upgraded); a removed pair is never learned back, only re-added by the owner.
     * Returns the alias id, or null when nothing was written.
     */
    public function learn(string $kind, string $raw, string $payerName, string $source = 'learned', array $ids = [], ?string $note = null, ?int $userId = null): ?int
    {
        $key = self::key($kind, $raw);
        $payerName = trim($payerName);
        if (!in_array($kind, self::KINDS, true) || !in_array($source, self::SOURCES, true) || $key === '' || $payerName === '') return null;
        if ($kind === 'bank_memo' && strlen($key) < 3) return null;    // "A" would match half the memos
        $pKey = self::payerKey($payerName);
        $this->cache = null;
        $this->writeLegacy($kind, $raw, $key, $payerName, $pKey, $ids['company_id'] ?? null, $note);
        if (!$this->ready()) return null;

        $s = $this->db->prepare("SELECT id, source, removed_at FROM payer_aliases WHERE kind = ? AND alias_key = ? AND payer_key = ?");
        $s->execute([$kind, $key, $pKey]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['removed_at'] !== null && $source !== 'tim') return null;
            $this->db->prepare("UPDATE payer_aliases SET times = times + 1, last_seen = ?, removed_at = NULL,
                                       source = CASE WHEN ? = 'tim' THEN 'tim' ELSE source END,
                                       contact_id = COALESCE(?, contact_id), company_id = COALESCE(?, company_id),
                                       note = COALESCE(?, note)
                                WHERE id = ?")
                     ->execute([date('Y-m-d H:i:s'), $source, $ids['contact_id'] ?? null, $ids['company_id'] ?? null, $note, (int)$row['id']]);
            return (int)$row['id'];
        }
        $this->db->prepare("INSERT INTO payer_aliases (kind, alias_key, alias_raw, payer_name, payer_key, contact_id, company_id, source, times, last_seen, note, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)")
                 ->execute([$kind, $key, mb_substr(trim($raw), 0, 255), mb_substr($payerName, 0, 255), $pKey,
                            $ids['contact_id'] ?? null, $ids['company_id'] ?? null, $source, date('Y-m-d H:i:s'),
                            $note !== null ? mb_substr($note, 0, 255) : null, $userId, date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    /** The owner removes an alias: kept as removed so Penny never learns it back. */
    public function remove(int $id): bool
    {
        if (!$this->ready() || $id <= 0) return false;
        $this->cache = null;
        $s = $this->db->prepare("UPDATE payer_aliases SET removed_at = ? WHERE id = ? AND removed_at IS NULL");
        $s->execute([date('Y-m-d H:i:s'), $id]);
        $gone = $s->rowCount() > 0;
        if ($gone) {
            // The old tables have no "removed" flag: take it out of them so their readers agree.
            $r = $this->db->prepare("SELECT kind, alias_key, alias_raw, payer_key FROM payer_aliases WHERE id = ?");
            $r->execute([$id]);
            if ($a = $r->fetch(PDO::FETCH_ASSOC)) {
                try {
                    if ($a['kind'] === 'bank_memo') $this->db->prepare("DELETE FROM bank_memo_payers WHERE pattern = ?")->execute([$a['alias_raw']]);
                    if ($a['kind'] === 'etransfer_sender') $this->db->prepare("DELETE FROM etransfer_sender_payers WHERE sender_key = ? AND payer_key = ?")->execute([$a['alias_key'], $a['payer_key']]);
                } catch (Throwable $e) { /* old table gone */ }
            }
        }
        return $gone;
    }

    /** After 1362's SQL copy: recompute every key with this class's rules (SQL can't strip all punctuation). */
    public function rekey(): int
    {
        if (!$this->ready()) return 0;
        $n = 0;
        $up = $this->db->prepare("UPDATE payer_aliases SET alias_key = ?, payer_key = ? WHERE id = ?");
        foreach ($this->db->query("SELECT id, kind, alias_raw, payer_name, alias_key, payer_key FROM payer_aliases")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['kind'] === 'etransfer_sender' ? (string)$r['alias_key'] : self::key((string)$r['kind'], (string)$r['alias_raw']);
            $p = self::payerKey((string)$r['payer_name']);
            if ($k === $r['alias_key'] && $p === $r['payer_key']) continue;
            try {
                $up->execute([$k, $p, (int)$r['id']]);
                $n++;
            } catch (Throwable $e) { /* would duplicate another row: leave it */ }
        }
        $this->cache = null;
        return $n;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->db->query("SELECT 1 FROM payer_aliases LIMIT 0");
                $this->ready = true;
            } catch (Throwable $e) {
                $this->ready = false;
            }
        }
        return $this->ready;
    }

    /** Rows of a kind: payer_aliases, or the old tables before 1362. */
    private function rows(string $kind): array
    {
        if ($this->cache === null) $this->cache = [];
        if (isset($this->cache[$kind])) return $this->cache[$kind];
        $rows = [];
        try {
            if ($this->ready()) {
                $s = $this->db->prepare("SELECT kind, alias_key, payer_name, source, times, removed_at FROM payer_aliases WHERE kind = ? AND removed_at IS NULL");
                $s->execute([$kind]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($kind === 'bank_memo') {
                foreach ($this->db->query("SELECT pattern, payer_name FROM bank_memo_payers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = ['kind' => 'bank_memo', 'alias_key' => self::key('bank_memo', (string)$r['pattern']), 'payer_name' => $r['payer_name'], 'source' => 'learned', 'times' => 1];
                }
            } elseif ($kind === 'etransfer_sender') {
                foreach ($this->db->query("SELECT sender_key, payer_name, payer_key, times FROM etransfer_sender_payers")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $rows[] = ['kind' => 'etransfer_sender', 'alias_key' => $r['sender_key'], 'payer_name' => $r['payer_name'] ?: $r['payer_key'], 'source' => 'learned', 'times' => (int)$r['times']];
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        return $this->cache[$kind] = $rows;
    }

    /** Keep the old tables' readers current until they are dropped (one month after 1362). */
    private function writeLegacy(string $kind, string $raw, string $key, string $payerName, string $pKey, $companyId, ?string $note): void
    {
        try {
            if ($kind === 'bank_memo') {
                $this->db->prepare("INSERT INTO bank_memo_payers (pattern, payer_name, company_id, note, created_at) VALUES (?, ?, ?, ?, ?)")   // an existing pattern is never overwritten (unique key → caught)
                         ->execute([mb_substr($key, 0, 80), mb_substr($payerName, 0, 255), $companyId, $note !== null ? mb_substr($note, 0, 255) : null, date('Y-m-d H:i:s')]);
            } elseif ($kind === 'etransfer_sender') {
                $u = $this->db->prepare("UPDATE etransfer_sender_payers SET times = times + 1, last_seen = ? WHERE sender_key = ? AND payer_key = ?");
                $u->execute([date('Y-m-d H:i:s'), $key, $pKey]);
                if ($u->rowCount() === 0) {
                    $this->db->prepare("INSERT INTO etransfer_sender_payers (sender_key, payer_key, sender_name, payer_name, times, last_seen) VALUES (?, ?, ?, ?, 1, ?)")
                             ->execute([$key, $pKey, mb_substr(trim($raw), 0, 255), mb_substr($payerName, 0, 255), date('Y-m-d H:i:s')]);
                }
            }
        } catch (Throwable $e) { /* old table gone, or a parallel insert won */ }
    }
}
