<?php
error_reporting(0);
ini_set('display_errors', 0);

$allowed = ['https://socialmedia110.com', 'https://www.socialmedia110.com'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Content-Type: application/json; charset=utf-8');

ob_start(); // buffer from here to catch any stray output from the include
require_once 'dbconnect_hdb.php';
ob_end_clean(); // discard anything the include printed

$niche = trim($_GET['niche'] ?? '');
if ($niche === '') {
    echo json_encode(['success' => false, 'error' => 'No niche']);
    exit;
}

$safeNiche = mysqli_real_escape_string($conn, $niche);

// ── DEBUG: show columns + sample rows ────────────────────────────────────────
if (isset($_GET['debug'])) {
    $debug = [];

    // 1. What columns does hdb_podcasts actually have?
    $colRes = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts");
    $cols = [];
    if ($colRes) while ($c = mysqli_fetch_assoc($colRes)) $cols[] = $c['Field'];
    $debug['columns'] = $cols;

    // 2. Sample of 5 rows for admin_id=32, company_id=27
    $sampleRes = mysqli_query($conn, "SELECT id, title, niche, admin_id, company_id FROM hdb_podcasts WHERE admin_id = 32 AND company_id = 27 LIMIT 5");
    $sample = [];
    if ($sampleRes) while ($r = mysqli_fetch_assoc($sampleRes)) $sample[] = $r;
    $debug['sample_rows'] = $sample;
    $debug['sample_error'] = mysqli_error($conn);

    // 3. Distinct niche values for admin_id=32, company_id=27
    $nicheRes = mysqli_query($conn, "SELECT DISTINCT niche FROM hdb_podcasts WHERE admin_id = 32 AND company_id = 27 LIMIT 20");
    $nicheVals = [];
    if ($nicheRes) while ($r = mysqli_fetch_assoc($nicheRes)) $nicheVals[] = $r['niche'];
    $debug['distinct_niche_values'] = $nicheVals;

    // 4. Sample video_url values to confirm path format
    $vidRes = mysqli_query($conn, "SELECT id, title, video_filename, thumbnail FROM hdb_podcasts WHERE admin_id = 32 AND company_id = 27 AND video_filename != '' LIMIT 5");
    $vidSample = [];
    if ($vidRes) while ($r = mysqli_fetch_assoc($vidRes)) $vidSample[] = $r;
    $debug['video_url_samples'] = $vidSample;
    echo json_encode(['debug' => $debug], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

$sql = "SELECT id, title, thumbnail, video_filename
        FROM hdb_podcasts
        WHERE niche = '$safeNiche'
          AND admin_id = 32
          AND company_id = 27
          AND (campaign_id IS NULL OR campaign_id = 0)
        ORDER BY id DESC
        LIMIT 10";

$res      = mysqli_query($conn, $sql);
$sqlError = mysqli_error($conn); 
$rows     = [];

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $thumb = trim($row['thumbnail'] ?? '');
        $bare  = $thumb ? basename($thumb) : '';
        $rows[] = [
            'title'     => $row['title'] ?: 'Untitled',
            'thumb'     => $bare ? 'https://www.videovizard.com/podcast_thumbnails/' . $bare : '',
            'video_url' => trim($row['video_filename'] ?? '') !== ''
                            ? 'https://www.videovizard.com/published_videos/' . basename(trim($row['video_filename']))
                            : '',
            'duration'  => '',
        ];
    }
}

echo json_encode([
    'success'   => true,
    'videos'    => $rows,
    'sql'       => $sql,          // remove after debugging
    'sql_error' => $sqlError,     // remove after debugging
    'count'     => count($rows),  // remove after debugging
], JSON_UNESCAPED_UNICODE);
