# CRM Error Handler - Implementation Checklist

## System Overview

Three files have been created to provide unified error reporting:

1. **error-handler.php** - Core error handler class
2. **ERROR_HANDLER_TEMPLATE.php** - Copy/paste template for new pages
3. **ERROR_HANDLER_GUIDE.md** - Complete documentation
4. **IMPLEMENTATION_CHECKLIST.md** - This file (implementation guide)

## Quick Implementation (5 minutes per page)

### Step 1: Add to Existing CRM Pages

Copy this code block to the top of each CRM page (after auth checks):

```php
// Add this after requireLogin() and getCurrentUser()
require_once __DIR__ . '/../includes/error-handler.php';

$errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;
```

### Step 2: Wrap Database Operations

**Before:**
```php
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
```

**After:**
```php
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError(
        $e,
        'SELECT * FROM users WHERE id = ?',
        [$id],
        'Unable to load user data.'
    );
    // Handle error (redirect, return, etc.)
}
```

### Step 3: Add Session Alert Display

Add this to your page template (inside appstack_head.php include):

```php
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
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
```

### Step 4: Validate Input

**Before:**
```php
$name = $_POST['name'] ?? '';
```

**After:**
```php
if (!$errorHandler->validateRequiredParams(['name', 'email'], $_POST)) {
    header('Location: index.php?error=validation');
    exit;
}
$name = $_POST['name'];
```

## Pages to Update (Priority Order)

### High Priority (Used Frequently)
- [ ] dashboard_appstack.php
- [ ] quotes_appstack.php
- [ ] jobs_appstack.php
- [ ] clients_appstack.php
- [ ] map_appstack.php
- [ ] settings.php

### Medium Priority (Feature Modules)
- [ ] quotes/index.php
- [ ] quotes/create.php
- [ ] quotes/view.php
- [ ] jobs/index.php
- [ ] jobs/jobs_create_location_appstack.php
- [ ] invoices/index.php
- [ ] invoices/create.php
- [ ] invoices/view.php
- [ ] products/index.php
- [ ] products/products-manager.php

### Lower Priority (API Endpoints & Utilities)
- [ ] quotes/api-*.php
- [ ] invoices/api-*.php
- [ ] products/api-*.php
- [ ] gsc/sync-cron.php (already has logging)
- [ ] portfolio/index.php (already has logging)

## Template Files to Copy

### For Root-Level Pages (*.php)
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/../includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

$errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// ... rest of page
```

### For Subdirectory Pages (quotes/index.php)
```php
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/../includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

$errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;

// ... rest of page
```

## Common Patterns

### Pattern 1: Simple Database Query
```php
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM table WHERE id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Failed to load data.');
    header('Location: index.php?error=db');
    exit;
}
```

### Pattern 2: Insert with Validation
```php
if (!$errorHandler->validateRequiredParams(['name', 'email'])) {
    header('Location: form.php?error=validation');
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO table (name, email) VALUES (?, ?)");
    $stmt->execute([$_POST['name'], $_POST['email']]);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Failed to save. Email may already exist.');
    header('Location: form.php?error=save');
    exit;
}

$errorHandler->displayAlert('success', 'Record created successfully');
```

### Pattern 3: AJAX Endpoint
```php
<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/../includes/error-handler.php';

$errorHandler = new CRMErrorHandler('API Endpoint', 'POST');
$GLOBALS['crm_error_handler'] = $errorHandler;

header('Content-Type: application/json');

// Validate input
if (!$errorHandler->validateRequiredParams(['id', 'action'])) {
    exit; // JSON already sent by validator
}

// Process
try {
    // Your code here
    echo json_encode(['success' => true, 'message' => 'Done']);
} catch (Exception $e) {
    $errorHandler->logError('API failed', $e);
    echo json_encode(['success' => false, 'message' => 'Operation failed']);
}
```

### Pattern 4: Complex Operation
```php
$result = $errorHandler->tryOperation(
    function () use ($db, $data) {
        // Complex logic here
        $stmt = $db->prepare("UPDATE table SET status = ? WHERE id = ?");
        $stmt->execute(['completed', $data['id']]);
        return true;
    },
    'Failed to complete operation',
    ['table' => 'table', 'id' => $data['id'] ?? null]
);

if ($result === null) {
    header('Location: index.php?error=operation');
    exit;
}
```

## Key Features

✅ **Automatic Context Collection**
- Page name
- Request method (GET/POST)
- Session ID
- User IP address
- Current user info

✅ **Unique Error IDs**
- Every error gets unique ID like `ERR-20260208120530-a1b2c3d4`
- Can be referenced in support tickets

✅ **User-Friendly Messages**
- Technical details hidden from users
- Database errors summarized safely
- No stack traces in alerts

✅ **Development Support**
- Full stack traces in server logs
- Query context for SQL errors
- Additional context data can be passed

✅ **AJAX Support**
- Automatic JSON responses
- Consistent error format across API endpoints

✅ **Session Tracking**
- Recent errors stored in session (last 20)
- Useful for debugging

## Testing Implementation

### Test 1: Database Error
```php
// Intentional error to verify logging
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM nonexistent_table");
    $stmt->execute();
} catch (PDOException $e) {
    $errorHandler->logDatabaseError($e, '', [], 'Test error');
}

// Check: Server log should contain error with full context
```

### Test 2: Validation Error
```php
// Missing required field
$_POST = [];
$errorHandler->validateRequiredParams(['name'], $_POST);

// Check: Alert should be in session
```

### Test 3: AJAX Error
```javascript
// Test AJAX error handling
fetch('/crm/api/test.php', {
    method: 'POST',
    body: new FormData()
})
.then(r => r.json())
.then(data => console.log(data));

// Check: Should return JSON with error
```

## Deployment Checklist

- [ ] Read ERROR_HANDLER_GUIDE.md
- [ ] Understand CRMErrorHandler class structure
- [ ] Copy error-handler.php template to each page
- [ ] Add session alert display to templates
- [ ] Test database error logging
- [ ] Test validation error handling
- [ ] Test AJAX error responses
- [ ] Monitor server logs for new error format
- [ ] Test with real user scenarios
- [ ] Update documentation if needed

## Support & Debugging

### Find specific error by ID:
```bash
grep "ERR-20260208120530" /home/mowology/logs/error_log
```

### Find errors on specific page:
```bash
grep "Page: Dashboard" /home/mowology/logs/error_log
```

### Find errors from specific user:
```bash
grep "admin@example.com" /home/mowology/logs/error_log
```

### Recent errors:
```bash
tail -100 /home/mowology/logs/error_log | grep "ERROR ID"
```

## Questions & Help

For questions about implementation:
1. See ERROR_HANDLER_GUIDE.md for complete API reference
2. See ERROR_HANDLER_TEMPLATE.php for copy/paste examples
3. Check IMPLEMENTATION_CHECKLIST.md (this file) for patterns
4. Review server logs to understand error context

---

**Created:** February 2026
**Version:** 1.0
**Status:** Ready for Implementation
