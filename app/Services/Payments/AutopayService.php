<?php
/**
 * AutopayService — off-session charge orchestration for recurring plans
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Payments/AutopayService.php';
 *   $autopay = new AutopayService(getDB());
 *   if ($autopay->isAutopayEligible($invoiceId)) {
 *       $result = $autopay->attemptCharge($invoiceId);
 *   }
 *
 * The invoice status update on success is handled by the existing
 * payment_intent.succeeded webhook — this service only manages autopay_attempts.
 */

declare(strict_types=1);

require_once APP_ROOT . '/Core/paths.php';
require_once PUBLIC_ROOT . '/vendor/autoload.php';
require_once PUBLIC_ROOT . '/app_config/secrets.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';

class AutopayService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        \Stripe\Stripe::setAppInfo('Mowology CRM', '1.0', 'https://mowology.ca');
    }

    /**
     * Returns true if this invoice should be auto-charged.
     *
     * Conditions:
     * - Invoice status is sent, viewed, partial, or overdue
     * - Invoice has a contact with stripe_payment_method_id set
     * - Effective autopay is on (plan override ?? contact default)
     * - No existing autopay_attempts row with status succeeded or pending
     */
    public function isAutopayEligible(int $invoiceId): bool
    {
        $data = $this->loadInvoiceData($invoiceId);
        if (!$data) {
            return false;
        }

        $payableStatuses = ['sent', 'viewed', 'partial', 'overdue'];
        if (!in_array($data['invoice_status'], $payableStatuses, true)) {
            return false;
        }

        if (empty($data['stripe_payment_method_id'])) {
            return false;
        }

        if (!$this->resolveAutopayFlag($data)) {
            return false;
        }

        // Don't retry if already pending or succeeded
        $existing = $this->db->prepare("
            SELECT id FROM autopay_attempts
            WHERE invoice_id = ? AND status IN ('pending', 'succeeded')
            LIMIT 1
        ");
        $existing->execute([$invoiceId]);
        if ($existing->fetch()) {
            return false;
        }

        return true;
    }

    /**
     * Attempt an off-session charge for the given invoice.
     *
     * Returns an array with keys:
     *   status  => 'succeeded' | 'failed' | 'authentication_required' | 'skipped'
     *   message => human-readable description
     *   attempt_id => autopay_attempts.id (if an attempt row was created)
     */
    public function attemptCharge(int $invoiceId): array
    {
        $data = $this->loadInvoiceData($invoiceId);
        if (!$data) {
            return ['status' => 'skipped', 'message' => 'Invoice not found'];
        }

        $payableStatuses = ['sent', 'viewed', 'partial', 'overdue'];
        if (!in_array($data['invoice_status'], $payableStatuses, true)) {
            return ['status' => 'skipped', 'message' => 'Invoice not in payable status: ' . $data['invoice_status']];
        }

        if (empty($data['stripe_payment_method_id'])) {
            return ['status' => 'skipped', 'message' => 'No payment method on file'];
        }

        if (!$this->resolveAutopayFlag($data)) {
            return ['status' => 'skipped', 'message' => 'Autopay not enabled for this invoice'];
        }

        $balanceDue  = (float) $data['balance_due'];
        if ($balanceDue <= 0) {
            return ['status' => 'skipped', 'message' => 'No balance due'];
        }
        $amountCents = (int) round($balanceDue * 100);
        $contactId   = (int) $data['contact_id'];
        $attemptNum  = $this->nextAttemptNumber($invoiceId);

        // Insert attempt row as pending
        $insertStmt = $this->db->prepare("
            INSERT INTO autopay_attempts
                (invoice_id, contact_id, amount_cents, status, attempt_number, created_at)
            VALUES (?, ?, ?, 'pending', ?, NOW())
        ");
        $insertStmt->execute([$invoiceId, $contactId, $amountCents, $attemptNum]);
        $attemptId = (int) $this->db->lastInsertId();

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount'         => $amountCents,
                'currency'       => 'cad',
                'customer'       => $data['stripe_customer_id'],
                'payment_method' => $data['stripe_payment_method_id'],
                'confirm'        => true,
                'off_session'    => true,
                'description'    => 'Invoice ' . $data['invoice_number'] . ' — Mowology Landscaping (autopay)',
                'statement_descriptor' => 'MOWOLOGY INV',
                'metadata'       => [
                    'invoice_id'       => (string) $invoiceId,
                    'invoice_number'   => $data['invoice_number'],
                    'contact_id'       => (string) $contactId,
                    'autopay_attempt'  => (string) $attemptId,
                    'source'           => 'autopay',
                ],
            ]);

            // Payment succeeded synchronously (rare but possible for some card types)
            $this->db->prepare("
                UPDATE autopay_attempts
                SET status = 'succeeded', payment_intent_id = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$intent->id, $attemptId]);

            error_log(sprintf(
                '[AutopayService] Invoice %d charged successfully. PI: %s  Attempt: %d',
                $invoiceId, $intent->id, $attemptId
            ));

            return ['status' => 'succeeded', 'message' => 'Charge succeeded', 'attempt_id' => $attemptId];

        } catch (\Stripe\Exception\CardException $e) {
            $errorCode    = $e->getStripeCode()  ?? 'unknown';
            $errorMessage = $e->getMessage();
            $piId         = $e->getError()->payment_intent->id ?? null;

            if ($errorCode === 'authentication_required') {
                // 3DS required — do not retry; send customer to invoice portal
                $this->db->prepare("
                    UPDATE autopay_attempts
                    SET status = 'authentication_required',
                        payment_intent_id = ?,
                        failure_code = ?,
                        failure_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$piId, $errorCode, $errorMessage, $attemptId]);

                $this->notifyAuthRequired($data, $invoiceId);

                error_log(sprintf(
                    '[AutopayService] Invoice %d requires 3DS authentication. Contact: %d',
                    $invoiceId, $contactId
                ));

                return ['status' => 'authentication_required', 'message' => $errorMessage, 'attempt_id' => $attemptId];
            }

            // Decline or other card error — schedule retry
            $retryAt = (new \DateTime('+3 days'))->format('Y-m-d H:i:s');

            $this->db->prepare("
                UPDATE autopay_attempts
                SET status = 'failed',
                    payment_intent_id = ?,
                    failure_code = ?,
                    failure_message = ?,
                    next_retry_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$piId, $errorCode, $errorMessage, $retryAt, $attemptId]);

            $this->notifyStaffPaymentFailed($data, $invoiceId, $errorCode, $errorMessage, $attemptNum);

            error_log(sprintf(
                '[AutopayService] Invoice %d autopay failed. Code: %s  Attempt: %d  Next retry: %s',
                $invoiceId, $errorCode, $attemptNum, $retryAt
            ));

            return ['status' => 'failed', 'message' => $errorMessage, 'attempt_id' => $attemptId];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $errorMessage = $e->getMessage();

            $this->db->prepare("
                UPDATE autopay_attempts
                SET status = 'failed',
                    failure_code = 'api_error',
                    failure_message = ?,
                    next_retry_at = DATE_ADD(NOW(), INTERVAL 3 DAY),
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$errorMessage, $attemptId]);

            error_log('[AutopayService] Stripe API error for invoice ' . $invoiceId . ': ' . $errorMessage);

            return ['status' => 'failed', 'message' => $errorMessage, 'attempt_id' => $attemptId];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Load invoice + contact + plan data needed for autopay decisions.
     */
    private function loadInvoiceData(int $invoiceId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                i.id                AS invoice_id,
                i.invoice_number,
                i.status            AS invoice_status,
                i.balance_due,
                i.plan_id,
                i.contact_id,
                i.access_token,
                ct.stripe_customer_id,
                ct.stripe_payment_method_id,
                ct.autopay_enabled,
                ct.email            AS contact_email,
                ct.first_name       AS contact_first,
                ct.last_name        AS contact_last,
                jp.autopay_override
            FROM invoices i
            LEFT JOIN contacts  ct ON i.contact_id = ct.id
            LEFT JOIN job_plans jp ON i.plan_id    = jp.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Resolve effective autopay flag: plan override takes precedence over contact default.
     */
    private function resolveAutopayFlag(array $data): bool
    {
        if ($data['autopay_override'] !== null) {
            return (bool) $data['autopay_override'];
        }
        return (bool) ($data['autopay_enabled'] ?? false);
    }

    /**
     * Count previous attempts for this invoice to assign attempt_number.
     */
    private function nextAttemptNumber(int $invoiceId): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(attempt_number), 0) FROM autopay_attempts WHERE invoice_id = ?
        ");
        $stmt->execute([$invoiceId]);
        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * Email the customer when 3DS authentication is required.
     * The existing invoice portal link will handle the authentication.
     */
    private function notifyAuthRequired(array $data, int $invoiceId): void
    {
        $email = $data['contact_email'] ?? null;
        if (!$email) {
            return;
        }

        $firstName = $data['contact_first'] ?? 'there';
        $portalUrl = 'https://mowology.ca/customer/invoice.php?token=' . urlencode($data['access_token'] ?? '');

        $subject = 'Action required: please complete payment for Invoice ' . htmlspecialchars($data['invoice_number'], ENT_QUOTES);

        $body = '
        <p>Hi ' . htmlspecialchars($firstName, ENT_QUOTES) . ',</p>
        <p>We tried to process your invoice automatically, but your bank requires you to confirm the payment.</p>
        <p><strong>This only takes a moment.</strong> Please click the link below to complete the payment:</p>
        <p><a href="' . $portalUrl . '" style="background:#2D8659;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Complete Payment</a></p>
        <p>Invoice: ' . htmlspecialchars($data['invoice_number'], ENT_QUOTES) . '</p>
        <p>If you have questions, call us at (778) 846-9273.</p>
        <p>— Mowology Landscaping</p>';

        sendEmail($email, $subject, $body);
    }

    /**
     * Notify CRM staff when an autopay charge fails (non-3DS).
     * Uses the configured admin notification email from business_settings if available,
     * falls back to hardcoded office address.
     */
    private function notifyStaffPaymentFailed(array $data, int $invoiceId, string $code, string $message, int $attemptNum): void
    {
        try {
            $adminEmailRow = $this->db->query(
                "SELECT setting_value FROM business_settings WHERE setting_key = 'admin_email' LIMIT 1"
            )->fetch(\PDO::FETCH_ASSOC);
            $adminEmail = $adminEmailRow['setting_value'] ?? 'office@mowology.ca';
        } catch (\Throwable $e) {
            $adminEmail = 'office@mowology.ca';
        }

        $clientName = trim(($data['contact_first'] ?? '') . ' ' . ($data['contact_last'] ?? '')) ?: 'Unknown';
        $subject    = 'Autopay failed: Invoice ' . $data['invoice_number'] . ' — ' . $clientName;
        $body       = '
        <p>Autopay attempt ' . $attemptNum . ' failed for:</p>
        <ul>
            <li><strong>Invoice:</strong> ' . htmlspecialchars($data['invoice_number'], ENT_QUOTES) . '</li>
            <li><strong>Client:</strong> ' . htmlspecialchars($clientName, ENT_QUOTES) . '</li>
            <li><strong>Failure code:</strong> ' . htmlspecialchars($code, ENT_QUOTES) . '</li>
            <li><strong>Message:</strong> ' . htmlspecialchars($message, ENT_QUOTES) . '</li>
        </ul>
        ' . ($attemptNum < 3 ? '<p>The system will retry in 3 days.</p>' : '<p><strong>Maximum retries reached.</strong> Please contact the client directly.</p>') . '
        <p><a href="https://mowology.ca/crm/invoices/view.php?id=' . $invoiceId . '">View invoice in CRM</a></p>';

        sendEmail($adminEmail, $subject, $body);
    }
}
