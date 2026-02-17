<?php
/**
 * Area Cards Block Form
 *
 * Config fields: title, subtitle, areas[] (name, neighborhoods)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title    = $config['title'] ?? '';
$subtitle = $config['subtitle'] ?? '';
$areas    = $config['areas'] ?? [];
?>

<div class="area-cards-block-form">
    <div class="form-group">
        <label for="ac-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="ac-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. Service Areas">
    </div>

    <div class="form-group">
        <label for="ac-subtitle-<?= $blockIndex ?>">Subtitle</label>
        <input type="text"
               class="form-control"
               id="ac-subtitle-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][subtitle]"
               value="<?= h($subtitle) ?>"
               placeholder="e.g. Proudly serving Metro Vancouver">
    </div>

    <hr>
    <h6 class="text-muted mb-3">Areas</h6>

    <div class="area-items-container-<?= $blockIndex ?>">
        <?php if (!empty($areas)): ?>
            <?php foreach ($areas as $i => $area): ?>
                <div class="card mb-2 area-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">Area #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-area-btn">&times; Remove</button>
                        </div>
                        <div class="form-group mb-2">
                            <label>Area Name</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="blocks[<?= $blockIndex ?>][config][areas][<?= $i ?>][name]"
                                   value="<?= h($area['name'] ?? '') ?>"
                                   placeholder="e.g. Vancouver">
                        </div>
                        <div class="form-group mb-0">
                            <label>Neighborhoods</label>
                            <textarea class="form-control form-control-sm"
                                      name="blocks[<?= $blockIndex ?>][config][areas][<?= $i ?>][neighborhoods]"
                                      rows="2"
                                      placeholder="e.g. Downtown, West End, Kitsilano, Point Grey"><?= h($area['neighborhoods'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-area-btn-<?= $blockIndex ?>">
        + Add Area
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.area-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-area-btn-' + blockIndex);
    var areaCount = container.querySelectorAll('.area-item').length;

    addBtn.addEventListener('click', function() {
        var idx = areaCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 area-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Area #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-area-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-group mb-2">' +
                    '<label>Area Name</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][areas][' + idx + '][name]" placeholder="e.g. Vancouver">' +
                '</div>' +
                '<div class="form-group mb-0">' +
                    '<label>Neighborhoods</label>' +
                    '<textarea class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][areas][' + idx + '][neighborhoods]" rows="2" placeholder="e.g. Downtown, West End, Kitsilano"></textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-area-btn')) {
            e.target.closest('.area-item').remove();
        }
    });
})();
</script>
