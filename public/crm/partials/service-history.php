<?php
/**
 * Service history — presentation only. Data comes from ServiceHistoryService::forVisits().
 *
 *   mwServiceHistoryLine($stop, $historyByVisit)  → the one-line "Last done …" for a compact card
 *   mwServiceHistoryGrid($history, $label)        → two rows of seven (last week / this week)
 *
 * Same rules as the iOS app (Stop.historyLine / ServiceHistoryGrid): with several services on a
 * stop a skip wins, otherwise the first visit still to be done; the service is only named when
 * the stop has more than one.
 */

if (!function_exists('mwServiceHistoryLine')) {

    function mwServiceHistoryLine(array $stop, array $historyByVisit): string
    {
        $visits = $stop['visits'] ?? [];
        $open   = array_values(array_filter($visits, static function (array $v): bool {
            return !in_array($v['visit_status'] ?? 'scheduled', ['completed', 'skipped', 'cancelled'], true);
        }));
        $pool = $open ?: $visits;

        $pick = null;
        foreach ($pool as $v) {
            $s = $historyByVisit[(int)($v['visit_id'] ?? 0)]['summary'] ?? null;
            if ($s !== null && strpos($s, 'Skipped') === 0) { $pick = $v; break; }
        }
        if ($pick === null) {
            foreach ($pool as $v) {
                if (!empty($historyByVisit[(int)($v['visit_id'] ?? 0)]['summary'])) { $pick = $v; break; }
            }
        }
        if ($pick === null) {
            return '';
        }

        $summary = (string)$historyByVisit[(int)$pick['visit_id']]['summary'];
        $warning = strpos($summary, 'Skipped') === 0;
        $service = count($visits) > 1 ? trim((string)($pick['plan_title'] ?? '')) : '';
        $text    = ($service !== '' ? $service . ': ' : '') . $summary;

        return '<div class="mw-svc-hist-line' . ($warning ? ' mw-svc-hist-line--warn' : '') . '">'
             . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    function mwServiceHistoryGrid(?array $history, string $label = ''): string
    {
        if (!$history || count($history['days'] ?? []) !== 14) {
            return '';
        }
        $summary = (string)($history['summary'] ?? 'Service history');
        $warning = strpos($summary, 'Skipped') === 0;
        $today   = (string)($history['today'] ?? '');
        $h       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $out  = '<div class="mw-svc-hist" role="img" aria-label="' . $h(($label !== '' ? $label . ': ' : '') . $summary) . '">';
        $out .= '<div class="mw-svc-hist-head' . ($warning ? ' mw-svc-hist-head--warn' : '') . '">'
              . $h(($label !== '' ? $label . ' — ' : '') . $summary) . '</div>';
        $out .= '<div class="mw-svc-hist-grid"><span></span>';
        foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $letter) {
            $out .= '<span class="mw-svc-hist-dow">' . $letter . '</span>';
        }
        foreach (array_chunk($history['days'], 7) as $i => $week) {
            $out .= '<span class="mw-svc-hist-week">' . ($i === 0 ? 'Last' : 'This') . '</span>';
            foreach ($week as $day) {
                $state = preg_replace('/[^a-z_]/', '', (string)($day['state'] ?? 'none'));
                $cls   = 'mw-svc-hist-cell mw-svc-hist-cell--' . $state . (($day['date'] ?? '') === $today ? ' mw-svc-hist-cell--today' : '');
                $count = (int)($day['count'] ?? 0);
                $out  .= '<span class="' . $cls . '">' . (int)substr((string)$day['date'], 8, 2)
                       . ($count > 1 ? '<i class="mw-svc-hist-count">' . $count . '</i>' : '') . '</span>';
            }
        }
        $out .= '</div>';
        $out .= '<div class="mw-svc-hist-legend">'
              . '<span><i class="mw-svc-hist-key mw-svc-hist-cell--completed"></i>Done</span>'
              . '<span><i class="mw-svc-hist-key mw-svc-hist-cell--skipped"></i>Skipped</span>'
              . '<span><i class="mw-svc-hist-key mw-svc-hist-cell--scheduled"></i>Scheduled</span>'
              . '</div></div>';
        return $out;
    }
}
