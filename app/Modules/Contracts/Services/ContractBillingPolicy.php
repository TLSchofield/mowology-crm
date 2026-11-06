<?php
declare(strict_types=1);

/** Pure safety policy for outbound messages created by contract billing. */
final class ContractBillingPolicy
{
    public static function sendAllowed(bool $isTest): bool
    {
        return !$isTest;
    }
}
