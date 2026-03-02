<?php
/**
 * Block Form: Portfolio Showcase
 * Config: {title, description, cta_text, cta_url, projects[{title, media_id, url}]}
 *
 * @var array $config
 * @var int   $blockIndex
 */
$title       = $config['title']       ?? 'Our Work';
$description = $config['description'] ?? '';
$ctaText     = $config['cta_text']    ?? '';
$ctaUrl      = $config['cta_url']     ?? '';
$projects    = is_array($config['projects'] ?? null) ? $config['projects'] : [];
?>
<div class="form-group">
    <label>Section Title</label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][title]"
           value="<?php echo h($title); ?>"
           placeholder="e.g. Our Work">
</div>
<div class="form-group">
    <label>Section Description <small class="text-muted">(optional)</small></label>
    <textarea class="form-control" rows="2"
              name="blocks[<?php echo $blockIndex; ?>][config][description]"
              placeholder="Brief intro above the portfolio grid."><?php echo h($description); ?></textarea>
</div>

<hr>
<h6 class="text-muted mb-2">Portfolio Items</h6>

<div class="ps-items-container-<?php echo $blockIndex; ?>">
    <?php foreach ($projects as $i => $proj): ?>
    <div class="card mb-2 ps-item">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="text-muted">Project #<?php echo $i + 1; ?></strong>
                <button type="button" class="btn btn-sm btn-outline-danger remove-ps-btn">&times; Remove</button>
            </div>
            <div class="form-row">
                <div class="col-md-3">
                    <div class="form-group mb-2">
                        <label class="small">Media ID</label>
                        <input type="number" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][projects][<?php echo $i; ?>][media_id]"
                               value="<?php echo (int)($proj['media_id'] ?? 0) ?: ''; ?>"
                               placeholder="ID" min="1">
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="form-group mb-2">
                        <label class="small">Project Title</label>
                        <input type="text" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][projects][<?php echo $i; ?>][title]"
                               value="<?php echo h($proj['title'] ?? ''); ?>"
                               placeholder="e.g. Riverside Estate Landscape">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group mb-2">
                        <label class="small">Link URL <small class="text-muted">(optional)</small></label>
                        <input type="text" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][projects][<?php echo $i; ?>][url]"
                               value="<?php echo h($proj['url'] ?? ''); ?>"
                               placeholder="/portfolio/riverside">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<button type="button" class="btn btn-sm btn-outline-secondary mt-1 add-ps-btn-<?php echo $blockIndex; ?>">
    + Add Portfolio Item
</button>

<hr>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>CTA Button Text <small class="text-muted">(optional)</small></label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][cta_text]"
                   value="<?php echo h($ctaText); ?>"
                   placeholder="e.g. View Full Portfolio">
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>CTA URL</label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][cta_url]"
                   value="<?php echo h($ctaUrl); ?>"
                   placeholder="e.g. /portfolio">
        </div>
    </div>
</div>

<script>
(function() {
    var bi = <?php echo (int)$blockIndex; ?>;
    var container = document.querySelector('.ps-items-container-' + bi);
    var addBtn    = document.querySelector('.add-ps-btn-' + bi);
    var count     = container.querySelectorAll('.ps-item').length;

    addBtn.addEventListener('click', function() {
        var idx = count++;
        var card = document.createElement('div');
        card.className = 'card mb-2 ps-item';
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Project #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-ps-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-row">' +
                    '<div class="col-md-3"><div class="form-group mb-2"><label class="small">Media ID</label>' +
                    '<input type="number" class="form-control form-control-sm" name="blocks[' + bi + '][config][projects][' + idx + '][media_id]" placeholder="ID" min="1"></div></div>' +
                    '<div class="col-md-5"><div class="form-group mb-2"><label class="small">Project Title</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][projects][' + idx + '][title]" placeholder="e.g. Riverside Estate Landscape"></div></div>' +
                    '<div class="col-md-4"><div class="form-group mb-2"><label class="small">Link URL <small class="text-muted">(optional)</small></label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][projects][' + idx + '][url]" placeholder="/portfolio/riverside"></div></div>' +
                '</div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-ps-btn')) {
            e.target.closest('.ps-item').remove();
        }
    });
})();
</script>
