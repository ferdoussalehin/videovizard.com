<?php 
include 'translate.php';
include 'logo_component.php';  

$rtl_class = is_rtl() ? 'rtl' : 'ltr'; 
$rtl_dir = is_rtl() ? 'rtl' : 'ltr';
?>
<!DOCTYPE html>
<html lang="<?php echo current_lang(); ?>" dir="<?php echo $rtl_dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('about.title'); ?> - <?php echo t('site_name'); ?></title>
    <meta name="description" content="<?php echo t('about.intro'); ?>">
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
            line-height: 1.7;
            color: #333;
            background: #f8f9ff;
        }

        /* RTL Support */
        body.rtl {
            direction: rtl;
            text-align: right;
        }

        body.rtl .global-card ul,
        body.rtl .about-content {
            text-align: right;
        }

        body.rtl .global-card ul {
            padding-right: 20px;
            padding-left: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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

        /* Hamburger Menu */
        .hamburger {
            display: none;
            flex-direction: column;
            cursor: pointer;
            z-index: 1001;
        }

        .hamburger span {
            width: 25px;
            height: 3px;
            background: #667eea;
            margin: 3px 0;
            transition: all 0.3s;
            border-radius: 2px;
        }

        .hamburger.active span:nth-child(1) {
            transform: rotate(45deg) translate(8px, 8px);
        }

        .hamburger.active span:nth-child(2) {
            opacity: 0;
        }

        .hamburger.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -7px);
        }

        /* Header */
        .page-header {
            padding: 120px 0 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }

        .page-header h1 {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .page-header p {
            font-size: 22px;
            opacity: 0.95;
            max-width: 700px;
            margin: 0 auto;
        }

        /* About StressReleasor */
        .about-sr {
            padding: 80px 0;
            background: white;
        }

        .section-title {
            font-size: 42px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 60px;
            color: #1a1a2e;
        }

        .about-content {
            max-width: 900px;
            margin: 0 auto;
        }

        .about-content p {
            font-size: 18px;
            line-height: 1.8;
            color: #555;
            margin-bottom: 25px;
        }

        .about-content p.highlight {
            font-size: 20px;
            font-weight: 600;
            color: #667eea;
            text-align: center;
            margin: 40px 0;
        }

        /* Global Community Section */
        .global-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #1a1a2e 0%, #2d2d4a 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .global-section::before {
            content: '🌍';
            position: absolute;
            font-size: 400px;
            opacity: 0.05;
            top: -100px;
            right: -100px;
            z-index: 0;
        }

        body.rtl .global-section::before {
            right: auto;
            left: -100px;
        }

        .global-content {
            position: relative;
            z-index: 1;
        }

        .global-intro {
            max-width: 900px;
            margin: 0 auto 60px;
            text-align: center;
        }

        .global-intro p {
            font-size: 20px;
            line-height: 1.8;
            margin-bottom: 20px;
            opacity: 0.9;
        }

        /* Feature Highlights */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin: 60px 0;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
            text-align: center;
        }

        .feature-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 56px;
            margin-bottom: 20px;
            display: block;
        }

        .feature-card h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #667eea;
        }

        .feature-card p {
            font-size: 16px;
            line-height: 1.7;
            opacity: 0.9;
        }

        .feature-badge {
            display: inline-block;
            background: rgba(102, 126, 234, 0.3);
            color: #a5b4fc;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Global Reach Cards */
        .global-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-top: 60px;
        }

        .global-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s;
        }

        .global-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
        }

        .global-icon {
            font-size: 48px;
            margin-bottom: 20px;
            display: block;
        }

        .global-card h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #667eea;
        }

        .global-card p {
            font-size: 16px;
            line-height: 1.7;
            opacity: 0.9;
        }

        .global-card ul {
            margin-top: 15px;
            padding-left: 20px;
        }

        .global-card li {
            font-size: 15px;
            line-height: 1.7;
            opacity: 0.85;
            margin-bottom: 8px;
        }

        /* Sponsorship CTA */
        .sponsor-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 50px;
            border-radius: 20px;
            text-align: center;
            margin-top: 60px;
            box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
        }

        .sponsor-box h3 {
            font-size: 32px;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .sponsor-box p {
            font-size: 18px;
            margin-bottom: 30px;
            opacity: 0.95;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .sponsor-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-bottom: 40px;
        }

        .sponsor-stat {
            padding: 20px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
        }

        .sponsor-stat h4 {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .sponsor-stat p {
            font-size: 14px;
            opacity: 1;
            margin: 0;
        }

        .btn-sponsor {
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

        .btn-sponsor:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 255, 255, 0.4);
        }

        /* Vision & Mission */
        .vm-section {
            padding: 80px 0;
            background: #f8f9ff;
        }

        .vm-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            margin-top: 40px;
        }

        .vm-card {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .vm-icon {
            font-size: 60px;
            margin-bottom: 20px;
            display: block;
        }

        .vm-card h3 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #667eea;
        }

        .vm-card p {
            font-size: 18px;
            line-height: 1.8;
            color: #555;
        }

        /* How It Works */
        .how-section {
            padding: 80px 0;
            background: white;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-top: 40px;
        }

        .step-card {
            text-align: center;
            padding: 40px 30px;
            background: #f8f9ff;
            border-radius: 15px;
            transition: transform 0.3s;
        }

        .step-card:hover {
            transform: translateY(-5px);
        }

        .step-number {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            margin: 0 auto 20px;
        }

        .step-card h4 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #1a1a2e;
        }

        .step-card p {
            font-size: 16px;
            color: #666;
            line-height: 1.6;
        }

        /* Stats */
        .stats-section {
            padding: 80px 0;
            background: #1a1a2e;
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 40px;
            text-align: center;
        }

        .stat-item h4 {
            font-size: 56px;
            font-weight: 800;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-item p {
            font-size: 18px;
            opacity: 0.8;
        }

        /* CTA */
        .cta-section {
            padding: 100px 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            text-align: center;
            color: white;
        }

        .cta-section h2 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .cta-section p {
            font-size: 20px;
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn-cta {
            display: inline-block;
            background: white;
            color: #667eea;
            padding: 20px 50px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            transition: all 0.3s;
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255,255,255,0.3);
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

        .footer-brand .logo {
            margin-bottom: 15px;
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

        /* Mobile Responsive */
        @media (max-width: 968px) {
            .hamburger {
                display: flex;
            }

            .nav-links {
                position: fixed;
                left: -100%;
                top: 70px;
                flex-direction: column;
                background: rgba(255, 255, 255, 0.98);
                width: 100%;
                text-align: center;
                transition: left 0.3s;
                box-shadow: 0 10px 27px rgba(0,0,0,0.05);
                padding: 30px 0;
                gap: 20px;
            }

            body.rtl .nav-links {
                left: auto;
                right: -100%;
                transition: right 0.3s;
            }

            .nav-links.active {
                left: 0;
            }

            body.rtl .nav-links.active {
                left: auto;
                right: 0;
            }

            .nav-links a {
                font-size: 18px;
                padding: 10px 0;
            }

            .btn-nav {
                width: 200px;
                margin: 10px auto;
            }

            .page-header h1 {
                font-size: 36px;
            }

            .page-header p {
                font-size: 18px;
            }

            .section-title {
                font-size: 32px;
            }

            .about-content p {
                font-size: 16px;
            }

            .features-grid,
            .global-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .feature-icon {
                font-size: 48px;
            }

            .sponsor-stats {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .sponsor-box {
                padding: 30px 20px;
            }

            .sponsor-box h3 {
                font-size: 26px;
            }

            .vm-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }

            .vm-card {
                padding: 30px;
            }

            .vm-card h3 {
                font-size: 26px;
            }

            .steps-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 30px;
            }

            .stat-item h4 {
                font-size: 42px;
            }

            .cta-section h2 {
                font-size: 32px;
            }

            .cta-section p {
                font-size: 18px;
            }

            .footer-content {
                grid-template-columns: 1fr;
                gap: 40px;
            }
        }

        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 28px;
            }

            .section-title {
                font-size: 26px;
            }

            .vm-card {
                padding: 25px;
            }

            .vm-icon {
                font-size: 48px;
            }

            .vm-card h3 {
                font-size: 22px;
            }

            .stat-item h4 {
                font-size: 36px;
            }

            .stat-item p {
                font-size: 14px;
            }

            .btn-cta {
                padding: 15px 30px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="<?php echo $rtl_class; ?>">
    <!-- Nav -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <?php echo logo('nav', 'index.php'); ?>
                
                <div class="hamburger" onclick="toggleMenu()">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                
                <div class="nav-links" id="navLinks">
                    <a href="index.php#how-it-works"><?php echo t('nav.how_it_works'); ?></a>
                    <a href="founder.php"><?php echo t('nav.founder'); ?></a>
                    <a href="blog.php"><?php echo t('nav.blog'); ?></a>
                    <a href="community.php"><?php echo t('nav.community'); ?></a>
                    
                    <!-- Language Switcher -->
                    <?php include 'language_switcher_db.php'; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <section class="page-header">
        <div class="container">
            <h1><?php echo t('about.title'); ?></h1>
            <p><?php echo t('tagline'); ?></p>
        </div>
    </section>

    <!-- About StressReleasor -->
    <!-- About StressReleasor -->
	<section class="about-sr">
		<div class="container">
			<h2 class="section-title"><?php echo t('about.title'); ?></h2>
			<div class="about-content">
				<p><?php echo t('about.intro'); ?></p>

				<p><?php echo t('about.stress_body'); ?></p>

				<p><?php echo t('about.overwhelmed'); ?></p>

				<p><?php echo t('about.platform'); ?></p>

				<p><?php echo t('about.not_fixing'); ?></p>

				<p class="highlight"><?php echo t('about.our_mission'); ?>: <?php echo t('about.mission_text'); ?></p>

				<p><?php echo t('about.clarity_returns'); ?></p>
			</div>
		</div>
	</section>

    <!-- Global Community Section -->   
	<section class="global-section">
		<div class="container">
			<div class="global-content">
				<h2 class="section-title" style="color: white;"><?php echo t('community.title'); ?></h2>
				
				<div class="global-intro">
					<p><?php echo t('about.global_intro_1'); ?></p>
					<p><?php echo t('about.global_intro_2'); ?></p>
				</div>

				<!-- Feature Highlights -->
				<div class="features-grid">
					<div class="feature-card">
						<span class="feature-icon">💪</span>
						<h3><?php echo t('about.feature_inner_strength'); ?></h3>
						<p><?php echo t('about.feature_inner_strength_desc'); ?></p>
					</div>

					<div class="feature-card">
						<span class="feature-icon">🌐</span>
						<h3><?php echo t('about.feature_multilingual'); ?></h3>
						<p><?php echo t('about.feature_multilingual_desc'); ?></p>
					</div>

					<div class="feature-card">
						<span class="feature-icon">🎯</span>
						<h3><?php echo t('about.feature_trigger'); ?></h3>
						<p><?php echo t('about.feature_trigger_desc'); ?></p>
					</div>
				</div>

				<!-- Global Reach -->
				<div class="global-grid">
					<div class="global-card">
						<span class="global-icon">🤝</span>
						<h3><?php echo t('about.crowdfunding_title'); ?></h3>
						<p><?php echo t('about.crowdfunding_desc'); ?></p>
						<p style="margin-top: 15px; font-weight: 600; color: #a5b4fc;"><?php echo t('about.when_you_heal'); ?></p>
					</div>

					<div class="global-card">
						<span class="global-icon">🌍</span>
						<h3><?php echo t('about.worldwide_title'); ?></h3>
						<p><?php echo t('about.worldwide_desc'); ?></p>
						<ul>
							<li><?php echo t('about.worldwide_no_geo'); ?></li>
							<li><?php echo t('about.worldwide_any_device'); ?></li>
							<li><?php echo t('about.worldwide_affordable'); ?></li>
							<li><?php echo t('about.worldwide_free'); ?></li>
						</ul>
					</div>

					<div class="global-card">
						<span class="global-icon">💝</span>
						<h3><?php echo t('about.sponsor_title'); ?></h3>
						<p><?php echo t('about.sponsor_desc'); ?></p>
						<ul>
							<li><?php echo t('about.sponsor_healthcare'); ?></li>
							<li><?php echo t('about.sponsor_students'); ?></li>
							<li><?php echo t('about.sponsor_parents'); ?></li>
							<li><?php echo t('about.sponsor_anyone'); ?></li>
						</ul>
					</div>
				</div>

				<!-- Sponsorship CTA -->
				<div class="sponsor-box">
					<h3><?php echo t('about.help_world_title'); ?></h3>
					<p><?php echo t('about.help_world_desc'); ?></p>
					
					<div class="sponsor-stats">
						<div class="sponsor-stat">
							<h4><?php echo t('about.sponsor_stat_1'); ?></h4>
							<p><?php echo t('about.sponsor_stat_1_desc'); ?></p>
						</div>
						<div class="sponsor-stat">
							<h4><?php echo t('about.sponsor_stat_2'); ?></h4>
							<p><?php echo t('about.sponsor_stat_2_desc'); ?></p>
						</div>
						<div class="sponsor-stat">
							<h4><?php echo t('about.sponsor_stat_3'); ?></h4>
							<p><?php echo t('about.sponsor_stat_3_desc'); ?></p>
						</div>
					</div>

					<a href="stressreleasor.php" class="btn-sponsor"><?php echo t('about.join_mission'); ?></a>
				</div>
			</div>
		</div>
	</section>

	<!-- Vision & Mission -->
	<section class="vm-section">
		<div class="container">
			<h2 class="section-title"><?php echo t('about.vision_mission_title'); ?></h2>
			<div class="vm-grid">
				<div class="vm-card">
					<span class="vm-icon">🔮</span>
					<h3><?php echo t('about.vision_title'); ?></h3>
					<p><?php echo t('about.vision_text'); ?></p>
				</div>

				<div class="vm-card">
					<span class="vm-icon">🎯</span>
					<h3><?php echo t('about.our_mission'); ?></h3>
					<p><?php echo t('about.mission_text'); ?></p>
				</div>
			</div>
		</div>
	</section>

	<!-- How It Works -->
	<section class="how-section">
		<div class="container">
			<h2 class="section-title"><?php echo t('index.how_it_works.title'); ?></h2>
			<div class="steps-grid">
				<div class="step-card">
					<div class="step-number">1</div>
					<h4><?php echo t('about.how_step1_title'); ?></h4>
					<p><?php echo t('about.how_step1_desc'); ?></p>
				</div>

				<div class="step-card">
					<div class="step-number">2</div>
					<h4><?php echo t('about.how_step2_title'); ?></h4>
					<p><?php echo t('about.how_step2_desc'); ?></p>
				</div>

				<div class="step-card">
					<div class="step-number">3</div>
					<h4><?php echo t('about.how_step3_title'); ?></h4>
					<p><?php echo t('about.how_step3_desc'); ?></p>
				</div>

				<div class="step-card">
					<div class="step-number">4</div>
					<h4><?php echo t('about.how_step4_title'); ?></h4>
					<p><?php echo t('about.how_step4_desc'); ?></p>
				</div>
			</div>
		</div>
	</section>

	<!-- Stats -->
	<section class="stats-section">
		<div class="container">
			<div class="stats-grid">
				<div class="stat-item">
					<h4>$2</h4>
					<p><?php echo t('about.stat_accessible'); ?></p>
				</div>
				<div class="stat-item">
					<h4>24/7</h4>
					<p><?php echo t('about.stat_anytime'); ?></p>
				</div>
				<div class="stat-item">
					<h4>100%</h4>
					<p><?php echo t('about.stat_personalized'); ?></p>
				</div>
				<div class="stat-item">
					<h4>🌍</h4>
					<p><?php echo t('about.stat_global'); ?></p>
				</div>
			</div>
		</div>
	</section>

<!-- CTA -->
<section class="cta-section">
    <div class="container">
        <h2><?php echo t('index.cta.title'); ?></h2>
        <p><?php echo t('about.cta_subtitle'); ?></p>
        <a href="stressreleasor.php" class="btn-cta"><?php echo t('nav.get_started'); ?> - Just $2</a>
    </div>
</section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-brand">
                    <div class="logo">
                        <span class="logo-icon">🛟</span>
                        <span class="logo-text"><?php echo t('site_name'); ?></span>
                    </div>
                    <p><?php echo t('footer.description'); ?></p>
                </div>
                <div class="footer-links">
                    <div class="footer-column">
                        <h4><?php echo t('footer.resources'); ?></h4>
                        <a href="founder.php"><?php echo t('nav.founder'); ?></a>
                        <a href="about.php"><?php echo t('footer.about'); ?></a>
                        <a href="blog.php"><?php echo t('footer.blog'); ?></a>
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
                <p>&copy; <?php echo t('footer.copyright'); ?></p>
            </div>
        </div>
    </footer>

    <script>
        function toggleMenu() {
            const navLinks = document.getElementById('navLinks');
            const hamburger = document.querySelector('.hamburger');
            
            navLinks.classList.toggle('active');
            hamburger.classList.toggle('active');
        }

        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', () => {
                const navLinks = document.getElementById('navLinks');
                const hamburger = document.querySelector('.hamburger');
                
                navLinks.classList.remove('active');
                hamburger.classList.remove('active');
            });
        });
    </script>
</body>
</html>