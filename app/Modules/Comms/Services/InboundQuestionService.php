<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Sales/Services/SalesInboxService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/EmailLeadService.php';

/**
 * InboundQuestionService — "Who is this?" (Yui, 2026-11-10).
 *
 * office@ mail from people not on file waits in inbound_unplaced (migration 1446). The open person rows the system could
 * only half match (medium / weak) are questions for the owner; this answers them in place and teaches the poll:
 *   confirm()    this person is X on that account: contact (never any consent), account link, the mail stored under its own
 *                key; two different people at one business domain make the domain a rule (migration 1447).
 *   notClient()  never ask about this address again (an address rule, never a domain).
 *   undo()       reverses exactly what the decision JSON on the row lists, for 24 hours.
 * Every public write is one transaction. No namespace / no autoloader: require_once and construct.
 */
final class InboundQuestionService
{
    public const UNDO_HOURS = 24;

    private PDO $db;
    private ?bool $ready = null;
    private ?bool $endedOn = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Migration 1446 + 1447 have run. */
    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->db->query('SELECT id, decision FROM inbound_unplaced LIMIT 0');
                $this->db->query('SELECT sender_key, verdict FROM inbound_sender_rules LIMIT 0');
                $this->ready = true;
            } catch (Throwable $e) {
                $this->ready = false;
            }
        }
        return $this->ready;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function questions(int $limit = 20): array
    {
        $st = $this->db->prepare("SELECT * FROM inbound_unplaced WHERE status = 'open' AND kind = 'person' AND strength IN ('medium', 'weak')
                                  ORDER BY sent_at DESC, id DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cands = $this->candidates($r);
            $suggest = $r['client_id'] !== null ? (int)$r['client_id'] : (count($cands) === 1 ? $cands[0]['client_id'] : null);
            [$first, $last] = EmailLeadService::splitName((string)($r['from_name'] ?? ''), (string)$r['from_addr']);
            $out[] = ['id' => (int)$r['id'], 'name' => trim((string)($r['from_name'] ?? '')) !== '' ? (string)$r['from_name'] : (string)$r['from_addr'],
                'addr' => (string)$r['from_addr'], 'subject' => (string)$r['subject'], 'snippet' => (string)($r['snippet'] ?? ''), 'at' => $r['sent_at'],
                'why' => self::why((string)$r['evidence']), 'suggested_client_id' => $suggest, 'candidates' => $cands,
                'first_name' => $first, 'last_name' => $last];
        }
        return $out;
    }

    /** Plain words for the evidence list ("invoice,company"). */
    public static function why(string $evidence): string
    {
        $words = ['invoice' => 'mentions one of their invoices', 'company' => 'names the company', 'address' => 'names the address',
                  'domain' => 'same email domain as someone on the account', 'replied' => 'replied to mail we sent them'];
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $evidence))) as $e) {
            if (isset($words[$e])) $out[] = $words[$e];
        }
        return implode('; ', $out);
    }

    public function quietCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM inbound_unplaced WHERE status = 'open' AND kind = 'person' AND strength = 'none'")->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    public function quiet(int $limit = 30): array
    {
        $st = $this->db->prepare("SELECT id, from_name, from_addr, subject, sent_at FROM inbound_unplaced WHERE status = 'open' AND kind = 'person' AND strength = 'none'
                                  ORDER BY sent_at DESC, id DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute();
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => trim((string)($r['from_name'] ?? '')) !== '' ? (string)$r['from_name'] : (string)$r['from_addr'],
            'addr' => (string)$r['from_addr'], 'subject' => (string)$r['subject'], 'at' => $r['sent_at']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Active accounts whose name contains q (at least 2 characters). @return array<int, array{client_id:int, name:string}> */
    public function accounts(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $st = $this->db->prepare("SELECT id, display_name FROM clients WHERE (status = 'active' OR status IS NULL) AND display_name LIKE ? ESCAPE '!'
                                  ORDER BY display_name LIMIT " . max(1, min(50, $limit)));
        $st->execute(['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%']);
        return array_map(fn($r) => ['client_id' => (int)$r['id'], 'name' => (string)$r['display_name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The accounts this row might be about: its candidates json, else (a reply to our mail) the accounts that contact belongs to. */
    private function candidates(array $r): array
    {
        $out = [];
        $c = json_decode((string)($r['candidates'] ?? ''), true);
        foreach (is_array($c) ? $c : [] as $x) {
            if (isset($x['client_id'])) $out[(int)$x['client_id']] = ['client_id' => (int)$x['client_id'], 'name' => (string)($x['name'] ?? '')];
        }
        if (!$out && $r['contact_id'] !== null) {
            $cid = (int)$r['contact_id'];
            $ended = $this->hasEndedOn() ? ' AND cc.ended_on IS NULL' : '';
            $st = $this->db->prepare("SELECT c.id, c.display_name FROM clients c WHERE (c.status = 'active' OR c.status IS NULL) AND (c.legacy_contact_id = ?
                                      OR c.id IN (SELECT cc.client_id FROM client_contacts cc WHERE cc.contact_id = ?{$ended})) ORDER BY c.display_name");
            $st->execute([$cid, $cid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) $out[(int)$x['id']] = ['client_id' => (int)$x['id'], 'name' => (string)$x['display_name']];
        }
        if (!$out && $r['client_id'] !== null) {
            $st = $this->db->prepare("SELECT id, display_name FROM clients WHERE id = ?");
            $st->execute([(int)$r['client_id']]);
            if ($x = $st->fetch(PDO::FETCH_ASSOC)) $out[(int)$x['id']] = ['client_id' => (int)$x['id'], 'name' => (string)$x['display_name']];
        }
        return array_values($out);
    }

    private function hasEndedOn(): bool
    {
        if ($this->endedOn === null) {
            try {
                $this->db->query('SELECT ended_on FROM client_contacts LIMIT 0');
                $this->endedOn = true;
            } catch (Throwable $e) {
                $this->endedOn = false;
            }
        }
        return $this->endedOn;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Answering
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, message: string, undo_id?: int} */
    public function confirm(int $id, int $clientId, string $first, string $last, int $userId): array
    {
        $first = mb_substr(trim($first), 0, 100);
        $last = mb_substr(trim($last), 0, 100);
        $row = $this->openPerson($id);
        if ($row === null) return $this->no('That one is no longer waiting — refresh.');
        if ($first === '') return $this->no('Add a first name first.');
        $st = $this->db->prepare("SELECT display_name FROM clients WHERE id = ? AND (status = 'active' OR status IS NULL)");
        $st->execute([$clientId]);
        $account = $st->fetchColumn();
        if ($account === false) return $this->no('I can\'t find that account — pick another.');
        $addr = strtolower(trim((string)$row['from_addr']));
        if ($addr === '') return $this->no('That message has no sender address.');

        $now = date('Y-m-d H:i:s');
        $d = ['kind' => 'confirm', 'contact_created' => false, 'link_created' => null, 'messages' => [], 'closed' => [], 'prev' => [],
              'rule_key' => null, 'rule_created' => null, 'rule_replaced' => null];
        $this->db->beginTransaction();
        try {
            // 1. the person
            $cs = $this->db->prepare("SELECT id FROM contacts WHERE LOWER(TRIM(email)) = ? AND merged_into_id IS NULL ORDER BY id LIMIT 1");
            $cs->execute([$addr]);
            $contactId = (int)$cs->fetchColumn();
            if ($contactId === 0) {
                $this->db->prepare("INSERT INTO contacts (first_name, last_name, email, is_active, prospect_status, preferred_contact_method,
                        consent_source, notes, receive_sms, receive_marketing, consent_quote_followup, consent_marketing_email, consent_sms)
                    VALUES (?, ?, ?, 1, 'client', 'email', 'inbox_confirmed', 'Added from an email they sent to the office', 0, 0, 0, 0, 0)")
                    ->execute([$first, $last, $addr]);
                $contactId = (int)$this->db->lastInsertId();
                $d['contact_created'] = true;
            }
            $d['contact_id'] = $contactId;

            // 2. on the account
            $ended = $this->hasEndedOn() ? ' AND ended_on IS NULL' : '';
            $ls = $this->db->prepare("SELECT id FROM client_contacts WHERE client_id = ? AND contact_id = ?{$ended} LIMIT 1");
            $ls->execute([$clientId, $contactId]);
            if (!$ls->fetchColumn()) {
                $this->db->prepare("INSERT INTO client_contacts (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications)
                                    VALUES (?, ?, 'billing', 0, 0, 0)")->execute([$clientId, $contactId]);
                $d['link_created'] = (int)$this->db->lastInsertId();
            }

            // 3. this message and any other open mail from the same address
            $os = $this->db->prepare("SELECT * FROM inbound_unplaced WHERE status = 'open' AND kind = 'person' AND LOWER(from_addr) = ? ORDER BY id");
            $os->execute([$addr]);
            $rows = $os->fetchAll(PDO::FETCH_ASSOC);
            if (!array_filter($rows, fn($x) => (int)$x['id'] === $id)) $rows[] = $row;
            $inbox = new SalesInboxService($this->db);
            foreach ($rows as $x) {
                $res = $inbox->storeConfirmed($x, $contactId, 'confirmed');
                if ($res === 'skipped') throw new RuntimeException('message could not be stored');
                if ($res === 'stored') $d['messages'][] = (string)$x['message_key'];
                $d['prev'][(int)$x['id']] = [$x['client_id'] === null ? null : (int)$x['client_id'], $x['contact_id'] === null ? null : (int)$x['contact_id']];
                if ((int)$x['id'] !== $id) $d['closed'][] = (int)$x['id'];
            }
            $up = $this->db->prepare("UPDATE inbound_unplaced SET status = 'confirmed', decided_by = ?, decided_at = ?, client_id = ?, contact_id = ? WHERE id = ?");
            foreach ($rows as $x) $up->execute([$userId ?: null, $now, $clientId, $contactId, (int)$x['id']]);

            // 4. what this teaches: two different people at one business domain make the domain a rule for the account
            $domain = SalesInboxService::domain($addr);
            if ($domain !== '' && !in_array($domain, SalesInboxService::FREE_DOMAINS, true) && !in_array($domain, SalesInboxService::OUR_DOMAINS, true)) {
                $ps = $this->db->prepare("SELECT DISTINCT LOWER(from_addr) FROM inbound_unplaced WHERE status = 'confirmed' AND client_id = ?");
                $ps->execute([$clientId]);
                $people = array_filter($ps->fetchAll(PDO::FETCH_COLUMN), fn($a) => SalesInboxService::domain((string)$a) === $domain);
                $key = '@' . $domain;
                if ($this->domainOnOtherAccount($domain, $clientId)) {
                    // A property manager's domain serves many accounts: it can never mean this one, and an older rule is now wrong.
                    $this->dropRule($d, $key);
                } elseif (count($people) >= 2) {
                    $d['rule_key'] = $key;
                    $this->putRule($d, $key, 'account', $clientId, $contactId, $userId);
                }
            }

            // 5. the row remembers what it did
            $this->db->prepare("UPDATE inbound_unplaced SET decision = ? WHERE id = ?")->execute([json_encode($d), $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[who] confirm: ' . $e->getMessage());
            return $this->no('That didn\'t save — nothing changed. Try again.');
        }
        $name = trim($first . ' ' . $last);
        return ['ok' => true, 'message' => $name . ' is now on ' . $account . '. Their mail will land there from now on.', 'undo_id' => $id];
    }

    /** @return array{ok: bool, message: string, undo_id?: int} */
    public function notClient(int $id, int $userId): array
    {
        $row = $this->openPerson($id);
        if ($row === null) return $this->no('That one is no longer waiting — refresh.');
        $addr = strtolower(trim((string)$row['from_addr']));
        if ($addr === '') return $this->no('That message has no sender address.');
        $d = ['kind' => 'not_client', 'closed' => [], 'rule_key' => $addr, 'rule_created' => null, 'rule_replaced' => null];
        $this->db->beginTransaction();
        try {
            $this->putRule($d, $addr, 'noise', null, null, $userId);
            $os = $this->db->prepare("SELECT id FROM inbound_unplaced WHERE status = 'open' AND kind = 'person' AND LOWER(from_addr) = ? ORDER BY id");
            $os->execute([$addr]);
            $ids = array_map('intval', $os->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($id, $ids, true)) $ids[] = $id;
            $up = $this->db->prepare("UPDATE inbound_unplaced SET status = 'dismissed', decided_by = ?, decided_at = ? WHERE id = ?");
            $now = date('Y-m-d H:i:s');
            foreach ($ids as $x) {
                $up->execute([$userId ?: null, $now, $x]);
                if ($x !== $id) $d['closed'][] = $x;
            }
            $this->db->prepare("UPDATE inbound_unplaced SET decision = ? WHERE id = ?")->execute([json_encode($d), $id]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[who] not client: ' . $e->getMessage());
            return $this->no('That didn\'t save — nothing changed. Try again.');
        }
        return ['ok' => true, 'message' => 'I won\'t ask about ' . $addr . ' again.', 'undo_id' => $id];
    }

    /** @return array{ok: bool, message: string} */
    public function undo(int $id, int $userId): array
    {
        $st = $this->db->prepare("SELECT * FROM inbound_unplaced WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $d = $row ? json_decode((string)($row['decision'] ?? ''), true) : null;
        if (!$row || !is_array($d)) return $this->no('There is nothing to undo on that one.');
        $at = strtotime((string)($row['decided_at'] ?? '')) ?: 0;
        if ($at < time() - self::UNDO_HOURS * 3600) return $this->no('That was more than a day ago — it can\'t be undone here.');

        $notes = [];
        $this->db->beginTransaction();
        try {
            // the rule: remove the one this created, put back the one it replaced
            if (!empty($d['rule_created'])) {
                $this->db->prepare("DELETE FROM inbound_sender_rules WHERE id = ?")->execute([(int)$d['rule_created']]);
            } elseif (!empty($d['rule_replaced']) && is_array($d['rule_replaced'])) {
                $p = $d['rule_replaced'];
                $this->db->prepare("UPDATE inbound_sender_rules SET verdict = ?, client_id = ?, contact_id = ?, decided_by = ? WHERE sender_key = ?")
                    ->execute([(string)$p['verdict'], $p['client_id'], $p['contact_id'], $p['decided_by'], (string)$p['sender_key']]);
            }
            if (!empty($d['rule_removed']) && is_array($d['rule_removed'])) {
                $p = $d['rule_removed'];
                $this->db->prepare("INSERT INTO inbound_sender_rules (sender_key, verdict, client_id, contact_id, decided_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([(string)$p['sender_key'], (string)$p['verdict'], $p['client_id'], $p['contact_id'], $p['decided_by'], (string)$p['created_at']]);
            }
            // the stored mail, only while it is still untouched
            $kept = 0;
            $handled = $this->hasHandledAt();
            foreach ((array)($d['messages'] ?? []) as $key) {
                $del = $this->db->prepare("DELETE FROM sales_messages WHERE message_key = ?" . ($handled ? ' AND handled_at IS NULL' : ''));
                $del->execute([(string)$key]);
                if ($del->rowCount() === 0) {
                    $ex = $this->db->prepare("SELECT 1 FROM sales_messages WHERE message_key = ?");
                    $ex->execute([(string)$key]);
                    if ($ex->fetchColumn()) $kept++;
                }
            }
            if ($kept > 0) $notes[] = $kept . ' message' . ($kept === 1 ? ' was' : 's were') . ' already handled, so I left ' . ($kept === 1 ? 'it' : 'them');
            if (!empty($d['link_created'])) $this->db->prepare("DELETE FROM client_contacts WHERE id = ?")->execute([(int)$d['link_created']]);
            if (!empty($d['contact_created']) && !empty($d['contact_id'])) {
                if ($this->contactInUse((int)$d['contact_id'])) $notes[] = 'I kept the contact because it now has work or mail on file';
                else $this->db->prepare("DELETE FROM contacts WHERE id = ?")->execute([(int)$d['contact_id']]);
            }
            // the rows go back to waiting, as they were
            $reopen = $this->db->prepare("UPDATE inbound_unplaced SET status = 'open', decided_by = NULL, decided_at = NULL, decision = NULL, client_id = ?, contact_id = ? WHERE id = ? AND status IN ('confirmed', 'dismissed')");
            foreach (array_merge([$id], array_map('intval', (array)($d['closed'] ?? []))) as $rid) {
                $prev = $d['prev'][(string)$rid] ?? $d['prev'][$rid] ?? null;
                $keep = is_array($prev) ? $prev : [$rid === $id ? $row['client_id'] : null, $rid === $id ? $row['contact_id'] : null];
                if (!is_array($prev) && ($d['kind'] ?? '') === 'not_client') {
                    $keep = [null, null];
                    $cur = $this->db->prepare("SELECT client_id, contact_id FROM inbound_unplaced WHERE id = ?");
                    $cur->execute([$rid]);
                    if ($c = $cur->fetch(PDO::FETCH_ASSOC)) $keep = [$c['client_id'], $c['contact_id']];   // a refusal never changed them
                }
                $reopen->execute([$keep[0], $keep[1], $rid]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[who] undo: ' . $e->getMessage());
            return $this->no('That didn\'t undo — nothing changed. Try again.');
        }
        return ['ok' => true, 'message' => 'Undone. They are back on the list.' . ($notes ? ' (' . implode('; ', $notes) . '.)' : '')];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Write a rule, remembering in $d whether it was new or replaced an older one. */
    private function putRule(array &$d, string $key, string $verdict, ?int $clientId, ?int $contactId, int $userId): void
    {
        $st = $this->db->prepare("SELECT id, sender_key, verdict, client_id, contact_id, decided_by FROM inbound_sender_rules WHERE sender_key = ?");
        $st->execute([$key]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if ($old === false) {
            $this->db->prepare("INSERT INTO inbound_sender_rules (sender_key, verdict, client_id, contact_id, decided_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$key, $verdict, $clientId, $contactId, $userId ?: null, date('Y-m-d H:i:s')]);
            $d['rule_created'] = (int)$this->db->lastInsertId();
            return;
        }
        $same = $old['verdict'] === $verdict && ($old['client_id'] === null ? null : (int)$old['client_id']) === $clientId
            && ($old['contact_id'] === null ? null : (int)$old['contact_id']) === $contactId;
        if ($same) return;   // already says this: nothing to undo
        $d['rule_replaced'] = ['sender_key' => $key, 'verdict' => (string)$old['verdict'],
            'client_id' => $old['client_id'] === null ? null : (int)$old['client_id'], 'contact_id' => $old['contact_id'] === null ? null : (int)$old['contact_id'],
            'decided_by' => $old['decided_by'] === null ? null : (int)$old['decided_by']];
        $this->db->prepare("UPDATE inbound_sender_rules SET verdict = ?, client_id = ?, contact_id = ?, decided_by = ? WHERE sender_key = ?")
            ->execute([$verdict, $clientId, $contactId, $userId ?: null, $key]);
    }

    /** Is anyone at this domain on an account other than this one? */
    private function domainOnOtherAccount(string $domain, int $clientId): bool
    {
        $like = '%@' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $domain);
        $st = $this->db->prepare("SELECT COUNT(*) FROM client_contacts cc JOIN contacts c ON c.id = cc.contact_id
                                  WHERE cc.client_id <> ? AND LOWER(TRIM(c.email)) LIKE ? ESCAPE '!'");
        $st->execute([$clientId, $like]);
        if ((int)$st->fetchColumn() > 0) return true;
        $st = $this->db->prepare("SELECT COUNT(*) FROM clients WHERE id <> ? AND LOWER(TRIM(billing_email)) LIKE ? ESCAPE '!'");
        $st->execute([$clientId, $like]);
        return (int)$st->fetchColumn() > 0;
    }

    /** Take a domain's account rule away, remembering it for Undo. */
    private function dropRule(array &$d, string $key): void
    {
        $st = $this->db->prepare("SELECT sender_key, verdict, client_id, contact_id, decided_by, created_at FROM inbound_sender_rules WHERE sender_key = ? AND verdict = 'account'");
        $st->execute([$key]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if ($old === false) return;
        $d['rule_removed'] = $old;
        $this->db->prepare("DELETE FROM inbound_sender_rules WHERE sender_key = ?")->execute([$key]);
    }

    /** @return array<string, mixed>|null the row, only while it is an open person question */
    private function openPerson(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM inbound_unplaced WHERE id = ? AND status = 'open' AND kind = 'person'");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function contactInUse(int $contactId): bool
    {
        foreach (['invoices', 'quotes', 'sales_messages', 'client_contacts', 'inbound_sender_rules'] as $t) {
            $st = $this->db->prepare("SELECT COUNT(*) FROM {$t} WHERE contact_id = ?");
            $st->execute([$contactId]);
            if ((int)$st->fetchColumn() > 0) return true;
        }
        return false;
    }

    private function hasHandledAt(): bool
    {
        try {
            $this->db->query('SELECT handled_at FROM sales_messages LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{ok: false, message: string} */
    private function no(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
