<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
requirePermission('settings.edit');

$pageTitle = 'Design Mockups';
$activePage = 'mockups';

$mockups = [
    'obsidian-root-icons.html'      => 'Obsidian Root™ Icon Set',
    'day-summary-card-mockup.html'  => 'Day Summary Card',
    'profitability-viz-mockup.html' => 'Profitability Visualization',
];
?>
<?php include 'includes/appstack_head.php'; ?>

          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <h1 class="h3 mb-1">Design Mockups</h1>
              <p class="text-muted mb-0 small">In-progress UI concepts — admin only.</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <a id="openTabBtn" href="/crm/mockups/" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i data-feather="external-link" style="width:13px;height:13px;"></i> Open in new tab
              </a>
              <select class="form-control form-control-sm" id="mockupSelect" style="width:240px;" onchange="loadMockup(this.value)">
                <option value="">— choose a mockup —</option>
                <?php foreach ($mockups as $file => $label): ?>
                <option value="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mw-mockup-frame-wrap" style="border:1px solid #e8f3f0;border-radius:8px;overflow:hidden;height:calc(100vh - 220px);min-height:560px;background:#f8f9fa;">
            <div class="mw-mockup-placeholder" id="mockupPlaceholder" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;text-align:center;">
              <i data-feather="monitor" style="width:40px;height:40px;opacity:.2;display:block;margin:0 auto 12px;"></i>
              <p class="text-muted mb-1">Select a mockup above to preview it here</p>
              <p class="text-muted small">Or <a href="/crm/mockups/" target="_blank">browse all mockup files</a> directly.</p>
            </div>
            <iframe id="mockupFrame" src="" style="width:100%;height:100%;border:none;display:none;"></iframe>
          </div>

<script>
const BASE = '/crm/mockups/';

function loadMockup(file) {
  const frame       = document.getElementById('mockupFrame');
  const placeholder = document.getElementById('mockupPlaceholder');
  const tabBtn      = document.getElementById('openTabBtn');

  if (!file) {
    frame.style.display = 'none';
    placeholder.style.display = '';
    tabBtn.href = '/crm/mockups/';
    return;
  }

  frame.src = BASE + file;
  frame.style.display = '';
  placeholder.style.display = 'none';
  tabBtn.href = BASE + file;
}
</script>

<?php include 'includes/appstack_footer.php'; ?>
