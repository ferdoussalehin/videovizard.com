<?php
// ── Auth guard ────────────────────────────────────────────────
session_start(); 
//echo "hello";die;
//var_dump($_SESSION);
//die();
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . 'a_errors.log');
ini_set('display_errors', 0); // Don't show errors to users
error_reporting(E_ALL);


error_reporting(E_ALL);  
ini_set('display_errors', 1);


if (!isset($_SESSION['cts_user_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit;
}

require_once __DIR__ . '/dbconnect.php';

$uid        = (int)$_SESSION['cts_user_id'];
$client_id  = (int)($_SESSION['cts_client_id'] ?? 0);
$firstname  = $_SESSION['cts_firstname'] ?? 'Client';
$lastname   = $_SESSION['cts_lastname']  ?? '';
$fullname   = trim($firstname . ' ' . $lastname);
$initials   = strtoupper(substr($firstname,0,1) . substr($lastname,0,1));
$plan       = $_SESSION['cts_plan'] ?? 'starter';
$is_imp     = !empty($_SESSION['is_impersonating']);
$imp_co     = $_SESSION['impersonated_company'] ?? '';

// Load client record
$cl = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM cts_clients WHERE id=$client_id LIMIT 1"));
if (!$cl) { $cl = ['company_name'=>'My Account','monthly_minutes'=>200,'credit_balance'=>0,'total_calls'=>0,'total_minutes'=>0,'total_appointments'=>0,'plan_type'=>$plan]; }

// ── AJAX handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $act = $_POST['ajax_action'];
	
    // ── Save script ───────────────────────────────────────────
    if ($act === 'save_script') {
        $name    = mysqli_real_escape_string($conn, trim($_POST['name']        ?? ''));
        $dir = in_array($_POST['direction']??'', ['outbound','inbound']) ? $_POST['direction'] : 'outbound';
	  $uc      = mysqli_real_escape_string($conn, trim($_POST['use_case']    ?? ''));
        $opening = mysqli_real_escape_string($conn, trim($_POST['opening_line']?? ''));
        $body    = mysqli_real_escape_string($conn, trim($_POST['script_body'] ?? ''));
        $obj     = mysqli_real_escape_string($conn, trim($_POST['objections']  ?? ''));
        $sid     = (int)($_POST['script_id'] ?? 0);

        if (empty($name) || empty($body)) {
            echo json_encode(['success'=>false,'error'=>'Name and script body are required']); exit;
        }
        if ($sid) {
            $ok = mysqli_query($conn,
                "UPDATE cts_scripts SET name='$name',direction='$dir',use_case='$uc',
                 opening_line='$opening',script_body='$body',objections='$obj',updated_at=NOW()
                 WHERE id=$sid AND client_id=$client_id");
        } else {
            $ok = mysqli_query($conn,
                "INSERT INTO cts_scripts (client_id,name,direction,use_case,opening_line,script_body,objections,created_by,created_at,updated_at)
                 VALUES ($client_id,'$name','$dir','$uc','$opening','$body','$obj',$uid,NOW(),NOW())");
            $sid = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'script_id'=>$sid]); exit;
    }
	
    // ── Delete script ─────────────────────────────────────────
    if ($act === 'delete_script') {
        $sid = (int)($_POST['script_id'] ?? 0);
        $ok  = mysqli_query($conn,"DELETE FROM cts_scripts WHERE id=$sid AND client_id=$client_id");
        echo json_encode(['success'=>(bool)$ok]); exit;
    }

    // ── Save campaign ─────────────────────────────────────────
    if ($act === 'save_campaign') {
        $name      = mysqli_real_escape_string($conn, trim($_POST['name']        ?? ''));
        $agent_id  = (int)($_POST['agent_id']   ?? 0);
        $dir       = $_POST['direction'] === 'inbound' ? 'inbound' : 'outbound';
        $start     = mysqli_real_escape_string($conn, $_POST['start_date']  ?? '');
        $end       = mysqli_real_escape_string($conn, $_POST['end_date']    ?? '');
        $tf        = mysqli_real_escape_string($conn, $_POST['time_from']   ?? '09:00');
        $tt        = mysqli_real_escape_string($conn, $_POST['time_to']     ?? '17:00');
        $tz        = mysqli_real_escape_string($conn, $_POST['timezone']    ?? 'UTC');
        $days      = mysqli_real_escape_string($conn, $_POST['calling_days']?? 'mon,tue,wed,thu,fri');
        $max_calls = $_POST['max_calls']   !== '' ? (int)$_POST['max_calls']   : 'NULL';
        $max_mins  = $_POST['max_minutes'] !== '' ? (int)$_POST['max_minutes'] : 'NULL';
        $budget    = $_POST['budget_cap']  !== '' ? floatval($_POST['budget_cap']) : 'NULL';
        $max_ret   = (int)($_POST['max_retries']  ?? 2);
        $ret_hrs   = (int)($_POST['retry_hours']  ?? 4);
        $ret_na    = isset($_POST['retry_no_answer']) ? 1 : 0;
        $ret_vm    = isset($_POST['retry_voicemail']) ? 1 : 0;
        $cid_camp  = (int)($_POST['campaign_id']  ?? 0);

        if (empty($name)) { echo json_encode(['success'=>false,'error'=>'Campaign name required']); exit; }

        $budget_sql = is_numeric($budget) ? $budget : 'NULL';
        $calls_sql  = is_numeric($max_calls) ? $max_calls : 'NULL';
        $mins_sql   = is_numeric($max_mins) ? $max_mins : 'NULL';

        if ($cid_camp) {
            $ok = mysqli_query($conn,
                "UPDATE cts_campaigns SET name='$name',agent_id=$agent_id,direction='$dir',
                 start_date='$start',end_date=" . ($end?:"NULL") . ",calling_time_from='$tf',
                 calling_time_to='$tt',timezone='$tz',calling_days='$days',
                 max_calls=$calls_sql,max_minutes=$mins_sql,budget_cap=$budget_sql,
                 max_retries=$max_ret,retry_interval_hrs=$ret_hrs,
                 retry_no_answer=$ret_na,retry_voicemail=$ret_vm,updated_at=NOW()
                 WHERE id=$cid_camp AND client_id=$client_id");
        } else {
            $ok = mysqli_query($conn,
                "INSERT INTO cts_campaigns
                 (client_id,name,agent_id,direction,status,start_date,end_date,
                  calling_time_from,calling_time_to,timezone,calling_days,
                  max_calls,max_minutes,budget_cap,max_retries,retry_interval_hrs,
                  retry_no_answer,retry_voicemail,created_by,created_at,updated_at)
                 VALUES
                 ($client_id,'$name',$agent_id,'$dir','draft','$start',
                  " . ($end ? "'$end'" : "NULL") . ",'$tf','$tt','$tz','$days',
                  $calls_sql,$mins_sql,$budget_sql,$max_ret,$ret_hrs,
                  $ret_na,$ret_vm,$uid,NOW(),NOW())");
            $cid_camp = mysqli_insert_id($conn);
        }
        echo json_encode(['success'=>(bool)$ok,'campaign_id'=>$cid_camp]); exit;
    }
	
    // ── Toggle campaign status ────────────────────────────────
    if ($act === 'toggle_campaign') {
        $cid_camp = (int)($_POST['campaign_id'] ?? 0);
        $cur      = $_POST['current_status'] ?? 'draft';
        if ($cur === 'running') $new = 'paused';
		elseif ($cur === 'paused' || $cur === 'draft') $new = 'running';
		else $new = 'paused';
	   
	   $ok = mysqli_query($conn,"UPDATE cts_campaigns SET status='$new',updated_at=NOW() WHERE id=$cid_camp AND client_id=$client_id");
        echo json_encode(['success'=>(bool)$ok,'new_status'=>$new]); exit;
    }
	
    // ── Upload leads (CSV parse) ──────────────────────────────
    if ($act === 'upload_leads') {
        $cid_camp = (int)($_POST['campaign_id'] ?? 0);
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success'=>false,'error'=>'No file received']); exit;
        }
        $tmp  = $_FILES['csv_file']['tmp_name'];
        $rows = 0; $dupes = 0; $bad = 0;
        if (($fh = fopen($tmp,'r')) !== false) {
            $header = fgetcsv($fh); // skip header row
            $header = array_map('strtolower', array_map('trim', $header));
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 2) { $bad++; continue; }
                $data   = array_combine(array_slice($header,0,count($row)), $row);
                $fn_l   = mysqli_real_escape_string($conn, trim($data['firstname'] ?? $data['first_name'] ?? $data['first'] ?? ''));
                $ln_l   = mysqli_real_escape_string($conn, trim($data['lastname']  ?? $data['last_name']  ?? $data['last']  ?? ''));
                $ph_l   = mysqli_real_escape_string($conn, preg_replace('/[^0-9+]/','',$data['phone'] ?? $data['phone_number'] ?? ''));
                $em_l   = mysqli_real_escape_string($conn, trim($data['email'] ?? ''));
                $city_l = mysqli_real_escape_string($conn, trim($data['city']  ?? ''));
                $st_l   = mysqli_real_escape_string($conn, trim($data['state'] ?? ''));
                if (empty($ph_l)) { $bad++; continue; }
                // Dupe check
                $dc = mysqli_fetch_assoc(mysqli_query($conn,"SELECT id FROM cts_leads WHERE client_id=$client_id AND phone='$ph_l' LIMIT 1"));
                if ($dc) { $dupes++; continue; }
                mysqli_query($conn,
                    "INSERT INTO cts_leads (client_id,campaign_id,firstname,lastname,phone,email,city,state,status,created_at)
                     VALUES ($client_id,$cid_camp,'$fn_l','$ln_l','$ph_l','$em_l','$city_l','$st_l','new',NOW())");
                $rows++;
            }
            fclose($fh);
            // Update campaign total_leads
            mysqli_query($conn,"UPDATE cts_campaigns SET total_leads=total_leads+$rows WHERE id=$cid_camp");
        }
        echo json_encode(['success'=>true,'inserted'=>$rows,'duplicates'=>$dupes,'invalid'=>$bad]); exit;
    }

    // ── Get script for editing ────────────────────────────────
    if ($act === 'get_script') {
        $sid = (int)($_POST['script_id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM cts_scripts WHERE id=$sid AND client_id=$client_id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'script'=>$row]); exit;
    }

    // ── Get campaign for editing ──────────────────────────────
    if ($act === 'get_campaign') {
        $cid_camp = (int)($_POST['campaign_id'] ?? 0);
        $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM cts_campaigns WHERE id=$cid_camp AND client_id=$client_id LIMIT 1"));
        echo json_encode(['success'=>(bool)$row,'campaign'=>$row]); exit;
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
	
	
	
}

// ── Load data ─────────────────────────────────────────────────
// Stats
$stat = [];
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_campaigns WHERE client_id=$client_id AND status='running'"));
$stat['active_campaigns'] = (int)$r['c'];
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_call_log WHERE client_id=$client_id AND DATE(initiated_at)=CURDATE()"));
$stat['calls_today'] = (int)$r['c'];
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(talk_seconds),0) s FROM cts_call_log WHERE client_id=$client_id AND DATE(initiated_at)=CURDATE()"));
$stat['mins_today'] = round($r['s']/60,1);
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_appointments WHERE client_id=$client_id AND MONTH(created_at)=MONTH(NOW())"));
$stat['appts_month'] = (int)$r['c'];
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_leads WHERE client_id=$client_id"));
$stat['total_leads'] = (int)$r['c'];
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM cts_scripts WHERE client_id=$client_id"));
$stat['total_scripts'] = (int)$r['c'];

// Campaigns
$campaigns = [];
$q = mysqli_query($conn,"SELECT * FROM cts_campaigns WHERE client_id=$client_id ORDER BY id DESC");
while($r=mysqli_fetch_assoc($q)) $campaigns[]=$r;

// Scripts
$scripts = [];
$q = mysqli_query($conn,"SELECT * FROM cts_scripts WHERE client_id=$client_id ORDER BY id DESC");
while($r=mysqli_fetch_assoc($q)) $scripts[]=$r;

// Agents available
$agents = [];
$q = mysqli_query($conn,"SELECT * FROM cts_agents WHERE client_id=$client_id AND status='active' ORDER BY name");
while($r=mysqli_fetch_assoc($q)) $agents[]=$r;

// Recent calls
$recent_calls = [];
$q = mysqli_query($conn,"SELECT * FROM cts_call_log WHERE client_id=$client_id ORDER BY initiated_at DESC LIMIT 8");
while($r=mysqli_fetch_assoc($q)) $recent_calls[]=$r;

// Recent leads
$recent_leads = [];
$q = mysqli_query($conn,"SELECT * FROM cts_leads WHERE client_id=$client_id ORDER BY id DESC LIMIT 6");
while($r=mysqli_fetch_assoc($q)) $recent_leads[]=$r;

// Minutes used this month
$r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COALESCE(SUM(billed_minutes),0) m FROM cts_call_log WHERE client_id=$client_id AND MONTH(initiated_at)=MONTH(NOW())"));
$mins_used  = round((float)$r['m'],1);
$mins_total = (int)($cl['monthly_minutes'] ?? 200);
$mins_pct   = $mins_total > 0 ? min(100, round($mins_used/$mins_total*100)) : 0;

function fmtSecs($s){ $s=(int)$s; if($s<60)return $s.'s'; return floor($s/60).'m '.($s%60).'s'; }
function timeAgo($dt){ if(!$dt)return '—'; $d=time()-strtotime($dt); if($d<60)return 'Just now'; if($d<3600)return floor($d/60).'m ago'; if($d<86400)return floor($d/3600).'h ago'; return date('M j',strtotime($dt)); }


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — <?= htmlspecialchars($cl['company_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;0,9..144,700;1,9..144,300&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --ink:#0a0e1a; --ink-soft:#3d4460; --ink-mute:#7a8099;
  --bg:#f0f2f7; --card:#fff;
  --teal:#1a7a6e; --teal-lt:#22a090; --teal-pale:#d4efec;
  --gold:#c8973a; --gold-pale:rgba(200,151,58,.1);
  --red:#dc2626; --red-pale:#fef2f2;
  --green:#16a34a; --green-pale:#f0fdf4;
  --blue:#2563eb; --blue-pale:#eff6ff;
  --border:rgba(10,14,26,.08);
  --shadow:0 1px 4px rgba(10,14,26,.07);
  --shadow-md:0 4px 20px rgba(10,14,26,.10);
  --radius:14px;
  --ff-display:'Fraunces',Georgia,serif;
  --ff-body:'DM Sans',sans-serif;
  --sidebar-w:240px;
}
html{height:100%;}
body{font-family:var(--ff-body);background:var(--bg);color:var(--ink);min-height:100vh;display:flex;}

/* ── Sidebar ─────────────────────────────────────────────── */
.sidebar{width:var(--sidebar-w);background:var(--ink);min-height:100vh;position:fixed;top:0;left:0;bottom:0;display:flex;flex-direction:column;z-index:500;}
.sb-logo{padding:22px 20px 18px;display:flex;align-items:center;gap:10px;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.06);}
.sb-logo-mark{width:34px;height:34px;border-radius:9px;background:var(--teal);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sb-logo-mark svg{width:18px;height:18px;}
.sb-logo-name{font-family:var(--ff-display);font-size:17px;font-weight:700;color:#fff;letter-spacing:-.02em;}
.sb-logo-name em{color:#22a090;font-style:normal;}
.sb-co{padding:12px 20px;font-size:11px;font-weight:600;color:rgba(255,255,255,.35);letter-spacing:.04em;text-transform:uppercase;border-bottom:1px solid rgba(255,255,255,.06);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-section{padding:16px 12px 6px;}
.sb-lbl{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.22);padding:0 8px;margin-bottom:5px;}
.sb-nav{list-style:none;display:flex;flex-direction:column;gap:2px;}
.sb-nav a{display:flex;align-items:center;gap:9px;padding:9px 12px;border-radius:10px;font-size:13px;font-weight:500;color:rgba(255,255,255,.5);text-decoration:none;transition:all .15s;}
.sb-nav a:hover{background:rgba(255,255,255,.06);color:#fff;}
.sb-nav a.active{background:var(--teal);color:#fff;font-weight:600;}
.sb-nav a .ico{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
.sb-bottom{margin-top:auto;padding:14px 12px;border-top:1px solid rgba(255,255,255,.06);}
.sb-credit{background:rgba(255,255,255,.05);border-radius:10px;padding:12px 14px;margin-bottom:10px;}
.sb-credit-label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:6px;}
.sb-credit-bar{height:5px;background:rgba(255,255,255,.1);border-radius:10px;overflow:hidden;margin-bottom:5px;}
.sb-credit-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--teal),var(--teal-lt));transition:width .5s;}
.sb-credit-nums{display:flex;justify-content:space-between;font-size:10px;color:rgba(255,255,255,.35);}
.sb-user{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:10px;}
.sb-av{width:30px;height:30px;border-radius:8px;background:var(--teal);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;}
.sb-un{font-size:12px;font-weight:600;color:#fff;}
.sb-ur{font-size:10px;color:rgba(255,255,255,.35);}
.sb-logout{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;font-size:12px;color:rgba(255,255,255,.3);text-decoration:none;margin-top:4px;transition:all .15s;}
.sb-logout:hover{background:rgba(220,38,38,.15);color:#f87171;}

/* ── Main ─────────────────────────────────────────────────── */
.main{margin-left:var(--sidebar-w);flex:1;min-width:0;display:flex;flex-direction:column;}

/* ── Impersonate banner ──────────────────────────────────── */
.imp-bar{background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:8px 24px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:space-between;}
.imp-bar a{color:#fff;font-size:12px;padding:4px 12px;border:1.5px solid rgba(255,255,255,.5);border-radius:8px;text-decoration:none;margin-left:12px;}

/* ── Topbar ──────────────────────────────────────────────── */
.topbar{background:var(--card);border-bottom:1px solid var(--border);padding:0 24px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:400;}
.tb-title{font-family:var(--ff-display);font-size:17px;font-weight:700;color:var(--ink);letter-spacing:-.02em;}
.tb-right{display:flex;align-items:center;gap:10px;}
.btn-primary{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--teal);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:all .15s;}
.btn-primary:hover{background:var(--teal-lt);}
.btn-ghost{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:transparent;color:var(--ink-soft);border:1.5px solid var(--border);border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);transition:all .15s;text-decoration:none;}
.btn-ghost:hover{border-color:var(--ink);color:var(--ink);}

/* ── Page ────────────────────────────────────────────────── */
.page{padding:24px;flex:1;}

/* ── Stat cards ──────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:20px;}
.sc{background:var(--card);border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow);border:1px solid var(--border);transition:box-shadow .2s;position:relative;overflow:hidden;}
.sc:hover{box-shadow:var(--shadow-md);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.sc.t1::before{background:linear-gradient(90deg,var(--teal),var(--teal-lt));}
.sc.t2::before{background:linear-gradient(90deg,var(--gold),#e8b95a);}
.sc.t3::before{background:linear-gradient(90deg,var(--blue),#60a5fa);}
.sc.t4::before{background:linear-gradient(90deg,var(--green),#4ade80);}
.sc.t5::before{background:linear-gradient(90deg,#8b5cf6,#a78bfa);}
.sc.t6::before{background:linear-gradient(90deg,var(--red),#f87171);}
.sc-ico{font-size:18px;margin-bottom:8px;}
.sc-val{font-family:var(--ff-display);font-size:26px;font-weight:700;color:var(--ink);line-height:1;margin-bottom:3px;}
.sc-lbl{font-size:11px;color:var(--ink-mute);font-weight:500;}

/* ── Tabs ────────────────────────────────────────────────── */
.tabs-bar{display:flex;gap:4px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:4px;margin-bottom:20px;box-shadow:var(--shadow);}
.tab-btn{flex:1;padding:9px 14px;border:none;background:transparent;border-radius:9px;font-size:13px;font-weight:600;color:var(--ink-mute);cursor:pointer;font-family:var(--ff-body);transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px;}
.tab-btn:hover{color:var(--ink);background:rgba(10,14,26,.04);}
.tab-btn.active{background:var(--teal);color:#fff;box-shadow:0 2px 8px rgba(26,122,110,.3);}
.tab-badge{background:rgba(255,255,255,.25);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:100px;}
.tab-btn:not(.active) .tab-badge{background:rgba(10,14,26,.08);color:var(--ink-mute);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* ── Section header ──────────────────────────────────────── */
.sec-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.sec-title{font-size:14px;font-weight:700;color:var(--ink);}
.sec-sub{font-size:12px;color:var(--ink-mute);margin-top:2px;}

/* ── Campaign cards ──────────────────────────────────────── */
.campaign-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;}
.camp-card{background:var(--card);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);overflow:hidden;transition:all .2s;}
.camp-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.camp-card-top{padding:16px 18px 12px;display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
.camp-name{font-size:14px;font-weight:700;color:var(--ink);margin-bottom:3px;}
.camp-dir{font-size:11px;color:var(--ink-mute);}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:100px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;flex-shrink:0;}
.sp-running {background:#dcfce7;color:#15803d;}
.sp-paused  {background:#fef9c3;color:#854d0e;}
.sp-draft   {background:#f1f5f9;color:#64748b;}
.sp-completed{background:var(--teal-pale);color:var(--teal);}
.sp-dot{width:5px;height:5px;border-radius:50%;background:currentColor;}
.camp-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--border);}
.camp-stat{background:var(--card);padding:10px 12px;text-align:center;}
.camp-stat .cv{font-size:16px;font-weight:700;color:var(--ink);}
.camp-stat .cl{font-size:10px;color:var(--ink-mute);margin-top:2px;}
.camp-prog{padding:10px 18px;border-top:1px solid var(--border);}
.prog-bar{height:4px;background:rgba(10,14,26,.06);border-radius:10px;overflow:hidden;margin-bottom:4px;}
.prog-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--teal),var(--teal-lt));}
.prog-txt{font-size:10px;color:var(--ink-mute);display:flex;justify-content:space-between;}
.camp-footer{padding:10px 14px;border-top:1px solid var(--border);background:#fafbfc;display:flex;gap:6px;align-items:center;}
.act-btn{padding:5px 12px;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;border:1.5px solid;font-family:var(--ff-body);transition:all .15s;white-space:nowrap;}
.act-run {color:var(--teal);border-color:var(--teal-pale);background:var(--teal-pale);}
.act-run:hover{background:var(--teal);color:#fff;}
.act-pause{color:var(--gold);border-color:rgba(200,151,58,.2);background:var(--gold-pale);}
.act-pause:hover{background:var(--gold);color:#fff;}
.act-edit{color:var(--ink-mute);border-color:var(--border);background:#fff;}
.act-edit:hover{color:var(--ink);border-color:var(--ink);}
.act-leads{color:var(--blue);border-color:#dbeafe;background:var(--blue-pale);}
.act-leads:hover{background:var(--blue);color:#fff;}

/* ── Script cards ────────────────────────────────────────── */
.script-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;}
.script-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow);transition:all .2s;position:relative;}
.script-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.sc-dir-tag{display:inline-block;padding:2px 9px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;}
.sc-dir-tag.out{background:var(--teal-pale);color:var(--teal);}
.sc-dir-tag.in {background:var(--blue-pale);color:var(--blue);}
.sc-name{font-size:14px;font-weight:700;color:var(--ink);margin-bottom:4px;}
.sc-uc{font-size:12px;color:var(--ink-mute);margin-bottom:10px;}
.sc-preview{font-size:12px;color:var(--ink-soft);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:14px;}
.sc-footer{display:flex;gap:6px;}

/* ── Lead list ───────────────────────────────────────────── */
.leads-table{width:100%;border-collapse:collapse;background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);border:1px solid var(--border);}
.leads-table th{text-align:left;padding:10px 14px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-mute);background:#fafbfc;border-bottom:1px solid var(--border);}
.leads-table td{padding:11px 14px;font-size:13px;color:var(--ink-soft);border-bottom:1px solid var(--border);}
.leads-table tr:last-child td{border-bottom:none;}
.leads-table tr:hover td{background:#fafbfd;}

/* ── Call log ────────────────────────────────────────────── */
.call-feed{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.call-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);}
.call-item:last-child{border-bottom:none;}
.call-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.cdot-answered{background:var(--green);}
.cdot-no_answer{background:var(--ink-mute);}
.cdot-voicemail{background:var(--gold);}
.cdot-busy,.cdot-failed{background:var(--red);}
.call-info{flex:1;min-width:0;}
.call-name{font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.call-meta{font-size:11px;color:var(--ink-mute);margin-top:1px;}
.outcome-pill{font-size:10px;font-weight:700;padding:2px 8px;border-radius:100px;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;}
.op-appt{background:var(--teal-pale);color:var(--teal);}
.op-int{background:var(--green-pale);color:var(--green);}
.op-vm{background:var(--gold-pale);color:var(--gold);}
.op-na{background:#f1f5f9;color:var(--ink-mute);}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:50px 20px;background:var(--card);border:2px dashed var(--border);border-radius:var(--radius);}
.empty-icon{font-size:44px;margin-bottom:12px;}
.empty-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--ink-mute);margin-bottom:20px;}

/* ── Overview grid ───────────────────────────────────────── */
.overview-grid{display:grid;grid-template-columns:1fr 320px;gap:16px;}
.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.panel-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.panel-title{font-size:13px;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:7px;}
.panel-link{font-size:12px;color:var(--teal);font-weight:600;text-decoration:none;cursor:pointer;background:none;border:none;font-family:var(--ff-body);}

/* Usage card */
.usage-wrap{padding:16px 18px;}
.usage-label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-mute);margin-bottom:10px;display:flex;justify-content:space-between;}
.usage-bar{height:10px;background:rgba(10,14,26,.06);border-radius:10px;overflow:hidden;margin-bottom:6px;}
.usage-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--teal),var(--teal-lt));}
.usage-fill.warn{background:linear-gradient(90deg,var(--gold),#e8b95a);}
.usage-fill.danger{background:linear-gradient(90deg,var(--red),#f87171);}

/* Quick actions */
.qa-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:14px;}
.qa-btn{display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px;background:var(--bg);border:1.5px solid var(--border);border-radius:12px;cursor:pointer;transition:all .2s;font-family:var(--ff-body);}
.qa-btn:hover{border-color:var(--teal);background:var(--teal-pale);}
.qa-icon{font-size:22px;}
.qa-label{font-size:12px;font-weight:600;color:var(--ink);}

/* ── Modal ───────────────────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(10,14,26,.55);backdrop-filter:blur(4px);z-index:9000;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;}
.modal-overlay.open{display:flex;animation:fadeIn .2s ease;}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.modal{background:var(--card);border-radius:20px;padding:28px;width:100%;max-width:560px;margin:auto;box-shadow:0 24px 80px rgba(10,14,26,.2);animation:modalUp .25s ease;position:relative;}
.modal.wide{max-width:720px;}
@keyframes modalUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal-close{position:absolute;top:14px;right:14px;width:28px;height:28px;border-radius:7px;background:var(--bg);border:none;cursor:pointer;font-size:13px;color:var(--ink-mute);display:flex;align-items:center;justify-content:center;}
.modal-close:hover{background:var(--border);}
.modal-title{font-family:var(--ff-display);font-size:20px;font-weight:700;margin-bottom:4px;padding-right:30px;}
.modal-sub{font-size:13px;color:var(--ink-mute);margin-bottom:22px;}

/* modal form */
.fg{margin-bottom:14px;}
.fg:last-child{margin-bottom:0;}
.fg-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.fg-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.fg label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:5px;letter-spacing:.02em;}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 12px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:var(--ff-body);color:var(--ink);outline:none;transition:border-color .15s,box-shadow .15s;}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(26,122,110,.08);background:#fff;}
.fg textarea{resize:vertical;min-height:90px;line-height:1.5;}
.fg-hint{font-size:11px;color:var(--ink-mute);margin-top:4px;}
.fg-section{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-mute);padding-bottom:8px;border-bottom:1px solid var(--border);margin:16px 0 14px;}
.modal-alert{padding:10px 14px;border-radius:9px;font-size:13px;margin-bottom:14px;display:none;}
.modal-alert.err{background:var(--red-pale);color:var(--red);border:1px solid #fecaca;}
.modal-alert.ok{background:var(--green-pale);color:var(--green);border:1px solid #86efac;}
.modal-footer{display:flex;gap:10px;margin-top:18px;}
.btn-cancel{flex:1;padding:10px;border:1.5px solid var(--border);border-radius:10px;background:#fff;color:var(--ink-mute);font-size:13px;font-weight:600;cursor:pointer;font-family:var(--ff-body);}
.btn-save{flex:2;padding:10px;border:none;border-radius:10px;background:var(--teal);color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:var(--ff-body);transition:background .15s;}
.btn-save:hover{background:var(--teal-lt);}
.btn-save:disabled{opacity:.55;cursor:not-allowed;}

/* day checkboxes */
.day-check-group{display:flex;gap:6px;flex-wrap:wrap;}
.day-check{position:relative;}
.day-check input{position:absolute;opacity:0;}
.day-check label{display:flex;align-items:center;justify-content:center;width:38px;height:34px;border-radius:8px;border:1.5px solid var(--border);font-size:11px;font-weight:700;cursor:pointer;background:#fff;color:var(--ink-mute);transition:all .15s;user-select:none;}
.day-check input:checked + label{background:var(--teal);border-color:var(--teal);color:#fff;}

/* toggle switches */
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);}
.toggle-row:last-child{border-bottom:none;}
.toggle-label{font-size:13px;color:var(--ink-soft);}
.toggle{position:relative;width:36px;height:20px;flex-shrink:0;}
.toggle input{position:absolute;opacity:0;}
.toggle-slider{position:absolute;inset:0;background:rgba(10,14,26,.15);border-radius:100px;cursor:pointer;transition:.2s;}
.toggle-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
.toggle input:checked + .toggle-slider{background:var(--teal);}
.toggle input:checked + .toggle-slider::before{transform:translateX(16px);}

/* toast */
.toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:var(--ink);color:#fff;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap;}

/* upload zone */
.upload-zone{border:2px dashed var(--border);border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.dragover{border-color:var(--teal);background:var(--teal-pale);}
.upload-zone input{display:none;}
.upload-icon{font-size:32px;margin-bottom:8px;}
.upload-title{font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;}
.upload-sub{font-size:12px;color:var(--ink-mute);}

@media(max-width:1200px){.stats-row{grid-template-columns:repeat(3,1fr);}.overview-grid{grid-template-columns:1fr;}}
@media(max-width:768px){.sidebar{display:none;}.main{margin-left:0;}.stats-row{grid-template-columns:repeat(2,1fr);}.campaign-grid,.script-grid{grid-template-columns:1fr;}.fg-row,.fg-row-3{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<aside class="sidebar">
  <a href="dashboard.php" class="sb-logo">
    <div class="sb-logo-mark">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
        <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
      </svg>
    </div>
    <span class="sb-logo-name">Call<em>Mind</em> AI</span>
  </a>
  <div class="sb-co" title="<?= htmlspecialchars($cl['company_name']) ?>">
    <?= htmlspecialchars($cl['company_name']) ?>
  </div>

  <div class="sb-section">
    <div class="sb-lbl">My Account</div>
    <ul class="sb-nav">
      <li><a href="dashboard.php" class="active"><span class="ico">📊</span> Dashboard</a></li>
      <li><a href="active_campaigns.php"><span class="ico">🟢</span> Active Campaigns</a></li>
      <li><a href="#" onclick="switchTab('scripts');return false;"><span class="ico">📝</span> Scripts</a></li>
      <li><a href="#" onclick="switchTab('leads');return false;"><span class="ico">👥</span> Leads</a></li>
      <li><a href="#" onclick="switchTab('calls');return false;"><span class="ico">📞</span> Call Log</a></li>
    </ul>
  </div>

  <div class="sb-section">
    <div class="sb-lbl">Settings</div>
    <ul class="sb-nav">
      <li><a href="profile.php"><span class="ico">👤</span> Profile</a></li>
      <li><a href="billing.php"><span class="ico">💳</span> Billing</a></li>
    </ul>
  </div>

  <div class="sb-bottom">
    <!-- Minutes usage bar -->
    <div class="sb-credit">
      <div class="sb-credit-label">Minutes Used This Month</div>
      <div class="sb-credit-bar">
        <div class="sb-credit-fill" style="width:<?= $mins_pct ?>%"></div>
      </div>
      <div class="sb-credit-nums">
        <span><?= $mins_used ?> min used</span>
        <span><?= $mins_total == 9999 ? '∞' : $mins_total ?> included</span>
      </div>
    </div>
    <div class="sb-user">
      <div class="sb-av"><?= htmlspecialchars($initials) ?></div>
      <div>
        <div class="sb-un"><?= htmlspecialchars($fullname) ?></div>
        <div class="sb-ur"><?= ucfirst($plan) ?> plan</div>
      </div>
    </div>
    <a href="logout.php" class="sb-logout"><span>🚪</span> Log Out</a>
  </div>
</aside>

<!-- ══ MAIN ══════════════════════════════════════════════════ -->
<div class="main">

  <?php if ($is_imp): ?>
  <div class="imp-bar">
    <span>🔑 You are viewing as <strong><?= htmlspecialchars($imp_co) ?></strong> (impersonating)</span>
    <a href="admin/dashboard.php">← Back to Admin</a>
  </div>
  <?php endif; ?>

  <!-- Topbar -->
  <div class="topbar">
    <div class="tb-title">
      Good <?= date('H')<12?'morning':(date('H')<18?'afternoon':'evening') ?>, <?= htmlspecialchars($firstname) ?> 👋
    </div>
    <div class="tb-right">
      <button class="btn-ghost" onclick="openModal('leadsModal')">⬆ Upload Leads</button>
      <button class="btn-primary" onclick="openModal('campaignModal')">➕ New Campaign</button>
    </div>
  </div>

  <div class="page">

    <!-- ── Stats ─────────────────────────────────────────── -->
    <div class="stats-row">
      <div class="sc t1"><div class="sc-ico">📣</div><div class="sc-val"><?= $stat['active_campaigns'] ?></div><div class="sc-lbl">Active Campaigns</div></div>
      <div class="sc t2"><div class="sc-ico">📞</div><div class="sc-val"><?= $stat['calls_today'] ?></div><div class="sc-lbl">Calls Today</div></div>
      <div class="sc t3"><div class="sc-ico">⏱</div><div class="sc-val"><?= $stat['mins_today'] ?></div><div class="sc-lbl">Minutes Today</div></div>
      <div class="sc t4"><div class="sc-ico">📅</div><div class="sc-val"><?= $stat['appts_month'] ?></div><div class="sc-lbl">Appts This Month</div></div>
      <div class="sc t5"><div class="sc-ico">👥</div><div class="sc-val"><?= number_format($stat['total_leads']) ?></div><div class="sc-lbl">Total Leads</div></div>
      <div class="sc t6"><div class="sc-ico">📝</div><div class="sc-val"><?= $stat['total_scripts'] ?></div><div class="sc-lbl">Scripts</div></div>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────── -->
    <div class="tabs-bar" id="tabsBar">
      <button class="tab-btn active" onclick="switchTab('overview')"  id="tab-overview">📊 Overview</button>
      <button class="tab-btn" onclick="switchTab('campaigns')" id="tab-campaigns">📣 Campaigns <span class="tab-badge"><?= count($campaigns) ?></span></button>
      <button class="tab-btn" onclick="switchTab('scripts')"   id="tab-scripts">📝 Scripts <span class="tab-badge"><?= count($scripts) ?></span></button>
      <button class="tab-btn" onclick="switchTab('leads')"     id="tab-leads">👥 Leads</button>
      <button class="tab-btn" onclick="switchTab('calls')"     id="tab-calls">📞 Call Log</button>
    </div>

    <!-- ══ TAB: Overview ════════════════════════════════════ -->
    <div class="tab-content active" id="tc-overview">
      <div class="overview-grid">
        <div>
          <!-- Recent campaigns -->
          <div class="panel" style="margin-bottom:16px;">
            <div class="panel-head">
              <div class="panel-title">📣 Active Campaigns</div>
              <button class="panel-link" onclick="switchTab('campaigns')">View all →</button>
            </div>
            <?php if(empty($campaigns)): ?>
            <div style="padding:30px;text-align:center;color:var(--ink-mute);font-size:13px;">No campaigns yet — <button onclick="openModal('campaignModal')" style="color:var(--teal);background:none;border:none;cursor:pointer;font-weight:600;">create one</button></div>
            <?php else: ?>
            <?php foreach(array_slice($campaigns,0,3) as $c):
              $pct = $c['total_leads']>0 ? min(100,round($c['leads_called']/$c['total_leads']*100)) : 0;
            ?>
            <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:14px;">
              <div style="flex:1;">
                <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:2px;"><?= htmlspecialchars($c['name']) ?></div>
                <div style="font-size:11px;color:var(--ink-mute);"><?= $c['leads_called'] ?> / <?= $c['total_leads'] ?> leads called</div>
                <div class="prog-bar" style="margin-top:6px;"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
              </div>
              <span class="status-pill sp-<?= $c['status'] ?>"><span class="sp-dot"></span><?= ucfirst($c['status']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Recent calls -->
          <div class="call-feed">
            <div class="panel-head">
              <div class="panel-title">📞 Recent Calls</div>
              <button class="panel-link" onclick="switchTab('calls')">View all →</button>
            </div>
            <?php if(empty($recent_calls)): ?>
            <div style="padding:24px;text-align:center;color:var(--ink-mute);font-size:13px;">No calls yet</div>
            <?php else: ?>
            <?php foreach($recent_calls as $c): ?>
            <div class="call-item">
              <div class="call-dot cdot-<?= htmlspecialchars($c['disposition']??'no_answer') ?>"></div>
              <div class="call-info">
                <div class="call-name"><?= htmlspecialchars($c['lead_name']) ?></div>
                <div class="call-meta"><?= htmlspecialchars($c['lead_phone']) ?> · <?= timeAgo($c['initiated_at']) ?></div>
              </div>
              <?php if($c['talk_seconds']>0): ?>
                <span style="font-size:12px;font-weight:600;color:var(--ink-soft);"><?= fmtSecs($c['talk_seconds']) ?></span>
              <?php endif; ?>
              <?php if($c['outcome']==='appointment_set'): ?>
                <span class="outcome-pill op-appt">Appt</span>
              <?php elseif($c['outcome']==='interested'): ?>
                <span class="outcome-pill op-int">Hot</span>
              <?php elseif($c['disposition']==='voicemail'): ?>
                <span class="outcome-pill op-vm">VM</span>
              <?php else: ?>
                <span class="outcome-pill op-na">N/A</span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Right sidebar -->
        <div>
          <!-- Usage -->
          <div class="panel" style="margin-bottom:12px;">
            <div class="panel-head"><div class="panel-title">📊 Plan Usage</div></div>
            <div class="usage-wrap">
              <div class="usage-label">
                <span>Minutes</span><span><?= $mins_used ?> / <?= $mins_total==9999?'∞':$mins_total ?></span>
              </div>
              <div class="usage-bar">
                <div class="usage-fill <?= $mins_pct>90?'danger':($mins_pct>70?'warn':'') ?>" style="width:<?= $mins_pct ?>%"></div>
              </div>
              <div style="font-size:11px;color:var(--ink-mute);text-align:right;"><?= $mins_pct ?>% used</div>
              <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:13px;">
                <span style="color:var(--ink-mute);">Credit balance</span>
                <span style="font-weight:700;color:var(--green);">$<?= number_format((float)$cl['credit_balance'],2) ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:8px;">
                <span style="color:var(--ink-mute);">Plan</span>
                <span style="font-weight:700;color:var(--teal);"><?= ucfirst($plan) ?></span>
              </div>
            </div>
          </div>

          <!-- Quick actions -->
          <div class="panel" style="margin-bottom:12px;">
            <div class="panel-head"><div class="panel-title">⚡ Quick Actions</div></div>
            <div class="qa-grid">
              <button class="qa-btn" onclick="openModal('campaignModal')">
                <span class="qa-icon">📣</span><span class="qa-label">New Campaign</span>
              </button>
              <button class="qa-btn" onclick="openModal('scriptModal')">
                <span class="qa-icon">📝</span><span class="qa-label">Add Script</span>
              </button>
              <button class="qa-btn" onclick="openModal('leadsModal')">
                <span class="qa-icon">⬆</span><span class="qa-label">Upload Leads</span>
              </button>
              <button class="qa-btn" onclick="switchTab('calls')">
                <span class="qa-icon">📞</span><span class="qa-label">View Calls</span>
              </button>
            </div>
          </div>

          <!-- Recent leads -->
          <div class="panel">
            <div class="panel-head"><div class="panel-title">👥 Recent Leads</div><button class="panel-link" onclick="switchTab('leads')">View all →</button></div>
            <?php if(empty($recent_leads)): ?>
            <div style="padding:20px;text-align:center;color:var(--ink-mute);font-size:13px;">No leads uploaded yet</div>
            <?php else: ?>
            <?php foreach($recent_leads as $l): ?>
            <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
              <div>
                <div style="font-size:12px;font-weight:600;color:var(--ink);"><?= htmlspecialchars($l['firstname'].' '.$l['lastname']) ?></div>
                <div style="font-size:11px;color:var(--ink-mute);"><?= htmlspecialchars($l['phone']) ?></div>
              </div>
              <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:#f1f5f9;color:var(--ink-mute);"><?= ucfirst($l['status']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TAB: Campaigns ════════════════════════════════════ -->
    <div class="tab-content" id="tc-campaigns">
      <div class="sec-hdr">
        <div><div class="sec-title">All Campaigns</div><div class="sec-sub">Manage your calling campaigns, schedules, and limits</div></div>
        <button class="btn-primary" onclick="openModal('campaignModal')">➕ New Campaign</button>
      </div>
      <?php if(empty($campaigns)): ?>
      <div class="empty-state">
        <div class="empty-icon">📣</div>
        <div class="empty-title">No campaigns yet</div>
        <div class="empty-sub">Create your first campaign to start making AI calls</div>
        <button class="btn-primary" onclick="openModal('campaignModal')">➕ Create Campaign</button>
      </div>
      <?php else: ?>
      <div class="campaign-grid">
        <?php foreach($campaigns as $c):
          $pct = $c['total_leads']>0 ? min(100,round($c['leads_called']/$c['total_leads']*100)) : 0;
        ?>
        <div class="camp-card">
          <div class="camp-card-top">
            <div>
              <div class="camp-name"><?= htmlspecialchars($c['name']) ?></div>
              <div class="camp-dir"><?= ucfirst($c['direction']) ?> · <?= $c['calling_time_from'] ?> – <?= $c['calling_time_to'] ?></div>
            </div>
            <span class="status-pill sp-<?= $c['status'] ?>">
              <span class="sp-dot"></span><?= ucfirst($c['status']) ?>
            </span>
          </div>
          <div class="camp-stats">
            <div class="camp-stat"><div class="cv"><?= $c['leads_called'] ?></div><div class="cl">Called</div></div>
            <div class="camp-stat"><div class="cv"><?= $c['calls_answered'] ?></div><div class="cl">Answered</div></div>
            <div class="camp-stat"><div class="cv"><?= $c['appointments_set'] ?></div><div class="cl">Appts</div></div>
          </div>
          <div class="camp-prog">
            <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
            <div class="prog-txt"><span><?= $pct ?>% complete</span><span><?= $c['leads_called'] ?>/<?= $c['total_leads'] ?> leads</span></div>
          </div>
          <?php if($c['max_calls']||$c['max_minutes']||$c['budget_cap']): ?>
          <div style="padding:6px 18px;font-size:11px;color:var(--ink-mute);background:#fafbfc;border-top:1px solid var(--border);">
            Limits:
            <?= $c['max_calls']   ? $c['max_calls'].' calls' : '' ?>
            <?= $c['max_minutes'] ? ' · '.$c['max_minutes'].' mins' : '' ?>
            <?= $c['budget_cap']  ? ' · $'.$c['budget_cap'].' budget' : '' ?>
          </div>
          <?php endif; ?>
          <div class="camp-footer">
            <?php if($c['status']==='running'): ?>
              <button class="act-btn act-pause" onclick="toggleCamp(<?= $c['id'] ?>,'running',this)">⏸ Pause</button>
            <?php elseif($c['status']==='paused'): ?>
              <button class="act-btn act-run" onclick="toggleCamp(<?= $c['id'] ?>,'paused',this)">▶ Resume</button>
            <?php else: ?>
              <button class="act-btn act-run" onclick="toggleCamp(<?= $c['id'] ?>,'draft',this)">▶ Start</button>
            <?php endif; ?>
            <button class="act-btn act-edit" onclick="editCampaign(<?= $c['id'] ?>)">✏ Edit</button>
            <button class="act-btn act-leads" onclick="openLeads(<?= $c['id'] ?>,'<?= htmlspecialchars($c['name'],ENT_QUOTES) ?>')">⬆ Leads</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ TAB: Scripts ══════════════════════════════════════ -->
    <div class="tab-content" id="tc-scripts">
      <div class="sec-hdr">
        <div><div class="sec-title">Scripts & Rebuttals</div><div class="sec-sub">AI conversation scripts, opening lines, and objection handling</div></div>
        <button class="btn-primary" onclick="openModal('scriptModal')">➕ Add Script</button>
      </div>
      <?php if(empty($scripts)): ?>
      <div class="empty-state">
        <div class="empty-icon">📝</div>
        <div class="empty-title">No scripts yet</div>
        <div class="empty-sub">Add your first script to power your AI agents</div>
        <button class="btn-primary" onclick="openModal('scriptModal')">➕ Add Script</button>
      </div>
      <?php else: ?>
      <div class="script-grid">
        <?php foreach($scripts as $s): ?>
        <div class="script-card">
          <span class="sc-dir-tag <?= $s['direction']==='inbound'?'in':'out' ?>"><?= ucfirst($s['direction']) ?></span>
          <div class="sc-name"><?= htmlspecialchars($s['name']) ?></div>
          <div class="sc-uc"><?= htmlspecialchars($s['use_case'] ?: 'General') ?></div>
          <?php if($s['opening_line']): ?>
          <div class="sc-preview" style="font-style:italic;color:var(--teal);margin-bottom:8px;">"<?= htmlspecialchars($s['opening_line']) ?>"</div>
          <?php endif; ?>
          <div class="sc-preview"><?= htmlspecialchars($s['script_body']) ?></div>
          <div class="sc-footer">
            <button class="act-btn act-edit" onclick="editScript(<?= $s['id'] ?>)">✏ Edit</button>
            <button class="act-btn" style="color:var(--red);border-color:#fecaca;background:var(--red-pale);" onclick="deleteScript(<?= $s['id'] ?>, this)">🗑 Delete</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ TAB: Leads ════════════════════════════════════════ -->
    <div class="tab-content" id="tc-leads">
      <div class="sec-hdr">
        <div><div class="sec-title">Lead Database</div><div class="sec-sub">All contacts uploaded across your campaigns</div></div>
        <button class="btn-primary" onclick="openModal('leadsModal')">⬆ Upload Leads</button>
      </div>
      <?php if(empty($recent_leads)): ?>
      <div class="empty-state">
        <div class="empty-icon">👥</div>
        <div class="empty-title">No leads yet</div>
        <div class="empty-sub">Upload a CSV file with your contacts to get started</div>
        <button class="btn-primary" onclick="openModal('leadsModal')">⬆ Upload CSV</button>
      </div>
      <?php else: ?>
      <table class="leads-table">
        <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>City</th><th>Status</th><th>Campaign</th><th>Added</th></tr></thead>
        <tbody>
        <?php
        $all_leads = [];
        $q = mysqli_query($conn,"SELECT l.*, c.name as camp_name FROM cts_leads l LEFT JOIN cts_campaigns c ON c.id=l.campaign_id WHERE l.client_id=$client_id ORDER BY l.id DESC LIMIT 50");
        while($r=mysqli_fetch_assoc($q)) $all_leads[]=$r;
        foreach($all_leads as $l):
        ?>
        <tr>
          <td style="font-weight:600;color:var(--ink);"><?= htmlspecialchars($l['firstname'].' '.$l['lastname']) ?></td>
          <td><?= htmlspecialchars($l['phone']) ?></td>
          <td><?= htmlspecialchars($l['email']??'—') ?></td>
          <td><?= htmlspecialchars($l['city']??'—') ?></td>
          <td>
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;
              background:<?= $l['status']==='new'?'#f1f5f9':($l['status']==='called'?'var(--teal-pale)':'var(--green-pale)') ?>;
              color:<?= $l['status']==='new'?'#64748b':($l['status']==='called'?'var(--teal)':'var(--green)') ?>;">
              <?= ucfirst($l['status']) ?>
            </span>
          </td>
          <td><?= htmlspecialchars($l['camp_name']??'—') ?></td>
          <td style="color:var(--ink-mute);font-size:12px;"><?= timeAgo($l['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- ══ TAB: Call Log ════════════════════════════════════ -->
    <div class="tab-content" id="tc-calls">
      <div class="sec-hdr">
        <div><div class="sec-title">Call History</div><div class="sec-sub">Every call attempt with outcome, duration, and transcript</div></div>
      </div>
      <?php if(empty($recent_calls)): ?>
      <div class="empty-state">
        <div class="empty-icon">📞</div>
        <div class="empty-title">No calls yet</div>
        <div class="empty-sub">Call history will appear here once your campaigns start running</div>
      </div>
      <?php else: ?>
      <table class="leads-table">
        <thead><tr><th>Lead</th><th>Phone</th><th>Direction</th><th>Duration</th><th>Disposition</th><th>Outcome</th><th>Cost</th><th>Time</th></tr></thead>
        <tbody>
        <?php
        $all_calls=[];
        $q=mysqli_query($conn,"SELECT * FROM cts_call_log WHERE client_id=$client_id ORDER BY initiated_at DESC LIMIT 100");
        while($r=mysqli_fetch_assoc($q)) $all_calls[]=$r;
        foreach($all_calls as $c):
        $disp_color=['answered'=>'var(--green)','no_answer'=>'var(--ink-mute)','voicemail'=>'var(--gold)','busy'=>'var(--red)','failed'=>'var(--red)'];
        $dc=$disp_color[$c['disposition']??'']??'var(--ink-mute)';
        ?>
        <tr>
          <td style="font-weight:600;color:var(--ink);"><?= htmlspecialchars($c['lead_name']) ?></td>
          <td><?= htmlspecialchars($c['lead_phone']) ?></td>
          <td><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;background:#f1f5f9;color:#64748b;"><?= ucfirst($c['direction']) ?></span></td>
          <td><?= $c['talk_seconds']>0?fmtSecs($c['talk_seconds']):'—' ?></td>
          <td style="color:<?= $dc ?>;font-weight:600;font-size:12px;"><?= ucwords(str_replace('_',' ',$c['disposition']??'—')) ?></td>
          <td>
            <?php if($c['outcome']==='appointment_set'): ?>
              <span class="outcome-pill op-appt">Appt Set</span>
            <?php elseif($c['outcome']==='interested'): ?>
              <span class="outcome-pill op-int">Interested</span>
            <?php else: ?>
              <span style="font-size:12px;color:var(--ink-mute);"><?= $c['outcome'] ? ucwords(str_replace('_',' ',$c['outcome'])) : '—' ?></span>
            <?php endif; ?>
          </td>
          <td style="font-weight:600;color:var(--ink);">$<?= number_format($c['total_cost'],4) ?></td>
          <td style="color:var(--ink-mute);font-size:12px;"><?= timeAgo($c['initiated_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div><!-- /page -->
</div><!-- /main -->

<!-- ══ CAMPAIGN MODAL ════════════════════════════════════════ -->
<div class="modal-overlay" id="campaignModal" onclick="overlayClick(event,'campaignModal')">
<div class="modal wide">
  <button class="modal-close" onclick="closeModal('campaignModal')">✕</button>
  <div class="modal-title" id="campModalTitle">➕ New Campaign</div>
  <div class="modal-sub">Set up your AI calling campaign with schedule, limits, and retry rules</div>
  <div class="modal-alert err" id="campErr"></div>
  <input type="hidden" id="camp_id" value="">

  <div class="fg-section">Basic Info</div>
  <div class="fg-row">
    <div class="fg"><label>Campaign Name *</label><input type="text" id="camp_name" placeholder="e.g. March Expired Listings"></div>
    <div class="fg">
      <label>Direction</label>
      <select id="camp_dir">
        <option value="outbound">Outbound (we call leads)</option>
        <option value="inbound">Inbound (leads call us)</option>
      </select>
    </div>
  </div>
  <div class="fg">
    <label>AI Agent</label>
    <select id="camp_agent">
      <option value="0">— Select agent (optional) —</option>
      <?php foreach($agents as $a): ?>
      <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="fg-section">Schedule</div>
  <div class="fg-row">
    <div class="fg"><label>Start Date *</label><input type="date" id="camp_start" value="<?= date('Y-m-d') ?>"></div>
    <div class="fg"><label>End Date <span style="color:var(--ink-mute);font-weight:400;">(optional)</span></label><input type="date" id="camp_end"></div>
  </div>
  <div class="fg-row">
    <div class="fg"><label>Call From</label><input type="time" id="camp_tf" value="09:00"></div>
    <div class="fg"><label>Call Until</label><input type="time" id="camp_tt" value="17:00"></div>
  </div>
  <div class="fg">
    <label>Timezone</label>
    <select id="camp_tz">
      <option value="America/New_York">Eastern (ET)</option>
      <option value="America/Chicago" selected>Central (CT)</option>
      <option value="America/Denver">Mountain (MT)</option>
      <option value="America/Los_Angeles">Pacific (PT)</option>
      <option value="UTC">UTC</option>
    </select>
  </div>
  <div class="fg">
    <label>Calling Days</label>
    <div class="day-check-group">
      <?php foreach(['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'] as $v=>$l): ?>
      <div class="day-check">
        <input type="checkbox" id="day_<?=$v?>" name="days" value="<?=$v?>" <?= in_array($v,['mon','tue','wed','thu','fri'])?'checked':'' ?>>
        <label for="day_<?=$v?>"><?=$l?></label>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="fg-section">Limits <span style="font-weight:400;font-size:11px;color:var(--ink-mute);">(leave blank for unlimited)</span></div>
  <div class="fg-row-3">
    <div class="fg"><label>Max Calls</label><input type="number" id="camp_maxcalls" placeholder="e.g. 500" min="1"><div class="fg-hint">Stop after this many calls</div></div>
    <div class="fg"><label>Max Minutes</label><input type="number" id="camp_maxmins" placeholder="e.g. 200" min="1"><div class="fg-hint">Stop after this many minutes</div></div>
    <div class="fg"><label>Budget Cap ($)</label><input type="number" id="camp_budget" placeholder="e.g. 50.00" min="0" step="0.01"><div class="fg-hint">Stop when cost reaches this</div></div>
  </div>

  <div class="fg-section">Retry Settings</div>
  <div class="fg-row">
    <div class="fg"><label>Max Retry Attempts</label><input type="number" id="camp_maxret" value="2" min="0" max="10"></div>
    <div class="fg"><label>Retry Interval (hours)</label><input type="number" id="camp_rethrs" value="4" min="1" max="72"></div>
  </div>
  <div style="background:var(--bg);border-radius:10px;padding:12px 14px;">
    <div class="toggle-row">
      <span class="toggle-label">Retry on No Answer</span>
      <label class="toggle"><input type="checkbox" id="camp_retna" checked><span class="toggle-slider"></span></label>
    </div>
    <div class="toggle-row">
      <span class="toggle-label">Retry after Voicemail</span>
      <label class="toggle"><input type="checkbox" id="camp_retvm" checked><span class="toggle-slider"></span></label>
    </div>
  </div>

  <div class="modal-footer">
    <button class="btn-cancel" onclick="closeModal('campaignModal')">Cancel</button>
    <button class="btn-save" id="campSaveBtn" onclick="saveCampaign()">💾 Save Campaign</button>
  </div>
</div>
</div>

<!-- ══ SCRIPT MODAL ══════════════════════════════════════════ -->
<div class="modal-overlay" id="scriptModal" onclick="overlayClick(event,'scriptModal')">
<div class="modal wide">
  <button class="modal-close" onclick="closeModal('scriptModal')">✕</button>
  <div class="modal-title" id="scriptModalTitle">📝 Add Script</div>
  <div class="modal-sub">Write your AI agent's conversation script, opening line, and objection rebuttals</div>
  <div class="modal-alert err" id="scriptErr"></div>
  <input type="hidden" id="script_id" value="">

  <div class="fg-row">
    <div class="fg"><label>Script Name *</label><input type="text" id="sc_name" placeholder="e.g. Expired Listing Outbound"></div>
    <div class="fg">
      <label>Direction</label>
      <select id="sc_dir">
        <option value="outbound">Outbound</option>
        <option value="inbound">Inbound</option>
      </select>
    </div>
  </div>
  <div class="fg">
    <label>Use Case / Purpose</label>
    <input type="text" id="sc_uc" placeholder="e.g. Expired listing follow-up, Annual review booking">
  </div>
  <div class="fg">
    <label>Opening Line <span style="font-size:11px;color:var(--ink-mute);font-weight:400;">— the first thing the agent says</span></label>
    <input type="text" id="sc_opening" placeholder='e.g. "Hi, this is Sarah calling from ABC Realty about your property at 123 Main..."'>
    <div class="fg-hint">Keep it natural. Introduce the agent name and purpose in the first sentence.</div>
  </div>
  <div class="fg">
    <label>Main Script Body *</label>
    <textarea id="sc_body" style="min-height:140px;" placeholder="Write the full conversation flow here. Use variables like {lead_name}, {agent_name}, {company_name}. Describe what the agent should say at each stage of the conversation..."></textarea>
  </div>
  <div class="fg">
    <label>Objections & Rebuttals <span style="font-size:11px;color:var(--ink-mute);font-weight:400;">— how to handle common pushback</span></label>
    <textarea id="sc_obj" style="min-height:100px;" placeholder="Objection: I already have an agent.&#10;Rebuttal: That's great! I'm not here to replace anyone — I just wanted to share some market data...&#10;&#10;Objection: Now's not a good time.&#10;Rebuttal: Completely understand. When would be a better time for a 2-minute call?"></textarea>
    <div class="fg-hint">List objections one per block with their rebuttals. The AI uses these to handle resistance naturally.</div>
  </div>

  <div class="modal-footer">
    <button class="btn-cancel" onclick="closeModal('scriptModal')">Cancel</button>
    <button class="btn-save" id="scriptSaveBtn" onclick="saveScript()">💾 Save Script</button>
  </div>
</div>
</div>

<!-- ══ LEADS UPLOAD MODAL ════════════════════════════════════ -->
<div class="modal-overlay" id="leadsModal" onclick="overlayClick(event,'leadsModal')">
<div class="modal">
  <button class="modal-close" onclick="closeModal('leadsModal')">✕</button>
  <div class="modal-title">⬆ Upload Leads</div>
  <div class="modal-sub">Upload a CSV file with your contact list. Duplicates are detected automatically.</div>
  <div class="modal-alert err" id="leadsErr"></div>
  <div class="modal-alert ok"  id="leadsOk"></div>

  <div class="fg">
    <label>Assign to Campaign</label>
    <select id="leads_campaign">
      <option value="0">— No campaign (general pool) —</option>
      <?php foreach($campaigns as $c): ?>
      <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="upload-zone" id="uploadZone" onclick="document.getElementById('csvFile').click()">
    <input type="file" id="csvFile" accept=".csv" onchange="handleFileSelect(this)">
    <div class="upload-icon">📄</div>
    <div class="upload-title" id="uploadTitle">Click to select CSV file</div>
    <div class="upload-sub">or drag & drop · CSV format only</div>
  </div>

  <div style="margin-top:14px;background:var(--bg);border-radius:10px;padding:12px 14px;">
    <div style="font-size:12px;font-weight:700;color:var(--ink-mute);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Required CSV columns</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach(['firstname','lastname','phone'] as $col): ?>
      <span style="background:var(--teal-pale);color:var(--teal);padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;"><?= $col ?></span>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:8px;font-size:11px;color:var(--ink-mute);">Optional: email, city, state, zip, address, lead_type</div>
  </div>

  <div class="modal-footer">
    <button class="btn-cancel" onclick="closeModal('leadsModal')">Cancel</button>
    <button class="btn-save" id="leadsSaveBtn" onclick="uploadLeads()" disabled>⬆ Upload</button>
  </div>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Tab switching ─────────────────────────────────────────────
function switchTab(name) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.getElementById('tc-' + name).classList.add('active');
  // Sync sidebar active
  document.querySelectorAll('.sb-nav a').forEach(a => a.classList.remove('active'));
}

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function overlayClick(e, id) { if (e.target === document.getElementById(id)) closeModal(id); }
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open').forEach(m=>{ m.classList.remove('open'); document.body.style.overflow=''; }); });

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, dur=2500) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.opacity = '1';
  setTimeout(()=>t.style.opacity='0', dur);
}

// ── Fetch helper ──────────────────────────────────────────────
async function post(data) {
  const fd = new FormData();
  for (const [k,v] of Object.entries(data)) fd.append(k,v);
  const r = await fetch(location.href, {method:'POST',body:fd});
  return r.json();
}

// ── Save campaign ─────────────────────────────────────────────
async function saveCampaign() {
  const btn = document.getElementById('campSaveBtn');
  const err = document.getElementById('campErr');
  err.style.display='none';
  const name = document.getElementById('camp_name').value.trim();
  if(!name) { err.textContent='Campaign name is required.'; err.style.display='block'; return; }
  const days = [...document.querySelectorAll('input[name="days"]:checked')].map(c=>c.value).join(',');
  btn.disabled=true; btn.textContent='Saving…';
  try {
    const d = await post({
      ajax_action:'save_campaign',
      campaign_id: document.getElementById('camp_id').value,
      name, direction: document.getElementById('camp_dir').value,
      agent_id: document.getElementById('camp_agent').value,
      start_date: document.getElementById('camp_start').value,
      end_date:   document.getElementById('camp_end').value,
      time_from:  document.getElementById('camp_tf').value,
      time_to:    document.getElementById('camp_tt').value,
      timezone:   document.getElementById('camp_tz').value,
      calling_days: days,
      max_calls:    document.getElementById('camp_maxcalls').value,
      max_minutes:  document.getElementById('camp_maxmins').value,
      budget_cap:   document.getElementById('camp_budget').value,
      max_retries:  document.getElementById('camp_maxret').value,
      retry_hours:  document.getElementById('camp_rethrs').value,
      retry_no_answer: document.getElementById('camp_retna').checked ? '1':'',
      retry_voicemail: document.getElementById('camp_retvm').checked ? '1':'',
    });
    if(d.success) { showToast('✅ Campaign saved!'); closeModal('campaignModal'); setTimeout(()=>location.reload(),800); }
    else { err.textContent=d.error||'Save failed'; err.style.display='block'; }
  } catch(e){ err.textContent='Network error'; err.style.display='block'; }
  btn.disabled=false; btn.textContent='💾 Save Campaign';
}

// ── Edit campaign ─────────────────────────────────────────────
async function editCampaign(id) {
  const d = await post({ajax_action:'get_campaign', campaign_id:id});
  if(!d.success) return;
  const c = d.campaign;
  document.getElementById('campModalTitle').textContent = '✏ Edit Campaign';
  document.getElementById('camp_id').value    = c.id;
  document.getElementById('camp_name').value  = c.name;
  document.getElementById('camp_dir').value   = c.direction;
  document.getElementById('camp_agent').value = c.agent_id||'0';
  document.getElementById('camp_start').value = c.start_date||'';
  document.getElementById('camp_end').value   = c.end_date||'';
  document.getElementById('camp_tf').value    = c.calling_time_from||'09:00';
  document.getElementById('camp_tt').value    = c.calling_time_to||'17:00';
  document.getElementById('camp_tz').value    = c.timezone||'UTC';
  document.getElementById('camp_maxcalls').value = c.max_calls||'';
  document.getElementById('camp_maxmins').value  = c.max_minutes||'';
  document.getElementById('camp_budget').value   = c.budget_cap||'';
  document.getElementById('camp_maxret').value   = c.max_retries||2;
  document.getElementById('camp_rethrs').value   = c.retry_interval_hrs||4;
  document.getElementById('camp_retna').checked  = c.retry_no_answer=='1';
  document.getElementById('camp_retvm').checked  = c.retry_voicemail=='1';
  // Set days
  const activeDays = (c.calling_days||'').split(',');
  document.querySelectorAll('input[name="days"]').forEach(cb=>{
    cb.checked = activeDays.includes(cb.value);
  });
  openModal('campaignModal');
}

// ── Toggle campaign run/pause ─────────────────────────────────
async function toggleCamp(id, curStatus, btn) {
  const d = await post({ajax_action:'toggle_campaign',campaign_id:id,current_status:curStatus});
  if(d.success) { showToast(d.new_status==='running'?'▶ Campaign started':'⏸ Campaign paused'); setTimeout(()=>location.reload(),600); }
  else showToast('⚠ Error');
}

// ── Open leads modal pre-selected to campaign ─────────────────
function openLeads(campId, campName) {
  document.getElementById('leads_campaign').value = campId;
  openModal('leadsModal');
}

// ── Save script ───────────────────────────────────────────────
async function saveScript() {
  const btn = document.getElementById('scriptSaveBtn');
  const err = document.getElementById('scriptErr');
  err.style.display='none';
  const name = document.getElementById('sc_name').value.trim();
  const body = document.getElementById('sc_body').value.trim();
  if(!name||!body){ err.textContent='Name and script body are required.'; err.style.display='block'; return; }
  btn.disabled=true; btn.textContent='Saving…';
  try {
    const d = await post({
      ajax_action:'save_script',
      script_id: document.getElementById('script_id').value,
      name, direction: document.getElementById('sc_dir').value,
      use_case:    document.getElementById('sc_uc').value,
      opening_line:document.getElementById('sc_opening').value,
      script_body: body,
      objections:  document.getElementById('sc_obj').value,
    });
    if(d.success) { showToast('✅ Script saved!'); closeModal('scriptModal'); setTimeout(()=>location.reload(),800); }
    else { err.textContent=d.error||'Save failed'; err.style.display='block'; }
  } catch(e){ err.textContent='Network error'; err.style.display='block'; }
  btn.disabled=false; btn.textContent='💾 Save Script';
}

// ── Edit script ───────────────────────────────────────────────
async function editScript(id) {
  const d = await post({ajax_action:'get_script',script_id:id});
  if(!d.success) return;
  const s = d.script;
  document.getElementById('scriptModalTitle').textContent='✏ Edit Script';
  document.getElementById('script_id').value      = s.id;
  document.getElementById('sc_name').value        = s.name;
  document.getElementById('sc_dir').value         = s.direction;
  document.getElementById('sc_uc').value          = s.use_case||'';
  document.getElementById('sc_opening').value     = s.opening_line||'';
  document.getElementById('sc_body').value        = s.script_body||'';
  document.getElementById('sc_obj').value         = s.objections||'';
  openModal('scriptModal');
}

// ── Delete script ─────────────────────────────────────────────
async function deleteScript(id, btn) {
  if(!confirm('Delete this script? This cannot be undone.')) return;
  const d = await post({ajax_action:'delete_script',script_id:id});
  if(d.success) { btn.closest('.script-card').remove(); showToast('🗑 Script deleted'); }
  else showToast('⚠ Delete failed');
}

// ── File upload ───────────────────────────────────────────────
let selectedFile = null;
function handleFileSelect(input) {
  selectedFile = input.files[0];
  if(selectedFile) {
    document.getElementById('uploadTitle').textContent = selectedFile.name;
    document.getElementById('uploadZone').style.borderColor = 'var(--teal)';
    document.getElementById('leadsSaveBtn').disabled = false;
  }
}

// Drag & drop
const zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', e=>{ e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave',()=>zone.classList.remove('dragover'));
zone.addEventListener('drop', e=>{
  e.preventDefault(); zone.classList.remove('dragover');
  const f = e.dataTransfer.files[0];
  if(f && f.name.endsWith('.csv')) {
    selectedFile = f;
    document.getElementById('uploadTitle').textContent = f.name;
    document.getElementById('leadsSaveBtn').disabled = false;
  } else { showToast('⚠ Please drop a CSV file'); }
});

async function uploadLeads() {
  if(!selectedFile) return;
  const btn = document.getElementById('leadsSaveBtn');
  const err = document.getElementById('leadsErr');
  const ok  = document.getElementById('leadsOk');
  err.style.display='none'; ok.style.display='none';
  btn.disabled=true; btn.textContent='Uploading…';
  const fd = new FormData();
  fd.append('ajax_action','upload_leads');
  fd.append('campaign_id', document.getElementById('leads_campaign').value);
  fd.append('csv_file', selectedFile);
  try {
    const r = await fetch(location.href,{method:'POST',body:fd});
    const d = await r.json();
    if(d.success) {
      ok.textContent=`✓ ${d.inserted} leads imported · ${d.duplicates} duplicates skipped · ${d.invalid} invalid`;
      ok.style.display='block';
      showToast(`✅ ${d.inserted} leads uploaded!`);
      setTimeout(()=>location.reload(),1800);
    } else { err.textContent=d.error||'Upload failed'; err.style.display='block'; }
  } catch(e){ err.textContent='Network error'; err.style.display='block'; }
  btn.disabled=false; btn.textContent='⬆ Upload';
}

// ── Reset modals on open ──────────────────────────────────────
document.getElementById('campaignModal').addEventListener('click', ()=>{});
// Reset campaign modal when clicking "New Campaign"
function resetCampaignModal() {
  document.getElementById('campModalTitle').textContent = '➕ New Campaign';
  document.getElementById('camp_id').value = '';
  document.getElementById('camp_name').value = '';
  document.getElementById('camp_dir').value = 'outbound';
  document.getElementById('camp_start').value = '<?= date('Y-m-d') ?>';
  document.getElementById('camp_end').value = '';
  document.getElementById('camp_maxcalls').value='';
  document.getElementById('camp_maxmins').value='';
  document.getElementById('camp_budget').value='';
  document.getElementById('campErr').style.display='none';
}
document.querySelector('[onclick="openModal(\'campaignModal\')"]')?.addEventListener('click', resetCampaignModal);
</script>
</body>
</html>
