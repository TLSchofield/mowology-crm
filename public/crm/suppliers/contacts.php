<?php
/** Supplier contacts — choose the address used by the quote-request workflow. */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('products.view');
$user = getCurrentUser();

$pageTitle = 'Supplier contacts';
$activePage = 'products';

require_once APP_ROOT . '/Modules/Operations/Services/SupplierContactService.php';
$svc = new SupplierContactService(getDB());
$ready = $svc->ready();
$canEdit = userHasPermission('products.edit');
$flash = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); exit('You need products.edit to change supplier contacts.'); }
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('The security token expired. Go back and try again.'); }
    try {
        $do = (string)($_POST['do'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        if ($do === 'websites') {
            @set_time_limit(90);
            $r = $svc->fromWebsites(10, 50);
            $flash = "Looked at {$r['suppliers']} suppliers and {$r['pages']} pages; found {$r['found']} addresses." . ($r['note'] !== '' ? ' ' . $r['note'] : '');
        } elseif ($do === 'keep') {
            $svc->keep((int)($_POST['contact_id'] ?? 0), $userId);
            $flash = 'That address will receive quote requests.';
        } elseif ($do === 'drop') {
            $wasDefault = $svc->drop((int)($_POST['contact_id'] ?? 0), $userId);
            $flash = $wasDefault
                ? 'Marked "not this one". It stays the account address until you choose another with "Write to this one".'
                : 'That address will not be used.';
        } elseif ($do === 'add') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $id = $svc->suggest($supplierId, (string)($_POST['email'] ?? ''), 'manual');
            $hasDefault = false;
            foreach ($svc->forSupplier($supplierId) as $contact) if (!empty($contact['is_default'])) $hasDefault = true;
            if (!$hasDefault) $svc->keep($id, $userId);
            $flash = $hasDefault ? 'Address added.' : 'Address added and marked for quote requests.';
        }
    } catch (InvalidArgumentException $e) {
        $flash = $e->getMessage();
    } catch (Throwable $e) {
        error_log('supplier contacts: ' . $e->getMessage());
        $flash = 'Could not save that.';
    }
}

$suppliers = $ready ? $svc->all() : [];
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ops-page">
  <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1">Supplier contacts</h1>
      <p class="text-muted mb-2">Quote requests go to the address marked ✓. Addresses come from mail they sent us and from their own website; nothing here sends anything.</p>
    </div>
    <?php if ($ready && $canEdit): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>">
      <input type="hidden" name="do" value="websites">
      <button class="btn btn-outline-primary">Look on their websites</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($flash !== ''): ?><div class="alert alert-info"><?= h($flash) ?></div><?php endif; ?>
  <?php if (!$ready): ?><div class="alert alert-warning">Supplier contacts are not available yet.</div><?php endif; ?>

  <?php foreach ($suppliers as $supplier): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start">
        <div>
          <h2 class="h5 mb-1"><?= h($supplier['name']) ?> <span class="badge badge-secondary"><?= h($supplier['status']) ?></span></h2>
          <p class="text-muted mb-2">
            <?php if (!empty($supplier['website'])): ?><a href="<?= h(preg_match('#^https?://#i', $supplier['website']) ? $supplier['website'] : 'https://' . $supplier['website']) ?>" target="_blank" rel="noopener"><?= h($supplier['website']) ?></a> · <?php endif; ?>
            Current account address: <?= h((string)($supplier['email'] ?: 'none')) ?>
          </p>
        </div>
      </div>

      <?php if ($supplier['contacts']): ?>
      <div class="table-responsive">
        <table class="table table-sm mb-2">
          <thead><tr><th>Address</th><th>Person</th><th>Role</th><th>Found</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($supplier['contacts'] as $contact): ?>
            <tr>
              <td><strong><?= h($contact['email']) ?></strong><?php if (!empty($contact['is_default'])): ?><div class="text-success">✓ quote requests go here</div><?php endif; ?></td>
              <td><?= h((string)$contact['person']) ?></td>
              <td><span class="badge badge-light"><?= h($contact['role']) ?></span></td>
              <td>
                <?php if (in_array('mail', explode('|', $contact['sources']), true)): ?><span class="badge badge-info">from their mail<?= $contact['mail_ref'] ? ' ' . h($contact['mail_ref']) : '' ?></span><?php endif; ?>
                <?php if (in_array('website', explode('|', $contact['sources']), true)): ?><?php if ($contact['web_ref']): ?><a class="badge badge-info" href="<?= h($contact['web_ref']) ?>" target="_blank" rel="noopener">on their website</a><?php else: ?><span class="badge badge-info">on their website</span><?php endif; ?><?php endif; ?>
                <?php if (in_array('manual', explode('|', $contact['sources']), true)): ?><span class="badge badge-secondary">added by hand</span><?php endif; ?>
              </td>
              <td><?= h($contact['status']) ?></td>
              <td class="text-nowrap">
                <?php if ($canEdit && in_array($contact['status'], ['suggested', 'dropped'], true)): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>"><input type="hidden" name="do" value="keep"><input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>"><button class="btn btn-sm btn-success">Write to this one</button></form><?php endif; ?>
                <?php if ($canEdit && in_array($contact['status'], ['suggested', 'kept'], true)): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>"><input type="hidden" name="do" value="drop"><input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Not this one</button></form><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><p class="text-muted">No address found yet — phone only.</p><?php endif; ?>

      <?php if ($canEdit): ?>
      <form method="post" class="form-inline mt-2">
        <input type="hidden" name="csrf_token" value="<?= h(generateCSRFToken()) ?>"><input type="hidden" name="do" value="add"><input type="hidden" name="supplier_id" value="<?= (int)$supplier['id'] ?>">
        <label class="sr-only" for="email-<?= (int)$supplier['id'] ?>">Address</label>
        <input id="email-<?= (int)$supplier['id'] ?>" type="email" name="email" class="form-control form-control-sm mr-2" required placeholder="address@supplier.example">
        <button class="btn btn-sm btn-outline-primary">Add address</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
