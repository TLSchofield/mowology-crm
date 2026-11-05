<?php
declare(strict_types=1);

require_once __DIR__ . '/CronSchedule.php';

final class BusinessCrons
{
    /**
     * Deliberately excluded:
     * - Client/crew sending and billing wait for the outbound gate: campaign_sender, automation_runner, invoice_reminders, sync_ledger, auto_clockout, forgot_clockout_sms, tracking_silent_alert, quote_request_reminder, seasonal_triggers (which queues campaign mail).
     * - The original business's own accounts: mailbox pollers, Stripe autopay, Trackimo, Meta/Google, CMS/website, security_posture, schema_snapshot.
     * - Per-business settings are still needed: weather_guard and seasonal_outlook use Vancouver; penny_prepare and process_ocr_queue use the original business's AI/Vision keys; push_drain, social_publisher, and mia_*.
     */
    public const REGISTRY = [
        'auto_rollover' => ['path' => '/app/Modules/Jobs/Cron/auto_rollover.php', 'expr' => '0 23 * * *', 'timeout' => 600],
        'purchase_history' => ['path' => '/crm/cron/refresh_purchase_history.php', 'expr' => '0 2 * * *', 'timeout' => 600],
        'expense_baselines' => ['path' => '/crm/cron/expense_baselines.php', 'expr' => '0 2 1 * *', 'timeout' => 600],
        'invoice_overdue' => ['path' => '/app/Modules/Marketing/Cron/invoice_overdue.php', 'expr' => '0 8 * * *', 'timeout' => 600],
        'contract_renewal' => ['path' => '/app/Modules/Contracts/Cron/contract_renewal.php', 'expr' => '0 1 * * *', 'timeout' => 600],
        'estimating_feedback' => ['path' => '/crm/cron/estimating_feedback.php', 'expr' => '0 3 * * 1', 'timeout' => 600],
        'closer_recalibrate' => ['path' => '/app/Modules/Sales/Cron/closer_recalibrate.php', 'expr' => '15 4 * * 1', 'timeout' => 600],
        'pipeline_sweep' => ['path' => '/app/Modules/Sales/Cron/pipeline_sweep.php', 'expr' => '40 3 * * *', 'timeout' => 600],
        'receipt_archive' => ['path' => '/app/Modules/Expenses/Cron/receipt_archive.php', 'expr' => '0 3 2 * *', 'timeout' => 600],
        'data_retention' => ['path' => '/app/Modules/Privacy/Cron/data_retention.php', 'expr' => '0 3 * * *', 'timeout' => 600],
        'stop_orphaned_job_timers' => ['path' => '/app/Modules/Team/Cron/stop_orphaned_job_timers.php', 'expr' => '30 23 * * *', 'timeout' => 600],
        'expire_certs' => ['path' => '/app/Modules/Quiz/Cron/expire_certs.php', 'expr' => '15 2 * * *', 'timeout' => 600],
        'clues_daily' => ['path' => '/app/Modules/Comms/Cron/clues_daily.php', 'expr' => '10 6 * * *', 'timeout' => 600],
        'trip_runs_daily' => ['path' => '/app/Modules/Operations/Cron/trip_runs_daily.php', 'expr' => '20 1 * * *', 'timeout' => 600],
        'charlie_morning_brief' => ['path' => '/app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php', 'expr' => '2,17,32,47 * * * *', 'timeout' => 600],
        'seo_recommendations' => ['path' => '/crm/cron/seo_recommendations.php', 'expr' => '0 3 * * *', 'timeout' => 600],
        // QuickBooks sending (2026-11-04, G1): each business's own connection; a production company is refused unless
        // that business set qbo_live_sends=1. Was a fan-out inside penny_prepare, which ran twice (two crontab lines).
        'qbo_sync' => ['path' => '/app/Modules/QuickBooks/Cron/qbo_sync.php', 'expr' => '*/15 * * * *', 'timeout' => 300],
        // Contract billing (2026-11-04, G9): a test business ('test' => true in the registry) makes drafts, sends nothing.
        'contract_billing' => ['path' => '/app/Modules/Contracts/Cron/contract_billing.php', 'expr' => '0 6 1 * *', 'timeout' => 900],
        'contract_billing_retry' => ['path' => '/app/Modules/Contracts/Cron/contract_billing_retry.php', 'expr' => '15 7 * * *', 'timeout' => 600],
        'generate_visits' => ['path' => '/app/Modules/Jobs/Cron/generate_visits.php', 'expr' => '0 */6 * * *', 'timeout' => 1500],
    ];

    public static function scriptPath(string $path): string
    {
        return strpos($path, '/app/') === 0 ? APP_ROOT . substr($path, 4) : PUBLIC_ROOT . $path;
    }

    public static function due(int $from, int $to): array
    {
        $due = [];
        foreach (self::REGISTRY as $key => $cron) {
            if (CronSchedule::dueBetween($cron['expr'], $from, $to)) $due[] = $key;
        }
        return $due;
    }
}
