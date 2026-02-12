<?php
/**
 * Hero Block Form
 *
 * Config fields: headline, subheadline, cta_text, cta_url, media_id, media_alt
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$headline    = $config['headline'] ?? '';
$subheadline = $config['subheadline'] ?? '';
$ctaText     = $config['cta_text'] ?? '';
$ctaUrl      = $config['cta_url'] ?? '';
$mediaId     = $config['media_id'] ?? '';
$mediaAlt    = $config['media_alt'] ?? '';
?>

<div class="hero-block-form">
    <div class="form-group">
        <label for="hero-headline-<?= $blockIndex ?>">Headline</label>
        <input type="text"
               class="form-control"
               id="hero-headline-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][headline]"
               value="<?= h($headline) ?>"
               placeholder="e.g. Professional Landscaping Services">
        <small class="form-text text-muted">Main hero heading displayed prominently on the page.</small>
    </div>

    <div class="form-group">
        <label for="hero-subheadline-<?= $blockIndex ?>">Subheadline</label>
        <textarea class="form-control"
                  id="hero-subheadline-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][subheadline]"
                  rows="3"
                  placeholder="e.g. Transforming outdoor spaces across Vancouver since 2015."><?= h($subheadline) ?></textarea>
        <small class="form-text text-muted">Supporting text displayed below the headline.</small>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="hero-cta-text-<?= $blockIndex ?>">CTA Button Text</label>
            <input type="text"
                   class="form-control"
                   id="hero-cta-text-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][cta_text]"
                   value="<?= h($ctaText) ?>"
                   placeholder="e.g. Get a Free Quote">
        </div>
        <div class="form-group col-md-6">
            <label for="hero-cta-url-<?= $blockIndex ?>">CTA Button URL</label>
            <input type="text"
                   class="form-control"
                   id="hero-cta-url-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][cta_url]"
                   value="<?= h($ctaUrl) ?>"
                   placeholder="e.g. /quote">
        </div>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Hero Image</h6>

    <input type="hidden"
           id="hero-media-id-<?= $blockIndex ?>"
           name="blocks[<?= $blockIndex ?>][config][media_id]"
           value="<?= h($mediaId) ?>">

    <div class="form-group">
        <label for="hero-media-alt-<?= $blockIndex ?>">Image Alt Text</label>
        <input type="text"
               class="form-control"
               id="hero-media-alt-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][media_alt]"
               value="<?= h($mediaAlt) ?>"
               placeholder="Descriptive alt text for the hero image">
        <small class="form-text text-muted">Describes the image for accessibility and SEO. Media picker coming soon.</small>
    </div>
</div>
