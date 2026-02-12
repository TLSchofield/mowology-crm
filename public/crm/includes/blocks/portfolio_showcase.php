<?php
/**
 * Portfolio Showcase Block Renderer — Public Site Design System
 *
 * Uses CSS Grid for responsive portfolio layout.
 * No Bootstrap classes — uses public site design tokens.
 *
 * Config: {title, description, limit (3/4/6), filters (true/false), portfolio_ids: [id...]}
 */

$title = $config['title'] ?? 'Our Work';
$description = $config['description'] ?? '';
$limit = (int)($config['limit'] ?? 6);
$showFilters = (bool)($config['filters'] ?? false);
$portfolioIds = $config['portfolio_ids'] ?? [];

// Load portfolio items (placeholder — future: query portfolio table)
$items = [];

// Get unique categories from items
$categories = [];
if ($showFilters && !empty($items)) {
    foreach ($items as $item) {
        if (!empty($item['category']) && !in_array($item['category'], $categories)) {
            $categories[] = $item['category'];
        }
    }
}
?>

<section class="slp-section slp-alt">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php endif; ?>
    <?php if ($description): ?>
      <p class="slp-intro"><?php echo h($description); ?></p>
    <?php endif; ?>

    <?php if ($showFilters && !empty($categories)): ?>
      <div class="portfolio-filters" style="text-align: center; margin-bottom: 30px;">
        <button class="btn portfolio-filter-btn active" data-filter="">All</button>
        <?php foreach ($categories as $category): ?>
          <button class="btn portfolio-filter-btn" data-filter="<?php echo h($category); ?>"><?php echo h($category); ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($items)): ?>
      <div class="services-grid" id="portfolioGrid">
        <?php foreach (array_slice($items, 0, $limit) as $item): ?>
          <div class="service-card portfolio-item" data-category="<?php echo h($item['category'] ?? ''); ?>">
            <?php if (!empty($item['image'])): ?>
              <img src="<?php echo h($item['image']); ?>" alt="<?php echo h($item['title'] ?? ''); ?>" loading="lazy" style="width: 100%; height: 200px; object-fit: cover; border-radius: 8px 8px 0 0;">
            <?php endif; ?>
            <h3><?php echo h($item['title'] ?? ''); ?></h3>
            <p><?php echo h($item['description'] ?? ''); ?></p>
            <?php if (!empty($item['url'])): ?>
              <a href="<?php echo h($item['url']); ?>" class="btn">View Project →</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="text-align: center; color: var(--text-light, #6a6a6a); padding: 40px 0;">No portfolio items to display.</p>
    <?php endif; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const filterBtns = document.querySelectorAll('.portfolio-filter-btn');
  const portfolioItems = document.querySelectorAll('.portfolio-item');

  filterBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      filterBtns.forEach(function(b) { b.classList.remove('active'); });
      btn.classList.add('active');
      var category = btn.dataset.filter;
      portfolioItems.forEach(function(item) {
        item.style.display = (category === '' || item.dataset.category === category) ? '' : 'none';
      });
    });
  });
});
</script>
