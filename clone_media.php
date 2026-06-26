<?php
/**
 * clone_media.php
 * Clones image_file and video_file from source language rows
 * to all other language rows for the same title (matched by scene order)
 */
include 'dbconnect_hdb.php';
if (!isset($conn) && isset($con)) $conn = $con;

header('Content-Type: application/json');

// Get parameters - support both 'title' and 'project_title' for flexibility
$title = mysqli_real_escape_string($conn, $_POST['title'] ?? $_POST['project_title'] ?? '');
$source_lang = mysqli_real_escape_string($conn, $_POST['source_lang'] ?? 'en');
$project_id = mysqli_real_escape_string($conn, $_POST['project_id'] ?? '');
$project_id = 4;
if (empty($title) && empty($project_id)) {
    echo json_encode(['success' => false, 'error' => 'No title or project ID provided']);
    exit;
}

// If project_id is provided but title is empty, get title from database
if (empty($title) && !empty($project_id)) {
    $title_query = "SELECT DISTINCT title FROM hdb_podcast_stories WHERE id = '$project_id' OR title IN 
                    (SELECT title FROM hdb_podcast_stories WHERE id = '$project_id') LIMIT 1";
    $title_result = mysqli_query($conn, $title_query);
    if ($title_result && mysqli_num_rows($title_result) > 0) {
        $title_row = mysqli_fetch_assoc($title_result);
        $title = $title_row['title'];
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not find title for project ID']);
        exit;
    }
}

// 1. Get source language rows in order
$src_sql = "SELECT id, image_file, video_file FROM hdb_podcast_stories 
            WHERE title = '$title' AND lang_code = '$source_lang' 
            ORDER BY id ASC";
$src_result = mysqli_query($conn, $src_sql);

if (!$src_result) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_num_rows($src_result) === 0) {
    echo json_encode(['success' => false, 'error' => "No source rows found for title '$title' and language '$source_lang'"]);
    exit;
}

$source_rows = [];
while ($r = mysqli_fetch_assoc($src_result)) {
    $source_rows[] = $r;
}

// 2. Get all other languages that have this title
$lang_sql = "SELECT DISTINCT lang_code FROM hdb_podcast_stories 
             WHERE title = '$title' AND lang_code != '$source_lang'";
$lang_result = mysqli_query($conn, $lang_sql);

if (!$lang_result) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . mysqli_error($conn)]);
    exit;
}

$target_langs = [];
while ($l = mysqli_fetch_assoc($lang_result)) {
    $target_langs[] = $l['lang_code'];
}

if (empty($target_langs)) {
    echo json_encode([
        'success' => true, 
        'warning' => 'No other languages found for this title',
        'source_lang' => $source_lang,
        'source_scenes' => count($source_rows),
        'target_langs' => [],
        'total_updated' => 0,
        'details' => []
    ]);
    exit;
}

// 3. For each target language, match by scene order and update
$total_updated = 0;
$lang_details = [];

foreach ($target_langs as $tLang) {
    // Get target rows in order
    $tgt_sql = "SELECT id FROM hdb_podcast_stories 
                WHERE title = '$title' AND lang_code = '$tLang' 
                ORDER BY id ASC";
    $tgt_result = mysqli_query($conn, $tgt_sql);
    
    if (!$tgt_result) {
        $lang_details[] = [
            'lang' => $tLang, 
            'error' => mysqli_error($conn),
            'scenes' => 0,
            'updated' => 0,
            'matched' => 0
        ];
        continue;
    }
    
    $target_ids = [];
    while ($t = mysqli_fetch_assoc($tgt_result)) {
        $target_ids[] = $t['id'];
    }

    $updated = 0;
    $count = min(count($source_rows), count($target_ids));

    for ($i = 0; $i < $count; $i++) {
        $img = mysqli_real_escape_string($conn, $source_rows[$i]['image_file'] ?? '');
        $vid = mysqli_real_escape_string($conn, $source_rows[$i]['video_file'] ?? '');
        $tid = (int)$target_ids[$i];

        $upd = "UPDATE hdb_podcast_stories 
                SET image_file = '$img', video_file = '$vid' 
                WHERE id = $tid";
        if (mysqli_query($conn, $upd)) {
            if (mysqli_affected_rows($conn) > 0) {
                $updated++;
            }
        }
    }

    $total_updated += $updated;
    $lang_details[] = [
        'lang' => $tLang, 
        'scenes' => count($target_ids), 
        'updated' => $updated,
        'matched' => $count
    ];
}

echo json_encode([
    'success' => true,
    'source_lang' => $source_lang,
    'source_scenes' => count($source_rows),
    'target_langs' => $target_langs,
    'total_updated' => $total_updated,
    'details' => $lang_details
]);