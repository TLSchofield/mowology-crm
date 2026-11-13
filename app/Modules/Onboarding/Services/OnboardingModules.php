<?php
/**
 * The onboarding modules (2026-10-20). One class each; OnboardingService::defaultModules() lists them and the
 * owner's order (ops_settings onboarding_order) arranges them. To add one (a trade's own step, "Import from
 * Jobber"): a class here + one line in defaultModules().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OnboardingModule.php';

/**
 * "About you" (owner, 2026-10-20): the three things Ittai needs about the person — the name to use, how they
 * want to be contacted, and when not to bother them. One screen each; the knowledge glyph locks a side per
 * answer and closes its circle when all three are known. Key stays 'name' so a saved order keeps its place.
 */
class OnboardingNameModule extends OnboardingModule
{
    public const PARTS = ['name', 'reach', 'quiet'];
    public const CHANNELS = ['text' => 'Text message', 'email' => 'Email', 'app' => 'App notification'];

    public function key(): string { return 'name'; }
    public function title(): string { return 'About you'; }
    public function owner(): string { return 'penny'; }
    public function skippable(): bool { return false; }
    public function question(OnboardingContext $c): string { return "Hi, I'm Penny. What's your name, and what should I call you?"; }

    public function me(OnboardingContext $c): array
    {
        try {
            $s = $c->db->prepare('SELECT first_name, last_name, preferred_name, phone, contact_channel, quiet_start, quiet_end, quiet_weekends FROM users WHERE id = ?');
            $s->execute([$c->userId]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];   // migration 1385 not run yet
        }
    }

    /** @return array{0:bool,1:bool,2:bool} name to use · how to reach · when not to bother */
    public function known(OnboardingContext $c): array
    {
        return self::knownFrom($this->me($c));
    }

    /** Pure, from a users row: the three sides (Penny's brain counts a person the same way). */
    public static function knownFrom(array $m): array
    {
        $channel = (string)($m['contact_channel'] ?? '');
        return [trim((string)($m['preferred_name'] ?? '')) !== '',
                isset(self::CHANNELS[$channel]) && ($channel !== 'text' || self::mobile((string)($m['phone'] ?? '')) !== null),
                !empty($m['quiet_start']) && !empty($m['quiet_end'])];
    }

    /** The first part still to answer, or null. */
    public function nextPart(OnboardingContext $c): ?string
    {
        foreach ($this->known($c) as $i => $k) if (!$k) return self::PARTS[$i];
        return null;
    }

    public function state(OnboardingContext $c): string
    {
        return in_array(false, $this->known($c), true) ? self::TODO : self::DONE;
    }

    public function summary(OnboardingContext $c): string
    {
        $m = $this->me($c);
        $name = trim((string)($m['preferred_name'] ?? ''));
        $how = self::reachNote($m);
        $bits = array_values(array_filter([$name !== '' ? 'Penny calls you ' . $name : '', $how]));
        return $bits ? implode(' · ', $bits) . '.' : '';
    }

    /** Pure: "by text message · quiet 8 pm–7 am and weekends" (the parts that are known). */
    public static function reachNote(array $m): string
    {
        $bits = [];
        if (isset(self::CHANNELS[(string)($m['contact_channel'] ?? '')])) $bits[] = 'by ' . strtolower(self::CHANNELS[$m['contact_channel']]);
        if (!empty($m['quiet_start']) && !empty($m['quiet_end'])) $bits[] = 'quiet ' . self::clock((string)$m['quiet_start']) . '–' . self::clock((string)$m['quiet_end']) . (!empty($m['quiet_weekends']) ? ' and weekends' : '');
        return implode(' · ', $bits);
    }

    /** Pure: "20:00:00" → "8 pm", "07:30:00" → "7:30 am". */
    public static function clock(string $t): string
    {
        $ts = strtotime('2000-01-01 ' . $t);
        if ($ts === false) return $t;
        return date((int)date('i', $ts) ? 'g:i a' : 'g a', $ts);
    }

    /** @return array{ok:bool, message:string} */
    public function save(OnboardingContext $c, string $first, string $last, string $preferred): array
    {
        $first = trim($first); $last = trim($last); $preferred = trim($preferred);
        if ($first === '') return ['ok' => false, 'message' => 'Your first name, please.'];
        if ($preferred === '') $preferred = $first;
        if (mb_strlen($first) > 60 || mb_strlen($last) > 60 || mb_strlen($preferred) > 60) return ['ok' => false, 'message' => 'A shorter name, please (60 letters at most).'];
        $c->db->prepare('UPDATE users SET first_name = ?, last_name = ?, full_name = ?, preferred_name = ? WHERE id = ?')
              ->execute([$first, $last, trim($first . ' ' . $last), $preferred, $c->userId]);
        return ['ok' => true, 'message' => 'Nice to meet you, ' . $preferred . '.'];
    }

    /** "How should we reach you?" — text needs a mobile number. */
    public function saveReach(OnboardingContext $c, string $channel, string $mobile): array
    {
        if (!isset(self::CHANNELS[$channel])) return ['ok' => false, 'message' => 'Pick how you\'d like to hear from us.'];
        $number = self::mobile($mobile);
        if ($channel === 'text' && $number === null) return ['ok' => false, 'message' => 'Your mobile number, please (10 digits), so the texts reach you.'];
        if ($channel === 'text') {
            $c->db->prepare('UPDATE users SET contact_channel = ?, phone = ? WHERE id = ?')->execute([$channel, $number, $c->userId]);
        } else {
            $c->db->prepare('UPDATE users SET contact_channel = ? WHERE id = ?')->execute([$channel, $c->userId]);
        }
        return ['ok' => true, 'message' => 'Got it — ' . strtolower(self::CHANNELS[$channel]) . '.'];
    }

    /**
     * Pure: a North American mobile number as "(604) 555-1212", or null. Only digits, spaces, + ( ) - . are allowed;
     * 10 digits, or 11 starting with 1 (Codex review 2026-10-21: "abc6045551212xyz" used to pass and be stored as typed).
     */
    public static function mobile(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^[\d\s+().-]+$/', $raw)) return null;
        $d = preg_replace('/\D+/', '', $raw);
        if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
        if (strlen($d) !== 10) return null;
        return '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6);
    }

    /** "When shouldn't we bother you?" — quiet from / to (may run past midnight), weekends optional. */
    public function saveQuiet(OnboardingContext $c, string $from, string $to, bool $weekends): array
    {
        $ok = fn($t) => (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (!$ok($from) || !$ok($to)) return ['ok' => false, 'message' => 'Pick a time for both, please.'];
        if ($from === $to) return ['ok' => false, 'message' => 'The start and end are the same — pick two different times.'];
        $c->db->prepare('UPDATE users SET quiet_start = ?, quiet_end = ?, quiet_weekends = ? WHERE id = ?')
              ->execute([$from . ':00', $to . ':00', $weekends ? 1 : 0, $c->userId]);
        return ['ok' => true, 'message' => 'Nothing from us between ' . self::clock($from) . ' and ' . self::clock($to) . ($weekends ? ', or at weekends' : '') . '.'];
    }
}

/**
 * "Before we start": Ittai's terms and privacy policy, accepted once. The version is the one in
 * public/includes/legal/ittai-legal.php (a unit test keeps the two equal — app/ cannot rely on a path to public/).
 */
class OnboardingTermsModule extends OnboardingModule
{
    public const TERMS_VERSION = '2026-11-06';
    public const TERMS_URL = 'https://ittai.ca/terms/';
    public const PRIVACY_URL = 'https://ittai.ca/privacy/';
    public const SETTING = 'onboarding_terms_accepted';

    public function key(): string { return 'terms'; }
    public function title(): string { return 'Terms'; }
    public function owner(): string { return 'penny'; }
    public function skippable(): bool { return false; }
    public function question(OnboardingContext $c): string { return "Before we start: here are Ittai's terms and privacy policy. Please read them and accept to carry on."; }

    public function state(OnboardingContext $c): string
    {
        return $c->setting(self::SETTING) === self::TERMS_VERSION ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        return $this->state($c) === self::DONE ? 'Accepted the terms (version ' . self::TERMS_VERSION . ').' : '';
    }

    public function accept(OnboardingContext $c): void
    {
        $c->saveSetting(self::SETTING, self::TERMS_VERSION, 'Onboarding: the terms version the owner accepted (OnboardingTermsModule)');
    }
}

/**
 * What a read QuickBooks already says about the book questions (GST, year-end, payroll), from the onboarding
 * snapshot. Pure from(); read() is null until QuickBooks has been read, and then every step asks as before.
 */
class OnboardingQboFacts
{
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** @return array{gst_registered:?bool, gst_number:?string, gst_number_raw:?string, gst_last_filed:?string, fiscal_start:?string, fiscal_end:?string, payroll_in_qbo:?bool}|null */
    public static function read(OnboardingContext $c): ?array
    {
        try {
            $s = $c->db->prepare("SELECT kind, data FROM qbo_onboarding_snapshot WHERE site_id = 1 AND kind IN ('agency', 'company') ORDER BY id");
            $s->execute();
            $all = $s->fetchAll(PDO::FETCH_ASSOC);
            if (!(int)$c->one('SELECT COUNT(*) FROM qbo_onboarding_snapshot WHERE site_id = 1')) return null;
        } catch (Throwable $e) {
            return null;
        }
        $agencies = []; $company = [];
        foreach ($all as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) continue;
            if ($r['kind'] === 'agency') $agencies[] = $d; elseif (!$company) $company = $d;
        }
        return self::from($agencies, $company);
    }

    /** Pure: the facts from the 'agency' rows and the 'company' row. */
    public static function from(array $agencies, array $company): array
    {
        $gst = null; $tax = null;
        foreach ($agencies as $a) {
            if (strtoupper((string)($a['TaxAgencyConfig'] ?? '')) === 'HIDDEN_AGENCY') continue;
            $gst = $gst ?? false;
            $name = (string)($a['DisplayName'] ?? ''); $reg = trim((string)($a['TaxRegistrationNumber'] ?? ''));
            if (!empty($a['TaxTrackedOnSales']) && ($reg !== '' || preg_match('/revenue|\bCRA\b/i', $name))) { $gst = true; $tax = $a; break; }
        }
        $raw = $tax !== null && trim((string)($tax['TaxRegistrationNumber'] ?? '')) !== '' ? trim((string)$tax['TaxRegistrationNumber']) : null;
        $start = trim((string)($company['FiscalYearStartMonth'] ?? ''));
        $start = in_array($start, self::MONTHS, true) ? $start : null;
        $payroll = null;
        foreach ((array)($company['NameValue'] ?? []) as $nv) {
            if (is_array($nv) && ($nv['Name'] ?? '') === 'PayrollFeature') $payroll = strtolower((string)($nv['Value'] ?? '')) === 'true' ? true : (strtolower((string)($nv['Value'] ?? '')) === 'false' ? false : null);
        }
        $last = $tax !== null ? trim((string)($tax['LastFileDate'] ?? '')) : '';
        return ['gst_registered' => $gst, 'gst_number' => $raw !== null ? OnboardingGstModule::gstNumber($raw) : null, 'gst_number_raw' => $raw,
                'gst_last_filed' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $last) ? $last : null,
                'fiscal_start' => $start, 'fiscal_end' => $start !== null ? self::MONTHS[(array_search($start, self::MONTHS, true) + 11) % 12] : null, 'payroll_in_qbo' => $payroll];
    }
}

/** "Is your business registered for GST?" — 'no' switches GST off (TaxEngine::gstRegistered). */
class OnboardingGstModule extends OnboardingModule
{
    public const FILING = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annually'];

    public function key(): string { return 'gst'; }
    public function title(): string { return 'GST'; }
    public function owner(): string { return 'penny'; }
    public function question(OnboardingContext $c): string
    {
        $f = OnboardingQboFacts::read($c);
        if ($f !== null && $f['gst_registered'] === true) {
            $n = $f['gst_number'] ?? $f['gst_number_raw'];
            return "QuickBooks says you're registered for GST" . ($n !== null ? ', number ' . $n : '') . '. Is that right — and how often do you file?';
        }
        if ($f !== null && $f['gst_registered'] === false) return 'QuickBooks shows no GST registration. Is that right?';
        return 'Is your business registered for GST?';
    }

    public function answer(OnboardingContext $c): ?string
    {
        $a = $c->setting('gst_registered');
        return in_array($a, ['yes', 'no', 'unsure'], true) ? $a : null;
    }

    public function state(OnboardingContext $c): string { return $this->answer($c) !== null ? self::DONE : self::TODO; }

    public function summary(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        if ($a === 'no') return 'Not registered for GST — no GST is charged or claimed.';
        if ($a === 'unsure') return 'Not sure about GST yet — it stays on until you say.';
        if ($a !== 'yes') return '';
        $f = (string)$c->setting('gst_filing');
        return 'Registered for GST' . (isset(self::FILING[$f]) ? ' · files ' . strtolower(self::FILING[$f]) : '') . '.';
    }

    /** Pure: "123456789" / "123456789RT0001" (spaces and dashes ignored) as "123456789RT0001"-style upper case, or null. */
    public static function gstNumber(string $raw): ?string
    {
        $n = strtoupper(preg_replace('/[\s-]+/', '', $raw));
        return preg_match('/^\d{9}(RT0001)?$/', $n) ? $n : null;
    }

    /** @return array{ok:bool, message:string} */
    public function save(OnboardingContext $c, string $registered, string $number = '', string $filing = ''): array
    {
        if (!in_array($registered, ['yes', 'no', 'unsure'], true)) return ['ok' => false, 'message' => 'Pick yes, no or not sure.'];
        $gst = null;
        if ($registered === 'yes') {
            // No number sent: the one QuickBooks has, when it is a valid GST number.
            if (trim($number) === '') $number = (string)((OnboardingQboFacts::read($c)['gst_number'] ?? null));
            if (trim($number) !== '' && ($gst = self::gstNumber($number)) === null) return ['ok' => false, 'message' => 'A GST number is 9 digits (then RT0001). Check it, or leave it blank for now.'];
            if ($filing !== '' && !isset(self::FILING[$filing])) return ['ok' => false, 'message' => 'Pick monthly, quarterly or annually.'];
        }
        $c->saveSetting('gst_registered', $registered, 'Onboarding: is the business registered for GST — only "no" switches GST off (OnboardingGstModule)');
        if ($registered === 'yes' && $filing !== '') $c->saveSetting('gst_filing', $filing, 'Onboarding: how often GST is filed (OnboardingGstModule)');
        if ($gst !== null) $c->db->prepare('UPDATE business_settings SET gst_registration = ? WHERE id = 1')->execute([$gst]);
        return ['ok' => true, 'message' => $registered === 'no' ? 'Got it — no GST will be charged or claimed.' : 'Got it.'];
    }
}

/** "Who does your year-end?" — an accountant or the owner. */
class OnboardingYearEndModule extends OnboardingModule
{
    public function key(): string { return 'yearend'; }
    public function title(): string { return 'Year-end'; }
    public function owner(): string { return 'penny'; }

    public function answer(OnboardingContext $c): ?string
    {
        $a = $c->setting('onboarding_yearend');
        return in_array($a, ['accountant', 'self'], true) ? $a : null;
    }

    public function question(OnboardingContext $c): string
    {
        $q = 'Who does your year-end — an accountant, or you?';
        $f = OnboardingQboFacts::read($c);
        if ($f !== null && $f['fiscal_start'] !== null) $q = 'Your books run ' . $f['fiscal_start'] . ' to ' . $f['fiscal_end'] . ' (from QuickBooks). ' . $q;
        if ($this->answer($c) === 'self') $q .= ' The terms ask that an accountant or bookkeeper reviews your books before you file. You can still invite one on the next step.';
        return $q;
    }

    public function state(OnboardingContext $c): string { return $this->answer($c) !== null ? self::DONE : self::TODO; }

    public function summary(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        return $a === 'accountant' ? 'Year-end: your accountant.' : ($a === 'self' ? 'Year-end: you.' : '');
    }

    /** @return array{ok:bool, message:string} */
    public function save(OnboardingContext $c, string $who): array
    {
        if (!in_array($who, ['accountant', 'self'], true)) return ['ok' => false, 'message' => 'Pick an accountant or yourself.'];
        $c->saveSetting('onboarding_yearend', $who, 'Onboarding: who does the year-end (OnboardingYearEndModule)');
        $start = OnboardingQboFacts::read($c)['fiscal_start'] ?? null;
        if ($start !== null) $c->saveSetting('onboarding_fiscal_start', $start, 'Onboarding: the month the books start, from QuickBooks (OnboardingYearEndModule)');
        return ['ok' => true, 'message' => 'Got it.'];
    }
}

/** "How do you run payroll today?" — nobody, another app (named), or the accountant. */
class OnboardingPayrollModule extends OnboardingModule
{
    public const APP_MAX = 60;

    public function key(): string { return 'payroll'; }
    public function title(): string { return 'Payroll'; }
    public function owner(): string { return 'penny'; }
    public const QBO_APP = 'QuickBooks Payroll';

    public function question(OnboardingContext $c): string
    {
        $f = OnboardingQboFacts::read($c);
        return $f !== null && $f['payroll_in_qbo'] === true ? 'You run payroll in QuickBooks — is that right?' : 'How do you run payroll today?';
    }

    public function answer(OnboardingContext $c): ?string
    {
        $a = $c->setting('onboarding_payroll');
        return in_array($a, ['none', 'app', 'accountant'], true) ? $a : null;
    }

    public function state(OnboardingContext $c): string { return $this->answer($c) !== null ? self::DONE : self::TODO; }

    public function summary(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        if ($a === 'none') return 'Payroll: no staff on payroll.';
        if ($a === 'accountant') return 'Payroll: your accountant.';
        if ($a !== 'app') return '';
        $n = trim((string)$c->setting('onboarding_payroll_app'));
        return 'Payroll: ' . ($n !== '' ? $n : 'another app') . '.';
    }

    /** Pure: the app name trimmed and length-checked, or null when empty or too long. */
    public static function appName(string $raw): ?string
    {
        $n = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
        return $n !== '' && mb_strlen($n) <= self::APP_MAX ? $n : null;
    }

    /** @return array{ok:bool, message:string} */
    public function save(OnboardingContext $c, string $how, string $app = ''): array
    {
        if (!in_array($how, ['none', 'app', 'accountant'], true)) return ['ok' => false, 'message' => 'Pick one of the three, please.'];
        $name = '';
        if ($how === 'app') {
            if (trim($app) !== '' && ($name = (string)self::appName($app)) === '') return ['ok' => false, 'message' => 'A shorter app name, please (' . self::APP_MAX . ' letters at most).'];
        }
        $c->saveSetting('onboarding_payroll', $how, 'Onboarding: how payroll is run today (OnboardingPayrollModule)');
        $c->saveSetting('onboarding_payroll_app', $how === 'app' ? $name : '', 'Onboarding: the payroll app named by the owner (OnboardingPayrollModule)');
        return ['ok' => true, 'message' => 'Got it.'];
    }
}

/** "What's your business called?" — the registry lookup that fills Business Settings (penny-onboarding). */
class OnboardingCompanyModule extends OnboardingModule
{
    public function key(): string { return 'company'; }
    public function title(): string { return 'Company name'; }
    public function owner(): string { return 'penny'; }
    public function skippable(): bool { return false; }
    public function question(OnboardingContext $c): string { return "What's your business called? I'll look it up and fill in your business settings."; }

    public function state(OnboardingContext $c): string
    {
        $n = $c->one('SELECT COALESCE(NULLIF(operating_name, \'\'), NULLIF(legal_name, \'\')) FROM business_identity WHERE id = 1');
        return trim((string)$n) !== '' ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        $n = trim((string)$c->one('SELECT COALESCE(NULLIF(operating_name, \'\'), NULLIF(legal_name, \'\')) FROM business_identity WHERE id = 1'));
        return $n !== '' ? $n . (($r = $c->one('SELECT registration_number FROM business_identity WHERE id = 1')) ? ' · ' . $r : '') : '';
    }
}

/**
 * "Do you use QuickBooks?" — yes: connect, then Penny reads it (business details, clients → Sam,
 * services → Otto, suppliers, accounts), one screen each. No: the "Your books" module takes over.
 */
class OnboardingQuickBooksModule extends OnboardingModule
{
    public const PARTS = ['business', 'clients', 'services', 'suppliers', 'accounts', 'contracts'];

    public function key(): string { return 'quickbooks'; }
    public function title(): string { return 'QuickBooks'; }
    public function owner(): string { return 'penny'; }
    public function question(OnboardingContext $c): string { return 'Do you use QuickBooks?'; }

    public function answer(OnboardingContext $c): ?string
    {
        $a = $c->setting('onboarding_uses_qbo');
        return in_array($a, ['yes', 'no'], true) ? $a : null;
    }

    public function connected(OnboardingContext $c): bool
    {
        return in_array((string)$c->one("SELECT status FROM qbo_connections WHERE site_id = 1 AND refresh_token_enc IS NOT NULL"), ['connected', 'error'], true);
    }

    /** @return list<string> parts the owner has accepted */
    public function partsDone(OnboardingContext $c): array
    {
        try {
            return array_map('strval', $c->db->query("SELECT part FROM qbo_onboarding_parts WHERE site_id = 1")->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Penny has read the connected QuickBooks at least once (a snapshot exists). */
    public function hasRead(OnboardingContext $c): bool
    {
        try {
            return (int)$c->one('SELECT COUNT(*) FROM qbo_onboarding_snapshot WHERE site_id = 1') > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Done once connected and read: accepting the five parts is optional and stays available (here and on the
     * QuickBooks page). Tim 2026-11-03 after Claudia walk 1, where "all five accepted" made Continue loop.
     */
    public function state(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        if ($a === 'no') return self::NOT_NEEDED;
        if ($a !== 'yes' || !$this->connected($c)) return self::TODO;
        return $this->hasRead($c) ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        if ($a === 'no') return 'No QuickBooks — Ittai keeps the books.';
        if ($a === null) return '';
        if (!$this->connected($c)) return 'Uses QuickBooks — not connected yet.';
        $done = count(array_intersect(self::PARTS, $this->partsDone($c)));
        return 'Connected · ' . $done . ' of ' . count(self::PARTS) . ' parts set up.';
    }

    public function setAnswer(OnboardingContext $c, bool $uses): void
    {
        $c->saveSetting('onboarding_uses_qbo', $uses ? 'yes' : 'no', 'Onboarding: does this business use QuickBooks (OnboardingQuickBooksModule)');
    }
}

/** The owner chooses which address-book contacts are clients before any contact leaves the phone. */
class OnboardingContactsModule extends OnboardingModule
{
    public function key(): string { return 'contacts'; }
    public function title(): string { return 'Your clients from your phone'; }
    public function owner(): string { return 'sam'; }
    public function skippable(): bool { return true; }
    public function pinned(): bool { return false; }
    public function needs(): array { return []; }
    public function question(OnboardingContext $c): string { return 'Do you keep clients in your phone? Pick the ones you work for.'; }

    public function answer(OnboardingContext $c): ?string
    {
        $answer = $c->setting('onboarding_contacts_answer');
        return in_array($answer, ['imported', 'skipped'], true) ? $answer : null;
    }

    public function state(OnboardingContext $c): string { return $this->answer($c) !== null ? self::DONE : self::TODO; }

    public function summary(OnboardingContext $c): string
    {
        $answer = $this->answer($c);
        if ($answer === 'skipped') return 'Skipped';
        return $answer === 'imported' ? (int)$c->setting('onboarding_contacts_count') . ' clients from your phone' : '';
    }

    public function finish(OnboardingContext $c, int $count): void
    {
        $c->saveSetting('onboarding_contacts_count', (string)max(0, $count), 'Onboarding: clients selected from phone contacts (OnboardingContactsModule)');
        $c->saveSetting('onboarding_contacts_answer', 'imported', 'Onboarding: phone contacts step answer (OnboardingContactsModule)');
    }

    public function skip(OnboardingContext $c): void
    {
        $c->saveSetting('onboarding_contacts_answer', 'skipped', 'Onboarding: phone contacts step answer (OnboardingContactsModule)');
    }
}

/** No QuickBooks: "I'll keep your books here, ready for your accountant." */
class OnboardingBooksModule extends OnboardingModule
{
    public function key(): string { return 'books'; }
    public function title(): string { return 'Your books'; }
    public function owner(): string { return 'penny'; }
    public function needs(): array { return ['quickbooks']; }
    public function question(OnboardingContext $c): string
    {
        return "No problem — I'll keep your books here: every invoice, payment and receipt, ready for your accountant whenever they want them.";
    }

    public function state(OnboardingContext $c): string
    {
        // Needed unless QuickBooks keeps the books — also when the QuickBooks step was skipped unanswered
        // (it used to loop: "Got it" saved, the step stayed to-do — Codex review 2026-10-20).
        if ($c->setting('onboarding_uses_qbo') === 'yes') return self::NOT_NEEDED;
        return $c->setting('onboarding_books_ack') === '1' ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        return $this->state($c) === self::DONE ? 'Ittai keeps the books for your accountant.' : '';
    }

    public function acknowledge(OnboardingContext $c): void
    {
        $c->saveSetting('onboarding_books_ack', '1', 'Onboarding: the owner has seen that Ittai keeps the books (OnboardingBooksModule)');
    }
}

/** "Would you like to invite your accountant?" — an accountant login, sent only when the owner presses Send. */
class OnboardingAccountantModule extends OnboardingModule
{
    public function key(): string { return 'accountant'; }
    public function title(): string { return 'Invite your accountant'; }
    public function owner(): string { return 'penny'; }
    public function question(OnboardingContext $c): string { return 'Would you like to invite your accountant? They get their own login to see the books — nothing else.'; }

    public function state(OnboardingContext $c): string
    {
        return ((int)$c->one("SELECT COUNT(*) FROM users WHERE role = 'accountant'") > 0 || $c->setting('onboarding_accountant_invited')) ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        $who = $c->setting('onboarding_accountant_invited');
        if ($who) return 'Invited ' . $who . '.';
        $n = (int)$c->one("SELECT COUNT(*) FROM users WHERE role = 'accountant'");
        return $n ? $n . ' accountant login' . ($n === 1 ? '' : 's') . '.' : '';
    }
}

/** The end of the path: everything else done, skipped or not needed. */
class OnboardingDoneModule extends OnboardingModule
{
    public function key(): string { return 'done'; }
    public function title(): string { return "You're set up"; }
    public function owner(): string { return 'charlie'; }
    public function skippable(): bool { return false; }
    public function pinned(): bool { return true; }
    public function question(OnboardingContext $c): string { return "That's everything. You can come back to any step from the map."; }
    public function state(OnboardingContext $c): string { return $c->setting('onboarding_complete') === '1' ? self::DONE : self::TODO; }
    public function summary(OnboardingContext $c): string { return $this->state($c) === self::DONE ? 'Finished.' : ''; }
}
