<?php
ob_start();

// ============================================================
// STEP 1: ERROR LOGGING
// ============================================================
$logFile = __DIR__ . '/video_upload_errors.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);
error_reporting(E_ALL);

if (!function_exists('debug_log')) {
    function debug_log($message, $data = null) {
        global $logFile;
        $line = date('Y-m-d H:i:s') . " | " . $message;
        if ($data !== null) {
            $line .= " | " . (is_array($data) || is_object($data) ? json_encode($data) : $data);
        }
        file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('send_json')) {
    function send_json($data) {
        $strayOutput = ob_get_clean();
        if (!empty(trim($strayOutput))) {
            global $logFile;
            file_put_contents($logFile, date('Y-m-d H:i:s') . " | STRAY OUTPUT: " . $strayOutput . PHP_EOL, FILE_APPEND);
        }
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('svr_parseSize')) {
    function svr_parseSize($size) {
        $unit = strtoupper(substr($size, -1));
        $val  = (int)$size;
        switch ($unit) {
            case 'G': return $val * 1024 * 1024 * 1024;
            case 'M': return $val * 1024 * 1024;
            case 'K': return $val * 1024;
            default:  return $val;
        }
    }
}

debug_log("========== VIDEO UPLOAD STARTED ==========");
debug_log("PHP version", PHP_VERSION);
debug_log("Request method", $_SERVER['REQUEST_METHOD'] ?? 'unknown');
debug_log("Content-Length", $_SERVER['CONTENT_LENGTH'] ?? 'not set');

// ============================================================
// STEP 2: PHP LIMITS
// ============================================================
$postMaxBytes  = svr_parseSize(ini_get('post_max_size'));
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

debug_log("upload_max_filesize", ini_get('upload_max_filesize'));
debug_log("post_max_size", ini_get('post_max_size'));
debug_log("Content-Length bytes", $contentLength);
debug_log("Free disk MB", round(disk_free_space(__DIR__) / 1024 / 1024, 2));

// ============================================================
// STEP 3: POST SIZE OVERFLOW CHECK
// ============================================================
if ($contentLength > $postMaxBytes) {
    debug_log("OVERFLOW: $contentLength > $postMaxBytes");
    send_json(['success' => false, 'message' => 'File too large. Server max: ' . ini_get('post_max_size')]);
}

// ============================================================
// STEP 4: SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
debug_log("Session ID", session_id());

// ============================================================
// STEP 5: DB CONNECTION
// ============================================================
$dbFile = __DIR__ . '/dbconnect_hdb.php';
if (!file_exists($dbFile)) {
    debug_log("dbconnect_hdb.php NOT FOUND at $dbFile");
    send_json(['success' => false, 'message' => 'DB config file missing']);
}
require_once $dbFile;
debug_log("DB connection", (isset($conn) && mysqli_ping($conn)) ? 'OK' : 'FAILED');
if (!isset($conn) || !mysqli_ping($conn)) {
    debug_log("DB ERROR: mysqli_connect_error = " . mysqli_connect_error());
}

// ============================================================
// STEP 6: AUTH CHECK
// ============================================================
if (!isset($_SESSION['admin_id'])) {
    debug_log("Auth failed: No admin_id in session");
    send_json(['success' => false, 'message' => 'Not authenticated']);
}
debug_log("Auth OK. admin_id", $_SESSION['admin_id']);

// ============================================================
// STEP 7: LOG INCOMING DATA
// ============================================================
debug_log("--- POST fields ---");
foreach ($_POST as $k => $v) {
    debug_log("  POST[$k]", $v);
}

debug_log("--- FILES ---");
if (empty($_FILES)) {
    debug_log("_FILES is EMPTY");
} else {
    foreach ($_FILES as $field => $info) {
        debug_log("FILES[$field]", "name={$info['name']} type={$info['type']} size={$info['size']} error={$info['error']}");
    }
}

// ============================================================
// STEP 8: UPLOAD DIRECTORY
// ============================================================
$uploadDir      = 'published_videos/';
$fullUploadPath = __DIR__ . '/' . $uploadDir;

if (!is_dir($fullUploadPath)) {
    $mkdirResult = @mkdir($fullUploadPath, 0755, true);
    debug_log("mkdir result", $mkdirResult ? 'SUCCESS' : 'FAILED');
    if (!$mkdirResult) {
        $err = error_get_last();
        debug_log("mkdir error", $err['message'] ?? 'unknown');
    }
} else {
    debug_log("Upload dir exists, writable: " . (is_writable($fullUploadPath) ? 'YES' : 'NO'));
}

// ============================================================
// STEP 9: VALIDATE UPLOAD
// ============================================================
if (!isset($_FILES['video_blob'])) {
    $msg = "No video_blob in FILES. Keys: " . implode(', ', array_keys($_FILES));
    debug_log($msg);
    send_json(['success' => false, 'message' => $msg]);
}

$uploadErrors = [
    UPLOAD_ERR_OK         => 'No error',
    UPLOAD_ERR_INI_SIZE   => 'Exceeds upload_max_filesize in php.ini',
    UPLOAD_ERR_FORM_SIZE  => 'Exceeds MAX_FILE_SIZE in form',
    UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
    UPLOAD_ERR_NO_FILE    => 'No file uploaded',
    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
    UPLOAD_ERR_EXTENSION  => 'PHP extension blocked upload',
];

$fileError = $_FILES['video_blob']['error'];
debug_log("Upload error code", "$fileError = " . ($uploadErrors[$fileError] ?? 'Unknown'));

if ($fileError !== UPLOAD_ERR_OK) {
    $msg = $uploadErrors[$fileError] ?? 'Unknown upload error';
    debug_log("Upload failed: $msg");
    send_json(['success' => false, 'message' => $msg]);
}

// ============================================================
// STEP 10: BUILD FILENAME
// ============================================================
$uploadedName  = $_FILES['video_blob']['name'] ?? 'video.mp4';
$fileExtension = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION)) ?: 'mp4';

if (!in_array($fileExtension, ['mp4', 'webm', 'ogg'])) {
    $fileExtension = 'mp4';
}

$projectTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $_POST['project_title'] ?? 'unknown');
$langCode     = preg_replace('/[^a-zA-Z_-]/', '',    $_POST['lang_code']     ?? 'en');
$podcastId    = isset($_POST['podcast_id']) ? (int)$_POST['podcast_id'] : 0;
$timestamp    = date('Ymd_His');

debug_log("project=$projectTitle lang=$langCode podcast_id=$podcastId ext=$fileExtension");

$baseFilename = $podcastId > 0
    ? "{$podcastId}_{$projectTitle}_{$timestamp}"
    : "{$projectTitle}_{$langCode}_{$timestamp}";

$finalFilename     = $baseFilename . '.' . $fileExtension;
$finalPath         = $fullUploadPath . $finalFilename;
$relativeFinalPath = $uploadDir . $finalFilename;

debug_log("Target filename", $finalFilename);
debug_log("Incoming size", $_FILES['video_blob']['size'] . ' bytes');

// ============================================================
// STEP 11: SAVE FILE
// ============================================================
$moveResult = move_uploaded_file($_FILES['video_blob']['tmp_name'], $finalPath);
debug_log("move_uploaded_file", $moveResult ? 'SUCCESS' : 'FAILED');

if (!$moveResult) {
    $err = error_get_last();
    debug_log("move error", $err['message'] ?? 'no error captured');
    debug_log("dest dir writable", is_writable(dirname($finalPath)) ? 'YES' : 'NO');
    send_json(['success' => false, 'message' => 'Failed to save file — check video_upload_errors.log']);
}

$savedSize = filesize($finalPath);
debug_log("File saved OK. Size: $savedSize bytes");

$response = [
    'success'        => true,
    'final_filename' => $finalFilename,
    'file_extension' => $fileExtension,
    'file_size'      => $savedSize,
    'file_size_mb'   => round($savedSize / 1024 / 1024, 2),
    'download_url'   => 'https://' . $_SERVER['HTTP_HOST'] . '/' . $relativeFinalPath,
    'file_exists'    => true,
];

// ============================================================
// STEP 12: DATABASE UPDATE
// ============================================================
debug_log("--- DB UPDATE ---");
debug_log("podcast_id value", $podcastId);
debug_log("podcast_id type", gettype($podcastId));

if ($podcastId > 0) {
    debug_log("Entering DB update block for podcast_id=$podcastId");

    if (!isset($conn) || !$conn) {
        debug_log("FAILED: DB connection not available");
        $response['db_updated'] = false;
        $response['db_error']   = 'No DB connection';
    } else {
        debug_log("DB conn OK, running SELECT...");

        $selectSQL = "SELECT * FROM hdb_podcasts WHERE id = " . (int)$podcastId;
        debug_log("SELECT query", $selectSQL);

        $checkQuery = mysqli_query($conn, $selectSQL);

        if ($checkQuery === false) {
            debug_log("SELECT failed", mysqli_error($conn));
            $response['db_updated'] = false;
            $response['db_error']   = 'SELECT failed: ' . mysqli_error($conn);
        } elseif (mysqli_num_rows($checkQuery) === 0) {
            debug_log("FAILED: Podcast ID $podcastId not found in hdb_podcasts");
            // Log all podcast IDs to help debug
            $allIds = mysqli_query($conn, "SELECT id, title FROM hdb_podcasts ORDER BY id DESC LIMIT 10");
            if ($allIds) {
                while ($r = mysqli_fetch_assoc($allIds)) {
                    debug_log("  Available podcast", "id={$r['id']} title={$r['title']}");
                }
            }
            $response['db_updated'] = false;
            $response['db_error']   = "Podcast ID $podcastId not found in DB";
        } else {
            $current = mysqli_fetch_assoc($checkQuery);
            debug_log("Found podcast", "id={$current['id']} title={$current['title']} current_video_file=" . ($current['video_file'] ?? 'NULL'));

            $escapedFilename = mysqli_real_escape_string($conn, $finalFilename);
            $updateSQL = "UPDATE hdb_podcasts SET video_filename = '$escapedFilename', video_status = 'RECORDED' WHERE id = " . (int)$podcastId;
            debug_log("UPDATE query", $updateSQL);

            $updateResult = mysqli_query($conn, $updateSQL);

            if ($updateResult) {
                $affectedRows = mysqli_affected_rows($conn);
                debug_log("UPDATE success. Affected rows: $affectedRows");
                $response['db_updated']       = true;
                $response['db_podcast_id']    = $podcastId;
                $response['db_affected_rows'] = $affectedRows;

                // Verify the update
                $verifyQuery = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id = " . (int)$podcastId);
                if ($verifyQuery) {
                    $verified = mysqli_fetch_assoc($verifyQuery);
                    debug_log("VERIFY after update", "video_file={$verified['video_file']} video_status={$verified['video_status']}");
                    $response['db_verified_filename'] = $verified['video_filename'];
                    $response['db_verified_status']   = $verified['video_status'];
                }
            } else {
                $dbError = mysqli_error($conn);
                $dbErrNo = mysqli_errno($conn);
                debug_log("UPDATE FAILED. Error #$dbErrNo: $dbError");
                debug_log("UPDATE query was", $updateSQL);
                $response['db_updated'] = false;
                $response['db_error']   = "Error #$dbErrNo: $dbError";
            }
        }
    }
} else {
    debug_log("No podcast_id (value=$podcastId) — skipping DB update");
    $response['db_updated'] = false;
    $response['db_note']    = 'No podcast_id sent — used title+lang+timestamp filename';
}

// ============================================================
// STEP 13: DONE
// ============================================================
debug_log("========== VIDEO UPLOAD FINISHED ==========\n");
send_json($response);
?>
