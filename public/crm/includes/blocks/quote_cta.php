<?php
/**
 * Quote CTA Block Renderer (P2-E)
 *
 * Dynamic CTA with live phone number and current seasonal offer pulled from tokens.
 * Config: { headline, subheadline, offer_text, button_text, button_url, style, show_phone }
 */

$headline    = $config['headline']    ?? 'Get Your Free Lawn Care Quote';
$subheadline = $config['subheadline'] ?? 'No obligation. Same-day estimates available.';
$offerText   = $config['offer_text']  ?? '';
$buttonText  = $config['button_text'] ?? 'Get a Free Quote';
$buttonUrl   = $config['button_url']  ?? '/quote';
$style       = $config['style']       ?? 'green';   // green | dark | light
$showPhone   = !isset($config['show_phone']) || (bool)$config['show_phone'];

// Pull live contact details from constants (set in bootstrap.php)
$phoneDisplay = defined('SITE_PHONE_DISPLAY') ? SITE_PHONE_DISPLAY : '(778) 846-9273';
$phoneTel     = defined('SITE_PHONE_TEL')     ? SITE_PHONE_TEL     : '+17788469273';

// Style map
$bgStyles = [
    'green' => 'background: linear-gradient(135deg, var(--mowology-green, #2D8659), var(--mowology-dark, #1A5F4A)); color:#fff;',
    'dark'  => 'background: #1A1A2E; color:#fff;',
    'light' => 'background: #f4faf7; color:#1A5F4A; border: 2px solid #2D8659;',
];
$bgStyle = $bgStyles[$style] ?? $bgStyles['green'];
$btnClass = $style === 'light' ? 'btn-primary-large' : 'btn-light-large';
?>

<section class="quote-cta-block" style="padding: 4rem 0; <?php echo $bgStyle; ?>">
  <div class="container text-center">

    <?php if ($offerText): ?>
    <div class="quote-cta-offer" style="display:inline-block; background:rgba(255,255,255,0.15);
         border-radius:20px; padding:6px 18px; font-size:0.85rem; font-weight:600;
         letter-spacing:0.5px; margin-bottom:1rem; text-transform:uppercase;">
      <?php echo h($offerText); ?>
    </div>
    <?php endif; ?>

    <h2 style="font-size:2rem; font-weight:700; margin-bottom:0.75rem;"><?php echo h($headline); ?></h2>

    <?php if ($subheadline): ?>
    <p style="font-size:1.1rem; opacity:0.9; margin-bottom:2rem; max-width:560px; margin-left:auto; margin-right:auto;">
      <?php echo h($subheadline); ?>
    </p>
    <?php endif; ?>

    <div class="cta-buttons" style="display:flex; flex-wrap:wrap; gap:1rem; justify-content:center; align-items:center;">
      <a href="<?php echo h($buttonUrl); ?>" class="<?php echo $btnClass; ?>">
        <?php echo h($buttonText); ?>
      </a>
      <?php if ($showPhone): ?>
      <a href="tel:<?php echo h($phoneTel); ?>" class="<?php echo $style === 'light' ? 'btn btn-outline-success' : 'btn btn-outline-light'; ?>"
         style="padding: 14px 28px; font-size:1rem; font-weight:600; border-radius:6px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right:6px;vertical-align:middle;">
          <path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1-9.4 0-17-7.6-17-17 0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1L6.6 10.8z"/>
        </svg>
        <?php echo h($phoneDisplay); ?>
      </a>
      <?php endif; ?>
    </div>

  </div>
</section>
