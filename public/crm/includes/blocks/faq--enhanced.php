<?php
/**
 * FAQ Enhanced Block Renderer — with category filter tabs
 *
 * Variant of faq.php with animated accordion + category pill filters.
 *
 * Config: {
 *   title       : string
 *   subtitle    : string
 *   categories  : [{key, label}]  — leave empty to hide filters
 *   faqs        : [{question, answer, category}]
 * }
 */

$title      = $config['title']    ?? 'Frequently Asked <em>Questions</em>';
$subtitle   = $config['subtitle'] ?? 'Everything you need to know before booking';
$categories = $config['categories'] ?? [
    ['key' => 'booking',  'label' => 'Booking & Scheduling'],
    ['key' => 'service',  'label' => 'Services'],
    ['key' => 'pricing',  'label' => 'Pricing'],
    ['key' => 'strata',   'label' => 'Strata'],
];
$faqs = $config['faqs'] ?? [
    ['question' => 'How do I schedule my first visit?',
     'answer'   => "Simply fill out our quote form or give us a call. We'll visit your property, assess your needs, and send a detailed proposal within one business day. Once approved, we'll set up your recurring schedule and you'll receive an automated reminder 24 hours before each visit.",
     'category' => 'booking'],
    ['question' => 'What happens if it rains on my scheduled day?',
     'answer'   => "We work in light rain — Vancouver weather doesn't give us much choice! For heavy rain or if conditions would damage your lawn, we'll reschedule to the next available day, usually within 48 hours. You'll be notified by text. We never skip a visit without rescheduling.",
     'category' => 'booking'],
    ['question' => 'Do I need to be home for the crew to visit?',
     'answer'   => "Not at all. Most of our clients are away at work during their visits. As long as we have access to your backyard (gate code, key, etc.), we'll take care of everything and leave a completion note. We photograph the finished work after every visit for your records.",
     'category' => 'service'],
    ['question' => 'Are there any contracts or lock-in periods?',
     'answer'   => "No long-term contracts on residential plans. We operate month-to-month and you can pause or cancel with 2 weeks notice. Annual plans get a 15% discount but can still be cancelled with a 30-day notice (remaining months are refunded).",
     'category' => 'pricing'],
    ['question' => 'How do you handle billing and payment?',
     'answer'   => "We bill monthly via automated credit card charge or e-transfer. Invoices are sent on the 1st of each month for the upcoming month's service. We accept Visa, Mastercard, and Interac e-Transfer. All billing is managed through your client portal.",
     'category' => 'pricing'],
    ['question' => "What's included in a strata maintenance contract?",
     'answer'   => "Strata contracts are fully customized. Typically this includes all common area lawn mowing, hedge and shrub maintenance, seasonal cleanups, garden bed care, fertilization, weed control, and monthly reporting with photos for your council records. We can also attend AGMs or council meetings when needed.",
     'category' => 'strata'],
    ['question' => 'Do you handle moss control? Vancouver lawns get very mossy.',
     'answer'   => "Yes — moss control is one of our most in-demand services in Metro Vancouver. We treat with iron sulphate in fall/spring, follow up with dethatching, then aerate and overseed to fill in the gaps with strong turf grass. A well-maintained lawn naturally resists moss re-establishment.",
     'category' => 'service'],
];

$showCats = !empty($categories);
$uid = 'faqe-' . ($block['id'] ?? substr(md5(uniqid('', true)), 0, 8));
?>

<section class="mw-faqe" id="<?= h($uid) ?>">
  <div class="mw-faqe__inner">
    <div class="mw-faqe__title">
      <h2><?= $title ?></h2>
      <?php if ($subtitle): ?><p><?= h($subtitle) ?></p><?php endif; ?>
    </div>

    <?php if ($showCats): ?>
    <div class="mw-faqe__cats">
      <button class="mw-faqe__cat active" data-cat="all">All Questions</button>
      <?php foreach ($categories as $cat): ?>
      <button class="mw-faqe__cat" data-cat="<?= h($cat['key']) ?>"><?= h($cat['label']) ?></button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mw-faqe__list">
      <?php foreach ($faqs as $faq): ?>
      <div class="mw-faqe__item visible" data-cat="<?= h($faq['category'] ?? 'all') ?>">
        <button class="mw-faqe__q">
          <span class="mw-faqe__icon">+</span>
          <?= h($faq['question'] ?? '') ?>
        </button>
        <div class="mw-faqe__a">
          <p><?= h($faq['answer'] ?? '') ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<style>
.mw-faqe{background:#f8f5ef;padding:clamp(3rem,6vw,6rem) 1.5rem}
.mw-faqe__inner{max-width:800px;margin:0 auto}
.mw-faqe__title{text-align:center;margin-bottom:2.5rem}
.mw-faqe__title h2{font-family:'Playfair Display',serif;font-size:clamp(1.8rem,3.5vw,2.8rem);color:#1c2b1e}
.mw-faqe__title h2 em{color:#2d7a3a;font-style:italic}
.mw-faqe__title p{color:#4a6350;margin-top:.5rem}
.mw-faqe__cats{display:flex;gap:.55rem;flex-wrap:wrap;justify-content:center;margin-bottom:2.5rem}
.mw-faqe__cat{padding:.45rem 1.05rem;border-radius:20px;border:1.5px solid rgba(45,122,58,.15);background:#fff;color:#4a6350;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .2s;font-family:'DM Sans',sans-serif}
.mw-faqe__cat.active{background:#2d7a3a;border-color:#2d7a3a;color:#fff}
.mw-faqe__cat:hover:not(.active){border-color:#2d7a3a;color:#2d7a3a}
.mw-faqe__list{display:flex;flex-direction:column;border-radius:16px;overflow:hidden;border:1px solid rgba(45,122,58,.15)}
.mw-faqe__item{background:#fff;border-bottom:1px solid rgba(45,122,58,.12)}
.mw-faqe__item:last-child{border-bottom:none}
.mw-faqe__item:not(.visible){display:none}
.mw-faqe__q{width:100%;padding:1.3rem 1.6rem;display:flex;align-items:center;gap:.9rem;cursor:pointer;background:none;border:none;font-family:'DM Sans',sans-serif;text-align:left;font-size:.95rem;font-weight:600;color:#1c2b1e;transition:background .15s}
.mw-faqe__q:hover{background:rgba(45,122,58,.04)}
.mw-faqe__q.open{color:#2d7a3a}
.mw-faqe__icon{width:26px;height:26px;border-radius:50%;flex-shrink:0;background:#f8f5ef;display:flex;align-items:center;justify-content:center;font-size:1.1rem;transition:all .25s;color:#2d7a3a;font-style:normal;line-height:1}
.mw-faqe__q.open .mw-faqe__icon{background:#2d7a3a;color:#fff;transform:rotate(45deg)}
.mw-faqe__a{max-height:0;overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1),padding .3s;padding:0 1.6rem}
.mw-faqe__a.open{max-height:500px;padding:0 1.6rem 1.3rem}
.mw-faqe__a p{color:#4a6350;line-height:1.75;font-size:.9rem}
</style>

<script>
(function(){
  var uid = <?= json_encode($uid) ?>;
  var section = document.getElementById(uid);
  if (!section) return;

  // Category filter
  section.addEventListener('click', function(e) {
    var catBtn = e.target.closest('.mw-faqe__cat');
    if (catBtn) {
      section.querySelectorAll('.mw-faqe__cat').forEach(function(b) { b.classList.remove('active'); });
      catBtn.classList.add('active');
      var cat = catBtn.getAttribute('data-cat');
      section.querySelectorAll('.mw-faqe__item').forEach(function(item) {
        item.classList.toggle('visible', cat === 'all' || item.getAttribute('data-cat') === cat);
      });
      return;
    }

    // Accordion
    var qBtn = e.target.closest('.mw-faqe__q');
    if (qBtn) {
      var isOpen = qBtn.classList.contains('open');
      section.querySelectorAll('.mw-faqe__q.open').forEach(function(q) {
        q.classList.remove('open');
        q.nextElementSibling.classList.remove('open');
      });
      if (!isOpen) {
        qBtn.classList.add('open');
        qBtn.nextElementSibling.classList.add('open');
      }
    }
  });
})();
</script>
