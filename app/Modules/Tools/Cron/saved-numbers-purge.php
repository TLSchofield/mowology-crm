<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ITTAI_DIRECTORY_MODE', true);
require_once dirname(__DIR__, 3) . '/Core/config.php';
require_once dirname(__DIR__) . '/Services/SavedNumbersService.php';
require_once dirname(__DIR__, 3) . '/Services/Messaging/SmtpMailer.php';
$db = Database::platform();
if (!$db) { echo "platform database not configured\n"; exit(0); }
$service = new SavedNumbersService($db);
$summary = $service->purge(null, function (array $row, string $keepToken, string $deleteToken): bool {
    $keep = 'https://ittai.ca/tools/keep/?t=' . rawurlencode($keepToken);
    $delete = 'https://ittai.ca/tools/delete/?t=' . rawurlencode($deleteToken);
    $html = '<p>Your saved numbers will be deleted in 2 weeks.</p><p><a href="' . htmlspecialchars($keep, ENT_QUOTES, 'UTF-8') . '">Keep them</a></p><p><a href="' . htmlspecialchars($delete, ENT_QUOTES, 'UTF-8') . '">Delete them now</a></p>';
    $plain = "Your saved numbers will be deleted in 2 weeks.\nKeep them: {$keep}\nDelete them: {$delete}\n";
    return sendPlatformEmail($row['email'], 'Your saved numbers will be deleted in 2 weeks', $html, $plain);
});
echo 'pending deleted ' . $summary['pending_deleted'] . ', reminders sent ' . $summary['reminders_sent'] . ', confirmed deleted ' . $summary['confirmed_deleted'] . "\n";
