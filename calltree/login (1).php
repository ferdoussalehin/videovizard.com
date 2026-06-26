<?php
session_start();

// ── Already logged in → forward ──────────────────────────────
if (isset($_SESSION['cts_user_id'])) {
    header('Location: ' . ($_SESSION['forward_to'] ?? 'dashboard.php'));
    exit;
}

require_once __DIR__ . '/dbconnect.php';

$error   = '';
$success = '';

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $user_input = trim($_POST['user_name'] ?? '');
    $pass_input = $_POST['password']       ?? '';

    if (empty($user_input) || empty($pass_input)) {
        $error = 'Please enter your username and password.';
    } else {
        // Accept username OR email
        $safe = mysqli_real_escape_string($conn, $user_input);
        $row  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM cts_users
             WHERE user_name = '$safe' OR email_id = '$safe'
             LIMIT 1"));

        if (!$row) {
            $error = 'No account found with those credentials.';
        } elseif (!password_verify($pass_input, $row['password'])) {
            $error = 'Incorrect password. Please try again.';
        } else {
            // ── Successful login ──────────────────────────────
            session_regenerate_id(true);
            $_SESSION['cts_user_id']   = $row['id'];
            $_SESSION['cts_user_name'] = $row['user_name'];
            $_SESSION['cts_firstname'] = $row['firstname'];
            $_SESSION['cts_lastname']  = $row['lastname'];
            $_SESSION['cts_role']      = $row['role'];
            $_SESSION['cts_level']     = $row['level_name'];
            $_SESSION['cts_client_id'] = $row['client_id'];
            $_SESSION['cts_plan']      = $row['plan_type'];
            $_SESSION['forward_to']    = $row['forward_to'] ?: 'dashboard.php';

            // Update last login timestamp
            $now = date('Y-m-d H:i:s');
            mysqli_query($conn,
                "UPDATE cts_users SET updated_at='$now' WHERE id={$row['id']}");

            header('Location: ' . $_SESSION['forward_to']);
            exit;
        }
    }
}

// ── Forgot password token handler ────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'reset_password' && !empty($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    $check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM cts_users
         WHERE reset_token='$token'
           AND reset_expires > NOW()
         LIMIT 1"));
    if (!$check) {
        $error = 'This reset link has expired or is invalid.';
    } else {
        $new_pw   = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset'])) {
            if (strlen($new_pw) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($new_pw !== $confirm) {
                $error = 'Passwords do not match.';
            } else {
                $hash = password_hash($new_pw, PASSWORD_DEFAULT);
                $now  = date('Y-m-d H:i:s');
                mysqli_query($conn,
                    "UPDATE cts_users
                     SET password='$hash', reset_token=NULL, reset_expires=NULL, updated_at='$now'
                     WHERE id={$check['id']}");
                $success = 'Password updated successfully. You can now log in.';
            }
        }
        // Show reset form (handled in template below via $show_reset flag)
        $show_reset = true;
        $reset_token = $_GET['token'];
    }
}

// ── Forgot password: send email ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_forgot'])) {
    $email_in = trim($_POST['forgot_email'] ?? '');
    $safe_em  = mysqli_real_escape_string($conn, $email_in);
    $fr       = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, firstname, email_id FROM cts_users WHERE email_id='$safe_em' LIMIT 1"));
    // Always show success to prevent email enumeration
    $success = 'If that email exists, a reset link has been sent.';
    if ($fr) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        mysqli_query($conn,
            "UPDATE cts_users SET reset_token='$token', reset_expires='$expires'
             WHERE id={$fr['id']}");
        $reset_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST']
                    . '/login.php?action=reset_password&token=' . $token;
        // Wire up your mailer here — example placeholder:
        // mail($fr['email_id'], 'Reset your CallMind AI password',
        //      "Hi {$fr['firstname']},\n\nReset link (expires 1 hour):\n$reset_link");
    }
}

$show_forgot = isset($_GET['forgot']);
$show_reset  = $show_reset ?? false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — CallMind AI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;0,9..144,700;1,9..144,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --ink:       #0a0e1a;
  --ink-soft:  #3d4460;
  --ink-mute:  #7a8099;
  --cream:     #f5f0e8;
  --teal:      #1a7a6e;
  --teal-lt:   #22a090;
  --teal-pale: #d4efec;
  --gold:      #c8973a;
  --white:     #ffffff;
  --danger:    #dc2626;
  --danger-bg: #fef2f2;
  --success:   #166534;
  --success-bg:#f0fdf4;
  --border:    rgba(10,14,26,.1);
  --shadow-md: 0 8px 32px rgba(10,14,26,.10);
  --shadow-lg: 0 24px 64px rgba(10,14,26,.16);
  --ff-display: 'Fraunces', Georgia, serif;
  --ff-body:    'DM Sans', sans-serif;
}

html, body {
  height: 100%;
  font-family: var(--ff-body);
  background: var(--ink);
  color: var(--ink);
}

/* ── Layout: split screen ───────────────────────────────────── */
.login-wrap {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 1fr;
}

/* ── Left panel — brand / visual ────────────────────────────── */
.brand-panel {
  background: var(--ink);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 40px 52px;
  position: relative;
  overflow: hidden;
}

/* animated background mesh */
.brand-panel::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(ellipse 80% 60% at 20% 10%,  rgba(26,122,110,.25) 0%, transparent 55%),
    radial-gradient(ellipse 60% 70% at 85% 80%,  rgba(200,151,58,.12) 0%, transparent 55%),
    radial-gradient(ellipse 50% 50% at 60% 40%,  rgba(26,122,110,.08) 0%, transparent 50%);
}

/* grid dots */
.brand-panel::after {
  content: '';
  position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px);
  background-size: 28px 28px;
}

.brand-panel > * { position: relative; z-index: 1; }

/* logo */
.bp-logo {
  display: flex; align-items: center; gap: 10px;
  text-decoration: none;
}
.bp-logo-mark {
  width: 38px; height: 38px; border-radius: 10px;
  background: var(--teal);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.bp-logo-mark svg { width: 20px; height: 20px; }
.bp-logo-name {
  font-family: var(--ff-display);
  font-size: 20px; font-weight: 700;
  color: var(--white); letter-spacing: -.02em;
}
.bp-logo-name em { color: var(--teal-lt); font-style: normal; }

/* centre content */
.bp-body { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 40px 0; }

.bp-tagline {
  font-family: var(--ff-display);
  font-size: clamp(28px, 3vw, 42px);
  font-weight: 600; line-height: 1.2;
  color: var(--white);
  letter-spacing: -.03em;
  margin-bottom: 16px;
}
.bp-tagline em { color: var(--teal-lt); font-style: italic; }

.bp-sub {
  font-size: 15px; line-height: 1.7;
  color: rgba(255,255,255,.5);
  max-width: 340px; margin-bottom: 44px;
}

/* stat pills */
.bp-stats { display: flex; flex-direction: column; gap: 12px; }
.bp-stat {
  display: flex; align-items: center; gap: 14px;
  background: rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 14px; padding: 16px 20px;
  backdrop-filter: blur(4px);
  animation: statIn .6s ease both;
}
.bp-stat:nth-child(1) { animation-delay: .1s; }
.bp-stat:nth-child(2) { animation-delay: .2s; }
.bp-stat:nth-child(3) { animation-delay: .3s; }
@keyframes statIn {
  from { opacity: 0; transform: translateX(-16px); }
  to   { opacity: 1; transform: translateX(0); }
}
.bp-stat-icon {
  width: 40px; height: 40px; border-radius: 10px;
  background: rgba(26,122,110,.25);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.bp-stat-num {
  font-family: var(--ff-display);
  font-size: 22px; font-weight: 700;
  color: var(--white); line-height: 1;
}
.bp-stat-lbl { font-size: 12px; color: rgba(255,255,255,.45); margin-top: 2px; }

/* bottom copy */
.bp-footer {
  font-size: 12px; color: rgba(255,255,255,.25);
}
.bp-footer a { color: rgba(255,255,255,.35); text-decoration: none; }
.bp-footer a:hover { color: rgba(255,255,255,.6); }

/* ── Right panel — form ─────────────────────────────────────── */
.form-panel {
  background: var(--cream);
  display: flex; align-items: center; justify-content: center;
  padding: 40px 32px;
  position: relative;
}

/* noise texture */
.form-panel::before {
  content: '';
  position: absolute; inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
  pointer-events: none;
}

.form-box {
  width: 100%; max-width: 420px;
  position: relative; z-index: 1;
  animation: formIn .5s ease both;
}
@keyframes formIn {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}

.form-head { margin-bottom: 32px; }
.form-title {
  font-family: var(--ff-display);
  font-size: 30px; font-weight: 600;
  color: var(--ink); letter-spacing: -.03em;
  margin-bottom: 6px;
}
.form-sub { font-size: 14px; color: var(--ink-mute); line-height: 1.5; }

/* alert boxes */
.alert {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 16px; border-radius: 12px;
  font-size: 13px; line-height: 1.5;
  margin-bottom: 20px;
  animation: alertIn .3s ease;
}
@keyframes alertIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.alert-err  { background: var(--danger-bg);  color: var(--danger);  border: 1px solid #fecaca; }
.alert-ok   { background: var(--success-bg); color: var(--success); border: 1px solid #86efac; }
.alert-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* form elements */
.fg { margin-bottom: 18px; }
.fg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

label {
  display: block;
  font-size: 12px; font-weight: 600;
  color: var(--ink); letter-spacing: .03em;
  margin-bottom: 6px;
}

.input-wrap { position: relative; }
.input-icon {
  position: absolute; left: 13px; top: 50%;
  transform: translateY(-50%);
  color: var(--ink-mute); font-size: 15px;
  pointer-events: none;
}
input[type="text"],
input[type="email"],
input[type="password"] {
  width: 100%;
  padding: 11px 14px 11px 38px;
  background: var(--white);
  border: 1.5px solid var(--border);
  border-radius: 12px;
  font-size: 14px;
  font-family: var(--ff-body);
  color: var(--ink);
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
input:focus {
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(26,122,110,.1);
}
input.no-icon { padding-left: 14px; }

/* password eye toggle */
.pw-wrap { position: relative; }
.pw-eye {
  position: absolute; right: 12px; top: 50%;
  transform: translateY(-50%);
  background: none; border: none; cursor: pointer;
  color: var(--ink-mute); font-size: 15px;
  padding: 4px; line-height: 1;
  transition: color .15s;
}
.pw-eye:hover { color: var(--ink); }

/* remember + forgot row */
.form-meta {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 22px; margin-top: -6px;
}
.remember {
  display: flex; align-items: center; gap: 7px;
  font-size: 13px; color: var(--ink-soft); cursor: pointer;
}
.remember input[type="checkbox"] {
  width: 15px; height: 15px;
  accent-color: var(--teal);
  cursor: pointer;
  padding: 0; border: none;
}
.forgot-link {
  font-size: 13px; font-weight: 600;
  color: var(--teal); text-decoration: none;
  transition: color .15s;
}
.forgot-link:hover { color: var(--teal-lt); }

/* submit button */
.btn-signin {
  width: 100%;
  padding: 13px;
  background: var(--ink);
  color: var(--white);
  border: none; border-radius: 12px;
  font-size: 15px; font-weight: 700;
  font-family: var(--ff-body);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: background .2s, transform .15s;
  letter-spacing: .01em;
}
.btn-signin:hover   { background: #1a2035; transform: translateY(-1px); }
.btn-signin:active  { transform: translateY(0); }
.btn-signin:disabled { opacity: .6; cursor: not-allowed; transform: none; }

.btn-teal {
  width: 100%; padding: 13px;
  background: var(--teal); color: var(--white);
  border: none; border-radius: 12px;
  font-size: 15px; font-weight: 700;
  font-family: var(--ff-body); cursor: pointer;
  transition: background .2s, transform .15s;
}
.btn-teal:hover { background: var(--teal-lt); transform: translateY(-1px); }

/* divider */
.or-divider {
  display: flex; align-items: center; gap: 12px;
  margin: 22px 0; color: var(--ink-mute); font-size: 12px;
}
.or-divider::before, .or-divider::after {
  content: ''; flex: 1; height: 1px;
  background: var(--border);
}

/* bottom link */
.form-bottom {
  text-align: center; margin-top: 24px;
  font-size: 13px; color: var(--ink-mute);
}
.form-bottom a { color: var(--teal); font-weight: 600; text-decoration: none; }
.form-bottom a:hover { color: var(--teal-lt); }

/* back link */
.back-link {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 13px; color: var(--ink-mute);
  text-decoration: none; margin-bottom: 28px;
  transition: color .15s;
}
.back-link:hover { color: var(--ink); }

/* spinner */
.spinner {
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: var(--white);
  border-radius: 50%;
  animation: spin .6s linear infinite;
  display: none;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 768px) {
  .login-wrap { grid-template-columns: 1fr; }
  .brand-panel { display: none; }
  .form-panel { padding: 32px 20px; min-height: 100vh; background: var(--cream); }
}
</style>
</head>
<body>

<div class="login-wrap">

  <!-- ══ LEFT: Brand Panel ════════════════════════════════════ -->
  <div class="brand-panel">

    <a href="index.php" class="bp-logo">
      <div class="bp-logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
          <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
        </svg>
      </div>
      <span class="bp-logo-name">Call<em>Mind</em> AI</span>
    </a>

    <div class="bp-body">
      <h2 class="bp-tagline">
        Your pipeline,<br>
        <em>always working.</em>
      </h2>
      <p class="bp-sub">
        AI calling agents that prospect, qualify, and book appointments for real estate agents and financial advisors — 24 hours a day.
      </p>

      <div class="bp-stats">
        <div class="bp-stat">
          <div class="bp-stat-icon">📞</div>
          <div>
            <div class="bp-stat-num">94%</div>
            <div class="bp-stat-lbl">Lead answer rate vs voicemail</div>
          </div>
        </div>
        <div class="bp-stat">
          <div class="bp-stat-icon">📅</div>
          <div>
            <div class="bp-stat-num">3.2×</div>
            <div class="bp-stat-lbl">More appointments booked</div>
          </div>
        </div>
        <div class="bp-stat">
          <div class="bp-stat-icon">💰</div>
          <div>
            <div class="bp-stat-num">$0.08</div>
            <div class="bp-stat-lbl">Per minute of AI calling</div>
          </div>
        </div>
      </div>
    </div>

    <div class="bp-footer">
      © <?= date('Y') ?> CallMind AI &nbsp;·&nbsp;
      <a href="privacy.php">Privacy</a> &nbsp;·&nbsp;
      <a href="terms.php">Terms</a>
    </div>

  </div>

  <!-- ══ RIGHT: Form Panel ════════════════════════════════════ -->
  <div class="form-panel">
    <div class="form-box">

      <?php if ($show_reset): ?>
      <!-- ── Reset Password Form ──────────────────────────── -->
      <a href="login.php" class="back-link">← Back to sign in</a>
      <div class="form-head">
        <h1 class="form-title">Set new password</h1>
        <p class="form-sub">Choose a strong password — at least 8 characters.</p>
      </div>

      <?php if ($error):   ?><div class="alert alert-err"><span class="alert-icon">⚠</span><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-ok"><span class="alert-icon">✓</span><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" action="login.php?action=reset_password&token=<?= htmlspecialchars($reset_token ?? '') ?>">
        <div class="fg">
          <label>New Password</label>
          <div class="pw-wrap">
            <input type="password" name="new_password" id="pw1" class="no-icon" placeholder="At least 8 characters" required minlength="8">
            <button type="button" class="pw-eye" onclick="togglePw('pw1',this)">👁</button>
          </div>
        </div>
        <div class="fg">
          <label>Confirm Password</label>
          <div class="pw-wrap">
            <input type="password" name="confirm_password" id="pw2" class="no-icon" placeholder="Repeat your password" required>
            <button type="button" class="pw-eye" onclick="togglePw('pw2',this)">👁</button>
          </div>
        </div>
        <input type="hidden" name="do_reset" value="1">
        <button type="submit" class="btn-teal">Update Password</button>
      </form>
      <?php endif; ?>

      <?php elseif ($show_forgot): ?>
      <!-- ── Forgot Password Form ─────────────────────────── -->
      <a href="login.php" class="back-link">← Back to sign in</a>
      <div class="form-head">
        <h1 class="form-title">Forgot password?</h1>
        <p class="form-sub">Enter your account email and we'll send a reset link — valid for 1 hour.</p>
      </div>

      <?php if ($error):   ?><div class="alert alert-err"><span class="alert-icon">⚠</span><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-ok"><span class="alert-icon">✓</span><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <?php if (!$success): ?>
      <form method="POST" action="login.php?forgot">
        <div class="fg">
          <label>Email Address</label>
          <div class="input-wrap">
            <span class="input-icon">✉</span>
            <input type="email" name="forgot_email" placeholder="you@company.com" required>
          </div>
        </div>
        <input type="hidden" name="do_forgot" value="1">
        <button type="submit" class="btn-teal">Send Reset Link</button>
      </form>
      <?php endif; ?>

      <div class="form-bottom">
        Remembered it? <a href="login.php">Sign in</a>
      </div>

      <?php else: ?>
      <!-- ── Main Login Form ──────────────────────────────── -->
      <div class="form-head">
        <h1 class="form-title">Welcome back</h1>
        <p class="form-sub">Sign in to your CallMind AI dashboard.</p>
      </div>

      <?php if ($error):   ?><div class="alert alert-err"><span class="alert-icon">⚠</span><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-ok"><span class="alert-icon">✓</span><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <form method="POST" action="login.php" id="loginForm" onsubmit="handleSubmit(event)">

        <div class="fg">
          <label for="user_name">Username or Email</label>
          <div class="input-wrap">
            <span class="input-icon">👤</span>
            <input
              type="text"
              id="user_name"
              name="user_name"
              placeholder="username or email@domain.com"
              value="<?= htmlspecialchars($_POST['user_name'] ?? '') ?>"
              autocomplete="username"
              required
            >
          </div>
        </div>

        <div class="fg">
          <label for="password">Password</label>
          <div class="pw-wrap">
            <input
              type="password"
              id="password"
              name="password"
              class="no-icon"
              placeholder="Your password"
              autocomplete="current-password"
              required
            >
            <button type="button" class="pw-eye" id="eyeBtn" onclick="togglePw('password', this)">👁</button>
          </div>
        </div>

        <div class="form-meta">
          <label class="remember">
            <input type="checkbox" name="remember" value="1">
            Keep me signed in
          </label>
          <a href="login.php?forgot" class="forgot-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn-signin" id="signinBtn">
          <span class="spinner" id="spinEl"></span>
          <span id="btnLabel">Sign In →</span>
        </button>

      </form>

      <div class="or-divider">or</div>

      <div class="form-bottom">
        Don't have an account?
        <a href="index.php#pricing">View plans</a>
        &nbsp;·&nbsp;
        <a href="index.php#pricing">Request access</a>
      </div>

      <?php endif; ?>

    </div><!-- /form-box -->
  </div><!-- /form-panel -->

</div><!-- /login-wrap -->

<script>
// ── Password visibility toggle ────────────────────────────────
function togglePw(inputId, btn) {
  const f = document.getElementById(inputId);
  if (!f) return;
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}

// ── Submit spinner ────────────────────────────────────────────
function handleSubmit(e) {
  const btn   = document.getElementById('signinBtn');
  const spin  = document.getElementById('spinEl');
  const label = document.getElementById('btnLabel');
  if (!btn || !spin || !label) return;
  btn.disabled     = true;
  spin.style.display = 'block';
  label.textContent  = 'Signing in…';
}

// ── Auto-focus first empty field ──────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const un = document.getElementById('user_name');
  const pw = document.getElementById('password');
  if (un && !un.value) un.focus();
  else if (pw) pw.focus();
});
</script>
</body>
</html>
