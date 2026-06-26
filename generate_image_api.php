<?php
// generate_image_api.php
// ══════════════════════════════════════════════════════════════════════════════
// AI Image Generation — Flux (Modal) PRIMARY, OpenAI gpt-image-1 FALLBACK
// Handles both:
//   action=generate_image_api   (standard name)
//   action=generate_single_image (legacy — remapped automatically)
// ══════════════════════════════════════════════════════════════════════════════

// All generator functions live in the shared file — safe to include anywhere
require_once __DIR__ . '/image_generation_functions.php';

// ── Logging helper ────────────────────────────────────────────────────────────
function img_log($msg) {
    error_log('[generate_image_api] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ── Remap legacy action name ──────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'generate_single_image') {
    img_log("Legacy action 'generate_single_image' remapped to 'generate_image_api'");
    $_POST['action'] = 'generate_image_api';
}

// ── Action handler ────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'generate_image_api') {
    header('Content-Type: application/json');

    session_start();
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }

    $scene_id        = (int)($_POST['scene_id']       ?? 0);
    $podcast_id      = (int)($_POST['podcast_id']     ?? 0);
    $enhanced_prompt = trim($_POST['enhanced_prompt'] ?? '');
    $hashtags        = trim($_POST['hashtags']        ?? '');
    $seq_no          = (int)($_POST['seq_no']         ?? 1);
    $image_field     = trim($_POST['image_field']     ?? 'image_file');
    $admin_id        = (int)$_SESSION['admin_id'];

    $allowed_fields = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    if (!in_array($image_field, $allowed_fields)) $image_field = 'image_file';

    $folder_field_map = [
        'image_file'   => 'image_folder',
        'image_file_1' => 'image_folder_1',
        'image_file_2' => 'image_folder_2',
        'image_file_3' => 'image_folder_3',
        'image_file_4' => 'image_folder_4',
    ];
    $folder_field = $folder_field_map[$image_field] ?? 'image_folder';

    if (empty($enhanced_prompt)) {
        img_log("ERROR: No prompt provided for scene=$scene_id");
        echo json_encode(['success' => false, 'message' => 'No prompt provided']);
        exit;
    }

    $seq_padded = str_pad($seq_no, 3, '0', STR_PAD_LEFT);
    $imageName  = "generated_{$podcast_id}_{$seq_padded}";
    $folder     = 'podcast_images';

    img_log("=== IMAGE GENERATION START === scene=$scene_id podcast=$podcast_id slot=$image_field seq=$seq_no");

    // ── 1. Try Flux PRIMARY ───────────────────────────────────────────────────
    img_log(">>> STEP 1: Trying Flux PRIMARY");
    $_t = microtime(true);
    $result = generateAndSaveImageFlux($enhanced_prompt, $imageName, $folder);
    $elapsed = round(microtime(true) - $_t, 2) . 's';

    if ($result['success']) {
        img_log(">>> Flux SUCCESS in {$elapsed}");
    } else {
        // ── 2. Fallback to OpenAI ─────────────────────────────────────────────
        img_log(">>> Flux FAILED in {$elapsed} ({$result['message']}) — falling back to OpenAI");
        require_once __DIR__ . '/config.php';
        $_t2 = microtime(true);
        $result = generateAndSaveImage($enhanced_prompt, $imageName, '1024x1536', $folder, $apiKey);
        $elapsed2 = round(microtime(true) - $_t2, 2) . 's';
        if ($result['success']) {
            img_log(">>> OpenAI SUCCESS in {$elapsed2}");
        } else {
            img_log(">>> OpenAI ALSO FAILED ({$result['message']})");
            echo json_encode(['success' => false, 'message' => $result['message']]);
            exit;
        }
    }

    img_log(">>> Provider used: " . $result['provider']);

    // ── 3. Persist to hdb_image_data ─────────────────────────────────────────
    include_once 'dbconnect_hdb.php';

    // Derive extension from the actual saved file — never assume .png
    $ext          = pathinfo($result['filepath'], PATHINFO_EXTENSION) ?: 'png';
    $filename     = $imageName . '.' . $ext;
    $esc_filename = mysqli_real_escape_string($conn, $filename);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
    $esc_prompt   = mysqli_real_escape_string($conn, $enhanced_prompt);
    $image_folder = 'podcast_images';

    mysqli_query($conn,
        "INSERT IGNORE INTO hdb_image_data
             (image_name, image_hashtags, description, media_type, add_by, created_at)
         VALUES ('$esc_filename','$esc_hashtags','$esc_prompt','image',$admin_id,NOW())"
    );

    // ── 3b. Generate resized thumbnail via shared helper ──────────────────────
    $thumb        = generateThumbnail($result['filepath'], $imageName, $ext);
    $thumbFilename = $thumb['generated'] ? $thumb['filename'] : $filename; // fallback to main if failed
    $esc_thumbname = mysqli_real_escape_string($conn, $thumbFilename);
    img_log(">>> Thumbnail: " . $thumb['filepath'] . " generated=" . ($thumb['generated'] ? 'YES' : 'NO'));

    // ── 4. Assign to scene slot ───────────────────────────────────────────────
    if ($scene_id > 0) {
        // Only update thumbnail column when writing to the primary image slot
        $thumb_sql = ($image_field === 'image_file') ? ", thumbnail='$esc_thumbname'" : '';
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories
             SET `$image_field`  = '$esc_filename',
                 image_folder    = '$image_folder',
                 `$folder_field` = '$image_folder'
                 $thumb_sql
             WHERE id = $scene_id"
        );
        img_log("scene update: scene=$scene_id slot=$image_field thumbnail=$thumbFilename ok=" . ($ok ? 'YES' : 'NO') . " rows=" . mysqli_affected_rows($conn));
    }

    img_log("=== IMAGE GENERATION COMPLETE | provider=" . $result['provider'] . " ===");

    echo json_encode([
        'success'        => true,
        'filename'       => $filename,
        'thumbnail'      => $thumbFilename,
        'thumb_folder'   => 'podcast_thumbnails',
        'filepath'       => $result['filepath'],
        'thumb_filepath' => $thumb['filepath'],
        'resolution'     => $result['resolution'],
        'image_field'    => $image_field,
        'folder_field'   => $folder_field,
        'provider'       => $result['provider'],
    ]);
    exit;
}
?>
