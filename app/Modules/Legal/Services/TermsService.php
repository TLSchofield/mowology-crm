<?php
declare(strict_types=1);

/**
 * TermsService
 *
 * Platform Terms of Service acceptance.
 *   - currentVersion()  — the active version, or null when the gate is off
 *   - needsAcceptance() — true when a version is active and the user has not accepted it
 *   - recordAcceptance() — idempotent; stores IP and user agent as evidence
 *
 * The gate is OFF until a terms_versions row has is_current = 1 (migration 1381), so
 * deploying the code never locks anyone out before the text has been reviewed.
 * Fails open: if the tables are missing or unreadable, nobody is blocked from signing in.
 *
 * No namespace — loaded via require_once (consistent with other services).
 */
class TermsService
{
    public const DOC_KEY = 'platform';

    public function __construct(private PDO $db) {}

    public function currentVersion(): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT version FROM terms_versions WHERE doc_key = ? AND is_current = 1 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([self::DOC_KEY]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            error_log('TermsService::currentVersion: ' . $e->getMessage());
            return null;
        }
    }

    public function hasAccepted(int $userId, string $version): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM terms_acceptances WHERE user_id = ? AND doc_key = ? AND version = ? LIMIT 1"
        );
        $stmt->execute([$userId, self::DOC_KEY, $version]);
        return $stmt->fetchColumn() !== false;
    }

    public function needsAcceptance(int $userId): bool
    {
        try {
            $version = $this->currentVersion();
            if ($version === null) {
                return false;
            }
            return !$this->hasAccepted($userId, $version);
        } catch (Throwable $e) {
            error_log('TermsService::needsAcceptance: ' . $e->getMessage());
            return false;
        }
    }

    /** Records acceptance of the current version. Returns false when no version is active. */
    public function recordAcceptance(int $userId, ?string $ip, ?string $userAgent): bool
    {
        $version = $this->currentVersion();
        if ($version === null) {
            return false;
        }
        if ($this->hasAccepted($userId, $version)) {
            return true;
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO terms_acceptances (user_id, doc_key, version, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                self::DOC_KEY,
                $version,
                $ip !== null ? substr($ip, 0, 45) : null,
                $userAgent !== null ? substr($userAgent, 0, 255) : null,
            ]);
        } catch (PDOException $e) {
            // A double-submit loses the race on the unique key: the acceptance exists, so that is success.
            if (!$this->hasAccepted($userId, $version)) {
                throw $e;
            }
        }
        return true;
    }
}
