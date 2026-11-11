<?php
/**
 * "Crew time on site" panel for an invoice linked to a visit.
 * One copy, used by invoices/create.php, edit.php and view.php.
 *
 * Set before including:
 *   $crewTimeVisitId  int   the visit the invoice is for (0 or unset → nothing is drawn)
 *   $crewTimeClass    string optional extra classes on the panel (default 'mb-3')
 * Needs $db (PDO) and APP_ROOT. The numbers come from InvoiceCrewTime::forVisit().
 * A failed lookup draws nothing rather than breaking the invoice page.
 */
require_once APP_ROOT . '/Modules/Invoices/Services/InvoiceCrewTime.php';

$crewTime = null;
if (!empty($crewTimeVisitId)) {
    try {
        $crewTime = (new InvoiceCrewTime($db))->forVisit((int)$crewTimeVisitId);
    } catch (Throwable $e) {
        error_log('[invoice-crew-time] visit ' . (int)$crewTimeVisitId . ': ' . $e->getMessage());
        $crewTime = null;
    }
}
$crewTimeClass = $crewTimeClass ?? 'mb-3';
?>
<?php if ($crewTime !== null): ?>
<div class="mw-crew-time <?php echo htmlspecialchars($crewTimeClass); ?>">
    <div class="mw-crew-time-label">Crew time on site</div>
    <?php if ($crewTime['has_time']): ?>
        <?php
        $crewTimeParts = [];
        if ($crewTime['arrived'] && $crewTime['left']) {
            $crewTimeParts[] = date('g:i a', strtotime($crewTime['arrived'])) . ' – ' . date('g:i a', strtotime($crewTime['left']));
        } elseif ($crewTime['running'] && $crewTime['arrived']) {
            $crewTimeParts[] = 'since ' . date('g:i a', strtotime($crewTime['arrived']));
        }
        if ($crewTime['on_site_minutes'] !== null) {
            $crewTimeParts[] = InvoiceCrewTime::formatMinutes((int)$crewTime['on_site_minutes']) . ' on site';
        }
        if ($crewTime['crew_count'] > 0) {
            $crewTimeParts[] = (int)$crewTime['crew_count'] . ' crew';
        }
        $crewTimeParts[] = InvoiceCrewTime::formatMinutes((int)$crewTime['crew_minutes']) . ' crew-time';
        $crewPeople = [];
        foreach ($crewTime['people'] as $person) {
            $crewPeople[] = $person['name'] . ' ' . InvoiceCrewTime::formatMinutes((int)$person['minutes']);
        }
        ?>
        <div class="mw-crew-time-main"><?php echo htmlspecialchars(implode(' · ', $crewTimeParts)); ?></div>
        <?php if (count($crewTime['people']) > 1): ?>
        <div class="mw-crew-time-people"><?php echo htmlspecialchars(implode(' · ', $crewPeople)); ?></div>
        <?php endif; ?>
        <?php if ($crewTime['running']): ?><span class="mw-crew-time-flag">Timer still running</span><?php endif; ?>
        <?php if ($crewTime['estimated']): ?><span class="mw-crew-time-flag">Includes estimated time</span><?php endif; ?>
        <?php if ($crewTime['source'] === 'visit'): ?><span class="mw-crew-time-flag">From the visit record, no timers</span><?php endif; ?>
    <?php else: ?>
        <div class="mw-crew-time-main mw-crew-time-none">No crew time recorded for this visit.</div>
    <?php endif; ?>
</div>
<?php endif; ?>
