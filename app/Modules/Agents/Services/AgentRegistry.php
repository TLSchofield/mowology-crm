<?php
declare(strict_types=1);

/**
 * AgentRegistry — who the seven heads are, in one place (Ittai audit 2026-10-27, stage 5).
 *
 * Identity used to live in prose inside each service (a persona string in a prompt, a header comment). This is the
 * single, code-readable list the Studio and the policy layer read: name, role, what the head is responsible for, which
 * registered crons wake it (keys from the Crons tab in public/crm/database_appstack.php) and the policy actions it owns.
 * It describes; it does not change behaviour. The roles are the owner's (Phase 2 brief); `cron` keys are checked
 * against the real Crons-tab registry by AgentRegistryTest so the two cannot drift apart silently.
 *
 * Pure. Global namespace; require_once it.
 */
final class AgentRegistry
{
    /** @return array<string, array{name:string, role:string, responsibilities:string[], crons:string[]}> */
    public static function heads(): array
    {
        return [
            'charlie' => [
                'name' => 'Charlie', 'role' => 'Chief of Staff',
                'responsibilities' => ['Business priorities and the morning brief', 'Cross-department coordination and exceptions', 'Urgent alerts to the owner', 'Answers questions from the CRM'],
                'crons' => ['charlie_morning_brief'],
            ],
            'yui' => [
                'name' => 'Yui', 'role' => 'Head of Customer Relations',
                'responsibilities' => ['Incoming mail and message routing', 'Customer history and follow-ups', 'Reply drafts', 'Overdue-account contact'],
                'crons' => ['icloud_inbox_poll', 'icloud_tidy_keep', 'clues_daily'],
            ],
            'penny' => [
                'name' => 'Penny', 'role' => 'Head of Finance',
                'responsibilities' => ['Receipt bookkeeping and categorisation', 'Bank-line guidance and reconciliation', 'Payment tracking', 'Financial anomaly checks'],
                'crons' => ['penny_prepare', 'receipt_inbox_poll', 'process_ocr_queue'],
            ],
            'otto' => [
                'name' => 'Otto', 'role' => 'Head of Operations',
                'responsibilities' => ['Scheduling and weather moves', 'Crew coordination and missed visits', 'Equipment manuals and service intervals', 'Trip and supply-run costs'],
                'crons' => ['trip_runs_daily'],
            ],
            'sam' => [
                'name' => 'Sam', 'role' => 'Head of Sales',
                'responsibilities' => ['Leads and the quote pipeline', 'Quote follow-ups', 'Accepting quotes the customer signed by email', 'Conversion tracking'],
                'crons' => ['sales_inbox_poll', 'pipeline_sweep', 'closer_recalibrate'],
            ],
            'mia' => [
                'name' => 'Mia', 'role' => 'Head of Marketing',
                'responsibilities' => ['Campaigns and email sequences', 'Content and social drafts', 'Photo tagging', 'Channel reporting'],
                'crons' => ['mia_email_daily', 'mia_channels_weekly', 'mia_media_tags'],
            ],
            'ada' => [
                'name' => 'Ada', 'role' => 'Head of Technology',
                'responsibilities' => ['Cron and integration health', 'Security posture', 'Technical diagnostics (read-only)'],
                'crons' => ['security_posture'],
            ],
        ];
    }

    public static function exists(string $head): bool
    {
        return isset(self::heads()[strtolower($head)]);
    }
}
