<?php
/**
 * /crm/sites_appstack.php
 * Super-Admin — Tenant Sites Overview
 *
 * Shows all tenant sites with stats, theme preview, and quick-access buttons.
 * Create new tenant sites and manage existing ones from here.
 */

require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
requirePermission('database.manage'); // Super-admin only

$user = getCurrentUser();
$db   = getDB();

// ── Load CMS helpers ──────────────────────────────────────────────────────────
$siteFuncs  = dirname(__DIR__) . '/app/Modules/CMS/Services/CmsSiteFunctions.php';
$themeFuncs = dirname(__DIR__) . '/app/Modules/CMS/Services/CmsThemeFunctions.php';
if (file_exists($siteFuncs))  require_once $siteFuncs;
if (file_exists($themeFuncs)) require_once $themeFuncs;

// ── Handle: Create new site ───────────────────────────────────────────────────
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'create_site') {
    $siteName    = trim($_POST['site_name']    ?? '');
    $domain      = trim($_POST['domain']       ?? '');
    $tagline     = trim($_POST['tagline']      ?? '');
    $adminEmail  = trim($_POST['admin_email']  ?? '');
    $adminPass   = $_POST['admin_password']    ?? '';
    $primaryColor= trim($_POST['primary_color']?? '#2D8659');

    if (!$siteName || !$domain || !$adminEmail || !$adminPass) {
        $flash = 'Site name, domain, admin email, and password are all required.';
        $flashType = 'danger';
    } else {
        try {
            // 1. Create site
            $newSiteId = cms_createSite([
                'name'    => $siteName,
                'domain'  => $domain,
                'tagline' => $tagline,
                'is_active' => 1,
            ]);

            // 2. Seed default theme with chosen primary color
            $darkColor = _cms_darken_hex($primaryColor, 20);
            cms_saveTheme($newSiteId, [
                'name'          => $siteName . ' Default',
                'color_primary' => $primaryColor,
                'color_dark'    => $darkColor,
            ]);

            // 3. Create first admin user
            if (function_exists('cms_createSiteUser')) {
                cms_createSiteUser($newSiteId, $adminEmail, $adminPass, 'owner');
            }

            $flash = "Site <strong>" . htmlspecialchars($siteName) . "</strong> created at <strong>" . htmlspecialchars($domain) . "</strong>. Admin login: " . htmlspecialchars($adminEmail);
        } catch (\Throwable $e) {
            $flash = 'Error creating site: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

// ── Handle: Toggle site status ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'toggle_status') {
    $toggleId = (int)($_POST['site_id'] ?? 0);
    if ($toggleId > 1) { // Never deactivate site_id=1 (primary)
        try {
            $stmt = $db->prepare('UPDATE cms_sites SET is_active = NOT is_active WHERE id = ?');
            $stmt->execute([$toggleId]);
        } catch (\Throwable $e) {}
    }
}

// ── Load all sites with stats ─────────────────────────────────────────────────
$sites = [];
try {
    $stmt = $db->query("SELECT * FROM cms_sites ORDER BY id ASC");
    $rawSites = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawSites as $site) {
        $sid = (int)$site['id'];

        // Page count
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM cms_pages WHERE site_id = ? AND status = 'published'");
            $s->execute([$sid]);
            $site['pages_published'] = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COUNT(*) FROM cms_pages WHERE site_id = ?");
            $s->execute([$sid]);
            $site['pages_total'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) { $site['pages_published'] = 0; $site['pages_total'] = 0; }

        // Section count
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM cms_page_sections WHERE site_id = ? AND is_active = 1");
            $s->execute([$sid]);
            $site['sections_count'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) { $site['sections_count'] = 0; }

        // User count
        try {
            $s = $db->prepare("SELECT COUNT(*) FROM cms_site_users WHERE site_id = ?");
            $s->execute([$sid]);
            $site['users_count'] = (int)$s->fetchColumn();
        } catch (\Throwable $e) { $site['users_count'] = 0; }

        // Active theme
        try {
            $s = $db->prepare("SELECT * FROM cms_themes WHERE site_id = ? ORDER BY is_active DESC, id ASC LIMIT 1");
            $s->execute([$sid]);
            $site['theme'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { $site['theme'] = null; }

        // Last updated page
        try {
            $s = $db->prepare("SELECT MAX(updated_at) FROM cms_pages WHERE site_id = ?");
            $s->execute([$sid]);
            $site['last_updated'] = $s->fetchColumn();
        } catch (\Throwable $e) { $site['last_updated'] = null; }

        $sites[] = $site;
    }
} catch (\Throwable $e) {}

// ── Platform totals ───────────────────────────────────────────────────────────
$totalPages    = array_sum(array_column($sites, 'pages_total'));
$totalSections = array_sum(array_column($sites, 'sections_count'));
$totalUsers    = array_sum(array_column($sites, 'users_count'));

// ── Helpers ───────────────────────────────────────────────────────────────────
function _cms_darken_hex(string $hex, int $amount): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = max(0, hexdec(substr($hex,0,2)) - $amount);
    $g = max(0, hexdec(substr($hex,2,2)) - $amount);
    $b = max(0, hexdec(substr($hex,4,2)) - $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

$pageTitle  = 'Tenant Sites';
$activePage = 'sites';
?>
<?php include 'includes/appstack_head.php'; ?>

<!-- ── Page Header ────────────────────────────────────────────────────────── -->
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Tenant Sites</h1>
        <p class="mw-page-subtitle">Manage all sites on the platform. Each site has its own login, admin, and brand.</p>
    </div>
    <div class="mw-page-actions">
        <button class="btn btn-primary" onclick="document.getElementById('newSiteModal').style.display='flex'">
            <i data-feather="plus"></i> New Tenant
        </button>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?> alert-dismissible mb-4" role="alert">
    <?= $flash ?>
    <button type="button" class="close" data-dismiss="alert"><span>×</span></button>
</div>
<?php endif; ?>

<!-- ── Platform Stats ─────────────────────────────────────────────────────── -->
<div class="row mb-4">
    <?php
    $stats = [
        ['label' => 'Tenant Sites',    'value' => count($sites),    'icon' => 'globe',      'color' => 'var(--mw-green)'],
        ['label' => 'Total Pages',     'value' => $totalPages,      'icon' => 'file-text',  'color' => '#2563eb'],
        ['label' => 'Total Sections',  'value' => $totalSections,   'icon' => 'layout',     'color' => '#7c3aed'],
        ['label' => 'Site Users',      'value' => $totalUsers,      'icon' => 'user',       'color' => '#d97706'],
    ];
    foreach ($stats as $s): ?>
    <div class="col-sm-6 col-xl-3 mb-3">
        <div class="card h-100">
            <div class="card-body d-flex align-items-center" style="gap:1rem">
                <div style="width:48px;height:48px;border-radius:12px;background:<?= $s['color'] ?>1a;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i data-feather="<?= $s['icon'] ?>" style="width:22px;height:22px;color:<?= $s['color'] ?>"></i>
                </div>
                <div>
                    <div style="font-size:1.75rem;font-weight:700;line-height:1;color:#1a1a1a"><?= number_format($s['value']) ?></div>
                    <div style="font-size:.8125rem;color:#6b7280;margin-top:.2rem"><?= $s['label'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Site Cards ─────────────────────────────────────────────────────────── -->
<?php if (empty($sites)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i data-feather="globe" style="width:48px;height:48px;color:#d1d5db;margin-bottom:1rem"></i>
        <h4>No Sites Yet</h4>
        <p class="text-muted">Click <strong>+ New Tenant</strong> to create your first site.</p>
    </div>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($sites as $site):
        $primary = $site['theme']['color_primary'] ?? '#2D8659';
        $dark    = $site['theme']['color_dark']    ?? '#1A5F4A';
        $accent  = $site['theme']['color_accent']  ?? '#e85d04';
        $isActive = (bool)($site['is_active'] ?? 1);
        $isPrimary = ((int)$site['id'] === 1);
        $adminUrl = 'https://' . ltrim($site['domain'] ?? '', '/') . '/site-admin/';
        $siteUrl  = 'https://' . ltrim($site['domain'] ?? '', '/');
    ?>
    <div class="col-md-6 col-xl-4 mb-4">
        <div class="card h-100 sa-site-card <?= !$isActive ? 'sa-site-card--inactive' : '' ?>">

            <!-- Color header band -->
            <div class="sa-site-card__band" style="background:linear-gradient(135deg, <?= htmlspecialchars($primary) ?>, <?= htmlspecialchars($dark) ?>)">
                <div class="sa-site-card__band-inner">
                    <!-- Color swatches -->
                    <div class="sa-site-card__swatches">
                        <span class="sa-swatch" style="background:<?= htmlspecialchars($primary) ?>" title="Primary"></span>
                        <span class="sa-swatch" style="background:<?= htmlspecialchars($dark) ?>" title="Dark"></span>
                        <span class="sa-swatch" style="background:<?= htmlspecialchars($accent) ?>" title="Accent"></span>
                        <span class="sa-swatch" style="background:<?= htmlspecialchars($site['theme']['color_bg_dark'] ?? '#0d1f12') ?>" title="Dark BG"></span>
                    </div>
                    <!-- Status badges -->
                    <div class="d-flex gap-1" style="gap:.35rem">
                        <?php if ($isActive): ?>
                        <span class="badge" style="background:rgba(255,255,255,.25);color:#fff;font-size:10px">Active</span>
                        <?php else: ?>
                        <span class="badge badge-warning" style="font-size:10px">Inactive</span>
                        <?php endif; ?>
                        <?php if ($isPrimary): ?>
                        <span class="badge" style="background:rgba(255,255,255,.2);color:#fff;font-size:10px">Primary</span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Font preview -->
                <div class="sa-site-card__font-preview" style="font-family:'<?= htmlspecialchars($site['theme']['font_heading'] ?? 'Playfair Display') ?>', Georgia, serif">
                    <?= htmlspecialchars($site['name'] ?? 'Unnamed Site') ?>
                </div>
            </div>

            <div class="card-body">

                <!-- Site info -->
                <div class="mb-3">
                    <h5 class="mb-1" style="font-weight:700"><?= htmlspecialchars($site['name'] ?? '') ?></h5>
                    <a href="<?= htmlspecialchars($siteUrl) ?>" target="_blank"
                       class="text-muted" style="font-size:.8125rem;text-decoration:none">
                        <?= htmlspecialchars($site['domain'] ?? '') ?>
                        <i data-feather="external-link" style="width:12px;height:12px;margin-left:2px"></i>
                    </a>
                    <?php if (!empty($site['tagline'])): ?>
                    <p class="text-muted mb-0 mt-1" style="font-size:.8rem"><?= htmlspecialchars($site['tagline']) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Stats row -->
                <div class="sa-site-stats mb-3">
                    <div class="sa-site-stat">
                        <span class="sa-site-stat__value"><?= $site['pages_published'] ?></span>
                        <span class="sa-site-stat__label">Published</span>
                    </div>
                    <div class="sa-site-stat">
                        <span class="sa-site-stat__value"><?= $site['pages_total'] ?></span>
                        <span class="sa-site-stat__label">Pages</span>
                    </div>
                    <div class="sa-site-stat">
                        <span class="sa-site-stat__value"><?= $site['sections_count'] ?></span>
                        <span class="sa-site-stat__label">Sections</span>
                    </div>
                    <div class="sa-site-stat">
                        <span class="sa-site-stat__value"><?= $site['users_count'] ?></span>
                        <span class="sa-site-stat__label">Users</span>
                    </div>
                </div>

                <!-- Theme info -->
                <?php if ($site['theme']): ?>
                <div class="sa-site-theme-info mb-3">
                    <i data-feather="droplet" style="width:13px;height:13px;color:#9ca3af"></i>
                    <span><?= htmlspecialchars($site['theme']['name'] ?? 'Default') ?></span>
                    <span class="mx-1" style="color:#e5e7eb">·</span>
                    <span><?= htmlspecialchars($site['theme']['font_heading'] ?? 'Playfair Display') ?></span>
                    <span class="mx-1" style="color:#e5e7eb">·</span>
                    <span><?= htmlspecialchars($site['theme']['font_body'] ?? 'DM Sans') ?></span>
                </div>
                <?php endif; ?>

                <!-- Last updated -->
                <?php if ($site['last_updated']): ?>
                <p class="text-muted mb-3" style="font-size:.75rem">
                    <i data-feather="clock" style="width:12px;height:12px"></i>
                    Updated <?= date('M j, Y', strtotime($site['last_updated'])) ?>
                </p>
                <?php endif; ?>

                <!-- Action buttons -->
                <div class="d-flex" style="gap:.5rem;flex-wrap:wrap">
                    <a href="<?= htmlspecialchars($adminUrl) ?>" target="_blank"
                       class="btn btn-sm btn-primary" style="flex:1;justify-content:center">
                        <i data-feather="layout" style="width:13px;height:13px"></i> Open Admin
                    </a>
                    <button class="btn btn-sm btn-outline-secondary"
                            onclick="openEditSite(<?= (int)$site['id'] ?>, <?= htmlspecialchars(json_encode($site)) ?>)"
                            title="Edit site settings">
                        <i data-feather="edit-2" style="width:13px;height:13px"></i>
                    </button>
                    <?php if (!$isPrimary): ?>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Toggle site status?')">
                        <input type="hidden" name="_action" value="toggle_status">
                        <input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-<?= $isActive ? 'warning' : 'success' ?>"
                                title="<?= $isActive ? 'Deactivate' : 'Activate' ?> site">
                            <i data-feather="<?= $isActive ? 'pause' : 'play' ?>" style="width:13px;height:13px"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════ NEW SITE MODAL ═══════════════════════════════════════════ -->
<div class="sa-modal-overlay" id="newSiteModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal">
        <div class="sa-modal-header">
            <h5 class="mb-0"><i data-feather="globe" style="width:18px;height:18px;margin-right:.5rem"></i> New Tenant Site</h5>
            <button class="sa-modal-close" onclick="document.getElementById('newSiteModal').style.display='none'">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="_action" value="create_site">
            <div class="sa-modal-body">

                <div class="row">
                    <div class="col-md-7">
                        <div class="form-group">
                            <label class="sa-label">Site Name <span class="text-danger">*</span></label>
                            <input type="text" name="site_name" class="form-control" placeholder="e.g. Sun Wukong Wing Tsun" required>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label class="sa-label">Primary Colour</label>
                            <div class="d-flex align-items-center" style="gap:.5rem">
                                <input type="color" name="primary_color" value="#2D8659"
                                       style="width:44px;height:38px;border-radius:6px;border:1px solid #dee2e6;padding:2px;cursor:pointer">
                                <input type="text" id="primaryColorHex" value="#2D8659"
                                       class="form-control form-control-sm" style="font-family:monospace;width:90px">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="sa-label">Domain <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text">https://</span></div>
                        <input type="text" name="domain" class="form-control" placeholder="talkinghands.ca" required>
                    </div>
                    <small class="form-text text-muted">Must match the actual domain where this site is served.</small>
                </div>

                <div class="form-group">
                    <label class="sa-label">Tagline</label>
                    <input type="text" name="tagline" class="form-control" placeholder="e.g. Wing Tsun Kung Fu in Vancouver">
                </div>

                <hr>
                <p class="text-muted" style="font-size:.8rem;margin-bottom:.75rem"><strong>First Admin User</strong> — this person will be able to log into the site admin.</p>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="sa-label">Admin Email <span class="text-danger">*</span></label>
                            <input type="email" name="admin_email" class="form-control" placeholder="admin@talkinghands.ca" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="sa-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="admin_password" class="form-control" autocomplete="new-password" required>
                        </div>
                    </div>
                </div>

            </div>
            <div class="sa-modal-footer">
                <button type="submit" class="btn btn-primary">
                    <i data-feather="plus" style="width:14px;height:14px"></i> Create Site
                </button>
                <button type="button" class="btn btn-light ml-2"
                        onclick="document.getElementById('newSiteModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════ EDIT SITE MODAL ══════════════════════════════════════════ -->
<div class="sa-modal-overlay" id="editSiteModal" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="sa-modal">
        <div class="sa-modal-header">
            <h5 class="mb-0"><i data-feather="edit-2" style="width:18px;height:18px;margin-right:.5rem"></i> Edit Site</h5>
            <button class="sa-modal-close" onclick="document.getElementById('editSiteModal').style.display='none'">×</button>
        </div>
        <form method="POST" id="editSiteForm">
            <input type="hidden" name="_action" value="update_site">
            <input type="hidden" name="site_id" id="editSiteId">
            <div class="sa-modal-body">
                <div class="form-group">
                    <label class="sa-label">Site Name</label>
                    <input type="text" name="site_name" id="editSiteName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="sa-label">Domain</label>
                    <input type="text" name="domain" id="editSiteDomain" class="form-control">
                </div>
                <div class="form-group">
                    <label class="sa-label">Tagline</label>
                    <input type="text" name="tagline" id="editSiteTagline" class="form-control">
                </div>
                <p class="text-muted" style="font-size:.8rem;margin-top:.5rem">
                    To change the theme colours and fonts, use the
                    <strong>Settings → Theme</strong> page in that site's admin panel.
                </p>
            </div>
            <div class="sa-modal-footer">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-light ml-2"
                        onclick="document.getElementById('editSiteModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php
// ── Handle: Update site (needs to be before page render but after modal HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_site') {
    $updateId = (int)($_POST['site_id'] ?? 0);
    if ($updateId) {
        try {
            $stmt = $db->prepare('UPDATE cms_sites SET name=?, domain=?, tagline=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([
                trim($_POST['site_name'] ?? ''),
                trim($_POST['domain']    ?? ''),
                trim($_POST['tagline']   ?? ''),
                $updateId,
            ]);
        } catch (\Throwable $e) {}
    }
    header('Location: /crm/sites_appstack.php');
    exit;
}
?>

<style>
/* ── Site Cards ─────────────────────────────────────────────────────────── */
.sa-site-card {
    border-radius: 12px; overflow: hidden;
    transition: box-shadow .2s, transform .2s;
}
.sa-site-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,.1); transform: translateY(-2px); }
.sa-site-card--inactive { opacity: .6; }

.sa-site-card__band {
    padding: 1.25rem 1.25rem .75rem;
    min-height: 100px;
}
.sa-site-card__band-inner {
    display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .75rem;
}
.sa-site-card__swatches { display: flex; gap: .3rem; }
.sa-swatch {
    width: 16px; height: 16px; border-radius: 50%;
    border: 2px solid rgba(255,255,255,.4);
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.sa-site-card__font-preview {
    font-size: 1.25rem; font-weight: 700; color: rgba(255,255,255,.95);
    line-height: 1.2; text-shadow: 0 1px 4px rgba(0,0,0,.2);
}

/* Stats */
.sa-site-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    border: 1px solid #f3f4f6; border-radius: 8px; overflow: hidden;
}
.sa-site-stat {
    padding: .5rem .25rem; text-align: center;
    border-right: 1px solid #f3f4f6;
}
.sa-site-stat:last-child { border-right: none; }
.sa-site-stat__value {
    display: block; font-size: 1.125rem; font-weight: 700; color: #111827; line-height: 1;
}
.sa-site-stat__label {
    display: block; font-size: .65rem; color: #9ca3af; margin-top: .2rem; text-transform: uppercase; letter-spacing: .04em;
}

/* Theme info */
.sa-site-theme-info {
    display: flex; align-items: center; gap: .3rem;
    font-size: .75rem; color: #6b7280;
}

/* Modal */
.sa-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    z-index: 1050; display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(2px);
}
.sa-modal {
    background: #fff; border-radius: 14px;
    width: min(580px, calc(100vw - 2rem));
    max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.sa-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; border-bottom: 1px solid #e5e7eb;
}
.sa-modal-close {
    width: 30px; height: 30px; background: #f3f4f6; border: none;
    border-radius: 6px; font-size: 1.25rem; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
}
.sa-modal-close:hover { background: #e5e7eb; }
.sa-modal-body { padding: 1.5rem; }
.sa-modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb; background: #f9fafb; border-radius: 0 0 14px 14px; }
.sa-label { font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: .375rem; display: block; }
</style>

<script>
// Sync colour picker + hex input
const picker = document.querySelector('[name=primary_color]');
const hexIn  = document.getElementById('primaryColorHex');
if (picker && hexIn) {
    picker.addEventListener('input', () => { hexIn.value = picker.value; });
    hexIn.addEventListener('input', () => {
        if (/^#[0-9a-fA-F]{6}$/.test(hexIn.value)) picker.value = hexIn.value;
    });
}

function openEditSite(id, site) {
    document.getElementById('editSiteId').value      = id;
    document.getElementById('editSiteName').value    = site.name    || '';
    document.getElementById('editSiteDomain').value  = site.domain  || '';
    document.getElementById('editSiteTagline').value = site.tagline || '';
    document.getElementById('editSiteModal').style.display = 'flex';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('newSiteModal').style.display  = 'none';
        document.getElementById('editSiteModal').style.display = 'none';
    }
});
</script>

<?php include 'includes/appstack_footer.php'; ?>
