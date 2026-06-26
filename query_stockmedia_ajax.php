<?php
// query_stockmedia_ajax.php
// Returns updated video counts for a single promo_group/promo_subgroup row
include 'config.php';
header('Content-Type: application/json');

$action    = trim($_POST['action'] ?? '');
$group     = trim($_POST['group']    ?? '');
$subgroup  = trim($_POST['subgroup'] ?? '');

if ($action !== 'row_counts' || !$group || !$subgroup) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

define('TARGET_VIDEOS', 150);

$g_e  = mysqli_real_escape_string($conn, $group);
$sg_e = mysqli_real_escape_string($conn, $subgroup);

$row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*) AS total_media,
        SUM(CASE WHEN LOWER(media_type)='image' THEN 1 ELSE 0 END) AS image_count,
        SUM(CASE WHEN LOWER(media_type)='video' THEN 1 ELSE 0 END) AS video_count,
        GREATEST(" . TARGET_VIDEOS . " - SUM(CASE WHEN LOWER(media_type)='video' THEN 1 ELSE 0 END), 0) AS videos_to_make
    FROM hdb_image_data
    WHERE promo_group    = '$g_e'
      AND promo_subgroup = '$sg_e'
"));

echo json_encode([
    'success'        => true,
    'group'          => $group,
    'subgroup'       => $subgroup,
    'total_media'    => (int)($row['total_media']    ?? 0),
    'image_count'    => (int)($row['image_count']    ?? 0),
    'video_count'    => (int)($row['video_count']    ?? 0),
    'videos_to_make' => (int)($row['videos_to_make'] ?? 0),
]);
