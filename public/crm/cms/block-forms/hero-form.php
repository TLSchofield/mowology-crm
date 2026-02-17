<?php
/**
 * Hero Block Form
 *
 * Config fields: headline, subheadline, cta_text, cta_url, secondary_cta_text, secondary_cta_url,
 *                media_id, media_alt, image, image_tablet, image_mobile, hero_style, trust_line
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$headline         = $config['headline'] ?? '';
$subheadline      = $config['subheadline'] ?? '';
$ctaText          = $config['cta_text'] ?? '';
$ctaUrl           = $config['cta_url'] ?? '';
$secondaryCtaText = $config['secondary_cta_text'] ?? '';
$secondaryCtaUrl  = $config['secondary_cta_url'] ?? '';
$mediaId          = $config['media_id'] ?? '';
$mediaAlt         = $config['media_alt'] ?? '';
$imagePath        = $config['image'] ?? '';
$imageTablet      = $config['image_tablet'] ?? '';
$imageMobile      = $config['image_mobile'] ?? '';
$heroStyle        = $config['hero_style'] ?? 'auto';
$trustLine        = $config['trust_line'] ?? '';

// Resolve current image for preview
$previewImage = $imagePath;
if ($mediaId && function_exists('cms_getMediaAssetById')) {
    $media = cms_getMediaAssetById((int)$mediaId);
    if ($media) {
        $previewImage = $media['file_path'] ?? '';
    }
}
?>

<div class="hero-block-form">
    <!-- Hero Style -->
    <div class="form-group">
        <label for="hero-style-<?= $blockIndex ?>">Hero Style</label>
        <select class="form-control"
                id="hero-style-<?= $blockIndex ?>"
                name="blocks[<?= $blockIndex ?>][config][hero_style]">
            <option value="auto" <?= $heroStyle === 'auto' ? 'selected' : '' ?>>Auto (image → side-by-side, no image → text only)</option>
            <option value="image_bg" <?= $heroStyle === 'image_bg' ? 'selected' : '' ?>>Full-Width Background Image (homepage style)</option>
            <option value="image_side" <?= $heroStyle === 'image_side' ? 'selected' : '' ?>>Side-by-Side Text + Image</option>
            <option value="text_only" <?= $heroStyle === 'text_only' ? 'selected' : '' ?>>Text Only (gradient background)</option>
        </select>
        <small class="form-text text-muted">Controls the layout of the hero section.</small>
    </div>

    <div class="form-group">
        <label for="hero-headline-<?= $blockIndex ?>">Headline</label>
        <input type="text"
               class="form-control"
               id="hero-headline-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][headline]"
               value="<?= h($headline) ?>"
               placeholder="e.g. Professional Landscaping Services">
    </div>

    <div class="form-group">
        <label for="hero-subheadline-<?= $blockIndex ?>">Subheadline</label>
        <textarea class="form-control"
                  id="hero-subheadline-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][subheadline]"
                  rows="3"
                  placeholder="Supporting text below the headline."><?= h($subheadline) ?></textarea>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="hero-cta-text-<?= $blockIndex ?>">Primary CTA Text</label>
            <input type="text"
                   class="form-control"
                   id="hero-cta-text-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][cta_text]"
                   value="<?= h($ctaText) ?>"
                   placeholder="e.g. Get Free Quote">
        </div>
        <div class="form-group col-md-6">
            <label for="hero-cta-url-<?= $blockIndex ?>">Primary CTA URL</label>
            <input type="text"
                   class="form-control"
                   id="hero-cta-url-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][cta_url]"
                   value="<?= h($ctaUrl) ?>"
                   placeholder="e.g. /quote">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="hero-cta2-text-<?= $blockIndex ?>">Secondary CTA Text <small class="text-muted">(optional)</small></label>
            <input type="text"
                   class="form-control"
                   id="hero-cta2-text-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][secondary_cta_text]"
                   value="<?= h($secondaryCtaText) ?>"
                   placeholder="e.g. Our Services">
        </div>
        <div class="form-group col-md-6">
            <label for="hero-cta2-url-<?= $blockIndex ?>">Secondary CTA URL</label>
            <input type="text"
                   class="form-control"
                   id="hero-cta2-url-<?= $blockIndex ?>"
                   name="blocks[<?= $blockIndex ?>][config][secondary_cta_url]"
                   value="<?= h($secondaryCtaUrl) ?>"
                   placeholder="e.g. /services">
        </div>
    </div>

    <hr>
    <h6 class="text-muted mb-3">Hero Image</h6>

    <!-- Image preview + picker -->
    <div class="mw-media-preview mb-3" id="hero-media-preview-<?= $blockIndex ?>">
        <?php if ($previewImage): ?>
            <img src="<?= h($previewImage) ?>" alt="Current hero image" class="mw-media-preview-img">
            <span class="mw-media-preview-name"><?= h(basename($previewImage)) ?></span>
        <?php else: ?>
            <div class="mw-media-preview-empty">No image selected</div>
        <?php endif; ?>
    </div>

    <input type="hidden"
           id="hero-media-id-<?= $blockIndex ?>"
           name="blocks[<?= $blockIndex ?>][config][media_id]"
           value="<?= h($mediaId) ?>">

    <div class="btn-group mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary hero-pick-media-btn" data-block="<?= $blockIndex ?>">
            <i data-feather="image" style="width:14px;height:14px;"></i> Browse Media
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger hero-clear-media-btn <?= !$previewImage ? 'd-none' : '' ?>" data-block="<?= $blockIndex ?>">
            <i data-feather="x" style="width:14px;height:14px;"></i> Remove
        </button>
    </div>

    <div class="form-group">
        <label for="hero-media-alt-<?= $blockIndex ?>">Image Alt Text</label>
        <input type="text"
               class="form-control"
               id="hero-media-alt-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][media_alt]"
               value="<?= h($mediaAlt) ?>"
               placeholder="Descriptive alt text for the hero image">
    </div>

    <!-- Manual image path (fallback / for existing images) -->
    <details class="mb-3">
        <summary class="text-muted small">Advanced: Manual image paths</summary>
        <div class="mt-2">
            <div class="form-group">
                <label>Desktop Image Path</label>
                <input type="text"
                       class="form-control form-control-sm"
                       name="blocks[<?= $blockIndex ?>][config][image]"
                       value="<?= h($imagePath) ?>"
                       placeholder="/assets/img/hero/your-image.jpg">
                <small class="form-text text-muted">Web-root relative path. Used as fallback if no media selected.</small>
            </div>
            <div class="form-group">
                <label>Tablet Image Path <small class="text-muted">(optional, for background heroes)</small></label>
                <input type="text"
                       class="form-control form-control-sm"
                       name="blocks[<?= $blockIndex ?>][config][image_tablet]"
                       value="<?= h($imageTablet) ?>"
                       placeholder="/assets/img/hero/your-image-1024x768.jpg">
            </div>
            <div class="form-group">
                <label>Mobile Image Path <small class="text-muted">(optional, for background heroes)</small></label>
                <input type="text"
                       class="form-control form-control-sm"
                       name="blocks[<?= $blockIndex ?>][config][image_mobile]"
                       value="<?= h($imageMobile) ?>"
                       placeholder="/assets/img/hero/your-image-768x1024.jpg">
            </div>
        </div>
    </details>

    <div class="form-group">
        <label for="hero-trust-<?= $blockIndex ?>">Trust Line <small class="text-muted">(optional)</small></label>
        <input type="text"
               class="form-control"
               id="hero-trust-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][trust_line]"
               value="<?= h($trustLine) ?>"
               placeholder="e.g. Trusted by 500+ property managers">
    </div>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;

    // Browse media button
    document.querySelector('.hero-pick-media-btn[data-block="' + blockIndex + '"]').addEventListener('click', function() {
        if (typeof openMediaPicker !== 'function') {
            alert('Media picker not loaded. Please reload the page.');
            return;
        }
        openMediaPicker(function(media) {
            // Set hidden media_id
            document.getElementById('hero-media-id-' + blockIndex).value = media.id;

            // Set alt text if empty
            var altInput = document.getElementById('hero-media-alt-' + blockIndex);
            if (!altInput.value && media.alt_text) {
                altInput.value = media.alt_text;
            }

            // Update preview
            var preview = document.getElementById('hero-media-preview-' + blockIndex);
            preview.innerHTML = '<img src="' + media.file_path + '" alt="Selected image" class="mw-media-preview-img">' +
                '<span class="mw-media-preview-name">' + media.file_path.split('/').pop() + '</span>';

            // Show remove button
            document.querySelector('.hero-clear-media-btn[data-block="' + blockIndex + '"]').classList.remove('d-none');
        });
    });

    // Clear media button
    document.querySelector('.hero-clear-media-btn[data-block="' + blockIndex + '"]').addEventListener('click', function() {
        document.getElementById('hero-media-id-' + blockIndex).value = '';
        document.getElementById('hero-media-preview-' + blockIndex).innerHTML = '<div class="mw-media-preview-empty">No image selected</div>';
        this.classList.add('d-none');
    });
})();
</script>
