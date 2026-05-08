<?php
/**
 * /site-admin/page-edit.php
 * Page Editor — meta fields + visual section builder.
 *
 * ?id=X   — edit existing page
 * (no id) — create new page (redirects after save)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
requireSiteLogin();

require_once dirname(__DIR__) . '/app/Modules/CMS/Services/CmsSectionFunctions.php';
require_once dirname(__DIR__) . '/app/Modules/CMS/Services/CmsFunctions.php';

$siteId   = (int)CMS_SITE_ID;
$siteUser = getSiteUser();
$pageId   = (int)($_GET['id'] ?? 0);
$db       = getDB();

// ── Load page ─────────────────────────────────────────────────────────────────
$page = null;
if ($pageId) {
    try {
        $stmt = $db->prepare('SELECT * FROM cms_pages WHERE id = ? AND site_id = ?');
        $stmt->execute([$pageId, $siteId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {}
}

if ($pageId && !$page) {
    header('Location: /site-admin/pages.php');
    exit;
}

// ── Handle page meta save ─────────────────────────────────────────────────────
$saved = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_page'])) {
    $title       = trim($_POST['title']       ?? '');
    $slug        = trim($_POST['slug']        ?? '');
    $status      = in_array($_POST['status'] ?? '', ['published','draft','archived'])
                    ? $_POST['status'] : 'draft';
    $metaDesc    = trim($_POST['meta_description'] ?? '');
    $metaKeywords= trim($_POST['meta_keywords']    ?? '');

    if (!$title) {
        $error = 'Title is required.';
    } else {
        try {
            if ($page) {
                $stmt = $db->prepare(
                    'UPDATE cms_pages SET title=?, slug=?, status=?, meta_description=?, meta_keywords=?, updated_at=NOW()
                     WHERE id=? AND site_id=?'
                );
                $stmt->execute([$title, $slug, $status, $metaDesc, $metaKeywords, $pageId, $siteId]);
                $saved = true;
                // Refresh page data
                $stmt = $db->prepare('SELECT * FROM cms_pages WHERE id=?');
                $stmt->execute([$pageId]);
                $page = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO cms_pages (site_id, title, slug, status, meta_description, meta_keywords, type, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,NOW(),NOW())'
                );
                $pageType = 'page';
                $stmt->execute([$siteId, $title, $slug, $status, $metaDesc, $metaKeywords, $pageType]);
                $newId = (int)$db->lastInsertId();
                header('Location: /site-admin/page-edit.php?id=' . $newId . '&created=1');
                exit;
            }
        } catch (\Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
    }
}

// ── Block type registry for JS ────────────────────────────────────────────────
$blockTypes  = cms_getSectionBlockTypes();
$sections    = $pageId ? cms_getPageSections($pageId, $siteId) : [];
$isNew       = !$page;
$activeTab   = $_GET['tab'] ?? 'meta';

$pageTitle = $page ? 'Edit: ' . ($page['title'] ?? 'Page') : 'New Page';
include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/nav.php';

if (isset($_GET['created'])): ?>
<div class="sa-alert sa-alert-success" style="margin-bottom:1rem">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    Page created! Now add some sections below.
</div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="sa-alert sa-alert-success" style="margin-bottom:1rem">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    Page saved.
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="sa-alert sa-alert-error" style="margin-bottom:1rem">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="pe-layout">

    <!-- ── Left: Main editor ──────────────────────────────────────────────── -->
    <div class="pe-main">

        <!-- Header -->
        <div class="pe-header">
            <a href="/site-admin/pages.php" class="pe-back">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Pages
            </a>
            <h1 class="pe-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($page): ?>
            <a href="<?= htmlspecialchars(SITE_URL . '/' . ltrim($page['slug'] ?? '', '/')) ?>"
               target="_blank" class="pe-view-link">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                View Page
            </a>
            <?php endif; ?>
        </div>

        <!-- Tab nav -->
        <div class="pe-tabs">
            <button class="pe-tab <?= $activeTab === 'meta' ? 'active' : '' ?>" data-tab="meta">Page Info</button>
            <?php if ($pageId): ?>
            <button class="pe-tab <?= $activeTab === 'sections' ? 'active' : '' ?>" data-tab="sections">
                Sections
                <?php if (!empty($sections)): ?>
                <span class="pe-tab-badge"><?= count($sections) ?></span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
        </div>

        <!-- ── Tab: Page Info ────────────────────────────────────────────── -->
        <div class="pe-tab-panel <?= $activeTab === 'meta' ? 'active' : '' ?>" id="panel-meta">
            <form method="POST" id="pageMetaForm">
                <input type="hidden" name="_save_page" value="1">

                <div class="pe-card">
                    <div class="pe-card-body">
                        <div class="pe-field">
                            <label>Page Title <span class="pe-required">*</span></label>
                            <input type="text" name="title" class="pe-input pe-input--lg"
                                   value="<?= htmlspecialchars($page['title'] ?? '') ?>"
                                   placeholder="e.g. Our Services" required>
                        </div>
                        <div class="pe-field">
                            <label>URL Slug</label>
                            <div class="pe-slug-row">
                                <span class="pe-slug-prefix"><?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/</span>
                                <input type="text" name="slug" id="slugInput" class="pe-input"
                                       value="<?= htmlspecialchars($page['slug'] ?? '') ?>"
                                       placeholder="services">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pe-card" style="margin-top:1rem">
                    <div class="pe-card-header">SEO</div>
                    <div class="pe-card-body">
                        <div class="pe-field">
                            <label>Meta Description</label>
                            <textarea name="meta_description" class="pe-textarea" rows="3"
                                      placeholder="Brief description for search engines (150–160 chars)"><?= htmlspecialchars($page['meta_description'] ?? '') ?></textarea>
                            <span class="pe-char-count" id="metaDescCount">0 / 160</span>
                        </div>
                        <div class="pe-field" style="margin-top:.75rem">
                            <label>Keywords</label>
                            <input type="text" name="meta_keywords" class="pe-input"
                                   value="<?= htmlspecialchars($page['meta_keywords'] ?? '') ?>"
                                   placeholder="landscaping, Vancouver, lawn care">
                        </div>
                    </div>
                </div>

                <div class="pe-actions">
                    <button type="submit" class="pe-btn pe-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <?= $page ? 'Save Changes' : 'Create Page' ?>
                    </button>
                    <?php if ($page && !empty($sections)): ?>
                    <button type="button" class="pe-btn pe-btn-secondary" onclick="switchTab('sections')">
                        View Sections →
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── Tab: Sections ─────────────────────────────────────────────── -->
        <?php if ($pageId): ?>
        <div class="pe-tab-panel <?= $activeTab === 'sections' ? 'active' : '' ?>" id="panel-sections">

            <!-- Sections list -->
            <div id="sectionsList">
                <?php if (empty($sections)): ?>
                <div class="pe-empty" id="sectionsEmpty">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                    <p>No sections yet.<br>Click <strong>+ Add Section</strong> to start building.</p>
                </div>
                <?php else: ?>
                <?php foreach ($sections as $sec): ?>
                <?php $bt = $blockTypes[$sec['block_type']] ?? null; ?>
                <div class="pe-section-row" data-id="<?= $sec['id'] ?>">
                    <div class="pe-section-row__drag" title="Drag to reorder">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="5" r="1" fill="currentColor"/><circle cx="15" cy="5" r="1" fill="currentColor"/><circle cx="9" cy="12" r="1" fill="currentColor"/><circle cx="15" cy="12" r="1" fill="currentColor"/><circle cx="9" cy="19" r="1" fill="currentColor"/><circle cx="15" cy="19" r="1" fill="currentColor"/></svg>
                    </div>
                    <div class="pe-section-row__icon">
                        <i data-feather="<?= htmlspecialchars($bt['icon'] ?? 'square') ?>" style="width:18px;height:18px;color:#6b7280"></i>
                    </div>
                    <div class="pe-section-row__info">
                        <strong><?= htmlspecialchars($bt['label'] ?? $sec['block_type']) ?></strong>
                        <span><?= htmlspecialchars(_cms_sectionPreviewText($sec['data'])) ?></span>
                    </div>
                    <div class="pe-section-row__actions">
                        <button class="pe-sec-btn pe-sec-btn-up"    title="Move up"    onclick="moveSection(this,'up')">↑</button>
                        <button class="pe-sec-btn pe-sec-btn-down"  title="Move down"  onclick="moveSection(this,'down')">↓</button>
                        <button class="pe-sec-btn pe-sec-btn-edit"  title="Edit"       onclick="editSection(<?= $sec['id'] ?>, '<?= htmlspecialchars($sec['block_type']) ?>')">Edit</button>
                        <button class="pe-sec-btn pe-sec-btn-delete"title="Delete"     onclick="deleteSection(<?= $sec['id'] ?>)">×</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="pe-add-section">
                <button class="pe-btn pe-btn-add" onclick="openPicker()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Section
                </button>
            </div>

        </div><!-- /#panel-sections -->
        <?php endif; ?>

    </div><!-- /.pe-main -->

    <!-- ── Right: Sidebar ─────────────────────────────────────────────────── -->
    <div class="pe-sidebar">
        <div class="pe-card">
            <div class="pe-card-header">Status</div>
            <div class="pe-card-body">
                <select name="status" form="pageMetaForm" class="pe-select">
                    <option value="draft"     <?= ($page['status'] ?? 'draft') === 'draft'     ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= ($page['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="archived"  <?= ($page['status'] ?? '') === 'archived'  ? 'selected' : '' ?>>Archived</option>
                </select>
                <?php if ($page): ?>
                <div class="pe-sidebar-meta">
                    <div>Updated: <?= date('M j, Y', strtotime($page['updated_at'] ?? 'now')) ?></div>
                    <?php if (!empty($page['views'])): ?>
                    <div><?= number_format((int)$page['views']) ?> views</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <button type="submit" form="pageMetaForm" class="pe-btn pe-btn-primary pe-btn-full" style="margin-top:.75rem">
                    <?= $page ? 'Save' : 'Create Page' ?>
                </button>
            </div>
        </div>

        <?php if ($pageId && !empty($sections)): ?>
        <div class="pe-card" style="margin-top:1rem">
            <div class="pe-card-header">Sections (<?= count($sections) ?>)</div>
            <div class="pe-card-body pe-card-body--compact">
                <?php foreach ($sections as $i => $sec):
                    $bt = $blockTypes[$sec['block_type']] ?? null; ?>
                <div class="pe-sidebar-sec">
                    <span class="pe-sidebar-sec__num"><?= $i + 1 ?></span>
                    <span><?= htmlspecialchars($bt['label'] ?? $sec['block_type']) ?></span>
                </div>
                <?php endforeach; ?>
                <button class="pe-btn pe-btn-add pe-btn-full" onclick="switchTab('sections');openPicker()" style="margin-top:.5rem">
                    + Add Section
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.pe-layout -->

<!-- ════════════════ SECTION PICKER MODAL ════════════════════════════════════ -->
<div class="pe-modal-overlay" id="pickerOverlay" onclick="closePicker()" style="display:none"></div>
<div class="pe-modal" id="pickerModal" style="display:none">
    <div class="pe-modal-header">
        <h2>Add a Section</h2>
        <button onclick="closePicker()" class="pe-modal-close">×</button>
    </div>
    <div class="pe-modal-body">
        <div class="pe-picker-grid">
            <?php foreach ($blockTypes as $typeKey => $typeDef): ?>
            <button class="pe-picker-card" onclick="pickType('<?= htmlspecialchars($typeKey) ?>')">
                <div class="pe-picker-card__preview btp-<?= htmlspecialchars($typeKey) ?>">
                    <?= _cms_blockTypePreviewSvg($typeKey) ?>
                </div>
                <div class="pe-picker-card__info">
                    <strong><?= htmlspecialchars($typeDef['label']) ?></strong>
                    <span><?= htmlspecialchars($typeDef['description']) ?></span>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ════════════════ SECTION EDIT MODAL ══════════════════════════════════════ -->
<div class="pe-modal-overlay" id="editOverlay" onclick="closeEdit()" style="display:none"></div>
<div class="pe-modal pe-modal--wide" id="editModal" style="display:none">
    <div class="pe-modal-header">
        <h2 id="editModalTitle">Edit Section</h2>
        <button onclick="closeEdit()" class="pe-modal-close">×</button>
    </div>
    <div class="pe-modal-body" id="editModalBody">
        <!-- Populated by JS -->
    </div>
    <div class="pe-modal-footer">
        <button onclick="saveSection()" class="pe-btn pe-btn-primary">Save Section</button>
        <button onclick="closeEdit()" class="pe-btn pe-btn-secondary">Cancel</button>
    </div>
</div>

<?php

// ── Helper: section preview text ──────────────────────────────────────────────
function _cms_sectionPreviewText(array $data): string {
    foreach (['headline', 'title', 'content', 'quote'] as $key) {
        if (!empty($data[$key])) {
            $text = strip_tags($data[$key]);
            return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
        }
    }
    return 'No content yet';
}

// ── Helper: block type picker preview SVG ─────────────────────────────────────
function _cms_blockTypePreviewSvg(string $type): string {
    return match($type) {
        'hero' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#0d1f12"/>
            <rect x="35" y="8" width="50" height="6" rx="3" fill="rgba(255,255,255,.15)"/>
            <rect x="20" y="20" width="80" height="12" rx="4" fill="rgba(255,255,255,.9)"/>
            <rect x="30" y="37" width="60" height="7" rx="3" fill="rgba(255,255,255,.5)"/>
            <rect x="42" y="52" width="36" height="10" rx="5" fill="#e85d04"/>
        </svg>',
        'features' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#f9fafb"/>
            <rect x="15" y="8" width="90" height="8" rx="3" fill="#e5e7eb"/>
            <rect x="8"  y="24" width="30" height="38" rx="6" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>
            <rect x="45" y="24" width="30" height="38" rx="6" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>
            <rect x="82" y="24" width="30" height="38" rx="6" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>
            <circle cx="23" cy="36" r="5" fill="#2D8659"/>
            <rect x="13" y="44" width="22" height="3" rx="1.5" fill="#d1d5db"/>
            <rect x="13" y="50" width="18" height="2.5" rx="1.25" fill="#e5e7eb"/>
            <circle cx="60" cy="36" r="5" fill="#2D8659"/>
            <rect x="50" y="44" width="22" height="3" rx="1.5" fill="#d1d5db"/>
            <circle cx="97" cy="36" r="5" fill="#2D8659"/>
            <rect x="87" y="44" width="22" height="3" rx="1.5" fill="#d1d5db"/>
        </svg>',
        'text_block' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#fff"/>
            <rect x="15" y="12" width="90" height="8" rx="3" fill="#1a1a1a"/>
            <rect x="15" y="26" width="90" height="4" rx="2" fill="#d1d5db"/>
            <rect x="15" y="34" width="75" height="4" rx="2" fill="#d1d5db"/>
            <rect x="15" y="42" width="82" height="4" rx="2" fill="#d1d5db"/>
            <rect x="15" y="50" width="60" height="4" rx="2" fill="#d1d5db"/>
        </svg>',
        'cta_banner' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#2D8659"/>
            <rect x="20" y="16" width="80" height="10" rx="4" fill="rgba(255,255,255,.9)"/>
            <rect x="30" y="31" width="60" height="6" rx="3" fill="rgba(255,255,255,.5)"/>
            <rect x="38" y="46" width="44" height="12" rx="6" fill="#fff"/>
        </svg>',
        'image_text' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#fff"/>
            <rect x="8"  y="8"  width="50" height="54" rx="6" fill="#e5e7eb"/>
            <line x1="28" y1="28" x2="38" y2="20" stroke="#9ca3af" stroke-width="1.5"/>
            <circle cx="38" cy="20" r="4" fill="#9ca3af"/>
            <rect x="67" y="14" width="45" height="8"  rx="3" fill="#1a1a1a"/>
            <rect x="67" y="28" width="45" height="4"  rx="2" fill="#d1d5db"/>
            <rect x="67" y="36" width="38" height="4"  rx="2" fill="#d1d5db"/>
            <rect x="67" y="44" width="42" height="4"  rx="2" fill="#d1d5db"/>
            <rect x="67" y="55" width="28" height="9"  rx="4.5" fill="#2D8659"/>
        </svg>',
        'testimonials' => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
            <rect width="120" height="70" fill="#f3f4f6"/>
            <rect x="8"  y="8"  width="48" height="54" rx="6" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>
            <rect x="64" y="8"  width="48" height="54" rx="6" fill="#fff" stroke="#e5e7eb" stroke-width="1"/>
            <text x="12" y="26"  font-size="12" fill="#c9a84c">★★★★★</text>
            <rect x="12" y="30" width="40" height="3" rx="1.5" fill="#e5e7eb"/>
            <rect x="12" y="37" width="35" height="3" rx="1.5" fill="#e5e7eb"/>
            <rect x="12" y="44" width="30" height="3" rx="1.5" fill="#e5e7eb"/>
            <rect x="12" y="52" width="20" height="3" rx="1.5" fill="#d1d5db"/>
            <text x="68" y="26"  font-size="12" fill="#c9a84c">★★★★★</text>
            <rect x="68" y="30" width="40" height="3" rx="1.5" fill="#e5e7eb"/>
            <rect x="68" y="37" width="35" height="3" rx="1.5" fill="#e5e7eb"/>
            <rect x="68" y="52" width="20" height="3" rx="1.5" fill="#d1d5db"/>
        </svg>',
        default => '<svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg"><rect width="120" height="70" fill="#f3f4f6" rx="4"/></svg>',
    };
}
?>

<style>
/* ── Page Editor Layout ─────────────────────────────────────────── */
.pe-layout {
    display: grid;
    grid-template-columns: 1fr 240px;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 900px) { .pe-layout { grid-template-columns: 1fr; } }

.pe-header {
    display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem;
}
.pe-back {
    display: inline-flex; align-items: center; gap: .25rem;
    font-size: .8125rem; color: #6b7280; text-decoration: none;
    padding: .375rem .625rem; border-radius: 6px;
    border: 1px solid #e5e7eb; background: #fff;
}
.pe-back:hover { color: #111827; border-color: #d1d5db; }
.pe-title { font-size: 1.25rem; font-weight: 700; color: #111827; margin: 0; flex: 1; }
.pe-view-link {
    display: inline-flex; align-items: center; gap: .25rem;
    font-size: .8125rem; color: #6b7280; text-decoration: none;
}
.pe-view-link:hover { color: var(--sa-primary, #2563eb); }

/* Tabs */
.pe-tabs {
    display: flex; gap: .25rem; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem;
}
.pe-tab {
    padding: .5rem 1rem; font-size: .875rem; font-weight: 500;
    color: #6b7280; background: none; border: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; cursor: pointer; border-radius: 4px 4px 0 0;
    display: inline-flex; align-items: center; gap: .375rem;
}
.pe-tab.active { color: var(--sa-primary, #2563eb); border-bottom-color: var(--sa-primary, #2563eb); }
.pe-tab-badge {
    background: var(--sa-primary, #2563eb); color: #fff;
    font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 10px;
}

.pe-tab-panel { display: none; }
.pe-tab-panel.active { display: block; }

/* Cards */
.pe-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
.pe-card-header {
    padding: .75rem 1.25rem; font-weight: 600; font-size: .875rem;
    color: #111827; background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    border-radius: 10px 10px 0 0;
}
.pe-card-body { padding: 1.25rem; }
.pe-card-body--compact { padding: .75rem; }

/* Form fields */
.pe-field { margin-bottom: 1rem; }
.pe-field:last-child { margin-bottom: 0; }
.pe-field label {
    display: block; font-size: .8125rem; font-weight: 600;
    color: #374151; margin-bottom: .375rem;
}
.pe-required { color: #dc2626; }
.pe-input, .pe-textarea, .pe-select {
    width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db;
    border-radius: 6px; font-size: .875rem; color: #111827;
    background: #fff; box-sizing: border-box;
}
.pe-input--lg { font-size: 1rem; padding: .625rem .875rem; }
.pe-textarea { resize: vertical; font-family: inherit; }
.pe-input:focus, .pe-textarea:focus, .pe-select:focus {
    outline: none; border-color: var(--sa-primary,#2563eb);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.pe-slug-row { display: flex; align-items: center; }
.pe-slug-prefix {
    background: #f3f4f6; border: 1px solid #d1d5db; border-right: none;
    border-radius: 6px 0 0 6px; padding: .5rem .75rem;
    font-size: .8125rem; color: #6b7280; white-space: nowrap;
}
.pe-slug-row .pe-input { border-radius: 0 6px 6px 0; }
.pe-char-count { font-size: .75rem; color: #9ca3af; display: block; text-align: right; margin-top: .25rem; }

/* Buttons */
.pe-btn {
    display: inline-flex; align-items: center; gap: .375rem;
    padding: .5rem 1rem; border-radius: 6px; border: none;
    font-size: .875rem; font-weight: 600; cursor: pointer; transition: opacity .15s;
}
.pe-btn-primary { background: var(--sa-primary,#2563eb); color: #fff; }
.pe-btn-primary:hover { opacity: .9; }
.pe-btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
.pe-btn-add {
    background: #f0fdf4; color: #166534; border: 1px dashed #86efac;
    width: 100%; justify-content: center; padding: .75rem;
    border-radius: 8px; font-size: .875rem; font-weight: 600;
}
.pe-btn-add:hover { background: #dcfce7; }
.pe-btn-full { width: 100%; justify-content: center; }
.pe-actions { display: flex; gap: .75rem; margin-top: 1.25rem; }

/* Section rows */
.pe-section-row {
    display: flex; align-items: center; gap: .75rem;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: .75rem 1rem; margin-bottom: .5rem;
    transition: border-color .15s, box-shadow .15s;
}
.pe-section-row:hover { border-color: #d1d5db; box-shadow: 0 2px 6px rgba(0,0,0,.06); }
.pe-section-row__drag { cursor: grab; color: #9ca3af; flex-shrink: 0; }
.pe-section-row__icon { flex-shrink: 0; }
.pe-section-row__info { flex: 1; min-width: 0; }
.pe-section-row__info strong { display: block; font-size: .875rem; color: #111827; }
.pe-section-row__info span  { font-size: .75rem; color: #9ca3af; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; max-width: 300px; }
.pe-section-row__actions { display: flex; gap: .25rem; flex-shrink: 0; }
.pe-sec-btn {
    padding: .3rem .6rem; border-radius: 5px; border: 1px solid #e5e7eb;
    background: #fff; font-size: .8125rem; font-weight: 500; cursor: pointer; color: #374151;
}
.pe-sec-btn:hover { background: #f9fafb; }
.pe-sec-btn-edit   { color: var(--sa-primary,#2563eb); }
.pe-sec-btn-delete { color: #dc2626; border-color: #fecaca; }
.pe-sec-btn-delete:hover { background: #fef2f2; }

.pe-add-section { padding: .5rem 0 0; }
.pe-empty {
    text-align: center; padding: 3rem 2rem; color: #9ca3af;
    border: 2px dashed #e5e7eb; border-radius: 10px; margin-bottom: 1rem;
}
.pe-empty p { margin: .75rem 0 0; font-size: .9375rem; line-height: 1.6; }

/* Sidebar */
.pe-sidebar-meta { font-size: .75rem; color: #9ca3af; margin-top: .5rem; line-height: 1.8; }
.pe-sidebar-sec {
    display: flex; align-items: center; gap: .5rem;
    padding: .35rem 0; border-bottom: 1px solid #f3f4f6; font-size: .8125rem; color: #374151;
}
.pe-sidebar-sec:last-of-type { border-bottom: none; }
.pe-sidebar-sec__num {
    width: 18px; height: 18px; background: #f3f4f6; border-radius: 50%;
    font-size: .7rem; font-weight: 700; color: #6b7280;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

/* ── Modal ─────────────────────────────────────────────────────── */
.pe-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    z-index: 1000; backdrop-filter: blur(2px);
}
.pe-modal {
    position: fixed; top: 50%; left: 50%; transform: translate(-50%,-50%);
    background: #fff; border-radius: 14px; z-index: 1001;
    width: min(640px, calc(100vw - 2rem));
    max-height: 85vh; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    overflow: hidden;
}
.pe-modal--wide { width: min(860px, calc(100vw - 2rem)); }
.pe-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;
}
.pe-modal-header h2 { font-size: 1.125rem; font-weight: 700; color: #111827; margin: 0; }
.pe-modal-close {
    width: 32px; height: 32px; border-radius: 8px; border: none;
    background: #f3f4f6; color: #6b7280; font-size: 1.25rem; cursor: pointer;
    display: flex; align-items: center; justify-content: center; line-height: 1;
}
.pe-modal-close:hover { background: #e5e7eb; color: #111827; }
.pe-modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
.pe-modal-footer {
    padding: 1rem 1.5rem; border-top: 1px solid #e5e7eb;
    display: flex; gap: .75rem; flex-shrink: 0;
    background: #f9fafb;
}

/* ── Picker grid ───────────────────────────────────────────────── */
.pe-picker-grid {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: .875rem;
}
@media (max-width: 600px) { .pe-picker-grid { grid-template-columns: repeat(2, 1fr); } }
.pe-picker-card {
    border: 2px solid #e5e7eb; border-radius: 10px;
    background: #fff; cursor: pointer; text-align: left;
    padding: 0; overflow: hidden; transition: border-color .15s, box-shadow .15s;
}
.pe-picker-card:hover { border-color: var(--sa-primary,#2563eb); box-shadow: 0 4px 14px rgba(37,99,235,.12); }
.pe-picker-card__preview { width: 100%; aspect-ratio: 120/70; overflow: hidden; }
.pe-picker-card__preview svg { width: 100%; height: 100%; display: block; }
.pe-picker-card__info { padding: .625rem .875rem; }
.pe-picker-card__info strong { display: block; font-size: .875rem; color: #111827; margin-bottom: .125rem; }
.pe-picker-card__info span  { font-size: .75rem; color: #6b7280; line-height: 1.4; }

/* ── Section edit form fields ──────────────────────────────────── */
.se-field { margin-bottom: 1rem; }
.se-field:last-child { margin-bottom: 0; }
.se-field label {
    display: block; font-size: .8125rem; font-weight: 600;
    color: #374151; margin-bottom: .375rem;
}
.se-input, .se-textarea, .se-select {
    width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db;
    border-radius: 6px; font-size: .875rem; color: #111827;
    background: #fff; box-sizing: border-box;
}
.se-textarea { resize: vertical; min-height: 80px; font-family: inherit; }
.se-input:focus, .se-textarea:focus, .se-select:focus {
    outline: none; border-color: var(--sa-primary,#2563eb);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.se-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.se-repeater { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
.se-repeater-item {
    padding: 1rem; border-bottom: 1px solid #f3f4f6; background: #fff;
    position: relative;
}
.se-repeater-item:last-child { border-bottom: none; }
.se-repeater-header {
    display: flex; align-items: center; justify-content: space-between;
    font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: .75rem;
}
.se-repeater-del {
    background: none; border: none; color: #dc2626; cursor: pointer;
    font-size: 1rem; line-height: 1; padding: 2px 4px;
}
.se-repeater-add {
    width: 100%; padding: .625rem; background: #f9fafb; border: none;
    border-top: 1px solid #f3f4f6; font-size: .8125rem; font-weight: 600;
    color: var(--sa-primary,#2563eb); cursor: pointer; text-align: center;
}
.se-repeater-add:hover { background: #f0f9ff; }
.se-section-divider {
    font-size: .75rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: #9ca3af; margin: 1.25rem 0 .75rem;
    padding-bottom: .375rem; border-bottom: 1px solid #f3f4f6;
}
</style>

<script>
const PAGE_ID   = <?= $pageId ?>;
const SITE_ADMIN_API = '/site-admin/api/sections.php';
const BLOCK_TYPES = <?= json_encode($blockTypes, JSON_HEX_TAG) ?>;

// ── Tab switching ──────────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.pe-tab').forEach(t => {
        t.classList.toggle('active', t.dataset.tab === name);
    });
    document.querySelectorAll('.pe-tab-panel').forEach(p => {
        p.classList.toggle('active', p.id === 'panel-' + name);
    });
}
document.querySelectorAll('.pe-tab').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});

// ── Char counter for meta description ────────────────────────────────────────
const metaDesc = document.querySelector('[name=meta_description]');
const metaCount = document.getElementById('metaDescCount');
if (metaDesc && metaCount) {
    const update = () => {
        const n = metaDesc.value.length;
        metaCount.textContent = n + ' / 160';
        metaCount.style.color = n > 160 ? '#dc2626' : (n >= 130 ? '#d97706' : '#9ca3af');
    };
    metaDesc.addEventListener('input', update);
    update();
}

// ── Auto-slug from title ──────────────────────────────────────────────────────
const titleInput = document.querySelector('[name=title]');
const slugInput  = document.getElementById('slugInput');
if (titleInput && slugInput && !slugInput.value) {
    titleInput.addEventListener('input', () => {
        slugInput.value = titleInput.value
            .toLowerCase().trim()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    });
}

// ── Section picker ────────────────────────────────────────────────────────────
function openPicker() {
    document.getElementById('pickerOverlay').style.display = 'block';
    document.getElementById('pickerModal').style.display   = 'flex';
}
function closePicker() {
    document.getElementById('pickerOverlay').style.display = 'none';
    document.getElementById('pickerModal').style.display   = 'none';
}

let _currentEditId   = null;
let _currentEditType = null;

function pickType(blockType) {
    closePicker();
    _currentEditId   = null;
    _currentEditType = blockType;
    const typeDef = BLOCK_TYPES[blockType];
    document.getElementById('editModalTitle').textContent = 'Add: ' + typeDef.label;
    document.getElementById('editModalBody').innerHTML = buildEditForm(blockType, typeDef.defaults || {});
    document.getElementById('editOverlay').style.display = 'block';
    document.getElementById('editModal').style.display   = 'flex';
    if (window.feather) feather.replace();
}

function editSection(id, blockType) {
    // Fetch current data from the DOM section row context — or just open blank form
    // We'll fetch via API
    _currentEditId   = id;
    _currentEditType = blockType;
    const typeDef = BLOCK_TYPES[blockType];
    document.getElementById('editModalTitle').textContent = 'Edit: ' + typeDef.label;

    // Build form with existing data from section rows (data-* attrs not stored, do AJAX)
    fetch(SITE_ADMIN_API + '?action=list&page_id=' + PAGE_ID)
        .then(r => r.json())
        .then(res => {
            const sec = res.sections.find(s => s.id == id);
            const data = sec ? sec.data : (typeDef.defaults || {});
            document.getElementById('editModalBody').innerHTML = buildEditForm(blockType, data);
            document.getElementById('editOverlay').style.display = 'block';
            document.getElementById('editModal').style.display   = 'flex';
            if (window.feather) feather.replace();
        });
}

function closeEdit() {
    document.getElementById('editOverlay').style.display = 'none';
    document.getElementById('editModal').style.display   = 'none';
    _currentEditId   = null;
    _currentEditType = null;
}

// ── Build edit form HTML from block type schema ───────────────────────────────
function buildEditForm(blockType, data) {
    const typeDef = BLOCK_TYPES[blockType];
    if (!typeDef) return '<p>Unknown block type.</p>';
    let html = '';

    for (const [key, field] of Object.entries(typeDef.fields)) {
        if (field.type === 'repeater') {
            html += buildRepeaterField(key, field, data[key] || [{}]);
        } else {
            html += buildField(key, field, data[key] ?? field.default ?? '');
        }
    }
    return html;
}

function buildField(key, field, value) {
    const label = `<label>${escHtml(field.label || key)}${field.required ? ' <span style="color:#dc2626">*</span>' : ''}</label>`;
    let input = '';
    const ph = escHtml(field.placeholder || '');
    const val = escHtml(String(value ?? ''));

    if (field.type === 'select') {
        input = `<select class="se-select" name="${key}">`;
        for (const [v, l] of Object.entries(field.options || {})) {
            input += `<option value="${escHtml(v)}" ${value == v ? 'selected' : ''}>${escHtml(l)}</option>`;
        }
        input += '</select>';
    } else if (field.type === 'textarea' || field.type === 'richtext') {
        input = `<textarea class="se-textarea" name="${key}" placeholder="${ph}" rows="${field.type==='richtext'?6:3}">${val}</textarea>`;
        if (field.type === 'richtext') input += `<p style="font-size:.72rem;color:#9ca3af;margin:.25rem 0 0">HTML allowed</p>`;
    } else {
        const inputType = field.type === 'url' ? 'url' : 'text';
        input = `<input type="${inputType}" class="se-input" name="${key}" value="${val}" placeholder="${ph}">`;
    }
    return `<div class="se-field">${label}${input}</div>`;
}

function buildRepeaterField(key, field, items) {
    const maxItems = field.max || 6;
    let html = `<div class="se-section-divider">${escHtml(field.label)}</div>`;
    html += `<div class="se-repeater" id="rep-${key}">`;
    (items.length ? items : [{}]).forEach((item, i) => {
        html += buildRepeaterItem(key, field, item, i);
    });
    html += `<button type="button" class="se-repeater-add" onclick="addRepeaterItem('${key}')" id="rep-add-${key}">+ Add Item</button>`;
    html += '</div>';
    return html;
}

function buildRepeaterItem(key, field, data, index) {
    let html = `<div class="se-repeater-item" data-rep-item="${key}">`;
    html += `<div class="se-repeater-header">
        <span>Item ${index + 1}</span>
        <button type="button" class="se-repeater-del" onclick="removeRepeaterItem(this)" title="Remove">×</button>
    </div>`;
    const subFields = field.item_fields || {};
    const isTwo = Object.keys(subFields).length > 2;
    if (isTwo) html += '<div class="se-two-col">';
    for (const [subKey, subField] of Object.entries(subFields)) {
        html += buildField(`${key}[${index}][${subKey}]`, subField, data[subKey] ?? '');
    }
    if (isTwo) html += '</div>';
    html += '</div>';
    return html;
}

function addRepeaterItem(key) {
    const container = document.getElementById('rep-' + key);
    const addBtn    = document.getElementById('rep-add-' + key);
    const items     = container.querySelectorAll('[data-rep-item]');
    const typeDef   = BLOCK_TYPES[_currentEditType];
    if (!typeDef) return;
    const field     = typeDef.fields[key];
    if (!field) return;
    if (items.length >= (field.max || 6)) {
        alert('Maximum ' + (field.max || 6) + ' items reached.');
        return;
    }
    const newItem = document.createElement('div');
    newItem.innerHTML = buildRepeaterItem(key, field, {}, items.length);
    container.insertBefore(newItem.firstElementChild, addBtn);
    renumberRepeaterItems(container, key, field);
}

function removeRepeaterItem(btn) {
    const item = btn.closest('[data-rep-item]');
    const container = item.parentElement;
    const key = item.dataset.repItem;
    item.remove();
    const typeDef = BLOCK_TYPES[_currentEditType];
    if (typeDef) renumberRepeaterItems(container, key, typeDef.fields[key]);
}

function renumberRepeaterItems(container, key, field) {
    container.querySelectorAll('[data-rep-item]').forEach((item, i) => {
        item.querySelector('.se-repeater-header span').textContent = 'Item ' + (i + 1);
        item.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace(new RegExp(`^${key}\\[\\d+\\]`), `${key}[${i}]`);
        });
    });
}

// ── Collect form data from edit modal ─────────────────────────────────────────
function collectFormData() {
    const body = document.getElementById('editModalBody');
    const data = {};
    const typeDef = BLOCK_TYPES[_currentEditType];
    if (!typeDef) return data;

    // Simple fields
    body.querySelectorAll('[name]').forEach(el => {
        const name = el.name;
        if (name.includes('[')) return; // repeater — handled separately
        data[name] = el.value;
    });

    // Repeater fields
    for (const [key, field] of Object.entries(typeDef.fields)) {
        if (field.type !== 'repeater') continue;
        const items = [];
        body.querySelectorAll(`[data-rep-item="${key}"]`).forEach(itemEl => {
            const item = {};
            itemEl.querySelectorAll('[name]').forEach(el => {
                const match = el.name.match(/\[(\d+)\]\[(\w+)\]$/);
                if (match) item[match[2]] = el.value;
            });
            items.push(item);
        });
        data[key] = items;
    }
    return data;
}

// ── Save section (create or update) ──────────────────────────────────────────
function saveSection() {
    const formData = collectFormData();

    if (_currentEditId) {
        // Update existing
        fetch(SITE_ADMIN_API + '?action=update', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: _currentEditId, data: formData}),
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { closeEdit(); location.reload(); }
            else alert('Error: ' + (res.error || 'Unknown error'));
        });
    } else {
        // Create new
        const sortOrder = document.querySelectorAll('.pe-section-row').length * 10;
        fetch(SITE_ADMIN_API + '?action=create', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({page_id: PAGE_ID, block_type: _currentEditType, data: formData, sort_order: sortOrder}),
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { closeEdit(); location.reload(); }
            else alert('Error: ' + (res.error || 'Unknown error'));
        });
    }
}

// ── Delete section ────────────────────────────────────────────────────────────
function deleteSection(id) {
    if (!confirm('Remove this section?')) return;
    fetch(SITE_ADMIN_API + '?action=delete', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id}),
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Error: ' + (res.error || 'Delete failed'));
    });
}

// ── Move section up/down ──────────────────────────────────────────────────────
function moveSection(btn, dir) {
    const row   = btn.closest('.pe-section-row');
    const list  = document.getElementById('sectionsList');
    if (dir === 'up' && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling);
    } else if (dir === 'down' && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row);
    }
    saveOrder();
}

function saveOrder() {
    const ids = [...document.querySelectorAll('.pe-section-row')].map(r => parseInt(r.dataset.id));
    fetch(SITE_ADMIN_API + '?action=reorder', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ids}),
    });
}

// ── Escape helper ─────────────────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Close modal on Escape ─────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closePicker(); closeEdit(); }
});
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
