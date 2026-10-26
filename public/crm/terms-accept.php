<?php
/**
 * Terms of Service acceptance — shown once after sign-in to account owners
 * (admin / manager) while a terms_versions row is active (migration 1381).
 * Self-contained page (like login) so it never sits behind another gate.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
require_once APP_ROOT . '/Modules/Legal/Services/TermsService.php';
requireLogin();
$user = getCurrentUser();

header('Cache-Control: no-store, no-cache, must-revalidate');

$dest  = !empty($user['is_driver']) ? '/crm/driver-portal.php' : '/crm/app-launch.php';
$terms = new TermsService(getDB());
$error = '';

if (!$terms->needsAcceptance((int)$user['id'])) {
    header('Location: ' . $dest);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken((string)$_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($_POST['agree'])) {
        $error = 'Please tick the box to continue.';
    } else {
        try {
            $terms->recordAcceptance(
                (int)$user['id'],
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            header('Location: ' . $dest);
            exit;
        } catch (Throwable $e) {
            // Fail open: never trap someone outside the CRM because the acceptance could not be
            // saved. They are asked again at the next sign-in.
            error_log('terms-accept: could not save acceptance: ' . $e->getMessage());
            header('Location: ' . $dest);
            exit;
        }
    }
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service | Ittai</title>
    <style>
        body { margin:0; font-family: system-ui, -apple-system, sans-serif; background:#E8F3F0; color:#1f2937; }
        .card { max-width:480px; margin:8vh auto; background:#fff; border-radius:12px; padding:28px 24px; box-shadow:0 2px 12px rgba(13,59,46,.12); }
        h1 { font-size:1.35rem; margin:0 0 12px; color:#0D3B2E; }
        p { line-height:1.6; font-size:.95rem; }
        label { display:flex; gap:10px; align-items:flex-start; margin:18px 0; font-size:.95rem; }
        button { width:100%; padding:12px; border:0; border-radius:8px; background:#2D8659; color:#fff; font-size:1rem; font-weight:600; cursor:pointer; }
        .err { background:#fdecea; color:#8a1f11; padding:10px 12px; border-radius:8px; font-size:.9rem; }
        a { color:#2D8659; }
    </style>
</head>
<body>
<div class="card">
    <h1>Terms of Service</h1>
    <p>Before you continue, please read and accept the Terms of Service that apply to your business&rsquo;s use of Ittai.</p>
    <p><a href="/terms.php" target="_blank" rel="noopener">Read the Terms of Service</a></p>
    <?php if ($error !== ''): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
    <?php if (in_array($user['role'], ['admin', 'manager'], true)): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <label><input type="checkbox" name="agree" value="1" required>
            <span>I have read the Terms of Service and agree to them on behalf of my business.</span></label>
        <button type="submit">Accept and continue</button>
    </form>
    <?php else: ?>
    <p>Your account owner needs to accept these terms. You can carry on.</p>
    <p><a href="<?= htmlspecialchars($dest, ENT_QUOTES) ?>">Continue</a></p>
    <?php endif; ?>
</div>
</body>
</html>
