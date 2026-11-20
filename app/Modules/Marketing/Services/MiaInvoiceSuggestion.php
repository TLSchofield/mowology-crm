<?php
declare(strict_types=1);

if (!class_exists('MiaInvoiceLibrary')) {
    require_once __DIR__ . '/MiaInvoiceLibrary.php';
}

/**
 * MiaInvoiceSuggestion — Mia suggests a line for the invoice note; a tap inserts it; taps and results teach her.
 *
 * suggest()   picks the best library entry for the services on the invoice and the month (the season order
 *             stands; what you tapped or what led to work nudges an entry at most one place ahead, like
 *             MiaSeasonThemes::pick). Logs one 'shown' event per invoice+entry. Residential only for now
 *             (audience 'home'); a company invoice gets nothing.
 * record()    'inserted' | 'edited' | 'dismissed' on an event. An entry dismissed at least twice, and more often
 *             than it was inserted, is not suggested again. A review ask is skipped for a contact asked within COOLDOWN_DAYS.
 * setOutcome() 'reached' | 'acted' | 'converted' — what the ask led to (the nightly matcher calls this).
 * Automation  checkAutomation(): when an entry was inserted INSERT_BAR of its last WINDOW showings, Mia proposes a
 *             rule (status 'proposed'); the owner decides 'active' (pre-fill the note), 'not_now' (waits NOT_NOW_SHOWS
 *             more showings) or 'never'. Nothing is ever sent by this: the note is only pre-filled for a person to save.
 *
 * Private to this business: events hold ids, service words and the month, no names. No namespace / autoloader.
 * SQL is MySQL 5.7-safe and runs on SQLite (tests); today and the clock are passed in.
 */
class MiaInvoiceSuggestion
{
    public const WINDOW = 6;
    public const INSERT_BAR = 5;
    public const NOT_NOW_SHOWS = 10;
    public const COOLDOWN_DAYS = 30;

    private PDO $db;
    private DateTimeImmutable $today;

    public function __construct(PDO $db, ?DateTimeImmutable $today = null)
    {
        $this->db = $db;
        $this->today = $today ?? new DateTimeImmutable('now');
    }

    /**
     * @param string[] $serviceNames names of the lines already on the invoice
     * @return array{event_id:int, key:string, method:string, text:string, auto:bool}|null
     */
    public function suggest(array $serviceNames, string $audience, int $invoiceId, int $propertyId, int $contactId, string $firstName, string $reviewUrl, string $phone): ?array
    {
        if ($audience !== 'home') return null;
        $month = (int)$this->today->format('n');
        $struck = $this->struck();
        $stats = $this->stats();
        $contactAsked = $contactId > 0 && $this->reviewAskedRecently($contactId);
        $handled = $this->handledKeys($invoiceId, $propertyId, $contactId);

        $ranked = [];
        foreach (MiaInvoiceLibrary::all() as $i => $e) {
            if (in_array($e['key'], $struck, true) || in_array($e['key'], $handled, true)) continue;
            if (!in_array($month, $e['months'], true)) continue;
            if ($e['needs'] && !MiaInvoiceLibrary::anyMatch($e['needs'], $serviceNames)) continue;
            if ($e['avoid'] && MiaInvoiceLibrary::anyMatch($e['avoid'], $serviceNames)) continue;
            if ($e['method'] === 'review' && ($contactAsked || $reviewUrl === '')) continue;
            $s = $stats[$e['key']] ?? ['inserted' => 0, 'dismissed' => 0, 'converted' => 0];
            if ($s['dismissed'] >= 2 && $s['dismissed'] > $s['inserted']) continue;
            $score = $s['inserted'] + 3 * $s['converted'] - 1.5 * $s['dismissed'];
            $ranked[] = ['e' => $e, 'rank' => -$e['priority'] / 10 + min(1.5, max(0.0, $score) / 3)];
        }
        if (!$ranked) return null;
        usort($ranked, fn($a, $b) => $b['rank'] <=> $a['rank']);
        $e = $ranked[0]['e'];

        $eventId = $this->logShown($e, $serviceNames, $audience, $invoiceId, $propertyId, $contactId, $month);
        return [
            'event_id' => $eventId,
            'key'      => $e['key'],
            'method'   => $e['method'],
            'text'     => MiaInvoiceLibrary::render($e['text'], ['first_name' => $firstName !== '' ? $firstName : 'there', 'review_url' => $reviewUrl, 'phone' => $phone]),
            'auto'     => $this->ruleStatus($e['key']) === 'active',
        ];
    }

    public function record(int $eventId, string $action): bool
    {
        if (!in_array($action, ['inserted', 'edited', 'dismissed'], true)) return false;
        $st = $this->db->prepare("SELECT library_key FROM mia_invoice_suggestion_events WHERE id = ?");
        $st->execute([$eventId]);
        $key = $st->fetchColumn();
        if ($key === false) return false;
        $this->db->prepare("UPDATE mia_invoice_suggestion_events SET action = ?, acted_at = ? WHERE id = ?")
            ->execute([$action, $this->now(), $eventId]);
        if ($action === 'inserted') $this->checkAutomation((string)$key);
        return true;
    }

    public function setOutcome(int $eventId, string $outcome): bool
    {
        if (!in_array($outcome, ['reached', 'acted', 'converted'], true)) return false;
        $st = $this->db->prepare("UPDATE mia_invoice_suggestion_events SET outcome = ?, outcome_at = ? WHERE id = ?");
        $st->execute([$outcome, $this->now(), $eventId]);
        return $st->rowCount() > 0;
    }

    /** Proposes a rule when the entry was inserted INSERT_BAR of its last WINDOW showings. @return array|null the rule row */
    public function checkAutomation(string $key): ?array
    {
        $st = $this->db->prepare("SELECT action, outcome FROM mia_invoice_suggestion_events WHERE library_key = ? ORDER BY id DESC LIMIT " . self::WINDOW);
        $st->execute([$key]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < self::WINDOW) return null;
        $ins = count(array_filter($rows, fn($r) => in_array($r['action'], ['inserted', 'edited'], true)));
        $conv = count(array_filter($rows, fn($r) => $r['outcome'] === 'converted'));
        if ($ins < self::INSERT_BAR) return null;

        $total = $this->shownCount($key);
        $rule = $this->rule($key);
        if ($rule) {
            if ($rule['status'] === 'not_now' && $total - (int)$rule['shown_at_decision'] >= self::NOT_NOW_SHOWS) {
                $this->db->prepare("UPDATE mia_invoice_rules SET status = 'proposed', evidence_shown = ?, evidence_inserted = ?, evidence_converted = ? WHERE id = ?")
                    ->execute([count($rows), $ins, $conv, $rule['id']]);
                return $this->rule($key);
            }
            return null;   // proposed / active / never: nothing new to say
        }
        $this->db->prepare("INSERT INTO mia_invoice_rules (library_key, status, evidence_shown, evidence_inserted, evidence_converted, created_at) VALUES (?, 'proposed', ?, ?, ?, ?)")
            ->execute([$key, count($rows), $ins, $conv, $this->now()]);
        return $this->rule($key);
    }

    /** @return list<array{id:int, library_key:string, evidence_shown:int, evidence_inserted:int, evidence_converted:int}> */
    public function pendingProposals(): array
    {
        $rows = $this->db->query("SELECT id, library_key, evidence_shown, evidence_inserted, evidence_converted FROM mia_invoice_rules WHERE status = 'proposed' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'library_key' => $r['library_key'], 'evidence_shown' => (int)$r['evidence_shown'],
            'evidence_inserted' => (int)$r['evidence_inserted'], 'evidence_converted' => (int)$r['evidence_converted']], $rows);
    }

    public function decideRule(int $ruleId, string $decision): bool
    {
        if (!in_array($decision, ['active', 'not_now', 'never'], true)) return false;
        $st = $this->db->prepare("SELECT library_key FROM mia_invoice_rules WHERE id = ? AND status = 'proposed'");
        $st->execute([$ruleId]);
        $key = $st->fetchColumn();
        if ($key === false) return false;
        $this->db->prepare("UPDATE mia_invoice_rules SET status = ?, shown_at_decision = ?, decided_at = ? WHERE id = ?")
            ->execute([$decision, $this->shownCount((string)$key), $this->now(), $ruleId]);
        return true;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function now(): string { return $this->today->format('Y-m-d H:i:s'); }

    /** @return string[] */
    private function struck(): array
    {
        try {
            $st = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = 'mia_invoice_library_struck'");
            $st->execute();
            $v = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }

    /** @return array<string, array{inserted:int, dismissed:int, converted:int}> */
    private function stats(): array
    {
        $out = [];
        $rows = $this->db->query("SELECT library_key, action, outcome FROM mia_invoice_suggestion_events")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = $r['library_key'];
            $out[$k] = $out[$k] ?? ['inserted' => 0, 'dismissed' => 0, 'converted' => 0];
            if ($r['action'] === 'inserted' || $r['action'] === 'edited') $out[$k]['inserted']++;
            if ($r['action'] === 'dismissed') $out[$k]['dismissed']++;
            if ($r['outcome'] === 'converted') $out[$k]['converted']++;
        }
        return $out;
    }

    private function reviewAskedRecently(int $contactId): bool
    {
        $since = $this->today->modify('-' . self::COOLDOWN_DAYS . ' days')->format('Y-m-d H:i:s');
        $st = $this->db->prepare("SELECT COUNT(*) FROM mia_invoice_suggestion_events WHERE contact_id = ? AND method = 'review' AND action IN ('inserted', 'edited') AND acted_at >= ?");
        $st->execute([$contactId, $since]);
        return (int)$st->fetchColumn() > 0;
    }

    private function logShown(array $e, array $serviceNames, string $audience, int $invoiceId, int $propertyId, int $contactId, int $month): int
    {
        if ($invoiceId > 0) {
            $st = $this->db->prepare("SELECT id FROM mia_invoice_suggestion_events WHERE invoice_id = ? AND library_key = ? ORDER BY id LIMIT 1");
            $st->execute([$invoiceId, $e['key']]);
            $id = $st->fetchColumn();
            if ($id !== false) return (int)$id;
        }
        elseif ($propertyId > 0) {
            // A new invoice has no id yet: a page reload the same day is the same showing, not a new one.
            $st = $this->db->prepare("SELECT id FROM mia_invoice_suggestion_events WHERE invoice_id = 0 AND property_id = ? AND contact_id = ? AND library_key = ? AND action = 'shown' AND shown_at >= ? ORDER BY id DESC LIMIT 1");
            $st->execute([$propertyId, $contactId, $e['key'], $this->today->format('Y-m-d') . ' 00:00:00']);
            $id = $st->fetchColumn();
            if ($id !== false) return (int)$id;
        }
        $this->db->prepare("INSERT INTO mia_invoice_suggestion_events (invoice_id, property_id, contact_id, library_key, method, wording_version, services, season_month, audience, action, outcome, shown_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'shown', 'none', ?)")
            ->execute([$invoiceId, $propertyId, $contactId, $e['key'], $e['method'], $e['version'],
                       substr(implode(',', array_map('strval', $serviceNames)), 0, 255), $month, $audience, $this->now()]);
        return (int)$this->db->lastInsertId();
    }

    /** Entries already tapped (inserted, edited, dismissed) on this invoice, or today for a not-yet-saved one: never offered twice. @return string[] */
    private function handledKeys(int $invoiceId, int $propertyId, int $contactId): array
    {
        if ($invoiceId > 0) {
            $st = $this->db->prepare("SELECT library_key FROM mia_invoice_suggestion_events WHERE invoice_id = ? AND action <> 'shown'");
            $st->execute([$invoiceId]);
        } elseif ($propertyId > 0) {
            $st = $this->db->prepare("SELECT library_key FROM mia_invoice_suggestion_events WHERE invoice_id = 0 AND property_id = ? AND contact_id = ? AND action <> 'shown' AND shown_at >= ?");
            $st->execute([$propertyId, $contactId, $this->today->format('Y-m-d') . ' 00:00:00']);
        } else {
            return [];
        }
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    private function shownCount(string $key): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM mia_invoice_suggestion_events WHERE library_key = ?");
        $st->execute([$key]);
        return (int)$st->fetchColumn();
    }

    private function rule(string $key): ?array
    {
        $st = $this->db->prepare("SELECT * FROM mia_invoice_rules WHERE library_key = ?");
        $st->execute([$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function ruleStatus(string $key): ?string
    {
        $r = $this->rule($key);
        return $r ? (string)$r['status'] : null;
    }
}
