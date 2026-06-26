<?php
/**
 * wan_processor.php
 * -----------------
 * Reads hdb_podcasts where videogen_flag = 1,
 * fetches the first matching row from hdb_podcast_stories,
 * sends video_prompt to the Wan 2.1 Modal endpoint (streaming),
 * downloads the resulting MP4, and updates both tables.
 *
 * Based on sadtalker_processor.php architecture.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);

include 'config.php';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('WAN_GENERATE_URL', 'https://YOUR-MODAL-APP--prompt-to-video-wan-fastapi-app.modal.run/generate');
define('WAN_VIDEO_BASE_URL', 'https://YOUR-MODAL-APP--prompt-to-video-wan-fastapi-app.modal.run');
define('WAN_API_KEY',        'sk-wan-video-studio-8293-f2a1');
define('USER_VIDEOS_FOLDER', __DIR__ . '/user_videos/');
define('LOG_FOLDER',         __DIR__ . '/logs/');
define('WAN_RESOLUTION',     '720*1280'); // Portrait HD

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------
function smartLog(string $msg, string $type = 'INFO'): void {
    $logFile = LOG_FOLDER . 'wan_processor_' . date('Y-m-d') . '.log';
    $entry   = '[' . date('Y-m-d H:i:s') . "] [$type] $msg" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// ---------------------------------------------------------------------------
// DB keep-alive (mirrors sadtalker pattern)
// ---------------------------------------------------------------------------
function ensure_db_connection(): void {
    global $conn;
    if (!@mysqli_ping($conn)) {
        smartLog('DB disconnected. Reconnecting...', 'WARN');
        @mysqli_close($conn);
        include 'config.php'; // re-establishes $conn
    }
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
if (!is_dir(USER_VIDEOS_FOLDER)) {
    mkdir(USER_VIDEOS_FOLDER, 0755, true);
}
if (!is_dir(LOG_FOLDER)) {
    mkdir(LOG_FOLDER, 0755, true);
}

ensure_db_connection();

// ---------------------------------------------------------------------------
// Step 1 – Find podcasts that need video generation (up to 5 at a time)
// ---------------------------------------------------------------------------
$podcasts_result = mysqli_query($conn,
    "SELECT id, title
     FROM hdb_podcasts
     WHERE videogen_flag = 1
     ORDER BY id ASC
     LIMIT 5"
);

if (!$podcasts_result) {
    smartLog('DB query failed (hdb_podcasts): ' . mysqli_error($conn), 'ERROR');
    echo json_encode(['success' => false, 'message' => 'DB error on hdb_podcasts']);
    exit;
}

if (mysqli_num_rows($podcasts_result) === 0) {
    echo json_encode(['success' => true, 'message' => 'No podcasts with videogen_flag=1']);
    exit;
}

// Collect into array to avoid result-set/DB-timeout conflicts
$podcasts = [];
while ($row = mysqli_fetch_assoc($podcasts_result)) {
    $podcasts[] = $row;
}

$success_count = 0;

// ---------------------------------------------------------------------------
// Step 2 – Process each podcast
// ---------------------------------------------------------------------------
foreach ($podcasts as $podcast) {
    $podcast_id    = (int) $podcast['id'];
    $podcast_title = $podcast['title'] ?? "Podcast #$podcast_id";

    smartLog("Processing podcast_id=$podcast_id ($podcast_title)", 'INFO');

    // -----------------------------------------------------------------------
    // Step 2a – Fetch the FIRST story that still needs a video
    // -----------------------------------------------------------------------
    ensure_db_connection();

    $story_result = mysqli_query($conn,
        "SELECT id, video_prompt, image_file, image_folder
         FROM hdb_podcast_stories
         WHERE podcast_id = $podcast_id
           AND videogen_flag = 1
         ORDER BY id ASC
         LIMIT 1"
    );

    if (!$story_result || mysqli_num_rows($story_result) === 0) {
        // No pending stories – clear the parent flag so we don't loop forever
        smartLog("No pending stories for podcast_id=$podcast_id. Clearing videogen_flag.", 'INFO');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET videogen_flag = 0 WHERE id = $podcast_id"
        );
        continue;
    }

    $story        = mysqli_fetch_assoc($story_result);
    $story_id     = (int) $story['id'];
    $video_prompt = trim($story['video_prompt'] ?? '');

    if (empty($video_prompt)) {
        smartLog("Story $story_id has empty video_prompt. Marking failed.", 'WARN');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET videogen_flag = 0, video_file = NULL,
                 video_error = 'empty_prompt'
             WHERE id = $story_id"
        );
        continue;
    }

    // -----------------------------------------------------------------------
    // Step 2b – Mark story as processing
    // -----------------------------------------------------------------------
    ensure_db_connection();
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET video_status = 'processing'
         WHERE id = $story_id"
    );

    smartLog("Story $story_id → prompt: " . substr($video_prompt, 0, 80) . '...', 'INFO');

    // -----------------------------------------------------------------------
    // Step 2c – Call Wan 2.1 Modal endpoint (streaming response)
    // -----------------------------------------------------------------------
    $post_fields = http_build_query([
        'mode'       => 'wan',
        'prompt'     => $video_prompt,
        'resolution' => WAN_RESOLUTION,
    ]);

    // We collect the full streamed body so we can parse all NDJSON lines
    $raw_response = '';
    $curl_error   = '';
    $http_code    = 0;

    $ch = curl_init(WAN_GENERATE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-API-Key: ' . WAN_API_KEY,
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        // Wan video generation can take up to 30 minutes
        CURLOPT_TIMEOUT        => 1800,
    ]);

    smartLog("Sending prompt to Wan Modal for story $story_id ...", 'INFO');
    $raw_response = curl_exec($ch);
    $curl_error   = curl_error($ch);
    $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // -----------------------------------------------------------------------
    // Step 2d – Handle cURL / HTTP errors
    // -----------------------------------------------------------------------
    if ($curl_error || $raw_response === false) {
        $err = $curl_error ?: 'empty_response';
        smartLog("cURL error for story $story_id: $err", 'ERROR');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET video_status = 'failed', videogen_flag = 0,
                 video_error = 'curl_error: " . mysqli_real_escape_string($conn, $err) . "'
             WHERE id = $story_id"
        );
        continue;
    }

    if ($http_code >= 400) {
        smartLog("HTTP $http_code for story $story_id. Body: " . substr($raw_response, 0, 300), 'ERROR');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET video_status = 'failed', videogen_flag = 0,
                 video_error = 'http_$http_code'
             WHERE id = $story_id"
        );
        continue;
    }

    // -----------------------------------------------------------------------
    // Step 2e – Parse NDJSON stream to find video_url
    //           (Modal streams one JSON object per line)
    // -----------------------------------------------------------------------
    $final_video_url = '';
    $last_message    = '';

    $lines = explode("\n", trim($raw_response));
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        $event = json_decode($line, true);
        if (!is_array($event)) continue;

        // Log progress messages from Modal
        if (!empty($event['message'])) {
            $last_message = $event['message'];
            smartLog("Modal → story $story_id: {$event['message']} (" . ($event['percent'] ?? '?') . "%)", 'INFO');
        }

        // A video_url in the event means generation succeeded
        if (!empty($event['video_url'])) {
            // The URL returned is a relative path like /video/<id>
            // We prepend the base URL so we can download it
            $path = $event['video_url'];
            if (strpos($path, 'http') === 0) {
                $final_video_url = $path;              // already absolute
            } else {
                $final_video_url = WAN_VIDEO_BASE_URL . $path;
            }
            break;
        }

        // Detect explicit error events
        if (isset($event['error'])) {
            smartLog("Modal error for story $story_id: {$event['error']}", 'ERROR');
        }
    }

    if (empty($final_video_url)) {
        $err_detail = 'no_video_url | last_msg: ' . ($last_message ?: 'none');
        smartLog("No video_url found for story $story_id. $err_detail", 'ERROR');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET video_status = 'failed', videogen_flag = 0,
                 video_error = '" . mysqli_real_escape_string($conn, $err_detail) . "'
             WHERE id = $story_id"
        );
        continue;
    }

    smartLog("Story $story_id: video_url resolved → $final_video_url", 'INFO');

    // -----------------------------------------------------------------------
    // Step 2f – Download the generated MP4
    // -----------------------------------------------------------------------
    $video_filename = 'wan_' . $story_id . '_' . time() . '.mp4';
    $save_path      = USER_VIDEOS_FOLDER . $video_filename;

    $fp = fopen($save_path, 'wb');
    if (!$fp) {
        smartLog("Cannot open file for writing: $save_path", 'ERROR');
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET video_status = 'failed', videogen_flag = 0,
                 video_error = 'cannot_open_save_path'
             WHERE id = $story_id"
        );
        continue;
    }

    $dl = curl_init($final_video_url);
    curl_setopt_array($dl, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ['X-API-Key: ' . WAN_API_KEY],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($dl);
    $dl_err  = curl_error($dl);
    $dl_code = curl_getinfo($dl, CURLINFO_HTTP_CODE);
    curl_close($dl);
    fclose($fp);

    $file_size = file_exists($save_path) ? filesize($save_path) : 0;

    if ($dl_err || $file_size < 1000) {
        $err = $dl_err ?: "small_file_{$file_size}b";
        smartLog("Download failed for story $story_id: $err", 'ERROR');
        @unlink($save_path); // clean up empty/corrupt file
        ensure_db_connection();
        mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET video_status = 'failed', videogen_flag = 0,
                 video_error = 'download_failed: " . mysqli_real_escape_string($conn, $err) . "'
             WHERE id = $story_id"
        );
        continue;
    }

    smartLog("Story $story_id: downloaded $video_filename ({$file_size} bytes)", 'INFO');

    // -----------------------------------------------------------------------
    // Step 2g – Update DB: mark story complete
    // -----------------------------------------------------------------------
    ensure_db_connection();
    $safe_filename = mysqli_real_escape_string($conn, $video_filename);

    // Update the story row
    mysqli_query($conn,
        "UPDATE hdb_podcast_stories
         SET video_status  = 'completed',
             videogen_flag = 0,
             video_file    = '$safe_filename',
             video_folder  = 'user_videos',
             video_error   = NULL
         WHERE id = $story_id"
    );

    // -----------------------------------------------------------------------
    // Step 2h – Check if ALL stories for this podcast are now done
    //           If yes, clear the parent videogen_flag
    // -----------------------------------------------------------------------
    ensure_db_connection();
    $remaining = mysqli_query($conn,
        "SELECT COUNT(*) AS cnt
         FROM hdb_podcast_stories
         WHERE podcast_id = $podcast_id
           AND videogen_flag = 1"
    );
    $rem_row = mysqli_fetch_assoc($remaining);

    if ((int)($rem_row['cnt'] ?? 1) === 0) {
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET videogen_flag = 0 WHERE id = $podcast_id"
        );
        smartLog("All stories done for podcast_id=$podcast_id. Cleared videogen_flag.", 'INFO');
    } else {
        smartLog("Podcast $podcast_id still has {$rem_row['cnt']} pending story/stories.", 'INFO');
    }

    $success_count++;
    smartLog("Story $story_id DONE → $video_filename", 'SUCCESS');
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------
echo json_encode(['success' => true, 'processed' => $success_count]);
