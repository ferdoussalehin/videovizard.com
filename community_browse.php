<?php
session_start();
require_once 'translate.php';
include 'logo_component.php';
include 'dbconnect_hdb.php';

// Get current language
$lang = $_SESSION['lang'] ?? 'en';
$dir = in_array($lang, ['ar', 'ur']) ? 'rtl' : 'ltr';

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'newest';

// Build query
$query = "SELECT * FROM community_appeals WHERE status = 'waiting'";

// Apply category filter
if ($category_filter !== 'all') {
    $query .= " AND category = '" . mysqli_real_escape_string($conn, $category_filter) . "'";
}

// Apply sorting
switch ($sort_by) {
    case 'oldest':
        $query .= " ORDER BY submitted_at ASC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY submitted_at DESC";
        break;
}

$result = mysqli_query($conn, $query);
$appeals = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $appeals[] = $row;
    }
}

// Get count by category for filter
$category_counts = [];
$count_query = "SELECT category, COUNT(*) as count FROM community_appeals WHERE status = 'waiting' GROUP BY category";
$count_result = mysqli_query($conn, $count_query);
if ($count_result) {
    while ($row = mysqli_fetch_assoc($count_result)) {
        $category_counts[$row['category']] = $row['count'];
    }
}

// Category translation keys
$category_keys = [
    'work_stress' => 'cat_work',
    'relationship' => 'cat_relationship',
    'anxiety' => 'cat_anxiety',
    'depression' => 'cat_depression',
    'sleep' => 'cat_sleep',
    'trauma' => 'cat_trauma',
    'grief' => 'cat_grief',
    'health' => 'cat_health',
    'financial' => 'cat_financial',
    'family' => 'cat_family',
    'self_esteem' => 'cat_self_esteem',
    'life_transition' => 'cat_life_transition',
    'anger' => 'cat_anger',
    'phobia' => 'cat_phobia',
    'addiction' => 'cat_addiction',
    'other' => 'cat_other'
];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('community.browse.title'); ?> - StressReleasor</title>
    <meta name="description" content="<?php echo t('community.browse.subtitle'); ?>">
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
            direction: <?php echo $dir; ?>;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* RTL Support */
        [dir="rtl"] .nav-links {
            <?php if ($dir === 'rtl'): ?>
            right: auto;
            left: -100%;
            <?php endif; ?>
        }

        [dir="rtl"] .nav-links.active {
            <?php if ($dir === 'rtl'): ?>
            left: 0;
            right: auto;
            <?php endif; ?>
        }

        /* Navigation */
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
            display: flex;
            gap: 8px;
            align-items: center;
            padding: 8px;
            background: #f8f9ff;
            border-radius: 8px;
        }

        .language-switcher a {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            color: #666;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .language-switcher a:hover {
            background: white;
            color: #667eea;
        }

        .language-switcher a.active {
            background: #667eea;
            color: white;
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
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 15px;
        }

        .hero-subtitle {
            font-size: 18px;
            opacity: 0.95;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 30px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .filter-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group label {
            font-weight: 600;
            color: #333;
        }

        .filter-group select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .results-count {
            color: #666;
            font-size: 15px;
        }

        /* Appeals Grid */
        .appeals-section {
            padding: 60px 0;
        }

        .appeals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }

        .appeal-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
        }

        .appeal-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .appeal-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .appeal-category {
            background: #f0f4ff;
            color: #667eea;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .appeal-date {
            font-size: 13px;
            color: #999;
        }

        .appeal-info {
            margin-bottom: 20px;
        }

        .appeal-name {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .appeal-location {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .appeal-location::before {
            content: "📍 ";
        }

        .appeal-issue {
            font-size: 14px;
            color: #667eea;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .appeal-text {
            font-size: 15px;
            color: #666;
            line-height: 1.7;
            margin-bottom: 20px;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .appeal-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sponsor {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-sponsor:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-view {
            padding: 12px 20px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-view:hover {
            background: #f8f9ff;
        }

        /* No Results */
        .no-results {
            text-align: center;
            padding: 80px 20px;
        }

        .no-results-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .no-results h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 15px;
        }

        .no-results p {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
        }

        .btn-clear-filters {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            transition: background 0.3s;
        }

        .btn-clear-filters:hover {
            background: #5568d3;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 700px;
            width: 100%;
            padding: 50px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            <?php echo $dir === 'rtl' ? 'left' : 'right'; ?>: 20px;
            font-size: 30px;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: #333;
        }

        .modal-category {
            background: #f0f4ff;
            color: #667eea;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
        }

        .modal-name {
            font-size: 32px;
            font-weight: 800;
            color: #333;
            margin-bottom: 15px;
        }

        .modal-location {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
        }

        .modal-issue {
            background: #fff4e6;
            border-<?php echo $dir === 'rtl' ? 'right' : 'left'; ?>: 4px solid #f39c12;
            padding: 15px 20px;
            margin-bottom: 30px;
            border-radius: 8px;
        }

        .modal-issue strong {
            color: #e67e22;
        }

        .modal-story {
            font-size: 16px;
            color: #666;
            line-height: 1.8;
            margin-bottom: 30px;
            white-space: pre-wrap;
        }

        .modal-actions {
            display: flex;
            gap: 15px;
        }

        .modal-actions .btn-sponsor {
            flex: 1;
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

        /* Responsive */
        @media (max-width: 968px) {
            .menu-toggle {
                display: block;
            }

            .nav-links {
                position: fixed;
                top: 0;
                <?php echo $dir === 'rtl' ? 'left' : 'right'; ?>: -100%;
                height: 100vh;
                width: 300px;
                background: white;
                flex-direction: column;
                padding: 80px 30px 30px;
                box-shadow: <?php echo $dir === 'rtl' ? '5px' : '-5px'; ?> 0 15px rgba(0,0,0,0.1);
                transition: <?php echo $dir === 'rtl' ? 'left' : 'right'; ?> 0.3s ease;
                align-items: flex-start;
                gap: 20px;
            }

            .nav-links.active {
                <?php echo $dir === 'rtl' ? 'left' : 'right'; ?>: 0;
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

            .language-switcher {
                width: 100%;
                justify-content: center;
            }

            .hero-title {
                font-size: 32px;
            }

            .appeals-grid {
                grid-template-columns: 1fr;
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                width: 100%;
                justify-content: space-between;
            }

            .filter-group select {
                flex: 1;
            }

            .modal-content {
                padding: 30px 20px;
            }

            .modal-name {
                font-size: 24px;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 28px;
            }

            .appeal-card {
                padding: 20px;
            }

            .modal-actions {
                flex-direction: column;
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
            <div class="hero-icon">💙</div>
            <h1 class="hero-title"><?php echo t('community.browse.title'); ?></h1>
            <p class="hero-subtitle"><?php echo t('community.browse.subtitle'); ?></p>
            <?php if (isset($_SESSION['sponsor_error'])): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-top: 20px; max-width: 600px; margin-left: auto; margin-right: auto;">
                    <?php 
                    echo htmlspecialchars($_SESSION['sponsor_error']); 
                    unset($_SESSION['sponsor_error']);
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Filter Section -->
    <section class="filter-section">
        <div class="container">
            <div class="filter-controls">
                <div class="filter-group">
                    <label for="categoryFilter"><?php echo t('community.browse.filter_by'); ?>:</label>
                    <select id="categoryFilter" onchange="applyFilters()">
                        <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>><?php echo t('community.browse.all_countries'); ?></option>
                        <?php foreach ($category_keys as $key => $trans_key): ?>
                            <option value="<?php echo $key; ?>" <?php echo $category_filter === $key ? 'selected' : ''; ?>>
                                <?php echo t('submit_appeal.' . $trans_key); ?> 
                                <?php echo isset($category_counts[$key]) ? '(' . $category_counts[$key] . ')' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sortBy"><?php echo t('community.browse.sort_by'); ?>:</label>
                    <select id="sortBy" onchange="applyFilters()">
                        <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>><?php echo t('community.browse.newest'); ?></option>
                        <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>><?php echo t('community.browse.oldest'); ?></option>
                    </select>
                </div>

                <div class="results-count">
                    <strong><?php echo count($appeals); ?></strong> <?php echo count($appeals) === 1 ? t('community.browse.card.from') : t('community.browse.card.from'); ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Appeals Section -->
    <section class="appeals-section">
        <div class="container">
            <?php if (count($appeals) > 0): ?>
                <div class="appeals-grid">
                    <?php foreach ($appeals as $appeal): ?>
                        <div class="appeal-card" onclick="showDetails(<?php echo $appeal['id']; ?>)">
                            <div class="appeal-header">
                                <span class="appeal-category"><?php echo t('submit_appeal.' . ($category_keys[$appeal['category']] ?? 'cat_other')); ?></span>
                                <span class="appeal-date"><?php echo date('M j', strtotime($appeal['submitted_at'])); ?></span>
                            </div>
                            
                            <div class="appeal-info">
                                <h3 class="appeal-name"><?php echo htmlspecialchars($appeal['name']); ?>, <?php echo htmlspecialchars($appeal['age']); ?></h3>
                                <div class="appeal-location"><?php echo htmlspecialchars($appeal['city']); ?>, <?php echo htmlspecialchars($appeal['country']); ?></div>
                                <div class="appeal-issue"><?php echo htmlspecialchars($appeal['issue']); ?></div>
                            </div>
                            
                            <p class="appeal-text"><?php echo htmlspecialchars($appeal['appeal_text']); ?></p>
                            
                            <div class="appeal-actions" onclick="event.stopPropagation()">
                                <button class="btn-sponsor" onclick="sponsorAppeal(<?php echo $appeal['id']; ?>)"><?php echo t('community.browse.card.sponsor_now'); ?></button>
                                <button class="btn-view" onclick="showDetails(<?php echo $appeal['id']; ?>)"><?php echo t('community.browse.card.view_details'); ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-results">
                    <div class="no-results-icon">🔍</div>
                    <h2><?php echo t('community.browse.no_appeals'); ?></h2>
                    <p><?php echo t('community.browse.loading'); ?></p>
                    <?php if ($category_filter !== 'all'): ?>
                        <a href="community_browse.php" class="btn-clear-filters"><?php echo t('common.back'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Modal for Appeal Details -->
    <div class="modal" id="appealModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div id="modalBody"></div>
        </div>
    </div>

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
        // Appeals data for modal
        const appealsData = <?php echo json_encode($appeals); ?>;
        const categoryTranslations = <?php 
            $cats = [];
            foreach ($category_keys as $key => $trans_key) {
                $cats[$key] = t('submit_appeal.' . $trans_key);
            }
            echo json_encode($cats);
        ?>;

        // Translations for JavaScript
        const translations = {
            sponsor_2: '<?php echo t("community.browse.card.sponsor_now"); ?>',
            sponsor_29: '<?php echo t("community.payment.full_amount"); ?>',
            struggling: '<?php echo t("community.payment.appeal_details"); ?>:'
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

        // Apply Filters
        function applyFilters() {
            const category = document.getElementById('categoryFilter').value;
            const sort = document.getElementById('sortBy').value;
            const currentLang = '<?php echo $lang; ?>';
            window.location.href = `community_browse.php?lang=${currentLang}&category=${category}&sort=${sort}`;
        }

        // Show Appeal Details in Modal
        function showDetails(appealId) {
            const appeal = appealsData.find(a => a.id == appealId);
            if (!appeal) return;

            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = `
                <span class="modal-category">${categoryTranslations[appeal.category] || appeal.category}</span>
                <h2 class="modal-name">${escapeHtml(appeal.name)}, ${appeal.age}</h2>
                <div class="modal-location">📍 ${escapeHtml(appeal.city)}, ${escapeHtml(appeal.country)}</div>
                
                <div class="modal-issue">
                    <strong>${translations.struggling}</strong> ${escapeHtml(appeal.issue)}
                </div>
                
                <div class="modal-story">${escapeHtml(appeal.appeal_text)}</div>
                
                <div class="modal-actions">
                    <button class="btn-sponsor" onclick="sponsorAppeal(${appeal.id})">${translations.sponsor_2}</button>
                    <button class="btn-sponsor" onclick="sponsorAppeal(${appeal.id}, 29)" style="background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);">${translations.sponsor_29}</button>
                </div>
            `;

            document.getElementById('appealModal').classList.add('active');
        }

        // Close Modal
        function closeModal() {
            document.getElementById('appealModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('appealModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Sponsor Appeal
        function sponsorAppeal(appealId, amount = 2) {
            const appeal = appealsData.find(a => a.id == appealId);
            if (!appeal) {
                alert('Error: Appeal not found. Please refresh the page and try again.');
                return;
            }
            
            const currentLang = '<?php echo $lang; ?>';
            const url = `sponsor_payment.php?lang=${currentLang}&appeal_id=${appealId}&amount=${amount}`;
            window.location.href = url;
        }

        // Escape HTML helper
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
		
		
		
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