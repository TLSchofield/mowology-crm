<?php
/**
 * /crm/jobs/jobs_create_location_appstack.php
 * Mobile-friendly job creation with GPS awareness
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/location-functions.php';

requireLogin();
$user = getCurrentUser();
$db = getDB();

$pageTitle = 'Create Job - Location Aware';
$activePage = 'jobs';
$csrfToken = generateCSRFToken();
$extraHead = <<<'EOF'
  <meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">
  <link href="/crm/jobs/location-job-creation.css" rel="stylesheet">
EOF;

// ─── AJAX: Find nearby properties ───
if ($_GET['action'] === 'find_nearby_properties' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = json_decode(file_get_contents('php://input'), true);

    $latitude = floatval($jsonData['latitude'] ?? 0);
    $longitude = floatval($jsonData['longitude'] ?? 0);
    $radiusKm = floatval($jsonData['radius_km'] ?? 1);

    if (!$latitude || !$longitude) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
        exit;
    }

    $properties = findNearbyProperties($latitude, $longitude, $radiusKm);

    echo json_encode([
        'success' => true,
        'properties' => $properties,
        'crew_location' => ['lat' => $latitude, 'lng' => $longitude]
    ]);
    exit;
}

// ─── AJAX: Reverse geocode location ───
if ($_GET['action'] === 'reverse_geocode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = json_decode(file_get_contents('php://input'), true);

    $latitude = floatval($jsonData['latitude'] ?? 0);
    $longitude = floatval($jsonData['longitude'] ?? 0);

    if (!$latitude || !$longitude) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
        exit;
    }

    $address = reverseGeocodeLocation($latitude, $longitude);

    echo json_encode([
        'success' => true,
        'address' => $address,
        'coordinates' => ['lat' => $latitude, 'lng' => $longitude]
    ]);
    exit;
}

// ─── AJAX: Create new property from location ───
if ($_GET['action'] === 'create_property_from_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    $address = trim($_POST['address'] ?? '');
    $clientId = intval($_POST['client_id'] ?? 0);
    $propertyType = $_POST['property_type'] ?? 'residential';

    try {
        $propertyId = createPropertyFromLocation(
            $address,
            $latitude,
            $longitude,
            $clientId,
            $propertyType,
            $user['id']
        );

        if ($propertyId) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'property_id' => $propertyId,
                'message' => 'Property created. Ready to create job.'
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to create property']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── AJAX: Get property details for quick job creation ───
if ($_GET['action'] === 'get_property_summary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $propertyId = intval($jsonData['property_id'] ?? 0);

    if (!$propertyId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid property']);
        exit;
    }

    $property = $db->prepare("
        SELECT p.*, c.company_name, c.id as company_id, c.company_type
        FROM properties p
        JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
        JOIN companies c ON cp.company_id = c.id
        WHERE p.id = ?
    ")->execute([$propertyId])->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Property not found']);
        exit;
    }

    // Get recent jobs on this property
    $recentJobs = $db->prepare("
        SELECT j.id, j.title, sp.package_name, sp.base_price, sp.default_duration_minutes, sp.id as package_id
        FROM jobs j
        LEFT JOIN service_packages sp ON j.service_package_id = sp.id
        WHERE j.property_id = ?
        AND j.status IN ('completed', 'scheduled')
        ORDER BY j.scheduled_date DESC
        LIMIT 5
    ")->execute([$propertyId])->fetchAll(PDO::FETCH_ASSOC);

    // Get available service packages
    $packages = $db->query("
        SELECT id, package_name, icon, base_price, default_duration_minutes, category
        FROM service_packages
        WHERE is_active = 1
        ORDER BY sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'property' => $property,
        'recent_jobs' => $recentJobs,
        'available_packages' => $packages
    ]);
    exit;
}

// ─── AJAX: Search clients ───
if ($_GET['action'] === 'search_clients' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = json_decode(file_get_contents('php://input'), true);
    $query = trim($jsonData['query'] ?? '');

    if (strlen($query) < 2) {
        echo json_encode(['clients' => []]);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, company_name, company_type
        FROM companies
        WHERE company_name LIKE ?
        AND is_active = 1
        ORDER BY company_name ASC
        LIMIT 10
    ");
    $stmt->execute(['%' . $query . '%']);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['clients' => $clients]);
    exit;
}

// ─── AJAX: Log crew location for history ───
if ($_GET['action'] === 'log_crew_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = json_decode(file_get_contents('php://input'), true);

    $latitude = floatval($jsonData['latitude'] ?? 0);
    $longitude = floatval($jsonData['longitude'] ?? 0);
    $accuracy = intval($jsonData['accuracy_meters'] ?? 0);
    $jobId = intval($jsonData['job_id'] ?? 0);

    try {
        $stmt = $db->prepare("
            INSERT INTO crew_location_history (crew_id, latitude, longitude, accuracy_meters, job_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $latitude, $longitude, $accuracy, $jobId ?: null]);

        http_response_code(200);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── POST: Create job from location ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    try {
        $jobData = [
            'company_id' => intval($_POST['client_id']),
            'property_id' => intval($_POST['property_id']),
            'service_package_id' => intval($_POST['service_package_id']),
            'billing_template_id' => intval($_POST['billing_template_id'] ?? 1),
            'scheduled_date' => $_POST['scheduled_date'],
            'created_by' => $user['id']
        ];

        $jobId = createJobWithDefaults($jobData, []);

        if ($jobId) {
            // Log location
            logCrewLocation(
                floatval($_POST['latitude'] ?? 0),
                floatval($_POST['longitude'] ?? 0),
                $jobId
            );

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'job_id' => $jobId,
                'message' => 'Job created successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Failed to create job']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── INCLUDE APPSTACK HEAD ───
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Create Job</h1>
            <a href="jobs_appstack.php" class="btn btn-secondary">
              <i data-feather="arrow-left"></i> Back to Jobs
            </a>
          </div>

          <div class="card">
            <div class="card-body">
              <div id="job-creation-app"></div>
            </div>
          </div>

          <script src="https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js"></script>
          <script src="/crm/jobs/location-job-creation.js"></script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
