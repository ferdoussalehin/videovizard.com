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
$falApiKey = $falApiKey ?? $fal_api_key ?? $fal_key ?? null;
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
        "SELECT companyname, description, brand_name, logo_file, website, phone, address
         FROM hdb_companies WHERE id=$company_id AND admin_id=$admin_id LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
if (!$company) {
    $co_res = mysqli_query($conn,
        "SELECT companyname, description, brand_name, logo_file, website, phone, address
         FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
    if ($co_res) $company = mysqli_fetch_assoc($co_res);
}
$co_name  = htmlspecialchars($company['companyname'] ?? '');
$co_brand = htmlspecialchars(!empty($company['brand_name']) ? $company['brand_name'] : ($company['companyname'] ?? ''));
$co_desc  = htmlspecialchars($company['description'] ?? '');
$co_logo  = htmlspecialchars($company['logo_file']   ?? '');
$co_phone = htmlspecialchars($company['phone']       ?? '');
$co_web   = htmlspecialchars($company['website']     ?? '');

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
}

promoSetupDB($conn);

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
    $urgency_text   = mysqli_real_escape_string($conn, trim($_POST['urgency_text']   ?? ''));
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
            cta_contact='$cta_contact', extra_fields='$extra_json',
            status='draft', updated_at=NOW()
            WHERE id=$brief_id AND admin_id=$admin_id");
    } else {
        $ok = mysqli_query($conn, "INSERT INTO hdb_promo_briefs
            (admin_id, company_id, company_name, brand_name, category_key, sub_category,
             product_name, key_features, target_audience, offer_text, highlight,
             price_text, urgency_text, cta_action, cta_contact, extra_fields, status)
            VALUES
            ($admin_id, $company_id, '$company_name_esc', '$brand_name_esc',
             '$category_key', '$sub_category', '$product_name', '$key_features',
             '$target_audience', '$offer_text', '$highlight',
             '$price_text', '$urgency_text', '$cta_action', '$cta_contact',
             '$extra_json', 'draft')");
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
    ob_clean(); header('Content-Type: application/json');
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
    $saveDir = 'product_images/promo4';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);
    $savePath = $saveDir . '/p4_' . time() . '_' . session_id() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $savePath)) {
        echo json_encode(['success'=>false,'error'=>'Failed to save file']); exit;
    }
    $_SESSION['product_image_path'] = $savePath;
    $_SESSION['product_image_name'] = htmlspecialchars($file['name']);

    // GPT-4o vision auto-description
    $autoDesc = null;
    $imgData  = file_get_contents($savePath);
    if ($imgData && $apiKey) {
        $b64  = base64_encode($imgData);
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
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
            CURLOPT_HTTPHEADER=>["Content-Type: application/json","Authorization: Bearer $apiKey"],
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(["model"=>"gpt-4o-mini","messages"=>$messages,"max_tokens"=>200,"temperature"=>0.5]),
        ]);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http===200) {
            $j = json_decode($res, true);
            $autoDesc = $j["choices"][0]["message"]["content"] ?? null;
            if ($autoDesc) $_SESSION['product_auto_desc'] = $autoDesc;
        }
    }
    promoLog("Image uploaded: $savePath auto_desc=".($autoDesc?'yes':'no'));
    echo json_encode(['success'=>true,'path'=>$savePath,'name'=>$file['name'],'auto_desc'=>$autoDesc]);
    exit;
}

// ── AJAX: clear_product_image ──────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'clear_product_image') {
    ob_clean(); header('Content-Type: application/json');
    $path = $_SESSION['product_image_path'] ?? null;
    if ($path && file_exists($path)) @unlink($path);
    unset($_SESSION['product_image_path'], $_SESSION['product_image_name'], $_SESSION['product_auto_desc']);
    echo json_encode(['success'=>true]);
    exit;
}

// ══════════════════════════════════════════════════════════
// AJAX: generate_scene_plan
// Reads brief from DB, asks GPT to plan 7 scenes with
// captions + stock search queries
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_plan') {
    ob_clean(); header('Content-Type: application/json');
    ini_set('display_errors', 0);
    set_time_limit(90);

    try {

    $brief_id = (int)($_POST['brief_id'] ?? 0);
    $track    = trim($_POST['track'] ?? 'stock');

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
    $urgency  = $brief['urgency_text']    ?? '';
    $cta_act  = $brief['cta_action']      ?? 'whatsapp';
    $cta_con  = $brief['cta_contact']     ?? $co_phone;
    $category = $brief['category_key']    ?? '';
    $sub_cat  = $brief['sub_category']    ?? '';

    $cta_labels = ['whatsapp'=>'WhatsApp us','call'=>'Call us','dm'=>'DM on Instagram',
                   'visit'=>'Visit our store','book'=>'Book online','order'=>'Order now','website'=>'Visit our website'];
    $cta_text = $cta_labels[$cta_act] ?? $cta_act;
    if ($cta_con) $cta_text .= ' — ' . $cta_con;

    $system = "You are a video director and copywriter for short-form social media promotional videos.
Create a 7-scene, 30-second storyboard for a stock-footage promotional video.

Scene arc (use exactly in this order):
1. hook       — Attention-grabbing opening (2-3 seconds)
2. problem    — The pain point or desire the product solves (4 seconds)
3. product    — Product/service reveal (5 seconds)
4. feature    — Key feature or benefit (4 seconds)
5. lifestyle  — Target customer enjoying the result (5 seconds)
6. social     — Trust signal or proof (5 seconds)
7. cta        — Call to action close (4 seconds)

For each scene return:
- scene_type: one of: hook, problem, product, feature, lifestyle, social, cta
- caption: short punchy on-screen text (max 8 words, no hashtags)
- stock_query: 3-5 word search query for a stock footage library (concrete, visual, no brand names)
- duration_sec: integer seconds (must total 30)

Return ONLY a valid JSON array of 7 objects. No markdown, no explanation.
Example: [{\"scene_type\":\"hook\",\"caption\":\"caption here\",\"stock_query\":\"woman opening gift box\",\"duration_sec\":3}]";

    $user = "Product/Service: $product
Brand: $brand
Category: $category" . ($sub_cat ? " — $sub_cat" : '') . "
Key features: $features
Target audience: $audience
Offer details: $offer" .
($highlight ? "\nHighlight: $highlight" : '') .
($price     ? "\nPrice: $price"         : '') .
($urgency   ? "\nUrgency: $urgency"     : '') . "
Call to action: $cta_text";

    $raw = promoCallAI($apiUrl, $apiKey, $system, $user, 0.82, 60);

    if (!$raw) { echo json_encode(['success'=>false,'error'=>'AI did not respond. Check API key.']); exit; }

    // Strip markdown fences
    $clean  = trim(preg_replace('/^```json\s*|\s*```$/s', '', $raw));
    $scenes = json_decode($clean, true);

    if (!is_array($scenes) || count($scenes) < 3) {
        promoLog("scene_plan parse fail: " . substr($raw, 0, 300), 'ERROR');
        echo json_encode(['success'=>false,'error'=>'Could not parse scenes','raw'=>substr($raw,0,400)]); exit;
    }

    // Ensure 7 scenes with required keys
    $out = [];
    $types = ['hook','problem','product','feature','lifestyle','social','cta'];
    foreach ($scenes as $i => $sc) {
        $out[] = [
            'seq'          => $i + 1,
            'scene_type'   => $sc['scene_type']   ?? ($types[$i] ?? 'feature'),
            'caption'      => trim($sc['caption']  ?? ''),
            'stock_query'  => trim($sc['stock_query'] ?? ''),
            'duration_sec' => (int)($sc['duration_sec'] ?? 4),
        ];
    }

    promoLog("scene_plan: brief=$brief_id scenes=" . count($out) . " product=$product", 'STAGE2');
    echo json_encode(['success'=>true,'scenes'=>$out]);

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
        $sql_pod = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, title, lang_code, video_type,
             video_status, internal_status, created_date, updated_at,
             niche, category, topic_key, hashtags, keywords,
             host_voice, guest_voice, voice_rate, is_campaign, logo_flag,
             facebook_status, tiktok_status, instagram_status, youtube_status,
             twitter_status, linkedin_status, schedule_date, schedule_time,
             publish_date, video_format, video_media, music_file, hook_name, videogen_flag)
            VALUES
            ($admin_id, $team_lead_id, $company_id, '$esc_title', 'en', '$video_type',
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

        // Store voice on podcast immediately
        mysqli_query($conn, "UPDATE hdb_podcasts SET
            host_voice='$host_voice', guest_voice='$host_voice', voice_rate=$voice_rate
            WHERE id=$podcast_id");

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
                ($podcast_id,'en','promo','$niche','$esc_title','host',
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

        // Update promo_brief status
        mysqli_query($conn, "UPDATE hdb_promo_briefs SET
            podcast_id=$podcast_id, status='storyboard_approved', updated_at=NOW()
            WHERE id=$brief_id");

        // Deduct credits
        mysqli_query($conn, "UPDATE hdb_users SET
            credit_balance = GREATEST(0, credit_balance - $credits_charge)
            WHERE id=$team_lead_id");

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

/* ── AI generating block ──────────────────────────────────── */
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
.btn-row{display:flex;align-items:center;justify-content:space-between;margin-top:18px;gap:10px;}

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

.scene-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:16px;}
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
.s2-action-bar{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
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
    <span class="hdr-co">/ Product Promo</span>
    <div class="hdr-right">
        <span class="credit-badge">⚡ <?= number_format($user_credit_balance) ?> credits</span>
        <?php if (count($all_companies) > 1): ?>
        <select class="co-switch" onchange="location.href='?company_id='+this.value">
            <?php foreach ($all_companies as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id']==$company_id?'selected':'' ?>>
                <?= htmlspecialchars($c['companyname']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
</header>

<div class="wrap">

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

                <!-- Brand / Company — read only from DB -->
                <div style="margin-top:12px;">
                    <div class="flabel">Brand / Company <span class="opt">— from your account</span></div>
                    <div class="field-readonly">
                        <span class="ro-icon">🏢</span>
                        <div>
                            <div class="ro-val" id="ro-brand"><?= $co_brand ?: $co_name ?></div>
                            <div class="ro-sub">Auto-filled from your company profile</div>
                        </div>
                    </div>
                </div>

                <!-- Product image upload (shown when category needs_image) -->
                <div id="image-upload-wrap" style="display:none;margin-top:16px;">
                    <div class="flabel">Product Photo <span class="opt">— recommended</span></div>
                    <div class="fhint">Upload your product image — AI will analyze it and tailor every scene to match</div>

                    <div class="upload-zone" id="uploadZone">
                        <input type="file" id="productImageInput" accept="image/jpeg,image/png,image/webp">
                        <div class="upload-icon">🖼️</div>
                        <div class="upload-label">Click or drag & drop your product image</div>
                        <div class="upload-sub">JPG, PNG, WEBP — max 10 MB</div>
                    </div>

                    <div class="product-preview" id="productPreview" style="display:none;">
                        <img id="previewImg" src="" alt="Product">
                        <div class="preview-info">
                            <div class="preview-name" id="previewName">—</div>
                            <div class="preview-desc" id="previewDesc">Analyzing with AI vision…</div>
                        </div>
                        <button type="button" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--muted);flex-shrink:0;" onclick="clearProductImage()" title="Remove">✕</button>
                    </div>
                </div>

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

    <!-- ══ STEP: PRICING ═══════════════════════════════════ -->
    <div id="step-pricing" style="display:none">
        <div class="sec-title">Choose how to generate your video</div>
        <div class="sec-sub">Both options produce a 30-second promotional reel</div>

        <div class="pricing-track">

            <!-- Stock -->
            <div class="pricing-card" id="pc-stock" onclick="selectTrack('stock')">
                <div class="pricing-icon">📚</div>
                <div class="pricing-name">Stock Media Video</div>
                <div class="pricing-desc">Professional stock footage matched to your offer. Fast and cost-effective.</div>
                <div class="pricing-credits"><?= $credits_stock ?> <span>credits</span></div>
                <div class="pricing-time">⏱ Ready in ~3 minutes</div>
                <div class="pricing-status" id="ps-stock-status"></div>
            </div>

            <!-- AI -->
            <div class="pricing-card recommended" id="pc-ai" onclick="selectTrack('ai')">
                <div class="pricing-icon">✨</div>
                <div class="pricing-name">AI Custom Video</div>
                <div class="pricing-desc">Your actual product in every scene. AI-generated images animated to video.</div>
                <div class="pricing-credits"><?= $credits_ai ?> <span>credits</span></div>
                <div class="pricing-time">⏱ Ready in ~20 minutes</div>
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

    <!-- ══ STEP: CONFIRM ═══════════════════════════════════ -->
    <div id="step-confirm" style="display:none">
        <div class="sec-title">Looking good 👍</div>
        <div class="sec-sub">Here's your brief. Tap any field to edit.</div>
        <div class="card" id="confirm-card"></div>
        <div class="btn-row">
            <button class="btn btn-ghost" onclick="showStep('pricing')">← Change option</button>
            <button class="btn btn-primary" id="btn-save" onclick="doSave()">
                Save & Continue →
            </button>
        </div>
    </div>

    <!-- ══ STAGE 2: STORYBOARD ════════════════════════════ -->
    <div id="step-storyboard" style="display:none">
        <div class="sec-title">🎬 Your Scene Plan</div>
        <div class="sec-sub">AI has written your scenes — edit any caption, then approve to create your video</div>

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

        <!-- Action bar -->
        <div class="s2-action-bar" id="s2-action-bar" style="display:none">
            <div style="font-size:13px;color:var(--muted);">
                <span id="s2-scene-count"></span> &nbsp;·&nbsp; Review captions then approve
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-ghost btn-sm" onclick="regenerateScenes()">↺ Regenerate</button>
                <button class="btn btn-primary" id="btn-approve" onclick="approveAndSave()">
                    ✓ Approve & Create Video
                </button>
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
          <span style="opacity:.8;">Stock Promo = 20 credits</span>
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
const IS_DEBUG   = <?= $is_debug ? 'true' : 'false' ?>;

// ── State ──────────────────────────────────────────────────
const S = {
    category: '', cat_name: '', sub_cats: [],
    sub_category: '',
    has_product_name: true,
    pn_label: 'Product Name',
    pn_placeholder: '',
    needs_image: false,
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
    ['category','offer','pricing','confirm','storyboard'].forEach(s =>
        document.getElementById('step-' + s).style.display = s === name ? '' : 'none');
    updateProg(name);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function updateProg(step) {
    const active = step === 'category' || step === 'offer' ? 1
                 : step === 'pricing'  ? 2
                 : step === 'confirm'  ? 3
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
function selectCategory(tile, cat) {
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
    document.getElementById('image-upload-wrap').style.display = cat.needs_image ? '' : 'none';

    setTimeout(() => showStep('offer'), 200);
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
            inp = `<select id="input-${def.id}">${def.opts.map(o=>`<option value="${o}">${o}</option>`).join('')}</select>`;
        } else if (def.type === 'textarea') {
            inp = `<textarea id="input-${def.id}" rows="3" placeholder="${def.ph||''}"></textarea>`;
        } else {
            inp = `<input type="text" id="input-${def.id}" placeholder="${def.ph||''}">`;
        }
        w.innerHTML = `<div class="flabel">${def.label}${optStr}</div>${inp}`;
        container.appendChild(w);
    });
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
        showPricing();
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
    // Update credit status for each option
    const setStatus = (elId, credits) => {
        const el  = document.getElementById(elId);
        const ok  = CREDITS >= credits;
        const pct = Math.round((CREDITS / credits) * 100);
        el.className = 'pricing-status ' + (ok ? 'ok' : 'no');
        el.textContent = ok
            ? `✓ You have ${CREDITS} credits`
            : `✗ Need ${credits - CREDITS} more credits`;
    };
    setStatus('ps-stock-status', CR_STOCK);
    setStatus('ps-ai-status',    CR_AI);

    // Pre-select if only one is affordable
    if (CREDITS >= CR_AI)       selectTrack('ai');
    else if (CREDITS >= CR_STOCK) selectTrack('stock');

    dbg({ step: 'pricing', credits: CREDITS, cr_ai: CR_AI, cr_stock: CR_STOCK });
    showStep('pricing');
}

function selectTrack(track) {
    S.video_track = track;
    document.getElementById('pc-stock').classList.toggle('selected', track === 'stock');
    document.getElementById('pc-ai').classList.toggle('selected', track === 'ai');
    const credits = track === 'ai' ? CR_AI : CR_STOCK;
    const canAfford = CREDITS >= credits;
    document.getElementById('btn-pricing-next').disabled = !canAfford;
    if (!canAfford) toast(`Not enough credits — need ${credits} credits`, 'error');
}

// ── Collect all fields ─────────────────────────────────────
function collectFields() {
    const val = id => { const el = document.getElementById(id); return el ? el.value.trim() : ''; };
    const extra = {};
    document.querySelectorAll('#extra-fields .fstep').forEach(el => {
        const key = el.dataset.key;
        const inp = el.querySelector('input,textarea,select');
        if (key && inp && inp.value.trim()) extra[key] = inp.value.trim();
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
    const trackLabel = f.video_track === 'ai'
        ? `✨ AI Custom Video — ${CR_AI} credits`
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
    showStep('confirm');
}

// ── Save to DB ─────────────────────────────────────────────
async function doSave() {
    const f   = collectFields();
    const btn = document.getElementById('btn-save');
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
const uploadZone = document.getElementById('uploadZone');
if (uploadZone) {
    uploadZone.addEventListener('dragover',  e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
    uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
    uploadZone.addEventListener('drop', e => {
        e.preventDefault(); uploadZone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) handleImageUpload(e.dataTransfer.files[0]);
    });
    document.getElementById('productImageInput').addEventListener('change', e => {
        if (e.target.files[0]) handleImageUpload(e.target.files[0]);
    });
}

async function handleImageUpload(file) {
    // Show instant preview
    const reader = new FileReader();
    reader.onload = ev => {
        document.getElementById('previewImg').src     = ev.target.result;
        document.getElementById('previewName').textContent = file.name;
        document.getElementById('previewDesc').textContent = 'Analyzing with AI vision…';
        document.getElementById('uploadZone').style.display    = 'none';
        document.getElementById('productPreview').style.display = '';
    };
    reader.readAsDataURL(file);

    // Upload to server
    const fd = new FormData();
    fd.append('ajax_action',   'upload_product_image');
    fd.append('product_image', file);
    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            S.product_image_path = d.path;
            S.product_image_name = d.name;
            const descEl = document.getElementById('previewDesc');
            if (d.auto_desc) {
                descEl.innerHTML = '<span class="vision-badge">✨ AI Vision</span> ' + d.auto_desc;
            } else {
                descEl.textContent = 'Image uploaded successfully';
            }
        } else {
            toast('Upload failed: ' + (d.error || 'Unknown error'), 'error');
            clearProductImage();
        }
    } catch(e) {
        toast('Upload error: ' + e.message, 'error');
        clearProductImage();
    }
}

async function clearProductImage() {
    document.getElementById('productPreview').style.display = 'none';
    document.getElementById('uploadZone').style.display     = '';
    document.getElementById('productImageInput').value      = '';
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
    document.getElementById('s2Setup').style.display    = 'block';
    document.getElementById('s2Progress').style.display = 'none';
    document.getElementById('s2SceneGrid').style.display = 'none';
    document.getElementById('s2DoneBarGrid').style.display = 'none';
    document.getElementById('s2Log').innerHTML = '';
    document.getElementById('s2CloseBtn').style.display = 'inline';
    ['s2Step0','s2Step1','s2Step2','s2Step3'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.className = 's2-step'; el.querySelector('.s2-step-sub').textContent = 'Waiting…'; }
    });
    document.getElementById('s2Overlay').classList.add('open');
}

function closeS2Promo() {
    s2PromoCancelled = true;
    document.getElementById('s2ProcessingOverlay').classList.remove('active');
    document.getElementById('s2Overlay').classList.remove('open');
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

function s2StepDone(n, txt)   { const el = document.getElementById('s2Step'+n); if(el){ el.className='s2-step done';   el.querySelector('.s2-step-sub').textContent = txt; } }
function s2StepActive(n, txt) { const el = document.getElementById('s2Step'+n); if(el){ el.className='s2-step active'; el.querySelector('.s2-step-sub').textContent = txt; } }
function s2StepError(n, txt)  { const el = document.getElementById('s2Step'+n); if(el){ el.className='s2-step error';  el.querySelector('.s2-step-sub').textContent = txt; } }

function updateSceneBox(i, state, file) {
    const box   = document.getElementById('promo-box-' + i);
    const inner = document.getElementById('promo-box-inner-' + i);
    if (!box || !inner) return;
    if (state === 'done' && file) {
        const isVid = /\.(mp4|webm)$/i.test(file);
        const folder = isVid ? 'podcast_videos' : 'podcast_images';
        box.style.border = '2px solid #22c55e';
        box.innerHTML = isVid
            ? `<video src="${folder}/${file}" style="width:100%;height:100%;object-fit:cover;" muted playsinline loop></video><div style="position:absolute;bottom:2px;left:0;right:0;text-align:center;font-size:8px;color:#fff;background:rgba(0,0,0,.5);">${i+1} ✓</div>`
            : `<img src="${folder}/${file}" style="width:100%;height:100%;object-fit:cover;"><div style="position:absolute;bottom:2px;left:0;right:0;text-align:center;font-size:8px;color:#fff;background:rgba(0,0,0,.5);">${i+1} ✓</div>`;
    } else if (state === 'audio') {
        box.style.borderColor = '#4fc3f7';
        inner.innerHTML = `<div style="font-size:9px;color:#4fc3f7;font-weight:700;">${i+1}</div><div style="font-size:11px;">🔊</div>`;
    } else if (state === 'error') {
        box.style.borderColor = '#ef4444';
        inner.innerHTML = `<div style="font-size:9px;color:#ef4444;">${i+1}</div><div style="font-size:11px;">✗</div>`;
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
    const hostVoice  = document.getElementById('s2StdHostVoice').value || 'openai:alloy';
    const rate       = document.getElementById('s2Rate').value || '1.1';
    const S2_EP      = 'promo_step2.php';
    const _buildStart = performance.now();
    const elapsed    = () => ((performance.now() - _buildStart) / 1000).toFixed(1) + 's';

    // Switch to progress view
    document.getElementById('s2Setup').style.display    = 'none';
    document.getElementById('s2Progress').style.display = 'block';
    document.getElementById('s2CloseBtn').style.display = 'none';
    document.getElementById('s2ProcessingOverlay').classList.add('active');
    document.getElementById('s2ProcessingMsg').textContent  = 'Building your promo video…';
    document.getElementById('s2ProcessingStep').textContent = 'Saving scenes…';

    // ── Step 0: Save scenes to DB ──────────────────────────
    s2StepActive(0, 'Saving scenes…');
    s2Log('💾 Saving scene plan to database…', 'info');

    const fd = new FormData();
    fd.append('ajax_action', 'save_storyboard');
    fd.append('brief_id',    S2.brief_id);
    fd.append('track',       S2.track);
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
            document.getElementById('s2CloseBtn').style.display = 'inline';
            document.getElementById('s2ProcessingOverlay').classList.remove('active');
            return;
        }
        if (!d.success) {
            s2Log('❌ ' + (d.error || 'Save failed'), 'error');
            s2StepError(0, d.error || 'Failed');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            document.getElementById('s2ProcessingOverlay').classList.remove('active');
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
        document.getElementById('s2CloseBtn').style.display = 'inline';
        document.getElementById('s2ProcessingOverlay').classList.remove('active');
        return;
    }

    // Draw scene boxes
    document.getElementById('s2SceneGrid').style.display = 'block';
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
    document.getElementById('s2ProcessingStep').textContent = 'Saving voice settings…';
    try {
        const vFd = new FormData();
        vFd.append('action','update_podcast_voice');
        vFd.append('podcast_id', podcast_id);
        vFd.append('host_voice', hostVoice);
        vFd.append('guest_voice', hostVoice);
        vFd.append('rate', rate);
        await fetch(S2_EP, { method:'POST', body:vFd, credentials:'include' });
        s2Log('✅ Voice: ' + hostVoice + ' @ ' + rate + 'x', 'success');
    } catch(e) { s2Log('⚠ Voice save: ' + e.message, 'warning'); }

    // ── Fetch DB scene rows (get IDs + text) ───────────────
    document.getElementById('s2ProcessingStep').textContent = 'Loading scenes…';
    s2Log('📋 Fetching scenes from DB…', 'info');
    let dbScenes = [];
    try {
        const gFd = new FormData();
        gFd.append('action','get_scenes');
        gFd.append('podcast_id', podcast_id);
        const gr = await fetch(S2_EP, { method:'POST', body:gFd, credentials:'include' });
        const gt = await gr.text();
        try { dbScenes = JSON.parse(gt); } catch(e) { s2Log('⚠ get_scenes parse: ' + gt.substring(0,100), 'warning'); }
        if (!Array.isArray(dbScenes)) dbScenes = [];
        s2Log('📋 ' + dbScenes.length + ' scenes loaded (' + elapsed() + ')', 'info');
    } catch(e) { s2Log('⚠ get_scenes: ' + e.message, 'warning'); }

    if (!dbScenes.length) {
        s2Log('❌ No scenes in DB — aborting', 'error');
        s2StepError(1, 'No scenes found in DB');
        document.getElementById('s2CloseBtn').style.display = 'inline';
        document.getElementById('s2ProcessingOverlay').classList.remove('active');
        return;
    }

    // ── Push nl_tags (stock_query) into each scene ─────────
    s2Log('🏷 Pushing search tags to scenes…', 'info');
    document.getElementById('s2ProcessingStep').textContent = 'Setting search tags…';
    await Promise.all(dbScenes.map((scene, i) => {
        const sq = S2.scenes[i]?.stock_query || scene.text_contents || '';
        const tFd = new FormData();
        tFd.append('action',    'update_scene_tags');
        tFd.append('scene_id',  scene.id);
        tFd.append('nl_tags',   sq);
        tFd.append('hashtags',  sq.replace(/\s+/g,' '));
        return fetch(S2_EP, { method:'POST', body:tFd, credentials:'include' }).catch(() => {});
    }));
    s2Log('✅ Search tags set', 'success');
    s2StepDone(1, '✓ Tags + voice ready');

    // ── Step 2: Audio (parallel) ───────────────────────────
    s2StepActive(2, 'Generating audio…');
    document.getElementById('s2ProcessingStep').textContent = 'Generating voiceover…';
    s2Log('🔊 Generating audio for ' + dbScenes.length + ' scenes in parallel…', 'info');
    let audioDone = 0, audioFail = 0;
    const audioPromises = dbScenes.map((scene, i) => {
        const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();
        if (!text) { audioDone++; return Promise.resolve(); }
        const aFd = new FormData();
        aFd.append('action',     'generate_scene_audio');
        aFd.append('scene_id',   scene.id);
        aFd.append('podcast_id', podcast_id);
        aFd.append('seq_no',     i + 1);
        aFd.append('lang_code',  'en');
        aFd.append('voice_id',   hostVoice);
        aFd.append('rate',       rate);
        aFd.append('text',       text);
        return fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' })
            .then(r => r.json())
            .then(d => {
                if (d.success) { audioDone++; updateSceneBox(i,'audio'); s2Log('✓ Scene '+(i+1)+' audio ('+elapsed()+')', 'success'); }
                else           { audioFail++; s2Log('✗ Scene '+(i+1)+' audio: '+(d.error||'failed')+' ('+elapsed()+')', 'error'); }
            })
            .catch(e => { audioFail++; s2Log('✗ Scene '+(i+1)+' audio: '+e.message,'error'); });
    });

    // ── Step 3: Media (sequential to avoid dupes) ──────────
    s2StepActive(3, 'Searching stock media…');
    document.getElementById('s2ProcessingStep').textContent = 'Assigning stock media…';
    s2Log('🖼 Assigning stock media sequentially…', 'info');
    let mediaDone = 0, mediaFail = 0;
    const usedFilenames = new Set();

    for (let i = 0; i < dbScenes.length; i++) {
        if (s2PromoCancelled) break;
        const scene  = dbScenes[i];
        const nlTags = S2.scenes[i]?.stock_query || scene.natural_language_tags || scene.text_contents || '';
        document.getElementById('s2ProcessingStep').textContent = 'Media ' + (i+1) + '/' + dbScenes.length;
        try {
            const sFd = new FormData();
            sFd.append('action',        'search_images_batch');
            sFd.append('podcast_id',    podcast_id);
            sFd.append('slots',         1);
            sFd.append('media_type',    'stock_videos');
            sFd.append('exclude_files', JSON.stringify([...usedFilenames]));
            sFd.append('scenes', JSON.stringify([{
                scene_idx: i, scene_id: scene.id,
                nl_tags: nlTags, query: nlTags
            }]));
            const sr   = await fetch(S2_EP, { method:'POST', body:sFd, credentials:'include' });
            const sRes = await sr.json();
            const found = sRes.results?.[0]?.found || [];

            if (found.length > 0) {
                const best = found.find(f => !usedFilenames.has(f.filename)) || found[0];
                usedFilenames.add(best.filename);
                const aFd = new FormData();
                aFd.append('action',           'assign_image');
                aFd.append('scene_id',         scene.id);
                aFd.append('podcast_id',       podcast_id);
                aFd.append('filename',         best.filename);
                aFd.append('image_field',      'image_file');
                aFd.append('media_type',       best.type || 'video');
                aFd.append('search_query',     nlTags);
                aFd.append('similarity_score', best.score  || 0);
                aFd.append('match_rank',       best.rank   || 1);
                aFd.append('matched_terms',    best.matched_terms || '[]');
                await fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' });
                mediaDone++;
                updateSceneBox(i, 'done', best.filename);
                s2Log('✓ Scene '+(i+1)+': '+best.filename+' ('+elapsed()+')', 'success');
            } else {
                mediaFail++;
                updateSceneBox(i, 'error');
                s2Log('⚠ Scene '+(i+1)+': no stock match for "'+nlTags+'"', 'warning');
            }
        } catch(e) {
            mediaFail++;
            updateSceneBox(i, 'error');
            s2Log('✗ Scene '+(i+1)+' media: '+e.message, 'error');
        }
    }

    // Wait for all audio to finish
    await Promise.all(audioPromises);

    s2StepDone(2, '✓ ' + audioDone + ' audio' + (audioFail > 0 ? ' ('+audioFail+' failed)' : ''));
    s2StepDone(3, '✓ ' + mediaDone + ' media' + (mediaFail > 0 ? ' ('+mediaFail+' skipped)' : ''));

    document.getElementById('s2ProcessingOverlay').classList.remove('active');
    document.getElementById('s2CloseBtn').style.display = 'inline';
    document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id=' + podcast_id;
    const doneBar = document.getElementById('s2DoneBarGrid');
    doneBar.style.display = 'flex';
    s2Log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
    s2Log('🎉 Done! Podcast #' + podcast_id + ' — ' + mediaDone + '/' + dbScenes.length + ' scenes OK', 'success');
    s2Log('⏱ Total build time: ' + elapsed(), 'success');
    s2Log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'info');
    toast('✅ Video ready!', 'success');
    dbg({ done: true, podcast_id });
}
let S2 = { brief_id: 0, track: '', scenes: [], podcast_id: 0 };

const SCENE_TYPES = ['hook','problem','product','feature','lifestyle','social','cta'];
const SCENE_LABELS = { hook:'Hook', problem:'Problem', product:'Product', feature:'Feature', lifestyle:'Lifestyle', social:'Social Proof', cta:'Call to Action' };

async function launchStage2(brief_id, track) {
    S2.brief_id = brief_id;
    S2.track    = track;
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

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        document.getElementById('s2-generating').style.display = 'none';

        if (!d.success) { toast('Scene generation failed: ' + (d.error || 'Unknown'), 'error'); return; }

        S2.scenes = d.scenes;
        renderScenes(S2.scenes);
    } catch(e) {
        document.getElementById('s2-generating').style.display = 'none';
        toast('Network error: ' + e.message, 'error');
    }
}

function renderScenes(scenes) {
    const grid = document.getElementById('scene-grid');
    grid.className = 'scene-grid';
    grid.innerHTML = '';

    scenes.forEach((sc, i) => {
        const typeKey  = sc.scene_type || 'feature';
        const badgeCls = 'badge-' + typeKey;
        const label    = SCENE_LABELS[typeKey] || typeKey;

        const card = document.createElement('div');
        card.className = 'scene-card';
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

        // Live-sync edits back to S2.scenes
        card.querySelector('.scene-caption').addEventListener('input', function() {
            S2.scenes[i].caption = this.value;
        });

        grid.appendChild(card);
    });

    document.getElementById('s2-scene-count').textContent = scenes.length + ' scenes · 30 seconds';
    document.getElementById('s2-action-bar').style.display = '';
    dbg({ stage: 2, scenes });
}

async function regenerateScenes() {
    document.getElementById('s2-generating').style.display = '';
    document.getElementById('s2-action-bar').style.display = 'none';
    await generateScenePlan();
}

async function approveAndSave() {
    const btn = document.getElementById('btn-approve');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Saving…';

    // ── Step 1: Save scenes to DB ──────────────────────────────────────────
    const fd = new FormData();
    fd.append('ajax_action', 'save_storyboard');
    fd.append('brief_id',    S2.brief_id);
    fd.append('track',       S2.track);
    fd.append('scenes',      JSON.stringify(S2.scenes));

    let podcast_id = 0;
    try {
        const r    = await fetch('', { method: 'POST', body: fd });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); }
        catch(je) {
            toast('Server error — check promo_gen.log', 'error');
            console.error('save_storyboard raw:', text);
            btn.disabled = false; btn.textContent = '✓ Approve & Create Video';
            return;
        }

        if (!d.success) {
            toast('Save failed: ' + (d.error || 'Unknown'), 'error');
            btn.disabled = false; btn.textContent = '✓ Approve & Create Video';
            return;
        }
        podcast_id = d.podcast_id;
        S2.podcast_id = podcast_id;
        toast('✓ Scenes saved — building your video…', 'success');
    } catch(e) {
        toast('Network error: ' + e.message, 'error');
        btn.disabled = false; btn.textContent = '✓ Approve & Create Video';
        return;
    }

    // ── Step 2: Show processing UI ─────────────────────────────────────────
    btn.innerHTML = '<span class="spin"></span> Processing…';
    document.getElementById('s2-action-bar').style.display = 'none';
    document.getElementById('s2-generating').style.display = '';
    document.getElementById('s2-gen-status').textContent   = 'Enhancing prompts and generating audio…';

    const S2_EP    = 'promo_step2.php';
    const hostVoice = 'openai:alloy'; // default voice for promo videos
    const langCode  = 'en';
    const rate      = '1.0';
    const niche     = S2.scenes.map(s => s.stock_query).join(' ');

    function s2promoLog(msg) {
        const el = document.getElementById('s2-gen-status');
        if (el) el.textContent = msg;
        console.log('[PromoS2]', msg);
    }

    // ── Step 3: Update voice on podcast ───────────────────────────────────
    try {
        const vFd = new FormData();
        vFd.append('action','update_podcast_voice');
        vFd.append('podcast_id', podcast_id);
        vFd.append('host_voice', hostVoice);
        vFd.append('guest_voice', hostVoice);
        vFd.append('rate', rate);
        await fetch(S2_EP, { method:'POST', body:vFd, credentials:'include' });
        s2promoLog('✓ Voice set');
    } catch(e) { s2promoLog('⚠ Voice update skipped: ' + e.message); }

    // ── Step 4: create_scenes_from_podcast — enhances prompts, nl_tags, builds captions ──
    s2promoLog('Enhancing scene prompts and building captions…');
    try {
        const cFd = new FormData();
        cFd.append('action',      'create_scenes_from_podcast');
        cFd.append('podcast_id',  podcast_id);
        cFd.append('host_voice',  hostVoice);
        cFd.append('guest_voice', hostVoice);
        cFd.append('rate',        rate);
        cFd.append('lang_code',   langCode);
        cFd.append('is_broll',    '0');
        const cr = await fetch(S2_EP, { method:'POST', body:cFd, credentials:'include' });
        const ct = await cr.text();
        let cd;
        try { cd = JSON.parse(ct); } catch(e) { console.warn('create_scenes parse:', ct.substring(0,200)); }
        if (cd && cd.success) {
            s2promoLog('✓ ' + (cd.scene_count || S2.scenes.length) + ' scenes enhanced');
        } else {
            s2promoLog('⚠ Scene enhancement partial — continuing anyway');
        }
    } catch(e) { s2promoLog('⚠ Scene enhance error: ' + e.message + ' — continuing'); }

    // ── Step 5: get_scenes — get DB scene IDs ─────────────────────────────
    s2promoLog('Fetching scenes from database…');
    let dbScenes = [];
    try {
        const gFd = new FormData();
        gFd.append('action', 'get_scenes');
        gFd.append('podcast_id', podcast_id);
        const gr = await fetch(S2_EP, { method:'POST', body:gFd, credentials:'include' });
        dbScenes = await gr.json();
        if (!Array.isArray(dbScenes)) dbScenes = [];
        s2promoLog('✓ ' + dbScenes.length + ' scenes loaded');
    } catch(e) { s2promoLog('⚠ Could not fetch scenes: ' + e.message); }

    if (!dbScenes.length) {
        toast('No scenes found in DB — check logs', 'error');
        btn.disabled = false; btn.textContent = '✓ Approve & Create Video';
        document.getElementById('s2-generating').style.display = 'none';
        document.getElementById('s2-action-bar').style.display = '';
        return;
    }

    // ── Step 6: Generate audio for all scenes in parallel ─────────────────
    s2promoLog('Generating voiceover audio for ' + dbScenes.length + ' scenes…');
    const audioPromises = dbScenes.map((scene, i) => {
        const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();
        if (!text) return Promise.resolve();
        const aFd = new FormData();
        aFd.append('action',     'generate_scene_audio');
        aFd.append('scene_id',   scene.id);
        aFd.append('podcast_id', podcast_id);
        aFd.append('seq_no',     i + 1);
        aFd.append('lang_code',  langCode);
        aFd.append('voice_id',   hostVoice);
        aFd.append('rate',       rate);
        aFd.append('text',       text);
        return fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' })
            .then(r => r.json())
            .then(d => { if (d.success) console.log(`✓ Audio scene ${i+1}`); else console.warn(`✗ Audio scene ${i+1}: ${d.error}`); })
            .catch(e => console.warn(`✗ Audio scene ${i+1}: ${e.message}`));
    });

    // ── Step 7: Assign stock media to each scene (sequential, no duplicates) ──
    s2promoLog('Searching and assigning stock media…');
    const usedFilenames = new Set();
    for (let i = 0; i < dbScenes.length; i++) {
        const scene  = dbScenes[i];
        const nlTags = scene.natural_language_tags || S2.scenes[i]?.stock_query || scene.text_contents || '';
        try {
            const sFd = new FormData();
            sFd.append('action',        'search_images_batch');
            sFd.append('podcast_id',    podcast_id);
            sFd.append('slots',         1);
            sFd.append('media_type',    'stock_videos');
            sFd.append('exclude_files', JSON.stringify([...usedFilenames]));
            sFd.append('scenes', JSON.stringify([{
                scene_idx: i, scene_id: scene.id,
                nl_tags: nlTags, query: nlTags
            }]));
            const sr  = await fetch(S2_EP, { method:'POST', body:sFd, credentials:'include' });
            const sRes = await sr.json();
            const found = sRes.results?.[0]?.found || [];

            if (found.length > 0) {
                const best = found.find(item => !usedFilenames.has(item.filename)) || found[0];
                usedFilenames.add(best.filename);
                const aFd = new FormData();
                aFd.append('action',           'assign_image');
                aFd.append('scene_id',         scene.id);
                aFd.append('podcast_id',       podcast_id);
                aFd.append('filename',         best.filename);
                aFd.append('image_field',      'image_file');
                aFd.append('media_type',       best.type || 'video');
                aFd.append('search_query',     nlTags);
                aFd.append('similarity_score', best.score  || 0);
                aFd.append('match_rank',       best.rank   || 1);
                aFd.append('matched_terms',    best.matched_terms || '[]');
                await fetch(S2_EP, { method:'POST', body:aFd, credentials:'include' });
                console.log(`✓ Media scene ${i+1}: ${best.filename}`);
            } else {
                console.warn(`⚠ No stock media for scene ${i+1}`);
            }
        } catch(e) { console.warn(`✗ Media scene ${i+1}: ${e.message}`); }

        s2promoLog('Assigning media… ' + (i+1) + '/' + dbScenes.length);
    }

    // Wait for all audio to finish
    await Promise.all(audioPromises);
    s2promoLog('✓ All done! Redirecting to video editor…');

    dbg({ stage2_complete: true, podcast_id });
    toast('✅ Video ready! Opening editor…', 'success');

    setTimeout(() => {
        window.location.href = 'videomaker.php?podcast_id=' + podcast_id;
    }, 1000);
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
