<?php
/**
 * Block Form: Custom HTML
 * Config: {html_content}
 * WARNING: Output is NOT escaped — admin use only.
 *
 * @var array $config
 * @var int   $blockIndex
 */
$htmlContent = $config['html_content'] ?? '';
?>
<div class="alert alert-warning py-2 mb-3">
    <strong><i data-feather="alert-triangle" style="width:14px;height:14px;vertical-align:-2px;"></i> Admin Only Block</strong> —
    Content is rendered as raw HTML. Only use this for trusted markup that cannot be expressed with other block types.
</div>
<div class="form-group mb-0">
    <label>HTML Content</label>
    <textarea class="form-control" rows="10"
              name="blocks[<?php echo $blockIndex; ?>][config][html_content]"
              placeholder="Enter raw HTML here. Avoid inline styles — use CSS classes from the public design system instead."
              style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($htmlContent, ENT_QUOTES, 'UTF-8'); ?></textarea>
    <small class="form-text text-muted">
        Wrap content in semantic elements. Feather icons (<code>data-feather=""</code>) will NOT auto-initialize here —
        use SVG or emoji instead.
    </small>
</div>
