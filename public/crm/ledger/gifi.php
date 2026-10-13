<?php
/**
 * Ledger → GIFI mapping: every account, its GIFI code (suggested or confirmed), lead group and the
 * year's figures; the validator; ?export=csv (only when the validator passes).
 * See: ledger.view. Change a mapping: ledger.post (admin) or ledger.propose (the accountant) — every
 * change is logged ('ledger.gifi'). Suggestions are stored as 'suggested' and block the export until
 * a person confirms them.
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';

$db   = getDB();
$svc  = new GifiService($db);
$year = (int)($_GET['year'] ?? $_POST['year'] ?? date('Y'));
if ($year < 2024 || $year > (int)date('Y') + 1) $year = (int)date('Y');
$canEdit = $ledgerCanPost || $ledgerCanPropose;
$flash = null; $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $flashErr = 'The form expired — reload the page and try again.';
    } elseif (!$canEdit) {
        http_response_code(403);
        $flashErr = 'Only the owner or the accountant can change mappings.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save') {
                $r = $svc->save((int)$_POST['account_id'], (string)($_POST['gifi_code'] ?? ''), $_POST['lead_group'] ?? null,
                                $_POST['gifi_note'] ?? null, (int)$user['id']);
                $flash = 'Saved ' . $r['account'] . ($r['gifi_code'] ? ' → ' . $r['gifi_code'] . ' (confirmed)' : ' — mapping cleared');
            } elseif ($action === 'confirm_all' && $ledgerCanPost) {
                $n = 0;
                foreach ($svc->accounts($year) as $a) {
                    if (($a['gifi_status'] ?? '') === 'suggested' && $a['gifi_code']) {
                        $svc->save((int)$a['id'], (string)$a['gifi_code'], $a['lead_group'], $a['gifi_note'], (int)$user['id']); $n++;
                    }
                }
                $flash = "Confirmed $n suggestion" . ($n === 1 ? '' : 's') . '.';
            } elseif ($action === 'prefill' && $ledgerCanPost) {
                $n = $svc->prefillSuggestions();
                if (function_exists('logActivity')) logActivity((int)$user['id'], null, 'ledger.gifi', json_encode(['prefill' => $n]));
                $flash = "Suggested a code for $n account" . ($n === 1 ? '' : 's') . '. Nothing is confirmed until someone confirms it.';
            }
        } catch (InvalidArgumentException $e) {
            $flashErr = $e->getMessage();
        }
    }
}

$rep = $svc->report($year);

if (($_GET['export'] ?? '') === 'csv') {
    if (!$rep['ok']) {
        http_response_code(409);
        $flashErr = 'Export blocked: the GIFI does not validate yet — see the problems below.';
    } else {
        $csv = LedgerExport::csv(array_merge(
            LedgerExport::headerRows(LedgerExport::entity($db), 'GIFI (whole dollars, CRA RC4088)', "FY$year ($year-01-01 to $year-12-31)",
                                     LedgerExport::lockStatus($db, "$year-01-01", "$year-12-31"), date('Y-m-d H:i:s T')),
            GifiService::csvRows($rep)));
        LedgerExport::log((int)$user['id'], 'gifi.csv', ['year' => $year]);
        LedgerExport::send("gifi-fy$year.csv", $csv);
    }
}

$known = $rep['known'];
$byStatus = ['confirmed' => 0, 'suggested' => 0, 'unmapped' => 0];
foreach ($rep['accounts'] as $a) {
    $active = $a['lines'] > 0 || abs($a['end']) >= 0.005 || abs($a['period']) >= 0.005;
    if (!$active) continue;
    $st = $a['gifi_code'] ? ($a['gifi_status'] === 'confirmed' ? 'confirmed' : 'suggested') : 'unmapped';
    $byStatus[$st]++;
}
$csrf = generateCSRFToken();
$pageTitle = 'GIFI mapping — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('gifi', userHasPermission('users.manage')); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">GIFI mapping <span class="mw-muted">FY<?= $year ?></span></h1>
      <p class="mw-ledger-sub">Every account reports under one CRA GIFI code (RC4088). Suggested codes are guesses until someone confirms them.</p>
    </div>
    <form method="get" class="mw-ledger-inline">
      <label class="mw-ledger-field"><span>Year</span>
        <select name="year" data-mw-autosubmit>
          <?php for ($y = (int)date('Y'); $y >= 2025; $y--): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>>FY<?= $y ?></option><?php endfor; ?>
        </select></label>
    </form>
  </header>

  <?php if ($flash): ?><div class="mw-ledger-banner mw-ledger-banner--ok" role="status"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($flashErr): ?><div class="mw-ledger-banner mw-ledger-banner--bad" role="alert"><?= h($flashErr) ?></div><?php endif; ?>

  <section class="mw-ledger-validator <?= $rep['ok'] ? 'is-ok' : 'is-blocked' ?>" aria-label="Validator">
    <div class="mw-ledger-validator-head">
      <h2><?= $rep['ok'] ? 'Ready to export' : 'Export blocked' ?></h2>
      <p><?= $byStatus['confirmed'] ?> confirmed · <?= $byStatus['suggested'] ?> suggested · <?= $byStatus['unmapped'] ?> unmapped (accounts with activity)</p>
      <?php if ($rep['ok']): ?>
        <a class="btn btn-sm btn-primary" href="?year=<?= $year ?>&amp;export=csv">Export GIFI CSV</a>
      <?php else: ?>
        <button class="btn btn-sm btn-outline-secondary" type="button" disabled>Export GIFI CSV</button>
      <?php endif; ?>
    </div>
    <dl class="mw-ledger-totals">
      <?php foreach (['2599' => 'Total assets', '3499' => 'Total liabilities', '3620' => 'Shareholder equity', '3849' => 'Retained earnings – end',
                      '8299' => 'Total revenue', '9368' => 'Total expenses', '9999' => 'Net income'] as $c => $lab): ?>
        <div><dt><span class="mw-code"><?= $c ?></span> <?= h($lab) ?></dt><dd class="mw-num"><?= mwLedgerDollars($rep['totals'][$c] ?? 0) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php if ($rep['problems']): ?>
      <details class="mw-ledger-problems" open><summary><?= count($rep['problems']) ?> problem<?= count($rep['problems']) === 1 ? '' : 's' ?> blocking the export</summary>
        <ul><?php foreach (array_slice($rep['problems'], 0, 80) as $p): ?><li><?= h($p['message']) ?></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <?php if ($rep['warnings']): ?>
      <details class="mw-ledger-problems mw-ledger-problems--warn"><summary><?= count($rep['warnings']) ?> thing<?= count($rep['warnings']) === 1 ? '' : 's' ?> to check</summary>
        <ul><?php foreach ($rep['warnings'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <?php if ($ledgerCanPost): ?>
      <form method="post" class="mw-ledger-inline">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="year" value="<?= $year ?>">
        <button class="btn btn-sm btn-outline-secondary" name="action" value="prefill">Suggest codes for unmapped accounts</button>
        <?php if ($byStatus['suggested'] > 0): ?>
          <button class="btn btn-sm btn-outline-secondary" name="action" value="confirm_all"
                  data-mw-confirm="Confirm every suggested code as it stands? Do this only once you or the accountant have read them.">Confirm all suggestions</button>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </section>

  <datalist id="mw-gifi-codes"><?php foreach ($known as $c => $n): if (in_array((string)$c, GifiRules::TOTALS, true)) continue; ?><option value="<?= h((string)$c) ?>"><?= h($n) ?></option><?php endforeach; ?></datalist>

  <div class="mw-ledger-tablewrap">
    <table class="mw-ledger-table mw-ledger-table--gifi" data-mw-keytable>
      <thead><tr>
        <th scope="col">Account</th><th scope="col" class="mw-num">Balance at year end</th><th scope="col" class="mw-num">Year activity</th>
        <th scope="col">GIFI</th><th scope="col">Status</th><th scope="col" class="mw-ledger-hide-sm">Lead</th><th scope="col" class="mw-ledger-memo">Note</th>
        <?php if ($canEdit): ?><th scope="col"><span class="sr-only">Save</span></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rep['accounts'] as $a):
          $active = $a['lines'] > 0 || abs($a['end']) >= 0.005 || abs($a['period']) >= 0.005;
          $st = $a['gifi_code'] ? ($a['gifi_status'] === 'confirmed' ? 'confirmed' : 'suggested') : ($active ? 'unmapped' : 'idle');
          $isBs = in_array($a['type'], ['asset', 'liability', 'equity'], true);
          $sug = $a['suggestion'];
          $formId = 'gf' . (int)$a['id'];
      ?>
        <tr tabindex="0" class="mw-ledger-row<?= $active ? '' : ' is-idle' ?>">
          <td><span class="mw-code"><?= h($a['code']) ?></span> <?= h($a['name']) ?> <span class="mw-muted mw-ledger-hide-sm"><?= h($a['type']) ?></span></td>
          <td class="mw-num"><?= $isBs ? mwLedgerMoney($a['end'], true) : '' ?></td>
          <td class="mw-num"><?= mwLedgerMoney($a['period'], true) ?></td>
          <td>
            <?php if ($canEdit): ?>
              <input form="<?= $formId ?>" class="mw-ledger-code-input" name="gifi_code" list="mw-gifi-codes" inputmode="numeric" maxlength="4"
                     value="<?= h((string)$a['gifi_code']) ?>" placeholder="<?= h((string)($sug['code'] ?? '')) ?>" aria-label="GIFI code for <?= h($a['code']) ?>">
            <?php else: ?><span class="mw-code"><?= h((string)$a['gifi_code']) ?></span><?php endif; ?>
            <?php if ($a['gifi_code'] && isset($known[$a['gifi_code']])): ?><div class="mw-muted mw-ledger-small"><?= h($known[$a['gifi_code']]) ?></div><?php endif; ?>
          </td>
          <td><span class="mw-ledger-status mw-ledger-status--<?= $st ?>"><?= $st ?></span>
            <?php if ($st === 'confirmed' && $a['confirmed_by_name']): ?><div class="mw-muted mw-ledger-small"><?= h($a['confirmed_by_name']) ?></div><?php endif; ?></td>
          <td class="mw-ledger-hide-sm"><?php if ($canEdit): ?><input form="<?= $formId ?>" class="mw-ledger-lead-input" name="lead_group" maxlength="20" value="<?= h((string)$a['lead_group']) ?>" placeholder="<?= h((string)($sug['lead'] ?? '')) ?>" aria-label="Lead group for <?= h($a['code']) ?>"><?php else: ?><?= h((string)$a['lead_group']) ?><?php endif; ?></td>
          <td class="mw-ledger-memo mw-ledger-small"><?= h((string)($a['gifi_note'] ?: ($sug['code'] === null ? $sug['note'] : ''))) ?></td>
          <?php if ($canEdit): ?>
          <td><form id="<?= $formId ?>" method="post" class="mw-ledger-rowform">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save">
              <input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
              <input type="hidden" name="gifi_note" value="<?= h((string)$a['gifi_note']) ?>">
              <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $st === 'suggested' ? 'Confirm' : 'Save' ?></button>
            </form></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="mw-ledger-source-note">Codes: CRA RC4088(E) Rev. 23, General Index of Financial Information. Amounts round to whole dollars per line; totals are the sums of the rounded lines.
    Required items checked: 2599, 3499, 3620, 3849, 8299, 9368, 9999; assets must equal liabilities plus equity, and 9999 must equal the ledger's net income.</p>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013a" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
