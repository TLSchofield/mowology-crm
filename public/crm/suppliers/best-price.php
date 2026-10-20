<?php
/**
 * Best price — "for any plant or material, the cheapest supplier that has it, how many they have, and
 * how far the yard is from the job" (owner, 2026-10-19).
 *
 * GET q (e.g. "japanese maple #5", "garden soil") and optionally property_id (the job) → every supplier's
 * LATEST list, cheapest in stock first; stock, the list's date (flagged after 30 days), the order minimums,
 * and about how far each supplier's yard is from the job (an estimate: straight line × detour). Plants show
 * the selling price at the plant markup; bulk materials are priced installed by Otto and Sam on the quote.
 * Server-rendered, no inline script. Thin controller: SupplierSearchService.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
if (!userHasAnyPermission(['products.view', 'quotes.edit'])) { http_response_code(403); exit('You need products.view to see supplier prices.'); }
$user = getCurrentUser();

$pageTitle = 'Best Price';
$activePage = 'products';

require_once APP_ROOT . '/Modules/Operations/Services/SupplierSearchService.php';
require_once APP_ROOT . '/Modules/Operations/Services/MarketPriceService.php';
$db = getDB();
$market = new MarketPriceService($db);
$q = trim((string)($_GET['q'] ?? ''));
$pid = (int)($_GET['property_id'] ?? 0);
$res = null;
$error = '';
if (mb_strlen($q) >= 2) {
    try {
        $res = (new SupplierSearchService($db))->search(mb_substr($q, 0, 80), $pid > 0 ? $pid : null, 20);
    } catch (Throwable $e) {
        error_log('best-price: ' . $e->getMessage());
        $error = 'The search failed — try again.';
    }
}
$props = [];
try {
    $props = $db->query("SELECT id, address, city FROM properties WHERE latitude IS NOT NULL AND latitude <> 0 ORDER BY address LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* no job list */ }
$money = static fn($v) => $v === null ? '—' : '$' . number_format((float)$v, 2);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mb-3">
  <h1 class="h3 mb-1">Best price</h1>
  <p class="text-muted mb-0">Who has it, cheapest first — from each supplier's latest list. <a href="/crm/suppliers/price-lists.php">All price lists</a></p>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="form-row align-items-end">
      <div class="col-md-5 mb-2">
        <label class="form-label" for="bp-q">Plant or material</label>
        <input class="form-control form-control-lg" id="bp-q" name="q" value="<?= h($q) ?>" placeholder="japanese maple #5, boxwood #1, garden soil" autofocus>
      </div>
      <div class="col-md-5 mb-2">
        <label class="form-label" for="bp-job">Job (for distance) — optional</label>
        <select class="form-control form-control-lg" id="bp-job" name="property_id">
          <option value="">No job</option>
          <?php foreach ($props as $p): ?>
          <option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $pid ? ' selected' : '' ?>><?= h(trim($p['address'] . ', ' . $p['city'], ', ')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 mb-2">
        <button class="btn btn-primary btn-lg btn-block" type="submit">Search</button>
      </div>
    </form>
    <p class="text-muted small mb-0">Sizes: #5 or 5 gal (pots), 2m (height), 6cm (caliper). Common names work for every supplier — botanical-only lists are matched through the lists that carry both.</p>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($res !== null): ?>
  <p class="mb-2">
    <strong><?= count($res['results']) ?></strong> result<?= count($res['results']) === 1 ? '' : 's' ?> for “<?= h($q) ?>”<?= $res['size'] ? ' in ' . h($res['size']) : '' ?><?= $res['job'] ? ' · distances from ' . h($res['job']['label']) . ' (about — straight line × detour)' : '' ?>.
    Plants sell at cost + <?= h(rtrim(rtrim(number_format($res['markup_pct'], 1), '0'), '.')) ?>%.
  </p>
  <?php if (!$res['results']): ?>
    <div class="alert alert-light border">No supplier lists it right now. Try fewer words, another size, or the botanical name.</div>
  <?php endif; ?>
  <?php foreach ($res['results'] as $g): $b = $g['best']; ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-baseline">
        <div class="mb-2">
          <div class="h5 mb-0"><?= h($g['common'] ?: $g['name']) ?> <span class="text-muted">· <?= h($g['size'] ?: 'per yard') ?></span></div>
          <?php if ($g['common']): ?><div class="text-muted"><?= h($b['item']) ?></div><?php endif; ?>
        </div>
        <div class="text-right mb-2">
          <div class="h4 mb-0"><?= $money($b['price']) ?> <span class="h6 text-muted">cost · <?= h($b['supplier']) ?></span></div>
          <?php if ($g['sell'] !== null): ?><div>Sell <strong><?= $money($g['sell']) ?></strong></div><?php else: ?><div class="text-muted small"><?= h((string)$g['sell_note']) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($g['bulk']): $mk = $market->current($res['words'], MarketPriceService::material($g['name'])); if ($mk): ?>
      <div class="alert alert-light border py-2 mb-2">
        <strong>Local market</strong> — what customers pay others:
        <?php foreach ($mk as $m): ?>
          <div><?= h($m['source']) ?>, <?= h($m['product']) ?>:
            <?= h(implode(' · ', array_map(fn($y, $p) => ((float)$y === 1.0 ? '1 yd' : ((float)$y === 0.5 ? '½ yd' : ((float)$y === 0.25 ? '¼ yd' : $y . ' yd'))) . ' $' . number_format($p, 0), array_keys($m['sizes']), $m['sizes']))) ?>
            <span class="text-muted small">(<?= h((string)$m['what']) ?> Read <?= h(date('M j', strtotime($m['seen_on']))) ?>.)</span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; endif; ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Supplier</th><th>As listed</th><th class="text-right">Price</th><th class="text-right">In stock</th><th>List</th><th>From the job</th><th>Order rules</th></tr></thead>
          <tbody>
          <?php foreach ($g['offers'] as $o): ?>
            <tr>
              <td><strong><?= h($o['supplier']) ?></strong></td>
              <td><?= h($o['item']) ?> · <?= h($o['size']) ?></td>
              <td class="text-right"><?= $money($o['price']) ?><?php if ($o['price_change'] === 'up' || $o['price_change'] === 'down'): ?> <span class="badge badge-<?= $o['price_change'] === 'up' ? 'warning' : 'success' ?>"><?= $o['price_change'] === 'up' ? 'up' : 'down' ?> from <?= $money($o['prev_price']) ?></span><?php endif; ?></td>
              <td class="text-right"><?= $o['available'] === null ? '<span class="text-muted">not listed</span>' : ($o['available'] > 0 ? number_format($o['available']) : '<span class="badge badge-danger">out</span>') ?></td>
              <td><?= h(date('M j', strtotime($o['list_date']))) ?><?= $o['stale'] ? ' <span class="badge badge-warning">' . (int)$o['age_days'] . ' days old</span>' : '' ?></td>
              <td><?= $o['distance'] ? 'about ' . h((string)$o['distance']['km']) . ' km · ' . (int)$o['distance']['minutes'] . ' min' : '<span class="text-muted">—</span>' ?></td>
              <td class="small"><?= h(implode(' · ', $o['rules'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
