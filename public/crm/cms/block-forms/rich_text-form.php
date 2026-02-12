<?php
/**
 * Rich Text Block Form
 *
 * Config fields: title, content (raw HTML textarea)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title   = $config['title'] ?? '';
$content = $config['content'] ?? '';
?>

<div class="rich-text-block-form">
    <div class="form-group">
        <label for="rt-title-<?= $blockIndex ?>">Section Title <small class="text-muted">(optional)</small></label>
        <input type="text"
               class="form-control"
               id="rt-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="Optional heading above the text block">
    </div>

    <div class="form-group">
        <label for="rt-content-<?= $blockIndex ?>">Content</label>
        <div class="alert alert-warning py-2 px-3 mb-2" role="alert">
            <small><strong>Note:</strong> This field accepts raw HTML. Use valid markup and avoid pasting from word processors, which may include broken tags. Basic tags: <code>&lt;p&gt;</code>, <code>&lt;h2&gt;</code>-<code>&lt;h4&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;ol&gt;</code>, <code>&lt;a&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>.</small>
        </div>
        <textarea class="form-control"
                  id="rt-content-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][content]"
                  rows="12"
                  style="font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.85rem;"
                  placeholder="<p>Your content here...</p>"><?= h($content) ?></textarea>
        <small class="form-text text-muted">Content is rendered as-is on the public page. A WYSIWYG editor will replace this field in a future update.</small>
    </div>
</div>
