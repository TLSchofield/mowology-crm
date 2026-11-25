<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Comms/Services/MailTidyService.php';

/** Yui's tidy (D3): a sender Tim unticks entirely is "stay"; ticked again after a stay resets it; mixed teaches nothing. */
class YuiTidyLearningTest extends TestCase
{
    public function testSenderChoices(): void
    {
        $rows = [
            ['from_addr' => 'Ann@Strata.ca', 'unsel' => 3, 'n' => 3, 'targets' => 1, 'target_key' => 'newsletters'],
            ['from_addr' => 'bob@shop.com', 'unsel' => 1, 'n' => 4, 'targets' => 1, 'target_key' => 'shopping'],
            ['from_addr' => 'cy@list.org', 'unsel' => 0, 'n' => 2, 'targets' => 1, 'target_key' => 'newsletters'],
            ['from_addr' => 'dee@list.org', 'unsel' => 0, 'n' => 5, 'targets' => 1, 'target_key' => 'newsletters'],
        ];
        $this->assertSame(['ann@strata.ca' => 'stay', 'cy@list.org' => 'newsletters'],
            MailTidyService::senderChoices($rows, ['cy@list.org' => true]));
    }
}
