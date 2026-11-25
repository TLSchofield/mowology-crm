<?php
/**
 * QuoteIntakeDetails — the extra questions the public quote form asks strata and commercial leads
 * (2026-11-17: the form was built for homeowners; a council or a property manager is asked who decides,
 * how many buildings or sites, who has the work now, and when the next AGM / budget meeting is).
 *
 * Answers are cleaned here and folded into the request's notes (quote_requests.project_description) as one
 * "[Property details]" line, so Sam sees them with no schema change. split() takes the line back out so the
 * form can show the customer's own words and the fields separately when they come back from the review page.
 * Pure functions, no database. No namespace / no autoloader in production: require_once.
 */
class QuoteIntakeDetails
{
    public const MARKER = '[Property details]';

    /** property types that get the extra block */
    public const TYPES = ['strata', 'commercial'];

    public const DECIDERS = [
        ''                 => 'Not sure yet',
        'council'          => 'Strata council',
        'property_manager' => 'Property manager',
        'owner'            => 'Owner',
        'other'            => 'Someone else',
    ];

    private const LABELS = [
        'org_name'           => 'Management company or business',
        'site_count'         => 'Buildings or sites',
        'decision_maker'     => 'Decided by',
        'current_contractor' => 'Current contractor',
        'timing'             => 'Next AGM, budget meeting or start date',
    ];

    public static function applies(string $propertyType): bool
    {
        return in_array($propertyType, self::TYPES, true);
    }

    /** Cleaned answers keyed by field; [] when the property type does not get the block. */
    public static function clean(array $post, string $propertyType): array
    {
        if (!self::applies($propertyType)) {
            return [];
        }
        $text = static function ($v, int $max): string {
            $v = strip_tags((string)$v);
            $v = str_replace(['[', ']', ';'], ' ', $v); // keep the marker line parseable
            $v = trim((string)preg_replace('/\s+/', ' ', $v));
            return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
        };
        $decider = (string)($post['decision_maker'] ?? '');
        if (!array_key_exists($decider, self::DECIDERS)) {
            $decider = '';
        }
        return [
            'org_name'           => $text($post['org_name'] ?? '', 120),
            'site_count'         => $text($post['site_count'] ?? '', 40),
            'decision_maker'     => $decider,
            'current_contractor' => $text($post['current_contractor'] ?? '', 120),
            'timing'             => $text($post['timing'] ?? '', 80),
        ];
    }

    /** The customer's notes with the details line added; empty answers are left out. */
    public static function appendTo(string $description, array $intake, int $max = 2000): string
    {
        $parts = [];
        foreach (self::LABELS as $key => $label) {
            $value = (string)($intake[$key] ?? '');
            if ($value === '') {
                continue;
            }
            if ($key === 'decision_maker') {
                $value = self::DECIDERS[$value] ?? $value;
            }
            $parts[] = $label . ': ' . $value;
        }
        $description = self::split($description)['notes'];
        if ($parts === []) {
            return $description;
        }
        $line = self::MARKER . ' ' . implode('; ', $parts);
        $room = max(0, $max - self::len($line) - 2);
        $description = self::cut($description, $room);
        return ltrim($description . "\n\n" . $line);
    }

    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    /** Cut to $max characters without splitting a multibyte character. */
    private static function cut(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        $s = substr($s, 0, $max);
        return (string)preg_replace('/[\x80-\xBF]*$/', '', $s) === $s ? $s : (string)@iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }

    /** ['notes' => the customer's own words, 'line' => the details line or ''] */
    public static function split(string $description): array
    {
        $at = strpos($description, self::MARKER);
        if ($at === false) {
            return ['notes' => $description, 'line' => ''];
        }
        return [
            'notes' => rtrim(substr($description, 0, $at)),
            'line'  => trim(substr($description, $at)),
        ];
    }
}
