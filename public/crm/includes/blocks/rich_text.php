<?php
/**
 * Rich Text Block Renderer
 *
 * Config: {title, content}
 * Content is stored as HTML and NOT escaped (WYSIWYG output)
 */

$title = $config['title'] ?? '';
$content = $config['content'] ?? '';
?>

<section class="rich-text-block py-5">
  <div class="container">
    <?php if ($title): ?>
      <h2 class="mb-4"><?php echo h($title); ?></h2>
    <?php endif; ?>

    <div class="row">
      <div class="col-lg-8 mx-auto">
        <div class="rich-text-content">
          <?php echo $content; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
.rich-text-content h1, .rich-text-content h2, .rich-text-content h3, .rich-text-content h4, .rich-text-content h5, .rich-text-content h6 {
  margin-top: 1.5rem;
  margin-bottom: 1rem;
  color: var(--mw-dark, #1A5F4A);
}

.rich-text-content h1 { font-size: 2rem; }
.rich-text-content h2 { font-size: 1.75rem; }
.rich-text-content h3 { font-size: 1.5rem; }

.rich-text-content p {
  margin-bottom: 1rem;
  line-height: 1.6;
}

.rich-text-content ul, .rich-text-content ol {
  margin-bottom: 1rem;
  padding-left: 2rem;
}

.rich-text-content li {
  margin-bottom: 0.5rem;
}

.rich-text-content blockquote {
  border-left: 4px solid var(--mw-green, #2D8659);
  padding-left: 1rem;
  margin: 1rem 0;
  font-style: italic;
  color: var(--mw-dark, #1A5F4A);
}

.rich-text-content img {
  max-width: 100%;
  height: auto;
  margin: 1rem 0;
  border-radius: 8px;
}

.rich-text-content a {
  color: var(--mw-green, #2D8659);
  text-decoration: underline;
}

.rich-text-content a:hover {
  color: var(--mw-dark, #1A5F4A);
}

.rich-text-content code {
  background-color: #f5f5f5;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: 'Courier New', monospace;
  font-size: 0.9em;
}

.rich-text-content pre {
  background-color: #f5f5f5;
  padding: 1rem;
  border-radius: 4px;
  overflow-x: auto;
  margin: 1rem 0;
}
</style>
