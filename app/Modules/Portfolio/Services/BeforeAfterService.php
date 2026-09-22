<?php
declare(strict_types=1);

/**
 * BeforeAfterService — the road from a crew endorsement to the public portfolio.
 *
 *   crew endorse (heart on the visit)
 *     → queueFromVisit(): a PENDING ba_pairs row from the visit's before + after photo
 *   manager (portfolio.edit) opens Before & After → Awaiting approval
 *     → approve(): label / service / neighbourhood / alt text, consent recorded where the
 *       property needs it, web-sized copies made, published = 1
 *     → reject(): parked with a reason, never shown
 *   public portfolio + feed API
 *     → published(): approved pairs only, public fields only
 *
 * What the public ever sees: two photos, a service, a neighbourhood, a month. No address,
 * no client, no crew name, no visit number.
 *
 * Consent: a photo of a lawn is not personal information, so a residential pair needs only
 * the manager's eye (faces, house numbers, plates). Strata and commercial properties are
 * governed by their contracts, so those pairs cannot be approved until the manager records
 * how the client agreed.
 */
class BeforeAfterService
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const CONSENT_NOT_NEEDED = 'not_needed';
    public const CONSENT_NEEDED     = 'needed';
    public const CONSENT_RECORDED   = 'recorded';

    /** Public filter buckets on the portfolio page. */
    public const CATEGORIES = ['lawn', 'garden', 'cleanup', 'strata', 'general'];

    /** Largest web copy we serve; crew originals are 3000+ px. */
    public const WEB_WIDTH = 1280;

    /**
     * Postal-code forward sortation areas → neighbourhood, for the public label.
     * The manager can overwrite it before approval; unknown areas fall back to the city.
     */
    public const NEIGHBOURHOODS = [
        'V5K' => 'Hastings-Sunrise', 'V5L' => 'Grandview-Woodland', 'V5M' => 'Hastings-Sunrise',
        'V5N' => 'Kensington-Cedar Cottage', 'V5P' => 'Victoria-Fraserview', 'V5R' => 'Renfrew-Collingwood',
        'V5S' => 'Killarney', 'V5T' => 'Mount Pleasant', 'V5V' => 'Riley Park', 'V5W' => 'South Cambie',
        'V5X' => 'Sunset', 'V5Y' => 'Mount Pleasant', 'V5Z' => 'Cambie',
        'V6A' => 'Strathcona', 'V6B' => 'Downtown Vancouver', 'V6C' => 'Downtown Vancouver',
        'V6E' => 'West End', 'V6G' => 'Coal Harbour', 'V6H' => 'Fairview', 'V6J' => 'South Granville',
        'V6K' => 'Kitsilano', 'V6L' => 'Arbutus Ridge', 'V6M' => 'Kerrisdale', 'V6N' => 'Dunbar',
        'V6P' => 'Marpole', 'V6R' => 'Point Grey', 'V6S' => 'Dunbar-Southlands', 'V6T' => 'UBC',
        'V6Z' => 'Yaletown',
        'V5A' => 'Burnaby Mountain', 'V5B' => 'North Burnaby', 'V5C' => 'Burnaby Heights',
        'V5E' => 'Deer Lake', 'V5G' => 'Brentwood', 'V5H' => 'Metrotown', 'V5J' => 'South Burnaby',
        'V3N' => 'Edmonds', 'V3J' => 'Lougheed',
        'V6X' => 'Richmond', 'V6Y' => 'Richmond', 'V7A' => 'Steveston', 'V7B' => 'Sea Island',
        'V7C' => 'Terra Nova', 'V7E' => 'Steveston',
        'V7L' => 'Lower Lonsdale', 'V7M' => 'Central Lonsdale', 'V7N' => 'Upper Lonsdale',
        'V7P' => 'Norgate', 'V7R' => 'Edgemont', 'V7S' => 'West Vancouver', 'V7T' => 'Ambleside',
        'V7V' => 'Dundarave', 'V7W' => 'Caulfeild',
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure rules ────────────────────────────────────────────────────────────

    /**
     * Does publishing a photo of this property need the client's agreement on record?
     * Strata and commercial work is contract-governed; a managed or company-owned
     * building is treated the same way even when the type was never filled in.
     */
    public static function consentNeeded(array $property): bool
    {
        $type = strtolower(trim((string)($property['property_type'] ?? '')));
        if (in_array($type, ['strata', 'commercial', 'multi_family', 'multifamily'], true)) {
            return true;
        }
        return !empty($property['property_manager_id']) || !empty($property['company_id'])
            || !empty($property['billing_company_id']);
    }

    /** Neighbourhood for the public label: FSA lookup, then the city, then null. */
    public static function neighbourhood(?string $postalCode, ?string $city): ?string
    {
        $fsa = strtoupper(substr(preg_replace('/\s+/', '', (string)$postalCode), 0, 3));
        if (isset(self::NEIGHBOURHOODS[$fsa])) {
            return self::NEIGHBOURHOODS[$fsa];
        }
        $city = trim((string)$city);
        return $city !== '' ? $city : null;
    }

    /** Portfolio filter bucket for a plan's service_type (free text in the wild). */
    public static function categoryFor(?string $serviceType, bool $strata = false): string
    {
        if ($strata) {
            return 'strata';
        }
        $s = strtolower((string)$serviceType);
        if (preg_match('/clean|leaf|power rak|dethatch/', $s)) return 'cleanup';
        if (preg_match('/garden|hedge|prun|bed|mulch|plant|weed/', $s)) return 'garden';
        if (preg_match('/lawn|cut|mow|fertil|aerat|seed|topsoil|turf|acelepyn/', $s)) return 'lawn';
        return 'general';
    }

    /** Human service name for the public label. */
    public static function serviceName(?string $serviceType): string
    {
        $raw = trim((string)$serviceType);
        if ($raw === '') {
            return 'Property Maintenance';
        }
        $fixed = [
            'lawn_care' => 'Lawn Care', 'landscaping' => 'Landscaping', 'maintenance' => 'Property Maintenance',
            'snow_removal' => 'Snow Removal', 'salt_application' => 'Salt Application',
            'garden_maintenance' => 'Garden Maintenance', 'basic labor' => 'Landscaping',
        ];
        $key = strtolower($raw);
        if (isset($fixed[$key])) {
            return $fixed[$key];
        }
        if (preg_match('/lawn cut/i', $raw)) {
            return 'Lawn Cut';
        }
        return ucwords(strtolower(str_replace('_', ' ', $raw)));
    }

    /** "Lawn Care — Kitsilano" */
    public static function labelFor(?string $serviceType, ?string $area): string
    {
        $service = self::serviceName($serviceType);
        return $area ? $service . ' — ' . $area : $service;
    }

    /** Alt text that says what the picture is, for Google Images and screen readers. */
    public static function altFor(string $which, ?string $serviceType, ?string $area): string
    {
        $service = strtolower(self::serviceName($serviceType));
        $place   = $area ? ' in ' . $area : ' in Metro Vancouver';
        return $which === 'before'
            ? ucfirst($service) . $place . ' before Mowology'
            : ucfirst($service) . $place . ' after Mowology';
    }

    /**
     * Choose the pair from a visit's photo links: the earliest "before" and the latest
     * "after". Rows need media_id, category and a sortable created_at / id.
     *
     * @return array{before:int, after:int}|null
     */
    public static function pickPair(array $links): ?array
    {
        $sortKey = static function (array $r): string {
            return (string)($r['created_at'] ?? '') . '|' . str_pad((string)($r['id'] ?? 0), 10, '0', STR_PAD_LEFT);
        };
        $before = null;
        $after  = null;
        foreach ($links as $r) {
            $cat = strtolower((string)($r['category'] ?? ''));
            $id  = (int)($r['media_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($cat === 'before' && ($before === null || $sortKey($r) < $sortKey($before))) {
                $before = $r;
            } elseif ($cat === 'after' && ($after === null || $sortKey($r) > $sortKey($after))) {
                $after = $r;
            }
        }
        if ($before === null || $after === null) {
            return null;
        }
        return ['before' => (int)$before['media_id'], 'after' => (int)$after['media_id']];
    }

    /**
     * May this pair be approved? Consent, where needed, must be on record.
     *
     * @return array{ok:bool, reason:?string}
     */
    public static function canApprove(array $pair): array
    {
        if (($pair['status'] ?? '') === self::STATUS_APPROVED) {
            return ['ok' => false, 'reason' => 'Already approved'];
        }
        if (($pair['consent_state'] ?? '') === self::CONSENT_NEEDED) {
            return ['ok' => false, 'reason' => 'This property needs the client\'s agreement recorded first'];
        }
        if (empty($pair['before_id']) || empty($pair['after_id'])) {
            return ['ok' => false, 'reason' => 'Both photos are required'];
        }
        return ['ok' => true, 'reason' => null];
    }

    /**
     * From the variants on record, the JPEG to serve publicly: WEB_WIDTH if it exists,
     * else the largest JPEG not wider than 1600, else the original.
     * Rows need format, width, file_path.
     */
    public static function webPathFor(array $variants, string $originalPath): string
    {
        $best = null;
        foreach ($variants as $v) {
            if (strtolower((string)($v['format'] ?? '')) !== 'jpeg' || empty($v['file_path'])) {
                continue;
            }
            $w = (int)($v['width'] ?? 0);
            if ($w === self::WEB_WIDTH) {
                return (string)$v['file_path'];
            }
            if ($w > 1600) {
                continue;
            }
            if ($best === null || $w > (int)$best['width']) {
                $best = $v;
            }
        }
        return $best ? (string)$best['file_path'] : $originalPath;
    }

    /** The fields the public is allowed to see. */
    public static function publicShape(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'before_url' => $row['web_before_path'] ?: $row['before_url'],
            'after_url'  => $row['web_after_path']  ?: $row['after_url'],
            'alt_before' => $row['alt_before'] ?: self::altFor('before', $row['service'] ?? null, $row['area'] ?? null),
            'alt_after'  => $row['alt_after']  ?: self::altFor('after',  $row['service'] ?? null, $row['area'] ?? null),
            'label'      => (string)($row['label'] ?? ''),
            'service'    => (string)($row['service'] ?? ''),
            'category'   => in_array($row['category'] ?? '', self::CATEGORIES, true) ? $row['category'] : 'general',
            'area'       => (string)($row['area'] ?? ''),
            'date'       => !empty($row['approved_at']) ? date('F Y', strtotime($row['approved_at'])) : date('F Y', strtotime($row['created_at'])),
        ];
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    /**
     * Queue a pending pair for an endorsed visit. Idempotent: a visit with a pair in any
     * state is left alone, and a visit without both a before and an after is skipped
     * (it is picked up later by sweepEndorsed() once the after photo lands).
     *
     * @return int|null the pair id, or null when nothing was queued
     */
    public function queueFromVisit(int $visitId, ?int $userId = null): ?int
    {
        if ($visitId <= 0) {
            return null;
        }
        $exists = $this->db->prepare("SELECT id FROM ba_pairs WHERE visit_id = ? LIMIT 1");
        $exists->execute([$visitId]);
        if ($exists->fetchColumn()) {
            return null;
        }

        $links = $this->db->prepare("
            SELECT ml.id, ml.media_id, ml.category, ml.created_at
            FROM media_links ml
            JOIN media_assets ma ON ma.id = ml.media_id
            WHERE ml.context_type = 'job_visit' AND ml.context_id = ?
              AND ml.category IN ('before', 'after')
              AND (ma.status IS NULL OR ma.status <> 'deleted')
        ");
        $links->execute([$visitId]);
        $pair = self::pickPair($links->fetchAll(PDO::FETCH_ASSOC));
        if ($pair === null) {
            return null;
        }

        $ctx = $this->visitContext($visitId);
        $needsConsent = $ctx ? self::consentNeeded($ctx) : true;
        $area         = $ctx ? self::neighbourhood($ctx['postal_code'] ?? null, $ctx['city'] ?? null) : null;
        $serviceType  = $ctx['service_type'] ?? null;
        $strata       = $ctx && strtolower((string)($ctx['property_type'] ?? '')) === 'strata';

        $ins = $this->db->prepare("
            INSERT INTO ba_pairs
                (before_id, after_id, visit_id, label, service, category, area, alt_before, alt_after,
                 published, status, consent_state, queued_by, queued_at, sort_order, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?, ?, NOW(), 0, NOW(), NOW())
        ");
        $ins->execute([
            $pair['before'], $pair['after'], $visitId,
            self::labelFor($serviceType, $area),
            self::serviceName($serviceType),
            self::categoryFor($serviceType, $strata),
            $area,
            self::altFor('before', $serviceType, $area),
            self::altFor('after', $serviceType, $area),
            $needsConsent ? self::CONSENT_NEEDED : self::CONSENT_NOT_NEEDED,
            $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Queue every endorsed visit that has both photos and no pair yet. Cheap, idempotent,
     * run when the manager opens the queue — it catches visits endorsed before the after
     * photo was taken, and everything endorsed while the inbox was reading the wrong table.
     *
     * @return int how many pairs were queued
     */
    public function sweepEndorsed(): int
    {
        $stmt = $this->db->query("
            SELECT jv.id, jv.assigned_crew_id
            FROM job_visits jv
            WHERE jv.is_flagged = 1
              AND NOT EXISTS (SELECT 1 FROM ba_pairs bp WHERE bp.visit_id = jv.id)
              AND EXISTS (SELECT 1 FROM media_links b WHERE b.context_type = 'job_visit' AND b.context_id = jv.id AND b.category = 'before')
              AND EXISTS (SELECT 1 FROM media_links a WHERE a.context_type = 'job_visit' AND a.context_id = jv.id AND a.category = 'after')
            ORDER BY jv.id DESC
            LIMIT 100
        ");
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->queueFromVisit((int)$row['id'], $row['assigned_crew_id'] !== null ? (int)$row['assigned_crew_id'] : null)) {
                $n++;
            }
        }
        return $n;
    }

    /** Property + plan facts for a visit; null if the visit is unknown. */
    private function visitContext(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT jv.id, jv.completed_at, jv.visit_number, jv.assigned_crew_id,
                   COALESCE(NULLIF(jv.service_type, ''), jp.service_type) AS service_type,
                   p.id AS property_id, p.property_name, p.address, p.city, p.postal_code, p.property_type,
                   p.property_manager_id, p.company_id, p.billing_company_id
            FROM job_visits jv
            JOIN job_plans jp ON jp.id = jv.plan_id
            JOIN properties p ON p.id = jp.property_id
            WHERE jv.id = ? LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    // ── Manager queue ─────────────────────────────────────────────────────────

    /** Pending pairs with what the manager needs to judge them (private fields included). */
    public function pending(): array
    {
        $stmt = $this->db->query("
            SELECT bp.*, " . self::assetSelect() . ",
                   jv.visit_number, jv.completed_at, jv.scheduled_date,
                   p.property_name, p.address, p.city, p.property_type,
                   u.username AS queued_by_name
            FROM ba_pairs bp
            JOIN media_assets mb ON mb.id = bp.before_id
            JOIN media_assets ma ON ma.id = bp.after_id
            LEFT JOIN job_visits jv ON jv.id = bp.visit_id
            LEFT JOIN job_plans jp ON jp.id = jv.plan_id
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN users u ON u.id = bp.queued_by
            WHERE bp.status = 'pending'
            ORDER BY bp.queued_at DESC, bp.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{pending:int, approved:int, rejected:int} */
    public function counts(): array
    {
        $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->db->query("SELECT status, COUNT(*) n FROM ba_pairs GROUP BY status") as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int)$r['n'];
            }
        }
        return $out;
    }

    /** Record that the client agreed to publication (how, in the manager's words). */
    public function recordConsent(int $pairId, int $userId, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new InvalidArgumentException('Say how the client agreed (e.g. "Email from Ron 21 Sep")');
        }
        $this->db->prepare("
            UPDATE ba_pairs SET consent_state = 'recorded', consent_note = ?, updated_at = NOW() WHERE id = ?
        ")->execute([mb_substr($note, 0, 255), $pairId]);
    }

    /**
     * Approve and publish. $fields may override label, service, category, area, alt_before,
     * alt_after; blanks keep the queued suggestion. Makes the web-sized copies.
     *
     * @return array the approved pair (public shape)
     */
    public function approve(int $pairId, int $userId, array $fields = []): array
    {
        $pair = $this->load($pairId);
        if (!$pair) {
            throw new RuntimeException('Pair not found');
        }
        $check = self::canApprove($pair);
        if (!$check['ok']) {
            throw new RuntimeException($check['reason']);
        }

        $label    = trim((string)($fields['label'] ?? '')) ?: $pair['label'];
        $service  = trim((string)($fields['service'] ?? '')) ?: $pair['service'];
        $area     = trim((string)($fields['area'] ?? '')) ?: $pair['area'];
        $category = in_array($fields['category'] ?? '', self::CATEGORIES, true) ? $fields['category'] : $pair['category'];
        $altB     = trim((string)($fields['alt_before'] ?? '')) ?: ($pair['alt_before'] ?: self::altFor('before', $service, $area));
        $altA     = trim((string)($fields['alt_after'] ?? ''))  ?: ($pair['alt_after']  ?: self::altFor('after',  $service, $area));

        $webBefore = $this->ensureWebCopy((int)$pair['before_id'], (string)$pair['before_url']);
        $webAfter  = $this->ensureWebCopy((int)$pair['after_id'],  (string)$pair['after_url']);

        $maxSort = (int)$this->db->query("SELECT COALESCE(MAX(sort_order),0) FROM ba_pairs WHERE status='approved'")->fetchColumn();

        $this->db->prepare("
            UPDATE ba_pairs
               SET status = 'approved', published = 1, approved_by = ?, approved_at = NOW(),
                   label = ?, service = ?, category = ?, area = ?, alt_before = ?, alt_after = ?,
                   web_before_path = ?, web_after_path = ?, rejected_reason = NULL,
                   sort_order = ?, updated_at = NOW()
             WHERE id = ?
        ")->execute([
            $userId, mb_substr($label, 0, 255), mb_substr($service, 0, 100), $category, $area ? mb_substr($area, 0, 80) : null,
            mb_substr($altB, 0, 160), mb_substr($altA, 0, 160), $webBefore, $webAfter, $maxSort + 1, $pairId,
        ]);

        return self::publicShape($this->load($pairId));
    }

    public function reject(int $pairId, int $userId, string $reason): void
    {
        $this->db->prepare("
            UPDATE ba_pairs
               SET status = 'rejected', published = 0, approved_by = ?, approved_at = NOW(),
                   rejected_reason = ?, updated_at = NOW()
             WHERE id = ?
        ")->execute([$userId, mb_substr(trim($reason), 0, 255) ?: null, $pairId]);
    }

    /** Take a published pair off the website without losing it. */
    public function unpublish(int $pairId, int $userId, string $reason = 'Unpublished'): void
    {
        $this->reject($pairId, $userId, $reason);
    }

    // ── Public ────────────────────────────────────────────────────────────────

    /** Approved pairs, public fields only, newest approvals first within manual order. */
    public function published(int $limit = 12, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $stmt = $this->db->prepare("
            SELECT bp.*, " . self::assetSelect() . "
            FROM ba_pairs bp
            JOIN media_assets mb ON mb.id = bp.before_id
            JOIN media_assets ma ON ma.id = bp.after_id
            WHERE bp.status = 'approved' AND bp.published = 1
            ORDER BY bp.sort_order DESC, bp.approved_at DESC, bp.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute();
        return array_map([self::class, 'publicShape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function publishedCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM ba_pairs WHERE status = 'approved' AND published = 1")->fetchColumn();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function assetSelect(): string
    {
        return "mb.file_path AS before_url, ma.file_path AS after_url,
                (SELECT v.file_path FROM media_variants v WHERE v.media_id = mb.id AND v.variant_type = 'thumb_square' AND v.format = 'jpeg' LIMIT 1) AS before_thumb,
                (SELECT v.file_path FROM media_variants v WHERE v.media_id = ma.id AND v.variant_type = 'thumb_square' AND v.format = 'jpeg' LIMIT 1) AS after_thumb";
    }

    private function load(int $pairId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT bp.*, " . self::assetSelect() . "
            FROM ba_pairs bp
            JOIN media_assets mb ON mb.id = bp.before_id
            JOIN media_assets ma ON ma.id = bp.after_id
            WHERE bp.id = ? LIMIT 1
        ");
        $stmt->execute([$pairId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * The web-sized JPEG for an asset, generating the responsive set once if the crew
     * upload only made a thumbnail. Falls back to the original rather than failing the
     * approval — a big image on the site beats a pair stuck in the queue.
     */
    private function ensureWebCopy(int $mediaId, string $originalPath): string
    {
        $variants = $this->responsiveVariants($mediaId);
        if (!$variants && function_exists('generateMediaVariants') && defined('PUBLIC_ROOT')) {
            $abs = PUBLIC_ROOT . $originalPath;
            if (is_file($abs)) {
                try {
                    generateMediaVariants($mediaId, $abs);
                } catch (Throwable $e) {
                    error_log('BeforeAfterService: variant generation failed for media ' . $mediaId . ': ' . $e->getMessage());
                }
                $variants = $this->responsiveVariants($mediaId);
            }
        }
        return self::webPathFor($variants, $originalPath);
    }

    private function responsiveVariants(int $mediaId): array
    {
        $stmt = $this->db->prepare("
            SELECT format, width, file_path FROM media_variants
            WHERE media_id = ? AND variant_type = 'responsive'
        ");
        $stmt->execute([$mediaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
