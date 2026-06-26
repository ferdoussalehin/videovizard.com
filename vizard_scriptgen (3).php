<?php
session_start();
ini_set('session.gc_maxlifetime', 15552000);  // 180 days in seconds
ini_set('session.cookie_lifetime', 15552000); // 180 days
session_set_cookie_params(15552000);
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }
include 'dbconnect_hdb.php';

$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$plan_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1"));
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// ── Shared company/role resolver ──────────────────────────────────────────────
// Logic (matches business rules exactly):
//   1. Get role + team_lead_id for logged-in admin_id
//   2. If Team Member  → owner = team_lead_id  (company belongs to team lead)
//      If Team Lead    → owner = admin_id
//   3. Read hdb_companies WHERE admin_id = owner (+ session company_id if set)
//   4. company_type ''         → non-internal, show only DB niches/categories, no AI
//      company_type 'internal' → internal user, full AI features
// Returns: [owner_id, resolved_company_id, company_type, role]
function vv_log($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/a_errors.log', $line, FILE_APPEND);
}

function vv_resolve_user($conn, $admin_id, $session_company_id) {
    $urow         = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $role         = $urow['role']         ?? 'Team Lead';
    $team_lead_id = (int)($urow['team_lead_id'] ?? 0);

    // Owner: Team Member defers to team lead, Team Lead uses self
    $owner_id = ($role === 'Team Member' && $team_lead_id > 0) ? $team_lead_id : $admin_id;

    // --- DIAGNOSTIC: dump the actual hdb_companies row for this company_id ---
    if ($session_company_id > 0) {
        $diag = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, admin_id, companyname, company_type FROM hdb_companies WHERE id=$session_company_id LIMIT 1"));
        vv_log("DIAG hdb_companies id=$session_company_id => " . json_encode($diag));
    }
    // -------------------------------------------------------------------------

    // Fetch company — prefer session company_id if set, else first company for owner
    if ($session_company_id > 0) {
        $co_sql = "SELECT id, company_type FROM hdb_companies
                   WHERE admin_id=$owner_id AND id=$session_company_id LIMIT 1";
    } else {
        $co_sql = "SELECT id, company_type FROM hdb_companies
                   WHERE admin_id=$owner_id ORDER BY id ASC LIMIT 1";
    }
    $co_row          = mysqli_fetch_assoc(mysqli_query($conn, $co_sql));
    $company_type    = $co_row['company_type'] ?? '';
    $resolved_co_id  = $co_row ? (int)$co_row['id'] : $session_company_id;

    vv_log("vv_resolve_user | admin_id=$admin_id session_co=$session_company_id"
         . " | role=$role team_lead_id=$team_lead_id owner_id=$owner_id"
         . " | co_sql=[$co_sql]"
         . " | co_row=" . json_encode($co_row)
         . " | company_type=[$company_type] resolved_co_id=$resolved_co_id");

    return [$owner_id, $resolved_co_id, $company_type, $role];
}
// ── AJAX: Get user video ideas ───────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_video_ideas') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $niche_name    = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    $page          = (int)($_POST['page'] ?? 1);
    $limit         = 10;
    $offset        = ($page - 1) * $limit;

    if (empty($niche_name) || empty($category_name)) {
        echo json_encode(['success' => false, 'error' => 'Missing niche or category']);
        exit;
    }

    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    
    // DEBUG: Log what we're looking for
    vv_log("=== DEBUG get_user_video_ideas ===");
    vv_log("Looking for: niche_name='$niche_name', category_name='$category_name'");
    vv_log("admin_id=$admin_id, owner_id=$owner_id, company_id=$co_id");
    
    // FIRST: Check what's actually in the database for this user
    $check_query = mysqli_query($conn,
        "SELECT id, admin_id, company_id, niche_id, category_id, niche_name, category_name, video_idea 
         FROM hdb_user_video_ideas 
         WHERE niche_name = '$niche_name' AND category_name = '$category_name'
         LIMIT 10");
    
    vv_log("Sample records for this niche/category:");
    while ($row = mysqli_fetch_assoc($check_query)) {
        vv_log("  ID: {$row['id']}, admin_id: {$row['admin_id']}, company_id: {$row['company_id']}, niche_id: {$row['niche_id']}, category_id: {$row['category_id']}");
        vv_log("    idea: {$row['video_idea']}");
    }
    
    // Now try to get user's ideas with the correct scope
    $myIdeas = [];
    $total_my = 0;
    
    // Try different scope combinations
    $count_q = mysqli_query($conn,
        "SELECT COUNT(*) as total FROM hdb_user_video_ideas
         WHERE niche_name = '$niche_name' AND category_name = '$category_name'
         AND admin_id = $owner_id AND company_id = $co_id");
    
    if ($count_q && $count_row = mysqli_fetch_assoc($count_q)) {
        $total_my = (int)$count_row['total'];
        vv_log("Count with admin_id=$owner_id, company_id=$co_id: $total_my");
    }
    
    // If no results, try with just admin_id
    if ($total_my == 0) {
        $count_q2 = mysqli_query($conn,
            "SELECT COUNT(*) as total FROM hdb_user_video_ideas
             WHERE niche_name = '$niche_name' AND category_name = '$category_name'
             AND admin_id = $owner_id");
        if ($count_q2 && $count_row2 = mysqli_fetch_assoc($count_q2)) {
            $total_my = (int)$count_row2['total'];
            vv_log("Count with just admin_id=$owner_id: $total_my");
        }
    }
    
    // If still no results, try with just company_id
    if ($total_my == 0 && $co_id > 0) {
        $count_q3 = mysqli_query($conn,
            "SELECT COUNT(*) as total FROM hdb_user_video_ideas
             WHERE niche_name = '$niche_name' AND category_name = '$category_name'
             AND company_id = $co_id");
        if ($count_q3 && $count_row3 = mysqli_fetch_assoc($count_q3)) {
            $total_my = (int)$count_row3['total'];
            vv_log("Count with just company_id=$co_id: $total_my");
        }
    }
    
    // If still no results, try with niche_id and category_id
    if ($total_my == 0) {
        // Get niche_id and category_id
        $niche_q = mysqli_query($conn, "SELECT id FROM hdb_user_niches WHERE niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
        $niche_id = $niche_q ? (int)(mysqli_fetch_assoc($niche_q)['id'] ?? 0) : 0;
        
        $cat_q = mysqli_query($conn, "SELECT id FROM hdb_user_categories WHERE category_name='$category_name' AND niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
        $category_id = $cat_q ? (int)(mysqli_fetch_assoc($cat_q)['id'] ?? 0) : 0;
        
        if ($niche_id > 0 && $category_id > 0) {
            $count_q4 = mysqli_query($conn,
                "SELECT COUNT(*) as total FROM hdb_user_video_ideas
                 WHERE niche_id = $niche_id AND category_id = $category_id");
            if ($count_q4 && $count_row4 = mysqli_fetch_assoc($count_q4)) {
                $total_my = (int)$count_row4['total'];
                vv_log("Count with niche_id=$niche_id, category_id=$category_id: $total_my");
            }
        }
    }
    
    vv_log("Final total_my: $total_my");
    
    if ($total_my > 0) {
        $q = mysqli_query($conn,
            "SELECT id, video_idea, is_ai_generated, created_date 
             FROM hdb_user_video_ideas
             WHERE niche_name = '$niche_name' AND category_name = '$category_name'
               AND admin_id = $owner_id AND company_id = $co_id
             ORDER BY created_date DESC 
             LIMIT $offset, $limit");
        
        // If no results with both, try with just admin_id
        if (!$q || mysqli_num_rows($q) == 0) {
            $q = mysqli_query($conn,
                "SELECT id, video_idea, is_ai_generated, created_date 
                 FROM hdb_user_video_ideas
                 WHERE niche_name = '$niche_name' AND category_name = '$category_name'
                   AND admin_id = $owner_id
                 ORDER BY created_date DESC 
                 LIMIT $offset, $limit");
        }
        
        // If still no results, try with niche_id and category_id
        if (!$q || mysqli_num_rows($q) == 0) {
            $niche_q = mysqli_query($conn, "SELECT id FROM hdb_user_niches WHERE niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
            $niche_id = $niche_q ? (int)(mysqli_fetch_assoc($niche_q)['id'] ?? 0) : 0;
            
            $cat_q = mysqli_query($conn, "SELECT id FROM hdb_user_categories WHERE category_name='$category_name' AND niche_name='$niche_name' AND admin_id=$owner_id LIMIT 1");
            $category_id = $cat_q ? (int)(mysqli_fetch_assoc($cat_q)['id'] ?? 0) : 0;
            
            if ($niche_id > 0 && $category_id > 0) {
                $q = mysqli_query($conn,
                    "SELECT id, video_idea, is_ai_generated, created_date 
                     FROM hdb_user_video_ideas
                     WHERE niche_id = $niche_id AND category_id = $category_id
                     ORDER BY created_date DESC 
                     LIMIT $offset, $limit");
            }
        }
        
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $myIdeas[] = $r['video_idea'];
            }
        }
    }
    
    vv_log("Returning " . count($myIdeas) . " ideas: " . json_encode($myIdeas));
    
    echo json_encode([
        'success' => true, 
        'ideas' => $myIdeas, 
        'common_ideas' => [], 
        'used_titles' => [],
        'total_my' => count($myIdeas),
        'current_page' => $page,
        'has_more' => false
    ]); 
    exit;
}

// ── AJAX: Get video quota ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_quota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT plan_type, role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $pt   = $urow['plan_type']      ?? 'free_trial';
    $role = $urow['role']           ?? 'Team Lead';
    $tl   = (int)($urow['team_lead_id'] ?? 0);
    // Team Members share their Team Lead's credit_balance
    if ($role === 'Team Member' && $tl > 0) {
        $crow    = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM hdb_users WHERE id=$tl LIMIT 1"));
        $credits = (int)($crow['credit_balance'] ?? 0);
    } else {
        $credits = (int)($urow['credit_balance'] ?? 0);
    }
    echo json_encode([
        'success'        => true,
        'credit_balance' => $credits,
        'plan_type'      => $pt,
        'exceeded'       => ($credits <= 0),
    ]);
    exit;
}

// ── AJAX: Get AI video suggestions (not saved to DB automatically) ────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ai_video_suggestions') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $niche_name    = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));

    if (!$niche_name || !$category_name) {
        echo json_encode(['success' => false, 'error' => 'Missing niche or category']);
        exit;
    }

    // Get existing used titles to exclude
    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    $used_titles = [];
    $used_query  = mysqli_query($conn,
        "SELECT DISTINCT title FROM hdb_podcasts
         WHERE admin_id=$owner_id AND title != '' AND title IS NOT NULL LIMIT 30");
    while ($row = mysqli_fetch_assoc($used_query)) $used_titles[] = $row['title'];
    $exclude = count($used_titles) ? ' Do NOT repeat: ' . implode(', ', array_slice($used_titles, 0, 20)) . '.' : '';

    $prompt = "You are an expert content creator for the niche '{$niche_name}', specialising in the area of '{$category_name}'.
Generate 10 specific VIDEO TITLE IDEAS for short-form social media videos (Reels/TikTok/Shorts).
Each title should be a clear topic or subject — NOT a hook, angle, or storytelling style.
A hook (like 'As a story', 'Did you know', 'Controversial take') will be chosen separately later.
The title should answer: WHAT is this video about? (e.g. '5 Signs You Have Anxiety', 'How to Start Saving for Retirement', 'Why Most Diets Fail').
{$exclude}
Return ONLY a valid JSON array of title strings, no extra text.";

    require_once __DIR__ . '/config.php';
    $apiKey = $apiKey ?? $chatgpt_api_key ?? '';

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a creative video content strategist. Return ONLY valid JSON arrays.'],
            ['role' => 'user',   'content' => $prompt]
        ],
        'temperature' => 0.8,
        'max_tokens'  => 800
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $data    = json_decode($response, true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');
        $content = preg_replace('/^```json\s*|\s*```$/s', '', $content);
        $items   = json_decode($content, true);
        if (is_array($items)) {
            echo json_encode(['success' => true, 'suggestions' => array_slice($items, 0, 10)]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid AI response format']);
        }
    } else {
        $err  = json_decode((string)$response, true);
        $msg  = $err['error']['message'] ?? "HTTP $httpCode";
        echo json_encode(['success' => false, 'error' => $msg]);
    }
    exit;
}
// ── AJAX: Save CTA ────────────────────────────────────────────────────────────
// ── AJAX: Save CTA ────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_cta') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $cta_text = mysqli_real_escape_string($conn, trim($_POST['cta_text'] ?? ''));
    if (!$cta_text) { echo json_encode(['success'=>false,'message'=>'Empty CTA']); exit; }
    
    // Get current user's company context
    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    
    // Check if CTA exists for this company
    $exists = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_user_cta 
         WHERE cta_text='$cta_text' AND company_id=$co_id 
         LIMIT 1"));
    
    if (!$exists) {
        mysqli_query($conn, "INSERT INTO hdb_user_cta (cta_text, company_id, admin_id, created_date)
            VALUES ('$cta_text', $co_id, $owner_id, NOW())");
    }
    
    echo json_encode(['success'=>true]); 
    exit;
}

// ── AJAX: Get CTAs ────────────────────────────────────────────────────────────
// ── AJAX: Get CTAs (company-specific + global fallback) ───────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ctas') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    
    // Get company-specific CTAs first, then global CTAs (company_id = 0)
    $q = mysqli_query($conn, 
        "SELECT cta_text, company_id FROM hdb_user_cta 
         WHERE company_id = $co_id OR company_id = 0
         ORDER BY CASE WHEN company_id = $co_id THEN 0 ELSE 1 END, created_date DESC 
         LIMIT 30");
    
    $ctas = [];
    $company_ctas = [];
    $global_ctas = [];
    
    while ($r = mysqli_fetch_assoc($q)) {
        if ($r['company_id'] == $co_id) {
            $company_ctas[] = $r['cta_text'];
        } else {
            $global_ctas[] = $r['cta_text'];
        }
    }
    
    // Merge: company CTAs first, then global CTAs (deduplicated)
    $all_ctas = array_merge($company_ctas, $global_ctas);
    $ctas = array_values(array_unique($all_ctas));
    
    vv_log("get_ctas | company_id=$co_id | found " . count($ctas) . " CTAs (" . count($company_ctas) . " company, " . count($global_ctas) . " global)");
    
    echo json_encode(['success'=>true, 'ctas'=>$ctas]); 
    exit;
}
// ── AJAX: Save niche ──────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_niche') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name      = mysqli_real_escape_string($conn, trim($_POST['niche_name'] ?? ''));
    $is_ai_generated = (int)($_POST['is_ai_generated'] ?? 0);
    $store_as_common = (int)($_POST['store_as_common'] ?? 0);
    if (!$niche_name) { echo json_encode(['success'=>false,'message'=>'Empty niche']); exit; }

    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);

    if ($company_type !== 'internal') {
        // Non-internal (company_type = ''): store under owner + company, never AI
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_niches
             WHERE admin_id=$owner_id AND company_id=$co_id AND niche_name='$niche_name' LIMIT 1"));
        if (!$exists) {
            mysqli_query($conn, "INSERT INTO hdb_user_niches (admin_id, company_id, niche_name, is_ai_generated)
                VALUES ($owner_id, $co_id, '$niche_name', 0)");
        }
    } elseif ($store_as_common && $is_ai_generated) {
        // Internal: store as common pool (admin_id=0)
        $exists0 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_niches WHERE admin_id=0 AND niche_name='$niche_name' LIMIT 1"));
        if (!$exists0) {
            mysqli_query($conn, "INSERT INTO hdb_user_niches (admin_id, company_id, niche_name, is_ai_generated)
                VALUES (0, 0, '$niche_name', 1)");
        }
    } else {
        // Internal: store as user's own niche
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_niches WHERE admin_id=$admin_id AND niche_name='$niche_name' LIMIT 1"));
        if (!$exists) {
            mysqli_query($conn, "INSERT INTO hdb_user_niches (admin_id, company_id, niche_name, is_ai_generated)
                VALUES ($admin_id, $co_id, '$niche_name', $is_ai_generated)");
        }
    }
    echo json_encode(['success'=>true]); exit;
}
// ── AJAX: Get video quota ─────────────────────────────────────────────────────

// ── AJAX: Get CTAs separated (company vs global) ─────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_ctas_separated') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
    
    // Get company-specific CTAs
    $company_q = mysqli_query($conn, 
        "SELECT cta_text FROM hdb_user_cta 
         WHERE company_id = $co_id
         ORDER BY created_date DESC LIMIT 30");
    
    $company_ctas = [];
    while ($r = mysqli_fetch_assoc($company_q)) {
        $company_ctas[] = $r['cta_text'];
    }
    
    // Get global CTAs (company_id = 0)
    $global_q = mysqli_query($conn, 
        "SELECT cta_text FROM hdb_user_cta 
         WHERE company_id = 0
         ORDER BY created_date DESC LIMIT 30");
    
    $global_ctas = [];
    while ($r = mysqli_fetch_assoc($global_q)) {
        $global_ctas[] = $r['cta_text'];
    }
    
    echo json_encode([
        'success' => true,
        'company_ctas' => $company_ctas,
        'global_ctas' => $global_ctas
    ]);
    exit;
}


// ── AJAX: Get video quota ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_quota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    // Load logged-in user's role and team_lead_id
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT plan_type, role, team_lead_id, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $pt   = $urow['plan_type']     ?? 'free_trial';
    $role = $urow['role']          ?? 'Team Lead';
    $tl   = (int)($urow['team_lead_id'] ?? 0);
    // Team Members share their Team Lead's credit_balance
    if ($role === 'Team Member' && $tl > 0) {
        $crow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM hdb_users WHERE id=$tl LIMIT 1"));
        $credits = (int)($crow['credit_balance'] ?? 0);
    } else {
        $credits = (int)($urow['credit_balance'] ?? 0);
    }
    echo json_encode([
        'success'        => true,
        'credit_balance' => $credits,
        'plan_type'      => $pt,
        'exceeded'       => ($credits <= 0),
    ]);
    exit;
}
// ── AJAX: Get user niches ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_niches') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);

    if ($company_type !== 'internal') {
        // --- DIAGNOSTIC: show ALL niches for owner_id regardless of company_id/is_ai ---
        $diag_q = mysqli_query($conn,
            "SELECT admin_id, company_id, niche_name, is_ai_generated
             FROM hdb_user_niches WHERE admin_id=$owner_id LIMIT 20");
        $diag_rows = [];
        while ($dr = mysqli_fetch_assoc($diag_q)) $diag_rows[] = $dr;
        vv_log("get_user_niches | DIAG raw hdb_user_niches for admin_id=$owner_id => " . json_encode($diag_rows));
        // -------------------------------------------------------------------------

        $sql = "SELECT niche_name FROM hdb_user_niches
                WHERE admin_id=$owner_id AND company_id=$co_id AND is_ai_generated=0
                ORDER BY created_date DESC LIMIT 50";
        vv_log("get_user_niches | NON-INTERNAL | company_type=[$company_type] | sql=$sql");
        $q = mysqli_query($conn, $sql);
        $niches = [];
        while ($r = mysqli_fetch_assoc($q)) $niches[] = $r['niche_name'];
        vv_log("get_user_niches | NON-INTERNAL | rows_found=" . count($niches) . " | niches=" . json_encode($niches));
        echo json_encode(['success'=>true, 'niches'=>$niches, 'common_niches'=>[], 'is_internal'=>false]);
        exit;
    }

    // company_type = 'internal' → show own niches + AI common pool
    $sql1 = "SELECT niche_name FROM hdb_user_niches WHERE admin_id=$admin_id ORDER BY created_date DESC LIMIT 20";
    $sql2 = "SELECT niche_name FROM hdb_user_niches WHERE admin_id=0 ORDER BY niche_name ASC LIMIT 50";
    vv_log("get_user_niches | INTERNAL | sql1=$sql1 | sql2=$sql2");
    $q  = mysqli_query($conn, $sql1);
    $myNiches = [];
    while ($r = mysqli_fetch_assoc($q)) $myNiches[] = $r['niche_name'];
    $q2 = mysqli_query($conn, $sql2);
    $commonNiches = [];
    while ($r = mysqli_fetch_assoc($q2)) $commonNiches[] = $r['niche_name'];
    vv_log("get_user_niches | INTERNAL | my=" . count($myNiches) . " common=" . count($commonNiches));
    echo json_encode(['success'=>true, 'niches'=>$myNiches, 'common_niches'=>$commonNiches, 'is_internal'=>true]);
    exit;
}

// ── AJAX: Save category ───────────────────────────────────────────────────────
// ── AJAX: Save category ───────────────────────────────────────────────────────
// ── AJAX: Save category ───────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_category') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name      = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name   = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    $is_ai           = (int)($_POST['is_ai_generated'] ?? 0);
    $store_as_common = (int)($_POST['store_as_common']  ?? 0);
    if (!$category_name || !$niche_name) { echo json_encode(['success'=>false]); exit; }

    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);

    // Get the niche_id from hdb_user_niches
    $niche_id = 0;
    
    if ($store_as_common && $is_ai) {
        // For common AI categories, try to find niche_id from common pool first
        $niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE admin_id = 0 AND niche_name = '$niche_name' 
             LIMIT 1");
        if ($niche_row = mysqli_fetch_assoc($niche_query)) {
            $niche_id = (int)$niche_row['id'];
        }
    } else {
        // For user-specific categories, find their niche_id
        $niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE niche_name = '$niche_name' 
             AND admin_id = $owner_id 
             LIMIT 1");
        if ($niche_row = mysqli_fetch_assoc($niche_query)) {
            $niche_id = (int)$niche_row['id'];
        }
        
        // If not found with owner_id, try with admin_id (for team members)
        if ($niche_id == 0 && $role === 'Team Member') {
            $niche_query2 = mysqli_query($conn, 
                "SELECT id FROM hdb_user_niches 
                 WHERE niche_name = '$niche_name' 
                 AND admin_id = $admin_id 
                 LIMIT 1");
            if ($niche_row2 = mysqli_fetch_assoc($niche_query2)) {
                $niche_id = (int)$niche_row2['id'];
            }
        }
    }

    vv_log("save_category | niche_name=$niche_name | niche_id=$niche_id | store_as_common=$store_as_common | is_ai=$is_ai");

    if ($company_type !== 'internal') {
        // Non-internal: store under owner + company, never AI
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_categories
             WHERE admin_id=$owner_id AND company_id=$co_id
               AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1"));
        if (!$exists) {
            mysqli_query($conn, "INSERT INTO hdb_user_categories (admin_id, company_id, niche_id, niche_name, category_name, is_ai_generated)
                VALUES ($owner_id, $co_id, $niche_id, '$niche_name', '$category_name', 0)");
        }
    } elseif ($store_as_common && $is_ai) {
        // Internal: store as common for all users (admin_id=0)
        $exists0 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_categories
             WHERE admin_id=0 AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1"));
        if (!$exists0) {
            mysqli_query($conn, "INSERT INTO hdb_user_categories (admin_id, company_id, niche_id, niche_name, category_name, is_ai_generated)
                VALUES (0, 0, $niche_id, '$niche_name', '$category_name', 1)");
        }
    } else {
        // Internal: store as user's own category
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_categories
             WHERE admin_id=$admin_id AND niche_name='$niche_name' AND category_name='$category_name' LIMIT 1"));
        if (!$exists) {
            mysqli_query($conn, "INSERT INTO hdb_user_categories (admin_id, company_id, niche_id, niche_name, category_name, is_ai_generated)
                VALUES ($admin_id, $co_id, $niche_id, '$niche_name', '$category_name', $is_ai)");
        }
    }
    echo json_encode(['success'=>true]); exit;
}
// ── AJAX: Get user categories ─────────────────────────────────────────────────
// ── AJAX: Get user categories ─────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_categories') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn, trim($_POST['niche_name'] ?? ''));
    $myCategories = $commonCategories = [];

    [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);

    if ($niche_name) {
        if ($company_type !== 'internal') {
            $sql = "SELECT category_name, niche_id FROM hdb_user_categories
                    WHERE admin_id=$owner_id AND company_id=$co_id
                      AND niche_name='$niche_name' AND is_ai_generated=0
                    ORDER BY created_date DESC LIMIT 50";
            $q = mysqli_query($conn, $sql);
            while ($r = mysqli_fetch_assoc($q)) $myCategories[] = $r['category_name'];
        } else {
            $sql1 = "SELECT category_name, niche_id FROM hdb_user_categories
                     WHERE admin_id=$admin_id AND niche_name='$niche_name'
                     ORDER BY created_date DESC LIMIT 20";
            $sql2 = "SELECT category_name FROM hdb_user_categories
                     WHERE admin_id=0 AND niche_name='$niche_name'
                     ORDER BY category_name ASC LIMIT 30";
            $q  = mysqli_query($conn, $sql1);
            while ($r = mysqli_fetch_assoc($q))  $myCategories[]     = $r['category_name'];
            $q2 = mysqli_query($conn, $sql2);
            while ($r = mysqli_fetch_assoc($q2)) $commonCategories[] = $r['category_name'];
        }
    }
    echo json_encode(['success'=>true, 'categories'=>$myCategories, 'common_categories'=>$commonCategories]); exit;
}
// ── AJAX: Delete niche ────────────────────────────────────────────────────────
// ── AJAX: Delete niche ────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_niche') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn, trim($_POST['niche_name'] ?? ''));
    
    if ($niche_name) {
        // FIRST: Get the niche ID(s) for this niche
        $niche_ids = [];
        
        // Get user's niche ID
        $user_niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE admin_id = $admin_id AND niche_name = '$niche_name'");
        while ($row = mysqli_fetch_assoc($user_niche_query)) {
            $niche_ids[] = (int)$row['id'];
        }
        
        // Also get common pool niche ID (admin_id = 0) if it exists
        $common_niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE admin_id = 0 AND niche_name = '$niche_name'");
        while ($row = mysqli_fetch_assoc($common_niche_query)) {
            $niche_ids[] = (int)$row['id'];
        }
        
        // Delete related records using niche_id
        if (!empty($niche_ids)) {
            $niche_ids_list = implode(',', $niche_ids);
            
            // Delete categories with matching niche_id
            $delete_cats = mysqli_query($conn, 
                "DELETE FROM hdb_user_categories 
                 WHERE niche_id IN ($niche_ids_list)");
            $cats_deleted = mysqli_affected_rows($conn);
            
            // Delete video ideas with matching niche_id
            $delete_ideas = mysqli_query($conn, 
                "DELETE FROM hdb_user_video_ideas 
                 WHERE niche_id IN ($niche_ids_list)");
            $ideas_deleted = mysqli_affected_rows($conn);
            
            // Delete angles with matching niche_id (if table exists)
            $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_user_angles'");
            if (mysqli_num_rows($table_check) > 0) {
                mysqli_query($conn, "DELETE FROM hdb_user_angles 
                    WHERE niche_id IN ($niche_ids_list)");
            }
            
            vv_log("delete_niche | Deleted $cats_deleted categories and $ideas_deleted video ideas for niche_ids: $niche_ids_list");
        }
        
        // ALSO delete by niche_name as fallback (for records that might not have niche_id set yet)
        $delete_cats_fallback = mysqli_query($conn, 
            "DELETE FROM hdb_user_categories 
             WHERE admin_id = $admin_id AND niche_name = '$niche_name'");
        $delete_ideas_fallback = mysqli_query($conn, 
            "DELETE FROM hdb_user_video_ideas 
             WHERE admin_id = $admin_id AND niche_name = '$niche_name'");
        
        // Delete angles by name as fallback
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_user_angles'");
        if (mysqli_num_rows($table_check) > 0) {
            mysqli_query($conn, "DELETE FROM hdb_user_angles 
                WHERE admin_id = $admin_id AND niche_name = '$niche_name'");
        }
        
        // Finally, delete the niche records themselves
        mysqli_query($conn, "DELETE FROM hdb_user_niches 
            WHERE admin_id = $admin_id AND niche_name = '$niche_name'");
        
        // Delete from common pool only if no other users are using it
        $check_other_users = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE niche_name = '$niche_name' AND admin_id != $admin_id 
             LIMIT 1");
        if (mysqli_num_rows($check_other_users) == 0) {
            mysqli_query($conn, "DELETE FROM hdb_user_niches 
                WHERE admin_id = 0 AND niche_name = '$niche_name'");
        }
        
        vv_log("delete_niche | Successfully deleted niche '$niche_name' for admin_id=$admin_id");
    }
    
    echo json_encode(['success' => true]); 
    exit;
}

// ── AJAX: Delete category ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_category') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name    = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    
    if ($niche_name && $category_name) {
        // FIRST: Get the category ID
        $category_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_categories 
             WHERE admin_id = $admin_id 
               AND niche_name = '$niche_name' 
               AND category_name = '$category_name' 
             LIMIT 1");
        
        $category_id = 0;
        if ($cat_row = mysqli_fetch_assoc($category_query)) {
            $category_id = (int)$cat_row['id'];
        }
        
        // Delete video ideas with this category_id
        if ($category_id > 0) {
            $delete_ideas = mysqli_query($conn, 
                "DELETE FROM hdb_user_video_ideas 
                 WHERE category_id = $category_id");
            $ideas_deleted = mysqli_affected_rows($conn);
            vv_log("delete_category | Deleted $ideas_deleted video ideas for category_id=$category_id");
        }
        
        // ALSO delete video ideas by name as fallback (for records without category_id)
        mysqli_query($conn, 
            "DELETE FROM hdb_user_video_ideas 
             WHERE admin_id = $admin_id 
               AND niche_name = '$niche_name' 
               AND category_name = '$category_name'");
        
        // Finally, delete the category itself
        mysqli_query($conn, 
            "DELETE FROM hdb_user_categories 
             WHERE admin_id = $admin_id 
               AND niche_name = '$niche_name' 
               AND category_name = '$category_name'");
        
        // Also delete from common pool if this was a common category and no other users are using it
        $check_other_users = mysqli_query($conn, 
            "SELECT id FROM hdb_user_categories 
             WHERE niche_name = '$niche_name' 
               AND category_name = '$category_name' 
               AND admin_id != $admin_id 
             LIMIT 1");
        if (mysqli_num_rows($check_other_users) == 0) {
            mysqli_query($conn, 
                "DELETE FROM hdb_user_categories 
                 WHERE admin_id = 0 
                   AND niche_name = '$niche_name' 
                   AND category_name = '$category_name'");
            // Also delete common video ideas for this category
            mysqli_query($conn, 
                "DELETE FROM hdb_user_video_ideas 
                 WHERE admin_id = 0 
                   AND niche_name = '$niche_name' 
                   AND category_name = '$category_name'");
        }
    }
    echo json_encode(['success' => true]); 
    exit;
}
// ── AJAX: Save video idea ─────────────────────────────────────────────────────
// ── AJAX: Save video idea ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_video_idea') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name      = mysqli_real_escape_string($conn, trim($_POST['niche_name']    ?? ''));
    $category_name   = mysqli_real_escape_string($conn, trim($_POST['category_name'] ?? ''));
    $video_idea      = mysqli_real_escape_string($conn, trim($_POST['video_idea']    ?? ''));
    $is_ai           = (int)($_POST['is_ai_generated'] ?? 0);
    $store_as_common = (int)($_POST['store_as_common']  ?? 0);
    if (!$video_idea) { echo json_encode(['success'=>false]); exit; }
    
    // Get niche_id and category_id
    $niche_id = 0;
    $category_id = 0;
    
    if ($store_as_common && $is_ai) {
        // For common AI ideas, look up from common pool
        $niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE admin_id = 0 AND niche_name = '$niche_name' 
             LIMIT 1");
        if ($niche_row = mysqli_fetch_assoc($niche_query)) {
            $niche_id = (int)$niche_row['id'];
        }
        
        $category_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_categories 
             WHERE admin_id = 0 AND niche_name = '$niche_name' AND category_name = '$category_name' 
             LIMIT 1");
        if ($category_row = mysqli_fetch_assoc($category_query)) {
            $category_id = (int)$category_row['id'];
        }
    } else {
        // For user-specific ideas, find their niche_id and category_id
        $niche_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_niches 
             WHERE niche_name = '$niche_name' 
             AND admin_id = $admin_id 
             LIMIT 1");
        if ($niche_row = mysqli_fetch_assoc($niche_query)) {
            $niche_id = (int)$niche_row['id'];
        }
        
        $category_query = mysqli_query($conn, 
            "SELECT id FROM hdb_user_categories 
             WHERE niche_name = '$niche_name' 
             AND category_name = '$category_name' 
             AND admin_id = $admin_id 
             LIMIT 1");
        if ($category_row = mysqli_fetch_assoc($category_query)) {
            $category_id = (int)$category_row['id'];
        }
        
        // If not found with admin_id, try with company scope for non-internal users
        if ($category_id == 0) {
            [$owner_id, $co_id, $company_type, $role] = vv_resolve_user($conn, $admin_id, $company_id);
            $category_query2 = mysqli_query($conn, 
                "SELECT id FROM hdb_user_categories 
                 WHERE niche_name = '$niche_name' 
                 AND category_name = '$category_name' 
                 AND admin_id = $owner_id AND company_id = $co_id 
                 LIMIT 1");
            if ($category_row2 = mysqli_fetch_assoc($category_query2)) {
                $category_id = (int)$category_row2['id'];
            }
        }
    }
    
    vv_log("save_video_idea | niche=$niche_name (id=$niche_id) | category=$category_name (id=$category_id) | idea=$video_idea");
    
    if ($store_as_common && $is_ai) {
        $exists0 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_video_ideas 
             WHERE admin_id=0 AND niche_name='$niche_name' 
             AND category_name='$category_name' AND video_idea='$video_idea' 
             LIMIT 1"));
        if (!$exists0) {
            mysqli_query($conn, "INSERT INTO hdb_user_video_ideas 
                (admin_id, company_id, niche_id, category_id, niche_name, category_name, video_idea, is_ai_generated)
                VALUES (0, 0, $niche_id, $category_id, '$niche_name', '$category_name', '$video_idea', 1)");
        }
    } else {
        $exists = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_video_ideas 
             WHERE admin_id=$admin_id AND niche_name='$niche_name' 
             AND category_name='$category_name' AND video_idea='$video_idea' 
             LIMIT 1"));
        if (!$exists) {
            mysqli_query($conn, "INSERT INTO hdb_user_video_ideas 
                (admin_id, company_id, niche_id, category_id, niche_name, category_name, video_idea, is_ai_generated)
                VALUES ($admin_id, $company_id, $niche_id, $category_id, '$niche_name', '$category_name', '$video_idea', $is_ai)");
        }
    }
    
    echo json_encode(['success'=>true]); 
    exit;
}


// ── AJAX: Get user angles ─────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_angles') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name = mysqli_real_escape_string($conn, trim($_POST['niche_name'] ?? ''));
    $myAngles = $commonAngles = [];
    $q = mysqli_query($conn, "SELECT angle_name FROM hdb_user_angles
         WHERE admin_id=$admin_id" . ($niche_name ? " AND niche_name='$niche_name'" : '') . "
         ORDER BY created_date DESC LIMIT 20");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $myAngles[] = $r['angle_name'];
    $q2 = mysqli_query($conn, "SELECT angle_name FROM hdb_user_angles
         WHERE admin_id=0
         ORDER BY angle_name ASC LIMIT 50");
    if ($q2) while ($r = mysqli_fetch_assoc($q2)) $commonAngles[] = $r['angle_name'];
    echo json_encode(['success'=>true, 'my_angles'=>$myAngles, 'common_angles'=>$commonAngles]); exit;
}
// ── AJAX: Save angle ──────────────────────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_angle') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $niche_name      = mysqli_real_escape_string($conn, trim($_POST['niche_name']  ?? ''));
    $angle_name      = mysqli_real_escape_string($conn, trim($_POST['angle_name']  ?? ''));
    $is_ai           = (int)($_POST['is_ai_generated'] ?? 0);
    $store_as_common = (int)($_POST['store_as_common']  ?? 0);
    if (!$angle_name) { echo json_encode(['success'=>false]); exit; }
    if ($store_as_common && $is_ai) {
        $e = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_angles WHERE admin_id=0 AND angle_name='$angle_name' LIMIT 1"));
        if (!$e) mysqli_query($conn, "INSERT INTO hdb_user_angles (admin_id, company_id, niche_name, angle_name, is_ai_generated) VALUES (0, 0, '$niche_name', '$angle_name', 1)");
    } else {
        $e = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM hdb_user_angles WHERE admin_id=$admin_id AND angle_name='$angle_name' LIMIT 1"));
        if (!$e) mysqli_query($conn, "INSERT INTO hdb_user_angles (admin_id, company_id, niche_name, angle_name, is_ai_generated) VALUES ($admin_id, $company_id, '$niche_name', '$angle_name', $is_ai)");
    }
    echo json_encode(['success'=>true]); exit;
}
// ── AJAX: Save campaign production ───────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_campaign_production') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $campaign_id = (int)($_POST['campaign_id'] ?? 0);
    $voice_id    = mysqli_real_escape_string($conn, trim($_POST['voice_id']   ?? ''));
    $rate        = mysqli_real_escape_string($conn, trim($_POST['rate']       ?? '1.0'));
    $media_type  = mysqli_real_escape_string($conn, trim($_POST['media_type'] ?? 'stock_videos'));
    if (!$campaign_id) { echo json_encode(['success'=>false,'message'=>'No campaign_id']); exit; }
    $check = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_campaigns WHERE id=$campaign_id AND admin_id=$admin_id LIMIT 1"));
    if (!$check) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    mysqli_query($conn, "UPDATE hdb_campaigns
         SET voice_id='$voice_id', speech_rate='$rate', media_type='$media_type'
         WHERE id=$campaign_id AND admin_id=$admin_id");
    mysqli_query($conn, "UPDATE hdb_podcasts
         SET host_voice='$voice_id', voice_rate='$rate', video_media='$media_type'
         WHERE campaign_id=$campaign_id AND admin_id=$admin_id");
    $rows = mysqli_affected_rows($conn);
    echo json_encode(['success'=>true, 'podcasts_updated'=>$rows]); exit;
}

// ── AJAX: Apply user_settings animation to all captions of a new podcast ─────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'init_captions_from_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $pid = (int)($_POST['podcast_id'] ?? 0);
    if (!$pid) { echo json_encode(['success'=>false,'message'=>'No podcast_id']); exit; }

    // Load user settings — specifically animation_style and animation_speed
    $anim_style = 'none';
    $anim_speed = 1.0;

    $ust = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_user_settings'");
    if ($ust && mysqli_num_rows($ust) > 0) {
        $uq = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id=$admin_id LIMIT 1");
        if ($uq && $ur = mysqli_fetch_assoc($uq)) {
            // Prefer the new explicit columns, fall back to legacy caption_style/caption_speed
            $anim_style = $ur['animation_style'] ?? $ur['caption_style'] ?? $anim_style;
            $anim_speed = (float)($ur['animation_speed'] ?? $ur['caption_speed'] ?? $anim_speed);
        }
    }

    // Nothing to do if defaults
    if ($anim_style === 'none' && $anim_speed == 1.0) {
        echo json_encode(['success'=>true,'updated'=>0,'message'=>'No custom settings to apply']);
        exit;
    }

    $safe_style = mysqli_real_escape_string($conn, $anim_style);
    $safe_speed = (float)$anim_speed;

    // Update all captions belonging to this podcast
    // Exception: do NOT overwrite text_content — we're only touching animation fields
    $ok = mysqli_query($conn,
        "UPDATE hdb_captions
         SET animation_style='$safe_style', animation_speed=$safe_speed
         WHERE podcast_id=$pid");

    $updated = $ok ? (int)mysqli_affected_rows($conn) : 0;
    echo json_encode([
        'success'       => (bool)$ok,
        'updated'       => $updated,
        'animation_style' => $anim_style,
        'animation_speed' => $anim_speed,
    ]);
    exit;
}

$js_is_free_trial = $is_free_trial ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Script Wizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;  --mid-blue: #143b63;   --accent: #5fd1ff;
  --purple: #8b5cf6;     --purple-lt: #ede9fe;   --green: #10b981;
  --orange: #f59e0b;     --orange-lt: #fef3c7;   --text: #1e293b;
  --muted: #64748b;      --border: #e2e8f0;       --bg: #f8fafc;
  --card: #ffffff;       --shadow: 0 4px 12px rgba(0,0,0,0.08);
}
body { font-family:'Inter',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; display:flex; flex-direction:column; }
.vidora-header { display:flex; justify-content:space-between; align-items:center; padding:12px 20px; background:linear-gradient(90deg,#0f2a44,#143b63); color:#fff; box-shadow:0 3px 10px rgba(0,0,0,0.15); position:sticky; top:0; z-index:1000; }
.brand-link { text-decoration:none; display:flex; align-items:center; gap:8px; }
.brand-icon { font-size:24px; }
.brand-name { font-size:18px; font-weight:700; }
.brand-video { color:#fff; }
.brand-vizard { color:#5fd1ff; }
.page-wrap { flex:1; display:flex; align-items:flex-start; justify-content:center; padding:28px 16px 48px; }
.wiz-card { background:var(--card); border-radius:16px; border:1px solid var(--border); box-shadow:var(--shadow); width:100%; max-width:600px; overflow:hidden; }
.wiz-card-header { padding:18px 24px 16px; background:linear-gradient(90deg,#0f2a44,#143b63); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:12px; }
.wiz-card-header h1 { font-size:20px; font-weight:700; color:#fff; margin:0; }
.wiz-card-header p { font-size:13px; color:rgba(255,255,255,.7); margin:2px 0 0; }
.gear-btn { width:36px; height:36px; border-radius:50%; border:1px solid var(--border); background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:17px; color:rgba(255,255,255,.8); transition:all .15s; flex-shrink:0; }
.gear-btn:hover { background:var(--purple-lt); border-color:var(--purple); color:var(--purple); }
.wiz-card-body { padding:24px; }
.settings-bar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; background:#f7f9fc; border:1px solid var(--border); border-radius:8px; padding:8px 12px; margin-bottom:20px; cursor:pointer; transition:border-color .15s; }
.settings-bar:hover { border-color:var(--purple); }
.settings-bar-label { font-size:11px; font-weight:700; color:#aaa; text-transform:uppercase; letter-spacing:.06em; margin-right:2px; white-space:nowrap; }
.settings-bar-edit { font-size:11px; color:var(--purple); margin-left:auto; white-space:nowrap; }
.s-pill { font-size:11px; background:var(--purple-lt); color:#6d28d9; border-radius:4px; padding:2px 7px; white-space:nowrap; }
.prog-track { height:4px; background:var(--border); border-radius:2px; margin-bottom:24px; overflow:hidden; }
.prog-fill { height:100%; background:linear-gradient(90deg,var(--dark-blue),var(--purple)); border-radius:2px; transition:width .4s ease; }
.step-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:6px; }
.step-q { font-size:20px; font-weight:700; color:var(--dark-blue); margin-bottom:18px; line-height:1.35; }
/* Inline header row for niche/category steps */
.step-q-row { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px; }
.step-q-row .step-q-text { font-size:17px; font-weight:700; color:var(--dark-blue); line-height:1.3; flex:1; margin:0; }
.step-q-row .step-q-actions { display:flex; align-items:center; gap:6px; flex-shrink:0; }
.step-q-row .more-btn-sm { display:inline-flex; align-items:center; gap:4px; padding:6px 12px; background:#fff; border:1.5px dashed var(--purple); border-radius:8px; color:var(--purple); font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.step-q-row .more-btn-sm:hover { background:var(--purple-lt); border-style:solid; }
.step-q-row .more-btn-sm:disabled { opacity:.5; cursor:not-allowed; }
.step-q-row .more-btn-sm .spin { display:inline-block; animation:spin .8s linear infinite; }
.step-q-row .cont-btn-sm { display:inline-flex; align-items:center; padding:7px 14px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
.step-q-row .cont-btn-sm:hover { box-shadow:0 3px 8px rgba(15,42,68,.3); }
.step-q-row .cont-btn-sm:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }
.opts { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
.opt { padding:9px 16px; border:1.5px solid var(--border); border-radius:8px; background:#fff; color:var(--text); font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; line-height:1; }
.opt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.opt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.opt.multi-sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.my-niches-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.my-niches-label::before { content:'⭐'; font-size:12px; }
.divider-label { font-size:11px; font-weight:700; color:#bbb; text-transform:uppercase; letter-spacing:.07em; margin:10px 0 8px; }
.more-btn { display:inline-flex; align-items:center; gap:5px; padding:10px 16px; background:#fff; border:1.5px dashed var(--purple); border-radius:10px; color:var(--purple); font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.more-btn:hover { background:var(--purple-lt); border-style:solid; }
.more-btn:disabled { opacity:.5; cursor:not-allowed; }
.more-btn .spin { display:inline-block; animation:spin .8s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.custom-row { display:flex; gap:8px; margin-bottom:6px; }
.custom-in { flex:1; padding:9px 12px; font-size:13px; border:1.5px solid var(--border); border-radius:8px; color:var(--text); outline:none; transition:border-color .15s; background:#fff; }
.custom-in:focus { border-color:var(--purple); }
.custom-add { padding:9px 14px; font-size:13px; background:#f5f5f5; border:1.5px solid var(--border); border-radius:8px; color:var(--muted); cursor:pointer; white-space:nowrap; transition:all .15s; }
.custom-add:hover { background:var(--purple-lt); color:var(--purple); border-color:var(--purple); }
.loading { display:flex; align-items:center; gap:10px; color:var(--muted); font-size:14px; padding:16px 0; }
.dot { width:6px; height:6px; border-radius:50%; background:var(--purple); animation:blink 1.2s ease-in-out infinite; }
.dot:nth-child(2){animation-delay:.2s} .dot:nth-child(3){animation-delay:.4s}
@keyframes blink { 0%,80%,100%{opacity:.2} 40%{opacity:1} }
.nav { display:flex; align-items:center; justify-content:space-between; margin-top:24px; padding-top:20px; border-top:1px solid #f0f0f0; }
.nav-back { font-size:13px; color:var(--muted); cursor:pointer; padding:8px 0; background:none; border:none; transition:color .15s; }
.nav-back:hover { color:var(--text); }
.nav-next { padding:11px 28px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; transition:all .15s; }
.nav-next:hover { background:linear-gradient(135deg,var(--mid-blue),#1e4a7a); box-shadow:0 4px 12px rgba(15,42,68,.3); }
.nav-next:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }
.summary { background:#f7f9fc; border:1px solid var(--border); border-radius:12px; padding:16px 20px; margin-top:4px; }
.sum-section { font-size:10px; font-weight:700; color:#bbb; text-transform:uppercase; letter-spacing:.07em; margin:14px 0 6px; }
.sum-section:first-child { margin-top:0; }
.sum-row { display:flex; justify-content:space-between; align-items:flex-start; padding:7px 0; border-bottom:1px solid #eef0f3; font-size:13px; gap:16px; }
.sum-row:last-child { border-bottom:none; }
.sum-key { color:var(--muted); white-space:nowrap; }
.sum-val { color:var(--dark-blue); font-weight:600; text-align:right; }
.done-title { font-size:22px; font-weight:700; color:var(--dark-blue); margin-bottom:6px; }
.done-sub { font-size:13px; color:var(--muted); margin-bottom:18px; }
.gen-btn { margin-top:16px; width:100%; padding:14px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; transition:all .15s; }
.gen-btn:hover { background:linear-gradient(135deg,var(--mid-blue),#1e4a7a); box-shadow:0 4px 12px rgba(15,42,68,.3); }
.gen-btn:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; box-shadow:none; }
.restart-btn { margin-top:10px; width:100%; padding:11px; background:#fff; color:var(--muted); border:1.5px solid var(--border); border-radius:10px; font-size:14px; font-weight:500; cursor:pointer; transition:all .15s; }
.restart-btn:hover { border-color:var(--purple); color:var(--purple); }
.script-box { background:#f7f9fc; border:1px solid var(--border); border-radius:10px; padding:16px 20px; font-size:14px; line-height:1.8; color:var(--text); white-space:pre-wrap; margin-top:4px; }
.copy-btn { margin-top:8px; width:100%; padding:10px; background:#fff; color:var(--purple); border:1.5px solid var(--purple); border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; transition:all .15s; }
.copy-btn:hover { background:var(--purple-lt); }
.free-trial-badge { display:inline-flex; align-items:center; gap:6px; background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:20px; padding:4px 12px; font-size:12px; font-weight:600; margin-bottom:12px; }
.settings-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:200; align-items:center; justify-content:center; padding:20px; }
.settings-overlay.open { display:flex; }
.settings-panel { background:#fff; border-radius:16px; padding:28px; width:100%; max-width:500px; max-height:90vh; overflow-y:auto; box-shadow:0 12px 40px rgba(0,0,0,0.2); }
.settings-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
.settings-title { font-size:17px; font-weight:700; color:var(--dark-blue); }
.settings-close { background:none; border:none; font-size:22px; color:var(--muted); cursor:pointer; padding:0 4px; }
.settings-close:hover { color:var(--text); }
.setting-group { margin-bottom:20px; }
.setting-label { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; margin-bottom:8px; }
.setting-opts { display:flex; flex-wrap:wrap; gap:7px; }
.sopt { padding:7px 13px; border:1.5px solid var(--border); border-radius:7px; background:#fff; color:var(--text); font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
.sopt:hover { border-color:var(--purple); color:var(--purple); background:var(--purple-lt); }
.sopt.sel { background:var(--purple-lt); border-color:var(--purple); color:#5b21b6; font-weight:600; }
.settings-save { margin-top:8px; width:100%; padding:13px; background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue)); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
.settings-hint { font-size:12px; color:#bbb; margin-top:10px; text-align:center; }
.mode-card { flex:1; min-width:180px; border:1.5px solid var(--border); border-radius:16px; padding:22px 18px; background:var(--card); cursor:pointer; transition:all .2s; box-shadow:0 2px 8px rgba(0,0,0,0.04); }
.mode-card:hover { border-color:var(--purple); box-shadow:0 6px 20px rgba(139,92,246,0.12); transform:translateY(-2px); }
.mode-card-icon { font-size:32px; margin-bottom:10px; }
.mode-card-title { font-size:15px; font-weight:700; color:var(--dark-blue); margin-bottom:8px; }
.mode-card-desc { font-size:13px; color:var(--muted); line-height:1.5; margin-bottom:12px; }
.mode-card-badge { display:inline-block; font-size:11px; font-weight:600; padding:4px 10px; border-radius:20px; }
.back-to-menu { background:none; border:none; color:var(--muted); font-size:13px; font-weight:600; cursor:pointer; padding:0; display:inline-flex; align-items:center; gap:4px; transition:color .15s; }
.back-to-menu:hover { color:var(--dark-blue); }
.field-label { display:block; font-size:13px; font-weight:600; color:var(--dark-blue); margin-bottom:6px; }
.camp-progress-bar { background:linear-gradient(135deg,#fef3c7,#fffbeb); border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#92400e; }
.camp-progress-bar strong { color:#78350f; }
.title-grid { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; max-height:320px; overflow-y:auto; padding-right:4px; }
.title-item { display:flex; align-items:flex-start; gap:10px; padding:10px 14px; border:1.5px solid var(--border); border-radius:10px; background:#fff; cursor:pointer; transition:all .15s; font-size:13px; line-height:1.4; }
.title-item:hover { border-color:var(--purple); background:var(--purple-lt); }
.title-item.sel { border-color:var(--purple); background:var(--purple-lt); color:#5b21b6; font-weight:600; }
.title-item .chk { width:18px; height:18px; border:2px solid var(--border); border-radius:4px; flex-shrink:0; margin-top:1px; display:flex; align-items:center; justify-content:center; font-size:11px; transition:all .15s; }
.title-item.sel .chk { background:var(--purple); border-color:var(--purple); color:#fff; }
.title-count-badge { display:inline-flex; align-items:center; gap:6px; background:var(--orange-lt); color:#92400e; border:1px solid #fde68a; border-radius:20px; padding:4px 12px; font-size:12px; font-weight:600; margin-bottom:12px; }
.campaign-result-card { border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:12px; }
.campaign-result-header { padding:12px 16px; background:linear-gradient(135deg,#f8fafc,#f1f5f9); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:8px; cursor:pointer; }
.campaign-result-header:hover { background:var(--purple-lt); }
.campaign-result-body { padding:14px 16px; display:none; }
.campaign-result-body.open { display:block; }
.toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--dark-blue); color:#fff; padding:10px 22px; border-radius:10px; font-size:13px; font-weight:600; z-index:999; transition:opacity .3s; pointer-events:none; }
.site-footer { background:linear-gradient(90deg,#0f2a44,#143b63); color:rgba(255,255,255,.5); padding:14px 20px; font-size:12px; display:flex; justify-content:center; align-items:center; gap:24px; flex-wrap:wrap; }
.site-footer a { color:rgba(255,255,255,.55); text-decoration:none; transition:color .2s; }
.site-footer a:hover { color:var(--accent); }
.footer-brand { font-weight:700; color:var(--accent); }
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
.game-strip{border-top:2px solid #bae6fd;background:#fff;}
.token-bar{display:flex;align-items:center;justify-content:space-between;background:#0c4a6e !important;padding:5px 10px 4px;}
.tk-left{font-size:10px;color:#bae6fd;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.tk-val{font-size:12px;font-weight:700;color:#e0f2fe;}
.tk-msg{font-size:9px;color:#7dd3fc;font-weight:700;min-width:70px;text-align:right;}
/* Arcade */
.arc-bg{background:#f0f9ff !important;padding:6px 8px 8px;color:#0c4a6e !important;}
.arc-tabs{display:flex;gap:3px;margin-bottom:6px;}
.arc-tab{flex:1;padding:5px 2px;border-radius:6px;border:1.5px solid #0284c7;background:#f0f9ff !important;color:#0284c7 !important;font-size:13px;font-weight:700;cursor:pointer;text-transform:uppercase;text-align:center;transition:all .12s;-webkit-tap-highlight-color:transparent;}
.arc-tab:hover{background:#bae6fd;}
.arc-tab.active{background:#0284c7;border-color:#0284c7;color:#fff;}
.arc-panel{display:none;}
.arc-panel.active{display:block;}
.arc-stat{font-size:11px;font-weight:600;color:#0369a1;text-align:center;min-height:14px;margin-bottom:3px;}
.arc-btn{width:100%;padding:6px;border-radius:6px;border:1.5px solid #0284c7;background:#0284c7;color:#fff;font-size:11px;font-weight:700;cursor:pointer;text-transform:uppercase;margin-top:4px;transition:all .1s;}
.arc-btn:hover{background:#0369a1;border-color:#0369a1;}
.arc-btn.primary{background:#0284c7;border-color:#0284c7;color:#fff;}
.arc-btn.primary:hover{background:#0369a1;}
/* ── TTT ── */
.ttt-wrap{display:flex;flex-direction:column;gap:6px;max-width:280px;margin:0 auto;}
.ttt-r1{display:flex;gap:4px;}
.ttt-diff{flex:1;padding:6px 0;border-radius:6px;border:1.5px solid #0284c7;background:#f0f9ff !important;color:#0284c7 !important;font-size:13px;font-weight:700;cursor:pointer;text-align:center;transition:all .1s;}
.ttt-diff:hover{background:#bae6fd !important;}
.ttt-diff.sel{background:#0284c7 !important;border-color:#0284c7;color:#fff !important;}
.ttt-board{display:grid;grid-template-columns:repeat(3,56px);grid-template-rows:repeat(3,56px);gap:0;width:168px;margin:0 auto;border:2px solid #0284c7;border-radius:8px;overflow:hidden;}
.ttt-cell{width:56px;height:56px;border:none;border-right:1px solid #bae6fd;border-bottom:1px solid #bae6fd;background:#f0f9ff !important;color:#0c4a6e !important;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;cursor:pointer;transition:background .1s;-webkit-tap-highlight-color:transparent;user-select:none;}
.ttt-cell:nth-child(3n){border-right:none;}
.ttt-cell:nth-child(n+7){border-bottom:none;}
.ttt-cell:hover:not(.taken){background:#bae6fd !important;}
.ttt-cell.taken{cursor:default;}
.ttt-cell.cx{color:#0284c7 !important;}
.ttt-cell.co{color:#dc2626 !important;}
.ttt-cell.win{background:#bbf7d0 !important;color:#166534 !important;animation:gsPop .3s ease;}
.ttt-r3{display:flex;align-items:center;gap:4px;}
.ttt-sc{flex:1;background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:6px;padding:3px 0;text-align:center;}
.ttt-slbl{font-size:9px;color:#0369a1;text-transform:uppercase;line-height:1.3;font-weight:700;}
.ttt-snum{font-size:16px;font-weight:700;color:#0c4a6e;line-height:1.2;}
.ttt-stat{font-size:11px;font-weight:600;color:#0369a1;flex:2;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ttt-newbtn{padding:6px 12px;border-radius:6px;border:1.5px solid #0284c7;background:#0284c7;color:#fff;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all .1s;}
.ttt-newbtn:hover{background:#0369a1;}
/* ── Word Guess ── */
.wg-word{display:flex;gap:4px;justify-content:center;flex-wrap:wrap;margin:4px 0;}
.wg-ltr{width:26px;height:30px;border-radius:5px;border:2px solid #7dd3fc;background:#f0f9ff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#0c4a6e;text-transform:uppercase;}
.wg-ltr.blank{background:#e0f2fe;border-color:#bae6fd;color:transparent;}
.wg-ltr.hit{background:#bbf7d0;border-color:#16a34a;color:#166534;}
.wg-ltr.pre{background:#dbeafe;border-color:#7dd3fc;color:#0c4a6e;}
.wg-hint{font-size:12px;color:#0369a1;text-align:center;margin-bottom:4px;font-weight:600;}
.wg-tries{font-size:11px;color:#0369a1;text-align:center;margin-bottom:3px;}
.wg-hangman{font-size:26px;text-align:center;margin-bottom:3px;min-height:32px;}
.wg-kb{display:flex;flex-wrap:wrap;gap:2px;justify-content:center;}
.wg-key{min-width:22px;height:24px;padding:0 2px;border-radius:4px;border:1.5px solid #7dd3fc;background:#f0f9ff;color:#0c4a6e;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .1s;-webkit-tap-highlight-color:transparent;}
.wg-key:active{transform:scale(.93);}
.wg-key.used{opacity:.35;cursor:default;}
.wg-key.correct{background:#bbf7d0;border-color:#16a34a;color:#166534;}
.wg-key.wrong{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}
/* ── Emoji ── */
.eg-emojis{font-size:30px;text-align:center;letter-spacing:8px;margin:3px 0 6px;}
.eg-choices{display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:5px;}
.eg-choice{padding:9px 5px;border-radius:7px;border:1.5px solid #7dd3fc;background:#f0f9ff;color:#0c4a6e;font-size:13px;font-weight:600;cursor:pointer;text-align:center;transition:all .1s;-webkit-tap-highlight-color:transparent;line-height:1.4;}
.eg-choice:hover{background:#bae6fd;border-color:#0284c7;}
.eg-choice.correct{background:#bbf7d0;border-color:#16a34a;color:#166534;cursor:default;}
.eg-choice.wrong{background:#fee2e2;border-color:#fca5a5;color:#dc2626;cursor:default;}
.eg-scores{display:flex;gap:4px;}
/* ── Math ── */
.sm-eq{font-size:20px;font-weight:700;color:#0c4a6e;text-align:center;margin:3px 0;font-family:monospace;min-height:26px;}
.sm-timer{height:5px;background:#bae6fd;border-radius:3px;overflow:hidden;margin:3px 0 5px;}
.sm-bar{height:100%;background:#0284c7;transition:width .5s linear;}
.sm-row{display:flex;gap:4px;margin-bottom:4px;align-items:center;}
.sm-inp{flex:1;padding:7px 8px;border-radius:6px;border:2px solid #7dd3fc;background:#f0f9ff;color:#0c4a6e;font-size:16px;font-weight:700;text-align:center;outline:none;}
.sm-inp:focus{border-color:#0284c7;}
.sm-inp::placeholder{color:#7dd3fc;}
.sm-ok{padding:7px 16px;border-radius:6px;border:1.5px solid #0284c7;background:#0284c7;color:#fff;font-size:13px;font-weight:700;cursor:pointer;}
.sm-ok:hover{background:#0369a1;}
.sm-scores{display:flex;gap:4px;}
/* ── Grid ── */
.ms-grid{display:grid;grid-template-columns:repeat(4,34px);grid-template-rows:repeat(4,34px);gap:3px;justify-content:center;}
.ms-cell{width:34px;height:34px;border-radius:6px;border:2px solid #7dd3fc;background:#f0f9ff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#0c4a6e;cursor:pointer;transition:all .1s;-webkit-tap-highlight-color:transparent;user-select:none;}
.ms-cell.given{color:#0284c7;cursor:default;border-color:#0284c7;background:#e0f2fe;}
.ms-cell.sel{background:#bae6fd;border-color:#0284c7;border-width:2.5px;}
.ms-cell.err{background:#fee2e2;border-color:#fca5a5;color:#dc2626;}
.ms-cell.good{background:#bbf7d0;border-color:#16a34a;color:#166534;}
.ms-num{flex:1;height:32px;border-radius:6px;border:1.5px solid #0284c7;background:#0284c7;color:#fff;font-size:15px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .12s;-webkit-tap-highlight-color:transparent;}
.ms-num:hover{background:#0369a1;border-color:#0369a1;}
@keyframes gsPop{0%{transform:scale(.7)}60%{transform:scale(1.1)}100%{transform:scale(1)}}

.setting-row { display:flex; align-items:center; justify-content:space-between; padding:13px 16px; border-bottom:1px solid var(--border); cursor:pointer; transition:background .13s; gap:12px; }
.setting-row:last-child { border-bottom:none; }
.setting-row:hover { background:var(--purple-lt); }
.setting-row-left { display:flex; flex-direction:column; gap:3px; }
.setting-row-key { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.07em; }
.setting-row-val { font-size:14px; font-weight:600; color:var(--dark-blue); }
.setting-row-arrow { font-size:16px; color:var(--muted); flex-shrink:0; }


.settings-ok-mode .settings-save { display: none; }
.settings-ok-btn { display: none; margin-top: 8px; width: 100%; padding: 13px; background: linear-gradient(135deg, var(--dark-blue), var(--mid-blue)); color: #fff; border: none; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }
.settings-ok-mode .settings-ok-btn { display: block; }
.settings-ok-mode .settings-hint { display: none; }

.opt-del { 
    display:inline-flex; align-items:center; justify-content:center;
    width:14px; height:14px; margin-left:6px; border-radius:50%;
    font-size:10px; line-height:1; color:var(--muted); 
    background:transparent; border:none; cursor:pointer;
    transition:all .15s; flex-shrink:0; vertical-align:middle;
    padding:0;
}
.opt-del:hover { background:#fee2e2; color:#dc2626; }
#step-body textarea#opts-wrap-cust-in {
    display: block;
    min-height: 72px;
    height: 72px;
    width: 100%;
    resize: vertical;
    box-sizing: border-box;
}


.opt.used-idea {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f3f4f6;
    border-color: #d1d5db;
}

.opt.used-idea:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: var(--text);
}
</style>
</head>
<body> 

<header class="vidora-header">
  <a class="brand-link" href="index.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></span>
  </a>
  <a href="vizard_browser.php" style="color:rgba(255,255,255,.75);font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px;transition:color .2s;" onmouseover="this.style.color='#5fd1ff'" onmouseout="this.style.color='rgba(255,255,255,.75)'">
    ← Browser
  </a>
</header>

<!-- Settings overlay -->
<!-- Settings: main list overlay -->
<div class="settings-overlay" id="settingsOverlay" onclick="overlayClick(event)">
  <div class="settings-panel" id="settingsPanel">
    <div class="settings-header">
      <span class="settings-title">⚙️ Video Settings</span>
      <button class="settings-close" onclick="closeSettings()">✕</button>
    </div>
    <div id="settings-list">
      <!-- rows injected by renderSettingsList() -->
    </div>
    <div class="settings-hint">Tap any setting to change it</div>
	 <button class="settings-ok-btn" onclick="closeSettingsOK()">✓ OK — Let's go!</button>
  </div>
</div>

<!-- Settings: single-setting focused overlay -->
<div class="settings-overlay" id="settingFocusOverlay" onclick="settingFocusOverlayClick(event)">
  <div class="settings-panel" id="settingFocusPanel" style="max-width:420px;">
    <div class="settings-header">
      <span class="settings-title" id="settingFocusTitle"></span>
      <button class="settings-close" onclick="closeSettingFocus()">✕</button>
    </div>
    <div style="padding:4px 0 8px;">
      <div class="setting-opts" id="settingFocusOpts"></div>
    </div>
    <button class="settings-save" onclick="saveSettingFocus()">✓ Done</button>
  </div>
</div>

<div class="page-wrap">

  <!-- MODE SELECT -->
  <div id="modeSelect" style="width:100%; max-width:700px;">
    <div class="wiz-card" style="width:100%;max-width:700px;">
      <div class="wiz-card-header">
        <div><h1 style="margin:0;font-size:20px;font-weight:700;color:#fff;">Step 1: Select Script Generation Method</h1><p style="font-size:13px;color:rgba(255,255,255,.7);margin:2px 0 0;">Choose how you'd like to create your video script</p></div>
      </div>
      <div class="wiz-card-body" style="padding:20px 24px;">
    <div style="display:flex; gap:16px; flex-wrap:wrap;">
      <div class="mode-card" onclick="selectMode('wizard')">
        <div class="mode-card-icon">✨</div>
        <div class="mode-card-title">Generate Video Script</div>
        <div class="mode-card-desc">Answer a few questions — AI writes a complete, ready-to-use video script for you.</div>
        <div class="mode-card-badge" style="background:#ede9fe; color:#6d28d9;">Best for new ideas</div>
      </div>
      <div class="mode-card" onclick="selectMode('campaign')">
        <div class="mode-card-icon">📅</div>
        <div class="mode-card-title">Generate Campaign</div>
        <div class="mode-card-desc">Plan a week, month or custom period. AI generates multiple scripts at once.</div>
        <div class="mode-card-badge" style="background:#fef3c7; color:#92400e;">Best for content planning</div>
      </div>
      <div class="mode-card" onclick="selectMode('content')">
        <div class="mode-card-icon">📄</div>
        <div class="mode-card-title">I Have Content</div>
        <div class="mode-card-desc">Paste your own text or script — it gets formatted and split into scenes automatically.</div>
        <div class="mode-card-badge" style="background:#dbeafe; color:#1e40af;">Best for existing content</div>
      </div>
    </div>
      </div>
    </div>
  </div>

  <!-- SINGLE SCRIPT WIZARD -->
  <div id="modeWizard" style="display:none; width:100%; max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div><h1 id="cardTitle">✨ Generate Video Script</h1><p id="cardSubtitle">Answer a few questions to generate your video script</p></div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:16px;display:flex;align-items:center;gap:10px;">
          <button class="back-to-menu" onclick="goToMenu()">← All options</button>
          <button class="nav-back" id="backBtn" onclick="goBack()" style="visibility:hidden;">← Back</button>
        </div>
        <div class="settings-bar" id="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="settings-bar-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div class="prog-track"><div class="prog-fill" id="prog"></div></div>
        <div id="step-label" class="step-label"></div>
        <div class="step-q-row" id="step-q-row">
          <div class="step-q-text" id="step-q"></div>
          <div class="step-q-actions" id="step-q-actions" style="display:none;"></div>
        </div>
        <div id="step-body"></div>
        <div class="nav" id="nav-bar" style="display:none;">
          <button class="nav-next" id="nextBtn" disabled onclick="goNext()">Continue →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- CAMPAIGN WIZARD -->
  <div id="modeCampaign" style="display:none; width:100%; max-width:640px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div><h1 id="campCardTitle">📅 Generate Campaign</h1><p id="campCardSubtitle">Build your full content calendar in one go</p></div>
        <button class="gear-btn" onclick="openSettings()" title="Video Settings">⚙</button>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:16px;"><button class="back-to-menu" onclick="goToMenu()">← All options</button></div>
        <div class="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="camp-settings-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div class="prog-track"><div class="prog-fill" id="camp-prog"></div></div>
        <div id="camp-step-label" class="step-label"></div>
        <div class="step-q-row" id="camp-step-q-row">
          <div class="step-q-text" id="camp-step-q"></div>
          <div class="step-q-actions" id="camp-step-q-actions" style="display:none;"></div>
        </div>
        <div id="camp-step-body"></div>
        <div class="nav" id="camp-nav-bar">
          <button class="nav-back" id="campBackBtn" onclick="campGoBack()">← Back</button>
          <button class="nav-next" id="campNextBtn" disabled onclick="campGoNext()">Continue →</button>
        </div>
      </div>
    </div>
  </div>

  <!-- I HAVE CONTENT -->
  <div id="modeContent" style="display:none; width:100%; max-width:600px;">
    <div class="wiz-card">
      <div class="wiz-card-header">
        <div><h1>📄 I Have Content</h1><p>Paste your script — AI formats it into scenes</p></div>
      </div>
      <div class="wiz-card-body">
        <div style="margin-bottom:20px;"><button class="back-to-menu" onclick="goToMenu()">← All options</button></div>
        <div class="settings-bar" onclick="openSettings()">
          <span class="settings-bar-label">Settings</span>
          <span id="content-settings-pills"></span>
          <span class="settings-bar-edit">Edit ›</span>
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Video Title</label>
          <input type="text" id="content-title" placeholder="e.g. 5 Ways to Reduce Stress" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; outline:none;">
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Your Script / Story</label>
          <textarea id="content-script" rows="8" placeholder="Paste your script, blog post, or story here…" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; resize:vertical; outline:none; line-height:1.6;"></textarea>
        </div>
        <div style="margin-bottom:16px;">
          <label class="field-label">Call to Action</label>
          <input type="text" id="content-cta" placeholder="e.g. Follow for more tips" value="Follow for more tips" style="width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; outline:none;">
        </div>
        <div id="content-script-output"></div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:4px;">
          <button class="nav-next" id="content-process-btn" onclick="processMyContent()" style="flex:1; min-width:160px;">📝 Process Content</button>
        </div>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

<footer class="site-footer">
  <span class="footer-brand">🎬 VideoVizard</span>
  <a href="index.php">Home</a>
  <a href="wizard.php">Script Generator</a>
  <a href="settings.php">Settings</a>
  <span>© 2025 VideoVizard</span>
</footer>

<script>
// ═══════════════════════════════════════════════════════════════════════════════
// CONSTANTS & GLOBALS
// ═══════════════════════════════════════════════════════════════════════════════
const AZURE_BREAK     = '<break time="200ms"/>';
const SCENE_SEPARATOR = '[SCENE BREAK]';
const IS_FREE_TRIAL   = <?= $js_is_free_trial ?>;

// ── Wizard step definitions ───────────────────────────────────────────────────
const DEFAULT_NICHES = [
    'Hypnotherapy','Real Estate','Hair Dressing','Nail Parlour','Financial Adviser',
    'Physiotherapist','Life Coaching','Personal Training','Dentistry','Mortgage Broker'
];

const STEPS = [
    { key:'niche', label:'Step 1 of 6', q:'Select your niche or profession',
      type:'niche-select', opts: DEFAULT_NICHES,
      morePrompt: (existing) =>
        `You are a content strategy expert. List 10 MORE professional niche ideas for short social media videos.
         Do NOT repeat any of these: ${existing.join(', ')}.
         Return ONLY a valid JSON array of short niche name strings.`
    },
    { key:'topic', label:'Step 2 of 6', q:'Select a category',
      type:'ai', aiUrl:'generate_categories.php', aiPayload:['niche'], resKey:'categories',
      morePrompt: (existing, ans) =>
        `You are an expert in the niche: "${ans.niche}".
List 8 MORE broad INDUSTRY SUBCATEGORIES within this niche.
A category must represent a distinct segment, specialization, problem area, or target group within the niche — NOT content types, themes, or video ideas.
If the niche is problem-based, categories should be problems or outcomes (e.g., Anxiety, Depression, Weight Loss).
If the niche is industry-based, categories should be market segments or service types (e.g., Residential Real Estate, Commercial Real Estate, Luxury Properties).
Do NOT include anything like tips, trends, strategies, advice, or content formats.
Do NOT repeat any of these: ${existing.join(', ')}.
Return ONLY a valid JSON array of category name strings, no extra text.`
    },
    { key:'title', label:'Step 3 of 6', q:'Choose a video idea',
      type:'ai', aiUrl:'generate_titles.php', aiPayload:['niche','topic'], resKey:'titles',
      morePrompt: (existing, ans) =>
        `You are an expert short-form video content creator for the niche "${ans.niche}", category "${ans.topic}".
         Generate 8 MORE specific, engaging video topic ideas for Reels/TikTok/Shorts.
         Do NOT repeat any of these: ${existing.join(', ')}.
         Return ONLY a valid JSON array of title strings.`
    },
    { key:'angle', label:'Step 4 of 6', q:'What hook or angle will you use?',
      type:'opts+more',
      opts:['Quick Hacks','Step-by-Step','Common Mistakes','Surprising Secrets','Before & After',
            'Myth Busting','Did You Know?','Storytime','Controversial Opinion','Top 5 List',
            'FAQs Answered','Behind the Scenes','Client Transformation','Warning / What to Avoid','Industry Trends'],
      morePrompt: (existing) =>
        `You are an expert short-form video content strategist.
         List 10 MORE creative hook or angle ideas for short social media videos.
         Do NOT repeat any of these: ${existing.join(', ')}.
         Return ONLY a valid JSON array of short hook/angle name strings.`
    },
    { key:'duration', label:'Step 5 of 6', q:'How long is this video?',
      type:'duration-select',
      opts: ['15 seconds','30 seconds','45 seconds','60 seconds','90 seconds','120 seconds','120+ seconds']
    },
    { key:'cta', label:'Step 6 of 6', q:'What should viewers do next?',
  type:'cta-select',
  opts:['Follow for More','Subscribe','Book a Free Call','Visit Website','Download Guide']
},
];

const WIZARD_LABELS = {
    niche:'Niche', topic:'Category', title:'Video Idea',
    angle:'Angle / Hook', duration:'Duration', cta:'Call to Action'
};

// ── Campaign step definitions ─────────────────────────────────────────────────
const CAMP_STEPS = [
    { key:'camp_goal', label:'Campaign Step 1 of 8', q:'What is your campaign goal?', type:'opts',
      opts:['Brand Awareness','Lead Generation','Product Launch','Education & Tips','Community Building','Sales & Promotions','Event Promotion','Trust Building'] },
    { key:'camp_niche', label:'Campaign Step 2 of 8', q:'Select your niche or profession', type:'niche-select',
      opts: DEFAULT_NICHES,
      morePrompt: (existing) => `List 10 MORE professional niche ideas for social media video campaigns. Do NOT repeat: ${existing.join(', ')}. Return ONLY a valid JSON array of strings.` },
    { key:'camp_category', label:'Campaign Step 3 of 8', q:'Select a content category',
      type:'ai', aiUrl:'generate_categories.php', aiPayload:['camp_niche'], resKey:'categories',
      payloadMap:{camp_niche:'niche'},
      morePrompt: (existing, ans) =>
        `You are an expert in the niche: "${ans.camp_niche}".
List 8 MORE broad INDUSTRY SUBCATEGORIES within this niche.
A category must represent a distinct segment, specialization, problem area, or target group — NOT content types, themes, or video ideas.
If the niche is problem-based, categories should be problems or outcomes (e.g., Anxiety, Depression, Weight Loss).
If the niche is industry-based, categories should be market segments or service types (e.g., Residential Real Estate, Commercial Real Estate).
Do NOT include anything like tips, trends, strategies, advice, or content formats.
Do NOT repeat any of these: ${existing.join(', ')}.
Return ONLY a valid JSON array of category name strings, no extra text.` },
    { key:'camp_languages', label:'Campaign Step 4 of 8', q:'Which languages will you post in?', type:'multi',
      opts:['English','Arabic','Spanish','French','Urdu','Hindi','Gujarati','Punjabi','Tamil','Mandarin Chinese','Farsi','Bengali','Portuguese','Russian','Japanese','Korean'] },
    { key:'camp_duration', label:'Campaign Step 5 of 8', q:'How long is your campaign?', type:'camp-duration',
      opts:['1 Week (7 days)','2 Weeks (14 days)','1 Month (30 days)','Custom'] },
    { key:'camp_posts_per_day', label:'Campaign Step 6 of 9', q:'How often will you post?', type:'opts',
      opts:['1 post every 3 days','1 post every 2 days','1 post per day','2 posts per day','3 posts per day'] },
    { key:'camp_start_date', label:'Campaign Step 7 of 9', q:'When should your campaign start?', type:'start-date' },
    { key:'camp_video_length', label:'Campaign Step 8 of 10', q:'How long is each video?',
      type:'duration-select',
      opts: ['15 seconds','30 seconds','45 seconds','60 seconds','90 seconds','120 seconds','120+ seconds'] },
    { key:'camp_production', label:'Campaign Step 9 of 10', q:'Voice, speed & media settings', type:'production-setup' },
    { key:'camp_titles', label:'Campaign Step 10 of 10', q:'Select the video titles for your campaign', type:'title-select',
      hint:'AI will generate title suggestions based on your niche and category.' }
];

// ── Settings definitions ──────────────────────────────────────────────────────
// WITH
const SETTING_DEFS = {
    language:  { opts:['English','Arabic','Spanish','French','Urdu','Hindi','Gujarati','Punjabi','Tamil','Mandarin Chinese','Farsi','Bengali','Portuguese','Russian','Japanese','Korean'], def:'English' },
    reel_type: { opts:['Standard','B-Roll (Voiceover)','Podcast Style','Talking Head'], def:'Standard' },
    objective: { opts:['Educate','Inspire','Entertain','Inform','Build Trust'], def:'Educate' },
    audience:  { opts:['Complete Beginners','Intermediate Learners','Professionals','General Public','Business Owners'], def:'General Public' },
};
const SETTING_LABELS = { language:'Language', reel_type:'Reel Type', objective:'Objective', audience:'Audience' };

// ── Language name → ISO code lookup ──────────────────────────────────────────
// Used everywhere we need to convert settings.language (display name) to a
// lang_code for TTS, DB storage and AI prompts.
const LANG_CODE_MAP = {
    'english':          'en',
    'arabic':           'ar',
    'spanish':          'es',
    'french':           'fr',
    'urdu':             'ur',
    'hindi':            'hi',
    'gujarati':         'gu',
    'punjabi':          'pa',
    'tamil':            'ta',
    'mandarin chinese': 'zh',
    'mandarin':         'zh',
    'farsi':            'fa',
    'bengali':          'bn',
    'portuguese':       'pt',
    'russian':          'ru',
    'japanese':         'ja',
    'korean':           'ko',
};
function langCodeFromName(name) {
    return LANG_CODE_MAP[(name || 'english').toLowerCase().trim()] || 'en';
}
// ── State vars ────────────────────────────────────────────────────────────────
let settings = {}, cur = 0, ans = {}, stepOpts = {};
let campCur = 0, campAns = {}, campStepOpts = {}, campSelectedTitles = [];
let userNiches     = [];
let userCategories = {};
let userVideoIdeas = [];
let userAngles     = { my: [], common: [] };

// ═══════════════════════════════════════════════════════════════════════════════
// UTILITY FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════
function esc(s)    { return String(s).replace(/"/g, '&quot;'); }
function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function splitIntoScenes(script) {
    if (!script) return '';
    let s = script.replace(/[\u00a0\t]+/g, ' ');
    if (s.includes(SCENE_SEPARATOR)) return s.split(SCENE_SEPARATOR).map(x=>x.trim()).filter(Boolean).join('\n');
    if (s.includes('\n')) return s.split('\n').map(x=>x.trim()).filter(Boolean).join('\n');
    const delim = '\x00';
    s = s.replace(/\.\s+/g,'.'+delim).replace(/!\s+/g,'!'+delim).replace(/\?\s+/g,'?'+delim);
    return s.split(delim).map(x=>x.trim()).filter(Boolean).join('\n');
}

function enforceSceneBreaks(script) {
    if (!script) return '';
    return script.split('\n').map(scene => {
        const t = scene.trim();
        if (!t) return '';
        return t.replace(/<break[^/]*\/>/gi, '').trimEnd() + ' ' + AZURE_BREAK;
    }).filter(Boolean).join('\n');
}

function showToast(msg) {
    const t = Object.assign(document.createElement('div'), { className:'toast', textContent:msg });
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 1800);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DB SAVE/LOAD — niches, categories, video ideas
// ═══════════════════════════════════════════════════════════════════════════════
async function loadUserNiches() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_niches');
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        userNiches = d.niches || [];
        // Store common niches for use in renderNicheSelect
        window._commonNiches  = d.common_niches || [];
        // Flag: false = non-internal user, only show their own niches, no DEFAULT_NICHES fallback
        window._isInternalUser = d.is_internal === true;
    } catch(e) { userNiches = []; window._commonNiches = []; window._isInternalUser = false; }
}

async function saveNicheToDB(nicheName, isAiGenerated, storeAsCommon = false) {
    try {
        const fd = new FormData();
        fd.append('ajax_action',     'save_niche');
        fd.append('niche_name',      nicheName);
        fd.append('is_ai_generated', isAiGenerated ? 1 : 0);
        fd.append('store_as_common', storeAsCommon ? 1 : 0);
        await fetch(location.href, { method:'POST', body:fd });
    } catch(e) {}
}
// Bulk-save all AI niches as common (admin_id=0) so next user skips AI call
async function bulkSaveCommonNiches(niches) {
    for (const n of niches) {
        await saveNicheToDB(n, true, true);
    }
}
	
async function loadUserCategories(nicheName) {
    if (!nicheName) return [];
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_categories');
        fd.append('niche_name',  nicheName);
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        userCategories[nicheName] = d.categories || [];
        // Store common categories separately
        if (!window._commonCategories) window._commonCategories = {};
        window._commonCategories[nicheName] = d.common_categories || [];
        return userCategories[nicheName];
    } catch(e) { return []; }
}

async function saveCategoryToDB(nicheName, categoryName, isAiGenerated, storeAsCommon = false) {
    if (!nicheName || !categoryName) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action',     'save_category');
        fd.append('niche_name',      nicheName);
        fd.append('category_name',   categoryName);
        fd.append('is_ai_generated', isAiGenerated ? 1 : 0);
        fd.append('store_as_common', storeAsCommon ? 1 : 0);
        await fetch(location.href, { method:'POST', body:fd });
    } catch(e) {}
}
async function bulkSaveCommonCategories(nicheName, categories) {
    for (const c of categories) await saveCategoryToDB(nicheName, c, true, true);
}

// Global variables for video ideas pagination
let videoIdeasCurrentPage = 1;
let videoIdeasTotalCount = 0;
let videoIdeasHasMore = false;
let videoIdeasNiche = '';
let videoIdeasCategory = '';
let videoIdeasMyList = [];
let videoIdeasCommonList = [];
let videoIdeasAiSuggestions = []; // Store AI suggestions separately
let videoIdeasShowAi = false; // Toggle between my ideas and AI suggestions

async function loadUserVideoIdeas(nicheName, categoryName, page = 1) {
    if (!nicheName || !categoryName) return [];
    
    try {
        const fd = new FormData();
        fd.append('ajax_action',   'get_user_video_ideas');
        fd.append('niche_name',    nicheName);
        fd.append('category_name', categoryName);
        fd.append('page',          page);
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        
        if (d.success) {
            // ✅ IMPORTANT: Assign to global userVideoIdeas
            if (page === 1) {
                userVideoIdeas = d.ideas || [];  // ← THIS IS CRITICAL
                videoIdeasMyList = d.ideas || [];
                videoIdeasCommonList = d.common_ideas || [];
                videoIdeasTotalCount = d.total_my || 0;
                videoIdeasHasMore = d.has_more || false;
            } else {
                userVideoIdeas = [...userVideoIdeas, ...(d.ideas || [])];
                videoIdeasMyList = [...videoIdeasMyList, ...(d.ideas || [])];
                videoIdeasHasMore = d.has_more || false;
            }
            
            window._usedVideoIdeasLower = d.used_titles || [];
            return userVideoIdeas;
        }
        return [];
    } catch(e) { 
        console.error('Error loading video ideas:', e);
        return []; 
    }
}

async function loadAiSuggestions(nicheName, categoryName) {
    const btn = document.getElementById('ai-suggestions-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spin">⟳</span> Loading AI suggestions...';
    }
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_ai_video_suggestions');
        fd.append('niche_name', nicheName);
        fd.append('category_name', categoryName);
        
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        
        if (d.success && d.suggestions) {
            videoIdeasAiSuggestions = d.suggestions;
            videoIdeasShowAi = true;
            renderVideoIdeaList();
        } else {
            showToast('Could not load AI suggestions');
        }
    } catch(e) {
        showToast('Error loading AI suggestions: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '🤖 AI Suggestions';
        }
    }
}

function showMyIdeas() {
    videoIdeasShowAi = false;
    renderVideoIdeaList();
}

function renderVideoIdeaList() {
    const body = document.getElementById('step-body');
    if (!body) return;
    
    const niche = videoIdeasNiche;
    const category = videoIdeasCategory;
    const usedLower = window._usedVideoIdeasLower || [];
    
    // Filter out used ideas
    const freshMyIdeas = videoIdeasMyList.filter(i => !usedLower.includes(i.toLowerCase()));
    const freshCommonIdeas = videoIdeasCommonList.filter(i => !usedLower.includes(i.toLowerCase()));
    
    let html = '';
    
    if (!videoIdeasShowAi) {
        // Show My Ideas section
        if (freshMyIdeas.length > 0) {
            html += `<div class="my-niches-label">📝 My Video Ideas (${videoIdeasTotalCount} total)</div>
                     <div class="opts" id="my-ideas-wrap" style="max-height: 300px; overflow-y: auto;">`;
            freshMyIdeas.forEach(idea => {
                html += `<div class="opt${window._selectedVideoIdea === idea ? ' sel' : ''}" 
                              data-v="${esc(idea)}" data-source="user" 
                              onclick="selectVideoIdea(this, '${esc(idea)}')">
                             ${idea}
                         </div>`;
            });
            html += `</div>`;
            
            // Load more button
            if (videoIdeasHasMore) {
                html += `<button class="more-btn" id="load-more-ideas-btn" onclick="loadMoreVideoIdeas()" 
                                style="margin: 8px 0; width: 100%;">
                            📖 Load More Ideas (${videoIdeasMyList.length}/${videoIdeasTotalCount})
                        </button>`;
            }
            
            if (freshCommonIdeas.length > 0) {
                html += `<div class="divider-label">📚 Common Ideas</div>
                         <div class="opts" id="common-ideas-wrap" style="max-height: 200px; overflow-y: auto;">`;
                freshCommonIdeas.forEach(idea => {
                    html += `<div class="opt${window._selectedVideoIdea === idea ? ' sel' : ''}" 
                                  data-v="${esc(idea)}" data-source="common"
                                  onclick="selectVideoIdea(this, '${esc(idea)}')">
                                 ${idea}
                             </div>`;
                });
                html += `</div>`;
            }
        } else {
            html += `<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#92400e;margin-bottom:12px;">
                      💡 No video ideas yet. Click <strong>AI Suggestions</strong> to generate ideas or add your own below.
                    </div>`;
        }
        
        // AI Suggestions button
        html += `<button class="more-btn" id="ai-suggestions-btn" 
                        data-niche="${esc(niche)}" data-category="${esc(category)}"
                        style="margin: 8px 0; width: 100%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: none;">
                    🤖 AI Suggestions
                </button>`;
        
    } else {
        // Show AI Suggestions
        html += `<div class="my-niches-label">🤖 AI-Generated Suggestions</div>
                 <div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:8px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#5b21b6;">
                    ✨ These are fresh AI-generated ideas. Select one to save it to your collection.
                 </div>
                 <div class="opts" id="ai-suggestions-wrap" style="max-height: 400px; overflow-y: auto;">`;
        
        videoIdeasAiSuggestions.forEach(idea => {
            const isUsed = usedLower.includes(idea.toLowerCase());
            html += `<div class="opt${isUsed ? ' used-idea' : ''}" 
                          data-v="${esc(idea)}" 
                          data-source="ai"
                          style="${isUsed ? 'opacity:0.5; cursor:not-allowed;' : ''}"
                          onclick="${isUsed ? '' : `selectAndSaveAiSuggestion(this, '${esc(idea)}')`}">
                         ${idea} ${isUsed ? ' ✓ (already used)' : ''}
                     </div>`;
        });
        
        html += `</div>
                 <button class="more-btn" onclick="showMyIdeas()" style="margin: 8px 0; width: 100%;">
                    ← Back to My Ideas
                 </button>`;
    }
    
    // Custom input section (always shown)
    html += `<div class="custom-row" style="margin-top: 12px;">
                <input class="custom-in" id="opts-wrap-cust-in" 
                       placeholder="Or type your own video idea..." 
                       style="flex: 1;">
                <button class="custom-add" id="opts-wrap-cust-btn">Add Idea</button>
             </div>`;
    
    body.innerHTML = html;
    
    // Attach event handlers
    const custBtn = document.getElementById('opts-wrap-cust-btn');
    const custIn = document.getElementById('opts-wrap-cust-in');
    if (custBtn) custBtn.onclick = () => addCustomVideoIdea();
    if (custIn) custIn.onkeydown = e => { if (e.key === 'Enter') addCustomVideoIdea(); };

    // Attach AI suggestions button safely (avoids inline onclick quote issues)
    const aiBtn = document.getElementById('ai-suggestions-btn');
    if (aiBtn) aiBtn.onclick = () => loadAiSuggestions(aiBtn.dataset.niche, aiBtn.dataset.category);
}

async function loadMoreVideoIdeas() {
    const nextPage = videoIdeasCurrentPage + 1;
    await loadUserVideoIdeas(videoIdeasNiche, videoIdeasCategory, nextPage);
    renderVideoIdeaList();
}

function selectVideoIdea(el, idea) {
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(idea.toLowerCase())) {
        showToast('This idea has already been used in a video');
        return;
    }
    
    // Deselect all
    document.querySelectorAll('#step-body .opt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    ans.title = idea;
    setNext(true);
    
    // Save to database
    saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, idea, false);
}

async function selectAndSaveAiSuggestion(el, idea) {
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(idea.toLowerCase())) {
        showToast('This idea has already been used');
        return;
    }
    
    // Deselect all
    document.querySelectorAll('#step-body .opt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
    ans.title = idea;
    setNext(true);
    
    // Save to database as user's own idea (not AI generated flag)
    await saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, idea, false);
    
    // Also save as common for future users (optional)
    // await saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, idea, true, true);
    
    showToast('✓ Idea saved to your collection');
    
    // Refresh my ideas list
    await loadUserVideoIdeas(videoIdeasNiche, videoIdeasCategory, 1);
    videoIdeasShowAi = false;
    renderVideoIdeaList();
}

async function addCustomVideoIdea() {
    const inp = document.getElementById('opts-wrap-cust-in');
    const v = inp.value.trim();
    if (!v) return;
    inp.value = '';
    
    const usedLower = window._usedVideoIdeasLower || [];
    if (usedLower.includes(v.toLowerCase())) {
        showToast('This idea has already been used');
        return;
    }
    
    // Add to my ideas list
    videoIdeasMyList.unshift(v);
    videoIdeasTotalCount++;
    
    // Save to database
    await saveVideoIdeaToDB(videoIdeasNiche, videoIdeasCategory, v, false);
    
    // Select it
    ans.title = v;
    setNext(true);
    
    // Re-render
    renderVideoIdeaList();
    showToast('✓ Idea added to your collection');
}
async function saveVideoIdeaToDB(nicheName, categoryName, videoIdea, isAiGenerated, storeAsCommon = false) {
    if (!videoIdea) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action',     'save_video_idea');
        fd.append('niche_name',      nicheName    || '');
        fd.append('category_name',   categoryName || '');
        fd.append('video_idea',      videoIdea);
        fd.append('is_ai_generated', isAiGenerated ? 1 : 0);
        fd.append('store_as_common', storeAsCommon ? 1 : 0);
        await fetch(location.href, { method: 'POST', body: fd });
    } catch(e) {}
}
async function bulkSaveCommonVideoIdeas(nicheName, categoryName, ideas) {
    for (const idea of ideas) await saveVideoIdeaToDB(nicheName, categoryName, idea, true, true);
}

// ── Angles DB functions ───────────────────────────────────────────────────────
async function loadUserAngles(nicheName) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_angles');
        fd.append('niche_name',  nicheName || '');
        const r = await fetch(location.href, { method:'POST', body:fd });
        const d = await r.json();
        userAngles.my     = d.my_angles     || [];
        userAngles.common = d.common_angles || [];
        return userAngles;
    } catch(e) { return { my:[], common:[] }; }
}
async function saveAngleToDB(angleName, nicheName, isAiGenerated, storeAsCommon = false) {
    if (!angleName) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action',     'save_angle');
        fd.append('angle_name',      angleName);
        fd.append('niche_name',      nicheName || '');
        fd.append('is_ai_generated', isAiGenerated ? 1 : 0);
        fd.append('store_as_common', storeAsCommon ? 1 : 0);
        await fetch(location.href, { method:'POST', body:fd });
    } catch(e) {}
}
async function bulkSaveCommonAngles(angles, nicheName) {
    for (const a of angles) await saveAngleToDB(a, nicheName, true, true);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
// WITH
function loadSettings() {
    try { settings = JSON.parse(localStorage.getItem('vw_settings') || '{}'); } catch(e) { settings = {}; }
    Object.keys(SETTING_DEFS).forEach(k => { if (!settings[k]) settings[k] = SETTING_DEFS[k].def; });
    settings.format = '9:16 Vertical (Reels / TikTok / Shorts)'; // always force vertical
    renderSettingsBar(); renderCampSettingsPills(); renderContentSettingsPills();
}
function renderSettingsBar() {
    const short = {
        language:  settings.language,
        reel_type: settings.reel_type.split(' (')[0],
        format:    settings.format.split(' ')[0],
        objective: settings.objective,
        audience:  settings.audience.replace(' Learners','').replace('Complete ','').replace('Business ','Biz ')
    };
    const el = document.getElementById('settings-bar-pills');
    if (el) el.innerHTML = Object.values(short).map(v => `<span class="s-pill">${v}</span>`).join('');
    renderCampSettingsPills(); renderContentSettingsPills();
}

function renderCampSettingsPills() {
    const el = document.getElementById('camp-settings-pills'); if (!el) return;
    const short = { language: settings.language, reel_type: settings.reel_type.split(' (')[0], format: settings.format.split(' ')[0] };
    el.innerHTML = Object.values(short).map(v => `<span class="s-pill">${v}</span>`).join('');
}

function renderContentSettingsPills() {
    const el = document.getElementById('content-settings-pills'); if (!el) return;
    const short = { language: settings.language, reel_type: settings.reel_type.split(' (')[0], format: settings.format.split(' ')[0] };
    el.innerHTML = Object.values(short).map(v => `<span class="s-pill">${v}</span>`).join('');
}

let _focusKey = null;

function renderSettingsList() {
    const list = document.getElementById('settings-list');
    if (!list) return;
    const icons = { language:'🌐', reel_type:'🎬', format:'📐', objective:'🎯', audience:'👥' };
    list.innerHTML = Object.entries(SETTING_LABELS).map(([k, label]) => `
        <div class="setting-row" onclick="openSettingFocus('${k}')">
            <div class="setting-row-left">
                <span class="setting-row-key">${icons[k] || ''} ${label}</span>
                <span class="setting-row-val" id="srow-val-${k}">${settings[k] || SETTING_DEFS[k].def}</span>
            </div>
            <span class="setting-row-arrow">›</span>
        </div>
    `).join('');
}

function openSettings() {
    renderSettingsList();
    document.getElementById('settingsOverlay').classList.add('open');
}

function openSettingFocus(key) {
    _focusKey = key;
    const def = SETTING_DEFS[key];
    const isCampaignMode = document.getElementById('modeCampaign').style.display !== 'none';
    const icons = { language:'🌐', reel_type:'🎬', format:'📐', objective:'🎯', audience:'👥' };

    document.getElementById('settingFocusTitle').textContent =
        (icons[key] || '') + ' ' + (SETTING_LABELS[key] || key);

    const optsEl = document.getElementById('settingFocusOpts');

    if (key === 'reel_type' && isCampaignMode) {
        // Campaign mode: Standard locked on, B-Roll and Podcast disabled
        optsEl.innerHTML = def.opts.map(o => {
            const isStandard = (o === 'Standard');
            const isLocked   = !isStandard;
            return `<div class="sopt${isStandard ? ' sel' : ''}"
                data-v="${o}"
                style="${isLocked ? 'opacity:0.4;cursor:not-allowed;' : 'cursor:default;'}"
                title="${isLocked ? 'Not available in campaign mode' : ''}"
            >${o}${isLocked ? ' 🔒' : ' ✓'}</div>`;
        }).join('') +
        `<div style="font-size:11px;color:#92400e;margin-top:10px;padding:8px 12px;background:#fef3c7;border-radius:8px;">
            🔒 Campaign mode always uses <strong>Standard</strong>. Language is set per-video in the campaign Languages step.
        </div>`;

    } else if (key === 'language') {
    // All users, all modes (except campaign which is handled above): all languages selectable
    optsEl.innerHTML = def.opts.map(o =>
        `<div class="sopt${settings[key] === o ? ' sel' : ''}" data-v="${o}" onclick="selectSopt(this,'${key}')">${o}</div>`
    ).join('') +
    (isCampaignMode
        ? `<div style="font-size:11px;color:var(--muted);margin-top:10px;padding:8px 12px;background:#f7f9fc;border-radius:8px;">
               🌐 Language per video is set in the campaign Languages step. This sets the default.
           </div>`
        : '');

	}
	else {
        // Default: normal selectable options
        optsEl.innerHTML = def.opts.map(o =>
            `<div class="sopt${settings[key] === o ? ' sel' : ''}" data-v="${o}" onclick="selectSopt(this,'${key}')">${o}</div>`
        ).join('');
    }

    document.getElementById('settingFocusOverlay').classList.add('open');
}

function closeSettingFocus() {
    document.getElementById('settingFocusOverlay').classList.remove('open');
    _focusKey = null;
}

function settingFocusOverlayClick(e) {
    if (e.target === document.getElementById('settingFocusOverlay')) closeSettingFocus();
}

function saveSettingFocus() {
    if (!_focusKey) { closeSettingFocus(); return; }
    const s = document.querySelector('#settingFocusOpts .sopt.sel');
    if (s) {
        settings[_focusKey] = s.dataset.v;
        // Update the row value in the list
        const rowVal = document.getElementById('srow-val-' + _focusKey);
        if (rowVal) rowVal.textContent = s.dataset.v;
    }
    localStorage.setItem('vw_settings', JSON.stringify(settings));
    renderSettingsBar();
    showToast('Saved ✓');
    closeSettingFocus();
}

function selectSopt(el, key) {
    document.querySelectorAll('#settingFocusOpts .sopt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel');
}

function saveSettings() {
    // Legacy — now handled by saveSettingFocus
    localStorage.setItem('vw_settings', JSON.stringify(settings));
    closeSettings();
    renderSettingsBar();
    showToast('Settings saved ✓');
}

function closeSettings() { document.getElementById('settingsOverlay').classList.remove('open'); }
function overlayClick(e) { if (e.target === document.getElementById('settingsOverlay')) closeSettings(); }




// ═══════════════════════════════════════════════════════════════════════════════
// DURATION STEP RENDERER
// ═══════════════════════════════════════════════════════════════════════════════
function renderDurationStep(opts, key, bodyId, ansObj, setNextFn) {
    const body = document.getElementById(bodyId);
    if (!body) return;
    if (!ansObj[key]) ansObj[key] = '30 seconds';

    let html = '';
    if (IS_FREE_TRIAL) {
        html += `<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400e;">
          <span style="font-size:16px;">🔒</span>
          <span>Free Trial is limited to <strong>30 seconds</strong>. <a href="upgrade.php" style="color:#92400e;font-weight:700;text-decoration:underline;">Upgrade</a> to unlock longer videos.</span>
        </div>`;
    }

    html += `<div style="display:flex;flex-wrap:wrap;gap:10px;">`;
    opts.forEach(opt => {
        const isSelected = (ansObj[key] === opt);
        const isThirty   = (opt === '30 seconds');
        const isLocked   = IS_FREE_TRIAL && !isThirty; // only 30s free for trial; 15s is locked
        let borderColor  = isSelected ? 'var(--purple)' : 'var(--border)';
        let bg           = isSelected ? 'var(--purple-lt)' : '#fff';
        let color        = isSelected ? '#5b21b6' : 'var(--text)';
        let opacity      = isLocked ? '0.45' : '1';
        let cursor       = isLocked ? 'not-allowed' : 'pointer';
        let extraBadge   = '';
        if (isLocked)    { extraBadge = `<span style="font-size:10px;margin-left:4px;">🔒</span>`; borderColor='var(--border)'; bg='#f8fafc'; color='var(--muted)'; }
        if (isSelected)  { extraBadge = `<span style="font-size:10px;margin-left:4px;background:var(--purple);color:#fff;border-radius:20px;padding:1px 6px;">✓</span>`; }
        html += `<div class="duration-opt${isSelected?' sel':''}" data-v="${esc(opt)}" data-locked="${isLocked?'1':'0'}"
          style="padding:10px 18px;border:1.5px solid ${borderColor};border-radius:8px;background:${bg};color:${color};font-size:14px;font-weight:600;cursor:${cursor};opacity:${opacity};display:flex;align-items:center;gap:4px;transition:all .15s;user-select:none;">
          ${opt}${extraBadge}</div>`;
    });
    html += `</div>`;
    body.innerHTML = html;

    body.querySelectorAll('.duration-opt').forEach(el => {
        el.onclick = () => {
            if (el.dataset.locked === '1') return;
            body.querySelectorAll('.duration-opt').forEach(x => {
                x.classList.remove('sel');
                const isLock = x.dataset.locked === '1';
                x.style.background  = isLock ? '#f8fafc' : '#fff';
                x.style.borderColor = 'var(--border)';
                x.style.color       = isLock ? 'var(--muted)' : 'var(--text)';
                x.innerHTML = x.dataset.v + (isLock ? ' <span style="font-size:10px;margin-left:4px;">🔒</span>' : '');
            });
            el.classList.add('sel');
            el.style.background  = 'var(--purple-lt)';
            el.style.borderColor = 'var(--purple)';
            el.style.color       = '#5b21b6';
            el.innerHTML = el.dataset.v + ` <span style="font-size:10px;margin-left:4px;background:var(--purple);color:#fff;border-radius:20px;padding:1px 6px;">✓</span>`;
            ansObj[key] = el.dataset.v;
            setNextFn(true);
        };
    });
    setNextFn(true);
}

// ═══════════════════════════════════════════════════════════════════════════════
// WIZARD ENGINE
// ═══════════════════════════════════════════════════════════════════════════════
function setNext(v) {
    document.getElementById('nextBtn').disabled = !v;
    // Sync inline Continue button in header row if present
    const inlineCont = document.getElementById('step-q-cont-btn');
    if (inlineCont) inlineCont.disabled = !v;
}
function setBack()  { document.getElementById('backBtn').style.visibility = cur === 0 ? 'hidden' : 'visible'; }

// Show inline header actions (More + Continue) for wizard steps
function setInlineActions(show, moreBtnId, nextBtnId, onMore, onNext) {
    const actionsEl = document.getElementById('step-q-actions');
    if (!actionsEl) return;
    if (show) {
        actionsEl.innerHTML = '';
        if (onMore) {
            const mb = document.createElement('button');
            mb.id = moreBtnId || 'more-btn';
            mb.className = 'more-btn-sm';
            mb.innerHTML = '<span>+</span> More';
            mb.onclick = onMore;
            actionsEl.appendChild(mb);
        }
        const cb = document.createElement('button');
        cb.id = 'step-q-cont-btn';
        cb.className = 'cont-btn-sm';
        cb.disabled = document.getElementById(nextBtnId || 'nextBtn')?.disabled ?? true;
        cb.textContent = 'Continue →';
        cb.onclick = onNext || goNext;
        actionsEl.appendChild(cb);
        actionsEl.style.display = 'flex';
    } else {
        actionsEl.style.display = 'none';
        actionsEl.innerHTML = '';
    }
}

// WITH
async function render() {
    document.getElementById('prog').style.width = Math.round((cur / STEPS.length) * 100) + '%';
    setBack(); setNext(false);
    const s = STEPS[cur];
    document.getElementById('step-label').textContent = s.label;
    document.getElementById('step-q').textContent     = s.q;
    updateCardSubtitle();

    if      (s.type === 'niche-select')   { await renderNicheSelect(s, 'step-body', 'opts-wrap', 'more-btn', 'nav-bar', 'nextBtn', ans); }
    else if (s.type === 'duration-select'){ renderDurationStep(s.opts, s.key, 'step-body', ans, setNext); }
    else if (s.type === 'cta-select')     { await renderCTASelect(s); }
    else if (s.type === 'opts' || s.type === 'opts+more') {
        if (s.key === 'angle') {
            await renderAngleSelect(s);
        } else {
            if (!stepOpts[s.key]) stepOpts[s.key] = [...s.opts];
            renderOpts(stepOpts[s.key], s.key, s.type === 'opts+more', 'step-body', 'opts-wrap', 'nav-bar', 'nextBtn', ans, setNext);
        }
    }
    else if (s.type === 'ai') { await renderAI(s); }

    // niche and category steps inject their own More+Continue after async load.
    // All other steps get Continue injected immediately; steps with More re-inject it after async load.
    const selfInjects = (s.type === 'niche-select' || s.key === 'topic');
    if (!selfInjects) {
        setInlineActions(true, null, 'nextBtn', null, () => { autoSubmitCustomInput(); goNext(); });
    }
}
async function renderAngleSelect(s) {
    const nicheName = ans.niche || '';
    const angles    = await loadUserAngles(nicheName);
    const myAngles  = angles.my;
    const commonAngles = angles.common;

    // Seed stepOpts: common from DB + hardcoded defaults, dedupe
    if (!stepOpts[s.key]) {
        const allCommon  = [...new Set([...commonAngles, ...s.opts])];
        stepOpts[s.key]  = allCommon;
    }

    const body = document.getElementById('step-body');
    let html   = '';

    if (myAngles.length > 0) {
        html += `<div class="my-niches-label">My Hooks & Angles</div><div class="opts" id="my-angles-wrap">`;
        myAngles.forEach(a => {
            html += `<div class="opt${ans[s.key]===a?' sel':''}" data-v="${esc(a)}" data-source="user">${a}</div>`;
        });
        html += `</div><div class="divider-label">All Hooks & Angles</div>`;
    }

    const myAnglesLower = myAngles.map(a => a.toLowerCase());
    const filteredOpts  = stepOpts[s.key].filter(a => !myAnglesLower.includes(a.toLowerCase()));

    html += `<div class="opts" id="opts-wrap">`;
    filteredOpts.forEach(a => { html += `<div class="opt${ans[s.key]===a?' sel':''}" data-v="${esc(a)}">${a}</div>`; });
    html += `</div>`;
    html += `<div class="custom-row"><input class="custom-in" id="opts-wrap-cust-in" placeholder="Or type your own hook…"><button class="custom-add" id="opts-wrap-cust-btn">Add</button></div>`;
    body.innerHTML = html;

    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel'); ans[s.key] = b.dataset.v; setNext(true);
            saveAngleToDB(b.dataset.v, nicheName, false, false);
        };
    });

    const custBtn = document.getElementById('opts-wrap-cust-btn');
    const custIn  = document.getElementById('opts-wrap-cust-in');
    if (custBtn) custBtn.onclick = () => {
        const v = custIn.value.trim(); if (!v) return; custIn.value = '';
        if (!stepOpts[s.key]) stepOpts[s.key] = [];
        if (!stepOpts[s.key].includes(v)) stepOpts[s.key].push(v);
        body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
        let wrap = document.getElementById('opts-wrap');
        if (!wrap) { wrap = document.createElement('div'); wrap.className='opts'; wrap.id='opts-wrap'; body.insertBefore(wrap, custIn.parentElement); }
        const b = document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
        b.onclick = () => { body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); ans[s.key]=v; setNext(true); saveAngleToDB(v, nicheName, false, false); };
        wrap.appendChild(b); ans[s.key]=v; setNext(true);
        saveAngleToDB(v, nicheName, false, false);
    };
    if (custIn) custIn.onkeydown = e => { if (e.key === 'Enter' && custBtn) custBtn.click(); };

    if (!ans[s.key]) { const first = body.querySelector('.opt'); if (first) first.click(); }
    else setNext(true);

    // More button — injected inline into header row
    const oldMore = document.getElementById('more-btn'); if (oldMore) oldMore.remove();
    const moreFnAngle = s.morePrompt ? async () => {
        const moreBtn = document.getElementById('more-btn');
        if (moreBtn) { moreBtn.disabled = true; moreBtn.innerHTML = '<span class="spin">⟳</span> Loading…'; }
        const existing = [...stepOpts[s.key]];
        const prompt   = s.morePrompt(existing, ans);
        try {
            const r = await fetch('generate_more_opts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prompt}) });
            const d = await r.json(); if (!d.success) throw new Error('failed');
            const newAngles = []; let added = 0;
            (d.items || []).forEach(item => {
                const c = String(item).trim();
                if (c && !stepOpts[s.key].includes(c)) { stepOpts[s.key].push(c); added++; newAngles.push(c); }
            });
            if (newAngles.length > 0) bulkSaveCommonAngles(newAngles, nicheName);
            renderAngleSelect(s);
        } catch(e) {
            const mb = document.getElementById('more-btn');
            if (mb) { mb.disabled=false; mb.innerHTML='<span>+</span> More'; }
        }
    } : null;
    setInlineActions(true, 'more-btn', 'nextBtn', moreFnAngle, () => { autoSubmitCustomInput(); goNext(); });
}

async function renderCTASelect(s) {
    await loadUserCTAs();
    
    // Get company-specific CTAs vs global (the server already merged, but we can track)
    // For better UX, let's make another call to get separated lists
    let companyCTAs = [];
    let globalCTAsList = [];
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_ctas_separated');
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            companyCTAs = d.company_ctas || [];
            globalCTAsList = d.global_ctas || [];
            userCTAs = [...companyCTAs, ...globalCTAsList];
        }
    } catch(e) {
        // Fallback to merged list
        companyCTAs = userCTAs;
    }
    
    if (!stepOpts[s.key]) stepOpts[s.key] = [...s.opts];

    const body = document.getElementById('step-body');
    let html = '';

    if (companyCTAs.length > 0) {
        html += `<div class="my-niches-label">🏢 Company CTAs (${companyCTAs.length})</div>
                 <div class="opts" id="opts-wrap-user-cta" style="margin-bottom: 12px;">`;
        companyCTAs.forEach(c => {
            html += `<div class="opt${ans[s.key] === c ? ' sel' : ''}" data-v="${esc(c)}" data-source="company">${c}</div>`;
        });
        html += `</div>`;
    }
    
    if (globalCTAsList.length > 0) {
        html += `<div class="divider-label">🌍 Global CTAs</div>
                 <div class="opts" id="opts-wrap-global-cta" style="margin-bottom: 12px;">`;
        globalCTAsList.forEach(c => {
            // Don't show if already shown in company CTAs
            if (!companyCTAs.includes(c)) {
                html += `<div class="opt${ans[s.key] === c ? ' sel' : ''}" data-v="${esc(c)}" data-source="global">${c}</div>`;
            }
        });
        html += `</div>`;
    }

    // Default suggestions
    if (stepOpts[s.key].length > 0) {
        html += `<div class="divider-label">💡 Suggestions</div>
                 <div class="opts" id="opts-wrap">`;
        stepOpts[s.key].forEach(o => {
            html += `<div class="opt${ans[s.key] === o ? ' sel' : ''}" data-v="${esc(o)}">${o}</div>`;
        });
        html += `</div>`;
    }

    html += `<div style="margin-bottom:6px; margin-top: 12px;">
        <textarea id="opts-wrap-cust-in" rows="3" placeholder="Or type your own CTA…"
            style="width:100%;height:72px;padding:9px 12px;font-size:13px;border:1.5px solid var(--border);border-radius:8px;color:var(--text);outline:none;transition:border-color .15s;background:#fff;resize:vertical;font-family:inherit;line-height:1.5;"
            onfocus="this.style.borderColor='var(--purple)'"
            onblur="this.style.borderColor='var(--border)'"
            onkeydown="event.stopPropagation()"></textarea>
        <button class="custom-add" id="opts-wrap-cust-btn" style="margin-top:6px;width:100%;">Add CTA to Company</button>
    </div>`;

    body.innerHTML = html;
    document.getElementById('nextBtn').setAttribute('type', 'button');
    
    // Handle selection for all opt types
    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            ans[s.key] = b.dataset.v;
            setNext(true);
            saveCTAToDB(b.dataset.v);
        };
    });

    const custBtn = document.getElementById('opts-wrap-cust-btn');
    const custIn = document.getElementById('opts-wrap-cust-in');
    
    if (custBtn) {
        custBtn.onclick = async () => {
            const v = custIn.value.trim(); 
            if (!v) return; 
            custIn.value = '';
            
            // Add to stepOpts
            if (!stepOpts[s.key].includes(v)) stepOpts[s.key].push(v);
            
            // Add to company CTAs
            if (!companyCTAs.includes(v)) {
                companyCTAs.unshift(v);
                userCTAs = [...companyCTAs, ...globalCTAsList];
            }
            
            // Deselect all and select the new one
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            
            // Create or get company CTAs wrap
            let companyWrap = document.getElementById('opts-wrap-user-cta');
            if (!companyWrap) {
                // Create company CTAs section if it doesn't exist
                companyWrap = document.createElement('div');
                companyWrap.id = 'opts-wrap-user-cta';
                companyWrap.className = 'opts';
                
                const label = document.createElement('div');
                label.className = 'my-niches-label';
                label.textContent = '🏢 Company CTAs';
                
                const firstOpt = body.querySelector('.opts');
                if (firstOpt) {
                    body.insertBefore(label, firstOpt);
                    body.insertBefore(companyWrap, firstOpt);
                } else {
                    body.appendChild(label);
                    body.appendChild(companyWrap);
                }
            }
            
            // Create new opt button
            const b = document.createElement('div');
            b.className = 'opt sel';
            b.dataset.v = v;
            b.textContent = v;
            b.onclick = () => {
                body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                b.classList.add('sel');
                ans[s.key] = v;
                setNext(true);
                saveCTAToDB(v);
            };
            
            companyWrap.insertBefore(b, companyWrap.firstChild);
            ans[s.key] = v;
            setNext(true);
            await saveCTAToDB(v);
            showToast('✓ CTA added to your company');
        };
    }
    
    if (custIn) {
        custIn.onkeydown = e => { 
            if (e.key === 'Enter' && custBtn) custBtn.click(); 
        };
    }
    
    if (ans[s.key]) setNext(true);
}
function updateCardSubtitle() {
    const parts = [];
    if (ans.niche) parts.push(ans.niche);
    if (ans.topic) parts.push(ans.topic);
    if (ans.title) parts.push(ans.title);
    document.getElementById('cardSubtitle').textContent = parts.length
        ? parts.join(' › ') : 'Answer a few questions to generate your video script';
}

// ── Auto-submit any pending custom input when user clicks Continue ────────────
// Covers: niche, category, angle, CTA, campaign opts, campaign category, campaign titles
function autoSubmitCustomInput() {
    const btnMap = {
        'opts-wrap-cust-in':  'opts-wrap-cust-btn',   // niche / angle / generic opts
        'camp-cust-in':       'camp-cust-btn',          // campaign opts steps
        'camp-cat-cust-in':   'camp-cat-cust-btn',      // campaign category
        'camp-title-cust-in': 'camp-title-cust-btn',    // campaign title select
    };
    Object.keys(btnMap).forEach(function(id) {
        var inp = document.getElementById(id);
        if (inp && inp.value && inp.value.trim()) {
            var btn = document.getElementById(btnMap[id]);
            if (btn && !btn.disabled) btn.click();
        }
    });
    // CTA step uses a different textarea id
    var ctaIn = document.getElementById('opts-wrap-cust-in');
    if (!ctaIn) {
        // try the textarea inside step-body directly
        var ta = document.querySelector('#step-body textarea');
        if (ta && ta.value && ta.value.trim()) {
            var addBtn = document.getElementById('opts-wrap-cust-btn');
            if (addBtn && !addBtn.disabled) addBtn.click();
        }
    }
}

function goNext() {
    autoSubmitCustomInput();
    if (cur < STEPS.length - 1) { cur++; clearMoreBtn(); render(); } else showSummary();
}
function goBack() { if (cur > 0) { cur--; clearMoreBtn(); render(); } }
function clearMoreBtn() {
    const b = document.getElementById('more-btn'); if (b) b.remove();
    // Also wipe the inline cont btn so it gets rebuilt fresh on next render
    const cb = document.getElementById('step-q-cont-btn'); if (cb) cb.remove();
}

// ═══════════════════════════════════════════════════════════════════════════════
// NICHE SELECT (shared: wizard + campaign)
// ═══════════════════════════════════════════════════════════════════════════════
async function renderNicheSelect(s, bodyId, wrapId, moreBtnId, navBarId, nextBtnId, ansObj) {
    // For non-internal users: never load DEFAULT_NICHES or common pool — only their own niches
    const isInternal = window._isInternalUser !== false; // default true for safety if flag missing
    if (!stepOpts[s.key]) {
        if (isInternal) {
            const commonFromDB = window._commonNiches || [];
            stepOpts[s.key] = commonFromDB.length > 0 ? [...commonFromDB] : [...s.opts];
        } else {
            stepOpts[s.key] = []; // non-internal: no default/common pool at all
        }
    }
    const body = document.getElementById(bodyId);

    let html = '';
    if (userNiches.length > 0) {
        html += `<div class="my-niches-label">My Niches</div><div class="opts" id="${wrapId}-user">`;
        userNiches.forEach(n => {
        html += `<div class="opt${ansObj[s.key] === n ? ' sel' : ''}" data-v="${esc(n)}" data-source="user" style="display:inline-flex;align-items:center;">
            <span class="opt-label">${n}</span>
            <button class="opt-del" title="Remove" onclick="event.stopPropagation();deleteNiche('${esc(n)}',this,this.closest('.opt'))">✕</button>
        </div>`;
    });
        html += `</div>`;
        // Only show "All Niches" divider + common pool for internal users
        if (isInternal) html += `<div class="divider-label">All Niches</div>`;
    }

    // Filter out default niches that duplicate user niches (case-insensitive)
    const userNichesLower = userNiches.map(n => n.toLowerCase());
    const filteredOpts = isInternal
        ? stepOpts[s.key].filter(n => !userNichesLower.includes(n.toLowerCase()))
        : []; // non-internal: no additional pool

    if (isInternal && filteredOpts.length > 0) {
        html += `<div class="opts" id="${wrapId}">`;
        filteredOpts.forEach(n => {
            html += `<div class="opt${ansObj[s.key] === n ? ' sel' : ''}" data-v="${esc(n)}">${n}</div>`;
        });
        html += `</div>`;
    } else if (!isInternal) {
        // Non-internal: no common opts div rendered at all
        html += `<div class="opts" id="${wrapId}"></div>`;
    } else {
        html += `<div class="opts" id="${wrapId}"></div>`;
    }

    html += `<div class="custom-row"><input class="custom-in" id="${wrapId}-cust-in" placeholder="Or type your own…"><button class="custom-add" id="${wrapId}-cust-btn">Add</button></div>`;
    body.innerHTML = html;

    // FIND (line 1116):
    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = () => {
            const prev = ansObj[s.key];
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            ansObj[s.key] = b.dataset.v;
            if (s.key === 'niche' && prev && prev !== ansObj[s.key]) {
                delete ansObj.topic; delete ansObj.title;
                delete stepOpts.topic; delete stepOpts.title;
                delete campStepOpts.camp_category;
            }
            document.getElementById(nextBtnId).disabled = false;
            saveNicheToDB(b.dataset.v, false);
        };
    });

// REPLACE WITH:
    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = (e) => {
            if (e.target.classList.contains('opt-del')) return;
            const prev = ansObj[s.key];
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            ansObj[s.key] = b.dataset.v;
            if (s.key === 'niche' && prev && prev !== ansObj[s.key]) {
                delete ansObj.topic; delete ansObj.title;
                delete stepOpts.topic; delete stepOpts.title;
                delete campStepOpts.camp_category;
            }
            document.getElementById(nextBtnId).disabled = false;
            saveNicheToDB(b.dataset.v, false);
        };
    });

    const custBtn = document.getElementById(`${wrapId}-cust-btn`);
    const custIn  = document.getElementById(`${wrapId}-cust-in`);
    if (custBtn) custBtn.onclick  = () => addNicheCustom(s.key, wrapId, ansObj, nextBtnId);
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addNicheCustom(s.key, wrapId, ansObj, nextBtnId); };

    if (!ansObj[s.key]) {
        const firstOpt = body.querySelector('.opt');
        if (firstOpt) firstOpt.click();
    } else {
        document.getElementById(nextBtnId).disabled = false;
    }

    const isWizardNiche = (navBarId === 'nav-bar' && s.key === 'niche');
    const isCampNiche   = (navBarId === 'camp-nav-bar');

    if (isWizardNiche) {
        // Inject More + Continue inline into the step-q header row
        setInlineActions(
            true,
            moreBtnId,
            nextBtnId,
            s.morePrompt ? () => loadMoreNiches(s, wrapId, moreBtnId, ansObj, nextBtnId) : null,
            () => { autoSubmitCustomInput(); goNext(); }
        );
        // Keep the More btn ID synced for loadMoreNiches to find it
        const inlineMore = document.getElementById(moreBtnId);
        if (inlineMore) inlineMore.id = moreBtnId;
    } else {
        // Campaign niche — keep bottom nav, just add More button there
        const navBar  = document.getElementById(navBarId);
        const oldMore = document.getElementById(moreBtnId); if (oldMore) oldMore.remove();
        if (s.morePrompt && navBar) {
            const moreBtn = document.createElement('button');
            moreBtn.id = moreBtnId; moreBtn.className = 'more-btn';
            moreBtn.innerHTML = '<span>+</span> More';
            moreBtn.onclick = () => loadMoreNiches(s, wrapId, moreBtnId, ansObj, nextBtnId);
            navBar.insertBefore(moreBtn, document.getElementById(nextBtnId));
        }
    }
}

function addNicheCustom(key, wrapId, ansObj, nextBtnId) {
    const inp = document.getElementById(`${wrapId}-cust-in`);
    const v   = inp.value.trim(); if (!v) return; inp.value = '';

    // Add to stepOpts so it persists within session
    if (!stepOpts[key]) stepOpts[key] = [];
    if (!stepOpts[key].includes(v)) stepOpts[key].push(v);

    // Add to userNiches so it appears in "My Niches" section immediately
    if (!userNiches.includes(v)) userNiches.unshift(v);

    // Deselect all existing opts
    document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));

    // Add button to "My Niches" section if it exists, otherwise fall back to main wrap
    let targetWrap = document.getElementById(`${wrapId}-user`);
    if (!targetWrap) {
        // "My Niches" section doesn't exist yet — create it above the main wrap
        const body      = document.getElementById(wrapId).parentElement;
        const mainWrap  = document.getElementById(wrapId);
        const label     = document.createElement('div');
        label.className = 'my-niches-label';
        label.textContent = 'My Niches';
        const divider     = document.createElement('div');
        divider.className = 'divider-label';
        divider.textContent = 'All Niches';
        targetWrap    = document.createElement('div');
        targetWrap.id = `${wrapId}-user`;
        targetWrap.className = 'opts';
        body.insertBefore(divider,  mainWrap);
        body.insertBefore(targetWrap, divider);
        body.insertBefore(label,    targetWrap);
    }

    const b = document.createElement('div');
    b.className  = 'opt sel';
    b.dataset.v  = v;
    b.textContent = v;
    b.dataset.source = 'user';
    b.onclick = () => {
        document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
        b.classList.add('sel');
        ansObj[key] = v;
        document.getElementById(nextBtnId).disabled = false;
        saveNicheToDB(v, false);
    };

    targetWrap.insertBefore(b, targetWrap.firstChild); // newest at top
    ansObj[key] = v;
    setNext(true); // syncs both #nextBtn AND inline #step-q-cont-btn
    saveNicheToDB(v, false);
}

async function loadMoreNiches(s, wrapId, moreBtnId, ansObj, nextBtnId) {
    const btn = document.getElementById(moreBtnId); if (!btn || !s.morePrompt) return;
    btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Loading…';
    const existing = [...stepOpts[s.key]];
    const prompt   = s.morePrompt(existing, ansObj);
    try {
        const r = await fetch('generate_more_opts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prompt}) });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json(); if (!d.success) throw new Error(d.error || 'Server error');
        let added = 0; const newNiches = [];
        (d.items || []).forEach(item => {
            const c = String(item).trim();
            if (c && !stepOpts[s.key].includes(c)) { stepOpts[s.key].push(c); added++; newNiches.push(c); }
        });
        // Bulk-save AI niches as common so future users skip this AI call
        if (newNiches.length > 0) bulkSaveCommonNiches(newNiches);
        const wrap = document.getElementById(wrapId);
        if (wrap) {
            stepOpts[s.key].forEach(n => {
                if (!wrap.querySelector(`[data-v="${esc(n)}"]`)) {
                    const b = document.createElement('div'); b.className='opt'; b.dataset.v=n; b.textContent=n;
                    b.onclick = () => {
                        document.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                        b.classList.add('sel'); ansObj[s.key] = n;
                        document.getElementById(nextBtnId).disabled = false;
                        saveNicheToDB(n, true);
                    };
                    wrap.appendChild(b);
                }
            });
        }
        if (added === 0) { btn.textContent = 'No more'; btn.disabled = true; }
        else { btn.disabled = false; btn.innerHTML = '<span>+</span> More'; }
    } catch(e) {
        const b = document.getElementById(moreBtnId);
        if (b) { b.disabled = false; b.innerHTML = '<span>+</span> More'; }
        showToast('Could not load more: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// GENERIC OPTS RENDER
// ═══════════════════════════════════════════════════════════════════════════════
function renderOpts(opts, key, showMore, bodyId, wrapId, navBarId, nextBtnId, ansObj, setNextFn) {
    bodyId    = bodyId    || 'step-body';
    wrapId    = wrapId    || 'opts-wrap';
    navBarId  = navBarId  || 'nav-bar';
    nextBtnId = nextBtnId || 'nextBtn';
    ansObj    = ansObj    || ans;
    setNextFn = setNextFn || setNext;
    const body = document.getElementById(bodyId);

    let html = `<div class="opts" id="${wrapId}">` +
        opts.map(o => `<div class="opt${ansObj[key] === o ? ' sel' : ''}" data-v="${esc(o)}">${o}</div>`).join('') +
        `</div>`;
    html += `<div class="custom-row"><input class="custom-in" id="${wrapId}-cust-in" placeholder="Or type your own…"><button class="custom-add" id="${wrapId}-cust-btn">Add</button></div>`;
    body.innerHTML = html;

    document.querySelectorAll(`#${wrapId} .opt`).forEach(b => {
        b.onclick = () => {
            const prev = ansObj[key];
            document.querySelectorAll(`#${wrapId} .opt`).forEach(x => x.classList.remove('sel'));
            b.classList.add('sel'); ansObj[key] = b.dataset.v;
            if (key === 'niche' && prev && prev !== ansObj[key]) { delete ansObj.topic; delete ansObj.title; delete stepOpts.topic; delete stepOpts.title; }
            if (key === 'topic' && prev && prev !== ansObj[key]) { delete ansObj.title; delete stepOpts.title; }
            setNextFn(true);
        };
    });

    const custBtn = document.getElementById(`${wrapId}-cust-btn`);
    const custIn  = document.getElementById(`${wrapId}-cust-in`);
    if (custBtn) custBtn.onclick  = () => addCustomGeneric(key, wrapId, ansObj, setNextFn);
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addCustomGeneric(key, wrapId, ansObj, setNextFn); };
    if (ansObj[key]) setNextFn(true);

    const navBar  = document.getElementById(navBarId);
    const oldMore = document.getElementById('more-btn'); if (oldMore) oldMore.remove();
    if (showMore) {
        const moreBtn = document.createElement('button');
        moreBtn.id = 'more-btn'; moreBtn.className = 'more-btn';
        moreBtn.innerHTML = '<span>+</span> More'; moreBtn.onclick = loadMore;
        navBar.insertBefore(moreBtn, document.getElementById(nextBtnId));
    }
}

function addCustomGeneric(key, wrapId, ansObj, setNextFn) {
    const inp = document.getElementById(`${wrapId}-cust-in`);
    const v   = inp.value.trim(); if (!v) return; inp.value = '';
    if (!stepOpts[key]) stepOpts[key] = [];
    if (!stepOpts[key].includes(v)) stepOpts[key].push(v);
    document.querySelectorAll(`#${wrapId} .opt`).forEach(x => x.classList.remove('sel'));
    const wrap = document.getElementById(wrapId);
    const b = document.createElement('div'); b.className = 'opt sel'; b.dataset.v = v; b.textContent = v;
    b.onclick = () => {
        document.querySelectorAll(`#${wrapId} .opt`).forEach(x => x.classList.remove('sel'));
        b.classList.add('sel'); ansObj[key] = v; setNextFn(true);
    };
    wrap.appendChild(b); ansObj[key] = v; setNextFn(true);
}

async function loadMore() {
    const s   = STEPS[cur];
    const btn = document.getElementById('more-btn'); if (!btn || !s.morePrompt) return;
    btn.disabled = true; btn.innerHTML = '<span class="spin">⟳</span> Loading…';
    const existing = [...stepOpts[s.key]];
    const prompt   = s.morePrompt(existing, ans);
    try {
        const r = await fetch('generate_more_opts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prompt}) });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json(); if (!d.success) throw new Error(d.error || 'Server error');
        let added = 0;
        (d.items || []).forEach(item => { const c = String(item).trim(); if (c && !stepOpts[s.key].includes(c)) { stepOpts[s.key].push(c); added++; } });
        renderOpts(stepOpts[s.key], s.key, true);
        if (added === 0) { const b = document.getElementById('more-btn'); if (b) { b.textContent = 'No more'; b.disabled = true; } }
    } catch(e) {
        const b = document.getElementById('more-btn');
        if (b) { b.disabled = false; b.innerHTML = '<span>+</span> More'; }
        showToast('Could not load more: ' + e.message);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI STEP RENDER (categories, video ideas)
// ═══════════════════════════════════════════════════════════════════════════════
async function renderAI(s) {
    const isCategory  = (s.key === 'topic' || s.key === 'camp_category');
    const isVideoIdea = (s.key === 'title');
    const currentNiche    = ans.niche    || campAns.camp_niche    || '';
    const currentCategory = ans.topic    || campAns.camp_category || '';

    // If already loaded in this session, just re-render
    if (stepOpts[s.key] && stepOpts[s.key].length > 0) {
        const isInternal = window._isInternalUser !== false;
        if (isCategory && currentNiche)  await renderCategorySelect(s, currentNiche, isInternal ? stepOpts[s.key] : []);
        else if (isVideoIdea)            await renderVideoIdeaSelect(s, stepOpts[s.key]);
        else                             renderOpts(stepOpts[s.key], s.key, true);
        return;
    }

    const oldMore = document.getElementById('more-btn'); if (oldMore) oldMore.remove();

    // ── CATEGORY: check DB first (my + common) ───────────────────────────────
    if (isCategory && currentNiche) {
        await loadUserCategories(currentNiche);
        const myCats     = userCategories[currentNiche] || [];
        const isInternal = window._isInternalUser !== false;
        // Non-internal: never use commonCats (AI-generated pool) — only their own DB rows
        const commonCats = isInternal ? ((window._commonCategories || {})[currentNiche] || []) : [];
        const allDbCats  = [...new Set([...myCats, ...commonCats])];

        if (allDbCats.length >= 6) {
            // Enough from DB — skip AI entirely
            stepOpts[s.key] = [...allDbCats];
            await renderCategorySelect(s, currentNiche, commonCats);
            return;
        }

        if (!isInternal) {
            // Non-internal with no/few DB categories: just show what they have + input box, no AI call
            stepOpts[s.key] = [...myCats];
            await renderCategorySelect(s, currentNiche, []);
            return;
        }

        // Internal user with < 6 DB categories — call AI, bulk-save results as common
        document.getElementById('step-body').innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading categories…</span></div>`;
        try {
            const payload = {}; (s.aiPayload || []).forEach(k => { payload[k] = ans[k]; });
            const r = await fetch(s.aiUrl, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
            const d = await r.json();
            const aiItems = (d[s.resKey] || []).filter(c => !allDbCats.map(x=>x.toLowerCase()).includes(c.toLowerCase()));
            // Bulk-save AI results as common for future users
            if (aiItems.length > 0) bulkSaveCommonCategories(currentNiche, aiItems);
            stepOpts[s.key] = [...aiItems];
            await renderCategorySelect(s, currentNiche, aiItems);
        } catch(e) {
            document.getElementById('step-body').innerHTML =
                `<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load — add your own below</div>
                 <div class="custom-row"><input class="custom-in" id="opts-wrap-cust-in" placeholder="Type here…"><button class="custom-add" id="opts-wrap-cust-btn">Add</button></div>`;
            document.getElementById('opts-wrap-cust-btn').onclick = () => addCustomGeneric(s.key, 'opts-wrap', ans, setNext);
        }
        return;
    }

    // ── VIDEO IDEA: check DB first ────────────────────────────────────────────
   // ── VIDEO IDEA: check DB first (NO AUTO-SAVE) ────────────────────────────
if (isVideoIdea) {
    // Just load existing ideas - NO auto-generation
    await loadUserVideoIdeas(currentNiche, currentCategory);
    const ideasKey = (currentNiche||'') + '|' + (currentCategory||'');
    const commonIdeas = (window._commonVideoIdeas || {})[ideasKey] || [];
    const usedLower = window._usedVideoIdeasLower || [];
    
    // Combine my ideas + common ideas, filter used ones
    const allAvailableIdeas = [...new Set([...userVideoIdeas, ...commonIdeas])];
    const freshIdeas = allAvailableIdeas.filter(i => !usedLower.includes(i.toLowerCase()));
    
    // Store what we have (could be 0)
    stepOpts[s.key] = [...freshIdeas];
    
    // Show the video ideas UI with a button to generate AI suggestions
    const body = document.getElementById('step-body');
    
    let html = '';
    
    // My Ideas section
    if (userVideoIdeas.length > 0) {
        html += `<div class="my-niches-label">📝 My Video Ideas (${userVideoIdeas.length})</div>
                 <div class="opts" id="my-ideas-wrap" style="max-height: 300px; overflow-y: auto; margin-bottom: 12px;">`;
        userVideoIdeas.forEach(idea => {
            const isUsed = usedLower.includes(idea.toLowerCase());
            html += `<div class="opt${ans.title === idea ? ' sel' : ''}${isUsed ? ' used-idea' : ''}" 
                          data-v="${esc(idea)}" data-source="user"
                          style="${isUsed ? 'opacity:0.5; cursor:not-allowed;' : ''}"
                          onclick="${isUsed ? '' : `selectVideoIdea(this, '${esc(idea)}', 'user')`}">
                         ${idea} ${isUsed ? ' ✓ (used)' : ''}
                     </div>`;
        });
        html += `</div>`;
    }
    
    // Common Ideas section (admin_id=0, manually added)
    const freshCommonIdeas = commonIdeas.filter(i => !usedLower.includes(i.toLowerCase()) && !userVideoIdeas.includes(i));
    if (freshCommonIdeas.length > 0) {
        html += `<div class="divider-label">📚 Common Ideas</div>
                 <div class="opts" id="common-ideas-wrap" style="max-height: 200px; overflow-y: auto; margin-bottom: 12px;">`;
        freshCommonIdeas.forEach(idea => {
            html += `<div class="opt${ans.title === idea ? ' sel' : ''}" 
                          data-v="${esc(idea)}" data-source="common"
                          onclick="selectVideoIdea(this, '${esc(idea)}', 'common')">
                         ${idea}
                     </div>`;
        });
        html += `</div>`;
    }
    
    // If no ideas at all, show empty state
    if (userVideoIdeas.length === 0 && freshCommonIdeas.length === 0) {
        html += `<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#92400e;margin-bottom:12px;">
                    💡 No video ideas yet. Generate AI suggestions or add your own below.
                 </div>`;
    }
    
    // Custom input and AI button
    html += `<div class="custom-row" style="margin-top: 12px;">
                <input class="custom-in" id="opts-wrap-cust-in" placeholder="Or type your own video idea…" style="flex: 1;">
                <button class="custom-add" id="opts-wrap-cust-btn">Add Idea</button>
             </div>
             <button class="more-btn" id="ai-suggestions-btn" 
                     data-niche="${esc(currentNiche)}" data-category="${esc(currentCategory)}"
                     style="margin: 12px 0 0 0; width: 100%; background: linear-gradient(135deg, #8b5cf6, #6d28d9); color: white; border: none;">
                 🤖 Generate AI Suggestions
             </button>`;
    
    body.innerHTML = html;

    // Attach AI suggestions handler safely (avoids inline onclick quote-escaping issues)
    const aiBtn = document.getElementById('ai-suggestions-btn');
    if (aiBtn) aiBtn.onclick = () => loadAiSuggestionsForStep(aiBtn.dataset.niche, aiBtn.dataset.category);
    
    // Attach event handlers for selecting ideas
    body.querySelectorAll('#my-ideas-wrap .opt, #common-ideas-wrap .opt').forEach(opt => {
        if (!opt.onclick) { // Only if not already set inline
            opt.onclick = (e) => {
                if (opt.style.cursor === 'not-allowed') return;
                body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                opt.classList.add('sel');
                ans.title = opt.dataset.v;
                setNext(true);
                
                // If selecting a common idea, save a copy for this user
                if (opt.dataset.source === 'common') {
                    saveVideoIdeaToDB(currentNiche, currentCategory, opt.dataset.v, false, false);
                    showToast('✓ Idea added to your collection');
                }
            };
        }
    });
    
    // Handle custom add
    const custBtn = document.getElementById('opts-wrap-cust-btn');
    const custIn = document.getElementById('opts-wrap-cust-in');
    if (custBtn) {
        custBtn.onclick = async () => {
            const v = custIn.value.trim();
            if (!v) return;
            custIn.value = '';
            
            const usedLowerLocal = window._usedVideoIdeasLower || [];
            if (usedLowerLocal.includes(v.toLowerCase())) {
                showToast('This idea has already been used');
                return;
            }
            
            await saveVideoIdeaToDB(currentNiche, currentCategory, v, false, false);
            await loadUserVideoIdeas(currentNiche, currentCategory, 1);
            
            // Re-render
            await renderAI(s);
            showToast('✓ Idea added to your collection');
        };
    }
    if (custIn) {
        custIn.onkeydown = e => { if (e.key === 'Enter' && custBtn) custBtn.click(); };
    }
    
    return;
}

async function loadAiSuggestionsForStep(niche, category) {
    const body = document.getElementById('step-body');
    if (!body) return;

    // Show loading state inline
    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating AI ideas…</span></div>`;

    try {
        const fd = new FormData();
        fd.append('ajax_action',   'get_ai_video_suggestions');
        fd.append('niche_name',    niche);
        fd.append('category_name', category);
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();

        if (!d.success || !d.suggestions || !d.suggestions.length) {
            showToast('Could not generate suggestions: ' + (d.error || 'Please try again.'));
            // Re-render the normal idea list on failure
            videoIdeasShowAi = false;
            renderVideoIdeaList();
            return;
        }

        const usedLower = window._usedVideoIdeasLower || [];

        // ── Render inline as .opt buttons — same as niches & categories ────────
        let html = `<div class="my-niches-label">🤖 AI Suggestions — click to select &amp; save</div>
                    <div class="opts" id="ai-step-wrap">`;

        d.suggestions.forEach(idea => {
            const used = usedLower.includes(idea.toLowerCase());
            html += `<div class="opt${used ? ' used-idea' : ''}"
                          data-v="${esc(idea)}" data-source="ai"
                          style="${used ? 'opacity:.5;cursor:not-allowed;' : ''}"
                          >${idea}${used ? ' ✓' : ''}</div>`;
        });

        html += `</div>
                 <button class="more-btn" id="ai-back-btn" style="margin:10px 0;width:100%;">← Back to My Ideas</button>
                 <div class="custom-row" style="margin-top:8px;">
                   <input class="custom-in" id="opts-wrap-cust-in" placeholder="Or type your own…" style="flex:1;">
                   <button class="custom-add" id="opts-wrap-cust-btn">Add</button>
                 </div>`;

        body.innerHTML = html;

        // ── Click handler: select + save to DB ────────────────────────────────
        body.querySelectorAll('#ai-step-wrap .opt:not(.used-idea)').forEach(opt => {
            opt.onclick = async () => {
                const idea = opt.dataset.v;

                // Highlight
                body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                opt.classList.add('sel');
                ans.title = idea;
                setNext(true);

                // Save to DB
                opt.innerHTML = idea + ' <span style="color:#16a34a;font-size:11px;">✓ Saving…</span>';
                await saveVideoIdeaToDB(niche, category, idea, false, false);
                opt.innerHTML = idea + ' <span style="color:#16a34a;font-size:11px;">✓ Saved</span>';
                showToast('✓ Idea saved to your collection');

                // Add to local list
                if (!videoIdeasMyList.includes(idea)) {
                    videoIdeasMyList.unshift(idea);
                    videoIdeasTotalCount++;
                }
            };
        });

        // Back button
        document.getElementById('ai-back-btn').onclick = async () => {
            await loadUserVideoIdeas(niche, category, 1);
            videoIdeasShowAi = false;
            renderVideoIdeaList();
        };

        // Custom add
        const custBtn = document.getElementById('opts-wrap-cust-btn');
        const custIn  = document.getElementById('opts-wrap-cust-in');
        if (custBtn) custBtn.onclick = () => addCustomVideoIdea();
        if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addCustomVideoIdea(); };

    } catch(e) {
        showToast('Error: ' + e.message);
        videoIdeasShowAi = false;
        renderVideoIdeaList();
    }
}

function showAiSuggestionsList(suggestions, niche, category) {
    // No modal — delegate to inline renderer
    loadAiSuggestionsForStep(niche, category);
}

function escapeHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

async function saveAiSuggestionAndClose(idea, niche, category, element) {
    // Legacy stub — no longer shows a modal
    await saveVideoIdeaToDB(niche, category, idea, false, false);
    showToast('✓ Idea saved to your collection');
}

    // ── FALLBACK: original AI call for other step types ───────────────────────
    document.getElementById('step-body').innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Asking AI…</span></div>`;
    const payload = {}; (s.aiPayload || []).forEach(k => { payload[k] = ans[k]; });
    try {
        const r   = await fetch(s.aiUrl, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const d   = await r.json();
        const items = d[s.resKey] || [];
        if (!items.length) throw new Error('empty');
        stepOpts[s.key] = [...items];
        renderOpts(stepOpts[s.key], s.key, true);
    } catch(e) {
        document.getElementById('step-body').innerHTML =
            `<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load suggestions — add your own below</div>
             <div class="custom-row"><input class="custom-in" id="opts-wrap-cust-in" placeholder="Type here…"><button class="custom-add" id="opts-wrap-cust-btn">Add</button></div>`;
        document.getElementById('opts-wrap-cust-btn').onclick = () => addCustomGeneric(s.key, 'opts-wrap', ans, setNext);
        document.getElementById('opts-wrap-cust-in').onkeydown = e => { if (e.key === 'Enter') addCustomGeneric(s.key, 'opts-wrap', ans, setNext); };
    }
}

// ── Video idea step ────────────────────────────────────────────────────────────
async function renderVideoIdeaSelect(s, aiIdeas) {
    const niche = ans.niche || '';
    const category = ans.topic || '';
    
    // Load first page of ideas
    await loadUserVideoIdeas(niche, category, 1);
    
    // Store AI ideas separately if provided
    if (aiIdeas && aiIdeas.length > 0) {
        videoIdeasAiSuggestions = aiIdeas;
    }
    
    videoIdeasShowAi = false;
    renderVideoIdeaList();
    
    // Set up inline actions (More button disabled - replaced by AI Suggestions button)
    setInlineActions(false, null, 'nextBtn', null, () => { 
        autoSubmitCustomInput(); 
        goNext(); 
    });
}
// ── Category step ──────────────────────────────────────────────────────────────
async function renderCategorySelect(s, nicheName, aiCategories) {
    const myCats    = userCategories[nicheName] || await loadUserCategories(nicheName);
    const body      = document.getElementById('step-body');
    const isWizard  = (s.key === 'topic');
    const ansObj    = isWizard ? ans : campAns;
    const setNextFn = isWizard ? setNext : campSetNext;
    let html = '';

    const isInternal        = window._isInternalUser !== false;
    const commonCatsFromDB  = isInternal ? ((window._commonCategories || {})[nicheName] || []) : [];
    const allUsedLower = myCats.map(c => c.toLowerCase());

    if (myCats.length > 0) {
        html += `<div class="my-niches-label">My Categories for ${nicheName}</div><div class="opts" id="my-cats-wrap">`;
        myCats.forEach(c => {
            html += `<div class="opt${ansObj[s.key]===c?' sel':''}" data-v="${esc(c)}" data-source="user" style="display:inline-flex;align-items:center;">
                <span class="opt-label">${c}</span>
                <button class="opt-del" title="Remove" onclick="event.stopPropagation();deleteCategory('${esc(nicheName)}','${esc(c)}',this,this.closest('.opt'))">✕</button>
            </div>`;
        });
        html += `</div>`;
    }

    // Build combined list: DB common + AI suggestions, deduped against my cats
    const myCatsLower = myCats.map(c => c.toLowerCase());
    const combinedCats = [...new Set([...commonCatsFromDB, ...aiCategories])].filter(c => !myCatsLower.includes(c.toLowerCase()));

    if (myCats.length > 0 || combinedCats.length > 0) {
        html += `<div class="divider-label">${myCats.length > 0 ? 'All Categories' : 'Categories'}</div>`;
    }
    html += `<div class="opts" id="opts-wrap">`;
    combinedCats.forEach(c => { html += `<div class="opt${ansObj[s.key]===c?' sel':''}" data-v="${esc(c)}">${c}</div>`; });
    html += `</div>`;
    html += `<div class="custom-row"><input class="custom-in" id="opts-wrap-cust-in" placeholder="Add categories, comma separated…"><button class="custom-add" id="opts-wrap-cust-btn">Add</button></div>`;
    body.innerHTML = html;

    // ── Selection handler ─────────────────────────────────────────────────────
    body.querySelectorAll('.opt').forEach(b => {
        b.onclick = (e) => {
            if (e.target.classList.contains('opt-del')) return;
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel'); ansObj[s.key] = b.dataset.v; setNextFn(true);
            saveCategoryToDB(nicheName, b.dataset.v, false);
        };
    });

    // ── Add custom categories (comma separated) ───────────────────────────────
    const custBtn = document.getElementById('opts-wrap-cust-btn');
    const custIn  = document.getElementById('opts-wrap-cust-in');
	

	
	
	
    function addCustomCategories() {
        const raw = custIn.value.trim();
        if (!raw) return;
        custIn.value = '';

        const entries = raw.split(',')
            .map(s => s.trim())
            .filter(Boolean)
            .map(s => s.charAt(0).toUpperCase() + s.slice(1));
        const unique = [...new Set(entries)];

        unique.forEach(v => {
            // Deduplicate against existing stepOpts
            if (!stepOpts[s.key]) stepOpts[s.key] = [];
            if (stepOpts[s.key].map(x => x.toLowerCase()).includes(v.toLowerCase())) return;
            stepOpts[s.key].push(v);

            // Add to userCategories memory
            if (!userCategories[nicheName]) userCategories[nicheName] = [];
            if (!userCategories[nicheName].map(x => x.toLowerCase()).includes(v.toLowerCase())) {
                userCategories[nicheName].unshift(v);
            }

            // Ensure "My Categories" section exists in DOM
            let myWrap = document.getElementById('my-cats-wrap');
            if (!myWrap) {
                myWrap = document.createElement('div');
                myWrap.id = 'my-cats-wrap';
                myWrap.className = 'opts';

                const label = document.createElement('div');
                label.className = 'my-niches-label';
                label.textContent = `My Categories for ${nicheName}`;

                const divider = document.createElement('div');
                divider.className = 'divider-label';
                divider.textContent = 'All Categories';

                const mainWrap = document.getElementById('opts-wrap');
                body.insertBefore(divider,  mainWrap);
                body.insertBefore(myWrap,   divider);
                body.insertBefore(label,    myWrap);
            }

            // Deselect all, create and insert button
            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));

            const b = document.createElement('div');
            b.className      = 'opt sel';
            b.dataset.v      = v;
            b.dataset.source = 'user';
            b.style.cssText  = 'display:inline-flex;align-items:center;';
            b.innerHTML      = `<span class="opt-label">${v}</span>
                <button class="opt-del" title="Remove" onclick="event.stopPropagation();deleteCategory('${esc(nicheName)}','${esc(v)}',this,this.closest('.opt'))">✕</button>`;
            b.onclick = (e) => {
                if (e.target.classList.contains('opt-del')) return;
                body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                b.classList.add('sel'); ansObj[s.key] = v; setNextFn(true);
                saveCategoryToDB(nicheName, v, false);
            };
            myWrap.insertBefore(b, myWrap.firstChild);
            ansObj[s.key] = v;
            saveCategoryToDB(nicheName, v, false);
        });

        setNextFn(true);
    }

    if (custBtn) custBtn.onclick  = addCustomCategories;
    if (custIn)  custIn.onkeydown = e => { if (e.key === 'Enter') addCustomCategories(); };

    // ── Auto-select first if nothing selected ─────────────────────────────────
    if (!ansObj[s.key]) {
        const firstOpt = body.querySelector('.opt');
        if (firstOpt) firstOpt.click();
    } else {
        setNextFn(true);
    }

    // ── More button / Continue ────────────────────────────────────────────────
    const oldMore = document.getElementById('more-btn'); if (oldMore) oldMore.remove();

    const moreFn = s.morePrompt ? async () => {
        const moreBtn = document.getElementById('more-btn') || document.querySelector('.more-btn-sm[id="more-btn"]');
        if (moreBtn) { moreBtn.disabled = true; moreBtn.innerHTML = '<span class="spin">⟳</span> Loading…'; }
        const existing = [...stepOpts[s.key]];
        const prompt   = s.morePrompt(existing, isWizard ? ans : campAns);
        try {
            const r = await fetch('generate_more_opts.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({prompt}) });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const d = await r.json(); if (!d.success) throw new Error(d.error || 'Server error');
            let added = 0; const newCats = [];
            (d.items || []).forEach(item => {
                const c = String(item).trim();
                if (c && !stepOpts[s.key].includes(c)) {
                    stepOpts[s.key].push(c); added++; newCats.push(c);
                    const wrap = document.getElementById('opts-wrap');
                    if (wrap) {
                        const b = document.createElement('div');
                        b.className = 'opt'; b.dataset.v = c; b.textContent = c;
                        b.onclick = (e) => {
                            if (e.target.classList.contains('opt-del')) return;
                            body.querySelectorAll('.opt').forEach(x => x.classList.remove('sel'));
                            b.classList.add('sel'); ansObj[s.key] = c; setNextFn(true);
                            saveCategoryToDB(nicheName, c, true);
                        };
                        wrap.appendChild(b);
                    }
                }
            });
            if (newCats.length > 0) bulkSaveCommonCategories(nicheName, newCats);
            if (moreBtn) {
                if (added === 0) { moreBtn.textContent = 'No more'; moreBtn.disabled = true; }
                else { moreBtn.disabled = false; moreBtn.innerHTML = '<span>+</span> More'; }
            }
        } catch(e) {
            if (moreBtn) { moreBtn.disabled = false; moreBtn.innerHTML = '<span>+</span> More'; }
            showToast('Could not load more: ' + e.message);
        }
    } : null;

    if (isWizard) {
        // Inject inline into header row
        setInlineActions(
            true,
            'more-btn',
            'nextBtn',
            moreFn,
            () => { autoSubmitCustomInput(); goNext(); }
        );
    } else {
        // Campaign — bottom nav
        const navBar = document.getElementById('camp-nav-bar');
        if (s.morePrompt && navBar) {
            const moreBtn = document.createElement('button');
            moreBtn.id = 'more-btn'; moreBtn.className = 'more-btn'; moreBtn.innerHTML = '<span>+</span> More';
            moreBtn.onclick = moreFn;
            navBar.insertBefore(moreBtn, document.getElementById('campNextBtn'));
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUMMARY & SCRIPT GENERATION
// ═══════════════════════════════════════════════════════════════════════════════
function showSummary() {
    document.getElementById('prog').style.width = '100%';
    document.getElementById('step-label').textContent = 'Ready';
    document.getElementById('step-q').textContent     = '';
    // Hide inline actions and Back button for summary screen
    const actionsEl = document.getElementById('step-q-actions');
    if (actionsEl) { actionsEl.style.display = 'none'; actionsEl.innerHTML = ''; }
    document.getElementById('backBtn').style.visibility = 'hidden';
    document.getElementById('cardTitle').textContent   = 'Your Video Brief';
    document.getElementById('cardSubtitle').textContent = 'Step 3 of 5  ·  Review your choices then generate your script';

    const wizRows = Object.entries(ans).map(([k,v]) =>
        `<div class="sum-row"><span class="sum-key">${WIZARD_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`).join('');
    const setRows = Object.entries(settings).map(([k,v]) =>
        `<div class="sum-row"><span class="sum-key">${SETTING_LABELS[k]||k}</span><span class="sum-val">${v}</span></div>`).join('');

    document.getElementById('step-body').innerHTML = `
        <div class="done-title">Your brief is complete</div>
        <div class="done-sub">Tap ⚙ to change language, format or duration anytime</div>
        <div class="free-trial-badge" style="margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:16px;">${_quota.credit_balance <= 0 ? '🔒' : '🎬'}</span>
            <span>
                <strong>${_quota.plan_type === 'free_trial' ? 'Free Trial' : _quota.plan_type === 'personal' ? 'Personal Plan' : 'Agency Plan'}</strong>
                &nbsp;·&nbsp;
                Available credits: <strong style="color:${_quota.credit_balance <= 0 ? '#dc2626' : _quota.credit_balance <= 3 ? '#d97706' : '#166534'};">${_quota.credit_balance}</strong>
                &nbsp;·&nbsp;
                <span style="font-size:11px;opacity:.85;">Standard/B-Roll = 1 &nbsp;|&nbsp; Podcast/Talking Head = 2</span>
            </span>
            ${_quota.credit_balance <= 2 ? '<a href="pricing.php" style="color:#92400e;font-weight:700;white-space:nowrap;">Upgrade →</a>' : ''}
        </div>

	  <div class="summary">
          <div class="sum-section">Content</div>${wizRows}
          <div class="sum-section">Settings</div>${setRows}
        </div>
        <div id="script-output"></div>
        <button class="gen-btn" id="gen-btn" onclick="generateScript()">🚀 Generate Video Script</button>
        <button class="restart-btn" onclick="restart()">Start over</button>`;
}

async function generateScript() {
    const btn = document.getElementById('gen-btn');
    const out = document.getElementById('script-output');
    btn.disabled = true; btn.textContent = '⏳ Generating…';
    out.innerHTML = `<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Writing your script…</span></div>`;

    try {
        const payload = Object.assign({}, ans, settings, {
            short_sentences:true, scene_format:true,
            max_words_per_scene:12, scene_count:'6-8', scene_break_tag:AZURE_BREAK
        });
        const r    = await fetch('generate_script.php', { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include', body:JSON.stringify(payload) });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { throw new Error('Server error: ' + text.substring(0, 200)); }
        if (!d.success) throw new Error(d.error || 'Script generation failed');

        const script = enforceSceneBreaks(splitIntoScenes(d.script));
        window._wizScriptRaw = d.script;
        window._wizAns       = Object.assign({}, ans, settings);
        window._wizPodcastId = d.podcast_id || null;
        
		
		window._wizData      = Object.assign({}, ans, settings);

        out.innerHTML = `
            <div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Generated Script</div>
            <textarea id="script-text"
              style="width:100%;min-height:200px;padding:14px;border:1.5px solid var(--border);border-radius:10px;font-family:monospace;font-size:13px;line-height:1.8;resize:vertical;outline:none;background:#f8fafc;color:var(--text);"
              oninput="window._wizScriptRaw = this.value"
            >${escHtml(script)}</textarea>
            <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
              <button class="build-btn" onclick="openS2('wizard')" style="width:100%;background:#16a34a;border-color:#16a34a;color:#fff;font-size:15px;padding:14px;">Approve &amp; Build Video  →</button>
            </div>`;
        btn.textContent = '🔄 Regenerate'; btn.disabled = false;

    } catch(e) {
        out.innerHTML = `<div style="color:#c00;font-size:13px;margin:12px 0">Error: ${e.message}</div>`;
        btn.textContent = 'Try Again →'; btn.disabled = false;
    }
}

function restart() { cur=0; ans={}; stepOpts={}; clearMoreBtn(); goToMenu(); }

// ═══════════════════════════════════════════════════════════════════════════════
// CAMPAIGN ENGINE
// ═══════════════════════════════════════════════════════════════════════════════
function campSetNext(v) {
    document.getElementById('campNextBtn').disabled = !v;
    const inlineCont = document.getElementById('camp-step-q-cont-btn');
    if (inlineCont) inlineCont.disabled = !v;
}
function campSetBack()  { document.getElementById('campBackBtn').style.visibility = campCur === 0 ? 'hidden' : 'visible'; }

function renderStartDateStep() {
    const body = document.getElementById('camp-step-body');
    const now         = new Date();
    const minDate     = new Date(now.getTime() + 24*60*60*1000);
    const defaultDate = new Date(now.getTime() + 24*60*60*1000);
    defaultDate.setHours(9, 0, 0, 0);
    if (defaultDate < minDate) defaultDate.setTime(minDate.getTime() + 60*60*1000);
    const pad = n => String(n).padStart(2, '0');
    const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const minStr     = fmt(minDate);
    const defaultStr = fmt(defaultDate);
    const savedVal   = campAns.camp_start_date || defaultStr;

    body.innerHTML = `
        <div style="margin-bottom:16px;">
          <div style="font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.6;">
            Your first post will go out at the selected date and time.<br>
            <strong style="color:var(--dark-blue);">Minimum start: 24 hours from now.</strong>
            Each subsequent post will be scheduled automatically based on your posting frequency.
          </div>
          <label style="display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;">Campaign Start Date &amp; Time</label>
          <input type="datetime-local" id="campStartDateInput" min="${minStr}" value="${savedVal}"
            style="width:100%;padding:12px 14px;font-size:15px;font-weight:600;border:1.5px solid var(--border);border-radius:10px;outline:none;color:var(--dark-blue);background:#fff;transition:border-color .15s;"
            oninput="onCampStartDateChange(this)"
            onfocus="this.style.borderColor='var(--purple)'"
            onblur="this.style.borderColor='var(--border)'">
          <div id="campStartDateError" style="display:none;color:#dc2626;font-size:12px;margin-top:6px;padding:8px 12px;background:#fef2f2;border-radius:6px;">
            ⚠️ Start date must be at least 24 hours from now.
          </div>
        </div>
        <div id="campSchedulePreview" style="background:#f0f4ff;border:1px solid #c7d7fd;border-radius:10px;padding:14px 16px;font-size:13px;color:var(--dark-blue);">
          <div style="font-weight:700;margin-bottom:8px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);">📅 Schedule Preview</div>
          <div id="campScheduleLines">Select a date to preview the schedule</div>
        </div>`;

    onCampStartDateChange(document.getElementById('campStartDateInput'));
}
// ═══════════════════════════════════════════════════════════════════════════════
// VIDEO QUOTA
// ═══════════════════════════════════════════════════════════════════════════════
let _quota = { credit_balance: 0, plan_type: 'free_trial' };

async function loadVideoQuota() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_video_quota');
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            _quota = d;
            renderQuotaBadge();
        }
    } catch(e) {}
}

function reelCreditCost() {
    const rt = (settings.reel_type || '').toLowerCase();
    return (rt.includes('podcast') || rt.includes('talking head')) ? 2 : 1;
}

function renderQuotaBadge() {
    const credits   = _quota.credit_balance;
    const plan      = _quota.plan_type;
    const planLabel = plan === 'free_trial' ? 'Free Trial'
                    : plan === 'personal'   ? 'Personal Plan'
                    : plan === 'agency'     ? 'Agency Plan' : plan;
    const color     = credits <= 0 ? '#ef4444' : credits <= 3 ? '#f59e0b' : '#10b981';
    const bg        = credits <= 0 ? '#fee2e2' : credits <= 3 ? '#fef3c7' : '#dcfce7';
    const border    = credits <= 0 ? '#fca5a5' : credits <= 3 ? '#fde68a' : '#86efac';

    document.querySelectorAll('.free-trial-badge').forEach(el => {
        el.style.display     = 'flex';
        el.style.alignItems  = 'center';
        el.style.gap         = '8px';
        el.style.flexWrap    = 'wrap';
        el.style.background  = bg;
        el.style.borderColor = border;
        el.style.color       = credits <= 0 ? '#991b1b' : credits <= 3 ? '#92400e' : '#166534';
        el.innerHTML = `
            <span style="font-size:16px;">${credits <= 0 ? '🔒' : '🎬'}</span>
            <span>
                <strong>${planLabel}</strong>
                &nbsp;·&nbsp;
                Available credits: <strong style="color:${color};">${credits}</strong>
                &nbsp;·&nbsp;
                <span style="font-size:11px;opacity:.85;">Standard/B-Roll = 1 credit &nbsp;|&nbsp; Podcast/Talking Head = 2 credits</span>
            </span>
            ${credits <= 2 ? '<a href="pricing.php" style="color:#92400e;font-weight:700;white-space:nowrap;">Upgrade →</a>' : ''}
        `;
    });
}

function isQuotaExceeded(costOverride) {
    const cost = costOverride ?? reelCreditCost();
    return _quota.credit_balance < cost;
}

function showQuotaModal() {
    const credits   = _quota.credit_balance;
    const plan      = _quota.plan_type;
    const planLabel = plan === 'free_trial' ? 'Free Trial'
                    : plan === 'personal'   ? 'Personal Plan'
                    : plan === 'agency'     ? 'Agency Plan' : plan;

    document.getElementById('quotaCountLabel').textContent = credits;
    document.getElementById('quotaPlanLabel').textContent  =
        `${planLabel} · ${credits <= 0 ? 'No credits remaining' : credits + ' credit' + (credits !== 1 ? 's' : '') + ' remaining'}`;

    const overlay = document.getElementById('quotaOverlay');
    overlay.style.display = 'flex';
}

function closeQuotaModal() {
    document.getElementById('quotaOverlay').style.display = 'none';
}

// Close quota modal on backdrop click
document.addEventListener('click', function(e) {
    const overlay = document.getElementById('quotaOverlay');
    if (e.target === overlay) closeQuotaModal();
});
function onCampStartDateChange(input) {
    const val    = input.value; if (!val) return;
    const chosen = new Date(val);
    const now    = new Date();
    const minMs  = now.getTime() + 24*60*60*1000;
    const errEl  = document.getElementById('campStartDateError');
    if (chosen.getTime() < minMs) {
        if (errEl) errEl.style.display = 'block';
        input.style.borderColor = '#dc2626';
        campAns.camp_start_date = null; campSetNext(false); return;
    }
    if (errEl) errEl.style.display = 'none';
    input.style.borderColor = 'var(--purple)';
    campAns.camp_start_date = val; campSetNext(true);
    renderSchedulePreview(chosen);
}

function renderSchedulePreview(startDate) {
    const rateMap = { '1 post every 3 days':3,'1 post every 2 days':2,'1 post per day':1,'2 posts per day':0.5,'3 posts per day':0.333 };
    const daysGap     = rateMap[campAns.camp_posts_per_day] || 1;
    const totalTitles = (campAns.camp_titles || []).length || 7;
    const lines       = document.getElementById('campScheduleLines'); if (!lines) return;
    const pad         = n => String(n).padStart(2, '0');
    const dayNames    = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const monthNames  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    let html = '';
    const maxPreview = Math.min(totalTitles, 5);
    for (let i = 0; i < maxPreview; i++) {
        const postTime = new Date(startDate.getTime() + i * daysGap * 24 * 60 * 60 * 1000);
        const day   = dayNames[postTime.getDay()];
        const date  = `${pad(postTime.getDate())} ${monthNames[postTime.getMonth()]} ${postTime.getFullYear()}`;
        const time  = `${pad(postTime.getHours())}:${pad(postTime.getMinutes())}`;
        const title = (campAns.camp_titles||[])[i]
            ? `<span style="color:var(--muted);"> — ${campAns.camp_titles[i].substring(0,40)}${campAns.camp_titles[i].length>40?'…':''}</span>` : '';
        html += `<div style="display:flex;align-items:center;gap:10px;padding:5px 0;border-bottom:1px solid #dde6ff;font-size:12px;">
          <span style="font-weight:700;color:var(--dark-blue);min-width:32px;">${day}</span>
          <span style="color:var(--muted);">${date}</span>
          <span style="background:var(--purple-lt);color:var(--purple);border-radius:12px;padding:1px 8px;font-weight:600;">${time}</span>
          ${title}</div>`;
    }
    if (totalTitles > maxPreview) html += `<div style="font-size:12px;color:var(--muted);padding-top:6px;">…and ${totalTitles - maxPreview} more posts</div>`;
    lines.innerHTML = html || '<span style="color:var(--muted);">Select a date to preview</span>';
}

function computeScheduleDates(startDateStr, postsPerDayStr, totalCount) {
    if (!startDateStr) return [];
    const rateMap = { '1 post every 3 days':3,'1 post every 2 days':2,'1 post per day':1,'2 posts per day':0.5,'3 posts per day':0.333 };
    const daysGap = rateMap[postsPerDayStr] || 1;
    const start   = new Date(startDateStr);
    const dates   = [];
    for (let i = 0; i < totalCount; i++) {
        const d = new Date(start.getTime() + i * daysGap * 24 * 60 * 60 * 1000);
        dates.push(d.toISOString().slice(0, 16).replace('T', ' '));
    }
    return dates;
}

async function renderProductionSetup() {
    const body = document.getElementById('camp-step-body');
    body.innerHTML = `<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Loading voices…</span></div>`;
    campSetNext(false);
    let voiceOpts = '';
    try {
        const langCode = langCodeFromName(settings.language);
        const r = await fetch('get_voices.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'lang_code='+encodeURIComponent(langCode) });
        if (!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json(); const voices=d.voices||[]; if(!voices.length) throw new Error('empty');
        voiceOpts = voices.map(v=>`<option value="${esc(v.voice_id)}">${v.voice_name}</option>`).join('');
    } catch(e) {
        const fallback=[{id:'openai:alloy',name:'Alloy'},{id:'openai:echo',name:'Echo'},
                        {id:'openai:fable',name:'Fable'},{id:'openai:onyx',name:'Onyx'},
                        {id:'openai:nova',name:'Nova'},{id:'openai:shimmer',name:'Shimmer'}];
        voiceOpts = fallback.map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
    }
    const saved      = campAns.camp_production || {};
    const savedVoice = saved.voice_id   || '';
    const savedRate  = saved.rate       || '1.25';
    const savedMedia = saved.media_type || 'stock_videos';
    const voiceOptsHtml = voiceOpts.replace(
        new RegExp(`value="${savedVoice.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}"`),
        `value="${savedVoice}" selected`
    );

    const aiImagesHtml = IS_FREE_TRIAL
        ? `<div class="s2-media-opt" data-val="unique_images" style="opacity:0.4;cursor:not-allowed;" title="Upgrade to use AI Images">🤖 AI Images 🔒</div>
           <div style="font-size:12px;color:#92400e;margin-top:6px;">🔒 AI Images require an upgrade. <a href="upgrade.php" style="color:#92400e;font-weight:700;">Upgrade →</a></div>`
        : `<div class="s2-media-opt${savedMedia==='unique_images'?' sel':''}" data-val="unique_images" onclick="selCampMedia(this)">🤖 AI Images</div>`;

    body.innerHTML = `
        <div class="s2-section" style="margin-bottom:18px;">
          <div class="s2-label">Host Voice</div>
          <select id="campProdVoice" class="s2-select" onchange="onCampProdChange()">${voiceOptsHtml}</select>
        </div>
        <div class="s2-section" style="margin-bottom:18px;">
          <div class="s2-label">Speech Rate</div>
          <select id="campProdRate" class="s2-select" onchange="onCampProdChange()">
            <option value="0.9"${savedRate==='0.9'?' selected':''}>0.9× — Slightly slow</option>
            <option value="1.0"${savedRate==='1.0'?' selected':''}>1.0× — Normal</option>
            <option value="1.1"${savedRate==='1.1'?' selected':''}>1.1× — Slightly fast</option>
            <option value="1.2"${savedRate==='1.2'?' selected':''}>1.2× — Fast</option>
            <option value="1.25"${savedRate==='1.25'?' selected':''}>1.25× — Default</option>
            <option value="1.3"${savedRate==='1.3'?' selected':''}>1.3× — Very fast</option>
          </select>
        </div>
        <div class="s2-section" style="margin-bottom:8px;">
          <div class="s2-label">Media Type</div>
          <div class="s2-media-opts">
            <div class="s2-media-opt${savedMedia==='stock_images'?' sel':''}" data-val="stock_images" onclick="selCampMedia(this)">📷 Stock Images</div>
            <div class="s2-media-opt${savedMedia==='stock_videos'?' sel':''}" data-val="stock_videos" onclick="selCampMedia(this)">🎥 Stock Videos</div>
            ${aiImagesHtml}
          </div>
        </div>`;
    onCampProdChange();
}

function selCampMedia(el) {
    document.querySelectorAll('#camp-step-body .s2-media-opt').forEach(x => x.classList.remove('sel'));
    el.classList.add('sel'); onCampProdChange();
}

function onCampProdChange() {
    const voice = document.getElementById('campProdVoice')?.value || '';
    const rate  = document.getElementById('campProdRate')?.value  || '1.0';
    const media = document.querySelector('#camp-step-body .s2-media-opt.sel')?.dataset.val || 'stock_videos';
    campAns.camp_production = { voice_id:voice, rate, media_type:media };
    campSetNext(!!voice);
}

async function campRender() {
    document.getElementById('camp-prog').style.width = Math.round((campCur / CAMP_STEPS.length) * 100) + '%';
    campSetBack(); campSetNext(false);
    const s = CAMP_STEPS[campCur];
    document.getElementById('camp-step-label').textContent = s.label;
    document.getElementById('camp-step-q').textContent     = s.q;
    campUpdateSubtitle();

    // Reset camp inline actions — most steps use bottom nav
    const campActionsEl = document.getElementById('camp-step-q-actions');
    const campNavBar    = document.getElementById('camp-nav-bar');
    if (campActionsEl) { campActionsEl.style.display = 'none'; campActionsEl.innerHTML = ''; }
    if (campNavBar)    campNavBar.style.display = 'flex';

    if      (s.type === 'niche-select')    { await renderNicheSelect(s,'camp-step-body','camp-opts-wrap','camp-more-btn','camp-nav-bar','campNextBtn',campAns); }
    else if (s.type === 'duration-select') { renderDurationStep(s.opts, s.key, 'camp-step-body', campAns, campSetNext); }
    else if (s.type === 'camp-duration')   { campRenderDuration(s.opts, s.key); }
    else if (s.type === 'start-date')      { renderStartDateStep(); }
    else if (s.type === 'production-setup'){ await renderProductionSetup(); }
    else if (s.type === 'opts')            { campRenderOpts(s.opts, s.key, false); }
    else if (s.type === 'opts+more')       { if(!campStepOpts[s.key]) campStepOpts[s.key]=[...s.opts]; campRenderOpts(campStepOpts[s.key],s.key,true); }
    else if (s.type === 'multi')           { campRenderMulti(s.opts, s.key); }
    else if (s.type === 'ai')              { await campRenderAI(s); }
    else if (s.type === 'title-select')    { await campRenderTitleSelect(); }
}

function campUpdateSubtitle() {
    const parts = [];
    if (campAns.camp_goal)     parts.push(campAns.camp_goal);
    if (campAns.camp_niche)    parts.push(campAns.camp_niche);
    if (campAns.camp_category) parts.push(campAns.camp_category);
    document.getElementById('campCardSubtitle').textContent = parts.length
        ? parts.join(' › ') : 'Build your full content calendar in one go';
}

function campRenderOpts(opts, key, showMore) {
    const body = document.getElementById('camp-step-body');

    // Free trial defaults & locks
    const FREE_TRIAL_LOCKS = {
        camp_duration:      '1 Week (7 days)',
        camp_posts_per_day: '1 post per day'
    };
    const isLockedKey = IS_FREE_TRIAL && Object.prototype.hasOwnProperty.call(FREE_TRIAL_LOCKS, key);
    if (isLockedKey) campAns[key] = FREE_TRIAL_LOCKS[key]; // always enforce

    let html = '';
    if (isLockedKey) {
        html += `<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400e;">
          <span style="font-size:16px;">🔒</span>
          <span>Free Trial is limited to <strong>${FREE_TRIAL_LOCKS[key]}</strong>. <a href="upgrade.php" style="color:#92400e;font-weight:700;text-decoration:underline;">Upgrade</a> to unlock more options.</span>
        </div>`;
    }
    html += `<div class="opts" id="camp-opts-wrap">`;
    opts.forEach(o => {
        const isLocked = isLockedKey && o !== FREE_TRIAL_LOCKS[key];
        const isSel    = campAns[key] === o;
        html += `<div class="opt${isSel ? ' sel' : ''}" data-v="${esc(o)}" data-locked="${isLocked ? '1' : '0'}"
            style="${isLocked ? 'opacity:0.4;cursor:not-allowed;' : ''}">${o}${isLocked ? ' <span style="font-size:10px;">🔒</span>' : ''}</div>`;
    });
    html += `</div>`;
    html += `<div class="custom-row">
        <input class="custom-in" id="camp-cust-in" placeholder="Or type your own…"${isLockedKey ? ' disabled style="opacity:0.4;"' : ''}>
        <button class="custom-add" id="camp-cust-btn"${isLockedKey ? ' disabled style="opacity:0.4;"' : ''}>Add</button>
    </div>`;
    body.innerHTML = html;

    document.querySelectorAll('#camp-opts-wrap .opt').forEach(b => {
        b.onclick = () => {
            if (b.dataset.locked === '1') return;
            document.querySelectorAll('#camp-opts-wrap .opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel'); campAns[key] = b.dataset.v; campSetNext(true);
        };
    });
    document.getElementById('camp-cust-btn').onclick  = () => campAddCustom(key);
    document.getElementById('camp-cust-in').onkeydown = e => { if (e.key === 'Enter') campAddCustom(key); };
    if (campAns[key]) campSetNext(true);
    const navBar  = document.getElementById('camp-nav-bar');
    const oldMore = document.getElementById('camp-more-btn'); if (oldMore) oldMore.remove();
    if (showMore && CAMP_STEPS[campCur].morePrompt) {
        const btn = document.createElement('button'); btn.id='camp-more-btn'; btn.className='more-btn';
        btn.innerHTML='<span>+</span> More'; btn.onclick=campLoadMore;
        navBar.insertBefore(btn, document.getElementById('campNextBtn'));
    }
}

function campAddCustom(key) {
    const inp=document.getElementById('camp-cust-in'); const v=inp.value.trim(); if(!v) return; inp.value='';
    if(!campStepOpts[key]) campStepOpts[key]=[];
    if(!campStepOpts[key].includes(v)) campStepOpts[key].push(v);
    document.querySelectorAll('#camp-opts-wrap .opt').forEach(x=>x.classList.remove('sel'));
    const wrap=document.getElementById('camp-opts-wrap');
    const b=document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
    b.onclick=()=>{ document.querySelectorAll('#camp-opts-wrap .opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); campAns[key]=v; campSetNext(true); };
    wrap.appendChild(b); campAns[key]=v; campSetNext(true);
}

function campRenderDuration(opts, key) {
    const body = document.getElementById('camp-step-body');
    const isLockedKey = IS_FREE_TRIAL;
    if (isLockedKey) campAns[key] = '1 Week (7 days)';

    let html = '';
    if (isLockedKey) {
        html += `<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#92400e;">
          <span style="font-size:16px;">🔒</span>
          <span>Free Trial is limited to <strong>1 Week (7 days)</strong>. <a href="upgrade.php" style="color:#92400e;font-weight:700;text-decoration:underline;">Upgrade</a> to unlock more options.</span>
        </div>`;
    }
    html += `<div class="opts" id="camp-opts-wrap">`;
    opts.forEach(o => {
        const isLocked = isLockedKey && o !== '1 Week (7 days)';
        const isSel    = campAns[key] === o;
        html += `<div class="opt${isSel?' sel':''}" data-v="${esc(o)}" data-locked="${isLocked?'1':'0'}"
            style="${isLocked?'opacity:0.4;cursor:not-allowed;':''}">${o}${isLocked?' <span style="font-size:10px;">🔒</span>':''}</div>`;
    });
    html += `</div>`;
    // Custom days input — hidden until Custom is selected
    html += `<div id="camp-dur-custom-row" style="display:${campAns[key]==='Custom'?'flex':'none'};gap:10px;align-items:center;margin-top:12px;flex-wrap:wrap;">
        <label style="font-size:13px;font-weight:600;color:var(--dark-blue);white-space:nowrap;">Number of days (max 30):</label>
        <input type="number" id="camp-dur-custom-val" min="1" max="30" value="${campAns[key+'_custom_days']||7}"
            style="width:80px;padding:8px;border:1.5px solid var(--border);border-radius:8px;font-size:15px;font-weight:700;text-align:center;">
    </div>`;
    body.innerHTML = html;

    document.querySelectorAll('#camp-opts-wrap .opt').forEach(b => {
        b.onclick = () => {
            if (b.dataset.locked === '1') return;
            document.querySelectorAll('#camp-opts-wrap .opt').forEach(x => x.classList.remove('sel'));
            b.classList.add('sel');
            campAns[key] = b.dataset.v;
            const customRow = document.getElementById('camp-dur-custom-row');
            if (customRow) customRow.style.display = b.dataset.v === 'Custom' ? 'flex' : 'none';
            if (b.dataset.v !== 'Custom') campSetNext(true);
            else campSetNext(false); // wait for day count
        };
    });

    const customInput = document.getElementById('camp-dur-custom-val');
    if (customInput) {
        customInput.oninput = () => {
            let v = Math.min(30, Math.max(1, parseInt(customInput.value) || 1));
            customInput.value = v;
            campAns[key + '_custom_days'] = v;
            // Store as readable label so daysMap can resolve it
            campAns[key] = `Custom (${v} days)`;
            campSetNext(true);
        };
    }
    if (campAns[key] && campAns[key] !== 'Custom') campSetNext(true);
}

function campRenderMulti(opts, key) {
    // Free trial: force English only; always default to English
    if (IS_FREE_TRIAL && key === 'camp_languages') {
        campAns[key] = ['English'];
    }
    if (!campAns[key] || campAns[key].length === 0) {
        campAns[key] = ['English'];
    }
    const body = document.getElementById('camp-step-body');

    let multiHtml = `<div style="font-size:12px;color:var(--muted);margin-bottom:12px;">Select one or more languages</div>`;
    if (IS_FREE_TRIAL && key === 'camp_languages') {
        multiHtml += `<div style="display:flex;align-items:center;gap:8px;background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#92400e;">
          <span style="font-size:16px;">🔒</span>
          <span>Free Trial is limited to <strong>English only</strong>. <a href="upgrade.php" style="color:#92400e;font-weight:700;text-decoration:underline;">Upgrade</a> to unlock all languages.</span>
        </div>`;
    }
    multiHtml += `<div class="opts" id="camp-multi-wrap">`;
    opts.forEach(o => {
        const isEnglish = (o === 'English');
        const isLocked  = IS_FREE_TRIAL && key === 'camp_languages' && !isEnglish;
        const isSel     = campAns[key] && campAns[key].includes(o);
        multiHtml += `<div class="opt${isSel ? ' multi-sel' : ''}" data-v="${esc(o)}"
            style="${isLocked ? 'opacity:0.4;cursor:not-allowed;' : ''}"
            data-locked="${isLocked ? '1' : '0'}">${o}${isLocked ? ' <span style="font-size:10px;">🔒</span>' : ''}</div>`;
    });
    multiHtml += `</div>`;
    body.innerHTML = multiHtml;

    document.querySelectorAll('#camp-multi-wrap .opt').forEach(b => {
        b.onclick = () => {
            if (b.dataset.locked === '1') return;
            const v = b.dataset.v;
            if (!campAns[key]) campAns[key] = [];
            if (campAns[key].includes(v)) { campAns[key] = campAns[key].filter(x => x !== v); b.classList.remove('multi-sel'); }
            else { campAns[key].push(v); b.classList.add('multi-sel'); }
            campSetNext(campAns[key].length > 0);
        };
    });
    if (campAns[key].length > 0) campSetNext(true);
}

async function campRenderAI(s) {
    const isCategory   = (s.key === 'camp_category');
    const currentNiche = campAns.camp_niche || '';
    const isInternal   = window._isInternalUser !== false;

    if (campStepOpts[s.key] && campStepOpts[s.key].length > 0) {
        if (isCategory && currentNiche) await renderCampCategorySelect(s, currentNiche, isInternal ? campStepOpts[s.key] : []);
        else campRenderOpts(campStepOpts[s.key], s.key, true);
        return;
    }
    const oldMore=document.getElementById('camp-more-btn'); if(oldMore) oldMore.remove();

    // If user has 10+ categories for this niche, skip AI call
    if (isCategory && currentNiche) {
        const existingCats = userCategories[currentNiche] || await loadUserCategories(currentNiche);
        if (existingCats.length >= 10) {
            campStepOpts[s.key] = [...existingCats];
            await renderCampCategorySelect(s, currentNiche, []);
            return;
        }
        // Non-internal: never call AI for categories — just show their own + input box
        if (!isInternal) {
            campStepOpts[s.key] = [...existingCats];
            await renderCampCategorySelect(s, currentNiche, []);
            return;
        }
    }

    document.getElementById('camp-step-body').innerHTML=`<div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Asking AI…</span></div>`;
    const payload={};
	
	
    (s.aiPayload||[]).forEach(k=>{const mk=(s.payloadMap&&s.payloadMap[k])?s.payloadMap[k]:k; payload[mk]=campAns[k]||campAns[mk];});
    try {
        const r=await fetch(s.aiUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const d=await r.json(); const items=d[s.resKey]||[]; if(!items.length) throw new Error('empty');
        campStepOpts[s.key]=[...items];
        if(isCategory && currentNiche) await renderCampCategorySelect(s, currentNiche, campStepOpts[s.key]);
        else campRenderOpts(campStepOpts[s.key], s.key, true);
    } catch(e){
        document.getElementById('camp-step-body').innerHTML=`<div style="color:#c00;font-size:13px;padding:10px 0;">Could not load — add your own below</div>
            <div class="custom-row"><input class="custom-in" id="camp-cust-in" placeholder="Type here…"><button class="custom-add" id="camp-cust-btn">Add</button></div>`;
        document.getElementById('camp-cust-btn').onclick=()=>campAddCustom(s.key);
        document.getElementById('camp-cust-in').onkeydown=e=>{if(e.key==='Enter')campAddCustom(s.key);};
    }
}

async function renderCampCategorySelect(s, nicheName, aiCategories) {
    const myCats=userCategories[nicheName]||await loadUserCategories(nicheName);
    const body=document.getElementById('camp-step-body');
    let html='';
    if(myCats.length>0){
        html+=`<div class="my-niches-label">My Categories for ${nicheName}</div><div class="opts" id="camp-cats-user">`;
        myCats.forEach(c=>{html+=`<div class="opt${campAns[s.key]===c?' sel':''}" data-v="${esc(c)}" data-source="user">${c}</div>`;});
        html+=`</div><div class="divider-label">All Categories</div>`;
    }
    html+=`<div class="opts" id="camp-cats-wrap">`;
    aiCategories.forEach(c=>{html+=`<div class="opt${campAns[s.key]===c?' sel':''}" data-v="${esc(c)}">${c}</div>`;});
    html+=`</div>`;
    html+=`<div class="custom-row"><input class="custom-in" id="camp-cat-cust-in" placeholder="Or type your own…"><button class="custom-add" id="camp-cat-cust-btn">Add</button></div>`;
    body.innerHTML=html;
    body.querySelectorAll('.opt').forEach(b=>{
        b.onclick=()=>{ body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); campAns[s.key]=b.dataset.v; campSetNext(true); saveCategoryToDB(nicheName,b.dataset.v,false); };
    });
    const custBtn=document.getElementById('camp-cat-cust-btn');
    const custIn=document.getElementById('camp-cat-cust-in');
    if(custBtn) custBtn.onclick=()=>{
        const v=custIn.value.trim(); if(!v) return; custIn.value='';
        if(!campStepOpts[s.key]) campStepOpts[s.key]=[];
        if(!campStepOpts[s.key].includes(v)) campStepOpts[s.key].push(v);
        body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));
        const wrap=document.getElementById('camp-cats-wrap');
        const b=document.createElement('div'); b.className='opt sel'; b.dataset.v=v; b.textContent=v;
        b.onclick=()=>{ body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel')); b.classList.add('sel'); campAns[s.key]=v; campSetNext(true); saveCategoryToDB(nicheName,v,false); };
        if(wrap) wrap.appendChild(b); campAns[s.key]=v; campSetNext(true); saveCategoryToDB(nicheName,v,false);
    };
    if(custIn) custIn.onkeydown=e=>{if(e.key==='Enter'&&custBtn) custBtn.click();};
    if(!campAns[s.key]){ const firstOpt=body.querySelector('.opt'); if(firstOpt) firstOpt.click(); } else campSetNext(true);
    const navBar=document.getElementById('camp-nav-bar');
    const oldMore=document.getElementById('camp-more-btn'); if(oldMore) oldMore.remove();
    const isInternalCamp = window._isInternalUser !== false;
    if(s.morePrompt && isInternalCamp){
        const moreBtn=document.createElement('button'); moreBtn.id='camp-more-btn'; moreBtn.className='more-btn'; moreBtn.innerHTML='<span>+</span> More';
        moreBtn.onclick=async()=>{
            moreBtn.disabled=true; moreBtn.innerHTML='<span class="spin">⟳</span> Loading…';
            const existing=[...(campStepOpts[s.key]||[])]; const prompt=s.morePrompt(existing,campAns);
            try{
                const r=await fetch('generate_more_opts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
                if(!r.ok) throw new Error('HTTP '+r.status);
                const d=await r.json(); if(!d.success) throw new Error(d.error||'Server error');
                let added=0;
                (d.items||[]).forEach(item=>{
                    const c=String(item).trim();
                    if(c&&!campStepOpts[s.key].includes(c)){
                        campStepOpts[s.key].push(c); added++;
                        const wrap=document.getElementById('camp-cats-wrap');
                        if(wrap){const b=document.createElement('div');b.className='opt';b.dataset.v=c;b.textContent=c;b.onclick=()=>{body.querySelectorAll('.opt').forEach(x=>x.classList.remove('sel'));b.classList.add('sel');campAns[s.key]=c;campSetNext(true);saveCategoryToDB(nicheName,c,true);};wrap.appendChild(b);}
                    }
                });
                if(added===0){moreBtn.textContent='No more';moreBtn.disabled=true;}else{moreBtn.disabled=false;moreBtn.innerHTML='<span>+</span> More';}
            }catch(e){moreBtn.disabled=false;moreBtn.innerHTML='<span>+</span> More';showToast('Could not load more: '+e.message);}
        };
        navBar.insertBefore(moreBtn,document.getElementById('campNextBtn'));
    }
}

async function campLoadMore() {
    const s=CAMP_STEPS[campCur]; const btn=document.getElementById('camp-more-btn'); if(!btn||!s.morePrompt) return;
    btn.disabled=true; btn.innerHTML='<span class="spin">⟳</span> Loading…';
    const existing=[...campStepOpts[s.key]]; const prompt=s.morePrompt(existing,campAns);
    try{
        const r=await fetch('generate_more_opts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
        if(!r.ok) throw new Error('HTTP '+r.status);
        const d=await r.json(); if(!d.success) throw new Error(d.error||'Server error');
        let added=0; (d.items||[]).forEach(item=>{const c=String(item).trim();if(c&&!campStepOpts[s.key].includes(c)){campStepOpts[s.key].push(c);added++;}});
        campRenderOpts(campStepOpts[s.key],s.key,true);
        if(added===0){const b=document.getElementById('camp-more-btn');if(b){b.textContent='No more';b.disabled=true;}}
    }catch(e){const b=document.getElementById('camp-more-btn');if(b){b.disabled=false;b.innerHTML='<span>+</span> More';}showToast('Could not load more: '+e.message);}
}
async function deleteNiche(nicheName, btnEl, wrapEl) {
    if (!confirm(`Remove "${nicheName}" from My Niches?`)) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'delete_niche');
        fd.append('niche_name',  nicheName);
        await fetch(location.href, { method:'POST', body:fd });
    } catch(e) {}
    // Remove from memory
    userNiches = userNiches.filter(n => n !== nicheName);
    // Remove button from DOM
    wrapEl.remove();
    // If "My Niches" section is now empty, remove the label and divider too
    const myWrap = document.getElementById('opts-wrap-user') || document.getElementById('camp-opts-wrap-user');
    if (myWrap && myWrap.children.length === 0) {
        // remove label above and divider below
        const prev = myWrap.previousElementSibling;
        const next = myWrap.nextElementSibling;
        if (prev && prev.classList.contains('my-niches-label')) prev.remove();
        if (next && next.classList.contains('divider-label'))   next.remove();
        myWrap.remove();
    }
}
// ── CTA save/load ─────────────────────────────────────────────────────────────
let userCTAs = [];
let globalCTAs = [];

async function loadUserCTAs() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_ctas');
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await r.json();
        userCTAs = d.ctas || [];
        
        // For display purposes, separate if needed
        // The server already merges company-specific + global
    } catch(e) { 
        userCTAs = []; 
    }
}



async function saveCTAToDB(ctaText) {
    if (!ctaText) return;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_cta');
        fd.append('cta_text',    ctaText);
        await fetch(location.href, { method: 'POST', body: fd });
    } catch(e) {}
}
async function deleteCategory(nicheName, categoryName, btnEl, wrapEl) {
    if (!confirm(`Remove "${categoryName}" from My Categories? This will also delete ALL video ideas in this category.`)) return;
    
    try {
        const fd = new FormData();
        fd.append('ajax_action',   'delete_category');
        fd.append('niche_name',    nicheName);
        fd.append('category_name', categoryName);
        const response = await fetch(location.href, { method: 'POST', body: fd });
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'Delete failed');
        }
    } catch(e) {
        showToast('Error deleting category: ' + e.message);
        return;
    }
    
    // Remove from memory
    if (userCategories[nicheName]) {
        userCategories[nicheName] = userCategories[nicheName].filter(c => c !== categoryName);
    }
    
    // Also remove video ideas for this category from memory
    if (window._usedVideoIdeasLower) {
        // Clear any cached video ideas for this niche/category
        const ideasKey = (nicheName||'') + '|' + (categoryName||'');
        if (window._commonVideoIdeas && window._commonVideoIdeas[ideasKey]) {
            delete window._commonVideoIdeas[ideasKey];
        }
    }
    
    // Remove button from DOM
    if (wrapEl && wrapEl.parentNode) {
        wrapEl.remove();
    } else if (btnEl && btnEl.closest('.opt')) {
        btnEl.closest('.opt').remove();
    }
    
    // Clean up empty "My Categories" section
    const myWrap = document.getElementById('my-cats-wrap') || document.getElementById('camp-cats-user');
    if (myWrap && myWrap.children.length === 0) {
        const prev = myWrap.previousElementSibling;
        const next = myWrap.nextElementSibling;
        if (prev && prev.classList.contains('my-niches-label')) prev.remove();
        if (next && next.classList.contains('divider-label'))   next.remove();
        myWrap.remove();
    }
    
    // If the deleted category was currently selected in wizard, clear it
    if (ans.topic === categoryName) {
        ans.topic = null;
        delete ans.title;
        // Re-render current step if in wizard mode
        if (document.getElementById('modeWizard').style.display !== 'none') {
            render();
        }
    }
    
    // If the deleted category was currently selected in campaign, clear it
    if (campAns.camp_category === categoryName) {
        campAns.camp_category = null;
        // Re-render current step if in campaign mode
        if (document.getElementById('modeCampaign').style.display !== 'none') {
            campRender();
        }
    }
    
    showToast(`✓ "${categoryName}" and all its video ideas deleted`);
}
async function campRenderTitleSelect() {
    const daysMap={'1 Week (7 days)':7,'2 Weeks (14 days)':14,'1 Month (30 days)':30,'Custom':7};
    const rateMap={'1 post every 3 days':1/3,'1 post every 2 days':1/2,'1 post per day':1,'2 posts per day':2,'3 posts per day':3};
    // Parse "Custom (N days)" format
    let days = daysMap[campAns.camp_duration] || 14;
    const customMatch = (campAns.camp_duration || '').match(/Custom \((\d+) days?\)/i);
    if (customMatch) days = Math.min(30, parseInt(customMatch[1]) || 7);
    const postsPerDay = rateMap[campAns.camp_posts_per_day] || 1;
    const totalVideos = Math.ceil(days * postsPerDay);
    const langs       = campAns.camp_languages || ['English'];
    const body        = document.getElementById('camp-step-body');

    body.innerHTML=`
        <div class="camp-progress-bar">📊 <strong>${days} days</strong> · <strong>${campAns.camp_posts_per_day}</strong> · <strong>${langs.length} language${langs.length>1?'s':''}</strong> = <strong>${totalVideos*langs.length} total videos</strong></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:12px;">Select exactly <strong>${totalVideos} title${totalVideos>1?'s':''}</strong></div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
            <div id="camp-title-count" class="title-count-badge"><span>🎯</span> <span id="camp-sel-count">0</span> of <strong>${totalVideos}</strong> selected</div>
            <button id="camp-select-all-btn" onclick="campSelectAll(${totalVideos})"
                style="padding:5px 14px;border-radius:20px;border:1.5px solid var(--purple);background:var(--purple-lt);color:#5b21b6;font-size:12px;font-weight:700;cursor:pointer;">
                ✅ Select All
            </button>
        </div>
        <div id="camp-title-list" class="title-grid"><div class="loading"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating title ideas…</span></div></div>
        <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap;">
          <input class="custom-in" id="camp-title-cust-in" placeholder="Or add your own title…" style="flex:1;min-width:160px;">
          <button class="custom-add" id="camp-title-cust-btn">Add</button>
          <button class="more-btn" id="camp-titles-more-btn" onclick="campLoadMoreTitles(${totalVideos})" disabled><span>+</span> More Titles</button>
        </div>`;

    document.getElementById('camp-title-cust-btn').onclick  = () => campAddCustomTitle(totalVideos);
    document.getElementById('camp-title-cust-in').onkeydown = e => { if(e.key==='Enter') campAddCustomTitle(totalVideos); };
    campSetNext(false);

    try {
        const r=await fetch('generate_titles.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({niche:campAns.camp_niche,topic:campAns.camp_category,count:Math.max(totalVideos+8,20),goal:campAns.camp_goal})});
        const d=await r.json(); const titles=d.titles||[];
        campStepOpts['camp_titles']=[...titles]; campRenderTitleList(titles,totalVideos);
        const mb=document.getElementById('camp-titles-more-btn'); if(mb) mb.disabled=false;
    } catch(e){
        document.getElementById('camp-title-list').innerHTML=`<div style="color:#c00;font-size:13px;padding:8px;">Could not load. Add your own above.</div>`;
        const mb=document.getElementById('camp-titles-more-btn'); if(mb) mb.disabled=false;
    }
}

async function campLoadMoreTitles(needed) {
    const btn=document.getElementById('camp-titles-more-btn');
    if(btn){btn.disabled=true;btn.innerHTML='<span class="spin">⟳</span> Loading…';}
    const existing=campStepOpts['camp_titles']||[];
    const prompt=`Expert short-form video creator for niche "${campAns.camp_niche}", category "${campAns.camp_category}", goal "${campAns.camp_goal}". Generate 10 MORE titles. Do NOT repeat: ${existing.join(', ')}. Return ONLY a valid JSON array.`;
    try{
        const r=await fetch('generate_more_opts.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt})});
        if(!r.ok) throw new Error('HTTP '+r.status);
        const d=await r.json(); if(!d.success) throw new Error(d.error||'Server error');
        const newItems=[];
        (d.items||[]).forEach(item=>{const c=String(item).trim();if(c&&!campStepOpts['camp_titles'].includes(c)){campStepOpts['camp_titles'].push(c);newItems.push(c);}});
        if(newItems.length===0){if(btn){btn.textContent='No more';btn.disabled=true;}showToast('No new titles found');return;}
        const list=document.getElementById('camp-title-list');
        if(list){newItems.forEach(t=>{const el=document.createElement('div');el.className='title-item';el.dataset.title=t;el.innerHTML=`<div class="chk"></div><span>${t}</span>`;el.onclick=()=>campToggleTitle(el,t,needed);list.appendChild(el);el.scrollIntoView({behavior:'smooth',block:'nearest'});});}
        campUpdateTitleCount(needed);
        if(btn){btn.disabled=false;btn.innerHTML='<span>+</span> More Titles';}showToast(`✅ ${newItems.length} new titles added`);
    }catch(e){showToast('Could not load more: '+e.message);if(btn){btn.disabled=false;btn.innerHTML='<span>+</span> More Titles';}}
}

function campRenderTitleList(titles, needed) {
    const list=document.getElementById('camp-title-list'); if(!list) return;
    if(!campSelectedTitles) campSelectedTitles=[];
    list.innerHTML=titles.map(t=>{
        const sel=campSelectedTitles.includes(t);
        return `<div class="title-item${sel?' sel':''}" data-title="${esc(t)}" onclick="campToggleTitle(this,'${esc(t)}',${needed})"><div class="chk">${sel?'✓':''}</div><span>${t}</span></div>`;
    }).join('');
    campUpdateTitleCount(needed);
}

function campToggleTitle(el, title, needed) {
    if(!campSelectedTitles) campSelectedTitles=[];
    if(campSelectedTitles.includes(title)){ campSelectedTitles=campSelectedTitles.filter(x=>x!==title); el.classList.remove('sel'); el.querySelector('.chk').textContent=''; }
    else { campSelectedTitles.push(title); el.classList.add('sel'); el.querySelector('.chk').textContent='✓'; }
    campUpdateTitleCount(needed);
}

function campAddCustomTitle(needed) {
    const inp=document.getElementById('camp-title-cust-in'); const v=inp.value.trim(); if(!v) return; inp.value='';
    if(!campSelectedTitles) campSelectedTitles=[];
    if(!campStepOpts['camp_titles']) campStepOpts['camp_titles']=[];
    campStepOpts['camp_titles'].push(v); campSelectedTitles.push(v);
    const list=document.getElementById('camp-title-list');
    const el=document.createElement('div'); el.className='title-item sel'; el.dataset.title=v;
    el.innerHTML=`<div class="chk">✓</div><span>${v}</span>`;
    el.onclick=()=>campToggleTitle(el,v,needed);
    list.appendChild(el); campUpdateTitleCount(needed);
}

function campSelectAll(needed) {
    if (!campSelectedTitles) campSelectedTitles = [];
    // Collect all visible title items
    const items = document.querySelectorAll('#camp-title-list .title-item');
    const allTitles = Array.from(items).map(el => el.dataset.title).filter(Boolean);
    const alreadyFull = campSelectedTitles.length >= needed;

    if (alreadyFull) {
        // Deselect all
        campSelectedTitles = [];
        items.forEach(el => { el.classList.remove('sel'); el.querySelector('.chk').textContent = ''; });
        const btn = document.getElementById('camp-select-all-btn');
        if (btn) btn.textContent = '✅ Select All';
    } else {
        // Select up to needed, starting from top — skip already selected
        const toAdd = allTitles.filter(t => !campSelectedTitles.includes(t));
        const remaining = needed - campSelectedTitles.length;
        toAdd.slice(0, remaining).forEach(t => {
            campSelectedTitles.push(t);
            const el = Array.from(items).find(x => x.dataset.title === t);
            if (el) { el.classList.add('sel'); el.querySelector('.chk').textContent = '✓'; }
        });
        const btn = document.getElementById('camp-select-all-btn');
        if (btn) btn.textContent = '☐ Deselect All';
    }
    campUpdateTitleCount(needed);
}

function campUpdateTitleCount(needed) {
    const count=campSelectedTitles.length;
    const el=document.getElementById('camp-sel-count'); if(el) el.textContent=count;
    const badge=document.getElementById('camp-title-count');
    if(badge){
        if(count>=needed){ badge.style.background='#dcfce7';badge.style.borderColor='#86efac';badge.style.color='#166534';badge.querySelector('span').textContent='✅'; }
        else { badge.style.background='#fef3c7';badge.style.borderColor='#fde68a';badge.style.color='#92400e';badge.querySelector('span').textContent='🎯'; }
    }
    campAns['camp_titles']=campSelectedTitles; campSetNext(count>=needed);
    const nextBtn=document.getElementById('campNextBtn');
    if(nextBtn){ if(count>=needed) nextBtn.textContent='Continue →'; else nextBtn.textContent=`Select ${needed-count} more →`; }
}

function campGoNext() {
    autoSubmitCustomInput();
    if(campCur<CAMP_STEPS.length-1){ campCur++; const om=document.getElementById('camp-more-btn'); if(om) om.remove(); campRender(); } else campShowSummary();
}
function campGoBack() { if(campCur>0){ campCur--; const om=document.getElementById('camp-more-btn'); if(om) om.remove(); campRender(); } }

function campShowSummary() {
    document.getElementById('camp-prog').style.width='100%';
    document.getElementById('camp-step-label').textContent='Ready';
    document.getElementById('camp-step-q').textContent='';
    document.getElementById('camp-nav-bar').style.display='none';
    document.getElementById('campCardTitle').textContent='📋 Campaign Brief';
    document.getElementById('campCardSubtitle').textContent='Review and generate your campaign scripts';
    const langs=campAns.camp_languages||['English'];
    const totalScripts=campSelectedTitles.length;
    const totalVideos=totalScripts*langs.length;
    document.getElementById('camp-step-body').innerHTML=`
        <div class="done-title">Your campaign is ready</div>
        <div class="done-sub">${totalScripts} scripts × ${langs.length} language${langs.length>1?'s':''} = ${totalVideos} total videos</div>
        <div class="summary">
          <div class="sum-section">Campaign Setup</div>
          <div class="sum-row"><span class="sum-key">Goal</span><span class="sum-val">${campAns.camp_goal||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Niche</span><span class="sum-val">${campAns.camp_niche||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Category</span><span class="sum-val">${campAns.camp_category||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Duration</span><span class="sum-val">${campAns.camp_duration||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Posts / Day</span><span class="sum-val">${campAns.camp_posts_per_day||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Start Date</span><span class="sum-val">${campAns.camp_start_date?campAns.camp_start_date.replace('T',' '):'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Voice</span><span class="sum-val">${(campAns.camp_production||{}).voice_id||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Speech Rate</span><span class="sum-val">${(campAns.camp_production||{}).rate||'1.0'}×</span></div>
          <div class="sum-row"><span class="sum-key">Media Type</span><span class="sum-val">${((campAns.camp_production||{}).media_type||'stock_videos').replace('_',' ')}</span></div>
          <div class="sum-row"><span class="sum-key">Video Length</span><span class="sum-val">${campAns.camp_video_length||'-'}</span></div>
          <div class="sum-row"><span class="sum-key">Languages</span><span class="sum-val">${langs.join(', ')}</span></div>
          <div class="sum-section">Videos to Generate</div>
          ${campSelectedTitles.map((t,i)=>`<div class="sum-row"><span class="sum-key" style="color:var(--purple);font-weight:600;">#${i+1}</span><span class="sum-val" style="text-align:left;">${t}</span></div>`).join('')}
        </div>
        <div id="camp-gen-output"></div>
        <button class="gen-btn" id="camp-gen-btn" onclick="generateCampaign()">🚀 Generate ${totalVideos} Campaign Scripts</button>
        <button class="restart-btn" onclick="campRestart()">Start over</button>`;
}

async function generateCampaign() {
    const btn=document.getElementById('camp-gen-btn'); const out=document.getElementById('camp-gen-output');
    const langs=campAns.camp_languages||['English']; const totalVideos=campSelectedTitles.length*langs.length;
    btn.disabled=true; btn.textContent=`⏳ Generating ${totalVideos} scripts…`;
    out.innerHTML=`<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Generating campaign scripts…</span></div>`;
    try{
        const r=await fetch('generate_campaign.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({
            goal:campAns.camp_goal,niche:campAns.camp_niche,category:campAns.camp_category,
            languages:langs,duration:campAns.camp_duration,posts_per_day:campAns.camp_posts_per_day,
            start_date:campAns.camp_start_date||'',
            schedule_dates:computeScheduleDates(campAns.camp_start_date,campAns.camp_posts_per_day,campSelectedTitles.length*langs.length),
            video_length:campAns.camp_video_length,titles:campSelectedTitles,
            voice_id:(campAns.camp_production||{}).voice_id||'',rate:(campAns.camp_production||{}).rate||'1.0',
            media_type:(campAns.camp_production||{}).media_type||'stock_videos',
            reel_type:settings.reel_type,format:settings.format,objective:settings.objective,audience:settings.audience,
            short_sentences:true,max_words_per_scene:12,scene_count:'6-8',scene_break_tag:AZURE_BREAK
        })});
        const rawText=await r.text(); let d;
        try{d=JSON.parse(rawText);}catch(e){throw new Error('Server error: '+rawText.substring(0,300));}
        if(!d.success) throw new Error(d.error||'Campaign generation failed');
        const results=(d.results||[]).map(item=>({...item,script:enforceSceneBreaks(splitIntoScenes(item.script||''))}));

        // Save voice/rate/media to campaign
        if(d.campaign_id && campAns.camp_production){
            const prod=campAns.camp_production;
            const pFd=new FormData();
            pFd.append('ajax_action','save_campaign_production');
            pFd.append('campaign_id',d.campaign_id);
            pFd.append('voice_id',prod.voice_id||'');
            pFd.append('rate',prod.rate||'1.0');
            pFd.append('media_type',prod.media_type||'stock_videos');
            await fetch(location.href,{method:'POST',body:pFd}).catch(()=>{});
        }

        let html=`<div style="margin:20px 0 12px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">✅ ${results.length} scripts generated</div>`;
        results.forEach((item,i)=>{
            html+=`<div class="campaign-result-card">
              <div class="campaign-result-header" onclick="toggleCampResult(this)">
                <div>
                  <div style="font-size:13px;font-weight:700;color:var(--dark-blue);">#${i+1} ${item.title}</div>
                  <div style="font-size:11px;color:var(--muted);margin-top:2px;">${item.language} · ${item.podcast_id?'Saved ✓':'Pending'}</div>
                </div>
                <span style="color:var(--muted);font-size:18px;">▾</span>
              </div>
              <div class="campaign-result-body">
                <div class="script-box" style="white-space:pre-wrap;">${escHtml(item.script||'')}</div>
                ${item.podcast_id?`<a href="videomaker.php?podcast_id=${item.podcast_id}" class="copy-btn" style="display:block;text-align:center;text-decoration:none;margin-top:8px;">🎬 Open in VideoMaker</a>`:''}
              </div>
            </div>`;
        });

        if(results.length===0){
            out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;padding:12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;"><strong>⚠️ No scripts generated.</strong><br>${(d.errors||[]).join('<br>')||'Check a_errors.log'}</div>`;
            btn.textContent='🔄 Regenerate Campaign'; btn.disabled=false;
        } else {
            out.innerHTML=html;
            out.innerHTML+=`<div style="text-align:center;padding:28px 20px;margin-top:16px;background:linear-gradient(135deg,#e8f0fe,#dbeafe);border:1px solid var(--mid-blue);border-radius:16px;">
              <div style="font-size:40px;margin-bottom:10px;">🎉</div>
              <div style="font-size:18px;font-weight:700;color:var(--dark-blue);margin-bottom:20px;">${results.length} scripts generated!</div>
              <a href="vizard_browser.php?tab=campaigns" style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,var(--dark-blue),var(--mid-blue));color:white;padding:14px 32px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;">🚀 View My Campaigns →</a>
            </div>`;
            btn.style.display='none'; showToast('✅ '+results.length+' scripts saved!');
        }
    }catch(e){
        out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${e.message}</div>`;
        btn.textContent='Try Again →'; btn.disabled=false;
    }
}

function toggleCampResult(header) { const body=header.nextElementSibling; body.classList.toggle('open'); header.querySelector('span:last-child').textContent=body.classList.contains('open')?'▴':'▾'; }
function campRestart() { campCur=0; campAns={}; campStepOpts={}; campSelectedTitles=[]; document.getElementById('camp-nav-bar').style.display='flex'; goToMenu(); }

// ═══════════════════════════════════════════════════════════════════════════════
// I HAVE CONTENT
// ═══════════════════════════════════════════════════════════════════════════════
async function processMyContent() {
    const btn=document.getElementById('content-process-btn'); const out=document.getElementById('content-script-output');
    const title=document.getElementById('content-title').value.trim();
    const script=document.getElementById('content-script').value.trim();
    const cta=document.getElementById('content-cta').value.trim();
    if(!title){ alert('Please enter a video title'); return; }
    if(!script){ alert('Please paste your script or content'); return; }
    btn.disabled=true; btn.textContent='⏳ Processing…';
    out.innerHTML=`<div class="loading" style="margin:16px 0"><div class="dot"></div><div class="dot"></div><div class="dot"></div><span>Formatting into scenes…</span></div>`;
    try{
        const r=await fetch('generate_script.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({
            niche:'custom',title,objective:'Inform',audience:'General Public',angle:'Storytelling',
            duration:'60 seconds',cta,language:settings.language,reel_type:settings.reel_type,format:settings.format,
            _custom_prompt:`ORIGINAL CONTENT:\n${script}\n\nLANGUAGE: ${settings.language}\nCALL TO ACTION: ${cta}`,
            _mode:'content'
        })});
        const rawText=await r.text(); let d;
        try{d=JSON.parse(rawText);}catch(e){throw new Error('Server error: '+rawText.substring(0,200));}
        if(!d.success) throw new Error(d.error||'Processing failed');

        window._contentScriptRaw = d.script;
        window._contentPodcastId = null;
        window._contentData = {
            niche: 'custom',
            title: document.getElementById('content-title')?.value.trim() || 'My Video',
            language:  settings.language  || 'English',
            reel_type: settings.reel_type || 'Standard',
            topic: '',
        };

        const processedScript=enforceSceneBreaks(splitIntoScenes(d.script));
        out.innerHTML=`
            <div style="margin:20px 0 8px;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Formatted Script</div>
           
		   <textarea id="content-script-text" style="width:100%;min-height:160px;padding:14px;border:1.5px solid var(--border);border-radius:10px;font-family:monospace;font-size:13px;line-height:1.8;resize:vertical;outline:none;background:#f8fafc;color:var(--text);">${escHtml(processedScript)}</textarea>
           
		   <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
              <button class="build-btn" onclick="openS2('content')" style="width:100%;background:#16a34a;border-color:#16a34a;color:#fff;font-size:15px;padding:14px;">Approve &amp; Build Video  →</button>
            </div>`;
        btn.textContent='🔄 Reprocess'; btn.disabled=false;
    }catch(e){
        out.innerHTML=`<div style="color:#c00;font-size:13px;margin:12px 0;">Error: ${e.message}</div>`;
        btn.textContent='📝 Process Content'; btn.disabled=false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MODE SWITCHING
// ═══════════════════════════════════════════════════════════════════════════════
// REPLACE the entire selectMode() function:
function selectMode(mode) {
    if (isQuotaExceeded(1)) { showQuotaModal(); return; }

    ['modeSelect','modeWizard','modeCampaign','modeContent'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });

    // Campaign: force reel_type to Standard and persist it
    if (mode === 'campaign') {
        settings.reel_type = 'Standard';
        localStorage.setItem('vw_settings', JSON.stringify(settings));
        renderSettingsBar();
    }

    if (mode === 'wizard') {
        document.getElementById('modeWizard').style.display = 'block';
        cur = 0; ans = {}; stepOpts = {}; clearMoreBtn();
        document.getElementById('cardTitle').textContent   = '✨ Generate Video Script';
        document.getElementById('cardSubtitle').textContent = 'Answer a few questions to generate your video script';
        render();
    } else if (mode === 'campaign') {
        document.getElementById('modeCampaign').style.display = 'block';
        campCur = 0; campAns = {}; campStepOpts = {}; campSelectedTitles = [];
        document.getElementById('camp-nav-bar').style.display = 'flex';
        renderCampSettingsPills();
        campRender();
    } else if (mode === 'content') {
        document.getElementById('modeContent').style.display = 'block';
        renderContentSettingsPills();
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Show settings modal in "intro" mode — OK button instead of Save
    renderSettingsList();
    document.getElementById('settingsPanel').classList.add('settings-ok-mode');
    document.getElementById('settingsOverlay').classList.add('open');
}

// ADD this new function right after selectMode():
function closeSettingsOK() {
    document.getElementById('settingsPanel').classList.remove('settings-ok-mode');
    document.getElementById('settingsOverlay').classList.remove('open');
}

function goToMenu() {
    ['modeWizard','modeCampaign','modeContent'].forEach(id => { document.getElementById(id).style.display='none'; });
    document.getElementById('modeSelect').style.display='block';
    window.scrollTo({top:0, behavior:'smooth'});
}

// ═══════════════════════════════════════════════════════════════════════════════
// STEP 2 — BUILD VIDEO MODAL HELPERS
// ═══════════════════════════════════════════════════════════════════════════════
const S2_ENDPOINT = 'wizard_step2.php';
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
    document.getElementById('s2Setup').style.display='block';
    document.getElementById('s2Progress').style.display='none';
    document.getElementById('s2DoneBar').style.display='none';
    document.getElementById('s2Log').innerHTML='';
    // Init games now that modal elements exist
    if (!window._gamesInited) {
        window._gamesInited = true;
        tReset(); wReset(); eNext(); gReset();
        const mInpEl = document.getElementById('mInp');
        if (mInpEl) mInpEl.addEventListener('keydown', e => { if (e.key === 'Enter') mSub(); });
    }
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

    if (needsImages) {
        loadPodcasterImages(folderType, isPodcast);
        loadS2Voices();   // populates both card dropdowns
    } else {
        loadS2VoicesStandard();  // populates s2StdHostVoice
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
    // avatars    → avatars/
    // podcast    → host picker uses podcast_hosts/, guest picker uses podcast_guests/
    const hostThumbBase  = isAvatar ? 'avatars/' : 'podcast_hosts/';
    const guestThumbBase = isAvatar ? 'avatars/' : 'podcast_guests/';

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
                '<img src="' + imgBase + img.thumb + '" onerror="this.src=\'images/placeholder_avatar.png\'" loading="lazy">' +
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
        return `<option value="${esc(v.voice_id||v.voice_key||'')}"${disabled?' disabled':''}${style} data-sample="${esc(v.sample_voice||'')}" data-gender="${esc(v.gender||'')}">${esc(v.voice_name||'')}${lock}</option>`;
    }).join('');
}

// ── Fill a <select> filtered by gender ───────────────────────────────────────
function _fillVoiceSelect(selEl, gender, placeholder) {
    if (!selEl) return;
    const filtered = gender === 'all'
        ? _allVoices
        : _allVoices.filter(v => (v.gender||'').toLowerCase() === gender);
    const openai = filtered.filter(v => (v.voice_id||'').startsWith('openai:'));
    const azure  = filtered.filter(v => !(v.voice_id||'').startsWith('openai:'));
    let html = placeholder ? `<option value="">${placeholder}</option>` : '';
    if (openai.length) html += `<optgroup label="OpenAI">${_buildVoiceOpts(openai)}</optgroup>`;
    if (azure.length)  html += `<optgroup label="Azure${IS_FREE_TRIAL?' 🔒 Subscribers Only':''}">${_buildVoiceOpts(azure)}</optgroup>`;
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
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !sel.value) { showToast('Select a voice first'); return; }
    if (opt.disabled) { showToast('🔒 Azure voices are for subscribers — upgrade to unlock'); return; }
    if (_sampleAudio) {
        _sampleAudio.pause(); _sampleAudio = null;
        document.querySelectorAll('.s2-sample-btn').forEach(b => { b.textContent='▶ Play Sample'; b.classList.remove('playing'); });
        if (btn.dataset.playing === '1') { btn.dataset.playing = '0'; return; }
    }
    const sampleFile = opt.getAttribute('data-sample') || '';
    if (!sampleFile) { showToast('No sample available for this voice'); return; }
    btn.textContent = '⏹ Stop'; btn.classList.add('playing'); btn.dataset.playing = '1';
    _sampleAudio = new Audio('podcast_audios/' + sampleFile + '?t=' + Date.now());
    const reset = () => { _sampleAudio=null; btn.textContent='▶ Play Sample'; btn.classList.remove('playing'); btn.dataset.playing='0'; };
    _sampleAudio.onended = reset;
    _sampleAudio.onerror = () => { reset(); showToast('Could not play sample'); };
    _sampleAudio.play().catch(() => { reset(); showToast('Could not play sample'); });
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

    const opt = sel.options[sel.selectedIndex];
    if (!opt || !sel.value) { showToast('Select a voice first'); return; }
    if (opt.disabled) { showToast('🔒 Azure voices are for subscribers — upgrade to unlock'); return; }

    // Toggle stop
    if (_sampleAudio) {
        _sampleAudio.pause();
        _sampleAudio = null;
        document.querySelectorAll('.s2-sample-btn').forEach(b => {
            b.textContent = '▶ Play Sample';
            b.classList.remove('playing');
        });
        if (btn.dataset.playing === '1') { btn.dataset.playing = '0'; return; }
    }

    const sampleFile = opt.getAttribute('data-sample') || '';
    if (!sampleFile) { showToast('No sample available for this voice'); return; }

    btn.textContent = '⏹ Stop';
    btn.classList.add('playing');
    btn.dataset.playing = '1';

    _sampleAudio = new Audio('podcast_audios/' + sampleFile + '?t=' + Date.now());
    const reset = () => {
        _sampleAudio = null;
        btn.textContent = '▶ Play Sample';
        btn.classList.remove('playing');
        btn.dataset.playing = '0';
    };
    _sampleAudio.onended = reset;
    _sampleAudio.onerror = () => { reset(); showToast('Could not play sample'); };
    _sampleAudio.play().catch(() => { reset(); showToast('Could not play sample'); });
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
        const resp=await fetch('enhance_scene.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({system:systemPrompt,message:userMsg})});
        const data=await resp.json();
        const raw=data.response||'';
        const cleaned=raw.replace(/^```(?:json)?\s*/i,'').replace(/\s*```$/i,'').trim();
        const parsed=JSON.parse(cleaned);
        if(parsed.prompts&&parsed.hashtags&&parsed.nl_tags){
            const videoPrompt = parsed.video_prompt ||
                `Cinematic stock video clip. Niche: ${niche}. Smooth camera movement, natural lighting, professional footage, authentic real-life environment, 4K quality.`;
            return{text:sceneText,prompt:parsed.prompts[0]||'',prompts:parsed.prompts,video_prompt:videoPrompt,hashtags:parsed.hashtags,nl_tags:parsed.nl_tags,actor:'host',image_count:imageCount};
        }
        throw new Error('Incomplete response');
    }catch(e){
        const basic=parseWizardScenesBasic(sceneText,{niche})[0];
        return{...basic,prompts:[basic?.prompt||cleanText],image_count:1};
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
        const r=await fetch('wizard_step2.php',{method:'POST',body:fd,credentials:'include'});
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

    const S2_ENDPOINT = isPodcast   ? 'wizard_step2_podcast.php'
                      : isTalkingHead ? 'wizard_step2_avatar.php'
                      : 'wizard_step2.php';

    document.getElementById('s2Setup').style.display    = 'none';
    document.getElementById('s2Progress').style.display = 'block';
    document.getElementById('s2GameStrip').style.display = 'block';
    
    document.getElementById('s2CloseBtn').style.display = 'none';
    s2Cancelled = false;

    showProcessingSpinner('Starting video build…');

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
                    body: JSON.stringify({ script: rawForParse, data: dataToSave })
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
        document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id=' + podcastId;
        document.getElementById('s2DoneBar').style.display = 'flex';
        document.getElementById('s2GameStrip').style.display = 'none';
        
        const modeLabel = isTalkingHead ? 'Talking Head' : 'Podcast';
        s2Log(`🎉 ${modeLabel} done! #${podcastId} — ${sceneDone}/${dbScenes.length} scenes OK`, 'success');
        showToast(`✅ ${modeLabel} video ready — #${podcastId}`);
        return;
    }
    
    // =========================================================================
    // STANDARD / B-ROLL FLOW
    // =========================================================================

    // STEP 0 — AI enhance scene prompts
    s2StepStatus(0, 'active', `Enhancing ${scenes.length} scenes with AI…`);
    s2Log(`🤖 Generating realistic image prompts for ${scenes.length} scenes…`, 'info');
    updateSpinnerStep('Generating AI prompts…');
    const _t0_enhance = performance.now();

    try {
        const rawLines = isBroll
            ? [rawForParse]
            : rawForParse.split('\n').map(l => l.trim()).filter(Boolean);

        const sceneDurations = rawLines.map(line => {
            const cleanText = line.replace(/<[^>]*>/g, '').trim();
            const wordCount = cleanText.split(/\s+/).filter(Boolean).length;
            return Math.max(3, Math.round((wordCount / 130) * 60));
        });

        s2Log(`📐 Pre-calculated durations: [${sceneDurations.join(', ')}]s`, 'info');

        if (isBroll) {
             s2Log('✅ B-Roll: scene already classified and prompts generated above', 'info');
        } else {
            s2Log(`✨ Enhancing all ${rawLines.length} scenes in parallel…`, 'info');
            updateSpinnerStep(`Enhancing ${rawLines.length} scenes simultaneously…`);

            const niche = wizAnsForParse.niche || 'professional';
            const title = wizAnsForParse.title || 'Video';
            const total = rawLines.length;
            const durationMap = {'15 seconds':15,'30 seconds':30,'60 seconds':60,'90 seconds':90};
            const totalSecs = durationMap[wizAnsForParse.duration] || 60;
            const defaultDur = Math.max(4, Math.round(totalSecs / total));

            scenes = await Promise.all(
                rawLines.map((line, i) => {
                    const dur = sceneDurations[i] || defaultDur;
                    return enhanceSceneWithAI(line, niche, title, i, total, dur)
                        .then(scene => {
                            s2Log(`✓ Scene ${i + 1}/${total} enhanced`, 'success');
                            return scene;
                        })
                        .catch(() => {
                            s2Log(`⚠ Scene ${i + 1} enhance failed, using basic prompt`, 'warning');
                            return parseWizardScenesBasic(line, { niche })[0];
                        });
                })
            );
        }

        s2StepStatus(0, 'done', `✓ ${scenes.length} scene prompts enhanced`);
        _step0Sec = ((performance.now() - _t0_enhance) / 1000).toFixed(1);
        s2Log(`✅ AI prompts generated (⏱ ${_step0Sec}s)`, 'success');

    } catch (e) {
        s2Log(`⚠ AI enhancement failed: ${e.message}`, 'warning');
        scenes = parseWizardScenesBasic(rawForParse, wizAnsForParse);
        s2StepStatus(0, 'done', '✓ Basic prompts used');
    }

    // STEP 1 — Get or create podcast, then create scenes
    s2StepStatus(1, 'active', 'Setting up podcast…');
    updateSpinnerStep('Setting up podcast…');
    const _t0_step1 = performance.now();

    let podcastId = s2Source === 'wizard'  ? window._wizPodcastId
                  : s2Source === 'content' ? window._contentPodcastId
                  : null;

    if ((s2Source === 'wizard' || s2Source === 'content') && !podcastId) {
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
                body: JSON.stringify({ script: rawForParse, data: dataToSave })
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
            s2StepStatus(1, 'error', 'Failed to create podcast: ' + e.message);
            s2Log('❌ ' + e.message, 'error');
            document.getElementById('s2CloseBtn').style.display = 'inline';
            hideProcessingSpinner();
            return;
        }

    } else if (podcastId) {
        s2Log(`✅ Reusing existing podcast #${podcastId}`, 'success');
        
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

    // Save voice
    try {
        const voiceFd = new FormData();
        voiceFd.append('action',      'update_podcast_voice');
        voiceFd.append('podcast_id',  podcastId);
        voiceFd.append('host_voice',  hostVoice);
        voiceFd.append('guest_voice', '');
        voiceFd.append('rate',        rate);
        const voiceResp = await fetch(S2_ENDPOINT, { method: 'POST', body: voiceFd, credentials: 'include' });
        const voiceData = await voiceResp.json();
        if (voiceData.success) {
            s2Log(`✅ Voice saved — host: ${hostVoice}`, 'success');
        } else {
            s2Log(`⚠ Could not save voice: ${voiceData.error || 'unknown'}`, 'warning');
        }
    } catch (e) {
        s2Log(`⚠ Voice update error: ${e.message}`, 'warning');
    }

    if (s2Cancelled) { s2Log('⏹ Cancelled', 'warning'); hideProcessingSpinner(); return; }

    // Create scenes
    s2Log('📝 Creating scenes from script…', 'info');
    updateSpinnerStep('Creating scenes…');
    const _t0_scenes = performance.now();
    try {
        const sceneCreateFd = new FormData();
        sceneCreateFd.append('action',      'create_scenes_from_podcast');
        sceneCreateFd.append('podcast_id',  podcastId);
        sceneCreateFd.append('host_voice',  hostVoice);
        sceneCreateFd.append('guest_voice', guestVoice || hostVoice);
        sceneCreateFd.append('rate',        rate);
        sceneCreateFd.append('lang_code',   langCode);
        sceneCreateFd.append('is_broll',    isBroll ? '1' : '0');
        const sceneCreateData = await s2SafeFetch(S2_ENDPOINT, { method: 'POST', body: sceneCreateFd, credentials: 'include' });
        if (!sceneCreateData || !sceneCreateData.success) throw new Error((sceneCreateData && sceneCreateData.error) || 'Scene creation failed');
        s2Log(`✅ ${sceneCreateData.scene_count} scenes created (⏱ ${((performance.now()-_t0_scenes)/1000).toFixed(1)}s)`, 'success');
    } catch (e) {
        s2StepStatus(1, 'error', 'Scene creation failed: ' + e.message);
        s2Log('❌ ' + e.message, 'error');
        document.getElementById('s2CloseBtn').style.display = 'inline';
        hideProcessingSpinner();
        return;
    }

    // Apply user animation settings to all captions of the new video
    await initCaptionsFromSettings(podcastId);

    s2StepStatus(1, 'done', `✓ Podcast #${podcastId} ready (⏱ ${((performance.now()-_t0_step1)/1000).toFixed(1)}s)`);
    _step1Sec = ((performance.now() - _t0_step1) / 1000).toFixed(1);

    // STEP 2 — Generate audio
    s2StepStatus(2, 'active', 'Fetching scenes…');
    updateSpinnerStep('Generating audio…');
    s2Log('🎤 Starting audio generation…', 'info');
    const _t0_audio = performance.now();

    const sceneFd = new FormData();
    sceneFd.append('action',     'get_scenes');
    sceneFd.append('podcast_id', podcastId);
    let dbScenes = [];

    try {
        const _d = await s2SafeFetch(S2_ENDPOINT, { method: 'POST', body: sceneFd, credentials: 'include' });
        dbScenes = Array.isArray(_d) ? _d : [];
        s2Log(`📋 Fetched ${dbScenes.length} scenes from DB`, 'info');
    } catch (e) {
        s2Log('⚠ Could not fetch scenes: ' + e.message, 'warning');
    }

    if (dbScenes.length > 0) {
        const needsFix = dbScenes.some(sc => (parseInt(sc.duration) || 0) === 0);
        if (needsFix) {
            dbScenes = dbScenes.map(sc => {
                const existingDur = parseInt(sc.duration) || 0;
                if (existingDur > 0) return sc;
                const cleanText = (sc.text_contents || '').replace(/<[^>]*>/g, '').trim();
                const wordCount  = cleanText.split(/\s+/).filter(Boolean).length;
                const calcDur    = Math.max(3, Math.round((wordCount / 130) * 60));
                return { ...sc, duration: calcDur };
            });
            s2Log('📐 Scene durations calculated from word count', 'info');
        }
    }

    // Sync AI tags
    updateSpinnerStep('Syncing AI tags…');
    s2Log('🏷 Syncing scene tags…', 'info');
    const _t0_tags = performance.now();

    for (let i = 0; i < dbScenes.length && i < scenes.length; i++) {
        const sceneItem = scenes[i];
        const prompts   = sceneItem.prompts || [sceneItem.prompt || ''];
        const tagFd     = new FormData();
        tagFd.append('action',       'update_scene_tags');
        tagFd.append('scene_id',     dbScenes[i].id);
        tagFd.append('hashtags',     sceneItem.hashtags     || '');
        tagFd.append('nl_tags',      sceneItem.nl_tags      || '');
        tagFd.append('prompt',       prompts[0]             || '');
        tagFd.append('video_prompt', sceneItem.video_prompt || '');
        tagFd.append('prompt_1',     prompts[1]             || '');
        tagFd.append('prompt_2',     prompts[2]             || '');
        tagFd.append('prompt_3',     prompts[3]             || '');
        tagFd.append('prompt_4',     prompts[4]             || '');
        await fetch(S2_ENDPOINT, { method: 'POST', body: tagFd, credentials: 'include' }).catch(() => {});
    }
    s2Log(`✅ Tags synced (${((performance.now()-_t0_tags)/1000).toFixed(1)}s)`, 'success');

    // Re-fetch with fresh tags
    try {
        const reFd = new FormData();
        reFd.append('action',     'get_scenes');
        reFd.append('podcast_id', podcastId);
        const _reD = await s2SafeFetch(S2_ENDPOINT, { method: 'POST', body: reFd, credentials: 'include' });
        if (Array.isArray(_reD) && _reD.length > 0) dbScenes = _reD;
    } catch (e) {
        s2Log('⚠ Re-fetch failed: ' + e.message, 'warning');
    }

    // Parallel audio generation
    s2Log(`🎤 Generating audio for ${dbScenes.length} scenes in parallel…`, 'info');
    updateSpinnerStep(`Generating ${dbScenes.length} audio files simultaneously…`);
    s2StepStatus(2, 'active', `Generating ${dbScenes.length} audio files…`);

    // Delete old audio files first
    for (const scene of dbScenes) {
        const chkFd = new FormData();
        chkFd.append('action',   'check_audio_file');
        chkFd.append('filename', `voice_${podcastId}_${scene.id}_${langCode}.mp3`);
        await fetch(S2_ENDPOINT, { method: 'POST', body: chkFd, credentials: 'include' }).catch(() => {});
    }

    const audioResults = await Promise.all(
        dbScenes.map(async (scene, i) => {
            const seqNo  = i + 1;
            const ttsText = (scene.text_contents || '').replace(/<break[^>]*>/gi, '').trim();
            if (!ttsText) {
                s2Log(`⏭ Scene ${seqNo}: no text, skipping`, 'info');
                return { success: true, skipped: true };
            }
            const aFd = new FormData();
            aFd.append('action',     'generate_scene_audio');
            aFd.append('scene_id',   scene.id);
            aFd.append('podcast_id', podcastId);
            aFd.append('seq_no',     seqNo);
            aFd.append('lang_code',  langCode);
            aFd.append('voice_id',   hostVoice);
            aFd.append('rate',       rate);
            aFd.append('text',       ttsText);
            const _tScene = performance.now();
            try {
                const r = await fetch(S2_ENDPOINT, { method: 'POST', body: aFd, credentials: 'include' });
                const d = await r.json();
                const _sceneSec = ((performance.now() - _tScene) / 1000).toFixed(1);
                if (d.success) {
                    s2Log(`✓ Scene ${seqNo} audio OK (⏱ ${_sceneSec}s)`, 'success');
                } else {
                    s2Log(`✗ Scene ${seqNo}: ${d.error} (⏱ ${_sceneSec}s)`, 'error');
                }
                return d;
            } catch (e) {
                s2Log(`✗ Scene ${seqNo}: ${e.message}`, 'error');
                return { success: false, error: e.message };
            }
        })
    );

    const audioDone = audioResults.filter(r => r.success).length;
    const audioFail = audioResults.filter(r => !r.success).length;
    _audioSec = ((performance.now() - _t0_audio) / 1000).toFixed(1);

    s2StepStatus(2, audioFail > 0 ? 'error' : 'done',
        `✓ ${audioDone} audio files${audioFail > 0 ? ' (' + audioFail + ' failed)' : ''} (⏱ ${_audioSec}s)`);
    s2Log(`⏱ Audio total: ${_audioSec}s (${audioDone} ok, ${audioFail} failed)`, 'info');

    if (s2Cancelled) { s2Log('⏹ Cancelled', 'warning'); hideProcessingSpinner(); return; }

    // STEP 3 — Assign media
    s2StepStatus(3, 'active', 'Searching media library…');
    updateSpinnerStep('Running batch media search…');
    s2Log('🖼 Searching media library in one batch call…', 'info');
    const _t0_media = performance.now();

    const IMAGE_FIELDS = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
    let mediaDone = 0;
    let mediaFail = 0;

    const sceneQueries = dbScenes.map((scene, i) => {
        const nlTags    = (scene.natural_language_tags || '').trim();
        const hashtags  = (scene.hashtags || '').trim();
        const sceneText = (scene.text_contents || '').replace(/<break[^>]*>/gi, '').replace(/<[^>]*>/g, '').trim();
        return {
            scene_idx: i,
            scene_id:  scene.id,
            nl_tags:   nlTags,
            query:     nlTags || hashtags || sceneText
        };
    });

    // Single PHP call for all scenes
    let batchResults = [];
    if (s2MediaType !== 'unique_images') {
        try {
            const _t0_batch = performance.now();
            const bFd = new FormData();
            bFd.append('action',       'search_images_batch');
            bFd.append('podcast_id',   podcastId);
            bFd.append('slots',        5);
            bFd.append('media_type',   s2MediaType);
            bFd.append('admin_id',     <?= (int)$_SESSION['admin_id'] ?>);
            bFd.append('team_lead_id', <?= (int)($_SESSION['team_lead_id'] ?? 0) ?>);
            bFd.append('scenes',       JSON.stringify(sceneQueries));
            s2Log(`🔍 Batch searching ${sceneQueries.length} scenes (dedup enforced)…`, 'info');
            const bR   = await fetch(S2_ENDPOINT, { method: 'POST', body: bFd, credentials: 'include' });
            const bRaw = await bR.text();
            const batchResponse = JSON.parse(bRaw);
            batchResults = batchResponse.results || [];
            const _batchSec = ((performance.now() - _t0_batch) / 1000).toFixed(1);
            s2Log(`✅ Batch search complete — ${batchResults.length} scenes returned (⏱ ${_batchSec}s)`, 'success');
        } catch (e) {
            s2Log(`⚠ Batch search failed: ${e.message} — will use AI fallback per scene`, 'warning');
            batchResults = [];
        }
    }

    // Assign results to each scene
    for (let i = 0; i < dbScenes.length; i++) {
        if (s2Cancelled) break;

        const scene     = dbScenes[i];
        const seqNo     = i + 1;
        const sceneData = scenes[i] || {};
        const imageCount = 5;  //inam change of no of slots to be assigned

        s2StepStatus(3, 'active', `Assigning media ${seqNo}/${dbScenes.length}…`);
        updateSpinnerStep(`Assigning media ${seqNo}/${dbScenes.length}…`);

        if (s2MediaType === 'unique_images') {
            // AI image generation
            const prompts = sceneData.prompts || [sceneData.prompt || scene.text_contents || ''];
            let imgDone   = 0;

            for (let p = 0; p < imageCount && p < prompts.length; p++) {
                try {
                    s2Log(`🤖 Scene ${seqNo} image ${p + 1}/${Math.min(imageCount, prompts.length)}: generating…`, 'info');
                    updateSpinnerStep(`AI image: scene ${seqNo}, slot ${p + 1}…`);

                    const imgFd = new FormData();
                    imgFd.append('prompt',      prompts[p] || prompts[0] || '');
                    imgFd.append('scene_id',    scene.id);
                    imgFd.append('podcast_id',  podcastId);
                    imgFd.append('image_field', IMAGE_FIELDS[p]);

                    const r       = await fetch('wizard_image_gen.php', { method: 'POST', body: imgFd, credentials: 'include' });
                    const rawText = await r.text();
                    const d       = JSON.parse(rawText);

                    if (d.success && d.filename) {
                        const aFd = new FormData();
                        aFd.append('action',      'assign_image');
                        aFd.append('scene_id',    scene.id);
                        aFd.append('podcast_id',  podcastId);
                        aFd.append('filename',    d.filename);
                        aFd.append('image_field', IMAGE_FIELDS[p]);
                        aFd.append('media_type',  'image');
                        aFd.append('search_query',     sceneQueries[i]?.query || '');
                        aFd.append('similarity_score', '0.95');
                        aFd.append('match_rank',       (p + 1).toString());
                        aFd.append('matched_terms',    JSON.stringify(['ai_generated']));
                        await fetch(S2_ENDPOINT, { method: 'POST', body: aFd, credentials: 'include' }).catch(() => {});
                        imgDone++;
                        s2Log(`✓ Scene ${seqNo} slot ${p + 1}: ${d.filename}`, 'success');
                    } else {
                        s2Log(`✗ Scene ${seqNo} slot ${p + 1}: ${d.message || 'generation failed'}`, 'error');
                    }
                } catch (e) {
                    s2Log(`✗ Scene ${seqNo} slot ${p + 1} error: ${e.message}`, 'error');
                }
            }

            if (imgDone > 0) { mediaDone++; } else { mediaFail++; s2Log(`✗ Scene ${seqNo}: all image slots failed`, 'error'); }

        } else {
            // Stock media
            const batchRow = batchResults.find(r => r.scene_idx === i);
            const found    = batchRow?.found || [];

            if (found.length > 0) {
                // Deduplicate by filename — keep unique files only
                const seen      = new Set();
                const unique    = [];
                for (const item of found) {
                    if (!seen.has(item.filename)) {
                        seen.add(item.filename);
                        unique.push(item);
                    }
                }

                // Fill all 5 slots — cycle through unique items if fewer than 5
               const slotsToAssign = Math.min(imageCount, unique.length);
				const toAssign = [];
				for (let p = 0; p < slotsToAssign; p++) {
					toAssign.push({ slot: p, item: unique[p] });
				}

                // Assign each slot with match metadata
                for (const { slot, item } of toAssign) {
                    // Determine media type: use item.type from search result,
                    // fall back to s2MediaType selection (stock_videos → video, else image)
                    const itemType = item.type || (s2MediaType === 'stock_videos' ? 'video' : 'image');
                    const aFd = new FormData();
                    aFd.append('action',           'assign_image');
                    aFd.append('scene_id',         scene.id);
                    aFd.append('podcast_id',       podcastId);
                    aFd.append('filename',         item.filename);
                    aFd.append('image_field',      IMAGE_FIELDS[slot]);
                    aFd.append('media_type',       itemType);
                    aFd.append('search_query',     sceneQueries[i]?.query || '');
                    aFd.append('similarity_score', item.score || 0);
                    aFd.append('match_rank',       item.rank || (slot + 1));
                    aFd.append('matched_terms',    item.matched_terms || '[]');
                    
                    await fetch(S2_ENDPOINT, { method: 'POST', body: aFd, credentials: 'include' }).catch(() => {});
                    s2Log(`✓ Scene ${seqNo} slot ${slot+1}: ${item.filename} [${itemType}]`, 'success');
                }
                mediaDone++;
                s2Log(`✓ Scene ${seqNo}: ${toAssign.length} slots assigned`, 'success');

            } else {
                // AI fallback
                s2Log(`⚠ Scene ${seqNo}: No stock media found — AI generating fallback…`, 'warning');

                const prompts = sceneData.prompts || [sceneData.prompt || scene.text_contents || ''];
                let imgDone   = 0;

                try {
                    const imgFd = new FormData();
                    imgFd.append('prompt',      prompts[0] || scene.text_contents || '');
                    imgFd.append('scene_id',    scene.id);
                    imgFd.append('podcast_id',  podcastId);
                    imgFd.append('image_field', IMAGE_FIELDS[0]);

                    const r       = await fetch('wizard_image_gen.php', { method: 'POST', body: imgFd, credentials: 'include' });
                    const rawText = await r.text();
                    const d       = JSON.parse(rawText);

                    if (d.success && d.filename) {
                        const aFd = new FormData();
                        aFd.append('action',           'assign_image');
                        aFd.append('scene_id',         scene.id);
                        aFd.append('podcast_id',       podcastId);
                        aFd.append('filename',         d.filename);
                        aFd.append('image_field',      IMAGE_FIELDS[0]);
                        aFd.append('media_type',       'image');
                        aFd.append('search_query',     sceneQueries[i]?.query || '');
                        aFd.append('similarity_score', '0.90');
                        aFd.append('match_rank',       '1');
                        aFd.append('matched_terms',    JSON.stringify(['ai_fallback']));
                        await fetch(S2_ENDPOINT, { method: 'POST', body: aFd, credentials: 'include' }).catch(() => {});
                        imgDone++;
                        s2Log(`✓ Scene ${seqNo}: AI fallback — ${d.filename}`, 'success');
                    } else {
                        s2Log(`✗ Scene ${seqNo}: AI fallback failed — ${d.message || 'unknown'}`, 'error');
                    }
                } catch (e) {
                    s2Log(`✗ Scene ${seqNo}: AI fallback error — ${e.message}`, 'error');
                }

                if (imgDone > 0) { mediaDone++; } else { mediaFail++; }
            }
        }
    }

    _mediaSec = ((performance.now() - _t0_media) / 1000).toFixed(1);
    s2StepStatus(3, mediaFail === dbScenes.length ? 'error' : 'done',
        `✓ ${mediaDone} scenes assigned (⏱ ${_mediaSec}s)`);
    s2Log(`⏱ Media assign total: ${_mediaSec}s`, 'info');

    if (s2Source === 'wizard' || s2Source === 'content') {
        await updatePodcastThumbnail(podcastId);
    }

    hideProcessingSpinner();
    document.getElementById('s2CloseBtn').style.display = 'inline';
    document.getElementById('s2VideoLink').href = 'videomaker.php?podcast_id=' + podcastId;
    document.getElementById('s2GameStrip').style.display = 'none';
    document.getElementById('s2DoneBar').style.display = 'flex';

    const _totalMs  = Math.round(performance.now() - _buildStart);
    const _totalSec = (_totalMs / 1000).toFixed(1);
    s2Log(`━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`, 'info');
    s2Log(`⏱ BUILD SUMMARY — Podcast #${podcastId}`, 'info');
    s2Log(`   🤖 Step 0 · AI prompts   : ${_step0Sec}s`, 'info');
    s2Log(`   📝 Step 1 · Scene create : ${_step1Sec}s`, 'info');
    s2Log(`   🎤 Step 2 · Audio TTS    : ${_audioSec}s`, 'info');
    s2Log(`   🖼  Step 3 · Media assign : ${_mediaSec}s`, 'info');
    s2Log(`   ─────────────────────────────────────────`, 'info');
    s2Log(`   🏁 TOTAL                 : ${_totalSec}s`, 'success');
    s2Log(`━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`, 'info');
    s2Log('🎉 All done! Podcast #' + podcastId, 'success');
    showToast('✅ Video ready — Podcast #' + podcastId);

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
        
        const response = await fetch('wizard_step2.php', {
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
/* ═══════════════════════════════════════════════════════════════════════════
   INITIALIZATION
═══════════════════════════════════════════════════════════════════════════ */

// Load all settings and data
loadSettings();
loadUserNiches();

loadVideoQuota();

// Set initial mode display
['modeWizard', 'modeCampaign', 'modeContent'].forEach(id => {
    document.getElementById(id).style.display = 'none';
});
document.getElementById('modeSelect').style.display = 'block';

/* ═══════════════════════════════════════════════════════════════════════════
   GAME STRIP — Token system + 5 games
═══════════════════════════════════════════════════════════════════════════ */

let tokens = parseInt(localStorage.getItem('vv_tok') || '0');
(function() {
    const el = document.getElementById('tCount');
    if (el) el.textContent = tokens;
})();

// Initialize game functions (these should be defined elsewhere in your code)
// tReset, wReset, eNext, gReset, etc. should already be defined
function addTok(n,msg,game){
  tokens+=n;
  localStorage.setItem('vv_tok',tokens);
  const el=document.getElementById('tCount');if(el)el.textContent=tokens;
  const m=document.getElementById('tMsg');
  if(m){m.textContent='+'+n+' '+msg;clearTimeout(m._t);m._t=setTimeout(()=>m.textContent='',2400);}
  const fd=new FormData();fd.append('earned',n);fd.append('game',game);
  fetch('save_tokens.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{if(d.success){tokens=d.total;localStorage.setItem('vv_tok',tokens);const el=document.getElementById('tCount');if(el)el.textContent=tokens;}}).catch(()=>{});
}
function arcSw(id,btn){
  document.querySelectorAll('.arc-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.arc-panel').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('arc-'+id).classList.add('active');
}
/* GAME 1 — TikTokToe */
let tBd=Array(9).fill(''),tOver=false,tDiff='medium',tSc={y:0,d:0,a:0};
const tW=[[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
function tRender(){const b=document.getElementById('tBoard');if(!b)return;b.innerHTML='';tBd.forEach((v,i)=>{const c=document.createElement('div');c.className='ttt-cell'+(v?' taken':'')+(v==='X'?' cx':'')+(v==='O'?' co':'');c.textContent=v;c.onclick=()=>tMove(i);b.appendChild(c);});}
function tChk(p){return tW.find(w=>w.every(i=>tBd[i]===p));}
function tFull(){return tBd.every(v=>v);}
function tMM(b,mx,d){if(tW.find(w=>w.every(i=>b[i]==='O')))return 10-d;if(tW.find(w=>w.every(i=>b[i]==='X')))return d-10;if(b.every(v=>v))return 0;let best=mx?-99:99;for(let i=0;i<9;i++){if(!b[i]){b[i]=mx?'O':'X';const s=tMM(b,!mx,d+1);b[i]='';best=mx?Math.max(best,s):Math.min(best,s);}}return best;}
function tAI(){const emp=tBd.map((v,i)=>v?null:i).filter(i=>i!==null);if(!emp.length)return;let mv=-1,best=-99;if(tDiff==='easy'){mv=emp[Math.floor(Math.random()*emp.length)];}else if(tDiff==='medium'){mv=Math.random()<0.5?emp[Math.floor(Math.random()*emp.length)]:emp.reduce((bm,i)=>{const b=[...tBd];b[i]='O';const s=tMM(b,false,0);return s>best?(best=s,i):bm},-1);}else{emp.forEach(i=>{const b=[...tBd];b[i]='O';const s=tMM(b,false,0);if(s>best){best=s;mv=i;}});}if(mv===-1)return;tBd[mv]='O';tRender();const w=tChk('O');if(w){w.forEach(i=>document.getElementById('tBoard').children[i].classList.add('win'));tSc.a++;document.getElementById('tSA').textContent=tSc.a;document.getElementById('tStat').textContent='AI wins!';tOver=true;return;}if(tFull()){tSc.d++;document.getElementById('tSD').textContent=tSc.d;document.getElementById('tStat').textContent='Draw! +1';tOver=true;addTok(1,'draw','ttt');return;}document.getElementById('tStat').textContent='Your turn';}
function tMove(i){if(tOver||tBd[i])return;tBd[i]='X';tRender();const w=tChk('X');if(w){w.forEach(j=>document.getElementById('tBoard').children[j].classList.add('win'));tSc.y++;document.getElementById('tSY').textContent=tSc.y;document.getElementById('tStat').textContent='You win! +2 🎉';tOver=true;addTok(2,'win!','ttt');return;}if(tFull()){tSc.d++;document.getElementById('tSD').textContent=tSc.d;document.getElementById('tStat').textContent='Draw! +1';tOver=true;addTok(1,'draw','ttt');return;}document.getElementById('tStat').textContent='AI thinking…';setTimeout(tAI,260);}
function tReset(){tBd=Array(9).fill('');tOver=false;tRender();const el=document.getElementById('tStat');if(el)el.textContent='Your turn — place X';}
function tSetD(d,el){tDiff=d;document.querySelectorAll('.ttt-diff').forEach(b=>b.classList.remove('sel'));el.classList.add('sel');tReset();}
// Only init games when modal opens, not on page load
/* GAME 2 — Word Guess */
const wWords=[{w:'VIRAL',h:'📈 Spreads fast online'},{w:'NICHE',h:'🎯 Your target market'},{w:'REEL',h:'📱 Short video format'},{w:'HOOK',h:'🎣 Attention grabber'},{w:'REACH',h:'👀 How many people see it'},{w:'TREND',h:"🔥 What's popular now"},{w:'BRAND',h:'⭐ Your unique identity'},{w:'AUDIO',h:'🎤 Sound in your video'},{w:'SCENE',h:'🎬 One video segment'},{w:'VOICE',h:'🗣️ Narration style'},{w:'SCRIPT',h:'📝 Written words for video'},{w:'INTRO',h:'▶️ Opening of your video'},{w:'OUTRO',h:'⏹️ Ending of your video'},{w:'TOPIC',h:'💡 What your video is about'},{w:'LEADS',h:'🤝 Potential customers'}];
const wHangs=['😊','😐','😟','😨','😱','💀','☠️'];
let wW='',wG=[],wWr=0,wMax=6,wHidden=[];
function wReset(){const pick=wWords[Math.floor(Math.random()*wWords.length)];wW=pick.w;wG=[];wWr=0;const unique=[...new Set(wW.split(''))];const hideCount=wW.length<=4?1:wW.length<=6?2:3;const hideable=unique.filter((_,i)=>i>0&&i<unique.length-1);wHidden=hideable.sort(()=>Math.random()-.5).slice(0,Math.min(hideCount,hideable.length));wG=unique.filter(c=>!wHidden.includes(c));const wh=document.getElementById('wHint');if(wh)wh.textContent=pick.h;wRenderAll();}
function wRenderAll(){const wword=document.getElementById('wWord');if(wword)wword.innerHTML=wW.split('').map(c=>{if(wG.includes(c))return'<div class="wg-ltr '+(wHidden.includes(c)?'hit':'pre')+'">'+c+'</div>';return'<div class="wg-ltr blank">_</div>';}).join('');const whang=document.getElementById('wHang');if(whang)whang.textContent=wHangs[Math.min(wWr,wHangs.length-1)];const left=wMax-wWr;const wtries=document.getElementById('wTries');if(wtries)wtries.textContent=wWr===0?'Guess the missing letters!':left>0?'Wrong: '+wWr+' · '+left+' left':'No more tries!';const wkb=document.getElementById('wKb');if(wkb)wkb.innerHTML='ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').map(c=>{const u=wG.includes(c)||(!wHidden.includes(c)&&wW.includes(c));const isC=wG.includes(c)&&wHidden.includes(c);const isW=wG.includes(c)&&!wW.includes(c);const cl=isC?'correct':isW?'wrong':'';const dis=u&&!isC&&!isW;return'<div class="wg-key '+cl+' '+(dis?'used':'')+'" onclick="wGuess(\''+c+'\')">'+c+'</div>';}).join('');}
function wGuess(c){const needed=wHidden.filter(l=>!wG.includes(l));if(!needed.length||wWr>=wMax||wG.includes(c))return;wG.push(c);if(!wHidden.includes(c))wWr++;wRenderAll();const solved=wHidden.every(l=>wG.includes(l));const wh=document.getElementById('wHint');if(solved){if(wh)wh.textContent='You got it! +2 tokens 🎉';addTok(2,'word!','words');}else if(wWr>=wMax){if(wh)wh.textContent='Word was: '+wW+' 😅';}}
/* GAME 3 — Emoji */
const ePz=[{e:'🎬🤖',a:'AI Video'},{e:'📱🎵',a:'TikTok'},{e:'🎙️🎧',a:'Podcast'},{e:'🧠💡',a:'Bright Idea'},{e:'😌🌊',a:'Calm Vibes'},{e:'🔥📈',a:'Going Viral'},{e:'🎯👥',a:'Target Audience'},{e:'🌍📢',a:'Global Reach'},{e:'💆🎶',a:'Music Therapy'},{e:'🤝💼',a:'Business Deal'},{e:'📸✨',a:'Photo Magic'},{e:'🏆🥇',a:'Champion'},{e:'📅✅',a:'Schedule Done'},{e:'🚀📊',a:'Growth Launch'},{e:'💬❤️',a:'Social Love'},{e:'🎨🖥️',a:'Design Work'}];
let eScore=0,eRound=1,eCurr=null,eUsed=[];
function eShuffle(a){return[...a].sort(()=>Math.random()-.5);}
function eNext(){if(eUsed.length>=ePz.length)eUsed=[];let idx;do{idx=Math.floor(Math.random()*ePz.length);}while(eUsed.includes(idx));eUsed.push(idx);eCurr=ePz[idx];const ee=document.getElementById('eEmojis');if(!ee)return;ee.textContent=eCurr.e;const es=document.getElementById('eStat');if(es)es.textContent='Tap the correct answer!';const er=document.getElementById('eRound');if(er)er.textContent=eRound;const wrongs=eShuffle(ePz.filter((_,i)=>i!==idx)).slice(0,3).map(p=>p.a);const choices=eShuffle([eCurr.a,...wrongs]);const ec=document.getElementById('eChoices');if(ec)ec.innerHTML=choices.map(c=>'<div class="eg-choice" onclick="ePick(this,\''+c.replace(/'/g,"\\'")+'\''+')" data-v="'+c.replace(/"/g,'&quot;')+'">'+c+'</div>').join('');}
function ePick(el,val){document.querySelectorAll('.eg-choice').forEach(c=>c.style.pointerEvents='none');const es=document.getElementById('eStat');if(val===eCurr.a){el.classList.add('correct');if(es)es.textContent='Correct! +2 tokens 🎉';eScore++;const esc=document.getElementById('eScore');if(esc)esc.textContent=eScore;addTok(2,'emoji!','emoji');eRound++;setTimeout(eNext,1000);}else{el.classList.add('wrong');document.querySelectorAll('.eg-choice').forEach(c=>{if(c.dataset.v===eCurr.a)c.classList.add('correct');});if(es)es.textContent='Wrong — it was: '+eCurr.a;eRound++;setTimeout(eNext,1400);}}
/* GAME 4 — Math */
let mScore=0,mBest=0,mTimer=null,mAns=0,mActive=false,mTL=60;
function rr(min,max){return Math.floor(Math.random()*(max-min+1))+min;}
function mNewEq(){const type=Math.floor(Math.random()*5);let eq='',ans=0;if(type===0){const a=rr(12,60),b=rr(11,55),c=rr(8,40);eq=a+' + '+b+' + '+c;ans=a+b+c;}else if(type===1){const a=rr(30,80),b=rr(12,45),c=rr(5,25);eq=a+' + '+b+' - '+c;ans=a+b-c;}else if(type===2){const a=rr(50,99),b=rr(15,40),c=rr(8,30);eq=a+' - '+b+' + '+c;ans=a-b+c;}else if(type===3){const a=rr(3,9),b=rr(3,9),c=rr(8,35);eq=a+' × '+b+' + '+c;ans=a*b+c;}else{const a=rr(3,9),b=rr(3,9),c=rr(2,15);eq=a+' × '+b+' - '+c;ans=a*b-c;}mAns=ans;document.getElementById('mEq').textContent=eq+' = ?';document.getElementById('mInp').value='';if(mActive)document.getElementById('mInp').focus();}
function mStart(){mScore=0;mTL=60;mActive=true;document.getElementById('mScore').textContent=0;document.getElementById('mTime').textContent=60;document.getElementById('mBtn').style.display='none';document.getElementById('mEq').style.display='block';document.getElementById('mEq').closest('.arc-panel').querySelector('.sm-timer').style.display='block';document.getElementById('mRow').style.display='flex';document.getElementById('mStat').textContent='Go!';mNewEq();mTimer=setInterval(()=>{mTL--;document.getElementById('mBar').style.width=(mTL/60*100)+'%';document.getElementById('mTime').textContent=mTL;if(mTL<=0){clearInterval(mTimer);mActive=false;if(mScore>mBest){mBest=mScore;document.getElementById('mBest').textContent=mBest;}const earned=Math.floor(mScore/3);document.getElementById('mStat').textContent='Done! Score: '+mScore+(earned?' → +'+earned+' tokens':'');if(earned)addTok(earned,'math!','math');document.getElementById('mEq').textContent='Time up!';document.getElementById('mRow').style.display='none';document.getElementById('mBtn').style.display='block';document.getElementById('mBtn').textContent='▶ Play Again';}},1000);}
function mSub(){if(!mActive)return;const v=parseInt(document.getElementById('mInp').value,10);if(isNaN(v))return;if(v===mAns){mScore++;document.getElementById('mScore').textContent=mScore;document.getElementById('mStat').textContent='✓ Correct! Score: '+mScore;mNewEq();}else{document.getElementById('mStat').textContent='✗ Answer was '+mAns;document.getElementById('mInp').value='';document.getElementById('mInp').focus();}}
/* GAME 5 — Grid */
const gPuzz=[{g:[0,3,0,0,0,0,2,0,0,0,0,4,0,0,0,1],s:[2,3,1,4,4,1,2,3,3,2,4,1,1,4,3,2]},{g:[1,0,0,4,0,3,0,0,0,0,4,0,2,0,0,3],s:[1,2,3,4,4,3,1,2,3,1,4,2,2,4,1,3]},{g:[4,0,0,2,0,2,0,0,0,0,1,0,3,0,0,4],s:[4,3,1,2,1,2,4,3,2,4,3,1,3,1,2,4]},{g:[0,0,4,0,3,0,0,1,0,1,0,0,0,4,0,0],s:[1,2,4,3,3,4,2,1,4,1,3,2,2,3,1,4]},{g:[2,0,0,3,0,3,0,0,0,0,4,0,4,0,0,2],s:[2,1,4,3,1,3,2,4,3,2,4,1,4,1,3,2]}];
let gGiv=[],gRef=[],gSt=[],gSel=-1;
function gReset(){const p=gPuzz[Math.floor(Math.random()*gPuzz.length)];gGiv=[...p.g];gRef=[...p.s];gSt=[...p.g];gSel=-1;const gs=document.getElementById('gStat');if(gs)gs.textContent='';gRender();}
function gRender(){const el=document.getElementById('gGrid');if(!el)return;el.innerHTML='';for(let i=0;i<16;i++){const c=document.createElement('div');const isG=!!gGiv[i];const val=gSt[i];const isErr=val&&!isG&&val!==gRef[i];const isGood=val&&!isG&&val===gRef[i];c.className='ms-cell'+(isG?' given':'')+(i===gSel&&!isG?' sel':'')+(isErr?' err':'')+(isGood?' good':'');c.textContent=val||'';c.onclick=()=>{if(!isG){gSel=i;gRender();}};el.appendChild(c);}}
function gPad(n){if(gSel<0||gGiv[gSel])return;gSt[gSel]=n||0;gRender();if(n&&gSt.every((v,i)=>v===gRef[i])){const gs=document.getElementById('gStat');if(gs)gs.textContent='Solved! +5 tokens 🎉';addTok(5,'puzzle!','grid');}}

</script>

<!-- ═══════════════════════════════════════════════════════════════════════════
     BUILD VIDEO MODAL
═══════════════════════════════════════════════════════════════════════════ -->

<div class="s2-overlay" id="s2Overlay">
  <div class="s2-panel" id="s2Panel" style="position:relative;">

    <!-- Inline processing spinner -->
    <div class="s2-processing-overlay" id="s2ProcessingOverlay">
      <div class="s2-spinner"></div>
      <div>
        <div class="s2-processing-msg"  id="s2ProcessingMsg">Starting video build…</div>
        <div class="s2-processing-step" id="s2ProcessingStep">Please wait…</div>
      </div>
    </div>

    <div class="s2-header">
      <h2>🎬 Build Video</h2>
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
              <option value="1.25" selected>1.25× — Default</option>
              <option value="1.3">1.3× — Very fast</option>
            </select>
          </div>
        </div>

        <!-- ── MEDIA TYPE (standard / b-roll only) ───────────────────────── -->
        <div class="s2-section" id="s2MediaTypeSection">
          <div class="s2-label">Media Type</div>
          <div class="s2-media-opts">
            <div class="s2-media-opt"     data-val="stock_images"  onclick="selMedia(this)">📷 Stock Images</div>
            <div class="s2-media-opt sel" data-val="stock_videos"  onclick="selMedia(this)">🎥 Stock Videos</div>
            <div class="s2-media-opt"     data-val="unique_images" onclick="selMedia(this)">🤖 AI Images</div>
          </div>
        </div>

        <button class="s2-start-btn" onclick="startBuildVideo()">🚀 Build Video Now</button>
      </div>

      <!-- Progress panel -->
      <div id="s2Progress" style="display:none;">
        <div class="s2-steps">
          <div class="s2-step" id="s2Step0">
            <span class="s2-step-icon">✨</span>
            <span class="s2-step-title">AI Prompts</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="s2Step1">
            <span class="s2-step-icon">📝</span>
            <span class="s2-step-title">Create Scenes</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="s2Step2">
            <span class="s2-step-icon">🎤</span>
            <span class="s2-step-title">Audio</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
          <div class="s2-step" id="s2Step3">
            <span class="s2-step-icon">🖼️</span>
            <span class="s2-step-title">Media</span>
            <span class="s2-step-sub">Waiting…</span>
          </div>
        </div>
        <div class="s2-log" id="s2Log"></div>
        <div id="s2DoneBar" style="display:none;" class="s2-done-bar">
          <span style="font-size:14px;font-weight:600;color:#166534;">✅ Video ready!</span>
          <a id="s2VideoLink" href="#">Review / Record / Schedule →</a>
        </div>
      </div>

    </div><!-- /s2-body -->
	    <!-- ══ GAME STRIP — outside s2-body, never pushes log ══ -->
    <div class="game-strip" id="s2GameStrip" style="display:none;">
      <div class="token-bar">
        <span class="tk-left">🎮 Play &amp; Earn</span>
        <span class="tk-val">⚡ <span id="tCount">0</span> tokens</span>
        <span class="tk-msg" id="tMsg"></span>
      </div>
      <div class="arc-bg">
        <div class="arc-tabs">
          <div class="arc-tab active" onclick="arcSw('ttt',this)">TTT</div>
          <div class="arc-tab" onclick="arcSw('wg',this)">Words</div>
          <div class="arc-tab" onclick="arcSw('eg',this)">Emoji</div>
          <div class="arc-tab" onclick="arcSw('sm',this)">Math</div>
          <div class="arc-tab" onclick="arcSw('ms',this)">Grid</div>
        </div>
        <!-- GAME 1: TikTokToe -->
        <div class="arc-panel active" id="arc-ttt">
          <div class="ttt-wrap">
            <div class="ttt-r1">
              <div class="ttt-diff" onclick="tSetD('easy',this)">Easy</div>
              <div class="ttt-diff sel" onclick="tSetD('medium',this)">Medium</div>
              <div class="ttt-diff" onclick="tSetD('hard',this)">Hard</div>
            </div>
            <div class="ttt-board" id="tBoard"></div>
            <div class="ttt-r3">
              <div class="ttt-sc"><div class="ttt-slbl">You</div><div class="ttt-snum" id="tSY">0</div></div>
              <div class="ttt-sc"><div class="ttt-slbl">Draw</div><div class="ttt-snum" id="tSD">0</div></div>
              <div class="ttt-sc"><div class="ttt-slbl">AI</div><div class="ttt-snum" id="tSA">0</div></div>
              <div class="ttt-stat" id="tStat">Your turn</div>
              <button class="ttt-newbtn" onclick="tReset()">New Game</button>
            </div>
          </div>
        </div>
        <!-- GAME 2: Word Guess -->
        <div class="arc-panel" id="arc-wg">
          <div class="wg-hint" id="wHint">Loading…</div>
          <div class="wg-hangman" id="wHang">😊</div>
          <div class="wg-word" id="wWord"></div>
          <div class="wg-tries" id="wTries"></div>
          <div class="wg-kb" id="wKb"></div>
          <button class="arc-btn" onclick="wReset()" style="margin-top:2px;">New Word</button>
        </div>
        <!-- GAME 3: Emoji Guess -->
        <div class="arc-panel" id="arc-eg">
          <div class="arc-stat" id="eStat">Tap the correct answer!</div>
          <div class="eg-emojis" id="eEmojis"></div>
          <div class="eg-choices" id="eChoices"></div>
          <div class="eg-scores">
            <div class="ttt-sc"><div class="ttt-slbl">Score</div><div class="ttt-snum" id="eScore">0</div></div>
            <div class="ttt-sc"><div class="ttt-slbl">Round</div><div class="ttt-snum" id="eRound">1</div></div>
          </div>
        </div>
        <!-- GAME 4: Speed Math -->
        <div class="arc-panel" id="arc-sm">
          <div class="arc-stat" id="mStat">Multi-step math — 60 seconds!</div>
          <div class="sm-eq" id="mEq" style="display:none;"></div>
          <div class="sm-timer" style="display:none;"><div class="sm-bar" id="mBar" style="width:100%"></div></div>
          <div class="sm-row" id="mRow" style="display:none;">
            <input class="sm-inp" id="mInp" type="number" placeholder="your answer" inputmode="numeric" autocomplete="off"/>
            <button class="sm-ok" onclick="mSub()">OK ✓</button>
          </div>
          <div class="sm-scores">
            <div class="ttt-sc"><div class="ttt-slbl">Score</div><div class="ttt-snum" id="mScore">0</div></div>
            <div class="ttt-sc"><div class="ttt-slbl">Best</div><div class="ttt-snum" id="mBest">0</div></div>
            <div class="ttt-sc"><div class="ttt-slbl">Time</div><div class="ttt-snum" id="mTime">60</div></div>
          </div>
          <button class="arc-btn primary" id="mBtn" onclick="mStart()">▶ Start</button>
        </div>
        <!-- GAME 5: Mini Grid -->
        <div class="arc-panel" id="arc-ms">
          <div class="ttt-wrap">
            <div class="ttt-r1" style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:7px;padding:5px 8px;font-size:10px;color:#0369a1;line-height:1.5;display:block;">
              Fill every row <strong>→</strong> and column <strong>↓</strong> with <strong>1 2 3 4</strong> once each. <strong style="color:#0284c7;">Blue</strong> = given. Tap a white cell, then tap a number.
            </div>
            <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:7px;padding:6px;display:flex;justify-content:center;">
              <div class="ms-grid" id="gGrid"></div>
            </div>
            <div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:7px;padding:5px 6px;display:flex;align-items:center;gap:4px;">
              <div class="ms-num" onclick="gPad(1)">1</div>
              <div class="ms-num" onclick="gPad(2)">2</div>
              <div class="ms-num" onclick="gPad(3)">3</div>
              <div class="ms-num" onclick="gPad(4)">4</div>
              <div class="ms-num" onclick="gPad(0)" style="color:#dc2626;font-size:10px;flex:1.5;">✕ Del</div>
              <div class="ttt-stat" id="gStat" style="flex:2;font-size:10px;"></div>
              <button class="ttt-newbtn" onclick="gReset()">New</button>
            </div>
          </div>
        </div>
      </div>
    </div><!-- /game-strip -->
    <!-- Game sits OUTSIDE s2-body so it never expands the modal -->
    

  </div><!-- /s2-panel -->
</div><!-- /s2-overlay -->



<!-- ── GAME STYLES ──────────────────────────────────────────────────── -->
<style>
/* ── Mini Arcade ─────────────────────────────────────────────── */
/* ── Mini Arcade ─────────────────────────────────────────────── */
.arc-wrap{padding:5px 7px 6px;margin-top:4px;background:#1e293b;border-radius:6px;}
.arc-tabs{display:flex;gap:2px;margin-bottom:4px;overflow-x:auto;scrollbar-width:none;}
.arc-tabs::-webkit-scrollbar{display:none;}
.arc-tab{flex:1;padding:5px 2px;border-radius:6px;border:1.5px solid #0284c7;background:#f0f9ff !important;color:#0284c7 !important;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;transition:all .15s;text-transform:uppercase;letter-spacing:.03em;-webkit-tap-highlight-color:transparent;text-align:center;}
.arc-tab:hover{background:#bae6fd !important;}
.arc-tab.active{background:#0284c7 !important;border-color:#0284c7;color:#fff !important;}
.arc-panel{display:none;}
.arc-panel.active{display:block;}
/* TTT */
.ttt-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;}
.ttt-diffs{display:flex;gap:4px;}
.ttt-diff{flex:1;padding:6px 0;border-radius:6px;border:1.5px solid #0284c7;background:#f0f9ff !important;color:#0284c7 !important;font-size:13px;font-weight:700;cursor:pointer;text-align:center;transition:all .1s;}
.ttt-diff:hover{background:#bae6fd !important;}
.ttt-diff.sel{background:#0284c7 !important;border-color:#0284c7;color:#fff !important;}
.ttt-scores{display:flex;gap:3px;margin-bottom:3px;}
.ttt-score{flex:1;background:#0f172a;border:1px solid #334155;border-radius:4px;padding:2px 1px;text-align:center;}
.ttt-score-lbl{font-size:7px;color:#64748b;text-transform:uppercase;letter-spacing:.03em;}
.ttt-score-num{font-size:10px;font-weight:700;color:#e2e8f0;line-height:1.1;}
.ttt-board{display:grid;grid-template-columns:repeat(3,56px);grid-template-rows:repeat(3,56px);gap:0;width:168px;margin:0 auto;border:2px solid #0284c7;border-radius:8px;overflow:hidden;}
.ttt-cell{width:56px;height:56px;border:none;border-right:1px solid #bae6fd;border-bottom:1px solid #bae6fd;background:#f0f9ff !important;color:#0c4a6e !important;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;cursor:pointer;transition:background .1s;user-select:none;-webkit-tap-highlight-color:transparent;}
.ttt-cell:nth-child(3n){border-right:none;}
.ttt-cell:nth-child(n+7){border-bottom:none;}
.ttt-cell:hover:not(.ttt-taken){background:#bae6fd !important;}
.ttt-cell.ttt-taken{cursor:default;}
.ttt-cell.ttt-x{color:#0284c7 !important;}
.ttt-cell.ttt-o{color:#dc2626 !important;}
.ttt-cell.ttt-win{background:#bbf7d0 !important;color:#166534 !important;animation:arcPop .3s ease;}
/* Word Guess */
.wg-word{display:flex;gap:2px;justify-content:center;margin:2px 0 4px;flex-wrap:wrap;}
.wg-letter{width:14px;height:16px;border-radius:2px;border:1px solid #334155;background:#0f172a;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;color:#e2e8f0;text-transform:uppercase;}
.wg-letter.hit{background:#14532d;border-color:#22c55e;color:#86efac;}
.wg-kb{display:flex;flex-wrap:wrap;gap:2px;justify-content:center;margin-top:3px;}
.wg-key{min-width:16px;height:18px;padding:0 2px;border-radius:3px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:8px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .1s;-webkit-tap-highlight-color:transparent;}
.wg-key:active{transform:scale(0.93);}
.wg-key.used{opacity:.35;cursor:default;}
.wg-key.correct{background:#14532d;border-color:#22c55e;color:#86efac;}
.wg-key.wrong{background:#450a0a;border-color:#ef4444;color:#fca5a5;}
/* Emoji Guess */
.eg-emojis{font-size:18px;text-align:center;letter-spacing:4px;margin:3px 0 4px;min-height:24px;}
.eg-input{width:100%;padding:3px 8px;border-radius:4px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:11px;text-align:center;outline:none;box-sizing:border-box;}
.eg-input:focus{border-color:#5fd1ff;}
.eg-input::placeholder{color:#475569;}
.eg-hint{font-size:8px;color:#64748b;text-align:center;margin-top:2px;min-height:10px;}
/* Speed Math */
.sm-eq{font-size:18px;font-weight:700;color:#5fd1ff;text-align:center;margin:3px 0 4px;min-height:22px;}
.sm-input{width:100%;padding:3px 8px;border-radius:4px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;font-size:13px;font-weight:700;text-align:center;outline:none;box-sizing:border-box;}
.sm-input:focus{border-color:#5fd1ff;}
.sm-input::placeholder{color:#475569;}
.sm-timer{height:3px;background:#0f172a;border-radius:2px;overflow:hidden;margin:3px 0;}
.sm-timer-bar{height:100%;background:#5fd1ff;transition:width .5s linear;border-radius:2px;}
/* Mini Sudoku */
.ms-grid{display:grid;gap:2px;margin:3px auto 4px;width:100%;}
.ms-cell{border-radius:3px;border:1px solid #334155;background:#0f172a;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#e2e8f0;cursor:pointer;aspect-ratio:1;-webkit-tap-highlight-color:transparent;}
.ms-cell.given{color:#5fd1ff;cursor:default;}
.ms-cell.selected{background:#1e3a5f;border-color:#5fd1ff;}
.ms-cell.error{background:#450a0a;border-color:#ef4444;color:#fca5a5;}
.ms-numpad{display:flex;gap:3px;justify-content:center;margin:3px 0;}
.ms-num{flex:1;height:20px;border-radius:4px;border:1px solid #334155;background:#0f172a;color:#5fd1ff;font-size:10px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;transition:all .12s;}
.ms-num:hover{background:#1e3a5f;border-color:#5fd1ff;}
/* Shared */
.arc-status{font-size:9px;font-weight:600;color:#94a3b8;text-align:center;min-height:12px;margin-bottom:2px;}
.arc-thinking{font-size:8px;color:#475569;text-align:center;min-height:10px;}
.arc-btn{width:100%;padding:4px;border-radius:4px;border:1px solid #334155;background:#0f172a;color:#94a3b8;font-size:9px;font-weight:700;cursor:pointer;transition:all .12s;text-transform:uppercase;letter-spacing:.03em;margin-top:3px;-webkit-tap-highlight-color:transparent;}
.arc-btn:hover{background:#1e3a5f;border-color:#5fd1ff;color:#5fd1ff;}
@keyframes arcPop{0%{transform:scale(.7)}60%{transform:scale(1.15)}100%{transform:scale(1)}}</style>

<!-- ── UPGRADE / QUOTA MODAL ─────────────────────────────────────── -->
<div id="quotaOverlay" style="display:none;position:fixed;inset:0;background:rgba(10,20,40,0.72);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);">
  <div style="background:#fff;border-radius:24px;width:100%;max-width:460px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.35);animation:vsSlide .3s cubic-bezier(.16,1,.3,1);">

    <!-- Header gradient -->
    <div style="background:linear-gradient(135deg,#0f2a44 0%,#1a4a7a 100%);padding:32px 32px 28px;text-align:center;">
      <div style="width:56px;height:56px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 16px;">🎬</div>
      <h2 style="font-size:22px;font-weight:800;color:#fff;margin:0 0 12px;line-height:1.25;">You've reached your video limit.</h2>
      <p style="font-size:14px;color:rgba(255,255,255,.78);margin:0;line-height:1.65;">
        Thanks for trying VideoVizard! You've created <strong id="quotaCountLabel" style="color:#fff;font-size:16px;">30</strong> great videos.
        Upgrade to a paid plan to keep creating with unlimited generations, no watermarks, and full workspace features.
      </p>
    </div>

    <!-- CTA buttons -->
    <div style="padding:28px 32px 12px;display:flex;flex-direction:column;gap:12px;">
      <a href="/pricing" onclick="closeQuotaModal()"
         style="display:block;text-align:center;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;padding:15px 20px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;box-shadow:0 4px 16px rgba(59,130,246,.35);">
        View Pricing Plans &rarr;
      </a>
      <button onclick="closeQuotaModal()"
              style="background:none;border:1.5px solid #e2e8f0;border-radius:12px;color:#64748b;font-size:14px;font-weight:600;padding:13px 20px;cursor:pointer;width:100%;">
        Maybe Later
      </button>
    </div>

    <!-- Fine print -->
    <div style="padding:4px 32px 24px;text-align:center;">
      <p style="font-size:12px;color:#94a3b8;margin:0;" id="quotaPlanLabel">Free Trial · All videos used</p>
    </div>

  </div>
</div>
</body>
</html>