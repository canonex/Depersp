<?php

declare(strict_types=1);

/*
 * Example upload endpoint that never stores images.
 *
 * The photo is read from PHP's temporary upload file (which PHP deletes
 * automatically at the end of the request), rectified in memory and sent
 * straight back. Nothing is written to disk by this script.
 *
 *   POST upload.php   multipart/form-data, field "image"
 *   200  the rectified JPEG (Content-Type: image/jpeg)
 *        headers X-Depersp-Width / X-Depersp-Height
 *   4xx/5xx  JSON {"ok":false,"error":"…"}
 *
 * Try it:  php -S 127.0.0.1:8000 -t examples/upload
 */

use Depersp\DetectionException;
use Depersp\InvalidImageException;
use Depersp\Rectifier;

// With Composer: require 'vendor/autoload.php';
require __DIR__ . '/../../src/autoload.php';

const MAX_BYTES = 15 * 1024 * 1024;

function fail(int $status, string $error, string $message = ''): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail(405, 'method_not_allowed');
}

$file = $_FILES['image'] ?? null;
if (!is_array($file) || is_array($file['error'])) {
    fail(400, 'missing_file');
}
if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
    fail(413, 'too_large');
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    fail(400, 'upload_failed');
}
if ($file['size'] > MAX_BYTES) {
    fail(413, 'too_large');
}

$bytes = file_get_contents($file['tmp_name']);
@unlink($file['tmp_name']); // don't even wait for the end of the request
if ($bytes === false) {
    fail(400, 'upload_failed');
}

Rectifier::applyResourceLimits();

try {
    $result = (new Rectifier(maxFileBytes: MAX_BYTES))->rectifyBlob($bytes, 'jpg');
} catch (InvalidImageException $e) {
    fail(415, 'invalid_image', $e->getMessage());
} catch (DetectionException $e) {
    fail(422, 'no_frame', 'Frame not found: make sure all four corners are in the photo.');
} catch (Throwable $e) {
    error_log('depersp: ' . $e->getMessage());
    fail(500, 'server_error');
}
unset($bytes);

header('Content-Type: ' . $result->mimeType);
header('Content-Length: ' . strlen($result->data));
header('Content-Disposition: inline; filename="rectified.jpg"');
header('X-Depersp-Width: ' . $result->width);
header('X-Depersp-Height: ' . $result->height);
echo $result->data;
