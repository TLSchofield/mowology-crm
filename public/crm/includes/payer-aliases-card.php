<?php
/**
 * Penny — "Also pays as": the wordings the bank, Interac or a client use for this payer
 * (payer_aliases, migration 1362, PayerIdentityService). Penny's bank card reads them to say who a
 * deposit is from. Adding one is the owner's word (ranks above anything Penny learned); removing
 * one keeps it removed so she never learns it back.
 *
 * Included by clients_appstack.php (contact and company views). Expects $payerAliasName (string,
 * the payer as invoices name them) and optionally $payerAliasContactId / $payerAliasCompanyId.
 * Renders nothing without billing.view, before 1362, or on any error — never breaks the host page.
 * Script: /crm/js/payer-aliases.js → /crm/api/payer-aliases.php.
 */
if (!function_exists('userHasPermission') || !userHasPermission('billing.view') || empty($payerAliasName)) {
    return;
}
try {
    require_once APP_ROOT . '/Modules/Accounting/Services/PayerIdentityService.php';
    $__pa = new PayerIdentityService(getDB());
    if (!$__pa->ready()) return;
    $__paRows = $__pa->aliasesFor((string)$payerAliasName, !empty($payerAliasContactId) ? (int)$payerAliasContactId : null,
                                  !empty($payerAliasCompanyId) ? (int)$payerAliasCompanyId : null);
    $__paEdit = userHasPermission('billing.edit');
} catch (Throwable $__e) {
    error_log('Payer aliases card unavailable: ' . $__e->getMessage());
    return;
}
$__paKinds = ['bank_memo' => 'Bank memo words', 'etransfer_sender' => 'e-Transfer sender', 'sms_name' => 'Interac alert name',
              'email' => 'Email', 'phone' => 'Phone'];
?>
<div class="card mb-3 mw-payer-aliases" data-payer="<?php echo h((string)$payerAliasName); ?>"
     data-contact-id="<?php echo (int)($payerAliasContactId ?? 0); ?>" data-company-id="<?php echo (int)($payerAliasCompanyId ?? 0); ?>">
  <div class="card-header">
    <h5 class="card-title mb-0"><i data-feather="credit-card"></i> Also pays as</h5>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-2">How the bank or Interac names <?php echo h((string)$payerAliasName); ?>. Penny uses these to say who a deposit is from.</p>
    <ul class="list-unstyled mb-2 mw-payer-aliases-list">
      <?php if (!$__paRows): ?>
        <li class="text-muted small mw-payer-aliases-empty">None yet.</li>
      <?php endif; ?>
      <?php foreach ($__paRows as $__r): ?>
        <li class="d-flex justify-content-between align-items-center py-1" data-id="<?php echo (int)$__r['id']; ?>">
          <span>
            <strong><?php echo h($__r['alias_raw']); ?></strong>
            <small class="text-muted">· <?php echo h($__paKinds[$__r['kind']] ?? $__r['kind']); ?>
              · <?php echo $__r['source'] === 'tim' ? 'you said so' : ('seen ' . (int)$__r['times'] . ' time' . ((int)$__r['times'] === 1 ? '' : 's')); ?></small>
          </span>
          <?php if ($__paEdit): ?>
            <button type="button" class="btn btn-sm btn-link text-muted mw-payer-alias-remove" aria-label="Remove <?php echo h($__r['alias_raw']); ?>">Remove</button>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($__paEdit): ?>
      <form class="form-inline mw-payer-alias-add" autocomplete="off">
        <select class="form-control form-control-sm mr-2 mb-1" name="kind" aria-label="Kind of wording">
          <?php foreach ($__paKinds as $__k => $__label): ?>
            <option value="<?php echo $__k; ?>"><?php echo h($__label); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" class="form-control form-control-sm mr-2 mb-1" name="alias" maxlength="120" placeholder="e.g. KANCO HOLDINGS" aria-label="Wording">
        <button type="submit" class="btn btn-sm btn-primary mb-1">Add</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script src="/crm/js/payer-aliases.js?v=20261014" defer></script>
