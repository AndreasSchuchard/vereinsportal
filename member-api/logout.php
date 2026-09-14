<?php
declare(strict_types=1);
require_once __DIR__ . '/_security.php';
session_start();
unset($_SESSION['kgv_member'], $_SESSION['member_last_activity']);
session_regenerate_id(true);
header('Location: /mitglieder.php');
exit;
