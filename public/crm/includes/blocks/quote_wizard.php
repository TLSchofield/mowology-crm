<?php
/**
 * Quote Wizard Block Renderer — Multi-step quote request form
 *
 * Config: {
 *   headline       : string
 *   subheadline    : string
 *   cta_phone      : string  (phone shown on success screen)
 *   services       : [{icon, name, price}]  (defaults provided)
 *   submit_url     : string  (POST endpoint, defaults to /crm/api/cms-form-submit.php)
 *   form_id        : string  (submission tag, defaults to 'quote-wizard')
 *   success_msg    : string
 * }
 */

$headline    = $config['headline']    ?? 'Get Your <em>Free</em> Quote';
$subheadline = $config['subheadline'] ?? "Tell us about your property — we'll respond within one business day";
$ctaPhone    = $config['cta_phone']   ?? '(778) 846-9273';
$submitUrl   = $config['submit_url']  ?? '/crm/api/cms-form-submit.php';
$formId      = $config['form_id']     ?? 'quote-wizard';
$successMsg  = $config['success_msg'] ?? "We'll review your property and follow up within one business day.";

$services = $config['services'] ?? [
    ['icon' => '🌿', 'name' => 'Lawn Mowing',        'price' => 'from $45'],
    ['icon' => '✂️', 'name' => 'Hedge Trimming',     'price' => 'from $80'],
    ['icon' => '🍂', 'name' => 'Leaf Cleanup',       'price' => 'from $120'],
    ['icon' => '🌸', 'name' => 'Garden Beds',        'price' => 'from $95'],
    ['icon' => '🏢', 'name' => 'Strata Maintenance', 'price' => 'custom'],
    ['icon' => '❄️', 'name' => 'Winter Services',    'price' => 'from $200'],
];

$uid = 'qwiz-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-qwiz" id="<?= h($uid) ?>">
  <div class="mw-qwiz__inner">
    <div class="mw-qwiz__header">
      <h2><?= $headline /* intentionally allows em tag for italic gold */ ?></h2>
      <p><?= h($subheadline) ?></p>
    </div>

    <div class="mw-qwiz__steps" id="<?= h($uid) ?>-steps">
      <div class="mw-qwiz__step active" data-step="1"><span class="mw-qwiz__step-num">01</span>Service</div>
      <div class="mw-qwiz__step" data-step="2"><span class="mw-qwiz__step-num">02</span>Property</div>
      <div class="mw-qwiz__step" data-step="3"><span class="mw-qwiz__step-num">03</span>Contact</div>
      <div class="mw-qwiz__step" data-step="4"><span class="mw-qwiz__step-num">04</span>Confirm</div>
    </div>

    <div class="mw-qwiz__card">
      <!-- Step 1: Services -->
      <div class="mw-qwiz__panel active" id="<?= h($uid) ?>-p1">
        <p class="mw-qwiz__hint">Select all services you're interested in:</p>
        <div class="mw-qwiz__service-grid">
          <?php foreach ($services as $svc): ?>
          <div class="mw-qwiz__service-opt" data-service="<?= h($svc['name']) ?>">
            <span class="mw-qwiz__so-icon"><?= h($svc['icon']) ?></span>
            <div class="mw-qwiz__so-name"><?= h($svc['name']) ?></div>
            <div class="mw-qwiz__so-price"><?= h($svc['price']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mw-qwiz__nav"><span></span><button class="mw-qwiz__btn mw-qwiz__btn--primary" data-goto="2">Continue →</button></div>
      </div>

      <!-- Step 2: Property -->
      <div class="mw-qwiz__panel" id="<?= h($uid) ?>-p2">
        <div class="mw-qwiz__row">
          <div class="mw-qwiz__field">
            <label>Property Type</label>
            <select name="property_type">
              <option>Residential Home</option>
              <option>Townhouse</option>
              <option>Strata Complex</option>
              <option>Commercial</option>
            </select>
          </div>
          <div class="mw-qwiz__field">
            <label>Property Size</label>
            <select name="property_size">
              <option>Small (&lt;3,000 sq ft)</option>
              <option>Medium (3–6,000 sq ft)</option>
              <option>Large (6–10,000 sq ft)</option>
              <option>XL / Acreage</option>
            </select>
          </div>
        </div>
        <div class="mw-qwiz__row">
          <div class="mw-qwiz__field">
            <label>Street Address</label>
            <input type="text" name="address" placeholder="1234 Main Street">
          </div>
          <div class="mw-qwiz__field">
            <label>City / Area</label>
            <select name="city">
              <option>Vancouver</option>
              <option>Burnaby</option>
              <option>Richmond</option>
              <option>North Vancouver</option>
              <option>West Vancouver</option>
            </select>
          </div>
        </div>
        <div class="mw-qwiz__row mw-qwiz__row--full">
          <div class="mw-qwiz__field">
            <label>How often?</label>
            <select name="frequency">
              <option>Weekly</option>
              <option>Bi-weekly</option>
              <option>Monthly</option>
              <option>One-time</option>
              <option>Seasonal contract</option>
            </select>
          </div>
        </div>
        <div class="mw-qwiz__nav">
          <button class="mw-qwiz__btn mw-qwiz__btn--ghost" data-goto="1">← Back</button>
          <button class="mw-qwiz__btn mw-qwiz__btn--primary" data-goto="3">Continue →</button>
        </div>
      </div>

      <!-- Step 3: Contact -->
      <div class="mw-qwiz__panel" id="<?= h($uid) ?>-p3">
        <div class="mw-qwiz__row">
          <div class="mw-qwiz__field">
            <label>First Name</label>
            <input type="text" name="first_name" placeholder="Alex" required>
          </div>
          <div class="mw-qwiz__field">
            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="Thompson" required>
          </div>
        </div>
        <div class="mw-qwiz__row">
          <div class="mw-qwiz__field">
            <label>Phone</label>
            <input type="tel" name="phone" placeholder="(604) 555-0100" required>
          </div>
          <div class="mw-qwiz__field">
            <label>Email</label>
            <input type="email" name="email" placeholder="alex@email.com" required>
          </div>
        </div>
        <div class="mw-qwiz__row mw-qwiz__row--full">
          <div class="mw-qwiz__field">
            <label>Notes (optional)</label>
            <textarea name="notes" placeholder="Access codes, special requests, property details…"></textarea>
          </div>
        </div>
        <div id="<?= h($uid) ?>-err" class="mw-qwiz__err" style="display:none;"></div>
        <div class="mw-qwiz__nav">
          <button class="mw-qwiz__btn mw-qwiz__btn--ghost" data-goto="2">← Back</button>
          <button class="mw-qwiz__btn mw-qwiz__btn--primary" id="<?= h($uid) ?>-submit">Send Quote Request →</button>
        </div>
      </div>

      <!-- Step 4: Success -->
      <div class="mw-qwiz__panel" id="<?= h($uid) ?>-p4">
        <div class="mw-qwiz__success">
          <span class="mw-qwiz__checkmark">✅</span>
          <h3>Quote Request Sent!</h3>
          <p><?= h($successMsg) ?></p>
          <div class="mw-qwiz__phone-cta">
            <p>📞 Prefer to talk? Call us at <strong><?= h($ctaPhone) ?></strong></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.mw-qwiz{background:linear-gradient(135deg,#0d1f12 0%,#0f2d17 60%,#162a1a 100%);padding:clamp(3rem,6vw,6rem) 1.5rem;position:relative;overflow:hidden}
.mw-qwiz::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 80% 60% at 50% 0%,rgba(45,122,58,.25) 0%,transparent 70%);pointer-events:none}
.mw-qwiz__inner{max-width:780px;margin:0 auto;position:relative}
.mw-qwiz__header{text-align:center;margin-bottom:2.5rem}
.mw-qwiz__header h2{font-family:'Playfair Display',serif;font-size:clamp(2rem,4vw,3rem);color:#fff;line-height:1.15}
.mw-qwiz__header h2 em{color:#c9a84c;font-style:italic}
.mw-qwiz__header p{color:rgba(255,255,255,.6);margin-top:.6rem;font-size:1rem}
.mw-qwiz__steps{display:flex;margin-bottom:2rem;border-radius:12px;overflow:hidden;border:1px solid rgba(201,168,76,.2)}
.mw-qwiz__step{flex:1;padding:.9rem;text-align:center;background:rgba(255,255,255,.04);color:rgba(255,255,255,.4);font-size:.8rem;font-weight:500;letter-spacing:.04em;border-right:1px solid rgba(255,255,255,.07);transition:all .25s}
.mw-qwiz__step:last-child{border-right:none}
.mw-qwiz__step.active{background:rgba(201,168,76,.12);color:#c9a84c}
.mw-qwiz__step.done{background:rgba(45,122,58,.2);color:rgba(255,255,255,.7)}
.mw-qwiz__step-num{display:block;font-family:'DM Mono',monospace;font-size:.65rem;opacity:.6;margin-bottom:.15rem}
.mw-qwiz__card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:2rem;backdrop-filter:blur(10px)}
.mw-qwiz__panel{display:none}
.mw-qwiz__panel.active{display:block}
.mw-qwiz__hint{color:rgba(255,255,255,.5);font-size:.85rem;margin-bottom:1.25rem}
.mw-qwiz__service-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.85rem}
.mw-qwiz__service-opt{border:1.5px solid rgba(255,255,255,.12);border-radius:12px;padding:1.1rem .9rem;cursor:pointer;transition:all .2s;text-align:center;background:rgba(255,255,255,.03);user-select:none}
.mw-qwiz__service-opt:hover,.mw-qwiz__service-opt.selected{border-color:#c9a84c;background:rgba(201,168,76,.1)}
.mw-qwiz__so-icon{font-size:1.8rem;margin-bottom:.5rem;display:block}
.mw-qwiz__so-name{color:#fff;font-size:.82rem;font-weight:600}
.mw-qwiz__so-price{color:#c9a84c;font-size:.72rem;margin-top:.2rem;font-family:'DM Mono',monospace}
.mw-qwiz__row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem}
.mw-qwiz__row--full{grid-template-columns:1fr}
.mw-qwiz__field label{display:block;color:rgba(255,255,255,.6);font-size:.75rem;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.35rem}
.mw-qwiz__field input,.mw-qwiz__field select,.mw-qwiz__field textarea{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);border-radius:8px;color:#fff;font-family:'DM Sans',sans-serif;font-size:.92rem;padding:.7rem .9rem;outline:none;transition:border-color .2s;-webkit-appearance:none}
.mw-qwiz__field input:focus,.mw-qwiz__field select:focus,.mw-qwiz__field textarea:focus{border-color:#c9a84c}
.mw-qwiz__field select option{background:#0d1f12}
.mw-qwiz__field textarea{resize:vertical;min-height:90px}
.mw-qwiz__nav{display:flex;justify-content:space-between;align-items:center;margin-top:1.75rem}
.mw-qwiz__btn{padding:.8rem 1.75rem;border-radius:8px;border:none;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .2s;letter-spacing:.03em}
.mw-qwiz__btn--primary{background:#c9a84c;color:#0d1f12}
.mw-qwiz__btn--primary:hover{background:#e8c97a;transform:translateY(-1px)}
.mw-qwiz__btn--primary:disabled{opacity:.6;cursor:not-allowed;transform:none}
.mw-qwiz__btn--ghost{background:transparent;color:rgba(255,255,255,.5);border:1px solid rgba(255,255,255,.2)}
.mw-qwiz__err{background:rgba(220,53,69,.15);border:1px solid rgba(220,53,69,.4);color:#f8d7da;border-radius:8px;padding:.75rem 1rem;font-size:.85rem;margin-bottom:1rem}
.mw-qwiz__success{text-align:center;padding:.5rem 0}
.mw-qwiz__checkmark{font-size:3.5rem;display:block;margin-bottom:.75rem;animation:mwQwizPop .5s cubic-bezier(.34,1.56,.64,1)}
.mw-qwiz__success h3{font-family:'Playfair Display',serif;color:#fff;font-size:1.7rem;margin-bottom:.4rem}
.mw-qwiz__success > p{color:rgba(255,255,255,.55);margin-top:.4rem}
.mw-qwiz__phone-cta{margin-top:1.75rem;padding:1.25rem 1.5rem;background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.2);border-radius:12px}
.mw-qwiz__phone-cta p{color:#c9a84c;font-size:.88rem}
@keyframes mwQwizPop{from{transform:scale(0);opacity:0}to{transform:scale(1);opacity:1}}
@media(max-width:640px){.mw-qwiz__row{grid-template-columns:1fr}.mw-qwiz__service-grid{grid-template-columns:repeat(2,1fr)}.mw-qwiz__card{padding:1.5rem 1.25rem}}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var submitUrl = <?= json_encode($submitUrl) ?>;
  var formId = <?= json_encode($formId) ?>;
  var section = document.getElementById(uid);
  if (!section) return;

  // Step navigation
  section.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-goto]');
    if (btn) {
      var n = parseInt(btn.getAttribute('data-goto'));
      goStep(n);
    }
    // Service toggle
    var opt = e.target.closest('.mw-qwiz__service-opt');
    if (opt) opt.classList.toggle('selected');
  });

  function goStep(n) {
    section.querySelectorAll('.mw-qwiz__panel').forEach(function(p, i) {
      p.classList.toggle('active', i === n - 1);
    });
    section.querySelectorAll('.mw-qwiz__step').forEach(function(s, i) {
      s.classList.remove('active', 'done');
      if (i === n - 1) s.classList.add('active');
      else if (i < n - 1) s.classList.add('done');
    });
  }

  // Submit handler
  var submitBtn = document.getElementById(uid + '-submit');
  if (!submitBtn) return;

  submitBtn.addEventListener('click', function() {
    var panel = document.getElementById(uid + '-p3');
    var errEl = document.getElementById(uid + '-err');
    errEl.style.display = 'none';

    // Validate contact fields
    var firstName = panel.querySelector('[name="first_name"]').value.trim();
    var lastName  = panel.querySelector('[name="last_name"]').value.trim();
    var phone     = panel.querySelector('[name="phone"]').value.trim();
    var email     = panel.querySelector('[name="email"]').value.trim();
    var notes     = panel.querySelector('[name="notes"]').value.trim();

    if (!firstName || !lastName || !email || !phone) {
      errEl.textContent = 'Please fill in your name, phone, and email.';
      errEl.style.display = 'block';
      return;
    }

    // Collect services from step 1
    var services = [];
    section.querySelectorAll('.mw-qwiz__service-opt.selected').forEach(function(o) {
      services.push(o.getAttribute('data-service'));
    });

    // Collect property from step 2
    var p2 = document.getElementById(uid + '-p2');
    var propType  = p2.querySelector('[name="property_type"]').value;
    var propSize  = p2.querySelector('[name="property_size"]').value;
    var address   = p2.querySelector('[name="address"]').value.trim();
    var city      = p2.querySelector('[name="city"]').value;
    var frequency = p2.querySelector('[name="frequency"]').value;

    // Build message summary
    var msg = 'Services: ' + (services.length ? services.join(', ') : 'None selected') + '\n'
            + 'Property: ' + propType + ', ' + propSize + '\n'
            + 'Address: ' + (address || 'Not provided') + ', ' + city + '\n'
            + 'Frequency: ' + frequency;
    if (notes) msg += '\nNotes: ' + notes;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';

    var fd = new FormData();
    fd.append('form_id', formId);
    fd.append('name', firstName + ' ' + lastName);
    fd.append('email', email);
    fd.append('phone', phone);
    fd.append('message', msg);
    fd.append('source_url', window.location.href);

    fetch(submitUrl, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          goStep(4);
        } else {
          errEl.textContent = data.error || 'Something went wrong. Please try again.';
          errEl.style.display = 'block';
          submitBtn.disabled = false;
          submitBtn.textContent = 'Send Quote Request →';
        }
      })
      .catch(function() {
        errEl.textContent = 'Network error. Please check your connection and try again.';
        errEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Quote Request →';
      });
  });
})();
</script>
