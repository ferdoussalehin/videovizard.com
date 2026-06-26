<?php
session_start();
include 'translate.php';
include 'logo_component.php';
include 'dbconnect_hdb.php';

// Get current language and RTL settings
$current_lang = current_lang();
$rtl_dir = is_rtl() ? 'rtl' : 'ltr';
$rtl_class = is_rtl() ? 'rtl' : '';

// Get community stats
$stats_query = "
    SELECT 
        COUNT(DISTINCT cd.recipient_id) as people_helped,
        COUNT(DISTINCT ca.country) as countries_reached,
        COUNT(DISTINCT COALESCE(cd.donar_name, cd.donor_id)) as active_donors,
        COUNT(cd.thank_you_message) as thank_you_count,
        COUNT(cd.id) as total_donations,
        SUM(cd.amount) as total_amount
    FROM community_donations cd
    LEFT JOIN community_appeals ca 
        ON cd.recipient_id = ca.id
    WHERE cd.donation_result = 'success'
";

$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Default values if no data yet
$people_helped = $stats['people_helped'] ?? 0;
$countries_reached = $stats['countries_reached'] ?? 0;
$active_donors = $stats['active_donors'] ?? 0;
$thank_you_count = $stats['thank_you_count'] ?? 0;
$total_donations = $stats['total_donations'] ?? 0;
$total_amount = $stats['total_amount'] ?? 0;
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>" dir="<?php echo $rtl_dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('community.title'); ?> - <?php echo t('site_name'); ?></title>
    <meta name="description" content="<?php echo t('community.meta_desc'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="logo.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.8;
            color: #333;
            background: #f8f9ff;
        }

        /* RTL Support */
        body.rtl {
            direction: rtl;
            text-align: right;
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

        .btn-nav:hover {
            background: #5568d3;
        }

        /* Mobile Menu */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 5px;
            z-index: 1001;
        }

        .menu-toggle span {
            display: block;
            width: 25px;
            height: 3px;
            background: #333;
            margin: 5px 0;
            transition: 0.3s;
        }

        .menu-toggle.active span:nth-child(1) {
            transform: rotate(-45deg) translate(-5px, 6px);
        }

        .menu-toggle.active span:nth-child(2) {
            opacity: 0;
        }

        .menu-toggle.active span:nth-child(3) {
            transform: rotate(45deg) translate(-5px, -6px);
        }

        /* Hero Section */
        .hero {
            padding: 140px 0 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.1;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .hero-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .hero-title {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero-subtitle {
            font-size: 24px;
            max-width: 800px;
            margin: 0 auto 30px;
            opacity: 0.95;
        }

        .hero-description {
            font-size: 18px;
            max-width: 700px;
            margin: 0 auto;
            opacity: 0.9;
        }

        /* Stats Section */
        .stats-section {
            background: white;
            padding: 60px 0;
            margin-top: -40px;
            position: relative;
            z-index: 2;
        }

        .stats-container {
            max-width: 1000px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
        }

        .stat-box {
            text-align: center;
            padding: 30px 20px;
            background: #f8f9ff;
            border-radius: 15px;
        }

        .stat-number {
            font-size: 48px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Mission Section */
        .mission-section {
            padding: 80px 0;
            text-align: center;
        }

        .section-title {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #333;
        }

        .section-subtitle {
            font-size: 20px;
            color: #666;
            max-width: 800px;
            margin: 0 auto 60px;
        }

        /* How It Works */
        .how-it-works {
            padding: 80px 0;
            background: white;
        }

        .how-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-top: 50px;
        }

        .how-card {
            text-align: center;
            padding: 40px 30px;
            background: #f8f9ff;
            border-radius: 15px;
        }

        .how-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .how-title {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 15px;
        }

        .how-description {
            font-size: 16px;
            color: #666;
            line-height: 1.7;
        }

        /* Two Paths Section */
        .paths-section {
            padding: 80px 0;
        }

        .paths-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            margin-top: 50px;
        }

        .path-card {
            background: white;
            padding: 50px 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .path-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .path-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .path-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #667eea;
        }

        .path-description {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.7;
        }

        .path-list {
            text-align: left;
            margin: 30px 0;
        }

        body.rtl .path-list {
            text-align: right;
        }

        .path-list li {
            padding: 10px 0;
            padding-left: 30px;
            position: relative;
            color: #666;
        }

        body.rtl .path-list li {
            padding-left: 0;
            padding-right: 30px;
        }

        .path-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #667eea;
            font-weight: bold;
            font-size: 18px;
        }

        body.rtl .path-list li:before {
            left: auto;
            right: 0;
        }

        .btn-path {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 40px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s;
        }

        .btn-path:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        /* FAQ Section */
        .faq-section {
            padding: 80px 0;
            background: white;
        }

        .faq-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-top: 50px;
        }

        .faq-item {
            background: #f8f9ff;
            padding: 30px;
            border-radius: 15px;
        }

        .faq-question {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #667eea;
        }

        .faq-answer {
            font-size: 15px;
            color: #666;
            line-height: 1.7;
        }

        /* CTA Section */
        .cta-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }

        .cta-title {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .cta-subtitle {
            font-size: 20px;
            margin-bottom: 40px;
            opacity: 0.95;
        }

        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-cta {
            display: inline-block;
            background: white;
            color: #667eea;
            padding: 18px 45px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s;
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255,255,255,0.3);
        }

        .btn-cta-secondary {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .btn-cta-secondary:hover {
            background: rgba(255,255,255,0.1);
        }

        /* Footer */
        .footer {
            background: #1a1a2e;
            color: white;
            padding: 60px 0 20px;
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

        /* Responsive */
        @media (max-width: 968px) {
            .menu-toggle {
                display: block;
            }

            .nav-links {
                position: fixed;
                top: 0;
                right: -100%;
                height: 100vh;
                width: 300px;
                background: white;
                flex-direction: column;
                padding: 80px 30px 30px;
                box-shadow: -5px 0 15px rgba(0,0,0,0.1);
                transition: right 0.3s ease;
                align-items: flex-start;
                gap: 20px;
            }

            body.rtl .nav-links {
                right: auto;
                left: -100%;
                box-shadow: 5px 0 15px rgba(0,0,0,0.1);
                transition: left 0.3s ease;
            }

            .nav-links.active {
                right: 0;
            }

            body.rtl .nav-links.active {
                right: auto;
                left: 0;
            }

            .nav-links a {
                width: 100%;
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
            }

            .btn-nav {
                width: 100%;
                text-align: center;
                margin-top: 10px;
            }

            .hero-title {
                font-size: 36px;
            }

            .hero-subtitle {
                font-size: 18px;
            }

            .hero-description {
                font-size: 16px;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .how-grid {
                grid-template-columns: 1fr;
            }

            .paths-grid {
                grid-template-columns: 1fr;
            }

            .faq-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }

            .section-title {
                font-size: 32px;
            }

            .section-subtitle {
                font-size: 16px;
            }

            .cta-title {
                font-size: 32px;
            }

            .cta-subtitle {
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 28px;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .stat-number {
                font-size: 36px;
            }
        }
    </style>
</head>
<body class="<?php echo $rtl_class; ?>">
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <?php echo logo('nav', 'index.php'); ?>
                <button class="menu-toggle" id="menuToggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <div class="nav-links" id="navLinks">
                    <a href="index.php"><?php echo t('nav.home'); ?></a>
                    <a href="index.php#how-it-works"><?php echo t('nav.how_it_works'); ?></a>
                    <a href="founder.php"><?php echo t('nav.founder'); ?></a>
                    <a href="about.php"><?php echo t('nav.about'); ?></a>
                    <a href="blog.php"><?php echo t('nav.blog'); ?></a>
                    <?php include 'language_switcher_db.php'; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-background"></div>
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title"><?php echo t('community.hero_title'); ?></h1>
                <p class="hero-subtitle"><?php echo t('community.hero_subtitle'); ?></p>
                <p class="hero-description"><?php echo t('community.hero_description'); ?></p>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-container">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $people_helped; ?></div>
                    <div class="stat-label"><?php echo t('community.stats.people_helped'); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $countries_reached; ?></div>
                    <div class="stat-label"><?php echo t('community.stats.countries_reached'); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $active_donors; ?></div>
                    <div class="stat-label"><?php echo t('community.stats.active_donors'); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $thank_you_count; ?></div>
                    <div class="stat-label"><?php echo t('community.stats.thank_you_messages'); ?></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Mission Section -->
    <section class="mission-section">
        <div class="container">
            <h2 class="section-title"><?php echo t('community.mission.title'); ?></h2>
            <p class="section-subtitle"><?php echo t('community.mission.description'); ?></p>
        </div>
    </section>

    <!-- How It Works -->
    <section class="how-it-works">
        <div class="container">
            <h2 class="section-title"><?php echo t('community.how_works.title'); ?></h2>
            <div class="how-grid">
                <div class="how-card">
                    <div class="how-icon">🙏</div>
                    <h3 class="how-title"><?php echo t('community.how_works.need_help_title'); ?></h3>
                    <p class="how-description"><?php echo t('community.how_works.need_help_desc'); ?></p>
                </div>
                <div class="how-card">
                    <div class="how-icon">💙</div>
                    <h3 class="how-title"><?php echo t('community.how_works.can_help_title'); ?></h3>
                    <p class="how-description"><?php echo t('community.how_works.can_help_desc'); ?></p>
                </div>
                <div class="how-card">
                    <div class="how-icon">🌍</div>
                    <h3 class="how-title"><?php echo t('community.how_works.pay_forward_title'); ?></h3>
                    <p class="how-description"><?php echo t('community.how_works.pay_forward_desc'); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Two Paths Section -->
    <section class="paths-section">
        <div class="container">
            <h2 class="section-title"><?php echo t('community.paths.title'); ?></h2>
            <div class="paths-grid">
                <!-- Recipient Path -->
                <div class="path-card">
                    <div class="path-icon">🙏</div>
                    <h3 class="path-title"><?php echo t('community.paths.need_help_title'); ?></h3>
                    <p class="path-description"><?php echo t('community.paths.need_help_desc'); ?></p>
                    <ul class="path-list">
                        <li><?php echo t('community.paths.need_help_step1'); ?></li>
                        <li><?php echo t('community.paths.need_help_step2'); ?></li>
                        <li><?php echo t('community.paths.need_help_step3'); ?></li>
                        <li><?php echo t('community.paths.need_help_step4'); ?></li>
                        <li><?php echo t('community.paths.need_help_step5'); ?></li>
                    </ul>
                    <a href="community_appeal_form.php" class="btn-path"><?php echo t('community.paths.submit_appeal'); ?></a>
                </div>

                <!-- Donor Path -->
                <div class="path-card">
                    <div class="path-icon">💙</div>
                    <h3 class="path-title"><?php echo t('community.paths.want_help_title'); ?></h3>
                    <p class="path-description"><?php echo t('community.paths.want_help_desc'); ?></p>
                    <ul class="path-list">
                        <li><?php echo t('community.paths.want_help_step1'); ?></li>
                        <li><?php echo t('community.paths.want_help_step2'); ?></li>
                        <li><?php echo t('community.paths.want_help_step3'); ?></li>
                        <li><?php echo t('community.paths.want_help_step4'); ?></li>
                        <li><?php echo t('community.paths.want_help_step5'); ?></li>
                    </ul>
                    <a href="community_browse.php" class="btn-path"><?php echo t('community.paths.browse_sponsor'); ?></a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="faq-section">
        <div class="container">
            <h2 class="section-title"><?php echo t('community.faq.title'); ?></h2>
            <div class="faq-grid">
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q1'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a1'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q2'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a2'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q3'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a3'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q4'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a4'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q5'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a5'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q6'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a6'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q7'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a7'); ?></p>
                </div>
                <div class="faq-item">
                    <h3 class="faq-question"><?php echo t('community.faq.q8'); ?></h3>
                    <p class="faq-answer"><?php echo t('community.faq.a8'); ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="container">
            <h2 class="cta-title"><?php echo t('community.cta.title'); ?></h2>
            <p class="cta-subtitle"><?php echo t('community.cta.subtitle'); ?></p>
            <div class="cta-buttons">
                <a href="community_browse.php" class="btn-cta"><?php echo t('community.cta.become_donor'); ?></a>
                <a href="community_appeal_form.php" class="btn-cta btn-cta-secondary"><?php echo t('community.cta.submit_appeal'); ?></a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-brand">
                    <?php echo logo('footer', 'index.php'); ?>
                    <p><?php echo t('footer.description'); ?></p>
                </div>
                <div class="footer-links">
                    <div class="footer-column">
                        <h4><?php echo t('footer.resources'); ?></h4>
                        <a href="index.php#how-it-works"><?php echo t('footer.how_it_works'); ?></a>
                        <a href="about.php"><?php echo t('footer.about'); ?></a>
                        <a href="founder.php"><?php echo t('nav.founder'); ?></a>
                        <a href="blog.php"><?php echo t('footer.blog'); ?></a>
                        <a href="community.php"><?php echo t('nav.community'); ?></a>
                    </div>
                    <div class="footer-column">
                        <h4><?php echo t('footer.legal'); ?></h4>
                        <a href="privacy.html"><?php echo t('footer.privacy'); ?></a>
                        <a href="terms.html"><?php echo t('footer.terms'); ?></a>
                        <a href="contact.html"><?php echo t('footer.contact'); ?></a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p><?php echo t('footer.copyright'); ?></p>
            </div>
        </div>
    </footer>

    <script>
        // Mobile Menu Toggle
        const menuToggle = document.getElementById('menuToggle');
        const navLinks = document.getElementById('navLinks');

        menuToggle.addEventListener('click', function() {
            this.classList.toggle('active');
            navLinks.classList.toggle('active');
        });

        // Close menu when clicking on a link
        const navLinkItems = navLinks.querySelectorAll('a');
        navLinkItems.forEach(link => {
            link.addEventListener('click', function() {
                menuToggle.classList.remove('active');
                navLinks.classList.remove('active');
            });
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            const isClickInsideNav = navLinks.contains(event.target);
            const isClickOnToggle = menuToggle.contains(event.target);
            
            if (!isClickInsideNav && !isClickOnToggle && navLinks.classList.contains('active')) {
                menuToggle.classList.remove('active');
                navLinks.classList.remove('active');
            }
        });
    </script>
</body>
</html>