<?php
declare(strict_types=1);

/**
 * MulchPriceBand — the three prices Sam shows for a yard of mulch / soil / compost, each with its reason (Tim, 2026-11-03):
 *   the FLOOR     what you must charge to earn your target margin (cost-plus; the number Sam always had)
 *   PAID          what you have actually been paid per yard, from accepted quotes, with the margin at that price
 *   MARKET        what others publish for the same material (My Garden Bag's public prices, read weekly), and what that is
 *   OWN RATE      optional: what you say other landscapers charge installed
 * plus one plain reading of where they sit. It never recommends a number and never writes a price: Tim decides.
 * Absent is never zero: a missing source says so, it is not a $0 line. Pure and static; PHP 7.4.
 */
final class MulchPriceBand
{
    public const STALE_DAYS = 14;
    public const FEW_WON = 3;
    /** A "per yard" line priced above this many times the catalogue price is treated as a flat job. */
    public const FLAT_JOB_FACTOR = 2.5;
    private const PER_YARD_UNITS = ['', 'yd', 'yard', 'yards', 'cubic yard', 'yd3', 'yd³'];
    private const KEYWORDS = ['composted', 'cedar', 'hemlock', 'bark', 'black', 'chip', 'hog'];

    /**
     * @param array $in floor{sell,cost,margin_pct}|null, yards_per_load, product_name|null, yard_names[] (lowercase names of products sold by the
     *                  cubic yard), quotes[] {quote,status,date,service_type,unit_type,unit_price}, market[] {source,what,product,sizes[yards=>price],seen_on},
     *                  market_read|null (newest source read), own_rate (0 = not set), today
     * @return array{floor: array, paid: array, market: array, own: ?array, reading: ?string, notes: string[]}
     */
    public static function build(array $in): array
    {
        $today = (string)($in['today'] ?? date('Y-m-d'));
        $floor = self::floor($in['floor'] ?? null);
        $paid = self::paid($in, $floor);
        $market = self::market(['product_name' => $in['product_name'] ?? ($paid['product'] ?? null)] + $in, $today);
        $own = self::own((float)($in['own_rate'] ?? 0));
        $notes = [];
        if ($own === null) $notes[] = 'Tell Sam what other landscapers charge installed and it shows here as a fourth line.';
        return ['floor' => $floor, 'paid' => $paid, 'market' => $market, 'own' => $own,
                'reading' => self::reading($floor, $paid, $market, $own), 'notes' => $notes];
    }

    public static function money(float $v): string
    {
        $a = abs($v);
        return ($v < 0 ? '-' : '') . '$' . (abs($a - round($a)) < 0.005 ? number_format($a, 0) : number_format($a, 2));
    }

    private static function day(string $d): string
    {
        $t = strtotime($d);
        return $t ? date('M j', $t) : $d;
    }

    // ── 1. the floor ────────────────────────────────────────────────────────
    private static function floor($f): array
    {
        if (!is_array($f) || !isset($f['sell']) || (float)$f['sell'] <= 0) {
            return ['label' => 'Your floor', 'price' => null, 'cost' => null, 'text' => "Sam can't work out the floor yet: a cost input is missing."];
        }
        $m = isset($f['margin_pct']) ? rtrim(rtrim(number_format((float)$f['margin_pct'], 1), '0'), '.') . '%' : 'your';
        return ['label' => 'Your floor', 'price' => (float)$f['sell'], 'cost' => isset($f['cost']) && (float)$f['cost'] > 0 ? (float)$f['cost'] : null,
                'text' => (isset($f['cost']) && (float)$f['cost'] > 0
                            ? 'Your cost is ' . self::money((float)$f['cost']) . ' a yard; at ' . $m . ' target margin that is ' . self::money((float)$f['sell'])
                            : 'At ' . $m . ' target margin the floor is ' . self::money((float)$f['sell']) . ' (the cost behind it is not known)')
                        . '. Below it you earn less than you aim for.'];
    }

    // ── 2. what you have been paid ──────────────────────────────────────────
    private static function paid(array $in, array $floor): array
    {
        $names = array_map('strtolower', array_map('trim', (array)($in['yard_names'] ?? [])));
        $product = isset($in['product_name']) ? strtolower(trim((string)$in['product_name'])) : '';
        $lines = [];
        $skipped = 0;
        foreach ((array)($in['quotes'] ?? []) as $q) {
            $name = strtolower(trim((string)($q['service_type'] ?? '')));
            $unit = strtolower(trim((string)($q['unit_type'] ?? '')));
            if ((float)($q['unit_price'] ?? 0) <= 0 || !in_array((string)($q['status'] ?? ''), ['accepted', 'declined', 'expired'], true)) continue;
            if (!in_array($name, $names, true) || !in_array($unit, self::PER_YARD_UNITS, true)) continue; // a flat job or another unit is not a per-yard price
            // A blank unit does not prove "per yard": a flat job quoted under the product's name looks the same. Far above the catalogue price = not a yard price.
            $ref = (float)(($in['catalogue'] ?? [])[$name] ?? 0) ?: (float)($floor['price'] ?? 0);
            if ($ref > 0 && (float)$q['unit_price'] > $ref * self::FLAT_JOB_FACTOR) { $skipped++; continue; }
            $lines[] = $q + ['_name' => $name];
        }
        $byQuote = [];
        foreach ($lines as $q) {
            $k = (string)($q['quote'] ?? spl_object_id((object)[]));
            if (!isset($byQuote[$k]) || (float)$q['unit_price'] > (float)$byQuote[$k]['unit_price']) $byQuote[$k] = $q; // one price per quote
        }
        $lines = array_values($byQuote);
        if ($product === '' && $lines) {
            // No product named: the one actually sold the most (most accepted quotes, then the newest), not the first the catalogue lists.
            $tally = [];
            foreach ($lines as $q) {
                if ($q['status'] !== 'accepted') continue;
                $t = $tally[$q['_name']] ?? ['n' => 0, 'last' => ''];
                $tally[$q['_name']] = ['n' => $t['n'] + 1, 'last' => max($t['last'], (string)$q['date'])];
            }
            uasort($tally, fn($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
            $product = (string)(array_key_first($tally) ?? '');
        }
        $same = $product !== '' ? array_values(array_filter($lines, fn($q) => $q['_name'] === $product)) : [];
        $use = $same ?: $lines;
        $other = $product !== '' && !$same && $lines;
        $chosen = $same ? (string)($same[0]['service_type'] ?? '') : null;
        $won = array_values(array_filter($use, fn($q) => $q['status'] === 'accepted'));
        $lost = count(array_filter($use, fn($q) => $q['status'] === 'declined'));
        $expired = count(array_filter($use, fn($q) => $q['status'] === 'expired'));
        if (!$won) {
            return ['label' => 'What you have been paid', 'price' => null, 'highest' => null, 'won' => 0, 'lost' => $lost, 'expired' => $expired, 'product' => $chosen,
                    'text' => 'No accepted per-yard quote for this yet' . ($lost ? " ({$lost} declined)" : '') . ($expired ? " ({$expired} expired unanswered)" : '') . '.'];
        }
        usort($won, fn($a, $b) => [(string)$b['date'], (float)$b['unit_price']] <=> [(string)$a['date'], (float)$a['unit_price']]);
        $latest = $won[0];
        $price = (float)$latest['unit_price'];
        $high = max(array_map(fn($q) => (float)$q['unit_price'], $won));
        $n = count($won);
        $text = 'You were paid ' . self::money($price) . ' a yard on ' . $n . ' accepted quote' . ($n === 1 ? '' : 's') . ' (latest ' . self::day((string)$latest['date']) . ', ' . (string)($latest['quote'] ?? 'quote') . ')'
              . ($high > $price ? ', up to ' . self::money($high) : '') . ($lost ? ", {$lost} declined" : ', none declined') . ($expired ? ", {$expired} expired unanswered" : '') . '.';
        if ($skipped) $text .= ' ' . $skipped . ' line' . ($skipped === 1 ? '' : 's') . ' priced far above the catalogue price ' . ($skipped === 1 ? 'was' : 'were') . ' left out as a flat job.';
        if ($other) $text .= ' (These are other products of this kind; none is this exact one.)';
        elseif ($chosen) $text = 'For ' . $chosen . ': ' . $text;
        $cost = $floor['cost'];
        if ($cost !== null && $cost > 0) {
            $profit = $price - $cost;
            $trip = $profit * max(1.0, (float)($in['yards_per_load'] ?? 1));
            $text .= ' At that price you earn ' . self::money($profit) . ' a yard, ' . number_format($profit / $price * 100, 0) . '% margin, ' . self::money($trip)
                   . ' on a ' . rtrim(rtrim(number_format((float)($in['yards_per_load'] ?? 1), 1), '0'), '.') . '-yard trip.';
        }
        $text .= $n < self::FEW_WON ? ' That is too few quotes to call it your ceiling.'
               : ($lost === 0 ? ' None declined at this price, so there may be room above it.' : ' Some were declined, so it may be near the limit.');
        return ['label' => 'What you have been paid', 'price' => $price, 'highest' => $high, 'won' => $n, 'lost' => $lost, 'expired' => $expired, 'product' => $chosen, 'text' => $text];
    }

    // ── 3. the market ───────────────────────────────────────────────────────
    private static function market(array $in, string $today): array
    {
        $product = strtolower(trim((string)($in['product_name'] ?? '')));
        $cands = [];
        foreach ((array)($in['market'] ?? []) as $r) {
            if (strpos(strtolower((string)$r['product']), 'playground') !== false) continue;
            $one = null;
            foreach ((array)($r['sizes'] ?? []) as $yards => $price) {
                if ((float)$yards >= 1.0 && (float)$price > 0 && ($one === null || (float)$yards < $one['yards'])) $one = ['yards' => (float)$yards, 'price' => (float)$price];
            }
            if ($one === null) continue;
            $half = isset($r['sizes']['0.5']) ? (float)$r['sizes']['0.5'] * 2 : null;
            $cands[] = $r + ['_per' => round($one['price'] / $one['yards'], 2), '_half' => $half];
        }
        if (!$cands) {
            return ['label' => 'What the market charges', 'price' => null, 'text' => 'Sam has no market price for this yet: no source has published one.', 'stale' => false];
        }
        if ($product !== '') {
            $kw = array_values(array_filter(self::KEYWORDS, fn($k) => strpos($product, $k) !== false));
            if ($kw) {
                // The rows that share the MOST of the product's own words ("cedar bark" beats "composted bark"), cheapest of those.
                $score = fn($r) => count(array_filter($kw, fn($k) => strpos(strtolower((string)$r['product']), $k) !== false));
                $best = max(array_map($score, $cands));
                $cands = $best > 0 ? array_values(array_filter($cands, fn($r) => $score($r) === $best)) : [];
            }
        }
        if (!$cands) {
            return ['label' => 'What the market charges', 'price' => null, 'text' => 'Sam found no published price for a product like this one (' . ($in['product_name'] ?? 'this product') . ').', 'stale' => false];
        }
        usort($cands, fn($a, $b) => $a['_per'] <=> $b['_per']);
        $r = $cands[0];
        $reads = $in['market_read'] ?? null;
        $read = is_array($reads) ? (string)($reads[(string)$r['source']] ?? '') : (string)$reads; // each source has its own last read
        $stale = $read === '' || (strtotime($today) - (int)strtotime($read)) / 86400 > self::STALE_DAYS;
        $what = trim((string)($r['what'] ?? ''));
        $text = (string)$r['source'] . ' charges ' . self::money($r['_per']) . ' a yard for ' . (string)$r['product'] . ($what !== '' ? ': ' . rtrim($what, '.') : '')
              . ', at that price since ' . self::day((string)$r['seen_on']) . '. A customer who buys it there pays that and spreads it himself.';
        if ($r['_half'] !== null && $r['_half'] > $r['_per']) $text .= ' Half-yard bags work out at ' . self::money($r['_half']) . ' a yard.';
        $text .= ' Whether their price includes GST is not stated.';
        $text .= $read === '' ? ' When it was last read is unknown, so it may be out of date.'
               : ($stale ? ' Out of date: last read ' . self::day($read) . '.' : ' Last read ' . self::day($read) . '.');
        return ['label' => 'What the market charges', 'price' => $r['_per'], 'text' => $text, 'stale' => $stale, 'source' => (string)$r['source']];
    }

    // ── 4. Tim's own figure ─────────────────────────────────────────────────
    private static function own(float $rate): ?array
    {
        if ($rate <= 0) return null;
        return ['label' => 'What other landscapers charge', 'price' => $rate, 'text' => 'Your own figure for what others charge installed. Sam did not find it anywhere.'];
    }

    // ── the reading ─────────────────────────────────────────────────────────
    private static function reading(array $floor, array $paid, array $market, ?array $own): ?string
    {
        $p = $paid['price'];
        if ($p === null) return null;
        $parts = [];
        if ($floor['price'] !== null) {
            $d = $p - $floor['price'];
            $parts[] = abs($d) < 0.5 ? "{$paid['label']} equals your floor" : self::money(abs($d)) . ($d > 0 ? ' above' : ' BELOW') . ' your floor';
        }
        $room = false;
        if ($market['price'] !== null) {
            $d = $p - $market['price'];
            if (!empty($market['stale'])) {
                $parts[] = "unchecked against {$market['source']}'s price of " . self::money($market['price']) . ' because that reading is out of date';
            } else {
                $parts[] = self::money(abs($d)) . ($d > 0 ? ' above' : ' below') . " {$market['source']}'s delivered, unspread price";
                $room = $d < 0;
            }
        }
        if ($own !== null) {
            $d = $p - $own['price'];
            $parts[] = self::money(abs($d)) . ($d > 0 ? ' above' : ' below') . ' what you say others charge installed';
            $room = $room || $d < 0;
        }
        $s = 'Your last accepted price is ' . implode(', ', $parts) . '.';
        if ($room) $s .= ' The evidence says there may be room above it; it does not say how much, and the call is yours.';
        return $s;
    }
}
