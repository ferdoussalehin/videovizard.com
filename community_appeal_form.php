<?php
session_start();
include 'translate.php';
include 'logo_component.php';
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>" dir="<?php echo in_array($current_lang, ['ar', 'ur']) ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('community.appeal_form.title'); ?> - <?php echo t('site_name'); ?></title>
    <meta name="description" content="<?php echo t('community.appeal_form.subtitle'); ?>">
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
            line-height: 1.6;
            color: #333;
            background: #f8f9ff;
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

        /* Language Switcher */
        .language-switcher {
            position: relative;
            margin-left: 15px;
        }

        .language-switcher select {
            padding: 8px 30px 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            background: white;
            transition: border-color 0.3s;
        }

        .language-switcher select:hover {
            border-color: #667eea;
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
            padding: 140px 0 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }

        .hero-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .hero-title {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 15px;
        }

        .hero-subtitle {
            font-size: 18px;
            opacity: 0.95;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Form Section */
        .form-section {
            padding: 60px 0;
        }

        .form-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .form-intro {
            text-align: center;
            margin-bottom: 40px;
        }

        .form-intro h2 {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 15px;
        }

        .form-intro p {
            color: #666;
            font-size: 16px;
            line-height: 1.7;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .required {
            color: #e74c3c;
            margin-left: 3px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
            transition: transform 0.3s, box-shadow 0.3s;
            margin-top: 20px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .info-box {
            background: #f0f4ff;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 8px;
        }

        .info-box h3 {
            color: #667eea;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .info-box ul {
            margin-left: 20px;
            color: #666;
        }

        .info-box ul li {
            margin-bottom: 5px;
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

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* RTL Support */
        [dir="rtl"] .info-box {
            border-left: none;
            border-right: 4px solid #667eea;
        }

        [dir="rtl"] .info-box ul {
            margin-left: 0;
            margin-right: 20px;
        }

        [dir="rtl"] .required {
            margin-left: 0;
            margin-right: 3px;
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

            [dir="rtl"] .nav-links {
                right: auto;
                left: -100%;
                transition: left 0.3s ease;
            }

            .nav-links.active {
                right: 0;
            }

            [dir="rtl"] .nav-links.active {
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

            .form-container {
                padding: 30px 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .hero-title {
                font-size: 32px;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 28px;
            }

            .form-intro h2 {
                font-size: 24px;
            }
        }
		
		
		
				/* Language Dropdown */
		.language-dropdown {
			position: relative;
		}

		.language-btn {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 8px 12px;
			background: #f8f9ff;
			border: 2px solid #e0e0e0;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			color: #333;
			transition: all 0.3s;
		}

		.language-btn:hover {
			background: white;
			border-color: #667eea;
		}

		.globe-icon {
			font-size: 18px;
		}

		.current-lang {
			font-size: 13px;
		}

		.arrow {
			font-size: 10px;
			transition: transform 0.3s;
		}

		.language-btn.active .arrow {
			transform: rotate(180deg);
		}

		.language-menu {
			position: absolute;
			top: calc(100% + 8px);
			<?php echo $dir === 'rtl' ? 'left: 0;' : 'right: 0;'; ?>
			background: white;
			border-radius: 12px;
			box-shadow: 0 8px 25px rgba(0,0,0,0.15);
			min-width: 200px;
			opacity: 0;
			visibility: hidden;
			transform: translateY(-10px);
			transition: all 0.3s;
			z-index: 1000;
			overflow: hidden;
		}

		.language-menu.active {
			opacity: 1;
			visibility: visible;
			transform: translateY(0);
		}

		.language-menu a {
			display: flex;
			align-items: center;
			gap: 12px;
			padding: 12px 16px;
			text-decoration: none;
			color: #333;
			transition: background 0.3s;
			border-bottom: 1px solid #f0f0f0;
		}

		.language-menu a:last-child {
			border-bottom: none;
		}

		.language-menu a:hover {
			background: #f8f9ff;
		}

		.language-menu a.active {
			background: #667eea;
			color: white;
		}

		.language-menu a.active .lang-code {
			color: white;
		}

		.lang-code {
			font-weight: 700;
			font-size: 13px;
			color: #667eea;
			min-width: 30px;
		}

		.lang-name {
			font-size: 14px;
			font-weight: 500;
		}

		/* Mobile Language Dropdown */
		@media (max-width: 968px) {
			.language-dropdown {
				width: 100%;
				border-top: 1px solid #f0f0f0;
				padding-top: 15px;
				margin-top: 15px;
			}

			.language-btn {
				width: 100%;
				justify-content: space-between;
			}

			.language-menu {
				position: static;
				opacity: 1;
				visibility: visible;
				transform: none;
				box-shadow: none;
				margin-top: 10px;
				border: 1px solid #e0e0e0;
				max-height: 0;
				overflow: hidden;
				transition: max-height 0.3s;
			}

			.language-menu.active {
				max-height: 400px;
			}
		}
    </style>
</head>
<body>
    <!-- Navigation -->
  <!-- Navigation -->
	 <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <?php echo logo('nav', 'index.php'); ?>
                
                <!-- Hamburger Menu Button -->
                <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                
                <div class="nav-links" id="navLinks">
                    <a href="index.php#how-it-works" onclick="closeMobileMenu()"><?php echo t('nav.how_it_works'); ?></a>
                    <a href="about.php" onclick="closeMobileMenu()"><?php echo t('nav.about'); ?></a>
                    <a href="founder.php" onclick="closeMobileMenu()"><?php echo t('nav.founder'); ?></a>
                    <a href="blog.php" onclick="closeMobileMenu()"><?php echo t('nav.blog'); ?></a>
                    <a href="community.php" onclick="closeMobileMenu()"><?php echo t('nav.community'); ?></a>
                    
                    <!-- Language Switcher -->
                    <?php include 'language_switcher_db.php'; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="hero-icon">🙏</div>
            <h1 class="hero-title"><?php echo t('community.appeal_form.title'); ?></h1>
            <p class="hero-subtitle"><?php echo t('community.appeal_form.subtitle'); ?></p>
        </div>
    </section>

    <!-- Form Section -->
    <section class="form-section">
        <div class="container">
            <div class="form-container">
                <div class="form-intro">
                    <h2><?php echo t('community.paths.submit_appeal'); ?></h2>
                    <p><?php echo t('submit_appeal.intro_text'); ?></p>
                </div>

                <div class="info-box">
                    <h3><?php echo t('submit_appeal.what_happens_title'); ?></h3>
                    <ul>
                        <li><?php echo t('submit_appeal.step1'); ?></li>
                        <li><?php echo t('submit_appeal.step2'); ?></li>
                        <li><?php echo t('submit_appeal.step3'); ?></li>
                        <li><?php echo t('submit_appeal.step4'); ?></li>
                        <li><?php echo t('submit_appeal.step5'); ?></li>
                    </ul>
                </div>

                <form id="appealForm" method="POST" action="process_appeal.php">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name"><?php echo t('community.appeal_form.name'); ?> <span class="required">*</span></label>
                            <input type="text" id="name" name="name" required placeholder="<?php echo t('community.appeal_form.name_placeholder'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="age"><?php echo t('submit_appeal.age'); ?> <span class="required">*</span></label>
                            <input type="number" id="age" name="age" min="13" max="120" required placeholder="<?php echo t('submit_appeal.age_placeholder'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="email"><?php echo t('community.appeal_form.email'); ?> <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required placeholder="<?php echo t('community.appeal_form.email_placeholder'); ?>">
                        <div class="helper-text"><?php echo t('submit_appeal.email_helper'); ?></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city"><?php echo t('submit_appeal.city'); ?> <span class="required">*</span></label>
                            <input type="text" id="city" name="city" required placeholder="<?php echo t('submit_appeal.city_placeholder'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="country"><?php echo t('community.appeal_form.country'); ?> <span class="required">*</span></label>
                            <input type="text" id="country" name="country" required placeholder="<?php echo t('community.appeal_form.country_placeholder'); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="category"><?php echo t('submit_appeal.category_label'); ?> <span class="required">*</span></label>
                        <select id="category" name="category" required>
                            <option value=""><?php echo t('submit_appeal.select_category'); ?></option>
                            <option value="work_stress"><?php echo t('submit_appeal.cat_work'); ?></option>
                            <option value="relationship"><?php echo t('submit_appeal.cat_relationship'); ?></option>
                            <option value="anxiety"><?php echo t('submit_appeal.cat_anxiety'); ?></option>
                            <option value="depression"><?php echo t('submit_appeal.cat_depression'); ?></option>
                            <option value="sleep"><?php echo t('submit_appeal.cat_sleep'); ?></option>
                            <option value="trauma"><?php echo t('submit_appeal.cat_trauma'); ?></option>
                            <option value="grief"><?php echo t('submit_appeal.cat_grief'); ?></option>
                            <option value="health"><?php echo t('submit_appeal.cat_health'); ?></option>
                            <option value="financial"><?php echo t('submit_appeal.cat_financial'); ?></option>
                            <option value="family"><?php echo t('submit_appeal.cat_family'); ?></option>
                            <option value="self_esteem"><?php echo t('submit_appeal.cat_self_esteem'); ?></option>
                            <option value="life_transition"><?php echo t('submit_appeal.cat_life_transition'); ?></option>
                            <option value="anger"><?php echo t('submit_appeal.cat_anger'); ?></option>
                            <option value="phobia"><?php echo t('submit_appeal.cat_phobia'); ?></option>
                            <option value="addiction"><?php echo t('submit_appeal.cat_addiction'); ?></option>
                            <option value="other"><?php echo t('submit_appeal.cat_other'); ?></option>
                        </select>
                    </div>

                    <div class="form-group" id="issueGroup" style="display: none;">
                        <label for="issue"><?php echo t('submit_appeal.specific_issue'); ?> <span class="required">*</span></label>
                        <select id="issue" name="issue">
                            <option value=""><?php echo t('submit_appeal.select_issue'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="appeal"><?php echo t('community.appeal_form.reason'); ?> <span class="required">*</span></label>
                        <textarea id="appeal" name="appeal" required placeholder="<?php echo t('community.appeal_form.reason_placeholder'); ?>"></textarea>
                        <div class="helper-text"><?php echo t('submit_appeal.story_helper'); ?></div>
                    </div>

                    <button type="submit" class="submit-btn"><?php echo t('community.appeal_form.submit'); ?></button>
                </form>
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
        function changeLanguage(lang) {
            window.location.href = '?lang=' + lang;
        }

        // Category to Issues mapping
        const categoryIssues = {
            'work_stress': <?php echo json_encode([
                t('submit_appeal.issue_burnout'),
                t('submit_appeal.issue_conflict'),
                t('submit_appeal.issue_balance'),
                t('submit_appeal.issue_uncertainty'),
                t('submit_appeal.issue_pressure'),
                t('submit_appeal.issue_unemployment'),
                t('submit_appeal.issue_toxic')
            ]); ?>,
            // Add more categories...
        };

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

        // Dynamic Issue Selection
        const categorySelect = document.getElementById('category');
        const issueGroup = document.getElementById('issueGroup');
        const issueSelect = document.getElementById('issue');

        categorySelect.addEventListener('change', function() {
            const selectedCategory = this.value;
            
            if (selectedCategory && categoryIssues[selectedCategory]) {
                issueSelect.innerHTML = '<option value=""><?php echo t('submit_appeal.select_issue'); ?></option>';
                
                categoryIssues[selectedCategory].forEach(issue => {
                    const option = document.createElement('option');
                    option.value = issue;
                    option.textContent = issue;
                    issueSelect.appendChild(option);
                });
                
                issueGroup.style.display = 'block';
                issueSelect.required = true;
            } else {
                issueGroup.style.display = 'none';
                issueSelect.required = false;
            }
        });

        // Form Validation
        const appealForm = document.getElementById('appealForm');
        const appealTextarea = document.getElementById('appeal');

        appealForm.addEventListener('submit', function(e) {
            const appealText = appealTextarea.value.trim();
            
            if (appealText.length < 100) {
                e.preventDefault();
                alert('<?php echo t('submit_appeal.validation_message'); ?>');
                appealTextarea.focus();
                return false;
            }
        });
		
		// Language Dropdown Toggle
		const languageBtn = document.getElementById('languageBtn');
		const languageMenu = document.getElementById('languageMenu');

		languageBtn.addEventListener('click', function(e) {
			e.stopPropagation();
			languageBtn.classList.toggle('active');
			languageMenu.classList.toggle('active');
		});

		// Close language menu when clicking outside
		document.addEventListener('click', function(event) {
			if (!languageBtn.contains(event.target) && !languageMenu.contains(event.target)) {
				languageBtn.classList.remove('active');
				languageMenu.classList.remove('active');
			}
		});

		// Close language menu when selecting a language
		const languageLinks = languageMenu.querySelectorAll('a');
		languageLinks.forEach(link => {
			link.addEventListener('click', function() {
				languageBtn.classList.remove('active');
				languageMenu.classList.remove('active');
			});
		});
		
		
		
    </script>
</body>
</html>