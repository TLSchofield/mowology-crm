<?php
declare(strict_types=1);

require_once __DIR__ . '/AgentRegistry.php';
require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
require_once dirname(__DIR__, 2) . '/Expenses/Services/BookkeeperDeskService.php';

/** Week-by-week, recorded results for the seven Agent Studio heads. */
final class HeadProgressService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return string[] */
    public static function weekStarts(int $weeks, string $today): array
    {
        if ($weeks < 1) {
            return [];
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if (!$day) {
            return [];
        }
        $monday = $day->modify('monday this week');
        $out = [];
        for ($i = $weeks - 1; $i >= 0; --$i) {
            $out[] = $monday->modify('-' . $i . ' weeks')->format('Y-m-d');
        }
        return $out;
    }

    /** @param array<string,int|float|string|null> $byDay @param string[] $weekStarts @return array<int,int|float> */
    public static function bucket(array $byDay, array $weekStarts): array
    {
        $out = array_fill(0, count($weekStarts), 0);
        if (!$weekStarts) {
            return $out;
        }
        foreach ($byDay as $day => $value) {
            if ($day < $weekStarts[0] || $value === null) {
                continue;
            }
            for ($i = count($weekStarts) - 1; $i >= 0; --$i) {
                if ($day >= $weekStarts[$i]) {
                    $out[$i] += (float)$value;
                    break;
                }
            }
        }
        return $out;
    }

    /** @return array{weeks:string[],heads:array<string,array<string,mixed>>} */
    public function weekly(int $weeks = 12, ?string $today = null): array
    {
        $starts = self::weekStarts(max(1, $weeks), $today ?? date('Y-m-d'));
        $from = $starts[0];
        $to = ($today ?? date('Y-m-d')) . ' 23:59:59';
        $heads = [];
        foreach (AgentRegistry::heads() as $slug => $identity) {
            $heads[$slug] = $identity + ['metrics' => []];
            $heads[$slug]['metrics']['ai_calls'] = $this->metric('AI requests', 'Requests sent to the AI model (logged from 28 Oct; 0 before then means not logged)', 'int', $this->days("SELECT DATE(created_at) d, COUNT(*) v FROM llm_calls WHERE head = ? AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$slug, $from, $to]), $starts);
            $heads[$slug]['metrics']['ai_cost'] = $this->metric('AI cost', 'Billed cost from the AI request log (from 28 Oct)', 'usd', $this->days("SELECT DATE(created_at) d, SUM(cost_usd) v FROM llm_calls WHERE head = ? AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$slug, $from, $to]), $starts);
            $heads[$slug]['metrics']['ai_problems'] = $this->metric('AI requests that failed', 'Requests whose recorded result was not OK', 'int', $this->days("SELECT DATE(created_at) d, COUNT(*) v FROM llm_calls WHERE head = ? AND outcome <> 'ok' AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$slug, $from, $to]), $starts);
        }

        $this->penny($heads['penny']['metrics'], $starts, $from, $to);
        $this->sam($heads['sam']['metrics'], $starts, $from, $to);
        $this->otto($heads['otto']['metrics'], $starts, $from, $to);
        $this->mia($heads['mia']['metrics'], $starts, $from, $to);
        $this->yui($heads['yui']['metrics'], $starts, $from, $to);
        return ['weeks' => $starts, 'heads' => $heads];
    }

    /** @param array<string,mixed> $m @param string[] $w */
    private function penny(array &$m, array $w, string $from, string $to): void
    {
        $live = "source = 'live'";
        // Receipts, not suggestion rows: a re-read receipt gets new rows and the old ones become 'superseded'.
        $read = $this->values("SELECT DATE(created_at) d, COUNT(DISTINCT expense_id) v FROM expense_suggestions WHERE {$live} AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $approved = "expense_id IN (SELECT id FROM expenses WHERE status IN ('approved','forwarded'))";
        // One answer per receipt: a receipt is kept when none of its answered suggestions was edited.
        [$decided, $kept] = $this->receiptAnswers("SELECT DATE(MAX(decided_at)) d, SUM(status = 'edited') edits FROM expense_suggestions WHERE {$live} AND status IN ('accepted','edited') AND {$approved} AND decided_at >= ? AND decided_at <= ? GROUP BY expense_id", [$from, $to], $w);
        $code = $this->values("SELECT DATE(created_at) d, COUNT(DISTINCT expense_id) v FROM expense_suggestions WHERE {$live} AND model = 'code' AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $cost = $this->values("SELECT DATE(created_at) d, SUM(input_tokens / 1000000.0 * " . BookkeeperDeskService::PRICE_IN . " + output_tokens / 1000000.0 * " . BookkeeperDeskService::PRICE_OUT . ") v FROM expense_suggestions WHERE {$live} AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $m['read'] = $this->madeMetric('Receipts read', 'Receipts Penny recorded reading', 'int', $read);
        $m['decided'] = $this->madeMetric('Answers you gave', 'Approved receipts whose reading you accepted or corrected', 'int', $decided);
        $m['kept'] = $this->madeMetric('Kept as Penny read them', 'Approved receipts with nothing corrected', 'int', $kept);
        $m['right_first_time'] = $this->madeMetric('Right first time', 'Kept as read, from weeks with at least 5 answers', 'pct', $this->ratio($kept, $decided, 5));
        $m['code_alone'] = $this->madeMetric('Read without AI', 'Receipts read using code alone', 'pct', $this->ratio($code, $read, 1));
        $m['ai_cost_est'] = $this->madeMetric('AI cost, estimated', "From Penny's token counts at list price, so it reaches back before the AI request log began on 28 Oct; the billed cost is the AI cost row above", 'usd', $cost);
        $m['cost_per_receipt'] = $this->madeMetric('AI cost per receipt, estimated', 'The estimate above divided by receipts read', 'usd', $this->divide($cost, $read));
    }

    /** @param array<string,mixed> $m @param string[] $w */
    private function sam(array &$m, array $w, string $from, string $to): void
    {
        $notTest = SalesDeskService::NOT_TEST_SQL;
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $notTest = "COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '') || ' ' || COALESCE(q.title, '') NOT LIKE '%ZZTEST%'";
        }
        $join = "quotes q LEFT JOIN contacts c ON c.id = q.contact_id";
        $sent = $this->values("SELECT DATE(q.sent_at) d, COUNT(*) v FROM {$join} WHERE q.sent_at >= ? AND q.sent_at <= ? AND {$notTest} GROUP BY DATE(q.sent_at)", [$from, $to], $w);
        $won = $this->values("SELECT DATE(q.accepted_at) d, COUNT(*) v FROM {$join} WHERE q.status = 'accepted' AND q.accepted_at >= ? AND q.accepted_at <= ? AND {$notTest} GROUP BY DATE(q.accepted_at)", [$from, $to], $w);
        $lost = $this->values("SELECT DATE(q.updated_at) d, COUNT(*) v FROM {$join} WHERE q.status IN ('declined','expired') AND q.updated_at >= ? AND q.updated_at <= ? AND {$notTest} GROUP BY DATE(q.updated_at)", [$from, $to], $w);
        $followups = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM sam_followups WHERE status IN ('sent','edited') AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $amount = $this->values("SELECT DATE(q.accepted_at) d, SUM(" . SalesDeskService::AMOUNT_SQL . ") v FROM {$join} WHERE q.status = 'accepted' AND q.accepted_at >= ? AND q.accepted_at <= ? AND {$notTest} GROUP BY DATE(q.accepted_at)", [$from, $to], $w);
        $m['sent'] = $this->madeMetric('Quotes sent', 'Quotes dated when they were sent', 'int', $sent);
        $m['won'] = $this->madeMetric('Quotes won', 'Quotes accepted by the customer', 'int', $won);
        $m['lost'] = $this->madeMetric('Quotes declined or expired', 'Dated by the last change to the quote — there is no declined date', 'int', $lost);
        $m['followups'] = $this->madeMetric('Follow-ups sent', 'Follow-ups you sent or edited before sending', 'int', $followups);
        $m['won_amount'] = $this->madeMetric('Value won', 'Recorded value of accepted quotes', 'usd', $amount);
    }

    /** @param array<string,mixed> $m @param string[] $w */
    private function otto(array &$m, array $w, string $from, string $to): void
    {
        $base = "kind <> 'silent'";
        $made = $this->values("SELECT DATE(created_at) d, COUNT(*) v FROM otto_suggestions WHERE {$base} AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $answered = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM otto_suggestions WHERE {$base} AND status IN ('accepted','edited','dismissed') AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $expired = $this->values("SELECT DATE(created_at) d, COUNT(*) v FROM otto_suggestions WHERE {$base} AND status = 'expired' AND created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $accepted = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM otto_suggestions WHERE {$base} AND status = 'accepted' AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $edited = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM otto_suggestions WHERE {$base} AND status = 'edited' AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $m['made'] = $this->madeMetric('Suggestions made', 'Suggestions Otto recorded making', 'int', $made);
        $m['answered'] = $this->madeMetric('Suggestions you answered', 'Suggestions accepted, edited or dismissed', 'int', $answered);
        $m['expired'] = $this->madeMetric('Expired unanswered', 'Suggestions that expired before an answer', 'int', $expired);
        $den = array_map(static fn($a, $b) => $a + $b, $accepted, $edited);
        $m['kept_pct'] = $this->madeMetric('Kept as Otto suggested', 'Accepted rather than edited, from weeks with at least 5 answers', 'pct', $this->ratio($accepted, $den, 5));
    }

    /** @param array<string,mixed> $m @param string[] $w */
    private function mia(array &$m, array $w, string $from, string $to): void
    {
        $m['made'] = $this->queryMetric('Suggestions made', 'Suggestions Mia recorded making', 'int', "SELECT DATE(created_at) d, COUNT(*) v FROM mia_suggestions WHERE created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $m['sent'] = $this->queryMetric('Sent', 'Suggestions you sent', 'int', "SELECT DATE(decided_at) d, COUNT(*) v FROM mia_suggestions WHERE status = 'sent' AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $m['skipped'] = $this->queryMetric('Skipped', 'Suggestions you chose not to send', 'int', "SELECT DATE(decided_at) d, COUNT(*) v FROM mia_suggestions WHERE status = 'skipped' AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $m['won'] = $this->queryMetric('Turned into work', 'Suggestions recorded as a booking or quote', 'int', "SELECT DATE(outcome_at) d, COUNT(*) v FROM mia_suggestions WHERE outcome IN ('booked','quote') AND outcome_at >= ? AND outcome_at <= ? GROUP BY DATE(outcome_at)", [$from, $to], $w);
    }

    /** @param array<string,mixed> $m @param string[] $w */
    private function yui(array &$m, array $w, string $from, string $to): void
    {
        $drafted = $this->values("SELECT DATE(created_at) d, COUNT(*) v FROM yui_actions WHERE created_at >= ? AND created_at <= ? GROUP BY DATE(created_at)", [$from, $to], $w);
        $sent = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM yui_actions WHERE status IN ('sent','edited') AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $asWritten = $this->values("SELECT DATE(decided_at) d, COUNT(*) v FROM yui_actions WHERE status = 'sent' AND decided_at >= ? AND decided_at <= ? GROUP BY DATE(decided_at)", [$from, $to], $w);
        $m['drafted'] = $this->madeMetric('Replies drafted', 'Replies Yui recorded drafting', 'int', $drafted);
        $m['sent'] = $this->madeMetric('Sent', 'Replies sent, with or without an edit', 'int', $sent);
        $m['sent_as_written'] = $this->madeMetric('Sent as written', 'Sent unchanged, from weeks with at least 5 answers', 'pct', $this->ratio($asWritten, $sent, 5));
    }

    /** @param mixed[] $params @return array<string,float> */
    private function days(string $sql, array $params): array
    {
        $out = [];
        try {
            $s = $this->db->prepare($sql);
            $s->execute($params);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['d'] !== null) {
                    $out[(string)$r['d']] = (float)$r['v'];
                }
            }
        } catch (Throwable $e) { /* migration or column not present yet */ }
        return $out;
    }

    /** @param mixed[] $params @param string[] $weeks @return array{0:array<int,int|float>,1:array<int,int|float>} answered, kept */
    private function receiptAnswers(string $sql, array $params, array $weeks): array
    {
        $answered = $kept = [];
        try {
            $s = $this->db->prepare($sql);
            $s->execute($params);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d = (string)$r['d'];
                $answered[$d] = ($answered[$d] ?? 0) + 1;
                $kept[$d] = ($kept[$d] ?? 0) + ((int)$r['edits'] === 0 ? 1 : 0);
            }
        } catch (Throwable $e) { /* migration or column not present yet */ }
        return [self::bucket($answered, $weeks), self::bucket($kept, $weeks)];
    }

    /** @param mixed[] $params @param string[] $weeks @return array<int,int|float> */
    private function values(string $sql, array $params, array $weeks): array
    {
        return self::bucket($this->days($sql, $params), $weeks);
    }

    /** @param array<string,int|float> $days @param string[] $weeks @return array<string,mixed> */
    private function metric(string $label, string $how, string $format, array $days, array $weeks): array
    {
        return $this->madeMetric($label, $how, $format, self::bucket($days, $weeks));
    }

    /** @param mixed[] $params @param string[] $weeks @return array<string,mixed> */
    private function queryMetric(string $label, string $how, string $format, string $sql, array $params, array $weeks): array
    {
        return $this->madeMetric($label, $how, $format, $this->values($sql, $params, $weeks));
    }

    /** @param array<int,int|float|null> $values @return array<string,mixed> */
    private function madeMetric(string $label, string $how, string $format, array $values): array
    {
        return ['label' => $label, 'how' => $how, 'values' => $values, 'format' => $format];
    }

    /** @param array<int,int|float> $top @param array<int,int|float> $bottom @return array<int,float|null> */
    private function ratio(array $top, array $bottom, int $minimum): array
    {
        return array_map(static fn($a, $b) => $b < $minimum ? null : round($a / $b * 100), $top, $bottom);
    }

    /** @param array<int,int|float> $top @param array<int,int|float> $bottom @return array<int,float|null> */
    private function divide(array $top, array $bottom): array
    {
        return array_map(static fn($a, $b) => $b == 0 ? null : $a / $b, $top, $bottom);
    }

    public function snapshot(?string $date = null): int
    {
        $date = $date ?? date('Y-m-d');
        // No transaction of our own (a caller may hold one): the unique key makes the claim row the atomic
        // "first writer of the day" — a second writer's claim fails and it writes nothing.
        try {
            $s = $this->db->prepare('SELECT 1 FROM head_stat_snapshots WHERE snap_date = ? LIMIT 1');
            $s->execute([$date]);
            if ($s->fetchColumn() !== false) {
                return 0;
            }
            $claim = $this->db->prepare('INSERT INTO head_stat_snapshots (snap_date, head, stat_key, value, created_at) VALUES (?, ?, ?, ?, ?)');
            $claim->execute([$date, '__snapshot__', 'claimed', null, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            return 0;
        }

        $notTest = SalesDeskService::NOT_TEST_SQL;
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $notTest = "COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, '') || ' ' || COALESCE(q.title, '') NOT LIKE '%ZZTEST%'";
        }
        $amount = SalesDeskService::AMOUNT_SQL;
        $stats = [
            ['penny', 'waiting', "SELECT COUNT(*) FROM expenses WHERE status = 'pending_approval'", []],
            ['penny', 'drafts', "SELECT COUNT(*) FROM expenses WHERE status = 'draft'", []],
            ['sam', 'quotes_out', "SELECT COUNT(*) FROM quotes q LEFT JOIN contacts c ON c.id = q.contact_id WHERE q.status IN ('sent','viewed') AND (q.valid_until IS NULL OR q.valid_until >= ?) AND {$notTest}", [$date]],
            ['sam', 'quotes_out_amount', "SELECT COALESCE(SUM({$amount}), 0) FROM quotes q LEFT JOIN contacts c ON c.id = q.contact_id WHERE q.status IN ('sent','viewed') AND (q.valid_until IS NULL OR q.valid_until >= ?) AND {$notTest}", [$date]],
            ['otto', 'open', "SELECT COUNT(*) FROM otto_suggestions WHERE status = 'open'", []],
            ['mia', 'open', "SELECT COUNT(*) FROM mia_suggestions WHERE status = 'open'", []],
            ['yui', 'drafted', "SELECT COUNT(*) FROM yui_actions WHERE status = 'drafted'", []],
        ];
        foreach (array_keys(AgentRegistry::heads()) as $head) {
            $stats[] = [$head, 'open_tasks', "SELECT COUNT(*) FROM agent_tasks WHERE owner_head = ? AND state IN ('open','working','waiting_approval')", [$head]];
        }
        $written = 0;
        foreach ($stats as [$head, $key, $sql, $params]) {
            try {
                $q = $this->db->prepare($sql);
                $q->execute($params);
                $value = $q->fetchColumn();
                $ins = $this->db->prepare('INSERT INTO head_stat_snapshots (snap_date, head, stat_key, value, created_at) VALUES (?, ?, ?, ?, ?)');
                $ins->execute([$date, $head, $key, $value === false ? null : $value, date('Y-m-d H:i:s')]);
                ++$written;
            } catch (Throwable $e) { /* one unavailable stat must not block the rest */ }
        }
        return $written;
    }

    /** @return array{dates:string[],heads:array<string,array<string,array<string,float|null>>>,first:?string} */
    public function history(int $days = 28): array
    {
        $heads = array_fill_keys(array_keys(AgentRegistry::heads()), []);
        $dates = [];
        try {
            $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));
            $s = $this->db->prepare('SELECT snap_date, head, stat_key, value FROM head_stat_snapshots WHERE snap_date >= ? ORDER BY snap_date, id');
            $s->execute([$from]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $date = (string)$r['snap_date'];
                $head = (string)$r['head'];
                if (!isset($heads[$head])) {
                    continue;
                }
                $dates[$date] = true;
                $heads[$head][(string)$r['stat_key']][$date] = $r['value'] === null ? null : (float)$r['value'];
            }
        } catch (Throwable $e) { /* snapshot table not present yet */ }
        $dates = array_keys($dates);
        return ['dates' => $dates, 'heads' => $heads, 'first' => $dates[0] ?? null];
    }

    public static function snapshotLabel(string $key): string
    {
        return [
            'waiting' => 'Receipts waiting for you', 'drafts' => 'Receipts in draft',
            'quotes_out' => 'Quotes still out', 'quotes_out_amount' => 'Value of quotes still out',
            'open' => 'Open suggestions', 'drafted' => 'Replies waiting', 'open_tasks' => 'Open tasks',
        ][$key] ?? str_replace('_', ' ', ucfirst($key));
    }

    /** @param array<string,array<string,mixed>> $metrics @return array{value:?string,sentence:string} */
    public static function headline(string $head, array $metrics): array
    {
        $names = AgentRegistry::heads();
        $name = $names[$head]['name'] ?? ucfirst($head);
        $keys = ['penny' => 'right_first_time', 'sam' => 'won', 'otto' => 'answered', 'mia' => 'sent', 'yui' => 'sent', 'charlie' => 'ai_calls', 'ada' => 'ai_calls'];
        $key = $keys[$head] ?? 'ai_calls';
        $values = $metrics[$key]['values'] ?? [];
        $nonzero = array_sum(array_map(static fn($v) => (float)($v ?? 0), $values));
        if ($nonzero == 0) {
            if ($head === 'otto') {
                $made = array_sum($metrics['made']['values'] ?? []);
                $answered = array_sum($metrics['answered']['values'] ?? []);
                if ($made > 0 && $answered == 0) {
                    return ['value' => null, 'sentence' => 'Otto made ' . number_format($made) . ' suggestions in ' . count($values) . ' weeks and none were answered — they expire before anyone looks.'];
                }
            }
            if ($head === 'ada') {
                return ['value' => null, 'sentence' => 'Ada checks and reports; she has no answers to count yet.'];
            }
            // A head can be busy with the AI (Mia writes, Penny re-reads) before anything it made has an answer.
            $aiValues = $metrics['ai_calls']['values'] ?? [];
            $aiLast = count($aiValues) >= 2 ? (int)($aiValues[count($aiValues) - 2] ?? 0) : 0;
            if (array_sum(array_map(static fn($v) => (float)($v ?? 0), $aiValues)) > 0) {
                return ['value' => null, 'sentence' => $name . ' has no answers from you to count yet; ' . number_format($aiLast) . ' AI ' . ($aiLast === 1 ? 'request' : 'requests') . ' last week.'];
            }
            return ['value' => null, 'sentence' => $name . ': nothing recorded yet.'];
        }
        $lastIndex = count($values) - 2;
        $compareIndex = $lastIndex - 4;
        $last = $lastIndex >= 0 ? ($values[$lastIndex] ?? null) : null;
        $format = (string)($metrics[$key]['format'] ?? 'int');
        if ($last === null) {
            return ['value' => null, 'sentence' => $name . ': nothing recorded last week.'];
        }
        $shown = self::formatValue((float)$last, $format);
        $phrases = [
            'penny' => $name . ' kept ' . $shown . ' of receipts as she read them',
            'sam' => $name . ' won ' . number_format((float)$last) . ' ' . ((float)$last === 1.0 ? 'quote' : 'quotes'),
            'otto' => $name . ' had ' . number_format((float)$last) . ' ' . ((float)$last === 1.0 ? 'suggestion answered' : 'suggestions answered'),
            'mia' => $name . ' had ' . number_format((float)$last) . ' ' . ((float)$last === 1.0 ? 'suggestion sent' : 'suggestions sent'),
            'yui' => $name . ' had ' . number_format((float)$last) . ' ' . ((float)$last === 1.0 ? 'reply sent' : 'replies sent'),
            'charlie' => $name . ' made ' . number_format((float)$last) . ' AI ' . ((float)$last === 1.0 ? 'request' : 'requests'),
            'ada' => $name . ' made ' . number_format((float)$last) . ' AI ' . ((float)$last === 1.0 ? 'request' : 'requests'),
        ];
        $lead = ($phrases[$head] ?? ($name . ' recorded ' . $shown)) . ' last week';
        $compare = $compareIndex >= 0 ? ($values[$compareIndex] ?? null) : null;
        if ($compare === null) {
            return ['value' => $shown, 'sentence' => $lead . '. Not enough history yet to compare.'];
        }
        $old = self::formatValue((float)$compare, $format);
        $change = $last > $compare ? 'up from ' : ($last < $compare ? 'down from ' : 'the same as ');
        return ['value' => $shown, 'sentence' => $lead . ', ' . $change . $old . ' four weeks before.'];
    }

    private static function formatValue(float $value, string $format): string
    {
        if ($format === 'pct') {
            return number_format($value, 0) . '%';
        }
        if ($format === 'usd') {
            return '$' . number_format($value, $value < 1 ? 3 : 2);
        }
        return number_format($value, 0);
    }

    /**
     * Index of the first week in which any of a head's numbers is not zero, or null when none is. Weeks before it are
     * before that head's records begin (the AI log started 28 Oct), so the page leaves them out instead of showing 0.
     * @param array<string,array<string,mixed>> $metrics
     */
    public static function firstActiveWeek(array $metrics): ?int
    {
        $first = null;
        foreach ($metrics as $m) {
            foreach (($m['values'] ?? []) as $i => $v) {
                if ($v !== null && (float)$v != 0 && ($first === null || $i < $first)) {
                    $first = (int)$i;
                }
            }
        }
        return $first;
    }

    /** @param array<int,int|float|null> $values */
    public static function sparkline(array $values, int $w = 120, int $h = 28): string
    {
        $points = [];
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $points[$i] = (float)$v;
            }
        }
        if (count($points) < 2 || max(array_map('abs', $points)) == 0) {
            return '';
        }
        $min = min($points);
        $max = max($points);
        $lastIndex = max(1, count($values) - 1);
        $out = [];
        foreach ($points as $i => $v) {
            $x = $i / $lastIndex * $w;
            $y = $max === $min ? $h / 2 : $h - (($v - $min) / ($max - $min) * $h);
            $out[] = rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.') . ',' . rtrim(rtrim(number_format($y, 2, '.', ''), '0'), '.');
        }
        return implode(' ', $out);
    }
}
