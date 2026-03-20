<?php
// Serves a converted ZIP by download ID, then cleans up
if (!isset($_GET['id']) || !preg_match('/^[a-f0-9]+$/', $_GET['id'])) {
    http_response_code(400);
    echo 'Invalid download ID';
    exit;
}

$downloadPath = sys_get_temp_dir() . '/heic_download_' . $_GET['id'] . '.zip';

if (!file_exists($downloadPath)) {
    http_response_code(404);
    echo 'Download expired or not found';
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="converted_images.zip"');
header('Content-Length: ' . filesize($downloadPath));
readfile($downloadPath);
unlink($downloadPath);
