<?php
/**
 * Yui's job strip — her decision queue (a statement to send, a "who is this?", a reply to write) across the top of
 * her pages in the Ittai desktop shell. Included from appstack_head.php after Penny's strip, only when $itShell is on.
 *
 * Nothing is decided here: /crm/api/yui.php?mode=jobs (YuiJobAdapter) supplies the cards and /crm/js/ittai-job-yui.js
 * posts the same requests the web Yui card does. Shown only on Yui's pages, only to the owner. The strip stays hidden
 * until the script has cards, and any failure here renders nothing — it must never break the page it sits on.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__iyUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__iyHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__iyUser, (string)($activePage ?? '')) as $__iyB) {
        if (($__iyB['slug'] ?? '') === 'yui' && !empty($__iyB['here'])) { $__iyHere = true; break; }
    }
    if (!$__iyHere) return;
    // The Messages item carries no permission key; yui.php itself asks for billing.edit, so the strip does too.
    if (function_exists('userHasPermission') && !userHasPermission('billing.edit')) return;
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__iyDesk = new CharlieDeskService(getDB());
    if (!$__iyDesk->ready() || !$__iyDesk->isOwner($__iyUser)) return;
} catch (Throwable $__e) {
    error_log('Yui job strip unavailable: ' . $__e->getMessage());
    return;
}

require_once __DIR__ . '/ittai-icons.php';
$__iySvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
// Local-only fixture so the strip can be drawn without live data (never on production).
$__iyDemo = false;
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {   // the Host header is the client's; the address is not
    $__iyDemo = [
        ['head' => 'yui', 'kind' => 'statement', 'key' => 'demo-1', 'title' => 'Send Client A their statement', 'why' => 'Two invoices are more than 60 days overdue.',
         'left' => ['name' => 'Client A', 'subject' => 'Statement of account', 'text' => 'Account balance $450.00, oldest invoice 72 days.', 'at' => 'Today'],
         'right' => ['account' => 'Client A', 'label' => 'Statement', 'closing' => 'Thank you', 'recipients' => [['email' => 'a@example.com', 'name' => 'Pat Example', 'on_file' => true], ['email' => 'b@example.com', 'name' => '', 'on_file' => false]], 'recipient' => 'a@example.com', 'subject' => 'Your statement', 'body' => 'Hello Pat, your statement is attached. Please let us know if anything looks off.'],
         'fields' => ['recipient', 'subject', 'body'],
         'primary' => ['label' => 'Send', 'sub' => 'to Pat Example', 'request' => ['mode' => 'demo_send']],
         'secondary' => ['label' => 'Not now', 'sub' => 'leave it', 'request' => ['mode' => 'demo_skip']],
         'api' => '/crm/api/yui.php', 'index' => 1, 'total' => 3],
        ['head' => 'yui', 'kind' => 'who', 'key' => 'demo-2', 'title' => 'Who is Pat Example?', 'why' => 'Wrote to office@ and is not on file.',
         'left' => ['name' => 'Pat Example', 'subject' => 'Gate code', 'text' => 'Hi, the gate code changed at our building.', 'at' => 'Yesterday', 'addr' => 'pat@example.com', 'more' => ['Mentions: Building B']],
         'right' => ['first_name' => 'Pat', 'last_name' => 'Example', 'candidates' => [['client_id' => 1, 'name' => 'Client A'], ['client_id' => 2, 'name' => 'Client B']], 'client_id' => ''],
         'fields' => ['first_name', 'last_name', 'client_id'],
         'primary' => ['label' => 'Confirm', 'sub' => 'add to account', 'request' => ['mode' => 'demo_who']],
         'secondary' => ['label' => 'Not a client', 'sub' => 'ignore sender', 'request' => ['mode' => 'demo_not']],
         'undo' => ['request' => ['mode' => 'demo_undo']], 'search' => ['mode' => 'who_accounts'],
         'api' => '/crm/api/yui.php', 'index' => 2, 'total' => 3],
        ['head' => 'yui', 'kind' => 'reply', 'key' => 'demo-3', 'title' => 'Reply to Client B', 'why' => 'Asked about the next visit two days ago.',
         'left' => ['name' => 'Client B', 'subject' => 'Next visit?', 'text' => 'When are you next coming by?', 'at' => '2 days ago'],
         'right' => ['to' => 'b@example.com', 'subject' => 'Re: Next visit?', 'body' => '', 'can_draft' => true],
         'fields' => ['subject', 'body'],
         'primary' => ['label' => 'Send reply', 'sub' => 'to Client B', 'request' => ['mode' => 'demo_reply']],
         'secondary' => ['label' => 'Handled', 'sub' => 'no reply needed', 'request' => ['mode' => 'demo_handled']],
         'draft' => ['mode' => 'draft', 'request' => ['mode' => 'draft', 'key' => 'demo-3']],
         'api' => '/crm/api/yui.php', 'index' => 3, 'total' => 3],
    ];
}
$__iyFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job it-job-yui" data-head="yui" data-api="/crm/api/yui.php" hidden aria-label="Yui's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="/crm/messages_appstack.php"><span class="it-ad it-ad-fill"><?= $__iySvg('mail') ?></span><span>Inbox</span></a>
    <span class="it-job-conf" role="img" aria-label="Yui proposes; your decision sends"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><span class="it-job-subj"></span><p class="it-job-quote"></p><p class="it-job-addr"></p><ul class="it-job-more"></ul><p class="it-job-why"></p></div></div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__iySvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__iySvg('mail') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__iySvg('check') ?><b></b><span></span></button></div>
    </div>
    <a class="it-job-anchor" href="/crm/messages_appstack.php"><span class="it-ad"><?= $__iySvg('mail') ?></span><span>Messages</span></a>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Skip for now</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span> <button type="button" class="it-job-undo" hidden>Undo</button></p>
  <template class="it-job-tpl-statement">
    <label>To <select name="recipient" class="it-ctl"></select></label>
    <p class="it-job-warn" hidden><?= $__iySvg('alert') ?> Not on file for this account. Check before sending</p>
    <label>Subject <input name="subject" class="it-ctl"></label>
    <label>Message <textarea name="body" rows="3" class="it-ctl"></textarea></label>
  </template>
  <template class="it-job-tpl-who">
    <label>First name <input name="first_name" class="it-ctl"></label>
    <label>Last name <input name="last_name" class="it-ctl"></label>
    <label>Account <select name="client_id" class="it-ctl"></select></label>
    <label>Another account… <input name="q" type="search" autocomplete="off" class="it-ctl"></label>
    <ul class="it-job-found" role="listbox" aria-label="Matching accounts"></ul>
  </template>
  <template class="it-job-tpl-reply">
    <label>To <input name="to" readonly class="it-ctl"></label>
    <label>Subject <input name="subject" class="it-ctl"></label>
    <label>Message <textarea name="body" rows="3" class="it-ctl"></textarea></label>
    <button type="button" class="it-job-draft">Draft with Claude</button>
  </template>
<?php if ($__iyDemo): ?>
  <script type="application/json" class="it-job-demo"><?= json_encode($__iyDemo, $__iyFlags) ?></script>
<?php endif; ?>
</section>
<figure class="it-job-viz it-job-viz-yui" hidden></figure>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-yui.js') : '/crm/js/ittai-job-yui.js' ?>" defer></script>
