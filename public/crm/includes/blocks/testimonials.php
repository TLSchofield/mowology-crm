<?php
/**
 * Testimonials Block Renderer — Public Site Design System
 *
 * Uses .testimonials / .testimonials-grid / .testimonial-card from home.css
 *
 * Config: {title, testimonials: [{name, role, content, rating}...]}
 */

$title = $config['title'] ?? 'What Our Clients Say';
$testimonials = $config['testimonials'] ?? [];
?>

<section class="testimonials">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="section-title"><?php echo h($title); ?></h2>
    <?php endif; ?>

    <div class="testimonials-grid">
      <?php foreach ($testimonials as $testimonial): ?>
        <div class="testimonial-card">
          <div class="stars">
            <?php
            $rating = (int)($testimonial['rating'] ?? 5);
            for ($s = 0; $s < $rating; $s++): ?>
              ★
            <?php endfor; ?>
          </div>
          <p class="testimonial-text">"<?php echo h($testimonial['content'] ?? ''); ?>"</p>
          <p class="testimonial-author"><?php echo h($testimonial['name'] ?? ''); ?></p>
          <?php if (!empty($testimonial['role'])): ?>
            <p class="testimonial-role"><?php echo h($testimonial['role']); ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
