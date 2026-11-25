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

    public function visitBackToScheduled(int $visitId, int $userId): void
    {
        $this->lifecycle();
        VisitLifecycleService::updateVisitStatus($visitId, 'scheduled', $userId, null);
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

    public function refreshVisitCost(int $visitId, int $userId): void
    {
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
        getDB()->prepare("UPDATE job_visits SET labor_cost_snapshot = NULL WHERE id = ?")->execute([$visitId]);
        VisitCompletionService::capture($visitId, $userId);
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

    /** @return int the new expense id. A draft, so it goes through Penny's normal review. */
    public function recordBill(array $changes, int $ownerId): int
    {
        require_once APP_ROOT . '/Modules/Expenses/Services/ExpenseGate.php';
        $result = (new ExpenseGate(getDB()))->apply(null, $changes, ['id' => $ownerId, 'kind' => 'system'], 'subcontract');
        return (int)$result['expense_id'];
    }

    /** The closed folder her files go in. Staff read them only through the signed-in API. */
    public function fileDir(): string
    {
        require_once APP_ROOT . '/Core/Uploads.php';
        $dir = uploadsDir('subcontract');
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!is_file($dir . '/.htaccess')) file_put_contents($dir . '/.htaccess', "Require all denied\n");
        return $dir;
    }

    public function storeFile(string $tmpPath, string $destination): bool
    {
        return move_uploaded_file($tmpPath, $destination);
    }

    private function lifecycle(): void
    {
        require_once APP_ROOT . '/Modules/Jobs/Services/PlanFunctions.php';
        require_once APP_ROOT . '/Modules/Jobs/Services/VisitLifecycleService.php';
    }
}
