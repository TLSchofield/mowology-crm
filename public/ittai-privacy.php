<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Ittai's own privacy policy (the app), separate from privacy.php (Mowology's landscaping clients).
// Linked from the Intuit app listing as the privacy policy URL (2026-11-06). Not reviewed by a lawyer.
// The services named in section 6 are the ones the code calls — re-check them when one is added or dropped.
const ITTAI_PRIVACY_EFFECTIVE = 'November 6, 2026';
const ITTAI_LEGAL_ENTITY      = 'Mowology Lawns &amp; Landscapes Ltd';

$pageTitle       = 'Privacy Policy | Ittai';
$pageDescription = 'How Ittai collects, uses and protects business and personal information, including data from connected QuickBooks companies.';
$activeNav       = '';
// Same page styles as terms.php (master.css differs on production, so not via the bundle).
$extraHead       = '<link rel="stylesheet" href="/assets/css/pages/terms.css">';
?>
<?php require __DIR__ . '/includes/head.php'; ?>
<?php require __DIR__ . '/includes/header.php'; ?>

<div class="terms-hero">
    <div class="container">
        <h1>Ittai Privacy Policy</h1>
        <p class="terms-updated">Effective <?= ITTAI_PRIVACY_EFFECTIVE ?> &bull; <?= ITTAI_LEGAL_ENTITY ?> (Vancouver, BC)</p>
    </div>
</div>

<div class="container">
<div class="terms-layout">

    <nav class="terms-toc" aria-label="Privacy policy sections">
        <div class="terms-toc-title">Contents</div>
        <a href="#who">1. Who we are</a>
        <a href="#collect">2. What we collect</a>
        <a href="#quickbooks">3. QuickBooks</a>
        <a href="#use">4. How we use it</a>
        <a href="#never">5. What we never do</a>
        <a href="#providers">6. Service providers</a>
        <a href="#security">7. Security</a>
        <a href="#retention">8. Keeping and deleting data</a>
        <a href="#rights">9. Your rights</a>
        <a href="#changes">10. Changes</a>
        <a href="#contact">11. Contact</a>
    </nav>

    <main>
        <section class="terms-section" id="who">
            <h2>1. Who we are</h2>
            <p>Ittai is business software for trade and field-service businesses: scheduling, crew time, quotes, invoices, receipts and bookkeeping. It is operated by <strong><?= ITTAI_LEGAL_ENTITY ?></strong> of Vancouver, British Columbia, Canada (&ldquo;we&rdquo;, &ldquo;us&rdquo;).</p>
            <p>This policy explains what information Ittai handles and why, following Canada&rsquo;s <em>Personal Information Protection and Electronic Documents Act</em> (PIPEDA) and British Columbia&rsquo;s <em>Personal Information Protection Act</em>.</p>
            <p>A business that uses Ittai (&ldquo;you&rdquo;) decides what goes into its account, including its own clients&rsquo; details. For that information we act on the business&rsquo;s behalf, and the business answers to its own clients for it.</p>
        </section>

        <section class="terms-section" id="collect">
            <h2>2. What we collect</h2>
            <ul>
                <li><strong>Account details</strong> &mdash; names, email addresses, phone numbers and sign-in details of the owner and the staff they add.</li>
                <li><strong>Business records you put in</strong> &mdash; clients and properties, quotes, jobs, schedules, invoices, payments, receipts and their photos, notes and messages.</li>
                <li><strong>Crew time and location</strong> &mdash; clock-in and job times, and a phone&rsquo;s location while a crew member is clocked in, when the business switches this on and the person allows it on their phone.</li>
                <li><strong>Data from services you connect</strong> &mdash; for example QuickBooks Online (section 3) or a payment processor.</li>
                <li><strong>Technical information</strong> &mdash; device type, app version, error reports and sign-in records, used to keep the service secure and working.</li>
            </ul>
        </section>

        <section class="terms-section" id="quickbooks">
            <h2>3. QuickBooks Online</h2>
            <p>If you connect a QuickBooks Online company, you sign in with Intuit and choose to allow Ittai access. Ittai then:</p>
            <ul>
                <li><strong>Reads</strong> your company&rsquo;s customers, products and services, invoices and payments, accounts, tax codes, suppliers and recurring invoices, so it can set up your Ittai account from them and keep the two in step.</li>
                <li><strong>Writes</strong>, only after you switch sending on: the invoices and payments you make in Ittai, and the receipts you approve (with their supplier, category, tax and photo).</li>
            </ul>
            <p>QuickBooks data is used only to provide Ittai to your business. It is not sold, not shared with other businesses, and not used to train AI models.</p>
            <p>You can disconnect at any time from the QuickBooks page in Ittai, which revokes our access with Intuit and deletes the access tokens we held. You can also remove Ittai from your QuickBooks company under <em>Apps</em>. Disconnecting does not delete records already copied into your Ittai account; ask us (section 11) if you want those deleted too.</p>
        </section>

        <section class="terms-section" id="use">
            <h2>4. How we use information</h2>
            <ul>
                <li>To run Ittai for your business: schedules, crew apps, quotes, invoices, receipts, bookkeeping and the messages you send to your clients.</li>
                <li>To power the AI assistants inside Ittai, which read your records to draft messages, suggest entries and read receipts. You stay in control of what they may do.</li>
                <li>To support you, fix problems, prevent misuse and keep the service secure.</li>
                <li>To improve suggestions using aggregated, de-identified patterns across businesses (for example, which products a supplier carries). This never includes your clients&rsquo; personal information or your private records.</li>
                <li>To meet legal obligations.</li>
            </ul>
        </section>

        <section class="terms-section" id="never">
            <h2>5. What we never do</h2>
            <ul>
                <li>We do not sell personal information or business data.</li>
                <li>We do not show your records to other businesses.</li>
                <li>We do not use your data, or data from your connected accounts, for advertising.</li>
            </ul>
        </section>

        <section class="terms-section" id="providers">
            <h2>6. Service providers</h2>
            <p>We use a small number of providers to run Ittai. They receive only what their job needs and must protect it:</p>
            <ul>
                <li><strong>Canadian Web Hosting</strong> &mdash; servers and databases, located in Canada.</li>
                <li><strong>Intuit (QuickBooks Online)</strong> &mdash; only if you connect it (section 3).</li>
                <li><strong>Anthropic</strong> &mdash; the AI assistants.</li>
                <li><strong>Google</strong> &mdash; maps and routes, reading receipt images, Android notifications.</li>
                <li><strong>Apple</strong> &mdash; iPhone notifications.</li>
                <li><strong>Stripe</strong> &mdash; card payments, if you take them through Ittai.</li>
                <li><strong>Pl@ntNet</strong> &mdash; identifying plants from photos, when used.</li>
            </ul>
            <p>Some of these providers process information outside Canada, mainly in the United States, where it may be accessible to authorities under local law.</p>
        </section>

        <section class="terms-section" id="security">
            <h2>7. Security</h2>
            <p>Connections are encrypted. Each business&rsquo;s records are kept in its own database. Access tokens for connected services such as QuickBooks are stored encrypted. Staff access is limited by role, and we keep records of sign-ins. No system is perfectly secure; if a breach puts you at real risk of significant harm, we will tell you and the Privacy Commissioner as the law requires.</p>
        </section>

        <section class="terms-section" id="retention">
            <h2>8. Keeping and deleting data</h2>
            <p>We keep your business&rsquo;s records while your account is open. If you close it, you have 30 days to export your data; after that we delete it, except what we must keep by law (for example, our own tax records of what you paid us).</p>
        </section>

        <section class="terms-section" id="rights">
            <h2>9. Your rights</h2>
            <p>You can ask to see, correct or delete personal information we hold about you, or withdraw consent, by writing to us (section 11). We reply within 30 days. If a business&rsquo;s client asks us about their information, we pass the request to that business, which decides on it. If you are not satisfied, you can contact the Office of the Privacy Commissioner of Canada or the Office of the Information and Privacy Commissioner for British Columbia.</p>
        </section>

        <section class="terms-section" id="changes">
            <h2>10. Changes</h2>
            <p>If we change this policy in a way that matters, we will tell account owners in the app or by email before the change takes effect. The effective date at the top shows the current version.</p>
        </section>

        <section class="terms-section" id="contact">
            <h2>11. Contact</h2>
            <div class="terms-contact-card">
                <strong><?= ITTAI_LEGAL_ENTITY ?></strong> &mdash; Privacy<br>
                <a href="mailto:office@mowology.ca">office@mowology.ca</a><br>
                <a href="tel:7788469273">(778) 846-9273</a>
            </div>
        </section>
    </main>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
