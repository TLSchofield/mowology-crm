<?php
declare(strict_types=1);

require_once __DIR__ . '/OnboardingService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/BusinessIdentityService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/Registry/OrgBookBcProvider.php';

final class OnboardingStepService
{
    private PDO $db;
    private string $dbName;

    public function __construct(PDO $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    /** @return array{ok:bool,message?:string,next?:string} */
    public function apply(int $userId, array $in): array
    {
        $context = new OnboardingContext($this->db, $userId);
        $onboarding = new OnboardingService($context);
        $key = trim((string)($in['key'] ?? ''));

        if ($key === 'name') {
            $module = new OnboardingNameModule();
            $result = $module->save($context, (string)($in['first'] ?? ''), (string)($in['last'] ?? ''), (string)($in['preferred'] ?? ''));
            if (!$result['ok']) return $result;
            $channel = trim((string)($in['channel'] ?? ''));
            if ($channel !== '') {
                $result = $module->saveReach($context, $channel, (string)($in['mobile'] ?? ''));
                if (!$result['ok']) return $result;
            }
        } elseif ($key === 'company') {
            try {
                $identity = new BusinessIdentityService($this->db, new OrgBookBcProvider($this->dbName), $userId);
                $result = $identity->manual(['legal_name' => trim((string)($in['name'] ?? ''))]);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
            if (($result['ok'] ?? false) !== true) {
                return ['ok' => false, 'message' => (string)($result['error'] ?? 'Business identity could not be saved')];
            }
        } else {
            return ['ok' => false, 'message' => 'That step has no phone form yet.'];
        }

        return ['ok' => true, 'next' => $onboarding->next()];
    }
}
