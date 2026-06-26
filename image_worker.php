<?php
// image_worker.php
// ══════════════════════════════════════════════════════════════════════════════
// Works THREE ways:
//   1. Browser: http://videovizard.com/image_worker.php?secret=vw2026
//   2. Cron:    /usr/local/bin/php /path/to/image_worker.php
//   3. exec():  triggered automatically by wizard_step2.php after queuing
//
// Secret key protects browser access from public abuse.
// ══════════════════════════════════════════════════════════════════════════════

define('WORKER_SECRET',     'vw2026');       // change this to something private
define('WORKER_BATCH_SIZE',  3);             // jobs per run
define('MAX_ATTEMPTS',       3);             // max retries before marking failed
define('WORKER_LOG', __DIR__ . '/a_errors.log');

// ── Show all errors in browser mode ──────────────────────────────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Detect how we're being called ─────────────────────────────────────────────
$is_cli     = (php_sapi_name() === 'cli');
$is_browser = !$is_cli;

// ── Browser security check ────────────────────────────────────────────────────
if ($is_browser) {
    $secret = $_GET['secret'] ?? $_POST['secret'] ?? '';
    if ($secret !== WORKER_SECRET) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — wrong secret key']);
        exit;
    }
    header('Content-Type: text/plain'); // plain text so you can read it in browser
    echo "=== image_worker.php starting ===
";
    echo "PHP version: " . PHP_VERSION . "
";
    echo "Time: " . date('Y-m-d H:i:s') . "
";
    echo "Dir: " . __DIR__ . "

";
    flush();
}

function wk_log($msg) {
    error_log('[image_worker] ' . date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL, 3, WORKER_LOG);
    // Also echo to browser if running via HTTP
    if (php_sapi_name() !== 'cli') {
        echo $msg . "\n";
        if (ob_get_level()) ob_flush();
        flush();
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
chdir(__DIR__);
require_once __DIR__ . '/dbconnect_hdb.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image_generation_functions.php';

if (!$conn) {
    wk_log("ERROR: DB connection failed — check dbconnect_hdb.php");
    if ($is_browser) echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit(1);
}

wk_log("Worker started | mode=" . ($is_cli ? 'CLI' : 'HTTP') . " | batch=" . WORKER_BATCH_SIZE);

// ── Check queue table exists ──────────────────────────────────────────────────
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_image_gen_queue'");
if (!$table_check || mysqli_num_rows($table_check) === 0) {
    wk_log("ERROR: hdb_image_gen_queue table does not exist — run the CREATE TABLE SQL first");
    exit(1);
}

// ── Count pending jobs ────────────────────────────────────────────────────────
$count_res = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM hdb_image_gen_queue WHERE status='pending'");
$pending_count = (int)(mysqli_fetch_assoc($count_res)['cnt'] ?? 0);
wk_log("Pending jobs in queue: $pending_count");

if ($pending_count === 0) {
    wk_log("No pending jobs — exiting");
    if ($is_browser) echo json_encode(['success' => true, 'message' => 'No pending jobs']);
    exit(0);
}

// ── Claim jobs atomically ─────────────────────────────────────────────────────
mysqli_query($conn, "START TRANSACTION");

$claimed_ids = [];
$claim_res = mysqli_query($conn,
    "SELECT id FROM hdb_image_gen_queue
     WHERE status = 'pending' AND attempts < " . MAX_ATTEMPTS . "
     ORDER BY created_at ASC
     LIMIT " . WORKER_BATCH_SIZE . "
     FOR UPDATE"
);

if ($claim_res) {
    while ($r = mysqli_fetch_assoc($claim_res)) {
        $claimed_ids[] = (int)$r['id'];
    }
}

if (empty($claimed_ids)) {
    mysqli_query($conn, "ROLLBACK");
    wk_log("No claimable jobs (all may be processing or max attempts reached)");
    exit(0);
}

$ids_csv = implode(',', $claimed_ids);
mysqli_query($conn,
    "UPDATE hdb_image_gen_queue
     SET status='processing', started_at=NOW(), attempts=attempts+1
     WHERE id IN ($ids_csv)"
);
mysqli_query($conn, "COMMIT");

wk_log("Claimed " . count($claimed_ids) . " jobs: [$ids_csv]");

// ── Fetch full job details ────────────────────────────────────────────────────
$jobs = [];
$job_res = mysqli_query($conn, "SELECT * FROM hdb_image_gen_queue WHERE id IN ($ids_csv)");
while ($r = mysqli_fetch_assoc($job_res)) {
    $jobs[] = $r;
}

// ── Process each job ──────────────────────────────────────────────────────────
$folder_field_map = [
    'image_file'   => 'image_folder',
    'image_file_1' => 'image_folder_1',
    'image_file_2' => 'image_folder_2',
    'image_file_3' => 'image_folder_3',
    'image_file_4' => 'image_folder_4',
];

foreach ($jobs as $job) {
    $job_id      = (int)$job['id'];
    $podcast_id  = (int)$job['podcast_id'];
    $scene_id    = (int)$job['scene_id'];
    $admin_id    = (int)$job['admin_id'];
    $seq_no      = (int)$job['seq_no'];
    $image_field = $job['image_field'];
    $prompt      = $job['prompt'];
    $hashtags    = $job['hashtags'];
    $attempts    = (int)$job['attempts'];
    $folder_field = $folder_field_map[$image_field] ?? 'image_folder';
    $img_folder   = 'podcast_images';
    $seq_padded   = str_pad($seq_no, 3, '0', STR_PAD_LEFT);
    $imageName    = "generated_{$podcast_id}_{$seq_padded}";

    wk_log("--- Job $job_id | podcast=$podcast_id scene=$scene_id seq=$seq_no attempt=$attempts ---");

    // ── 1. Flux PRIMARY ───────────────────────────────────────────────────────
    wk_log("job=$job_id >>> Trying Flux PRIMARY");
    $_t    = microtime(true);
    $result = generateAndSaveImageFlux($prompt, $imageName, $img_folder);
    $elapsed = round(microtime(true) - $_t, 2) . 's';

    if ($result['success']) {
        wk_log("job=$job_id >>> Flux SUCCESS in $elapsed");
    } else {
        // ── 2. OpenAI FALLBACK ────────────────────────────────────────────────
        wk_log("job=$job_id >>> Flux FAILED in $elapsed ({$result['message']}) — trying OpenAI");
        $_t2   = microtime(true);
        $result = generateAndSaveImage($prompt, $imageName, '1024x1536', $img_folder, $apiKey);
        $elapsed2 = round(microtime(true) - $_t2, 2) . 's';
        if ($result['success']) {
            wk_log("job=$job_id >>> OpenAI SUCCESS in $elapsed2");
        } else {
            wk_log("job=$job_id >>> OpenAI ALSO FAILED in $elapsed2 ({$result['message']})");
        }
    }

    if ($result['success']) {
        $provider = $result['provider'] ?? 'flux';
        $filename = $imageName . '.png';

        // ── Reconnect to MySQL — connection may have timed out during long Flux generation ──
        // Flux can take 200+ seconds; MySQL wait_timeout is often 120-300s on shared hosts
        wk_log("job=$job_id reconnecting to DB after generation...");
        mysqli_close($conn);
        require __DIR__ . '/dbconnect_hdb.php'; // re-opens $conn
        if (!$conn || mysqli_connect_errno()) {
            wk_log("job=$job_id ERROR: DB reconnect failed — image saved but DB not updated: $filename");
            continue;
        }
        wk_log("job=$job_id DB reconnected OK");

        $esc_fn   = mysqli_real_escape_string($conn, $filename);
        $esc_pr   = mysqli_real_escape_string($conn, $prompt);
        $esc_ht   = mysqli_real_escape_string($conn, $hashtags);

        // Save to hdb_image_data
        mysqli_query($conn,
            "INSERT IGNORE INTO hdb_image_data
                 (image_name, image_hashtags, description, media_type, add_by, created_at)
             VALUES ('$esc_fn','$esc_ht','$esc_pr','image',$admin_id,NOW())"
        );

        // Update hdb_podcast_stories
        if ($scene_id > 0) {
            $thumb_sql = ($image_field === 'image_file') ? ", thumbnail='$esc_fn'" : '';
            $ok = mysqli_query($conn,
                "UPDATE hdb_podcast_stories
                 SET `$image_field` = '$esc_fn',
                     image_folder   = '$img_folder',
                     `$folder_field`= '$img_folder'
                     $thumb_sql
                 WHERE id = $scene_id"
            );
            $rows = mysqli_affected_rows($conn);
            if (!$ok)       wk_log("job=$job_id ERROR: UPDATE failed: " . mysqli_error($conn));
            elseif (!$rows) wk_log("job=$job_id WARNING: UPDATE 0 rows — scene_id=$scene_id not found");
            else            wk_log("job=$job_id OK: hdb_podcast_stories updated | slot=$image_field file=$filename");
        }

        // Mark done
        $esc_prov = mysqli_real_escape_string($conn, $provider);
        mysqli_query($conn,
            "UPDATE hdb_image_gen_queue
             SET status='done', provider='$esc_prov', filename='$esc_fn', completed_at=NOW()
             WHERE id=$job_id"
        );
        wk_log("job=$job_id DONE | provider=$provider | file=$filename");

    } else {
        // Reconnect before updating failure status too
        @mysqli_close($conn);
        require __DIR__ . '/dbconnect_hdb.php';

        if ($conn) {
            $err = mysqli_real_escape_string($conn, mb_substr($result['message'] ?? 'unknown', 0, 490));
            if ($attempts >= MAX_ATTEMPTS) {
                mysqli_query($conn,
                    "UPDATE hdb_image_gen_queue
                     SET status='failed', error_msg='$err', completed_at=NOW()
                     WHERE id=$job_id"
                );
                wk_log("job=$job_id FAILED permanently after $attempts attempts | $err");
            } else {
                mysqli_query($conn,
                    "UPDATE hdb_image_gen_queue SET status='pending', error_msg='$err' WHERE id=$job_id"
                );
                wk_log("job=$job_id back to pending for retry ($attempts/" . MAX_ATTEMPTS . ") | $err");
            }
        } else {
            wk_log("job=$job_id ERROR: could not reconnect to DB to mark failure");
        }
    }
}

wk_log("Worker done — processed " . count($jobs) . " of $pending_count pending jobs");

if ($is_browser) {
    echo json_encode([
        'success'   => true,
        'processed' => count($jobs),
        'pending'   => $pending_count,
    ]);
}
exit(0);
?>
