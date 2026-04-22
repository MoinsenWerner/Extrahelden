<?php
// download.php – erlaubt: public ODER (eingeloggt & zugewiesen) ODER Admin
declare(strict_types=1);
require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Not found'); }

$st = db()->prepare('SELECT id, filename, path, COALESCE(is_public,0) AS is_public FROM documents WHERE id=?');
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc || empty($doc['path']) || !is_file($doc['path'])) {
    http_response_code(404); exit('Not found');
}

$uid      = (int)($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

$allowed = false;
if ((int)$doc['is_public'] === 1) {
    $allowed = true; // öffentlich
} elseif ($uid > 0) {
    if ($is_admin) {
        $allowed = true;
    } else {
        $chk = db()->prepare('SELECT 1 FROM user_documents WHERE user_id=? AND document_id=?');
        $chk->execute([$uid, $id]);
        $allowed = (bool)$chk->fetchColumn();
    }
}

if (!$allowed) { http_response_code(403); exit('Forbidden'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.basename((string)$doc['filename']).'"');
header('Content-Length: '.filesize($doc['path']));
readfile($doc['path']);
exit;
