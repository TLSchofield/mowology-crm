<?php
/**
 * Block Form: Columns (Two-Column Layout) — P3-J
 * Config: {gap, valign, reverse, left{heading,body,button_text,button_url,media_id,img_url}, right{...}}
 *
 * @var array $config
 * @var int   $blockIndex
 */
$gap     = $config['gap']     ?? 'balanced';
$valign  = $config['valign']  ?? 'center';
$reverse = !empty($config['reverse']);
$left    = $config['left']    ?? [];
$right   = $config['right']   ?? [];
?>
<div class="form-row mb-3">
    <div class="col-md-4">
        <div class="form-group mb-0">
            <label class="small">Column Widths</label>
            <select class="form-control form-control-sm" name="blocks[<?php echo $blockIndex; ?>][config][gap]">
                <option value="balanced"  <?php echo $gap === 'balanced'  ? 'selected' : ''; ?>>50 / 50</option>
                <option value="wide-left" <?php echo $gap === 'wide-left' ? 'selected' : ''; ?>>60 / 40 (left wider)</option>
                <option value="wide-right"<?php echo $gap === 'wide-right'? 'selected' : ''; ?>>40 / 60 (right wider)</option>
            </select>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group mb-0">
            <label class="small">Vertical Align</label>
            <select class="form-control form-control-sm" name="blocks[<?php echo $blockIndex; ?>][config][valign]">
                <option value="center" <?php echo $valign === 'center' ? 'selected' : ''; ?>>Center</option>
                <option value="top"    <?php echo $valign === 'top'    ? 'selected' : ''; ?>>Top</option>
            </select>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group mb-0">
            <label class="small">&nbsp;</label>
            <div class="form-check mt-2">
                <input type="checkbox" class="form-check-input" id="colsReverse_<?php echo $blockIndex; ?>"
                       name="blocks[<?php echo $blockIndex; ?>][config][reverse]" value="1"
                       <?php echo $reverse ? 'checked' : ''; ?>>
                <label class="form-check-label" for="colsReverse_<?php echo $blockIndex; ?>">Reverse on desktop</label>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <?php foreach (['left' => 'Left Column', 'right' => 'Right Column'] as $side => $label):
        $col = $$side; // $left or $right
    ?>
    <div class="col-md-6">
        <div class="card mb-0 h-100">
            <div class="card-header py-2 bg-light"><strong><?php echo $label; ?></strong></div>
            <div class="card-body p-3">
                <div class="form-group mb-2">
                    <label class="small">Image — Media ID</label>
                    <input type="number" class="form-control form-control-sm"
                           name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][media_id]"
                           value="<?php echo (int)($col['media_id'] ?? 0) ?: ''; ?>"
                           placeholder="Media Asset ID" min="1">
                </div>
                <div class="form-group mb-2">
                    <label class="small">— OR — Image URL <small class="text-muted">(external)</small></label>
                    <input type="text" class="form-control form-control-sm"
                           name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][img_url]"
                           value="<?php echo h($col['img_url'] ?? ''); ?>"
                           placeholder="https://... or /assets/img/...">
                </div>
                <div class="form-group mb-2">
                    <label class="small">Heading</label>
                    <input type="text" class="form-control form-control-sm"
                           name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][heading]"
                           value="<?php echo h($col['heading'] ?? ''); ?>"
                           placeholder="Column heading">
                </div>
                <div class="form-group mb-2">
                    <label class="small">Body Text <small class="text-muted">(basic HTML ok: &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;p&gt;, &lt;ul&gt;&lt;li&gt;)</small></label>
                    <textarea class="form-control form-control-sm" rows="4"
                              name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][body]"
                              placeholder="Column body text..."><?php echo h($col['body'] ?? ''); ?></textarea>
                </div>
                <div class="form-row">
                    <div class="col-6">
                        <div class="form-group mb-0">
                            <label class="small">Button Text</label>
                            <input type="text" class="form-control form-control-sm"
                                   name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][button_text]"
                                   value="<?php echo h($col['button_text'] ?? ''); ?>"
                                   placeholder="e.g. Learn More">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group mb-0">
                            <label class="small">Button URL</label>
                            <input type="text" class="form-control form-control-sm"
                                   name="blocks[<?php echo $blockIndex; ?>][config][<?php echo $side; ?>][button_url]"
                                   value="<?php echo h($col['button_url'] ?? ''); ?>"
                                   placeholder="/page-slug">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
