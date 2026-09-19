<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Contracts/CampaignAware.php';

class JobFlowCampaignEmitter implements CampaignAware
{
    public static function registeredEvents(): array
    {
        return ['lead_submitted'];
    }

    public static function eventSchema(string $eventName): array
    {
        switch ($eventName) {
            case 'lead_submitted':
                return [
                    'lead_quality'     => 'string — value_tier from classification: high|medium|low',
                    'is_priority'      => 'bool — true if classification flagged as priority',
                    'service_types'    => 'string — comma-separated service slugs',
                    'urgency'          => 'string — inquiring|soon|asap',
                    'city'             => 'string',
                    'utm_source'       => 'string|null',
                    'utm_campaign'     => 'string|null',
                    'consent_sms'      => 'bool',
                    'consent_marketing'=> 'bool',
                    'source'           => 'string — tracking src value (e.g. strata-landing, website)',
                ];
            default:
                return [];
        }
    }
}
