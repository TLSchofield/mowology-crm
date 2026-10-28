<?php
declare(strict_types=1);

final class OnboardingApiPresenter
{
    /** Gather canonical service values, then hand them to the pure shape builders below. */
    public static function data(PDO $db, int $userId, string $dbName): array
    {
        require_once __DIR__ . '/OnboardingService.php';
        require_once dirname(__DIR__, 2) . '/Settings/Services/BusinessIdentityService.php';
        require_once dirname(__DIR__, 2) . '/Settings/Services/Registry/OrgBookBcProvider.php';
        require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboConnectionService.php';
        require_once dirname(__DIR__, 2) . '/QuickBooks/Services/QboOnboardingService.php';
        $ctx = new OnboardingContext($db, $userId);
        $name = new OnboardingNameModule();
        $identity = new BusinessIdentityService($db, new OrgBookBcProvider($dbName), $userId);
        $qmod = new OnboardingQuickBooksModule();
        $conn = null;
        try { $conn = new QboConnectionService($db); } catch (Throwable $e) { $conn = null; }
        $connected = $conn && $conn->tablesReady() && $conn->company() !== null;
        $qbo = new QboOnboardingService($db, $connected ? $conn : null);
        $invited = $ctx->setting('onboarding_accountant_invited');
        try { $logins = (int)$ctx->one("SELECT COUNT(*) FROM users WHERE role = 'accountant'"); } catch (Throwable $e) { $logins = 0; }
        return [
            'name' => self::nameData($name->me($ctx), $name->known($ctx), $name->nextPart($ctx)),
            'company' => self::companyData($identity->current()),
            'quickbooks' => self::quickBooksData($qmod->answer($ctx), (bool)$connected, $qbo->hasSnapshot(), $qbo->partSummaries(), $qbo->canWrite()),
            'books' => ['acknowledged' => $ctx->setting('onboarding_books_ack') === '1'],
            'accountant' => ['invited_name' => $invited !== null && $invited !== '' ? $invited : null, 'logins' => $logins],
            'done' => ['ready' => (new OnboardingService($ctx))->readyToFinish(), 'complete' => $ctx->setting('onboarding_complete') === '1'],
        ];
    }
    /** Build the phone map without leaking web-only row fields. */
    public static function present(array $map, ?string $next, bool $readyToFinish, array $data = []): array
    {
        $steps = [];
        foreach ($map as $row) {
            $key = (string)($row['key'] ?? '');
            $steps[] = ['key' => $key, 'title' => (string)($row['title'] ?? ''), 'state' => (string)($row['state'] ?? 'todo'),
                'head' => (string)($row['owner'] ?? ''), 'skippable' => (bool)($row['skippable'] ?? false),
                'data' => $data[$key] ?? []];
        }
        return ['ok' => true, 'steps' => $steps, 'next' => $next, 'ready_to_finish' => $readyToFinish];
    }

    public static function nameData(array $me, array $known, ?string $nextPart): array
    {
        $quiet = !empty($me['quiet_start']) && !empty($me['quiet_end']) ? ['from' => substr((string)$me['quiet_start'], 0, 5), 'to' => substr((string)$me['quiet_end'], 0, 5), 'weekends' => !empty($me['quiet_weekends'])] : null;
        return ['first' => (string)($me['first_name'] ?? ''), 'last' => (string)($me['last_name'] ?? ''), 'preferred' => (string)($me['preferred_name'] ?? ''),
            'mobile' => (string)($me['phone'] ?? ''), 'channel' => (string)($me['contact_channel'] ?? ''), 'quiet' => $quiet,
            'parts' => ['name' => (bool)($known[0] ?? false), 'reach' => (bool)($known[1] ?? false), 'quiet' => (bool)($known[2] ?? false)], 'next_part' => $nextPart];
    }

    public static function companyData(array $current): array
    {
        $i = is_array($current['identity'] ?? null) ? $current['identity'] : [];
        return ['entered_name' => $i['entered_name'] ?? null, 'legal_name' => $i['legal_name'] ?? null, 'operating_name' => $i['operating_name'] ?? null,
            'registration_number' => $i['registration_number'] ?? null, 'status' => $i['registration_status'] ?? null,
            'confirmed' => !empty($i['owner_confirmed_at']) || (($i['source'] ?? '') === 'owner' && !empty($i['legal_name']))];
    }

    public static function quickBooksData(?string $answer, bool $connected, bool $hasSnapshot, array $summaries, bool $canWrite): array
    {
        $labels = ['business' => 'Business', 'clients' => 'Clients', 'services' => 'Services', 'suppliers' => 'Suppliers', 'accounts' => 'Accounts'];
        $parts = [];
        foreach ($labels as $key => $label) $parts[] = ['key' => $key, 'label' => $label, 'done' => array_key_exists($key, $summaries), 'summary' => (string)($summaries[$key] ?? '')];
        return ['answer' => in_array($answer, ['yes', 'no'], true) ? $answer : null, 'connected' => $connected, 'has_snapshot' => $hasSnapshot, 'parts' => $parts, 'can_write' => $canWrite];
    }
}
