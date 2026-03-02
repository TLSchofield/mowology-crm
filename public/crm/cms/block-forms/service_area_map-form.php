<?php
/**
 * Block Form: Service Area Map (P2-E)
 * Variables: $blockIndex, $config
 */
$headline  = $config['headline']   ?? 'Our Service Areas';
$centerLat = $config['center_lat'] ?? '49.2827';
$centerLng = $config['center_lng'] ?? '-123.1207';
$zoom      = $config['zoom']       ?? '11';
$areas     = $config['areas']      ?? [];
?>
<div class="form-group">
    <label>Headline</label>
    <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][headline]"
           value="<?php echo h($headline); ?>">
</div>
<div class="form-row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Map Center Latitude</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][center_lat]"
                   value="<?php echo h($centerLat); ?>" placeholder="49.2827">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Map Center Longitude</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][center_lng]"
                   value="<?php echo h($centerLng); ?>" placeholder="-123.1207">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Default Zoom (1–18)</label>
            <input type="number" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][zoom]"
                   value="<?php echo (int)$zoom; ?>" min="1" max="18">
        </div>
    </div>
</div>
<p class="small text-muted">Service areas will use the default Vancouver metro pins if left blank.</p>
<div id="areas-container-<?php echo $blockIndex; ?>">
    <?php foreach ($areas as $ai => $area): ?>
    <div class="form-row mb-1 area-row">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm"
                   name="blocks[<?php echo $blockIndex; ?>][config][areas][<?php echo $ai; ?>][name]"
                   value="<?php echo h($area['name'] ?? ''); ?>" placeholder="Area name">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm"
                   name="blocks[<?php echo $blockIndex; ?>][config][areas][<?php echo $ai; ?>][lat]"
                   value="<?php echo h($area['lat'] ?? ''); ?>" placeholder="Latitude">
        </div>
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm"
                   name="blocks[<?php echo $blockIndex; ?>][config][areas][<?php echo $ai; ?>][lng]"
                   value="<?php echo h($area['lng'] ?? ''); ?>" placeholder="Longitude">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.area-row').remove()">Remove</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<button type="button" class="btn btn-sm btn-outline-secondary mt-1"
        onclick="addAreaRow('<?php echo $blockIndex; ?>')">+ Add Area</button>
<script>
var areaRowCounts = {};
function addAreaRow(bi) {
    areaRowCounts[bi] = (areaRowCounts[bi] || <?php echo count($areas); ?>) + 1;
    var idx = areaRowCounts[bi];
    var container = document.getElementById('areas-container-' + bi);
    var row = document.createElement('div');
    row.className = 'form-row mb-1 area-row';
    row.innerHTML = '<div class="col-md-4"><input type="text" class="form-control form-control-sm" name="blocks['+bi+'][config][areas]['+idx+'][name]" placeholder="Area name"></div>'
        + '<div class="col-md-3"><input type="text" class="form-control form-control-sm" name="blocks['+bi+'][config][areas]['+idx+'][lat]" placeholder="Latitude"></div>'
        + '<div class="col-md-3"><input type="text" class="form-control form-control-sm" name="blocks['+bi+'][config][areas]['+idx+'][lng]" placeholder="Longitude"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.area-row\').remove()">Remove</button></div>';
    container.appendChild(row);
}
</script>
