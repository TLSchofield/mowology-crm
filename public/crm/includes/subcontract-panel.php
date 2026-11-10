<?php
/**
 * Subcontract card on the visit page: send this visit to another company, or follow one already sent.
 * Expects $visitId and $db. Shows nothing until the tables exist on this business.
 */
require_once APP_ROOT . '/Modules/Subcontract/Services/SubcontractService.php';

$__sub = null;
try {
    $__service = new SubcontractService($db);
    $__active = $__service->activeForVisit($visitId);
    $__sub = [
        'job' => $__active,
        'companies' => $__service->listCompanies(),
        'timeline' => $__active ? $__service->timeline((int)$__active['id']) : [],
        'files' => $__active ? $__service->files((int)$__active['id']) : [],
        'counts' => $__service->marketingCounts(),
    ];
} catch (Throwable $__e) {
    error_log('subcontract-panel: ' . $__e->getMessage());
}
if ($__sub === null) return;

$__eventWords = ['sent' => 'Link created', 'opened' => 'Opened the link', 'accepted' => 'Took the job', 'declined' => 'Said no', 'scheduled' => 'Day arranged',
    'on_way' => 'On the way', 'started' => 'Started', 'finished' => 'Finished', 'undo' => 'Took back a step', 'work_accepted' => 'You accepted the work',
    'bill_sent' => 'Sent a bill', 'bill_approved' => 'Bill approved', 'bill_returned' => 'Bill sent back', 'paid' => 'Marked paid', 'cancelled' => 'Cancelled', 'new_link' => 'New link created',
    'ittai_interest' => 'Asked about Ittai'];
$__j = $__sub['job'];
$__minutes = $__j ? SubcontractRules::minutesOnJob($__j['started_at'] ?? null, $__j['finished_at'] ?? null) : null;
?>
<div class="card mw-card mb-4" id="mw-sub" data-visit="<?= (int)$visitId ?>" data-job="<?= $__j ? (int)$__j['id'] : 0 ?>">
  <div class="card-header"><h5 class="card-title mb-0"><i data-feather="users" class="mr-2"></i>Subcontract</h5></div>
  <div class="card-body">
    <div class="alert alert-danger" id="mw-sub-error" role="alert" hidden></div>

    <?php if (!$__j): ?>
    <form id="mw-sub-form" novalidate>
      <div class="form-group">
        <label for="mw-sub-company">Company</label>
        <select class="form-control" id="mw-sub-company">
          <?php foreach ($__sub['companies'] as $__c): ?><option value="<?= (int)$__c['id'] ?>" data-email="<?= h($__c['email'] ?? '') ?>"><?= h($__c['company_name']) ?></option><?php endforeach; ?>
          <option value="0"<?= $__sub['companies'] ? '' : ' selected' ?>>Add a company…</option>
        </select>
      </div>
      <div id="mw-sub-new"<?= $__sub['companies'] ? ' hidden' : '' ?>>
        <div class="form-group"><label for="mw-sub-name">Company name</label><input class="form-control" id="mw-sub-name"></div>
        <div class="form-group"><label for="mw-sub-contact">Contact person</label><input class="form-control" id="mw-sub-contact"></div>
        <div class="form-group"><label for="mw-sub-email">Email</label><input class="form-control" type="email" id="mw-sub-email"></div>
        <div class="form-group"><label for="mw-sub-phone">Phone</label><input class="form-control" type="tel" id="mw-sub-phone"></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="mw-sub-ittai"><label class="form-check-label" for="mw-sub-ittai">Already on Ittai (no Ittai offer on their page)</label></div>
      </div>
      <div class="form-group"><label for="mw-sub-amount">What you'll pay, before GST</label><input class="form-control" inputmode="decimal" id="mw-sub-amount"></div>
      <div class="form-group"><label for="mw-sub-note">Note for them</label><textarea class="form-control" rows="2" id="mw-sub-note"></textarea></div>
      <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="mw-sub-share-name" checked><label class="form-check-label" for="mw-sub-share-name">Show the client's name</label></div>
      <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="mw-sub-share-phone" checked><label class="form-check-label" for="mw-sub-share-phone">Show the client's phone, once they take the job</label></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="mw-sub-done"><label class="form-check-label" for="mw-sub-done">Already done: just ask for proof of work and the bill</label></div>
      <p class="small text-muted">They always see the address and the work. They never see your price, billing, the client's email or other jobs.</p>
      <button type="submit" class="btn btn-primary btn-block">Create the link</button>
    </form>
    <?php else: ?>
      <p class="mb-1"><strong><?= h($__j['company_name']) ?></strong><?= !empty($__j['contact_name']) ? ' · ' . h($__j['contact_name']) : '' ?></p>
      <?php if ($__j['agreed_amount'] !== null): ?><p class="small text-muted mb-2">Agreed, before GST: $<?= h(number_format((float)$__j['agreed_amount'], 2)) ?></p><?php endif; ?>

      <?php if ($__j['work_status'] === 'finished' && empty($__j['work_accepted_at'])): ?>
        <button type="button" class="btn btn-primary btn-block mb-2" data-sub-action="accept">Accept the work</button>
        <p class="small text-muted">This completes the visit. Any client follow-up goes out only after you accept.</p>
      <?php endif; ?>
      <?php if (!empty($__j['work_accepted_at']) && $__j['bill_status'] === 'approved'): ?>
        <button type="button" class="btn btn-primary btn-block mb-2" data-sub-action="mark_paid">Mark paid</button>
      <?php endif; ?>
      <?php if ($__j['bill_status'] === 'sent'): ?>
        <p class="small mb-2">Bill: $<?= h(number_format((float)$__j['bill_amount'] + (float)$__j['bill_gst'], 2)) ?> incl. GST, waiting in Penny's review<?= !empty($__j['bill_invoice_no']) ? ' (their invoice ' . h($__j['bill_invoice_no']) . ')' : '' ?>.</p>
      <?php endif; ?>

      <?php if ($__sub['files']): ?>
      <div class="mw-sub-files mb-3">
        <?php foreach ($__sub['files'] as $__f): $__url = '/crm/api/subcontract.php?mode=file&id=' . (int)$__f['id']; ?>
          <?php if (strpos($__f['mime_type'], 'image/') === 0 && strpos($__f['mime_type'], 'hei') === false): ?>
            <a href="<?= h($__url) ?>" target="_blank" rel="noopener"><img src="<?= h($__url) ?>" alt="<?= $__f['kind'] === 'invoice' ? 'Their invoice' : 'Photo from the subcontractor' ?>" loading="lazy"></a>
          <?php else: ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= h($__url) ?>" target="_blank" rel="noopener"><?= $__f['kind'] === 'invoice' ? 'Their invoice' : 'Open photo' ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php elseif ($__j['work_status'] !== 'finished'): ?>
        <p class="small text-muted">No proof of work yet.</p>
      <?php endif; ?>

      <ul class="list-unstyled small mb-3">
        <?php foreach ($__sub['timeline'] as $__ev): if (!isset($__eventWords[$__ev['event_type']])) continue; ?>
          <li class="d-flex justify-content-between mw-sub-event">
            <span><?= h($__eventWords[$__ev['event_type']]) ?><?= (string)$__ev['on_site'] === '1' ? ', at the property' : ((string)$__ev['on_site'] === '0' ? ', not at the property' : '') ?><?= !empty($__ev['note']) ? ': ' . h($__ev['note']) : '' ?></span>
            <span class="text-muted text-nowrap ml-2"><?= h(date('D M j, g:i A', strtotime($__ev['created_at']))) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if ($__minutes !== null && empty($__j['after_the_fact'])): ?><li><strong><?= (int)floor($__minutes / 60) ?> h <?= (int)($__minutes % 60) ?> min on the job</strong></li><?php endif; ?>
      </ul>

      <div class="d-flex">
        <button type="button" class="btn btn-outline-secondary btn-sm mr-2" data-sub-action="new_link">Send a new link</button>
        <button type="button" class="btn btn-outline-danger btn-sm" data-sub-action="cancel">Cancel this job</button>
      </div>
    <?php endif; ?>

    <div id="mw-sub-share" class="mt-3" hidden>
      <label for="mw-sub-link">Their private link</label>
      <input class="form-control mb-2" id="mw-sub-link" readonly>
      <div class="d-flex flex-wrap">
        <button type="button" class="btn btn-primary btn-sm mr-2 mb-2" id="mw-sub-share-btn">Share</button>
        <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-2" id="mw-sub-copy">Copy</button>
      </div>
      <div class="input-group input-group-sm">
        <input class="form-control" type="email" id="mw-sub-send-to" placeholder="Their email" value="<?= h($__j['company_email'] ?? '') ?>" aria-label="Their email">
        <div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="mw-sub-email-btn">Email it</button></div>
      </div>
      <p class="small text-muted mt-2 mb-0" id="mw-sub-share-status" role="status">This link is shown once. Send it now.</p>
    </div>

    <p class="small text-muted mt-3 mb-0">So far: <?= (int)$__sub['counts']['invited'] ?> sent, <?= (int)$__sub['counts']['opened'] ?> opened, <?= (int)$__sub['counts']['asked'] ?> asked about Ittai.</p>
  </div>
</div>
<script src="/crm/js/subcontract-panel.js?v=1" defer></script>
