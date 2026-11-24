<?php
/**
 * MiaCalendar — the year of email campaigns Mia proposes (Lower Mainland lawn & garden year).
 *
 * Source of truth is the `mia_calendar` table (migration 1195) so Tim can edit dates, words and
 * conditions without a deploy. defaults() below is the same seed in PHP: it fills the table
 * (via the migration) and stands in when the table isn't there yet.
 *
 * Dates are MM-DD. An occurrence's "season year" is the year its send window starts; a propose
 * date later in the calendar than the send start belongs to the year before (a Jan campaign
 * proposed on Dec 29), and a send end earlier than the send start runs into the next year.
 * The proposal key is "<cal_key>_<season year>", e.g. fall_lawn_main_2026.
 *
 * Words: Tim's voice (first person, signed Tim, no exclamation marks, no discounts). Prices are
 * {price:<service>} tokens filled from the CRM's products at proposal time — never typed here.
 * A token the CRM can't fill stays in the text, and MiaCampaignService::problems() refuses it.
 * {{first_name}} is left for the campaign sender.
 *
 * Domain rules encoded (sourced 2026-10-06, see docs/crm/mia-email-hub.md):
 *   - Metro Vancouver lawn watering restrictions run May 1 – Oct 15; in Stage 2/3 no lawn
 *     watering and no new-seed permits, so lawn-seeding campaigns are held (conditions).
 *   - Aerate/overseed: April – early May, and mid-Sep – mid-Oct; seed by mid-April.
 *   - Hedges: nesting season Mar 15 – Aug 15 (BC Wildlife Act s.34).
 *   - Leaves Oct – Dec; irrigation blow-out late Oct – early Nov; strata winter contracts
 *     before Oct 1; strata budgets at the AGM within 2 months of fiscal year-end.
 *   - Send 3–4 weeks before the work window; Mia proposes ~7 days before the send window.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class MiaCalendar
{
    /** Tim's post-drought words, approved 2026-10-06 (the first campaign; kept word for word). */
    public const LEGACY_SUBJECT = 'Your lawn after the watering ban';
    public const LEGACY_BODY = "Hi {{first_name}},\n\n"
        . "A lot of lawns came through this summer brown and thin. The watering restrictions lift on October 15, and with the fall rain on its way, the second half of October is the right time to bring yours back. A lawn that goes into winter thin usually comes out of it full of moss and weeds.\n\n"
        . "Three things do most of the work:\n"
        . "- Aeration opens up compacted soil so rain and air reach the roots.\n"
        . "- Overseeding fills the thin and bare patches before winter.\n"
        . "- Top-dressing goes on with the seed: a thin layer of compost that holds moisture and feeds it.\n\n"
        . "One thing to know first: new seed needs light. If your lawn sits under trees, falling leaves will smother the seed before it takes. For those lawns we aerate now, and overseed and top-dress in spring once the leaves are done.\n\n"
        . "What it costs: aeration starts at \$95 and overseeding at \$90. Both go by the size of your lawn, so I'll measure yours, tell you what it actually needs, and send a fixed price before any work starts. Top-dressing depends on how much compost it takes, and goes in the same quote. You don't need to be home, and you'll get photos of the finished work.\n\n"
        . "If you'd like us to look at your lawn, reply to this email and I'll get back to you within a day.\n\n"
        . "Thanks,\nTim\n\n"
        . "P.S. Spring is our busiest season, so we only take spring work that's booked ahead. If you'd rather do it all in spring, reply \"spring\" and I'll hold a timeslot open for you whilst we work out the details together.";

    /** Audiences a calendar entry may name (comma-separated for several). */
    public const AUDIENCES = ['homeowners', 'property_managers', 'stratas', 'all_clients', 'past_seed', 'spring_holds'];

    /** A reminder that has fallen this many days behind its due date is not sent at all. */
    public const STALE_DAYS = 7;

    // ─────────────────────────────────────────────────────────────────────────
    // Loading
    // ─────────────────────────────────────────────────────────────────────────

    /** The calendar: the table if it exists (active rows), else the defaults. */
    public static function load(?PDO $db): array
    {
        if ($db) {
            try {
                $rows = $db->query("SELECT * FROM mia_calendar WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
                $out = [];
                foreach ($rows as $r) $out[(string)$r['cal_key']] = self::fromRow($r);
                return $out;
            } catch (Throwable $e) { /* before migration 1195 */ }
        }
        return self::defaults();
    }

    /** One table row as a calendar entry. */
    public static function fromRow(array $r): array
    {
        $cond = json_decode((string)($r['conditions_json'] ?? ''), true);
        return [
            'key'              => (string)$r['cal_key'],
            'name'             => (string)$r['name'],
            'audience'         => (string)$r['audience'],
            'propose_on'       => (string)$r['propose_on'],
            'send_from'        => (string)$r['send_from'],
            'send_to'          => (string)$r['send_to'],
            'work_window'      => (string)($r['work_window'] ?? ''),
            'services'         => array_values(array_filter(array_map('trim', explode(',', (string)($r['services'] ?? ''))))),
            'conditions'       => is_array($cond) ? $cond : [],
            'why'              => (string)($r['why'] ?? ''),
            'subject'          => (string)$r['subject'],
            'body'             => (string)$r['body_text'],
            'reminder_days'    => $r['reminder_days'] !== null && $r['reminder_days'] !== '' ? (int)$r['reminder_days'] : null,
            'reminder_subject' => (string)($r['reminder_subject'] ?? ''),
            'reminder_body'    => (string)($r['reminder_body'] ?? ''),
            'lastcall_days'    => $r['lastcall_days'] !== null && $r['lastcall_days'] !== '' ? (int)$r['lastcall_days'] : null,
            'lastcall_subject' => (string)($r['lastcall_subject'] ?? ''),
            'lastcall_body'    => (string)($r['lastcall_body'] ?? ''),
            'photo'            => (string)($r['photo_pattern'] ?? ''),
            'label'            => (string)($r['label'] ?? ''),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dates (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The occurrence of an entry that matters on $today: the one whose send window hasn't ended,
     * earliest first. Returns propose / send_from / send_to dates and the season year.
     * @return array{year: int, key: string, propose: string, send_from: string, send_to: string}
     */
    public static function occurrence(array $e, DateTimeImmutable $today): array
    {
        $t = $today->format('Y-m-d');
        $y0 = (int)$today->format('Y');
        foreach ([$y0 - 1, $y0, $y0 + 1] as $y) {
            $o = self::occurrenceFor($e, $y);
            if ($o['send_to'] >= $t) return $o;
        }
        return self::occurrenceFor($e, $y0 + 1);
    }

    /** The occurrence whose send window starts in $year. */
    public static function occurrenceFor(array $e, int $year): array
    {
        $from = (string)$e['send_from'];
        $to = (string)$e['send_to'];
        $prop = (string)$e['propose_on'];
        return [
            'year'      => $year,
            'key'       => $e['key'] . '_' . $year,
            'propose'   => self::date($prop > $from ? $year - 1 : $year, $prop),
            'send_from' => self::date($year, $from),
            'send_to'   => self::date($to < $from ? $year + 1 : $year, $to),
        ];
    }

    /** Due for a proposal today: on or after its propose date and before its send window ends. */
    public static function proposable(array $e, DateTimeImmutable $today): bool
    {
        $o = self::occurrence($e, $today);
        $t = $today->format('Y-m-d');
        return $o['propose'] <= $t && $t <= $o['send_to'];
    }

    /**
     * The next $n campaigns on the calendar (not yet past their send window), soonest send first.
     * @return array<int, array{entry: array, occ: array}>
     */
    public static function upcoming(array $calendar, DateTimeImmutable $today, int $n = 3): array
    {
        $out = [];
        foreach ($calendar as $e) $out[] = ['entry' => $e, 'occ' => self::occurrence($e, $today)];
        usort($out, fn($a, $b) => [$a['occ']['send_from'], $a['entry']['key']] <=> [$b['occ']['send_from'], $b['entry']['key']]);
        return array_slice($out, 0, $n);
    }

    /** "2026-09-15" → "Sep 15". */
    public static function short(string $ymd): string
    {
        $t = strtotime($ymd);
        return $t === false ? $ymd : date('M j', $t);
    }

    private static function date(int $year, string $md): string
    {
        // Feb 29 in a year without one falls back to Feb 28.
        if ($md === '02-29' && !checkdate(2, 29, $year)) $md = '02-28';
        return sprintf('%04d-%s', $year, $md);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conditions (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /** Does this campaign put new seed down (needs watering to take)? */
    public static function isLawnSeeding(array $e): bool
    {
        return !empty($e['conditions']['lawn_seeding']);
    }

    /**
     * Whether the entry may be proposed now, and why not.
     * @param array $ctx ['stage' => ?int (0 none, 1–4, null = in season but unknown), 'drought_year' => bool]
     * @return array{ok: bool, reason: ?string}
     */
    public static function conditionsHold(array $e, array $ctx): array
    {
        $c = $e['conditions'] ?? [];
        $max = $c['requires_restriction_stage_max'] ?? null;
        if (self::isLawnSeeding($e) && $max === null) $max = 1; // new seed needs a Stage 1 permit or no restrictions
        if (array_key_exists('stage', $ctx) && $ctx['stage'] === null) {
            // In season but never read: don't promise watering for new seed on a guess.
            return $max !== null ? ['ok' => false, 'reason' => "Held: the watering stage hasn't been checked yet"] : ['ok' => true, 'reason' => null];
        }
        $stage = (int)($ctx['stage'] ?? 0);
        if ($max !== null && $stage > (int)$max) {
            return ['ok' => false, 'reason' => "Held: Stage $stage watering restrictions — no new-seed permits, and lawns can't be watered"];
        }
        if (!empty($c['requires_drought_year']) && empty($ctx['drought_year'])) {
            return ['ok' => false, 'reason' => 'Only in a summer with Stage 2 or 3 restrictions'];
        }
        return ['ok' => true, 'reason' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sequences (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the follow-ups go, for a main send on $mainDate (Y-m-d).
     * @return array<int, string> step => date (1 reminder, 2 last call)
     */
    public static function sequenceDates(array $e, string $mainDate): array
    {
        $out = [];
        $base = new DateTimeImmutable($mainDate);
        if (!empty($e['reminder_days']) && trim((string)$e['reminder_body']) !== '') {
            $out[1] = $base->modify('+' . (int)$e['reminder_days'] . ' days')->format('Y-m-d');
        }
        if (!empty($e['lastcall_days']) && trim((string)$e['lastcall_body']) !== '') {
            $out[2] = $base->modify('+' . (int)$e['lastcall_days'] . ' days')->format('Y-m-d');
        }
        return $out;
    }

    /** "includes a reminder on Sep 29 and a last call Oct 8" (or '' for a single email). */
    public static function sequenceNote(array $dates): string
    {
        $parts = [];
        if (isset($dates[1])) $parts[] = 'a reminder on ' . self::short($dates[1]);
        if (isset($dates[2])) $parts[] = 'a last call ' . self::short($dates[2]);
        return $parts ? 'includes ' . implode(' and ', $parts) : '';
    }

    /** When the main email goes: the send window's start, or today if that has passed. */
    public static function mainDate(array $occ, DateTimeImmutable $today): string
    {
        $t = $today->format('Y-m-d');
        return $t > $occ['send_from'] ? $t : $occ['send_from'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Words (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /** Product-matching needles for each {price:…} token. */
    public const PRICE_NEEDLES = [
        'aeration' => ['aerat'],
        'overseed' => ['overseed', 'over-seed'],
        'hedge'    => ['hedge'],
        'cleanup'  => ['cleanup', 'clean-up', 'clean up'],
        'mulch'    => ['mulch'],
        'snow'     => ['snow'],
        'salt'     => ['salt', 'de-ic', 'deic'],
        'topdress' => ['top-dress', 'topdress', 'top dress'],
    ];

    /**
     * The lowest real price for each service among the CRM's products ("starts at").
     * Uses min_price when set, else base_price.
     * @param array $products rows with name, service_type, base_price, min_price
     * @return array<string, float>
     */
    public static function pricesFrom(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $hay = strtolower(($p['service_type'] ?? '') . ' ' . ($p['name'] ?? ''));
            $price = (float)($p['min_price'] ?? 0) > 0 ? (float)$p['min_price'] : (float)($p['base_price'] ?? 0);
            if ($price <= 0) continue;
            foreach (self::PRICE_NEEDLES as $svc => $needles) {
                foreach ($needles as $n) {
                    if (strpos($hay, $n) !== false) {
                        if (!isset($out[$svc]) || $price < $out[$svc]) $out[$svc] = $price;
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /** Fill {price:x}, {year} and {next_year}. Unknown prices stay as tokens (problems() flags them). */
    public static function fill(string $text, array $prices, int $year): string
    {
        $text = str_replace(['{year}', '{next_year}'], [(string)$year, (string)($year + 1)], $text);
        return (string)preg_replace_callback('/\{price:([a-z_]+)\}/', function ($m) use ($prices) {
            if (!isset($prices[$m[1]])) return $m[0];
            $p = (float)$prices[$m[1]];
            return '$' . (floor($p) == $p ? number_format($p, 0) : number_format($p, 2));
        }, $text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The seed (mirrors migration 1195's INSERTs)
    // ─────────────────────────────────────────────────────────────────────────

    public static function defaults(): array
    {
        $e = function (array $a): array {
            return $a + [
                'work_window' => '', 'services' => [], 'conditions' => [], 'why' => '',
                'reminder_days' => null, 'reminder_subject' => '', 'reminder_body' => '',
                'lastcall_days' => null, 'lastcall_subject' => '', 'lastcall_body' => '',
                'photo' => '', 'label' => '',
            ];
        };
        $sign = "\n\nThanks,\nTim";
        $list = [
            $e([
                'key' => 'spring_early_bird', 'name' => 'Spring lawn early-bird', 'audience' => 'homeowners',
                'propose_on' => '01-05', 'send_from' => '01-12', 'send_to' => '01-23', 'work_window' => 'April',
                'services' => ['aeration', 'overseed'], 'conditions' => ['lawn_seeding' => true],
                'why' => 'Spring is the busiest season and April is the window for aeration and overseeding. Homeowners who book in January get the April timeslots.',
                'subject' => 'Spring lawn work, booked ahead',
                'body' => "Hi {{first_name}},\n\nSpring is our busiest season, and the lawns that come back best are the ones aerated and overseeded in April, once the soil warms up and before the watering restrictions start on May 1.\n\nSo I take spring lawn work booked ahead. If you'd like yours on the list, reply to this email and I'll hold an April timeslot for you. I'll measure the lawn and send a fixed price before any work starts.\n\nAeration starts at {price:aeration} and overseeding at {price:overseed}. Both go by the size of the lawn." . $sign,
                'reminder_days' => 10, 'reminder_subject' => 'April timeslots',
                'reminder_body' => "Hi {{first_name}},\n\nA short follow-up to my note about spring lawn work. I hold April timeslots in the order people reply. If you'd like one for your lawn, reply \"spring\" and I'll put you down." . $sign,
                'photo' => '/aerat|overseed|lawn|turf|grass/i', 'label' => 'the spring early-bird email',
            ]),
            $e([
                'key' => 'spring_holds', 'name' => 'Spring holds — confirm dates', 'audience' => 'spring_holds',
                'propose_on' => '01-05', 'send_from' => '01-12', 'send_to' => '01-30', 'work_window' => 'April',
                'services' => ['aeration', 'overseed'],
                'why' => 'Everyone who replied "spring" last fall asked for a timeslot held. January is when to turn that into a date.',
                'subject' => 'Your spring timeslot',
                'body' => "Hi {{first_name}},\n\nIn the fall you asked me to hold a spring timeslot for your lawn. I haven't forgotten.\n\nAeration and overseeding go in best in April, once the soil warms up and before the watering restrictions start on May 1. Reply with the weeks that suit you and I'll confirm a date. I'll measure the lawn and send a fixed price before any work starts." . $sign,
                'reminder_days' => 10, 'reminder_subject' => 'Your spring timeslot',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my note about your spring timeslot. Reply with a week in April that suits you and I'll confirm it." . $sign,
                'label' => 'the spring timeslot email',
            ]),
            $e([
                'key' => 'hedge_before_nesting', 'name' => 'Hedges before nesting season', 'audience' => 'all_clients',
                'propose_on' => '02-01', 'send_from' => '02-08', 'send_to' => '02-20', 'work_window' => 'before March 15',
                'services' => ['hedge'],
                'why' => 'Nesting season starts March 15 (BC Wildlife Act s.34). A trim before then needs no nest check.',
                'subject' => 'Hedges before March 15',
                'body' => "Hi {{first_name}},\n\nFrom March 15 to August 15 birds nest in hedges, and BC's Wildlife Act protects active nests. A hard trim in that window needs a nest check first, and if there's a nest, the hedge waits.\n\nThe simple way round it is to trim before March 15. The hedge is dormant, the shape is easy to see, and it grows back clean in spring.\n\nIf you'd like yours done before then, reply to this email and I'll send a price. Hedge trimming starts at {price:hedge}." . $sign,
                'reminder_days' => 9, 'reminder_subject' => 'Hedges before March 15',
                'reminder_body' => "Hi {{first_name}},\n\nA short reminder from my last email: hedges need trimming before March 15, when nesting season starts. If you'd like yours done in time, reply and I'll fit it in." . $sign,
                'photo' => '/hedge|shrub/i', 'label' => 'the hedge email',
            ]),
            $e([
                'key' => 'spring_lawn_main', 'name' => 'Spring aeration and overseeding', 'audience' => 'homeowners',
                'propose_on' => '02-17', 'send_from' => '02-24', 'send_to' => '03-06', 'work_window' => 'April to early May',
                'services' => ['aeration', 'overseed', 'topdress'], 'conditions' => ['lawn_seeding' => true],
                'why' => 'April to early May is the best window for aeration, overseeding and top-dressing (soil 10–15 °C; March is too wet). Sent 3–4 weeks ahead.',
                'subject' => 'Spring aeration and overseeding',
                'body' => "Hi {{first_name}},\n\nApril is the best month of the year for a tired lawn. The soil warms enough for new seed to take, and there's still enough rain to keep it going. March is usually too wet, and from May 1 the watering restrictions make new seed hard to keep alive.\n\nWhat we do:\n- Aeration opens up compacted soil so rain and air reach the roots.\n- Overseeding fills the thin and bare patches.\n- Top-dressing goes on with the seed: a thin layer of compost that holds moisture.\n- Lime, if the soil needs it, goes on at least a month before the seed.\n\nAeration starts at {price:aeration} and overseeding at {price:overseed}. Both go by the size of your lawn, so I'll measure yours and send a fixed price before any work starts.\n\nIf you'd like an April timeslot, reply to this email and I'll get back to you within a day." . $sign,
                'reminder_days' => 12, 'reminder_subject' => 'April lawn timeslots',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my email about spring aeration and overseeding. April timeslots go in the order people reply. If you'd like one for your lawn, reply to this email." . $sign,
                'photo' => '/aerat|overseed|top.?dress|lawn|turf|grass/i', 'label' => 'the spring lawn email',
            ]),
            $e([
                'key' => 'spring_lawn_last_chance', 'name' => 'Spring lawns — seed by mid-April', 'audience' => 'homeowners',
                'propose_on' => '03-10', 'send_from' => '03-17', 'send_to' => '03-25', 'work_window' => 'early April',
                'services' => ['aeration', 'overseed'],
                'conditions' => ['lawn_seeding' => true, 'exclude_responders_of' => 'spring_lawn_main'],
                'why' => 'Metro Vancouver advises seeding by mid-April so it roots before May 1. Goes to homeowners who didn\'t answer the spring lawn email.',
                'subject' => 'Seed by mid-April',
                'body' => "Hi {{first_name}},\n\nOne last note on spring lawns. New seed needs about three weeks of steady moisture to take. Metro Vancouver's advice is to seed by mid-April, so it's rooted before the watering restrictions start on May 1.\n\nIf you'd like your lawn aerated and overseeded in time, reply to this email and I'll send a fixed price. Aeration starts at {price:aeration} and overseeding at {price:overseed}." . $sign,
                'label' => 'the seed-by-mid-April email',
            ]),
            $e([
                'key' => 'moss_lawn_care', 'name' => 'Moss and spring lawn care', 'audience' => 'homeowners',
                'propose_on' => '03-31', 'send_from' => '04-07', 'send_to' => '04-18', 'work_window' => 'April and May',
                'services' => ['aeration'], 'conditions' => ['stage_aware' => true],
                'why' => 'Moss follows a wet winter and shade. April is when it shows, and when the fix (drainage, lime, overseeding) works.',
                'subject' => 'Moss in the lawn',
                'body' => "Hi {{first_name}},\n\nAfter a wet winter, moss takes over the shady, compacted parts of a lawn. Raking it out only works for a season if nothing changes underneath.\n\nWhat changes it: aeration so the soil drains, lime where the soil is sour, and overseeding so grass fills the space the moss leaves. In the shadiest corners, a bed or a shade-tolerant seed mix often does better than lawn.\n\nAeration starts at {price:aeration}. Reply to this email and I'll look at your lawn and tell you what it actually needs." . $sign,
                'photo' => '/moss|lawn|turf|grass/i', 'label' => 'the moss email',
            ]),
            $e([
                'key' => 'beds_mulch', 'name' => 'Beds and mulch', 'audience' => 'homeowners',
                'propose_on' => '04-24', 'send_from' => '05-01', 'send_to' => '05-12', 'work_window' => 'May',
                'services' => ['mulch'],
                'why' => 'Mulch keeps beds cool and weed-free through the watering restrictions. Sold in whole yards, 2-yard minimum.',
                'subject' => 'Mulch for the beds',
                'body' => "Hi {{first_name}},\n\nA layer of mulch on the garden beds does three jobs through summer: it keeps the soil cool, holds in what moisture there is, and keeps the weeds down.\n\nWe weed and edge the beds first, then spread the mulch about two inches deep. Mulch is sold in whole yards, with a two-yard minimum, at {price:mulch} a yard. I'll tell you how many yards your beds take before any work starts.\n\nIf you'd like it done in May, reply to this email." . $sign,
                'reminder_days' => 10, 'reminder_subject' => 'Mulch for the beds',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my note about mulching the beds before summer. If you'd like a price for yours, reply to this email." . $sign,
                'photo' => '/mulch|bed|garden/i', 'label' => 'the mulch email',
            ]),
            $e([
                'key' => 'pm_snow_preseason', 'name' => 'Snow and salt — pre-season', 'audience' => 'property_managers,stratas',
                'propose_on' => '06-08', 'send_from' => '06-15', 'send_to' => '07-10', 'work_window' => 'November to March',
                'services' => ['snow', 'salt'],
                'why' => 'Strata and commercial winter contracts are settled by October 1. Early summer is when budgets for them are drawn up.',
                'subject' => 'Snow and salt for this winter',
                'body' => "Hi {{first_name}},\n\nIt's early to think about snow, but strata and commercial winter contracts are mostly settled by October 1, and the sites that get through a cold snap well are the ones with a plan in place before it.\n\nWhat we do: salting and snow clearing for walkways, entrances and parking areas, with a time-stamped photo after every visit, so you have a record if a slip claim ever comes in. We carry \$5M liability insurance and WorkSafeBC coverage.\n\nIf you'd like prices for any of your buildings, reply with the addresses and I'll send a number for each site." . $sign,
                'label' => 'the snow and salt email',
            ]),
            $e([
                'key' => 'fall_lawn_homeowners', 'name' => 'Fall aeration and overseeding — early word', 'audience' => 'homeowners',
                'propose_on' => '08-08', 'send_from' => '08-15', 'send_to' => '08-26', 'work_window' => 'mid-September to mid-October',
                'services' => ['aeration', 'overseed'],
                'conditions' => ['lawn_seeding' => true, 'requires_restriction_stage_max' => 1, 'skip_if_leaf_heavy' => true],
                'why' => 'Mid-September to mid-October is the fall window for aeration and overseeding. Held while Stage 2/3 restrictions are on; leaf-heavy lawns are left for spring.',
                'subject' => 'Fall aeration and overseeding',
                'body' => "Hi {{first_name}},\n\nThe second-best time of year for a lawn is coming up. From mid-September to mid-October the soil is still warm, the rain is coming back, and new seed has time to root before winter.\n\nWe aerate, overseed and top-dress in one visit. Aeration starts at {price:aeration} and overseeding at {price:overseed}, both by the size of the lawn. I'll measure yours and send a fixed price before any work starts.\n\nIf you'd like a timeslot in that window, reply to this email." . $sign,
                'reminder_days' => 10, 'reminder_subject' => 'Fall lawn timeslots',
                'reminder_body' => "Hi {{first_name}},\n\nA short follow-up to my email about fall aeration and overseeding. If you'd like your lawn done between mid-September and mid-October, reply to this email." . $sign,
                'photo' => '/aerat|overseed|lawn|turf|grass/i', 'label' => 'the fall lawn email',
            ]),
            $e([
                'key' => 'hedges_post_nesting', 'name' => 'Hedges after nesting season', 'audience' => 'all_clients',
                'propose_on' => '08-10', 'send_from' => '08-17', 'send_to' => '08-31', 'work_window' => 'late August to October',
                'services' => ['hedge'],
                'why' => 'Nesting season ends August 15, so hedges can be trimmed again without a nest check.',
                'subject' => 'Hedges after nesting season',
                'body' => "Hi {{first_name}},\n\nNesting season ends on August 15, so hedges can get a proper trim again without a nest check first. A trim in late summer holds its shape through winter.\n\nHedge trimming starts at {price:hedge}. If you'd like yours done, reply to this email and I'll send a price for your property." . $sign,
                'photo' => '/hedge|shrub/i', 'label' => 'the late-summer hedge email',
            ]),
            $e([
                'key' => 'pm_snow_signup', 'name' => 'Snow and salt — sign-up before October 1', 'audience' => 'property_managers,stratas',
                'propose_on' => '08-25', 'send_from' => '09-01', 'send_to' => '09-24', 'work_window' => 'November to March',
                'services' => ['snow', 'salt'],
                'why' => 'Strata and property managers sign winter contracts before October 1.',
                'subject' => 'Winter snow and salt, before October 1',
                'body' => "Hi {{first_name}},\n\nMost strata and commercial winter contracts are signed before October 1, so I'm putting this year's snow and salt routes together now.\n\nWhat you get: salting and snow clearing for walkways, entrances and parking areas, a time-stamped photo after every visit, \$5M liability insurance and WorkSafeBC coverage. If a slip claim ever comes in, you have the record.\n\nReply with the sites you'd like priced and I'll send a number for each, laid out so you can forward it to the council or owners." . $sign,
                'reminder_days' => 10, 'reminder_subject' => 'Snow and salt before October 1',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my email about snow and salt for this winter. If you'd like prices for your sites before October 1, reply with the addresses." . $sign,
                'label' => 'the snow sign-up email',
            ]),
            $e([
                'key' => 'fall_lawn_main', 'name' => 'October lawn renovation + bulbs', 'audience' => 'all_clients',
                'propose_on' => '09-08', 'send_from' => '09-15', 'send_to' => '09-24', 'work_window' => 'October',
                'services' => ['aeration', 'overseed', 'topdress'],
                'conditions' => ['lawn_seeding' => true, 'requires_restriction_stage_max' => 1],
                'why' => 'The main fall campaign: book October aeration, overseeding and top-dressing (and bulbs). Sent 3–4 weeks ahead, with a reminder and a last call.',
                'subject' => 'Your lawn this October',
                'body' => "Hi {{first_name}},\n\nOctober is when we bring lawns back after summer. The rain is back, the soil is still warm, and seed put down now is rooted before winter. A lawn that goes into winter thin usually comes out of it full of moss.\n\nThree things do most of the work:\n- Aeration opens up compacted soil so rain and air reach the roots.\n- Overseeding fills the thin and bare patches.\n- Top-dressing goes on with the seed: a thin layer of compost that holds moisture and feeds it.\n\nOne thing to know first: if your lawn sits under trees, falling leaves will smother new seed. For those lawns we aerate now and overseed in spring.\n\nAeration starts at {price:aeration} and overseeding at {price:overseed}. I'll measure your lawn and send a fixed price before any work starts. October is also the month to plant spring bulbs, if you'd like some in the beds.\n\nReply to this email and I'll get back to you within a day." . $sign,
                'reminder_days' => 14, 'reminder_subject' => 'Your October lawn timeslot',
                'reminder_body' => "Hi {{first_name}},\n\nA short follow-up to my email about aeration and overseeding. October timeslots go in the order people reply. If you'd like your lawn done before winter, reply to this email." . $sign,
                'lastcall_days' => 23, 'lastcall_subject' => 'Last call for fall overseeding',
                'lastcall_body' => "Hi {{first_name}},\n\nThis is the last week I can book fall overseeding. After mid-October the soil cools and new seed doesn't root in time. Aeration on its own still helps through November. Reply if you'd like either." . $sign,
                'photo' => '/aerat|overseed|top.?dress|lawn|turf|grass/i', 'label' => 'the October lawn email',
            ]),
            $e([
                'key' => 'post_drought', 'name' => 'Post-drought lawn recovery', 'audience' => 'all_clients',
                'propose_on' => '10-01', 'send_from' => '10-01', 'send_to' => '10-31', 'work_window' => 'second half of October',
                'services' => ['aeration', 'overseed'], 'conditions' => ['requires_drought_year' => true],
                'why' => 'The lawn watering restrictions lift on October 15. With the fall rain coming, the second half of October is the window for aeration, overseeding and top-dressing.',
                'subject' => self::LEGACY_SUBJECT,
                'body' => self::LEGACY_BODY,
                'photo' => '/aerat|overseed|top.?dress|lawn|turf|grass/i', 'label' => 'the fall lawn email',
            ]),
            $e([
                'key' => 'fall_cleanup', 'name' => 'Leaves, fall cleanup and blow-outs', 'audience' => 'all_clients',
                'propose_on' => '10-01', 'send_from' => '10-08', 'send_to' => '10-20', 'work_window' => 'late October to December',
                'services' => ['cleanup'],
                'why' => 'Leaves fall October to December (city collection runs then; leaves blown into the street can be fined). Irrigation blow-outs late October to early November.',
                'subject' => 'Leaves, fall cleanup and sprinkler blow-outs',
                'body' => "Hi {{first_name}},\n\nThe leaves start coming down properly this month and keep coming until December. Left on the lawn, they smother the grass and it comes out of winter thin and mossy. Blowing them into the street isn't the answer either: the city collects leaves from October to December and can fine for leaves left in the road.\n\nWhat we do: clear the leaves from the lawn and beds, cut back what's finished for the year, and take everything away. Fall cleanup starts at {price:cleanup}.\n\nIf you have an irrigation system, late October to early November is the time to blow it out before the first frost. We can do both on the same visit.\n\nReply to this email and I'll send a price for your property." . $sign,
                'reminder_days' => 12, 'reminder_subject' => 'Fall cleanup',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my email about leaves and fall cleanup. If you'd like your property cleared before winter, reply and I'll send a price." . $sign,
                'photo' => '/clean|leaf|leaves|fall/i', 'label' => 'the fall cleanup email',
            ]),
            $e([
                'key' => 'pm_next_year', 'name' => 'Next year\'s contracts (strata budgets)', 'audience' => 'property_managers,stratas',
                'propose_on' => '11-03', 'send_from' => '11-10', 'send_to' => '11-28', 'work_window' => 'next season',
                'services' => [],
                'why' => 'Strata budgets are approved at the AGM within two months of fiscal year-end; numbers need to reach the PM 2–3 months before it.',
                'subject' => 'Next year\'s grounds maintenance numbers',
                'body' => "Hi {{first_name}},\n\nMost strata councils approve next year's budget at the AGM, within two months of their fiscal year-end. If any of your buildings has a year-end coming up, I can send next season's grounds maintenance numbers now, so they go into the budget rather than arriving after it.\n\nEach site gets one number for the year, the visits it covers, and a photo report after every visit. Laid out so you can forward it to the council as it is.\n\nReply with the buildings and their year-ends and I'll send the numbers." . $sign,
                'reminder_days' => 14, 'reminder_subject' => 'Numbers for next year\'s budget',
                'reminder_body' => "Hi {{first_name}},\n\nFollowing up on my email about next season's numbers. If a council's year-end is coming up, reply with the building and I'll get them to you in time for the budget." . $sign,
                'label' => 'the next-year contracts email',
            ]),
        ];
        $out = [];
        foreach ($list as $i => $x) {
            $x['sort'] = ($i + 1) * 10;
            $out[$x['key']] = $x;
        }
        return $out;
    }
}
