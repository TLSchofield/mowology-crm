<?php
/**
 * Automation Runner Cron
 *
 * Processes the queued_actions table and evaluates automation rules
 * against recent CRM events. Run every 5–15 minutes.
 *
 * Cron example:
 *   * /5 * * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Marketing/Cron/automation_runner.php
 *
 * @package Mowology\Marketing
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once CRM_INCLUDES . '/functions.php';
require_once CRM_INCLUDES . '/messaging.php';
require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';

$db = getDB();
$log = [];
$processed = 0;
$maxActions = 50; // Max queue items per run

function logMsg(string $msg): void {
    global $log;
    $ts = date('Y-m-d H:i:s');
    $log[] = "[$ts] $msg";
    if (PHP_SAPI === 'cli') { echo "[$ts] $msg\n"; }
}

logMsg("=== Automation Runner started ===");

// ── 1. Evaluate active triggers against recent events ─────────────────────

$rules = $db->query("
    SELECT * FROM automation_rules
    WHERE is_enabled = 1
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

logMsg("Found " . count($rules) . " enabled rules");

foreach ($rules as $rule) {
    try {
        evaluateRule($db, $rule);
    } catch (Throwable $e) {
        logMsg("ERROR evaluating rule #{$rule['id']} ({$rule['name']}): " . $e->getMessage());
        error_log("automation_runner rule #{$rule['id']}: " . $e->getMessage());
    }
}

// ── 2. Process pending queued actions ─────────────────────────────────────

$pending = $db->query("
    SELECT * FROM queued_actions
    WHERE status = 'pending'
      AND scheduled_at <= NOW()
      AND attempts < max_attempts
    ORDER BY scheduled_at ASC
    LIMIT $maxActions
")->fetchAll(PDO::FETCH_ASSOC);

logMsg("Processing " . count($pending) . " queued actions");

foreach ($pending as $qa) {
    $processed++;
    $db->prepare("UPDATE queued_actions SET status='processing', started_at=NOW(), attempts=attempts+1 WHERE id=?")->execute([$qa['id']]);

    try {
        $actionData = json_decode($qa['action_data'] ?? '{}', true) ?: [];
        $result = executeQueuedAction($db, $qa['action_type'], $actionData, (int)($qa['contact_id'] ?? 0));

        $db->prepare("UPDATE queued_actions SET status='completed', completed_at=NOW() WHERE id=?")->execute([$qa['id']]);
        logMsg("  Completed action #{$qa['id']} ({$qa['action_type']}) for contact #{$qa['contact_id']}");

    } catch (Throwable $e) {
        $willRetry = $qa['attempts'] < $qa['max_attempts'];
        $newStatus = $willRetry ? 'pending' : 'failed';
        $db->prepare("UPDATE queued_actions SET status=?, error_message=? WHERE id=?")->execute([$newStatus, $e->getMessage(), $qa['id']]);
        logMsg("  FAILED action #{$qa['id']} ({$qa['action_type']}): " . $e->getMessage() . ($willRetry ? " [will retry]" : " [max retries]"));
        error_log("queued_action #{$qa['id']}: " . $e->getMessage());
    }
}

logMsg("=== Done. Processed $processed actions ===");


// ── Rule Evaluator ─────────────────────────────────────────────────────────

function evaluateRule(PDO $db, array $rule): void
{
    $triggerType   = $rule['trigger_type'];
    $triggerConfig = json_decode($rule['trigger_config'] ?? '{}', true) ?: [];
    $conditions    = json_decode($rule['conditions']  ?? '[]', true) ?: [];
    $actions       = json_decode($rule['actions']     ?? '[]', true) ?: [];

    if (empty($actions)) return;

    $contacts = getContactsForTrigger($db, $triggerType, $triggerConfig, $rule);
    if (empty($contacts)) return;

    logMsg("Rule #{$rule['id']} ({$rule['name']}): {$triggerType} matched " . count($contacts) . " contacts");

    foreach ($contacts as $contact) {
        $contactId = (int)$contact['id'];

        // Cooldown check: don't re-trigger if recently executed for this contact+rule
        if ($rule['cooldown_hours'] > 0) {
            $lastLog = $db->prepare("
                SELECT id FROM automation_logs
                WHERE rule_id=? AND contact_id=? AND status='completed'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                LIMIT 1
            ");
            $lastLog->execute([$rule['id'], $contactId, $rule['cooldown_hours']]);
            if ($lastLog->fetchColumn()) continue;
        }

        // Max executions check (global, not per-contact)
        if ($rule['max_executions'] > 0 && $rule['execution_count'] >= $rule['max_executions']) {
            $db->prepare("UPDATE automation_rules SET is_enabled=0 WHERE id=?")->execute([$rule['id']]);
            logMsg("Rule #{$rule['id']}: max executions reached, disabled.");
            break;
        }

        // Evaluate conditions
        if (!empty($conditions)) {
            $pass = evaluateConditions($db, $contactId, $conditions);
            if (!$pass) {
                logRow($db, $rule['id'], $contactId, $triggerType, 'conditions_failed');
                continue;
            }
        }

        // Queue all actions for this contact
        $scheduledAt = date('Y-m-d H:i:s');
        foreach ($actions as $i => $action) {
            // Small delay offset between chained actions
            $delay = $i * 60; // 60 second gap between actions
            $schedTime = date('Y-m-d H:i:s', time() + $delay);

            $db->prepare("
                INSERT INTO queued_actions (rule_id, contact_id, action_type, action_data, status, scheduled_at, created_at)
                VALUES (?, ?, ?, ?, 'pending', ?, NOW())
            ")->execute([
                $rule['id'],
                $contactId,
                $action['type'] ?? 'unknown',
                json_encode(array_merge($action['config'] ?? [], ['contact' => $contact])),
                $schedTime,
            ]);
        }

        logRow($db, $rule['id'], $contactId, $triggerType, 'triggered');
        $db->prepare("UPDATE automation_rules SET execution_count=execution_count+1, last_executed_at=NOW() WHERE id=?")->execute([$rule['id']]);
    }
}


// ── Trigger Resolvers ──────────────────────────────────────────────────────

function getContactsForTrigger(PDO $db, string $type, array $config, array $rule): array
{
    $lastRun = $rule['last_executed_at'] ?? date('Y-m-d H:i:s', time() - 3600);

    $base = "SELECT DISTINCT c.id, c.first_name, c.last_name, c.email, c.receive_marketing FROM contacts c";
    $mktFilter = "c.is_active=1 AND c.email IS NOT NULL AND c.email != ''";

    switch ($type) {

        case 'quote_created':
            $stmt = $db->prepare("$base
                INNER JOIN quotes q ON q.contact_id = c.id
                WHERE $mktFilter AND q.created_at >= ?
            ");
            $stmt->execute([$lastRun]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'quote_viewed':
            $hours = (int)($config['hours_since_view'] ?? 24);
            $stmt = $db->prepare("$base
                INNER JOIN quotes q ON q.contact_id = c.id
                WHERE $mktFilter
                  AND q.last_viewed_at IS NOT NULL
                  AND q.last_viewed_at BETWEEN DATE_SUB(NOW(), INTERVAL ? HOUR) AND DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  AND q.status = 'sent'
            ");
            $stmt->execute([$hours + 1]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'quote_followup':
            // Fires for sent quotes with no acceptance after N days.
            // Returns contact + quote data so send_email can personalise the message.
            $days = (int)($config['days_since_sent'] ?? 3);
            $stmt = $db->prepare("
                SELECT
                    c.id, c.first_name, c.last_name, c.email, c.receive_marketing,
                    q.id          AS quote_id,
                    q.quote_number,
                    q.amount      AS quote_amount,
                    q.valid_until AS quote_valid_until,
                    q.access_token AS quote_token,
                    q.sent_at,
                    q.follow_up_count,
                    q.follow_up_sent_at
                FROM contacts c
                INNER JOIN quotes q ON q.contact_id = c.id
                WHERE $mktFilter
                  AND q.status = 'sent'
                  AND q.sent_at IS NOT NULL
                  AND q.sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND q.follow_up_count < 3
                  AND (q.follow_up_sent_at IS NULL
                       OR q.follow_up_sent_at < DATE_SUB(NOW(), INTERVAL 6 DAY))
                ORDER BY q.sent_at ASC
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'quote_accepted':
            $stmt = $db->prepare("$base
                INNER JOIN quotes q ON q.contact_id = c.id
                WHERE $mktFilter AND q.status='accepted' AND q.accepted_at >= ?
            ");
            $stmt->execute([$lastRun]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'job_completed':
            $stmt = $db->prepare("$base
                INNER JOIN jobs j ON j.contact_id = c.id
                WHERE $mktFilter AND j.status='completed' AND j.completed_at >= ?
            ");
            $stmt->execute([$lastRun]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'invoice_overdue':
            $days = (int)($config['overdue_days'] ?? 7);
            $stmt = $db->prepare("$base
                INNER JOIN invoices i ON i.contact_id = c.id
                WHERE $mktFilter
                  AND i.status IN ('sent','overdue')
                  AND i.due_date < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND i.paid_at IS NULL
            ");
            $stmt->execute([$days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'client_inactive':
            $inactiveDays = (int)($config['inactive_days'] ?? 90);
            $stmt = $db->query("$base
                WHERE $mktFilter
                  AND (c.last_service_date IS NULL OR c.last_service_date < DATE_SUB(NOW(), INTERVAL $inactiveDays DAY))
                  AND c.receive_marketing = 1
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'tagged':
            $tag = $db->quote($config['tag'] ?? '');
            $stmt = $db->query("$base
                INNER JOIN contact_tags ct ON ct.contact_id = c.id
                WHERE $mktFilter
                  AND ct.tag = $tag
                  AND c.receive_marketing = 1
                  AND c.email NOT IN (SELECT email COLLATE utf8mb4_general_ci FROM marketing_unsubscribes)
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'season_change':
            $season = $config['season'] ?? '';
            $month  = (int)date('n');
            $currentSeason = match(true) {
                in_array($month, [3, 4, 5])  => 'spring',
                in_array($month, [6, 7, 8])  => 'summer',
                in_array($month, [9, 10, 11])=> 'fall',
                default                      => 'winter',
            };
            if ($season !== $currentSeason) return [];
            // Only fire once per season: check if last_executed_at is before this season start
            $seasonStart = match($currentSeason) {
                'spring' => date('Y') . '-03-01',
                'summer' => date('Y') . '-06-01',
                'fall'   => date('Y') . '-09-01',
                'winter' => date('Y') . '-12-01',
            };
            if ($rule['last_executed_at'] && $rule['last_executed_at'] >= $seasonStart) return [];
            $stmt = $db->query("$base WHERE $mktFilter AND c.receive_marketing=1");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        case 'product_not_purchased':
            $productId = (int)($config['product_id'] ?? 0);
            if (!$productId) return [];
            $stmt = $db->prepare("$base
                WHERE $mktFilter
                  AND c.receive_marketing = 1
                  AND c.id NOT IN (SELECT contact_id FROM contact_product_history WHERE product_id = ?)
            ");
            $stmt->execute([$productId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        default:
            return [];
    }
}


// ── Condition Evaluator ────────────────────────────────────────────────────

function evaluateConditions(PDO $db, int $contactId, array $conditions): bool
{
    foreach ($conditions as $cond) {
        $field    = $cond['field']    ?? '';
        $operator = $cond['operator'] ?? 'eq';
        $value    = $cond['value']    ?? '';

        $pass = evaluateSingleCondition($db, $contactId, $field, $operator, $value);
        if (!$pass) return false;
    }
    return true;
}

function evaluateSingleCondition(PDO $db, int $contactId, string $field, string $op, $value): bool
{
    switch ($field) {
        case 'tag':
            $stmt = $db->prepare("SELECT COUNT(*) FROM contact_tags WHERE contact_id=? AND tag=?");
            $stmt->execute([$contactId, $value]);
            $has = (bool)$stmt->fetchColumn();
            return $op === 'has' ? $has : !$has;

        case 'receive_marketing':
            $stmt = $db->prepare("SELECT receive_marketing FROM contacts WHERE id=?");
            $stmt->execute([$contactId]);
            $actual = (int)$stmt->fetchColumn();
            return compare($actual, $op, (int)$value);

        case 'days_since_service':
            $stmt = $db->prepare("SELECT DATEDIFF(NOW(), last_service_date) FROM contacts WHERE id=?");
            $stmt->execute([$contactId]);
            $days = $stmt->fetchColumn();
            if ($days === false) $days = 9999;
            return compare((int)$days, $op, (int)$value);

        case 'city':
            $stmt = $db->prepare("SELECT p.city FROM properties p INNER JOIN contacts c ON c.id=p.site_contact_id WHERE c.id=? LIMIT 1");
            $stmt->execute([$contactId]);
            $actual = strtolower((string)$stmt->fetchColumn());
            $v = strtolower($value);
            return match($op) {
                'eq'       => $actual === $v,
                'not_eq'   => $actual !== $v,
                'contains' => str_contains($actual, $v),
                default    => false,
            };

        default:
            return true; // Unknown condition: pass
    }
}

function compare($actual, string $op, $value): bool
{
    return match($op) {
        'eq'  => $actual == $value,
        'gt'  => $actual >  $value,
        'gte' => $actual >= $value,
        'lt'  => $actual <  $value,
        'lte' => $actual <= $value,
        'not_eq' => $actual != $value,
        default => true,
    };
}


// ── Action Executor ────────────────────────────────────────────────────────

function executeQueuedAction(PDO $db, string $type, array $data, int $contactId): void
{
    switch ($type) {

        case 'add_tag':
            $tag = $data['tag'] ?? '';
            if ($tag && $contactId) {
                $db->prepare("INSERT IGNORE INTO contact_tags (contact_id, tag, added_at) VALUES (?, ?, NOW())")->execute([$contactId, $tag]);
            }
            break;

        case 'remove_tag':
            $tag = $data['tag'] ?? '';
            if ($tag && $contactId) {
                $db->prepare("DELETE FROM contact_tags WHERE contact_id=? AND tag=?")->execute([$contactId, $tag]);
            }
            break;

        case 'notify_admin':
            $message = $data['message'] ?? 'Automation triggered';
            $contact = $data['contact'] ?? [];
            $first = $contact['first_name'] ?? '';
            $last  = $contact['last_name']  ?? '';
            $message = str_replace(['{{first_name}}', '{{last_name}}'], [$first, $last], $message);
            // Log to activity
            error_log("AUTOMATION ADMIN NOTIFY: $message (contact #$contactId)");
            // Future: insert into notifications table
            break;

        case 'send_campaign':
            $campaignId = (int)($data['campaign_id'] ?? 0);
            $templateCategory = $data['template_category'] ?? '';

            if (!$campaignId && $templateCategory) {
                // Find a campaign using a template of this category
                $stmt = $db->prepare("
                    SELECT mc.id FROM marketing_campaigns mc
                    INNER JOIN email_templates et ON et.id = mc.template_id
                    WHERE et.category = ? AND mc.status IN ('draft','scheduled')
                    LIMIT 1
                ");
                $stmt->execute([$templateCategory]);
                $campaignId = (int)$stmt->fetchColumn();
            }

            if ($campaignId && $contactId) {
                // Check not already in this campaign
                $exists = $db->prepare("SELECT id FROM campaign_sends WHERE campaign_id=? AND contact_id=? AND status IN ('pending','sent')");
                $exists->execute([$campaignId, $contactId]);
                if (!$exists->fetchColumn()) {
                    $db->prepare("INSERT INTO campaign_sends (campaign_id, contact_id, status, created_at) SELECT ?, ?, 'pending', NOW() FROM contacts WHERE id=? AND email IS NOT NULL LIMIT 1")
                       ->execute([$campaignId, $contactId, $contactId]);
                    $db->prepare("UPDATE marketing_campaigns SET status='sending', recipient_count=recipient_count+1 WHERE id=? AND status IN ('draft','scheduled','queued','paused')")->execute([$campaignId]);
                }
            }
            break;

        case 'send_email':
            if (!$contactId) break;
            $stmt = $db->prepare("SELECT first_name, last_name, email FROM contacts WHERE id=?");
            $stmt->execute([$contactId]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($contact && !empty($contact['email'])) {
                // Always honour the unsubscribe blocklist — even for transactional automations
                $unsubCheck = $db->prepare("SELECT 1 FROM marketing_unsubscribes WHERE email = ? LIMIT 1");
                $unsubCheck->execute([strtolower(trim($contact['email']))]);
                if ($unsubCheck->fetchColumn()) {
                    logMsg("  [SKIPPED] Contact #$contactId on unsubscribe list — no email sent");
                    break;
                }
                // Build template vars — include quote data if this action came from a quote trigger
                $quoteData   = $data['contact'] ?? [];
                $quoteNumber = $quoteData['quote_number'] ?? '';
                $quoteAmount = isset($quoteData['quote_amount'])
                    ? '$' . number_format((float)$quoteData['quote_amount'], 2)
                    : '';
                $quoteValidUntil = !empty($quoteData['quote_valid_until'])
                    ? date('F j, Y', strtotime($quoteData['quote_valid_until']))
                    : '';
                $quoteUrl = !empty($quoteData['quote_token'])
                    ? 'https://mowology.ca/customer/quote.php?token=' . urlencode($quoteData['quote_token'])
                    : '';

                $vars = [
                    'first_name'        => $contact['first_name'],
                    'last_name'         => $contact['last_name'],
                    'quote_number'      => $quoteNumber,
                    'quote_amount'      => $quoteAmount,
                    'quote_valid_until' => $quoteValidUntil,
                    'quote_url'         => $quoteUrl,
                ];

                // Prefer loading from the named template (use_template key) for HTML wrapper
                $emailBody    = '';
                $emailSubject = '';
                if (!empty($data['use_template'])) {
                    try {
                        require_once PUBLIC_ROOT . '/app/Services/Messaging/EmailWrapper.php';
                        $tplVars = [
                            '{{customer_first_name}}' => $contact['first_name'],
                            '{{customer_name}}'       => trim($contact['first_name'] . ' ' . $contact['last_name']),
                            '{{quote_number}}'        => $quoteNumber,
                            '{{quote_amount}}'        => $quoteAmount,
                            '{{quote_valid_until}}'   => $quoteValidUntil,
                            '{{company_name}}'        => 'Mowology Landscaping',
                            '{{company_phone}}'       => '(778) 846-9273',
                        ];
                        $tpl = loadEmailTemplate($data['use_template'], $tplVars);
                        if ($tpl && !empty($tpl['subject'])) {
                            $emailSubject = $tpl['subject'];
                            $emailBody = $quoteUrl
                                ? EmailWrapper::wrap($tpl['body_html'], 'View Your Quote', $quoteUrl, EmailWrapper::getCompanyInfo())
                                : $tpl['body_html'];
                        }
                    } catch (Throwable $tplEx) {
                        error_log("automation send_email template load failed: " . $tplEx->getMessage());
                    }
                }

                // Fallback to inline subject/body from action config
                if (empty($emailBody)) {
                    $emailSubject = renderTemplate($data['subject'] ?? 'Message from Mowology', $vars);
                    $emailBody    = renderTemplate($data['body'] ?? '', $vars);
                }

                if ($emailSubject && $emailBody) {
                    sendCrmEmail($contact['email'], $emailSubject, $emailBody, trim($contact['first_name'] . ' ' . $contact['last_name']));

                    // If this was a quote follow-up, record the send
                    if (!empty($quoteData['quote_id'])) {
                        try {
                            $db->prepare("
                                UPDATE quotes
                                SET follow_up_sent_at = NOW(),
                                    follow_up_count   = follow_up_count + 1
                                WHERE id = ?
                            ")->execute([(int)$quoteData['quote_id']]);
                        } catch (Throwable $qEx) {
                            error_log("automation: failed to update follow_up tracking for quote #{$quoteData['quote_id']}: " . $qEx->getMessage());
                        }
                    }
                }
            }
            break;

        case 'create_task':
            // Future: insert into tasks table when implemented
            $title = $data['title'] ?? 'Follow up';
            $dueDays = (int)($data['due_days'] ?? 2);
            $dueDate = date('Y-m-d', strtotime("+$dueDays days"));
            logMsg("  [TODO] Create task '$title' for contact #$contactId due $dueDate");
            break;

        case 'schedule_sms':
            // Future-ready: log intent; actual sending requires SMS queue
            $msg = substr($data['message'] ?? '', 0, 160);
            logMsg("  [SMS-QUEUED] For contact #$contactId: " . $msg);
            break;

        default:
            logMsg("  Unknown action type: $type");
    }
}


// ── Helper ─────────────────────────────────────────────────────────────────

function logRow(PDO $db, int $ruleId, int $contactId, string $triggerType, string $status): void
{
    $db->prepare("INSERT INTO automation_logs (rule_id, contact_id, trigger_type, status, created_at) VALUES (?,?,?,?,NOW())")
       ->execute([$ruleId, $contactId, $triggerType, $status]);
}
