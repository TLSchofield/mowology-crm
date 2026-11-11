<?php
/**
 * Mia's Before & After job: the decisions on a pair waiting for the website, one step at a time, shaped for the Ittai
 * strip (public/crm/includes/ittai-job-mia-ba.php, drawn by public/crm/js/ittai-job-mia.js).
 *
 * Nothing is decided here. For each waiting pair this returns the ONE step that is next: the privacy check (faces,
 * house numbers, licence plates), the client's agreement (strata / managed property only), then Mia's offered text.
 * Each button carries the request the page's own buttons send (before-after_appstack.php: queue_privacy,
 * queue_consent, queue_approve, queue_reject), so there is no second write path and BeforeAfterService::approve()
 * still decides what publishing does. The text is built from the pair's own fields by the existing builders
 * (BeforeAfterService::labelFor / altFor): no AI call. It names the neighbourhood and the service only.
 *
 * Off unless ops_settings ba_flowline = '1'. Any failure means no jobs.
 */
final class BeforeAfterJobAdapter
{
    public const SETTING    = 'ba_flowline';
    public const MASTERED_AT = 5;
    public const MAX_JOBS   = 20;
    public const PAGE       = '/crm/before-after_appstack.php';
    public const REJECT_FACE = 'A face, house number or licence plate is visible';

    public static function enabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return list<array> one card per waiting pair, the next step only. [] when off or on any failure. */
    public static function load(PDO $db): array
    {
        try {
            if (!self::enabled($db)) return [];
            $svc = new BeforeAfterService($db);
            $svc->sweepEndorsed();
            return self::jobs($svc->pending(), $svc->offerRecord());
        } catch (Throwable $e) {
            error_log('BeforeAfterJobAdapter::load: ' . $e->getMessage());
            return [];
        }
    }

    /** @param list<array> $pending BeforeAfterService::pending() rows  @param array{0:int,1:int} $record */
    public static function jobs(array $pending, array $record): array
    {
        $out = [];
        foreach ($pending as $pair) {
            $out[] = self::cardFor($pair, $record);
            if (count($out) >= self::MAX_JOBS) break;
        }
        return $out;
    }

    /** privacy | consent | offer: the step this pair is at. */
    public static function step(array $pair): string
    {
        if (trim((string)($pair['privacy_checked_at'] ?? '')) === '') return 'privacy';
        if (($pair['consent_state'] ?? '') === BeforeAfterService::CONSENT_NEEDED) return 'consent';
        return 'offer';
    }

    /** The four-state triangle from the record: none = forming, an edit or one call = held, 2-4 unedited = known, 5+ = mastered. */
    public static function sure(array $record): string
    {
        $called = (int)($record[0] ?? 0);
        $agreed = (int)($record[1] ?? 0);
        if ($called <= 0) return 'forming';
        if ($agreed < $called) return 'held';
        return $called >= self::MASTERED_AT ? 'mastered' : ($called >= 2 ? 'known' : 'held');
    }

    /**
     * Mia's text for a pair, from its own service and neighbourhood. Anything that could identify the client
     * (property name, street number, postal code, any house-number-looking digits) is left blank, never passed on.
     *
     * @return array{label:string,area:string,alt_before:string,alt_after:string,notes:list<string>}
     */
    public static function offer(array $pair): array
    {
        $service = trim((string)($pair['service'] ?? ''));
        $area    = trim((string)($pair['area'] ?? ''));
        $notes   = [];
        $fields  = [
            'label'      => BeforeAfterService::labelFor($service, $area),
            'area'       => $area,
            'alt_before' => BeforeAfterService::altFor('before', $service, $area),
            'alt_after'  => BeforeAfterService::altFor('after', $service, $area),
        ];
        foreach ($fields as $k => $text) {
            if (self::identifies($text, $pair)) {
                $fields[$k] = '';
                $notes[] = 'left the ' . ['label' => 'label', 'area' => 'neighbourhood', 'alt_before' => 'first description', 'alt_after' => 'second description'][$k] . ' blank: it could identify the client';
            }
        }
        if ($area === '') $notes[] = 'no neighbourhood on file: add one';
        if (class_exists('VoiceProfile')) {
            foreach (['label', 'alt_before', 'alt_after'] as $k) {
                if ($fields[$k] !== '' && VoiceProfile::check($fields[$k])) {
                    $fields[$k] = '';
                    $notes[] = 'left the ' . $k . ' blank: it failed the house-voice check';
                }
            }
        }
        $fields['notes'] = $notes;
        return $fields;
    }

    /** Could this text point at one client? Property name, a postal code, a street number, or any 2-5 digit number. */
    public static function identifies(string $text, array $pair): bool
    {
        if ($text === '') return false;
        $name = trim((string)($pair['property_name'] ?? ''));
        if (mb_strlen($name) >= 4 && stripos($text, $name) !== false) return true;
        if (preg_match('/[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d/', $text)) return true;
        if (preg_match('/\b\d{2,5}\b/', $text)) return true;
        $street = trim((string)($pair['address'] ?? ''));
        if ($street !== '' && stripos($text, $street) !== false) return true;
        return false;
    }

    /** True when what was submitted differs from what Mia offered (compared after trimming). */
    public static function edited(array $pair, array $submitted): bool
    {
        $o = self::offer($pair);
        foreach (['label', 'area', 'alt_before', 'alt_after'] as $k) {
            if (trim((string)($submitted[$k] ?? '')) !== $o[$k]) return true;
        }
        return false;
    }

    public static function cardFor(array $pair, array $record): array
    {
        $id    = (int)$pair['id'];
        $step  = self::step($pair);
        $sure  = self::sure($record);
        $conf  = $record[0] > 0 ? 'Published unedited ' . (int)$record[1] . ' of ' . (int)$record[0] . ' offers.' : '';
        $strata = ($pair['consent_state'] ?? '') !== BeforeAfterService::CONSENT_NOT_NEEDED;
        $when  = !empty($pair['completed_at']) ? $pair['completed_at'] : ($pair['scheduled_date'] ?? $pair['queued_at'] ?? '');
        $o     = $step === 'offer' ? self::offer($pair) : null;
        $post  = [
            'id' => $id,
            'caption' => $o ? $o['label'] : (string)($pair['label'] ?? ''),
            'thumbs' => array_values(array_filter([self::web($pair['before_url'] ?? ''), self::web($pair['after_url'] ?? '')])),
            'platforms' => [],
            'when' => $when ? date('M j', strtotime((string)$when)) : '',
        ];
        $reject = fn(string $reason) => ['label' => 'Not for the website', 'sub' => 'keep it off', 'request' => self::form('queue_reject', $id, ['reason' => $reason])];
        $base = ['head' => 'mia', 'key' => 'ba:' . $id . ':' . $step, 'sure' => $sure, 'confidence' => $conf, 'post' => $post, 'detail' => '', 'fields' => [], 'required' => []];

        if ($step === 'privacy') {
            return array_merge($base, [
                'kind' => 'ba_privacy', 'title' => 'Check the photos',
                'why' => 'Look for faces, house numbers and licence plates. ' . ($strata ? 'This is strata or managed property: the client must agree next, then Mia offers the text.' : 'Residential: Mia offers the text next.'),
                'primary' => ['label' => 'Looks ok', 'sub' => 'no faces, numbers or plates', 'request' => self::form('queue_privacy', $id)],
                'secondary' => ['label' => 'Has a face, number or plate', 'sub' => 'keep it off the website', 'request' => self::form('queue_reject', $id, ['reason' => self::REJECT_FACE])],
            ]);
        }
        if ($step === 'consent') {
            return array_merge($base, [
                'kind' => 'ba_consent', 'title' => 'Has the client agreed?',
                'why' => 'Strata or managed property: the client must agree before the photos go on the website.',
                'fields' => ['note'], 'required' => ['note'], 'requiredMsg' => 'Say how they agreed first.',
                'primary' => ['label' => 'Record agreement', 'sub' => 'then Mia offers the text', 'request' => self::form('queue_consent', $id)],
                'secondary' => $reject('The client has not agreed'),
            ]);
        }
        $why = 'Mia wrote this from the pair. Read it, change what you like, then publish.' . ($o['notes'] ? ' She ' . implode('; ', $o['notes']) . '.' : '');
        return array_merge($base, [
            'kind' => 'ba_offer', 'title' => 'Publish this pair?', 'why' => $why,
            'fields' => ['label', 'area', 'alt_before', 'alt_after'], 'required' => ['label'], 'requiredMsg' => 'The label cannot be empty.',
            'right' => ['label' => $o['label'], 'area' => $o['area'], 'alt_before' => $o['alt_before'], 'alt_after' => $o['alt_after']],
            'primary' => ['label' => 'Publish', 'sub' => 'goes on the website', 'request' => self::form('queue_approve', $id, [
                'service' => (string)($pair['service'] ?? ''), 'category' => (string)($pair['category'] ?? ''), 'offered' => '1',
            ])],
            'secondary' => $reject('Not for the website'),
        ]);
    }

    /** A request in the page's own form-post shape. */
    private static function form(string $action, int $pairId, array $extra = []): array
    {
        return ['url' => self::PAGE, 'form' => true, 'body' => array_merge(['action' => $action, 'pair_id' => (string)$pairId, 'strip' => '1'], $extra)];
    }

    private static function web(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        return $path[0] === '/' || preg_match('#^(https?://|data:image/)#', $path) ? $path : '/' . $path;
    }
}
