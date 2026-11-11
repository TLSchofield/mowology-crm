<?php
/**
 * Mia's job strip — her decisions on the Social Posts page (reconnect, a failed post and why, a post to approve, a
 * draft to schedule), across the top of the page in the Ittai desktop shell. Included from appstack_head.php after
 * Yui's strip, only when $itShell is on.
 *
 * Nothing is decided here: MiaJobAdapter reads the posts the page already lists and shapes each decision; each button
 * carries the request the page's own button sends (posts.php approve / retry / schedule) or a link (editor, Facebook
 * reconnect), and /crm/js/ittai-job-mia.js sends it. Shown only on the Social Posts page, only to the owner, only with
 * marketing.approve (Schedule also needs marketing.edit). Any failure renders nothing — it must never break the page.
 */
if (empty($itShell)) return;
if (!defined('APP_ROOT')) return;
if ((string)($activePage ?? '') !== 'social') return;
try {
    require_once __DIR__ . '/nav-items.php';
    $__imUser = (array)($user ?? (function_exists('getCurrentUser') ? getCurrentUser() : []) ?? []);
    $__imHere = false;
    foreach (mwIttaiNavBlocks((array)($navItems ?? []), $__imUser, (string)($activePage ?? '')) as $__imB) {
        if (($__imB['slug'] ?? '') === 'mia' && !empty($__imB['here'])) { $__imHere = true; break; }
    }
    if (!$__imHere) return;
    // approve / retry / the reconnect link all ask for marketing.approve; Schedule asks for marketing.edit.
    if (function_exists('userHasPermission') && !userHasPermission('marketing.approve')) return;
    require_once APP_ROOT . '/Services/HeadIdentity.php';
    require_once APP_ROOT . '/Modules/ChiefOfStaff/Services/CharlieDeskService.php';
    $__imDb = getDB();
    $__imDesk = new CharlieDeskService($__imDb);
    if (!$__imDesk->ready() || !$__imDesk->isOwner($__imUser)) return;
    require_once APP_ROOT . '/Modules/Marketing/Services/MiaJobAdapter.php';
    $__imVizData = MiaJobAdapter::viz($__imDb);
    $__imJobs = MiaJobAdapter::jobs($__imDb, $__imUser, !function_exists('userHasPermission') || userHasPermission('marketing.edit'));
} catch (Throwable $__e) {
    error_log('Mia job strip unavailable: ' . $__e->getMessage());
    return;
}
// Local-only fixture so the strip can be drawn without live data (never on production).
if (($_GET['it_job_demo'] ?? '') === '1'
    && in_array(strtok((string)($_SERVER['HTTP_HOST'] ?? ''), ':'), ['127.0.0.1', 'localhost'], true)
    && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {   // the Host header is the client's; the address is not
    $__imJobs = MiaJobAdapter::queue(
        ['needs_reconnect' => false, 'message' => '', 'action_url' => SocialConnectionState::RECONNECT_URL],
        [['id' => 1, 'caption' => 'Crew on-site. Work getting done. Strata and commercial grounds maintenance across Vancouver.', 'platforms' => 'facebook,instagram', 'thumb_path' => '',
          'detail' => 'Meta API error [Instagram media container creation] (code 36003): The aspect ratio is not supported.']],
        [['id' => 2, 'caption' => 'We talk a lot about lawns. Clean edges. Fresh cuts. Consistent service.', 'platforms' => 'facebook,instagram', 'scheduled_at' => null, 'auto_generated' => 1, 'created_by_name' => 'Mia', 'thumb_path' => '']],
        [['id' => 3, 'caption' => 'Fall cleanup is booking now.', 'platforms' => 'facebook', 'auto_generated' => 1, 'thumb_path' => '']],
        ['crew' => [5, 5], 'hand' => [0, 0]], true, new DateTimeImmutable('now'));
    $__imVizData = MiaJobAdapter::shapeViz(['2026-11' => 1, '2026-12' => 2, '2027-01' => 1],
        [['caption' => 'We talk a lot about lawns. Clean edges.'], ['caption' => 'Mowing season starts'], ['caption' => 'Aeration time'], ['caption' => 'Spring cleanup booking']], new DateTimeImmutable('now'));
}
if (!$__imJobs) return;
$__miaStripShown = true;   // the page's own red reconnect banner stays quiet while the strip asks

require_once __DIR__ . '/ittai-icons.php';
$__imFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$__imSvg = static function (string $id): string {
    return '<svg class="it-job-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="#it-w-' . $id . '"/></svg>';
};
// The chart the line runs on into: ready posts and posts held for their season, by month (same card as Penny's graph).
$__imViz = static function (?array $v) use ($__imSvg): string {
    if (!$v) return '';
    $w = 288; $h = 120; $pad = 8; $n = count($v['months']); $slot = (int)floor(($w - $pad * 2) / $n); $bw = max(4, (int)floor($slot / 2) - 2);
    $bars = '';
    foreach ($v['months'] as $i => $m) {
        $x = $pad + $i * $slot;
        foreach ([['ready', 'it-viz-now'], ['held', 'it-viz-bar']] as $j => [$k, $cls]) {
            $bh = (int)round(($m[$k] / $v['max']) * 80);
            if ($bh < 1 && $m[$k] > 0) $bh = 1;
            if ($m[$k] > 0) $bars .= '<rect x="' . ($x + $j * ($bw + 2)) . '" y="' . (96 - $bh) . '" width="' . $bw . '" height="' . max($bh, 2) . '" rx="3" class="' . $cls . '"><title>' . h($m['label'] . ': ' . $m[$k] . ($k === 'held' ? ' held for their season' : ' ready to go out')) . '</title></rect>';
        }
        $bars .= '<text x="' . ($x + $bw) . '" y="112" text-anchor="middle" class="it-viz-lbl' . ($m['now'] ? ' it-viz-lbl-now' : '') . '">' . h(substr($m['label'], 0, 1)) . '</text>';
    }
    $next = $v['next'] ? ' <span>Next season opens ' . h($v['next']['label']) . ': ' . (int)$v['next']['n'] . ' post' . ($v['next']['n'] === 1 ? '' : 's') . '</span>' : '';
    return '<figure class="it-job-viz it-job-viz-mia"><span class="it-ad it-ad-sm it-job-viz-node" aria-hidden="true">' . $__imSvg('chart') . '</span><figcaption><b>Posts by month</b><span>' . (int)$v['ready'] . ' ready · ' . (int)$v['held'] . ' held</span></figcaption>'
        . '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="Posts ready to go out and posts held for their season, next twelve months">' . $bars . '</svg>'
        . '<p class="it-job-viz-gst"><b>' . (int)$v['held'] . '</b> held for their season' . $next . '</p></figure>';
};
itIconSprite();
?>
<div class="it-job-outer"><div class="it-job-wrap">
<section class="it-job it-job-mia" data-head="mia" aria-label="Mia's job">
  <h2 class="it-vh it-job-tt"></h2>
  <div class="it-job-body"><div class="it-job-rail">
    <a class="it-job-anchor it-job-anchor-start" href="/crm/marketing/social.php"><span class="it-ad it-ad-fill"><?= $__imSvg('eye') ?></span><span>Posts</span></a>
    <span class="it-job-conf is-forming" role="img"><svg class="it-si" width="24" height="24" viewBox="0 0 16 16" aria-hidden="true"><use href="#it-st-approval-forming"/></svg></span>
    <div class="it-job-split">
      <div class="it-job-col it-job-left"><div class="it-job-what"><b class="it-job-who"></b><span class="it-job-at"></span><p class="it-job-quote"></p><p class="it-job-why"></p></div></div>
      <div class="it-job-col it-job-fig-col"><figure class="it-job-fig" hidden><img alt="" src=""></figure></div>
      <span class="it-job-disc it-job-disc-info" role="img" aria-label="becomes"><?= $__imSvg('arrow') ?></span>
      <div class="it-job-col it-job-right"><form class="it-job-form" novalidate></form></div>
      <div class="it-job-col it-job-acts"><button type="button" class="it-btn it-btn-fill" data-role="primary"><?= $__imSvg('check') ?><b></b><span></span></button><span class="it-job-m"></span><button type="button" class="it-btn it-btn-line" data-role="secondary"><?= $__imSvg('arrow') ?><b></b><span></span></button></div>
    </div>
    <a class="it-job-anchor it-job-anchor-end" href="/crm/marketing/social-calendar.php"><span class="it-ad"><?= $__imSvg('chart') ?></span><span>Calendar</span></a>
  </div></div>
  <p class="it-job-foot"><span class="it-job-n"></span> · <button type="button" class="it-job-skip">Skip for now</button></p>
  <p class="it-job-msg" role="status" aria-live="polite" hidden><span class="it-job-msgt"></span></p>
  <template class="it-job-tpl-schedule">
    <label>When it goes out <input name="scheduled_at" type="datetime-local" class="it-ctl"></label>
  </template>
  <script type="application/json" class="it-job-data"><?= json_encode($__imJobs, $__imFlags) ?></script>
</section>
<?= $__imViz($__imVizData ?? null) ?>
</div></div>
<script src="<?= function_exists('_av') ? _av('/crm/js/ittai-job-mia.js') : '/crm/js/ittai-job-mia.js' ?>" defer></script>
