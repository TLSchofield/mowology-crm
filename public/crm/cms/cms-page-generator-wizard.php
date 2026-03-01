<?php
/**
 * CMS Page Generator Wizard - Template-Driven Landing Page Generation
 *
 * Provides step-by-step wizard UI for staff to generate service landing pages
 * by selecting template, service, and neighbourhood.
 *
 * @package Mowology CRM
 * @subpackage CMS Phase 4
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/page-generator.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Generate Landing Page';
$activePage = 'cms';

// Get available generators, services, neighbourhoods
$generators = pg_getGeneratorConfigs(true);
$services = pg_getServices();
$neighbourhoods = pg_getNeighbourhoods();
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

    <style>
        .wizard-container {
            max-width: 700px;
            margin: 0 auto;
        }

        .wizard-step {
            display: none;
            animation: fadeIn 0.3s ease-in;
        }

        .wizard-step.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .wizard-progress {
            display: flex;
            margin-bottom: 2rem;
            gap: 0.5rem;
        }

        .progress-step {
            flex: 1;
            height: 4px;
            background: #ddd;
            border-radius: 2px;
            transition: background 0.3s;
        }

        .progress-step.active {
            background: var(--mw-green);
        }

        .progress-step.completed {
            background: var(--mw-lime);
        }

        .step-header {
            margin-bottom: 1.5rem;
        }

        .step-header h3 {
            font-size: 1.3rem;
            color: var(--mw-dark);
            margin-bottom: 0.25rem;
        }

        .step-header p {
            color: #666;
            font-size: 0.9rem;
        }

        .generator-cards {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .generator-card {
            border: 2px solid #ddd;
            border-radius: 4px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .generator-card:hover {
            border-color: var(--mw-green);
            background: rgba(45, 134, 89, 0.02);
        }

        .generator-card.selected {
            border-color: var(--mw-green);
            background: rgba(45, 134, 89, 0.05);
        }

        .generator-card input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .generator-card label {
            margin: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .generator-card input[type="radio"]:checked + label::before {
            content: "✓";
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: var(--mw-green);
            color: white;
            border-radius: 50%;
            font-weight: bold;
            flex-shrink: 0;
        }

        .generator-card input[type="radio"]:not(:checked) + label::before {
            content: "";
            display: inline-flex;
            width: 24px;
            height: 24px;
            border: 2px solid #ddd;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .select-group {
            margin-bottom: 1.5rem;
        }

        .select-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }

        .select-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            background-color: white;
        }

        .select-group select:focus {
            outline: none;
            border-color: var(--mw-green);
            box-shadow: 0 0 0 3px rgba(45, 134, 89, 0.1);
        }

        .preview-box {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .preview-row {
            margin-bottom: 0.75rem;
            display: flex;
            gap: 0.5rem;
        }

        .preview-label {
            font-weight: 600;
            color: #666;
            min-width: 120px;
        }

        .preview-value {
            color: #333;
            word-break: break-word;
        }

        .wizard-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 2rem;
            justify-content: space-between;
        }

        .btn-wizard {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-prev {
            background: #ddd;
            color: #333;
        }

        .btn-prev:hover {
            background: #ccc;
        }

        .btn-next, .btn-generate {
            background: var(--mw-green);
            color: white;
            flex-grow: 1;
        }

        .btn-next:hover, .btn-generate:hover {
            background: var(--mw-dark);
        }

        .btn-next:disabled, .btn-generate:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .success-message a {
            color: #155724;
            font-weight: 600;
            text-decoration: underline;
        }

        .loading {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--mw-green);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 0.5rem;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 0.75rem 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .text-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        .text-input:focus {
            outline: none;
            border-color: var(--mw-green);
            box-shadow: 0 0 0 3px rgba(45, 134, 89, 0.1);
        }

        .generator-description {
            font-size: 0.9rem;
            color: #666;
            margin: 0;
        }
    </style>

          <div class="wizard-container">

              <div class="card">
                  <div class="card-header">
                      <h2 data-feather="layout" class="me-2"></h2>
                      Generate Landing Page
                  </div>
                  <div class="card-body">

                      <!-- Wizard Progress (6 steps) -->
                      <div class="wizard-progress">
                          <div class="progress-step active" data-step="1" title="Template"></div>
                          <div class="progress-step" data-step="2" title="Service"></div>
                          <div class="progress-step" data-step="3" title="Location"></div>
                          <div class="progress-step" data-step="4" title="SEO"></div>
                          <div class="progress-step" data-step="5" title="Modules"></div>
                          <div class="progress-step" data-step="6" title="Review"></div>
                      </div>
                      <div class="d-flex justify-content-between mb-3" style="font-size:.7rem;color:#999;margin-top:-0.75rem">
                          <span>Template</span><span>Service</span><span>Location</span><span>SEO</span><span>Modules</span><span>Review</span>
                      </div>

                      <!-- Error messages -->
                      <div id="errorContainer"></div>

                      <!-- Step 1: Select Generator -->
                      <div class="wizard-step active" data-step="1">
                          <div class="step-header">
                              <h3>Choose Template</h3>
                              <p>Select a landing page template to generate from</p>
                          </div>

                          <div class="generator-cards">
                              <?php foreach ($generators as $gen): ?>
                                  <div class="generator-card">
                                      <input type="radio" id="gen_<?php echo h($gen['config_key']); ?>"
                                             name="generator_key" value="<?php echo h($gen['config_key']); ?>">
                                      <label for="gen_<?php echo h($gen['config_key']); ?>">
                                          <div>
                                              <strong><?php echo h($gen['config_label']); ?></strong>
                                              <p class="generator-description">
                                                  <?php echo h($gen['config_data']['description'] ?? 'Service landing page template'); ?>
                                              </p>
                                          </div>
                                      </label>
                                  </div>
                              <?php endforeach; ?>
                          </div>

                          <?php if (empty($generators)): ?>
                              <div class="error-message">
                                  No generator templates found. Please create templates first.
                              </div>
                          <?php endif; ?>
                      </div>

                      <!-- Step 2: Select Service -->
                      <div class="wizard-step" data-step="2">
                          <div class="step-header">
                              <h3>Select Service</h3>
                              <p>Choose which service this page is for</p>
                          </div>

                          <div class="select-group">
                              <label for="serviceSelect">Service *</label>
                              <select id="serviceSelect" name="service">
                                  <option value="">-- Select a service --</option>
                                  <?php foreach ($services as $key => $label): ?>
                                      <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>

                      <!-- Step 3: Select Neighbourhood -->
                      <div class="wizard-step" data-step="3">
                          <div class="step-header">
                              <h3>Select Neighbourhood</h3>
                              <p>Choose which neighbourhood this page targets</p>
                          </div>

                          <div class="select-group">
                              <label for="neighbourhoodSelect">Neighbourhood *</label>
                              <select id="neighbourhoodSelect" name="neighbourhood">
                                  <option value="">-- Select a neighbourhood --</option>
                                  <?php foreach ($neighbourhoods as $key => $label): ?>
                                      <option value="<?php echo h($key); ?>"><?php echo h($label); ?></option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>

                      <!-- Step 4: SEO Pre-population (P1-H) -->
                      <div class="wizard-step" data-step="4">
                          <div class="step-header">
                              <h3><i data-feather="search" style="width:1.1rem;height:1.1rem;vertical-align:-2px;color:var(--mw-green)"></i> SEO Settings</h3>
                              <p>Auto-generated from your service &amp; location — edit as needed before publishing.</p>
                          </div>

                          <div class="select-group">
                              <label for="seoTitle">Meta Title *
                                  <span class="text-muted fw-normal" style="font-size:.8rem" id="seoTitleCount">(0/60)</span>
                              </label>
                              <input type="text" id="seoTitle" name="meta_title" class="text-input"
                                     placeholder="Auto-generated — edit freely" maxlength="60">
                              <small class="text-muted">Keep under 60 characters. Appears as the browser tab title and Google headline.</small>
                          </div>

                          <div class="select-group">
                              <label for="seoDesc">Meta Description *
                                  <span class="text-muted fw-normal" style="font-size:.8rem" id="seoDescCount">(0/155)</span>
                              </label>
                              <textarea id="seoDesc" name="meta_description" class="text-input" rows="3"
                                        maxlength="155" placeholder="Auto-generated — edit freely"></textarea>
                              <small class="text-muted">Keep under 155 characters. This is the snippet shown in Google search results.</small>
                          </div>

                          <div class="select-group">
                              <label for="seoKeywords">Focus Keywords <span class="text-muted fw-normal">(optional)</span></label>
                              <input type="text" id="seoKeywords" name="meta_keywords" class="text-input"
                                     placeholder="e.g. lawn care Vancouver, grass cutting Burnaby">
                              <small class="text-muted">Comma-separated. Used internally — not a strong ranking factor but useful for tracking.</small>
                          </div>

                          <div class="alert" style="background:var(--mw-light);border:1px solid var(--mw-green);border-radius:4px;font-size:.85rem">
                              <i data-feather="info" style="width:0.9rem;height:0.9rem;color:var(--mw-green)"></i>
                              <strong>Auto-populated from:</strong> service name, neighbourhood, business name (<?= h(defined('SITE_NAME') ? SITE_NAME : 'Mowology') ?>), and phone (<?= h(defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : '(778) 846-9273') ?>).
                          </div>
                      </div>

                      <!-- Step 5: Module Selector (P1-H) -->
                      <div class="wizard-step" data-step="5">
                          <div class="step-header">
                              <h3><i data-feather="layers" style="width:1.1rem;height:1.1rem;vertical-align:-2px;color:var(--mw-green)"></i> Choose Modules</h3>
                              <p>Select which content blocks to include. The template default is pre-selected.</p>
                          </div>

                          <div id="moduleList" class="generator-cards" style="grid-template-columns:1fr 1fr">
                              <!-- Populated by JS from block types list -->
                              <?php
                              $wizardBlockTypes = [
                                  ['block_type' => 'hero',               'label' => 'Hero Banner',       'icon' => 'image',        'default' => true,  'desc' => 'Full-width headline + CTA'],
                                  ['block_type' => 'feature_grid',       'label' => 'Feature Grid',      'icon' => 'grid',         'default' => true,  'desc' => '3-column benefits layout'],
                                  ['block_type' => 'service_cards',      'label' => 'Service Cards',     'icon' => 'briefcase',    'default' => true,  'desc' => 'Service tiles with icons'],
                                  ['block_type' => 'testimonials',       'label' => 'Testimonials',      'icon' => 'message-circle','default' => true, 'desc' => 'Customer reviews carousel'],
                                  ['block_type' => 'cta',                'label' => 'CTA Section',       'icon' => 'zap',          'default' => true,  'desc' => 'Call-to-action with buttons'],
                                  ['block_type' => 'faq',                'label' => 'FAQ',               'icon' => 'help-circle',  'default' => false, 'desc' => 'Accordion FAQ'],
                                  ['block_type' => 'gallery',            'label' => 'Gallery',           'icon' => 'camera',       'default' => false, 'desc' => 'Photo gallery grid'],
                                  ['block_type' => 'stats_banner',       'label' => 'Stats Banner',      'icon' => 'bar-chart-2',  'default' => false, 'desc' => 'Key metrics / numbers'],
                                  ['block_type' => 'before_after',       'label' => 'Before/After',      'icon' => 'columns',      'default' => false, 'desc' => 'Comparison image slider'],
                                  ['block_type' => 'area_cards',         'label' => 'Service Areas',     'icon' => 'map-pin',      'default' => false, 'desc' => 'Location coverage cards'],
                                  ['block_type' => 'portfolio_showcase', 'label' => 'Portfolio',         'icon' => 'award',        'default' => false, 'desc' => 'Featured project showcase'],
                                  ['block_type' => 'rich_text',          'label' => 'Rich Text',         'icon' => 'file-text',    'default' => false, 'desc' => 'Custom HTML content'],
                              ];
                              foreach ($wizardBlockTypes as $bt):
                              ?>
                              <div class="generator-card" onclick="toggleModule(this, '<?= h($bt['block_type']) ?>')">
                                  <div style="display:flex;align-items:center;gap:.6rem">
                                      <div class="mw-module-check <?= $bt['default'] ? 'checked' : '' ?>"
                                           id="mcheck_<?= h($bt['block_type']) ?>"
                                           style="width:20px;height:20px;border:2px solid;border-color:<?= $bt['default'] ? 'var(--mw-green)' : '#ddd' ?>;border-radius:4px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:<?= $bt['default'] ? 'var(--mw-green)' : 'transparent' ?>">
                                          <?php if ($bt['default']): ?>
                                          <i data-feather="check" style="width:.75rem;height:.75rem;color:white"></i>
                                          <?php endif; ?>
                                      </div>
                                      <div>
                                          <div style="font-weight:600;font-size:.9rem"><?= h($bt['label']) ?></div>
                                          <div style="font-size:.75rem;color:#888"><?= h($bt['desc']) ?></div>
                                      </div>
                                  </div>
                                  <input type="hidden" id="mod_<?= h($bt['block_type']) ?>" name="modules[]"
                                         value="<?= h($bt['block_type']) ?>" <?= $bt['default'] ? '' : 'disabled' ?>>
                              </div>
                              <?php endforeach; ?>
                          </div>

                          <div class="preview-box" style="margin-top:1rem">
                              <div style="font-size:.85rem;color:#666">
                                  <strong>Selected modules:</strong>
                                  <span id="selectedModulesSummary"><?= implode(', ', array_column(array_filter($wizardBlockTypes, fn($b) => $b['default']), 'label')) ?></span>
                              </div>
                          </div>
                      </div>

                      <!-- Step 6: Review and Generate (was Step 4) -->
                      <div class="wizard-step" data-step="6">
                          <div class="step-header">
                              <h3>Review and Generate</h3>
                              <p>Confirm page details before generating</p>
                          </div>

                          <div class="preview-box">
                              <div class="preview-row">
                                  <div class="preview-label">Template:</div>
                                  <div class="preview-value" id="previewTemplate">-</div>
                              </div>
                              <div class="preview-row">
                                  <div class="preview-label">Service:</div>
                                  <div class="preview-value" id="previewService">-</div>
                              </div>
                              <div class="preview-row">
                                  <div class="preview-label">Neighbourhood:</div>
                                  <div class="preview-value" id="previewNeighbourhood">-</div>
                              </div>
                              <div class="preview-row">
                                  <div class="preview-label">Page Title:</div>
                                  <div class="preview-value" id="previewTitle">-</div>
                              </div>
                              <div class="preview-row" style="border-top:1px solid #e0e0e0;padding-top:.5rem;margin-top:.5rem">
                                  <div class="preview-label">Meta Title:</div>
                                  <div class="preview-value" id="previewSeoTitle" style="color:var(--mw-green)">-</div>
                              </div>
                              <div class="preview-row">
                                  <div class="preview-label">Meta Desc:</div>
                                  <div class="preview-value" id="previewSeoDesc" style="font-size:.85rem">-</div>
                              </div>
                              <div class="preview-row">
                                  <div class="preview-label">Modules:</div>
                                  <div class="preview-value small" id="previewModules">-</div>
                              </div>
                          </div>

                          <div class="select-group">
                              <label for="customTitle">Custom Title (optional)</label>
                              <input type="text" id="customTitle" class="text-input"
                                     placeholder="Leave blank to use template default">
                              <small class="text-muted">Use {service} and {neighbourhood} as placeholders</small>
                          </div>
                      </div>

                      <!-- Wizard Actions -->
                      <div class="wizard-actions">
                          <button class="btn-wizard btn-prev" id="btnPrev" style="display:none;">← Back</button>
                          <button class="btn-wizard btn-next" id="btnNext">Next →</button>
                      </div>

                  </div>
              </div>

          </div>

<script>
let currentStep = 1;
const totalSteps = 6;  // P1-H: 6 steps (added SEO + Modules)

const generatorMap = <?php echo json_encode(array_reduce($generators, fn($acc, $g) => $acc + [$g['config_key'] => $g['config_label']], [])); ?>;
const serviceMap = <?php echo json_encode($services); ?>;
const neighbourhoodMap = <?php echo json_encode($neighbourhoods); ?>;
const siteName = <?php echo json_encode(defined('SITE_NAME') ? SITE_NAME : 'Mowology') ?>;
const sitePhone = <?php echo json_encode(defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : '(778) 846-9273') ?>;

function showStep(step) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.querySelector(`.wizard-step[data-step="${step}"]`).classList.add('active');

    // Update progress
    document.querySelectorAll('.progress-step').forEach((el) => {
        el.classList.remove('active', 'completed');
        const stepNum = parseInt(el.dataset.step);
        if (stepNum === step) el.classList.add('active');
        else if (stepNum < step) el.classList.add('completed');
    });

    // Update button visibility
    document.getElementById('btnPrev').style.display = step > 1 ? 'block' : 'none';
    const btnNext = document.getElementById('btnNext') || document.getElementById('btnGenerate');
    if (btnNext) {
        btnNext.textContent = step === totalSteps ? 'Generate Page' : 'Next →';
        btnNext.id = step === totalSteps ? 'btnGenerate' : 'btnNext';
    }

    // Step 4: auto-populate SEO fields
    if (step === 4) autoPopulateSeo();

    // Step 6: update preview summary
    if (step === totalSteps) updatePreview();

    // Re-run feather icons for any new SVGs
    if (typeof feather !== 'undefined') feather.replace();
}

// SEO auto-population from service + neighbourhood + site constants (P1-H)
function autoPopulateSeo() {
    const service = serviceMap[document.getElementById('serviceSelect')?.value] || '';
    const neighbourhood = neighbourhoodMap[document.getElementById('neighbourhoodSelect')?.value] || '';

    const titleEl = document.getElementById('seoTitle');
    const descEl  = document.getElementById('seoDesc');
    const kwEl    = document.getElementById('seoKeywords');

    // Only auto-populate if still empty (don't overwrite user edits)
    if (titleEl && !titleEl.value) {
        const title = service && neighbourhood
            ? `${service} in ${neighbourhood} | ${siteName}`
            : `${service || 'Our Services'} | ${siteName}`;
        titleEl.value = title.substring(0, 60);
        updateCharCount('seoTitle', 'seoTitleCount', 60);
    }

    if (descEl && !descEl.value) {
        const desc = service && neighbourhood
            ? `Professional ${service.toLowerCase()} in ${neighbourhood}. Reliable, affordable, and locally trusted. Call ${sitePhone} for a free quote.`
            : `Expert ${service.toLowerCase()} services by ${siteName}. Serving the Greater Vancouver area. Call ${sitePhone} today.`;
        descEl.value = desc.substring(0, 155);
        updateCharCount('seoDesc', 'seoDescCount', 155);
    }

    if (kwEl && !kwEl.value && service) {
        const neighbourhood_lc = neighbourhood ? neighbourhood.toLowerCase() : 'vancouver';
        kwEl.value = `${service.toLowerCase()}, ${service.toLowerCase()} ${neighbourhood_lc}, lawn care ${neighbourhood_lc}`;
    }
}

function updateCharCount(inputId, countId, max) {
    const el = document.getElementById(inputId);
    const cnt = document.getElementById(countId);
    if (el && cnt) {
        cnt.textContent = `(${el.value.length}/${max})`;
        cnt.style.color = el.value.length > max * 0.9 ? 'var(--mw-orange)' : '#999';
    }
}

// Wire character counters
['seoTitle', 'seoDesc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        const max = id === 'seoTitle' ? 60 : 155;
        el.addEventListener('input', () => updateCharCount(id, id + 'Count', max));
    }
});

// Module toggle (P1-H)
function toggleModule(card, blockType) {
    const check  = document.getElementById('mcheck_' + blockType);
    const input  = document.getElementById('mod_' + blockType);
    const isOn   = !input.disabled;

    if (isOn) {
        input.disabled = true;
        check.style.background = 'transparent';
        check.style.borderColor = '#ddd';
        check.innerHTML = '';
    } else {
        input.disabled = false;
        check.style.background = 'var(--mw-green)';
        check.style.borderColor = 'var(--mw-green)';
        check.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    }

    // Update summary text
    const selected = [...document.querySelectorAll('input[name="modules[]"]:not(:disabled)')]
        .map(i => i.closest('.generator-card').querySelector('div div:first-child').textContent.trim());
    const summary = document.getElementById('selectedModulesSummary');
    if (summary) summary.textContent = selected.length ? selected.join(', ') : 'None selected';
}

function getFormData() {
    const modules = [...document.querySelectorAll('input[name="modules[]"]:not(:disabled)')]
        .map(i => i.value);
    return {
        generator_key:    document.querySelector('input[name="generator_key"]:checked')?.value,
        service:          document.getElementById('serviceSelect')?.value,
        neighbourhood:    document.getElementById('neighbourhoodSelect')?.value,
        custom_title:     document.getElementById('customTitle')?.value,
        meta_title:       document.getElementById('seoTitle')?.value,
        meta_description: document.getElementById('seoDesc')?.value,
        meta_keywords:    document.getElementById('seoKeywords')?.value,
        modules:          modules,
    };
}

function validateStep(step) {
    const data = getFormData();
    switch (step) {
        case 1:
            if (!data.generator_key) { showError('Please select a template'); return false; }
            return true;
        case 2:
            if (!data.service) { showError('Please select a service'); return false; }
            return true;
        case 3:
            if (!data.neighbourhood) { showError('Please select a neighbourhood'); return false; }
            return true;
        case 4:
            if (!data.meta_title || data.meta_title.length < 5) {
                showError('Please enter a meta title (at least 5 characters)');
                return false;
            }
            if (!data.meta_description || data.meta_description.length < 10) {
                showError('Please enter a meta description (at least 10 characters)');
                return false;
            }
            return true;
        case 5:
            if (!data.modules || data.modules.length === 0) {
                showError('Please select at least one content module');
                return false;
            }
            return true;
        default:
            return true;
    }
}

function updatePreview() {
    const data = getFormData();
    document.getElementById('previewTemplate').textContent     = generatorMap[data.generator_key] || '-';
    document.getElementById('previewService').textContent      = serviceMap[data.service] || '-';
    document.getElementById('previewNeighbourhood').textContent = neighbourhoodMap[data.neighbourhood] || '-';

    // SEO preview
    const previewSeoTitle = document.getElementById('previewSeoTitle');
    const previewSeoDesc  = document.getElementById('previewSeoDesc');
    const previewModules  = document.getElementById('previewModules');
    if (previewSeoTitle) previewSeoTitle.textContent = data.meta_title || '-';
    if (previewSeoDesc)  previewSeoDesc.textContent  = data.meta_description || '-';
    if (previewModules)  previewModules.textContent   = data.modules?.join(', ') || '-';

    // Generate preview title
    const config = <?php echo json_encode(array_reduce($generators, fn($acc, $g) => $acc + [$g['config_key'] => $g['config_data']], [])); ?>;
    let titleTemplate = config[data.generator_key]?.title_template || 'Generated Page';
    if (data.custom_title) titleTemplate = data.custom_title;
    const title = titleTemplate
        .replace('{service}', serviceMap[data.service] || '')
        .replace('{neighbourhood}', neighbourhoodMap[data.neighbourhood] || '');
    document.getElementById('previewTitle').textContent = title;
}

function showError(message) {
    const container = document.getElementById('errorContainer');
    container.innerHTML = `<div class="error-message">${h(message)}</div>`;
    setTimeout(() => { container.innerHTML = ''; }, 5000);
}

function showSuccess(pageId, pageSlug, editUrl) {
    const container = document.getElementById('errorContainer');
    container.innerHTML = `
        <div class="success-message">
            <strong>✓ Page generated successfully!</strong><br>
            Page slug: <code>${h(pageSlug)}</code><br>
            <a href="${h(editUrl)}">Edit page →</a>
        </div>
    `;
    setTimeout(() => {
        window.location.href = editUrl;
    }, 2000);
}

function h(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('btnPrev').addEventListener('click', () => {
    if (currentStep > 1) {
        currentStep--;
        showStep(currentStep);
    }
});

document.addEventListener('click', (e) => {
    if (e.target.id === 'btnNext' || e.target.id === 'btnGenerate') {
        if (!validateStep(currentStep)) return;

        if (currentStep < totalSteps) {
            currentStep++;
            showStep(currentStep);
        } else {
            // Generate page — include SEO metadata and selected modules (P1-H)
            const data = getFormData();
            const formData = new FormData();
            formData.append('generator_key',    data.generator_key);
            formData.append('service',          data.service);
            formData.append('neighbourhood',    data.neighbourhood);
            formData.append('custom_title',     data.custom_title);
            formData.append('meta_title',       data.meta_title || '');
            formData.append('meta_description', data.meta_description || '');
            formData.append('meta_keywords',    data.meta_keywords || '');
            (data.modules || []).forEach(m => formData.append('modules[]', m));
            formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');

            const btn = document.getElementById('btnGenerate');
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span>Generating...';

            fetch('/crm/api/generate-page.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showSuccess(result.page_id, result.page_slug, result.edit_url);
                } else {
                    throw new Error(result.error || 'Generation failed');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = 'Generate';
                showError(err.message);
            });
        }
    }
});

// Initialize
showStep(1);
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
