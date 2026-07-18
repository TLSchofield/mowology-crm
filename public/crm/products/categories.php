<?php
/**
 * Product Categories Management
 * Organize and manage product categories
 */

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$db = getDB();

// Get all categories
$stmt = $db->prepare("
    SELECT id, name, description, display_order, active,
           (SELECT COUNT(*) FROM products WHERE category_id = product_categories.id AND is_archived = 0) as product_count
    FROM product_categories
    ORDER BY display_order, name
");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Product Categories';
$activePage = 'products';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <!-- Page Header -->
          <div class="mw-page-header">
              <div>
                  <a href="index.php" class="mw-back-link">
                      <i data-feather="arrow-left"></i> Back to Products Hub
                  </a>
                  <h1 class="h3 mb-0">Product Categories</h1>
                  <p class="text-muted mb-0">Organize products into categories for better management and reporting</p>
              </div>
              <button class="btn btn-primary" data-toggle="modal" data-target="#addCategoryModal">
                  <i data-feather="plus"></i> New Category
              </button>
          </div>

          <!-- Categories List -->
          <div class="card">
              <div class="card-body">
                  <?php if (empty($categories)): ?>
                      <div class="alert alert-info">
                          <p>No categories yet. <a href="#" data-toggle="modal" data-target="#addCategoryModal">Create your first category</a></p>
                      </div>
                  <?php else: ?>
                      <div class="table-responsive">
                          <table class="table table-hover">
                              <thead>
                                  <tr>
                                      <th>Category Name</th>
                                      <th>Description</th>
                                      <th>Products</th>
                                      <th>Status</th>
                                      <th>Order</th>
                                      <th>Actions</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($categories as $cat): ?>
                                      <tr>
                                          <td>
                                              <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                                          </td>
                                          <td>
                                              <span class="text-muted">
                                                  <?php echo htmlspecialchars($cat['description'] ?: '—'); ?>
                                              </span>
                                          </td>
                                          <td>
                                              <span class="badge badge-secondary"><?php echo (int)$cat['product_count']; ?></span>
                                          </td>
                                          <td>
                                              <?php if ($cat['active']): ?>
                                                  <span class="badge badge-success">Active</span>
                                              <?php else: ?>
                                                  <span class="badge badge-secondary">Inactive</span>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <span class="text-muted"><?php echo (int)$cat['display_order']; ?></span>
                                          </td>
                                          <td>
                                              <button class="btn btn-sm btn-outline-secondary"
                                                      onclick="editCategory(<?php echo (int)$cat['id']; ?>, '<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($cat['description'] ?? '', ENT_QUOTES); ?>', <?php echo (int)$cat['display_order']; ?>, <?php echo $cat['active'] ? 'true' : 'false'; ?>)">
                                                  <i data-feather="edit-2"></i> Edit
                                              </button>
                                              <?php if ((int)$cat['product_count'] === 0): ?>
                                                  <button class="btn btn-sm btn-outline-danger"
                                                          onclick="deleteCategory(<?php echo (int)$cat['id']; ?>, '<?php echo htmlspecialchars($cat['name'], ENT_QUOTES); ?>')">
                                                      <i data-feather="trash-2"></i> Delete
                                                  </button>
                                              <?php else: ?>
                                                  <button class="btn btn-sm btn-outline-secondary" disabled
                                                          title="Cannot delete category with products">
                                                      <i data-feather="trash-2"></i> Delete
                                                  </button>
                                              <?php endif; ?>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  <?php endif; ?>
              </div>
          </div>

          <!-- Add/Edit Category Modal -->
          <div class="modal fade" id="addCategoryModal" tabindex="-1" role="dialog" aria-labelledby="addCategoryLabel" aria-hidden="true">
              <div class="modal-dialog" role="document">
                  <div class="modal-content">
                      <div class="modal-header">
                          <h5 class="modal-title" id="addCategoryLabel">Add New Category</h5>
                          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                              <span aria-hidden="true">&times;</span>
                          </button>
                      </div>
                      <div class="modal-body">
                          <form id="categoryForm">
                              <input type="hidden" id="categoryId" value="">
                              <div class="form-group">
                                  <label for="categoryName">Category Name *</label>
                                  <input type="text" class="form-control" id="categoryName" required>
                              </div>
                              <div class="form-group">
                                  <label for="categoryDescription">Description</label>
                                  <textarea class="form-control" id="categoryDescription" rows="3" placeholder="Optional description..."></textarea>
                              </div>
                              <div class="form-group">
                                  <label for="displayOrder">Display Order</label>
                                  <input type="number" class="form-control" id="displayOrder" value="0" min="0">
                              </div>
                              <div class="form-check">
                                  <input type="checkbox" class="form-check-input" id="categoryActive" checked>
                                  <label class="form-check-label" for="categoryActive">
                                      Active
                                  </label>
                              </div>
                          </form>
                      </div>
                      <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                          <button type="button" class="btn btn-primary" onclick="saveCategory()">Save Category</button>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Feedback Toast -->
          <div id="feedbackToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="3000" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: none;">
              <div class="toast-body bg-success text-white">
                  <span id="toastMessage"></span>
                  <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
          </div>

<script>
/**
 * Category Management Script
 */

function editCategory(id, name, description, order, active) {
    document.getElementById('categoryId').value = id;
    document.getElementById('categoryName').value = name;
    document.getElementById('categoryDescription').value = description;
    document.getElementById('displayOrder').value = order;
    document.getElementById('categoryActive').checked = active;

    document.querySelector('#addCategoryModal .modal-title').textContent = 'Edit Category';
    $('#addCategoryModal').modal('show');
}

function saveCategory() {
    const id = document.getElementById('categoryId').value;
    const name = document.getElementById('categoryName').value.trim();
    const description = document.getElementById('categoryDescription').value.trim();
    const order = parseInt(document.getElementById('displayOrder').value) || 0;
    const active = document.getElementById('categoryActive').checked ? 1 : 0;

    if (!name) {
        alert('Category name is required');
        return;
    }

    const isNewCategory = !id;
    const action = isNewCategory ? 'add-category' : 'update-category';

    const payload = {
        name: name,
        description: description || null,
        display_order: order,
        active: active
    };

    if (!isNewCategory) {
        payload.id = parseInt(id);
    }

    fetch('api-products.php?action=' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.MW_CSRF_TOKEN || ''
        },
        body: JSON.stringify(payload)
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(data => {
                throw new Error(data.error || 'Failed to save category');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showFeedback(data.message || (isNewCategory ? 'Category added successfully' : 'Category updated successfully'));
            $('#addCategoryModal').modal('hide');
            resetCategoryForm();
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to save category: ' + error.message);
    });
}

function deleteCategory(id, name) {
    if (!confirm('Are you sure you want to delete the category "' + name + '"?\n\nThis cannot be undone.')) {
        return;
    }

    fetch('api-products.php?action=delete-category', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.MW_CSRF_TOKEN || ''
        },
        body: JSON.stringify({ id: parseInt(id) })
    })
    .then(response => {
        if (!response.ok) {
            return response.json().then(data => {
                throw new Error(data.error || 'Failed to delete category');
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showFeedback('Category deleted successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to delete category: ' + error.message);
    });
}

function resetCategoryForm() {
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.querySelector('#addCategoryModal .modal-title').textContent = 'Add New Category';
    document.getElementById('categoryActive').checked = true;
}

function showFeedback(message) {
    const toast = document.getElementById('feedbackToast');
    const msg = document.getElementById('toastMessage');
    msg.textContent = message;

    toast.style.display = 'block';
    const bsToast = new (window.bootstrap ? window.bootstrap.Toast : $.fn.toast.Constructor)(toast);
    if (bsToast && bsToast.show) {
        bsToast.show();
    } else if (window.$) {
        $(toast).toast('show');
    }
}

// Reset form when modal is hidden
$('#addCategoryModal').on('hidden.bs.modal', function() {
    resetCategoryForm();
});
</script>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
