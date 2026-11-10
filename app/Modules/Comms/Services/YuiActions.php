<?php
declare(strict_types=1);

/**
 * What Yui's cards do, in one place: /crm/api/yui.php (session, CSRF) and team-mobile.php (JWT) both call dispatch().
 * The phone never sends a trusted request: phoneRequest() finds the card in the server's own queue
 * (YuiJobAdapter::jobs) and takes the request and the editable field names from THAT card, merging only
 * the values the phone supplies for those names.
 *
 * dispatch() returns the JSON-ready array; a refused mode carries `_status` (the HTTP code the caller should set).
 */
final class YuiActions
{
    /** Modes the phone may ask for. */
    public const PHONE_MODES = ['send', 'handled', 'who_confirm', 'who_not', 'who_undo', 'draft', 'who_accounts'];

    /** @return array<string, mixed> */
    public static function dispatch(PDO $db, string $mode, array $input, array $user, string $owner): array
    {
        require_once __DIR__ . '/YuiDeskService.php';
        require_once __DIR__ . '/YuiDraftService.php';
        require_once __DIR__ . '/InboundQuestionService.php';
        $desk = new YuiDeskService($db);
        if (!$desk->ready()) return ['ok' => false, 'error' => 'Migration 1191 has not run yet'];
        $drafts = new YuiDraftService($db);
        $key = substr((string)($input['key'] ?? ''), 0, 120);
        $gone = ['ok' => false, 'message' => 'That one is no longer on my list — refresh.'];

        switch ($mode) {
            case 'jobs':   // Yui's decisions, one card at a time (desktop strip, iPhone carousel)
                require_once __DIR__ . '/YuiJobAdapter.php';
                return ['ok' => true, 'jobs' => YuiJobAdapter::jobs($db, $owner)];

            case 'desk':
                $out = ['ok' => true, 'name' => $owner] + $desk->desk($owner);
                $who = new InboundQuestionService($db);
                if ($who->ready()) $out['who'] = ['items' => $who->questions(), 'quiet_count' => $who->quietCount(), 'quiet' => $who->quiet()];
                return $out;

            case 'who_accounts':
                $who = new InboundQuestionService($db);
                return ['ok' => true, 'accounts' => $who->ready() ? $who->accounts(mb_substr(trim((string)($input['q'] ?? '')), 0, 60)) : []];

            case 'who_confirm':
            case 'who_not':
            case 'who_undo': {
                $who = new InboundQuestionService($db);
                if (!$who->ready()) return ['ok' => false, 'message' => 'Migration 1447 has not run yet.'];
                $wid = (int)($input['id'] ?? 0);
                $uid = (int)($user['id'] ?? 0);
                if ($mode === 'who_confirm') {
                    return $who->confirm($wid, (int)($input['client_id'] ?? 0), mb_substr(trim((string)($input['first_name'] ?? '')), 0, 100),
                                         mb_substr(trim((string)($input['last_name'] ?? '')), 0, 100), $uid);
                }
                return $mode === 'who_not' ? $who->notClient($wid, $uid) : $who->undo($wid, $uid);
            }

            case 'draft': {
                $item = $desk->find($key, $owner);
                return $item ? $drafts->draftReply($item, $owner, (int)$user['id']) : $gone;
            }

            case 'send': {
                $item = $desk->find($key, $owner);
                return $item ? $drafts->send($item, $input, $user, $owner) : $gone;
            }

            case 'handled': {
                if (strpos($key, 'yui:') !== 0) return $gone;
                $item = $desk->find($key, $owner);
                return $drafts->handled($key, (string)($item['kind'] ?? ''), isset($item['contact_id']) ? (int)$item['contact_id'] : null, $user);
            }
        }
        return ['ok' => false, 'error' => 'Unknown mode', '_status' => 400];
    }

    /**
     * The phone's {request, fields} → the input to dispatch(), or null when it is not allowed / not a current card.
     * @param array<int, array<string, mixed>> $jobs the server's own current YuiJobAdapter::jobs()
     */
    public static function phoneRequest(array $jobs, array $request, array $fields): ?array
    {
        $mode = (string)($request['mode'] ?? '');
        if (!in_array($mode, self::PHONE_MODES, true)) return null;
        if ($mode === 'who_accounts') {   // a search, no card behind it
            return ['mode' => 'who_accounts', 'q' => mb_substr(trim((string)($fields['q'] ?? $request['q'] ?? '')), 0, 60)];
        }
        if ($mode === 'who_undo') {   // the answered card has left the queue; undo() itself only reverses a decision under a day old
            $id = (int)($request['id'] ?? 0);
            return $id > 0 ? ['mode' => 'who_undo', 'id' => $id] : null;
        }
        $job = self::findJob($jobs, $request);
        if ($job === null) return null;
        $base = $job['request'];
        $merged = $base;
        foreach ((array)($job['job']['fields'] ?? []) as $name) {
            $name = (string)$name;
            if (!array_key_exists($name, $fields) || !is_scalar($fields[$name])) continue;
            if (!self::mergesInto($mode, $name)) continue;
            $v = $fields[$name];
            $merged[$name] = $name === 'client_id' ? (int)$v : mb_substr((string)$v, 0, $name === 'body' ? 10000 : 500);
        }
        // The balance the phone showed, so a payment landing since is refused rather than contradicting the PDF (YuiDraftService).
        if ($mode === 'send' && ($merged['template'] ?? '') === 'statement') {
            $merged['closing'] = isset($fields['closing']) && is_numeric($fields['closing']) ? (float)$fields['closing'] : -1.0;
        }
        return $merged;
    }

    /** Only the primary action takes the card's editable fields; handled / not-a-client / undo / draft just name the item. */
    private static function mergesInto(string $mode, string $field): bool
    {
        return in_array($mode, ['send', 'who_confirm'], true);
    }

    /** @return array{job: array<string, mixed>, request: array<string, mixed>}|null the card owning this request and the card's own copy of it */
    public static function findJob(array $jobs, array $request): ?array
    {
        $mode = (string)($request['mode'] ?? '');
        foreach ($jobs as $job) {
            foreach (['primary', 'secondary', 'undo', 'draft'] as $slot) {
                $r = $slot === 'draft' ? ($job['draft'] ?? null) : ($job[$slot]['request'] ?? null);
                if (!is_array($r) || (string)($r['mode'] ?? '') !== $mode) continue;
                if (isset($r['key']) ? (string)$r['key'] === (string)($request['key'] ?? '') : (isset($r['id']) && (int)$r['id'] === (int)($request['id'] ?? 0))) {
                    return ['job' => $job, 'request' => $r];
                }
            }
        }
        return null;
    }
}
