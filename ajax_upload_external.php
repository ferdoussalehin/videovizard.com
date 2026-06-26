<?php
// ajax_upload_external.php
// Creates a podcast row and saves uploaded external video
session_start();
header('Content-Type: application/json');
error_reporting(0);
include 'dbconnect_hdb.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success'=>false,'message'=>'Not logged in']); exit;
}

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_POST['company_id'] ?? $_SESSION['company_id'] ?? 0);
$title      = trim($_POST['title']     ?? '');
$niche      = trim($_POST['niche']     ?? '');
$category   = trim($_POST['category']  ?? '');
$lang_code  = trim($_POST['lang_code'] ?? 'en');

if (!$title) { echo json_encode(['success'=>false,'message'=>'Title is required']); exit; }
if (empty($_FILES['video_file']['tmp_name'])) {
    echo json_encode(['success'=>false,'message'=>'No video file received']); exit;
}

// Resolve team_lead_id
$_u = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
if (!empty($_u) && trim($_u['role'])==='Team Member' && (int)$_u['team_lead_id']>0) {
    $team_lead_id = (int)$_u['team_lead_id'];
} else {
    $team_lead_id = $admin_id;
}

$today      = date('Y-m-d');
$esc_title  = mysqli_real_escape_string($conn, $title);
$esc_niche  = mysqli_real_escape_string($conn, $niche);
$esc_cat    = mysqli_real_escape_string($conn, $category);
$esc_lang   = mysqli_real_escape_string($conn, $lang_code);

// Step 1: Create podcast row first to get the ID
$sql = "INSERT INTO hdb_podcasts
    (admin_id, team_lead_id, company_id, title, niche, category, lang_code,
     video_type, video_status, internal_status,
     created_date, updated_at,
     logo_flag, facebook_status, tiktok_status, instagram_status,
     youtube_status, twitter_status, linkedin_status,
     schedule_date, schedule_time, publish_date,
     video_format, video_media, music_file, hook_name, podcast_type)
    VALUES
    ($admin_id, $team_lead_id, $company_id, '$esc_title', '$esc_niche', '$esc_cat', '$esc_lang',
     'standard', 'RECORDED', 'external',
     '$today', NOW(),
     0, 'pending', 'pending', 'pending',
     'pending', 'pending', 'pending',
     '$today', '09:00', '$today',
     'vertical', 'video', '', '', 'external')";

if (!mysqli_query($conn, $sql)) {
    echo json_encode(['success'=>false,'message'=>'DB error: '.mysqli_error($conn)]); exit;
}
$podcast_id = mysqli_insert_id($conn);

// Step 2: Save video file as podcast_{id}.mp4
$upload_dir = __DIR__ . '/published_videos/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$ext       = strtolower(pathinfo($_FILES['video_file']['name'], PATHINFO_EXTENSION));
$allowed   = ['mp4','mov','webm','avi','mkv'];
if (!in_array($ext, $allowed)) {
    // Rollback podcast row
    mysqli_query($conn, "DELETE FROM hdb_podcasts WHERE id=$podcast_id");
    echo json_encode(['success'=>false,'message'=>'Invalid file type. Use MP4, MOV or WebM.']); exit;
}

$filename  = 'podcast_' . $podcast_id . '.' . $ext;
$dest      = $upload_dir . $filename;

if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $dest)) {
    mysqli_query($conn, "DELETE FROM hdb_podcasts WHERE id=$podcast_id");
    echo json_encode(['success'=>false,'message'=>'Failed to save video file. Check folder permissions.']); exit;
}

// Step 3: Update row with filename
$esc_file = mysqli_real_escape_string($conn, $filename);
mysqli_query($conn,
    "UPDATE hdb_podcasts
     SET published_video='$esc_file', video_filename='$esc_file'
     WHERE id=$podcast_id");

echo json_encode([
    'success'    => true,
    'podcast_id' => $podcast_id,
    'filename'   => $filename,
]);
