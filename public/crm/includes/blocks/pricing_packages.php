<?php
/**
 * Pricing Packages Block Renderer — Monthly/Annual toggle, tiered plans
 *
 * Config: {
 *   title    : string
 *   subtitle : string
 *   plans    : [{name, tagline, price_monthly, price_annual, annual_save_note, features[],
 *               not_included[], featured, cta_text, cta_url, is_custom}]
 * }
 */

$title    = $config['title']    ?? 'Simple, <em>Transparent</em> Pricing';
$subtitle = $config['subtitle'] ?? 'No surprise invoices. No hidden fees. Just great lawns.';

$plans = $config['plans'] ?? [
    [
        'name'             => 'Essential',
        'tagline'          => 'For smaller residential lawns',
        'price_monthly'    => 149,
        'price_annual'     => 127,
        'annual_save_note' => 'Billed as $1,519/year (save $237)',
        'features'         => ['Bi-weekly lawn mowing','Edging & blowing included','Spring & fall cleanup (1 each)','Online scheduling portal'],
        'not_included'     => ['Hedge trimming','Fertilization program'],
        'featured'         => false,
        'cta_text'         => 'Get Started',
        'cta_url'          => '/quote?service=lawn-care&src=pricing',
        'is_custom'        => false,
    ],
    [
        'name'             => 'Premium',
        'tagline'          => 'Full-service residential care',
        'price_monthly'    => 299,
        'price_annual'     => 254,
        'annual_save_note' => 'Billed as $3,049/year (save $539)',
        'features'         => ['Weekly or bi-weekly mowing','Edging, blowing & trimming','4× hedge trimming/year','Spring & fall aeration + seeding','4-step fertilization program','Spring & fall deep cleanup'],
        'not_included'     => [],
        'featured'         => true,
        'cta_text'         => 'Get Started',
        'cta_url'          => '/quote?service=lawn-care&plan=premium&src=pricing',
        'is_custom'        => false,
    ],
    [
        'name'             => 'Strata',
        'tagline'          => 'Commercial & multi-family',
        'price_custom'     => 'Custom',
        'price_sub'        => 'Starting from $800/mo',
        'features'         => ['Full grounds maintenance','Dedicated crew assigned','Monthly reporting & photos','Council liaison available','Seasonal planting programs','Priority emergency response'],
        'not_included'     => [],
        'featured'         => false,
        'cta_text'         => 'Contact for Quote',
        'cta_url'          => '/contact?src=pricing-strata',
        'is_custom'        => true,
    ],
];

$uid = 'pric-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-pric" id="<?= h($uid) ?>">
  <div class="mw-pric__inner">
    <div class="mw-pric__title">
      <h2><?= $title ?></h2>
      <p><?= h($subtitle) ?></p>
    </div>

    <div class="mw-pric__toggle">
      <span class="mw-pric__lbl active" id="<?= h($uid) ?>-lbl-m">Monthly</span>
      <button class="mw-pric__switch" id="<?= h($uid) ?>-switch" aria-label="Toggle annual billing">
        <div class="mw-pric__knob"></div>
      </button>
      <span class="mw-pric__lbl" id="<?= h($uid) ?>-lbl-a">Annual</span>
      <span class="mw-pric__save">SAVE 15%</span>
    </div>

    <div class="mw-pric__grid">
      <?php foreach ($plans as $pi => $plan): ?>
      <div class="mw-pric__card mw-reveal<?= !empty($plan['featured']) ? ' mw-pric__card--featured' : '' ?>">
        <div class="mw-pric__plan-name"><?= h($plan['name']) ?></div>
        <div class="mw-pric__plan-tag"><?= h($plan['tagline'] ?? '') ?></div>

        <?php if (!empty($plan['is_custom'])): ?>
        <div class="mw-pric__price">
          <span class="mw-pric__amount mw-pric__amount--custom"><?= h($plan['price_custom'] ?? 'Custom') ?></span>
          <?php if (!empty($plan['price_sub'])): ?>
          <div class="mw-pric__price-sub"><?= h($plan['price_sub']) ?></div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="mw-pric__price" id="<?= h($uid) ?>-p<?= $pi ?>">
          <span class="mw-pric__amount">$<?= (int)($plan['price_monthly'] ?? 0) ?></span><span class="mw-pric__per">/mo</span>
          <div class="mw-pric__annual-note"><?= h($plan['annual_save_note'] ?? '') ?></div>
        </div>
        <?php endif; ?>

        <ul class="mw-pric__features">
          <?php foreach ($plan['features'] as $f): ?>
          <li><span class="mw-pric__fi mw-pric__fi--yes">✓</span><?= h($f) ?></li>
          <?php endforeach; ?>
          <?php foreach ($plan['not_included'] ?? [] as $f): ?>
          <li class="mw-pric__off"><span class="mw-pric__fi">✕</span><?= h($f) ?></li>
          <?php endforeach; ?>
        </ul>

        <a href="<?= h($plan['cta_url'] ?? '/quote') ?>"
           class="mw-pric__btn <?= !empty($plan['featured']) ? 'mw-pric__btn--gold' : 'mw-pric__btn--outline' ?>">
          <?= h($plan['cta_text'] ?? 'Get Started') ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.mw-pric{background:#0d1f12;padding:clamp(3rem,6vw,6rem) 1.5rem;position:relative;overflow:hidden}
.mw-pric::before{content:'';position:absolute;inset:0;background:radial-gradient(circle 600px at 0% 100%,rgba(45,122,58,.2) 0%,transparent 70%),radial-gradient(circle 500px at 100% 0%,rgba(201,168,76,.08) 0%,transparent 70%);pointer-events:none}
.mw-pric__inner{max-width:1100px;margin:0 auto;position:relative}
.mw-pric__title{text-align:center;margin-bottom:2.5rem}
.mw-pric__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#fff}
.mw-pric__title h2 em{color:#c9a84c;font-style:italic}
.mw-pric__title p{color:rgba(255,255,255,.55);margin-top:.5rem}
.mw-pric__toggle{display:flex;align-items:center;justify-content:center;gap:.9rem;margin-bottom:2.5rem}
.mw-pric__lbl{color:rgba(255,255,255,.55);font-size:.9rem;font-weight:500;transition:color .2s}
.mw-pric__lbl.active{color:#fff}
.mw-pric__switch{width:52px;height:28px;background:rgba(255,255,255,.15);border-radius:14px;cursor:pointer;position:relative;border:none;transition:background .3s;flex-shrink:0}
.mw-pric__switch.on{background:#c9a84c}
.mw-pric__knob{position:absolute;top:3px;left:3px;width:22px;height:22px;background:#fff;border-radius:50%;transition:transform .3s;box-shadow:0 1px 4px rgba(0,0,0,.3)}
.mw-pric__switch.on .mw-pric__knob{transform:translateX(24px)}
.mw-pric__save{background:#c9a84c;color:#0d1f12;font-size:.7rem;font-weight:700;padding:.2rem .55rem;border-radius:4px;letter-spacing:.05em}
.mw-pric__grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem}
.mw-pric__card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:2.25rem;position:relative;transition:all .3s;overflow:hidden}
.mw-pric__card:hover{transform:translateY(-5px);border-color:rgba(255,255,255,.2)}
.mw-pric__card--featured{background:rgba(201,168,76,.08);border-color:#c9a84c}
.mw-pric__card--featured::before{content:'Most Popular';position:absolute;top:0;right:0;background:#c9a84c;color:#0d1f12;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.38rem 1rem;border-radius:0 20px 0 12px}
.mw-pric__plan-name{font-family:'Playfair Display',serif;font-size:1.4rem;color:#fff;margin-bottom:.3rem}
.mw-pric__plan-tag{color:rgba(255,255,255,.5);font-size:.83rem;margin-bottom:1.75rem}
.mw-pric__price{margin-bottom:1.75rem}
.mw-pric__amount{font-size:2.9rem;font-weight:700;color:#fff;line-height:1;font-family:'DM Mono',monospace}
.mw-pric__amount--custom{font-size:2rem;line-height:1.3}
.mw-pric__per{color:rgba(255,255,255,.5);font-size:.83rem;margin-left:.25rem}
.mw-pric__annual-note{color:#c9a84c;font-size:.76rem;margin-top:.3rem;display:none}
.mw-pric__price.annual .mw-pric__annual-note{display:block}
.mw-pric__price-sub{color:rgba(255,255,255,.4);font-size:.78rem;margin-top:.3rem}
.mw-pric__features{list-style:none;display:flex;flex-direction:column;gap:.65rem;margin-bottom:1.75rem}
.mw-pric__features li{display:flex;align-items:flex-start;gap:.6rem;font-size:.86rem;color:rgba(255,255,255,.75)}
.mw-pric__fi{color:#c9a84c;font-size:.78rem;flex-shrink:0;margin-top:2px}
.mw-pric__off{opacity:.35}
.mw-pric__btn{display:block;width:100%;padding:.85rem;border-radius:10px;border:none;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none}
.mw-pric__btn--outline{background:transparent;border:1.5px solid rgba(255,255,255,.25);color:rgba(255,255,255,.8)}
.mw-pric__btn--outline:hover{border-color:rgba(255,255,255,.6);color:#fff}
.mw-pric__btn--gold{background:#c9a84c;color:#0d1f12}
.mw-pric__btn--gold:hover{background:#e8c97a}
@media(max-width:900px){.mw-pric__grid{grid-template-columns:1fr;max-width:420px;margin:0 auto}}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var section = document.getElementById(uid);
  if (!section) return;

  // Build price map from PHP data
  var priceData = {};
  <?php foreach ($plans as $pi => $plan): if (empty($plan['is_custom'])): ?>
  priceData[<?= $pi ?>] = {
    monthly: <?= (int)($plan['price_monthly'] ?? 0) ?>,
    annual: <?= (int)($plan['price_annual'] ?? 0) ?>,
    annualNote: <?= json_encode($plan['annual_save_note'] ?? '') ?>
  };
  <?php endif; endforeach; ?>

  var isAnnual = false;
  var switchBtn = document.getElementById(uid + '-switch');
  var lblM = document.getElementById(uid + '-lbl-m');
  var lblA = document.getElementById(uid + '-lbl-a');

  if (!switchBtn) return;

  switchBtn.addEventListener('click', function() {
    isAnnual = !isAnnual;
    switchBtn.classList.toggle('on', isAnnual);
    lblM.classList.toggle('active', !isAnnual);
    lblA.classList.toggle('active', isAnnual);

    Object.keys(priceData).forEach(function(pi) {
      var priceEl = document.getElementById(uid + '-p' + pi);
      if (!priceEl) return;
      var d = priceData[pi];
      priceEl.querySelector('.mw-pric__amount').textContent = '$' + (isAnnual ? d.annual : d.monthly);
      priceEl.classList.toggle('annual', isAnnual);
    });
  });

  // Reveal animation
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) {
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });
  section.querySelectorAll('.mw-reveal').forEach(function(el) {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity .5s ease, transform .5s ease';
    obs.observe(el);
  });
})();
</script>
