<?php
/**
 * FAQ Block Renderer
 *
 * Config: {title, description, faqs: [{question, answer}...]}
 */

$title = $config['title'] ?? 'Frequently Asked Questions';
$description = $config['description'] ?? '';
$faqs = $config['faqs'] ?? [];
$uniqueId = uniqid('faq-');
?>

<section class="faq-block py-5">
  <div class="container">
    <div class="row mb-5">
      <div class="col-lg-8 mx-auto">
        <?php if ($title): ?>
          <h2 class="text-center mb-3"><?php echo h($title); ?></h2>
        <?php endif; ?>
        <?php if ($description): ?>
          <p class="text-center text-muted"><?php echo h($description); ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-8 mx-auto">
        <div class="accordion" id="<?php echo $uniqueId; ?>">
          <?php foreach ($faqs as $index => $faq): ?>
          <div class="card border-0 mb-2">
            <div class="card-header bg-white border-bottom" id="heading-<?php echo $uniqueId; ?>-<?php echo $index; ?>">
              <h5 class="mb-0">
                <button class="btn btn-link btn-block text-left collapsed" type="button" data-toggle="collapse" data-target="#collapse-<?php echo $uniqueId; ?>-<?php echo $index; ?>" aria-expanded="false" aria-controls="collapse-<?php echo $uniqueId; ?>-<?php echo $index; ?>">
                  <?php echo h($faq['question'] ?? ''); ?>
                  <i class="fas fa-chevron-down float-right"></i>
                </button>
              </h5>
            </div>
            <div id="collapse-<?php echo $uniqueId; ?>-<?php echo $index; ?>" class="collapse" aria-labelledby="heading-<?php echo $uniqueId; ?>-<?php echo $index; ?>" data-parent="#<?php echo $uniqueId; ?>">
              <div class="card-body">
                <?php echo h($faq['answer'] ?? ''); ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.faq-block .card-header button {
  color: var(--mw-green, #2D8659);
  font-weight: 500;
  text-decoration: none;
}

.faq-block .card-header button:hover {
  color: var(--mw-dark, #1A5F4A);
  text-decoration: none;
}

.faq-block .card-header button i {
  transition: transform 0.3s ease;
}

.faq-block .card-header button:not(.collapsed) i {
  transform: rotate(180deg);
}
</style>
