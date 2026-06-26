<?php
ob_start();
session_start();

include 'dbconnect_hdb.php';
header('Content-Type: application/json');
ini_set('error_log', __DIR__ . '/a_errors.log');

function logError($msg) {
    error_log(date("Y-m-d H:i:s") . " - " . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// Read from $_POST — matches FormData sent by vizard_browser.php
$video_id   = (int)($_POST['video_id'] ?? 0);
$action     = $_POST['action'] ?? '';
$admin_id   = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$company_id = (int)($_POST['company_id'] ?? $_SESSION['company_id'] ?? 0);

logError("AJAX UPDATE: video_id=$video_id action=$action admin_id=$admin_id company_id=$company_id session=" . json_encode($_SESSION));

if (!$video_id || !$action || !$admin_id || !$company_id) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid request',
        'debug'   => "vid=$video_id act=$action adm=$admin_id comp=$company_id",
    ]);
    exit;
}

// Verify video belongs to this company
$check = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id = $video_id AND company_id = $company_id LIMIT 1");

if (!$check || mysqli_num_rows($check) === 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Video not found or access denied for this company']);
    exit;
}
$video   = mysqli_fetch_assoc($check);
$success = false;

switch ($action) {

    case 'archive':
        $success = mysqli_query($conn,
            "UPDATE hdb_podcasts SET archived_flag = 1 WHERE id = $video_id AND company_id = $company_id");
        break;

    case 'restore':
        $success = mysqli_query($conn,
            "UPDATE hdb_podcasts SET archived_flag = 0 WHERE id = $video_id AND company_id = $company_id");
        break;

    case 'delete':
        // 1. Delete captions
        mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id = $video_id");

        // 2. Delete video generation records
        mysqli_query($conn, "DELETE FROM hdb_video_gen WHERE podcast_id = $video_id");

        // 3. Delete audio files for each scene, then delete scene rows
        $lang_code    = strtolower($video['lang_code'] ?? 'en');
        $scenes_result = mysqli_query($conn, "SELECT id FROM hdb_podcast_stories WHERE podcast_id = $video_id");
        if ($scenes_result) {
            while ($scene = mysqli_fetch_assoc($scenes_result)) {
                $scene_id   = (int)$scene['id'];
                $audio_file = __DIR__ . "/audio_images/voice_{$video_id}_{$scene_id}_{$lang_code}.mp3";
                if (file_exists($audio_file)) @unlink($audio_file);
            }
        }
        mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id = $video_id");

        // 4. Delete the podcast row itself
        $success = mysqli_query($conn,
            "DELETE FROM hdb_podcasts WHERE id = $video_id AND company_id = $company_id");

        // 5. Clean up physical files (best-effort)
        $thumb     = $video['thumbnail']  ?? '';
        $vid       = $video['video_file'] ?? '';
        $pub_video = __DIR__ . "/published_videos/podcast_{$video_id}.mp4";

        foreach ([$thumb, $vid] as $f) {
            if (!$f) continue;
            $abs = (strpos($f, '/') === 0) ? $f : __DIR__ . '/' . ltrim($f, '/');
            if (file_exists($abs)) @unlink($abs);
        }
        if (file_exists($pub_video)) @unlink($pub_video);
        break;

    default:
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        exit;
}

// ── Return updated counts ──────────────────────────────────────
$counts_result = mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN
                video_status NOT IN ('RECORDED','SCHEDULED','POSTED','PUBLISHED','ARCHIVED')
                AND (archived_flag IS NULL OR archived_flag = 0)
            THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN video_status = 'RECORDED'
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN video_status = 'SCHEDULED'
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
        SUM(CASE WHEN video_status IN ('POSTED','PUBLISHED')
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN (video_status = 'ARCHIVED' OR archived_flag = 1) THEN 1 ELSE 0 END) as archived_count
     FROM hdb_podcasts WHERE company_id = $company_id");
$counts = $counts_result ? mysqli_fetch_assoc($counts_result) : [];

$stray = ob_get_clean();
if (!empty($stray)) error_log("AJAX_UPDATE stray output: " . $stray);

echo json_encode([
    'success'  => (bool)$success,
    'counts'   => [
        'active'    => (int)($counts['active_count']    ?? 0),
        'completed' => (int)($counts['completed_count'] ?? 0),
        'scheduled' => (int)($counts['scheduled_count'] ?? 0),
        'posted'    => (int)($counts['posted_count']    ?? 0),
        'archived'  => (int)($counts['archived_count']  ?? 0),
    ],
    'db_error' => $success ? '' : mysqli_error($conn),
]);
?>