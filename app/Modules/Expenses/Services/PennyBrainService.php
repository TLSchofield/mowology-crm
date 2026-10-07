<?php
/**
 * PennyBrainService — what Penny has learned, counted for her 3D "brain" on the dashboard.
 *
 * Every unit is something real, learned from the owner's corrections and approvals:
 *   trusted vendors   — 5 receipts in a row approved unchanged (PennyBadgeService)
 *   badges            — earned, never claimed (PennyBadgeService)
 *   stores by GPS     — vendor_locations learned from receipts (migration 1124)
 *   vendor categories — vendors whose category she learned (vendor_parse_profiles)
 *   reading lessons   — receipt_parse_lessons: a field the reader got wrong, corrected
 *                       by the owner and now remembered (vendor, total, item name…)
 * Counted from her start line (ops_settings penny_brain_baseline, set the first time the
 * brain is shown — 2026-10-05): what the receipt reader knew before Penny doesn't count,
 * so she starts at shape 1 and grows only with what she learns from then on.
 * The total picks her shape: one of 500, from a tetrahedron to a folded geodesic brain
 * (public/crm/js/penny-brain.js builds the shapes; one new shape per thing learned).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/PennyBadgeService.php';

class PennyBrainService
{
    public const SHAPES = 500;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{units: int, parts: array<int, array{key: string, label: string, n: int}>} */
    public function learned(): array
    {
        $pb = new PennyBadgeService($this->db);
        $trusted = count(array_filter($pb->vendors(1000), fn($v) => $v['trusted']));
        $badges = count($pb->badges()['earned']);
        $raw = [
            'trusted'  => $trusted,
            'badges'   => $badges,
            'stores'   => $this->count("SELECT COUNT(*) FROM vendor_locations WHERE source = 'learned'"),
            'category' => $this->count("SELECT COUNT(*) FROM vendor_parse_profiles WHERE learned_accounting_category IS NOT NULL AND learned_accounting_category <> ''"),
            'lessons'  => $this->count("SELECT COUNT(*) FROM receipt_parse_lessons"),
        ];
        $base = $this->baseline($raw);
        return self::combine(self::sinceBaseline($raw, $base['counts'])) + ['since' => $base['since']];
    }

    /** Her start line: the counts on the day the brain was first shown (saved then). */
    private function baseline(array $raw): array
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'penny_brain_baseline'")->fetchColumn();
            $b = $v ? json_decode((string)$v, true) : null;
            if (is_array($b) && isset($b['counts'])) return $b;
            $b = ['since' => date('Y-m-d'), 'counts' => $raw];
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description)
                VALUES ('penny_brain_baseline', ?, 'Penny brain start line: what was already learned before her brain started counting')
                ON DUPLICATE KEY UPDATE setting_key = setting_key
            ")->execute([json_encode($b)]);
            return $b;
        } catch (Throwable $e) {
            return ['since' => null, 'counts' => []];
        }
    }

    /** A count that is 0 when the table/column isn't there yet. */
    private function count(string $sql): int
    {
        try {
            return (int)$this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** What's new since the start line (never below zero — a lost streak isn't unlearning). */
    public static function sinceBaseline(array $raw, array $base): array
    {
        $out = [];
        foreach ($raw as $k => $v) $out[$k] = max(0, (int)$v - (int)($base[$k] ?? 0));
        return $out;
    }

    public static function combine(array $n): array
    {
        $labels = [
            'trusted'  => ['vendor trusted', 'vendors trusted'],
            'badges'   => ['badge', 'badges'],
            'stores'   => ['store found by GPS', 'stores found by GPS'],
            'category' => ['vendor category learned', 'vendor categories learned'],
            'lessons'  => ['reading lesson remembered', 'reading lessons remembered'],
        ];
        $parts = [];
        $units = 0;
        foreach ($labels as $k => [$one, $many]) {
            $c = max(0, (int)($n[$k] ?? 0));
            $units += $c;
            if ($c > 0) $parts[] = ['key' => $k, 'label' => $c . ' ' . ($c === 1 ? $one : $many), 'n' => $c];
        }
        return ['units' => $units, 'parts' => $parts];
    }

    /** Shape number (1-based) for this many things learned: one new shape per thing, up to SHAPES. */
    public static function shapeNumber(int $units): int
    {
        return max(1, min(self::SHAPES, $units + 1));
    }
}
