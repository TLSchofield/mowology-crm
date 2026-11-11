<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Penny's "deposit this e-Transfer" pushes: when they fire, what they say, what a tap opens. */
class EtransferClaimAlertServiceTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge(['id' => 7, 'amount' => '232.36', 'sender_name' => 'TOVE MARIE PASHKOWSKI', 'status' => 'pending',
                            'expires_on' => '2026-10-22', 'deposit_url' => 'https://etransfer.interac.ca/redirectFrom?fiID=CA000809&pID=X',
                            'claim_alerted_at' => null, 'claim_reminded' => 0, 'deposited_at' => null], $o);
    }

    public function test_a_new_claim_is_pushed_once(): void
    {
        $this->assertSame('new', EtransferClaimAlertService::due($this->row(), '2026-09-23'));
        $this->assertNull(EtransferClaimAlertService::due($this->row(['claim_alerted_at' => '2026-09-23 10:00:00']), '2026-09-24'));
    }

    public function test_reminders_at_seven_then_two_days_before_expiry(): void
    {
        $alerted = ['claim_alerted_at' => '2026-09-23 10:00:00'];
        $this->assertSame('remind7', EtransferClaimAlertService::due($this->row($alerted), '2026-10-15'));
        $this->assertNull(EtransferClaimAlertService::due($this->row($alerted + ['claim_reminded' => 1]), '2026-10-16'));
        $this->assertSame('remind2', EtransferClaimAlertService::due($this->row($alerted + ['claim_reminded' => 1]), '2026-10-20'));
        $this->assertNull(EtransferClaimAlertService::due($this->row($alerted + ['claim_reminded' => 2]), '2026-10-21'));
    }

    public function test_nothing_once_deposited_dismissed_or_expired(): void
    {
        $this->assertNull(EtransferClaimAlertService::due($this->row(['deposited_at' => '2026-09-29 09:00:00']), '2026-09-30'));
        $this->assertNull(EtransferClaimAlertService::due($this->row(['status' => 'dismissed']), '2026-09-30'));
        $this->assertNull(EtransferClaimAlertService::due($this->row(), '2026-10-23'));
    }

    public function test_message_names_amount_sender_and_expiry(): void
    {
        [$t, $b] = EtransferClaimAlertService::message($this->row(), 'new', '2026-09-23');
        $this->assertSame('Penny: deposit $232.36', $t);
        $this->assertSame('$232.36 from Tove Marie Pashkowski needs depositing (expires Oct 22). Tap to deposit.', $b);
        [, $b2] = EtransferClaimAlertService::message($this->row(), 'remind2', '2026-10-20');
        $this->assertStringContainsString('expires in 2 days', $b2);
    }

    public function test_payload_carries_only_an_interac_deposit_link(): void
    {
        $p = EtransferClaimAlertService::payload($this->row());
        $this->assertSame('penny', $p['head']);
        $this->assertStringStartsWith('https://etransfer.interac.ca/', $p['url']);
        $this->assertArrayNotHasKey('url', EtransferClaimAlertService::payload($this->row(['deposit_url' => 'https://evil.example/x'])));
    }
}
