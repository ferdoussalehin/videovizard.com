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
          <button type="submit" name="login_submit" class="btn-submit">Log in →</button>
        </form>
        <div class="login-link">
          Don't have an account? <a href="register.php">Start free trial</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- PHP BACKEND (embedded in same file for demo, but you can separate) -->

<?php
// Enable error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// mysqli connection credentials (CHANGE TO YOUR OWN)
include 'dbconnect_hdb.php';

// Set charset to UTF-8
$conn->set_charset("utf8mb4");



// ----- REGISTER LOGIC -----
if (isset($_POST['register_submit'])) {
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $plan       = $_POST['plan']; // free_trial etc

    $errors = [];

    // Basic validation
    if (empty($first_name)) {
        $errors[] = "First name is required.";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    // Check if email already exists in hdb_users (using email_id column)
    if (empty($errors)) {
        $check_sql = "SELECT id FROM hdb_users WHERE email_id = ?";
        $stmt = $conn->prepare($check_sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email already registered.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        // Hash password
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert into hdb_users with correct column names
            $insert_user = "INSERT INTO hdb_users (firstname, lastname, email_id, password, plan_type, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($insert_user);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("sssss", $first_name, $last_name, $email, $hashed, $plan);
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            $user_id = $stmt->insert_id;
            $stmt->close();

            // Create default company in hdb_companies
            $company_name = $first_name . "'s Company";
            $insert_comp = "INSERT INTO hdb_companies (companyname, user_id, created_at) VALUES (?, ?, NOW())";
            $stmt2 = $conn->prepare($insert_comp);
            if (!$stmt2) {
                throw new Exception("Prepare failed for company: " . $conn->error);
            }
            $stmt2->bind_param("si", $company_name, $user_id);
            if (!$stmt2->execute()) {
                throw new Exception("Execute failed for company: " . $stmt2->error);
            }
            $company_id = $stmt2->insert_id;
            $stmt2->close();

            // Update user with company_id (if the column exists)
            // First check if company_id column exists
            $check_column = "SHOW COLUMNS FROM hdb_users LIKE 'company_id'";
            $column_result = $conn->query($check_column);
            if ($column_result->num_rows > 0) {
                $update_user = "UPDATE hdb_users SET company_id = ? WHERE id = ?";
                $stmt3 = $conn->prepare($update_user);
                if ($stmt3) {
                    $stmt3->bind_param("ii", $company_id, $user_id);
                    $stmt3->execute();
                    $stmt3->close();
                }
            }

            // Commit transaction
            $conn->commit();

            // Success message and redirect
            echo "<script>
                alert('Registration successful! Please log in.');
                window.location.href = 'login.php';
            </script>";
            exit;

        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $errors[] = "Database error: " . $e->getMessage();
            
            // Log error for debugging
            error_log("Registration error: " . $e->getMessage());
        }
    }

    // Display errors if any
    if (!empty($errors)) {
        echo '<div style="position:fixed; top:90px; left:50%; transform:translateX(-50%); background:rgba(239,68,68,0.9); padding:12px 24px; border-radius:60px; color:white; z-index:200; font-size:14px; box-shadow:0 4px 15px rgba(0,0,0,0.3);">';
        foreach ($errors as $e) {
            echo '• ' . htmlspecialchars($e) . '<br>';
        }
        echo '</div>';
    }
}

// ----- LOGIN LOGIC -----
if (isset($_POST['login_submit'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    $errors = [];

    if (empty($email)) {
        $errors[] = "Email is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    if (empty($errors)) {
        // Query using email_id column and correct field names
        $sql = "SELECT id, firstname, lastname, password, company_id FROM hdb_users WHERE email_id = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password'])) {
                    // Start session if not already started
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    
                    // Store user data in session
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['firstname'] = $row['firstname'];
                    $_SESSION['lastname'] = $row['lastname'];
                    $_SESSION['company_id'] = $row['company_id'];
                    $_SESSION['email'] = $email;
                    
                    // Redirect to dashboard
                    echo "<script>
                        alert('Login successful! Welcome back, " . addslashes($row['firstname']) . "!');
                        window.location.href = 'index.html';
                    </script>";
                    exit;
                } else {
                    $errors[] = "Invalid email or password.";
                }
            } else {
                $errors[] = "Invalid email or password.";
            }
            $stmt->close();
        } else {
            $errors[] = "Database error occurred.";
        }
    }

    // Display login errors
    if (!empty($errors)) {
        echo '<div style="position:fixed; top:90px; left:50%; transform:translateX(-50%); background:rgba(239,68,68,0.9); padding:12px 24px; border-radius:60px; color:white; z-index:200; font-size:14px; box-shadow:0 4px 15px rgba(0,0,0,0.3);">';
        foreach ($errors as $e) {
            echo '• ' . htmlspecialchars($e) . '<br>';
        }
        echo '</div>';
    }
}

// Close connection
$conn->close();
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
</body>
</html>