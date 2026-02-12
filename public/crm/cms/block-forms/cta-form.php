<?php
/**
 * CTA (Call-to-Action) Block Form
 *
 * Config fields: headline, subheadline, primary_text, primary_url,
 *                secondary_text, secondary_url, style
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$headline      = $config['headline'] ?? '';
$subheadline   = $config['subheadline'] ?? '';
$primaryText   = $config['primary_text'] ?? '';
$primaryUrl    = $config['primary_url'] ?? '';
$secondaryText = $config['secondary_text'] ?? '';
$secondaryUrl  = $config['secondary_url'] ?? '';
$style         = $config['style'] ?? 'gradient';
?>

<div class="cta-block-form">
    <div class="form-group">
        <label for="cta-headline-<?= $blockIndex ?>">Headline</label>
        <input type="text"
               class="form-control"
               id="cta-headline-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][headline]"
               value="<?= h($headline) ?>"
               placeholder="e.g. Ready to Transform Your Yard?">
    </div>

    <div class="form-group">
        <label for="cta-subheadline-<?= $blockIndex ?>">Subheadline</label>
        <textarea class="form-control"
                  id="cta-subheadline-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][subheadline]"
                  rows="2"
                  placeholder="Supporting text below the headline."><?= h($subheadline) ?></textarea>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Primary Button</h6>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="cta-primary-text-<?= $blockIndex ?>">Button Text</label>
            <input type="text"
                   class="form-control"
                   id="cta-primary-text-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][primary_text]"
                   value="<?= h($primaryText) ?>"
                   placeholder="e.g. Get a Free Quote">
        </div>
        <div class="form-group col-md-6">
            <label for="cta-primary-url-<?= $blockIndex ?>">Button URL</label>
            <input type="text"
                   class="form-control"
                   id="cta-primary-url-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][primary_url]"
                   value="<?= h($primaryUrl) ?>"
                   placeholder="e.g. /quote">
        </div>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Secondary Button <small class="text-muted">(optional)</small></h6>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="cta-secondary-text-<?= $blockIndex ?>">Button Text</label>
            <input type="text"
                   class="form-control"
                   id="cta-secondary-text-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][secondary_text]"
                   value="<?= h($secondaryText) ?>"
                   placeholder="e.g. Learn More">
        </div>
        <div class="form-group col-md-6">
            <label for="cta-secondary-url-<?= $blockIndex ?>">Button URL</label>
            <input type="text"
                   class="form-control"
                   id="cta-secondary-url-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][secondary_url]"
                   value="<?= h($secondaryUrl) ?>"
                   placeholder="e.g. /services">
        </div>
    </div>

    <hr>

    <div class="form-group">
        <label for="cta-style-<?= $blockIndex ?>">Background Style</label>
        <select class="form-control"
                id="cta-style-<?= $blockIndex ?>"
                name="blocks[<?= $blockIndex ?>][config][style]">
            <option value="gradient" <?= $style === 'gradient' ? 'selected' : '' ?>>Gradient (brand green)</option>
            <option value="dark" <?= $style === 'dark' ? 'selected' : '' ?>>Dark (forest green)</option>
            <option value="light" <?= $style === 'light' ? 'selected' : '' ?>>Light (subtle background)</option>
        </select>
    </div>
</div>
