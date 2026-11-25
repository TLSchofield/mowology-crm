<?php
// ittai.ca/quickbooks/disconnected/ — the Intuit app listing's disconnect URL (2026-11-06).
require dirname(__DIR__, 2) . '/legal-page.php';
ittaiPageStart('Ittai is disconnected from QuickBooks', 'QuickBooks Online');
?>
<main class="it-page">
  <section class="terms-section">
    <p>Ittai no longer reads from or sends anything to your QuickBooks company, and the access keys Ittai held for it are deleted.</p>
    <p>Records already copied into your Ittai account stay there so your business keeps running. If you want those deleted too, write to <a href="mailto:office@mowology.ca">office@mowology.ca</a>.</p>
    <p>Changed your mind? <a href="/quickbooks/">Reconnect Ittai to QuickBooks</a>.</p>
  </section>
</main>
<?php ittaiPageEnd();
