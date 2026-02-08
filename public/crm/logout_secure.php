<?php
require_once __DIR__ . '/../loginAuth/auth.php';

logoutUser();

header('Location: login_secure.php');
exit();
?>
