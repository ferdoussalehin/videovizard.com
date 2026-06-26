<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Read action from POST body (JSON) or GET param
$bodyRaw = file_get_contents('php://input');
$bodyJson = json_decode($bodyRaw, true) ?? [];
$action = $bodyJson['action'] ?? $_GET['action'] ?? '';

// ── Upload: done entirely server-side to avoid client DNS issues ──────────────
if ($action === 'upload') {
    $body     = $bodyJson; // already parsed above — do NOT re-read php://input (stream is consumed)
    $base64   = $body['base64']     ?? '';
    $mimeType = $body['mime_type']  ?? 'image/jpeg';
    $fileName = $body['file_name']  ?? 'source.jpg';

    if (!$base64) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing base64 data']);
        exit;
    }

    $fileBytes = base64_decode($base64);
    if ($fileBytes === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid base64 data']);
        exit;
    }

    // Step 1: get upload token from fal
    $tokenUrl = 'https://rest.alpha.fal.ai/storage/auth/token?storage_type=fal-cdn-v3';
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Key ' . $falApiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $tokenRes  = curl_exec($ch);
    $tokenCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tokenErr  = curl_error($ch);
    curl_close($ch);

    if ($tokenErr || $tokenCode !== 200) {
        http_response_code(502);
        echo json_encode(['error' => 'Token fetch failed', 'detail' => $tokenErr ?: $tokenRes]);
        exit;
    }

    $tokenData = json_decode($tokenRes, true);
    $token    = $tokenData['token']    ?? '';
    $baseUrl  = $tokenData['base_url'] ?? '';

    if (!$token || !$baseUrl) {
        http_response_code(502);
        echo json_encode(['error' => 'Invalid token response', 'detail' => $tokenRes]);
        exit;
    }

    // Step 2: upload file bytes
    $uploadUrl = rtrim($baseUrl, '/') . '/files/upload?file_name=' . urlencode($fileName);
    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileBytes);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: ' . $mimeType,
        'Content-Length: ' . strlen($fileBytes),
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $uploadRes  = curl_exec($ch);
    $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $uploadErr  = curl_error($ch);
    curl_close($ch);

    if ($uploadErr || $uploadCode >= 400) {
        http_response_code(502);
        echo json_encode(['error' => 'Upload failed', 'http' => $uploadCode, 'detail' => $uploadErr ?: $uploadRes]);
        exit;
    }

    $uploadData = json_decode($uploadRes, true);
    $fileUrl = $uploadData['access_url'] ?? $uploadData['url'] ?? '';

    if (!$fileUrl) {
        http_response_code(502);
        echo json_encode(['error' => 'No URL in upload response', 'detail' => $uploadRes]);
        exit;
    }

    echo json_encode(['file_url' => $fileUrl]);
    exit;
}

// ── Status polling ────────────────────────────────────────────────────────────
if ($action === 'status') {
    $model = $bodyJson['model'] ?? $_GET['model'] ?? '';
    $rid   = $bodyJson['rid']   ?? $_GET['rid']   ?? '';
    $url   = "https://queue.fal.run/{$model}/requests/{$rid}/status?logs=1";
    falRequest('GET', $url, '');
    exit;
}

// ── Result fetch ──────────────────────────────────────────────────────────────
if ($action === 'result') {
    $model = $bodyJson['model'] ?? $_GET['model'] ?? '';
    $rid   = $bodyJson['rid']   ?? $_GET['rid']   ?? '';
    $url   = "https://queue.fal.run/{$model}/requests/{$rid}";
    falRequest('GET', $url, '');
    exit;
}

// ── Generic passthrough (submit) ──────────────────────────────────────────────
$falPath = $_GET['path'] ?? '';
if (!$falPath) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing path or action parameter']);
    exit;
}
$baseUrl = (strpos($falPath, 'storage/') === 0)
    ? 'https://fal.run/'
    : 'https://queue.fal.run/';
$url  = $baseUrl . ltrim($falPath, '/');
falRequest($_SERVER['REQUEST_METHOD'], $url, $bodyRaw);

// ── Shared cURL helper ────────────────────────────────────────────────────────
function falRequest($method, $url, $body) {
    global $falApiKey;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Key ' . $falApiKey,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if (in_array($method, ['POST', 'PUT']) && $body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        http_response_code(502);
        echo json_encode(['error' => 'Proxy curl error: ' . $curlErr, 'target_url' => $url]);
        exit;
    }
    http_response_code($httpCode);
    echo $response;
}
