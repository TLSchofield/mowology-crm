<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle       = 'Privacy Policy | Mowology Landscaping';
$pageDescription = 'Mowology\'s privacy policy — how we collect, use, and protect your personal information in compliance with Canadian privacy law (PIPEDA).';
$activeNav       = '';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

    <section class="page-hero">
        <div class="container">
            <h1>Privacy Policy</h1>
            <p>How we handle your personal information</p>
        </div>
    </section>

    <section class="privacy-section">
        <div class="container">
            <div class="privacy-content">

                <p>Mowology (operated by Tim Schofield, Metro Vancouver, BC, Canada) is committed to protecting the personal information of our clients and website visitors. This policy describes what information we collect, how we use it, and your rights under Canada's <em>Personal Information Protection and Electronic Documents Act</em> (PIPEDA).</p>

                <h2>1. Information We Collect</h2>
                <p>We collect personal information only when it is necessary to provide our services. This may include:</p>
                <ul>
                    <li><strong>Contact details</strong> — name, email address, phone number</li>
                    <li><strong>Service address</strong> — property address where landscaping services are performed</li>
                    <li><strong>Account credentials</strong> — if you create a customer portal account (email and hashed password)</li>
                    <li><strong>Service communications</strong> — quotes, invoices, job notes, and messages exchanged through our platform</li>
                    <li><strong>Photos</strong> — images uploaded through our platform or taken on-site to document service visits</li>
                    <li><strong>Usage data</strong> — pages visited, browser type, and approximate location for analytics and security purposes</li>
                </ul>
                <p>We do not collect payment card numbers directly. Payments are processed by a PCI-compliant third-party processor.</p>

                <h2>2. How We Use Your Information</h2>
                <p>Your information is used to:</p>
                <ul>
                    <li>Schedule and deliver landscaping services at your property</li>
                    <li>Issue quotes, invoices, and service reports</li>
                    <li>Communicate with you about appointments, service updates, and account activity</li>
                    <li>Improve our platform and understand how clients use our services</li>
                    <li>Comply with legal obligations</li>
                </ul>
                <p>We will not use your personal information for unrelated purposes without your consent.</p>

                <h2>3. Third-Party Service Providers</h2>
                <p>We work with trusted third parties to operate our business. These providers access your information only as needed to perform services on our behalf and are bound by confidentiality obligations. Providers may include:</p>
                <ul>
                    <li><strong>Payment processors</strong> — for secure credit card and e-transfer handling</li>
                    <li><strong>Email delivery providers</strong> — for transactional and notification emails</li>
                    <li><strong>SMS gateways</strong> — for appointment reminder text messages</li>
                    <li><strong>Analytics providers</strong> — for aggregate website usage statistics</li>
                    <li><strong>Cloud infrastructure providers</strong> — for secure data hosting</li>
                </ul>
                <p>We do not sell, rent, or trade your personal information to any third party for marketing purposes.</p>

                <h2>4. Data Retention</h2>
                <p>We retain your personal information for as long as necessary to deliver our services and meet legal obligations. Client account data is typically retained for a minimum of seven years to comply with Canadian tax and business record requirements. You may request earlier deletion of non-essential data at any time.</p>

                <h2>5. Your Rights Under PIPEDA</h2>
                <p>As a Canadian resident you have the right to:</p>
                <ul>
                    <li><strong>Access</strong> — request a copy of the personal information we hold about you</li>
                    <li><strong>Correction</strong> — ask us to correct inaccurate or incomplete information</li>
                    <li><strong>Withdrawal of consent</strong> — withdraw consent for non-essential uses of your data (subject to legal constraints)</li>
                    <li><strong>Deletion</strong> — request deletion of your personal information where it is no longer required</li>
                </ul>
                <p>To exercise any of these rights, contact us at the address below. We will respond within 30 days.</p>

                <h2>6. Cookies &amp; Analytics</h2>
                <p>Our website uses cookies and similar technologies to remember your session and understand aggregate traffic patterns. We use analytics software that processes anonymised data. You can disable cookies in your browser settings; this may affect some site functionality.</p>
                <p>We do not use cross-site tracking or serve third-party advertising cookies.</p>

                <h2>7. Security</h2>
                <p>We implement industry-standard technical and organisational measures to protect your personal information from unauthorised access, disclosure, or loss. All data is transmitted over encrypted (HTTPS) connections.</p>

                <h2>8. Changes to This Policy</h2>
                <p>We may update this policy from time to time. Material changes will be communicated via email or a notice on our website. Continued use of our services after an update constitutes acceptance of the revised policy.</p>

                <h2>9. Contact Us</h2>
                <p>For any privacy-related questions, access requests, or concerns:</p>
                <ul>
                    <li><strong>Email:</strong> <a href="mailto:<?= h(SITE_EMAIL) ?>"><?= h(SITE_EMAIL) ?></a></li>
                    <li><strong>Phone:</strong> <a href="tel:<?= h(SITE_PHONE_TEL) ?>"><?= h(SITE_PHONE_DISPLAY) ?></a></li>
                    <li><strong>Business:</strong> Mowology, Metro Vancouver, BC, Canada</li>
                </ul>

                <p class="privacy-updated">Last updated: May 2026</p>

            </div>
        </div>
    </section>

<?php require __DIR__ . '/includes/footer.php'; ?>
