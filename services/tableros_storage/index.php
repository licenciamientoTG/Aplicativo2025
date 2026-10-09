<?php
declare(strict_types=1);

// Standalone IIS application: publish under a dedicated pool identity.
header('Cache-Control: no-store');

function respond(int $status, string $message = ''): never {
    http_response_code($status);
    if ($message !== '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    }
    exit;
}

function pathIsWithin(string $path, string $root): bool {
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

$expectedToken = (string)getenv('TABLEROS_STORAGE_SERVICE_TOKEN');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (strlen($expectedToken) < 32 || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
    || !hash_equals($expectedToken, trim($matches[1]))) respond(401, 'Unauthorized');

$key = $_GET['key'] ?? '';
$legacy = is_string($key) && preg_match('/^[a-f0-9]{64}$/', $key);
$hierarchical = is_string($key) && preg_match('~^[1-9][0-9]*/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}$~', $key);
$sticker = is_string($key) && preg_match('~^stickers/[1-9][0-9]*/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{2}/[a-f0-9]{64}$~', $key);
if (!$legacy && !$hierarchical && !$sticker) respond(400, 'Invalid object key');

$root = trim((string)getenv('TABLEROS_STORAGE_ROOT'));
if ($root === '' || !str_starts_with($root, '\\\\')) respond(500, 'Storage root is not configured');
$root = rtrim($root, '\\/');
$canonicalRoot = realpath($root);
if ($canonicalRoot === false || !is_dir($canonicalRoot)) respond(500, 'Storage root is unavailable');
$relative = $legacy ? substr($key, 0, 2) . DIRECTORY_SEPARATOR . $key : str_replace('/', DIRECTORY_SEPARATOR, $key);
$target = $canonicalRoot . DIRECTORY_SEPARATOR . $relative;
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'PUT') {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 1 || $length > 100 * 1024 * 1024) respond(413, 'Upload must be between 1 byte and 100 MB');
    $directory = dirname($target);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) respond(500, 'Cannot create storage directory');
    $canonicalDirectory = realpath($directory);
    if ($canonicalDirectory === false || !pathIsWithin($canonicalDirectory, $canonicalRoot)) respond(400, 'Invalid storage path');
    $existingTarget = realpath($target);
    if ($existingTarget !== false && !pathIsWithin($existingTarget, $canonicalRoot)) respond(400, 'Invalid storage path');
    $target = $canonicalDirectory . DIRECTORY_SEPARATOR . basename($target);
    $temp = false;
    $destination = false;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $candidate = $canonicalDirectory . DIRECTORY_SEPARATOR . '.tableros-' . bin2hex(random_bytes(16));
        $destination = @fopen($candidate, 'xb');
        if (is_resource($destination)) {
            $temp = $candidate;
            break;
        }
    }
    if ($temp === false) respond(500, 'Cannot prepare storage write');
    $source = fopen('php://input', 'rb');
    if (!is_resource($source) || !is_resource($destination)) {
        if (is_resource($source)) fclose($source);
        if (is_resource($destination)) fclose($destination);
        @unlink($temp);
        respond(500, 'Cannot open storage stream');
    }
    $written = stream_copy_to_stream($source, $destination, 100 * 1024 * 1024 + 1);
    fclose($source);
    fclose($destination);
    if ($written !== $length || $written > 100 * 1024 * 1024) { @unlink($temp); respond(400, 'Upload length mismatch'); }
    if (file_exists($target) || !@rename($temp, $target)) { @unlink($temp); respond(409, 'Object already exists or cannot be committed'); }
    respond(201);
}

if ($method === 'GET') {
    $realTarget = realpath($target);
    if ($realTarget === false || !pathIsWithin($realTarget, $canonicalRoot) || !is_file($realTarget) || !is_readable($realTarget)) respond(404, 'Object not found');
    $target = $realTarget;
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string)filesize($target));
    readfile($target);
    exit;
}

if ($method === 'DELETE') {
    $realTarget = realpath($target);
    if ($realTarget === false) respond(204);
    if (!pathIsWithin($realTarget, $canonicalRoot)) respond(400, 'Invalid storage path');
    if (!is_file($realTarget) || !@unlink($realTarget)) respond(500, 'Cannot delete object');
    respond(204);
}

header('Allow: GET, PUT, DELETE');
respond(405, 'Method not allowed');
