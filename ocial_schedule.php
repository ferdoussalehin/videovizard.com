<?php
require 'config.php';
require 'dbconnect_hdb.php';

header('Content-Type: application/json');

$input      = json_decode(file_get_contents('php://input'), true);
$podcast_id = intval($input['podcast_id'] ?? 0);
$platforms  = $input['platforms']  ?? [];   // array: ['instagram','tiktok']
$caption    = trim($input['caption']   ?? '');
$sched_date = trim($input['sched_date'] ?? '');
$sched_time = trim($input['sched_time'] ?? '');
$post_type  = trim($input['post_type']  ?? 'scheduled'); // 'now' or 'scheduled'

if (!$podcast_id) {
    echo json_encode(['success' => false, 'error' => 'Missing podcast_id']);
    exit;
}

// Build platform status values
// Connected platforms selected by user = 'pending'
// Connected platforms NOT selected = 'skip'
// Disconnected platforms = 'disconnected' (handled on frontend, just leave as-is)
$all_platforms = ['facebook', 'tiktok', 'instagram', 'youtube', 'twitter', 'linkedin'];
$platform_fields = [];
foreach ($all_platforms as $p) {
    $col = $p . '_status';
    $platform_fields[$col] = in_array($p, $platforms) ? 'pending' : 'skip';
}

// Overall video status
$video_status = ($post_type === 'now') ? 'posting' : 'scheduled';

// Date and time
$now = date('Y-m-d H:i:s');
$final_date = $sched_date ?: date('Y-m-d');
$final_time = $sched_time ?: date('H:i');

$sql = "UPDATE hdb_podcasts SET
    caption_text      = ?,
    schedule_date     = ?,
    schedule_time     = ?,
    video_status      = ?,
    facebook_status   = ?,
    tiktok_status     = ?,
    instagram_status  = ?,
    youtube_status    = ?,
    twitter_status    = ?,
    linkedin_status   = ?,
    updated_at        = ?
    WHERE id          = ?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'sssssssssssi',
    $caption,
    $final_date,
    $final_time,
    $video_status,
    $platform_fields['facebook_status'],
    $platform_fields['tiktok_status'],
    $platform_fields['instagram_status'],
    $platform_fields['youtube_status'],
    $platform_fields['twitter_status'],
    $platform_fields['linkedin_status'],
    $now,
    $podcast_id
);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'success'      => true,
        'podcast_id'   => $podcast_id,
        'video_status' => $video_status,
        'platforms'    => $platforms,
        'schedule_date'=> $final_date,
        'schedule_time'=> $final_time
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => mysqli_stmt_error($stmt)
    ]);
}

mysqli_stmt_close($stmt);