<?php
session_start();
include 'dbconnect_hdb.php';
include 'brevo_new_api.php'; // Your email function file

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // Check if email exists in database
    $sql = "SELECT * FROM hdb_users WHERE email = '$email' LIMIT 1";
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Generate unique token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store token in database
        $update_sql = "UPDATE hdb_users 
                       SET reset_token = '$token', 
                           reset_expires = '$expires' 
                       WHERE id = '{$user['id']}'";
        
        if (mysqli_query($conn, $update_sql)) {
            // Send reset email
            $reset_link = "https://yourdomain.com/reset-password.php?token=" . $token;
            
            $subject = "Reset Your VideoVizard Password";
            
            // HTML Email Template
            $htmlContent = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <style>
                    body { font-family: "DM Sans", Arial, sans-serif; background: #0a1628; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
                    .card { background: rgba(15, 42, 68, 0.9); backdrop-filter: blur(20px); border: 1px solid rgba(95,209,255,0.15); border-radius: 24px; padding: 40px; }
                    .logo { font-family: "Syne", sans-serif; font-size: 28px; font-weight: 800; text-align: center; margin-bottom: 30px; }
                    .logo span { color: #5fd1ff; }
                    h2 { color: #ffffff; font-family: "Syne", sans-serif; font-size: 24px; margin-bottom: 20px; }
                    p { color: rgba(255,255,255,0.7); line-height: 1.6; margin-bottom: 30px; }
                    .btn { display: inline-block; background: linear-gradient(135deg, #5fd1ff, #38bdf8); color: #0f2a44; text-decoration: none; padding: 16px 32px; border-radius: 50px; font-weight: 700; margin: 20px 0; }
                    .footer { color: rgba(255,255,255,0.4); font-size: 12px; text-align: center; margin-top: 30px; }
                </style>
            </head>
            <body style="background: #0a1628;">
                <div class="container">
                    <div class="card">
                        <div class="logo">
                            🎬 Video<span style="color: #5fd1ff;">Vizard</span>
                        </div>
                        <h2>Password Reset Request</h2>
                        <p>Hello ' . htmlspecialchars($user['user_name']) . ',</p>
                        <p>We received a request to reset your password for your VideoVizard account. Click the button below to set a new password. This link will expire in 1 hour.</p>
                        <p style="text-align: center;">
                            <a href="' . $reset_link . '" class="btn" style="color: #0f2a44; text-decoration: none;">Reset Password</a>
                        </p>
                        <p>If you didn\'t request this, you can safely ignore this email.</p>
                        <p>For security, never share this link with anyone.</p>
                        <div class="footer">
                            <p>© 2025 VideoVizard. All rights reserved.</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>';
            
            // Send email using your function
            $email_result = sendFormattedEmail(
                $email,
                $user['user_name'],
                $subject,
                $htmlContent
            );
            
            if ($email_result['success']) {
                $success_message = "Password reset instructions have been sent to your email.";
            } else {
                $error_message = "Failed to send email. Please try again.";
                error_log("Email sending failed: " . ($email_result['error'] ?? 'Unknown error'));
            }
        } else {
            $error_message = "System error. Please try again later.";
        }
    } else {
        // Don't reveal if email exists or not for security
        $success_message = "If this email exists in our system, you'll receive reset instructions.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard - Forgot Password</title>
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

.mesh-bg::before {
    content: '';
    position: absolute;
    width: 800px;
    height: 800px;
    top: -200px;
    right: -200px;
    background: radial-gradient(circle, rgba(95,209,255,0.12) 0%, transparent 70%);
    animation: float1 8s ease-in-out infinite;
}

.mesh-bg::after {
    content: '';
    position: absolute;
    width: 600px;
    height: 600px;
    bottom: 10%;
    left: -100px;
    background: radial-gradient(circle, rgba(16,185,129,0.08) 0%, transparent 70%);
    animation: float2 10s ease-in-out infinite;
}

@keyframes float1 {
    0%,100% { transform: translate(0,0); }
    50% { transform: translate(-30px,30px); }
}

@keyframes float2 {
    0%,100% { transform: translate(0,0); }
    50% { transform: translate(30px,-20px); }
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
    -webkit-backdrop-filter: blur(20px);
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
    font-size: 14px;
    animation: slideDown 0.3s ease;
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
    font-size: 14px;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Form */
.form-group {
    margin-bottom: 24px;
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
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.25s;
    box-shadow: 0 0 30px rgba(95, 209, 255, 0.3);
    margin: 8px 0 16px;
}

.reset-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 0 40px rgba(95, 209, 255, 0.5);
}

.back-link {
    text-align: center;
    margin-top: 24px;
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
        <h2>Forgot Password?</h2>
        <div class="card-sub">No worries, we'll send you reset instructions</div>

        <?php if(!empty($error_message)): ?>
        <div class="error-message">
            <span>⚠️</span>
            <span><?php echo $error_message; ?></span>
        </div>
        <?php endif; ?>

        <?php if(!empty($success_message)): ?>
        <div class="success-message">
            <span>✓</span>
            <span><?php echo $success_message; ?></span>
        </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon">📧</span>
                    <input type="email" 
                           name="email" 
                           placeholder="Enter your email address" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                           required>
                </div>
            </div>

            <button type="submit" class="reset-btn">
                Send Reset Instructions <span style="font-size: 18px;">→</span>
            </button>

            <div class="back-link">
                <a href="login.php">← Back to Login</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>