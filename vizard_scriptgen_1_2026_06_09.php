<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 15552000);
    ini_set('session.cookie_lifetime', 15552000);
    session_set_cookie_params(15552000);
    session_start();
}
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }
include 'dbconnect_hdb.php';

// ── Suppress fatal DB exceptions — use warnings instead ──────────────────────
mysqli_report(MYSQLI_REPORT_OFF);

// ── Auto-create missing tables on VPS ────────────────────────────────────────
$_tbls = [
"hdb_master_groups" => "CREATE TABLE IF NOT EXISTS hdb_master_groups (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    core_group    VARCHAR(120) DEFAULT NULL,
    industry_desc VARCHAR(200) NOT NULL,
    INDEX idx_cg (core_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_master_niches" => "CREATE TABLE IF NOT EXISTS hdb_master_niches (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    master_industry_id INT NOT NULL DEFAULT 0,
    niche_desc         VARCHAR(200) NOT NULL,
    INDEX idx_ind (master_industry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_master_video_ideas" => "CREATE TABLE IF NOT EXISTS hdb_master_video_ideas (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    niche_name VARCHAR(200) NOT NULL,
    video_idea VARCHAR(500) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_idea (niche_name(100), video_idea(200))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_video_ideas" => "CREATE TABLE IF NOT EXISTS hdb_user_video_ideas (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL DEFAULT 0,
    company_id      INT NOT NULL DEFAULT 0,
    niche_id        INT NOT NULL DEFAULT 0,
    category_id     INT NOT NULL DEFAULT 0,
    niche_name      VARCHAR(200) NOT NULL DEFAULT '',
    category_name   VARCHAR(200) NOT NULL DEFAULT '',
    video_idea      VARCHAR(500) NOT NULL,
    is_ai_generated TINYINT(1)  NOT NULL DEFAULT 0,
    created_date    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_an (admin_id, niche_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_niches" => "CREATE TABLE IF NOT EXISTS hdb_user_niches (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL DEFAULT 0,
    company_id      INT NOT NULL DEFAULT 0,
    niche_name      VARCHAR(200) NOT NULL,
    is_ai_generated TINYINT(1)  NOT NULL DEFAULT 0,
    created_date    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_n (admin_id, company_id, niche_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_categories" => "CREATE TABLE IF NOT EXISTS hdb_user_categories (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL DEFAULT 0,
    company_id      INT NOT NULL DEFAULT 0,
    niche_id        INT NOT NULL DEFAULT 0,
    niche_name      VARCHAR(200) NOT NULL DEFAULT '',
    category_name   VARCHAR(200) NOT NULL DEFAULT '',
    is_ai_generated TINYINT(1)  NOT NULL DEFAULT 0,
    created_date    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_c (admin_id, company_id, niche_name(100), category_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_cta" => "CREATE TABLE IF NOT EXISTS hdb_user_cta (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    admin_id     INT NOT NULL DEFAULT 0,
    company_id   INT NOT NULL DEFAULT 0,
    cta_text     VARCHAR(500) NOT NULL,
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cta (company_id, cta_text(200))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_hooks" => "CREATE TABLE IF NOT EXISTS hdb_hooks (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    hook_name  VARCHAR(300) NOT NULL,
    hook_type  VARCHAR(100) NOT NULL DEFAULT 'general',
    status     TINYINT(1)  NOT NULL DEFAULT 1,
    sort_order INT         NOT NULL DEFAULT 0,
    INDEX idx_type (hook_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_groups" => "CREATE TABLE IF NOT EXISTS hdb_user_groups (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL DEFAULT 0,
    company_id      INT NOT NULL DEFAULT 0,
    niche_name      VARCHAR(200) DEFAULT NULL,
    industry_id     INT NOT NULL DEFAULT 0,
    is_ai_generated TINYINT(1)  NOT NULL DEFAULT 0,
    created_date    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_g (admin_id, industry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_angles" => "CREATE TABLE IF NOT EXISTS hdb_user_angles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT NOT NULL DEFAULT 0,
    niche_name      VARCHAR(200) NOT NULL DEFAULT '',
    angle_name      VARCHAR(300) NOT NULL,
    is_ai_generated TINYINT(1)  NOT NULL DEFAULT 0,
    created_date    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"hdb_user_hooks" => "CREATE TABLE IF NOT EXISTS hdb_user_hooks (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    admin_id     INT NOT NULL DEFAULT 0,
    company_id   INT NOT NULL DEFAULT 0,
    niche_name   VARCHAR(200) NOT NULL DEFAULT '',
    hook_text    VARCHAR(500) NOT NULL,
    hook_type    VARCHAR(100) NOT NULL DEFAULT 'custom',
    created_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_niche_hook (admin_id, niche_name(100), hook_text(200)),
    INDEX idx_un (admin_id, niche_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($_tbls as $_tn => $_tsql) {
    if (!mysqli_query($conn, $_tsql)) {
        error_log("VPS table create failed [$_tn]: " . mysqli_error($conn));
    }
}

// Auto-add missing columns to hdb_companies
if (mysqli_query($conn, "SELECT 1 FROM hdb_companies LIMIT 1") !== false) {
    $_cols = []; $_cr = mysqli_query($conn, "SHOW COLUMNS FROM hdb_companies");
    if ($_cr) while ($_col = mysqli_fetch_assoc($_cr)) $_cols[] = $_col['Field'];
    if (!in_array('group_name',    $_cols)) mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN group_name    VARCHAR(120) DEFAULT NULL");
    if (!in_array('subgroup_name', $_cols)) mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN subgroup_name VARCHAR(120) DEFAULT NULL");
    if (!in_array('niche',         $_cols)) mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN niche         VARCHAR(120) DEFAULT NULL");
}

// Auto-add missing columns to hdb_users (team_lead_id and role may not exist on all installs)
if (mysqli_query($conn, "SELECT 1 FROM hdb_users LIMIT 1") !== false) {
    $_ucols = []; $_ucr = mysqli_query($conn, "SHOW COLUMNS FROM hdb_users");
    if ($_ucr) while ($_uc = mysqli_fetch_assoc($_ucr)) $_ucols[] = $_uc['Field'];
    if (!in_array('team_lead_id', $_ucols)) mysqli_query($conn, "ALTER TABLE hdb_users ADD COLUMN team_lead_id INT DEFAULT NULL");
    if (!in_array('role',         $_ucols)) mysqli_query($conn, "ALTER TABLE hdb_users ADD COLUMN role         VARCHAR(50) DEFAULT 'Team Lead'");
}
// ─────────────────────────────────────────────────────────────────────────────

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

// Force immediate diagnostic log using PHP's error_log (always works on VPS)
error_log("[VPS-DIAG] vizard loaded | admin_id=$admin_id company_id=$company_id");

// ── Core helpers — defined first so they're available everywhere ──────────────
function vv_log($msg) {
    error_log('[VPS-VIZ] ' . $msg);
}
function vv_safe_fetch($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    if (!$r || $r === false) {
        vv_log("vv_safe_fetch FAILED: " . mysqli_error($conn) . " | SQL: " . substr($sql, 0, 200));
        return null;
    }
    return mysqli_fetch_assoc($r) ?: null;
}

$plan_row      = vv_safe_fetch($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1");
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// ── Company / role resolver ───────────────────────────────────────────────────
function vv_resolve_user($conn, $admin_id, $session_company_id) {
    $urow         = [];
    $_uq          = mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    if ($_uq)     $urow = mysqli_fetch_assoc($_uq) ?: [];
    $role         = $urow['role']         ?? 'Team Lead';
    $team_lead_id = (int)($urow['team_lead_id'] ?? 0);
    $owner_id     = ($role === 'Team Member' && $team_lead_id > 0) ? $team_lead_id : $admin_id;

    if ($session_company_id > 0) {
        $_dq  = mysqli_query($conn, "SELECT id, admin_id, companyname, company_type FROM hdb_companies WHERE id=$session_company_id LIMIT 1");
        $diag = $_dq ? (mysqli_fetch_assoc($_dq) ?: []) : [];
        vv_log("DIAG hdb_companies id=$session_company_id => " . json_encode($diag));
    }

    $co_sql = $session_company_id > 0
        ? "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id AND id=$session_company_id LIMIT 1"
        : "SELECT id, company_type FROM hdb_companies WHERE admin_id=$owner_id ORDER BY id ASC LIMIT 1";

    $_cq            = mysqli_query($conn, $co_sql);
    $co_row         = $_cq ? (mysqli_fetch_assoc($_cq) ?: null) : null;
    $company_type   = $co_row['company_type'] ?? '';
    $resolved_co_id = $co_row ? (int)$co_row['id'] : $session_company_id;

    vv_log("vv_resolve_user | admin_id=$admin_id session_co=$session_company_id"
         . " | role=$role team_lead_id=$team_lead_id owner_id=$owner_id"
         . " | co_row=" . json_encode($co_row)
         . " | company_type=[$company_type] resolved_co_id=$resolved_co_id");

    return [$owner_id, $resolved_co_id, $company_type, $role];
}

// ═════════════════════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

// ── Master video ideas (library) ──────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_video_ideas') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn, trim($_POST['niche_name']??''));
    $offset     = (int)($_POST['offset']??0);
    $limit      = 10;
    if (!$niche_name) { echo json_encode(['success'=>false,'ideas'=>[],'total'=>0]); exit; }
    $q = mysqli_query($conn,"SELECT id,video_idea FROM hdb_master_video_ideas WHERE niche_name='$niche_name' ORDER BY video_idea ASC LIMIT $limit OFFSET $offset");
    $ideas = [];
    while ($r = mysqli_fetch_assoc($q)) $ideas[] = ['id'=>(int)$r['id'],'video_idea'=>$r['video_idea']];
    $t = vv_safe_fetch($conn, "SELECT COUNT(*) cnt FROM hdb_master_video_ideas WHERE niche_name='$niche_name'")['cnt'] ?? 0;
    echo json_encode(['success'=>true,'ideas'=>$ideas,'total'=>(int)$t,'offset'=>$offset]); exit;
}

// ── Save user video idea ──────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_video_idea') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $niche_name = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    $video_idea = mysqli_real_escape_string($conn,trim($_POST['video_idea']??''));
    if (!$niche_name || !$video_idea) { echo json_encode(['success'=>false]); exit; }
    mysqli_query($conn,"INSERT IGNORE INTO hdb_user_video_ideas (admin_id,company_id,niche_id,category_id,niche_name,category_name,video_idea,is_ai_generated) VALUES ($owner_id,$co_id,0,0,'$niche_name','$niche_name','$video_idea',0)");
    echo json_encode(['success'=>true]); exit;
}

// ── Get user video ideas ──────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_video_ideas') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name    = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    $page          = (int)($_POST['page'] ?? 1);
    $limit         = 10;
    $offset        = ($page - 1) * $limit;
    if (empty($niche_name) || empty($category_name)) { echo json_encode(['success'=>false,'error'=>'Missing niche or category']); exit; }
    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    $myIdeas = []; $total_my = 0;
    $count_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM hdb_user_video_ideas WHERE niche_name='$niche_name' AND category_name='$category_name' AND admin_id=$owner_id AND company_id=$co_id");
    if ($count_q && $crow = mysqli_fetch_assoc($count_q)) $total_my = (int)$crow['total'];
    if ($total_my == 0) {
        $count_q2 = mysqli_query($conn,"SELECT COUNT(*) as total FROM hdb_user_video_ideas WHERE niche_name='$niche_name' AND category_name='$category_name' AND admin_id=$owner_id");
        if ($count_q2 && $crow2 = mysqli_fetch_assoc($count_q2)) $total_my = (int)$crow2['total'];
    }
    if ($total_my > 0) {
        $q = mysqli_query($conn,"SELECT id,video_idea,is_ai_generated,created_date FROM hdb_user_video_ideas WHERE niche_name='$niche_name' AND category_name='$category_name' AND admin_id=$owner_id ORDER BY created_date DESC LIMIT $offset,$limit");
        if (!$q || mysqli_num_rows($q) == 0)
            $q = mysqli_query($conn,"SELECT id,video_idea,is_ai_generated,created_date FROM hdb_user_video_ideas WHERE niche_name='$niche_name' AND category_name='$category_name' AND admin_id=$owner_id ORDER BY created_date DESC LIMIT $offset,$limit");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $myIdeas[] = $r['video_idea'];
    }
    echo json_encode(['success'=>true,'ideas'=>$myIdeas,'common_ideas'=>[],'used_titles'=>[],'total_my'=>count($myIdeas),'current_page'=>$page,'has_more'=>false]);
    exit;
}

// ── Include credit deduction helper ──────────────────────────────────────────
if (file_exists(__DIR__ . '/deduct_credit.php')) require_once __DIR__ . '/deduct_credit.php';

// ── AJAX: Deduct video credit ─────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'deduct_video_credit') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $amount      = (float)($_POST['amount']      ?? 1);
    $description = trim($_POST['description']    ?? 'Video generation');
    // Team Members deduct from Team Lead
    $_uq2 = mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $urow = $_uq2 ? (mysqli_fetch_assoc($_uq2) ?: []) : [];
    $role = $urow['role'] ?? 'Team Lead';
    $tl   = (int)($urow['team_lead_id'] ?? 0);
    $target_id = ($role === 'Team Member' && $tl > 0) ? $tl : $admin_id;
    if (function_exists('deduct_user_credit')) {
        $result = deduct_user_credit($conn, $target_id, $amount, $description);
        echo json_encode($result);
    } else {
        // Fallback inline deduction if deduct_credit.php not found
        $row = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$target_id LIMIT 1");
        $balance = (float)($row['credit_balance'] ?? 0);
        if ($balance < $amount) {
            echo json_encode(['success'=>false,'message'=>'Insufficient credits','new_balance'=>$balance]); exit;
        }
        $new_balance = round($balance - $amount, 4);
        mysqli_query($conn, "UPDATE hdb_users SET credit_balance=$new_balance WHERE id=$target_id");
        echo json_encode(['success'=>true,'new_balance'=>$new_balance,'message'=>'OK']);
    }
    exit;
}

// ── Get video quota ───────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_quota') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $_uq3 = mysqli_query($conn,"SELECT plan_type,role,team_lead_id,credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1");
    $urow = $_uq3 ? (mysqli_fetch_assoc($_uq3) ?: []) : [];
    $pt   = $urow['plan_type']     ?? 'free_trial';
    $role = $urow['role']          ?? 'Team Lead';
    $tl   = (int)($urow['team_lead_id'] ?? 0);
    if ($role === 'Team Member' && $tl > 0) {
        $crow = vv_safe_fetch($conn, "SELECT credit_balance FROM hdb_users WHERE id=$tl LIMIT 1");
        $credits = (int)($crow['credit_balance'] ?? 0);
    } else {
        $credits = (int)($urow['credit_balance'] ?? 0);
    }
    echo json_encode(['success'=>true,'credit_balance'=>$credits,'plan_type'=>$pt,'exceeded'=>($credits<=0)]); exit;
}

// ── AI video suggestions ──────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ai_video_suggestions') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name    = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    if (!$niche_name || !$category_name) { echo json_encode(['success'=>false,'error'=>'Missing niche or category']); exit; }
    require_once __DIR__ . '/config.php';
    $apiKey = $apiKey ?? $chatgpt_api_key ?? '';
    $prompt  = "You are a video content strategist. Generate 10 video TOPIC ideas for the \"$niche_name\" niche.\n\n";
    $prompt .= "A video topic is the SUBJECT of the video — NOT a hook, title, or opening line.\n";
    $prompt .= "The hook (how you open the video) is chosen separately.\n\n";
    $prompt .= "Rules:\n";
    $prompt .= "- State the subject plainly, like a search term or category label\n";
    $prompt .= "- NEVER start with: How to, Top, Best, Why, What, When, Did you know, Stop, Here is\n";
    $prompt .= "- NEVER use numbers at the start (5 ways, 3 tips, etc.)\n";
    $prompt .= "- NEVER use adjectives like Essential, Common, Simple, Quick\n";
    $prompt .= "- Good examples: \"Tax audit preparation\", \"Bookkeeping for freelancers\", \"Accounting software comparison\", \"Cash vs accrual accounting\", \"BAS lodgement process\"\n";
    $prompt .= "- Bad examples: \"How to prepare for a tax audit\", \"Top 5 bookkeeping tips\", \"Essential accounting software\"\n\n";
    $prompt .= "Return ONLY a valid JSON array of 10 strings. No markdown, no explanation.";
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','messages'=>[
            ['role'=>'system','content'=>'You are a viral short-form video content strategist. Return ONLY valid JSON arrays.'],
            ['role'=>'user','content'=>$prompt]
        ],'temperature'=>0.7,'max_tokens'=>800])
    ]);
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($httpCode == 200) {
        $data    = json_decode($response, true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');
        $content = preg_replace('/^```json\s*|\s*```$/s', '', $content);
        $items   = json_decode($content, true);
        if (is_array($items) && count($items) > 0) {
            $suggestions = array_slice($items, 0, 10);

            // ── Save all suggestions to hdb_user_video_ideas immediately ──────
            // Same pattern as hooks: save on generation, not on click.
            // Next visit loads from DB — AI never called again for this niche.
            [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
            $niche_id = 0;
            $nq = mysqli_query($conn, "SELECT id FROM hdb_user_niches WHERE niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
            if ($nq && $nr = mysqli_fetch_assoc($nq)) $niche_id = (int)$nr['id'];
            $cat_id = 0;
            $cq = mysqli_query($conn, "SELECT id FROM hdb_user_categories WHERE category_name='$category_name' AND niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
            if ($cq && $cr = mysqli_fetch_assoc($cq)) $cat_id = (int)$cr['id'];

            foreach ($suggestions as $idea) {
                $ie = mysqli_real_escape_string($conn, trim($idea));
                if (!$ie) continue;
                // INSERT IGNORE — skips exact duplicates silently
                mysqli_query($conn,
                    "INSERT IGNORE INTO hdb_user_video_ideas
                        (admin_id, company_id, niche_id, category_id, niche_name, category_name, video_idea, is_ai_generated)
                     VALUES
                        ($owner_id, $co_id, $niche_id, $cat_id, '$niche_name', '$category_name', '$ie', 1)");
            }
            vv_log("get_ai_video_suggestions: saved " . count($suggestions) . " ideas for niche=$niche_name admin=$owner_id");

            echo json_encode(['success'=>true,'suggestions'=>$suggestions]);
        }
        else echo json_encode(['success'=>false,'error'=>'Invalid AI response']);
    } else {
        $err = json_decode((string)$response, true);
        echo json_encode(['success'=>false,'error'=>$err['error']['message'] ?? "HTTP $httpCode"]);
    }
    exit;
}

// ── Save video idea ───────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_video_idea') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    $niche_name    = mysqli_real_escape_string($conn,trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn,trim($_POST['category_name'] ?? ''));
    $video_idea    = mysqli_real_escape_string($conn,trim($_POST['video_idea']    ?? ''));
    $is_ai         = (int)($_POST['is_ai_generated'] ?? 0);
    if (!$video_idea) { echo json_encode(['success'=>false]); exit; }
    $niche_id = 0;
    $nq = mysqli_query($conn,"SELECT id FROM hdb_user_niches WHERE niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
    if ($nq && $nr = mysqli_fetch_assoc($nq)) $niche_id = (int)$nr['id'];
    $cat_id = 0;
    $cq = mysqli_query($conn,"SELECT id FROM hdb_user_categories WHERE category_name='$category_name' AND niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
    if ($cq && $cr = mysqli_fetch_assoc($cq)) $cat_id = (int)$cr['id'];
    $store_as_common = (int)($_POST['store_as_common'] ?? 0);
    if ($store_as_common && $is_ai) {
        $exists = vv_safe_fetch($conn, "SELECT id FROM hdb_user_video_ideas WHERE admin_id=0 AND niche_name='$niche_name' AND category_name='$category_name' AND video_idea='$video_idea' LIMIT 1");
        if (!$exists) mysqli_query($conn,"INSERT INTO hdb_user_video_ideas (admin_id,company_id,niche_id,category_id,niche_name,category_name,video_idea,is_ai_generated) VALUES (0,0,COALESCE($niche_id,0),COALESCE($cat_id,0),'$niche_name','$category_name','$video_idea',1)");
    } else {
        $exists = vv_safe_fetch($conn, "SELECT id FROM hdb_user_video_ideas WHERE admin_id=$owner_id AND company_id=$co_id AND niche_name='$niche_name' AND category_name='$category_name' AND video_idea='$video_idea' LIMIT 1");
        if (!$exists) mysqli_query($conn,"INSERT INTO hdb_user_video_ideas (admin_id,company_id,niche_id,category_id,niche_name,category_name,video_idea,is_ai_generated) VALUES ($owner_id,$co_id,COALESCE($niche_id,0),COALESCE($cat_id,0),'$niche_name','$category_name','$video_idea',$is_ai)");
    }
    echo json_encode(['success'=>true]); exit;
}

// ── User industries ───────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_industries') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $q = mysqli_query($conn,"SELECT ui.industry_id, mi.industry_desc, mi.core_group FROM hdb_user_groups ui JOIN hdb_master_groups mi ON mi.id=ui.industry_id WHERE ui.admin_id=$owner_id ORDER BY ui.created_date DESC LIMIT 50");
    $rows=[];
    while ($r=mysqli_fetch_assoc($q)) $rows[]=['id'=>(int)$r['industry_id'],'industry_desc'=>$r['industry_desc'],'core_group'=>$r['core_group']??''];
    echo json_encode(['success'=>true,'industries'=>$rows]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_industry') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $iid = (int)($_POST['industry_id']??0);
    if (!$iid) { echo json_encode(['success'=>false]); exit; }
    mysqli_query($conn,"INSERT IGNORE INTO hdb_user_groups (admin_id,company_id,niche_name,industry_id,is_ai_generated) SELECT $owner_id,$co_id,industry_desc,id,0 FROM hdb_master_groups WHERE id=$iid LIMIT 1");
    echo json_encode(['success'=>true]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_industry_id') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $ind = mysqli_real_escape_string($conn,$_POST['industry_desc']??'');
    $q   = mysqli_query($conn,"SELECT id, core_group FROM hdb_master_groups WHERE industry_desc='$ind' LIMIT 1");
    $row = mysqli_fetch_assoc($q);
    echo json_encode(['success'=>true,'id'=>$row?(int)$row['id']:0,'core_group'=>$row?($row['core_group']??''):'']); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_industries') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    // Return unique core_groups from hdb_master_groups
    $q = mysqli_query($conn,
        "SELECT DISTINCT core_group FROM hdb_master_groups WHERE core_group IS NOT NULL AND core_group != '' ORDER BY core_group ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = ['core_group' => $r['core_group']];
    }
    echo json_encode(['success'=>true,'groups'=>$rows,'total'=>count($rows)]); exit;
}
// ── Sub-groups: industry_desc rows for a given core_group ─────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_subgroups') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $cg = mysqli_real_escape_string($conn, trim($_POST['core_group'] ?? ''));
    if (!$cg) { echo json_encode(['success'=>false,'error'=>'Missing core_group']); exit; }
    $q = mysqli_query($conn,
        "SELECT id, industry_desc FROM hdb_master_groups WHERE core_group='$cg' ORDER BY industry_desc ASC");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = ['id'=>(int)$r['id'], 'industry_desc'=>$r['industry_desc']];
    }
    echo json_encode(['success'=>true,'subgroups'=>$rows,'total'=>count($rows)]); exit;
}

// ── Master niches ─────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_master_niches') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    // Accept single industry_id OR comma-separated industry_ids (core_group may map to many rows)
    $raw_ids = trim($_POST['industry_ids'] ?? $_POST['industry_id'] ?? '');
    $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
    $offset = (int)($_POST['offset'] ?? 0);
    $limit  = (int)($_POST['limit']  ?? 50);
    if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'Missing industry_id']); exit; }
    $ids_sql = implode(',', $ids);
    $q = mysqli_query($conn,
        "SELECT id, niche_desc FROM hdb_master_niches
         WHERE master_industry_id IN ($ids_sql)
         ORDER BY niche_desc ASC LIMIT $limit OFFSET $offset");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = ['id'=>(int)$r['id'], 'niche_desc'=>$r['niche_desc']];
    $t = vv_safe_fetch($conn, "SELECT COUNT(*) cnt FROM hdb_master_niches WHERE master_industry_id IN ($ids_sql)")['cnt'] ?? 0;
    echo json_encode(['success'=>true,'niches'=>$rows,'total'=>(int)$t,'offset'=>$offset]); exit;
}

// ── User niches ───────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_niches') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    if ($company_type !== 'internal') {
        $q = mysqli_query($conn,"SELECT niche_name FROM hdb_user_niches WHERE admin_id=$owner_id AND company_id=$co_id AND is_ai_generated=0 ORDER BY created_date DESC LIMIT 50");
        $niches=[];
        while ($r=mysqli_fetch_assoc($q)) $niches[]=$r['niche_name'];
        echo json_encode(['success'=>true,'niches'=>$niches,'common_niches'=>[],'is_internal'=>false]); exit;
    }
    $q  = mysqli_query($conn,"SELECT niche_name FROM hdb_user_niches WHERE admin_id=$admin_id ORDER BY created_date DESC LIMIT 20");
    $q2 = mysqli_query($conn,"SELECT niche_name FROM hdb_user_niches WHERE admin_id=0 ORDER BY niche_name ASC LIMIT 50");
    $myNiches=[]; while ($r=mysqli_fetch_assoc($q))  $myNiches[]=$r['niche_name'];
    $commonNiches=[]; while ($r=mysqli_fetch_assoc($q2)) $commonNiches[]=$r['niche_name'];
    echo json_encode(['success'=>true,'niches'=>$myNiches,'common_niches'=>$commonNiches,'is_internal'=>true]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_niche') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    $niche_name      = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    $is_ai           = (int)($_POST['is_ai_generated']??0);
    $store_as_common = (int)($_POST['store_as_common']??0);
    if (!$niche_name) { echo json_encode(['success'=>false]); exit; }
    if ($store_as_common && $is_ai) {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_niches WHERE admin_id=0 AND niche_name='$niche_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_niches (admin_id,company_id,niche_name,is_ai_generated) VALUES (0,0,'$niche_name',1)");
    } elseif ($company_type !== 'internal') {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_niches WHERE admin_id=$owner_id AND company_id=$co_id AND niche_name='$niche_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_niches (admin_id,company_id,niche_name,is_ai_generated) VALUES ($owner_id,$co_id,'$niche_name',0)");
    } else {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_niches WHERE admin_id=$admin_id AND niche_name='$niche_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_niches (admin_id,company_id,niche_name,is_ai_generated) VALUES ($admin_id,$co_id,'$niche_name',$is_ai)");
    }
    echo json_encode(['success'=>true]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_niche') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    if ($niche_name) {
        $niche_ids=[];
        $q1=mysqli_query($conn,"SELECT id FROM hdb_user_niches WHERE admin_id=$admin_id AND niche_name='$niche_name'");
        while ($r=mysqli_fetch_assoc($q1)) $niche_ids[]=(int)$r['id'];
        $q2=mysqli_query($conn,"SELECT id FROM hdb_user_niches WHERE admin_id=0 AND niche_name='$niche_name'");
        while ($r=mysqli_fetch_assoc($q2)) $niche_ids[]=(int)$r['id'];
        if (!empty($niche_ids)) {
            $ids=implode(',',$niche_ids);
            mysqli_query($conn,"DELETE FROM hdb_user_categories WHERE niche_id IN ($ids)");
            mysqli_query($conn,"DELETE FROM hdb_user_video_ideas WHERE niche_id IN ($ids)");
        }
        mysqli_query($conn,"DELETE FROM hdb_user_niches WHERE admin_id=$admin_id AND niche_name='$niche_name'");
    }
    echo json_encode(['success'=>true]); exit;
}

// ── User categories ───────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_category') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name    = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    $category_name = mysqli_real_escape_string($conn,trim($_POST['category_name']??''));
    $is_ai         = (int)($_POST['is_ai_generated']??0);
    $store_as_common = (int)($_POST['store_as_common']??0);
    if (!$category_name || !$niche_name) { echo json_encode(['success'=>false]); exit; }
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    $niche_id=0;
    $nq=mysqli_query($conn,"SELECT id FROM hdb_user_niches WHERE niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
    if ($nq && $nr=mysqli_fetch_assoc($nq)) $niche_id=(int)$nr['id'];
    if ($company_type !== 'internal') {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_categories WHERE admin_id=$owner_id AND company_id=$co_id AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_categories (admin_id,company_id,niche_id,niche_name,category_name,is_ai_generated) VALUES ($owner_id,$co_id,$niche_id,'$niche_name','$category_name',0)");
    } elseif ($store_as_common && $is_ai) {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_categories WHERE admin_id=0 AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_categories (admin_id,company_id,niche_id,niche_name,category_name,is_ai_generated) VALUES (0,0,$niche_id,'$niche_name','$category_name',1)");
    } else {
        $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_categories WHERE admin_id=$admin_id AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1");
        if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_categories (admin_id,company_id,niche_id,niche_name,category_name,is_ai_generated) VALUES ($admin_id,$co_id,$niche_id,'$niche_name','$category_name',$is_ai)");
    }
    echo json_encode(['success'=>true]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_categories') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    $myCategories=[]; $commonCategories=[];
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    if ($niche_name) {
        if ($company_type !== 'internal') {
            $q=mysqli_query($conn,"SELECT category_name FROM hdb_user_categories WHERE admin_id=$owner_id AND company_id=$co_id AND niche_name='$niche_name' AND is_ai_generated=0 ORDER BY created_date DESC LIMIT 50");
            while ($r=mysqli_fetch_assoc($q)) $myCategories[]=$r['category_name'];
        } else {
            $q  = mysqli_query($conn,"SELECT category_name FROM hdb_user_categories WHERE admin_id=$admin_id AND niche_name='$niche_name' ORDER BY created_date DESC LIMIT 20");
            $q2 = mysqli_query($conn,"SELECT category_name FROM hdb_user_categories WHERE admin_id=0 AND niche_name='$niche_name' ORDER BY category_name ASC LIMIT 30");
            while ($r=mysqli_fetch_assoc($q))  $myCategories[]=$r['category_name'];
            while ($r=mysqli_fetch_assoc($q2)) $commonCategories[]=$r['category_name'];
        }
    }
    echo json_encode(['success'=>true,'categories'=>$myCategories,'common_categories'=>$commonCategories]); exit;
}

// ── Angles ────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_angles') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    [$owner_id,$co_id,$company_type,$role] = vv_resolve_user($conn,$admin_id,$company_id);
    $my=[]; $common=[];
    $q  = mysqli_query($conn,"SELECT angle_name FROM hdb_user_angles WHERE admin_id=$admin_id AND niche_name='$niche_name' ORDER BY created_date DESC LIMIT 20");
    $q2 = mysqli_query($conn,"SELECT angle_name FROM hdb_user_angles WHERE admin_id=0 ORDER BY angle_name ASC LIMIT 50");
    while ($r=mysqli_fetch_assoc($q))  $my[]=$r['angle_name'];
    while ($r=mysqli_fetch_assoc($q2)) $common[]=$r['angle_name'];
    echo json_encode(['success'=>true,'my_angles'=>$my,'common_angles'=>$common]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_angle') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $angle_name = mysqli_real_escape_string($conn,trim($_POST['angle_name']??''));
    $niche_name = mysqli_real_escape_string($conn,trim($_POST['niche_name']??''));
    $is_ai      = (int)($_POST['is_ai_generated']??0);
    $store_as_common = (int)($_POST['store_as_common']??0);
    if (!$angle_name) { echo json_encode(['success'=>false]); exit; }
    $target_admin = ($store_as_common && $is_ai) ? 0 : $admin_id;
    $e = vv_safe_fetch($conn, "SELECT id FROM hdb_user_angles WHERE admin_id=$target_admin AND angle_name='$angle_name' LIMIT 1");
    if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_angles (admin_id,niche_name,angle_name,is_ai_generated) VALUES ($target_admin,'$niche_name','$angle_name',$is_ai)");
    echo json_encode(['success'=>true]); exit;
}

// ── Preview voice — generate a short TTS sample on demand ────────────────────
// Reads voice_text from hdb_voices for the selected language, calls OpenAI TTS,
// returns base64-encoded MP3 so the browser can play it without saving a file.
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'preview_voice') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    require_once __DIR__ . '/config.php';
    $apiKey   = isset($apiKey) ? $apiKey : (isset($chatgpt_api_key) ? $chatgpt_api_key : '');
    $voice_id = trim($_POST['voice_id'] ?? '');
    $language = trim($_POST['language'] ?? 'English');

    if (!$voice_id) { echo json_encode(['success'=>false,'error'=>'No voice selected']); exit; }

    // Extract OpenAI voice name from voice_id (e.g. "openai:alloy" → "alloy")
    $openai_voice = strtolower(preg_replace('/^openai:/i', '', $voice_id));

    // ── Language → lang_code map ──────────────────────────────────────────────
    $lang_code_map = [
        'english'=>'en','arabic'=>'ar','spanish'=>'es','french'=>'fr','urdu'=>'ur',
        'hindi'=>'hi','gujarati'=>'gu','punjabi'=>'pa','tamil'=>'ta',
        'mandarin chinese'=>'zh','mandarin'=>'zh','farsi'=>'fa','bengali'=>'bn',
        'portuguese'=>'pt','russian'=>'ru','japanese'=>'ja','korean'=>'ko',
        'german'=>'de','dutch'=>'nl','turkish'=>'tr','polish'=>'pl',
        'romanian'=>'ro','czech'=>'cs','slovak'=>'sk','hungarian'=>'hu',
        'swedish'=>'sv','norwegian'=>'no','danish'=>'da','finnish'=>'fi',
        'bulgarian'=>'bg','croatian'=>'hr','serbian'=>'sr','ukrainian'=>'uk',
        'albanian'=>'sq','greek'=>'el','slovenian'=>'sl','italian'=>'it',
    ];
    $lc     = $lang_code_map[strtolower(trim($language))] ?? 'en';
    $lc_esc = mysqli_real_escape_string($conn, $lc);

    // ── Build a safe filename key: e.g. "alloy_en.mp3" ───────────────────────
    $safe_voice_key  = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($openai_voice));
    $safe_lang       = preg_replace('/[^a-z0-9]/', '_', strtolower($lc));
    $sample_filename = $safe_voice_key . '_' . $safe_lang . '.mp3';

    // ── voice_samples folder (relative to this file's directory) ─────────────
    $samples_dir = __DIR__ . '/voice_samples';
    if (!is_dir($samples_dir)) {
        mkdir($samples_dir, 0755, true);
    }
    $sample_filepath = $samples_dir . '/' . $sample_filename;
    // Web-accessible URL (one directory up from __DIR__ would need adjustment;
    // keep it relative to the web root — adjust base URL to match your setup)
    $sample_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/voice_samples/' . $sample_filename;

    // ── 1. Check if sample already exists in voice_samples folder ────────────
    //    Also verify the DB column sample_voice is populated for this voice+lang
    $db_voice_key_esc = mysqli_real_escape_string($conn, $openai_voice);
    $_dbq = mysqli_query($conn,
        "SELECT id, sample_voice FROM hdb_voices
         WHERE (voice_key='$db_voice_key_esc' OR voice_key='openai:$db_voice_key_esc')
           AND lang_code='$lc_esc'
         LIMIT 1");
    $_dbr = $_dbq ? mysqli_fetch_assoc($_dbq) : null;

    if (file_exists($sample_filepath)) {
        // File exists on disk — serve it directly as base64
        $audio_bytes = file_get_contents($sample_filepath);
        if ($audio_bytes !== false && strlen($audio_bytes) > 0) {
            // Make sure DB column is also set (repair if missing)
            if ($_dbr && empty($_dbr['sample_voice'])) {
                $sv_esc  = mysqli_real_escape_string($conn, $sample_filename);
                $row_id  = (int)$_dbr['id'];
                mysqli_query($conn, "UPDATE hdb_voices SET sample_voice='$sv_esc' WHERE id=$row_id");
                vv_log("preview_voice cache-hit DB repair | voice=$openai_voice lc=$lc file=$sample_filename");
            }
            vv_log("preview_voice cache-hit | voice=$openai_voice lc=$lc file=$sample_filename bytes=" . strlen($audio_bytes));
            echo json_encode([
                'success' => true,
                'audio'   => base64_encode($audio_bytes),
                'mime'    => 'audio/mpeg',
                'cached'  => true,
            ]);
            exit;
        }
        // File exists but is corrupt/empty — fall through to regenerate
        vv_log("preview_voice cache-invalid (empty file) | file=$sample_filename — regenerating");
        @unlink($sample_filepath);
    }

    // ── 2. File not found — get sample text for TTS ───────────────────────────
    $sample_text = "Hi there! This is a quick sample so you can hear how this voice sounds before you choose.";
    $_vq = mysqli_query($conn, "SELECT voice_text FROM hdb_voices WHERE lang_code='$lc_esc' AND voice_text != '' LIMIT 1");
    if ($_vq && $_vr = mysqli_fetch_assoc($_vq)) {
        if (!empty($_vr['voice_text'])) $sample_text = $_vr['voice_text'];
    } else {
        $_vq2 = mysqli_query($conn, "SELECT voice_text FROM hdb_voices WHERE lang_code='en' AND voice_text != '' LIMIT 1");
        if ($_vq2 && $_vr2 = mysqli_fetch_assoc($_vq2)) {
            if (!empty($_vr2['voice_text'])) $sample_text = $_vr2['voice_text'];
        }
    }
    vv_log("preview_voice generating | voice=$openai_voice lang=$language lc=$lc text=" . substr($sample_text, 0, 60));

    // ── 3. Call OpenAI TTS ────────────────────────────────────────────────────
    $payload = json_encode([
        'model'           => 'tts-1',
        'input'           => $sample_text,
        'voice'           => $openai_voice,
        'response_format' => 'mp3',
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $audio    = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        vv_log("preview_voice curl error: $curlErr");
        echo json_encode(['success'=>false,'error'=>'Network error: '.$curlErr]); exit;
    }
    if ($httpCode !== 200) {
        $errData = json_decode((string)$audio, true);
        $msg = $errData['error']['message'] ?? "TTS failed (HTTP $httpCode)";
        vv_log("preview_voice API error: $msg");
        echo json_encode(['success'=>false,'error'=>$msg]); exit;
    }

    // ── 4. Save to voice_samples folder ──────────────────────────────────────
    $written = file_put_contents($sample_filepath, $audio);
    if ($written !== false && $written > 0) {
        vv_log("preview_voice saved | file=$sample_filename bytes=$written");
        // ── 5. Update sample_voice column in hdb_voices ───────────────────────
        if ($_dbr) {
            $sv_esc = mysqli_real_escape_string($conn, $sample_filename);
            $row_id = (int)$_dbr['id'];
            mysqli_query($conn, "UPDATE hdb_voices SET sample_voice='$sv_esc' WHERE id=$row_id");
            vv_log("preview_voice DB updated | id=$row_id sample_voice=$sample_filename");
        } else {
            // No matching row found — log it (insert not attempted as we don't have full row data)
            vv_log("preview_voice DB skip — no hdb_voices row matched voice_key=$openai_voice lc=$lc");
        }
    } else {
        vv_log("preview_voice WARNING — could not save file: $sample_filepath");
    }

    vv_log("preview_voice OK | voice=$openai_voice lang=$language bytes=" . strlen($audio));
    echo json_encode([
        'success' => true,
        'audio'   => base64_encode($audio),
        'mime'    => 'audio/mpeg',
        'cached'  => false,
    ]);
    exit;
}

// ── Hooks ─────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_hooks') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $q = mysqli_query($conn,"SELECT hook_name,hook_type FROM hdb_hooks ORDER BY hook_type,hook_name ASC");
    $groups=[];
    while ($r=mysqli_fetch_assoc($q)) {
        $t=$r['hook_type']??'general';
        if (!isset($groups[$t])) $groups[$t]=[];
        $groups[$t][]=['name'=>$r['hook_name'],'type'=>$t];
    }
    echo json_encode(['success'=>true,'groups'=>$groups]); exit;
}

// ── Get saved hooks for user + niche (all rows) ──────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_saved_hook') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    $niche = mysqli_real_escape_string($conn, trim($_POST['niche'] ?? ''));
    if (!$niche) { echo json_encode(['success' => false, 'hooks' => []]); exit; }

    $q    = mysqli_query($conn,
        "SELECT hook_text, hook_type FROM hdb_user_hooks
         WHERE admin_id=$owner_id AND niche_name='$niche'
         ORDER BY created_date DESC LIMIT 20");
    $hooks = [];
    if ($q) while ($row = mysqli_fetch_assoc($q)) {
        $hooks[] = ['hook_text' => $row['hook_text'], 'hook_type' => $row['hook_type']];
    }
    vv_log("get_saved_hook | admin=$owner_id niche='$niche' found=" . count($hooks));
    echo json_encode(['success' => true, 'hooks' => $hooks, 'has_saved' => count($hooks) > 0]);
    exit;
}

// ── Save hook(s) for user + niche ─────────────────────────────────────────────
// Accepts single hook (hook_text) or batch (hooks = JSON array of {hook_text, hook_type})
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_hook') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    $niche = mysqli_real_escape_string($conn, trim($_POST['niche'] ?? ''));
    if (!$niche) { echo json_encode(['success' => false, 'error' => 'missing niche']); exit; }

    // Build list of hooks to save — batch or single
    $to_save = [];
    if (!empty($_POST['hooks'])) {
        $batch = json_decode($_POST['hooks'], true);
        if (is_array($batch)) {
            foreach ($batch as $h) {
                $ht = trim($h['hook_text'] ?? '');
                $ty = trim($h['hook_type'] ?? 'ai');
                if ($ht) $to_save[] = ['text' => $ht, 'type' => $ty];
            }
        }
    } else {
        $ht = trim($_POST['hook_text'] ?? '');
        $ty = trim($_POST['hook_type'] ?? 'custom');
        if ($ht) $to_save[] = ['text' => $ht, 'type' => $ty];
    }

    if (empty($to_save)) {
        vv_log("save_user_hook SKIPPED — nothing to save for niche='$niche'");
        echo json_encode(['success' => false, 'error' => 'no hooks provided']); exit;
    }

    $saved = 0;
    foreach ($to_save as $h) {
        $hook_text = mysqli_real_escape_string($conn, $h['text']);
        $hook_type = mysqli_real_escape_string($conn, $h['type']);
        // INSERT IGNORE: keeps all unique hooks, skips exact duplicates
        $ok = mysqli_query($conn,
            "INSERT IGNORE INTO hdb_user_hooks (admin_id, company_id, niche_name, hook_text, hook_type)
             VALUES ($owner_id, $co_id, '$niche', '$hook_text', '$hook_type')");
        if ($ok) $saved++;
    }
    vv_log("save_user_hook | admin=$owner_id niche='$niche' saved=$saved of " . count($to_save));
    echo json_encode(['success' => true, 'saved' => $saved]); exit;
}

// ── AI hook recommendations ───────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ai_hook_recommendations') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $video_idea = mysqli_real_escape_string($conn, trim($_POST['video_idea'] ?? ''));
    $niche      = mysqli_real_escape_string($conn, trim($_POST['niche']      ?? ''));
    if (!$video_idea) { echo json_encode(['success'=>false,'error'=>'Missing video_idea']); exit; }

    // ── Auto-create cache table if missing ────────────────────────────────────
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS hdb_ai_hook_cache (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        company_id    INT NOT NULL DEFAULT 0,
        video_idea    VARCHAR(500) NOT NULL,
        niche         VARCHAR(200) NOT NULL DEFAULT '',
        recommendations JSON NOT NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lookup (company_id, video_idea(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Check cache first ─────────────────────────────────────────────────────
    $cache_q = mysqli_query($conn,
        "SELECT recommendations FROM hdb_ai_hook_cache
         WHERE company_id=$company_id AND video_idea='$video_idea'
         ORDER BY created_at DESC LIMIT 1");
    if ($cache_q && $cache_row = mysqli_fetch_assoc($cache_q)) {
        $cached = json_decode($cache_row['recommendations'], true);
        if (is_array($cached) && count($cached) > 0) {
            echo json_encode(['success'=>true,'recommendations'=>$cached,'cached'=>true]);
            exit;
        }
    }

    // ── Call AI ───────────────────────────────────────────────────────────────
    require_once __DIR__ . '/config.php';
    $apiKey = $apiKey ?? $chatgpt_api_key ?? '';
    $prompt = "You are an expert short-form video strategist. For the video idea: \"$video_idea\" in the \"$niche\" niche, pick the top 5 hook types and adapt them. Return ONLY a valid JSON array of objects: [{\"hook_name\":\"...\",\"hook_type\":\"...\",\"adapted_hook\":\"...\",\"why\":\"...\"}]";
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','messages'=>[
            ['role'=>'system','content'=>'You are a viral short-form video content strategist. Return ONLY valid JSON.'],
            ['role'=>'user','content'=>$prompt]
        ],'temperature'=>0.7,'max_tokens'=>800])
    ]);
    $response = curl_exec($ch); $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($httpCode == 200) {
        $data    = json_decode($response,true);
        $content = trim($data['choices'][0]['message']['content']??'');
        $content = preg_replace('/^```json\s*|\s*```$/s','',$content);
        $items   = json_decode($content,true);
        if (is_array($items) && count($items) > 0) {
            $recs = array_slice($items, 0, 5);
            // ── Save to cache ─────────────────────────────────────────────────
            $recs_json = mysqli_real_escape_string($conn, json_encode($recs));
            mysqli_query($conn,
                "INSERT INTO hdb_ai_hook_cache (company_id, video_idea, niche, recommendations)
                 VALUES ($company_id, '$video_idea', '$niche', '$recs_json')");
            echo json_encode(['success'=>true,'recommendations'=>$recs,'cached'=>false]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Invalid AI response format']);
        }
    } else {
        $err = json_decode((string)$response,true);
        echo json_encode(['success'=>false,'error'=>$err['error']['message']??"HTTP $httpCode"]);
    }
    exit;
}

// ── CTAs ──────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_cta') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $q = mysqli_query($conn,"SELECT cta_text FROM hdb_user_cta WHERE company_id=$co_id ORDER BY created_date DESC LIMIT 30");
    $ctas=[]; while ($r=mysqli_fetch_assoc($q)) $ctas[]=$r['cta_text'];
    echo json_encode(['success'=>true,'ctas'=>$ctas]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_cta') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $cta_text = mysqli_real_escape_string($conn,trim($_POST['cta_text']??''));
    if (!$cta_text) { echo json_encode(['success'=>false]); exit; }
    $e=vv_safe_fetch($conn, "SELECT id FROM hdb_user_cta WHERE company_id=$co_id AND cta_text='$cta_text' LIMIT 1");
    if (!$e) mysqli_query($conn,"INSERT INTO hdb_user_cta (admin_id,company_id,cta_text) VALUES ($admin_id,$co_id,'$cta_text')");
    echo json_encode(['success'=>true]); exit;
}
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ctas_separated') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    [$owner_id,$co_id] = vv_resolve_user($conn,$admin_id,$company_id);
    $q1 = mysqli_query($conn,"SELECT cta_text FROM hdb_user_cta WHERE company_id=$co_id ORDER BY created_date DESC LIMIT 30");
    $q2 = mysqli_query($conn,"SELECT cta_text FROM hdb_user_cta WHERE company_id=0 ORDER BY created_date DESC LIMIT 30");
    $company_ctas=[]; while ($r=mysqli_fetch_assoc($q1)) $company_ctas[]=$r['cta_text'];
    $global_ctas=[]; while ($r=mysqli_fetch_assoc($q2)) $global_ctas[]=$r['cta_text'];
    echo json_encode(['success'=>true,'company_ctas'=>$company_ctas,'global_ctas'=>$global_ctas]); exit;
}

// ── PHP data for JS ───────────────────────────────────────────────────────────
$php_audiences = [];
$aud_res = mysqli_query($conn,"SELECT audience_type FROM hdb_target_audience ORDER BY id ASC");
if ($aud_res) while ($r=mysqli_fetch_assoc($aud_res)) $php_audiences[]=$r['audience_type'];
if (empty($php_audiences)) $php_audiences=['General Public','Business Owners','Professionals','Beginners'];

$php_tones = [];
$tone_res = mysqli_query($conn,"SELECT tone_type FROM hdb_tone ORDER BY id ASC");
if ($tone_res) while ($r=mysqli_fetch_assoc($tone_res)) $php_tones[]=$r['tone_type'];
if (empty($php_tones)) $php_tones=['Friendly','Professional','Inspirational','Humorous','Casual'];

$php_company_lang  = 'English';
$php_brand_name    = '';
$php_co_group      = '';
$php_co_subgroup   = '';
$php_co_niche      = '';

// ── Ensure group_name/subgroup_name/niche columns exist in hdb_companies ────
$_have_cols = false;
$_col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_companies LIKE 'group_name'");
if ($_col_check && mysqli_num_rows($_col_check) === 0) {
    mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN group_name    VARCHAR(120) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN subgroup_name VARCHAR(120) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE hdb_companies ADD COLUMN niche         VARCHAR(120) DEFAULT NULL");
    vv_log("VPS: Added group_name/subgroup_name/niche columns to hdb_companies");
} else {
    $_have_cols = true;
}

// Resolve the company row — prefer session company_id, fall back to owner's first company
$_co_sql = $company_id > 0
    ? "SELECT language,companyname,group_name,subgroup_name,niche FROM hdb_companies WHERE id=$company_id LIMIT 1"
    : "SELECT language,companyname,group_name,subgroup_name,niche FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1";
$_co_res = mysqli_query($conn, $_co_sql);
if (!$_co_res) {
    vv_log("VPS company query failed: " . mysqli_error($conn) . " | SQL: $_co_sql");
} else {
    $_co_row = mysqli_fetch_assoc($_co_res);
    vv_log("VPS company row: " . json_encode($_co_row) . " | company_id=$company_id admin_id=$admin_id");
    if ($_co_row) {
        if (!empty($_co_row['language']))       $php_company_lang = $_co_row['language'];
        if (!empty($_co_row['companyname']))    $php_brand_name   = $_co_row['companyname'];
        if (!empty($_co_row['group_name']))     $php_co_group     = $_co_row['group_name'];
        if (!empty($_co_row['subgroup_name']))  $php_co_subgroup  = $_co_row['subgroup_name'];
        if (!empty($_co_row['niche']))          $php_co_niche     = $_co_row['niche'];
    }
    vv_log("VPS coIndustry: group=[$php_co_group] subgroup=[$php_co_subgroup] niche=[$php_co_niche]");
}
$js_is_free_trial = $is_free_trial ? 'true' : 'false';

// ── Get video ideas for company profile (group + subgroup + niche) ───────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_company_video_ideas') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    mysqli_report(MYSQLI_REPORT_OFF); // ensure no fatal exceptions from failed queries
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    $niche_name = mysqli_real_escape_string($conn, trim($_POST['niche_name'] ?? ''));
    $industry   = mysqli_real_escape_string($conn, trim($_POST['industry']   ?? ''));
    $page       = max(1, (int)($_POST['page'] ?? 1));
    $limit      = 12;
    $offset     = ($page - 1) * $limit;

    $ideas = [];

    // 1. User's own saved ideas for this niche
    if ($niche_name) {
        $q = mysqli_query($conn,
            "SELECT video_idea FROM hdb_user_video_ideas
             WHERE admin_id=$owner_id AND niche_name='$niche_name'
             ORDER BY created_date DESC LIMIT $limit OFFSET $offset");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $ideas[] = $r['video_idea'];
        else vv_log("get_company_video_ideas user query error: " . mysqli_error($conn));
    }

    // 2. Pad with master ideas if not enough
    if (count($ideas) < $limit && $niche_name) {
        $need = $limit - count($ideas);
        $existing = implode("','", array_map(function($i) use ($conn){ return mysqli_real_escape_string($conn,$i); }, $ideas));
        $where_not = $existing ? "AND video_idea NOT IN ('$existing')" : '';
        $q2 = mysqli_query($conn,
            "SELECT video_idea FROM hdb_master_video_ideas
             WHERE niche_name='$niche_name' $where_not
             ORDER BY video_idea ASC LIMIT $need OFFSET $offset");
        if ($q2) while ($r = mysqli_fetch_assoc($q2)) $ideas[] = $r['video_idea'];
        else vv_log("get_company_video_ideas master query error: " . mysqli_error($conn));
    }

    // 3. Fallback: AI-generate ideas specific to this niche
    if (empty($ideas) && $niche_name) {
        require_once __DIR__ . '/config.php';
        $apiKey_fb = $apiKey ?? $chatgpt_api_key ?? '';
        if ($apiKey_fb) {
            $fb_prompt = "Generate 10 short video topic ideas for the \"$niche_name\" niche.\n"
                       . "Rules: plain subject labels only, no How to/Top/Best/Why/What/Numbers at start.\n"
                       . "Return ONLY a JSON array of 10 strings.";
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>20,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey_fb],
                CURLOPT_POSTFIELDS=>json_encode(['model'=>'gpt-4o-mini','messages'=>[
                    ['role'=>'system','content'=>'Return ONLY a valid JSON array of strings.'],
                    ['role'=>'user','content'=>$fb_prompt]
                ],'temperature'=>0.7,'max_tokens'=>400])
            ]);
            $fb_resp = curl_exec($ch); curl_close($ch);
            if ($fb_resp) {
                $fb_data  = json_decode($fb_resp, true);
                $fb_raw   = trim($fb_data['choices'][0]['message']['content'] ?? '');
                $fb_raw   = preg_replace('/^```json\s*|\s*```$/s', '', $fb_raw);
                $fb_items = json_decode($fb_raw, true);
                if (is_array($fb_items)) {
                    foreach (array_slice($fb_items, 0, $limit) as $idea) {
                        $ideas[] = trim($idea);
                        $ie = mysqli_real_escape_string($conn, trim($idea));
                        if ($ie) mysqli_query($conn,
                            "INSERT IGNORE INTO hdb_master_video_ideas
                             (admin_id,company_id,niche_id,category_id,niche_name,category_name,video_idea,is_ai_generated)
                             VALUES (0,0,0,0,'$niche_name','$niche_name','$ie',1)");
                    }
                }
            }
        }
    }

    // Total count for pagination
    $total = 0;
    if ($niche_name) {
        $_r1 = mysqli_query($conn, "SELECT COUNT(*) cnt FROM hdb_user_video_ideas WHERE admin_id=$owner_id AND niche_name='$niche_name'");
        $tc  = ($_r1 && $_row1 = mysqli_fetch_assoc($_r1)) ? (int)$_row1['cnt'] : 0;
        $_r2 = mysqli_query($conn, "SELECT COUNT(*) cnt FROM hdb_master_video_ideas WHERE niche_name='$niche_name'");
        $tm  = ($_r2 && $_row2 = mysqli_fetch_assoc($_r2)) ? (int)$_row2['cnt'] : 0;
        $total = max((int)$tc, (int)$tm, count($ideas));
    }

    echo json_encode([
        'success' => true,
        'ideas'   => array_values(array_unique($ideas)),
        'total'   => (int)$total,
        'page'    => $page,
        'has_more'=> ($offset + $limit) < $total,
    ]); exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_company_video_ideas') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $group    = trim($_POST['group']    ?? '');
    $subgroup = trim($_POST['subgroup'] ?? '');
    $niche    = trim($_POST['niche']    ?? '');
    if (!$niche && !$subgroup && !$group) { echo json_encode(['success'=>false,'error'=>'No context']); exit; }
    require_once __DIR__ . '/config.php';
    $apiKey = isset($apiKey) ? $apiKey : (isset($chatgpt_api_key) ? $chatgpt_api_key : '');
    $parts = array_filter([$group, $subgroup, $niche]);
    $context_str = implode(' > ', $parts);
    $focus = $niche ? $niche : ($subgroup ? $subgroup : $group);
    $prompt  = "You are a video content strategist. Generate 10 video TOPIC ideas for a business in: {$context_str}\n\n";
    $prompt .= "A video topic is the SUBJECT of the video — NOT a hook, title, or opening line.\n";
    $prompt .= "The hook (how you open the video) is chosen separately after the topic.\n\n";
    $prompt .= "Rules:\n";
    $prompt .= "- State the subject plainly, like a search term or category label\n";
    $prompt .= "- NEVER start with: How to, Top, Best, Why, What, When, Did you know, Stop, Here is\n";
    $prompt .= "- NEVER use numbers at the start (5 ways, 3 tips, 10 mistakes, etc.)\n";
    $prompt .= "- NEVER use adjectives like Essential, Common, Simple, Quick, Important\n";
    $prompt .= "- Good: \"Tax audit preparation\", \"Bookkeeping for freelancers\", \"Cash vs accrual accounting\", \"BAS lodgement process\", \"Payroll software comparison\"\n";
    $prompt .= "- Bad: \"How to prepare for a tax audit\", \"Top 5 bookkeeping tips\", \"Essential accounting software\"\n\n";
    $prompt .= "Be specific to: {$focus}\n\n";
    $prompt .= "Return ONLY a JSON array of 10 strings. No markdown, no explanation.";
    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'Return ONLY a valid JSON array of strings. No markdown.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature' => 0.75,
        'max_tokens'  => 500,
    ]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $data  = json_decode($response, true);
        $raw   = trim($data['choices'][0]['message']['content'] ?? '');
        $raw   = preg_replace('/^```json\s*|\s*```$/s', '', $raw);
        $ideas = json_decode($raw, true);
        if (is_array($ideas) && count($ideas) > 0) {
            // Try to save ideas to DB — failure here must NOT prevent returning ideas to the browser
            try {
                [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
                $niche_esc = mysqli_real_escape_string($conn, $niche ? $niche : $subgroup);
                foreach (array_slice($ideas, 0, 10) as $idea) {
                    $ie = mysqli_real_escape_string($conn, trim($idea));
                    if ($ie && $niche_esc) {
                        mysqli_query($conn, "INSERT IGNORE INTO hdb_user_video_ideas (admin_id,company_id,niche_id,category_id,niche_name,category_name,video_idea,is_ai_generated) VALUES ($owner_id,$co_id,0,0,'$niche_esc','$niche_esc','$ie',1)");
                    }
                }
            } catch (\Throwable $e) {
                vv_log("generate_company_video_ideas: DB save failed — " . $e->getMessage());
            }
            echo json_encode(['success' => true, 'ideas' => array_slice($ideas, 0, 10)]);
        } else { echo json_encode(['success' => false, 'error' => 'Invalid AI response']); }
    } else {
        $err = json_decode((string)$response, true);
        echo json_encode(['success' => false, 'error' => isset($err['error']['message']) ? $err['error']['message'] : 'HTTP ' . $httpCode]);
    }
    exit;
}

// ── AJAX: Create campaign — insert one hdb_podcasts row per idea ──────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_campaign') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json; charset=utf-8');

    $ideas_raw   = trim($_POST['ideas']      ?? '');
    $niche       = trim($_POST['niche']      ?? '');
    $subgroup    = trim($_POST['subgroup']   ?? '');
    $group_name  = trim($_POST['group']      ?? '');
    $lang_code   = trim($_POST['lang_code']  ?? 'en');
    $start_date  = trim($_POST['start_date'] ?? date('Y-m-d', strtotime('+1 day')));
    $freq_days   = max(1, (int)($_POST['freq_days'] ?? 1));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-d');

    $ideas = json_decode($ideas_raw, true);
    if (!is_array($ideas) || empty($ideas)) {
        echo json_encode(['success' => false, 'error' => 'No ideas provided']); exit;
    }

    try {
        [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    } catch (\Throwable $e) {
        $owner_id = $admin_id; $co_id = $company_id;
    }
    $team_lead_id = 0;
    $category     = $subgroup ?: $group_name;
    $today        = date('Y-m-d');

    // ── Step 1: Auto-add any missing columns ──────────────────────────────────
    $camp_cols = [];
    $cc_res = mysqli_query($conn, "SHOW COLUMNS FROM hdb_campaigns");
    if ($cc_res) while ($cc = mysqli_fetch_assoc($cc_res)) $camp_cols[] = $cc['Field'];
    if (!in_array('campaign_name', $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN campaign_name VARCHAR(255) DEFAULT NULL");
    if (!in_array('niche',         $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN niche         VARCHAR(120) DEFAULT NULL");
    if (!in_array('start_date',    $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN start_date    DATE DEFAULT NULL");
    if (!in_array('freq_days',     $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN freq_days     INT DEFAULT 1");
    if (!in_array('company_id',    $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN company_id    INT DEFAULT 0");
    if (!in_array('created_date',  $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN created_date  DATE DEFAULT NULL");
    if (!in_array('video_count',   $camp_cols)) mysqli_query($conn, "ALTER TABLE hdb_campaigns ADD COLUMN video_count   INT DEFAULT 0");

    $pod_cols = [];
    $pc_res = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts");
    if ($pc_res) while ($pc = mysqli_fetch_assoc($pc_res)) $pod_cols[] = $pc['Field'];
    if (!in_array('campaign_id', $pod_cols)) mysqli_query($conn, "ALTER TABLE hdb_podcasts ADD COLUMN campaign_id INT DEFAULT 0");

    // ── Step 2: Create one hdb_campaigns row ─────────────────────────────────
    $camp_niche = $niche ?: $subgroup ?: $group_name;
    $camp_label = mysqli_real_escape_string($conn, $camp_niche . ' Campaign ' . date('d M Y'));
    $esc_cn     = mysqli_real_escape_string($conn, $camp_niche);
    $esc_sd     = mysqli_real_escape_string($conn, $start_date);

    mysqli_query($conn, "INSERT INTO hdb_campaigns
        (admin_id, company_id, campaign_name, niche, start_date, freq_days, created_date, video_count, voice_id, speech_rate, media_type)
        VALUES
        ($owner_id, $co_id, '$camp_label', '$esc_cn', '$esc_sd', $freq_days, '$today', " . count($ideas) . ", '', 1.25, 'stock_videos')");
    $campaign_id = (int)mysqli_insert_id($conn);
    if (!$campaign_id) vv_log("create_campaign: hdb_campaigns INSERT failed — " . mysqli_error($conn));

    // ── Step 3: Create one hdb_podcasts row per idea ──────────────────────────
    $created = [];
    $failed  = [];

    foreach ($ideas as $idx => $idea) {
        $idea = trim($idea);
        if (!$idea) continue;

        $sched_ts   = strtotime($start_date) + ($idx * $freq_days * 86400);
        $sched_date = date('Y-m-d', $sched_ts);

        $esc_title = mysqli_real_escape_string($conn, $idea);
        $esc_niche = mysqli_real_escape_string($conn, $camp_niche);
        $esc_cat   = mysqli_real_escape_string($conn, $category);
        $esc_lang  = mysqli_real_escape_string($conn, $lang_code);
        $esc_sched = mysqli_real_escape_string($conn, $sched_date);
        $camp_id_val = $campaign_id ?: 0;

        $sql = "INSERT INTO hdb_podcasts
            (admin_id, team_lead_id, company_id, campaign_id, title, lang_code, video_type, video_status,
             internal_status, created_date, updated_at, niche, category, topic_key,
             host_voice, guest_voice, voice_rate, is_campaign,
             logo_flag, facebook_status, tiktok_status, instagram_status,
             youtube_status, twitter_status, linkedin_status,
             schedule_date, schedule_time, publish_date, video_format, video_media,
             music_file, hook_name)
            VALUES
            ($owner_id, $team_lead_id, $co_id, $camp_id_val, '$esc_title', '$esc_lang', 'standard', 'draft',
             'draft', '$today', NOW(), '$esc_niche', '$esc_cat', '',
             '', '', 1.25, 1,
             0, 'pending', 'pending', 'pending',
             'pending', 'pending', 'pending',
             '$esc_sched', '09:00', '$esc_sched', 'vertical', 'stock_videos',
             '', '')";

        if (mysqli_query($conn, $sql)) {
            $pid = mysqli_insert_id($conn);
            $created[] = ['podcast_id' => $pid, 'title' => $idea, 'schedule_date' => $sched_date, 'schedule_time' => '09:00'];
            mysqli_query($conn, "INSERT INTO hdb_user_activity_log
                (podcast_id, admin_id, action_type, action_detail)
                VALUES ($pid, $admin_id, 'podcast_created', 'type:standard scenes:0 campaign_id:$camp_id_val sched:$sched_date')"
            );
        } else {
            $failed[] = $idea;
            vv_log("create_campaign: INSERT failed for '$idea' — " . mysqli_error($conn));
        }
    }

    if ($campaign_id && count($created) > 0) {
        mysqli_query($conn, "UPDATE hdb_campaigns SET video_count=" . count($created) . " WHERE id=$campaign_id");
    }

    echo json_encode([
        'success'     => count($created) > 0,
        'campaign_id' => $campaign_id,
        'created'     => $created,
        'failed'      => $failed,
        'count'       => count($created),
        'niche'       => $camp_niche,
        'start_date'  => $start_date,
        'freq_days'   => $freq_days,
    ]);
    exit;
}


if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'init_captions_from_settings') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'No podcast_id']); exit; }
    $pod_row = vv_safe_fetch($conn, "SELECT video_type FROM hdb_podcasts WHERE id=$pid LIMIT 1");
    $_vtype = strtolower($pod_row['video_type'] ?? '');
    if (strpos($_vtype,'broll')!==false || strpos($_vtype,'b-roll')!==false) {
        echo json_encode(['success'=>true,'updated'=>0,'message'=>'B-Roll: animation preserved']); exit;
    }
    $anim_style = 'none'; $anim_speed = 1.0;
    $ust = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_user_settings'");
    if ($ust && mysqli_num_rows($ust) > 0) {
        $uq = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id LIMIT 1");
        if ($uq && $ur = mysqli_fetch_assoc($uq)) {
            $anim_style = $ur['animation_style'] ?? $ur['caption_style'] ?? 'none';
            $_raw = $ur['animation_speed'] ?? $ur['caption_speed'] ?? 1.0;
            $anim_speed = is_numeric($_raw) ? (float)$_raw : 1.0;
        }
    }
    if ($anim_style === 'none' && $anim_speed == 1.0) {
        echo json_encode(['success'=>true,'updated'=>0,'message'=>'No custom caption settings']); exit;
    }
    $safe_style = mysqli_real_escape_string($conn, $anim_style);
    $ok = mysqli_query($conn, "UPDATE hdb_captions SET animation_style='$safe_style', animation_speed=$anim_speed WHERE podcast_id=$pid");
    $updated = $ok ? (int)mysqli_affected_rows($conn) : 0;
    echo json_encode(['success'=>(bool)$ok,'updated'=>$updated,'animation_style'=>$anim_style,'animation_speed'=>$anim_speed]);
    exit;
}

// ── Generate video script ─────────────────────────────────────────────────────
// Supports Standard, B-Roll, Talking Head, and Podcast reel types.
// Prompt logic ported from generate_script.php buildPrompt().
// ─────────────────────────────────────────────────────────────────────────────

// Helper: merge short scenes (< minWords) into their neighbour
function vv_mergeShortScenes(string $script, int $minWords = 15, int $maxWords = 35): string {
    $BREAK = '<break time="200ms"/>';
    $lines  = array_values(array_filter(array_map('trim', explode("\n", $script))));
    $merged = [];
    $buffer = '';
    foreach ($lines as $line) {
        $clean     = trim(preg_replace('/<break[^\/]*\/>/i', '', $line));
        $wordCount = str_word_count($clean);
        if ($buffer === '') {
            $buffer = $line;
        } else {
            $bufClean = trim(preg_replace('/<break[^\/]*\/>/i', '', $buffer));
            $bufWords = str_word_count($bufClean);
            if ($bufWords < $minWords && ($bufWords + $wordCount) <= $maxWords) {
                $bufNoBreak = rtrim(preg_replace('/<break[^\/]*\/>/i', '', $buffer));
                $buffer     = $bufNoBreak . ' ' . $clean . ' ' . $BREAK;
            } else {
                $merged[] = $buffer;
                $buffer   = $line;
            }
        }
    }
    if ($buffer !== '') $merged[] = $buffer;
    return implode("\n", $merged);
}

// Helper: split raw AI response into tagged scenes
function vv_splitAndTag(string $raw, string $reel_type = ''): string {
    $BREAK = '<break time="200ms"/>';
    $raw = trim($raw);
    if (empty($raw)) return '';
    $is_broll        = stripos($reel_type, 'b-roll') !== false || stripos($reel_type, 'broll') !== false;
    $is_podcast      = stripos($reel_type, 'podcast') !== false;
    $is_talking_head = stripos($reel_type, 'talking head') !== false;
    if (!$is_podcast && !$is_talking_head) {
        $raw = preg_replace('/<break[^\/]*\/>/i', '', $raw);
    }
    $raw = trim(str_replace(["\xc2\xa0", "\t"], [' ', ' '], $raw));
    if ($is_broll) {
        return $raw . ' ' . $BREAK;
    }
    if ($is_podcast || $is_talking_head) {
        $lines = preg_split('/\r?\n/', $raw);
        $lines = array_values(array_filter(array_map('trim', $lines)));
        $lines = array_map(function($line) use ($BREAK) {
            if (!preg_match('/<break[^\/]*\/>/i', $line)) {
                $line = rtrim($line) . ' ' . $BREAK;
            }
            return $line;
        }, $lines);
        return implode("\n", $lines);
    }
    // Standard: split on [SCENE BREAK] or newlines
    $scenes = preg_split('/\[SCENE BREAK\]/i', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        $tagged = implode("\n", array_map(fn($s) => rtrim($s) . ' ' . $BREAK, $scenes));
        return vv_mergeShortScenes($tagged, 8, 40);
    }
    $scenes = preg_split('/\r?\n/', $raw);
    $scenes = array_values(array_filter(array_map('trim', $scenes)));
    if (count($scenes) > 1) {
        $tagged = implode("\n", array_map(fn($s) => rtrim($s) . ' ' . $BREAK, $scenes));
        return vv_mergeShortScenes($tagged, 8, 40);
    }
    return $raw . ' ' . $BREAK;
}

// Helper: parse ---META--- block from AI response
function vv_parseScriptAndMeta(string $raw): array {
    $meta = ['hashtags' => '', 'keywords' => '', 'caption_text' => ''];
    if (strpos($raw, '---META---') !== false) {
        [$script_part, $meta_part] = explode('---META---', $raw, 2);
        $script_part = trim($script_part);
        $meta_part   = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/i'], '', trim($meta_part)));
        $decoded = json_decode($meta_part, true);
        if (is_array($decoded)) {
            $meta['hashtags']     = $decoded['hashtags']     ?? '';
            $meta['keywords']     = $decoded['keywords']     ?? '';
            $meta['caption_text'] = $decoded['caption_text'] ?? '';
        }
        return [$script_part, $meta];
    }
    return [$raw, $meta];
}

// Helper: build reel-type-specific prompt
function vv_buildPrompt(array $d): string {
    $BREAK = '<break time="200ms"/>';
    $niche           = trim($d['niche']           ?? '');
    $category        = trim($d['topic']           ?? $d['category'] ?? '');
    $idea            = trim($d['title']           ?? $d['video_idea'] ?? '');
    $angle           = trim($d['hook']            ?? $d['angle'] ?? '');
    $duration_raw    = trim($d['duration']        ?? '60');
    $cta             = trim($d['cta']             ?? 'Follow for more tips');
    $language        = trim($d['language']        ?? 'English');
    $reelType        = trim($d['reel_type']       ?? 'Standard');
    $tone            = trim($d['tone']            ?? 'Friendly');
    $brand_name      = trim($d['brand_name']      ?? '');
    $content_goals   = trim($d['content_goals']   ?? $d['objective'] ?? 'Education');
    $growth_goals    = trim($d['growth_goals']    ?? 'Grow Followers');
    $target_audience = trim($d['audience']        ?? 'General Public');
    $target_location = 'Global';

    // Normalise duration to string key
    $dur_num = (int)preg_replace('/[^0-9]/', '', $duration_raw);
    if ($dur_num <= 15)      $duration = '15 seconds';
    elseif ($dur_num <= 30)  $duration = '30 seconds';
    elseif ($dur_num <= 60)  $duration = '60 seconds';
    else                     $duration = '90 seconds';

    $durationConfig = [
        '15 seconds' => ['total_words'=>32,  'scene_count'=>'3-4', 'min_scene'=>8,  'max_scene'=>12],
        '30 seconds' => ['total_words'=>110, 'scene_count'=>'6-7', 'min_scene'=>12, 'max_scene'=>20],
        '60 seconds' => ['total_words'=>200, 'scene_count'=>'10-12', 'min_scene'=>14, 'max_scene'=>22],
        '90 seconds' => ['total_words'=>280, 'scene_count'=>'13-15', 'min_scene'=>18, 'max_scene'=>26],
    ];
    $dc = $durationConfig[$duration];
    $words           = $dc['total_words'];
    $scene_count     = $dc['scene_count'];
    $min_scene_words = $dc['min_scene'];
    $max_scene_words = $dc['max_scene'];

    // Language enforcement
    $script_enforcement_map = [
        'Urdu'=>'CRITICAL: Write ONLY in Urdu using Nastaliq/Perso-Arabic script (اردو). Do NOT use Roman Urdu.',
        'Arabic'=>'CRITICAL: Write ONLY in Arabic script (العربية). Do NOT use romanized Arabic.',
        'Hindi'=>'CRITICAL: Write ONLY in Hindi using Devanagari script (हिन्दी). Do NOT use Roman/transliterated Hindi.',
        'Punjabi'=>'CRITICAL: Write ONLY in Punjabi using Gurmukhi script (ਪੰਜਾਬੀ). Do NOT romanize.',
        'Gujarati'=>'CRITICAL: Write ONLY in Gujarati script (ગુજરાતી). Do NOT use romanized text.',
        'Tamil'=>'CRITICAL: Write ONLY in Tamil script (தமிழ்). Do NOT use romanized Tamil.',
        'Bengali'=>'CRITICAL: Write ONLY in Bengali script (বাংলা). Do NOT use romanized Bengali.',
        'Mandarin Chinese'=>'CRITICAL: Write ONLY in Simplified Chinese characters (中文). Do NOT use Pinyin.',
        'Japanese'=>'CRITICAL: Write ONLY in Japanese using Kanji/Hiragana/Katakana (日本語). Do NOT romanize.',
        'Korean'=>'CRITICAL: Write ONLY in Korean Hangul (한국어). Do NOT use romanized Korean.',
        'Farsi'=>'CRITICAL: Write ONLY in Farsi/Persian using Arabic script (فارسی). Do NOT use romanized Farsi.',
        'Russian'=>'CRITICAL: Write ONLY in Russian using Cyrillic script (Русский). Do NOT romanize.',
        'Bulgarian'=>'CRITICAL: Write ONLY in Bulgarian Cyrillic (Български). Do NOT romanize.',
        'Serbian'=>'CRITICAL: Write ONLY in Serbian Cyrillic (Српски). Do NOT romanize.',
        'Ukrainian'=>'CRITICAL: Write ONLY in Ukrainian Cyrillic (Українська). Do NOT romanize.',
        'Greek'=>'CRITICAL: Write ONLY in Greek alphabet (Ελληνικά). Do NOT romanize.',
        'Turkish'=>'CRITICAL: Write in Turkish (Türkçe) using correct characters (ş, ğ, ı, ç, ö, ü).',
        'Portuguese'=>'CRITICAL: Write in Portuguese (Português) with correct accented characters.',
        'Spanish'=>'CRITICAL: Write in Spanish (Español) with correct accented characters.',
        'French'=>'CRITICAL: Write in French (Français) with correct accented characters.',
        'German'=>'CRITICAL: Write in German (Deutsch) with correct characters (ä, ö, ü, ß).',
        'Dutch'=>'CRITICAL: Write in Dutch (Nederlands) with correct accented characters.',
        'Swedish'=>'CRITICAL: Write in Swedish (Svenska) with correct characters (å, ä, ö).',
        'Norwegian'=>'CRITICAL: Write in Norwegian (Norsk) with correct characters (æ, ø, å).',
        'Danish'=>'CRITICAL: Write in Danish (Dansk) with correct characters (æ, ø, å).',
        'Finnish'=>'CRITICAL: Write in Finnish (Suomi) with correct characters (ä, ö).',
        'Polish'=>'CRITICAL: Write in Polish (Polski) with correct characters (ą, ć, ę, ł, ń, ó, ś, ź, ż).',
        'Czech'=>'CRITICAL: Write in Czech (Čeština) with correct diacritical characters.',
        'Slovak'=>'CRITICAL: Write in Slovak (Slovenčina) with correct diacritical characters.',
        'Hungarian'=>'CRITICAL: Write in Hungarian (Magyar) with correct characters (á, é, í, ó, ö, ő, ú, ü, ű).',
        'Romanian'=>'CRITICAL: Write in Romanian (Română) with correct characters (ă, â, î, ș, ț).',
        'Croatian'=>'CRITICAL: Write in Croatian (Hrvatski) with correct characters (č, ć, đ, š, ž).',
        'Slovenian'=>'CRITICAL: Write in Slovenian (Slovenščina) with correct characters (č, š, ž).',
        'Albanian'=>'CRITICAL: Write in Albanian (Shqip) with correct characters (ë, ç).',
    ];
    $lang_enforce = $script_enforcement_map[$language] ?? '';
    $language_instruction = "Language: {$language}" . ($lang_enforce ? "\n{$lang_enforce}" : '');

    $brand_ctx    = $brand_name      ? "Brand/Business Name: $brand_name"  : '';
    $location_ctx = "Target Location: Global (worldwide audience)";

    // Goal style block
    $goal_styles = [
        'Education'   => ['writing_style'=>'Clear, authoritative, genuinely useful. Teach one specific thing the viewer can apply immediately. Never mention the brand — pure value only.','hook_style'=>'Lead with a surprising fact, a common mistake, or a question that makes the viewer think "I didn\'t know that".','structure'=>'Hook (surprising fact or mistake) → explain the concept step by step → concrete real-world example → one actionable takeaway → soft CTA (follow for more tips, save this).','cta_tone'=>'Value-driven: follow for more tips, save this for later, share with someone who needs this.','avoid'=>'Promotional language, brand mentions, vague generalisations, anything that sounds like an ad.'],
        'Inform'      => ['writing_style'=>'Newsy, factual, timely. Report something relevant happening right now in the niche — a change, an update, a trend, or a stat. No fluff, no storytelling.','hook_style'=>'Lead with the news itself — open with the announcement, the number, or the change. Do not build up to it.','structure'=>'State the news or fact immediately → explain what it means and who it affects → give 1-2 key details → CTA (find out more, check the link, speak to an expert).','cta_tone'=>'Informative and direct: find out more, check the link in bio, speak to a professional.','avoid'=>'Storytelling for its own sake, how-to tutorials, promotional language, emotional appeals.'],
        'Promote'     => ['writing_style'=>'Promotional and conversion-focused. Open with the viewer\'s problem, present the brand as the solution, back it with proof, and close with a strong direct CTA.','hook_style'=>'Lead with the problem the viewer is experiencing right now — make them feel seen before you introduce the solution.','structure'=>'Hook (viewer\'s problem) → introduce the brand/service as the solution → one piece of social proof (result, number, testimonial) → urgency or reason to act now → strong direct CTA.','cta_tone'=>'Direct and urgent: book now, visit us today, call us, order now, limited spots available, link in bio.','avoid'=>'Generic education, tips with no brand tie-in, weak CTAs like "follow for more", anything that doesn\'t drive action.'],
    ];
    $gs = $goal_styles[$content_goals] ?? $goal_styles['Education'];
    $goal_style_block = "CONTENT GOAL: {$content_goals}
Writing Style: {$gs['writing_style']}
Hook Style: {$gs['hook_style']}
Script Structure: {$gs['structure']}
CTA Tone: {$gs['cta_tone']}
Avoid: {$gs['avoid']}";

    $meta_instruction = "
After the script, on a new line write exactly: ---META---
Then on the next line return ONLY this JSON (no markdown, no extra text):
{\"hashtags\":\"<value>\",\"keywords\":\"<value>\",\"caption_text\":\"<value>\"}

HASHTAG RULES (25-30 hashtags, always in English):
- Niche-specific tags for: $niche / $category
- Audience tags for: $target_audience
- Content goal tags for: $content_goals
- Growth goal tags for: $growth_goals
- Mix popular and niche-specific hashtags
- No spaces inside hashtags, separated by spaces, include # prefix

KEYWORD RULES (12-18 keywords, comma-separated, no #, always in English):
- Include: niche terms, category terms, audience type ($target_audience)
- Include: content goal ($content_goals), growth goal ($growth_goals)

CAPTION RULES (2-3 sentences, write in $language):
- Tone: $tone
- Open with an engaging hook related to the video content
- End with CTA driving: $growth_goals — use this CTA: $cta
- No hashtags in the caption";

    // ── B-Roll ────────────────────────────────────────────────────────────────
    if (stripos($reelType, 'B-Roll') !== false) {
        return "You are an expert short-form video scriptwriter for the '$niche' industry.

Write a voiceover script for: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

FORMAT: B-Roll voiceover — ONE continuous narration broken into paragraphs.

OUTPUT RULES:
- Write 3-5 paragraphs separated by [SCENE BREAK]
- Each paragraph = 2-4 sentences, flowing naturally into the next
- Every paragraph ends with: $BREAK
- First paragraph: powerful opening statement or question
- Last paragraph: $cta $BREAK
- NO labels, NO headings, NO scene numbers
$meta_instruction";
    }

    // ── Talking Head ──────────────────────────────────────────────────────────
    if (stripos($reelType, 'Talking Head') !== false) {
        return "You are a confident, engaging on-camera speaker.
Create a natural spoken script for a talking avatar.

Topic: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

STYLE:
- Sound like a real person talking to camera (not reading a script)
- Conversational, slightly informal, human
- Use natural phrasing: \"so\", \"honestly\", \"you know\", \"here's the thing\"

DELIVERY RULES:
- Mix short and medium-length sentences (5-20 words)
- Occasionally use sentence fragments (like real speech)
- Add light emotional cues: (smiles), (pauses), (leans in), (excited)
- Avoid overly polished or corporate language

PACING:
- Use natural pauses: <break time=\"100ms\"/> (quick) / <break time=\"300ms\"/> (normal) / <break time=\"600ms\"/> (emphasis)

STRUCTURE:
- Start with a strong, natural hook — NOT \"Hi\" or \"Welcome\"
- Flow smoothly between ideas
- End with this CTA naturally: $cta

FORMAT:
- No headings, no bullet points, no labels
- Output as continuous spoken lines, each ending with a break tag
- 1-2 sentences per line

GOAL: Make it feel like a real human speaking directly to the viewer.
$meta_instruction";
    }

    // ── Podcast ───────────────────────────────────────────────────────────────
    if (stripos($reelType, 'Podcast') !== false) {
        $pod_words = (int)round($words * 0.8);
        return "You are an expert podcast scriptwriter for the '$niche' industry.

Write a highly natural, engaging podcast conversation for: $idea

Context:
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Tone: $tone | Duration: $duration (~$pod_words words) | $language_instruction

$goal_style_block

STYLE: Real human conversation (not scripted, not robotic).

CRITICAL RULES:
- Use HOST and GUEST labels
- DO NOT strictly alternate speakers (allow interruptions and follow-ups)
- Mix short and long lines (3-25 words)
- Occasionally use sentence fragments (like real speech)
- Allow natural fillers: \"yeah\", \"honestly\", \"you know\", \"I mean\"
- Add light conversational cues: (laughs), (pauses), (sighs), (chuckles)
- HOST can react, interrupt, or add opinions (not just ask questions)
- GUEST should sound human: imperfect, reflective, sometimes hesitant
- Include occasional follow-up lines from the same speaker

PACING:
- Vary pauses naturally using:
  <break time=\"100ms\"/> for quick replies
  <break time=\"300ms\"/> for normal pauses
  <break time=\"600ms\"/> for emphasis or emotional moments

FORMAT:
- Each line starts with HOST: or GUEST:
- 1-2 sentences per line (not forced to one)
- Each line ends with a break tag
- No headings or extra formatting

FLOW:
- Start casually, not overly formal
- Build into deeper discussion
- Include at least 1 moment of interruption or overlap feeling
- End naturally with HOST delivering this CTA: $cta

GOAL:
Make it sound like a real recorded podcast, not an AI script.
$meta_instruction";
    }

    // ── Standard (default) ────────────────────────────────────────────────────
    return "You are an expert short-form video scriptwriter for the '$niche' industry.

Write a script for: $idea
Niche: $niche | Category: $category
Audience: $target_audience | Growth Goal: $growth_goals
$location_ctx
$brand_ctx
Angle: $angle | Tone: $tone | Duration: $duration (~$words words) | $language_instruction

$goal_style_block

FORMAT: Standard short-form video — direct, engaging, scene-by-scene.

OUTPUT RULES:
- Exactly $scene_count scenes separated by [SCENE BREAK]
- Each scene = 1-2 complete sentences, {$min_scene_words}–{$max_scene_words} words
- Every scene ends with: $BREAK
- First scene: attention-grabbing hook — do NOT start with \"Hi\" or \"Welcome\"
- Last scene: $cta $BREAK
- NO labels, NO headings, NO scene numbers
- Each scene must be a COMPLETE thought a viewer can absorb in 4-7 seconds
- Do NOT write single short punchy lines — write full, meaningful sentences

GOOD EXAMPLE SCENE:
\"Most people wear the same outfit the same way every time, but with one simple swap you can make it work for three completely different occasions. $BREAK\"

BAD EXAMPLE (too short):
\"Stop wearing the same outfit! $BREAK\"
$meta_instruction";
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_script') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/config.php';
    $apiKey = isset($apiKey) ? $apiKey : (isset($chatgpt_api_key) ? $chatgpt_api_key : '');

    $d = [
        'niche'         => trim($_POST['niche']         ?? ''),
        'title'         => trim($_POST['topic']         ?? $_POST['title'] ?? ''),
        'topic'         => trim($_POST['topic']         ?? ''),
        'hook'          => trim($_POST['hook']          ?? ''),
        'angle'         => trim($_POST['hook']          ?? ''),
        'duration'      => trim($_POST['duration']      ?? '60'),
        'cta'           => trim($_POST['cta']           ?? ''),
        'language'      => trim($_POST['language']      ?? 'English'),
        'reel_type'     => trim($_POST['reel_type']     ?? 'Standard'),
        'audience'      => trim($_POST['audience']      ?? 'General Public'),
        'tone'          => trim($_POST['tone']          ?? 'Friendly'),
        'content_goals' => trim($_POST['content_goals'] ?? 'Education'),
        'growth_goals'  => trim($_POST['growth_goals']  ?? 'Grow Followers'),
        'brand_name'    => trim($_POST['brand_name']    ?? ''),
        'voice_id'      => trim($_POST['voice_id']      ?? ''),
    ];

    if (!$d['niche']) { echo json_encode(['success'=>false,'error'=>'Niche is missing']); exit; }
    if (!$d['title']) { echo json_encode(['success'=>false,'error'=>'Video topic is missing']); exit; }

    vv_log("generate_script | reel_type={$d['reel_type']} niche={$d['niche']} title={$d['title']}");

    $prompt = vv_buildPrompt($d);

    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a professional video scriptwriter. Follow all format and language instructions exactly.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature' => 0.75,
        'max_tokens'  => 2000,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode((string)$response, true);
        $msg = $err['error']['message'] ?? "HTTP $httpCode";
        vv_log("generate_script API error: $msg");
        echo json_encode(['success'=>false,'error'=>$msg]); exit;
    }

    $result  = json_decode($response, true);
    $raw_full = trim($result['choices'][0]['message']['content'] ?? '');
    vv_log("generate_script raw response length=" . strlen($raw_full));

    [$raw_script, $meta] = vv_parseScriptAndMeta($raw_full);

    $script = vv_splitAndTag($raw_script, $d['reel_type']);

    // For Standard/B-Roll: merge scenes still under 15 words
    $rt_lower = strtolower($d['reel_type']);
    if (strpos($rt_lower, 'podcast') === false && strpos($rt_lower, 'talking head') === false) {
        $script = vv_mergeShortScenes($script, 8, 40);
    }

    // Return scene array for JS rendering
    $BREAK   = '<break time="200ms"/>';
    $lines   = array_values(array_filter(array_map('trim', explode("\n", $script))));
    $scenes  = array_map(fn($l) => trim(preg_replace('/<break[^\/]*\/>/i', '', $l)), $lines);
    $scenes  = array_values(array_filter($scenes));

    vv_log("generate_script done | scenes=" . count($scenes) . " meta_hashtags=" . strlen($meta['hashtags']));

    echo json_encode([
        'success'      => true,
        'scenes'       => $scenes,
        'script'       => $script,
        'word_count'   => str_word_count(implode(' ', $scenes)),
        'reel_type'    => $d['reel_type'],
        'meta'         => $meta,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// ── AJAX: Format user's own pasted content into scenes ───────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'format_user_content') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json; charset=utf-8');

    $raw_text  = trim($_POST['raw_text'] ?? '');
    $title     = trim($_POST['title']    ?? 'My Video');
    $reel_type = 'Standard'; // always Standard for "I Have Content"

    if (!$raw_text) {
        echo json_encode(['success' => false, 'error' => 'No content provided']); exit;
    }

    // Split and tag using the same pipeline as AI scripts
    $script = vv_splitAndTag($raw_text, $reel_type);
    $script = vv_mergeShortScenes($script, 8, 40);

    // Build scenes array
    $lines  = array_values(array_filter(array_map('trim', explode("\n", $script))));
    $scenes = array_map(fn($l) => trim(preg_replace('/<break[^\/]*\/>/i', '', $l)), $lines);
    $scenes = array_values(array_filter($scenes));

    vv_log("format_user_content | title=$title scenes=" . count($scenes));

    echo json_encode([
        'success'    => true,
        'scenes'     => $scenes,
        'script'     => $script,
        'word_count' => str_word_count(implode(' ', $scenes)),
        'reel_type'  => $reel_type,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Save company industry fields ───────────────────────────────────────────────
// NOTE: If columns don't exist yet, run:
//   ALTER TABLE hdb_companies ADD COLUMN group_name    VARCHAR(120) DEFAULT NULL;
//   ALTER TABLE hdb_companies ADD COLUMN subgroup_name VARCHAR(120) DEFAULT NULL;
//   ALTER TABLE hdb_companies ADD COLUMN niche         VARCHAR(120) DEFAULT NULL;
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_company_industry') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $field = mysqli_real_escape_string($conn, $_POST['field'] ?? '');
    $val   = mysqli_real_escape_string($conn, trim($_POST['value'] ?? ''));
    $allowed = ['group_name', 'subgroup_name', 'niche'];
    if (!in_array($field, $allowed)) { echo json_encode(['success'=>false,'error'=>'Bad field']); exit; }

    // Resolve company: prefer session company_id, then owner's first company
    [$owner_id, $co_id] = vv_resolve_user($conn, $admin_id, $company_id);
    if (!$co_id) {
        // Fallback: find by admin_id directly
        $fb = vv_safe_fetch($conn, "SELECT id FROM hdb_companies WHERE admin_id=$admin_id ORDER BY id ASC LIMIT 1");
        $co_id = $fb ? (int)$fb['id'] : 0;
    }
    if (!$co_id) { echo json_encode(['success'=>false,'error'=>'No company found']); exit; }

    $result = mysqli_query($conn, "UPDATE hdb_companies SET `$field`='$val' WHERE id=$co_id LIMIT 1");
    $affected = mysqli_affected_rows($conn);
    vv_log("save_company_industry | co_id=$co_id field=$field val=$val affected=$affected");
    echo json_encode(['success'=>true, 'field'=>$field, 'value'=>$val, 'co_id'=>$co_id]); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Script Wizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ──────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;
  --mid-blue:  #143b63;
  --accent:    #5fd1ff;
  --purple:    #8b5cf6;
  --purple-lt: #ede9fe;
  --green:     #10b981;
  --orange:    #f59e0b;
  --orange-lt: #fef3c7;
  --text:      #1e293b;
  --muted:     #64748b;
  --border:    #e2e8f0;
  --bg:        #f8fafc;
  --card:      #ffffff;
  --shadow:    0 4px 12px rgba(0,0,0,0.08);
}
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

/* ── Header ─────────────────────────────────────────────────────────────────── */
.app-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: #fff;
  box-shadow: 0 3px 10px rgba(0,0,0,0.15);
  position: sticky; top: 0; z-index: 1000;
}
.brand { display: flex; align-items: center; gap: 8px; text-decoration: none; }
.brand-name { font-size: 18px; font-weight: 700; }
.brand-name .v { color: #fff; }
.brand-name .z { color: #5fd1ff; }
.header-back { color: rgba(255,255,255,.75); font-size: 13px; font-weight: 600; text-decoration: none; transition: color .2s; }
.header-back:hover { color: #5fd1ff; }

/* ── Page wrap ───────────────────────────────────────────────────────────────── */
.page-wrap { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 28px 16px 48px; }

/* ── Wizard card ─────────────────────────────────────────────────────────────── */
.wiz-card { background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); width: 100%; max-width: 600px; overflow: hidden; }
.wiz-header { padding: 18px 24px 16px; background: linear-gradient(90deg, #0f2a44, #143b63); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.wiz-header h1 { font-size: 20px; font-weight: 700; color: #fff; }
.wiz-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 2px 0 0; }
.gear-btn { width: 36px; height: 36px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); background: rgba(255,255,255,.1); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 17px; color: rgba(255,255,255,.8); transition: all .15s; flex-shrink: 0; }
.gear-btn:hover { background: rgba(255,255,255,.2); color: #fff; }
.wiz-body { padding: 24px; }

/* ── Settings bar ────────────────────────────────────────────────────────────── */
.settings-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; background: #f7f9fc; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; margin-bottom: 20px; cursor: pointer; transition: border-color .15s; }
.settings-bar:hover { border-color: var(--purple); }
.settings-bar-label { font-size: 11px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: .06em; margin-right: 2px; white-space: nowrap; }
.settings-bar-edit  { font-size: 11px; color: var(--purple); margin-left: auto; white-space: nowrap; }
.s-pill { font-size: 11px; background: var(--purple-lt); color: #6d28d9; border-radius: 4px; padding: 2px 7px; white-space: nowrap; }

/* ── Progress & step dots ────────────────────────────────────────────────────── */
.prog-track { height: 4px; background: var(--border); border-radius: 2px; margin-bottom: 24px; overflow: hidden; }
.prog-fill  { height: 100%; background: linear-gradient(90deg, var(--dark-blue), var(--purple)); border-radius: 2px; transition: width .4s ease; }
.step-dots { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; position: relative; }
.step-dots::before { content: ""; position: absolute; top: 11px; left: 11px; right: 11px; height: 1px; background: var(--border); z-index: 0; }
.sdot { display: flex; flex-direction: column; align-items: center; gap: 4px; flex: 1; position: relative; z-index: 1; }
.sdot-icon { width: 22px; height: 22px; border-radius: 50%; background: var(--border); border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all .25s; }
.sdot.active .sdot-icon { background: var(--dark-blue); border-color: var(--dark-blue); }
.sdot.done   .sdot-icon { background: var(--purple); border-color: var(--purple); }
.sdot.done   .sdot-icon::after { content: "\2713"; color: #fff; font-size: 11px; font-weight: 700; }
.sdot-label { font-size: 10px; color: var(--muted); text-align: center; white-space: nowrap; transition: color .25s; }
.sdot.active .sdot-label { color: var(--dark-blue); font-weight: 700; }
.sdot.done   .sdot-label { color: var(--purple); }

/* ── Step title row ──────────────────────────────────────────────────────────── */
.step-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; margin-bottom: 6px; }
.step-q-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.step-q-text { font-size: 17px; font-weight: 700; color: var(--dark-blue); line-height: 1.3; flex: 1; }
.step-q-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.more-btn-sm { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; background: #fff; border: 1.5px dashed var(--purple); border-radius: 8px; color: var(--purple); font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap; }
.more-btn-sm:hover { background: var(--purple-lt); border-style: solid; }
.more-btn-sm:disabled { opacity: .5; cursor: not-allowed; }
.cont-btn-sm { display: inline-flex; align-items: center; padding: 7px 14px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all .15s; white-space: nowrap; }
.cont-btn-sm:hover { box-shadow: 0 3px 8px rgba(15,42,68,.3); }
.cont-btn-sm:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; box-shadow: none; }

/* ── Option chips ────────────────────────────────────────────────────────────── */
.opts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.opt { padding: 9px 16px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font-size: 14px; font-weight: 500; cursor: pointer; transition: all .15s; line-height: 1; }
.opt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.opt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }
.opt-del { display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; margin-left: 6px; border-radius: 50%; font-size: 10px; color: var(--muted); background: transparent; border: none; cursor: pointer; transition: all .15s; flex-shrink: 0; vertical-align: middle; padding: 0; }
.opt-del:hover { background: #fee2e2; color: #dc2626; }

/* ── Section labels ──────────────────────────────────────────────────────────── */
.my-niches-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.my-niches-label::before { content: '⭐'; font-size: 12px; }
.divider-label { font-size: 11px; font-weight: 700; color: #bbb; text-transform: uppercase; letter-spacing: .07em; margin: 10px 0 8px; }

/* ── Custom input ────────────────────────────────────────────────────────────── */
.custom-row { display: flex; gap: 8px; margin-bottom: 6px; }
.custom-in { flex: 1; padding: 9px 12px; font-size: 13px; border: 1.5px solid var(--border); border-radius: 8px; color: var(--text); outline: none; transition: border-color .15s; background: #fff; }
.custom-in:focus { border-color: var(--purple); }
.custom-add { padding: 9px 14px; font-size: 13px; background: #f5f5f5; border: 1.5px solid var(--border); border-radius: 8px; color: var(--muted); cursor: pointer; white-space: nowrap; transition: all .15s; }
.custom-add:hover { background: var(--purple-lt); color: var(--purple); border-color: var(--purple); }

/* ── More btn (bottom) ───────────────────────────────────────────────────────── */
.more-btn { display: inline-flex; align-items: center; gap: 5px; padding: 10px 16px; background: #fff; border: 1.5px dashed var(--purple); border-radius: 10px; color: var(--purple); font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap; }
.more-btn:hover { background: var(--purple-lt); border-style: solid; }
.more-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Loading ─────────────────────────────────────────────────────────────────── */
.loading { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; padding: 16px 0; }
.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--purple); animation: blink 1.2s ease-in-out infinite; }
.dot:nth-child(2) { animation-delay: .2s; }
.dot:nth-child(3) { animation-delay: .4s; }
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }
.spin { display: inline-block; animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Navigation ──────────────────────────────────────────────────────────────── */
.nav { display: flex; align-items: center; justify-content: space-between; margin-top: 24px; padding-top: 20px; border-top: 1px solid #f0f0f0; }
.nav-back { font-size: 13px; color: var(--muted); cursor: pointer; padding: 8px 0; background: none; border: none; transition: color .15s; }
.nav-back:hover { color: var(--text); }
.nav-next { padding: 11px 28px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all .15s; }
.nav-next:hover { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.nav-next:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; box-shadow: none; }

/* ── Back to menu ────────────────────────────────────────────────────────────── */
.back-to-menu { background: none; border: none; color: var(--muted); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 4px; transition: color .15s; }
.back-to-menu:hover { color: var(--dark-blue); }

/* ── Mode select cards ───────────────────────────────────────────────────────── */
.mode-cards { display: flex; gap: 16px; flex-wrap: wrap; }
.mode-card { flex: 1; min-width: 180px; border: 1.5px solid var(--border); border-radius: 16px; padding: 22px 18px; background: var(--card); cursor: pointer; transition: all .2s; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.mode-card:hover { border-color: var(--purple); box-shadow: 0 6px 20px rgba(139,92,246,0.12); transform: translateY(-2px); }
.mode-card-icon  { font-size: 32px; margin-bottom: 10px; }
.mode-card-title { font-size: 15px; font-weight: 700; color: var(--dark-blue); margin-bottom: 8px; }
.mode-card-desc  { font-size: 13px; color: var(--muted); line-height: 1.5; margin-bottom: 12px; }
.mode-card-badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }

/* ── Video idea step ─────────────────────────────────────────────────────────── */
.used-idea { opacity: .5; cursor: not-allowed !important; background: #f3f4f6 !important; border-color: #d1d5db !important; }
.used-idea:hover { background: #f3f4f6 !important; border-color: #d1d5db !important; color: var(--text) !important; }
#step-body textarea.custom-in { display: block; min-height: 72px; height: 72px; width: 100%; resize: vertical; }

/* ── Toast ───────────────────────────────────────────────────────────────────── */
.toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--dark-blue); color: #fff; padding: 10px 22px; border-radius: 10px; font-size: 13px; font-weight: 600; z-index: 999; transition: opacity .3s; pointer-events: none; }

/* ── Footer ──────────────────────────────────────────────────────────────────── */
.site-footer { background: linear-gradient(90deg, #0f2a44, #143b63); color: rgba(255,255,255,.5); padding: 14px 20px; font-size: 12px; display: flex; justify-content: center; align-items: center; gap: 24px; flex-wrap: wrap; }
.site-footer a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .2s; }
.site-footer a:hover { color: var(--accent); }
.footer-brand { font-weight: 700; color: var(--accent); }

/* ── Settings overlay ────────────────────────────────────────────────────────── */
.settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.settings-overlay.open { display: flex; }
.settings-panel { background: #fff; border-radius: 16px; padding: 28px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.settings-title  { font-size: 17px; font-weight: 700; color: var(--dark-blue); }
.settings-close  { background: none; border: none; font-size: 22px; color: var(--muted); cursor: pointer; }
.setting-group   { margin-bottom: 20px; }
.setting-label   { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 8px; }
.setting-opts    { display: flex; flex-wrap: wrap; gap: 7px; }
.sopt { padding: 7px 13px; border: 1.5px solid var(--border); border-radius: 7px; background: #fff; color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; }
.sopt:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
.sopt.sel { background: var(--purple-lt); border-color: var(--purple); color: #5b21b6; font-weight: 600; }
.settings-save { margin-top: 8px; width: 100%; padding: 13px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
/* ── Settings tabs ───────────────────────────────────────────────────────────── */
.stab-bar { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 18px; overflow-x: auto; scrollbar-width: none; }
.stab-bar::-webkit-scrollbar { display: none; }
.stab { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 8px 10px; border: none; background: none; cursor: pointer; color: var(--muted); font-size: 11px; font-weight: 600; white-space: nowrap; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all .15s; min-width: 58px; }
.stab:hover { color: var(--purple); }
.stab-active { color: var(--purple); border-bottom-color: var(--purple); }
.stab-icon { font-size: 18px; line-height: 1; }
.stab-label { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
.setting-tab-body { min-height: 120px; }

/* ── Business settings navigation ────────────────────────────────────────────── */
.biz-back-btn { background: none; border: none; color: var(--purple); font-size: 12px; font-weight: 700; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 3px; }
.biz-back-btn:hover { text-decoration: underline; }

/* Step dots inside business modal */
.biz-step-dots { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 16px; }
.biz-sdot { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.biz-sdot-icon { width: 26px; height: 26px; border-radius: 50%; background: var(--border); border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #aaa; transition: all .2s; }
.biz-sdot-label { font-size: 10px; color: var(--muted); white-space: nowrap; }
.biz-sdot-active .biz-sdot-icon { background: var(--dark-blue); border-color: var(--dark-blue); color: #fff; }
.biz-sdot-active .biz-sdot-label { color: var(--dark-blue); font-weight: 700; }
.biz-sdot-done .biz-sdot-icon { background: var(--green); border-color: var(--green); color: #fff; }
.biz-sdot-done .biz-sdot-label { color: var(--green); }
.biz-sdot-line { flex: 1; height: 2px; background: var(--border); margin: 0 6px 14px; min-width: 28px; }

/* Breadcrumb trail */
.biz-breadcrumb { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; background: #f7f9fc; border-radius: 8px; padding: 7px 12px; margin-bottom: 14px; font-size: 12px; min-height: 34px; }
.biz-bc-item { color: var(--muted); }
.biz-bc-active { color: var(--dark-blue); font-weight: 700; }
.biz-bc-sep { color: #ccc; font-size: 10px; }

/* Done button pinned to bottom of panel content */
.biz-footer { margin-top: 18px; display: flex; justify-content: flex-end; border-top: 1px solid #f0f0f0; padding-top: 14px; }
.biz-done-btn { padding: 11px 28px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all .15s; }
.biz-done-btn:hover:not(:disabled) { box-shadow: 0 4px 12px rgba(15,42,68,.3); }
.biz-done-btn:disabled { background: var(--border); color: var(--muted); cursor: not-allowed; }

/* ── Company video ideas box ─────────────────────────────────────────────────── */
.ideas-box { background: var(--card); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow); width: 100%; max-width: 700px; overflow: hidden; animation: slideIn .25s ease; }
@keyframes slideIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
.ideas-box-header { padding: 18px 24px 16px; background: linear-gradient(90deg, #0f2a44, #143b63); }
.ideas-box-header h2 { font-size: 18px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.ideas-box-header p  { font-size: 12px; color: rgba(255,255,255,.65); }
.ideas-box-body { padding: 20px 24px; }
.ideas-context { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
.ideas-ctx-pill { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; background: var(--purple-lt); color: #5b21b6; }
.ideas-ctx-sep { color: #ccc; font-size: 11px; }
.idea-chips { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.idea-chip { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1.5px solid var(--border); border-radius: 10px; background: #fff; cursor: pointer; transition: all .15s; gap: 12px; }
.idea-chip:hover { border-color: var(--purple); background: var(--purple-lt); }
.idea-chip-text { font-size: 14px; color: var(--text); font-weight: 500; flex: 1; line-height: 1.4; }
.idea-chip-arrow { font-size: 16px; color: var(--purple); flex-shrink: 0; opacity: 0; transition: opacity .15s; }
.idea-chip:hover .idea-chip-arrow { opacity: 1; }
/* Campaign checkbox chip */
.idea-chip.camp-chip { cursor: pointer; }
.idea-chip.camp-chip.checked { border-color: var(--purple); background: var(--purple-lt); }
.camp-checkbox { width: 20px; height: 20px; border: 2px solid var(--border); border-radius: 5px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; transition: all .15s; background: #fff; color: transparent; }
.idea-chip.camp-chip.checked .camp-checkbox { background: var(--purple); border-color: var(--purple); color: #fff; }
/* Campaign duration pill selector */
.camp-dur-opt { padding: 7px 14px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; font-weight: 600; color: var(--muted); cursor: pointer; transition: all .15s; background: #fff; }
.camp-dur-opt:hover { border-color: var(--purple); color: var(--purple); }
.camp-dur-opt.sel { border-color: var(--purple); background: var(--purple-lt); color: #5b21b6; }
.ideas-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid #f0f0f0; padding-top: 16px; }
.ideas-load-more { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border: 1.5px dashed var(--purple); border-radius: 8px; background: #fff; color: var(--purple); font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s; }
.ideas-load-more:hover { background: var(--purple-lt); border-style: solid; }
.ideas-load-more:disabled { opacity: .5; cursor: not-allowed; }
.ideas-skip { font-size: 13px; color: var(--muted); cursor: pointer; background: none; border: none; padding: 8px 0; transition: color .15s; }
.ideas-skip:hover { color: var(--text); }
.ideas-empty { text-align: center; padding: 32px 20px; color: var(--muted); font-size: 14px; }
.ideas-empty-icon { font-size: 36px; margin-bottom: 10px; }

/* ── Hook cards ──────────────────────────────────────────────────────────────── */
.hook-cards { display: flex; flex-direction: column; gap: 10px; margin-bottom: 8px; }
.hook-card { border: 1.5px solid var(--border); border-radius: 10px; padding: 13px 16px; background: #fff; cursor: pointer; transition: all .15s; }
.hook-card:hover { border-color: var(--purple); background: var(--purple-lt); }
.hook-card-sel { border-color: var(--purple); background: var(--purple-lt); box-shadow: 0 0 0 3px rgba(139,92,246,.12); }
.hook-card-type { font-size: 10px; font-weight: 700; color: var(--purple); text-transform: uppercase; letter-spacing: .07em; margin-bottom: 5px; }
.hook-card-text { font-size: 14px; font-weight: 600; color: var(--dark-blue); line-height: 1.4; margin-bottom: 4px; }
.hook-card-why  { font-size: 12px; color: var(--muted); line-height: 1.4; }
.hook-type-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; margin: 12px 0 6px; }

.build-btn { margin-top:10px; width:100%; padding:14px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; transition:all .15s; }
.build-btn:hover { background:linear-gradient(135deg,var(--mid-blue),#1e4a7a); box-shadow:0 4px 12px rgba(15,42,68,.3); }
.build-btn:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }
.s2-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1100; align-items:flex-start; justify-content:center; padding-top:70px; padding-left:16px; padding-right:16px; padding-bottom:20px; overflow-y:auto; }
.s2-overlay.open { display:flex; }

/* Fixed-size panel — header/footer never move */
.s2-panel {
    background:#fff;
    border-radius:16px;
    width:100%;
    max-width:540px;
    display:flex;
    flex-direction:column;
    overflow:hidden;
    margin:0 0 20px;
    box-shadow:0 12px 40px rgba(0,0,0,0.25);
    position:relative;
}

/* Header — fixed, never scrolls */
.s2-header {
    padding:14px 20px 12px;
    background:linear-gradient(90deg,#0f2a44,#143b63);
    border-bottom:1px solid rgba(255,255,255,.1);
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-shrink:0;
}
.s2-header h2 { font-size:17px; font-weight:700; color:#fff; margin:0; }

/* Body — scrolls internally */
.s2-body {
    padding:16px 20px;
    display:flex;
    flex-direction:column;
    gap:0;
}

/* Setup panel fills body */
#s2Setup { display:flex; flex-direction:column; gap:0; }

/* Progress panel fills body */
#s2Progress { display:flex; flex-direction:column; flex:1; min-height:0; }

/* Compact one-line step rows */
.s2-steps {
    display:flex;
    flex-direction:column;
    gap:4px;
    margin:0 0 8px;
    flex-shrink:0;
}
.s2-step {
    display:flex;
    align-items:center;
    gap:8px;
    padding:6px 10px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#f8fafc;
    min-height:34px;
    overflow:hidden;
}
.s2-step.active { border-color:var(--purple); background:var(--purple-lt); }
.s2-step.done   { border-color:var(--mid-blue); background:#e8f0fe; }
.s2-step.error  { border-color:#fca5a5; background:#fef2f2; }
.s2-step-icon  { font-size:15px; flex-shrink:0; }
.s2-step-title { font-size:12px; font-weight:700; color:var(--dark-blue); white-space:nowrap; flex-shrink:0; }
.s2-step-sub   {
    font-size:11px;
    color:var(--muted);
    margin-left:2px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    flex:1;
}

/* Log — fixed height, scrolls internally, never grows modal */
.s2-log {
    background:#0f2a44;
    border-radius:8px;
    padding:10px 12px;
    max-height:110px;
    overflow-y:auto;
    font-family:monospace;
    font-size:10px;
    line-height:1.5;
    margin-top:6px;
}
.s2-log-line          { margin:0; }
.s2-log-line.info     { color:#7dd3fc; }
.s2-log-line.success  { color:var(--accent); }
.s2-log-line.warning  { color:#fde68a; }
.s2-log-line.error    { color:#fca5a5; }

/* Done bar — always at bottom, never hidden */
.s2-done-bar {
    background:#e8f0fe;
    border:1px solid var(--mid-blue);
    border-radius:10px;
    padding:10px 14px;
    margin-top:8px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    flex-shrink:0;
}
.s2-done-bar a {
    padding:9px 20px;
    background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));
    color:#fff;
    border-radius:8px;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
}

/* Processing spinner — compact */
.s2-processing-overlay {
    display:none;
    flex-direction:row;
    align-items:center;
    gap:12px;
    background:linear-gradient(135deg,#0f2a44,#143b63);
    border-radius:8px;
    padding:10px 14px;
    margin-bottom:8px;
    flex-shrink:0;
}
.s2-processing-overlay.active { display:flex; }
.s2-spinner {
    width:24px;
    height:24px;
    border:3px solid rgba(255,255,255,0.2);
    border-top-color:#5fd1ff;
    border-radius:50%;
    animation:spin 0.8s linear infinite;
    flex-shrink:0;
}
.s2-processing-msg  { color:#fff; font-size:13px; font-weight:600; }
.s2-processing-step { color:rgba(255,255,255,0.6); font-size:11px; margin-top:2px; }

/* Sections inside modal */
.s2-section { margin-bottom:14px; }
.s2-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:6px; }
.s2-select { width:100%; padding:9px 12px; font-size:13px; border:1.5px solid var(--border); border-radius:8px; background:#fff; color:var(--text); outline:none; transition:border-color .15s; }
.s2-select:focus { border-color:var(--purple); }
.s2-media-opts { display:flex; gap:8px; flex-wrap:wrap; }
.s2-media-opt { padding:8px 14px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.s2-media-opt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.s2-media-opt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.s2-start-btn { width:100%; padding:13px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; transition:all .15s; margin-top:4px; }
.s2-start-btn:hover { box-shadow:0 4px 12px rgba(15,42,68,.3); }
.s2-close { background:none; border:none; font-size:22px; color:rgba(255,255,255,.8); cursor:pointer; }
/* ── Image picker grid ── */
.s2-img-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(80px,1fr)); gap:8px; max-height:200px; overflow-y:auto; padding:4px 2px; }
.s2-img-card { position:relative; border:2px solid var(--border); border-radius:10px; overflow:hidden; cursor:pointer; transition:all .15s; background:#f8fafc; text-align:center; }
.s2-img-card:hover { border-color:var(--purple); box-shadow:0 2px 8px rgba(139,92,246,.2); }
.s2-img-card.selected { border-color:var(--purple); box-shadow:0 0 0 3px rgba(139,92,246,.25); }
.s2-img-card.taken { opacity:.35; cursor:not-allowed; }
.s2-img-card img { width:100%; aspect-ratio:3/4; object-fit:cover; display:block; }
.s2-img-label { font-size:9px; color:var(--muted); padding:3px 4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.s2-img-check { position:absolute; top:4px; right:4px; background:var(--purple); color:#fff; border-radius:50%; width:18px; height:18px; font-size:11px; display:flex; align-items:center; justify-content:center; font-weight:700; }
.s2-img-taken  { position:absolute; top:4px; right:4px; background:#ef4444; color:#fff; border-radius:50%; width:18px; height:18px; font-size:11px; display:flex; align-items:center; justify-content:center; font-weight:700; }
/* ── Role cards ── */
.s2-role-card { border:1.5px solid var(--border); border-radius:12px; padding:14px 14px 10px; margin-bottom:14px; }
.s2-host-card  { background:#f5f3ff; border-color:#c4b5fd; }   /* light purple for host */
.s2-guest-card { background:#f0f9ff; border-color:#bae6fd; }   /* light blue for guest */
.s2-speed-card { background:#f8fafc; border-color:var(--border); }
.s2-role-card-title { font-size:13px; font-weight:700; color:#1e293b; margin-bottom:10px; }
.s2-role-subsection { margin-bottom:10px; }
.s2-role-subsection:last-child { margin-bottom:0; }
.s2-sublabel { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; margin-bottom:5px; }
/* ── Gender tabs ── */
.s2-gender-tabs { display:flex; gap:6px; }
.s2-gtab { flex:1; padding:5px 8px; border:1.5px solid var(--border); border-radius:7px; background:#fff; font-size:12px; font-weight:600; color:var(--muted); cursor:pointer; transition:all .15s; }
.s2-gtab:hover { border-color:var(--purple); color:var(--purple); }
.s2-gtab.active { background:var(--purple); border-color:var(--purple); color:#fff; }
/* ── Sample audio button ── */
.s2-sample-btn { width:100%; padding:7px 12px; background:#fff; border:1.5px solid var(--border); border-radius:8px; font-size:12px; font-weight:600; color:var(--muted); cursor:pointer; transition:all .15s; display:flex; align-items:center; justify-content:center; gap:6px; }
.s2-sample-btn:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.s2-sample-btn.playing { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; }

/* ── Game Strip ── */
/* ── Game Strip ── */
/* GAMES CSS COMMENTED OUT
.game-strip{border-top:2px solid #bae6fd;background:#fff;}
*/
/* Scene preview grid */
#s2SceneGrid{border-top:1px solid #e5e7eb;background:#f8f9fa;}
#s2SceneBoxes{display:flex;flex-wrap:wrap;gap:8px;padding:4px 0;}




/* ── Duration chips ─────────────────────────────────────────────────────────── */
.dur-chips { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
.dur-chip { flex: 1; min-width: 80px; padding: 16px 10px; border: 1.5px solid var(--border); border-radius: 10px; background: #fff; cursor: pointer; text-align: center; transition: all .15s; }
.dur-chip:hover { border-color: var(--purple); background: var(--purple-lt); }
.dur-chip.sel { border-color: var(--purple); background: var(--purple-lt); }
.dur-chip-sec { font-size: 20px; font-weight: 800; color: var(--dark-blue); line-height: 1; }
.dur-chip-lbl { font-size: 10px; color: var(--muted); margin-top: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.dur-chip.sel .dur-chip-sec { color: #5b21b6; }
/* ── Script output ────────────────────────────────────────────────────────────── */
.script-box { background: #f8fafc; border: 1.5px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 16px; position: relative; }
.script-text { font-size: 15px; line-height: 1.8; color: var(--text); white-space: pre-wrap; }
.script-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.script-meta-pill { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; background: var(--purple-lt); color: #5b21b6; }
.script-actions { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
.script-copy-btn { flex: 1; padding: 11px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s; }
.script-copy-btn:hover { box-shadow: 0 3px 8px rgba(15,42,68,.25); }
.script-regen-btn { padding: 11px 18px; background: #fff; border: 1.5px solid var(--border); border-radius: 9px; font-size: 13px; font-weight: 600; color: var(--muted); cursor: pointer; transition: all .15s; }
.script-regen-btn:hover { border-color: var(--purple); color: var(--purple); background: var(--purple-lt); }
/* ── Scene cards ──────────────────────────────────────────────────────────────── */
.scene-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.scene-card { border: 1.5px solid var(--border); border-radius: 10px; background: #fff; overflow: hidden; }
.scene-card:first-child { border-color: var(--purple); }
.scene-card:last-child { border-color: var(--green); }
.scene-card-header { display: flex; align-items: center; justify-content: space-between; padding: 6px 14px; background: #f7f9fc; border-bottom: 1px solid var(--border); }
.scene-card:first-child .scene-card-header { background: var(--purple-lt); border-bottom-color: #c4b5fd; }
.scene-card:last-child .scene-card-header { background: #d1fae5; border-bottom-color: #6ee7b7; }
.scene-label { font-size: 11px; font-weight: 700; color: var(--dark-blue); text-transform: uppercase; letter-spacing: .06em; }
.scene-card:first-child .scene-label { color: #5b21b6; }
.scene-card:last-child .scene-label { color: #065f46; }
.scene-duration { font-size: 11px; color: var(--muted); font-weight: 600; }
.scene-text { padding: 12px 14px; font-size: 14px; line-height: 1.7; color: var(--text); }

/* ── TODO banner (next steps placeholder) ────────────────────────────────────── */
.todo-banner { background: linear-gradient(135deg, #fef3c7, #fffbeb); border: 1.5px dashed #f59e0b; border-radius: 12px; padding: 20px 22px; margin-top: 4px; }
.todo-banner h3 { font-size: 15px; font-weight: 700; color: #92400e; margin-bottom: 8px; }
.todo-banner p  { font-size: 13px; color: #78350f; line-height: 1.6; }
.todo-banner ul { font-size: 13px; color: #78350f; margin: 8px 0 0 16px; line-height: 1.8; }

/* ── Summary (debug only) ─────────────────────────────────────────────────────── */
.debug-summary { background: #f7f9fc; border: 1px solid var(--border); border-radius: 12px; padding: 16px 20px; margin-top: 4px; font-size: 13px; }
.debug-summary pre { white-space: pre-wrap; word-break: break-all; color: var(--dark-blue); font-family: monospace; font-size: 12px; line-height: 1.6; }
</style>
</head>
<body>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<header class="app-header">
  <a class="brand" href="index.php">
    <span style="font-size:24px;">🎬</span>
    <span class="brand-name"><span class="v">Video</span><span class="z">Vizard</span></span>
  </a>
  <a class="header-back" href="vizard_browser.php">← Home</a>
</header>

<div class="page-wrap">

  <!-- ══ MODE SELECT ══════════════════════════════════════════════════════════ -->
  <div id="modeSelect" style="width:100%;max-width:700px;">
    <div class="wiz-card" style="max-width:700px;">
      <div class="wiz-header">
        <div>
          <h1 style="font-size:20px;">Step 1: Select Script Generation Method</h1>
          <p>Choose how you'd like to create your video script</p>
        </div>
      </div>
      <div class="wiz-body">
        <div class="mode-cards">
          <div class="mode-card" data-mode="wizard">
            <div class="mode-card-icon">✨</div>
            <div class="mode-card-title">Generate Video Script</div>
            <div class="mode-card-desc">Answer a few questions — AI writes a complete, ready-to-use video script for you.</div>
            <div class="mode-card-badge" style="background:#ede9fe;color:#6d28d9;">Best for new ideas</div>
          </div>
          <div class="mode-card" data-mode="campaign">
            <div class="mode-card-icon">📅</div>
            <div class="mode-card-title">Generate Campaign</div>
            <div class="mode-card-desc">Plan a week, month or custom period. AI generates multiple scripts at once.</div>
            <div class="mode-card-badge" style="background:#fef3c7;color:#92400e;">Best for content planning</div>
          </div>
          <div class="mode-card" data-mode="content">
            <div class="mode-card-icon">📄</div>
            <div class="mode-card-title">I Have Content</div>
            <div class="mode-card-desc">Paste your own text or script — it gets formatted and split into scenes automatically.</div>
            <div class="mode-card-badge" style="background:#dbeafe;color:#1e40af;">Best for existing content</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ VIDEO IDEAS BOX (shown when company profile is complete) ═══════════════ -->
  <div id="modeIdeas" style="display:none;width:100%;max-width:700px;">
    <div class="ideas-box">
      <div class="ideas-box-header">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div>
            <h2>🎬 Video Ideas for You</h2>
            <p id="ideas-box-subtitle">Based on your business profile</p>
          </div>
          <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
        </div>
      </div>
      <div class="ideas-box-body">
        <!-- Business Settings bar -->
        <div class="settings-bar" onclick="openBusinessSettings()" style="margin-bottom:6px;">
          <span class="settings-bar-label">Business</span>
          <span id="ideas-business-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <!-- Video Settings bar -->
        <div class="settings-bar" onclick="openSettings()" style="margin-bottom:16px;">
          <span class="settings-bar-label">Video</span>
          <span id="ideas-video-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div class="ideas-context" id="ideas-context-pills"></div>
        <div id="ideas-chip-list" class="idea-chips"></div>
        <div class="ideas-actions">
          <button class="ideas-load-more" id="ideas-load-more-btn" onclick="loadMoreIdeas()" style="display:none;">
            + More Ideas
          </button>
          <button class="ideas-skip" onclick="skipIdeas()">
            Browse all options →
          </button>
        </div>
        <div style="display:flex;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f0;">
          <input class="custom-in" id="ideas-custom-in" style="flex:1;font-size:13px;" placeholder="Or type your own video topic…">
          <button onclick="addCustomIdea()" style="padding:9px 16px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">Use This →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ WIZARD (steps 1-3) ═══════════════════════════════════════════════════ -->
  <div id="modeWizard" style="display:none;width:100%;max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-header">
        <div>
          <h1 id="cardTitle">Generate Video Script</h1>
          <p id="cardSubtitle">Answer a few questions to generate your video script</p>
        </div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-body">

        <!-- Back to menu -->
        <div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
          <button class="nav-back" id="backBtn" onclick="goBack()" style="visibility:hidden;">← Back</button>
        </div>

        <!-- Business Settings bar -->
        <div class="settings-bar" onclick="openBusinessSettings()">
          <span class="settings-bar-label">Business</span>
          <span id="business-bar-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <!-- Video Settings bar -->
        <div class="settings-bar" onclick="openSettings()" style="margin-top:6px;">
          <span class="settings-bar-label">Video</span>
          <span id="settings-bar-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <!-- Progress bar -->
        <div class="prog-track"><div class="prog-fill" id="prog"></div></div>

        <!-- Step dots -->
        <div class="step-dots" id="step-dots">
          <div class="sdot" data-step="0"><span class="sdot-icon"></span><span class="sdot-label">Group</span></div>
          <div class="sdot" data-step="1"><span class="sdot-icon"></span><span class="sdot-label">Industry</span></div>
          <div class="sdot" data-step="2"><span class="sdot-icon"></span><span class="sdot-label">Niche</span></div>
          <div class="sdot" data-step="3"><span class="sdot-icon"></span><span class="sdot-label">Video Idea</span></div>
          <div class="sdot" data-step="4"><span class="sdot-icon"></span><span class="sdot-label">Hook</span></div>
          <div class="sdot" data-step="5"><span class="sdot-icon"></span><span class="sdot-label">Duration</span></div>
          <div class="sdot" data-step="6"><span class="sdot-icon"></span><span class="sdot-label">CTA</span></div>
          <div class="sdot" data-step="7"><span class="sdot-icon"></span><span class="sdot-label">Voice</span></div>
        </div>

        <!-- Step content -->
        <div id="step-label" class="step-label"></div>
        <div class="step-q-row" id="step-q-row">
          <div class="step-q-text" id="step-q"></div>
          <div class="step-q-actions" id="step-q-actions" style="display:none;"></div>
        </div>
        <div id="step-body"></div>

        <!-- Bottom nav -->
        <div class="nav" id="nav-bar" style="display:none;">
          <button class="nav-next" id="nextBtn" disabled onclick="goNext()">Continue →</button>
        </div>

      </div>
    </div>
  </div>

  <!-- ══ CAMPAIGN ══════════════════════════════════════════════════════════ -->
  <div id="modeCampaign" style="display:none;width:100%;max-width:700px;">
    <div class="ideas-box">
      <div class="ideas-box-header">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div>
            <h2>📅 Generate Campaign</h2>
            <p id="camp-subtitle">Select video ideas to build your content calendar</p>
          </div>
          <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
        </div>
      </div>
      <div class="ideas-box-body">

        <!-- Business Settings bar -->
        <div class="settings-bar" onclick="openBusinessSettings()" style="margin-bottom:6px;">
          <span class="settings-bar-label">Business</span>
          <span id="camp-business-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <!-- Video Settings bar -->
        <div class="settings-bar" onclick="openSettings()" style="margin-bottom:16px;">
          <span class="settings-bar-label">Video</span>
          <span id="camp-video-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <!-- Context breadcrumb -->
        <div class="ideas-context" id="camp-context-pills"></div>

        <!-- Selection counter -->
        <div id="camp-counter-bar" style="display:none;background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:8px 14px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
          <span style="font-size:13px;font-weight:600;color:#5b21b6;"><span id="camp-sel-count">0</span> video idea<span id="camp-sel-plural">s</span> selected</span>
          <div style="display:flex;gap:8px;">
            <button onclick="campSelectAll()" style="font-size:12px;padding:4px 12px;border:1.5px solid #8b5cf6;background:#fff;color:#6d28d9;border-radius:6px;cursor:pointer;font-weight:600;">Select All</button>
            <button onclick="campClearAll()" style="font-size:12px;padding:4px 12px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;border-radius:6px;cursor:pointer;font-weight:600;">Clear</button>
          </div>
        </div>

        <!-- Ideas list (checkbox mode) -->
        <div id="camp-chip-list" class="idea-chips"></div>

        <!-- Load more / actions -->
        <div class="ideas-actions" style="margin-bottom:12px;">
          <button class="ideas-load-more" id="camp-load-more-btn" onclick="campLoadMoreIdeas()" style="display:none;">
            + More Ideas
          </button>
          <button class="ideas-skip" onclick="goToMenu()">
            ← Back to options
          </button>
        </div>

        <!-- Generate Campaign button + credit info -->
        <div id="camp-schedule-section" style="display:none;margin-bottom:14px;padding:14px 16px;background:#f7f9fc;border:1px solid var(--border);border-radius:10px;">
          <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:12px;">📅 Schedule</div>
          <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <div style="flex:1;min-width:160px;">
              <label style="display:block;font-size:12px;font-weight:600;color:var(--dark-blue);margin-bottom:5px;">Start Date</label>
              <input type="date" id="camp-start-date" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;font-weight:600;color:var(--dark-blue);outline:none;background:#fff;" oninput="campUpdateSchedulePreview()">
            </div>
            <div style="flex:1;min-width:160px;">
              <label style="display:block;font-size:12px;font-weight:600;color:var(--dark-blue);margin-bottom:5px;">Posting Frequency</label>
              <select id="camp-frequency" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;color:var(--dark-blue);outline:none;background:#fff;" onchange="campUpdateSchedulePreview()">
                <option value="1">Daily (every day)</option>
                <option value="2">Every 2nd day</option>
                <option value="3">Every 3rd day</option>
                <option value="7">Weekly</option>
              </select>
            </div>
          </div>
          <div id="camp-schedule-preview" style="margin-top:10px;font-size:12px;color:var(--muted);display:none;"></div>
        </div>
        <div id="camp-credit-bar" style="display:none;background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:12px;font-size:13px;color:#166534;">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span>💳 <strong id="camp-credit-needed">0</strong> credit<span id="camp-credit-plural">s</span> needed &nbsp;·&nbsp; You have <strong id="camp-credit-balance">…</strong></span>
            <span id="camp-credit-warn" style="display:none;color:#dc2626;font-weight:700;font-size:12px;">⚠ Not enough credits</span>
          </div>
        </div>

        <button id="camp-generate-btn" onclick="campConfirmAndCreate()" style="width:100%;padding:14px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;opacity:.5;" disabled>
          📅 Create Campaign — <span id="camp-gen-count">0</span> Videos
        </button>

        <!-- Results area -->
        <div id="camp-results"></div>

      </div>
    </div>
  </div>

  <!-- ══ I HAVE CONTENT (placeholder) ════════════════════════════════════════ -->
  <!-- ══ I HAVE CONTENT ═══════════════════════════════════════════════════════ -->
  <div id="modeContent" style="display:none;width:100%;max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-header">
        <div>
          <h1>📄 I Have Content</h1>
          <p>Paste your script — we'll format it into scenes</p>
        </div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-body">

        <div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
        </div>

        <!-- Business settings bar -->
        <div class="settings-bar" onclick="openBusinessSettings()" style="margin-bottom:6px;">
          <span class="settings-bar-label">Business</span>
          <span id="content-business-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <!-- Video settings bar — reel type locked to Standard -->
        <div class="settings-bar" onclick="openSettings()" style="margin-bottom:20px;">
          <span class="settings-bar-label">Video</span>
          <span id="content-video-pills"></span>
          <span style="font-size:11px;background:#dbeafe;color:#1e40af;border-radius:4px;padding:2px 7px;white-space:nowrap;">Standard (fixed)</span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>

        <!-- Video Title -->
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:13px;font-weight:700;color:var(--dark-blue);margin-bottom:6px;">Video Title</label>
          <input type="text" id="content-title"
            placeholder="e.g. 5 Ways to Reduce Stress"
            style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;outline:none;color:var(--text);transition:border-color .15s;"
            onfocus="this.style.borderColor='var(--purple)'"
            onblur="this.style.borderColor='var(--border)'">
        </div>

        <!-- Script paste area -->
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:13px;font-weight:700;color:var(--dark-blue);margin-bottom:6px;">Your Script / Story</label>
          <textarea id="content-raw-script" rows="9"
            placeholder="Paste your script, blog post, or story here…&#10;&#10;Write each sentence or scene on its own line, or leave it as a single block — we'll split it automatically."
            style="width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;line-height:1.7;resize:vertical;outline:none;color:var(--text);transition:border-color .15s;"
            onfocus="this.style.borderColor='var(--purple)'"
            onblur="this.style.borderColor='var(--border)'"></textarea>
          <div style="font-size:11px;color:var(--muted);margin-top:5px;">Tip: put each scene on its own line for best results, or paste as a block and we'll split it.</div>
        </div>

        <!-- Format button -->
        <button id="content-format-btn" onclick="contentFormatScript()"
          style="width:100%;padding:13px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;margin-bottom:10px;">
          📝 Format into Scenes
        </button>

        <!-- Output: editable script + approve button (injected after processing) -->
        <div id="content-script-output"></div>

      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

<!-- ── Video Settings overlay ────────────────────────────────────────────── -->
<div id="settingsOverlay" class="settings-overlay" onclick="overlayClick(event)">
  <div class="settings-panel">
    <div class="settings-header">
      <span class="settings-title">⚙️ Video Settings</span>
      <button class="settings-close" onclick="closeSettings()">✕</button>
    </div>
    <div id="settings-content"></div>
    <button class="settings-save" onclick="saveSettings()">✓ Save</button>
    <p style="font-size:12px;color:#bbb;margin-top:10px;text-align:center;">Settings apply to all new videos you create.</p>
  </div>
</div>

<!-- ── Business Settings overlay ─────────────────────────────────────────── -->
<div id="businessOverlay" class="settings-overlay" onclick="businessOverlayClick(event)">
  <div class="settings-panel">
    <div class="settings-header">
      <span class="settings-title">🏢 Business Settings</span>
      <button class="settings-close" onclick="closeBusinessSettings()">✕</button>
    </div>
    <div id="business-content"></div>
    <p style="font-size:11px;color:#bbb;margin-top:6px;text-align:center;">Saved to your company profile · Select all 3 to complete</p>
  </div>
</div>

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<!-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════════ -->
<script>
// ── Quota — declared first ──────────────────────────────────────────────────
let _quota = { credit_balance: 999, plan_type: 'free_trial', exceeded: false };
let _quotaLoaded = false;

// ═════════════════════════════════════════════════════════════════════════════
// CONSTANTS
// ═════════════════════════════════════════════════════════════════════════════
const IS_FREE_TRIAL    = <?= $js_is_free_trial ?>;

// ── Language name → ISO code ──────────────────────────────────────────────────
const LANG_CODE_MAP = {
    'english':'en','arabic':'ar','spanish':'es','french':'fr','urdu':'ur',
    'hindi':'hi','gujarati':'gu','punjabi':'pa','tamil':'ta',
    'mandarin chinese':'zh','mandarin':'zh','farsi':'fa','bengali':'bn',
    'portuguese':'pt','russian':'ru','japanese':'ja','korean':'ko',
    'german':'de','dutch':'nl','italian':'it','turkish':'tr','polish':'pl',
    'romanian':'ro','czech':'cs','slovak':'sk','hungarian':'hu',
    'swedish':'sv','norwegian':'no','danish':'da','finnish':'fi',
    'bulgarian':'bg','croatian':'hr','serbian':'sr','ukrainian':'uk',
    'albanian':'sq','greek':'el','slovenian':'sl',
};
function langCodeFromName(name) {
    return LANG_CODE_MAP[(name || 'english').toLowerCase().trim()] || 'en';
}

// ═════════════════════════════════════════════════════════════════════════════
// CREDIT QUOTA
// ═════════════════════════════════════════════════════════════════════════════

async function loadVideoQuota() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_video_quota');
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            _quota = d;
            _quotaLoaded = true;
            _updateCreditDisplay();
        }
    } catch(e) {}
}

function reelCreditCost() {
    const rt = (settings.reel_type || '').toLowerCase();
    if (rt.includes('podcast') || rt.includes('talking head')) return 2;
    return 1;
}

function isQuotaExceeded(cost) {
    const c = cost ?? reelCreditCost();
    return _quota.credit_balance < c;
}

function _updateCreditDisplay() {
    const el = document.getElementById('s2CreditBalance');
    const cost = reelCreditCost();
    const bal  = _quota.credit_balance;
    if (el) el.innerHTML = '<strong>' + bal + '</strong> &nbsp;(this video costs <strong>' + cost + '</strong>)';
}

async function deductCredit(amount, description) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'deduct_video_credit');
        fd.append('amount',      amount);
        fd.append('description', description || 'Video generation');
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            _quota.credit_balance = d.new_balance;
            _quota.exceeded = d.new_balance <= 0;
            _updateCreditDisplay();
        }
        return d;
    } catch(e) {
        return { success: false, message: e.message };
    }
}

function showQuotaModal() {
    const credits = _quota.credit_balance;
    const plan    = _quota.plan_type || 'free_trial';
    const planLabel = plan === 'free_trial' ? 'Free Trial'
                    : plan === 'personal'   ? 'Personal Plan'
                    : plan === 'agency'     ? 'Agency Plan' : plan;
    const returnUrl = encodeURIComponent(window.location.pathname + window.location.search);

    let body = '';
    if (plan === 'free_trial') {
        body = '<div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);padding:28px;text-align:center;">'
             + '<div style="font-size:40px;margin-bottom:10px;">🚀</div>'
             + '<h2 style="font-size:20px;font-weight:800;color:#fff;margin:0 0 10px;">Free trial limit reached</h2>'
             + '<p style="font-size:13px;color:rgba(255,255,255,.78);margin:0;">Choose a plan to keep creating.</p>'
             + '</div>'
             + '<div style="padding:20px;display:flex;flex-direction:column;gap:10px;">'
             + '<a href="/pricing_free_trial.php?return_url=' + returnUrl + '" onclick="closeQuotaModal()" style="display:block;text-align:center;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:14px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;">View Plans →</a>'
             + '<button onclick="closeQuotaModal()" style="background:none;border:1.5px solid #e2e8f0;border-radius:10px;color:#64748b;font-size:13px;font-weight:600;padding:12px;cursor:pointer;">Maybe Later</button>'
             + '</div>';
    } else {
        body = '<div style="background:linear-gradient(135deg,#0f2a44,#1a4a7a);padding:28px;text-align:center;">'
             + '<div style="font-size:40px;margin-bottom:10px;">💳</div>'
             + '<h2 style="font-size:20px;font-weight:800;color:#fff;margin:0 0 10px;">Out of credits</h2>'
             + '<p style="font-size:13px;color:rgba(255,255,255,.78);margin:0;">' + planLabel + ' · Balance: ' + credits + ' credits</p>'
             + '</div>'
             + '<div style="padding:20px;display:flex;flex-direction:column;gap:10px;">'
             + '<a href="/pricing.php?return_url=' + returnUrl + '" onclick="closeQuotaModal()" style="display:block;text-align:center;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;padding:14px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;">Buy Credits →</a>'
             + '<button onclick="closeQuotaModal()" style="background:none;border:1.5px solid #e2e8f0;border-radius:10px;color:#64748b;font-size:13px;font-weight:600;padding:12px;cursor:pointer;">Maybe Later</button>'
             + '</div>';
    }

    document.getElementById('quotaModalBody').innerHTML = body;
    document.getElementById('quotaOverlay').style.display = 'flex';
}

function closeQuotaModal() {
    document.getElementById('quotaOverlay').style.display = 'none';
}
const PHP_AUDIENCES    = <?= json_encode($php_audiences) ?>;
const PHP_TONES        = <?= json_encode($php_tones) ?>;
const PHP_COMPANY_LANG = <?= json_encode($php_company_lang) ?>;
const PHP_BRAND_NAME   = <?= json_encode($php_brand_name) ?>;
// Company industry defaults (from hdb_companies — blank = not set yet)
const PHP_CO_GROUP    = <?= json_encode($php_co_group) ?>;
const PHP_CO_SUBGROUP = <?= json_encode($php_co_subgroup) ?>;
const PHP_CO_NICHE    = <?= json_encode($php_co_niche) ?>;

// ── Step definitions (steps 0-2 active; placeholders for 3+) ─────────────────
const STEPS = [
    // ── Step 0: Group (core_group from hdb_master_groups) ─────────────────────
    {
        key:   'industry_group',
        label: 'Step 1 of 7',
        q:     'Select your industry group',
        type:  'group-select',
    },
    // ── Step 1: Sub-group (industry_desc rows for selected core_group) ─────────
    {
        key:   'industry_desc',
        label: 'Step 2 of 7',
        q:     'Select your industry',
        type:  'subgroup-select',
    },
    // ── Step 2: Niche ─────────────────────────────────────────────────────────
    {
        key:   'niche',
        label: 'Step 3 of 7',
        q:     'Select your niche',
        type:  'niche-db-select',
        morePrompt: (existing, curAns) =>
            `List 10 specific niche ideas for social media video campaigns in the "${(curAns||{}).industry_desc || 'general'}" industry. These should be specific professions, services or topics. Do NOT repeat: ${existing.join(', ')}. Return ONLY a valid JSON array of strings.`,
    },
    // ── Step 3: Video Idea ────────────────────────────────────────────────────
    {
        key:   'title',
        label: 'Step 4 of 7',
        q:     'Choose a video idea',
        type:  'video-idea-select',
    },
    // ── Step 4: Hook ──────────────────────────────────────────────────────────────
    {
        key:   'hook',
        label: 'Step 5 of 7',
        q:     'Choose your opening hook',
        type:  'hook-select',
    },
    // ── Step 5: Duration ──────────────────────────────────────────────────────────
    {
        key:   'duration',
        label: 'Step 6 of 7',
        q:     'How long is your video?',
        type:  'duration-select',
    },
    // ── Step 6: CTA ───────────────────────────────────────────────────────────────
    {
        key:   'cta',
        label: 'Step 7 of 8',
        q:     'Choose a call to action',
        type:  'cta-select',
    },
    // ── Step 7: Voice ─────────────────────────────────────────────────────────────
    {
        key:   'voice_id',
        label: 'Step 8 of 8',
        q:     'Choose your voice',
        type:  'voice-select',
    },
];

// ── Settings definitions ──────────────────────────────────────────────────────
const SETTING_DEFS = {
    language:        { opts:['Albanian','Arabic','Bengali','Bulgarian','Croatian','Czech','Danish','Dutch','English','Farsi','Finnish','French','German','Gujarati','Greek','Hindi','Hungarian','Japanese','Korean','Mandarin Chinese','Norwegian','Polish','Portuguese','Punjabi','Romanian','Russian','Serbian','Slovak','Slovenian','Spanish','Swedish','Tamil','Turkish','Ukrainian','Urdu'], def: PHP_COMPANY_LANG || 'English' },
    reel_type:       { opts:['Standard','B-Roll (Voiceover)','Podcast Style','Talking Head'], def:'Standard' },
    content_goals:   { opts:['Education','Inform','Promote'], def:'Education' },
    growth_goals:    { opts:['Generate Leads','Increase Sales','Grow Followers','Boost Engagement','Drive Traffic'], def:'Grow Followers' },
    audience:        { opts: PHP_AUDIENCES, def:'General Public' },
    tone:            { opts: PHP_TONES, def:'Friendly' },
};
const SETTING_LABELS = {
    language:'Language', reel_type:'Reel Type', content_goals:'Content Goals',
    growth_goals:'Growth Goals', audience:'Target Audience', tone:'Tone',
};

// ═════════════════════════════════════════════════════════════════════════════
// STATE
// ═════════════════════════════════════════════════════════════════════════════
let settings  = {};
let cur       = 0;
let ans       = {};
let stepOpts  = {};
let userNiches       = [];
let userCategories   = {};
let userVideoIdeas   = [];

// Video idea pagination globals
let videoIdeasCurrentPage = 1;
let videoIdeasTotalCount  = 0;
let videoIdeasHasMore     = false;
let videoIdeasNiche       = '';
let videoIdeasCategory    = '';
let videoIdeasMyList      = [];
let videoIdeasCommonList  = [];
let videoIdeasAiSuggestions = [];
let videoIdeasShowAi      = false;

// ═════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ═════════════════════════════════════════════════════════════════════════════
function esc(s)     { return String(s).replace(/"/g,'&quot;'); }
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function showToast(msg) {
    const t = Object.assign(document.createElement('div'), { className:'toast', textContent:msg });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; setTimeout(() => t.remove(), 400); }, 1800);
}

// ═════════════════════════════════════════════════════════════════════════════
// SETTINGS
// ═════════════════════════════════════════════════════════════════════════════

// ── Company industry state (synced to hdb_companies) ─────────────────────────
const coIndustry = {
    group:    PHP_CO_GROUP    || '',
    subgroup: PHP_CO_SUBGROUP || '',
    niche:    PHP_CO_NICHE    || '',
};
console.log('[VPS Debug] coIndustry:', coIndustry);
console.log('[VPS Debug] PHP_CO_GROUP:', PHP_CO_GROUP, '| PHP_CO_SUBGROUP:', PHP_CO_SUBGROUP, '| PHP_CO_NICHE:', PHP_CO_NICHE);

// ── Render the Business settings bar pills ────────────────────────────────────
function renderBusinessBar() {
    const pills = [coIndustry.group, coIndustry.subgroup, coIndustry.niche].filter(Boolean);
    const html = pills.length
        ? pills.map(function(v) { return '<span class="s-pill">' + escHtml(v) + '</span>'; }).join('')
        : '<span style="font-size:11px;color:#aaa;font-style:italic;">Not set</span>';
    ['business-bar-pills','ideas-business-pills','camp-business-pills','content-business-pills'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = html;
    });
}

// ── Render the Video settings bar pills ───────────────────────────────────────
function renderSettingsBar() {
    const pillMap = [
        { key: 'language',      val: settings.language },
        { key: 'reel_type',     val: (settings.reel_type || '').split(' (')[0] },
        { key: 'content_goals', val: settings.content_goals },
        { key: 'growth_goals',  val: settings.growth_goals },
        { key: 'audience',      val: settings.audience },
        { key: 'tone',          val: settings.tone },
    ];
    const html = pillMap.filter(function(p) { return p.val; }).map(function(p) {
        return '<span class="s-pill" style="cursor:pointer;" onclick="openSettings(\'' + p.key + '\')" title="Edit ' + escHtml(SETTING_LABELS[p.key] || p.key) + '">' + escHtml(p.val) + '</span>';
    }).join('');
    ['settings-bar-pills','ideas-video-pills','camp-video-pills','content-video-pills'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = html;
    });
}

function loadSettings() {
    try { settings = JSON.parse(localStorage.getItem('vw_settings') || '{}'); } catch(e) { settings = {}; }
    Object.keys(SETTING_DEFS).forEach(k => { if (!settings[k]) settings[k] = SETTING_DEFS[k].def; });
    renderSettingsBar();
    renderBusinessBar();
}

// ─────────────────────────────────────────────────────────────────────────────
// VIDEO SETTINGS  (Language, Reel Type, Goals, Audience, Tone)
// ─────────────────────────────────────────────────────────────────────────────
// ── Settings tab state ───────────────────────────────────────────────────────
let _activeSettingTab = 'language';

function openSettings(tabKey) {
    _activeSettingTab = tabKey || _activeSettingTab || Object.keys(SETTING_LABELS)[0];
    _renderSettingsTabs();
    document.getElementById('settingsOverlay').classList.add('open');
}

function _renderSettingsTabs() {
    const keys   = Object.keys(SETTING_LABELS);
    const active = _activeSettingTab;
    const def    = SETTING_DEFS[active];

    // Tab icons
    const TAB_ICONS = { language:'🌐', reel_type:'🎬', content_goals:'🎯', growth_goals:'📈', audience:'👥', tone:'🎭' };

    const tabsHtml = keys.map(k => {
        const isActive = k === active;
        return `<button class="stab${isActive ? ' stab-active' : ''}" onclick="switchSettingTab('${k}')" title="${SETTING_LABELS[k]}">
            <span class="stab-icon">${TAB_ICONS[k] || '•'}</span>
            <span class="stab-label">${SETTING_LABELS[k]}</span>
        </button>`;
    }).join('');

    const optsHtml = def.opts.map(o =>
        `<div class="sopt${settings[active]===o?' sel':''}" onclick="selectSopt(this,'${active}')" data-v="${esc(o)}">${escHtml(o)}</div>`
    ).join('');

    document.getElementById('settings-content').innerHTML = `
        <div class="stab-bar">${tabsHtml}</div>
        <div class="setting-tab-body">
            <div class="setting-opts" id="setting-opts-wrap">${optsHtml}</div>
        </div>`;
}

function switchSettingTab(key) {
    _activeSettingTab = key;
    _renderSettingsTabs();
}

function selectSopt(el, key) {
    const wrap = document.getElementById('setting-opts-wrap');
    if (wrap) wrap.querySelectorAll('.sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    settings[key] = el.dataset.v;
}

function closeSettings() {
    document.getElementById('settingsOverlay').classList.remove('open');
    renderSettingsBar();
}

function saveSettings() {
    localStorage.setItem('vw_settings', JSON.stringify(settings));
    closeSettings();
    showToast('Video settings saved ✓');
}

function overlayClick(e) {
    if (e.target === document.getElementById('settingsOverlay')) closeSettings();
}

// ─────────────────────────────────────────────────────────────────────────────
// BUSINESS SETTINGS  (Group → Sub-group → Niche)
// Flow: pick chip → Done button advances to next panel
//       Back button returns to previous panel
//       Final Done saves all 3 fields to hdb_companies and closes modal
// ─────────────────────────────────────────────────────────────────────────────

// Temp selections held while user moves through panels — only committed on final Done
const bizTemp = { group:'', subgroup:'', subgroupId:'', niche:'' };

// Which panel: 'group' | 'subgroup' | 'niche'
let bizPanel = 'group';

async function openBusinessSettings() {
    // Seed temp from what's already saved
    bizTemp.group    = coIndustry.group    || '';
    bizTemp.subgroup = coIndustry.subgroup || '';
    bizTemp.niche    = coIndustry.niche    || '';
    bizTemp.subgroupId = '';

    bizPanel = 'group';
    document.getElementById('businessOverlay').classList.add('open');

    if (!window._masterGroups || window._masterGroups.length === 0) {
        _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading…</span></div>`);
        try {
            const d = await post({ ajax_action:'get_master_industries' });
            if (d.success) window._masterGroups = d.groups || [];
        } catch(e) {}
    }
    _renderBizGroupPanel();
}

function _renderBizPanel(html) {
    document.getElementById('business-content').innerHTML = html;
}

// ── Breadcrumb strip ──────────────────────────────────────────────────────────
function _bizBreadcrumb() {
    const parts = [bizTemp.group, bizTemp.subgroup, bizTemp.niche].filter(Boolean);
    if (!parts.length) return '';
    return `<div class="biz-breadcrumb">
      ${parts.map((p,i) => `<span class="biz-bc-item${i===parts.length-1?' biz-bc-active':''}">${escHtml(p)}</span>`).join('<span class="biz-bc-sep">›</span>')}
    </div>`;
}

// ── Step indicator ────────────────────────────────────────────────────────────
function _bizStepDots(active) {
    const steps = ['Group','Industry','Niche'];
    return `<div class="biz-step-dots">
      ${steps.map((s,i) => `<div class="biz-sdot${i<active?' biz-sdot-done':i===active?' biz-sdot-active':''}">
        <span class="biz-sdot-icon">${i<active?'✓':i+1}</span>
        <span class="biz-sdot-label">${s}</span>
      </div>`).join('<div class="biz-sdot-line"></div>')}
    </div>`;
}

// ── PANEL 1: Group ────────────────────────────────────────────────────────────
function _renderBizGroupPanel() {
    bizPanel = 'group';
    const groups = window._masterGroups || [];

    const chips = groups.map(g =>
        `<div class="sopt${bizTemp.group===g.core_group?' sel':''}"
            onclick="bizTempSelectGroup(this)"
            data-v="${esc(g.core_group)}">${escHtml(g.core_group)}</div>`
    ).join('');

    _renderBizPanel(`
      ${_bizStepDots(0)}
      ${_bizBreadcrumb()}
      <div class="setting-group">
        <div class="setting-label">Select your industry group</div>
        <div class="setting-opts" id="biz-group-opts">${chips}</div>
      </div>
      <div class="biz-footer">
        <button class="biz-done-btn" id="biz-done-group" onclick="_bizGroupDone()"
            ${bizTemp.group ? '' : 'disabled'}>Done →</button>
      </div>`);
}

function bizTempSelectGroup(el) {
    document.querySelectorAll('#biz-group-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    const newGroup = el.dataset.v;
    if (bizTemp.group !== newGroup) {
        bizTemp.group = newGroup;
        bizTemp.subgroup = '';
        bizTemp.subgroupId = '';
        bizTemp.niche = '';
        delete window['_sgCache_' + newGroup];
    }
    document.getElementById('biz-done-group').disabled = false;
}

async function _bizGroupDone() {
    if (!bizTemp.group) return;
    await _renderBizSubgroupPanel();
}

// ── PANEL 2: Sub-group ────────────────────────────────────────────────────────
async function _renderBizSubgroupPanel() {
    bizPanel = 'subgroup';
    const cacheKey = '_sgCache_' + bizTemp.group;

    _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading industries…</span></div>`);

    if (!window[cacheKey]) {
        try {
            const d = await post({ ajax_action:'get_master_subgroups', core_group:bizTemp.group });
            window[cacheKey] = d.success ? (d.subgroups || []) : [];
        } catch(e) { window[cacheKey] = []; }
    }

    // Resolve subgroupId from cache if not set
    if (bizTemp.subgroup && !bizTemp.subgroupId) {
        const match = (window[cacheKey] || []).find(s => s.industry_desc === bizTemp.subgroup);
        if (match) bizTemp.subgroupId = match.id;
    }

    const subs = window[cacheKey];
    const chips = subs.map(sg =>
        `<div class="sopt${bizTemp.subgroup===sg.industry_desc?' sel':''}"
            onclick="bizTempSelectSubgroup(this)"
            data-v="${esc(sg.industry_desc)}"
            data-id="${sg.id}">${escHtml(sg.industry_desc)}</div>`
    ).join('');

    _renderBizPanel(`
      ${_bizStepDots(1)}
      ${_bizBreadcrumb()}
      <div class="setting-group">
        <div class="setting-label">Select your industry
          <button class="biz-back-btn" style="margin-left:8px;" onclick="_renderBizGroupPanel()">← Back</button>
        </div>
        <div class="setting-opts" id="biz-subgroup-opts">${chips}</div>
      </div>
      <div class="biz-footer">
        <button class="biz-done-btn" id="biz-done-subgroup" onclick="_bizSubgroupDone()"
            ${bizTemp.subgroup ? '' : 'disabled'}>Done →</button>
      </div>`);
}

function bizTempSelectSubgroup(el) {
    document.querySelectorAll('#biz-subgroup-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    if (bizTemp.subgroup !== el.dataset.v) {
        bizTemp.subgroup   = el.dataset.v;
        bizTemp.subgroupId = el.dataset.id;
        bizTemp.niche      = '';
    }
    document.getElementById('biz-done-subgroup').disabled = false;
}

async function _bizSubgroupDone() {
    if (!bizTemp.subgroup) return;
    await _renderBizNichePanel();
}

// ── PANEL 3: Niche ────────────────────────────────────────────────────────────
async function _renderBizNichePanel() {
    bizPanel = 'niche';
    _renderBizPanel(`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading niches…</span></div>`);

    // Load master niches for selected subgroup id
    let masterNiches = [];
    if (bizTemp.subgroupId) {
        try {
            const d = await post({ ajax_action:'get_master_niches', industry_ids:bizTemp.subgroupId });
            if (d.success) masterNiches = d.niches || [];
        } catch(e) {}
    }

    // Also load user niches
    let userNicheList = [];
    try {
        const d2 = await post({ ajax_action:'get_user_niches' });
        if (d2.success) userNicheList = d2.niches || [];
    } catch(e) {}

    const userSet   = new Set(userNicheList.map(n => n.toLowerCase()));
    const masterOnly = masterNiches.filter(n => !userSet.has(n.niche_desc.toLowerCase()));

    let chipsHtml = '';
    if (userNicheList.length > 0) {
        chipsHtml += `<div class="my-niches-label">My Niches</div>
          <div class="setting-opts biz-niche-opts" style="margin-bottom:10px;">
            ${userNicheList.map(n => `<div class="sopt${bizTemp.niche===n?' sel':''}"
                onclick="bizTempSelectNiche(this)" data-v="${esc(n)}">${escHtml(n)}</div>`).join('')}
          </div>`;
    }
    if (masterOnly.length > 0) {
        chipsHtml += `${userNicheList.length ? '<div class="divider-label">More Niches</div>' : ''}
          <div class="setting-opts biz-niche-opts">
            ${masterOnly.map(n => `<div class="sopt${bizTemp.niche===n.niche_desc?' sel':''}"
                onclick="bizTempSelectNiche(this)" data-v="${esc(n.niche_desc)}">${escHtml(n.niche_desc)}</div>`).join('')}
          </div>`;
    }
    if (!chipsHtml) {
        chipsHtml = `<p style="font-size:13px;color:#aaa;margin:8px 0;">No niches found — type your own below.</p>`;
    }

    _renderBizPanel(`
      ${_bizStepDots(2)}
      ${_bizBreadcrumb()}
      <div class="setting-group">
        <div class="setting-label">Select your niche
          <button class="biz-back-btn" style="margin-left:8px;" onclick="_renderBizSubgroupPanel()">← Back</button>
        </div>
        ${chipsHtml}
        <div class="custom-row" style="margin-top:12px;">
          <input class="custom-in" id="biz-niche-in" placeholder="Or type your own niche…">
          <button class="custom-add" id="biz-niche-add">Add</button>
        </div>
      </div>
      <div class="biz-footer">
        <button class="biz-done-btn" id="biz-done-niche" onclick="_bizNicheDone()"
            ${bizTemp.niche ? '' : 'disabled'}>Done ✓</button>
      </div>`);

    const addBtn = document.getElementById('biz-niche-add');
    const addInp = document.getElementById('biz-niche-in');
    if (addBtn) addBtn.onclick   = () => _bizCustomNiche();
    if (addInp) addInp.onkeydown = e => { if (e.key === 'Enter') _bizCustomNiche(); };
}

function bizTempSelectNiche(el) {
    document.querySelectorAll('.biz-niche-opts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    bizTemp.niche = el.dataset.v;
    const doneBtn = document.getElementById('biz-done-niche');
    if (doneBtn) doneBtn.disabled = false;
}

function _bizCustomNiche() {
    const inp = document.getElementById('biz-niche-in');
    const v = inp ? inp.value.trim() : ''; if (!v) return;
    bizTemp.niche = v;
    const doneBtn = document.getElementById('biz-done-niche');
    if (doneBtn) doneBtn.disabled = false;
    // Highlight as selected by removing other selections and adding a temp pill
    document.querySelectorAll('.biz-niche-opts .sopt').forEach(x => x.classList.remove('sel'));
    showToast(`"${v}" selected`);
}

// ── Final commit — save all 3 to hdb_companies ────────────────────────────────
async function _bizNicheDone() {
    if (!bizTemp.niche) return;

    // Commit temp → coIndustry
    coIndustry.group    = bizTemp.group;
    coIndustry.subgroup = bizTemp.subgroup;
    coIndustry.niche    = bizTemp.niche;

    // Save all 3 fields to DB in parallel
    await Promise.all([
        post({ ajax_action:'save_company_industry', field:'group_name',    value:coIndustry.group    }),
        post({ ajax_action:'save_company_industry', field:'subgroup_name',  value:coIndustry.subgroup }),
        post({ ajax_action:'save_company_industry', field:'niche',          value:coIndustry.niche    }),
    ]).catch(() => {});

    renderBusinessBar();
    showToast('Business profile saved ✓');
    closeBusinessSettings();
    // After saving profile, show ideas box
    setTimeout(() => showIdeasBox(), 300);
}

function closeBusinessSettings() {
    document.getElementById('businessOverlay').classList.remove('open');
    renderBusinessBar();
}

function businessOverlayClick(e) {
    if (e.target === document.getElementById('businessOverlay')) closeBusinessSettings();
}


// ═════════════════════════════════════════════════════════════════════════════
// MODE NAVIGATION
// ═════════════════════════════════════════════════════════════════════════════
function selectMode(mode) {
    ['modeSelect','modeWizard','modeCampaign','modeContent','modeIdeas'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('mode' + mode.charAt(0).toUpperCase() + mode.slice(1)).style.display = '';
    window.scrollTo({ top:0, behavior:'smooth' });
    if (mode === 'wizard') {
        stepOpts = {};
        if (coIndustry.group && coIndustry.subgroup && coIndustry.niche) {
            document.getElementById('modeWizard').style.display = 'none';
            showIdeasBox();
        } else {
            ans = {}; cur = 0;
            render();
            openBusinessSettings();
        }
    } else if (mode === 'campaign') {
        campInitMode();
    } else if (mode === 'content') {
        contentInitMode();
    }
}

function goToMenu() {
    ['modeWizard','modeCampaign','modeContent','modeIdeas'].forEach(function(id) {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('modeSelect').style.display = '';
    window.scrollTo({ top:0, behavior:'smooth' });
}

// Wire mode cards
document.querySelectorAll('.mode-card[data-mode]').forEach(card => {
    card.onclick = () => selectMode(card.dataset.mode);
});

// ═════════════════════════════════════════════════════════════════════════════
// WIZARD ENGINE
// ═════════════════════════════════════════════════════════════════════════════
function setNext(v) {
    const nb = document.getElementById('nextBtn');
    if (nb) nb.disabled = !v;
    const cb = document.getElementById('step-q-cont-btn');
    if (cb) cb.disabled = !v;
}

function setBack() {
    const bb = document.getElementById('backBtn');
    if (bb) bb.style.visibility = cur === 0 ? 'hidden' : 'visible';
}

function setInlineActions(show, moreBtnId, nextBtnId, onMore, onNext) {
    const el = document.getElementById('step-q-actions');
    if (!el) return;
    if (!show) { el.style.display = 'none'; el.innerHTML = ''; return; }
    el.innerHTML = '';
    if (onMore) {
        const mb = document.createElement('button');
        mb.id = moreBtnId || 'more-btn-inline';
        mb.className = 'more-btn-sm';
        mb.innerHTML = '<span>+</span> More';
        mb.onclick = onMore;
        el.appendChild(mb);
    }
    const cb = document.createElement('button');
    cb.id = 'step-q-cont-btn';
    cb.className = 'cont-btn-sm';
    cb.disabled = document.getElementById(nextBtnId || 'nextBtn')?.disabled ?? true;
    cb.textContent = 'Continue →';
    cb.onclick = onNext || goNext;
    el.appendChild(cb);
    el.style.display = 'flex';
}

function clearInlineActions() {
    const cb = document.getElementById('step-q-cont-btn');
    if (cb) cb.remove();
    const el = document.getElementById('step-q-actions');
    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
}

function updateStepDots(current) {
    document.querySelectorAll('.sdot').forEach((dot, i) => {
        dot.classList.remove('active','done');
        if      (i < current)  dot.classList.add('done');
        else if (i === current) dot.classList.add('active');
    });
}

function updateCardSubtitle() {
    const parts = [];
    if (ans.industry_group) parts.push(ans.industry_group);
    if (ans.industry_desc)  parts.push(ans.industry_desc);
    if (ans.niche)          parts.push(ans.niche);
    if (ans.title)          parts.push(ans.title);
    document.getElementById('cardSubtitle').textContent =
        parts.length ? parts.join(' › ') : 'Answer a few questions to generate your video script';
}

async function render() {
    document.getElementById('prog').style.width = Math.round((cur / STEPS.length) * 100) + '%';
    setBack();
    setNext(false);
    clearInlineActions();
    const s = STEPS[cur];
    document.getElementById('step-label').textContent = s.label;
    document.getElementById('step-q').textContent     = s.q;
    updateStepDots(cur);
    updateCardSubtitle();

    // If business profile is complete and we're on group/subgroup/niche steps, skip ahead
    if (['group-select','subgroup-select','niche-db-select'].includes(s.type)
            && coIndustry.group && coIndustry.subgroup && coIndustry.niche) {
        ans.industry_group = coIndustry.group;
        ans.industry_desc  = coIndustry.subgroup;
        ans.niche          = coIndustry.niche;
        cur = 3;
        render(); return;
    }

    if      (s.type === 'group-select')       await renderGroupSelect(s);
    else if (s.type === 'subgroup-select')    await renderSubgroupSelect(s);
    else if (s.type === 'niche-db-select')    await renderNicheDbSelect(s);
    else if (s.type === 'video-idea-select')  await renderVideoIdeaSelect(s);
    else if (s.type === 'hook-select')         await renderHookSelect(s);
    else if (s.type === 'duration-select')     renderDurationSelect(s);
    else if (s.type === 'cta-select')          await renderCtaSelect(s);
    else if (s.type === 'voice-select')        await renderVoiceSelect(s);
    else {
        // Fallback / TODO placeholder for future steps
        document.getElementById('step-body').innerHTML = `
            <div class="todo-banner">
              <h3>🚧 Step "${s.label}" not yet implemented</h3>
              <p>This step will be added next. Current answers collected so far:</p>
              <div class="debug-summary"><pre>${JSON.stringify(ans, null, 2)}</pre></div>
            </div>`;
        setNext(true);
        setInlineActions(true, null, 'nextBtn', null, goNext);
    }
}

function goNext() {
    autoSubmitCustomInput();
    if (cur < STEPS.length - 1) {
        cur++;
        clearInlineActions();
        render();
    } else {
        showFinalStep();
    }
}

function goBack() {
    if (cur > 0) { cur--; clearInlineActions(); render(); }
}

// Auto-submit any pending custom inputs when Continue is clicked
function autoSubmitCustomInput() {
    ['opts-wrap-cust-in','niche-cust-in','idea-cust-in'].forEach(id => {
        const inp = document.getElementById(id);
        if (inp && inp.value && inp.value.trim()) {
            const btnId = id.replace('-in', '-btn');
            const btn = document.getElementById(btnId);
            if (btn && !btn.disabled) btn.click();
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// Final step (after step 2 — video idea)
// ═════════════════════════════════════════════════════════════════════════════
// STEP 4 — HOOK SELECT (saved DB hooks first, AI fallback, browse list)
// ═════════════════════════════════════════════════════════════════════════════
async function renderHookSelect(s) {
    const body  = document.getElementById('step-body');
    const title = ans.title || coIndustry.niche || '';
    const niche = ans.niche || coIndustry.niche || '';

    document.getElementById('step-label').textContent = s.label;
    document.getElementById('step-q').textContent     = s.q;

    body.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading hooks…</span></div>';
    setNext(false);

    var hooksData = {}, aiRecs = [], savedHooks = [];

    try {
        // Always load browse list; also check DB for saved hooks
        var [hooksRes, savedRes] = await Promise.all([
            post({ ajax_action: 'get_hooks' }),
            post({ ajax_action: 'get_saved_hook', niche: niche }),
        ]);
        hooksData   = hooksRes.success ? hooksRes.groups : {};
        savedHooks  = (savedRes.success && savedRes.hooks) ? savedRes.hooks : [];

        if (savedHooks.length > 0) {
            // We have saved hooks — use them as recommendations, skip AI call
            aiRecs = savedHooks.map(function(h) {
                return { hook_name: h.hook_type || 'Saved', adapted_hook: h.hook_text, why: '', _saved: true };
            });
        } else {
            // No saved hooks — call AI, then save all results to DB
            body.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Getting AI hook recommendations…</span></div>';
            var aiRes = await post({ ajax_action: 'get_ai_hook_recommendations', video_idea: title, niche: niche });
            aiRecs = (aiRes.success && aiRes.recommendations) ? aiRes.recommendations : [];

            // Save all AI hooks to DB for this niche (INSERT IGNORE — no duplicates)
            if (aiRecs.length > 0 && niche) {
                var batch = aiRecs.map(function(r) {
                    return { hook_text: r.adapted_hook, hook_type: r.hook_name || 'ai' };
                });
                post({ ajax_action: 'save_user_hook', niche: niche, hooks: JSON.stringify(batch) })
                    .catch(function() {});
            }
        }
    } catch(e) {
        hooksData = {}; aiRecs = [];
    }

    _renderHookOpts(body, hooksData, aiRecs);
    document.getElementById('nav-bar').style.display = 'none';
    var actEl = document.getElementById('step-q-actions');
    if (actEl) { actEl.style.display = 'none'; actEl.innerHTML = ''; }
}

function _renderHookOpts(body, hooksData, aiRecs) {
    var html = '';

    // ── Recommendations section (saved from DB, or fresh AI) ─────────────────
    if (aiRecs && aiRecs.length > 0) {
        var isSaved = aiRecs[0] && aiRecs[0]._saved;
        var label   = isSaved ? '💾 Your Saved Hooks for This Niche' : '✨ AI Recommended for Your Idea';
        html += '<div class="my-niches-label" style="margin-bottom:10px;">' + label + '</div>';
        html += '<div class="hook-cards" id="hook-ai-wrap">';
        aiRecs.forEach(function(rec) {
            var sel = ans.hook === rec.adapted_hook ? ' hook-card-sel' : '';
            html += '<div class="hook-card' + sel + '" data-hook="' + esc(rec.adapted_hook) + '" data-type="' + esc(rec.hook_name) + '" onclick="selectHook(this)">'
                  + '<div class="hook-card-type">' + escHtml(rec.hook_name) + '</div>'
                  + '<div class="hook-card-text">' + escHtml(rec.adapted_hook) + '</div>'
                  + '<div class="hook-card-why">' + escHtml(rec.why || '') + '</div>'
                  + '</div>';
        });
        html += '</div>';
    }

    html += '<button id="hook-browse-btn" class="more-btn" style="width:100%;margin:12px 0 0;" onclick="toggleHookBrowser()">🔍 Browse All Hook Types</button>';
    html += '<div id="hook-browser" style="display:none;margin-top:10px;">';
    if (hooksData && Object.keys(hooksData).length > 0) {
        Object.entries(hooksData).forEach(function([type, hooks]) {
            html += '<div class="hook-type-label" style="margin-top:10px;">' + escHtml(type) + '</div>';
            html += '<div class="opts hook-plain-wrap">';
            hooks.forEach(function(h) {
                var sel = ans.hook === h.name ? ' sel' : '';
                html += '<div class="opt' + sel + '" data-hook="' + esc(h.name) + '" data-type="' + esc(type) + '" onclick="selectHookPlain(this)">' + escHtml(h.name) + '</div>';
            });
            html += '</div>';
        });
    } else if (!aiRecs || aiRecs.length === 0) {
        html += '<div style="color:var(--muted);font-size:13px;padding:12px 0;">No hooks found. Type your own below.</div>';
    }
    html += '</div>';

    // ── Custom hook input ─────────────────────────────────────────────────────
    html += '<div class="custom-row" style="margin-top:14px;">'
          + '<input class="custom-in" id="hook-custom-in" placeholder="Or write your own hook…" value="' + esc(ans.hook || '') + '">'
          + '<button class="custom-add" id="hook-custom-btn">Use</button>'
          + '</div>';

    body.innerHTML = html;

    // Wire custom input
    var custBtn = document.getElementById('hook-custom-btn');
    var custIn  = document.getElementById('hook-custom-in');
    if (custBtn) custBtn.onclick  = function() { _useCustomHook(); };
    if (custIn)  custIn.onkeydown = function(e) { if (e.key === 'Enter') _useCustomHook(); };
}

function toggleHookBrowser() {
    var panel = document.getElementById('hook-browser');
    var btn   = document.getElementById('hook-browse-btn');
    if (!panel) return;
    var open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'block';
    if (btn) btn.textContent = open ? '🔍 Browse All Hook Types' : '✕ Close Hook Browser';
}

function selectHook(el) {
    document.querySelectorAll('.hook-card').forEach(function(c) { c.classList.remove('hook-card-sel'); });
    document.querySelectorAll('.hook-plain-wrap .opt').forEach(function(c) { c.classList.remove('sel'); });
    el.classList.add('hook-card-sel');
    ans.hook      = el.dataset.hook;
    ans.hook_type = el.dataset.type;
    setNext(true);
    setTimeout(function() { goNext(); }, 400);
}

function selectHookPlain(el) {
    document.querySelectorAll('.hook-card').forEach(function(c) { c.classList.remove('hook-card-sel'); });
    document.querySelectorAll('.hook-plain-wrap .opt').forEach(function(c) { c.classList.remove('sel'); });
    el.classList.add('sel');
    ans.hook      = el.dataset.hook;
    ans.hook_type = el.dataset.type;
    // Save browse-list pick to DB (these aren't auto-saved like AI hooks)
    var niche = ans.niche || coIndustry.niche || '';
    if (niche && ans.hook) {
        post({ ajax_action: 'save_user_hook', niche: niche, hooks: JSON.stringify([{hook_text: ans.hook, hook_type: ans.hook_type || 'browse'}]) })
            .catch(function() {});
    }
    setNext(true);
    setTimeout(function() { goNext(); }, 400);
}

function _useCustomHook() {
    var inp = document.getElementById('hook-custom-in');
    var v = inp ? inp.value.trim() : '';
    if (!v) return;
    document.querySelectorAll('.hook-card').forEach(function(c) { c.classList.remove('hook-card-sel'); });
    document.querySelectorAll('.hook-plain-wrap .opt').forEach(function(c) { c.classList.remove('sel'); });
    ans.hook      = v;
    ans.hook_type = 'custom';
    setNext(true);
    var cb = document.getElementById('step-q-cont-btn');
    if (cb) cb.disabled = false;

    // Save to DB so it can be pre-filled next time for this niche
    var niche = ans.niche || coIndustry.niche || '';
    if (niche) {
        post({ ajax_action: 'save_user_hook', niche: niche, hook_text: v, hook_type: 'custom' })
            .catch(function() {}); // silent fail — don't block UX
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 5 — DURATION SELECT
// ═════════════════════════════════════════════════════════════════════════════
function renderDurationSelect(s) {
    const body = document.getElementById('step-body');
    const options = [
        {sec:'15', lbl:'Short'},
        {sec:'30', lbl:'Standard'},
        {sec:'45', lbl:'Medium'},
        {sec:'60', lbl:'1 min'},
        {sec:'90', lbl:'Long'},
        {sec:'120',lbl:'2 min'},
    ];
    const current = ans.duration || '30';
    let html = '<div class="dur-chips">';
    options.forEach(function(o) {
        const sel = current === o.sec ? ' sel' : '';
        html += '<div class="dur-chip' + sel + '" data-v="' + o.sec + '" onclick="selectDuration(this)">'
              + '<div class="dur-chip-sec">' + o.sec + 's</div>'
              + '<div class="dur-chip-lbl">' + o.lbl + '</div>'
              + '</div>';
    });
    html += '</div>';
    body.innerHTML = html;

    if (!ans.duration) {
        var def = body.querySelector('[data-v="30"]');
        if (def) { def.classList.add('sel'); ans.duration = def.dataset.v; }
    }
    document.getElementById('nav-bar').style.display = 'none';
    var actDur = document.getElementById('step-q-actions');
    if (actDur) { actDur.style.display = 'none'; actDur.innerHTML = ''; }
}

function selectDuration(el) {
    document.querySelectorAll('.dur-chip').forEach(function(c) { c.classList.remove('sel'); });
    el.classList.add('sel');
    ans.duration = el.dataset.v;
    setNext(true);
    setTimeout(function() { goNext(); }, 350);
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 6 — CTA SELECT
// ═════════════════════════════════════════════════════════════════════════════
async function renderCtaSelect(s) {
    const body = document.getElementById('step-body');
    body.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading CTAs…</span></div>';
    setNext(!!ans.cta);

    var companyCtas = [], globalCtas = [];
    try {
        const d = await post({ ajax_action: 'get_ctas_separated' });
        if (d.success) {
            companyCtas = d.company_ctas || [];
            globalCtas  = d.global_ctas  || [];
        }
    } catch(e) {}

    _renderCtaOpts(body, companyCtas, globalCtas);
    document.getElementById('nav-bar').style.display = 'none';
    var actCta = document.getElementById('step-q-actions');
    if (actCta) { actCta.style.display = 'none'; actCta.innerHTML = ''; }
}

function _renderCtaOpts(body, companyCtas, globalCtas) {
    var html = '';

    // Skip option
    html += '<div class="opt' + (!ans.cta ? ' sel' : '') + '" data-v="" onclick="selectCta(this)" style="margin-bottom:8px;font-style:italic;color:var(--muted);">No CTA — skip</div>';

    if (companyCtas.length > 0) {
        html += '<div class="my-niches-label">My CTAs</div><div class="opts" id="cta-wrap">';
        companyCtas.forEach(function(c) {
            html += '<div class="opt' + (ans.cta === c ? ' sel' : '') + '" data-v="' + esc(c) + '" onclick="selectCta(this)">' + escHtml(c) + '</div>';
        });
        html += '</div>';
    }

    if (globalCtas.length > 0) {
        html += '<div class="divider-label">Common CTAs</div><div class="opts">';
        globalCtas.forEach(function(c) {
            html += '<div class="opt' + (ans.cta === c ? ' sel' : '') + '" data-v="' + esc(c) + '" onclick="selectCta(this)">' + escHtml(c) + '</div>';
        });
        html += '</div>';
    }

    if (companyCtas.length === 0 && globalCtas.length === 0) {
        html += '<p style="font-size:13px;color:#aaa;margin:8px 0 12px;">No CTAs saved yet. Add your own below.</p>';
    }

    html += '<div class="custom-row" style="margin-top:12px;">'
          + '<input class="custom-in" id="cta-custom-in" placeholder="e.g. Follow for more tips" value="' + esc(ans.cta || '') + '">'
          + '<button class="custom-add" onclick="_saveCustomCta()">Add</button>'
          + '</div>';

    body.innerHTML = html;
    const inp = document.getElementById('cta-custom-in');
    if (inp) inp.onkeydown = function(e) { if (e.key === 'Enter') _saveCustomCta(); };
}

function selectCta(el) {
    document.querySelectorAll('#step-body .opt').forEach(function(x) { x.classList.remove('sel'); });
    el.classList.add('sel');
    ans.cta = el.dataset.v;
    setNext(true);
    setTimeout(function() { goNext(); }, 350);
}

async function _saveCustomCta() {
    const inp = document.getElementById('cta-custom-in');
    const v = inp ? inp.value.trim() : ''; if (!v) return;
    ans.cta = v;
    try { await post({ ajax_action:'save_user_cta', cta_text:v }); } catch(e) {}
    setNext(true);
    const cb = document.getElementById('step-q-cont-btn'); if (cb) cb.disabled = false;
    showToast('CTA saved ✓');
    // Re-render to show the new CTA selected
    inp.value = '';
    const body = document.getElementById('step-body');
    try {
        const d = await post({ ajax_action:'get_ctas_separated' });
        _renderCtaOpts(body, d.company_ctas || [], d.global_ctas || []);
    } catch(e) {}
}

// ═════════════════════════════════════════════════════════════════════════════
// SCRIPT GENERATION
// ═════════════════════════════════════════════════════════════════════════════
async function generateScript() {
    const body = document.getElementById('step-body');

    // Show generating state
    document.getElementById('step-label').textContent = 'Generating…';
    document.getElementById('step-q').textContent = 'Writing your script';
    document.getElementById('step-q-actions').style.display = 'none';
    body.innerHTML = '<div class="loading" style="padding:32px 0;justify-content:center;">'
        + '<div class="dot"></div><div class="dot"></div><div class="dot"></div>'
        + '<span style="font-size:14px;">Writing your ' + (ans.duration||'30') + 's script…</span></div>';

    try {
        const d = await post({
            ajax_action: 'generate_script',
            group:       ans.industry_group || coIndustry.group    || '',
            subgroup:    ans.industry_desc  || coIndustry.subgroup || '',
            niche:       ans.niche          || coIndustry.niche    || '',
            topic:       ans.title          || '',
            hook:        ans.hook           || '',
            hook_type:   ans.hook_type      || '',
            duration:    ans.duration       || '30',
            cta:         ans.cta            || '',
            language:       settings.language      || 'English',
            reel_type:      settings.reel_type     || 'Standard',
            audience:       settings.audience      || 'General Public',
            tone:           settings.tone          || 'Friendly',
            content_goals:  settings.content_goals || 'Education',
            growth_goals:   settings.growth_goals  || 'Grow Followers',
            brand_name:     PHP_BRAND_NAME         || '',
            voice_id:       ans.voice_id            || '',
            voice_rate:     ans.voice_rate          || '1.1',
        });

        if (d.success) {
            _renderScript(d.scenes || [d.script || ''], d.word_count, d.reel_type || settings.reel_type || 'Standard');
        } else {
            body.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div>'
                + '<div>' + escHtml(d.error || 'Could not generate script') + '</div>'
                + '<button onclick="generateScript()" class="ideas-load-more" style="margin-top:12px;">Try again</button></div>';
        }
    } catch(e) {
        body.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div>'
            + '<div>Network error. Please try again.</div>'
            + '<button onclick="generateScript()" class="ideas-load-more" style="margin-top:12px;">Try again</button></div>';
    }
}

function _renderScript(scenes, wordCount, reelType) {
    var rt = (reelType || settings.reel_type || 'Standard').toLowerCase();
    var isPodcast     = rt.includes('podcast');
    var isTalkingHead = rt.includes('talking head');
    var isBroll       = rt.includes('b-roll') || rt.includes('broll');

    document.getElementById('step-label').textContent = 'Generated Script';
    document.getElementById('step-q').textContent     = ans.title || '';
    document.getElementById('step-q-actions').style.display = 'none';
    document.getElementById('nav-bar').style.display  = 'none';
    document.getElementById('prog').style.width = '100%';

    var BREAK = '<break time="200ms"/>';

    // Build editable textarea content
    var scriptText;
    if (isPodcast || isTalkingHead || isBroll) {
        // Preserve lines as-is (HOST:/GUEST: labels + break tags already in scenes)
        scriptText = scenes.map(function(text) {
            var clean = text.replace(/<break[^>]*>/gi, '').trim();
            return clean + ' ' + BREAK;
        }).join('\n');
    } else {
        // Standard: one scene per line
        scriptText = scenes.map(function(text) {
            var clean = text.replace(/<break[^>]*>/gi, '').trim();
            return clean + ' ' + BREAK;
        }).join('\n');
    }

    // Store for Approve Script button
    window._wizScriptRaw = scriptText;
    window._wizAns  = Object.assign({}, ans, settings);
    window._wizData = {
        niche:     ans.niche          || coIndustry.niche    || '',
        title:     ans.title          || '',
        language:  settings.language  || 'English',
        reel_type: settings.reel_type || 'Standard',
        topic:     ans.title          || '',
        angle:     ans.hook           || '',
        duration:  ans.duration       || '30',
        cta:       ans.cta            || '',
        tone:      settings.tone      || 'Friendly',
        audience:  settings.audience  || 'General Public',
        content_goals: settings.content_goals || 'Education',
        growth_goals:  settings.growth_goals  || 'Grow Followers',
        brand_name:    PHP_BRAND_NAME         || '',
        voice_id:  ans.voice_id       || '',
        voice_rate: ans.voice_rate    || '1.1',
    };

    // Hint text per reel type
    var hintText = isPodcast
        ? 'HOST: / GUEST: lines — each line is one spoken turn. Edit freely.'
        : isTalkingHead
        ? 'Each line is one spoken segment for your avatar. Edit freely.'
        : isBroll
        ? 'Continuous voiceover narration broken into paragraphs. Edit freely.'
        : 'Each line = one scene (~5-6 seconds). Edit freely.';

    // Reel type badge colour
    var rtBadgeColor = isPodcast ? '#7c3aed' : isTalkingHead ? '#0369a1' : isBroll ? '#065f46' : '#1e40af';

    // Meta pills
    var dur = ans.duration || '30';
    var reelLabel = (settings.reel_type || 'Standard').split(' (')[0];

    // Estimated audio duration from word count at ~130 wpm
    var estSecs = wordCount ? Math.round(wordCount / 130 * 60) : parseInt(dur);
    var durLabel = estSecs >= 60
        ? Math.floor(estSecs / 60) + 'm ' + (estSecs % 60) + 's'
        : estSecs + 's';

    var pills = [
        dur + 's target',
        settings.language || 'English',
        reelLabel,
        settings.tone || 'Friendly',
        scenes.length + ' scenes',
        wordCount ? wordCount + ' words' : '',
        wordCount ? '~' + durLabel + ' audio' : ''
    ].filter(Boolean)
     .map(function(p){ return '<span class="script-meta-pill">'+escHtml(p)+'</span>'; }).join('');

    // Textarea height: podcast/talking head scripts are longer
    var minHeight = (isPodcast || isTalkingHead) ? '320px' : '220px';

    document.getElementById('step-body').innerHTML =
        '<div class="script-meta" style="margin-bottom:12px;">' + pills + '</div>'
        + '<div style="font-size:12px;color:var(--muted);margin-bottom:8px;">' + escHtml(hintText) + '</div>'
        + '<textarea id="script-text" oninput="window._wizScriptRaw=this.value"'
        + ' style="width:100%;min-height:' + minHeight + ';padding:14px;border:1.5px solid var(--border);border-radius:10px;'
        + 'font-family:monospace;font-size:13px;line-height:1.9;resize:vertical;outline:none;'
        + 'background:#f8fafc;color:var(--text);">' + escHtml(scriptText) + '</textarea>'
        + '<div style="display:flex;gap:8px;margin-top:10px;flex-direction:column;">'
        + '<button class="nav-next" style="width:100%;font-size:15px;padding:14px;" onclick="openS2(&quot;wizard&quot;)">Approve Script →</button>'
        + '<button class="script-regen-btn" onclick="generateScript()" style="width:100%;">🔄 Regenerate</button>'
        + '<button class="restart-btn" onclick="goToMenu()" style="margin-top:0;">Start over</button>'
        + '</div>';
}

function copyScript() {
    var el = document.getElementById('script-output');
    if (!el) return;
    var text = el.dataset.full || el.textContent;
    navigator.clipboard.writeText(text).then(function() {
        showToast('Script copied ✓');
    }).catch(function() {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        showToast('Script copied ✓');
    });
}


// ═════════════════════════════════════════════════════════════════════════════
// STEP 7 — VOICE SELECT
// ═════════════════════════════════════════════════════════════════════════════
var _wizVoiceAudio = null;

async function renderVoiceSelect(s) {
    const body = document.getElementById('step-body');
    body.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading voices…</span></div>';
    setNext(!!ans.voice_id);

    // Load voices filtered by selected language
    var langCode = langCodeFromName(settings.language || 'English');
    var voices = [];
    try {
        var fd = new FormData();
        fd.append('lang_code', langCode);
        var r = await fetch('get_voices.php', { method:'POST', body:fd, credentials:'include' });
        var d = await r.json();
        voices = d.voices || [];
    } catch(e) {
        voices = [
            {voice_id:'openai:alloy',   voice_name:'Alloy (Neutral)', gender:'male',   sample_voice:''},
            {voice_id:'openai:echo',    voice_name:'Echo',            gender:'male',   sample_voice:''},
            {voice_id:'openai:onyx',    voice_name:'Onyx',            gender:'male',   sample_voice:''},
            {voice_id:'openai:fable',   voice_name:'Fable',           gender:'male',   sample_voice:''},
            {voice_id:'openai:nova',    voice_name:'Nova',            gender:'female', sample_voice:''},
            {voice_id:'openai:shimmer', voice_name:'Shimmer',         gender:'female', sample_voice:''},
        ];
    }
    _allVoices = voices;

    function buildOpts(gender) {
        var filtered = gender === 'all' ? voices : voices.filter(function(v){ return (v.gender||'').toLowerCase() === gender; });
        // OpenAI only — no provider labels
        var openai = filtered.filter(function(v){ return (v.voice_id||'').startsWith('openai:'); });
        var list   = openai.length ? openai : filtered;
        var html   = list.map(function(v){
            var sel  = ans.voice_id === v.voice_id ? ' selected' : '';
            var name = (v.voice_name||'').replace(/^openai[:\s]*/i,'').replace(/^azure[:\s]*/i,'');
            return '<option value="' + esc(v.voice_id) + '"' + sel + ' data-sample="' + esc(v.sample_voice||'') + '">' + escHtml(name) + '</option>';
        }).join('');
        return html || '<option value="">No voices found</option>';
    }

    var currentGender = 'male';
    var html =
        '<div style="margin-bottom:14px;">'
      + '<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">Filter by Gender</div>'
      + '<div class="s2-gender-tabs" id="wiz-voice-gender-tabs" style="margin-bottom:10px;">'
      +   '<button class="s2-gtab active" onclick="wizFilterVoices(&quot;male&quot;,this)">👨 Male</button>'
      +   '<button class="s2-gtab" onclick="wizFilterVoices(&quot;female&quot;,this)">👩 Female</button>'
      +   '<button class="s2-gtab" onclick="wizFilterVoices(&quot;all&quot;,this)">All</button>'
      + '</div>'
      + '<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;">Voice</div>'
      + '<select id="wiz-voice-select" class="s2-select" style="margin-bottom:10px;">' + buildOpts('male') + '</select>'
      + '<button class="s2-sample-btn" id="wiz-sample-btn" onclick="wizPlaySample()" style="margin-bottom:16px;">▶ Play Sample</button>'
      + '</div>'
      + '<div style="margin-bottom:6px;">'
      + '<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;">Speech Speed</div>'
      + '<select id="wiz-voice-rate" class="s2-select">'
      +   '<option value="0.9">0.9× — Slightly slow</option>'
      +   '<option value="1.0">1.0× — Normal</option>'
      +   '<option value="1.1">1.1× — Slightly fast</option>'
      +   '<option value="1.2">1.2× — Fast</option>'
      +   '<option value="1.1" selected>1.1× — Default</option>'
      +   '<option value="1.3">1.3× — Very fast</option>'
      + '</select>'
      + '</div>';

    body.innerHTML = html;

    // Wire voice select change
    var sel = document.getElementById('wiz-voice-select');
    if (sel) {
        sel.onchange = function() {
            ans.voice_id = sel.value;
            ans.voice_rate = document.getElementById('wiz-voice-rate').value;
            setNext(!!ans.voice_id);
        };
        // Auto-select first option
        if (!ans.voice_id && sel.options.length > 0) {
            var firstEnabled = Array.from(sel.options).find(function(o){ return !o.disabled && o.value; });
            if (firstEnabled) { sel.value = firstEnabled.value; ans.voice_id = firstEnabled.value; setNext(true); }
        } else if (ans.voice_id) {
            setNext(true);
        }
    }

    var rateEl = document.getElementById('wiz-voice-rate');
    if (rateEl) {
        if (ans.voice_rate) rateEl.value = ans.voice_rate;
        rateEl.onchange = function() { ans.voice_rate = rateEl.value; };
    }

    setInlineActions(true, null, 'nextBtn', null, goNext);
    var cb = document.getElementById('step-q-cont-btn'); if (cb) cb.disabled = !ans.voice_id;
}

function wizFilterVoices(gender, btn) {
    document.querySelectorAll('#wiz-voice-gender-tabs .s2-gtab').forEach(function(t){ t.classList.remove('active'); });
    btn.classList.add('active');
    var voices   = _allVoices || [];
    var filtered = gender === 'all' ? voices : voices.filter(function(v){ return (v.gender||'').toLowerCase() === gender; });
    // OpenAI only — no provider labels
    var openai = filtered.filter(function(v){ return (v.voice_id||'').startsWith('openai:'); });
    var list   = openai.length ? openai : filtered;
    var html   = list.map(function(v){
        var name = (v.voice_name||'').replace(/^openai[:\s]*/i,'').replace(/^azure[:\s]*/i,'');
        return '<option value="' + esc(v.voice_id) + '" data-sample="' + esc(v.sample_voice||'') + '">' + escHtml(name) + '</option>';
    }).join('');
    var sel = document.getElementById('wiz-voice-select');
    if (sel) {
        sel.innerHTML = html || '<option value="">No voices found</option>';
        var firstEnabled = Array.from(sel.options).find(function(o){ return !o.disabled && o.value; });
        if (firstEnabled) { sel.value = firstEnabled.value; ans.voice_id = firstEnabled.value; setNext(true); }
        var cb = document.getElementById('step-q-cont-btn'); if (cb) cb.disabled = !ans.voice_id;
    }
}

// ── Shared voice preview — calls preview_voice AJAX, plays returned base64 MP3 ─
var _previewAudio = null;

async function _playVoicePreview(voiceId, btnEl) {
    if (!voiceId) { showToast('Select a voice first'); return; }

    // If already playing, stop it
    if (_previewAudio) {
        _previewAudio.pause(); _previewAudio = null;
        document.querySelectorAll('.s2-sample-btn, #wiz-sample-btn').forEach(function(b) {
            b.textContent = '▶ Play Sample'; b.classList.remove('playing'); b.dataset.playing = '0';
        });
        if (btnEl && btnEl.dataset.playing === '0') return; // was playing this button — just stop
        if (!btnEl) return;
    }

    if (btnEl) { btnEl.textContent = '⏳ Generating…'; btnEl.classList.add('playing'); }

    try {
        var d = await post({
            ajax_action: 'preview_voice',
            voice_id:    voiceId,
            language:    settings.language || 'English',
        });
        if (!d.success) { showToast(d.error || 'Could not generate sample'); if (btnEl) { btnEl.textContent = '▶ Play Sample'; btnEl.classList.remove('playing'); } return; }

        var blob = _b64ToBlob(d.audio, d.mime || 'audio/mpeg');
        var url  = URL.createObjectURL(blob);
        _previewAudio = new Audio(url);

        var reset = function() {
            _previewAudio = null;
            URL.revokeObjectURL(url);
            document.querySelectorAll('.s2-sample-btn, #wiz-sample-btn').forEach(function(b) {
                b.textContent = '▶ Play Sample'; b.classList.remove('playing'); b.dataset.playing = '0';
            });
        };
        _previewAudio.onended  = reset;
        _previewAudio.onerror  = function() { reset(); showToast('Could not play audio'); };

        if (btnEl) { btnEl.textContent = '⏹ Stop'; btnEl.dataset.playing = '1'; }
        _previewAudio.play().catch(function() { reset(); showToast('Could not play audio'); });

    } catch(e) {
        if (btnEl) { btnEl.textContent = '▶ Play Sample'; btnEl.classList.remove('playing'); }
        showToast('Could not generate sample');
    }
}

function _b64ToBlob(b64, mime) {
    var bytes = atob(b64);
    var arr   = new Uint8Array(bytes.length);
    for (var i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
    return new Blob([arr], { type: mime });
}

function wizPlaySample() {
    var sel = document.getElementById('wiz-voice-select');
    var btn = document.getElementById('wiz-sample-btn');
    if (!sel || !btn) return;
    if (!sel.value) { showToast('Select a voice first'); return; }
    _playVoicePreview(sel.value, btn);
}

function showFinalStep() {
    // Use coIndustry as fallback if ans fields weren't set by wizard steps
    const group    = ans.industry_group || coIndustry.group    || '—';
    const subgroup = ans.industry_desc  || coIndustry.subgroup || '—';
    const niche    = ans.niche          || coIndustry.niche    || '—';
    const title    = ans.title          || '—';

    document.getElementById('step-label').textContent = 'Ready to generate';
    document.getElementById('step-q').textContent     = escHtml(title);
    document.getElementById('step-q-actions').style.display = 'none';
    document.getElementById('nav-bar').style.display  = 'none';
    updateStepDots(STEPS.length);
    document.getElementById('prog').style.width = '100%';
    document.getElementById('cardSubtitle').textContent = `${group} › ${subgroup} › ${niche}`;

    const hook     = ans.hook     || '—';
    const hookType = ans.hook_type || '';
    const duration = ans.duration  || '30';
    const cta      = ans.cta       || '';

    function row(label, value, subval) {
        if (!value || value === '—') return '';
        return '<div style="display:flex;gap:8px;font-size:13px;color:#065f46;padding-top:7px;border-top:1px solid #a7f3d0;">'
             + '<span style="font-weight:700;min-width:90px;">' + label + '</span>'
             + '<div><div style="font-weight:' + (label==='Video Idea'?'600':'400') + ';">' + escHtml(value) + '</div>'
             + (subval ? '<div style="font-size:11px;color:#10b981;margin-top:2px;">' + escHtml(subval) + '</div>' : '')
             + '</div></div>';
    }

    var scenesCount = Math.min(24, Math.max(2, Math.ceil(parseInt(duration) / 5)));

    document.getElementById('step-body').innerHTML =
        '<div style="background:linear-gradient(135deg,#d1fae5,#ecfdf5);border:1.5px solid #6ee7b7;border-radius:12px;padding:18px 20px;margin-bottom:16px;">'
        + '<div style="font-size:13px;color:#065f46;font-weight:700;margin-bottom:10px;text-transform:uppercase;letter-spacing:.06em;">✅ Ready to generate</div>'
        + '<div style="display:flex;flex-direction:column;gap:0px;">'
        + row('Group',        group,    '')
        + row('Industry',     subgroup, '')
        + row('Niche',        niche,    '')
        + row('Video Idea',   title,    '')
        + row('Hook',         hook,     hookType)
        + row('Duration',     duration + 's → ' + scenesCount + ' scenes (~5-6s each)', '')
        + (cta ? row('CTA',  cta, '') : '')
        + (ans.voice_id ? row('Voice', ans.voice_id.replace('openai:','').replace('azure:',''), (ans.voice_rate||'1.1') + '× speed') : '')
        + '<div style="border-top:1px solid #a7f3d0;margin-top:8px;padding-top:8px;">'
        + '<div style="font-size:10px;font-weight:700;color:#10b981;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Video Settings</div>'
        + row('Language',     settings.language      || 'English',        '')
        + row('Reel Type',    settings.reel_type     || 'Standard',       '')
        + row('Audience',     settings.audience      || 'General Public', '')
        + row('Tone',         settings.tone          || 'Friendly',       '')
        + row('Content Goal', settings.content_goals || 'Education',        '')
        + row('Growth Goal',  settings.growth_goals  || 'Grow Followers', '')
        + '</div>'
        + '</div></div>'
        + '<button onclick="generateScript()" class="nav-next" style="width:100%;font-size:15px;padding:14px;margin-bottom:10px;">'
        + '✨ Generate Script</button>'
        + '<div style="display:flex;gap:8px;">'
        + '<button onclick="goBack()" class="nav-next" style="flex:1;background:#f1f5f9;color:#64748b;font-size:13px;padding:10px;">← Edit</button>'
        + '<button onclick="goToMenu()" class="nav-next" style="flex:1;background:#f1f5f9;color:#64748b;font-size:13px;padding:10px;">Start Over</button>'
        + '</div>';
}

// ═════════════════════════════════════════════════════════════════════════════
// DB HELPERS
// ═════════════════════════════════════════════════════════════════════════════
async function post(payload) {
    const fd = new FormData();
    Object.entries(payload).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch(location.href, { method:'POST', body:fd });
    const text = await r.text();
    try {
        return JSON.parse(text);
    } catch(e) {
        console.error('[post] JSON parse error for action:', payload.ajax_action, '\nRaw response:', text.substring(0,500));
        throw new Error('Server returned invalid JSON: ' + text.substring(0,100));
    }
}

async function loadUserNiches() {
    try {
        const d = await post({ ajax_action:'get_user_niches' });
        userNiches              = d.niches        || [];
        window._commonNiches    = d.common_niches || [];
        window._isInternalUser  = d.is_internal === true;
    } catch(e) { userNiches = []; window._commonNiches = []; window._isInternalUser = false; }
}

async function saveNicheToDB(nicheName, isAi, storeAsCommon = false) {
    try { await post({ ajax_action:'save_niche', niche_name:nicheName, is_ai_generated:isAi?1:0, store_as_common:storeAsCommon?1:0 }); } catch(e) {}
}

async function deleteNiche(nicheName, btnEl, optEl) {
    if (!confirm(`Remove "${nicheName}"?`)) return;
    optEl && optEl.remove();
    userNiches = userNiches.filter(n => n !== nicheName);
    if (ans.niche === nicheName) { ans.niche = ''; setNext(false); }
    try { await post({ ajax_action:'delete_niche', niche_name:nicheName }); showToast('Niche removed'); } catch(e) {}
}

async function loadUserCategories(nicheName) {
    if (!nicheName) return [];
    try {
        const d = await post({ ajax_action:'get_user_categories', niche_name:nicheName });
        userCategories[nicheName] = d.categories || [];
        if (!window._commonCategories) window._commonCategories = {};
        window._commonCategories[nicheName] = d.common_categories || [];
        return userCategories[nicheName];
    } catch(e) { return []; }
}

async function saveCategoryToDB(nicheName, categoryName, isAi, storeAsCommon = false) {
    if (!nicheName || !categoryName) return;
    try { await post({ ajax_action:'save_category', niche_name:nicheName, category_name:categoryName, is_ai_generated:isAi?1:0, store_as_common:storeAsCommon?1:0 }); } catch(e) {}
}

async function loadUserVideoIdeas(nicheName, categoryName, page = 1) {
    if (!nicheName || !categoryName) return [];
    try {
        const d = await post({ ajax_action:'get_user_video_ideas', niche_name:nicheName, category_name:categoryName, page });
        if (d.success) {
            if (page === 1) {
                userVideoIdeas    = d.ideas || [];
                videoIdeasMyList  = d.ideas || [];
                videoIdeasCommonList = d.common_ideas || [];
                videoIdeasTotalCount = d.total_my || 0;
                videoIdeasHasMore = d.has_more || false;
            } else {
                userVideoIdeas   = [...userVideoIdeas, ...(d.ideas || [])];
                videoIdeasMyList = [...videoIdeasMyList, ...(d.ideas || [])];
                videoIdeasHasMore = d.has_more || false;
            }
            window._usedVideoIdeasLower = d.used_titles || [];
            return userVideoIdeas;
        }
        return [];
    } catch(e) { return []; }
}

async function saveVideoIdeaToDB(nicheName, categoryName, videoIdea, isAi, storeAsCommon = false) {
    if (!videoIdea) return;
    try { await post({ ajax_action:'save_video_idea', niche_name:nicheName, category_name:categoryName, video_idea:videoIdea, is_ai_generated:isAi?1:0, store_as_common:storeAsCommon?1:0 }); } catch(e) {}
}

async function _loadMasterIndustries() {
    // Returns all unique core_groups in one shot — no pagination needed (max ~15 groups from 52 rows)
    const d = await post({ ajax_action:'get_master_industries' });
    if (d.success) {
        // Each entry: { core_group, ids:[], industry_descs:[] }
        window._masterIndustries     = d.industries || [];
        window._masterIndustriesTotal = d.total || d.industries.length;
        // Map core_group → {ids, industry_descs} for fast lookup
        window._masterIndustriesMap  = {};
        (d.industries || []).forEach(grp => {
            window._masterIndustriesMap[grp.core_group] = {
                ids:            grp.ids,
                industry_descs: grp.industry_descs,
            };
        });
    }
}

async function _loadMasterNiches() {
    // Use the industry_id from the selected sub-group
    const industryId = ans.industry_id || '';
    if (!industryId) return;
    const d = await post({ ajax_action:'get_master_niches', industry_ids:industryId, offset:0, limit:50 });
    if (d.success) {
        window._masterNiches       = d.niches || [];
        window._masterNichesOffset = d.niches.length;
        window._masterNichesTotal  = d.total;
    }
}

function _saveUserIndustry(industryId) {
    if (!industryId) return;
    post({ ajax_action:'save_user_industry', industry_id:industryId }).catch(() => {});
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 0 — GROUP SELECT  (unique core_group values from hdb_master_groups)
// ═════════════════════════════════════════════════════════════════════════════
async function renderGroupSelect(s) {
    const body = document.getElementById('step-body');
    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading groups…</span></div>`;

    if (!window._masterGroups || window._masterGroups.length === 0) {
        window._masterGroups = [];
        try {
            const d = await post({ ajax_action: 'get_master_industries' });
            if (d.success) window._masterGroups = d.groups || [];
        } catch(e) {}
    }

    const groups = window._masterGroups;   // [{core_group}]
    let html = `<div class="opts" id="group-wrap">`;
    groups.forEach(g => {
        const sel = ans.industry_group === g.core_group;
        html += `<div class="opt${sel ? ' sel' : ''}" data-group="${esc(g.core_group)}">${escHtml(g.core_group)}</div>`;
    });
    html += `</div>`;
    body.innerHTML = html;

    body.querySelectorAll('#group-wrap .opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            const prev = ans.industry_group;
            ans.industry_group = b.dataset.group;
            // Clear downstream if group changed
            if (prev && prev !== ans.industry_group) {
                delete ans.industry_desc; delete ans.industry_id;
                delete ans.niche; delete ans.title;
                delete stepOpts.niche; delete stepOpts.title;
                window._masterSubgroups = [];
                window._masterNiches = []; window._masterNichesOffset = 0;
                window._masterNichesTotal = 0; window._masterNichesIndustry = '';
            }
            setNext(true);
        };
    });

    if (!ans.industry_group) {
        const first = body.querySelector('.opt');
        if (first) first.click();
    } else {
        setNext(true);
    }

    setInlineActions(true, null, 'nextBtn', null, () => { autoSubmitCustomInput(); goNext(); });
    const cb = document.getElementById('step-q-cont-btn');
    if (cb && ans.industry_group) cb.disabled = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — SUB-GROUP SELECT  (industry_desc rows for selected core_group)
// ═════════════════════════════════════════════════════════════════════════════
async function renderSubgroupSelect(s) {
    const body = document.getElementById('step-body');
    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading industries…</span></div>`;

    // Load sub-groups for the selected core_group if not already cached
    if (!window._masterSubgroups || window._masterSubgroupsFor !== ans.industry_group) {
        window._masterSubgroups    = [];
        window._masterSubgroupsFor = ans.industry_group || '';
        try {
            const d = await post({ ajax_action: 'get_master_subgroups', core_group: ans.industry_group || '' });
            if (d.success) window._masterSubgroups = d.subgroups || [];  // [{id, industry_desc}]
        } catch(e) {}
    }

    const subs = window._masterSubgroups;
    let html = `<div class="opts" id="subgroup-wrap">`;
    subs.forEach(sg => {
        const sel = ans.industry_desc === sg.industry_desc;
        html += `<div class="opt${sel ? ' sel' : ''}" data-v="${esc(sg.industry_desc)}" data-id="${sg.id}">${escHtml(sg.industry_desc)}</div>`;
    });
    html += `</div>`;

    // Custom input so user can type their own if not in list
    html += `<div class="custom-row">
        <input class="custom-in" id="subgroup-cust-in" placeholder="Or type your own…">
        <button class="custom-add" id="subgroup-cust-btn">Add</button>
    </div>`;
    body.innerHTML = html;

    function selectSubgroup(b) {
        body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
        b.classList.add('sel');
        const prev = ans.industry_desc;
        ans.industry_desc = b.dataset.v;
        ans.industry_id   = b.dataset.id || '';
        // Clear downstream if sub-group changed
        if (prev && prev !== ans.industry_desc) {
            delete ans.niche; delete ans.title;
            delete stepOpts.niche; delete stepOpts.title;
            window._masterNiches = []; window._masterNichesOffset = 0;
            window._masterNichesTotal = 0; window._masterNichesIndustry = '';
        }
        setNext(true);
        _saveUserIndustry(b.dataset.id);
    }

    body.querySelectorAll('#subgroup-wrap .opt').forEach(b => { b.onclick = () => selectSubgroup(b); });

    // Custom input
    const custBtn = document.getElementById('subgroup-cust-btn');
    const custIn  = document.getElementById('subgroup-cust-in');
    function addCustomSubgroup() {
        const v = custIn.value.trim(); if (!v) return; custIn.value = '';
        const wrap = document.getElementById('subgroup-wrap');
        const b = document.createElement('div');
        b.className = 'opt sel'; b.dataset.v = v; b.dataset.id = '';
        b.textContent = v; b.onclick = () => selectSubgroup(b);
        wrap.appendChild(b);
        ans.industry_desc = v; ans.industry_id = '';
        delete ans.niche; delete ans.title;
        delete stepOpts.niche; delete stepOpts.title;
        window._masterNiches = []; window._masterNichesOffset = 0;
        window._masterNichesTotal = 0; window._masterNichesIndustry = '';
        setNext(true);
    }
    if (custBtn) custBtn.onclick  = addCustomSubgroup;
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addCustomSubgroup(); };

    if (!ans.industry_desc) {
        const first = body.querySelector('.opt');
        if (first) first.click();
    } else {
        setNext(true);
    }

    setInlineActions(true, null, 'nextBtn', null, () => { autoSubmitCustomInput(); goNext(); });
    const cb = document.getElementById('step-q-cont-btn');
    if (cb && ans.industry_desc) cb.disabled = false;
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 1 — NICHE SELECT (DB + AI)
// ═════════════════════════════════════════════════════════════════════════════
async function renderNicheDbSelect(s) {
    const body = document.getElementById('step-body');
    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading niches…</span></div>`;

    // Reload niches if the selected sub-group (industry_id) changed
    const _currentId = String(ans.industry_id || '');
    if (!window._masterNiches || window._masterNichesIndustry !== _currentId) {
        window._masterNiches = []; window._masterNichesOffset = 0; window._masterNichesTotal = 0;
        window._masterNichesIndustry = _currentId;
        await _loadMasterNiches();
    }
    _renderNicheOpts(s);
}

function _renderNicheOpts(s) {
    const body   = document.getElementById('step-body');
    const niches = window._masterNiches || [];
    const wrapId = 'niche-all-wrap';

    let html = `<div class="opts" id="${wrapId}">`;
    niches.forEach(n => {
        html += `<div class="opt${ans.niche === n.niche_desc ? ' sel' : ''}" data-v="${esc(n.niche_desc)}" data-id="${n.id}">${n.niche_desc}</div>`;
    });
    html += `</div>`;
    html += `<div class="custom-row">
        <input class="custom-in" id="niche-cust-in" placeholder="Or type your own niche…">
        <button class="custom-add" id="niche-cust-btn">Add</button>
    </div>`;
    body.innerHTML = html;

    function selectNiche(b) {
        body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
        b.classList.add('sel');
        const prev = ans.niche;
        ans.niche    = b.dataset.v;
        ans.niche_id = b.dataset.id || '';
        if (prev && prev !== ans.niche) { delete ans.title; delete stepOpts.title; }
        setNext(true);
    }
    body.querySelectorAll('.opt').forEach(b => { b.onclick = () => selectNiche(b); });

    // Custom input
    const custBtn = document.getElementById('niche-cust-btn');
    const custIn  = document.getElementById('niche-cust-in');
    function addCustomNiche() {
        const v = custIn.value.trim(); if (!v) return; custIn.value = '';
        const wrap = document.getElementById(wrapId);
        const existing = [...wrap.querySelectorAll('[data-v]')].map(el => el.dataset.v.toLowerCase());
        if (existing.includes(v.toLowerCase())) {
            const found = [...wrap.querySelectorAll('[data-v]')].find(el => el.dataset.v.toLowerCase() === v.toLowerCase());
            if (found) selectNiche(found); return;
        }
        const b = document.createElement('div');
        b.className = 'opt sel'; b.dataset.v = v; b.dataset.id = '';
        b.textContent = v; b.onclick = () => selectNiche(b);
        wrap.appendChild(b);
        ans.niche = v; ans.niche_id = '';
        delete ans.title; delete stepOpts.title;
        setNext(true);
        saveNicheToDB(v, false);
    }
    if (custBtn) custBtn.onclick  = addCustomNiche;
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addCustomNiche(); };

    if (!ans.niche) {
        const first = body.querySelector('.opt');
        if (first) first.click();
    } else {
        setNext(true);
    }

    // Inline actions: DB More + AI + Continue
    const hasMoreDb = (window._masterNichesOffset || 0) < (window._masterNichesTotal || 0);
    setInlineActions(
        true, 'niche-more-btn', 'nextBtn',
        hasMoreDb ? () => _loadMoreNichesDb(s) : null,
        () => { autoSubmitCustomInput(); goNext(); }
    );

    // Always add AI button if morePrompt defined
    if (s.morePrompt) {
        const actionsEl = document.getElementById('step-q-actions');
        if (actionsEl) {
            const aiBtn = document.createElement('button');
            aiBtn.id = 'niche-ai-btn'; aiBtn.className = 'more-btn-sm';
            aiBtn.innerHTML = '✨ AI'; aiBtn.title = 'Get AI niche suggestions';
            aiBtn.onclick = () => _loadAiNicheSuggestions(s);
            const contBtn = actionsEl.querySelector('#step-q-cont-btn');
            if (contBtn) actionsEl.insertBefore(aiBtn, contBtn);
            else actionsEl.appendChild(aiBtn);
        }
    }
    const cb = document.getElementById('step-q-cont-btn');
    if (cb && ans.niche) cb.disabled = false;
}

async function _loadMoreNichesDb(s) {
    const btn = document.getElementById('niche-more-btn'); if (!btn) return;
    btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Loading…';
    try {
        await _loadMasterNiches();
        const wrap = document.getElementById('niche-all-wrap');
        const existing = wrap ? [...wrap.querySelectorAll('[data-v]')].map(el => el.dataset.v) : [];
        (window._masterNiches || []).forEach(n => {
            if (!existing.includes(n.niche_desc)) {
                const b = document.createElement('div');
                b.className = 'opt'; b.dataset.v = n.niche_desc; b.dataset.id = n.id; b.textContent = n.niche_desc;
                b.onclick = () => {
                    document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                    b.classList.add('sel'); ans.niche = n.niche_desc; ans.niche_id = n.id;
                    delete ans.title; delete stepOpts.title;
                    setNext(true);
                };
                if (wrap) wrap.appendChild(b);
            }
        });
        const hasMore = (window._masterNichesOffset || 0) < (window._masterNichesTotal || 0);
        btn.disabled = false;
        if (!hasMore) {
            if (s.morePrompt) { btn.innerHTML = '✨ AI Suggestions'; btn.onclick = () => _loadAiNicheSuggestions(s); }
            else { btn.textContent = 'No more'; btn.disabled = true; }
        } else {
            btn.innerHTML = '<span>+</span> More';
        }
    } catch(e) { btn.disabled = false; btn.innerHTML = '<span>+</span> More'; showToast('Error: ' + e.message); }
}

async function _loadAiNicheSuggestions(s) {
    const aiBtn = document.getElementById('niche-ai-btn');
    if (aiBtn) { aiBtn.disabled = true; aiBtn.innerHTML = '<span class="spin">⟳</span> AI…'; }
    try {
        const wrap = document.getElementById('niche-all-wrap');
        const existing = wrap ? [...wrap.querySelectorAll('[data-v]')].map(el => el.dataset.v) : [];
        const prompt = s.morePrompt(existing, ans);
        const r = await fetch('generate_more_opts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ prompt }) });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'AI error');
        let added = 0;
        (d.items || []).forEach(item => {
            const v = String(item).trim();
            if (!v || existing.map(e => e.toLowerCase()).includes(v.toLowerCase())) return;
            const b = document.createElement('div');
            b.className = 'opt'; b.dataset.v = v; b.dataset.id = ''; b.textContent = v;
            b.onclick = () => {
                document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                b.classList.add('sel'); ans.niche = v; ans.niche_id = '';
                delete ans.title; delete stepOpts.title; setNext(true);
                saveNicheToDB(v, true);
            };
            if (wrap) wrap.appendChild(b);
            added++;
        });
        if (aiBtn) { aiBtn.disabled = false; aiBtn.innerHTML = added > 0 ? '✨ More AI' : '✨ AI'; }
    } catch(e) {
        if (aiBtn) { aiBtn.disabled = false; aiBtn.innerHTML = '✨ AI'; }
        showToast('Could not load AI suggestions: ' + e.message);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// STEP 2 — VIDEO IDEA SELECT
// ═════════════════════════════════════════════════════════════════════════════
async function renderVideoIdeaSelect(s) {
    const body = document.getElementById('step-body');

    // Use business profile values (from coIndustry) as primary source, ans as fallback
    const nicheName    = coIndustry.niche    || ans.niche || '';
    const categoryName = coIndustry.niche    || ans.niche || '';
    const industryName = coIndustry.subgroup || ans.industry_desc || '';

    // Update step label to reflect skipped steps
    document.getElementById('step-label').textContent = 'Step 4 of 4';
    document.getElementById('step-q').textContent     = 'Choose a video idea';
    if (nicheName) {
        document.getElementById('cardSubtitle').textContent =
            [coIndustry.group, coIndustry.subgroup, nicheName].filter(Boolean).join(' › ');
    }

    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading video ideas…</span></div>`;

    // Initialise globals
    videoIdeasNiche    = nicheName;
    videoIdeasCategory = categoryName;
    videoIdeasShowAi   = false;

    await loadUserVideoIdeas(nicheName, categoryName, 1);

    // If no saved ideas exist yet, auto-trigger AI immediately — no button click needed.
    // This mirrors how hooks work: AI fires once, results saved, never called again.
    if (videoIdeasMyList.length === 0) {
        body.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating video ideas for ' + escHtml(nicheName) + '…</span></div>';
        await _loadAiVideoSuggestions(nicheName, categoryName);
    } else {
        _renderVideoIdeaList(nicheName, categoryName);
    }

    // Inline Continue (no More here — the list handles its own controls)
    setInlineActions(true, null, 'nextBtn', null, () => { autoSubmitCustomInput(); goNext(); });
    const cb = document.getElementById('step-q-cont-btn');
    if (cb && ans.title) cb.disabled = false;
}

function _renderVideoIdeaList(nicheName, categoryName) {
    const body = document.getElementById('step-body');
    const usedLower   = window._usedVideoIdeasLower || [];
    const freshMyIdeas = videoIdeasMyList.filter(i => !usedLower.includes(i.toLowerCase()));

    let html = '';

    if (!videoIdeasShowAi) {
        // ── My Ideas ─────────────────────────────────────────────────────────
        if (freshMyIdeas.length > 0) {
            html += `<div class="my-niches-label">📝 My Video Ideas (${videoIdeasTotalCount} total)</div>
                     <div class="opts" id="my-ideas-wrap" style="max-height:300px;overflow-y:auto;">`;
            freshMyIdeas.forEach(idea => {
                html += `<div class="opt${ans.title === idea ? ' sel' : ''}" data-v="${esc(idea)}" data-source="user">${escHtml(idea)}</div>`;
            });
            html += `</div>`;
            if (videoIdeasHasMore) {
                html += `<button class="more-btn" id="load-more-ideas-btn" style="margin:8px 0;width:100%;">
                    📖 Load More (${videoIdeasMyList.length}/${videoIdeasTotalCount})
                </button>`;
            }
        } else {
            html += `<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#92400e;margin-bottom:12px;">
                💡 No video ideas yet. Click <strong>AI Suggestions</strong> to generate ideas or add your own below.
            </div>`;
        }

        // AI Suggestions button
        html += `<button class="more-btn" id="ai-suggestions-btn"
                    data-niche="${esc(nicheName)}" data-category="${esc(categoryName)}"
                    style="margin:8px 0;width:100%;background:linear-gradient(135deg,#8b5cf6,#6d28d9);color:white;border:none;">
                    🤖 AI Suggestions
                 </button>`;
    } else {
        // ── AI Suggestions view ───────────────────────────────────────────────
        html += `<div class="my-niches-label">🤖 AI-Generated Suggestions</div>
                 <div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#5b21b6;">
                    ✨ Select an idea to save it to your collection.
                 </div>
                 <div class="opts" id="ai-suggestions-wrap" style="max-height:400px;overflow-y:auto;">`;
        videoIdeasAiSuggestions.forEach(idea => {
            const isUsed = usedLower.includes(idea.toLowerCase());
            html += `<div class="opt${isUsed?' used-idea':''}" data-v="${esc(idea)}" data-source="ai" data-used="${isUsed?'1':'0'}"
                         style="${isUsed?'opacity:.5;cursor:not-allowed;':''}">
                         ${escHtml(idea)}${isUsed?' ✓ (used)':''}
                     </div>`;
        });
        html += `</div>
                 <button class="more-btn" id="back-to-my-ideas-btn" style="margin:8px 0;width:100%;">← Back to My Ideas</button>`;
    }

    // Custom input (always shown)
    html += `<div class="custom-row" style="margin-top:12px;">
        <input class="custom-in" id="idea-cust-in" placeholder="Or type your own video idea…" style="flex:1;">
        <button class="custom-add" id="idea-cust-btn">Add</button>
    </div>`;

    body.innerHTML = html;

    // ── Wire events ───────────────────────────────────────────────────────────
    body.querySelectorAll('#my-ideas-wrap .opt').forEach(el => {
        el.addEventListener('click', () => {
            if (el.style.cursor === 'not-allowed') return;
            _selectVideoIdea(el, el.dataset.v);
        });
    });

    body.querySelectorAll('#ai-suggestions-wrap .opt').forEach(el => {
        el.addEventListener('click', () => {
            if (el.dataset.used === '1') return;
            _selectAndSaveAiIdea(el, el.dataset.v);
        });
    });

    const loadMoreBtn = document.getElementById('load-more-ideas-btn');
    if (loadMoreBtn) loadMoreBtn.onclick = async () => {
        videoIdeasCurrentPage++;
        await loadUserVideoIdeas(videoIdeasNiche, videoIdeasCategory, videoIdeasCurrentPage);
        _renderVideoIdeaList(videoIdeasNiche, videoIdeasCategory);
    };

    const aiBtn = document.getElementById('ai-suggestions-btn');
    if (aiBtn) aiBtn.onclick = () => _loadAiVideoSuggestions(nicheName, categoryName);

    const backBtn = document.getElementById('back-to-my-ideas-btn');
    if (backBtn) backBtn.onclick = () => { videoIdeasShowAi = false; _renderVideoIdeaList(videoIdeasNiche, videoIdeasCategory); };

    const custBtn = document.getElementById('idea-cust-btn');
    const custIn  = document.getElementById('idea-cust-in');
    if (custBtn) custBtn.onclick  = () => _addCustomVideoIdea();
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') _addCustomVideoIdea(); };
}

function _selectVideoIdea(el, idea) {
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(idea.toLowerCase())) { showToast('This idea has already been used'); return; }
    document.querySelectorAll('#step-body .opt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    ans.title = idea;
    setNext(true);
    saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, idea, false);
}

async function _selectAndSaveAiIdea(el, idea) {
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(idea.toLowerCase())) { showToast('Already used'); return; }
    document.querySelectorAll('#step-body .opt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    ans.title = idea;
    setNext(true);
    await saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, idea, false);
    showToast('✓ Idea saved to your collection');
    await loadUserVideoIdeas(videoIdeasNiche, videoIdeasCategory, 1);
    videoIdeasShowAi = false;
    _renderVideoIdeaList(videoIdeasNiche, videoIdeasCategory);
}

async function _addCustomVideoIdea() {
    const inp = document.getElementById('idea-cust-in');
    const v   = inp.value.trim(); if (!v) return; inp.value = '';
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(v.toLowerCase())) { showToast('This idea has already been used'); return; }
    videoIdeasMyList.unshift(v);
    videoIdeasTotalCount++;
    await saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, v, false);
    ans.title = v;
    setNext(true);
    _renderVideoIdeaList(videoIdeasNiche, videoIdeasCategory);
    showToast('✓ Idea added');
}

async function _loadAiVideoSuggestions(nicheName, categoryName) {
    const btn = document.getElementById('ai-suggestions-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Loading…'; }
    try {
        const d = await post({ ajax_action:'get_ai_video_suggestions', niche_name:nicheName, category_name:categoryName });
        if (d.success && d.suggestions) {
            videoIdeasAiSuggestions = d.suggestions;

            // Save all AI suggestions to DB immediately — same pattern as hooks.
            // Next visit loads from hdb_user_video_ideas so AI is never called again
            // for this niche. INSERT IGNORE prevents duplicates.
            if (d.suggestions.length > 0 && nicheName) {
                d.suggestions.forEach(function(idea) {
                    post({
                        ajax_action:   'save_video_idea',
                        niche_name:    nicheName,
                        category_name: categoryName,
                        video_idea:    idea,
                        is_ai_generated: 1,
                    }).catch(function() {});
                });
                // Refresh the my-ideas list so saved ideas appear immediately
                // without the user having to click each one individually
                loadUserVideoIdeas(nicheName, categoryName, 1).then(function() {
                    videoIdeasShowAi = true;
                    _renderVideoIdeaList(nicheName, categoryName);
                });
            } else {
                videoIdeasShowAi = true;
                _renderVideoIdeaList(nicheName, categoryName);
            }
        } else {
            showToast('Could not load AI suggestions');
            if (btn) { btn.disabled = false; btn.innerHTML = '🤖 AI Suggestions'; }
        }
    } catch(e) {
        showToast('Error: ' + e.message);
        if (btn) { btn.disabled = false; btn.innerHTML = '🤖 AI Suggestions'; }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// VIDEO IDEAS BOX
// ═════════════════════════════════════════════════════════════════════════════
let ideasPage    = 1;
let ideasHasMore = false;
let ideasLoading = false;

async function showIdeasBox() {
    // Hide all other panels
    ['modeSelect','modeWizard','modeCampaign','modeContent'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('modeIdeas').style.display = '';
    window.scrollTo({ top:0, behavior:'smooth' });

    // Context pills
    const ctxEl = document.getElementById('ideas-context-pills');
    ctxEl.innerHTML = [coIndustry.group, coIndustry.subgroup, coIndustry.niche]
        .filter(Boolean)
        .map((p, i, a) =>
            `<span class="ideas-ctx-pill">${escHtml(p)}</span>${i < a.length-1 ? '<span class="ideas-ctx-sep">›</span>' : ''}`
        ).join('');

    document.getElementById('ideas-box-subtitle').textContent =
        `Showing ideas for ${coIndustry.niche || coIndustry.subgroup || coIndustry.group}`;

    ideasPage = 1;
    ideasHasMore = false;
    document.getElementById('ideas-chip-list').innerHTML =
        `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Finding video ideas…</span></div>`;
    document.getElementById('ideas-load-more-btn').style.display = 'none';

    await _fetchIdeas(1, true);
}

function _appendIdeaChip(list, idea) {
    var chip = document.createElement('div');
    chip.className = 'idea-chip';
    chip.innerHTML = '<span class="idea-chip-text">' + escHtml(idea) + '</span><span class="idea-chip-arrow">→</span>';
    chip.onclick = function() { selectIdeaAndStart(idea); };
    list.appendChild(chip);
}

async function _generateAiIdeas(append) {
    var list = document.getElementById('ideas-chip-list');
    if (!list) return;
    var mb = document.getElementById('ideas-load-more-btn');
    if (mb) { mb.disabled = true; mb.textContent = 'Loading...'; mb.style.display = ''; }
    var spinner = document.createElement('div');
    spinner.id = 'ai-spinner'; spinner.className = 'loading';
    spinner.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating ideas...</span>';
    if (!append) list.innerHTML = '';
    list.appendChild(spinner);
    try {
        var d = await post({ajax_action:'generate_company_video_ideas',group:coIndustry.group||'',subgroup:coIndustry.subgroup||'',niche:coIndustry.niche||''});
        var sp = document.getElementById('ai-spinner'); if (sp) sp.remove();
        if (d.success && d.ideas && d.ideas.length > 0) {
            d.ideas.forEach(function(idea) { _appendIdeaChip(list, idea); });
            if (mb) { mb.style.display = ''; mb.textContent = '+ More Ideas'; mb.disabled = false; }
        } else {
            if (!append) list.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">💡</div><div>Could not generate ideas. Type your own below.</div></div>';
            if (mb) mb.style.display = 'none';
        }
    } catch(e) {
        var sp2 = document.getElementById('ai-spinner'); if (sp2) sp2.remove();
        if (!append) list.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div><div>Error. Type your own below.</div></div>';
        if (mb) mb.style.display = 'none';
    }
}

async function _fetchIdeas(page, replace) {
    if (ideasLoading) return;
    ideasLoading = true;
    try {
        var d = await post({ajax_action:'get_company_video_ideas',niche_name:coIndustry.niche||'',industry:coIndustry.subgroup||'',page:page});
        if (!d.success) throw new Error('Failed');
        ideasHasMore = d.has_more || false;
        ideasPage = page;
        var list = document.getElementById('ideas-chip-list');
        if (replace) list.innerHTML = '';
        if (!d.ideas || (d.ideas.length === 0 && page === 1)) {
            ideasLoading = false;
            await _generateAiIdeas(false);
            return;
        }
        d.ideas.forEach(function(idea) { _appendIdeaChip(list, idea); });
        var mb = document.getElementById('ideas-load-more-btn');
        if (mb) { mb.style.display = ''; mb.disabled = false; mb.textContent = '+ More Ideas'; }
    } catch(e) {
        console.error('[_fetchIdeas] Error:', e.message, e);
        var el = document.getElementById('ideas-chip-list');
        if (el) el.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div><div>Could not load ideas: ' + e.message + '</div></div>';
    } finally { ideasLoading = false; }
}

async function loadMoreIdeas() {
    var btn = document.getElementById('ideas-load-more-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Loading...'; }
    await _generateAiIdeas(true);
}

function addCustomIdea() {
    var inp = document.getElementById('ideas-custom-in');
    var v = inp ? inp.value.trim() : '';
    if (!v) { if (inp) inp.focus(); return; }
    selectIdeaAndStart(v);
}

function selectIdeaAndStart(idea) {
    // Seed ans from business profile + selected idea
    ans = {
        industry_group: coIndustry.group,
        industry_desc:  coIndustry.subgroup,
        niche:          coIndustry.niche,
        title:          idea,
    };
    stepOpts = {};
    // Jump to hook step (step index 4) — the last step before final summary
    cur = 4;
    ['modeSelect','modeIdeas','modeCampaign','modeContent'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('modeWizard').style.display = '';
    window.scrollTo({ top:0, behavior:'smooth' });
    render();
}

function skipIdeas() {
    document.getElementById('modeIdeas').style.display = 'none';
    document.getElementById('modeSelect').style.display = '';
    window.scrollTo({ top:0, behavior:'smooth' });
}

function goToWizardMode() {
    ['modeSelect','modeIdeas','modeCampaign','modeContent'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('modeWizard').style.display = '';
    render();
}

// ═════════════════════════════════════════════════════════════════════════════
// INIT
// ═════════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2 — BUILD VIDEO MODAL HELPERS
// ═══════════════════════════════════════════════════════════════════════════════
// Resolved dynamically per reel type — never hardcoded
function getS2Endpoint() {
    var rt = (settings.reel_type || '').toLowerCase();
    if (rt.includes('podcast'))      return 'wizard_step2_podcast.php';
    if (rt.includes('talking head')) return 'wizard_step2_avatar.php';
    return 'wizard_step2.php';
}
let s2PodcastId=null, s2Source='wizard', s2MediaType='stock_videos', s2Cancelled=false;

const PROCESSING_MSGS = [
    'Enhancing scene prompts with AI…','Fetching scenes from database…',
    'Generating voiceover audio…','Searching media library…',
    'Assigning images to scenes…','Almost there…'
];
let processingMsgInterval = null;

function showProcessingSpinner(msg) {
    const overlay=document.getElementById('s2ProcessingOverlay'); if(!overlay) return;
    overlay.classList.add('active');
    let i=0;
    const msgEl=document.getElementById('s2ProcessingMsg');
    if(msgEl) msgEl.textContent=msg||PROCESSING_MSGS[0];
    processingMsgInterval=setInterval(()=>{ i=(i+1)%PROCESSING_MSGS.length; if(msgEl) msgEl.textContent=PROCESSING_MSGS[i]; },3000);
}

function hideProcessingSpinner() {
    const overlay=document.getElementById('s2ProcessingOverlay'); if(overlay) overlay.classList.remove('active');
    if(processingMsgInterval){ clearInterval(processingMsgInterval); processingMsgInterval=null; }
}

function updateSpinnerStep(stepName) { const el=document.getElementById('s2ProcessingStep'); if(el) el.textContent=stepName; }

function selMedia(el) { document.querySelectorAll('.s2-media-opt').forEach(x=>x.classList.remove('sel')); el.classList.add('sel'); s2MediaType=el.dataset.val; }

function openS2(source) {
    s2Source=source||'wizard'; s2Cancelled=false; s2PodcastId=null;
    // Sync media type from settings
    if (settings.media_type) {
        const _mtMap={'Stock Images':'stock_images','Stock Videos':'stock_videos','AI Images':'unique_images'};
        const _mt=_mtMap[settings.media_type]||'stock_videos'; s2MediaType=_mt;
        document.querySelectorAll('.s2-media-opt').forEach(o=>o.classList.toggle('sel',o.dataset.val===_mt));
    }
    document.getElementById('s2Setup').style.display='block';
    document.getElementById('s2Progress').style.display='none';
    document.getElementById('s2DoneBar').style.display='none';
    { const _dbg2 = document.getElementById('s2DoneBarGrid'); if(_dbg2) _dbg2.style.display='none'; }
    const _dbg = document.getElementById('s2DoneBarGrid'); if(_dbg) _dbg.style.display='none';
    document.getElementById('s2Log').innerHTML='';
    // GAMES INIT COMMENTED OUT
    // if (!window._gamesInited) {
    //     window._gamesInited = true;
    //     tReset(); wReset(); eNext(); gReset();
    //     const mInpEl = document.getElementById('mInp');
    //     if (mInpEl) mInpEl.addEventListener('keydown', e => { if (e.key === 'Enter') mSub(); });
    // }
    ['s2Step0','s2Step1','s2Step2','s2Step3'].forEach(id=>{const el=document.getElementById(id);if(el){el.className='s2-step';el.querySelector('.s2-step-sub').textContent='Waiting…';}});
    const isPodcast=settings.reel_type&&settings.reel_type.toLowerCase().includes('podcast');
    const isTalkingHead2=settings.reel_type&&settings.reel_type.toLowerCase().includes('talking head');
    const needsImages = isPodcast || isTalkingHead2;
    const folderType  = isTalkingHead2 ? 'avatars' : 'podcaster';

    // Show/hide cards
    document.getElementById('s2HostCard').style.display           = needsImages ? 'block' : 'none';
    document.getElementById('s2GuestCard').style.display          = isPodcast   ? 'block' : 'none';
    document.getElementById('s2StandardVoiceSection').style.display = needsImages ? 'none'  : 'block';
    document.getElementById('s2MediaTypeSection').style.display   = needsImages ? 'none'  : 'block';

    // Populate media type options (Standard/B-Roll only)
    if (!needsImages) {
        const aiHtml = IS_FREE_TRIAL
            ? '<div class="s2-media-opt" style="opacity:.4;cursor:not-allowed;" title="Upgrade">🤖 AI Images 🔒</div>'
            : '<div class="s2-media-opt' + (s2MediaType==='unique_images'?' sel':'') + '" data-val="unique_images" onclick="selMedia(this)">🤖 AI Images</div>';
        document.getElementById('s2MediaTypeSection').innerHTML =
            '<div class="s2-label">Media Type</div>'
          + '<div class="s2-media-opts">'
          +   '<div class="s2-media-opt' + (s2MediaType==='stock_videos'?' sel':'') + '" data-val="stock_videos" onclick="selMedia(this)">🎥 Stock Videos</div>'
          +   '<div class="s2-media-opt' + (s2MediaType==='stock_images'?' sel':'') + '" data-val="stock_images" onclick="selMedia(this)">📷 Stock Images</div>'
          +   aiHtml
          + '</div>';
    }

    if (needsImages) {
        loadPodcasterImages(folderType, isPodcast);
        loadS2Voices();
    } else {
        // If voice already chosen in wizard Step 8, skip voice UI entirely
        if (ans.voice_id) {
            document.getElementById('s2StandardVoiceSection').style.display = 'none';
            // Update modal title to reflect we only need media type
            var hdr = document.querySelector('#s2Panel .s2-header h2');
            if (hdr) hdr.textContent = 'Step 6: Choose Media Type';
            // Pre-fill the hidden select so startBuildVideo can still read it
            loadS2VoicesStandard().then(function() {
                var sel = document.getElementById('s2StdHostVoice');
                if (sel) {
                    var found = Array.from(sel.options).find(function(o){ return o.value === ans.voice_id; });
                    if (found) { sel.value = ans.voice_id; }
                    else {
                        // Voice not in list yet — add it
                        var opt = document.createElement('option');
                        opt.value = ans.voice_id; opt.selected = true;
                        opt.textContent = ans.voice_id.replace('openai:','').replace('azure:','');
                        sel.appendChild(opt); sel.value = ans.voice_id;
                    }
                }
                var rateEl = document.getElementById('s2Rate');
                if (rateEl && ans.voice_rate) rateEl.value = ans.voice_rate;
            });
            // Hide speech speed card too — rate already set in wizard
            var speedCard = document.querySelector('.s2-speed-card');
            if (speedCard) speedCard.style.display = 'none';
        } else {
            loadS2VoicesStandard();
        }
    }

    // Show current credit balance and cost for this reel type
    const rt = (settings.reel_type || '').toLowerCase();
    const thisCost = (rt.includes('podcast') || rt.includes('talking head')) ? 2 : 1;
    const bal = _quota.credit_balance ?? '…';
    const credEl = document.getElementById('s2CreditBalance');
    if (credEl) credEl.innerHTML = `<strong>${bal}</strong> &nbsp;(this video costs <strong>${thisCost}</strong>)`;
    document.getElementById('s2Overlay').classList.add('open');
    document.getElementById('s2CloseBtn').style.display='inline';
}

//function closeS2() { s2Cancelled=true; hideProcessingSpinner(); document.getElementById('s2Overlay').classList.remove('open'); }
function closeS2() { s2Cancelled=true; hideProcessingSpinner(); document.getElementById('s2Overlay').classList.remove('open'); }

// DEBUG: toggle to keep modal open after build finishes
let _s2KeepOpen = false;
function toggleKeepOpen() {
    _s2KeepOpen = !_s2KeepOpen;
    const btn = document.getElementById('s2KeepOpenBtn');
    if (btn) {
        btn.textContent = _s2KeepOpen ? '📌 Pinned — modal stays open' : '📌 Pin Modal Open';
        btn.style.background = _s2KeepOpen ? '#f59e0b' : '#f8fafc';
        btn.style.borderColor = _s2KeepOpen ? '#f59e0b' : 'var(--border)';
        btn.style.color = _s2KeepOpen ? '#fff' : 'var(--muted)';
    }
}





let _voicePreviewAudio = null;

// ── Cached voice data ─────────────────────────────────────────────────────────
// ── Load podcaster / avatar images for the host+guest picker ─────────────────
let _podcasterImages = [];  // cached after first load

async function loadPodcasterImages(folderType, isPodcast) {
    const hostGrid  = document.getElementById('s2HostImageGrid');
    const guestGrid = document.getElementById('s2GuestImageGrid');
    if (hostGrid)  hostGrid.innerHTML  = '<div style="color:var(--muted);font-size:12px;padding:8px;">Loading images…</div>';
    if (guestGrid && isPodcast) guestGrid.innerHTML = '<div style="color:var(--muted);font-size:12px;padding:8px;">Loading images…</div>';

    const isAvatar  = (folderType === 'avatars');
    const endpoint  = isAvatar ? 'wizard_step2_avatar.php'  : 'wizard_step2_podcast.php';
    const actionKey = isAvatar ? 'get_avatar_images'        : 'get_podcaster_images';

    // Thumbnail base URLs:
    // avatars    → podcast_avatars_thumbnails/
    // podcast    → host picker uses podcast_hosts/, guest picker uses podcast_guests/
    const hostThumbBase  = isAvatar ? 'podcast_avatars_thumbnails/' : 'podcast_hosts/';
    const guestThumbBase = isAvatar ? 'podcast_avatars_thumbnails/' : 'podcast_guests/';

    let _hostImages  = [];
    let _guestImages = [];

    try {
        const fd = new FormData();
        fd.append('action', actionKey);
        const r = await fetch(endpoint, { method:'POST', body:fd, credentials:'include' });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const raw = await r.text();
        let d;
        try { d = JSON.parse(raw); }
        catch(je) {
            console.error('[loadPodcasterImages] Non-JSON from ' + endpoint + ':', raw.substring(0,300));
            throw new Error('Server returned non-JSON (check console)');
        }
        if (!d.success) throw new Error(d.error || 'Server error');

        if (isAvatar) {
            // Avatar: single list, normalise to { base, thumb }
            const avatarList = d.avatars || [];
            _hostImages = avatarList.map(item => ({ base: item.avatar_base, thumb: item.thumb }));
            _guestImages = _hostImages;
        } else {
            // Podcast: separate host_images and guest_images lists
            const normalise = list => (list || []).map(item => ({
                base:  item.image_name,
                thumb: item.thumb || (item.image_name + '_p1.png'),
            }));
            _hostImages  = normalise(d.host_images);
            _guestImages = normalise(d.guest_images);
            // Fallback: if server returned merged 'images' only
            if (!_hostImages.length && !_guestImages.length) {
                _hostImages = _guestImages = normalise(d.images);
            }
        }

        _podcasterImages = _hostImages; // keep global in sync for legacy refs
        if (!_hostImages.length) throw new Error('No images returned');
    } catch(e) {
        if (hostGrid) hostGrid.innerHTML = '<div style="color:#ef4444;font-size:12px;padding:8px;">Could not load images: ' + e.message + '</div>';
        return;
    }

    function getSelectedBase(gridId) {
        const el = document.querySelector('#' + gridId + ' .s2-img-card.selected');
        return el ? el.dataset.base : '';
    }

    function renderGrid(gridEl, imageList, imgBase, selectedBase, takenBase, onSelect) {
        if (!gridEl) return;
        gridEl.innerHTML = '';
        if (!imageList.length) {
            gridEl.innerHTML = '<div style="color:var(--muted);font-size:12px;padding:8px;">No images found</div>';
            return;
        }
        imageList.forEach(img => {
            const isSel   = img.base === selectedBase;
            const isTaken = img.base === takenBase;
            const card = document.createElement('div');
            card.className = 's2-img-card' + (isSel ? ' selected' : '') + (isTaken ? ' taken' : '');
            card.dataset.base = img.base;
            card.title = isTaken ? 'Already selected for other role' : img.base;
            card.innerHTML =
                '<img src="' + imgBase + img.thumb + '" onerror="this.onerror=null;this.style.background=\'#e2e8f0\'" loading="lazy">' +
                '<div class="s2-img-label">' + img.base.replace(/_/g,' ') + '</div>' +
                (isSel   ? '<div class="s2-img-check">✓</div>' : '') +
                (isTaken ? '<div class="s2-img-taken">✕</div>'  : '');
            if (!isTaken) card.onclick = () => onSelect(img.base);
            gridEl.appendChild(card);
        });
    }

    function selectInGrid(gridId, base) {
        document.querySelectorAll('#' + gridId + ' .s2-img-card').forEach(c => {
            const isSel = c.dataset.base === base;
            c.classList.toggle('selected', isSel);
            const chk = c.querySelector('.s2-img-check');
            if (chk) chk.remove();
            if (isSel) {
                const d = document.createElement('div'); d.className = 's2-img-check'; d.textContent = '✓';
                c.appendChild(d);
            }
        });
    }

    function renderBoth() {
        const hostSel  = getSelectedBase('s2HostImageGrid');
        const guestSel = isPodcast ? getSelectedBase('s2GuestImageGrid') : '';

        renderGrid(hostGrid, _hostImages, hostThumbBase, hostSel, guestSel, function(base) {
            if (isPodcast && base === getSelectedBase('s2GuestImageGrid')) return;
            selectInGrid('s2HostImageGrid', base);
            renderBoth();
        });

        if (isPodcast && guestGrid) {
            renderGrid(guestGrid, _guestImages, guestThumbBase, guestSel, hostSel, function(base) {
                if (base === getSelectedBase('s2HostImageGrid')) return;
                selectInGrid('s2GuestImageGrid', base);
                renderBoth();
            });
        }
    }

    // Auto-select first for host, second for guest
    if (_hostImages.length > 0) selectInGrid('s2HostImageGrid', _hostImages[0].base);
    if (isPodcast && _guestImages.length > 0) selectInGrid('s2GuestImageGrid', _guestImages[0].base);
    renderBoth();
}

let _allVoices = [];   // full list from get_voices.php
let _sampleAudio = null;

// ── Build option HTML for a voice list ───────────────────────────────────────
function _buildVoiceOpts(list) {
    const esc = s => s.replace(/"/g, '&quot;').replace(/</g,'&lt;');
    return list.map(v => {
        const isAzure   = !(v.voice_id||'').startsWith('openai:');
        const disabled  = IS_FREE_TRIAL && isAzure;
        const lock      = disabled ? ' 🔒' : '';
        const style     = disabled ? ' style="color:#aaa;background:#f8f8f8;"' : '';
        // Clean display name — remove provider prefix if present
        var displayName = (v.voice_name||'').replace(/^openai[:\s]*/i,'').replace(/^azure[:\s]*/i,'');
        return `<option value="${esc(v.voice_id||v.voice_key||'')}"${disabled?' disabled':''}${style} data-sample="${esc(v.sample_voice||'')}" data-gender="${esc(v.gender||'')}">${esc(displayName)}${lock}</option>`;
    }).join('');
}

// ── Fill a <select> filtered by gender ───────────────────────────────────────
function _fillVoiceSelect(selEl, gender, placeholder) {
    if (!selEl) return;
    const filtered = gender === 'all'
        ? _allVoices
        : _allVoices.filter(v => (v.gender||'').toLowerCase() === gender);
    // Show only OpenAI voices — no provider labels
    const openai = filtered.filter(v => (v.voice_id||'').startsWith('openai:'));
    let html = placeholder ? `<option value="">${placeholder}</option>` : '';
    html += _buildVoiceOpts(openai.length ? openai : filtered);
    selEl.innerHTML = html || `<option value="">No ${gender} voices</option>`;
}

// ── Load voices from server, then populate both card selects ─────────────────
async function loadS2Voices() {
    const isPodcast     = (settings.reel_type || '').toLowerCase().includes('podcast');
    const isTalkingHead = (settings.reel_type || '').toLowerCase().includes('talking head');
    const langCode = langCodeFromName(settings.language);

    const hostSel  = document.getElementById('s2HostVoice');
    const guestSel = document.getElementById('s2GuestVoice');
    if (hostSel) hostSel.innerHTML = '<option value="">Loading…</option>';

    try {
        const r = await fetch('get_voices.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'lang_code='+encodeURIComponent(langCode)
        });
        if (!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json();
        _allVoices = d.voices || [];
        if (!_allVoices.length) throw new Error('No voices');
    } catch(e) {
        // Fallback voices with gender tags
        _allVoices = [
            {voice_id:'openai:alloy',   voice_name:'Alloy (neutral)', gender:'male',   sample_voice:''},
            {voice_id:'openai:echo',    voice_name:'Echo',            gender:'male',   sample_voice:''},
            {voice_id:'openai:onyx',    voice_name:'Onyx',            gender:'male',   sample_voice:''},
            {voice_id:'openai:fable',   voice_name:'Fable',           gender:'male',   sample_voice:''},
            {voice_id:'openai:nova',    voice_name:'Nova',            gender:'female', sample_voice:''},
            {voice_id:'openai:shimmer', voice_name:'Shimmer',         gender:'female', sample_voice:''},
        ];
    }

    // Default: show male voices in host, female in guest
    _fillVoiceSelect(hostSel,  'male',   'Select host voice…');
    _fillVoiceSelect(guestSel, 'female', '— Select guest voice —');
}

// ── Standard (non-podcast) voice loader — populates s2StdHostVoice ───────────
async function loadS2VoicesStandard() {
    const sel = document.getElementById('s2StdHostVoice');
    if (!sel) return;
    sel.innerHTML = '<option value="">Loading voices…</option>';
    const langCode = langCodeFromName(settings.language);
    try {
        const r = await fetch('get_voices.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'lang_code='+encodeURIComponent(langCode)
        });
        if (!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json();
        _allVoices = d.voices || [];
        if (!_allVoices.length) throw new Error('No voices');
    } catch(e) {
        _allVoices = [
            {voice_id:'openai:alloy',   voice_name:'Alloy (neutral)', gender:'male',   sample_voice:''},
            {voice_id:'openai:echo',    voice_name:'Echo',            gender:'male',   sample_voice:''},
            {voice_id:'openai:onyx',    voice_name:'Onyx',            gender:'male',   sample_voice:''},
            {voice_id:'openai:fable',   voice_name:'Fable',           gender:'male',   sample_voice:''},
            {voice_id:'openai:nova',    voice_name:'Nova',            gender:'female', sample_voice:''},
            {voice_id:'openai:shimmer', voice_name:'Shimmer',         gender:'female', sample_voice:''},
        ];
    }
    // Default: show male voices, matching active tab
    _fillVoiceSelect(sel, 'male', 'Select voice…');
    // Select default voice after filling
    const _defVoice = settings.voice_id || settings.host_voice || 'openai:alloy';
    const _defOpt = Array.from(sel.options).find(o => o.value === _defVoice)
                 || Array.from(sel.options).find(o => o.value && !o.disabled);
    if (_defOpt) sel.value = _defOpt.value;
}

// ── Gender tab filter for standard mode ──────────────────────────────────────
function filterVoicesStd(gender, btn) {
    document.querySelectorAll('#s2StdGenderTabs .s2-gtab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    _fillVoiceSelect(document.getElementById('s2StdHostVoice'), gender, 'Select voice…');
}

// ── Sample audio for standard mode ───────────────────────────────────────────
function playSampleStd() {
    const sel = document.getElementById('s2StdHostVoice');
    const btn = document.getElementById('s2StdSampleBtn');
    if (!sel || !btn) return;
    if (!sel.value) { showToast('Select a voice first'); return; }
    _playVoicePreview(sel.value, btn);
}

// ── Gender tab filter ─────────────────────────────────────────────────────────
function filterVoices(role, gender, btn) {
    const tabsId  = role === 'host' ? 's2HostGenderTabs' : 's2GuestGenderTabs';
    const selId   = role === 'host' ? 's2HostVoice'      : 's2GuestVoice';
    const ph      = role === 'host' ? 'Select host voice…' : '— Select guest voice —';
    document.querySelectorAll('#'+tabsId+' .s2-gtab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    _fillVoiceSelect(document.getElementById(selId), gender, ph);
}

// ── Sample audio playback ─────────────────────────────────────────────────────
function playSample(role) {
    const selId = role === 'host' ? 's2HostVoice' : 's2GuestVoice';
    const btnId = role === 'host' ? 's2HostSampleBtn' : 's2GuestSampleBtn';
    const sel   = document.getElementById(selId);
    const btn   = document.getElementById(btnId);
    if (!sel || !btn) return;
    if (!sel.value) { showToast('Select a voice first'); return; }
    _playVoicePreview(sel.value, btn);
}


async function loadS2Voices_UNUSED_END() {}  // closing stub

function s2Log(msg, type='info') {
    const log=document.getElementById('s2Log');
    const p=document.createElement('p'); p.className='s2-log-line '+type; p.textContent=msg;
    log.appendChild(p); log.scrollTop=log.scrollHeight;
}

function s2StepStatus(stepNum, status, sub) {
    const el=document.getElementById('s2Step'+stepNum); if(!el) return;
    el.className='s2-step '+status; el.querySelector('.s2-step-sub').textContent=sub;
}

// ── Scene parsing helpers ─────────────────────────────────────────────────────
function parseWizardScenesBasic(rawScript, wizAns) {
    const lines=rawScript.split('\n').map(l=>l.trim()).filter(Boolean);
    // niche and category are always stored in English — use them as the English anchor
    const niche    = wizAns.niche    || 'professional';
    const category = wizAns.topic || wizAns.camp_category || '';
    const anchor   = category ? category.toLowerCase() : niche.toLowerCase();
    // stopWords is English — we only extract keywords from the ENGLISH niche/category strings,
    // NOT from the (possibly foreign-language) scene text, so prompts/nl_tags stay English.
    const stopWords=new Set(['the','and','for','you','your','with','that','this','are','can','will','have','from','they','what','about','more','just','into']);
    // Derive English keyword seeds from niche + category (always English)
    const nicheWords   = niche.toLowerCase().replace(/[^a-z0-9 ]/g,'').split(/\s+/).filter(w=>w.length>2&&!stopWords.has(w));
    const catWords     = anchor.toLowerCase().replace(/[^a-z0-9 ]/g,'').split(/\s+/).filter(w=>w.length>2&&!stopWords.has(w));
    const engKeywords  = [...new Set([...nicheWords, ...catWords])];
    const kw0 = engKeywords[0] || niche.toLowerCase().replace(/\s+/g,'');
    const kw1 = engKeywords[1] || 'professional';
    const kw2 = engKeywords[2] || 'lifestyle';
    return lines.map(line=>{
        const text=line.replace(/<break[^>]*>/gi,'').trim();
        // hashtags: always English — built from niche/category keywords only
        const hashtags=[...new Set([niche.toLowerCase().replace(/\s+/g,''), kw0, kw1, kw2])].join(' ');
        // nl_tags: always English — built from niche+category anchor keywords only (never from foreign-language text)
        const nlParts = [
            `${niche} ${kw0} ${kw1}`.trim(),
            `${anchor} ${kw0}`.trim(),
            `${niche} ${kw1}`.trim(),
            `${anchor} ${kw2}`.trim(),
            `${kw0} ${kw1} ${niche}`.trim(),
        ].filter((v,i,a) => v && a.indexOf(v) === i); // dedupe
        const nlTags = nlParts.join('|');
        // prompts: always English — Scene description uses only niche (English), not the foreign-language text
        const prompt=`Photorealistic documentary-style photograph. Niche: ${niche}. Topic: ${anchor}. Natural lighting, candid composition, 35mm lens, shallow depth of field, authentic environment. Shot on Sony A7R, no yellow cast, crisp clean tones.`;
        const video_prompt=`Cinematic stock video clip. Niche: ${niche}. Topic: ${anchor}. Smooth camera movement, natural lighting, professional footage, authentic real-life environment, 4K quality.`;
        return { text:line, prompt, video_prompt, hashtags, nl_tags:nlTags, actor:'host' };
    });
}

async function enhanceSceneWithAI(sceneText, niche, title, sceneIndex, total, sceneDuration) {
    const cleanText=sceneText.replace(/<break[^>]*>/gi,'').trim();
    const duration=sceneDuration&&sceneDuration>0?sceneDuration:5;
     const imageCount = 5; // always generate all 5 prompt slots

    const systemPrompt=`You are a professional video art director. For each scene, output ONLY valid JSON: {"prompts":["..."],"video_prompt":"...","hashtags":"...","nl_tags":"..."}.
CRITICAL LANGUAGE RULE: ALL output — every image prompt, video_prompt, nl_tags, and hashtags — MUST be written in ENGLISH only, regardless of the language of the scene text. The scene text may be in any language; your job is to describe its visual content in English for an English-language stock image/video library.
Generate ${imageCount} photorealistic image prompts (60-100 words each), 1 cinematic video clip prompt (40-60 words), 3-5 hashtag words, 5-6 pipe-separated nl_tags phrases.

nl_tags STRICT RULES — these are used as search queries against a stock image/video library:
- Each phrase must describe something LITERALLY VISIBLE in the scene — what the camera would physically see
- Always anchor at least 2 tags to the niche or category (e.g. for bridal: "wedding dress", "bridal boutique")
- Every tag must be specific enough that ONLY images from this niche would match it — no cross-niche tags
- NEVER use photography/technical terms: photorealistic, natural lighting, cinematic, shallow depth of field, lens, candid, authentic, 4K, footage, documentary, composition, bokeh, shot on camera
- NEVER use vague process/setting words: creative process, professional setting, modern environment, lifestyle concept, creative studio, designer workspace, business concept
- NEVER use abstract descriptors: concept, style, approach, technique, method, solution
- Good example for bridal niche: "bridal gown fitting|bride in wedding dress|bridal boutique interior|wedding dress detail|bride with designer"
- Bad example for bridal niche: "creative studio|natural lighting|fashion design|professional setting|photorealistic designer"
- Good example for real estate niche: "house exterior|real estate agent showing home|modern kitchen interior|suburban neighbourhood|home for sale sign"
- Bad example for real estate niche: "professional concept|modern lifestyle|creative process|business environment|authentic setting"
- The niche is: ${niche}. Every tag must be visually tied to this niche.

nl_tags STRICT RULES — these are used to search a stock image/video library:
- Each phrase must describe something LITERALLY VISIBLE in the scene (what the camera would see)
- Always anchor at least 2 tags to the niche or category word (e.g. "bridal gown fitting" not "fashion design")
- Every tag must be specific enough that ONLY images from this niche would match — not images from any other niche
- NEVER use photography or technical words: photorealistic, natural lighting, cinematic, shallow depth, lens, candid, authentic, 4K, footage, documentary, composition, bokeh, portrait
- NEVER use vague process or environment words: creative process, professional setting, modern environment, lifestyle concept, designer studio, creative studio, business concept
- Good example for bridal niche: "bridal gown fitting|bride in wedding dress|bridal boutique interior|wedding dress details|bride with designer"
- Bad example for bridal niche: "creative studio|natural lighting|fashion design|professional setting|modern workspace"
- Good example for hypnotherapy niche: "hypnotherapy session|client relaxing in chair|therapist guiding client|calm therapy room|stress relief session"
- Bad example for hypnotherapy niche: "wellness concept|natural healing|peaceful environment|professional consultation|mindful setting"

The "video_prompt" describes a cinematic stock video clip for this scene:
- Start with: "Cinematic stock video clip."
- Describe movement, environment, lighting matching the scene text and niche
- End with: smooth camera movement, natural lighting, 4K quality, photorealistic

STRICT COLOR & LIGHTING RULES — apply to every single prompt:
- Always use cool-neutral daylight lighting (5500K-6500K), never warm/yellow/orange tones
- Always include: soft natural daylight, color accurate, vibrant true-to-life colors, no warm cast
- Always end every prompt with: Shot on Sony A7R, 35mm lens, shallow depth of field, photorealistic, no yellow cast, crisp clean tones
- Never use words like: golden, warm, cozy, amber, candlelight, incandescent

VISUAL CONTINUITY RULES — these ${imageCount} images play sequentially every 2 seconds:
- All prompts must share the same location, subject, lighting and color palette
- Image 1: Establish the full scene (wide or medium shot)
- Image 2: Closer detail or different angle of THE SAME scene
- Image 3+ (if needed): Even closer detail or subtle shift — still the SAME scene
- NEVER switch to a different subject, object, person or location between images

PEOPLE RULES — mandatory for health, wellness and therapy niches:
- ALWAYS include at least one real person in every image
- For niches like hypnotherapy, therapy, counselling, anxiety, stress, depression, mental health, wellness, coaching, life coaching, NLP: ALWAYS show a real human interaction — a therapist with a client, a person looking calm and relieved, someone in a relaxed meditative state, a coach with a client, a person breathing deeply
- NEVER generate empty rooms, abstract concepts, objects only, candles, sofas with no people, nature scenes, or symbolic imagery UNLESS the scene text explicitly describes something with no people
- If the scene text is abstract (e.g. "anxiety can feel overwhelming") — still show a REAL PERSON experiencing or overcoming that emotion, not a symbol of it
- People must look engaged, calm, hopeful or professional — never distressed, crying or in pain

PEOPLE & APPEARANCE RULES — apply to every prompt that includes a person:
- Target market is Canada, USA and Europe — people must look North American or Northern European
- Use descriptors like: fair skin, light complexion, Caucasian, or specify: Canadian, American, British
- Hair: blonde, brown, auburn, light brown, chestnut — not black hair unless specified
- Features: defined jawline, light eyes (blue, green, grey, hazel) or brown eyes with fair skin
- Style: clean, professional, modern Western wardrobe — business casual or smart casual
- NEVER generate faces that appear Southeast Asian, East Asian, South Asian, Middle Eastern or Latin American unless the niche specifically calls for it
- Age range: 28–55 unless the scene calls for something different`;

    const isTherapyNiche = /hypno|therap|counsel|anxiety|stress|depress|mental|wellness|coach|nlp|mindful/i.test(niche);
    const therapyNote = isTherapyNiche
        ? '\nIMPORTANT: This is a ' + niche + ' niche. Every prompt MUST include real people — a therapist, coach, or client. No empty rooms, no objects only, no abstract imagery.'
        : '';

    const userMsg = 'Scene ' + (sceneIndex+1) + '/' + total + '\n'
        + 'Niche: ' + niche + '\n'
        + 'Title: ' + title + '\n'
        + 'Duration: ' + duration + 's (' + imageCount + ' sequential images needed)\n'
        + 'Scene text: "' + cleanText + '"\n'
        + therapyNote + '\n\n'
        + 'Generate exactly ' + imageCount + ' image prompts that form a visual sequence for this scene.\n'
        + 'They must show the SAME subject from different angles/distances — not different subjects.';


  try{
        const resp=await fetch('enhance_scene.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({scenes:[{index:0,system:systemPrompt,message:userMsg}]})});
        const data=await resp.json();
        const raw=(data.results?.[0]?.response)||data.response||'';
        const cleaned=raw.replace(/^```(?:json)?\s*/i,'').replace(/\s*```$/i,'').trim();
        const parsed=JSON.parse(cleaned);
        if(parsed.prompts&&parsed.hashtags&&parsed.nl_tags){
            const videoPrompt = parsed.video_prompt ||
                `Cinematic stock video clip. Niche: ${niche}. Smooth camera movement, natural lighting, professional footage, authentic real-life environment, 4K quality.`;
            return{text:sceneText,prompt:parsed.prompts[0]||'',prompts:parsed.prompts,video_prompt:videoPrompt,hashtags:parsed.hashtags,nl_tags:parsed.nl_tags,actor:'host',image_count:imageCount};
        }
        throw new Error('Incomplete response');
    }catch(e){
        console.warn('[enhanceSceneWithAI] fallback:', e.message);
        const basic=parseWizardScenesBasic(sceneText,{niche})[0];
        return{...basic,prompts:[basic?.prompt||cleanText],image_count:1,_fallback:true};
    }
}

async function parseWizardScenes(rawScript, wizAns, logFn, sceneDurations) {
    const lines = rawScript.split('\n').map(l => l.trim()).filter(Boolean);
    const niche = wizAns.niche || 'professional';
    const title = wizAns.title || 'Video';
    const total = lines.length;
    const durationMap = {'15 seconds':15,'30 seconds':30,'60 seconds':60,'90 seconds':90};
    const totalSecs = durationMap[wizAns.duration] || 60;
    const sceneDur = Math.max(4, Math.round(totalSecs / total));

    if (logFn) logFn(`✨ Enhancing all ${total} scenes in parallel…`, 'info');
    updateSpinnerStep(`Enhancing ${total} scenes simultaneously…`);

    // ── PARALLEL: all enhance calls fire at the same time ──
    const scenes = await Promise.all(
        lines.map((line, i) =>
            enhanceSceneWithAI(line, niche, title, i, total, sceneDur)
                .then(scene => {
                    if (logFn) logFn(`✓ Scene ${i + 1}/${total} enhanced`, 'success');
                    return scene;
                })
                .catch(err => {
                    if (logFn) logFn(`⚠ Scene ${i + 1} enhance failed, using basic`, 'warning');
                    return parseWizardScenesBasic(line, { niche })[0];
                })
        )
    );

    return scenes;
}



// ═══════════════════════════════════════════════════════════════════════════════
// B-ROLL BACKGROUND CLASSIFIER
// ═══════════════════════════════════════════════════════════════════════════════
async function classifyBrollBackground(scriptText, niche, title) {

    const BROLL_CATEGORIES = [
        {
            key: 'background_nature',
            label: 'Nature / Greenery',
            keywords: ['nature','forest','tree','garden','green','plant','leaf','park','outdoor','environment','eco'],
            nl_tags: 'background_nature|lush green nature scene|outdoor forest landscape|calm greenery background|nature b-roll footage',
            hashtags: 'background_nature nature green outdoor landscape',
            prompt: 'Cinematic B-Roll background. Lush green nature scene, forest path with sunlight filtering through trees, soft bokeh, 4K drone shot, cool neutral daylight, no people, photorealistic. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast, crisp clean tones.'
        },
        {
            key: 'background_sunset',
            label: 'Sunset / Golden Sky',
            keywords: ['sunset','sunrise','sky','dusk','dawn','horizon','evening','morning','twilight','clouds'],
            nl_tags: 'background_sunset|dramatic sunset sky|golden hour sky background|colorful sky at dusk|cinematic sunset b-roll',
            hashtags: 'background_sunset sunset sky dusk cinematic',
            prompt: 'Cinematic B-Roll background. Breathtaking sunset over the horizon, dramatic orange and pink clouds, wide landscape, vibrant true-to-life colors, no people, photorealistic. Shot on Sony A7R, 24mm lens, deep depth of field, crisp clean tones.'
        },
        {
            key: 'background_beach',
            label: 'Beach / Ocean',
            keywords: ['beach','ocean','sea','wave','sand','coast','water','surf','tropical','island','shore'],
            nl_tags: 'background_beach|tropical beach waves|ocean shore background|sandy beach b-roll|calm sea water footage',
            hashtags: 'background_beach ocean waves tropical shore',
            prompt: 'Cinematic B-Roll background. Beautiful tropical beach with gentle waves, white sand, crystal clear turquoise water, aerial perspective, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast.'
        },
        {
            key: 'background_people_walking',
            label: 'People Walking / City Life',
            keywords: ['people','walking','crowd','street','city','urban','busy','lifestyle','commute','pedestrian','hustle'],
            nl_tags: 'background_people_walking|people walking city street|urban lifestyle background|busy street crowd b-roll|city commute footage',
            hashtags: 'background_people_walking urban city street lifestyle',
            prompt: 'Cinematic B-Roll background. People walking on a modern city street, urban environment, natural movement, slight motion blur, cool neutral daylight, photorealistic. Shot on Sony A7R, 50mm lens, shallow depth of field, no yellow cast, crisp clean tones.'
        },
        {
            key: 'background_islamic',
            label: 'Islamic / Spiritual',
            keywords: ['islamic','islam','muslim','mosque','prayer','quran','ramadan','spiritual','faith','religion','arabic','masjid'],
            nl_tags: 'background_islamic|mosque architecture background|islamic spiritual scene|arabic calligraphy background|masjid b-roll footage',
            hashtags: 'background_islamic mosque spiritual prayer arabic',
            prompt: 'Cinematic B-Roll background. Beautiful mosque architecture with intricate geometric patterns, soft natural light through ornate windows, peaceful spiritual atmosphere, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, crisp clean tones.'
        },
        {
            key: 'background_food_fruits',
            label: 'Food / Fruits',
            keywords: ['food','fruit','eat','meal','nutrition','health','diet','cook','kitchen','recipe','vegetable','organic'],
            nl_tags: 'background_food_fruits|fresh fruits and vegetables|healthy food background|colourful produce b-roll|organic food photography',
            hashtags: 'background_food_fruits food healthy nutrition organic',
            prompt: 'Cinematic B-Roll background. Fresh colourful fruits and vegetables arranged beautifully, vibrant produce, natural studio lighting, macro detail shots, photorealistic, cool neutral daylight. Shot on Sony A7R, 100mm macro lens, shallow depth of field, no yellow cast.'
        },
        {
            key: 'background_office_business',
            label: 'Office / Business',
            keywords: ['business','office','work','professional','corporate','finance','meeting','desk','laptop','entrepreneur','success','money'],
            nl_tags: 'background_office_business|modern office background|professional business setting|corporate workspace b-roll|entrepreneur desk footage',
            hashtags: 'background_office_business office professional corporate work',
            prompt: 'Cinematic B-Roll background. Modern professional office environment, clean minimal workspace, natural window light, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast, crisp clean tones.'
        },
        {
            key: 'background_fitness',
            label: 'Fitness / Gym',
            keywords: ['fitness','gym','workout','exercise','training','sport','health','muscle','yoga','run','weight','athlete'],
            nl_tags: 'background_fitness|gym fitness equipment background|workout training environment|sport fitness b-roll|exercise studio footage',
            hashtags: 'background_fitness gym workout sport health',
            prompt: 'Cinematic B-Roll background. Modern gym with fitness equipment, clean well-lit environment, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast, crisp clean tones.'
        },
        {
            key: 'background_medical',
            label: 'Medical / Health',
            keywords: ['medical','health','doctor','hospital','clinic','therapy','treatment','wellness','mental','anxiety','stress','hypno'],
            nl_tags: 'background_medical|medical clinic background|health wellness environment|therapy room b-roll|clean clinical setting footage',
            hashtags: 'background_medical health clinic therapy wellness',
            prompt: 'Cinematic B-Roll background. Clean modern medical clinic or therapy room, minimal professional environment, soft natural light, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast.'
        },
        {
            key: 'background_technology',
            label: 'Technology / Digital',
            keywords: ['tech','technology','digital','ai','software','code','computer','data','internet','innovation','app','robot'],
            nl_tags: 'background_technology|technology digital background|futuristic tech environment|digital data visualization|innovation tech b-roll',
            hashtags: 'background_technology tech digital innovation futuristic',
            prompt: 'Cinematic B-Roll background. Futuristic technology environment with digital elements, glowing screens, data visualizations, clean dark background with blue accent lighting, no people, photorealistic. Shot on Sony A7R, 35mm lens, shallow depth of field, crisp clean tones.'
        },
        {
            key: 'background_abstract',
            label: 'Abstract / Minimal',
            keywords: [],
            nl_tags: 'background_abstract|abstract minimal background|clean geometric shapes|modern minimal b-roll|cinematic abstract footage',
            hashtags: 'background_abstract minimal geometric modern cinematic',
            prompt: 'Cinematic B-Roll background. Abstract minimal design with clean geometric shapes, soft gradient tones, modern aesthetic, no people, photorealistic, cool neutral daylight. Shot on Sony A7R, 35mm lens, shallow depth of field, no yellow cast, crisp clean tones.'
        },
    ];

    const combined = (scriptText + ' ' + niche + ' ' + title).toLowerCase();
    let bestCategory = BROLL_CATEGORIES[BROLL_CATEGORIES.length - 1];
    let bestScore    = 0;

    for (const cat of BROLL_CATEGORIES) {
        if (cat.keywords.length === 0) continue;
        let score = 0;
        for (const kw of cat.keywords) {
            if (combined.includes(kw)) score++;
        }
        if (score > bestScore) {
            bestScore    = score;
            bestCategory = cat;
        }
    }

    s2Log(`🔍 B-Roll classifier: best="${bestCategory.label}" score=${bestScore}`, 'info');

    if (bestScore <= 1) {
        try {
            s2Log('🤖 Low keyword score — asking AI to classify B-Roll background…', 'info');
            const resp = await fetch('enhance_scene.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    system: `You are a video background selector. Given a script and niche, pick the single best background category from this list:
${BROLL_CATEGORIES.map(c => c.key).join(', ')}
Return ONLY the category key, nothing else. Example: background_nature`,
                    message: `Niche: ${niche}\nTitle: ${title}\nScript: ${scriptText.substring(0, 300)}`
                })
            });
            const data = await resp.json();
            const aiKey = (data.response || '').trim().toLowerCase().replace(/[^a-z_]/g, '');
            const aiMatch = BROLL_CATEGORIES.find(c => c.key === aiKey);
            if (aiMatch) {
                s2Log(`✅ AI classified as: ${aiMatch.label}`, 'success');
                bestCategory = aiMatch;
            }
        } catch(e) {
            s2Log(`⚠ AI classification failed: ${e.message}`, 'warning');
        }
    }

    return bestCategory;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GENERATE 5 VARIED B-ROLL PROMPTS
// ═══════════════════════════════════════════════════════════════════════════════
async function generateBrollPrompts(category, niche, title) {

    const SHOT_VARIATIONS = [
        'Wide establishing shot, full scene visible, deep depth of field.',
        'Medium shot, closer perspective, slight bokeh in background.',
        'Close-up detail shot, macro focus on key element, shallow depth of field.',
        'Low angle shot looking upward, dramatic perspective, wide lens.',
        'Aerial or high angle top-down shot, overview perspective, drone-style.'
    ];

    try {
        s2Log('🤖 Asking AI to generate 5 B-Roll prompts…', 'info');

        const systemPrompt = `You are a professional cinematographer specialising in B-Roll background footage.
Generate exactly 5 photorealistic image prompts for the same background category, each from a different camera angle.
Output ONLY valid JSON: {"prompts": ["prompt1", "prompt2", "prompt3", "prompt4", "prompt5"]}

RULES:
- Background category: ${category.label}
- No people unless category requires them (e.g. background_people_walking)
- Cool-neutral daylight lighting, never warm/yellow/orange tones
- Always end with: Shot on Sony A7R, photorealistic, no yellow cast, crisp clean tones
- Each prompt 60-100 words
- Shot 1: Wide establishing shot
- Shot 2: Medium shot, different angle
- Shot 3: Close-up detail
- Shot 4: Low angle / dramatic perspective
- Shot 5: High angle / aerial perspective`;

        const userMsg = `Background category: ${category.label}
Niche: ${niche}
Title: ${title}
Base prompt: ${category.prompt}
Generate 5 varied B-Roll prompts each from a different camera angle.`;

        const resp = await fetch('enhance_scene.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ system: systemPrompt, message: userMsg })
        });

        const data    = await resp.json();
        const raw     = data.response || '';
        const cleaned = raw.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();
        const parsed  = JSON.parse(cleaned);

        if (parsed.prompts && Array.isArray(parsed.prompts) && parsed.prompts.length >= 3) {
            while (parsed.prompts.length < 5) {
                parsed.prompts.push(category.prompt + ' ' + SHOT_VARIATIONS[parsed.prompts.length]);
            }
            s2Log(`✅ AI generated ${parsed.prompts.length} B-Roll prompts`, 'success');
            return parsed.prompts.slice(0, 5);
        }
        throw new Error('Not enough prompts returned');

    } catch(e) {
        s2Log(`⚠ AI prompt generation failed: ${e.message} — using template variations`, 'warning');
    }

    const basePrompt = category.prompt
        .replace(/Shot on Sony A7R.*$/i, '')
        .trim();

    return SHOT_VARIATIONS.map(variation =>
        `${basePrompt} ${variation} Shot on Sony A7R, photorealistic, no yellow cast, crisp clean tones.`
    );
}




// REPLACE WITH:
async function updatePodcastThumbnail(podcastId) {
    if(!podcastId) return;

    // Reset reel_type to Standard after podcast or b-roll build completes
    const currentReelType = (settings.reel_type || '').toLowerCase();
    if (currentReelType.includes('podcast') || currentReelType.includes('b-roll') || currentReelType.includes('broll')) {
        settings.reel_type = 'Standard';
        localStorage.setItem('vw_settings', JSON.stringify(settings));
        renderSettingsBar();
        s2Log('⚙ Reel type reset to Standard', 'info');
    }

    try{
        const fd=new FormData(); fd.append('action','get_thumbnail_from_scene'); fd.append('podcast_id',podcastId);
        const r=await fetch(getS2Endpoint(),{method:'POST',body:fd,credentials:'include'});
        const d=await r.json();
        if(d.success&&d.thumbnail) s2Log('🖼 Thumbnail updated: '+d.thumbnail,'success');
    }catch(e){ s2Log('⚠ Could not update thumbnail: '+e.message,'warning'); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// startBuildVideo()
//
// ═══════════════════════════════════════════════════════════════════════════════
async function startBuildVideo() {

    const _buildStart = performance.now();
    let _step0Sec = '0.0', _step1Sec = '0.0', _audioSec = '0.0', _mediaSec = '0.0';

    const isPodcast     = (settings.reel_type || '').toLowerCase().includes('podcast');
    const isBroll       = (settings.reel_type || '').toLowerCase().includes('b-roll')
                       || (settings.reel_type || '').toLowerCase().includes('broll');
    const isTalkingHead = (settings.reel_type || '').toLowerCase().includes('talking head');
    // Credit cost: podcast and talking head = 2 credits, standard and b-roll = 1 credit
    const creditCost    = (isPodcast || isTalkingHead) ? 2 : 1;

    await loadVideoQuota();
    if (isQuotaExceeded(creditCost)) {
        closeS2();
        showQuotaModal();
        return;
    }

    // Deduct credits upfront before build starts
    s2Log('💳 Deducting ' + creditCost + ' credit(s)…', 'info');
    const deductResult = await deductCredit(creditCost,
        'Video: ' + (window._wizData?.title || window._wizAns?.title || 'Script') + ' [' + (settings.reel_type || 'Standard') + ']'
    );
    if (!deductResult.success) {
        closeS2();
        showQuotaModal();
        s2Log('❌ Credit deduction failed: ' + deductResult.message, 'error');
        return;
    }
    s2Log('✅ Credits deducted — balance: ' + deductResult.new_balance, 'success');

    const hostVoice  = (isPodcast || isTalkingHead)
        ? document.getElementById('s2HostVoice').value
        : document.getElementById('s2StdHostVoice').value;
    const guestVoice = isPodcast
        ? (document.getElementById('s2GuestVoice').value || hostVoice)
        : hostVoice;
    const rate       = document.getElementById('s2Rate').value;

    if (!hostVoice) { alert('Please select a host voice'); return; }

    if (isPodcast) {
        const guestVal = document.getElementById('s2GuestVoice').value;
        if (!guestVal) { alert('Please select a guest voice for Podcast mode'); return; }
        if (guestVal === hostVoice) { alert('Host and guest voices must be different for Podcast mode'); return; }
    }

    const S2_ENDPOINT = getS2Endpoint();

    document.getElementById('s2Setup').style.display    = 'none';
    document.getElementById('s2Progress').style.display = 'block';
    document.getElementById('s2SceneGrid').style.display = 'block';
    
    document.getElementById('s2CloseBtn').style.display = 'none';
    s2Cancelled = false;

    showProcessingSpinner('Step 5: Building your video…');

    let langCode = langCodeFromName(settings.language);

    let rawForParse = '';
    let wizAnsForParse = {};
    let scenes = [];

    const editedScriptEl = s2Source === 'wizard'
        ? document.getElementById('script-text')
        : s2Source === 'content'
            ? document.getElementById('content-script-text')
            : null;
    const editedScript = editedScriptEl ? editedScriptEl.value.trim() : '';

    if (s2Source === 'wizard') {
        rawForParse = editedScript || window._wizScriptRaw || '';
        if (editedScript) {
            window._wizScriptRaw = editedScript;
            
            if (window._wizPodcastId) {
                s2Log('📝 Saving edited script to database...', 'info');
                await saveEditedScriptToPodcast(window._wizPodcastId, editedScript);
            }
        }
        wizAnsForParse = Object.assign({}, window._wizAns || ans);

        if (isBroll) {
            rawForParse = rawForParse
                .replace(/<break[^>]*\/>/gi, '')
                .replace(/[ \t]+/g, ' ')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            window._wizScriptRaw = rawForParse;

            const brollNiche = wizAnsForParse.niche || 'general';
            const brollTitle = wizAnsForParse.title || 'My Video';

            const brollCategory = await classifyBrollBackground(rawForParse, brollNiche, brollTitle);
            s2Log(`🎬 B-Roll category: ${brollCategory.label}`, 'info');

            const brollPrompts = await generateBrollPrompts(brollCategory, brollNiche, brollTitle);
            s2Log(`✅ Generated ${brollPrompts.length} B-Roll prompts`, 'success');

            scenes = [{
                text: rawForParse,
                prompt:   brollPrompts[0] || brollCategory.prompt,
                prompts:  brollPrompts,
                hashtags: brollCategory.hashtags,
                nl_tags:  brollCategory.nl_tags,
                actor:    'host',
                image_count: 5
            }];
        } else {
            scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse);
        }

    } else if (s2Source === 'content') {
        rawForParse = editedScript || window._contentScriptRaw || '';
        if (editedScript) {
            window._contentScriptRaw = editedScript;
            
            if (window._contentPodcastId) {
                s2Log('📝 Saving edited script to database...', 'info');
                await saveEditedScriptToPodcast(window._contentPodcastId, editedScript);
            }
        }
        const contentTitle = document.getElementById('content-title')?.value.trim() || 'My Video';
        wizAnsForParse = { niche: 'general', title: contentTitle, topic: contentTitle };

        if (isBroll) {
            rawForParse = rawForParse
                .replace(/<break[^>]*\/>/gi, '')
                .replace(/[ \t]+/g, ' ')
                .replace(/\n{3,}/g, '\n\n')
                .trim();
            window._contentScriptRaw = rawForParse;

            const contentTitle = document.getElementById('content-title')?.value.trim() || 'My Video';
            const brollCategory = await classifyBrollBackground(rawForParse, 'general', contentTitle);
            s2Log(`🎬 B-Roll category: ${brollCategory.label}`, 'info');

            const brollPrompts = await generateBrollPrompts(brollCategory, 'general', contentTitle);
            s2Log(`✅ Generated ${brollPrompts.length} B-Roll prompts`, 'success');

            scenes = [{
                text:        rawForParse,
                prompt:      brollPrompts[0] || brollCategory.prompt,
                prompts:     brollPrompts,
                hashtags:    brollCategory.hashtags,
                nl_tags:     brollCategory.nl_tags,
                actor:       'host',
                image_count: 5
            }];
        } else {
            scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse);
        }
    }

    // =========================================================================
    // PODCAST / TALKING HEAD BRANCH
    // =========================================================================
    if (isPodcast || isTalkingHead) {

        s2StepStatus(0, 'done', '⏭ Skipped — uses character images');
        s2Log('⏭ ' + (isTalkingHead ? 'Talking Head' : 'Podcast') + ' mode: AI prompt enhancement skipped', 'info');

        // ── Get user-selected host / guest images from the UI picker ──────────
        const hostImgCard  = document.querySelector('#s2HostImageGrid .s2-img-card.selected');
        const guestImgCard = isPodcast ? document.querySelector('#s2GuestImageGrid .s2-img-card.selected') : null;
        // .base = avatar_01 (talking head) or host_female_2 (podcast)
        let hostImagename  = hostImgCard  ? hostImgCard.dataset.base  : '';
        let guestImagename = guestImgCard ? guestImgCard.dataset.base : '';

        if (!hostImagename) {
            s2Log('❌ Please select a ' + (isTalkingHead ? 'avatar' : 'host image') + ' before building', 'error');
            document.getElementById('s2Setup').style.display    = 'block';
            document.getElementById('s2Progress').style.display = 'none';
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }
        if (isPodcast && !guestImagename) {
            s2Log('❌ Please select a guest image before building', 'error');
            document.getElementById('s2Setup').style.display    = 'block';
            document.getElementById('s2Progress').style.display = 'none';
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }
        if (isPodcast && hostImagename === guestImagename) {
            s2Log('❌ Host and guest must be different images', 'error');
            document.getElementById('s2Setup').style.display    = 'block';
            document.getElementById('s2Progress').style.display = 'none';
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }

        s2Log(`🎭 Host image: ${hostImagename}${isPodcast ? ' | Guest image: ' + guestImagename : ''}`, 'info');

        s2StepStatus(1, 'active', 'Setting up ' + (isTalkingHead ? 'talking head' : 'podcast') + '…');
        updateSpinnerStep('Setting up…');

        let podcastId = s2Source === 'wizard'  ? window._wizPodcastId
                      : s2Source === 'content' ? window._contentPodcastId
                      : null;

        if (!podcastId) {
            const dataToSave = s2Source === 'wizard'
                ? (window._wizData || Object.assign({}, window._wizAns || ans, settings))
                : (window._contentData || {
                    niche: 'custom',
                    title: document.getElementById('content-title')?.value.trim() || 'My Video'
                  });

            s2Log('💾 Creating podcast record…', 'info');
            try {
                const saveResp = await fetch('save_script_to_db.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify({ script: rawForParse.replace(/<break[^>]*>/gi,'').trim(), data: dataToSave })
                });
                const saveData = await saveResp.json();
                if (!saveData.success) throw new Error(saveData.error || 'Save failed');
                podcastId = saveData.podcast_id;
                if (s2Source === 'wizard')  window._wizPodcastId     = podcastId;
                if (s2Source === 'content') window._contentPodcastId = podcastId;
                s2Log(`✅ Podcast #${podcastId} created`, 'success');
                
                if (editedScript && editedScript !== rawForParse) {
                    s2Log('📝 Saving edited script to database...', 'info');
                    await saveEditedScriptToPodcast(podcastId, editedScript);
                    rawForParse = editedScript;
                }
                
            } catch (e) {
                s2StepStatus(1, 'error', 'Failed: ' + e.message);
                s2Log('❌ ' + e.message, 'error');
                document.getElementById('s2CloseBtn').style.display = 'inline';
                hideProcessingSpinner();
                return;
            }
        } else {
            s2Log(`✅ Reusing podcast #${podcastId}`, 'success');
            
            if (editedScript) {
                s2Log('📝 Saving edited script to existing podcast...', 'info');
                await saveEditedScriptToPodcast(podcastId, editedScript);
                rawForParse = editedScript;
            }
        }

        if (!podcastId) {
            s2StepStatus(1, 'error', 'No podcast ID — please regenerate');
            s2Log('❌ No podcast_id found.', 'error');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }

        s2PodcastId = podcastId;

        try {
            const voiceFd = new FormData();
            voiceFd.append('action',      'update_podcast_voice');
            voiceFd.append('podcast_id',  podcastId);
            voiceFd.append('host_voice',  hostVoice);
            voiceFd.append('guest_voice', guestVoice || hostVoice);
            voiceFd.append('rate',        rate);
            await fetch(S2_ENDPOINT, { method: 'POST', body: voiceFd, credentials: 'include' });
            s2Log(`✅ Voices saved — host: ${hostVoice} | guest: ${guestVoice || hostVoice}`, 'success');
        } catch (e) {
            s2Log(`⚠ Voice save error: ${e.message}`, 'warning');
        }

        if (s2Cancelled) { s2Log('⏹ Cancelled', 'warning'); hideProcessingSpinner(); return; }

        s2Log('📝 Creating scenes from script…', 'info');
        updateSpinnerStep('Creating scenes…');
        try {
            const scFd = new FormData();
            if (isTalkingHead) {
                scFd.append('action',     'create_scenes_from_avatar');
                scFd.append('podcast_id', podcastId);
                scFd.append('host_voice', hostVoice);
                scFd.append('rate',       rate);
                scFd.append('lang_code',  langCode);
            } else {
                scFd.append('action',      'create_scenes_from_podcast');
                scFd.append('podcast_id',  podcastId);
                scFd.append('host_voice',  hostVoice);
                scFd.append('guest_voice', guestVoice || hostVoice);
                scFd.append('rate',        rate);
                scFd.append('lang_code',   langCode);
                scFd.append('is_broll',    isBroll ? '1' : '0');
            }
            const scResp = await fetch(S2_ENDPOINT, { method: 'POST', body: scFd, credentials: 'include' });
            if (!scResp.ok) throw new Error('HTTP ' + scResp.status + ' from server');
            const scText = await scResp.text();
            if (!scText || !scText.trim()) throw new Error('Empty response from server');
            let scData;
            try { scData = JSON.parse(scText); } catch(pe) { throw new Error('Invalid JSON: ' + scText.substring(0,100)); }
            if (!scData.success) throw new Error(scData.error || 'Scene creation failed');
            s2Log(`✅ ${scData.scene_count} scenes created`, 'success');
        } catch (e) {
            s2StepStatus(1, 'error', 'Scene creation failed: ' + e.message);
            s2Log('❌ ' + e.message, 'error');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }

        // Apply user animation settings to all captions of the new video
        await initCaptionsFromSettings(podcastId);

        s2StepStatus(1, 'done', `✓ ${isTalkingHead ? 'Talking Head' : 'Podcast'} #${podcastId} ready`);

        s2StepStatus(2, 'active', 'Fetching scenes…');
        updateSpinnerStep('Loading scenes from DB…');

        let dbScenes = [];
        try {
            const getFd = new FormData();
            getFd.append('action',     'get_scenes');
            getFd.append('podcast_id', podcastId);
            const _getD = await s2SafeFetch(S2_ENDPOINT, { method: 'POST', body: getFd, credentials: 'include' });
            dbScenes = Array.isArray(_getD) ? _getD : [];
            s2Log(`📋 Fetched ${dbScenes.length} scenes`, 'info');
        } catch (e) {
            s2Log('⚠ Could not fetch scenes: ' + e.message, 'warning');
        }

        if (dbScenes.length === 0) {
            s2StepStatus(2, 'error', 'No scenes found');
            s2Log('❌ No scenes to process', 'error');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }

        let hostImagename_resolved  = hostImagename;
        let guestImagename_resolved = guestImagename;

        // Per-actor pose counters (1→2→3→4→1…)
        let hostPoseCtr   = 1;
        let guestPoseCtr  = 1;
        let malePoseCtr   = 1;
        let femalePoseCtr = 1;
        let sceneDone     = 0;
        let sceneFail     = 0;

        for (let i = 0; i < dbScenes.length; i++) {
            if (s2Cancelled) break;

            const scene = dbScenes[i];
            const seqNo = i + 1;
            const actor = (scene.actor || 'host').toLowerCase();

            // Determine pose counter for this scene
            let poseCtrForScene;
            if (actor === 'guest' && isPodcast) {
                poseCtrForScene = guestPoseCtr;
                guestPoseCtr    = (guestPoseCtr % 4) + 1;
            } else {
                poseCtrForScene = hostPoseCtr;
                hostPoseCtr     = (hostPoseCtr % 4) + 1;
            }

            s2StepStatus(2, 'active', `Scene ${seqNo}/${dbScenes.length}…`);
            s2StepStatus(3, 'active', `Scene ${seqNo}/${dbScenes.length}…`);
            updateSpinnerStep(`Processing scene ${seqNo} of ${dbScenes.length}…`);
            s2Log(`▶ Scene ${seqNo}/${dbScenes.length} — actor: ${actor} | pose: _p${poseCtrForScene}`, 'info');

            const fd = new FormData();

            if (isTalkingHead) {
                // Talking Head: single avatar, process_avatar_scene action
                fd.append('action',      'process_avatar_scene');
                fd.append('scene_id',    scene.id);
                fd.append('podcast_id',  podcastId);
                fd.append('seq_no',      seqNo);
                fd.append('lang_code',   langCode);
                fd.append('host_voice',  hostVoice);
                fd.append('rate',        rate);
                fd.append('avatar_base', hostImagename);  // e.g. "avatar_01"
            } else {
                // Podcast: host + guest, process_podcast_scene action
                fd.append('action',               'process_podcast_scene');
                fd.append('scene_id',             scene.id);
                fd.append('podcast_id',           podcastId);
                fd.append('seq_no',               seqNo);
                fd.append('lang_code',            langCode);
                fd.append('reel_type',            settings.reel_type || 'podcast');
                fd.append('host_voice',           hostVoice);
                fd.append('guest_voice',          guestVoice || hostVoice);
                fd.append('rate',                 rate);
                fd.append('male_pose_counter',    poseCtrForScene);
                fd.append('female_pose_counter',  poseCtrForScene);
                fd.append('host_imagename',       hostImagename);
                fd.append('guest_imagename',      guestImagename);
            }

            try {
                const r = await fetch(S2_ENDPOINT, { method: 'POST', body: fd, credentials: 'include' });
                const d = await r.json();

                if (d.success) {
                    // PHP still returns updated counters — sync just in case
                    malePoseCtr   = d.male_pose_counter   ?? malePoseCtr;
                    femalePoseCtr = d.female_pose_counter ?? femalePoseCtr;
                    const audioInfo  = d.audio_file   ? `audio: ${d.audio_file}`  : 'audio: skipped';
                    const imageInfo  = d.image_file   ? `image: ${d.image_file}`  : 'image: none';
                    const genderInfo = d.voice_gender ? `[${d.voice_gender}]`     : '';
                    s2Log(`  ✓ ${audioInfo} | ${imageInfo} ${genderInfo}`, 'success');
                    if (d.errors?.length) d.errors.forEach(err => s2Log(`  ⚠ ${err}`, 'warning'));
                    sceneDone++;
                } else {
                    s2Log(`  ✗ Scene ${seqNo} failed: ${d.error || 'unknown error'}`, 'error');
                    sceneFail++;
                }
            } catch (e) {
                s2Log(`  ✗ Scene ${seqNo} exception: ${e.message}`, 'error');
                sceneFail++;
            }
        }

        s2StepStatus(2, sceneFail > 0 ? 'error' : 'done',
            `✓ ${sceneDone} scenes processed${sceneFail > 0 ? ` (${sceneFail} failed)` : ''}`);
        s2StepStatus(3, sceneFail > 0 ? 'error' : 'done',
            `✓ ${sceneDone} images assigned`);

        await updatePodcastThumbnail(podcastId);
        hideProcessingSpinner();
        document.getElementById('s2CloseBtn').style.display = 'inline';
        document.getElementById('s2VideoLink').href         = 'videomaker.php?podcast_id=' + podcastId;
        document.getElementById('s2DoneBarGrid').style.display = 'flex';
        
        const modeLabel = isTalkingHead ? 'Talking Head' : 'Podcast';
        s2Log(`🎉 ${modeLabel} done! #${podcastId} — ${sceneDone}/${dbScenes.length} scenes OK`, 'success');
        showToast(`✅ ${modeLabel} video ready — #${podcastId}`);
        return;
    }
    
    // =========================================================================
    // STANDARD / B-ROLL FLOW — Scene-by-scene parallel build
    // =========================================================================

    const rawLines = isBroll
        ? [rawForParse]
        : rawForParse.split('\n').map(l => l.trim()).filter(Boolean);

    const sceneDurations = rawLines.map(line => {
        const cleanText = line.replace(/<[^>]*>/g, '').trim();
        const wordCount = cleanText.split(/\s+/).filter(Boolean).length;
        return Math.max(3, Math.round((wordCount / 130) * 60));
    });

    const niche      = wizAnsForParse.niche || 'professional';
    const titleStr   = wizAnsForParse.title || 'Video';
    const total      = rawLines.length;
    const durationMap = {'15 seconds':15,'30 seconds':30,'60 seconds':60,'90 seconds':90};
    const totalSecs  = durationMap[wizAnsForParse.duration] || 60;
    const defaultDur = Math.max(4, Math.round(totalSecs / total));

    // ── Draw empty 9x16 placeholder boxes ────────────────────────────────────
    const boxesWrap = document.getElementById('s2SceneBoxes');
    boxesWrap.innerHTML = '';
    rawLines.forEach((_, i) => {
        const box = document.createElement('div');
        box.id    = `scene-box-${i}`;
        box.style.cssText = [
            'position:relative',
            'width:52px',
            'height:92px',  // 9x16 ratio
            'background:#0f2744',
            'border:1.5px solid #1e3a5f',
            'border-radius:6px',
            'overflow:hidden',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'flex-shrink:0',
        ].join(';');
        box.innerHTML = `
            <div id="scene-box-inner-${i}" style="text-align:center;">
              <div style="font-size:9px;color:#4fc3f7;font-weight:700;">${i + 1}</div>
              <div style="font-size:14px;margin-top:2px;">⏳</div>
            </div>`;
        boxesWrap.appendChild(box);
    });

    s2Log(`📦 ${rawLines.length} scene boxes ready — starting parallel build…`, 'info');
    s2StepStatus(0, 'active', `Building ${rawLines.length} scenes in parallel…`);
    updateSpinnerStep('Building scenes simultaneously…');

    // ── Helper: update a scene box ────────────────────────────────────────────
    function updateSceneBox(i, state, mediaFile) {
        const inner = document.getElementById(`scene-box-inner-${i}`);
        const box   = document.getElementById(`scene-box-${i}`);
        if (!inner || !box) return;
        if (state === 'enhancing') {
            inner.innerHTML = `<div style="font-size:9px;color:#4fc3f7;font-weight:700;">${i+1}</div><div style="font-size:11px;margin-top:2px;">🤖</div>`;
            box.style.borderColor = '#4fc3f7';
        } else if (state === 'media') {
            // Show image/video thumbnail
            const isVideo = mediaFile && /\.(mp4|webm|mov)$/i.test(mediaFile);
            const folder  = isVideo ? 'podcast_videos' : 'podcast_images';
            box.style.border = '2px solid #22c55e';
            if (mediaFile) {
                if (isVideo) {
                    box.innerHTML = `
                        <video src="${folder}/${mediaFile}" style="width:100%;height:100%;object-fit:cover;" muted playsinline loop></video>
                        <div style="position:absolute;bottom:2px;left:0;right:0;text-align:center;font-size:8px;color:#fff;background:rgba(0,0,0,0.5);padding:1px;">${i+1} ✓</div>`;
                } else {
                    box.innerHTML = `
                        <img src="${folder}/${mediaFile}" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'">
                        <div style="position:absolute;bottom:2px;left:0;right:0;text-align:center;font-size:8px;color:#fff;background:rgba(0,0,0,0.5);padding:1px;">${i+1} ✓</div>`;
                }
            }
        } else if (state === 'audio') {
            // Add audio indicator overlay
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:absolute;top:2px;right:2px;font-size:9px;background:rgba(0,0,0,0.6);border-radius:3px;padding:1px 3px;color:#4fc3f7;';
            overlay.textContent = '🔊';
            box.appendChild(overlay);
        } else if (state === 'done') {
            box.style.borderColor = '#22c55e';
            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:absolute;top:2px;left:2px;font-size:9px;background:rgba(34,197,94,0.8);border-radius:3px;padding:1px 3px;color:#fff;';
            overlay.textContent = '✓';
            box.appendChild(overlay);
        } else if (state === 'error') {
            inner.innerHTML = `<div style="font-size:9px;color:#ef4444;font-weight:700;">${i+1}</div><div style="font-size:11px;">✗</div>`;
            box.style.borderColor = '#ef4444';
        }
    }

    // =========================================================================
    // PARALLEL PER-SCENE PIPELINE
    // Phase 1 (serial): podcast record → voice → create_scenes_from_podcast
    //                   → get_scenes  (need DB ids before anything else)
    // Phase 2 (parallel, one Promise per scene):
    //   enhance prompt → sync tags → search/assign media → generate audio
    //   Each scene proceeds independently the moment its enhancement returns.
    //   Scene boxes fill in live as each scene completes.
    // =========================================================================

    const IMAGE_FIELDS = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    let _parallelSec = '0.0';

    // ── PHASE 1 — serial setup ────────────────────────────────────────────────
    s2StepStatus(0, 'active', 'Setting up podcast…');
    s2StepStatus(1, 'active', 'Setting up podcast…');
    updateSpinnerStep('Setting up podcast…');
    const _t1 = performance.now();

    // 1a. Get or create podcast record
    let podcastId = s2Source === 'wizard'  ? window._wizPodcastId
                  : s2Source === 'content' ? window._contentPodcastId
                  : null;

    if ((s2Source === 'wizard' || s2Source === 'content') && !podcastId) {
        const dataToSave = s2Source === 'wizard'
            ? (window._wizData || Object.assign({}, window._wizAns || ans, settings))
            : (window._contentData || { niche:'custom', title: document.getElementById('content-title')?.value.trim() || 'My Video' });
        s2Log('💾 Creating podcast record…', 'info');
        try {
            const r = await fetch('save_script_to_db.php', {
                method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
                body: JSON.stringify({ script: rawForParse.replace(/<break[^>]*>/gi,'').trim(), data: dataToSave })
            });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'Save failed');
            podcastId = d.podcast_id;
            if (s2Source === 'wizard')  window._wizPodcastId     = podcastId;
            if (s2Source === 'content') window._contentPodcastId = podcastId;
            s2Log(`✅ Podcast #${podcastId} created`, 'success');
            if (editedScript && editedScript !== rawForParse) {
                s2Log('📝 Saving edited script…', 'info');
                await saveEditedScriptToPodcast(podcastId, editedScript);
                rawForParse = editedScript;
            }
        } catch (e) {
            s2StepStatus(1, 'error', 'Failed: ' + e.message);
            s2Log('❌ ' + e.message, 'error');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner(); return;
        }
    } else if (podcastId) {
        s2Log(`✅ Reusing podcast #${podcastId}`, 'success');
        if (editedScript) {
            s2Log('📝 Saving edited script…', 'info');
            await saveEditedScriptToPodcast(podcastId, editedScript);
            rawForParse = editedScript;
        }
    }

    if (!podcastId) {
        s2StepStatus(1, 'error', 'No podcast ID'); s2Log('❌ No podcast_id.', 'error');
        document.getElementById('s2CloseBtn').style.display = 'inline';
        hideProcessingSpinner(); return;
    }
    s2PodcastId = podcastId;

    // 1b. Save voice
    try {
        const vFd = new FormData();
        vFd.append('action','update_podcast_voice'); vFd.append('podcast_id',podcastId);
        vFd.append('host_voice',hostVoice); vFd.append('guest_voice',''); vFd.append('rate',rate);
        const vd = await (await fetch(S2_ENDPOINT,{method:'POST',body:vFd,credentials:'include'})).json();
        s2Log(vd.success ? `✅ Voice saved — ${hostVoice}` : `⚠ Voice: ${vd.error}`, vd.success?'success':'warning');
    } catch(e){ s2Log(`⚠ Voice error: ${e.message}`,'warning'); }

    if (s2Cancelled) { s2Log('⏹ Cancelled','warning'); hideProcessingSpinner(); return; }

    // 1c. Create scene rows in DB
    s2Log('📝 Creating scenes from script…','info');
    updateSpinnerStep('Creating scenes…');
    try {
        const cFd = new FormData();
        cFd.append('action','create_scenes_from_podcast'); cFd.append('podcast_id',podcastId);
        cFd.append('host_voice',hostVoice); cFd.append('guest_voice',guestVoice||hostVoice);
        cFd.append('rate',rate); cFd.append('lang_code',langCode);
        cFd.append('is_broll',isBroll?'1':'0');
        cFd.append('ai_group',    ans.industry_desc || '');
        cFd.append('ai_subgroup', ans.niche || ans.industry_desc || '');
        const cd = await s2SafeFetch(S2_ENDPOINT,{method:'POST',body:cFd,credentials:'include'});
        if (!cd || !cd.success) throw new Error((cd&&cd.error)||'Scene creation failed');
        s2Log(`✅ ${cd.scene_count} scenes created`,'success');
    } catch(e) {
        s2StepStatus(1,'error','Scene creation failed: '+e.message);
        s2Log('❌ '+e.message,'error');
        document.getElementById('s2CloseBtn').style.display='inline';
        hideProcessingSpinner(); return;
    }

    await initCaptionsFromSettings(podcastId);

    // 1d. Fetch DB scene rows — we need scene.id per scene before firing parallel work
    updateSpinnerStep('Fetching scene list…');
    const gFd = new FormData();
    gFd.append('action','get_scenes'); gFd.append('podcast_id',podcastId);
    let dbScenes = [];
    try {
        const gd = await s2SafeFetch(S2_ENDPOINT,{method:'POST',body:gFd,credentials:'include'});
        dbScenes = Array.isArray(gd) ? gd : [];
        // Fix missing durations
        dbScenes = dbScenes.map(sc => {
            if ((parseInt(sc.duration)||0) > 0) return sc;
            const wc = (sc.text_contents||'').replace(/<[^>]*>/g,'').trim().split(/\s+/).filter(Boolean).length;
            return {...sc, duration: Math.max(3, Math.round((wc/130)*60))};
        });
        s2Log(`📋 ${dbScenes.length} scenes fetched`,'info');
    } catch(e){ s2Log('⚠ get_scenes: '+e.message,'warning'); }

    if (!dbScenes.length) {
        s2StepStatus(1,'error','No scenes returned from DB');
        s2Log('❌ get_scenes returned nothing','error');
        document.getElementById('s2CloseBtn').style.display='inline';
        hideProcessingSpinner(); return;
    }

    _step1Sec = ((performance.now()-_t1)/1000).toFixed(1);
    s2StepStatus(1,'done',`✓ Podcast #${podcastId} ready (⏱ ${_step1Sec}s)`);

    // ── PHASE 2 — per-scene parallel pipeline ────────────────────────────────
    // All scenes are launched at once. Each scene independently chains:
    //   enhance → sync tags → search/assign media → audio
    // Boxes fill as each scene completes. No global waits between steps.
    // ─────────────────────────────────────────────────────────────────────────
    s2StepStatus(2, 'active', `${rawLines.length} scenes running…`);
    s2StepStatus(3, 'active', `${rawLines.length} scenes running…`);
    updateSpinnerStep(`All ${rawLines.length} scenes in parallel…`);
    s2Log(`🚀 ${rawLines.length} scenes launched in parallel (enhance→tags→media→audio)…`,'info');

    let mediaDone=0, mediaFail=0, audioDone=0, audioFail=0;
    const usedFilenames = new Set(); // cross-scene dedup — MUST be sequential
    const _t2 = performance.now();

    // ── Audio: fire ALL scenes in parallel immediately ──────────────────────
    s2Log(`🔊 Starting audio for all ${rawLines.length} scenes in parallel…`,'info');
    const audioPromises = rawLines.map((line, i) => {
        const dbScene = dbScenes[i];
        if (!dbScene || !dbScene.id) return Promise.resolve();
        const ttsText = (dbScene.text_contents||'').replace(/<break[^>]*>/gi,'').trim();
        if (!ttsText) { audioDone++; return Promise.resolve(); }
        const aFd = new FormData();
        aFd.append('action','generate_scene_audio'); aFd.append('scene_id',dbScene.id);
        aFd.append('podcast_id',podcastId); aFd.append('seq_no',i+1);
        aFd.append('lang_code',langCode);   aFd.append('voice_id',hostVoice);
        aFd.append('rate',rate);            aFd.append('text',ttsText);
        return fetch(S2_ENDPOINT,{method:'POST',body:aFd,credentials:'include'})
            .then(r=>r.json())
            .then(ad=>{
                if(ad.success){ audioDone++; updateSceneBox(i,'audio'); s2Log(`✓ Scene ${i+1} audio OK`,'success'); }
                else           { audioFail++; s2Log(`✗ Scene ${i+1} audio: ${ad.error}`,'error'); }
            }).catch(e=>{ audioFail++; s2Log(`✗ Scene ${i+1} audio: ${e.message}`,'error'); });
    });

    // ── Phase A: Enhance ALL scenes in parallel ────────────────────────────
    s2Log(`✨ Enhancing all ${rawLines.length} scenes in parallel…`,'info');
    rawLines.forEach((_,i) => updateSceneBox(i,'enhancing'));
    const enhanceResults = await Promise.all(rawLines.map(async (line, i) => {
        const seqNo = i+1;
        const dbScene = dbScenes[i];
        if (!dbScene || !dbScene.id) return null;
        try {
            if (isBroll) return parseWizardScenesBasic(line,{niche})[0];
            const dur = sceneDurations[i] || defaultDur;
            const sd = await enhanceSceneWithAI(line, niche, titleStr, i, total, dur);
            if (sd._fallback) s2Log(`⚠ Scene ${seqNo} AI fallback`,'warning');
            else s2Log(`✓ Scene ${seqNo}/${total} enhanced`,'success');
            return sd;
        } catch(e) {
            s2Log(`⚠ Scene ${seqNo} enhance failed — using basic`,'warning');
            return parseWizardScenesBasic(line,{niche})[0];
        }
    }));
    enhanceResults.forEach((sd,i) => { if (sd) scenes[i] = sd; });

    // Sync all tags to DB in parallel (fire-and-forget)
    enhanceResults.forEach((sd, i) => {
        const dbScene = dbScenes[i];
        if (!sd || !dbScene || !dbScene.id) return;
        const prompts = sd.prompts || [sd.prompt||''];
        const tFd = new FormData();
        tFd.append('action','update_scene_tags'); tFd.append('scene_id',dbScene.id);
        tFd.append('hashtags',sd.hashtags||''); tFd.append('nl_tags',sd.nl_tags||'');
        tFd.append('prompt',prompts[0]||''); tFd.append('video_prompt',sd.video_prompt||'');
        tFd.append('prompt_1',prompts[1]||''); tFd.append('prompt_2',prompts[2]||'');
        tFd.append('prompt_3',prompts[3]||''); tFd.append('prompt_4',prompts[4]||'');
        fetch(S2_ENDPOINT,{method:'POST',body:tFd,credentials:'include'}).catch(()=>{});
    });

    // ── Phase B: Media assignment sequential (usedFilenames always current) ──
    s2Log(`🖼 Assigning media sequentially to prevent duplicates…`,'info');
    for (let i = 0; i < rawLines.length; i++) {
        const seqNo = i+1;
        const dbScene = dbScenes[i];
        const sd = enhanceResults[i];

        if (!dbScene || !dbScene.id) {
            s2Log(`⚠ Scene ${seqNo}: no DB row — skipped`,'warning');
            updateSceneBox(i,'error'); mediaFail++; continue;
        }

        // ── Step C: media search/assign ──────────────────────────────────────
        await (async () => {
        const nlTags    = (sd.nl_tags  || '').trim();
        const hashtags  = (sd.hashtags || '').trim();
        const sceneText = (dbScene.text_contents||'').replace(/<break[^>]*>/gi,'').replace(/<[^>]*>/g,'').trim();
        const mediaQ    = nlTags || hashtags || sceneText;

        if (s2MediaType === 'unique_images') {
            // AI-generated images per slot
            const prompts = sd.prompts || [sd.prompt || dbScene.text_contents || ''];
            let imgDone = 0;
            for (let p=0; p<5 && p<prompts.length; p++) {
                try {
                    s2Log(`🤖 Scene ${seqNo} img ${p+1}/${Math.min(5,prompts.length)}…`,'info');
                    const imgFd = new FormData();
                    imgFd.append('prompt',      prompts[p]||prompts[0]||'');
                    imgFd.append('scene_id',    dbScene.id);
                    imgFd.append('podcast_id',  podcastId);
                    imgFd.append('image_field', IMAGE_FIELDS[p]);
                    const d = JSON.parse(await (await fetch('wizard_image_gen.php',{method:'POST',body:imgFd,credentials:'include'})).text());
                    if (d.success && d.filename) {
                        const aFd = new FormData();
                        aFd.append('action','assign_image'); aFd.append('scene_id',dbScene.id);
                        aFd.append('podcast_id',podcastId); aFd.append('filename',d.filename);
                        aFd.append('image_field',IMAGE_FIELDS[p]); aFd.append('media_type','image');
                        aFd.append('search_query',mediaQ); aFd.append('similarity_score','0.95');
                        aFd.append('match_rank',(p+1).toString()); aFd.append('matched_terms',JSON.stringify(['ai_generated']));
                        await fetch(S2_ENDPOINT,{method:'POST',body:aFd,credentials:'include'}).catch(()=>{});
                        imgDone++;
                        if (p===0) updateSceneBox(i,'media',d.filename);
                        s2Log(`✓ Scene ${seqNo} slot ${p+1}: ${d.filename}`,'success');
                    } else {
                        s2Log(`✗ Scene ${seqNo} slot ${p+1}: ${d.message||'failed'}`,'error');
                    }
                } catch(e){ s2Log(`✗ Scene ${seqNo} slot ${p+1}: ${e.message}`,'error'); }
            }
            if (imgDone>0){ mediaDone++; updateSceneBox(i,'done'); }
            else           { mediaFail++; updateSceneBox(i,'error'); s2Log(`✗ Scene ${seqNo}: all slots failed`,'error'); }

        } else {
            // Stock media — one search call per scene (already running in parallel so no batch needed)
            try {
                const sFd = new FormData();
                sFd.append('action','search_images_batch'); sFd.append('podcast_id',podcastId);
                sFd.append('slots',1); sFd.append('media_type',s2MediaType);
                sFd.append('exclude_files', JSON.stringify([...usedFilenames]));
                sFd.append('admin_id',     <?= (int)$_SESSION['admin_id'] ?>);
                sFd.append('team_lead_id', <?= (int)($_SESSION['team_lead_id'] ?? 0) ?>);
                sFd.append('scenes', JSON.stringify([{scene_idx:i, scene_id:dbScene.id, nl_tags:nlTags, query:mediaQ}]));
                const sRes = JSON.parse(await (await fetch(S2_ENDPOINT,{method:'POST',body:sFd,credentials:'include'})).text());
                const found = sRes.results?.[0]?.found || [];

                if (found.length > 0) {
                    // Pick first result NOT already used in this podcast
                    const best = found.find(item => !usedFilenames.has(item.filename)) || found[0];
                    const iType = best.type||(s2MediaType==='stock_videos'?'video':'image');
                    const aFd = new FormData();
                    aFd.append('action','assign_image'); aFd.append('scene_id',dbScene.id);
                    aFd.append('podcast_id',podcastId);  aFd.append('filename',best.filename);
                    aFd.append('image_field','image_file'); aFd.append('media_type',iType);
                    aFd.append('search_query',mediaQ); aFd.append('similarity_score',best.score||0);
                    aFd.append('match_rank',best.rank||1); aFd.append('matched_terms',best.matched_terms||'[]');
                    await fetch(S2_ENDPOINT,{method:'POST',body:aFd,credentials:'include'}).catch(()=>{});
                    usedFilenames.add(best.filename);
                    updateSceneBox(i,'media',best.filename);
                    mediaDone++; updateSceneBox(i,'done');
                    s2Log(`✓ Scene ${seqNo}: ${best.filename} [${iType}]`,'success');

                } else {
                    // AI fallback
                    s2Log(`⚠ Scene ${seqNo}: no stock media — AI fallback…`,'warning');
                    const fbPrompts = sd.prompts||[sd.prompt||dbScene.text_contents||''];
                    try {
                        const fbFd = new FormData();
                        fbFd.append('prompt',fbPrompts[0]||dbScene.text_contents||'');
                        fbFd.append('scene_id',dbScene.id); fbFd.append('podcast_id',podcastId);
                        fbFd.append('image_field',IMAGE_FIELDS[0]);
                        const fbD = JSON.parse(await (await fetch('wizard_image_gen.php',{method:'POST',body:fbFd,credentials:'include'})).text());
                        if (fbD.success && fbD.filename) {
                            const aFd = new FormData();
                            aFd.append('action','assign_image'); aFd.append('scene_id',dbScene.id);
                            aFd.append('podcast_id',podcastId); aFd.append('filename',fbD.filename);
                            aFd.append('image_field',IMAGE_FIELDS[0]); aFd.append('media_type','image');
                            aFd.append('search_query',mediaQ); aFd.append('similarity_score','0.90');
                            aFd.append('match_rank','1'); aFd.append('matched_terms',JSON.stringify(['ai_fallback']));
                            await fetch(S2_ENDPOINT,{method:'POST',body:aFd,credentials:'include'}).catch(()=>{});
                            mediaDone++; updateSceneBox(i,'media',fbD.filename); updateSceneBox(i,'done');
                            s2Log(`✓ Scene ${seqNo}: fallback ${fbD.filename}`,'success');
                        } else {
                            mediaFail++; updateSceneBox(i,'error');
                            s2Log(`✗ Scene ${seqNo}: fallback failed — ${fbD.message||'unknown'}`,'error');
                        }
                    } catch(e){ mediaFail++; updateSceneBox(i,'error'); s2Log(`✗ Scene ${seqNo} fallback: ${e.message}`,'error'); }
                }
            } catch(e){ mediaFail++; updateSceneBox(i,'error'); s2Log(`✗ Scene ${seqNo} media: ${e.message}`,'error'); }
        }

        })(); // end sequential media work

    } // end sequential media loop

    // Now wait for all audio to finish
    await Promise.all(audioPromises);

    _parallelSec = ((performance.now()-_t2)/1000).toFixed(1);
    s2StepStatus(2, audioFail>0?'error':'done',
        `✓ ${audioDone} audio${audioFail>0?` (${audioFail} failed)`:''} (⏱ ${_parallelSec}s)`);
    s2StepStatus(3, mediaFail===dbScenes.length?'error':'done',
        `✓ ${mediaDone} media assigned (⏱ ${_parallelSec}s)`);
    s2Log(`⏱ Parallel pipeline: ${_parallelSec}s`,'info');

    if (s2Source==='wizard'||s2Source==='content') await updatePodcastThumbnail(podcastId);

    hideProcessingSpinner();
    document.getElementById('s2CloseBtn').style.display = 'inline';
    document.getElementById('s2VideoLink').href         = 'videomaker.php?podcast_id=' + podcastId;
    document.getElementById('s2DoneBarGrid').style.display = 'flex';

    const _tot = ((performance.now()-_buildStart)/1000).toFixed(1);
    s2Log(`━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`,'info');
    s2Log(`⏱ BUILD SUMMARY — Podcast #${podcastId}`,'info');
    s2Log(`   📝 Setup (serial)       : ${_step1Sec}s`,'info');
    s2Log(`   🚀 Parallel pipeline    : ${_parallelSec}s`,'info');
    s2Log(`   ─────────────────────────────────────────`,'info');
    s2Log(`   🏁 TOTAL                : ${_tot}s`,'success');
    s2Log(`━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`,'info');
    s2Log('🎉 All done! Podcast #'+podcastId,'success');
    showToast('✅ Video ready — Podcast #'+podcastId);

} // END startBuildVideo()



// Safe fetch wrapper — never throws on HTTP errors, always returns parsed JSON or null
async function s2SafeFetch(url, options) {
    try {
        const resp = await fetch(url, options);
        const text = await resp.text();
        if (!resp.ok) {
            s2Log('⚠ Server returned HTTP ' + resp.status + ' — continuing', 'warning');
            return null;
        }
        if (!text || !text.trim()) return null;
        try { return JSON.parse(text); } catch(e) { return null; }
    } catch(e) {
        s2Log('⚠ Network error: ' + e.message + ' — continuing', 'warning');
        return null;
    }
}

async function saveEditedScriptToPodcast(podcastId, scriptText) {
    try {
        const fd = new FormData();
        fd.append('action', 'save_edited_script');
        fd.append('podcast_id', podcastId);
        fd.append('script', scriptText);
        
        const response = await fetch(getS2Endpoint(), {
            method: 'POST',
            body: fd,
            credentials: 'include'
        });

        if (!response.ok) {
            s2Log('⚠ Server error saving script (HTTP ' + response.status + ') — continuing anyway', 'warning');
            return false;
        }

        const text = await response.text();
        if (!text || !text.trim()) {
            s2Log('⚠ Empty response saving script — continuing anyway', 'warning');
            return false;
        }

        let data;
        try { data = JSON.parse(text); }
        catch(e) {
            s2Log('⚠ Could not parse save response — continuing anyway', 'warning');
            return false;
        }

        if (data.success) {
            s2Log('✓ Edited script saved to database', 'success');
            return true;
        } else {
            s2Log('⚠ Could not save edited script: ' + (data.error || 'unknown'), 'warning');
            return false;
        }
    } catch (e) {
        s2Log('⚠ Error saving edited script: ' + e.message + ' — continuing anyway', 'warning');
        return false;
    }
}

// ── Apply user_settings animation_style/speed to all captions of a new video ─
async function initCaptionsFromSettings(podcastId) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'init_captions_from_settings');
        fd.append('podcast_id',  podcastId);
        const r    = await fetch(location.href, { method: 'POST', body: fd, credentials: 'include' });
        const data = await r.json();
        if (data.success && data.updated > 0) {
            s2Log(`✅ Caption animation applied — style: ${data.animation_style}, speed: ${data.animation_speed} (${data.updated} captions)`, 'success');
        } else if (data.success) {
            s2Log('ℹ No custom caption animation in user settings — using defaults', 'info');
        } else {
            s2Log('⚠ Could not apply caption settings: ' + (data.message || 'unknown'), 'warning');
        }
    } catch (e) {
        s2Log('⚠ initCaptionsFromSettings error: ' + e.message, 'warning');
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// CAMPAIGN MODE ENGINE
// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
// CAMPAIGN MODE ENGINE
// ═════════════════════════════════════════════════════════════════════════════

let campSelectedIdeas = [];
let campIdeasPage     = 1;
let campIdeasLoading  = false;
let campIdeasHasMore  = false;
let campCreditBalance = 0;

// ── Initialise campaign mode ──────────────────────────────────────────────────
async function campInitMode() {
    campRenderBusinessBar();
    campRenderVideoBar();

    const ctx = document.getElementById('camp-context-pills');
    if (ctx) {
        ctx.innerHTML = [coIndustry.group, coIndustry.subgroup, coIndustry.niche]
            .filter(Boolean)
            .map((p,i,a) =>
                `<span class="ideas-ctx-pill">${escHtml(p)}</span>${i<a.length-1?'<span class="ideas-ctx-sep">›</span>':''}`
            ).join('');
    }
    const sub = document.getElementById('camp-subtitle');
    if (sub) sub.textContent = coIndustry.niche
        ? `Showing ideas for ${coIndustry.niche}`
        : 'Select videos to add to your campaign';

    campSelectedIdeas = [];
    campIdeasPage     = 1;
    campIdeasHasMore  = false;
    campUpdateCounter();

    const res = document.getElementById('camp-results');
    if (res) res.innerHTML = '';

    const list = document.getElementById('camp-chip-list');
    if (list) list.innerHTML = '<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Finding video ideas…</span></div>';
    const mb = document.getElementById('camp-load-more-btn');
    if (mb) mb.style.display = 'none';

    // Load credit balance
    try {
        const d = await post({ ajax_action:'get_video_quota' });
        if (d.success) campCreditBalance = d.credit_balance ?? 0;
    } catch(e) {}

    await campFetchIdeas(1, true);

    if (!coIndustry.group && !coIndustry.subgroup && !coIndustry.niche) {
        if (list) list.innerHTML = `<div class="ideas-empty"><div class="ideas-empty-icon">🏢</div>
            <div style="margin-bottom:12px;">Set your business profile to get personalised ideas</div>
            <button onclick="openBusinessSettings()" style="padding:10px 20px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">Set Business Profile →</button></div>`;
    }
}

function campRenderBusinessBar() {
    const pills = [coIndustry.group, coIndustry.subgroup, coIndustry.niche].filter(Boolean);
    const html  = pills.length
        ? pills.map(v => `<span class="s-pill">${escHtml(v)}</span>`).join('')
        : '<span style="font-size:11px;color:#aaa;font-style:italic;">Not set</span>';
    const el = document.getElementById('camp-business-pills');
    if (el) el.innerHTML = html;
}

function campRenderVideoBar() {
    const pillMap = [
        { val: settings.language },
        { val: (settings.reel_type || '').split(' (')[0] },
        { val: settings.content_goals },
        { val: settings.growth_goals },
        { val: settings.audience },
        { val: settings.tone },
    ];
    const html = pillMap.filter(p => p.val).map(p =>
        `<span class="s-pill">${escHtml(p.val)}</span>`
    ).join('');
    const el = document.getElementById('camp-video-pills');
    if (el) el.innerHTML = html;
}

async function campFetchIdeas(page, replace) {
    if (campIdeasLoading) return;
    campIdeasLoading = true;
    try {
        const d = await post({ ajax_action:'get_company_video_ideas', niche_name:coIndustry.niche||'', industry:coIndustry.subgroup||'', page:page });
        if (!d.success) throw new Error('Failed');
        campIdeasHasMore = d.has_more || false;
        campIdeasPage    = page;
        const list = document.getElementById('camp-chip-list');
        if (replace) list.innerHTML = '';
        if (!d.ideas || (d.ideas.length === 0 && page === 1)) {
            campIdeasLoading = false;
            await campGenerateAiIdeas(false);
            return;
        }
        d.ideas.forEach(idea => campAppendChip(list, idea));
        const mb = document.getElementById('camp-load-more-btn');
        if (mb) { mb.style.display = d.has_more ? '' : 'none'; mb.disabled = false; mb.textContent = '+ More Ideas'; }
    } catch(e) {
        const list = document.getElementById('camp-chip-list');
        if (list) list.innerHTML = `<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div><div>Could not load ideas: ${e.message}</div></div>`;
    } finally { campIdeasLoading = false; }
}

async function campGenerateAiIdeas(append) {
    const list = document.getElementById('camp-chip-list');
    if (!list) return;
    const mb = document.getElementById('camp-load-more-btn');
    if (mb) { mb.disabled = true; mb.textContent = 'Loading...'; mb.style.display = ''; }
    const spinner = document.createElement('div');
    spinner.id = 'camp-ai-spinner'; spinner.className = 'loading';
    spinner.innerHTML = '<div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating ideas…</span>';
    if (!append) list.innerHTML = '';
    list.appendChild(spinner);
    try {
        const d = await post({ ajax_action:'generate_company_video_ideas', group:coIndustry.group||'', subgroup:coIndustry.subgroup||'', niche:coIndustry.niche||'' });
        const sp = document.getElementById('camp-ai-spinner'); if (sp) sp.remove();
        if (d.success && d.ideas && d.ideas.length > 0) {
            d.ideas.forEach(idea => campAppendChip(list, idea));
            if (mb) { mb.style.display = ''; mb.textContent = '+ More Ideas'; mb.disabled = false; }
        } else {
            if (!append) list.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">💡</div><div>No ideas found. Try refreshing your business profile.</div></div>';
            if (mb) mb.style.display = 'none';
        }
    } catch(e) {
        const sp = document.getElementById('camp-ai-spinner'); if (sp) sp.remove();
        if (!append) list.innerHTML = '<div class="ideas-empty"><div class="ideas-empty-icon">⚠️</div><div>Error. Please try again.</div></div>';
        if (mb) mb.style.display = 'none';
    }
}

function campAppendChip(list, idea) {
    const chip = document.createElement('div');
    chip.className = 'idea-chip camp-chip';
    chip.dataset.idea = idea;
    chip.innerHTML = `
        <div class="camp-checkbox">&#10003;</div>
        <span class="idea-chip-text">${escHtml(idea)}</span>`;
    chip.onclick = () => campToggleChip(chip, idea);
    list.appendChild(chip);
}

function campToggleChip(chip, idea) {
    const idx = campSelectedIdeas.indexOf(idea);
    if (idx === -1) {
        campSelectedIdeas.push(idea);
        chip.classList.add('checked');
    } else {
        campSelectedIdeas.splice(idx, 1);
        chip.classList.remove('checked');
    }
    campUpdateCounter();
}

function campSelectAll() {
    document.querySelectorAll('#camp-chip-list .camp-chip').forEach(chip => {
        const idea = chip.dataset.idea;
        if (!campSelectedIdeas.includes(idea)) campSelectedIdeas.push(idea);
        chip.classList.add('checked');
    });
    campUpdateCounter();
}

function campClearAll() {
    campSelectedIdeas = [];
    document.querySelectorAll('#camp-chip-list .camp-chip').forEach(chip => chip.classList.remove('checked'));
    campUpdateCounter();
}

async function campLoadMoreIdeas() {
    const btn = document.getElementById('camp-load-more-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }
    await campGenerateAiIdeas(true);
}

function campUpdateCounter() {
    const n = campSelectedIdeas.length;
    const counterBar   = document.getElementById('camp-counter-bar');
    const selCount     = document.getElementById('camp-sel-count');
    const selPlural    = document.getElementById('camp-sel-plural');
    const genBtn       = document.getElementById('camp-generate-btn');
    const genCount     = document.getElementById('camp-gen-count');
    const creditBar    = document.getElementById('camp-credit-bar');
    const creditNeeded = document.getElementById('camp-credit-needed');
    const creditBal    = document.getElementById('camp-credit-balance');
    const creditWarn   = document.getElementById('camp-credit-warn');
    const schedSection = document.getElementById('camp-schedule-section');

    if (counterBar)   counterBar.style.display   = n > 0 ? 'flex'  : 'none';
    if (schedSection) schedSection.style.display  = n > 0 ? 'block' : 'none';
    if (selCount)     selCount.textContent  = n;
    if (selPlural)    selPlural.textContent = n === 1 ? '' : 's';
    if (genCount)     genCount.textContent  = n;

    // Set default start date to tomorrow if not set
    const startInput = document.getElementById('camp-start-date');
    if (startInput) {
        const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
        const tomorrowStr = tomorrow.toISOString().split('T')[0];
        startInput.min = tomorrowStr;   // prevent picking today or past
        if (!startInput.value) {
            startInput.value = tomorrowStr;
            campUpdateSchedulePreview();
        }
    }

    // Credits: 1 credit per video (always Standard)
    const creditsNeeded = n;
    const hasEnough     = campCreditBalance >= creditsNeeded;

    if (creditBar)    creditBar.style.display    = n > 0 ? 'block' : 'none';
    if (creditNeeded) creditNeeded.textContent   = creditsNeeded;
    if (creditBal)    creditBal.textContent       = campCreditBalance;
    if (creditWarn)   creditWarn.style.display    = (n > 0 && !hasEnough) ? '' : 'none';

    const canMake = Math.min(campCreditBalance, n);

    if (genBtn) {
        genBtn.disabled = n === 0;
        genBtn.style.opacity = n > 0 ? '1' : '.5';
        if (n > 0 && !hasEnough && campCreditBalance > 0) {
            genBtn.textContent = `📅 Create ${canMake} Videos (${campCreditBalance} credit${campCreditBalance!==1?'s':''} available)`;
        } else if (n > 0 && campCreditBalance === 0) {
            genBtn.textContent = '🔒 No Credits — Upgrade to Create Campaign';
            genBtn.disabled = true;
            genBtn.style.opacity = '.5';
        } else {
            genBtn.textContent = `📅 Create Campaign — ${n} Video${n!==1?'s':''}`;
        }
    }
}

// ── Schedule preview ──────────────────────────────────────────────────────────
function campUpdateSchedulePreview() {
    const startInput = document.getElementById('camp-start-date');
    const freqSel    = document.getElementById('camp-frequency');
    const preview    = document.getElementById('camp-schedule-preview');
    if (!startInput || !freqSel || !preview) return;
    const startVal = startInput.value;
    const freqDays = parseInt(freqSel.value) || 1;
    const n        = campSelectedIdeas.length;
    if (!startVal || n === 0) { preview.style.display = 'none'; return; }

    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const lines = [];
    const maxShow = Math.min(n, 4);
    for (let i = 0; i < maxShow; i++) {
        const d = new Date(startVal + 'T09:00:00');
        d.setDate(d.getDate() + i * freqDays);
        const label = `${dayNames[d.getDay()]} ${d.getDate()} ${monthNames[d.getMonth()]}`;
        const title = campSelectedIdeas[i] ? ' — ' + campSelectedIdeas[i].substring(0, 35) + (campSelectedIdeas[i].length > 35 ? '…' : '') : '';
        lines.push(`<span style="display:inline-block;background:#ede9fe;color:#5b21b6;border-radius:4px;padding:2px 8px;font-weight:600;margin-right:6px;">${label}</span>${escHtml(title)}`);
    }
    if (n > maxShow) lines.push(`<span style="color:#94a3b8;">…and ${n - maxShow} more</span>`);

    preview.innerHTML = lines.map(l => `<div style="padding:3px 0;">${l}</div>`).join('');
    preview.style.display = 'block';
}

// ── Confirm credit use then create campaign ───────────────────────────────────
async function campConfirmAndCreate() {
    const n = campSelectedIdeas.length;
    if (n === 0) return;

    const startInput = document.getElementById('camp-start-date');
    const freqSel    = document.getElementById('camp-frequency');
    const _tmrw = new Date(); _tmrw.setDate(_tmrw.getDate()+1);
    const startDate  = startInput ? startInput.value : _tmrw.toISOString().split('T')[0];
    const freqDays   = freqSel ? parseInt(freqSel.value) || 1 : 1;
    const freqLabel  = freqSel ? freqSel.options[freqSel.selectedIndex].text : 'Daily';

    if (!startDate) { alert('Please select a start date.'); startInput && startInput.focus(); return; }

    const canMake   = Math.min(campCreditBalance, n);
    const hasEnough = campCreditBalance >= n;
    const ideasToCreate = hasEnough ? campSelectedIdeas : campSelectedIdeas.slice(0, canMake);

    if (canMake === 0) { showToast('No credits available. Please upgrade.'); return; }

    // Calculate end date
    const endTs    = new Date(startDate + 'T09:00:00');
    endTs.setDate(endTs.getDate() + (ideasToCreate.length - 1) * freqDays);
    const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const endLabel = `${endTs.getDate()} ${monthNames[endTs.getMonth()]} ${endTs.getFullYear()}`;

    let msg = `Create campaign with ${ideasToCreate.length} video${ideasToCreate.length!==1?'s':''}?\n\n`;
    msg += `📅 Start: ${startDate}   Frequency: ${freqLabel}\n`;
    msg += `📅 Last video scheduled: ${endLabel}\n`;
    msg += `💳 Credits: ${ideasToCreate.length} will be used (balance: ${campCreditBalance})\n`;
    if (!hasEnough) msg += `\n⚠ Only ${canMake} of ${n} selected videos will be created (insufficient credits).\n`;
    msg += `\nProceed?`;

    if (!confirm(msg)) return;

    await campCreatePodcasts(ideasToCreate, startDate, freqDays);
}

// ── Create one hdb_podcasts row per idea ──────────────────────────────────────
async function campCreatePodcasts(ideas, startDate, freqDays) {
    const btn = document.getElementById('camp-generate-btn');
    const res = document.getElementById('camp-results');

    btn.disabled = true;
    btn.textContent = `⏳ Creating ${ideas.length} videos…`;
    if (res) res.innerHTML = '';

    const langCode = langCodeFromName(settings.language || 'English');
    const niche    = coIndustry.niche || coIndustry.subgroup || coIndustry.group || 'your niche';

    try {
        const d = await post({
            ajax_action: 'create_campaign',
            ideas:       JSON.stringify(ideas),
            niche:       coIndustry.niche    || '',
            subgroup:    coIndustry.subgroup || '',
            group:       coIndustry.group    || '',
            lang_code:   langCode,
            start_date:  startDate  || (() => { const t=new Date(); t.setDate(t.getDate()+1); return t.toISOString().split('T')[0]; })(),
            freq_days:   freqDays   || 1,
        });

        if (d.success && d.created && d.created.length > 0) {
            campCreditBalance = Math.max(0, campCreditBalance - d.created.length);

            const nicheLabel  = escHtml(d.niche || niche);
            const monthNames  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const freqSel     = document.getElementById('camp-frequency');
            const freqLabel   = freqSel ? freqSel.options[freqSel.selectedIndex].text : 'Daily';

            // Format the last scheduled date
            const lastItem     = d.created[d.created.length - 1];
            const lastDateStr  = lastItem.schedule_date || '';
            let lastDateLabel  = lastDateStr;
            if (lastDateStr) {
                const ld = new Date(lastDateStr + 'T00:00:00');
                lastDateLabel = `${ld.getDate()} ${monthNames[ld.getMonth()]} ${ld.getFullYear()}`;
            }
            const firstDateStr = d.created[0].schedule_date || startDate || '';
            let firstDateLabel = firstDateStr;
            if (firstDateStr) {
                const fd = new Date(firstDateStr + 'T00:00:00');
                firstDateLabel = `${fd.getDate()} ${monthNames[fd.getMonth()]} ${fd.getFullYear()}`;
            }

            let html = `
                <div style="text-align:center;padding:24px 20px;margin-top:16px;background:linear-gradient(135deg,#e8f0fe,#dbeafe);border:1px solid #93c5fd;border-radius:16px;">
                    <div style="font-size:36px;margin-bottom:10px;">🎉</div>
                    <div style="font-size:17px;font-weight:700;color:var(--dark-blue);margin-bottom:8px;">
                        Your campaign for <em>${nicheLabel}</em> is ready!
                    </div>
                    <div style="font-size:13px;color:#475569;margin-bottom:6px;">
                        ${d.created.length} video${d.created.length!==1?'s':''} scheduled &nbsp;·&nbsp; ${freqLabel} &nbsp;·&nbsp; ${firstDateLabel} → ${lastDateLabel}
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:20px;line-height:1.6;">
                        Each video will be fully prepared when you open it in the editor or when it is posted.
                    </div>
                    <a href="vizard_browser.php" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#0f2a44,#143b63);color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;">🎬 Go to Video Editor →</a>
                </div>`;

            if (d.failed && d.failed.length > 0) {
                html += `<div style="margin-top:10px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:12px;color:#dc2626;">
                    ⚠ ${d.failed.length} could not be created: ${d.failed.map(f => escHtml(f)).join(', ')}
                </div>`;
            }

            // Video list with schedule dates
            html += `<div style="margin-top:14px;">
                <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">Scheduled Videos</div>`;
            d.created.forEach((item, i) => {
                let dateLabel = item.schedule_date || '';
                if (dateLabel) {
                    const sd = new Date(dateLabel + 'T00:00:00');
                    const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    dateLabel = `${dayNames[sd.getDay()]} ${sd.getDate()} ${monthNames[sd.getMonth()]}`;
                }
                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:#fff;gap:10px;">
                    <span style="font-size:11px;font-weight:700;color:var(--purple);white-space:nowrap;min-width:80px;">${escHtml(dateLabel)}</span>
                    <span style="font-size:13px;font-weight:500;color:var(--dark-blue);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(item.title)}</span>
                    <a href="videomaker.php?podcast_id=${item.podcast_id}" style="font-size:12px;padding:5px 12px;background:var(--purple-lt);color:var(--purple);border-radius:6px;text-decoration:none;font-weight:700;white-space:nowrap;flex-shrink:0;">Open →</a>
                </div>`;
            });
            html += '</div>';

            if (res) res.innerHTML = html;
            showToast(`✅ ${d.created.length} campaign videos created!`);

            campSelectedIdeas = [];
            document.querySelectorAll('#camp-chip-list .camp-chip').forEach(c => c.classList.remove('checked'));
            campUpdateCounter();
        } else {
            if (res) res.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0;padding:12px;background:#fef2f2;border-radius:8px;">⚠️ ${escHtml(d.error || 'Could not create campaign. Please try again.')}</div>`;
            btn.disabled = false;
            btn.textContent = `📅 Create Campaign — ${ideas.length} Video${ideas.length!==1?'s':''}`;
            btn.style.opacity = '1';
        }
    } catch(e) {
        if (res) res.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${escHtml(e.message)}</div>`;
        btn.disabled = false;
        btn.textContent = `📅 Create Campaign — ${ideas.length} Video${ideas.length!==1?'s':''}`;
        btn.style.opacity = '1';
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// I HAVE CONTENT ENGINE
// ═════════════════════════════════════════════════════════════════════════════

function contentInitMode() {
    // Render both settings bars
    // renderBusinessBar / renderSettingsBar already push to content-* pill spans

    // Reset output area
    const out = document.getElementById('content-script-output');
    if (out) out.innerHTML = '';

    // Reset format button
    const btn = document.getElementById('content-format-btn');
    if (btn) { btn.disabled = false; btn.textContent = '📝 Format into Scenes'; }
}

async function contentFormatScript() {
    const titleEl  = document.getElementById('content-title');
    const scriptEl = document.getElementById('content-raw-script');
    const out      = document.getElementById('content-script-output');
    const btn      = document.getElementById('content-format-btn');

    const title  = titleEl  ? titleEl.value.trim()  : '';
    const rawText = scriptEl ? scriptEl.value.trim() : '';

    if (!rawText) {
        showToast('Please paste your script first.');
        if (scriptEl) scriptEl.focus();
        return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Formatting…';
    out.innerHTML = '<div class="loading" style="margin:16px 0;"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Splitting into scenes…</span></div>';

    try {
        const d = await post({
            ajax_action: 'format_user_content',
            raw_text:    rawText,
            title:       title || 'My Video',
        });

        if (!d.success) throw new Error(d.error || 'Could not format content');

        const scenes    = d.scenes || [];
        const script    = d.script || '';
        const wordCount = d.word_count || 0;
        const BREAK     = '<break time="200ms"/>';

        // Build editable script text (same as _renderScript for Standard)
        const scriptText = scenes.map(function(text) {
            return text.replace(/<break[^>]*>/gi, '').trim() + ' ' + BREAK;
        }).join('\n');

        // Store on window for openS2
        window._contentScriptRaw = scriptText;
        window._contentPodcastId = null;
        window._contentData = {
            niche:         coIndustry.niche    || coIndustry.subgroup || coIndustry.group || '',
            title:         title || 'My Video',
            language:      settings.language      || 'English',
            reel_type:     'Standard',
            topic:         coIndustry.subgroup    || coIndustry.group || '',
            angle:         '',
            duration:      '60',
            cta:           'Follow for more tips',
            tone:          settings.tone          || 'Friendly',
            audience:      settings.audience      || 'General Public',
            content_goals: settings.content_goals || 'Education',
            growth_goals:  settings.growth_goals  || 'Grow Followers',
            brand_name:    typeof PHP_BRAND_NAME !== 'undefined' ? PHP_BRAND_NAME : '',
            voice_id:      '',
            voice_rate:    '1.1',
        };
        window._wizAns  = Object.assign({}, window._contentData);
        window._wizData = Object.assign({}, window._contentData);

        // Pills
        const estSecs = wordCount ? Math.round(wordCount / 130 * 60) : 0;
        const durLabel = estSecs >= 60
            ? Math.floor(estSecs / 60) + 'm ' + (estSecs % 60) + 's'
            : estSecs + 's';
        const pills = [
            scenes.length + ' scenes',
            wordCount ? wordCount + ' words' : '',
            wordCount ? '~' + durLabel + ' audio' : '',
            settings.language || 'English',
            'Standard',
        ].filter(Boolean).map(p => '<span class="script-meta-pill">' + escHtml(p) + '</span>').join('');

        out.innerHTML =
            '<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin:16px 0 8px;">Formatted Script</div>'
            + '<div class="script-meta" style="margin-bottom:10px;">' + pills + '</div>'
            + '<div style="font-size:12px;color:var(--muted);margin-bottom:8px;">Each line = one scene (~5-6 seconds). Edit freely before approving.</div>'
            + '<textarea id="content-script-text" oninput="window._contentScriptRaw=this.value"'
            + ' style="width:100%;min-height:220px;padding:14px;border:1.5px solid var(--border);border-radius:10px;'
            + 'font-family:monospace;font-size:13px;line-height:1.9;resize:vertical;outline:none;'
            + 'background:#f8fafc;color:var(--text);">' + escHtml(scriptText) + '</textarea>'
            + '<div style="display:flex;gap:8px;margin-top:10px;flex-direction:column;">'
            + '<button class="nav-next" style="width:100%;font-size:15px;padding:14px;" onclick="contentApproveScript()">Approve Script →</button>'
            + '<button class="script-regen-btn" onclick="contentReformat()" style="width:100%;">🔄 Re-format</button>'
            + '</div>';

        btn.disabled = false;
        btn.textContent = '📝 Format into Scenes';

    } catch(e) {
        out.innerHTML = '<div style="color:#c00;font-size:13px;padding:12px;background:#fef2f2;border-radius:8px;margin-top:12px;">⚠️ ' + escHtml(e.message) + '</div>';
        btn.disabled = false;
        btn.textContent = '📝 Format into Scenes';
    }
}

function contentReformat() {
    // Clear output and let user re-click format
    const out = document.getElementById('content-script-output');
    if (out) out.innerHTML = '';
    contentFormatScript();
}

function contentApproveScript() {
    // Sync any edits from the textarea
    const ta = document.getElementById('content-script-text');
    if (ta) window._contentScriptRaw = ta.value;

    // Ensure _wizData / _wizAns are current
    const titleEl = document.getElementById('content-title');
    const title   = titleEl ? titleEl.value.trim() : 'My Video';
    window._contentData.title = title;
    window._wizData  = Object.assign({}, window._contentData);
    window._wizAns   = Object.assign({}, window._contentData);

    openS2('content');
}


loadSettings();
loadVideoQuota();
(function(){var i=document.getElementById("ideas-custom-in");if(i)i.addEventListener("keydown",function(e){if(e.key==="Enter")addCustomIdea();});})();
// Page always starts at Screen 1 (3 mode cards)
</script>
<div class="s2-overlay" id="s2Overlay">
  <div class="s2-panel" id="s2Panel" style="position:relative;">

    <!-- Inline processing spinner -->
    <div class="s2-processing-overlay" id="s2ProcessingOverlay">
      <div class="s2-spinner"></div>
      <div>
        <div class="s2-processing-msg"  id="s2ProcessingMsg">Step 6: Building your video…</div>
        <div class="s2-processing-step" id="s2ProcessingStep">Please wait…</div>
      </div>
    </div>

    <div class="s2-header">
      <h2>Step 6: Build Video</h2>
      <button class="s2-close" id="s2CloseBtn" onclick="closeS2()">✕</button>
    </div>

    <div class="s2-body">

      <!-- Setup panel -->
      <div id="s2Setup">

        <!-- Credit info bar -->
        <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#166534;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
          <span>💳 Available credits: <strong id="s2CreditBalance">…</strong></span>
          <span style="opacity:.8;">Standard/B-Roll = 1 credit &nbsp;·&nbsp; Podcast/Talking Head = 2 credits</span>
        </div>

        <!-- ── HOST CARD (podcast + talking head) ─────────────────────────── -->
        <div class="s2-role-card s2-host-card" id="s2HostCard" style="display:none;">
          <div class="s2-role-card-title">🎭 Host</div>

          <!-- image picker -->
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Select Image</div>
            <div id="s2HostImageGrid" class="s2-img-grid"></div>
          </div>

          <!-- voice selector — male / female tabs then dropdown -->
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Voice</div>
            <div class="s2-gender-tabs" id="s2HostGenderTabs">
              <button class="s2-gtab active" data-gender="male"   onclick="filterVoices('host','male',this)">👨 Male</button>
              <button class="s2-gtab"        data-gender="female" onclick="filterVoices('host','female',this)">👩 Female</button>
              <button class="s2-gtab"        data-gender="all"    onclick="filterVoices('host','all',this)">All</button>
            </div>
            <select class="s2-select" id="s2HostVoice" style="margin-top:6px;">
              <option value="">Loading voices…</option>
            </select>
          </div>

          <!-- sample audio -->
          <div class="s2-role-subsection">
            <button class="s2-sample-btn" id="s2HostSampleBtn" onclick="playSample('host')">▶ Play Sample</button>
          </div>
        </div>

        <!-- ── GUEST CARD (podcast only) ──────────────────────────────────── -->
        <div class="s2-role-card s2-guest-card" id="s2GuestCard" style="display:none;">
          <div class="s2-role-card-title">🎙️ Guest <span style="font-size:11px;font-weight:400;opacity:.75;">(must differ from host)</span></div>

          <!-- image picker -->
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Select Image</div>
            <div id="s2GuestImageGrid" class="s2-img-grid"></div>
          </div>

          <!-- voice selector -->
          <div class="s2-role-subsection">
            <div class="s2-sublabel">Voice</div>
            <div class="s2-gender-tabs" id="s2GuestGenderTabs">
              <button class="s2-gtab active" data-gender="male"   onclick="filterVoices('guest','male',this)">👨 Male</button>
              <button class="s2-gtab"        data-gender="female" onclick="filterVoices('guest','female',this)">👩 Female</button>
              <button class="s2-gtab"        data-gender="all"    onclick="filterVoices('guest','all',this)">All</button>
            </div>
            <select class="s2-select" id="s2GuestVoice" style="margin-top:6px;">
              <option value="">— Select guest voice —</option>
            </select>
          </div>

          <!-- sample audio -->
          <div class="s2-role-subsection">
            <button class="s2-sample-btn" id="s2GuestSampleBtn" onclick="playSample('guest')">▶ Play Sample</button>
          </div>
        </div>

        <!-- ── STANDARD voice row (non-podcast / non-talking-head) ─────────── -->
        <div id="s2StandardVoiceSection">
          <div class="s2-role-card s2-host-card">
            <div class="s2-role-card-title">🎤 Voice</div>
            <div class="s2-role-subsection">
              <div class="s2-sublabel">Filter by Gender</div>
              <div class="s2-gender-tabs" id="s2StdGenderTabs">
                <button class="s2-gtab active" data-gender="male"   onclick="filterVoicesStd('male',this)">👨 Male</button>
                <button class="s2-gtab"        data-gender="female" onclick="filterVoicesStd('female',this)">👩 Female</button>
                <button class="s2-gtab"        data-gender="all"    onclick="filterVoicesStd('all',this)">All</button>
              </div>
            </div>
            <div class="s2-role-subsection">
              <div class="s2-sublabel">Voice</div>
              <select class="s2-select" id="s2StdHostVoice">
                <option value="">Loading voices…</option>
              </select>
            </div>
            <div class="s2-role-subsection">
              <button class="s2-sample-btn" id="s2StdSampleBtn" onclick="playSampleStd()">▶ Play Sample</button>
            </div>
          </div>
        </div>

        <!-- ── SPEED CARD (always shown) ─────────────────────────────────── -->
        <div class="s2-role-card s2-speed-card">
          <div class="s2-role-card-title">⚡ Speech Speed</div>
          <div class="s2-role-subsection">
            <select class="s2-select" id="s2Rate">
              <option value="0.9">0.9× — Slightly slow</option>
              <option value="1.0">1.0× — Normal</option>
              <option value="1.1">1.1× — Slightly fast</option>
              <option value="1.2">1.2× — Fast</option>
              <option value="1.1" selected>1.1× — Default</option>
              <option value="1.3">1.3× — Very fast</option>
            </select>
          </div>
        </div>

        <!-- ── MEDIA TYPE (standard / b-roll only) ───────────────────────── -->
        <div class="s2-section" id="s2MediaTypeSection" style="display:none;"></div>

        <button class="s2-start-btn" onclick="startBuildVideo()">🚀 Build Video Now</button>
      </div>

      <!-- Progress panel -->
      <div id="s2Progress" style="display:none;">

        <div class="s2-log" id="s2Log"></div>
        <div id="s2DoneBar" style="display:none;"></div>
      </div>

    </div><!-- /s2-body -->
	    <!-- ══ GAME STRIP — outside s2-body, never pushes log ══ -->
    <!-- GAMES REMOVED — see s2SceneGrid below -->

        <!-- SCENE PREVIEW GRID — shows 9x16 boxes per scene, fills with media as build progresses -->
    <div id="s2SceneGrid" style="display:none;padding:12px 20px;background:#f8f9fa;border-top:1px solid #e5e7eb;">
      <div style="font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:10px;">
        📽 Scene Progress
      </div>
      <div id="s2SceneBoxes" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
      <div id="s2DoneBarGrid" style="display:none;margin-top:14px;padding:12px 14px;background:#0d3321;border:1.5px solid #22c55e;border-radius:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span style="font-size:13px;font-weight:600;color:#4ade80;">✅ Video ready!</span>
        <a id="s2VideoLink" href="#" style="font-size:13px;font-weight:700;color:#4fc3f7;text-decoration:none;padding:6px 12px;background:rgba(79,195,247,0.15);border:1px solid #4fc3f7;border-radius:8px;">Review / Record / Schedule →</a>
      </div>
    </div>

  </div><!-- /s2-panel -->
</div><!-- /s2-overlay -->


<!-- ── QUOTA / UPGRADE MODAL ────────────────────────────────── -->
<div id="quotaOverlay" style="display:none;position:fixed;inset:0;background:rgba(10,20,40,0.72);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);">
  <div style="background:#fff;border-radius:20px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.35);">
    <div id="quotaModalBody"></div>
  </div>
</div>
<script>
document.addEventListener('click', function(e) {
    const ov = document.getElementById('quotaOverlay');
    if (e.target === ov) closeQuotaModal();
});
</script>
</body>
</html>
