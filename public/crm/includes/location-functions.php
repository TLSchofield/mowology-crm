<?php
/**
 * Location-Aware Job Creation Helper Functions
 * /crm/includes/location-functions.php
 */

/**
 * Find properties near crew location using Haversine formula
 */
function findNearbyProperties($crewLat, $crewLng, $radiusKm = 1) {
    $db = getDB();

    // Haversine distance formula in SQL
    $stmt = $db->prepare("
        SELECT
            p.id, p.address, p.square_feet, p.access_notes, p.known_issues,
            c.id as company_id, c.company_name, c.company_type,
            (
                6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )
            ) AS distance_km,
            (SELECT COUNT(*) FROM jobs WHERE property_id = p.id AND status = 'completed') as job_count,
            (SELECT scheduled_date FROM jobs WHERE property_id = p.id ORDER BY scheduled_date DESC LIMIT 1) as last_job_date
        FROM properties p
        JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
        JOIN companies c ON cp.company_id = c.id
        WHERE latitude IS NOT NULL
        AND longitude IS NOT NULL
        AND (
            6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )
        ) <= ?
        ORDER BY distance_km ASC
        LIMIT 3
    ");

    $stmt->execute([$crewLat, $crewLng, $crewLat, $crewLat, $crewLng, $crewLat, $radiusKm]);

    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Enrich with service packages used on each property
    foreach ($properties as &$prop) {
        $recentPackage = $db->prepare("
            SELECT sp.id, sp.package_name, sp.base_price
            FROM service_packages sp
            JOIN recent_jobs rj ON sp.id = rj.service_package_id
            WHERE rj.property_id = ?
            ORDER BY rj.last_executed_date DESC
            LIMIT 1
        ")->execute([$prop['id']])->fetch();

        $prop['suggested_package'] = $recentPackage;
        $prop['distance_km'] = round($prop['distance_km'], 3);
    }

    return $properties;
}

/**
 * Reverse geocode coordinates to street address
 */
function reverseGeocodeLocation($lat, $lng) {
    $db = getDB();

    // Check cache first
    $cached = $db->prepare("
        SELECT address FROM geocoding_cache
        WHERE latitude = ? AND longitude = ?
        AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ")->execute([$lat, $lng])->fetch();

    if ($cached) {
        return $cached['address'];
    }

    // Call Google Maps Reverse Geocoding API
    $googleApiKey = getenv('GOOGLE_MAPS_API_KEY');
    if (!$googleApiKey) {
        // Fallback: return coordinates if API key not configured
        return "{$lat}, {$lng}";
    }

    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng={$lat},{$lng}&key={$googleApiKey}";

    try {
        $context = stream_context_create([
            'http' => ['timeout' => 5]
        ]);
        $response = @file_get_contents($url, false, $context);
        $data = json_decode($response, true);

        if ($data['status'] === 'OK' && !empty($data['results'])) {
            $address = $data['results'][0]['formatted_address'];

            // Cache it
            $stmt = $db->prepare("
                INSERT INTO geocoding_cache (address, latitude, longitude, source, expires_at)
                VALUES (?, ?, ?, 'google_maps', DATE_ADD(NOW(), INTERVAL 30 DAYS))
                ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$address, $lat, $lng]);

            return $address;
        }
    } catch (Exception $e) {
        error_log("Geocoding error: " . $e->getMessage());
    }

    // Fallback: return coordinates if API fails
    return "{$lat}, {$lng}";
}

/**
 * Check if a property already exists at this location
 */
function checkPropertyNearby($lat, $lng, $toleranceMeters = 50) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT p.id, c.company_name
        FROM properties p
        JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
        JOIN companies c ON cp.company_id = c.id
        WHERE (
            6371000 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )
        ) <= ?
        LIMIT 1
    ");

    $stmt->execute([$lat, $lng, $lat, $toleranceMeters]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Create property from crew location + reverse geocode
 */
function createPropertyFromLocation($address, $lat, $lng, $clientId, $propertyType, $userId) {
    $db = getDB();

    if (!$clientId) {
        throw new Exception('Client ID required');
    }

    // Check if property already exists nearby
    $existing = checkPropertyNearby($lat, $lng, 50);
    if ($existing) {
        throw new Exception('Property already exists nearby: ' . $existing['company_name']);
    }

    $db->beginTransaction();

    try {
        // Create property
        $stmt = $db->prepare("
            INSERT INTO properties (address, latitude, longitude, property_type, location_verified_by, location_verified_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$address, $lat, $lng, $propertyType, $userId]);

        $propertyId = $db->lastInsertId();

        // Link to company
        $stmt = $db->prepare("
            INSERT INTO company_properties (company_id, property_id, is_primary)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE is_primary = 1
        ");
        $stmt->execute([$clientId, $propertyId]);

        // Cache geocoding result
        $stmt = $db->prepare("
            INSERT INTO geocoding_cache (address, latitude, longitude, source, expires_at)
            VALUES (?, ?, ?, 'manual', DATE_ADD(NOW(), INTERVAL 1 YEAR))
            ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude)
        ");
        $stmt->execute([$address, $lat, $lng]);

        // Log activity
        logActivityExtended($userId, 'Property created from location', "Address: {$address}", $clientId);

        $db->commit();
        return $propertyId;

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Log crew location for audit trail
 */
function logCrewLocation($lat, $lng, $jobId = null) {
    $db = getDB();
    $userId = isset($GLOBALS['user']) ? $GLOBALS['user']['id'] : null;

    if (!$userId) {
        return false;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, job_id)
            VALUES (?, ?, ?, 50, ?)
        ");
        return $stmt->execute([$userId, $lat, $lng, $jobId ?: null]);
    } catch (Exception $e) {
        error_log("Error logging crew location: " . $e->getMessage());
        return false;
    }
}

/**
 * Update property visit patterns (learning for route optimization)
 */
function updatePropertyVisitPattern($propertyId, $crewId, $durationMinutes = null) {
    $db = getDB();

    try {
        $stmt = $db->prepare("
            INSERT INTO property_visit_patterns (property_id, crew_id, last_visit_date, visit_count, avg_visit_duration_minutes)
            VALUES (?, ?, NOW(), 1, ?)
            ON DUPLICATE KEY UPDATE
                last_visit_date = NOW(),
                visit_count = visit_count + 1,
                avg_visit_duration_minutes = IFNULL(?, avg_visit_duration_minutes)
        ");
        return $stmt->execute([$propertyId, $crewId, $durationMinutes, $durationMinutes]);
    } catch (Exception $e) {
        error_log("Error updating visit pattern: " . $e->getMessage());
        return false;
    }
}

/**
 * Get recent jobs for quick repeat from property
 */
function getRecentJobsForProperty($propertyId, $limit = 5) {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT j.id, j.title, sp.package_name, sp.base_price, sp.default_duration_minutes, sp.id as package_id
        FROM jobs j
        LEFT JOIN service_packages sp ON j.service_package_id = sp.id
        WHERE j.property_id = ?
        AND j.status IN ('completed', 'scheduled')
        ORDER BY j.scheduled_date DESC
        LIMIT ?
    ");

    $stmt->execute([$propertyId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create job from location-based data
 */
function createJobFromLocationData($propertyId, $clientId, $packageId, $scheduledDate, $userId) {
    $db = getDB();

    try {
        // Get package defaults
        $pkg = $db->prepare("SELECT * FROM service_packages WHERE id = ?")
            ->execute([$packageId])
            ->fetch(PDO::FETCH_ASSOC);

        if (!$pkg) {
            throw new Exception('Service package not found');
        }

        $jobNumber = generateJobNumber();

        $stmt = $db->prepare("
            INSERT INTO jobs (
                job_number, company_id, property_id, service_package_id,
                billing_template_id, title, description, estimated_amount,
                estimated_duration_minutes, crew_size_required,
                scheduled_date, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
        ");

        $stmt->execute([
            $jobNumber,
            $clientId,
            $propertyId,
            $packageId,
            $pkg['default_billing_template_id'] ?? 1,
            $pkg['package_name'],
            $pkg['description'],
            $pkg['base_price'],
            $pkg['default_duration_minutes'],
            $pkg['default_crew_size'],
            $scheduledDate,
            $userId
        ]);

        $jobId = $db->lastInsertId();

        // Create proof of work config
        $proofStmt = $db->prepare("
            INSERT INTO job_proof_of_work (
                job_id, required_checklist_items, required_photo_types,
                gps_required, checklist_items_that_block_completion, photo_types_that_block_completion
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");

        $proofStmt->execute([
            $jobId,
            $pkg['required_checklist_items'] ?? '[]',
            $pkg['required_photo_types'] ?? '[]',
            $pkg['gps_enforcement'] === 'required' ? 1 : 0,
            $pkg['required_checklist_items'] ?? '[]',
            json_encode(['before', 'after'])
        ]);

        logActivityExtended($userId, 'Job created from location', "Job #{$jobNumber}", $clientId, $jobId);

        return $jobId;

    } catch (Exception $e) {
        error_log("Error creating job from location: " . $e->getMessage());
        throw $e;
    }
}
