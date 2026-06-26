<?php
// ============================================
// VideoVizard — Combined Login + Register Page
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Single include handles ALL session config site-wide
require_once 'session_config.php';

// Sanitize the ?redirect= param — only allow same-site relative paths
function sanitize_redirect(string $raw): string {
    if (preg_match('/^\/[^\/]/', $raw) || $raw === '/') {
        return $raw;
    }
    return '';
}
$redirect_to = sanitize_redirect($_GET['redirect'] ?? '');

if (isset($_SESSION['user']) || isset($_SESSION['admin_id'])) {
    $forward_url = $redirect_to ?: ($_SESSION['forward_url'] ?: 'vizard_browser.php');
    header("Location: $forward_url");
    exit;
}

function log_to_file($message) {
    $log_file = __DIR__ . '/a_error_logs.txt';
    file_put_contents($log_file, "[" . date("Y-m-d H:i:s") . "] $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

include 'dbconnect_hdb.php';
if ($conn->connect_error) {
    die("Database connection failed.");
}
$conn->set_charset("utf8mb4");

$register_errors = [];
$login_errors    = [];
$active_tab      = 'login';

// ── REGISTRATION ─────────────────────────────────────────────────────────────
if (isset($_POST['register_submit'])) {
    $active_tab = 'register';
    $first_name = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
    $last_name  = mysqli_real_escape_string($conn, trim($_POST['last_name']  ?? ''));
    $email      = mysqli_real_escape_string($conn, trim($_POST['email']      ?? ''));
    $password   = $_POST['password'] ?? '';
    $plan       = 'free_trial';

    if (empty($first_name) || empty($email) || strlen($password) < 6) {
        $register_errors[] = "Please fill all fields correctly (password min 6 chars).";
    }

    if (empty($register_errors)) {
        $check_res = $conn->query("SELECT id FROM hdb_users WHERE email_id = '$email' LIMIT 1");
        if ($check_res && $check_res->num_rows > 0) {
            $register_errors[] = "This email is already registered.";
        } else {
            $conn->query("START TRANSACTION");
            try {
                $hashed   = $password;
                $trial_expiry = date('Y-m-d H:i:s', strtotime('+14 days'));
                $sql_user = "INSERT INTO hdb_users
                             (user_name, firstname, lastname, level_name, email_id, password,
                              plan_type, role, credit_balance, client_id, phone_number, country,
                              schedule_flag, max_videos_allowed, trial_period_expiry_dt,
                              team_lead_id, last_company_id, game_tokens, credits_used,
                              video_count, created_at, updated_at)
                             VALUES
                             ('$email', '$first_name', '$last_name', 'user', '$email', '$hashed',
                              '$plan', 'Team Lead', 30, 0, '', '',
                              0, 10, '$trial_expiry',
                              0, 0, 0, 0,
                              0, NOW(), NOW())";
                if (!$conn->query($sql_user)) throw new Exception("User Insert Error: " . $conn->error);
                $user_id = $conn->insert_id;

                $company_name = mysqli_real_escape_string($conn, $first_name . "'s Studio");
                $sql_comp = "INSERT INTO hdb_companies (companyname, admin_id, company_type, created_at) VALUES ('$company_name', $user_id, 'internal', NOW())";
                if (!$conn->query($sql_comp)) throw new Exception("Company Insert Error: " . $conn->error);
                $company_id = $conn->insert_id;

                // Load template defaults
                $fontfamily='Arial,sans-serif'; $fontsize=28; $fontcolor='#ffffff';
                $fontweight='bold'; $fontcolor_bg='#000000'; $fontbg_enable=0;
                $caption_style='none'; $caption_position='bottom'; $caption_alignment='center';
                $caption_speed=1; $logo_name=''; $logo_size='60'; $logo_position='top-right';
                $logo_enabled=0; $position_x=50; $position_y=250; $width=500; $last_niche_id=0;

                $tpl = $conn->query("SELECT * FROM hdb_user_settings WHERE admin_id=1 AND company_id=1 AND text_type='caption' LIMIT 1");
                if ($tpl && $t = $tpl->fetch_assoc()) {
                    $fontfamily=$t['fontfamily']??'Arial'; $fontsize=(int)($t['fontsize']??28);
                    $fontcolor=$t['fontcolor']??'#ffffff'; $fontweight=$t['fontweight']??'bold';
                    $fontcolor_bg=$t['fontcolor_bg']??'#000000'; $fontbg_enable=(int)($t['fontbg_enable']??0);
                    $caption_style=$t['caption_style']??'none'; $caption_position=$t['caption_position']??'bottom';
                    $caption_alignment=$t['caption_alignment']??'center'; $caption_speed=(int)($t['caption_speed']??1);
                    $logo_name=$t['logo_name']??''; $logo_size=$t['logo_size']??'60';
                    $logo_position=$t['logo_position']??'top-right'; $logo_enabled=(int)($t['logo_enabled']??0);
                    $position_x=(int)($t['position_x']??50); $position_y=(int)($t['position_y']??250); $width=(int)($t['width']??500);
                }

                $text_types = [
                    'caption' => ['is_enabled'=>1,'fontfamily'=>$fontfamily,'fontsize'=>$fontsize,'fontcolor'=>$fontcolor,'fontweight'=>$fontweight,'fontcolor_bg'=>$fontcolor_bg,'fontbg_enable'=>$fontbg_enable,'font_italic'=>0,'font_underline'=>0,'caption_style'=>$caption_style,'caption_position'=>$caption_position,'caption_alignment'=>$caption_alignment,'caption_speed'=>$caption_speed,'position_x'=>$position_x,'position_y'=>$position_y,'width'=>$width,'text_effect'=>'none','text_animation'=>'static','display_mode'=>'full','animation_speed'=>'medium','stroke_color'=>'#000000','stroke_width'=>0,'gradient_color'=>'#ff6600','shadow_color'=>'#000000','text_align_v'=>'bottom','logo_name'=>$logo_name,'logo_size'=>$logo_size,'logo_position'=>$logo_position,'logo_enabled'=>$logo_enabled,'logo_file'=>'','logo_pos_h'=>'right','logo_pos_v'=>'top','logo_size_pct'=>15,'header_text'=>'','footer_text'=>''],
                    'header'  => ['is_enabled'=>0,'fontfamily'=>'Helvetica','fontsize'=>16,'fontcolor'=>'#ffffff','fontweight'=>'bold','fontcolor_bg'=>'#1a1a2e','fontbg_enable'=>1,'font_italic'=>0,'font_underline'=>0,'caption_style'=>'box','caption_position'=>'top','caption_alignment'=>'center','caption_speed'=>1,'position_x'=>0,'position_y'=>0,'width'=>1080,'text_effect'=>'none','text_animation'=>'fade_in','display_mode'=>'full','animation_speed'=>'medium','stroke_color'=>'#000000','stroke_width'=>0,'gradient_color'=>'#ff6600','shadow_color'=>'#000000','text_align_v'=>'top','logo_name'=>'','logo_size'=>'60','logo_position'=>'top-right','logo_enabled'=>0,'logo_file'=>'','logo_pos_h'=>'right','logo_pos_v'=>'top','logo_size_pct'=>15,'header_text'=>$first_name."'s Studio",'footer_text'=>''],
                    'footer'  => ['is_enabled'=>0,'fontfamily'=>'Georgia','fontsize'=>12,'fontcolor'=>'#aaaaaa','fontweight'=>'normal','fontcolor_bg'=>'#000000','fontbg_enable'=>0,'font_italic'=>0,'font_underline'=>0,'caption_style'=>'none','caption_position'=>'bottom','caption_alignment'=>'center','caption_speed'=>1,'position_x'=>0,'position_y'=>0,'width'=>1080,'text_effect'=>'none','text_animation'=>'static','display_mode'=>'full','animation_speed'=>'slow','stroke_color'=>'#000000','stroke_width'=>0,'gradient_color'=>'#ff6600','shadow_color'=>'#000000','text_align_v'=>'bottom','logo_name'=>'','logo_size'=>'60','logo_position'=>'top-right','logo_enabled'=>0,'logo_file'=>'','logo_pos_h'=>'right','logo_pos_v'=>'top','logo_size_pct'=>15,'header_text'=>'','footer_text'=>'Follow for more tips'],
                ];

                foreach ($text_types as $ttype => $ts) {
                    $te=mysqli_real_escape_string($conn,$ttype); $ff=mysqli_real_escape_string($conn,$ts['fontfamily']);
                    $fc=mysqli_real_escape_string($conn,$ts['fontcolor']); $fw=mysqli_real_escape_string($conn,$ts['fontweight']);
                    $fbg=mysqli_real_escape_string($conn,$ts['fontcolor_bg']); $cs=mysqli_real_escape_string($conn,$ts['caption_style']);
                    $cp=mysqli_real_escape_string($conn,$ts['caption_position']); $ca=mysqli_real_escape_string($conn,$ts['caption_alignment']);
                    $eff=mysqli_real_escape_string($conn,$ts['text_effect']); $tan=mysqli_real_escape_string($conn,$ts['text_animation']);
                    $dm=mysqli_real_escape_string($conn,$ts['display_mode']); $asp=mysqli_real_escape_string($conn,$ts['animation_speed']);
                    $sc=mysqli_real_escape_string($conn,$ts['stroke_color']); $gc=mysqli_real_escape_string($conn,$ts['gradient_color']);
                    $shc=mysqli_real_escape_string($conn,$ts['shadow_color']); $tav=mysqli_real_escape_string($conn,$ts['text_align_v']);
                    $ln=mysqli_real_escape_string($conn,$ts['logo_name']); $ls=mysqli_real_escape_string($conn,$ts['logo_size']);
                    $lp=mysqli_real_escape_string($conn,$ts['logo_position']); $lf=mysqli_real_escape_string($conn,$ts['logo_file']);
                    $lph=mysqli_real_escape_string($conn,$ts['logo_pos_h']); $lpv=mysqli_real_escape_string($conn,$ts['logo_pos_v']);
                    $ht=mysqli_real_escape_string($conn,$ts['header_text']); $ft=mysqli_real_escape_string($conn,$ts['footer_text']);
                    $fs=(int)$ts['fontsize'];
                    $sql_s = "INSERT INTO hdb_user_settings (admin_id,company_id,text_type,is_enabled,fontfamily,fontsize,fontcolor,fontweight,fontcolor_bg,fontbg_enable,font_italic,font_underline,caption_style,caption_position,caption_alignment,caption_speed,position_x,position_y,width,last_niche_id,text_effect,text_animation,display_mode,animation_speed,stroke_color,stroke_width,gradient_color,shadow_color,text_align_v,logo_name,logo_size,logo_position,logo_enabled,logo_file,logo_pos_h,logo_pos_v,logo_size_pct,created_at) VALUES ($user_id,$company_id,'$te',{$ts['is_enabled']},'$ff',$fs,'$fc','$fw','$fbg',{$ts['fontbg_enable']},{$ts['font_italic']},{$ts['font_underline']},'$cs','$cp','$ca',{$ts['caption_speed']},{$ts['position_x']},{$ts['position_y']},{$ts['width']},$last_niche_id,'$eff','$tan','$dm','$asp','$sc',{$ts['stroke_width']},'$gc','$shc','$tav','$ln','$ls','$lp',{$ts['logo_enabled']},'$lf','$lph','$lpv',{$ts['logo_size_pct']},NOW())";
                    if (!$conn->query($sql_s)) throw new Exception("Settings Insert Error ($ttype): " . $conn->error);
                }

                $conn->query("UPDATE hdb_users SET company_id=$company_id, admin_id=$user_id WHERE id=$user_id");
                $conn->query("COMMIT");
                log_to_file("SUCCESS: User $user_id registered.");
                $active_tab = 'login';
                $login_errors = [];
                $register_errors = [];
                $reg_success = "Account created! Please sign in.";

            } catch (Exception $e) {
                $conn->query("ROLLBACK");
                log_to_file("FAILURE: " . $e->getMessage());
                $register_errors[] = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if (isset($_POST['login_submit'])) {
    $active_tab = 'login';
    $user = mysqli_real_escape_string($conn, $_POST['user_name'] ?? '');
    $pass = $_POST['password'] ?? '';

    $sql    = "SELECT * FROM hdb_users WHERE email_id = '$user' AND password = '$pass' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) === 1) {

        $row = mysqli_fetch_assoc($result);
        session_regenerate_id(true);

        $post_redirect = sanitize_redirect($_POST['redirect'] ?? '');
        if ($post_redirect) {
            $forward_url = $post_redirect;
        } elseif (empty($row['forward_url'])) {
            $forward_url = "vizard_browser.php";
        } else {
            $forward_url = $row['forward_url'];
        }

        $admin_id = (int)$row['id'];
        $comp_res = mysqli_query($conn, "SELECT id FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
        $comp_row = $comp_res ? mysqli_fetch_assoc($comp_res) : null;
        $company_id = $comp_row ? (int)$comp_row['id'] : 0;

        $_SESSION['admin_id']      = $admin_id;
        $_SESSION['company_id']    = $company_id;
        $_SESSION['level']         = $row['level_name'];
        $_SESSION['client_id']     = $row['client_id'];
        $_SESSION['user']          = $row['user_name'];
        $_SESSION['forward_url']   = $row['forward_url'];
        $_SESSION['created_at']    = time();
        $_SESSION['last_activity'] = time();

        // Set persistent cookie — 1 year
        setcookie(
            session_name(),
            session_id(),
            [
                'expires'  => time() + SESSION_LIFETIME,
                'path'     => '/',
                'domain'   => '',
                'secure'   => COOKIE_SECURE,   // match the request scheme
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );

        header("Location: $forward_url");
        exit();

    } else {
        $login_errors[] = "Invalid username or password. Please try again.";
    }
}

$google_client_id    = "1043391327555-oubkn2aku04gnn2mtjfam05mvll63b82.apps.googleusercontent.com";
$google_redirect_url = "https://videovizard.com/google_callback.php";
$facebook_app_id     = "952268383945804";
$facebook_redirect_url = "https://videovizard.com/facebook_login_callback.php";
// Facebook Login for Business — configuration ID from Meta App Dashboard
// (Use cases → Authenticate / Login for Business → your config). The config
// itself declares which permissions are requested, so no `scope` is passed.
$facebook_login_config_id = "899645549757112";

// CSRF state for Facebook login flow — verified in facebook_login_callback.php
if (empty($_SESSION['fb_login_state'])) {
    $_SESSION['fb_login_state'] = bin2hex(random_bytes(16));
}
$fb_login_state = $_SESSION['fb_login_state'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard · Sign In</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
:root {
    --bg:            #F0F9FF;
    --card-bg:       rgba(255,255,255,0.92);
    --accent:        #0284C7;
    --accent-dark:   #0369a1;
    --text-h:        #0C4A6E;
    --text-body:     #334155;
    --text-muted:    #64748b;
    --border:        rgba(2,132,199,0.15);
    --border-hover:  rgba(2,132,199,0.35);
    --tab-active-bg: #fff;
    --tab-inactive:  #e0eef8;
    --error-bg:      #fee2e2;
    --error-border:  #fca5a5;
    --error-text:    #b91c1c;
    --success-bg:    #f0fdf4;
    --success-border:#86efac;
    --success-text:  #15803d;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'DM Sans',sans-serif;
    background:var(--bg);
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:20px;
    position:relative;
    overflow-x:hidden;
}
body::before{
    content:'';
    position:fixed;inset:0;pointer-events:none;
    background:
        radial-gradient(ellipse at 15% 25%, rgba(2,132,199,0.07) 0%, transparent 45%),
        radial-gradient(ellipse at 85% 75%, rgba(56,189,248,0.07) 0%, transparent 45%),
        radial-gradient(ellipse at 50% 100%, rgba(5,150,105,0.04) 0%, transparent 40%);
}
.deco-circle{
    position:fixed;pointer-events:none;border-radius:50%;
    background:radial-gradient(circle, rgba(2,132,199,0.08), transparent 70%);
    animation:float 8s ease-in-out infinite;
}
.deco-circle:nth-child(1){width:400px;height:400px;top:-100px;right:-100px;animation-delay:0s;}
.deco-circle:nth-child(2){width:300px;height:300px;bottom:-80px;left:-80px;animation-delay:3s;}
@keyframes float{0%,100%{transform:translateY(0);}50%{transform:translateY(-20px);}}
.wrapper{
    position:relative;z-index:10;
    width:100%;max-width:460px;
    animation:fadeUp 0.45s cubic-bezier(.22,.68,0,1.2) both;
}
@keyframes fadeUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
.brand{text-align:center;margin-bottom:28px;}
.brand-name{
    font-family:'Syne',sans-serif;font-size:34px;font-weight:800;
    text-decoration:none;display:inline-flex;align-items:center;gap:2px;
    letter-spacing:-1px;
}
.brand-video{
    background:linear-gradient(135deg,#0284C7,#0C4A6E);
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;
}
.brand-vizard{color:#0C4A6E;}
.brand-tag{font-size:13px;color:var(--text-muted);margin-top:4px;font-weight:400;}
.card{
    background:var(--card-bg);
    backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    border:1.5px solid var(--border);
    border-radius:32px;
    box-shadow:0 24px 48px -12px rgba(2,132,199,0.18), 0 0 0 1px rgba(255,255,255,0.6) inset;
    overflow:hidden;
    transition:border-color .3s, box-shadow .3s;
}
.card:hover{border-color:var(--border-hover);box-shadow:0 28px 56px -12px rgba(2,132,199,0.22), 0 0 0 1px rgba(255,255,255,0.6) inset;}
.tabs{
    display:grid;grid-template-columns:1fr 1fr;
    background:#e8f4fd;
    border-bottom:1.5px solid var(--border);
}
.tab-btn{
    padding:18px 20px;
    font-family:'Syne',sans-serif;font-size:15px;font-weight:700;
    color:var(--text-muted);background:transparent;border:none;cursor:pointer;
    transition:all .25s;letter-spacing:.3px;
    position:relative;
}
.tab-btn.active{background:#fff;color:var(--accent);}
.tab-btn.active::after{
    content:'';position:absolute;bottom:-1px;left:0;right:0;height:2.5px;
    background:var(--accent);border-radius:2px 2px 0 0;
}
.tab-btn:not(.active):hover{background:rgba(255,255,255,0.5);color:var(--text-h);}
.tab-content{display:none;padding:32px 32px 36px;}
.tab-content.active{display:block;animation:panelIn .3s ease both;}
@keyframes panelIn{from{opacity:0;transform:translateX(8px);}to{opacity:1;transform:translateX(0);}}
.pane-title{
    font-family:'Syne',sans-serif;font-size:26px;font-weight:700;
    color:var(--text-h);margin-bottom:4px;
}
.pane-sub{color:var(--text-muted);font-size:14px;margin-bottom:24px;}
.alert{
    border-radius:14px;padding:14px 18px;margin-bottom:20px;
    display:flex;align-items:flex-start;gap:10px;font-size:14px;font-weight:500;
    border:1.5px solid;animation:shake .35s ease;
}
.alert-error{background:var(--error-bg);border-color:var(--error-border);color:var(--error-text);}
.alert-success{background:var(--success-bg);border-color:var(--success-border);color:var(--success-text);}
.alert-icon{font-size:17px;flex-shrink:0;margin-top:1px;}
.alert-close{margin-left:auto;cursor:pointer;opacity:.7;font-size:17px;line-height:1;flex-shrink:0;}
.alert-close:hover{opacity:1;}
@keyframes shake{0%,100%{transform:translateX(0);}25%{transform:translateX(-4px);}75%{transform:translateX(4px);}}
.social-btns{display:flex;flex-direction:row;gap:10px;margin-bottom:20px;}
.social-btn{
    display:flex;align-items:center;justify-content:center;gap:10px;
    width:100%;padding:13px 20px;border-radius:50px;font-size:14px;font-weight:600;
    text-decoration:none;transition:all .2s;border:1.5px solid transparent;cursor:pointer;
}
.btn-google{background:#fff;color:#1e3a5f;border-color:#cbd5e1;}
.btn-google:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 6px 16px rgba(2,132,199,0.12);}
.btn-facebook{background:#1877f2;color:#fff;}
.btn-facebook:hover{background:#166fe5;transform:translateY(-2px);box-shadow:0 6px 16px rgba(24,119,242,0.2);}
/* Style-skin trick: <fb:login-button> renders a fixed-size FB button inside
   a cross-origin iframe we can't restyle. We:
     1) tell FB to render an over-wide internal button (data-width="500") so
        the inner click target spans the full visible pill width;
     2) shrink the iframe DOM element to the wrapper size and drop it to
        ~zero opacity (still hit-testable);
     3) overlay our own .fb-skin span with pointer-events:none so clicks
        pass through to the iframe underneath. */
.fb-login-wrap{position:relative;padding:0;overflow:hidden;cursor:pointer;height:46px;flex:1;}
.fb-login-wrap .fb-skin{
    position:absolute;inset:0;
    display:flex;align-items:center;justify-content:center;gap:10px;
    pointer-events:none;
    z-index:2;
    border-radius:50px;
}
.fb-login-wrap .fb_iframe_widget{
    position:absolute;inset:0;
    width:100% !important;height:100% !important;
    opacity:0.01;          /* invisible but still hit-testable */
    z-index:1;
}
.fb-login-wrap .fb_iframe_widget>span,
.fb-login-wrap .fb_iframe_widget iframe{
    width:100% !important;
    height:100% !important;
}
.divider{display:flex;align-items:center;gap:14px;margin:18px 0;color:var(--text-muted);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.field{margin-bottom:16px;}
.field label{display:block;font-size:12px;font-weight:700;color:var(--accent);margin-bottom:6px;letter-spacing:.4px;text-transform:uppercase;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{position:absolute;left:15px;color:var(--text-muted);font-size:14px;z-index:1;pointer-events:none;}
input[type=text],input[type=email],input[type=password]{
    width:100%;padding:13px 14px 13px 42px;
    background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;
    font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text-h);
    transition:all .2s;
}
input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 4px rgba(2,132,199,0.1);}
input::placeholder{color:#94a3b8;font-weight:300;}
input.err{border-color:var(--error-text);background:#fff5f5;}
.forgot{text-align:right;margin:-8px 0 16px;}
.forgot a{font-size:12px;color:var(--text-muted);text-decoration:none;transition:color .2s;}
.forgot a:hover{color:var(--accent);}
.submit-btn{
    width:100%;padding:15px;margin:4px 0 18px;
    background:var(--accent);border:none;border-radius:50px;
    color:#fff;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;
    cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
    transition:all .2s;box-shadow:0 8px 20px rgba(2,132,199,0.3);letter-spacing:.2px;
}
.submit-btn:hover{background:var(--accent-dark);transform:translateY(-2px);box-shadow:0 12px 26px rgba(2,132,199,0.38);}
.submit-btn:active{transform:translateY(0);}
.switch-link{text-align:center;font-size:14px;color:var(--text-muted);}
.switch-link a{color:var(--accent);font-weight:700;text-decoration:none;cursor:pointer;}
.switch-link a:hover{text-decoration:underline;}
.demo-box{
    margin-top:16px;padding:12px 16px;
    background:#e6f3ff;border:1px dashed rgba(2,132,199,0.25);border-radius:16px;
    font-size:12px;color:var(--text-muted);text-align:center;
}
.demo-box span{
    color:var(--accent);font-weight:700;background:#fff;
    padding:3px 8px;border-radius:20px;margin:0 3px;font-family:monospace;font-size:12px;
}
.terms{font-size:12px;color:var(--text-muted);text-align:center;margin-top:14px;line-height:1.6;}
.terms a{color:var(--accent);text-decoration:none;}
@media(max-width:480px){
    .tab-content{padding:24px 20px 28px;}
    .form-row{grid-template-columns:1fr;}
    .brand-name{font-size:28px;}
}
.terms-policy {
  margin-top: 20px;
  text-align: center;
}
.terms-policy a {
  color: gray;
  text-decoration: none;
  margin-right: 20px;
}
.terms-policy a:hover {
  text-decoration: underline;
}
</style>
</head>
<body>

<div class="deco-circle"></div>
<div class="deco-circle"></div>

<div class="wrapper">

    <!-- Brand -->
    <div class="brand">
        <img src="/videovizard.com/images/logo.png" alt="VideoVizard Logo" class="gw-logo-img" style="height:36px;width:auto;">
        <a href="#" class="brand-name">
            <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
        </a>
        <div class="brand-tag">Create · Convert · Publish</div>
    </div>

    <!-- Card -->
    <div class="card">

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn <?= $active_tab==='login' ? 'active' : '' ?>"
                    onclick="switchTab('login')" id="tab-login">
                Sign In
            </button>
            <button class="tab-btn <?= $active_tab==='register' ? 'active' : '' ?>"
                    onclick="switchTab('register')" id="tab-register">
                Create Account
            </button>
        </div>

        <!-- ── LOGIN PANE ── -->
        <div class="tab-content <?= $active_tab==='login' ? 'active' : '' ?>" id="pane-login">

            <div class="pane-title">Welcome back 👋</div>
            <div class="pane-sub">Sign in to continue creating</div>

            <?php if (!empty($login_errors)): ?>
            <div class="alert alert-error" id="loginAlert">
                <span class="alert-icon">⚠️</span>
                <span><?= htmlspecialchars($login_errors[0]) ?></span>
                <span class="alert-close" onclick="this.parentElement.remove()">✕</span>
            </div>
            <?php endif; ?>

            <?php if (!empty($reg_success)): ?>
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <span><?= htmlspecialchars($reg_success) ?></span>
            </div>
            <?php endif; ?>

            <!-- Social -->
            <div class="social-btns">
                <a href="https://accounts.google.com/o/oauth2/v2/auth?client_id=<?= $google_client_id ?>&redirect_uri=<?= urlencode($google_redirect_url) ?>&response_type=code&scope=email%20profile&access_type=online" class="social-btn btn-google">
                    <i class="fab fa-google"></i> Google-Login
                </a>
                <!--
                Old dialog/oauth redirect flow — replaced with FB JS SDK below
                <a href="https://www.facebook.com/v18.0/dialog/oauth?client_id=<?= $facebook_app_id ?>&redirect_uri=<?= urlencode($facebook_redirect_url) ?>&state=<?= urlencode($fb_login_state) ?>&response_type=code&scope=public_profile" class="social-btn btn-facebook">
                    <i class="fab fa-facebook-f"></i> Facebook-Login
                </a>
                -->
                <div class="social-btn btn-facebook">
                    
                    <fb:login-button
                      config_id="899645549757112"
                      data-size="large"
                      data-width="500"
                      onlogin="checkLoginState();">
                    </fb:login-button>
                </div>
            </div>

            <div class="divider">or continue with email</div>

            <form method="POST" id="loginForm">
                <?php if ($redirect_to): ?>
                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect_to) ?>">
                <?php endif; ?>
                <div class="field">
                    <label>Username / Email</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="far fa-user"></i></span>
                        <input type="text" name="user_name" placeholder="your username or email"
                               value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>"
                               class="<?= !empty($login_errors) ? 'err' : '' ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label>Password</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" placeholder="••••••••"
                               class="<?= !empty($login_errors) ? 'err' : '' ?>" required>
                    </div>
                </div>
                <div class="forgot"><a href="forgot-password.php">Forgot password?</a></div>
                <button type="submit" name="login_submit" class="submit-btn">
                    Sign In &nbsp;<i class="fas fa-arrow-right" style="font-size:13px;"></i>
                </button>
            </form>

            <div class="demo-box">
                ⚡ Demo: <span>demo</span> / <span>demo123</span>
            </div>
            <div class="terms-policy">
              <a href="https://videovizard.com/privacy.php">Privacy Policy</a>
              <a href="https://videovizard.com/terms.php">Terms &amp; Conditions</a>
            </div>
        </div>

        <!-- ── REGISTER PANE ── -->
        <div class="tab-content <?= $active_tab==='register' ? 'active' : '' ?>" id="pane-register">

            <div class="pane-title">Join VideoVizard ✨</div>
            <div class="pane-sub">Set up your workspace in seconds — it's free</div>

            <?php if (!empty($register_errors)): ?>
            <div class="alert alert-error" id="regAlert">
                <span class="alert-icon">⚠️</span>
                <span><?= htmlspecialchars($register_errors[0]) ?></span>
                <span class="alert-close" onclick="this.parentElement.remove()">✕</span>
            </div>
            <?php endif; ?>

            <form method="POST" id="registerForm">
                <div class="form-row">
                    <div class="field">
                        <label>First Name</label>
                        <div class="input-wrap">
                            <span class="input-icon"><i class="far fa-user"></i></span>
                            <input type="text" name="first_name" placeholder="First name"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <div class="input-wrap">
                            <span class="input-icon"><i class="far fa-user"></i></span>
                            <input type="text" name="last_name" placeholder="Last name"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label>Email Address</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="far fa-envelope"></i></span>
                        <input type="email" name="email" placeholder="you@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label>Password</label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                        <input type="password" name="password" placeholder="Min 6 characters" minlength="6" required>
                    </div>
                </div>
                <button type="submit" name="register_submit" class="submit-btn">
                    Create Free Account &nbsp;<i class="fas fa-arrow-right" style="font-size:13px;"></i>
                </button>
            </form>

            <div class="switch-link">
                Already have an account? <a onclick="switchTab('login')">Sign in →</a>
            </div>
            <div class="terms">
                By registering you agree to our <a href="#">Terms</a> and <a href="#">Privacy Policy</a>.
            </div>
        </div>

    </div><!-- /card -->
</div><!-- /wrapper -->

<div id="fb-root"></div>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
<script>
window.fbAsyncInit = function() {
    FB.init({
        appId   : '<?= htmlspecialchars($facebook_app_id, ENT_QUOTES, 'UTF-8') ?>',
        cookie  : true,
        xfbml   : true,
        version : 'v25.0'
    });
};

function fbLogin() {
      const btn = document.getElementById('btnConnect');
      if (btn) btn.disabled = true;

      FB.login(function(response) {
        if (btn) btn.disabled = false;

        if (response.authResponse) {
          window.location.href = "facebook_login_callback.php?access_token=" + encodeURIComponent(response.authResponse.accessToken);
        } else {
          console.log('User cancelled login or did not fully authorize.');
        }
      }, {
        scope: 'public_profile',
        return_scopes: true,
        auth_type: 'rerequest'
      });
    }

// Wired to <fb:login-button onlogin="checkLoginState();"> — fires after the
// user completes the Facebook Login for Business dialog.
function checkLoginState() {
    FB.getLoginStatus(function(response) {
        statusChangeCallback(response);
    });
}

function statusChangeCallback(response) {
    if (response.status === 'connected'
        && response.authResponse
        && response.authResponse.accessToken) {
        window.location.href = 'facebook_login_callback.php?access_token='
            + encodeURIComponent(response.authResponse.accessToken);
    } else {
        console.log('FB login not completed:', response);
    }
}
</script>
<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('pane-' + tab).classList.add('active');
}
document.querySelectorAll('.alert').forEach(a => {
    setTimeout(() => { if(a) a.style.opacity='0'; setTimeout(()=>a?.remove(),400); }, 5000);
});
document.querySelectorAll('input').forEach(inp => {
    inp.addEventListener('input', function() { this.classList.remove('err'); });
});
</script>
</body>
</html>
