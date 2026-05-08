<?php
/**
 * User & Role Management — RBAC Admin Page
 *
 * Lists users, lets admins assign roles, toggle active status,
 * and view effective permissions per user.
 *
 * Requires: users.manage permission
 */
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('users.manage');

$db = getDB();

$pageTitle = 'User Management';
$activePage = 'users';

// ── Handle POST actions ─────────────────────────────────────
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token. Please try again.';
        $flashType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {

                // ── Assign roles to a user ──────────────────
                case 'assign_roles':
                    $targetUserId = (int)($_POST['user_id'] ?? 0);
                    $roleIds = array_map('intval', $_POST['roles'] ?? []);

                    if ($targetUserId <= 0) {
                        throw new Exception('Invalid user ID');
                    }

                    // Prevent removing your own admin role
                    if ($targetUserId === $user['id']) {
                        $adminRoleId = (int)$db->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();
                        if ($adminRoleId && !in_array($adminRoleId, $roleIds)) {
                            throw new Exception('You cannot remove your own admin role.');
                        }
                    }

                    // Delete existing roles
                    $db->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$targetUserId]);

                    // Insert new roles
                    $stmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roleIds as $rid) {
                        if ($rid > 0) {
                            $stmt->execute([$targetUserId, $rid]);
                        }
                    }

                    // Clear perm cache for that user's session (bump version)
                    // The user will get fresh permissions on their next request
                    $flash = 'Roles updated successfully.';
                    logActivity($user['id'], null, 'Updated roles for user #' . $targetUserId, 'Roles: ' . implode(',', $roleIds));
                    break;

                // ── Toggle active status ────────────────────
                case 'toggle_active':
                    $targetUserId = (int)($_POST['user_id'] ?? 0);
                    if ($targetUserId <= 0) {
                        throw new Exception('Invalid user ID');
                    }
                    if ($targetUserId === $user['id']) {
                        throw new Exception('You cannot deactivate yourself.');
                    }

                    $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$targetUserId]);
                    $flash = 'User status updated.';
                    logActivity($user['id'], null, 'Toggled active status for user #' . $targetUserId, null);
                    break;

                // ── Change password for a user ──────────────
                case 'change_password':
                    $targetUserId = (int)($_POST['user_id'] ?? 0);
                    $newPass      = $_POST['new_password'] ?? '';
                    $confirmPass  = $_POST['confirm_password'] ?? '';

                    if ($targetUserId <= 0) {
                        throw new Exception('Invalid user ID.');
                    }
                    if (strlen($newPass) < 8) {
                        throw new Exception('Password must be at least 8 characters.');
                    }
                    if ($newPass !== $confirmPass) {
                        throw new Exception('Passwords do not match.');
                    }

                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1")
                       ->execute([$hash, $targetUserId]);

                    $flash = 'Password updated successfully.';
                    logActivity($user['id'], null, 'Changed password for user #' . $targetUserId, null);
                    break;

                default:
                    throw new Exception('Unknown action.');
            }
        } catch (Exception $e) {
            $flash = $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// ── Check if RBAC tables exist ──────────────────────────────
$rbacReady = false;
try {
    $tblCheck = $db->query("SHOW TABLES LIKE 'roles'");
    $rbacReady = $tblCheck && $tblCheck->rowCount() > 0;
} catch (Throwable $e) {
    $rbacReady = false;
}

if (!$rbacReady) {
    // Tables not created yet — show migration prompt instead of crashing
    $pageTitle = 'User Management';
    $activePage = 'users';
    include 'includes/appstack_head.php';
    echo '<div class="alert alert-warning">';
    echo '<h4 class="alert-heading">RBAC Tables Not Found</h4>';
    echo '<p>The roles and permissions tables have not been created yet.</p>';
    echo '<a href="/crm/migrate_400_rbac.php" class="btn btn-primary">Run Migration 400</a>';
    echo '</div>';
    include 'includes/appstack_footer.php';
    exit;
}

// ── Fetch data ──────────────────────────────────────────────
// All users
$users = $db->query("
    SELECT u.id, u.email, u.full_name, u.role AS legacy_role, u.is_active, u.last_login, u.created_at
    FROM users u
    ORDER BY u.is_active DESC, u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// All roles
$roles = $db->query("SELECT id, name, description FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// User-role assignments
$userRolesMap = []; // user_id => [role_id, ...]
$urStmt = $db->query("SELECT user_id, role_id FROM user_roles");
while ($row = $urStmt->fetch(PDO::FETCH_ASSOC)) {
    $uid = (int)$row['user_id'];
    if (!isset($userRolesMap[$uid])) $userRolesMap[$uid] = [];
    $userRolesMap[$uid][] = (int)$row['role_id'];
}

// Effective permissions per role (for the detail view)
$rolePermsMap = []; // role_id => [perm_key, ...]
$rpStmt = $db->query("
    SELECT rp.role_id, p.`key`
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    ORDER BY rp.role_id, p.`key`
");
while ($row = $rpStmt->fetch(PDO::FETCH_ASSOC)) {
    $rid = (int)$row['role_id'];
    if (!isset($rolePermsMap[$rid])) $rolePermsMap[$rid] = [];
    $rolePermsMap[$rid][] = $row['key'];
}

$csrfToken = generateCSRFToken();
?>
<?php include 'includes/appstack_head.php'; ?>

          <!-- Flash message -->
          <?php if ($flash): ?>
          <div class="alert alert-<?php echo $flashType; ?> alert-dismissible fade show" role="alert">
            <?php echo h($flash); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
          </div>
          <?php endif; ?>

          <div class="mw-page-header">
            <div class="mw-page-header-left">
              <h1 class="mw-page-title">User Management</h1>
              <p class="mw-page-subtitle">Auth &amp; access control — roles, passwords, account status</p>
            </div>
            <div class="mw-page-actions">
              <a href="/crm/team/" class="btn btn-outline-secondary">
                <i data-feather="users"></i> Add users via Team
              </a>
            </div>
          </div>

          <!-- Users Table -->
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">All Users (<?php echo count($users); ?>)</h5>
            </div>
            <div class="table-responsive">
              <table class="table table-striped mb-0">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Legacy Role</th>
                    <th>RBAC Roles</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th class="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $u):
                    $uid = (int)$u['id'];
                    $assignedRoles = $userRolesMap[$uid] ?? [];
                    $roleNames = [];
                    foreach ($roles as $r) {
                        if (in_array($r['id'], $assignedRoles)) {
                            $roleNames[] = $r['name'];
                        }
                    }
                  ?>
                  <tr<?php echo !$u['is_active'] ? ' class="text-muted"' : ''; ?>>
                    <td>
                      <span class="mw-cell-primary"><?php echo h($u['full_name'] ?: '(unnamed)'); ?></span>
                      <?php if ($uid === $user['id']): ?>
                        <span class="badge badge-info ml-1">You</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo h($u['email']); ?></td>
                    <td><span class="badge badge-secondary"><?php echo h($u['legacy_role']); ?></span></td>
                    <td>
                      <?php if (empty($roleNames)): ?>
                        <span class="text-muted">(none)</span>
                      <?php else: ?>
                        <?php foreach ($roleNames as $rn): ?>
                          <span class="badge badge-<?php echo $rn === 'admin' ? 'danger' : ($rn === 'manager' ? 'warning' : ($rn === 'staff' ? 'success' : 'secondary')); ?>"><?php echo h($rn); ?></span>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($u['is_active']): ?>
                        <span class="badge badge-success">Active</span>
                      <?php else: ?>
                        <span class="badge badge-danger">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo $u['last_login'] ? date('M j, Y g:ia', strtotime($u['last_login'])) : '<span class="text-muted">Never</span>'; ?></td>
                    <td class="text-right">
                      <a href="/crm/team/profile.php?id=<?php echo $uid; ?>" class="btn btn-sm btn-outline-secondary">
                        <i data-feather="file-text" style="width:14px;height:14px"></i> HR File
                      </a>
                      <button class="btn btn-sm btn-outline-primary ml-1" data-toggle="modal" data-target="#roleModal<?php echo $uid; ?>">
                        <i data-feather="shield" style="width:14px;height:14px"></i> Roles
                      </button>
                      <button class="btn btn-sm btn-outline-secondary ml-1" data-toggle="modal" data-target="#pwModal<?php echo $uid; ?>">
                        <i data-feather="lock" style="width:14px;height:14px"></i> Password
                      </button>
                      <?php if ($uid !== $user['id']): ?>
                      <form method="post" class="d-inline ml-1" onsubmit="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Reactivate'; ?> this user?')">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?php echo $u['is_active'] ? 'danger' : 'success'; ?>">
                          <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Role Summary Card -->
          <div class="card mt-4">
            <div class="card-header">
              <h5 class="card-title mb-0">Role Definitions</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <?php foreach ($roles as $r): ?>
                <div class="col-md-6 col-lg-3 mb-3">
                  <div class="card border h-100">
                    <div class="card-body p-3">
                      <h6 class="mb-1">
                        <span class="badge badge-<?php echo $r['name'] === 'admin' ? 'danger' : ($r['name'] === 'manager' ? 'warning' : ($r['name'] === 'staff' ? 'success' : 'secondary')); ?>"><?php echo h($r['name']); ?></span>
                      </h6>
                      <small class="text-muted"><?php echo h($r['description']); ?></small>
                      <hr class="my-2">
                      <small>
                        <?php
                        $perms = $rolePermsMap[(int)$r['id']] ?? [];
                        if ($r['name'] === 'admin') {
                            echo '<em>All permissions</em>';
                        } elseif (empty($perms)) {
                            echo '<em class="text-muted">No permissions assigned</em>';
                        } else {
                            echo implode(', ', array_map(function($p) { return '<code>' . h($p) . '</code>'; }, $perms));
                        }
                        ?>
                      </small>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Role assignment modals -->
          <?php foreach ($users as $u):
            $uid = (int)$u['id'];
            $assignedRoles = $userRolesMap[$uid] ?? [];
          ?>
          <div class="modal fade" id="roleModal<?php echo $uid; ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="assign_roles">
                  <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">Assign Roles: <?php echo h($u['full_name'] ?: $u['email']); ?></h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                  </div>
                  <div class="modal-body">
                    <?php foreach ($roles as $r): ?>
                    <div class="custom-control custom-checkbox mb-2">
                      <input type="checkbox" class="custom-control-input" name="roles[]"
                             value="<?php echo (int)$r['id']; ?>"
                             id="role_<?php echo $uid; ?>_<?php echo (int)$r['id']; ?>"
                             <?php echo in_array((int)$r['id'], $assignedRoles) ? 'checked' : ''; ?>>
                      <label class="custom-control-label" for="role_<?php echo $uid; ?>_<?php echo (int)$r['id']; ?>">
                        <strong><?php echo h($r['name']); ?></strong>
                        <small class="text-muted d-block"><?php echo h($r['description']); ?></small>
                      </label>
                    </div>
                    <?php endforeach; ?>

                    <!-- Effective permissions preview -->
                    <hr>
                    <h6 class="mb-2">Current Effective Permissions</h6>
                    <div class="bg-light p-2 rounded" style="max-height:200px;overflow-y:auto">
                      <?php
                      $effectivePerms = [];
                      foreach ($assignedRoles as $rid) {
                          if (isset($rolePermsMap[$rid])) {
                              $effectivePerms = array_merge($effectivePerms, $rolePermsMap[$rid]);
                          }
                      }
                      $effectivePerms = array_unique($effectivePerms);
                      sort($effectivePerms);

                      // Check if user has admin role
                      $hasAdmin = false;
                      foreach ($roles as $r) {
                          if ($r['name'] === 'admin' && in_array((int)$r['id'], $assignedRoles)) {
                              $hasAdmin = true;
                              break;
                          }
                      }

                      if ($hasAdmin) {
                          echo '<em class="text-success">All permissions (admin)</em>';
                      } elseif (empty($effectivePerms)) {
                          echo '<em class="text-muted">No permissions</em>';
                      } else {
                          foreach ($effectivePerms as $p) {
                              echo '<code class="d-block mb-1">' . h($p) . '</code>';
                          }
                      }
                      ?>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Roles</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

          <!-- Password change modals -->
          <?php foreach ($users as $u):
            $uid = (int)$u['id'];
          ?>
          <div class="modal fade" id="pwModal<?php echo $uid; ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
              <div class="modal-content">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                  <input type="hidden" name="action" value="change_password">
                  <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                  <div class="modal-header">
                    <h5 class="modal-title">
                      <i data-feather="lock" style="width:16px;height:16px;vertical-align:middle"></i>
                      Change Password: <?php echo h($u['full_name'] ?: $u['email']); ?>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                  </div>
                  <div class="modal-body">
                    <div class="form-group">
                      <label for="pw_new_<?php echo $uid; ?>">New Password</label>
                      <input type="password" class="form-control" id="pw_new_<?php echo $uid; ?>"
                             name="new_password" required minlength="8"
                             placeholder="Minimum 8 characters" autocomplete="new-password">
                    </div>
                    <div class="form-group mb-0">
                      <label for="pw_confirm_<?php echo $uid; ?>">Confirm Password</label>
                      <input type="password" class="form-control" id="pw_confirm_<?php echo $uid; ?>"
                             name="confirm_password" required minlength="8"
                             placeholder="Re-enter new password" autocomplete="new-password">
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Password</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>

<?php include 'includes/appstack_footer.php'; ?>
