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
require_once dirname(__DIR__, 2) . '/Services/Messaging/IdentityMail.php';

require_once APP_ROOT . '/Core/paths.php';
require_once PUBLIC_ROOT . '/vendor/autoload.php';
require_once PUBLIC_ROOT . '/app_config/secrets.php';
require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
require_once __DIR__ . '/AutopayEligibility.php';
require_once __DIR__ . '/StripeErrorRules.php';

class AutopayService
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        \Stripe\Stripe::setAppInfo(_idFromName() . ' CRM', '1.0', _idSiteUrl());
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
        // Cheap pre-check only — attemptCharge() repeats it under the invoice row lock.
        return AutopayEligibility::blockReason(
            $this->loadInvoiceData($invoiceId),
            $this->hasOpenAttempt($invoiceId)
        ) === null;
    }

    /**
     * Fire-and-log an autopay attempt when an invoice first transitions to 'sent'.
     * Call this from EVERY code path that marks an invoice 'sent' for the first
     * time — desktop send, visit invoicing, contract billing, bulk resend, any
     * future one. Swallows all errors so a charge-trigger failure never blocks or
     * breaks the caller's send flow. See Known-Failure-Patterns "Touching Autopay"
     * for why this must not be duplicated inline per-caller again — that's exactly
     * how the visit-invoicing and contract-billing paths silently never charged.
     */
    public static function triggerOnSend(\PDO $db, int $invoiceId, string $context = ''): void
    {
        try {
            $autopay = new self($db);
            if ($autopay->isAutopayEligible($invoiceId)) {
                $result = $autopay->attemptCharge($invoiceId);
                error_log('[Autopay] ' . $context . ' send invoice ' . $invoiceId . ' → '
                    . ($result['status'] ?? '?') . ': ' . ($result['message'] ?? ''));
            }
        } catch (\Throwable $e) {
            error_log('[Autopay] ' . $context . ' charge trigger failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
        }
    }

    /**
     * Never charge a card while the client has credit on account (Codex review 2026-10-14):
     * ClientCreditService::applyOnSend swallows a failure so the invoice still goes out, and the
     * charge would then take the full balance with the credit sitting unused. Skip, say why, and
     * leave it for the office: apply the credit, and the next run charges only what is left.
     * A failure to read credit skips the charge too (fails closed; Codex review): the next run retries.
     */
    private function unappliedCreditReason(int $invoiceId): ?string
    {
        try {
            if (!class_exists('ClientCreditService')) require_once APP_ROOT . '/Modules/Accounting/Services/ClientCreditService.php';
            $c = (new \ClientCreditService($this->db))->getBalanceForInvoiceClient($invoiceId);
            if ((float)$c['balance'] > 0.005) {
                error_log(sprintf('[Autopay] invoice %d skipped — client %d has $%.2f credit not applied', $invoiceId, (int)$c['client_id'], (float)$c['balance']));
                return sprintf('Client has $%.2f credit on account that was not applied — apply it on the invoice first; autopay charges only what is left', (float)$c['balance']);
            }
        } catch (\Throwable $e) {
            error_log('[Autopay] credit check failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
            return 'Could not check the client\'s credit on account — not charged; the next run tries again';
        }
        return null;
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
        // ── System-wide live-mode gate ────────────────────────────────────────
        // autopay_live_mode must be explicitly set to 1 in business_settings
        // before any real charges fire. While 0, every eligible invoice is
        // recorded in autopay_attempts as 'skipped' so you can review what
        // WOULD be charged before going live.
        if (!$this->isLiveModeEnabled()) {
            $data = $this->loadInvoiceData($invoiceId);
            if ($data && $this->resolveAutopayFlag($data) && !empty($data['stripe_payment_method_id'])) {
                $contactId   = (int) $data['contact_id'];
                $balanceDue  = (float) $data['balance_due'];
                $amountCents = $balanceDue > 0 ? (int) round($balanceDue * 100) : 0;
                $attemptNum  = $this->nextAttemptNumber($invoiceId);
                $this->db->prepare("
                    INSERT INTO autopay_attempts
                        (invoice_id, contact_id, amount_cents, status, attempt_number,
                         failure_code, failure_message, created_at)
                    VALUES (?, ?, ?, 'skipped', ?, 'system_disabled',
                            'Autopay live mode is OFF — no charge attempted. Set autopay_live_mode=1 in business_settings to enable.', NOW())
                ")->execute([$invoiceId, $contactId, $amountCents, $attemptNum]);
                error_log(sprintf(
                    '[AutopayService] DRY RUN — invoice %d contact %d $%.2f would be charged. Enable live mode to charge.',
                    $invoiceId, $contactId, $balanceDue
                ));
            }
            return ['status' => 'skipped', 'message' => 'Autopay live mode is disabled — no charge attempted'];
        }

        // ── Reserve the attempt atomically (Fable review 2026-10-12) ──────────
        // Lock the invoice row, re-check eligibility (including "no pending or
        // succeeded attempt") and insert the 'pending' row in ONE transaction, so two
        // callers (cron + a send, two sends) cannot both pass the check and both charge.
        // If the caller already holds a transaction we join it; the lock lasts until
        // the caller commits.
        $ownTxn = !$this->db->inTransaction();
        if ($ownTxn) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("SELECT id FROM invoices WHERE id = ? FOR UPDATE")->execute([$invoiceId]);
            $data   = $this->loadInvoiceData($invoiceId);
            $reason = AutopayEligibility::blockReason($data, $this->hasOpenAttempt($invoiceId))
                   ?? $this->unappliedCreditReason($invoiceId);
            if ($reason !== null) {
                if ($ownTxn) {
                    $this->db->rollBack();
                }
                return ['status' => 'skipped', 'message' => $reason];
            }

            $balanceDue  = (float) $data['balance_due'];
            $amountCents = (int) round($balanceDue * 100);
            $contactId   = (int) $data['contact_id'];
            $attemptNum  = $this->nextAttemptNumber($invoiceId);

            $insertStmt = $this->db->prepare("
                INSERT INTO autopay_attempts
                    (invoice_id, contact_id, amount_cents, status, attempt_number, created_at)
                VALUES (?, ?, ?, 'pending', ?, NOW())
            ");
            $insertStmt->execute([$invoiceId, $contactId, $amountCents, $attemptNum]);
            $attemptId = (int) $this->db->lastInsertId();

            if ($ownTxn) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTxn && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount'         => $amountCents,
                'currency'       => 'cad',
                'customer'       => $data['stripe_customer_id'],
                'payment_method' => $data['stripe_payment_method_id'],
                'confirm'        => true,
                'off_session'    => true,
                'description'    => 'Invoice ' . $data['invoice_number'] . ' — ' . _idCompanyInfo()['company_name'] . ' (autopay)',
                'statement_descriptor_suffix' => (strtoupper(substr(preg_replace('/[^A-Za-z0-9 ]/', '', _idFromName()), 0, 22)) ?: 'INVOICE'),
                'metadata'       => [
                    'invoice_id'       => (string) $invoiceId,
                    'invoice_number'   => $data['invoice_number'],
                    'contact_id'       => (string) $contactId,
                    'autopay_attempt'  => (string) $attemptId,
                    'source'           => 'autopay',
                ],
            ], [
                // One key per attempt: a network retry of this request can never charge twice.
                'idempotency_key' => AutopayEligibility::idempotencyKey($invoiceId, $attemptNum),
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
            // Unknown outcome (lost connection, Stripe 5xx): the card may have been charged. Never
            // auto-retry it three days later under a new idempotency key — that can charge twice.
            // Staff check Stripe first; if it did go through, the webhook marks the invoice paid
            // (Fable review 2026-10-13). A clear refusal still retries as before.
            $unknown = StripeErrorRules::chargeOutcomeUnknown(get_class($e), $e->getHttpStatus());

            $this->db->prepare("
                UPDATE autopay_attempts
                SET status = 'failed',
                    failure_code = ?,
                    failure_message = ?,
                    next_retry_at = " . ($unknown ? "NULL" : "DATE_ADD(NOW(), INTERVAL 3 DAY)") . ",
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$unknown ? 'api_unknown' : 'api_error', $errorMessage, $attemptId]);

            if ($unknown) {
                $this->notifyStaffPaymentFailed($data, $invoiceId, 'api_unknown',
                    'Stripe did not answer clearly (' . $errorMessage . '). The card MAY have been charged — check Stripe for this invoice before charging again. No automatic retry.', 3);
            }
            error_log('[AutopayService] Stripe API error for invoice ' . $invoiceId . ($unknown ? ' (outcome unknown — no auto-retry)' : '') . ': ' . $errorMessage);

            return ['status' => 'failed', 'message' => $errorMessage, 'attempt_id' => $attemptId];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Check whether autopay_live_mode is enabled in business_settings.
     * Returns false (safe default) if the column doesn't exist yet or the row is missing.
     */
    private function isLiveModeEnabled(): bool
    {
        try {
            $row = $this->db->query("SELECT autopay_live_mode FROM business_settings LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            return isset($row['autopay_live_mode']) && (int) $row['autopay_live_mode'] === 1;
        } catch (\Throwable $e) {
            // Column doesn't exist yet (migration not run) — treat as disabled
            return false;
        }
    }

    /**
     * Load invoice + contact + plan data needed for autopay decisions.
     *
     * Payment method resolution (company card takes precedence):
     *   - Invoice linked to a company AND company has a card → use company card
     *   - Otherwise → use contact card
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
                i.company_id,
                i.access_token,
                ct.stripe_customer_id        AS ct_stripe_customer_id,
                ct.stripe_payment_method_id  AS ct_stripe_payment_method_id,
                ct.autopay_enabled           AS ct_autopay_enabled,
                ct.email            AS contact_email,
                ct.first_name       AS contact_first,
                ct.last_name        AS contact_last,
                co.stripe_customer_id        AS co_stripe_customer_id,
                co.stripe_payment_method_id  AS co_stripe_payment_method_id,
                co.autopay_enabled           AS co_autopay_enabled,
                co.company_name              AS co_company_name,
                co.billing_email             AS co_billing_email,
                cobc.email                   AS co_billing_contact_email,
                cobc.first_name              AS co_billing_contact_first,
                jp.autopay_override
            FROM invoices i
            LEFT JOIN contacts  ct ON i.contact_id  = ct.id
            LEFT JOIN companies co ON i.company_id  = co.id
            LEFT JOIN contacts  cobc ON cobc.id     = co.billing_contact_id
            LEFT JOIN job_plans jp ON i.plan_id     = jp.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Resolve which payment source to use
        if (!empty($row['company_id']) && !empty($row['co_stripe_payment_method_id'])) {
            $row['stripe_customer_id']       = $row['co_stripe_customer_id'];
            $row['stripe_payment_method_id'] = $row['co_stripe_payment_method_id'];
            $row['autopay_enabled']          = $row['co_autopay_enabled'];
            // Which card is being charged decides who can authenticate it.
            $row['payment_source']           = 'company';
        } else {
            $row['payment_source']           = 'contact';
            $row['stripe_customer_id']       = $row['ct_stripe_customer_id'];
            $row['stripe_payment_method_id'] = $row['ct_stripe_payment_method_id'];
            $row['autopay_enabled']          = $row['ct_autopay_enabled'];
        }

        return $row;
    }

    /**
     * Resolve effective autopay flag: plan override takes precedence over contact default.
     */
    private function resolveAutopayFlag(array $data): bool
    {
        return AutopayEligibility::autopayOn($data);
    }

    /** A 'pending' or 'succeeded' attempt exists — never start another charge. */
    private function hasOpenAttempt(int $invoiceId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM autopay_attempts
            WHERE invoice_id = ? AND status IN ('pending', 'succeeded')
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        return (bool) $stmt->fetch();
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
        // 3DS can only be completed by whoever HOLDS the card, so this notice
        // follows the payment source — NOT the invoice's billing recipient.
        // Deliberate, and easy to "correct" wrongly: reminders were rerouted to
        // the management firm's accounts inbox 2026-09-25, and this email looks
        // like the same bug. It isn't. Sending a strata's property manager a
        // prompt to authenticate a council member's personal card would be both
        // useless and a disclosure of that person's card activity to a third
        // party. What WAS wrong: a company-card charge still notified the
        // individual contact, who may not hold that card at all.
        if (($data['payment_source'] ?? 'contact') === 'company') {
            $email     = $data['co_billing_contact_email'] ?: ($data['co_billing_email'] ?: null);
            $firstName = $data['co_billing_contact_first']
                ?: ($data['co_company_name'] ? $data['co_company_name'] . ' accounts' : 'there');
        } else {
            $email     = $data['contact_email'] ?? null;
            $firstName = $data['contact_first'] ?? 'there';
        }
        if (!$email) {
            // No way to reach the cardholder — tell the office rather than
            // emailing the wrong person or failing in silence.
            error_log("[autopay] 3DS required on invoice {$invoiceId} but no cardholder email (source: "
                      . ($data['payment_source'] ?? 'contact') . ')');
            return;
        }

        $portalUrl = _idSiteUrl() . '/customer/invoice.php?token=' . urlencode($data['access_token'] ?? '');

        $subject = 'Action required: please complete payment for Invoice ' . htmlspecialchars($data['invoice_number'], ENT_QUOTES);

        $body = '
        <p>Hi ' . htmlspecialchars($firstName, ENT_QUOTES) . ',</p>
        <p>We tried to process your invoice automatically, but your bank requires you to confirm the payment.</p>
        <p><strong>This only takes a moment.</strong> Please click the link below to complete the payment:</p>
        <p><a href="' . $portalUrl . '" style="background:#2D8659;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Complete Payment</a></p>
        <p>Invoice: ' . htmlspecialchars($data['invoice_number'], ENT_QUOTES) . '</p>
        <p>If you have questions, ' . (_idPhone() !== '' ? 'call us at ' . htmlspecialchars(_idPhone()) . '.' : 'reply to this email.') . '</p>
        <p>— ' . htmlspecialchars(_idCompanyInfo()['company_name']) . '</p>';

        // Every customer email about an invoice carries the invoice PDF (owner rule
        // 2026-10-06). No PDF → this notice is held and recorded; Charlie emails the
        // owner (who also gets the authentication_required payment alert).
        require_once APP_ROOT . '/Modules/Invoices/Services/InvoicePdfGate.php';
        $pdfGate    = new InvoicePdfGate($this->db);
        $attachPath = $pdfGate->ensurePdf($invoiceId);
        if ($attachPath === null || !$pdfGate->isUsable($attachPath)) {
            $pdfGate->recordBlocked($invoiceId, 'autopay_auth_notice');
            error_log("[autopay] 3DS notice for invoice {$invoiceId} NOT sent — " . InvoicePdfGate::NOT_SENT_MESSAGE);
            return;
        }

        sendEmail($email, $subject, $body, $attachPath);
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
                "SELECT admin_email FROM business_settings LIMIT 1"
            )->fetch(\PDO::FETCH_ASSOC);
            $adminEmail = !empty($adminEmailRow['admin_email']) ? $adminEmailRow['admin_email'] : _idReplyTo();
        } catch (\Throwable $e) {
            $adminEmail = _idReplyTo();
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
