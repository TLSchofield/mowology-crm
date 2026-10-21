<?php
/**
 * Supplier price lists — Otto's view of what suppliers sell this week, at OUR trade price.
 *
 * Shows the newest availability list of a supplier (search by name/code, filter by category and
 * size, only changes), the supplier's specials still in force (price + until when), last list's
 * price (up/down/new), the order rules printed on the list, and for one catalogue product + size
 * every supplier's price side by side. The review queue confirms/merges/separates look-alike
 * names (what is decided is learned for the next list).
 *
 * products.view to see; products.edit (+ CSRF, POST) to decide on the review queue.
 * Thin controller: SupplierPriceListService + CatalogService (migration 1331) hold the logic.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('products.view');
$user = getCurrentUser();

$pageTitle = 'Supplier Price Lists';
$activePage = 'products';

require_once APP_ROOT . '/Modules/Operations/Services/SupplierPriceListService.php';
$db = getDB();
$svc = new SupplierPriceListService($db);
$cat = $svc->catalog();
$ready = $svc->ready();
$canEdit = userHasPermission('products.edit');
$flash = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('You need products.edit to change catalogue matches.'); }
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('The security token expired. Go back and try again.'); }
    $do = (string)($_POST['do'] ?? '');
    if (in_array($do, ['confirm_format', 'fix_columns', 'dismiss_skip', 'fetch_taxa'], true)) {
        // The reading of a supplier's first list (migration 1390): confirm it, correct it, or dismiss a list we could not read.
        try {
            if ($do === 'confirm_format') { $svc->confirmFormat((int)($_POST['format_id'] ?? 0)); $flash = 'Confirmed. Those prices now count in quotes, and later lists from this supplier import quietly.'; }
            elseif ($do === 'dismiss_skip') { $svc->dismissSkip((int)($_POST['skip_id'] ?? 0)); $flash = 'Dismissed.'; }
            elseif ($do === 'fetch_taxa') {
                // Common names and reference photos from iNaturalist, about 40 plant species a click (1 call a second).
                require_once APP_ROOT . '/Modules/Operations/Services/CatalogTaxonService.php';
                @set_time_limit(120);
                $tx = (new CatalogTaxonService($db))->enrichBatch(40);
                $flash = "Looked up {$tx['keys']} plant species: {$tx['found']} found, {$tx['none']} not found." . ($tx['stopped'] ? ' Stopped early: ' . $tx['stopped'] . '. Try again in a minute.' : '');
            }
            else {
                $svc->updateFormatColumns((int)($_POST['format_id'] ?? 0), (array)($_POST['cols'] ?? []));
                $flash = 'Columns saved. The next list uses them; the list already read keeps its old reading until the supplier sends the next one.';
            }
        } catch (Throwable $e) {
            error_log('price-lists format: ' . $e->getMessage());
            $flash = 'Could not save that: ' . $e->getMessage();
        }
    } else
    try {
        $target = (int)($_POST['target_id'] ?? 0);
        $cat->decide((int)($_POST['queue_id'] ?? 0), (string)($_POST['decision'] ?? ''), (int)($user['id'] ?? 0) ?: null, $target ?: null);
        $flash = 'Saved. The next list from any supplier will use it.';
    } catch (Throwable $e) {
        error_log('price-lists decide: ' . $e->getMessage());
        $flash = 'Could not save that: ' . $e->getMessage();
    }
}

$lists = $ready ? $svc->latestLists() : [];
// Only the families this company works with (tenant_catalog_families).
$families = $ready ? $cat->families() : [];
$usedFamilies = array_column(array_filter($families, fn($f) => $f['used']), 'slug');
$formatFamily = [];
if ($ready) {
    foreach ($db->query("SELECT id, family FROM supplier_list_formats")->fetchAll(PDO::FETCH_KEY_PAIR) as $fid => $fam) $formatFamily[(int)$fid] = $fam;
}
$lists = array_values(array_filter($lists, fn($l) => !empty($l['needs_review']) || !$l['format_id'] || !$usedFamilies || in_array($formatFamily[(int)$l['format_id']] ?? 'other', $usedFamilies, true)));
$reviews = $ready ? $svc->reviewQueue() : [];   // first lists read automatically, waiting for "looks right"
$skips = $ready ? $svc->openSkips() : [];        // lists that arrived but could not be read
require_once APP_ROOT . '/Modules/Operations/Services/CatalogTaxonService.php';
$taxaPending = $ready ? (new CatalogTaxonService($db))->pending() : 0;   // plants still waiting for a common name / photo

$suppliers = [];
foreach ($lists as $l) $suppliers[(int)$l['supplier_account_id']] = $l['supplier_name'] ?: ('Supplier #' . $l['supplier_account_id']);
$supplierId = (int)($_GET['supplier'] ?? 0);
if (!isset($suppliers[$supplierId])) $supplierId = (int)(array_key_first($suppliers) ?? 0);
$avail = $specials = null;
foreach ($lists as $l) {
    if ((int)$l['supplier_account_id'] !== $supplierId) continue;
    if ($l['kind'] === 'availability') $avail = $l;
    if ($l['kind'] === 'specials') $specials = $l;
}
$filters = ['q' => trim((string)($_GET['q'] ?? '')), 'category' => (string)($_GET['category'] ?? ''), 'size' => (string)($_GET['size'] ?? ''),
            'changes' => !empty($_GET['changes'])];
$items = $avail ? $svc->items((int)$avail['id'], $filters) : [];
$facets = $avail ? $svc->facets((int)$avail['id']) : ['categories' => [], 'sizes' => []];
$diff = $avail ? $svc->diff((int)$avail['id']) : null;
$curSpecials = $supplierId ? $svc->currentSpecials($supplierId) : ['list_id' => null, 'valid_until' => null, 'items' => []];
$family = $avail && $avail['format_id'] ? ($formatFamily[(int)$avail['format_id']] ?? 'other') : 'plants';
$sizeNotes = $ready ? $cat->sizeNotes($family) : [];
$queue = $ready ? $cat->queue() : [];

$compareId = (int)($_GET['compare'] ?? 0);
$compareSize = (string)($_GET['csize'] ?? '');
$compare = $compareId && $ready ? $svc->compare($compareId, $compareSize !== '' ? $compareSize : null) : [];
$compareProduct = $compareId && $ready ? $cat->product($compareId) : null;
$compareAttrs = $compareProduct ? $cat->attributes($compareId) : [];

$money = fn($v) => $v === null || $v === '' ? '' : '$' . number_format((float)$v, 2);
$qs = function (array $over) use ($supplierId, $filters): string {
    $p = array_filter(['supplier' => $supplierId, 'q' => $filters['q'], 'category' => $filters['category'], 'size' => $filters['size'],
                       'changes' => $filters['changes'] ? 1 : null] , fn($v) => $v !== null && $v !== '' && $v !== 0);
    return '?' . http_build_query(array_merge($p, $over));
};
$sizeLabel = function (array $r) use ($sizeNotes): string {
    $t = h((string)$r['size']);
    if (!empty($r['size_code']) && isset($sizeNotes[$r['size_code']]) && $sizeNotes[$r['size_code']] !== '') {
        $t = '<span title="' . h($sizeNotes[$r['size_code']]) . '">' . $t . '</span>';
    }
    return $t;
};
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>
<p class="mb-2"><a class="btn btn-outline-primary" href="/crm/suppliers/best-price.php">Best price: who has it cheapest →</a></p>

<div class="mw-ops-page mw-pl-page">
  <div class="mw-ops-head">
    <img src="/crm/img/heads/otto.jpg" alt="" width="56" height="56">
    <div>
      <h1 class="h3 mb-1">Supplier price lists</h1>
      <p class="text-muted mb-0">What suppliers have this week, at our trade price. Lists that arrive by email are read automatically.</p>
    </div>
  </div>

  <?php if ($flash !== ''): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>

  <?php if ($ready && $taxaPending > 0 && $canEdit): ?>
  <form method="post" class="mw-pl-taxa mb-3">
    <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>"><input type="hidden" name="do" value="fetch_taxa">
    <button class="btn btn-outline-primary btn-sm">Fetch plant names and photos</button>
    <span class="mw-ops-hint"><?= (int)$taxaPending ?> catalogue plant(s) have no common name or photo yet. This looks up about 40 species a click; it also runs by itself every 6 hours. Photos come from iNaturalist and show the photographer's credit on hover.</span>
  </form>
  <?php endif; ?>

  <?php foreach ($reviews as $rv): $fmt = $rv['format']; $seen = (array)((json_decode((string)$fmt['spec_json'], true) ?: [])['seen_headers'] ?? []); ?>
  <div class="card mw-plr">
    <div class="card-body">
      <h2 class="h5 mb-1">Check how we read <?= h($rv['list']['supplier_name'] ?: $fmt['name']) ?></h2>
      <p class="mw-ops-hint mb-2">This is the first list we have from them, read automatically (<?= (int)round(((float)$fmt['inferred_confidence']) * 100) ?>% sure). The prices are shown here but are <strong>not used in quotes</strong> until you say it looks right.</p>
      <div class="table-responsive">
        <table class="table table-sm mw-ops-table">
          <thead><tr><th>Name</th><th>Size</th><th class="text-right">Price</th><th class="text-right">Available</th></tr></thead>
          <tbody>
          <?php foreach ($rv['sample'] as $it): ?>
            <tr><td><?= h($it['name']) ?></td><td><?= h((string)$it['size']) ?></td><td class="text-right"><?= h($money($it['price'])) ?></td><td class="text-right"><?= $it['available'] === null ? '' : (int)$it['available'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="mw-ops-hint mb-2">Columns used: <?php foreach ($rv['columns'] as $field => $hdr): ?><span class="mw-plr-col"><?= h(str_replace('_', ' ', $field)) ?> = “<?= h($hdr) ?>”</span> <?php endforeach; ?></p>
      <?php if ($canEdit): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
        <input type="hidden" name="do" value="confirm_format"><input type="hidden" name="format_id" value="<?= (int)$fmt['id'] ?>">
        <button class="btn btn-primary btn-sm">Looks right</button>
      </form>
      <details class="mw-plr-fix">
        <summary>Something is wrong — fix the columns</summary>
        <form method="post" class="mt-2">
          <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
          <input type="hidden" name="do" value="fix_columns"><input type="hidden" name="format_id" value="<?= (int)$fmt['id'] ?>">
          <div class="mw-plr-grid">
          <?php foreach (['name' => 'Name', 'product_code' => 'Code', 'size' => 'Size', 'price' => 'Our price', 'list_price' => 'List price', 'special_price' => 'Special price', 'available' => 'Available', 'zone' => 'Zone', 'category' => 'Category'] as $field => $label): ?>
            <label><?= h($label) ?>
              <select name="cols[<?= h($field) ?>]" class="form-control form-control-sm">
                <option value="">— none —</option>
                <?php foreach ($seen as $hdr): ?><option value="<?= h($hdr) ?>"<?= ($rv['columns'][$field] ?? '') === $hdr ? ' selected' : '' ?>><?= h($hdr) ?></option><?php endforeach; ?>
              </select>
            </label>
          <?php endforeach; ?>
          </div>
          <button class="btn btn-outline-primary btn-sm mt-2">Save columns</button>
        </form>
      </details>
      <?php else: ?><p class="mw-ops-hint mb-0">Someone with product edit access needs to confirm this.</p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($skips): ?>
  <div class="card mw-plr">
    <div class="card-body">
      <h2 class="h5 mb-1">Lists we could not read</h2>
      <p class="mw-ops-hint mb-2">These arrived by email but did not look like a price list we can read. Forward the file to Claude to teach it, or dismiss it.</p>
      <?php foreach ($skips as $k): ?>
        <div class="mw-plr-skip">
          <div><strong><?= h($k['supplier_name'] ?: $k['sender']) ?></strong> — <?= h($k['file_name']) ?> <span class="text-muted">(<?= h(substr((string)$k['created_at'], 0, 10)) ?>)</span><br><span class="text-muted"><?= h($k['reason']) ?></span></div>
          <?php if ($canEdit): ?>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>"><input type="hidden" name="do" value="dismiss_skip"><input type="hidden" name="skip_id" value="<?= (int)$k['id'] ?>"><button class="btn btn-outline-secondary btn-sm">Dismiss</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Run migration 1331 first (/crm/api/run-migration-1331.php).</div>
  <?php elseif (!$suppliers): ?>
    <div class="card"><div class="card-body">No price lists yet. When a supplier with a known list format (Inline Nurseries) emails its weekly list, it appears here.</div></div>
  <?php else: ?>

  <?php if (count($suppliers) > 1): ?>
  <div class="mw-ops-chips mb-3">
    <?php foreach ($suppliers as $sid => $name): ?>
      <a class="mw-ops-chip<?= $sid === $supplierId ? ' is-covered' : '' ?>" href="?supplier=<?= (int)$sid ?>"><?= h($name) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($compareProduct): ?>
  <div class="card mw-pl-compare"><div class="card-body">
    <h2 class="h5 mb-1"><?= h($compareProduct['canonical_name']) ?><?= $compareSize !== '' ? ' — ' . h($compareSize) : '' ?></h2>
    <p class="mw-ops-hint mb-2">
      <?php if (!empty($compareAttrs['zone'])): ?>Zone <?= h($compareAttrs['zone']) ?>. <?php endif; ?>
      <?php if (!empty($compareProduct['common_names'])): ?>Also called <?= h(str_replace('|', ', ', $compareProduct['common_names'])) ?>. <?php endif; ?>
      Each supplier's newest list, cheapest first.
      <?php if ($compareSize !== ''): ?><a href="<?= h($qs(['compare' => $compareId])) ?>">All sizes</a><?php endif; ?>
      · <a href="<?= h($qs([])) ?>">Close</a>
    </p>
    <div class="table-responsive">
      <table class="table table-sm mw-ops-table">
        <thead><tr><th>Supplier</th><th>Their name</th><th>Size</th><th class="text-right">Trade price</th><th class="text-right">Available</th><th>List date</th></tr></thead>
        <tbody>
          <?php foreach ($compare as $r): ?>
            <tr><td><?= h($r['supplier_name'] ?? '') ?></td><td><?= h($r['name']) ?></td><td><?= $sizeLabel($r) ?><?= $r['height'] ? ' ' . h($r['height']) : '' ?></td>
                <td class="text-right"><?= $money($r['price']) ?></td><td class="text-right"><?= $r['available'] === null ? '' : (int)$r['available'] ?></td><td><?= h($r['list_date']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$compare): ?><tr><td colspan="6" class="text-muted">No supplier lists this one now.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
  <?php endif; ?>

  <div class="mw-pl-summary">
    <div class="card"><div class="card-body">
      <h2 class="h5"><?= h($suppliers[$supplierId]) ?> — availability</h2>
      <?php if ($avail): ?>
        <p class="mb-1">List of <b><?= h(date('M j, Y', strtotime($avail['list_date']))) ?></b> · <?= (int)$avail['row_count'] ?> lines · read <?= h(date('M j', strtotime($avail['imported_at']))) ?></p>
        <?php if ($diff && !$diff['first']): ?>
          <p class="mb-0 mw-pl-diff">Since the last list:
            <span class="mw-pl-new"><?= (int)$diff['new'] ?> new</span> ·
            <span class="mw-pl-up"><?= (int)$diff['up'] ?> up</span> ·
            <span class="mw-pl-down"><?= (int)$diff['down'] ?> down</span> ·
            <?= count($diff['gone']) ?> gone</p>
        <?php else: ?>
          <p class="mw-ops-hint mb-0">First list from this supplier — price changes show from next week.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="mb-0 text-muted">No availability list yet.</p>
      <?php endif; ?>
      <?php if ($specials): ?>
        <p class="mb-0 mt-2"><b><?= h($specials['title'] ?: 'Specials') ?></b>
          <?= $specials['valid_until'] ? ' — valid until ' . h(date('M j, Y', strtotime($specials['valid_until']))) : '' ?>
          <?= $curSpecials['list_id'] ? '' : ' <span class="mw-ops-flag">ended</span>' ?></p>
      <?php endif; ?>
    </div></div>

    <?php $rules = array_merge($avail['rules'] ?? [], $specials['rules'] ?? []); if ($rules): ?>
    <div class="card mw-pl-rules"><div class="card-body">
      <h2 class="h6">Order rules (as printed on the list)</h2>
      <ul class="mw-ops-list"><?php foreach (array_unique($rules) as $r): ?><li><?= h($r) ?></li><?php endforeach; ?></ul>
    </div></div>
    <?php endif; ?>
  </div>

  <?php if ($queue): ?>
  <div class="card"><div class="card-body">
    <h2 class="h5">Is this the same plant?</h2>
    <p class="mw-ops-hint">These names look like a catalogue entry but are not linked until someone decides. What you decide is learned for every future list.</p>
    <div class="table-responsive">
      <table class="table table-sm mw-ops-table">
        <thead><tr><th>Supplier's name</th><th>Looks like</th><th class="text-right">Lines</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($queue as $q): ?>
          <tr>
            <td><?= h($q['raw_name']) ?></td>
            <td><?= h((string)$q['suggested_name']) ?> <span class="mw-ops-hint">(<?= h(number_format((float)$q['score'] * 100)) ?>%)</span></td>
            <td class="text-right"><?= (int)$q['item_count'] ?></td>
            <td class="text-nowrap">
              <?php if ($canEdit): ?>
              <form method="post" class="mw-pl-decide">
                <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
                <input type="hidden" name="queue_id" value="<?= (int)$q['id'] ?>">
                <button class="btn btn-sm btn-success" name="decision" value="confirm">Same plant</button>
                <button class="btn btn-sm btn-outline-secondary" name="decision" value="separate">Different plant</button>
                <input type="number" name="target_id" class="form-control form-control-sm" placeholder="Catalogue #" aria-label="Catalogue number to merge into">
                <button class="btn btn-sm btn-outline-secondary" name="decision" value="merge">Same as #</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>
  <?php endif; ?>

  <?php if ($avail): ?>
  <div class="card"><div class="card-body">
    <form method="get" class="mw-pl-filters">
      <input type="hidden" name="supplier" value="<?= (int)$supplierId ?>">
      <input type="search" name="q" value="<?= h($filters['q']) ?>" class="form-control form-control-sm" placeholder="Search name, common name or code">
      <select name="category" class="form-control form-control-sm">
        <option value="">All categories</option>
        <?php foreach ($facets['categories'] as $c): ?><option value="<?= h($c) ?>"<?= $filters['category'] === $c ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
      </select>
      <select name="size" class="form-control form-control-sm">
        <option value="">All sizes</option>
        <?php foreach ($facets['sizes'] as $z): ?><option value="<?= h($z['size_code']) ?>"<?= $filters['size'] === $z['size_code'] ? ' selected' : '' ?>><?= h($z['label']) ?> (<?= (int)$z['n'] ?>)</option><?php endforeach; ?>
      </select>
      <label class="mw-pl-check"><input type="checkbox" name="changes" value="1"<?= $filters['changes'] ? ' checked' : '' ?>> Only changes</label>
      <button class="btn btn-sm btn-success">Show</button>
      <?php if ($filters['q'] !== '' || $filters['category'] !== '' || $filters['size'] !== '' || $filters['changes']): ?><a class="btn btn-sm btn-link" href="?supplier=<?= (int)$supplierId ?>">Clear</a><?php endif; ?>
    </form>
    <p class="mw-ops-hint"><?= count($items) ?> line(s). Prices are our trade prices — never show them to clients.</p>
    <div class="table-responsive">
      <table class="table table-sm mw-ops-table mw-pl-table">
        <thead><tr><th>Code</th><th>Plant</th><th>Size</th><th>Zone</th><th>Category</th><th class="text-right">Trade price</th><th class="text-right">Last list</th><th class="text-right">Special</th><th class="text-right">Available</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $r): ?>
          <tr class="<?= $r['price_change'] ? 'mw-pl-' . h($r['price_change']) . '-row' : '' ?>">
            <td class="mw-pl-code"><?= h((string)$r['product_code']) ?></td>
            <td class="mw-pl-name"><?php if (!empty($r['photo_file'])): ?><img class="mw-plantpic" src="/uploads/taxa/<?= h(rawurlencode((string)$r['photo_file'])) ?>" alt="" loading="lazy" title="<?= h((string)$r['photo_credit']) ?>"><?php endif; ?><?= h($r['name']) ?>
              <?php if (!empty($r['common_names'])): ?><div class="mw-ops-hint mt-0"><?= h(str_replace('|', ', ', $r['common_names'])) ?></div><?php endif; ?></td>
            <td><?= $sizeLabel($r) ?></td>
            <td><?= h((string)$r['zone']) ?></td>
            <td><?= h((string)$r['category']) ?></td>
            <td class="text-right"><?= $money($r['price']) ?></td>
            <td class="text-right">
              <?php if ($r['price_change'] === 'new'): ?><span class="mw-pl-new">new</span>
              <?php elseif ($r['price_change'] === 'up' || $r['price_change'] === 'down'): ?><span class="mw-pl-<?= h($r['price_change']) ?>"><?= $r['price_change'] === 'up' ? '▲' : '▼' ?> was <?= $money($r['prev_price']) ?></span>
              <?php endif; ?></td>
            <td class="text-right"><?php if (!empty($r['special'])): ?><span class="mw-pl-special" title="<?= h(trim(($r['special']['comment'] ?? '') . ($r['special_until'] ? ' — until ' . $r['special_until'] : ''))) ?>"><?= $money($r['special']['price']) ?></span><?php endif; ?></td>
            <td class="text-right"><?= $r['available'] === null ? '' : (int)$r['available'] ?></td>
            <td><?php if ($r['catalog_product_id']): ?><a class="mw-ops-src" href="<?= h($qs(['compare' => (int)$r['catalog_product_id'], 'csize' => (string)$r['size_code']])) ?>">Compare</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($diff && $diff['gone'] && !$filters['q']): ?>
      <details class="mt-2"><summary class="mw-ops-hint">Gone since the last list (<?= count($diff['gone']) ?>)</summary>
        <ul class="mw-ops-list"><?php foreach ($diff['gone'] as $g): ?><li><?= h($g['name']) ?> — <?= h((string)$g['size']) ?> (was <?= $money($g['price']) ?>)</li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
  </div></div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
