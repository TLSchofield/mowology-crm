<?php
/**
 * Block Form: Before / After
 * Config: {title, description, pairs[{before_media_id, after_media_id, caption}]}
 * The renderer queries media_assets for file_path — we store IDs and show a text fallback.
 *
 * @var array $config
 * @var int   $blockIndex
 */
$title       = $config['title']       ?? '';
$description = $config['description'] ?? '';
$pairs       = $config['pairs']       ?? [];
?>
<div class="form-group">
    <label>Section Title <small class="text-muted">(optional)</small></label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][title]"
           value="<?php echo h($title); ?>"
           placeholder="e.g. See the Difference">
</div>
<div class="form-group">
    <label>Section Description <small class="text-muted">(optional)</small></label>
    <textarea class="form-control" rows="2"
              name="blocks[<?php echo $blockIndex; ?>][config][description]"
              placeholder="Brief intro text above the comparison pairs."><?php echo h($description); ?></textarea>
</div>

<hr>
<h6 class="text-muted mb-2">Before / After Pairs</h6>
<p class="small text-muted">Enter the Media Asset IDs from the Media Library for each before and after image.</p>

<div class="ba-items-container-<?php echo $blockIndex; ?>">
    <?php foreach ($pairs as $i => $pair): ?>
    <div class="card mb-2 ba-item">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="text-muted">Pair #<?php echo $i + 1; ?></strong>
                <button type="button" class="btn btn-sm btn-outline-danger remove-ba-btn">&times; Remove</button>
            </div>
            <div class="form-row">
                <div class="col-md-6">
                    <div class="form-group mb-2">
                        <label class="small">Before — Media ID</label>
                        <input type="number" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][pairs][<?php echo $i; ?>][before_media_id]"
                               value="<?php echo (int)($pair['before_media_id'] ?? 0) ?: ''; ?>"
                               placeholder="Media Asset ID" min="1">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group mb-2">
                        <label class="small">After — Media ID</label>
                        <input type="number" class="form-control form-control-sm"
                               name="blocks[<?php echo $blockIndex; ?>][config][pairs][<?php echo $i; ?>][after_media_id]"
                               value="<?php echo (int)($pair['after_media_id'] ?? 0) ?: ''; ?>"
                               placeholder="Media Asset ID" min="1">
                    </div>
                </div>
            </div>
            <div class="form-group mb-0">
                <label class="small">Caption <small class="text-muted">(optional)</small></label>
                <input type="text" class="form-control form-control-sm"
                       name="blocks[<?php echo $blockIndex; ?>][config][pairs][<?php echo $i; ?>][caption]"
                       value="<?php echo h($pair['caption'] ?? ''); ?>"
                       placeholder="e.g. Overgrown lawn → pristine finish">
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<button type="button" class="btn btn-sm btn-outline-secondary mt-1 add-ba-btn-<?php echo $blockIndex; ?>">
    + Add Before/After Pair
</button>

<script>
(function() {
    var bi = <?php echo (int)$blockIndex; ?>;
    var container = document.querySelector('.ba-items-container-' + bi);
    var addBtn    = document.querySelector('.add-ba-btn-' + bi);
    var count     = container.querySelectorAll('.ba-item').length;

    addBtn.addEventListener('click', function() {
        var idx = count++;
        var card = document.createElement('div');
        card.className = 'card mb-2 ba-item';
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">Pair #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-ba-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-row">' +
                    '<div class="col-md-6"><div class="form-group mb-2"><label class="small">Before — Media ID</label>' +
                    '<input type="number" class="form-control form-control-sm" name="blocks[' + bi + '][config][pairs][' + idx + '][before_media_id]" placeholder="Media Asset ID" min="1"></div></div>' +
                    '<div class="col-md-6"><div class="form-group mb-2"><label class="small">After — Media ID</label>' +
                    '<input type="number" class="form-control form-control-sm" name="blocks[' + bi + '][config][pairs][' + idx + '][after_media_id]" placeholder="Media Asset ID" min="1"></div></div>' +
                '</div>' +
                '<div class="form-group mb-0"><label class="small">Caption <small class="text-muted">(optional)</small></label>' +
                '<input type="text" class="form-control form-control-sm" name="blocks[' + bi + '][config][pairs][' + idx + '][caption]" placeholder="e.g. Overgrown lawn → pristine finish"></div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-ba-btn')) {
            e.target.closest('.ba-item').remove();
        }
    });
})();
</script>
