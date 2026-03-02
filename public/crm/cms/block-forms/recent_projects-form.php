<?php
/**
 * Block Form: Recent Projects (P2-E)
 * Variables: $blockIndex, $config
 */
$headline = $config['headline']  ?? 'Recent Projects';
$count    = $config['count']     ?? 6;
$category = $config['category']  ?? '';
$ctaText  = $config['cta_text']  ?? 'View All Projects';
$ctaUrl   = $config['cta_url']   ?? '/portfolio';
$layout   = $config['layout']    ?? 'grid';
?>
<div class="form-group">
    <label>Section Headline</label>
    <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][headline]"
           value="<?php echo h($headline); ?>" placeholder="Recent Projects">
</div>
<div class="form-row">
    <div class="col-md-4">
        <div class="form-group">
            <label>Number of Projects</label>
            <input type="number" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][count]"
                   value="<?php echo (int)$count; ?>" min="1" max="12">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Filter by Category</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][category]"
                   value="<?php echo h($category); ?>" placeholder="e.g. Lawn Care (leave blank for all)">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label>Layout</label>
            <select class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][layout]">
                <option value="grid"     <?php echo $layout === 'grid'     ? 'selected' : ''; ?>>Grid</option>
                <option value="carousel" <?php echo $layout === 'carousel' ? 'selected' : ''; ?>>Carousel</option>
            </select>
        </div>
    </div>
</div>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>CTA Button Text</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][cta_text]"
                   value="<?php echo h($ctaText); ?>" placeholder="View All Projects">
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>CTA Button URL</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][cta_url]"
                   value="<?php echo h($ctaUrl); ?>" placeholder="/portfolio">
        </div>
    </div>
</div>
