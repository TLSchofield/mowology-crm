<?php
declare(strict_types=1);

/**
 * CampaignEventEmitter — write-side event bus for the Campaign Connector.
 *
 * Any operational module calls CampaignEventEmitter::fire() when a campaign-
 * relevant event occurs. The call is non-blocking: all errors are swallowed and
 * logged so the calling module never fails because of this service.
 *
 * The automation_runner cron reads unprocessed rows from campaign_trigger_log
 * and evaluates matching automation_rules against each event.
 *
 * Usage:
 *   require_once APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
 *   CampaignEventEmitter::fire('invoice_paid', 'invoice', $invoiceId, $contactId, [...], 'invoices');
 */
class CampaignEventEmitter
{
    /**
     * @param string   $eventName    Named event: 'invoice_paid', 'quote_declined', 'photos_uploaded', etc.
     * @param string   $entityType   Entity category: 'invoice', 'quote', 'job_visit', 'lead', 'contract'
     * @param int      $entityId     Primary key of the triggering entity
     * @param int|null $contactId    Associated contact (null if unknown — runner resolves from entity)
     * @param array    $payload      Full event context at fire time (will be JSON-encoded)
     * @param string   $sourceModule Which module fired: 'invoices', 'quotes', 'crew_app', 'jobflow', etc.
     */
    public static function fire(
        string $eventName,
        string $entityType,
        int    $entityId,
        ?int   $contactId,
        array  $payload,
        string $sourceModule
    ): void {
        try {
            $db = getDB();
            $db->prepare(
                'INSERT INTO campaign_trigger_log
                 (event_name, entity_type, entity_id, contact_id, payload, source_module, fired_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $eventName,
                $entityType,
                $entityId,
                $contactId,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $sourceModule,
            ]);
        } catch (Throwable $e) {
            // Non-blocking: the operational module must never fail because marketing is down.
            error_log('[CampaignEventEmitter] ' . $eventName . ' on ' . $entityType . ':' . $entityId . ' — ' . $e->getMessage());
        }
    }
}
