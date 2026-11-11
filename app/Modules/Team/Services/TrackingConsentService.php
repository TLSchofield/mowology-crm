<?php
declare(strict_types=1);

/**
 * TrackingConsentService — the record that an employee was TOLD what location
 * tracking does and AGREED to it.
 *
 * Until 2026-09-19 there was none: users.location_tracking_enabled is an admin
 * toggle the employee never touches. BC PIPA s.10/13 and PIPEDA require notice
 * of what is collected, why, and who sees it before collecting it; Apple and
 * Google both require an in-app disclosure before background location.
 *
 * The disclosure text lives HERE, versioned, and is served to every client so
 * iOS, Android and web show identical wording. Changing what is collected or
 * who sees it means bumping DISCLOSURE_VERSION — which makes every existing
 * consent stale and re-prompts everyone.
 *
 * Global-namespace, no autoloader: require_once, then `new TrackingConsentService($db)`.
 */
class TrackingConsentService
{
    public const DISCLOSURE_VERSION = '2026-09-v1';

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The disclosure, as structured sections so each client can lay it out natively.
     * $businessName comes from business_settings — never hardcoded (multi-tenant).
     * PURE.
     */
    public static function disclosure(string $businessName, int $retentionDays = 90, int $visitRetentionDays = 180): array
    {
        $biz = trim($businessName) !== '' ? trim($businessName) : 'your employer';
        return [
            'version'  => self::DISCLOSURE_VERSION,
            'title'    => 'Location tracking while you work',
            'summary'  => "{$biz} records your phone's location while you are clocked in, and only then.",
            'sections' => [
                [
                    'heading' => 'When',
                    'body'    => 'Only between clock-in and clock-out. Tracking stops when you clock out, when you are '
                               . 'clocked out automatically or by the office, and when you sign out. It runs in the '
                               . 'background with the screen off, so the app asks for "Always" location access.',
                ],
                [
                    'heading' => 'What',
                    'body'    => 'Your position, its accuracy, speed and the time. Between jobs this is recorded about once '
                               . 'a minute. On a client property it is recorded more often and more precisely, to show '
                               . 'when you arrived, where work was done, and when you left.',
                ],
                [
                    'heading' => 'Why',
                    'body'    => 'To start and stop job timers automatically, to pay you accurately, to dispatch the nearest '
                               . 'crew, and to prove that a service was carried out. For salting and snow removal that '
                               . 'record is what protects you and the company if someone later claims a property was not treated.',
                ],
                [
                    'heading' => 'Who sees it',
                    'body'    => 'Managers and office staff see crew locations and routes. Clients can see the route covered '
                               . 'on their own property for a visit as proof of service. Clients are never shown your name '
                               . 'or which crew member it was. You can see your own route in the app.',
                ],
                [
                    'heading' => 'How long',
                    'body'    => "Location history is deleted after {$retentionDays} days. The route attached to a completed "
                               . "visit is kept for {$visitRetentionDays} days because it is part of that visit's service record.",
                ],
                [
                    'heading' => 'Your choices',
                    'body'    => 'You can ask the office for a copy of your location data at any time. You can withdraw this '
                               . 'consent in the app; location tracking is a requirement of field roles, so talk to your '
                               . 'manager first. Nothing is recorded while you are off the clock.',
                ],
            ],
            'agree_label' => 'I understand and agree',
        ];
    }

    /** A consent counts only if it is for the CURRENT disclosure and has not been withdrawn. PURE. */
    public static function isCurrent(?array $row): bool
    {
        return $row !== null
            && ($row['disclosure_version'] ?? null) === self::DISCLOSURE_VERSION
            && empty($row['withdrawn_at']);
    }

    public function latest(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, disclosure_version, consented_at, withdrawn_at, platform
                FROM tracking_consents WHERE user_id = ? ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;   // migration 1116 not run yet
        }
    }

    public function hasCurrentConsent(int $userId): bool
    {
        return self::isCurrent($this->latest($userId));
    }

    /** Idempotent: agreeing twice to the same version keeps the original timestamp. */
    public function record(int $userId, string $version, array $context = []): bool
    {
        if ($version !== self::DISCLOSURE_VERSION) {
            throw new InvalidArgumentException('That disclosure is out of date — reload and review the current one.');
        }
        if ($this->hasCurrentConsent($userId)) {
            return true;
        }
        $clip = static fn ($v, int $n) => isset($v) && $v !== '' ? substr((string)$v, 0, $n) : null;
        $this->db->prepare("
            INSERT INTO tracking_consents (user_id, disclosure_version, consented_at, platform, device_id, app_version, ip_address)
            VALUES (?, ?, NOW(), ?, ?, ?, ?)
        ")->execute([
            $userId, $version,
            $clip($context['platform'] ?? null, 16), $clip($context['device_id'] ?? null, 64),
            $clip($context['app_version'] ?? null, 24), $clip($context['ip'] ?? null, 45),
        ]);
        return true;
    }

    public function withdraw(int $userId): void
    {
        $this->db->prepare("UPDATE tracking_consents SET withdrawn_at = NOW() WHERE user_id = ? AND withdrawn_at IS NULL")
                 ->execute([$userId]);
    }
}
