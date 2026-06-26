<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

include 'dbconnect_hdb.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? $_SESSION['company_id'] ?? 0);
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

// ── ACTION: update_campaign_thumbnail ────────────────────────────────────────
if ($action === 'update_campaign_thumbnail') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $thumbnail  = mysqli_real_escape_string($conn, trim($_POST['thumbnail'] ?? ''));

    if (!$podcast_id || !$thumbnail) {
        echo json_encode(['success' => false, 'error' => 'Missing params']);
        exit;
    }

    $pod = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT campaign_id FROM hdb_podcasts WHERE id = $podcast_id AND admin_id = $admin_id LIMIT 1"
    ));
    $campaign_id = (int)($pod['campaign_id'] ?? 0);

    if (!$campaign_id) {
        echo json_encode(['success' => false, 'error' => 'No campaign found for this podcast']);
        exit;
    }

    $ok = mysqli_query($conn,
        "UPDATE hdb_campaigns SET thumbnail = '$thumbnail' WHERE id = $campaign_id AND admin_id = $admin_id"
    );
    echo json_encode(['success' => (bool)$ok, 'campaign_id' => $campaign_id, 'thumbnail' => $thumbnail]);
    exit;
}

// ── ACTION: delete_campaign ───────────────────────────────────────────────────
if ($action === 'delete_campaign') {
    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    if (!$campaign_id) {
        echo json_encode(['success' => false, 'message' => 'Missing campaign_id']);
        exit;
    }
    // Get all podcast IDs for this campaign
    $pod_res = mysqli_query($conn,
        "SELECT id FROM hdb_podcasts WHERE campaign_id = $campaign_id AND admin_id = $admin_id"
    );
    while ($pod = mysqli_fetch_assoc($pod_res)) {
        $pid = (int)$pod['id'];
        mysqli_query($conn, "DELETE FROM hdb_podcast_stories WHERE podcast_id = $pid");
        mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id = $pid");
    }
    mysqli_query($conn, "DELETE FROM hdb_podcasts WHERE campaign_id = $campaign_id AND admin_id = $admin_id");
    $ok = mysqli_query($conn, "DELETE FROM hdb_campaigns WHERE id = $campaign_id AND admin_id = $admin_id");
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// ── DEFAULT: load campaigns list ──────────────────────────────────────────────
if (!$company_id) {
    $fc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_companies WHERE admin_id = $admin_id ORDER BY id ASC LIMIT 1"
    ));
    $company_id = (int)($fc['id'] ?? 0);
}

if (!$company_id) {
    echo json_encode(['success' => true, 'campaigns' => []]);
    exit;
}

$result = mysqli_query($conn,
    "SELECT id, campaign_name, niche, category, goal, reel_type,
            languages, total_videos, status, created_at, thumbnail
     FROM hdb_campaigns
     WHERE admin_id = $admin_id AND company_id = $company_id
     ORDER BY created_at DESC"
);

if (!$result) {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    exit;
}

$campaigns = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['scheduled_at'] = null;
    $campaigns[] = $row;
}

echo json_encode(['success' => true, 'campaigns' => $campaigns]);