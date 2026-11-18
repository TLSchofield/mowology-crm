<?php
use PHPUnit\Framework\TestCase;

final class StripeErrorRulesTest extends TestCase
{
    public function testOnlyAMissingIntentMayBeForgotten(): void
    {
        $this->assertTrue(StripeErrorRules::canForgetIntent('resource_missing'));
        foreach ([null, 'rate_limit', 'api_connection_error', 'lock_timeout'] as $code) {
            $this->assertFalse(StripeErrorRules::canForgetIntent($code), (string)$code);
        }
    }

    public function testAnUnansweredChargeIsUnknownAndAClearRefusalIsNot(): void
    {
        $this->assertTrue(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\ApiConnectionException', null));
        $this->assertTrue(StripeErrorRules::chargeOutcomeUnknown('\\Stripe\\Exception\\UnknownApiErrorException', 500));
        $this->assertTrue(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\IdempotencyException', 400));
        $this->assertTrue(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\ApiErrorException', 503));
        $this->assertFalse(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\CardException', 402));
        $this->assertFalse(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\RateLimitException', 429));
        $this->assertFalse(StripeErrorRules::chargeOutcomeUnknown('Stripe\\Exception\\InvalidRequestException', 400));
    }
}
