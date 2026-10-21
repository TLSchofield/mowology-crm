<?php
/**
 * OnboardingModule — one section of onboarding (owner, 2026-10-20: "each section is a module that can be
 * moved up or down the step progress — QuickBooks being one, company name another").
 *
 * A module knows only itself: its title, its owner (which AI head), what it needs first, whether it is done
 * (worked out from real data, never a tick box that can drift), its one-line summary for the map, and the
 * one question Penny asks on its screen. The ORDER lives in settings (OnboardingService), so modules can be
 * moved, skipped, and new ones added (a trade's own step) without touching the others.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
abstract class OnboardingModule
{
    public const DONE = 'done';
    public const TODO = 'todo';
    public const NOT_NEEDED = 'not_needed';   // e.g. "Your books" when QuickBooks keeps them

    abstract public function key(): string;
    abstract public function title(): string;
    /** penny | sam | otto | charlie — who looks after what this module fills. */
    abstract public function owner(): string;
    /** What Penny says on the module's screen (one sentence). */
    abstract public function question(OnboardingContext $c): string;
    abstract public function state(OnboardingContext $c): string;
    /** One line for the map ("34 clients imported"); '' when there is nothing to say yet. */
    abstract public function summary(OnboardingContext $c): string;

    /** Modules that must come BEFORE this one in the order. */
    public function needs(): array { return []; }
    /** Can the owner skip it? */
    public function skippable(): bool { return true; }
    /** Pinned modules (Done) never move. */
    public function pinned(): bool { return false; }
}

/** What a module may read: the database, who is onboarding, and onboarding's own settings. */
class OnboardingContext
{
    public PDO $db;
    public int $userId;
    private array $settings = [];

    public function __construct(PDO $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }

    public function setting(string $key): ?string
    {
        if (!array_key_exists($key, $this->settings)) {
            try {
                $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
                $s->execute([$key]);
                $v = $s->fetchColumn();
                $this->settings[$key] = $v === false ? null : (string)$v;
            } catch (Throwable $e) {
                $this->settings[$key] = null;
            }
        }
        return $this->settings[$key];
    }

    public function saveSetting(string $key, ?string $value, string $desc): void
    {
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
        $u->execute([$value, $key]);
        if ($u->rowCount() === 0 && $this->setting($key) === null) {
            $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $desc]);
        }
        $this->settings[$key] = $value;
    }

    /** A query that may hit a table not there yet (a migration not run): null instead of an error. */
    public function one(string $sql, array $p = [])
    {
        try {
            $s = $this->db->prepare($sql);
            $s->execute($p);
            $v = $s->fetchColumn();
            return $v === false ? null : $v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
