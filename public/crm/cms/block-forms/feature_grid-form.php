<?php
/**
 * Feature Grid Block Form
 *
 * Config fields: title, description, layout, features[] (icon, title, description)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$layout      = $config['layout'] ?? '3';
$features    = $config['features'] ?? [];
?>

<div class="feature-grid-block-form">
    <div class="form-group">
        <label for="fg-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="fg-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. Why Choose Us">
    </div>

    <div class="form-group">
        <label for="fg-description-<?= $blockIndex ?>">Section Description</label>
        <textarea class="form-control"
                  id="fg-description-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][description]"
                  rows="2"
                  placeholder="Brief intro text above the feature cards."><?= h($description) ?></textarea>
    </div>

    <div class="form-group">
        <label for="fg-layout-<?= $blockIndex ?>">Columns</label>
        <select class="form-control"
                id="fg-layout-<?= $blockIndex ?>"
                name="blocks[<?= $blockIndex ?>][config][layout]">
            <option value="2" <?= $layout === '2' ? 'selected' : '' ?>>2 Columns</option>
            <option value="3" <?= $layout === '3' ? 'selected' : '' ?>>3 Columns</option>
            <option value="4" <?= $layout === '4' ? 'selected' : '' ?>>4 Columns</option>
        </select>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Features</h6>

    <div class="feature-items-container-<?= $blockIndex ?>">
        <?php if (!empty($features)): ?>
            <?php foreach ($features as $i => $feature): ?>
                <div class="card mb-2 feature-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">Feature #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-feature-btn">&times; Remove</button>
                        </div>
                        <div class="form-group mb-2">
                            <label>Icon Name</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="blocks[<?= $blockIndex ?>][config][features][<?= $i ?>][icon]"
                                   value="<?= h($feature['icon'] ?? '') ?>"
                                   placeholder="e.g. star, shield, check-circle (Feather icon)">
                        </div>
                        <div class="form-group mb-2">
                            <label>Title</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="blocks[<?= $blockIndex ?>][config][features][<?= $i ?>][title]"
                                   value="<?= h($feature['title'] ?? '') ?>"
                                   placeholder="Feature title">
                        </div>
                        <div class="form-group mb-0">
                            <label>Description</label>
                            <textarea class="form-control form-control-sm"
                                      name="blocks[<?= $blockIndex ?>][config][features][<?= $i ?>][description]"
                                      rows="2"
                                      placeholder="Brief feature description."><?= h($feature['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-feature-btn-<?= $blockIndex ?>">
        + Add Feature
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.feature-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-feature-btn-' + blockIndex);
    var featureCount = container.querySelectorAll('.feature-item').length;

    addBtn.addEventListener('click', function() {
        var idx = featureCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 feature-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Feature #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-feature-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-group mb-2">' +
                    '<label>Icon Name</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][features][' + idx + '][icon]" placeholder="e.g. star, shield, check-circle (Feather icon)">' +
                '</div>' +
                '<div class="form-group mb-2">' +
                    '<label>Title</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][features][' + idx + '][title]" placeholder="Feature title">' +
                '</div>' +
                '<div class="form-group mb-0">' +
                    '<label>Description</label>' +
                    '<textarea class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][features][' + idx + '][description]" rows="2" placeholder="Brief feature description."></textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-feature-btn')) {
            e.target.closest('.feature-item').remove();
        }
    });
})();
</script>
