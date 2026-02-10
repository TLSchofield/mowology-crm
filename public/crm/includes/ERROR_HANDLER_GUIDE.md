# CRM Error Reporting System

## Overview

The unified error reporting system provides standardized, comprehensive error handling across all CRM pages. It handles:

- **Server-side logging** with full context (page, user, session, IP, request method)
- **User alerts** without exposing technical details
- **AJAX error responses** as JSON with appropriate status codes
- **Database errors** with query context
- **Session tracking** of recent errors for debugging
- **Automatic error ID generation** for tracking and support

## Quick Start

### 1. Initialize in Your Page

At the top of any CRM page (after auth checks):

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/../includes/error-handler.php';

requireLogin();
$user = getCurrentUser();

// Initialize error handler
$errorHandler = new CRMErrorHandler('Dashboard', $_SERVER['REQUEST_METHOD']);
$GLOBALS['crm_error_handler'] = $errorHandler;
```

### 2. Use in Your Code

#### Database Operations
```php
try {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET name = ? WHERE id = ?");
    $stmt->execute([$name, $userId]);
} catch (PDOException $e) {
    $errorHandler->logDatabaseError(
        $e,
        'UPDATE users SET name = ? WHERE id = ?',
        [$name, $userId],
        'Failed to update user. Please try again.'
    );
    header('Location: index.php?error=1');
    exit;
}
```

#### Validate Required Parameters
```php
if (!$errorHandler->validateRequiredParams(['user_id', 'email'], $_POST)) {
    // Error logged and alert queued automatically
    header('Location: index.php?error=validation');
    exit;
}
```

#### Validate CSRF Token
```php
if (!$errorHandler->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    // Error logged automatically
    header('Location: index.php?error=csrf');
    exit;
}
```

#### Try/Catch with Context
```php
$result = $errorHandler->tryOperation(
    function () {
        // Your operation here
        return complexCalculation($data);
    },
    'Calculation failed',
    ['data_id' => $data['id']]
);

if ($result === null) {
    // Operation failed - error already logged
    header('Location: index.php?error=processing');
    exit;
}
```

#### Quick Error Logging
```php
try {
    // Some operation
} catch (Exception $e) {
    logCrmError('Operation failed', $e, ['context' => 'value']);
}
```

#### Display Alert
```php
$errorHandler->displayAlert('error', 'Something went wrong');
// For AJAX: Returns JSON
// For HTML: Stores in session for display
```

## Error Logging Details

Each logged error includes:

```
[ERROR ID: ERR-20260208120530-a1b2c3d4]
Operation failed |
Page: Dashboard |
Method: POST |
Session: abc123def456 |
User IP: 192.168.1.100 |
User: admin@example.com (ID: 1)

Exception: PDOException |
Code: 23000 |
File: /path/to/database.php:123
Stack: [full stack trace]

Context: {
  "operation": "update_user",
  "user_id": 42
}
```

### Error ID Format

```
ERR-YYYYMMDDHHMSS-XXXXXXXX
│   │               │
│   │               └─ Random 8-char hex
│   └─────────────────── Timestamp
└─────────────────────── Error prefix
```

Error IDs can be given to users for support reference.

## Alert Display in HTML

Alerts automatically display in your page template:

```html
<!-- Session alert displays once, then clears -->
<?php
if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $class = [
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'success' => 'alert-success',
        'info' => 'alert-info'
    ][$alert['type']] ?? 'alert-info';
?>
    <div class="alert <?php echo $class; ?> alert-dismissible fade show">
        <strong><?php echo ucfirst($alert['type']); ?>:</strong>
        <?php echo h($alert['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']); ?>
<?php endif; ?>
```

## AJAX Error Responses

For AJAX requests, errors return JSON:

```json
{
    "success": false,
    "type": "error",
    "message": "Failed to save. Please try again."
}
```

In your JavaScript:

```javascript
fetch('/crm/api/endpoint.php', {
    method: 'POST',
    body: formData
})
.then(r => r.json())
.then(data => {
    if (data.success) {
        alert('✓ ' + data.message);
    } else {
        // User-friendly message
        alert('Error: ' + data.message);
        // Full details in console
        if (data.details) console.error('Details:', data.details);
    }
})
.catch(err => alert('Network error: ' + err.message));
```

## Database Error Example

```php
try {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO users (email, name)
        VALUES (?, ?)
    ");
    $stmt->execute([$email, $name]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        // Duplicate email
        $userMessage = 'Email already registered. Please use another email or log in.';
    } else {
        $userMessage = 'Failed to create account. Please try again later.';
    }

    $errorHandler->logDatabaseError(
        $e,
        'INSERT INTO users (email, name) VALUES (?, ?)',
        [$email, $name],
        $userMessage
    );

    $errorHandler->displayAlert('error', $userMessage);
    exit;
}
```

## Accessing Error History

View recent errors (if needed for debugging):

```php
// In session after errors logged
$recentErrors = $_SESSION['crm_errors'] ?? [];

foreach ($recentErrors as $error) {
    echo "Error {$error['id']}: {$error['message']} at {$error['timestamp']}\n";
}
```

## Server-Side Log Location

Errors are logged to PHP's configured error log:

```bash
# Typically on cPanel:
tail -f /home/mowology/logs/error_log | grep "ERR-"

# View errors for a specific page:
tail -f /home/mowology/logs/error_log | grep "Dashboard"

# View errors for a specific user:
tail -f /home/mowology/logs/error_log | grep "admin@example.com"
```

## Guidelines for CRM Pages

1. **Always initialize** the error handler at the top
2. **Validate input** before processing (required params, CSRF tokens)
3. **Wrap database operations** in try/catch with specific error messages
4. **Log context** relevant to the operation (IDs, parameters, etc.)
5. **Display session alerts** in your template
6. **Use descriptive messages** - the user sees these, so be helpful
7. **Include error IDs** in alerts for support reference
8. **Never expose** technical details to users

## Error Handler API Reference

### Constructor
```php
$errorHandler = new CRMErrorHandler(
    $pageName = 'Unknown',    // Name of page/endpoint
    $requestMethod = 'GET'    // GET, POST, etc.
);
```

### Methods

#### logError()
```php
$errorHandler->logError(
    $message,              // User-friendly message
    $exception = null,     // Exception object (optional)
    $context = []          // Additional context array (optional)
);
```

#### logDatabaseError()
```php
$errorHandler->logDatabaseError(
    $exception,            // PDOException
    $query = '',          // SQL query (optional)
    $params = [],         // Query parameters (optional)
    $userMessage = ''     // Message for user (optional)
);
```

#### displayAlert()
```php
$errorHandler->displayAlert(
    $type = 'error',      // 'error', 'warning', 'success', 'info'
    $message = '',        // Alert message
    $details = []         // Additional details (optional)
);
```

#### validateRequiredParams()
```php
if (!$errorHandler->validateRequiredParams(
    ['param1', 'param2'], // Required parameter names
    $_POST                // Source array (optional, defaults to POST/GET)
)) {
    // Handle validation failure
}
```

#### validateCsrfToken()
```php
if (!$errorHandler->validateCsrfToken($_POST['csrf_token'] ?? '')) {
    // Handle CSRF failure
}
```

#### tryOperation()
```php
$result = $errorHandler->tryOperation(
    $callable,            // Function to execute
    $errorMessage = '',   // Message on failure
    $context = []         // Context array
);
```

#### getErrors()
```php
$errorIds = $errorHandler->getErrors(); // Array of error IDs
```

#### hasErrors()
```php
if ($errorHandler->hasErrors()) {
    // One or more errors occurred
}
```

## Global Helper Functions

After initializing error handler, you can use global functions:

```php
// Log error
logCrmError('Something went wrong', $exception, ['context' => 'data']);

// Display alert
showCrmAlert('error', 'Operation failed');
```

## Security Considerations

- Error IDs don't expose technical details
- Stack traces only sent to server logs, never to browser
- User IPs and session IDs logged server-side only
- Validation happens before database operations
- CSRF tokens validated automatically
- User-friendly messages prevent information leakage

---

**Last Updated:** February 2026
**Status:** Production Ready
