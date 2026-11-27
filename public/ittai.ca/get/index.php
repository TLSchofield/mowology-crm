<?php
declare(strict_types=1);
// ittai.ca/get/ (2026-11-26): the page behind the link we send a new owner to download the Android app. The install
// steps live HERE, on the same page as the button (Tim, 2026-11-26), so nobody needs a second document.
// Everything that changes per build is in app.json. Plain text only; no user input is read.

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');

$cfg = json_decode((string)@file_get_contents(__DIR__ . '/app.json'), true);
if (!is_array($cfg)) $cfg = [];
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$apk = (string)($cfg['apk_url'] ?? '');
// Only a file on one of our own hosts is ever offered.
$apkOk = (bool)preg_match('~^https://([a-z0-9-]+\.)*(ittai\.ca|mowology\.ca)/[^\s"<>]+\.apk$~i', $apk);
$version = (string)($cfg['version'] ?? '');
$size = (int)($cfg['size_mb'] ?? 0);
$minAndroid = (string)($cfg['min_android'] ?? '');
$sha = strtolower(trim((string)($cfg['sha256'] ?? '')));
$shaOk = (bool)preg_match('/^[0-9a-f]{64}$/', $sha);
$help = trim((string)($cfg['help_phone'] ?? ''));
$preview = isset($_SERVER['ITTAI_GET_PREVIEW']);   // set only by the local preview script: shows the draft box
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
// Samsung phones add one more check (Auto Blocker), so their box goes first. Signals, strongest first: the link we send
// (?for=samsung), the browser's user-agent string (Samsung Internet sends the model; Chrome hides it), then the script
// at the bottom, which asks the browser for the model (userAgentData) and reorders the page if it starts with SM-.
$samsung = (($_GET['for'] ?? '') === 'samsung') || (bool)preg_match('/samsung|\bSM-[A-Z0-9]{3,}/i', $ua);
$css = $preview ? '' : '/get/get.css';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Get the Ittai app | Ittai</title>
<link rel="stylesheet" href="/legal.css">
<link rel="stylesheet" href="<?= $h($css) ?>">
</head>
<body>
<header class="it-top">
  <a class="it-brand" href="/" aria-label="Ittai home"><span>Ittai</span></a>
  <nav><a href="/terms/">Terms</a> <a href="/privacy/">Privacy</a></nav>
</header>
<main class="get">
<?php if ($preview): ?>
  <p class="draft"><strong>Draft for Tim.</strong> Steps are from Android's standard screens and Samsung's, not yet walked on a clean phone. The download button, size, checksum and help number come from <code>app.json</code>; until the file is uploaded the button says it is not ready. This box never shows on the live page.</p>
<?php endif; ?>
  <h1>Get the Ittai app</h1>
  <p class="lede">For Android phones. It takes about five minutes.</p>

<?php if ($apkOk): ?>
  <a class="get-btn" href="<?= $h($apk) ?>" download>Download the Ittai app</a>
  <p class="meta"><?= $version !== '' ? 'Version ' . $h($version) : '' ?><?= $size > 0 ? ' · ' . $size . ' MB' : '' ?><?= $minAndroid !== '' ? ' · Android ' . $h($minAndroid) . ' or newer' : '' ?></p>
<?php else: ?>
  <a class="get-btn" aria-disabled="true" role="link">The download is not ready yet</a>
  <p class="meta">We will email you when it is. Nothing is wrong on your phone.</p>
<?php endif; ?>

  <div class="note">
    <p><strong>Your phone will ask you to say yes a few times.</strong> Ittai is not in the Play Store yet, so your phone stops you and asks if it is OK. That is normal. You are only saying yes to an app that came from us.</p>
  </div>

  <h2>What to do</h2>
  <ol class="steps">
    <li><span>Tap <b>Download the Ittai app</b> above.<small>If your phone asks, tap <b>Download anyway</b>, <b>Keep</b> or <b>OK</b>.</small></span></li>
    <li><span>When it finishes, tap <b>Open</b> at the top of the screen.<small>It disappeared? Swipe down from the top and tap <b>Ittai</b>.</small></span></li>
    <li><span>Tap <b>Install</b>, then <b>Open</b>.<small>Your phone may stop you first. See the box below that matches your screen.</small></span></li>
    <li><span>Allow the <b>camera</b> (tap <b>While using the app</b>) so you can photograph receipts, and allow <b>notifications</b>.</span></li>
    <li><span>Create your login with the email address we sent this link to.</span></li>
  </ol>

  <h2>Your phone stopped you?</h2>
<?php
$boxSamsung = '<div id="case-samsung" class="case' . ($samsung ? ' case-yours' : '') . '">'
  . '<p class="when">' . ($samsung ? '<b>You have a Samsung phone.</b> ' : '<b>Samsung phone?</b> ') . 'If the message mentions <b>Auto Blocker</b>:</p>'
  . '<ol><li>Open <b>Settings</b>, then <b>Security and privacy</b>, then <b>Auto Blocker</b>.</li>'
  . '<li>Turn off <b>Block app installs from unauthorized sources</b>.</li>'
  . '<li>Go back and tap <b>Install</b> again.</li></ol>'
  . '<p class="why">Auto Blocker is Samsung\'s own safety setting. You can turn it back on once the app is installed.</p></div>';
$boxUnknown = '<div class="case"><p class="when">It says <b>"not allowed to install unknown apps"</b></p>'
  . '<ol><li>Tap <b>Settings</b>.</li><li>Switch on <b>Allow from this source</b>.</li>'
  . '<li>Press the back arrow until you see <b>Install</b>, then tap it.</li></ol></div>';
$boxPlay = '<div class="case"><p class="when">It says <b>"Blocked by Play Protect"</b></p>'
  . '<ol><li>Tap <b>More details</b>.</li><li>Tap <b>Install anyway</b>.</li></ol>'
  . '<p class="why">Play Protect is Google\'s safety check. It does not know our app yet. If it says <b>App scan recommended</b>, tap <b>Scan app</b>, wait, then tap <b>Install</b>.</p></div>';
echo '  <div id="cases">' . "\n";
if ($samsung) {
    echo '  <p class="sub">On a Samsung phone you may see these in this order.</p>' . "\n  " . $boxSamsung . "\n  " . $boxUnknown . "\n  " . $boxPlay . "\n";
} else {
    echo '  <p id="cases-sub" class="sub">Find the words on your screen.</p>' . "\n  " . $boxUnknown . "\n  " . $boxPlay . "\n  " . $boxSamsung . "\n";
}
echo '  </div>' . "\n";
?>

  <h2>If something goes wrong</h2>
  <details>
    <summary>It says "App not installed" or "problem parsing the package"</summary>
    <p>The download was cut off. Swipe down from the top, press and hold the file, tap <b>Delete</b>. Then tap the download button again and wait for the whole file.</p>
  </details>
  <details>
    <summary>It says "This app is not compatible" or "Package appears to be invalid"</summary>
    <p>Download it again from this page. If it says the same thing, contact us and tell us the make of your phone.</p>
  </details>
  <details>
    <summary>I cannot find the file</summary>
    <p>Open the <b>Files</b> or <b>My Files</b> app, tap <b>Downloads</b>, then tap the Ittai file.</p>
  </details>
  <details>
    <summary>The Install button is grey</summary>
    <p>Do step 4 or step 6 first, then try again.</p>
  </details>
  <details>
    <summary>It says "Update required" when I open the app</summary>
    <p>Tap <b>Update</b> and follow steps 4 to 6 again. It is the same thing.</p>
  </details>

  <h2>After you have installed it</h2>
  <details>
    <summary>Switch the safety back on (recommended)</summary>
    <p>So your phone stays safe, turn the permission off again. Open <b>Settings</b>, <b>Apps</b>, then <b>Special access</b> (or the three dots), then <b>Install unknown apps</b>. Find your browser or <b>Gmail</b> and turn <b>Allow from this source</b> off. If you turned <b>Auto Blocker</b> off, turn it back on. If this looks confusing, skip it. The app keeps working.</p>
  </details>
  <details>
    <summary>Location is optional</summary>
    <p>The app works without it. If you switch it on, it can help in two ways: it can match each receipt to where you bought it, and it can help you see how long you spent on each job. It is your choice and you can turn it off at any time. To turn it on, open the app, tap <b>Account</b>, then <b>Location</b>, and switch it on.</p>
  </details>
<?php if ($shaOk): ?>
  <details>
    <summary>For the careful: check the file</summary>
    <p>The file's SHA-256 checksum is:</p>
    <p><code style="word-break:break-all"><?= $h($sha) ?></code></p>
  </details>
<?php endif; ?>

  <div class="help">
    <p><strong>Stuck?</strong> You will not break anything.</p>
<?php if ($help !== ''): ?>
    <p>Call or text <a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $help)) ?>"><?= $h($help) ?></a> and we will do it with you on the phone.</p>
<?php else: ?>
    <p>Reply to the email we sent you and we will do it with you on the phone.</p>
<?php endif; ?>
    <p>Or take a photo of the screen and send it to us.</p>
  </div>
</main>
<footer class="it-foot">&copy; <?= date('Y') ?> Ittai</footer>
<script>
// If the phone is a Samsung but the server could not tell (Chrome hides the model), ask the browser and move the Samsung box up.
(function () {
  var box = document.getElementById('case-samsung'), wrap = document.getElementById('cases');
  if (!box || !wrap || box.classList.contains('case-yours')) return;
  var ud = navigator.userAgentData;
  if (!ud || !ud.getHighEntropyValues) return;
  ud.getHighEntropyValues(['model']).then(function (v) {
    if (!v || !/^SM-/i.test(v.model || '')) return;
    var sub = document.getElementById('cases-sub');
    box.classList.add('case-yours');
    box.firstElementChild.innerHTML = '<b>You have a Samsung phone.</b> If the message mentions <b>Auto Blocker</b>:';
    wrap.insertBefore(box, sub ? sub.nextSibling : wrap.firstChild);
    if (sub) sub.textContent = 'On a Samsung phone you may see these in this order.';
  }).catch(function () {});
})();
</script>
</body>
</html>
