<?php
declare(strict_types=1);

/**
 * ContractService
 *
 * Business logic for contract e-signature and version history.
 * Extracted from (and extending) the inline logic in
 * public/crm/contracts/view.php and CrmFunctions contract helpers.
 *
 * No namespace — loaded via require_once (consistent with other services).
 */
class ContractService
{
    /** Signature link expiry in days. */
    private const TOKEN_EXPIRY_DAYS = 14;

    public function __construct(private PDO $db) {}

    // ─────────────────────────────────────────────────────────────────────────
    // E-SIGNATURE — Send
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a contract for digital signature.
     *
     * - Creates a version snapshot (if none exists yet)
     * - Expires any currently pending request for this contract
     * - Generates a new signature token
     * - Inserts a contract_signatures row
     * - Updates contracts.signature_status = 'pending'
     *
     * Returns the token (to be embedded in the emailed URL).
     *
     * @throws \RuntimeException if the contract is already signed
     */
    public function sendForSignature(
        int    $contractId,
        string $signerName,
        string $signerEmail,
        int    $userId
    ): string {
        $contract = $this->getContract($contractId);
        if (!$contract) {
            throw new \InvalidArgumentException("Contract {$contractId} not found");
        }
        if ($contract['signature_status'] === 'signed') {
            throw new \RuntimeException(
                "Contract {$contract['contract_number']} is already signed. " .
                "To amend it, create a new version first."
            );
        }

        // Snapshot version 1 if this is the first signature request
        $version = (int) ($contract['current_version'] ?? 1);
        if ($this->getVersionCount($contractId) === 0) {
            $this->snapshotVersion($contractId, $userId, 'Initial signature request');
        }

        // Expire any existing pending request
        $this->expirePendingRequests($contractId);

        $token     = $this->generateToken();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_EXPIRY_DAYS . ' days'));

        $this->db->prepare("
            INSERT INTO contract_signatures
                (contract_id, contract_version, signature_token, token_expires_at,
                 signer_name, signer_email, status, sent_by)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ")->execute([$contractId, $version, $token, $expiresAt, $signerName, $signerEmail, $userId]);

        $this->db->prepare(
            "UPDATE contracts SET signature_status = 'pending', updated_at = NOW() WHERE id = ?"
        )->execute([$contractId]);

        return $token;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E-SIGNATURE — Verify token (customer-facing)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Look up a signature request by token.
     * Returns null if token is invalid, expired, or already used.
     */
    public function getSignatureRequest(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT cs.*,
                   c.contract_number, c.title, c.billing_cycle, c.billing_amount,
                   c.invoice_timing, c.start_date, c.end_date, c.auto_renew,
                   c.renewal_date, c.renewal_increase_pct, c.notes,
                   c.property_id, c.contact_id,
                   p.address AS property_address, p.city AS property_city,
                   p.province AS property_province, p.postal_code AS property_postal,
                   ct.first_name, ct.last_name, ct.email AS contact_email
            FROM contract_signatures cs
            JOIN contracts c  ON c.id  = cs.contract_id
            JOIN properties p ON p.id  = c.property_id
            JOIN contacts ct  ON ct.id = c.contact_id
            WHERE cs.signature_token = ?
              AND cs.status = 'pending'
              AND cs.token_expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E-SIGNATURE — Record
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a successful signature.
     *
     * @param string $signatureData base64 PNG from SignaturePad canvas
     * @return bool true if saved; false if token was invalid/already used
     */
    public function recordSignature(string $token, string $signatureData, string $signerIp): bool
    {
        $row = $this->getSignatureRequest($token);
        if (!$row) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');

            $this->db->prepare("
                UPDATE contract_signatures
                SET status = 'signed', signature_data = ?, signed_at = ?, signed_ip = ?
                WHERE signature_token = ?
            ")->execute([$signatureData, $now, $signerIp, $token]);

            $this->db->prepare("
                UPDATE contracts
                SET signature_status = 'signed', updated_at = NOW()
                WHERE id = ?
            ")->execute([$row['contract_id']]);

            // Seal the version snapshot with signature info
            $this->db->prepare("
                UPDATE contract_versions
                SET signature_status = 'signed', signed_at = ?, signed_by_name = ?
                WHERE contract_id = ? AND version_number = ?
            ")->execute([$now, $row['signer_name'], $row['contract_id'], $row['contract_version']]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E-SIGNATURE — Decline
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a client-initiated decline.
     *
     * @return bool true if recorded; false if token invalid
     */
    public function declineSignature(string $token, string $reason, string $signerIp): bool
    {
        // We can decline even after expiry (client may load the page just before expiry)
        $stmt = $this->db->prepare("
            SELECT cs.contract_id, c.signature_status
            FROM contract_signatures cs
            JOIN contracts c ON c.id = cs.contract_id
            WHERE cs.signature_token = ? AND cs.status = 'pending'
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->prepare("
            UPDATE contract_signatures
            SET status = 'declined', decline_reason = ?, declined_at = ?, declined_ip = ?
            WHERE signature_token = ?
        ")->execute([$reason, $now, $signerIp, $token]);

        $this->db->prepare(
            "UPDATE contracts SET signature_status = 'declined', updated_at = NOW() WHERE id = ?"
        )->execute([$row['contract_id']]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // E-SIGNATURE — Revoke (admin)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Admin revokes a pending signature request.
     * Sets contract back to 'unsigned' so it can be resent or edited.
     */
    public function revokeSignatureRequest(int $contractId): void
    {
        $this->expirePendingRequests($contractId);
        $this->db->prepare(
            "UPDATE contracts SET signature_status = 'unsigned', updated_at = NOW() WHERE id = ?"
        )->execute([$contractId]);
    }

    /**
     * Get the current pending signature request for a contract, or null.
     */
    public function getActiveSignatureRequest(int $contractId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM contract_signatures
            WHERE contract_id = ? AND status = 'pending' AND token_expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$contractId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get all signature history for a contract (most recent first).
     */
    public function getSignatureHistory(int $contractId): array
    {
        $stmt = $this->db->prepare("
            SELECT cs.*, u.full_name AS sent_by_name
            FROM contract_signatures cs
            LEFT JOIN users u ON u.id = cs.sent_by
            WHERE cs.contract_id = ?
            ORDER BY cs.created_at DESC
        ");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERSIONING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Snapshot the current contract state as a new version.
     *
     * Called automatically:
     *   - When the first signature request is sent (creates v1)
     *   - When a signed contract is amended (creates v2, v3, …)
     */
    public function snapshotVersion(int $contractId, int $userId, string $reason = ''): void
    {
        $contract = $this->getContract($contractId);
        if (!$contract) {
            throw new \InvalidArgumentException("Contract {$contractId} not found");
        }

        $nextVersion = $this->getVersionCount($contractId) + 1;

        $this->db->prepare("
            INSERT INTO contract_versions
                (contract_id, version_number, changed_by, change_reason,
                 title, billing_cycle, billing_amount, invoice_timing,
                 start_date, end_date, renewal_date, auto_renew,
                 renewal_increase_pct, notes, signature_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $contractId,
            $nextVersion,
            $userId,
            $reason,
            $contract['title'],
            $contract['billing_cycle'],
            $contract['billing_amount'],
            $contract['invoice_timing'] ?? null,
            $contract['start_date'],
            $contract['end_date'],
            $contract['renewal_date'],
            $contract['auto_renew'],
            $contract['renewal_increase_pct'] ?? 0,
            $contract['notes'],
            $contract['signature_status'],
        ]);

        $this->db->prepare(
            "UPDATE contracts SET current_version = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$nextVersion, $contractId]);
    }

    /**
     * Bump to a new version when a previously-signed contract is amended.
     * Resets signature_status to 'unsigned' so it must be re-signed.
     */
    public function amendContract(int $contractId, int $userId, string $reason): void
    {
        $contract = $this->getContract($contractId);
        if (!$contract) {
            throw new \InvalidArgumentException("Contract {$contractId} not found");
        }

        $this->snapshotVersion($contractId, $userId, $reason);

        // Reset signature status so the new version needs re-signing
        $this->db->prepare(
            "UPDATE contracts SET signature_status = 'unsigned', updated_at = NOW() WHERE id = ?"
        )->execute([$contractId]);
    }

    /**
     * Get all version history for a contract (most recent first).
     */
    public function getVersions(int $contractId): array
    {
        $stmt = $this->db->prepare("
            SELECT cv.*, u.full_name AS changed_by_name
            FROM contract_versions cv
            LEFT JOIN users u ON u.id = cv.changed_by
            WHERE cv.contract_id = ?
            ORDER BY cv.version_number DESC
        ");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BILLING ELIGIBILITY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * True if the given job_plan is currently billed under a formal, active
     * contract (i.e. NOT per-visit). Source of truth is job_plans.contract_id
     * joined to the live contracts row — NOT job_plans.pricing_model, which
     * can go stale relative to the contract (e.g. a plan created before the
     * contract's billing_cycle changed, or before pricing_model was correctly
     * derived at creation time).
     *
     * A plan is contract-billed only while the contract is 'active' and its
     * billing_cycle isn't itself 'per_visit' — a paused/cancelled contract or
     * a per-visit-billed contract must allow normal per-visit invoicing.
     */
    public function isPlanContractBilled(int $planId): bool
    {
        if ($planId < 1) return false;

        $stmt = $this->db->prepare("
            SELECT c.status, c.billing_cycle
            FROM job_plans jp
            JOIN contracts c ON jp.contract_id = c.id
            WHERE jp.id = ?
        ");
        $stmt->execute([$planId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return false; // no contract_id, or contract row missing

        return $row['status'] === 'active' && $row['billing_cycle'] !== 'per_visit';
    }

    /**
     * Batch version of isPlanContractBilled() to avoid N+1 queries.
     *
     * @param int[] $planIds
     * @return array<int,bool> plan_id => is contract-billed
     */
    public function getContractBilledPlanIds(array $planIds): array
    {
        $planIds = array_values(array_unique(array_filter(array_map('intval', $planIds))));
        $result  = array_fill_keys($planIds, false);
        if (!$planIds) return $result;

        $placeholders = implode(',', array_fill(0, count($planIds), '?'));
        $stmt = $this->db->prepare("
            SELECT jp.id AS plan_id, c.status, c.billing_cycle
            FROM job_plans jp
            JOIN contracts c ON jp.contract_id = c.id
            WHERE jp.id IN ($placeholders)
        ");
        $stmt->execute($planIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['plan_id']] = ($row['status'] === 'active' && $row['billing_cycle'] !== 'per_visit');
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Fetch a contract row (basic fields). */
    private function getContract(int $contractId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getVersionCount(int $contractId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM contract_versions WHERE contract_id = ?"
        );
        $stmt->execute([$contractId]);
        return (int) $stmt->fetchColumn();
    }

    private function expirePendingRequests(int $contractId): void
    {
        $this->db->prepare("
            UPDATE contract_signatures
            SET status = 'expired'
            WHERE contract_id = ? AND status = 'pending'
        ")->execute([$contractId]);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }
}
