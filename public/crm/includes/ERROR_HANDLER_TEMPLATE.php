<?php
/**
 * ERROR HANDLER TEMPLATE
 *
 * Copy this template into any CRM page to add comprehensive error reporting.
 * Follow the pattern below for consistent error handling across all CRM pages.
 */

// ============================================================
// STEP 1: Include error handler at top of page (after auth)
// ============================================================

declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/../includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

$pageTitle = 'Your Page';
$activePage = 'nav-key';

// ============================================================
// STEP 2: Wrap operations in error handling
// ============================================================

// Example 1: Database operations
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM table WHERE id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError(
        $e,
        'SELECT * FROM table WHERE id = ?',
        [$id],
        'Unable to load data. Please try again.'
    );
    header('Location: index.php?error=1');
    exit;
}

// Example 2: Validate CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!$errorHandler->validateCsrfToken($csrfToken)) {
        header('Location: index.php?error=csrf');
        exit;
    }
}

// Example 3: Validate required parameters
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$errorHandler->validateRequiredParams(['name', 'email'], $_POST)) {
        // Alert already displayed by validator
        header('Location: index.php?error=validation');
        exit;
    }
}

// Example 4: Try/catch pattern
try {
    $result = $errorHandler->tryOperation(
        function () use ($db, $data) {
            // Your operation here
            return someFunction($data);
        },
        'Failed to process request',
        ['data_id' => $data['id'] ?? null]
    );

    if ($result === null) {
        // Error was logged, show user-friendly message
        header('Location: index.php?error=processing');
        exit;
    }
} catch (Exception $e) {
    $errorHandler->logError('Unexpected error', $e);
    header('Location: index.php?error=unexpected');
    exit;
}

// Example 5: Quick error logging
try {
    // Some operation
} catch (Exception $e) {
    logCrmError('Operation failed', $e, ['additional' => 'context']);
}

// ============================================================
// STEP 3: Display alerts in session
// ============================================================

?>
<?php include 'includes/appstack_head.php'; ?>

<!-- Display session alert if present -->
<?php
if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $alertClass = [
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'success' => 'alert-success',
        'info' => 'alert-info'
    ][$alert['type']] ?? 'alert-info';
?>
    <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
        <strong><?php echo ucfirst($alert['type']); ?>:</strong> <?php echo h($alert['message']); ?>
        <?php if (!empty($alert['details'])): ?>
            <div class="mt-2 small">
                <?php foreach ($alert['details'] as $key => $value): ?>
                    <div><code><?php echo h($key); ?>: <?php echo h($value); ?></code></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>

<!-- Your page content here -->

<?php include 'includes/appstack_footer.php'; ?>

<!-- ============================================================
     AJAX ERROR REPORTING
     ============================================================ -->
<script>
// For AJAX requests that use the error handler JSON responses
fetch('/crm/api/endpoint.php', {
    method: 'POST',
    body: formData
})
.then(r => r.json())
.then(data => {
    if (data.success) {
        alert('✓ Success!\n\n' + (data.message || ''));
    } else {
        // Error was logged server-side with full context
        alert('Error: ' + data.message);
        if (data.details) {
            console.warn('Error details:', data.details);
        }
    }
})
.catch(err => {
    // Network error
    console.error('Request failed:', err);
    alert('Network error: ' + err.message);
});
</script>
