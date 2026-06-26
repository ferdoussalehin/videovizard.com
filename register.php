<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function log_to_file($message) {
    $log_file = __DIR__ . '/a_error_logs.txt';
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

file_put_contents(__DIR__ . '/a_error_logs.txt', '');
log_to_file("--- Page loaded: " . ($_SERVER['PHP_SELF'] ?? 'unknown') . " ---");

include 'dbconnect_hdb.php';

if ($conn->connect_error) {
    log_to_file("CRITICAL: Connection failed: " . $conn->connect_error);
    die("Database connection failed.");
}
$conn->set_charset("utf8mb4");

$register_errors = [];
$login_errors    = [];

// ── REGISTRATION ─────────────────────────────────────────────────────────────
if (isset($_POST['register_submit'])) {
    $first_name = mysqli_real_escape_string($conn, trim($_POST['first_name'] ?? ''));
    $last_name  = mysqli_real_escape_string($conn, trim($_POST['last_name']  ?? ''));
    $email      = mysqli_real_escape_string($conn, trim($_POST['email']      ?? ''));
    $password   = $_POST['password'] ?? '';
    $plan       = 'free_trial';

    if (empty($first_name) || empty($email) || strlen($password) < 6) {
        $register_errors[] = "Please fill all fields correctly (Password min 6 chars).";
    }

    if (empty($register_errors)) {
        $check_res = $conn->query("SELECT id FROM hdb_users WHERE email_id = '$email' LIMIT 1");
        if ($check_res && $check_res->num_rows > 0) {
            $register_errors[] = "Email is already registered.";
        } else {
            $conn->query("START TRANSACTION");
            try {
                // 1. Create user — role always 'Team Lead' for self-registered users
                $hashed           = $password; // use password_hash() in production
                $starting_credits = 30;
                $sql_user = "INSERT INTO hdb_users (firstname, lastname, level_name, email_id, password, plan_type, role, team_lead_id, credit_balance, created_at)
                             VALUES ('$first_name', '$last_name', 'user' '$email', '$hashed', '$plan', 'Team Lead', 0, $starting_credits, NOW())";
                if (!$conn->query($sql_user)) throw new Exception("User Insert Error: " . $conn->error);
                $user_id = $conn->insert_id;
                log_to_file("INFO: Created user id=$user_id role=Team Lead plan=$plan credits=$starting_credits");

                // 2. Create company
                $company_name = mysqli_real_escape_string($conn, $first_name . "'s Studio");
                $sql_comp = "INSERT INTO hdb_companies (companyname, admin_id, created_at)
                             VALUES ('$company_name', $user_id, NOW())";
                if (!$conn->query($sql_comp)) throw new Exception("Company Insert Error: " . $conn->error);
                $company_id = $conn->insert_id;
                log_to_file("INFO: Created company id=$company_id");

                // 3. Load template defaults from admin_id=1 caption row
                $fontfamily        = 'Arial,sans-serif';
                $fontsize          = 28;
                $fontcolor         = '#ffffff';
                $fontweight        = 'bold';
                $fontcolor_bg      = '#000000';
                $fontbg_enable     = 0;
                $caption_style     = 'none';
                $caption_position  = 'bottom';
                $caption_alignment = 'center';
                $caption_speed     = 1;
                $logo_name         = '';
                $logo_size         = '60';
                $logo_position     = 'top-right';
                $logo_enabled      = 0;
                $position_x        = 50;
                $position_y        = 250;
                $width             = 500;
                $last_niche_id     = 0;

                $tpl = $conn->query("SELECT fontfamily, fontsize, fontcolor, fontweight, fontcolor_bg, fontbg_enable,
                                            caption_style, caption_position, caption_alignment, caption_speed,
                                            logo_name, logo_size, logo_position, logo_enabled,
                                            position_x, position_y, width
                                     FROM hdb_user_settings
                                     WHERE admin_id=1 AND company_id=1 AND text_type='caption' LIMIT 1");

                if ($tpl && $t = $tpl->fetch_assoc()) {
                    $fontfamily        = mysqli_real_escape_string($conn, $t['fontfamily']        ?? 'Arial');
                    $fontsize          = (int)($t['fontsize']                                      ?? 28);
                    $fontcolor         = mysqli_real_escape_string($conn, $t['fontcolor']          ?? '#ffffff');
                    $fontweight        = mysqli_real_escape_string($conn, $t['fontweight']         ?? 'bold');
                    $fontcolor_bg      = mysqli_real_escape_string($conn, $t['fontcolor_bg']       ?? '#000000');
                    $fontbg_enable     = (int)($t['fontbg_enable']                                ?? 0);
                    $caption_style     = mysqli_real_escape_string($conn, $t['caption_style']      ?? 'none');
                    $caption_position  = mysqli_real_escape_string($conn, $t['caption_position']   ?? 'bottom');
                    $caption_alignment = mysqli_real_escape_string($conn, $t['caption_alignment']  ?? 'center');
                    $caption_speed     = (int)($t['caption_speed']                                ?? 1);
                    $logo_name         = mysqli_real_escape_string($conn, $t['logo_name']          ?? '');
                    $logo_size         = mysqli_real_escape_string($conn, $t['logo_size']          ?? '60');
                    $logo_position     = mysqli_real_escape_string($conn, $t['logo_position']      ?? 'top-right');
                    $logo_enabled      = (int)($t['logo_enabled']                                 ?? 0);
                    $position_x        = (int)($t['position_x']                                   ?? 50);
                    $position_y        = (int)($t['position_y']                                   ?? 250);
                    $width             = (int)($t['width']                                        ?? 500);
                    log_to_file("INFO: Template loaded — fontfamily=$fontfamily fontsize=$fontsize");
                } else {
                    log_to_file("WARNING: No template found in hdb_user_settings — using defaults");
                }

                // 4. Define 3 rows: caption, header, footer
                $text_types = [
                    'caption' => [
                        'is_enabled'       => 1,
                        'fontfamily'       => $fontfamily,
                        'fontsize'         => $fontsize,
                        'fontcolor'        => $fontcolor,
                        'fontweight'       => $fontweight,
                        'fontcolor_bg'     => $fontcolor_bg,
                        'fontbg_enable'    => $fontbg_enable,
                        'font_italic'      => 0,
                        'font_underline'   => 0,
                        'caption_style'    => $caption_style,
                        'caption_position' => $caption_position,
                        'caption_alignment'=> $caption_alignment,
                        'caption_speed'    => $caption_speed,
                        'position_x'       => $position_x,
                        'position_y'       => $position_y,
                        'width'            => $width,
                        'text_effect'      => 'none',
                        'text_animation'   => 'static',
                        'display_mode'     => 'full',
                        'animation_speed'  => 'medium',
                        'stroke_color'     => '#000000',
                        'stroke_width'     => 0,
                        'gradient_color'   => '#ff6600',
                        'shadow_color'     => '#000000',
                        'text_align_v'     => 'bottom',
                        'logo_name'        => $logo_name,
                        'logo_size'        => $logo_size,
                        'logo_position'    => $logo_position,
                        'logo_enabled'     => $logo_enabled,
                        'logo_file'        => '',
                        'logo_pos_h'       => 'right',
                        'logo_pos_v'       => 'top',
                        'logo_size_pct'    => 15,
                        'header_text'      => '',
                        'footer_text'      => '',
                    ],
                    'header' => [
                        'is_enabled'       => 0,
                        'fontfamily'       => 'Helvetica',
                        'fontsize'         => 16,
                        'fontcolor'        => '#ffffff',
                        'fontweight'       => 'bold',
                        'fontcolor_bg'     => '#1a1a2e',
                        'fontbg_enable'    => 1,
                        'font_italic'      => 0,
                        'font_underline'   => 0,
                        'caption_style'    => 'box',
                        'caption_position' => 'top',
                        'caption_alignment'=> 'center',
                        'caption_speed'    => 1,
                        'position_x'       => 0,
                        'position_y'       => 0,
                        'width'            => 1080,
                        'text_effect'      => 'none',
                        'text_animation'   => 'fade_in',
                        'display_mode'     => 'full',
                        'animation_speed'  => 'medium',
                        'stroke_color'     => '#000000',
                        'stroke_width'     => 0,
                        'gradient_color'   => '#ff6600',
                        'shadow_color'     => '#000000',
                        'text_align_v'     => 'top',
                        'logo_name'        => '',
                        'logo_size'        => '60',
                        'logo_position'    => 'top-right',
                        'logo_enabled'     => 0,
                        'logo_file'        => '',
                        'logo_pos_h'       => 'right',
                        'logo_pos_v'       => 'top',
                        'logo_size_pct'    => 15,
                        'header_text'      => $first_name . "'s Studio",
                        'footer_text'      => '',
                    ],
                    'footer' => [
                        'is_enabled'       => 0,
                        'fontfamily'       => 'Georgia',
                        'fontsize'         => 12,
                        'fontcolor'        => '#aaaaaa',
                        'fontweight'       => 'normal',
                        'fontcolor_bg'     => '#000000',
                        'fontbg_enable'    => 0,
                        'font_italic'      => 0,
                        'font_underline'   => 0,
                        'caption_style'    => 'none',
                        'caption_position' => 'bottom',
                        'caption_alignment'=> 'center',
                        'caption_speed'    => 1,
                        'position_x'       => 0,
                        'position_y'       => 0,
                        'width'            => 1080,
                        'text_effect'      => 'none',
                        'text_animation'   => 'static',
                        'display_mode'     => 'full',
                        'animation_speed'  => 'slow',
                        'stroke_color'     => '#000000',
                        'stroke_width'     => 0,
                        'gradient_color'   => '#ff6600',
                        'shadow_color'     => '#000000',
                        'text_align_v'     => 'bottom',
                        'logo_name'        => '',
                        'logo_size'        => '60',
                        'logo_position'    => 'top-right',
                        'logo_enabled'     => 0,
                        'logo_file'        => '',
                        'logo_pos_h'       => 'right',
                        'logo_pos_v'       => 'top',
                        'logo_size_pct'    => 15,
                        'header_text'      => '',
                        'footer_text'      => 'Follow for more tips',
                    ],
                    'logo' => [
                        'is_enabled'       => 0,
                        'fontfamily'       => $fontfamily,
                        'fontsize'         => $fontsize,
                        'fontcolor'        => '#ffffff',
                        'fontweight'       => 'normal',
                        'fontcolor_bg'     => '#000000',
                        'fontbg_enable'    => 0,
                        'font_italic'      => 0,
                        'font_underline'   => 0,
                        'caption_style'    => 'none',
                        'caption_position' => 'top',
                        'caption_alignment'=> 'right',
                        'caption_speed'    => 1,
                        'position_x'       => 900,
                        'position_y'       => 40,
                        'width'            => 160,
                        'text_effect'      => 'none',
                        'text_animation'   => 'static',
                        'display_mode'     => 'full',
                        'animation_speed'  => 'medium',
                        'stroke_color'     => '#000000',
                        'stroke_width'     => 0,
                        'gradient_color'   => '#ff6600',
                        'shadow_color'     => '#000000',
                        'text_align_v'     => 'top',
                        'logo_name'        => '',
                        'logo_size'        => '60',
                        'logo_position'    => 'top-right',
                        'logo_enabled'     => 0,
                        'logo_file'        => '',
                        'logo_pos_h'       => 'right',
                        'logo_pos_v'       => 'top',
                        'logo_size_pct'    => 15,
                        'header_text'      => '',
                        'footer_text'      => '',
                    ],
                ];

                // 5. Insert the 3 rows
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
                    $fs  = (int)$ts['fontsize'];

                    $sql_s = "INSERT INTO hdb_user_settings
                        (admin_id, company_id, text_type, is_enabled,
                         fontfamily, fontsize, fontcolor, fontweight, fontcolor_bg, fontbg_enable,
                         font_italic, font_underline, caption_style, caption_position, caption_alignment, caption_speed,
                         position_x, position_y, width, last_niche_id,
                         text_effect, text_animation, display_mode, animation_speed,
                         stroke_color, stroke_width, gradient_color, shadow_color, text_align_v,
                         logo_name, logo_size, logo_position, logo_enabled, logo_file,
                         logo_pos_h, logo_pos_v, logo_size_pct,
                         
                         created_at)
                     VALUES
                        ($user_id, $company_id, '$te', {$ts['is_enabled']},
                         '$ff', $fs, '$fc', '$fw', '$fbg', {$ts['fontbg_enable']},
                         {$ts['font_italic']}, {$ts['font_underline']}, '$cs', '$cp', '$ca', {$ts['caption_speed']},
                         {$ts['position_x']}, {$ts['position_y']}, {$ts['width']}, $last_niche_id,
                         '$eff', '$tan', '$dm', '$asp',
                         '$sc', {$ts['stroke_width']}, '$gc', '$shc', '$tav',
                         '$ln', '$ls', '$lp', {$ts['logo_enabled']}, '$lf',
                         '$lph', '$lpv', {$ts['logo_size_pct']},
                         
                         NOW())";

                    log_to_file("INFO: Inserting $ttype row");
                    if (!$conn->query($sql_s)) {
                        throw new Exception("Settings Insert Error ($ttype): " . $conn->error);
                    }
                    log_to_file("INFO: $ttype row created for user=$user_id company=$company_id");
                }

                // 6. Update user with company_id and admin_id
                // credit_balance already set correctly in the INSERT above
                $conn->query("UPDATE hdb_users
                              SET company_id = $company_id,
                                  admin_id   = $user_id
                              WHERE id = $user_id");

                $conn->query("COMMIT");
                log_to_file("SUCCESS: User $user_id registered — role=Team Lead plan=$plan credits=$starting_credits — 4 settings rows created.");
                echo "<script>alert('Account created! Login to continue.'); window.location.href='login.php';</script>";
                exit;

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
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';

    $res = $conn->query("SELECT id, firstname, lastname, password, company_id FROM hdb_users WHERE email_id='$email' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $match = ($password === $row['password']) || password_verify($password, $row['password']);
        if ($match) {
            $_SESSION['admin_id']   = $row['id'];
            $_SESSION['user_id']    = $row['id'];
            $_SESSION['firstname']  = $row['firstname'];
            $_SESSION['lastname']   = $row['lastname'] ?? '';
            $_SESSION['company_id'] = $row['company_id'];
            log_to_file("LOGIN OK: admin_id=" . $row['id']);
            echo "<script>window.location.href='vizard_browser.php';</script>";
            exit;
        }
    }
    $login_errors[] = "Invalid email or password.";
    log_to_file("LOGIN FAIL: email=$email");
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VideoVizard – Register / Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{font-family:'Inter',Arial,sans-serif;background:#fdfdfd;}
.glass{background:rgba(255,255,255,0.85);backdrop-filter:blur(10px);border:1px solid #e2e8f0;}
</style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">
<div class="glass w-full max-w-md p-8 rounded-3xl shadow-2xl">

  <?php
  $errs = array_merge($register_errors, $login_errors);
  if (!empty($errs)): ?>
  <div class="bg-red-50 text-red-600 p-4 rounded-xl mb-6 text-sm border border-red-100">
    <?= htmlspecialchars($errs[0]) ?>
  </div>
  <?php endif; ?>

  <?php if (strpos($_SERVER['PHP_SELF'], 'register.php') !== false): ?>
    <h2 class="text-3xl font-bold text-slate-900 mb-2">Join VideoVizard</h2>
    <p class="text-slate-500 mb-8">Set up your workspace in seconds.</p>
    <form method="POST" class="space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <input type="text"     name="first_name" placeholder="First Name" required
               class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
        <input type="text"     name="last_name"  placeholder="Last Name"  required
               class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
      </div>
      <input type="email"    name="email"    placeholder="Email Address"          required
             class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
      <input type="password" name="password" placeholder="Password (min 6 chars)" minlength="6" required
             class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
      <button type="submit" name="register_submit"
              class="w-full bg-sky-600 text-white py-4 rounded-full font-bold shadow-lg hover:bg-sky-700 transition-all">
        Create Account
      </button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-500">
      Already have an account? <a href="login.php" class="text-sky-600 font-bold">Login</a>
    </p>

  <?php else: ?>
    <h2 class="text-3xl font-bold text-slate-900 mb-2">Welcome Back</h2>
    <p class="text-slate-500 mb-8">Access your video projects.</p>
    <form method="POST" class="space-y-4">
      <input type="email"    name="email"    placeholder="Email Address" required
             class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
      <input type="password" name="password" placeholder="Password"      required
             class="w-full p-3 rounded-xl border border-slate-200 outline-none focus:ring-2 ring-sky-500/20">
      <button type="submit" name="login_submit"
              class="w-full bg-slate-900 text-white py-4 rounded-full font-bold shadow-lg hover:bg-black transition-all">
        Sign In
      </button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-500">
      New to the platform? <a href="register.php" class="text-sky-600 font-bold">Register free</a>
    </p>
  <?php endif; ?>

</div>
</body>
</html>
