<?php
/**
 * facebook_post.php
 * 
 * Test + production Facebook video posting
 * Mirrors the YouTube posting pattern
 * 
 * Usage:
 *   Browser test:  facebook_post.php?test=1&podcast_id=123
 *   Called by:     cron_poster.php for auto-posting
 *   Called by:     vizard_browser.php modal for manual posting
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

require_once 'config.php';
include 'dbconnect_hdb.php';

session_set_cookie_params(15552000);
session_start();

$admin_id = (int)($_SESSION['admin_id'] ?? 0);

// ── Allow cron calls with no session ──────────────────────────────
$is_cron    = (php_sapi_name() === 'cli');
$is_test    = isset($_GET['test']);
$podcast_id = (int)($_GET['podcast_id'] ?? $_POST['podcast_id'] ?? 0);

if (!$is_cron && !$admin_id) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// ════════════════════════════════════════════════════════
// CORE FUNCTION — post video to a Facebook Page
// ════════════════════════════════════════════════════════
function postVideoToFacebook($page_id, $page_token, $video_path, $title, $description) {

    error_log("[FB Post] Starting upload: page=$page_id file=$video_path");

    // ── Step 1: Check video file exists ──────────────────
    if (!file_exists($video_path)) {
        error_log("[FB Post] ERROR: Video file not found: $video_path");
        return ['success' => false, 'error' => 'Video file not found: ' . basename($video_path)];
    }

    $file_size = filesize($video_path);
    error_log("[FB Post] File size: " . round($file_size / 1024 / 1024, 2) . " MB");

    // ── Step 2: Initialise resumable upload session ───────
    // Facebook requires initialisation for videos > 1MB
    $init_url = "https://graph-video.facebook.com/v18.0/{$page_id}/videos";

    $init_params = [
        'upload_phase'  => 'start',
        'file_size'     => $file_size,
        'access_token'  => $page_token,
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $init_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $init_params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $init_response = curl_exec($ch);
    $init_http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $init_err      = curl_error($ch);
    curl_close($ch);

    error_log("[FB Post] Init response HTTP=$init_http: $init_response");

    $init_data = json_decode($init_response, true);

    if ($init_http !== 200 || empty($init_data['upload_session_id'])) {
        $err = $init_data['error']['message'] ?? $init_err ?? 'Init failed';
        error_log("[FB Post] Init failed: $err");
        return ['success' => false, 'error' => 'Upload init failed: ' . $err];
    }

    $upload_session_id = $init_data['upload_session_id'];
    $video_id          = $init_data['video_id'];
    error_log("[FB Post] Upload session started: $upload_session_id video_id=$video_id");

    // ── Step 3: Transfer the video file ──────────────────
    $transfer_url = "https://graph-video.facebook.com/v18.0/{$page_id}/videos";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $transfer_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'upload_phase'      => 'transfer',
            'upload_session_id' => $upload_session_id,
            'start_offset'      => 0,
            'video_file_chunk'  => new CURLFile($video_path, 'video/mp4', basename($video_path)),
            'access_token'      => $page_token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300, // 5 min for large files
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $transfer_response = curl_exec($ch);
    $transfer_http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $transfer_err      = curl_error($ch);
    curl_close($ch);

    error_log("[FB Post] Transfer HTTP=$transfer_http: $transfer_response");

    $transfer_data = json_decode($transfer_response, true);

    if ($transfer_http !== 200) {
        $err = $transfer_data['error']['message'] ?? $transfer_err ?? 'Transfer failed';
        error_log("[FB Post] Transfer failed: $err");
        return ['success' => false, 'error' => 'Upload transfer failed: ' . $err];
    }

    // ── Step 4: Finish — attach title/description ─────────
    $finish_url = "https://graph-video.facebook.com/v18.0/{$page_id}/videos";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $finish_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'upload_phase'      => 'finish',
            'upload_session_id' => $upload_session_id,
            'title'             => substr($title, 0, 255),
            'description'       => substr($description, 0, 2000),
            'published'         => 'true',
            'access_token'      => $page_token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $finish_response = curl_exec($ch);
    $finish_http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("[FB Post] Finish HTTP=$finish_http: $finish_response");

    $finish_data = json_decode($finish_response, true);

    if ($finish_http !== 200 || !empty($finish_data['error'])) {
        $err = $finish_data['error']['message'] ?? 'Finish failed';
        error_log("[FB Post] Finish failed: $err");
        return ['success' => false, 'error' => 'Upload finish failed: ' . $err];
    }

    error_log("[FB Post] SUCCESS! video_id=$video_id");
    return [
        'success'  => true,
        'video_id' => $video_id,
        'post_id'  => $finish_data['id'] ?? $video_id,
    ];
}

// ════════════════════════════════════════════════════════
// HELPER — get Facebook page token for an admin
// ════════════════════════════════════════════════════════
function getFacebookToken($conn, $admin_id, $company_id = 0) {
    $admin_id   = (int)$admin_id;
    $company_id = (int)$company_id;
    // Token is scoped to this company (one row per admin+company+platform).
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT access_token, refresh_token, channel_id, channel_name, token_expiry
         FROM hdb_oauth_tokens
         WHERE admin_id = $admin_id AND company_id = $company_id AND platform = 'facebook'
         LIMIT 1"
    ));
    if (!$row) return null;

    // Check expiry (warn but don't block — long-lived tokens last 60 days)
    if ($row['token_expiry'] && strtotime($row['token_expiry']) < time()) {
        error_log("[FB Token] WARNING: Token expired for admin_id=$admin_id");
    }

    return [
        'user_token'  => $row['access_token'],
        'page_token'  => $row['refresh_token'], // we store page_token in refresh_token column
        'page_id'     => $row['channel_id'],
        'page_name'   => $row['channel_name'],
        'token_expiry'=> $row['token_expiry'],
    ];
}

// ════════════════════════════════════════════════════════
// MAIN — post a podcast video to Facebook
// Called by cron or manually
// ════════════════════════════════════════════════════════
function postPodcastToFacebook($conn, $podcast_id, $admin_id) {

    error_log("[FB Post] postPodcastToFacebook podcast_id=$podcast_id admin_id=$admin_id");

    // ── Get podcast row ───────────────────────────────────
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title, caption_text, hashtags, keywords,
                published_video, video_status, facebook_status, admin_id, company_id
         FROM hdb_podcasts
         WHERE id = $podcast_id LIMIT 1"
    ));

    if (!$row) {
        error_log("[FB Post] Podcast $podcast_id not found");
        return ['success' => false, 'error' => 'Podcast not found'];
    }

    if (empty($row['published_video'])) {
        error_log("[FB Post] No published_video for podcast $podcast_id");
        return ['success' => false, 'error' => 'No recorded video file — record video first'];
    }

    // Use podcast's admin_id if not passed explicitly (cron use)
    if (!$admin_id) $admin_id = (int)$row['admin_id'];
    // Token is scoped to the podcast's owning company (works for cron too)
    $company_id = (int)($row['company_id'] ?? 0);

    // ── Get Facebook token ────────────────────────────────
    $fb = getFacebookToken($conn, $admin_id, $company_id);
    if (!$fb) {
        error_log("[FB Post] No Facebook token for admin_id=$admin_id");
        mysqli_query($conn, "UPDATE hdb_podcasts SET facebook_status='failed' WHERE id=$podcast_id");
        return ['success' => false, 'error' => 'Facebook not connected — visit facebook_connect.php'];
    }

    error_log("[FB Post] Using page: {$fb['page_name']} ({$fb['page_id']})");

    // ── Build video path ──────────────────────────────────
    $video_file = $row['published_video'];
    $video_path = __DIR__ . '/published_videos/' . $video_file;

    // ── Build description ─────────────────────────────────
    $hashtags    = trim($row['hashtags'] ?? '');
    $caption     = trim($row['caption_text'] ?? '');
    $description = $caption;
    if ($hashtags) {
        // Format hashtags — add # if missing
        $tags = array_map(function($t) {
            $t = trim($t);
            return $t ? (str_starts_with($t, '#') ? $t : '#' . $t) : '';
        }, explode(',', $hashtags));
        $description .= "\n\n" . implode(' ', array_filter($tags));
    }

    // ── Mark as posting ───────────────────────────────────
    mysqli_query($conn, "UPDATE hdb_podcasts SET facebook_status='posting' WHERE id=$podcast_id");

    // ── Do the upload ─────────────────────────────────────
    $result = postVideoToFacebook(
        $fb['page_id'],
        $fb['page_token'],
        $video_path,
        $row['title'],
        $description
    );

    // ── Update status ─────────────────────────────────────
    if ($result['success']) {
        $post_id   = mysqli_real_escape_string($conn, $result['post_id'] ?? '');
        $posted_at = date('Y-m-d H:i:s');
        // Try with tracking columns first; fall back if they don't exist yet
        $upd = mysqli_query($conn,
            "UPDATE hdb_podcasts
             SET facebook_status = 'posted',
                 facebook_post_id = '$post_id',
                 facebook_posted_at = '$posted_at'
             WHERE id = $podcast_id"
        );
        if (!$upd) {
            // Columns not added yet — just update status
            mysqli_query($conn,
                "UPDATE hdb_podcasts SET facebook_status='posted' WHERE id=$podcast_id"
            );
        }
        error_log("[FB Post] DB updated: podcast $podcast_id facebook_status=posted");
    } else {
        $err = mysqli_real_escape_string($conn, $result['error'] ?? 'unknown');
        $upd2 = mysqli_query($conn,
            "UPDATE hdb_podcasts SET facebook_status='failed', facebook_error='$err' WHERE id=$podcast_id"
        );
        if (!$upd2) {
            mysqli_query($conn, "UPDATE hdb_podcasts SET facebook_status='failed' WHERE id=$podcast_id");
        }
        error_log("[FB Post] DB updated: podcast $podcast_id facebook_status=failed err=$err");
    }

    return $result;
}

// ════════════════════════════════════════════════════════
// BROWSER TEST MODE  — facebook_post.php?test=1&podcast_id=X
// ════════════════════════════════════════════════════════
if ($is_test && $podcast_id) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Facebook Post Test ===\n";
    echo "Podcast ID: $podcast_id\n";
    echo "Admin ID:   $admin_id\n\n";

    // Check token (scoped to the session's active company)
    $test_company_id = (int)($_SESSION['company_id'] ?? $_SESSION['client_company_id'] ?? 0);
    $fb = getFacebookToken($conn, $admin_id, $test_company_id);
    if (!$fb) {
        echo "ERROR: No Facebook token found for admin_id=$admin_id\n";
        echo "Visit facebook_connect.php to connect your Facebook account first.\n";
        exit;
    }
    echo "Facebook Page: {$fb['page_name']} (ID: {$fb['page_id']})\n";
    echo "Token expires: {$fb['token_expiry']}\n\n";

    // Check podcast
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title, published_video, facebook_status FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"
    ));
    if (!$row) { echo "ERROR: Podcast $podcast_id not found\n"; exit; }

    echo "Podcast:      {$row['title']}\n";
    echo "Video file:   {$row['published_video']}\n";
    echo "FB status:    {$row['facebook_status']}\n\n";

    if (empty($row['published_video'])) {
        echo "ERROR: No published_video — record the video in videomaker.php first\n";
        exit;
    }

    $video_path = __DIR__ . '/published_videos/' . $row['published_video'];
    if (!file_exists($video_path)) {
        echo "ERROR: Video file not found at: $video_path\n";
        exit;
    }
    echo "Video found: " . round(filesize($video_path)/1024/1024, 2) . " MB\n\n";

    echo "Posting to Facebook...\n";
    $result = postPodcastToFacebook($conn, $podcast_id, $admin_id);

    echo "\nResult:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit;
}

// ════════════════════════════════════════════════════════
// AJAX MODE — called from vizard_browser.php modal
// POST: action=post_facebook&podcast_id=X
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_facebook') {
    header('Content-Type: application/json');
    if (!$admin_id)    { echo json_encode(['success'=>false,'error'=>'Not logged in']); exit; }
    if (!$podcast_id)  { echo json_encode(['success'=>false,'error'=>'No podcast_id']); exit; }

    $result = postPodcastToFacebook($conn, $podcast_id, $admin_id);
    echo json_encode($result);
    exit;
}
