<?php
/**
 * Seasonal Services Calendar Block Renderer
 *
 * Tab-based seasonal service guide with month-highlight strip per season.
 *
 * Config: {
 *   title    : string
 *   subtitle : string
 *   seasons  : {spring:{heading,description,active_months[],services[]}, summer:{…}, fall:{…}, winter:{…}}
 *   Each service: {icon, name, description, tag}  — tag: essential|recommended|popular
 * }
 */

$title    = $config['title']    ?? 'Services by <em>Season</em>';
$subtitle = $config['subtitle'] ?? "Know exactly what your lawn needs, when it needs it — in Metro Vancouver's climate";

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

$defaultSeasons = [
    'spring' => [
        'label'         => 'Spring',
        'icon'          => '🌱',
        'heading'       => 'Wake Up Your Lawn',
        'description'   => "After Vancouver's wet winter, spring is the most critical time for your lawn. Moss control, aeration, and overseeding set the foundation for the entire season.",
        'active_months' => [3,4,5],
        'services'      => [
            ['icon'=>'🌿','name'=>'First Lawn Mow of Season',   'description'=>'Cut at higher setting to encourage deep root growth','tag'=>'essential'],
            ['icon'=>'🕳️','name'=>'Core Aeration',              'description'=>'Relieves compaction from winter foot traffic and rain','tag'=>'recommended'],
            ['icon'=>'🌾','name'=>'Overseeding',                'description'=>'Fill bare patches before weeds establish','tag'=>'recommended'],
            ['icon'=>'🟢','name'=>'Moss Treatment',             'description'=>"Critical in Metro Vancouver's damp climate",'tag'=>'essential'],
            ['icon'=>'🌸','name'=>'Garden Bed Prep',            'description'=>'Edge, mulch, and plant spring annuals','tag'=>'popular'],
            ['icon'=>'✂️','name'=>'Hedge & Shrub Pruning',      'description'=>'Encourage healthy growth for the season ahead','tag'=>'recommended'],
        ],
    ],
    'summer' => [
        'label'         => 'Summer',
        'icon'          => '☀️',
        'heading'       => 'Maintain the Peak',
        'description'   => 'Vancouver summers bring heat and dry spells. Regular mowing rhythm, smart watering, and pest control keep your lawn lush through July and August.',
        'active_months' => [6,7,8],
        'services'      => [
            ['icon'=>'🌿','name'=>'Weekly Mowing',              'description'=>'Consistent 3" cut height in summer heat','tag'=>'essential'],
            ['icon'=>'💧','name'=>'Irrigation Check',           'description'=>'Ensure sprinklers cover evenly before summer heat','tag'=>'recommended'],
            ['icon'=>'🌺','name'=>'Mid-Season Fertilizing',     'description'=>'Slow-release nitrogen for sustained colour','tag'=>'popular'],
            ['icon'=>'✂️','name'=>'Hedge Trimming',             'description'=>'Keep formal hedges sharp through peak growing season','tag'=>'popular'],
            ['icon'=>'🐛','name'=>'Pest & Chafer Control',      'description'=>'Pre-emptive treatment before European chafer season','tag'=>'recommended'],
        ],
    ],
    'fall' => [
        'label'         => 'Fall',
        'icon'          => '🍂',
        'heading'       => 'Prepare for Winter',
        'description'   => 'Fall is the second most important season. What you do in September and October determines how well your lawn survives and recovers from winter.',
        'active_months' => [9,10,11],
        'services'      => [
            ['icon'=>'🍂','name'=>'Leaf Removal',               'description'=>'Prevent smothering and fungal disease over winter','tag'=>'essential'],
            ['icon'=>'🕳️','name'=>'Fall Aeration & Overseeding','description'=>'Best time for renovation — warm soil, cool air','tag'=>'recommended'],
            ['icon'=>'🌿','name'=>'Winterizer Fertilizer',      'description'=>'Strengthens roots before frost','tag'=>'recommended'],
            ['icon'=>'✂️','name'=>'Fall Hedge Cutback',         'description'=>'Last shaping before dormancy','tag'=>'popular'],
            ['icon'=>'🌺','name'=>'Garden Bed Winterizing',     'description'=>'Cut back perennials, refresh mulch, plant bulbs','tag'=>'popular'],
        ],
    ],
    'winter' => [
        'label'         => 'Winter',
        'icon'          => '❄️',
        'heading'       => 'Property Protection',
        'description'   => 'Vancouver winters are mild but very wet. Storm cleanup, drainage maintenance, and keeping walkways safe are the priorities December through February.',
        'active_months' => [1,2,12],
        'services'      => [
            ['icon'=>'🌪️','name'=>'Storm Debris Cleanup',       'description'=>'Post-windstorm branch and debris removal','tag'=>'essential'],
            ['icon'=>'🚶','name'=>'Walkway Maintenance',        'description'=>'Keep paths safe and clear of debris and moss','tag'=>'recommended'],
            ['icon'=>'💧','name'=>'Drainage Inspection',        'description'=>'Check gutters, French drains, and low spots','tag'=>'recommended'],
            ['icon'=>'🌿','name'=>'Pre-Emergent Moss Treatment','description'=>'Apply before moss establishes in wet conditions','tag'=>'popular'],
        ],
    ],
];

// Merge config seasons over defaults
$seasons = $defaultSeasons;
if (!empty($config['seasons']) && is_array($config['seasons'])) {
    foreach ($config['seasons'] as $key => $override) {
        if (isset($seasons[$key])) {
            $seasons[$key] = array_merge($seasons[$key], $override);
        }
    }
}

$uid = 'seas-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
$seasonKeys = ['spring','summer','fall','winter'];
?>

<section class="mw-seas" id="<?= h($uid) ?>">
  <div class="mw-seas__inner">
    <div class="mw-seas__title">
      <h2><?= $title ?></h2>
      <p><?= h($subtitle) ?></p>
    </div>

    <div class="mw-seas__tabs">
      <?php foreach ($seasonKeys as $i => $key): $s = $seasons[$key]; ?>
      <button class="mw-seas__tab mw-seas__tab--<?= h($key) ?><?= $i === 0 ? ' active' : '' ?>"
              data-season="<?= h($key) ?>">
        <span class="mw-seas__tab-icon"><?= h($s['icon']) ?></span>
        <?= h($s['label']) ?>
      </button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($seasonKeys as $i => $key): $s = $seasons[$key]; ?>
    <div class="mw-seas__panel<?= $i === 0 ? ' active' : '' ?>" id="<?= h($uid) ?>-<?= h($key) ?>">
      <div class="mw-seas__grid">
        <div class="mw-seas__desc-card">
          <span class="mw-seas__badge mw-seas__badge--<?= h($key) ?>"><?= h($s['label']) ?></span>
          <h3><?= h($s['heading']) ?></h3>
          <p><?= h($s['description']) ?></p>
          <div class="mw-seas__months">
            <?php foreach ($months as $mi => $m):
                $active = in_array($mi + 1, $s['active_months'] ?? []);
            ?>
            <span class="mw-seas__month<?= $active ? ' active' : '' ?>"><?= h($m) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mw-seas__services">
          <?php foreach ($s['services'] as $svc): ?>
          <div class="mw-seas__svc mw-reveal">
            <div class="mw-seas__svc-icon"><?= h($svc['icon']) ?></div>
            <div class="mw-seas__svc-info">
              <h4><?= h($svc['name']) ?></h4>
              <p><?= h($svc['description']) ?></p>
            </div>
            <span class="mw-seas__tag mw-seas__tag--<?= h($svc['tag'] ?? 'recommended') ?>"><?= h(ucfirst($svc['tag'] ?? 'Recommended')) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<style>
.mw-seas{background:#f8f5ef;padding:clamp(3rem,6vw,6rem) 1.5rem}
.mw-seas__inner{max-width:1200px;margin:0 auto}
.mw-seas__title{text-align:center;margin-bottom:3rem}
.mw-seas__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#1c2b1e}
.mw-seas__title h2 em{color:#2d7a3a;font-style:italic}
.mw-seas__title p{color:#4a6350;margin-top:.5rem}
.mw-seas__tabs{display:flex;border-radius:12px;overflow:hidden;border:2px solid rgba(45,122,58,.15);margin-bottom:2rem;background:#fff}
.mw-seas__tab{flex:1;padding:1rem;text-align:center;cursor:pointer;font-weight:600;font-size:.88rem;border:none;font-family:'DM Sans',sans-serif;color:#4a6350;background:transparent;transition:all .2s}
.mw-seas__tab-icon{font-size:1.4rem;display:block;margin-bottom:.25rem}
.mw-seas__tab--spring.active{background:#e8f5e9;color:#2d7a3a}
.mw-seas__tab--summer.active{background:#fff8e1;color:#c9a84c}
.mw-seas__tab--fall.active{background:#fbe9e7;color:#bf4f2a}
.mw-seas__tab--winter.active{background:#e3f2fd;color:#1565c0}
.mw-seas__panel{display:none}
.mw-seas__panel.active{display:block;animation:mwSeasFade .3s ease}
@keyframes mwSeasFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.mw-seas__grid{display:grid;grid-template-columns:280px 1fr;gap:2rem;align-items:start}
.mw-seas__desc-card{background:#fff;border-radius:16px;padding:1.75rem;border:1px solid rgba(45,122,58,.15);position:sticky;top:80px}
.mw-seas__desc-card h3{font-family:'Playfair Display',serif;font-size:1.4rem;margin:.6rem 0 .65rem}
.mw-seas__desc-card p{color:#4a6350;font-size:.88rem;line-height:1.7;margin-bottom:1rem}
.mw-seas__badge{display:inline-block;padding:.3rem .85rem;border-radius:20px;font-size:.72rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase}
.mw-seas__badge--spring{background:#e8f5e9;color:#2d7a3a}
.mw-seas__badge--summer{background:#fff8e1;color:#c9a84c}
.mw-seas__badge--fall{background:#fbe9e7;color:#bf4f2a}
.mw-seas__badge--winter{background:#e3f2fd;color:#1565c0}
.mw-seas__months{display:flex;gap:.35rem;flex-wrap:wrap;margin-top:.75rem}
.mw-seas__month{padding:.2rem .6rem;border-radius:6px;font-size:.7rem;font-weight:600;font-family:'DM Mono',monospace;background:#ede9df;color:#4a6350}
.mw-seas__month.active{background:#2d7a3a;color:#fff}
.mw-seas__services{display:flex;flex-direction:column;gap:.65rem}
.mw-seas__svc{background:#fff;border-radius:12px;padding:1rem 1.25rem;border:1px solid rgba(45,122,58,.15);display:flex;align-items:center;gap:.9rem;transition:all .2s}
.mw-seas__svc:hover{border-color:#2d7a3a;transform:translateX(4px)}
.mw-seas__svc-icon{font-size:1.4rem;flex-shrink:0;width:42px;height:42px;background:#f8f5ef;border-radius:10px;display:flex;align-items:center;justify-content:center}
.mw-seas__svc-info{flex:1}
.mw-seas__svc-info h4{font-weight:600;font-size:.9rem;color:#1c2b1e}
.mw-seas__svc-info p{color:#4a6350;font-size:.8rem;margin-top:.1rem}
.mw-seas__tag{font-size:.7rem;font-weight:600;padding:.18rem .55rem;border-radius:4px;white-space:nowrap;flex-shrink:0}
.mw-seas__tag--recommended{background:rgba(45,122,58,.12);color:#2d7a3a}
.mw-seas__tag--popular{background:rgba(201,168,76,.15);color:#8b6e1f}
.mw-seas__tag--essential{background:rgba(191,79,42,.12);color:#bf4f2a}
@media(max-width:768px){.mw-seas__grid{grid-template-columns:1fr}.mw-seas__desc-card{position:static}.mw-seas__tab{font-size:.8rem;padding:.75rem .5rem}}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var section = document.getElementById(uid);
  if (!section) return;

  section.addEventListener('click', function(e) {
    var btn = e.target.closest('.mw-seas__tab');
    if (!btn) return;
    var season = btn.getAttribute('data-season');
    section.querySelectorAll('.mw-seas__tab').forEach(function(t) { t.classList.remove('active'); });
    btn.classList.add('active');
    section.querySelectorAll('.mw-seas__panel').forEach(function(p) { p.classList.remove('active'); });
    var panel = document.getElementById(uid + '-' + season);
    if (panel) panel.classList.add('active');
  });

  // Reveal animation
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) { e.target.classList.add('is-visible'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.1 });
  section.querySelectorAll('.mw-reveal').forEach(function(el) {
    el.style.opacity = '0';
    el.style.transform = 'translateY(16px)';
    el.style.transition = 'opacity .4s ease, transform .4s ease';
    obs.observe(el);
  });
  section.querySelectorAll('.mw-reveal.is-visible').forEach(function(el) {
    el.style.opacity = '1';
    el.style.transform = 'translateY(0)';
  });
})();
</script>
