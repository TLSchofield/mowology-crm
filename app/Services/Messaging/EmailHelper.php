<?php
/**
 * Email Helper — Multipart MIME support for PDF attachments
 *
 * Usage:
 *   require_once __DIR__ . '/email_helper.php';
 *
 *   // Without attachment:
 *   sendCrmEmail($to, $subject, $htmlBody);
 *
 *   // With attachment:
 *   sendCrmEmail($to, $subject, $htmlBody, '/path/to/file.pdf');
 *
 * Migrated from: public/crm/includes/email_helper.php
 */

/**
 * Send an HTML email, optionally with a PDF attachment.
 *
 * @param string      $to             Recipient email
 * @param string      $subject        Email subject
 * @param string      $htmlBody       HTML email body
 * @param string|null $attachmentPath Full filesystem path to PDF (or null)
 * @param string      $from           From header value
 * @return bool       Whether mail() returned true
 */
function sendCrmEmail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath = null,
    string $from = 'Mowology <noreply@mowology.ca>'
): bool {
    // Validate email address
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("sendCrmEmail: invalid recipient email: {$to}");
        return false;
    }

    // Validate attachment exists
    if ($attachmentPath && !file_exists($attachmentPath)) {
        error_log("sendCrmEmail: attachment not found: {$attachmentPath}");
        $attachmentPath = null;
    }

    // --- Simple HTML email (no attachment) ---
    if (!$attachmentPath) {
        $headers = "From: {$from}\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        error_log("sendCrmEmail: Attempting to send email to {$to}, subject: {$subject}");
        $result = mail($to, $subject, $htmlBody, $headers);
        if (!$result) {
            error_log("sendCrmEmail: mail() returned false for {$to}, subject: {$subject}. Check php.ini mail configuration.");
        } else {
            error_log("sendCrmEmail: mail() succeeded for {$to}, subject: {$subject}");
        }
        return $result;
    }

    // --- Multipart MIME with PDF attachment ---
    $boundary = 'MOWOLOGY_' . md5(uniqid((string)time(), true));
    $filename = basename($attachmentPath);

    try {
        $fileContent = chunk_split(base64_encode(file_get_contents($attachmentPath)));
    } catch (Exception $e) {
        error_log("sendCrmEmail: failed to read attachment: {$attachmentPath} - " . $e->getMessage());
        return false;
    }

    $headers = "From: {$from}\r\n";
    $headers .= "Reply-To: office@mowology.ca\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    // HTML part
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";

    // PDF attachment part
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
    $body .= $fileContent . "\r\n";

    // End boundary
    $body .= "--{$boundary}--";

    error_log("sendCrmEmail: Attempting to send email with attachment to {$to}, subject: {$subject}, attachment: {$filename}");
    $result = mail($to, $subject, $body, $headers);
    if (!$result) {
        error_log("sendCrmEmail: mail() with attachment returned false for {$to}, subject: {$subject}. Check php.ini mail configuration.");
    } else {
        error_log("sendCrmEmail: mail() with attachment succeeded for {$to}, subject: {$subject}");
    }
    return $result;
}

/**
 * Check if a company prefers PDF attachments on emails.
 *
 * @param int $companyId
 * @return bool
 */
function companyPrefersAttachment(int $companyId): bool
{
    if (!$companyId) return true; // Default to attach

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT pref_attach_pdf FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool)($row['pref_attach_pdf'] ?? 1);
    } catch (PDOException $e) {
        return true; // Default to attach on error
    }
}
