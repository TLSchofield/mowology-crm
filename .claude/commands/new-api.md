Generate a new CRM API endpoint for: $ARGUMENTS

Parse $ARGUMENTS as: "endpoint-name [methods] [description]"
- endpoint-name: kebab-case (e.g. invoice-send → invoice-send.php in /crm/api/)
- methods: GET, POST, or both (default: POST)
- description: what this endpoint does

Output the complete PHP file at `public/crm/api/ENDPOINT-NAME.php`:

```php
<?php
/**
 * DESCRIPTION
 * METHOD /crm/api/ENDPOINT-NAME.php
 *
 * Auth: CRM session (requireLogin)
 * Accepts: { action: string, ... }
 * Returns: { success: bool, ... } or { error: string }
 */
declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
session_write_close(); // Release session lock before DB work

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF check
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$db     = getDB();
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'example_action':
            // Validate input
            $id = (int) ($input['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('Invalid id');

            // Business logic here
            // $result = SomeService::doSomething($id, $db);

            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    writeSystemLog('error', 'ENDPOINT-NAME', $e->getMessage(), [
        'action' => $action,
        'user_id' => $user['id'] ?? null,
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Server error — please try again']);
}
```

Rules:
- Always call `session_write_close()` after getCurrentUser() and before heavy DB work
- Always verify CSRF token on POST requests
- Use `writeSystemLog()` for errors (not error_log)
- Catch `Throwable` not `Exception` — require failures throw Error
- Use `(int)`, `trim()`, `filter_var()` to sanitize all input before DB
- All DB queries must use prepared statements with `?` placeholders
- Return `{'success': true, ...}` on success, `{'error': 'message'}` on failure
- Never expose stack traces or DB errors to the response

Also note: if this endpoint needs business logic beyond simple CRUD, create a service class first at `app/Modules/[Module]/Services/[Name]Service.php` and call it from here.
