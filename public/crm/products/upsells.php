<?php
/**
 * Upsells Admin — Configure product_upsells relationships + bundle pricing.
 *
 * Lets admins map base products → upsell products, set a discounted bundle
 * price, and flag "Most popular" (which shows a badge on the customer quote).
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

// Load all upsell rows with product info
$upsells = [];
try {
    $upsells = $db->query("
        SELECT
            u.id, u.base_product_id, u.upsell_product_id, u.type, u.display_text,
            u.default_checked, u.sort_order, u.is_active, u.is_popular, u.bundled_price,
            bp.name AS base_name,
            up.name AS upsell_name,
            up.base_price AS upsell_base_price,
            up.description AS upsell_description
        FROM product_upsells u
        JOIN products bp ON u.base_product_id = bp.id
        JOIN products up ON u.upsell_product_id = up.id
        ORDER BY bp.name, u.sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $upsells = [];
}

// Load all active products for dropdowns
$products = [];
try {
    $products = $db->query("
        SELECT id, name, base_price FROM products
        WHERE active = 1 AND is_archived = 0
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $products = [];
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Upsell Configuration</h1>
        <p class="mw-page-subtitle">Manage add-ons shown to customers on quotes — bundle pricing &amp; popular badges</p>
    </div>
    <div>
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
            <p class="text-muted">Map a base product to an upsell product to start offering add-ons on customer quotes.</p>
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
                            <th>Popular</th>
                            <th>Active</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($upsells as $u):
                            $regular = (float)$u['upsell_base_price'];
                            $bundled = $u['bundled_price'] !== null ? (float)$u['bundled_price'] : round($regular * 0.85, 2);
                            $savings = round($regular - $bundled, 2);
                        ?>
                        <tr id="mw-upsell-row-<?php echo (int)$u['id']; ?>">
                            <td class="mw-cell-primary"><?php echo h($u['base_name']); ?></td>
                            <td>
                                <span class="mw-cell-primary"><?php echo h($u['upsell_name']); ?></span>
                                <span class="mw-cell-secondary"><?php echo h($u['display_text']); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $u['type']==='recommended'?'success':($u['type']==='upgrade'?'info':'secondary'); ?>">
                                    <?php echo h($u['type']); ?>
                                </span>
                            </td>
                            <td>$<?php echo number_format($regular, 2); ?></td>
                            <td>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm mw-bundle-input"
                                       style="width:90px;"
                                       value="<?php echo number_format($bundled, 2, '.', ''); ?>"
                                       data-upsell-id="<?php echo (int)$u['id']; ?>"
                                       data-regular="<?php echo $regular; ?>"
                                       onchange="mwUpdateBundledPrice(<?php echo (int)$u['id']; ?>, this.value)">
                            </td>
                            <td>
                                <span class="mw-savings-<?php echo (int)$u['id']; ?> text-success font-weight-bold">
                                    $<?php echo number_format($savings, 2); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <label class="mw-switch" style="margin:0;">
                                    <input type="checkbox" <?php echo $u['is_popular']?'checked':''; ?>
                                           onchange="mwTogglePopular(<?php echo (int)$u['id']; ?>, this.checked)">
                                    <span class="mw-switch-slider"></span>
                                </label>
                            </td>
                            <td class="text-center">
                                <label class="mw-switch" style="margin:0;">
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
<div class="modal fade" id="mwUpsellModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Upsell Relationship</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Base Product (what the customer bought)</label>
                    <select id="mwBaseProduct" class="form-control">
                        <option value="">Choose a base product…</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo h($p['name']); ?> ($<?php echo number_format($p['base_price'],2); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Upsell Product (what they can add on)</label>
                    <select id="mwUpsellProduct" class="form-control" onchange="mwAutoFillBundle()">
                        <option value="">Choose an upsell product…</option>
                        <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo (float)$p['base_price']; ?>"><?php echo h($p['name']); ?> ($<?php echo number_format($p['base_price'],2); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select id="mwType" class="form-control">
                        <option value="addon">Add-on (optional extra)</option>
                        <option value="recommended">Recommended (suggested)</option>
                        <option value="upgrade">Upgrade (replaces/enhances base)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Display Text (shown on customer quote)</label>
                    <input type="text" id="mwDisplayText" class="form-control" placeholder="Optional — overrides the product name">
                </div>
                <div class="form-group">
                    <label>Bundled Price <small class="text-muted">(what they pay if added pre-signing — 85% of regular by default)</small></label>
                    <input type="number" step="0.01" id="mwBundledPrice" class="form-control" placeholder="Auto-filled when you select upsell product">
                </div>
                <div class="form-group">
                    <label><input type="checkbox" id="mwIsPopular"> Flag as "Most popular" (shows a badge)</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="mwCreateUpsell()">Create Upsell</button>
            </div>
        </div>
    </div>
</div>

<style>
.mw-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.mw-switch input { opacity: 0; width: 0; height: 0; }
.mw-switch-slider { position: absolute; cursor: pointer; inset: 0; background: #cbd5e1; border-radius: 20px; transition: .2s; }
.mw-switch-slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
.mw-switch input:checked + .mw-switch-slider { background: var(--mw-green); }
.mw-switch input:checked + .mw-switch-slider:before { transform: translateX(16px); }
</style>

<script>
function mwOpenUpsellModal() {
    document.getElementById('mwBaseProduct').value = '';
    document.getElementById('mwUpsellProduct').value = '';
    document.getElementById('mwDisplayText').value = '';
    document.getElementById('mwBundledPrice').value = '';
    document.getElementById('mwType').value = 'addon';
    document.getElementById('mwIsPopular').checked = false;
    if (typeof $ !== 'undefined') $('#mwUpsellModal').modal('show');
    else document.getElementById('mwUpsellModal').style.display = 'block';
}

function mwAutoFillBundle() {
    var sel = document.getElementById('mwUpsellProduct');
    var opt = sel.options[sel.selectedIndex];
    var price = parseFloat(opt.getAttribute('data-price')) || 0;
    if (price > 0) {
        document.getElementById('mwBundledPrice').value = (price * 0.85).toFixed(2);
    }
}

function mwCreateUpsell() {
    var payload = {
        base_product_id:   parseInt(document.getElementById('mwBaseProduct').value, 10),
        upsell_product_id: parseInt(document.getElementById('mwUpsellProduct').value, 10),
        type:              document.getElementById('mwType').value,
        display_text:      document.getElementById('mwDisplayText').value,
        bundled_price:     parseFloat(document.getElementById('mwBundledPrice').value) || null,
        is_popular:        document.getElementById('mwIsPopular').checked ? 1 : 0,
    };
    if (!payload.base_product_id || !payload.upsell_product_id) {
        alert('Please select both base and upsell products.');
        return;
    }
    if (payload.base_product_id === payload.upsell_product_id) {
        alert('Base and upsell must be different products.');
        return;
    }
    fetch('/crm/api/upsells.php?action=create', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert('Error: ' + (d.error || 'Unknown'));
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
        } else {
            alert('Error: ' + (d.error || 'Unknown'));
        }
    });
}

function mwTogglePopular(upsellId, isPopular) {
    fetch('/crm/api/upsells.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId, is_popular: isPopular ? 1 : 0 })
    })
    .then(r => r.json()).then(d => {
        if (!d.success) alert('Error: ' + (d.error || 'Unknown'));
    });
}

function mwToggleActive(upsellId, isActive) {
    fetch('/crm/api/upsells.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId, is_active: isActive ? 1 : 0 })
    })
    .then(r => r.json()).then(d => {
        if (!d.success) alert('Error: ' + (d.error || 'Unknown'));
    });
}

function mwDeleteUpsell(upsellId) {
    if (!confirm('Delete this upsell relationship? This does not affect existing quotes.')) return;
    fetch('/crm/api/upsells.php?action=delete', {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id: upsellId })
    })
    .then(r => r.json()).then(d => {
        if (d.success) {
            var row = document.getElementById('mw-upsell-row-' + upsellId);
            if (row) row.remove();
        } else {
            alert('Error: ' + (d.error || 'Unknown'));
        }
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
