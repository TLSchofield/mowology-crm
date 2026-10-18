<?php
/** Penny onboarding — Step 1: business identity. */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Meet Penny';
$activePage = 'dashboard';
$csrfToken = generateCSRFToken();
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">' . "\n"
    . '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
    . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
    . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">' . "\n"
    . '<link href="/crm/css/penny-onboarding.css?v=20261017b" rel="stylesheet">';

include __DIR__ . '/includes/appstack_head.php';
?>

<main class="it-onboard" id="pennyOnboarding" aria-labelledby="itObTitle">
    <div class="it-ob-shell">
        <header class="it-ob-header">
            <p class="it-ob-kicker">Penny onboarding</p>
            <h1 id="itObTitle">Meet Penny</h1>
            <p>Step 1 of your business setup</p>
        </header>
        <section class="it-ob-conversation" aria-label="Business identity setup">
            <div class="it-ob-penny-row">
                <div class="it-ob-avatar" aria-hidden="true">P</div>
                <div class="it-ob-penny-content">
                    <div id="itObPennyLine" class="it-ob-penny-line" aria-live="polite" aria-atomic="true"></div>
                    <div id="itObKnowledge" class="it-ob-knowledge" hidden></div>
                </div>
            </div>
            <div id="itObWorkspace" class="it-ob-workspace"></div>
            <p id="itObError" class="it-ob-error" role="alert" hidden></p>
        </section>
    </div>
</main>

<script src="/crm/js/penny-onboarding.js?v=20261017b"></script>
<?php include __DIR__ . '/includes/appstack_footer.php'; ?>
