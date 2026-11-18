<?php
// ittai.ca/quickbooks/ — the Intuit app listing's launch and connect/reconnect URL (2026-11-06).
require dirname(__DIR__) . '/legal-page.php';
ittaiPageStart('Connect Ittai to QuickBooks Online', 'For businesses that use Ittai');
?>
<main class="it-page">
  <section class="terms-section">
    <h2>Connect or reconnect</h2>
    <ol>
      <li>Sign in to Ittai at your business&rsquo;s own address (for example <em>yourbusiness.ittai.ca</em>) or in the Ittai app.</li>
      <li>Open your setup map and choose <strong>QuickBooks</strong>.</li>
      <li>Press <strong>Connect to QuickBooks</strong>, sign in with Intuit, choose your company and allow Ittai.</li>
    </ol>
    <p>Connecting needs someone allowed to change your Ittai business settings, usually the owner. Reconnecting works the same way.</p>
  </section>
  <section class="terms-section">
    <h2>What Ittai does with QuickBooks</h2>
    <p>Ittai reads your customers, products and services, invoices, accounts, tax codes, suppliers and recurring invoices to set up your account. It sends invoices, payments and approved receipts back only after you switch sending on. Details are in our <a href="/privacy/">privacy policy</a>.</p>
  </section>
  <section class="terms-section">
    <h2>Disconnect</h2>
    <p>Press <strong>Disconnect</strong> on the QuickBooks page in Ittai, or remove Ittai from your QuickBooks company under <em>Apps</em>. Either one stops Ittai reading from or sending to QuickBooks.</p>
  </section>
  <div class="terms-contact-card">Need help? <a href="mailto:office@mowology.ca">office@mowology.ca</a> &middot; <a href="tel:7788469273">(778) 846-9273</a></div>
</main>
<?php ittaiPageEnd();
