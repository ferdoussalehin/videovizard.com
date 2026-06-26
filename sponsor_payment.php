<?php
session_start();
include 'logo_component.php';
include 'dbconnect_hdb.php';
//echo "not valid";die; 
// Get appeal ID and amount
$appeal_id = intval($_GET['appeal_id'] ?? 0);
$amount = intval($_GET['amount'] ?? 2);

// Validate amount
if (!in_array($amount, [2, 29])) {
    $amount = 2;
}

// Get appeal details
$appeal = null;
if ($appeal_id > 0) {
    $query = "SELECT * FROM community_appeals WHERE id = " . $appeal_id . " AND status = 'waiting'";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $appeal = mysqli_fetch_assoc($result);
    }
}

// If appeal not found, redirect back
if (!$appeal) {
	echo "not valid";die;
    header("Location: community_browse.php");
    exit();
}

// Category display names
$category_names = [
    'work_stress' => 'Work & Career Stress',
    'relationship' => 'Relationship Issues',
    'anxiety' => 'Anxiety & Worry',
    'depression' => 'Depression & Low Mood',
    'sleep' => 'Sleep Problems',
    'trauma' => 'Trauma & PTSD',
    'grief' => 'Grief & Loss',
    'health' => 'Health Anxiety',
    'financial' => 'Financial Stress',
    'family' => 'Family Issues',
    'self_esteem' => 'Self-Esteem & Confidence',
    'life_transition' => 'Life Transitions',
    'anger' => 'Anger Management',
    'phobia' => 'Phobias & Fears',
    'addiction' => 'Addiction & Habits',
    'other' => 'Other'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sponsor <?php echo htmlspecialchars($appeal['name']); ?> - StressReleasor Community</title>
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

        /* Hero Section */
        .hero {
            padding: 120px 0 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }

        .hero-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }

        .hero-title {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 15px;
        }

        /* Main Content */
        .payment-section {
            padding: 60px 0;
        }

        .payment-container {
            max-width: 900px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .appeal-summary {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .summary-title {
            font-size: 22px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 20px;
        }

        .summary-category {
            background: #f0f4ff;
            color: #667eea;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 15px;
        }

        .summary-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .summary-location {
            color: #666;
            margin-bottom: 15px;
        }

        .summary-issue {
            background: #fff4e6;
            border-left: 4px solid #f39c12;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }

        .summary-text {
            color: #666;
            line-height: 1.7;
            margin: 20px 0;
            max-height: 200px;
            overflow-y: auto;
        }

        .payment-form {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .form-title {
            font-size: 22px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 20px;
        }

        .amount-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 30px;
        }

        .amount-option {
            border: 3px solid #e0e0e0;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .amount-option:hover {
            border-color: #667eea;
        }

        .amount-option.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .amount-value {
            font-size: 32px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 5px;
        }

        .amount-label {
            font-size: 14px;
            color: #666;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            min-height: 100px;
            resize: vertical;
        }

        .helper-text {
            font-size: 13px;
            color: #999;
            margin-top: 5px;
        }

        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 20px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .info-box {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-top: 20px;
            border-radius: 8px;
        }

        .info-box h4 {
            color: #667eea;
            margin-bottom: 10px;
        }

        .info-box ul {
            margin-left: 20px;
            color: #666;
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
            .payment-container {
                grid-template-columns: 1fr;
            }

            .hero-title {
                font-size: 28px;
            }

            .appeal-summary, .payment-form {
                padding: 30px 20px;
            }

            .footer-content {
                grid-template-columns: 1fr;
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
                    <a href="community_browse.php">← Back to Browse</a>
                    <a href="index.php">Home</a>
                    <a href="community.php">Community</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-icon">💙</div>
            <h1 class="hero-title">You're About to Change a Life</h1>
        </div>
    </section>

    <!-- Payment Section -->
    <section class="payment-section">
        <div class="container">
            <div class="payment-container">
                <!-- Appeal Summary -->
                <div class="appeal-summary">
                    <h2 class="summary-title">You're Sponsoring</h2>
                    
                    <span class="summary-category"><?php echo htmlspecialchars($category_names[$appeal['category']] ?? $appeal['category']); ?></span>
                    
                    <h3 class="summary-name"><?php echo htmlspecialchars($appeal['name']); ?>, <?php echo htmlspecialchars($appeal['age']); ?></h3>
                    <div class="summary-location">📍 <?php echo htmlspecialchars($appeal['city']); ?>, <?php echo htmlspecialchars($appeal['country']); ?></div>
                    
                    <div class="summary-issue">
                        <strong>Struggling with:</strong> <?php echo htmlspecialchars($appeal['issue']); ?>
                    </div>
                    
                    <div class="summary-text">
                        <?php echo nl2br(htmlspecialchars($appeal['appeal_text'])); ?>
                    </div>
                </div>

                <!-- Payment Form -->
                <div class="payment-form">
                    <h2 class="form-title">Complete Your Sponsorship</h2>
                    
                    <form id="sponsorForm" method="POST" action="process_sponsorship.php">
                        <input type="hidden" name="appeal_id" value="<?php echo $appeal_id; ?>">
                        <input type="hidden" name="amount" id="selectedAmount" value="<?php echo $amount; ?>">
                        
                        <!-- Amount Selector -->
                        <div class="amount-selector">
                            <div class="amount-option <?php echo $amount == 2 ? 'selected' : ''; ?>" onclick="selectAmount(2)">
                                <div class="amount-value">$2</div>
                                <div class="amount-label">Audio Session</div>
                            </div>
                            <div class="amount-option <?php echo $amount == 29 ? 'selected' : ''; ?>" onclick="selectAmount(29)">
                                <div class="amount-value">$29</div>
                                <div class="amount-label">Live Session</div>
                            </div>
                        </div>

                        <!-- Donor Information -->
                        <div class="form-group">
                            <label for="donor_name">Your Name</label>
                            <input type="text" id="donor_name" name="donor_name" required placeholder="John Doe">
                        </div>

                        <div class="form-group">
                            <label for="donor_email">Your Email</label>
                            <input type="email" id="donor_email" name="donor_email" required placeholder="your.email@example.com">
                            <div class="helper-text">You'll receive a receipt and updates</div>
                        </div>

                        <div class="form-group">
                            <label for="message">Message to Recipient (Optional)</label>
                            <textarea id="message" name="message" placeholder="A word of encouragement for the person you're helping..."></textarea>
                        </div>

                        <div class="info-box">
                            <h4>What Happens Next?</h4>
                            <ul>
                                <li>Payment is processed securely</li>
                                <li>Recipient gets immediate session access</li>
                                <li>They can send you a thank-you message</li>
                                <li>You receive a receipt by email</li>
                                <li>Track your impact on your dashboard</li>
                            </ul>
                        </div>

                        <button type="submit" class="submit-btn">Complete Sponsorship - $<span id="displayAmount"><?php echo $amount; ?></span></button>
                    </form>

                    <div style="text-align: center; margin-top: 20px; color: #999; font-size: 13px;">
                        🔒 Secure payment powered by Stripe
                    </div>
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

    <script>
        function selectAmount(amount) {
            // Update selected state
            document.querySelectorAll('.amount-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Update hidden input and display
            document.getElementById('selectedAmount').value = amount;
            document.getElementById('displayAmount').textContent = amount;
        }
    </script>
</body>
</html>
