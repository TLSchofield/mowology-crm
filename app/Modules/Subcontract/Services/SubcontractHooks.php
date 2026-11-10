<?php
declare(strict_types=1);

/**
 * SubcontractHooks — what a subcontract step does to the rest of the system: the visit and the owner's phone.
 * One method per effect so a test can subclass and count the calls.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class SubcontractHooks
{
    public function visitInProgress(int $visitId, int $ownerId): void
    {
        $this->lifecycle();
        VisitLifecycleService::updateVisitStatus($visitId, 'in_progress', $ownerId, null);
    }

    public function moveVisit(int $visitId, string $date, int $ownerId): bool
    {
        $this->lifecycle();
        return VisitLifecycleService::moveVisit($visitId, $date, null, $ownerId);
    }

    /** Runs only after the owner accepts the work: completing a visit can email the client a review request. */
    public function completeVisit(int $visitId, int $userId): bool
    {
        $this->lifecycle();
        return VisitLifecycleService::updateVisitStatus($visitId, 'completed', $userId, null);
    }

    /** Best-effort: a push that fails must never undo the step she just recorded. */
    public function push(array $userIds, string $title, string $body, array $data): void
    {
        try {
            require_once APP_ROOT . '/Services/Push/ApnsService.php';
            require_once APP_ROOT . '/Services/Push/PushDispatcher.php';
            PushDispatcher::notifyUsers($userIds, $title, $body, $data);
        } catch (Throwable $e) {
            error_log('SubcontractHooks::push: ' . $e->getMessage());
        }
    }

    private function lifecycle(): void
    {
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
    }
}
