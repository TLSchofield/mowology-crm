<?php
/**
 * Client Profile Editor
 *
 * Lets a client update their email, phone, SMS number, marketing prefs.
 * URL:  /customer/profile.php?token={contacts.portal_token}
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/app_config/config.php';

$db      = getDB();
$error   = '';
$contact = null;

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    $error = 'No token provided.';
} else {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, phone, mobile,
               preferred_contact_method, receive_marketing, receive_sms,
               portal_token
        FROM contacts
        WHERE portal_token = ? LIMIT 1
    ");
    $stmt->execute([$token]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) $error = 'This link is invalid or has expired.';
}

$clientName = $contact ? trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) : '';
$portalUrl  = $token ? '/customer/portal.php?token=' . urlencode($token) : '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Details — Mowology Landscaping</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="/customer/portal.css">
</head>
<body>
  <div class="portal-container" style="max-width:640px;">
    <div class="portal-hero">
      <div class="portal-hero-greeting">Your Details</div>
      <div class="portal-hero-sub">
        <?php if ($contact): ?>
          Update how we reach you, <?= htmlspecialchars($contact['first_name'] ?: 'friend') ?>.
        <?php else: ?>
          <?= htmlspecialchars($error) ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($contact): ?>
    <div class="portal-info-card">
      <div class="portal-info-card-header">Contact Information</div>
      <div class="portal-info-card-body">
        <form id="mwProfileForm" onsubmit="return mwSubmitProfile(event)">
          <div class="portal-form-group">
            <label class="portal-form-label">First Name</label>
            <input type="text" class="portal-form-input" id="mwFirstName" value="<?= htmlspecialchars($contact['first_name'] ?? '') ?>" maxlength="80">
          </div>
          <div class="portal-form-group">
            <label class="portal-form-label">Last Name</label>
            <input type="text" class="portal-form-input" id="mwLastName" value="<?= htmlspecialchars($contact['last_name'] ?? '') ?>" maxlength="80">
          </div>
          <div class="portal-form-group">
            <label class="portal-form-label">Email Address</label>
            <input type="email" class="portal-form-input" id="mwEmail" value="<?= htmlspecialchars($contact['email'] ?? '') ?>" maxlength="150">
            <small style="color:var(--p-text-muted);font-size:0.78rem;">Quote confirmations and receipts are sent here.</small>
          </div>
          <div class="portal-form-group">
            <label class="portal-form-label">Phone Number</label>
            <input type="tel" class="portal-form-input" id="mwPhone" value="<?= htmlspecialchars($contact['phone'] ?? '') ?>" placeholder="(778) 555-1234" maxlength="30">
          </div>
          <div class="portal-form-group">
            <label class="portal-form-label">Mobile / SMS Number</label>
            <input type="tel" class="portal-form-input" id="mwMobile" value="<?= htmlspecialchars($contact['mobile'] ?? '') ?>" placeholder="(778) 555-1234" maxlength="30">
            <small style="color:var(--p-text-muted);font-size:0.78rem;">Used for visit reminders if SMS is enabled below.</small>
          </div>
          <div class="portal-form-group">
            <label class="portal-form-label">Preferred Way to Reach You</label>
            <select class="portal-form-input" id="mwPreferred">
              <?php $pcm = $contact['preferred_contact_method'] ?? 'email'; ?>
              <option value="email"<?= $pcm === 'email' ? ' selected' : '' ?>>Email</option>
              <option value="phone"<?= $pcm === 'phone' ? ' selected' : '' ?>>Phone call</option>
              <option value="text"<?= $pcm === 'text'  ? ' selected' : '' ?>>Text message</option>
            </select>
          </div>

          <hr style="margin:18px 0;border:none;border-top:1px solid var(--p-border);">

          <div class="portal-checkbox-row">
            <input type="checkbox" id="mwReceiveMarketing" <?= !empty($contact['receive_marketing']) ? 'checked' : '' ?>>
            <label for="mwReceiveMarketing">
              <strong>Email me seasonal tips &amp; offers</strong>
              <div style="font-size:0.78rem;color:var(--p-text-muted);">Occasional updates — we never spam. Unsubscribe any time.</div>
            </label>
          </div>

          <div class="portal-checkbox-row" style="margin-top:10px;">
            <input type="checkbox" id="mwReceiveSms" <?= !empty($contact['receive_sms']) ? 'checked' : '' ?>>
            <label for="mwReceiveSms">
              <strong>Text me visit reminders</strong>
              <div style="font-size:0.78rem;color:var(--p-text-muted);">We'll send a short SMS the day before a scheduled visit.</div>
            </label>
          </div>

          <div style="margin-top:22px;display:flex;gap:10px;align-items:center;">
            <button type="submit" class="portal-btn" id="mwSaveBtn">Save Changes</button>
            <a href="<?= htmlspecialchars($portalUrl) ?>" style="color:var(--p-text-mid);font-size:0.88rem;">&larr; Back to portal</a>
          </div>
          <div id="mwProfileStatus" style="margin-top:14px;font-size:0.88rem;"></div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="portal-footer">
      Questions? Call <a href="tel:7788469273">(778) 846-9273</a> &mdash; &copy; <?= date('Y') ?> Mowology
    </div>
  </div>

  <script>
  function mwSubmitProfile(e) {
    e.preventDefault();
    var btn = document.getElementById('mwSaveBtn');
    var status = document.getElementById('mwProfileStatus');
    btn.disabled = true; btn.textContent = 'Saving…';
    status.textContent = '';

    var formData = new FormData();
    formData.append('token', <?= json_encode($token) ?>);
    formData.append('first_name', document.getElementById('mwFirstName').value);
    formData.append('last_name',  document.getElementById('mwLastName').value);
    formData.append('email',      document.getElementById('mwEmail').value);
    formData.append('phone',      document.getElementById('mwPhone').value);
    formData.append('mobile',     document.getElementById('mwMobile').value);
    formData.append('preferred_contact_method', document.getElementById('mwPreferred').value);
    formData.append('receive_marketing', document.getElementById('mwReceiveMarketing').checked ? 1 : 0);
    formData.append('receive_sms',       document.getElementById('mwReceiveSms').checked ? 1 : 0);

    fetch('/customer/api/profile-update.php', { method: 'POST', body: formData })
      .then(r => r.json()).then(d => {
        btn.disabled = false; btn.textContent = 'Save Changes';
        if (d.success) {
          status.innerHTML = '<span style="color:#2D8659;font-weight:600;">&#10003; Saved — thank you.</span>';
          setTimeout(function() { status.textContent = ''; }, 5000);
        } else {
          status.innerHTML = '<span style="color:#dc3545;">Error: ' + (d.error || 'Unknown') + '</span>';
        }
      })
      .catch(function() {
        btn.disabled = false; btn.textContent = 'Save Changes';
        status.innerHTML = '<span style="color:#dc3545;">Network error — please try again.</span>';
      });
    return false;
  }
  </script>
</body>
</html>
