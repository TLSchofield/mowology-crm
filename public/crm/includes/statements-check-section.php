<?php
/**
 * Bank import page — "Statements check" card: which statements are expected, whether last
 * month is in, and every gap per account / month (StatementCoverageService, migration 1231).
 * Expects $__scReport = StatementCoverageService::latest(). Renders nothing without it.
 * The expected / closing-day edits post to /crm/api/accounting-bank-import.php
 * (action=statement_account_save) from bank-import.php's script.
 */
if (empty($__scReport) || empty($__scReport['accounts'])) return;
$__scMonths = [];
$__scEnd = substr((string)$__scReport['date'], 0, 7);
for ($__i = 11; $__i >= 0; $__i--) $__scMonths[] = date('Y-m', strtotime($__scEnd . '-01 -' . $__i . ' month'));
$__scLabel = [
    'ok' => ['is-ok', 'In ✓'], 'never' => ['is-bad', 'Never imported'], 'missing' => ['is-bad', 'Not imported'],
    'partial' => ['is-warn', 'Partly in'], 'gap' => ['is-warn', 'Gap inside'],
];
?>
<div class="card mb-3" id="statements-check">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h5 class="card-title mb-0">Statements check</h5>
      <span class="text-muted small">Is every statement in? Each line's running balance must follow the one before. Checked once a day — last <?= h(date('M j, g:i a', strtotime((string)$__scReport['computed_at']))) ?>.</span>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mw-acct-table mw-sc-table mb-0">
        <thead>
          <tr>
            <th>Account</th>
            <th>Expected</th>
            <th>Closes on</th>
            <th><?= h(date('F', strtotime($__scReport['last_month'] . '-01'))) ?></th>
            <th>Lines by month</th>
            <th>Checked by</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($__scReport['accounts'] as $__a):
            $__st = $__a['last_month']['status'];
            [$__cls, $__txt] = $__a['expected'] ? ($__scLabel[$__st] ?? ['is-off', $__st]) : ['is-off', 'Not expected'];
            if ($__st === 'partial' && $__a['expected']) $__txt = 'In to ' . date('j M', strtotime((string)$__a['last_month']['through']));
            $__byMonth = array_column($__a['months'], null, 'month');
            $__gapMonths = [];
            foreach ($__a['breaks'] as $__b) {
                for ($__m = substr($__b['from'], 0, 7); $__m <= substr($__b['to'], 0, 7); $__m = date('Y-m', strtotime($__m . '-01 +1 month'))) $__gapMonths[$__m] = true;
            }
        ?>
          <tr data-sc-id="<?= (int)$__a['list_id'] ?>">
            <td>
              <?php if ($__a['list_id']): ?>
                <input type="text" class="form-control form-control-sm mw-sc-label" value="<?= h($__a['label']) ?>" maxlength="80" aria-label="Account name">
              <?php else: ?>
                <?= h($__a['label']) ?> <span class="text-muted small">(import with no account)</span>
              <?php endif; ?>
              <div class="text-muted small"><?= $__a['kind'] === 'card' ? 'Credit card' : 'Bank' ?> · <?= (int)$__a['lines'] ?> lines<?= $__a['first'] ? ' · ' . h(date('M Y', strtotime($__a['first']))) . ' – ' . h(date('M Y', strtotime($__a['last']))) : '' ?></div>
            </td>
            <td>
              <?php if ($__a['list_id']): ?>
                <input type="checkbox" class="form-check-input mw-sc-expected" <?= $__a['expected'] ? 'checked' : '' ?> aria-label="Statement expected every month">
              <?php endif; ?>
            </td>
            <td>
              <?php if ($__a['list_id']): ?>
                <input type="number" min="1" max="31" class="form-control form-control-sm mw-sc-day" value="<?= $__a['statement_day'] !== null ? (int)$__a['statement_day'] : '' ?>" placeholder="end" aria-label="Statement closing day">
              <?php endif; ?>
            </td>
            <td><span class="mw-sc-st <?= $__cls ?>"><?= h($__txt) ?></span></td>
            <td>
              <div class="mw-sc-months">
                <?php foreach ($__scMonths as $__m): $__c = (int)($__byMonth[$__m]['lines'] ?? 0); ?>
                  <span class="mw-sc-m<?= $__c === 0 && $__a['lines'] > 0 && $__a['first'] && $__m >= substr($__a['first'], 0, 7) ? ' is-empty' : (isset($__gapMonths[$__m]) ? ' is-gap' : '') ?>" title="<?= h(date('F Y', strtotime($__m . '-01'))) ?>: <?= $__c ?> lines"><?= h(date('M', strtotime($__m . '-01'))) ?><b><?= $__c ?: '–' ?></b></span>
                <?php endforeach; ?>
              </div>
            </td>
            <td class="small text-muted">
              <?php if ($__a['method'] === 'balance'): ?>Running balance<br><?= (int)$__a['linked'] ?> of <?= (int)$__a['with_balance'] ?> lines link
              <?php elseif ($__a['method'] === 'counts'): ?>Line counts only<br>(no balance column)
              <?php else: ?>—<?php endif; ?>
              <?php if ($__a['list_id']): ?><br><button type="button" class="btn btn-xs btn-outline-secondary mt-1 mw-sc-save">Save</button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="p-3">
      <?php $__any = false; foreach ($__scReport['accounts'] as $__a):
          if (!$__a['breaks'] && !$__a['possible_gaps'] && !$__a['other_account'] && !$__a['future_lines']) continue;
          $__any = true; ?>
        <div class="mw-sc-acct">
          <h6><?= h($__a['label']) ?></h6>
          <ul class="mw-sc-gaps">
            <?php foreach (array_reverse($__a['breaks']) as $__b): ?>
              <li><?= h(StatementCoverageService::breakText($__a['label'], $__b, $__scReport['date'])) ?>
                <span class="text-muted">(balance <?= h(number_format($__b['before'], 2)) ?> → <?= h(number_format($__b['after'], 2)) ?>)</span></li>
            <?php endforeach; ?>
            <?php foreach ($__a['possible_gaps'] as $__g): ?>
              <li class="is-weak"><?= h(StatementCoverageService::possibleGapText($__a['label'], $__g)) ?></li>
            <?php endforeach; ?>
            <?php foreach ($__a['other_account'] as $__o): ?>
              <li class="is-info"><?= (int)$__o['lines'] ?> line<?= $__o['lines'] === 1 ? '' : 's' ?> <?= h(date('j M', strtotime($__o['from']))) ?> – <?= h(date('j M Y', strtotime($__o['to']))) ?> follow a balance of their own — another account printed on the same statement, not a gap.</li>
            <?php endforeach; ?>
            <?php if ($__a['future_lines']): ?>
              <li><?= (int)$__a['future_lines'] ?> line<?= $__a['future_lines'] === 1 ? ' is' : 's are' ?> dated after today — a statement got the wrong year.</li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endforeach; ?>
      <?php if (!$__any): ?><p class="text-muted small mb-0">No gaps: every balance follows the one before.</p><?php endif; ?>
    </div>
  </div>
</div>
