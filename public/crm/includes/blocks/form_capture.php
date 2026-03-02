<?php
/**
 * Form Capture Block Renderer — P3-D
 *
 * Embeds a contact/lead form on any CMS page.
 * Submissions POST to /crm/api/cms-form-submit.php and are stored in cms_form_submissions.
 *
 * Config: {
 *   headline      : string  (form heading)
 *   subheadline   : string  (optional — shown under heading)
 *   fields        : array   — which optional fields to show: name, email, phone, message (all shown by default)
 *   button_text   : string  (submit button label)
 *   success_msg   : string  (shown after submit)
 *   form_id       : string  (optional slug to tag submissions, defaults to page slug)
 * }
 */

$headline    = $config['headline']    ?? 'Get in Touch';
$subheadline = $config['subheadline'] ?? '';
$fields      = $config['fields']      ?? ['name', 'email', 'phone', 'message'];
$buttonText  = $config['button_text'] ?? 'Send Message';
$successMsg  = $config['success_msg'] ?? 'Thanks! We\'ll be in touch within 1 business day.';
$formId      = $config['form_id']     ?? 'cms-contact';
$uniqueId    = 'fc-' . substr(md5(uniqid('', true)), 0, 8);

// Ensure $fields is a flat list of strings
if (!is_array($fields)) {
    $fields = ['name', 'email', 'phone', 'message'];
}
$show = array_flip($fields);
?>
<section class="section-pad bg-light">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <?php if ($headline): ?>
          <h2 class="section-title text-center"><?php echo h($headline); ?></h2>
        <?php endif; ?>
        <?php if ($subheadline): ?>
          <p class="section-intro text-center"><?php echo h($subheadline); ?></p>
        <?php endif; ?>

        <div id="<?php echo h($uniqueId); ?>-wrap">
          <form id="<?php echo h($uniqueId); ?>" class="fc-form" novalidate>
              <input type="hidden" name="form_id"   value="<?php echo h($formId); ?>">
              <input type="hidden" name="source_url" value=""><!-- filled by JS -->

              <?php if (isset($show['name'])): ?>
              <div class="form-group">
                  <label for="<?php echo h($uniqueId); ?>-name">Your Name *</label>
                  <input type="text" class="form-control" id="<?php echo h($uniqueId); ?>-name"
                         name="name" required placeholder="First &amp; Last Name">
              </div>
              <?php endif; ?>

              <?php if (isset($show['email'])): ?>
              <div class="form-group">
                  <label for="<?php echo h($uniqueId); ?>-email">Email Address *</label>
                  <input type="email" class="form-control" id="<?php echo h($uniqueId); ?>-email"
                         name="email" required placeholder="you@example.com">
              </div>
              <?php endif; ?>

              <?php if (isset($show['phone'])): ?>
              <div class="form-group">
                  <label for="<?php echo h($uniqueId); ?>-phone">Phone Number</label>
                  <input type="tel" class="form-control" id="<?php echo h($uniqueId); ?>-phone"
                         name="phone" placeholder="604-555-1234">
              </div>
              <?php endif; ?>

              <?php if (isset($show['message'])): ?>
              <div class="form-group">
                  <label for="<?php echo h($uniqueId); ?>-message">Message</label>
                  <textarea class="form-control" id="<?php echo h($uniqueId); ?>-message"
                            name="message" rows="4" placeholder="Tell us about your project…"></textarea>
              </div>
              <?php endif; ?>

              <!-- Honeypot anti-spam -->
              <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">

              <div id="<?php echo h($uniqueId); ?>-err" class="alert alert-danger py-2 mb-3" style="display:none;"></div>

              <button type="submit" class="btn btn-primary-large w-100">
                  <?php echo h($buttonText); ?>
              </button>
          </form>
          <div id="<?php echo h($uniqueId); ?>-success" class="alert alert-success text-center py-4" style="display:none;">
              <i style="font-size:2rem;">✓</i><br>
              <strong><?php echo h($successMsg); ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<script>
(function() {
    var uid = <?php echo json_encode($uniqueId); ?>;
    var form = document.getElementById(uid);
    if (!form) return;

    // Fill source URL
    var srcInput = form.querySelector('[name="source_url"]');
    if (srcInput) srcInput.value = window.location.href;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var errEl     = document.getElementById(uid + '-err');
        var successEl = document.getElementById(uid + '-success');
        var btn       = form.querySelector('button[type="submit"]');

        errEl.style.display     = 'none';
        successEl.style.display = 'none';

        // Basic validation
        var required = form.querySelectorAll('[required]');
        var valid = true;
        required.forEach(function(f) { if (!f.value.trim()) valid = false; });
        if (!valid) {
            errEl.textContent = 'Please fill in all required fields.';
            errEl.style.display = 'block';
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Sending…';

        var fd = new FormData(form);
        fetch('/crm/api/cms-form-submit.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    form.style.display         = 'none';
                    successEl.style.display    = 'block';
                } else {
                    errEl.textContent   = data.error || 'Something went wrong. Please try again.';
                    errEl.style.display = 'block';
                    btn.disabled        = false;
                    btn.textContent     = <?php echo json_encode($buttonText); ?>;
                }
            })
            .catch(function() {
                errEl.textContent   = 'Network error. Please check your connection and try again.';
                errEl.style.display = 'block';
                btn.disabled        = false;
                btn.textContent     = <?php echo json_encode($buttonText); ?>;
            });
    });
})();
</script>
