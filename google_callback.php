<?php
// google_callback.php
// Handles Google OAuth — logs in existing users or auto-registers new ones

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

// Single include handles all session config — keeps user logged in for 1 year
require_once 'session_config.php';

require_once 'dbconnect_hdb.php';
require_once 'youtube_config.php';
if (!isset($conn)) include 'dbconnect_hdb.php';
mysqli_set_charset($conn, 'utf8mb4');

// ── Exchange code for token ───────────────────────────────────────
$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    error_log("Google Login: error=$error");
    header('Location: login.php?error=google_denied');
    exit;
}

if (!$code) {
    header('Location: login.php?error=no_code');
    exit;
}

// Exchange code for access token
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => YT_CLIENT_ID,
        'client_secret' => YT_CLIENT_SECRET,
        'redirect_uri'  => 'https://videovizard.com/google_callback.php',
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token_data = json_decode($response, true);
error_log("Google Login: token exchange HTTP=$httpcode");

if ($httpcode !== 200 || empty($token_data['access_token'])) {
    error_log("Google Login: token failed: $response");
    header('Location: login.php?error=token_failed');
    exit;
}

$access_token = $token_data['access_token'];

// ── Get Google user info ──────────────────────────────────────────
$ch2 = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$user_response = curl_exec($ch2);
curl_close($ch2);

$google_user = json_decode($user_response, true);
error_log("Google Login: user info: " . json_encode($google_user));

$google_email   = trim($google_user['email']       ?? '');
$google_first   = trim($google_user['given_name']  ?? '');
$google_last    = trim($google_user['family_name'] ?? '');
$google_name    = trim($google_user['name']        ?? '');
$google_picture = trim($google_user['picture']     ?? '');

if (!$google_email) {
    error_log("Google Login: no email returned");
    header('Location: login.php?error=no_email');
    exit;
}

// ── Helper: set persistent cookie and redirect ────────────────────
function loginAndRedirect($admin_id, $company_id, $firstname, $lastname, $url = 'vizard_browser.php') {
    session_regenerate_id(true);

    $_SESSION['admin_id']      = $admin_id;
    $_SESSION['company_id']    = $company_id;
    $_SESSION['user']          = $firstname;
    $_SESSION['firstname']     = $firstname;
    $_SESSION['lastname']      = $lastname;
    $_SESSION['created_at']    = time();
    $_SESSION['last_activity'] = time();

    // Persistent 1-year cookie — stays alive through browser restarts
    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,   // set false if not on HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );

    header("Location: $url");
    exit;
}

// ── Check if user already exists ─────────────────────────────────
$esc_email = mysqli_real_escape_string($conn, $google_email);
$query     = "SELECT * FROM hdb_users WHERE LOWER(TRIM(email_id))='$esc_email' LIMIT 1";
$existing  = mysqli_fetch_assoc(mysqli_query($conn, $query));

error_log("Google Login: looking for email=$esc_email found=" . ($existing ? 'YES id='.$existing['id'] : 'NO'));

if ($existing) {
    // ── EXISTING USER — log in ────────────────────────────────
    error_log("Google Login: existing user id=" . $existing['id']);
    $admin_id   = (int)$existing['id'];
    $company_id = (int)($existing['company_id'] ?? 0);

    // Fetch company if not stored on user row
    if (!$company_id) {
        $comp = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1"));
        $company_id = $comp ? (int)$comp['id'] : 0;
        if ($company_id) {
            mysqli_query($conn, "UPDATE hdb_users SET company_id=$company_id WHERE id=$admin_id");
        }
    }

    error_log("Google Login: login OK admin_id=$admin_id company_id=$company_id");
    loginAndRedirect($admin_id, $company_id, $existing['firstname'], $existing['lastname']);
}

// ── NEW USER — auto-register ──────────────────────────────────────
error_log("Google Login: new user, auto-registering email=$google_email");

if (!$google_first) $google_first = explode(' ', $google_name)[0] ?? 'User';
if (!$google_last)  $google_last  = explode(' ', $google_name)[1] ?? '';

$esc_first    = mysqli_real_escape_string($conn, $google_first);
$esc_last     = mysqli_real_escape_string($conn, $google_last);
$now          = date('Y-m-d H:i:s');
$trial_expiry = date('Y-m-d', strtotime('+14 days'));

mysqli_query($conn, "START TRANSACTION");

try {
    // 1. Create user
    $rand_pass = bin2hex(random_bytes(8));
    $sql_user  = "INSERT INTO hdb_users 
        (firstname, lastname, level_name, role, credit_balance, email_id, password, plan_type, 
         client_id, trial_period_expiry_dt,
         max_videos_allowed, video_count, schedule_flag,
         created_at, updated_at)
     VALUES 
        ('$esc_first', '$esc_last', 'user', 'Team Lead', 30, '$esc_email', '$rand_pass', 'free_trial',
         0, '$trial_expiry',
         10, 0, 0,
         '$now', '$now')";

    if (!mysqli_query($conn, $sql_user))
        throw new Exception("User insert failed: " . mysqli_error($conn));

    $user_id = mysqli_insert_id($conn);
    error_log("Google Login: created user_id=$user_id");

    // 2. Create company
    $company_name = mysqli_real_escape_string($conn, $google_first . "'s Studio");
    $sql_comp = "INSERT INTO hdb_companies (companyname, admin_id, company_type, created_at)
                 VALUES ('$company_name', $user_id, 'internal', '$now')";

    if (!mysqli_query($conn, $sql_comp))
        throw new Exception("Company insert failed: " . mysqli_error($conn));

    $company_id = mysqli_insert_id($conn);
    error_log("Google Login: created company_id=$company_id");

    // 3. Load template defaults from admin_id=1
    $fontfamily = 'Arial,sans-serif'; $fontsize = 28;
    $fontcolor = '#ffffff'; $fontweight = 'bold';
    $fontcolor_bg = '#000000'; $fontbg_enable = 0;
    $caption_style = 'none'; $caption_position = 'bottom';
    $caption_alignment = 'center'; $caption_speed = 1;
    $logo_name = ''; $logo_size = '60';
    $logo_position = 'top-right'; $logo_enabled = 0;
    $position_x = 50; $position_y = 250; $width = 500;

    $tpl = mysqli_query($conn,
        "SELECT * FROM hdb_user_settings 
         WHERE admin_id=1 AND company_id=1 AND text_type='caption' LIMIT 1");
    if ($tpl && $t = mysqli_fetch_assoc($tpl)) {
        $fontfamily        = mysqli_real_escape_string($conn, $t['fontfamily']         ?? 'Arial,sans-serif');
        $fontsize          = (int)($t['fontsize']                                       ?? 28);
        $fontcolor         = mysqli_real_escape_string($conn, $t['fontcolor']           ?? '#ffffff');
        $fontweight        = mysqli_real_escape_string($conn, $t['fontweight']          ?? 'bold');
        $fontcolor_bg      = mysqli_real_escape_string($conn, $t['fontcolor_bg']        ?? '#000000');
        $fontbg_enable     = (int)($t['fontbg_enable']                                 ?? 0);
        $caption_style     = mysqli_real_escape_string($conn, $t['caption_style']       ?? 'none');
        $caption_position  = mysqli_real_escape_string($conn, $t['caption_position']    ?? 'bottom');
        $caption_alignment = mysqli_real_escape_string($conn, $t['caption_alignment']   ?? 'center');
        $caption_speed     = (int)($t['caption_speed']                                 ?? 1);
        $logo_name         = mysqli_real_escape_string($conn, $t['logo_name']           ?? '');
        $logo_size         = mysqli_real_escape_string($conn, $t['logo_size']           ?? '60');
        $logo_position     = mysqli_real_escape_string($conn, $t['logo_position']       ?? 'top-right');
        $logo_enabled      = (int)($t['logo_enabled']                                  ?? 0);
        $position_x        = (int)($t['position_x']                                    ?? 50);
        $position_y        = (int)($t['position_y']                                    ?? 250);
        $width             = (int)($t['width']                                         ?? 500);
    }

    // 4. Insert caption/header/footer settings
    $text_types = [
        'caption' => [
            'is_enabled'=>1, 'fontfamily'=>$fontfamily, 'fontsize'=>$fontsize,
            'fontcolor'=>$fontcolor, 'fontweight'=>$fontweight,
            'fontcolor_bg'=>$fontcolor_bg, 'fontbg_enable'=>$fontbg_enable,
            'font_italic'=>0, 'font_underline'=>0,
            'caption_style'=>$caption_style, 'caption_position'=>$caption_position,
            'caption_alignment'=>$caption_alignment, 'caption_speed'=>$caption_speed,
            'position_x'=>$position_x, 'position_y'=>$position_y, 'width'=>$width,
            'text_effect'=>'none', 'text_animation'=>'static', 'display_mode'=>'full',
            'animation_speed'=>'medium', 'stroke_color'=>'#000000', 'stroke_width'=>0,
            'gradient_color'=>'#ff6600', 'shadow_color'=>'#000000', 'text_align_v'=>'bottom',
            'logo_name'=>$logo_name, 'logo_size'=>$logo_size, 'logo_position'=>$logo_position,
            'logo_enabled'=>$logo_enabled, 'logo_file'=>'',
            'logo_pos_h'=>'right', 'logo_pos_v'=>'top', 'logo_size_pct'=>15,
            'header_text'=>'', 'footer_text'=>'',
        ],
        'header' => [
            'is_enabled'=>0, 'fontfamily'=>'Helvetica', 'fontsize'=>16,
            'fontcolor'=>'#ffffff', 'fontweight'=>'bold',
            'fontcolor_bg'=>'#1a1a2e', 'fontbg_enable'=>1,
            'font_italic'=>0, 'font_underline'=>0,
            'caption_style'=>'box', 'caption_position'=>'top',
            'caption_alignment'=>'center', 'caption_speed'=>1,
            'position_x'=>0, 'position_y'=>0, 'width'=>1080,
            'text_effect'=>'none', 'text_animation'=>'fade_in', 'display_mode'=>'full',
            'animation_speed'=>'medium', 'stroke_color'=>'#000000', 'stroke_width'=>0,
            'gradient_color'=>'#ff6600', 'shadow_color'=>'#000000', 'text_align_v'=>'top',
            'logo_name'=>'', 'logo_size'=>'60', 'logo_position'=>'top-right',
            'logo_enabled'=>0, 'logo_file'=>'',
            'logo_pos_h'=>'right', 'logo_pos_v'=>'top', 'logo_size_pct'=>15,
            'header_text'=>$google_first . "'s Studio", 'footer_text'=>'',
        ],
        'footer' => [
            'is_enabled'=>0, 'fontfamily'=>'Georgia', 'fontsize'=>12,
            'fontcolor'=>'#aaaaaa', 'fontweight'=>'normal',
            'fontcolor_bg'=>'#000000', 'fontbg_enable'=>0,
            'font_italic'=>0, 'font_underline'=>0,
            'caption_style'=>'none', 'caption_position'=>'bottom',
            'caption_alignment'=>'center', 'caption_speed'=>1,
            'position_x'=>0, 'position_y'=>0, 'width'=>1080,
            'text_effect'=>'none', 'text_animation'=>'static', 'display_mode'=>'full',
            'animation_speed'=>'slow', 'stroke_color'=>'#000000', 'stroke_width'=>0,
            'gradient_color'=>'#ff6600', 'shadow_color'=>'#000000', 'text_align_v'=>'bottom',
            'logo_name'=>'', 'logo_size'=>'60', 'logo_position'=>'top-right',
            'logo_enabled'=>0, 'logo_file'=>'',
            'logo_pos_h'=>'right', 'logo_pos_v'=>'top', 'logo_size_pct'=>15,
            'header_text'=>'', 'footer_text'=>'Follow for more tips',
        ],
    ];

    foreach ($text_types as $ttype => $ts) {
        $te  = mysqli_real_escape_string($conn, $ttype);
        $ff  = mysqli_real_escape_string($conn, $ts['fontfamily']);
        $fc  = mysqli_real_escape_string($conn, $ts['fontcolor']);
        $fw  = mysqli_real_escape_string($conn, $ts['fontweight']);
        $fbg = mysqli_real_escape_string($conn, $ts['fontcolor_bg']);
        $cs  = mysqli_real_escape_string($conn, $ts['caption_style']);
        $cp  = mysqli_real_escape_string($conn, $ts['caption_position']);
        $ca  = mysqli_real_escape_string($conn, $ts['caption_alignment']);
        $eff = mysqli_real_escape_string($conn, $ts['text_effect']);
        $tan = mysqli_real_escape_string($conn, $ts['text_animation']);
        $dm  = mysqli_real_escape_string($conn, $ts['display_mode']);
        $asp = mysqli_real_escape_string($conn, $ts['animation_speed']);
        $sc  = mysqli_real_escape_string($conn, $ts['stroke_color']);
        $gc  = mysqli_real_escape_string($conn, $ts['gradient_color']);
        $shc = mysqli_real_escape_string($conn, $ts['shadow_color']);
        $tav = mysqli_real_escape_string($conn, $ts['text_align_v']);
        $ln  = mysqli_real_escape_string($conn, $ts['logo_name']);
        $ls  = mysqli_real_escape_string($conn, $ts['logo_size']);
        $lp  = mysqli_real_escape_string($conn, $ts['logo_position']);
        $lf  = mysqli_real_escape_string($conn, $ts['logo_file']);
        $lph = mysqli_real_escape_string($conn, $ts['logo_pos_h']);
        $lpv = mysqli_real_escape_string($conn, $ts['logo_pos_v']);
        $ht  = mysqli_real_escape_string($conn, $ts['header_text']);
        $ft  = mysqli_real_escape_string($conn, $ts['footer_text']);
        $fs2 = (int)$ts['fontsize'];

        $sql_s = "INSERT INTO hdb_user_settings
            (admin_id, company_id, text_type, is_enabled,
             fontfamily, fontsize, fontcolor, fontweight, fontcolor_bg, fontbg_enable,
             font_italic, font_underline, caption_style, caption_position, caption_alignment, caption_speed,
             position_x, position_y, width, last_niche_id,
             text_effect, text_animation, display_mode, animation_speed,
             stroke_color, stroke_width, gradient_color, shadow_color, text_align_v,
             logo_name, logo_size, logo_position, logo_enabled, logo_file,
             logo_pos_h, logo_pos_v, logo_size_pct, created_at)
         VALUES
            ($user_id, $company_id, '$te', {$ts['is_enabled']},
             '$ff', $fs2, '$fc', '$fw', '$fbg', {$ts['fontbg_enable']},
             {$ts['font_italic']}, {$ts['font_underline']}, '$cs', '$cp', '$ca', {$ts['caption_speed']},
             {$ts['position_x']}, {$ts['position_y']}, {$ts['width']}, 0,
             '$eff', '$tan', '$dm', '$asp',
             '$sc', {$ts['stroke_width']}, '$gc', '$shc', '$tav',
             '$ln', '$ls', '$lp', {$ts['logo_enabled']}, '$lf',
             '$lph', '$lpv', {$ts['logo_size_pct']}, '$now')";

        if (!mysqli_query($conn, $sql_s))
            throw new Exception("Settings insert failed ($ttype): " . mysqli_error($conn));
    }

    // 5. Update user with company_id and admin_id
    mysqli_query($conn,
        "UPDATE hdb_users SET company_id=$company_id, admin_id=$user_id WHERE id=$user_id");

    mysqli_query($conn, "COMMIT");
    error_log("Google Login: auto-registration complete user_id=$user_id company_id=$company_id");

    // 6. Set session, persistent cookie, and redirect
    loginAndRedirect($user_id, $company_id, $google_first, $google_last);

} catch (Exception $e) {
    mysqli_query($conn, "ROLLBACK");
    error_log("Google Login: registration failed: " . $e->getMessage());
    header('Location: login.php?error=registration_failed');
    exit;
}
