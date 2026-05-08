<?php
declare(strict_types=1);
/**
 * Public Privacy Request Form
 * No login required — PIPEDA mandates anyone can submit a DSAR.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// CSRF token — session already started by bootstrap.php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pageTitle       = 'Privacy Request | Mowology Landscaping';
$pageDescription = 'Submit a data access, correction, or deletion request under PIPEDA.';
$activeNav       = '';
$extraHead       = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken) . '">';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
?>

<section style="padding:64px 0 80px;">
    <div class="container" style="max-width:640px;">

        <h1 style="font-size:1.75rem;font-weight:700;color:var(--color-heading,#1a2e26);margin-bottom:8px;">
            Privacy Request
        </h1>
        <p style="color:var(--color-text-muted,#6b7280);margin-bottom:32px;line-height:1.6;">
            Under Canada's <em>Personal Information Protection and Electronic Documents Act</em> (PIPEDA),
            you have the right to access, correct, or request deletion of personal information we hold about you.
            We will respond within <strong>30 days</strong>.
        </p>

        <div id="formArea">
            <div id="formAlert" style="display:none;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.9rem;"></div>

            <form id="privacyForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div style="margin-bottom:20px;">
                    <label style="display:block;font-weight:600;font-size:.9rem;margin-bottom:6px;">
                        Request Type <span style="color:#e85d04;">*</span>
                    </label>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <?php foreach ([
                            'access'     => 'Access My Data',
                            'deletion'   => 'Delete My Data',
                            'correction' => 'Correct My Data',
                        ] as $val => $label): ?>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                                      padding:10px 16px;border:1.5px solid #d1d5db;border-radius:8px;
                                      font-size:.9rem;user-select:none;" class="prv-type-opt">
                            <input type="radio" name="request_type" value="<?php echo $val; ?>"
                                   style="accent-color:#2D8659;"
                                   <?php echo ($val === 'access') ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label for="requester_name" style="display:block;font-weight:600;font-size:.9rem;margin-bottom:6px;">
                        Full Name <span style="color:#e85d04;">*</span>
                    </label>
                    <input type="text" id="requester_name" name="requester_name"
                           style="width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;
                                  font-size:.9rem;box-sizing:border-box;"
                           placeholder="Your full name" required>
                </div>

                <div style="margin-bottom:20px;">
                    <label for="requester_email" style="display:block;font-weight:600;font-size:.9rem;margin-bottom:6px;">
                        Email Address <span style="color:#e85d04;">*</span>
                    </label>
                    <input type="email" id="requester_email" name="requester_email"
                           style="width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;
                                  font-size:.9rem;box-sizing:border-box;"
                           placeholder="The email address associated with your account" required>
                </div>

                <div style="margin-bottom:28px;">
                    <label for="notes" style="display:block;font-weight:600;font-size:.9rem;margin-bottom:6px;">
                        Additional Details <span style="font-weight:400;color:#6b7280;">(optional)</span>
                    </label>
                    <textarea id="notes" name="notes" rows="4"
                              style="width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;
                                     font-size:.9rem;box-sizing:border-box;resize:vertical;"
                              placeholder="Describe what you are requesting or any specific data you want corrected…"></textarea>
                </div>

                <button type="submit" id="submitBtn"
                        style="display:inline-block;padding:12px 32px;background:var(--mowology-green,#2D8659);
                               color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;
                               cursor:pointer;width:100%;">
                    Submit Privacy Request
                </button>
            </form>

            <p style="margin-top:20px;font-size:.82rem;color:#6b7280;text-align:center;line-height:1.5;">
                We will respond within 30 days. Questions? Email
                <a href="mailto:office@mowology.ca" style="color:#2D8659;">office@mowology.ca</a>
                or call <a href="tel:7788469273" style="color:#2D8659;">(778) 846-9273</a>.<br>
                <a href="/privacy" style="color:#2D8659;">Read our full Privacy Policy</a>
            </p>
        </div>

        <div id="successArea" style="display:none;text-align:center;padding:40px 0;">
            <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24"
                 fill="none" stroke="#2D8659" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                 style="margin:0 auto 20px;display:block;">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="7 12 10 15 17 9"/>
            </svg>
            <h2 style="font-size:1.3rem;font-weight:700;margin-bottom:8px;">Request Submitted</h2>
            <p id="successMsg" style="color:#6b7280;line-height:1.6;"></p>
            <p style="margin-top:12px;font-size:.85rem;color:#6b7280;">
                Check your email for a confirmation. Reference number shown in the email.
            </p>
        </div>

    </div>
</section>

<style>
.prv-type-opt:has(input:checked) {
    border-color: #2D8659;
    background: #f0faf5;
    color: #1a4d35;
    font-weight: 600;
}
</style>

<script>
document.getElementById('privacyForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn  = document.getElementById('submitBtn');
    var alert = document.getElementById('formAlert');

    btn.disabled = true;
    btn.textContent = 'Submitting\u2026';
    alert.style.display = 'none';

    var data = {
        csrf_token:      document.querySelector('input[name="csrf_token"]').value,
        request_type:    document.querySelector('input[name="request_type"]:checked').value,
        requester_name:  document.getElementById('requester_name').value.trim(),
        requester_email: document.getElementById('requester_email').value.trim(),
        notes:           document.getElementById('notes').value.trim(),
    };

    fetch('/crm/api/privacy-submit.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(data),
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
        if (res.success) {
            document.getElementById('formArea').style.display = 'none';
            document.getElementById('successMsg').textContent = res.message;
            document.getElementById('successArea').style.display = 'block';
        } else {
            alert.style.background = '#fef2f2';
            alert.style.border = '1px solid #fca5a5';
            alert.style.color = '#991b1b';
            alert.textContent = res.error || 'An error occurred. Please try again.';
            alert.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit Privacy Request';
        }
    })
    .catch(function () {
        alert.style.background = '#fef2f2';
        alert.style.border = '1px solid #fca5a5';
        alert.style.color = '#991b1b';
        alert.textContent = 'Network error. Please try again or email office@mowology.ca.';
        alert.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Submit Privacy Request';
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
