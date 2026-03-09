<?php
/**
 * Crew Showcase Block Renderer — Team member cards with avatar, bio, and stats
 *
 * Config: {
 *   title    : string
 *   subtitle : string
 *   members  : [{avatar, name, role, bio, stat1_val, stat1_label, stat2_val, stat2_label}]
 * }
 */

$title    = $config['title']    ?? 'Meet the <em>Crew</em>';
$subtitle = $config['subtitle'] ?? 'The people who show up — week after week, rain or shine';

$members = $config['members'] ?? [
    [
        'avatar'      => '👨‍🌾',
        'name'        => 'Jordan Mak',
        'role'        => 'Founder & Operations Lead',
        'bio'         => 'Started Mowology with a single mower and a commitment to doing things right. Still out in the field every week.',
        'stat1_val'   => '11 yrs',
        'stat1_label' => 'Experience',
        'stat2_val'   => 'Vancouver',
        'stat2_label' => 'Territory',
    ],
    [
        'avatar'      => '👩‍🌾',
        'name'        => 'Sarah Chen',
        'role'        => 'Senior Horticulturist',
        'bio'         => 'UBC Horticulture graduate. Leads our garden design and seasonal planting programs for premium clients.',
        'stat1_val'   => '7 yrs',
        'stat1_label' => 'Experience',
        'stat2_val'   => 'Burnaby',
        'stat2_label' => 'Territory',
    ],
    [
        'avatar'      => '🧑‍🌾',
        'name'        => 'Mike Torres',
        'role'        => 'Strata Crew Lead',
        'bio'         => 'Manages all strata and commercial contracts. Known for his reliability and thorough completion notes.',
        'stat1_val'   => '5 yrs',
        'stat1_label' => 'Experience',
        'stat2_val'   => 'Richmond',
        'stat2_label' => 'Territory',
    ],
    [
        'avatar'      => '👩‍💼',
        'name'        => 'Priya Sharma',
        'role'        => 'Client Services Manager',
        'bio'         => 'Your first point of contact. Handles scheduling, quotes, and makes sure every client gets a fast response.',
        'stat1_val'   => '4 yrs',
        'stat1_label' => 'With Mowology',
        'stat2_val'   => 'Office',
        'stat2_label' => 'Based',
    ],
];

$uid = 'crew-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-crew" id="<?= h($uid) ?>">
  <div class="mw-crew__inner">
    <div class="mw-crew__title">
      <h2><?= $title ?></h2>
      <p><?= h($subtitle) ?></p>
    </div>

    <div class="mw-crew__grid">
      <?php foreach ($members as $i => $m): ?>
      <div class="mw-crew__card mw-reveal" style="--delay:<?= $i * 0.1 ?>s">
        <div class="mw-crew__avatar">
          <div class="mw-crew__avatar-bg"></div>
          <div class="mw-crew__avatar-emoji"><?= h($m['avatar'] ?? '👤') ?></div>
        </div>
        <div class="mw-crew__info">
          <h3><?= h($m['name'] ?? '') ?></h3>
          <div class="mw-crew__role"><?= h($m['role'] ?? '') ?></div>
          <p><?= h($m['bio'] ?? '') ?></p>
          <div class="mw-crew__stats">
            <?php if (!empty($m['stat1_val'])): ?>
            <div class="mw-crew__stat">
              <div class="mw-crew__stat-val"><?= h($m['stat1_val']) ?></div>
              <div class="mw-crew__stat-label"><?= h($m['stat1_label'] ?? '') ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($m['stat2_val'])): ?>
            <div class="mw-crew__stat">
              <div class="mw-crew__stat-val"><?= h($m['stat2_val']) ?></div>
              <div class="mw-crew__stat-label"><?= h($m['stat2_label'] ?? '') ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.mw-crew{background:#0d1f12;padding:clamp(3rem,6vw,6rem) 1.5rem;position:relative;overflow:hidden}
.mw-crew::before{content:'';position:absolute;bottom:0;left:0;right:0;height:250px;background:linear-gradient(to top,rgba(45,122,58,.1),transparent);pointer-events:none}
.mw-crew__inner{max-width:1200px;margin:0 auto;position:relative}
.mw-crew__title{text-align:center;margin-bottom:3rem}
.mw-crew__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#fff}
.mw-crew__title h2 em{color:#c9a84c;font-style:italic}
.mw-crew__title p{color:rgba(255,255,255,.55);margin-top:.5rem}
.mw-crew__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.5rem}
.mw-crew__card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:20px;overflow:hidden;transition:all .3s}
.mw-crew__card:hover{transform:translateY(-6px);border-color:rgba(201,168,76,.3);background:rgba(255,255,255,.07)}
.mw-crew__avatar{height:190px;position:relative;overflow:hidden;background:linear-gradient(135deg,#0a2010,#1a4a22);display:flex;align-items:flex-end;justify-content:center}
.mw-crew__avatar-bg{position:absolute;inset:0;background:radial-gradient(ellipse 80% 60% at 50% 100%,rgba(45,122,58,.3),transparent)}
.mw-crew__avatar-emoji{font-size:6.5rem;line-height:1;position:relative;z-index:1;transition:transform .3s}
.mw-crew__card:hover .mw-crew__avatar-emoji{transform:scale(1.05) translateY(-4px)}
.mw-crew__info{padding:1.4rem}
.mw-crew__info h3{font-family:'Playfair Display',serif;color:#fff;font-size:1.15rem;margin-bottom:.18rem}
.mw-crew__role{color:#c9a84c;font-size:.75rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.65rem}
.mw-crew__info p{color:rgba(255,255,255,.55);font-size:.82rem;line-height:1.6}
.mw-crew__stats{display:flex;gap:1.5rem;margin-top:.9rem;padding-top:.9rem;border-top:1px solid rgba(255,255,255,.08)}
.mw-crew__stat-val{font-family:'DM Mono',monospace;font-size:1.05rem;color:#fff;font-weight:500}
.mw-crew__stat-label{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.05em;margin-top:.1rem}
@media(max-width:600px){.mw-crew__grid{grid-template-columns:1fr 1fr}}
@media(max-width:440px){.mw-crew__grid{grid-template-columns:1fr}}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var section = document.getElementById(uid);
  if (!section) return;
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) {
        var delay = e.target.style.getPropertyValue('--delay') || '0s';
        e.target.style.transition = 'opacity .5s ease ' + delay + ', transform .5s ease ' + delay;
        e.target.style.opacity = '1';
        e.target.style.transform = 'translateY(0)';
        obs.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });
  section.querySelectorAll('.mw-reveal').forEach(function(el) {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    obs.observe(el);
  });
})();
</script>
