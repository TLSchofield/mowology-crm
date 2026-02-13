<?php
/**
 * Portfolio Project - Create/Edit Form
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('portfolio.edit');

$projectId = intval($_GET['id'] ?? 0);
$project = null;
$isEdit = false;

if ($projectId) {
    $project = getPortfolioProject($projectId);
    if (!$project) {
        header("Location: index.php?error=not_found");
        exit;
    }
    $isEdit = true;
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectName = trim($_POST['project_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $featured = isset($_POST['featured']) ? true : false;
    $displayOrder = intval($_POST['display_order'] ?? 999);
    // Handle multi-select categories array
    $categoryArray = $_POST['categories'] ?? [];
    if (!is_array($categoryArray)) {
        $categoryArray = [];
    }
    $categories = json_encode($categoryArray);

    // Handle tags (comma-separated string converted to array)
    $tagsInput = trim($_POST['tags'] ?? '');
    $tagsArray = array_filter(array_map('trim', explode(',', $tagsInput)));
    $tags = json_encode($tagsArray);

    // Handle gallery images (JSON array of paths)
    $galleryImagesInput = $_POST['gallery_images'] ?? '[]';
    $galleryImages = json_decode($galleryImagesInput, true);
    if (!is_array($galleryImages)) {
        $galleryImages = [];
    }
    $galleryImages = array_filter($galleryImages);
    $galleryImagesJson = json_encode(array_values($galleryImages));

    // Handle main gallery image index
    $mainGalleryImageIndex = isset($_POST['main_gallery_image_index']) && $_POST['main_gallery_image_index'] !== ''
        ? intval($_POST['main_gallery_image_index'])
        : null;

    // Handle before/after images
    $beforeImagePath = $_POST['before_image_path'] ?? null;
    $afterImagePath = $_POST['after_image_path'] ?? null;

    if (empty($projectName)) {
        $errors[] = 'Project name is required';
    }

    if (empty($errors)) {
        $data = [
            'project_name' => $projectName,
            'description' => $description,
            'location' => $location,
            'status' => $status,
            'featured' => $featured,
            'display_order' => $displayOrder,
            'categories' => $categories,
            'tags' => $tags,
            'gallery_images' => $galleryImagesJson,
            'main_gallery_image_index' => $mainGalleryImageIndex,
            'before_image_path' => $beforeImagePath,
            'after_image_path' => $afterImagePath
        ];

        if ($isEdit) {
            if (updatePortfolioProject($projectId, $data)) {
                logActivityExtended($user['id'], 'Portfolio project updated', "Project '{$projectName}' updated", null, null, null, null, $projectId);
                header("Location: view.php?id={$projectId}&success=updated");
                exit;
            }
        } else {
            $data['created_by'] = $user['id'];
            $newId = createPortfolioProject($data);
            if ($newId) {
                logActivityExtended($user['id'], 'Portfolio project created', "Project '{$projectName}' created", null, null, null, null, $newId);
                header("Location: view.php?id={$newId}&success=created");
                exit;
            }
        }

        $errors[] = 'Failed to save project. Please try again.';
    }
}

// Pre-populate form data
$projectName = $project['project_name'] ?? '';
$description = $project['description'] ?? '';
$location = $project['location'] ?? '';
$status = $project['status'] ?? 'draft';
$featured = $project['featured'] ?? false;
$displayOrder = $project['display_order'] ?? 999;
$categories = $project['categories'] ?? '[]';
$categoriesArray = json_decode($categories, true);
if (!is_array($categoriesArray)) {
    $categoriesArray = [];
}

$tagsJson = $project['tags'] ?? '[]';
$tagsArray = json_decode($tagsJson, true);
if (!is_array($tagsArray)) {
    $tagsArray = [];
}
$tagsString = implode(', ', $tagsArray);

$galleryImagesJson = $project['gallery_images'] ?? '[]';
$galleryImages = json_decode($galleryImagesJson, true);
if (!is_array($galleryImages)) {
    $galleryImages = [];
}

$pageTitle = $isEdit ? 'Edit Project' : 'New Project';
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <div class="mw-page-header mb-4">
              <div>
                  <a href="index.php" class="mw-back-link">
                      <i data-feather="arrow-left"></i> Back to Portfolio
                  </a>
                  <h1 class="h3 mb-0"><?php echo htmlspecialchars($pageTitle); ?></h1>
                  <p class="text-muted mb-0"><?php echo $isEdit ? 'Update project details' : 'Create a new portfolio project'; ?></p>
              </div>
          </div>

          <?php if (!empty($errors)): ?>
              <div class="alert alert-danger" role="alert">
                  <h4 class="alert-heading">Unable to Save</h4>
                  <ul class="mb-0">
                      <?php foreach ($errors as $error): ?>
                          <li><?php echo htmlspecialchars($error); ?></li>
                      <?php endforeach; ?>
                  </ul>
              </div>
          <?php endif; ?>

          <form method="post" class="mw-form" enctype="multipart/form-data">
              <div class="card mb-4">
                  <div class="card-header">
                      <h5 class="mb-0">Basic Information</h5>
                  </div>
                  <div class="card-body">
                      <div class="form-group">
                          <label for="projectName">Project Name *</label>
                          <input type="text" class="form-control" id="projectName" name="project_name" required value="<?php echo htmlspecialchars($projectName); ?>" placeholder="e.g., Complete Backyard Renovation">
                      </div>

                      <div class="row">
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="location">Location</label>
                                  <input type="text" class="form-control" id="location" name="location" value="<?php echo htmlspecialchars($location); ?>" placeholder="e.g., Vancouver, BC">
                              </div>
                          </div>
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="displayOrder">Display Order</label>
                                  <input type="number" class="form-control" id="displayOrder" name="display_order" min="0" value="<?php echo intval($displayOrder); ?>">
                                  <small class="form-text text-muted">Lower numbers appear first</small>
                              </div>
                          </div>
                      </div>

                      <div class="form-group">
                          <label for="description">Description</label>
                          <textarea class="form-control" id="description" name="description" rows="4" placeholder="Describe the project, services provided, and results..."><?php echo htmlspecialchars($description); ?></textarea>
                      </div>
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-header">
                      <h5 class="mb-0">Categorization & Visibility</h5>
                  </div>
                  <div class="card-body">
                      <div class="form-group">
                          <label for="categories">Service Categories</label>
                          <select class="form-control" id="categories" name="categories[]" multiple>
                              <option value="Strata & Property Management" <?php echo in_array('Strata & Property Management', $categoriesArray) ? 'selected' : ''; ?>>Strata & Property Management</option>
                              <option value="Residential" <?php echo in_array('Residential', $categoriesArray) ? 'selected' : ''; ?>>Residential</option>
                              <option value="Maintenance" <?php echo in_array('Maintenance', $categoriesArray) ? 'selected' : ''; ?>>Maintenance</option>
                              <option value="Design & Installation" <?php echo in_array('Design & Installation', $categoriesArray) ? 'selected' : ''; ?>>Design & Installation</option>
                          </select>
                          <small class="form-text text-muted">Hold Ctrl/Cmd to select multiple categories</small>
                      </div>

                      <div class="form-group">
                          <label for="tags">Project Tags</label>
                          <input type="text" class="form-control" id="tags" name="tags" value="<?php echo htmlspecialchars($tagsString); ?>" placeholder="e.g., Weekly Maintenance, Strata, Before & After">
                          <small class="form-text text-muted">Enter tags separated by commas (e.g., "Weekly Maintenance, Strata")</small>
                      </div>

                      <div class="row">
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label for="status">Status</label>
                                  <select class="form-control" id="status" name="status">
                                      <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft (Hidden from public)</option>
                                      <option value="published" <?php echo $status === 'published' ? 'selected' : ''; ?>>Published (Visible on portfolio)</option>
                                  </select>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <div class="form-group">
                                  <div class="form-check" style="margin-top: 32px;">
                                      <input type="checkbox" class="form-check-input" id="featured" name="featured" <?php echo $featured ? 'checked' : ''; ?>>
                                      <label class="form-check-label" for="featured">
                                          Featured Project <i data-feather="star" style="width: 16px; height: 16px;"></i>
                                      </label>
                                  </div>
                                  <small class="form-text text-muted">Featured projects appear at the top</small>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-header">
                      <h5 class="mb-0">Images</h5>
                  </div>
                  <div class="card-body">
                      <div class="row">
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label>Before Image</label>
                                  <div class="mw-image-upload-area">
                                      <input type="file" class="form-control" accept="image/*" id="beforeImage" onchange="previewImage(this, 'beforePreview')">
                                      <div id="beforePreview" class="mt-3"></div>
                                      <?php if ($project && $project['before_image_path']): ?>
                                          <div class="mt-3" id="beforeImageContainer">
                                              <img src="<?php echo htmlspecialchars($project['before_image_path']); ?>" style="max-width: 100%; max-height: 200px;">
                                              <div class="mt-2">
                                                  <button type="button" class="btn btn-sm btn-danger" onclick="deleteImage('before_image_path', <?php echo $projectId; ?>, this)">
                                                      <i data-feather="trash-2"></i> Delete Image
                                                  </button>
                                              </div>
                                          </div>
                                      <?php endif; ?>
                                  </div>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <div class="form-group">
                                  <label>After Image</label>
                                  <div class="mw-image-upload-area">
                                      <input type="file" class="form-control" accept="image/*" id="afterImage" onchange="previewImage(this, 'afterPreview')">
                                      <div id="afterPreview" class="mt-3"></div>
                                      <?php if ($project && $project['after_image_path']): ?>
                                          <div class="mt-3" id="afterImageContainer">
                                              <img src="<?php echo htmlspecialchars($project['after_image_path']); ?>" style="max-width: 100%; max-height: 200px;">
                                              <div class="mt-2">
                                                  <button type="button" class="btn btn-sm btn-danger" onclick="deleteImage('after_image_path', <?php echo $projectId; ?>, this)">
                                                      <i data-feather="trash-2"></i> Delete Image
                                                  </button>
                                              </div>
                                          </div>
                                      <?php endif; ?>
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

              <div class="card mb-4">
                  <div class="card-header">
                      <h5 class="mb-0">Gallery Images</h5>
                      <small class="text-muted d-block">Upload multiple project photos</small>
                  </div>
                  <div class="card-body">
                      <div class="form-group">
                          <label>Add Gallery Images</label>
                          <div class="mw-image-upload-area">
                              <input type="file" class="form-control" accept="image/*" id="galleryImage" multiple onchange="previewGalleryImages(this)">
                              <small class="form-text text-muted">Select one or more images (JPEG, PNG, GIF, WebP - max 5MB each)</small>
                          </div>
                      </div>

                      <div id="galleryPreview" class="mt-3"></div>

                      <?php if (!empty($galleryImages)): ?>
                          <div class="mt-4">
                              <label>Current Gallery Images</label>
                              <small class="text-muted d-block mb-3">Click "Make Primary" to display as main image on portfolio listing</small>
                              <div class="mw-gallery-grid" id="galleryGrid">
                                  <?php foreach ($galleryImages as $index => $imagePath):
                                      $isPrimary = (isset($project['main_gallery_image_index']) && $project['main_gallery_image_index'] === $index);
                                  ?>
                                      <div class="mw-gallery-item" data-index="<?php echo $index; ?>" data-path="<?php echo htmlspecialchars($imagePath); ?>" <?php if ($isPrimary) echo 'data-primary="1"'; ?>>
                                          <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Gallery image" style="width: 100%; height: 150px; object-fit: cover;">
                                          <div class="mw-gallery-overlay">
                                              <button type="button" class="btn btn-sm btn-success <?php echo $isPrimary ? 'active' : ''; ?>" onclick="setGalleryImageAsPrimary(<?php echo $index; ?>, this)">
                                                  <i data-feather="star"></i> <?php echo $isPrimary ? 'Primary' : 'Make Primary'; ?>
                                              </button>
                                              <button type="button" class="btn btn-sm btn-danger mt-2" onclick="deleteGalleryImage(<?php echo $index; ?>, this)">
                                                  <i data-feather="trash-2"></i> Delete
                                              </button>
                                          </div>
                                      </div>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                      <?php endif; ?>

                      <!-- Hidden input to store main gallery image index -->
                      <input type="hidden" id="mainGalleryImageIndex" name="main_gallery_image_index" value="<?php echo htmlspecialchars($project['main_gallery_image_index'] ?? ''); ?>"

                      <!-- Hidden input to store gallery images -->
                      <input type="hidden" id="galleryImagesInput" name="gallery_images" value="<?php echo htmlspecialchars(json_encode($galleryImages)); ?>">
                  </div>
              </div>

              <div class="mw-form-actions">
                  <button type="submit" class="btn btn-primary">
                      <i data-feather="save"></i> <?php echo $isEdit ? 'Update Project' : 'Create Project'; ?>
                  </button>
                  <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
              </div>
          </form>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<script>
function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const file = input.files[0];

        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 100%; max-height: 200px;"> <small class="d-block mt-2">Click "Save" to upload</small>';
        };
        reader.readAsDataURL(file);

        // Auto-upload on file select
        uploadImage(input, previewId.replace('Preview', ''));
    }
}

function uploadImage(input, fieldName) {
    if (!input.files || !input.files[0]) return;

    const formData = new FormData();
    formData.append('image', input.files[0]);

    const preview = document.getElementById(fieldName + 'Preview');
    preview.innerHTML += '<small style="display:block; margin-top:5px; color:#666;">Uploading...</small>';

    fetch('upload-image.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store the filepath in a hidden input with correct name
            let pathInput = document.getElementById(fieldName + 'Path');
            if (!pathInput) {
                pathInput = document.createElement('input');
                pathInput.type = 'hidden';
                pathInput.id = fieldName + 'Path';
                pathInput.name = fieldName + '_image_path';  // Fix: was fieldName + '_path'
                input.parentElement.appendChild(pathInput);
            }
            pathInput.value = data.filepath;

            // Update preview
            preview.innerHTML = '<img src="' + data.filepath + '" style="max-width: 100%; max-height: 200px;"> <small style="display:block; margin-top:5px; color:green;">✓ Uploaded</small>';
        } else {
            preview.innerHTML += '<small style="display:block; margin-top:5px; color:red;">Error: ' + data.message + '</small>';
        }
    })
    .catch(error => {
        preview.innerHTML += '<small style="display:block; margin-top:5px; color:red;">Upload failed</small>';
        console.error('Error:', error);
    });
}

// Delete image function
function deleteImage(imageField, projectId, button) {
    if (!confirm('Are you sure you want to delete this image? This action cannot be undone.')) {
        return;
    }

    const formData = new FormData();
    formData.append('project_id', projectId);
    formData.append('image_field', imageField);

    button.disabled = true;
    button.innerHTML = '<i data-feather="loader" style="width: 16px; height: 16px; animation: spin 1s linear infinite;"></i> Deleting...';

    fetch('delete-image.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const containerId = imageField === 'before_image_path' ? 'beforeImageContainer' : 'afterImageContainer';
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = '<small style="color: green;">✓ Image deleted</small>';
            }
        } else {
            alert('Error: ' + data.message);
            button.disabled = false;
            button.innerHTML = '<i data-feather="trash-2"></i> Delete Image';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to delete image');
        button.disabled = false;
        button.innerHTML = '<i data-feather="trash-2"></i> Delete Image';
    });
}

// Gallery functions
let galleryImages = JSON.parse(document.getElementById('galleryImagesInput').value);

function previewGalleryImages(input) {
    if (!input.files) return;

    const preview = document.getElementById('galleryPreview');
    const filesArray = Array.from(input.files);

    filesArray.forEach(file => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const tempPreview = document.createElement('div');
            tempPreview.style.cssText = 'display: inline-block; margin: 5px; position: relative;';
            tempPreview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 150px; max-height: 150px; border: 2px solid #ccc;"> <small style="display:block; margin-top:5px; color:#666;">Uploading...</small>';
            preview.appendChild(tempPreview);

            uploadGalleryImage(file, tempPreview);
        };
        reader.readAsDataURL(file);
    });

    // Clear the input so same file can be selected again
    input.value = '';
}

function uploadGalleryImage(file, previewElement) {
    const formData = new FormData();
    formData.append('image', file);

    fetch('upload-gallery-image.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            galleryImages.push(data.filepath);
            updateGalleryImagesInput();

            // Update preview
            previewElement.innerHTML = '<div class="mw-gallery-item" style="display: inline-block;">' +
                '<img src="' + data.filepath + '" style="max-width: 150px; max-height: 150px; border: 2px solid #2D8659;">' +
                '<small style="display:block; margin-top:5px; color:green;">✓ Uploaded</small>' +
                '<button type="button" class="btn btn-sm btn-danger mt-2" onclick="removeGalleryImageFromPreview(this, \'' + data.filepath + '\')">' +
                '<i data-feather="trash-2"></i> Remove' +
                '</button></div>';
        } else {
            previewElement.innerHTML = '<small style="color:red;">Error: ' + data.message + '</small>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        previewElement.innerHTML = '<small style="color:red;">Upload failed</small>';
    });
}

function removeGalleryImageFromPreview(button, filepath) {
    const index = galleryImages.indexOf(filepath);
    if (index > -1) {
        galleryImages.splice(index, 1);
        updateGalleryImagesInput();
    }
    button.closest('div').remove();
}

function deleteGalleryImage(index, button) {
    if (!confirm('Are you sure you want to delete this gallery image?')) {
        return;
    }

    const imagePath = galleryImages[index];
    galleryImages.splice(index, 1);
    updateGalleryImagesInput();

    button.closest('.mw-gallery-item').style.opacity = '0.5';
    button.disabled = true;
    button.innerHTML = '<i data-feather="loader"></i> Deleting...';

    setTimeout(() => {
        button.closest('.mw-gallery-item').remove();
    }, 300);
}

function setGalleryImageAsPrimary(index, button) {
    // Remove active state from all primary buttons
    document.querySelectorAll('.mw-gallery-overlay .btn-success.active').forEach(btn => {
        btn.classList.remove('active');
        btn.innerHTML = '<i data-feather="star"></i> Make Primary';
    });

    // Set this button as active/primary
    button.classList.add('active');
    button.innerHTML = '<i data-feather="star"></i> Primary';

    // Update the hidden input with the selected index
    document.getElementById('mainGalleryImageIndex').value = index;
}

function updateGalleryImagesInput() {
    const input = document.getElementById('galleryImagesInput');
    input.value = JSON.stringify(galleryImages);
}

// Before form submit, ensure image paths are set
document.querySelector('form').addEventListener('submit', function(e) {
    // Image paths will be set by hidden inputs created during upload
});
</script>

<!-- Hidden inputs for image paths that will be set during upload -->
<style>
.mw-image-upload-area input[type="file"] {
    display: block;
    cursor: pointer;
    padding: 10px;
    border: none;
}

.mw-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 15px;
}

.mw-gallery-item {
    position: relative;
    overflow: hidden;
    border: 2px solid #ddd;
    border-radius: 4px;
    transition: all 0.3s ease;
}

.mw-gallery-item:hover .mw-gallery-overlay {
    opacity: 1;
}

.mw-gallery-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.mw-gallery-overlay button {
    white-space: nowrap;
}

.mw-gallery-overlay .btn-success.active {
    background-color: #1a5f4a !important;
    border-color: #1a5f4a !important;
}

.mw-gallery-item[data-primary="1"] {
    border: 3px solid #2D8659;
}
</style>
