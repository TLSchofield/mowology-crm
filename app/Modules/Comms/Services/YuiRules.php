<?php
/**
 * YuiRules — the pure decisions behind Yui, the comms / client relations head (unit tested).
 *
 * Yui's job: every one-to-one conversation with EXISTING clients. Sam keeps quotes and new
 * leads, Mia keeps campaigns/consent/reviews/referrals, Penny keeps the money, Otto keeps the
 * properties. Yui never sends anything herself — she drafts, Tim edits and sends.
 *
 * Sections (each item carries Charlie's brief contract: key, kind, value, since, text, url,
 * priority — plus card fields):
 *   inbox     — client replies nobody answered that are not about a quote (UnclaimedReplyService
 *               'client' lane, kind client_reply); a clear yes ranks first.
 *   promises  — an inbound "yes / council approved / go ahead" with no quote accepted after it.
 *   accounts  — property-management firms and stratas with missing people or emails, PM-managed
 *               buildings with nobody to send quotes to, contract holders still marked 'lead'.
 *   renewals  — contracts ending within RENEWAL_DAYS, last winter's salt/snow customers with
 *               nothing for this winter (SEASON_LEAD_DAYS before the season), quiet PM firms.
 *   arrears   — invoices more than ARREARS_DAYS overdue: a polite note to the right person
 *               (property manager or company primary; an accountant only when they are the
 *               billing contact). Penny keeps the numbers; Yui only drafts the conversation.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/SamFollowupService.php';

class YuiRules
{
    public const HEAD = 'yui';
    public const OFFICE_PHONE = '(778) 846-9273';
    public const SMS_MAX = 160;

    public const PROMISE_WINDOW_DAYS = 60;
    public const RENEWAL_DAYS = 60;
    /** Ending this soon → priority 2 instead of 3. */
    public const RENEWAL_URGENT_DAYS = 14;
    public const SEASON_LEAD_DAYS = 45;
    /** Winter (salt/snow) season start, month-day. */
    public const SEASON_START_MD = '11-01';
    /** A seasonal contract that ended within this many days before the season is "last winter's". */
    public const LAST_SEASON_DAYS = 400;
    public const CHECKIN_QUIET_DAYS = 90;
    public const ARREARS_DAYS = 60;
    /** After Tim sends a renewal / check-in / arrears note, the item rests this long. */
    public const SENT_REST_DAYS = 14;

    /** Section order in Yui's brief and on her card. */
    public const SECTIONS = ['inbox', 'promises', 'accounts', 'renewals', 'arrears'];
    /** Brief order: yes/promises, arrears, inbox, accounts, renewals. */
    public const BRIEF_RANK = ['yes' => 0, 'promise' => 0, 'arrears' => 1, 'inbox' => 2, 'accounts' => 3, 'renewals' => 4];

    /**
     * subject, email body, text. Tim's voice (app/Services/Copy/mowology-copy-rules.md): short,
     * plain, no exclamation marks, never "just checking in". Texts follow CLAUDE.md rule 11.
     */
    public const TEMPLATES = [
        'reply' => [
            'Re: {subject}',
            "Hi {first_name},\n\n\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology. Got your note. Check your email or call {phone}.",
        ],
        'checkin' => [
            'Your buildings with Mowology',
            "Hi {first_name},\n\nIt's been a while since we spoke, so a quick note from me. Is there anything at your buildings you'd like us to look at, or anything about our visits you'd change?\n\nIf it helps with your owners or council, I can send one page listing what we do at each site.\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology. I sent you a quick note about your buildings. Check your email or call {phone}.",
        ],
        'renewal' => [
            'Your contract for {place}',
            "Hi {first_name},\n\nYour contract for {place} runs until {end_date}. I'd like to keep the same crew on it next term. Should I send the renewal over, or is there anything you'd like changed first?\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology about renewing {place}. Check your email or call {phone}.",
        ],
        'season' => [
            'Salting and snow at {place} this winter',
            "Hi {first_name},\n\nWe looked after {place} last winter, and the season starts {season_start}. I'm putting the winter routes together now. Would you like us back on it this year? If anything needs to change from last year, tell me and I'll update it.\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology about salting and snow this winter. Check your email or call {phone}.",
        ],
        'arrears' => [
            'Open {invoice_word} for {place}',
            "Hi {first_name},\n\nI'm going through our open invoices and {invoice_list} {is_are} still showing as unpaid on our side ({total}, the oldest due {oldest_due}). If it's already been paid, could you tell me when and how, so I can match it up? If something on {it_them} needs fixing, tell me and I'll sort it out.\n\nThanks,\n{owner}",
            "Hi {first_name}, it's {owner} at Mowology. I sent you an email about an open invoice. Check your email or call {phone}.",
        ],
    ];
    public const SMS_FALLBACK = "Hi, it's Mowology. I sent you an email. Check your email or call {phone}.";

    // ─────────────────────────────────────────────────────────────────────────
    // Promises
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Does this message say the work is approved? A short yes (UnclaimedReplyService::isYes),
     * or an approval phrase anywhere: "council approved", "approved the quote", "go ahead",
     * "please proceed", "we accept", "signed the contract" — unless it is negated or
     * conditional ("hasn't approved yet", "once council approves", "if they approve").
     */
    public static function isPromise(string $text): bool
    {
        if (UnclaimedReplyService::isYes($text)) return true;
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}"], "'", $text))));
        if ($t === '') return false;
        $phrase = "/\b(?:(?:council|board|owners?|strata|committee|management|the client|they|we|i) (?:has |have )?approved"
                . "|approved (?:the|your|our|this|it|both|all)\b"
                . "|(?:please |you can |ok to |okay to )?go ahead"
                . "|please proceed|you may proceed|ok to proceed|okay to proceed"
                . "|we(?: would like to|'d like to)? accept|accepted (?:the|your)\b"
                . "|signed (?:the |your )?(?:quote|contract|estimate|proposal))/";
        if (!preg_match($phrase, $t, $m, PREG_OFFSET_CAPTURE)) return false;
        // Negated or conditional just before the phrase, in the same sentence: "not", "n't", "yet to", "once", "if", "before", "until".
        $before = mb_substr(substr($t, 0, $m[0][1]), -30);
        if (preg_match("/\b(?:not|never|yet to|once|if|before|until|whether)\b[^.!?]*$|n't\b[^.!?]*$/", $before)) return false;
        $after = substr($t, $m[0][1], strlen($m[0][0]) + 12);
        if (preg_match('/\byet\b|\?/', $after)) return false;
        return true;
    }

    /**
     * Inbound approvals with no quote accepted after them — one per contact (their latest).
     * @param array $messages inbound rows: message_key, contact_id, first_name, employer_company_id, channel,
     *                        from_addr, subject, snippet, sent_at
     * @param array $ctx accepted: [['contact_id','site_contact_id','company_id','pm_company_id','accepted_at']] ·
     *                   skip_keys: message_key[] already in an inbox lane · skip_contacts: int[] (Sam's "waiting on you") ·
     *                   hidden: item keys
     */
    public static function promises(array $messages, array $ctx, string $now): array
    {
        $from = strtotime($now) - self::PROMISE_WINDOW_DAYS * 86400;
        $skipKeys = array_flip(array_map('strval', $ctx['skip_keys'] ?? []));
        $skipContacts = array_flip(array_map('intval', $ctx['skip_contacts'] ?? []));
        $hidden = array_flip($ctx['hidden'] ?? []);
        $latest = [];
        foreach ($messages as $m) {
            $cid = (int)($m['contact_id'] ?? 0);
            $t = strtotime((string)($m['sent_at'] ?? ''));
            if ($cid <= 0 || $t === false || $t < $from || isset($skipContacts[$cid])) continue;
            if (UnclaimedReplyService::isAutomated((string)($m['from_addr'] ?? ''), (string)($m['subject'] ?? ''), (string)($m['snippet'] ?? ''))) continue;
            if (!self::isPromise((string)($m['snippet'] ?? ''))) continue;
            if (!isset($latest[$cid]) || $t > $latest[$cid]['_t']) $latest[$cid] = $m + ['_t' => $t];
        }
        $items = [];
        foreach ($latest as $cid => $m) {
            if (isset($skipKeys[(string)$m['message_key']])) continue;
            if (self::acceptedAfter($cid, (int)($m['employer_company_id'] ?? 0), (string)$m['sent_at'], $ctx['accepted'] ?? [])) continue;
            $key = 'yui:promise:' . $cid . ':' . substr(sha1((string)$m['message_key']), 0, 12);
            if (isset($hidden[$key])) continue;
            $name = UnclaimedReplyService::firstName((string)($m['first_name'] ?? '')) ?: 'A client';
            $quote = UnclaimedReplyService::quote((string)($m['snippet'] ?? ''));
            $items[] = [
                'key'        => $key,
                'kind'       => 'promise',
                'section'    => 'promises',
                'value'      => null,
                'since'      => date('Y-m-d', (int)$m['_t']),
                'text'       => $name . ': "' . $quote . '" (' . date('M j', (int)$m['_t']) . ') — no accepted quote in the CRM',
                'url'        => '/crm/clients_appstack.php?action=view_contact&id=' . $cid,
                'priority'   => 1,
                'contact_id' => $cid,
                'name'       => $name,
                'quote'      => $quote,
                'at'         => (string)$m['sent_at'],
                'channel'    => ($m['channel'] ?? '') === 'sms' ? 'sms' : 'email',
            ];
        }
        usort($items, fn($a, $b) => strcmp($b['at'], $a['at']));
        return $items;
    }

    /** A quote for this person (or their firm) was accepted at or after $at. */
    public static function acceptedAfter(int $cid, int $companyId, string $at, array $accepted): bool
    {
        foreach ($accepted as $q) {
            if ((string)($q['accepted_at'] ?? '') === '' || (string)$q['accepted_at'] < $at) continue;
            if ((int)($q['contact_id'] ?? 0) === $cid || (int)($q['site_contact_id'] ?? 0) === $cid) return true;
            if ($companyId > 0 && ((int)($q['company_id'] ?? 0) === $companyId || (int)($q['pm_company_id'] ?? 0) === $companyId)) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Accounts (read-only checks — nothing is fixed automatically)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $firms      [id, name, type, primary_contact_id, billing_contact_id, billing_email,
     *                           contacts: int[] (primary, billing, employees), people: [id => [first_name, email]]]
     * @param array $properties PM-managed buildings: [id, address, firm_id, firm_name, has_quote_contact, has_strata_rep]
     * @param array $leads      contacts on an active contract still at lifecycle 'lead': [contact_id, name, contract_id, contract_number]
     */
    public static function accountFlags(array $firms, array $properties, array $leads, array $hidden = []): array
    {
        $hide = array_flip($hidden);
        $out = [];
        $add = function (string $flag, int $id, string $text, string $url) use (&$out, $hide) {
            $key = 'yui:account:' . $flag . ':' . $id;
            if (isset($hide[$key])) return;
            $out[] = ['key' => $key, 'kind' => 'account_' . $flag, 'section' => 'accounts', 'value' => null, 'since' => null,
                      'text' => $text, 'url' => $url, 'priority' => 3, 'flag' => $flag];
        };
        foreach ($firms as $f) {
            $id = (int)$f['id'];
            $name = trim((string)($f['name'] ?? '')) ?: 'A firm';
            $url = '/crm/companies/view.php?id=' . $id;
            $people = (array)($f['people'] ?? []);
            $contacts = array_values(array_unique(array_filter(array_map('intval', (array)($f['contacts'] ?? [])))));
            $contacts = array_values(array_filter($contacts, fn($c) => isset($people[$c])));
            if (!$contacts) { $add('no_contacts', $id, $name . ' has no contacts in the CRM', $url); continue; }
            $primary = (int)($f['primary_contact_id'] ?? 0);
            if ($primary <= 0 || !isset($people[$primary])) {
                $add('no_primary', $id, $name . ' has no primary contact', $url);
            } elseif (!self::hasEmail($people[$primary]['email'] ?? '')) {
                $add('primary_no_email', $id, $name . "'s primary contact" . self::named($people[$primary]) . ' has no email', $url);
            }
            $billing = (int)($f['billing_contact_id'] ?? 0);
            if ($billing > 0 && $billing !== $primary && isset($people[$billing]) && !self::hasEmail($people[$billing]['email'] ?? '')
                && !self::hasEmail((string)($f['billing_email'] ?? ''))) {
                $add('billing_no_email', $id, $name . "'s billing contact" . self::named($people[$billing]) . ' has no email', $url);
            }
        }
        foreach ($properties as $p) {
            if (!empty($p['has_quote_contact']) || !empty($p['has_strata_rep'])) continue;
            $add('pm_no_contact', (int)$p['id'], (trim((string)($p['address'] ?? '')) ?: 'A building')
                . (!empty($p['firm_name']) ? ' (managed by ' . $p['firm_name'] . ')' : '') . ' has no quote contact or strata rep',
                '/crm/properties/view.php?id=' . (int)$p['id']);
        }
        foreach ($leads as $l) {
            $add('lead_contract', (int)$l['contact_id'], (trim((string)($l['name'] ?? '')) ?: 'A contact') . ' has an active contract'
                . (!empty($l['contract_number']) ? ' (' . $l['contract_number'] . ')' : '') . ' but is still marked a lead',
                '/crm/clients_appstack.php?action=view_contact&id=' . (int)$l['contact_id']);
        }
        return $out;
    }

    private static function named(array $person): string
    {
        $n = UnclaimedReplyService::firstName((string)($person['first_name'] ?? ''));
        return $n !== '' ? ', ' . $n . ',' : '';
    }

    public static function hasEmail($email): bool
    {
        return is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who to write to
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The right person, in the caller's order (e.g. property manager → company primary →
     * billing contact → invoice contact). An accountant — contact_role 'billing_contact' —
     * is skipped unless they ARE the billing contact (billing => true). First one with a
     * usable email wins; with no email anywhere, the first allowed person (email '').
     * @param array $candidates [id, first_name, last_name, email, phone, role (contact_role), why, billing?: bool]
     */
    public static function pickContact(array $candidates): ?array
    {
        $allowed = [];
        $seen = [];
        foreach ($candidates as $c) {
            $id = (int)($c['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            if (($c['role'] ?? '') === 'billing_contact' && empty($c['billing'])) continue;
            $allowed[] = $c;
        }
        foreach ($allowed as $c) {
            if (self::hasEmail($c['email'] ?? '')) return self::person($c);
        }
        return $allowed ? self::person($allowed[0]) : null;
    }

    private static function person(array $c): array
    {
        $first = UnclaimedReplyService::firstName((string)($c['first_name'] ?? ''));
        return [
            'contact_id' => (int)$c['id'],
            'first_name' => $first,
            'name'       => trim(($first !== '' ? (string)$c['first_name'] : '') . ' ' . (string)($c['last_name'] ?? '')) ?: 'them',
            'email'      => self::hasEmail($c['email'] ?? '') ? trim((string)$c['email']) : '',
            'phone'      => (string)($c['phone'] ?? ''),
            'why'        => (string)($c['why'] ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Renewals & check-ins
    // ─────────────────────────────────────────────────────────────────────────

    public static function daysBetween(string $fromDay, string $toDay): int
    {
        return (int)round((strtotime(substr($toDay, 0, 10)) - strtotime(substr($fromDay, 0, 10))) / 86400);
    }

    /** Contracts ending within RENEWAL_DAYS (today included). rows: id, contract_number, end_date, address, auto_renew, to */
    public static function endingContracts(array $rows, string $now, array $hidden = []): array
    {
        $hide = array_flip($hidden);
        $today = substr($now, 0, 10);
        $out = [];
        foreach ($rows as $r) {
            $end = substr((string)($r['end_date'] ?? ''), 0, 10);
            if ($end === '') continue;
            $days = self::daysBetween($today, $end);
            if ($days < 0 || $days > self::RENEWAL_DAYS) continue;
            $key = 'yui:renewal:ending:' . (int)$r['id'];
            if (isset($hide[$key])) continue;
            $place = trim((string)($r['address'] ?? '')) ?: 'the property';
            $out[] = [
                'key' => $key, 'kind' => 'renewal_ending', 'section' => 'renewals', 'value' => isset($r['amount']) ? (float)$r['amount'] : null,
                'since' => null,
                'text' => 'Contract ' . ($r['contract_number'] ?? '') . ' at ' . $place . ' ends ' . date('M j', strtotime($end))
                    . ' (' . ($days === 0 ? 'today' : $days . ' day' . ($days === 1 ? '' : 's')) . ')'
                    . (!empty($r['auto_renew']) ? ' — it renews on its own; tell them' : ' — ask about renewing'),
                'url' => '/crm/contracts/view.php?id=' . (int)$r['id'],
                'priority' => $days <= self::RENEWAL_URGENT_DAYS ? 2 : 3,
                'template' => 'renewal', 'place' => $place, 'end_date' => $end, 'days' => $days, 'to' => $r['to'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => $a['days'] <=> $b['days']);
        return $out;
    }

    /** Salt / snow / winter work, from a contract title or its quote's service type. */
    public static function isSeasonal(string $text): bool
    {
        return (bool)preg_match('/\b(?:salt|salting|snow|ice|de-?icing|de-?icer|winter)\b/i', str_replace('_', ' ', $text));
    }

    /**
     * The winter season this date belongs to or is heading into: its start day, and the days
     * until it starts (negative once it has started). Nov 1 → Mar 31.
     * @return array{start: string, days: int}
     */
    public static function season(string $now): array
    {
        $today = substr($now, 0, 10);
        $y = (int)substr($today, 0, 4);
        $md = substr($today, 5, 5);
        $start = ($md <= '03-31') ? ($y - 1) . '-' . self::SEASON_START_MD : $y . '-' . self::SEASON_START_MD;
        return ['start' => $start, 'days' => self::daysBetween($today, $start)];
    }

    /**
     * Properties that had a salt/snow contract last winter and have nothing for this one —
     * only within SEASON_LEAD_DAYS before the season starts.
     * @param array $contracts recent contracts: id, contract_number, property_id, address, title, service, status,
     *                         start_date, end_date, to
     * @param int[] $quotedPropertyIds properties with a winter quote made recently (Sam is on it)
     */
    public static function seasonGaps(array $contracts, array $quotedPropertyIds, string $now, array $hidden = []): array
    {
        $s = self::season($now);
        if ($s['days'] < 0 || $s['days'] > self::SEASON_LEAD_DAYS) return [];
        $hide = array_flip($hidden);
        $quoted = array_flip(array_map('intval', $quotedPropertyIds));
        $byProp = [];
        foreach ($contracts as $c) {
            if (!self::isSeasonal((string)($c['title'] ?? '') . ' ' . (string)($c['service'] ?? ''))) continue;
            $byProp[(int)$c['property_id']][] = $c;
        }
        $seasonYear = substr($s['start'], 0, 4);
        $out = [];
        foreach ($byProp as $pid => $list) {
            if ($pid <= 0 || isset($quoted[$pid])) continue;
            $current = false;
            $last = null;
            foreach ($list as $c) {
                $status = (string)($c['status'] ?? '');
                $end = substr((string)($c['end_date'] ?? ''), 0, 10);
                if (in_array($status, ['active', 'paused'], true) && ($end === '' || $end >= $s['start'])) { $current = true; break; }
                if ($status !== 'cancelled' && $end !== '' && $end < $s['start'] && self::daysBetween($end, $s['start']) <= self::LAST_SEASON_DAYS) {
                    if ($last === null || $end > substr((string)$last['end_date'], 0, 10)) $last = $c;
                }
            }
            if ($current || $last === null) continue;
            $key = 'yui:renewal:season:' . $pid . ':' . $seasonYear;
            if (isset($hide[$key])) continue;
            $place = trim((string)($last['address'] ?? '')) ?: 'the property';
            $out[] = [
                'key' => $key, 'kind' => 'renewal_season', 'section' => 'renewals', 'value' => null, 'since' => null,
                'text' => 'Salt & snow at ' . $place . ': last winter\'s contract (' . ($last['contract_number'] ?? '') . '), nothing for this winter yet — the season starts '
                    . date('M j', strtotime($s['start'])),
                'url' => '/crm/contracts/view.php?id=' . (int)$last['id'],
                'priority' => 3,
                'template' => 'season', 'place' => $place, 'season_start' => $s['start'], 'days' => $s['days'], 'to' => $last['to'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Property-management firms nobody has written to in CHECKIN_QUIET_DAYS. $covered: the
     * message log reaches back that far (before that, "no message" proves nothing).
     * @param array $firms id, name, last_out (?datetime), to
     */
    public static function checkins(array $firms, bool $covered, string $now, array $hidden = []): array
    {
        if (!$covered) return [];
        $hide = array_flip($hidden);
        $cut = date('Y-m-d H:i:s', strtotime($now) - self::CHECKIN_QUIET_DAYS * 86400);
        $out = [];
        foreach ($firms as $f) {
            $last = (string)($f['last_out'] ?? '');
            if ($last !== '' && $last >= $cut) continue;
            $key = 'yui:checkin:' . (int)$f['id'];
            if (isset($hide[$key])) continue;
            $name = trim((string)($f['name'] ?? '')) ?: 'A property manager';
            $out[] = [
                'key' => $key, 'kind' => 'checkin', 'section' => 'renewals', 'value' => null,
                'since' => $last !== '' ? substr($last, 0, 10) : null,
                'text' => 'Nothing sent to ' . $name . ($last !== '' ? ' since ' . date('M j', strtotime($last)) : ' in ' . self::CHECKIN_QUIET_DAYS . '+ days') . ' — time for a check-in',
                'url' => '/crm/companies/view.php?id=' . (int)$f['id'],
                'priority' => 3,
                'template' => 'checkin', 'place' => $name, 'last_out' => $last ?: null, 'to' => $f['to'] ?? null,
            ];
        }
        usort($out, fn($a, $b) => strcmp((string)$a['last_out'], (string)$b['last_out']));
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Arrears
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One conversation per payer with invoices more than ARREARS_DAYS overdue.
     * @param array $groups payer ('company:12' | 'contact:5'), name, place, invoices: [id, number, balance, due_date], to
     */
    public static function arrears(array $groups, string $now, array $hidden = []): array
    {
        $hide = array_flip($hidden);
        $today = substr($now, 0, 10);
        $out = [];
        foreach ($groups as $g) {
            $inv = array_values(array_filter((array)($g['invoices'] ?? []), fn($i) => (float)($i['balance'] ?? 0) > 0.005
                && !empty($i['due_date']) && self::daysBetween((string)$i['due_date'], $today) > self::ARREARS_DAYS));
            if (!$inv) continue;
            $key = 'yui:arrears:' . str_replace(':', '-', (string)$g['payer']);
            if (isset($hide[$key])) continue;
            usort($inv, fn($a, $b) => strcmp((string)$a['due_date'], (string)$b['due_date']));
            $total = round(array_sum(array_map(fn($i) => (float)$i['balance'], $inv)), 2);
            $oldest = substr((string)$inv[0]['due_date'], 0, 10);
            $days = self::daysBetween($oldest, $today);
            $to = $g['to'] ?? null;
            $who = $to ? (($to['first_name'] ?: $to['name']) . ($to['why'] !== '' ? ' (' . $to['why'] . ')' : '')) : 'nobody on file';
            $out[] = [
                'key' => $key, 'kind' => 'arrears', 'section' => 'arrears', 'value' => $total, 'since' => $oldest,
                'text' => (trim((string)($g['name'] ?? '')) ?: 'A client') . ' owes ' . SalesDeskService::money($total) . ' on ' . count($inv)
                    . ' invoice' . (count($inv) === 1 ? '' : 's') . ', oldest ' . $days . ' days overdue — a note to ' . $who,
                'url' => count($inv) === 1 ? '/crm/invoices/view.php?id=' . (int)$inv[0]['id'] : (string)($g['url'] ?? '/crm/invoices/index.php?status=overdue'),
                'priority' => 2,
                'template' => 'arrears', 'place' => trim((string)($g['place'] ?? '')) ?: (trim((string)($g['name'] ?? '')) ?: 'your account'),
                'invoices' => array_map(fn($i) => ['id' => (int)$i['id'], 'number' => (string)$i['number'], 'balance' => (float)$i['balance'],
                                                   'due_date' => substr((string)$i['due_date'], 0, 10)], $inv),
                'total' => $total, 'oldest_due' => $oldest, 'days' => $days, 'to' => $to,
            ];
        }
        usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Inbox (from UnclaimedReplyService — the single source)
    // ─────────────────────────────────────────────────────────────────────────

    /** Client-lane unclaimed replies as Yui's inbox items (yeses first, then newest). */
    public static function inbox(array $unclaimed): array
    {
        $out = [];
        foreach ($unclaimed as $u) {
            if (($u['lane'] ?? '') !== 'client') continue;
            $out[] = $u + ['section' => 'inbox', 'template' => 'reply'];
        }
        usort($out, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] <=> $b['priority'];
            return strcmp((string)$b['at'], (string)$a['at']);
        });
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Drafts
    // ─────────────────────────────────────────────────────────────────────────

    public static function vars(array $item, string $owner): array
    {
        $to = (array)($item['to'] ?? []);
        $inv = (array)($item['invoices'] ?? []);
        $numbers = array_map(fn($i) => $i['number'], $inv);
        $list = count($numbers) <= 1 ? 'invoice ' . ($numbers[0] ?? '')
            : 'invoices ' . implode(', ', array_slice($numbers, 0, -1)) . ' and ' . end($numbers);
        $subject = UnclaimedReplyService::cleanSubject((string)($item['subject'] ?? ''));
        return [
            '{first_name}'   => (string)($to['first_name'] ?? ($item['name'] ?? '')) ?: 'there',
            '{owner}'        => $owner !== '' ? $owner : 'the Mowology team',
            '{phone}'        => self::OFFICE_PHONE,
            '{place}'        => (string)($item['place'] ?? '') ?: 'your property',
            '{subject}'      => $subject !== '' ? $subject : 'your note',
            '{end_date}'     => !empty($item['end_date']) ? date('F j', strtotime((string)$item['end_date'])) : 'the end of the term',
            '{season_start}' => !empty($item['season_start']) ? date('F j', strtotime((string)$item['season_start'])) : 'November 1',
            '{invoice_list}' => trim($list),
            '{is_are}'       => count($inv) > 1 ? 'are' : 'is',
            '{invoice_word}' => count($inv) > 1 ? 'invoices' : 'invoice',
            '{it_them}'      => count($inv) > 1 ? 'them' : 'it',
            '{total}'        => SalesDeskService::money((float)($item['total'] ?? 0)),
            '{oldest_due}'   => !empty($item['oldest_due']) ? date('F j', strtotime((string)$item['oldest_due'])) : 'a while ago',
        ];
    }

    /**
     * Take this client's details back out of Tim's edited email, so it can be the next draft
     * for the template. Only identity values become {placeholders} — never grammar words
     * ("is", "are", "them"), which would corrupt every other sentence.
     */
    public static function learnFrom(string $body, array $item, string $owner): string
    {
        $vars = array_intersect_key(self::vars($item, $owner), array_flip(['{first_name}', '{owner}', '{phone}', '{place}', '{end_date}',
                                                                             '{season_start}', '{invoice_list}', '{total}', '{oldest_due}']));
        $out = SamFollowupService::unfill($body, $vars);
        return (string)preg_replace('/\{invoice_list\} (?:is|are)\b/', '{invoice_list} {is_are}', $out);
    }

    /** The suggested email + text for a sendable item. $learned: Tim's last edit for this template (placeholders kept). */
    public static function draft(array $item, string $owner, ?string $learned = null): array
    {
        $tpl = isset(self::TEMPLATES[$item['template'] ?? '']) ? $item['template'] : 'reply';
        [$subject, $body, $sms] = self::TEMPLATES[$tpl];
        $vars = self::vars($item, $owner);
        $by = 'template';
        if ($learned !== null && $learned !== '' && $tpl !== 'reply') { $body = $learned; $by = 'learned'; }
        $smsText = SamFollowupService::fill($sms, $vars);
        if (self::smsProblems($smsText)) $smsText = SamFollowupService::fill(self::SMS_FALLBACK, $vars);
        $subj = SamFollowupService::fill($subject, $vars);
        if ($tpl === 'reply' && !preg_match('/^re:/i', $subj)) $subj = 'Re: ' . $subj;
        return ['template' => $tpl, 'drafted_by' => $by, 'subject' => $subj, 'body' => SamFollowupService::fill($body, $vars), 'sms' => $smsText];
    }

    /**
     * Why a text would be dropped or break the house rules (CLAUDE.md rule 11): over 160
     * characters, a link or domain, special characters, no office phone number, or not
     * pointing them to their email. Empty = fine.
     */
    public static function smsProblems(string $text): array
    {
        $p = SamFollowupService::smsProblems($text);
        if (trim($text) === '') return $p;
        if (strpos($text, self::OFFICE_PHONE) === false) $p[] = 'it needs the office number ' . self::OFFICE_PHONE;
        if (!preg_match('/\bemail\b/i', $text)) $p[] = 'it should point them to their email';
        return $p;
    }

    /** What Claude is told for an inbox reply: the house rules, the thread, and how Tim writes. */
    public static function replyPrompt(array $item, array $thread, string $owner, array $examples): array
    {
        $first = (string)(($item['to']['first_name'] ?? '') ?: ($item['name'] ?? '')) ?: 'there';
        if ($first === 'A customer' || $first === 'A client') $first = 'there';
        $lines = array_map(fn($m) => (($m['direction'] ?? $m['dir'] ?? '') === 'inbound' ? 'CLIENT' : 'US')
            . (($m['channel'] ?? '') === 'sms' ? ' by text' : '') . ' (' . ($m['sent_at'] ?? $m['at'] ?? '') . '): ' . trim((string)($m['snippet'] ?? '')),
            array_reverse($thread));
        $rules = SamFollowupService::copyRules();
        $system = ($rules !== '' ? $rules . "\n\n---\n\n" : '')
            . "You draft short, friendly, plain emails for {$owner}, owner of Mowology, a landscaping and snow removal company in Vancouver, "
            . "to an EXISTING client. Write as {$owner}, first person. Answer what the client actually said; never invent prices, dates, "
            . "visits or promises that aren't in the conversation — if something needs checking, say {$owner} will check and get back to them. "
            . "No subject line, no placeholders, no markdown. Start with 'Hi {$first},' and end with 'Thanks,\\n{$owner}'. Under 120 words.";
        $user = "Conversation (oldest first):\n" . implode("\n", $lines)
            . ($examples ? "\n\nHow {$owner} writes (recent emails he sent):\n---\n" . implode("\n---\n", array_map(fn($e) => mb_substr((string)$e, 0, 600), $examples)) : '')
            . "\n\nDraft {$owner}'s reply to the client's latest message.";
        return ['system' => $system, 'user' => $user];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Brief for Charlie
    // ─────────────────────────────────────────────────────────────────────────

    /** Brief order: yeses and promises, arrears, the rest of the inbox, accounts, renewals. */
    public static function rank(array $item): int
    {
        $s = (string)($item['section'] ?? '');
        if ($s === 'inbox' && !empty($item['yes'])) return self::BRIEF_RANK['yes'];
        if ($s === 'promises') return self::BRIEF_RANK['promise'];
        return self::BRIEF_RANK[$s] ?? 9;
    }

    /**
     * @param array $sections section => items (from the desk)
     * @return array{head: string, headline: string, items: array, count: int}
     */
    public static function brief(array $sections, int $max = 40): array
    {
        $all = [];
        $n = 0;   // ties keep the card's order: section, then position
        foreach (self::SECTIONS as $s) foreach ((array)($sections[$s] ?? []) as $it) $all[] = $it + ['_i' => $n++];
        usort($all, function ($a, $b) {
            $ra = self::rank($a); $rb = self::rank($b);
            return $ra !== $rb ? $ra <=> $rb : $a['_i'] <=> $b['_i'];
        });
        $items = array_map(fn($it) => array_intersect_key($it, array_flip(['key', 'kind', 'value', 'since', 'text', 'url', 'priority'])),
            array_slice($all, 0, $max));
        return ['head' => self::HEAD, 'headline' => self::headline($sections), 'items' => $items, 'count' => count($all)];
    }

    public static function headline(array $sections): string
    {
        $inbox = (array)($sections['inbox'] ?? []);
        $yes = count(array_filter($inbox, fn($i) => !empty($i['yes'])));
        $promises = count((array)($sections['promises'] ?? []));
        $arrears = (array)($sections['arrears'] ?? []);
        if ($yes + $promises > 0) {
            $parts = [];
            if ($yes) $parts[] = $yes . ' client' . ($yes === 1 ? '' : 's') . ' said yes';
            if ($promises) $parts[] = $promises . ' approval' . ($promises === 1 ? '' : 's') . ' with no accepted quote';
            return implode(' · ', $parts);
        }
        if ($arrears) {
            $total = array_sum(array_map(fn($a) => (float)$a['total'], $arrears));
            return SalesDeskService::money($total) . ' more than ' . self::ARREARS_DAYS . ' days overdue — ' . count($arrears)
                . ' conversation' . (count($arrears) === 1 ? '' : 's') . ' to have';
        }
        if ($inbox) return count($inbox) . ' client repl' . (count($inbox) === 1 ? 'y is' : 'ies are') . ' waiting on an answer';
        $acc = count((array)($sections['accounts'] ?? []));
        $ren = count((array)($sections['renewals'] ?? []));
        if ($ren) return $ren . ' renewal' . ($ren === 1 ? '' : 's') . ' and check-ins due';
        if ($acc) return $acc . ' account detail' . ($acc === 1 ? '' : 's') . ' to tidy';
        return 'All quiet with existing clients';
    }
}
