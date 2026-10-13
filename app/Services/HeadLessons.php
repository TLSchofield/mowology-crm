<?php
/**
 * HeadLessons — one learning record for every department head (migration 1348, plan
 * docs/plans/2026-10-13-penny-upgrade-and-head-learning.md Part A1).
 *
 * Every place a person overrides a head's answer writes one row: which head, about what
 * (subject_kind + subject_key: a vendor, a bank wording, an invoice…), which field, what
 * the head said, what was right, who corrected it, from which code path, and why.
 * Penny writes first (PennyLessons is her adapter); other heads join later.
 *
 * Rules:
 *   - Recording NEVER blocks the person's action: recordQuietly() catches and logs.
 *   - A "correction" that changes nothing (said == correct) is not a lesson.
 *   - Tenant-private: every row carries site_id (Ittai). Shared knowledge is a later layer.
 *   - Nothing here changes the books or any learner yet; A2 reads countConsistent().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class HeadLessons
{
    public const TABLE = 'head_lessons';
    public const MAX_KEY = 120;
    public const MAX_TEXT = 2000;

    private PDO $db;
    private int $siteId;

    public function __construct(PDO $db, int $siteId = 1)
    {
        $this->db = $db;
        $this->siteId = $siteId;
    }

    // ── Pure (unit tested) ────────────────────────────────────────────────────

    /** A subject key the same way every time: lower case, single spaces, trimmed, ≤ 120 chars. */
    public static function normaliseKey(?string $key): string
    {
        $k = strtolower(trim((string)preg_replace('/\s+/u', ' ', (string)$key)));
        return function_exists('mb_substr') ? mb_substr($k, 0, self::MAX_KEY) : substr($k, 0, self::MAX_KEY);
    }

    /** A said / correct value as text: null stays null, bools are yes/no, arrays are JSON. */
    public static function valueText($v): ?string
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? 'yes' : 'no';
        if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $s = trim((string)$v);
        if ($s === '') return null;
        return function_exists('mb_substr') ? mb_substr($s, 0, self::MAX_TEXT) : substr($s, 0, self::MAX_TEXT);
    }

    /** Nothing was really corrected (5 vs "5", " Fuel" vs "Fuel", 12.5 vs 12.50). */
    public static function isNoChange($said, $correct): bool
    {
        $a = self::valueText($said);
        $b = self::valueText($correct);
        if ($a === $b) return true;
        if ($a !== null && $b !== null && is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) < 0.005;
        return false;
    }

    /**
     * A clean row from a caller's lesson, or null when it is not a lesson.
     * Required: head, subject_kind, subject_key, field, source; said ≠ correct.
     */
    public static function row(array $l, int $siteId = 1): ?array
    {
        $head = strtolower(trim((string)($l['head'] ?? '')));
        $kind = strtolower(trim((string)($l['subject_kind'] ?? '')));
        $key = self::normaliseKey($l['subject_key'] ?? '');
        $field = trim((string)($l['field'] ?? ''));
        $source = trim((string)($l['source'] ?? ''));
        if ($head === '' || $kind === '' || $key === '' || $field === '' || $source === '') return null;
        if (self::isNoChange($l['said'] ?? null, $l['correct'] ?? null)) return null;
        $by = isset($l['corrected_by']) && (int)$l['corrected_by'] > 0 ? (int)$l['corrected_by'] : null;
        $refId = isset($l['ref_id']) && (int)$l['ref_id'] > 0 ? (int)$l['ref_id'] : null;
        $reason = self::valueText($l['reason'] ?? null);
        return [
            'site_id'      => (int)($l['site_id'] ?? $siteId),
            'head'         => substr($head, 0, 20),
            'subject_kind' => substr($kind, 0, 30),
            'subject_key'  => $key,
            'field'        => substr($field, 0, 40),
            'said'         => self::valueText($l['said'] ?? null),
            'correct'      => self::valueText($l['correct'] ?? null),
            'source'       => substr($source, 0, 60),
            'ref_type'     => isset($l['ref_type']) && $l['ref_type'] !== '' ? substr((string)$l['ref_type'], 0, 30) : null,
            'ref_id'       => $refId,
            'corrected_by' => $by,
            'reason'       => $reason === null ? null : substr($reason, 0, 255),
        ];
    }

    /**
     * How consistent the corrections to one subject+field are (for A2's "3+ times, no
     * contradiction"). $corrects = the `correct` values, oldest first.
     * @return array{value: ?string, count: int, contradictions: int, total: int}
     *   value = the latest answer; count = rows giving that answer; contradictions = rows giving another.
     */
    public static function tally(array $corrects): array
    {
        $total = count($corrects);
        if (!$total) return ['value' => null, 'count' => 0, 'contradictions' => 0, 'total' => 0];
        $latest = self::valueText(end($corrects));
        $same = 0;
        foreach ($corrects as $c) {
            if (self::isNoChange($c, $latest)) $same++;
        }
        return ['value' => $latest, 'count' => $same, 'contradictions' => $total - $same, 'total' => $total];
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /** @return int|null the new row's id; null when it is not a lesson. Throws on a database error. */
    public function record(array $lesson): ?int
    {
        $r = self::row($lesson, $this->siteId);
        if (!$r) return null;
        $this->db->prepare("INSERT INTO " . self::TABLE . " (site_id, head, subject_kind, subject_key, field, said, correct, source,
                                ref_type, ref_id, corrected_by, reason, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$r['site_id'], $r['head'], $r['subject_kind'], $r['subject_key'], $r['field'], $r['said'], $r['correct'],
                      $r['source'], $r['ref_type'], $r['ref_id'], $r['corrected_by'], $r['reason'], date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
    }

    /** record(), but a failure (no table before migration 1348, a bad value) is logged, never thrown. */
    public static function recordQuietly(PDO $db, array $lesson): ?int
    {
        try {
            return (new self($db))->record($lesson);
        } catch (Throwable $e) {
            error_log('Head lesson not recorded (' . ($lesson['head'] ?? '?') . '/' . ($lesson['source'] ?? '?') . '): ' . $e->getMessage());
            return null;
        }
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    /** Lessons about one subject (optionally one field), newest first. */
    public function forSubject(string $head, string $kind, string $key, ?string $field = null, int $limit = 50): array
    {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE site_id = ? AND head = ? AND subject_kind = ? AND subject_key = ?";
        $args = [$this->siteId, strtolower($head), strtolower($kind), self::normaliseKey($key)];
        if ($field !== null) { $sql .= " AND field = ?"; $args[] = $field; }
        $s = $this->db->prepare($sql . " ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(500, $limit)));
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** A head's lessons from the last $days days, newest first. */
    public function recentFor(string $head, int $days = 30, int $limit = 200): array
    {
        $s = $this->db->prepare("SELECT * FROM " . self::TABLE . " WHERE site_id = ? AND head = ? AND created_at >= ?
                                 ORDER BY created_at DESC, id DESC LIMIT " . max(1, min(1000, $limit)));
        $s->execute([$this->siteId, strtolower($head), date('Y-m-d H:i:s', time() - max(0, $days) * 86400)]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** tally() over one subject+field's corrections (A2 reads this). */
    public function countConsistent(string $head, string $kind, string $key, string $field): array
    {
        $s = $this->db->prepare("SELECT correct FROM " . self::TABLE . " WHERE site_id = ? AND head = ? AND subject_kind = ? AND subject_key = ? AND field = ?
                                 ORDER BY created_at, id");
        $s->execute([$this->siteId, strtolower($head), strtolower($kind), self::normaliseKey($key), $field]);
        return self::tally($s->fetchAll(PDO::FETCH_COLUMN));
    }
}
