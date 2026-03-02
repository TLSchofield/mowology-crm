<?php
/**
 * Block Form: Quote CTA (P2-E)
 * Variables: $blockIndex, $config
 */
$headline    = $config['headline']    ?? 'Get Your Free Lawn Care Quote';
$subheadline = $config['subheadline'] ?? 'No obligation. Same-day estimates available.';
$offerText   = $config['offer_text']  ?? '';
$buttonText  = $config['button_text'] ?? 'Get a Free Quote';
$buttonUrl   = $config['button_url']  ?? '/quote';
$style       = $config['style']       ?? 'green';
$showPhone   = isset($config['show_phone']) ? (bool)$config['show_phone'] : true;
?>
<div class="form-group">
    <label>Headline</label>
    <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][headline]"
           value="<?php echo h($headline); ?>">
</div>
<div class="form-group">
    <label>Subheadline</label>
    <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][subheadline]"
           value="<?php echo h($subheadline); ?>" placeholder="Supporting text under the headline">
</div>
<div class="form-group">
    <label>Offer / Urgency Text <small class="text-muted">(optional — shown as a pill above headline)</small></label>
    <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][offer_text]"
           value="<?php echo h($offerText); ?>" placeholder="e.g. Spring Special — Save 20%">
</div>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Button Text</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][button_text]"
                   value="<?php echo h($buttonText); ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Button URL</label>
            <input type="text" class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][button_url]"
                   value="<?php echo h($buttonUrl); ?>">
        </div>
    </div>
</div>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Style</label>
            <select class="form-control" name="blocks[<?php echo $blockIndex; ?>][config][style]">
                <option value="green" <?php echo $style === 'green' ? 'selected' : ''; ?>>Green (brand)</option>
                <option value="dark"  <?php echo $style === 'dark'  ? 'selected' : ''; ?>>Dark</option>
                <option value="light" <?php echo $style === 'light' ? 'selected' : ''; ?>>Light</option>
            </select>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>&nbsp;</label>
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" id="showPhone_<?php echo $blockIndex; ?>"
                       name="blocks[<?php echo $blockIndex; ?>][config][show_phone]" value="1"
                       <?php echo $showPhone ? 'checked' : ''; ?>>
                <label class="form-check-label" for="showPhone_<?php echo $blockIndex; ?>">Show phone number button</label>
            </div>
        </div>
    </div>
</div>
