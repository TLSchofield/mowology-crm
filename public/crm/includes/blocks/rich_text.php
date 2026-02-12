<?php
/**
 * Rich Text Block Renderer — Public Site Design System
 *
 * Uses .slp-section for consistent spacing with other blocks.
 * Content is stored as HTML and NOT escaped (WYSIWYG output).
 *
 * Config: {title, content}
 */

$title = $config['title'] ?? '';
$content = $config['content'] ?? '';
?>

<section class="slp-section">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="slp-heading"><?php echo h($title); ?></h2>
    <?php endif; ?>

    <div class="rich-text-content" style="max-width: 800px; margin: 0 auto;">
      <?php echo $content; ?>
    </div>
  </div>
</section>

<style>
.rich-text-content h1, .rich-text-content h2, .rich-text-content h3,
.rich-text-content h4, .rich-text-content h5, .rich-text-content h6 {
  margin-top: 1.5rem;
  margin-bottom: 1rem;
  color: var(--mowology-dark, #1A5F4A);
}

.rich-text-content h1 { font-size: 2rem; }
.rich-text-content h2 { font-size: 1.75rem; }
.rich-text-content h3 { font-size: 1.5rem; }

.rich-text-content p {
  margin-bottom: 1rem;
  line-height: 1.7;
  color: var(--text-medium, #4a4a4a);
}

.rich-text-content ul, .rich-text-content ol {
  margin-bottom: 1rem;
  padding-left: 2rem;
}

.rich-text-content li {
  margin-bottom: 0.5rem;
  line-height: 1.6;
}

.rich-text-content blockquote {
  border-left: 4px solid var(--mowology-green, #2D8659);
  padding-left: 1rem;
  margin: 1.5rem 0;
  font-style: italic;
  color: var(--mowology-dark, #1A5F4A);
}

.rich-text-content img {
  max-width: 100%;
  height: auto;
  margin: 1rem 0;
  border-radius: 8px;
}

.rich-text-content a {
  color: var(--mowology-green, #2D8659);
  text-decoration: underline;
}

.rich-text-content a:hover {
  color: var(--mowology-dark, #1A5F4A);
}
</style>
