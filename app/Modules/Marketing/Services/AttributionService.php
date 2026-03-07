<?php
declare(strict_types=1);

/**
 * AttributionService — last-touch campaign attribution.
 *
 * Records marketing campaign attribution when key conversion events occur:
 *   - quote_created  : CRM staff creates a quote for a contact
 *   - job_booked     : An accepted quote is converted to a job plan
 *   - invoice_paid   : An invoice is marked fully paid
 *
 * Uses last-touch attribution with a 30-day lookback window.
 * Clicked sends are prioritised over opens-only, then recency.
 * Each (type, entity) pair is recorded once — no duplicates.
 *
 * All public methods are fire-and-forget: exceptions are caught and
 * logged; they never bubble up to break page flows.
 */
class AttributionService
{
    /** Look-back window for campaign attribution (days). */
    private const LOOKBACK_DAYS = 30;

    // ── Public hook entry-points ───────────────────────────────────────────

    /**
     * Record attribution when a new quote is created.
     *
     * @param PDO $db
     * @param int $quoteId    Newly created quote ID
     * @param int $propertyId Property the quote is for
     */
    public static function onQuoteCreated(PDO $db, int $quoteId, int $propertyId): void
    {
        try {
            $contactId = self::contactFromProperty($db, $propertyId);
            self::maybeRecord($db, $contactId, 'quote_created', 'quote', $quoteId, 0.0);
        } catch (\Throwable $e) {
            error_log('[AttributionService] onQuoteCreated error: ' . $e->getMessage());
        }
    }

    /**
     * Record attribution when a job plan is booked from an accepted quote.
     *
     * @param PDO   $db
     * @param int   $planId     Newly created job_plan ID
     * @param int   $propertyId Property the plan is for
     * @param float $value      Quote value (used as potential revenue signal)
     */
    public static function onJobBooked(PDO $db, int $planId, int $propertyId, float $value = 0.0): void
    {
        try {
            $contactId = self::contactFromProperty($db, $propertyId);
            self::maybeRecord($db, $contactId, 'job_booked', 'job_plan', $planId, $value);
        } catch (\Throwable $e) {
            error_log('[AttributionService] onJobBooked error: ' . $e->getMessage());
        }
    }

    /**
     * Record attribution when an invoice is fully paid.
     *
     * @param PDO   $db
     * @param int   $invoiceId Invoice that was paid
     * @param float $amount    Amount paid
     */
    public static function onInvoicePaid(PDO $db, int $invoiceId, float $amount): void
    {
        try {
            $stmt = $db->prepare("SELECT contact_id FROM invoices WHERE id = ? LIMIT 1");
            $stmt->execute([$invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $contactId = (int)($row['contact_id'] ?? 0);
            self::maybeRecord($db, $contactId, 'invoice_paid', 'invoice', $invoiceId, $amount);
        } catch (\Throwable $e) {
            error_log('[AttributionService] onInvoicePaid error: ' . $e->getMessage());
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Core attribution recorder.
     *
     * 1. Validates contact
     * 2. Finds most recent campaign send via last-touch (clicked > opened > sent)
     * 3. Deduplicates — skips if this entity already has an attribution row
     * 4. Inserts into campaign_attribution (and campaign_revenue_log if revenue > 0)
     */
    private static function maybeRecord(
        PDO    $db,
        int    $contactId,
        string $type,
        string $entityType,
        int    $entityId,
        float  $revenue
    ): void {
        if ($contactId <= 0 || $entityId <= 0) {
            return;
        }

        $campaignId = self::findCampaign($db, $contactId);
        if (!$campaignId) {
            return; // No recent campaign send — nothing to attribute
        }

        // Deduplicate: only one attribution row per (type, entity)
        $check = $db->prepare("
            SELECT id FROM campaign_attribution
            WHERE attribution_type = ? AND entity_type = ? AND entity_id = ?
            LIMIT 1
        ");
        $check->execute([$type, $entityType, $entityId]);
        if ($check->fetch()) {
            return;
        }

        $db->prepare("
            INSERT INTO campaign_attribution
                (campaign_id, contact_id, attribution_type, entity_type, entity_id, revenue, attributed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$campaignId, $contactId, $type, $entityType, $entityId, $revenue]);

        if ($revenue > 0) {
            $db->prepare("
                INSERT INTO campaign_revenue_log
                    (campaign_id, contact_id, event_type, revenue, entity_type, entity_id, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$campaignId, $contactId, $type, $revenue, $entityType, $entityId]);
        }
    }

    /**
     * Find the most recent campaign for a contact using last-touch attribution.
     * Priority: clicked send > opened send > any sent (all within LOOKBACK_DAYS).
     *
     * @return int|null campaign_id or null if no recent send found
     */
    private static function findCampaign(PDO $db, int $contactId): ?int
    {
        $days = self::LOOKBACK_DAYS;
        $stmt = $db->prepare("
            SELECT campaign_id
            FROM campaign_sends
            WHERE contact_id = ?
              AND sent_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            ORDER BY
                (clicked_at IS NOT NULL) DESC,
                (opened_at  IS NOT NULL) DESC,
                sent_at DESC
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['campaign_id'] : null;
    }

    /**
     * Resolve the primary contact for a property via site_contact_id.
     *
     * @return int contact_id or 0 if not found
     */
    private static function contactFromProperty(PDO $db, int $propertyId): int
    {
        if ($propertyId <= 0) {
            return 0;
        }
        $stmt = $db->prepare("SELECT site_contact_id FROM properties WHERE id = ? LIMIT 1");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['site_contact_id'] ?? 0);
    }
}
