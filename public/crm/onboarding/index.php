<?php
/**
 * The onboarding map (owner, 2026-10-20: "an onboarding map that we can keep on the website in admin").
 * Every module in its order, its state, who owns it, one line — and, for settings editors, Move up / Move
 * down / Skip. Thin controller: OnboardingService.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();
$user = getCurrentUser();
require_once APP_ROOT . '/Modules/Onboarding/Services/OnboardingService.php';

$canEdit = function_exists('userHasPermission') && userHasPermission('settings.edit');
$svc = new OnboardingService(new OnboardingContext(getDB(), (int)($user['id'] ?? 0)));
$flash = $_SESSION['ob_flash'] ?? null;
unset($_SESSION['ob_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit || !verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['ob_flash'] = ['ok' => false, 'message' => $canEdit ? 'The page was open too long. Try again.' : 'Only someone who can change settings can rearrange onboarding.'];
    } else {
        $key = (string)($_POST['key'] ?? '');
        $do = (string)($_POST['do'] ?? '');
        $_SESSION['ob_flash'] = $do === 'up' ? $svc->move($key, -1) : ($do === 'down' ? $svc->move($key, 1)
            : ($do === 'skip' ? $svc->skip($key, true) : ($do === 'unskip' ? $svc->skip($key, false) : ['ok' => false, 'message' => 'Nothing to do.'])));
    }
    header('Location: index.php'); exit;
}

$rows = $svc->map();
$progress = $svc->progress();
$next = $svc->next();
$csrf = generateCSRFToken();
$words = ['done' => 'Done', 'next' => 'Next', 'todo' => 'To do', 'skipped' => 'Skipped', 'not_needed' => 'Not needed'];

$pageTitle = 'Onboarding';
$activePage = 'settings';
$extraHead = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
    . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">';
foreach (['penny-onboarding.css', 'ittai-onboarding.css'] as $__css) {
    $extraHead .= '<link href="/crm/css/' . $__css . '?v=' . (int)@filemtime(dirname(__DIR__) . '/css/' . $__css) . '" rel="stylesheet">';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<main class="it-onboard it-obm" aria-labelledby="obmTitle">
    <div class="it-ob-shell">
        <header class="it-ob-header">
            <p class="it-ob-kicker">Onboarding</p>
            <h1 id="obmTitle">Your setup</h1>
            <p><?= (int)$progress['done'] ?> of <?= (int)$progress['total'] ?> steps done</p>
        </header>

        <?php if ($flash): ?><p class="it-obm-flash<?= empty($flash['ok']) ? ' it-obm-flash-warn' : '' ?>" role="status"><?= h((string)$flash['message']) ?></p><?php endif; ?>

        <?php if ($next): ?>
            <p class="it-obm-continue"><a class="it-ob-button it-ob-button-primary" href="step.php?m=<?= h($next) ?>">Continue</a></p>
        <?php endif; ?>

        <ol class="it-obm-path">
            <?php foreach ($rows as $i => $r): ?>
            <li class="it-obm-step it-obm-<?= h($r['state']) ?>">
                <span class="it-obm-marker" aria-hidden="true"><?= $r['state'] === 'done' ? '✓' : (int)($i + 1) ?></span>
                <div class="it-obm-body">
                    <a class="it-obm-title" href="step.php?m=<?= h($r['key']) ?>"><?= h($r['title']) ?></a>
                    <p class="it-obm-line">
                        <span class="it-obm-state"><?= h($words[$r['state']] ?? $r['state']) ?></span> · <?= h($r['owner']) ?><?= $r['summary'] !== '' ? ' · ' . h($r['summary']) : '' ?>
                    </p>
                </div>
                <?php if ($canEdit && !$r['pinned']): ?>
                <form method="post" class="it-obm-tools">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="key" value="<?= h($r['key']) ?>">
                    <button class="it-ob-change" name="do" value="up" aria-label="Move <?= h($r['title']) ?> up"<?= $r['first'] ? ' disabled' : '' ?>>↑</button>
                    <button class="it-ob-change" name="do" value="down" aria-label="Move <?= h($r['title']) ?> down"<?= ($i >= count($rows) - 2) ? ' disabled' : '' ?>>↓</button>
                    <?php if ($r['skippable']): ?>
                        <button class="it-ob-change" name="do" value="<?= $r['state'] === 'skipped' ? 'unskip' : 'skip' ?>"><?= $r['state'] === 'skipped' ? 'Bring back' : 'Skip' ?></button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</main>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
