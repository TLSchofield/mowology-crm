<?php
/**
 * Block Form: Form Capture — P3-D
 * Config: {headline, subheadline, fields[], button_text, success_msg, form_id}
 *
 * @var array $config
 * @var int   $blockIndex
 */
$headline    = $config['headline']    ?? 'Get in Touch';
$subheadline = $config['subheadline'] ?? '';
$fields      = is_array($config['fields'] ?? null) ? $config['fields'] : ['name', 'email', 'phone', 'message'];
$buttonText  = $config['button_text'] ?? 'Send Message';
$successMsg  = $config['success_msg'] ?? "Thanks! We'll be in touch within 1 business day.";
$formId      = $config['form_id']     ?? '';
$allFields   = ['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'message' => 'Message'];
$activeFields = array_flip($fields);
?>
<div class="form-group">
    <label>Form Heading</label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][headline]"
           value="<?php echo h($headline); ?>"
           placeholder="e.g. Get in Touch">
</div>
<div class="form-group">
    <label>Subheadline <small class="text-muted">(optional)</small></label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][subheadline]"
           value="<?php echo h($subheadline); ?>"
           placeholder="e.g. We typically respond within 1 business day.">
</div>
<div class="form-group">
    <label>Fields to Show</label>
    <div class="d-flex gap-3">
        <?php foreach ($allFields as $key => $label): ?>
        <div class="form-check form-check-inline mr-3">
            <input type="checkbox" class="form-check-input"
                   id="fc_field_<?php echo $blockIndex; ?>_<?php echo $key; ?>"
                   name="blocks[<?php echo $blockIndex; ?>][config][fields][]"
                   value="<?php echo $key; ?>"
                   <?php echo isset($activeFields[$key]) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="fc_field_<?php echo $blockIndex; ?>_<?php echo $key; ?>"><?php echo $label; ?></label>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="form-row">
    <div class="col-md-6">
        <div class="form-group">
            <label>Submit Button Text</label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][button_text]"
                   value="<?php echo h($buttonText); ?>"
                   placeholder="e.g. Send Message">
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label>Form ID / Tag <small class="text-muted">(for tracking submissions)</small></label>
            <input type="text" class="form-control"
                   name="blocks[<?php echo $blockIndex; ?>][config][form_id]"
                   value="<?php echo h($formId); ?>"
                   placeholder="e.g. lawn-care-landing">
        </div>
    </div>
</div>
<div class="form-group mb-0">
    <label>Success Message</label>
    <input type="text" class="form-control"
           name="blocks[<?php echo $blockIndex; ?>][config][success_msg]"
           value="<?php echo h($successMsg); ?>"
           placeholder="e.g. Thanks! We'll be in touch within 1 business day.">
</div>
