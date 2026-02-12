<?php
/**
 * Gallery Block Form
 *
 * Config fields: title, description, layout, columns, images[] (media_id, caption)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$layout      = $config['layout'] ?? 'grid';
$columns     = $config['columns'] ?? '3';
$images      = $config['images'] ?? [];
?>

<div class="gallery-block-form">
    <div class="form-group">
        <label for="gal-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="gal-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. Our Work">
    </div>

    <div class="form-group">
        <label for="gal-description-<?= $blockIndex ?>">Section Description</label>
        <textarea class="form-control"
                  id="gal-description-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][description]"
                  rows="2"
                  placeholder="Optional intro text above the gallery."><?= h($description) ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="gal-layout-<?= $blockIndex ?>">Layout</label>
            <select class="form-control"
                    id="gal-layout-<?= $blockIndex ?>"
                    name="blocks[<?= $blockIndex ?>][config][layout]">
                <option value="grid" <?= $layout === 'grid' ? 'selected' : '' ?>>Grid</option>
                <option value="carousel" <?= $layout === 'carousel' ? 'selected' : '' ?>>Carousel</option>
            </select>
        </div>
        <div class="form-group col-md-6">
            <label for="gal-columns-<?= $blockIndex ?>">Columns</label>
            <select class="form-control"
                    id="gal-columns-<?= $blockIndex ?>"
                    name="blocks[<?= $blockIndex ?>][config][columns]">
                <option value="2" <?= $columns === '2' ? 'selected' : '' ?>>2 Columns</option>
                <option value="3" <?= $columns === '3' ? 'selected' : '' ?>>3 Columns</option>
                <option value="4" <?= $columns === '4' ? 'selected' : '' ?>>4 Columns</option>
            </select>
            <small class="form-text text-muted">Only applies to grid layout.</small>
        </div>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Images</h6>

    <div class="gallery-items-container-<?= $blockIndex ?>">
        <?php if (!empty($images)): ?>
            <?php foreach ($images as $i => $image): ?>
                <div class="card mb-2 gallery-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">Image #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-gallery-btn">&times; Remove</button>
                        </div>
                        <input type="hidden"
                               name="blocks[<?= $blockIndex ?>][config][images][<?= $i ?>][media_id]"
                               value="<?= h($image['media_id'] ?? '') ?>">
                        <div class="form-group mb-0">
                            <label>Caption</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="blocks[<?= $blockIndex ?>][config][images][<?= $i ?>][caption]"
                                   value="<?= h($image['caption'] ?? '') ?>"
                                   placeholder="Image caption (optional)">
                        </div>
                        <small class="form-text text-muted">Media picker coming soon.</small>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-gallery-btn-<?= $blockIndex ?>">
        + Add Image
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.gallery-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-gallery-btn-' + blockIndex);
    var itemCount = container.querySelectorAll('.gallery-item').length;

    addBtn.addEventListener('click', function() {
        var idx = itemCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 gallery-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Image #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-gallery-btn">&times; Remove</button>' +
                '</div>' +
                '<input type="hidden" name="blocks[' + blockIndex + '][config][images][' + idx + '][media_id]" value="">' +
                '<div class="form-group mb-0">' +
                    '<label>Caption</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][images][' + idx + '][caption]" placeholder="Image caption (optional)">' +
                '</div>' +
                '<small class="form-text text-muted">Media picker coming soon.</small>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-gallery-btn')) {
            e.target.closest('.gallery-item').remove();
        }
    });
})();
</script>
