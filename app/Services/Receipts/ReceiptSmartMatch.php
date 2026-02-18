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

// Keyword-to-category mapping for fallback when no vendor match
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
        'recycling', 'tipping fee',
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
 * @return array Suggestion with confidence scores
 */
function suggestReceiptMeta(?string $ocrText, ?float $lat, ?float $lng, ?int $jobId = null): array
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
            if (strlen($cleanPhone) >= 7 && strpos($ocrLower, $cleanPhone) !== false) {
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
function suggestJobFromSchedule(int $userId, ?float $lat, ?float $lng): array
{
    try {
        // Load plan functions for getCalendarStops()
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $now = time();

        // Get all stops for today + tomorrow (no crew filter — we score crew ourselves)
        $calendarData = getCalendarStops($today, $tomorrow);

        $db = getDB();
        $scored = [];

        foreach ($calendarData as $date => $stops) {
            foreach ($stops as $stopId => $stop) {
                $score = 0;
                $reasons = [];

                // GPS proximity scoring
                if ($lat !== null && $lng !== null &&
                    !empty($stop['latitude']) && !empty($stop['longitude'])) {
                    $distance = haversineDistance($lat, $lng, (float)$stop['latitude'], (float)$stop['longitude']);
                    if ($distance <= 0.2) {
                        $score += 50;
                        $reasons[] = 'GPS within 200m';
                    } elseif ($distance <= 1.0) {
                        $score += 30;
                        $reasons[] = 'GPS within 1km';
                    } elseif ($distance <= 5.0) {
                        $score += 10;
                        $reasons[] = 'GPS within 5km';
                    }
                }

                // Crew match: check if current user is assigned to this stop
                $crewIds = $stop['crew_ids'] ?? [];
                if (in_array($userId, $crewIds) || ($stop['crew_id'] ?? 0) == $userId) {
                    $score += 20;
                    $reasons[] = 'Crew assigned';
                }

                // Time proximity: compare receipt upload time to stop's estimated arrival
                if (!empty($stop['estimated_arrival']) && $date === $today) {
                    $arrivalTime = strtotime($date . ' ' . $stop['estimated_arrival']);
                    if ($arrivalTime) {
                        $timeDiff = abs($now - $arrivalTime);
                        if ($timeDiff <= 3600) {
                            $score += 20;
                            $reasons[] = 'Within 1hr of arrival';
                        } elseif ($timeDiff <= 10800) {
                            $score += 10;
                            $reasons[] = 'Within 3hr of arrival';
                        }
                    }
                }

                // Status boost: in_progress stop is likely where user currently is
                if (($stop['status'] ?? '') === 'in_progress') {
                    $score += 15;
                    $reasons[] = 'Stop in progress';
                }

                // Only include if some signal exists
                if ($score > 0) {
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
                        'stop_id'          => (int)$stopId,
                        'property_id'      => (int)($stop['property_id'] ?? 0),
                        'property_address'  => $stop['property_address'] ?? '',
                        'property_city'     => $stop['property_city'] ?? '',
                        'contact_name'      => $stop['contact_name'] ?? '',
                        'contact_id'        => $contactId ? (int)$contactId : null,
                        'company_name'      => $stop['company_name'] ?? '',
                        'plan_id'           => (int)($firstVisit['plan_id'] ?? 0),
                        'plan_title'        => $firstVisit['plan_title'] ?? '',
                        'service_type'      => $firstVisit['service_type'] ?? '',
                        'visit_id'          => (int)($firstVisit['visit_id'] ?? 0),
                        'stop_date'         => $date,
                        'score'             => $score,
                        'match_reasons'     => $reasons,
                    ];
                }
            }
        }

        // Sort by score descending, return top 3
        usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });
        return array_slice($scored, 0, 3);

    } catch (Throwable $e) {
        error_log('suggestJobFromSchedule error: ' . $e->getMessage());
        return [];
    }
}
