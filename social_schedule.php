<?php
// social_schedule.php
// Receives scheduler payload, updates hdb_podcasts, saves to hdb_schedule

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'Not authenticated']);
    exit;
}

header('Content-Type: application/json');
include 'dbconnect_hdb.php';

$admin_id = (int)$_SESSION['admin_id'];
$raw      = file_get_contents('php://input');
$data     = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success'=>false,'error'=>'Invalid JSON']);
    exit;
}

$podcast_id     = (int)($data['podcast_id']     ?? 0);
$platforms      = $data['platforms']            ?? [];
$caption        = trim($data['caption']         ?? '');
$sched_date     = trim($data['sched_date']      ?? date('Y-m-d'));
$sched_time     = trim($data['sched_time']      ?? '09:00');
$post_type      = trim($data['post_type']       ?? 'scheduled');
$video_filename = trim($data['video_filename']  ?? '');

if (!$podcast_id) {
    echo json_encode(['success'=>false,'error'=>'Missing podcast_id']);
    exit;
}

// Verify ownership
$chk = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1"));
if (!$chk) {
    echo json_encode(['success'=>false,'error'=>'Podcast not found']);
    exit;
}

// Build per-platform status
$all_platforms   = ['instagram','tiktok','youtube','facebook','twitter','linkedin'];
$platform_status = [];
foreach ($all_platforms as $p) {
    $platform_status[$p] = in_array($p, $platforms) ? 'pending' : 'skip';
}

$video_status   = ($post_type === 'now') ? 'posting' : 'scheduled';
$esc_caption    = mysqli_real_escape_string($conn, $caption);
$esc_date       = mysqli_real_escape_string($conn, $sched_date);
$esc_time       = mysqli_real_escape_string($conn, $sched_time);
$esc_vid_status = mysqli_real_escape_string($conn, $video_status);
$esc_vid_file   = mysqli_real_escape_string($conn, $video_filename);
$esc_instagram  = mysqli_real_escape_string($conn, $platform_status['instagram']);
$esc_tiktok     = mysqli_real_escape_string($conn, $platform_status['tiktok']);
$esc_youtube    = mysqli_real_escape_string($conn, $platform_status['youtube']);
$esc_facebook   = mysqli_real_escape_string($conn, $platform_status['facebook']);
$esc_twitter    = mysqli_real_escape_string($conn, $platform_status['twitter']);
$esc_linkedin   = mysqli_real_escape_string($conn, $platform_status['linkedin']);

// Update hdb_podcasts
$ok = mysqli_query($conn,
    "UPDATE hdb_podcasts SET
        caption_text       = '$esc_caption',
        schedule_date      = '$esc_date',
        schedule_time      = '$esc_time',
        video_status       = '$esc_vid_status',
        video_filename     = '$esc_vid_file',
        instagram_status   = '$esc_instagram',
        tiktok_status      = '$esc_tiktok',
        youtube_status     = '$esc_youtube',
        facebook_status    = '$esc_facebook',
        twitter_status     = '$esc_twitter',
        linkedin_status    = '$esc_linkedin',
        updated_at         = NOW()
     WHERE id = $podcast_id AND admin_id = $admin_id");

if (!$ok) {
    echo json_encode(['success'=>false,'error'=>'DB update failed: '.mysqli_error($conn)]);
    exit;
}

// Also save to hdb_schedule table if it exists
$tbl = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_schedule'");
if ($tbl && mysqli_num_rows($tbl) > 0) {
    // Delete existing schedule for this podcast
    mysqli_query($conn, "DELETE FROM hdb_schedule WHERE podcast_id=$podcast_id");

    // Insert new schedule row per platform
    foreach ($platforms as $platform) {
        $esc_plat = mysqli_real_escape_string($conn, $platform);
        mysqli_query($conn,
            "INSERT INTO hdb_schedule
                (admin_id, podcast_id, platform, caption, sched_date, sched_time,
                 post_type, status, created_at)
             VALUES
                ($admin_id, $podcast_id, '$esc_plat', '$esc_caption',
                 '$esc_date', '$esc_time', '$post_type', 'pending', NOW())");
    }
}

echo json_encode([
    'success'       => true,
    'podcast_id'    => $podcast_id,
    'video_status'  => $video_status,
    'platforms'     => $platforms,
    'schedule_date' => $sched_date,
    'schedule_time' => $sched_time,
    'video_filename'=> $video_filename,
]);
exit;
?>
