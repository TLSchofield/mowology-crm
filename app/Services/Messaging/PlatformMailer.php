<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

/** Load PHPMailer without loading CRM identity or logging services. */
if (!function_exists('platformLoadPhpMailer')) {
    function platformLoadPhpMailer(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
            return $loaded = true;
        }
        $possibleBases = [
            PUBLIC_ROOT . '/vendor/phpmailer/phpmailer/src',
            PUBLIC_ROOT . '/crm/vendor/phpmailer/src',
            PUBLIC_ROOT . '/vendor/phpmailer/src',
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
            dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
        ];
        foreach ($possibleBases as $base) {
            if (file_exists($base . '/PHPMailer.php')) {
                require_once $base . '/Exception.php';
                require_once $base . '/PHPMailer.php';
                require_once $base . '/SMTP.php';
                return $loaded = true;
            }
        }
        return $loaded = false;
    }
}

if (!function_exists('platformStripHeaderInjection')) {
    /** Remove control characters that could inject mail headers. */
    function platformStripHeaderInjection(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F]+/', ' ', $value));
    }
}

/** Send platform mail without business identity or database logging. */
function sendPlatformEmail(string $to, string $subject, string $htmlBody, string $plainBody = ''): bool
{
    if (!defined('ITTAI_MAIL_FROM') || (string) ITTAI_MAIL_FROM === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!platformLoadPhpMailer() || !defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return false;
    }
    $fromName = defined('ITTAI_MAIL_FROM_NAME') && (string) ITTAI_MAIL_FROM_NAME !== ''
        ? (string) ITTAI_MAIL_FROM_NAME
        : 'Ittai';
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) SMTP_HOST;
        $mail->Port = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
        $mail->SMTPAuth = true;
        $mail->Username = (string) SMTP_USER;
        $mail->Password = (string) SMTP_PASS;
        $mail->SMTPSecure = $mail->Port === 465
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = '8bit';
        $mail->setFrom((string) ITTAI_MAIL_FROM, $fromName);
        $mail->addReplyTo((string) ITTAI_MAIL_FROM, $fromName);
        $mail->addAddress($to);
        $mail->Subject = platformStripHeaderInjection($subject);
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody !== '' ? $plainBody : trim(strip_tags($htmlBody));
        $mail->XMailer = 'Ittai';
        return $mail->send();
    } catch (Throwable $exception) {
        error_log('sendPlatformEmail error: ' . $exception->getMessage());
        return false;
    }
}
