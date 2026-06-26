<?php
declare(strict_types=1);

session_start();
$cfg = require __DIR__ . '/config.php';

function fb_get_json(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [$code ?: 0, json_encode(['curl_error' => $err]), ['curl_error' => $err]];
    }

    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!is_array($data)) $data = [];

    return [$code, (string)$resp, $data];
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function get_granted_permissions(array $permData): array {
    $out = [];
    foreach (($permData['data'] ?? []) as $row) {
        if (($row['status'] ?? '') === 'granted' && !empty($row['permission'])) {
            $out[] = (string)$row['permission'];
        }
    }
    sort($out);
    return $out;
}

if (isset($_GET['error'])) {
    $msg = $_GET['error_description'] ?? $_GET['error'] ?? 'Unknown error';
    http_response_code(400);
    die('<h2>Authorization Failed</h2><p>' . h((string)$msg) . '</p>');
}

$short_token = $_GET['access_token'] ?? null;
if (!$short_token) {
    http_response_code(400);
    die('<h2>Error</h2><p>Missing access token. Please restart from index.php.</p>');
}

$appId     = $cfg['meta']['app_id'] ?? null;
$appSecret = $cfg['meta']['app_secret'] ?? null;
if (!$appId || !$appSecret) {
    http_response_code(500);
    die('<h2>Server Error</h2><p>Missing meta.app_id or meta.app_secret in config.php</p>');
}


$exchange_url = "https://graph.facebook.com/v24.0/oauth/access_token?"
    . "grant_type=fb_exchange_token"
    . "&client_id=" . urlencode((string)$appId)
    . "&client_secret=" . urlencode((string)$appSecret)
    . "&fb_exchange_token=" . urlencode((string)$short_token);

[$ex_status, $long_raw, $long_data] = fb_get_json($exchange_url);

if (!isset($long_data['access_token'])) {
    http_response_code(400);
    die('<h2>Error Getting Long-Lived Token</h2><p>HTTP: ' . h((string)$ex_status) . '</p><pre>' . h($long_raw) . '</pre>');
}
$user_long_token = (string)$long_data['access_token'];


$perm_url = "https://graph.facebook.com/v24.0/me/permissions?access_token=" . urlencode($user_long_token);
[, , $p_data] = fb_get_json($perm_url);
$granted = get_granted_permissions($p_data);


$me_url = "https://graph.facebook.com/v24.0/me?"
    . "fields=id,name,picture.type(large){url,is_silhouette}"
    . "&access_token=" . urlencode($user_long_token);

[, $me_raw, $me_data] = fb_get_json($me_url);

$fb_user_id   = (string)($me_data['id'] ?? '');
$fb_user_name = (string)($me_data['name'] ?? '');
$fb_user_pic  = $me_data['picture']['data']['url'] ?? null;


$accounts_url = "https://graph.facebook.com/v24.0/me/accounts?"
    . "fields=id,name,access_token,instagram_business_account,picture.type(square){url}"
    . "&access_token=" . urlencode($user_long_token);

[$acc_status, $acc_raw, $acc_data] = fb_get_json($accounts_url);
$pages = (is_array($acc_data['data'] ?? null)) ? $acc_data['data'] : [];

if (count($pages) === 0) {
    http_response_code(400);

    $need = ['pages_show_list', 'pages_manage_metadata'];
    $missing = array_values(array_diff($need, $granted));

    echo "<h1>Discovery Error</h1>";
    echo "<p><b>/me/accounts</b> returned empty array (HTTP " . h((string)$acc_status) . ").</p>";
    echo "<h3>Logged in Facebook user</h3><pre>" . h($me_raw) . "</pre>";
    echo "<h3>Granted permissions</h3><pre>" . h(json_encode($granted, JSON_PRETTY_PRINT)) . "</pre>";

    if (!empty($missing)) {
        echo "<h3 style='color:#b00'>Missing required permission(s)</h3>";
        echo "<pre>" . h(json_encode($missing, JSON_PRETTY_PRINT)) . "</pre>";
        echo "<p><b>Fix:</b> add these to your login scopes, then force re-consent.</p>";
    }

    echo "<h3>/me/accounts raw</h3><pre>" . h($acc_raw) . "</pre>";
    exit;
}


$pages_out = [];
foreach ($pages as $p) {
    $page_id = (string)($p['id'] ?? '');
    if ($page_id === '') continue;

    $page_pic = $p['picture']['data']['url'] ?? null;

    $pages_out[] = [
        'id'           => $page_id,
        'name'         => (string)($p['name'] ?? ''),
        'access_token' => (string)($p['access_token'] ?? ''),
        'picture'      => $page_pic,
        'ig_business_id' => $p['instagram_business_account']['id'] ?? null,
    ];
}


$default_page_id = $pages_out[0]['id'] ?? null;
foreach ($pages_out as $pp) {
    if (!empty($pp['ig_business_id'])) {
        $default_page_id = $pp['id'];
        break;
    }
}

$tokens = [
    'fb_user' => [
        'id'      => $fb_user_id,
        'name'    => $fb_user_name,
        'picture' => $fb_user_pic,
    ],
    'pages' => $pages_out,
    'selected_page_id' => $default_page_id,
    'updated_at' => date('Y-m-d H:i:s'),
];

file_put_contents(__DIR__ . '/tokens.json', json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Connection Successful</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
  <div class="card shadow text-center">
    <div class="card-body">
      <h1 class="text-success">Setup Complete!</h1>
      <p>Saved to <code>tokens.json</code>.</p>
      <ul class="list-group text-left mb-4">
        <li class="list-group-item"><strong>User:</strong> <?= h((string)$tokens['fb_user']['name']) ?></li>
        <li class="list-group-item"><strong>User pic saved:</strong> <?= $tokens['fb_user']['picture'] ? 'Yes' : 'No' ?></li>
        <li class="list-group-item"><strong>Pages saved:</strong> <?= h((string)count($tokens['pages'])) ?></li>
      </ul>
      <a href="index.php" class="btn btn-primary">Return Home</a>
    </div>
  </div>
</div>
</body>
</html>
