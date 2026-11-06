<?php
/**
 * InvoiceMoveService — move an unpaid invoice to another job (and optionally another completed visit).
 *
 *  - candidates() → jobs the invoice could move to, with their unbilled completed visits
 *  - preview()    → read-only: every check, plus from/to, bill-to change and recipients before/after
 *  - move()       → the same checks, then one transaction that re-links visit, lines, invoice and (drafts) recipients
 *
 * Not responsible for auth (the API controller does it) or re-sending the invoice (the owner resends after moving).
 * Depends on resolveManagementBillingRecipient() (InvoiceRouting.php) unless a resolver is injected (tests).
 */
declare(strict_types=1);

class InvoiceMoveService
{
    private const MOVABLE = ['draft', 'sent', 'viewed', 'overdue'];
    private const LIMIT   = 30;

    private PDO $db;
    /** @var callable|null (int $propertyId): array — tests only; default resolveManagementBillingRecipient */
    private $pmResolver;

    public function __construct(PDO $db, ?callable $pmResolver = null)
    {
        $this->db = $db;
        $this->pmResolver = $pmResolver;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CANDIDATES
    // ══════════════════════════════════════════════════════════════════════════

    public function candidates(int $invoiceId, string $search = ''): array
    {
        $inv = $this->invoice($invoiceId);
        if (!$inv) {
            return [];
        }
        $propertyId = (int)($inv['property_id'] ?? 0);
        $companyId  = (int)($inv['company_id'] ?? 0);
        $curPlan    = (int)($inv['plan_id'] ?? 0);
        $search     = trim($search);
        // Same street address ranks right after the same building: one address can hold two
        // properties with different billing (Shannon Station: commercial #28, strata #1528).
        $address    = '';
        if ($propertyId > 0) {
            $a = $this->db->prepare("SELECT address FROM properties WHERE id = ?");
            $a->execute([$propertyId]);
            $address = strtolower(trim((string)($a->fetchColumn() ?: '')));
        }
        $order = " ORDER BY (jp.property_id = ?) DESC, (LOWER(TRIM(pr.address)) = ?) DESC, jp.id DESC LIMIT " . self::LIMIT;

        $sql = "SELECT jp.id, jp.plan_number, jp.title, jp.property_id,
                       pr.address, pr.city
                FROM job_plans jp
                LEFT JOIN properties pr ON pr.id = jp.property_id
                WHERE jp.status <> 'cancelled' AND jp.id <> ?";
        $args = [$curPlan];
        if (strlen($search) >= 2) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $sql .= " AND (jp.plan_number LIKE ? OR jp.title LIKE ?)";
            $args[] = $like;
            $args[] = $like;
            $sql .= $order;
            array_push($args, $propertyId, $address !== '' ? $address : '__no_address__');
        } else {
            $sql .= " AND (jp.property_id = ? OR jp.company_id = ? OR pr.property_manager_id = ? OR LOWER(TRIM(pr.address)) = ?)";
            array_push($args, $propertyId, $companyId, $companyId, $address !== '' ? $address : '__no_address__');
            $sql .= $order;
            array_push($args, $propertyId, $address !== '' ? $address : '__no_address__');
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);

        $vis = $this->db->prepare("
            SELECT id, visit_number, scheduled_date, status
            FROM job_visits
            WHERE plan_id = ? AND status = 'completed' AND (invoice_id IS NULL OR invoice_id = 0)
            ORDER BY scheduled_date DESC, id DESC");
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vis->execute([(int)$r['id']]);
            $visits = [];
            foreach ($vis->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $visits[] = [
                    'visit_id'       => (int)$v['id'],
                    'visit_number'   => (string)$v['visit_number'],
                    'scheduled_date' => $v['scheduled_date'],
                    'status'         => (string)$v['status'],
                ];
            }
            $out[] = [
                'plan_id'        => (int)$r['id'],
                'plan_number'    => (string)$r['plan_number'],
                'title'          => (string)$r['title'],
                'property_id'    => (int)$r['property_id'],
                'property_label' => $this->label($r['address'] ?? '', $r['city'] ?? ''),
                'same_property'  => $propertyId > 0 && (int)$r['property_id'] === $propertyId,
                'same_address'   => $address !== '' && strtolower(trim((string)($r['address'] ?? ''))) === $address,
                'visits'         => $visits,
            ];
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PREVIEW
    // ══════════════════════════════════════════════════════════════════════════

    public function preview(int $invoiceId, int $targetPlanId, ?int $targetVisitId): array
    {
        $ctx = $this->check($invoiceId, $targetPlanId, $targetVisitId);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'error' => $ctx['error']];
        }
        $inv = $ctx['invoice'];
        $plan = $ctx['plan'];
        $before = $this->recipientEmails($invoiceId);
        $after = $before;
        $warnings = [];
        $propChange = $ctx['property_changes'];

        if ($propChange && $inv['status'] === 'draft') {
            $pm = $this->pmRecipients((int)$plan['property_id']);
            if ($pm) {
                $after = $this->emails($pm);
            }
        }
        if ($inv['status'] !== 'draft' && $before) {
            $warnings[] = 'Already sent to ' . implode(', ', $before)
                . ' — resend it after moving so they get the corrected copy.';
        }
        if ($propChange) {
            $warnings[] = 'The service address changes to the new job\'s property.';
        }
        $warnings[] = 'The saved PDF is cleared and will be regenerated.';

        return [
            'ok'                    => true,
            'from'                  => $this->side($ctx['from_plan'], $ctx['from_visit'], (int)$inv['property_id']),
            'to'                    => $this->side($plan, $ctx['to_visit'], (int)$plan['property_id']),
            'property_changes'      => $propChange,
            'recipients_before'     => $before,
            'recipients_after'      => $after,
            'recipients_will_change' => $before !== $after,
            'warnings'              => $warnings,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MOVE
    // ══════════════════════════════════════════════════════════════════════════

    public function move(int $invoiceId, int $targetPlanId, ?int $targetVisitId, int $userId): array
    {
        $ctx = $this->check($invoiceId, $targetPlanId, $targetVisitId);
        if (isset($ctx['error'])) {
            return ['ok' => false, 'error' => $ctx['error']];
        }

        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lock  = $mysql ? ' FOR UPDATE' : '';
        $newVisit = $targetVisitId && $targetVisitId > 0 ? $targetVisitId : null;

        $this->db->beginTransaction();
        try {
            // Re-check under row locks: the invoice (paid meanwhile?) and the target visit (invoiced elsewhere?).
            $ctx = $this->check($invoiceId, $targetPlanId, $targetVisitId, $lock);
            if (isset($ctx['error'])) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => $ctx['error']];
            }
            $inv  = $ctx['invoice'];
            $plan = $ctx['plan'];
            $oldVisit = (int)($inv['visit_id'] ?? 0);

            // 1. Old visit goes back to unbilled.
            if ($oldVisit > 0) {
                $this->db->prepare("UPDATE job_visits SET invoice_id = NULL, is_invoiced = 0 WHERE id = ? AND invoice_id = ?")
                    ->execute([$oldVisit, $invoiceId]);
            }
            // 2. New visit is claimed.
            if ($newVisit !== null) {
                $claim = $this->db->prepare("UPDATE job_visits SET invoice_id = ?, is_invoiced = 1
                                             WHERE id = ? AND (invoice_id IS NULL OR invoice_id = 0)");
                $claim->execute([$invoiceId, $newVisit]);
                if ($claim->rowCount() !== 1) {
                    $this->db->rollBack();
                    return ['ok' => false, 'error' => 'That visit was just invoiced by someone else.'];
                }
            }
            // 3. Lines tied to the old visit (or to none) follow; lines on other visits stay.
            $lineWhere = $oldVisit > 0 ? "(visit_id = ? OR visit_id IS NULL)" : "(visit_id IS NULL OR visit_id = 0)";
            $set = $newVisit !== null ? "visit_id = ?, service_date = ?" : "visit_id = ?";
            $this->db->prepare("UPDATE invoice_line_items SET {$set} WHERE invoice_id = ? AND {$lineWhere}")
                ->execute($this->lineArgs($newVisit, $ctx, $invoiceId, $oldVisit));

            // 4. The invoice itself.
            $propChange = $ctx['property_changes'];
            $cols = "plan_id = ?, visit_id = ?, property_id = ?, company_id = ?, contract_id = ?";
            $args = [
                $targetPlanId,
                $newVisit,
                (int)$plan['property_id'] ?: null,
                ((int)($plan['company_id'] ?? 0) ?: (int)($inv['company_id'] ?? 0)) ?: null,
                (int)($plan['contract_id'] ?? 0) ?: null,
            ];
            if ($propChange) {
                $cols .= ", service_address = ?, service_city = ?, service_province = ?, service_postal_code = ?";
                $args[] = $plan['address'] ?? null;
                $args[] = $plan['city'] ?? null;
                $args[] = $plan['province'] ?? null;
                $args[] = $plan['postal_code'] ?? null;
            }
            $cols .= ", pdf_path = NULL, pdf_version = 0, pdf_generated_at = NULL";
            $args[] = $invoiceId;
            $this->db->prepare("UPDATE invoices SET {$cols} WHERE id = ?")->execute($args);

            // 5. Draft recipients follow the new building's billing rule; a sent invoice is never touched.
            if ($propChange && $inv['status'] === 'draft') {
                $pm = $this->pmRecipients((int)$plan['property_id']);
                if ($pm) {
                    $this->db->prepare("DELETE FROM invoice_contacts WHERE invoice_id = ?")->execute([$invoiceId]);
                    $ins = $this->db->prepare("INSERT INTO invoice_contacts (invoice_id, contact_id, contact_role, email_address) VALUES (?, ?, ?, ?)");
                    foreach ($pm as $r) {
                        $ins->execute([$invoiceId, !empty($r['contact_id']) ? (int)$r['contact_id'] : null,
                                       $r['contact_role'] ?: 'billing_contact', $r['email_address']]);
                    }
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[InvoiceMoveService] move failed for invoice ' . $invoiceId . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not move the invoice. Nothing was changed.'];
        }

        // 6. Activity log — never blocks the move.
        $fromTxt = $ctx['from_plan']['plan_number'] . ($ctx['from_visit'] ? '/' . $ctx['from_visit']['visit_number'] : '');
        $toTxt   = $plan['plan_number'] . ($ctx['to_visit'] ? '/' . $ctx['to_visit']['visit_number'] : '');
        $message = $inv['invoice_number'] . ' moved from ' . $fromTxt . ' to ' . $toTxt;
        if (function_exists('logActivityExtended')) {
            try {
                logActivityExtended($userId, 'Invoice moved', $message, (int)($inv['company_id'] ?? 0) ?: null,
                    null, null, $invoiceId, $targetPlanId, $newVisit);
            } catch (Throwable $e) {
                error_log('[InvoiceMoveService] activity log failed: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'invoice_id' => $invoiceId, 'message' => $message];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // INTERNALS
    // ══════════════════════════════════════════════════════════════════════════

    /** Argument list for the line-item UPDATE, in the order the placeholders appear. */
    private function lineArgs(?int $newVisit, array $ctx, int $invoiceId, int $oldVisit): array
    {
        $args = [$newVisit];
        if ($newVisit !== null) {
            $args[] = $ctx['to_visit']['scheduled_date'] ?? null;
        }
        $args[] = $invoiceId;
        if ($oldVisit > 0) {
            $args[] = $oldVisit;
        }
        return $args;
    }

    private function invoice(int $invoiceId, string $lock = ''): ?array
    {
        $s = $this->db->prepare("SELECT * FROM invoices WHERE id = ?" . $lock);
        $s->execute([$invoiceId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function planRow(int $planId): ?array
    {
        $s = $this->db->prepare("
            SELECT jp.id, jp.plan_number, jp.title, jp.status, jp.property_id, jp.company_id, jp.contract_id,
                   pr.address, pr.city, pr.province, pr.postal_code
            FROM job_plans jp LEFT JOIN properties pr ON pr.id = jp.property_id
            WHERE jp.id = ?");
        $s->execute([$planId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function visitRow(int $visitId, string $lock = ''): ?array
    {
        $s = $this->db->prepare("SELECT id, visit_number, plan_id, status, scheduled_date, invoice_id FROM job_visits WHERE id = ?" . $lock);
        $s->execute([$visitId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Every refusal rule. Returns ['error' => msg] or the context the writes need.
     * $lock is ' FOR UPDATE' inside the transaction (MySQL only).
     */
    private function check(int $invoiceId, int $targetPlanId, ?int $targetVisitId, string $lock = ''): array
    {
        $inv = $this->invoice($invoiceId, $lock);
        if (!$inv) {
            return ['error' => 'Invoice not found.'];
        }
        $status = (string)$inv['status'];
        if (round((float)($inv['amount_paid'] ?? 0), 2) > 0 || !in_array($status, self::MOVABLE, true)) {
            return ['error' => 'Paid or part-paid invoices can\'t move — refund or cancel first.'];
        }
        if (!empty($inv['stripe_payment_intent_id']) && $status !== 'draft') {
            return ['error' => 'An online payment may be in progress for this invoice, so it can\'t move right now.'];
        }

        $plan = $targetPlanId > 0 ? $this->planRow($targetPlanId) : null;
        if (!$plan) {
            return ['error' => 'That job was not found.'];
        }
        if ((string)$plan['status'] === 'cancelled') {
            return ['error' => 'That job is cancelled.'];
        }

        $curPlan  = (int)($inv['plan_id'] ?? 0);
        $curVisit = (int)($inv['visit_id'] ?? 0);
        $newVisit = $targetVisitId && $targetVisitId > 0 ? $targetVisitId : 0;
        if ($targetPlanId === $curPlan && $newVisit === $curVisit) {
            return ['error' => 'The invoice is already on that job and visit.'];
        }

        $toVisit = null;
        if ($newVisit > 0) {
            $toVisit = $this->visitRow($newVisit, $lock);
            if (!$toVisit || (int)$toVisit['plan_id'] !== $targetPlanId) {
                return ['error' => 'That visit does not belong to the chosen job.'];
            }
            if ((string)$toVisit['status'] !== 'completed') {
                return ['error' => 'Only completed visits can be invoiced.'];
            }
            if ((int)($toVisit['invoice_id'] ?? 0) > 0) {
                return ['error' => 'That visit already has an invoice.'];
            }
        }

        if (!empty($plan['contract_id'])) {
            if (!class_exists('ContractService')) {
                require_once dirname(__DIR__, 2) . '/Contracts/Services/ContractService.php';
            }
            if ((new ContractService($this->db))->isPlanContractBilled($targetPlanId)) {
                return ['error' => 'That job is billed under an active contract, not per-visit. '
                                 . 'Billing happens automatically through the contract.'];
            }
        }

        $fromPlan = $curPlan > 0 ? ($this->planRow($curPlan) ?: ['plan_number' => '', 'title' => '', 'property_id' => $inv['property_id']]) :
                    ['plan_number' => '', 'title' => '', 'property_id' => $inv['property_id']];
        $fromVisit = $curVisit > 0 ? $this->visitRow($curVisit) : null;

        return [
            'invoice'          => $inv,
            'plan'             => $plan,
            'to_visit'         => $toVisit,
            'from_plan'        => $fromPlan,
            'from_visit'       => $fromVisit,
            'property_changes' => (int)$plan['property_id'] > 0 && (int)$plan['property_id'] !== (int)($inv['property_id'] ?? 0),
        ];
    }

    private function side(array $plan, ?array $visit, int $propertyId): array
    {
        $label = '';
        if ($propertyId > 0) {
            $s = $this->db->prepare("SELECT address, city FROM properties WHERE id = ?");
            $s->execute([$propertyId]);
            $p = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $label = $this->label($p['address'] ?? '', $p['city'] ?? '');
        }
        return [
            'plan_number'    => (string)($plan['plan_number'] ?? ''),
            'title'          => (string)($plan['title'] ?? ''),
            'visit_number'   => $visit ? (string)$visit['visit_number'] : '',
            'property_label' => $label,
        ];
    }

    private function label($address, $city): string
    {
        return trim((string)$address . ((string)$city !== '' ? ', ' . $city : ''), ', ');
    }

    private function recipientEmails(int $invoiceId): array
    {
        $s = $this->db->prepare("SELECT email_address FROM invoice_contacts WHERE invoice_id = ? AND email_address IS NOT NULL AND email_address <> '' ORDER BY id");
        $s->execute([$invoiceId]);
        return array_values(array_unique(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function emails(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['email_address'])) {
                $out[] = (string)$r['email_address'];
            }
        }
        return array_values(array_unique($out));
    }

    private function pmRecipients(int $propertyId): array
    {
        if ($propertyId <= 0) {
            return [];
        }
        try {
            if ($this->pmResolver) {
                return (array)call_user_func($this->pmResolver, $propertyId);
            }
            require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceRouting.php';
            return resolveManagementBillingRecipient($propertyId);
        } catch (Throwable $e) {
            error_log('[InvoiceMoveService] PM routing failed for property ' . $propertyId . ': ' . $e->getMessage());
            return [];
        }
    }
}
