<?php
declare(strict_types=1);

$t = $_GET['t'] ?? 'light';
$r = $_GET['r'] ?? '/';

if (!in_array($t, ['light','dark'], true)) { $t = 'light'; }

$secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('theme', $t, [
    'expires'  => time() + 60*60*24*365,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (!is_string($r) || !preg_match('~^/[^:\s]*$~', $r)) { $r = '/'; }
header('Location: ' . $r);
exit;
