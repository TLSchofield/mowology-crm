<?php
/**
 * MiaListingJobs — the Listings page's decisions as cards for Mia's job strip (the "listing" kinds).
 *
 * One decision at a time: reconnect a login that stopped working, say whether Google has approved API access, claim an
 * unclaimed listing, fix a listing that differs from the master record, check one nobody has compared yet, re-check one
 * not looked at in 90 days. The page keeps the master record and the list; this only shapes the questions.
 *
 * What is read and what is asked (nothing is invented):
 *  - Google Business Profile, when its API is live: Mia reads name, address, phone, website, hours and categories from
 *    Google (cached in ops_settings 'listings_gbp_read', refreshed after the page is sent) and raises a card only for what differs.
 *  - Every other site has no API we hold: the owner is shown the master record and the profile link and answers.
 *    A "matches" is only ever recorded from Google's own answer or from the owner's click.
 * Buttons send the request the page's own forms send (POST /crm/marketing/listings.php, form=listing | gbp_approved), so
 * there is no new write path. The strip's JSON reply is the same handler's flash text.
 *
 * Her record per site (the four-state triangle): marketing_listing_checks, written by ListingsService::save when the
 * strip sends job_result. Same ladder as Penny and the posts: wrong once = held, 2-4 confirmations = known, 5 = mastered
 * (MiaJobAdapter::sure). A confirmation is the owner's, not Google's: gold means owner and Mia have agreed five times.
 *
 * Off unless ops_settings mia_listing_job_enabled = '1'. Any failure means no cards. No namespace / no autoloader.
 */
require_once __DIR__ . '/ListingsService.php';
require_once __DIR__ . '/MiaJobAdapter.php';
require_once dirname(__DIR__, 2) . '/Social/Services/SocialConnectionState.php';

class MiaListingJobs
{
    public const PAGE = '/crm/marketing/listings.php';
    public const MAX_CARDS = 8;
    public const WINDOW = 10;                 // checks counted toward her record
    public const GOOGLE_CACHE_HOURS = 24;
    public const SETTINGS_URL = '/crm/settings.php';

    private const COMPARE = ['name' => 'Name', 'address' => 'Address', 'phone' => 'Phone', 'website' => 'Website'];

    public static function enabled(PDO $db): bool
    {
        return self::setting($db, 'mia_listing_job_enabled') === '1';
    }

    /** @return array<int, array> cards in order; [] when off, nothing waits, or anything goes wrong */
    public static function jobs(PDO $db, bool $canEdit, ?DateTimeImmutable $now = null): array
    {
        try {
            if (!$canEdit || !self::enabled($db)) return [];
            require_once __DIR__ . '/ListingsService.php';
            require_once dirname(__DIR__, 2) . '/Social/Services/SocialConnectionState.php';
            $now = $now ?? new DateTimeImmutable('now');
            $svc = new ListingsService($db);
            if (!$svc->ready()) return [];
            $state = SocialConnectionState::fromAccounts(SocialConnectionState::load($db));
            return self::queue($svc->master(), $svc->all(), self::googleRead($db), self::records($db), $state,
                self::setting($db, 'gbp_api_approved') === '1', self::gbpConnected($db), $now);
        } catch (Throwable $e) {
            error_log('MiaListingJobs::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array      $master   ListingsService::master()
     * @param array      $rows     ListingsService::all() (marketing_listings rows + nap + stale)
     * @param array|null $google   googleRead(): what Google shows, with 'at'; null when not read
     * @param array      $records  listing id => ['called' => n, 'agreed' => n]
     * @param array      $state    SocialConnectionState::fromAccounts()
     */
    public static function queue(array $master, array $rows, ?array $google, array $records, array $state, bool $gbpApproved, bool $gbpConnected, DateTimeImmutable $now): array
    {
        $jobs = [];
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $p => $label) {
            if (($state[$p]['status'] ?? '') === 'reconnect') {
                $jobs[] = self::reconnectJob($label, (string)($state[$p]['reason'] ?? ''), SocialConnectionState::RECONNECT_URL, 'reconnect:' . $p);
                break;   // one login covers both: one card
            }
        }
        if (($state['gbp']['status'] ?? '') === 'reconnect') {
            $jobs[] = self::reconnectJob('Google Business Profile', (string)($state['gbp']['reason'] ?? ''), SocialConnectionState::GBP_CONNECT_URL, 'reconnect:gbp');
        }
        if ($gbpConnected && !$gbpApproved) {
            $jobs[] = self::approvalJob();
        }

        $claim = $fix = $compare = $recheck = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $rec = (array)($records[$id] ?? []);
            $sure = MiaJobAdapter::sure([(int)($rec['called'] ?? 0), (int)($rec['agreed'] ?? 0)]);
            $site = (string)$r['site_name'];
            $isGoogle = ((string)($r['site_key'] ?? '')) === 'gbp';
            $status = (string)$r['status'];

            if ($isGoogle && $google && self::googleFresh($google, $r, $now)) {
                $rows2 = self::compareRows($master, $google, true);
                if (self::anyDiffers($rows2)) {
                    $fix[] = self::fixJob($r, $rows2, $rec, $sure, 'Google shows', true, $master);
                }
                continue;   // matches: nothing to ask; the page shows it from the same read
            }
            if (in_array($status, ['unknown', 'not_claimed'], true) && empty($r['profile_url']) && !empty($r['claim_url'])) {
                if ((int)$r['is_optional'] === 1) continue;
                $claim[] = self::claimJob($r, $master, $sure);
                continue;
            }
            if (empty($r['profile_url']) && !in_array($status, ['claimed', 'verified'], true)) continue;
            $nap = (string)($r['nap']['status'] ?? 'unchecked');
            if ($nap === 'mismatch') {
                $rows2 = self::compareRows($master, self::listed($r), false);
                $fix[] = self::fixJob($r, $rows2, $rec, $sure, 'The listing shows', false, $master);
            } elseif ($nap === 'unchecked') {
                $compare[] = self::compareJob($r, $master, $sure, $rec);
            } elseif (!empty($r['stale'])) {
                $recheck[] = self::recheckJob($r, $master, $sure, $rec);
            }
        }
        $jobs = array_merge($jobs, array_slice($claim, 0, 3), array_slice($fix, 0, 3), array_slice($compare, 0, 3), array_slice($recheck, 0, 3));
        return array_slice($jobs, 0, self::MAX_CARDS);
    }

    // ── what each side says (pure) ────────────────────────────────────────

    /**
     * One row per field for the discs: k label, site (what the site shows), master, state ok | diff | blank.
     * Hours and categories only when $withExtras (Google tells us; the other sites' pages do not hold them).
     */
    public static function compareRows(array $master, array $site, bool $withExtras): array
    {
        $norm = [
            'name' => fn($s) => ListingsService::normName($s), 'address' => fn($s) => ListingsService::normAddress($s),
            'phone' => fn($s) => ListingsService::normPhone($s), 'website' => fn($s) => ListingsService::normWebsite($s),
            'hours' => fn($s) => strtolower(preg_replace('/\s+/', ' ', trim($s))),
            'categories' => fn($s) => self::normCategories($s),
        ];
        $keys = self::COMPARE + ($withExtras ? ['hours' => 'Hours', 'categories' => 'Categories'] : []);
        $out = [];
        foreach ($keys as $k => $label) {
            $mine = trim((string)($master[$k] ?? ''));
            $theirs = trim((string)($site[$k] ?? ''));
            $state = $theirs === '' ? 'blank' : ($norm[$k]($theirs) === $norm[$k]($mine) ? 'ok' : 'diff');
            $out[] = ['k' => $label, 'site' => $theirs, 'master' => $mine, 'state' => $state];
        }
        return $out;
    }

    public static function anyDiffers(array $rows): bool
    {
        foreach ($rows as $r) if ($r['state'] === 'diff') return true;
        return false;
    }

    /** "Landscaper; Lawn care service" vs "lawn care service, landscaper": same set, any order. */
    public static function normCategories(string $s): string
    {
        $parts = preg_split('/[;,]+/', strtolower($s)) ?: [];
        $parts = array_values(array_filter(array_map(fn($p) => trim(preg_replace('/\s+/', ' ', $p)), $parts), fn($p) => $p !== ''));
        sort($parts);
        return implode('|', $parts);
    }

    /** Google's Business Information location → the same plain fields as the master record. */
    public static function shapeGoogle(array $info): array
    {
        $a = (array)($info['storefrontAddress'] ?? []);
        $line = implode(' ', array_map('strval', (array)($a['addressLines'] ?? [])));
        $addr = trim(implode(', ', array_filter([$line, (string)($a['locality'] ?? ''), (string)($a['administrativeArea'] ?? ''), (string)($a['postalCode'] ?? '')], fn($x) => trim((string)$x) !== '')));
        $cats = [];
        if (!empty($info['categories']['primaryCategory']['displayName'])) $cats[] = (string)$info['categories']['primaryCategory']['displayName'];
        foreach ((array)($info['categories']['additionalCategories'] ?? []) as $c) {
            if (!empty($c['displayName'])) $cats[] = (string)$c['displayName'];
        }
        return [
            'name' => trim((string)($info['title'] ?? '')),
            'address' => $addr,
            'phone' => trim((string)($info['phoneNumbers']['primaryPhone'] ?? '')),
            'website' => trim((string)($info['websiteUri'] ?? '')),
            'hours' => self::googleHours((array)($info['regularHours']['periods'] ?? [])),
            'categories' => implode('; ', $cats),
        ];
    }

    /** Google's periods ({openDay, openTime{hours,minutes}, closeTime}) → "Mon–Fri 08:00–17:00", the master's own shape. */
    public static function googleHours(array $periods): string
    {
        $days = ['MONDAY' => 'monday', 'TUESDAY' => 'tuesday', 'WEDNESDAY' => 'wednesday', 'THURSDAY' => 'thursday', 'FRIDAY' => 'friday', 'SATURDAY' => 'saturday', 'SUNDAY' => 'sunday'];
        $hours = [];
        foreach ($periods as $p) {
            $d = $days[strtoupper((string)($p['openDay'] ?? ''))] ?? null;
            if (!$d) continue;
            $fmt = fn($t) => sprintf('%02d:%02d', (int)($t['hours'] ?? 0), (int)($t['minutes'] ?? 0));
            $hours[$d] = ['open' => true, 'start' => $fmt((array)($p['openTime'] ?? [])), 'end' => $fmt((array)($p['closeTime'] ?? []))];
        }
        return $hours ? ListingsService::hoursText(json_encode(['hours' => $hours])) : '';
    }

    /** The listing row's own typed values, as the plain fields. */
    public static function listed(array $r): array
    {
        return ['name' => (string)($r['listed_name'] ?? ''), 'address' => (string)($r['listed_address'] ?? ''),
                'phone' => (string)($r['listed_phone'] ?? ''), 'website' => (string)($r['listed_website'] ?? '')];
    }

    /** A Google read counts until the owner has checked the listing after it, or when it is too old. */
    public static function googleFresh(array $google, array $row, DateTimeImmutable $now): bool
    {
        $at = strtotime((string)($google['at'] ?? ''));
        if (!$at || $at < $now->getTimestamp() - 3 * 86400) return false;
        $checked = !empty($row['last_checked']) ? strtotime((string)$row['last_checked'] . ' 23:59:59') : 0;
        return $checked < $at;
    }

    // ── cards ─────────────────────────────────────────────────────────────

    private static function base(string $kind, string $key, string $title, string $why, string $sure, string $confidence = ''): array
    {
        return ['head' => 'mia', 'kind' => $kind, 'key' => $key, 'title' => $title, 'why' => $why, 'sure' => $sure, 'confidence' => $confidence,
                'post' => null, 'detail' => '',
                'anchor' => ['label' => 'Settings', 'href' => self::SETTINGS_URL]];
    }

    private static function reconnectJob(string $label, string $reason, string $url, string $key): array
    {
        return self::base('reconnect', $key, $label . ' needs you to sign in again',
            ($reason !== '' ? ucfirst($reason) : 'The saved login was refused.') . ' Nothing reaches it until you reconnect.', 'forming')
            + ['primary' => ['label' => 'Reconnect', 'sub' => 'sign in again', 'href' => $url], 'secondary' => ['label' => 'Later', 'sub' => 'for now', 'skip' => true]];
    }

    private static function approvalJob(): array
    {
        return self::base('listing', 'gbp-approval', 'Has Google approved API access?',
            'Replies and posts stay drafts until it has. The quota in Cloud Console shows 300 once it is approved, not 0.', 'forming')
            + ['primary' => ['label' => 'Yes, approved', 'sub' => 'turn Google on', 'request' => ['url' => self::PAGE, 'body' => ['form' => 'gbp_approved', 'approved' => '1']]],
               'secondary' => ['label' => 'Not yet', 'sub' => 'ask me later', 'skip' => true]];
    }

    /** The row's own values as the page's Update form sends them; any field can be overridden by a card's inputs. */
    private static function saveBody(array $r, array $set = []): array
    {
        return array_merge([
            'form' => 'listing', 'id' => (int)$r['id'], 'status' => (string)$r['status'], 'profile_url' => (string)($r['profile_url'] ?? ''),
            'review_count' => $r['review_count'] === null ? '' : (string)(int)$r['review_count'], 'rating' => $r['rating'] === null ? '' : (string)$r['rating'],
            'listed_name' => (string)($r['listed_name'] ?? ''), 'listed_address' => (string)($r['listed_address'] ?? ''),
            'listed_phone' => (string)($r['listed_phone'] ?? ''), 'listed_website' => (string)($r['listed_website'] ?? ''),
            'notes' => (string)($r['notes'] ?? ''),
        ], $set);
    }

    private static function saveReq(array $r, array $set, string $result): array
    {
        return ['url' => self::PAGE, 'body' => self::saveBody($r, $set + ['job_result' => $result])];
    }

    private static function masterBody(array $master): array
    {
        return ['listed_name' => $master['name'], 'listed_address' => $master['address'], 'listed_phone' => $master['phone'], 'listed_website' => $master['website']];
    }

    private static function conf(array $rec): string
    {
        $called = (int)($rec['called'] ?? 0);
        return $called > 0 ? 'Confirmed ' . (int)($rec['agreed'] ?? 0) . ' of ' . $called . ' checks of this listing.' : '';
    }

    private static function claimJob(array $r, array $master, string $sure): array
    {
        $site = (string)$r['site_name'];
        $done = self::base('listing', 'claimed:' . (int)$r['id'], 'Did you claim ' . $site . '?', 'Mia marks it claimed only when you say so.', $sure)
            + ['primary' => ['label' => 'Claimed', 'sub' => 'mark it claimed', 'request' => self::saveReq($r, ['status' => 'claimed'], 'claimed')],
               'secondary' => ['label' => 'Not yet', 'sub' => 'ask me later', 'skip' => true]];
        return self::base('listing', 'claim:' . (int)$r['id'], 'Claim ' . $site, '', $sure)
            + ['compare' => self::masterOnly($master, 'The master record says'),
               'primary' => ['label' => 'Open the claim page', 'sub' => $site, 'open' => (string)$r['claim_url'], 'alt' => $done],
               'secondary' => ['label' => 'Later', 'sub' => 'for now', 'skip' => true]];
    }

    private static function compareJob(array $r, array $master, string $sure, array $rec = []): array
    {
        $site = (string)$r['site_name'];
        $enter = self::enterCard($r, $master, 'What does ' . $site . ' show?', 'Change only what is different, then save.', $sure, self::masterBody($master), 'changed', false);
        $c = self::base('listing', 'compare:' . (int)$r['id'], 'Does ' . $site . ' match?', 'Open it and look. Nobody has compared this listing with the master record yet.', $sure, self::conf($rec))
            + ['compare' => self::masterOnly($master, 'The master record says'),
               'primary' => ['label' => 'Matches', 'sub' => 'all four are the same', 'request' => self::saveReq($r, self::masterBody($master), 'confirmed')],
               'secondary' => ['label' => 'It differs', 'sub' => 'type what it shows', 'alt' => $enter]];
        if (!empty($r['profile_url'])) $c['link'] = ['label' => 'Open ' . $site, 'href' => (string)$r['profile_url']];
        return $c;
    }

    private static function recheckJob(array $r, array $master, string $sure, array $rec = []): array
    {
        $site = (string)$r['site_name'];
        $enter = self::enterCard($r, $master, 'What does ' . $site . ' show now?', 'Change only what is different, then save.', $sure, self::listed2($r), 'changed', true);
        $c = self::base('listing', 'recheck:' . (int)$r['id'], 'Still the same on ' . $site . '?', 'Last checked ' . date('M j', strtotime((string)$r['last_checked'])) . '. Listings are re-checked every ' . ListingsService::STALE_DAYS . ' days.', $sure, self::conf($rec))
            + ['compare' => self::compareRows($master, self::listed($r), false),
               'primary' => ['label' => 'Still the same', 'sub' => 'checked today', 'request' => self::saveReq($r, [], 'confirmed')],
               'secondary' => ['label' => 'It changed', 'sub' => 'type what it shows', 'alt' => $enter]];
        if (!empty($r['profile_url'])) $c['link'] = ['label' => 'Open ' . $site, 'href' => (string)$r['profile_url']];
        return $c;
    }

    private static function listed2(array $r): array
    {
        return ['listed_name' => (string)($r['listed_name'] ?? ''), 'listed_address' => (string)($r['listed_address'] ?? ''),
                'listed_phone' => (string)($r['listed_phone'] ?? ''), 'listed_website' => (string)($r['listed_website'] ?? '')];
    }

    private static function enterCard(array $r, array $master, string $title, string $why, string $sure, array $prefill, string $result, bool $showMaster): array
    {
        return self::base('listing_enter', 'enter:' . (int)$r['id'], $title, $why, $sure)
            + ($showMaster ? ['compare' => self::masterOnly($master, 'The master record says')] : [])
            + ['fields' => ['listed_name', 'listed_address', 'listed_phone', 'listed_website'], 'right' => $prefill,
               'primary' => ['label' => 'Save', 'sub' => 'checked today', 'request' => self::saveReq($r, [], $result)],
               'secondary' => ['label' => 'Later', 'sub' => 'for now', 'skip' => true]];
    }

    private static function fixJob(array $r, array $rows, array $rec, string $sure, string $siteLabel, bool $google, array $master): array
    {
        $site = (string)$r['site_name'];
        $diff = array_values(array_filter($rows, fn($x) => $x['state'] === 'diff'));
        $names = implode(', ', array_map(fn($x) => strtolower($x['k']), $diff));
        $fixed = self::base('listing', 'fixed:' . (int)$r['id'], 'Did you fix ' . $site . '?', 'Mia checks it again after you answer.', $sure)
            + ['primary' => ['label' => 'Fixed', 'sub' => 'checked today', 'request' => self::saveReq($r, self::masterBody($master), 'fixed')],
               'secondary' => ['label' => 'Not yet', 'sub' => 'ask me later', 'skip' => true]];
        return self::base('listing', 'fix:' . (int)$r['id'], $site . ' shows a different ' . $names, '', $sure, self::conf($rec))
            + ['compare' => $rows,
               'primary' => ['label' => 'Fix it on ' . $site, 'sub' => 'open the listing', 'open' => (string)($r['claim_url'] ?: $r['profile_url']), 'alt' => $fixed],
               'secondary' => ['label' => 'The master is wrong', 'sub' => 'open Settings', 'href' => self::SETTINGS_URL]];
    }

    private static function masterOnly(array $master, string $head): array
    {
        $out = [];
        foreach (self::COMPARE as $k => $label) $out[] = ['k' => $label, 'site' => '', 'master' => (string)$master[$k], 'state' => 'master'];
        return $out;
    }

    // ── her record and what Google shows ──────────────────────────────────

    /** @return array<int, array{called: int, agreed: int}> by listing id, newest WINDOW checks each */
    public static function records(PDO $db): array
    {
        $out = [];
        try {
            $q = $db->query("SELECT listing_id, result FROM marketing_listing_checks ORDER BY id DESC");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['listing_id'];
                $out[$id] = $out[$id] ?? ['called' => 0, 'agreed' => 0];
                if ($out[$id]['called'] >= self::WINDOW) continue;
                if ($row['result'] === 'claimed' || $row['result'] === 'fixed') continue;   // claiming and fixing are the owner's work, not a call of hers
                $out[$id]['called']++;
                if ($row['result'] === 'confirmed') $out[$id]['agreed']++;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** What Google showed at the last read, or null. */
    public static function googleRead(PDO $db): ?array
    {
        $j = json_decode(self::setting($db, 'listings_gbp_read'), true);
        return is_array($j) && !empty($j['at']) ? $j : null;
    }

    /** Read Google once if the cache is old. Called after the page is sent; any failure is logged and the cache left as it was. */
    public static function refreshGoogle(PDO $db, ?DateTimeImmutable $now = null): bool
    {
        try {
            $now = $now ?? new DateTimeImmutable('now');
            $last = self::setting($db, 'listings_gbp_attempt');
            if ($last !== '' && (int)$last > $now->getTimestamp() - 6 * 3600) return false;
            $have = self::googleRead($db);
            if ($have && strtotime((string)$have['at']) > $now->getTimestamp() - self::GOOGLE_CACHE_HOURS * 3600) return false;
            self::put($db, 'listings_gbp_attempt', (string)$now->getTimestamp(), 'Listings: last attempt to read Google (6 h back-off)');
            require_once dirname(__DIR__, 2) . '/Social/Services/GbpService.php';
            $gbp = new GbpService($db);
            if ($gbp->mode()['mode'] !== 'live') return false;
            $read = self::shapeGoogle($gbp->businessInfo());
            if ($read['name'] === '' && $read['phone'] === '') return false;   // an empty answer is not "everything differs"
            self::put($db, 'listings_gbp_read', json_encode($read + ['at' => $now->format('Y-m-d H:i:s')]), 'Listings: what Google Business Profile showed at the last read');
            return true;
        } catch (Throwable $e) {
            error_log('MiaListingJobs::refreshGoogle: ' . $e->getMessage());
            return false;
        }
    }

    private static function gbpConnected(PDO $db): bool
    {
        try {
            return (int)$db->query("SELECT COUNT(*) FROM social_accounts WHERE platform = 'gbp' AND is_active = 1 AND location_id_external IS NOT NULL AND location_id_external <> ''")->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function setting(PDO $db, string $key): string
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false || $v === null ? '' : (string)$v;
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function put(PDO $db, string $key, string $value, string $why): void
    {
        $db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value, $why]);
    }
}
