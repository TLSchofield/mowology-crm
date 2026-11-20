<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Contracts/CampaignAware.php';

class QuotesCampaignEmitter implements CampaignAware
{
    public static function registeredEvents(): array
    {
        return ['quote_declined'];
    }

    public static function eventSchema(string $eventName): array
    {
        switch ($eventName) {
            case 'quote_declined':
                return [
                    'quote_number'   => 'string',
                    'decline_reason' => 'string|null — reason provided by customer',
                    'amount'         => 'float|null — quoted total',
                    'service_type'   => 'string|null',
                ];
            default:
                return [];
        }
    }
}
