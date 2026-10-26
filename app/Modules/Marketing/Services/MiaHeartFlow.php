<?php
/**
 * MiaHeartFlow — what the owner's heart on a finished visit now starts (Tim 2026-10-26: "my heart counts as the pair approval,
 * consent still checked").
 *
 * For a single-operator business (`mia_single_operator` = 1) the heart is the endorsement AND the manager's approval of the
 * before/after pair, so there is no second queue to visit. Within seconds the pair is approved (website copies made), Mia is told,
 * and the story and every channel's preview are waiting as ONE question on the phone. Consent is still checked by the same rule as
 * ever (BeforeAfterService::canApprove): a strata, commercial or managed property stays pending until the client's agreement is
 * recorded. Only an owner's heart counts: a crew member's heart still goes to the owner's queue as before.
 *
 * It never slows the heart tap: the work runs after the response has been sent (fastcgi_finish_request where available), and every
 * failure is logged and swallowed. When `mia_publish_mode` is `bundle` the older template post that the heart used to draft from
 * the raw photos is switched off (the bundle replaces it), see skipsOldDraft().
 *
 * Global namespace, no autoloader. PHP 7.4-compatible.
 */
declare(strict_types=1);

final class MiaHeartFlow
{
    private static function setting(PDO $db, string $name): ?string
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            foreach (['mia_s1_' . $name, 'mia_' . $name] as $k) {
                $s->execute([$k]);
                $v = $s->fetchColumn();
                if ($v !== false && $v !== null && $v !== '') { return (string)$v; }
            }
        } catch (Throwable $e) { /* no settings: off */ }
        return null;
    }

    public static function singleOperator(PDO $db): bool { return self::setting($db, 'single_operator') === '1'; }

    /** The bundle replaces the older template post the heart used to draft. */
    public static function skipsOldDraft(PDO $db): bool { return self::setting($db, 'publish_mode') === 'bundle'; }

    /** Run after the response is sent. Safe to call from any endorsement path: it costs one settings read when the feature is off. */
    public static function schedule(int $visitId, int $userId): void
    {
        try {
            $db = getDB();
            if (!self::singleOperator($db)) { return; }
            register_shutdown_function(static function () use ($visitId, $userId): void {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                try { self::run(getDB(), $visitId, $userId); } catch (Throwable $e) { error_log('MiaHeartFlow: ' . $e->getMessage()); }
            });
        } catch (Throwable $e) {
            error_log('MiaHeartFlow schedule: ' . $e->getMessage());
        }
    }

    /**
     * @return array{ran:bool, reason:?string, pair_id:?int, approved:bool}
     */
    public static function run(PDO $db, int $visitId, int $userId, ?callable $drain = null): array
    {
        $res = ['ran' => false, 'reason' => null, 'pair_id' => null, 'approved' => false];
        if (!self::singleOperator($db)) { $res['reason'] = 'off'; return $res; }
        $u = $db->prepare("SELECT role FROM users WHERE id = ?");
        $u->execute([$userId]);
        if (!in_array((string)$u->fetchColumn(), ['admin', 'owner'], true)) { $res['reason'] = 'not_owner'; return $res; }
        $f = $db->prepare("SELECT is_flagged FROM job_visits WHERE id = ?");
        $f->execute([$visitId]);
        if ((int)$f->fetchColumn() !== 1) { $res['reason'] = 'not_endorsed'; return $res; }

        if (!class_exists('BeforeAfterService')) { require_once APP_ROOT . '/Modules/Portfolio/Services/BeforeAfterService.php'; }
        $ba = new BeforeAfterService($db);
        $pairId = $ba->queueFromVisit($visitId, $userId);
        if ($pairId === null) {                                           // already queued earlier, or the after photo is not there yet
            $p = $db->prepare("SELECT id FROM ba_pairs WHERE visit_id = ? ORDER BY id DESC LIMIT 1");
            $p->execute([$visitId]);
            $found = $p->fetchColumn();
            $pairId = $found !== false ? (int)$found : null;
        }
        if ($pairId === null) { $res['reason'] = 'no_pair_yet'; return $res; }
        $res['pair_id'] = $pairId;
        $res['ran'] = true;

        $row = $db->prepare("SELECT status, consent_state FROM ba_pairs WHERE id = ?");
        $row->execute([$pairId]);
        $pair = $row->fetch(PDO::FETCH_ASSOC);
        if ($pair && $pair['status'] === 'pending' && BeforeAfterService::canApprove($pair + ['before_id' => 1, 'after_id' => 1])['ok']) {
            try { $ba->approve($pairId, $userId); $res['approved'] = true; }
            catch (Throwable $e) { error_log('MiaHeartFlow approve pair ' . $pairId . ': ' . $e->getMessage()); $res['reason'] = 'approve_failed'; return $res; }
        } elseif ($pair && $pair['status'] === 'approved') {
            $res['approved'] = true;
        } else {
            $res['reason'] = 'consent_or_state';                           // needs the client's agreement: stays in the queue
            return $res;
        }

        // Tell Mia now instead of waiting for the next 15-minute sweep.
        if (!class_exists('MiaEventService')) { require_once APP_ROOT . '/Modules/Marketing/Services/MiaEventService.php'; }
        if ($drain !== null) { call_user_func($drain, $db); } else { MiaEventService::drainAll($db); }
        return $res;
    }
}
