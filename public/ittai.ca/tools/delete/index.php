<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_inc/action-bootstrap.php';
define('TOOLS_REFERRER_POLICY', 'no-referrer');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$token = (string) ($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$db = Database::platform();
$service = $db ? new SavedNumbersService($db) : null;
$row = $service ? $service->findDelete($token) : null;
$deleted = $isPost && validPost() && $row ? $service->delete($token) : false;

toolsStart('Delete saved numbers | Ittai', 'Delete your saved calculator numbers.', 'https://ittai.ca/tools/delete/', 'Delete saved numbers');
?>
<header class="hero">
    <h1><?= $deleted ? 'Deleted' : 'Delete my saved numbers?' ?></h1>
</header>
<?php if ($deleted): ?>
    <p>Deleted. Nothing of yours is kept.</p>
<?php elseif ($row): ?>
    <form method="post">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <button type="submit">Delete my saved numbers</button>
    </form>
<?php else: ?>
    <p>This link is invalid or has expired.</p>
<?php endif; ?>
<?php toolsEnd(); ?>
