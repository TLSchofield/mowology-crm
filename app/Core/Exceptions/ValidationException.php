<?php
declare(strict_types=1);

/**
 * ValidationException
 *
 * Thrown by API entry-point guards when client input is missing, malformed,
 * or fails type/range checks. The constructor signature is (field, message)
 * so call sites read naturally: `throw new ValidationException('id', 'Invalid id')`.
 *
 * Mapping:
 *   - When wrapped by ApiHandler::handle() (see app/Core/Api/ApiHandler.php
 *     introduced in Phase 3) the exception is mapped to HTTP 422 + canonical
 *     envelope.
 *   - For endpoints that have not yet adopted the wrap pattern, callers can
 *     use respondValidationError($e) below to emit the same 422 response and
 *     exit.
 *
 * No namespace — matches the procedural style of /app/Core/* helpers
 * (paths.php, config.php, IdempotencyHelper.php).
 */

if (!class_exists('ValidationException', false)) {
    final class ValidationException extends \RuntimeException
    {
        public string $field;

        public function __construct(string $field, string $message = '', ?\Throwable $previous = null)
        {
            parent::__construct($message, 0, $previous);
            $this->field = $field;
        }
    }
}

if (!function_exists('respondValidationError')) {
    /**
     * Emit a 422 JSON response describing a validation failure and exit.
     * Safe to call after partial output: only sets headers if not yet sent.
     */
    function respondValidationError(ValidationException $e): void
    {
        if (!headers_sent()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode([
            'ok'      => false,
            'error'   => 'validation',
            'field'   => $e->field,
            'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Validation failed',
        ]);
        exit;
    }
}
