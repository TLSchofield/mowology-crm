<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class BusinessCronsTest extends TestCase
{
    public function test_registry_matches_cron_manager(): void
    {
        $text = (string)file_get_contents(__DIR__ . '/../../../public/crm/database_appstack.php');
        preg_match_all("/\['key'=>'([^']+)'.*?'expr'=>'([^']+)'.*?'app'=>'([^']+)'/", $text, $matches, PREG_SET_ORDER);
        $manager = [];
        foreach ($matches as $match) $manager[$match[1]] = ['expr' => $match[2], 'path' => $match[3]];
        foreach (BusinessCrons::REGISTRY as $key => $cron) {
            $this->assertArrayHasKey($key, $manager);
            $this->assertSame($manager[$key]['expr'], $cron['expr'], $key);
            $this->assertSame($manager[$key]['path'], $cron['path'], $key);
        }
    }

    public function test_excluded_crons_are_absent(): void
    {
        $excluded = ['campaign_sender', 'automation_runner', 'invoice_reminders', 'sync_ledger',
            'auto_clockout', 'forgot_clockout_sms', 'tracking_silent_alert', 'quote_request_reminder', 'seasonal_triggers',
            'weather_guard', 'seasonal_outlook', 'penny_prepare', 'qbo_sync', 'process_ocr_queue', 'push_drain',
            'social_publisher', 'security_posture', 'schema_snapshot'];
        foreach ($excluded as $key) $this->assertArrayNotHasKey($key, BusinessCrons::REGISTRY);
    }

    public function test_paths_and_due_order(): void
    {
        $this->assertSame(APP_ROOT . '/Modules/Jobs/Cron/auto_rollover.php', BusinessCrons::scriptPath('/app/Modules/Jobs/Cron/auto_rollover.php'));
        $this->assertSame(PUBLIC_ROOT . '/crm/cron/refresh_purchase_history.php', BusinessCrons::scriptPath('/crm/cron/refresh_purchase_history.php'));
        $from = mktime(22, 59, 0, 11, 2, 2026);
        $this->assertContains('auto_rollover', BusinessCrons::due($from, mktime(23, 0, 0, 11, 2, 2026)));
        $this->assertNotContains('stop_orphaned_job_timers', BusinessCrons::due($from, mktime(23, 29, 0, 11, 2, 2026)));
        $this->assertContains('stop_orphaned_job_timers', BusinessCrons::due($from, mktime(23, 30, 0, 11, 2, 2026)));
    }

    /** The allow-list is exactly the phase-1 set (Tim, 2026-11-04): adding a cron here is a decision, not a side effect.
     *  + contact_roster_sync (Tim approved the contact roster plan, 2026-11-07): adds missing roster rows only, sends nothing. */
    public function test_the_allow_list_is_exactly_phase_one(): void
    {
        $this->assertSame([
            'auto_rollover', 'purchase_history', 'expense_baselines', 'invoice_overdue', 'contract_renewal',
            'estimating_feedback', 'closer_recalibrate', 'pipeline_sweep', 'receipt_archive', 'data_retention',
            'stop_orphaned_job_timers', 'expire_certs', 'contact_roster_sync', 'clues_daily', 'trip_runs_daily', 'charlie_morning_brief',
            'seo_recommendations', 'qbo_sync_business', 'contract_billing', 'contract_billing_retry', 'generate_visits',
        ], array_keys(BusinessCrons::REGISTRY));
    }
}
