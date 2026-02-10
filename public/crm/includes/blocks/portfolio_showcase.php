<?php
/**
 * Portfolio Showcase Block Renderer
 *
 * Config: {title, description, limit (3/4/6), filters (true/false), portfolio_ids: [id...]}
 */

$title = $config['title'] ?? 'Our Work';
$description = $config['description'] ?? '';
$limit = (int)($config['limit'] ?? 6);
$showFilters = (bool)($config['filters'] ?? false);
$portfolioIds = $config['portfolio_ids'] ?? [];

// Load portfolio items
$items = [];
if (!empty($portfolioIds)) {
    // In a real scenario, this would query the portfolio table
    // For now, we'll use a placeholder
    foreach ($portfolioIds as $id) {
        // $items[] = portfolio_getById($id);
    }
}

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

<section class="portfolio-showcase-block py-5" style="background-color: #f8f9fa;">
  <div class="container">
    <?php if ($title): ?>
      <div class="row mb-5">
        <div class="col-lg-8 mx-auto text-center">
          <h2 class="mb-3"><?php echo h($title); ?></h2>
          <?php if ($description): ?>
            <p class="lead text-muted"><?php echo h($description); ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($showFilters && !empty($categories)): ?>
      <div class="row mb-4">
        <div class="col-lg-8 mx-auto text-center">
          <div class="btn-group btn-group-toggle" role="group">
            <label class="btn btn-outline-primary active">
              <input type="radio" name="portfolio-filter" value="" checked> All
            </label>
            <?php foreach ($categories as $category): ?>
            <label class="btn btn-outline-primary">
              <input type="radio" name="portfolio-filter" value="<?php echo h($category); ?>"> <?php echo h($category); ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row" id="portfolioGrid">
      <?php foreach (array_slice($items, 0, $limit) as $item): ?>
      <div class="col-md-4 mb-4 portfolio-item" data-category="<?php echo h($item['category'] ?? ''); ?>">
        <div class="card border-0 shadow-sm overflow-hidden" style="cursor: pointer; transition: transform 0.3s ease;">
          <img
            src="<?php echo h($item['image'] ?? ''); ?>"
            alt="<?php echo h($item['title'] ?? ''); ?>"
            class="card-img-top"
            style="height: 250px; object-fit: cover;"
            loading="lazy"
          >
          <div class="card-body">
            <h5 class="card-title"><?php echo h($item['title'] ?? ''); ?></h5>
            <p class="card-text text-muted small"><?php echo h($item['description'] ?? ''); ?></p>
            <?php if (!empty($item['url'])): ?>
              <a href="<?php echo h($item['url']); ?>" class="btn btn-sm btn-outline-primary">View Project</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (empty($items)): ?>
      <div class="row">
        <div class="col text-center py-5">
          <p class="text-muted">No portfolio items to display.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const filterBtns = document.querySelectorAll('[name="portfolio-filter"]');
  const portfolioItems = document.querySelectorAll('.portfolio-item');

  filterBtns.forEach(btn => {
    btn.addEventListener('change', function() {
      const category = this.value;
      portfolioItems.forEach(item => {
        if (category === '' || item.dataset.category === category) {
          item.style.display = '';
        } else {
          item.style.display = 'none';
        }
      });
    });
  });
});
</script>
