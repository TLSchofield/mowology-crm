<?php
/**
 * /crm/includes/roi-functions.php
 * ROI tracking and attribution helpers
 *
 * Migrated from: public/crm/includes/roi-functions.php
 */

declare(strict_types=1);

/**
 * Log a lead event (entry into funnel)
 * Called from quote request form
 */
function logLeadEvent(?string $landingPage = null, ?array $utmParams = null): int
{
    try {
        $db = getDB();

        $sessionId = session_id();
        if (empty($sessionId)) {
            session_start();
            $sessionId = session_id();
        }

        $stmt = $db->prepare("
            INSERT INTO lead_events (
                session_id, landing_page,
                utm_source, utm_medium, utm_campaign, utm_content,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $sessionId,
            $landingPage ?? $_SERVER['HTTP_REFERER'] ?? null,
            $utmParams['source'] ?? $_GET['utm_source'] ?? null,
            $utmParams['medium'] ?? $_GET['utm_medium'] ?? null,
            $utmParams['campaign'] ?? $_GET['utm_campaign'] ?? null,
            $utmParams['content'] ?? $_GET['utm_content'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log("logLeadEvent error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Link a conversion event to a lead
 */
function logConversionEvent(
    int $leadEventId,
    string $eventType,
    ?int $entityId = null
): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            INSERT INTO conversion_events (lead_event_id, event_type, entity_id, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $stmt->execute([$leadEventId, $eventType, $entityId]);
        return true;
    } catch (Throwable $e) {
        error_log("logConversionEvent error: " . $e->getMessage());
        return false;
    }
}

/**
 * Create ROI attribution record for a job plan
 */
function createROIAttribution(
    int $planId,
    ?int $leadEventId = null,
    ?string $sourceCampaign = null,
    ?float $estimatedValue = null
): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            INSERT INTO roi_attribution (
                plan_id, lead_event_id, source_campaign, estimated_value,
                created_at
            ) VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                lead_event_id = COALESCE(VALUES(lead_event_id), lead_event_id),
                source_campaign = COALESCE(VALUES(source_campaign), source_campaign),
                estimated_value = COALESCE(VALUES(estimated_value), estimated_value)
        ");

        $stmt->execute([$planId, $leadEventId, $sourceCampaign, $estimatedValue]);
        return true;
    } catch (Throwable $e) {
        error_log("createROIAttribution error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get ROI funnel stats
 */
function getROIFunnelStats(
    ?string $startDate = null,
    ?string $endDate = null,
    ?string $source = null
): array {
    try {
        $db = getDB();

        // Default: last 30 days
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $whereClause = "le.created_at >= ? AND le.created_at <= ?";
        $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];

        if ($source) {
            $whereClause .= " AND le.utm_source = ?";
            $params[] = $source;
        }

        // Lead events (all entries)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT le.id) as count
            FROM lead_events le
            WHERE $whereClause
        ");
        $stmt->execute($params);
        $leads = (int)$stmt->fetch()['count'];

        // Quote requests (conversion_events)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT ce.id) as count
            FROM conversion_events ce
            JOIN lead_events le ON ce.lead_event_id = le.id
            WHERE ce.event_type = 'quote_request' AND $whereClause
        ");
        $stmt->execute($params);
        $quoteRequests = (int)$stmt->fetch()['count'];

        // Jobs created (job plans)
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT jp.id) as count
            FROM job_plans jp
            JOIN roi_attribution ra ON jp.id = ra.plan_id
            JOIN lead_events le ON ra.lead_event_id = le.id
            WHERE jp.status IN ('active', 'completed') AND $whereClause
        ");
        $stmt->execute($params);
        $jobsCompleted = (int)$stmt->fetch()['count'];

        // Revenue (sum of invoices paid)
        $stmt = $db->prepare("
            SELECT SUM(i.amount_paid) as total_revenue
            FROM invoices i
            JOIN job_plans jp ON i.plan_id = jp.id
            JOIN roi_attribution ra ON jp.id = ra.plan_id
            JOIN lead_events le ON ra.lead_event_id = le.id
            WHERE i.status IN ('paid', 'partial') AND $whereClause
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
        $totalRevenue = (float)($row['total_revenue'] ?? 0);

        return [
            'leads' => $leads,
            'quote_requests' => $quoteRequests,
            'jobs_completed' => $jobsCompleted,
            'revenue' => $totalRevenue,
            'lead_to_quote_rate' => $leads > 0 ? ($quoteRequests / $leads) * 100 : 0,
            'quote_to_job_rate' => $quoteRequests > 0 ? ($jobsCompleted / $quoteRequests) * 100 : 0,
            'job_to_revenue_rate' => $jobsCompleted > 0 ? ($totalRevenue / $jobsCompleted) : 0,
        ];
    } catch (Throwable $e) {
        error_log("getROIFunnelStats error: " . $e->getMessage());
        return [
            'leads' => 0,
            'quote_requests' => 0,
            'jobs_completed' => 0,
            'revenue' => 0,
            'lead_to_quote_rate' => 0,
            'quote_to_job_rate' => 0,
            'job_to_revenue_rate' => 0,
        ];
    }
}

/**
 * Get revenue by source
 */
function getRevenueBySource(
    ?string $startDate = null,
    ?string $endDate = null
): array {
    try {
        $db = getDB();

        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $stmt = $db->prepare("
            SELECT
                le.utm_source as source,
                le.utm_campaign as campaign,
                COUNT(DISTINCT le.id) as leads,
                COUNT(DISTINCT CASE WHEN ce.event_type = 'quote_request' THEN ce.id END) as quote_requests,
                COUNT(DISTINCT jp.id) as jobs,
                SUM(i.amount_paid) as revenue
            FROM lead_events le
            LEFT JOIN conversion_events ce ON le.id = ce.lead_event_id
            LEFT JOIN roi_attribution ra ON le.id = ra.lead_event_id
            LEFT JOIN job_plans jp ON ra.plan_id = jp.id
            LEFT JOIN invoices i ON jp.id = i.plan_id AND i.status IN ('paid', 'partial')
            WHERE le.created_at >= ? AND le.created_at <= ?
            GROUP BY le.utm_source, le.utm_campaign
            ORDER BY revenue DESC
        ");

        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getRevenueBySource error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get conversion funnel details
 */
function getConversionFunnelDetails(
    ?string $startDate = null,
    ?string $endDate = null
): array {
    try {
        $db = getDB();

        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $stmt = $db->prepare("
            SELECT
                le.id,
                le.session_id,
                le.utm_source,
                le.utm_campaign,
                le.landing_page,
                le.created_at as lead_date,
                GROUP_CONCAT(DISTINCT ce.event_type ORDER BY ce.event_type) as events,
                MAX(CASE WHEN ce.event_type = 'quote_request' THEN ce.created_at END) as quote_request_date,
                MAX(CASE WHEN ce.event_type = 'job_created' THEN ce.created_at END) as job_created_date,
                jp.plan_number,
                SUM(i.amount_paid) as total_paid
            FROM lead_events le
            LEFT JOIN conversion_events ce ON le.id = ce.lead_event_id
            LEFT JOIN roi_attribution ra ON le.id = ra.lead_event_id
            LEFT JOIN job_plans jp ON ra.plan_id = jp.id
            LEFT JOIN invoices i ON jp.id = i.plan_id
            WHERE le.created_at >= ? AND le.created_at <= ?
            GROUP BY le.id
            ORDER BY le.created_at DESC
            LIMIT 100
        ");

        $stmt->execute([$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("getConversionFunnelDetails error: " . $e->getMessage());
        return [];
    }
}

/**
 * Calculate ROI (revenue / marketing spend estimate)
 */
function calculateROI(
    float $totalRevenue,
    float $estimatedMarketingSpend
): float {
    if ($estimatedMarketingSpend <= 0) {
        return 0;
    }

    return (($totalRevenue - $estimatedMarketingSpend) / $estimatedMarketingSpend) * 100;
}

/**
 * Log when a quote is sent (stage in funnel)
 */
function logQuoteSentEvent(
    int $quoteId,
    ?int $leadEventId = null
): bool {
    try {
        $db = getDB();

        // If no lead_event_id provided, try to find it from quote_requests
        if (!$leadEventId) {
            $stmt = $db->prepare("
                SELECT qr.lead_event_id FROM quote_requests qr
                WHERE qr.quote_id = ? LIMIT 1
            ");
            $stmt->execute([$quoteId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $leadEventId = (int)($result['lead_event_id'] ?? 0);
        }

        if (!$leadEventId) {
            error_log("logQuoteSentEvent: No lead_event_id found for quote {$quoteId}");
            return false;
        }

        return logConversionEvent($leadEventId, 'quote_sent', $quoteId);
    } catch (Throwable $e) {
        error_log("logQuoteSentEvent error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log when a quote is accepted (stage in funnel)
 */
function logQuoteAcceptedEvent(
    int $quoteId,
    ?int $leadEventId = null
): bool {
    try {
        $db = getDB();

        // If no lead_event_id provided, try to find it from quotes
        if (!$leadEventId) {
            $stmt = $db->prepare("
                SELECT lead_event_id FROM quotes WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$quoteId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $leadEventId = (int)($result['lead_event_id'] ?? 0);
        }

        if (!$leadEventId) {
            error_log("logQuoteAcceptedEvent: No lead_event_id found for quote {$quoteId}");
            return false;
        }

        return logConversionEvent($leadEventId, 'quote_accepted', $quoteId);
    } catch (Throwable $e) {
        error_log("logQuoteAcceptedEvent error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update contact lifecycle after first quote request
 */
function updateContactLifecycleOnQuoteRequest(int $contactId): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            UPDATE contacts SET
                prospect_status = 'prospect',
                lifecycle_stage = 'opportunity',
                first_quote_date = CASE WHEN first_quote_date IS NULL THEN NOW() ELSE first_quote_date END
            WHERE id = ?
        ");

        return $stmt->execute([$contactId]);
    } catch (Throwable $e) {
        error_log("updateContactLifecycleOnQuoteRequest error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update contact to client status after job creation
 */
function updateContactToClient(int $contactId): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            UPDATE contacts SET
                prospect_status = 'client',
                lifecycle_stage = 'client',
                first_job_date = CASE WHEN first_job_date IS NULL THEN NOW() ELSE first_job_date END
            WHERE id = ?
        ");

        return $stmt->execute([$contactId]);
    } catch (Throwable $e) {
        error_log("updateContactToClient error: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate and update contact lifetime value
 */
function updateContactLifetimeValue(int $contactId): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare("
            UPDATE contacts c SET
                total_lifetime_value = (
                    SELECT COALESCE(SUM(i.amount_paid), 0)
                    FROM invoices i
                    WHERE i.company_id IN (
                        SELECT company_id FROM contacts WHERE id = c.id
                    ) AND i.status IN ('paid', 'partial')
                )
            WHERE c.id = ?
        ");

        return $stmt->execute([$contactId]);
    } catch (Throwable $e) {
        error_log("updateContactLifetimeValue error: " . $e->getMessage());
        return false;
    }
}
