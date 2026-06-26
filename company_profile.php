<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_GET['company_id'] ?? $_POST['company_id'] ?? 0);

if (!$company_id) {
    header("Location: user_clients.php");
    exit;
}

include 'dbconnect_hdb.php';
require_once __DIR__ . '/config.php';

// ── Ensure all columns exist ────────────────────────────────────
function ensureCol($conn, $col, $def) {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `hdb_companies` LIKE '$col'");
    if ($r && mysqli_num_rows($r) === 0)
        mysqli_query($conn, "ALTER TABLE `hdb_companies` ADD COLUMN `$col` $def");
}
ensureCol($conn, 'website',         "VARCHAR(100)  NULL DEFAULT ''");
ensureCol($conn, 'email',           "VARCHAR(50)   NULL DEFAULT ''");
ensureCol($conn, 'phone',           "VARCHAR(20)   NULL DEFAULT ''");
ensureCol($conn, 'address',         "VARCHAR(100)  NULL DEFAULT ''");
ensureCol($conn, 'client_username', "VARCHAR(100)  NULL");
ensureCol($conn, 'client_password', "VARCHAR(255)  NULL");
ensureCol($conn, 'company_type',    "VARCHAR(20)   NULL DEFAULT ''");
ensureCol($conn, 'username',        "VARCHAR(80)   NULL DEFAULT ''");
ensureCol($conn, 'password_hash',   "VARCHAR(255)  NULL DEFAULT ''");

// ── Load admin info ─────────────────────────────────────────────
$admin_info    = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT firstname, lastname, plan_type, role FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$firstname     = $admin_info['firstname'] ?? 'User';
$admin_initial = strtoupper(substr($firstname, 0, 1));

// ── Load company ────────────────────────────────────────────────
$company = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1"));
if (!$company) {
    header("Location: user_clients.php");
    exit;
}

// ── AJAX save ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_profile') {
    header('Content-Type: application/json');

    $companyname     = trim($_POST['companyname']     ?? '');
    $company_type    = trim($_POST['company_type']    ?? '');
    $email           = trim($_POST['email']           ?? '');
    $phone           = trim($_POST['phone']           ?? '');
    $website         = trim($_POST['website']         ?? '');
    $address         = trim($_POST['address']         ?? '');
    $client_username = trim($_POST['client_username'] ?? '');
    $client_password = trim($_POST['client_password'] ?? '');
    $username        = trim($_POST['username']        ?? '');
    $new_password    = trim($_POST['new_password']    ?? '');
    $status          = in_array($_POST['status'] ?? '', ['active','inactive','suspended'])
                       ? $_POST['status'] : 'active';

    if (empty($companyname)) {
        echo json_encode(['success' => false, 'error' => 'Company name is required.']); exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']); exit;
    }
    if ($username !== '') {
        $uesc   = mysqli_real_escape_string($conn, $username);
        $ucheck = mysqli_query($conn,
            "SELECT id FROM hdb_companies WHERE admin_id=$admin_id AND username='$uesc' AND id<>$company_id LIMIT 1");
        if ($ucheck && mysqli_num_rows($ucheck) > 0) {
            echo json_encode(['success' => false, 'error' => 'That username is already taken.']); exit;
        }
    }

    $pw_sql = '';
    if ($new_password !== '') {
        $hash     = password_hash($new_password, PASSWORD_DEFAULT);
        $hash_esc = mysqli_real_escape_string($conn, $hash);
        $pw_sql   = ", password_hash='$hash_esc'";
    }

    $f = fn($v) => mysqli_real_escape_string($conn, $v);

    $ok = mysqli_query($conn,
        "UPDATE hdb_companies SET
            companyname     = '{$f($companyname)}',
            company_type    = '{$f($company_type)}',
            email           = '{$f($email)}',
            phone           = '{$f($phone)}',
            website         = '{$f($website)}',
            address         = '{$f($address)}',
            client_username = '{$f($client_username)}',
            client_password = '{$f($client_password)}',
            username        = '{$f($username)}',
            status          = '{$f($status)}'
            $pw_sql
         WHERE id=$company_id AND admin_id=$admin_id");

    // Handle logo upload
    $logo_msg = '';
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $ext     = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','svg'];
        if (in_array($ext, $allowed)) {
            $upload_dir = __DIR__ . '/uploads/logos/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = 'company_' . $company_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $upload_dir . $filename)) {
                mysqli_query($conn,
                    "UPDATE hdb_companies SET logo_file='$filename' WHERE id=$company_id AND admin_id=$admin_id");
                $logo_msg = $filename;
            }
        }
    }

    echo json_encode([
        'success'  => (bool)$ok,
        'logo_msg' => $logo_msg,
        'error'    => $ok ? '' : mysqli_error($conn),
    ]);
    exit;
}

// ── Video count ─────────────────────────────────────────────────
$video_count = (int)(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) as c FROM hdb_podcasts WHERE company_id=$company_id"))['c'] ?? 0);

$status    = $company['status'] ?? 'active';
$date_fmt  = $company['created_at'] ? date('M j, Y', strtotime($company['created_at'])) : '—';
$logo_file = $company['logo_file'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Company Profile — <?= htmlspecialchars($company['companyname']) ?></title>
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
  --shadow:    0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.04);
  --shadow-md: 0 4px 16px rgba(0,0,0,.1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}

/* ── Header ── */
.vidora-header{display:flex;justify-content:space-between;align-items:center;
               padding:12px 20px;background:linear-gradient(90deg,#0f2a44,#143b63);
               color:#fff;box-shadow:0 3px 10px rgba(0,0,0,.15);
               position:sticky;top:0;z-index:1000;gap:12px;}
.brand-link{text-decoration:none;display:flex;align-items:center;gap:8px;}
.brand-icon{font-size:24px;}
.brand-name{font-size:18px;font-weight:700;}
.brand-video{color:#fff;}.brand-vizard{color:var(--accent);}
.header-right{display:flex;align-items:center;gap:10px;}
.profile-wrap{position:relative;}
.profile-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
             color:#fff;padding:8px 12px;border-radius:10px;cursor:pointer;
             font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px;
             transition:all .2s;min-height:40px;}
.avatar{width:26px;height:26px;background:#5fd1ff;color:#0f2a44;border-radius:50%;
        display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;}
.chevron{font-size:11px;transition:transform .2s;}
.profile-btn.open .chevron{transform:rotate(180deg);}
.dropdown-menu{display:none;position:absolute;top:calc(100% + 8px);right:0;
               background:#fff;border-radius:12px;box-shadow:var(--shadow-md);
               min-width:200px;overflow:hidden;z-index:9999;border:1px solid var(--border);}
.dropdown-menu.open{display:block;animation:ddSlide .2s ease;}
@keyframes ddSlide{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.dropdown-user{padding:14px 16px;border-bottom:1px solid var(--border);}
.d-name{font-weight:700;font-size:14px;color:var(--dark-blue);}
.d-role{font-size:12px;color:var(--muted);margin-top:2px;}
.dropdown-item{padding:12px 16px;font-size:14px;color:var(--text);display:flex;
               align-items:center;gap:10px;text-decoration:none;transition:background .15s;}
.dropdown-item:hover{background:#f8fafc;}
.dropdown-item.logout{color:#ef4444;}
.dropdown-divider{height:1px;background:var(--border);}

/* ── Main layout ── */
.main{flex:1;padding:28px 20px;max-width:960px;margin:0 auto;width:100%;}

/* ── Breadcrumb ── */
.breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:22px;}
.breadcrumb a{color:var(--muted);text-decoration:none;}.breadcrumb a:hover{color:var(--dark-blue);text-decoration:underline;}
.breadcrumb .sep{font-size:11px;}

/* ── Hero card ── */
.hero-card{background:var(--card);border:1px solid var(--border);border-radius:20px;
           overflow:hidden;box-shadow:var(--shadow-md);margin-bottom:24px;}
.hero-banner{height:100px;background:linear-gradient(135deg,#0f2a44 0%,#143b63 50%,#1e4d82 100%);
             position:relative;}
.hero-body{padding:0 28px 24px;}
.hero-avatar-wrap{display:flex;align-items:flex-end;gap:18px;margin-top:-36px;margin-bottom:16px;}
.hero-avatar{width:72px;height:72px;border-radius:16px;border:4px solid #fff;
             object-fit:cover;background:#e2e8f0;display:flex;align-items:center;
             justify-content:center;font-size:28px;font-weight:800;color:#fff;flex-shrink:0;}
.hero-avatar.av-0{background:linear-gradient(135deg,#3b82f6,#1d4ed8);}
.hero-avatar.av-1{background:linear-gradient(135deg,#10b981,#059669);}
.hero-avatar.av-2{background:linear-gradient(135deg,#8b5cf6,#6d28d9);}
.hero-avatar.av-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
.hero-avatar.av-4{background:linear-gradient(135deg,#ef4444,#b91c1c);}
.hero-avatar.av-5{background:linear-gradient(135deg,#06b6d4,#0891b2);}
.hero-meta{flex:1;min-width:0;padding-top:8px;}
.hero-name{font-size:22px;font-weight:800;color:var(--dark-blue);margin-bottom:4px;}
.hero-sub{font-size:13px;color:var(--muted);}
.hero-badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;
       font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.badge.active   {background:#dcfce7;color:#15803d;}
.badge.inactive {background:#f1f5f9;color:#64748b;}
.badge.suspended{background:#fee2e2;color:#b91c1c;}
.badge.type{background:#eff6ff;color:#1d4ed8;}
.stat-row{display:flex;gap:24px;padding-top:14px;border-top:1px solid var(--border);flex-wrap:wrap;}
.stat-item{text-align:center;}
.stat-item .sv{font-size:20px;font-weight:800;color:var(--dark-blue);}
.stat-item .sl{font-size:11px;color:var(--muted);margin-top:2px;}

/* ── Form card ── */
.form-card{background:var(--card);border:1px solid var(--border);border-radius:20px;
           padding:28px;box-shadow:var(--shadow);margin-bottom:20px;}
.section-hdr{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;
             letter-spacing:.06em;padding-bottom:12px;border-bottom:1px solid var(--border);
             margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group.full{grid-column:1/-1;}
.form-label{font-size:12px;font-weight:600;color:var(--dark-blue);}
.form-label .req{color:#ef4444;}
.form-label .hint{font-weight:400;color:var(--muted);font-size:11px;}
.form-input{padding:10px 13px;border:1.5px solid var(--border);border-radius:10px;
            font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;
            color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s;background:#fff;}
.form-input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.08);}
.form-input:disabled{background:#f8fafc;color:var(--muted);cursor:not-allowed;}
textarea.form-input{resize:vertical;min-height:72px;line-height:1.5;}
select.form-input{cursor:pointer;}
.pw-wrap{position:relative;}
.pw-wrap .form-input{padding-right:38px;}
.pw-eye{position:absolute;right:9px;top:50%;transform:translateY(-50%);
        background:none;border:none;cursor:pointer;font-size:14px;color:var(--muted);padding:4px;}

/* ── Logo upload ── */
.logo-upload-wrap{display:flex;align-items:center;gap:16px;padding:16px;
                  background:#f8fafc;border:1.5px dashed var(--border);border-radius:12px;}
.logo-preview{width:64px;height:64px;border-radius:10px;object-fit:cover;
              background:#e2e8f0;display:flex;align-items:center;justify-content:center;
              font-size:24px;border:1px solid var(--border);flex-shrink:0;overflow:hidden;}
.logo-preview img{width:100%;height:100%;object-fit:cover;}
.logo-upload-info{flex:1;}
.logo-upload-info p{font-size:12px;color:var(--muted);margin-top:4px;}
.btn-upload{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;
            background:#eff6ff;color:#1d4ed8;border:1.5px solid #bfdbfe;
            border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;
            transition:all .15s;font-family:'Plus Jakarta Sans',sans-serif;}
.btn-upload:hover{background:#dbeafe;}

/* ── Actions ── */
.form-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
.btn-save{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;
          background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));
          color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;
          cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:opacity .15s;}
.btn-save:hover{opacity:.9;}
.btn-save:disabled{opacity:.55;cursor:not-allowed;}
.btn-back{display:inline-flex;align-items:center;gap:6px;padding:12px 20px;
          background:#fff;color:var(--muted);border:1.5px solid var(--border);border-radius:12px;
          font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;
          font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;}
.btn-back:hover{background:#f8fafc;color:var(--text);}
.btn-workspace{display:inline-flex;align-items:center;gap:6px;padding:12px 20px;
               background:linear-gradient(135deg,#10b981,#059669);
               color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;
               cursor:pointer;text-decoration:none;font-family:'Plus Jakarta Sans',sans-serif;
               transition:all .2s;}
.btn-workspace:hover{opacity:.9;}

/* ── Alerts ── */
.alert{padding:12px 16px;border-radius:10px;font-size:13px;font-weight:500;margin-bottom:16px;display:none;}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;}
.alert.ok {background:#f0fdf4;border:1px solid #86efac;color:#166534;}

/* ── Read-only meta ── */
.meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.meta-item{background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
.meta-item .mk{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
.meta-item .mv{font-size:14px;font-weight:600;color:var(--dark-blue);}

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

@media(max-width:640px){
  .main{padding:16px;}
  .form-grid{grid-template-columns:1fr;}
  .meta-grid{grid-template-columns:1fr;}
  .hero-body{padding:0 16px 20px;}
  .form-card{padding:20px 16px;}
  .form-actions{flex-direction:column;}
  .btn-save,.btn-back,.btn-workspace{width:100%;justify-content:center;}
}
</style>
</head>
<body>

<!-- ══ Header ══════════════════════════════════════════════════ -->
<header class="vidora-header">
  <a class="brand-link" href="index.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
  <div class="header-right">
    <div class="profile-wrap">
      <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()">
        <div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
        <span><?= htmlspecialchars($firstname) ?></span>
        <span class="chevron">▼</span>
      </button>
      <div class="dropdown-menu" id="dropdownMenu">
        <div class="dropdown-user">
          <div class="d-name"><?= htmlspecialchars($firstname) ?></div>
          <div class="d-role">Admin</div>
        </div>
        <a href="vizard_browser.php"   class="dropdown-item">🎬 My Projects</a>
        <a href="vizard_scriptgen.php" class="dropdown-item">✨ Create Video</a>
        <a href="user_clients.php"     class="dropdown-item">👥 Clients</a>
        <a href="user_settings.php"    class="dropdown-item">⚙️ Settings</a>
        <div class="dropdown-divider"></div>
        <a href="logout.php" class="dropdown-item logout">🚪 Logout</a>
      </div>
    </div>
  </div>
</header>

<!-- ══ Main ════════════════════════════════════════════════════ -->
<div class="main">

  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="user_clients.php">👥 Clients</a>
    <span class="sep">›</span>
    <a href="client_workspace.php?admin_id=<?= $admin_id ?>&company_id=<?= $company_id ?>"><?= htmlspecialchars($company['companyname']) ?></a>
    <span class="sep">›</span>
    <span>Company Profile</span>
  </div>

  <!-- Hero card -->
  <div class="hero-card">
    <div class="hero-banner"></div>
    <div class="hero-body">
      <div class="hero-avatar-wrap">
        <?php
          $initial = strtoupper(substr($company['companyname'], 0, 1));
          $av_idx  = $company_id % 6;
        ?>
        <div class="hero-avatar av-<?= $av_idx ?>" id="heroAvatar">
          <?php if ($logo_file && file_exists(__DIR__ . '/uploads/logos/' . $logo_file)): ?>
            <img src="uploads/logos/<?= htmlspecialchars($logo_file) ?>" alt="Logo" id="logoImg">
          <?php else: ?>
            <span id="logoInitial"><?= htmlspecialchars($initial) ?></span>
          <?php endif; ?>
        </div>
        <div class="hero-meta">
          <div class="hero-name" id="heroName"><?= htmlspecialchars($company['companyname']) ?></div>
          <div class="hero-sub">ID #<?= $company_id ?> &nbsp;·&nbsp; Admin ID #<?= $admin_id ?> &nbsp;·&nbsp; Created <?= $date_fmt ?></div>
          <div class="hero-badges">
            <span class="badge <?= htmlspecialchars($status) ?>">
              <?= ucfirst($status) ?>
            </span>
            <?php if (!empty($company['company_type'])): ?>
            <span class="badge type"><?= htmlspecialchars($company['company_type']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <a href="client_workspace.php?admin_id=<?= $admin_id ?>&company_id=<?= $company_id ?>"
           class="btn-workspace">🚀 Open Workspace</a>
      </div>

      <div class="stat-row">
        <div class="stat-item">
          <div class="sv"><?= number_format($video_count) ?></div>
          <div class="sl">Videos</div>
        </div>
        <div class="stat-item">
          <div class="sv"><?= $company['username'] ? '✓' : '—' ?></div>
          <div class="sl">Portal Login</div>
        </div>
        <div class="stat-item">
          <div class="sv"><?= !empty($company['password_hash']) ? '🔑' : '—' ?></div>
          <div class="sl">Password Set</div>
        </div>
        <div class="stat-item">
          <div class="sv"><?= $logo_file ? '🖼' : '—' ?></div>
          <div class="sl">Logo</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Alert messages -->
  <div class="alert err" id="alertErr"></div>
  <div class="alert ok"  id="alertOk"></div>

  <!-- ── Section 1: Company Info ─────────────────────────────── -->
  <div class="form-card">
    <div class="section-hdr">🏢 Company Information</div>
    <div class="form-grid">

      <div class="form-group">
        <label class="form-label">Company Name <span class="req">*</span></label>
        <input type="text" class="form-input" id="f_companyname"
               value="<?= htmlspecialchars($company['companyname']) ?>"
               maxlength="80" placeholder="Company / Client name">
      </div>

      <div class="form-group">
        <label class="form-label">Company Type</label>
        <select class="form-input" id="f_company_type">
          <?php
          $types = ['','Real Estate','Healthcare','Retail','Finance','Education','Technology','Hospitality','Legal','Other'];
          foreach ($types as $t):
            $sel = (($company['company_type'] ?? '') === $t) ? 'selected' : '';
          ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $sel ?>><?= $t ?: '— Select type —' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-input" id="f_status">
          <?php foreach (['active','inactive','suspended'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" class="form-input" id="f_email"
               value="<?= htmlspecialchars($company['email'] ?? '') ?>"
               maxlength="50" placeholder="client@example.com">
      </div>

      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="tel" class="form-input" id="f_phone"
               value="<?= htmlspecialchars($company['phone'] ?? '') ?>"
               maxlength="20" placeholder="+1 555 000 0000">
      </div>

      <div class="form-group">
        <label class="form-label">Website</label>
        <input type="text" class="form-input" id="f_website"
               value="<?= htmlspecialchars($company['website'] ?? '') ?>"
               maxlength="100" placeholder="https://example.com">
      </div>

      <div class="form-group full">
        <label class="form-label">Address</label>
        <textarea class="form-input" id="f_address" maxlength="100"
                  placeholder="123 Main St, City, State, ZIP"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
      </div>

    </div>
  </div>

  <!-- ── Section 2: Logo ──────────────────────────────────────── -->
  <div class="form-card">
    <div class="section-hdr">🖼 Company Logo</div>
    <div class="logo-upload-wrap">
      <div class="logo-preview" id="logoPreviewBox">
        <?php if ($logo_file && file_exists(__DIR__ . '/uploads/logos/' . $logo_file)): ?>
          <img src="uploads/logos/<?= htmlspecialchars($logo_file) ?>" alt="Logo" id="logoPreviewImg">
        <?php else: ?>
          <span id="logoPreviewInitial"><?= htmlspecialchars($initial) ?></span>
        <?php endif; ?>
      </div>
      <div class="logo-upload-info">
        <label class="btn-upload" for="f_logo_file">📁 Choose Logo</label>
        <input type="file" id="f_logo_file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg"
               style="display:none" onchange="previewLogo(this)">
        <p>JPG, PNG, GIF, WebP or SVG — max 2 MB. Uploaded on save.</p>
        <?php if ($logo_file): ?>
          <p style="margin-top:4px;color:var(--dark-blue);font-weight:600;">Current: <?= htmlspecialchars($logo_file) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Section 3: Portal Credentials (username / password_hash) ── -->
  <div class="form-card">
    <div class="section-hdr">🔑 Portal Login Credentials <span style="font-size:11px;font-weight:400;text-transform:none;color:var(--muted);">(used by the client to log in)</span></div>
    <div class="form-grid">

      <div class="form-group">
        <label class="form-label">Portal Username <span class="hint">(username column)</span></label>
        <input type="text" class="form-input" id="f_username"
               value="<?= htmlspecialchars($company['username'] ?? '') ?>"
               maxlength="80" autocomplete="off" placeholder="login_username">
      </div>

      <div class="form-group">
        <label class="form-label">New Password <span class="hint">(leave blank to keep current)</span></label>
        <div class="pw-wrap">
          <input type="password" class="form-input" id="f_new_password"
                 maxlength="128" autocomplete="new-password" placeholder="Set new password…">
          <button type="button" class="pw-eye" onclick="togglePw('f_new_password')">👁</button>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Section 4: Legacy Client Credentials ─────────────────── -->
  <div class="form-card">
    <div class="section-hdr">👤 Legacy Client Credentials <span style="font-size:11px;font-weight:400;text-transform:none;color:var(--muted);">(client_username / client_password columns)</span></div>
    <div class="form-grid">

      <div class="form-group">
        <label class="form-label">Client Username <span class="hint">(client_username)</span></label>
        <input type="text" class="form-input" id="f_client_username"
               value="<?= htmlspecialchars($company['client_username'] ?? '') ?>"
               maxlength="100" autocomplete="off" placeholder="legacy_username">
      </div>

      <div class="form-group">
        <label class="form-label">Client Password <span class="hint">(client_password — plain text)</span></label>
        <div class="pw-wrap">
          <input type="password" class="form-input" id="f_client_password"
                 value="<?= htmlspecialchars($company['client_password'] ?? '') ?>"
                 maxlength="255" autocomplete="off" placeholder="legacy_password">
          <button type="button" class="pw-eye" onclick="togglePw('f_client_password')">👁</button>
        </div>
      </div>

    </div>
  </div>

  <!-- ── Section 5: Read-only Meta ────────────────────────────── -->
  <div class="form-card">
    <div class="section-hdr">ℹ️ System Information <span style="font-size:11px;font-weight:400;text-transform:none;">(read-only)</span></div>
    <div class="meta-grid">
      <div class="meta-item">
        <div class="mk">Company ID</div>
        <div class="mv">#<?= $company_id ?></div>
      </div>
      <div class="meta-item">
        <div class="mk">Admin ID</div>
        <div class="mv">#<?= $admin_id ?></div>
      </div>
      <div class="meta-item">
        <div class="mk">Created At</div>
        <div class="mv"><?= htmlspecialchars($company['created_at'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="mk">Logo File</div>
        <div class="mv"><?= $logo_file ? htmlspecialchars($logo_file) : '—' ?></div>
      </div>
    </div>
  </div>

  <!-- ── Save / Actions ───────────────────────────────────────── -->
  <div class="form-actions">
    <a href="user_clients.php" class="btn-back">← Back to Clients</a>
    <a href="user_settings.php?admin_id=<?= $admin_id ?>&company_id=<?= $company_id ?>"
       class="btn-back" style="color:#059669;border-color:#a7f3d0;background:#f0fdf4;">⚙️ Settings</a>
    <button class="btn-save" id="saveBtn" onclick="saveProfile()">💾 Save Profile</button>
  </div>

</div>

<!-- Footer -->
<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="vizard_browser.php">Projects</a>
  <a href="user_clients.php">Clients</a>
  <a href="user_settings.php">Settings</a>
  <span>© <?= date('Y') ?> VideoVizard</span>
</footer>

<div class="toast" id="toast" style="opacity:0"></div>

<script>
const ADMIN_ID   = <?= $admin_id ?>;
const COMPANY_ID = <?= $company_id ?>;

// ── Dropdown ──────────────────────────────────────────────────
function toggleDropdown(){
  document.getElementById('dropdownMenu').classList.toggle('open');
  document.getElementById('profileBtn').classList.toggle('open');
}
document.addEventListener('click', e => {
  const pw = document.querySelector('.profile-wrap');
  if (pw && !pw.contains(e.target)) {
    document.getElementById('dropdownMenu')?.classList.remove('open');
    document.getElementById('profileBtn')?.classList.remove('open');
  }
});

// ── Password eye ──────────────────────────────────────────────
function togglePw(id){
  const f = document.getElementById(id);
  f.type  = f.type === 'password' ? 'text' : 'password';
}

// ── Logo preview ──────────────────────────────────────────────
function previewLogo(input){
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    // Update hero avatar
    const hero = document.getElementById('heroAvatar');
    hero.innerHTML = `<img src="${e.target.result}" alt="Logo" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">`;
    // Update preview box
    const box = document.getElementById('logoPreviewBox');
    box.innerHTML = `<img src="${e.target.result}" alt="Logo" id="logoPreviewImg" style="width:100%;height:100%;object-fit:cover;">`;
  };
  reader.readAsDataURL(file);
}

// ── Save profile ──────────────────────────────────────────────
async function saveProfile(){
  const get = id => document.getElementById(id)?.value?.trim() ?? '';
  const btn = document.getElementById('saveBtn');
  const errEl = document.getElementById('alertErr');
  const okEl  = document.getElementById('alertOk');
  errEl.style.display = 'none';
  okEl.style.display  = 'none';

  const name = get('f_companyname');
  if (!name) {
    errEl.textContent  = 'Company name is required.';
    errEl.style.display = 'block';
    document.getElementById('f_companyname').focus();
    return;
  }
  const email = get('f_email');
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errEl.textContent  = 'Please enter a valid email address.';
    errEl.style.display = 'block';
    return;
  }

  btn.disabled = true; btn.textContent = '⏳ Saving…';

  const fd = new FormData();
  fd.append('ajax_action',     'save_profile');
  fd.append('company_id',      COMPANY_ID);
  fd.append('companyname',     name);
  fd.append('company_type',    get('f_company_type'));
  fd.append('status',          get('f_status'));
  fd.append('email',           email);
  fd.append('phone',           get('f_phone'));
  fd.append('website',         get('f_website'));
  fd.append('address',         document.getElementById('f_address')?.value?.trim() ?? '');
  fd.append('username',        get('f_username'));
  fd.append('new_password',    document.getElementById('f_new_password')?.value ?? '');
  fd.append('client_username', get('f_client_username'));
  fd.append('client_password', document.getElementById('f_client_password')?.value ?? '');

  const logoFile = document.getElementById('f_logo_file')?.files[0];
  if (logoFile) fd.append('logo_file', logoFile);

  try {
    const r = await fetch('company_profile.php?company_id=' + COMPANY_ID, {
      method: 'POST', body: fd, credentials: 'include'
    });
    const d = await r.json();
    if (d.success) {
      okEl.textContent  = '✓ Profile saved successfully!';
      okEl.style.display = 'block';
      // Update hero name live
      document.getElementById('heroName').textContent = name;
      document.getElementById('f_new_password').value = '';
      showToast('✅ Profile saved');
      okEl.scrollIntoView({behavior:'smooth', block:'nearest'});
    } else {
      errEl.textContent  = d.error || 'Failed to save profile.';
      errEl.style.display = 'block';
    }
  } catch(e) {
    errEl.textContent  = 'Network error — please try again.';
    errEl.style.display = 'block';
  }
  btn.disabled = false; btn.textContent = '💾 Save Profile';
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(() => t.style.opacity = '0', 2400);
}
</script>
</body>
</html>
