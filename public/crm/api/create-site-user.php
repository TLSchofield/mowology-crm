<?php
/**
 * One-time helper: create a cms_site_users account.
 * Protected by CRM login (requireLogin). Safe to leave in place.
 * Usage: /crm/api/create-site-user.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/paths.php';
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
requireLogin();

require_once dirname(__DIR__, 2) . '/app/Modules/CMS/Services/CmsSiteFunctions.php';

$db = getDB();

// Load all sites for the dropdown
$sites = cms_getAllSites();

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteId    = (int)($_POST['site_id'] ?? 0);
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $role      = $_POST['role'] ?? 'owner';

    if (!$siteId || !$email || !$password) {
        $error = 'Site, email, and password are all required.';
    } else {
        try {
            $userId = cms_createSiteUser($siteId, $email, $password, $role, $firstName, $lastName);
            $siteName = '';
            foreach ($sites as $s) {
                if ($s['id'] === $siteId) { $siteName = $s['name']; break; }
            }
            $message = "User &nbsp;<strong>" . htmlspecialchars($email) . "</strong>&nbsp; created (ID $userId) as &nbsp;<strong>$role</strong>&nbsp; for &nbsp;<strong>" . htmlspecialchars($siteName) . "</strong>.";
        } catch (\Throwable $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$pageTitle  = 'Create Site User';
$activePage = 'database';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="mw-page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Create Site Admin User</h1>
        <p class="text-muted mb-0">Add a login account to <code>cms_site_users</code> for any tenant site.</p>
    </div>
    <a href="/crm/database_appstack.php" class="btn btn-outline-secondary btn-sm">
        <i data-feather="arrow-left" style="width:14px;height:14px;"></i> Database Manager
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Site</label>
                        <select name="site_id" class="form-control" required>
                            <option value="">— select site —</option>
                            <?php foreach ($sites as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                    <?= isset($_POST['site_id']) && (int)$_POST['site_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['domain']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group col">
                            <label>First Name</label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
                        </div>
                        <div class="form-group col">
                            <label>Last Name</label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" class="form-control">
                            <option value="owner" <?= ($_POST['role'] ?? 'owner') === 'owner' ? 'selected' : '' ?>>Owner</option>
                            <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="editor" <?= ($_POST['role'] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
                            <option value="viewer" <?= ($_POST['role'] ?? '') === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </form>
            </div>
        </div>

        <?php
        // Show existing users
        try {
            $stmt = $db->query("
                SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.created_at, s.name as site_name, s.domain
                FROM cms_site_users u
                JOIN cms_sites s ON s.id = u.site_id
                ORDER BY u.created_at DESC
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $users = [];
        }
        ?>

        <?php if (!empty($users)): ?>
        <div class="card mt-4">
            <div class="card-header"><strong>Existing Site Users</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr><th>Site</th><th>Email</th><th>Role</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['site_name']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><span class="badge badge-secondary"><?= htmlspecialchars($u['role']) ?></span></td>
                                <td style="font-size:12px;color:#666"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
