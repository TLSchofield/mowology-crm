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

                      <!-- Wizard Progress -->
                      <div class="wizard-progress">
                          <div class="progress-step active" data-step="1"></div>
                          <div class="progress-step" data-step="2"></div>
                          <div class="progress-step" data-step="3"></div>
                          <div class="progress-step" data-step="4"></div>
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

                      <!-- Step 4: Review and Generate -->
                      <div class="wizard-step" data-step="4">
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
const totalSteps = 4;

const generatorMap = <?php echo json_encode(array_reduce($generators, fn($acc, $g) => $acc + [$g['config_key'] => $g['config_label']], [])); ?>;
const serviceMap = <?php echo json_encode($services); ?>;
const neighbourhoodMap = <?php echo json_encode($neighbourhoods); ?>;

function showStep(step) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.remove('active'));
    document.querySelector(`[data-step="${step}"]`).classList.add('active');

    // Update progress
    document.querySelectorAll('.progress-step').forEach((el, idx) => {
        el.classList.remove('active', 'completed');
        const stepNum = parseInt(el.dataset.step);
        if (stepNum === step) {
            el.classList.add('active');
        } else if (stepNum < step) {
            el.classList.add('completed');
        }
    });

    // Update button visibility
    document.getElementById('btnPrev').style.display = step > 1 ? 'block' : 'none';
    document.getElementById('btnNext').textContent = step === totalSteps ? 'Generate' : 'Next →';
    document.getElementById('btnNext').id = step === totalSteps ? 'btnGenerate' : 'btnNext';

    // Update preview on last step
    if (step === totalSteps) {
        updatePreview();
    }
}

function getFormData() {
    return {
        generator_key: document.querySelector('input[name="generator_key"]:checked')?.value,
        service: document.getElementById('serviceSelect')?.value,
        neighbourhood: document.getElementById('neighbourhoodSelect')?.value,
        custom_title: document.getElementById('customTitle')?.value
    };
}

function validateStep(step) {
    const data = getFormData();
    switch (step) {
        case 1:
            if (!data.generator_key) {
                showError('Please select a template');
                return false;
            }
            return true;
        case 2:
            if (!data.service) {
                showError('Please select a service');
                return false;
            }
            return true;
        case 3:
            if (!data.neighbourhood) {
                showError('Please select a neighbourhood');
                return false;
            }
            return true;
        default:
            return true;
    }
}

function updatePreview() {
    const data = getFormData();
    document.getElementById('previewTemplate').textContent = generatorMap[data.generator_key] || '-';
    document.getElementById('previewService').textContent = serviceMap[data.service] || '-';
    document.getElementById('previewNeighbourhood').textContent = neighbourhoodMap[data.neighbourhood] || '-';

    // Generate preview title
    const config = <?php echo json_encode(array_reduce($generators, fn($acc, $g) => $acc + [$g['config_key'] => $g['config_data']], [])); ?>;
    let titleTemplate = config[data.generator_key]?.title_template || 'Generated Page';
    if (data.custom_title) {
        titleTemplate = data.custom_title;
    }
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
            // Generate page
            const data = getFormData();
            const formData = new FormData();
            formData.append('generator_key', data.generator_key);
            formData.append('service', data.service);
            formData.append('neighbourhood', data.neighbourhood);
            formData.append('custom_title', data.custom_title);
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
