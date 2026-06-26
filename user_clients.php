<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$admin_id = (int)$_SESSION['admin_id'];

include 'dbconnect_hdb.php';
require_once __DIR__ . '/config.php';

// ── Ensure extra columns exist on hdb_companies ───────────────
function addCompanyColIfMissing($conn, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `hdb_companies` LIKE '$col'");
    if ($res && mysqli_num_rows($res) === 0) {
        mysqli_query($conn, "ALTER TABLE `hdb_companies` ADD COLUMN `$col` $def");
    }
}
addCompanyColIfMissing($conn, 'phone',           "VARCHAR(30)  NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'email',           "VARCHAR(120) NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'website',         "VARCHAR(255) NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'address',         "TEXT         NULL");
addCompanyColIfMissing($conn, 'client_username', "VARCHAR(80)  NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'client_password', "VARCHAR(255) NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'password_hash',   "VARCHAR(255) NULL DEFAULT ''");
addCompanyColIfMissing($conn, 'company_type',    "VARCHAR(50)  NULL DEFAULT 'client'");

// ── Helper: clone user settings from admin template ──────────
function cloneUserSettings($conn, $new_user_id, $new_company_id) {
    $sql_template = "SELECT fontfamily, fontcolor, fontsize, fontweight, fontcolor_bg, fontbg_enable,
                            caption_style, caption_position, caption_alignment, caption_speed,
                            logo_name, logo_size, logo_position, logo_enabled,
                            position_x, position_y, width, last_niche_id
                     FROM hdb_user_settings
                     WHERE admin_id = 1 AND company_id = 1 LIMIT 1";
    $template_res = mysqli_query($conn, $sql_template);

    $fontfamily = 'ariel'; $fontcolor = 'white'; $fontsize = 28; $fontweight = '700';
    $fontcolor_bg = 'black'; $fontbg_enable = 0; $caption_style = 'bottom';
    $caption_position = 'center'; $caption_alignment = 'center'; $caption_speed = 1;
    $logo_name = 'logo.png'; $logo_size = 'medium'; $logo_position = 'bottom';
    $logo_enabled = 1; $position_x = 40; $position_y = 0; $width = 275; $last_niche_id = 0;

    if ($template_res && $t = mysqli_fetch_assoc($template_res)) {
        $fontfamily        = mysqli_real_escape_string($conn, $t['fontfamily'] ?? $fontfamily);
        $fontcolor         = mysqli_real_escape_string($conn, $t['fontcolor']  ?? $fontcolor);
        $fontsize          = (int)($t['fontsize'] ?? $fontsize);
        $fontweight        = mysqli_real_escape_string($conn, $t['fontweight'] ?? $fontweight);
        $fontcolor_bg      = mysqli_real_escape_string($conn, $t['fontcolor_bg'] ?? $fontcolor_bg);
        $fontbg_enable     = (int)($t['fontbg_enable'] ?? 0);
        $caption_style     = mysqli_real_escape_string($conn, $t['caption_style'] ?? $caption_style);
        $caption_position  = mysqli_real_escape_string($conn, $t['caption_position'] ?? $caption_position);
        $caption_alignment = mysqli_real_escape_string($conn, $t['caption_alignment'] ?? $caption_alignment);
        $caption_speed     = (int)($t['caption_speed'] ?? 1);
        $logo_name         = mysqli_real_escape_string($conn, $t['logo_name'] ?? $logo_name);
        $logo_size         = mysqli_real_escape_string($conn, $t['logo_size'] ?? $logo_size);
        $logo_position     = mysqli_real_escape_string($conn, $t['logo_position'] ?? $logo_position);
        $logo_enabled      = (int)($t['logo_enabled'] ?? 1);
        $position_x        = (int)($t['position_x'] ?? 40);
        $position_y        = (int)($t['position_y'] ?? 0);
        $width             = (int)($t['width'] ?? 275);
    }

    $sql = "INSERT INTO hdb_user_settings
                (admin_id, company_id, fontfamily, fontcolor, fontsize, fontweight, fontcolor_bg, fontbg_enable,
                 caption_style, caption_position, caption_alignment, caption_speed,
                 logo_name, logo_size, logo_position, logo_enabled,
                 position_x, position_y, width, last_niche_id, created_at)
            VALUES
                ($new_user_id, $new_company_id, '$fontfamily', '$fontcolor', $fontsize, '$fontweight',
                 '$fontcolor_bg', $fontbg_enable, '$caption_style', '$caption_position',
                 '$caption_alignment', $caption_speed, '$logo_name', '$logo_size', '$logo_position',
                 $logo_enabled, $position_x, $position_y, $width, $last_niche_id, NOW())";
    return mysqli_query($conn, $sql);
}

// ── Load admin plan + role ────────────────────────────────────
$admin_info = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT firstname, lastname, plan_type, role FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$plan_type = $admin_info['plan_type'] ?? 'free_trial';
$user_role = $admin_info['role']      ?? 'Team Lead';
$client_limit = 2; // Max clients allowed on non-agency plans (free_trial / personal)

if ($user_role === 'Team Member') {
    header("Location: vizard_browser.php?error=no_permission");
    exit;
}

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'add_client') {

        if ($plan_type !== 'agency') {
            $existing_count = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) as c FROM hdb_companies WHERE admin_id=$admin_id AND (company_type IS NULL OR company_type != 'internal')"))['c'];
            if ($existing_count >= $client_limit) {
                $plan_label = ucfirst(str_replace('_', ' ', $plan_type));
                echo json_encode([
                    'success' => false,
                    'limit'   => true,
                    'error'   => "You're on the {$plan_label} plan, which allows up to {$client_limit} clients. Upgrade to Agency for unlimited client workspaces.",
                ]);
                exit;
            }
        }

        $company_name = trim($_POST['company_name'] ?? '');
        $phone        = trim($_POST['phone']        ?? '');
        $email        = trim($_POST['email']        ?? '');
        $website      = trim($_POST['website']      ?? '');
        $address      = trim($_POST['address']      ?? '');
        $target_location = trim($_POST['target_location'] ?? '');
        $target_audience = trim($_POST['target_audience'] ?? '');
        $cta             = trim($_POST['cta']             ?? '');
        $username     = trim($_POST['username']     ?? '');
        $password_raw = trim($_POST['password']     ?? '');
        $status       = 'active';
        $company_type = 'client';

        if (empty($company_name)) {
            echo json_encode(['success' => false, 'error' => 'Company name is required']); exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']); exit;
        }
        if ($username !== '') {
            $uesc   = mysqli_real_escape_string($conn, $username);
            $ucheck = mysqli_query($conn, "SELECT id FROM hdb_companies WHERE admin_id=$admin_id AND client_username='$uesc' LIMIT 1");
            if ($ucheck && mysqli_num_rows($ucheck) > 0) {
                echo json_encode(['success' => false, 'error' => 'That username is already taken by another client.']); exit;
            }
        }

        $password_hash = $password_raw !== '' ? password_hash($password_raw, PASSWORD_DEFAULT) : '';
        $client_password = $password_raw;

        $company_esc  = mysqli_real_escape_string($conn, $company_name);
        $phone_esc    = mysqli_real_escape_string($conn, $phone);
        $email_esc    = mysqli_real_escape_string($conn, $email);
        $website_esc  = mysqli_real_escape_string($conn, $website);
        $address_esc  = mysqli_real_escape_string($conn, $address);
        $target_location_esc = mysqli_real_escape_string($conn, $target_location);
        $target_audience_esc = mysqli_real_escape_string($conn, $target_audience);
        $cta_esc             = mysqli_real_escape_string($conn, $cta);
        $username_esc = mysqli_real_escape_string($conn, $username);
        $pwhash_esc   = mysqli_real_escape_string($conn, $password_hash);
        $pw_plain_esc = mysqli_real_escape_string($conn, $client_password);
        $type_esc     = mysqli_real_escape_string($conn, $company_type);
        $today        = date('Y-m-d H:i:s');

        mysqli_query($conn, "START TRANSACTION");
        try {
            $ok = mysqli_query($conn,
                "INSERT INTO hdb_companies
                    (admin_id, companyname, logo_file, status, company_type,
                     phone, email, website, address, target_location, target_audience, cta,
                     client_username, client_password, password_hash, created_at)
                 VALUES
                    ($admin_id, '$company_esc', '', '$status', '$type_esc',
                     '$phone_esc', '$email_esc', '$website_esc', '$address_esc',
                     '$target_location_esc', '$target_audience_esc', '$cta_esc',
                     '$username_esc', '$pw_plain_esc', '$pwhash_esc', '$today')");
            if (!$ok) throw new Exception('Company insert failed: ' . mysqli_error($conn));
            $company_id = mysqli_insert_id($conn);
            cloneUserSettings($conn, $admin_id, $company_id);
            mysqli_query($conn, "COMMIT");
            echo json_encode([
                'success'      => true,
                'company_id'   => $company_id,
                'company_name' => $company_name,
                'phone'        => $phone,
                'email'        => $email,
                'website'      => $website,
                'address'      => $address,
                'target_location' => $target_location,
                'target_audience' => $target_audience,
                'cta'              => $cta,
                'username'     => $username,
                'password'     => $client_password,
                'has_password' => $password_hash !== '',
                'status'       => $status,
                'created_at'   => $today,
            ]);
        } catch (Exception $e) {
            mysqli_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'update_status') {
        $company_id = (int)($_POST['company_id'] ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['active','inactive','suspended'])
                      ? $_POST['status'] : 'active';
        $ok = mysqli_query($conn,
            "UPDATE hdb_companies SET status='$status' WHERE id=$company_id AND admin_id=$admin_id AND (company_type IS NULL OR company_type != 'internal')");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($_POST['ajax_action'] === 'delete_client') {
        $company_id = (int)($_POST['company_id'] ?? 0);
        $ok = mysqli_query($conn,
            "DELETE FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id AND (company_type IS NULL OR company_type != 'internal')");
        echo json_encode(['success' => (bool)$ok]);
        exit;
    }

    if ($_POST['ajax_action'] === 'edit_client') {
        $company_id   = (int)($_POST['company_id'] ?? 0);
        $company_name = trim($_POST['company_name'] ?? '');
        $phone        = trim($_POST['phone']        ?? '');
        $email        = trim($_POST['email']        ?? '');
        $website      = trim($_POST['website']      ?? '');
        $address      = trim($_POST['address']      ?? '');
        $target_location = trim($_POST['target_location'] ?? '');
        $target_audience = trim($_POST['target_audience'] ?? '');
        $cta             = trim($_POST['cta']             ?? '');
        $username     = trim($_POST['username']     ?? '');
        $password_raw = trim($_POST['password']     ?? '');

        if (!$company_id || empty($company_name)) {
            echo json_encode(['success' => false, 'error' => 'Company name is required']); exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']); exit;
        }
        if ($username !== '') {
            $uesc   = mysqli_real_escape_string($conn, $username);
            $ucheck = mysqli_query($conn,
                "SELECT id FROM hdb_companies WHERE admin_id=$admin_id AND client_username='$uesc' AND id<>$company_id LIMIT 1");
            if ($ucheck && mysqli_num_rows($ucheck) > 0) {
                echo json_encode(['success' => false, 'error' => 'That username is already taken by another client.']); exit;
            }
        }

        $company_esc  = mysqli_real_escape_string($conn, $company_name);
        $phone_esc    = mysqli_real_escape_string($conn, $phone);
        $email_esc    = mysqli_real_escape_string($conn, $email);
        $website_esc  = mysqli_real_escape_string($conn, $website);
        $address_esc  = mysqli_real_escape_string($conn, $address);
        $target_location_esc = mysqli_real_escape_string($conn, $target_location);
        $target_audience_esc = mysqli_real_escape_string($conn, $target_audience);
        $cta_esc             = mysqli_real_escape_string($conn, $cta);
        $username_esc = mysqli_real_escape_string($conn, $username);

        $pw_sql = '';
        if ($password_raw !== '') {
            $hash     = password_hash($password_raw, PASSWORD_DEFAULT);
            $hash_esc = mysqli_real_escape_string($conn, $hash);
            $plain_esc = mysqli_real_escape_string($conn, $password_raw);
            $pw_sql   = ", password_hash='$hash_esc', client_password='$plain_esc'";
        }

        $ok = mysqli_query($conn,
            "UPDATE hdb_companies
             SET companyname='$company_esc', phone='$phone_esc', email='$email_esc',
                 website='$website_esc', address='$address_esc',
                 target_location='$target_location_esc', target_audience='$target_audience_esc', cta='$cta_esc',
                 client_username='$username_esc'
                 $pw_sql
             WHERE id=$company_id AND admin_id=$admin_id AND (company_type IS NULL OR company_type != 'internal')");

        $pw_plain = '';
        if ($password_raw !== '') {
            $pw_plain = $password_raw;
        } else {
            $pw_res = mysqli_query($conn, "SELECT client_password FROM hdb_companies WHERE id=$company_id LIMIT 1");
            if ($pw_res && $pw_row = mysqli_fetch_assoc($pw_res)) {
                $pw_plain = $pw_row['client_password'];
            }
        }

        echo json_encode([
            'success'      => (bool)$ok,
            'company_id'   => $company_id,
            'company_name' => $company_name,
            'phone'        => $phone,
            'email'        => $email,
            'website'      => $website,
            'address'      => $address,
            'target_location' => $target_location,
            'target_audience' => $target_audience,
            'cta'              => $cta,
            'username'     => $username,
            'password'     => $pw_plain,
            'has_password' => ($password_raw !== '' || !empty($_POST['keep_password'])),
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']); exit;
}

// ── Load display info ─────────────────────────────────────────
$firstname     = $admin_info['firstname'] ?? 'User';
$admin_initial = strtoupper(substr($firstname, 0, 1));

// ── Load clients (EXCLUDE internal companies) ─────────────────
$clients = [];
$cq = mysqli_query($conn,
    "SELECT c.*,
            (SELECT COUNT(*) FROM hdb_podcasts p WHERE p.company_id = c.id) as video_count
     FROM hdb_companies c
     WHERE c.admin_id = $admin_id 
       AND c.companyname NOT IN ('VideoVizard', 'My Company', 'Internal')
       AND (c.company_type IS NULL OR c.company_type != 'internal')
     ORDER BY c.id DESC");
while ($r = mysqli_fetch_assoc($cq)) $clients[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clients — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --green:     #10b981;
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f0f4f8;
  --card:      #ffffff;
  --shadow:    0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
  --shadow-md: 0 4px 16px rgba(0,0,0,.1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);
     color:var(--text);min-height:100vh;display:flex;flex-direction:column;}

/* ── Header ── */
.vidora-header{display:flex;justify-content:space-between;align-items:center;
               padding:12px 20px;background:linear-gradient(90deg,#0f2a44,#143b63);
               color:#fff;box-shadow:0 3px 10px rgba(0,0,0,.15);
               position:sticky;top:0;z-index:1000;gap:12px;}
.brand-link{text-decoration:none;display:flex;align-items:center;gap:8px;}
.brand-icon{font-size:24px;}
.brand-name{font-size:18px;font-weight:700;}
.brand-video{color:#fff;}
.brand-vizard{color:var(--accent);}
.header-right{display:flex;align-items:center;gap:10px;}
.back-browser-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
                  color:#fff;padding:8px 16px;border-radius:10px;cursor:pointer;
                  font-size:14px;font-weight:600;display:flex;align-items:center;
                  gap:8px;transition:all .2s;text-decoration:none;}
.back-browser-btn:hover{background:rgba(255,255,255,.25);transform:translateY(-1px);}

/* ── Main ── */
.main{flex:1;padding:28px 20px;max-width:1100px;margin:0 auto;width:100%;}

/* ── Page header ── */
.page-hdr{display:flex;align-items:center;justify-content:space-between;
          flex-wrap:wrap;gap:14px;margin-bottom:28px;}
.page-title{font-size:26px;font-weight:800;color:var(--dark-blue);}
.page-title span{color:var(--green);}
.page-meta{font-size:13px;color:var(--muted);margin-top:3px;}

.btn-add{display:inline-flex;align-items:center;gap:8px;
         background:linear-gradient(135deg,#10b981,#059669);
         color:#fff;border:none;padding:12px 22px;border-radius:12px;
         font-size:14px;font-weight:700;cursor:pointer;
         box-shadow:0 4px 14px rgba(16,185,129,.3);transition:all .2s;
         font-family:'Plus Jakarta Sans',sans-serif;}
.btn-add:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(16,185,129,.4);}
.btn-add:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
.btn-add-limit{opacity:.6;}
.btn-add-limit:hover{opacity:.8;}

/* ── Stats strip ── */
.stats-strip{display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;
           padding:16px 20px;flex:1;min-width:140px;box-shadow:var(--shadow);}
.stat-card .sv{font-size:28px;font-weight:800;color:var(--dark-blue);line-height:1;}
.stat-card .sl{font-size:12px;color:var(--muted);margin-top:4px;font-weight:500;}
.stat-card.active-stat .sv{color:var(--green);}

/* ── Client grid ── */
.client-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;}

/* ── Client card ── */
.client-card{background:var(--card);border:1px solid var(--border);border-radius:16px;
             overflow:hidden;box-shadow:var(--shadow);transition:all .25s;position:relative;}
.client-card:hover{border-color:#93c5fd;box-shadow:0 6px 20px rgba(59,130,246,.12);
                   transform:translateY(-2px);}

.client-card-top{padding:20px 20px 14px;display:flex;align-items:flex-start;gap:14px;}
.client-avatar{width:48px;height:48px;border-radius:12px;flex-shrink:0;
               display:flex;align-items:center;justify-content:center;
               font-size:20px;font-weight:800;color:#fff;}
.client-info{flex:1;min-width:0;}
.client-name{font-size:16px;font-weight:700;color:var(--dark-blue);
             white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;}
.client-id{font-size:11px;color:var(--muted);font-weight:500;}

.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;
              border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;
              letter-spacing:.04em;white-space:nowrap;}
.status-badge.active    {background:#dcfce7;color:#15803d;}
.status-badge.inactive  {background:#f1f5f9;color:#64748b;}
.status-badge.suspended {background:#fee2e2;color:#b91c1c;}
.status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}

/* ── Contact details strip on card ── */
.client-details{padding:0 20px 12px;display:flex;flex-direction:column;gap:4px;
                border-bottom:1px solid var(--border);}
.detail-row{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--muted);}
.detail-row .di{width:20px;text-align:center;flex-shrink:0;font-size:13px;}
.detail-row .dv{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;}
.detail-row a{color:inherit;text-decoration:none;}
.detail-row a:hover{color:var(--dark-blue);text-decoration:underline;}
.copy-btn{background:none;border:1px solid var(--border);cursor:pointer;font-size:11px;padding:4px 8px;border-radius:6px;transition:all .15s;}
.copy-btn:hover{background:#e2e8f0;}

/* ── Card body / footer ── */
.client-card-body{padding:12px 20px 14px;display:flex;gap:16px;}
.client-stat{text-align:center;flex:1;}
.client-stat .cv{font-size:18px;font-weight:800;color:var(--dark-blue);}
.client-stat .cl{font-size:11px;color:var(--muted);margin-top:2px;}

.client-card-footer{padding:12px 16px;border-top:1px solid var(--border);
                    background:#fafbfc;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.client-date{font-size:11px;color:var(--muted);flex:1;}

.action-btn{padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;
            cursor:pointer;transition:all .15s;border:1.5px solid;
            font-family:'Plus Jakarta Sans',sans-serif;display:inline-flex;align-items:center;
            gap:4px;text-decoration:none;}
.action-btn.edit{background:#f8fafc;color:var(--muted);border-color:var(--border);}
.action-btn.edit:hover{background:#f1f5f9;color:var(--text);}
.action-btn.del{background:#fff;color:#ef4444;border-color:#fecaca;}
.action-btn.del:hover{background:#fef2f2;}
.action-btn.edit-profile{background:#fff7ed;color:#d97706;border-color:#fed7aa;}
.action-btn.edit-profile:hover{background:#ffedd5;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:60px 20px;background:var(--card);
             border:2px dashed var(--border);border-radius:16px;grid-column:1/-1;}
.empty-icon{font-size:56px;margin-bottom:16px;}
.empty-state h3{font-size:18px;font-weight:700;color:var(--dark-blue);margin-bottom:8px;}
.empty-state p{font-size:14px;color:var(--muted);}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
               z-index:2000;align-items:flex-start;justify-content:center;
               padding:20px;overflow-y:auto;}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--card);border-radius:20px;padding:32px;width:100%;max-width:520px;
       box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;margin:auto;}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal h2{font-size:20px;font-weight:800;color:var(--dark-blue);margin-bottom:6px;}
.modal-sub{font-size:13px;color:var(--muted);margin-bottom:22px;}

/* Form layout */
.form-section{margin-bottom:22px;}
.form-section-title{font-size:11px;font-weight:700;color:var(--muted);
                    text-transform:uppercase;letter-spacing:.06em;
                    padding-bottom:8px;border-bottom:1px solid var(--border);
                    margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.form-section-title .opt-tag{font-size:10px;font-weight:600;background:#f1f5f9;
                              color:var(--muted);padding:2px 7px;border-radius:10px;
                              text-transform:none;letter-spacing:0;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{margin-bottom:12px;}
.form-group:last-child{margin-bottom:0;}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--dark-blue);margin-bottom:5px;}
.required-star{color:#ef4444;}
.form-input{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:10px;
            font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;
            color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s;background:#fff;}
.form-input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.08);}
textarea.form-input{resize:vertical;min-height:60px;line-height:1.5;}

/* password eye toggle */
.pw-wrap{position:relative;}
.pw-wrap .form-input{padding-right:38px;}
.pw-eye{position:absolute;right:9px;top:50%;transform:translateY(-50%);
        background:none;border:none;cursor:pointer;font-size:14px;
        color:var(--muted);padding:4px;line-height:1;}

.modal-actions{display:flex;gap:10px;margin-top:8px;}
.btn-cancel{flex:1;padding:12px;border:1.5px solid var(--border);border-radius:10px;
            background:#fff;color:var(--muted);font-size:14px;font-weight:600;
            cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;}
.btn-cancel:hover{background:#f8fafc;}
.btn-submit{flex:2;padding:12px;border:none;border-radius:10px;
            background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));
            color:#fff;font-size:14px;font-weight:700;cursor:pointer;
            font-family:'Plus Jakarta Sans',sans-serif;transition:opacity .15s;}
.btn-submit:hover{opacity:.9;}
.btn-submit:disabled{opacity:.55;cursor:not-allowed;}

.alert-msg{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;display:none;}
.alert-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;}
.alert-msg.ok {background:#f0fdf4;border:1px solid #86efac;color:#166534;}

/* ── Toast ── */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);
       background:#1e293b;color:#fff;padding:10px 20px;border-radius:10px;
       font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;pointer-events:none;}

/* ── Footer ── */
.site-footer{background:linear-gradient(90deg,#0f2a44,#143b63);color:rgba(255,255,255,.55);
             padding:14px 20px;font-size:12px;display:flex;justify-content:center;
             align-items:center;gap:24px;flex-wrap:wrap;margin-top:auto;}
.site-footer a{color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;}
.site-footer a:hover{color:var(--accent);}
.footer-brand{font-weight:700;color:var(--accent);}

/* ── Avatar colours ── */
.av-0{background:linear-gradient(135deg,#3b82f6,#1d4ed8);}
.av-1{background:linear-gradient(135deg,#10b981,#059669);}
.av-2{background:linear-gradient(135deg,#8b5cf6,#6d28d9);}
.av-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
.av-4{background:linear-gradient(135deg,#ef4444,#b91c1c);}
.av-5{background:linear-gradient(135deg,#06b6d4,#0891b2);}

@media(max-width:640px){
  .main{padding:16px;}
  .client-grid{grid-template-columns:1fr;}
  .stats-strip{gap:10px;}
  .form-row{grid-template-columns:1fr;}
  .modal{padding:24px 20px;}
  .client-card-footer{flex-wrap:wrap;}
}
</style>
</head>
<body>

<!-- ══ Header with Back to Browser button (no dropdown) ═══════════════════ -->
<header class="vidora-header">
  <a class="brand-link" href="index.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name">
      <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
    </span>
  </a>
  <div class="header-right">
    <a href="vizard_browser.php" class="back-browser-btn">
      ← Back to Browser
    </a>
  </div>
</header>

<!-- ══ Add Client Modal ═════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal" onclick="modalOverlayClick(event)">
  <div class="modal" id="addModalPanel">
    <h2>➕ Add New Client</h2>
    <p class="modal-sub">Create a new client workspace. Settings are cloned from the default template.</p>

    <div class="alert-msg err" id="modalError"></div>
    <div class="alert-msg ok"  id="modalSuccess"></div>

    <div class="form-section">
      <div class="form-section-title">🏢 Company Info</div>

      <div class="form-group">
        <label class="form-label">Company / Client Name <span class="required-star">*</span></label>
        <input type="text" class="form-input" id="newCompanyName"
               placeholder="e.g. Acme Real Estate" maxlength="80" autocomplete="organization">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" class="form-input" id="newPhone"
                 placeholder="+1 555 000 0000" maxlength="30">
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" class="form-input" id="newEmail"
                 placeholder="client@example.com" maxlength="120">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Website</label>
        <input type="text" class="form-input" id="newWebsite"
               placeholder="https://example.com" maxlength="255">
      </div>

      <div class="form-group">
        <label class="form-label">Address</label>
        <textarea class="form-input" id="newAddress"
                  placeholder="123 Main St, City, State, ZIP" maxlength="400"></textarea>
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">
        🎯 Video Targeting
        <span class="opt-tag">Optional</span>
      </div>

      <div class="form-group">
        <label class="form-label">Target Location</label>
        <input type="text" class="form-input" id="newTargetLocation"
               placeholder="e.g. Toronto, ON / Nationwide" maxlength="150">
      </div>

      <div class="form-group">
        <label class="form-label">Target Audience</label>
        <textarea class="form-input" id="newTargetAudience"
                  placeholder="e.g. First-time homebuyers aged 28-45" maxlength="400"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Call To Action (CTA)</label>
        <input type="text" class="form-input" id="newCta"
               placeholder="e.g. Book a free consultation today" maxlength="200">
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">
        🔑 Client Login Credentials
        <span class="opt-tag">Optional</span>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-input" id="newUsername"
                 placeholder="client_username" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="pw-wrap">
            <input type="password" class="form-input" id="newPassword"
                   placeholder="Set a password" maxlength="128" autocomplete="new-password">
            <button type="button" class="pw-eye" id="pwEyeBtn" onclick="togglePw()" title="Show/hide">👁</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-submit" id="submitBtn" onclick="submitAddClient()">➕ Create Client</button>
    </div>
  </div>
</div>

<!-- ══ Edit Profile Modal ════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal" onclick="editModalOverlayClick(event)">
  <div class="modal" id="editModalPanel">
    <h2>✏️ Edit Company Profile</h2>
    <p class="modal-sub">Update this client's profile details. Leave Password blank to keep the current one.</p>

    <div class="alert-msg err" id="editModalError"></div>
    <div class="alert-msg ok"  id="editModalSuccess"></div>
    <input type="hidden" id="editCompanyId">
    <input type="hidden" id="editCurrentPassword">

    <div class="form-section">
      <div class="form-section-title">🏢 Company Info</div>
      <div class="form-group">
        <label class="form-label">Company / Client Name <span class="required-star">*</span></label>
        <input type="text" class="form-input" id="editCompanyName" maxlength="80" autocomplete="organization">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="tel" class="form-input" id="editPhone" maxlength="30">
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <input type="email" class="form-input" id="editEmail" maxlength="120">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Website</label>
        <input type="text" class="form-input" id="editWebsite" maxlength="255">
      </div>
      <div class="form-group">
        <label class="form-label">Address</label>
        <textarea class="form-input" id="editAddress" maxlength="400"></textarea>
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">
        🎯 Video Targeting
        <span class="opt-tag">Optional</span>
      </div>

      <div class="form-group">
        <label class="form-label">Target Location</label>
        <input type="text" class="form-input" id="editTargetLocation"
               placeholder="e.g. Toronto, ON / Nationwide" maxlength="150">
      </div>

      <div class="form-group">
        <label class="form-label">Target Audience</label>
        <textarea class="form-input" id="editTargetAudience"
                  placeholder="e.g. First-time homebuyers aged 28-45" maxlength="400"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Call To Action (CTA)</label>
        <input type="text" class="form-input" id="editCta"
               placeholder="e.g. Book a free consultation today" maxlength="200">
      </div>
    </div>

    <div class="form-section">
      <div class="form-section-title">
        🔑 Client Login Credentials
        <span class="opt-tag">Optional</span>
      </div>
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <div class="detail-row" style="background:#f8fafc;padding:8px 12px;border-radius:8px;">
          <span class="di">🔑</span>
          <span class="dv" id="currentPwDisplay" style="font-family: monospace;"></span>
          <button type="button" class="copy-btn" onclick="copyCurrentPassword()">📋 Copy</button>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-input" id="editUsername" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">New Password <span style="color:var(--muted);font-weight:400;">(leave blank to keep)</span></label>
          <div class="pw-wrap">
            <input type="password" class="form-input" id="editPassword" maxlength="128" autocomplete="new-password" placeholder="New password…">
            <button type="button" class="pw-eye" onclick="toggleEditPw()" title="Show/hide">👁</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeEditModal()">Cancel</button>
      <button class="btn-submit" id="editSubmitBtn" onclick="submitEditClient()">💾 Save Changes</button>
    </div>
  </div>
</div>

<!-- ══ Delete Confirm Modal ═════════════════════════════════════ -->
<div class="modal-overlay" id="delModal" onclick="delOverlayClick(event)">
  <div class="modal" id="delModalPanel">
    <h2>Delete Client?</h2>
    <p id="delModalMsg">This will remove the client workspace. Videos created under this client will remain but lose their company association.</p>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeDelModal()">Cancel</button>
      <button class="btn-submit" style="background:linear-gradient(135deg,#ef4444,#b91c1c);"
              id="confirmDelBtn">🗑 Delete</button>
    </div>
  </div>
</div>

<!-- ══ Main ════════════════════════════════════════════════════ -->
<div class="main">

  <div class="page-hdr">
    <div>
      <h1 class="page-title">My <span>Clients</span></h1>
      <div class="page-meta">
        <?= count($clients) ?> client workspace<?= count($clients)!==1?'s':'' ?> under your account
        &nbsp;·&nbsp;
        <?php if ($plan_type === 'agency'): ?>
          <span style="background:#dcfce7;color:#15803d;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Agency — Unlimited workspaces</span>
        <?php elseif ($plan_type === 'personal'): ?>
          <span style="background:#eff6ff;color:#1d4ed8;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Personal — 2 client max</span>
        <?php else: ?>
          <span style="background:#fef9c3;color:#854d0e;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Free Trial — 2 client max</span>
        <?php endif; ?>
      </div>
    </div>
    <button class="btn-add<?= ($plan_type !== 'agency' && count($clients) >= $client_limit) ? ' btn-add-limit' : '' ?>" id="addClientBtn" onclick="handleAddClientClick()">
      ➕ Add Client
    </button>
  </div>

  <?php if ($plan_type !== 'agency' && count($clients) >= $client_limit): ?>
  <div style="background:#fffbeb;border:1.5px solid #fbbf24;border-radius:12px;
              padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;
              justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="font-size:14px;color:#92400e;font-weight:600;">
      🔒 You're on the <strong><?= ucfirst(str_replace('_', ' ', $plan_type)) ?></strong> plan, which allows up to <?= $client_limit ?> clients — and you've reached that limit.
      Upgrade to Agency for unlimited client workspaces.
    </div>
    <a href="pricing.php" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;
       padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;
       text-decoration:none;white-space:nowrap;">Upgrade →</a>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <?php
  $active_count   = count(array_filter($clients, fn($c) => $c['status']==='active'));
  $inactive_count = count($clients) - $active_count;
  $total_videos   = array_sum(array_column($clients, 'video_count'));
  ?>
  <div class="stats-strip">
    <div class="stat-card active-stat">
      <div class="sv"><?= $active_count ?></div>
      <div class="sl">Active clients</div>
    </div>
    <div class="stat-card">
      <div class="sv"><?= count($clients) ?></div>
      <div class="sl">Total workspaces</div>
    </div>
    <div class="stat-card">
      <div class="sv"><?= number_format($total_videos) ?></div>
      <div class="sl">Total videos</div>
    </div>
    <div class="stat-card">
      <div class="sv"><?= $inactive_count ?></div>
      <div class="sl">Inactive</div>
    </div>
  </div>

  <!-- Client grid -->
  <div class="client-grid" id="clientGrid">
    <?php if (empty($clients)): ?>
    <div class="empty-state">
      <div class="empty-icon">🏢</div>
      <h3>No clients yet</h3>
      <p>Click "Add Client" to create your first client workspace.</p>
    </div>
    <?php else: ?>
    <?php foreach ($clients as $idx => $client):
      $initial  = strtoupper(substr($client['companyname'], 0, 1));
      $av_class = 'av-' . ($idx % 6);
      $status   = $client['status'] ?? 'active';
      $date_fmt = $client['created_at'] ? date('M j, Y', strtotime($client['created_at'])) : '—';
      $ph    = trim($client['phone']         ?? '');
      $em    = trim($client['email']         ?? '');
      $wb    = trim($client['website']       ?? '');
      $ad    = trim($client['address']       ?? '');
      $un    = trim($client['client_username'] ?? '');
      $pw    = trim($client['client_password'] ?? '');
      $hasPw = !empty($client['client_password']) || !empty($client['password_hash']);
      $tl    = trim($client['target_location']  ?? '');
      $taud  = trim($client['target_audience']  ?? '');
      $cta_v = trim($client['cta']              ?? '');
    ?>
    <div class="client-card" id="cc-<?= $client['id'] ?>">

      <div class="client-card-top">
        <div class="client-avatar <?= $av_class ?>"><?= htmlspecialchars($initial) ?></div>
        <div class="client-info">
          <div class="client-name"><?= htmlspecialchars($client['companyname']) ?></div>
          <div class="client-id">ID #<?= $client['id'] ?></div>
          <?php if ($un): ?>
          <div class="client-username" style="margin-top: 4px; font-size: 12px; color: var(--green); font-weight: 600;">
            👤 Username: <?= htmlspecialchars($un) ?>
          </div>
          <?php endif; ?>
        </div>
        <span class="status-badge <?= $status ?>">
          <span class="status-dot"></span><?= ucfirst($status) ?>
        </span>
      </div>

      <?php if ($ph || $em || $wb || $ad || $tl || $taud || $cta_v): ?>
      <div class="client-details">
        <?php if ($ph): ?>
          <div class="detail-row">
            <span class="di">📞</span>
            <span class="dv"><a href="tel:<?= htmlspecialchars($ph) ?>"><?= htmlspecialchars($ph) ?></a></span>
          </div>
        <?php endif; ?>
        <?php if ($em): ?>
          <div class="detail-row">
            <span class="di">✉️</span>
            <span class="dv"><a href="mailto:<?= htmlspecialchars($em) ?>"><?= htmlspecialchars($em) ?></a></span>
          </div>
        <?php endif; ?>
        <?php if ($wb): ?>
          <div class="detail-row">
            <span class="di">🌐</span>
            <span class="dv"><a href="<?= htmlspecialchars($wb) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(preg_replace('#^https?://#','', rtrim($wb,'/'))) ?></a></span>
          </div>
        <?php endif; ?>
        <?php if ($ad): ?>
          <div class="detail-row">
            <span class="di">📍</span>
            <span class="dv" title="<?= htmlspecialchars($ad) ?>"><?= htmlspecialchars($ad) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($tl): ?>
          <div class="detail-row">
            <span class="di">🎯</span>
            <span class="dv" title="<?= htmlspecialchars($tl) ?>"><?= htmlspecialchars($tl) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($taud): ?>
          <div class="detail-row">
            <span class="di">👥</span>
            <span class="dv" title="<?= htmlspecialchars($taud) ?>"><?= htmlspecialchars($taud) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($cta_v): ?>
          <div class="detail-row">
            <span class="di">📣</span>
            <span class="dv" title="<?= htmlspecialchars($cta_v) ?>"><?= htmlspecialchars($cta_v) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div style="padding: 8px 20px 0; border-bottom: 1px solid var(--border);">
        <?php if ($pw): ?>
          <div class="detail-row" style="margin-bottom: 8px;">
            <span class="di">🔑</span>
            <span class="dv" style="color: #059669; font-weight: 600; font-family: monospace;">
              Password: <?= htmlspecialchars($pw) ?>
            </span>
            <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($pw, ENT_QUOTES) ?>')" title="Copy password">📋 Copy</button>
          </div>
        <?php elseif ($hasPw && !$pw): ?>
          <div class="detail-row" style="margin-bottom: 8px;">
            <span class="di">🔑</span>
            <span class="dv" style="color: #f59e0b; font-weight: 600;">Password: (legacy - set new password to view)</span>
            <button class="action-btn edit" style="margin-left: auto; padding: 2px 8px; font-size: 11px;"
                    onclick="openEditModal(<?= $client['id'] ?>, '<?= htmlspecialchars($client['companyname'], ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($ph, ENT_QUOTES) ?>', '<?= htmlspecialchars($em, ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($wb, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($ad), ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($un, ENT_QUOTES) ?>', '', '<?= htmlspecialchars($tl, ENT_QUOTES) ?>',
                      '<?= htmlspecialchars(addslashes($taud), ENT_QUOTES) ?>', '<?= htmlspecialchars($cta_v, ENT_QUOTES) ?>')">
              Set Password
            </button>
          </div>
        <?php else: ?>
          <div class="detail-row" style="margin-bottom: 8px;">
            <span class="di">⚠️</span>
            <span class="dv" style="color: #f59e0b;">No password set</span>
            <button class="action-btn edit" style="margin-left: auto; padding: 2px 8px; font-size: 11px;"
                    onclick="openEditModal(<?= $client['id'] ?>, '<?= htmlspecialchars($client['companyname'], ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($ph, ENT_QUOTES) ?>', '<?= htmlspecialchars($em, ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($wb, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($ad), ENT_QUOTES) ?>',
                      '<?= htmlspecialchars($un, ENT_QUOTES) ?>', '', '<?= htmlspecialchars($tl, ENT_QUOTES) ?>',
                      '<?= htmlspecialchars(addslashes($taud), ENT_QUOTES) ?>', '<?= htmlspecialchars($cta_v, ENT_QUOTES) ?>')">
              Set Password
            </button>
          </div>
        <?php endif; ?>
      </div>

      <div class="client-card-body">
        <div class="client-stat">
          <div class="cv"><?= number_format($client['video_count']) ?></div>
          <div class="cl">Videos</div>
        </div>
        <div class="client-stat">
          <div class="cv" style="font-size:13px;font-weight:700;"><?= $date_fmt ?></div>
          <div class="cl">Created</div>
        </div>
        <div class="client-stat">
          <div class="cv" style="font-size:16px;"><?= $pw ? '🔑' : '⚠️' ?></div>
          <div class="cl"><?= $pw ? 'Has Login' : 'No Login' ?></div>
        </div>
      </div>

      <!-- Footer actions - NO Switch, NO Profile, NO Settings buttons -->
      <div class="client-card-footer">
        <span class="client-date"><?= $client['logo_file'] ? '🖼 Has logo' : 'No logo' ?></span>
        <button class="action-btn edit-profile"
                onclick="openEditModal(<?= $client['id'] ?>, '<?= htmlspecialchars($client['companyname'], ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($ph, ENT_QUOTES) ?>', '<?= htmlspecialchars($em, ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($wb, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($ad), ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($un, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($pw), ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($tl, ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($taud), ENT_QUOTES) ?>',
                  '<?= htmlspecialchars($cta_v, ENT_QUOTES) ?>')">
          ✏️ Edit
        </button>
        <button class="action-btn edit"
                onclick="toggleStatus(<?= $client['id'] ?>, '<?= $status ?>', this)">
          <?= $status==='active' ? 'Deactivate' : 'Activate' ?>
        </button>
        <button class="action-btn del"
                onclick="confirmDelete(<?= $client['id'] ?>, '<?= htmlspecialchars($client['companyname'], ENT_QUOTES) ?>')">
          🗑 Delete
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="vizard_browser.php">Projects</a>
  <a href="user_team.php">My Team</a>
  <a href="settings.php">Settings</a>
  <span>© <?= date('Y') ?> VideoVizard</span>
</footer>

<div class="toast" id="toast" style="opacity:0"></div>

<script>
const ADMIN_ID     = <?= $admin_id ?>;
const PLAN_TYPE     = '<?= $plan_type ?>';
const CLIENT_LIMIT  = <?= $client_limit ?>;

function togglePw(){
  const f = document.getElementById('newPassword');
  f.type = (f.type === 'password') ? 'text' : 'password';
}
function toggleEditPw(){
  const f = document.getElementById('editPassword');
  f.type = (f.type === 'password') ? 'text' : 'password';
}

function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(function() {
    showToast('📋 Copied to clipboard!');
  }).catch(function() {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    showToast('📋 Copied!');
  });
}

function copyCurrentPassword() {
  const pw = document.getElementById('editCurrentPassword').value;
  if (pw) {
    copyToClipboard(pw);
  } else {
    showToast('⚠ No password to copy');
  }
}

const MODAL_FIELDS = ['newCompanyName','newPhone','newEmail','newWebsite','newAddress','newTargetLocation','newTargetAudience','newCta','newUsername','newPassword'];

function planLabel(plan){
  return plan.charAt(0).toUpperCase() + plan.slice(1).replace('_', ' ');
}

function handleAddClientClick(){
  const count = document.querySelectorAll('.client-card').length;
  if (PLAN_TYPE !== 'agency' && count >= CLIENT_LIMIT) {
    alert(`You're on the ${planLabel(PLAN_TYPE)} plan, which allows up to ${CLIENT_LIMIT} clients — and you've reached that limit.\n\nUpgrade to Agency for unlimited client workspaces.`);
    return;
  }
  openModal();
}

function openModal(){
  MODAL_FIELDS.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  document.getElementById('modalError').style.display   = 'none';
  document.getElementById('modalSuccess').style.display = 'none';
  document.getElementById('addModal').classList.add('open');
  setTimeout(() => document.getElementById('newCompanyName').focus(), 120);
}
function closeModal(){ document.getElementById('addModal').classList.remove('open'); }
function modalOverlayClick(e){ if(e.target === document.getElementById('addModal')) closeModal(); }

document.addEventListener('keydown', e => {
  if(e.key === 'Escape'){ closeModal(); closeDelModal(); closeEditModal(); }
});

async function submitAddClient(){
  const get = id => document.getElementById(id)?.value?.trim() ?? '';
  const name    = get('newCompanyName');
  const phone   = get('newPhone');
  const email   = get('newEmail');
  const website = get('newWebsite');
  const address = document.getElementById('newAddress')?.value?.trim() ?? '';
  const targetLocation  = get('newTargetLocation');
  const targetAudience  = document.getElementById('newTargetAudience')?.value?.trim() ?? '';
  const cta             = get('newCta');
  const uname   = get('newUsername');
  const pw      = document.getElementById('newPassword')?.value ?? '';

  const errEl = document.getElementById('modalError');
  const sucEl = document.getElementById('modalSuccess');
  const btn   = document.getElementById('submitBtn');

  errEl.style.display = 'none';
  sucEl.style.display = 'none';

  if (!name) {
    showModalErr('Company name is required.');
    document.getElementById('newCompanyName').focus();
    return;
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    showModalErr('Please enter a valid email address.');
    document.getElementById('newEmail').focus();
    return;
  }

  btn.disabled = true;
  btn.textContent = '⏳ Creating…';

  const fd = new FormData();
  fd.append('ajax_action',  'add_client');
  fd.append('company_name', name);
  fd.append('phone',        phone);
  fd.append('email',        email);
  fd.append('website',      website);
  fd.append('address',      address);
  fd.append('target_location', targetLocation);
  fd.append('target_audience', targetAudience);
  fd.append('cta',              cta);
  fd.append('username',     uname);
  fd.append('password',     pw);

  try {
    const r = await fetch('user_clients.php', {method:'POST', body:fd, credentials:'include'});
    const d = await r.json();

    if (d.success) {
      sucEl.textContent    = `✓ Client "${name}" created successfully!`;
      sucEl.style.display  = 'block';
      addClientCard(d);
      updateStats();
      updateLimitUI();
      showToast('✅ Client created — ID #' + d.company_id);
      setTimeout(closeModal, 1300);
    } else if (d.limit) {
      errEl.innerHTML     = d.error + ' <a href="pricing.php" style="color:#b91c1c;font-weight:700;text-decoration:underline;">Upgrade →</a>';
      errEl.style.display = 'block';
    } else {
      showModalErr(d.error || 'Failed to create client.');
    }
  } catch(e) {
    showModalErr('Network error — please try again.');
  }

  btn.disabled    = false;
  btn.textContent = '➕ Create Client';
}

function showModalErr(msg){
  const el = document.getElementById('modalError');
  el.textContent  = msg;
  el.style.display = 'block';
}

function updateLimitUI(){
  const addBtn = document.getElementById('addClientBtn');
  const count  = document.querySelectorAll('.client-card').length;
  const atLimit = (PLAN_TYPE !== 'agency' && count >= CLIENT_LIMIT);
  // Keep the button clickable even at the limit — handleAddClientClick() shows
  // a clear alert explaining why. A hard `disabled` attribute would silently
  // swallow the click and leave the user with no explanation.
  if (addBtn) addBtn.classList.toggle('btn-add-limit', atLimit);
}

function addClientCard(d){
  const grid = document.getElementById('clientGrid');
  grid.querySelector('.empty-state')?.remove();

  const colors = ['av-0','av-1','av-2','av-3','av-4','av-5'];
  const av      = colors[grid.querySelectorAll('.client-card').length % 6];
  const initial = d.company_name.charAt(0).toUpperCase();
  const today   = new Date().toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});

  let detailRows = '';
  if(d.phone)   detailRows += `<div class="detail-row"><span class="di">📞</span><span class="dv"><a href="tel:${escH(d.phone)}">${escH(d.phone)}</a></span></div>`;
  if(d.email)   detailRows += `<div class="detail-row"><span class="di">✉️</span><span class="dv"><a href="mailto:${escH(d.email)}">${escH(d.email)}</a></span></div>`;
  if(d.website) detailRows += `<div class="detail-row"><span class="di">🌐</span><span class="dv"><a href="${escH(d.website)}" target="_blank" rel="noopener">${escH(d.website.replace(/^https?:\/\//,'').replace(/\/$/,''))}</a></span></div>`;
  if(d.address) detailRows += `<div class="detail-row"><span class="di">📍</span><span class="dv">${escH(d.address)}</span></div>`;
  if(d.target_location) detailRows += `<div class="detail-row"><span class="di">🎯</span><span class="dv">${escH(d.target_location)}</span></div>`;
  if(d.target_audience) detailRows += `<div class="detail-row"><span class="di">👥</span><span class="dv">${escH(d.target_audience)}</span></div>`;
  if(d.cta)              detailRows += `<div class="detail-row"><span class="di">📣</span><span class="dv">${escH(d.cta)}</span></div>`;
  const detailSection = detailRows ? `<div class="client-details">${detailRows}</div>` : '';

  const unLabel = d.username ? ` &nbsp;·&nbsp; @${escH(d.username)}` : '';
  const pwDisplay = d.password ? `<div class="detail-row" style="margin-bottom:8px;"><span class="di">🔑</span><span class="dv" style="color:#059669;font-weight:600;font-family:monospace;">Password: ${escH(d.password)}</span><button class="copy-btn" onclick="copyToClipboard('${escA(d.password)}')">📋 Copy</button></div>` : '<div class="detail-row"><span class="di">⚠️</span><span class="dv" style="color:#f59e0b;">No password set</span></div>';

  const card = document.createElement('div');
  card.className = 'client-card';
  card.id        = 'cc-' + d.company_id;
  card.innerHTML = `
    <div class="client-card-top">
      <div class="client-avatar ${av}">${initial}</div>
      <div class="client-info">
        <div class="client-name">${escH(d.company_name)}</div>
        <div class="client-id">ID #${d.company_id}${unLabel}</div>
        ${d.username ? `<div class="client-username" style="margin-top:4px;font-size:12px;color:var(--green);font-weight:600;">👤 Username: ${escH(d.username)}</div>` : ''}
      </div>
      <span class="status-badge active"><span class="status-dot"></span>Active</span>
    </div>
    ${detailSection}
    <div style="padding:8px 20px 0;border-bottom:1px solid var(--border);">
      ${pwDisplay}
    </div>
    <div class="client-card-body">
      <div class="client-stat"><div class="cv">0</div><div class="cl">Videos</div></div>
      <div class="client-stat"><div class="cv" style="font-size:13px;font-weight:700;">${today}</div><div class="cl">Created</div></div>
      <div class="client-stat"><div class="cv" style="font-size:16px;">${d.has_password ? '🔑' : '⚠️'}</div><div class="cl">${d.has_password ? 'Has Login' : 'No Login'}</div></div>
    </div>
    <div class="client-card-footer">
      <span class="client-date">No logo</span>
      <button class="action-btn edit-profile"
              onclick="openEditModal(${d.company_id},'${escA(d.company_name)}','${escA(d.phone||'')}','${escA(d.email||'')}','${escA(d.website||'')}','${escA(d.address||'')}','${escA(d.username||'')}','${escA(d.password||'')}','${escA(d.target_location||'')}','${escA(d.target_audience||'')}','${escA(d.cta||'')}')">✏️ Edit</button>
      <button class="action-btn edit" onclick="toggleStatus(${d.company_id},'active',this)">Deactivate</button>
      <button class="action-btn del"  onclick="confirmDelete(${d.company_id},'${escA(d.company_name)}')">🗑 Delete</button>
    </div>`;

  grid.insertBefore(card, grid.firstChild);
  card.style.animation = 'slideUp .3s ease';
}

let currentClientPassword = '';

function openEditModal(id, name, phone, email, website, address, username, password, targetLocation, targetAudience, cta){
  document.getElementById('editCompanyId').value   = id;
  document.getElementById('editCompanyName').value = name;
  document.getElementById('editPhone').value       = phone;
  document.getElementById('editEmail').value       = email;
  document.getElementById('editWebsite').value     = website;
  document.getElementById('editAddress').value     = address;
  document.getElementById('editTargetLocation').value = targetLocation || '';
  document.getElementById('editTargetAudience').value = targetAudience || '';
  document.getElementById('editCta').value             = cta || '';
  document.getElementById('editUsername').value    = username;
  document.getElementById('editPassword').value    = '';
  document.getElementById('editCurrentPassword').value = password || '';
  currentClientPassword = password || '';
  
  const pwDisplay = document.getElementById('currentPwDisplay');
  if (password) {
    pwDisplay.innerHTML = `<span style="font-family:monospace;color:#059669;">${escapeHtml(password)}</span>`;
  } else {
    pwDisplay.innerHTML = '<span style="color:#f59e0b;">No password set</span>';
  }
  
  document.getElementById('editModalError').style.display   = 'none';
  document.getElementById('editModalSuccess').style.display = 'none';
  document.getElementById('editModal').classList.add('open');
  setTimeout(() => document.getElementById('editCompanyName').focus(), 120);
}
function closeEditModal(){ document.getElementById('editModal').classList.remove('open'); }
function editModalOverlayClick(e){ if(e.target === document.getElementById('editModal')) closeEditModal(); }

async function submitEditClient(){
  const id      = document.getElementById('editCompanyId').value;
  const get     = elId => document.getElementById(elId)?.value?.trim() ?? '';
  const name    = get('editCompanyName');
  const phone   = get('editPhone');
  const email   = get('editEmail');
  const website = get('editWebsite');
  const address = document.getElementById('editAddress')?.value?.trim() ?? '';
  const targetLocation  = get('editTargetLocation');
  const targetAudience  = document.getElementById('editTargetAudience')?.value?.trim() ?? '';
  const cta             = get('editCta');
  const uname   = get('editUsername');
  const pw      = document.getElementById('editPassword')?.value ?? '';

  const errEl = document.getElementById('editModalError');
  const sucEl = document.getElementById('editModalSuccess');
  const btn   = document.getElementById('editSubmitBtn');

  errEl.style.display = 'none';
  sucEl.style.display = 'none';

  if (!name) {
    errEl.textContent = 'Company name is required.';
    errEl.style.display = 'block';
    document.getElementById('editCompanyName').focus();
    return;
  }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errEl.textContent = 'Please enter a valid email address.';
    errEl.style.display = 'block';
    return;
  }

  btn.disabled = true; btn.textContent = '⏳ Saving…';

  const fd = new FormData();
  fd.append('ajax_action',  'edit_client');
  fd.append('company_id',   id);
  fd.append('company_name', name);
  fd.append('phone',        phone);
  fd.append('email',        email);
  fd.append('website',      website);
  fd.append('address',      address);
  fd.append('target_location', targetLocation);
  fd.append('target_audience', targetAudience);
  fd.append('cta',              cta);
  fd.append('username',     uname);
  fd.append('password',     pw);

  try {
    const r = await fetch('user_clients.php', {method:'POST', body:fd, credentials:'include'});
    const d = await r.json();
    if (d.success) {
      sucEl.textContent   = `✓ "${name}" updated successfully!`;
      sucEl.style.display = 'block';
      const card = document.getElementById('cc-' + d.company_id);
      if (card) {
        const nameEl = card.querySelector('.client-name');
        if (nameEl) nameEl.textContent = name;
        const idEl = card.querySelector('.client-id');
        if (idEl) idEl.innerHTML = `ID #${d.company_id}${uname ? ' &nbsp;·&nbsp; @' + escH(uname) : ''}`;
        
        let usernameDiv = card.querySelector('.client-username');
        if (uname) {
          if (!usernameDiv) {
            usernameDiv = document.createElement('div');
            usernameDiv.className = 'client-username';
            usernameDiv.style.cssText = 'margin-top:4px;font-size:12px;color:var(--green);font-weight:600;';
            card.querySelector('.client-info').appendChild(usernameDiv);
          }
          usernameDiv.innerHTML = `👤 Username: ${escH(uname)}`;
        } else if (usernameDiv) {
          usernameDiv.remove();
        }

        // Rebuild the contact/target details block to reflect the latest values
        let detailsDiv = card.querySelector('.client-details');
        let detailRows = '';
        if (phone)   detailRows += `<div class="detail-row"><span class="di">📞</span><span class="dv"><a href="tel:${escH(phone)}">${escH(phone)}</a></span></div>`;
        if (email)   detailRows += `<div class="detail-row"><span class="di">✉️</span><span class="dv"><a href="mailto:${escH(email)}">${escH(email)}</a></span></div>`;
        if (website) detailRows += `<div class="detail-row"><span class="di">🌐</span><span class="dv"><a href="${escH(website)}" target="_blank" rel="noopener">${escH(website.replace(/^https?:\/\//,'').replace(/\/$/,''))}</a></span></div>`;
        if (address) detailRows += `<div class="detail-row"><span class="di">📍</span><span class="dv">${escH(address)}</span></div>`;
        if (targetLocation) detailRows += `<div class="detail-row"><span class="di">🎯</span><span class="dv">${escH(targetLocation)}</span></div>`;
        if (targetAudience) detailRows += `<div class="detail-row"><span class="di">👥</span><span class="dv">${escH(targetAudience)}</span></div>`;
        if (cta)             detailRows += `<div class="detail-row"><span class="di">📣</span><span class="dv">${escH(cta)}</span></div>`;
        if (detailRows) {
          if (!detailsDiv) {
            detailsDiv = document.createElement('div');
            detailsDiv.className = 'client-details';
            card.querySelector('.client-card-top').insertAdjacentElement('afterend', detailsDiv);
          }
          detailsDiv.innerHTML = detailRows;
        } else if (detailsDiv) {
          detailsDiv.remove();
        }

        let pwRow = card.querySelector('.client-details + div') || card.querySelector('.client-card-body')?.previousElementSibling;
        if (pwRow && pwRow.style.borderBottom) {
          const newPw = d.password || '';
          if (newPw) {
            pwRow.innerHTML = `<div class="detail-row" style="margin-bottom:8px;">
              <span class="di">🔑</span>
              <span class="dv" style="color:#059669;font-weight:600;font-family:monospace;">Password: ${escH(newPw)}</span>
              <button class="copy-btn" onclick="copyToClipboard('${escA(newPw)}')">📋 Copy</button>
            </div>`;
          } else {
            pwRow.innerHTML = `<div class="detail-row" style="margin-bottom:8px;">
              <span class="di">⚠️</span>
              <span class="dv" style="color:#f59e0b;">No password set</span>
              <button class="action-btn edit" style="margin-left:auto;padding:2px 8px;font-size:11px;"
                onclick="openEditModal(${d.company_id},'${escA(name)}','${escA(phone)}','${escA(email)}','${escA(website)}','${escA(address)}','${escA(uname)}','','${escA(targetLocation)}','${escA(targetAudience)}','${escA(cta)}')">Set Password</button>
            </div>`;
          }
        }
        
        const editBtn = card.querySelector('.action-btn.edit-profile');
        if (editBtn) {
          editBtn.onclick = () => openEditModal(d.company_id, name, phone, email, website, address, uname, d.password || '', targetLocation, targetAudience, cta);
        }
      }
      showToast('✅ Profile updated');
      setTimeout(closeEditModal, 1200);
    } else {
      errEl.textContent  = d.error || 'Failed to save changes.';
      errEl.style.display = 'block';
    }
  } catch(e) {
    errEl.textContent  = 'Network error — please try again.';
    errEl.style.display = 'block';
  }
  btn.disabled = false; btn.textContent = '💾 Save Changes';
}

async function toggleStatus(id, currentStatus, btn){
  const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
  const fd = new FormData();
  fd.append('ajax_action','update_status');
  fd.append('company_id', id);
  fd.append('status',     newStatus);
  try {
    const r = await fetch('user_clients.php',{method:'POST',body:fd,credentials:'include'});
    const d = await r.json();
    if(d.success){
      const card  = document.getElementById('cc-'+id);
      const badge = card.querySelector('.status-badge');
      badge.className = 'status-badge ' + newStatus;
      badge.innerHTML = `<span class="status-dot"></span>${newStatus.charAt(0).toUpperCase()+newStatus.slice(1)}`;
      btn.textContent = newStatus === 'active' ? 'Deactivate' : 'Activate';
      btn.onclick = () => toggleStatus(id, newStatus, btn);
      showToast(`Client ${newStatus === 'active' ? 'activated' : 'deactivated'}`);
      updateStats();
    }
  } catch(e){ showToast('⚠ Error updating status'); }
}

let pendingDeleteId = null;
function confirmDelete(id, name){
  pendingDeleteId = id;
  document.getElementById('delModalMsg').textContent = `Delete "${name}"? This cannot be undone.`;
  document.getElementById('delModal').classList.add('open');
}
function closeDelModal(){ document.getElementById('delModal').classList.remove('open'); pendingDeleteId = null; }
function delOverlayClick(e){ if(e.target === document.getElementById('delModal')) closeDelModal(); }

document.getElementById('confirmDelBtn').addEventListener('click', async () => {
  if(!pendingDeleteId) return;
  const fd = new FormData();
  fd.append('ajax_action','delete_client');
  fd.append('company_id', pendingDeleteId);
  try {
    const r = await fetch('user_clients.php',{method:'POST',body:fd,credentials:'include'});
    const d = await r.json();
    if(d.success){
      const card = document.getElementById('cc-'+pendingDeleteId);
      if(card){
        card.style.cssText += ';opacity:0;transform:scale(.95);transition:.3s;';
        setTimeout(() => { card.remove(); updateStats(); }, 300);
      }
      showToast('🗑 Client deleted');
    } else { showToast('⚠ Delete failed'); }
  } catch(e){ showToast('⚠ Network error'); }
  closeDelModal();
});

function updateStats(){
  const cards    = document.querySelectorAll('.client-card');
  const active   = document.querySelectorAll('.status-badge.active').length;
  const statsVals = document.querySelectorAll('.stat-card .sv');
  if(statsVals[0]) statsVals[0].textContent = active;
  if(statsVals[1]) statsVals[1].textContent = cards.length;
  if(statsVals[3]) statsVals[3].textContent = cards.length - active;
}

function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(() => t.style.opacity = '0', 2400);
}

function escH(s){ return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function escA(s){ return String(s).replace(/'/g, "\\'").replace(/"/g, '&quot;'); }
function escapeHtml(str){ return escH(str); }
</script>
</body>
</html>