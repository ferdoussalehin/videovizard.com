<?php
/**
 * facebook_test.php  — standalone test, no session needed
 * Visit: https://videovizard.com/facebook_test.php?podcast_id=167
 */

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

echo "<pre>\n";
echo "=== Facebook Post Test ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n"; 

// ── 1. Load config & DB ────────────────────────────────────────────
echo "Loading config...\n";
if (!file_exists(__DIR__ . '/config.php')) { die("ERROR: config.php not found\n"); }
require_once 'config.php';

echo "Loading DB...\n";
if (!file_exists(__DIR__ . '/dbconnect_hdb.php')) { die("ERROR: dbconnect_hdb.php not found\n"); }
include 'dbconnect_hdb.php';

// $conn or $con?
if (!isset($conn) && isset($con)) $conn = $con;
if (!isset($conn)) { die("ERROR: No DB connection variable found\n"); }
echo "DB connected: " . (mysqli_ping($conn) ? "YES" : "NO") . "\n\n";

// ── 2. Check FB constants ──────────────────────────────────────────
echo "FB_APP_ID:      " . (defined('FB_APP_ID')      ? FB_APP_ID      : 'NOT DEFINED') . "\n";
echo "FB_APP_SECRET:  " . (defined('FB_APP_SECRET')  ? '***set***'     : 'NOT DEFINED') . "\n";
echo "FB_REDIRECT_URI:" . (defined('FB_REDIRECT_URI') ? FB_REDIRECT_URI : 'NOT DEFINED') . "\n\n";

// ── 3. Session & admin_id ──────────────────────────────────────────
session_set_cookie_params(15552000);
session_start();
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
echo "Session admin_id: $admin_id\n";
if (!$admin_id) {
    echo "WARNING: No session — trying to find admin from podcast...\n";
}

// ── 4. Get podcast ─────────────────────────────────────────────────
$podcast_id = (int)($_GET['podcast_id'] ?? 0);
if (!$podcast_id) { die("ERROR: No podcast_id in URL. Add ?podcast_id=167\n"); }

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, title, admin_id, published_video, video_filename,
            facebook_status, video_status, schedule_date, schedule_time
     FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"
));

if (!$row) { die("ERROR: Podcast $podcast_id not found in DB\n"); }

echo "\n--- Podcast #$podcast_id ---\n";
echo "Title:          {$row['title']}\n";
echo "Admin ID:       {$row['admin_id']}\n";
echo "Video status:   {$row['video_status']}\n";
echo "FB status:      {$row['facebook_status']}\n";
echo "Published video:{$row['published_video']}\n";
echo "Video filename: {$row['video_filename']}\n\n";

// Use podcast's admin_id if session has none
if (!$admin_id) $admin_id = (int)$row['admin_id'];
echo "Using admin_id: $admin_id\n\n";

// ── 5. Check video file ────────────────────────────────────────────
$video_file = trim($row['published_video'] ?? '');
if (!$video_file) {
    echo "ERROR: published_video is empty.\n";
    echo "You need to record the video in videomaker.php first.\n\n";
    // Check video_filename as fallback
    $vf2 = trim($row['video_filename'] ?? '');
    if ($vf2) echo "Note: video_filename = $vf2 (this is the source, not the recorded output)\n";
} else {
    $paths = [
        __DIR__ . '/published_videos/' . $video_file,
        __DIR__ . '/' . $video_file,
    ];
    $found_path = '';
    foreach ($paths as $p) {
        echo "Checking: $p ... " . (file_exists($p) ? "EXISTS (" . round(filesize($p)/1024/1024,2) . " MB)" : "not found") . "\n";
        if (file_exists($p) && !$found_path) $found_path = $p;
    }
    echo "\n";
}

// ── 6. Check Facebook token ────────────────────────────────────────
echo "--- Facebook Token ---\n";
$tok = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT access_token, refresh_token, channel_id, channel_name,
            token_expiry, created_at
     FROM hdb_oauth_tokens
     WHERE admin_id=$admin_id AND platform='facebook'
     LIMIT 1"
));

if (!$tok) {
    echo "ERROR: No Facebook token for admin_id=$admin_id\n";
    echo "Visit facebook_connect.php to connect your Facebook account.\n\n";

    // Show all tokens in table for debugging
    $all = mysqli_query($conn, "SELECT admin_id, platform, channel_name, token_expiry FROM hdb_oauth_tokens ORDER BY admin_id");
    echo "All tokens in hdb_oauth_tokens:\n";
    while ($t = mysqli_fetch_assoc($all)) {
        echo "  admin_id={$t['admin_id']} platform={$t['platform']} page={$t['channel_name']} expires={$t['token_expiry']}\n";
    }
} else {
    echo "Page name:    {$tok['channel_name']}\n";
    echo "Page ID:      {$tok['channel_id']}\n";
    echo "Token expiry: {$tok['token_expiry']}\n";
    echo "Created at:   {$tok['created_at']}\n";
    $expired = strtotime($tok['token_expiry']) < time();
    echo "Token valid:  " . ($expired ? "NO — EXPIRED, reconnect via facebook_connect.php" : "YES") . "\n\n";

    if (!$expired && !empty($found_path)) {
        echo "--- Attempting Facebook Upload ---\n";
        $page_id    = $tok['channel_id'];
        $page_token = $tok['refresh_token']; // page token stored in refresh_token

        // Quick test — just init the upload session
        echo "Step 1: Initialising upload session...\n";
        $file_size = filesize($found_path);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://graph-video.facebook.com/v18.0/{$page_id}/videos",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'upload_phase'  => 'start',
                'file_size'     => $file_size,
                'access_token'  => $page_token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        echo "HTTP: $http\n";
        echo "Response: $resp\n";
        if ($err) echo "cURL error: $err\n";

        $data = json_decode($resp, true);
        if (!empty($data['upload_session_id'])) {
            $session_id = $data['upload_session_id'];
            $video_id   = $data['video_id'];
            echo "\nSession ID: $session_id\n";
            echo "Video ID:   $video_id\n\n";

            // Step 2: transfer
            echo "Step 2: Uploading video file ($file_size bytes)...\n";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL        => "https://graph-video.facebook.com/v18.0/{$page_id}/videos",
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => [
                    'upload_phase'      => 'transfer',
                    'upload_session_id' => $session_id,
                    'start_offset'      => 0,
                    'video_file_chunk'  => new CURLFile($found_path, 'video/mp4', basename($found_path)),
                    'access_token'      => $page_token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp2 = curl_exec($ch);
            $http2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            echo "HTTP: $http2\n";
            echo "Response: $resp2\n\n";

            $data2 = json_decode($resp2, true);
            if ($http2 === 200 && empty($data2['error'])) {
                // Step 3: finish
                echo "Step 3: Finishing upload...\n";
                $title       = $row['title'];
                $description = trim($row['caption_text'] ?? '') . "\n\n" . trim($row['hashtags'] ?? '');
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL        => "https://graph-video.facebook.com/v18.0/{$page_id}/videos",
                    CURLOPT_POST       => true,
                    CURLOPT_POSTFIELDS => [
                        'upload_phase'      => 'finish',
                        'upload_session_id' => $session_id,
                        'title'             => substr($title, 0, 255),
                        'description'       => substr($description, 0, 2000),
                        'published'         => 'true',
                        'access_token'      => $page_token,
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp3 = curl_exec($ch);
                $http3 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                echo "HTTP: $http3\n";
                echo "Response: $resp3\n\n";
                $data3 = json_decode($resp3, true);
                if ($http3 === 200 && empty($data3['error'])) {
                    echo "SUCCESS! Posted to Facebook.\n";
                    echo "Post ID: " . ($data3['id'] ?? $video_id) . "\n";
                    // Update DB
                    $pid = $podcast_id;
                    mysqli_query($conn, "UPDATE hdb_podcasts SET facebook_status='posted' WHERE id=$pid");
                    echo "DB updated: facebook_status=posted\n";
                } else {
                    $errmsg = $data3['error']['message'] ?? 'unknown';
                    echo "ERROR on finish: $errmsg\n";
                }
            } else {
                $errmsg = $data2['error']['message'] ?? 'transfer failed';
                echo "ERROR on transfer: $errmsg\n";
            }
        } else {
            $errmsg = $data['error']['message'] ?? 'init failed';
            echo "ERROR on init: $errmsg\n";
        }
    } elseif ($expired) {
        echo "Skipping upload — token expired. Reconnect at facebook_connect.php\n";
    } elseif (empty($found_path)) {
        echo "Skipping upload — no video file found.\n";
    }
}

echo "\n=== DONE ===\n</pre>\n";
