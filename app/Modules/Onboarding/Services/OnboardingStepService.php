<?php
declare(strict_types=1);

require_once __DIR__ . '/OnboardingService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/BusinessIdentityService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/Registry/OrgBookBcProvider.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboConnectionService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboOnboardingService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboSyncService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboExpenseSyncService.php';
require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboRecurringService.php';
require_once dirname(__DIR__, 2) . '/Ledger/Services/AccountantInviteService.php';
require_once dirname(__DIR__, 2) . '/Contacts/Services/ContactImportService.php';
require_once dirname(__DIR__, 2) . '/Contacts/Services/GoogleContactsOAuth.php';
require_once dirname(__DIR__, 2) . '/Contacts/Services/GoogleContactsStageService.php';

final class OnboardingStepService
{
    private PDO $db;
    private string $dbName;
    private $identityFactory;
    private $qboFactory;
    private $accountantFactory;
    private $googleFactory;
    private $contactImportFactory;

    public function __construct(PDO $db, string $dbName, ?callable $identityFactory = null, ?callable $qboFactory = null, ?callable $accountantFactory = null, ?callable $googleFactory = null, ?callable $contactImportFactory = null)
    {
        $this->db = $db; $this->dbName = $dbName;
        $this->identityFactory = $identityFactory; $this->qboFactory = $qboFactory; $this->accountantFactory = $accountantFactory; $this->googleFactory = $googleFactory; $this->contactImportFactory = $contactImportFactory;
    }

    public function apply(int $userId, array $in): array
    {
        $context = new OnboardingContext($this->db, $userId);
        // Phones say which new steps they know ('knows'); the web page sends nothing and sees them all.
        $onboarding = new OnboardingService($context, null, array_key_exists('knows', $in) ? OnboardingService::knowsFrom($in['knows']) : null);
        $key = trim((string)($in['key'] ?? ''));
        $do = trim((string)($in['do'] ?? ''));
        if ($onboarding->module($key) === null) return ['ok' => false, 'error' => 'validation', 'message' => 'No such step.'];
        if ($do === 'skip' && $key !== 'contacts') {
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
                    if ($qbo['sync']->setAuto($on, $on ? self::businessToday($this->db) : null) === false) {
                        return ['ok' => false, 'error' => 'refused', 'message' => 'Sending could not be switched ' . ($on ? 'on' : 'off') . '. Nothing was changed.'];
                    }
                    $qbo['expenses']->setReceipts($on);
                    $message = $on ? 'Sending to QuickBooks from today.' : 'Automatic sending is off.';
                    if ($on) {
                        $prompt = isset($qbo['recurring']) ? $qbo['recurring']->pausePrompt() : (new QboRecurringService($this->db, $qbo['conn'] ?? null))->pausePrompt();
                        if ($prompt !== null) $message .= ' ' . $prompt;
                    }
                    $r = ['ok' => true, 'message' => $message];
                }
                else return self::badAction();
                return !empty($r['ok']) ? ['ok' => true, 'next' => $onboarding->next(), 'message' => $r['message'] ?? null]
                    : ['ok' => false, 'error' => 'refused', 'message' => (string)($r['message'] ?? '')];
            }
            if ($key === 'contacts') {
                $m = new OnboardingContactsModule();
                if ($do === 'google_start') {
                    $google = $this->google();
                    if ($google === null) return ['ok' => false, 'error' => 'not_configured', 'message' => 'Google is not set up on this server yet.'];
                    $secret = (string)($google['state_secret'] ?? '');
                    if ($secret === '') throw new RuntimeException('Google state signing is not configured.');
                    $nonce = bin2hex(random_bytes(16));
                    $businessKey = isset($google['business_key']) ? (string)$google['business_key'] : Tenant::key();
                    $state = GoogleContactsOAuth::signState(['b' => $businessKey, 'u' => $userId, 'n' => $nonce, 'e' => time() + 300], $secret);
                    return ['ok' => true, 'next' => $onboarding->next(), 'url' => GoogleContactsOAuth::authUrl((string)$google['client_id'], GoogleContactsOAuth::REDIRECT_URI, $state), 'nonce' => $nonce];
                }
                if ($do === 'google_list') {
                    $nonce = strtolower(trim((string)($in['nonce'] ?? '')));
                    if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return ['ok' => false, 'error' => 'expired', 'message' => 'That Google list is no longer available. Start again.'];
                    $google = $this->googleFactory ? $this->google() : null;
                    $stage = $google['stage'] ?? new GoogleContactsStageService($this->db);   // reading a staged list needs no Google settings
                    $grant = strtolower(trim((string)($in['grant'] ?? '')));
                    $contacts = preg_match('/^[a-f0-9]{32}$/', $grant) ? $stage->take($nonce, $userId, $grant) : null;
                    if ($contacts === null) return ['ok' => false, 'error' => 'expired', 'message' => 'That Google list is no longer available. Start again.'];
                    return ['ok' => true, 'next' => $onboarding->next(), 'contacts' => $contacts];
                }
                if ($do === 'import') {
                    if (!is_array($in['contacts'] ?? null)) return ['ok' => false, 'error' => 'validation', 'message' => 'Send a list of contacts to import.'];
                    if (count($in['contacts']) > 200) return ['ok' => false, 'error' => 'too_many', 'message' => 'Send no more than 200 contacts at a time.'];
                    $source = (string)($in['source'] ?? 'phone') === 'google' ? 'google' : 'phone';
                    $importer = $this->contactImportFactory ? ($this->contactImportFactory)($this->db) : new ContactImportService($this->db);
                    $results = $importer->import($in['contacts'], $source);
                    return ['ok' => true, 'next' => $onboarding->next(), 'message' => 'Contacts checked.', 'results' => $results];
                }
                if ($do === 'finish') {
                    $count = filter_var($in['count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    if ($count === false) return ['ok' => false, 'error' => 'validation', 'message' => 'Send the total number of clients you kept.'];
                    $m->finish($context, (int)$count);
                    return ['ok' => true, 'next' => $onboarding->next(), 'message' => 'Your phone clients are ready.'];
                }
                if ($do === 'skip') {
                    $m->skip($context);
                    return ['ok' => true, 'next' => $onboarding->next(), 'message' => 'Skipped.'];
                }
                return self::badAction();
            }
            if ($key === 'terms' && $do === 'accept') {
                (new OnboardingTermsModule())->accept($context);
                $this->recordTerms($userId, (string)($in['_ip'] ?? ''), (string)($in['_ua'] ?? ''));
                return ['ok' => true, 'next' => $onboarding->next()];
            }
            if ($key === 'gst' && $do === 'answer') return $this->finish((new OnboardingGstModule())->save($context, trim((string)($in['registered'] ?? '')), (string)($in['number'] ?? ''), trim((string)($in['filing'] ?? ''))), $onboarding);
            if ($key === 'yearend' && $do === 'answer') return $this->finish((new OnboardingYearEndModule())->save($context, trim((string)($in['who'] ?? $in['answer'] ?? ''))), $onboarding);
            if ($key === 'payroll' && $do === 'answer') return $this->finish((new OnboardingPayrollModule())->save($context, trim((string)($in['how'] ?? $in['answer'] ?? '')), (string)($in['app'] ?? '')), $onboarding);
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
            $s = $db->query('SELECT timezone FROM business_settings ORDER BY id LIMIT 1');
            $timezone = trim((string)($s->fetchColumn() ?: ''));
            if ($timezone !== '') {
                return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            }
        } catch (Throwable $e) { /* installations without the column use PHP's configured timezone */ }
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /**
     * Accepting the terms in onboarding also records it against the platform terms (the sign-in gate), for a
     * business that is not the default one: the first accept makes this version current for that business's own
     * database. Best effort — missing tables must never stop the step, and Mowology's gate is Tim's switch.
     */
    private function recordTerms(int $userId, string $ip, string $ua): void
    {
        if (class_exists('Tenant') && Tenant::isDefault()) return;
        try {
            require_once dirname(__DIR__, 2) . '/Legal/Services/TermsService.php';
            // Only when no platform row exists at all: a version someone set non-current must stay off.
            $has = $this->db->prepare('SELECT COUNT(*) FROM terms_versions WHERE doc_key = ?');
            $has->execute([TermsService::DOC_KEY]);
            if ((int)$has->fetchColumn() === 0) {
                $v = OnboardingTermsModule::TERMS_VERSION;
                $this->db->prepare(($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO terms_versions (doc_key, version, is_current, activated_at) VALUES (?, ?, 1, CURRENT_TIMESTAMP)')
                    ->execute([TermsService::DOC_KEY, $v]);
            }
            (new TermsService($this->db))->recordAcceptance($userId, $ip !== '' ? $ip : null, $ua !== '' ? $ua : null);
        } catch (Throwable $e) {
            error_log('Onboarding terms record: ' . get_class($e));
        }
    }

    private function identity(int $userId)
    { return $this->identityFactory ? ($this->identityFactory)($userId) : new BusinessIdentityService($this->db, new OrgBookBcProvider($this->dbName), $userId); }
    private function qbo(): array
    {
        if ($this->qboFactory) return ($this->qboFactory)();
        $conn = new QboConnectionService($this->db);
        $connected = $conn->tablesReady() && $conn->company() !== null;
        return ['connected' => $connected, 'conn' => $connected ? $conn : null, 'onboarding' => new QboOnboardingService($this->db, $connected ? $conn : null),
                'sync' => new QboSyncService($conn), 'expenses' => new QboExpenseSyncService($conn)];
    }
    private function google(): ?array
    {
        if ($this->googleFactory) return ($this->googleFactory)($this->db);
        $clientId = defined('ITTAI_GOOGLE_WEB_CLIENT_ID') ? trim((string)constant('ITTAI_GOOGLE_WEB_CLIENT_ID')) : '';
        $clientSecret = defined('ITTAI_GOOGLE_WEB_CLIENT_SECRET') ? trim((string)constant('ITTAI_GOOGLE_WEB_CLIENT_SECRET')) : '';
        if ($clientId === '' || $clientSecret === '') return null;
        $stateSecret = GoogleContactsOAuth::stateSecret();
        if ($stateSecret === null) throw new RuntimeException('Google state signing is not configured.');
        return ['client_id' => $clientId, 'state_secret' => $stateSecret,
            'oauth' => new GoogleContactsOAuth($clientId, $clientSecret), 'stage' => new GoogleContactsStageService($this->db)];
    }
    private function finish(array $r, OnboardingService $onboarding): array
    { return !empty($r['ok']) ? ['ok' => true, 'next' => $onboarding->next(), 'message' => $r['message'] ?? null] : ['ok' => false, 'error' => 'validation', 'message' => (string)($r['message'] ?? $r['error'] ?? '')]; }
    private static function failed(array $r): array
    { return ['ok' => false, 'error' => 'validation', 'message' => (string)($r['message'] ?? $r['error'] ?? 'That did not work.')]; }
    private static function badAction(): array
    { return ['ok' => false, 'error' => 'validation', 'message' => 'Choose a valid action.']; }
}
