<?php
/**
 * /site-admin/settings.php
 * Site Settings — Theme, General, SEO
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
requireSiteLogin();

require_once dirname(__DIR__) . '/app/Modules/CMS/Services/CmsThemeFunctions.php';

$siteId   = (int)CMS_SITE_ID;
$siteUser = getSiteUser();
$saved    = false;
$error    = '';

// ── Handle form submit ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_tab'])) {
    $tab = $_POST['_tab'];

    if ($tab === 'theme') {
        $result = cms_saveTheme($siteId, [
            'name'          => trim($_POST['theme_name'] ?? 'Default'),
            'color_primary' => trim($_POST['color_primary'] ?? '#2D8659'),
            'color_dark'    => trim($_POST['color_dark']    ?? '#1A5F4A'),
            'color_accent'  => trim($_POST['color_accent']  ?? '#e85d04'),
            'color_bg_dark' => trim($_POST['color_bg_dark'] ?? '#0d1f12'),
            'color_text'    => trim($_POST['color_text']    ?? '#1a1a1a'),
            'font_heading'  => trim($_POST['font_heading']  ?? 'Playfair Display'),
            'font_body'     => trim($_POST['font_body']     ?? 'DM Sans'),
            'custom_css'    => trim($_POST['custom_css']    ?? ''),
        ]);
        $saved = $result;
        if (!$result) $error = 'Failed to save theme. Please try again.';
    }
}

// ── Load current theme ────────────────────────────────────────────────────────
$theme = cms_getActiveTheme($siteId);

// ── Active tab (from query or default) ───────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'theme';
$allowedTabs = ['theme', 'general', 'seo'];
if (!in_array($activeTab, $allowedTabs)) $activeTab = 'theme';

$pageTitle = 'Settings';
include __DIR__ . '/includes/head.php';
include __DIR__ . '/includes/nav.php';
?>

<div class="sa-page-header">
    <h1>Settings</h1>
</div>

<?php if ($saved): ?>
<div class="sa-alert sa-alert-success">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    Settings saved successfully.
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="sa-alert sa-alert-error">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Tab nav -->
<div class="sa-tabs">
    <a href="?tab=theme"   class="sa-tab <?= $activeTab === 'theme'   ? 'active' : '' ?>">Theme</a>
    <a href="?tab=general" class="sa-tab <?= $activeTab === 'general' ? 'active' : '' ?>">General</a>
    <a href="?tab=seo"     class="sa-tab <?= $activeTab === 'seo'     ? 'active' : '' ?>">SEO</a>
</div>

<!-- ═══════════════════════════ THEME TAB ════════════════════════════════════ -->
<?php if ($activeTab === 'theme'): ?>

<form method="POST" id="themeForm">
    <input type="hidden" name="_tab" value="theme">

    <div class="sa-settings-grid">

        <!-- Left: controls -->
        <div class="sa-settings-col">

            <div class="sa-card">
                <div class="sa-card-header">Brand Colours</div>
                <div class="sa-card-body">

                    <div class="sa-color-row">
                        <label class="sa-color-label">
                            <input type="color" name="color_primary"
                                   value="<?= htmlspecialchars($theme['color_primary']) ?>"
                                   class="sa-color-input" data-preview="primary">
                            <span>
                                <strong>Primary</strong>
                                <small>Main buttons, links, headings</small>
                            </span>
                            <code class="sa-hex-display" id="hex-primary"><?= htmlspecialchars($theme['color_primary']) ?></code>
                        </label>
                    </div>

                    <div class="sa-color-row">
                        <label class="sa-color-label">
                            <input type="color" name="color_dark"
                                   value="<?= htmlspecialchars($theme['color_dark']) ?>"
                                   class="sa-color-input" data-preview="dark">
                            <span>
                                <strong>Primary Dark</strong>
                                <small>Hover states, gradients</small>
                            </span>
                            <code class="sa-hex-display" id="hex-dark"><?= htmlspecialchars($theme['color_dark']) ?></code>
                        </label>
                    </div>

                    <div class="sa-color-row">
                        <label class="sa-color-label">
                            <input type="color" name="color_accent"
                                   value="<?= htmlspecialchars($theme['color_accent']) ?>"
                                   class="sa-color-input" data-preview="accent">
                            <span>
                                <strong>Accent / CTA</strong>
                                <small>Call-to-action buttons</small>
                            </span>
                            <code class="sa-hex-display" id="hex-accent"><?= htmlspecialchars($theme['color_accent']) ?></code>
                        </label>
                    </div>

                    <div class="sa-color-row">
                        <label class="sa-color-label">
                            <input type="color" name="color_bg_dark"
                                   value="<?= htmlspecialchars($theme['color_bg_dark']) ?>"
                                   class="sa-color-input" data-preview="bgdark">
                            <span>
                                <strong>Dark Background</strong>
                                <small>Hero sections, footers</small>
                            </span>
                            <code class="sa-hex-display" id="hex-bgdark"><?= htmlspecialchars($theme['color_bg_dark']) ?></code>
                        </label>
                    </div>

                    <div class="sa-color-row">
                        <label class="sa-color-label">
                            <input type="color" name="color_text"
                                   value="<?= htmlspecialchars($theme['color_text']) ?>"
                                   class="sa-color-input" data-preview="text">
                            <span>
                                <strong>Body Text</strong>
                                <small>Main content text colour</small>
                            </span>
                            <code class="sa-hex-display" id="hex-text"><?= htmlspecialchars($theme['color_text']) ?></code>
                        </label>
                    </div>

                </div>
            </div>

            <div class="sa-card" style="margin-top:1rem">
                <div class="sa-card-header">Typography</div>
                <div class="sa-card-body">

                    <div class="sa-form-group">
                        <label>Heading Font</label>
                        <select name="font_heading" class="sa-select" id="fontHeadingSelect">
                            <?php foreach (CMS_HEADING_FONTS as $fname => $furl): ?>
                            <option value="<?= htmlspecialchars($fname) ?>"
                                <?= ($theme['font_heading'] ?? '') === $fname ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fname) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sa-font-preview" id="headingPreviewText">
                            The Quick Brown Fox Jumps
                        </p>
                    </div>

                    <div class="sa-form-group" style="margin-top:1rem">
                        <label>Body Font</label>
                        <select name="font_body" class="sa-select" id="fontBodySelect">
                            <?php foreach (CMS_BODY_FONTS as $fname => $furl): ?>
                            <option value="<?= htmlspecialchars($fname) ?>"
                                <?= ($theme['font_body'] ?? '') === $fname ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fname) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sa-font-preview sa-font-preview--body" id="bodyPreviewText">
                            Landscaping services for residential and commercial properties.
                        </p>
                    </div>

                </div>
            </div>

            <div class="sa-card" style="margin-top:1rem">
                <div class="sa-card-header">Custom CSS <span class="sa-badge-optional">optional</span></div>
                <div class="sa-card-body">
                    <textarea name="custom_css" class="sa-textarea sa-textarea--code"
                              rows="8" placeholder="/* Add extra CSS overrides here */"><?= htmlspecialchars($theme['custom_css'] ?? '') ?></textarea>
                    <p class="sa-hint">Injected after all other styles. Useful for fine-tuning without touching CSS files.</p>
                </div>
            </div>

        </div>

        <!-- Right: live preview -->
        <div class="sa-settings-preview-col">
            <div class="sa-card sa-preview-sticky">
                <div class="sa-card-header">Live Preview</div>
                <div class="sa-card-body sa-card-body--preview">
                    <div class="sa-preview" id="livePreview">

                        <!-- Hero strip -->
                        <div class="sp-hero" id="prev-hero">
                            <div class="sp-hero-inner">
                                <div class="sp-badge" id="prev-badge">Professional Service</div>
                                <h2 class="sp-heading" id="prev-heading">Premium Landscaping</h2>
                                <p class="sp-body" id="prev-body">Residential &amp; commercial grounds maintenance since 2018.</p>
                                <button class="sp-btn-cta" id="prev-cta">Get a Free Quote</button>
                            </div>
                        </div>

                        <!-- Feature strip -->
                        <div class="sp-features">
                            <div class="sp-feature">
                                <div class="sp-feature-icon" id="prev-icon1">✓</div>
                                <div class="sp-feature-text" id="prev-feat1">Licensed &amp; Insured</div>
                            </div>
                            <div class="sp-feature">
                                <div class="sp-feature-icon" id="prev-icon2">✓</div>
                                <div class="sp-feature-text" id="prev-feat2">Free Estimates</div>
                            </div>
                            <div class="sp-feature">
                                <div class="sp-feature-icon" id="prev-icon3">✓</div>
                                <div class="sp-feature-text" id="prev-feat3">Satisfaction Guaranteed</div>
                            </div>
                        </div>

                        <!-- Card strip -->
                        <div class="sp-cards">
                            <div class="sp-card">
                                <div class="sp-card-title" id="prev-card1-title">Lawn Care</div>
                                <div class="sp-card-body" id="prev-card1-body">Weekly mowing, edging, and cleanup.</div>
                                <a class="sp-card-link" id="prev-card1-link">Learn more →</a>
                            </div>
                            <div class="sp-card">
                                <div class="sp-card-title" id="prev-card2-title">Garden Design</div>
                                <div class="sp-card-body" id="prev-card2-body">Custom planting plans &amp; seasonal colour.</div>
                                <a class="sp-card-link" id="prev-card2-link">Learn more →</a>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="sa-card-footer">
                    <button type="submit" class="sa-btn sa-btn-primary sa-btn-full">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Theme
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /.sa-settings-grid -->
</form>

<?php endif; // theme tab ?>

<!-- ═══════════════════════════ GENERAL TAB ══════════════════════════════════ -->
<?php if ($activeTab === 'general'): ?>
<div class="sa-card" style="max-width:600px">
    <div class="sa-card-header">General Settings</div>
    <div class="sa-card-body">
        <p class="sa-hint" style="margin:0">General settings (site name, contact info, logo upload) are coming in a future update.</p>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════ SEO TAB ══════════════════════════════════════ -->
<?php if ($activeTab === 'seo'): ?>
<div class="sa-card" style="max-width:600px">
    <div class="sa-card-header">SEO Settings</div>
    <div class="sa-card-body">
        <p class="sa-hint" style="margin:0">Global SEO defaults (meta title template, GA4 ID, sitemap settings) are coming in a future update.</p>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════ STYLES & SCRIPTS ══════════════════════════════ -->
<style>
/* Settings page layout */
.sa-page-header { margin-bottom: 1.5rem; }
.sa-page-header h1 { font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0; }

.sa-alert {
    display: flex; align-items: center; gap: .5rem;
    padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1.25rem;
    font-size: .875rem; font-weight: 500;
}
.sa-alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.sa-alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* Tabs */
.sa-tabs { display: flex; gap: .25rem; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem; }
.sa-tab {
    padding: .5rem 1rem; font-size: .875rem; font-weight: 500;
    color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; border-radius: 4px 4px 0 0; transition: color .15s;
}
.sa-tab:hover { color: #111827; }
.sa-tab.active { color: var(--sa-primary, #2563eb); border-bottom-color: var(--sa-primary, #2563eb); }

/* Two-column settings layout */
.sa-settings-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 960px) {
    .sa-settings-grid { grid-template-columns: 1fr; }
}

/* Card tweaks */
.sa-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
.sa-card-header {
    padding: .875rem 1.25rem; font-weight: 600; font-size: .875rem;
    color: #111827; background: #f9fafb; border-bottom: 1px solid #e5e7eb;
    display: flex; align-items: center; gap: .5rem;
}
.sa-card-body { padding: 1.25rem; }
.sa-card-body--preview { padding: 0; }
.sa-card-footer { padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; background: #f9fafb; }

.sa-badge-optional {
    font-size: 10px; font-weight: 500; background: #e5e7eb; color: #6b7280;
    padding: 2px 6px; border-radius: 4px; margin-left: auto;
}

/* Color rows */
.sa-color-row { padding: .625rem 0; border-bottom: 1px solid #f3f4f6; }
.sa-color-row:last-child { border-bottom: none; }
.sa-color-label {
    display: flex; align-items: center; gap: .875rem; cursor: pointer;
}
.sa-color-input {
    width: 44px; height: 44px; border-radius: 8px; border: 2px solid #e5e7eb;
    cursor: pointer; padding: 2px; background: none;
    flex-shrink: 0;
}
.sa-color-input::-webkit-color-swatch-wrapper { padding: 0; border-radius: 5px; }
.sa-color-input::-webkit-color-swatch { border: none; border-radius: 5px; }
.sa-color-label > span { flex: 1; }
.sa-color-label > span strong { display: block; font-size: .875rem; color: #111827; }
.sa-color-label > span small  { font-size: .75rem; color: #6b7280; }
.sa-hex-display {
    font-size: .75rem; font-family: monospace; background: #f3f4f6;
    padding: 3px 7px; border-radius: 5px; color: #374151; flex-shrink: 0;
}

/* Typography */
.sa-select {
    width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db;
    border-radius: 6px; font-size: .875rem; color: #111827;
    background: #fff; cursor: pointer;
}
.sa-font-preview {
    margin: .625rem 0 0; padding: .625rem .75rem;
    background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;
    font-size: 1.125rem; color: #111827; line-height: 1.4;
    transition: font-family .2s;
}
.sa-font-preview--body { font-size: .9375rem; }

/* Custom CSS textarea */
.sa-textarea {
    width: 100%; border: 1px solid #d1d5db; border-radius: 6px;
    padding: .625rem .75rem; font-size: .875rem; color: #111827;
    resize: vertical; background: #fff;
}
.sa-textarea--code { font-family: 'Fira Code', 'Consolas', monospace; font-size: .8125rem; }
.sa-hint { font-size: .75rem; color: #6b7280; margin: .5rem 0 0; }

/* Sticky preview column */
.sa-preview-sticky { position: sticky; top: 1.5rem; }

/* Live preview mockup */
.sa-preview { font-family: system-ui, sans-serif; user-select: none; }

.sp-hero {
    padding: 1.25rem; background: #0d1f12; transition: background .15s;
}
.sp-hero-inner { max-width: 100%; }
.sp-badge {
    display: inline-block; font-size: 10px; font-weight: 600; letter-spacing: .05em;
    text-transform: uppercase; padding: 3px 8px; border-radius: 20px;
    background: rgba(255,255,255,.15); color: #7FD858; margin-bottom: .5rem;
}
.sp-heading {
    font-size: 1.125rem; font-weight: 700; color: #fff; margin: 0 0 .375rem;
    line-height: 1.3; transition: font-family .2s;
}
.sp-body { font-size: .8rem; color: rgba(255,255,255,.7); margin: 0 0 .75rem; transition: font-family .2s; }
.sp-btn-cta {
    padding: .5rem 1rem; border-radius: 6px; border: none; cursor: pointer;
    font-size: .8rem; font-weight: 600; color: #fff; background: #e85d04;
    transition: background .15s;
}

.sp-features {
    display: flex; gap: 0; background: #fff;
}
.sp-feature {
    flex: 1; padding: .625rem .5rem; text-align: center;
    border-right: 1px solid #f3f4f6;
}
.sp-feature:last-child { border-right: none; }
.sp-feature-icon {
    font-size: .875rem; font-weight: 700; color: #2D8659;
    transition: color .15s;
}
.sp-feature-text { font-size: .7rem; color: #4a4a4a; margin-top: 2px; }

.sp-cards { display: grid; grid-template-columns: 1fr 1fr; background: #f9fafb; }
.sp-card {
    padding: .875rem; border: 1px solid #e5e7eb; background: #fff;
    margin: .5rem; border-radius: 8px;
}
.sp-card-title { font-size: .875rem; font-weight: 600; color: #111827; margin-bottom: .25rem; transition: font-family .2s; }
.sp-card-body  { font-size: .75rem; color: #6b7280; margin-bottom: .5rem; transition: font-family .2s; }
.sp-card-link  { font-size: .75rem; font-weight: 600; color: #2D8659; text-decoration: none; transition: color .15s; }

/* Buttons */
.sa-btn { display: inline-flex; align-items: center; justify-content: center; gap: .375rem;
    padding: .625rem 1.25rem; border-radius: 6px; border: none; cursor: pointer;
    font-size: .875rem; font-weight: 600; transition: opacity .15s; }
.sa-btn-primary { background: var(--sa-primary, #2563eb); color: #fff; }
.sa-btn-primary:hover { opacity: .9; }
.sa-btn-full { width: 100%; }

.sa-form-group label { display: block; font-size: .8125rem; font-weight: 600; color: #374151; margin-bottom: .375rem; }
</style>

<script>
// ── Font loading helper ───────────────────────────────────────────────────────
const googleFonts = <?= json_encode(array_filter(CMS_HEADING_FONTS + CMS_BODY_FONTS)) ?>;
const loadedFonts = new Set();

function loadGoogleFont(family) {
    if (!googleFonts[family] || loadedFonts.has(family)) return;
    loadedFonts.add(family);
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' + googleFonts[family] + '&display=swap';
    document.head.appendChild(link);
}

// ── Live preview update ───────────────────────────────────────────────────────
function applyPreview() {
    const primary = document.querySelector('[name=color_primary]')?.value || '#2D8659';
    const dark    = document.querySelector('[name=color_dark]')?.value    || '#1A5F4A';
    const accent  = document.querySelector('[name=color_accent]')?.value  || '#e85d04';
    const bgdark  = document.querySelector('[name=color_bg_dark]')?.value || '#0d1f12';
    const fh      = document.querySelector('[name=font_heading]')?.value  || 'Playfair Display';
    const fb      = document.querySelector('[name=font_body]')?.value     || 'DM Sans';

    // Load fonts lazily
    loadGoogleFont(fh);
    loadGoogleFont(fb);

    // Apply to preview
    const hero = document.getElementById('prev-hero');
    if (hero) hero.style.background = bgdark;

    const cta = document.getElementById('prev-cta');
    if (cta) cta.style.background = accent;

    ['prev-icon1','prev-icon2','prev-icon3'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.color = primary;
    });

    ['prev-card1-link','prev-card2-link'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.color = primary;
    });

    // Typography
    const headingEls = ['prev-heading'];
    headingEls.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.fontFamily = `'${fh}', Georgia, serif`;
    });

    const bodyEls = ['prev-body','prev-feat1','prev-feat2','prev-feat3',
                     'prev-card1-body','prev-card2-body'];
    bodyEls.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.fontFamily = `'${fb}', system-ui, sans-serif`;
    });

    // Heading preview
    const hp = document.getElementById('headingPreviewText');
    if (hp) hp.style.fontFamily = `'${fh}', Georgia, serif`;
    const bp = document.getElementById('bodyPreviewText');
    if (bp) bp.style.fontFamily = `'${fb}', system-ui, sans-serif`;
}

// ── Hex display sync ──────────────────────────────────────────────────────────
document.querySelectorAll('.sa-color-input').forEach(input => {
    const key = input.dataset.preview;
    const display = document.getElementById('hex-' + key);
    input.addEventListener('input', () => {
        if (display) display.textContent = input.value;
        applyPreview();
    });
});

// ── Font change ───────────────────────────────────────────────────────────────
document.getElementById('fontHeadingSelect')?.addEventListener('change', applyPreview);
document.getElementById('fontBodySelect')?.addEventListener('change', applyPreview);

// ── Init ──────────────────────────────────────────────────────────────────────
applyPreview();

// Pre-load currently selected fonts
loadGoogleFont(document.getElementById('fontHeadingSelect')?.value || '');
loadGoogleFont(document.getElementById('fontBodySelect')?.value || '');
</script>

<?php include __DIR__ . '/includes/foot.php'; ?>
