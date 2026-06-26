<?php
session_start();

// Check if user came from successful sponsorship
if (!isset($_SESSION['sponsorship_success']) || !$_SESSION['sponsorship_success']) {
    header("Location: community_browse.php");
    exit();
}

$donation_id = $_SESSION['donation_id'] ?? '';
$recipient_name = $_SESSION['recipient_name'] ?? '';
$amount = $_SESSION['amount'] ?? 0;

// Clear session variables
unset($_SESSION['sponsorship_success']);
unset($_SESSION['donation_id']);
unset($_SESSION['recipient_name']);
unset($_SESSION['amount']);

include 'logo_component.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Your Donation - StressReleasor Community</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f8f9ff;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .nav-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .nav-links {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-links a:hover {
            color: #667eea;
        }

        .btn-nav {
            background: #667eea;
            color: white !important;
            padding: 10px 20px;
            border-radius: 8px;
            transition: background 0.3s;
        }

        /* Success Section */
        .success-section {
            padding: 140px 0 80px;
            min-height: 100vh;
        }

        .success-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 60px 50px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            text-align: center;
        }

        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 1s ease;
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        .success-title {
            font-size: 36px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 15px;
        }

        .success-subtitle {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }

        .donation-details {
            background: #f0f4ff;
            padding: 30px;
            border-radius: 15px;
            margin: 30px 0;
            text-align: left;
        }

        .donation-details h3 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid rgba(102, 126, 234, 0.1);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #666;
            font-weight: 500;
        }

        .detail-value {
            color: #333;
            font-weight: 700;
        }

        .impact-box {
            background: #fff4e6;
            border-left: 4px solid #f39c12;
            padding: 25px;
            margin: 30px 0;
            border-radius: 8px;
            text-align: left;
        }

        .impact-box h3 {
            color: #e67e22;
            margin-bottom: 15px;
            font-size: 20px;
        }

        .impact-box p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 15px 35px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            font-size: 16px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-secondary:hover {
            background: #f8f9ff;
        }

        .social-share {
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid #e0e0e0;
        }

        .social-share h4 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .social-share p {
            color: #666;
            font-size: 14px;
        }

        /* Footer */
        .footer {
            background: #1a1a2e;
            color: white;
            padding: 60px 0 20px;
            margin-top: 80px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 40px;
        }

        .footer-brand p {
            opacity: 0.8;
            margin-top: 15px;
        }

        .footer-column h4 {
            margin-bottom: 20px;
            color: #667eea;
        }

        .footer-column a {
            display: block;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            margin-bottom: 10px;
            transition: color 0.3s;
        }

        .footer-column a:hover {
            color: white;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
            opacity: 0.6;
        }

        @media (max-width: 768px) {
            .success-container {
                padding: 40px 30px;
            }

            .success-title {
                font-size: 28px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }

            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <?php echo logo('nav', 'index.php'); ?>
                <div class="nav-links">
                    <a href="index.php">Home</a>
                    <a href="community.php">Community</a>
                    <a href="stressreleasor.php" class="btn-nav">Get Started</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Success Section -->
    <section class="success-section">
        <div class="container">
            <div class="success-container">
                <div class="success-icon">💙</div>
                <h1 class="success-title">You Just Changed a Life!</h1>
                <p class="success-subtitle">
                    Thank you for your incredible generosity. <?php echo htmlspecialchars($recipient_name); ?> can now access their session and begin healing.
                </p>

                <div class="donation-details">
                    <h3>Your Donation Details</h3>
                    <div class="detail-row">
                        <span class="detail-label">Amount Donated:</span>
                        <span class="detail-value">$<?php echo htmlspecialchars($amount); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Recipient:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($recipient_name); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Session Type:</span>
                        <span class="detail-value"><?php echo $amount == 2 ? 'AI-Powered Audio Session' : 'Live 1-on-1 Session'; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date:</span>
                        <span class="detail-value"><?php echo date('F j, Y'); ?></span>
                    </div>
                    <?php if ($donation_id): ?>
                    <div class="detail-row">
                        <span class="detail-label">Donation ID:</span>
                        <span class="detail-value">#<?php echo htmlspecialchars($donation_id); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="impact-box">
                    <h3>The Impact You Made</h3>
                    <p>✓ <?php echo htmlspecialchars($recipient_name); ?> received an email notification</p>
                    <p>✓ Their session access is now active and ready to use</p>
                    <p>✓ They can send you a thank-you message</p>
                    <p>✓ You've created a ripple of kindness that will inspire others</p>
                    <p>✓ A receipt has been sent to your email</p>
                </div>

                <p style="color: #666; margin: 20px 0; font-size: 15px;">
                    Today's recipient could become tomorrow's donor. You're not just helping one person—you're building a community where everyone lifts each other up.
                </p>

                <div class="action-buttons">
                    <a href="community_browse.php" class="btn btn-primary">Help Someone Else</a>
                    <a href="community.php" class="btn btn-secondary">Back to Community</a>
                </div>

                <div class="social-share">
                    <h4>Inspire Others</h4>
                    <p>Share this moment and encourage your friends to join our community of kindness.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-brand">
                    <?php echo logo('footer', 'index.php'); ?>
                    <p>Created with care by Inam Alvi</p>
                </div>
                <div class="footer-links">
                    <div class="footer-column">
                        <h4>Resources</h4>
                        <a href="index.php#how-it-works">How It Works</a>
                        <a href="about-inam.php">About Inam</a>
                        <a href="blog.php">Blog</a>
                        <a href="community.php">Community</a>
                    </div>
                    <div class="footer-column">
                        <h4>Legal</h4>
                        <a href="privacy.html">Privacy Policy</a>
                        <a href="terms.html">Terms of Service</a>
                        <a href="contact.html">Contact</a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 StressReleasor.com. All rights reserved.</p>
            </div>
        </div>
    </footer>
</body>
</html>
