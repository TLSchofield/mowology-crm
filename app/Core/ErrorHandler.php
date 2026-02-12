<?php
/**
 * /app/Core/ErrorHandler.php
 * Unified Error Reporting System for CRM Pages
 *
 * Migrated from /public/crm/includes/error-handler.php
 *
 * Provides:
 * - Standardized error logging to server logs with page context
 * - User-friendly error alerts without exposing technical details
 * - Error tracking for AJAX requests with JSON responses
 * - Database error capture and reporting
 * - Graceful degradation on errors
 *
 * Usage:
 *   require_once __DIR__ . '/error-handler.php';
 *   $errorHandler = new CRMErrorHandler('Page Name', $_SERVER['REQUEST_METHOD']);
 *
 *   try {
 *       // your code
 *   } catch (Exception $e) {
 *       $errorHandler->logError('Operation failed', $e);
 *       $errorHandler->displayAlert('error', 'Something went wrong. Please try again.');
 *   }
 */

declare(strict_types=1);

class CRMErrorHandler
{
    private string $pageName;
    private string $requestMethod;
    private bool $isAjax;
    private array $errors = [];
    private string $sessionId;

    /**
     * Initialize error handler
     * @param string $pageName - Name of the page/endpoint
     * @param string $requestMethod - GET, POST, etc.
     */
    public function __construct(string $pageName = 'Unknown', string $requestMethod = 'GET')
    {
        $this->pageName = $pageName;
        $this->requestMethod = $requestMethod;
        $this->isAjax = $this->detectAjax();
        $this->sessionId = session_id() ?: 'no-session';

        // Set up global error handler
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }

    /**
     * Detect if request is AJAX
     */
    private function detectAjax(): bool
    {
        return (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) || (isset($_GET['is_ajax']) || isset($_POST['is_ajax']));
    }

    /**
     * Log error with full context
     * @param string $message - User-friendly message
     * @param Exception|Throwable $exception - The exception
     * @param array $context - Additional context data
     */
    public function logError(
        string $message,
        $exception = null,
        array $context = []
    ): void {
        $errorId = $this->generateErrorId();

        // Build detailed error log
        $logMessage = sprintf(
            "[ERROR ID: %s] %s | Page: %s | Method: %s | Session: %s | User IP: %s | User: %s\n",
            $errorId,
            $message,
            $this->pageName,
            $this->requestMethod,
            $this->sessionId,
            $this->getUserIp(),
            $this->getUserInfo()
        );

        // Add exception details if present
        if ($exception) {
            $logMessage .= sprintf(
                "Exception: %s | Code: %s | File: %s:%d\nStack: %s\n",
                get_class($exception),
                $exception->getCode(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
        }

        // Add context data
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }

        // Log to PHP error log
        error_log($logMessage);

        // Store in session for later retrieval if needed
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['crm_errors'] = $_SESSION['crm_errors'] ?? [];
            $_SESSION['crm_errors'][] = [
                'id' => $errorId,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s'),
                'page' => $this->pageName
            ];
            // Keep only last 20 errors
            if (count($_SESSION['crm_errors']) > 20) {
                array_shift($_SESSION['crm_errors']);
            }
        }

        $this->errors[] = $errorId;
    }

    /**
     * Handle PHP errors
     */
    public function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline
    ): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $errorTypes = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        $type = $errorTypes[$errno] ?? 'Unknown';
        $this->logError(
            "{$type}: {$errstr}",
            null,
            ['file' => $errfile, 'line' => $errline]
        );

        return true;
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException($exception): void
    {
        $this->logError(
            'Uncaught exception: ' . $exception->getMessage(),
            $exception
        );
    }

    /**
     * Display user-facing error message
     * @param string $type - 'error', 'warning', 'success', 'info'
     * @param string $message - Message to display
     * @param array $details - Optional additional details for development
     */
    public function displayAlert(
        string $type = 'error',
        string $message = '',
        array $details = []
    ): void {
        // AJAX requests: JSON response
        if ($this->isAjax) {
            $this->displayJsonAlert($type, $message, $details);
            return;
        }

        // HTML response: Store in session for display
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['alert'] = [
                'type' => $type,
                'message' => $message,
                'details' => $details
            ];
        }
    }

    /**
     * Display alert for AJAX requests as JSON
     */
    private function displayJsonAlert(
        string $type,
        string $message,
        array $details
    ): void {
        header('Content-Type: application/json');

        $response = [
            'success' => $type === 'success',
            'type' => $type,
            'message' => $message
        ];

        if (!empty($details) && defined('WP_DEBUG') && WP_DEBUG) {
            $response['details'] = $details;
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Log database error with query context
     * @param PDOException $exception - PDO exception
     * @param string $query - SQL query that failed
     * @param array $params - Query parameters
     * @param string $userMessage - Message to show user
     */
    public function logDatabaseError(
        PDOException $exception,
        string $query = '',
        array $params = [],
        string $userMessage = 'Database operation failed. Please try again.'
    ): void {
        $this->logError(
            'Database Error: ' . $exception->getMessage(),
            $exception,
            [
                'query' => $query,
                'params' => $params,
                'error_code' => $exception->getCode()
            ]
        );

        $this->displayAlert('error', $userMessage);
    }

    /**
     * Validate required POST/GET parameters
     * @param array $required - Keys to check
     * @param array $source - $_POST, $_GET, etc.
     * @return bool - True if all present, false otherwise
     */
    public function validateRequiredParams(
        array $required,
        array $source = null
    ): bool {
        if ($source === null) {
            $source = $this->requestMethod === 'POST' ? $_POST : $_GET;
        }

        foreach ($required as $key) {
            if (!isset($source[$key]) || trim((string)$source[$key]) === '') {
                $this->logError(
                    'Missing required parameter',
                    null,
                    ['missing' => $key, 'expected' => $required]
                );
                $this->displayAlert('error', 'Missing required information. Please fill in all fields.');
                return false;
            }
        }

        return true;
    }

    /**
     * Validate CSRF token
     * @param string $token - Token from form/request
     * @return bool - True if valid
     */
    public function validateCsrfToken(string $token): bool
    {
        if (function_exists('verifyCSRFToken')) {
            if (!verifyCSRFToken($token)) {
                $this->logError(
                    'CSRF token validation failed',
                    null,
                    ['session_id' => $this->sessionId]
                );
                $this->displayAlert('error', 'Security validation failed. Please refresh and try again.');
                return false;
            }
            return true;
        }

        return true;
    }

    /**
     * Get all logged errors
     * @return array - Array of error IDs
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if any errors occurred
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Generate unique error ID for tracking
     */
    private function generateErrorId(): string
    {
        return 'ERR-' . date('YmdHis') . '-' . substr(md5(uniqid()), 0, 8);
    }

    /**
     * Get user IP address
     */
    private function getUserIp(): string
    {
        foreach (
            [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_FORWARDED_FOR',
                'REMOTE_ADDR'
            ] as $key
        ) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if ((bool)filter_var(
                        $ip,
                        FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                    )) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get current user information
     */
    private function getUserInfo(): string
    {
        if (function_exists('getCurrentUser')) {
            $user = getCurrentUser();
            if ($user) {
                return sprintf('%s (ID: %d)', $user['email'] ?? 'unknown', $user['id'] ?? 0);
            }
        }
        return 'unauthenticated';
    }

    /**
     * Helper: Try operation with error handling
     * @param callable $operation - Function to execute
     * @param string $errorMessage - Message on failure
     * @param array $context - Additional context
     * @return mixed - Result of operation or null on error
     */
    public function tryOperation(
        callable $operation,
        string $errorMessage = 'Operation failed',
        array $context = []
    ) {
        try {
            return $operation();
        } catch (Exception $e) {
            $this->logError($errorMessage, $e, $context);
            return null;
        }
    }
}

/**
 * Global helper function for quick error logging
 * Usage: logCrmError('Something went wrong', $exception);
 */
if (!function_exists('logCrmError')) {
    function logCrmError(string $message, $exception = null, array $context = []): void
    {
        if (isset($GLOBALS['crm_error_handler'])) {
            $GLOBALS['crm_error_handler']->logError($message, $exception, $context);
        } else {
            error_log("[CRM ERROR] {$message}");
        }
    }
}

/**
 * Global helper for displaying alerts
 * Usage: showCrmAlert('error', 'Something went wrong');
 */
if (!function_exists('showCrmAlert')) {
    function showCrmAlert(string $type = 'error', string $message = '', array $details = []): void
    {
        if (isset($GLOBALS['crm_error_handler'])) {
            $GLOBALS['crm_error_handler']->displayAlert($type, $message, $details);
        }
    }
}
