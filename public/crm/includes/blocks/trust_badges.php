<?php
/**
 * Trust Badges + Guarantee Block Renderer
 *
 * 4-stat grid + optional guarantee banner below.
 *
 * Config: {
 *   title            : string
 *   badges           : [{icon, number, heading, description}]
 *   show_guarantee   : bool
 *   guarantee_heading: string
 *   guarantee_text   : string
 * }
 */

$title  = $config['title'] ?? 'Why <em>Metro Vancouver</em> Trusts Us';

$badges = $config['badges'] ?? [
    ['icon' => '🏆', 'number' => '11+',  'heading' => 'Years in Business',      'description' => 'Serving Metro Vancouver since 2013 with the same team values'],
    ['icon' => '🏠', 'number' => '600+', 'heading' => 'Properties Maintained',  'description' => 'From Kitsilano to Richmond, Burnaby to North Shore'],
    ['icon' => '⭐', 'number' => '4.9★', 'heading' => 'Average Rating',         'description' => 'Across 200+ verified Google & HomeStars reviews'],
    ['icon' => '🛡️', 'number' => '$5M',  'heading' => 'Liability Insurance',    'description' => 'Fully insured, WCB covered, and bonded on every job'],
];

$showGuarantee    = $config['show_guarantee']    ?? true;
$guaranteeHeading = $config['guarantee_heading'] ?? 'Our Mowology Guarantee';
$guaranteeText    = $config['guarantee_text']    ?? "Not satisfied with your visit? Call or text us within 24 hours and we'll come back to make it right — at no extra charge. We stand behind every job, every time. That's the Mowology standard.";

$uid = 'trbdg-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-trbdg" id="<?= h($uid) ?>">
  <div class="mw-trbdg__inner">
    <div class="mw-trbdg__title">
      <h2><?= $title ?></h2>
    </div>

    <div class="mw-trbdg__grid">
      <?php foreach ($badges as $badge): ?>
      <div class="mw-trbdg__item mw-reveal">
        <div class="mw-trbdg__icon-wrap"><?= h($badge['icon']) ?></div>
        <span class="mw-trbdg__number"><?= h($badge['number']) ?></span>
        <h3><?= h($badge['heading']) ?></h3>
        <p><?= h($badge['description']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($showGuarantee): ?>
    <div class="mw-trbdg__guarantee">
      <div class="mw-trbdg__seal">
        <div class="mw-trbdg__seal-icon">✅</div>
        <div class="mw-trbdg__seal-text">Satisfaction<br>Guaranteed</div>
      </div>
      <div class="mw-trbdg__guarantee-content">
        <h3><?= h($guaranteeHeading) ?></h3>
        <p><?= h($guaranteeText) ?></p>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<style>
.mw-trbdg{background:#fff;padding:clamp(3rem,5vw,5rem) 1.5rem;border-top:1px solid rgba(45,122,58,.12);border-bottom:1px solid rgba(45,122,58,.12)}
.mw-trbdg__inner{max-width:1100px;margin:0 auto}
.mw-trbdg__title{text-align:center;margin-bottom:3rem}
.mw-trbdg__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#1c2b1e}
.mw-trbdg__title h2 em{color:#2d7a3a;font-style:italic}
.mw-trbdg__grid{display:grid;grid-template-columns:repeat(4,1fr);gap:2.5rem;margin-bottom:3rem}
.mw-trbdg__item{text-align:center}
.mw-trbdg__icon-wrap{width:78px;height:78px;border-radius:20px;margin:0 auto 1.1rem;background:linear-gradient(135deg,rgba(45,122,58,.1),rgba(45,122,58,.05));border:1px solid rgba(45,122,58,.15);display:flex;align-items:center;justify-content:center;font-size:2rem;transition:all .3s}
.mw-trbdg__item:hover .mw-trbdg__icon-wrap{transform:translateY(-4px);background:linear-gradient(135deg,rgba(45,122,58,.18),rgba(45,122,58,.1));border-color:#2d7a3a}
.mw-trbdg__number{font-family:'DM Mono',monospace;font-size:1.55rem;font-weight:500;color:#2d7a3a;display:block;margin-bottom:.2rem}
.mw-trbdg__item h3{font-weight:700;font-size:.97rem;color:#1c2b1e;margin-bottom:.35rem}
.mw-trbdg__item p{color:#4a6350;font-size:.82rem;line-height:1.5}
.mw-trbdg__guarantee{background:linear-gradient(135deg,#2d7a3a,#1a5227);border-radius:20px;padding:2.25rem 2.75rem;display:flex;align-items:center;gap:2rem}
.mw-trbdg__seal{width:88px;height:88px;border-radius:50%;flex-shrink:0;border:3px solid rgba(201,168,76,.4);background:rgba(0,0,0,.15);display:flex;align-items:center;justify-content:center;flex-direction:column;text-align:center}
.mw-trbdg__seal-icon{font-size:1.8rem;line-height:1}
.mw-trbdg__seal-text{font-size:.48rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;line-height:1.25;margin-top:.2rem;color:#c9a84c}
.mw-trbdg__guarantee-content h3{font-family:'Playfair Display',serif;color:#fff;font-size:1.35rem;margin-bottom:.35rem}
.mw-trbdg__guarantee-content p{color:rgba(255,255,255,.75);font-size:.88rem;line-height:1.65}
@media(max-width:768px){.mw-trbdg__grid{grid-template-columns:repeat(2,1fr)}.mw-trbdg__guarantee{flex-direction:column;text-align:center;padding:2rem 1.5rem}.mw-trbdg__seal{margin:0 auto}}
@media(max-width:480px){.mw-trbdg__grid{grid-template-columns:1fr}}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var section = document.getElementById(uid);
  if (!section) return;
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) {
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });
  section.querySelectorAll('.mw-reveal').forEach(function(el, i) {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity .5s ease ' + (i * 0.1) + 's, transform .5s ease ' + (i * 0.1) + 's';
    obs.observe(el);
  });
})();
</script>
