<?php
/** The subcontract job page. Included by sub-job.php with $sub set; opened directly it shows nothing. */
if (!isset($sub) || !is_array($sub)) { http_response_code(404); exit; }

$e = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$digits = static fn (string $p): string => preg_replace('/[^0-9+]/', '', $p) ?? '';
$when = static fn (string $at): string => $at !== '' && strtotime($at) ? date('D M j, g:i A', (int)strtotime($at)) : '';
$day = static fn (string $d): string => $d !== '' && strtotime($d) ? date('l, M j', (int)strtotime($d)) : '';
$page = $sub['page'] ?? null;
$business = (string)$sub['business'];
$step = $page['next_step'] ?? '';

$statusWords = [
    'respond' => 'Waiting for your answer', 'pick_day' => 'You took this job', 'on_my_way' => 'Day arranged', 'start' => 'On your way',
    'finish' => 'Job started', 'send_proof' => $business . ' needs your proof of work', 'send_bill' => 'Finished',
    'bill_waiting' => 'Bill sent', 'payment_waiting' => 'Bill approved', 'done' => 'Paid', 'closed' => 'This job is closed',
];
$primary = ['respond' => ['accept', 'Take this job'], 'pick_day' => ['pick_day', 'Confirm this day'], 'on_my_way' => ['on_my_way', "I'm on my way"],
            'start' => ['start', 'Start the job'], 'finish' => ['finish', 'Finish the job'], 'send_proof' => ['finish', 'Send my proof of work'], 'send_bill' => ['bill', 'Send my bill']][$step] ?? null;
$dateLine = $page ? $day((string)$page['date']) : '';
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= $e($page ? 'Your job from ' . $business : 'Ittai') ?></title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;display=swap">
<link rel="stylesheet" href="/customer/sub-job.css">
</head>
<body>
<?php if ($page): ?><script src="/customer/sub-job-splash.js"></script><?php endif; ?>
<main class="sj" id="sj" data-token="<?= $e($sub['token']) ?>">

  <header class="sj-brand">
    <svg class="sj-symbol" width="32" height="32" viewBox="0 0 64 64" aria-hidden="true"><g fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-dasharray="65.8 34.2" stroke-dashoffset="-17.1"><path pathLength="100" d="M18.96 9.42 A6 6 0 0 1 21.96 8.62 L42.04 8.62 A6 6 0 0 1 45.04 9.42"/><path pathLength="100" d="M45.04 9.42 A6 6 0 0 1 47.23 11.62 L57.27 29 A6 6 0 0 1 58.07 32"/><path pathLength="100" d="M58.07 32 A6 6 0 0 1 57.27 35 L47.23 52.38 A6 6 0 0 1 45.04 54.58"/><path pathLength="100" d="M45.04 54.58 A6 6 0 0 1 42.04 55.38 L21.96 55.38 A6 6 0 0 1 18.96 54.58"/><path pathLength="100" d="M18.96 54.58 A6 6 0 0 1 16.77 52.38 L6.73 35 A6 6 0 0 1 5.93 32"/><path pathLength="100" d="M5.93 32 A6 6 0 0 1 6.73 29 L16.77 11.62 A6 6 0 0 1 18.96 9.42"/></g><circle cx="32" cy="32" r="9" fill="none" stroke="currentColor" stroke-width="4"/></svg>
    <svg class="sj-wordmark" width="73" height="32" viewBox="-1 23 180.2 79" role="img" aria-label="Ittai"><g class="sj-navy"><rect x="0" y="44" width="13" height="56"/><rect x="31" y="28" width="13" height="72"/><rect x="62" y="28" width="13" height="72"/><rect x="134.2" y="44" width="13" height="56"/><rect x="165.2" y="44" width="13" height="56"/></g><circle class="sj-navy-ring" cx="118.6" cy="72" r="22.1"/><g class="sj-gold"><circle cx="6.5" cy="31.5" r="6"/><rect x="25" y="44" width="56" height="11" rx="5.5"/><circle cx="171.7" cy="31.5" r="6"/></g></svg>
    <button type="button" class="sj-link sj-sun" id="sj-sun" aria-pressed="false">Bright sun</button>
  </header>

<?php if (!$page): ?>
  <section class="sj-section">
    <h1 class="sj-title"><?= $e($sub['state'] === 'revoked' ? 'This job was cancelled' : ($sub['state'] === 'expired' ? 'This link has expired' : ($sub['state'] === 'error' ? 'Something went wrong' : 'This link is not valid'))) ?></h1>
    <p class="sj-meta"><?= $e($sub['state'] === 'error' ? 'Please try again in a minute.' : 'Please ask whoever sent it for a new link.') ?> <?= $e($sub['contact']) ?></p>
  </section>
<?php else: ?>

  <section class="sj-head">
    <div class="sj-meta"><?= $e($business) ?> sent you this job</div>
    <h1 class="sj-title"><?= $e($page['title']) ?></h1>
    <div class="sj-status sj-status-<?= in_array($step, ['respond', 'send_proof'], true) ? 'attention' : (in_array($step, ['send_bill', 'payment_waiting', 'done'], true) ? 'complete' : 'progress') ?>" role="status">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6.25"/><path d="M8 4.5V8l2.5 1.5"/></svg>
      <span><?= $e($statusWords[$step] ?? '') ?></span>
    </div>
  </section>

  <section class="sj-section sj-row">
    <div class="sj-grow">
      <?php if ($dateLine !== '' && empty($page['after_the_fact'])): ?><div class="sj-meta"><?= $e($dateLine) ?></div><?php endif; ?>
      <div class="sj-strong"><?= $e($page['address']) ?></div>
    </div>
    <?php if ($page['address'] !== ''): ?>
    <a class="sj-btn" href="https://www.google.com/maps/search/?api=1&amp;query=<?= $e(rawurlencode($page['address'])) ?>" target="_blank" rel="noopener noreferrer">Directions</a>
    <?php endif; ?>
  </section>

  <?php if ($page['client_name'] !== '' || $page['client_phone'] !== ''): ?>
  <section class="sj-section sj-row">
    <div class="sj-grow">
      <div class="sj-meta">Client</div>
      <div class="sj-strong"><?= $e($page['client_name'] ?: 'Your contact') ?></div>
      <?php if ($page['client_phone'] !== ''): ?>
        <div class="sj-meta sj-num"><?= $e($page['client_phone']) ?></div>
      <?php elseif ($step === 'respond'): ?>
        <div class="sj-meta">You'll get their number when you take the job.</div>
      <?php endif; ?>
    </div>
    <?php if ($page['client_phone'] !== ''): ?>
    <div class="sj-pair">
      <a class="sj-btn" href="tel:<?= $e($digits($page['client_phone'])) ?>">Call</a>
      <a class="sj-btn" href="sms:<?= $e($digits($page['client_phone'])) ?>">Text</a>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($page['work_items'] || $page['note'] !== ''): ?>
  <section class="sj-section">
    <h2 class="sj-h2">The work</h2>
    <?php if ($page['work_items']): ?><ul class="sj-list"><?php foreach ($page['work_items'] as $item): ?><li><?= $e($item) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($page['note'] !== ''): ?><p class="sj-meta sj-note">From <?= $e($business) ?>: <?= $e($page['note']) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($page['agreed_amount'] !== null): ?>
  <section class="sj-section sj-row sj-baseline">
    <div class="sj-meta">You'll be paid, before GST</div>
    <div class="sj-strong sj-num">$<?= $e(number_format((float)$page['agreed_amount'], 2)) ?></div>
  </section>
  <?php endif; ?>

  <?php if (!empty($sub['record'])): ?>
  <section class="sj-section">
    <h2 class="sj-h2">Your record</h2>
    <?php foreach ($sub['record'] as $row): ?>
      <div class="sj-record"><span><?= $e($row['word']) ?></span><span class="sj-meta sj-num"><?= $e($when($row['at'])) ?></span></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if (!empty($sub['show_panel'])): ?>
  <section class="sj-section sj-offer">
    <h2 class="sj-h2">This whole job ran from one link.</h2>
    <p class="sj-meta"><?= $e($business) ?> runs its business on Ittai. Your own jobs, crews and bills can work the same way.</p>
    <?php if (!empty($sub['asked'])): ?>
      <p class="sj-thanks" role="status">Thanks. We'll be in touch.</p>
    <?php else: ?>
      <button type="button" class="sj-primary sj-offer-btn" data-action="interested">Run your own jobs this way</button>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <form class="sj-action" id="sj-form" novalidate>
    <?php if ($step === 'pick_day'): ?>
      <?php if ($page['client_phone'] !== ''): ?><p class="sj-meta">Call or text <?= $e($page['client_name'] ?: 'the client') ?> to arrange a day, then confirm it here.</p><?php endif; ?>
      <label class="sj-field"><span>Day you arranged</span><input type="date" name="date" min="<?= $e($today) ?>" value="<?= $e($page['date'] >= $today ? $page['date'] : $today) ?>" required></label>
    <?php endif; ?>

    <?php if ($step === 'on_my_way' || $step === 'start'): ?>
      <p class="sj-meta">If you allow it, your location is saved once when you start and once when you finish. It is never tracked in between.</p>
    <?php endif; ?>

    <?php if ($step === 'send_proof'): ?>
      <p class="sj-meta"><?= $e($business) ?> needs this to pay you.</p>
      <label class="sj-field"><span>Day you did the work</span><input type="date" name="done_on" max="<?= $e($today) ?>" required></label>
    <?php endif; ?>

    <?php if ($step === 'finish' || $step === 'send_proof'): ?>
      <label class="sj-btn sj-file"><span id="sj-photo-word"><?= (int)$sub['photos'] > 0 ? (int)$sub['photos'] . ' added. Add more photos' : 'Add photos' ?></span><input type="file" id="sj-photos" accept="image/*" multiple></label>
      <p class="sj-sync" id="sj-photo-status" role="status" hidden></p>
      <label class="sj-field"><span><?= $step === 'send_proof' ? 'What did you do?' : 'Anything ' . $e($business) . ' should know?' ?></span><textarea name="note" rows="2"></textarea></label>
    <?php endif; ?>

    <?php if ($step === 'send_bill'): ?>
      <?php if ($page['bill_status'] === 'returned'): ?><p class="sj-warn" role="alert"><?= $e($business) ?> sent this bill back. Please check it and send it again.</p><?php endif; ?>
      <div class="sj-two">
        <label class="sj-field"><span>Amount before GST</span><input name="amount" inputmode="decimal" class="sj-num" value="<?= $page['agreed_amount'] !== null ? $e(number_format((float)$page['agreed_amount'], 2, '.', '')) : '' ?>" required></label>
        <label class="sj-field"><span>GST</span><input name="gst" inputmode="decimal" class="sj-num" value="<?= $page['agreed_amount'] !== null ? $e(number_format(round((float)$page['agreed_amount'] * 0.05, 2), 2, '.', '')) : '' ?>"></label>
      </div>
      <label class="sj-field"><span>Your invoice number</span><input name="invoice_no" maxlength="60"></label>
      <label class="sj-btn sj-file"><span id="sj-invoice-word"><?= (int)$sub['invoices'] > 0 ? 'Invoice attached. Replace or add' : 'Attach your invoice (PDF or photo)' ?></span><input type="file" id="sj-invoice" accept="image/*,application/pdf"></label>
      <p class="sj-sync" id="sj-invoice-status" role="status" hidden></p>
    <?php endif; ?>

    <?php if ($step === 'bill_waiting'): ?><p class="sj-waiting"><?= $e($business) ?> has your bill. You will see here when it is approved and paid.</p><?php endif; ?>
    <?php if ($step === 'payment_waiting'): ?><p class="sj-waiting">Your bill is approved. You will see here when it is paid.</p><?php endif; ?>
    <?php if ($step === 'done'): ?><p class="sj-waiting">Paid. Thank you for the work.</p><?php endif; ?>
    <?php if ($step === 'closed'): ?><p class="sj-waiting">Thanks for letting <?= $e($business) ?> know.</p><?php endif; ?>

    <p class="sj-error" id="sj-error" role="alert" hidden></p>

    <?php if ($primary): ?>
      <button type="submit" class="sj-primary" data-action="<?= $e($primary[0]) ?>"><?= $e($primary[1]) ?></button>
    <?php endif; ?>
    <?php if ($step === 'respond'): ?>
      <button type="button" class="sj-btn sj-wide" data-action="decline">I can't take this job</button>
    <?php endif; ?>
    <?php if (!empty($page['can_undo'])): ?>
      <button type="button" class="sj-link sj-wide" data-action="undo">Take that back</button>
    <?php endif; ?>
  </form>
<?php endif; ?>
</main>
<script src="/customer/sub-job.js" defer></script>
</body>
</html>
