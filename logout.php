<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
session_unset();
session_destroy();
session_start();
$_SESSION['flash'][] = ['success','Abgemeldet.'];
header('Location: index.php');
