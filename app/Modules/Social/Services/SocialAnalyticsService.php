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
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
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

        $out['published_total'] = (int)$db->query(
            "SELECT COUNT(*) FROM social_posts WHERE status = 'published'"
        )->fetchColumn();

        $monthStart = date('Y-m-01 00:00:00');
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM social_posts
             WHERE status = 'published' AND published_at >= ?"
        );
        $stmt->execute([$monthStart]);
        $out['published_month'] = (int)$stmt->fetchColumn();

        $out['scheduled_upcoming'] = (int)$db->query(
            "SELECT COUNT(*) FROM social_posts
             WHERE status = 'scheduled' AND scheduled_at > NOW()"
        )->fetchColumn();

        // Social-attributed quote requests in the window (independent of per-post match)
        if (self::tableExists($db, 'lead_events') && self::tableExists($db, 'conversion_events')) {
            $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));
            $stmt  = $db->prepare("
                SELECT COUNT(*)
                FROM conversion_events ce
                JOIN lead_events le ON le.id = ce.lead_event_id
                WHERE ce.event_type = 'quote_request'
                  AND ce.created_at >= ?
                  AND (le.utm_source = 'social' OR le.utm_medium = 'post')
            ");
            $stmt->execute([$since]);
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
                "SELECT COUNT(*) FROM social_posts
                 WHERE status = 'published' AND published_at BETWEEN ? AND ?"
            );
            $stmt->execute([$start, $end]);
            $monthly[] = [
                'label' => date('M Y', $ts),
                'count' => (int)$stmt->fetchColumn(),
            ];
        }
        $out['monthly'] = $monthly;

        // Status mix (all time)
        $rows = $db->query(
            "SELECT status, COUNT(*) AS cnt FROM social_posts GROUP BY status"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['by_status'][] = ['status' => $r['status'], 'count' => (int)$r['cnt']];
        }

        // Platform mix + publish success rate (from social_post_platforms)
        if (self::tableExists($db, 'social_post_platforms')) {
            $rows = $db->query("
                SELECT platform,
                       COUNT(*)                                              AS attempts,
                       SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
                       SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) AS failed
                FROM social_post_platforms
                GROUP BY platform
                ORDER BY published DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            $totAttempts = 0;
            $totPublished = 0;
            foreach ($rows as $r) {
                $out['by_platform'][] = [
                    'platform'  => $r['platform'],
                    'attempts'  => (int)$r['attempts'],
                    'published' => (int)$r['published'],
                    'failed'    => (int)$r['failed'],
                ];
                $totAttempts  += (int)$r['attempts'];
                $totPublished += (int)$r['published'];
            }
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
            $out['by_template'] = $db->query("
                SELECT st.name AS label, COUNT(sp.id) AS count
                FROM social_posts sp
                JOIN social_templates st ON st.id = sp.template_id
                WHERE sp.status = 'published'
                GROUP BY st.id
                ORDER BY count DESC
                LIMIT 8
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $out['by_service'] = $db->query("
            SELECT COALESCE(NULLIF(service_type, ''), 'Unspecified') AS label,
                   COUNT(*) AS count
            FROM social_posts
            WHERE status = 'published'
            GROUP BY label
            ORDER BY count DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $out['by_city'] = $db->query("
            SELECT COALESCE(NULLIF(city, ''), 'Unspecified') AS label,
                   COUNT(*) AS count
            FROM social_posts
            WHERE status = 'published'
            GROUP BY label
            ORDER BY count DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

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
        $stmt = $db->prepare("
            SELECT sp.id,
                   sp.caption,
                   sp.published_at,
                   sul.utm_campaign,
                   sul.clicks,
                   SUM(CASE WHEN ce.event_type = 'quote_request'  THEN 1 ELSE 0 END) AS quote_request,
                   SUM(CASE WHEN ce.event_type = 'quote_accepted' THEN 1 ELSE 0 END) AS quote_accepted,
                   SUM(CASE WHEN ce.event_type = 'job_created'    THEN 1 ELSE 0 END) AS job_created
            FROM social_posts sp
            JOIN social_utm_links sul       ON sul.post_id = sp.id
            LEFT JOIN lead_events le         ON le.utm_campaign = sul.utm_campaign
                                            AND le.utm_campaign IS NOT NULL
                                            AND le.utm_campaign <> ''
                                            AND le.created_at >= ?
            LEFT JOIN conversion_events ce   ON ce.lead_event_id = le.id
            WHERE sp.status = 'published'
            GROUP BY sp.id
            HAVING quote_request > 0 OR quote_accepted > 0 OR job_created > 0
            ORDER BY quote_request DESC, quote_accepted DESC
            LIMIT 50
        ");
        $stmt->execute([$since]);
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
        $totalPublished = (int)$db->query(
            "SELECT COUNT(*) FROM social_posts WHERE status = 'published'"
        )->fetchColumn();
        $withUtm = (int)$db->query("
            SELECT COUNT(DISTINCT sp.id)
            FROM social_posts sp
            JOIN social_utm_links sul ON sul.post_id = sp.id
            WHERE sp.status = 'published'
              AND sul.utm_campaign IS NOT NULL AND sul.utm_campaign <> ''
        ")->fetchColumn();
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
