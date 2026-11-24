<?php
/**
 * Tidy iCloud — Yui's filing assistant for Tim's mowology@icloud.com.
 *
 * Shows the proposed folder plan, then a PREVIEW of where every message in INBOX / Archive
 * would go (counts + a few sample subjects, never bodies) and the Junk rescue list (real work
 * mail caught as spam, each with a checkbox). Nothing moves until Tim clicks Apply; every
 * Apply is a batch that Undo can reverse. "Keep it tidy" (cron) is off by default.
 *
 * Thin controller: the page renders the plan; icloud-tidy.js drives /crm/api/icloud-tidy.php
 * (MailTidyService). Admin only.
 */
declare(strict_types=1);
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
if (!isAdmin()) {
    http_response_code(403);
    exit('Admin only');
}

require_once APP_ROOT . '/Services/Mail/MailboxConfig.php';
require_once APP_ROOT . '/Modules/Comms/Services/MailTidyService.php';

$tidy = new MailTidyService(getDB());
$ready = $tidy->ready();
$plan = $tidy->plan();
$configured = MailboxConfig::icloud() !== null;

$pageTitle = 'Tidy iCloud';
$activePage = 'settings';
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-tidy" id="mw-tidy" data-ready="<?= $ready ? '1' : '0' ?>" data-configured="<?= $configured ? '1' : '0' ?>"
     data-keep="<?= h($plan['settings']['keep_tidy'] ?? '0') ?>">

  <div class="mw-tidy-head">
    <div>
      <h1 class="h3 mb-1">Tidy iCloud</h1>
      <p class="mw-tidy-sub">Yui files <strong><?= h($configured ? (string)MailboxConfig::icloud()['user'] : 'mowology@icloud.com') ?></strong> into the folders below and checks Junk for real work mail.
        A preview never changes anything. Mail only moves when you click Apply — never deleted, never marked read — and every batch can be undone.</p>
    </div>
    <label class="mw-tidy-keep" title="Every 15 minutes, file new INBOX mail older than <?= (int)($plan['settings']['keep_tidy_after_days'] ?? 7) ?> days by the same rules">
      <input type="checkbox" id="mw-tidy-keep" <?= ($plan['settings']['keep_tidy'] ?? '0') === '1' ? 'checked' : '' ?> <?= $ready ? '' : 'disabled' ?>>
      <span>Keep it tidy</span>
    </label>
  </div>

  <?php if (!$ready): ?>
    <div class="alert alert-warning">Migration 1226 has not run yet — run it from Database before using this page.</div>
  <?php elseif (!$configured): ?>
    <div class="alert alert-warning">iCloud is not configured — ICLOUD_IMAP_USER / ICLOUD_IMAP_PASS are not set in secrets.php.</div>
  <?php endif; ?>

  <section class="card mw-tidy-card">
    <div class="card-body">
      <h2 class="mw-tidy-h">The folder plan</h2>
      <p class="mw-tidy-note">Your existing folders keep their names. New folders are only created when the first message moves into them.
        INBOX keeps the last <?= (int)($plan['settings']['inbox_keep_days'] ?? 30) ?> days and anything Yui is unsure about.</p>
      <div class="mw-tidy-plan">
        <?php foreach ($plan['folders'] as $key => $f): ?>
          <div class="mw-tidy-folder" data-folder="<?= h($f['imap']) ?>">
            <div class="mw-tidy-folder-name"><?= h($f['label']) ?>
              <span class="mw-tidy-tag <?= $f['existing'] ? 'is-existing' : 'is-new' ?>"><?= $f['existing'] ? 'existing' : 'new' ?></span></div>
            <div class="mw-tidy-folder-note"><?= h($f['note']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mw-tidy-note mb-0">Never touched: Sent Messages, Drafts, Notes, Deleted Items. Junk is only checked — real spam stays there.</p>
    </div>
  </section>

  <section class="card mw-tidy-card">
    <div class="card-body">
      <div class="mw-tidy-row">
        <h2 class="mw-tidy-h mb-0">Preview</h2>
        <button type="button" class="btn btn-success" id="mw-tidy-preview" <?= $ready && $configured ? '' : 'disabled' ?>>Run a preview</button>
      </div>
      <div class="mw-tidy-progress" id="mw-tidy-progress" hidden>
        <div class="mw-tidy-bar"><i id="mw-tidy-bar"></i></div>
        <div class="mw-tidy-progress-text" id="mw-tidy-progress-text"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="mw-tidy-stop">Stop</button>
      </div>
      <div id="mw-tidy-summary" aria-live="polite"><div class="mw-tidy-empty">No preview yet.</div></div>
    </div>
  </section>

  <section class="card mw-tidy-card">
    <div class="card-body">
      <div class="mw-tidy-row">
        <h2 class="mw-tidy-h mb-0">Junk check</h2>
        <button type="button" class="btn btn-outline-success" id="mw-tidy-rescue" disabled>Move ticked to INBOX</button>
      </div>
      <p class="mw-tidy-note">Mail in Junk from a client, supplier, payment sender, bank, CRA, insurer or payroll — or a real work enquiry. Ticked ones move back to INBOX.
        Senders that failed authentication (spoofed) and subject-only matches stay in Junk.</p>
      <div id="mw-tidy-junk"><div class="mw-tidy-empty">Run a preview to check Junk.</div></div>
    </div>
  </section>

  <section class="card mw-tidy-card">
    <div class="card-body">
      <h2 class="mw-tidy-h">Batches</h2>
      <div id="mw-tidy-batches"><div class="mw-tidy-empty">Nothing moved yet.</div></div>
    </div>
  </section>
</div>

<script src="/crm/js/icloud-tidy.js?v=<?= (int)@filemtime(__DIR__ . '/js/icloud-tidy.js') ?>"></script>

<?php include 'includes/appstack_footer.php'; ?>
