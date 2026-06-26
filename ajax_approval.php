<?php
// ============================================
// ajax_approval.php — VideoVizard
// ============================================
session_start();
header('Content-Type: application/json');
error_reporting(0);
include 'dbconnect_hdb.php';

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$podcast_id = (int)($_POST['podcast_id'] ?? $_GET['podcast_id'] ?? 0);

if ($action === 'send_for_approval_bulk') {
    $email_file = __DIR__ . '/email_functions.php';
    if (file_exists($email_file)) require_once $email_file;
}

// ══════════════════════════════════════════════════════════════
// ADMIN ACTIONS
// ══════════════════════════════════════════════════════════════
if ($action === 'set_approval_status') {
    if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
    $admin_id   = (int)$_SESSION['admin_id'];
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $status     = trim($_POST['status'] ?? '');
    $allowed    = ['approval_required', ''];
    if (!in_array($status, $allowed)) { echo json_encode(['success'=>false,'message'=>'Invalid status']); exit; }
    $_u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $team_lead_id = (!empty($_u) && trim($_u['role'])==='Team Member' && (int)$_u['team_lead_id']>0) ? (int)$_u['team_lead_id'] : $admin_id;
    $scope = "(admin_id=$team_lead_id OR team_lead_id=$team_lead_id)";
    $new_status = $status === 'approval_required' ? "'approval_required'" : "NULL";
    $sent_at    = $status === 'approval_required' ? ", approval_sent_at=NOW()" : ", approval_sent_at=NULL";
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET approval_status=$new_status $sent_at WHERE id=$podcast_id AND $scope");
    echo json_encode(['success' => (bool)$ok]); exit;
}

if (in_array($action, ['send_for_approval_bulk','get_approval_status'])) {
    if (!isset($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'message'=>'Not logged in']); exit; }
    $admin_id   = (int)$_SESSION['admin_id'];
    $company_id = (int)($_SESSION['company_id'] ?? 0);
    $_u = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $team_lead_id = (!empty($_u) && trim($_u['role'])==='Team Member' && (int)$_u['team_lead_id']>0) ? (int)$_u['team_lead_id'] : $admin_id;
    $scope = "(admin_id=$team_lead_id OR team_lead_id=$team_lead_id)";

    if ($action === 'send_for_approval_bulk') {
        $ids          = array_map('intval', json_decode($_POST['podcast_ids'] ?? '[]', true) ?: []);
        $client_email = trim($_POST['client_email'] ?? '');
        if (empty($ids))   { echo json_encode(['success'=>false,'message'=>'No videos selected']); exit; }
        if (!filter_var($client_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Invalid client email']); exit; }
        $id_list = implode(',', $ids);
        $res = mysqli_query($conn, "SELECT id, title FROM hdb_podcasts WHERE id IN($id_list) AND $scope AND video_status='RECORDED'");
        $valid=[]; $titles=[];
        while ($r=mysqli_fetch_assoc($res)) { $valid[]=(int)$r['id']; $titles[]=$r['title']; }
        if (empty($valid)) { echo json_encode(['success'=>false,'message'=>'No eligible ready videos']); exit; }
        $vlist = implode(',', $valid);
        if ($company_id>0) { $se=mysqli_real_escape_string($conn,$client_email); mysqli_query($conn,"UPDATE hdb_companies SET client_email='$se' WHERE id=$company_id"); }
        mysqli_query($conn, "UPDATE hdb_podcasts SET approval_status='approval_required', approval_sent_at=NOW() WHERE id IN($vlist)");
        $count     = count($valid);
        $videoList = implode("\n", array_map(fn($t)=>'• '.$t, $titles));
        $portalUrl = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['REQUEST_URI']),'/').'/client_approval.php';
        $html = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;'>
            <div style='background:linear-gradient(135deg,#0f2a44,#1e4a7a);padding:28px 30px;border-radius:16px 16px 0 0;'>
                <div style='font-size:22px;font-weight:800;color:#fff;'>🎬 VideoVizard</div>
                <div style='color:#93c5fd;font-size:14px;'>Client Approval Portal</div>
            </div>
            <div style='padding:28px 30px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 16px 16px;background:#fff;'>
                <p style='font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px;'>$count video(s) ready for your review</p>
                <p style='font-size:14px;color:#64748b;margin:0 0 20px;'>Your agency has submitted the following videos for your approval:</p>
                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;font-size:14px;color:#334155;line-height:2;margin-bottom:24px;'>
                    ".nl2br(htmlspecialchars($videoList))."
                </div>
                <a href='$portalUrl' style='display:inline-block;padding:13px 28px;background:#0284c7;color:#fff;border-radius:50px;font-size:15px;font-weight:700;text-decoration:none;'>
                    Review &amp; Approve Videos &rarr;
                </a>
            </div>
        </div>";
        sendFormattedEmail($client_email, 'Client', "$count Video(s) Ready for Your Approval — VideoVizard", $html);
        echo json_encode(['success'=>true,'sent'=>$count,'email'=>$client_email]); exit;
    }

    if ($action==='get_approval_status') {
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT approval_status,client_feedback,approval_sent_at,approval_received_at
             FROM hdb_podcasts WHERE id=$podcast_id AND $scope LIMIT 1"));
        echo json_encode(['success'=>true,'data'=>$row]); exit;
    }
}

// ══════════════════════════════════════════════════════════════
// ADMIN FEEDBACK CHAT ACTIONS
// ══════════════════════════════════════════════════════════════
if (in_array($action, ['get_feedback_admin', 'send_feedback_admin'])) {

    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success'=>false,'message'=>'Not logged in']); exit;
    }

    $admin_id = (int)$_SESSION['admin_id'];
    $_u = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT firstname, lastname FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $admin_name = trim(($_u['firstname'] ?? '') . ' ' . ($_u['lastname'] ?? '')) ?: 'Admin';

    if ($action === 'get_feedback_admin') {
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        $res = mysqli_query($conn,
            "SELECT id, sender_type, sender_name, message, created_at
             FROM hdb_podcast_feedback
             WHERE podcast_id=$podcast_id
             ORDER BY created_at ASC");
        $messages = [];
        while ($r = mysqli_fetch_assoc($res)) $messages[] = $r;
        echo json_encode(['success'=>true,'messages'=>$messages]); exit;
    }

    if ($action === 'send_feedback_admin') {
        $message = mysqli_real_escape_string($conn, trim($_POST['message'] ?? ''));
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        if (!$message)    { echo json_encode(['success'=>false,'message'=>'Message is empty']); exit; }
        $pc = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT company_id FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
        $pod_company_id = (int)($pc['company_id'] ?? 0);
        $sender = mysqli_real_escape_string($conn, $admin_name);
        mysqli_query($conn,
            "INSERT INTO hdb_podcast_feedback (podcast_id, company_id, sender_type, sender_name, message, created_at)
             VALUES ($podcast_id, $pod_company_id, 'admin', '$sender', '$message', NOW())");
        $new_id = mysqli_insert_id($conn);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, sender_type, sender_name, message, created_at
             FROM hdb_podcast_feedback WHERE id=$new_id"));
        echo json_encode(['success'=>true,'message'=>$row]); exit;
    }
}

// ══════════════════════════════════════════════════════════════
// CLIENT ACTIONS
// ══════════════════════════════════════════════════════════════
if (in_array($action, ['approve','reject','get_client_videos','get_client_stats','get_feedback','send_feedback'])) {

    if (!isset($_SESSION['client_company_id'])) {
        echo json_encode(['success'=>false,'message'=>'Client not logged in']); exit;
    }

    $company_id      = (int)$_SESSION['client_company_id'];
    $client_company  = $_SESSION['client_company'] ?? 'Client';

    // ── get_client_stats ──────────────────────────────────────
    if ($action === 'get_client_stats') {
        $total    = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id"))['c'];
        $approved = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id AND approval_status='approved'"))['c'];
        $pending  = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id AND approval_status='approval_required'"))['c'];
        $rejected = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id AND approval_status='rejected'"))['c'];
        echo json_encode(['success'=>true,'total'=>$total,'approved'=>$approved,'pending'=>$pending,'rejected'=>$rejected]); exit;
    }

    // ── get_feedback — load chat thread ───────────────────────
    if ($action === 'get_feedback') {
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        $res = mysqli_query($conn,
            "SELECT id, sender_type, sender_name, message, created_at
             FROM hdb_podcast_feedback
             WHERE podcast_id=$podcast_id AND company_id=$company_id
             ORDER BY created_at ASC");
        $messages = [];
        while ($r = mysqli_fetch_assoc($res)) $messages[] = $r;
        echo json_encode(['success'=>true,'messages'=>$messages]); exit;
    }

    // ── send_feedback — client posts a message ────────────────
    if ($action === 'send_feedback') {
        $message = mysqli_real_escape_string($conn, trim($_POST['message'] ?? ''));
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        if (!$message)    { echo json_encode(['success'=>false,'message'=>'Message is empty']); exit; }
        $sender = mysqli_real_escape_string($conn, $client_company);
        mysqli_query($conn,
            "INSERT INTO hdb_podcast_feedback (podcast_id, company_id, sender_type, sender_name, message, created_at)
             VALUES ($podcast_id, $company_id, 'client', '$sender', '$message', NOW())");
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET client_feedback='$message', approval_received_at=NOW()
             WHERE id=$podcast_id AND company_id=$company_id");
        $new_id = mysqli_insert_id($conn);
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, sender_type, sender_name, message, created_at FROM hdb_podcast_feedback WHERE id=$new_id"));
        echo json_encode(['success'=>true,'message'=>$row]); exit;
    }

    // ── get_client_videos ─────────────────────────────────────
    if ($action==='get_client_videos') {
        $tab  = $_GET['tab'] ?? 'pending';
        $page = max(1,(int)($_GET['page'] ?? 1));

        if ($tab === 'all') {
            $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 500)));
            $offset  = ($page - 1) * $perPage;
            $total   = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id"))['c'];
            $res = mysqli_query($conn,
                "SELECT id, title, thumbnail, video_status, approval_status,
                        published_video, video_filename,
                        approval_sent_at, approval_received_at, client_feedback
                 FROM hdb_podcasts
                 WHERE company_id=$company_id
                 ORDER BY id ASC
                 LIMIT $perPage OFFSET $offset");
            $videos = [];
            while ($r = mysqli_fetch_assoc($res)) $videos[] = $r;
            echo json_encode(['success'=>true,'videos'=>$videos,'total'=>$total,'has_more'=>($offset+$perPage)<$total]); exit;
        } else {
            $perPage = 12;
            $offset  = ($page - 1) * $perPage;
            $where   = $tab === 'pending'
                ? "approval_status='approval_required'"
                : "approval_status IN('approved','rejected')";
            $total = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) c FROM hdb_podcasts WHERE company_id=$company_id AND $where"))['c'];
            $res = mysqli_query($conn,
                "SELECT id, title, thumbnail, video_status, approval_status,
                        published_video, video_filename,
                        approval_sent_at, approval_received_at, client_feedback
                 FROM hdb_podcasts
                 WHERE company_id=$company_id AND $where
                 ORDER BY approval_sent_at DESC, id DESC
                 LIMIT $perPage OFFSET $offset");
            $videos = [];
            while ($r = mysqli_fetch_assoc($res)) $videos[] = $r;
            echo json_encode(['success'=>true,'videos'=>$videos,'total'=>$total,'has_more'=>($offset+$perPage)<$total]); exit;
        }
    }

    // ── approve ────────────────────────────────────────────────
    if ($action==='approve') {
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET approval_status='approved',
             approval_received_at=NOW(), client_feedback=NULL
             WHERE id=$podcast_id AND company_id=$company_id");
        // Log approval in chat
        $sender = mysqli_real_escape_string($conn, $client_company);
        mysqli_query($conn,
            "INSERT INTO hdb_podcast_feedback (podcast_id, company_id, sender_type, sender_name, message, created_at)
             VALUES ($podcast_id, $company_id, 'client', '$sender', '✅ Video approved.', NOW())");
        echo json_encode(['success'=>true,'new_status'=>'approved']); exit;
    }

    // ── reject ─────────────────────────────────────────────────
    if ($action==='reject') {
        $feedback = mysqli_real_escape_string($conn, trim($_POST['feedback']??''));
        if (!$podcast_id) { echo json_encode(['success'=>false,'message'=>'No podcast ID']); exit; }
        if (!$feedback)   { echo json_encode(['success'=>false,'message'=>'Please enter feedback']); exit; }
        mysqli_query($conn,
            "UPDATE hdb_podcasts SET approval_status='rejected',
             approval_received_at=NOW(), client_feedback='$feedback'
             WHERE id=$podcast_id AND company_id=$company_id");
        // Log in chat
        $sender = mysqli_real_escape_string($conn, $client_company);
        $msg    = mysqli_real_escape_string($conn, $feedback);
        mysqli_query($conn,
            "INSERT INTO hdb_podcast_feedback (podcast_id, company_id, sender_type, sender_name, message, created_at)
             VALUES ($podcast_id, $company_id, 'client', '$sender', '🔄 Changes requested: $msg', NOW())");
        echo json_encode(['success'=>true,'new_status'=>'rejected']); exit;
    }
}

// ══════════════════════════════════════════════════════════════
// CLIENT LOGIN / LOGOUT
// ══════════════════════════════════════════════════════════════
if ($action==='client_login') {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']??''));
    $password = $_POST['password']??'';
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, admin_id, companyname, client_username, client_password
         FROM hdb_companies WHERE client_username='$username' LIMIT 1"));
    if ($row && $row['client_password']===$password) {
        $_SESSION['client_company_id'] = (int)$row['id'];
        $_SESSION['client_username']   = $username;
        $_SESSION['client_company']    = $row['companyname'];
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Invalid username or password']);
    }
    exit;
}

if ($action==='client_logout') { session_destroy(); echo json_encode(['success'=>true]); exit; }

echo json_encode(['success'=>false,'message'=>'Unknown action']);
