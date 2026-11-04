<?php
declare(strict_types=1);

require_once __DIR__ . '/OnboardingService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/BusinessIdentityService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/Registry/OrgBookBcProvider.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboConnectionService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboOnboardingService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboSyncService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboExpenseSyncService.php';
require_once dirname(__DIR__, 2) . '/Ledger/Services/AccountantInviteService.php';

final class OnboardingStepService
{
    private PDO $db;
    private string $dbName;
    private $identityFactory;
    private $qboFactory;
    private $accountantFactory;

    public function __construct(PDO $db, string $dbName, ?callable $identityFactory = null, ?callable $qboFactory = null, ?callable $accountantFactory = null)
    {
        $this->db = $db; $this->dbName = $dbName;
        $this->identityFactory = $identityFactory; $this->qboFactory = $qboFactory; $this->accountantFactory = $accountantFactory;
    }

    public function apply(int $userId, array $in): array
    {
        $context = new OnboardingContext($this->db, $userId);
        $onboarding = new OnboardingService($context);
        $key = trim((string)($in['key'] ?? ''));
        $do = trim((string)($in['do'] ?? ''));
        if ($onboarding->module($key) === null) return ['ok' => false, 'error' => 'validation', 'message' => 'No such step.'];
        if ($do === 'skip') {
            $r = $onboarding->skip($key, true);
            return !empty($r['ok']) ? ['ok' => true, 'next' => $onboarding->next(), 'message' => $r['message'] ?? null]
                : ['ok' => false, 'error' => 'refused', 'message' => (string)($r['message'] ?? '')];
        }
        try {
            if ($key === 'name') {
                $m = new OnboardingNameModule();
                if ($do === 'save') $r = $m->save($context, (string)($in['first'] ?? ''), (string)($in['last'] ?? ''), (string)($in['preferred'] ?? ''));
                elseif ($do === 'reach') $r = $m->saveReach($context, (string)($in['channel'] ?? ''), (string)($in['mobile'] ?? ''));
                elseif ($do === 'quiet') $r = $m->saveQuiet($context, (string)($in['from'] ?? ''), (string)($in['to'] ?? ''), filter_var($in['weekends'] ?? false, FILTER_VALIDATE_BOOLEAN));
                else return self::badAction();
                return $this->finish($r, $onboarding);
            }
            if ($key === 'company') {
                $identity = $this->identity($userId);
                if ($do === 'search') {
                    $name = trim((string)($in['name'] ?? '')); $started = $identity->start($name); if (empty($started['ok'])) return self::failed($started);
                    $r = $identity->search($name); $matches = [];
                    foreach ((array)($r['matches'] ?? []) as $m) $matches[] = ['name' => (string)($m['operating_names'][0] ?? $m['legal_name'] ?? ''), 'registration_number' => (string)($m['registration_number'] ?? ''), 'status' => (string)($m['status'] ?? ''), 'record_id' => (string)($m['record_id'] ?? '')];
                    return ['ok' => true, 'next' => $onboarding->next(), 'matches' => $matches, 'registry' => self::registryState((string)($r['status'] ?? ''))];
                }
                if ($do === 'confirm') $r = $identity->confirm((string)($in['record_id'] ?? ''));
                elseif ($do === 'manual') $r = $identity->manual(['legal_name' => trim((string)($in['name'] ?? ''))]);
                else return self::badAction();
                if (empty($r['ok'])) return self::failed($r);
                $i = (array)($r['identity'] ?? []); $name = trim((string)($i['operating_name'] ?? '')) ?: trim((string)($i['legal_name'] ?? ''));
                if ($name !== '') $this->db->prepare('UPDATE business_settings SET company_name = ? WHERE id = 1')->execute([$name]);
                return ['ok' => true, 'next' => $onboarding->next()];
            }
            if ($key === 'quickbooks') {
                $m = new OnboardingQuickBooksModule();
                if ($do === 'yes' || $do === 'no') { $m->setAnswer($context, $do === 'yes'); return ['ok' => true, 'next' => $onboarding->next()]; }
                $qbo = $this->qbo();
                if ($do === 'read') { if (!$qbo['connected']) return ['ok' => false, 'error' => 'refused', 'message' => 'Connect QuickBooks first.']; $r = $qbo['onboarding']->pull(); }
                elseif ($do === 'accept') $r = $qbo['onboarding']->apply((string)($in['part'] ?? ''), $userId);
                elseif ($do === 'send') {
                    if (!$qbo['connected']) return ['ok' => false, 'error' => 'refused', 'message' => 'Connect QuickBooks first.'];
                    $on = filter_var($in['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $qbo['sync']->setAuto($on, $on ? self::businessToday($this->db) : null);
                    $qbo['expenses']->setReceipts($on);
                    $r = ['ok' => true, 'message' => $on ? 'Sending to QuickBooks from today.' : 'Automatic sending is off.'];
                }
                else return self::badAction();
                return !empty($r['ok']) ? ['ok' => true, 'next' => $onboarding->next(), 'message' => $r['message'] ?? null]
                    : ['ok' => false, 'error' => 'refused', 'message' => (string)($r['message'] ?? '')];
            }
            if ($key === 'books' && $do === 'ack') { (new OnboardingBooksModule())->acknowledge($context); return ['ok' => true, 'next' => $onboarding->next()]; }
            if ($key === 'accountant' && $do === 'invite') {
                $name = trim((string)($in['name'] ?? '')); $email = trim((string)($in['email'] ?? ''));
                if (($bad = AccountantInviteService::invalid($name, $email)) !== null) return ['ok' => false, 'error' => 'validation', 'message' => $bad];
                $svc = $this->accountantFactory ? ($this->accountantFactory)() : new AccountantInviteService($this->db);
                $inv = $svc->invite($name, $email, $userId, (string)($in['_site_url'] ?? ''));
                $context->saveSetting('onboarding_accountant_invited', $name, 'Onboarding: who was invited as the accountant (OnboardingAccountantModule)');
                return ['ok' => true, 'next' => $onboarding->next(), 'invite' => ['link' => $inv['link'], 'expires_at' => $inv['expires_at'], 'name' => $name]];
            }
            if ($key === 'done' && $do === 'finish') {
                if (!$onboarding->readyToFinish()) return ['ok' => false, 'error' => 'refused', 'message' => 'A step before this one still needs doing — the map shows which.'];
                $context->saveSetting('onboarding_complete', '1', 'Onboarding: the owner finished the path (OnboardingDoneModule)');
                return ['ok' => true, 'next' => $onboarding->next()];
            }
            return self::badAction();
        } catch (Throwable $e) {
            error_log('Onboarding phone step ' . $key . ': ' . get_class($e));
            return ['ok' => false, 'error' => $e instanceof InvalidArgumentException ? 'validation' : 'refused', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'That did not work. Nothing was changed.'];
        }
    }

    /**
     * The phone contract: 'ok' when the registry answered (with or without matches), else the provider's reason
     * ('unavailable', 'rate_limited'). The provider says 'matches' / 'none' on success, which the app read as
     * "can't be reached" (rehearsal walk 2026-11-03).
     */
    public static function registryState(string $providerStatus): string
    {
        return in_array($providerStatus, ['matches', 'none', 'ok'], true) ? 'ok' : ($providerStatus === '' ? 'unavailable' : $providerStatus);
    }

    public static function businessToday(PDO $db, ?DateTimeImmutable $now = null): string
    {
        try {
            $s = $db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute(['business_timezone']);
            $timezone = trim((string)($s->fetchColumn() ?: ''));
            if ($timezone !== '') {
                return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            }
        } catch (Throwable $e) { /* installations without the setting use PHP's configured timezone */ }
        return ($now ?? new DateTimeImmutable('now'))->format('Y-m-d');
    }

    private function identity(int $userId)
    { return $this->identityFactory ? ($this->identityFactory)($userId) : new BusinessIdentityService($this->db, new OrgBookBcProvider($this->dbName), $userId); }
    private function qbo(): array
    {
        if ($this->qboFactory) return ($this->qboFactory)();
        $conn = new QboConnectionService($this->db);
        $connected = $conn->tablesReady() && $conn->company() !== null;
        return ['connected' => $connected, 'onboarding' => new QboOnboardingService($this->db, $connected ? $conn : null),
                'sync' => new QboSyncService($conn), 'expenses' => new QboExpenseSyncService($conn)];
    }
    private function finish(array $r, OnboardingService $onboarding): array
    { return !empty($r['ok']) ? ['ok' => true, 'next' => $onboarding->next(), 'message' => $r['message'] ?? null] : ['ok' => false, 'error' => 'validation', 'message' => (string)($r['message'] ?? $r['error'] ?? '')]; }
    private static function failed(array $r): array
    { return ['ok' => false, 'error' => 'validation', 'message' => (string)($r['message'] ?? $r['error'] ?? 'That did not work.')]; }
    private static function badAction(): array
    { return ['ok' => false, 'error' => 'validation', 'message' => 'Choose a valid action.']; }
}
