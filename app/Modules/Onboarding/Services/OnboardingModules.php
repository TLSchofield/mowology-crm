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
    public const PARTS = ['business', 'clients', 'services', 'suppliers', 'accounts'];

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

    public function state(OnboardingContext $c): string
    {
        $a = $this->answer($c);
        if ($a === 'no') return self::NOT_NEEDED;
        if ($a !== 'yes' || !$this->connected($c)) return self::TODO;
        return count(array_intersect(self::PARTS, $this->partsDone($c))) === count(self::PARTS) ? self::DONE : self::TODO;
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
