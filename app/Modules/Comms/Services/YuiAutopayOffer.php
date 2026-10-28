<?php
declare(strict_types=1);

/**
 * YuiAutopayOffer — may Yui draft an "set up autopay" note for this overdue account? (Ittai/Mowology, 2026-10-28)
 *
 * The sign-up page (public/customer/autopay.php?token=<invoice access_token>) attaches the card to the contact the invoice is
 * made out to. So the offer is only safe for an individual client who is both the invoice's contact and the person Yui would write
 * to; a strata, company or property-manager account would put someone else's card on the wrong record. Also no point offering it
 * when the client is already on autopay, when there is no email address, when automatic charging is switched off for the whole
 * business (enrolling would do nothing), or when no unpaid invoice has a live link.
 *
 * decide() is pure and unit tested; load() gathers the facts and fails soft (any problem = no offer, with a reason).
 * Nothing here sends anything: Tim reads the draft and presses Send.
 *
 * No namespace / no autoloader: require_once and call statically.
 */
final class YuiAutopayOffer
{
    /**
     * @param array{payer_contact_id:int, invoices:array<int,array{id:int,contact_id:int,company_id:?int,access_token:?string,token_expires_at:?string}>,
     *              contact:?array{autopay_enabled:?int,brand:?string,last4:?string}, live_mode:?bool,
     *              to_contact_id:int, to_email:string, now:string, base_url:string} $in
     * @return array{offer:bool, why:?string, link:?string, card:?string}
     */
    public static function decide(array $in): array
    {
        $no = fn(string $why) => ['offer' => false, 'why' => $why, 'link' => null, 'card' => null];
        $payer = (int)($in['payer_contact_id'] ?? 0);
        if ($payer <= 0) return $no('This account is a company, strata or property manager, not one person.');
        $invoices = (array)($in['invoices'] ?? []);
        if (!$invoices) return $no('No open invoices to attach the sign-up to.');
        foreach ($invoices as $i) {
            if ((int)($i['company_id'] ?? 0) > 0) return $no('The invoices are billed to a company, so the card would go on the wrong record.');
            if ((int)($i['contact_id'] ?? 0) !== $payer) return $no('The invoices are made out to more than one person.');
        }
        $contact = $in['contact'] ?? null;
        if (!$contact) return $no('That contact could not be found.');
        if (!empty($contact['autopay_enabled'])) return $no('Already on autopay.');
        if (($in['live_mode'] ?? null) !== true) return $no('Automatic charging is switched off for the business, so signing up would do nothing yet.');
        if ((int)($in['to_contact_id'] ?? 0) !== $payer) return $no('The note would go to someone other than the person on the invoices.');
        if (trim((string)($in['to_email'] ?? '')) === '') return $no('No email address on file.');

        // The newest open invoice with a link that has not expired.
        usort($invoices, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        $now = (string)($in['now'] ?? date('Y-m-d H:i:s'));
        $token = null;
        foreach ($invoices as $i) {
            $t = trim((string)($i['access_token'] ?? ''));
            if ($t === '') continue;
            $exp = (string)($i['token_expires_at'] ?? '');
            if ($exp !== '' && $exp <= $now) continue;
            $token = $t;
            break;
        }
        if ($token === null) return $no('None of the open invoices has a live invoice link.');

        $brand = trim((string)($contact['brand'] ?? ''));
        $last4 = trim((string)($contact['last4'] ?? ''));
        return [
            'offer' => true, 'why' => null,
            'link'  => rtrim((string)($in['base_url'] ?? 'https://mowology.ca'), '/') . '/customer/autopay.php?token=' . urlencode($token),
            'card'  => $last4 !== '' ? trim(ucfirst($brand !== '' ? $brand : 'Card') . ' ending ' . $last4) : null,
        ];
    }

    /** Works out the facts for one arrears item (YuiRules::arrears shape) and decides. @return array{offer:bool, why:?string, link:?string, card:?string} */
    public static function load(PDO $db, array $item, string $now): array
    {
        $fail = fn(string $why) => ['offer' => false, 'why' => $why, 'link' => null, 'card' => null];
        try {
            if (!preg_match('/^yui:arrears:contact-(\d+)$/', (string)($item['key'] ?? ''), $m)) {
                return $fail('This account is a company, strata or property manager, not one person.');
            }
            $payer = (int)$m[1];
            $ids = array_values(array_filter(array_map(fn($i) => (int)($i['id'] ?? 0), (array)($item['invoices'] ?? []))));
            if (!$ids) return $fail('No open invoices to attach the sign-up to.');
            $in = implode(',', $ids);
            $inv = $db->query("SELECT id, contact_id, company_id, access_token, token_expires_at FROM invoices WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            $c = $db->prepare("SELECT autopay_enabled, stripe_card_brand AS brand, stripe_card_last4 AS last4 FROM contacts WHERE id = ?");
            $c->execute([$payer]);
            $contact = $c->fetch(PDO::FETCH_ASSOC) ?: null;
            $live = null;
            try {
                $v = $db->query("SELECT autopay_live_mode FROM business_settings LIMIT 1")->fetchColumn();
                if ($v !== false && $v !== null) $live = (int)$v === 1;
            } catch (Throwable $e) { /* unknown */ }
            $to = (array)($item['to'] ?? []);
            return self::decide([
                'payer_contact_id' => $payer, 'invoices' => $inv, 'contact' => $contact, 'live_mode' => $live,
                'to_contact_id' => (int)($to['contact_id'] ?? 0), 'to_email' => (string)($to['email'] ?? ''), 'now' => $now,
                'base_url' => defined('SITE_URL') && SITE_URL !== '' ? (string)SITE_URL : 'https://mowology.ca',
            ]);
        } catch (Throwable $e) {
            error_log('YuiAutopayOffer: ' . $e->getMessage());
            return $fail('Could not check this account right now.');
        }
    }
}
