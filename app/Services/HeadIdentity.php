<?php
/**
 * HeadIdentity — the ONE place a department head's shown name, pronouns and photo come from (2026-10-21).
 *
 * The chief of staff is shown as "Kaname" (要, the pivot — owner, 2026-10-21; was "Charlie"). The internal key
 * stays `charlie` everywhere (tables charlie_*, ops_settings keys, file names, ?head=charlie): renaming those
 * would risk the live brief for no visible gain. In code read "charlie" as "Kaname (key: charlie)".
 *
 * A tenant can rename their chief of staff: ops_settings `chief_name`, `chief_pronoun` (he|she|they),
 * `chief_photo` override the defaults below. Every screen, email, push, prompt and JS string takes the name
 * from here (JS reads it from a data-chief-name attribute printed by the page).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class HeadIdentity
{
    public const CHIEF_KEY = 'charlie';

    public const CHIEF_DEFAULTS = [
        'name'    => 'Kaname',
        'role'    => 'Chief of Staff',
        'pronoun' => 'he',                        // he | she | they — the owner may change it
        'photo'   => '/crm/img/heads/charlie.jpg',
    ];

    private const PRONOUNS = [
        'he'   => ['they' => 'he',   'them' => 'him',  'their' => 'his',   'theyre' => "he's"],
        'she'  => ['they' => 'she',  'them' => 'her',  'their' => 'her',   'theyre' => "she's"],
        'they' => ['they' => 'they', 'them' => 'them', 'their' => 'their', 'theyre' => "they're"],
    ];

    /** Per database connection object, like BusinessIdentity (Codex 2026-10-23). */
    private static ?SplObjectStorage $chief = null;
    private static ?array $noDb = null;

    /**
     * The chief of staff as shown: name, role, photo and pronoun forms (they/them/their/theyre).
     * Reads the tenant's overrides once per request when a database is available; never throws.
     */
    public static function chief(?PDO $db = null): array
    {
        if ($db === null && function_exists('getDB')) {
            try { $db = getDB(); } catch (Throwable $e) { $db = null; }
        }
        self::$chief = self::$chief ?? new SplObjectStorage();
        if ($db && self::$chief->contains($db)) return self::$chief[$db];
        if (!$db && self::$noDb !== null) return self::$noDb;
        $c = self::CHIEF_DEFAULTS;
        if ($db) {
            try {
                $st = $db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('chief_name', 'chief_pronoun', 'chief_photo')");
                foreach ($st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [] as $k => $v) {
                    $v = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v));   // it reaches email headers
                    if ($v === '') continue;
                    if ($k === 'chief_name' && mb_strlen($v) <= 40) $c['name'] = $v;
                    if ($k === 'chief_pronoun' && isset(self::PRONOUNS[$v])) $c['pronoun'] = $v;
                    if ($k === 'chief_photo' && preg_match('#^/crm/img/[\w./-]+$#', $v)) $c['photo'] = $v;
                }
            } catch (Throwable $e) { /* defaults */ }
        }
        $out = $c + self::PRONOUNS[$c['pronoun']];
        if ($db) self::$chief[$db] = $out; else self::$noDb = $out;
        return $out;
    }

    /** The chief of staff's shown name ("Kaname"). */
    public static function chiefName(?PDO $db = null): string
    {
        return self::chief($db)['name'];
    }

    /** Pure, for tests: the chief with the given overrides applied. */
    public static function chiefWith(array $overrides): array
    {
        self::$chief = null;
        self::$noDb = null;
        $c = array_merge(self::CHIEF_DEFAULTS, array_filter($overrides, 'strlen'));
        if (!isset(self::PRONOUNS[$c['pronoun']])) $c['pronoun'] = self::CHIEF_DEFAULTS['pronoun'];
        return $c + self::PRONOUNS[$c['pronoun']];
    }

    /** Forget the cached chief (tests, or after the owner changes a setting in the same request). */
    public static function reset(): void
    {
        self::$chief = null;
        self::$noDb = null;
    }
}
