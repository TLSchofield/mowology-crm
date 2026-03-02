<?php
/**
 * Block Form: Service Grid
 * Config: {title, description, services[{icon, title, description, url}], cta_text, cta_url}
 *
 * @var array $config
 * @var int   $blockIndex
 */
$title       = $config['title']       ?? '';
$description = $config['description'] ?? '';
$services    = $config['services']    ?? [];
$ctaText     = $config['cta_text']    ?? '';
$ctaUrl      = $config['cta_url']     ?? '';
?>
<div class="form-group">
    <label>Section Title</label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][title]"
           value="<?php echo h($title); ?>"
           placeholder="e.g. Our Services">
</div>
<div class="form-group">
    <label>Section Description <small class="text-muted">(optional)</small></label>
    <textarea class="form-control" rows="2"
              name="blocks[<?php echo $blockIndex; ?>][config][description]"
              placeholder="Brief intro above the service cards."><?php echo h($description); ?></textarea>
</div>

<hr>
<h6 class="text-muted mb-2">Service Cards</h6>

<div class="sg-items-container-<?php echo $blockIndex; ?>">
    <?php foreach ($services as $i => $svc): ?>
    <div class="card mb-2 sg-item">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="text-muted">Service #<?php echo $i + 1; ?></strong>
                <button type="button" class="btn btn-sm btn-outline-danger remove-sg-btn">&times; Remove</button>
            </div>
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group mb-2">
                        <label class="small">Feather Icon Name</label>
                        <input type="text" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][services][<?php echo $i; ?>][icon]"
                               value="<?php echo h($svc['icon'] ?? ''); ?>"
                               placeholder="e.g. scissors, sun, droplet">
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="form-group mb-2">
                        <label class="small">Service Title</label>
                        <input type="text" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][services][<?php echo $i; ?>][title]"
                               value="<?php echo h($svc['title'] ?? ''); ?>"
                               placeholder="e.g. Lawn Mowing">
                    </div>
                </div>
            </div>
            <div class="form-group mb-2">
                <label class="small">Description</label>
                <textarea class="form-control form-control-sm" rows="2"
                          name="blocks[<?php echo $blockIndex; ?>][config][services][<?php echo $i; ?>][description]"
                          placeholder="One or two sentences about this service."><?php echo h($svc['description'] ?? ''); ?></textarea>
            </div>
            <div class="form-group mb-0">
                <label class="small">Link URL <small class="text-muted">(optional — shows "Learn more" arrow)</small></label>
                <input type="text" class="form-control form-control-sm"
                       name="blocks[<?php echo $blockIndex; ?>][config][services][<?php echo $i; ?>][url]"
                       value="<?php echo h($svc['url'] ?? ''); ?>"
                       placeholder="e.g. /services/lawn-mowing">
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<button type="button" class="btn btn-sm btn-outline-secondary mt-1 add-sg-btn-<?php echo $blockIndex; ?>">
    + Add Service Card
</button>

<hr>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Bottom CTA Button Text <small class="text-muted">(optional)</small></label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][cta_text]"
                   value="<?php echo h($ctaText); ?>"
                   placeholder="e.g. View All Services">
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Bottom CTA URL</label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][cta_url]"
                   value="<?php echo h($ctaUrl); ?>"
                   placeholder="e.g. /services">
        </div>
    </div>
</div>

<script>
(function() {
    var bi = <?php echo (int)$blockIndex; ?>;
    var container = document.querySelector('.sg-items-container-' + bi);
    var addBtn    = document.querySelector('.add-sg-btn-' + bi);
    var count     = container.querySelectorAll('.sg-item').length;

    addBtn.addEventListener('click', function() {
        var idx = count++;
        var card = document.createElement('div');
        card.className = 'card mb-2 sg-item';
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Service #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-sg-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-row">' +
                    '<div class="col-md-4"><div class="form-group mb-2"><label class="small">Feather Icon Name</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][services][' + idx + '][icon]" placeholder="e.g. scissors"></div></div>' +
                    '<div class="col-md-8"><div class="form-group mb-2"><label class="small">Service Title</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][services][' + idx + '][title]" placeholder="e.g. Lawn Mowing"></div></div>' +
                '</div>' +
                '<div class="form-group mb-2"><label class="small">Description</label>' +
                '<textarea class="form-control form-control-sm" rows="2" name="blocks[' + bi + '][config][services][' + idx + '][description]" placeholder="One or two sentences."></textarea></div>' +
                '<div class="form-group mb-0"><label class="small">Link URL <small class="text-muted">(optional)</small></label>' +
                '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][services][' + idx + '][url]" placeholder="e.g. /services/lawn-mowing"></div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-sg-btn')) {
            e.target.closest('.sg-item').remove();
        }
    });
})();
</script>
