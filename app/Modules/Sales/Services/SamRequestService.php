<?php
/** A known client's inbound work request, its property and the best deterministic price precedent. */
require_once __DIR__ . '/SamRequestRules.php';
require_once __DIR__ . '/JobberLineParser.php';
require_once __DIR__ . '/CloserPricing.php';
require_once dirname(__DIR__, 2) . '/Agents/Services/AgentActivity.php';
require_once dirname(__DIR__, 3) . '/Services/HeadLessons.php';

class SamRequestService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM sam_requests LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Detect or refresh an open request. Never interrupts mail ingestion. */
    public function propose(string $messageKey): ?int
    {
        try {
            if (!$this->ready()) return null;
            $s = $this->db->prepare("SELECT message_key, contact_id, subject, snippet, quote_id FROM sales_messages
                                      WHERE message_key = ? AND direction = 'inbound' AND contact_id IS NOT NULL LIMIT 1");
            $s->execute([$messageKey]);
            $m = $s->fetch(PDO::FETCH_ASSOC);
            if (!$m) return null;
            $find = $this->db->prepare("SELECT id, status FROM sam_requests WHERE message_key = ? LIMIT 1");
            $find->execute([$messageKey]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            $signal = SamRequestRules::isRequest((string)$m['subject'], (string)$m['snippet']);
            // An open request must still absorb a later negation ("do not need it now")
            // even though that follow-up is no longer classified as a sales request.
            if (empty($signal['yes']) && (!$old || $old['status'] !== 'open')) return null;

            $services = SamRequestRules::services((string)$m['subject'] . "\n" . (string)$m['snippet']);
            $negation = SamRequestRules::negated((string)$m['snippet']);
            // No service named: a thanks, an approval or a chat — not a card (replay 2026-11-05: most "not sure" hits were these).
            if (!$services && !$old) return null;
            $property = $this->properties((int)$m['contact_id'], isset($m['quote_id']) ? (int)$m['quote_id'] : 0);
            $propertyId = count($property['ids']) === 1 ? (int)$property['ids'][0] : null;
            $precedent = count($services) === 1 ? $this->precedent($services[0], $propertyId) : ['source' => 'none', 'date' => null, 'price' => null, 'count' => 0, 'lines' => []];
            $level = SamRequestRules::level([
                'known_client' => true, 'properties' => count($property['ids']), 'services' => $services,
                'has_history' => in_array($precedent['source'], ['property_jobber', 'property_quote'], true),
                'negation' => $negation, 'is_request' => true,
            ]);
            $hasHistory = in_array($precedent['source'], ['property_jobber', 'property_quote'], true);
            $evidence = [
                'is_request' => true, 'why' => (string)($signal['why'] ?? ''), 'services' => $services,
                'negation' => $negation, 'property_count' => count($property['ids']),
                'property_via' => $property['via'], 'has_history' => $hasHistory, 'reasons' => $level['reasons'],
            ];
            $service = count($services) === 1 ? $services[0] : null;
            $now = date('Y-m-d H:i:s');
            if ($old) {
                if ($old['status'] === 'open') {
                    $this->db->prepare("UPDATE sam_requests SET contact_id = ?, property_id = ?, service_key = ?, level = ?, evidence_json = ?, precedent_json = ?, updated_at = ? WHERE id = ?")
                        ->execute([(int)$m['contact_id'], $propertyId, $service, $level['level'], self::json($evidence), self::json($precedent), $now, (int)$old['id']]);
                }
                return (int)$old['id'];
            }
            $this->db->prepare("INSERT INTO sam_requests (message_key, contact_id, property_id, service_key, level, evidence_json, precedent_json, status, created_at, updated_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)")
                ->execute([$messageKey, (int)$m['contact_id'], $propertyId, $service, $level['level'], self::json($evidence), self::json($precedent), $now, $now]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('Sam request check (' . $messageKey . '): ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{ids:array,via:?string} */
    private function properties(int $contactId, int $quoteId): array
    {
        $ids = []; $via = null;
        $add = static function ($id, string $source) use (&$ids, &$via): void {
            $id = (int)$id;
            if ($id > 0 && !isset($ids[$id])) { $ids[$id] = $id; if ($via === null) $via = $source; }
        };
        if ($quoteId > 0) {
            try { $s = $this->db->prepare("SELECT property_id FROM quotes WHERE id = ?"); $s->execute([$quoteId]); $add($s->fetchColumn(), 'message_quote'); } catch (Throwable $e) {}
            if ($ids) return ['ids' => array_values($ids), 'via' => $via];
        }
        foreach ([['properties', 'site_contact_id', 'property_contact'], ['properties', 'client_id', 'property_client'], ['property_contacts', 'contact_id', 'property_contacts']] as $q) {
            try {
                $column = $q[0] === 'property_contacts' ? 'property_id' : 'id';
                $s = $this->db->prepare("SELECT {$column} AS property_id FROM {$q[0]} WHERE {$q[1]} = ?");
                $s->execute([$contactId]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['property_id'], $q[2]);
            } catch (Throwable $e) {}
        }
        if ($ids) return ['ids' => array_values($ids), 'via' => $via];
        foreach (['invoices', 'jobs', 'jobber_invoices', 'quotes'] as $table) {
            try {
                $s = $this->db->prepare("SELECT DISTINCT property_id FROM {$table} WHERE contact_id = ? AND property_id IS NOT NULL");
                $s->execute([$contactId]);
                foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $add($id, $table);
            } catch (Throwable $e) {}
        }
        return ['ids' => array_values($ids), 'via' => $via];
    }

    private function precedent(string $key, ?int $propertyId): array
    {
        if ($propertyId) {
            try {
                $s = $this->db->prepare("SELECT * FROM jobber_invoices WHERE property_id = ?");
                $s->execute([$propertyId]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC); $this->newestFirst($rows);
                foreach ($rows as $r) {
                    $lines = JobberLineParser::forService(JobberLineParser::parse($r['line_items'] ?? null), $key);
                    if ($lines) return $this->linePrecedent('property_jobber', $lines, self::rowDate($r));
                }
            } catch (Throwable $e) {}
            try {
                $s = $this->db->prepare("SELECT q.id AS quote_id, q.accepted_at, q.created_at, li.description, li.service_type, li.quantity, li.unit_price, li.line_total
                    FROM quotes q JOIN quote_line_items li ON li.quote_id = q.id
                    WHERE q.property_id = ? AND q.status = 'accepted' ORDER BY COALESCE(q.accepted_at, q.created_at) DESC, li.sort_order");
                $s->execute([$propertyId]);
                $byQuote = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!in_array($key, CloserPricing::serviceKeys((string)$r['description'] . ' ' . (string)$r['service_type']), true)) continue;
                    $qid = (int)$r['quote_id'];
                    if (!isset($byQuote[$qid])) $byQuote[$qid] = ['date' => self::rowDate($r), 'lines' => []];
                    $byQuote[$qid]['lines'][] = self::quoteLine($r);
                }
                foreach ($byQuote as $quote) if ($quote['lines']) return $this->linePrecedent('property_quote', $quote['lines'], $quote['date']);
            } catch (Throwable $e) {}
        }
        $samples = $this->similar($key);
        if ($samples) {
            usort($samples, static fn($a, $b) => $a['amount'] <=> $b['amount']);
            $n = count($samples); $mid = intdiv($n, 2);
            $price = $n % 2 ? $samples[$mid]['amount'] : ($samples[$mid - 1]['amount'] + $samples[$mid]['amount']) / 2;
            return ['source' => 'similar', 'date' => null, 'price' => round($price, 2), 'count' => $n,
                    'dates' => array_values(array_column($samples, 'date')), 'lines' => []];
        }
        return ['source' => 'none', 'date' => null, 'price' => null, 'count' => 0, 'lines' => []];
    }

    private function similar(string $key): array
    {
        $all = []; $cut = strtotime('-24 months');
        try {
            $rows = $this->db->query("SELECT q.accepted_at, q.created_at, li.description, li.service_type, li.quantity, li.unit_price, li.line_total
                FROM quotes q JOIN quote_line_items li ON li.quote_id = q.id WHERE q.status = 'accepted'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $date = self::rowDate($r); $amount = self::lineAmount($r);
                if (strtotime($date) >= $cut && $amount > 0 && in_array($key, CloserPricing::serviceKeys((string)$r['description'] . ' ' . (string)$r['service_type']), true)) $all[] = ['amount' => $amount, 'date' => $date];
            }
        } catch (Throwable $e) {}
        try {
            $rows = $this->db->query("SELECT * FROM jobber_invoices")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $date = self::rowDate($r); if (strtotime($date) < $cut) continue;
                foreach (JobberLineParser::forService(JobberLineParser::parse($r['line_items'] ?? null), $key) as $line) if ((float)$line['amount'] > 0) $all[] = ['amount' => (float)$line['amount'], 'date' => $date];
            }
        } catch (Throwable $e) {}
        usort($all, static fn($a, $b) => strcmp($b['date'], $a['date']));
        return array_slice($all, 0, 5);
    }

    public function open(int $limit = 10): array
    {
        if (!$this->ready()) return [];
        try {
            $s = $this->db->prepare("SELECT r.*, m.from_name, m.from_addr, m.subject, m.snippet, m.sent_at,
                       c.first_name, c.last_name, p.address
                FROM sam_requests r JOIN sales_messages m ON m.message_key = r.message_key
                LEFT JOIN contacts c ON c.id = r.contact_id LEFT JOIN properties p ON p.id = r.property_id
                WHERE r.status = 'open' ORDER BY r.created_at DESC LIMIT ?");
            $s->bindValue(1, max(1, min(100, $limit)), PDO::PARAM_INT); $s->execute();
            $out = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $e = self::decode($r['evidence_json'] ?? null); $p = self::decode($r['precedent_json'] ?? null);
                $from = trim((string)($r['from_name'] ?? '')) ?: trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: (string)$r['from_addr'];
                $out[] = ['id' => (int)$r['id'], 'from' => $from, 'subject' => (string)$r['subject'],
                    'snippet' => mb_substr((string)$r['snippet'], 0, 160), 'sent_at' => $r['sent_at'], 'contact_id' => (int)$r['contact_id'],
                    'property' => $r['property_id'] ? ['id' => (int)$r['property_id'], 'address' => (string)$r['address']] : null,
                    'property_count' => (int)($e['property_count'] ?? 0), 'service_key' => (string)($r['service_key'] ?? ''),
                    'service_label' => self::serviceLabel((string)($r['service_key'] ?? '')), 'level' => (string)$r['level'],
                    'reasons' => array_values((array)($e['reasons'] ?? [])), 'precedent' => $p + ['source' => 'none', 'date' => null, 'price' => null, 'count' => 0, 'lines' => []]];
            }
            return $out;
        } catch (Throwable $e) { error_log('Sam requests open: ' . $e->getMessage()); return []; }
    }

    public function makeDraft(int $id, array $user): array
    {
        return AgentActivity::run($this->db, ['head' => 'sam', 'action' => 'sam.prepare_quote', 'skill' => 'prepare_quote',
            'entity_type' => 'sam_request', 'entity_id' => (string)$id, 'user_id' => (int)($user['id'] ?? 0),
            'approved' => true, 'reversible' => true], fn() => $this->doMakeDraft($id, $user));
    }

    private function doMakeDraft(int $id, array $user): array
    {
        $this->db->beginTransaction();
        try {
            $r = $this->row($id, true);
            if (!$r) { $this->db->commit(); return ['ok' => false, 'message' => 'That request is no longer available.']; }
            if ($r['status'] === 'drafted' && (int)$r['draft_quote_id'] > 0) {
                $this->db->commit();
                return ['ok' => true, 'quote_id' => (int)$r['draft_quote_id'], 'url' => '/crm/quotes/view.php?id=' . (int)$r['draft_quote_id']];
            }
            if ($r['status'] !== 'open') { $this->db->commit(); return ['ok' => false, 'message' => 'Already decided.']; }
            if (!(int)$r['property_id']) { $this->db->commit(); return ['ok' => false, 'message' => 'Pick the property first — open the client and start a quote.']; }
            $p = self::decode($r['precedent_json'] ?? null);
            // The business's own GST rate (TaxEngine: business_settings.gst_rate), as quote lines carry a percent.
            require_once dirname(__DIR__, 2) . '/Accounting/Services/TaxEngine.php';
            $lines = self::draftLines($p, (string)$r['service_key'], round(TaxEngine::gstRate($this->db) * 100, 2));
            $address = (string)($r['address'] ?? '');
            $data = ['property_id' => (int)$r['property_id'], 'contact_id' => (int)$r['contact_id'], 'title' => self::serviceLabel((string)$r['service_key']) . ' at ' . $address,
                'service_type' => (string)$r['service_key'], 'valid_until' => null, 'terms' => '', 'notes_customer' => '',
                'notes_internal' => self::precedentNote($p), 'description' => '', 'is_contract' => 0];
            $quoteId = $this->createQuote($data, $lines, (int)($user['id'] ?? 0));
            $this->db->prepare("UPDATE sam_requests SET status = 'drafted', draft_quote_id = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status = 'open'")
                ->execute([$quoteId, (int)($user['id'] ?? 0) ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            $this->db->commit();
            return ['ok' => true, 'quote_id' => $quoteId, 'url' => '/crm/quotes/view.php?id=' . $quoteId];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Kept overridable so orchestration can be unit-tested without QuoteService's MySQL-only SQL. */
    protected function createQuote(array $data, array $lines, int $userId): int
    {
        require_once dirname(__DIR__, 2) . '/Quotes/Services/QuoteService.php';
        return (new QuoteService($this->db))->create($data, $lines, $userId);
    }

    /** Pure line builder, also keeping draft creation testable without MySQL-specific QuoteService SQL. */
    /** The line on the draft (staff only) that says where the price came from. Pure. */
    public static function precedentNote(array $p): string
    {
        $source = (string)($p['source'] ?? 'none');
        $when = !empty($p['date']) ? date('M Y', strtotime((string)$p['date'])) : null;
        if ($source === 'property_jobber') return 'Sam: price from this property\'s Jobber invoice' . ($when ? ' (' . $when . ')' : '') . ' — check it is still right before sending.';
        if ($source === 'property_quote') return 'Sam: price from this property\'s accepted quote' . ($when ? ' (' . $when . ')' : '') . ' — check it is still right before sending.';
        if ($source === 'similar') return 'Sam: price is the middle of ' . (int)($p['count'] ?? 0) . ' similar recent quotes — not this property; check before sending.';
        return 'Sam: no price on record for this service — set the price before sending.';
    }

    public static function draftLines(array $precedent, string $key, float $taxPct = 5.0): array
    {
        $source = (string)($precedent['source'] ?? 'none'); $out = [];
        if (in_array($source, ['property_jobber', 'property_quote'], true) && !empty($precedent['lines'])) {
            $note = !empty($precedent['date']) ? 'from ' . date('M Y', strtotime((string)$precedent['date'])) : 'from price history';
            foreach ($precedent['lines'] as $line) {
                $qty = (float)($line['qty'] ?? 1); if ($qty == 0.0) $qty = 1.0;
                $amount = (float)($line['amount'] ?? 0);
                $out[] = self::draftLine((string)($line['name'] ?? self::serviceLabel($key)), $key, $qty, $amount / $qty, $note, $taxPct);
            }
        } elseif ($source === 'similar') {
            $out[] = self::draftLine(self::serviceLabel($key), $key, 1, (float)($precedent['price'] ?? 0), 'from ' . (int)($precedent['count'] ?? 0) . ' similar quotes', $taxPct);
        } else {
            $out[] = self::draftLine(self::serviceLabel($key), $key, 1, 0, 'no price on record', $taxPct);
        }
        return $out;
    }

    public function dismiss(int $id, string $reason, array $user): array
    {
        if (!in_array($reason, ['not_request', 'wrong_service'], true)) return ['ok' => false, 'message' => 'Choose a reason.'];
        $r = $this->row($id); if (!$r || $r['status'] !== 'open') return ['ok' => false, 'message' => 'Already decided.'];
        $s = $this->db->prepare("UPDATE sam_requests SET status = 'dismissed', dismiss_reason = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status = 'open'");
        $s->execute([$reason, (int)($user['id'] ?? 0) ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        if ($s->rowCount()) HeadLessons::recordQuietly($this->db, ['head' => 'sam', 'subject_kind' => 'request_phrase',
            'subject_key' => HeadLessons::normaliseKey((string)($r['snippet'] ?? '')), 'field' => $reason === 'not_request' ? 'is_request' : 'service',
            'said' => $reason === 'not_request' ? 'yes' : (string)$r['service_key'], 'correct' => $reason === 'not_request' ? 'no' : null,
            'source' => 'sam_request_dismiss', 'ref_type' => 'sam_request', 'ref_id' => $id, 'corrected_by' => (int)($user['id'] ?? 0)]);
        return ['ok' => $s->rowCount() > 0, 'message' => $s->rowCount() ? 'OK — dismissed.' : 'Already decided.'];
    }

    public function recordOutcome(int $quoteId): void
    {
        try {
            $s = $this->db->prepare("SELECT * FROM sam_requests WHERE draft_quote_id = ? AND status = 'drafted' LIMIT 1"); $s->execute([$quoteId]);
            $r = $s->fetch(PDO::FETCH_ASSOC); if (!$r) return;
            $q = $this->db->prepare("SELECT property_id, service_type, amount, subtotal FROM quotes WHERE id = ?"); $q->execute([$quoteId]); $quote = $q->fetch(PDO::FETCH_ASSOC); if (!$quote) return;
            $l = $this->db->prepare("SELECT COUNT(*) FROM quote_line_items WHERE quote_id = ?"); $l->execute([$quoteId]); $sentLines = (int)$l->fetchColumn();
            $p = self::decode($r['precedent_json'] ?? null); $saidLines = count(self::draftLines($p, (string)$r['service_key']));
            $saidPrice = self::outcomeNumber($p['price'] ?? 0); $sentPrice = self::outcomeNumber($quote['amount']);
            $pairs = ['property' => [(int)$r['property_id'], (int)$quote['property_id']], 'service' => [(string)$r['service_key'], (string)$quote['service_type']],
                'lines' => [$saidLines, $sentLines], 'price' => [$saidPrice, $sentPrice]];
            $out = ['said' => [], 'sent' => []];
            foreach ($pairs as $field => $v) {
                $changed = $field === 'price' ? abs((float)$v[0] - (float)$v[1]) >= 0.005 : $v[0] !== $v[1];
                $out[$field] = $changed ? 'changed' : 'accepted';
                $out['said'][$field] = $v[0];
                $out['sent'][$field] = $v[1];
                if ($changed) HeadLessons::recordQuietly($this->db, ['head' => 'sam', 'subject_kind' => 'service', 'subject_key' => (string)$r['service_key'],
                    'field' => $field, 'said' => $v[0], 'correct' => $v[1], 'source' => 'sam_request_sent', 'ref_type' => 'quote', 'ref_id' => $quoteId,
                    'corrected_by' => (int)($r['decided_by'] ?? 0)]);
            }
            $this->db->prepare("UPDATE sam_requests SET outcome_json = ?, status = 'sent', updated_at = ? WHERE id = ? AND status = 'drafted'")
                ->execute([self::json($out), date('Y-m-d H:i:s'), (int)$r['id']]);
        } catch (Throwable $e) { error_log('Sam request outcome (' . $quoteId . '): ' . $e->getMessage()); }
    }

    public static function serviceLabel(string $key): string
    {
        $labels = ['cleanup' => 'Cleanup', 'hedge' => 'Hedge & tree trimming', 'snow' => 'Snow removal', 'salting' => 'Salting',
            'aeration' => 'Aeration', 'overseed' => 'Overseeding', 'beds' => 'Garden beds', 'edge' => 'Edging', 'mow' => 'Mowing'];
        return $labels[$key] ?? ucfirst($key);
    }

    private function row(int $id, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $s = $this->db->prepare("SELECT r.*, m.snippet, p.address FROM sam_requests r LEFT JOIN sales_messages m ON m.message_key = r.message_key LEFT JOIN properties p ON p.id = r.property_id WHERE r.id = ?" . $lock);
        $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    private function linePrecedent(string $source, array $lines, string $date): array { $price = array_sum(array_column($lines, 'amount')); return ['source' => $source, 'date' => $date, 'price' => $price == 0.0 ? null : round($price, 2), 'count' => count($lines), 'lines' => array_values($lines)]; }
    private function newestFirst(array &$rows): void { usort($rows, static fn($a, $b) => strcmp(self::rowDate($b), self::rowDate($a))); }
    private static function rowDate(array $r): string { return (string)($r['accepted_at'] ?? $r['issued_at'] ?? $r['invoice_date'] ?? $r['date'] ?? $r['created_at'] ?? '1970-01-01'); }
    private static function lineAmount(array $r): float { return isset($r['line_total']) ? (float)$r['line_total'] : (float)($r['quantity'] ?? 1) * (float)($r['unit_price'] ?? 0); }
    /** Keep whole-dollar outcome values as integers while retaining decimal prices. */
    private static function outcomeNumber($value) { $number = (float)$value; return floor($number) === $number ? (int)$number : $number; }
    private static function quoteLine(array $r): array { return ['name' => (string)($r['description'] ?? ''), 'qty' => (float)($r['quantity'] ?? 1), 'amount' => self::lineAmount($r)]; }
    private static function draftLine(string $name, string $key, float $qty, float $unit, string $note, float $taxPct): array { return ['service_type' => $key, 'description' => $name, 'quantity' => $qty, 'unit_type' => 'each', 'unit_price' => round($unit, 2), 'line_total' => round($qty * $unit, 2), 'tax_rate' => $taxPct, 'note' => $note, 'pricing_snapshot' => self::json(['note' => $note])]; }
    private static function json(array $v): string { return (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
    private static function decode($v): array { $x = json_decode((string)$v, true); return is_array($x) ? $x : []; }
}
