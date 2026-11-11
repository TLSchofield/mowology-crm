<?php
/**
 * Portfolio Project - Detail View
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

$projectId = intval($_GET['id'] ?? 0);
if (!$projectId) {
    header("Location: index.php?error=invalid");
    exit;
}

$project = getPortfolioProject($projectId);
if (!$project) {
    header("Location: index.php?error=not_found");
    exit;
}

$pageTitle = htmlspecialchars($project['project_name']);
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<?php
$isLive      = ($project['status'] ?? '') === 'published';
$categories  = json_decode($project['categories'] ?? '[]', true) ?: [];
$tags        = json_decode($project['tags'] ?? '[]', true) ?: [];
$hasBefore   = !empty($project['before_image_path']);
$hasAfter    = !empty($project['after_image_path']);
$changed     = $_GET['status_changed'] ?? '';
?>
          <div class="it-pf">
              <a href="index.php" class="it-pf-back"><span aria-hidden="true">&larr;</span>&nbsp;Back to Portfolio</a>

              <header class="it-pf-head">
                  <div class="it-pf-id">
                      <h1 class="it-pf-title"><?php echo htmlspecialchars($project['project_name']); ?></h1>
                      <p class="it-pf-meta">
                          <span class="it-pf-status<?php echo $isLive ? ' it-pf-status--live' : ''; ?>">
                              <?php if ($isLive): ?>
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
                                  Published: shown on your public portfolio
                              <?php else: ?>
                                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6v6l4 2M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z"/></svg>
                                  Draft: hidden from your public portfolio
                              <?php endif; ?>
                          </span>
                          <span class="it-pf-num"><?php echo htmlspecialchars($project['project_number']); ?></span>
                          <?php if (!empty($project['location'])): ?><span><?php echo htmlspecialchars($project['location']); ?></span><?php endif; ?>
                      </p>
                  </div>
                  <div class="it-pf-actions">
                      <?php if ($isLive): ?>
                          <button type="button" class="it-pf-btn" onclick="setProjectStatus(<?php echo (int)$project['id']; ?>, 'draft')">Unpublish</button>
                          <a href="edit.php?id=<?php echo (int)$project['id']; ?>" class="it-pf-btn">Edit</a>
                      <?php else: ?>
                          <button type="button" class="it-pf-btn it-pf-btn--primary" onclick="setProjectStatus(<?php echo (int)$project['id']; ?>, 'published')">Publish</button>
                          <a href="edit.php?id=<?php echo (int)$project['id']; ?>" class="it-pf-btn">Edit</a>
                      <?php endif; ?>
                  </div>
              </header>

              <?php if ($changed === 'published'): ?>
                  <p class="it-pf-note" role="status">Published. It now shows on your public portfolio page.</p>
              <?php elseif ($changed === 'draft'): ?>
                  <p class="it-pf-note" role="status">Unpublished. It is hidden from your public portfolio page again.</p>
              <?php elseif (($_GET['error'] ?? '') === 'status_failed'): ?>
                  <p class="it-pf-note it-pf-note--bad" role="alert">The status could not be changed. Nothing was changed.</p>
              <?php endif; ?>
              <?php if (isset($_GET['success'])): ?>
                  <p class="it-pf-note" role="status">Project saved.</p>
              <?php endif; ?>

              <?php if ($hasBefore || $hasAfter): ?>
                  <div class="it-pf-photos<?php echo ($hasBefore && $hasAfter) ? '' : ' it-pf-photos--one'; ?>">
                      <?php if ($hasBefore): ?>
                          <figure class="it-pf-photo">
                              <figcaption>Before</figcaption>
                              <img src="<?php echo htmlspecialchars($project['before_image_path']); ?>" alt="Before: <?php echo htmlspecialchars($project['project_name']); ?>">
                          </figure>
                      <?php endif; ?>
                      <?php if ($hasAfter): ?>
                          <figure class="it-pf-photo">
                              <figcaption>After</figcaption>
                              <img src="<?php echo htmlspecialchars($project['after_image_path']); ?>" alt="After: <?php echo htmlspecialchars($project['project_name']); ?>">
                          </figure>
                      <?php endif; ?>
                  </div>
              <?php endif; ?>

              <div class="it-pf-body">
                  <section aria-labelledby="pf-desc-h">
                      <h2 class="it-pf-h" id="pf-desc-h">Description</h2>
                      <?php if (!empty($project['description'])): ?>
                          <p class="it-pf-desc"><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
                      <?php else: ?>
                          <p class="it-pf-desc">No description yet. <a href="edit.php?id=<?php echo (int)$project['id']; ?>">Add one</a>.</p>
                      <?php endif; ?>

                      <?php if ($categories): ?>
                          <h2 class="it-pf-h">Categories</h2>
                          <ul class="it-pf-chips">
                              <?php foreach ($categories as $category): ?><li><?php echo htmlspecialchars((string)$category); ?></li><?php endforeach; ?>
                          </ul>
                      <?php endif; ?>
                      <?php if ($tags): ?>
                          <h2 class="it-pf-h">Tags</h2>
                          <ul class="it-pf-chips">
                              <?php foreach ($tags as $tag): ?><li><?php echo htmlspecialchars((string)$tag); ?></li><?php endforeach; ?>
                          </ul>
                      <?php endif; ?>
                  </section>

                  <section aria-labelledby="pf-det-h">
                      <h2 class="it-pf-h" id="pf-det-h">Details</h2>
                      <dl class="it-pf-dl">
                          <div><dt>Status</dt><dd><?php echo $isLive ? 'Published' : 'Draft'; ?></dd></div>
                          <div><dt>Location</dt><dd><?php echo htmlspecialchars($project['location'] ?: 'Not set'); ?></dd></div>
                          <div><dt>Featured</dt><dd><?php echo $project['featured'] ? 'Yes' : 'No'; ?></dd></div>
                          <div><dt>Display order</dt><dd><?php echo intval($project['display_order']); ?></dd></div>
                          <div><dt>Created</dt><dd><?php echo formatDate($project['created_at'], 'M j, Y g:i A'); ?></dd></div>
                          <div><dt>Last updated</dt><dd><?php echo formatDate($project['updated_at'], 'M j, Y g:i A'); ?></dd></div>
                      </dl>
                  </section>
              </div>

              <footer class="it-pf-foot">
                  <button type="button" class="it-pf-btn it-pf-btn--quiet" onclick="deleteProject(<?php echo (int)$project['id']; ?>)">Delete this project</button>
              </footer>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<script>
function setProjectStatus(projectId, status) {
    var msg = status === 'published'
        ? 'Publish this project? It will be visible to everyone on your public portfolio page.'
        : 'Unpublish this project? It will be hidden from your public portfolio page.';
    if (!confirm(msg)) { return; }
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = 'status.php';
    [['id', projectId], ['status', status], ['csrf_token', window.MW_CSRF_TOKEN || '']].forEach(function (kv) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
        f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
}

function deleteProject(projectId) {
    if (confirm('Are you sure you want to delete this project? This action cannot be undone.')) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = 'delete.php';
        [['id', projectId], ['csrf_token', window.MW_CSRF_TOKEN || '']].forEach(function (kv) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = kv[0]; i.value = kv[1];
            f.appendChild(i);
        });
        document.body.appendChild(f);
        f.submit();
    }
}
</script>
