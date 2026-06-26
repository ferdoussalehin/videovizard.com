<?php
// ============================================================
// vizard_promo4.php — Promotional Video Generator
// Stage 1 v2: 6 categories, product name, AI features/audience,
//             brand auto-fill, two-track pricing display
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/promo_gen.log');
ob_start();

// ── Session ────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }

include 'dbconnect_hdb.php';
include 'config.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$is_debug   = ($admin_id === 34);

// ── API keys ───────────────────────────────────────────────
$apiKey    = $apiKey    ?? $myApiKey ?? $api_Key   ?? $openai_key ?? null;
$falApiKey = (!empty($falApiKey) ? $falApiKey : null)
          ?? (!empty($fal_api_key) ? $fal_api_key : null)
          ?? null;
$MODAL_URL = defined('MODAL_IMAGE_URL') ? MODAL_IMAGE_URL
           : ($MODAL_URL ?? 'https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image');
$apiUrl    = "https://api.openai.com/v1/chat/completions";

// ── Logging ────────────────────────────────────────────────
function promoLog($message, $type = 'INFO') {
    $ts    = date('Y-m-d H:i:s') . '.' . sprintf('%06d', (int)(fmod(microtime(true), 1) * 1000000));
    $entry = "[$ts] [$type] $message" . PHP_EOL;
    @file_put_contents(__DIR__ . '/promo_gen.log', $entry, FILE_APPEND | LOCK_EX);
    @file_put_contents(__DIR__ . '/a_errors_log',  $entry, FILE_APPEND | LOCK_EX);
}

// ── Credit resolver ────────────────────────────────────────
$_plan_row     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1"));
$plan_type     = $_plan_row['plan_type'] ?? 'free_trial';
$is_free_plan  = in_array($plan_type, ['free_plan','free_trial']);

// Load languages from hdb_languages
$_lang_priority = ['en','ur','ar','hi','fr','es','it','fa'];
$_languages_all = [];
$_lr = mysqli_query($conn, "SELECT language_code, lang_desc FROM hdb_languages WHERE status='active' ORDER BY sort_order ASC, lang_desc ASC");
if ($_lr) while ($_lrow = mysqli_fetch_assoc($_lr)) {
    $_languages_all[$_lrow['language_code']] = $_lrow['lang_desc'] ?: $_lrow['language_code'];
}
if (empty($_languages_all['en'])) $_languages_all = array_merge(['en' => 'English'], $_languages_all);

$_user_row     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$_credit_uid   = (!empty($_user_row) && trim((string)($_user_row['role'] ?? '')) === 'Team Member' && (int)($_user_row['team_lead_id'] ?? 0) > 0)
    ? (int)$_user_row['team_lead_id'] : $admin_id;
if ($_credit_uid !== $admin_id) {
    $_lead_row           = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credit_balance FROM hdb_users WHERE id=$_credit_uid LIMIT 1"));
    $user_credit_balance = (int)($_lead_row['credit_balance'] ?? 0);
} else {
    $user_credit_balance = (int)($_user_row['credit_balance'] ?? 0);
}

// ── Company resolver ───────────────────────────────────────
$company = null;
if ($company_id > 0) {
    $co_res = mysqli_query($conn,
        "SELECT id, companyname, description, brand_name, logo_file, website, phone, address, ai_group, ai_subgroup, target_location, target_audience
         FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
if (!$company) {
    $co_res = mysqli_query($conn,
        "SELECT id, companyname, description, brand_name, logo_file, website, phone, address, ai_group, ai_subgroup, target_location, target_audience
         FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
// Always use the actual DB company ID — not the potentially-0 session value
if ($company) {
    $company_id = (int)$company['id'];
    $_SESSION['company_id'] = $company_id;
}
$co_name     = htmlspecialchars($company['companyname'] ?? '');
$co_brand    = htmlspecialchars(!empty($company['brand_name']) ? $company['brand_name'] : ($company['companyname'] ?? ''));
$co_desc     = htmlspecialchars($company['description'] ?? '');
$co_logo     = htmlspecialchars($company['logo_file']   ?? '');
$co_phone    = htmlspecialchars($company['phone']       ?? '');
$co_web      = htmlspecialchars($company['website']     ?? '');
$co_ai_group = trim($company['ai_group'] ?? ''); // e.g. 'Fashion & Beauty'
$co_target_location = trim($company['target_location'] ?? '');
$co_target_audience = trim($company['target_audience'] ?? '');
// Ensure user media folder exists — called AFTER company_id is resolved from DB
require_once __DIR__ . '/user_media_setup.php';
$_umf = ensureUserMediaFolder($admin_id, $company_id);
if (!$_umf['ok']) error_log('[promo] user_media: ' . $_umf['error']);


// ── Run DB setup ───────────────────────────────────────────
promoSetupDB($conn);

// ── All companies for switcher ─────────────────────────────
$all_companies = [];
$acq = mysqli_query($conn, "SELECT id, companyname FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC");
if ($acq) while ($acr = mysqli_fetch_assoc($acq)) $all_companies[] = $acr;

if (isset($_GET['company_id'])) {
    $switched = (int)$_GET['company_id'];
    $valid = false;
    foreach ($all_companies as $c) { if ($c['id'] == $switched) { $valid = true; break; } }
    if ($valid) {
        $_SESSION['company_id'] = $switched;
        mysqli_query($conn, "UPDATE hdb_users SET last_company_id=$switched WHERE id=$admin_id");
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// ══════════════════════════════════════════════════════════
// DB SETUP
// ══════════════════════════════════════════════════════════
function promoSetupDB($conn) {

    // ── hdb_ai_pricing ──────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_ai_pricing (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        service_key   VARCHAR(50)  NOT NULL,
        service_name  VARCHAR(100) NOT NULL,
        service_type  ENUM('image','video','text','stock','assembly') NOT NULL,
        provider      VARCHAR(50)  NOT NULL,
        model_string  VARCHAR(200) DEFAULT NULL,
        unit          ENUM('per_second','per_image','per_video','per_call','flat') NOT NULL,
        our_cost      DECIMAL(10,4) NOT NULL DEFAULT 0,
        our_price     DECIMAL(10,4) NOT NULL DEFAULT 0,
        retry_buffer  DECIMAL(4,2)  NOT NULL DEFAULT 1.40,
        credits       INT           NOT NULL DEFAULT 0,
        is_default    TINYINT(1)   DEFAULT 0,
        is_active     TINYINT(1)   DEFAULT 1,
        notes         VARCHAR(300) DEFAULT NULL,
        updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_key (service_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pricing = [
        "('flux_modal','FLUX Image Modal','image','modal',NULL,'per_image',0.0200,0.0000,1.00,0,1,1,'Storyboard images. Bundled.')",
        "('flux_openai_fallback','GPT Image-1 Fallback','image','openai','gpt-image-1','per_image',0.0400,0.0000,1.00,0,0,1,'Fallback if Modal fails.')",
        "('ltx_fast','LTX 2.3 Fast 1080p','video','fal','fal-ai/ltx-2.3/image-to-video/fast','per_second',0.0400,0.0000,1.40,0,0,1,'$0.04/sec fastest')",
        "('ltx_standard','LTX 2.3 Standard 1080p','video','fal','fal-ai/ltx-2.3/image-to-video','per_second',0.0600,0.0000,1.40,0,0,1,'$0.06/sec')",
        "('hailuo_pro','Hailuo 2.3 Pro 1080p','video','fal','fal-ai/minimax/hailuo-02/standard/image-to-video','per_video',0.4900,0.0000,1.40,0,0,1,'$0.49 flat per 6sec')",
        "('wan_26_flash','Wan 2.6 Flash Budget','video','fal','fal-ai/wan/v2.6/flash/image-to-video','per_second',0.0180,0.0000,1.40,0,0,1,'$0.018/sec cheapest')",
        "('kling_turbo','Kling 2.5 Turbo Pro','video','fal','fal-ai/kling-video/v2.5/turbo/image-to-video','per_second',0.0700,0.0000,1.40,0,0,1,'$0.07/sec')",
        "('promo_ai','AI Promo Video 30sec','video','internal',NULL,'flat',4.3500,12.0000,1.00,120,1,1,'120 credits = $12. AI-generated scenes.')",
        "('promo_stock','Stock Promo Video 30sec','video','internal',NULL,'flat',0.5000,2.0000,1.00,20,1,1,'20 credits = $2. Stock media from library.')",
        "('gpt_scene_plan','GPT Scene Plan','text','openai','gpt-4o-mini','per_call',0.0050,0.0000,1.00,0,1,1,'Bundled.')",
        "('gpt_vision','GPT Vision Analysis','text','openai','gpt-4o-mini','per_call',0.0080,0.0000,1.00,0,1,1,'Bundled.')",
        "('gpt_features','GPT Features+Audience','text','openai','gpt-4o-mini','per_call',0.0030,0.0000,1.00,0,1,1,'Auto-generated on product name entry.')",
        "('gpt_captions','GPT Platform Captions','text','openai','gpt-4o-mini','per_call',0.0030,0.0000,1.00,0,1,1,'Bundled.')",
        "('ffmpeg_assembly','FFmpeg Assembly','assembly','server','/usr/bin/ffmpeg','flat',0.0050,0.0000,1.00,0,1,1,'Bundled.')",
    ];
    foreach ($pricing as $row) {
        mysqli_query($conn, "INSERT IGNORE INTO hdb_ai_pricing
            (service_key,service_name,service_type,provider,model_string,unit,
             our_cost,our_price,retry_buffer,credits,is_default,is_active,notes)
            VALUES $row");
    }

    // ── hdb_promo_categories (6 categories) ────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_promo_categories (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        category_key    VARCHAR(50)  NOT NULL,
        category_name   VARCHAR(100) NOT NULL,
        category_icon   VARCHAR(10)  NOT NULL,
        sub_categories  JSON         NOT NULL,
        has_product_name  TINYINT(1) DEFAULT 1,
        product_name_label VARCHAR(60) DEFAULT 'Product Name',
        product_name_placeholder VARCHAR(200) DEFAULT 'Enter product name',
        needs_image     TINYINT(1)   DEFAULT 1,
        needs_model     TINYINT(1)   DEFAULT 0,
        ai_group_map    VARCHAR(100) DEFAULT NULL,
        sort_order      INT          DEFAULT 0,
        is_active       TINYINT(1)   DEFAULT 1,
        UNIQUE KEY uniq_key (category_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cats = [
        "('fashion','Fashion & Beauty','👗',
          '[\"Bridal wear\",\"Party wear\",\"Casual wear\",\"Jewellery\",\"Shoes\",\"Makeup & Skincare\",\"Bags & Accessories\",\"Modest fashion\"]',
          1,'Product Name','e.g. Crimson Bridal Lehnga — Zardozi Collection',
          1,1,'Fashion',1)",

        "('food','Food & Drink','🍽️',
          '[\"Restaurant\",\"Catering service\",\"Packaged food\",\"Bakery & Desserts\",\"Beverages\",\"Home-cooked / cloud kitchen\"]',
          1,'Dish or Menu Name','e.g. Special Eid Mutton Karahi · Desi Breakfast Platter',
          1,0,'Food & Beverage',2)",

        "('tech_home','Tech, Home & Products','📱',
          '[\"Smartphones & Tablets\",\"Laptops & Computers\",\"Audio & Gaming\",\"Home Appliances\",\"Furniture & Decor\",\"Kitchen & Cookware\",\"Smart Home\",\"Other Products\"]',
          1,'Product Name','e.g. iPhone 16 Pro Max 256GB Space Black',
          1,0,'Technology',3)",

        "('travel_svc','Travel, Events & Services','✈️',
          '[\"Holiday packages\",\"Umrah / Hajj\",\"Honeymoon\",\"Event venue\",\"Wedding planning\",\"Photography & Video\",\"Catering\",\"Social media management\",\"Web & Design\",\"Accounting & Legal\",\"Other services\"]',
          1,'Package or Service Name','e.g. 5-Day Bangkok Trip · Instagram Management Package',
          0,0,'Travel & Tourism',4)",

        "('health_edu','Health, Education & Wellness','💪',
          '[\"Supplements & Nutrition\",\"Gym & Fitness program\",\"Equipment\",\"Clinic & Healthcare\",\"Online course\",\"Tutoring & Coaching\",\"Skills bootcamp\",\"Kids education\",\"Mental wellness\"]',
          1,'Product or Program Name','e.g. 90-Day Transformation Program · Complete Digital Marketing Course',
          0,0,'Health & Fitness',5)",

        "('realestate','Real Estate','🏠',
          '[\"Apartment\",\"House & Villa\",\"Duplex\",\"Plot & Land\",\"Commercial property\",\"Rental — Residential\",\"Rental — Commercial\",\"New project launch\"]',
          1,'Property Title','e.g. 3-Bed Apartment · Bahria Town Phase 7',
          0,0,'Real Estate',6)",
    ];
    foreach ($cats as $row) {
        mysqli_query($conn, "INSERT IGNORE INTO hdb_promo_categories
            (category_key,category_name,category_icon,sub_categories,
             has_product_name,product_name_label,product_name_placeholder,
             needs_image,needs_model,ai_group_map,sort_order)
            VALUES $row");
    }

    // ── hdb_promo_briefs ────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_promo_briefs (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        podcast_id          INT          NOT NULL DEFAULT 0,
        admin_id            INT          NOT NULL,
        company_id          INT          NOT NULL,
        company_name        VARCHAR(200) DEFAULT NULL,
        brand_name          VARCHAR(200) DEFAULT NULL,
        category_key        VARCHAR(50)  NOT NULL,
        sub_category        VARCHAR(100) DEFAULT NULL,
        product_name        VARCHAR(300) DEFAULT NULL,
        key_features        TEXT,
        target_audience     TEXT,
        offer_text          TEXT,
        highlight           VARCHAR(300) DEFAULT NULL,
        price_text          VARCHAR(100) DEFAULT NULL,
        urgency_text        VARCHAR(200) DEFAULT NULL,
        special_instructions TEXT         DEFAULT NULL,
        target_location     VARCHAR(200) DEFAULT NULL,
        audience_detail     VARCHAR(500) DEFAULT NULL,
        cta_action          VARCHAR(100) DEFAULT NULL,
        cta_contact         VARCHAR(200) DEFAULT NULL,
        extra_fields        JSON         DEFAULT NULL,
        dress_description   TEXT,
        model_fingerprint   TEXT,
        product_image_path  VARCHAR(500) DEFAULT NULL,
        product_image_name  VARCHAR(200) DEFAULT NULL,
        scene_plan          JSON         DEFAULT NULL,
        video_track         ENUM('ai','stock') DEFAULT 'ai',
        estimated_cost      DECIMAL(8,2) DEFAULT 0,
        actual_cost         DECIMAL(8,2) DEFAULT 0,
        credits_charged     INT          DEFAULT 0,
        estimated_mins_min  INT          DEFAULT 0,
        estimated_mins_max  INT          DEFAULT 0,
        video_model_key     VARCHAR(50)  DEFAULT 'ltx_fast',
        status              VARCHAR(50)  DEFAULT 'draft',
        created_at          DATETIME     DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_admin   (admin_id),
        INDEX idx_podcast (podcast_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Extend hdb_podcast_stories ──────────────────────────
    $story_cols = [];
    $sc = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories");
    if ($sc) while ($r = mysqli_fetch_assoc($sc)) $story_cols[] = $r['Field'];
    $add = [
        'visual_type'      => "VARCHAR(20)  DEFAULT 'ai_image'",
        'motion_prompt'    => "TEXT         DEFAULT NULL",
        'image_prompt'     => "TEXT         DEFAULT NULL",
        'stock_query'      => "VARCHAR(300) DEFAULT NULL",
        'stock_clip_url'   => "VARCHAR(500) DEFAULT NULL",
        'image_url'        => "VARCHAR(500) DEFAULT NULL",
        'fal_request_id'   => "VARCHAR(200) DEFAULT NULL",
        'onscreen_caption' => "VARCHAR(200) DEFAULT NULL",
        'user_feedback'    => "TEXT         DEFAULT NULL",
        'image_cost'       => "DECIMAL(8,4) DEFAULT 0",
        'video_cost'       => "DECIMAL(8,4) DEFAULT 0",
        'provider_used'    => "VARCHAR(20)  DEFAULT NULL",
        'user_approved'    => "TINYINT(1)   DEFAULT 0",
        'promo_brief_id'   => "INT          DEFAULT 0",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $story_cols)) {
            mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN $col $def");
        }
    }
    // ── hdb_promo_prompt_styles ─────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_promo_prompt_styles (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        category_key   VARCHAR(50)  NOT NULL,
        sub_category   VARCHAR(100) DEFAULT NULL,
        style_prefix   TEXT         NOT NULL,
        negative_extra TEXT         DEFAULT NULL,
        cultural_tag   VARCHAR(50)  DEFAULT NULL,
        is_active      TINYINT(1)   DEFAULT 1,
        sort_order     INT          DEFAULT 0,
        KEY idx_cat (category_key, sub_category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default styles if empty
    $cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as n FROM hdb_promo_prompt_styles"))['n'] ?? 0;
    if ((int)$cnt === 0) {
        $seeds = [
            ["fashion","Bridal wear","Pakistani bridal fashion photography, South Asian aesthetic, richly decorated wedding venue, marigold garlands, warm golden lighting, traditional Pakistani wedding setting","western wedding, white dress, church","south_asian"],
            ["fashion","Modest fashion","Modest fashion editorial photography, elegant Islamic fashion styling, refined Middle Eastern or South Asian setting, soft diffused studio lighting","revealing clothing, western fashion, bare shoulders","middle_eastern"],
            ["fashion","Casual wear","Modern lifestyle fashion photography, urban street style, clean natural light, contemporary casual setting, trendy editorial look",NULL,NULL],
            ["fashion","Party wear","Evening fashion editorial, glamorous party setting, warm ambient lighting, elegant event venue",NULL,NULL],
            ["fashion","Jewellery","Luxury jewellery editorial photography, close-up product detail, soft studio lighting, elegant backdrop, high-end fashion magazine style","text, typography",NULL],
            ["fashion","Kids clothing","Children fashion photography, bright cheerful setting, natural playful environment, soft warm lighting",NULL,NULL],
            ["food",NULL,"Professional food photography, appetizing presentation, shallow depth of field, warm natural lighting, restaurant quality plating","text, watermark, dirty",NULL],
            ["food","Pakistani cuisine","Authentic Pakistani food photography, traditional karahi or biryani dish, copper cookware, wooden background with spices, desi restaurant ambiance","western food, text","south_asian"],
            ["health",NULL,"Clean minimalist beauty product photography, white or pastel background, soft diffused lighting, luxury skincare product, elegant aesthetic","text on background",NULL],
            ["home",NULL,"Interior lifestyle photography, beautifully styled home environment, warm ambient lighting, modern decor, product featured prominently",NULL,NULL],
            ["tech",NULL,"Premium technology product photography, sleek dark gradient background, dramatic studio lighting, modern minimalist aesthetic","text, watermark, person",NULL],
            ["real_estate",NULL,"Professional real estate photography, bright well-lit interior, wide angle perspective, clean inviting presentation, golden hour lighting","people, text",NULL],
            ["services",NULL,"Professional service business photography, clean modern workspace, confident professional environment, trust-building visual","text overlay, clichés",NULL],
        ];
        foreach ($seeds as $s) {
            $ck  = mysqli_real_escape_string($conn, $s[0]);
            $sk  = $s[1] ? "'" . mysqli_real_escape_string($conn, $s[1]) . "'" : 'NULL';
            $sp  = mysqli_real_escape_string($conn, $s[2]);
            $ne  = $s[3] ? "'" . mysqli_real_escape_string($conn, $s[3]) . "'" : 'NULL';
            $ct  = $s[4] ? "'" . mysqli_real_escape_string($conn, $s[4]) . "'" : 'NULL';
            mysqli_query($conn, "INSERT IGNORE INTO hdb_promo_prompt_styles (category_key,sub_category,style_prefix,negative_extra,cultural_tag) VALUES ('$ck',$sk,'$sp',$ne,$ct)");
        }
    }

    // ── hdb_credit_charge_history ───────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_credit_charge_history (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        admin_id        INT          NOT NULL,
        team_lead_id    INT          NOT NULL DEFAULT 0,
        company_id      INT          NOT NULL DEFAULT 0,
        podcast_id      INT          NOT NULL DEFAULT 0,
        brief_id        INT          NOT NULL DEFAULT 0,
        credits_charged INT          NOT NULL DEFAULT 0,
        balance_before  INT          NOT NULL DEFAULT 0,
        balance_after   INT          NOT NULL DEFAULT 0,
        charge_type     VARCHAR(50)  NOT NULL DEFAULT 'promo_video',
        video_track     VARCHAR(20)  DEFAULT NULL,
        gen_mode        VARCHAR(20)  DEFAULT NULL,
        duration_sec    INT          DEFAULT 0,
        model_used      VARCHAR(100) DEFAULT NULL,
        description     VARCHAR(300) DEFAULT NULL,
        created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin   (admin_id),
        INDEX idx_company (company_id),
        INDEX idx_podcast (podcast_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Ensure credit_used columns exist ────────────────────
    mysqli_query($conn, "ALTER TABLE hdb_podcasts  ADD COLUMN IF NOT EXISTS credit_used INT DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN IF NOT EXISTS credit_used INT DEFAULT 0");

    mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");
    // Backfill for existing user uploads missing image_folder
    mysqli_query($conn, "UPDATE hdb_image_data SET
        image_folder = CONCAT('user_media/user_id_', admin_id, '_company_id_', company_id, '/',
            IF(media_type='video','podcast_videos','podcast_images'))
        WHERE company_id > 0 AND (image_folder IS NULL OR image_folder = '')");

}

// ══════════════════════════════════════════════════════════
// HELPER: GPT call
// ══════════════════════════════════════════════════════════
function promoCallAI($apiUrl, $apiKey, $system, $user, $temp = 0.80, $timeout = 40) {
    if (!$apiKey) { promoLog("callAI: no key", "ERROR"); return null; }
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $apiKey"],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            "model"       => "gpt-4o-mini",
            "messages"    => [["role"=>"system","content"=>$system],["role"=>"user","content"=>$user]],
            "temperature" => $temp,
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { promoLog("callAI HTTP $http", "ERROR"); return null; }
    $json = json_decode($res, true);
    return $json["choices"][0]["message"]["content"] ?? null;
}

// ══════════════════════════════════════════════════════════
// AJAX: get_promo_categories
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_promo_categories') {
    ob_clean(); header('Content-Type: application/json');
    $cats = [];
    $res  = mysqli_query($conn, "SELECT * FROM hdb_promo_categories WHERE is_active=1 ORDER BY sort_order ASC");
    if ($res) while ($row = mysqli_fetch_assoc($res)) {
        $cats[] = [
            'category_key'              => $row['category_key'],
            'category_name'             => $row['category_name'],
            'category_icon'             => $row['category_icon'],
            'sub_categories'            => json_decode($row['sub_categories'], true),
            'has_product_name'          => (bool)$row['has_product_name'],
            'product_name_label'        => $row['product_name_label'],
            'product_name_placeholder'  => $row['product_name_placeholder'],
            'needs_image'               => (bool)$row['needs_image'],
            'needs_model'               => (bool)$row['needs_model'],
        ];
    }
    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ── AJAX: upload_stock_media ───────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_stock_media') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(120);
    try {
        if (empty($_FILES['stock_media']['tmp_name'])) {
            echo json_encode(['success'=>false,'error'=>'No file uploaded']); exit;
        }
        $file     = $_FILES['stock_media'];
        $mime     = mime_content_type($file['tmp_name']);
        $allowed  = ['image/jpeg','image/png','image/webp','video/mp4','video/webm','video/quicktime'];
        if (!in_array($mime, $allowed)) {
            echo json_encode(['success'=>false,'error'=>'Invalid file type: '.$mime]); exit;
        }
        if ($file['size'] > 50*1024*1024) {
            echo json_encode(['success'=>false,'error'=>'File too large (max 50MB)']); exit;
        }

        $is_video = strpos($mime, 'video/') === 0;
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: ($is_video ? 'mp4' : 'jpg');
        $subdir   = $is_video ? 'podcast_videos' : 'podcast_images';

        // Save to user_media folder
        if (!function_exists('ensureUserMediaFolder')) require_once __DIR__ . '/user_media_setup.php';
        $umf     = ensureUserMediaFolder($admin_id, $company_id);
        $saveDir = ($umf['ok'] ? $umf['path'] : __DIR__) . '/' . $subdir;
        if (!is_dir($saveDir)) @mkdir($saveDir, 0777, true);

        $filename = 'stock_' . time() . '_' . substr(md5($file['name']),0,6) . '.' . $ext;
        $savePath = $saveDir . '/' . $filename;
        $relPath  = ($umf['rel'] ?? 'user_media') . '/' . $subdir . '/' . $filename;

        // Save file
        $saved = @move_uploaded_file($file['tmp_name'], $savePath);
        if (!$saved) $saved = @copy($file['tmp_name'], $savePath);
        if (!$saved || !file_exists($savePath)) {
            echo json_encode(['success'=>false,'error'=>'Could not save file — check folder permissions']); exit;
        }
        @chmod($savePath, 0644);

        // ── Generate thumbnail ─────────────────────────────
        $thumbDir      = ($umf['ok'] ? $umf['path'] : __DIR__) . '/podcast_images/thumbs';
        if (!is_dir($thumbDir)) @mkdir($thumbDir, 0777, true);
        $thumbFilename = pathinfo($filename, PATHINFO_FILENAME) . '_thumb.jpg';
        $thumbPath     = $thumbDir . '/' . $thumbFilename;
        $thumbRel      = ($umf['rel'] ?? 'user_media') . '/podcast_images/thumbs/' . $thumbFilename;
        $thumbOk       = false;

        if ($is_video) {
            // Use known ffmpeg path first, fallback to which
            $ffmpeg = file_exists('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg'
                    : (file_exists('/usr/local/bin/ffmpeg') ? '/usr/local/bin/ffmpeg'
                    : trim(shell_exec('which ffmpeg 2>/dev/null') ?: ''));

            if ($ffmpeg) {
                // Trim video to max 10 seconds
                $trimmedPath = $saveDir . '/trimmed_' . $filename;
                $trimCmd = $ffmpeg . ' -y -i ' . escapeshellarg($savePath)
                         . ' -t 10 -c copy '
                         . escapeshellarg($trimmedPath) . ' 2>/dev/null';
                exec($trimCmd, $trimOut, $trimRet);
                if ($trimRet === 0 && file_exists($trimmedPath) && filesize($trimmedPath) > 1000) {
                    @unlink($savePath);
                    rename($trimmedPath, $savePath);
                    promoLog("stock_media video trimmed to 10s: $filename");
                }

                // Extract thumbnail at 1 second (or 0 if video < 1s)
                $cmd = $ffmpeg . ' -y -i ' . escapeshellarg($savePath)
                     . ' -ss 00:00:01 -vframes 1 -vf "scale=300:-1" '
                     . escapeshellarg($thumbPath) . ' 2>/dev/null';
                exec($cmd, $out, $ret);
                if ($ret !== 0 || !file_exists($thumbPath)) {
                    $cmd2 = $ffmpeg . ' -y -i ' . escapeshellarg($savePath)
                          . ' -ss 00:00:00 -vframes 1 -vf "scale=300:-1" '
                          . escapeshellarg($thumbPath) . ' 2>/dev/null';
                    exec($cmd2, $out2, $ret2);
                    $thumbOk = ($ret2 === 0 && file_exists($thumbPath) && filesize($thumbPath) > 100);
                } else {
                    $thumbOk = file_exists($thumbPath) && filesize($thumbPath) > 100;
                }
            }
            promoLog("stock_media video thumb: ffmpeg=" . ($ffmpeg?'found':'missing') . " ok=" . ($thumbOk?'yes':'no'));
        } else {
            // Image thumbnail using GD
            $info = @getimagesize($savePath);
            if ($info) {
                $src = match($info['mime']) {
                    'image/jpeg' => @imagecreatefromjpeg($savePath),
                    'image/png'  => @imagecreatefrompng($savePath),
                    'image/webp' => @imagecreatefromwebp($savePath),
                    default      => null,
                };
                if ($src) {
                    $sw = imagesx($src); $sh = imagesy($src);
                    $tw = 300; $th = (int)($sh * $tw / $sw);
                    $thumb = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
                    $thumbOk = imagejpeg($thumb, $thumbPath, 85);
                    imagedestroy($src); imagedestroy($thumb);
                }
            }
        }

        // ── Insert into hdb_image_data ─────────────────────
        // Store just filename in image_name, folder separately in image_folder
        $fn_e    = mysqli_real_escape_string($conn, $filename); // just filename
        $name_e  = mysqli_real_escape_string($conn, $file['name']);
        $thumb_e = mysqli_real_escape_string($conn, $thumbOk ? $thumbFilename : '');
        $mt      = $is_video ? 'video' : 'image';
        $sz      = (int)$file['size'];
        $ai_grp  = mysqli_real_escape_string($conn, $co_ai_group ?? '');
        $ai_sub  = mysqli_real_escape_string($conn, trim($company['ai_subgroup'] ?? ''));

        // Store relative path in image_name AND folder separately for easy lookup
        $img_folder_e = mysqli_real_escape_string($conn, ($umf['rel'] ?? 'user_media') . '/' . $subdir);
        // image_name stays as full relative path for backward compat with search
        // image_folder stores the directory part

        // Add image_folder column if missing
        mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");

        mysqli_query($conn, "INSERT INTO hdb_image_data
            (image_name, image_description, image_hashtags, niches, add_by, admin_id, company_id,
             media_type, status, created_at, updated_at, file_size, thumbnail, image_folder,
             ai_group, ai_subgroup, promo_group, promo_subgroup, skip_embedding, tag_flag, resize_flag)
            VALUES
            ('$fn_e', '$name_e', '', '', $admin_id, $admin_id, $company_id,
             '$mt', 'active', NOW(), NOW(), '$sz', '$thumb_e', '$img_folder_e',
             '$ai_grp', '$ai_sub', '$ai_grp', '$ai_sub', 0, 0, 0)");
        $image_id = mysqli_insert_id($conn);

        // ── Auto-tag via GPT-4o vision ─────────────────────
        $nl_tags = ''; $hashtags = ''; $ai_desc = ''; $ai_tags_arr = [];
        $vision_src = $thumbOk ? $thumbPath : ($is_video ? null : $savePath);

        if ($vision_src && file_exists($vision_src) && $apiKey) {
            $img_data = @file_get_contents($vision_src);
            if ($img_data) {
                $vext      = 'jpg';
                $b64       = base64_encode($img_data);
                $context   = $co_ai_group
                    ? "This media is for a '$co_ai_group' business" . ($ai_sub ? " in the '$ai_sub' niche" : "") . "."
                    : '';
                $vMessages = [
                    ["role"=>"system","content"=>"You are a media tagging expert. $context
Analyze this image and return JSON with:
- nl_tags: natural language description for embedding search (60-100 words, scene, mood, setting, people, colors, actions, visual style)
- hashtags: 8-12 relevant hashtags without # symbol, comma-separated
- ai_description: 2-3 sentence factual description
- ai_tags: array of 10-15 keyword tags
Return ONLY valid JSON, no markdown."],
                    ["role"=>"user","content"=>[
                        ["type"=>"image_url","image_url"=>["url"=>"data:image/jpeg;base64,{$b64}","detail"=>"low"]],
                        ["type"=>"text","text"=>"Tag this media for video search. Return JSON only."]
                    ]]
                ];
                $vch = curl_init("https://api.openai.com/v1/chat/completions");
                curl_setopt_array($vch,[
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
                    CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer $apiKey"],
                    CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>json_encode(["model"=>"gpt-4o-mini","messages"=>$vMessages,"max_tokens"=>500,"temperature"=>0.4,"response_format"=>["type"=>"json_object"]]),
                ]);
                $vres  = curl_exec($vch);
                $vhttp = curl_getinfo($vch, CURLINFO_HTTP_CODE);
                curl_close($vch);
                if ($vhttp === 200) {
                    $vj  = json_decode($vres, true);
                    $raw = $vj["choices"][0]["message"]["content"] ?? '{}';
                    $parsed = json_decode($raw, true);
                    if ($parsed) {
                        $nl_tags     = trim($parsed['nl_tags']        ?? '');
                        $hashtags    = trim($parsed['hashtags']        ?? '');
                        $ai_desc     = trim($parsed['ai_description']  ?? '');
                        $ai_tags_arr = $parsed['ai_tags'] ?? [];
                    }
                }
            }
        }

        // ── Generate embedding + save tags ─────────────────
        $embedding_dims = 0;
        if ($nl_tags && $apiKey) {
            $ch_emb = curl_init('https://api.openai.com/v1/embeddings');
            curl_setopt_array($ch_emb,[
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>30,
                CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer $apiKey"],
                CURLOPT_POSTFIELDS=>json_encode(['model'=>'text-embedding-3-large','input'=>$nl_tags]),
            ]);
            $emb_res  = curl_exec($ch_emb);
            $emb_http = curl_getinfo($ch_emb, CURLINFO_HTTP_CODE);
            curl_close($ch_emb);
            if ($emb_http === 200) {
                $emb_data  = json_decode($emb_res, true);
                $embedding = $emb_data['data'][0]['embedding'] ?? null;
                if ($embedding) {
                    $embedding_dims = count($embedding);
                    $nl_e    = mysqli_real_escape_string($conn, $nl_tags);
                    $ht_e    = mysqli_real_escape_string($conn, $hashtags);
                    $ad_e    = mysqli_real_escape_string($conn, $ai_desc);
                    $at_e    = mysqli_real_escape_string($conn, json_encode($ai_tags_arr));
                    $emb_e   = mysqli_real_escape_string($conn, json_encode($embedding));
                    mysqli_query($conn, "UPDATE hdb_image_data SET
                        natural_language_tags='$nl_e', image_hashtags='$ht_e',
                        ai_description='$ad_e', ai_tags='$at_e',
                        embedding='$emb_e', tag_flag=1, tagged_at=NOW(), updated_at=NOW()
                        WHERE id=$image_id");
                    promoLog("stock_media tagged+embedded: id=$image_id dims=$embedding_dims");
                }
            }
        }

        promoLog("stock_media upload OK: file=$filename id=$image_id type=$mt thumb=" . ($thumbOk?$thumbFilename:'none') . " tags=" . ($nl_tags?'yes':'no'));
        echo json_encode([
            'success'        => true,
            'filename'       => $filename,
            'path'           => $relPath,
            'image_id'       => $image_id,
            'media_type'     => $mt,
            'thumbnail'      => $thumbOk ? $thumbRel : null,
            'nl_tags'        => $nl_tags ? substr($nl_tags,0,80).'…' : null,
            'embedding_dims' => $embedding_dims,
            'tagged'         => $nl_tags ? true : false,
        ]);
    } catch (Throwable $e) {
        promoLog("stock_media ERROR: " . $e->getMessage());
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: save_company_ai_group ────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_company_ai_group') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $ai_group    = mysqli_real_escape_string($conn, trim($_POST['ai_group']    ?? ''));
    $ai_subgroup = mysqli_real_escape_string($conn, trim($_POST['ai_subgroup'] ?? ''));
    if ($ai_group && $company_id) {
        mysqli_query($conn, "UPDATE hdb_companies SET
            ai_group='$ai_group', ai_subgroup='$ai_subgroup'
            WHERE id=$company_id AND admin_id=$admin_id");
    }
    echo json_encode(['success'=>true]);
    exit;
}

// AJAX: generate_features_audience
// Triggered after user enters product name
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_features_audience') {
    ob_clean(); header('Content-Type: application/json');

    $product_name = trim($_POST['product_name'] ?? '');
    $category_key = trim($_POST['category_key'] ?? '');
    $sub_category = trim($_POST['sub_category'] ?? '');
    $brand_name   = trim($_POST['brand_name']   ?? $co_brand);

    if (!$product_name) {
        echo json_encode(['success' => false, 'error' => 'No product name']);
        exit;
    }

    // Category-aware prompts
    $context_map = [
        'fashion'     => "fashion/clothing product for Pakistani/South Asian market",
        'food'        => "food, dish or beverage for a restaurant/catering business",
        'tech_home'   => "tech gadget, electronics or home product",
        'travel_svc'  => "travel package, event or service offering",
        'health_edu'  => "health product, fitness program or educational course",
        'realestate'  => "real estate property listing",
    ];
    $context = $context_map[$category_key] ?? "product or service";

    $system = "You are a marketing strategist for small businesses in Pakistan.
Given a product name, brand, and category, generate:
1. key_features: 3-5 bullet points (short phrases, not sentences) highlighting what makes this product/service valuable
2. target_audience: One sentence describing who this is for — age, lifestyle, need

Return ONLY valid JSON, no markdown:
{
  \"key_features\": \"feature 1 · feature 2 · feature 3 · feature 4\",
  \"target_audience\": \"one sentence description\"
}";

    $user = "Product: $product_name
Brand: $brand_name
Category: $context
Sub-category: $sub_category";

    $result = promoCallAI($apiUrl, $apiKey, $system, $user, 0.75);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'AI unavailable']);
        exit;
    }

    // Strip markdown fences if present
    $clean = trim(preg_replace('/^```json\s*|\s*```$/s', '', $result));
    $data  = json_decode($clean, true);

    if (!$data || empty($data['key_features'])) {
        echo json_encode(['success' => false, 'error' => 'Could not parse AI response']);
        exit;
    }

    promoLog("generate_features: product=$product_name cat=$category_key features=" . substr($data['key_features'], 0, 60));

    echo json_encode([
        'success'         => true,
        'key_features'    => $data['key_features'],
        'target_audience' => $data['target_audience'] ?? '',
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════
// AJAX: detect_subcategory
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'detect_subcategory') {
    ob_clean(); header('Content-Type: application/json');

    $offer_text   = trim($_POST['offer_text']   ?? '');
    $product_name = trim($_POST['product_name'] ?? '');
    $category_key = trim($_POST['category_key'] ?? '');

    $ck  = mysqli_real_escape_string($conn, $category_key);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT sub_categories FROM hdb_promo_categories WHERE category_key='$ck' LIMIT 1"));
    $subs = $row ? json_decode($row['sub_categories'], true) : [];
    if (empty($subs)) { echo json_encode(['success'=>true,'sub_category'=>'']); exit; }

    $subs_list = implode(', ', $subs);
    $input     = $product_name ?: $offer_text;

    $system = "You detect sub-category from a short product/service description.
Available: $subs_list
Return ONLY the matching sub-category name exactly as listed. No explanation.";

    $result = promoCallAI($apiUrl, $apiKey, $system, $input, 0.3);
    $sub    = trim($result ?? $subs[0] ?? '');
    if (!in_array($sub, $subs)) $sub = $subs[0] ?? $sub;

    echo json_encode(['success' => true, 'sub_category' => $sub]);
    exit;
}

// ══════════════════════════════════════════════════════════
// AJAX: save_brief_draft
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_brief_draft') {
    ob_clean(); header('Content-Type: application/json');

    $brief_id       = (int)($_POST['brief_id']       ?? 0);
    $category_key   = mysqli_real_escape_string($conn, trim($_POST['category_key']   ?? ''));
    $sub_category   = mysqli_real_escape_string($conn, trim($_POST['sub_category']   ?? ''));
    $product_name   = mysqli_real_escape_string($conn, trim($_POST['product_name']   ?? ''));
    $key_features   = mysqli_real_escape_string($conn, trim($_POST['key_features']   ?? ''));
    $target_audience= mysqli_real_escape_string($conn, trim($_POST['target_audience']?? ''));
    $offer_text     = mysqli_real_escape_string($conn, trim($_POST['offer_text']     ?? ''));
    $highlight      = mysqli_real_escape_string($conn, trim($_POST['highlight']      ?? ''));
    $price_text     = mysqli_real_escape_string($conn, trim($_POST['price_text']     ?? ''));
    $urgency_text        = mysqli_real_escape_string($conn, trim($_POST['urgency_text']        ?? ''));
    $special_instructions= mysqli_real_escape_string($conn, trim($_POST['special_instructions'] ?? ''));
    $target_location     = mysqli_real_escape_string($conn, trim($_POST['target_location']     ?? ''));
    $audience_detail     = mysqli_real_escape_string($conn, trim($_POST['audience_detail']     ?? ''));
    $duration            = max(10, min(60, (int)($_POST['duration'] ?? 30)));
    $language            = mysqli_real_escape_string($conn, trim($_POST['language'] ?? 'en'));
    $cta_action     = mysqli_real_escape_string($conn, trim($_POST['cta_action']     ?? 'whatsapp'));
    $cta_contact    = mysqli_real_escape_string($conn, trim($_POST['cta_contact']    ?? $co_phone));
    $brand_name_esc = mysqli_real_escape_string($conn, $co_brand);
    $company_name_esc = mysqli_real_escape_string($conn, $co_name);

    // Extra category-specific fields as JSON
    $extra = [];
    foreach (['availability','delivery','area_served','condition','whats_included',
              'duration_trip','departure_date','seats_available','departing_from',
              'result_delivers','target_audience_extra','proof_credibility',
              'property_type','property_location','property_details','property_status',
              'course_duration','course_format','next_batch','current_offer',
              'service_result','ideal_client'] as $k) {
        if (!empty($_POST[$k])) $extra[$k] = trim($_POST[$k]);
    }
    $extra_json = mysqli_real_escape_string($conn, json_encode($extra));

    if (!$category_key) {
        echo json_encode(['success' => false, 'error' => 'Category is required']); exit;
    }

    if ($brief_id > 0) {
        $ok = mysqli_query($conn, "UPDATE hdb_promo_briefs SET
            category_key='$category_key', sub_category='$sub_category',
            company_name='$company_name_esc', brand_name='$brand_name_esc',
            product_name='$product_name', key_features='$key_features',
            target_audience='$target_audience', offer_text='$offer_text',
            highlight='$highlight', price_text='$price_text',
            urgency_text='$urgency_text', cta_action='$cta_action',
            special_instructions='$special_instructions',
            target_location='$target_location', audience_detail='$audience_detail',
            duration=$duration, language='$language',
            cta_contact='$cta_contact', extra_fields='$extra_json',
            status='draft', updated_at=NOW()
            WHERE id=$brief_id AND admin_id=$admin_id");
    } else {
        $ok = mysqli_query($conn, "INSERT INTO hdb_promo_briefs
            (admin_id, company_id, company_name, brand_name, category_key, sub_category,
             product_name, key_features, target_audience, offer_text, highlight,
             price_text, urgency_text, cta_action, cta_contact, extra_fields,
             special_instructions, target_location, audience_detail, duration, language, status)
            VALUES
            ($admin_id, $company_id, '$company_name_esc', '$brand_name_esc',
             '$category_key', '$sub_category', '$product_name', '$key_features',
             '$target_audience', '$offer_text', '$highlight',
             '$price_text', '$urgency_text', '$cta_action', '$cta_contact',
             '$extra_json', '$special_instructions', '$target_location', '$audience_detail', $duration, '$language', 'draft')");
        if ($ok) $brief_id = mysqli_insert_id($conn);
    }

    if (!$ok) {
        promoLog("save_brief FAIL: " . mysqli_error($conn), "ERROR");
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]); exit;
    }

    $_SESSION['promo_brief_id'] = $brief_id;
    $_SESSION['promo_category'] = $category_key;
    $_SESSION['promo_step']     = 2;

    promoLog("save_brief: id=$brief_id cat=$category_key product=$product_name");

    $brief_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_promo_briefs WHERE id=$brief_id LIMIT 1"));

    echo json_encode(['success' => true, 'brief_id' => $brief_id, 'brief' => $brief_row]);
    exit;
}

// ── AJAX: upload_product_image ─────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_product_image') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    try {
        if (empty($_FILES['product_image']['tmp_name'])) {
            echo json_encode(['success'=>false,'error'=>'No file uploaded']); exit;
        }
        $file    = $_FILES['product_image'];
        $allowed = ['image/jpeg','image/png','image/webp'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            echo json_encode(['success'=>false,'error'=>'Invalid type. Use JPG, PNG, or WEBP.']); exit;
        }
        if ($file['size'] > 10*1024*1024) {
            echo json_encode(['success'=>false,'error'=>'File too large (max 10MB)']); exit;
        }
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!$ext) $ext = 'jpg';

        // Get/create user folder
        if (!function_exists('ensureUserMediaFolder')) require_once __DIR__ . '/user_media_setup.php';
        $umf = ensureUserMediaFolder($admin_id, $company_id);
        if (!$umf['ok']) {
            echo json_encode(['success'=>false,'error'=>'Storage not ready: ' . $umf['error']]); exit;
        }

        $saveDir     = getUserMediaPath($admin_id, $company_id, 'product_images');
        $savePathRel = getUserMediaRel($admin_id, $company_id, 'product_images');
        $filename    = 'product_' . time() . '_' . substr(session_id(),0,8) . '.' . $ext;
        $savePath    = $saveDir . '/' . $filename;
        $savePathRel = $savePathRel . '/' . $filename;

        // Ensure saveDir is writable
        if (!is_dir($saveDir)) @mkdir($saveDir, 0777, true);
        @chmod($saveDir, 0777);

        // Try move_uploaded_file first (best), then copy, then file_put_contents
        $saved = false;
        if (is_uploaded_file($file['tmp_name'])) {
            $saved = @move_uploaded_file($file['tmp_name'], $savePath);
        }
        if (!$saved && file_exists($file['tmp_name'])) {
            $saved = @copy($file['tmp_name'], $savePath);
        }
        if (!$saved && file_exists($file['tmp_name'])) {
            $data  = @file_get_contents($file['tmp_name']);
            $saved = $data !== false && @file_put_contents($savePath, $data) !== false;
        }
        if (!$saved) {
            $diag = 'dir=' . $saveDir
                  . ' writable=' . (is_writable($saveDir)?'yes':'NO')
                  . ' dir_exists=' . (is_dir($saveDir)?'yes':'NO')
                  . ' tmp=' . $file['tmp_name']
                  . ' tmp_exists=' . (file_exists($file['tmp_name'])?'yes':'NO');
            promoLog("upload FAIL: $diag");
            echo json_encode(['success'=>false,'error'=>'Could not save file. '.$diag]); exit;
        }
        @chmod($savePath, 0644);

        // Verify the file actually landed on disk
        if (!file_exists($savePath) || filesize($savePath) < 100) {
            promoLog("upload VERIFY FAIL: file not found after save: $savePath");
            echo json_encode(['success'=>false,'error'=>'File save verification failed — file missing after write at ' . $savePath]); exit;
        }
        promoLog("upload verified OK: $savePathRel size=" . filesize($savePath));
        $_SESSION['product_image_path'] = $savePathRel;
        $_SESSION['product_image_name'] = htmlspecialchars($file['name']);

        // GPT-4o vision auto-description (non-fatal if fails)
        $autoDesc = null;
        if ($apiKey) {
            $imgData = @file_get_contents($savePath);
            if ($imgData) {
                $b64     = base64_encode($imgData);
                $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
                $imgMime = $mimeMap[$ext] ?? 'image/jpeg';
                $messages = [
                    ["role"=>"system","content"=>"You are a product analyst. Describe this product image in 2-3 sentences covering: product type, key visual features (shape, color, packaging, materials), and any visible branding. Be factual and concise."],
                    ["role"=>"user","content"=>[
                        ["type"=>"image_url","image_url"=>["url"=>"data:{$imgMime};base64,{$b64}"]],
                        ["type"=>"text","text"=>"Describe this product for a promotional video brief."]
                    ]]
                ];
                $ch = curl_init("https://api.openai.com/v1/chat/completions");
                curl_setopt_array($ch,[
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 40,
                    CURLOPT_HTTPHEADER     => ["Content-Type: application/json","Authorization: Bearer $apiKey"],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(["model"=>"gpt-4o-mini","messages"=>$messages,"max_tokens"=>200,"temperature"=>0.5]),
                ]);
                $res  = curl_exec($ch);
                $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($http === 200) {
                    $j = json_decode($res, true);
                    $autoDesc = $j["choices"][0]["message"]["content"] ?? null;
                    if ($autoDesc) $_SESSION['product_auto_desc'] = $autoDesc;
                }
            }
        }
        promoLog("Image uploaded: $savePathRel auto_desc=" . ($autoDesc?'yes':'no'));
        echo json_encode([
            'success'   => true,
            'path'      => $savePathRel,
            'abs_path'  => $savePath,
            'name'      => $file['name'],
            'size'      => filesize($savePath),
            'auto_desc' => $autoDesc,
        ]);
    } catch (Throwable $e) {
        promoLog("upload_product_image ERROR: " . $e->getMessage());
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: clear_product_image ──────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clear_product_image') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    $path = $_SESSION['product_image_path'] ?? null;
    if ($path) {
        $abs = (strpos($path, '/') === 0) ? $path : __DIR__ . '/' . $path;
        if (file_exists($abs)) @unlink($abs);
    }
    unset($_SESSION['product_image_path'], $_SESSION['product_image_name'], $_SESSION['product_auto_desc']);
    echo json_encode(['success'=>true]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════
// AJAX: get_promo_models
// Returns models for the picker, filtered by sub_category
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_promo_models') {
    ob_clean(); header('Content-Type: application/json');

    $sub_category = mysqli_real_escape_string($conn, trim($_POST['sub_category'] ?? ''));

    // Get default category for this sub_category from mapping table
    $default_category = 'female_formal';
    if ($sub_category) {
        $map_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT model_category, show_also FROM hdb_promo_model_mapping
             WHERE sub_category='$sub_category' LIMIT 1"));
        if ($map_row) $default_category = $map_row['model_category'];
    }

    // Get all active models
    $res    = mysqli_query($conn,
        "SELECT id, filename, category, gender, ethnicity, age_range, pose, description
         FROM hdb_promo_models WHERE is_active=1 ORDER BY category, sort_order ASC");
    $models = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $models[] = $r;

    if (empty($models)) {
        // Fallback: scan the promo_models directory
        $base = __DIR__ . '/promo_models/';
        $cats = ['female_formal','female_casual','female_business','female_hijab','male_casual','male_formal','kids'];
        foreach ($cats as $cat) {
            $dir = $base . $cat . '/';
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $fp) {
                $fn = basename($fp);
                $models[] = [
                    'id'          => 0,
                    'filename'    => $fn,
                    'category'    => $cat,
                    'gender'      => strpos($cat,'male_') === 0 ? 'male' : (strpos($cat,'kids')!==false?'kids':'female'),
                    'ethnicity'   => '',
                    'age_range'   => '',
                    'pose'        => '',
                    'description' => $cat . ' model',
                ];
            }
        }
    }

    // Build unique category list for filter chips
    $cat_labels = [
        'female_formal'    => '👗 Female Formal',
        'female_casual'    => '👕 Female Casual',
        'female_business'  => '💼 Female Business',
        'female_hijab'     => '🧕 Female Modest',
        'male_casual'      => '👔 Male Casual',
        'male_formal'      => '🤵 Male Formal',
        'kids'             => '👧 Kids',
    ];
    $seen_cats = [];
    $categories = [];
    foreach ($models as $m) {
        if (!in_array($m['category'], $seen_cats)) {
            $seen_cats[] = $m['category'];
            $categories[] = [
                'key'   => $m['category'],
                'label' => $cat_labels[$m['category']] ?? $m['category'],
            ];
        }
    }

    promoLog("get_promo_models: sub=$sub_category default=$default_category models=" . count($models));
    echo json_encode([
        'success'          => true,
        'models'           => $models,
        'categories'       => $categories,
        'default_category' => $default_category,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════
// AJAX: refine_scene_prompt
// Takes user feedback + original prompt → GPT refines it
// Returns new image_prompt + video_prompt
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'refine_scene_prompt') {
    ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(30);
    try {
        $original_prompt = trim($_POST['original_prompt'] ?? '');
        $caption         = trim($_POST['caption']         ?? '');
        $feedback        = trim($_POST['feedback']        ?? '');
        $track           = trim($_POST['track']           ?? 'ai');
        $needs_model     = (int)($_POST['needs_model']    ?? 0);

        if (!$feedback) { echo json_encode(['success'=>false,'error'=>'No feedback']); exit; }

        $context = $needs_model
            ? "This is a BACKGROUND/ENVIRONMENT description for a fashion try-on video. The actual garment is composited from the product photo — you CANNOT change garment details (sleeves, color, length) via this prompt. Focus ONLY on: setting, background, lighting, mood, environment. If user mentions garment details, adapt by changing the environment to complement that style instead."
            : "This is a full scene image prompt for an AI-generated product video.";

        $system = "You are a creative director refining AI image generation prompts.
$context

Given the original prompt, caption, and user feedback, return an improved prompt.
Keep it 50-70 words. Photorealistic, cinematic, commercial quality.
Return ONLY a JSON object: {\"new_prompt\": \"...\", \"video_prompt\": \"...\"}
video_prompt = short cinematic camera movement description (10-15 words).
No markdown, no explanation.";

        $user = "Original prompt: $original_prompt
Caption: $caption
User feedback: $feedback

Refine the prompt incorporating the feedback.";

        $raw = promoCallAI($apiUrl, $apiKey, $system, $user, 0.8, 300);
        if (!$raw) { echo json_encode(['success'=>false,'error'=>'AI did not respond']); exit; }

        $clean  = trim(preg_replace('/^```json\s*|\s*```$/s', '', $raw));
        $result = json_decode($clean, true);

        if (!isset($result['new_prompt'])) {
            // Try to extract if GPT returned plain text
            $result = ['new_prompt' => trim($raw), 'video_prompt' => 'slow gentle push in'];
        }

        promoLog("refine_prompt: feedback=" . substr($feedback,0,60) . " new=" . substr($result['new_prompt'],0,80));
        echo json_encode(['success'=>true, 'new_prompt'=>$result['new_prompt'], 'video_prompt'=>$result['video_prompt']??'slow gentle push in']);

    } catch (Throwable $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// AJAX: generate_scene_plan
// Stock track: captions + stock_query
// AI track:    captions + image_prompt + video_prompt
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_plan') {
    ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(90);

    try {

    $brief_id        = (int)($_POST['brief_id'] ?? 0);
    $track           = trim($_POST['track'] ?? 'stock');
    $needs_model     = (int)($_POST['needs_model'] ?? 0);
    $duration        = max(10, min(60, (int)($_POST['duration'] ?? 30)));
    $language        = trim($_POST['language'] ?? 'en');
    $target_location = trim($_POST['target_location'] ?? '');
    $audience_detail = trim($_POST['audience_detail'] ?? '');

    $lang_names = ['en'=>'English','ur'=>'Urdu','ar'=>'Arabic','hi'=>'Hindi','es'=>'Spanish','fr'=>'French','tr'=>'Turkish','id'=>'Indonesian'];
    $lang_name  = $lang_names[$language] ?? 'English';
    $lang_instr = $language !== 'en' ? "IMPORTANT: Write ALL captions in $lang_name language only." : "Write all captions in English.";

    if (!$brief_id) { echo json_encode(['success'=>false,'error'=>'No brief_id']); exit; }

    $brief = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM hdb_promo_briefs WHERE id=$brief_id AND admin_id=$admin_id LIMIT 1"));
    if (!$brief) { echo json_encode(['success'=>false,'error'=>'Brief not found']); exit; }

    $product  = $brief['product_name']    ?? '';
    $brand    = $brief['brand_name']      ?? $co_name;
    $features = $brief['key_features']    ?? '';
    $audience = $brief['target_audience'] ?? '';
    $offer    = $brief['offer_text']      ?? '';
    $highlight= $brief['highlight']       ?? '';
    $price    = $brief['price_text']      ?? '';
    $urgency  = $brief['urgency_text']         ?? '';
    $special_instructions = $brief['special_instructions'] ?? '';
    // Use POST values first, then fall back to saved brief values
    if (!$target_location) $target_location = trim($brief['target_location'] ?? '');
    if (!$audience_detail) $audience_detail = trim($brief['audience_detail'] ?? '');
    if (!$duration || $duration === 30) $duration = (int)($brief['duration'] ?? 30);
    if (!$language || $language === 'en') $language = $brief['language'] ?? 'en';
    $cta_act  = $brief['cta_action']      ?? 'whatsapp';
    $cta_con  = $brief['cta_contact']     ?? $co_phone;
    $category = $brief['category_key']    ?? '';
    $sub_cat  = $brief['sub_category']    ?? '';

    $product_image_path = trim($_POST['product_image_path'] ?? $_SESSION['product_image_path'] ?? '');

    // ── Vision analysis of garment/product image ──────────
    $visual_context = '';
    if ($product_image_path && $apiKey) {
        $abs_path = (strpos($product_image_path, '/') === 0)
            ? $product_image_path
            : __DIR__ . '/' . $product_image_path;
        if (file_exists($abs_path)) {
            $img_data = @file_get_contents($abs_path);
            if ($img_data) {
                $ext_v   = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
                $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
                $imgMime = $mimeMap[$ext_v] ?? 'image/jpeg';
                $b64     = base64_encode($img_data);
                $vMessages = [
                    ["role"=>"system","content"=>"You are a fashion and product analyst. Describe this item in detail covering: exact product type and style, colors, patterns (embroidery, prints, embellishments), cultural style (e.g. Pakistani, South Asian, Western), fabric texture, silhouette, and any distinctive design elements. Be specific and factual — 3-4 sentences."],
                    ["role"=>"user","content"=>[
                        ["type"=>"image_url","image_url"=>["url"=>"data:{$imgMime};base64,{$b64}"]],
                        ["type"=>"text","text"=>"Describe this product for AI image generation prompts."]
                    ]]
                ];
                $vch = curl_init("https://api.openai.com/v1/chat/completions");
                curl_setopt_array($vch, [
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
                    CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer $apiKey"],
                    CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>json_encode(["model"=>"gpt-4o-mini","messages"=>$vMessages,"max_tokens"=>250,"temperature"=>0.4]),
                ]);
                $vres  = curl_exec($vch);
                $vhttp = curl_getinfo($vch, CURLINFO_HTTP_CODE);
                curl_close($vch);
                if ($vhttp === 200) {
                    $vj = json_decode($vres, true);
                    $visual_context = trim($vj["choices"][0]["message"]["content"] ?? '');
                }
                promoLog("vision analysis: " . substr($visual_context, 0, 100));
            }
        }
    }

    // ── Load cultural style prefix from DB ─────────────────
    $style_prefix_gpt = '';
    if ($category) {
        $cat_e = mysqli_real_escape_string($conn, $category);
        $sub_e = mysqli_real_escape_string($conn, $sub_cat);
        $ps = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT style_prefix FROM hdb_promo_prompt_styles
             WHERE category_key='$cat_e'
               AND (sub_category='$sub_e' OR sub_category IS NULL)
               AND is_active=1
             ORDER BY (sub_category IS NOT NULL) DESC LIMIT 1"));
        if ($ps) $style_prefix_gpt = trim($ps['style_prefix'] ?? '');
    }
    $style_block = $style_prefix_gpt
        ? "\nCULTURAL/PRODUCT STYLE CONTEXT (use in every image prompt):\n$style_prefix_gpt\n"
        : '';

    $visual_block = ($visual_context
        ? "\nPRODUCT VISUAL ANALYSIS:\n$visual_context\n"
        : '')
        . $style_block;

    $user_input = "Product/Service: $product
Brand: $brand
Category: $category" . ($sub_cat ? " — $sub_cat" : '') . "
Key features: $features
Target audience: $audience" .
    ($audience_detail ? "\nAudience detail: $audience_detail"          : '') .
    ($target_location ? "\nTarget location: $target_location"          : '') . "
Offer details: $offer" .
    ($highlight   ? "\nHighlight: $highlight"                  : '') .
    ($price       ? "\nPrice/Discount: $price"                 : '') .
    ($urgency     ? "\nUrgency: $urgency"                      : '') .
    ($special_instructions ? "\nSpecial instructions: $special_instructions" : '') . "
Call to action: $cta_text

IMPORTANT RULES FOR CAPTIONS:
- Scene 1 (hook): Must be a SCROLL-STOPPING question or bold statement that creates curiosity or addresses a pain point.
- Scene 3 (product): Do NOT just say 'Introducing [Brand]'. Show the VALUE using key features.
- Use the offer, price, urgency in relevant scenes.
- Every caption must be specific to THIS product, not generic copy.

HASHTAG RULES (generate 30 hashtags in this mix):
- 10 niche product hashtags (specific to product type, features, cultural context)
" . ($target_location ? "- 10 location hashtags (specific to: $target_location — include city, region, community hashtags)\n" : "- 10 broad reach hashtags for the target market\n") . "- 10 broad fashion/wedding/trending hashtags
- Use no # symbol in the output — just the words separated by commas
- Make them highly relevant: use product name, category ($sub_cat), features, and audience ($audience" . ($audience_detail ? ", $audience_detail" : '') . ")";

    // Calculate scene durations proportionally based on total duration
    $scene_secs = [
        'hook'=>round($duration*0.10), 'problem'=>round($duration*0.13),
        'product'=>round($duration*0.17), 'feature'=>round($duration*0.13),
        'lifestyle'=>round($duration*0.17), 'social'=>round($duration*0.17), 'cta'=>round($duration*0.13)
    ];
    // Adjust last scene so total = duration
    $total_assigned = array_sum($scene_secs);
    $scene_secs['cta'] += ($duration - $total_assigned);

    // ── Stock track prompt ────────────────────────────────
    if ($track === 'stock') {
        $system = "You are a video director and copywriter for short-form social media promotional videos.
Create a 7-scene, {$duration}-second storyboard for a stock-footage promotional video.
$lang_instr

Scene arc (use exactly in this order):
1. hook       — Attention-grabbing opening ({$scene_secs['hook']} seconds)
2. problem    — The pain point or desire the product solves ({$scene_secs['problem']} seconds)
3. product    — Product/service reveal ({$scene_secs['product']} seconds)
4. feature    — Key feature or benefit ({$scene_secs['feature']} seconds)
5. lifestyle  — Target customer enjoying the result ({$scene_secs['lifestyle']} seconds)
6. social     — Trust signal or proof ({$scene_secs['social']} seconds)
7. cta        — Call to action close ({$scene_secs['cta']} seconds)

For each scene return:
- scene_type: one of: hook, problem, product, feature, lifestyle, social, cta
- caption: short punchy on-screen text (max 8 words, no hashtags) in $lang_name
- stock_query: 3-5 word search query for a stock footage library (concrete, visual, no brand names, in English)
- duration_sec: integer seconds (must total {$duration})

Return ONLY a valid JSON array of 7 objects. No markdown, no explanation.
Example: [{\"scene_type\":\"hook\",\"caption\":\"caption here\",\"stock_query\":\"woman opening gift box\",\"duration_sec\":{$scene_secs['hook']}}]";

    } else {
        // ── AI track prompt ───────────────────────────────
        // needs_model=1: fashion/wearable — model wears the garment, backgrounds vary
        // needs_model=0: product scenes — FLUX generates full scene images
        if ($needs_model) {
            $system = "You are a creative director for AI-generated fashion promotional videos.
The user has a garment/product that will be virtually worn by a model in each scene.
$visual_block
$lang_instr
Create a 7-scene, {$duration}-second storyboard. The model wearing the garment appears in every scene with a DIFFERENT background/environment/lighting.

IMPORTANT: Each scene duration must be either 4 or 6 seconds only (fal.ai requirement). Total must equal {$duration} seconds.

Scene arc:
1. hook       — Dramatic reveal ({$scene_secs['hook']} sec) — striking studio backdrop with colored lighting
2. detail     — Focus on garment details ({$scene_secs['problem']} sec) — clean white/cream studio, soft lighting
3. lifestyle  — Real-world culturally-appropriate setting ({$scene_secs['product']} sec)
4. elegance   — Luxury environment ({$scene_secs['feature']} sec) — marble interior, chandeliers
5. dynamic    — Movement/energy ({$scene_secs['lifestyle']} sec) — walking toward camera, fabric flowing
6. social     — Social celebration moment ({$scene_secs['social']} sec)
7. cta        — Bold call to action ({$scene_secs['cta']} sec) — gradient studio background

For each scene return:
- scene_type: one of: hook, detail, lifestyle, elegance, dynamic, social, cta
- caption: short punchy on-screen text (max 8 words) in $lang_name
- image_prompt: describe ONLY the background/environment/lighting/setting (40-60 words, in English)
- video_prompt: cinematic camera movement for the clip (10-15 words, in English)
- duration_sec: integer — MUST be 4 or 6 only

Return ONLY a valid JSON array of 7 objects. No markdown, no explanation.";
        } else {
            $system = "You are a creative director for AI-generated product promotional videos.
$visual_block
Create a 7-scene, 30-second storyboard. Each scene will be fully AI-generated using FLUX image generation.

IMPORTANT: Each scene duration must be either 4 or 6 seconds only (fal.ai requirement). Total must equal 30 seconds.

Scene arc:
1. hook       — Bold attention-grabbing opener (4 sec)
2. problem    — Pain point the product solves (4 sec)
3. product    — Hero product shot, glamorous (6 sec)
4. feature    — Key benefit shown visually (4 sec)
5. lifestyle  — Customer using/enjoying the product (4 sec)
6. social     — Trust/results/testimonial moment (4 sec)
7. cta        — Strong call to action (4 sec)

For each scene return:
- scene_type: one of: hook, problem, product, feature, lifestyle, social, cta
- caption: short punchy on-screen text (max 8 words)
- image_prompt: detailed photorealistic FLUX image generation prompt (60-80 words). Include: the product prominently, scene setting, lighting style, camera angle, mood, color palette. Style: commercial product photography, 9:16 vertical format.
- video_prompt: cinematic camera movement for the clip (15-20 words)
- stock_query: 3-5 word fallback stock search query
- duration_sec: integer — MUST be 4 or 6 only

Return ONLY a valid JSON array of 7 objects. No markdown, no explanation.";
        }
    }

    $raw = promoCallAI($apiUrl, $apiKey, $system, $user_input, 0.82, 2000);

    if (!$raw) { echo json_encode(['success'=>false,'error'=>'AI did not respond. Check API key.']); exit; }

    // Strip markdown fences and extract JSON array
    $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $raw));
    // Extract just the JSON array if GPT added text around it
    if (preg_match('/(\[\s*\{.*\}\s*\])/s', $clean, $m)) {
        $clean = $m[1];
    }
    $scenes = json_decode($clean, true);

    if (!is_array($scenes) || count($scenes) < 3) {
        promoLog("scene_plan parse fail: " . substr($raw, 0, 300), 'ERROR');
        echo json_encode(['success'=>false,'error'=>'Could not parse scenes','raw'=>substr($raw,0,400)]); exit;
    }

    // Normalise output — handle both tracks
    $out   = [];
    $types = ['hook','problem','product','feature','lifestyle','social','cta'];
    foreach ($scenes as $i => $sc) {
        $out[] = [
            'seq'          => $i + 1,
            'scene_type'   => $sc['scene_type']    ?? ($types[$i] ?? 'feature'),
            'caption'      => trim($sc['caption']   ?? ''),
            'stock_query'  => trim($sc['stock_query'] ?? $sc['image_prompt'] ?? ''),
            'image_prompt' => trim($sc['image_prompt'] ?? ''),
            'video_prompt' => trim($sc['video_prompt'] ?? 'slow gentle push in'),
            'duration_sec' => (int)($sc['duration_sec'] ?? 4),
        ];
    }

    promoLog("scene_plan: brief=$brief_id track=$track needs_model=$needs_model scenes=" . count($out) . " product=$product", 'STAGE2');
    echo json_encode(['success'=>true,'scenes'=>$out,'track'=>$track,'needs_model'=>(bool)$needs_model]);

    } catch (Throwable $e) {
        promoLog("scene_plan EXCEPTION: " . $e->getMessage() . " line " . $e->getLine(), 'ERROR');
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════════
// AJAX: save_storyboard
// Creates hdb_podcasts + hdb_podcast_stories rows,
// deducts credits, updates promo_brief status
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_storyboard') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(60);

    try {
        $brief_id    = (int)($_POST['brief_id'] ?? 0);
        $track       = trim($_POST['track']     ?? 'stock');
        $scenes_json = $_POST['scenes']         ?? '[]';
        $scenes      = json_decode($scenes_json, true);

        if (!$brief_id || !is_array($scenes) || count($scenes) === 0) {
            echo json_encode(['success'=>false,'error'=>'Missing brief or scenes']); exit;
        }

        // Load brief
        $brief = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM hdb_promo_briefs WHERE id=$brief_id AND admin_id=$admin_id LIMIT 1"));
        if (!$brief) { echo json_encode(['success'=>false,'error'=>'Brief not found']); exit; }

        // Team lead for credits
        $_u = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $team_lead_id = (!empty($_u) && trim((string)($_u['role']??''))==='Team Member' && (int)($_u['team_lead_id']??0)>0)
            ? (int)$_u['team_lead_id'] : $admin_id;

        // Credit amounts — look up directly (not from page-scope vars)
        $pr_ai    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credits FROM hdb_ai_pricing WHERE service_key='promo_ai' LIMIT 1"));
        $pr_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credits FROM hdb_ai_pricing WHERE service_key='promo_stock' LIMIT 1"));
        $cr_ai    = (int)($pr_ai['credits']    ?? 120);
        $cr_stock = (int)($pr_stock['credits'] ?? 20);
        $credits_charge = $track === 'stock' ? $cr_stock : $cr_ai;

        $product   = $brief['product_name'] ?? 'Promo Video';
        $title_raw = $product . ' — Promo Video';
        $niche     = mysqli_real_escape_string($conn, $product);
        $esc_title = mysqli_real_escape_string($conn, $title_raw);
        $today     = date('Y-m-d');
        $video_type = $track === 'stock' ? 'stock_promo' : 'ai_promo';

        // Voice from the modal (passed when starting the build)
        $host_voice = mysqli_real_escape_string($conn, trim($_POST['host_voice'] ?? 'openai:alloy'));
        $voice_rate = (float)($_POST['rate'] ?? 1.1);

        // Keywords/hashtags from captions
        $all_text = implode(' ', array_column($scenes, 'caption'));
        $stop = ['the','and','for','you','your','with','that','this','are','can','will'];
        $words = array_diff(str_word_count(strtolower($all_text), 1), $stop);
        $kw_arr = array_slice(array_unique(array_values($words)), 0, 10);
        $ht_arr = array_map(fn($w) => '#'.$w, array_slice($kw_arr,0,7));
        $hashtags = mysqli_real_escape_string($conn, implode(', ', $ht_arr));
        $keywords = mysqli_real_escape_string($conn, implode(', ', $kw_arr));

        // Ensure optional columns exist on hdb_podcast_stories
        $story_cols = [];
        $sc_res = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories");
        if ($sc_res) while ($r = mysqli_fetch_assoc($sc_res)) $story_cols[] = $r['Field'];
        $needed_cols = [
            'stock_query'    => "VARCHAR(300) DEFAULT NULL",
            'stock_clip_url' => "VARCHAR(500) DEFAULT NULL",
            'image_url'      => "VARCHAR(500) DEFAULT NULL",
            'image_prompt'   => "TEXT DEFAULT NULL",
            'promo_brief_id' => "INT DEFAULT 0",
            'visual_type'    => "VARCHAR(20) DEFAULT 'stock'",
        ];
        foreach ($needed_cols as $col => $def) {
            if (!in_array($col, $story_cols)) {
                mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN $col $def");
            }
        }

        // Insert hdb_podcasts
        $pod_lang    = mysqli_real_escape_string($conn, $brief['language'] ?? 'en');
        $pod_duration= max(10, min(60, (int)($brief['duration'] ?? 30)));

        $sql_pod = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, title, lang_code, video_type,
             video_status, internal_status, created_date, updated_at,
             niche, category, topic_key, hashtags, keywords,
             host_voice, guest_voice, voice_rate, is_campaign, logo_flag,
             facebook_status, tiktok_status, instagram_status, youtube_status,
             twitter_status, linkedin_status, schedule_date, schedule_time,
             publish_date, video_format, video_media, music_file, hook_name, videogen_flag)
            VALUES
            ($admin_id, $team_lead_id, $company_id, '$esc_title', '$pod_lang', '$video_type',
             'draft', 'scenes_ready', '$today', NOW(),
             '$niche', 'promo', '$niche', '$hashtags', '$keywords',
             '', '', 1.0, 0, 0,
             'pending','pending','pending','pending',
             'pending','pending','$today','09:00',
             '$today','vertical','video','','',1)";

        if (!mysqli_query($conn, $sql_pod)) {
            echo json_encode(['success'=>false,'error'=>'Podcast insert failed: '.mysqli_error($conn)]); exit;
        }
        $podcast_id = (int)mysqli_insert_id($conn);

        // Store voice on podcast immediately + set ai_group and duration
        $co_ai_group_esc = mysqli_real_escape_string($conn, $co_ai_group);
        mysqli_query($conn, "UPDATE hdb_podcasts SET
            host_voice='$host_voice', guest_voice='$host_voice', voice_rate=$voice_rate,
            ai_group='$co_ai_group_esc'
            WHERE id=$podcast_id");
        // Store promo duration — use video_duration column if it exists
        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts LIKE 'video_duration'");
        if ($col_check && mysqli_num_rows($col_check) > 0) {
            mysqli_query($conn, "UPDATE hdb_podcasts SET video_duration=$pod_duration WHERE id=$podcast_id");
        } else {
            // Add the column if missing
            mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN IF NOT EXISTS video_duration INT DEFAULT 30");
            mysqli_query($conn, "UPDATE hdb_podcasts SET video_duration=$pod_duration WHERE id=$podcast_id");
        }

        // Insert scenes — core columns only, then UPDATE optional ones
        $sc_count = count($scenes);
        foreach ($scenes as $i => $sc) {
            $seq     = $i + 1;
            $caption = mysqli_real_escape_string($conn, trim($sc['caption']     ?? ''));
            $sq      = mysqli_real_escape_string($conn, trim($sc['stock_query'] ?? ''));
            $dur     = (int)($sc['duration_sec'] ?? 4);
            $display = mysqli_real_escape_string($conn, substr($caption,0,50).(strlen($caption)>50?'...':''));

            $ins = "INSERT INTO hdb_podcast_stories
                (podcast_id, lang_code, category, topic_key, title, actor,
                 text_contents, text_display, duration, prompt, video_prompt,
                 status, created_date, seq_no, logo_flag, voice_id, voice_rate, videogen_flag)
                VALUES
                ($podcast_id,'$pod_lang','promo','$niche','$esc_title','host',
                 '$caption','$display',$dur,'$sq','$sq',
                 'PENDING',NOW(),$seq,0,'$host_voice',$voice_rate,1)";

            if (mysqli_query($conn, $ins)) {
                $story_id = (int)mysqli_insert_id($conn);
                mysqli_query($conn, "UPDATE hdb_podcast_stories SET
                    stock_query='$sq', visual_type='stock', promo_brief_id=$brief_id
                    WHERE id=$story_id");
            } else {
                promoLog("story insert fail seq=$seq: ".mysqli_error($conn), 'ERROR');
            }
        }

        // ── Write hdb_captions directly here (session is still active) ──
        if (!function_exists('p2_getUserSettings')) {
            // Inline getUserSettings — same as promo_step2.php version
            function p2_getUserSettings($conn, $admin_id, $company_id = 0) {
                if ($company_id > 0) {
                    $q = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' AND company_id='$company_id' ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
                    if (!$q || mysqli_num_rows($q) === 0) $q = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
                } else {
                    $q = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' ORDER BY FIELD(text_type,'caption','header','footer','logo') LIMIT 10");
                }
                $settings = ['caption'=>null,'header'=>null,'footer'=>null,'logo'=>null];
                if ($q) while ($r = mysqli_fetch_assoc($q)) { $t = $r['text_type']??'caption'; if (array_key_exists($t,$settings)) $settings[$t]=$r; }
                $def = ['text_type'=>'caption','is_enabled'=>1,'fontfamily'=>'Arial','fontsize'=>28,'fontcolor'=>'#ffff00','fontweight'=>'bold','fontcolor_bg'=>'#000000','fontbg_enable'=>0,'caption_style'=>'none','caption_position'=>'bottom','caption_alignment'=>'center','caption_speed'=>1.0,'text_effect'=>'none','text_animation'=>'none','display_mode'=>'full','animation_speed'=>'normal','position_x'=>50,'position_y'=>250,'width'=>500,'logo_name'=>'','logo_size_pct'=>15,'logo_pos_h'=>'right','logo_pos_v'=>'top','logo_enabled'=>0,'caption_text'=>'','font_italic'=>0,'font_underline'=>0,'stroke_color'=>'#000000','stroke_width'=>0,'shadow_color'=>'#000000','gradient_color'=>'#ff6600','text_effect_color'=>'#ffffff','text_align_v'=>'bottom'];
                $sm = function(array $d, array $r): array { $res=$d; foreach($r as $k=>$v) if($v!==null) $res[$k]=$v; $res['_anim_style']=(isset($r['text_animation'])&&strlen($r['text_animation'])>0)?$r['text_animation']:($d['text_animation']??'none'); $rs=isset($r['animation_speed'])&&strlen($r['animation_speed'])>0?$r['animation_speed']:($d['animation_speed']??'normal'); $res['_anim_speed']=is_numeric($rs)?(float)$rs:1.0; $res['_text_fx']=(isset($r['text_effect'])&&strlen($r['text_effect'])>0)?$r['text_effect']:($d['text_effect']??'none'); $res['_text_fx_col']=(isset($r['text_effect_color'])&&strlen($r['text_effect_color'])>0)?$r['text_effect_color']:($d['text_effect_color']??'#ffffff'); $res['logo_name']=trim($res['logo_name']??''); return $res; };
                $settings['caption']=$settings['caption']?$sm($def,$settings['caption']):$sm($def,[]);
                $dh=array_merge($def,['text_type'=>'header','is_enabled'=>0,'fontfamily'=>'Helvetica','fontsize'=>16,'fontcolor'=>'#ffffff','fontcolor_bg'=>'#1a1a2e','fontbg_enable'=>1,'caption_style'=>'box','caption_position'=>'top','text_animation'=>'fade_in','position_x'=>0,'position_y'=>16,'width'=>1080,'caption_text'=>'','logo_enabled'=>0]);
                $settings['header']=$settings['header']?$sm($dh,$settings['header']):$sm($dh,[]);
                $df=array_merge($def,['text_type'=>'footer','is_enabled'=>0,'fontfamily'=>'Georgia','fontsize'=>12,'fontcolor'=>'#aaaaaa','fontweight'=>'normal','fontcolor_bg'=>'#000000','fontbg_enable'=>0,'caption_style'=>'none','caption_position'=>'bottom','text_animation'=>'static','animation_speed'=>1.0,'position_x'=>0,'position_y'=>555,'width'=>1080,'caption_text'=>'','logo_enabled'=>0]);
                $settings['footer']=$settings['footer']?$sm($df,$settings['footer']):$sm($df,[]);
                $dl=['text_type'=>'logo','position_x'=>960,'position_y'=>20,'width'=>120,'logo_name'=>'','logo_size_pct'=>15,'logo_pos_h'=>'right','logo_pos_v'=>'top','logo_enabled'=>0];
                $settings['logo']=$settings['logo']?$sm($dl,$settings['logo']):$sm($dl,[]);
                return $settings;
            }
        }

        if (!function_exists('p2_buildCaptionRows')) {
            function p2_buildCaptionRows($conn, $podcast_id, $story_id, $scene_text, $uset) {
                $cap=$uset['caption']; $hdr=$uset['header']; $ftr=$uset['footer']; $rows=0;
                $ins = function($cap_type,$cap_name,$text,array $s,$z,$px=-1,$py=-1,$fs=-1,$pw=-1) use ($conn,$podcast_id,$story_id): bool {
                    $ff=mysqli_real_escape_string($conn,$s['fontfamily']??'Arial');
                    $fs2=$fs>=0?$fs:(int)($s['fontsize']??28);
                    $fc=mysqli_real_escape_string($conn,$s['fontcolor']??'#ffffff');
                    $fw=mysqli_real_escape_string($conn,$s['fontweight']??'normal');
                    $fst=((int)($s['font_italic']??0))?'italic':'normal';
                    $uline=(int)($s['font_underline']??0);
                    $ta=mysqli_real_escape_string($conn,$s['caption_alignment']??$s['text_align']??'center');
                    $tav=mysqli_real_escape_string($conn,$s['text_align_v']??'bottom');
                    $bgc=mysqli_real_escape_string($conn,$s['fontcolor_bg']??'#000000');
                    $bge=(int)($s['fontbg_enable']??0);
                    $sc=mysqli_real_escape_string($conn,$s['stroke_color']??'#000000');
                    $sw=(int)($s['stroke_width']??0);
                    $shc=mysqli_real_escape_string($conn,$s['shadow_color']??'#000000');
                    $gc=mysqli_real_escape_string($conn,$s['gradient_color']??'#ff6600');
                    $as=mysqli_real_escape_string($conn,$s['_anim_style']??'none');
                    $asp=is_numeric($s['_anim_speed']??null)?(float)$s['_anim_speed']:1.0;
                    $tf=mysqli_real_escape_string($conn,$s['_text_fx']??'none');
                    $tc=mysqli_real_escape_string($conn,$s['_text_fx_col']??'#ffffff');
                    $cs=mysqli_real_escape_string($conn,$s['caption_style']??'none');
                    $cp=mysqli_real_escape_string($conn,$s['caption_position']??'bottom');
                    $dm=mysqli_real_escape_string($conn,$s['display_mode']??'full');
                    $px2=$px>=0?$px:(int)($s['position_x']??50);
                    $py2=$py>=0?$py:(int)($s['position_y']??200);
                    $pw2=$pw>=0?$pw:min((int)($s['width']??500),350);
                    $te=mysqli_real_escape_string($conn,$text);
                    $ok=mysqli_query($conn,"INSERT INTO hdb_captions (podcast_id,story_id,caption_type,caption_name,text_content,fontfamily,fontsize,fontcolor,fontweight,fontstyle,underline,text_align,text_align_v,bg_color,bg_enabled,stroke_color,stroke_width,stroke_enabled,shadow_color,gradient_color,text_effects,text_effect_colors,panning_zooming_type,panning_zooming_speed,position_x,position_y,width,rotation,animation_style,animation_speed,caption_style,caption_position,display_mode,text_decoration,is_visible,z_index) VALUES ($podcast_id,$story_id,'$cap_type','$cap_name','$te','$ff',$fs2,'$fc','$fw','$fst',$uline,'$ta','$tav','$bgc',$bge,'$sc',$sw,".($sw>0?1:0).",'$shc','$gc','$tf','$tc',0,0,$px2,$py2,$pw2,0,'$as',$asp,'$cs','$cp','$dm','none',1,$z)");
                    return (bool)$ok;
                };
                if ((int)($cap['is_enabled']??1)) {
                    $cl=trim(preg_replace('/<break[^>]*>/i','',$scene_text));
                    $ct=$cl;
                    if (preg_match('/^(.+?[.!?])\s+\S/u',$cl,$m2)){$f=trim($m2[1]);$w=preg_split('/\s+/',$f,-1,PREG_SPLIT_NO_EMPTY);if(count($w)>12)$f=implode(' ',array_slice($w,0,10)).'…';$ct=$f;}
                    else{$w=preg_split('/\s+/',$cl,-1,PREG_SPLIT_NO_EMPTY);if(count($w)>12)$ct=implode(' ',array_slice($w,0,10)).'…';}
                    if ($ins('caption','main',$ct,$cap,1)) $rows++;
                }
                if ((int)($hdr['is_enabled']??0)) { if ($ins('header','header',trim($hdr['caption_text']??''),$hdr,2,-1,16,-1,min((int)($hdr['width']??1080),350))) $rows++; }
                if ((int)($ftr['is_enabled']??0)) { if ($ins('footer','footer',trim($ftr['caption_text']??''),$ftr,3,-1,555,-1,min((int)($ftr['width']??1080),350))) $rows++; }
                $logo=$uset['logo']??[]; $lf2=trim($logo['logo_name']??$logo['logo_file']??$cap['logo_name']??'');
                if ((int)($logo['logo_enabled']??0)&&$lf2){$lfe=mysqli_real_escape_string($conn,$lf2);$lpx=(int)($logo['position_x']??960);$lpy=(int)($logo['position_y']??20);$lw=(int)($logo['width']??120);if(mysqli_query($conn,"INSERT INTO hdb_captions (podcast_id,story_id,caption_type,caption_name,text_content,fontfamily,fontsize,fontcolor,fontweight,fontstyle,underline,text_align,text_align_v,bg_color,bg_enabled,stroke_color,stroke_width,stroke_enabled,shadow_color,gradient_color,text_effects,text_effect_colors,panning_zooming_type,panning_zooming_speed,position_x,position_y,width,rotation,animation_style,animation_speed,caption_style,caption_position,display_mode,media_type,image_file,is_visible,z_index) VALUES ($podcast_id,$story_id,'image','logo','$lfe','Arial',0,'#ffffff','normal','normal',0,'left','top','#000000',0,'#000000',0,0,'#000000','#ff6600','none','#ffffff',0,0,$lpx,$lpy,$lw,0,'none',1.0,'none','top','full','image','$lfe',1,10)"))$rows++;}
                return $rows;
            }
        }

        $user_settings = p2_getUserSettings($conn, $admin_id, $company_id);
        mysqli_query($conn, "DELETE FROM hdb_captions WHERE podcast_id=$podcast_id");
        $cap_res = mysqli_query($conn, "SELECT id, text_contents FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC");
        $total_caption_rows = 0;
        if ($cap_res) {
            while ($cap_row = mysqli_fetch_assoc($cap_res)) {
                $total_caption_rows += p2_buildCaptionRows($conn, $podcast_id, (int)$cap_row['id'], $cap_row['text_contents'] ?? '', $user_settings);
            }
        }
        promoLog("captions written inline: podcast=$podcast_id rows=$total_caption_rows", 'STAGE2');

        // Update promo_brief status
        mysqli_query($conn, "UPDATE hdb_promo_briefs SET
            podcast_id=$podcast_id, status='storyboard_approved', updated_at=NOW()
            WHERE id=$brief_id");

        // Deduct credits
        $gen_mode_used = trim($_POST['gen_mode'] ?? 'fast');
        $duration_used = max(10, min(60, (int)($brief['duration'] ?? 30)));
        $track_used    = $track;

        // Get balance before deduction
        $bal_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM hdb_users WHERE id=$team_lead_id LIMIT 1"));
        $bal_before = (int)($bal_row['credit_balance'] ?? 0);
        $bal_after  = max(0, $bal_before - $credits_charge);

        // 1. Deduct from user balance
        mysqli_query($conn, "UPDATE hdb_users SET
            credit_balance = GREATEST(0, credit_balance - $credits_charge)
            WHERE id=$team_lead_id");

        // 2. Update company credit_used
        mysqli_query($conn, "UPDATE hdb_companies SET
            credit_used = COALESCE(credit_used, 0) + $credits_charge
            WHERE id=$company_id");

        // 3. Update podcast credit_used
        mysqli_query($conn, "UPDATE hdb_podcasts SET
            credit_used = COALESCE(credit_used, 0) + $credits_charge
            WHERE id=$podcast_id");

        // 4. Insert credit charge history
        $desc_e = mysqli_real_escape_string($conn,
            "Promo video · " . ($track === 'ai' ? 'AI Custom' : 'Stock') .
            " · " . $duration_used . "s · " . ($gen_mode_used === 'fast' ? 'Fast/Fal.ai' : 'Smart Saver/Modal') .
            " · Podcast #$podcast_id"
        );
        $gm_e = mysqli_real_escape_string($conn, $gen_mode_used);
        $tr_e = mysqli_real_escape_string($conn, $track_used);
        mysqli_query($conn, "INSERT INTO hdb_credit_charge_history
            (admin_id, team_lead_id, company_id, podcast_id, brief_id,
             credits_charged, balance_before, balance_after,
             charge_type, video_track, gen_mode, duration_sec, description)
            VALUES
            ($admin_id, $team_lead_id, $company_id, $podcast_id, $brief_id,
             $credits_charge, $bal_before, $bal_after,
             'promo_video', '$tr_e', '$gm_e', $duration_used, '$desc_e')");

        promoLog("save_storyboard OK: brief=$brief_id podcast=$podcast_id scenes=$sc_count credits=$credits_charge", 'STAGE2');
        echo json_encode(['success'=>true,'podcast_id'=>$podcast_id,'scene_count'=>$sc_count]);

    } catch (Throwable $e) {
        promoLog("save_storyboard EXCEPTION: " . $e->getMessage() . " line " . $e->getLine(), 'ERROR');
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Clear session on fresh load ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['company_id'])) {
    unset($_SESSION['promo_brief_id'], $_SESSION['promo_category'], $_SESSION['promo_step'],
          $_SESSION['product_image_path'], $_SESSION['product_image_name'],
          $_SESSION['product_auto_desc'], $_SESSION['promo_model_fp']);
}

// ── Load generation rates for dynamic credit calculation ───
$gen_rates = ['video_fal_rate_per_sec'=>0, 'video_modal_rate_per_sec'=>0,
              'image_fal_rate_per_sec'=>0, 'image_modal_rate_per_sec'=>0];
if (file_exists(__DIR__ . '/videovizard_general_functions.php')) {
    require_once __DIR__ . '/videovizard_general_functions.php';
    $rates_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT video_fal_rate_per_sec, video_modal_rate_per_sec,
                image_fal_rate_per_sec, image_modal_rate_per_sec
         FROM hdb_video_generation_rates LIMIT 1"));
    if ($rates_row) $gen_rates = $rates_row;
}

// ── Pricing for JS ─────────────────────────────────────────
$p_ai    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credits,our_price FROM hdb_ai_pricing WHERE service_key='promo_ai' LIMIT 1"));
$p_stock = mysqli_fetch_assoc(mysqli_query($conn, "SELECT credits,our_price FROM hdb_ai_pricing WHERE service_key='promo_stock' LIMIT 1"));
$credits_ai    = (int)($p_ai['credits']    ?? 120);
$credits_stock = (int)($p_stock['credits'] ?? 20);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Promo Video — <?= $co_name ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
:root{
    --dark-blue:#0f2a44; --mid-blue:#143b63; --accent:#5fd1ff;
    --green:#10b981;     --green-lt:#d1fae5;
    --purple:#8b5cf6;    --purple-lt:#ede9fe;
    --orange:#f59e0b;    --orange-lt:#fef3c7;
    --red:#ef4444;
    --text:#1e293b;      --muted:#64748b;
    --border:#e2e8f0;    --bg:#f8fafc;
    --card:#ffffff;      --shadow:0 4px 12px rgba(0,0,0,0.08);
    --radius:12px;       --radius-sm:8px; --radius-lg:16px;
    --tr:0.15s ease;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);
     min-height:100vh;font-size:15px;line-height:1.6;}

/* ── Header ───────────────────────────────────────────────── */
.hdr{display:flex;align-items:center;gap:16px;padding:0 24px;height:58px;
     background:linear-gradient(90deg,var(--dark-blue),var(--mid-blue));
     box-shadow:0 3px 10px rgba(0,0,0,.15);position:sticky;top:0;z-index:100;}
.hdr-logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:17px;
          color:#fff;text-decoration:none;}
.hdr-logo-icon{font-size:22px;}
.hdr-logo-vizard{color:#5fd1ff;}
.hdr-co{font-size:13px;color:rgba(255,255,255,.65);}
.hdr-right{margin-left:auto;display:flex;align-items:center;gap:12px;}
.credit-badge{background:var(--green-lt);border:1px solid var(--green);color:#065f46;
              padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;}
.co-switch{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:#fff;
           padding:6px 10px;border-radius:var(--radius-sm);font-size:13px;
           cursor:pointer;font-family:inherit;}
.co-switch:focus{outline:none;border-color:#5fd1ff;}
.co-switch option{background:#0f2a44;}

/* ── Layout ───────────────────────────────────────────────── */
.wrap{max-width:680px;margin:0 auto;padding:32px 20px 80px;}

/* ── Progress bar ─────────────────────────────────────────── */
.prog{display:flex;align-items:center;margin-bottom:32px;
      background:var(--card);border:1px solid var(--border);
      border-radius:var(--radius);padding:14px 20px;box-shadow:var(--shadow);}
.prog-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;}
.prog-step:not(:last-child)::after{content:'';position:absolute;top:14px;left:60%;width:80%;
    height:2px;background:var(--border);z-index:0;}
.prog-step.done:not(:last-child)::after{background:var(--green);}
.prog-dot{width:28px;height:28px;border-radius:50%;background:var(--bg);
          border:2px solid var(--border);display:flex;align-items:center;justify-content:center;
          font-size:11px;font-weight:700;color:var(--muted);position:relative;z-index:1;transition:var(--tr);}
.prog-step.active .prog-dot{background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));
    border-color:var(--dark-blue);color:#fff;}
.prog-step.done .prog-dot{background:var(--green);border-color:var(--green);color:#fff;}
.prog-lbl{font-size:10px;color:var(--muted);margin-top:5px;text-align:center;white-space:nowrap;font-weight:600;}
.prog-step.active .prog-lbl{color:var(--dark-blue);}
.prog-step.done .prog-lbl{color:var(--green);}

/* ── Section titles ───────────────────────────────────────── */
.sec-title{font-size:21px;font-weight:700;margin-bottom:5px;color:var(--dark-blue);}
.sec-sub{font-size:14px;color:var(--muted);margin-bottom:22px;}

/* ── Cards ────────────────────────────────────────────────── */
.card{background:var(--card);border:1px solid var(--border);
      border-radius:var(--radius-lg);padding:22px 24px;margin-bottom:14px;box-shadow:var(--shadow);}
.card-title{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;
            letter-spacing:.07em;margin-bottom:14px;display:flex;align-items:center;gap:6px;}
.card-title::before{content:'';width:3px;height:14px;background:var(--green);
                    border-radius:2px;display:inline-block;}

/* ── Company banner ───────────────────────────────────────── */
.co-banner{display:flex;align-items:center;gap:14px;background:var(--green-lt);
           border:1px solid #6ee7b7;border-left:4px solid var(--green);
           border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:20px;}
.co-logo-thumb{width:46px;height:46px;border-radius:9px;object-fit:cover;flex-shrink:0;
               background:white;border:1px solid var(--border);display:flex;
               align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#065f46;overflow:hidden;}
.co-logo-thumb img{width:100%;height:100%;object-fit:cover;border-radius:8px;}
.co-banner-info{flex:1;min-width:0;}
.co-banner-name{font-weight:700;font-size:14px;color:var(--dark-blue);}
.co-banner-sub{font-size:11px;color:var(--muted);margin-top:2px;}
.co-banner-meta{display:flex;gap:14px;flex-wrap:wrap;margin-top:4px;}
.co-banner-meta span{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:3px;}

/* ── Category tiles ───────────────────────────────────────── */
.cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.cat-tile{background:var(--bg);border:2px solid var(--border);border-radius:var(--radius);
          padding:18px 10px;cursor:pointer;transition:var(--tr);
          display:flex;flex-direction:column;align-items:center;gap:8px;
          text-align:center;user-select:none;}
.cat-tile:hover{border-color:var(--green);background:var(--green-lt);transform:translateY(-2px);}
.cat-tile.selected{border-color:var(--dark-blue);background:linear-gradient(135deg,rgba(15,42,68,.06),rgba(20,59,99,.06));
    box-shadow:0 0 0 3px rgba(15,42,68,.08);}
.cat-icon{font-size:28px;line-height:1;}
.cat-name{font-size:11px;font-weight:600;color:var(--muted);line-height:1.3;}
.cat-tile.selected .cat-name{color:var(--dark-blue);}

/* ── Sub-category chips ───────────────────────────────────── */
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.chip{background:var(--bg);border:1.5px solid var(--border);border-radius:20px;
      padding:6px 14px;font-size:12px;color:var(--muted);cursor:pointer;
      transition:var(--tr);user-select:none;font-weight:500;}
.chip:hover{border-color:var(--green);color:#059669;}
.chip.active{background:var(--green-lt);border-color:var(--green);color:#059669;font-weight:600;}

/* ── Field steps ──────────────────────────────────────────── */
.fstep{display:none;animation:fadeUp 0.22s ease;}
.fstep.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
.flabel{font-size:12px;font-weight:700;color:var(--dark-blue);text-transform:uppercase;
        letter-spacing:.05em;margin-bottom:7px;display:flex;align-items:center;gap:6px;}
.flabel .opt{color:var(--muted);font-weight:400;text-transform:none;letter-spacing:0;font-size:11px;}
.fhint{font-size:12px;color:var(--muted);margin-bottom:10px;}

/* ── Inputs ───────────────────────────────────────────────── */
input[type=text],input[type=tel],textarea,select{
    width:100%;background:var(--bg);border:1.5px solid var(--border);
    border-radius:var(--radius-sm);color:var(--text);font-family:'Inter',sans-serif;
    font-size:14px;padding:11px 14px;outline:none;transition:var(--tr);-webkit-appearance:none;}
input:focus,textarea:focus,select:focus{border-color:var(--green);
    box-shadow:0 0 0 3px rgba(16,185,129,.1);}
input::placeholder,textarea::placeholder{color:var(--muted);}
textarea{resize:vertical;min-height:80px;line-height:1.6;}
select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 12px center;padding-right:34px;cursor:pointer;}

/* ── Read-only brand field ────────────────────────────────── */
.field-readonly{background:var(--green-lt);border:1.5px solid #6ee7b7;border-radius:var(--radius-sm);
                padding:11px 14px;color:var(--text);font-size:14px;
                display:flex;align-items:center;gap:10px;}
.field-readonly .ro-icon{font-size:18px;}
.field-readonly .ro-val{font-weight:600;color:var(--dark-blue);}
.field-readonly .ro-sub{font-size:11px;color:var(--muted);}

/* ── Upload zone ──────────────────────────────────────────── */
.upload-zone{border:2px dashed var(--border);border-radius:var(--radius);
             padding:22px;text-align:center;cursor:pointer;transition:var(--tr);
             background:var(--bg);position:relative;margin-top:14px;}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--green);background:var(--green-lt);}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-icon{font-size:26px;margin-bottom:6px;}
.upload-label{font-size:13px;font-weight:600;color:var(--dark-blue);}
.upload-sub{font-size:11px;color:var(--muted);margin-top:3px;}
.product-preview{display:flex;align-items:center;gap:12px;background:var(--green-lt);
                 border:1.5px solid #6ee7b7;border-radius:var(--radius-sm);
                 padding:10px 14px;margin-top:12px;}
.product-preview img{width:60px;height:60px;object-fit:cover;border-radius:8px;
                     border:1px solid var(--border);flex-shrink:0;}
.preview-info{flex:1;min-width:0;}
.preview-name{font-size:13px;font-weight:600;color:#065f46;white-space:nowrap;
              overflow:hidden;text-overflow:ellipsis;}
.preview-desc{font-size:11px;color:var(--muted);margin-top:2px;line-height:1.4;
              display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.vision-badge{display:inline-block;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));
              color:#fff;font-size:9px;font-weight:700;padding:2px 7px;
              border-radius:99px;margin-left:6px;vertical-align:middle;}

/* ── AI scene card — image preview + feedback ─────────────── */
.scene-img-wrap{position:relative;width:100%;padding-top:177.78%;background:#e8edf2;overflow:hidden;border-radius:var(--radius-sm) var(--radius-sm) 0 0;}
.scene-img-wrap img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
.scene-img-loading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:#f1f5f9;}
.scene-img-actions{position:absolute;bottom:0;left:0;right:0;display:flex;gap:0;background:rgba(15,42,68,.75);backdrop-filter:blur(4px);opacity:0;transition:opacity .2s;}
.scene-img-wrap:hover .scene-img-actions{opacity:1;}
.scene-img-btn{flex:1;padding:8px 6px;background:none;border:none;color:#fff;font-size:11px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;gap:4px;transition:background .15s;}
.scene-img-btn:hover{background:rgba(255,255,255,.15);}
.scene-img-btn + .scene-img-btn{border-left:1px solid rgba(255,255,255,.2);}

/* ── Feedback panel ───────────────────────────────────────── */
.scene-feedback-panel{display:none;padding:10px 12px;background:var(--purple-lt);border-top:1px solid #c4b5fd;}
.scene-feedback-panel.open{display:block;}
.scene-feedback-panel textarea{width:100%;background:#fff;border:1.5px solid #c4b5fd;border-radius:6px;
    padding:8px 10px;font-size:12px;font-family:'Inter',sans-serif;color:var(--text);
    outline:none;resize:none;min-height:54px;line-height:1.5;}
.scene-feedback-panel textarea:focus{border-color:var(--purple);}
.scene-feedback-actions{display:flex;gap:8px;margin-top:8px;}
.btn-regen{padding:7px 14px;background:linear-gradient(135deg,var(--purple),#7c3aed);color:#fff;
    border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;
    font-family:'Inter',sans-serif;display:flex;align-items:center;gap:5px;transition:all .15s;}
.btn-regen:hover{box-shadow:0 3px 10px rgba(139,92,246,.35);}
.btn-regen:disabled{opacity:.5;cursor:not-allowed;}
.btn-cancel-fb{padding:7px 12px;background:none;border:1.5px solid #c4b5fd;color:var(--purple);
    border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}


.ai-generating{display:none;background:var(--purple-lt);border:1px solid var(--purple);
               border-radius:var(--radius-sm);padding:12px 16px;margin-top:12px;
               font-size:13px;color:var(--purple);align-items:center;gap:10px;}
.ai-generating.visible{display:flex;}
.ai-result{display:none;margin-top:12px;}
.ai-result.visible{display:block;}
.ai-result-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
                 color:var(--muted);margin-bottom:6px;}
.ai-result textarea{font-size:13px;min-height:60px;border-color:#c4b5fd;background:var(--purple-lt);}
.ai-result textarea:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(139,92,246,.1);}

/* ── Buttons ──────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
     padding:11px 22px;border-radius:var(--radius-sm);font-size:14px;font-weight:700;
     cursor:pointer;border:none;font-family:'Inter',sans-serif;transition:var(--tr);white-space:nowrap;}
.btn-primary{background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;}
.btn-primary:hover:not(:disabled){box-shadow:0 4px 14px rgba(15,42,68,.3);}
.btn-primary:disabled{opacity:.45;cursor:not-allowed;}
.btn-ghost{background:var(--bg);color:var(--muted);border:1.5px solid var(--border);}
.btn-ghost:hover{border-color:var(--purple);color:var(--purple);}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-row{display:flex;align-items:center;justify-content:flex-end;margin-top:18px;gap:10px;}
.btn-row .btn-ghost:first-child{margin-right:auto;}

/* ── Field dots ───────────────────────────────────────────── */
.fdots{display:flex;gap:6px;justify-content:center;margin:14px 0 6px;}
.fdot{width:6px;height:6px;border-radius:50%;background:var(--border);transition:var(--tr);}
.fdot.active{background:var(--dark-blue);transform:scale(1.4);}
.fdot.done{background:var(--green);}

/* ── Pricing cards ────────────────────────────────────────── */
.pricing-track{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;}
.pricing-card{background:var(--bg);border:2px solid var(--border);border-radius:var(--radius);
              padding:20px;cursor:pointer;transition:var(--tr);position:relative;}
.pricing-card:hover{border-color:var(--green);}
.pricing-card.selected{border-color:var(--dark-blue);background:rgba(15,42,68,.04);
    box-shadow:0 0 0 3px rgba(15,42,68,.08);}
.pricing-card.recommended::after{content:'RECOMMENDED';position:absolute;top:-1px;right:12px;
    background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;font-size:9px;
    font-weight:700;padding:3px 8px;border-radius:0 0 6px 6px;letter-spacing:.5px;}
.pricing-icon{font-size:24px;margin-bottom:8px;}
.pricing-name{font-size:15px;font-weight:700;color:var(--dark-blue);margin-bottom:4px;}
.pricing-desc{font-size:12px;color:var(--muted);margin-bottom:12px;line-height:1.5;}
.pricing-credits{font-size:22px;font-weight:700;color:var(--dark-blue);}
.pricing-credits span{font-size:13px;font-weight:400;color:var(--muted);}
.pricing-time{font-size:12px;color:var(--muted);margin-top:4px;}
.pricing-status{font-size:12px;margin-top:8px;font-weight:600;}
.pricing-status.ok{color:var(--green);}
.pricing-status.low{color:var(--orange);}
.pricing-status.no{color:var(--red);}

/* ── Confirm rows ─────────────────────────────────────────── */
.confirm-row{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);}
.confirm-label{flex:0 0 130px;font-size:11px;font-weight:700;color:var(--muted);
               text-transform:uppercase;letter-spacing:.05em;padding-top:2px;}
.confirm-val{flex:1;font-size:14px;color:var(--text);}

/* ── Spinner ──────────────────────────────────────────────── */
.spin{width:15px;height:15px;border:2px solid transparent;border-top-color:currentColor;
      border-radius:50%;animation:spin .7s linear infinite;display:inline-block;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg);}}

/* ── Toast ────────────────────────────────────────────────── */
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;
            display:flex;flex-direction:column;gap:8px;pointer-events:none;}
.toast{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-sm);
       padding:12px 18px;font-size:13px;color:var(--text);box-shadow:var(--shadow);
       animation:fadeUp .3s ease;pointer-events:auto;max-width:320px;}
.toast.success{border-color:var(--green);color:#065f46;}
.toast.error{border-color:var(--red);color:var(--red);}

/* ── Debug panel ──────────────────────────────────────────── */
.dbg{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);
     padding:20px;margin-top:40px;}
.dbg h4{color:var(--dark-blue);font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;}
.dbg pre{color:var(--text);white-space:pre-wrap;word-break:break-all;
         max-height:400px;overflow-y:auto;font-family:'Courier New',monospace;font-size:12px;}

/* ── Stage 2 — Build Video Modal ──────────────────────────── */
.s2-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1100;align-items:flex-start;justify-content:center;padding:70px 16px 20px;overflow-y:auto;}
.s2-overlay.open{display:flex;}
.s2-panel{background:#fff;border-radius:16px;width:100%;max-width:540px;display:flex;flex-direction:column;overflow:hidden;margin:0 0 20px;box-shadow:0 12px 40px rgba(0,0,0,0.25);position:relative;}
.s2-header{padding:14px 20px 12px;background:linear-gradient(90deg,var(--dark-blue),var(--mid-blue));border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.s2-header h2{font-size:17px;font-weight:700;color:#fff;margin:0;}
.s2-close{background:none;border:none;font-size:22px;color:rgba(255,255,255,.8);cursor:pointer;}
.s2-body{padding:16px 20px;display:flex;flex-direction:column;gap:0;}
.s2-processing-overlay{display:none;flex-direction:row;align-items:center;gap:12px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));border-radius:8px;padding:10px 14px;margin-bottom:8px;flex-shrink:0;}
.s2-processing-overlay.active{display:flex;}
.s2-spinner{width:24px;height:24px;border:3px solid rgba(255,255,255,0.2);border-top-color:#5fd1ff;border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0;}
.s2-processing-msg{color:#fff;font-size:13px;font-weight:600;}
.s2-processing-step{color:rgba(255,255,255,0.6);font-size:11px;margin-top:2px;}
.s2-steps{display:flex;flex-direction:column;gap:4px;margin:0 0 8px;flex-shrink:0;}
.s2-step{display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);min-height:34px;overflow:hidden;}
.s2-step.active{border-color:var(--purple);background:var(--purple-lt);}
.s2-step.done{border-color:var(--mid-blue);background:#e8f0fe;}
.s2-step.error{border-color:#fca5a5;background:#fef2f2;}
.s2-step-icon{font-size:15px;flex-shrink:0;}
.s2-step-title{font-size:12px;font-weight:700;color:var(--dark-blue);white-space:nowrap;flex-shrink:0;}
.s2-step-sub{font-size:11px;color:var(--muted);margin-left:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.s2-log{background:#0f2a44;border-radius:8px;padding:10px 12px;max-height:160px;overflow-y:auto;font-family:monospace;font-size:10px;line-height:1.5;margin-top:6px;}
.s2-log-line{margin:0;}
.s2-log-line.info{color:#7dd3fc;}
.s2-log-line.success{color:#5fd1ff;}
.s2-log-line.warning{color:#fde68a;}
.s2-log-line.error{color:#fca5a5;}
#s2SceneGrid{border-top:1px solid #e5e7eb;background:#f8f9fa;padding:12px 20px;}
#s2SceneBoxes{display:flex;flex-wrap:wrap;gap:8px;padding:4px 0;}
.s2-select{width:100%;padding:9px 12px;font-size:13px;border:1.5px solid var(--border);border-radius:8px;background:#fff;color:var(--text);outline:none;transition:border-color .15s;font-family:'Inter',sans-serif;}
.s2-select:focus{border-color:var(--purple);}
.s2-role-card{border:1.5px solid var(--border);border-radius:12px;padding:14px 14px 10px;margin-bottom:14px;background:#f5f3ff;border-color:#c4b5fd;}
.s2-role-card-title{font-size:13px;font-weight:700;color:var(--dark-blue);margin-bottom:10px;}
.s2-role-subsection{margin-bottom:10px;}
.s2-sublabel{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;}
.s2-gender-tabs{display:flex;gap:6px;}
.s2-gtab{flex:1;padding:5px 8px;border:1.5px solid var(--border);border-radius:7px;background:#fff;font-size:12px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .15s;font-family:'Inter',sans-serif;}
.s2-gtab:hover{border-color:var(--purple);color:var(--purple);}
.s2-gtab.active{background:var(--purple);border-color:var(--purple);color:#fff;}
.s2-sample-btn{width:100%;padding:7px 12px;background:#fff;border:1.5px solid var(--border);border-radius:8px;font-size:12px;font-weight:600;color:var(--muted);cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;font-family:'Inter',sans-serif;}
.s2-sample-btn:hover{border-color:var(--purple);color:var(--purple);background:var(--purple-lt);}
.s2-start-btn{width:100%;padding:13px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;margin-top:4px;font-family:'Inter',sans-serif;}
.s2-start-btn:hover{box-shadow:0 4px 12px rgba(15,42,68,.3);}

/* ── Model picker grid ────────────────────────────────────── */
.model-card{position:relative;border:2px solid var(--border);border-radius:var(--radius);
            overflow:hidden;cursor:pointer;transition:var(--tr);background:var(--bg);text-align:center;}
.model-card:hover{border-color:var(--green);transform:translateY(-2px);}
.model-card.selected{border-color:var(--dark-blue);box-shadow:0 0 0 3px rgba(15,42,68,.12);}
.model-card img{width:100%;aspect-ratio:1/1;object-fit:cover;object-position:center 18%;display:block;transform:scale(2);transform-origin:50% 18%;}
.model-card-label{font-size:10px;color:var(--muted);padding:5px 6px;font-weight:600;
                  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.model-check{display:none;position:absolute;top:6px;right:6px;
    background:var(--dark-blue);color:#fff;border-radius:50%;
    width:20px;height:20px;font-size:11px;align-items:center;justify-content:center;font-weight:700;}
.model-card.selected .model-check{display:flex;}

.scene-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:16px;}
.scene-card{background:var(--card);border:1.5px solid var(--border);border-radius:var(--radius);
            box-shadow:var(--shadow);overflow:hidden;transition:var(--tr);}
.scene-card:hover{border-color:var(--green);}
.scene-card-head{background:linear-gradient(90deg,var(--dark-blue),var(--mid-blue));
                 padding:8px 14px;display:flex;align-items:center;justify-content:space-between;}
.scene-num{font-size:11px;font-weight:700;color:rgba(255,255,255,.85);text-transform:uppercase;letter-spacing:.06em;}
.scene-type-badge{font-size:9px;font-weight:700;padding:2px 8px;border-radius:99px;text-transform:uppercase;letter-spacing:.05em;}
.badge-hook      {background:#fef3c7;color:#92400e;}
.badge-problem   {background:#fee2e2;color:#991b1b;}
.badge-product   {background:#dbeafe;color:#1e40af;}
.badge-feature   {background:#d1fae5;color:#065f46;}
.badge-lifestyle {background:#ede9fe;color:#5b21b6;}
.badge-social    {background:#fef3c7;color:#92400e;}
.badge-cta       {background:#d1fae5;color:#065f46;}
.scene-card-body{padding:12px 14px;}
.scene-stock-query{font-size:10px;color:var(--muted);margin-bottom:8px;
                   display:flex;align-items:center;gap:4px;}
.scene-stock-query span{background:var(--bg);border:1px solid var(--border);
                        border-radius:4px;padding:1px 6px;font-family:'Courier New',monospace;}
.scene-caption-wrap{position:relative;}
.scene-caption{width:100%;background:var(--bg);border:1.5px solid var(--border);
               border-radius:var(--radius-sm);padding:9px 11px;font-size:13px;
               font-family:'Inter',sans-serif;color:var(--text);outline:none;
               resize:none;min-height:64px;line-height:1.5;transition:var(--tr);}
.scene-caption:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(16,185,129,.1);}
.scene-dur{font-size:10px;font-weight:700;color:var(--muted);margin-top:6px;
           display:flex;align-items:center;gap:4px;}

/* ── Stage 2 action bar ───────────────────────────────────── */
#dur-chips .chip.active{background:#0f2a44!important;color:#fff!important;border-color:#0f2a44!important;box-shadow:0 0 0 2px #0f2a44!important;}
#lang-chips .chip.active,#lang-chips-more .chip.active{background:#0f2a44!important;color:#fff!important;border-color:#0f2a44!important;}
.dur-pill.active{background:var(--dark-blue);color:#fff;border-color:var(--dark-blue);}
.dur-pill:hover{border-color:var(--dark-blue);}
.gen-mode-card.selected{border-color:var(--dark-blue)!important;background:#f0f4ff;}font-size:11px;font-weight:700;background:var(--border);color:var(--muted);transition:.3s;}
.s2-step-pill.active{background:var(--dark-blue);color:#fff;}
.s2-step-pill.done{background:#16a34a;color:#fff;}
.s2-action-bar{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:12px 16px;display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:12px;flex-wrap:wrap;}
               padding:14px 18px;display:flex;align-items:center;justify-content:space-between;
               gap:12px;box-shadow:var(--shadow);flex-wrap:wrap;margin-top:4px;}
</style>
</head>
<body>

<!-- Header -->
<header class="hdr">
    <a class="hdr-logo" href="dashboard.php">
        <span class="hdr-logo-icon">🎬</span>
        <span>Video<span class="hdr-logo-vizard">Vizard</span></span>
    </a>
    <a href="vizard_scriptgen.php" style="margin-left:auto;display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);text-decoration:none;font-size:13px;padding:6px 12px;border:1px solid rgba(255,255,255,.2);border-radius:8px;transition:.2s;" onmouseover="this.style.background='rgba(255,255,255,.1)'" onmouseout="this.style.background='none'">
        ← Back
    </a>
</header>

<div class="wrap">

    <!-- Hero banner -->
    <div style="background:var(--dark-blue);border-radius:var(--radius);padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px;">
        <span style="font-size:28px;">📦</span>
        <div>
            <div style="font-size:16px;font-weight:700;color:#fff;">Product Promotion Video</div>
            <div style="font-size:12px;color:rgba(255,255,255,.65);margin-top:3px;">Upload your product → AI builds a cinematic script → generate scene images</div>
        </div>
    </div>

    <!-- Progress -->
    <div class="prog">
        <?php $steps = ['Your offer','Product photo','Review plan','Storyboard','Video']; ?>
        <?php foreach ($steps as $i => $lbl): ?>
        <div class="prog-step <?= $i===0?'active':'' ?>" id="prog-<?= $i+1 ?>">
            <div class="prog-dot"><?= $i+1 ?></div>
            <div class="prog-lbl"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ══ STEP: CATEGORY ══════════════════════════════════ -->
    <div id="step-category">
        <div class="sec-title">What are you promoting?</div>
        <div class="sec-sub">Choose a category to get started</div>

        <!-- Company banner -->
        <div class="co-banner">
            <?php if ($co_logo && file_exists($co_logo)): ?>
            <div class="co-logo-thumb"><img src="<?= $co_logo ?>" alt=""></div>
            <?php else: ?>
            <div class="co-logo-thumb"><?= mb_strtoupper(mb_substr(strip_tags($co_name),0,1)) ?: '🏢' ?></div>
            <?php endif; ?>
            <div class="co-banner-info">
                <div class="co-banner-name"><?= $co_brand ?: $co_name ?></div>
                <div class="co-banner-sub">Your video will be created for this brand</div>
                <div class="co-banner-meta">
                    <?php if ($co_phone): ?><span>📞 <?= $co_phone ?></span><?php endif; ?>
                    <?php if ($co_web):   ?><span>🌐 <?= $co_web ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="cat-grid" id="cat-grid">
                <div style="grid-column:1/-1;text-align:center;color:var(--text-3);padding:24px;">
                    <span class="spin"></span> Loading...
                </div>
            </div>
        </div>
    </div>

    <!-- ══ STEP: OFFER ═════════════════════════════════════ -->
    <div id="step-offer" style="display:none">

        <!-- Company banner — always visible at top of offer step -->
        <div class="co-banner" style="margin-bottom:16px;">
            <?php if ($co_logo && file_exists($co_logo)): ?>
            <div class="co-logo-thumb"><img src="<?= $co_logo ?>" alt=""></div>
            <?php else: ?>
            <div class="co-logo-thumb"><?= mb_strtoupper(mb_substr(strip_tags($co_name),0,1)) ?: '🏢' ?></div>
            <?php endif; ?>
            <div class="co-banner-info">
                <div class="co-banner-name"><?= $co_brand ?: $co_name ?></div>
                <div class="co-banner-sub">Brand / Company — auto-filled from your account</div>
                <?php if ($co_phone || $co_web): ?>
                <div class="co-banner-meta">
                    <?php if ($co_phone): ?><span>📞 <?= $co_phone ?></span><?php endif; ?>
                    <?php if ($co_web):   ?><span>🌐 <?= $co_web ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sec-title" id="offer-title">Tell us about your offer</div>
        <div class="sec-sub" id="offer-sub">Answer a few quick questions</div>

        <!-- Sub-category chips -->
        <div class="card" id="sub-card" style="display:none">
            <div class="flabel">What type is this?</div>
            <div class="chips" id="sub-chips"></div>
        </div>

        <!-- Fields card -->
        <div class="card" id="fields-card">

            <!-- F0: Product Name -->
            <div class="fstep active" id="f-product-name">
                <div class="flabel" id="pn-label">Product Name <span style="color:var(--red);margin-left:4px;">*</span></div>
                <div class="fhint" id="pn-hint">Enter the name of your product or service</div>
                <input type="text" id="input-product-name" placeholder="e.g. iPhone 16 Pro Max 256GB Space Black"
                    autocomplete="off">

                <!-- AI generating indicator -->
                <div class="ai-generating" id="ai-generating">
                    <span class="spin"></span>
                    <span>Generating key features and target audience...</span>
                </div>

                <!-- AI results -->
                <div class="ai-result" id="ai-result-features">
                    <div class="ai-result-label">✨ Key Features / Selling Points</div>
                    <textarea id="input-key-features" rows="2"
                        placeholder="AI will generate these from your product name"></textarea>
                </div>

                <div class="ai-result" id="ai-result-audience">
                    <div class="ai-result-label">✨ Target Audience</div>
                    <textarea id="input-target-audience" rows="2"
                        placeholder="AI will generate this from your product name"></textarea>
                </div>
            </div>

            <!-- F1: What are you promoting -->
            <div class="fstep" id="f-offer">
                <div class="flabel">Describe your offer <span style="color:var(--red);margin-left:4px;">*</span></div>
                <div class="fhint" id="offer-hint">Tell us more — price, inclusions, special details</div>
                <textarea id="input-offer" rows="3"
                    placeholder="e.g. Limited Eid collection, hand-stitched, available in 3 colours"></textarea>
            </div>

            <!-- F2: Highlight -->
            <div class="fstep" id="f-highlight">
                <div class="flabel">What should we highlight? <span class="opt">— optional</span></div>
                <div class="fhint">The single most important selling point</div>
                <input type="text" id="input-highlight"
                    placeholder="e.g. Hand-stitched zardozi · 2-hour delivery · Limited to 50 pieces">
            </div>

            <!-- F3: Price -->
            <div class="fstep" id="f-price">
                <div class="flabel">Price / offer <span class="opt">— optional</span></div>
                <input type="text" id="input-price"
                    placeholder="e.g. Starting Rs 25,000 · Buy 2 get 10% off · Rs 1,800 for 2 persons">
            </div>

            <!-- F4: Urgency -->
            <div class="fstep" id="f-urgency">
                <div class="flabel">Urgency or limited availability <span class="opt">— optional</span></div>
                <div class="fhint">Creates action — leave blank if not applicable</div>
                <input type="text" id="input-urgency"
                    placeholder="e.g. Only 3 left · Eid special ends Friday · 10 seats remaining">
            </div>

            <!-- F4b: Special Instructions (AI track only) -->
            <div class="fstep" id="f-special-instructions" style="display:none;">
                <div class="flabel">🎨 Special Instructions <span class="opt">— optional, AI track only</span></div>
                <div class="fhint">Tell AI exactly how you want the video to look or feel</div>
                <textarea id="input-special-instructions" rows="3"
                    placeholder="e.g. Use Pakistani bridal culture, Mughal courtyard background, warm golden tones, show bride with family, traditional mehndi ceremony setting…"
                    style="width:100%;resize:vertical;"></textarea>
            </div>

            <!-- F5: CTA -->
            <div class="fstep" id="f-cta">
                <div class="flabel">What should people do? <span style="color:var(--red);margin-left:4px;">*</span></div>
                <select id="input-cta">
                    <option value="whatsapp">WhatsApp us</option>
                    <option value="call">Call us</option>
                    <option value="dm">DM on Instagram</option>
                    <option value="visit">Visit our store</option>
                    <option value="book">Book online</option>
                    <option value="order">Order now</option>
                    <option value="website">Visit our website</option>
                </select>
            </div>

            <!-- F6: Contact -->
            <div class="fstep" id="f-contact">
                <div class="flabel">Phone number or link <span style="color:var(--red);margin-left:4px;">*</span></div>
                <input type="text" id="input-contact"
                    value="<?= $co_phone ?>"
                    placeholder="e.g. 0300-1234567 or instagram.com/yourbrand">
            </div>

            <!-- Extra category fields injected here -->
            <div id="extra-fields"></div>

            <!-- Dots -->
            <div class="fdots" id="fdots"></div>

            <!-- Navigation -->
            <div class="btn-row">
                <button class="btn btn-ghost btn-sm" id="btn-back" onclick="fieldBack()" style="display:none">← Back</button>
                <button class="btn btn-primary" id="btn-next" onclick="fieldNext()">Continue →</button>
            </div>
        </div>

        <button class="btn btn-ghost btn-sm" onclick="showStep('category')" style="margin-top:4px">
            ← Change category
        </button>
    </div>

    <!-- ══ STEP: DURATION + LANGUAGE ════════════════════════ -->
    <div id="step-duration" style="display:none">
        <div class="sec-title">⏱ Video Settings</div>
        <div class="sec-sub">Choose duration and language — this affects the video price</div>

        <!-- Duration -->
        <div class="card">
            <div class="flabel">Video Duration <span style="font-size:11px;color:var(--muted);">(default: 30 sec)</span></div>
            <?php if ($is_free_plan): ?>
            <div class="fhint" style="color:#f59e0b;">⚠ Free plan: max 30 seconds. <a href="upgrade.php" style="color:#f59e0b;font-weight:700;">Upgrade to Pro →</a></div>
            <?php else: ?>
            <div class="fhint">Longer videos cost more credits</div>
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;" id="dur-chips">
                <?php foreach ([10,20,30,40,50,60] as $d):
                    $locked = $is_free_plan && $d > 30;
                ?>
                <button class="chip"
                    <?= $locked ? 'disabled title="Upgrade to Pro to unlock"' : '' ?>
                    onclick="<?= $locked ? '' : "selectDurationStep($d,this)" ?>"
                    style="<?= $locked ? 'opacity:.4;cursor:not-allowed;' : ($d===30 ? 'background:#10b981;color:#fff;border-color:#10b981;' : '') ?>"
                ><?= $d ?> sec<?= $locked ? ' 🔒' : '' ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Language -->
        <div class="card" style="margin-top:12px;">
            <div class="flabel">Language <span style="font-size:11px;color:var(--muted);">(default: English)</span></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;" id="lang-chips">
                <?php
                $priority = ['en','ur','ar','hi','fr','es','it','fa'];
                $shown    = [];
                foreach ($priority as $lc) {
                    $name = $_languages_all[$lc] ?? null;
                    if (!$name && $lc === 'en') $name = 'English';
                    if (!$name) continue;
                    $isActive = $lc === 'en';
                    $style = $isActive ? 'style="background:#10b981;color:#fff;border-color:#10b981;"' : '';
                    echo '<button class="chip" ' . $style . ' onclick="selectLangStep(\'' . $lc . '\',this)" data-lang="' . $lc . '">' . htmlspecialchars($name) . '</button>';
                    $shown[] = $lc;
                }
                ?>
                <button class="chip" style="border-style:dashed;" onclick="showMoreLanguages(this)">+ More</button>
            </div>
            <div id="lang-chips-more" style="display:none;flex-wrap:wrap;gap:8px;margin-top:8px;">
                <?php foreach ($_languages_all as $lc => $name):
                    if (in_array($lc, $shown)) continue; ?>
                <button class="chip" onclick="selectLangStep('<?= htmlspecialchars($lc) ?>',this)" data-lang="<?= htmlspecialchars($lc) ?>"><?= htmlspecialchars($name) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Target Location — from hdb_companies.target_location -->
        <div class="card" style="margin-top:12px;">
            <div class="flabel">🌍 Target Location <span class="opt">— optional, improves hashtags</span></div>
            <div class="fhint">City, country or region where your customers are</div>
            <input type="text" id="input-target-location"
                value="<?= htmlspecialchars($co_target_location) ?>"
                placeholder="e.g. Toronto Canada · Mississauga · GTA"
                style="margin-top:8px;">
        </div>

        <!-- Target Audience — from hdb_companies.target_audience -->
        <div class="card" style="margin-top:12px;">
            <div class="flabel">👥 Target Audience <span class="opt">— optional</span></div>
            <div class="fhint">Who exactly is this video for? Improves hashtags and script</div>
            <input type="text" id="input-audience-detail"
                value="<?= htmlspecialchars($co_target_audience) ?>"
                placeholder="e.g. Pakistani brides in Canada aged 20-35"
                style="margin-top:8px;">
        </div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('offer')">← Back</button>
            <button class="btn btn-primary" onclick="showPricing()">Choose Video Option →</button>
        </div>
    </div>
    <div id="step-pricing" style="display:none">
        <div class="sec-title">Choose how to generate your video</div>
        <div class="sec-sub">Both options produce a 30-second promotional reel</div>

        <div class="pricing-track">

            <!-- Stock -->
            <div class="pricing-card recommended" id="pc-stock" onclick="selectTrack('stock')">
                <div class="pricing-icon">📚</div>
                <div class="pricing-name">Stock Media Video</div>
                <div class="pricing-desc">Professional stock footage matched to your offer. Fast and cost-effective.</div>
                <div class="pricing-credits"><?= $credits_stock ?> <span>credits for 30 sec</span></div>
                <div class="pricing-time">⏱ Ready in ~1 minute</div>
                <div class="pricing-status" id="ps-stock-status"></div>
            </div>

            <!-- AI -->
            <div class="pricing-card" id="pc-ai" onclick="selectTrack('ai')">
                <div class="pricing-icon">✨</div>
                <div class="pricing-name">AI Custom Video</div>
                <div class="pricing-desc">Your actual product in every scene. AI-generated images animated to video.</div>
                <div class="pricing-credits"><?= $credits_ai ?> <span>credits for 30 sec</span></div>
                <div class="pricing-time">⏱ Ready in ~15 minutes</div>
                <div class="pricing-status" id="ps-ai-status"></div>
            </div>

        </div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('offer')">← Edit details</button>
            <button class="btn btn-primary" id="btn-pricing-next"
                onclick="saveBriefAndContinue()" disabled>
                Continue →
            </button>
        </div>
    </div>

    <!-- ══ STEP: STOCK MEDIA UPLOAD ══════════════════════════ -->
    <div id="step-media-upload" style="display:none">
        <div class="sec-title">📁 Add Your Media <span style="font-size:13px;font-weight:400;color:var(--muted);">— optional</span></div>
        <div class="sec-sub">Upload your own photos or videos — they'll be used in your video alongside stock clips</div>

        <div class="card" id="media-upload-card">
            <!-- Drop zone -->
            <div class="upload-zone" id="stockMediaZone" style="min-height:140px;">
                <input type="file" id="stockMediaInput" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" multiple>
                <div class="upload-icon">🎬</div>
                <div class="upload-label">Click or drag & drop photos or videos</div>
                <div class="upload-sub">JPG, PNG, WEBP, MP4, MOV — max 50MB each</div>
            </div>

            <!-- Uploaded files list -->
            <div id="stock-media-list" style="margin-top:12px;display:none;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Uploaded Files</div>
                <div id="stock-media-items"></div>
            </div>

            <!-- Upload progress -->
            <div id="stock-media-uploading" style="display:none;padding:10px 0;">
                <span class="spin" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px;"></span>
                <span id="stock-media-uploading-msg" style="font-size:13px;color:var(--muted);">Uploading…</span>
            </div>

            <!-- Post-upload actions -->
            <div id="stock-media-actions" style="display:none;margin-top:14px;display:none;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-ghost" onclick="showMoreUpload()">+ Upload More</button>
                <button class="btn btn-primary" onclick="showStep('confirm')">Continue to Review →</button>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('pricing')">← Back</button>
            <button class="btn btn-primary" onclick="showStep('confirm')">
                Continue →
            </button>
        </div>
    </div>

    <!-- ══ STEP: GENERATION MODE (AI track only) ══════════ -->
    <div id="step-gen-mode" style="display:none">
        <div class="sec-title">🎬 Choose Generation Mode</div>
        <div class="sec-sub">How do you want your AI video generated?</div>

        <?php
        // Load queue times using the general functions
        $que_fal = 0; $que_modal = 0;
        if (file_exists(__DIR__ . '/videovizard_general_functions.php')) {
            require_once __DIR__ . '/videovizard_general_functions.php';
            try { $que_fal   = get_generation_que_time('fal_ai'); } catch(Exception $e) { $que_fal = 0; }
            try { $que_modal = get_generation_que_time('modal');  } catch(Exception $e) { $que_modal = 3; }
        }
        // Add this job's scenes (7) to the queue estimate
        $fal_this_job   = 7 * 2;  // 7 scenes × 2 min each (parallel = just 2 min extra)
        $modal_this_job = 7 * 5;  // 7 scenes × 5 min each (sequential)
        $fal_total_eta   = $que_fal + $fal_this_job;
        $modal_total_eta = $que_modal + $modal_this_job;
        $modal_credits   = round($credits_ai * 2/3);
        $modal_saving    = $credits_ai - $modal_credits;
        ?>

        <div style="background:#fff9e6;border:1px solid #f59e0b;border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-size:13px;">
            💳 <strong>You have <?= $user_credit_balance ?> credits.</strong>
            Video generation runs in the background — you can close this page and check back later.
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;" id="gen-mode-grid">

            <!-- Fast Mode -->
            <div class="gen-mode-card" id="gm-fast" onclick="selectGenMode('fast')" style="border:2px solid var(--border);border-radius:var(--radius);padding:16px;cursor:pointer;transition:.2s;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:22px;">⚡</span>
                        <div>
                            <div style="font-weight:700;font-size:14px;">Fast Mode</div>
                            <div style="font-size:11px;color:var(--muted);">via Fal.ai · All scenes parallel</div>
                        </div>
                    </div>
                    <div style="background:#f59e0b;color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:12px;"><?= $credits_ai ?> credits</div>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">All 7 scenes fire at once — fastest possible delivery.</p>
                <div style="background:#fff9e6;border-radius:6px;padding:8px 10px;font-size:11px;color:#92400e;">
                    ✨ ETA: 🖼 ~1 min images · 🎬 ~<?= $fal_total_eta ?> min videos
                    <?php if ($que_fal > 0): ?>
                    <div style="margin-top:4px;color:#b45309;">(<?= $que_fal ?> min queue + your job)</div>
                    <?php else: ?>
                    <div style="margin-top:4px;color:#b45309;">(no queue — starts immediately)</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Smart Saver Mode -->
            <div class="gen-mode-card" id="gm-standard" onclick="selectGenMode('standard')" style="border:2px solid var(--border);border-radius:var(--radius);padding:16px;cursor:pointer;transition:.2s;position:relative;">
                <div style="position:absolute;top:-1px;right:12px;background:#10b981;color:#fff;font-size:9px;font-weight:700;padding:3px 8px;border-radius:0 0 6px 6px;letter-spacing:.5px;">SAVE <?= $modal_saving ?> CREDITS</div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:22px;">💰</span>
                        <div>
                            <div style="font-weight:700;font-size:14px;">Smart Saver</div>
                            <div style="font-size:11px;color:var(--muted);">via Modal.com · Lower cost</div>
                        </div>
                    </div>
                    <div style="background:var(--dark-blue);color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:12px;"><?= $modal_credits ?> credits</div>
                </div>
                <p style="font-size:12px;color:var(--muted);margin-bottom:10px;">Same quality, lower cost — scenes process one at a time.</p>
                <div style="background:#eff6ff;border-radius:6px;padding:8px 10px;font-size:11px;color:#1e40af;">
                    ⏱ ETA: 🖼 ~6 min images · 🎬 ~<?= $modal_total_eta ?> min videos
                    <?php if ($que_modal > 0): ?>
                    <div style="margin-top:4px;color:#1e40af;">(<?= $que_modal ?> min in queue + your <?= $modal_this_job ?> min)</div>
                    <?php else: ?>
                    <div style="margin-top:4px;color:#1e40af;">(<?= $modal_this_job ?> min + 3 min cold start)</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('pricing')">← Back</button>
            <button class="btn btn-primary" id="btn-gen-mode-continue" onclick="continueFromGenMode()" disabled>
                Continue →
            </button>
        </div>
    </div>

    <!-- ══ STEP: PRODUCT IMAGE UPLOAD (AI track only) ════════ -->
    <div id="step-product-upload" style="display:none">
        <div class="sec-title">📷 Product Image</div>
        <div class="sec-sub">Upload a clear photo of your product. AI will use it to generate all 7 video scenes.</div>

        <div class="card">
            <div class="upload-zone" id="confirmUploadZone">
                <input type="file" id="confirmProductImageInput" accept="image/jpeg,image/png,image/webp">
                <div class="upload-icon">🖼️</div>
                <div class="upload-label">Click or drag & drop your product image</div>
                <div class="upload-sub">JPG, PNG, WEBP — max 10 MB</div>
            </div>

            <div class="product-preview" id="confirmProductPreview" style="display:none;">
                <img id="confirmPreviewImg" src="" alt="Product">
                <div class="preview-info">
                    <div class="preview-name" id="confirmPreviewName">—</div>
                    <div class="preview-desc" id="confirmPreviewDesc">Analysing with AI vision…</div>
                </div>
                <button type="button" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--muted);flex-shrink:0;" onclick="clearProductImage()" title="Remove">✕</button>
            </div>

            <!-- Upload progress indicator -->
            <div id="product-upload-progress" style="display:none;padding:10px 0;text-align:center;">
                <span class="spin" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px;"></span>
                <span style="font-size:13px;color:var(--muted);">Uploading and analysing…</span>
            </div>
        </div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('pricing')">← Back</button>
            <button class="btn btn-primary" id="btn-product-upload-continue" onclick="showStep('confirm')" disabled>
                Continue →
            </button>
        </div>
    </div>

    <!-- ══ STEP: CONFIRM ═══════════════════════════════════ -->
    <div id="step-confirm" style="display:none">
        <div class="sec-title">Looking good 👍</div>
        <div class="sec-sub">Here's your brief. Tap any field to edit.</div>
        <div class="card" id="confirm-card"></div>

        <!-- Hidden upload wrap kept for compatibility -->
        <div id="confirm-upload-wrap" style="display:none"></div>

        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('pricing')">← Change option</button>
            <button class="btn btn-primary" id="btn-save" onclick="doSave()">
                Save & Continue →
            </button>
        </div>
    </div>

    <!-- ══ STAGE 2: MODEL PICKER (AI track + needs_model only) ══ -->
    <div id="step-model-picker" style="display:none">
        <div class="sec-title">👗 Choose a Model</div>
        <div class="sec-sub">Select the model who will wear your product in the video</div>

        <!-- Filter tabs -->
        <div class="chips" id="model-filter-chips" style="margin-bottom:16px;"></div>

        <!-- Loading state -->
        <div id="model-loading" class="card" style="text-align:center;padding:24px;color:var(--muted);">
            <span class="spin" style="color:var(--purple);"></span> Loading models…
        </div>

        <!-- Model grid -->
        <div id="model-grid" style="display:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:16px;"></div>

        <!-- Action row -->
        <div class="btn-row" id="model-picker-actions" style="display:none">
            <button class="btn btn-ghost" onclick="showStep('confirm')">← Back</button>
            <button class="btn btn-primary" id="btn-model-continue" onclick="modelPickerContinue()" disabled>
                Continue with this model →
            </button>
        </div>
    </div>

    <!-- ══ STAGE 2: STORYBOARD ════════════════════════════ -->
    <div id="step-storyboard" style="display:none">
        <div class="sec-title">🎬 Your Scene Plan</div>
        <div class="sec-sub" id="storyboard-sub">AI has written your scenes — edit any caption, then approve to create your video</div>

        <!-- Generating indicator -->
        <div class="s2-generating card" id="s2-generating" style="display:none">
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="spin" style="color:var(--purple);width:20px;height:20px;border-width:3px;"></span>
                <div>
                    <div style="font-weight:700;color:var(--dark-blue);">Planning your scenes…</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;" id="s2-gen-status">Analysing brief and generating 7 scenes</div>
                </div>
            </div>
        </div>

        <!-- Scene cards grid -->
        <div id="scene-grid"></div>

        <!-- Action bar — right aligned -->
        <div class="s2-action-bar" id="s2-action-bar" style="display:none">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;width:100%;">
                <span id="s2-scene-count" style="font-size:12px;color:var(--muted);white-space:nowrap;"></span>
                <!-- Voice -->
                <select class="s2-select" id="s2StdHostVoice" style="font-size:11px;padding:5px 8px;min-width:120px;">
                    <option value="openai:alloy">🎤 Alloy</option>
                    <option value="openai:nova">🎤 Nova (F)</option>
                    <option value="openai:echo">🎤 Echo (M)</option>
                    <option value="openai:onyx">🎤 Onyx (M)</option>
                    <option value="openai:shimmer">🎤 Shimmer (F)</option>
                </select>
                <!-- Duration -->
                <div style="display:flex;gap:4px;align-items:center;">
                    <span style="font-size:11px;color:var(--muted);white-space:nowrap;">⏱</span>
                    <?php foreach ([10,20,30,40,50,60] as $d): ?>
                    <button class="dur-pill <?= $d===30?'active':'' ?>" onclick="selectDuration(<?= $d ?>,this)" style="padding:4px 8px;font-size:10px;"><?= $d ?>s</button>
                    <?php endforeach; ?>
                </div>
                <!-- Language -->
                <select class="s2-select" id="s2Language" style="font-size:11px;padding:5px 8px;min-width:100px;">
                    <option value="en" selected>🇬🇧 English</option>
                    <option value="ur">🇵🇰 Urdu</option>
                    <option value="ar">🇸🇦 Arabic</option>
                    <option value="hi">🇮🇳 Hindi</option>
                    <option value="es">🇪🇸 Spanish</option>
                    <option value="fr">🇫🇷 French</option>
                    <option value="tr">🇹🇷 Turkish</option>
                    <option value="id">🇮🇩 Indonesian</option>
                </select>
                <!-- Speed -->
                <select class="s2-select" id="s2Rate" style="font-size:11px;padding:5px 8px;min-width:80px;">
                    <option value="0.9">0.9×</option>
                    <option value="1.0">1.0×</option>
                    <option value="1.1" selected>1.1×</option>
                    <option value="1.2">1.2×</option>
                </select>
                <div style="margin-left:auto;display:flex;gap:8px;">
                    <button class="btn btn-ghost btn-sm" onclick="regenerateScenes()">↺ Regenerate</button>
                    <button class="btn btn-primary" id="btn-approve" onclick="approveAndSave()">
                        ✓ Approve & Build →
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Inline build progress — shown BELOW scene cards after approve ── -->
        <div id="inline-build-wrap" style="display:none;margin-top:20px;">
            <!-- Step pills -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;" id="inline-steps">
                <div class="s2-step-pill" id="ipill-1">💾 Saving</div>
                <div class="s2-step-pill" id="ipill-2">🔊 Audio</div>
                <div class="s2-step-pill" id="ipill-3">🖼 Media</div>
            </div>
            <!-- Log console -->
            <div class="s2-log" id="s2Log" style="max-height:200px;font-size:11px;"></div>
            <!-- Done bar -->
            <div id="s2DoneBarGrid" style="display:none;margin-top:12px;background:var(--dark-blue);border-radius:var(--radius);padding:14px 18px;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="color:#fff;font-size:13px;font-weight:700;">✅ Video ready!</div>
                <a id="s2VideoLink" href="videomaker.php" style="font-size:13px;font-weight:700;color:#4fc3f7;text-decoration:none;padding:8px 14px;background:rgba(79,195,247,0.15);border:1px solid #4fc3f7;border-radius:8px;">Open Video Editor →</a>
            </div>

            <!-- Video preview cards — populated by cron polling -->
            <div id="s2VideoCards" style="display:none;margin-top:16px;">
                <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">🎬 Generated Videos</div>
                <div id="s2VideoCardsGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;"></div>
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <a id="s2VideoEditorBtn" href="videomaker.php" style="font-size:13px;font-weight:700;color:#fff;text-decoration:none;padding:10px 18px;background:var(--dark-blue);border-radius:8px;display:inline-flex;align-items:center;gap:6px;">
                        🎬 Open Video Editor →
                    </a>
                    <span id="s2VideoProgress" style="font-size:12px;color:var(--muted);align-self:center;"></span>
                </div>
            </div>
        </div>
    </div>

</div><!-- /wrap -->

<?php if ($is_debug): ?>
<div class="wrap">
    <div class="dbg">
        <h4>🔧 Debug Panel — Stage 1 v2 (Admin only)</h4>
        <pre id="dbg-out">Waiting for interaction...</pre>
    </div>
</div>
<?php endif; ?>

<div class="toast-wrap" id="toast-wrap"></div>

<!-- ══ BUILD VIDEO MODAL ══════════════════════════════════════ -->
<div class="s2-overlay" id="s2Overlay">
  <div class="s2-panel" id="s2Panel">

    <!-- Inline processing spinner -->
    <div class="s2-processing-overlay" id="s2ProcessingOverlay">
      <div class="s2-spinner"></div>
      <div>
        <div class="s2-processing-msg"  id="s2ProcessingMsg">Building your video…</div>
        <div class="s2-processing-step" id="s2ProcessingStep">Please wait…</div>
      </div>
    </div>

    <div class="s2-header">
      <h2>🎬 Build Promo Video</h2>
      <button class="s2-close" id="s2CloseBtn" onclick="closeS2Promo()">✕</button>
    </div>

    <div class="s2-body">

      <!-- Setup panel — voice picker -->
      <div id="s2Setup">
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#166534;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
          <span>💳 Credits: <strong><?= $user_credit_balance ?></strong></span>
          <span style="opacity:.8;">Stock = 20cr · AI = <?= $credits_ai ?>cr</span>
        </div>

        <!-- Voice picker -->
        <div class="s2-role-card">
          <div class="s2-role-card-title">🎤 Voiceover</div>
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Filter by Gender</div>
            <div class="s2-gender-tabs" id="s2StdGenderTabs">
              <button class="s2-gtab active" onclick="filterVoicesPromo('male',this)">👨 Male</button>
              <button class="s2-gtab"        onclick="filterVoicesPromo('female',this)">👩 Female</button>
              <button class="s2-gtab"        onclick="filterVoicesPromo('all',this)">All</button>
            </div>
          </div>
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Voice</div>
            <select class="s2-select" id="s2StdHostVoice">
              <option value="openai:alloy">Alloy (neutral)</option>
              <option value="openai:echo">Echo (male)</option>
              <option value="openai:onyx">Onyx (male)</option>
              <option value="openai:nova">Nova (female)</option>
              <option value="openai:shimmer">Shimmer (female)</option>
            </select>
          </div>
        </div>

        <!-- Speech speed -->
        <div class="s2-role-card" style="background:#f8fafc;border-color:var(--border);">
          <div class="s2-role-card-title">⚡ Speech Speed</div>
          <div class="s2-role-subsection">
            <select class="s2-select" id="s2Rate">
              <option value="0.9">0.9× — Slightly slow</option>
              <option value="1.0">1.0× — Normal</option>
              <option value="1.1" selected>1.1× — Slightly fast</option>
              <option value="1.2">1.2× — Fast</option>
            </select>
          </div>
        </div>

        <!-- Video Duration -->
        <div class="s2-role-card" style="background:#f8fafc;border-color:var(--border);">
          <div class="s2-role-card-title">⏱ Video Duration</div>
          <div class="s2-role-subsection">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <?php foreach ([10,20,30,40,50,60] as $d): ?>
              <button class="dur-pill <?= $d===30?'active':'' ?>" onclick="selectDuration(<?= $d ?>,this)"><?= $d ?>s</button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Language -->
        <div class="s2-role-card" style="background:#f8fafc;border-color:var(--border);">
          <div class="s2-role-card-title">🌐 Language</div>
          <div class="s2-role-subsection">
            <select class="s2-select" id="s2Language">
              <option value="en" selected>🇬🇧 English (default)</option>
              <option value="ur">🇵🇰 Urdu</option>
              <option value="ar">🇸🇦 Arabic</option>
              <option value="hi">🇮🇳 Hindi</option>
              <option value="es">🇪🇸 Spanish</option>
              <option value="fr">🇫🇷 French</option>
              <option value="tr">🇹🇷 Turkish</option>
              <option value="id">🇮🇩 Indonesian</option>
            </select>
          </div>
        </div>

        <button class="s2-start-btn" onclick="startPromoVideo()">🚀 Build Video Now</button>
      </div>

      <!-- Progress panel -->
      <div id="s2Progress" style="display:none;">
        <div class="s2-steps">
          <div class="s2-step" id="s2Step0"><span class="s2-step-icon">💾</span><span class="s2-step-title">Save scenes</span><span class="s2-step-sub">Waiting…</span></div>
          <div class="s2-step" id="s2Step1"><span class="s2-step-icon">✨</span><span class="s2-step-title">Enhance prompts</span><span class="s2-step-sub">Waiting…</span></div>
          <div class="s2-step" id="s2Step2"><span class="s2-step-icon">🔊</span><span class="s2-step-title">Generate audio</span><span class="s2-step-sub">Waiting…</span></div>
          <div class="s2-step" id="s2Step3"><span class="s2-step-icon">🖼️</span><span class="s2-step-title">Assign media</span><span class="s2-step-sub">Waiting…</span></div>
        </div>
        <div class="s2-log" id="s2Log"></div>
      </div>

    </div><!-- /s2-body -->

    <!-- Scene preview grid -->
    <div id="s2SceneGrid" style="display:none;">
      <div style="font-size:11px;font-weight:700;color:#64748b;letter-spacing:.1em;text-transform:uppercase;margin-bottom:10px;">📽 Scene Progress</div>
      <div id="s2SceneBoxes"></div>
      <div id="s2DoneBarGrid" style="display:none;margin-top:14px;padding:12px 14px;background:#0d3321;border:1.5px solid #22c55e;border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span style="font-size:13px;font-weight:600;color:#4ade80;">✅ Video ready!</span>
        <a id="s2VideoLink" href="#" style="font-size:13px;font-weight:700;color:#4fc3f7;text-decoration:none;padding:6px 12px;background:rgba(79,195,247,0.15);border:1px solid #4fc3f7;border-radius:8px;">Open Video Editor →</a>
      </div>
    </div>

  </div><!-- /s2-panel -->
</div><!-- /s2Overlay -->

<script>
// ── Constants from PHP ─────────────────────────────────────
const CO_NAME    = <?= json_encode($co_name) ?>;
const CO_BRAND   = <?= json_encode($co_brand ?: $co_name) ?>;
const CO_PHONE   = <?= json_encode($co_phone) ?>;
const CREDITS    = <?= $user_credit_balance ?>;
const CR_AI      = <?= $credits_ai ?>;
const CR_STOCK   = <?= $credits_stock ?>;
const GEN_RATES  = <?= json_encode($gen_rates) ?>;
const SCENES     = 7;

// Calculate credits based on duration — scales from base 30s price
function calcCredits(gen_mode, duration) {
    const base30 = gen_mode === 'fal_ai' ? CR_AI : Math.round(CR_AI * 2/3);
    return Math.ceil(base30 * (duration / 30));
}
const IS_DEBUG   = <?= $is_debug ? 'true' : 'false' ?>;

// ── State ──────────────────────────────────────────────────
const S = {
    category: '', cat_name: '', sub_cats: [],
    sub_category: '',
    has_product_name: true,
    pn_label: 'Product Name',
    pn_placeholder: '',
    needs_image: false,
    needs_model: false,
    brief_id: 0,
    video_track: '',   // 'ai' or 'stock'
    field_index: 0,
    fields: [],
    ai_features: '', ai_audience: '',
    product_image_path: '', product_image_name: '',
};

// ── Utilities ──────────────────────────────────────────────
function toast(msg, type = 'info') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = msg;
    document.getElementById('toast-wrap').appendChild(el);
    setTimeout(() => el.remove(), 3500);
}
function dbg(data) {
    if (!IS_DEBUG) return;
    document.getElementById('dbg-out').textContent = JSON.stringify(data, null, 2);
}
function showStep(name) {
    ['category','offer','duration','pricing','gen-mode','media-upload','product-upload','confirm','model-picker','storyboard'].forEach(s =>
        document.getElementById('step-' + s)?.style && (document.getElementById('step-' + s).style.display = s === name ? '' : 'none'));
    updateProg(name);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function updateProg(step) {
    const active = (step === 'category' || step === 'offer') ? 1
                 : (step === 'duration' || step === 'pricing') ? 2
                 : (step === 'gen-mode' || step === 'product-upload' || step === 'media-upload' || step === 'confirm') ? 3
                 : step === 'model-picker' ? 3
                 : step === 'storyboard' ? 4 : 1;
    for (let i = 1; i <= 5; i++) {
        const el = document.getElementById('prog-' + i);
        if (!el) continue;
        el.className = 'prog-step' + (i < active ? ' done' : i === active ? ' active' : '');
    }
}

// ── Load categories ────────────────────────────────────────
async function loadCategories() {
    const fd = new FormData();
    fd.append('ajax_action', 'get_promo_categories');
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.success) { toast('Failed to load categories', 'error'); return; }

    const grid = document.getElementById('cat-grid');
    grid.innerHTML = '';
    d.categories.forEach(cat => {
        const t = document.createElement('div');
        t.className = 'cat-tile';
        t.innerHTML = `<div class="cat-icon">${cat.category_icon}</div>
                       <div class="cat-name">${cat.category_name}</div>`;
        t.addEventListener('click', () => selectCategory(t, cat));
        grid.appendChild(t);
    });

}

// ── Select category ────────────────────────────────────────
function selectCategory(tile, cat, silent = false) {
    document.querySelectorAll('.cat-tile').forEach(t => t.classList.remove('selected'));
    tile.classList.add('selected');

    S.category         = cat.category_key;
    S.cat_name         = cat.category_name;
    S.sub_cats         = cat.sub_categories || [];
    S.sub_category     = S.sub_cats[0] || '';
    S.has_product_name = cat.has_product_name;
    S.pn_label         = cat.product_name_label || 'Product Name';
    S.pn_placeholder   = cat.product_name_placeholder || '';
    S.needs_image      = cat.needs_image;
    S.needs_model      = cat.needs_model;

    // Save ai_group to company profile silently
    const gFd = new FormData();
    gFd.append('ajax_action', 'save_company_ai_group');
    gFd.append('ai_group',    cat.category_name);
    gFd.append('ai_subgroup', S.sub_cats[0] || '');
    fetch('', { method:'POST', body:gFd }).catch(() => {});

    // Update product name label + placeholder
    document.getElementById('pn-label').innerHTML =
        S.pn_label + ' <span style="color:var(--red);margin-left:4px;">*</span>';
    document.getElementById('pn-hint').textContent =
        'Enter the name of your ' + S.pn_label.toLowerCase();
    document.getElementById('input-product-name').placeholder = S.pn_placeholder;

    // Sub-category chips
    const subCard = document.getElementById('sub-card');
    const chips   = document.getElementById('sub-chips');
    chips.innerHTML = '';
    if (S.sub_cats.length > 1) {
        subCard.style.display = '';
        S.sub_cats.forEach((s, i) => {
            const c = document.createElement('div');
            c.className = 'chip' + (i === 0 ? ' active' : '');
            c.textContent = s;
            c.addEventListener('click', () => {
                document.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
                c.classList.add('active');
                S.sub_category = s;
                updateOfferHints();
                // Save sub-category preference to company
                const sFd = new FormData();
                sFd.append('ajax_action', 'save_company_ai_group');
                sFd.append('ai_group',    S.cat_name);
                sFd.append('ai_subgroup', s);
                fetch('', { method:'POST', body:sFd }).catch(() => {});
            });
            chips.appendChild(c);
        });
    } else { subCard.style.display = 'none'; }

    updateOfferHints();
    buildExtraFields();
    buildFieldList();

    document.getElementById('offer-title').textContent = 'Tell us about your ' + S.cat_name;
    document.getElementById('offer-sub').textContent   = 'Answer a few quick questions';

    // Show/hide image upload based on category
    // Upload zone now lives on confirm step — nothing to show/hide here

    if (!silent) setTimeout(() => showStep('offer'), 200);
}

// ── Update offer hints by category ────────────────────────
function updateOfferHints() {
    const hints = {
        fashion:    'Occasion, style, available sizes or colours',
        food:       'Ingredients, portion, special preparation, serve for how many',
        tech_home:  'Storage, colour, condition, what\'s in the box',
        travel_svc: 'Duration, inclusions, departure city, what makes it special',
        health_edu: 'Program length, format, results, support included',
        realestate: 'Bedrooms, floor, area, nearby landmarks',
    };
    const placeholders = {
        fashion:    'e.g. Available in small to XL, hand-stitched, ships within 48 hours',
        food:       'e.g. Slow-cooked 4 hours, family recipe, serves 4-6 people',
        tech_home:  'e.g. Box pack, warranty card, free tempered glass included',
        travel_svc: 'e.g. Flights from Karachi, breakfast included, visa assistance',
        health_edu: 'e.g. 3 months program, weekly live sessions, WhatsApp support',
        realestate: 'e.g. Ground floor, 10 marla, near mosque and school',
    };
    document.getElementById('offer-hint').textContent = hints[S.category] || 'Describe your offer in plain words';
    document.getElementById('input-offer').placeholder = placeholders[S.category] || 'Describe what you are promoting';
}

// ── Build extra category fields ────────────────────────────
function buildExtraFields() {
    const container = document.getElementById('extra-fields');
    container.innerHTML = '';

    const defs = {
        fashion: [
            { id:'ef-avail', key:'availability', label:'Availability',
              type:'select', opts:['Limited stock','Made to order','Ready to ship','Pre-order open'] },
        ],
        food: [
            { id:'ef-avail', key:'availability', label:'When available',
              type:'select', opts:['Today only','This week','Order 24hrs ahead','Always available','Eid special'] },
            { id:'ef-delivery', key:'delivery', label:'Delivery or dine-in',
              type:'select', opts:['Delivery','Dine-in','Both','Catering only'] },
            { id:'ef-area', key:'area_served', label:'Area served', hint:'optional',
              type:'text', ph:'e.g. Delivery across Karachi' },
        ],
        tech_home: [
            { id:'ef-cond', key:'condition', label:'Condition',
              type:'select', opts:['New — Box pack','New — Open box','Refurbished','Pre-owned'] },
            { id:'ef-incl', key:'whats_included', label:'Included in box', hint:'optional',
              type:'text', ph:'e.g. Charger, warranty card, free case' },
            { id:'ef-delivery', key:'delivery', label:'Delivery',
              type:'select', opts:['Same day (Lahore/Karachi/Islamabad)','Next day nationwide','2-3 days','Pick-up only'] },
        ],
        travel_svc: [
            { id:'ef-incl', key:'whats_included', label:'What\'s included',
              type:'textarea', ph:'e.g. Return flights, 4-star hotel, airport transfers, breakfast daily' },
            { id:'ef-dep', key:'departure_date', label:'Departure / frequency',
              type:'text', ph:'e.g. 15 July · Every Friday' },
            { id:'ef-seats', key:'seats_available', label:'Seats / slots available', hint:'optional',
              type:'text', ph:'e.g. 12 seats remaining · 3 spots left this month' },
        ],
        health_edu: [
            { id:'ef-fmt', key:'course_format', label:'Format',
              type:'select', opts:['Online — Live sessions','Online — Recorded','In-person','Home delivery','App-based','Hybrid'] },
            { id:'ef-audience2', key:'target_audience_extra', label:'Who is it for', hint:'optional',
              type:'text', ph:'e.g. Working women 25-40 with no time for gym' },
            { id:'ef-batch', key:'next_batch', label:'Next batch / start date', hint:'optional',
              type:'text', ph:'e.g. 1st of every month · 15 July 2025' },
        ],
        realestate: [
            { id:'ef-proptype', key:'property_type', label:'Property type',
              type:'select', opts:['Apartment','House','Duplex','Commercial','Plot','Rental — Residential','Rental — Commercial'] },
            { id:'ef-loc', key:'property_location', label:'Location',
              type:'text', ph:'e.g. Bahria Town Phase 7, Rawalpindi' },
            { id:'ef-status', key:'property_status', label:'Status',
              type:'select', opts:['Ready possession','Under construction','Available from date','Rental available now'] },
        ],
    };

    const list = defs[S.category] || [];
    list.forEach(def => {
        S.fields.push(def.id);
        const w = document.createElement('div');
        w.className = 'fstep';
        w.id = def.id;
        w.dataset.key = def.key;

        const optStr = def.hint ? ` <span class="opt">— ${def.hint}</span>` : '';
        let inp = '';
        if (def.type === 'select') {
            // Render as chip buttons instead of dropdown
            inp = `<div class="chip-group" id="input-${def.id}" data-selected="${def.opts[0]}">
                ${def.opts.map((o,i) => `<button type="button" class="chip${i===0?' active':''}" onclick="selectChipField(this,'input-${def.id}')">${o}</button>`).join('')}
            </div>`;
        } else if (def.type === 'textarea') {
            inp = `<textarea id="input-${def.id}" rows="3" placeholder="${def.ph||''}"></textarea>`;
        } else {
            inp = `<input type="text" id="input-${def.id}" placeholder="${def.ph||''}">`;
        }
        w.innerHTML = `<div class="flabel">${def.label}${optStr}</div>${inp}`;
        container.appendChild(w);
    });
}

function selectChipField(btn, groupId) {
    const group = document.getElementById(groupId);
    if (!group) return;
    group.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    group.dataset.selected = btn.textContent.trim();
}

// ── Build field list ───────────────────────────────────────
function buildFieldList() {
    S.fields = ['f-product-name','f-offer','f-highlight','f-price','f-urgency','f-cta','f-contact'];
    buildExtraFields(); // adds extras to S.fields
    S.field_index = 0;
    renderField(0);
    renderDots();
}

// ── Render field ───────────────────────────────────────────
function renderField(idx) {
    S.fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.remove('active');
    });
    const cur = S.fields[idx];
    if (cur) {
        const el = document.getElementById(cur);
        if (el) {
            el.classList.add('active');
            setTimeout(() => {
                const inp = el.querySelector('input[type=text],textarea');
                if (inp) inp.focus();
            }, 280);
        }
    }
    document.getElementById('btn-back').style.display = idx > 0 ? '' : 'none';
    const isLast = idx === S.fields.length - 1;
    document.getElementById('btn-next').textContent = isLast ? 'Choose video option →' : 'Continue →';
    renderDots();
}

function renderDots() {
    document.getElementById('fdots').innerHTML = S.fields.map((_, i) =>
        `<div class="fdot ${i < S.field_index ? 'done' : i === S.field_index ? 'active' : ''}"></div>`
    ).join('');
}

// ── Field navigation ───────────────────────────────────────
function fieldNext() {
    const cur = S.fields[S.field_index];

    // Validation
    if (cur === 'f-product-name') {
        const pn = document.getElementById('input-product-name').value.trim();
        if (!pn) { toast('Please enter the ' + S.pn_label.toLowerCase(), 'error'); return; }
        // Trigger AI generation if not done yet
        if (!S.ai_features) {
            generateFeaturesAudience(pn).then(() => {
                S.field_index++;
                renderField(S.field_index);
            });
            return;
        }
    }
    if (cur === 'f-offer' && !document.getElementById('input-offer').value.trim()) {
        toast('Please describe your offer', 'error'); return;
    }
    if (cur === 'f-contact' && !document.getElementById('input-contact').value.trim()) {
        toast('Please enter a contact number or link', 'error'); return;
    }

    if (S.field_index < S.fields.length - 1) {
        S.field_index++;
        renderField(S.field_index);
    } else {
        showStep('duration');
    }
}

function fieldBack() {
    if (S.field_index > 0) { S.field_index--; renderField(S.field_index); }
}

// Enter key advances (except textarea)
document.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        const active = document.querySelector('.fstep.active');
        if (active && !active.querySelector('textarea')) {
            e.preventDefault(); fieldNext();
        }
    }
});

// ── AI: Generate features + audience ──────────────────────
async function generateFeaturesAudience(productName) {
    const gen = document.getElementById('ai-generating');
    gen.classList.add('visible');

    // Detect sub-category from product name simultaneously
    detectSubcat(productName);

    const fd = new FormData();
    fd.append('ajax_action',   'generate_features_audience');
    fd.append('product_name',  productName);
    fd.append('category_key',  S.category);
    fd.append('sub_category',  S.sub_category);
    fd.append('brand_name',    CO_BRAND);

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();

        gen.classList.remove('visible');

        if (d.success) {
            S.ai_features = d.key_features    || '';
            S.ai_audience = d.target_audience || '';

            document.getElementById('input-key-features').value    = S.ai_features;
            document.getElementById('input-target-audience').value = S.ai_audience;
            document.getElementById('ai-result-features').classList.add('visible');
            document.getElementById('ai-result-audience').classList.add('visible');

            dbg({ ai_features: S.ai_features, ai_audience: S.ai_audience });
        } else {
            toast('Could not generate features — you can fill them in manually', 'error');
        }
    } catch(e) {
        gen.classList.remove('visible');
        toast('Network error generating features', 'error');
    }
}

// Also trigger AI if user blurs the product name field (convenience)
document.getElementById('input-product-name').addEventListener('blur', async function() {
    const pn = this.value.trim();
    if (pn && !S.ai_features) {
        await generateFeaturesAudience(pn);
    }
});

// ── Detect sub-category from product name ─────────────────
async function detectSubcat(text) {
    if (!text || !S.category) return;
    const fd = new FormData();
    fd.append('ajax_action',  'detect_subcategory');
    fd.append('product_name', text);
    fd.append('category_key', S.category);
    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success && d.sub_category) {
            S.sub_category = d.sub_category;
            // Highlight matching chip
            document.querySelectorAll('.chip').forEach(c => {
                if (c.textContent.trim() === d.sub_category) {
                    document.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
                    c.classList.add('active');
                }
            });
        }
    } catch(e) {}
}

// ── Show pricing screen ────────────────────────────────────
function showPricing() {
    const dur = _promoDuration || 30;
    const crAI    = calcCredits('fal_ai', dur);
    const crSaver = calcCredits('modal', dur);
    const crStock = CR_STOCK;

    // Update pricing card credit displays
    const aiCredEl = document.querySelector('#pc-ai .pricing-credits');
    if (aiCredEl) aiCredEl.innerHTML = crAI + ' <span>credits for ' + dur + 's</span>';
    const stCredEl = document.querySelector('#pc-stock .pricing-credits');
    if (stCredEl) stCredEl.innerHTML = crStock + ' <span>credits for ' + dur + 's</span>';

    // Update time estimates based on duration
    const stockTime = document.querySelector('#pc-stock .pricing-time');
    if (stockTime) stockTime.textContent = '⏱ Ready in ~' + Math.max(1, Math.ceil(dur/30)) + ' minute' + (dur > 30 ? 's' : '');
    const aiTime = document.querySelector('#pc-ai .pricing-time');
    if (aiTime) aiTime.textContent = '⏱ Ready in ~' + Math.ceil(dur/2) + ' minutes';

    // Update credit status
    const setStatus = (elId, credits) => {
        const el = document.getElementById(elId);
        if (!el) return;
        el.className = 'pricing-status ' + (CREDITS >= credits ? 'ok' : 'no');
        el.textContent = CREDITS >= credits
            ? `✓ You have ${CREDITS} credits`
            : `✗ Need ${credits - CREDITS} more credits`;
    };
    setStatus('ps-stock-status', crStock);
    setStatus('ps-ai-status',    crAI);

    // Store calculated credits for use in save/deduct
    window._calcCrAI    = crAI;
    window._calcCrSaver = crSaver;

    if (CREDITS >= crStock) selectTrack('stock');

    dbg({ step: 'pricing', credits: CREDITS, cr_ai: crAI, cr_stock: crStock, duration: dur });
    showStep('pricing');
}

function selectTrack(track) {
    S.video_track = track;
    document.getElementById('pc-stock').classList.toggle('selected', track === 'stock');
    document.getElementById('pc-ai').classList.toggle('selected', track === 'ai');
    const credits = track === 'ai'
        ? (window._calcCrAI || calcCredits('fal_ai', _promoDuration || 30))
        : CR_STOCK;
    const canAfford = CREDITS >= credits;
    document.getElementById('btn-pricing-next').disabled = !canAfford;
    if (!canAfford) toast(`Not enough credits — need ${credits} credits`, 'error');
    if (track === 'stock' && S.product_image_path) clearProductImage();
    const siEl = document.getElementById('f-special-instructions');
    if (siEl) siEl.style.display = track === 'ai' ? '' : 'none';
}

// ── Collect all fields ─────────────────────────────────────
function collectFields() {
    const val = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
    const extra = {};
    document.querySelectorAll('#extra-fields .fstep').forEach(el => {
        const key = el.dataset.key;
        // Check for chip group first, then regular input
        const chipGroup = el.querySelector('.chip-group');
        const inp = el.querySelector('input,textarea,select');
        if (key) {
            const val = chipGroup ? (chipGroup.dataset.selected || '') : (inp ? inp.value.trim() : '');
            if (val) extra[key] = val;
        }
    });
    return {
        category_key:   S.category,
        sub_category:   S.sub_category,
        product_name:   val('input-product-name'),
        key_features:   val('input-key-features'),
        target_audience:val('input-target-audience'),
        offer_text:     val('input-offer'),
        highlight:      val('input-highlight'),
        price_text:     val('input-price'),
        urgency_text:   val('input-urgency'),
        special_instructions: val('input-special-instructions'),
        target_location: val('input-target-location'),
        audience_detail: val('input-audience-detail'),
        duration:        _promoDuration || 30,
        language:        S.language || 'en',
        cta_action:     val('input-cta'),
        cta_contact:    val('input-contact'),
        video_track:    S.video_track,
        brief_id:       S.brief_id,
        extra,
    };
}

// ── Show confirm ───────────────────────────────────────────
function saveBriefAndContinue() {
    const f = collectFields();
    const card = document.getElementById('confirm-card');
    const ctaLabels = { whatsapp:'WhatsApp us', call:'Call us', dm:'DM on Instagram',
                        visit:'Visit store', book:'Book online', order:'Order now', website:'Visit website' };
    const crAI    = window._calcCrAI    || calcCredits('fal_ai', _promoDuration || 30);
    const crSaver = window._calcCrSaver || calcCredits('modal',  _promoDuration || 30);
    const selectedCr = (f.video_track === 'ai' && S.gen_mode === 'standard') ? crSaver : crAI;
    const modeLabel  = S.gen_mode === 'standard' ? '💰 Smart Saver' : '⚡ Fast Mode';
    const trackLabel = f.video_track === 'ai'
        ? `✨ AI Custom Video — ${selectedCr} credits · ${_promoDuration || 30}s · ${modeLabel}`
        : `📚 Stock Media Video — ${CR_STOCK} credits`;

    const rows = [
        ['Category',      S.cat_name + (f.sub_category ? ' — ' + f.sub_category : '')],
        ['Brand',         CO_BRAND],
        [S.pn_label,      f.product_name],
        ['Key features',  f.key_features   || '—'],
        ['Audience',      f.target_audience|| '—'],
        ['Offer details', f.offer_text     || '—'],
        ['Highlight',     f.highlight      || '—'],
        ['Price',         f.price_text     || '—'],
        ['Urgency',       f.urgency_text   || '—'],
        ...(f.special_instructions ? [['Special Instructions', f.special_instructions]] : []),
        ['Call to action',ctaLabels[f.cta_action] || f.cta_action],
        ['Contact',       f.cta_contact],
        ['Video type',    trackLabel],
    ];
    Object.entries(f.extra).forEach(([k, v]) => rows.push([k.replace(/_/g,' '), v]));

    card.innerHTML = rows.map(([l, v]) =>
        `<div class="confirm-row">
            <div class="confirm-label">${l}</div>
            <div class="confirm-val">${v || '—'}</div>
        </div>`
    ).join('');

    dbg({ step: 'confirm', fields: f });

    // Show image upload on confirm step only for AI track + needs_image/model
    const needsUpload = (S.video_track === 'ai') && (S.needs_image || S.needs_model);
    const confirmUpload = document.getElementById('confirm-upload-wrap');
    if (confirmUpload) confirmUpload.style.display = needsUpload ? '' : 'none';

    // Stock track → media upload, AI track → gen mode selector → product upload
    if (S.video_track === 'stock') {
        showStep('media-upload');
    } else if (S.video_track === 'ai') {
        showStep('gen-mode');
    } else {
        showStep('confirm');
    }
}

// ── Save to DB ─────────────────────────────────────────────
async function doSave() {
    const f   = collectFields();
    const btn = document.getElementById('btn-save');

    // Block if AI track + needs_model/image but no product image uploaded
    const needsImg = (S.video_track === 'ai') && (S.needs_image || S.needs_model);
    if (needsImg && !S.product_image_path) {
        toast('⚠ Please upload your product image first — it\'s required for AI generation', 'error');
        // Highlight the upload zone
        const zone = document.getElementById('confirmUploadZone');
        if (zone) {
            zone.style.borderColor = 'var(--red)';
            zone.style.background  = '#fff5f5';
            setTimeout(() => {
                zone.style.borderColor = '';
                zone.style.background  = '';
            }, 3000);
        }
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Saving...';

    const fd = new FormData();
    fd.append('ajax_action',   'save_brief_draft');
    Object.entries(f).forEach(([k, v]) => {
        if (k === 'extra') {
            Object.entries(v).forEach(([ek, ev]) => fd.append(ek, ev));
        } else {
            fd.append(k, v ?? '');
        }
    });
    fd.append('video_track', f.video_track);

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();

        if (!d.success) {
            toast('Save failed: ' + (d.error || 'Unknown error'), 'error');
            btn.disabled = false; btn.textContent = 'Save & Continue →';
            return;
        }

        S.brief_id = d.brief_id;
        dbg({ step: 'saved', brief_id: d.brief_id, track: f.video_track, brief: d.brief });
        toast('✓ Brief saved! Building your scene plan…', 'success');
        btn.textContent = '✓ Saved';

        // Move straight to Stage 2
        setTimeout(() => launchStage2(d.brief_id, f.video_track), 600);

    } catch(e) {
        toast('Network error: ' + e.message, 'error');
        btn.disabled = false;
        btn.textContent = 'Save & Continue →';
    }
}

// ── Image upload ───────────────────────────────────────────
let _promoDuration = 30; // default 30 seconds
function showMoreLanguages(btn) {
    const more = document.getElementById('lang-chips-more');
    if (more) { more.style.display = 'flex'; btn.style.display = 'none'; }
}

function selectDurationStep(sec, btn) {
    <?php if ($is_free_plan): ?>
    if (sec > 30) { toast('Upgrade to Pro to unlock videos longer than 30 seconds', 'warning'); return; }
    <?php endif; ?>
    _promoDuration = sec;
    document.querySelectorAll('#dur-chips .chip').forEach(p => {
        p.style.background = ''; p.style.color = ''; p.style.borderColor = '';
    });
    btn.style.background = '#0f2a44';
    btn.style.color = '#fff';
    btn.style.borderColor = '#0f2a44';
    document.querySelectorAll('.dur-pill').forEach(p => {
        p.classList.toggle('active', parseInt(p.textContent) === sec);
    });
}

function selectLangStep(val, btn) {
    S.language = val;
    document.querySelectorAll('#lang-chips .chip, #lang-chips-more .chip').forEach(p => {
        p.style.background = ''; p.style.color = ''; p.style.borderColor = '';
    });
    btn.style.background = '#0f2a44';
    btn.style.color = '#fff';
    btn.style.borderColor = '#0f2a44';
    const sel = document.getElementById('s2Language');
    if (sel) sel.value = val;
}

function selectDuration(sec, btn) {
    _promoDuration = sec;
    document.querySelectorAll('.dur-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
}

function selectGenMode(mode) {
    S.gen_mode = mode; // 'fast' or 'standard'
    document.querySelectorAll('.gen-mode-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('gm-' + mode)?.classList.add('selected');
    document.getElementById('btn-gen-mode-continue').disabled = false;
}

function continueFromGenMode() {
    if (!S.gen_mode) return;
    if (S.needs_image || S.needs_model) {
        showStep('product-upload');
    } else {
        showStep('confirm');
    }
}

// ── Stock media upload ─────────────────────────────────────
(function() {
    const zone  = document.getElementById('stockMediaZone');
    const input = document.getElementById('stockMediaInput');
    if (!zone) return;

    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        [...e.dataTransfer.files].forEach(f => uploadStockMedia(f));
    });
    input.addEventListener('change', e => {
        [...e.target.files].forEach(f => uploadStockMedia(f));
        e.target.value = '';
    });
})();

function showMoreUpload() {
    // Re-show the drop zone for another upload
    document.getElementById('stockMediaZone').style.display = '';
    document.getElementById('stockMediaInput').value = '';
    document.getElementById('stockMediaZone').scrollIntoView({ behavior:'smooth' });
}

async function uploadStockMedia(file) {
    const maxSize = 50 * 1024 * 1024;
    if (file.size > maxSize) { toast('File too large: ' + file.name + ' (max 50MB)', 'error'); return; }

    const allowed = ['image/jpeg','image/png','image/webp','video/mp4','video/webm','video/quicktime'];
    if (!allowed.includes(file.type)) { toast('Unsupported type: ' + file.type, 'error'); return; }

    // Show uploading state
    const upMsg = document.getElementById('stock-media-uploading');
    const upTxt = document.getElementById('stock-media-uploading-msg');
    upMsg.style.display = '';
    upTxt.textContent   = 'Uploading ' + file.name + '… (analyzing with AI vision — may take 15s)';
    document.getElementById('stockMediaZone').style.display = 'none';

    const fd = new FormData();
    fd.append('ajax_action', 'upload_stock_media');
    fd.append('stock_media',  file);

    try {
        const r    = await fetch('', { method:'POST', body:fd });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch(e) {
            toast('Upload error — invalid response', 'error');
            console.error('[StockUpload]', text.substring(0,300));
            upMsg.style.display = 'none';
            return;
        }

        upMsg.style.display = 'none';

        if (d.success) {
            // Add to uploaded list
            const list  = document.getElementById('stock-media-list');
            const items = document.getElementById('stock-media-items');
            list.style.display = '';

            const isVideo = file.type.startsWith('video/');
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);';

            // Show thumbnail if available
            let thumbHtml = '';
            if (d.thumbnail) {
                // Thumbnail is always a JPG (first frame for video, resized for image)
                thumbHtml = `<img src="${d.thumbnail}" style="width:50px;height:70px;object-fit:cover;border-radius:4px;flex-shrink:0;" onerror="this.style.display='none'">`;
            } else {
                thumbHtml = `<div style="width:50px;height:70px;background:var(--border);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">${isVideo?'🎬':'🖼️'}</div>`;
            }

            const tagStatus = d.tagged
                ? `<span style="color:var(--green);font-size:10px;">✅ Tagged + embedded (${d.embedding_dims}d)</span>`
                : `<span style="color:var(--muted);font-size:10px;">⚠ Tagging pending</span>`;

            row.innerHTML = `
                ${thumbHtml}
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${file.name}</div>
                    <div style="font-size:10px;color:var(--muted);margin-top:2px;">${(file.size/1024/1024).toFixed(1)}MB · ID: #${d.image_id || '—'}</div>
                    <div style="margin-top:3px;">${tagStatus}</div>
                    ${d.nl_tags ? `<div style="font-size:10px;color:#6d28d9;margin-top:2px;font-style:italic;">${d.nl_tags}</div>` : ''}
                </div>
                <div style="font-size:11px;color:var(--green);font-weight:700;flex-shrink:0;">✓ Saved</div>`;
            items.appendChild(row);
            // Show post-upload action buttons
            const actions = document.getElementById('stock-media-actions');
            if (actions) actions.style.display = 'flex';
            toast('✓ ' + file.name + (d.tagged ? ' — tagged & ready' : ' — uploaded'), 'success');
        } else {
            toast('Upload failed: ' + (d.error || 'Unknown error'), 'error');
        }
    } catch(e) {
        upMsg.style.display = 'none';
        toast('Upload error: ' + e.message, 'error');
    }
}
const uploadZone = document.getElementById('confirmUploadZone');
if (uploadZone) {
    uploadZone.addEventListener('dragover',  e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', e => {
        e.preventDefault(); uploadZone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) handleImageUpload(e.dataTransfer.files[0]);
    });
    document.getElementById('confirmProductImageInput').addEventListener('change', e => {
        if (e.target.files[0]) handleImageUpload(e.target.files[0]);
    });
}

async function handleImageUpload(file) {
    // Disable continue button during upload
    const continueBtn = document.getElementById('btn-product-upload-continue');
    if (continueBtn) continueBtn.disabled = true;
    const progEl = document.getElementById('product-upload-progress');
    if (progEl) progEl.style.display = '';

    // Show instant preview
    const reader = new FileReader();
    reader.onload = ev => {
        document.getElementById('confirmPreviewImg').src          = ev.target.result;
        document.getElementById('confirmPreviewName').textContent = file.name;
        document.getElementById('confirmPreviewDesc').textContent = 'Analysing with AI vision…';
        document.getElementById('confirmUploadZone').style.display    = 'none';
        document.getElementById('confirmProductPreview').style.display = '';
    };
    reader.readAsDataURL(file);

    // Upload to server
    const fd = new FormData();
    fd.append('ajax_action',   'upload_product_image');
    fd.append('product_image', file);
    try {
        const r    = await fetch('', { method: 'POST', body: fd });
        const text = await r.text();
        console.log('[Upload response raw]', text.substring(0, 500));
        let d;
        try { d = JSON.parse(text); }
        catch(je) {
            toast('Upload error: Server returned invalid response — check console', 'error');
            clearProductImage();
            if (progEl) progEl.style.display = 'none';
            return;
        }
        if (d.success) {
            S.product_image_path = d.path;
            S.product_image_name = d.name;
            window._garmentImagePath = d.path;
            const zone = document.getElementById('confirmUploadZone');
            if (zone) { zone.style.borderColor = ''; zone.style.background = ''; }
            const descEl = document.getElementById('confirmPreviewDesc');
            if (d.auto_desc) {
                descEl.innerHTML = '<span class="vision-badge">✨ AI Vision</span> ' + d.auto_desc;
            } else {
                descEl.textContent = 'Image uploaded successfully';
            }
            // Enable continue button
            if (continueBtn) continueBtn.disabled = false;
        } else {
            toast('Upload failed: ' + (d.error || 'Unknown error'), 'error');
            clearProductImage();
        }
    } catch(e) {
        toast('Upload error: ' + e.message, 'error');
        clearProductImage();
    } finally {
        if (progEl) progEl.style.display = 'none';
    }
}

async function clearProductImage() {
    document.getElementById('confirmProductPreview').style.display = 'none';
    document.getElementById('confirmUploadZone').style.display     = '';
    document.getElementById('confirmProductImageInput').value      = '';
    S.product_image_path = '';
    S.product_image_name = '';
    const fd = new FormData();
    fd.append('ajax_action', 'clear_product_image');
    await fetch('', { method: 'POST', body: fd });
}



// ── Modal helpers ──────────────────────────────────────────
let s2PromoCancelled = false;

function openS2Promo() {
    s2PromoCancelled = false;
    // Show inline build panel in storyboard — no modal needed
    document.getElementById('s2-action-bar').style.display    = 'none';
    document.getElementById('inline-build-wrap').style.display = '';
    document.getElementById('s2Log').innerHTML                 = '';
    document.getElementById('s2DoneBarGrid').style.display     = 'none';
    ['ipill-1','ipill-2','ipill-3'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.className = 's2-step-pill';
    });
    document.getElementById('inline-build-wrap').scrollIntoView({ behavior:'smooth', block:'nearest' });
    startPromoVideo();
}

function closeS2Promo() {
    s2PromoCancelled = true;
}

function s2Log(msg, type = 'info') {
    const log = document.getElementById('s2Log');
    if (!log) return;
    const p = document.createElement('p');
    p.className = 's2-log-line ' + type;
    p.textContent = msg;
    log.appendChild(p);
    log.scrollTop = log.scrollHeight;
}

function s2StepDone(n, txt) {
    const el = document.getElementById('ipill-' + n);
    if (el) { el.className = 's2-step-pill done'; if (txt) el.textContent = txt; }
    // Also update old modal steps if they exist
    const old = document.getElementById('s2Step' + n);
    if (old) { old.className = 's2-step done'; old.querySelector('.s2-step-sub') && (old.querySelector('.s2-step-sub').textContent = txt); }
}
function s2StepActive(n, txt) {
    const el = document.getElementById('ipill-' + n);
    if (el) { el.className = 's2-step-pill active'; if (txt) el.textContent = txt; }
    const old = document.getElementById('s2Step' + n);
    if (old) { old.className = 's2-step active'; old.querySelector('.s2-step-sub') && (old.querySelector('.s2-step-sub').textContent = txt); }
}
function s2StepError(n, txt) {
    const el = document.getElementById('ipill-' + n);
    if (el) { el.className = 's2-step-pill error'; if (txt) el.textContent = txt; }
}

function updateSceneBox(i, state, file, imageFolder) {
    const box   = document.getElementById('promo-box-' + i);
    const inner = document.getElementById('promo-box-inner-' + i);
    const card  = document.querySelector('.scene-card[data-scene-index="' + i + '"]') ||
                  document.getElementById('scene-card-' + i);
    const wrap  = document.getElementById('scene-img-wrap-' + i);

    if (state === 'done' && file) {
        const isVid  = /\.(mp4|webm|mov)$/i.test(file);
        const folder = imageFolder || (isVid ? 'podcast_videos' : 'podcast_images');
        const src    = folder + '/' + file;

        // Update scene-img-wrap with assigned media
        if (wrap) {
            wrap.innerHTML = isVid
                ? `<video src="${src}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" muted playsinline loop autoplay></video>
                   <div style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;font-size:9px;padding:2px 5px;border-radius:8px;">🎬</div>`
                : `<img src="${src}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'">
                   <div style="position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;font-size:9px;padding:2px 5px;border-radius:8px;">🖼</div>`;
        }
        if (card) card.style.borderColor = '#22c55e';
        if (box) { box.style.border = '2px solid #22c55e'; box.innerHTML = `<img src="${src}" style="width:100%;height:100%;object-fit:cover;"><div style="position:absolute;bottom:2px;left:0;right:0;text-align:center;font-size:8px;color:#fff;background:rgba(0,0,0,.5);">${i+1} ✓</div>`; }

    } else if (state === 'queued') {
        // AI track — image already on card from preview, just mark as queued
        if (card) card.style.borderColor = '#a78bfa';
        if (wrap) {
            const badge = document.createElement('div');
            badge.style.cssText = 'position:absolute;top:4px;right:4px;background:#a78bfa;color:#fff;font-size:9px;padding:2px 6px;border-radius:8px;font-weight:700;';
            badge.textContent = '🎬 Queued';
            if (!wrap.querySelector('.queued-badge')) { badge.className='queued-badge'; wrap.appendChild(badge); }
        }
        if (box && inner) { box.style.borderColor='#a78bfa'; inner.innerHTML=`<div style="font-size:9px;color:#a78bfa;font-weight:700;">${i+1}</div><div style="font-size:11px;">🎬</div>`; }

    } else if (state === 'audio') {
        if (card) card.style.borderColor = '#4fc3f7';
        if (box && inner) { box.style.borderColor='#4fc3f7'; inner.innerHTML=`<div style="font-size:9px;color:#4fc3f7;font-weight:700;">${i+1}</div><div style="font-size:11px;">🔊</div>`; }

    } else if (state === 'error') {
        if (card) card.style.borderColor = '#ef4444';
        if (box && inner) { box.style.borderColor='#ef4444'; inner.innerHTML=`<div style="font-size:9px;color:#ef4444;">${i+1}</div><div style="font-size:11px;">✗</div>`; }
    }
}

function filterVoicesPromo(gender, btn) {
    document.querySelectorAll('#s2StdGenderTabs .s2-gtab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    const sel = document.getElementById('s2StdHostVoice');
    const voiceMap = {
        male:   [['openai:alloy','Alloy (neutral)'],['openai:echo','Echo'],['openai:onyx','Onyx'],['openai:fable','Fable']],
        female: [['openai:nova','Nova'],['openai:shimmer','Shimmer']],
        all:    [['openai:alloy','Alloy'],['openai:echo','Echo'],['openai:onyx','Onyx'],['openai:fable','Fable'],['openai:nova','Nova'],['openai:shimmer','Shimmer']],
    };
    sel.innerHTML = (voiceMap[gender]||voiceMap.all).map(([v,l]) => `<option value="${v}">${l}</option>`).join('');
}

// ── Called from Approve button — opens modal ───────────────
function approveAndSave() {
    openS2Promo();
}

// ── Called from "Build Video Now" inside modal ─────────────
async function startPromoVideo() {
    const hostVoice   = document.getElementById('s2StdHostVoice').value || 'openai:alloy';
    const rate        = document.getElementById('s2Rate').value || '1.1';
    const S2_EP       = 'promo_step2.php';
    const genMode     = S.gen_mode || 'fast';
    const duration    = _promoDuration || 30;
    const language    = document.getElementById('s2Language')?.value || 'en';
    const _buildStart = performance.now();
    const elapsed     = () => ((performance.now() - _buildStart) / 1000).toFixed(1) + 's';

    // Switch to progress view
    // inline build — no modal
    
    
    
    
    s2Log('⟳ Saving scenes…', 'info');

    // ── Step 0: Save scenes to DB ──────────────────────────
    s2StepActive(0, 'Saving scenes…');
    s2Log('💾 Saving scene plan to database…', 'info');

    const fd = new FormData();
    fd.append('ajax_action', 'save_storyboard');
    fd.append('brief_id',    S2.brief_id);
    fd.append('track',       S2.track);
    fd.append('gen_mode',    S.gen_mode || 'fast');
    fd.append('duration',    duration);
    fd.append('language',    language);
    fd.append('scenes',      JSON.stringify(S2.scenes));
    fd.append('host_voice',  hostVoice);
    fd.append('rate',        rate);

    let podcast_id = 0;
    try {
        const r    = await fetch('', { method:'POST', body:fd });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); }
        catch(e) {
            s2Log('❌ Server error — ' + text.substring(0,300), 'error');
            console.error('save_storyboard raw:', text);
            s2StepError(0, 'Save failed — see console');
            
            
            return;
        }
        if (!d.success) {
            s2Log('❌ ' + (d.error || 'Save failed'), 'error');
            s2StepError(0, d.error || 'Failed');
            
            
            return;
        }
        podcast_id = d.podcast_id;
        S2.podcast_id = podcast_id;
        s2Log('✅ Podcast #' + podcast_id + ' — ' + d.scene_count + ' scenes saved (' + elapsed() + ')', 'success');
        s2StepDone(0, '✓ Podcast #' + podcast_id);

        // Build caption rows using user's font/animation settings
        s2Log('📝 Writing captions with your settings…', 'info');
        try {
            const capFd = new FormData();
            capFd.append('action',     'build_captions');
            capFd.append('podcast_id', podcast_id);
            capFd.append('company_id', <?= (int)$company_id ?>);
            capFd.append('admin_id',   <?= (int)$admin_id ?>);
            const capR = await fetch(S2_EP, { method:'POST', body:capFd, credentials:'include' });
            const capD = await capR.json();
            if (capD.success) {
                s2Log('✅ ' + capD.caption_rows + ' caption rows written (' + elapsed() + ')', 'success');
            } else {
                s2Log('⚠ Captions: ' + (capD.error || 'partial'), 'warning');
            }
        } catch(e) { s2Log('⚠ Captions error: ' + e.message, 'warning'); }
    } catch(e) {
        s2Log('❌ Network error: ' + e.message, 'error');
        s2StepError(0, e.message);
        
        
        return;
    }

    // Draw scene boxes
    
    const boxesWrap = document.getElementById('s2SceneBoxes');
    boxesWrap.innerHTML = '';
    S2.scenes.forEach((_, i) => {
        const box = document.createElement('div');
        box.id = 'promo-box-' + i;
        box.style.cssText = 'position:relative;width:52px;height:92px;background:#0f2744;border:1.5px solid #1e3a5f;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0;';
        box.innerHTML = `<div id="promo-box-inner-${i}" style="text-align:center;"><div style="font-size:9px;color:#4fc3f7;font-weight:700;">${i+1}</div><div style="font-size:14px;margin-top:2px;">⏳</div></div>`;
        boxesWrap.appendChild(box);
    });

    // ── Set voice on podcast record ────────────────────────
    s2StepActive(1, 'Setting up…');
    s2Log('⟳ Saving voice settings…', 'info');
    try {
        const vFd = new FormData();
        vFd.append('action','update_podcast_voice');
        vFd.append('podcast_id', podcast_id);
        vFd.append('host_voice', hostVoice);
        vFd.append('guest_voice', hostVoice);
        vFd.append('rate', rate);
        vFd.append('admin_id',   <?= (int)$admin_id ?>);
        await fetch(S2_EP, { method:'POST', body:vFd, credentials:'include' });
        s2Log('✅ Voice: ' + hostVoice + ' @ ' + rate + 'x (' + elapsed() + ')', 'success');
    } catch(e) { s2Log('⚠ Voice save: ' + e.message, 'warning'); }

    // ── Fetch DB scene rows (get IDs + text) ───────────────
    s2Log('⟳ Loading scenes…', 'info');
    s2Log('📋 Fetching scenes from DB…', 'info');
    let dbScenes = [];
    try {
        const gFd = new FormData();
        gFd.append('action','get_scenes');
        gFd.append('podcast_id', podcast_id);
        gFd.append('admin_id',   <?= (int)$admin_id ?>);
        const gr = await fetch(S2_EP, { method:'POST', body:gFd, credentials:'include' });
        const gt = await gr.text();
        try { dbScenes = JSON.parse(gt); } catch(e) { s2Log('⚠ get_scenes parse: ' + gt.substring(0,100), 'warning'); }
        if (!Array.isArray(dbScenes)) dbScenes = [];
        s2Log('📋 ' + dbScenes.length + ' scenes loaded (' + elapsed() + ')', 'info');
    } catch(e) { s2Log('⚠ get_scenes: ' + e.message, 'warning'); }

    if (!dbScenes.length) {
        s2Log('❌ No scenes in DB — aborting', 'error');
        s2StepError(1, 'No scenes found in DB');
        
        
        return;
    }

    // ── Push nl_tags (stock_query) into each scene ─────────
    s2Log('🏷 Pushing search tags to scenes…', 'info');
    s2Log('⟳ Setting search tags…', 'info');
    await Promise.all(dbScenes.map((scene, i) => {
        const sq = S2.scenes[i]?.stock_query || scene.text_contents || '';
        const tFd = new FormData();
        tFd.append('action',    'update_scene_tags');
        tFd.append('scene_id',  scene.id);
        tFd.append('nl_tags',   sq);
        tFd.append('hashtags',  sq.replace(/\s+/g,' '));
        tFd.append('admin_id',   <?= (int)$admin_id ?>);
        return fetch(S2_EP, { method:'POST', body:tFd, credentials:'include' }).catch(() => {});
    }));
    s2Log('✅ Search tags set (' + elapsed() + ')', 'success');
    s2StepDone(1, '✓ Tags + voice ready');

    // ── Step 2: Audio (parallel) ───────────────────────────
    s2StepActive(2, 'Generating audio…');
    s2Log('⟳ Generating voiceover…', 'info');
    s2Log('🔊 Generating audio for ' + dbScenes.length + ' scenes in parallel… (' + elapsed() + ')', 'info');
    let audioDone = 0, audioFail = 0;
    // ── Audio: fire all in parallel, then mop up any failures ──
    const AUDIO_TIMEOUT = 10000; // 10s — if it doesn't respond, round 2 will catch it

    async function generateOneAudio(scene, i) {
        const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();
        if (!text) { audioDone++; return true; }

        const aFd = new FormData();
        aFd.append('action',     'generate_scene_audio');
        aFd.append('scene_id',   scene.id);
        aFd.append('podcast_id', podcast_id);
        aFd.append('seq_no',     i + 1);
        aFd.append('lang_code',  language);
        aFd.append('voice_id',   hostVoice);
        aFd.append('rate',       rate);
        aFd.append('text',       text);
        aFd.append('admin_id',   <?= (int)$admin_id ?>);

        try {
            const fetchP   = fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' }).then(r => r.json());
            const timeoutP = new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), AUDIO_TIMEOUT));
            const d = await Promise.race([fetchP, timeoutP]);
            if (d.success) {
                audioDone++;
                updateSceneBox(i, 'audio');
                s2Log('✓ Scene '+(i+1)+' audio ('+elapsed()+')', 'success');
                return true;
            }
            s2Log('⚠ Scene '+(i+1)+' audio: '+(d.error||'failed'), 'warning');
        } catch(e) {
            s2Log('⚠ Scene '+(i+1)+' audio: '+e.message, 'warning');
        }
        return false;
    }

    // Round 1: all scenes in parallel — runs alongside media search
    const audioResults = new Array(dbScenes.length).fill(false);
    const audioPromises = dbScenes.map((scene, i) =>
        generateOneAudio(scene, i).then(ok => { audioResults[i] = ok; })
    );

    // ── Step 3: Stock track — assign media | AI track — queue video generation ──
    let mediaDone = 0, mediaFail = 0;

    if (S2.track === 'ai') {
        // ── AI TRACK ──────────────────────────────────────────
        // Step 1: Save preview images to podcast_stories + podcast_images folder
        // Step 2: Queue video generation in hdb_image_gen_queue
        // Step 3: Show "generating" message — cron fires Kling

        s2StepActive(3, 'Saving images + queuing video generation…');
        s2Log('🎬 Saving preview images and queuing video generation…', 'info');

        const videoJobPromises = dbScenes.map(async (scene, i) => {
            const sceneData   = S2.scenes[i] || {};
            // Try all possible image URL fields
            const imageUrl    = sceneData.image_url || sceneData.preview_image || sceneData.image_path || '';
            const videoPrompt = sceneData.video_prompt || sceneData.image_prompt || 'slow gentle push in, cinematic';

            if (!imageUrl) {
                s2Log('⚠ Scene ' + (i+1) + ': preview not ready yet — queuing without image', 'warning');
                // Still insert into queue with prompt only — cron can generate from prompt
            }

            const vFd = new FormData();
            vFd.append('action',       'save_ai_scene_image');
            vFd.append('scene_id',     scene.id);
            vFd.append('podcast_id',   podcast_id);
            vFd.append('admin_id',     <?= (int)$admin_id ?>);
            vFd.append('company_id',   <?= (int)$company_id ?>);
            vFd.append('image_url',    imageUrl);
            vFd.append('video_prompt', videoPrompt);
            vFd.append('seq_no',       i + 1);
            vFd.append('gen_mode',     genMode);
            // fal.ai accepts 4, 6, or 8 seconds only — pick nearest valid value
            const rawDur = Math.round(duration / 7) || 6;
            const falDurs = [4, 6, 8];
            const sceneDur = falDurs.reduce((a,b) => Math.abs(b-rawDur) < Math.abs(a-rawDur) ? b : a);
            vFd.append('duration', sceneDur);

            try {
                const r = await fetch(S2_EP, { method:'POST', body:vFd, credentials:'include' });
                const d = await r.json();
                if (d.success) {
                    mediaDone++;
                    updateSceneBox(i, 'queued');
                    s2Log('✓ Scene '+(i+1)+': image saved + video queued (' + elapsed() + ')', 'success');
                } else {
                    mediaFail++;
                    updateSceneBox(i, 'error');
                    s2Log('✗ Scene '+(i+1)+': '+(d.error||'failed'), 'error');
                }
            } catch(e) {
                mediaFail++;
                updateSceneBox(i, 'error');
                s2Log('✗ Scene '+(i+1)+': '+e.message, 'error');
            }
        });

        await Promise.all([...audioPromises, ...videoJobPromises]);

        // Round 2 — retry failed audio after video jobs complete
        const audioFailedAI = dbScenes.filter((_, i) => !audioResults[i]);
        if (audioFailedAI.length > 0) {
            s2Log('↺ Retrying ' + audioFailedAI.length + ' audio(s)…', 'info');
            for (const scene of audioFailedAI) {
                const i = dbScenes.indexOf(scene);
                const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();
                if (!text) continue;
                const rFd = new FormData();
                rFd.append('action','generate_scene_audio'); rFd.append('scene_id',scene.id);
                rFd.append('podcast_id',podcast_id); rFd.append('seq_no',i+1);
                rFd.append('lang_code','en'); rFd.append('voice_id',hostVoice);
                rFd.append('rate',rate); rFd.append('text',text);
                rFd.append('admin_id',<?= (int)$admin_id ?>);
                try {
                    const fp = fetch(S2_EP,{method:'POST',body:rFd,credentials:'include'}).then(r=>r.json());
                    const tp = new Promise((_,rej)=>setTimeout(()=>rej(new Error('timeout')),55000));
                    const d  = await Promise.race([fp,tp]);
                    if (d.success) { audioDone++; audioResults[i]=true; updateSceneBox(i,'audio'); s2Log('✓ Scene '+(i+1)+' audio retry ('+elapsed()+')', 'success'); }
                    else { audioFail++; s2Log('✗ Scene '+(i+1)+' audio retry: '+(d.error||'?'), 'error'); }
                } catch(e) { audioFail++; s2Log('✗ Scene '+(i+1)+' audio retry: '+e.message, 'error'); }
            }
        }

        s2StepDone(2, '✓ ' + audioDone + ' audio');
        s2StepDone(3, '✓ ' + mediaDone + '/' + dbScenes.length + ' queued');

        
        
        document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id=' + podcast_id;

        // Trigger video generation cron — exits silently if already running
        s2Log('🚀 Triggering video generation cron…', 'info');
        fetch('cron_video_gen.php', { method:'GET', credentials:'include' })
            .then(r => r.text())
            .then(t => {
                s2Log('⚙️ Cron: ' + t.split('\n')[0], 'info');
                console.log('[Cron launched]', t);
            })
            .catch(e => {
                s2Log('⚠ Cron trigger failed: ' + e.message, 'warning');
                console.warn('[Cron] Could not trigger:', e.message);
            });

        // Poll cron status every 15 seconds
        startCronPolling(podcast_id);
        doneBar.style.borderColor = '#4fc3f7';
        const etaMin  = genMode === 'fast'
            ? (<?= $que_fal ?? 0 ?> + 14)
            : (<?= $que_modal ?? 3 ?> + Math.round(mediaDone * 5));
        const modeLabel = genMode === 'fast' ? '⚡ Fast Mode (Fal.ai)' : '💰 Smart Saver (Modal.com)';

        doneBar.innerHTML = `
            <div>
                <div style="font-size:14px;font-weight:700;color:#4fc3f7;">🎬 AI Videos Being Generated</div>
                <div style="font-size:12px;color:rgba(255,255,255,.7);margin-top:4px;">
                    ${mediaDone} scenes queued via ${modeLabel}<br>
                    Estimated wait: ~${etaMin} minutes${etaMin > 20 ? ' (queue is busy)' : ''}<br>
                    You can view with images now, or return when videos are ready.
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
                <a href="videomaker.php?podcast_id=${podcast_id}" style="font-size:13px;font-weight:700;color:#4fc3f7;text-decoration:none;padding:8px 14px;background:rgba(79,195,247,0.15);border:1px solid #4fc3f7;border-radius:8px;white-space:nowrap;">
                    View with Images →
                </a>
                <span style="font-size:11px;color:rgba(255,255,255,.5);">or return in ~${etaMin} min for videos</span>
            </div>`;

        s2Log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
        s2Log('🎉 Done! ' + mediaDone + '/' + dbScenes.length + ' scenes queued for video generation', 'success');
        s2Log('⏱ Total: ' + elapsed(), 'success');
        toast('✅ Videos queued — open editor or return in 15–20 min', 'success');
        dbg({ done: true, podcast_id, track: 'ai' });

    } else {
        // ── STOCK TRACK: search ALL scenes in one batch, then assign ──
        s2StepActive(3, 'Searching stock media…');
        s2Log('⟳ Searching stock media…', 'info');
        s2Log('🖼 Searching stock media (batch)…', 'info');

        // Build all scenes for batch search in one call
        const allSceneQueries = dbScenes.map((scene, i) => ({
            scene_idx: i,
            scene_id:  scene.id,
            nl_tags:   S2.scenes[i]?.stock_query || scene.natural_language_tags || scene.text_contents || '',
            query:     S2.scenes[i]?.stock_query || scene.natural_language_tags || scene.text_contents || '',
        }));

        const bFd = new FormData();
        bFd.append('action',     'search_images_batch');
        bFd.append('podcast_id', podcast_id);
        bFd.append('admin_id',   <?= (int)$admin_id ?>);
        bFd.append('slots',      3); // get top 3 candidates per scene for dedup
        bFd.append('media_type', 'stock_videos');
        bFd.append('scenes',     JSON.stringify(allSceneQueries));

        let batchResults = [];
        try {
            s2Log('⟳ Searching (calling AI embeddings)…', 'info');
            const br   = await fetch(S2_EP, { method:'POST', body:bFd, credentials:'include' });
            const bRes = await br.json();
            batchResults = bRes.results || [];
            if (bRes._stats) {
                console.log('[Search Stats]', {
                    promo_group:     bRes._stats.promo_group,
                    tier1_rows:      bRes._stats.tier1_rows,
                    tier2_rows:      bRes._stats.tier2_rows,
                    total_rows:      bRes._stats.total_rows,
                    load_ms:         bRes._stats.load_ms + 'ms',
                    search_ms:       bRes._stats.search_ms + 'ms',
                    total_ms:        bRes._stats.total_ms + 'ms',
                    embedding_calls: bRes._stats.embedding_calls,
                });
            }
            s2Log('🔍 Search: ' + batchResults.length + ' scenes matched — '
                + (bRes._stats?.total_rows||'?') + ' assets ('
                + '🏢 ' + (bRes._stats?.tier1_rows||0) + ' company + '
                + '📦 ' + (bRes._stats?.tier2_rows||0) + ' stock) in '
                + (bRes._stats?.total_ms||'?') + 'ms', 'success');
        } catch(e) {
            s2Log('✗ Batch search failed: ' + e.message, 'error');
        }

        // Assign sequentially with dedup (search already done — just DB writes now)
        const usedFilenames = new Set();
        for (let i = 0; i < dbScenes.length; i++) {
            if (s2PromoCancelled) break;
            const scene   = dbScenes[i];
            const nlTags  = allSceneQueries[i]?.query || '';
            const found   = (batchResults.find(r => r.scene_idx === i)?.found || []);
            document.getElementById('s2ProcessingStep').textContent = 'Assigning ' + (i+1) + '/' + dbScenes.length;

            if (found.length > 0) {
                const best = found.find(f => !usedFilenames.has(f.filename)) || found[0];
                usedFilenames.add(best.filename);
                try {
                    const aFd = new FormData();
                    aFd.append('action','assign_image');
                    aFd.append('scene_id',      scene.id);
                    aFd.append('podcast_id',    podcast_id);
                    aFd.append('admin_id',      <?= (int)$admin_id ?>);
                    aFd.append('filename',      best.filename);
                    aFd.append('image_folder',  best.image_folder || '');
                    aFd.append('image_field',   'image_file');
                    aFd.append('media_type',    best.type || 'video');
                    aFd.append('search_query',  nlTags);
                    aFd.append('similarity_score', best.score || 0);
                    aFd.append('match_rank',    best.rank || 1);
                    aFd.append('matched_terms', best.matched_terms || '[]');
                    await fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' });
                    mediaDone++;
                    updateSceneBox(i, 'done', best.filename, best.image_folder || '');
                    const tierLabel = best.tier === 1 ? '🏢 company' : '📦 stock';
                    const typeLabel = (best.media_type||'').toLowerCase().includes('video') ? '🎬' : '🖼';
                    s2Log('✓ Scene '+(i+1)+': '+typeLabel+' '+tierLabel+' '+best.filename+' ('+elapsed()+')', 'success');
                } catch(e) {
                    mediaFail++;
                    updateSceneBox(i, 'error');
                    s2Log('✗ Scene '+(i+1)+' assign: '+e.message, 'error');
                }
            } else {
                mediaFail++;
                updateSceneBox(i, 'error');
                s2Log('⚠ Scene '+(i+1)+': no match for "'+nlTags.substring(0,40)+'"', 'warning');
            }
        }

        await Promise.all(audioPromises);

        // Rounds 2-4 — retry any timed-out audio after media assigned
        // Each round: 10s timeout, parallel, up to 3 extra rounds
        const MAX_AUDIO_ROUNDS = 3;
        for (let round = 2; round <= MAX_AUDIO_ROUNDS + 1; round++) {
            const stillFailed = dbScenes.filter((_, i) => !audioResults[i]);
            if (stillFailed.length === 0) break;
            s2Log('↺ Audio round ' + round + ': retrying ' + stillFailed.length + ' scene(s)… (' + elapsed() + ')', 'info');
            await Promise.all(stillFailed.map(async (scene) => {
                const i = dbScenes.indexOf(scene);
                const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();
                if (!text) { audioResults[i] = true; return; }
                const rFd = new FormData();
                rFd.append('action','generate_scene_audio'); rFd.append('scene_id',scene.id);
                rFd.append('podcast_id',podcast_id); rFd.append('seq_no',i+1);
                rFd.append('lang_code','en'); rFd.append('voice_id',hostVoice);
                rFd.append('rate',rate); rFd.append('text',text);
                rFd.append('admin_id',<?= (int)$admin_id ?>);
                try {
                    const fp = fetch(S2_EP,{method:'POST',body:rFd,credentials:'include'}).then(r=>r.json());
                    const tp = new Promise((_,rej)=>setTimeout(()=>rej(new Error('timeout')),10000));
                    const d  = await Promise.race([fp,tp]);
                    if (d.success) {
                        audioDone++; audioResults[i] = true;
                        updateSceneBox(i,'audio');
                        s2Log('✓ Scene '+(i+1)+' audio round '+round+' ('+elapsed()+')', 'success');
                    } else {
                        s2Log('⚠ Scene '+(i+1)+' audio round '+round+': '+(d.error||'failed'), 'warning');
                    }
                } catch(e) {
                    s2Log('⚠ Scene '+(i+1)+' audio round '+round+': '+e.message, 'warning');
                }
            }));
        }
        // Mark any still-failed as audioFail
        dbScenes.forEach((_, i) => { if (!audioResults[i]) audioFail++; });

        s2StepDone(2, '✓ ' + audioDone + ' audio' + (audioFail > 0 ? ' ('+audioFail+' failed)' : ''));
        s2StepDone(3, '✓ ' + mediaDone + ' media' + (mediaFail > 0 ? ' ('+mediaFail+' skipped)' : ''));

        
        
        document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id=' + podcast_id;
        const doneBar = document.getElementById('s2DoneBarGrid');
        doneBar.style.display = 'flex';
        s2Log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
        s2Log('🎉 Done! Podcast #' + podcast_id + ' — ' + mediaDone + '/' + dbScenes.length + ' scenes OK', 'success');
        s2Log('⏱ Total: ' + elapsed(), 'success');
        toast('✅ Video ready!', 'success');
        dbg({ done: true, podcast_id, track: 'stock' });
    }
}
let S2 = { brief_id: 0, track: '', scenes: [], podcast_id: 0, needs_model: false, selected_model: null };

const SCENE_TYPES = ['hook','problem','product','feature','lifestyle','social','cta'];
const SCENE_LABELS = { hook:'Hook', problem:'Problem', product:'Product', feature:'Feature', lifestyle:'Lifestyle', social:'Social Proof', cta:'Call to Action', detail:'Detail', elegance:'Elegance', dynamic:'Dynamic' };

async function launchStage2(brief_id, track) {
    S2.brief_id    = brief_id;
    S2.track       = track;
    S2.needs_model = (track === 'ai' && S.needs_model);

    // AI track + wearable category → show model picker first
    if (S2.needs_model) {
        showStep('model-picker');
        await loadModelPicker();
    } else {
        showStep('storyboard');
        await generateScenePlan();
    }
}

// ── Model Picker ────────────────────────────────────────────
let _allModels = [];
let _activeModelCategory = '';

async function loadModelPicker() {
    document.getElementById('model-loading').style.display = '';
    document.getElementById('model-grid').style.display    = 'none';
    document.getElementById('model-picker-actions').style.display = 'none';

    const fd = new FormData();
    fd.append('ajax_action', 'get_promo_models');
    fd.append('sub_category', S.sub_category || '');

    try {
        const r = await fetch('', { method:'POST', body:fd });
        const d = await r.json();
        document.getElementById('model-loading').style.display = 'none';

        if (!d.success || !d.models.length) {
            document.getElementById('model-loading').style.display = '';
            document.getElementById('model-loading').innerHTML = '<p style="color:var(--muted)">No models found. <a href="#" onclick="skipModelPicker()">Skip and continue →</a></p>';
            return;
        }

        _allModels = d.models;
        _activeModelCategory = d.default_category || d.models[0].category;

        renderModelFilterChips(d.categories);
        renderModelGrid(_activeModelCategory);
        document.getElementById('model-picker-actions').style.display = '';

    } catch(e) {
        document.getElementById('model-loading').innerHTML = '<p style="color:var(--red)">Error: ' + e.message + ' <a href="#" onclick="skipModelPicker()">Skip →</a></p>';
    }
}

function renderModelFilterChips(categories) {
    const wrap = document.getElementById('model-filter-chips');
    wrap.innerHTML = '';
    categories.forEach(cat => {
        const c = document.createElement('div');
        c.className = 'chip' + (cat.key === _activeModelCategory ? ' active' : '');
        c.textContent = cat.label;
        c.onclick = () => {
            _activeModelCategory = cat.key;
            document.querySelectorAll('#model-filter-chips .chip').forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            renderModelGrid(cat.key);
        };
        wrap.appendChild(c);
    });
}

function renderModelGrid(category) {
    const grid   = document.getElementById('model-grid');
    // Show base pose only: _01.jpg OR _pose_p1.jpg (first pose variant)
    const models = _allModels.filter(m => m.category === category && 
        (m.filename.match(/_01\.(jpg|jpeg|png|webp)$/i) || m.filename.match(/_pose_p1\.(jpg|jpeg|png|webp)$/i)));
    grid.innerHTML = '';
    grid.style.display = 'grid';

    models.forEach(m => {
        const card = document.createElement('div');
        card.className = 'model-card' + (S2.selected_model?.id === m.id ? ' selected' : '');
        card.innerHTML = `
            <img src="promo_models/thumbnails/${m.filename}"
                 onerror="this.src='promo_models/${m.category}/${m.filename}'"
                 loading="lazy" alt="${m.description || m.filename}">
            <div class="model-card-label">${m.ethnicity ? m.ethnicity.replace('_',' ') : ''}</div>
            <div style="font-size:9px;color:var(--muted);padding:0 6px 4px;text-align:center;">#${m.id}</div>
            <div class="model-check">✓</div>`;
        card.onclick = () => selectModel(m, card);
        grid.appendChild(card);
    });
}

function selectModel(model, cardEl) {
    // Deselect all
    document.querySelectorAll('.model-card').forEach(c => c.classList.remove('selected'));
    cardEl.classList.add('selected');
    S2.selected_model = model;
    document.getElementById('btn-model-continue').disabled = false;
    dbg({ selected_model: model });
}

function skipModelPicker() {
    S2.selected_model = null;
    showStep('storyboard');
    generateScenePlan();
}

async function modelPickerContinue() {
    if (!S2.selected_model) { toast('Please select a model first', 'error'); return; }
    showStep('storyboard');
    await generateScenePlan();
}

async function generateScenePlan() {
    document.getElementById('s2-generating').style.display = '';
    document.getElementById('scene-grid').innerHTML = '';
    document.getElementById('s2-action-bar').style.display = 'none';

    const fd = new FormData();
    fd.append('ajax_action', 'generate_scene_plan');
    fd.append('brief_id',    S2.brief_id);
    fd.append('track',       S2.track);
    fd.append('needs_model', S2.needs_model ? '1' : '0');
    fd.append('duration',    _promoDuration || 30);
    fd.append('language',    S.language || document.getElementById('s2Language')?.value || 'en');
    fd.append('target_location', document.getElementById('input-target-location')?.value.trim() || '');
    fd.append('audience_detail', document.getElementById('input-audience-detail')?.value.trim() || '');
    fd.append('product_image_path', S.product_image_path || '');

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        document.getElementById('s2-generating').style.display = 'none';

        if (!d.success) {
            console.error('Scene generation failed:', d.error, d.raw || '');
            s2Log('❌ Scene generation failed: ' + (d.error || 'Unknown'), 'error');
            document.getElementById('s2-generating').style.display = 'none';
            document.getElementById('s2-gen-status').textContent = '❌ ' + (d.error || 'Failed — check console');
            return;
        }

        S2.scenes = d.scenes;
        renderScenes(S2.scenes);
    } catch(e) {
        document.getElementById('s2-generating').style.display = 'none';
        console.error('Scene plan network error:', e);
        s2Log('❌ Network error: ' + e.message, 'error');
    }
}

function renderScenes(scenes) {
    const grid = document.getElementById('scene-grid');
    grid.className = 'scene-grid';
    grid.innerHTML = '';
    const isAI = S2.track === 'ai';

    scenes.forEach((sc, i) => {
        const typeKey  = sc.scene_type || 'feature';
        const badgeCls = 'badge-' + typeKey;
        const label    = SCENE_LABELS[typeKey] || typeKey;

        const card = document.createElement('div');
        card.className = 'scene-card';
        card.id        = 'scene-card-' + i;
        card.dataset.sceneIndex = i;

        if (isAI) {
            card.innerHTML = `
                <div class="scene-card-head">
                    <span class="scene-num">Scene ${i + 1}</span>
                    <span class="scene-type-badge ${badgeCls}">${label}</span>
                </div>
                <div class="scene-img-wrap" id="scene-img-wrap-${i}">
                    <div class="scene-img-loading" id="scene-img-placeholder-${i}">
                        <span class="spin" style="width:22px;height:22px;border-width:2px;border-color:rgba(15,42,68,.15);border-top-color:var(--dark-blue);"></span>
                        <span style="font-size:10px;color:var(--muted);">Generating preview…</span>
                    </div>
                    <div class="scene-img-actions" id="scene-img-actions-${i}" style="display:none;">
                        <button class="scene-img-btn" onclick="openFeedback(${i})">✏️ Give Feedback</button>
                        <button class="scene-img-btn" onclick="regenScene(${i})">↺ Regenerate</button>
                    </div>
                </div>
                <div class="scene-feedback-panel" id="scene-feedback-${i}">
                    <div style="font-size:11px;font-weight:700;color:var(--purple);margin-bottom:6px;">💬 What to change?</div>
                    <textarea id="scene-feedback-text-${i}" placeholder="${S2.needs_model ? 'Change background/setting only (e.g. outdoor garden, marble interior, bright lighting). Garment style comes from your product image.' : 'e.g. more elegant, outdoor garden background, brighter lighting, add people…'}" rows="3"></textarea>
                    <div class="scene-feedback-actions">
                        <button class="btn-regen" id="scene-regen-btn-${i}" onclick="submitFeedback(${i})">
                            ↺ Regenerate with feedback
                        </button>
                        <button class="btn-cancel-fb" onclick="closeFeedback(${i})">Cancel</button>
                    </div>
                </div>
                <div class="scene-card-body">
                    <textarea class="scene-caption" data-idx="${i}" rows="2">${sc.caption || ''}</textarea>
                    <div class="scene-dur">⏱ ${sc.duration_sec || 4}s</div>
                </div>`;
        } else {
            card.innerHTML = `
                <div class="scene-card-head">
                    <span class="scene-num">Scene ${i + 1}</span>
                    <span class="scene-type-badge ${badgeCls}">${label}</span>
                </div>
                <div class="scene-card-body">
                    <div class="scene-stock-query">🔍 Stock: <span>${sc.stock_query || ''}</span></div>
                    <div class="scene-caption-wrap">
                        <textarea class="scene-caption" data-idx="${i}" rows="3">${sc.caption || ''}</textarea>
                    </div>
                    <div class="scene-dur">⏱ ${sc.duration_sec || 4}s</div>
                </div>`;
        }

        card.querySelector('.scene-caption').addEventListener('input', function() {
            S2.scenes[i].caption = this.value;
        });

        grid.appendChild(card);
    });

    const sceneCountEl = document.getElementById('s2-scene-count');
    if (sceneCountEl) sceneCountEl.textContent = scenes.length + ' scenes · ' + (_promoDuration||30) + ' seconds';
    document.getElementById('s2-action-bar').style.display = '';
    dbg({ stage: 2, scenes, track: S2.track });

    // AI track — generate preview images in parallel immediately
    if (isAI) {
        let previewsDone = 0;
        const totalPreviews = scenes.length;
        const approveBtn = document.getElementById('btn-approve');
        if (approveBtn) {
            approveBtn.disabled = true;
            approveBtn.textContent = '⏳ Generating previews 0/' + totalPreviews + '…';
        }

        scenes.forEach((sc, i) => {
            generatePreviewImage(sc, i).then(() => {
                previewsDone++;
                if (approveBtn) {
                    if (previewsDone < totalPreviews) {
                        approveBtn.textContent = '⏳ Generating previews ' + previewsDone + '/' + totalPreviews + '…';
                    } else {
                        approveBtn.disabled = false;
                        approveBtn.textContent = '✓ Approve & Build →';
                        toast('✅ All previews ready — review and approve!', 'success');
                    }
                }
            });
        });
    }
}


// ── Generate AI preview image for one scene card ──────────
async function generatePreviewImage(sc, i) {
    const prompt = sc.image_prompt || sc.stock_query || sc.caption || '';
    if (!prompt) {
        document.getElementById('scene-img-placeholder-' + i).innerHTML =
            '<span style="font-size:10px;color:var(--muted);">No prompt</span>';
        return;
    }

    const fd = new FormData();
    fd.append('action',       'generate_preview_image');
    fd.append('prompt',       prompt);
    fd.append('scene_index',  i);
    fd.append('brief_id',     S2.brief_id);
    fd.append('company_id',   <?= (int)$company_id ?>);
    fd.append('admin_id',     <?= (int)$admin_id ?>);
    fd.append('needs_model',  S2.needs_model ? '1' : '0');
    fd.append('model_file',   S2.selected_model?.filename || '');
    fd.append('model_cat',    S2.selected_model?.category || '');
    fd.append('model_index',  i); // used to rotate through poses
    // Pass garment image path if we have one (set during upload)
    fd.append('garment_path', S.product_image_path || window._garmentImagePath || '');

    console.log('[Preview] Scene', i, {
        needs_model: S2.needs_model,
        model: S2.selected_model?.filename,
        garment: S.product_image_path,
        prompt: prompt.substring(0, 80)
    });

    try {
        const r = await fetch('promo_step2.php', { method:'POST', body:fd, credentials:'include' });
        const d = await r.json();

        const wrap  = document.getElementById('scene-img-wrap-' + i);
        const phld  = document.getElementById('scene-img-placeholder-' + i);

        if (d.success && d.image_url) {
            // Log debug info to console so we can diagnose FASHN issues
            if (d._debug) console.log('[Scene ' + i + ' debug]', d._debug, 'type:', d.type);
            // Replace spinner with generated image
            const img    = document.createElement('img');
            img.src      = d.image_url;
            img.alt      = 'Scene ' + (i + 1) + ' preview';
            img.onload   = () => {
                if (phld) phld.style.display = 'none';
                // Show hover action buttons
                const acts = document.getElementById('scene-img-actions-' + i);
                if (acts) acts.style.display = '';
            };
            wrap.insertBefore(img, wrap.firstChild);
            // Store image path in scene data for save_storyboard
            S2.scenes[i].preview_image = d.image_path || d.image_url;
            S2.scenes[i].image_url     = d.image_path || d.image_url;
        } else {
            if (phld) phld.innerHTML = '<span style="font-size:11px;color:var(--red);text-align:center;padding:8px;">⚠ ' + (d.error || 'Generation failed') + '</span>';
        }
    } catch(e) {
        const phld = document.getElementById('scene-img-placeholder-' + i);
        if (phld) phld.innerHTML = '<span style="font-size:10px;color:var(--red);">⚠ ' + e.message + '</span>';
    }
}



// ── Scene feedback & regeneration ─────────────────────────
function openFeedback(i) {
    const panel = document.getElementById('scene-feedback-' + i);
    if (panel) {
        panel.classList.add('open');
        const ta = document.getElementById('scene-feedback-text-' + i);
        if (ta) ta.focus();
    }
}
function closeFeedback(i) {
    const panel = document.getElementById('scene-feedback-' + i);
    if (panel) panel.classList.remove('open');
}

async function regenScene(i) {
    // Quick regenerate without feedback — just re-run with original prompt
    closeFeedback(i);
    const wrap = document.getElementById('scene-img-wrap-' + i);
    const acts = document.getElementById('scene-img-actions-' + i);
    const phld = document.getElementById('scene-img-placeholder-' + i);
    // Show spinner again
    if (phld) { phld.style.display = ''; phld.innerHTML = '<span class="spin" style="width:22px;height:22px;border-width:2px;border-color:rgba(15,42,68,.15);border-top-color:var(--dark-blue);"></span><span style="font-size:10px;color:var(--muted);margin-top:4px;">Regenerating…</span>'; }
    if (acts) acts.style.display = 'none';
    // Remove old image
    wrap.querySelectorAll('img').forEach(el => el.remove());
    await generatePreviewImage(S2.scenes[i], i);
}

async function submitFeedback(i) {
    const feedbackText = (document.getElementById('scene-feedback-text-' + i)?.value || '').trim();
    if (!feedbackText) { toast('Please enter your feedback first', 'error'); return; }

    const btn = document.getElementById('scene-regen-btn-' + i);
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin" style="width:12px;height:12px;border-width:2px;"></span> Updating…'; }

    // Ask GPT to refine the image_prompt based on feedback
    const fd = new FormData();
    fd.append('ajax_action',    'refine_scene_prompt');
    fd.append('scene_index',    i);
    fd.append('original_prompt', S2.scenes[i].image_prompt || S2.scenes[i].stock_query || '');
    fd.append('caption',        S2.scenes[i].caption || '');
    fd.append('feedback',       feedbackText);
    fd.append('track',          S2.track);
    fd.append('needs_model',    S2.needs_model ? '1' : '0');

    try {
        const r = await fetch('', { method:'POST', body:fd });
        const d = await r.json();

        if (d.success && d.new_prompt) {
            // Update scene data with refined prompt
            S2.scenes[i].image_prompt = d.new_prompt;
            S2.scenes[i].video_prompt = d.video_prompt || S2.scenes[i].video_prompt;

            // Close feedback panel
            closeFeedback(i);
            if (document.getElementById('scene-feedback-text-' + i))
                document.getElementById('scene-feedback-text-' + i).value = '';

            // Regenerate the image with new prompt
            const wrap = document.getElementById('scene-img-wrap-' + i);
            const acts = document.getElementById('scene-img-actions-' + i);
            const phld = document.getElementById('scene-img-placeholder-' + i);
            if (phld) { phld.style.display = ''; phld.innerHTML = '<span class="spin" style="width:22px;height:22px;border-width:2px;border-color:rgba(15,42,68,.15);border-top-color:var(--dark-blue);"></span><span style="font-size:10px;color:var(--muted);margin-top:4px;">Applying feedback…</span>'; }
            if (acts) acts.style.display = 'none';
            wrap.querySelectorAll('img').forEach(el => el.remove());

            await generatePreviewImage(S2.scenes[i], i);
            toast('✓ Scene ' + (i+1) + ' updated', 'success');
        } else {
            toast('Could not refine prompt: ' + (d.error || 'Unknown'), 'error');
        }
    } catch(e) {
        toast('Error: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '↺ Regenerate with feedback'; }
    }
}


async function regenerateScenes() {
    document.getElementById('s2-generating').style.display = '';
    document.getElementById('s2-action-bar').style.display = 'none';
    await generateScenePlan();
}


// ── Cron status polling ────────────────────────────────────
let _cronPollTimer = null;
let _shownVideos   = new Set();

function startCronPolling(podcast_id) {
    if (_cronPollTimer) clearInterval(_cronPollTimer);

    // Show video cards section immediately
    const cardsSection = document.getElementById('s2VideoCards');
    if (cardsSection) cardsSection.style.display = '';

    _cronPollTimer = setInterval(async () => {
        try {
            const r = await fetch('cron_video_gen.php?status=1&podcast_id=' + podcast_id, { credentials:'include' });
            const d = await r.json();

            const done  = parseInt(d.podcast?.done  || 0);
            const total = parseInt(d.podcast?.total || 7);
            const pct   = total > 0 ? Math.round((done / total) * 100) : 0;

            // Update progress text
            const progEl = document.getElementById('s2VideoProgress');
            if (progEl) {
                if (d.cron_running) {
                    progEl.textContent = `⚙️ Generating… ${done}/${total} videos ready`;
                } else if (done === total && total > 0) {
                    progEl.textContent = `✅ All ${total} videos ready!`;
                } else {
                    progEl.textContent = `${done}/${total} videos ready`;
                }
            }

            // Update editor link
            const edBtn = document.getElementById('s2VideoEditorBtn');
            if (edBtn) edBtn.href = 'videomaker.php?podcast_id=' + podcast_id;

            // Add log lines
            if (d.log?.length) {
                const log = document.getElementById('s2Log');
                if (log) {
                    d.log.forEach(line => {
                        if (!log.innerHTML.includes(line.substring(20, 50))) {
                            const p = document.createElement('p');
                            p.className = 's2-log-line ' + (line.includes('ERROR') ? 'error' : line.includes('SUCCESS') ? 'success' : 'info');
                            p.textContent = line.replace(/^\[.*?\]\s*/, '');
                            log.appendChild(p);
                        }
                    });
                    log.scrollTop = log.scrollHeight;
                }
            }

            // Fetch completed video scenes and show cards
            if (done > 0) fetchVideoCards(podcast_id);

            // All done
            if (done === total && total > 0) {
                clearInterval(_cronPollTimer);
                toast('🎬 All AI videos are ready!', 'success');
                fetchVideoCards(podcast_id);
            }

            console.log('[CronPoll]', { running: d.cron_running, done, total });

        } catch(e) {
            console.warn('[CronPoll] error:', e.message);
        }
    }, 15000);

    // First poll immediately
    setTimeout(() => {
        fetch('cron_video_gen.php?status=1&podcast_id=' + podcast_id, { credentials:'include' })
            .then(r => r.json()).then(d => {
                const done  = parseInt(d.podcast?.done  || 0);
                const progEl = document.getElementById('s2VideoProgress');
                if (progEl) progEl.textContent = `${done} videos generating in background…`;
            }).catch(() => {});
    }, 3000);
}

async function fetchVideoCards(podcast_id) {
    try {
        const fd = new FormData();
        fd.append('action',     'get_scenes');
        fd.append('podcast_id', podcast_id);
        fd.append('admin_id',   <?= (int)$admin_id ?>);
        const r  = await fetch('promo_step2.php', { method:'POST', body:fd, credentials:'include' });
        const scenes = await r.json();
        if (!Array.isArray(scenes)) return;

        const grid = document.getElementById('s2VideoCardsGrid');
        if (!grid) return;

        scenes.forEach((scene, i) => {
            const hasVideo = scene.videogen_flag == 3 && scene.image_file;
            const cardId   = 'vcard-' + scene.id;
            if (_shownVideos.has(cardId) && !hasVideo) return;

            let existing = document.getElementById(cardId);
            if (!existing) {
                existing = document.createElement('div');
                existing.id = cardId;
                existing.style.cssText = 'background:var(--card);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;aspect-ratio:9/16;position:relative;';
                grid.appendChild(existing);
            }

            if (hasVideo) {
                const folder = scene.image_folder || 'podcast_videos';
                const src    = folder + '/' + scene.image_file;
                const isVid  = /\.mp4|\.webm/i.test(scene.image_file);
                existing.style.borderColor = '#22c55e';
                existing.innerHTML = isVid
                    ? `<video src="${src}" style="width:100%;height:100%;object-fit:cover;" muted playsinline loop autoplay></video>
                       <div style="position:absolute;bottom:4px;left:0;right:0;text-align:center;font-size:9px;color:#fff;background:rgba(0,0,0,.5);padding:2px;">Scene ${i+1} ✓</div>`
                    : `<img src="${src}" style="width:100%;height:100%;object-fit:cover;">
                       <div style="position:absolute;bottom:4px;left:0;right:0;text-align:center;font-size:9px;color:#fff;background:rgba(0,0,0,.5);padding:2px;">Scene ${i+1}</div>`;
                _shownVideos.add(cardId);
            } else {
                existing.innerHTML = `
                    <div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;background:#0f2a44;">
                        <span class="spin" style="width:16px;height:16px;border-width:2px;border-color:rgba(255,255,255,.2);border-top-color:#4fc3f7;"></span>
                        <span style="font-size:9px;color:rgba(255,255,255,.5);">Scene ${i+1}</span>
                    </div>`;
            }
        });
    } catch(e) {
        console.warn('[VideoCards]', e.message);
    }
}

// ── Init ───────────────────────────────────────────────────
loadCategories();

<?php if ($is_debug): ?>
console.log('%c PromoGen Stage 1 v2 ', 'background:#10b981;color:#fff;font-weight:bold;padding:2px 8px;border-radius:4px;');
console.log('Company:', <?= json_encode($co_name) ?>, '| Brand:', <?= json_encode($co_brand) ?>);
console.log('Credits:', <?= $user_credit_balance ?>, '| AI:', <?= $credits_ai ?>, '| Stock:', <?= $credits_stock ?>);
console.log('Admin ID:', <?= $admin_id ?>, '| Company ID:', <?= $company_id ?>);
<?php endif; ?>
</script>
</body>
</html>
