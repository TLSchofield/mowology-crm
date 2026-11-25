<?php
/**
 * SocialAnalyticsService — first-party analytics for organic ("free") social posts.
 *
 * Uses ONLY data the CRM already owns — no Meta/Graph API dependency:
 *   - Posting activity & cadence       (social_posts, social_post_platforms)
 *   - Content breakdown                (templates / service / city)
 *   - UTM → conversion attribution     (social_utm_links → lead_events → conversion_events)
 *
 * Every query is guarded against schema drift (production may lag the schema
 * files) — missing tables degrade to empty results, never a fatal.
 *
 * @package Mowology\Social
 */

declare(strict_types=1);

class SocialAnalyticsService
{
    private static function tableExists(PDO $db, string $table): bool
    {
        // Probe the table directly — avoids collation issues with SHOW TABLES / information_schema
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        try {
            $db->query("SELECT 1 FROM `{$safe}` LIMIT 0");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Headline numbers for the KPI row. */
    public static function summary(int $days = 90): array
    {
        $db = getDB();
        $out = [
            'published_total'      => 0,
            'published_month'      => 0,
            'scheduled_upcoming'   => 0,
            'attributed_quotes'    => 0,
            'attribution_window'   => $days,
        ];
        if (!self::tableExists($db, 'social_posts')) {
            return $out;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM social_posts WHERE status = ?");
        $stmt->execute(['published']);
        $out['published_total'] = (int)$stmt->fetchColumn();

        $monthStart = date('Y-m-01 00:00:00');
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM social_posts WHERE status = ? AND published_at >= ?"
        );
        $stmt->execute(['published', $monthStart]);
        $out['published_month'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM social_posts WHERE status = ? AND scheduled_at > NOW()"
        );
        $stmt->execute(['scheduled']);
        $out['scheduled_upcoming'] = (int)$stmt->fetchColumn();

        // Social-attributed quote requests in the window (independent of per-post match)
        if (self::tableExists($db, 'lead_events') && self::tableExists($db, 'conversion_events')) {
            $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
            $stmt  = $db->prepare("
                SELECT COUNT(*)
                FROM conversion_events ce
                JOIN lead_events le ON le.id = ce.lead_event_id
                WHERE ce.event_type = ?
                  AND ce.created_at >= ?
                  AND (le.utm_source = ? OR le.utm_medium = ?)
            ");
            $stmt->execute(['quote_request', $since, 'social', 'post']);
            $out['attributed_quotes'] = (int)$stmt->fetchColumn();
        }

        return $out;
    }

    /** Posting activity: monthly cadence, status mix, platform mix, success rate. */
    public static function activity(int $months = 6): array
    {
        $db  = getDB();
        $out = ['monthly' => [], 'by_status' => [], 'by_platform' => [], 'success_rate' => null];
        if (!self::tableExists($db, 'social_posts')) {
            return $out;
        }

        // Monthly published count for the last N months (oldest → newest)
        $monthly = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ts    = strtotime("first day of -{$i} month");
            $start = date('Y-m-01 00:00:00', $ts);
            $end   = date('Y-m-t 23:59:59', $ts);
            $stmt  = $db->prepare(
                "SELECT COUNT(*) FROM social_posts WHERE status = ? AND published_at BETWEEN ? AND ?"
            );
            $stmt->execute(['published', $start, $end]);
            $monthly[] = [
                'label' => date('M Y', $ts),
                'count' => (int)$stmt->fetchColumn(),
            ];
        }
        $out['monthly'] = $monthly;

        // Status mix (all time) — just GROUP BY, no string literal comparison
        $rows = $db->query(
            "SELECT status, COUNT(*) AS cnt FROM social_posts GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['by_status'][] = ['status' => $r['status'], 'count' => (int)$r['cnt']];
        }

        // Platform mix + publish success rate — pivot in PHP to avoid CASE WHEN string comparisons
        if (self::tableExists($db, 'social_post_platforms')) {
            $rows = $db->query(
                "SELECT platform, status, COUNT(*) AS cnt
                 FROM social_post_platforms
                 GROUP BY platform, status
                 ORDER BY platform"
            )->fetchAll(PDO::FETCH_ASSOC);

            $platforms = [];
            foreach ($rows as $r) {
                $p = $r['platform'];
                if (!isset($platforms[$p])) {
                    $platforms[$p] = ['platform' => $p, 'attempts' => 0, 'published' => 0, 'failed' => 0];
                }
                $platforms[$p]['attempts'] += (int)$r['cnt'];
                if ($r['status'] === 'published') $platforms[$p]['published'] += (int)$r['cnt'];
                if ($r['status'] === 'failed')    $platforms[$p]['failed']    += (int)$r['cnt'];
            }

            $totAttempts  = 0;
            $totPublished = 0;
            foreach ($platforms as $p) {
                $out['by_platform'][] = $p;
                $totAttempts  += $p['attempts'];
                $totPublished += $p['published'];
            }
            usort($out['by_platform'], fn($a, $b) => $b['published'] - $a['published']);

            if ($totAttempts > 0) {
                $out['success_rate'] = round($totPublished / $totAttempts * 100, 1);
            }
        }

        return $out;
    }

    /** Content breakdown: which templates / services / areas you post about. */
    public static function content(): array
    {
        $db  = getDB();
        $out = ['by_template' => [], 'by_service' => [], 'by_city' => []];
        if (!self::tableExists($db, 'social_posts')) {
            return $out;
        }

        if (self::tableExists($db, 'social_templates')) {
            $stmt = $db->prepare("
                SELECT st.name AS label, COUNT(sp.id) AS count
                FROM social_posts sp
                JOIN social_templates st ON st.id = sp.template_id
                WHERE sp.status = ?
                GROUP BY st.id
                ORDER BY count DESC
                LIMIT 8
            ");
            $stmt->execute(['published']);
            $out['by_template'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Use CHAR_LENGTH to avoid empty-string comparison collation issues
        $stmt = $db->prepare("
            SELECT IF(CHAR_LENGTH(COALESCE(service_type, '')) = 0, 'Unspecified', service_type) AS label,
                   COUNT(*) AS count
            FROM social_posts
            WHERE status = ?
            GROUP BY label
            ORDER BY count DESC
            LIMIT 8
        ");
        $stmt->execute(['published']);
        $out['by_service'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT IF(CHAR_LENGTH(COALESCE(city, '')) = 0, 'Unspecified', city) AS label,
                   COUNT(*) AS count
            FROM social_posts
            WHERE status = ?
            GROUP BY label
            ORDER BY count DESC
            LIMIT 8
        ");
        $stmt->execute(['published']);
        $out['by_city'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $out;
    }

    /**
     * First-party attribution: link published posts to quote-request conversions
     * by matching the post's UTM campaign to captured lead events.
     */
    public static function attribution(int $days = 90): array
    {
        $db  = getDB();
        $out = ['posts' => [], 'totals' => [
            'quote_request' => 0, 'quote_accepted' => 0, 'job_created' => 0,
        ], 'coverage' => null, 'available' => false];

        foreach (['social_posts', 'social_utm_links', 'lead_events', 'conversion_events'] as $t) {
            if (!self::tableExists($db, $t)) {
                return $out; // attribution not possible on this schema
            }
        }
        $out['available'] = true;
        $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

        // Per-post conversion counts, matched on UTM campaign.
        // CASE WHEN uses column-to-column (ce.event_type vs ?) with parameters to avoid collation issues.
        $stmt = $db->prepare("
            SELECT sp.id,
                   sp.caption,
                   sp.published_at,
                   sul.utm_campaign,
                   sul.clicks,
                   SUM(ce.event_type = ?) AS quote_request,
                   SUM(ce.event_type = ?) AS quote_accepted,
                   SUM(ce.event_type = ?) AS job_created
            FROM social_posts sp
            JOIN social_utm_links sul       ON sul.post_id = sp.id
            LEFT JOIN lead_events le         ON le.utm_campaign COLLATE utf8mb4_general_ci = sul.utm_campaign
                                            AND le.utm_campaign IS NOT NULL
                                            AND CHAR_LENGTH(le.utm_campaign) > 0
                                            AND le.created_at >= ?
            LEFT JOIN conversion_events ce   ON ce.lead_event_id = le.id
            WHERE sp.status = ?
            GROUP BY sp.id
            HAVING quote_request > 0 OR quote_accepted > 0 OR job_created > 0
            ORDER BY quote_request DESC, quote_accepted DESC
            LIMIT 50
        ");
        $stmt->execute(['quote_request', 'quote_accepted', 'job_created', $since, 'published']);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($posts as &$p) {
            $p['id']             = (int)$p['id'];
            $p['clicks']         = (int)$p['clicks'];
            $p['quote_request']  = (int)$p['quote_request'];
            $p['quote_accepted'] = (int)$p['quote_accepted'];
            $p['job_created']    = (int)$p['job_created'];
            $cap = trim((string)$p['caption']);
            $p['caption'] = mb_substr($cap, 0, 90) . (mb_strlen($cap) > 90 ? '…' : '');
            $out['totals']['quote_request']  += $p['quote_request'];
            $out['totals']['quote_accepted'] += $p['quote_accepted'];
            $out['totals']['job_created']    += $p['job_created'];
        }
        unset($p);
        $out['posts'] = $posts;

        // Coverage: how many published posts even have a UTM campaign to match on.
        $stmt = $db->prepare("SELECT COUNT(*) FROM social_posts WHERE status = ?");
        $stmt->execute(['published']);
        $totalPublished = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT sp.id)
            FROM social_posts sp
            JOIN social_utm_links sul ON sul.post_id = sp.id
            WHERE sp.status = ?
              AND sul.utm_campaign IS NOT NULL
              AND CHAR_LENGTH(sul.utm_campaign) > 0
        ");
        $stmt->execute(['published']);
        $withUtm = (int)$stmt->fetchColumn();

        $out['coverage'] = [
            'published_total' => $totalPublished,
            'with_utm'        => $withUtm,
        ];

        return $out;
    }

    /** One call powering the whole analytics page. */
    public static function overview(int $days = 90, int $months = 6): array
    {
        return [
            'summary'     => self::summary($days),
            'activity'    => self::activity($months),
            'content'     => self::content(),
            'attribution' => self::attribution($days),
        ];
    }
}
