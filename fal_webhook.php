<?php
// fal_webhook.php — fal.ai async video result receiver
//
// cron_video_gen.php submits video jobs to queue.fal.run with
//   ?fal_webhook=https://videovizard.com/fal_webhook.php?token=...&que_id=<id>
// fal.ai POSTs the finished result here. We authenticate, store the result
// video URL on the queue row and flip it to flag=5 ("result ready"); the next
// cron run downloads + ingests it. We do NOT do the heavy lifting here so the
// webhook always responds fast (fal.ai retries non-2xx / slow responses).
//
// fal.ai payload (POST body):
//   { "request_id":"...", "gateway_request_id":"...", "status":"OK"|"ERROR",
//     "payload": { "video": { "url": "https://..." } }, "error": null }
//
// hdb_video_gen_que.videogen_flag transitions handled here: 6 -> 5 (ok)
//                                                           6 -> 1/4 (error)

// config first (constants), dbconnect_hdb.php LAST so $conn points at the
// correct DB (config.php can otherwise leave $conn on a stale connection).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dbconnect_hdb.php';

function fw_log($msg) {
    $ts = date('Y-m-d H:i:s');
    error_log("[$ts] [fal_webhook] $msg" . PHP_EOL, 3, __DIR__ . '/fal_webhook.log');
}

// ── Read raw body BEFORE anything else ───────────────────────────────────────
$raw    = @file_get_contents('php://input');
$que_id = (int)($_GET['que_id'] ?? 0);

// ── 1) Shared-secret token check (primary auth) ──────────────────────────────
$token    = $_GET['token'] ?? '';
$expected = defined('FAL_WEBHOOK_TOKEN') ? FAL_WEBHOOK_TOKEN : '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    fw_log("REJECT bad/missing token | que_id=$que_id ip=" . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit('forbidden');
}

// ── 2) Optional ED25519 signature verification (defense-in-depth) ────────────
if (defined('FAL_WEBHOOK_VERIFY_SIGNATURE') && FAL_WEBHOOK_VERIFY_SIGNATURE) {
    if (!fal_verify_signature($raw)) {
        http_response_code(401);
        fw_log("REJECT bad ED25519 signature | que_id=$que_id");
        exit('bad signature');
    }
}

// ── 3) Parse payload ─────────────────────────────────────────────────────────
$data       = json_decode($raw, true) ?: [];
$request_id = $data['request_id'] ?? ($_GET['request_id'] ?? '');
$status     = strtoupper($data['status'] ?? '');
fw_log("HIT que_id=$que_id request_id=$request_id status=$status body_len=" . strlen($raw));

// ── 4) Locate the queue row (by que_id from URL; verify request_id if present) ─
if ($que_id <= 0) {
    // Fallback: match by request_id only
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_video_gen_que WHERE request_id='" . mysqli_real_escape_string($conn, $request_id) . "' LIMIT 1"));
} else {
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_video_gen_que WHERE id=$que_id LIMIT 1"));
}

if (!$row) {
    // Unknown row — ack with 200 so fal.ai stops retrying.
    http_response_code(200);
    fw_log("NO MATCH que_id=$que_id request_id=$request_id — acking");
    exit('no match');
}
$que_id = (int)$row['id'];

// Idempotency: only a row still awaiting the webhook (flag=6) should be moved.
if ((int)$row['videogen_flag'] !== 6) {
    http_response_code(200);
    fw_log("IGNORE que_id=$que_id already flag=" . $row['videogen_flag'] . " (duplicate delivery?) — acking");
    exit('already handled');
}

// ── 5) Apply result ──────────────────────────────────────────────────────────
if ($status === 'OK' || $status === '' || $status === 'COMPLETED') {
    $payload   = $data['payload'] ?? [];
    $video_url = $payload['video']['url'] ?? $payload['video_url'] ?? ($payload['url'] ?? '');

    if ($video_url === '') {
        // Success status but no URL — treat as failure so the cron can retry.
        $rc = (int)$row['retry_count'] + 1;
        if ($rc >= 3) {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$rc,
                error_msg='webhook OK but no video url (3x)', updated_at=NOW() WHERE id=$que_id");
            fw_log("que_id=$que_id OK but no video url x$rc — error (flag=4)");
        } else {
            mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, retry_count=$rc, updated_at=NOW() WHERE id=$que_id");
            fw_log("que_id=$que_id OK but no video url (attempt $rc/3) — re-queue (flag=1)");
        }
        http_response_code(200);
        exit('no url');
    }

    $url_esc = mysqli_real_escape_string($conn, $video_url);
    $rid_esc = mysqli_real_escape_string($conn, $request_id);
    mysqli_query($conn, "UPDATE hdb_video_gen_que
        SET videogen_flag=5, result_video_url='$url_esc', request_id='$rid_esc',
            status='result_ready', error_msg=NULL, updated_at=NOW()
        WHERE id=$que_id AND videogen_flag=6");
    fw_log("que_id=$que_id OK -> flag=5 (result ready) url=$video_url");
    fw_kick_cron(); // nudge cron to download+ingest now (no per-minute crontab needed)
    http_response_code(200);
    exit('ok');
}

// status = ERROR (or anything else)
$err = $data['error'] ?? ($data['payload']['detail'] ?? ($data['detail'] ?? 'fal.ai reported ERROR'));
if (is_array($err)) $err = json_encode($err);
$rc = (int)$row['retry_count'] + 1;
$em = mysqli_real_escape_string($conn, substr((string)$err, 0, 1000));
if ($rc >= 3) {
    mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=4, retry_count=$rc, error_msg='$em', updated_at=NOW() WHERE id=$que_id");
    fw_log("que_id=$que_id ERROR x$rc — error (flag=4) | $err");
} else {
    mysqli_query($conn, "UPDATE hdb_video_gen_que SET videogen_flag=1, retry_count=$rc, error_msg='$em', updated_at=NOW() WHERE id=$que_id");
    fw_log("que_id=$que_id ERROR (attempt $rc/3) — re-queue (flag=1) | $err");
}
http_response_code(200);
exit('error recorded');


// ============================================================================
// Spawn a background cron run so the just-readied video gets downloaded +
// ingested promptly, without depending on a per-minute system crontab. A
// lockfile throttles this so a burst of webhooks can't fork a storm of
// processes — the cron's own flag=5->7 claim already prevents double-work.
// ============================================================================
function fw_kick_cron() {
    $lock = __DIR__ . '/fal_webhook_kick.lock';
    if (file_exists($lock) && (time() - filemtime($lock)) < 90) return; // recently kicked
    @touch($lock);
    $php_bin = file_exists('/usr/bin/php') ? '/usr/bin/php'
             : (file_exists('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php');
    $setsid  = file_exists('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
    $script  = escapeshellarg(__DIR__ . '/cron_video_gen.php');
    $log     = escapeshellarg(__DIR__ . '/video_generation.log');
    @shell_exec("{$setsid}{$php_bin} -d error_reporting=0 -d display_errors=0 {$script} >> {$log} 2>&1 < /dev/null &");
    fw_log('kicked background cron run');
}

// ============================================================================
// fal.ai ED25519 webhook signature verification
// Docs: message = "{request_id}\n{user_id}\n{timestamp}\n{sha256_hex(body)}"
// signed with one of the ED25519 keys published at fal's JWKS endpoint.
// ============================================================================
function fal_verify_signature($body) {
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        fw_log('libsodium not available — cannot verify signature');
        return false;
    }
    $request_id = $_SERVER['HTTP_X_FAL_WEBHOOK_REQUEST_ID'] ?? '';
    $user_id    = $_SERVER['HTTP_X_FAL_WEBHOOK_USER_ID']    ?? '';
    $timestamp  = $_SERVER['HTTP_X_FAL_WEBHOOK_TIMESTAMP']  ?? '';
    $sig_hex    = $_SERVER['HTTP_X_FAL_WEBHOOK_SIGNATURE']  ?? '';
    if ($request_id === '' || $user_id === '' || $timestamp === '' || $sig_hex === '') return false;

    // Reject stale callbacks (replay protection) — 5 min tolerance.
    if (abs(time() - (int)$timestamp) > 300) { fw_log('signature timestamp out of tolerance'); return false; }

    $message = $request_id . "\n" . $user_id . "\n" . $timestamp . "\n" . hash('sha256', $body);
    $sig     = @hex2bin($sig_hex);
    if ($sig === false || strlen($sig) !== 64) return false;

    foreach (fal_public_keys() as $pub) {
        if (strlen($pub) === 32 && sodium_crypto_sign_verify_detached($sig, $message, $pub)) return true;
    }
    return false;
}

// Fetch + cache fal.ai's ED25519 public keys (JWKS). Cached 24h on disk.
function fal_public_keys() {
    $cache = __DIR__ . '/fal_jwks_cache.json';
    $jwks  = null;
    if (file_exists($cache) && (time() - filemtime($cache)) < 86400) {
        $jwks = json_decode(@file_get_contents($cache), true);
    }
    if (!$jwks) {
        $ch = curl_init('https://rest.alpha.fal.ai/.well-known/jwks.json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $resp) { $jwks = json_decode($resp, true); @file_put_contents($cache, $resp); }
    }
    $keys = [];
    foreach (($jwks['keys'] ?? []) as $k) {
        if (empty($k['x'])) continue;
        $raw = base64_decode(strtr($k['x'], '-_', '+/')); // base64url -> raw 32-byte ed25519 pubkey
        if ($raw !== false) $keys[] = $raw;
    }
    return $keys;
}
