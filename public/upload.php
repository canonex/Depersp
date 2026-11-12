<?php

declare(strict_types=1);

/*
 * Example upload endpoint.
 *
 *   POST /upload.php   multipart/form-data, field "image"
 *   200 {"ok":true,"id":"…","rectified":"rectified/<id>.jpg","width":…,"height":…}
 *   422 {"ok":false,"error":"no_frame", …}   photo kept, but no frame found
 *   400/413/415/500 {"ok":false,"error":"…"}
 *
 * Only this "public" directory should be the web root. Uploaded originals go
 * to a directory that is NOT served; rectified images go to public/rectified/.
 */

use Depersp\DetectionException;
use Depersp\InvalidImageException;
use Depersp\Rectifier;

require __DIR__ . '/../src/autoload.php';

// ---- configuration --------------------------------------------------------
const UPLOAD_DIR = __DIR__ . '/../storage/uploads';   // not web-accessible
const OUTPUT_DIR = __DIR__ . '/rectified';            // web-accessible
const OUTPUT_URL = 'rectified';
const MAX_BYTES = 15 * 1024 * 1024;
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$file = $_FILES['image'] ?? null;
if (!is_array($file) || is_array($file['error'])) {
    reply(400, ['ok' => false, 'error' => 'missing_file']);
}
switch ($file['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        reply(413, ['ok' => false, 'error' => 'too_large']);
    default:
        reply(400, ['ok' => false, 'error' => 'upload_failed']);
}
if ($file['size'] > MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
    reply(413, ['ok' => false, 'error' => 'too_large']);
}

foreach ([UPLOAD_DIR, OUTPUT_DIR] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log("depersp: cannot create $dir");
        reply(500, ['ok' => false, 'error' => 'server_error']);
    }
}

Rectifier::applyResourceLimits();
$rectifier = new Rectifier(maxFileBytes: MAX_BYTES);

// Never trust the client's file name: use a random id.
$id = bin2hex(random_bytes(16));
$original = UPLOAD_DIR . '/' . $id;
if (!move_uploaded_file($file['tmp_name'], $original)) {
    reply(500, ['ok' => false, 'error' => 'server_error']);
}

try {
    $result = $rectifier->rectifyFile($original, OUTPUT_DIR . '/' . $id . '.jpg');
} catch (InvalidImageException $e) {
    @unlink($original);
    reply(415, ['ok' => false, 'error' => 'invalid_image', 'message' => $e->getMessage()]);
} catch (DetectionException $e) {
    // Keep the original so it can be reviewed or straightened by hand.
    reply(422, ['ok' => false, 'error' => 'no_frame', 'id' => $id, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('depersp: ' . $e);
    reply(500, ['ok' => false, 'error' => 'server_error']);
}

reply(200, [
    'ok' => true,
    'id' => $id,
    'rectified' => OUTPUT_URL . '/' . basename($result->outputPath),
    'width' => $result->width,
    'height' => $result->height,
    'seconds' => round($result->seconds, 3),
]);
