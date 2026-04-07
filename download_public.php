<?php
// download_public.php – liefert nur Dateien, die in documents_public gelistet sind
declare(strict_types=1);
require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Bad request'; exit; }

$st = db()->prepare('
  SELECT d.filename, d.path
  FROM documents_public p
  JOIN documents d ON d.id = p.document_id
  WHERE d.id = ?
');
$st->execute([$id]);
$doc = $st->fetch();

if (!$doc) { http_response_code(404); echo 'Not found'; exit; }

$path = (string)($doc['path'] ?? '');
$name = (string)($doc['filename'] ?? 'download');

if (!is_file($path) || !is_readable($path)) { http_response_code(404); echo 'File missing'; exit; }

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.rawurlencode($name).'"');
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');

$fp = fopen($path, 'rb');
if ($fp) { fpassthru($fp); fclose($fp); }
exit;
