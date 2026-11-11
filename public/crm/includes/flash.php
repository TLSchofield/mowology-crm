<?php
/**
 * Show and clear the one-shot $_SESSION['flash_success'] / ['flash_error'] messages.
 * Include right after appstack_head.php. (2026-10-14: several pages set these and nothing
 * displayed them, so a refused timesheet approval looked like it had worked.)
 */
foreach (['flash_error' => 'danger', 'flash_success' => 'success'] as $__k => $__cls) {
    if (!empty($_SESSION[$__k])) {
        echo '<div class="alert alert-' . $__cls . ' mb-3" role="alert">' . htmlspecialchars((string)$_SESSION[$__k]) . '</div>';
        unset($_SESSION[$__k]);
    }
}
unset($__k, $__cls);
