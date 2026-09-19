<?php
declare(strict_types=1);

/**
 * CampaignAware — plug-in contract for campaign-aware modules.
 *
 * Any module that fires campaign events should implement this interface and
 * register itself in /app/Modules/CampaignConnector/campaign_modules.php.
 *
 * This gives the Opportunities Dashboard and the rule editor a way to
 * discover which events exist and what payload fields they carry — without
 * reading source code.
 *
 * Implementation example:
 *
 *   class InvoicesCampaignEmitter implements CampaignAware {
 *       public static function registeredEvents(): array {
 *           return ['invoice_paid', 'invoice_overdue'];
 *       }
 *       public static function eventSchema(string $eventName): array {
 *           return match ($eventName) {
 *               'invoice_paid' => ['invoice_number' => 'string', 'amount' => 'float', ...],
 *               default => [],
 *           };
 *       }
 *   }
 */
interface CampaignAware
{
    /**
     * Return the list of event_name values this module can emit.
     * These must match the strings passed to CampaignEventEmitter::fire().
     *
     * @return string[]
     */
    public static function registeredEvents(): array;

    /**
     * Return the payload field schema for a given event.
     * Keys are field names; values are human-readable type descriptions.
     * Used by the rule editor UI and Opportunities Dashboard for documentation.
     *
     * @param  string $eventName
     * @return array<string, string>
     */
    public static function eventSchema(string $eventName): array;
}
