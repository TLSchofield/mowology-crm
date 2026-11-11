<?php
/**
 * Search — the crew's "find anything" tab (Android / web). Same service, ranking and property
 * page as the iOS Search tab: today's stops first, then nearest; every hit is a property you can
 * act on (navigate, call, open today's visit, add a visit).
 *
 * The office's ⌘K Spotlight (global-search.js) is a different tool: it spans quotes and invoices
 * and ranks alphabetically. This one is built for someone standing in a driveway.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
requirePermission('clients.view');
$user = getCurrentUser();

$pageTitle  = 'Search';
$activePage = 'search';
$canAddJob  = userHasAnyPermission(['jobs.create_field', 'jobs.edit']);
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="mw-fs" id="mwFieldSearch"
     data-csrf="<?php echo h(generateCSRFToken()); ?>"
     data-can-add="<?php echo $canAddJob ? '1' : '0'; ?>">

    <div class="mw-fs-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="mwFsInput" class="mw-fs-input" placeholder="Client, address, building, phone…"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" aria-label="Search">
        <button type="button" class="mw-fs-clear" id="mwFsClear" aria-label="Clear" hidden>&times;</button>
    </div>

    <div id="mwFsStatus" class="mw-fs-status" role="status" aria-live="polite"></div>
    <div id="mwFsList"></div>

    <!-- Property page (slides over the list) -->
    <div id="mwFsDetail" class="mw-fs-detail" hidden></div>
</div>

<script src="/crm/js/mw-field-search.js?v=20260921a" defer></script>

<?php include 'includes/appstack_footer.php'; ?>
