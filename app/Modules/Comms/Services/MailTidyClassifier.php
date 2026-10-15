<?php
/**
 * MailTidyClassifier — where one email in Tim's iCloud belongs, the way a senior assistant
 * would file it. PURE: no IMAP, no database. The folder plan and the sender rules come in as
 * a $plan built from mail_tidy_rules rows (fromRows), or the defaults below.
 *
 * Output: the target folder + a confidence (0–100) + a short reason + the basis it rests on.
 * Below the plan's min_confidence (default 70) the mail STAYS where it is. Nothing here moves
 * anything — MailTidyService does, and only through ImapWriter.
 *
 * First match wins:
 *   1. Interac / Yardi sender (or an Interac subject)          → Payments          95 / 80
 *   2. To or from a CRM contact                                 → Clients           90
 *   3. The CRM's own robots (no-reply@ / crm@ … @mowology.ca)     → Mowology CRM      85
 *      Tim writing to himself                                   → stays
 *   4. An exact sender address rule                             → its folder        90
 *   5. A sender domain rule (domain or any subdomain)           → its folder        85
 *        a supplier domain with receipt words                   → Receipts          85
 *   6. A CRM vendor (VendorMessageService::matchVendor)         → Receipts / Suppliers 80
 *   7. A subject phrase rule (Wing Chun, Search Console…)       → its folder        75
 *   8. A strong work enquiry (EmailLeadService::score)          → Enquiries         80
 *      …never a sales pitch TO us ("custom engraved gifts for MOWOLOGY", SEO offers) nor a
 *      payment / receipt notice — pitches go to Newsletters & Promos (list mail) or stay
 *   9. A robot's e-receipt / order confirmation                 → Receipts          70
 *  10. Mailing-list headers (List-Unsubscribe, List-Id, bulk)    → Newsletters       75
 *      a marketing sender name (news@, promo@…)                 → Newsletters       70
 *  11. Anything else                                             → stays (unsure)
 *
 * Junk check (junkVerdict): a Junk message is a false positive only when the SENDER is known
 * work — a contact, a vendor, a payments / bank / CRA / insurer / payroll domain or address —
 * or it is a strong work enquiry. Subject-only matches (a fake "Interac e-Transfer") never
 * qualify, and mail whose Authentication-Results say dkim/spf/dmarc=fail is left in Junk.
 *
 * No namespace / no autoloader in production: require_once and call statically.
 */
require_once __DIR__ . '/../../Sales/Services/SalesInboxService.php';
require_once __DIR__ . '/../../Sales/Services/EmailLeadService.php';
require_once __DIR__ . '/../../Expenses/Services/VendorMessageService.php';

class MailTidyClassifier
{
    public const MIN_CONFIDENCE = 70;
    public const INTERAC_SENDER = 'notify@payments.interac.ca';
    public const YARDI_SENDER = 'donotreply@yardi.com';

    /** Folders whose mail in Junk is worth a second look (Tim's work). */
    public const RESCUE_KEYS = ['clients', 'enquiries', 'suppliers', 'receipts', 'payments', 'banking', 'insurance', 'team'];
    /** Bases that rest on WHO sent it (subject-only and header-only guesses never rescue). */
    private const SENDER_BASES = ['contact', 'payment_sender', 'address', 'domain', 'vendor'];

    // ─────────────────────────────────────────────────────────────────────────
    // The proposed plan (seeded into mail_tidy_rules by migration 1226)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * key => [imap name (modified UTF-7: "&" is "&-"), label, existing?, what goes there].
     * Existing folders keep their names exactly — nothing is renamed.
     */
    public static function defaultFolders(): array
    {
        return [
            'clients'      => ['clients', 'Clients', true, 'Customers, strata and property managers — anyone in the CRM'],
            'enquiries'    => ['Enquiries', 'Enquiries', false, 'New work requests from people not yet in the CRM'],
            'suppliers'    => ['Suppliers', 'Suppliers', false, 'Lawnboy, Home Depot, nurseries, parts dealers — orders and deliveries'],
            'receipts'     => ['RECEIPTS', 'Receipts & Invoices', true, 'E-receipts, order confirmations and bills'],
            'payments'     => ['Money', 'Money', true, 'Payments in and out: Interac e-Transfers, Stripe, Yardi EFT, Square / PayPal notices'],
            'banking'      => ['Banking &- Tax', 'Banking & Tax', false, 'Vancity, TD, CRA, GST / PST, the accountant'],
            'insurance'    => ['Insurance &- Vehicles', 'Insurance & Vehicles', false, 'Insurance, ICBC, the RAM / TD auto loan, Trackimo'],
            'team'         => ['Team &- Payroll', 'Team & Payroll', false, 'Wave payroll, WorkSafeBC, hiring'],
            'marketing'    => ['Marketing &- Web', 'Marketing & Web', false, 'Google Business / Search Console, Facebook, Instagram, website, domain, SEO'],
            'software'     => ['Software &- Accounts', 'Software & Accounts', false, 'Apps, subscriptions, security codes (Apple and Arlo keep their own folders)'],
            'apple'        => ['Apple &- Tech', 'Apple & Tech', true, 'Apple ID, App Store, iCloud, Apple receipts'],
            'arlo'         => ['Arlo', 'Arlo', true, 'Arlo camera alerts and account mail'],
            'newsletters'  => ['Newsletters &- Promos', 'Newsletters & Promos', true, 'Anything sent to a mailing list (it has an unsubscribe link), and sales pitches'],
            'crm'          => ['Mowology CRM', 'Mowology CRM', true, 'The CRM\'s own system mail (cron reports, alerts, no-reply notices)'],
            'jobber'       => ['Jobber', 'Legacy / Jobber', true, 'Jobber mail from before the 2026-02-25 switch to the CRM'],
            'london_drugs' => ['London Drugs', 'London Drugs', true, 'London Drugs orders and photo mail'],
            'wing_chun'    => ['Wing Chun', 'Wing Chun', true, 'Wing Chun / Wing Tsun — personal'],
            'personal'     => ['Personal', 'Personal', true, 'Clearly personal mail on an explicit rule — when unsure it stays in INBOX'],
        ];
    }

    /** [type, folder key, match] — type: address | domain | subject. */
    public static function defaultRules(): array
    {
        $r = [];
        $add = function (string $type, string $key, array $list) use (&$r): void {
            foreach ($list as $v) $r[] = [$type, $key, $v];
        };
        $add('domain', 'payments', ['payments.interac.ca', 'interac.ca', 'stripe.com', 'paypal.com', 'paypal.ca', 'squareup.com', 'square.com', 'yardi.com']);
        $add('domain', 'banking', ['vancity.com', 'td.com', 'tdcanadatrust.com', 'cra-arc.gc.ca', 'canada.ca', 'gov.bc.ca', 'intuit.com']);
        $add('domain', 'insurance', ['icbc.com', 'ramtrucks.com', 'mopar.com', 'tdautofinance.ca', 'trackimo.com', 'intact.ca', 'squareone.ca', 'aviva.ca', 'cooperators.ca', 'bcaa.com']);
        $add('domain', 'team', ['waveapps.com', 'worksafebc.com', 'indeed.com']);
        $add('domain', 'marketing', ['facebookmail.com', 'instagram.com', 'business.google.com', 'godaddy.com', 'canadianwebhosting.com', 'namecheap.com', 'semrush.com', 'mailchimp.com', 'yelp.com', 'yelp.ca', 'homestars.com', 'nextdoor.com']);
        $add('domain', 'software', ['dropbox.com', 'microsoft.com', 'adobe.com', 'zoom.us', 'github.com', 'anthropic.com', 'openai.com', 'accounts.google.com']);
        $add('domain', 'apple', ['apple.com']);
        $add('domain', 'arlo', ['arlo.com']);
        $add('domain', 'suppliers', ['homedepot.ca', 'homedepot.com', 'rona.ca', 'leevalley.com', 'stihl.ca', 'husqvarna.com', 'princessauto.com', 'canadiantire.ca', 'kubota.ca', 'toro.com']);
        $add('domain', 'jobber', ['getjobber.com', 'jobber.com']);
        $add('domain', 'london_drugs', ['londondrugs.com']);
        $add('address', 'marketing', ['sc-noreply@google.com', 'businessprofile-noreply@google.com', 'ads-noreply@google.com', 'analytics-noreply@google.com']);
        $add('address', 'software', ['no-reply@accounts.google.com']);
        $add('subject', 'wing_chun', ['wing chun', 'wing tsun', 'wingtsun', 'sifu']);
        $add('subject', 'marketing', ['search console', 'google business profile']);
        $add('subject', 'banking', ['gst/hst', 'gst return', 'notice of assessment']);
        $add('subject', 'payments', ['you sent a payment', 'you received a payment', 'you\'ve got money', 'payment received']);
        return $r;
    }

    public static function defaultSettings(): array
    {
        return [
            'min_confidence'       => '70',     // below this, mail stays put
            'inbox_keep_days'      => '30',     // INBOX keeps the last 30 days whatever they are
            'sources'              => 'INBOX,Archive',
            'junk_folders'         => 'Junk,Junk E-mailings oh',
            'chunk_size'           => '200',    // UIDs per MOVE
            'pause_ms'             => '1500',   // between chunks — gentle with Apple
            'batch_max'            => '2000',   // messages per Apply click
            'keep_tidy'            => '0',      // OFF until Tim turns it on
            'keep_tidy_after_days' => '7',
        ];
    }

    /** The default plan (tests, and before migration 1226 has run). */
    public static function defaultPlan(): array
    {
        $rows = [];
        $i = 0;
        foreach (self::defaultFolders() as $k => $f) {
            $rows[] = ['rule_type' => 'folder', 'folder_key' => $k, 'match_value' => $f[0], 'label' => $f[1], 'note' => $f[3],
                       'existing' => $f[2], 'sort_order' => ++$i, 'is_active' => 1];
        }
        foreach (self::defaultRules() as [$t, $k, $v]) {
            $rows[] = ['rule_type' => $t, 'folder_key' => $k, 'match_value' => $v, 'is_active' => 1];
        }
        foreach (self::defaultSettings() as $k => $v) {
            $rows[] = ['rule_type' => 'setting', 'folder_key' => $k, 'match_value' => $v, 'is_active' => 1];
        }
        return self::fromRows($rows);
    }

    /**
     * mail_tidy_rules rows → the plan.
     * @return array{folders: array<string, array{imap: string, label: string, note: string, existing: bool}>,
     *               address: array<string,string>, domain: array<string,string>, subject: array<string,string>,
     *               settings: array<string,string>}
     */
    public static function fromRows(array $rows): array
    {
        $plan = ['folders' => [], 'address' => [], 'domain' => [], 'subject' => [], 'settings' => self::defaultSettings()];
        usort($rows, fn($a, $b) => ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0)));
        foreach ($rows as $r) {
            if (isset($r['is_active']) && !(int)$r['is_active']) continue;
            $type = (string)$r['rule_type'];
            $key = (string)$r['folder_key'];
            $val = (string)$r['match_value'];
            if ($type === 'folder') {
                $plan['folders'][$key] = ['imap' => $val, 'label' => (string)($r['label'] ?? self::displayFolder($val)),
                                          'note' => (string)($r['note'] ?? ''), 'existing' => !empty($r['existing']) || !empty($r['is_existing'])];
            } elseif ($type === 'setting') {
                $plan['settings'][$key] = $val;
            } elseif (in_array($type, ['address', 'domain', 'subject'], true)) {
                $plan[$type][strtolower(trim($val))] = $key;
            }
        }
        // Longest domain first, so "business.google.com" beats "google.com".
        uksort($plan['domain'], fn($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
        return $plan;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Classification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Where one message belongs.
     * @param array{from: string, to?: string, subject?: string, headers?: string, body?: string|callable} $m
     *        body is optional (a callable is only called for unknown senders — the enquiry check)
     * @param array{contacts?: array<string,int>, vendors?: array, ours?: string[]} $ctx
     * @return array{key: ?string, folder: ?string, confidence: int, reason: string, basis: string, move: bool}
     */
    public static function classify(array $m, array $ctx, ?array $plan = null): array
    {
        $plan = $plan ?? self::defaultPlan();
        $from = SalesInboxService::addresses((string)($m['from'] ?? ''))[0] ?? '';
        $subject = (string)($m['subject'] ?? '');
        $headers = (string)($m['headers'] ?? '');
        $ours = array_map('strtolower', (array)($ctx['ours'] ?? []));
        $contacts = (array)($ctx['contacts'] ?? []);
        $vendors = (array)($ctx['vendors'] ?? []);

        if ($from === '') return self::out($plan, null, 0, 'no sender', 'none');

        // 1. Payment notices.
        if ($from === self::INTERAC_SENDER || $from === self::YARDI_SENDER) {
            return self::out($plan, 'payments', 95, $from === self::YARDI_SENDER ? 'Yardi EFT remittance' : 'Interac e-Transfer notice', 'payment_sender');
        }
        if (preg_match('/interac e-?transfer/i', $subject)) {
            return self::out($plan, 'payments', 80, 'Interac e-Transfer subject', 'subject');
        }

        // 2. A customer conversation, either direction.
        $c = SalesInboxService::classify((string)$m['from'], (string)($m['to'] ?? ''), $contacts, $ours);
        if ($c !== null) return self::out($plan, 'clients', 90, 'CRM contact', 'contact');

        // 3. The CRM's own robots, then Tim's own mail (notes to self, forwards) — his call.
        if (MailTidyClassifier::domainMatches($from, 'mowology.ca')
            && preg_match('/^(no-?reply|donotreply|crm|system|notifications?|alerts?|cron|reports?|mailer|wordpress|admin)([._+\-].*)?@/', $from)) {
            return self::out($plan, 'crm', 85, 'Mowology CRM system mail', 'address');
        }
        if (SalesInboxService::isOurs($from, $ours)) return self::out($plan, null, 0, 'from you', 'ours');

        // 4. Exact sender.
        if (isset($plan['address'][$from])) {
            return self::out($plan, $plan['address'][$from], 90, 'sender ' . $from, 'address');
        }

        // 5. Sender domain.
        foreach ($plan['domain'] as $domain => $key) {
            if (!self::domainMatches($from, $domain)) continue;
            if ($key === 'suppliers' && self::isReceiptSubject($subject)) {
                return self::out($plan, 'receipts', 85, 'supplier receipt (' . $domain . ')', 'domain');
            }
            return self::out($plan, $key, 85, 'sender domain ' . $domain, 'domain');
        }

        // 6. A CRM vendor.
        $vid = $vendors ? VendorMessageService::matchVendor($from, self::displayName((string)$m['from']), $vendors) : null;
        if ($vid !== null) {
            return self::isReceiptSubject($subject)
                ? self::out($plan, 'receipts', 80, 'vendor receipt', 'vendor')
                : self::out($plan, 'suppliers', 80, 'CRM vendor', 'vendor');
        }

        // 7. Subject phrases.
        foreach ($plan['subject'] as $phrase => $key) {
            if ($phrase !== '' && preg_match('/\b' . preg_quote($phrase, '/') . '\b/i', $subject)) {
                return self::out($plan, $key, 75, 'subject mentions "' . $phrase . '"', 'subject');
            }
        }

        $list = self::isListMail($headers);
        $robot = $list || EmailLeadService::isAutomated($from, $headers);

        // 8. A work enquiry from someone new (people only — never a robot, a pitch or a payment).
        if (!$robot && !self::isReceiptSubject($subject) && !preg_match('/\bpayment\b|\bpaid\b|\brefund\b/i', $subject)) {
            $body = $m['body'] ?? '';
            $text = is_callable($body) ? (string)$body() : (string)$body;
            $plain = preg_match('/<[a-z][^>]*>/i', $text) ? strip_tags($text) : $text;
            if (self::isPitch($subject, $plain)) {
                return self::out($plan, null, 30, 'sales pitch to you — left for you', 'pitch');
            }
            $score = EmailLeadService::score($subject, SalesInboxService::snippet($plain));
            if ($score['strength'] === 'strong') return self::out($plan, 'enquiries', 80, 'work enquiry', 'enquiry');
            if ($score['strength'] === 'weak') return self::out($plan, null, 40, 'maybe an enquiry — left for you', 'enquiry');
        }

        // 9. A robot's receipt.
        if ($robot && self::isReceiptSubject($subject) && !preg_match('/\b(sale|% off|deal|save|offer)\b/i', $subject)) {
            return self::out($plan, 'receipts', 70, 'e-receipt / order confirmation', 'header');
        }

        // 10. Mailing lists (a pitch sent through one is a promo too).
        if ($list) return self::out($plan, 'newsletters', 75, 'mailing list (unsubscribe link)', 'header');
        if ($robot && self::isPitch($subject, '')) return self::out($plan, 'newsletters', 70, 'promotional pitch', 'header');
        $local = strtolower((string)strstr($from, '@', true));
        if (preg_match('/^(newsletters?|news|marketing|promo(tions?)?|offers?|deals|specials|hello)([._+\-].*)?$/', $local)) {
            return self::out($plan, 'newsletters', 70, 'marketing sender', 'sender_pattern');
        }

        return self::out($plan, null, 0, $robot ? 'notification — left for you' : 'not sure — left for you', 'none');
    }

    /**
     * Is this Junk message really Tim's work mail?
     * @return array{rescue: bool, selected: bool, confidence: int, reason: string, key: ?string}
     */
    public static function junkVerdict(array $m, array $ctx, ?array $plan = null): array
    {
        $plan = $plan ?? self::defaultPlan();
        $headers = (string)($m['headers'] ?? '');
        $r = self::classify($m, $ctx, $plan);
        $no = ['rescue' => false, 'selected' => false, 'confidence' => 0, 'reason' => 'spam stays in Junk', 'key' => $r['key']];
        if ($r['key'] === null || !in_array($r['key'], self::RESCUE_KEYS, true)) return $no;
        $bySender = in_array($r['basis'], self::SENDER_BASES, true);
        $enquiry = $r['basis'] === 'enquiry' && $r['key'] === 'enquiries';
        if (!$bySender && !$enquiry) return $no + ['why' => 'subject-only match'];
        if (self::authFailed($headers)) {
            return ['rescue' => false, 'selected' => false, 'confidence' => 0, 'reason' => 'sender failed authentication — looks spoofed', 'key' => $r['key']];
        }
        $conf = $enquiry ? 70 : $r['confidence'];
        return ['rescue' => true, 'selected' => $conf >= 85, 'confidence' => $conf, 'reason' => $r['reason'], 'key' => $r['key']];
    }

    private static function out(array $plan, ?string $key, int $conf, string $reason, string $basis): array
    {
        $min = (int)($plan['settings']['min_confidence'] ?? self::MIN_CONFIDENCE);
        $folder = $key !== null ? ($plan['folders'][$key]['imap'] ?? null) : null;
        if ($key !== null && $folder === null) {   // a rule points at a folder the plan doesn't have
            return ['key' => null, 'folder' => null, 'confidence' => 0, 'reason' => $reason . ' (no folder "' . $key . '" in the plan)', 'basis' => $basis, 'move' => false];
        }
        return ['key' => $key, 'folder' => $folder, 'confidence' => $conf, 'reason' => $reason, 'basis' => $basis,
                'move' => $folder !== null && $conf >= $min];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** a@x.vancity.com matches vancity.com; a@notvancity.com does not. */
    public static function domainMatches(string $email, string $domain): bool
    {
        $host = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $domain = strtolower(ltrim(trim($domain), '@.'));
        if ($host === '' || $domain === '') return false;
        return $host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain;
    }

    public static function isReceiptSubject(string $subject): bool
    {
        if (preg_match('/e-?transfer|interac|remittance|quote request/i', $subject)) return false;
        return (bool)preg_match('/receipt|re[çc]u\b|your order|order (confirmation|#|number|receipt|has shipped|is ready)|purchase|invoice|\bbill\b|statement is ready/i', $subject);
    }

    /**
     * Someone selling TO Mowology (not asking for work): "Custom engraved gifts for MOWOLOGY",
     * SEO / web design / lead-gen offers, promotional products, wholesale, guest posts.
     */
    public static function isPitch(string $subject, string $body): bool
    {
        $text = $subject . "\n" . mb_substr($body, 0, 2000);
        return (bool)preg_match('/\bfor (mowology|your (business|company|team|brand|website))\b|\b(seo|backlinks?|guest post|web ?design|website (design|redesign)|'
            . 'lead generation|more (leads|customers|reviews)|marketing (services|agency)|google (ranking|first page)|first page of google|wholesale|'
            . 'custom (engraved|printed|branded|logo)|promotional (products|items)|branded merch|business loan|merchant (services|cash)|'
            . 'partnership opportunit|sponsorship|we (help|offer|provide) (businesses|companies|landscapers))\b/i', $text);
    }

    /** Sent to a mailing list: List-Unsubscribe / List-Id / Precedence bulk / campaign headers. */
    public static function isListMail(string $headers): bool
    {
        $h = (string)preg_replace("/\r?\n[ \t]+/", ' ', $headers);
        return (bool)preg_match('/^(List-Unsubscribe|List-Id|X-Campaign(?:-?Id)?|X-Mailchimp-[A-Za-z-]+|X-SG-EID):|^Precedence:\s*(bulk|list)\b/mi', $h);
    }

    /** The receiving server says the sender is forged (dkim / spf / dmarc = fail). */
    public static function authFailed(string $headers): bool
    {
        $h = (string)preg_replace("/\r?\n[ \t]+/", ' ', $headers);
        if (!preg_match_all('/^Authentication-Results:(.*)$/mi', $h, $m)) return false;
        foreach ($m[1] as $line) {
            if (preg_match('/\b(dkim|spf|dmarc)=(fail|softfail|permerror)\b/i', $line)) return true;
        }
        return false;
    }

    /** "The Home Depot" from a From header. */
    public static function displayName(string $header): string
    {
        $n = trim((string)preg_replace('/<[^>]*>|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '', $header));
        return trim($n, " \t\"'");
    }

    /** IMAP modified UTF-7 → readable ("Apple &- Tech" → "Apple & Tech"). */
    public static function displayFolder(string $imap): string
    {
        if (strpos($imap, '&') === false) return $imap;
        if (function_exists('mb_convert_encoding')) {
            $d = @mb_convert_encoding($imap, 'UTF-8', 'UTF7-IMAP');
            if (is_string($d) && $d !== '') return $d;
        }
        return str_replace('&-', '&', $imap);
    }

    /** Readable ASCII name → IMAP modified UTF-7 ("Banking & Tax" → "Banking &- Tax"). */
    public static function imapName(string $label): string
    {
        return str_replace('&', '&-', $label);
    }
}
