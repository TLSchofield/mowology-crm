<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_inc/action-bootstrap.php';
define('TOOLS_REFERRER_POLICY', 'no-referrer');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$token = (string) ($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));
$db = Database::platform();
$service = $db ? new SavedNumbersService($db) : null;
$row = $service ? $service->findConfirm($token) : null;
$done = null;
if ($isPost && validPost() && $row) {
    $news = isset($_POST['news']) && $_POST['news'] === '1';
    $benchmark = isset($_POST['benchmark']) && $_POST['benchmark'] === '1';
    $done = $service->confirm($token, $news, $benchmark);
    if ($done) {
        try {
            (new ToolUsageCounter($db))->increment($done['tool'], 'saves_confirmed');
        } catch (Throwable $exception) {
        }
    }
}

toolsStart(
    'Confirm saved numbers | Ittai',
    'Confirm your saved calculator numbers.',
    'https://ittai.ca/tools/confirm/',
    'Confirm saved numbers'
);
?>
<header class="hero">
    <h1><?= $done ? 'Saved' : 'Confirm and save' ?></h1>
</header>
<?php if ($done): ?>
    <p>Your numbers are saved. The link to delete them is in the email we sent you.</p>
<?php elseif ($row && $row['status'] === 'pending'): ?>
    <?php
    $inputs = json_decode((string) $row['inputs_json'], true);
    $labels = ToolCalculator::labels((string) $row['tool']);
    ?>
    <h2><?= h(ucwords(str_replace('-', ' ', (string) $row['tool']))) ?></h2>
    <ul>
        <?php foreach ((array) $inputs as $key => $value): ?>
            <?php if (isset($labels[$key])): ?>
                <?php [$label, $unit] = $labels[$key]; ?>
                <li>
                    <?= h($label) ?>:
                    <?php if ($unit === '$'): ?>
                        $<?= h(number_format((float) $value, 2)) ?>
                    <?php elseif ($unit === '%'): ?>
                        <?= h((string) $value) ?>%
                    <?php elseif ($unit !== ''): ?>
                        <?= h((string) $value) ?> <?= h($unit) ?>
                    <?php else: ?>
                        <?= h($key === 'mode' ? ucfirst((string) $value) : (string) $value) ?>
                    <?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
    <p>You're saving these numbers so you can come back to them.</p>
    <form method="post">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <label class="check">
            <input type="checkbox" name="news" value="1">
            <span><?= h(SavedNumbersService::NEWS_TEXT) ?></span>
        </label>
        <label class="check">
            <input type="checkbox" name="benchmark" value="1">
            <span><?= h(SavedNumbersService::BENCHMARK_TEXT) ?></span>
        </label>
        <button type="submit">Confirm and save</button>
    </form>
<?php else: ?>
    <p>This link is invalid or has expired.</p>
<?php endif; ?>
<?php toolsEnd(); ?>
