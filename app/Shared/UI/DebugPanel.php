<?php
/**
 * Debug Panel - Development/Testing Tool
 *
 * Include this in appstack_footer.php to enable debugging across all CRM pages.
 * Toggle with ?debug=1 in URL or localStorage('debug_panel', 'enabled')
 *
 * Features:
 * - Page performance metrics
 * - Database query log
 * - User session info
 * - Console errors
 * - Custom event logging
 * - Memory usage
 *
 * Migrated from: public/crm/includes/debug-panel.php
 */

// Only show in development or if explicitly enabled
$debug_enabled = (
    (defined('DEBUG_MODE') && DEBUG_MODE) ||
    (isset($_GET['debug']) && $_GET['debug'] === '1') ||
    (isset($_COOKIE['debug_panel']) && $_COOKIE['debug_panel'] === 'enabled')
);

if (!$debug_enabled) {
    return;
}

// Collect debug data
$debug_data = [
    'page' => $_SERVER['PHP_SELF'] ?? 'unknown',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'timestamp' => date('Y-m-d H:i:s'),
    'user' => isset($user) ? ($user['email'] ?? $user['name'] ?? 'Unknown') : 'Not logged in',
    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
    'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
    'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . ' ms',
    'session_id' => session_id() ?? 'No session',
    'active_tab' => $activePage ?? 'unknown',
];

// Get error log from this session
$error_log = isset($GLOBALS['_error_log']) ? $GLOBALS['_error_log'] : [];
$debug_data['errors_logged'] = count($error_log);

// Database query count (if tracking available)
$debug_data['queries'] = isset($GLOBALS['_query_count']) ? $GLOBALS['_query_count'] : 'N/A';
?>

<style>
#debug-panel {
  position: fixed;
  bottom: 20px;
  right: 20px;
  font-family: 'Courier New', monospace;
  font-size: 11px;
  z-index: 99999;
  background: #1a1a1a;
  color: #00ff00;
  border: 2px solid #00ff00;
  border-radius: 4px;
  max-width: 400px;
  max-height: 300px;
  overflow-y: auto;
  box-shadow: 0 0 20px rgba(0, 255, 0, 0.3);
}

#debug-panel-header {
  background: #00ff00;
  color: #000;
  padding: 8px 12px;
  font-weight: bold;
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  user-select: none;
}

#debug-panel-header:hover {
  background: #00dd00;
}

#debug-panel-content {
  padding: 12px;
  display: none;
  max-height: 250px;
  overflow-y: auto;
}

#debug-panel-content.expanded {
  display: block;
}

.debug-row {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  border-bottom: 1px solid #333;
}

.debug-row:last-child {
  border-bottom: none;
}

.debug-label {
  font-weight: bold;
  color: #00ff00;
  margin-right: 10px;
}

.debug-value {
  color: #88ff88;
  word-break: break-all;
  flex: 1;
  text-align: right;
}

.debug-warning {
  color: #ffff00;
}

.debug-error {
  color: #ff4444;
}

#debug-toggle-btn {
  background: #00ff00;
  color: #000;
  border: 1px solid #00ff00;
  padding: 4px 8px;
  border-radius: 3px;
  cursor: pointer;
  font-size: 10px;
  font-weight: bold;
  font-family: monospace;
}

#debug-toggle-btn:hover {
  background: #00dd00;
}

.debug-section {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid #333;
}

.debug-section:first-of-type {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}

.debug-section-title {
  color: #ffff00;
  font-weight: bold;
  margin-bottom: 4px;
  font-size: 10px;
}

.debug-error-list {
  background: #330000;
  padding: 6px;
  border-left: 2px solid #ff4444;
  margin: 4px 0;
  border-radius: 2px;
}

.debug-error-item {
  margin: 2px 0;
  font-size: 10px;
}

/* Scrollbar styling */
#debug-panel-content::-webkit-scrollbar {
  width: 6px;
}

#debug-panel-content::-webkit-scrollbar-track {
  background: #1a1a1a;
}

#debug-panel-content::-webkit-scrollbar-thumb {
  background: #00ff00;
  border-radius: 3px;
}

#debug-panel-content::-webkit-scrollbar-thumb:hover {
  background: #00dd00;
}
</style>

<div id="debug-panel">
  <div id="debug-panel-header">
    <span>🔧 DEBUG PANEL</span>
    <button id="debug-toggle-btn">▼</button>
  </div>

  <div id="debug-panel-content">
    <!-- Page Info -->
    <div class="debug-section">
      <div class="debug-section-title">PAGE INFO</div>
      <div class="debug-row">
        <span class="debug-label">Page:</span>
        <span class="debug-value"><?php echo htmlspecialchars($debug_data['page']); ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Method:</span>
        <span class="debug-value"><?php echo $debug_data['method']; ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Tab:</span>
        <span class="debug-value"><?php echo htmlspecialchars($debug_data['active_tab']); ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Time:</span>
        <span class="debug-value"><?php echo $debug_data['timestamp']; ?></span>
      </div>
    </div>

    <!-- Performance -->
    <div class="debug-section">
      <div class="debug-section-title">PERFORMANCE</div>
      <div class="debug-row">
        <span class="debug-label">Execution:</span>
        <span class="debug-value"><?php echo $debug_data['execution_time']; ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Memory:</span>
        <span class="debug-value"><?php echo $debug_data['memory_usage']; ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Peak:</span>
        <span class="debug-value"><?php echo $debug_data['peak_memory']; ?></span>
      </div>
      <?php if ($debug_data['queries'] !== 'N/A'): ?>
      <div class="debug-row">
        <span class="debug-label">Queries:</span>
        <span class="debug-value"><?php echo $debug_data['queries']; ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- User & Session -->
    <div class="debug-section">
      <div class="debug-section-title">USER & SESSION</div>
      <div class="debug-row">
        <span class="debug-label">User:</span>
        <span class="debug-value"><?php echo htmlspecialchars($debug_data['user']); ?></span>
      </div>
      <div class="debug-row">
        <span class="debug-label">Session:</span>
        <span class="debug-value"><?php echo substr($debug_data['session_id'], 0, 12) . '...'; ?></span>
      </div>
    </div>

    <!-- Errors -->
    <?php if (!empty($error_log)): ?>
    <div class="debug-section">
      <div class="debug-section-title debug-warning">ERRORS (<?php echo count($error_log); ?>)</div>
      <div class="debug-error-list">
        <?php foreach ($error_log as $error): ?>
          <div class="debug-error-item debug-error">
            • <?php echo htmlspecialchars(substr($error, 0, 50)); ?>...
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Links -->
    <div class="debug-section">
      <div class="debug-section-title">QUICK LINKS</div>
      <div style="display: flex; gap: 4px; flex-wrap: wrap;">
        <a href="?debug=1" style="color: #00ff00; text-decoration: none; font-size: 10px;">
          [Refresh]
        </a>
        <a href="<?php echo $_SERVER['REQUEST_URI']; ?>&debug=0" style="color: #ff8800; text-decoration: none; font-size: 10px;">
          [Hide]
        </a>
        <a href="javascript:debugPanel.downloadLog()" style="color: #00ff00; text-decoration: none; font-size: 10px;">
          [Download]
        </a>
        <a href="javascript:debugPanel.clearLog()" style="color: #ff4444; text-decoration: none; font-size: 10px;">
          [Clear]
        </a>
      </div>
    </div>
  </div>
</div>

<script>
const debugPanel = {
  init() {
    const header = document.getElementById('debug-panel-header');
    const content = document.getElementById('debug-panel-content');
    const toggle = document.getElementById('debug-toggle-btn');

    // Load state from localStorage
    const isExpanded = localStorage.getItem('debug_panel_expanded') !== 'false';
    if (isExpanded) {
      content.classList.add('expanded');
      toggle.textContent = '▲';
    }

    // Toggle on header click
    header.addEventListener('click', () => {
      content.classList.toggle('expanded');
      toggle.textContent = content.classList.contains('expanded') ? '▲' : '▼';
      localStorage.setItem('debug_panel_expanded', content.classList.contains('expanded'));
    });

    // Log console errors
    window.addEventListener('error', (e) => {
      this.logError(`JS Error: ${e.message}`);
    });

    // Listen for unhandled promise rejections
    window.addEventListener('unhandledrejection', (e) => {
      this.logError(`Promise Error: ${e.reason}`);
    });
  },

  logError(message) {
    const errorList = document.querySelector('.debug-error-list');
    if (!errorList) return;

    const item = document.createElement('div');
    item.className = 'debug-error-item debug-error';
    item.textContent = '• ' + message.substring(0, 50);
    errorList.insertBefore(item, errorList.firstChild);
  },

  downloadLog() {
    const content = document.getElementById('debug-panel-content').innerText;
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `debug-${new Date().toISOString()}.txt`;
    a.click();
    URL.revokeObjectURL(url);
  },

  clearLog() {
    if (confirm('Clear debug log?')) {
      location.reload();
    }
  }
};

// Initialize on page load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => debugPanel.init());
} else {
  debugPanel.init();
}
</script>
