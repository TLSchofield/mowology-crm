<?php
declare(strict_types=1);

/** Handles only a matching phone OAuth return; null means the existing CRM callback must continue. */
final class QboPhoneCallbackService
{
    private PDO $db;
    /** @var callable(PDO):object */
    private $serviceFactory;
    /** @var callable():int */
    private $clock;

    public function __construct(PDO $db, ?callable $serviceFactory = null, ?callable $clock = null)
    {
        $this->db = $db;
        $this->serviceFactory = $serviceFactory ?? static function (PDO $db): QboConnectionService { return new QboConnectionService($db); };
        $this->clock = $clock ?? 'time';
    }

    /** @return array{ok:bool,message:string}|null */
    public function handle(array $query, bool $hasCrmState): ?array
    {
        if ($hasCrmState) return null;
        $phone = QboConnectionService::phoneState($this->db);
        $state = (string)($query['state'] ?? '');
        if (!QboConnectionService::stateOk($phone, $state, ($this->clock)(), 1)) return null;

        $session = ['qbo_oauth' => $phone];
        $service = ($this->serviceFactory)($this->db);
        try {
            if (!empty($query['error'])) {
                $service->cancel($session, $state);
                return ['ok' => false, 'message' => $query['error'] === 'access_denied'
                    ? 'QuickBooks was not connected (the sign-in was cancelled).'
                    : 'QuickBooks did not connect.'];
            }
            return $service->finish($session, (string)($query['code'] ?? ''), $state,
                (string)($query['realmId'] ?? ''), (int)($phone['user_id'] ?? 0));
        } catch (Throwable $e) {
            error_log('QuickBooks phone callback: ' . get_class($e));
            return ['ok' => false, 'message' => 'QuickBooks did not connect. Try again.'];
        } finally {
            QboConnectionService::clearPhoneState($this->db);
        }
    }
}
