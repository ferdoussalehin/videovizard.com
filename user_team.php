<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$admin_id = (int)$_SESSION['admin_id'];

include 'dbconnect_hdb.php';
require_once __DIR__ . '/config.php';

// ── Load admin plan + role ────────────────────────────────────
$admin_info = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT firstname, lastname, plan_type, role FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$plan_type     = $admin_info['plan_type'] ?? 'free_trial';
$user_role     = $admin_info['role']      ?? 'Team Lead';
$firstname     = $admin_info['firstname'] ?? 'User';
$admin_initial = strtoupper(substr($firstname, 0, 1));

// ── Only Team Leads can manage members ───────────────────────
if ($user_role === 'Team Member') {
    header("Location: vizard_browser.php?error=no_permission");
    exit;
}

// Max 1 member for free_trial/personal, unlimited for agency
$max_members = ($plan_type === 'agency') ? PHP_INT_MAX : 1;

// ── AJAX ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    // ── add_member ───────────────────────────────────────────
    if ($_POST['ajax_action'] === 'add_member') {

        // Plan limit check
        $member_count = (int)mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) as c FROM hdb_users
             WHERE team_lead_id=$admin_id AND role='Team Member'"))['c'];

        if ($plan_type !== 'agency' && $member_count >= 1) {
            echo json_encode([
                'success' => false,
                'limit'   => true,
                'error'   => ($plan_type === 'free_trial')
                    ? 'Free Trial allows only 1 team member. Upgrade to Agency for unlimited members.'
                    : 'Personal plan allows only 1 team member. Upgrade to Agency for unlimited members.',
            ]);
            exit;
        }

        $first_name = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
        $last_name  = mysqli_real_escape_string($conn, trim($_POST['last_name']  ?? ''));
        $email      = mysqli_real_escape_string($conn, trim($_POST['email']      ?? ''));
        $password   = trim($_POST['password'] ?? '');

        if (empty($first_name) || empty($email) || strlen($password) < 6) {
            echo json_encode(['success' => false, 'error' => 'First name, email and password are required. Password min 6 chars.']);
            exit;
        }

        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_users WHERE email_id='$email' LIMIT 1"));
        if ($exists) {
            echo json_encode(['success' => false, 'error' => 'This email is already registered.']);
            exit;
        }

        mysqli_query($conn, "START TRANSACTION");
        try {
            // 1. Create member user
            //    role           = 'Team Member'
            //    team_lead_id   = admin_id (the Team Lead adding them)
            //    credit_balance = 0  (members use lead's credits)
            $hashed = $password;
            $ok = mysqli_query($conn,
                "INSERT INTO hdb_users
                    (firstname, lastname, email_id, password, plan_type,
                     level_name, role, team_lead_id, credit_balance, created_at)
                 VALUES
                    ('$first_name', '$last_name', '$email', '$hashed', '$plan_type',
                     'user', 'Team Member', $admin_id, 0, NOW())");
            if (!$ok) throw new Exception('User insert failed: ' . mysqli_error($conn));
            $member_id = mysqli_insert_id($conn);

            // 2. Create member's company workspace
			/*
		   $company_name = mysqli_real_escape_string($conn, $first_name . "'s Workspace");
            $ok = mysqli_query($conn,
                "INSERT INTO hdb_companies (companyname, admin_id, created_at)
                 VALUES ('$company_name', $member_id, NOW())");
            if (!$ok) throw new Exception('Company insert failed: ' . mysqli_error($conn));
            $company_id = mysqli_insert_id($conn);

            // 3. Update user with company_id and admin_id
            mysqli_query($conn,
                "UPDATE hdb_users SET company_id=$company_id, admin_id=$member_id
                 WHERE id=$member_id");
			*/
            // 4. Insert 4 hdb_user_settings rows: caption, header, footer, logo
            $tpl = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT * FROM hdb_user_settings
                 WHERE admin_id=1 AND company_id=1 AND text_type='caption' LIMIT 1"));

            $ff  = mysqli_real_escape_string($conn, $tpl['fontfamily']        ?? 'Arial,sans-serif');
            $fs  = (int)($tpl['fontsize']                                      ?? 28);
            $fc  = mysqli_real_escape_string($conn, $tpl['fontcolor']          ?? '#ffffff');
            $fw  = mysqli_real_escape_string($conn, $tpl['fontweight']         ?? 'bold');
            $fbg = mysqli_real_escape_string($conn, $tpl['fontcolor_bg']       ?? '#000000');
            $fbe = (int)($tpl['fontbg_enable']                                 ?? 0);
            $cs  = mysqli_real_escape_string($conn, $tpl['caption_style']      ?? 'none');
            $cp  = mysqli_real_escape_string($conn, $tpl['caption_position']   ?? 'bottom');
            $ca  = mysqli_real_escape_string($conn, $tpl['caption_alignment']  ?? 'center');
            $csp = (int)($tpl['caption_speed']                                 ?? 1);
            $px  = (int)($tpl['position_x']                                    ?? 50);
            $py  = (int)($tpl['position_y']                                    ?? 250);
            $pw  = (int)($tpl['width']                                         ?? 500);
            $fn  = mysqli_real_escape_string($conn, $first_name);

            $text_types = [
                ['caption', 1,  $ff,$fs,$fc,$fw,$fbg,$fbe,'none','bottom','center',$csp,$px,$py,$pw,'',''],
                ['header',  0,  'Helvetica',16,'#ffffff','bold','#1a1a2e',1,'box','top','center',1,0,0,1080,"$fn's Studio",''],
                ['footer',  0,  'Georgia',12,'#aaaaaa','normal','#000000',0,'none','bottom','center',1,0,0,1080,'','Follow for more tips'],
                ['logo',    0,  $ff,$fs,$fc,$fw,$fbg,$fbe,'none','top','right',1,900,40,160,'',''],
            ];

            foreach ($text_types as $tt) {
                [$ttype,$en,$tff,$tfs,$tfc,$tfw,$tfbg,$tfbe,$tcs,$tcp,$tca,$tcsp,$tpx,$tpy,$tpw,$ht,$ft] = $tt;
                $te   = mysqli_real_escape_string($conn, $ttype);
                $tff2 = mysqli_real_escape_string($conn, $tff);
                $tfc2 = mysqli_real_escape_string($conn, $tfc);
                $tfw2 = mysqli_real_escape_string($conn, $tfw);
                $tfbg2= mysqli_real_escape_string($conn, $tfbg);
                $tcs2 = mysqli_real_escape_string($conn, $tcs);
                $tcp2 = mysqli_real_escape_string($conn, $tcp);
                $tca2 = mysqli_real_escape_string($conn, $tca);
                $ht2  = mysqli_real_escape_string($conn, $ht);
                $ft2  = mysqli_real_escape_string($conn, $ft);

                $ins = "INSERT INTO hdb_user_settings
                    (admin_id, company_id, text_type, is_enabled,
                     fontfamily, fontsize, fontcolor, fontweight, fontcolor_bg, fontbg_enable,
                     caption_style, caption_position, caption_alignment, caption_speed,
                     position_x, position_y, width,
                     caption_text, created_at)
                VALUES
                    ($member_id, $company_id, '$te', $en,
                     '$tff2', $tfs, '$tfc2', '$tfw2', '$tfbg2', $tfbe,
                     '$tcs2', '$tcp2', '$tca2', $tcsp,
                     $tpx, $tpy, $tpw,
                     '$ht2', NOW())";
                if (!mysqli_query($conn, $ins))
                    throw new Exception("Settings insert ($ttype) failed: " . mysqli_error($conn));
            }

            mysqli_query($conn, "COMMIT");
            echo json_encode([
                'success'    => true,
                'member_id'  => $member_id,
                'first_name' => $_POST['first_name'],
                'last_name'  => $_POST['last_name'] ?? '',
                'email'      => $_POST['email'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

        } catch (Exception $e) {
            mysqli_query($conn, "ROLLBACK");
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── remove_member ─────────────────────────────────────────
    if ($_POST['ajax_action'] === 'remove_member') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $ok = mysqli_query($conn,
            "DELETE FROM hdb_users
             WHERE id=$member_id AND team_lead_id=$admin_id AND role='Team Member'");
        echo json_encode(['success' => (bool)$ok && mysqli_affected_rows($conn) > 0]);
        exit;
    }

    // ── reset_password ────────────────────────────────────────
    if ($_POST['ajax_action'] === 'reset_password') {
        $member_id    = (int)($_POST['member_id'] ?? 0);
        $new_password = mysqli_real_escape_string($conn, trim($_POST['new_password'] ?? ''));
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']); exit;
        }
        $ok = mysqli_query($conn,
            "UPDATE hdb_users SET password='$new_password'
             WHERE id=$member_id AND team_lead_id=$admin_id AND role='Team Member'");
        echo json_encode(['success' => (bool)$ok && mysqli_affected_rows($conn) > 0]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']); exit;
}

// ── Load members ──────────────────────────────────────────────
$members = [];
$mq = mysqli_query($conn,
    "SELECT id, firstname, lastname, email_id, created_at
     FROM hdb_users
     WHERE team_lead_id=$admin_id AND role='Team Member'
     ORDER BY id DESC");
while ($r = mysqli_fetch_assoc($mq)) $members[] = $r;
$member_count = count($members);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Team Members — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --green:     #10b981;
  --indigo:    #6366f1;
  --indigo2:   #4f46e5;
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
.dropdown-menu.open{display:block;animation:slideDown .2s ease;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.dropdown-user{padding:14px 16px;border-bottom:1px solid var(--border);}
.d-name{font-weight:700;font-size:14px;color:var(--dark-blue);}
.d-role{font-size:12px;color:var(--muted);margin-top:2px;}
.dropdown-item{padding:12px 16px;font-size:14px;color:var(--text);display:flex;
               align-items:center;gap:10px;text-decoration:none;transition:background .15s;}
.dropdown-item:hover{background:#f8fafc;}
.dropdown-item.active{background:#eff6ff;color:var(--dark-blue);font-weight:600;}
.dropdown-item.logout{color:#ef4444;}
.dropdown-divider{height:1px;background:var(--border);}

.main{flex:1;padding:28px 20px;max-width:1100px;margin:0 auto;width:100%;}

.page-hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:28px;}
.page-title{font-size:26px;font-weight:800;color:var(--dark-blue);}
.page-title span{color:var(--indigo);}
.page-meta{font-size:13px;color:var(--muted);margin-top:3px;}

.btn-add{display:inline-flex;align-items:center;gap:8px;
         background:linear-gradient(135deg,var(--indigo),var(--indigo2));
         color:#fff;border:none;padding:12px 22px;border-radius:12px;
         font-size:14px;font-weight:700;cursor:pointer;
         box-shadow:0 4px 14px rgba(99,102,241,.3);transition:all .2s;
         font-family:'Plus Jakarta Sans',sans-serif;}
.btn-add:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(99,102,241,.4);}
.btn-add:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}

.stats-strip{display:flex;gap:14px;margin-bottom:24px;flex-wrap:wrap;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;
           padding:16px 20px;flex:1;min-width:140px;box-shadow:var(--shadow);}
.stat-card .sv{font-size:28px;font-weight:800;color:var(--dark-blue);line-height:1;}
.stat-card .sl{font-size:12px;color:var(--muted);margin-top:4px;font-weight:500;}
.stat-card.hi .sv{color:var(--indigo);}

.member-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;}

.member-card{background:var(--card);border:1px solid var(--border);border-radius:16px;
             overflow:hidden;box-shadow:var(--shadow);transition:all .25s;}
.member-card:hover{border-color:#a5b4fc;box-shadow:0 6px 20px rgba(99,102,241,.12);transform:translateY(-2px);}
.member-card-top{padding:20px 20px 16px;display:flex;align-items:flex-start;gap:14px;}
.member-avatar{width:48px;height:48px;border-radius:12px;flex-shrink:0;
               display:flex;align-items:center;justify-content:center;
               font-size:20px;font-weight:800;color:#fff;
               background:linear-gradient(135deg,var(--indigo),var(--indigo2));}
.member-info{flex:1;min-width:0;}
.member-name{font-size:16px;font-weight:700;color:var(--dark-blue);
             white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;}
.member-email{font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;}
.member-id{font-size:11px;color:var(--muted);}
.role-badge{display:inline-block;background:#ede9fe;color:#5b21b6;
            padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}

.member-card-footer{padding:12px 16px;border-top:1px solid var(--border);
                    background:#fafbfc;display:flex;gap:8px;align-items:center;}
.member-date{font-size:11px;color:var(--muted);flex:1;}

.action-btn{padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;
            cursor:pointer;transition:all .15s;border:1.5px solid;
            font-family:'Plus Jakarta Sans',sans-serif;}
.action-btn.pwd{background:#f8fafc;color:var(--muted);border-color:var(--border);}
.action-btn.pwd:hover{background:#f1f5f9;color:var(--text);}
.action-btn.del{background:#fff;color:#ef4444;border-color:#fecaca;}
.action-btn.del:hover{background:#fef2f2;}

.empty-state{text-align:center;padding:60px 20px;background:var(--card);
             border:2px dashed var(--border);border-radius:16px;grid-column:1/-1;}
.empty-icon{font-size:56px;margin-bottom:16px;}
.empty-state h3{font-size:18px;font-weight:700;color:var(--dark-blue);margin-bottom:8px;}
.empty-state p{font-size:14px;color:var(--muted);}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
               z-index:2000;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--card);border-radius:20px;padding:32px;width:100%;max-width:460px;
       box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal h2{font-size:20px;font-weight:800;color:var(--dark-blue);margin-bottom:6px;}
.modal p{font-size:13px;color:var(--muted);margin-bottom:20px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--dark-blue);margin-bottom:6px;}
.form-input{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;
            font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;
            color:var(--text);outline:none;transition:border-color .15s;background:#fff;}
.form-input:focus{border-color:var(--indigo);}
.form-hint{font-size:11px;color:var(--muted);margin-top:5px;}
.modal-actions{display:flex;gap:10px;margin-top:6px;}
.btn-cancel{flex:1;padding:12px;border:1.5px solid var(--border);border-radius:10px;
            background:#fff;color:var(--muted);font-size:14px;font-weight:600;
            cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;}
.btn-cancel:hover{background:#f8fafc;}
.btn-submit{flex:2;padding:12px;border:none;border-radius:10px;
            background:linear-gradient(135deg,var(--indigo),var(--indigo2));
            color:#fff;font-size:14px;font-weight:700;cursor:pointer;
            font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;}
.btn-submit:hover{opacity:.9;}
.btn-submit:disabled{opacity:.6;cursor:not-allowed;}
.btn-submit.danger{background:linear-gradient(135deg,#ef4444,#b91c1c);}

.error-msg{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;
           padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;display:none;}
.success-msg{background:#f0fdf4;border:1px solid #86efac;color:#166634;
             padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;display:none;}

.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);
       background:#1e293b;color:#fff;padding:10px 20px;border-radius:10px;
       font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;pointer-events:none;}

.site-footer{background:linear-gradient(90deg,#0f2a44,#143b63);color:rgba(255,255,255,.55);
             padding:14px 20px;font-size:12px;display:flex;justify-content:center;
             align-items:center;gap:24px;flex-wrap:wrap;margin-top:auto;}
.site-footer a{color:rgba(255,255,255,.55);text-decoration:none;transition:color .2s;}
.site-footer a:hover{color:var(--accent);}
.footer-brand{font-weight:700;color:var(--accent);}

.av-0{background:linear-gradient(135deg,#6366f1,#4f46e5);}
.av-1{background:linear-gradient(135deg,#10b981,#059669);}
.av-2{background:linear-gradient(135deg,#8b5cf6,#6d28d9);}
.av-3{background:linear-gradient(135deg,#f59e0b,#d97706);}
.av-4{background:linear-gradient(135deg,#ef4444,#b91c1c);}
.av-5{background:linear-gradient(135deg,#06b6d4,#0891b2);}

@media(max-width:640px){
  .main{padding:16px;}
  .member-grid{grid-template-columns:1fr;}
  .stats-strip{gap:10px;}
  .form-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- Header -->
<header class="vidora-header">
  <a class="brand-link" href="vizard_browser.php">
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
          <div class="d-role">Team Lead · <?= ucfirst(str_replace('_',' ',$plan_type)) ?></div>
        </div>
        <a href="vizard_browser.php" class="dropdown-item">🎬 My Projects</a>
        <a href="vizard_scriptgen.php" class="dropdown-item">✨ Create Video</a>
        <a href="user_clients.php" class="dropdown-item">👥 Clients</a>
       
        <a href="settings.php" class="dropdown-item">⚙️ Settings</a>
        <div class="dropdown-divider"></div>
        <a href="logout.php" class="dropdown-item logout">🚪 Logout</a>
      </div>
    </div>
  </div>
</header>

<!-- Add Member Modal -->
<div class="modal-overlay" id="addModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h2>➕ Add Team Member</h2>
    <p>They'll get their own login and can access your workspace.</p>
    <div class="error-msg"   id="modalError"></div>
    <div class="success-msg" id="modalSuccess"></div>
    <div class="form-row">
      <div>
        <label class="form-label">First Name *</label>
        <input type="text" class="form-input" id="mFirstName" placeholder="Jane" maxlength="60">
      </div>
      <div>
        <label class="form-label">Last Name</label>
        <input type="text" class="form-input" id="mLastName" placeholder="Smith" maxlength="60">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email Address *</label>
      <input type="email" class="form-input" id="mEmail" placeholder="jane@example.com">
    </div>
    <div class="form-group">
      <label class="form-label">Temporary Password *</label>
      <input type="text" class="form-input" id="mPassword" placeholder="Min 6 characters">
      <div class="form-hint">Share this with the member so they can log in.</div>
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-submit" id="submitBtn" onclick="submitAddMember()">➕ Add Member</button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="pwdModal" onclick="if(event.target===this)closePwdModal()">
  <div class="modal">
    <h2>🔑 Reset Password</h2>
    <p id="pwdModalMsg">Set a new temporary password for this member.</p>
    <div class="error-msg" id="pwdError"></div>
    <input type="hidden" id="pwdMemberId">
    <div class="form-group">
      <label class="form-label">New Password *</label>
      <input type="text" class="form-input" id="newPwd" placeholder="Min 6 characters">
    </div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closePwdModal()">Cancel</button>
      <button class="btn-submit" onclick="submitResetPwd()">🔑 Reset Password</button>
    </div>
  </div>
</div>

<!-- Remove Confirm Modal -->
<div class="modal-overlay" id="delModal" onclick="if(event.target===this)closeDelModal()">
  <div class="modal">
    <h2>Remove Member?</h2>
    <p id="delModalMsg">This will permanently delete this team member's account.</p>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeDelModal()">Cancel</button>
      <button class="btn-submit danger" id="confirmDelBtn">🗑 Remove</button>
    </div>
  </div>
</div>

<!-- Main -->
<div class="main">

  <div class="page-hdr">
    <div>
      <h1 class="page-title">Team <span>Members</span></h1>
      <div class="page-meta">
        <?= $member_count ?> team member<?= $member_count!==1?'s':'' ?> under your account
        &nbsp;·&nbsp;
        <?php if ($plan_type === 'agency'): ?>
          <span style="background:#dcfce7;color:#15803d;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Agency — Unlimited members</span>
        <?php elseif ($plan_type === 'personal'): ?>
          <span style="background:#eff6ff;color:#1d4ed8;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Personal — 1 member max</span>
        <?php else: ?>
          <span style="background:#fef9c3;color:#854d0e;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;">Free Trial — 1 member max</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="user_clients.php" style="display:inline-flex;align-items:center;gap:7px;
         background:linear-gradient(135deg,#10b981,#059669);color:#fff;
         padding:12px 20px;border-radius:12px;font-size:14px;font-weight:700;
         text-decoration:none;box-shadow:0 4px 14px rgba(16,185,129,.3);">
        👥 Clients
      </a>
      <button class="btn-add" id="addBtn" onclick="openModal()"
        <?= ($plan_type !== 'agency' && $member_count >= 1) ? 'disabled title="Upgrade to Agency for unlimited members"' : '' ?>>
        ➕ Add Member
      </button>
    </div>
  </div>

  <?php if ($plan_type !== 'agency' && $member_count >= 1): ?>
  <div style="background:#fffbeb;border:1.5px solid #fbbf24;border-radius:12px;
              padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;
              justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="font-size:14px;color:#92400e;font-weight:600;">
      🔒 You've used your 1 team member slot on the
      <strong><?= ucfirst(str_replace('_',' ',$plan_type)) ?></strong> plan.
      Upgrade to Agency for unlimited team members.
    </div>
    <a href="pricing.php" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;
       padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;
       text-decoration:none;white-space:nowrap;">Upgrade →</a>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-strip">
    <div class="stat-card hi">
      <div class="sv"><?= $member_count ?></div>
      <div class="sl">Team members</div>
    </div>
    <div class="stat-card">
      <div class="sv"><?= $plan_type === 'agency' ? '∞' : '1' ?></div>
      <div class="sl">Member limit</div>
    </div>
    <div class="stat-card">
      <div class="sv"><?= $plan_type === 'agency' ? '∞' : max(0, 1 - $member_count) ?></div>
      <div class="sl">Slots remaining</div>
    </div>
  </div>

  <!-- Member grid -->
  <div class="member-grid" id="memberGrid">
    <?php if (empty($members)): ?>
    <div class="empty-state">
      <div class="empty-icon">👤</div>
      <h3>No team members yet</h3>
      <p>Click "Add Member" to invite someone to your workspace.</p>
    </div>
    <?php else: ?>
    <?php foreach ($members as $idx => $m):
      $full_name = trim(($m['firstname'] ?? '') . ' ' . ($m['lastname'] ?? ''));
      $initial   = strtoupper(substr($m['firstname'] ?? 'M', 0, 1));
      $av_class  = 'av-' . ($idx % 6);
      $date_fmt  = $m['created_at'] ? date('M j, Y', strtotime($m['created_at'])) : '—';
    ?>
    <div class="member-card" id="mc-<?= $m['id'] ?>">
      <div class="member-card-top">
        <div class="member-avatar <?= $av_class ?>"><?= htmlspecialchars($initial) ?></div>
        <div class="member-info">
          <div class="member-name"><?= htmlspecialchars($full_name) ?></div>
          <div class="member-email"><?= htmlspecialchars($m['email_id'] ?? '') ?></div>
          <div class="member-id">ID #<?= $m['id'] ?> &nbsp;·&nbsp; <span class="role-badge">Team Member</span></div>
        </div>
      </div>
      <div class="member-card-footer">
        <span class="member-date">Added <?= $date_fmt ?></span>
        <button class="action-btn pwd"
                onclick="openPwdModal(<?= $m['id'] ?>, '<?= htmlspecialchars($full_name, ENT_QUOTES) ?>')">
          🔑 Reset Pwd
        </button>
        <button class="action-btn del"
                onclick="confirmRemove(<?= $m['id'] ?>, '<?= htmlspecialchars($full_name, ENT_QUOTES) ?>')">
          🗑 Remove
        </button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="vizard_browser.php">Projects</a>
  <a href="user_clients.php">Clients</a>
  <a href="team_members.php">Team</a>
  <a href="settings.php">Settings</a>
  <span>© <?= date('Y') ?> VideoVizard</span>
</footer>

<div class="toast" id="toast" style="opacity:0"></div>

<script>
const PLAN_TYPE    = '<?= $plan_type ?>';
let memberCount    = <?= $member_count ?>;

// ── Dropdown ─────────────────────────────────────────────────
function toggleDropdown(){
  document.getElementById('dropdownMenu').classList.toggle('open');
  document.getElementById('profileBtn').classList.toggle('open');
}
document.addEventListener('click', e => {
  const pw = document.querySelector('.profile-wrap');
  if(pw && !pw.contains(e.target)){
    document.getElementById('dropdownMenu')?.classList.remove('open');
    document.getElementById('profileBtn')?.classList.remove('open');
  }
});

// ── Add member modal ──────────────────────────────────────────
function openModal(){
  document.getElementById('mFirstName').value  = '';
  document.getElementById('mLastName').value   = '';
  document.getElementById('mEmail').value      = '';
  document.getElementById('mPassword').value   = '';
  document.getElementById('modalError').style.display   = 'none';
  document.getElementById('modalSuccess').style.display = 'none';
  document.getElementById('addModal').classList.add('open');
  setTimeout(()=>document.getElementById('mFirstName').focus(), 100);
}
function closeModal(){ document.getElementById('addModal').classList.remove('open'); }

async function submitAddMember(){
  const first = document.getElementById('mFirstName').value.trim();
  const last  = document.getElementById('mLastName').value.trim();
  const email = document.getElementById('mEmail').value.trim();
  const pwd   = document.getElementById('mPassword').value.trim();
  const errEl = document.getElementById('modalError');
  const sucEl = document.getElementById('modalSuccess');
  const btn   = document.getElementById('submitBtn');

  errEl.style.display = 'none'; sucEl.style.display = 'none';

  if(!first || !email || !pwd){
    errEl.textContent = 'First name, email and password are required.';
    errEl.style.display = 'block'; return;
  }
  if(pwd.length < 6){
    errEl.textContent = 'Password must be at least 6 characters.';
    errEl.style.display = 'block'; return;
  }

  btn.disabled = true; btn.textContent = '⏳ Adding…';

  const fd = new FormData();
  fd.append('ajax_action', 'add_member');
  fd.append('first_name',  first);
  fd.append('last_name',   last);
  fd.append('email',       email);
  fd.append('password',    pwd);

  try {
    const r = await fetch('user_team.php', {method:'POST', body:fd, credentials:'include'});
    const d = await r.json();
    if(d.success){
      sucEl.textContent = `✓ ${first} added as a Team Member!`;
      sucEl.style.display = 'block';
      addMemberCard(d, first, last, email);
      memberCount++;
      updateLimitUI();
      setTimeout(closeModal, 1200);
      showToast('✅ Member added — ID #' + d.member_id);
    } else if(d.limit){
      errEl.innerHTML = d.error + ' <a href="pricing.php" style="color:#b91c1c;font-weight:700;text-decoration:underline;">Upgrade →</a>';
      errEl.style.display = 'block';
    } else {
      errEl.textContent = d.error || 'Failed to add member.';
      errEl.style.display = 'block';
    }
  } catch(e){
    errEl.textContent = 'Network error. Please try again.';
    errEl.style.display = 'block';
  }
  btn.disabled = false; btn.textContent = '➕ Add Member';
}

function addMemberCard(d, first, last, email){
  const grid  = document.getElementById('memberGrid');
  const empty = grid.querySelector('.empty-state');
  if(empty) empty.remove();

  const fullName = (first + ' ' + last).trim();
  const initial  = first.charAt(0).toUpperCase();
  const avColors = ['av-0','av-1','av-2','av-3','av-4','av-5'];
  const av       = avColors[grid.querySelectorAll('.member-card').length % 6];
  const today    = new Date().toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});

  const card = document.createElement('div');
  card.className = 'member-card';
  card.id = 'mc-' + d.member_id;
  card.innerHTML = `
    <div class="member-card-top">
      <div class="member-avatar ${av}">${escHtml(initial)}</div>
      <div class="member-info">
        <div class="member-name">${escHtml(fullName)}</div>
        <div class="member-email">${escHtml(email)}</div>
        <div class="member-id">ID #${d.member_id} &nbsp;·&nbsp; <span class="role-badge">Team Member</span></div>
      </div>
    </div>
    <div class="member-card-footer">
      <span class="member-date">Added ${today}</span>
      <button class="action-btn pwd" onclick="openPwdModal(${d.member_id},'${escAttr(fullName)}')">🔑 Reset Pwd</button>
      <button class="action-btn del" onclick="confirmRemove(${d.member_id},'${escAttr(fullName)}')">🗑 Remove</button>
    </div>`;
  grid.insertBefore(card, grid.firstChild);
  card.style.animation = 'slideDown .3s ease';
}

// ── Reset password modal ──────────────────────────────────────
function openPwdModal(id, name){
  document.getElementById('pwdMemberId').value = id;
  document.getElementById('pwdModalMsg').textContent = `Set a new password for ${name}.`;
  document.getElementById('newPwd').value = '';
  document.getElementById('pwdError').style.display = 'none';
  document.getElementById('pwdModal').classList.add('open');
  setTimeout(()=>document.getElementById('newPwd').focus(), 100);
}
function closePwdModal(){ document.getElementById('pwdModal').classList.remove('open'); }

async function submitResetPwd(){
  const id  = document.getElementById('pwdMemberId').value;
  const pwd = document.getElementById('newPwd').value.trim();
  const err = document.getElementById('pwdError');
  err.style.display = 'none';
  if(pwd.length < 6){ err.textContent='Password must be at least 6 characters.'; err.style.display='block'; return; }

  const fd = new FormData();
  fd.append('ajax_action',  'reset_password');
  fd.append('member_id',    id);
  fd.append('new_password', pwd);

  try {
    const r = await fetch('team_members.php',{method:'POST',body:fd,credentials:'include'});
    const d = await r.json();
    if(d.success){ closePwdModal(); showToast('🔑 Password reset successfully'); }
    else{ err.textContent = d.error || 'Reset failed.'; err.style.display='block'; }
  } catch(e){ err.textContent='Network error.'; err.style.display='block'; }
}

// ── Remove member ─────────────────────────────────────────────
let pendingRemoveId = null;
function confirmRemove(id, name){
  pendingRemoveId = id;
  document.getElementById('delModalMsg').textContent =
    `Remove "${name}"? Their account will be permanently deleted.`;
  document.getElementById('delModal').classList.add('open');
}
function closeDelModal(){ document.getElementById('delModal').classList.remove('open'); pendingRemoveId=null; }

document.getElementById('confirmDelBtn').addEventListener('click', async () => {
  if(!pendingRemoveId) return;
  const fd = new FormData();
  fd.append('ajax_action','remove_member');
  fd.append('member_id',  pendingRemoveId);
  try {
    const r = await fetch('team_members.php',{method:'POST',body:fd,credentials:'include'});
    const d = await r.json();
    if(d.success){
      const card = document.getElementById('mc-'+pendingRemoveId);
      if(card){
        card.style.opacity='0'; card.style.transform='scale(.95)'; card.style.transition='.3s';
        setTimeout(()=>{ card.remove(); memberCount--; updateLimitUI(); }, 300);
      }
      showToast('Member removed');
    } else { showToast('⚠ Remove failed'); }
  } catch(e){ showToast('⚠ Network error'); }
  closeDelModal();
});

// ── Update limit UI after add/remove ─────────────────────────
function updateLimitUI(){
  const addBtn  = document.getElementById('addBtn');
  const atLimit = PLAN_TYPE !== 'agency' && memberCount >= 1;
  if(addBtn) addBtn.disabled = atLimit;
  const statVals = document.querySelectorAll('.stat-card .sv');
  if(statVals[0]) statVals[0].textContent = memberCount;
  if(statVals[2]) statVals[2].textContent = PLAN_TYPE === 'agency' ? '∞' : Math.max(0, 1 - memberCount);
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg){
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(()=>t.style.opacity='0', 2400);
}

document.addEventListener('keydown', e => {
  if(e.key==='Escape'){ closeModal(); closePwdModal(); closeDelModal(); }
});

function escHtml(s){ return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function escAttr(s){ return String(s).replace(/'/g,"\\'"); }
</script>
</body>
</html>
