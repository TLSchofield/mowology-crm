<?php
require_once __DIR__ . '/../loginAuth/auth.php';
require_once 'includes/functions.php';

requireLogin();
$user = getCurrentUser();

$pageTitle = 'Media Library';
$activePage = 'cms';
?>
<?php include 'includes/appstack_head.php'; ?>

  <div class="slh-page-header">
    <h1>Media Library</h1>
    <p class="text-muted">Manage images and files for CMS pages</p>
  </div>

  <div class="card">
    <div class="card-header">
      <h5 class="mb-0">Upload Media</h5>
    </div>
    <div class="card-body">
      <form id="media-upload-form" enctype="multipart/form-data">
        <div class="form-group">
          <label for="media-file">Select File</label>
          <input type="file" class="form-control-file" id="media-file" name="media-file" accept="image/*,.pdf,.doc,.docx" required>
          <small class="form-text text-muted">Supported: JPG, PNG, GIF, PDF, DOC, DOCX</small>
        </div>
        <button type="submit" class="btn btn-primary">Upload</button>
      </form>
    </div>
  </div>

  <div class="card mt-4">
    <div class="card-header">
      <h5 class="mb-0">Media Files</h5>
    </div>
    <div class="card-body">
      <div id="media-list" class="media-list">
        <p class="text-muted">Loading...</p>
      </div>
    </div>
  </div>

<?php include 'includes/appstack_footer.php'; ?>
