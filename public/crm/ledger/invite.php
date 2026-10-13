<?php
/**
 * Ledger → Accountant access (admin only, users.manage): invite the accountant, re-issue a
 * set-password link, revoke. SENDS NOTHING — the link is shown here for the owner to pass on.
 * The Settings page links here ("Invite your accountant").
 */
define('MW_LEDGER_PAGE', true);
require __DIR__ . '/_ledger.php';
requirePermission('users.manage');
require_once APP_ROOT . '/Modules/Ledger/Services/AccountantInviteService.php';

$db  = getDB();
$svc = new AccountantInviteService($db);
$result = null; $err = null; $note = null;

// The CRM's own address (CRM_SITE_URL, else business_settings.crm_url, else this host only when it is
// the company's own domain or a local dev host) — never a hardcoded domain or a forged Host header.
$siteUrl = LedgerSite::url($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $err = 'The form expired — reload the page and try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? 'invite');
            if ($action === 'revoke') {
                $note = $svc->revoke((int)$_POST['user_id'], (int)$user['id']) ? 'Access revoked. The login is off and any unused link is gone.' : 'Nothing to revoke.';
            } else {
                if ($siteUrl === null) throw new InvalidArgumentException("The CRM's own web address isn't set (Settings → business_settings.crm_url), so no link can be made.");
                $result = $svc->invite((string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), (int)$user['id'], $siteUrl);
            }
        } catch (InvalidArgumentException $e) {
            $err = $e->getMessage();
        } catch (Throwable $e) {
            error_log('ledger/invite: ' . $e->getMessage());
            $err = 'Something went wrong — nothing was created.';
        }
    }
}

$list = $svc->accountants();
$csrf = generateCSRFToken();
$pageTitle = 'Accountant access — Ledger';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-ledger">
  <?php mwLedgerNav('access', true); ?>

  <header class="mw-ledger-head">
    <div>
      <h1 class="mw-ledger-title">Invite your accountant</h1>
      <p class="mw-ledger-sub">They get the Ledger only: trial balance, journal with source documents, GIFI mapping, exceptions.
        Client names only where they are the payer on a source document; no client records, schedule, crew locations, payroll SINs or bank details. Every page they open and every export is recorded.</p>
    </div>
  </header>

  <?php if ($err): ?><div class="mw-ledger-banner mw-ledger-banner--bad" role="alert"><?= h($err) ?></div><?php endif; ?>
  <?php if ($note): ?><div class="mw-ledger-banner mw-ledger-banner--ok" role="status"><?= h($note) ?></div><?php endif; ?>

  <?php if ($result): ?>
  <section class="mw-ledger-invite-result" aria-label="Link to pass on">
    <h2><?= $result['created'] ? 'Login created' : 'New link made' ?> — nothing has been sent</h2>
    <p>Send this link to your accountant yourself (email or text). It lets them choose a password, and works once, until <?= h($result['expires_at']) ?>.</p>
    <div class="mw-ledger-copy">
      <input type="text" readonly value="<?= h($result['link']) ?>" id="mw-invite-link" aria-label="Set-password link">
      <button type="button" class="btn btn-sm btn-primary" data-mw-copy="#mw-invite-link">Copy</button>
    </div>
    <p class="mw-ledger-small">What to tell them: "Open this link and choose a password. After that, sign in at <?= h(rtrim($siteUrl, '/')) ?>/loginAuth/login.php with your email. You'll land on our Ledger."</p>
  </section>
  <?php endif; ?>

  <form method="post" class="mw-ledger-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="invite">
    <label class="mw-ledger-field mw-ledger-field--grow"><span>Accountant's name</span><input type="text" name="name" required maxlength="120"></label>
    <label class="mw-ledger-field mw-ledger-field--grow"><span>Their email</span><input type="email" name="email" required maxlength="255"></label>
    <div class="mw-ledger-actions"><button class="btn btn-sm btn-primary" type="submit">Create login and show link</button></div>
  </form>

  <h2 class="mw-ledger-h2">Accountant logins</h2>
  <div class="mw-ledger-tablewrap">
    <table class="mw-ledger-table">
      <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col" class="mw-ledger-hide-sm">Last sign-in</th>
        <th scope="col" class="mw-ledger-hide-sm">Last Ledger page</th><th scope="col" class="mw-num">Exports</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>
      <?php if (!$list): ?><tr><td colspan="7" class="mw-ledger-empty">No accountant has a login yet.</td></tr><?php endif; ?>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><?= h((string)$a['full_name']) ?></td>
          <td><?= h((string)$a['email']) ?></td>
          <td><span class="mw-ledger-status mw-ledger-status--<?= (int)$a['is_active'] === 1 ? 'confirmed' : 'idle' ?>"><?= (int)$a['is_active'] === 1 ? 'active' : 'revoked' ?></span></td>
          <td class="mw-ledger-hide-sm mw-nowrap"><?= h((string)($a['last_login'] ?: '—')) ?></td>
          <td class="mw-ledger-hide-sm mw-nowrap"><?= h((string)($a['last_ledger'] ?: '—')) ?></td>
          <td class="mw-num"><?= (int)$a['exports'] ?></td>
          <td class="mw-nowrap">
            <form method="post" class="mw-ledger-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="name" value="<?= h((string)$a['full_name']) ?>"><input type="hidden" name="email" value="<?= h((string)$a['email']) ?>">
              <button class="btn btn-sm btn-outline-secondary" name="action" value="invite"><?= (int)$a['is_active'] === 1 ? 'New link' : 'Restore + link' ?></button>
              <?php if ((int)$a['is_active'] === 1): ?>
                <input type="hidden" name="user_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" name="action" value="revoke" data-mw-confirm="Turn off this accountant's login?">Revoke</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mw-ledger-small"><a href="/crm/audit-trail_appstack.php">The audit trail</a> lists every page an accountant opened (actions ledger.view and ledger.export).</p>
</div>

<script src="/crm/js/mw-ledger.js?v=20261013b" defer></script>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
