<?php
// Copy the entire code from register.php
// Then modify the JavaScript at the bottom to force login view:
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VidoraEasy – sign up & log in</title>
  <!-- same fonts & base styling from index, plus extra for forms & carousel -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    /* ----- global / same as index theme ----- */
    :root {
      --dark: #0a1628;
      --navy: #0f2a44;
      --blue: #143b63;
      --accent: #5fd1ff;
      --accent2: #38bdf8;
      --green: #10b981;
      --glow: rgba(95,209,255,0.18);
      --white: #ffffff;
      --muted: rgba(255,255,255,0.55);
      --border: rgba(95,209,255,0.15);
      --input-bg: rgba(10,22,40,0.7);
    }
    *, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
    html { scroll-behavior:smooth; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--dark);
      color: var(--white);
      overflow-x: hidden;
      min-height:100vh;
      display: flex;
      flex-direction: column;
    }
    h1,h2,h3,h4 { font-family: 'Syne', sans-serif; }
    body::before {
      content:'';
      position:fixed; inset:0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
      pointer-events:none; z-index:0; opacity:0.4;
    }
    .mesh-bg {
      position:fixed; inset:0; pointer-events:none; z-index:0; overflow:hidden;
    }
    .mesh-bg::before {
      content:''; position:absolute; width:800px; height:800px; top:-200px; right:-200px;
      background:radial-gradient(circle, rgba(95,209,255,0.12) 0%, transparent 70%);
      animation:float1 8s ease-in-out infinite;
    }
    .mesh-bg::after {
      content:''; position:absolute; width:600px; height:600px; bottom:10%; left:-100px;
      background:radial-gradient(circle, rgba(16,185,129,0.08) 0%, transparent 70%);
      animation:float2 10s ease-in-out infinite;
    }
    @keyframes float1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-30px,30px)} }
    @keyframes float2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(30px,-20px)} }

    /* nav (compact) */
    nav {
      position:fixed; top:0; left:0; right:0; z-index:100;
      padding:14px 40px; display:flex; justify-content:space-between; align-items:center;
      background: rgba(10,22,40,0.7); backdrop-filter:blur(20px);
      border-bottom:1px solid var(--border);
    }
    .nav-brand {
      font-family:'Syne',sans-serif; font-size:20px; font-weight:800;
      display:flex; align-items:center; gap:10px; text-decoration:none; color:white;
    }
    .brand-easy { color:var(--accent); }
    .nav-links a {
      color:var(--muted); text-decoration:none; font-size:14px; font-weight:500;
      margin-left:32px; transition:color 0.2s;
    }
    .nav-links a:hover { color:white; }
    .nav-links .nav-cta {
      background:linear-gradient(135deg,var(--accent),#38bdf8); color:var(--navy)!important;
      padding:8px 20px; border-radius:50px; font-weight:700; font-size:13px;
      box-shadow:0 0 20px rgba(95,209,255,0.3);
    }

    /* ----- split layout for register & login (pictory style) ----- */
    .auth-split {
      display:flex; min-height:100vh; margin-top:0; padding-top:70px; /* nav space */
      width:100%; position:relative; z-index:5;
    }
    .auth-left {
      flex:1 1 50%; background: rgba(15,42,68,0.3); backdrop-filter:blur(4px);
      display:flex; align-items:center; justify-content:center;
      border-right:1px solid var(--border);
      padding:40px 20px;
    }
    .carousel-container {
      max-width:500px; width:100%; text-align:left;
    }
    .carousel-slide {
      display:none; animation:fadeCarousel 0.8s ease;
    }
    .carousel-slide.active { display:block; }
    .carousel-slide h2 { font-size:42px; font-weight:800; line-height:1.2; margin-bottom:25px; }
    .carousel-slide h2 span { color:var(--accent); }
    .carousel-slide p { font-size:18px; color:var(--muted); margin-bottom:30px; }
    .carousel-icon { font-size:80px; margin-bottom:20px; }
    .carousel-dots {
      display:flex; gap:12px; margin-top:40px;
    }
    .dot-carousel {
      width:10px; height:10px; border-radius:50%; background:var(--border);
      cursor:pointer; transition:all 0.2s;
    }
    .dot-carousel.active { background:var(--accent); width:28px; border-radius:20px; }

    .auth-right {
      flex:1 1 50%; display:flex; align-items:center; justify-content:center;
      padding:40px 20px; background: rgba(10,22,40,0.3);
    }
    .auth-card {
      width:100%; max-width:440px; background:rgba(15,42,68,0.5); backdrop-filter:blur(16px);
      border:1px solid var(--border); border-radius:32px; padding:40px;
      box-shadow:0 30px 60px rgba(0,0,0,0.5);
    }
    .auth-card h2 { font-size:28px; margin-bottom:8px; }
    .auth-card .free-trial { font-size:14px; color:var(--muted); margin-bottom:24px; }
    .google-btn {
      width:100%; background:white; border:none; border-radius:60px;
      padding:14px 20px; font-weight:600; color:#333; display:flex;
      align-items:center; justify-content:center; gap:12px; font-size:16px;
      cursor:pointer; border:1px solid var(--border); transition:0.2s;
      margin-bottom:28px; background:#fff;
    }
    .google-btn:hover { background:#f0f0f0; }
    .divider {
      display:flex; align-items:center; gap:12px; color:var(--muted); font-size:12px;
      margin-bottom:28px;
    }
    .divider-line { height:1px; background:var(--border); flex:1; }
    .auth-form .form-row { display:flex; gap:12px; margin-bottom:16px; }
    .auth-form .input-group { flex:1; margin-bottom:16px; }
    .auth-form label { display:block; font-size:12px; font-weight:600; margin-bottom:6px; color:var(--muted); letter-spacing:0.5px; }
    .auth-form input {
      width:100%; background:var(--input-bg); border:1px solid var(--border);
      border-radius:14px; padding:14px 18px; font-size:15px; color:white;
      outline:none; transition:0.2s;
    }
    .auth-form input:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(95,209,255,0.15); }
    .auth-form .btn-submit {
      width:100%; background:linear-gradient(135deg,var(--accent),#38bdf8);
      border:none; border-radius:60px; padding:16px; font-weight:700;
      font-size:16px; color:var(--navy); font-family:'Syne',sans-serif;
      margin:16px 0 20px; cursor:pointer; transition:0.2s; box-shadow:0 0 20px rgba(95,209,255,0.3);
    }
    .auth-form .btn-submit:hover { transform:translateY(-2px); box-shadow:0 0 40px rgba(95,209,255,0.5); }
    .terms { font-size:12px; color:var(--muted); text-align:center; }
    .terms a { color:var(--accent); text-decoration:none; }
    .login-link { text-align:center; margin-top:20px; font-size:14px; }
    .login-link a { color:var(--accent); text-decoration:none; font-weight:600; }
    .error-msg { background:rgba(239,68,68,0.2); border:1px solid #ef4444; border-radius:10px; padding:10px; color:#fecaca; font-size:13px; margin-bottom:16px; }

    @keyframes fadeCarousel { from{opacity:0.3;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
    @media (max-width:780px){ .auth-split{flex-direction:column;} .auth-left{border-right:none;border-bottom:1px solid var(--border);} }
  </style>
</head>
<body>
<div class="mesh-bg"></div>
<!-- minimal nav (same as index) -->
<nav>
  <a href="index.html" class="nav-brand">🎬 <span class="brand-vidora">Vidora</span><span class="brand-easy">Easy</span></a>
  <div class="nav-links">
    <a href="index.html">Home</a>
    <a href="login.php" class="nav-cta">Log In</a>
  </div>
</nav>

<!-- *************** REGISTER PAGE (split with carousel) *************** -->
<div id="register-page" style="display: block;">
  <div class="auth-split">
    <!-- left promotional carousel (exactly like pictory) -->
    <div class="auth-left">
      <div class="carousel-container">
        <div class="carousel-slide active" id="slide1">
          <div class="carousel-icon">🎬</div>
          <h2>AI that <span>creates</span> your reels</h2>
          <p>From script to schedule in minutes. Choose your topic, AI writes & builds scenes.</p>
        </div>
        <div class="carousel-slide" id="slide2">
          <div class="carousel-icon">🌐</div>
          <h2>Translate & <span>reach millions</span></h2>
          <p>One video, 6 languages. Culturally adapted, not just translated.</p>
        </div>
        <div class="carousel-slide" id="slide3">
          <div class="carousel-icon">📅</div>
          <h2>Auto‑schedule & <span>publish</span></h2>
          <p>Set it once, VidoraEasy posts at peak times – Instagram, TikTok, Shorts.</p>
        </div>
        <div class="carousel-dots">
          <span class="dot-carousel active" onclick="showSlide(0)"></span>
          <span class="dot-carousel" onclick="showSlide(1)"></span>
          <span class="dot-carousel" onclick="showSlide(2)"></span>
        </div>
      </div>
    </div>

    <!-- right signup form -->
    <div class="auth-right">
      <div class="auth-card">
        <h2>Start your FREE trial</h2>
        <div class="free-trial">No credit card required</div>

        <!-- Google login button (mock) you can replace # with Google OAuth later -->
        <button class="google-btn" onclick="window.location='#'">
          <img src="data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z' fill='%234285F4'/%3E%3Cpath d='M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z' fill='%2334A853'/%3E%3Cpath d='M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z' fill='%23FBBC05'/%3E%3Cpath d='M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z' fill='%23EA4335'/%3E%3C/svg%3E" style="width:20px; height:20px;" alt="google">
          Continue with Google
        </button>

        <div class="divider"><span class="divider-line"></span> or <span class="divider-line"></span></div>

        <!-- REGISTRATION FORM (submits to register.php itself) -->
        <form class="auth-form" method="POST" action="register.php">
          <div class="form-row">
            <div class="input-group">
              <label>First name</label>
              <input type="text" name="first_name" required placeholder="John">
            </div>
            <div class="input-group">
              <label>Last name</label>
              <input type="text" name="last_name" required placeholder="Doe">
            </div>
          </div>
          <div class="input-group">
            <label>Email address</label>
            <input type="email" name="email" required placeholder="hello@domain.com">
          </div>
          <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••" minlength="6">
          </div>
          <!-- optional trial plan field (default 'free_trial') -->
          <input type="hidden" name="plan" value="free_trial">
          <button type="submit" name="register_submit" class="btn-submit">Create free account →</button>
          <div class="terms">
            By signing up you agree to our <a href="#">Terms</a> and <a href="#">Privacy Policy</a>.
          </div>
        </form>
        <div class="login-link">
          Already have an account? <a href="login.php">Log in</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- *************** LOGIN PAGE (simple centered card, same style) *************** -->
<div id="login-page" style="display: none;">  <!-- hidden by default, but login.php will show it -->
  <div class="auth-split" style="justify-content:center;">
    <div class="auth-right" style="flex:unset; width:100%; max-width:500px; background:transparent;">
      <div class="auth-card" style="max-width:440px;">
        <h2>Welcome back</h2>
        <div class="free-trial">Log in to your VidoraEasy account</div>

        <button class="google-btn" onclick="window.location='#'">
          <img src="data:image/svg+xml,%3Csvg width='20' height='20' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z' fill='%234285F4'/%3E%3Cpath d='M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z' fill='%2334A853'/%3E%3Cpath d='M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z' fill='%23FBBC05'/%3E%3Cpath d='M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z' fill='%23EA4335'/%3E%3C/svg%3E" style="width:20px;" alt="google">
          Log in with Google
        </button>
        <div class="divider"><span class="divider-line"></span> or <span class="divider-line"></span></div>

        <form class="auth-form" method="POST" action="login.php">
          <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" required placeholder="hello@domain.com">
          </div>
          <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">
          </div>
          <div class="input-group" style="display: flex; align-items: center; gap: 10px;">
            <input type="checkbox" name="remember_me" id="remember_me" style="width: auto;">
            <label for="remember_me" style="margin-bottom: 0;">Remember me for 30 days</label>
          </div>
          <button type="submit" name="login_submit" class="btn-submit">Log in →</button>
        </form>
        <div class="login-link">
          Don't have an account? <a href="register.php">Start free trial</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PHP BACKEND -->
<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'dbconnect_hdb.php';



// ----- REGISTER LOGIC -----
// (Your register logic would go here if you have it)

// ----- LOGIN LOGIC WITH DEBUGGING -----
if (isset($_POST['login_submit'])) 
{
    echo "<div style='background:blue; color:white; padding:10px; position:fixed; top:10px; right:10px; z-index:9999;'>DEBUG: Login form submitted</div>";
    
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;
    
    echo "<div style='background:blue; color:white; padding:10px; position:fixed; top:50px; right:10px; z-index:9999;'>";
    echo "Login attempt with email: $email<br>";
    echo "Password entered: $password<br>";
    echo "Remember me: " . ($remember_me ? 'Yes' : 'No');
    echo "</div>";
    
    $errors = [];

    if (empty($email)) $errors[] = "Email is required.";
    if (empty($password)) $errors[] = "Password is required.";

    if (empty($errors)) {
        // Escape email
        $email = mysqli_real_escape_string($conn, $email);
        
        // Try different column combinations for email
        $queries = [
            "SELECT * FROM hdb_users WHERE email_id = '$email' LIMIT 1",
            "SELECT * FROM hdb_users WHERE email = '$email' LIMIT 1",
            "SELECT * FROM hdb_users WHERE user_name = '$email' LIMIT 1"
        ];
        
        $user_found = false;
        $user_data = null;
        
        foreach ($queries as $sql) {
            $result = mysqli_query($conn, $sql);
            if ($result && mysqli_num_rows($result) > 0) {
                $user_found = true;
                $user_data = mysqli_fetch_assoc($result);
                break;
            }
        }
        
        if ($user_found && $user_data) {
            // SIMPLE PLAIN TEXT COMPARISON - NO ENCRYPTION
            if ($user_data['password'] === $password) {
                
                echo "<div style='background:green; color:white; padding:10px; position:fixed; top:600px; right:10px; z-index:9999;'>✓ PASSWORD VERIFIED SUCCESSFULLY</div>";
                
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                
                // Set session to last 30 days
                $timeout = 30 * 24 * 60 * 60;
                ini_set('session.gc_maxlifetime', $timeout);
                session_set_cookie_params($timeout);
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user_data['id'];
                $_SESSION['firstname'] = isset($user_data['firstname']) ? $user_data['firstname'] : (isset($user_data['first_name']) ? $user_data['first_name'] : '');
                $_SESSION['lastname'] = isset($user_data['lastname']) ? $user_data['lastname'] : (isset($user_data['last_name']) ? $user_data['last_name'] : '');
                $_SESSION['company_id'] = isset($user_data['company_id']) ? $user_data['company_id'] : 0;
                $_SESSION['email'] = $email;
                $_SESSION['logged_in'] = true;
                
                // Admin specific variables
                $_SESSION['admin_id'] = $user_data['id'];
                $_SESSION['level'] = isset($user_data['level_name']) ? $user_data['level_name'] : 'user';
                $_SESSION['client_id'] = isset($user_data['client_id']) ? $user_data['client_id'] : 0;
                $_SESSION['created_at'] = time();
                
                // Debug: Check if session variables are set
                echo "<div style='background:yellow; color:black; padding:10px; position:fixed; top:650px; right:10px; z-index:9999;'>";
                echo "Session variables set:<br>";
                echo "admin_id: " . $_SESSION['admin_id'] . "<br>";
                echo "user_id: " . $_SESSION['user_id'] . "<br>";
                echo "level: " . $_SESSION['level'] . "<br>";
                echo "client_id: " . $_SESSION['client_id'] . "<br>";
                echo "</div>";
                
                // Set long-term cookies if "Remember Me" is checked
                if ($remember_me) {
                    $cookie_expiry = time() + (86400 * 30); // 30 days
                    $cookie_path = '/';
                    $cookie_domain = ''; // Set to your domain if needed
                    $cookie_secure = isset($_SERVER['HTTPS']); // Only send over HTTPS
                    $cookie_httponly = true; // Prevent JavaScript access
                    
                    // Set cookies for auto-login
                    setcookie('user_id', $user_data['id'], $cookie_expiry, $cookie_path, $cookie_domain, $cookie_secure, $cookie_httponly);
                    setcookie('user_email', $email, $cookie_expiry, $cookie_path, $cookie_domain, $cookie_secure, $cookie_httponly);
                    setcookie('user_logged_in', '1', $cookie_expiry, $cookie_path, $cookie_domain, $cookie_secure, false);
                    
                    // Also set the session cookie to last longer
                    setcookie(session_name(), session_id(), time() + (30 * 24 * 60 * 60), "/");
                }
                
                // Redirect to home page
                echo "<script>
                    alert('Login successful! Welcome back!');
                    window.location.href = 'vidora_home.php';
                </script>";
                exit;
            } else {
                $errors[] = "Password verification failed.";
                echo "<div style='background:red; color:white; padding:10px; position:fixed; top:600px; right:10px; z-index:9999;'>✗ PASSWORD VERIFICATION FAILED</div>";
            }
        } else {
            $errors[] = "No user found with that email.";
        }
    }

    if (!empty($errors)) {
        echo '<div style="position:fixed; top:650px; right:10px; background:rgba(239,68,68,0.9); padding:20px; border-radius:10px; color:white; z-index:9999; font-size:14px;">';
        echo '<strong>Login Errors:</strong><br>';
        foreach ($errors as $e) {
            echo '• ' . htmlspecialchars($e) . '<br>';
        }
        echo '</div>';
    }
}

// Close connection
mysqli_close($conn);

?>

<!-- tiny carousel script -->
<script>
let currentSlide = 0;
const slides = document.querySelectorAll('.carousel-slide');
const dots = document.querySelectorAll('.dot-carousel');

function showSlide(index) {
  slides.forEach((s,i) => {
    s.classList.toggle('active', i === index);
    dots[i]?.classList.toggle('active', i === index);
  });
  currentSlide = index;
}
// Auto rotate every 5 sec
setInterval(() => {
  let next = (currentSlide + 1) % slides.length;
  showSlide(next);
}, 5000);

// simple toggle for login/register page (based on filename)
(function setActivePage() {
  const path = window.location.pathname;
  const regDiv = document.getElementById('register-page');
  const loginDiv = document.getElementById('login-page');
  if (path.includes('login.php')) {
    regDiv.style.display = 'none';
    loginDiv.style.display = 'block';
  } else {
    regDiv.style.display = 'block';
    loginDiv.style.display = 'none';
  }
})();
</script>

<?php
// AUTO-LOGIN VIA COOKIES (add this at the very top of vidora_home.php)
/*
// At the top of vidora_home.php, add this code:
session_start();

// Check if user is not logged in via session but has remember me cookies
if (!isset($_SESSION['user_id']) && isset($_COOKIE['user_id']) && isset($_COOKIE['remember_token'])) {
    include 'dbconnect_hdb.php';
    
    $user_id = mysqli_real_escape_string($conn, $_COOKIE['user_id']);
    $token = mysqli_real_escape_string($conn, $_COOKIE['remember_token']);
    
    // Verify token in database
    $sql = "SELECT u.*, rt.token FROM hdb_users u 
            JOIN user_remember_tokens rt ON u.id = rt.user_id 
            WHERE u.id = '$user_id' AND rt.token = '$token' AND rt.expires > NOW()";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user_data = mysqli_fetch_assoc($result);
        
        // Restore session
        $_SESSION['user_id'] = $user_data['id'];
        $_SESSION['firstname'] = isset($user_data['firstname']) ? $user_data['firstname'] : $user_data['first_name'];
        $_SESSION['lastname'] = isset($user_data['lastname']) ? $user_data['lastname'] : $user_data['last_name'];
        $_SESSION['company_id'] = $user_data['company_id'];
        $_SESSION['email'] = $user_data['email_id'] ?? $user_data['email'];
        $_SESSION['logged_in'] = true;
    }
    
    mysqli_close($conn);
}
*/
?>
</body>
</html>