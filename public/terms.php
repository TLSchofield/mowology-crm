<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// DRAFT — not legal advice. Bracketed items [LIKE THIS] must be settled with a lawyer
// before the sign-in gate is switched on (see migration 1381: the gate stays off until a
// terms_versions row is activated). TERMS_VERSION must match that row's version.
const TERMS_VERSION   = '2026-10-25-draft';
const TERMS_EFFECTIVE = 'DRAFT — [effective date]';

$pageTitle       = 'Terms of Service | Ittai';
$pageDescription = 'The terms that apply when your business uses Ittai.';
$activeNav       = '';
// Linked directly rather than via master.css: master.css differs on production (diff live
// before ever redeploying it), so this page does not depend on that bundle.
$extraHead       = '<link rel="stylesheet" href="/assets/css/pages/terms.css">';
?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

<div class="terms-hero">
    <div class="container">
        <h1>Terms of Service</h1>
        <p class="terms-updated">Version <?= htmlspecialchars(TERMS_VERSION, ENT_QUOTES) ?> &bull; Effective <?= htmlspecialchars(TERMS_EFFECTIVE, ENT_QUOTES) ?></p>
    </div>
</div>

<div class="container">
<div class="terms-layout">

    <nav class="terms-toc" aria-label="Terms sections">
        <div class="terms-toc-title">Contents</div>
        <a href="#agreement">1. The agreement</a>
        <a href="#accounts">2. Accounts</a>
        <a href="#fees">3. Fees</a>
        <a href="#your-data">4. Your data</a>
        <a href="#use">5. Acceptable use</a>
        <a href="#messaging">6. Messages to your clients</a>
        <a href="#ai">7. AI features</a>
        <a href="#advice">8. No professional advice</a>
        <a href="#third-parties">9. Third-party services</a>
        <a href="#beta">10. Beta features</a>
        <a href="#warranty">11. No warranty</a>
        <a href="#liability">12. Limit of liability</a>
        <a href="#indemnity">13. Indemnity</a>
        <a href="#ending">14. Ending the service</a>
        <a href="#changes">15. Changes</a>
        <a href="#law">16. Governing law</a>
        <a href="#contact">17. Contact</a>
    </nav>

    <main>
        <section class="terms-section" id="agreement">
            <h2>1. The agreement</h2>
            <p>These terms are between <strong>[Ittai legal entity name]</strong> (&ldquo;Ittai&rdquo;, &ldquo;we&rdquo;) and the business that signs up (&ldquo;you&rdquo;). By signing in after we ask you to accept them, or by using Ittai, you agree to them on behalf of your business and confirm you have authority to do so.</p>
            <p>Some features have short add-on terms (payments, accounting, messaging). Where an add-on conflicts with these terms for that feature, the add-on wins.</p>
        </section>

        <section class="terms-section" id="accounts">
            <h2>2. Accounts</h2>
            <p>The account owner is responsible for everything done under the account, including by staff and by anyone given access. Give us accurate information, keep passwords private, and tell us promptly if you think an account has been misused. You decide who on your team has access and at what level.</p>
        </section>

        <section class="terms-section" id="fees">
            <h2>3. Fees</h2>
            <ul>
                <li>Plans are billed in advance and renew automatically until cancelled.</li>
                <li>Fees are not refunded for partial periods or unused time, except where the law requires it.</li>
                <li>We will give at least 14 days&rsquo; notice before a price change takes effect.</li>
                <li>Taxes are extra. Overdue amounts may be collected with reasonable collection costs.</li>
            </ul>
        </section>

        <section class="terms-section" id="your-data">
            <h2>4. Your data</h2>
            <p>Your clients, jobs, invoices, photos and records stay yours. You give us permission to store, process and display them to run Ittai for you, and to meet legal obligations.</p>
            <p>Ittai may also learn from activity across businesses in an aggregated, de-identified form (for example, which products suppliers carry, or common price ranges) so that suggestions improve for everyone. We do not share your clients&rsquo; personal information or your private records with other businesses through this.</p>
            <p><strong>Keep your own backups.</strong> We take reasonable care to protect and back up the system, but Ittai is not your official record-keeping archive. Export what the law requires you to keep (for example tax records) on your own schedule.</p>
            <p>You are responsible for having the right to put your clients&rsquo; information into Ittai and for handling their privacy requests under the laws that apply to you.</p>
        </section>

        <section class="terms-section" id="use">
            <h2>5. Acceptable use</h2>
            <p>Do not use Ittai to break the law, send spam, harass anyone, upload harmful code, attempt to access other businesses&rsquo; data, scrape or resell the service, or build a competing product from it. We may remove content or suspend access to protect the service or other users.</p>
        </section>

        <section class="terms-section" id="messaging">
            <h2>6. Messages to your clients</h2>
            <p>When you send email or text messages through Ittai, you are the sender. You are responsible for having each recipient&rsquo;s consent where the law requires it (including Canada&rsquo;s anti-spam law, CASL), for honouring opt-outs, and for the content of your messages. Mobile carriers may block or delay messages; we cannot guarantee delivery.</p>
        </section>

        <section class="terms-section" id="ai">
            <h2>7. AI features</h2>
            <p>Ittai includes AI assistants that read information, draft messages, suggest entries and, where you switch it on, take actions for you. AI can be wrong, can misread documents, and can act on incomplete information.</p>
            <ul>
                <li>You are responsible for checking AI output before relying on it, especially invoices, amounts, messages to clients, and accounting entries.</li>
                <li>Actions an assistant takes in your account under your settings are treated as your actions.</li>
                <li>Some changes cannot be reversed automatically. Use the approval settings that suit your risk.</li>
                <li>We do not promise that an assistant will always follow your instructions or stay within the limits you set.</li>
            </ul>
        </section>

        <section class="terms-section" id="advice">
            <h2>8. No professional advice</h2>
            <p>Ittai, including its bookkeeping features, is a tool. It is not legal, tax, accounting, payroll or financial advice, and we are not your accountant. Tax rates, categories, remittances and filings remain your responsibility; confirm anything important with a qualified professional.</p>
        </section>

        <section class="terms-section" id="third-parties">
            <h2>9. Third-party services</h2>
            <p>Ittai connects to services we do not control, such as payment processors, accounting software, mapping, email and phone carriers. Their own terms apply, we are not responsible for their availability or conduct, and you are responsible for the accounts you connect.</p>
        </section>

        <section class="terms-section" id="beta">
            <h2>10. Beta features</h2>
            <p>Features marked beta or early access are provided as they are, may change or end at any time, and may contain errors.</p>
        </section>

        <section class="terms-section" id="warranty">
            <h2>11. No warranty</h2>
            <p>To the fullest extent the law allows, Ittai is provided &ldquo;as is&rdquo; and &ldquo;as available&rdquo;. We do not promise it will be uninterrupted, error-free, or fit for a particular purpose, or that results (including AI output, route estimates and totals) will be accurate. You bear the risk of loss from outages or errors, which is why backups matter.</p>
        </section>

        <section class="terms-section" id="liability">
            <h2>12. Limit of liability</h2>
            <p>To the fullest extent the law allows, we are not liable for indirect, incidental, special or consequential losses, or for lost profits, revenue, data or goodwill. Our total liability for all claims relating to Ittai is limited to <strong>[the greater of $100 or the fees you paid us in the 12 months before the claim &mdash; confirm with counsel]</strong>. Nothing here limits liability that the law does not allow to be limited.</p>
        </section>

        <section class="terms-section" id="indemnity">
            <h2>13. Indemnity</h2>
            <p>You will defend and cover us against claims, losses and costs arising from the content you put into Ittai, messages you send, your clients&rsquo; data you handle, your breach of these terms or the law, and disputes between you and your clients or staff &mdash; except to the extent we caused the loss through our own fraud or wilful misconduct.</p>
        </section>

        <section class="terms-section" id="ending">
            <h2>14. Ending the service</h2>
            <p>You can cancel at any time from your account or by contacting us; cancelling does not cancel amounts already owing. We may suspend or end access for non-payment, breach of these terms, legal requirement, or risk to the service. After ending, you will have <strong>[30] days</strong> to export your data; after that we may delete it, subject to legal retention duties. Sections that by their nature should survive (fees owing, disclaimers, liability, indemnity, governing law) do.</p>
        </section>

        <section class="terms-section" id="changes">
            <h2>15. Changes</h2>
            <p>We may update these terms. For material changes we will notify you in the app or by email and ask you to accept the new version; continuing to use Ittai after that means you accept it. We record which version you accepted and when.</p>
        </section>

        <section class="terms-section" id="law">
            <h2>16. Governing law</h2>
            <p>These terms are governed by the laws of <strong>[British Columbia and the federal laws of Canada]</strong>. Disputes will be handled in <strong>[the courts of British Columbia / binding arbitration &mdash; counsel to decide]</strong>. Each side tries first to resolve a dispute informally for 60 days after written notice.</p>
        </section>

        <section class="terms-section" id="contact">
            <h2>17. Contact</h2>
            <div class="terms-contact-card">
                <strong>[Ittai legal entity name]</strong><br>
                <a href="mailto:office@mowology.ca">office@mowology.ca</a><br>
                <a href="tel:7788469273">(778) 846-9273</a>
            </div>
        </section>
    </main>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
