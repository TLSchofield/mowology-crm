<?php
/**
 * Service Cards Block Form
 *
 * Config fields: title, description, columns, services[] (icon, title, description, url)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$columns     = $config['columns'] ?? '3';
$services    = $config['services'] ?? [];
?>

<div class="service-cards-block-form">
    <div class="form-group">
        <label for="sc-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="sc-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. Our Services">
    </div>

    <div class="form-group">
        <label for="sc-description-<?= $blockIndex ?>">Section Description</label>
        <textarea class="form-control"
                  id="sc-description-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][description]"
                  rows="2"
                  placeholder="Optional intro text above the service cards."><?= h($description) ?></textarea>
    </div>

    <div class="form-group">
        <label for="sc-columns-<?= $blockIndex ?>">Columns</label>
        <select class="form-control"
                id="sc-columns-<?= $blockIndex ?>"
                name="blocks[<?= $blockIndex ?>][config][columns]">
            <option value="2" <?= $columns === '2' ? 'selected' : '' ?>>2 Columns</option>
            <option value="3" <?= $columns === '3' ? 'selected' : '' ?>>3 Columns</option>
            <option value="4" <?= $columns === '4' ? 'selected' : '' ?>>4 Columns</option>
        </select>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Services</h6>

    <div class="service-items-container-<?= $blockIndex ?>">
        <?php if (!empty($services)): ?>
            <?php foreach ($services as $i => $service): ?>
                <div class="card mb-2 service-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">Service #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-service-btn">&times; Remove</button>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-4 mb-2">
                                <label>Icon Name</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="blocks[<?= $blockIndex ?>][config][services][<?= $i ?>][icon]"
                                       value="<?= h($service['icon'] ?? '') ?>"
                                       placeholder="e.g. scissors, sun, droplet">
                            </div>
                            <div class="form-group col-md-4 mb-2">
                                <label>Title</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="blocks[<?= $blockIndex ?>][config][services][<?= $i ?>][title]"
                                       value="<?= h($service['title'] ?? '') ?>"
                                       placeholder="Service name">
                            </div>
                            <div class="form-group col-md-4 mb-2">
                                <label>Link URL</label>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       name="blocks[<?= $blockIndex ?>][config][services][<?= $i ?>][url]"
                                       value="<?= h($service['url'] ?? '') ?>"
                                       placeholder="e.g. /services/lawn-care">
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label>Description</label>
                            <textarea class="form-control form-control-sm"
                                      name="blocks[<?= $blockIndex ?>][config][services][<?= $i ?>][description]"
                                      rows="2"
                                      placeholder="Brief description of this service."><?= h($service['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-service-btn-<?= $blockIndex ?>">
        + Add Service
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.service-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-service-btn-' + blockIndex);
    var itemCount = container.querySelectorAll('.service-item').length;

    addBtn.addEventListener('click', function() {
        var idx = itemCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 service-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Service #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-service-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-row">' +
                    '<div class="form-group col-md-4 mb-2">' +
                        '<label>Icon Name</label>' +
                        '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][services][' + idx + '][icon]" placeholder="e.g. scissors, sun, droplet">' +
                    '</div>' +
                    '<div class="form-group col-md-4 mb-2">' +
                        '<label>Title</label>' +
                        '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][services][' + idx + '][title]" placeholder="Service name">' +
                    '</div>' +
                    '<div class="form-group col-md-4 mb-2">' +
                        '<label>Link URL</label>' +
                        '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][services][' + idx + '][url]" placeholder="e.g. /services/lawn-care">' +
                    '</div>' +
                '</div>' +
                '<div class="form-group mb-0">' +
                    '<label>Description</label>' +
                    '<textarea class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][services][' + idx + '][description]" rows="2" placeholder="Brief description of this service."></textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-service-btn')) {
            e.target.closest('.service-item').remove();
        }
    });
})();
</script>
