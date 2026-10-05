<?php
/**
 * /app/Services/Receipts/ReceiptSmartMatch.php
 * Smart Receipt Categorization Engine
 *
 * Matches OCR text against vendor directory, boosts confidence with GPS proximity,
 * and suggests accounting + GBP categories.
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';
 *   $suggestion = suggestReceiptMeta($ocrText, $lat, $lng, $jobId);
 *   // Returns: [
 *   //   'vendor_id' => 3, 'vendor_name' => 'Home Depot', 'vendor_confidence' => 85,
 *   //   'accounting_category' => 'Materials', 'category_confidence' => 80,
 *   //   'gbp_category' => 'Hardware store', 'gbp_confidence' => 80,
 *   //   'suggested_job_id' => null,
 *   // ]
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

// Keyword-to-category mapping for fallback when no vendor match.
// Category names must match EXPENSE_ACCOUNTING_CATEGORIES in ExpenseConstants.php exactly.
const CATEGORY_KEYWORDS = [
    'Fuel' => [
        'shell', 'petro', 'chevron', 'esso', 'gas', 'fuel', 'gasoline',
        'diesel', 'unleaded', 'pump', 'litre', 'gallon',
    ],
    'Materials' => [
        'lumber', 'soil', 'mulch', 'gravel', 'sand', 'fertilizer', 'seed',
        'sod', 'plants', 'shrub', 'tree', 'fastener', 'screw', 'nail',
        'cement', 'concrete', 'paver', 'stone', 'topsoil', 'compost',
        'bark', 'edging', 'landscape fabric', 'weed barrier',
    ],
    'Tools/Equipment' => [
        'rental', 'hire', 'tool', 'equipment', 'blade', 'trimmer',
        'mower', 'blower', 'chainsaw', 'drill', 'saw', 'compactor',
    ],
    'Disposal/Dump' => [
        'landfill', 'transfer station', 'waste', 'dump', 'disposal',
        'recycling', 'tipping fee', 'green waste', 'yard waste', 'composting',
    ],
    'Licenses/Permits' => [
        'permit', 'license fee', 'licence fee', 'municipal fee', 'city fee',
        'business license', 'occupancy permit',
    ],
    'Repairs/Maintenance' => [
        'repair', 'maintenance', 'service', 'oil change', 'mechanic',
        'brake', 'tire', 'filter',
    ],
    'Vehicle' => [
        'auto parts', 'napa', 'lordco', 'automotive', 'car wash',
    ],
    'Meals' => [
        'restaurant', 'coffee', 'tim hortons', 'starbucks', 'subway',
        'mcdonalds', 'food', 'lunch', 'breakfast', 'dinner',
    ],
    'Office/Admin' => [
        'staples', 'office', 'paper', 'ink', 'printer', 'postage',
    ],
    'Safety' => [
        'safety', 'ppe', 'gloves', 'boots', 'helmet', 'vest',
        'hard hat', 'first aid',
    ],
];

// GBP category keywords (vendor type)
const GBP_KEYWORDS = [
    'Garden center/nursery'      => ['garden', 'nursery', 'plant', 'art knapp', 'gardenworks'],
    'Hardware store'             => ['hardware', 'home depot', 'lowes', 'rona', 'canadian tire'],
    'Building materials'         => ['building supply', 'lumber', 'cement'],
    'Equipment rental'           => ['rental', 'sunbelt', 'united rentals'],
    'Gas station'                => ['shell', 'petro', 'chevron', 'esso', 'gas station', 'fuel'],
    'Waste disposal/landfill'    => ['landfill', 'transfer station', 'waste', 'dump'],
    'Restaurant/food'            => ['restaurant', 'coffee', 'cafe', 'tim hortons', 'starbucks'],
    'Office supply'              => ['staples', 'office', 'print'],
    'Auto parts'                 => ['auto parts', 'napa', 'lordco', 'automotive'],
    'Wholesale store'            => ['costco', 'wholesale'],
];

/**
 * Suggest vendor, accounting category, and GBP category from OCR text + GPS.
 *
 * @param string|null $ocrText  Raw OCR text from receipt
 * @param float|null  $lat      GPS latitude at upload
 * @param float|null  $lng      GPS longitude at upload
 * @param int|null    $jobId    Linked job ID (for context-based suggestions)
 * @param array|null  $parsed   Parsed receipt fields (for line-item category inference)
 * @return array Suggestion with confidence scores
 */
function suggestReceiptMeta(?string $ocrText, ?float $lat, ?float $lng, ?int $jobId = null, ?array $parsed = null): array
{
    $result = [
        'vendor_id'            => null,
        'vendor_name'          => null,
        'vendor_confidence'    => 0,
        'accounting_category'  => null,
        'category_confidence'  => 0,
        'gbp_category'         => null,
        'gbp_confidence'       => 0,
        'suggested_job_id'     => $jobId,
        'match_details'        => [],
    ];

    if (empty($ocrText)) {
        return $result;
    }

    $ocrLower = strtolower($ocrText);
    $db = getDB();

    // ── Step 1: Match against vendor directory ──────────────────────
    $vendorMatch = matchVendorFromOcr($ocrLower, $db);
    if ($vendorMatch) {
        $result['vendor_id']         = $vendorMatch['id'];
        $result['vendor_name']       = $vendorMatch['name'];
        $result['vendor_confidence'] = $vendorMatch['confidence'];
        $result['vendor_gst_exempt'] = !empty($vendorMatch['gst_exempt']);
        $result['match_details'][]   = $vendorMatch['reason'];

        // Use vendor defaults for categories
        if (!empty($vendorMatch['default_accounting_category'])) {
            $result['accounting_category'] = $vendorMatch['default_accounting_category'];
            $result['category_confidence'] = $vendorMatch['confidence'];
        }
        if (!empty($vendorMatch['default_gbp_category'])) {
            $result['gbp_category']  = $vendorMatch['default_gbp_category'];
            $result['gbp_confidence'] = $vendorMatch['confidence'];
        }

        // Patch missing category from OCR context when vendor record has no default —
        // e.g. City of Vancouver with green waste / landfill text → Disposal/Dump.
        if (empty($result['accounting_category'])) {
            $nameLower = strtolower($vendorMatch['name'] ?? '');
            if (
                stripos($nameLower, 'landfill') !== false ||
                stripos($ocrLower, 'green waste') !== false ||
                stripos($ocrLower, 'yard waste') !== false ||
                stripos($ocrLower, 'landfill') !== false ||
                stripos($ocrLower, 'transfer station') !== false
            ) {
                $result['accounting_category'] = 'Disposal/Dump';
                $result['category_confidence'] = 70;
            } elseif (
                stripos($ocrLower, 'permit') !== false ||
                stripos($ocrLower, 'license fee') !== false ||
                stripos($ocrLower, 'municipal') !== false
            ) {
                $result['accounting_category'] = 'Licenses/Permits';
                $result['category_confidence'] = 65;
            }
        }
    }

    // ── Step 1b: Learned store location names the vendor the text couldn't ──
    if (!$result['vendor_id'] && $lat !== null && $lng !== null) {
        $store = nearestKnownStore($db, $lat, $lng);
        if ($store) {
            $result['vendor_id']         = (int)$store['vendor_id'];
            $result['vendor_name']       = $store['vendor_name'];
            $result['vendor_confidence'] = 55;
            $result['vendor_gst_exempt'] = !empty($store['gst_exempt']);
            $result['match_details'][]   = sprintf('Store location: %d m away%s', $store['meters'],
                $store['receipts_seen'] > 0 ? ", bought here {$store['receipts_seen']}×" : '');
            if (!empty($store['default_accounting_category'])) {
                $result['accounting_category'] = $store['default_accounting_category'];
                $result['category_confidence'] = 50;
            }
            if (!empty($store['default_gbp_category'])) {
                $result['gbp_category']  = $store['default_gbp_category'];
                $result['gbp_confidence'] = 50;
            }
        }
    }

    // ── Step 2: GPS proximity boost ─────────────────────────────────
    if ($lat !== null && $lng !== null && $result['vendor_id']) {
        $gpsBoost = checkVendorProximity($result['vendor_id'], $lat, $lng, $db);
        if ($gpsBoost > 0) {
            $result['vendor_confidence'] = min(100, $result['vendor_confidence'] + $gpsBoost);
            $result['category_confidence'] = min(100, $result['category_confidence'] + $gpsBoost);
            $result['match_details'][] = "GPS within range (+{$gpsBoost})";
        }
    }

    // ── Step 2b: Vendor accuracy routing ─────────────────────────────
    if ($result['vendor_id']) {
        $accuracyInfo = getVendorAccuracyRouting($result['vendor_id'], $db);
        if ($accuracyInfo) {
            $result['vendor_accuracy_rate'] = $accuracyInfo['accuracy_rate'];
            $result['vendor_total_receipts'] = $accuracyInfo['total_receipts'];

            if ($accuracyInfo['accuracy_rate'] > 80 && $accuracyInfo['total_receipts'] >= 3) {
                $result['vendor_confidence'] = min(100, $result['vendor_confidence'] + 10);
                $result['match_details'][] = sprintf('High accuracy vendor (%.0f%%, +10)', $accuracyInfo['accuracy_rate']);
            } elseif ($accuracyInfo['accuracy_rate'] < 50 && $accuracyInfo['total_receipts'] >= 3) {
                $result['vendor_confidence'] = max(0, $result['vendor_confidence'] - 15);
                $result['low_accuracy_warning'] = true;
                $result['match_details'][] = sprintf('Low accuracy vendor (%.0f%%, -15)', $accuracyInfo['accuracy_rate']);
            }
        }
    }

    // ── Step 3: Keyword fallback for category ───────────────────────
    if (empty($result['accounting_category'])) {
        $kwMatch = matchCategoryByKeywords($ocrLower);
        if ($kwMatch) {
            $result['accounting_category'] = $kwMatch['category'];
            $result['category_confidence'] = $kwMatch['confidence'];
            $result['match_details'][]     = 'Keyword match: ' . $kwMatch['keyword'];
        }
    }

    if (empty($result['gbp_category'])) {
        $gbpMatch = matchGbpByKeywords($ocrLower);
        if ($gbpMatch) {
            $result['gbp_category']  = $gbpMatch['category'];
            $result['gbp_confidence'] = $gbpMatch['confidence'];
        }
    }

    // ── Line item → category inference ────────────────────────────────
    // If category is still unknown or low-confidence, derive it from extracted
    // line item names. Helpful for new vendors and general stores (e.g., Costco
    // selling landscaping materials should still suggest "Materials").
    if ((!empty($parsed['line_items']) || !empty($parsed['product_matches'])) &&
        (empty($result['accounting_category']) || $result['category_confidence'] < 60)
    ) {
        $allItems = array_merge($parsed['line_items'] ?? [], $parsed['product_matches'] ?? []);
        $lineItemText = strtolower(implode(' ', array_column($allItems, 'name')));
        if (!empty($lineItemText)) {
            foreach (CATEGORY_KEYWORDS as $cat => $keywords) {
                foreach ($keywords as $kw) {
                    if (strpos($lineItemText, $kw) !== false) {
                        $result['accounting_category']        = $cat;
                        $result['category_confidence']        = 65;
                        $result['category_from_line_items']   = true;
                        $result['match_details'][]            = 'Line item keyword: ' . $kw;
                        break 2;
                    }
                }
            }
        }
    }

    return $result;
}

/**
 * Match vendor from OCR text against the vendors table aliases.
 *
 * @param string $ocrLower Lowercase OCR text
 * @param PDO    $db
 * @return array|null Matched vendor data with confidence, or null
 */
function matchVendorFromOcr(string $ocrLower, PDO $db): ?array
{
    try {
        $stmt = $db->query("
            SELECT id, name, aliases, default_accounting_category, default_gbp_category, phone, gst_exempt
            FROM vendors
            WHERE is_active = 1
            ORDER BY id
        ");
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('matchVendorFromOcr error: ' . $e->getMessage());
        return null;
    }

    $bestMatch = null;
    $bestScore = 0;

    // Phones printed on the receipt, separators stripped ("604-555-1234" → 6045551234).
    // Comparing the vendor's digits against the raw text only matched receipts that
    // print the number without separators.
    if (!function_exists('extractPhoneNumbers')) {
        require_once __DIR__ . '/ReceiptParser.php';
    }
    $ocrPhones = extractPhoneNumbers($ocrLower);

    foreach ($vendors as $vendor) {
        $score = 0;
        $reason = '';

        // Check vendor name
        if (stripos($ocrLower, strtolower($vendor['name'])) !== false) {
            $score = 60;
            $reason = 'Name match: ' . $vendor['name'];
        }

        // Check aliases
        if ($score === 0 && !empty($vendor['aliases'])) {
            $aliases = array_map('trim', explode(',', $vendor['aliases']));
            foreach ($aliases as $alias) {
                if (empty($alias)) continue;
                if (stripos($ocrLower, strtolower($alias)) !== false) {
                    $score = 60;
                    $reason = 'Alias match: ' . $alias;
                    break;
                }
            }
        }

        // Check phone number match (strong signal)
        if (!empty($vendor['phone'])) {
            $cleanPhone = preg_replace('/\D/', '', $vendor['phone']);
            $last10     = strlen($cleanPhone) >= 10 ? substr($cleanPhone, -10) : null;
            if (($last10 !== null && in_array($last10, $ocrPhones, true))
                || (strlen($cleanPhone) >= 7 && strpos($ocrLower, $cleanPhone) !== false)) {
                $score = max($score, 80);
                $reason = 'Phone match: ' . $vendor['phone'];
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = array_merge($vendor, [
                'confidence' => $score,
                'reason'     => $reason,
            ]);
        }
    }

    return $bestMatch;
}

/**
 * Check if GPS coordinates are within range of a vendor's known locations.
 *
 * @return int Confidence boost (0 if no match, 30 if within 500m)
 */
function checkVendorProximity(int $vendorId, float $lat, float $lng, PDO $db): int
{
    try {
        $stmt = $db->prepare("
            SELECT lat, lng FROM vendor_locations
            WHERE vendor_id = ? AND lat IS NOT NULL AND lng IS NOT NULL
        ");
        $stmt->execute([$vendorId]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locations as $loc) {
            $distance = haversineDistance($lat, $lng, (float)$loc['lat'], (float)$loc['lng']);
            if ($distance <= 0.5) { // Within 500 meters
                return 30;
            }
            if ($distance <= 2.0) { // Within 2 km
                return 15;
            }
        }
    } catch (Throwable $e) {
        error_log('checkVendorProximity error: ' . $e->getMessage());
    }

    return 0;
}

/**
 * Calculate distance between two GPS coordinates in kilometers (Haversine formula).
 */
function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

/**
 * Match accounting category by keyword search in OCR text.
 *
 * @return array|null ['category' => string, 'confidence' => int, 'keyword' => string]
 */
function matchCategoryByKeywords(string $ocrLower): ?array
{
    foreach (CATEGORY_KEYWORDS as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($ocrLower, $keyword) !== false) {
                return [
                    'category'   => $category,
                    'confidence' => 40,
                    'keyword'    => $keyword,
                ];
            }
        }
    }
    return null;
}

/**
 * Match GBP category by keyword search in OCR text.
 *
 * @return array|null ['category' => string, 'confidence' => int]
 */
function matchGbpByKeywords(string $ocrLower): ?array
{
    foreach (GBP_KEYWORDS as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (stripos($ocrLower, $keyword) !== false) {
                return [
                    'category'   => $category,
                    'confidence' => 35,
                ];
            }
        }
    }
    return null;
}


/**
 * Suggest matching job(s) from today's/tomorrow's schedule based on GPS + crew + time.
 *
 * Scores calendar_stops by:
 *   - GPS proximity (receipt lat/lng vs property lat/lng): +50 within 200m, +30 within 1km, +10 within 5km
 *   - Crew match (user assigned to stop): +20
 *   - Time proximity (receipt time vs estimated_arrival): +20 within 1hr, +10 within 3hr
 *   - Status boost (stop in_progress): +15
 *
 * Returns top 3 ranked suggestions with contact/property info.
 *
 * @param int        $userId  Current logged-in user ID
 * @param float|null $lat     GPS latitude at receipt upload
 * @param float|null $lng     GPS longitude at receipt upload
 * @return array Array of job suggestions, each with score and match_reasons
 */
function suggestJobFromSchedule(int $userId, ?float $lat, ?float $lng, ?string $purchaseDate = null, ?string $purchaseTime = null): array
{
    try {
        // Load plan functions for getCalendarStops()
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';

        // With the receipt's own date, match that day's schedule; with its printed time
        // too, match against the visits around the moment of purchase. Without either,
        // fall back to today/tomorrow against the upload time (the original behaviour).
        $purchaseDate = receiptMatchDate($purchaseDate);
        $anchorIsPurchase = false;
        if ($purchaseDate !== null) {
            $from = $to = $purchaseDate;
            $anchor = ($purchaseTime !== null && preg_match('/^\d{2}:\d{2}$/', $purchaseTime))
                ? strtotime($purchaseDate . ' ' . $purchaseTime) : null;
            $anchorIsPurchase = $anchor !== null && $anchor !== false;
            if (!$anchorIsPurchase) $anchor = null;
        } else {
            $from = date('Y-m-d');
            $to = date('Y-m-d', strtotime('+1 day'));
            $anchor = time();
        }

        // All crews' stops (no crew filter — we score crew ourselves)
        $calendarData = getCalendarStops($from, $to);

        $db = getDB();
        $scored = [];

        foreach ($calendarData as $date => $stops) {
            foreach ($stops as $stopId => $stop) {
                $m = scoreStopForReceipt($stop, (string)$date, $userId, $lat, $lng, $anchor, $anchorIsPurchase, $purchaseDate !== null);
                $score = $m['score'];
                $reasons = $m['reasons'];

                // Date-only matches need a second signal — "same day" alone would list
                // every stop that day.
                if ($score > ($purchaseDate !== null ? 10 : 0)) {
                    // Look up contact_id from property
                    $contactId = null;
                    if (!empty($stop['property_id'])) {
                        $stmt = $db->prepare("SELECT site_contact_id FROM properties WHERE id = ?");
                        $stmt->execute([$stop['property_id']]);
                        $contactId = $stmt->fetchColumn() ?: null;
                    }

                    // Get the first visit for plan info
                    $firstVisit = $stop['visits'][0] ?? [];

                    $scored[] = [
                        // Fields required by iOS JobSuggestion struct
                        'id'               => (int)($firstVisit['plan_id'] ?? $stopId),
                        'plan_number'      => $firstVisit['plan_number'] ?? null,
                        'service_type'     => $firstVisit['service_type'] ?? null,
                        'address'          => $stop['property_address'] ?? null,
                        // Additional context fields
                        'stop_id'          => (int)$stopId,
                        'property_id'      => (int)($stop['property_id'] ?? 0),
                        'property_city'     => $stop['property_city'] ?? '',
                        'contact_name'      => $stop['contact_name'] ?? '',
                        'contact_id'        => $contactId ? (int)$contactId : null,
                        'company_name'      => $stop['company_name'] ?? '',
                        'plan_id'           => (int)($firstVisit['plan_id'] ?? 0),
                        'plan_title'        => $firstVisit['plan_title'] ?? '',
                        'visit_id'          => (int)($firstVisit['visit_id'] ?? 0),
                        'stop_date'         => $date,
                        'score'             => $score,
                        'match_reasons'     => $reasons,
                        '_start'            => $m['start'],
                        '_crew'             => $m['crew'],
                    ];
                }
            }
        }

        // Supplies are usually bought on the way to the job: the purchaser's first stop
        // starting within 3h after the purchase gets the strongest time signal.
        if ($anchorIsPurchase) {
            $next = nextStopAfterPurchase($scored, (int)$anchor);
            if ($next !== null) {
                $scored[$next]['score'] += 20;
                $scored[$next]['match_reasons'][] = 'Next stop after purchase';
            }
        }
        foreach ($scored as &$row) {
            unset($row['_start'], $row['_crew']);
        }
        unset($row);

        // Sort by score descending, return top 3
        usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });
        return array_slice($scored, 0, 3);

    } catch (Throwable $e) {
        error_log('suggestJobFromSchedule error: ' . $e->getMessage());
        return [];
    }
}


/**
 * The receipt's printed date, if it's usable for a schedule lookup: a real
 * Y-m-d no later than tomorrow (time zones) and no older than a year.
 */
function receiptMatchDate(?string $date): ?string
{
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $ts = strtotime($date);
    if ($ts === false || $ts > strtotime('+1 day') || $ts < strtotime('-365 days')) {
        return null;
    }
    return $date;
}

/**
 * Score one scheduled stop against a receipt. Pure apart from haversineDistance().
 *
 * Purchase-anchored (the receipt printed a time): bought during the visit window
 * +30, the visit starts later that day within 3h +10 (the next one also gets +20 in
 * nextStopAfterPurchase), visit ended within the hour before +5. Date-only: +10 for
 * the same day. Upload-anchored (no receipt date): the original ±1h/±3h of arrival
 * on today's stops, plus the in-progress boost.
 *
 * @return array{score: int, reasons: string[], start: ?int, crew: bool}
 */
function scoreStopForReceipt(array $stop, string $stopDate, int $userId, ?float $lat, ?float $lng, ?int $anchor, bool $anchorIsPurchase, bool $dateKnown): array
{
    $score = 0;
    $reasons = [];

    if ($lat !== null && $lng !== null && !empty($stop['latitude']) && !empty($stop['longitude'])) {
        $distance = haversineDistance($lat, $lng, (float)$stop['latitude'], (float)$stop['longitude']);
        if ($distance <= 0.2) {
            $score += 50; $reasons[] = 'GPS within 200m';
        } elseif ($distance <= 1.0) {
            $score += 30; $reasons[] = 'GPS within 1km';
        } elseif ($distance <= 5.0) {
            $score += 10; $reasons[] = 'GPS within 5km';
        }
    }

    $crewIds = $stop['crew_ids'] ?? [];
    $crew = in_array($userId, $crewIds) || ($stop['crew_id'] ?? 0) == $userId;
    if ($crew) {
        $score += 20; $reasons[] = 'Crew assigned';
    }

    // Visit window: the stop's estimate, else the first visit's scheduled slot.
    $first = $stop['visits'][0] ?? [];
    $startStr = $stop['estimated_arrival'] ?: ($first['scheduled_time_start'] ?? null);
    $endStr   = $stop['estimated_departure'] ?: ($first['scheduled_time_end'] ?? null);
    $start = $startStr ? strtotime($stopDate . ' ' . $startStr) : false;
    $start = $start === false ? null : $start;
    $end = $endStr ? strtotime($stopDate . ' ' . $endStr) : false;
    if (($end === false || $end === null) && $start !== null) {
        $end = $start + 60 * (int)($first['estimated_duration'] ?? 60 ?: 60);
    }

    if ($anchorIsPurchase && $anchor !== null) {
        $score += 10; $reasons[] = 'Same day as receipt';
        if ($start !== null) {
            if ($anchor >= $start - 900 && $anchor <= $end) {
                $score += 30; $reasons[] = 'Bought during this visit';
            } elseif ($start > $anchor && $start - $anchor <= 3 * 3600) {
                $score += 10; $reasons[] = 'Visit within 3h after purchase';
            } elseif ($anchor > $end && $anchor - $end <= 3600) {
                $score += 5; $reasons[] = 'Bought just after this visit';
            }
        }
    } elseif ($dateKnown) {
        $score += 10; $reasons[] = 'Same day as receipt';
    } elseif ($anchor !== null && $start !== null && $stopDate === date('Y-m-d')) {
        $timeDiff = abs($anchor - $start);
        if ($timeDiff <= 3600) {
            $score += 20; $reasons[] = 'Within 1hr of arrival';
        } elseif ($timeDiff <= 10800) {
            $score += 10; $reasons[] = 'Within 3hr of arrival';
        }
        if (($stop['status'] ?? $stop['stop_status'] ?? '') === 'in_progress') {
            $score += 15; $reasons[] = 'Stop in progress';
        }
    }

    return ['score' => $score, 'reasons' => $reasons, 'start' => $start, 'crew' => $crew];
}

/**
 * Index of the first stop starting after the purchase (within 3h), preferring the
 * purchaser's own crew. Rows carry '_start' / '_crew' from scoreStopForReceipt().
 */
function nextStopAfterPurchase(array $rows, int $purchaseTs): ?int
{
    $best = null;
    foreach ([true, false] as $ownCrewOnly) {
        foreach ($rows as $i => $r) {
            if ($ownCrewOnly && empty($r['_crew'])) continue;
            $start = $r['_start'] ?? null;
            if ($start === null || $start <= $purchaseTs || $start - $purchaseTs > 3 * 3600) continue;
            if ($best === null || $start < $rows[$best]['_start']) $best = $i;
        }
        if ($best !== null) return $best;
    }
    return null;
}


// ═══════════════════════════════════════════════════════════════════════
// Learned store locations (migration 1124)
// ═══════════════════════════════════════════════════════════════════════

/** Learned spots within this distance are the same store. */
const STORE_MATCH_METERS = 150;
/** A receipt this close to a known store is assumed to be from it. */
const STORE_SUGGEST_METERS = 300;
/** A spot shared by this many different vendors is home/office/truck, not a store. */
const NON_STORE_VENDOR_COUNT = 3;

function vendorLocationsLearnedReady(PDO $db): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = $db->query("SHOW COLUMNS FROM vendor_locations LIKE 'receipts_seen'")->rowCount() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Metres between two points. */
function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    return haversineDistance($lat1, $lng1, $lat2, $lng2) * 1000;
}

/**
 * Distinct vendors among located receipts within $meters of a point. Pure.
 * @param array $rows each ['vendor' => id|string, 'lat' => float, 'lng' => float]
 */
function distinctVendorsNear(array $rows, float $lat, float $lng, float $meters): int
{
    $seen = [];
    foreach ($rows as $r) {
        if (metersBetween($lat, $lng, (float)$r['lat'], (float)$r['lng']) <= $meters) {
            $seen[(string)$r['vendor']] = true;
        }
    }
    return count($seen);
}

/**
 * Is this where the crew photographs receipts rather than where they buy — home,
 * the office, the truck's usual spot? On live data a quarter of located receipts
 * shared one spot, and three spots each carried 4+ different vendors.
 */
function isNonStoreSpot(PDO $db, float $lat, float $lng): bool
{
    $dLat = 0.0015;                                   // ~165 m
    $dLng = 0.0015 / max(0.2, cos(deg2rad($lat)));
    $stmt = $db->prepare("
        SELECT COALESCE(CAST(vendor_id AS CHAR), LOWER(vendor_name_raw)) AS vendor, receipt_lat AS lat, receipt_lng AS lng
        FROM expenses
        WHERE receipt_lat BETWEEN ? AND ? AND receipt_lng BETWEEN ? AND ?
    ");
    $stmt->execute([$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);
    $rows = array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($r) => $r['vendor'] !== null && $r['vendor'] !== '');
    return distinctVendorsNear($rows, $lat, $lng, STORE_MATCH_METERS) >= NON_STORE_VENDOR_COUNT;
}

/**
 * Nearest store this receipt could be from: a hand-entered location, or a learned
 * one confirmed by 2+ receipts. Never at a non-store spot.
 *
 * @return array|null vendor row fields + location + 'meters'
 */
function nearestKnownStore(PDO $db, float $lat, float $lng, float $maxMeters = STORE_SUGGEST_METERS): ?array
{
    try {
        $learned = vendorLocationsLearnedReady($db);
        $dLat = 0.004;                                   // ~450 m box, refined below
        $dLng = 0.004 / max(0.2, cos(deg2rad($lat)));
        $stmt = $db->prepare("
            SELECT vl.vendor_id, vl.lat, vl.lng, " . ($learned ? "vl.source, vl.receipts_seen" : "NULL AS source, 0 AS receipts_seen") . ",
                   v.name AS vendor_name, v.default_accounting_category, v.default_gbp_category, v.gst_exempt
            FROM vendor_locations vl
            JOIN vendors v ON v.id = vl.vendor_id AND v.is_active = 1
            WHERE vl.lat BETWEEN ? AND ? AND vl.lng BETWEEN ? AND ?
        ");
        $stmt->execute([$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng]);

        $best = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['source'] === 'learned' && (int)$row['receipts_seen'] < 2) continue;
            $m = metersBetween($lat, $lng, (float)$row['lat'], (float)$row['lng']);
            if ($m <= $maxMeters && ($best === null || $m < $best['meters'])) {
                $best = $row + ['meters' => (int)round($m)];
            }
        }
        if ($best && isNonStoreSpot($db, $lat, $lng)) {
            return null;
        }
        if ($best) {
            $best['receipts_seen'] = (int)$best['receipts_seen'];
        }
        return $best;
    } catch (Throwable $e) {
        error_log('nearestKnownStore: ' . $e->getMessage());
        return null;
    }
}

/** Running centroid after adding one point to a spot seen $n times. Pure. */
function movedCentroid(float $lat, float $lng, int $n, float $newLat, float $newLng): array
{
    $n = max(1, $n);
    return [round(($lat * $n + $newLat) / ($n + 1), 7), round(($lng * $n + $newLng) / ($n + 1), 7)];
}

/**
 * Attach an approved receipt's capture location to its vendor's store spots.
 * Returns what happened: 'skipped' | 'non_store' | 'confirmed' | 'new'.
 */
function learnStoreLocation(PDO $db, int $vendorId, float $lat, float $lng): string
{
    if (!vendorLocationsLearnedReady($db)) {
        return 'skipped';
    }
    if (isNonStoreSpot($db, $lat, $lng)) {
        return 'non_store';
    }
    $stmt = $db->prepare("SELECT id, lat, lng, source, receipts_seen FROM vendor_locations WHERE vendor_id = ? AND lat IS NOT NULL AND lng IS NOT NULL");
    $stmt->execute([$vendorId]);
    $nearest = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $loc) {
        $m = metersBetween($lat, $lng, (float)$loc['lat'], (float)$loc['lng']);
        if ($m <= STORE_MATCH_METERS && ($nearest === null || $m < $nearest['m'])) {
            $nearest = $loc + ['m' => $m];
        }
    }

    if ($nearest) {
        if ($nearest['source'] === 'learned') {
            [$cLat, $cLng] = movedCentroid((float)$nearest['lat'], (float)$nearest['lng'], (int)$nearest['receipts_seen'], $lat, $lng);
            $db->prepare("UPDATE vendor_locations SET lat = ?, lng = ?, receipts_seen = receipts_seen + 1, last_seen_at = NOW() WHERE id = ?")
               ->execute([$cLat, $cLng, $nearest['id']]);
        } else {
            // Hand-entered coordinates stay put; just count the confirmation.
            $db->prepare("UPDATE vendor_locations SET receipts_seen = receipts_seen + 1, last_seen_at = NOW() WHERE id = ?")
               ->execute([$nearest['id']]);
        }
        return 'confirmed';
    }

    $db->prepare("
        INSERT INTO vendor_locations (vendor_id, label, lat, lng, source, receipts_seen, last_seen_at)
        VALUES (?, 'Learned from receipts', ?, ?, 'learned', 1, NOW())
    ")->execute([$vendorId, round($lat, 7), round($lng, 7)]);
    return 'new';
}


// ═══════════════════════════════════════════════════════════════════════
// Vendor Accuracy Routing
// ═══════════════════════════════════════════════════════════════════════

/**
 * Look up a vendor's parsing accuracy from vendor_parse_profiles.
 *
 * @param int $vendorId
 * @param PDO $db
 * @return array|null ['accuracy_rate' => float, 'total_receipts' => int] or null
 */
function getVendorAccuracyRouting(int $vendorId, PDO $db): ?array
{
    try {
        $stmt = $db->prepare("
            SELECT accuracy_rate, total_receipts
            FROM vendor_parse_profiles
            WHERE vendor_id = ?
        ");
        $stmt->execute([$vendorId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) return null;

        return [
            'accuracy_rate'  => (float)($profile['accuracy_rate'] ?? 0),
            'total_receipts' => (int)($profile['total_receipts'] ?? 0),
        ];
    } catch (\Throwable $e) {
        return null;
    }
}


// ═══════════════════════════════════════════════════════════════════════
// Vendor Auto-Creation Gating (Fuzzy Match)
// ═══════════════════════════════════════════════════════════════════════

/**
 * Check if a vendor hint should auto-create a new vendor or if it matches
 * an existing one via fuzzy matching. Prevents OCR typos from polluting
 * the vendor table.
 *
 * @param string   $vendorHint OCR-extracted vendor name
 * @param PDO      $db
 * @param int      $minConfidence Minimum OCR confidence to allow auto-creation (0-100)
 * @return array ['action' => 'match'|'create'|'skip', 'vendor' => array|null, 'similarity' => float]
 */
function gateVendorAutoCreation(string $vendorHint, PDO $db, int $minConfidence = 70): array
{
    $vendorHint = trim($vendorHint);
    if (empty($vendorHint)) {
        return ['action' => 'skip', 'vendor' => null, 'similarity' => 0];
    }

    $hintLower = strtolower($vendorHint);

    try {
        $stmt = $db->query("SELECT id, name, aliases FROM vendors WHERE is_active = 1");
        $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return ['action' => 'skip', 'vendor' => null, 'similarity' => 0];
    }

    $bestMatch = null;
    $bestSimilarity = 0;

    foreach ($vendors as $vendor) {
        // Check vendor name similarity
        $nameLower = strtolower($vendor['name']);
        $similarity = 0;

        // Exact containment
        if (strpos($hintLower, $nameLower) !== false || strpos($nameLower, $hintLower) !== false) {
            $similarity = 90;
        } else {
            // Levenshtein distance (normalized)
            $maxLen = max(strlen($hintLower), strlen($nameLower));
            if ($maxLen > 0) {
                $distance = levenshtein($hintLower, $nameLower);
                $similarity = (1 - ($distance / $maxLen)) * 100;
            }
        }

        if ($similarity > $bestSimilarity) {
            $bestSimilarity = $similarity;
            $bestMatch = $vendor;
        }

        // Also check aliases
        if (!empty($vendor['aliases'])) {
            $aliases = array_map('trim', explode(',', $vendor['aliases']));
            foreach ($aliases as $alias) {
                if (empty($alias)) continue;
                $aliasLower = strtolower($alias);

                $aliasSim = 0;
                if (strpos($hintLower, $aliasLower) !== false || strpos($aliasLower, $hintLower) !== false) {
                    $aliasSim = 85;
                } else {
                    $maxLen = max(strlen($hintLower), strlen($aliasLower));
                    if ($maxLen > 0) {
                        $distance = levenshtein($hintLower, $aliasLower);
                        $aliasSim = (1 - ($distance / $maxLen)) * 100;
                    }
                }

                if ($aliasSim > $bestSimilarity) {
                    $bestSimilarity = $aliasSim;
                    $bestMatch = $vendor;
                }
            }
        }
    }

    // Decision logic:
    // >70% similarity: use existing vendor (likely OCR variant of known vendor)
    // 50-70%: flag for review (might be a match, might not)
    // <50%: allow auto-create (genuinely new vendor)
    if ($bestSimilarity >= 70 && $bestMatch) {
        return [
            'action'     => 'match',
            'vendor'     => $bestMatch,
            'similarity' => round($bestSimilarity, 1),
            'reason'     => sprintf('Fuzzy match to "%s" (%.0f%% similar)', $bestMatch['name'], $bestSimilarity),
        ];
    }

    if ($bestSimilarity >= 50 && $bestMatch) {
        return [
            'action'     => 'review',
            'vendor'     => $bestMatch,
            'similarity' => round($bestSimilarity, 1),
            'reason'     => sprintf('Possible match to "%s" (%.0f%% similar) — needs review', $bestMatch['name'], $bestSimilarity),
        ];
    }

    return [
        'action'     => 'create',
        'vendor'     => null,
        'similarity' => round($bestSimilarity, 1),
        'reason'     => 'No close vendor match found — safe to auto-create',
    ];
}


// ═══════════════════════════════════════════════════════════════════════
// Job-Type Expense Validation
// ═══════════════════════════════════════════════════════════════════════

/**
 * Mapping of job service types to expected expense categories.
 */
const JOB_CATEGORY_MAP = [
    'lawn mowing'          => ['Fuel', 'Materials', 'Repairs/Maintenance'],
    'lawn care'            => ['Fuel', 'Materials', 'Repairs/Maintenance'],
    'hedge trimming'       => ['Fuel', 'Tools/Equipment', 'Disposal/Dump'],
    'tree service'         => ['Fuel', 'Tools/Equipment', 'Disposal/Dump', 'Materials'],
    'landscape install'    => ['Materials', 'Tools/Equipment', 'Disposal/Dump', 'Fuel'],
    'hardscaping'          => ['Materials', 'Tools/Equipment', 'Fuel'],
    'garden maintenance'   => ['Materials', 'Fuel', 'Disposal/Dump'],
    'irrigation'           => ['Materials', 'Tools/Equipment'],
    'snow removal'         => ['Fuel', 'Materials', 'Vehicle'],
    'spring cleanup'       => ['Disposal/Dump', 'Fuel', 'Materials'],
    'fall cleanup'         => ['Disposal/Dump', 'Fuel', 'Materials'],
    'fertilization'        => ['Materials', 'Fuel'],
    'aeration'             => ['Fuel', 'Tools/Equipment'],
    'power washing'        => ['Fuel', 'Tools/Equipment'],
];

/**
 * Validate whether an expense category makes sense for a job's service type.
 *
 * @param int    $jobPlanId Job plan ID
 * @param string $category  Expense accounting category
 * @param PDO|null $db
 * @return array|null ['mismatch' => true, 'message' => string, 'expected' => array] or null if OK
 */
function validateExpenseForJob(int $jobPlanId, string $category, ?PDO $db = null): ?array
{
    if ($db === null) $db = getDB();
    if (empty($category)) return null;

    try {
        // Get the service types for this job's plan line items
        $stmt = $db->prepare("
            SELECT DISTINCT pli.service_type
            FROM plan_line_items pli
            WHERE pli.plan_id = ?
              AND pli.service_type IS NOT NULL
              AND pli.service_type != ''
        ");
        $stmt->execute([$jobPlanId]);
        $serviceTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($serviceTypes)) return null;

        // Collect all expected categories for this job's service types
        $expectedCategories = [];
        foreach ($serviceTypes as $st) {
            $stLower = strtolower($st);
            foreach (JOB_CATEGORY_MAP as $jobType => $cats) {
                if (strpos($stLower, $jobType) !== false || strpos($jobType, $stLower) !== false) {
                    $expectedCategories = array_merge($expectedCategories, $cats);
                }
            }
        }

        $expectedCategories = array_unique($expectedCategories);

        // If we couldn't determine expected categories, don't flag
        if (empty($expectedCategories)) return null;

        // Check if the expense category matches any expected category
        $catLower = strtolower($category);
        foreach ($expectedCategories as $expected) {
            if (strtolower($expected) === $catLower) {
                return null; // Match found — no mismatch
            }
        }

        // Meals and Office/Admin are always acceptable
        if (in_array($category, ['Meals', 'Office/Admin'])) return null;

        return [
            'mismatch' => true,
            'category' => $category,
            'expected' => $expectedCategories,
            'service_types' => $serviceTypes,
            'message'  => sprintf(
                '"%s" is unusual for a %s job (expected: %s)',
                $category,
                implode('/', $serviceTypes),
                implode(', ', $expectedCategories)
            ),
        ];
    } catch (\Throwable $e) {
        error_log('validateExpenseForJob: ' . $e->getMessage());
        return null;
    }
}
