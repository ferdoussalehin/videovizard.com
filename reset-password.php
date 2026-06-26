<?php
session_start();
include 'dbconnect_hdb.php';

$error_message = '';
$success_message = '';
$token_valid = false;
$email = '';

// Check if token is provided
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    
    // Verify token
    $sql = "SELECT * FROM hdb_users 
            WHERE reset_token = '$token' 
            AND reset_expires > NOW() 
            LIMIT 1";
    
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        $token_valid = true;
        $email = $user['email'];
    } else {
        $error_message = "Invalid or expired reset link. Please request a new one.";
    }
}

// Handle password reset
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset'])) {
    $token = mysqli_real_escape_string($conn, $_POST['token']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password
    if (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Verify token again
        $sql = "SELECT * FROM hdb_users 
                WHERE reset_token = '$token' 
                AND reset_expires > NOW() 
                LIMIT 1";
        
        $result = mysqli_query($conn, $sql);
        
        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);
            
            // Update password and clear token
            $hashed_password = $password; // You should hash this properly!
            $update_sql = "UPDATE hdb_users 
                          SET password = '$hashed_password',
                              reset_token = NULL,
                              reset_expires = NULL 
                          WHERE id = '{$user['id']}'";
            
            if (mysqli_query($conn, $update_sql)) {
                $success_message = "Password has been reset successfully! You can now login with your new password.";
            } else {
                $error_message = "Failed to reset password. Please try again.";
            }
        } else {
            $error_message = "Invalid or expired reset link. Please request a new one.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard - Reset Password</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root {
    --dark: #0a1628;
    --navy: #0f2a44;
    --blue: #143b63;
    --accent: #5fd1ff;
    --accent2: #38bdf8;
    --green: #10b981;
    --white: #ffffff;
    --muted: rgba(255,255,255,0.55);
    --border: rgba(95,209,255,0.15);
    --error: #ef4444;
    --success: #10b981;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'DM Sans', sans-serif;
    background: var(--dark);
    color: var(--white);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow-x: hidden;
}

/* Background effects (same as login) */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 0;
    opacity: 0.4;
}

.mesh-bg {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}

.mesh-bg::before,
.mesh-bg::after {
    content: '';
    position: absolute;
    background: radial-gradient(circle, rgba(95,209,255,0.12) 0%, transparent 70%);
    animation: float 8s ease-in-out infinite;
}

.mesh-bg::before {
    width: 800px;
    height: 800px;
    top: -200px;
    right: -200px;
}

.mesh-bg::after {
    width: 600px;
    height: 600px;
    bottom: 10%;
    left: -100px;
    background: radial-gradient(circle, rgba(16,185,129,0.08) 0%, transparent 70%);
}

@keyframes float {
    0%,100% { transform: translate(0,0); }
    50% { transform: translate(-30px,30px); }
}

.container {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 440px;
    padding: 20px;
    animation: fadeUp 0.6s ease-out;
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.brand-header {
    text-align: center;
    margin-bottom: 30px;
}

.brand-link {
    font-family: 'Syne', sans-serif;
    font-size: 28px;
    font-weight: 800;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: var(--white);
}

.brand-video { color: var(--white); }
.brand-vizard { color: var(--accent); }

.card {
    background: rgba(15, 42, 68, 0.6);
    backdrop-filter: blur(20px);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 40px 32px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.card h2 {
    font-family: 'Syne', sans-serif;
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--white);
    text-align: center;
}

.card-sub {
    text-align: center;
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 32px;
}

/* Messages */
.success-message {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--success);
}

.error-message {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--error);
}

/* Form */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--accent);
    margin-bottom: 8px;
}

.input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 16px;
    color: var(--muted);
    font-size: 16px;
}

input {
    width: 100%;
    padding: 14px 16px 14px 48px;
    background: rgba(10, 22, 40, 0.6);
    border: 1px solid var(--border);
    border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    color: var(--white);
    transition: all 0.2s ease;
}

input:focus {
    outline: none;
    border-color: var(--accent);
    background: rgba(10, 22, 40, 0.8);
    box-shadow: 0 0 0 4px rgba(95, 209, 255, 0.1);
}

input::placeholder {
    color: rgba(255, 255, 255, 0.25);
}

.password-hint {
    font-size: 12px;
    color: var(--muted);
    margin-top: 4px;
}

/* Button */
.reset-btn {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border: none;
    border-radius: 50px;
    color: var(--navy);
    font-family: 'Syne', sans-serif;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.25s;
    box-shadow: 0 0 30px rgba(95, 209, 255, 0.3);
    margin: 20px 0 16px;
}

.reset-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 40px rgba(95, 209, 255, 0.5);
}

.back-link {
    text-align: center;
}

.back-link a {
    color: var(--accent);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.back-link a:hover {
    color: var(--white);
}

/* Invalid token state */
.invalid-token {
    text-align: center;
}

.invalid-token .icon {
    font-size: 48px;
    margin-bottom: 20px;
}

.invalid-token h3 {
    font-family: 'Syne', sans-serif;
    font-size: 20px;
    margin-bottom: 12px;
}

.invalid-token p {
    color: var(--muted);
    margin-bottom: 24px;
}

/* Responsive */
@media (max-width: 480px) {
    .container { padding: 16px; }
    .card { padding: 32px 20px; }
    .brand-link { font-size: 24px; }
}
</style>
</head>
<body>

<div class="mesh-bg"></div>

<div class="container">
    <div class="brand-header">
        <a href="index.php" class="brand-link">
            🎬 <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
        </a>
    </div>

    <div class="card">
        <?php if (!empty($success_message)): ?>
            <h2>Password Reset!</h2>
            <div class="success-message">
                <span>✓</span>
                <span><?php echo $success_message; ?></span>
            </div>
            <div class="back-link">
                <a href="login.php">← Go to Login</a>
            </div>

        <?php elseif (!$token_valid && !empty($error_message)): ?>
            <div class="invalid-token">
                <div class="icon">🔒</div>
                <h3>Invalid Reset Link</h3>
                <p><?php echo $error_message; ?></p>
                <a href="forgot-password.php" class="reset-btn" style="text-decoration: none; display: inline-block; width: auto; padding: 12px 24px;">Request New Link</a>
                <div class="back-link" style="margin-top: 16px;">
                    <a href="login.php">← Back to Login</a>
                </div>
            </div>

        <?php elseif ($token_valid): ?>
            <h2>Set New Password</h2>
            <div class="card-sub">Create a new password for your account</div>

            <?php if(!empty($error_message)): ?>
            <div class="error-message">
                <span>⚠️</span>
                <span><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">
                
                <div class="form-group">
                    <label>New Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" 
                               name="password" 
                               placeholder="Enter new password"
                               minlength="6"
                               required>
                    </div>
                    <div class="password-hint">Minimum 6 characters</div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✓</span>
                        <input type="password" 
                               name="confirm_password" 
                               placeholder="Confirm new password"
                               minlength="6"
                               required>
                    </div>
                </div>

                <button type="submit" name="reset" class="reset-btn">
                    Reset Password
                </button>

                <div class="back-link">
                    <a href="login.php">← Back to Login</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>