<?php
declare(strict_types=1);

/**
 * SubcontractRules — what a subcontracted job may do next and what its private page may show.
 *
 * pagePayload() is the only thing the subcontractor's page reads. It is a list of what is allowed, not a list
 * of what is hidden, so a column added to a query later cannot reach her page by accident.
 *
 * Pure (unit tested). No namespace / no autoloader in production: require_once.
 */
final class SubcontractRules
{
    public const WORK_STATUSES = ['sent', 'accepted', 'declined', 'scheduled', 'on_way', 'started', 'finished', 'cancelled'];
    public const BILL_STATUSES = ['none', 'sent', 'approved', 'paid', 'returned'];
    private const HAS_THE_JOB = ['accepted', 'scheduled', 'on_way', 'started', 'finished'];

    /** A step tapped by mistake can be taken back for this long, until the owner has accepted the work. */
    public const UNDO_MINUTES = 10;
    /** How far from the property's pin still counts as at the property. */
    public const ON_SITE_METRES = 150;
    public const LINK_DAYS = 90;
    /** A link keeps working this long after the job closes, so she can still see that she was paid. */
    public const CLOSED_LINK_DAYS = 30;

    private const TRANSITIONS = [
        'sent'      => ['accepted', 'declined', 'cancelled'],
        'accepted'  => ['scheduled', 'on_way', 'started', 'cancelled'],
        'scheduled' => ['scheduled', 'on_way', 'started', 'cancelled'],
        'on_way'    => ['started', 'cancelled'],
        'started'   => ['finished', 'cancelled'],
    ];
    private const UNDO_TO = ['on_way' => 'scheduled', 'finished' => 'started'];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** The status an undo goes back to, or null when this step cannot be undone now. */
    public static function undoTarget(array $job, string $now): ?string
    {
        $status = (string)($job['work_status'] ?? '');
        if (!isset(self::UNDO_TO[$status]) || !empty($job['work_accepted_at'])) return null;
        if ($status === 'finished' && (string)($job['bill_status'] ?? 'none') !== 'none') return null;
        $changed = strtotime((string)($job['status_changed_at'] ?? ''));
        $at = strtotime($now);
        if ($changed === false || $at === false || $at - $changed > self::UNDO_MINUTES * 60) return null;
        return self::UNDO_TO[$status];
    }

    /** The one thing her page asks her to do now. */
    public static function nextStep(array $job): string
    {
        $bill = (string)($job['bill_status'] ?? 'none');
        switch ((string)($job['work_status'] ?? '')) {
            case 'sent':      return 'respond';
            case 'accepted':  return 'pick_day';
            case 'scheduled': return 'on_my_way';
            case 'on_way':    return 'start';
            case 'started':   return !empty($job['after_the_fact']) ? 'send_proof' : 'finish';
            case 'finished':
                if ($bill === 'none' || $bill === 'returned') return 'send_bill';
                if ($bill === 'sent') return 'bill_waiting';
                if ($bill === 'approved') return 'payment_waiting';
                return 'done';
            default:          return 'closed';
        }
    }

    /** Work done off the system whose invoice the owner already has: the link asks only for photos. */
    public static function billOnFile(array $job): bool
    {
        return !empty($job['after_the_fact']) && empty($job['bill_expense_id']) && (string)($job['bill_status'] ?? 'none') !== 'none' && (string)($job['bill_status'] ?? '') !== 'returned';
    }

    public static function isClosed(array $job): bool
    {
        return in_array(self::nextStep($job), ['closed', 'done'], true);
    }

    /** ok | revoked | expired — revoked first, so a cancelled link never reads as simply out of date. */
    public static function linkState(array $job, string $now): string
    {
        if (!empty($job['revoked_at'])) return 'revoked';
        $expires = strtotime((string)($job['expires_at'] ?? ''));
        $at = strtotime($now);
        if ($expires === false || $at === false || $expires <= $at) return 'expired';
        return 'ok';
    }

    public static function expiryFrom(string $now, bool $closed = false): string
    {
        return date('Y-m-d H:i:s', (int)strtotime($now) + 86400 * ($closed ? self::CLOSED_LINK_DAYS : self::LINK_DAYS));
    }

    /**
     * Was this one reading at the property? null when it cannot be said: no reading, no pin on the property,
     * or a reading too rough to tell.
     */
    public static function onSite(?float $lat, ?float $lng, ?int $accuracyM, ?float $siteLat, ?float $siteLng): ?bool
    {
        if ($lat === null || $lng === null || $siteLat === null || $siteLng === null) return null;
        if (($siteLat == 0.0 && $siteLng == 0.0) || abs($lat) > 90 || abs($lng) > 180) return null;
        $a = sin(deg2rad($siteLat - $lat) / 2) ** 2
           + cos(deg2rad($lat)) * cos(deg2rad($siteLat)) * sin(deg2rad($siteLng - $lng) / 2) ** 2;
        $metres = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
        $accuracy = max(0, (int)$accuracyM);
        if ($metres <= self::ON_SITE_METRES) return true;
        // Far outside even after giving the reading its full margin of error.
        return $metres - $accuracy > self::ON_SITE_METRES ? false : null;
    }

    /** @return ?string what proof is still missing, in words for her, or null. Work done off the system may have no photo, so a note can stand in. */
    public static function proofProblem(bool $afterTheFact, int $photos, string $note): ?string
    {
        if ($photos > 0) return null;
        if (!$afterTheFact) return 'Add at least one photo of the finished work.';
        return mb_strlen(trim($note)) >= 10 ? null : 'Add a photo, or say what you did.';
    }

    public static function minutesOnJob(?string $startedAt, ?string $finishedAt): ?int
    {
        $from = strtotime((string)$startedAt);
        $to = strtotime((string)$finishedAt);
        if (!$startedAt || !$finishedAt || $from === false || $to === false || $to < $from) return null;
        return (int)round(($to - $from) / 60);
    }

    /**
     * Her own record of the job: only these steps, in her words, so a staff note or the location never reaches her page.
     * @return list<array{word:string, at:string}>
     */
    public static function recordFor(array $events): array
    {
        $words = ['accepted' => 'You took the job', 'scheduled' => 'Day arranged', 'on_way' => 'On your way', 'started' => 'Started', 'finished' => 'Finished',
                  'work_accepted' => 'Work accepted', 'bill_sent' => 'Bill sent', 'bill_returned' => 'Bill sent back', 'paid' => 'Paid'];
        $out = [];
        foreach ($events as $event) {
            $type = (string)($event['event_type'] ?? '');
            if (isset($words[$type])) $out[] = ['word' => $words[$type], 'at' => (string)($event['created_at'] ?? '')];
        }
        return $out;
    }

    /** @return ?string the extension to store a file under, or null when this kind of file is not accepted */
    public static function extensionFor(string $kind, string $mime): ?string
    {
        $images = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];
        if ($kind === 'photo') return $images[$mime] ?? null;
        if ($kind === 'invoice') return ($images + ['application/pdf' => 'pdf'])[$mime] ?? null;
        return null;
    }

    /** @return ?string what is wrong with a bill, in words for her, or null */
    public static function billProblem(float $amount, float $gst, string $invoiceNo): ?string
    {
        if ($amount <= 0) return 'Enter the amount before GST.';
        if ($amount > 250000) return 'That amount looks too large. Check it and try again.';
        if ($gst < 0) return 'GST can\'t be negative.';
        if ($gst > round($amount * 0.15, 2)) return 'That GST looks too high for this amount.';
        if (mb_strlen(trim($invoiceNo)) > 60) return 'Keep the invoice number under 60 characters.';
        return null;
    }

    /**
     * What her page may show. $context is the joined visit, plan, property and contact row; $workItems are the names of
     * the work only. Prices the client pays, billing details, client email and other jobs are not read here at all.
     */
    public static function pagePayload(array $job, array $context, array $workItems, string $now): array
    {
        $address = trim(implode(', ', array_filter([(string)($context['property_address'] ?? ''), (string)($context['property_city'] ?? '')])));
        $clientName = !empty($job['share_client_name'])
            ? trim(($context['contact_first_name'] ?? '') . ' ' . ($context['contact_last_name'] ?? ''))
            : '';
        return [
            'next_step'     => self::nextStep($job),
            'work_status'   => (string)($job['work_status'] ?? ''),
            'bill_status'   => (string)($job['bill_status'] ?? 'none'),
            'can_undo'      => self::undoTarget($job, $now) !== null,
            'title'         => (string)($context['plan_title'] ?? ''),
            'date'          => (string)($job['planned_date'] ?? $context['scheduled_date'] ?? ''),
            'address'       => $address,
            'client_name'   => $clientName,
            // She arranges the visit with the client, so she gets the number once she has taken the job, not before.
            'client_phone'  => !empty($job['share_client_phone']) && empty($job['after_the_fact']) && in_array($job['work_status'] ?? '', self::HAS_THE_JOB, true)
                ? (string)($context['contact_phone'] ?? '') : '',
            'after_the_fact' => !empty($job['after_the_fact']),
            'bill_received' => self::billOnFile($job),
            'work_items'    => array_values(array_filter(array_map(static fn ($w) => trim((string)$w), $workItems), 'strlen')),
            'note'          => (string)($job['note_to_sub'] ?? ''),
            'agreed_amount' => ($job['agreed_amount'] ?? null) !== null && $job['agreed_amount'] !== '' ? (float)$job['agreed_amount'] : null,
            'started_at'    => $job['started_at'] ?? null,
            'finished_at'   => $job['finished_at'] ?? null,
            'work_accepted' => !empty($job['work_accepted_at']),
            'paid_at'       => $job['bill_paid_at'] ?? null,
        ];
    }

    /** The owner's push for a step: [title, body], or null for a step that needs no push. */
    public static function pushFor(string $event, string $company, string $place): ?array
    {
        $words = [
            'accepted'  => 'took the job at',
            'declined'  => 'said no to the job at',
            'on_way'    => 'is on the way to',
            'started'   => 'started at',
            'finished'  => 'finished at',
            'proof_sent' => 'sent proof of work for',
            'ittai_interest' => 'asked about running their jobs on Ittai, from',
            'bill_sent' => 'sent a bill for',
        ];
        if (!isset($words[$event])) return null;
        return [in_array($event, ['finished', 'proof_sent'], true) ? 'Ready for your OK' : 'Subcontract', trim("{$company} {$words[$event]} {$place}")];
    }
}
