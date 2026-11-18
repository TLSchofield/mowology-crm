<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_inc/action-bootstrap.php';
define('TOOLS_REFERRER_POLICY', 'no-referrer');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$token = (string) ($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$db = Database::platform();
$service = $db ? new SavedNumbersService($db) : null;
$row = $service ? $service->findConfirm($token) : null;
$kept = $isPost && validPost() && $row ? $service->keep($token) : false;

toolsStart('Keep saved numbers | Ittai', 'Keep your saved calculator numbers.', 'https://ittai.ca/tools/keep/', 'Keep saved numbers');
?>
<header class="hero">
    <h1><?= $kept ? 'Kept' : 'Keep my saved numbers?' ?></h1>
</header>
<?php if ($kept): ?>
    <p>Your saved numbers will be kept.</p>
<?php elseif ($row): ?>
    <form method="post">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <button type="submit">Keep my saved numbers</button>
    </form>
<?php else: ?>
    <p>This link is invalid or has expired.</p>
<?php endif; ?>
<?php toolsEnd(); ?>
