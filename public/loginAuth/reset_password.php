<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/signin-chrome.php';   // Ittai v1.2 sign-in look (2026-10-23)
$__bizName = itSigninBizName();

// If already logged in, redirect to app
if (isLoggedIn()) {
    header('Location: /crm/app-launch.php');
    exit();
}

$message = '';
$messageType = '';
$validToken = false;

// Always read token from GET first, then POST (so form submit still knows the token)
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$token = is_string($token) ? trim($token) : '';

$tokenData = null;
$db = null;

if ($token === '') {
    $message = 'Invalid or missing reset token.';
    $messageType = 'error';
} else {
    try {
        $db = getDB();

        // Check if token is valid and not expired
        $stmt = $db->prepare("
            SELECT prt.user_id, prt.expires_at, u.email, u.full_name
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([hash('sha256', $token)]);
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tokenData) {
            $validToken = true;
        } else {
            $message = 'This reset link is invalid or has expired. Please request a new one.';
            $messageType = 'error';
        }
    } catch (Throwable $e) {
        error_log("Reset token check error: " . $e->getMessage());
        $message = 'An error occurred. Please try again.';
        $messageType = 'error';
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken && $tokenData && $db) {
    if (isset($_POST['csrf_token']) && verifyCSRFToken((string)$_POST['csrf_token'])) {

        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($password === '' || $confirmPassword === '') {
            $message = 'Please fill in both password fields.';
            $messageType = 'error';
        } elseif (strlen($password) < 12) {
            $message = 'Password must be at least 12 characters long.';
            $messageType = 'error';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                // Use the link up FIRST, atomically: two submits of the same link at once both passed the check above
                // and both changed the password (Codex review 2026-10-16). Only the one that deletes it goes on.
                $use = $db->prepare("DELETE FROM password_reset_tokens WHERE token = ? AND user_id = ? AND expires_at > NOW()");
                $use->execute([hash('sha256', $token), (int)$tokenData['user_id']]);
                if ($use->rowCount() !== 1) {
                    $db->rollBack();
                    throw new RuntimeException('reset link already used');
                }

                // Update password
                $hashedPassword = hashPassword($password);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
                $stmt->execute([$hashedPassword, (int)$tokenData['user_id']]);

                // Delete the used token (or all tokens for that user)
                $stmt = $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? LIMIT 10");
                $stmt->execute([(int)$tokenData['user_id']]);

                $db->commit();

                // Log activity
                logActivity((int)$tokenData['user_id'], null, 'Password reset completed', "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                $message = 'Your password has been reset successfully! You can now log in with your new password.';
                $messageType = 'success';
                $validToken = false; // Hide the form after success

            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log("Password reset error: " . $e->getMessage());
                $message = 'An error occurred while resetting your password. Please try again.';
                $messageType = 'error';
            }
        }
    } else {
        $message = 'Invalid request. Please try again.';
        $messageType = 'error';
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php itSigninHead('Reset password - ' . $__bizName); ?>
</head>
<body class="<?= itSigninBodyClass() ?>">
<main class="it-signin-wrap">
    <?php itSigninLockup(); ?>

    <div class="it-signin-card">
        <h1 class="it-signin-title">Set a new password</h1>
        <p class="it-signin-sub">for <strong><?= htmlspecialchars($__bizName) ?></strong></p>

        <?php if ($message): ?>
            <div class="it-signin-msg it-signin-msg--<?php echo $messageType === 'success' ? 'success' : 'error'; ?>" role="<?php echo $messageType === 'success' ? 'status' : 'alert'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="it-signin-note" id="pw-rules">At least 12 characters. A mix of words, numbers and symbols is strongest.</div>

                <div class="it-signin-field">
                    <label class="it-signin-label" for="password">New password</label>
                    <input type="password" id="password" name="password" class="it-signin-input"
                           required minlength="12" autocomplete="new-password" aria-describedby="pw-rules">
                </div>

                <div class="it-signin-field">
                    <label class="it-signin-label" for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="it-signin-input"
                           required minlength="12" autocomplete="new-password">
                </div>

                <button type="submit" class="it-signin-button">Save new password</button>
            </form>

            <div class="it-signin-links">
                <a href="forgot_password.php" class="it-signin-link">Request a new reset link</a>
            </div>
        <?php else: ?>
            <div class="it-signin-links">
                <a href="login.php" class="it-signin-link">Go to sign in</a>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
