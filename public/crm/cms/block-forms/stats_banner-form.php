<?php
/**
 * Block Form: Stats Banner
 * Config: {title, background (green|dark|light), stats[{number, label}]}
 *
 * @var array $config
 * @var int   $blockIndex
 */
$title      = $config['title']      ?? '';
$background = $config['background'] ?? 'green';
$stats      = $config['stats']      ?? [];
?>
<div class="form-row">
    <div class="col-md-8">
        <div class="form-group">
            <label>Section Title <small class="text-muted">(optional)</small></label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][title]"
                   value="<?php echo h($title); ?>"
                   placeholder="e.g. Our Results in Numbers">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Background Style</label>
            <select class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][background]">
                <option value="green" <?php echo $background === 'green' ? 'selected' : ''; ?>>Green (brand)</option>
                <option value="dark"  <?php echo $background === 'dark'  ? 'selected' : ''; ?>>Dark</option>
                <option value="light" <?php echo $background === 'light' ? 'selected' : ''; ?>>Light</option>
            </select>
        </div>
    </div>
</div>

<hr>
<h6 class="text-muted mb-2">Stats <small>(each stat shows a big number + label)</small></h6>

<div class="stats-items-container-<?php echo $blockIndex; ?>">
    <?php foreach ($stats as $i => $stat): ?>
    <div class="form-row mb-2 stats-item-row">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm"
                   name="blocks[<?php echo $blockIndex; ?>][config][stats][<?php echo $i; ?>][number]"
                   value="<?php echo h($stat['number'] ?? ''); ?>"
                   placeholder="e.g. 500+">
        </div>
        <div class="col-md-6">
            <input type="text" class="form-control form-control-sm"
                   name="blocks[<?php echo $blockIndex; ?>][config][stats][<?php echo $i; ?>][label]"
                   value="<?php echo h($stat['label'] ?? ''); ?>"
                   placeholder="e.g. Happy Customers">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="this.closest('.stats-item-row').remove()">Remove</button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<button type="button" class="btn btn-sm btn-outline-secondary mt-1"
        onclick="addStatRow_<?php echo $blockIndex; ?>()">+ Add Stat</button>
<script>
var statRowCount_<?php echo $blockIndex; ?> = <?php echo count($stats); ?>;
function addStatRow_<?php echo $blockIndex; ?>() {
    var bi  = <?php echo (int)$blockIndex; ?>;
    var idx = statRowCount_<?php echo $blockIndex; ?>++;
    var container = document.querySelector('.stats-items-container-' + bi);
    var row = document.createElement('div');
    row.className = 'form-row mb-2 stats-item-row';
    row.innerHTML =
        '<div class="col-md-4"><input type="text" class="form-control form-control-sm"'
        + ' name="blocks[' + bi + '][config][stats][' + idx + '][number]" placeholder="e.g. 500+"></div>'
        + '<div class="col-md-6"><input type="text" class="form-control form-control-sm"'
        + ' name="blocks[' + bi + '][config][stats][' + idx + '][label]" placeholder="e.g. Happy Customers"></div>'
        + '<div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger"'
        + ' onclick="this.closest(\'.stats-item-row\').remove()">Remove</button></div>';
    container.appendChild(row);
}
</script>
