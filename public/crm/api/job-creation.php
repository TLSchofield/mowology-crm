<?php
/**
 * Next-Gen Job Creation API
 * Endpoints for job creation workflow
 *
 * All endpoints require:
 * - Admin authentication
 * - CSRF token validation (POST requests)
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// Require admin
requireLogin();
$user = getCurrentUser();

// Check admin role
if ($user['role'] !== 'admin' && $user['role'] !== 'manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied', 'success' => false]);
    exit;
}

// Set JSON response header
header('Content-Type: application/json');

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($action) {
        /**
         * GET list of all service packages
         * Optional params: category (mowing, trimming, cleanup, seasonal)
         */
        case 'get_service_packages':
            $category = $_GET['category'] ?? $_POST['category'] ?? null;
            $packages = getServicePackages($category, true);
            echo json_encode([
                'success' => true,
                'packages' => $packages,
                'count' => count($packages)
            ]);
            break;

        /**
         * GET single service package details
         * Required: package_id
         */
        case 'get_package_details':
            $packageId = intval($_GET['package_id'] ?? $_POST['package_id'] ?? 0);
            if (!$packageId) {
                http_response_code(400);
                echo json_encode(['error' => 'package_id required', 'success' => false]);
                exit;
            }

            $package = getServicePackageDetails($packageId);
            if (!$package) {
                http_response_code(404);
                echo json_encode(['error' => 'Package not found', 'success' => false]);
                exit;
            }

            // Decode JSON fields
            $package['checklist_items'] = json_decode($package['checklist_items'], true) ?? [];
            $package['photo_types_required'] = json_decode($package['photo_types_required'], true) ?? [];
            $package['modifiers'] = json_decode($package['modifiers'], true) ?? [];

            echo json_encode([
                'success' => true,
                'package' => $package
            ]);
            break;

        /**
         * POST suggest crew for job
         * Required: property_id, scheduled_date, duration_minutes, crew_size_required
         */
        case 'suggest_crew':
            $propertyId = intval($_POST['property_id'] ?? 0);
            $scheduledDate = $_POST['scheduled_date'] ?? null;
            $durationMinutes = intval($_POST['duration_minutes'] ?? 45);
            $crewSizeRequired = intval($_POST['crew_size_required'] ?? 1);

            if (!$propertyId || !$scheduledDate) {
                http_response_code(400);
                echo json_encode(['error' => 'property_id and scheduled_date required', 'success' => false]);
                exit;
            }

            $suggestion = suggestCrewForJob($propertyId, $scheduledDate, $durationMinutes, $crewSizeRequired);

            echo json_encode([
                'success' => true,
                'suggestion' => $suggestion
            ]);
            break;

        /**
         * POST check crew availability
         * Required: crew_id, start_time, duration_minutes
         */
        case 'check_availability':
            $crewId = intval($_POST['crew_id'] ?? 0);
            $startTime = $_POST['start_time'] ?? null;
            $durationMinutes = intval($_POST['duration_minutes'] ?? 45);

            if (!$crewId || !$startTime) {
                http_response_code(400);
                echo json_encode(['error' => 'crew_id and start_time required', 'success' => false]);
                exit;
            }

            $available = checkCrewAvailability($crewId, $startTime, $durationMinutes);
            $conflicts = $available ? [] : detectSchedulingConflicts($crewId, $startTime, $durationMinutes);

            echo json_encode([
                'success' => true,
                'available' => $available,
                'conflicts' => $conflicts
            ]);
            break;

        /**
         * POST calculate optimal time window
         * Required: property_id, crew_id, duration_minutes
         */
        case 'calculate_time_window':
            $propertyId = intval($_POST['property_id'] ?? 0);
            $crewId = intval($_POST['crew_id'] ?? 0);
            $durationMinutes = intval($_POST['duration_minutes'] ?? 45);

            if (!$propertyId || !$crewId) {
                http_response_code(400);
                echo json_encode(['error' => 'property_id and crew_id required', 'success' => false]);
                exit;
            }

            $timeWindow = calculateOptimalTimeWindow($propertyId, $crewId, $durationMinutes);

            echo json_encode([
                'success' => true,
                'time_window' => $timeWindow
            ]);
            break;

        /**
         * POST validate job data against guardrails
         * Required: job_data (full object)
         */
        case 'validate_job':
            $jobData = $_POST;
            unset($jobData['action']); // Remove action from data

            $validation = validateJobCreationGuardrails($jobData, $user['id']);

            echo json_encode([
                'success' => true,
                'is_valid' => $validation['is_valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'suggestions' => $validation['suggestions']
            ]);
            break;

        /**
         * POST create job with defaults
         * Required: client_id, property_id, service_package_id, scheduled_date
         * Optional: job_type (one_time|recurring), assigned_to, title, description, ...
         */
        case 'create_job':
            // CSRF validation required
            if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
                http_response_code(403);
                echo json_encode(['error' => 'CSRF token invalid', 'success' => false]);
                exit;
            }

            $jobData = $_POST;
            unset($jobData['action']);
            unset($jobData['csrf_token']);

            // Determine if recurring
            if ($jobData['job_type'] === 'recurring') {
                $result = createRecurringJobSeries($jobData, $user['id']);
            } else {
                $result = createJobWithDefaults($jobData, $user['id']);
            }

            if ($result['success']) {
                http_response_code(201);
            } else {
                http_response_code(400);
            }

            echo json_encode($result);
            break;

        /**
         * GET recent jobs on property
         * Required: property_id
         */
        case 'recent_jobs':
            $propertyId = intval($_GET['property_id'] ?? $_POST['property_id'] ?? 0);

            if (!$propertyId) {
                http_response_code(400);
                echo json_encode(['error' => 'property_id required', 'success' => false]);
                exit;
            }

            $recentJobs = getRecentJobsOnProperty($propertyId, 3);

            echo json_encode([
                'success' => true,
                'recent_jobs' => $recentJobs
            ]);
            break;

        /**
         * POST suggest modifiers
         * Required: property_id, service_package_id
         */
        case 'suggest_modifiers':
            $propertyId = intval($_POST['property_id'] ?? 0);
            $servicePackageId = intval($_POST['service_package_id'] ?? 0);

            if (!$propertyId || !$servicePackageId) {
                http_response_code(400);
                echo json_encode(['error' => 'property_id and service_package_id required', 'success' => false]);
                exit;
            }

            $modifiers = suggestModifiers($propertyId, $servicePackageId);

            echo json_encode([
                'success' => true,
                'modifiers' => $modifiers
            ]);
            break;

        /**
         * POST check job invoice eligibility
         * Required: job_id
         */
        case 'can_invoice_job':
            $jobId = intval($_POST['job_id'] ?? 0);

            if (!$jobId) {
                http_response_code(400);
                echo json_encode(['error' => 'job_id required', 'success' => false]);
                exit;
            }

            $result = canInvoiceJob($jobId);

            echo json_encode([
                'success' => true,
                'can_invoice' => $result['can_invoice'],
                'missing_requirements' => $result['missing_requirements'],
                'photos_count' => $result['photos_count']
            ]);
            break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action), 'success' => false]);
    }

} catch (Exception $e) {
    error_log("Job creation API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'success' => false
    ]);
}
?>
