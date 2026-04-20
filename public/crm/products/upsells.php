<?php
/**
 * Upsells Admin — Configure product_upsells relationships + bundle pricing.
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') {
    $_SESSION['alert'] = ['type' => 'error', 'message' => 'Admin access required'];
    header('Location: /crm/dashboard_appstack.php');
    exit;
}

$pageTitle  = 'Upsell Configuration';
$activePage = 'products';

$db = getDB();

// Load all upsell rows with product/bundle info
$upsells = [];
try {
    $upsells = $db->query("
        SELECT
            u.id, u.base_product_id, u.upsell_product_id, u.upsell_bundle_id,
            u.type, u.display_text, u.default_checked, u.sort_order,
            u.is_active, u.is_popular, u.bundled_price,
            bp.name AS base_name,
            up.name AS upsell_product_name,
            up.base_price AS upsell_product_price,
            ub.name AS upsell_bundle_name,
            ub.bundle_price AS upsell_bundle_price
        FROM product_upsells u
        JOIN products bp ON u.base_product_id = bp.id
        LEFT JOIN products up ON u.upsell_product_id = up.id
        LEFT JOIN product_bundles ub ON u.upsell_bundle_id = ub.id
        ORDER BY bp.name, u.sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // product_bundles may not exist on all envs — fall back to product-only
    try {
        $upsells = $db->query("
            SELECT u.id, u.base_product_id, u.upsell_product_id, NULL AS upsell_bundle_id,
                   u.type, u.display_text, u.default_checked, u.sort_order,
                   u.is_active, u.is_popular, u.bundled_price,
                   bp.name AS base_name,
                   up.name AS upsell_product_name,
                   up.base_price AS upsell_product_price,
                   NULL AS upsell_bundle_name, NULL AS upsell_bundle_price
            FROM product_upsells u
            JOIN products bp ON u.base_product_id = bp.id
            LEFT JOIN products up ON u.upsell_product_id = up.id
            ORDER BY bp.name, u.sort_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $upsells = [];
    }
}

// Load products for picker
$products = [];
try {
    $products = $db->query("
        SELECT id, name, base_price FROM products
        WHERE active = 1 AND is_archived = 0
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $products = []; }

// Load bundles for picker
$bundles = [];
try {
    $bundles = $db->query("
        SELECT id, name, bundle_price FROM product_bundles
        WHERE is_active = 1
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $bundles = []; }
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Quote Upsells</h1>
        <p class="mw-page-subtitle">Add-ons shown to customers on quotes. Bundle pricing (pre-signing) &amp; regular pricing (post-accept).</p>
    </div>
    <div class="mw-page-header-right">
        <a href="/crm/products/" class="btn btn-outline-secondary mr-2">
            <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Products Hub
        </a>
        <button class="btn btn-primary" onclick="mwOpenUpsellModal()">
            <i data-feather="plus" style="width:14px;height:14px;"></i> Add Upsell
        </button>
    </div>
</div>

<?php if (empty($upsells)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i data-feather="package" style="width:48px;height:48px;color:#94a3b8;"></i>
            <h4 class="mt-3">No upsells configured yet</h4>
            <p class="text-muted">Map a base product (e.g. "Lawn Care") to an upsell product or bundle (e.g. "Aeration") to offer add-ons on customer quotes.</p>
            <button class="btn btn-primary mt-2" onclick="mwOpenUpsellModal()">+ Create first upsell</button>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><?php echo count($upsells); ?> upsell relationship<?php echo count($upsells)===1?'':'s'; ?> configured</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="mw-upsells-table">
                    <thead>
                        <tr>
                            <th>Base Product</th>
                            <th>→ Add-On</th>
                            <th>Type</th>
                            <th>Regular</th>
                            <th>Bundled</th>
                            <th>Savings</th>
                            <th class="text-center">Popular</th>
                            <th class="text-center">Active</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upsells as $u):
                            $isBundle = !empty($u['upsell_bundle_id']);
                            $upsellName = $isBundle ? $u['upsell_bundle_name'] : $u['upsell_product_name'];
                            $regular    = (float)($isBundle ? ($u['upsell_bundle_price'] ?? 0) : ($u['upsell_product_price'] ?? 0));
                            $bundled    = $u['bundled_price'] !== null ? (float)$u['bundled_price'] : round($regular * 0.85, 2);
                            $savings    = round($regular - $bundled, 2);
                        ?>
                        <tr id="mw-upsell-row-<?php echo (int)$u['id']; ?>">
                            <td class="mw-cell-primary"><?php echo h($u['base_name']); ?></td>
                            <td>
                                <span class="mw-cell-primary">
                                    <?php if ($isBundle): ?><i data-feather="gift" style="width:14px;height:14px;"></i><?php endif; ?>
                                    <?php echo h($upsellName ?: '— missing —'); ?>
                                </span>
                                <?php if (!empty($u['display_text'])): ?>
                                <span class="mw-cell-secondary"><?php echo h($u['display_text']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $u['type']==='recommended'?'success':($u['type']==='upgrade'?'info':'secondary'); ?>">
                                    <?php echo h($u['type']); ?>
                                </span>
                                <?php if ($isBundle): ?><span class="badge badge-primary ml-1">Bundle</span><?php endif; ?>
                            </td>
                            <td>$<?php echo number_format($regular, 2); ?></td>
                            <td>
                                <div class="input-group input-group-sm" style="width:110px;">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" step="0.01" min="0" class="form-control mw-bundle-input"
                                           value="<?php echo number_format($bundled, 2, '.', ''); ?>"
                                           data-upsell-id="<?php echo (int)$u['id']; ?>"
                                           data-regular="<?php echo $regular; ?>"
                                           onchange="mwUpdateBundledPrice(<?php echo (int)$u['id']; ?>, this.value)">
                                </div>
                            </td>
                            <td>
                                <span class="mw-savings-<?php echo (int)$u['id']; ?> text-success font-weight-bold">
                                    $<?php echo number_format($savings, 2); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <label class="mw-switch">
                                    <input type="checkbox" <?php echo $u['is_popular']?'checked':''; ?>
                                           onchange="mwTogglePopular(<?php echo (int)$u['id']; ?>, this.checked)">
                                    <span class="mw-switch-slider"></span>
                                </label>
                            </td>
                            <td class="text-center">
                                <label class="mw-switch">
                                    <input type="checkbox" <?php echo $u['is_active']?'checked':''; ?>
                                           onchange="mwToggleActive(<?php echo (int)$u['id']; ?>, this.checked)">
                                    <span class="mw-switch-slider"></span>
                                </label>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-danger" onclick="mwDeleteUpsell(<?php echo (int)$u['id']; ?>)" title="Delete">
                                    <i data-feather="trash-2" style="width:14px;height:14px;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Add Upsell Modal -->
<div class="modal fade" id="mwUpsellModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title">New Upsell Relationship</h5>
                    <small class="text-muted">When a customer adds the base product to their quote, they'll be offered this upsell.</small>
                </div>
                <button type="button" class="close" data-dismiss="modal" onclick="mwCloseModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mw-searchable-select">
                            <label class="mw-form-label">Base Product <span class="text-muted">(what the customer bought)</span></label>
                            <input type="text" class="form-control mw-search-input" placeholder="Type to search…"
                                   oninput="mwFilterSelect('mwBaseProduct', this.value)" autocomplete="off">
                            <select id="mwBaseProduct" class="form-control mw-upsell-select" size="8" onchange="mwSyncSelection('mwBaseProduct')">
                                <?php foreach ($products as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" data-search="<?php echo strtolower(h($p['name'])); ?>"><?php echo h($p['name']); ?> ($<?php echo number_format($p['base_price'],2); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mw-searchable-select">
                            <label class="mw-form-label">Upsell Target <span class="text-muted">(product or bundle)</span></label>

                            <div class="mw-upsell-type-toggle">
                                <label><input type="radio" name="mwUpsellKind" value="product" checked onchange="mwSwitchUpsellKind('product')"> Product</label>
                                <label><input type="radio" name="mwUpsellKind" value="bundle" <?php echo empty($bundles)?'disabled':''; ?> onchange="mwSwitchUpsellKind('bundle')"> Bundle <?php if (empty($bundles)): ?><small class="text-muted">(none created)</small><?php endif; ?></label>
                            </div>

                            <input type="text" class="form-control mw-search-input" placeholder="Type to search…"
                                   oninput="mwFilterSelect(mwCurrentUpsellSelect(), this.value)" autocomplete="off" id="mwUpsellSearch">

                            <select id="mwUpsellProduct" class="form-control mw-upsell-select" size="8" onchange="mwAutoFillBundle()">
                                <?php foreach ($products as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo (float)$p['base_price']; ?>" data-search="<?php echo strtolower(h($p['name'])); ?>"><?php echo h($p['name']); ?> ($<?php echo number_format($p['base_price'],2); ?>)</option>
                                <?php endforeach; ?>
                            </select>

                            <select id="mwUpsellBundle" class="form-control mw-upsell-select" size="8" style="display:none;" onchange="mwAutoFillBundle()">
                                <?php foreach ($bundles as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>" data-price="<?php echo (float)$b['bundle_price']; ?>" data-search="<?php echo strtolower(h($b['name'])); ?>"><?php echo h($b['name']); ?> ($<?php echo number_format($b['bundle_price'],2); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="mw-form-label">Type</label>
                            <select id="mwType" class="form-control">
                                <option value="addon">Add-on (optional extra)</option>
                                <option value="recommended">Recommended</option>
                                <option value="upgrade">Upgrade</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label class="mw-form-label">Display Text <span class="text-muted">(optional — overrides product name on customer quote)</span></label>
                            <input type="text" id="mwDisplayText" class="form-control" placeholder="e.g. Add aeration — loosens soil & improves root depth">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="mw-form-label">Bundled Price <span class="text-muted">(pre-signing discount)</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                <input type="number" step="0.01" min="0" id="mwBundledPrice" class="form-control" placeholder="Auto-fills to 85% of regular">
                            </div>
                            <small class="text-muted">Leave blank for 85% auto-discount. Set higher to reduce the bundle savings.</small>
                        </div>
                    </div>
                    <div class="col-md-6 d-flex align-items-center pt-3">
                        <label class="mw-switch-label">
                            <label class="mw-switch mr-2">
                                <input type="checkbox" id="mwIsPopular">
                                <span class="mw-switch-slider"></span>
                            </label>
                            <strong>Flag as "Most popular"</strong>
                            <div class="text-muted small">Shows a ⭐ badge on the customer quote (auto-tagged weekly by adoption rate — override here if you want).</div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="mwCloseModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="mwCreateUpsell()" id="mwCreateBtn">
                    <i data-feather="check" style="width:14px;height:14px;"></i> Create Upsell
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.mw-page-header-right { display: flex; align-items: center; }
.mw-upsell-select {
    height: auto !important;
    min-height: 200px;
    max-height: 240px;
    overflow-y: auto;
    font-size: 0.9rem;
}
.mw-upsell-select option { padding: 6px 10px; }
.mw-upsell-select option:checked { background: var(--mw-green); color: #fff; }
.mw-search-input { margin-bottom: 6px; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
.mw-searchable-select select { border-top-left-radius: 0; border-top-right-radius: 0; border-top: 0; }
.mw-searchable-select .mw-search-input:focus + select,
.mw-searchable-select select:focus { border-color: var(--mw-green); box-shadow: var(--mw-shadow-ring); outline: none; }

.mw-upsell-type-toggle { display: flex; gap: 14px; margin-bottom: 8px; font-size: 0.875rem; }
.mw-upsell-type-toggle label { margin: 0; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.mw-upsell-type-toggle input { margin: 0; accent-color: var(--mw-green); }

.mw-form-label { font-weight: 600; color: #1a202c; margin-bottom: 6px; display: block; }

.modal-lg { max-width: 820px; }
.modal-header { align-items: flex-start; }
.modal-header .close { font-size: 1.5rem; font-weight: 400; line-height: 1; color: #64748b; margin-left: 1rem; }

.mw-switch { position: relative; display: inline-block; width: 36px; height: 20px; vertical-align: middle; margin: 0; }
.mw-switch input { opacity: 0; width: 0; height: 0; }
.mw-switch-slider { position: absolute; cursor: pointer; inset: 0; background: #cbd5e1; border-radius: 20px; transition: .2s; }
.mw-switch-slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
.mw-switch input:checked + .mw-switch-slider { background: var(--mw-green); }
.mw-switch input:checked + .mw-switch-slider:before { transform: translateX(16px); }
.mw-switch-label { display: block; font-weight: 500; margin: 0; cursor: pointer; }

#mw-upsells-table .input-group-text { background: #f1f5f9; border-color: #e2e8f0; font-size: 0.8rem; padding: 2px 8px; }
</style>

<script>
var mwCurrentKind = 'product';

function mwCurrentUpsellSelect() {
    return mwCurrentKind === 'bundle' ? 'mwUpsellBundle' : 'mwUpsellProduct';
}

function mwSwitchUpsellKind(kind) {
    mwCurrentKind = kind;
    document.getElementById('mwUpsellProduct').style.display = kind === 'product' ? '' : 'none';
    document.getElementById('mwUpsellBundle').style.display  = kind === 'bundle'  ? '' : 'none';
    document.getElementById('mwUpsellSearch').value = '';
    mwFilterSelect(mwCurrentUpsellSelect(), '');
    document.getElementById('mwBundledPrice').value = '';
}

function mwFilterSelect(selectId, query) {
    var sel = document.getElementById(selectId);
    if (!sel) return;
    var q = query.toLowerCase().trim();
    Array.from(sel.options).forEach(function(opt) {
        var searchText = opt.getAttribute('data-search') || opt.textContent.toLowerCase();
        opt.style.display = (q === '' || searchText.indexOf(q) !== -1) ? '' : 'none';
    });
}

function mwSyncSelection(selectId) {
    // Placeholder — select is already controlled via value
}

function mwOpenUpsellModal() {
    document.getElementById('mwBaseProduct').value = '';
    document.getElementById('mwUpsellProduct').value = '';
    document.getElementById('mwUpsellBundle').value = '';
    document.getElementById('mwDisplayText').value = '';
    document.getElementById('mwBundledPrice').value = '';
    document.getElementById('mwType').value = 'addon';
    document.getElementById('mwIsPopular').checked = false;
    document.getElementById('mwUpsellSearch').value = '';
    document.querySelectorAll('.mw-search-input').forEach(function(el){ el.value = ''; });
    mwSwitchUpsellKind('product');
    // Reset search filters
    mwFilterSelect('mwBaseProduct', '');
    mwFilterSelect('mwUpsellProduct', '');
    mwFilterSelect('mwUpsellBundle', '');
    if (typeof $ !== 'undefined') {
        $('#mwUpsellModal').modal('show');
    } else {
        document.getElementById('mwUpsellModal').classList.add('show');
        document.getElementById('mwUpsellModal').style.display = 'block';
    }
    if (typeof feather !== 'undefined') feather.replace();
}

function mwCloseModal() {
    if (typeof $ !== 'undefined') $('#mwUpsellModal').modal('hide');
    else document.getElementById('mwUpsellModal').style.display = 'none';
}

function mwAutoFillBundle() {
    var selId = mwCurrentUpsellSelect();
    var sel = document.getElementById(selId);
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    var price = parseFloat(opt.getAttribute('data-price')) || 0;
    if (price > 0) {
        document.getElementById('mwBundledPrice').value = (price * 0.85).toFixed(2);
    }
}

function mwCreateUpsell() {
    var btn = document.getElementById('mwCreateBtn');
    var baseId   = parseInt(document.getElementById('mwBaseProduct').value, 10);
    var upsellProductId = mwCurrentKind === 'product'
        ? parseInt(document.getElementById('mwUpsellProduct').value, 10) : null;
    var upsellBundleId  = mwCurrentKind === 'bundle'
        ? parseInt(document.getElementById('mwUpsellBundle').value, 10) : null;

    if (!baseId) { alert('Please select a base product.'); return; }
    if (!upsellProductId && !upsellBundleId) { alert('Please select an upsell ' + mwCurrentKind + '.'); return; }
    if (upsellProductId && upsellProductId === baseId) { alert('Base and upsell must be different products.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating…';

    var payload = {
        base_product_id:   baseId,
        upsell_product_id: upsellProductId,
        upsell_bundle_id:  upsellBundleId,
        type:              document.getElementById('mwType').value,
        display_text:      document.getElementById('mwDisplayText').value,
        bundled_price:     parseFloat(document.getElementById('mwBundledPrice').value) || null,
        is_popular:        document.getElementById('mwIsPopular').checked ? 1 : 0,
    };
    fetch('/crm/api/upsells.php?action=create', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else {
            alert('Error: ' + (d.error || 'Unknown'));
            btn.disabled = false;
            btn.innerHTML = '<i data-feather="check" style="width:14px;height:14px;"></i> Create Upsell';
            if (typeof feather !== 'undefined') feather.replace();
        }
    });
}

function mwUpdateBundledPrice(upsellId, value) {
    var row = document.getElementById('mw-upsell-row-' + upsellId);
    var input = row && row.querySelector('.mw-bundle-input');
    var regular = parseFloat(input.getAttribute('data-regular')) || 0;
    var bundled = parseFloat(value) || 0;
    var savings = Math.max(0, regular - bundled).toFixed(2);

    fetch('/crm/api/upsells.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId, bundled_price: bundled })
    })
    .then(r => r.json()).then(d => {
        if (d.success) {
            var savingsEl = document.querySelector('.mw-savings-' + upsellId);
            if (savingsEl) savingsEl.textContent = '$' + savings;
        } else { alert('Error: ' + (d.error || 'Unknown')); }
    });
}

function mwTogglePopular(upsellId, isPopular) {
    fetch('/crm/api/upsells.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId, is_popular: isPopular ? 1 : 0 })
    }).then(r => r.json()).then(d => { if (!d.success) alert(d.error || 'Error'); });
}

function mwToggleActive(upsellId, isActive) {
    fetch('/crm/api/upsells.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId, is_active: isActive ? 1 : 0 })
    }).then(r => r.json()).then(d => { if (!d.success) alert(d.error || 'Error'); });
}

function mwDeleteUpsell(upsellId) {
    if (!confirm('Delete this upsell? Existing quotes are unaffected.')) return;
    fetch('/crm/api/upsells.php?action=delete', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId })
    })
    .then(r => r.json()).then(d => {
        if (d.success) {
            var row = document.getElementById('mw-upsell-row-' + upsellId);
            if (row) row.remove();
        } else { alert('Error: ' + (d.error || 'Unknown')); }
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
