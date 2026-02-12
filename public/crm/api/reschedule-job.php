<?php
/**
 * @deprecated Use reschedule-stop.php instead.
 * This endpoint operated on the legacy `jobs` table which has been dropped.
 */
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success' => false, 'error' => 'This endpoint is deprecated. Use reschedule-stop.php instead.']);
