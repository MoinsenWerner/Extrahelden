<?php
$file = '/usr/share/icons/cecolor/linkedhearts.txt';

if (!file_exists($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/txt');
header('Content-Disposition: attachment; filename="linkedhearts.txt"');
header('Content-Length: ' . filesize($file));

readfile($file);
exit;
