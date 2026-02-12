<?php
/**
 * Testimonials Block Form
 *
 * Config fields: title, layout, testimonials[] (name, role, content, image_id)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title        = $config['title'] ?? '';
$layout       = $config['layout'] ?? 'grid';
$testimonials = $config['testimonials'] ?? [];
?>

<div class="testimonials-block-form">
    <div class="form-group">
        <label for="test-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="test-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. What Our Clients Say">
    </div>

    <div class="form-group">
        <label for="test-layout-<?= $blockIndex ?>">Layout</label>
        <select class="form-control"
                id="test-layout-<?= $blockIndex ?>"
                name="blocks[<?= $blockIndex ?>][config][layout]">
            <option value="grid" <?= $layout === 'grid' ? 'selected' : '' ?>>Grid</option>
            <option value="carousel" <?= $layout === 'carousel' ? 'selected' : '' ?>>Carousel</option>
        </select>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Testimonials</h6>

    <div class="testimonial-items-container-<?= $blockIndex ?>">
        <?php if (!empty($testimonials)): ?>
            <?php foreach ($testimonials as $i => $testimonial): ?>
                <div class="card mb-2 testimonial-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">Testimonial #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-testimonial-btn">&times; Remove</button>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6 mb-2">
                                <label>Name</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="blocks[<?= $blockIndex ?>][config][testimonials][<?= $i ?>][name]"
                                       value="<?= h($testimonial['name'] ?? '') ?>"
                                       placeholder="Client name">
                            </div>
                            <div class="form-group col-md-6 mb-2">
                                <label>Role / Title</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="blocks[<?= $blockIndex ?>][config][testimonials][<?= $i ?>][role]"
                                       value="<?= h($testimonial['role'] ?? '') ?>"
                                       placeholder="e.g. Homeowner, Business Owner">
                            </div>
                        </div>
                        <div class="form-group mb-2">
                            <label>Content</label>
                            <textarea class="form-control form-control-sm"
                                      name="blocks[<?= $blockIndex ?>][config][testimonials][<?= $i ?>][content]"
                                      rows="3"
                                      placeholder="The testimonial text."><?= h($testimonial['content'] ?? '') ?></textarea>
                        </div>
                        <input type="hidden"
                               name="blocks[<?= $blockIndex ?>][config][testimonials][<?= $i ?>][image_id]"
                               value="<?= h($testimonial['image_id'] ?? '') ?>">
                        <small class="form-text text-muted">Image picker coming soon.</small>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-testimonial-btn-<?= $blockIndex ?>">
        + Add Testimonial
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.testimonial-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-testimonial-btn-' + blockIndex);
    var itemCount = container.querySelectorAll('.testimonial-item').length;

    addBtn.addEventListener('click', function() {
        var idx = itemCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 testimonial-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Testimonial #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-testimonial-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-row">' +
                    '<div class="form-group col-md-6 mb-2">' +
                        '<label>Name</label>' +
                        '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][testimonials][' + idx + '][name]" placeholder="Client name">' +
                    '</div>' +
                    '<div class="form-group col-md-6 mb-2">' +
                        '<label>Role / Title</label>' +
                        '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][testimonials][' + idx + '][role]" placeholder="e.g. Homeowner, Business Owner">' +
                    '</div>' +
                '</div>' +
                '<div class="form-group mb-2">' +
                    '<label>Content</label>' +
                    '<textarea class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][testimonials][' + idx + '][content]" rows="3" placeholder="The testimonial text."></textarea>' +
                '</div>' +
                '<input type="hidden" name="blocks[' + blockIndex + '][config][testimonials][' + idx + '][image_id]" value="">' +
                '<small class="form-text text-muted">Image picker coming soon.</small>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-testimonial-btn')) {
            e.target.closest('.testimonial-item').remove();
        }
    });
})();
</script>
