<?php
/**
 * Portfolio Project - Detail View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$projectId = intval($_GET['id'] ?? 0);
if (!$projectId) {
    header("Location: index.php?error=invalid");
    exit;
}

$project = getPortfolioProject($projectId);
if (!$project) {
    header("Location: index.php?error=not_found");
    exit;
}

$pageTitle = htmlspecialchars($project['project_name']);
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-page-header mb-4">
              <div>
                  <a href="index.php" class="mw-back-link">
                      <i data-feather="arrow-left"></i> Back to Portfolio
                  </a>
                  <div class="d-flex justify-content-between align-items-start">
                      <div>
                          <h1 class="h3 mb-1"><?php echo htmlspecialchars($project['project_name']); ?></h1>
                          <p class="text-muted mb-0"><?php echo htmlspecialchars($project['project_number']); ?></p>
                      </div>
                      <div class="d-flex" style="gap: 8px;">
                          <a href="edit.php?id=<?php echo $project['id']; ?>" class="btn btn-secondary">
                              <i data-feather="edit"></i> Edit
                          </a>
                          <button type="button" class="btn btn-danger" onclick="deleteProject(<?php echo $project['id']; ?>)">
                              <i data-feather="trash-2"></i> Delete
                          </button>
                      </div>
                  </div>
              </div>
          </div>

          <?php if (isset($_GET['success'])): ?>
              <div class="alert alert-success alert-dismissible fade show" role="alert">
                  Project saved successfully!
                  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                  </button>
              </div>
          <?php endif; ?>

          <div class="row">
              <div class="col-md-8">
                  <!-- Images -->
                  <?php if ($project['before_image_path'] || $project['after_image_path']): ?>
                      <div class="card mb-4">
                          <div class="card-header">
                              <h5 class="mb-0">Before & After</h5>
                          </div>
                          <div class="card-body">
                              <div class="row">
                                  <?php if ($project['before_image_path']): ?>
                                      <div class="col-md-6">
                                          <h6>Before</h6>
                                          <img src="<?php echo htmlspecialchars($project['before_image_path']); ?>" alt="Before" style="width: 100%; max-height: 300px; object-fit: cover; border-radius: 4px;">
                                      </div>
                                  <?php endif; ?>
                                  <?php if ($project['after_image_path']): ?>
                                      <div class="col-md-6">
                                          <h6>After</h6>
                                          <img src="<?php echo htmlspecialchars($project['after_image_path']); ?>" alt="After" style="width: 100%; max-height: 300px; object-fit: cover; border-radius: 4px;">
                                      </div>
                                  <?php endif; ?>
                              </div>
                          </div>
                      </div>
                  <?php endif; ?>

                  <!-- Description -->
                  <div class="card mb-4">
                      <div class="card-header">
                          <h5 class="mb-0">Project Details</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-detail-grid">
                              <div class="mw-detail-row">
                                  <span class="label">Location</span>
                                  <span class="value"><?php echo htmlspecialchars($project['location'] ?? '-'); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Status</span>
                                  <span class="value"><?php echo getStatusBadge($project['status'], $project['status'] === 'published' ? 'success' : 'secondary'); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Featured</span>
                                  <span class="value"><?php echo $project['featured'] ? '<span class="badge badge-warning"><i data-feather="star"></i> Yes</span>' : '<span class="badge badge-light">No</span>'; ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Display Order</span>
                                  <span class="value"><?php echo intval($project['display_order']); ?></span>
                              </div>
                          </div>

                          <?php if (!empty($project['description'])): ?>
                              <hr>
                              <div class="mt-4">
                                  <h6>Description</h6>
                                  <p><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                              </div>
                          <?php endif; ?>

                          <?php
                          $categories = json_decode($project['categories'] ?? '[]', true);
                          if (!empty($categories)):
                          ?>
                              <hr>
                              <div class="mt-4">
                                  <h6>Categories</h6>
                                  <div>
                                      <?php foreach ($categories as $category): ?>
                                          <span class="badge badge-primary mr-2"><?php echo htmlspecialchars($category); ?></span>
                                      <?php endforeach; ?>
                                  </div>
                              </div>
                          <?php endif; ?>

                          <?php
                          $tags = json_decode($project['tags'] ?? '[]', true);
                          if (!empty($tags)):
                          ?>
                              <hr>
                              <div class="mt-4">
                                  <h6>Tags</h6>
                                  <div>
                                      <?php foreach ($tags as $tag): ?>
                                          <span class="badge badge-secondary mr-2"><?php echo htmlspecialchars($tag); ?></span>
                                      <?php endforeach; ?>
                                  </div>
                              </div>
                          <?php endif; ?>
                      </div>
                  </div>
              </div>

              <div class="col-md-4">
                  <!-- Metadata -->
                  <div class="card">
                      <div class="card-header">
                          <h5 class="mb-0">Metadata</h5>
                      </div>
                      <div class="card-body">
                          <div class="mw-detail-grid">
                              <div class="mw-detail-row">
                                  <span class="label">Project ID</span>
                                  <span class="value"><?php echo $project['id']; ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Project Number</span>
                                  <span class="value"><?php echo htmlspecialchars($project['project_number']); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Created</span>
                                  <span class="value"><?php echo formatDate($project['created_at'], 'M j, Y \a\t g:i A'); ?></span>
                              </div>
                              <div class="mw-detail-row">
                                  <span class="label">Last Updated</span>
                                  <span class="value"><?php echo formatDate($project['updated_at'], 'M j, Y \a\t g:i A'); ?></span>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<script>
function deleteProject(projectId) {
    if (confirm('Are you sure you want to delete this project? This action cannot be undone.')) {
        window.location.href = 'delete.php?id=' + projectId;
    }
}
</script>
