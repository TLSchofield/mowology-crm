<?php
/**
 * The onboarding modules (2026-10-20). One class each; OnboardingService::defaultModules() lists them and the
 * owner's order (ops_settings onboarding_order) arranges them. To add one (a trade's own step, "Import from
 * Jobber"): a class here + one line in defaultModules().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OnboardingModule.php';

/** "What's your name, and what should I call you?" */
class OnboardingNameModule extends OnboardingModule
{
    public function key(): string { return 'name'; }
    public function title(): string { return 'Your name'; }
    public function owner(): string { return 'penny'; }
    public function skippable(): bool { return false; }
    public function question(OnboardingContext $c): string { return "Hi, I'm Penny. What's your name, and what should I call you?"; }

    public function state(OnboardingContext $c): string
    {
        return trim((string)$c->one('SELECT preferred_name FROM users WHERE id = ?', [$c->userId])) !== '' ? self::DONE : self::TODO;
    }

    public function summary(OnboardingContext $c): string
    {
        $p = trim((string)$c->one('SELECT preferred_name FROM users WHERE id = ?', [$c->userId]));
        return $p !== '' ? 'Penny calls you ' . $p . '.' : '';
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
