/**
 * Migrations Manager UI
 * Handles:
 * - Loading pending migrations list
 * - Executing migrations with feedback
 * - Displaying migration history
 * - Database health checks
 */

document.addEventListener('DOMContentLoaded', function() {
    // Only initialize if we're on the database tab
    const dbTab = document.getElementById('database-tab');
    if (!dbTab) return;

    // Load data when database tab is clicked
    dbTab.addEventListener('click', function() {
        setTimeout(() => {
            loadDatabaseHealth();
            loadPendingMigrations();
            loadMigrationHistory('all');
        }, 100);
    });

    // Load on page load if tab is active
    if (dbTab.classList.contains('active')) {
        loadDatabaseHealth();
        loadPendingMigrations();
        loadMigrationHistory('all');
    }

    // History filter change
    document.getElementById('historyStatusFilter')?.addEventListener('change', function(e) {
        loadMigrationHistory(e.target.value);
    });
});

/**
 * Load and display database health information
 */
function loadDatabaseHealth() {
    const csrfToken = getCsrfToken();

    fetch('/crm/api/migrations-manager.php?action=verify', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ csrf_token: csrfToken })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('dbHealthLoading').style.display = 'none';
            document.getElementById('dbHealthInfo').style.display = 'block';

            document.getElementById('dbName').textContent = data.database || 'unknown';
            document.getElementById('dbVersion').textContent = data.mysql_version || 'unknown';

            const statusBadge = document.getElementById('dbStatus');
            if (data.health === 'ok') {
                statusBadge.className = 'badge badge-success';
                statusBadge.textContent = '✓ Healthy';
            } else {
                statusBadge.className = 'badge badge-warning';
                statusBadge.textContent = '⚠ Issues Found';
            }
        }
    })
    .catch(error => {
        console.error('Error loading database health:', error);
        document.getElementById('dbHealthLoading').innerHTML =
            '<div class="alert alert-danger">Failed to load database health</div>';
    });
}

/**
 * Load and display pending migrations
 */
function loadPendingMigrations() {
    const csrfToken = getCsrfToken();

    fetch('/crm/api/migrations-manager.php?action=list', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ csrf_token: csrfToken })
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('pendingMigrationsLoading').style.display = 'none';
        document.getElementById('pendingMigrationsContainer').style.display = 'block';

        const list = document.getElementById('pendingMigrationsList');
        list.innerHTML = '';

        document.getElementById('pendingCount').textContent = data.pending_count;

        if (data.pending.length === 0) {
            document.getElementById('noPendingMessage').style.display = 'block';
            return;
        }

        document.getElementById('noPendingMessage').style.display = 'none';

        data.pending.forEach(migration => {
            const card = document.createElement('div');
            card.className = 'col-md-6 col-lg-4 mb-3';
            const title = migration.title || migration.filename;
            const purpose = migration.purpose || '';
            const createdAt = migration.created_at || '';
            const sizeKb = migration.size ? (migration.size / 1024).toFixed(1) + ' KB' : '';
            card.innerHTML = `
                <div class="card mw-migration-card border-warning">
                    <div class="card-body">
                        <h6 class="card-title font-monospace small">${escapeHtml(migration.filename)}</h6>
                        ${title !== migration.filename ? '<p class="card-text small mb-2">' + escapeHtml(title) + '</p>' : ''}
                        ${purpose ? '<p class="text-muted small mb-2">' + escapeHtml(purpose) + '</p>' : ''}
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted">${createdAt}${sizeKb ? ' · ' + sizeKb : ''}</small>
                            <button class="btn btn-sm btn-warning" onclick="executeMigration('${escapeHtml(migration.filename)}')">
                                Execute
                            </button>
                        </div>
                    </div>
                </div>
            `;
            list.appendChild(card);
        });
    })
    .catch(error => {
        console.error('Error loading pending migrations:', error);
        document.getElementById('pendingMigrationsLoading').innerHTML =
            '<div class="alert alert-danger">Failed to load migrations</div>';
    });
}

/**
 * Execute a single migration
 */
function executeMigration(filename) {
    if (!confirm(`Execute migration: ${filename}?\n\nThis will modify your database. Make sure you have a backup.`)) {
        return;
    }

    const csrfToken = getCsrfToken();
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Executing...';

    fetch('/crm/api/migrations-manager.php?action=execute', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            csrf_token: csrfToken,
            filename: filename
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', `Migration executed successfully: ${filename}`);
            // Reload both lists
            setTimeout(() => {
                loadPendingMigrations();
                loadMigrationHistory('all');
            }, 500);
        } else {
            showAlert('danger', `Migration failed: ${data.error}`);
            btn.disabled = false;
            btn.textContent = 'Execute';
        }
    })
    .catch(error => {
        console.error('Error executing migration:', error);
        showAlert('danger', 'Error executing migration. Check console for details.');
        btn.disabled = false;
        btn.textContent = 'Execute';
    });
}

/**
 * Load and display migration history
 */
function loadMigrationHistory(status = 'all') {
    const csrfToken = getCsrfToken();

    fetch('/crm/api/migrations-manager.php?action=history', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            csrf_token: csrfToken,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('migrationHistoryLoading').style.display = 'none';
        document.getElementById('migrationHistoryContainer').style.display = 'block';

        const tbody = document.getElementById('migrationHistoryList');
        tbody.innerHTML = '';

        if (data.history.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">No migrations found</td></tr>';
            return;
        }

        data.history.forEach(migration => {
            const row = document.createElement('tr');
            const statusBadge = migration.status === 'success'
                ? '<span class="badge badge-success">Success</span>'
                : '<span class="badge badge-danger">Failed</span>';

            row.innerHTML = `
                <td>
                    <code class="small">${escapeHtml(migration.migration_filename)}</code>
                </td>
                <td>${statusBadge}</td>
                <td class="small">${escapeHtml(migration.executed_by_name)}</td>
                <td class="small text-muted">${formatDateTime(migration.executed_at)}</td>
            `;

            if (migration.status === 'failed' && migration.error_message) {
                const details = document.createElement('tr');
                details.innerHTML = `
                    <td colspan="4">
                        <small class="text-danger font-monospace">
                            Error: ${escapeHtml(migration.error_message)}
                        </small>
                    </td>
                `;
                tbody.appendChild(row);
                tbody.appendChild(details);
            } else {
                tbody.appendChild(row);
            }
        });
    })
    .catch(error => {
        console.error('Error loading migration history:', error);
        document.getElementById('migrationHistoryLoading').innerHTML =
            '<div class="alert alert-danger">Failed to load history</div>';
    });
}

/**
 * Get CSRF token from page
 */
function getCsrfToken() {
    // Try to find it in a meta tag or form
    let token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (token) return token;

    // Fallback - request a new token (shouldn't happen in normal flow)
    return '';
}

/**
 * Show alert message
 */
function showAlert(type, message) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show`;
    alert.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    // Find the settings error/success divs and replace
    const container = document.querySelector('#settingsError')?.parentElement || document.body;
    container.insertBefore(alert, container.firstChild);

    if (type === 'success') {
        setTimeout(() => alert.remove(), 5000);
    }
}

/**
 * Escape HTML special characters
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

/**
 * Format datetime for display
 */
function formatDateTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString();
}
