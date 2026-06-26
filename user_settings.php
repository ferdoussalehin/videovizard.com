<?php

ob_start();
session_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_clean();
    error_log(date('Y-m-d H:i:s') . " | user_settings | PHP ERROR [$errno]: $errstr in $errfile line $errline\n", 3, __DIR__ . "/a_errors.log");
    if (!empty($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>"PHP Error: $errstr (line $errline)"]);
        exit;
    }
});

set_exception_handler(function($e) {
    ob_clean();
    error_log(date('Y-m-d H:i:s') . " | user_settings | EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "\n", 3, __DIR__ . "/a_errors.log");
    if (!empty($_POST['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>"Exception: " . $e->getMessage()]);
        exit;
    }
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        error_log(date('Y-m-d H:i:s') . " | user_settings | FATAL: " . $error['message'] . " in " . $error['file'] . " line " . $error['line'] . "\n", 3, __DIR__ . "/a_errors.log");
        if (!empty($_POST['ajax_action'])) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'message'=>"Fatal: " . $error['message']]);
        }
    }
});

if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }

include 'dbconnect_hdb.php';
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// ── Load companies ────────────────────────────────────────────────────────────
$companies = [];
$cq = mysqli_query($conn, "SELECT id, companyname, website, phone, email, address FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC");
while ($c = mysqli_fetch_assoc($cq)) $companies[] = $c;
if (!$company_id && !empty($companies)) {
    $company_id = (int)$companies[0]['id'];
    $_SESSION['company_id'] = $company_id;
}

// ── AJAX: Upload logo ─────────────────────────────────────────────────────────
// ── AJAX: Upload logo ─────────────────────────────────────────────────────────
// ── AJAX: Upload logo ─────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_logo') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $logoDir = __DIR__ . '/podcast_images/';
    if (!is_dir($logoDir)) mkdir($logoDir, 0777, true);
    
    if (empty($_FILES['logo']['tmp_name'])) {
        echo json_encode(['success'=>false,'message'=>'No file uploaded']); 
        exit;
    }
    
    $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    $allowed = ['png','jpg','jpeg','gif','webp','svg'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid file type']); 
        exit;
    }
    
    $fname = 'logo_' . $admin_id . '_' . $company_id . '_' . time() . '.' . $ext;
    
    if (move_uploaded_file($_FILES['logo']['tmp_name'], $logoDir . $fname)) {
        // ── Insert into hdb_image_data ──
        $fname_safe = mysqli_real_escape_string($conn, $fname);
        
        // Check if already exists
        $check = mysqli_query($conn, "SELECT id FROM hdb_image_data WHERE image_name='$fname_safe' AND admin_id=$admin_id LIMIT 1");
        
        if (mysqli_num_rows($check) == 0) {
            // Get company name for tags
            $company_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT companyname FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1"));
            $company_name = mysqli_real_escape_string($conn, $company_info['companyname'] ?? 'Company');
            
            $insert_sql = "INSERT INTO hdb_image_data 
                (image_name, media_type, admin_id, image_hashtags, natural_language_tags, skip_embedding, created_at) 
                VALUES 
                ('$fname_safe', 'image', $admin_id, '#logo #brand #companylogo', 'company logo|brand identity|$company_name logo', 1, NOW())";
            
            $insert_result = mysqli_query($conn, $insert_sql);
            
            if (!$insert_result) {
                error_log(date('Y-m-d H:i:s') . " | Failed to insert logo into hdb_image_data: " . mysqli_error($conn) . "\n", 3, __DIR__ . "/a_errors.log");
            }
        }
        
        echo json_encode([
            'success'=>true, 
            'filename'=>$fname, 
            'url'=>'podcast_images/'.$fname,
            'message'=>'Logo uploaded and saved to library'
        ]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Upload failed']);
    }
    exit;
}

// ── AJAX: Save company info ───────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_company') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $cid      = (int)($_POST['company_id'] ?? $company_id);
    $name     = mysqli_real_escape_string($conn, trim($_POST['companyname'] ?? ''));
    $website  = mysqli_real_escape_string($conn, trim($_POST['website']     ?? ''));
    $phone    = mysqli_real_escape_string($conn, trim($_POST['phone']       ?? ''));
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']       ?? ''));
    $address  = mysqli_real_escape_string($conn, trim($_POST['address']     ?? ''));
    if (!$name) { echo json_encode(['success'=>false,'message'=>'Company name required']); exit; }
    $ok = mysqli_query($conn,
        "UPDATE hdb_companies
         SET companyname='$name', website='$website', phone='$phone',
             email='$email', address='$address'
         WHERE id=$cid AND admin_id=$admin_id");
    echo json_encode(['success'=>(bool)$ok, 'message'=> $ok ? '✅ Company info saved!' : mysqli_error($conn)]);
    exit;
}

// ── AJAX: Load settings for company + text_type ───────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $cid  = (int)($_POST['company_id'] ?? $company_id);
    $type = mysqli_real_escape_string($conn, $_POST['text_type'] ?? 'caption');
    $row  = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$cid AND text_type='$type' LIMIT 1"));
    echo json_encode(['success'=>true,'settings'=>$row?:null]);
    exit;
}

// ── AJAX: Load ALL types at once ──────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_all_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $cid = (int)($_POST['company_id'] ?? $company_id);
    $result = [];
    $q = mysqli_query($conn,
        "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id AND company_id=$cid");
    while ($r = mysqli_fetch_assoc($q)) $result[$r['text_type']] = $r;
    echo json_encode(['success'=>true,'settings'=>$result]);
    exit;
}

// ── AJAX: Save settings ───────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $cid  = (int)($_POST['company_id'] ?? $company_id);
    $type = mysqli_real_escape_string($conn, $_POST['text_type'] ?? 'caption');

    $is_enabled_val = ($type === 'caption') ? 1 : (int)($_POST['is_enabled'] ?? 0);

    $f = [
        'is_enabled'       => $is_enabled_val,
        'fontfamily'       => mysqli_real_escape_string($conn, $_POST['fontfamily']       ?? 'Arial'),
        'fontsize'         => (int)($_POST['fontsize']         ?? 28),
        'fontcolor'        => mysqli_real_escape_string($conn, $_POST['fontcolor']        ?? '#ffffff'),
        'fontweight'       => mysqli_real_escape_string($conn, $_POST['fontweight']       ?? 'normal'),
        'fontcolor_bg'     => mysqli_real_escape_string($conn, $_POST['fontcolor_bg']     ?? '#000000'),
        'fontbg_enable'    => (int)($_POST['fontbg_enable']    ?? 0),
        'font_italic'      => (int)($_POST['font_italic']      ?? 0),
        'font_underline'   => (int)($_POST['font_underline']   ?? 0),
        'caption_style'    => mysqli_real_escape_string($conn, $_POST['caption_style']    ?? 'none'),
        'caption_position' => mysqli_real_escape_string($conn, $_POST['caption_position'] ?? 'bottom'),
        'caption_alignment'=> mysqli_real_escape_string($conn, $_POST['caption_alignment']?? 'center'),
        'caption_speed'    => (int)($_POST['caption_speed']    ?? 1),
        'position_x'       => (int)($_POST['position_x']       ?? 5),
        'position_y'       => (int)($_POST['position_y']       ?? 530),
        'width'            => (int)($_POST['width']            ?? 350),
        'text_effect'      => mysqli_real_escape_string($conn, $_POST['text_effect']      ?? 'none'),
        'text_animation'   => mysqli_real_escape_string($conn, $_POST['text_animation']   ?? 'static'),
        'display_mode'     => mysqli_real_escape_string($conn, $_POST['display_mode']     ?? 'full'),
        'animation_speed'  => mysqli_real_escape_string($conn, $_POST['animation_speed']  ?? 'medium'),
        'stroke_color'     => mysqli_real_escape_string($conn, $_POST['stroke_color']     ?? '#000000'),
        'stroke_width'     => (int)($_POST['stroke_width']     ?? 0),
        'gradient_color'   => mysqli_real_escape_string($conn, $_POST['gradient_color']   ?? '#ff6600'),
        'shadow_color'     => mysqli_real_escape_string($conn, $_POST['shadow_color']     ?? '#000000'),
        'text_align_v'     => mysqli_real_escape_string($conn, $_POST['text_align_v']     ?? 'bottom'),
        'caption_text'     => mysqli_real_escape_string($conn, $_POST['caption_text']     ?? ''),
        'logo_name'        => mysqli_real_escape_string($conn, $_POST['logo_name']        ?? ''),
        'logo_size'        => mysqli_real_escape_string($conn, $_POST['logo_size']        ?? '60'),
        'logo_position'    => mysqli_real_escape_string($conn, $_POST['logo_position']    ?? 'top-right'),
        'logo_enabled'     => (int)($_POST['logo_enabled']     ?? 0),
        'logo_file'        => mysqli_real_escape_string($conn, $_POST['logo_file']        ?? ''),
        'logo_pos_h'       => mysqli_real_escape_string($conn, $_POST['logo_pos_h']       ?? 'right'),
        'logo_pos_v'       => mysqli_real_escape_string($conn, $_POST['logo_pos_v']       ?? 'top'),
        'logo_size_pct'    => (int)($_POST['logo_size_pct']    ?? 15),
    ];

    $check_q = mysqli_query($conn,
        "SELECT admin_id FROM hdb_user_settings
         WHERE admin_id=$admin_id AND company_id=$cid AND text_type='$type' LIMIT 1");
    if ($check_q === false) {
        echo json_encode(['success'=>false,'message'=>'DB select error: '.mysqli_error($conn)]);
        exit;
    }
    $exists = mysqli_fetch_assoc($check_q);
    if ($exists) {
        $sets = array();
        foreach (array_keys($f) as $k) {
            $sets[] = "$k='{$f[$k]}'";
        }
        $sql  = "UPDATE hdb_user_settings SET " . implode(', ', $sets) . ", updated_at=NOW()
                 WHERE admin_id=$admin_id AND company_id=$cid AND text_type='$type'";
    } else {
        $cols = 'admin_id,company_id,text_type,' . implode(',', array_keys($f)) . ',created_at,updated_at';
        $vals = "$admin_id,$cid,'$type',";
        foreach (array_values($f) as $v) {
            $vals .= "'$v',";
        }
        $vals .= 'NOW(),NOW()';
        $sql  = "INSERT INTO hdb_user_settings ($cols) VALUES ($vals)";
    }
    
    error_log(date('Y-m-d H:i:s') . " | user_settings | SQL: $sql\n", 3, __DIR__ . "/a_errors.log");
    
    if (mysqli_query($conn, $sql)) {
        // ── If this is logo settings and logo file exists, save to hdb_image_data ──
        if ($type === 'logo' && !empty($f['logo_file'])) {
            $logo_file = $f['logo_file'];
            $logo_enabled = $f['logo_enabled'];
            
            // Check if logo already exists in hdb_image_data
            $check_img = mysqli_query($conn, "SELECT id FROM hdb_image_data WHERE image_name='$logo_file' AND admin_id=$admin_id LIMIT 1");
            
            if (mysqli_num_rows($check_img) == 0) {
                // Get company name for tags
                $company_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT companyname FROM hdb_companies WHERE id=$cid AND admin_id=$admin_id LIMIT 1"));
                $company_name = mysqli_real_escape_string($conn, $company_info['companyname'] ?? 'Company');
                
                $insert_sql = "INSERT INTO hdb_image_data 
                    (image_name, media_type, admin_id, image_hashtags, natural_language_tags, skip_embedding, created_at) 
                    VALUES 
                    ('$logo_file', 'image', $admin_id, '#logo #brand #companylogo', 'company logo|brand identity|$company_name logo', 1, NOW())";
                
                if (mysqli_query($conn, $insert_sql)) {
                    error_log(date('Y-m-d H:i:s') . " | Logo inserted into hdb_image_data: $logo_file for admin $admin_id\n", 3, __DIR__ . "/a_errors.log");
                } else {
                    error_log(date('Y-m-d H:i:s') . " | Failed to insert logo into hdb_image_data: " . mysqli_error($conn) . "\n", 3, __DIR__ . "/a_errors.log");
                }
            }
        }
        
        echo json_encode(['success'=>true,'message'=>'✅ ' . ucfirst($type) . ' settings saved!', 'is_enabled'=>$is_enabled_val]);
    } else {
        echo json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    }
    exit;
}
// Load current company info
$current_company = null;
foreach ($companies as $c) {
    if ((int)$c['id'] === $company_id) { $current_company = $c; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Video Settings</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#0d1117;--paper:#f6f8fa;
  --accent:#2563eb;--accent2:#1d4ed8;--accent-lt:#dbeafe;
  --green:#16a34a;--red:#dc2626;--amber:#d97706;
  --text:#1e293b;--muted:#64748b;--border:#cbd5e1;
  --card:#ffffff;--shadow:0 1px 3px rgba(0,0,0,.1),0 4px 16px rgba(0,0,0,.06);
  --radius:10px;
}
body{font-family:'DM Sans',sans-serif;background:var(--paper);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
.header{background:var(--ink);color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:100;}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff;font-size:17px;font-weight:700;}
.brand-blue{color:#60a5fa;}
.header-nav{display:flex;gap:8px;}
.header-link{color:rgba(255,255,255,.65);text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:6px;transition:all .15s;}
.header-link:hover{background:rgba(255,255,255,.1);color:#fff;}
.header-link.active{background:rgba(96,165,250,.2);color:#60a5fa;}
.page{flex:1;max-width:1100px;margin:0 auto;width:100%;padding:28px 20px 60px;}
.page-header{margin-bottom:22px;}
.page-title{font-size:22px;font-weight:700;color:var(--ink);}
.page-title span{color:var(--accent);}
.page-sub{font-size:13px;color:var(--muted);margin-top:4px;}

/* ── Company Selector ── */
.selector-bar{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:20px;box-shadow:var(--shadow);}
.sel-group{display:flex;flex-direction:column;gap:5px;min-width:180px;flex:1;}
.sel-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;}
.sel-select{padding:9px 12px;font-size:14px;font-weight:600;font-family:inherit;border:1.5px solid var(--border);border-radius:8px;background:#fff;color:var(--text);outline:none;cursor:pointer;transition:border-color .15s;}
.sel-select:focus{border-color:var(--accent);}

/* ── Five Tab Strip ── */
.type-strip{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;}
.type-tab{
    display:flex;align-items:center;gap:8px;padding:12px 20px;
    border-radius:var(--radius);border:1.5px solid var(--border);
    background:var(--card);cursor:pointer;transition:all .2s;
    font-size:13px;font-weight:700;color:var(--muted);
    user-select:none;
}
.type-tab:hover{border-color:var(--accent);color:var(--accent);background:#f8faff;}
.type-tab.active-caption { border-color:var(--accent);background:var(--accent-lt);color:var(--accent2); }
.type-tab.active-header  { border-color:var(--amber);background:#fef3c7;color:var(--amber); }
.type-tab.active-footer  { border-color:var(--green);background:#f0fdf4;color:var(--green); }
.type-tab.active-logo    { border-color:#db2777;background:#fdf2f8;color:#be185d; }
.enabled-dot{width:8px;height:8px;border-radius:50%;background:#cbd5e1;flex-shrink:0;}
.enabled-dot.on{background:var(--green);}

/* ── No Tab Selected State ── */
.no-tab-state{
    text-align:center;padding:60px 20px;
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--radius);box-shadow:var(--shadow);
}
.no-tab-state .big{font-size:52px;margin-bottom:14px;}
.no-tab-state h3{font-size:20px;font-weight:700;color:var(--muted);margin-bottom:8px;}
.no-tab-state p{font-size:14px;color:var(--muted);}

/* ── Enable Toggle (header/footer/logo only) ── */
.enable-toggle{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:8px;border:1.5px solid var(--border);background:#fff;cursor:pointer;transition:all .2s;user-select:none;margin-bottom:16px;}
.enable-toggle.enabled{border-color:var(--green);background:#f0fdf4;}
.enable-toggle.disabled-state{border-color:#fca5a5;background:#fef2f2;}
.toggle-switch{width:40px;height:22px;border-radius:11px;background:#cbd5e1;position:relative;transition:background .2s;flex-shrink:0;}
.toggle-switch::after{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:3px;left:3px;transition:left .2s;box-shadow:0 1px 3px rgba(0,0,0,.2);}
.enable-toggle.enabled .toggle-switch{background:var(--green);}
.enable-toggle.enabled .toggle-switch::after{left:21px;}
.toggle-label{font-size:13px;font-weight:700;}
.enable-toggle.enabled .toggle-label{color:var(--green);}
.enable-toggle.disabled-state .toggle-label{color:var(--red);}

/* ── Caption always-on badge ── */
.caption-always-on{
    display:inline-flex;align-items:center;gap:8px;
    padding:10px 16px;border-radius:8px;
    border:1.5px solid var(--green);background:#f0fdf4;
    margin-bottom:16px;font-size:13px;font-weight:700;color:var(--green);
}

/* ── Settings Grid ── */
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:680px){.settings-grid{grid-template-columns:1fr;}}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.card-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.card-icon{font-size:15px;}
.card-title{font-size:13px;font-weight:700;color:var(--ink);}
.card-body{padding:16px;}
.card.full{grid-column:1/-1;}
.field{margin-bottom:12px;}
.field:last-child{margin-bottom:0;}
.fl{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.f2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
input[type=text],input[type=number],input[type=email],input[type=url],select,textarea{width:100%;padding:8px 10px;font-size:13px;font-family:inherit;border:1.5px solid var(--border);border-radius:7px;background:#fff;color:var(--text);outline:none;transition:border-color .15s;}
input[type=text]:focus,input[type=number]:focus,input[type=email]:focus,input[type=url]:focus,select:focus,textarea:focus{border-color:var(--accent);}
textarea{resize:vertical;line-height:1.5;}
input[type=color]{width:40px;height:34px;padding:2px;border:1.5px solid var(--border);border-radius:6px;cursor:pointer;background:#fff;}
input[type=range]{width:100%;accent-color:var(--accent);}
.tg{display:flex;gap:5px;flex-wrap:wrap;}
.tb{padding:5px 12px;border:1.5px solid var(--border);border-radius:6px;background:#fff;color:var(--text);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;font-family:inherit;white-space:nowrap;}
.tb:hover{border-color:var(--accent);color:var(--accent);}
.tb.on{background:var(--accent);border-color:var(--accent);color:#fff;}
.chip-g{display:flex;flex-wrap:wrap;gap:5px;}
.chip{padding:4px 11px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;color:var(--text);font-family:inherit;}
.chip:hover{border-color:var(--accent);color:var(--accent);}
.chip.on{background:var(--accent-lt);border-color:var(--accent);color:var(--accent2);}
.cr{display:flex;align-items:center;gap:7px;}
.chex{flex:1;font-family:'Space Mono',monospace;font-size:12px;}
.preview-phone{background:#1a1a2e;border-radius:12px;overflow:hidden;position:relative;aspect-ratio:9/16;max-width:160px;margin:0 auto;border:2px solid #333;}
.prev-bg{width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);position:relative;}
.prev-header{position:absolute;top:0;left:0;right:0;padding:5px 8px;text-align:center;font-size:9px;font-weight:700;}
.prev-footer{position:absolute;bottom:0;left:0;right:0;padding:5px 8px;text-align:center;font-size:8px;}
.prev-caption{position:absolute;left:50%;transform:translateX(-50%);padding:3px 7px;text-align:center;max-width:88%;word-break:break-word;font-size:10px;line-height:1.3;}
.logo-upload-area{border:2px dashed var(--border);border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:#fafbfc;}
.logo-upload-area:hover{border-color:var(--accent);background:var(--accent-lt);}
.logo-upload-area.dragover{border-color:var(--accent);background:var(--accent-lt);}
.logo-preview-img{max-width:80px;max-height:80px;border-radius:6px;margin:0 auto 8px;display:block;}
.upload-hint{font-size:12px;color:var(--muted);}
.logo-fname{font-size:12px;font-family:'Space Mono',monospace;color:var(--accent);margin-top:6px;word-break:break-all;}
.stabs{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:18px;overflow-x:auto;}
.stab{padding:9px 16px;font-size:13px;font-weight:600;color:var(--muted);background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;white-space:nowrap;font-family:inherit;}
.stab.active{color:var(--accent);border-bottom-color:var(--accent);}
.save-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border:none;border-radius:var(--radius);font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s;margin-top:18px;}
.save-btn:hover{box-shadow:0 4px 16px rgba(37,99,235,.4);}
.save-btn:disabled{background:var(--border);color:var(--muted);cursor:not-allowed;}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:11px 24px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;pointer-events:none;transition:opacity .3s;}
.toast.success{background:var(--green);color:#fff;}
.toast.error{background:var(--red);color:#fff;}
.footer{background:var(--ink);color:rgba(255,255,255,.5);text-align:center;padding:14px;font-size:12px;}
.caption-text-row{margin-bottom:16px;}
.caption-text-hint{font-size:11px;color:var(--muted);margin-top:4px;}

/* Company info card */
</style>
</head>
<body>

<header class="header">
  <a class="brand" href="index.php">🎬 Video<span class="brand-blue">Vizard</span></a>
  <nav class="header-nav">
    <a href="vizard_browser.php" class="header-link">← Projects</a>
    
  </nav>
</header>

<div class="page">
  <div class="page-header">
    <h1 class="page-title">Video <span>Settings</span></h1>
    <p class="page-sub">Company info, caption styles, header, footer and logo — saved per company</p>
  </div>

  <!-- Company Selector -->
  <div class="selector-bar">
    <div class="sel-group">
      <span class="sel-label">🏢 Company</span>
      <select class="sel-select" id="sel-company" onchange="onCompanyChange()">
        <?php foreach($companies as $c): ?>
        <option value="<?= $c['id'] ?>" <?= ((int)$c['id']===$company_id)?'selected':'' ?>>
          <?= htmlspecialchars($c['companyname']) ?>
        </option>
        <?php endforeach; ?>
        <?php if(empty($companies)): ?><option value="0">No companies found</option><?php endif; ?>
      </select>
    </div>
    <div style="font-size:12px;color:var(--muted);padding-bottom:4px;flex-shrink:0;">
      All settings are saved per company.
    </div>
  </div>

  <!-- Five Tab Strip -->
  <div class="type-strip">
<div class="type-tab" id="tab-btn-caption" onclick="switchType('caption')">
      <span class="enabled-dot on" id="dot-caption"></span> 📄 Captions
    </div>
    <div class="type-tab" id="tab-btn-header" onclick="switchType('header')">
      <span class="enabled-dot" id="dot-header"></span> 🔝 Header
    </div>
    <div class="type-tab" id="tab-btn-footer" onclick="switchType('footer')">
      <span class="enabled-dot" id="dot-footer"></span> 🔚 Footer
    </div>
    <div class="type-tab" id="tab-btn-logo" onclick="switchType('logo')">
      <span class="enabled-dot" id="dot-logo"></span> 🖼 Logo
    </div>
  </div>

  <!-- No tab selected state -->
  <div id="pane-none" class="no-tab-state">
    <div class="big">⚙️</div>
    <h3>Select a setting above</h3>
    <p>Choose Captions, Header, Footer or Logo to configure your video settings.</p>
  </div>

  <!-- ══ TEXT SETTINGS FORM (caption / header / footer) ══ -->
  <div id="pane-text" style="display:none;">
    <form id="settingsForm">
      <input type="hidden" id="company_id_field" name="company_id" value="<?= $company_id ?>">
      <input type="hidden" id="text_type_field"  name="text_type"  value="caption">
      <input type="hidden" id="is_enabled_field" name="is_enabled" value="1">
      <input type="hidden" id="logo_file_field"  name="logo_file"  value="">

      <!-- Caption: always on badge -->
      <div id="caption-always-on-badge" class="caption-always-on" style="display:none;">
        ✅ Captions are always enabled on all your videos
      </div>

      <!-- Header / Footer: enable toggle -->
      <div id="enable-row" style="display:none;">
        <div class="enable-toggle enabled" id="enableToggle" onclick="toggleEnable()">
          <div class="toggle-switch"></div>
          <span class="toggle-label" id="enableLabel">Header Enabled</span>
          <span style="font-size:12px;color:var(--muted);margin-left:6px;" id="enableHint">Will show on videos</span>
        </div>
      </div>

      <!-- Fixed text for header / footer -->
      <div class="caption-text-row" id="captionTextRow" style="display:none;">
        <div class="card full" style="margin-bottom:16px;">
          <div class="card-header">
            <span class="card-icon" id="captionTextIcon">🔝</span>
            <span class="card-title" id="captionTextLabel">Header Text</span>
          </div>
          <div class="card-body">
            <label class="fl" id="captionTextFieldLabel">Text shown in header on every video</label>
            <textarea name="caption_text" id="caption_text" rows="2"
              placeholder="e.g. My Channel Name  |  www.mysite.com"
              oninput="autoSaveTextSettings()"></textarea>
            <div class="caption-text-hint" id="captionTextHint">
              This fixed text appears on all your videos. Leave blank to hide.
            </div>
          </div>
        </div>
      </div>

      <!-- Sub-tabs -->
      <div class="stabs">
        <button type="button" class="stab active" onclick="switchSTab('typography',this)">✏️ Typography</button>
        <button type="button" class="stab" onclick="switchSTab('effects',this)">✨ Effects</button>
        <button type="button" class="stab" onclick="switchSTab('animation',this)">🎬 Animation</button>
        <button type="button" class="stab" onclick="switchSTab('position',this)">📐 Position</button>
      </div>

      <!-- Typography -->
      <div id="stab-typography">
        <div class="settings-grid">
          <div class="card">
            <div class="card-header"><span class="card-icon">🔤</span><span class="card-title">Font</span></div>
            <div class="card-body">
              <div class="field">
                <label class="fl">Font Family</label>
                <select name="fontfamily" id="fontfamily" onchange="autoSaveTextSettings()">
                  <?php foreach(['Arial','Helvetica','Georgia','Times New Roman','Courier New','Verdana','Trebuchet MS','Impact','Comic Sans MS','Tahoma','Palatino','Garamond','Century Gothic','Lucida Sans'] as $ff): ?>
                  <option value="<?= $ff ?>"><?= $ff ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label class="fl">Font Size — <span id="fontsize-val">28</span>px</label>
                <input type="range" name="fontsize" id="fontsize" min="8" max="80" value="28"
                       oninput="document.getElementById('fontsize-val').textContent=this.value;updatePreview();autoSaveTextSettings()">
              </div>
              <div class="field">
                <label class="fl">Style</label>
                <div class="tg">
                  <button type="button" class="tb" id="btn-bold"      onclick="toggleStyle('bold');autoSaveTextSettings()"><b>B</b></button>
                  <button type="button" class="tb" id="btn-italic"    onclick="toggleStyle('italic');autoSaveTextSettings()"><i>I</i></button>
                  <button type="button" class="tb" id="btn-underline" onclick="toggleStyle('underline');autoSaveTextSettings()"><u>U</u></button>
                </div>
                <input type="hidden" name="fontweight"     id="fontweight"     value="normal">
                <input type="hidden" name="font_italic"    id="font_italic"    value="0">
                <input type="hidden" name="font_underline" id="font_underline" value="0">
              </div>
              <div class="field">
                <label class="fl">Caption Style</label>
                <select name="caption_style" id="caption_style" onchange="updatePreview();autoSaveTextSettings()">
                  <option value="none">None</option>
                  <option value="box">Box</option>
                  <option value="highlight">Highlight</option>
                  <option value="pill">Pill</option>
                  <option value="underline_style">Underline</option>
                </select>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><span class="card-icon">🎨</span><span class="card-title">Colors &amp; Alignment</span></div>
            <div class="card-body">
              <div class="field">
                <label class="fl">Text Color</label>
                <div class="cr">
                  <input type="color" name="fontcolor" id="fontcolor" value="#ffffff" oninput="syncHex('fontcolor');updatePreview();autoSaveTextSettings()">
                  <input type="text" class="chex" id="fontcolor-hex" value="#ffffff" maxlength="7" oninput="syncColor('fontcolor');updatePreview();autoSaveTextSettings()">
                </div>
              </div>
              <div class="field">
                <label class="fl">Background Color</label>
                <div class="cr">
                  <input type="color" name="fontcolor_bg" id="fontcolor_bg" value="#000000" oninput="syncHex('fontcolor_bg');updatePreview();autoSaveTextSettings()">
                  <input type="text" class="chex" id="fontcolor_bg-hex" value="#000000" maxlength="7" oninput="syncColor('fontcolor_bg');updatePreview();autoSaveTextSettings()">
                </div>
              </div>
              <div class="field">
                <label class="fl">Background Enabled</label>
                <div class="tg">
                  <button type="button" class="tb" id="btn-bg-on"  onclick="setBgEnable(1);autoSaveTextSettings()">ON</button>
                  <button type="button" class="tb on" id="btn-bg-off" onclick="setBgEnable(0);autoSaveTextSettings()">OFF</button>
                </div>
                <input type="hidden" name="fontbg_enable" id="fontbg_enable" value="0">
              </div>
              <div class="field">
                <label class="fl">Text Alignment</label>
                <div class="tg">
                  <button type="button" class="tb" id="align-left"   onclick="setAlign('left');autoSaveTextSettings()">◀ Left</button>
                  <button type="button" class="tb on" id="align-center" onclick="setAlign('center');autoSaveTextSettings()">▮ Center</button>
                  <button type="button" class="tb" id="align-right"  onclick="setAlign('right');autoSaveTextSettings()">▶ Right</button>
                </div>
                <input type="hidden" name="caption_alignment" id="caption_alignment" value="center">
              </div>
            </div>
          </div>

          <!-- Preview -->
          <div class="card full">
            <div class="card-header"><span class="card-icon">👁</span><span class="card-title">Live Preview</span></div>
            <div class="card-body" style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
              <div style="flex-shrink:0;">
                <div class="preview-phone">
                  <div class="prev-bg">
                    <div class="prev-header" id="prevHeader" style="display:none;">Header</div>
                    <div class="prev-caption" id="prevCaption" style="bottom:20%;">Caption text here</div>
                    <div class="prev-footer" id="prevFooter" style="display:none;">Footer text</div>
                  </div>
                </div>
                <div style="text-align:center;font-size:10px;color:var(--muted);margin-top:6px;">Live 9:16</div>
              </div>
              <div style="flex:1;min-width:160px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Now editing</div>
                <div id="editing-badge" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;background:var(--accent-lt);color:var(--accent2);font-weight:700;font-size:13px;margin-bottom:12px;">📄 Captions</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Effects -->
      <div id="stab-effects" style="display:none;">
        <div class="card full">
          <div class="card-header"><span class="card-icon">✨</span><span class="card-title">Text Effects</span></div>
          <div class="card-body">
            <div class="field">
              <label class="fl">Effect Type</label>
              <div class="chip-g" id="effect-chips">
                <?php foreach(['none'=>'None','shadow'=>'Drop Shadow','outline'=>'Outline','glow'=>'Glow','stroke'=>'Stroke','gradient'=>'Gradient','3d'=>'3D'] as $v=>$l): ?>
                <button type="button" class="chip <?= $v==='none'?'on':'' ?>" data-val="<?= $v ?>" onclick="setEffect(this);autoSaveTextSettings()"><?= $l ?></button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="text_effect" id="text_effect" value="none">
            </div>
            <div class="f2" style="margin-top:12px;">
              <div class="field">
                <label class="fl">Shadow / Outline Color</label>
                <div class="cr"><input type="color" name="shadow_color" id="shadow_color" value="#000000" oninput="syncHex('shadow_color');updateEffectPreview();autoSaveTextSettings()"><input type="text" class="chex" id="shadow_color-hex" value="#000000" maxlength="7" oninput="syncColor('shadow_color');updateEffectPreview();autoSaveTextSettings()"></div>
              </div>
              <div class="field">
                <label class="fl">Gradient 2nd Color</label>
                <div class="cr"><input type="color" name="gradient_color" id="gradient_color" value="#ff6600" oninput="syncHex('gradient_color');updateEffectPreview();autoSaveTextSettings()"><input type="text" class="chex" id="gradient_color-hex" value="#ff6600" maxlength="7" oninput="syncColor('gradient_color');updateEffectPreview();autoSaveTextSettings()"></div>
              </div>
            </div>
            <div class="field" style="margin-top:10px;">
              <label class="fl">Stroke Width — <span id="stroke-val">0</span>px</label>
              <input type="range" name="stroke_width" id="stroke_width" min="0" max="10" value="0" oninput="document.getElementById('stroke-val').textContent=this.value;updateEffectPreview();autoSaveTextSettings()">
              <input type="hidden" name="stroke_color" id="stroke_color" value="#000000">
            </div>
            <div style="margin-top:16px;background:#111;border-radius:8px;padding:24px;text-align:center;">
              <span id="effectPreviewText" style="font-size:28px;font-weight:700;color:#fff;font-family:Arial;transition:all .3s;">Sample Text</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Animation -->
      <div id="stab-animation" style="display:none;">
        <div class="settings-grid">
          <div class="card">
            <div class="card-header"><span class="card-icon">🎬</span><span class="card-title">Display Mode</span></div>
            <div class="card-body">
              <div class="field">
                <label class="fl">How text appears</label>
                <div class="chip-g">
                  <?php foreach(['full'=>'Full Text','word'=>'Word by Word','line'=>'Line by Line','char'=>'Char by Char','typewriter'=>'Typewriter','word_reveal'=>'Word Reveal','karaoke'=>'Karaoke'] as $v=>$l): ?>
                  <button type="button" class="chip <?= $v==='full'?'on':'' ?>" data-val="<?= $v ?>" id="dm-<?= $v ?>" onclick="setDisplayMode(this);autoSaveTextSettings()"><?= $l ?></button>
                  <?php endforeach; ?>
                </div>
                <input type="hidden" name="display_mode" id="display_mode" value="full">
              </div>
              <div class="field" style="margin-top:12px;">
                <label class="fl">Caption Speed (words/sec)</label>
                <input type="number" name="caption_speed" id="caption_speed" value="1" min="1" max="10" style="max-width:110px;" onchange="autoSaveTextSettings()">
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-icon">⚡</span><span class="card-title">Animation</span></div>
            <div class="card-body">
              <div class="field">
                <label class="fl">Animation Style</label>
                <div class="chip-g">
                  <?php foreach(['static'=>'Static','fade_in'=>'Fade In','slide_up'=>'Slide Up','zoom_in'=>'Zoom In','zoom_out'=>'Zoom Out','pop'=>'Pop','bounce'=>'Bounce'] as $v=>$l): ?>
                  <button type="button" class="chip <?= $v==='static'?'on':'' ?>" data-val="<?= $v ?>" id="anim-<?= $v ?>" onclick="setAnimation(this);autoSaveTextSettings()"><?= $l ?></button>
                  <?php endforeach; ?>
                </div>
                <input type="hidden" name="text_animation" id="text_animation" value="static">
              </div>
              <div class="field" style="margin-top:12px;">
                <label class="fl">Speed</label>
                <div class="tg">
                  <button type="button" class="tb" id="speed-slow"   onclick="setSpeed('slow');autoSaveTextSettings()">🐢 Slow</button>
                  <button type="button" class="tb on" id="speed-medium" onclick="setSpeed('medium');autoSaveTextSettings()">▶ Normal</button>
                  <button type="button" class="tb" id="speed-fast"   onclick="setSpeed('fast');autoSaveTextSettings()">⚡ Fast</button>
                </div>
                <input type="hidden" name="animation_speed" id="animation_speed" value="medium">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Position -->
      <div id="stab-position" style="display:none;">
        <div class="settings-grid">
          <div class="card">
            <div class="card-header"><span class="card-icon">📐</span><span class="card-title">Position</span></div>
            <div class="card-body">
              <div class="field">
                <label class="fl">Vertical</label>
                <div class="tg">
                  <button type="button" class="tb" id="vpos-top"    onclick="setVPos('top');autoSaveTextSettings()">⬆ Top</button>
                  <button type="button" class="tb" id="vpos-middle" onclick="setVPos('middle');autoSaveTextSettings()">▮ Middle</button>
                  <button type="button" class="tb on" id="vpos-bottom" onclick="setVPos('bottom');autoSaveTextSettings()">⬇ Bottom</button>
                </div>
                <input type="hidden" name="text_align_v" id="text_align_v" value="bottom">
              </div>
              <div class="f2" style="margin-top:10px;">
                <div class="field"><label class="fl">X (px)</label><input type="number" name="position_x" id="position_x" value="50" min="0" max="360" onchange="autoSaveTextSettings();posCanvasSyncFromFields()"></div>
                <div class="field"><label class="fl">Y (px)</label><input type="number" name="position_y" id="position_y" value="250" min="0" max="640" onchange="autoSaveTextSettings();posCanvasSyncFromFields()"></div>
              </div>
              <div class="field">
                <label class="fl">Width (px)</label>
                <input type="number" name="width" id="width" value="350" min="50" max="360" onchange="autoSaveTextSettings();posCanvasSyncFromFields()">
              </div>
              <div class="field">
                <label class="fl">Caption Position</label>
                <select name="caption_position" id="caption_position" onchange="autoSaveTextSettings()">
                  <option value="top">Top</option>
                  <option value="middle">Middle</option>
                  <option value="bottom" selected>Bottom</option>
                </select>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-icon">👁</span><span class="card-title">Position Preview — drag to set</span></div>
            <div class="card-body" style="display:flex;flex-direction:column;align-items:center;gap:10px;">
              <div id="posCanvasWrap" style="position:relative;width:160px;flex-shrink:0;aspect-ratio:9/16;
                   background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
                   border-radius:10px;border:2px solid #333;overflow:hidden;cursor:crosshair;touch-action:none;">
                <!-- Grid lines -->
                <div style="position:absolute;inset:0;pointer-events:none;">
                  <div style="position:absolute;top:33.3%;left:0;right:0;border-top:1px dashed rgba(255,255,255,.1);"></div>
                  <div style="position:absolute;top:66.6%;left:0;right:0;border-top:1px dashed rgba(255,255,255,.1);"></div>
                  <div style="position:absolute;left:33.3%;top:0;bottom:0;border-left:1px dashed rgba(255,255,255,.1);"></div>
                  <div style="position:absolute;left:66.6%;top:0;bottom:0;border-left:1px dashed rgba(255,255,255,.1);"></div>
                </div>
                <!-- Draggable caption box -->
                <div id="posDragBox"
                     style="position:absolute;background:rgba(37,99,235,.75);border:2px solid #60a5fa;
                            border-radius:4px;cursor:grab;user-select:none;touch-action:none;
                            display:flex;align-items:center;justify-content:center;
                            font-size:8px;color:#fff;font-weight:700;white-space:nowrap;overflow:hidden;
                            padding:2px 4px;box-sizing:border-box;min-height:16px;">
                  Caption
                  <!-- Right-edge resize handle -->
                  <div id="posResizeHandle"
                       style="position:absolute;right:-4px;top:50%;transform:translateY(-50%);
                              width:9px;height:20px;background:#60a5fa;border-radius:3px;cursor:ew-resize;">
                  </div>
                </div>
                <div style="position:absolute;bottom:4px;left:0;right:0;text-align:center;font-size:8px;color:rgba(255,255,255,.4);pointer-events:none;">9:16</div>
              </div>
              <div style="font-size:10px;color:var(--muted);text-align:center;line-height:1.5;">
                Drag box to set position<br>Drag right edge to resize width
              </div>
            </div>
          </div>
        </div>
      </div>

      <div style="font-size:11px;color:var(--green);text-align:center;margin-top:8px;" id="textAutoSaveStatus"></div>
    </form>
  </div>

  <!-- ══ LOGO PANE ══ -->
  <div id="pane-logo" style="display:none;">
    <form id="logoForm">
      <input type="hidden" id="logo_company_id_field" name="company_id" value="<?= $company_id ?>">
      <input type="hidden" id="logo_text_type_field"  name="text_type"  value="logo">
      <input type="hidden" id="logo_is_enabled_field" name="is_enabled" value="0">
      <input type="hidden" id="logo_file_field"        name="logo_file"  value="">

      <!-- Logo enable toggle -->
      <div class="enable-toggle enabled" id="logoEnableToggle" onclick="toggleLogoEnable();autoSaveLogoSettings()">
        <div class="toggle-switch"></div>
        <span class="toggle-label" id="logoEnableLabel">Logo Enabled</span>
        <span style="font-size:12px;color:var(--muted);margin-left:6px;">Will show logo on videos</span>
      </div>

      <div class="settings-grid">
        <div class="card">
          <div class="card-header"><span class="card-icon">📤</span><span class="card-title">Upload Logo</span></div>
          <div class="card-body">
            <div class="logo-upload-area" id="logoDropArea" onclick="document.getElementById('logoFileInput').click()"
                 ondragover="event.preventDefault();this.classList.add('dragover')"
                 ondragleave="this.classList.remove('dragover')"
                 ondrop="handleLogoDrop(event)">
              <img id="logoPreviewImg" class="logo-preview-img" src="" style="display:none;" alt="Logo">
              <div style="font-size:28px;margin-bottom:6px;" id="logoUploadIcon">🖼</div>
              <div style="font-size:13px;font-weight:600;color:var(--text);">Click or drag to upload</div>
              <div class="upload-hint">PNG, JPG, SVG, WEBP — saved to podcast_lmages/</div>
              <div class="logo-fname" id="logoFileName"></div>
            </div>
            <input type="file" id="logoFileInput" accept="image/*" style="display:none;" onchange="handleLogoSelect(event)">
            <div id="logoUploadStatus" style="margin-top:8px;font-size:12px;"></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><span class="card-icon">📐</span><span class="card-title">Logo Position &amp; Size</span></div>
          <div class="card-body">
            <div class="field">
              <label class="fl">Horizontal Position</label>
              <div class="tg">
                <button type="button" class="tb" id="lph-left"   onclick="setLogoPH('left');autoSaveLogoSettings()">◀ Left</button>
                <button type="button" class="tb" id="lph-center" onclick="setLogoPH('center');autoSaveLogoSettings()">▮ Center</button>
                <button type="button" class="tb on" id="lph-right"  onclick="setLogoPH('right');autoSaveLogoSettings()">▶ Right</button>
              </div>
              <input type="hidden" name="logo_pos_h" id="logo_pos_h" value="right">
            </div>
            <div class="field" style="margin-top:10px;">
              <label class="fl">Vertical Position</label>
              <div class="tg">
                <button type="button" class="tb on" id="lpv-top"    onclick="setLogoPV('top');autoSaveLogoSettings()">⬆ Top</button>
                <button type="button" class="tb" id="lpv-middle" onclick="setLogoPV('middle');autoSaveLogoSettings()">▮ Middle</button>
                <button type="button" class="tb" id="lpv-bottom" onclick="setLogoPV('bottom');autoSaveLogoSettings()">⬇ Bottom</button>
              </div>
              <input type="hidden" name="logo_pos_v" id="logo_pos_v" value="top">
            </div>
            <div class="field" style="margin-top:10px;">
              <label class="fl">Size — <span id="logo-size-val">15</span>% of video width</label>
              <input type="range" name="logo_size_pct" id="logo_size_pct" min="5" max="50" value="15"
                     oninput="document.getElementById('logo-size-val').textContent=this.value;updateLogoPreview();autoSaveLogoSettings()">
              <input type="hidden" name="logo_name"     id="logo_name"     value="">
              <input type="hidden" name="logo_size"     id="logo_size"     value="60">
              <input type="hidden" name="logo_position" id="logo_position" value="top-right">
              <input type="hidden" name="logo_enabled"  id="logo_enabled"  value="0">
            </div>
          </div>
        </div>
        <div class="card full">
          <div class="card-header"><span class="card-icon">👁</span><span class="card-title">Logo Preview</span></div>
          <div class="card-body" style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div>
              <div class="preview-phone">
                <div class="prev-bg">
                  <div id="logoPositionPreview" style="position:absolute;top:6px;right:6px;background:rgba(255,255,255,.2);border-radius:4px;padding:3px;font-size:11px;transition:all .3s;">🖼</div>
                </div>
              </div>
            </div>
            <div style="flex:1;min-width:160px;font-size:13px;color:var(--muted);line-height:1.7;">
              Logo is shown on every video for this company. Upload once and it applies to all new videos.
            </div>
          </div>
        </div>
      </div>

      <div style="font-size:11px;color:var(--green);text-align:center;margin-top:8px;" id="logoAutoSaveStatus"></div>
    </form>
  </div>

</div><!-- /page -->

<footer class="footer">© <?= date('Y') ?> VideoVizard</footer>

<script>
let COMPANY_ID = <?= $company_id ?>;
let TEXT_TYPE  = ''; // nothing selected on load
let autoSaveTimer = null;
let companyAutoSaveTimer = null;
let logoSaveTimer = null;

const enabledState = { caption:true, header:false, footer:false, logo:false };

// ── AUTO SAVE FUNCTIONS ───────────────────────────────────────────────────────
function autoSaveTextSettings() {
    clearTimeout(autoSaveTimer);
    const statusDiv = document.getElementById('textAutoSaveStatus');
    if (statusDiv) {
        statusDiv.textContent = '⏳ Saving...';
        statusDiv.style.color = 'var(--muted)';
    }
    autoSaveTimer = setTimeout(async () => {
        try {
            const fd = new FormData(document.getElementById('settingsForm'));
            fd.set('ajax_action', 'save_settings');
            fd.set('company_id',  COMPANY_ID);
            fd.set('text_type',   TEXT_TYPE);
            fd.set('is_enabled', TEXT_TYPE === 'caption' ? 1 : document.getElementById('is_enabled_field').value);
            
            const r = await fetch(location.href, {method:'POST', body:fd});
            const d = await r.json();
            
            if (statusDiv) {
                if (d.success) {
                    statusDiv.textContent = '✓ Auto-saved';
                    statusDiv.style.color = 'var(--green)';
                    setTimeout(() => {
                        if (statusDiv.textContent === '✓ Auto-saved') {
                            statusDiv.textContent = '';
                        }
                    }, 2000);
                } else {
                    statusDiv.textContent = '✗ Save failed';
                    statusDiv.style.color = 'var(--red)';
                }
            }
            
            if (d.success && TEXT_TYPE !== 'caption') {
                const dot = document.getElementById('dot-' + TEXT_TYPE);
                if (dot) dot.classList.toggle('on', enabledState[TEXT_TYPE]);
            }
        } catch(e) {
            if (statusDiv) {
                statusDiv.textContent = '✗ Error saving';
                statusDiv.style.color = 'var(--red)';
            }
        }
    }, 800);
}

function autoSaveCompany() {
    clearTimeout(companyAutoSaveTimer);
    const statusDiv = document.getElementById('companyAutoSaveStatus');
    if (statusDiv) {
        statusDiv.textContent = '⏳ Saving...';
        statusDiv.style.color = 'var(--muted)';
    }
    companyAutoSaveTimer = setTimeout(async () => {
        try {
            const fd = new FormData();
            fd.append('ajax_action',  'save_company');
            fd.append('company_id',   COMPANY_ID);
            fd.append('companyname',  document.getElementById('ci-name').value);
            fd.append('website',      document.getElementById('ci-website').value);
            fd.append('phone',        document.getElementById('ci-phone').value);
            fd.append('email',        document.getElementById('ci-email').value);
            fd.append('address',      document.getElementById('ci-address').value);
            
            const r = await fetch(location.href, {method:'POST', body:fd});
            const d = await r.json();
            
            if (statusDiv) {
                if (d.success) {
                    statusDiv.textContent = '✓ Company info auto-saved';
                    statusDiv.style.color = 'var(--green)';
                    setTimeout(() => {
                        if (statusDiv.textContent === '✓ Company info auto-saved') {
                            statusDiv.textContent = '';
                        }
                    }, 2000);
                } else {
                    statusDiv.textContent = '✗ Save failed';
                    statusDiv.style.color = 'var(--red)';
                }
            }
        } catch(e) {
            if (statusDiv) {
                statusDiv.textContent = '✗ Error saving';
                statusDiv.style.color = 'var(--red)';
            }
        }
    }, 800);
}

function autoSaveLogoSettings() {
    clearTimeout(logoSaveTimer);
    const statusDiv = document.getElementById('logoAutoSaveStatus');
    if (statusDiv) {
        statusDiv.textContent = '⏳ Saving...';
        statusDiv.style.color = 'var(--muted)';
    }
    logoSaveTimer = setTimeout(async () => {
        try {
            const fd = new FormData(document.getElementById('logoForm'));
            fd.set('ajax_action', 'save_settings');
            fd.set('company_id',  COMPANY_ID);
            fd.set('text_type',   'logo');
            fd.set('is_enabled',  document.getElementById('logo_is_enabled_field').value);
            fd.set('logo_file',   document.getElementById('logo_file_field').value);
            fd.set('logo_enabled',document.getElementById('logo_is_enabled_field').value);
            
            const r = await fetch(location.href, {method:'POST', body:fd});
            const d = await r.json();
            
            if (statusDiv) {
                if (d.success) {
                    statusDiv.textContent = '✓ Logo settings auto-saved';
                    statusDiv.style.color = 'var(--green)';
                    setTimeout(() => {
                        if (statusDiv.textContent === '✓ Logo settings auto-saved') {
                            statusDiv.textContent = '';
                        }
                    }, 2000);
                } else {
                    statusDiv.textContent = '✗ Save failed';
                    statusDiv.style.color = 'var(--red)';
                }
            }
            
            if (d.success) {
                const on = parseInt(document.getElementById('logo_is_enabled_field').value) === 1;
                const dot = document.getElementById('dot-logo');
                if (dot) dot.classList.toggle('on', on);
            }
        } catch(e) {
            if (statusDiv) {
                statusDiv.textContent = '✗ Error saving';
                statusDiv.style.color = 'var(--red)';
            }
        }
    }, 800);
}

// ── Company change ────────────────────────────────────────────────────────────
function onCompanyChange() {
    COMPANY_ID = parseInt(document.getElementById('sel-company').value);
    document.getElementById('company_id_field').value        = COMPANY_ID;
    document.getElementById('logo_company_id_field').value   = COMPANY_ID;
    loadAllDots();
    if (TEXT_TYPE === 'company')  loadCompanyInfo();
    else if (TEXT_TYPE === 'logo') loadLogoSettings();
    else if (TEXT_TYPE)           loadSettings();
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchType(type) {
    TEXT_TYPE = type;

    ['caption','header','footer','logo'].forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        if (btn) btn.className = 'type-tab';
    });

    const colorMap = {
        caption: 'active-caption',
        header:  'active-header',
        footer:  'active-footer',
        logo:    'active-logo',
    };
    const activeBtn = document.getElementById('tab-btn-' + type);
    if (activeBtn) activeBtn.classList.add(colorMap[type]);

    document.getElementById('pane-none').style.display = 'none';
    document.getElementById('pane-text').style.display = 'none';
    document.getElementById('pane-logo').style.display = 'none';

    if (type === 'logo') {
        document.getElementById('pane-logo').style.display = 'block';
        loadLogoSettings();
    } else {
        document.getElementById('text_type_field').value = type;
        document.getElementById('pane-text').style.display = 'block';

        const captionBadge = document.getElementById('caption-always-on-badge');
        const enableRow    = document.getElementById('enable-row');

        if (type === 'caption') {
            captionBadge.style.display = 'flex';
            enableRow.style.display    = 'none';
            document.getElementById('is_enabled_field').value = 1;
        } else {
            captionBadge.style.display = 'none';
            enableRow.style.display    = 'block';
        }

        const ctRow = document.getElementById('captionTextRow');
        if (type === 'header' || type === 'footer') {
            ctRow.style.display = 'block';
            const isHeader = type === 'header';
            document.getElementById('captionTextIcon').textContent       = isHeader ? '🔝' : '🔚';
            document.getElementById('captionTextLabel').textContent      = isHeader ? 'Header Text' : 'Footer Text';
            document.getElementById('captionTextFieldLabel').textContent  = isHeader ? 'Text shown in header on every video' : 'Text shown in footer on every video';
            document.getElementById('captionTextHint').textContent        = isHeader
                ? 'This fixed text appears in the header bar on all your videos.'
                : 'This fixed text appears in the footer bar on all your videos.';
            document.getElementById('caption_text').placeholder           = isHeader
                ? 'e.g. My Channel Name  |  www.mysite.com'
                : 'e.g. Follow us @handle  |  #hashtag';
        } else {
            ctRow.style.display = 'none';
        }

        document.getElementById('prevHeader').style.display  = (type === 'header')  ? 'block' : 'none';
        document.getElementById('prevFooter').style.display  = (type === 'footer')  ? 'block' : 'none';
        document.getElementById('prevCaption').style.display = (type === 'caption') ? 'block' : 'none';

        const badgeMap = { caption:'📄 Captions', header:'🔝 Header', footer:'🔚 Footer' };
        document.getElementById('editing-badge').textContent = badgeMap[type] || type;

        loadSettings();
    }
}

function switchSTab(name, btn) {
    ['typography','effects','animation','position'].forEach(n => {
        document.getElementById('stab-'+n).style.display = n===name ? 'block' : 'none';
    });
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

// ── Enable toggles ────────────────────────────────────────────────────────────
function toggleEnable() {
    const isOn = document.getElementById('enableToggle').classList.contains('enabled');
    setEnabled(!isOn);
    autoSaveTextSettings();
}
function setEnabled(on) {
    const toggle = document.getElementById('enableToggle');
    toggle.className = 'enable-toggle ' + (on ? 'enabled' : 'disabled-state');
    const labelMap = { header:'Header', footer:'Footer' };
    document.getElementById('enableLabel').textContent = (labelMap[TEXT_TYPE]||TEXT_TYPE) + (on ? ' Enabled' : ' Disabled');
    document.getElementById('enableHint').textContent  = on ? 'Will show on videos' : 'Will NOT show on videos';
    document.getElementById('is_enabled_field').value  = on ? 1 : 0;
    enabledState[TEXT_TYPE] = on;
    const dot = document.getElementById('dot-' + TEXT_TYPE);
    if (dot) dot.classList.toggle('on', on);
}

function toggleLogoEnable() {
    const isOn = document.getElementById('logoEnableToggle').classList.contains('enabled');
    setLogoEnabled(!isOn);
    autoSaveLogoSettings();
}
function setLogoEnabled(on) {
    const toggle = document.getElementById('logoEnableToggle');
    toggle.className = 'enable-toggle ' + (on ? 'enabled' : 'disabled-state');
    document.getElementById('logoEnableLabel').textContent = on ? 'Logo Enabled' : 'Logo Disabled';
    document.getElementById('logo_is_enabled_field').value = on ? 1 : 0;
    document.getElementById('logo_enabled').value          = on ? 1 : 0;
    enabledState.logo = on;
    const dot = document.getElementById('dot-logo');
    if (dot) dot.classList.toggle('on', on);
}

// ── Load dots ─────────────────────────────────────────────────────────────────
async function loadAllDots() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'load_all_settings');
        fd.append('company_id', COMPANY_ID);
        const r = await fetch(location.href, {method:'POST', body:fd});
        const d = await r.json();
        if (d.success && d.settings) {
            ['header','footer','logo'].forEach(t => {
                const s  = d.settings[t];
                const on = s ? parseInt(s.is_enabled) === 1 : false;
                enabledState[t] = on;
                const dot = document.getElementById('dot-' + t);
                if (dot) dot.classList.toggle('on', on);
            });
            enabledState.caption = true;
            document.getElementById('dot-caption').classList.add('on');
        }
    } catch(e) {}
}

// ── Load company info ─────────────────────────────────────────────────────────
function loadCompanyInfo() {
    const c = <?= json_encode($companies) ?>;
    const found = c.find(x => x.id == COMPANY_ID);
    if (found) {
        document.getElementById('ci-name').value    = found.companyname || '';
        document.getElementById('ci-website').value = found.website     || '';
        document.getElementById('ci-phone').value   = found.phone       || '';
        document.getElementById('ci-email').value   = found.email       || '';
        document.getElementById('ci-address').value = found.address     || '';
    }
}

// ── Load text settings ────────────────────────────────────────────────────────
async function loadSettings() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'load_settings');
        fd.append('company_id',  COMPANY_ID);
        fd.append('text_type',   TEXT_TYPE);
        const r = await fetch(location.href, {method:'POST', body:fd});
        const d = await r.json();
        if (d.success && d.settings) {
            applySettings(d.settings);
        } else {
            applyDefaults();
        }
    } catch(e) { applyDefaults(); }
}

// ── Load logo settings ────────────────────────────────────────────────────────
async function loadLogoSettings() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'load_settings');
        fd.append('company_id',  COMPANY_ID);
        fd.append('text_type',   'logo');
        const r = await fetch(location.href, {method:'POST', body:fd});
        const d = await r.json();
        if (d.success && d.settings && d.settings) {
            const s = d.settings;
            const on = parseInt(s.is_enabled || 0) === 1;
            setLogoEnabled(on);
            sv('logo_pos_h',    s.logo_pos_h    || 'right');
            sv('logo_pos_v',    s.logo_pos_v    || 'top');
            sv('logo_size_pct', s.logo_size_pct || 15);
            document.getElementById('logo-size-val').textContent = s.logo_size_pct || 15;
            sv('logo_name',     s.logo_name     || '');
            sv('logo_enabled',  s.logo_enabled  || 0);
            document.getElementById('logo_file_field').value = s.logo_file || '';
            sv('logo_file',     s.logo_file     || '');
            if (s.logo_file) showLogoPreview('podcast_images/' + s.logo_file, s.logo_file);
            ['left','center','right'].forEach(v => document.getElementById('lph-'+v)?.classList.toggle('on', v===(s.logo_pos_h||'right')));
            ['top','middle','bottom'].forEach(v => document.getElementById('lpv-'+v)?.classList.toggle('on', v===(s.logo_pos_v||'top')));
            updateLogoPreview();
        }
    } catch(e) {}
}

const DEFAULTS = {
    caption:{ fontfamily:'Arial', fontsize:28, fontcolor:'#ffffff', fontcolor_bg:'#000000', fontbg_enable:0, fontweight:'normal', font_italic:0, font_underline:0, caption_style:'none', caption_alignment:'center', caption_speed:1, caption_position:'bottom', text_effect:'none', shadow_color:'#000000', gradient_color:'#ff6600', stroke_width:0, display_mode:'full', text_animation:'static', animation_speed:'medium', position_x:5, position_y:530, width:350, text_align_v:'bottom', is_enabled:1, caption_text:'' },
    header:  { fontfamily:'Helvetica', fontsize:16, fontcolor:'#ffffff', fontcolor_bg:'#1a1a2e', fontbg_enable:1, fontweight:'bold', font_italic:0, font_underline:0, caption_style:'box', caption_alignment:'center', caption_speed:1, caption_position:'top', text_effect:'none', shadow_color:'#000000', gradient_color:'#ff6600', stroke_width:0, display_mode:'full', text_animation:'fade_in', animation_speed:'medium', position_x:0, position_y:0, width:360, text_align_v:'top', is_enabled:0, caption_text:'' },
    footer:  { fontfamily:'Georgia', fontsize:12, fontcolor:'#aaaaaa', fontcolor_bg:'#000000', fontbg_enable:0, fontweight:'normal', font_italic:0, font_underline:0, caption_style:'none', caption_alignment:'center', caption_speed:1, caption_position:'bottom', text_effect:'none', shadow_color:'#000000', gradient_color:'#ff6600', stroke_width:0, display_mode:'full', text_animation:'static', animation_speed:'slow', position_x:0, position_y:610, width:360, text_align_v:'bottom', is_enabled:0, caption_text:'' },
};

function applyDefaults() { applySettings(DEFAULTS[TEXT_TYPE] || DEFAULTS.caption); }

function applySettings(s) {
    const on = TEXT_TYPE === 'caption' ? true : parseInt(s.is_enabled ?? 0) === 1;
    if (TEXT_TYPE !== 'caption') setEnabled(on);
    document.getElementById('is_enabled_field').value = on ? 1 : 0;

    sv('fontfamily',     s.fontfamily      || 'Arial');
    sv('fontsize',       s.fontsize        || 28); document.getElementById('fontsize-val').textContent = s.fontsize || 28;
    sv('fontcolor',      s.fontcolor       || '#ffffff'); syncHex('fontcolor');
    sv('fontcolor_bg',   s.fontcolor_bg    || '#000000'); syncHex('fontcolor_bg');
    sv('fontbg_enable',  s.fontbg_enable   || 0);
    sv('caption_style',  s.caption_style   || 'none');
    sv('caption_alignment', s.caption_alignment || 'center');
    sv('caption_speed',  s.caption_speed   || 1);
    sv('caption_position', s.caption_position || 'bottom');
    sv('text_effect',    s.text_effect     || 'none');
    sv('shadow_color',   s.shadow_color    || '#000000'); syncHex('shadow_color');
    sv('gradient_color', s.gradient_color  || '#ff6600'); syncHex('gradient_color');
    sv('stroke_width',   s.stroke_width    || 0); document.getElementById('stroke-val').textContent = s.stroke_width || 0;
    sv('display_mode',   s.display_mode    || 'full');
    sv('text_animation', s.text_animation  || 'static');
    sv('animation_speed',s.animation_speed || 'medium');
    sv('position_x',     s.position_x      || 50);
    sv('position_y',     s.position_y      || 250);
    sv('width',          s.width           || 500);
    sv('text_align_v',   s.text_align_v    || 'bottom');

    const ctEl = document.getElementById('caption_text');
    if (ctEl) { ctEl.value = s.caption_text || ''; updatePreviewText(); }

    const bold  = s.fontweight === 'bold';
    const ital  = parseInt(s.font_italic  || 0) === 1;
    const under = parseInt(s.font_underline || 0) === 1;
    document.getElementById('btn-bold').classList.toggle('on', bold);
    document.getElementById('btn-italic').classList.toggle('on', ital);
    document.getElementById('btn-underline').classList.toggle('on', under);
    sv('fontweight',     bold  ? 'bold'   : 'normal');
    sv('font_italic',    ital  ? 1 : 0);
    sv('font_underline', under ? 1 : 0);

    const bgOn = parseInt(s.fontbg_enable || 0) === 1;
    document.getElementById('btn-bg-on').classList.toggle('on', bgOn);
    document.getElementById('btn-bg-off').classList.toggle('on', !bgOn);

    ['left','center','right'].forEach(a => document.getElementById('align-'+a).classList.toggle('on', (s.caption_alignment||'center') === a));
    document.querySelectorAll('#effect-chips .chip').forEach(c => c.classList.toggle('on', c.dataset.val === (s.text_effect||'none')));
    document.querySelectorAll('[id^="dm-"]').forEach(c => c.classList.toggle('on', c.dataset.val === (s.display_mode||'full')));
    document.querySelectorAll('[id^="anim-"]').forEach(c => c.classList.toggle('on', c.dataset.val === (s.text_animation||'static')));

    const spd = s.animation_speed || 'medium';
    ['slow','medium','fast'].forEach(sp => document.getElementById('speed-'+sp)?.classList.toggle('on', sp === spd));
    sv('animation_speed', spd);

    const vp = s.text_align_v || 'bottom';
    ['top','middle','bottom'].forEach(v => document.getElementById('vpos-'+v)?.classList.toggle('on', v === vp));

    updatePreview();
    updateEffectPreview();
    // Sync the draggable position canvas with loaded values
    setTimeout(posCanvasSyncFromFields, 50);
}

function sv(id, val) {
    const el = document.getElementById(id) || document.querySelector('[name="'+id+'"]');
    if (el) el.value = val;
}
function syncHex(id)   { const p=document.getElementById(id),h=document.getElementById(id+'-hex'); if(p&&h) h.value=p.value; }
function syncColor(id) { const h=document.getElementById(id+'-hex'),p=document.getElementById(id); if(h&&p&&/^#[0-9a-fA-F]{6}$/.test(h.value)) p.value=h.value; }

function toggleStyle(s) {
    if(s==='bold')      { const on=document.getElementById('btn-bold').classList.toggle('on');      sv('fontweight',on?'bold':'normal'); }
    if(s==='italic')    { const on=document.getElementById('btn-italic').classList.toggle('on');    sv('font_italic',on?1:0); }
    if(s==='underline') { const on=document.getElementById('btn-underline').classList.toggle('on');sv('font_underline',on?1:0); }
    updatePreview();
}
function setAlign(v)       { ['left','center','right'].forEach(a=>document.getElementById('align-'+a).classList.toggle('on',a===v)); sv('caption_alignment',v); updatePreview(); }
function setBgEnable(v)    { document.getElementById('btn-bg-on').classList.toggle('on',v===1); document.getElementById('btn-bg-off').classList.toggle('on',v===0); sv('fontbg_enable',v); updatePreview(); }
function setVPos(v) {
    ['top','middle','bottom'].forEach(p=>document.getElementById('vpos-'+p).classList.toggle('on',p===v));
    sv('text_align_v',v);
    // Snap Y to 360x640 coordinate space
    const yMap = { top: 20, middle: 300, bottom: 530 };
    const fy = document.getElementById('position_y');
    if (fy) { fy.value = yMap[v] || 530; }
    updatePositionPreview();
}
function setEffect(el)     { document.querySelectorAll('#effect-chips .chip').forEach(c=>c.classList.remove('on')); el.classList.add('on'); sv('text_effect',el.dataset.val); updateEffectPreview(); }
function setDisplayMode(el){ document.querySelectorAll('[id^="dm-"]').forEach(c=>c.classList.remove('on')); el.classList.add('on'); sv('display_mode',el.dataset.val); }
function setAnimation(el)  { document.querySelectorAll('[id^="anim-"]').forEach(c=>c.classList.remove('on')); el.classList.add('on'); sv('text_animation',el.dataset.val); }
function setSpeed(v)       { ['slow','medium','fast'].forEach(s=>document.getElementById('speed-'+s).classList.toggle('on',s===v)); sv('animation_speed',v); }
function setLogoPH(v)      { ['left','center','right'].forEach(a=>document.getElementById('lph-'+a).classList.toggle('on',a===v)); sv('logo_pos_h',v); updateLogoPreview(); }
function setLogoPV(v)      { ['top','middle','bottom'].forEach(a=>document.getElementById('lpv-'+a).classList.toggle('on',a===v)); sv('logo_pos_v',v); updateLogoPreview(); }

function updatePreviewText() {
    const val = (document.getElementById('caption_text')?.value || '').trim();
    if (TEXT_TYPE === 'header') {
        const el = document.getElementById('prevHeader');
        if (el) el.textContent = val || 'Header';
    } else if (TEXT_TYPE === 'footer') {
        const el = document.getElementById('prevFooter');
        if (el) el.textContent = val || 'Footer text';
    }
}

function updatePreview() {
    const family = document.getElementById('fontfamily')?.value    || 'Arial';
    const size   = Math.round((parseInt(document.getElementById('fontsize')?.value)||28)*0.36);
    const color  = document.getElementById('fontcolor')?.value     || '#fff';
    const bgEn   = parseInt(document.getElementById('fontbg_enable')?.value||0) === 1;
    const bgCol  = document.getElementById('fontcolor_bg')?.value  || '#000';
    const bold   = document.getElementById('fontweight')?.value    === 'bold';
    const italic = parseInt(document.getElementById('font_italic')?.value||0)  === 1;
    const under  = parseInt(document.getElementById('font_underline')?.value||0) === 1;
    const align  = document.getElementById('caption_alignment')?.value || 'center';
    const style  = `font-family:${family};font-size:${size}px;color:${color};background:${bgEn?bgCol:'transparent'};font-weight:${bold?'bold':'normal'};font-style:${italic?'italic':'normal'};text-decoration:${under?'underline':'none'};text-align:${align};padding:${bgEn?'2px 5px':'0'};border-radius:${bgEn?'3px':'0'};`;
    const map    = { caption:'prevCaption', header:'prevHeader', footer:'prevFooter' };
    const el     = document.getElementById(map[TEXT_TYPE] || 'prevCaption');
    if (el) {
        el.style.cssText = style + (TEXT_TYPE === 'caption'
            ? 'position:absolute;left:50%;transform:translateX(-50%);bottom:20%;max-width:88%;word-break:break-word;'
            : TEXT_TYPE === 'header' ? 'position:absolute;top:0;left:0;right:0;'
            : 'position:absolute;bottom:0;left:0;right:0;');
    }
    updatePreviewText();
}

function updateEffectPreview() {
    const el  = document.getElementById('effectPreviewText');
    const eff = document.getElementById('text_effect')?.value  || 'none';
    const sc  = document.getElementById('shadow_color')?.value || '#000';
    const gc  = document.getElementById('gradient_color')?.value || '#f60';
    const sw  = parseInt(document.getElementById('stroke_width')?.value || 0);
    el.style.cssText = 'font-size:28px;font-weight:700;color:#fff;font-family:Arial;transition:all .3s;';
    if(eff==='shadow')   el.style.textShadow = `3px 3px 6px ${sc}`;
    if(eff==='outline')  el.style.textShadow = `-1px -1px 0 ${sc},1px -1px 0 ${sc},-1px 1px 0 ${sc},1px 1px 0 ${sc}`;
    if(eff==='glow')     el.style.textShadow = `0 0 10px ${sc},0 0 25px ${sc}`;
    if(eff==='gradient') { el.style.backgroundImage=`linear-gradient(90deg,#fff,${gc})`;el.style.webkitBackgroundClip='text';el.style.webkitTextFillColor='transparent'; }
    if(eff==='stroke'&&sw>0) el.style.webkitTextStroke = `${sw}px ${sc}`;
    if(eff==='3d')       el.style.textShadow = `1px 1px 0 ${sc},2px 2px 0 ${sc},3px 3px 0 ${sc}`;
}

function updatePositionPreview() {
    // Sync from fields → canvas box position
    posCanvasSyncFromFields();
}

// ── Interactive position canvas ───────────────────────────────────────────────
// Matches videomaker.php exactly: CW=360, CH=640
const POS_W = 360, POS_H = 640;

function posCanvasWrap()  { return document.getElementById('posCanvasWrap'); }
function posDragBox()     { return document.getElementById('posDragBox'); }

// Convert logical video coords → canvas px
function posToCanvas(logX, logY, logW) {
    const wrap = posCanvasWrap(); if (!wrap) return null;
    const cw = wrap.offsetWidth, ch = wrap.offsetHeight;
    return {
        left:  Math.round(logX / POS_W * cw),
        top:   Math.round(logY / POS_H * ch),
        width: Math.round(logW / POS_W * cw),
    };
}

// Convert canvas px → logical video coords
function posFromCanvas(pxLeft, pxTop, pxW) {
    const wrap = posCanvasWrap(); if (!wrap) return null;
    const cw = wrap.offsetWidth, ch = wrap.offsetHeight;
    return {
        x: Math.round(pxLeft / cw * POS_W),
        y: Math.round(pxTop  / ch * POS_H),
        w: Math.round(pxW    / cw * POS_W),
    };
}

function posCanvasSyncFromFields() {
    const box  = posDragBox(); if (!box) return;
    const logX = parseInt(document.getElementById('position_x')?.value || 50);
    const logY = parseInt(document.getElementById('position_y')?.value || 250);
    const logW = parseInt(document.getElementById('width')?.value       || 500);
    const c    = posToCanvas(logX, logY, logW); if (!c) return;
    const wrap = posCanvasWrap();
    const cw   = wrap.offsetWidth, ch = wrap.offsetHeight;
    // Clamp
    const bw   = Math.max(20, Math.min(c.width, cw));
    const bx   = Math.max(0,  Math.min(c.left,  cw - bw));
    const by   = Math.max(0,  Math.min(c.top,   ch - 16));
    box.style.left  = bx + 'px';
    box.style.top   = by + 'px';
    box.style.width = bw + 'px';
}

function posCanvasSyncToFields(pxLeft, pxTop, pxW) {
    const v = posFromCanvas(pxLeft, pxTop, pxW); if (!v) return;
    const fx = document.getElementById('position_x');
    const fy = document.getElementById('position_y');
    const fw = document.getElementById('width');
    if (fx) fx.value = v.x;
    if (fy) fy.value = v.y;
    if (fw) fw.value = v.w;
    autoSaveTextSettings();
}

// ── Drag & resize logic ───────────────────────────────────────────────────────
(function initPosCanvas() {
    let mode = null; // 'drag' | 'resize'
    let startMX = 0, startMY = 0, startL = 0, startT = 0, startW = 0;

    function getXY(e) {
        const src = e.touches ? e.touches[0] : e;
        return { x: src.clientX, y: src.clientY };
    }

    function onDown(e) {
        const box    = posDragBox();
        const handle = document.getElementById('posResizeHandle');
        if (!box) return;
        const { x, y } = getXY(e);
        startMX = x; startMY = y;
        startL  = parseInt(box.style.left  || 0);
        startT  = parseInt(box.style.top   || 0);
        startW  = parseInt(box.style.width || 80);
        if (e.target === handle || e.target.id === 'posResizeHandle') {
            mode = 'resize';
        } else {
            mode = 'drag';
            box.style.cursor = 'grabbing';
        }
        e.preventDefault();
        e.stopPropagation();
    }

    function onMove(e) {
        if (!mode) return;
        const box  = posDragBox(); if (!box) return;
        const wrap = posCanvasWrap(); if (!wrap) return;
        const cw   = wrap.offsetWidth, ch = wrap.offsetHeight;
        const { x, y } = getXY(e);
        const dx = x - startMX, dy = y - startMY;

        if (mode === 'drag') {
            const bw  = parseInt(box.style.width || 80);
            const newL = Math.max(0, Math.min(startL + dx, cw - bw));
            const newT = Math.max(0, Math.min(startT + dy, ch - 16));
            box.style.left = newL + 'px';
            box.style.top  = newT + 'px';
        } else {
            const newW = Math.max(20, Math.min(startW + dx, cw - startL));
            box.style.width = newW + 'px';
        }
        e.preventDefault();
    }

    function onUp() {
        if (!mode) return;
        const box  = posDragBox(); if (!box) return;
        const pxL  = parseInt(box.style.left  || 0);
        const pxT  = parseInt(box.style.top   || 0);
        const pxW  = parseInt(box.style.width || 80);
        box.style.cursor = 'grab';
        mode = null;
        posCanvasSyncToFields(pxL, pxT, pxW);
    }

    // Attach once DOM is ready
    function attach() {
        const box = posDragBox(); if (!box) return;
        box.addEventListener('mousedown',  onDown, { passive: false });
        box.addEventListener('touchstart', onDown, { passive: false });
        document.addEventListener('mousemove',  onMove, { passive: false });
        document.addEventListener('touchmove',  onMove, { passive: false });
        document.addEventListener('mouseup',    onUp);
        document.addEventListener('touchend',   onUp);
        // Also allow clicking on the canvas bg to move the box
        const wrap = posCanvasWrap();
        if (wrap) {
            wrap.addEventListener('mousedown', function(e) {
                if (e.target === wrap || e.target.closest('#posCanvasWrap') === wrap && e.target === wrap) {
                    const rect = wrap.getBoundingClientRect();
                    const bw   = parseInt(posDragBox().style.width || 80);
                    const nx   = Math.max(0, Math.min(e.clientX - rect.left - bw / 2, wrap.offsetWidth - bw));
                    const ny   = Math.max(0, Math.min(e.clientY - rect.top  - 8,      wrap.offsetHeight - 16));
                    posDragBox().style.left = nx + 'px';
                    posDragBox().style.top  = ny + 'px';
                    posCanvasSyncToFields(nx, ny, bw);
                }
            });
        }
        // Initial sync
        posCanvasSyncFromFields();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        setTimeout(attach, 100);
    }
})();

function updateLogoPreview() {
    const el = document.getElementById('logoPositionPreview'); if(!el) return;
    const ph = document.getElementById('logo_pos_h')?.value  || 'right';
    const pv = document.getElementById('logo_pos_v')?.value  || 'top';
    const sz = parseInt(document.getElementById('logo_size_pct')?.value || 15);
    const px = Math.round(sz * 1.6) + 'px';
    el.style.cssText = `position:absolute;width:${px};height:${px};background:rgba(255,255,255,.2);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:12px;transition:all .3s;`;
    el.style.top = 'auto'; el.style.bottom = 'auto'; el.style.left = 'auto'; el.style.right = 'auto';
    if(pv==='top')    el.style.top    = '6px';
    if(pv==='middle') el.style.top    = `calc(50% - ${Math.round(sz*0.8)}px)`;
    if(pv==='bottom') el.style.bottom = '6px';
    if(ph==='left')   el.style.left   = '6px';
    if(ph==='center') { el.style.left = '50%'; el.style.transform = 'translateX(-50%)'; }
    if(ph==='right')  el.style.right  = '6px';
}

// ── Logo upload ───────────────────────────────────────────────────────────────
function handleLogoSelect(e) { if(e.target.files[0]) uploadLogo(e.target.files[0]); }
function handleLogoDrop(e)   { e.preventDefault(); document.getElementById('logoDropArea').classList.remove('dragover'); if(e.dataTransfer.files[0]) uploadLogo(e.dataTransfer.files[0]); }

async function uploadLogo(file) {
    const status = document.getElementById('logoUploadStatus');
    status.textContent = '⏳ Uploading…'; status.style.color = 'var(--muted)';
    const fd = new FormData();
    fd.append('ajax_action', 'upload_logo');
    fd.append('company_id',  COMPANY_ID);
    fd.append('logo',        file);
    try {
        const r = await fetch(location.href, {method:'POST', body:fd});
        const d = await r.json();
        if (d.success) {
            document.getElementById('logo_file_field').value = d.filename;
            sv('logo_file', d.filename);
            sv('logo_name', d.filename);
            showLogoPreview(d.url, d.filename);
            status.textContent = '✅ Uploaded: ' + d.filename;
            status.style.color = 'var(--green)';
            autoSaveLogoSettings();
        } else {
            status.textContent = '❌ ' + d.message;
            status.style.color = 'var(--red)';
        }
    } catch(e) {
        status.textContent = '❌ Upload error: ' + e.message;
        status.style.color = 'var(--red)';
    }
}

function showLogoPreview(url, fname) {
    const img  = document.getElementById('logoPreviewImg');
    const icon = document.getElementById('logoUploadIcon');
    const fn   = document.getElementById('logoFileName');
    img.src = url; img.style.display = 'block'; icon.style.display = 'none';
    if(fn) fn.textContent = fname;
    const lp = document.getElementById('logoPositionPreview');
    if (lp) lp.innerHTML = `<img src="${url}" style="width:100%;height:100%;object-fit:contain;border-radius:3px;">`;
}

function showToast(msg, type='success') {
    const t = document.createElement('div');
    t.className = 'toast ' + type; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 2400);
}

// ── Init ──────────────────────────────────────────────────────────────────────
loadAllDots();
</script>
</body>
</html>