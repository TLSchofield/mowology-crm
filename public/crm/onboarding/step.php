<?php
/**
 * One onboarding module's screen (owner, 2026-10-20: "each screen should be low information requirement").
 * Penny says one sentence, the screen shows the fewest facts that matter and ONE main button; details sit
 * behind "Show details". ?m=<module key>, QuickBooks parts with &part=. Thin controller: the modules and
 * QboOnboardingService do the work.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
requirePermission('settings.edit');
$user = getCurrentUser();
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingService.php';
require_once APP_ROOT . '/Modules/QuickBooks/Services/QboOnboardingService.php';
require_once APP_ROOT . '/Modules/Expenses/Services/PennyBrainService.php';

$db  = getDB();
$uid = (int)($user['id'] ?? 0);
$svc = new OnboardingService(new OnboardingContext($db, $uid));
$ctx = $svc->context();
$key = (string)($_GET['m'] ?? $_POST['m'] ?? '');
$mod = $svc->module($key);
if (!$mod) { header('Location: index.php'); exit; }
$part = (string)($_GET['part'] ?? $_POST['part'] ?? '');
$partsOk = $key === 'name' ? array_merge(OnboardingNameModule::PARTS, ['all']) : OnboardingQuickBooksModule::PARTS;
if ($part !== '' && !in_array($part, $partsOk, true)) $part = '';

$qconn = null;
try { $qconn = new QboConnectionService($db); } catch (Throwable $e) { /* not set up */ }
$qob = new QboOnboardingService($db, ($qconn && $qconn->tablesReady() && $qconn->company()) ? $qconn : null);

$flash = $_SESSION['ob_flash'] ?? null;
unset($_SESSION['ob_flash']);
$goNext = function () use ($svc) {
    $n = (new OnboardingService($svc->context()))->next();
    header('Location: ' . ($n ? 'step.php?m=' . rawurlencode($n) : 'index.php')); exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ob_flash'] = ['ok' => false, 'message' => 'The page was open too long. Try again.'];
        header('Location: step.php?m=' . rawurlencode($key)); exit;
    }
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'skip') { $_SESSION['ob_flash'] = $svc->skip($key, true); $goNext(); }
        if ($key === 'name' && in_array($do, ['save', 'reach', 'quiet'], true)) {
            $r = $do === 'save' ? $mod->save($ctx, (string)($_POST['first'] ?? ''), (string)($_POST['last'] ?? ''), (string)($_POST['preferred'] ?? ''))
               : ($do === 'reach' ? $mod->saveReach($ctx, (string)($_POST['channel'] ?? ''), (string)($_POST['mobile'] ?? ''))
               : $mod->saveQuiet($ctx, (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''), !empty($_POST['weekends'])));
            $_SESSION['ob_flash'] = $r;
            if ($r['ok']) {
                // The side that just locked animates in on the next screen.
                $_SESSION['ob_fresh'] = array_search($do === 'save' ? 'name' : $do, OnboardingNameModule::PARTS, true);
                $np = $mod->nextPart($ctx);
                if ($np === null) { header('Location: step.php?m=name&part=all'); exit; }
                header('Location: step.php?m=name&part=' . $np); exit;
            }
            header('Location: step.php?m=name&part=' . ($do === 'save' ? 'name' : $do)); exit;
        } elseif ($key === 'quickbooks' && in_array($do, ['yes', 'no'], true)) {
            $mod->setAnswer($ctx, $do === 'yes');
            if ($do === 'no') $goNext();
        } elseif ($key === 'quickbooks' && $do === 'read') {
            $_SESSION['ob_flash'] = $qob->pull();
        } elseif ($key === 'quickbooks' && $do === 'accept' && $part !== '') {
            $r = $qob->apply($part, $uid);
            $_SESSION['ob_flash'] = $r;
            $nextPart = OnboardingQuickBooksModule::PARTS[array_search($part, OnboardingQuickBooksModule::PARTS, true) + 1] ?? null;
            header('Location: step.php?m=quickbooks' . ($r['ok'] && $nextPart ? '&part=' . $nextPart : ($r['ok'] ? '' : '&part=' . $part))); exit;
        } elseif ($key === 'quickbooks' && $do === 'send' && $qconn && $qconn->company()) {
            require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingStepService.php';
            $send = (new OnboardingStepService($db, Tenant::db()[0]))->apply($uid,
                ['key' => 'quickbooks', 'do' => 'send', 'on' => (string)($_POST['on'] ?? '') === '1']);
            $_SESSION['ob_flash'] = $send;
        } elseif (in_array($key, ['terms', 'gst', 'yearend', 'payroll'], true) && in_array($do, ['accept', 'answer'], true)) {
            // The step service does the saving (and, for the terms, the acceptance record); the page only passes the form on.
            require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingStepService.php';
            $r = (new OnboardingStepService($db, Tenant::db()[0]))->apply($uid, [
                'key' => $key, 'do' => $do, 'registered' => (string)($_POST['registered'] ?? ''), 'number' => (string)($_POST['number'] ?? ''),
                'filing' => (string)($_POST['filing'] ?? ''), 'who' => (string)($_POST['who'] ?? ''), 'how' => (string)($_POST['how'] ?? ''),
                'app' => (string)($_POST['app'] ?? ''), '_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), '_ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
            if (!empty($r['ok'])) $goNext();
            $_SESSION['ob_flash'] = ['ok' => false, 'message' => (string)($r['message'] ?? 'That did not work.')];
        } elseif ($key === 'books' && $do === 'ack') {
            $mod->acknowledge($ctx);
            $goNext();
        } elseif ($key === 'accountant' && $do === 'invite') {
            require_once APP_ROOT . '/Modules/Ledger/Services/AccountantInviteService.php';
            $name = trim((string)($_POST['name'] ?? '')); $email = trim((string)($_POST['email'] ?? ''));
            if (($bad = AccountantInviteService::invalid($name, $email)) !== null) {
                $_SESSION['ob_flash'] = ['ok' => false, 'message' => $bad];
            } else {
                $base = (defined('SITE_URL') ? SITE_URL : 'https://' . ($_SERVER['HTTP_HOST'] ?? 'mowology.ca'));
                $inv = (new AccountantInviteService($db))->invite($name, $email, $uid, $base);
                $ctx->saveSetting('onboarding_accountant_invited', $name, 'Onboarding: who was invited as the accountant (OnboardingAccountantModule)');
                // Nothing is sent: the owner passes the link on (AccountantInviteService sends nothing).
                $_SESSION['ob_invite_link'] = ['link' => $inv['link'], 'expires' => $inv['expires_at'], 'name' => $name];
            }
        } elseif ($key === 'done' && $do === 'finish') {
            if (!$svc->readyToFinish()) {
                // Not before the steps ahead of it (Codex review 2026-10-20).
                $_SESSION['ob_flash'] = ['ok' => false, 'message' => 'A step before this one still needs doing — the map shows which.'];
                header('Location: index.php'); exit;
            }
            $ctx->saveSetting('onboarding_complete', '1', 'Onboarding: the owner finished the path (OnboardingDoneModule)');
            header('Location: index.php'); exit;
        }
    } catch (Throwable $e) {
        error_log('Onboarding step ' . $key . ': ' . $e->getMessage());
        $_SESSION['ob_flash'] = ['ok' => false, 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'That did not work. Nothing was changed.'];
    }
    header('Location: step.php?m=' . rawurlencode($key) . ($part !== '' ? '&part=' . $part : '')); exit;
}

$csrf = generateCSRFToken();
$state = $mod->state($ctx);
$order = $svc->order();
$pos = array_search($key, $order, true);
$me = $db->prepare('SELECT first_name, last_name, preferred_name FROM users WHERE id = ?');
$me->execute([$uid]);
$me = $me->fetch(PDO::FETCH_ASSOC) ?: [];
$call = trim((string)($me['preferred_name'] ?? '')) ?: trim((string)($me['first_name'] ?? ''));
$invite = $_SESSION['ob_invite_link'] ?? null;
unset($_SESSION['ob_invite_link']);
$fresh = $_SESSION['ob_fresh'] ?? null;
unset($_SESSION['ob_fresh']);
if ($key === 'name' && $part === '') $part = $mod->nextPart($ctx) ?? 'all';

$pageTitle = $mod->title();
$activePage = 'settings';
$extraHead = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">';
foreach (['penny-onboarding.css', 'ittai-onboarding.css'] as $__css) {
    $extraHead .= '<link href="/crm/css/' . $__css . '?v=' . (int)@filemtime(dirname(__DIR__) . '/css/' . $__css) . '" rel="stylesheet">';
}
foreach (['head-brain.js', 'ittai-knowledge-glyph.js', 'onboarding-about.js'] as $__js) {
    $extraHead .= '<script src="/crm/js/' . $__js . '?v=' . (int)@filemtime(dirname(__DIR__) . '/js/' . $__js) . '" defer></script>';
}
$money = fn($v) => $v === null ? '—' : '$' . number_format((float)$v, 2);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<main class="it-onboard it-obs" aria-labelledby="obsTitle">
    <div class="it-ob-shell">
        <header class="it-ob-header">
            <p class="it-ob-kicker"><a class="it-obm-back" href="index.php">Onboarding</a> · Step <?= (int)$pos + 1 ?> of <?= count($order) ?></p>
            <h1 id="obsTitle"><?= h($mod->title()) ?></h1>
        </header>

        <section class="it-ob-conversation">
            <div class="it-ob-penny-row">
                <?php
                // Penny's photo and brain (owner, 2026-10-20): what she learns here joins her brain. On the screen
                // where "About you" is finished, the brain is drawn without that fact; the gold triangle flies in
                // and the brain redraws with it (onboarding-about.js).
                $brain = null; $arrive = null;
                try { $brain = (new PennyBrainService($db))->learned(); } catch (Throwable $e) { $brain = null; }
                if ($brain && $key === 'name' && $fresh !== null && $fresh !== false) {
                    foreach ($brain['parts'] as $i => $p) {
                        if (($p['key'] ?? '') === 'person:' . $uid) { $arrive = $p; unset($brain['parts'][$i]); $brain['units']--; break; }
                    }
                    $brain['parts'] = array_values($brain['parts']);
                }
                ?>
                <div class="it-ob-penny-photo mw-head-photo">
                    <img src="/crm/img/heads/penny.jpg" alt="" width="64" height="64">
                    <?php if ($brain): ?>
                    <button type="button" class="mw-brain" id="obPennyBrain" data-head="Penny" data-no-page aria-label="Penny's brain: <?= (int)$brain['units'] ?> things learned"
                            data-empty="Nothing learned yet. Every answer you give teaches her something."
                            data-teach="She glows brighter the more often she's right first time"
                            data-units="<?= (int)$brain['units'] ?>" data-bright="0.5"
                            data-parts="<?= h(json_encode($brain['parts'])) ?>"
                            <?= $arrive ? 'data-arrive="' . h(json_encode($arrive)) . '"' : '' ?>
                            data-since="<?= h(!empty($brain['since']) ? date('M j, Y', strtotime($brain['since'])) : '') ?>"><canvas></canvas></button>
                    <?php endif; ?>
                </div>
                <div class="it-ob-penny-content">
                    <?php if ($key === 'name'): $kn = $mod->known($ctx); ?>
                    <div class="it-ob-knowledge" id="obAboutGlyph" data-part="<?= h($part) ?>" data-locked="<?= h(implode(',', array_map('intval', $kn))) ?>" data-fresh="<?= $fresh === null || $fresh === false ? '' : (int)$fresh ?>"></div>
                    <?php endif; ?>
                    <p class="it-ob-penny-line"><?php
                        if ($key === 'name') {
                            $who = $call !== '' ? $call . ', ' : '';
                            echo h(['name' => $mod->question($ctx), 'reach' => $who . 'how should we reach you?',
                                    'quiet' => $who . 'when shouldn\'t we bother you?', 'all' => 'Thank you' . ($call !== '' ? ', ' . $call : '') . ' — I know how to look after you now.'][$part] ?? $mod->question($ctx));
                        } elseif ($key === 'quickbooks' && $part !== '') {
                            $lines = ['business' => "Here's your business as QuickBooks has it.", 'clients' => 'These are your QuickBooks customers. I\'ll give them to Sam.',
                                      'services' => 'These are your products and services. Otto keeps them in his catalogue.', 'suppliers' => 'These are your suppliers.',
                                      'accounts' => 'I matched your QuickBooks accounts to Ittai\'s.'];
                            echo h($lines[$part]);
                        } else {
                            echo h(($call !== '' && $key !== 'name' ? $call . ', ' . lcfirst($mod->question($ctx)) : $mod->question($ctx)));
                        } ?></p>
                </div>
            </div>

            <?php if ($flash): ?><p class="it-obm-flash<?= empty($flash['ok']) ? ' it-obm-flash-warn' : '' ?>" role="status"><?= h((string)$flash['message']) ?></p><?php endif; ?>

            <div class="it-ob-workspace">
            <?php if ($key === 'name'): $m = $mod->me($ctx); ?>
                <?php if ($part === 'name'): ?>
                <form method="post" class="it-ob-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="name"><input type="hidden" name="do" value="save">
                    <div class="it-ob-field"><label for="ob-first">First name</label><input class="it-ob-input" id="ob-first" name="first" autocomplete="given-name" required value="<?= h((string)($m['first_name'] ?? '')) ?>"></div>
                    <div class="it-ob-field"><label for="ob-last">Last name</label><input class="it-ob-input" id="ob-last" name="last" autocomplete="family-name" value="<?= h((string)($m['last_name'] ?? '')) ?>"></div>
                    <div class="it-ob-field"><label for="ob-pref">What should I call you?</label><input class="it-ob-input" id="ob-pref" name="preferred" autocomplete="nickname" value="<?= h((string)($m['preferred_name'] ?? '')) ?>"><small>Leave it blank to use your first name.</small></div>
                    <div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary">Continue</button></div>
                </form>
                <?php elseif ($part === 'reach'): $ch = (string)($m['contact_channel'] ?? ''); ?>
                <form method="post" class="it-ob-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="name"><input type="hidden" name="do" value="reach">
                    <fieldset class="it-obs-choice">
                        <legend class="it-obs-fact">Pick one</legend>
                        <?php foreach (OnboardingNameModule::CHANNELS as $v => $label): ?>
                            <label class="it-obs-option"><input type="radio" name="channel" value="<?= h($v) ?>"<?= $ch === $v ? ' checked' : '' ?> required> <span><?= h($label) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="it-ob-field"><label for="ob-mobile">Mobile number</label><input class="it-ob-input" id="ob-mobile" name="mobile" type="tel" autocomplete="tel" value="<?= h((string)($m['phone'] ?? '')) ?>"><small>Needed for text messages.</small></div>
                    <div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary">Continue</button><a class="it-ob-button it-ob-button-link" href="step.php?m=name&amp;part=name">Back</a></div>
                </form>
                <?php elseif ($part === 'quiet'): ?>
                <form method="post" class="it-ob-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="name"><input type="hidden" name="do" value="quiet">
                    <div class="it-obs-pair">
                        <div class="it-ob-field"><label for="ob-from">Quiet from</label><input class="it-ob-input" id="ob-from" name="from" type="time" required value="<?= h(substr((string)($m['quiet_start'] ?? '20:00'), 0, 5)) ?>"></div>
                        <div class="it-ob-field"><label for="ob-to">Until</label><input class="it-ob-input" id="ob-to" name="to" type="time" required value="<?= h(substr((string)($m['quiet_end'] ?? '07:00'), 0, 5)) ?>"></div>
                    </div>
                    <label class="it-obs-option"><input type="checkbox" name="weekends" value="1"<?= !empty($m['quiet_weekends']) ? ' checked' : '' ?>> <span>Weekends too</span></label>
                    <div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary">Continue</button><a class="it-ob-button it-ob-button-link" href="step.php?m=name&amp;part=reach">Back</a></div>
                </form>
                <?php else: ?>
                    <div class="it-ob-card"><p class="it-ob-card-title"><?= h($mod->summary($ctx)) ?></p></div>
                    <div class="it-ob-actions">
                        <a class="it-ob-button it-ob-button-primary" href="<?= ($n = $svc->next()) ? 'step.php?m=' . h($n) : 'index.php' ?>">Continue</a>
                        <a class="it-ob-button it-ob-button-link" href="step.php?m=name&amp;part=name">Change something</a>
                    </div>
                <?php endif; ?>

            <?php elseif ($key === 'company'): ?>
                <?php if ($state === 'done'): ?>
                    <div class="it-ob-card"><p class="it-ob-card-title"><?= h($mod->summary($ctx)) ?></p></div>
                    <div class="it-ob-actions">
                        <a class="it-ob-button it-ob-button-primary" href="<?= ($n = $svc->next()) ? 'step.php?m=' . h($n) : 'index.php' ?>">Continue</a>
                        <a class="it-ob-button it-ob-button-link" href="/crm/penny-onboarding_appstack.php">Change it</a>
                    </div>
                <?php else: ?>
                    <div class="it-ob-actions"><a class="it-ob-button it-ob-button-primary" href="/crm/penny-onboarding_appstack.php">Look up my business</a></div>
                <?php endif; ?>

            <?php elseif ($key === 'quickbooks'): ?>
                <?php $answer = $mod->answer($ctx); $connected = $mod->connected($ctx); ?>
                <?php if ($answer === null || ($answer === 'no' && $part === '')): ?>
                    <form method="post" class="it-ob-actions">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="quickbooks">
                        <button class="it-ob-button it-ob-button-primary" name="do" value="yes">Yes, I use QuickBooks</button>
                        <button class="it-ob-button it-ob-button-link" name="do" value="no">No</button>
                    </form>
                <?php elseif (!$connected && !$qob->hasSnapshot()): ?>
                    <p class="it-obs-fact">You sign in at Intuit; Ittai never sees your QuickBooks password.</p>
                    <div class="it-ob-actions"><a class="it-ob-button it-ob-button-primary" href="/crm/accounting/quickbooks.php?connect=1&amp;t=<?= h(rawurlencode($csrf)) ?>">Connect QuickBooks</a></div>
                <?php elseif ($part === ''): ?>
                    <?php $done = $mod->partsDone($ctx); $sums = $qob->partSummaries(); ?>
                    <?php if (!$qob->hasSnapshot()): ?>
                        <form method="post" class="it-ob-actions">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="quickbooks">
                            <button class="it-ob-button it-ob-button-primary" name="do" value="read">Read my QuickBooks</button>
                        </form>
                    <?php else: ?>
                        <ol class="it-obs-parts">
                            <?php foreach (['business' => 'Business details', 'clients' => 'Clients', 'services' => 'Services and prices', 'suppliers' => 'Suppliers', 'accounts' => 'Accounts'] as $p => $label): ?>
                                <li class="<?= in_array($p, $done, true) ? 'it-obs-part-done' : '' ?>"><a href="step.php?m=quickbooks&amp;part=<?= h($p) ?>"><?= h($label) ?></a><?= isset($sums[$p]) ? ' <span>' . h($sums[$p]) . '</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php $first = array_values(array_diff(OnboardingQuickBooksModule::PARTS, $done))[0] ?? null; ?>
                        <div class="it-ob-actions">
                            <?php if ($first): ?><a class="it-ob-button it-ob-button-primary" href="step.php?m=quickbooks&amp;part=<?= h($first) ?>">Start</a>
                            <?php else: ?><a class="it-ob-button it-ob-button-primary" href="<?= ($n = $svc->next()) ? 'step.php?m=' . h($n) : 'index.php' ?>">Continue</a><?php endif; ?>
                        </div>
                        <?php if ($connected):
                            require_once APP_ROOT . '/Modules/QuickBooks/Services/QboSyncService.php';
                            $sending = (new QboSyncService($qconn))->auto(); ?>
                            <div class="it-ob-card">
                                <p class="it-ob-card-title">Send new invoices to QuickBooks?</p>
                                <?php if ($sending['on']): ?>
                                    <p class="it-obs-fact">Sending to QuickBooks from <?= h(date('j M Y', strtotime((string)$sending['from']))) ?></p>
                                <?php else: ?>
                                    <p class="it-obs-fact">From today, make invoices in Ittai, not in QuickBooks. Ittai sends each invoice, its payments and your receipts to QuickBooks for you.</p>
                                <?php endif; ?>
                                <form method="post" class="it-ob-actions">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="quickbooks">
                                    <input type="hidden" name="do" value="send">
                                    <button class="it-ob-button it-ob-button-primary" name="on" value="1">Yes, from today</button>
                                    <button class="it-ob-button it-ob-button-link" name="on" value="0">Not now</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!$qob->canWrite()): ?>
                        <p class="it-obm-flash it-obm-flash-warn">Preview — on the live site nothing is imported yet (Ittai has one business). The import runs on the local test site.</p>
                        <form method="post" class="it-obs-skip">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="quickbooks">
                            <button class="it-ob-button it-ob-button-link" name="do" value="skip">Skip QuickBooks for now</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($part === 'business'): $b = $qob->business(); ?>
                        <dl class="it-obs-facts">
                            <div><dt>Name</dt><dd><?= h($b['name'] ?: '—') ?></dd></div>
                            <div><dt>GST/HST number</dt><dd><?= h($b['gst_number'] ?: 'not in QuickBooks') ?></dd></div>
                            <div><dt>Year starts</dt><dd><?= h($b['fiscal_start'] ?: '—') ?></dd></div>
                        </dl>
                        <details class="it-ob-more"><summary>Show details</summary>
                            <dl class="it-obs-facts"><div><dt>Address</dt><dd><?= h($b['address'] ?: '—') ?></dd></div><div><dt>Phone</dt><dd><?= h($b['phone'] ?: '—') ?></dd></div><div><dt>Usual terms</dt><dd><?= h($b['terms'] ?: '—') ?></dd></div></dl>
                        </details>
                    <?php elseif ($part === 'clients'): $cl = $qob->clients(); ?>
                        <p class="it-obs-big"><?= (int)$cl['new'] ?> new clients</p>
                        <p class="it-obs-fact"><?= (int)$cl['match'] ?> already in Ittai · <?= (int)$cl['properties'] ?> jobs kept with their client · no marketing until they say yes</p>
                        <details class="it-ob-more"><summary>Show details</summary>
                            <ul class="it-obs-list"><?php foreach ($cl['rows'] as $r): ?><li><strong><?= h($r['name']) ?></strong><?= $r['address'] ? ' · ' . h($r['address']) : '' ?> <span><?= h($r['why']) ?></span></li><?php endforeach; ?></ul>
                        </details>
                    <?php elseif ($part === 'services'): $sv = $qob->services(); ?>
                        <p class="it-obs-big"><?= (int)$sv['new'] ?> services</p>
                        <p class="it-obs-fact"><?= (int)$sv['match'] ?> you already have · prices as QuickBooks has them</p>
                        <details class="it-ob-more"><summary>Show details</summary>
                            <ul class="it-obs-list"><?php foreach ($sv['rows'] as $r): ?><li><strong><?= h($r['name']) ?></strong> <?= h($money($r['price'])) ?><?= $r['group'] ? ' <span>' . h($r['group']) . '</span>' : '' ?><?= $r['status'] === 'match' ? ' <span>already in Ittai</span>' : '' ?></li><?php endforeach; ?></ul>
                        </details>
                    <?php elseif ($part === 'suppliers'): $sp = $qob->suppliers(); ?>
                        <p class="it-obs-big"><?= (int)$sp['new'] ?> suppliers</p>
                        <p class="it-obs-fact"><?= (int)$sp['match'] ?> you already have</p>
                        <details class="it-ob-more"><summary>Show details</summary>
                            <ul class="it-obs-list"><?php foreach ($sp['rows'] as $r): ?><li><strong><?= h($r['name']) ?></strong><?= $r['status'] === 'match' ? ' <span>already in Ittai</span>' : '' ?></li><?php endforeach; ?></ul>
                        </details>
                    <?php elseif ($part === 'accounts'): $ac = $qob->accounts(); ?>
                        <p class="it-obs-big"><?= (int)$ac['matched'] ?> of <?= (int)$ac['total'] ?> accounts matched</p>
                        <p class="it-obs-fact">You can check every one on the QuickBooks page.</p>
                        <details class="it-ob-more"><summary>Show details</summary>
                            <ul class="it-obs-list"><?php foreach ($ac['rows'] as $r): ?><li><strong><?= h($r['code'] . ' ' . $r['name']) ?></strong> → <?= h($r['guess'] ?? 'not matched') ?></li><?php endforeach; ?></ul>
                        </details>
                    <?php endif; ?>
                    <form method="post" class="it-ob-actions">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="quickbooks"><input type="hidden" name="part" value="<?= h($part) ?>">
                        <button class="it-ob-button it-ob-button-primary" name="do" value="accept">Looks right</button>
                        <a class="it-ob-button it-ob-button-link" href="step.php?m=quickbooks">Back</a>
                    </form>
                <?php endif; ?>

            <?php elseif ($key === 'terms'): ?>
                <p class="it-obs-fact">
                    <a href="<?= h(OnboardingTermsModule::TERMS_URL) ?>" target="_blank" rel="noopener">Terms of service</a> ·
                    <a href="<?= h(OnboardingTermsModule::PRIVACY_URL) ?>" target="_blank" rel="noopener">Privacy policy</a>
                </p>
                <form method="post" class="it-ob-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="terms">
                    <button class="it-ob-button it-ob-button-primary" name="do" value="accept"><?= $state === 'done' ? 'Accepted — continue' : 'I accept' ?></button>
                </form>

            <?php elseif ($key === 'gst'): $ga = $mod->answer($ctx); $qf = OnboardingQboFacts::read($ctx); $qg = $qf['gst_registered'] ?? null; ?>
                <?php if ($qg !== null && $ga === null): ?>
                    <?php if ($qg === true && $qf['gst_number'] === null && $qf['gst_number_raw'] !== null): ?><p class="it-obs-fact">QuickBooks has <?= h($qf['gst_number_raw']) ?> — I need the 9-digit GST number, so add it below.</p><?php endif; ?>
                    <?php if ($qg === true && $qf['gst_last_filed'] !== null): ?><p class="it-obs-fact">Last filed <?= h(date('j M Y', strtotime($qf['gst_last_filed']))) ?>.</p><?php endif; ?>
                    <?php if ($qg === false): ?>
                    <form method="post" class="it-ob-actions">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="gst"><input type="hidden" name="do" value="answer">
                        <button class="it-ob-button it-ob-button-primary" name="registered" value="no">Yes, I am not registered</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" class="it-ob-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="gst"><input type="hidden" name="do" value="answer">
                    <fieldset class="it-obs-choice">
                        <legend class="it-obs-fact">Pick one</legend>
                        <label class="it-obs-option"><input type="radio" name="registered" value="yes"<?= $ga === 'yes' || ($ga === null && $qg === true) ? ' checked' : '' ?> required> <span>Yes, I am registered</span></label>
                        <label class="it-obs-option"><input type="radio" name="registered" value="no"<?= $ga === 'no' || ($ga === null && $qg === false) ? ' checked' : '' ?>> <span>No, I am not</span></label>
                        <label class="it-obs-option"><input type="radio" name="registered" value="unsure"<?= $ga === 'unsure' ? ' checked' : '' ?>> <span>Not sure</span></label>
                    </fieldset>
                    <div class="it-ob-field"><label for="ob-gst-number">GST number (if registered)</label><input class="it-ob-input" id="ob-gst-number" name="number" autocomplete="off" placeholder="123456789 RT0001" value="<?= h((string)$ctx->one('SELECT gst_registration FROM business_settings WHERE id = 1') ?: (string)($qf['gst_number'] ?? '')) ?>"></div>
                    <div class="it-ob-field"><label for="ob-gst-filing">How often do you file?</label>
                        <select class="it-ob-input" id="ob-gst-filing" name="filing">
                            <option value="">Not sure</option>
                            <?php foreach (OnboardingGstModule::FILING as $v => $label): ?><option value="<?= h($v) ?>"<?= (string)$ctx->setting('gst_filing') === $v ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <p class="it-obs-fact">If you are not registered, Ittai charges and claims no GST.</p>
                    <div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary">Continue</button></div>
                </form>

            <?php elseif ($key === 'yearend'): ?>
                <form method="post" class="it-ob-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="yearend"><input type="hidden" name="do" value="answer">
                    <button class="it-ob-button it-ob-button-primary" name="who" value="accountant">An accountant</button>
                    <button class="it-ob-button it-ob-button-link" name="who" value="self">I do it myself</button>
                </form>

            <?php elseif ($key === 'payroll'): $qf = OnboardingQboFacts::read($ctx); ?>
                <?php if (($qf['payroll_in_qbo'] ?? null) === true && $mod->answer($ctx) === null): ?>
                <form method="post" class="it-ob-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="payroll"><input type="hidden" name="do" value="answer">
                    <input type="hidden" name="app" value="<?= h(OnboardingPayrollModule::QBO_APP) ?>">
                    <button class="it-ob-button it-ob-button-primary" name="how" value="app">Yes, in QuickBooks</button>
                </form>
                <?php endif; ?>
                <form method="post" class="it-ob-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="payroll"><input type="hidden" name="do" value="answer">
                    <div class="it-ob-field"><label for="ob-payroll-app">If you use an app, which one?</label><input class="it-ob-input" id="ob-payroll-app" name="app" maxlength="<?= (int)OnboardingPayrollModule::APP_MAX ?>" autocomplete="off" value="<?= h((string)$ctx->setting('onboarding_payroll_app')) ?>"></div>
                    <div class="it-ob-actions">
                        <button class="it-ob-button it-ob-button-primary" name="how" value="app">I use an app</button>
                        <button class="it-ob-button it-ob-button-link" name="how" value="accountant">My accountant does it</button>
                        <button class="it-ob-button it-ob-button-link" name="how" value="none">No staff on payroll</button>
                    </div>
                </form>

            <?php elseif ($key === 'books'): ?>
                <form method="post" class="it-ob-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="books">
                    <button class="it-ob-button it-ob-button-primary" name="do" value="ack">Got it</button>
                </form>

            <?php elseif ($key === 'accountant'): ?>
                <?php if ($invite): ?>
                    <div class="it-ob-card">
                        <p class="it-ob-card-title">A login is ready for <?= h($invite['name']) ?>.</p>
                        <p class="it-obs-fact">Send them this link yourself — Ittai doesn't email it. It works until <?= h(date('M j, g:i a', strtotime((string)$invite['expires']))) ?>.</p>
                        <label class="it-obs-fact" for="ob-link">Their link</label>
                        <input class="it-ob-input" id="ob-link" readonly value="<?= h($invite['link']) ?>">
                    </div>
                    <div class="it-ob-actions"><a class="it-ob-button it-ob-button-primary" href="<?= ($n = $svc->next()) ? 'step.php?m=' . h($n) : 'index.php' ?>">Continue</a></div>
                <?php elseif ($state === 'done'): ?>
                    <div class="it-ob-card"><p class="it-ob-card-title"><?= h($mod->summary($ctx)) ?></p></div>
                    <div class="it-ob-actions"><a class="it-ob-button it-ob-button-primary" href="<?= ($n = $svc->next()) ? 'step.php?m=' . h($n) : 'index.php' ?>">Continue</a><a class="it-ob-button it-ob-button-link" href="/crm/ledger/invite.php">Manage accountant logins</a></div>
                <?php else: ?>
                    <form method="post" class="it-ob-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="accountant"><input type="hidden" name="do" value="invite">
                        <div class="it-ob-field"><label for="ob-acc-name">Their name</label><input class="it-ob-input" id="ob-acc-name" name="name" required autocomplete="off"></div>
                        <div class="it-ob-field"><label for="ob-acc-email">Their email</label><input class="it-ob-input" id="ob-acc-email" name="email" type="email" required autocomplete="off"></div>
                        <div class="it-ob-actions"><button class="it-ob-button it-ob-button-primary">Make their login</button></div>
                    </form>
                <?php endif; ?>

            <?php elseif ($key === 'done'): ?>
                <form method="post" class="it-ob-actions">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="done">
                    <button class="it-ob-button it-ob-button-primary" name="do" value="finish">Finish</button>
                    <a class="it-ob-button it-ob-button-link" href="index.php">See the map</a>
                </form>
            <?php endif; ?>

            <?php if ($mod->skippable() && $state !== 'done' && !($key === 'quickbooks' && $part !== '')): ?>
                <form method="post" class="it-obs-skip">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="m" value="<?= h($key) ?>">
                    <button class="it-ob-button it-ob-button-link" name="do" value="skip">Skip this for now</button>
                </form>
            <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
