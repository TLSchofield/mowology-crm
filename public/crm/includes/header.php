<?php
if (!function_exists('h')) {
  function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?>

<link rel="stylesheet" href="css/dashboard.css">


<header class="topbar">
  <div class="topbar__left">
    <a href="/crm/dashboard.php" class="brand">Mowology CRM</a>
  </div>

  <div class="topbar__right">
    <span class="user">
      <?= h($user['email'] ?? 'User') ?> (<?= h($user['role'] ?? '') ?>)
    </span>
    <form method="post" action="/loginAuth/logout.php" style="display:inline;">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <button type="submit">Logout</button>
    </form>
  </div>
</header>
