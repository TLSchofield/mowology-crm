<?php
declare(strict_types=1);

/**
 * ittai.ca/g/<code>: a private page for gardeners (2026-11-11). Reached only by its link; see gate.php.
 * Wrong code, expired code, throttled visitor and any other path all get the same plain 404.
 * Photos are Mowology's own. Copy rules: only what works today is tagged Ready; anything not built is tagged Next.
 */
require __DIR__ . '/gate.php';
$links = require __DIR__ . '/links.php';

function ittaiNotFound(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    echo "<!doctype html><meta charset=\"utf-8\"><title>Not found</title><h1>Not found</h1>";
    exit;
}

$now = time();
$code = (string)($_GET['t'] ?? '');
$file = (string)($_GET['f'] ?? '');
$visitor = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$tmp = sys_get_temp_dir();

if ($code === '' || ittaiThrottled($tmp, $visitor, $now)) ittaiNotFound();
$link = ittaiLinkLookup($code, $links, $now);
if ($link === null) {
    ittaiThrottled($tmp, $visitor, $now, true);
    ittaiNotFound();
}

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

// A photo of this page.
if ($file !== '') {
    if (!in_array($file, ittaiPageImages(), true)) ittaiNotFound();
    $path = __DIR__ . '/_img/' . $file;
    if (!is_file($path)) ittaiNotFound();
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

// The page.
error_log('ittai-g view ' . (string)($link['label'] ?? '?'));   // which link, never who
$nonce = base64_encode(random_bytes(16));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; "
     . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'; connect-src 'none'");

$img = static fn(string $f): string => '/g/' . $code . '/i/' . $f;
$art = static fn(string $f): string => (string)file_get_contents(__DIR__ . '/_art/' . $f);
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$chip = static fn(string $kind, string $label): string => '<span class="chip chip--' . $kind . '">' . $h($label) . '</span>';

$rows = [
    ['Your day, on one screen', [['ready', 'Ready']],
        "Today's stops in driving order on Google Maps. Your crew sees their own day. Everyone clocks in and out, and the hours are ready for whoever runs your payroll."],
    ['Receipts file themselves', [['ready', 'Ready']],
        'Photograph the receipt at the counter. Penny reads the vendor, date and GST, suggests a category, and asks you to confirm.'],
    ['Quotes your clients can approve', [['ready', 'Ready']],
        'A quote page your client opens on their phone. A property manager can be copied on it. Approved work lands on your schedule.'],
    ['Every property remembered', [['ready', 'Ready'], ['next', 'Crew camera next']],
        'Notes, photos pinned to the spot, and each bed on the property. Photograph a plant and Ittai names it and keeps it with the bed it stands in. Today you do this from the property page.'],
    ['Trucks tracked as vehicles', [['ready', 'Ready']],
        'A truck can carry a tablet that keeps its position. It is a vehicle. It never shows up as an employee on a timesheet.'],
    ['You keep QuickBooks', [['together', 'Set up together']],
        'Invoices and receipts go from Ittai into your QuickBooks, so Ittai does the typing. We connect yours together and check the first invoices by eye.'],
    ['Best price, in stock', [['next', 'Next']],
        "Load your suppliers' lists. Quote a planting and see who has it, at what price, today."],
];

$faq = [
    ['Do I have to leave QuickBooks?', 'No. QuickBooks stays your file, and your accountant keeps working in it. Ittai sends invoices and receipts into it.'],
    ['Can my clients pay by card in Ittai?', 'No. Ittai does not take card payments for other businesses. Invoices go out with your QuickBooks pay link when online payment is on there, and Ittai marks them paid. We test that on your account before you rely on it.'],
    ['Is there an iPhone and an Android app?', 'Yes. We install them on your phone and your crew\'s phones together at the start. Everything is built to finish on a phone, so you do not need a computer.'],
    ['What languages does it speak?', 'English only today. If your crew reads another language first, tell me which screens they use most.'],
    ['Who can see my clients?', 'Your business gets its own copy: its own database, its own address, and nothing on it that says Mowology. Your clients are never mixed with anyone else\'s. The founding agreement will say so in writing.'],
    ['What does it cost?', 'I have not set a price. Early businesses agree terms with me in writing before any real clients go in.'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>Ittai for gardeners</title>
<style nonce="<?= $nonce ?>">
  /* Ittai v1.2 tokens (light / dark), from docs/design/ittai-v1.2/tokens.json. Status tags use text colours that clear 7:1 on their surface. */
  :root {
    --page: #F4F5F3; --surface: #FFFFFF; --line: #D9DFDA; --text: #131D17; --meta: #48534C; --brand: #233A5E;
    --on-brand: #FFFFFF; --ready: #1A5A39; --together: #2A557E; --next: #74490D;
    --knowledge-mastered: var(--brand); --marketing-sage: var(--meta);
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --page: #0F1512; --surface: #161E1A; --line: #2C3832; --text: #E9EEEA; --meta: #AFB9B3; --brand: #A9B9DA;
      --on-brand: #0F1512; --ready: #8FD0A9; --together: #A9C6EC; --next: #E6BE7A;
    }
  }
  * { box-sizing: border-box; }
  html { background: var(--page); }
  body {
    margin: 0; background: var(--page); color: var(--text);
    font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: 17px; line-height: 28px; -webkit-font-smoothing: antialiased; overflow-x: clip;
    padding-inline: max(16px, env(safe-area-inset-left, 0px)) max(16px, env(safe-area-inset-right, 0px));
  }
  .wrap { max-width: 920px; margin-inline: auto; }
  h1, h2, h3 { line-height: 1.2; margin: 0; text-wrap: balance; letter-spacing: -0.01em; }
  h1 { font-size: clamp(34px, 7vw, 52px); font-weight: 650; }
  h2 { font-size: clamp(26px, 4.5vw, 34px); font-weight: 650; }
  h3 { font-size: 19px; font-weight: 650; }
  p { margin: 0; }
  a { color: var(--brand); }
  :focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }

  .top { display: flex; align-items: center; gap: 12px; padding-block: 20px 0; color: var(--brand); }
  .top svg { width: 40px; height: 40px; }
  .top b { font-size: 22px; font-weight: 650; color: var(--text); }
  .top span { color: var(--meta); font-size: 15px; margin-inline-start: auto; }

  .hero { position: relative; padding-block: 48px 40px; isolation: isolate; overflow-x: clip; }
  .hero .pattern { position: absolute; top: -10px; right: 0; width: min(560px, 100%); opacity: .08; z-index: -1; pointer-events: none; }
  .hero .pattern svg { width: 100%; height: auto; display: block; clip-path: inset(0 0 22% 0); }   /* the traced file carries its own caption at the foot; crop it off */
  .eyebrow { font-size: 14px; letter-spacing: .08em; text-transform: uppercase; color: var(--meta); font-weight: 600; }
  .hero h1 { margin-top: 12px; max-width: 16ch; }
  .lede { margin-top: 20px; font-size: 20px; line-height: 30px; max-width: 34em; }
  .btns { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
  .btn { display: inline-flex; align-items: center; min-height: 52px; padding: 0 24px; border-radius: 10px; font-weight: 650; text-decoration: none; border: 2px solid var(--brand); }
  .btn--fill { background: var(--brand); color: var(--on-brand); }
  .btn--line { color: var(--brand); background: transparent; }
  .hero-photo { margin: 36px 0 0; }
  .hero-photo img { width: 100%; height: auto; display: block; border-radius: 14px; }
  figcaption { color: var(--meta); font-size: 15px; line-height: 22px; margin-top: 8px; }

  section { padding-block: 44px 0; }
  .sub { margin-top: 10px; color: var(--meta); max-width: 38em; }
  .chip { display: inline-block; font-size: 13px; line-height: 20px; font-weight: 650; padding: 2px 10px; border-radius: 999px; border: 1.5px solid currentColor; background: var(--surface); white-space: nowrap; }
  .chip--ready { color: var(--ready); } .chip--together { color: var(--together); } .chip--next { color: var(--next); }
  .legend { display: flex; flex-wrap: wrap; gap: 8px 20px; margin-top: 16px; color: var(--meta); font-size: 15px; }

  .rows { margin-top: 20px; border-top: 1px solid var(--line); }
  .row { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 6px 28px; padding-block: 20px; border-bottom: 1px solid var(--line); }
  .row .tags { display: flex; flex-wrap: wrap; gap: 6px; align-content: flex-start; }
  .row p { margin-top: 6px; }

  .show { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 28px; margin-top: 24px; align-items: start; }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
  .card h3 { margin-bottom: 4px; }
  .note { color: var(--meta); font-size: 15px; line-height: 22px; margin-top: 10px; }

  .ba { position: relative; margin: 12px 0 0; }
  .ba__stage { position: relative; overflow: hidden; border-radius: 10px; aspect-ratio: 4 / 3; background: var(--line); --pos: 50%; touch-action: pan-y; }
  .ba__stage img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
  .ba__after { position: absolute; inset: 0; clip-path: inset(0 0 0 var(--pos)); }
  .ba__bar { position: absolute; top: 0; bottom: 0; left: var(--pos); width: 3px; margin-left: -1.5px; background: #FFFFFF; box-shadow: 0 0 0 1px rgba(0,0,0,.35); pointer-events: none; }
  .ba__tag { position: absolute; top: 10px; padding: 4px 10px; border-radius: 6px; font-size: 14px; font-weight: 650; background: #131D17; color: #FFFFFF; }
  .ba__tag--b { left: 10px; } .ba__tag--a { right: 10px; }
  .ba label { display: block; margin-top: 12px; font-size: 15px; color: var(--meta); }
  .ba input[type=range] { width: 100%; height: 48px; margin: 4px 0 0; accent-color: var(--brand); }

  .post { background: var(--surface); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .post__label { display: flex; justify-content: space-between; gap: 8px; padding: 10px 14px; font-size: 14px; font-weight: 650; border-bottom: 1px solid var(--line); }
  .post__label i { font-style: normal; color: var(--meta); font-weight: 500; }
  .post__img { display: grid; grid-template-columns: 1fr 1fr; gap: 2px; background: var(--line); }
  .post__img img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; display: block; }
  .post__img--one { grid-template-columns: 1fr; }
  .post__img--one img { aspect-ratio: 4 / 3; }
  .post__cap { padding: 12px 14px 14px; font-size: 16px; line-height: 24px; }
  .post__cap small { display: block; color: var(--meta); margin-top: 6px; font-size: 14px; }
  .posts { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; margin-top: 16px; }

  .steps { list-style: none; margin: 20px 0 0; padding: 0; border-top: 1px solid var(--line); counter-reset: s; }
  .steps li { counter-increment: s; display: grid; grid-template-columns: 44px minmax(0, 1fr); gap: 4px 14px; padding-block: 16px; border-bottom: 1px solid var(--line); }
  .steps li::before { content: counter(s); width: 36px; height: 36px; border-radius: 50%; background: var(--brand); color: var(--on-brand); display: grid; place-items: center; font-weight: 650; }
  .spots { display: flex; gap: 20px; margin-top: 24px; opacity: .9; }
  .spots svg { width: 140px; height: auto; display: block; }

  dl { margin: 20px 0 0; border-top: 1px solid var(--line); }
  .qa { padding-block: 16px; border-bottom: 1px solid var(--line); }
  dt { font-weight: 650; }
  dd { margin: 4px 0 0; }

  .talk { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 28px 24px; margin-top: 44px; }
  .talk ul { margin: 14px 0 0; padding-inline-start: 22px; }
  .talk p + p { margin-top: 14px; }
  footer { padding-block: 36px 56px; color: var(--meta); font-size: 15px; line-height: 24px; }
  footer a { color: var(--meta); }

  @media (max-width: 760px) {
    .row, .show, .posts { grid-template-columns: minmax(0, 1fr); }
    .hero { padding-block: 32px 28px; }
    .spots svg { width: 100px; }
    .top span { display: none; }
  }
  @media (prefers-reduced-motion: no-preference) { html { scroll-behavior: smooth; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <svg viewBox="0 0 64 64" role="img" aria-label="Ittai symbol">
      <g fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="75.34 24.66" stroke-dashoffset="-12.33">
        <path pathLength="100" d="M18.96 9.42 A6 6 0 0 1 21.96 8.62 L42.04 8.62 A6 6 0 0 1 45.04 9.42"/>
        <path pathLength="100" d="M45.04 9.42 A6 6 0 0 1 47.23 11.62 L57.27 29 A6 6 0 0 1 58.07 32"/>
        <path pathLength="100" d="M58.07 32 A6 6 0 0 1 57.27 35 L47.23 52.38 A6 6 0 0 1 45.04 54.58"/>
        <path pathLength="100" d="M45.04 54.58 A6 6 0 0 1 42.04 55.38 L21.96 55.38 A6 6 0 0 1 18.96 54.58"/>
        <path pathLength="100" d="M18.96 54.58 A6 6 0 0 1 16.77 52.38 L6.73 35 A6 6 0 0 1 5.93 32"/>
        <path pathLength="100" d="M5.93 32 A6 6 0 0 1 6.73 29 L16.77 11.62 A6 6 0 0 1 18.96 9.42"/>
      </g>
    </svg>
    <b>Ittai</b><span>A private page. Please do not share the link.</span>
  </div>

  <header class="hero">
    <div class="pattern" aria-hidden="true"><?= $art('pattern.svg') ?></div>
    <div class="eyebrow">For gardeners and grounds crews</div>
    <h1>Run your garden business from your phone.</h1>
    <p class="lede">Clients, schedule, crew, receipts and quotes in one app. I built it to run my own landscaping company, Mowology, in Vancouver. You keep QuickBooks.</p>
    <div class="btns">
      <a class="btn btn--fill" href="#talk">Tell me about your business</a>
      <a class="btn btn--line" href="#week">See what changes in a week</a>
    </div>
    <figure class="hero-photo">
      <img src="<?= $h($img('crew-hedge.jpg')) ?>" width="1100" height="825" alt="A Mowology crew member on a ladder, trimming a tall green hedge with a hedge trimmer.">
      <figcaption>A Mowology crew member trimming a hedge. These are our own photos.</figcaption>
    </figure>
  </header>

  <section id="week">
    <h2>What changes in your week</h2>
    <p class="sub">Each line is something you do now that Ittai does or speeds up. The tag tells you what works today.</p>
    <div class="legend">
      <span><?= $chip('ready', 'Ready') ?> works today</span>
      <span><?= $chip('together', 'Set up together') ?> built, we switch it on with you</span>
      <span><?= $chip('next', 'Next') ?> not built yet</span>
    </div>
    <div class="rows">
      <?php foreach ($rows as [$title, $tags, $text]): ?>
      <div class="row">
        <div class="tags"><?php foreach ($tags as [$kind, $label]) echo $chip($kind, $label), ' '; ?></div>
        <div><h3><?= $h($title) ?></h3><p><?= $h($text) ?></p></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="work">
    <h2>Show the work you did</h2>
    <p class="sub">Photos from a visit become the proof your client sees and the post you share. Both examples below use our own hedge photos.</p>
    <div class="show">
      <div class="card">
        <h3>Before and after, on your website</h3>
        <p class="note">Drag the slider. This is an example. A paste-in version for your own website is next.</p>
        <figure class="ba" data-ba>
          <div class="ba__stage">
            <img src="<?= $h($img('hedge-before.jpg')) ?>" width="860" height="645" alt="A tall, untrimmed hedge beside a footpath on a Vancouver street, before the visit.">
            <div class="ba__after"><img src="<?= $h($img('hedge-after.jpg')) ?>" width="860" height="645" alt="The same hedge trimmed square after the visit."></div>
            <div class="ba__bar"></div>
            <span class="ba__tag ba__tag--b">Before</span><span class="ba__tag ba__tag--a">After</span>
          </div>
          <label for="ba-range">Drag to compare before and after
            <input id="ba-range" type="range" min="0" max="100" value="50" class="ba__range">
          </label>
        </figure>
        <?= $chip('next', 'Paste-in for your site: next') ?>
      </div>
      <div class="card">
        <h3>Posts from the same photos</h3>
        <p class="note">Ittai drafts the picture and the words from a visit. You approve them. Drafting works at Mowology today. Posting straight to Facebook and Instagram from Ittai is not working yet.</p>
        <div class="spots" aria-hidden="true"><?= $art('spot-planting.svg') ?><?= $art('spot-tree.svg') ?></div>
        <?= $chip('next', 'Posting: next') ?>
      </div>
    </div>
    <div class="posts">
      <article class="post">
        <div class="post__label">Instagram post <i>example</i></div>
        <div class="post__img">
          <img src="<?= $h($img('hedge-before.jpg')) ?>" width="860" height="645" alt="Hedge before the visit.">
          <img src="<?= $h($img('hedge-after.jpg')) ?>" width="860" height="645" alt="Hedge after the visit.">
        </div>
        <div class="post__cap">Before and after one visit. Hedge cut square, footpath edge cleared. Booking spring trims now, message us.
          <small>#vancouver #hedgetrimming #landscaping</small></div>
      </article>
      <article class="post">
        <div class="post__label">Facebook post <i>example</i></div>
        <div class="post__img post__img--one">
          <img src="<?= $h($img('strata-aerial.jpg')) ?>" width="515" height="760" alt="A strata courtyard seen from above, with shaped shrubs and a work truck.">
        </div>
        <div class="post__cap">A strata courtyard, seen from above. If your council wants a record of every visit, ask us about photo reports.</div>
      </article>
    </div>
  </section>

  <section id="start">
    <h2>How it starts</h2>
    <p class="sub">Slowly, on purpose. You decide when each step opens, and nothing is switched on without you.</p>
    <ol class="steps">
      <li><div><b>Snap receipts only.</b> The rest of the menu is visible but grey and says "Not turned on yet". You see what is coming and nothing is in your way.</div></li>
      <li><div><b>Your schedule and your crew.</b> Properties, the week, clock in and out, the trucks.</div></li>
      <li><div><b>Clients and quotes.</b> Your customers come across from QuickBooks, then your first quote goes out from the phone.</div></li>
      <li><div><b>QuickBooks connected.</b> Invoices and receipts flow into your own file.</div></li>
    </ol>
  </section>

  <section id="faq">
    <h2>Questions you will probably ask</h2>
    <dl>
      <?php foreach ($faq as [$q, $a]): ?>
      <div class="qa"><dt><?= $h($q) ?></dt><dd><?= $h($a) ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>

  <section id="talk" class="talk">
    <h2>Tell me about your business</h2>
    <p>Reply to the message this link came in, with three things:</p>
    <ul>
      <li>How many people are on your crew?</li>
      <li>How do you schedule today?</li>
      <li>What costs you the most time each week?</li>
    </ul>
    <p>I read every reply myself. Tim Schofield</p>
  </section>

  <footer>
    Ittai &middot; Vancouver, BC &middot; A private page, not for sharing.
    <a href="/privacy/">Privacy</a> &middot; <a href="/terms/">Terms</a>
  </footer>
</div>
<script nonce="<?= $nonce ?>">
  (function () {
    var r = document.getElementById('ba-range'), s = document.querySelector('[data-ba] .ba__stage');
    if (!r || !s) return;
    function set() { s.style.setProperty('--pos', r.value + '%'); }
    r.addEventListener('input', set); set();
  })();
</script>
</body>
</html>
