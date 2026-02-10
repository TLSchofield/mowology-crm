<?php
/**
 * CMS Generator Manager - Configure Page Generation Templates
 *
 * Allows staff to create, edit, and manage page generator templates
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

$pageTitle = 'Generator Templates';
$activePage = 'cms';

$generators = pg_getGeneratorConfigs(false);
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

    <style>
        .generator-list {
            display: grid;
            gap: 1rem;
        }

        .generator-item {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }

        .generator-item.disabled {
            opacity: 0.6;
            background: #f9f9f9;
        }

        .generator-info h3 {
            margin: 0 0 0.25rem;
            font-size: 1.1rem;
        }

        .generator-info p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .generator-status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .status-enabled {
            background: #d4edda;
            color: #155724;
        }

        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }

        .generator-actions {
            display: flex;
            gap: 0.75rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-edit {
            background: var(--mw-green);
            color: white;
        }

        .btn-edit:hover {
            background: var(--mw-dark);
        }

        .btn-toggle {
            background: #ddd;
            color: #333;
        }

        .btn-toggle:hover {
            background: #ccc;
        }

        .new-generator-btn {
            background: var(--mw-green);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            margin-bottom: 1.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .new-generator-btn:hover {
            background: var(--mw-dark);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: #666;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>

          <div class="container-fluid">

              <div class="row mb-4">
                  <div class="col-12">
                      <div style="display: flex; justify-content: space-between; align-items: center;">
                          <h2>Page Generator Templates</h2>
                          <button class="new-generator-btn" data-bs-toggle="modal" data-bs-target="#generatorModal">
                              <i data-feather="plus"></i>
                              New Template
                          </button>
                      </div>
                  </div>
              </div>

              <?php if (empty($generators)): ?>
                  <div class="card">
                      <div class="card-body">
                          <div class="empty-state">
                              <p><strong>No templates created yet</strong></p>
                              <p>Create your first page generator template to start generating landing pages.</p>
                          </div>
                      </div>
                  </div>
              <?php else: ?>
                  <div class="card">
                      <div class="card-body">
                          <div class="generator-list">
                              <?php foreach ($generators as $gen): ?>
                                  <div class="generator-item <?php echo !$gen['enabled'] ? 'disabled' : ''; ?>">
                                      <div class="generator-info">
                                          <h3><?php echo h($gen['config_label']); ?></h3>
                                          <p>Key: <code><?php echo h($gen['config_key']); ?></code></p>
                                          <p><?php echo h($gen['config_data']['description'] ?? ''); ?></p>
                                          <span class="generator-status <?php echo $gen['enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                                              <?php echo $gen['enabled'] ? '✓ Enabled' : '○ Disabled'; ?>
                                          </span>
                                      </div>
                                      <div class="generator-actions">
                                          <button class="btn-action btn-edit" onclick="editGenerator(<?php echo (int)$gen['id']; ?>)">
                                              <i data-feather="edit-2"></i>
                                              Edit
                                          </button>
                                          <button class="btn-action btn-toggle" onclick="toggleGenerator(<?php echo (int)$gen['id']; ?>, <?php echo $gen['enabled'] ? 'false' : 'true'; ?>)">
                                              <?php echo $gen['enabled'] ? 'Disable' : 'Enable'; ?>
                                          </button>
                                      </div>
                                  </div>
                              <?php endforeach; ?>
                          </div>
                      </div>
                  </div>
              <?php endif; ?>

              <div class="card mt-4">
                  <div class="card-header">
                      <h4 class="mb-0">Quick Reference: Template Configuration</h4>
                  </div>
                  <div class="card-body">
                      <p><strong>Template format (JSON structure):</strong></p>
                      <pre style="background: #f9f9f9; padding: 1rem; border-radius: 4px; overflow-x: auto;"><code>{
  "description": "Description of this template",
  "page_type": "service_landing",
  "title_template": "{service} in {neighbourhood} | Mowology",
  "slug_template": "{service}-{neighbourhood}",
  "meta_description_template": "Professional {service} services in {neighbourhood}",
  "required_variables": ["service", "neighbourhood"],
  "blocks": [
    {
      "block_type": "hero",
      "content": "Best {service} in {neighbourhood}",
      "config": {
        "headline": "{service} Services",
        "cta_url": "/quote?service={service}"
      }
    }
  ]
}</code></pre>
                      <p><strong>Available variables:</strong> <code>{service}</code>, <code>{neighbourhood}</code></p>
                  </div>
              </div>

          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
