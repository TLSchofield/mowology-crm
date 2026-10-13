<?php
/**
 * MediaTagRules — how Mia tags a photo in the media library. Pure: no DB, no HTTP.
 *
 * Every tag is namespaced and lives in media_assets.tags_json (a JSON array of strings):
 *   service/<slug>        from the visit's plan line items, else the plan's service_type
 *   season/<spring|summer|fall|winter>   from the date taken (EXIF captured_at, else created_at)
 *   stage/<before|after|during>  and pair/<visit id>  (a before and an after of one visit)
 *   area/<neighbourhood>  coarse, from the postal code prefix (or lat/lng); NEVER the address
 *   type/<home|strata|commercial>
 *   subject/<lawn|beds|hedge|leaves|tree|hardscape|snow>, quality/<1-5>,
 *   privacy/<ok|blur-needed>            from the AI vision pass (MediaTagService::visionPass)
 *   consent/<ok|no>       "use them all" (Tim, 2026-10-06): ok unless the client opted out
 *                         (contacts.photo_optout)
 *   use/hero              Tim's star (media_assets.is_favorite)
 *   used/<yyyy-mm>-<channel>   each time Mia uses it
 * Namespaces Mia owns are rewritten on every tagging; any other tag (Tim's own) is kept.
 */
class MediaTagRules
{
    /** Namespaces derived from context — replaced wholesale when a photo is re-tagged. */
    public const CONTEXT_NS = ['service', 'season', 'stage', 'pair', 'area', 'type', 'consent', 'use'];
    /** Namespaces from the vision pass — replaced only when a new pass runs. */
    public const VISION_NS = ['subject', 'quality', 'privacy'];
    public const SUBJECTS = ['lawn', 'beds', 'hedge', 'leaves', 'tree', 'hardscape', 'snow'];
    public const CHANNELS = ['email', 'instagram', 'facebook', 'gbp', 'web'];

    /** Coarse areas by postal-code prefix (FSA). Vancouver, Burnaby, Richmond; else the city. */
    private const FSA = [
        'V5K' => 'hastings-sunrise', 'V5L' => 'grandview', 'V5M' => 'renfrew', 'V5N' => 'grandview', 'V5P' => 'killarney',
        'V5R' => 'renfrew', 'V5S' => 'killarney', 'V5T' => 'mount-pleasant', 'V5V' => 'riley-park', 'V5W' => 'sunset',
        'V5X' => 'marpole', 'V5Y' => 'mount-pleasant', 'V5Z' => 'fairview',
        'V6A' => 'strathcona', 'V6B' => 'downtown', 'V6C' => 'downtown', 'V6E' => 'west-end', 'V6G' => 'west-end',
        'V6H' => 'fairview', 'V6J' => 'kitsilano', 'V6K' => 'kitsilano', 'V6L' => 'dunbar', 'V6M' => 'kerrisdale',
        'V6N' => 'southlands', 'V6P' => 'marpole', 'V6R' => 'point-grey', 'V6S' => 'dunbar', 'V6T' => 'ubc', 'V6Z' => 'downtown',
        'V5A' => 'burnaby', 'V5B' => 'burnaby', 'V5C' => 'burnaby', 'V5E' => 'burnaby', 'V5G' => 'burnaby',
        'V5H' => 'burnaby', 'V5J' => 'burnaby', 'V3N' => 'burnaby',
        'V6V' => 'richmond', 'V6W' => 'richmond', 'V6X' => 'richmond', 'V6Y' => 'richmond', 'V7A' => 'richmond',
        'V7B' => 'richmond', 'V7C' => 'richmond', 'V7E' => 'richmond',
    ];

    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    /** Northern-hemisphere meteorological seasons. */
    public static function season(?string $date): ?string
    {
        $ts = $date ? strtotime($date) : false;
        if (!$ts) return null;
        $m = (int)date('n', $ts);
        return [12 => 'winter', 1 => 'winter', 2 => 'winter', 3 => 'spring', 4 => 'spring', 5 => 'spring',
                6 => 'summer', 7 => 'summer', 8 => 'summer', 9 => 'fall', 10 => 'fall', 11 => 'fall'][$m];
    }

    /** Postal code first; a coarse lat/lng box second; else the city; never the street. */
    public static function area(?string $postal, ?float $lat = null, ?float $lng = null, ?string $city = null): ?string
    {
        $fsa = strtoupper(substr(preg_replace('/\s+/', '', (string)$postal), 0, 3));
        if (isset(self::FSA[$fsa])) return self::FSA[$fsa];
        if ($lat !== null && $lng !== null && $lat != 0.0) {
            if ($lat < 49.20 && $lng < -123.00) return 'richmond';
            if ($lng > -123.023) return 'burnaby';
            if ($lng < -123.14) return $lat > 49.245 ? 'west-side-north' : 'west-side-south';
            return $lat > 49.245 ? 'east-side-north' : 'east-side-south';
        }
        $c = self::slug((string)$city);
        return $c !== '' ? $c : null;
    }

    /** properties.property_type → home | strata | commercial */
    public static function propertyType(?string $t): ?string
    {
        $t = strtolower((string)$t);
        if ($t === '') return null;
        if (in_array($t, ['strata', 'condo', 'townhouse', 'multi_unit'], true)) return 'strata';
        if ($t === 'commercial') return 'commercial';
        return 'home';
    }

    /**
     * Context tags for one photo.
     * @param array $c [service_types: string[], date, photo_type, visit_id, has_pair: bool,
     *                  postal_code, lat, lng, city, property_type, optout: bool, favorite: bool]
     * @return string[]
     */
    public static function contextTags(array $c): array
    {
        $t = [];
        foreach (array_unique(array_filter(array_map([self::class, 'slug'], (array)($c['service_types'] ?? [])))) as $s) {
            $t[] = 'service/' . $s;
        }
        if ($s = self::season($c['date'] ?? null)) $t[] = 'season/' . $s;
        $stage = strtolower((string)($c['photo_type'] ?? ''));
        if (in_array($stage, ['before', 'after', 'during'], true)) $t[] = 'stage/' . $stage;
        if (!empty($c['has_pair']) && !empty($c['visit_id']) && in_array($stage, ['before', 'after'], true)) $t[] = 'pair/' . (int)$c['visit_id'];
        $area = self::area($c['postal_code'] ?? null, isset($c['lat']) ? (float)$c['lat'] : null, isset($c['lng']) ? (float)$c['lng'] : null, $c['city'] ?? null);
        if ($area) $t[] = 'area/' . $area;
        if ($pt = self::propertyType($c['property_type'] ?? null)) $t[] = 'type/' . $pt;
        $t[] = 'consent/' . (!empty($c['optout']) ? 'no' : 'ok');
        if (!empty($c['favorite'])) $t[] = 'use/hero';
        return $t;
    }

    /**
     * The vision answer → tags. Anything the model flagged (faces, house numbers, plates, children,
     * an identifiable street front) makes the photo blur-needed.
     * @param array{subjects?: string[], quality?: int, faces?: bool, house_numbers?: bool, licence_plates?: bool,
     *              children?: bool, identifiable_street_front?: bool} $v
     */
    public static function visionTags(array $v): array
    {
        $t = [];
        foreach ((array)($v['subjects'] ?? []) as $s) {
            $s = strtolower((string)$s);
            if (in_array($s, self::SUBJECTS, true)) $t[] = 'subject/' . $s;
        }
        $q = (int)($v['quality'] ?? 0);
        if ($q >= 1 && $q <= 5) $t[] = 'quality/' . $q;
        $flag = !empty($v['faces']) || !empty($v['house_numbers']) || !empty($v['licence_plates'])
             || !empty($v['children']) || !empty($v['identifiable_street_front']);
        $t[] = 'privacy/' . ($flag ? 'blur-needed' : 'ok');
        return array_values(array_unique($t));
    }

    /** Replace the given namespaces in $existing with $new; keep everything else (Tim's own tags, used/…). */
    public static function merge(array $existing, array $new, array $namespaces): array
    {
        $keep = array_filter($existing, function ($tag) use ($namespaces) {
            $ns = strstr((string)$tag, '/', true);
            return $ns === false || !in_array($ns, $namespaces, true);
        });
        $out = array_values(array_unique(array_merge(array_values($keep), $new)));
        sort($out);
        return $out;
    }

    public static function decode(?string $json): array
    {
        $a = json_decode((string)$json, true);
        return is_array($a) ? array_values(array_filter($a, 'is_string')) : [];
    }

    public static function usedTag(DateTimeImmutable $day, string $channel): string
    {
        return 'used/' . $day->format('Y-m') . '-' . self::slug($channel);
    }

    /** First value in a namespace ('quality' → '4'), or null. */
    public static function value(array $tags, string $ns): ?string
    {
        foreach ($tags as $t) {
            if (strpos($t, $ns . '/') === 0) return substr($t, strlen($ns) + 1);
        }
        return null;
    }

    public static function values(array $tags, string $ns): array
    {
        $out = [];
        foreach ($tags as $t) {
            if (strpos($t, $ns . '/') === 0) $out[] = substr($t, strlen($ns) + 1);
        }
        return $out;
    }

    /** May this photo go on a public channel? (privacy ok AND consent ok; unknown privacy = not yet) */
    public static function publicOk(array $tags): bool
    {
        return self::value($tags, 'privacy') === 'ok' && self::value($tags, 'consent') !== 'no';
    }

    /**
     * May a visit's before/after go into a public post? Needs a before and an after that are each
     * publicOk, and nothing in the set flagged blur-needed or opted out.
     * @param array<int, string[]> $photoTags tags of each before/after photo of the visit
     */
    public static function visitPublicOk(array $photoTags): bool
    {
        $before = $after = false;
        foreach ($photoTags as $t) {
            if (self::value($t, 'privacy') === 'blur-needed' || self::value($t, 'consent') === 'no') return false;
            $stage = self::value($t, 'stage');
            if (self::publicOk($t)) {
                if ($stage === 'before') $before = true;
                if ($stage === 'after') $after = true;
            }
        }
        return $before && $after;
    }

    /** The learning key for a photo: service + subject + stage. */
    public static function combo(array $tags): string
    {
        return implode('|', [
            self::value($tags, 'service') ?? '-',
            self::value($tags, 'subject') ?? '-',
            self::value($tags, 'stage') ?? '-',
        ]);
    }
}
