<?php
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

/**
 * QuoteSendService — send a quote to its client: the one place (2026-10-15, Tim: send quotes from Sam on the iPhone).
 * Moved unchanged from the quote page's "Send to Customer" (public/crm/quotes/view.php), which now calls it, and
 * used by Sam's iPhone desk (POST /api/sales/sales-head-mobile {mode: send_quote}).
 *
 * What a send does (owner decisions kept): the quote_sent email template with a "Read the quote" (or "Sign the
 * contract online") button to the client's link, the quote PDF attached (2026-09-30: quotes always carry the PDF),
 * an open-tracking pixel, the building's property manager copied (QuoteRecipientService), a text only with SMS
 * consent and never a link (carrier rules), then the status, the ROI event and the activity log.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QuoteService.php';
require_once __DIR__ . '/QuoteRecipientService.php';

class QuoteSendService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        self::loadHelpers();
    }

    /** The global helpers a send uses — loaded by the web page already; a phone request loads them here. */
    private static function loadHelpers(): void
    {
        if (!function_exists('formatCurrency')) require_once APP_ROOT . '/Services/CrmFunctions.php';
        if (!function_exists('sendEmail')) require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
        if (!function_exists('logQuoteSentEvent')) require_once APP_ROOT . '/Modules/Portfolio/Services/RoiFunctions.php';
        if (!class_exists('EmailWrapper')) require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
    }

    /**
     * Send (or re-send) a quote.
     * @param array{id:int|string, name?:string} $user  the person sending (activity log)
     * @return array{ok: bool, message: string, sent_via: list<string>, status: ?string, email: ?string}
     */
    public function send(int $quoteId, array $user, bool $draftOnly = false): array
    {
        // One send at a time per quote, for every caller (the page, Sam's phone): two clicks, or the page and the
        // phone at once, must not email the client twice (Codex review 2026-10-15).
        $lock = 'mw_quote_send_' . $quoteId;
        $held = false;
        try {
            $g = $this->db->prepare("SELECT GET_LOCK(?, 15)");
            $g->execute([$lock]);
            $held = (int)$g->fetchColumn() === 1;
        } catch (Throwable $e) { /* no named locks (SQLite tests) */ }
        if (!$held && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return ['ok' => false, 'message' => 'This quote is being sent right now — try again in a moment.', 'sent_via' => [], 'status' => null, 'email' => null];
        }
        try {
            return $this->sendLocked($quoteId, $user, $draftOnly);
        } finally {
            if ($held) $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$lock]);
        }
    }

    /**
     * The site's own address for the client's links — never the request's Host header, which a caller controls
     * (Codex review 2026-10-15). The business's website setting (per tenant), else the public site's SITE_URL.
     */
    public static function origin(?string $website = null): string
    {
        $w = trim((string)$website);
        if ($w !== '') {
            $host = parse_url(preg_match('#^https?://#i', $w) ? $w : 'https://' . $w, PHP_URL_HOST);
            if (is_string($host) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) return 'https://' . strtolower($host);
        }
        return defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : _idSiteUrl();
    }

    private function sendLocked(int $quoteId, array $user, bool $draftOnly): array
    {
        $svc = new QuoteService($this->db);
        $recipientSvc = new QuoteRecipientService($this->db);
        $quote = $svc->getWithContact($quoteId);
        if (!$quote) return ['ok' => false, 'message' => 'Quote not found.', 'sent_via' => [], 'status' => null, 'email' => null];
        if ($draftOnly && ($quote['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'message' => 'This quote was already sent.', 'sent_via' => [], 'status' => $quote['status'], 'email' => null];
        }
        $origin = self::origin(EmailWrapper::getCompanyInfo()['company_website'] ?? null);

        $customer              = $svc->resolveContact($quote);
        $customerEmail         = $customer['email'];
        $customerPhone         = $customer['phone'];
        $customerName          = $customer['name'];
        $customerConsentsToSms = $customer['contact_id'] ? hasSmConsent((int)$customer['contact_id']) : false;

        if (!$customerEmail && !$customerPhone) {
            return ['ok' => false, 'message' => 'No contact information found (no email or phone). Please add contact details first.',
                    'sent_via' => [], 'status' => $quote['status'], 'email' => null];
        }

        $emailSent  = false;
        $smsSent    = false;
        $sentVia    = [];
        $attachPath = null;
        $ccEmails   = [];

        $svc->ensureAccessToken($quoteId, $quote);
        $quoteUrl = $origin . '/customer/quote.php?token=' . $quote['access_token'];

        // --- SEND EMAIL ---
        if ($customerEmail) {
            $nameParts   = explode(' ', (string)$customerName, 2);
            $firstName   = $nameParts[0] ?: 'there';
            $companyInfo = EmailWrapper::getCompanyInfo();

            $tplVars = [
                '{{customer_first_name}}' => $firstName,
                '{{customer_name}}'       => $customerName ?: 'Valued Customer',
                '{{quote_number}}'        => $quote['quote_number'],
                '{{property_address}}'    => (string)($quote['property_address'] ?? ''),
                '{{quote_amount}}'        => formatCurrency($quote['amount']),
                '{{quote_valid_until}}'   => formatDate($quote['valid_until']),
                '{{company_name}}'        => $companyInfo['company_name'],
                '{{company_phone}}'       => $companyInfo['company_phone'],
            ];

            $tpl          = loadEmailTemplate('quote_sent', $tplVars);
            $emailSubject = QuoteService::subjectWithAddress((string)$tpl['subject'], $quote['property_address'] ?? '');
            $isContract   = !empty($quote['is_contract']);
            $emailBody    = EmailWrapper::wrap(
                $tpl['body_html'] . ($isContract
                    ? QuoteService::contractSigningHtml((string)($quote['terms'] ?? ''), $quoteUrl, $companyInfo['company_email'] ?? _idReplyTo())
                    : ''),
                $isContract ? 'Sign the contract online' : 'Read the quote',
                $quoteUrl,
                $companyInfo
            );

            // Generate the PDF BEFORE sending so it goes out as an attachment
            // (owner decision 2026-09-30: quotes and invoices always carry the PDF).
            try {
                require_once PUBLIC_ROOT . '/crm/includes/pdf_bootstrap.php';
                require_once PUBLIC_ROOT . '/crm/includes/PdfGenerator.php';
                $pdfGen       = new PdfGenerator();
                $existingPath = $pdfGen->getPdfPath('quote', $quoteId);
                if ($existingPath) {
                    $attachPath = $existingPath;
                } else {
                    $pdfResult = $pdfGen->generateQuotePdf($quoteId);
                    if ($pdfResult['success']) $attachPath = $pdfResult['path'];
                }
            } catch (Throwable $pdfEx) {
                error_log("Quote PDF generation error: " . $pdfEx->getMessage());
            }
            // Quotes always carry their PDF (owner, 2026-09-30): no PDF, no email — said, not sent without it.
            if (!$attachPath || !is_readable($attachPath)) {
                return ['ok' => false, 'message' => "The quote's PDF couldn't be made, so nothing was sent. Try again, or open the quote and download the PDF to see what's wrong.",
                        'sent_via' => [], 'status' => $quote['status'], 'email' => $customerEmail];
            }

            // Email open tracking pixel (1x1 transparent PNG)
            $pixelUrl  = $origin . '/crm/api/track-quote-open.php?t=' . urlencode($quote['access_token']);
            $emailBody = str_replace('</body>', '<img src="' . htmlspecialchars($pixelUrl) . '" width="1" height="1" alt="" style="display:none;border:none;" /></body>', $emailBody);

            // Copy the building's property manager, if known (QuoteRecipientService).
            $ccEmails    = $recipientSvc->ccEmailsForQuote($quote, $customer['contact_id'], $customerEmail);
            $emailResult = sendEmail($customerEmail, $emailSubject, $emailBody, $attachPath, '', [], $ccEmails);

            if ($emailResult['success']) {
                $emailSent = true;
                $sentVia[] = 'email';
            } else {
                error_log("Email failed for quote {$quoteId}: " . ($emailResult['error'] ?? 'unknown'));
            }
        }

        // --- SEND SMS (if consented) ---
        if ($customerPhone && $customerConsentsToSms) {
            // Carrier rules: no link, ≤160, the office phone (QuoteService::quoteSentSms(), tested).
            $smsResult = sendSms($customerPhone, QuoteService::quoteSentSms((string)$quote['quote_number']));
            if ($smsResult['success']) {
                $smsSent = true;
                $sentVia[] = 'SMS (' . ($smsResult['carrier'] ?? 'unknown') . ')';
            }
        }

        if (!$emailSent && !$smsSent) {
            error_log("Quote send failed for quote {$quoteId}");
            return ['ok' => false, 'message' => 'Error sending quote. Please check that the email address is valid and try again.',
                    'sent_via' => [], 'status' => $quote['status'], 'email' => $customerEmail];
        }

        // --- UPDATE QUOTE STATUS ---
        $oldStatus = $quote['status'];
        $newStatus = QuoteService::statusAfterSend((string)$oldStatus);
        $svc->markSent($quoteId, implode(',', $sentVia)); // never undoes accepted/declined/expired
        if ($newStatus !== $oldStatus) trackFieldChange('quote', $quoteId, 'status', $oldStatus, $newStatus, $user['id'] ?? null);
        logQuoteSentEvent($quoteId);

        $activityDetails = ($newStatus !== 'sent' ? "Quote link re-sent ({$oldStatus} quote, status unchanged) via " : 'Quote sent via ') . implode(' + ', $sentVia);
        if ($customerEmail) $activityDetails .= " (email: {$customerEmail}" . (!empty($ccEmails) ? ', cc: ' . implode(', ', $ccEmails) : '') . ')';
        if ($customerPhone && $customerConsentsToSms) $activityDetails .= " (SMS: {$customerPhone})";
        if ($attachPath) $activityDetails .= ' with PDF attached';
        logActivityExtended($user['id'] ?? null, 'Quote sent', $activityDetails, null, null, $quoteId);

        return ['ok' => true, 'message' => 'Quote sent successfully via ' . implode(' & ', $sentVia), 'sent_via' => $sentVia,
                'status' => $newStatus, 'email' => $customerEmail];
    }

    /**
     * Quotes waiting to be sent (drafts), newest first, for Sam's desk on the iPhone.
     * @return list<array{id:int, number:string, title:string, client:string, email:?string, address:string, amount:float,
     *                    created:string, items: list<array{name:string, description:string, qty:float, price:float, total:float}>}>
     */
    public function drafts(int $limit = 20): array
    {
        $svc = new QuoteService($this->db);
        $ids = $this->db->query("SELECT id FROM quotes WHERE status = 'draft' ORDER BY id DESC LIMIT " . max(1, min(50, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($ids as $id) {
            $q = $svc->getWithContact((int)$id);
            if (!$q) continue;
            $c = $svc->resolveContact($q);
            $items = [];
            foreach ($svc->getLineItems((int)$id) as $li) {
                $items[] = ['name' => (string)($li['service_type'] ?? ''), 'description' => (string)($li['description'] ?? ''),
                            'qty' => (float)($li['quantity'] ?? 1), 'price' => (float)($li['unit_price'] ?? 0), 'total' => (float)($li['line_total'] ?? 0),
                            'optional' => !empty($li['is_optional'])];
            }
            $out[] = ['id' => (int)$id, 'number' => (string)$q['quote_number'], 'title' => (string)($q['title'] ?? ''),
                      'client' => (string)($c['name'] ?? ''), 'email' => $c['email'] ?: null, 'address' => (string)($q['property_address'] ?? ''),
                      'subtotal' => (float)($q['subtotal'] ?? 0), 'gst' => (float)($q['tax_amount'] ?? 0), 'amount' => (float)($q['amount'] ?? 0),
                      'valid_until' => (string)($q['valid_until'] ?? ''), 'created' => (string)($q['created_at'] ?? ''), 'items' => $items];
        }
        return $out;
    }
}
