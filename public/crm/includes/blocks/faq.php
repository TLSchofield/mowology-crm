<?php
/**
 * FAQ Block Renderer — Public Site Design System
 *
 * Uses .slp-faq / .slp-faq-list / details.slp-faq-item from service-landing.css
 * Native HTML <details>/<summary> — no JavaScript required.
 *
 * Config: {title, description, faqs: [{question, answer}...]}
 */

$title = $config['title'] ?? 'Frequently Asked Questions';
$description = $config['description'] ?? '';
$faqs = $config['faqs'] ?? [];
?>

<section class="slp-section slp-faq">
  <div class="container">
    <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php if ($description): ?>
      <p class="slp-intro"><?php echo h($description); ?></p>
    <?php endif; ?>

    <div class="slp-faq-list">
      <?php foreach ($faqs as $faq): ?>
        <details class="slp-faq-item">
          <summary><?php echo h($faq['question'] ?? ''); ?></summary>
          <p><?php echo h($faq['answer'] ?? ''); ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
