<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';

/**
 * YuiStatementOffer — someone asked what is owing; can Yui have the statement ready? (2026-11-10)
 *
 * A paralegal asked office@ for the balance on a numbered company and nobody answered. asks() spots the request, decide() says
 * whether a statement is safe to offer, and the card shows the balance, the recipient and a draft. Tim's click sends.
 * A company name or domain in an email is evidence of the account, not proof of who wrote it, so someone not on file is labelled
 * beside the send button. A contact whose invoices span several accounts is refused: the statement would show one client another's.
 * decide() is pure; load() gathers the facts and fails soft (any problem = no offer). Nothing here sends anything.
 */
final class YuiStatementOffer
{
    /** Is this person asking what is owing? On a reply only their own words count, not our subject line. */
    public static function asks(string $subject, string $text): bool
    {
        if (preg_match('/^\s*(fw|fwd)\s*:/i', $subject)) return false;   // passing our own reminder on is not asking us
        $hay = (preg_match('/^\s*re\s*:/i', $subject) ? '' : $subject . "\n") . $text;
        return (bool)preg_match('/\b(?:amounts?|balances?|totals?)\s+(?:(?:now|currently|still)\s+)?(?:owing|owed|outstanding|due)\b'
            . '|\boutstanding\s+(?:balance|amount)\b|\bstatement\s+of\s+account\b'
            . '|\b(?:send|need|get|have|request|provide|copy\s+of)\b.{0,30}\bstatement\b'
            . '|\bhow\s+much\b.{0,40}\b(?:owe|owing|outstanding|due)\b|\bwhat\b.{0,25}\b(?:owe|owing)\b/is', $hay);
    }

    /**
     * @param array{hold?:bool, closing:float, label:string, accounts:array<int,string>, asker:array{name:string,email:string}, on_file:array{name:string,email:string}} $in
     * @return array{offer:bool, why:?string, closing:float, label:string, account:string, recipients:array<int,array{email:string,name:string,on_file:bool}>}
     */
    public static function decide(array $in): array
    {
        $closing = round((float)($in['closing'] ?? 0), 2);
        $label = (string)($in['label'] ?? '');
        $accounts = array_values(array_unique(array_map('strval', (array)($in['accounts'] ?? []))));
        $no = fn(string $why) => ['offer' => false, 'why' => $why, 'closing' => $closing, 'label' => $label, 'account' => '', 'recipients' => []];
        if (!empty($in['hold'])) return $no('That message needs a phone call first.');
        if (!$accounts) return $no('There are no invoices on file to put on a statement.');
        if (count($accounts) > 1 && empty($in['same_place'])) return $no('Their invoices are on ' . count($accounts) . ' accounts, so one statement would show one client another\'s balance. Use the statement page.');

        $fileAddr = strtolower(trim((string)($in['on_file']['email'] ?? '')));
        $askAddr = strtolower(trim((string)($in['asker']['email'] ?? '')));
        $askName = trim((string)($in['asker']['name'] ?? ''));
        $known = $askAddr === $fileAddr || !empty($in['asker_known']);
        // Tim (2026-11-10): "the correct contact has to be sorted first". A balance never goes to someone the account does not
        // know: the person who asked is added as a contact first (YuiJobAdapter shows that step), then the statement.
        if (filter_var($askAddr, FILTER_VALIDATE_EMAIL) && !$known) {
            $o = $no('Not on file for this account yet: add them first.');
            $o['needs_contact'] = ['name' => $askName, 'email' => $askAddr, 'client_id' => (int)($in['client_id'] ?? 0),
                                   'account' => (string)($in['name'] ?? '') ?: $accounts[0]];
            return $o;
        }
        $out = [];
        if (filter_var($askAddr, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['email' => $askAddr, 'name' => $askName ?: $askAddr, 'on_file' => true];
        }
        if (filter_var($fileAddr, FILTER_VALIDATE_EMAIL) && $fileAddr !== $askAddr) {
            $out[] = ['email' => $fileAddr, 'name' => trim((string)($in['on_file']['name'] ?? '')) ?: $fileAddr, 'on_file' => true];
        }
        if (!$out) return $no('There is no email address to send it to.');
        return ['offer' => true, 'why' => null, 'closing' => $closing, 'label' => $label, 'account' => (string)($in['name'] ?? '') ?: $accounts[0], 'recipients' => $out];
    }

    public static function draft(array $offer, string $firstName, string $subject, string $owner): array
    {
        $hi = $firstName !== '' ? $firstName : 'there';
        $clean = UnclaimedReplyService::cleanSubject($subject);
        $money = '$' . number_format((float)$offer['closing'], 2);
        $line = (float)$offer['closing'] > 0
            ? 'The total owing on ' . $offer['account'] . ' is ' . $money . '. The statement is attached and lists each invoice.'
            : 'There is nothing owing on ' . $offer['account'] . ' right now. The statement is attached for your records.';
        return ['subject' => 'Re: ' . ($clean !== '' ? $clean : 'Statement of account'), 'body' => 'Hi ' . $hi . ",\n\n" . $line . "\n\nThank you,\n" . $owner,
                'sms' => '', 'drafted_by' => 'template'];
    }

    /** The offer for one inbox item, or null when it is not asking. Adds the draft under 'draft'. */
    public static function load(PDO $db, array $item, string $owner): ?array
    {
        try {
            $key = (string)($item['message_key'] ?? '');
            $contactId = (int)($item['contact_id'] ?? 0);
            if ($key === '' || $contactId <= 0 || ($item['channel'] ?? 'email') === 'sms') return null;
            $st = $db->prepare('SELECT from_addr, from_name, subject, snippet FROM sales_messages WHERE message_key = ? LIMIT 1');
            $st->execute([$key]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m || !self::asks((string)$m['subject'], (string)$m['snippet'])) return null;

            require_once APP_ROOT . '/Modules/Invoices/Services/StatementService.php';
            require_once dirname(__DIR__, 2) . '/Sales/Services/EmailLeadService.php';
            $svc = new StatementService($db);
            $data = $svc->getStatementData($contactId, null, null, true);
            $name = trim((string)($m['from_name'] ?? ''));
            $cov = $svc->coverage($contactId);
            $known = false;
            $askAddr = strtolower(trim((string)$m['from_addr']));
            if ($askAddr !== '' && $cov['client_ids']) {
                $in = implode(',', array_map('intval', $cov['client_ids']));
                $k = $db->prepare("SELECT 1 FROM contacts c WHERE LOWER(TRIM(c.email)) = ? AND c.merged_into_id IS NULL
                                     AND (c.id = ? OR EXISTS (SELECT 1 FROM client_contacts cc WHERE cc.contact_id = c.id AND cc.client_id IN ({$in}))) LIMIT 1");
                $k->execute([$askAddr, $contactId]);
                $known = (bool)$k->fetchColumn();
            }
            $offer = self::decide(['hold' => !empty($item['hold']), 'closing' => (float)$data['ledger']['closing'], 'label' => (string)$data['period']['label'],
                'accounts' => $cov['accounts'], 'same_place' => $cov['same_place'], 'name' => $cov['name'],
                'client_id' => $cov['client_id'], 'asker_known' => $known, 'asker' => ['name' => $name, 'email' => (string)$m['from_addr']],
                'on_file' => ['name' => (string)($data['recipient']['name'] ?? ''), 'email' => (string)($data['recipient']['email'] ?? '')]]);
            // The greeting is for the person the note goes to by default (the one who asked, now known), never another name.
            $to = $offer['recipients'][0] ?? null;
            $toName = $to && $to['name'] !== $to['email'] ? (string)$to['name'] : '';
            [$first] = $toName !== '' ? EmailLeadService::splitName($toName, (string)$to['email']) : [''];   // never "Hi Rperera" from an address
            $offer['draft'] = $offer['offer'] ? self::draft($offer, $first, (string)$m['subject'], $owner) : null;
            $offer['asker_name'] = $name;
            return $offer;
        } catch (Throwable $e) {
            error_log('[yui statement] ' . $e->getMessage());
            return null;
        }
    }
}
