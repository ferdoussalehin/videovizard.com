<?php
require_once 'check_session.php';
ob_start();  // MUST BE FIRST
session_start();

// Debug mode - comment out in production
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 for JSON responses
ini_set('log_errors', 1);
// At the very top of script_gen.php after session_start()
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/script_gen_debug.log');

// Check login FIRST before anything else
if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Now safe to proceed
$admin_id    = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id   = $_SESSION['client_id'];

include 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';
require_once 'generate_image_api.php';
require_once 'script_gen_functions.php';



// ... rest of your code ...

// Get user info for header
$firstname = '';
$admin_initial = 'U';
if (isset($_SESSION['admin_id'])) {
    $user_query = mysqli_query($conn, "SELECT firstname, email FROM hdb_users WHERE id = '".$_SESSION['admin_id']."'");
    if ($user_query && mysqli_num_rows($user_query) > 0) {
        $user_data = mysqli_fetch_assoc($user_query);
        $firstname = $user_data['firstname'] ?? 'User';
        $admin_initial = strtoupper(substr($firstname, 0, 1));
    }
}
// Function to get user settings from hdb_user_settings
function getUserSettings($conn, $admin_id) {
    $query = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id = '$admin_id' LIMIT 1");
    
    if ($query && mysqli_num_rows($query) > 0) {
        return mysqli_fetch_assoc($query);
    }
    
    // Return default settings if not found
    return [
        'fontfamily' => 'Arial',
        'fontsize' => 28,
        'fontcolor' => '#ffff00',
        'fontweight' => '700',
        'fontcolor_bg' => '#000000',
        'fontbg_enable' => 0,
        'caption_style' => 'typewriter',
        'caption_position' => 'bottom',
        'caption_alignment' => 'center',
        'caption_speed' => 0.85,
        'logo_name' => '',
        'logo_size' => '60',
        'logo_position' => 'bottom-center',
        'logo_enabled' => 0,
        'last_niche_id' => 0
    ];
}

// Fetch user's saved topics from hdb_category_topics
$user_topics = [];
$topics_query = mysqli_query($conn, "SELECT topic_name FROM hdb_category_topics WHERE admin_id = '$admin_id' ORDER BY topic_name ASC");
while ($topic_row = mysqli_fetch_assoc($topics_query)) {
    $user_topics[] = $topic_row['topic_name'];
}
$has_user_topics = !empty($user_topics);
// Get user plan for free trial check
$user_query = mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
$user_row = mysqli_fetch_assoc($user_query);
$plan_type = $user_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

// Get voices from hdb_voices table
$voices_by_lang = [];
$languages_query = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order ASC");
while ($lang = mysqli_fetch_assoc($languages_query)) {
    $lang_code = $lang['language_code'];
    $voices_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$lang_code' ORDER BY voice_name");
    $voices_by_lang[$lang_code] = [];
    while ($voice = mysqli_fetch_assoc($voices_query)) {
        $voices_by_lang[$lang_code][] = $voice;
    }
}

// ── OpenAI TTS function (used for free-trial users) ─────────────────────────
function generateVoiceOpenAI($text, $voice_id, $filepath) {
    $log = __DIR__ . '/tts_debug.log';
    $ts  = date('Y-m-d H:i:s');
    $voice = strpos($voice_id, ':') !== false ? substr($voice_id, strpos($voice_id, ':') + 1) : 'alloy';
    if (empty(trim($voice))) $voice = 'alloy';
    file_put_contents($log, "[$ts] generateVoiceOpenAI called | voice=$voice | filepath=$filepath | text_len=" . strlen($text) . "\n", FILE_APPEND);
    $apiKey = '';
    if (defined('OPENAI_API_KEY'))   $apiKey = OPENAI_API_KEY;
    if (empty($apiKey))              $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if (empty($apiKey)) {
        $cfg = __DIR__ . '/openai_config.php';
        if (file_exists($cfg)) {
            include_once $cfg;
            $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
            file_put_contents($log, "[$ts] Loaded openai_config.php | key_found=" . (!empty($apiKey) ? 'YES' : 'NO') . "\n", FILE_APPEND);
        } else {
            file_put_contents($log, "[$ts] ERROR: openai_config.php not found at $cfg\n", FILE_APPEND);
        }
    }
    if (empty($apiKey)) {
        $msg = 'OpenAI API key not configured — create openai_config.php with define(\'OPENAI_API_KEY\',\'sk-...\')';
        file_put_contents($log, "[$ts] ERROR: $msg\n", FILE_APPEND);
        return ['success' => false, 'error' => $msg];
    }
    file_put_contents($log, "[$ts] API key found (first 8 chars): " . substr($apiKey, 0, 8) . "...\n", FILE_APPEND);
    $dir = dirname($filepath);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_writable($dir)) {
        $msg = "Audio directory not writable: $dir";
        file_put_contents($log, "[$ts] ERROR: $msg\n", FILE_APPEND);
        return ['success' => false, 'error' => $msg];
    }
    $payload = json_encode(['model' => 'gpt-4o-mini-tts', 'voice' => $voice, 'input' => $text], JSON_UNESCAPED_UNICODE);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.openai.com/v1/audio/speech',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);
    file_put_contents($log, "[$ts] HTTP status=$status | curl_err=" . ($curl_err ?: 'none') . " | response_len=" . strlen($response) . "\n", FILE_APPEND);
    if ($curl_err) return ['success' => false, 'error' => 'cURL error: ' . $curl_err];
    if ($status !== 200) {
        $decoded = json_decode($response, true);
        $apiMsg  = $decoded['error']['message'] ?? $response;
        $msg = "OpenAI API error (HTTP $status): $apiMsg";
        file_put_contents($log, "[$ts] ERROR: $msg\n", FILE_APPEND);
        return ['success' => false, 'error' => $msg];
    }
    $written = file_put_contents($filepath, $response);
    if ($written === false) return ['success' => false, 'error' => "Failed to write file: $filepath"];
    file_put_contents($log, "[$ts] SUCCESS — wrote $written bytes to $filepath\n", FILE_APPEND);
    return ['success' => true];
}
// ─────────────────────────────────────────────────────────────────────────────

// Add this handler for processing free format content (IMPROVED VERSION)
// Add this handler for processing free format content (FIXED VERSION)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'process_free_content') {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
	
	// At the start of each AJAX handler, add:
	if (!isset($_SESSION['admin_id'])) {
		header('Content-Type: application/json');
		echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
		exit;
	}
    header('Content-Type: application/json');
    
    $prompt = $_POST['prompt'] ?? '';
    
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'Prompt is required']);
        exit;
    }
    
    // Call ChatGPT function
    // DEBUG: log the prompt to confirm NL_TAGS instructions are present
    $has_nl_tags_in_prompt = (strpos($prompt, 'NL_TAGS') !== false) ? 'YES' : 'NO';
    error_log("process_free_content: prompt_len=" . strlen($prompt) . " | NL_TAGS_in_prompt=$has_nl_tags_in_prompt");
    
    $result = callChatGPT_inam($prompt);
    
    // Check if $result is defined and has the expected structure
    if (isset($result) && is_array($result) && isset($result['success'])) {
        if ($result['success']) {
            $content = trim($result['response']);
            
            // DEBUG: log raw response to check if AI output NL_TAGS
            $has_nl_tags_in_response = (strpos($content, 'NL_TAGS') !== false) ? 'YES' : 'NO';
            error_log("process_free_content: response_len=" . strlen($content) . " | NL_TAGS_in_response=$has_nl_tags_in_response");
            error_log("process_free_content: first_500_chars=" . substr($content, 0, 500));
            
            // Clean up markdown code blocks if present
            $content = preg_replace('/^```.*?\n/', '', $content);
            $content = preg_replace('/```$/', '', $content);
            
            // Remove common title patterns
            $content = preg_replace('/^Title:\s*.*?\n/i', '', $content);
            $content = preg_replace('/^Script:\s*.*?\n/i', '', $content);
            $content = preg_replace('/^Here\'s your script:?\s*\n/i', '', $content);
            $content = preg_replace('/^Output:\s*.*?\n/i', '', $content);
            
            $content = trim($content);
            
            echo json_encode(['success' => true, 'content' => $content]);
        } else {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Generation failed']);
        }
    } else {
        // Handle case where callChatGPT_inam didn't return expected result
        error_log("callChatGPT_inam returned unexpected result: " . print_r($result, true));
        echo json_encode(['success' => false, 'message' => 'API call failed - unexpected response']);
    }
    exit;
}
// Get single scene for verification
// Get single scene for verification
// ---------- AJAX: Get User Settings ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_user_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $settings = getUserSettings($conn, $admin_id);
    
    echo json_encode([
        'success' => true,
        'settings' => $settings
    ]);
    exit;
}

// ===== GET TOPICS BY NICHE (for db tab) =====
if (isset($_POST['get_topics']) && $_POST['get_topics'] == '1') {
    $niche_id = (int)($_POST['niche_id'] ?? 0);
    $html = '<option value="">-- Select Topic --</option>';
    if ($niche_id > 0) {
        $q = mysqli_query($conn, "SELECT id, topic_name FROM hdb_category_topics 
                                   WHERE admin_id = '$admin_id' AND niche_id = $niche_id 
                                   ORDER BY topic_name ASC");
        while ($r = mysqli_fetch_assoc($q)) {
            $html .= "<option value='{$r['id']}'>" . htmlspecialchars($r['topic_name']) . "</option>";
        }
    }
    echo $html;
    exit;
}

// ===== GET TITLES BY TOPIC (for db tab) =====
if (isset($_POST['get_titles']) && $_POST['get_titles'] == '1') {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $html = '<option value="">-- Select Title --</option>';
    if ($topic_id > 0) {
        $q = mysqli_query($conn, "SELECT id, title FROM hdb_topic_titles 
                                   WHERE admin_id = '$admin_id' AND topic_id = $topic_id 
                                   AND (status IS NULL OR status = '')
                                   ORDER BY created_date DESC");
        while ($r = mysqli_fetch_assoc($q)) {
            $html .= "<option value='{$r['id']}'>" . htmlspecialchars($r['title']) . "</option>";
        }
    }
    echo $html;
    exit;
}

// Check if podcast exists
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_podcast') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $podcast_id = (int)$_POST['podcast_id'];
    $client_id = $_SESSION['client_id'];
    
    $query = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE id = $podcast_id AND client_id = '$client_id'");
    
    if ($query && mysqli_num_rows($query) > 0) {
        echo json_encode(['success' => true, 'exists' => true]);
    } else {
        echo json_encode(['success' => true, 'exists' => false]);
    }
    exit;
}

// ===== ADD NICHE =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_niche') {
    header('Content-Type: application/json');
    $niche_name = trim(mysqli_real_escape_string($conn, $_POST['niche_name'] ?? ''));
    if (empty($niche_name)) { echo json_encode(['success' => false, 'message' => 'Niche name required']); exit; }
    $check = mysqli_query($conn, "SELECT id FROM hdb_user_niches WHERE admin_id='$admin_id' AND niche_name='$niche_name'");
    if (mysqli_num_rows($check) > 0) { echo json_encode(['success' => false, 'message' => 'Niche already exists']); exit; }
    mysqli_query($conn, "INSERT INTO hdb_user_niches (admin_id, niche_name, is_ai_generated, created_date) VALUES ('$admin_id','$niche_name',0,NOW())");
    $new_id = mysqli_insert_id($conn);
    // Save as last used
    mysqli_query($conn, "UPDATE hdb_user_settings SET last_niche_id=$new_id WHERE admin_id='$admin_id'");
    echo json_encode(['success' => true, 'id' => $new_id, 'name' => htmlspecialchars($niche_name)]);
    exit;
}

// ===== DELETE NICHE =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_niche') {
    header('Content-Type: application/json');
    $niche_id = (int)($_POST['niche_id'] ?? 0);
    if ($niche_id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid niche']); exit; }
    // Verify ownership
    $check = mysqli_query($conn, "SELECT id FROM hdb_user_niches WHERE id=$niche_id AND admin_id='$admin_id'");
    if (mysqli_num_rows($check) === 0) { echo json_encode(['success' => false, 'message' => 'Not found']); exit; }
    // Get topic IDs for this niche
    $topics_q = mysqli_query($conn, "SELECT id FROM hdb_category_topics WHERE admin_id='$admin_id' AND niche_id=$niche_id");
    while ($t = mysqli_fetch_assoc($topics_q)) {
        mysqli_query($conn, "DELETE FROM hdb_topic_titles WHERE admin_id='$admin_id' AND topic_id={$t['id']}");
    }
    mysqli_query($conn, "DELETE FROM hdb_category_topics WHERE admin_id='$admin_id' AND niche_id=$niche_id");
    mysqli_query($conn, "DELETE FROM hdb_user_niches WHERE id=$niche_id AND admin_id='$admin_id'");
    // Clear last_niche_id if it was this one
    mysqli_query($conn, "UPDATE hdb_user_settings SET last_niche_id=0 WHERE admin_id='$admin_id' AND last_niche_id=$niche_id");
    echo json_encode(['success' => true]);
    exit;
}

// ===== SAVE LAST NICHE =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_last_niche') {
    header('Content-Type: application/json');
    $niche_id = (int)($_POST['niche_id'] ?? 0);
    $check = mysqli_query($conn, "SELECT id FROM hdb_user_settings WHERE admin_id='$admin_id'");
    if (mysqli_num_rows($check) > 0) {
        mysqli_query($conn, "UPDATE hdb_user_settings SET last_niche_id=$niche_id WHERE admin_id='$admin_id'");
    } else {
        mysqli_query($conn, "INSERT INTO hdb_user_settings (admin_id, last_niche_id) VALUES ('$admin_id',$niche_id)");
    }
    echo json_encode(['success' => true]);
    exit;
}

// ===== ADD TOPIC =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_topic') {
    header('Content-Type: application/json');
    $topic_name = trim(mysqli_real_escape_string($conn, $_POST['topic_name'] ?? ''));
    $niche_id   = (int)($_POST['niche_id'] ?? 0);
    if (empty($topic_name)) { echo json_encode(['success'=>false,'message'=>'Topic name required']); exit; }
    $check = mysqli_query($conn, "SELECT id FROM hdb_category_topics WHERE admin_id='$admin_id' AND topic_name='$topic_name'");
    if (mysqli_num_rows($check) > 0) { echo json_encode(['success'=>false,'message'=>'Topic already exists']); exit; }
    mysqli_query($conn, "INSERT INTO hdb_category_topics (admin_id, niche_id, topic_name, is_ai_generated, created_date) VALUES ('$admin_id',$niche_id,'$topic_name',0,NOW())");
    echo json_encode(['success'=>true, 'name'=>htmlspecialchars($topic_name)]);
    exit;
}

// ===== DELETE TOPIC =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_topic') {
    header('Content-Type: application/json');
    $topic_name = mysqli_real_escape_string($conn, $_POST['topic_name'] ?? '');
    if (empty($topic_name)) { echo json_encode(['success'=>false,'message'=>'Topic required']); exit; }
    // Get topic id
    $q = mysqli_query($conn, "SELECT id FROM hdb_category_topics WHERE admin_id='$admin_id' AND topic_name='$topic_name' LIMIT 1");
    if (mysqli_num_rows($q) === 0) { echo json_encode(['success'=>false,'message'=>'Topic not found']); exit; }
    $row = mysqli_fetch_assoc($q);
    $topic_id = $row['id'];
    // Delete titles first
    mysqli_query($conn, "DELETE FROM hdb_topic_titles WHERE admin_id='$admin_id' AND topic_id=$topic_id");
    // Delete topic
    mysqli_query($conn, "DELETE FROM hdb_category_topics WHERE id=$topic_id AND admin_id='$admin_id'");
    echo json_encode(['success'=>true]);
    exit;
}

// ===== ADD TITLE =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_title') {
    header('Content-Type: application/json');
    $title      = trim(mysqli_real_escape_string($conn, $_POST['title'] ?? ''));
    $topic_name = mysqli_real_escape_string($conn, $_POST['topic_name'] ?? '');
    $niche_id   = (int)($_POST['niche_id'] ?? 0);
    if (empty($title)) { echo json_encode(['success'=>false,'message'=>'Title required']); exit; }
    // Get or create topic_id
    $topic_id = 0;
    if (!empty($topic_name)) {
        $q = mysqli_query($conn, "SELECT id FROM hdb_category_topics WHERE admin_id='$admin_id' AND topic_name='$topic_name' LIMIT 1");
        if (mysqli_num_rows($q) > 0) { $r = mysqli_fetch_assoc($q); $topic_id = $r['id']; }
    }
    mysqli_query($conn, "INSERT INTO hdb_topic_titles (admin_id, niche_id, topic_id, title, hook_type, status, created_date) VALUES ('$admin_id',$niche_id,$topic_id,'$title','hook','',NOW())");
    $new_id = mysqli_insert_id($conn);
    echo json_encode(['success'=>true,'id'=>$new_id,'title'=>htmlspecialchars($title)]);
    exit;
}

// ===== DELETE TITLE =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_title') {
    header('Content-Type: application/json');
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    if (empty($title)) { echo json_encode(['success'=>false,'message'=>'Title required']); exit; }
    mysqli_query($conn, "DELETE FROM hdb_topic_titles WHERE admin_id='$admin_id' AND title='$title'");
    echo json_encode(['success'=>true]);
    exit;
}


// ===== LOG MEDIA SEARCH =====
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'log_media_search') {
    header('Content-Type: application/json');
    $podcast_id   = (int)($_POST['podcast_id']   ?? 0);
    $scene_id     = (int)($_POST['scene_id']     ?? 0);
    $scene_no     = (int)($_POST['scene_no']     ?? 0);
    $hashtags     = mysqli_real_escape_string($conn, $_POST['hashtags']      ?? '');
    $found_images = (int)($_POST['found_images'] ?? 0);
    $found_videos = (int)($_POST['found_videos'] ?? 0);
    $selected_file= mysqli_real_escape_string($conn, $_POST['selected_file'] ?? '');
    $selected_type= mysqli_real_escape_string($conn, $_POST['selected_type'] ?? '');
    $was_duplicate= (int)($_POST['was_duplicate']?? 0);
    $ai_generated = (int)($_POST['ai_generated'] ?? 0);
    $ai_prompt    = mysqli_real_escape_string($conn, $_POST['ai_prompt']     ?? '');

    $sql = "INSERT INTO hdb_media_log
            (admin_id, podcast_id, scene_id, scene_no, hashtags,
             search_found_images, search_found_videos,
             selected_file, selected_type, was_duplicate, ai_generated, ai_prompt)
            VALUES
            ('$admin_id', $podcast_id, $scene_id, $scene_no, '$hashtags',
             $found_images, $found_videos,
             '$selected_file', '$selected_type', $was_duplicate, $ai_generated, '$ai_prompt')";
    mysqli_query($conn, $sql);
    echo json_encode(['success' => true]);
    exit;
}

// Fetch user's niches
$user_niches = [];
$niches_query = mysqli_query($conn, "SELECT * FROM hdb_user_niches WHERE admin_id = '$admin_id' ORDER BY niche_name ASC");
while ($niche_row = mysqli_fetch_assoc($niches_query)) {
    $user_niches[] = $niche_row;
}
$has_user_niches = !empty($user_niches);

// Get last used niche
$user_settings_for_niche = getUserSettings($conn, $admin_id);
$last_niche_id = (int)($user_settings_for_niche['last_niche_id'] ?? 0);

// Fetch topics grouped by niche
$topics_by_niche = [];
if ($has_user_niches) {
    $topics_query = mysqli_query($conn, "SELECT ct.*, n.niche_name 
                                         FROM hdb_category_topics ct
                                         LEFT JOIN hdb_user_niches n ON ct.niche_id = n.id
                                         WHERE ct.admin_id = '$admin_id' 
                                         ORDER BY n.niche_name, ct.topic_name");
    while ($topic_row = mysqli_fetch_assoc($topics_query)) {
        $niche_id = $topic_row['niche_id'] ?? 0;
        if (!isset($topics_by_niche[$niche_id])) {
            $topics_by_niche[$niche_id] = [
                'niche_name' => $topic_row['niche_name'] ?? 'Uncategorized',
                'topics' => []
            ];
        }
        $topics_by_niche[$niche_id]['topics'][] = $topic_row['topic_name'];
    }
}
// Get topics by niche ID


// Get topics by niche ID
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_topics_by_niche') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $niche_id = isset($_POST['niche_id']) ? (int)$_POST['niche_id'] : 0;
    
    if ($niche_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid niche ID']);
        exit;
    }
    
    // Get topics for this specific niche
    $topics_query = mysqli_query($conn, "SELECT topic_name FROM hdb_category_topics 
                                         WHERE admin_id = '$admin_id' AND niche_id = '$niche_id' 
                                         ORDER BY topic_name ASC");
    
    $topics = [];
    while ($topic_row = mysqli_fetch_assoc($topics_query)) {
        $topics[] = $topic_row['topic_name'];
    }
    
    echo json_encode([
        'success' => true,
        'topics' => $topics,
        'niche_id' => $niche_id,
        'count' => count($topics)
    ]);
    exit;
}


// Save user topics from AI generation
// Save user topics from AI generation
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_topics') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $topics_json = $_POST['topics'] ?? '[]';
    $topics = json_decode($topics_json, true);
    
    if (!is_array($topics) || empty($topics)) {
        echo json_encode(['success' => false, 'message' => 'No topics to save']);
        exit;
    }
    
    $success_count = 0;
    $duplicate_count = 0;
    $errors = [];
    
    foreach ($topics as $topic) {
        $topic = trim($topic);
        if (empty($topic)) continue;
        
        $topic_escaped = mysqli_real_escape_string($conn, $topic);
        
        // Check if topic already exists for this admin
        $check_query = mysqli_query($conn, "SELECT id FROM hdb_category_topics 
                                            WHERE admin_id = '$admin_id' 
                                            AND topic_name = '$topic_escaped'");
        
        if (mysqli_num_rows($check_query) == 0) {
            // Topic doesn't exist, insert it
            $insert_sql = "INSERT INTO hdb_category_topics (admin_id, topic_name, is_ai_generated, created_date) 
                          VALUES ('$admin_id', '$topic_escaped', 1, NOW())";
            
            if (mysqli_query($conn, $insert_sql)) {
                $success_count++;
                error_log("✅ Topic saved for admin #$admin_id: $topic");
            } else {
                $errors[] = "Failed to save: $topic - " . mysqli_error($conn);
            }
        } else {
            // Topic already exists
            $duplicate_count++;
        }
    }
    
    $message = "Saved $success_count new topics";
    if ($duplicate_count > 0) {
        $message .= ", $duplicate_count duplicates skipped";
    }
    
    echo json_encode([
        'success' => true,
        'saved_count' => $success_count,
        'duplicate_count' => $duplicate_count,
        'errors' => $errors,
        'message' => $message
    ]);
    exit;
}
// Add this AJAX handler for setting podcast thumbnail
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'set_podcast_thumbnail') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $podcast_id = (int)$_POST['podcast_id'];
    $thumbnail = mysqli_real_escape_string($conn, $_POST['thumbnail'] ?? '');
    $client_id = $_SESSION['client_id'];
    
    if (empty($thumbnail)) {
        echo json_encode(['success' => false, 'message' => 'No thumbnail filename provided']);
        exit;
    }
    
    // Verify podcast belongs to this client
    $verify_query = mysqli_query($conn, "SELECT id FROM hdb_podcasts WHERE id = $podcast_id AND client_id = '$client_id'");
    if (!$verify_query || mysqli_num_rows($verify_query) == 0) {
        echo json_encode(['success' => false, 'message' => 'Podcast not found or access denied']);
        exit;
    }
    
    // Update the podcast with the thumbnail
    $update_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $podcast_id";
    
    if (mysqli_query($conn, $update_sql)) {
        error_log("✅ Thumbnail set for podcast #$podcast_id: $thumbnail");
        echo json_encode([
            'success' => true,
            'thumbnail' => $thumbnail,
            'message' => 'Thumbnail set successfully'
        ]);
    } else {
        error_log("❌ Failed to set thumbnail for podcast #$podcast_id: " . mysqli_error($conn));
        echo json_encode([
            'success' => false,
            'message' => 'Database update failed: ' . mysqli_error($conn)
        ]);
    }
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_single_scene') {
    if (!isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    error_log("get_single_scene called for scene_id: $scene_id");
    
    $query = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE id = $scene_id");
    
    if ($query && mysqli_num_rows($query) > 0) {
        $scene = mysqli_fetch_assoc($query);
        error_log("Scene found: " . json_encode(['id' => $scene['id'], 'audio_file' => $scene['audio_file']]));
        echo json_encode(['success' => true, 'scene' => $scene]);
    } else {
        error_log("Scene not found: $scene_id");
        echo json_encode(['success' => false, 'message' => 'Scene not found']);
    }
    exit;
}
// ---------- AJAX: Save User Settings ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_settings') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    
    // Sanitize all inputs
    $fontfamily = mysqli_real_escape_string($conn, $_POST['fontfamily'] ?? 'Arial');
    $fontsize = intval($_POST['fontsize'] ?? 28);
    $fontcolor = mysqli_real_escape_string($conn, $_POST['fontcolor'] ?? '#ffffff');
    $fontweight = mysqli_real_escape_string($conn, $_POST['fontweight'] ?? '700');
    $fontcolor_bg = mysqli_real_escape_string($conn, $_POST['fontcolor_bg'] ?? '#000000');
    $fontbg_enable = intval($_POST['fontbg_enable'] ?? 1);
    $caption_style = mysqli_real_escape_string($conn, $_POST['caption_style'] ?? 'typewriter');
    $caption_position = mysqli_real_escape_string($conn, $_POST['caption_position'] ?? 'bottom');
    $caption_alignment = mysqli_real_escape_string($conn, $_POST['caption_alignment'] ?? 'center');
    $caption_speed = floatval($_POST['caption_speed'] ?? 0.85);
    $logo_name = mysqli_real_escape_string($conn, $_POST['logo_name'] ?? '');
    $logo_size = mysqli_real_escape_string($conn, $_POST['logo_size'] ?? '60');
    $logo_position = mysqli_real_escape_string($conn, $_POST['logo_position'] ?? 'top-right');
    $logo_enabled = intval($_POST['logo_enabled'] ?? 0);
    
    // Check if user settings exist
    $check = mysqli_query($conn, "SELECT id FROM hdb_user_settings WHERE admin_id = '$admin_id'");
    
    if (mysqli_num_rows($check) > 0) {
        // Update existing
        $sql = "UPDATE hdb_user_settings SET 
                fontfamily = '$fontfamily',
                fontsize = $fontsize,
                fontcolor = '$fontcolor',
                fontweight = '$fontweight',
                fontcolor_bg = '$fontcolor_bg',
                fontbg_enable = $fontbg_enable,
                caption_style = '$caption_style',
                caption_position = '$caption_position',
                caption_alignment = '$caption_alignment',
                caption_speed = $caption_speed,
                logo_name = '$logo_name',
                logo_size = '$logo_size',
                logo_position = '$logo_position',
                logo_enabled = $logo_enabled
                WHERE admin_id = '$admin_id'";
    } else {
        // Insert new
        $sql = "INSERT INTO hdb_user_settings (
                admin_id, fontfamily, fontsize, fontcolor, fontweight, 
                fontcolor_bg, fontbg_enable, caption_style, caption_position, 
                caption_alignment, caption_speed, logo_name, logo_size, 
                logo_position, logo_enabled
                ) VALUES (
                '$admin_id', '$fontfamily', $fontsize, '$fontcolor', '$fontweight',
                '$fontcolor_bg', $fontbg_enable, '$caption_style', '$caption_position',
                '$caption_alignment', $caption_speed, '$logo_name', '$logo_size',
                '$logo_position', $logo_enabled
                )";
    }
    
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
// Get topic ID by name
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_topic_id_by_name') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $topic_name = mysqli_real_escape_string($conn, $_POST['topic_name'] ?? '');
    
    if (empty($topic_name)) {
        echo json_encode(['success' => false, 'message' => 'Topic name required']);
        exit;
    }
    
    $query = mysqli_query($conn, "SELECT id FROM hdb_category_topics 
                                  WHERE admin_id = '$admin_id' 
                                  AND topic_name = '$topic_name' 
                                  LIMIT 1");
    
    if ($query && mysqli_num_rows($query) > 0) {
        $row = mysqli_fetch_assoc($query);
        echo json_encode([
            'success' => true,
            'topic_id' => $row['id']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Topic not found'
        ]);
    }
    exit;
}
// Save titles with CTAs

// Update scene with audio file
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene_audio') {
    if (!isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $audio_file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');
    $voice_id = mysqli_real_escape_string($conn, $_POST['voice_id'] ?? '');
    $voice_rate = (float)($_POST['voice_rate'] ?? 1.0);
    
    if (empty($audio_file)) {
        echo json_encode(['success' => false, 'message' => 'Audio filename required']);
        exit;
    }
    
    $update_sql = "UPDATE hdb_podcast_stories SET 
                   audio_file = '$audio_file',
                   voice_id = '$voice_id',
                   voice_rate = $voice_rate
                   WHERE id = $scene_id";
    
    if (mysqli_query($conn, $update_sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}


// AJAX: Get Scenes for Podcast
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scenes') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $podcast_id = (int)$_POST['podcast_id'];
    $client_id = $_SESSION['client_id'];
    
    error_log("get_scenes called for podcast_id: $podcast_id, client_id: $client_id");
    
    $query = "SELECT * FROM hdb_podcast_stories WHERE podcast_id = $podcast_id ORDER BY seq_no ASC";
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        error_log("get_scenes query failed: " . mysqli_error($conn));
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
        exit;
    }
    
    $scenes = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $scenes[] = $row;
    }
    
    error_log("get_scenes found " . count($scenes) . " scenes");
    echo json_encode($scenes); // Return array directly
    exit;
}
// Save user topics with niche
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_user_topics_with_niche') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $niche_json = $_POST['niche'] ?? '{}';
    $topics_json = $_POST['topics'] ?? '[]';
    
    $niche = json_decode($niche_json, true);
    $topics = json_decode($topics_json, true);
    
    if (!is_array($topics) || empty($topics)) {
        echo json_encode(['success' => false, 'message' => 'No topics to save']);
        exit;
    }
    
    if (empty($niche['name'])) {
        echo json_encode(['success' => false, 'message' => 'Niche information missing']);
        exit;
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        // First, handle the niche
        $niche_name = mysqli_real_escape_string($conn, trim($niche['name']));
        $niche_id = null;
        
        // Check if niche exists
        $check_niche = mysqli_query($conn, "SELECT id FROM hdb_user_niches 
                                           WHERE admin_id = '$admin_id' AND niche_name = '$niche_name'");
        
        if (mysqli_num_rows($check_niche) > 0) {
            $niche_row = mysqli_fetch_assoc($check_niche);
            $niche_id = $niche_row['id'];
        } else {
            // Insert new niche
            $is_ai = ($niche['id'] === 'new' || $niche['id'] === null) ? 1 : 0;
            $insert_niche = "INSERT INTO hdb_user_niches (admin_id, niche_name, is_ai_generated, created_date) 
                            VALUES ('$admin_id', '$niche_name', $is_ai, NOW())";
            
            if (mysqli_query($conn, $insert_niche)) {
                $niche_id = mysqli_insert_id($conn);
            } else {
                throw new Exception("Failed to create niche: " . mysqli_error($conn));
            }
        }
        
        // Now save the topics
        $success_count = 0;
        $duplicate_count = 0;
        $errors = [];
        
        foreach ($topics as $topic) {
            $topic = trim($topic);
            if (empty($topic)) continue;
            
            $topic_escaped = mysqli_real_escape_string($conn, $topic);
            
            // Check if topic already exists for this admin and niche
            $check_query = mysqli_query($conn, "SELECT id FROM hdb_category_topics 
                                                WHERE admin_id = '$admin_id' 
                                                AND niche_id = '$niche_id'
                                                AND topic_name = '$topic_escaped'");
            
            if (mysqli_num_rows($check_query) == 0) {
                // Topic doesn't exist, insert it
                $insert_sql = "INSERT INTO hdb_category_topics (admin_id, niche_id, topic_name, is_ai_generated, created_date) 
                              VALUES ('$admin_id', '$niche_id', '$topic_escaped', 1, NOW())";
                
                if (mysqli_query($conn, $insert_sql)) {
                    $success_count++;
                    error_log("✅ Topic saved for admin #$admin_id in niche #$niche_id: $topic");
                } else {
                    $errors[] = "Failed to save: $topic - " . mysqli_error($conn);
                }
            } else {
                // Topic already exists
                $duplicate_count++;
            }
        }
        
        mysqli_commit($conn);
        
        $message = "Saved $success_count new topics for '$niche_name'";
        if ($duplicate_count > 0) {
            $message .= ", $duplicate_count duplicates skipped";
        }
        
        echo json_encode([
            'success' => true,
            'saved_count' => $success_count,
            'duplicate_count' => $duplicate_count,
            'niche_id' => $niche_id,
            'niche_name' => $niche_name,
            'errors' => $errors,
            'message' => $message
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    
    exit;
}
// Get sample voice endpoint
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_voice_sample') {
    ob_clean();
    header('Content-Type: application/json');
    
    $voice_code = mysqli_real_escape_string($conn, $_POST['voice_code'] ?? '');
    
    $sample_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE voice_key = '$voice_code' LIMIT 1");
    
    if ($sample_query && mysqli_num_rows($sample_query) > 0) {
        $sample_row = mysqli_fetch_assoc($sample_query);
        echo json_encode([
            'success' => true,
            'sample_url' => $sample_row['sample_voice'],
            'voice_name' => $sample_row['voice_name']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No sample found for this voice'
        ]);
    }
    exit;
}

// Save global voice settings to session and database
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_voice_settings') {
    ob_clean();
    header('Content-Type: application/json');
    
    $podcast_id = (int)$_POST['podcast_id'];
    $host_voice = mysqli_real_escape_string($conn, $_POST['host_voice'] ?? '');
    $guest_voice = mysqli_real_escape_string($conn, $_POST['guest_voice'] ?? '');
    $rate = mysqli_real_escape_string($conn, $_POST['rate'] ?? '1.0');
    
    // Save to session
    $_SESSION['host_voice'] = $host_voice;
    $_SESSION['guest_voice'] = $guest_voice;
    $_SESSION['rate'] = $rate;
    
    // Save to hdb_podcasts if podcast_id exists
    if ($podcast_id > 0) {
        $update_sql = "UPDATE hdb_podcasts SET 
                       host_voice = '$host_voice', 
                       guest_voice = '$guest_voice', 
                       voice_rate = '$rate' 
                       WHERE id = $podcast_id AND client_id = '$client_id'";
        mysqli_query($conn, $update_sql);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// Save titles with CTAs
// Save titles with CTAs
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_titles_with_ctas') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Log the received data
    error_log("=== save_titles_with_ctas called ===");
    error_log("POST data: " . print_r($_POST, true));
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        error_log("Session expired");
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $niche_id = !empty($_POST['niche_id']) ? (int)$_POST['niche_id'] : 'NULL';
    $topic_id_input = $_POST['topic_id'] ?? '';
    $topic_name = mysqli_real_escape_string($conn, $_POST['topic_name'] ?? '');
    $titles_json = $_POST['titles'] ?? '[]';
    $titles = json_decode($titles_json, true);
    
    error_log("Admin ID: $admin_id");
    error_log("Niche ID: $niche_id");
    error_log("Topic ID input: " . ($topic_id_input ?: 'empty'));
    error_log("Topic Name: $topic_name");
    error_log("Titles JSON: $titles_json");
    error_log("Titles count: " . count($titles));
    
    if (!is_array($titles) || empty($titles)) {
        error_log("No titles to save");
        echo json_encode(['success' => false, 'message' => 'No titles to save']);
        exit;
    }
    
    // FIRST: Try to find the correct topic_id
    $topic_id = 'NULL'; // Default to NULL
    
    // If we have a numeric topic_id from the form, use it
    if (!empty($topic_id_input) && is_numeric($topic_id_input)) {
        $topic_id = (int)$topic_id_input;
        error_log("Using provided numeric topic_id: $topic_id");
    }
    // If we have topic_name but no valid topic_id, try to find it
    else if (!empty($topic_name)) {
        error_log("Looking up topic_id for name: $topic_name");
        $find_topic = mysqli_query($conn, "SELECT id FROM hdb_category_topics 
                                           WHERE admin_id = '$admin_id' 
                                           AND topic_name = '" . mysqli_real_escape_string($conn, $topic_name) . "'
                                           LIMIT 1");
        if ($find_topic && mysqli_num_rows($find_topic) > 0) {
            $topic_row = mysqli_fetch_assoc($find_topic);
            $topic_id = $topic_row['id'];
            error_log("Found topic_id: $topic_id for name: $topic_name");
        } else {
            error_log("No topic found for name: $topic_name, will save with NULL topic_id");
        }
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        $success_count = 0;
        $duplicate_count = 0;
        
        foreach ($titles as $index => $item) {
            $title = mysqli_real_escape_string($conn, trim($item['title'] ?? ''));
            $hook_type = mysqli_real_escape_string($conn, $item['hook_type'] ?? 'mixed');
            
            error_log("Processing title $index: $title");
            
            if (empty($title)) {
                error_log("Skipping empty title");
                continue;
            }
            
            // Check if title already exists
            $check_query = "SELECT id FROM hdb_topic_titles 
                           WHERE admin_id = '$admin_id' 
                           AND title = '$title'
                           AND (status IS NULL OR status = '')";
            
            error_log("Check query: $check_query");
            $check_result = mysqli_query($conn, $check_query);
            
            if (!$check_result) {
                error_log("Check query failed: " . mysqli_error($conn));
                throw new Exception("Check query failed: " . mysqli_error($conn));
            }
            
            if (mysqli_num_rows($check_result) == 0) {
                // Insert title - properly handle NULL vs numeric topic_id
                if ($topic_id === 'NULL') {
                    $insert_title = "INSERT INTO hdb_topic_titles 
                                    (admin_id, niche_id, topic_id, title, hook_type, status, created_date) 
                                    VALUES ('$admin_id', $niche_id, NULL, '$title', '$hook_type', '', NOW())";
                } else {
                    $insert_title = "INSERT INTO hdb_topic_titles 
                                    (admin_id, niche_id, topic_id, title, hook_type, status, created_date) 
                                    VALUES ('$admin_id', $niche_id, $topic_id, '$title', '$hook_type', '', NOW())";
                }
                
                error_log("Insert title: $insert_title");
                
                if (mysqli_query($conn, $insert_title)) {
                    $title_id = mysqli_insert_id($conn);
                    error_log("Title inserted with ID: $title_id, topic_id: " . ($topic_id === 'NULL' ? 'NULL' : $topic_id));
                    
                    // Insert CTAs
                    $ctas = $item['ctas'] ?? [];
                    $cta_types = ['engagement', 'conversion', 'retention'];
                    
                    foreach ($cta_types as $type) {
                        if (!empty($ctas[$type])) {
                            $cta_text = mysqli_real_escape_string($conn, trim($ctas[$type]));
                            
                            $insert_cta = "INSERT INTO hdb_title_ctas (title_id, cta_text, cta_type, created_date) 
                                          VALUES ('$title_id', '$cta_text', '$type', NOW())";
                            
                            error_log("Insert CTA: $insert_cta");
                            
                            if (!mysqli_query($conn, $insert_cta)) {
                                error_log("CTA insert failed: " . mysqli_error($conn));
                            }
                        }
                    }
                    
                    $success_count++;
                } else {
                    error_log("Title insert failed: " . mysqli_error($conn));
                }
            } else {
                error_log("Title already exists, skipping");
                $duplicate_count++;
            }
        }
        
        mysqli_commit($conn);
        error_log("Transaction committed. Success: $success_count, Duplicates: $duplicate_count");
        
        echo json_encode([
            'success' => true,
            'saved_count' => $success_count,
            'duplicate_count' => $duplicate_count,
            'topic_id_used' => ($topic_id === 'NULL' ? 'NULL' : $topic_id),
            'message' => "Saved $success_count titles successfully"
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Exception: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    
    exit;
}
// Handler for creating scenes from content (replace existing)

// ---------- AJAX: Enhance Prompt & Get Hashtags ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'enhance_prompt') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $scene_id = (int)$_POST['scene_id'];
    $original_prompt = trim($_POST['prompt'] ?? '');

    error_log("enhance_prompt called for scene $scene_id");

    if (empty($original_prompt)) {
        echo json_encode(['success' => false, 'message' => "Scene $scene_id has empty prompt"]);
        exit;
    }

    $gpt_prompt = "Enhance this image generation prompt for OpenAI gpt-image-1 model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words. The image should look like a real photograph.

Also generate 2 hashtags that describe the emotion and person type shown (examples: sadwoman, worriedman, happycouple, calmwoman, stressedman, peacefulwoman, anxiousman, hopefulwoman).

Original prompt:
" . $original_prompt . "

Return ONLY valid JSON, no markdown, no code fences:
{\"enhanced_prompt\": \"...\", \"hashtags\": \"emotionperson1,emotionperson2\"}";

    error_log("Calling callChatGPT_inam for scene $scene_id");
    
    $result = callChatGPT_inam($gpt_prompt);
    
    if (!$result || !is_array($result)) {
        error_log("callChatGPT_inam returned invalid result for scene $scene_id");
        echo json_encode(['success' => false, 'message' => 'AI service error']);
        exit;
    }
    
    if (!$result['success']) {
        $err = $result['error'] ?? $result['message'] ?? 'Unknown error';
        error_log("AI Error for scene $scene_id: $err");
        echo json_encode(['success' => false, 'message' => 'AI Error: ' . $err]);
        exit;
    }

    $raw = $result['response'];
    // Clean markdown code fences if present
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```$/i', '', $raw);
    $parsed = json_decode(trim($raw), true);

    if (!$parsed || !isset($parsed['enhanced_prompt']) || !isset($parsed['hashtags'])) {
        error_log("JSON parse fail for scene $scene_id. Raw: " . substr($raw, 0, 300));
        echo json_encode(['success' => false, 'message' => 'Failed to parse AI response']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'enhanced_prompt' => $parsed['enhanced_prompt'],
        'hashtags' => $parsed['hashtags']
    ]);
    exit;
}

// Handler for creating scenes from content (FIXED VERSION)
// Check if audio file exists and delete it
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_audio_file') {
    if (!isset($_SESSION['admin_id'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    ob_clean();
    header('Content-Type: application/json');
    
    $filename = mysqli_real_escape_string($conn, $_POST['filename']);
    $filepath = __DIR__ . '/podcast_audios/' . $filename;
    
    $exists = file_exists($filepath);
    $deleted = false;
    
    if ($exists) {
        // Try to delete the file
        $deleted = unlink($filepath);
        if ($deleted) {
            error_log("✅ Deleted existing audio file: $filename");
        } else {
            error_log("❌ Failed to delete audio file: $filename");
        }
    }
    
    echo json_encode([
        'success' => true,
        'exists' => $exists,
        'deleted' => $deleted,
        'filename' => $filename
    ]);
    exit;
}
// Save titles with CTAs

// Handler for creating scenes from content (FIXED VERSION with proper topic_key and voice settings)
// Handler for creating scenes from content (FIXED VERSION with proper topic_key and voice settings)
if (isset($_POST['action']) && $_POST['action'] == 'create_scenes_from_content') {
    error_log("=== CREATE SCENES FROM CONTENT STARTED ===");
    error_log("POST data: " . print_r($_POST, true));
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    function sendErrorResponse($message, $debug = null) {
        $response = ['success' => false, 'message' => $message];
        if ($debug) {
            $response['debug'] = $debug;
        }
        error_log("ERROR RESPONSE: " . json_encode($response));
        echo json_encode($response);
        exit;
    }
    
    try {
        // Check session
        if (!isset($_SESSION['admin_id'])) {
            sendErrorResponse('Session expired');
        }
        
        $admin_id = $_SESSION['admin_id'];
        $client_id = $_SESSION['client_id'];
        
        // ===== GET USER SETTINGS FIRST - BEFORE ANY INSERTS =====
        $user_settings = getUserSettings($conn, $admin_id);
        
        if (!$user_settings) {
            error_log("⚠️ No user settings found for admin #$admin_id, using defaults");
            // $user_settings already has defaults from getUserSettings function
        }
        
        error_log("User settings loaded: " . json_encode($user_settings));
        
        // Extract user settings with defaults for use throughout
        $default_fontfamily = mysqli_real_escape_string($conn, $user_settings['fontfamily'] ?? 'Inter');
        $default_fontsize = intval($user_settings['fontsize'] ?? 28);
        $default_fontcolor = mysqli_real_escape_string($conn, $user_settings['fontcolor'] ?? '#ffff00');
        $default_fontweight = mysqli_real_escape_string($conn, $user_settings['fontweight'] ?? 'bold');
        $default_bg_color = mysqli_real_escape_string($conn, $user_settings['fontcolor_bg'] ?? '#000000');
        $default_bg_enabled = intval($user_settings['fontbg_enable'] ?? 0);
        $default_caption_style = mysqli_real_escape_string($conn, $user_settings['caption_style'] ?? 'none');
        $default_caption_speed = floatval($user_settings['caption_speed'] ?? 1.0);
        $default_logo_name = mysqli_real_escape_string($conn, $user_settings['logo_name'] ?? '');
        $default_logo_size = mysqli_real_escape_string($conn, $user_settings['logo_size'] ?? '60');
        $default_logo_position = mysqli_real_escape_string($conn, $user_settings['logo_position'] ?? 'top-right');
        $default_logo_enabled = intval($user_settings['logo_enabled'] ?? 0);
        $default_position_x = intval($user_settings['position_x'] ?? 40);
		$default_position_y = intval($user_settings['position_y'] ?? 400);
		$default_width      = intval($user_settings['width'] ?? 275);
        error_log("Using font settings - Family: $default_fontfamily, Size: $default_fontsize, Color: $default_fontcolor");
        
        // Get inputs with proper defaults
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $combined_title = isset($_POST['combined_title']) ? trim($_POST['combined_title']) : $title;
        $target_lang = isset($_POST['target_lang']) ? mysqli_real_escape_string($conn, $_POST['target_lang']) : 'en';
        $reel_type = isset($_POST['reel_type']) ? mysqli_real_escape_string($conn, $_POST['reel_type']) : 'standard';
        
        // IMPORTANT: Get topic from input (this is the "what is in your mind" input box)
        $topic_key = isset($_POST['topic']) ? mysqli_real_escape_string($conn, $_POST['topic']) : '';
        if (empty($topic_key)) {
            // Fallback: try to get from other sources
            $topic_key = isset($_POST['topic_key']) ? mysqli_real_escape_string($conn, $_POST['topic_key']) : 'general';
        }
        
        $category = isset($_POST['category']) ? mysqli_real_escape_string($conn, $_POST['category']) : 'free-format';
        $hook_id = isset($_POST['hook_id']) ? (int)$_POST['hook_id'] : 1;
        $cta = isset($_POST['cta']) ? mysqli_real_escape_string($conn, $_POST['cta']) : '';
        
        // Get voice settings from session or POST
        $host_voice = isset($_SESSION['host_voice']) ? mysqli_real_escape_string($conn, $_SESSION['host_voice']) : '';
        $guest_voice = isset($_SESSION['guest_voice']) ? mysqli_real_escape_string($conn, $_SESSION['guest_voice']) : '';
        $voice_rate = isset($_SESSION['rate']) ? (float)$_SESSION['rate'] : 1.0;
        
        error_log("Topic key from input: $topic_key");
        error_log("Category: $category");
        error_log("Host voice: $host_voice");
        error_log("Voice rate: $voice_rate");
        
        // Get scenes JSON
        $scenes_json = isset($_POST['content']) ? $_POST['content'] : '[]';
        $scenes = json_decode($scenes_json, true);
        
        if (!is_array($scenes) || empty($scenes)) {
            error_log("No valid scenes in JSON: " . $scenes_json);
            sendErrorResponse('No valid scenes found');
        }
        
        error_log("Received " . count($scenes) . " scenes from client");
        
        // ===== GENERATE PROPER HASHTAGS AND KEYWORDS FOR PODCAST =====
        $hashtags_array = ['#videovizard'];
        $keywords_array = ['videovizard'];
        
        // Add topic-based hashtags
        if (!empty($topic_key) && $topic_key != 'custom') {
            $topic_words = preg_split('/[^\w]+/', strtolower($topic_key));
            foreach ($topic_words as $word) {
                $word = trim($word);
                if (strlen($word) > 2) {
                    $clean_word = preg_replace('/[^a-z0-9]/', '', $word);
                    if (!empty($clean_word)) {
                        $hashtags_array[] = '#' . $clean_word;
                        $keywords_array[] = $clean_word;
                    }
                }
            }
        }
        
        // Add scene-based keywords from first few scenes
        $all_scene_text = '';
        foreach ($scenes as $scene) {
            $all_scene_text .= ' ' . ($scene['text'] ?? '');
        }
        
        $words = str_word_count(strtolower($all_scene_text), 1);
        $common_words = ['the', 'and', 'for', 'you', 'your', 'with', 'that', 'this', 'are', 'can', 'will', 'have', 'from', 'they', 'their', 'what', 'about', 'there', 'more', 'some', 'would', 'could', 'should', 'been', 'were', 'was', 'one', 'two', 'three', 'first', 'then', 'than', 'very', 'just', 'like', 'into', 'over', 'also', 'after', 'other', 'such', 'only'];
        
        $keywords = array_diff($words, $common_words);
        $keywords = array_slice($keywords, 0, 8);
        
        foreach ($keywords as $kw) {
            $kw = trim($kw);
            if (strlen($kw) > 2 && !in_array($kw, $keywords_array)) {
                $keywords_array[] = $kw;
                if (!in_array('#' . $kw, $hashtags_array)) {
                    $hashtags_array[] = '#' . $kw;
                }
            }
        }
        
        // Limit to 5-7 hashtags
        $hashtags_array = array_slice(array_unique($hashtags_array), 0, 7);
        $keywords_array = array_slice(array_unique($keywords_array), 0, 10);
        
        $hashtags = implode(', ', $hashtags_array);
        $keywords = implode(', ', $keywords_array);
        $caption_text = mysqli_real_escape_string($conn, substr($title . ' - ' . $topic_key . ' ' . $cta, 0, 200));
        
        error_log("Generated podcast hashtags: $hashtags");
        error_log("Generated podcast keywords: $keywords");
        
        // Create podcast record with proper fields INCLUDING voice settings
        $sql1 = "INSERT INTO hdb_podcasts (
            category, 
            topic_key, 
            title, 
            client_id, 
            lang_code, 
            admin_id, 
            hook_id, 
            hashtags, 
            keywords, 
            caption_text, 
            host_voice,
            guest_voice,
            voice_rate,
            created_date
        ) VALUES (
            '$category',
            '$topic_key',
            '" . mysqli_real_escape_string($conn, $combined_title) . "',
            '$client_id',
            '$target_lang',
            '$admin_id',
            $hook_id,
            '" . mysqli_real_escape_string($conn, $hashtags) . "',
            '" . mysqli_real_escape_string($conn, $keywords) . "',
            '$caption_text',
            '$host_voice',
            '$guest_voice',
            $voice_rate,
            NOW()
        )";
        
        error_log("Insert podcast SQL: " . $sql1);
        
        if (!mysqli_query($conn, $sql1)) {
            error_log("MySQL Error: " . mysqli_error($conn));
            sendErrorResponse('Failed to create podcast: ' . mysqli_error($conn));
        }
        
        $podcast_id = mysqli_insert_id($conn);
        error_log("Podcast created with ID: $podcast_id");
        error_log("Topic key saved: $topic_key");
        error_log("Host voice saved: $host_voice, Rate: $voice_rate");
        
        // Insert scenes - using individual INSERTs
        $success_count = 0;
        $seq_counter = 1;
        
        foreach ($scenes as $scene) 
        {
            // Get scene data with defaults
            $text_contents = isset($scene['text']) ? trim($scene['text']) : '';
            $prompt = isset($scene['prompt']) ? trim($scene['prompt']) : '';
            $scene_hashtags = isset($scene['hashtags']) ? trim($scene['hashtags']) : '';
            $nl_tags = isset($scene['nl_tags']) ? trim($scene['nl_tags']) : '';
            
            if (!empty($scene_hashtags)) {
                // Remove any # symbols first
                $scene_hashtags = str_replace('#', '', $scene_hashtags);
                
                // Split by spaces, clean up, then join with commas
                $tags = preg_split('/\s+/', $scene_hashtags);
                $tags = array_filter($tags, function($tag) {
                    return strlen(trim($tag)) > 1;
                });
                $scene_hashtags = implode(',', $tags);
            }
            
            $actor = isset($scene['actor']) ? $scene['actor'] : 'host';
            
            // Format hashtags for scene (remove # symbols for storage)
            $scene_hashtags = str_replace('#', '', $scene_hashtags);
            
            // Remove any pause tags from stored text
            $text_contents = preg_replace('/<break[^>]*>/', '', $text_contents);
            $text_contents = trim($text_contents);
            
            if (empty($text_contents)) continue;
            
            // Create display text (first 50 chars)
            $text_display = substr($text_contents, 0, 50) . (strlen($text_contents) > 50 ? '...' : '');
            
            // Duration based on reel type
            $duration = ($reel_type === 'broll') ? 30 : 5;
            
            $seq_no = $seq_counter++;
            
            // Determine which voice to use for this scene
            $scene_voice_id = ($actor === 'guest' && !empty($guest_voice)) ? $guest_voice : $host_voice;
            $scene_voice_rate = $voice_rate;
            
            // Escape all values for SQL
            $text_contents_esc = mysqli_real_escape_string($conn, $text_contents);
            $text_display_esc = mysqli_real_escape_string($conn, $text_display);
            $prompt_esc = mysqli_real_escape_string($conn, $prompt);
            $scene_hashtags_esc = mysqli_real_escape_string($conn, $scene_hashtags);
            $actor_esc = mysqli_real_escape_string($conn, $actor);
            $scene_voice_id_esc = mysqli_real_escape_string($conn, $scene_voice_id);
            
            // Insert scene with voice settings
            $insert_scene_sql = "INSERT INTO hdb_podcast_stories 
                (podcast_id, lang_code, category, topic_key, title, actor, 
                 text_contents, text_display, duration, prompt, visual_type, 
                 status, created_date, seq_no, logo_flag, hashtags, natural_language_tags, voice_id, voice_rate) 
                VALUES (
                    $podcast_id,
                    '$target_lang',
                    '$category',
                    '$topic_key',
                    '" . mysqli_real_escape_string($conn, $combined_title) . "',
                    '$actor_esc',
                    '$text_contents_esc',
                    '$text_display_esc',
                    $duration,
                    '$prompt_esc',
                    'image',
                    'PENDING',
                    NOW(),
                    $seq_no,
                    0,
                    '$scene_hashtags_esc',
                    '".mysqli_real_escape_string($conn, $nl_tags)."',
                    '$scene_voice_id_esc',
                    $scene_voice_rate
                )";
            
            error_log("Inserting scene $seq_no: actor=$actor, voice=$scene_voice_id, nl_tags_len=" . strlen($nl_tags));
            error_log("INSERT SQL preview: " . substr($insert_scene_sql, 0, 300));
            
            if (mysqli_query($conn, $insert_scene_sql)) {
                $story_id = mysqli_insert_id($conn);
                
                // Insert into captions table WITH user settings (already loaded)
                $insert_caption_sql = "INSERT INTO hdb_captions 
                    (podcast_id, story_id, caption_type, caption_name, text_content, fontfamily, fontsize, fontcolor, 
                     fontweight, fontstyle, text_align, bg_color, bg_enabled,
                     position_x, position_y, width, rotation,
                     animation_style, animation_speed, is_visible, z_index)
                    VALUES (
                        $podcast_id,
						$story_id,
                        'text',
                        'main',
                        '$text_contents_esc',
                        '$default_fontfamily',
                        $default_fontsize,
                        '$default_fontcolor',
                        '$default_fontweight',
                        'normal',
                        'center',
                        '$default_bg_color',
                        $default_bg_enabled,
						 $default_position_x,
						$default_position_y,
						$default_width,
                        0,
                        '$default_caption_style',
                        $default_caption_speed,
                        1, 1
                    )";
                
                if (mysqli_query($conn, $insert_caption_sql)) {
                    error_log("✅ Caption inserted for scene $story_id with user settings");
                } else {
                    error_log("❌ Failed to insert caption: " . mysqli_error($conn));
                }
                
                $success_count++;
                error_log("Scene $seq_no inserted successfully with caption");
            } else {
                $mysql_err = mysqli_error($conn);
                $mysql_errno = mysqli_errno($conn);
                error_log("Scene $seq_no insert failed (errno=$mysql_errno): " . $mysql_err);
                
                // If error is unknown column (natural_language_tags not yet added), retry without it
                if ($mysql_errno == 1054 && strpos($mysql_err, 'natural_language_tags') !== false) {
                    error_log("Retrying INSERT without natural_language_tags — column may not exist yet. Run: ALTER TABLE hdb_podcast_stories ADD COLUMN natural_language_tags TEXT NULL;");
                    $insert_scene_sql_fallback = "INSERT INTO hdb_podcast_stories 
                        (podcast_id, lang_code, category, topic_key, title, actor, 
                         text_contents, text_display, duration, prompt, visual_type, 
                         status, created_date, seq_no, logo_flag, hashtags, voice_id, voice_rate) 
                        VALUES (
                            $podcast_id,
                            '$target_lang',
                            '$category',
                            '$topic_key',
                            '" . mysqli_real_escape_string($conn, $combined_title) . "',
                            '$actor_esc',
                            '$text_contents_esc',
                            '$text_display_esc',
                            $duration,
                            '$prompt_esc',
                            'image',
                            'PENDING',
                            NOW(),
                            $seq_no,
                            0,
                            '$scene_hashtags_esc',
                            '$scene_voice_id_esc',
                            $scene_voice_rate
                        )";
                    if (mysqli_query($conn, $insert_scene_sql_fallback)) {
                        $story_id = mysqli_insert_id($conn);
                        $success_count++;
                        error_log("Fallback INSERT succeeded for scene $seq_no — PLEASE ADD COLUMN: ALTER TABLE hdb_podcast_stories ADD COLUMN natural_language_tags TEXT NULL;");
                    } else {
                        error_log("Fallback INSERT also failed for scene $seq_no: " . mysqli_error($conn));
                    }
                } // end if errno 1054
            } // end else (main INSERT failed)
        } // end foreach scenes
        
        error_log("Successfully inserted $success_count of " . count($scenes) . " scenes");
        
        // ===== UPDATE PODCAST STORIES WITH ALL USER SETTINGS (for any additional fields) =====
        $update_sql = "UPDATE hdb_podcast_stories SET 
            fontfamily = '$default_fontfamily',
            fontsize = $default_fontsize,
            fontcolor = '$default_fontcolor',
            fontweight = '$default_fontweight',
            fontcolor_bg = '$default_bg_color',
            fontbg_enable = $default_bg_enabled,
            caption_style = '$default_caption_style',
            caption_position = '" . mysqli_real_escape_string($conn, $user_settings['caption_position'] ?? 'bottom') . "',
            caption_alignment = '" . mysqli_real_escape_string($conn, $user_settings['caption_alignment'] ?? 'center') . "',
            caption_speed = $default_caption_speed,
            logo_name = '$default_logo_name',
            logo_size = '$default_logo_size',
            logo_position = '$default_logo_position',
            logo_enabled = $default_logo_enabled
            WHERE podcast_id = $podcast_id";
        
        if (mysqli_query($conn, $update_sql)) {
            error_log("✅ Updated scenes with additional user settings for podcast #$podcast_id");
        } else {
            error_log("❌ Failed to update scenes with additional user settings: " . mysqli_error($conn));
        }
        
        $response = [
            'success' => true,
            'podcast_id' => $podcast_id,
            'scene_count' => $success_count,
            'topic_key' => $topic_key,
            'host_voice' => $host_voice,
            'guest_voice' => $guest_voice,
            'voice_rate' => $voice_rate,
            'hashtags' => $hashtags,
            'keywords' => $keywords
        ];
        
        error_log("Sending success response: " . json_encode($response));
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("EXCEPTION: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        sendErrorResponse('Exception: ' . $e->getMessage());
    }
    
    exit;
}
// Helper function to generate hashtags from text
function generateHashtagsFromText($text) {
    $words = str_word_count(strtolower($text), 1);
    $common_words = ['the', 'and', 'for', 'you', 'your', 'with', 'that', 'this', 'are', 'can', 'will', 'have', 'from', 'they', 'their', 'what', 'about', 'there', 'more', 'some', 'would', 'could', 'should'];
    
    $keywords = array_diff($words, $common_words);
    $keywords = array_slice($keywords, 0, 3);
    
    return implode(' ', $keywords);
}

// Get titles for topic dropdown (for My Idea tab)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_titles_for_topic_dropdown') {
    // Clear output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Log that we received the request
    error_log("=== get_titles_for_topic_dropdown CALLED ===");
    error_log("POST data: " . print_r($_POST, true));
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        error_log("Session expired");
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $admin_id = $_SESSION['admin_id'];
    $topic_id = isset($_POST['topic_id']) ? (int)$_POST['topic_id'] : 0;
    $topic_name = isset($_POST['topic_name']) ? mysqli_real_escape_string($conn, $_POST['topic_name']) : '';
    
    error_log("admin_id: $admin_id, topic_id: $topic_id, topic_name: $topic_name");
    
    // First, find the topic ID if we only have the name
    if ($topic_id == 0 && !empty($topic_name)) {
        $find_topic = mysqli_query($conn, "SELECT id FROM hdb_category_topics 
                                           WHERE admin_id = '$admin_id' 
                                           AND topic_name = '$topic_name' 
                                           LIMIT 1");
        if (!$find_topic) {
            error_log("Find topic query failed: " . mysqli_error($conn));
        } else if (mysqli_num_rows($find_topic) > 0) {
            $topic_row = mysqli_fetch_assoc($find_topic);
            $topic_id = $topic_row['id'];
            error_log("Found topic_id: $topic_id for name: $topic_name");
        } else {
            error_log("No topic found for name: $topic_name");
        }
    }
    
    // Get titles for this topic
    $titles = [];
    
    if ($topic_id > 0) {
        $query = "SELECT id, title, hook_type FROM hdb_topic_titles 
                  WHERE admin_id = '$admin_id' AND topic_id = $topic_id 
                  AND (status IS NULL OR status = '')
                  ORDER BY created_date DESC";
        
        error_log("Titles query: $query");
        
        $result = mysqli_query($conn, $query);
        
        if (!$result) {
            error_log("Query failed: " . mysqli_error($conn));
        } else {
            while ($row = mysqli_fetch_assoc($result)) {
                $titles[] = [
                    'id' => $row['id'],
                    'title' => $row['title'],
                    'hook_type' => $row['hook_type']
                ];
            }
            error_log("Found " . count($titles) . " titles for topic_id: $topic_id");
        }
    } else {
        error_log("No valid topic_id provided");
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'titles' => $titles,
        'topic_id' => $topic_id,
        'count' => count($titles)
    ];
    
    error_log("Sending response: " . json_encode($response));
    
    // Send JSON response
    echo json_encode($response);
    exit;
}

// Load voice settings
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_voice_settings') {
    ob_clean();
    header('Content-Type: application/json');
    
    $podcast_id = (int)$_POST['podcast_id'];
    $response = [
        'success' => true,
        'host_voice' => $_SESSION['host_voice'] ?? '',
        'guest_voice' => $_SESSION['guest_voice'] ?? '',
        'rate' => $_SESSION['rate'] ?? '1.0'
    ];
    
    // Try to load from database if podcast_id exists
    if ($podcast_id > 0) {
        $query = mysqli_query($conn, "SELECT host_voice, guest_voice, voice_rate FROM hdb_podcasts WHERE id = $podcast_id");
        if ($query && mysqli_num_rows($query) > 0) {
            $row = mysqli_fetch_assoc($query);
            if (!empty($row['host_voice'])) $response['host_voice'] = $row['host_voice'];
            if (!empty($row['guest_voice'])) $response['guest_voice'] = $row['guest_voice'];
            if (!empty($row['voice_rate'])) $response['rate'] = $row['voice_rate'];
        }
    }
    
    echo json_encode($response);
    exit;
}

// Generate audio for a scene
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_audio') {
    ob_clean();
    header('Content-Type: application/json');

    $log = __DIR__ . '/tts_debug.log';
    $ts  = date('Y-m-d H:i:s');

    $scene_id   = (int)$_POST['scene_id'];
    $podcast_id = (int)$_POST['podcast_id'];
    $seq_no     = (int)$_POST['seq_no'];
    $lang_code  = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? 'en');
    $voice_id   = $_POST['voice_id'] ?? '';
    $rate       = $_POST['rate'] ?? '1.0';
    $text       = $_POST['text'] ?? '';

    file_put_contents($log, "[$ts] generate_scene_audio | scene=$scene_id podcast=$podcast_id voice=$voice_id text_len=" . strlen($text) . "\n", FILE_APPEND);

    if (empty($text)) {
        echo json_encode(['success' => false, 'error' => 'No text provided']);
        exit;
    }
    if (empty($voice_id)) {
        echo json_encode(['success' => false, 'error' => 'No voice selected']);
        exit;
    }

    // Filename matches JS: voice_podcastId_sceneId_lang.mp3
    $voice_id_safe = mysqli_real_escape_string($conn, $voice_id);
    $rate_safe     = mysqli_real_escape_string($conn, $rate);
    $filename      = 'voice_' . $podcast_id . '_' . $scene_id . '_' . $lang_code . '.mp3';
    $audio_dir     = __DIR__ . '/podcast_audios/';
    if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);
    $filepath = $audio_dir . $filename;

    file_put_contents($log, "[$ts] filepath=$filepath\n", FILE_APPEND);

    // Route: openai: prefix → OpenAI TTS, else → Azure
    if (strpos($voice_id, 'openai:') === 0) {
        $api_result = generateVoiceOpenAI($text, $voice_id, $filepath);
    } else {
        $api_result = generateVoice($text, $voice_id, $rate, $filepath);
    }

    file_put_contents($log, "[$ts] api_result=" . json_encode($api_result) . "\n", FILE_APPEND);

    if ($api_result['success']) {
        $update_sql = "UPDATE hdb_podcast_stories SET
                       audio_file = '$filename',
                       voice_id   = '$voice_id_safe',
                       voice_rate = '$rate_safe'
                       WHERE id = $scene_id";
        if (mysqli_query($conn, $update_sql)) {
            file_put_contents($log, "[$ts] DB updated OK for scene $scene_id\n", FILE_APPEND);
            echo json_encode(['success' => true, 'filename' => $filename, 'file_url' => 'podcast_audios/' . $filename]);
        } else {
            $dberr = mysqli_error($conn);
            file_put_contents($log, "[$ts] DB update FAILED: $dberr\n", FILE_APPEND);
            echo json_encode(['success' => false, 'error' => 'DB update failed: ' . $dberr]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => $api_result['error'] ?? 'Audio generation failed']);
    }
    exit;
}

// Get image suggestions based on hashtags
// Get image suggestions based on hashtags
// Get image suggestions based on hashtags
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_image_suggestions') {
    // Clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    // Check session
    if (!isset($_SESSION['admin_id'])) {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    $hashtags = $_POST['hashtags'] ?? '';
    $limit = (int)($_POST['limit'] ?? 10);
    
    error_log("get_image_suggestions called with hashtags: $hashtags");
    
    if (empty($hashtags)) {
        echo json_encode(['success' => false, 'message' => 'No hashtags provided']);
        exit;
    }
    
    // Split hashtags (they are comma-separated)
    $tags = explode(',', $hashtags);
    $where_conditions = [];
    
    foreach ($tags as $tag) {
        $tag = trim($tag);
        if (!empty($tag)) {
            $tag_escaped = mysqli_real_escape_string($conn, $tag);
            $where_conditions[] = "image_hashtags LIKE '%$tag_escaped%'";
        }
    }
    
    if (empty($where_conditions)) {
        echo json_encode(['success' => false, 'message' => 'No valid hashtags']);
        exit;
    }
    
    $where_clause = implode(' OR ', $where_conditions);
    
    // First try to find videos
    $video_query = "SELECT * FROM hdb_image_data 
                    WHERE media_type = 'video' 
                    AND ($where_clause)
                    ORDER BY RAND()
                    LIMIT $limit";
    
    error_log("Video query: " . $video_query);
    
    $video_result = mysqli_query($conn, $video_query);
    if (!$video_result) {
        error_log("Video query failed: " . mysqli_error($conn));
    }
    
    $videos = [];
    if ($video_result && mysqli_num_rows($video_result) > 0) {
        while ($row = mysqli_fetch_assoc($video_result)) {
            $videos[] = [
                'id' => $row['id'],
                'filename' => $row['image_name'], // Fixed: using image_name
                'url' => 'podcast_images/' . $row['image_name'],
                'hashtags' => $row['image_hashtags'],
                'title' => $row['description'] ?? '',
                'source' => 'video',
                'type' => 'video'
            ];
        }
        error_log("Found " . count($videos) . " videos");
    }
    
    // Then try to find images
    $image_query = "SELECT * FROM hdb_image_data 
                    WHERE (media_type = 'image' OR media_type IS NULL OR media_type = '')
                    AND ($where_clause)
                    ORDER BY 
                        CASE 
                            WHEN image_hashtags LIKE '%" . implode("%' AND image_hashtags LIKE '%", $tags) . "%' THEN 3
                            WHEN image_hashtags LIKE '%" . implode("%' OR image_hashtags LIKE '%", $tags) . "%' THEN 2
                            ELSE 1
                        END DESC,
                        RAND()
                    LIMIT $limit";
    
    error_log("Image query: " . $image_query);
    
    $image_result = mysqli_query($conn, $image_query);
    if (!$image_result) {
        error_log("Image query failed: " . mysqli_error($conn));
    }
    
    $images = [];
    if ($image_result && mysqli_num_rows($image_result) > 0) {
        while ($row = mysqli_fetch_assoc($image_result)) {
            $images[] = [
                'id' => $row['id'],
                'filename' => $row['image_name'], // Fixed: using image_name
                'url' => 'podcast_images/' . $row['image_name'],
                'hashtags' => $row['image_hashtags'],
                'title' => $row['description'] ?? '',
                'source' => 'image',
                'type' => 'image'
            ];
        }
        error_log("Found " . count($images) . " images");
    }
    
    // Combine results - videos first, then images
    $all_results = array_merge($videos, $images);
    
    error_log("Total results: " . count($all_results));
    
    echo json_encode([
        'success' => true,
        'images' => $all_results,
        'videos' => count($videos),
        'images_count' => count($images),
        'total' => count($all_results),
        'search_tags' => $tags
    ]);
    exit;
}


// Assign image to scene
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'assign_image') {
    ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $image_filename = mysqli_real_escape_string($conn, $_POST['image_filename']);
    
    $update_sql = "UPDATE hdb_podcast_stories SET image_file = '$image_filename' WHERE id = $scene_id";
    
    if (mysqli_query($conn, $update_sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VideoVizard - Enhanced Script Generator</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --dark-blue: #0f2a44;
            --mid-blue: #143b63;
            --accent: #5fd1ff;
            --green: #10b981;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --shadow: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-hover: 0 8px 24px rgba(0,0,0,0.12);
            --error: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --info: #3b82f6;
            --purple: #8b5cf6;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
        }

        /* Header */
        .vidora-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: linear-gradient(90deg, #0f2a44, #143b63);
            color: #fff;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .brand-container a { 
            text-decoration: none; 
            display: flex; 
            align-items: center; 
            gap: 8px; 
        }

        .main-icon { 
            font-size: 24px; 
        }

        .brand-text { 
            display: flex; 
            flex-direction: column; 
        }

        .logo { 
            font-size: 18px; 
            font-weight: 700; 
            line-height: 1.2;
        }

        .brand-video { 
            color: white; 
        }

        .brand-vizard { 
            color: var(--accent); 
        }

        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px;
            width: 100%;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        }

        .card-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark-blue);
            margin-bottom: 4px;
        }

        .card-header p {
            font-size: 14px;
            color: var(--muted);
        }

        .card-body {
            padding: 20px;
        }

        /* Tab Bar */
        .tab-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .tab {
            padding: 12px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
            background: transparent;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            flex-shrink: 0;
            min-height: 48px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tab.active {
            background: var(--dark-blue);
            color: white;
            border-color: var(--dark-blue);
        }

        .tab-content.hidden {
            display: none;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-blue);
            margin-bottom: 6px;
        }

        .field-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        select, textarea, input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: #fff;
        }

        select:focus, textarea:focus, input:focus {
            outline: none;
            border-color: var(--purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        /* Voice Selection */
        .voice-row {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 20px;
        }

        @media (min-width: 768px) {
            .voice-row {
                flex-direction: row;
                gap: 20px;
            }
        }

        .voice-item {
            flex: 1;
            min-width: 0;
        }

        .voice-item label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-blue);
            margin-bottom: 6px;
        }

        .voice-select {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .btn-sample {
            background: var(--purple);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-sample:hover {
            background: #7c3aed;
            transform: translateY(-1px);
        }

        /* Buttons */
        .btn {
            border: none;
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #143b63, #1e4a7a);
            box-shadow: 0 4px 12px rgba(15, 42, 68, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669, #047857);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .button-group .btn {
            flex: 1;
            min-width: 200px;
        }

        /* Mode Title Box */
        .mode-title-box {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .mode-title-box.ai-mode {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: white;
        }

        .mode-title-box.user-mode {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .mode-title-box.database-mode {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
        }

        .mode-title-box h2 {
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .mode-title-box .mode-badge {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
        }

        /* Podcast Voice Info */
        .podcast-voice-info {
            background: #ede9fe;
            border: 1px solid #8b5cf6;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: #6d28d9;
        }

        /* Progress Container */
        .progress-container {
            display: none;
            margin-top: 24px;
            background: var(--card-bg);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        .progress-bar-bg {
            background: var(--border);
            border-radius: 8px;
            height: 24px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .progress-bar-fill {
            background: linear-gradient(90deg, var(--dark-blue), var(--purple));
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }

        .progress-text {
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--dark-blue);
            font-size: 14px;
        }

        .realtime-log {
            background: #0f172a;
            color: #e2e8f0;
            padding: 14px;
            border-radius: 10px;
            max-height: 250px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
            line-height: 1.6;
        }

        .log-entry { 
            padding: 4px 0; 
            border-bottom: 1px solid #334155; 
        }

        .log-entry.success { 
            color: #4ade80; 
        }

        .log-entry.error { 
            color: #f87171; 
        }

        .log-entry.info { 
            color: #60a5fa; 
        }

        .log-entry.warning { 
            color: #fbbf24; 
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            backdrop-filter: blur(4px);
        }

        .success-card {
            background: white;
            padding: 32px;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 400px;
            width: 90%;
        }

        .success-card h2 { 
            color: var(--success); 
            margin: 0 0 12px; 
            font-size: 24px; 
        }

        .success-card p { 
            color: var(--muted); 
            margin-bottom: 24px; 
            font-size: 15px; 
        }

        .success-card .btn-primary,
        .success-card .btn-secondary {
            width: 100%;
            margin-bottom: 12px;
            min-height: 52px;
        }

        .btn-secondary {
            background: var(--border);
            color: var(--text);
            border: none;
            padding: 14px 20px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
        }

        /* Image Selector Modal */
        .image-selector-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }

        .image-selector-content {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
        }

        .image-selector-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .image-selector-header h3 {
            font-size: 20px;
            color: var(--dark-blue);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--muted);
        }

        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .image-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
        }

        .image-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-color: var(--purple);
        }

        .image-card.selected {
            border: 3px solid var(--purple);
        }

        .image-card img {
            width: 100%;
            aspect-ratio: 1/1;
            object-fit: cover;
        }

        .image-card .image-hashtags {
            padding: 8px;
            font-size: 10px;
            color: var(--muted);
            background: #f8fafc;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Footer */
        .site-footer {
            background: linear-gradient(90deg, #0f2a44, #143b63);
            color: rgba(255,255,255,0.55);
            padding: 16px;
            font-size: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            text-align: center;
            margin-top: 40px;
        }

        .footer-brand { 
            font-weight: 700; 
            color: var(--accent); 
        }

        .footer-links { 
            display: flex; 
            gap: 20px; 
            justify-content: center; 
            flex-wrap: wrap; 
        }

        .footer-links a { 
            color: rgba(255,255,255,0.55); 
            text-decoration: none; 
            transition: color 0.2s; 
            padding: 8px 0;
        }

        .footer-links a:hover { 
            color: var(--accent); 
        }

        @media (min-width: 768px) {
            .container {
                padding: 24px;
            }
            
            .site-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 16px 30px;
            }
        }
		
		/* Modal enhancements */
.modal-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    backdrop-filter: blur(4px);
}

.success-card {
    background: white;
    padding: 32px;
    border-radius: 24px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    max-width: 600px;
    width: 90%;
}

.topic-item, .title-item {
    padding: 10px;
    margin: 5px 0;
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.topic-item:hover, .title-item:hover {
    background: #f1f5f9;
    border-color: var(--purple);
}

.topic-item.selected, .title-item.selected {
    background: #ede9fe;
    border-color: var(--purple);
    border-width: 2px;
}

.topic-item input[type="checkbox"] {
    width: auto;
    margin-right: 10px;
}

.field-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}

.btn-sample {
    background: var(--purple);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.btn-sample:hover {
    background: #7c3aed;
    transform: translateY(-1px);
}




/* Title item with CTA styling */
.title-item {
    margin-bottom: 15px;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}

.title-header {
    padding: 15px;
    background: white;
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    border-bottom: 1px solid transparent;
    transition: all 0.2s;
}

.title-header:hover {
    background: #f8f4ff;
}

.title-header.expanded {
    border-bottom-color: var(--border);
    background: #f1f5f9;
}

.title-header input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin: 0;
    cursor: pointer;
    accent-color: var(--purple);
}

.title-text {
    flex: 1;
    font-weight: 500;
    color: var(--dark-blue);
}

.delete-title-btn {
    background: none;
    border: none;
    color: #ef4444;
    font-size: 18px;
    cursor: pointer;
    padding: 5px 10px;
    border-radius: 6px;
    opacity: 0.6;
    transition: all 0.2s;
}

.delete-title-btn:hover {
    opacity: 1;
    background: #fee2e2;
}

.expand-icon {
    color: var(--muted);
    font-size: 18px;
    transition: transform 0.2s;
}

.expand-icon.expanded {
    transform: rotate(90deg);
}

/* CTA section styling */
.ctas-container {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed var(--border);
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
}

.cta-item {
    padding: 8px 12px;
    background: #f8fafc;
    border-radius: 8px;
    font-size: 13px;
    color: var(--text);
    border: 1px solid var(--border);
}

.cta-item strong {
    color: var(--purple);
    display: block;
    margin-bottom: 4px;
    font-size: 11px;
    text-transform: uppercase;
}

.cta-text {
    line-height: 1.4;
}

.cta-badge.engagement { background: #dbeafe; color: #1e40af; }
.cta-badge.conversion { background: #dcfce7; color: #166534; }
.cta-badge.retention { background: #fff3cd; color: #856404; }

/* Topic items styling */
.topic-item {
    padding: 12px 15px;
    margin: 8px 0;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: white;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 12px;
}

.topic-item:hover {
    background: #f8f4ff;
    border-color: var(--purple);
    transform: translateX(5px);
}

.topic-item input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin: 0;
    cursor: pointer;
    accent-color: var(--purple);
}

.topic-item label {
    flex: 1;
    cursor: pointer;
    margin: 0;
    font-size: 14px;
    color: var(--text);
}

.duplicate-warning {
    color: #ef4444;
    font-size: 12px;
    margin-left: 10px;
    font-style: italic;
}
.delete-topic-btn:hover {
    opacity: 1;
    background: #fee2e2;
}
.delete-topic-btn {
    background: none;
    border: none;
    color: #ef4444;
    font-size: 16px;
    cursor: pointer;
    padding: 5px 8px;
    border-radius: 6px;
    opacity: 0.6;
}
/* Dropdown with trash icon styling */
.dropdown-with-delete {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
}

.dropdown-with-delete select {
    flex: 1;
}

.delete-dropdown-btn {
    background: none;
    border: none;
    color: #ef4444 !important;  /* Force red color */
    font-size: 22px;
    cursor: pointer;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    min-height: 42px;
    border: 1.5px solid var(--border);
    background: white;
}

.delete-dropdown-btn:hover:not(:disabled) {
    background: #fee2e2;
    border-color: #ef4444;
    transform: scale(1.05);
}

.delete-dropdown-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}
/* Add this to your existing CSS */
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Also make sure the loading spinner container is visible when needed */
#titleLoading {
    display: none;
    text-align: center;
    padding: 30px 20px;
    background: #f1f5f9;
    border-radius: 12px;
    margin: 15px 0;
    z-index: 1000;
}

#titleLoading .loading-spinner {
    width: 50px;
    height: 50px;
    border: 5px solid #e2e8f0;
    border-top: 5px solid #8b5cf6;
    border-radius: 50%;
    margin: 0 auto;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loading-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid #e2e8f0;
    border-top: 4px solid #8b5cf6;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}


/* ===== WIZARD OPTION CARDS ===== */
.wizard-option {
    flex: 1;
    min-width: 200px;
    cursor: pointer;
    border: 2px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    background: #fff;
    transition: all .2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.wizard-option:hover {
    border-color: #8b5cf6;
    box-shadow: 0 6px 20px rgba(139,92,246,0.12);
    transform: translateY(-2px);
}
.wizard-option.selected {
    border-color: #8b5cf6;
    background: #faf5ff;
    box-shadow: 0 6px 20px rgba(139,92,246,0.15);
}
@media (max-width: 600px) {
    .wizard-option { min-width: 100%; }
}
    </style>
</head>
<body>

<div class="vidora-header">
    <div class="brand-container">
        <a href="index.php">
            <span class="main-icon">🎬</span>
            <div class="brand-text">
                <div class="logo">
                    <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="container">
    <!-- Main Card -->

<!-- Step 2 Setup Panel -->
<div id="step2SetupPanel" style="display:none; margin-bottom:20px;" class="card">
    <div class="card-body">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
            <h3 style="font-size:16px; margin:0;">🎬 Step 2: Video Setup</h3>
		<p style="margin:6px 0 0; font-size:13px; color:#666;">
		Choose your voice, visuals, and style. Select from stock videos, images, AI-generated visuals, or a mix to match your content.
		</p>
            <button type="button" onclick="hideStep2Setup()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;">←</button>
        </div>

        <!-- Host Voice -->
        <div class="form-group">
            <label>🎤 Host Voice</label>
            <select id="s2_hostVoice" class="voice-select">
                <option value="">-- Select Host Voice --</option>
            </select>
            <button class="btn-sample" onclick="playS2VoiceSample('host')" style="margin-top:6px;">▶️ Sample</button>
        </div>

        <!-- Guest Voice (podcast only) -->
        <div class="form-group" id="s2_guestContainer" style="display:none;">
            <label>👤 Guest Voice</label>
            <select id="s2_guestVoice" class="voice-select">
                <option value="">-- Select Guest Voice --</option>
            </select>
            <button class="btn-sample" onclick="playS2VoiceSample('guest')" style="margin-top:6px;">▶️ Sample</button>
        </div>

        <!-- Speed -->
        <div class="form-group dd-wrap" id="s2_speed_dd_wrap">
            <div class="dd-trigger" id="s2_speed_trigger" onclick="toggleDD('s2_speed')">
                <span>⚡ Speed: <strong id="s2_speed_label">Normal (1.0x)</strong></span>
                <span class="dd-arrow" id="s2_speed_arrow">▾</span>
            </div>
            <div class="dd-panel" id="s2_speed_panel">
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_speedVal','0.75','Very Slow (0.75x)',this)">Very Slow</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_speedVal','0.85','Calm (0.85x)',this)">Calm</button>
                <button type="button" class="reel-dd-btn active" onclick="pickDD('s2_speedVal','1.0','Normal (1.0x)',this)">Normal</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_speedVal','1.15','Podcast (1.15x)',this)">Podcast</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_speedVal','1.25','Fast (1.25x)',this)">Fast</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_speedVal','1.5','Very Fast (1.5x)',this)">Very Fast</button>
            </div>
            <input type="hidden" id="s2_speedVal" value="1.0">
        </div>

        <!-- Video Media Source -->
        <div class="form-group dd-wrap" id="s2_media_dd_wrap">
            <div class="dd-trigger" id="s2_media_trigger" onclick="toggleDD('s2_media')">
                <span>🎞️ Video Media: <strong id="s2_media_label">Mix Media</strong></span>
                <span class="dd-arrow" id="s2_media_arrow">▾</span>
            </div>
            <div class="dd-panel" id="s2_media_panel">
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_mediaVal','stock_images','Stock Images',this)">Stock Images</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_mediaVal','stock_videos','Stock Videos',this)">Stock Videos</button>
                <button type="button" class="reel-dd-btn" onclick="pickDD('s2_mediaVal','unique_images','AI Images',this)">AI Images</button>
                <button type="button" class="reel-dd-btn active" onclick="pickDD('s2_mediaVal','mix_media','Mix Media',this)">Mix Media</button>
            </div>
            <input type="hidden" id="s2_mediaVal" value="mix_media">
        </div>

        <!-- Proceed Button -->
        <div class="button-group" style="margin-top:20px;">
            <button class="btn btn-success" id="s2ProceedBtn" onclick="proceedStep2()" style="width:100%;">🚀 Proceed: Build Video Scenes</button>
        </div>
    </div>
</div>

    <div id="mainScriptCard">
    <div class="card">
        <div class="card-header">
            <h1 id="cardHeaderTitle">📋 Script Generator</h1>
            <p id="cardHeaderSubtitle">Choose how you want to create your script</p>
        </div>
		<div class="card-body">

        <!-- ===== STEP 1: CHOOSE METHOD ===== -->
        <div id="wizardStep1" style="margin-bottom:24px;">
            <div style="text-align:center; margin-bottom:16px;">
                <div style="display:inline-block; background:#f1f5f9; border-radius:30px; padding:6px 18px; font-size:12px; font-weight:700; color:#64748b; letter-spacing:.05em; text-transform:uppercase;">Step 1 — Choose how to create your script</div>
            </div>
            <div style="display:flex; gap:14px; flex-wrap:wrap;">

                <!-- Option A: AI Script -->
                <div class="wizard-option" id="wiz_ai" onclick="selectWizardOption('ai')" style="flex:1; min-width:200px; cursor:pointer; border:2px solid var(--border); border-radius:16px; padding:20px; background:#fff; transition:all .2s;">
                    <div style="font-size:32px; margin-bottom:10px;">✨</div>
                    <div style="font-weight:700; font-size:15px; color:#0f2a44; margin-bottom:6px;">AI Generate Script</div>
                    <div style="font-size:13px; color:#64748b; line-height:1.5;">Give AI your topic and instructions — it writes the full script for you.</div>
                    <div style="margin-top:12px; font-size:11px; font-weight:600; color:#8b5cf6; background:#f5f3ff; padding:4px 10px; border-radius:20px; display:inline-block;">Best for new ideas</div>
                </div>

                <!-- Option B: Content Bank -->
                <div class="wizard-option" id="wiz_bank" onclick="selectWizardOption('bank')" style="flex:1; min-width:200px; cursor:pointer; border:2px solid var(--border); border-radius:16px; padding:20px; background:#fff; transition:all .2s;">
                    <div style="font-size:32px; margin-bottom:10px;">📚</div>
                    <div style="font-weight:700; font-size:15px; color:#0f2a44; margin-bottom:6px;">Content Bank</div>
                    <div style="font-size:13px; color:#64748b; line-height:1.5;">Pick a niche, topic and title from your saved library — AI builds the script.</div>
                    <div style="margin-top:12px; font-size:11px; font-weight:600; color:#10b981; background:#f0fdf4; padding:4px 10px; border-radius:20px; display:inline-block;">Best for planned content</div>
                </div>

                <!-- Option C: My Content -->
                <div class="wizard-option" id="wiz_content" onclick="selectWizardOption('content')" style="flex:1; min-width:200px; cursor:pointer; border:2px solid var(--border); border-radius:16px; padding:20px; background:#fff; transition:all .2s;">
                    <div style="font-size:32px; margin-bottom:10px;">📄</div>
                    <div style="font-weight:700; font-size:15px; color:#0f2a44; margin-bottom:6px;">I Have Content</div>
                    <div style="font-size:13px; color:#64748b; line-height:1.5;">Paste your own text — it gets split into scenes automatically, no AI needed.</div>
                    <div style="margin-top:12px; font-size:11px; font-weight:600; color:#3b82f6; background:#eff6ff; padding:4px 10px; border-radius:20px; display:inline-block;">Best for existing content</div>
                </div>

            </div>
        </div>

        <!-- ===== STEP 2: CONFIGURE & GENERATE (hidden until option chosen) ===== -->
        <div id="wizardStep2" style="display:none;">

            <!-- Back + label -->
            <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                <button onclick="resetWizard()" style="background:#f1f5f9; border:none; color:#0f2a44; font-size:13px; font-weight:600; cursor:pointer; border-radius:30px; padding:8px 16px; display:flex; align-items:center; gap:6px;">← Back</button>
                <div id="wizardSelectedLabel" style="padding:8px 16px; background:#f8fafc; border-radius:12px; border-left:4px solid #8b5cf6; font-weight:600; font-size:14px; color:#0f2a44; flex:1;"></div>
            </div>

			<!-- Hidden global lang select - still used by JS -->
            <select id="global_lang_select" onchange="loadVoicesForLanguage(this.value); saveVoiceSettings(); syncLangTabs(this.value);" style="display:none;">
                <?php
                $lang_query = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order");
                while ($lang = mysqli_fetch_assoc($lang_query)) {
                    $selected = ($lang['language_code'] == 'en') ? 'selected' : '';
                    echo "<option value='{$lang['language_code']}' $selected>{$lang['flag_emoji']} {$lang['language']}</option>";
                }
                ?>
            </select>
            <!-- Hidden voice pickers - populated by JS, read by Step 2 -->
            <select id="hostVoicePicker" style="display:none;"></select>
            <select id="guestVoicePicker" style="display:none;"></select>
            <select id="ratePicker" style="display:none;">
                <option value="0.75">Very Slow (0.75x)</option>
                <option value="0.85">Calm (0.85x)</option>
                <option value="1.0" selected>Normal (1.0x)</option>
                <option value="1.15">Podcast (1.15x)</option>
                <option value="1.25">Fast (1.25x)</option>
                <option value="1.5">Very Fast (1.5x)</option>
            </select>

        <!-- My Idea Tab -->
<!-- My Idea Tab -->
<div id="my-idea-tab" class="tab-content">
<!-- Settings Toggle -->
    <div class="stg-wrap" id="idea_stg_wrap">
        <button type="button" class="stg-btn" onclick="toggleStg('idea')">
            ⚙️ Settings <span class="stg-chevron" id="idea_stg_chevron">▾</span>
        </button>
        <div class="stg-panel" id="idea_stg_panel">

            <!-- Language -->
            <div class="form-group dd-wrap" id="idea_lang_dd_wrap">
                <div class="dd-trigger" id="idea_lang_trigger" onclick="toggleDD('idea_lang')">
                    <span>🌐 Language: <strong id="idea_lang_label">🇬🇧 English</strong></span>
                    <span class="dd-arrow" id="idea_lang_arrow">▾</span>
                </div>
                <div class="dd-panel" id="idea_lang_panel" style="max-height:200px;overflow-y:auto;flex-wrap:wrap;">
                    <?php
                    $lang_q3 = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order");
                    while ($l = mysqli_fetch_assoc($lang_q3)) {
                        $active = ($l['language_code'] == 'en') ? ' active' : '';
                        $label  = htmlspecialchars($l['flag_emoji'] . ' ' . $l['language']);
                        echo "<button type=\"button\" class=\"reel-dd-btn{$active}\" onclick=\"pickLang('{$l['language_code']}','{$label}',this)\">{$label}</button>";
                    }
                    ?>
                </div>
            </div>

            <!-- Reel Type -->
            <div class="form-group reel-dd-wrap" id="idea_reel_dd_wrap">
                <div class="reel-dd-trigger" onclick="toggleReelDD('idea')">
                    <span>🎬 Reel Type: <strong id="idea_reel_type_label">Standard</strong></span>
                    <span class="reel-dd-arrow" id="idea_reel_dd_arrow">▾</span>
                </div>
                <div class="reel-dd-panel" id="idea_reel_dd_panel">
                    <button type="button" class="reel-dd-btn active" onclick="pickReel('idea','standard','Standard',this)">Standard</button>
                    <button type="button" class="reel-dd-btn" onclick="pickReel('idea','broll','B-Roll',this)">B-Roll</button>
                    <button type="button" class="reel-dd-btn" onclick="pickReel('idea','podcast','Podcast',this)">Podcast</button>
                </div>
                <input type="hidden" id="idea_reel_type" value="standard">
            </div>

            <!-- Video Duration -->
            <div class="form-group dd-wrap" id="idea_dur_dd_wrap">
                <?php if ($is_free_trial): ?>
                <div class="dd-trigger" style="opacity:0.7; cursor:not-allowed;">
                    <span>⏱️ Duration: <strong>30 seconds</strong></span>
                    <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                </div>
                <input type="hidden" id="idea_duration" value="30">
                <small style="color:#64748b;margin-top:4px;font-size:12px;display:block;">Upgrade to Pro for longer videos</small>
                <?php else: ?>
                <div class="dd-trigger" id="idea_dur_trigger" onclick="toggleDD('idea_dur')">
                    <span>⏱️ Duration: <strong id="idea_dur_label">30 seconds</strong></span>
                    <span class="dd-arrow" id="idea_dur_arrow">▾</span>
                </div>
                <div class="dd-panel" id="idea_dur_panel">
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','10','10 seconds',this,updateIdeaWordCount)">10s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','20','20 seconds',this,updateIdeaWordCount)">20s</button>
                    <button type="button" class="reel-dd-btn active" onclick="pickDD('idea_duration','30','30 seconds',this,updateIdeaWordCount)">30s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','40','40 seconds',this,updateIdeaWordCount)">40s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','50','50 seconds',this,updateIdeaWordCount)">50s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','60','60 seconds',this,updateIdeaWordCount)">60s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','70','70 seconds',this,updateIdeaWordCount)">70s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','80','80 seconds',this,updateIdeaWordCount)">80s</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_duration','90','90 seconds',this,updateIdeaWordCount)">90s</button>
                </div>
                <input type="hidden" id="idea_duration" value="30">
                <small style="color:#64748b;margin-top:4px;font-size:12px;display:block;">Approx. <span id="idea_word_count">75</span> words (2.5 words/second)</small>
                <?php endif; ?>
            </div>

            <!-- Video Format -->
            <div class="form-group dd-wrap" id="idea_fmt_dd_wrap">
                <?php if ($is_free_trial): ?>
                <div class="dd-trigger" style="opacity:0.7;cursor:not-allowed;">
                    <span>📐 Format: <strong>9×16 (Vertical)</strong></span>
                    <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                </div>
                <input type="hidden" id="idea_format" value="9x16">
                <?php else: ?>
                <div class="dd-trigger" id="idea_fmt_trigger" onclick="toggleDD('idea_fmt')">
                    <span>📐 Format: <strong id="idea_fmt_label">9×16 (Vertical)</strong></span>
                    <span class="dd-arrow" id="idea_fmt_arrow">▾</span>
                </div>
                <div class="dd-panel" id="idea_fmt_panel">
                    <button type="button" class="reel-dd-btn active" onclick="pickDD('idea_format','9x16','9×16 (Vertical)',this)">9×16 Vertical</button>
                    <button type="button" class="reel-dd-btn" onclick="pickDD('idea_format','16x9','16×9 (Landscape)',this)">16×9 Landscape</button>
                </div>
                <input type="hidden" id="idea_format" value="9x16">
                <?php endif; ?>
            </div>

        </div><!-- /.stg-panel -->
    </div><!-- /.stg-wrap -->
    
    <!-- Podcast Voice Info -->
    <div id="idea_podcast_info" class="podcast-voice-info" style="display: none;">
        <span class="icon">🎙️</span>
        <span>Podcast format: Lines starting with <strong>Host:</strong> and <strong>Guest:</strong> will be automatically detected</span>
    </div>

    <!-- Sub-tabs: Generate by AI / Generate Content Bank -->
    <div class="idea-subtabs">
        <button type="button" class="idea-subtab active" id="subtab_ai_btn" onclick="switchIdeaSubtab('ai')">✨ Generate by AI</button>
        <button type="button" class="idea-subtab" id="subtab_bank_btn" onclick="switchIdeaSubtab('bank')">📚 Content Bank</button>
    </div>

    <!-- SUB-TAB 1: Generate by AI -->
    <div id="idea_subtab_ai">
        <div class="form-group">
            <textarea id="idea_topic_ai" rows="3" placeholder="Enter your topic, key points, writing style and any other instructions to generate the script for you..." style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-size:14px; resize:vertical; line-height:1.5;"></textarea>
        </div>
        <div class="button-group">
            <button class="btn btn-primary" id="ideaProcessBtn" onclick="processIdeaContent()">📝 Step 1: Generate Content</button>
        </div>
    </div>

    <!-- SUB-TAB 2: Content Bank -->
    <div id="idea_subtab_bank" style="display:none;">

        <!-- Niche Selection -->
        <?php if ($has_user_niches): ?>
        <div class="form-group">
            <label>📋 Your Niche/Profession</label>
            <div class="dropdown-with-delete" id="niche_select_wrap">
                <select id="niche_select" onchange="onNicheChange(this.value)">
                    <option value="">-- Select a niche --</option>
                    <?php foreach ($user_niches as $niche):
                        $selected = ($niche['id'] == $last_niche_id) ? 'selected' : '';
                    ?>
                        <option value="<?php echo $niche['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($niche['niche_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="niche-action-btn niche-add-btn" onclick="addNiche()" title="Add new niche">＋</button>
                <button type="button" class="niche-action-btn niche-del-btn" onclick="deleteNiche()" title="Delete selected niche">－</button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Niche Input (shown when + clicked) -->
        <div id="new_niche_container" style="display:none;" class="form-group">
            <div style="display:flex;gap:8px;align-items:center;">
                <input type="text" id="new_niche_input" placeholder="e.g., Fitness, Mental Health..." style="flex:1;">
                <button type="button" class="btn btn-primary" style="padding:8px 14px;font-size:13px;" onclick="saveNewNiche()">Save</button>
                <button type="button" class="btn" style="padding:8px 14px;font-size:13px;background:#f1f5f9;" onclick="cancelAddNiche()">Cancel</button>
            </div>
        </div>

        <!-- Topic -->
        <div class="form-group">
            <div class="field-header">
                <label>📌 Topic of your video?</label>
                <button class="btn-sample" type="button" onclick="openTopicModal()" style="background: #8b5cf6;">
                    <span>🤖</span>AI Topic Ideas
                </button>
            </div>
            <?php if ($has_user_niches): ?>
                <div class="dropdown-with-delete" id="idea_topic_select_wrap">
                    <select id="idea_topic_select" class="voice-select" onchange="handleTopicSelection(this)">
                        <option value="">-- Select a topic --</option>
                        <?php
                        $first_topic = true;
                        foreach ($topics_by_niche as $niche_id => $niche_data):
                        ?>
                            <optgroup label="<?php echo htmlspecialchars($niche_data['niche_name']); ?>">
                                <?php foreach ($niche_data['topics'] as $topic):
                                    $selected = $first_topic ? 'selected' : '';
                                    $first_topic = false;
                                ?>
                                    <option value="<?php echo htmlspecialchars($topic); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($topic); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="niche-action-btn niche-add-btn" onclick="addTopic()" title="Add new topic">＋</button>
                    <button type="button" class="niche-action-btn niche-del-btn" onclick="deleteTopic()" title="Delete selected topic">－</button>
                </div>
                <div id="new_topic_container" style="display:none; margin-top:8px;">
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="text" id="new_topic_input" placeholder="e.g., Anxiety Relief..." style="flex:1;">
                        <button type="button" class="btn btn-primary" style="padding:8px 14px; font-size:13px;" onclick="saveNewTopic()">Save</button>
                        <button type="button" class="btn" style="padding:8px 14px; font-size:13px; background:#f1f5f9;" onclick="cancelAddTopic()">Cancel</button>
                    </div>
                </div>
                <input type="text" id="idea_topic_input" placeholder="Enter custom topic..." style="display:none; margin-top:10px;">
            <?php else: ?>
                <input type="text" id="idea_topic_input" placeholder="e.g., Stress Relief Techniques, Morning Motivation..." value="Stress Relief">
                <input type="hidden" id="idea_topic_select" value="">
            <?php endif; ?>
        </div>

        <!-- Title -->
        <div class="form-group">
            <div class="field-header">
                <label>📌 Title of your video</label>
                <button class="btn-sample" type="button" onclick="openTitleModal()" style="background: #8b5cf6;">
                    <span>🤖</span>AI Title Ideas
                </button>
            </div>
            <div class="dropdown-with-delete" id="idea_title_select_wrap">
                <select id="idea_title_select" class="voice-select" onchange="handleTitleSelection(this)" style="margin-bottom:0;">
                    <option value="">-- Select a title --</option>
                </select>
                <button type="button" class="niche-action-btn niche-add-btn" onclick="addTitle()" title="Add new title">＋</button>
                <button type="button" class="niche-action-btn niche-del-btn" onclick="deleteTitle()" title="Delete selected title">－</button>
            </div>
            <div id="new_title_container" style="display:none; margin-top:8px;">
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="text" id="new_title_input" placeholder="Enter title..." style="flex:1;">
                    <button type="button" class="btn btn-primary" style="padding:8px 14px; font-size:13px;" onclick="saveNewTitle()">Save</button>
                    <button type="button" class="btn" style="padding:8px 14px; font-size:13px; background:#f1f5f9;" onclick="cancelAddTitle()">Cancel</button>
                </div>
            </div>
            <input type="text" id="idea_title_input" placeholder="Enter custom title..." style="display:none; margin-top:5px;" value="">
        </div>

        <!-- Audience -->
        <div class="form-group">
            <label>👥 Target Audience</label>
            <select id="idea_audience">
                <option value="general">General Audience</option>
                <option value="students">Students</option>
                <option value="professionals">Professionals</option>
                <option value="stressed_people">People Feeling Stressed</option>
            </select>
        </div>

        <!-- CTA -->
        <div class="form-group">
            <label>📢 Call to Action</label>
            <input type="text" id="idea_cta" placeholder="e.g., Follow for more tips..." value="Follow for more stress relief tips">
        </div>

        <div class="button-group">
            <button class="btn btn-primary" id="ideaProcessBtnBank" onclick="processIdeaContent()">📝 Step 1: Generate Content</button>
        </div>
    </div>

    <!-- Processed Content Container (shared) -->
    <div id="idea_processed_container" style="display: none;">
        <div class="form-group">
            <label>📝<b> Your AI Draft is Ready</b><br>Edit the script for small tweaks, or change your inputs to generate a different direction</label>
            <textarea id="idea_processed_content" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
        </div>
    </div>

    <div class="button-group">
        <button class="btn btn-success" id="ideaCreateScenesBtn" onclick="showStep2Setup('idea')" style="display:none;">🎬 Step 2: Create Scenes</button>
    </div>
</div>
            
            <!-- My Content Tab -->
            <div id="my-content-tab" class="tab-content hidden">
<!-- Settings Toggle -->
                <div class="stg-wrap" id="content_stg_wrap">
                    <button type="button" class="stg-btn" onclick="toggleStg('content')">
                        ⚙️ Settings <span class="stg-chevron" id="content_stg_chevron">▾</span>
                    </button>
                    <div class="stg-panel" id="content_stg_panel">

                        <!-- Language -->
                        <div class="form-group dd-wrap" id="content_lang_dd_wrap">
                            <div class="dd-trigger" id="content_lang_trigger" onclick="toggleDD('content_lang')">
                                <span>🌐 Language: <strong id="content_lang_label">🇬🇧 English</strong></span>
                                <span class="dd-arrow" id="content_lang_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="content_lang_panel" style="max-height:200px;overflow-y:auto;flex-wrap:wrap;">
                                <?php
                                $lang_q4 = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order");
                                while ($l = mysqli_fetch_assoc($lang_q4)) {
                                    $active = ($l['language_code'] == 'en') ? ' active' : '';
                                    $label  = htmlspecialchars($l['flag_emoji'] . ' ' . $l['language']);
                                    echo "<button type=\"button\" class=\"reel-dd-btn{$active}\" onclick=\"pickLang('{$l['language_code']}','{$label}',this)\">{$label}</button>";
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Reel Type -->
                        <div class="form-group reel-dd-wrap" id="content_reel_dd_wrap">
                            <div class="reel-dd-trigger" onclick="toggleReelDD('content')">
                                <span>🎬 Reel Type: <strong id="content_reel_type_label">Standard</strong></span>
                                <span class="reel-dd-arrow" id="content_reel_dd_arrow">▾</span>
                            </div>
                            <div class="reel-dd-panel" id="content_reel_dd_panel">
                                <button type="button" class="reel-dd-btn active" onclick="pickReel('content','standard','Standard',this)">Standard</button>
                                <button type="button" class="reel-dd-btn" onclick="pickReel('content','broll','B-Roll',this)">B-Roll</button>
                                <button type="button" class="reel-dd-btn" onclick="pickReel('content','podcast','Podcast',this)">Podcast</button>
                            </div>
                            <input type="hidden" id="content_reel_type" value="standard">
                        </div>

                        <!-- Video Duration -->
                        <div class="form-group dd-wrap" id="content_dur_dd_wrap">
                            <?php if ($is_free_trial): ?>
                            <div class="dd-trigger" style="opacity:0.7;cursor:not-allowed;">
                                <span>⏱️ Duration: <strong>30 seconds</strong></span>
                                <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                            </div>
                            <input type="hidden" id="content_duration" value="30">
                            <?php else: ?>
                            <div class="dd-trigger" id="content_dur_trigger" onclick="toggleDD('content_dur')">
                                <span>⏱️ Duration: <strong id="content_dur_label">30 seconds</strong></span>
                                <span class="dd-arrow" id="content_dur_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="content_dur_panel">
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','10','10 seconds',this,updateContentWordCount)">10s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','20','20 seconds',this,updateContentWordCount)">20s</button>
                                <button type="button" class="reel-dd-btn active" onclick="pickDD('content_duration','30','30 seconds',this,updateContentWordCount)">30s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','40','40 seconds',this,updateContentWordCount)">40s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','50','50 seconds',this,updateContentWordCount)">50s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','60','60 seconds',this,updateContentWordCount)">60s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','70','70 seconds',this,updateContentWordCount)">70s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','80','80 seconds',this,updateContentWordCount)">80s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_duration','90','90 seconds',this,updateContentWordCount)">90s</button>
                            </div>
                            <input type="hidden" id="content_duration" value="30">
                            <small style="color:#64748b;margin-top:4px;font-size:12px;display:block;">Approx. <span id="content_word_count">75</span> words</small>
                            <?php endif; ?>
                        </div>

                        <!-- Video Format -->
                        <div class="form-group dd-wrap" id="content_fmt_dd_wrap">
                            <?php if ($is_free_trial): ?>
                            <div class="dd-trigger" style="opacity:0.7;cursor:not-allowed;">
                                <span>📐 Format: <strong>9×16 (Vertical)</strong></span>
                                <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                            </div>
                            <input type="hidden" id="content_format" value="9x16">
                            <?php else: ?>
                            <div class="dd-trigger" id="content_fmt_trigger" onclick="toggleDD('content_fmt')">
                                <span>📐 Format: <strong id="content_fmt_label">9×16 (Vertical)</strong></span>
                                <span class="dd-arrow" id="content_fmt_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="content_fmt_panel">
                                <button type="button" class="reel-dd-btn active" onclick="pickDD('content_format','9x16','9×16 (Vertical)',this)">9×16 Vertical</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('content_format','16x9','16×9 (Landscape)',this)">16×9 Landscape</button>
                            </div>
                            <input type="hidden" id="content_format" value="9x16">
                            <?php endif; ?>
                        </div>

                    </div><!-- /.stg-panel -->
                </div><!-- /.stg-wrap -->
				
				
                <!-- Podcast Voice Info -->
                <div id="content_podcast_info" class="podcast-voice-info" style="display: none;">
                    <span class="icon">🎙️</span>
                    <span>Make sure your script has lines starting with <strong>Host:</strong> and <strong>Guest:</strong></span>
                </div>
                
                <!-- Title -->
                <div class="form-group">
                    <label>📌 Title</label>
                    <input type="text" id="content_title" placeholder="Enter a title for your video..." value="My Custom Video">
                </div>
                
                <!-- Content Input -->
                <div class="form-group">
                    <label>📝 Your Script / Story</label>
                    <textarea id="content_story" rows="8" placeholder="Paste your script here..." style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
                </div>
                
                <!-- Processed Content Container -->
                <div id="content_processed_container" style="display: none;">
                    <div class="form-group">
                        <label>📝 Processed Content</label>
                        <textarea id="content_processed_content" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
                    </div>
                    
                    <!-- CTA -->
                    <div class="form-group">
                        <label>📢 Call to Action</label>
                        <input type="text" id="content_cta" placeholder="e.g., Follow for more tips..." value="Follow for more daily tips">
                    </div>
                    
                    <!-- Step 2 Button -->
                    <div class="button-group">
                        <button class="btn btn-success" id="contentCreateScenesBtn" onclick="showStep2Setup('content')" style="width:100%;">🎬 Step 2: Build Video Scenes</button>
                    </div>
                </div>
                
                <!-- Step 1 Button -->
                <div class="button-group">
                    <button class="btn btn-primary" id="contentProcessBtn" onclick="processContent()" style="width:100%;">📝 Step 1: Process Content</button>
                </div>
            </div>
            
            <!-- Database Tab -->
            <div id="database-tab" class="tab-content hidden">
<!-- Settings Toggle -->
                <div class="stg-wrap" id="db_stg_wrap">
                    <button type="button" class="stg-btn" onclick="toggleStg('db')">
                        ⚙️ Settings <span class="stg-chevron" id="db_stg_chevron">▾</span>
                    </button>
                    <div class="stg-panel" id="db_stg_panel">

                        <!-- Language -->
                        <div class="form-group dd-wrap" id="db_lang_dd_wrap">
                            <div class="dd-trigger" id="db_lang_trigger" onclick="toggleDD('db_lang')">
                                <span>🌐 Language: <strong id="db_lang_label">🇬🇧 English</strong></span>
                                <span class="dd-arrow" id="db_lang_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="db_lang_panel" style="max-height:200px;overflow-y:auto;flex-wrap:wrap;">
                                <?php
                                $lang_q5 = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order");
                                while ($l = mysqli_fetch_assoc($lang_q5)) {
                                    $active = ($l['language_code'] == 'en') ? ' active' : '';
                                    $label  = htmlspecialchars($l['flag_emoji'] . ' ' . $l['language']);
                                    echo "<button type=\"button\" class=\"reel-dd-btn{$active}\" onclick=\"pickLang('{$l['language_code']}','{$label}',this)\">{$label}</button>";
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Reel Type -->
                        <div class="form-group reel-dd-wrap" id="db_reel_dd_wrap">
                            <div class="reel-dd-trigger" onclick="toggleReelDD('db')">
                                <span>🎬 Reel Type: <strong id="db_reel_type_label">Standard</strong></span>
                                <span class="reel-dd-arrow" id="db_reel_dd_arrow">▾</span>
                            </div>
                            <div class="reel-dd-panel" id="db_reel_dd_panel">
                                <button type="button" class="reel-dd-btn active" onclick="pickReel('db','standard','Standard',this)">Standard</button>
                                <button type="button" class="reel-dd-btn" onclick="pickReel('db','broll','B-Roll',this)">B-Roll</button>
                                <button type="button" class="reel-dd-btn" onclick="pickReel('db','podcast','Podcast',this)">Podcast</button>
                            </div>
                            <input type="hidden" id="db_reel_type" value="standard">
                        </div>

                        <!-- Video Duration -->
                        <div class="form-group dd-wrap" id="db_dur_dd_wrap">
                            <?php if ($is_free_trial): ?>
                            <div class="dd-trigger" style="opacity:0.7;cursor:not-allowed;">
                                <span>⏱️ Duration: <strong>30 seconds</strong></span>
                                <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                            </div>
                            <input type="hidden" id="db_duration" value="30">
                            <small style="color:#64748b;margin-top:4px;font-size:12px;display:block;">Upgrade to Pro for longer videos</small>
                            <?php else: ?>
                            <div class="dd-trigger" id="db_dur_trigger" onclick="toggleDD('db_dur')">
                                <span>⏱️ Duration: <strong id="db_dur_label">30 seconds</strong></span>
                                <span class="dd-arrow" id="db_dur_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="db_dur_panel">
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','10','10 seconds',this,updateDbWordCount)">10s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','20','20 seconds',this,updateDbWordCount)">20s</button>
                                <button type="button" class="reel-dd-btn active" onclick="pickDD('db_duration','30','30 seconds',this,updateDbWordCount)">30s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','40','40 seconds',this,updateDbWordCount)">40s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','50','50 seconds',this,updateDbWordCount)">50s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','60','60 seconds',this,updateDbWordCount)">60s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','70','70 seconds',this,updateDbWordCount)">70s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','80','80 seconds',this,updateDbWordCount)">80s</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_duration','90','90 seconds',this,updateDbWordCount)">90s</button>
                            </div>
                            <input type="hidden" id="db_duration" value="30">
                            <small style="color:#64748b;margin-top:4px;font-size:12px;display:block;">Approx. <span id="db_word_count">75</span> words (2.5 words/second)</small>
                            <?php endif; ?>
                        </div>

                        <!-- Video Format -->
                        <div class="form-group dd-wrap" id="db_fmt_dd_wrap">
                            <?php if ($is_free_trial): ?>
                            <div class="dd-trigger" style="opacity:0.7;cursor:not-allowed;">
                                <span>📐 Format: <strong>9×16 (Vertical)</strong></span>
                                <span style="font-size:11px;background:#f59e0b;color:white;padding:2px 8px;border-radius:20px;">Free Trial</span>
                            </div>
                            <input type="hidden" id="db_format" value="9x16">
                            <?php else: ?>
                            <div class="dd-trigger" id="db_fmt_trigger" onclick="toggleDD('db_fmt')">
                                <span>📐 Format: <strong id="db_fmt_label">9×16 (Vertical)</strong></span>
                                <span class="dd-arrow" id="db_fmt_arrow">▾</span>
                            </div>
                            <div class="dd-panel" id="db_fmt_panel">
                                <button type="button" class="reel-dd-btn active" onclick="pickDD('db_format','9x16','9×16 (Vertical)',this)">9×16 Vertical</button>
                                <button type="button" class="reel-dd-btn" onclick="pickDD('db_format','16x9','16×9 (Landscape)',this)">16×9 Landscape</button>
                            </div>
                            <input type="hidden" id="db_format" value="9x16">
                            <?php endif; ?>
                        </div>

                    </div><!-- /.stg-panel -->
                </div><!-- /.stg-wrap -->
                
                <!-- Podcast Voice Info -->
                <div id="db_podcast_info" class="podcast-voice-info" style="display: none;">
                    <span class="icon">🎙️</span>
                    <span>Podcast format: Script will be formatted with Host and Guest voices</span>
                </div>
                
                <!-- Niche -->
                <div class="form-group">
                    <label>📋 Niche</label>
                    <select id="niche_select_db" onchange="loadTopicsForNiche(this.value)">
                        <option value="">-- Select Niche --</option>
                        <?php foreach ($user_niches as $niche): ?>
                        <option value="<?= (int)$niche['id'] ?>"><?= htmlspecialchars($niche['niche_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Topic -->
                <div class="form-group">
                    <label>📌 Topic</label>
                    <select id="topic_select" onchange="loadTitlesForTopic(this.value)">
                        <option value="">-- Select Topic --</option>
                    </select>
                </div>
                
                <!-- Title -->
                <div class="form-group">
                    <label>📌 Title</label>
                    <select id="sm_id_select" onchange="updatePrompt()">
                        <option value="">-- Select Title --</option>
                    </select>
                </div>
                
                <!-- CTA -->
                <div class="form-group">
                    <label>📢 CTA</label>
                    <input type="text" id="db_cta" placeholder="Enter CTA..." value="Follow for more tips">
                </div>
                
                <!-- Editable Prompt -->
                <div class="form-group">
                    <label>✏️ Editable Prompt</label>
                    <textarea id="editable_prompt" class="prompt-editor" rows="6" placeholder="Customize your prompt here..."></textarea>
                </div>
                
                <!-- Generated Content -->
                <div class="form-group">
                    <label>📝 Generated Content</label>
                    <textarea id="generated_content" rows="8" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
                </div>
                
                <!-- Buttons -->
                <div class="button-group">
                    <button class="btn btn-primary" id="generateContentBtn" onclick="generateContentOnly()">📝 Step 1: Generate Script</button>
                    <button class="btn btn-success" id="createScenesBtn" onclick="showStep2Setup('db')" style="display: none;">🎬 Step 2: Build Video Scenes</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Progress Container -->
    <!-- Progress Container - Only show logs for super admin -->
<div class="progress-container" id="progress_container">
    <h4 style="margin:0 0 12px; color:var(--dark-blue);">📊 Real-Time Progress</h4>
    <div class="progress-bar-bg">
        <div class="progress-bar-fill" id="progress_bar"></div>
    </div>
    <div class="progress-text" id="progress_text">Initializing...</div>
    <?php if ($_SESSION['level'] === 'super'): ?>
    <div class="realtime-log" id="realtime_log">
        <div class="log-entry">[System] Ready to start...</div>
    </div>
    <?php else: ?>
    <!-- Hidden for non-super users but keep the element for JavaScript -->
    <div class="realtime-log" id="realtime_log" style="display: none;"></div>
    <?php endif; ?>
</div>
        </div><!-- /#wizardStep2 -->

        </div><!-- /.card-body -->
    </div><!-- /.card -->
    </div><!-- /#mainScriptCard -->


    
    <!-- Status Message -->
    <div id="status_msg" style="display:none;"></div>
</div>

<!-- Image Selector Modal -->
<div class="image-selector-modal" id="imageSelectorModal">
    <div class="image-selector-content">
        <div class="image-selector-header">
            <h3>Select Image for Scene <span id="imageSelectorSceneId"></span></h3>
            <button class="close-btn" onclick="closeImageSelector()">&times;</button>
        </div>
        <div id="imageSearchTerms" style="margin-bottom: 16px; padding: 10px; background: #f1f5f9; border-radius: 8px;">
            Searching for: <span id="imageHashtags"></span>
        </div>
        <div class="image-grid" id="imageGrid"></div>
        <div style="text-align: center; padding: 20px;">
            <button class="btn btn-secondary" onclick="closeImageSelector()">Cancel</button>
        </div>
    </div>
</div>

<!-- Hidden audio element for samples -->
<audio id="sampleAudioPlayer" style="display:none;"></audio>

<!-- Footer -->
<footer class="site-footer">
    <div class="footer-brand">🎬 VideoVizard</div>
    <div class="footer-links">
        <a href="vidora_home.php">Home</a>
        <a href="profile.php">Profile</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
    </div>
    <div>© <?= date('Y') ?> VideoVizard</div>
</footer>

<!-- Processing Modal for Step 1 (Content Generation) -->
<div class="modal-overlay" id="processingModal" style="display: none;">
    <div class="success-card" style="max-width: 500px; text-align: center;">
        <div style="margin-bottom: 20px;">
            <div class="loading-spinner" style="width: 60px; height: 60px; border: 6px solid #e2e8f0; border-top: 6px solid #8b5cf6; border-radius: 50%; margin: 0 auto; animation: spin 1s linear infinite;"></div>
        </div>
        
        <h3 style="color: var(--dark-blue); margin-bottom: 15px;" id="processingTitle">Generating Content</h3>
        
        <p style="color: var(--muted); margin-bottom: 20px; font-size: 14px;" id="processingMessage">
            Please wait while AI creates your script...<br>
            This may take 30-60 seconds.
        </p>
        
        <div style="background: #f1f5f9; border-radius: 20px; height: 8px; width: 100%; margin: 20px 0; overflow: hidden;">
            <div id="processingProgressBar" style="background: linear-gradient(90deg, #8b5cf6, #6366f1); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
        </div>
        
        <div style="color: #94a3b8; font-size: 12px; margin-top: 10px;">
            <span id="processingTime">⏱️ This may take up to 60 seconds</span>
        </div>
        
        <div style="margin-top: 25px;">
            <button class="btn btn-secondary" onclick="cancelProcessing()" style="width: 100%;" id="cancelProcessingBtn">Cancel</button>
        </div>
    </div>
</div>



<!-- Progress Modal for Step 2 (Scene Creation) -->
<div class="modal-overlay" id="progressModal" style="display:none;">
    <div style="background:white; border-radius:20px; width:94%; max-width:560px; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#0f2a44,#1e3a5f); padding:16px 20px; flex-shrink:0;">
            <div style="color:white; font-size:16px; font-weight:700;">🎬 Creating Your Video</div>
        </div>

        <!-- Steps -->
        <div style="padding:16px 20px; flex-shrink:0; border-bottom:1px solid #f1f5f9;">
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach([1=>'Creating scenes',2=>'Generating audio',3=>'Assigning media'] as $n=>$label): ?>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div id="step<?=$n?>Icon" style="width:28px; height:28px; border-radius:50%; background:#e2e8f0; display:flex; align-items:center; justify-content:center; font-size:13px; color:white; font-weight:700; flex-shrink:0;"><?=$n?></div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-weight:600; color:#0f2a44; font-size:13px;" id="step<?=$n?>Text"><?=$label?></div>
                        <div style="font-size:11px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" id="step<?=$n?>Detail">Waiting...</div>
                    </div>
                    <div id="step<?=$n?>Status" style="font-size:18px; flex-shrink:0;">⏳</div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Progress bar -->
            <div style="background:#f1f5f9; border-radius:20px; height:6px; margin-top:14px; overflow:hidden;">
                <div id="overallProgressBar" style="background:linear-gradient(90deg,#8b5cf6,#6366f1); height:100%; width:0%; transition:width 0.3s;"></div>
            </div>
        </div>

        <!-- Live Activity Feed -->
        <div style="padding:10px 16px 4px; flex-shrink:0;">
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Live Activity</div>
        </div>
        <div id="progressLogFeed" style="flex:1; overflow-y:auto; padding:0 16px 8px; font-size:12px; font-family:monospace; min-height:120px; max-height:260px;">
            <!-- log entries inserted here -->
        </div>

        <!-- Footer -->
        <div style="padding:12px 16px; border-top:1px solid #f1f5f9; flex-shrink:0; display:flex; align-items:center; justify-content:space-between;">
            <span id="sceneProgress" style="font-size:12px; color:#64748b;">Scene 0/0</span>
            <button class="btn btn-secondary" onclick="cancelSceneCreation()" id="cancelSceneBtn" style="padding:8px 16px; font-size:13px;">Cancel</button>
        </div>

    </div>
</div>
<script>
function clearLogs() {
    if (confirm('Clear all logs? They will be permanently deleted.')) {
        document.getElementById('realtime_log').innerHTML = '<div class="log-entry">[System] Logs cleared at ' + new Date().toLocaleTimeString() + '</div>';
        updateLogCount();
    }
}

function copyLogs() {
    const logText = Array.from(document.getElementById('realtime_log').children)
        .map(entry => entry.innerText)
        .join('\n');
    navigator.clipboard.writeText(logText).then(() => {
        alert('Logs copied to clipboard!');
    });
}

function updateLogCount() {
    const log = document.getElementById('realtime_log');
    const countEl = document.getElementById('logCount');
    if (!log || !countEl) return;
    countEl.textContent = log.children.length;
}

// Update log count every second
setInterval(updateLogCount, 1000);
</script>



<script>







// ========== GLOBAL VARIABLES ==========
let voicesByLang = <?php echo json_encode($voices_by_lang); ?>;
let currentPodcastId = null;
let isFreeTrial = <?php echo $is_free_trial ? 'true' : 'false'; ?>;
let currentImageSelectorSceneId = null;
let currentImageSelectorHashtags = '';

// ========== COMMON PROMPT INSTRUCTIONS ==========
const COMMON_INSTRUCTIONS = {
    // IMAGE PROMPT REQUIREMENTS - Used for every scene
    imagePrompt: `**IMAGE PROMPT REQUIREMENTS (30-50 words per scene):**
For EACH scene, you MUST include ALL these elements in the PROMPT:
- Main subject/scene description (who/what is in the scene)
- Background setting (where does it take place)
- Color palette (warm/cool/soft/vibrant/muted)
- Lighting (soft light/dramatic/golden hour/natural/studio)
- Facial expressions/emotions if people are present
- Mood/atmosphere (peaceful/tense/hopeful/melancholic)
- Style (photorealistic/artistic/minimalist/cinematic)
- Camera angle/perspective (close-up/wide shot/aerial/eye-level)`,

    // HASHTAG REQUIREMENTS - Used for image matching
    hashtags: `**HASHTAG REQUIREMENTS:**
Generate 3-5 keyword tags WITHOUT the # symbol, separated by spaces.
Use these EXACT patterns for image library matching:

EMOTION + PERSON patterns:
- 'sadwoman', 'worriedman', 'happycouple', 'calmwoman', 'stressedman'
- 'peacefulwoman', 'anxiousman', 'hopefulwoman', 'lonelyman', 'excitedcrowd'

ACTION patterns:
- 'peopletalking', 'peoplewalking', 'peoplemeditating', 'peopleexercising'
- 'peopleworking', 'peopleeating', 'peoplecelebrating', 'peoplesleeping'

SCENE patterns:
- 'nature', 'beach', 'mountains', 'sunset', 'sunrise', 'forest', 'ocean'
- 'cityscape', 'office', 'home', 'livingroom', 'bedroom', 'kitchen', 'park'
- 'cafe', 'restaurant', 'gym', 'yogastudio', 'classroom'

OBJECT patterns:
- 'books', 'laptop', 'coffee', 'phone', 'camera', 'pillow', 'couch', 'table'
- 'lamp', 'window', 'plant', 'flowers', 'computer', 'notebook', 'pen'`,

    // OUTPUT FORMAT - The structure every response must follow
    outputFormat: `**CRITICAL OUTPUT FORMAT - YOU MUST FOLLOW THIS EXACT STRUCTURE:**

For each scene, you MUST provide FOUR things separated by [SCENE] markers:

[SCENE]
TEXT: [The spoken text for this scene - one sentence only]
PROMPT: [Detailed visual description for AI image generation - 30-50 words]
HASHTAGS: [space-separated keyword tags for internal image library search]
NL_TAGS: [5-8 natural language search phrases for stock media APIs, pipe-separated]

EXAMPLE:
[SCENE]
TEXT: Do you ever feel overwhelmed by stress?<break time="250ms"/>
PROMPT: A young woman sits alone in a cozy living room, bathed in soft, warm afternoon light streaming through sheer curtains. Her expression shows visible stress - furrowed brows, hands clasped tightly. The room has comfortable furniture in beige and cream tones. The mood is contemplative and slightly melancholic, captured from a medium shot angle.
HASHTAGS: stressedwoman livingroom contemplation
NL_TAGS: stressed woman sitting alone at home|woman feeling overwhelmed in living room|anxious woman on couch indoors|woman looking worried in cozy room|woman dealing with stress alone`,

    // TEXT RULES - How to format the spoken content
    textRules: `**TEXT REQUIREMENTS:**
1. Each TEXT line must be a complete sentence (5-10 words)
2. After EVERY TEXT line, add <break time="250ms"/> 
3. Each TEXT line MUST be followed by a newline character
4. DO NOT include the title in the output
5. DO NOT use any markdown formatting (*, **, #, etc.)`,

    // ABSOLUTE RULES - Non-negotiable rules
    absoluteRules: `**ABSOLUTE RULES - YOU MUST FOLLOW THESE EXACTLY:**
1. OUTPUT ONLY content with [SCENE] markers - NO explanations, NO comments
2. DO NOT add scene numbers - the [SCENE] marker is enough
3. EVERY scene MUST have TEXT, PROMPT, HASHTAGS, and NL_TAGS
4. TEXT must include <break time="250ms"/> at the end
5. PROMPT must be detailed (30-50 words) following the image prompt requirements
6. HASHTAGS must follow the patterns above for image matching
7. NL_TAGS must be 5-8 natural English phrases, pipe-separated, describing who/what/where suitable for Pexels or stock video search`
};

// Fixed buildGenerationPrompt function - changed const to let for reassigned variables
function buildGenerationPrompt(params) {
    const {
        title,
        topic,
        userInstructions = '',
        audience,
        cta,
        langName,
        reelType,
        duration,
        source = 'idea',
        originalContent = null,
        hookType = null // Add hook type parameter
    } = params;
    
    // Calculate target word count
    const targetWords = Math.round(duration * 2.5);
    const minWords = targetWords - 5;
    const maxWords = targetWords + 5;
    
    // Define hook types with examples
    const hookExamples = {
        'question': '❓ Question Hook: "Do you know the 5 ways to reduce stress?" or "Want to feel calmer in 5 minutes?"',
        'pattern-interrupt': '⏸️ Pattern Interrupt: "Stop scrolling! Your mental health needs this." or "Wait! Before you keep scrolling..."',
        'statistic': '📊 Statistic Hook: "77% of people feel stressed daily. Here\'s how to beat it." or "Studies show 5 minutes of this reduces stress by 50%."',
        'story': '📖 Story Hook: "I used to lie awake at 3am stressing. Then I discovered this." or "Last year, I was burned out. Here\'s what changed."',
        'myth': '❌ Myth Buster: "Think stress is bad? Actually, it\'s how you handle it that matters."',
        'prediction': '🔮 Prediction Hook: "What if I told you that 5 simple habits could eliminate your stress?"',
        'identity': '👤 Identity Hook: "For anyone who feels overwhelmed by stress..."'
    };
    
    // Select a hook type if not provided
    // FIX: Changed from const to let since we're reassigning hookType
    let selectedHookType = hookType;
    if (!selectedHookType) {
        const hookKeys = Object.keys(hookExamples);
        selectedHookType = hookKeys[Math.floor(Math.random() * hookKeys.length)];
    }
    
    const selectedHook = hookExamples[selectedHookType] || hookExamples['question'];
    
    // Scene splitting rules (vary by reel type)
    // FIX: Changed from const to let since we're conditionally assigning
    let sceneSplittingRules = '';
    if (reelType === 'broll') {
        sceneSplittingRules = `**B-ROLL MODE:**
- Create ONE scene only with continuous text (${duration} seconds total)
- Target word count: ${targetWords} words (${minWords}-${maxWords})
- Combine all content into a single [SCENE] block
- The TEXT can be multiple sentences in one paragraph`;
    } else if (reelType === 'podcast') {
        sceneSplittingRules = `**PODCAST MODE:**
- Each speaker turn is a separate scene
- Total duration: ${duration} seconds (${targetWords} words)
- Alternate between Host and Guest lines
- Each line must start with "Host:" or "Guest:"`;
    } else {
        sceneSplittingRules = `**STANDARD MODE:**
- Each sentence is a separate scene
- Total duration: ${duration} seconds (${targetWords} words)
- Break at every natural pause (periods, question marks, exclamations)
- Aim for ${Math.ceil(targetWords/10)}-${Math.ceil(targetWords/5)} scenes`;
    }
    
    // Build the complete prompt using common instructions
    let prompt;

    if (userInstructions) {
        // AI subtab: user wrote their own instructions — follow them directly, no forced hook
        prompt = `You are an expert video script writer. Create a ${duration}-second ${reelType} video script.

USER INSTRUCTIONS:
${userInstructions}

LANGUAGE: ${langName}
REEL TYPE: ${reelType}
DURATION: ${duration} seconds (target: ${targetWords} words)

${sceneSplittingRules}

${COMMON_INSTRUCTIONS.textRules}

${COMMON_INSTRUCTIONS.imagePrompt}

${COMMON_INSTRUCTIONS.hashtags}

${COMMON_INSTRUCTIONS.outputFormat}

${COMMON_INSTRUCTIONS.absoluteRules}

BEGIN GENERATION NOW:`;
    } else {
        // Content Bank subtab: use hook + title approach
        prompt = `You are an expert content writer and visual storyteller. Create a ${duration}-second ${reelType} video script.

TITLE: ${title}
${topic ? `TOPIC: ${topic}` : ''}
${audience ? `TARGET AUDIENCE: ${audience}` : ''}
LANGUAGE: ${langName}
REEL TYPE: ${reelType}
DURATION: ${duration} seconds (target: ${targetWords} words)
CTA: ${cta}
${originalContent ? `\nORIGINAL CONTENT TO FORMAT:\n${originalContent}\n` : ''}

**HOOK TYPE TO USE:**
${selectedHook}

**IMPORTANT: THE TITLE MUST BE INCORPORATED NATURALLY**
Your title is "${title}". You MUST weave this title into the script naturally. For example:
- If title is "5 Ways to Reduce Stress", the script should explicitly mention or count down the 5 ways
- If title is "Morning Motivation", the script should focus on morning routines
- The title should appear in the opening hook or be the central theme

${sceneSplittingRules}

${COMMON_INSTRUCTIONS.textRules}

${COMMON_INSTRUCTIONS.imagePrompt}

${COMMON_INSTRUCTIONS.hashtags}

${COMMON_INSTRUCTIONS.outputFormat}

${COMMON_INSTRUCTIONS.absoluteRules}

BEGIN GENERATION NOW:`;
    }

    return prompt;
}
// ========== INITIALIZATION ==========
// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    // Load voices and settings
    loadVoicesForLanguage('en');
    loadVoiceSettings();
    initTabs();
    // Load user settings
    loadUserSettings();
    // Add trash icons to dropdowns after a short delay
    setTimeout(addTrashIconsToDropdowns, 1000);

    console.log('✅ DOM fully loaded and initialized');
});
// ========== SAVE USER SETTINGS ==========
async function saveUserSettings(settings) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_user_settings');
        
        // Add all settings to form data
        Object.keys(settings).forEach(key => {
            fd.append(key, settings[key]);
        });
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const data = await response.json();
        
        if (data.success) {
            console.log('✅ User settings saved');
            return true;
        } else {
            console.error('Failed to save user settings:', data.message);
            return false;
        }
    } catch (err) {
        console.error('Error saving user settings:', err);
        return false;
    }
}
// ===== WIZARD FUNCTIONS =====
let currentWizardOption = null;

const WIZARD_CONFIG = {
    'ai': {
        tab: 'my-idea',
        subtab: 'ai',
        label: '✨ AI Generate Script — Give instructions, AI writes the script',
        color: '#8b5cf6',
        headerTitle: '✨ AI Generate Script',
        headerSub: 'Describe your topic — AI writes the full script for you'
    },
    'bank': {
        tab: 'my-idea',
        subtab: 'bank',
        label: '📚 Content Bank — Pick from your saved niche, topic & title library',
        color: '#10b981',
        headerTitle: '📚 AI Generate Content Bank',
        headerSub: 'Pick a niche, let AI generate topic and title — builds the script'
    },
    'content': {
        tab: 'my-content',
        subtab: null,
        label: '📄 I Have Content — Paste your text, split into scenes automatically',
        color: '#3b82f6',
        headerTitle: '📄 Use Your Content',
        headerSub: 'Paste your text — it gets split into scenes automatically'
    }
};

function selectWizardOption(type) {
    currentWizardOption = type;
    const cfg = WIZARD_CONFIG[type];

    // Update card header dynamically
    const hTitle = document.getElementById('cardHeaderTitle');
    const hSub   = document.getElementById('cardHeaderSubtitle');
    if (hTitle) hTitle.textContent = cfg.headerTitle;
    if (hSub)   hSub.textContent   = cfg.headerSub;

    // Highlight selected card
    document.querySelectorAll('.wizard-option').forEach(el => el.classList.remove('selected'));
    document.getElementById('wiz_' + (type === 'content' ? 'content' : type)).classList.add('selected');

    // Update label
    const label = document.getElementById('wizardSelectedLabel');
    label.textContent = cfg.label;
    label.style.borderLeftColor = cfg.color;

    // Show Step 2, hide Step 1
    document.getElementById('wizardStep1').style.display = 'none';
    document.getElementById('wizardStep2').style.display = 'block';

    // Switch to correct tab
    switchTab(cfg.tab);

    // Switch subtab if needed and hide the subtab bar (wizard already chose)
    if (cfg.subtab) {
        switchIdeaSubtab(cfg.subtab);
        const subtabBar = document.querySelector('.idea-subtabs');
        if (subtabBar) subtabBar.style.display = 'none';
    } else {
        // Make sure subtab bar is visible for non-idea tabs
        const subtabBar = document.querySelector('.idea-subtabs');
        if (subtabBar) subtabBar.style.display = '';
    }

    // Scroll to top of wizard
    document.getElementById('wizardStep2').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function resetWizard() {
    currentWizardOption = null;

    // Reset card header
    const hTitle = document.getElementById('cardHeaderTitle');
    const hSub   = document.getElementById('cardHeaderSubtitle');
    if (hTitle) hTitle.textContent = '📋 Script Generator';
    if (hSub)   hSub.textContent   = 'Choose how you want to create your script';

    // Restore subtab bar visibility
    const subtabBar = document.querySelector('.idea-subtabs');
    if (subtabBar) subtabBar.style.display = '';

    // Show Step 1, hide Step 2
    document.getElementById('wizardStep1').style.display = 'block';
    document.getElementById('wizardStep2').style.display = 'none';

    // Hide all tab content — clean slate
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));

    // Remove selected highlight
    document.querySelectorAll('.wizard-option').forEach(el => el.classList.remove('selected'));

    // Scroll to top
    document.getElementById('wizardStep1').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function initTabs() {
    // Hide all tab content — wizard step 1 is shown first
    document.querySelectorAll('.tab-content').forEach(t => t.classList.add('hidden'));
    
    // Restore from URL param if returning after save
    const urlParams = new URLSearchParams(window.location.search);
    const subtab = urlParams.get('subtab');
    if (subtab === 'bank') {
        selectWizardOption('bank');
        const clean = new URL(window.location.href);
        clean.searchParams.delete('subtab');
        history.replaceState({}, '', clean.toString());
    }
}

function switchTab(tab) {
    // Tab bar removed in wizard mode — guard against missing elements
    const tabEl = document.getElementById(`tab-${tab}`);
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    if (tabEl) tabEl.classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    const tabContent = document.getElementById(`${tab}-tab`);
    if (tabContent) tabContent.classList.remove('hidden');
    
    // Update voice containers based on reel type in active tab
    if (tab === 'my-idea') {
        checkPodcastType('idea');
        setTimeout(addTrashIconsToDropdowns, 500);
    } else if (tab === 'my-content') {
        checkPodcastType('content');
    } else if (tab === 'database') {
        checkPodcastType('db');
        setTimeout(addTrashIconsToDropdowns, 500);
        const nicheSelDb = document.getElementById('niche_select_db');
        if (nicheSelDb && nicheSelDb.options.length > 1 && !nicheSelDb.value) {
            nicheSelDb.selectedIndex = 1;
            loadTopicsForNiche(nicheSelDb.value);
        }
    }
}

// ========== VOICE FUNCTIONS ==========
// OpenAI voices for free trial users (hardcoded — fixed list)
const OPENAI_VOICES = [
    { voice_key: 'openai:alloy',   voice_name: 'Alloy',   voice_description: 'Neutral, balanced', sample_voice: '' },
    { voice_key: 'openai:ash',     voice_name: 'Ash',     voice_description: 'Clear, confident',  sample_voice: '' },
    { voice_key: 'openai:ballad',  voice_name: 'Ballad',  voice_description: 'Warm, expressive',  sample_voice: '' },
    { voice_key: 'openai:coral',   voice_name: 'Coral',   voice_description: 'Bright, friendly',  sample_voice: '' },
    { voice_key: 'openai:echo',    voice_name: 'Echo',    voice_description: 'Smooth, steady',    sample_voice: '' },
    { voice_key: 'openai:fable',   voice_name: 'Fable',   voice_description: 'Storytelling tone', sample_voice: '' },
    { voice_key: 'openai:onyx',    voice_name: 'Onyx',    voice_description: 'Deep, authoritative',sample_voice: '' },
    { voice_key: 'openai:nova',    voice_name: 'Nova',    voice_description: 'Energetic, upbeat',  sample_voice: '' },
    { voice_key: 'openai:sage',    voice_name: 'Sage',    voice_description: 'Calm, thoughtful',  sample_voice: '' },
    { voice_key: 'openai:shimmer', voice_name: 'Shimmer', voice_description: 'Soft, gentle',      sample_voice: '' },
    { voice_key: 'openai:verse',   voice_name: 'Verse',   voice_description: 'Versatile, clear',  sample_voice: '' },
];

function loadVoicesForLanguage(langCode) {
    const hostSelect = document.getElementById('hostVoicePicker');
    const guestSelect = document.getElementById('guestVoicePicker');
    
    // Clear existing options (no blank placeholder — first voice auto-selected)
    hostSelect.innerHTML = '';
    guestSelect.innerHTML = '';
    
    // Pick voice list based on plan
    let voices = [];
    if (isFreeTrial) {
        // Free trial: OpenAI voices (English only — OpenAI doesn't vary by lang)
        voices = OPENAI_VOICES;
    } else {
        // Paid: Azure voices filtered by language
        voices = (voicesByLang[langCode] || []);
    }

    voices.forEach(voice => {
        const displayName = voice.voice_description
            ? `${voice.voice_name} — ${voice.voice_description}`
            : voice.voice_name;
        const option = document.createElement('option');
        option.value = voice.voice_key;
        option.textContent = displayName;
        option.dataset.sample = voice.sample_voice || '';
        hostSelect.appendChild(option.cloneNode(true));
        guestSelect.appendChild(option);
    });

    // Auto-select first voice
    if (hostSelect.options.length > 0)  hostSelect.selectedIndex  = 0;
    if (guestSelect.options.length > 0) guestSelect.selectedIndex = 0;
}

async function playVoiceSample(type) {
    const select = type === 'host' 
        ? document.getElementById('hostVoicePicker') 
        : document.getElementById('guestVoicePicker');
    
    const voiceCode = select.value;
    
    if (!voiceCode) {
        alert('Please select a voice first');
        return;
    }
    
    L(`🔊 Loading sample for ${type} voice...`);
    
    const fd = new FormData();
    fd.append('ajax_action', 'get_voice_sample');
    fd.append('voice_code', voiceCode);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const data = await response.json();
        
        if (data.success && data.sample_url) {
            const audio = document.getElementById('sampleAudioPlayer');
            audio.src = data.sample_url;
            audio.play();
            L(`✅ Playing sample for ${voiceCode}`);
        } else {
            L(`❌ No sample available`);
            alert('No sample available for this voice');
        }
    } catch (e) {
        L(`❌ Error: ${e.message}`);
    }
}

async function saveVoiceSettings() {
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const rate = document.getElementById('ratePicker').value;
    
    const fd = new FormData();
    fd.append('ajax_action', 'save_voice_settings');
    fd.append('podcast_id', currentPodcastId || 0);
    fd.append('host_voice', hostVoice);
    fd.append('guest_voice', guestVoice);
    fd.append('rate', rate);
    
    try {
        await fetch(window.location.href, { method: 'POST', body: fd });
    } catch (e) {
        console.error('Failed to save voice settings:', e);
    }
}

async function loadVoiceSettings() {
    const fd = new FormData();
    fd.append('ajax_action', 'load_voice_settings');
    fd.append('podcast_id', currentPodcastId || 0);
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await response.json();
        
        if (data.success) {
            if (data.host_voice) {
                document.getElementById('hostVoicePicker').value = data.host_voice;
            }
            if (data.guest_voice) {
                document.getElementById('guestVoicePicker').value = data.guest_voice;
            }
            if (data.rate) {
                document.getElementById('ratePicker').value = data.rate;
            }
        }
    } catch (e) {
        console.error('Failed to load voice settings:', e);
    }
}

// ========== PODCAST TYPE HANDLING ==========
function checkPodcastType(source) {
    let reelType;
    if (source === 'idea') {
        reelType = document.getElementById('idea_reel_type').value;
        document.getElementById('idea_podcast_info').style.display = reelType === 'podcast' ? 'flex' : 'none';
    } else if (source === 'content') {
        reelType = document.getElementById('content_reel_type').value;
        document.getElementById('content_podcast_info').style.display = reelType === 'podcast' ? 'flex' : 'none';
    } else if (source === 'db') {
        reelType = document.getElementById('db_reel_type').value;
        document.getElementById('db_podcast_info').style.display = reelType === 'podcast' ? 'flex' : 'none';
    }
    
    // Show/hide guest voice based on reel type
    const guestContainer = document.getElementById('guestVoiceContainer');
    if (guestContainer) {
        guestContainer.style.display = (reelType === 'podcast') ? 'block' : 'none';
    }
}

// ========== LOGGING ==========
// ========== LOGGING ==========
function L(message, type = 'info') {
    // Only show logs if user is super admin
    <?php if ($_SESSION['level'] === 'super'): ?>
    const log = document.getElementById('realtime_log');
    const ts = new Date().toLocaleTimeString();
    const entry = document.createElement('div');
    entry.className = `log-entry ${type}`;
    entry.innerHTML = `[${ts}] ${message}`;
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
    <?php endif; ?>
}


// Updated processIdeaContent with comprehensive error handling and logging
async function processIdeaContent(retryCount = 0) {
    const btn = document.getElementById('ideaProcessBtn');
    const contentArea = document.getElementById('idea_processed_content');
    const contentContainer = document.getElementById('idea_processed_container');
    
    if (!btn || !contentArea || !contentContainer) {
        console.error('Required elements not found');
        alert('Page elements not loaded properly. Please refresh.');
        return;
    }
    
    // Validate voice selection
    const hostVoice = document.getElementById('hostVoicePicker');
    const guestVoice = document.getElementById('guestVoicePicker');
    const reelTypeSelect = document.getElementById('idea_reel_type');
    
    if (!hostVoice || !guestVoice || !reelTypeSelect) {
        console.error('Voice selection elements not found');
        alert('Voice settings not loaded. Please refresh.');
        return;
    }
    
    const hostVoiceValue = hostVoice.value;
    const guestVoiceValue = guestVoice.value;
    const reelType = reelTypeSelect.value;

    // Voice validation only needed at Step 2 (scene creation), not Step 1 (script gen)
    // Skip for AI subtab where user is just generating a script
    const checkingAISubtab = document.getElementById('idea_subtab_ai')?.style.display !== 'none';
    if (!checkingAISubtab) {
        if (!hostVoiceValue) {
            alert('Please select a Host Voice before generating content');
            hostVoice.focus();
            return;
        }
        if (reelType === 'podcast' && !guestVoiceValue) {
            alert('Please select a Guest Voice for Podcast format');
            guestVoice.focus();
            return;
        }
    }
    
    // Get duration
    let duration = '30';
    if (!isFreeTrial) {
        const durationElement = document.getElementById('idea_duration');
        if (durationElement) {
            duration = durationElement.value;
        }
    }
    
    // Get topic — check which sub-tab is active
    const topicSelect = document.getElementById('idea_topic_select');
    const topicInput  = document.getElementById('idea_topic_input');
    const topicAI     = document.getElementById('idea_topic_ai');
    const aiSubtab    = document.getElementById('idea_subtab_ai');

    let topic = '';
    let userInstructions = '';
    const isAISubtab = aiSubtab && aiSubtab.style.display !== 'none';

    if (isAISubtab) {
        // AI sub-tab: full textarea content as instructions
        userInstructions = topicAI ? topicAI.value.trim() : '';
        // Extract first line as topic for logging/title
        topic = userInstructions.split('\n')[0].trim() || userInstructions;
    } else if (topicSelect && topicSelect.value && topicSelect.value !== '__manual__' && topicSelect.value !== '') {
        topic = topicSelect.value;
    } else if (topicInput && topicInput.style.display !== 'none' && topicInput.value) {
        topic = topicInput.value.trim();
    } else if (topicInput) {
        topic = topicInput.value.trim();
    }
    
    // Get title
    const titleSelect = document.getElementById('idea_title_select');
    const titleInput = document.getElementById('idea_title_input');
    
    let title = '';
    if (titleSelect && titleSelect.value && titleSelect.value !== '__new__' && titleSelect.value !== '') {
        title = titleSelect.value;
    } else if (titleInput && titleInput.style.display !== 'none' && titleInput.value) {
        title = titleInput.value.trim();
    } else if (titleInput) {
        title = titleInput.value.trim();
    }
    // For AI subtab, generate a title from topic if none selected
    if (isAISubtab && !title) {
        title = topic.substring(0, 60) || 'My Video';
    }

    const audienceSelect = document.getElementById('idea_audience');
    const ctaInput = document.getElementById('idea_cta');
    const langSelect = document.getElementById('global_lang_select');
    
    const audience = audienceSelect?.value || 'general';
    const cta = ctaInput?.value.trim() || '';
    const langName = langSelect?.options[langSelect?.selectedIndex]?.text || 'English';
    
    if (!topic && !userInstructions) {
        alert('Please enter your topic or instructions');
        topicAI?.focus();
        return;
    }
    
    if (!isAISubtab && !title) {
        alert('Please enter a title');
        titleSelect?.focus();
        return;
    }
    
    // Show processing modal
    showProcessingModal('Generating Content', 'Please wait while AI creates your script...');
    
    btn.disabled = true;
    btn.innerHTML = 'Processing...';
    contentArea.value = "Generating content...";
    
    if (contentContainer) contentContainer.style.display = 'block';
    
    const progressContainer = document.getElementById('progress_container');
    if (progressContainer) progressContainer.style.display = 'none';
    
    if (retryCount > 0) {
        L(`🔄 Retry attempt ${retryCount}/3 for content generation...`, 'warning');
        document.getElementById('processingMessage').innerHTML = 
            `Retry attempt ${retryCount}/3. Please wait...<br>This may take a moment.`;
    } else {
        L('Starting content generation...', 'info');
    }
    
    L(`Topic: ${topic}`, 'info');
    L(`Title: ${title}`, 'info');
    L(`Reel Type: ${reelType}`, 'info');
    L(`Duration: ${duration}s`, 'info');
    
    const prompt = buildGenerationPrompt({
        title: title,
        topic: topic,
        userInstructions: userInstructions || '',
        audience: audience,
        cta: cta,
        langName: langName,
        reelType: reelType,
        duration: parseInt(duration),
        source: 'idea'
    });
    
    const debugPrompt = document.getElementById('debugPrompt');
    if (debugPrompt) debugPrompt.value = prompt;
    
    L('Prompt sent to AI (first 200 chars): ' + prompt.substring(0, 200) + '...', 'info');
    
    // Animate progress bar
    let progress = 0;
    const progressInterval = setInterval(() => {
        progress = Math.min(progress + 1, 90);
        document.getElementById('processingProgressBar').style.width = progress + '%';
    }, 600);
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => {
        controller.abort();
        L('❌ Request timed out after 60 seconds', 'error');
        hideProcessingModal();
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Content";
        
        if (retryCount < 3) {
            if (confirm('Request timed out. Would you like to retry?')) {
                processIdeaContent(retryCount + 1);
            }
        } else {
            alert('Request timed out after multiple attempts. Please try again later.');
        }
    }, 60000);
    
    try {
        L('Sending request to server...', 'info');
        
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData,
            signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        L(`Response status: ${response.status}`, 'info');
        
        if (!response.ok) {
            if (response.status === 429) {
                L('⚠️ Rate limit hit (HTTP 429)', 'warning');
                
                if (retryCount < 3) {
                    const waitTime = Math.pow(2, retryCount) * 2000;
                    L(`⏳ Rate limited. Waiting ${waitTime/1000}s before retry ${retryCount + 1}/3...`, 'warning');
                    
                    document.getElementById('processingMessage').innerHTML = 
                        `Rate limit reached. Waiting ${waitTime/1000} seconds...<br>Auto-retry in progress (${retryCount + 1}/3)`;
                    
                    clearInterval(progressInterval);
                    
                    await new Promise(r => setTimeout(r, waitTime));
                    
                    return processIdeaContent(retryCount + 1);
                } else {
                    L('❌ Rate limit exceeded after 3 retries', 'error');
                    hideProcessingModal();
                    btn.disabled = false;
                    btn.innerHTML = "📝 Step 1: Generate Content";
                    alert('Rate limit exceeded. Please try again in a few minutes.');
                    return;
                }
            }
            
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        L('✅ Request successful, parsing response...', 'info');
        
        const responseText = await response.text();
        
        document.getElementById('processingProgressBar').style.width = '100%';
        
        const debugResponse = document.getElementById('debugResponse');
        if (debugResponse) debugResponse.value = responseText;
        
        L(`Response received: ${responseText.length} characters`, 'info');
        
        let data;
        try {
            data = JSON.parse(responseText);
            L('✅ Response parsed as JSON successfully', 'success');
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            L('❌ Server returned non-JSON response', 'error');
            
            if (responseText.includes('Fatal error') || responseText.includes('Warning')) {
                const errorMatch = responseText.match(/<b>(?:Fatal error|Warning)<\/b>:\s+([^<]+)/i);
                if (errorMatch) {
                    L(`PHP Error: ${errorMatch[1]}`, 'error');
                }
            }
            
            hideProcessingModal();
            throw new Error('Invalid JSON response from server');
        }
        
        clearInterval(progressInterval);
        
        if (data.success) {
            let processedContent = data.content;
            
            const sceneCount = (processedContent.match(/\[SCENE\]/g) || []).length;
            L(`✅ Received response with ${sceneCount} scenes`, 'success');
            
            // DEBUG: check if NL_TAGS is in the raw AI response
            const hasNlTags = processedContent.includes('NL_TAGS:');
            L(`🔍 NL_TAGS present in AI response: ${hasNlTags ? '✅ YES' : '❌ NO — AI did not output NL_TAGS'}`, hasNlTags ? 'success' : 'error');
            if (!hasNlTags) {
                // Show a snippet of what the AI DID output so we can diagnose
                const snippet = processedContent.substring(0, 400).replace(/\n/g, '↵');
                L(`🔍 Raw response snippet: ${snippet}`, 'info');
            } else {
                // Show first NL_TAGS value found
                const nlMatch = processedContent.match(/NL_TAGS:\s*(.+)/);
                if (nlMatch) L(`🔍 First NL_TAGS value: ${nlMatch[1].substring(0, 100)}`, 'info');
            }
            
            const scenes = parseScenesFromContent(processedContent);
            
            // DEBUG: verify nl_tags was parsed
            if (scenes.length > 0) {
                L(`🔍 First scene nl_tags after parse: ${scenes[0].nl_tags ? scenes[0].nl_tags.substring(0, 80) : '❌ EMPTY — parser did not extract NL_TAGS'}`, scenes[0].nl_tags ? 'success' : 'error');
            }
            
            const debugScenes = document.getElementById('debugScenes');
            if (debugScenes) debugScenes.value = JSON.stringify(scenes, null, 2);
            
            let displayContent = '';
            scenes.forEach(scene => {
                let textLine = scene.text;
                if (!textLine.includes('<break')) {
                    textLine += '<break time="250ms"/>';
                }
                displayContent += textLine + '\n';
            });
            
            contentArea.value = displayContent;
            contentArea.dataset.scenes = JSON.stringify(scenes);
            contentArea.dataset.fullContent = processedContent;
            
            L(`✅ Parsed ${scenes.length} scenes with detailed prompts and hashtags`, 'success');
            
            const createScenesBtn = document.getElementById('ideaCreateScenesBtn');
            if (createScenesBtn) createScenesBtn.style.display = 'block';
            
            setTimeout(() => {
                hideProcessingModal();
                showStatus(`Content generated with ${sceneCount} detailed scenes!`, 'success');
            }, 1000);
            
        } else {
            hideProcessingModal();
            
            if (data.message && (data.message.includes('429') || data.message.includes('rate limit'))) {
                if (retryCount < 3) {
                    const waitTime = Math.pow(2, retryCount) * 3000;
                    L(`⚠️ Rate limit error from API. Retrying in ${waitTime/1000}s...`, 'warning');
                    
                    showProcessingModal('Retrying...', `API rate limit. Waiting ${waitTime/1000}s before retry ${retryCount + 1}/3`);
                    
                    await new Promise(r => setTimeout(r, waitTime));
                    
                    return processIdeaContent(retryCount + 1);
                }
            }
            
            throw new Error(data.message || 'Generation failed');
        }
    } catch (err) {
        hideProcessingModal();
        clearInterval(progressInterval);
        clearTimeout(timeoutId);
        
        console.error('Full error:', err);
        L(`❌ Error: ${err.message}`, 'error');
        
        if (err.name === 'AbortError') {
            if (retryCount < 3 && confirm('Request timed out. Would you like to retry?')) {
                processIdeaContent(retryCount + 1);
                return;
            }
        } else if (err.message.includes('429') || err.message.includes('rate limit')) {
            if (retryCount < 3) {
                const waitTime = Math.pow(2, retryCount) * 3000;
                L(`⚠️ Rate limit detected. Retrying in ${waitTime/1000}s...`, 'warning');
                
                showProcessingModal('Retrying...', `Rate limit reached. Waiting ${waitTime/1000}s... (${retryCount + 1}/3)`);
                
                setTimeout(() => {
                    processIdeaContent(retryCount + 1);
                }, waitTime);
                
                return;
            }
        }
        
        showStatus('Error: ' + err.message, 'error');
        alert('Error: ' + err.message);
    } finally {
        if (retryCount === 0 || retryCount >= 3) {
            btn.disabled = false;
            btn.innerHTML = "📝 Step 1: Generate Content";
        }
    }
}
// Processing Modal Functions
function showProcessingModal(title, message) {
    const modal = document.getElementById('processingModal');
    if (modal) {
        document.getElementById('processingTitle').textContent = title;
        document.getElementById('processingMessage').textContent = message;
        document.getElementById('processingProgressBar').style.width = '0%';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function hideProcessingModal() {
    const modal = document.getElementById('processingModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function cancelProcessing() {
    if (confirm('Are you sure you want to cancel content generation?')) {
        hideProcessingModal();
        // The actual abort is handled by the AbortController in the main function
        L('⏹️ Content generation cancelled by user', 'warning');
    }
}

																																																				
// Helper function to parse scenes from [SCENE] format
function parseScenesFromContent(content) {
    const scenes = [];
    const sceneBlocks = content.split('[SCENE]').filter(block => block.trim());
    
    for (const block of sceneBlocks) {
        const scene = {};
        
        // Extract TEXT
        const textMatch = block.match(/TEXT:\s*(.*?)(?=PROMPT:|HASHTAGS:|$)/s);
        if (textMatch) {
            scene.text = textMatch[1].trim();
        }
        
        // Extract PROMPT
        const promptMatch = block.match(/PROMPT:\s*(.*?)(?=HASHTAGS:|$)/s);
        if (promptMatch) {
            scene.prompt = promptMatch[1].trim();
        }
        
        // Extract HASHTAGS
        const hashtagsMatch = block.match(/HASHTAGS:\s*(.*?)(?=NL_TAGS:|$)/s);
        if (hashtagsMatch) {
            scene.hashtags = hashtagsMatch[1].trim();
        }

        // Extract NL_TAGS — natural language phrases for Pexels/stock media search
        const nlTagsMatch = block.match(/NL_TAGS:\s*(.*?)$/s);
        if (nlTagsMatch) {
            scene.nl_tags = nlTagsMatch[1].trim();
        }
        
        // Determine actor for podcast
        let actor = 'host';
        if (scene.text && scene.text.toLowerCase().startsWith('host:')) {
            actor = 'host';
            scene.text = scene.text.substring(5).trim();
        } else if (scene.text && scene.text.toLowerCase().startsWith('guest:')) {
            actor = 'guest';
            scene.text = scene.text.substring(6).trim();
        }
        scene.actor = actor;
        
        if (scene.text) {
            scenes.push(scene);
        }
    }
    
    return scenes;
}
// Helper function to escape regex special characters
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function processContent() {
    const btn = document.getElementById('contentProcessBtn');
    const contentArea = document.getElementById('content_processed_content');
    const contentContainer = document.getElementById('content_processed_container');
    
    // VALIDATE VOICE SELECTION
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const reelType = document.getElementById('content_reel_type').value;
    
    if (!hostVoice) {
        alert('Please select a Host Voice before processing content');
        document.getElementById('hostVoicePicker').focus();
        return;
    }
    
    if (reelType === 'podcast' && !guestVoice) {
        alert('Please select a Guest Voice for Podcast format');
        document.getElementById('guestVoicePicker').focus();
        return;
    }
    
    // Get duration (handles free trial automatically)
    let duration = '30';
    if (!isFreeTrial) {
        const durationElement = document.getElementById('content_duration');
        if (durationElement) {
            duration = durationElement.value;
        }
    }
    
    const title = document.getElementById('content_title').value.trim();
    const story = document.getElementById('content_story').value.trim();
    const langSelect = document.getElementById('global_lang_select');
    const langName = langSelect.options[langSelect.selectedIndex]?.text || 'English';
    const cta = document.getElementById('content_cta')?.value.trim() || 'Follow for more daily tips';
    
    if (!title || !story) {
        alert('Please enter title and content');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = 'Processing...';
    contentArea.value = "Processing...";
    
    if (contentContainer) contentContainer.style.display = 'block';
    
    document.getElementById('progress_container').style.display = 'block';
    L('Starting content processing...', 'info');
    
    // Map reel type for prompt builder
    let mappedReelType = 'standard';
    if (reelType === 'single-scene') {
        mappedReelType = 'broll';
    } else if (reelType === 'podcast') {
        mappedReelType = 'podcast';
    } else {
        mappedReelType = 'standard';
    }
    
    // Use the centralized prompt builder with original content - FIXED: Added duration
    const prompt = buildGenerationPrompt({
        title: title,
        cta: cta,
        langName: langName,
        reelType: mappedReelType,
        duration: parseInt(duration), // <-- ADDED THIS LINE
        source: 'content',
        originalContent: story
    });
    
    L('Sending request to server...', 'info');
    
    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        const responseText = await response.text();
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            L('❌ Server returned non-JSON response', 'error');
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.success) {
            let processedContent = data.content;
            
            // Parse [SCENE] format for display
            const sceneBlocks = processedContent.split('[SCENE]').filter(block => block.trim());
            let displayContent = '';
            
            sceneBlocks.forEach(block => {
                const textMatch = block.match(/TEXT:\s*(.*?)(?=PROMPT:|HASHTAGS:|$)/s);
                if (textMatch) {
                    let text = textMatch[1].trim();
                    displayContent += text + '\n';
                }
            });
            
            if (!displayContent) {
                displayContent = processedContent;
            }
            
            contentArea.value = displayContent;
            contentArea.dataset.fullContent = processedContent;
            
            const sceneCount = sceneBlocks.length;
            L(`✅ Content processed: ${sceneCount} scenes for ${duration}s video`, 'success');
            
            document.getElementById('contentCreateScenesBtn').style.display = 'block';
            showStatus(`Content processed with ${sceneCount} scenes for ${duration}s video!`, 'success');
        } else {
            throw new Error(data.message || 'Processing failed');
        }
    } catch (err) {
        L(`❌ Error: ${err.message}`, 'error');
        showStatus('Error: ' + err.message, 'error');
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Process Content";
    }
}
async function generateContentOnly() {
    const btn = document.getElementById('generateContentBtn');
    const contentArea = document.getElementById('generated_content');
    
    const smSelect = document.getElementById('sm_id_select');
    if (!smSelect.value) {
        alert('Please select a title');
        return;
    }
    
    // Get duration (handles free trial automatically)
    let duration = '30';
    if (!isFreeTrial) {
        const durationElement = document.getElementById('db_duration');
        if (durationElement) {
            duration = durationElement.value;
        }
    }
    
    const category = document.getElementById('cat_select').value;
    const topic = document.getElementById('topic_select').value;
    const title = smSelect.options[smSelect.selectedIndex].text;
    const cta = document.getElementById('db_cta').value;
    const langSelect = document.getElementById('global_lang_select');
    const langName = langSelect.options[langSelect.selectedIndex]?.text || 'English';
    const reelType = document.getElementById('db_reel_type').value;
    
    btn.disabled = true;
    btn.innerHTML = 'Generating...';
    contentArea.value = "Generating...";
    
    // Use centralized prompt builder - FIXED: Added duration
    const prompt = buildGenerationPrompt({
        title: title,
        topic: topic,
        cta: cta,
        langName: langName,
        reelType: reelType,
        duration: parseInt(duration), // <-- ADDED THIS LINE
        source: 'database',
        audience: 'general'
    });

    L(`Generating ${duration}s ${reelType} content for: ${title}...`, 'info');

    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'generate_content_only');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        const responseText = await response.text();
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            L('❌ Server returned non-JSON response', 'error');
            throw new Error('Invalid JSON response');
        }
        
        if (data.success) {
            contentArea.value = data.content;
            contentArea.dataset.fullContent = data.content;
            
            const sceneCount = (data.content.match(/\[SCENE\]/g) || []).length;
            L(`✅ Content generated: ${sceneCount} scenes for ${duration}s video`, 'success');
            
            document.getElementById('createScenesBtn').style.display = 'block';
            showStatus(`Content generated with ${sceneCount} scenes for ${duration}s video!`, 'success');
        } else {
            throw new Error(data.message || 'Generation failed');
        }
    } catch (err) {
        L(`❌ Error: ${err.message}`, 'error');
        showStatus('Error: ' + err.message, 'error');
        alert('Error: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Script";
    }
}

// ========== DATABASE FUNCTIONS ==========
function loadTopicsForNiche(nicheId) {
    const topicSel = document.getElementById('topic_select');
    const titleSel = document.getElementById('sm_id_select');

    // Reset dependents
    topicSel.innerHTML = '<option value="">-- Loading... --</option>';
    titleSel.innerHTML  = '<option value="">-- Select Title --</option>';

    if (!nicheId) {
        topicSel.innerHTML = '<option value="">-- Select Topic --</option>';
        return;
    }

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `get_topics=1&niche_id=${encodeURIComponent(nicheId)}`
    })
    .then(r => r.text())
    .then(html => {
        topicSel.innerHTML = html;
        // Auto-select first topic and load its titles
        if (topicSel.options.length > 1) {
            topicSel.selectedIndex = 1;
            loadTitlesForTopic(topicSel.value);
        }
    });
}

function loadTitlesForTopic(topicId) {
    const titleSel = document.getElementById('sm_id_select');
    titleSel.innerHTML = '<option value="">-- Loading... --</option>';

    if (!topicId) {
        titleSel.innerHTML = '<option value="">-- Select Title --</option>';
        return;
    }

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `get_titles=1&topic_id=${encodeURIComponent(topicId)}`
    })
    .then(r => r.text())
    .then(html => {
        titleSel.innerHTML = html;
        // Auto-select first title
        if (titleSel.options.length > 1) {
            titleSel.selectedIndex = 1;
            updatePrompt();
        }
    });
}

// Keep old names as aliases for backward compatibility
function loadTopics(cat) { loadTopicsForNiche(cat); }
function loadTitles(topicId) { loadTitlesForTopic(topicId); }

function updatePrompt() {
    // Not implemented in this version
}

// ========== SCENE CREATION FUNCTIONS ==========


async function createContentScenes() {
    const contentArea = document.getElementById('content_processed_content');
    const title = document.getElementById('content_title').value;
    const reelType = document.getElementById('content_reel_type').value;
    const langCode = document.getElementById('global_lang_select').value;
    
    if (!contentArea || !contentArea.value) {
        alert('Content is empty');
        return;
    }
    
    // Use fullContent (raw AI response with NL_TAGS) if available, otherwise fall back to display value
    const rawContent = contentArea.dataset.fullContent || contentArea.value;
    const scenes = parseScenesFromContent(rawContent);
    
    if (!scenes || scenes.length === 0) {
        alert('No scenes found in content');
        return;
    }
    
    L(`📝 Parsed ${scenes.length} scenes from content tab (nl_tags: ${scenes[0]?.nl_tags ? 'present' : 'missing'})`, 'info');
    
    await createAllScenes(scenes, title, reelType, langCode, 'content');
}

async function createDbScenes() {
    const contentArea = document.getElementById('generated_content');
    const title = document.getElementById('sm_id_select').options[document.getElementById('sm_id_select').selectedIndex]?.text || 'Database Content';
    const reelType = document.getElementById('db_reel_type').value;
    const langCode = document.getElementById('global_lang_select').value;
    
    if (!contentArea || !contentArea.value) {
        alert('Content is empty');
        return;
    }
    
    // Use fullContent (raw AI response with NL_TAGS) if available, otherwise fall back to display value
    const rawContent = contentArea.dataset.fullContent || contentArea.value;
    const scenes = parseScenesFromContent(rawContent);
    
    if (!scenes || scenes.length === 0) {
        alert('No scenes found in content');
        return;
    }
    
    L(`📝 Parsed ${scenes.length} scenes from db tab (nl_tags: ${scenes[0]?.nl_tags ? 'present' : 'missing'})`, 'info');
    
    await createAllScenes(scenes, title, reelType, langCode, 'db');
}

async function createAllScenes(scenes, title, reelType, langCode, source) {
    L('🎬 ===== STEP 1: CREATING SCENES =====', 'info');
    L(`🔍 DEBUG - createAllScenes STARTED at ${new Date().toLocaleTimeString()}`, 'info');
    
    // Safety: if scenes is a raw string (old code path), parse it now
    if (typeof scenes === 'string') {
        L('⚠️ scenes passed as string — parsing now (nl_tags may be missing if not raw AI output)', 'warning');
        scenes = parseScenesFromContent(scenes);
    }
    
    // Reset stop flag
    window.stopSceneCreation = false;
    
    // Update progress modal - Step 1 active
    updateStepStatus(1, 'active', 'Creating scenes in database', `Creating ${scenes.length} scenes...`);
    updateOverallProgress(10, 'Creating scenes...', `Processing ${scenes.length} scenes`, `Processing 0/${scenes.length} scenes`);
    
    // Step 1: Create scenes in database
    L('🔍 DEBUG - About to call createScenesInDB', 'info');
    const podcastId = await createScenesInDB(scenes, title, reelType, langCode, source);
    
    if (!podcastId) {
        L('❌ Failed to create scenes - aborting', 'error');
        L('🔍 DEBUG - createScenesInDB returned null/false', 'error');
        updateStepStatus(1, 'error', 'Failed to create scenes', 'Database error');
        alert('Failed to create scenes');
        return;
    }
    
    currentPodcastId = podcastId;
    L(`✅ Podcast created with ID: ${podcastId}`, 'success');
    L(`🔍 DEBUG - Podcast ID: ${podcastId}`, 'info');
    
    // Update progress - Step 1 complete
    updateStepStatus(1, 'complete', 'Scenes created', `${scenes.length} scenes created`);
    updateOverallProgress(30, 'Verifying scenes...', 'Checking database', `Processing 0/${scenes.length} scenes`);
    
    // Verify scenes were created
    L('🔍 Verifying scenes were created...', 'info');
    L('🔍 DEBUG - About to call getScenes', 'info');
    const verifyScenes = await getScenes(podcastId);
    
    if (!verifyScenes || verifyScenes.length === 0) {
        L('❌ Verification failed: No scenes found in database', 'error');
        L('🔍 DEBUG - getScenes returned empty or null', 'error');
        updateStepStatus(1, 'error', 'Verification failed', 'No scenes found');
        return;
    }
    
    L(`✅ Verified: ${verifyScenes.length} scenes in database`, 'success');
    L(`🔍 DEBUG - First scene hashtags: ${verifyScenes[0]?.hashtags || 'none'}`, 'info');
    
    // Check if cancelled
    if (window.stopSceneCreation) {
        L('⏹️ Process cancelled after scene creation', 'warning');
        hideProgressModal();
        return;
    }
    
    // Step 2: Generate audio for all scenes
    L('🎤 ===== STEP 2: GENERATING AUDIO =====', 'info');
    L('🔍 DEBUG - About to call generateAllAudio', 'info');
    
    // Update progress - Step 2 active
    updateStepStatus(2, 'active', 'Generating audio', `Processing ${verifyScenes.length} scenes...`);
    updateOverallProgress(40, 'Generating audio...', 'Starting audio generation', `Processing 0/${verifyScenes.length} scenes`);
    
    await generateAllAudio(podcastId, langCode);
    L('🔍 DEBUG - generateAllAudio COMPLETED', 'info');
    
    // Check if cancelled
    if (window.stopSceneCreation) {
        L('⏹️ Process cancelled after audio generation', 'warning');
        hideProgressModal();
        return;
    }
    
    // Step 2 complete
    updateStepStatus(2, 'complete', 'Audio generated', 'All scenes have audio');
    updateOverallProgress(70, 'Assigning images/videos...', 'Starting media assignment', `Processing 0/${verifyScenes.length} scenes`);
    
    // Step 3: Assign images to all scenes
    L('🖼️ ===== STEP 3: ASSIGNING IMAGES =====', 'info');
    L('🔍 DEBUG - About to call assignImagesToAllScenes', 'info');

    // Update progress - Step 3 active
    updateStepStatus(3, 'active', 'Assigning images/videos', 'Searching media library...');

    const mediaType = document.getElementById('s2_mediaVal')?.value || 'stock_images';
    L(`🎞️ Media type selected: ${mediaType}`, 'info');

    await assignImagesToAllScenes(podcastId, mediaType);
    L('🔍 DEBUG - assignImagesToAllScenes COMPLETED', 'info');
    
    // Check if cancelled
    if (window.stopSceneCreation) {
        L('⏹️ Process cancelled during media assignment', 'warning');
        hideProgressModal();
        return;
    }
    
    // Step 3 complete
    updateStepStatus(3, 'complete', 'Media assigned', 'All scenes have images/videos');
    updateOverallProgress(100, 'Complete!', 'All steps finished', `Processing ${verifyScenes.length}/${verifyScenes.length} scenes`);
    
    // Show completion
    L('🎉 ===== ALL STEPS COMPLETE =====', 'success');
    L(`✅ Podcast #${podcastId} fully processed`, 'success');
    L(`🔍 DEBUG - createAllScenes FINISHED at ${new Date().toLocaleTimeString()}`, 'info');
    
    // Hide progress modal and show success modal after short delay
    setTimeout(() => {
        hideProgressModal();
        showSuccessModal(podcastId);
    }, 1500);
}

async function assignImagesToAllScenes(podcastId, mediaType = 'stock_images') {
    logToModal(`Starting media assignment — type: ${mediaType}`, 'info');
    if (window.stopSceneCreation) { logToModal('Cancelled', 'warning'); return; }

    const scenes = await getScenes(podcastId);
    if (!scenes || scenes.length === 0) { logToModal('No scenes found', 'error'); return; }

    let completed = 0, total = scenes.length;
    let videosAssigned = 0, imagesAssigned = 0, generatedAssigned = 0, duplicatesRejected = 0;
    const usedMedia = new Set();
    let firstSceneImage = null;

    for (let i = 0; i < scenes.length; i++) {
        const scene      = scenes[i];
        const sceneNumber = i + 1;

        if (window.stopSceneCreation) { logToModal('Process cancelled', 'warning'); return; }

        document.getElementById('sceneProgress').textContent = `Scene ${sceneNumber}/${total}`;
        updateStepStatus(3, 'active', 'Assigning media', `Scene ${sceneNumber}/${total} — searching library...`);
        logToModal(`Scene ${sceneNumber}/${total} — tags: ${scene.hashtags || 'none'}`, 'info');

        if (!scene.hashtags && !scene.nl_tags) {
            logToModal(`Scene ${sceneNumber}: no hashtags or nl_tags, skipping`, 'warning');
            completed++;
            continue;
        }

        let assignedFile = null;
        let logData = {
            podcast_id: podcastId, scene_id: scene.id, scene_no: sceneNumber,
            hashtags: scene.hashtags, found_images: 0, found_videos: 0,
            selected_file: '', selected_type: '', was_duplicate: 0,
            ai_generated: 0, ai_prompt: ''
        };

        if (mediaType === 'unique_images') {
            logToModal(`Scene ${sceneNumber}: AI generate mode`, 'info');
            updateStepStatus(3, 'active', 'Assigning media', `Scene ${sceneNumber}/${total} — generating AI image...`);
            const generated = await generateImageForScene(scene, sceneNumber, total);
            if (generated && generated.success) {
                generatedAssigned++;
                assignedFile = generated.filename;
                usedMedia.add(generated.filename);
                await assignImageToScene(scene.id, generated.filename);
                logData.ai_generated = 1;
                logData.ai_prompt    = generated.prompt || '';
                logData.selected_file = generated.filename;
                logData.selected_type = 'image';
                logToModal(`Scene ${sceneNumber}: ✓ AI image → ${generated.filename}`, 'success');
            } else {
                logToModal(`Scene ${sceneNumber}: ✗ AI generation failed`, 'error');
            }
        } else {
            // Use nl_tags (natural language) for Pexels/stock search, fall back to hashtags for internal library
            const nlPhrases = scene.nl_tags ? scene.nl_tags.split('|').map(p => p.trim()).filter(Boolean) : [];
            const firstQuery = nlPhrases.length > 0 ? nlPhrases[0] : scene.hashtags;

            let results = await searchImagesByHashtags(firstQuery);

            // If no results, try remaining nl_tag phrases one by one
            if ((!results || results.length === 0) && nlPhrases.length > 1) {
                for (let p = 1; p < nlPhrases.length; p++) {
                    logToModal(`Scene ${sceneNumber}: no results, retrying with "${nlPhrases[p]}"`, 'info');
                    results = await searchImagesByHashtags(nlPhrases[p]);
                    if (results && results.length > 0) break;
                }
            }

            // Final fallback to hashtags if all nl_tags failed
            if ((!results || results.length === 0) && scene.hashtags) {
                logToModal(`Scene ${sceneNumber}: nl_tags exhausted, falling back to hashtags`, 'warning');
                results = await searchImagesByHashtags(scene.hashtags);
            }
            const totalFound = results ? results.length : 0;
            const foundImages = results ? results.filter(r => r.type === 'image').length : 0;
            const foundVideos = results ? results.filter(r => r.type === 'video').length : 0;
            logData.found_images = foundImages;
            logData.found_videos = foundVideos;

            logToModal(`Scene ${sceneNumber}: found ${foundVideos} videos, ${foundImages} images`, 'info');

            if (results && results.length > 0) {
                // Filter out already-used media
                const available = results.filter(r => !usedMedia.has(r.filename));
                const dupeCount  = results.length - available.length;
                duplicatesRejected += dupeCount;
                if (dupeCount > 0) logToModal(`Scene ${sceneNumber}: skipped ${dupeCount} already-used`, 'warning');

                // Filter by mediaType
                let candidates = available;
                if (mediaType === 'stock_images')  candidates = available.filter(r => r.type === 'image');
                if (mediaType === 'stock_videos')  candidates = available.filter(r => r.type === 'video');

                if (candidates.length === 0) {
                    logToModal(`Scene ${sceneNumber}: none of type '${mediaType}' available — AI fallback`, 'warning');
                    updateStepStatus(3, 'active', 'Assigning media', `Scene ${sceneNumber}/${total} — generating AI image...`);
                    const generated = await generateImageForScene(scene, sceneNumber, total);
                    if (generated && generated.success) {
                        generatedAssigned++;
                        assignedFile = generated.filename;
                        usedMedia.add(generated.filename);
                        await assignImageToScene(scene.id, generated.filename);
                        logData.ai_generated  = 1;
                        logData.ai_prompt     = generated.prompt || '';
                        logData.selected_file = generated.filename;
                        logData.selected_type = 'image';
                        logToModal(`Scene ${sceneNumber}: ✓ AI fallback → ${generated.filename}`, 'success');
                    } else {
                        logToModal(`Scene ${sceneNumber}: ✗ AI fallback failed`, 'error');
                    }
                } else {
                    // Random selection
                    let selectedItem = null;
                    if (mediaType === 'mix_media') {
                        const vids = candidates.filter(r => r.type === 'video');
                        const imgs = candidates.filter(r => r.type === 'image');
                        if (vids.length > 0 && (Math.random() < 0.7 || imgs.length === 0)) {
                            selectedItem = vids[Math.floor(Math.random() * vids.length)];
                            videosAssigned++;
                        } else if (imgs.length > 0) {
                            selectedItem = imgs[Math.floor(Math.random() * imgs.length)];
                            imagesAssigned++;
                        }
                    } else {
                        selectedItem = candidates[Math.floor(Math.random() * candidates.length)];
                        if (selectedItem.type === 'video') videosAssigned++;
                        else imagesAssigned++;
                    }

                    if (selectedItem) {
                        assignedFile = selectedItem.filename;
                        usedMedia.add(assignedFile);
                        const ok = await assignImageToScene(scene.id, assignedFile);
                        logData.selected_file = assignedFile;
                        logData.selected_type = selectedItem.type;
                        if (ok) {
                            logToModal(`Scene ${sceneNumber}: ✓ ${selectedItem.type} → ${assignedFile}`, 'success');
                            updateStepStatus(3, 'active', 'Assigning media', `Scene ${sceneNumber}/${total} — assigned ${selectedItem.type}`);
                        } else {
                            logToModal(`Scene ${sceneNumber}: ✗ assignment failed`, 'error');
                            assignedFile = null;
                        }
                    }
                }
            } else {
                logToModal(`Scene ${sceneNumber}: nothing in library — AI generate`, 'warning');
                updateStepStatus(3, 'active', 'Assigning media', `Scene ${sceneNumber}/${total} — generating AI image...`);
                const generated = await generateImageForScene(scene, sceneNumber, total);
                if (generated && generated.success) {
                    generatedAssigned++;
                    assignedFile = generated.filename;
                    usedMedia.add(generated.filename);
                    await assignImageToScene(scene.id, generated.filename);
                    logData.ai_generated  = 1;
                    logData.ai_prompt     = generated.prompt || '';
                    logData.selected_file = generated.filename;
                    logData.selected_type = 'image';
                    logToModal(`Scene ${sceneNumber}: ✓ AI image → ${generated.filename}`, 'success');
                } else {
                    logToModal(`Scene ${sceneNumber}: ✗ AI generation failed`, 'error');
                }
            }
        }

        // Save log to DB
        const logFd = new FormData();
        logFd.append('ajax_action',    'log_media_search');
        logFd.append('podcast_id',     logData.podcast_id);
        logFd.append('scene_id',       logData.scene_id);
        logFd.append('scene_no',       logData.scene_no);
        logFd.append('hashtags',       logData.hashtags);
        logFd.append('found_images',   logData.found_images);
        logFd.append('found_videos',   logData.found_videos);
        logFd.append('selected_file',  logData.selected_file);
        logFd.append('selected_type',  logData.selected_type);
        logFd.append('was_duplicate',  logData.was_duplicate);
        logFd.append('ai_generated',   logData.ai_generated);
        logFd.append('ai_prompt',      logData.ai_prompt);
        fetch('', { method: 'POST', body: logFd }); // fire-and-forget

        if (sceneNumber === 1 && assignedFile) {
            firstSceneImage = assignedFile;
        }

        completed++;
        const pct = Math.round((completed / total) * 100);
        document.getElementById('overallProgressBar').style.width = (70 + Math.round(pct * 0.3)) + '%';
        document.getElementById('sceneProgress').textContent =
            `Scene ${completed}/${total} · 🎬${videosAssigned} 🖼️${imagesAssigned} 🎨${generatedAssigned}`;

        await new Promise(r => setTimeout(r, 300));
    }
    
    // Set podcast thumbnail from first scene's image
    if (firstSceneImage) {
        L('🖼️ Setting podcast thumbnail from first scene...', 'info');
        const thumbnailSet = await setPodcastThumbnail(podcastId, firstSceneImage);
        if (thumbnailSet) {
            L(`✅ Podcast thumbnail set to: ${firstSceneImage}`, 'success');
        } else {
            L(`⚠️ Could not set podcast thumbnail`, 'warning');
        }
    } else {
        L(`⚠️ No image found for first scene, thumbnail not set`, 'warning');
    }
    
    L('\n=== MEDIA ASSIGNMENT COMPLETE ===', 'success');
    L(`📊 Summary:`, 'info');
    L(`   🎬 Videos assigned: ${videosAssigned} (all unique)`, 'info');
    L(`   🖼️ Images assigned: ${imagesAssigned} (all unique)`, 'info');
    L(`   🎨 AI Generated: ${generatedAssigned}`, 'info');
    L(`   🚫 Duplicates rejected: ${duplicatesRejected}`, 'info');
    L(`   ✅ Total: ${completed}/${total} scenes`, 'success');
    L(`   📸 Thumbnail: ${firstSceneImage || 'Not set'}`, 'info');
    
    if (duplicatesRejected > 0) {
        L(`   ✅ ${duplicatesRejected} duplicate media files were prevented from re-use`, 'success');
    }
}
// Add this helper function to set the podcast thumbnail
async function setPodcastThumbnail(podcastId, imageFilename) {
    L(`📡 Setting thumbnail for podcast ${podcastId} to ${imageFilename}...`, 'info');
    
    const fd = new FormData();
    fd.append('ajax_action', 'set_podcast_thumbnail');
    fd.append('podcast_id', podcastId);
    fd.append('thumbnail', imageFilename);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const data = await response.json();
        
        if (data.success) {
            L(`✅ Thumbnail set successfully`, 'success');
            return true;
        } else {
            L(`❌ Failed to set thumbnail: ${data.message}`, 'error');
            return false;
        }
    } catch (err) {
        L(`❌ Error setting thumbnail: ${err.message}`, 'error');
        return false;
    }
}

async function generateImageForScene(scene, sceneNumber, totalScenes) {
    L(`   🔧 ===== STARTING IMAGE GENERATION FOR SCENE ${sceneNumber} =====`, 'info');
    L(`   Scene ID: ${scene.id}`, 'info');
    L(`   Original prompt: "${scene.prompt?.substring(0, 100)}..."`, 'info');
    
    // Step 1: Enhance the prompt
    L(`   📡 Step 1: Sending to enhance_prompt...`, 'info');
    
    const enhanceData = new FormData();
    enhanceData.append('ajax_action', 'enhance_prompt');
    enhanceData.append('scene_id', scene.id);
    enhanceData.append('prompt', scene.prompt || '');
    
    try {
        const enhanceResponse = await fetch(window.location.href, {
            method: 'POST',
            body: enhanceData
        });
        
        L(`   📥 Enhance response status: ${enhanceResponse.status}`, 'info');
        
        if (!enhanceResponse.ok) {
            L(`   ❌ HTTP error: ${enhanceResponse.status}`, 'error');
            return { success: false, message: `HTTP ${enhanceResponse.status}` };
        }
        
        const enhanceText = await enhanceResponse.text();
        L(`   📥 Raw enhance response (first 200 chars): ${enhanceText.substring(0, 200)}`, 'info');
        
        if (enhanceText.trim().startsWith('<!DOCTYPE')) {
            L(`   ❌ Received HTML instead of JSON - possible PHP error`, 'error');
            return { success: false, message: 'Server returned HTML' };
        }
        
        let enhanceResult;
        try {
            enhanceResult = JSON.parse(enhanceText);
            L(`   ✅ Enhance response parsed successfully`, 'success');
        } catch (e) {
            L(`   ❌ Failed to parse enhance response: ${e.message}`, 'error');
            return { success: false, message: 'Enhance response parse failed' };
        }
        
        if (!enhanceResult.success) {
            L(`   ❌ Enhancement failed: ${enhanceResult.message}`, 'error');
            return { success: false, message: enhanceResult.message };
        }
        
        L(`   ✅ Prompt enhanced successfully`, 'success');
        L(`   Enhanced prompt: "${enhanceResult.enhanced_prompt?.substring(0, 100)}..."`, 'info');
        L(`   Generated hashtags: ${enhanceResult.hashtags}`, 'info');
        
        // Step 2: Generate the image
        L(`   📡 Step 2: Sending to generate_image_api.php...`, 'info');
        
        const generateData = new FormData();
        generateData.append('action', 'generate_single_image');
        generateData.append('scene_id', scene.id);
        generateData.append('podcast_id', scene.podcast_id);
        generateData.append('enhanced_prompt', enhanceResult.enhanced_prompt);
        generateData.append('hashtags', enhanceResult.hashtags);
        generateData.append('seq_no', sceneNumber);
        
        const generateResponse = await fetch('generate_image_api.php', {
            method: 'POST',
            body: generateData
        });
        
        L(`   📥 Generate response status: ${generateResponse.status}`, 'info');
        
        if (!generateResponse.ok) {
            L(`   ❌ HTTP error: ${generateResponse.status}`, 'error');
            return { success: false, message: `HTTP ${generateResponse.status}` };
        }
        
        const generateText = await generateResponse.text();
        L(`   📥 Raw generate response (first 200 chars): ${generateText.substring(0, 200)}`, 'info');
        
        if (generateText.trim().startsWith('<!DOCTYPE')) {
            L(`   ❌ Received HTML instead of JSON from generate_image_api.php`, 'error');
            return { success: false, message: 'Image API returned HTML' };
        }
        
        let generateResult;
        try {
            generateResult = JSON.parse(generateText);
            L(`   ✅ Generate response parsed successfully`, 'success');
        } catch (e) {
            L(`   ❌ Failed to parse generate response: ${e.message}`, 'error');
            return { success: false, message: 'Generate response parse failed' };
        }
        
        if (generateResult.success) {
            L(`   ✅ Image generated successfully!`, 'success');
            L(`   Filename: ${generateResult.filename}`, 'success');
            return {
                success: true,
                filename: generateResult.filename,
                filepath: generateResult.filepath
            };
        } else {
            L(`   ❌ Generation failed: ${generateResult.message}`, 'error');
            return { success: false, message: generateResult.message };
        }
    } catch (err) {
        L(`   ❌ Error in image generation: ${err.message}`, 'error');
        return { success: false, message: err.message };
    }
}
async function createIdeaScenes() {
    // Get the button
    const createBtn = document.getElementById('ideaCreateScenesBtn');
    
    // Disable the button
    if (createBtn) {
        createBtn.disabled = true;
        createBtn.innerHTML = '⏳ Processing...';
    }
    
    // Hide old progress container
    const progressContainer = document.getElementById('progress_container');
    if (progressContainer) {
        progressContainer.style.display = 'none';
    }
    
    // SHOW PROGRESS MODAL
    showProgressModal();
    updateStepStatus(1, 'pending', 'Creating scenes in database...', 'Initializing...');
    
    // Clear previous logs but keep the container
    const logEl = document.getElementById('realtime_log');
    if (logEl) {
        <?php if ($_SESSION['level'] === 'super'): ?>
        logEl.innerHTML = '<div class="log-entry">[System] Starting scene creation...</div>';
        <?php endif; ?>
    }
    
    // VALIDATE VOICES BEFORE PROCEEDING
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const reelType = document.getElementById('idea_reel_type').value;
    
    if (!hostVoice) {
        alert('Please select a Host Voice before creating scenes');
        document.getElementById('hostVoicePicker').focus();
        if (createBtn) {
            createBtn.disabled = false;
            createBtn.innerHTML = '🎬 Step 2: Create All';
        }
        hideProgressModal();
        return;
    }
    
    if (reelType === 'podcast' && !guestVoice) {
        alert('Please select a Guest Voice for Podcast format');
        document.getElementById('guestVoicePicker').focus();
        if (createBtn) {
            createBtn.disabled = false;
            createBtn.innerHTML = '🎬 Step 2: Create All';
        }
        hideProgressModal();
        return;
    }
    
    const contentArea = document.getElementById('idea_processed_content');
    
    // Get title - from either select or input
    const titleSelect = document.getElementById('idea_title_select');
    const titleInput = document.getElementById('idea_title_input');
    
    let title = '';
    if (titleSelect && titleSelect.value && titleSelect.value !== '__new__' && titleSelect.value !== '') {
        title = titleSelect.value;
    } else if (titleInput && titleInput.style.display !== 'none' && titleInput.value) {
        title = titleInput.value.trim();
    } else if (titleInput) {
        title = titleInput.value.trim();
    }
    
    const langCode = document.getElementById('global_lang_select').value;
    
    if (!title) {
        alert('Please enter a title');
        if (createBtn) {
            createBtn.disabled = false;
            createBtn.innerHTML = '🎬 Step 2: Create All';
        }
        hideProgressModal();
        return;
    }
    
    // Get the stored scenes data
    let scenes = [];
    if (contentArea.dataset.scenes) {
        scenes = JSON.parse(contentArea.dataset.scenes);
        L(`📝 Using ${scenes.length} pre-parsed scenes with prompts and hashtags`, 'info');
    } else {
        // Fallback: parse from content
        const content = contentArea.value;
        scenes = parseScenesFromContent(content);
        L(`⚠️ Parsed ${scenes.length} scenes from content (no stored data)`, 'warning');
    }
    
    if (scenes.length === 0) {
        alert('No scenes found in content');
        if (createBtn) {
            createBtn.disabled = false;
            createBtn.innerHTML = '🎬 Step 2: Create All';
        }
        hideProgressModal();
        return;
    }
    
    // Update overall progress
    document.getElementById('sceneProgress').textContent = `Processing 0/${scenes.length} scenes`;
    
    try {
        // Log what we're sending
        L(`Sending ${scenes.length} scenes to database:`, 'info');
        if (scenes.length > 0) {
            L(`First scene - Text: ${scenes[0].text.substring(0, 30)}...`, 'info');
            L(`First scene - Prompt: ${scenes[0].prompt ? scenes[0].prompt.substring(0, 30) + '...' : 'MISSING'}`, 'info');
            L(`First scene - Hashtags: ${scenes[0].hashtags || 'MISSING'}`, 'info');
        }
        
        await createAllScenes(scenes, title, reelType, langCode, 'idea');
        
    } catch (error) {
        console.error('Error in createIdeaScenes:', error);
        L(`❌ Error: ${error.message}`, 'error');
        alert('Error creating scenes: ' + error.message);
        hideProgressModal();
    } finally {
        // Re-enable the button when done (whether success or error)
        if (createBtn) {
            createBtn.disabled = false;
            createBtn.innerHTML = '🎬 Step 2: Create All';
        }
    }
}

// Progress Modal Functions
function showProgressModal() {
    const modal = document.getElementById('progressModal');
    if (modal) {
        updateStepStatus(1, 'pending', 'Creating scenes', 'Waiting to start...');
        updateStepStatus(2, 'pending', 'Generating audio', 'Waiting to start...');
        updateStepStatus(3, 'pending', 'Assigning media', 'Waiting to start...');
        document.getElementById('overallProgressBar').style.width = '0%';
        document.getElementById('sceneProgress').textContent = 'Scene 0/0';
        const feed = document.getElementById('progressLogFeed');
        if (feed) feed.innerHTML = '';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

// Log a message to the progress modal feed
function logToModal(msg, type = 'info') {
    const feed = document.getElementById('progressLogFeed');
    if (!feed) return;
    const colors = { info:'#334155', success:'#15803d', warning:'#b45309', error:'#dc2626' };
    const icons  = { info:'·', success:'✓', warning:'⚠', error:'✗' };
    const el = document.createElement('div');
    el.style.cssText = `color:${colors[type]||colors.info}; padding:2px 0; line-height:1.5;`;
    el.textContent = `${icons[type]||'·'} ${msg}`;
    feed.appendChild(el);
    feed.scrollTop = feed.scrollHeight;
}

function hideProgressModal() {
    const modal = document.getElementById('progressModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function updateStepStatus(step, status, text, detail) {
    const iconMap = {
        'pending': { icon: '⏳', bg: '#e2e8f0', numberColor: 'white' },
        'active': { icon: '🔄', bg: '#8b5cf6', numberColor: 'white' },
        'complete': { icon: '✅', bg: '#10b981', numberColor: 'white' },
        'error': { icon: '❌', bg: '#ef4444', numberColor: 'white' }
    };
    
    const stepIcon = document.getElementById(`step${step}Icon`);
    const stepText = document.getElementById(`step${step}Text`);
    const stepDetail = document.getElementById(`step${step}Detail`);
    const stepStatus = document.getElementById(`step${step}Status`);
    
    if (stepIcon) stepIcon.style.background = iconMap[status].bg;
    if (stepText) stepText.textContent = text;
    if (stepDetail) stepDetail.textContent = detail;
    if (stepStatus) stepStatus.textContent = iconMap[status].icon;
}

function updateOverallProgress(percent, action, detail, sceneProgress) {
    document.getElementById('overallProgressBar').style.width = percent + '%';
    if (sceneProgress) document.getElementById('sceneProgress').textContent = sceneProgress;
    if (action) logToModal(action + (detail ? ' — ' + detail : ''), 'info');
}

function cancelSceneCreation() {
    if (confirm('Are you sure you want to cancel the scene creation process? This will not delete already created scenes.')) {
        // Set a flag to stop processing
        window.stopSceneCreation = true;
        L('⏹️ Scene creation cancelled by user', 'warning');
        hideProgressModal();
    }
}
async function createScenesInDB(scenes, title, reelType, langCode, source) {
    L('📝 Creating scenes in database with detailed prompts and hashtags...', 'info');
    
    if (!scenes || scenes.length === 0) {
        L('❌ No scenes provided', 'error');
        return null;
    }
    
    // Get the topic from the input field (this is crucial!)
    let topicValue = '';
    if (source === 'idea') {
        topicValue = document.getElementById('idea_topic')?.value.trim() || 'general';
    } else if (source === 'content') {
        topicValue = document.getElementById('content_title')?.value.trim() || 'general';
    } else if (source === 'db') {
        topicValue = document.getElementById('topic_select')?.value || 'general';
    }
    
    L(`Topic being sent: "${topicValue}"`, 'info');
    
    // Get voice settings from global pickers
    const hostVoice = document.getElementById('hostVoicePicker')?.value || '';
    const guestVoice = document.getElementById('guestVoicePicker')?.value || '';
    const rate = document.getElementById('ratePicker')?.value || '1.0';
    
    L(`Host voice: ${hostVoice}, Rate: ${rate}`, 'info');
    
    L(`Preparing to insert ${scenes.length} scenes with detailed data...`, 'info');
    
    // Log first scene details for verification
    if (scenes.length > 0) {
        L(`Sample scene data being sent:`, 'info');
        L(`  TEXT: ${scenes[0].text ? scenes[0].text.substring(0, 50) + '...' : 'MISSING!'}`, 'info');
        L(`  PROMPT: ${scenes[0].prompt ? scenes[0].prompt.substring(0, 50) + '...' : 'MISSING!'}`, 'info');
        L(`  HASHTAGS: ${scenes[0].hashtags || 'MISSING!'}`, 'info');
        L(`  NL_TAGS: ${scenes[0].nl_tags ? scenes[0].nl_tags.substring(0, 80) + '...' : '⚠️ MISSING — column will be empty'}`, 'info');
    }
    
    const formData = new FormData();
    formData.append('action', 'create_scenes_from_content');
    formData.append('title', title);
    formData.append('combined_title', title);
    formData.append('content', JSON.stringify(scenes));
    formData.append('target_lang', langCode);
    formData.append('reel_type', reelType);
    formData.append('topic', topicValue); // THIS IS CRITICAL - sends the actual topic
    
    // Add source-specific fields
    if (source === 'db') {
        const smSelect = document.getElementById('sm_id_select');
        const catSelect = document.getElementById('cat_select');
        
        formData.append('sm_id', smSelect ? smSelect.value : '');
        formData.append('category', catSelect ? catSelect.value : 'free-format');
        formData.append('hook_id', '1');
        formData.append('cta', document.getElementById('db_cta')?.value || '');
    } else if (source === 'idea') {
        formData.append('cta', document.getElementById('idea_cta')?.value || '');
        formData.append('hook_id', '1');
        formData.append('category', 'free-format');
    } else if (source === 'content') {
        formData.append('cta', document.getElementById('content_cta')?.value || '');
        formData.append('hook_id', '1');
        formData.append('category', 'free-format');
    }
    
    try {
        L('Sending scenes to server for database insertion...', 'info');
        L(`Topic being sent: "${topicValue}"`, 'info');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const responseText = await response.text();
        
        // Log response for debugging
        L(`Server response: ${responseText.substring(0, 100)}...`, 'info');
        
        // Check if response is valid JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            L(`❌ Invalid JSON response: ${responseText.substring(0, 200)}`, 'error');
            throw new Error('Server returned invalid JSON');
        }
        
        if (data.success) {
            L(`✅ Created ${data.scene_count} scenes (Podcast ID: ${data.podcast_id})`, 'success');
            L(`✅ Topic key saved: "${data.topic_key}"`, 'success');
            L(`✅ Host voice saved: "${data.host_voice}"`, 'success');
            return data.podcast_id;
        } else {
            throw new Error(data.message || 'Failed to create scenes');
        }
    } catch (err) {
        L(`❌ Error creating scenes: ${err.message}`, 'error');
        console.error('Scene creation error:', err);
        return null;
    }
}
async function generateAllAudio(podcastId, langCode) {
	
	
	
	// Check if cancelled
	if (window.stopSceneCreation) {
		L('⏹️ Process cancelled, stopping audio generation', 'warning');
		return;
	}
    L('🎤 ===== STARTING AUDIO GENERATION =====', 'info');
    L(`📋 Podcast ID: ${podcastId}`, 'info');
    L(`🌐 Language: ${langCode}`, 'info');
    
    // First, verify the podcast exists
    L('🔍 Verifying podcast exists...', 'info');
    try {
        const verifyPodcast = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=check_podcast&podcast_id=${podcastId}`
        });
        const podcastCheck = await verifyPodcast.text();
        L(`Podcast check response: ${podcastCheck.substring(0, 100)}`, 'info');
    } catch (err) {
        L(`⚠️ Podcast check failed: ${err.message}`, 'warning');
    }
    
    // Get scenes
    L('📡 Fetching scenes from database...', 'info');
    const scenes = await getScenes(podcastId);
    
    if (!scenes || scenes.length === 0) {
        L('❌ No scenes found in database', 'error');
        return;
    }
    
    L(`✅ Found ${scenes.length} scenes to process`, 'success');
    
    // Get voice settings
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const rate = document.getElementById('ratePicker').value;
    const reelType = getCurrentReelType();
    
    L(`🎤 Host Voice: ${hostVoice || 'NOT SELECTED'}`, 'info');
    L(`👤 Guest Voice: ${guestVoice || 'NOT SELECTED'}`, 'info');
    L(`⚡ Rate: ${rate}`, 'info');
    L(`🎬 Reel Type: ${reelType}`, 'info');
    
    if (!hostVoice) {
        L('❌ No host voice selected - audio generation aborted', 'error');
        return;
    }
    
    let completed = 0;
    let failed = 0;
    const total = scenes.length;
    
    for (let i = 0; i < scenes.length; i++) {
        const scene = scenes[i];
        const seqNo = i + 1;
        
        L(`\n=== Processing Scene ${seqNo}/${total} ===`, 'info');
        L(`Scene ID: ${scene.id}`, 'info');
        L(`Actor: ${scene.actor || 'host'}`, 'info');
        
        // Determine which voice to use based on actor
        let voiceToUse = hostVoice;
        if (reelType === 'podcast' && scene.actor === 'guest') {
            voiceToUse = guestVoice;
            L(`👤 Using GUEST voice`, 'info');
        }
        
        // Get the scene text (remove any existing pause tags)
        let text = scene.text_contents || '';
        L(`Original text: "${text}"`, 'info');
        
        text = text.replace(/<break[^>]*>/g, '').trim();
        L(`Cleaned text: "${text.substring(0, 50)}${text.length > 50 ? '...' : ''}"`, 'info');
        
        if (!text) {
            L(`⚠️ Scene ${seqNo}: No text content, skipping`, 'warning');
            completed++;
            continue;
        }
        
        // Generate consistent filename: voice-podcastId_seqNo.mp3
        const seqNoPadded = String(seqNo).padStart(3, '0');
     
		const audioFilename = `voice_${podcastId}_${scene.id}_${langCode}.mp3`;
		
        L(`📝 Target filename: ${audioFilename}`, 'info');
        
        // CHECK IF FILE EXISTS AND DELETE IT
        L(`🔍 Checking if ${audioFilename} already exists...`, 'info');
        const checkData = new FormData();
        checkData.append('ajax_action', 'check_audio_file');
        checkData.append('filename', audioFilename);
        
        try {
            const checkResponse = await fetch(window.location.href, { 
                method: 'POST',
                body: checkData
            });
            const checkResult = await checkResponse.json();
            
            if (checkResult.exists) {
                if (checkResult.deleted) {
                    L(`✅ Existing file deleted, will generate fresh copy`, 'success');
                } else {
                    L(`⚠️ File exists but could not be deleted, will overwrite`, 'warning');
                }
            } else {
                L(`✅ No existing file found, generating new`, 'info');
            }
        } catch (err) {
            L(`⚠️ Could not check file existence: ${err.message}`, 'warning');
        }
        
        // Create form data for audio generation with custom filename
        const formData = new FormData();
        formData.append('row_id', scene.id);
        formData.append('text', text);
        formData.append('lang_code', langCode || 'en-US');
        formData.append('voice_id', voiceToUse);
        formData.append('rate', rate);
        formData.append('filename', audioFilename);

        // Route: OpenAI voices → script_gen AJAX handler
        //        Azure voices  → generate_voice.php
        let audioEndpoint, audioPayload;
        if (voiceToUse && voiceToUse.startsWith('openai:')) {
            L(`📡 Sending to OpenAI TTS handler...`, 'info');
            const sgData = new FormData();
            sgData.append('ajax_action', 'generate_scene_audio');
            sgData.append('scene_id', scene.id);
            sgData.append('podcast_id', podcastId);
            sgData.append('seq_no', seqNo);
            sgData.append('lang_code', langCode || 'en');
            sgData.append('voice_id', voiceToUse);
            sgData.append('rate', rate);
            sgData.append('text', text);
            audioEndpoint = window.location.href;
            audioPayload  = sgData;
        } else {
            L(`📡 Sending to generate_voice.php...`, 'info');
            audioEndpoint = 'generate_voice.php';
            audioPayload  = formData;
        }

        try {
            const response = await fetch(audioEndpoint, {
                method: 'POST',
                body: audioPayload
            });
            
            const responseText = await response.text();
            L(`📥 Raw response: ${responseText.substring(0, 100)}...`, 'info');
            
            let data;
            try {
                data = JSON.parse(responseText);
                L(`✅ Response parsed as JSON`, 'success');
            } catch (e) {
                L(`❌ Failed to parse JSON: ${responseText.substring(0, 200)}`, 'error');
                failed++;
                continue;
            }
            
            if (data.success) {
                // Use our filename since we sent it
                L(`✅ Audio generated: ${audioFilename}`, 'success');
                
                // OpenAI handler already updates DB — skip second update
                if (voiceToUse && voiceToUse.startsWith('openai:')) {
                    L(`✅ Database already updated by OpenAI handler`, 'success');
                    completed++;
                    continue;
                }
                
                // Azure: Update database separately
                L(`📝 Updating scene ${scene.id} in database...`, 'info');
                
                const updateData = new FormData();
                updateData.append('ajax_action', 'update_scene_audio');
                updateData.append('scene_id', scene.id);
                updateData.append('audio_file', audioFilename);
                updateData.append('voice_id', voiceToUse);
                updateData.append('voice_rate', rate);
                
                const updateResponse = await fetch(window.location.href, {
                    method: 'POST',
                    body: updateData
                });
                
                const updateText = await updateResponse.text();
                L(`Update response: ${updateText.substring(0, 100)}`, 'info');
                
                try {
                    const updateResult = JSON.parse(updateText);
                    if (updateResult.success) {
                        L(`✅ Database updated for scene ${scene.id}`, 'success');
                        completed++;
                    } else {
                        L(`❌ Database update failed: ${updateResult.message}`, 'error');
                        failed++;
                    }
                } catch (e) {
                    L(`❌ Failed to parse update response`, 'error');
                    failed++;
                }
            } else {
                const errMsg = data.error || data.message || 'Unknown error';
                L(`❌ Audio failed (Scene ${seqNo}): ${errMsg}`, 'error');
                failed++;
            }
        } catch (err) {
            L(`❌ Network Error: ${err.message}`, 'error');
            failed++;
        }
        
        // Update progress
        const percent = Math.round(((completed + failed) / total) * 100);
        document.getElementById('progress_bar').style.width = percent + '%';
        document.getElementById('progress_text').innerHTML = `Audio: ${completed}/${total} successful, ${failed} failed`;
        
        L(`Progress: ${completed} successful, ${failed} failed`, 'info');
        
        // Small delay between generations
        await new Promise(r => setTimeout(r, 500));
    }
    
    L('\n=== AUDIO GENERATION COMPLETE ===', 'info');
    L(`✅ Successful: ${completed}/${total}`, 'success');
    if (failed > 0) {
        L(`❌ Failed: ${failed}/${total}`, 'error');
    }
    
    // Final verification
    L('📊 Performing final verification...', 'info');
    const finalScenes = await getScenes(podcastId);
    const scenesWithAudio = finalScenes.filter(s => s.audio_file && s.audio_file.length > 0).length;
    L(`✅ Final count: ${scenesWithAudio}/${finalScenes.length} scenes have audio files`, 'success');
}

// Override any function that might clear logs
const originalClearLogs = window.clearLogs;
window.clearLogs = function() {
    if (confirm('Clear all logs? They will be permanently deleted.')) {
        document.getElementById('realtime_log').innerHTML = '<div class="log-entry">[System] Logs cleared at ' + new Date().toLocaleTimeString() + '</div>';
    }
};


// Add a fallback direct update function
async function directSceneAudioUpdate(sceneId, filename, voiceId, rate) {
    L(`📝 Direct update for scene ${sceneId}...`, 'info');
    
    const fd = new FormData();
    fd.append('ajax_action', 'update_scene_audio_direct');
    fd.append('scene_id', sceneId);
    fd.append('audio_file', filename);
    fd.append('voice_id', voiceId);
    fd.append('voice_rate', rate);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const data = await response.json();
        return data.success;
    } catch (err) {
        L(`❌ Direct update failed: ${err.message}`, 'error');
        return false;
    }
}

// Helper function to update scene with audio file
async function updateSceneAudio(sceneId, filename, voiceId, rate) {
    const fd = new FormData();
    fd.append('ajax_action', 'update_scene_audio');
    fd.append('scene_id', sceneId);
    fd.append('audio_file', filename);
    fd.append('voice_id', voiceId);
    fd.append('voice_rate', rate);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const data = await response.json();
        return data.success;
    } catch (err) {
        console.error('Failed to update scene audio:', err);
        return false;
    }
}


async function getScenes(podcastId) {
    L(`📡 Fetching scenes for podcast ${podcastId}...`, 'info');
    
    const fd = new FormData();
    fd.append('ajax_action', 'get_scenes');
    fd.append('podcast_id', podcastId);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        // Get the response text first
        const responseText = await response.text();
        
        // Log the first 200 chars for debugging
        L(`📥 Raw response (first 200 chars): ${responseText.substring(0, 200)}`, 'info');
        
        // Check if it's HTML (error page)
        if (responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html')) {
            L('❌ Server returned HTML page - possible PHP error or redirect', 'error');
            
            // Try to extract error message
            const errorMatch = responseText.match(/<b>(?:Fatal error|Warning|Parse error)<\/b>:\s+([^<]+)/i);
            if (errorMatch) {
                L(`🐞 PHP Error: ${errorMatch[1]}`, 'error');
            }
            
            // Check if it's a login redirect
            if (responseText.includes('login.php') || responseText.includes('Sign In')) {
                L('❌ Session expired - please refresh and login again', 'error');
            }
            
            return [];
        }
        
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            L(`❌ Failed to parse JSON: ${e.message}`, 'error');
            L(`Response was: ${responseText.substring(0, 500)}`, 'error');
            return [];
        }
        
        // Check if data is an array (success) or has success property
        if (Array.isArray(data)) {
            L(`✅ Found ${data.length} scenes`, 'success');
            return data;
        } else if (data.success === false) {
            L(`❌ Server error: ${data.message || 'Unknown error'}`, 'error');
            return [];
        } else if (data.error) {
            L(`❌ Error: ${data.error}`, 'error');
            return [];
        } else {
            L(`⚠️ Unexpected response format`, 'warning');
            console.log('Unexpected response:', data);
            return [];
        }
    } catch (err) {
        L(`❌ Error fetching scenes: ${err.message}`, 'error');
        console.error('Fetch error:', err);
        return [];
    }
}


function parseScenesFromContent(content) {
    const scenes = [];
    const sceneBlocks = content.split('[SCENE]').filter(block => block.trim());
    
    for (const block of sceneBlocks) {
        const scene = {};
        
        // Extract TEXT
        const textMatch = block.match(/TEXT:\s*(.*?)(?=PROMPT:|HASHTAGS:|$)/s);
        if (textMatch) {
            scene.text = textMatch[1].trim();
        }
        
        // Extract PROMPT
        const promptMatch = block.match(/PROMPT:\s*(.*?)(?=HASHTAGS:|$)/s);
        if (promptMatch) {
            scene.prompt = promptMatch[1].trim();
        }
        
        // Extract HASHTAGS and format them properly for image matching
        const hashtagsMatch = block.match(/HASHTAGS:\s*(.*?)(?=NL_TAGS:|$)/s);
        if (hashtagsMatch) {
            let hashtags = hashtagsMatch[1].trim();
            
            // Format hashtags to match image_data format
            // Remove any # symbols and extra spaces
            hashtags = hashtags.replace(/#/g, '').trim();
            
            // Split into individual tags
            let tags = hashtags.split(/\s+/);
            
            // Filter and clean tags
            tags = tags.filter(tag => {
                tag = tag.trim();
                // Remove any punctuation
                tag = tag.replace(/[^\w\s-]/g, '');
                return tag.length > 2; // Only keep tags with 3+ characters
            });
            
            // Limit to 3-5 tags
            tags = tags.slice(0, 5);
            
            // Join with spaces (no # symbols)
            scene.hashtags = tags.join(' ');
            
            // Also store as array for debugging
            scene.hashtagsArray = tags;
        }

        // Extract NL_TAGS — natural language phrases for Pexels/stock media search
        const nlTagsMatch2 = block.match(/NL_TAGS:\s*(.*?)$/s);
        if (nlTagsMatch2) {
            scene.nl_tags = nlTagsMatch2[1].trim();
        }
        
        // Determine actor for podcast
        let actor = 'host';
        if (scene.text && scene.text.toLowerCase().startsWith('host:')) {
            actor = 'host';
            scene.text = scene.text.substring(5).trim();
        } else if (scene.text && scene.text.toLowerCase().startsWith('guest:')) {
            actor = 'guest';
            scene.text = scene.text.substring(6).trim();
        }
        scene.actor = actor;
        
        if (scene.text) {
            scenes.push(scene);
        }
    }
    
    return scenes;
}
async function generateSingleAudio(sceneId, podcastId, seqNo, langCode, voiceId, rate, text) {
    const fd = new FormData();
    fd.append('ajax_action', 'generate_scene_audio');
    fd.append('scene_id', sceneId);
    fd.append('podcast_id', podcastId);
    fd.append('seq_no', seqNo);
    fd.append('lang_code', langCode);
    fd.append('voice_id', voiceId);
    fd.append('rate', rate);
    fd.append('text', text);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const data = await response.json();
        
        if (data.success) {
            L(`✅ Audio generated: ${data.filename}`, 'success');
            return true;
        } else {
            L(`❌ Failed: ${data.message}`, 'error');
            return false;
        }
    } catch (err) {
        L(`❌ Error: ${err.message}`, 'error');
        return false;
    }
}

function getCurrentReelType() {
    const activeTab = document.querySelector('.tab.active')?.id;
    
    if (activeTab === 'tab-my-idea') {
        return document.getElementById('idea_reel_type').value;
    } else if (activeTab === 'tab-my-content') {
        return document.getElementById('content_reel_type').value;
    } else if (activeTab === 'tab-database') {
        return document.getElementById('db_reel_type').value;
    }
    
    return 'standard';
}


async function searchImagesByHashtags(hashtags) {
    L(`🔍 Searching for: ${hashtags}`, 'info');
    
    const fd = new FormData();
    fd.append('ajax_action', 'get_image_suggestions');
    fd.append('hashtags', hashtags);
    fd.append('limit', '10');
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const responseText = await response.text();
        
        // Log first 100 chars for debugging
        L(`📥 Response preview: ${responseText.substring(0, 100)}`, 'info');
        
        // Check if response is HTML
        if (responseText.trim().startsWith('<!DOCTYPE')) {
            L('❌ Server returned HTML - possible PHP error', 'error');
            return [];
        }
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            L(`❌ Failed to parse JSON: ${responseText.substring(0, 200)}`, 'error');
            return [];
        }
        
        if (data.success && data.images) {
            L(`✅ Found ${data.images.length} matches`, 'success');
            return data.images;
        }
        
        L(`⚠️ No results found`, 'warning');
        return [];
    } catch (err) {
        L(`❌ Error searching: ${err.message}`, 'error');
        return [];
    }
}
async function assignImageToScene(sceneId, filename) {
    const fd = new FormData();
    fd.append('ajax_action', 'assign_image');
    fd.append('scene_id', sceneId);
    fd.append('image_filename', filename);
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        const data = await response.json();
        return data.success;
    } catch (err) {
        return false;
    }
}
// ========== ADD TRASH ICONS TO DROPDOWNS ==========
function addTrashIconsToDropdowns() {
    // Add to Niche dropdown
    addTrashIconToDropdown('niche_select', 'niche');
    
    // Add to Topic dropdown
    addTrashIconToDropdown('idea_topic_select', 'topic');
    
    // Add to Title dropdown
    addTrashIconToDropdown('sm_id_select', 'title');
}

function addTrashIconToDropdown(selectId, type) {
    const select = document.getElementById(selectId);
    if (!select) return;
    
    // Check if already wrapped
    if (select.parentNode.classList.contains('dropdown-with-delete')) return;
    
    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'dropdown-with-delete';
    
    // Insert wrapper before select
    select.parentNode.insertBefore(wrapper, select);
    
    // Move select into wrapper
    wrapper.appendChild(select);
    
    // Create delete button (just for show - no functionality yet)
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'delete-dropdown-btn';
    deleteBtn.innerHTML = '🗑️';
    deleteBtn.title = `Delete this ${type}`;
    deleteBtn.disabled = true; // Disabled for now - just for visual
    
    wrapper.appendChild(deleteBtn);
}

// ========== TITLE DROPDOWN FUNCTIONS ==========
function handleTitleSelection(select) {
    const input = document.getElementById('idea_title_input');
    if (!input) return;
    // Always sync hidden input with selected value
    input.style.display = 'none';
    input.value = select.value || '';
}

// Function to get the current title value (for use in other functions)
function getCurrentTitle() {
    const select = document.getElementById('idea_title_select');
    const input  = document.getElementById('idea_title_input');
    if (select && select.value) return select.value;
    return input ? input.value : '';
}

// Function to load titles for selected topic
// Function to load titles for selected topic
function loadTitlesForTopic() {
    const topicSelect = document.getElementById('idea_topic_select');
    const topicInput = document.getElementById('idea_topic_input');
    const titleSelect = document.getElementById('idea_title_select');
    const titleInput = document.getElementById('idea_title_input');
    
    // Get the topic ID or name
    let topicId = null;
    let topicName = null;
    
    if (topicSelect && topicSelect.value && topicSelect.value !== '' && topicSelect.value !== '__manual__') {
        // Check if the value is numeric (ID) or text (name)
        if (!isNaN(parseInt(topicSelect.value)) && isFinite(topicSelect.value)) {
            topicId = topicSelect.value;
            console.log('Topic ID found:', topicId);
        } else {
            topicName = topicSelect.value;
            console.log('Topic name found:', topicName);
        }
    } else if (topicInput && topicInput.value && topicInput.style.display !== 'none') {
        topicName = topicInput.value;
        console.log('Manual topic found:', topicName);
    } else {
        console.log('No topic selected');
        return;
    }
    
    // Show loading in title dropdown
    if (titleSelect) {
        titleSelect.innerHTML = '<option value="">Loading titles...</option>';
        titleSelect.disabled = true;
    }
    
    // Prepare form data
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'get_titles_for_topic_dropdown');
    if (topicId) {
        formData.append('topic_id', topicId);
    }
    if (topicName) {
        formData.append('topic_name', topicName);
    }
    
    // Make AJAX call
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(response => response.text()) // Get as text first to debug
    .then(text => {
        console.log('Raw response:', text.substring(0, 200)); // Log first 200 chars
        
        // Try to parse as JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Failed to parse JSON:', e);
            console.error('Response was:', text);
            
            // Check if it's an HTML error page
            if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                // Extract error message if possible
                const errorMatch = text.match(/<b>(?:Fatal error|Warning|Parse error)<\/b>:\s+([^<]+)/i);
                if (errorMatch) {
                    throw new Error('PHP Error: ' + errorMatch[1]);
                } else {
                    throw new Error('Server returned HTML instead of JSON. Possible PHP error or session expiry.');
                }
            }
            
            throw new Error('Invalid JSON response from server');
        }
    })
    .then(data => {
        if (titleSelect) {
            titleSelect.disabled = false;
        }
        
        if (data.success && data.titles && data.titles.length > 0) {
            // Build dropdown options
            let options = '<option value="">-- Select a title --</option>';
            
            data.titles.forEach(title => {
                options += `<option value="${escapeHtml(title.title)}">${escapeHtml(title.title)}</option>`;
            });
            
            
            
            titleSelect.innerHTML = options;
            
            // Select the first title by default
            if (data.titles.length > 0) {
                titleSelect.value = data.titles[0].title;
                titleInput.style.display = 'none';
                titleInput.value = data.titles[0].title;
            }
            
            L(`✅ Loaded ${data.titles.length} titles for this topic`, 'success');
        } else {
            // No titles found, show empty dropdown with custom option
            titleSelect.innerHTML = '<option value="">-- Select a title --</option>';
            titleSelect.value = '';
            
            // Show the input field for custom title
            titleInput.style.display = 'block';
            titleInput.value = '';
            titleInput.focus();
            
            L(`ℹ️ No saved titles found for this topic`, 'info');
        }
    })
    .catch(err => {
        console.error('Error loading titles:', err);
        
        if (titleSelect) {
            titleSelect.disabled = false;
            titleSelect.innerHTML = '<option value="">-- Select a title --</option>';
        }
        
        // Show the input field as fallback
        if (titleInput) {
            titleInput.style.display = 'block';
            titleInput.value = '';
        }
        
        L(`❌ Error loading titles: ${err.message}`, 'error');
    });
}

// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


// ========== IMAGE SELECTOR MODAL ==========
function openImageSelector(sceneId, hashtags) {
    currentImageSelectorSceneId = sceneId;
    currentImageSelectorHashtags = hashtags;
    
    document.getElementById('imageSelectorSceneId').textContent = sceneId;
    document.getElementById('imageHashtags').textContent = hashtags;
    
    searchAndDisplayImages(hashtags);
    
    document.getElementById('imageSelectorModal').style.display = 'flex';
}

function closeImageSelector() {
    document.getElementById('imageSelectorModal').style.display = 'none';
}

async function searchAndDisplayImages(hashtags) {
    const grid = document.getElementById('imageGrid');
    grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px;">Loading images...</div>';
    
    const images = await searchImagesByHashtags(hashtags);
    
    if (images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted);">No images found. Try different hashtags.</div>';
        return;
    }
    
    grid.innerHTML = '';
    
    images.forEach(image => {
        const card = document.createElement('div');
        card.className = 'image-card';
        card.onclick = () => selectImage(image.filename, image.url);
        
        card.innerHTML = `
            <img src="${image.url}" alt="Image" onerror="this.src='placeholder.jpg'">
            <div class="image-hashtags">${image.hashtags.substring(0, 50)}${image.hashtags.length > 50 ? '...' : ''}</div>
        `;
        
        grid.appendChild(card);
    });
}

async function selectImage(filename, url) {
    if (!currentImageSelectorSceneId) return;
    
    const assigned = await assignImageToScene(currentImageSelectorSceneId, filename);
    
    if (assigned) {
        L(`✅ Image assigned to scene ${currentImageSelectorSceneId}`, 'success');
        closeImageSelector();
    } else {
        alert('Failed to assign image');
    }
}

// ========== SUCCESS MODAL ==========
// ========== SUCCESS MODAL ==========
function showSuccessModal(podcastId) {
    // Log completion
    L('🎉 ===== PROCESS COMPLETE =====', 'success');
    L(`✅ Podcast #${podcastId} has been fully processed:`, 'success');
    L(`   ✓ Scenes created`, 'success');
    L(`   ✓ Audio generated`, 'success');
    L(`   ✓ Images assigned`, 'success');
    
    // Create and show a visual modal
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'modal-overlay';
    modalOverlay.id = 'successModalOverlay';
    modalOverlay.style.display = 'flex';
    
    const modalContent = document.createElement('div');
    modalContent.className = 'success-card';
    modalContent.style.maxWidth = '500px';
    modalContent.style.textAlign = 'left';
    
    // Get current date/time
    const now = new Date();
    const dateStr = now.toLocaleDateString();
    const timeStr = now.toLocaleTimeString();
    
    modalContent.innerHTML = `
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="font-size: 60px; margin-bottom: 10px;">🎉</div>
            <h2 style="color: var(--success); margin: 0;">Success!</h2>
            <p style="color: var(--muted); margin-top: 5px;">Podcast #${podcastId} created successfully</p>
        </div>
        
        <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <div style="flex: 1;">
                    <div style="font-size: 13px; color: #166534; font-weight: 600;">✓ SCENES CREATED</div>
                    <div style="font-size: 11px; color: #166534; opacity: 0.8;">${dateStr} ${timeStr}</div>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 13px; color: #166534; font-weight: 600;">✓ AUDIO GENERATED</div>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 13px; color: #166534; font-weight: 600;">✓ IMAGES ASSIGNED</div>
                </div>
            </div>
        </div>
        
        <p style="color: var(--dark-blue); font-weight: 600; margin-bottom: 15px;">📊 What would you like to do next?</p>
        
        <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
            <a href="videomaker.php?podcast_id=${podcastId}" class="btn btn-primary" style="text-decoration: none; text-align: center; background: linear-gradient(135deg, #8b5cf6, #6366f1);">
                🖼️ Proceed to render video
            </a>
            
            <button class="btn btn-secondary" onclick="location.reload()" style="width: 100%;">
                🔄 Start New Script
            </button>
        </div>
        
        
    `;
    
    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);
    
    // Hide progress but KEEP logs visible
    document.getElementById('progress_container').style.display = 'none';
    
    // Enable any buttons that were disabled
    document.querySelectorAll('.btn').forEach(btn => btn.disabled = false);
}

// Add function to close the modal
function closeSuccessModal() {
    const modal = document.getElementById('successModalOverlay');
    if (modal) {
        modal.remove();
    }
}

// Optional: Auto-close after 10 seconds
function showSuccessModalWithTimeout(podcastId) {
    showSuccessModal(podcastId);
    setTimeout(closeSuccessModal, 10000);
}
// ========== WORD COUNT UPDATE FUNCTIONS ==========
function updateIdeaWordCount() {
    const duration = document.getElementById('idea_duration').value;
    const wordCount = Math.round(duration * 2.5);
    const wordSpan = document.getElementById('idea_word_count');
    if (wordSpan) wordSpan.textContent = wordCount;
}

function updateContentWordCount() {
    const duration = document.getElementById('content_duration').value;
    const wordCount = Math.round(duration * 2.5);
    const wordSpan = document.getElementById('content_word_count');
    if (wordSpan) wordSpan.textContent = wordCount;
}

function updateDbWordCount() {
    const duration = document.getElementById('db_duration').value;
    const wordCount = Math.round(duration * 2.5);
    const wordSpan = document.getElementById('db_word_count');
    if (wordSpan) wordSpan.textContent = wordCount;
}
// ========== UTILITY FUNCTIONS ==========
function showStatus(message, type = 'info') {
    const status = document.getElementById('status_msg');
    status.style.display = 'block';
    status.innerHTML = message;
    
    if (type === 'success') {
        status.style.background = '#dcfce7';
        status.style.color = '#166534';
    } else if (type === 'error') {
        status.style.background = '#fee2e2';
        status.style.color = '#991b1b';
    } else {
        status.style.background = '#fef3c7';
        status.style.color = '#92400e';
    }
}

// ========== TOPIC MANAGEMENT ==========

function getCurrentTopic() {
    const select = document.getElementById('idea_topic_select');
    const input = document.getElementById('idea_topic_input');
    
    if (select && select.value && select.value !== '__manual__') {
        return select.value;
    }
    return input ? input.value : '';
}

// Topic Modal Functions
// ========== TOPIC MODAL FUNCTIONS ==========
function openTopicModal() {
    console.log('===== OPEN TOPIC MODAL DEBUG =====');
    
    const modal = document.getElementById('topicModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('topicStep1').style.display = 'block';
        document.getElementById('topicStep2').style.display = 'none';
        
        // Get the currently selected niche from the main form
        const mainNicheSelect = document.getElementById('niche_select');
        const mainNicheInput = document.getElementById('new_niche_input');
        
        console.log('Main Niche Select element:', mainNicheSelect);
        if (mainNicheSelect) {
            console.log('Main Niche Select value:', mainNicheSelect.value);
            console.log('Main Niche Select selectedIndex:', mainNicheSelect.selectedIndex);
            if (mainNicheSelect.selectedIndex >= 0) {
                console.log('Main Niche Select selected option text:', mainNicheSelect.options[mainNicheSelect.selectedIndex]?.text);
                console.log('Main Niche Select selected option value:', mainNicheSelect.options[mainNicheSelect.selectedIndex]?.value);
            }
        }
        
        console.log('Main Niche Input element:', mainNicheInput);
        if (mainNicheInput) {
            console.log('Main Niche Input value:', mainNicheInput.value);
        }
        
        const nicheSelect = document.getElementById('topicNicheSelect');
        const nicheInput = document.getElementById('topicNicheInput');
        
        console.log('Topic Modal Niche Select:', nicheSelect);
        console.log('Topic Modal Niche Input:', nicheInput);
        
        let selectedNicheId = null;
        let selectedNicheName = '';
        
        // Determine which niche is selected
        if (mainNicheSelect && mainNicheSelect.value && mainNicheSelect.value !== '__new__') {
            selectedNicheId = mainNicheSelect.value;
            const selectedOption = mainNicheSelect.options[mainNicheSelect.selectedIndex];
            selectedNicheName = selectedOption ? selectedOption.text : '';
            console.log('✅ Found selected niche ID:', selectedNicheId);
            console.log('✅ Found selected niche name:', selectedNicheName);
        } else if (mainNicheInput && mainNicheInput.value) {
            selectedNicheName = mainNicheInput.value;
            console.log('✅ Found custom niche name:', selectedNicheName);
        } else {
            console.log('❌ No niche selected in main form');
        }
        
        // Store the selected niche info for later use
        window.currentNicheForTopics = {
            id: selectedNicheId,
            name: selectedNicheName
        };
        
        console.log('Stored currentNicheForTopics:', window.currentNicheForTopics);
        
        // Pre-fill the modal with this niche
        if (nicheSelect) {
            console.log('Populating modal niche select...');
            console.log('Modal niche select has', nicheSelect.options.length, 'options');
            
            if (selectedNicheId) {
                // Try to select this niche in the dropdown
                let found = false;
                for (let i = 0; i < nicheSelect.options.length; i++) {
                    console.log(`Option ${i}: value=${nicheSelect.options[i].value}, text=${nicheSelect.options[i].text}, data-name=${nicheSelect.options[i].getAttribute('data-name')}`);
                    
                    if (nicheSelect.options[i].value == selectedNicheId) {
                        nicheSelect.selectedIndex = i;
                        found = true;
                        console.log(`✅ Found matching option at index ${i}`);
                        break;
                    }
                }
                
                if (found) {
                    nicheInput.style.display = 'none';
                    console.log('Matched existing niche, hiding input');
                } else {
                    nicheSelect.value = '';
                    nicheInput.value = selectedNicheName;
                    nicheInput.style.display = 'block';
                    console.log('No match found, showing input with value:', selectedNicheName);
                }
            } else {
                nicheSelect.value = '';
                nicheInput.value = selectedNicheName;
                nicheInput.style.display = 'block';
                console.log('No niche ID, showing input with value:', selectedNicheName);
            }
        } else if (nicheInput) {
            nicheInput.value = selectedNicheName;
            console.log('No select element, setting input value to:', selectedNicheName);
        }
        
        document.getElementById('topicLoading').style.display = 'none';
        document.getElementById('generateTopicsBtn').disabled = false;
        
        console.log('===== OPEN TOPIC MODAL COMPLETE =====');
    } else {
        console.log('❌ Topic modal element not found');
    }
}

function handleTopicNicheSelection(select) {
    const input = document.getElementById('topicNicheInput');
    if (select.value === '__new__') {
        input.style.display = 'block';
        input.value = '';
        input.focus();
    } else {
        input.style.display = 'none';
        if (select.value) {
            // Get the selected niche name from the option's data attribute or text
            const selectedOption = select.options[select.selectedIndex];
            input.value = selectedOption ? selectedOption.getAttribute('data-name') || selectedOption.text : '';
        }
    }
}

function closeTopicModal() {
    document.getElementById('topicModal').style.display = 'none';
}

function handleTopicSelection(select) {
    const input = document.getElementById('idea_topic_input');
    if (input) {
        input.style.display = 'none';
        input.value = select.value;
    }
    if (select.value) loadTitlesForTopic();
}

function getCurrentTopic() {
    const select = document.getElementById('idea_topic_select');
    const input = document.getElementById('idea_topic_input');
    
    if (select && select.value && select.value !== '__manual__') {
        return select.value;
    }
    return input ? input.value : '';
}



async function generateTopicIdeas() {
    // Get niche from either select or input
    const nicheSelect = document.getElementById('topicNicheSelect');
    const nicheInput = document.getElementById('topicNicheInput');
    
    let nicheName = '';
    let nicheId = null;
    
    if (nicheSelect && nicheSelect.value && nicheSelect.value !== '__new__') {
        // Using existing niche
        const selectedOption = nicheSelect.options[nicheSelect.selectedIndex];
        nicheName = selectedOption ? (selectedOption.getAttribute('data-name') || selectedOption.text) : '';
        nicheId = nicheSelect.value;
    } else {
        // Using new niche input
        nicheName = nicheInput ? nicheInput.value.trim() : '';
    }
    
    if (!nicheName) {
        alert('Please select or enter a niche/profession');
        return;
    }
    
    // Show loading
    document.getElementById('topicLoading').style.display = 'block';
    document.getElementById('generateTopicsBtn').disabled = true;
    
    L(`🤖 Generating micro-niche topic ideas for: ${nicheName}`, 'info');
    
    const prompt = `You are an expert content strategist.

Generate 20 SHORT, SPECIFIC topic ideas within the niche: "${nicheName}".

RULES — strictly follow these:
1. Each topic must be 2-4 words MAXIMUM. No exceptions.
2. NO descriptions, NO parentheses, NO explanations.
3. Topics should be specific problems, goals or audience segments.
4. Think like video content categories, not titles.

Good examples for "Hypnotherapy": Anxiety Relief, Sleep Disorders, Weight Loss, Nail Biting, Lack of Confidence, Phobia Treatment, Stress Management, Quit Smoking, Pain Management, Past Trauma
Good examples for "Real Estate": First-Time Buyers, Luxury Homes, Property Investment, Rental Income, Home Staging, Market Analysis, Eco-Friendly Homes, Downsizing Seniors, Commercial Properties, Foreclosure Buying

Return ONLY a valid JSON array of short strings.
Format: ["Topic 1", "Topic 2", "Topic 3", ...]

Generate 20 short topics for "${nicheName}" now:`;
    
    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Hide loading
        document.getElementById('topicLoading').style.display = 'none';
        document.getElementById('generateTopicsBtn').disabled = false;
        
        if (data.success) {
            let topics = [];
            
            // Try to parse the content
            if (data.content) {
                try {
                    // First try to parse as JSON
                    topics = JSON.parse(data.content);
                    console.log('Parsed as JSON:', topics);
                } catch (e) {
                    console.log('Not valid JSON, trying text parsing');
                    // If not JSON, try to extract topics from text
                    const lines = data.content.split('\n')
                        .map(line => line.trim())
                        .filter(line => line.length > 0);
                    
                    // Remove any numbering or bullet points
                    topics = lines.map(line => {
                        return line.replace(/^[\d\-*•]+\.?\s*/, '').trim();
                    }).filter(topic => topic.length > 0);
                }
            }
            
            // Ensure topics is an array
            if (!Array.isArray(topics)) {
                console.log('Topics is not an array, converting');
                topics = [String(topics)];
            }
            
            // Filter out any empty topics and strip parenthetical descriptions
            topics = topics
                .filter(topic => topic && topic.trim().length > 0)
                .map(topic => {
                    // Remove anything in parentheses e.g. "Eco Homes (for green buyers)"
                    topic = topic.replace(/\s*\(.*?\)\s*/g, '').trim();
                    // Remove leading bullets/numbers
                    topic = topic.replace(/^[\d\-*•]+\.?\s*/, '').trim();
                    return topic;
                })
                .filter(topic => topic.length > 0);
            
            if (topics.length === 0) {
                throw new Error('No topics could be extracted from the response');
            }
            
            // Store niche info for later use
            window.currentTopicNiche = {
                id: nicheId,
                name: nicheName
            };
            
            // Convert topics to the format expected by displayTopicList
            const topicsWithStatus = topics.map(topic => ({
                text: topic,
                isDuplicate: false
            }));
            
            // Use the EDITABLE version of displayTopicList
            displayTopicList(topicsWithStatus, nicheName);
            
            L(`✅ Generated ${topics.length} micro-niche ideas for ${nicheName}`, 'success');
        } else {
            throw new Error(data.message || 'Generation failed');
        }
    } catch (err) {
        // Hide loading
        document.getElementById('topicLoading').style.display = 'none';
        document.getElementById('generateTopicsBtn').disabled = false;
        
        console.error('Error in generateTopicIdeas:', err);
        L(`❌ Error generating topics: ${err.message}`, 'error');
        alert('Error generating topics: ' + err.message);
    }
}
// Simplified toggleSelectAll function
function toggleSelectAllTopics(checkbox) {
    const checkboxes = document.querySelectorAll('#topicList input[type="checkbox"]');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    const selectedCount = document.querySelectorAll('#topicList input[type="checkbox"]:checked').length;
    const countSpan = document.getElementById('selectedTopicsCount');
    if (countSpan) {
        countSpan.textContent = `${selectedCount} selected`;
    }
}
async function checkTopicsForDuplicates(topics, nicheName) {
    console.log('checkTopicsForDuplicates called with:', topics, 'for niche:', nicheName);
    
    // Safety check - if topics is undefined or not an array, use empty array
    if (!topics) {
        console.error('checkTopicsForDuplicates: topics is undefined');
        topics = [];
    }
    
    if (!Array.isArray(topics)) {
        console.error('checkTopicsForDuplicates: topics is not an array', topics);
        topics = [String(topics)].filter(t => t && t !== 'undefined');
    }
    
    console.log('Checking duplicates for', topics.length, 'topics in niche:', nicheName);
    
    // Get existing topics from the dropdown that belong to this niche
    const select = document.getElementById('idea_topic_select');
    const existingTopics = [];
    
    if (select) {
        for (let i = 0; i < select.options.length; i++) {
            const option = select.options[i];
            // Check if option belongs to the correct niche optgroup
            if (option.value && option.value !== '__manual__') {
                const parentOptgroup = option.parentNode;
                if (parentOptgroup && parentOptgroup.tagName === 'OPTGROUP' && parentOptgroup.label === nicheName) {
                    existingTopics.push(option.value.toLowerCase().trim());
                }
            }
        }
    }
    
    console.log('Found', existingTopics.length, 'existing topics in this niche');
    
    // Mark duplicates
    const topicsWithStatus = topics.map(topic => {
        if (!topic) return { text: '', isDuplicate: false };
        
        const isDuplicate = existingTopics.includes(topic.toLowerCase().trim());
        return {
            text: topic,
            isDuplicate: isDuplicate
        };
    }).filter(item => item.text); // Remove empty topics
    
    // Call display function
    displayTopicList(topicsWithStatus, nicheName);
}
// Add this temporarily for debugging
function testTopicGeneration() {
    console.log('Testing topic generation...');
    const nicheSelect = document.getElementById('topicNicheSelect');
    const nicheInput = document.getElementById('topicNicheInput');
    
    console.log('Niche Select:', nicheSelect ? nicheSelect.value : 'not found');
    console.log('Niche Input:', nicheInput ? nicheInput.value : 'not found');
    
    // Manually trigger the generation
    generateTopicIdeas();
}

function displayTopicList(topicsWithStatus, nicheName) {
    const listDiv = document.getElementById('topicList');
    if (!listDiv) {
        console.error('Topic list element not found');
        return;
    }
    
    listDiv.innerHTML = '';
    
    // Display the niche name
    const nicheDisplay = document.getElementById('selectedNicheDisplay');
    if (nicheDisplay) {
        nicheDisplay.textContent = nicheName || 'Selected Niche';
    }
    
    // Check if topicsWithStatus is valid
    if (!topicsWithStatus || !Array.isArray(topicsWithStatus) || topicsWithStatus.length === 0) {
        listDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--muted);">No topics to display</div>';
        return;
    }
    
    topicsWithStatus.forEach((item, index) => {
        if (!item || !item.text) return;

        const topicId = `topic_${Date.now()}_${index}`;
        const itemDiv = document.createElement('div');
        itemDiv.style.cssText = `display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid #f1f5f9; background:${item.isDuplicate ? '#f8fafc' : 'white'}; opacity:${item.isDuplicate ? '0.6' : '1'};`;

        // Checkbox
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.id = topicId;
        checkbox.value = index;
        checkbox.disabled = !!item.isDuplicate;
        checkbox.style.cssText = 'width:18px; height:18px; flex-shrink:0; accent-color:#8b5cf6; cursor:pointer;';
        checkbox.onchange = updateSelectedTopicsCount;

        // Editable text
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'topic-edit-input';
        input.setAttribute('data-index', index);
        input.value = escapeHtml(item.text);
        input.disabled = !!item.isDuplicate;
        input.style.cssText = 'flex:1; min-width:0; border:none; background:transparent; font-size:14px; color:#0f2a44; padding:0; outline:none;';
        input.onfocus = function() { this.style.background = '#eff6ff'; this.style.borderRadius = '4px'; this.style.padding = '2px 6px'; };
        input.onblur  = function() { this.style.background = 'transparent'; this.style.padding = '0'; };

        // Duplicate badge
        if (item.isDuplicate) {
            const badge = document.createElement('span');
            badge.textContent = 'exists';
            badge.style.cssText = 'font-size:10px; background:#fef2f2; color:#ef4444; padding:2px 6px; border-radius:10px; flex-shrink:0;';
            itemDiv.appendChild(checkbox);
            itemDiv.appendChild(input);
            itemDiv.appendChild(badge);
        } else {
            // Delete button
            const deleteBtn = document.createElement('button');
            deleteBtn.innerHTML = '×';
            deleteBtn.style.cssText = 'background:none; border:none; color:#94a3b8; cursor:pointer; font-size:18px; padding:0 4px; flex-shrink:0; line-height:1;';
            deleteBtn.onmouseover = function() { this.style.color = '#ef4444'; };
            deleteBtn.onmouseout  = function() { this.style.color = '#94a3b8'; };
            deleteBtn.onclick = function() { deleteTopicFromList(this, index); };
            itemDiv.appendChild(checkbox);
            itemDiv.appendChild(input);
            itemDiv.appendChild(deleteBtn);
        }

        listDiv.appendChild(itemDiv);
    });
    
    document.getElementById('topicStep1').style.display = 'none';
    document.getElementById('topicStep2').style.display = 'flex';
    updateSelectedTopicsCount();
}

function changeNiche() {
    document.getElementById('topicStep1').style.display = 'flex';
    document.getElementById('topicStep2').style.display = 'none';
}

function areAllTopicsDisabled() {
    const checkboxes = document.querySelectorAll('#topicList input[type="checkbox"]');
    if (checkboxes.length === 0) return true;
    
    for (let cb of checkboxes) {
        if (!cb.disabled) return false;
    }
    return true;
}

function toggleSelectAllTopics(checkbox) {
    const checkboxes = document.querySelectorAll('#topicList input[type="checkbox"]:not([disabled])');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedTopicsCount();
}

function updateSelectedTopicsCount() {
    const selected = document.querySelectorAll('#topicList input[type="checkbox"]:checked').length;
    const countSpan = document.getElementById('selectedTopicsCount');
    if (countSpan) {
        countSpan.textContent = `${selected} selected`;
    }
    
    // Update select all checkbox
    const enabledCheckboxes = document.querySelectorAll('#topicList input[type="checkbox"]:not([disabled])');
    const checkedCheckboxes = document.querySelectorAll('#topicList input[type="checkbox"]:checked');
    const selectAll = document.getElementById('selectAllTopics');
    
    if (selectAll && enabledCheckboxes.length > 0) {
        selectAll.checked = checkedCheckboxes.length === enabledCheckboxes.length;
        selectAll.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < enabledCheckboxes.length;
    }
}

function generateMoreTopics() {
    // Go back to step 1 to generate more ideas, but keep the niche pre-filled
    document.getElementById('topicStep1').style.display = 'block';
    document.getElementById('topicStep2').style.display = 'none';
    document.getElementById('topicLoading').style.display = 'none';
    document.getElementById('generateTopicsBtn').disabled = false;
    
    // Don't clear the niche input - keep it for generating more
    const niche = window.currentTopicNiche;
    if (niche) {
        const nicheSelect = document.getElementById('topicNicheSelect');
        const nicheInput = document.getElementById('topicNicheInput');
        
        if (nicheSelect && niche.id) {
            // Select the existing niche
            nicheSelect.value = niche.id;
            if (nicheSelect.value === '__new__') {
                nicheInput.value = niche.name;
                nicheInput.style.display = 'block';
            } else {
                nicheInput.style.display = 'none';
            }
        } else if (nicheInput) {
            nicheInput.value = niche.name;
        }
    }
}

async function saveSelectedTopics() {
    // Get all checked checkboxes
    const checkboxes = document.querySelectorAll('#topicList input[type="checkbox"]:checked');
    
    if (checkboxes.length === 0) {
        alert('Please select at least one topic');
        return;
    }
    
    // Get the EDITED values from the input fields
    const selectedTopics = [];
    checkboxes.forEach(cb => {
        const index = cb.value; // This is the index number
        const input = document.querySelector(`.topic-edit-input[data-index="${index}"]`);
        if (input && input.value.trim()) {
            selectedTopics.push(input.value.trim());
        } else {
            console.log('No input found for index:', index);
        }
    });
    
    if (selectedTopics.length === 0) {
        alert('No valid topics to save');
        return;
    }
    
    const niche = window.currentTopicNiche;
    if (!niche || !niche.name) {
        alert('Niche information missing');
        return;
    }
    
    L(`💾 Saving ${selectedTopics.length} edited topics for niche "${niche.name}"...`, 'info');
    console.log('Selected topics to save:', selectedTopics);
    console.log('Niche info:', niche);
    
    // Show saving indicator
    const saveBtn = event.target;
    const originalText = saveBtn.textContent;
    saveBtn.textContent = 'Saving...';
    saveBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('ajax_action', 'save_user_topics_with_niche');
    formData.append('niche', JSON.stringify(niche));
    formData.append('topics', JSON.stringify(selectedTopics));
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            throw new Error('Invalid server response');
        }
        
        if (data.success) {
            L(`✅ ${data.saved_count} topics saved for niche "${niche.name}"`, 'success');
            
            // Show success message
            alert(`Successfully saved ${data.saved_count} topics for "${niche.name}"!`);
            
            // Ask if user wants to add more
            if (confirm('Topics saved! Do you want to generate more topic ideas for this niche?')) {
                generateMoreTopics();
            } else {
                closeTopicModal();
                refreshTopicDropdown();
            }
        } else {
            throw new Error(data.message || 'Failed to save topics');
        }
    } catch (err) {
        L(`❌ Error saving topics: ${err.message}`, 'error');
        alert('Error saving topics: ' + err.message);
    } finally {
        // Restore button
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
    }
}
function refreshTopicDropdown() {
    // Reload page but return to Content Bank tab
    const url = new URL(window.location.href);
    url.searchParams.set('subtab', 'bank');
    location.href = url.toString();
}

// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// ========== TITLE MANAGEMENT ==========


// ========== TITLE MODAL FUNCTIONS ==========
// ========== TITLE MODAL FUNCTIONS ==========
function openTitleModal() {
    console.log('openTitleModal called');
    const topic = getCurrentTopic();
    const niche = getCurrentNiche();
    
    if (!topic) {
        alert('Please select or enter a topic first');
        return;
    }
    
    if (!niche) {
        alert('Please select or enter a niche first');
        return;
    }
    
    // Get topic ID - THIS IS CRITICAL
    let topicId = null;
    const topicSelect = document.getElementById('idea_topic_select');
    
    if (topicSelect && topicSelect.value && topicSelect.value !== '__manual__') {
        // The value might be the topic name or ID - we need to find the ID
        // For now, let's try to get it from the selected option's data attribute
        const selectedOption = topicSelect.options[topicSelect.selectedIndex];
        
        // If the value is numeric, it's probably the ID
        if (!isNaN(parseInt(topicSelect.value)) && isFinite(topicSelect.value)) {
            topicId = topicSelect.value;
            console.log('Topic ID from select value:', topicId);
        } else {
            // Otherwise, we need to get the ID from the database
            // For now, we'll set it to null and let the server find it by name
            topicId = null;
            console.log('Topic ID not found in select, will lookup by name');
        }
    }
    
    // Store current context with ALL needed info
    window.currentTitleContext = {
        nicheName: niche,
        topicName: topic,
        topicId: topicId,
        nicheId: getCurrentNicheId()
    };
    
    console.log('Current title context:', window.currentTitleContext);
    
    // Display the topic in modal
    const topicDisplay = document.getElementById('titleSelectedTopic');
    if (topicDisplay) {
        topicDisplay.textContent = topic;
    }
    
    // Show modal
    const modal = document.getElementById('titleModal');
    if (modal) {
        modal.style.display = 'flex';
    }
    
    const step1 = document.getElementById('titleStep1');
    const step2 = document.getElementById('titleStep2');
    
    if (step1) step1.style.display = 'flex';
    if (step2) step2.style.display = 'none';
}

function closeTitleModal() {
    const modal = document.getElementById('titleModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function getCurrentNicheId() {
    const nicheSelect = document.getElementById('niche_select');
    if (nicheSelect && nicheSelect.value && nicheSelect.value !== '__new__') {
        return nicheSelect.value;
    }
    return null;
}



function displayTitleList(titles, topicName) {
    const listDiv = document.getElementById('titleList');
    if (!listDiv) return;

    listDiv.innerHTML = '';

    if (!titles || titles.length === 0) {
        listDiv.innerHTML = '<div style="padding:20px; text-align:center; color:#64748b;">No titles to display</div>';
        return;
    }

    titles.forEach((item, index) => {
        if (!item || !item.title) return;

        const titleId = `title_${index}`;
        const row = document.createElement('div');
        row.className = 'title-item';
        row.style.cssText = 'display:flex; align-items:center; gap:10px; padding:11px 16px; border-bottom:1px solid #f1f5f9;';

        // Checkbox
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'title-checkbox';
        cb.setAttribute('data-index', index);
        cb.style.cssText = 'width:18px; height:18px; flex-shrink:0; accent-color:#0f2a44; cursor:pointer;';
        cb.onchange = updateSelectedTitlesCount;

        // Editable title text
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'title-edit-input';
        inp.setAttribute('data-index', index);
        inp.value = item.title;
        inp.style.cssText = 'flex:1; min-width:0; border:none; background:transparent; font-size:14px; color:#0f2a44; padding:0; outline:none;';
        inp.onfocus = function() { this.style.background='#eff6ff'; this.style.borderRadius='4px'; this.style.padding='2px 6px'; };
        inp.onblur  = function() { this.style.background='transparent'; this.style.padding='0'; };

        // Delete button
        const del = document.createElement('button');
        del.innerHTML = '×';
        del.style.cssText = 'background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; padding:0 4px; line-height:1; flex-shrink:0;';
        del.onmouseover = function() { this.style.color='#ef4444'; };
        del.onmouseout  = function() { this.style.color='#94a3b8'; };
        del.onclick = function() { deleteTitleFromList(this, index); };

        row.appendChild(cb);
        row.appendChild(inp);
        row.appendChild(del);
        listDiv.appendChild(row);
    });

    updateSelectedTitlesCount();
}

function deleteTitleFromList(button, index) {
    if (confirm('Are you sure you want to remove this title from the list?')) {
        const titleItem = button.closest('.title-item');
        if (titleItem) {
            titleItem.remove();
        }
        
        // Update counts
        const remainingTitles = document.querySelectorAll('.title-item').length;
        const titleCountEl = document.getElementById('titleCount');
        if (titleCountEl) {
            titleCountEl.textContent = remainingTitles;
        }
        updateSelectedTitlesCount();
        
        // Remove from stored data
        if (window.currentTitleData && window.currentTitleData.titles) {
            window.currentTitleData.titles.splice(index, 1);
        }
        
        L(`🗑️ Title removed from list`, 'info');
    }
}

function toggleSelectAllTitles(checkbox) {
    const checkboxes = document.querySelectorAll('.title-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedTitlesCount();
}

function updateSelectedTitlesCount() {
    const selected = document.querySelectorAll('.title-checkbox:checked').length;
    const countSpan = document.getElementById('selectedTitlesCount');
    if (countSpan) {
        countSpan.textContent = `${selected} selected`;
    }
    
    const total = document.querySelectorAll('.title-checkbox').length;
    const selectAll = document.getElementById('selectAllTitles');
    if (selectAll) {
        selectAll.checked = selected === total && total > 0;
        selectAll.indeterminate = selected > 0 && selected < total;
    }
}

function generateMoreTitles() {
    // Just re-run — hooks are deterministic so this just resets selection
    generateTitleIdeas();
}

async function saveSelectedTitles() {
    console.log('saveSelectedTitles started');
    
    const selectedCheckboxes = document.querySelectorAll('.title-checkbox:checked');
    console.log('Selected checkboxes:', selectedCheckboxes.length);
    
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one title');
        return;
    }
    
    if (!window.currentTitleData) {
        console.error('currentTitleData is missing');
        alert('Title data missing');
        return;
    }
    
    console.log('currentTitleData:', window.currentTitleData);
    
    // Get EDITED values from input fields
    const selectedTitles = [];
    
    selectedCheckboxes.forEach(cb => {
        const index = cb.getAttribute('data-index');
        if (index === null) return;
        
        const titleInput = document.querySelector(`.title-edit-input[data-index="${index}"]`);
        if (!titleInput || !titleInput.value.trim()) return;
        
        selectedTitles.push({
            title: titleInput.value.trim(),
            hook_type: window.currentTitleData?.titles[index]?.hook_type || 'hook',
            ctas: { engagement: '', conversion: '', retention: '' }
        });
    });
    
    if (selectedTitles.length === 0) {
        alert('No valid titles to save');
        return;
    }
    
    // IMPORTANT: Get the topic_id from the context or from the dropdown
    let topicId = window.currentTitleContext?.topicId || '';
    
    // If topicId is still empty, try to get it from the dropdown
    if (!topicId) {
        const topicSelect = document.getElementById('idea_topic_select');
        if (topicSelect && topicSelect.value && topicSelect.value !== '__manual__') {
            // Check if the value is numeric (ID)
            if (!isNaN(parseInt(topicSelect.value)) && isFinite(topicSelect.value)) {
                topicId = topicSelect.value;
            }
        }
    }
    
    console.log('Final topicId being sent:', topicId);
    
    L(`💾 Saving ${selectedTitles.length} edited titles to database...`, 'info');
    L(`Topic ID: ${topicId || 'NOT SET'}`, 'info');
    L(`Topic Name: ${window.currentTitleData.topicName}`, 'info');
    
    const saveBtn = event.target;
    const originalText = saveBtn.textContent;
    saveBtn.textContent = 'Saving...';
    saveBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('ajax_action', 'save_titles_with_ctas');
    formData.append('niche_id', window.currentTitleData.nicheId || '');
    formData.append('topic_id', topicId || '');
    formData.append('topic_name', window.currentTitleData.topicName);
    formData.append('titles', JSON.stringify(selectedTitles));
    
    try {
        console.log('Sending fetch request with topic_id:', topicId);
        console.log('Titles being sent:', JSON.stringify(selectedTitles, null, 2));
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        console.log('Response status:', response.status);
        
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
            console.log('Parsed response data:', data);
        } catch (e) {
            console.error('Failed to parse JSON response:', responseText);
            throw new Error('Invalid server response');
        }
        
        if (data.success) {
            L(`✅ ${data.saved_count} titles saved successfully`, 'success');
            
            alert(`Successfully saved ${data.saved_count} titles!`);
            
            const topicSelect = document.getElementById('idea_topic_select');
            if (topicSelect && topicSelect.value && topicSelect.value !== '__manual__') {
                // Call the function to reload titles for this topic
                loadTitlesForTopic();
                console.log('🔄 Title dropdown refreshed with new titles');
            }
            
            if (confirm('Titles saved! Generate more for this topic?')) {
                generateMoreTitles();
            } else {
                closeTitleModal();
            }
        } else {
            throw new Error(data.message || 'Failed to save titles');
        }
    } catch (err) { 
        console.error('Error in saveSelectedTitles:', err);
        L(`❌ Error saving titles: ${err.message}`, 'error');
        alert('Error saving titles: ' + err.message);
    } finally {
        saveBtn.textContent = originalText;
        saveBtn.disabled = false;
    }
}
// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}



function refreshTitleDropdown() {
    // Reload the page to show new data
    location.reload();
}

// 50 Proven Hooks for Title Generation
const HOOKS = [
    "Question Hook: Do you know {topic}?",
    "Question Hook: Want to {topic}?",
    "Question Hook: Are you making these {topic} mistakes?",
    "Question Hook: What if you could {topic}?",
    "Question Hook: Why is {topic} so hard?",
    "Pattern Interrupt: Stop scrolling! {topic}",
    "Pattern Interrupt: Wait! Before you {topic}...",
    "Pattern Interrupt: This {topic} will change everything",
    "Pattern Interrupt: I can't believe this {topic}",
    "Pattern Interrupt: The truth about {topic}",
    "Statistic Hook: 77% of people {topic}. Here's why",
    "Statistic Hook: Studies show that {topic}",
    "Statistic Hook: The numbers don't lie about {topic}",
    "Statistic Hook: Research reveals {topic}",
    "Statistic Hook: 9 out of 10 people {topic}",
    "Story Hook: I used to struggle with {topic}",
    "Story Hook: How I mastered {topic} in 30 days",
    "Story Hook: The day I discovered {topic}",
    "Story Hook: My {topic} journey",
    "Story Hook: What I learned about {topic}",
    "Myth Buster: Think {topic} is hard? Think again",
    "Myth Buster: The biggest myth about {topic}",
    "Myth Buster: What they don't tell you about {topic}",
    "Myth Buster: {topic} myths debunked",
    "Myth Buster: Stop believing these {topic} lies",
    "Prediction Hook: What if I told you {topic}",
    "Prediction Hook: The future of {topic}",
    "Prediction Hook: Here's what {topic} will look like",
    "Prediction Hook: Imagine if {topic}",
    "Prediction Hook: The {topic} revolution is coming",
    "Identity Hook: For anyone who loves {topic}",
    "Identity Hook: If you're a {topic} person...",
    "Identity Hook: To all the {topic} lovers",
    "Identity Hook: The {topic} mindset",
    "Identity Hook: {topic} people will understand",
    "How-To: How to {topic} in 5 minutes",
    "How-To: The ultimate guide to {topic}",
    "How-To: 3 steps to master {topic}",
    "How-To: {topic} made simple",
    "How-To: Quick {topic} tips",
    "List: 5 ways to {topic}",
    "List: 10 {topic} secrets",
    "List: 7 {topic} hacks",
    "List: Top {topic} strategies",
    "List: Best {topic} tips",
    "Comparison: {topic} vs {topic}",
    "Comparison: Why {topic} beats {topic}",
    "Comparison: The difference between {topic}",
    "Comparison: {topic} alternatives",
    "Comparison: Which {topic} is right for you?"
];



function selectTitle(element, title) {
    // Remove selected class from all titles
    document.querySelectorAll('.title-item').forEach(el => {
        el.classList.remove('selected');
    });
    // Add selected class to clicked title
    element.classList.add('selected');
    // Store selected title
    element.setAttribute('data-selected', 'true');
}

function selectRandomTitle() {
    const titles = document.querySelectorAll('.title-item');
    if (titles.length === 0) return;
    
    const randomIndex = Math.floor(Math.random() * titles.length);
    // Remove selected class from all
    titles.forEach(el => el.classList.remove('selected'));
    // Add selected class to random
    titles[randomIndex].classList.add('selected');
    
    L(`🎲 Random title selected: ${titles[randomIndex].textContent}`, 'info');
}

function useSelectedTitle() {
    const selected = document.querySelector('.title-item.selected');
    
    if (!selected) {
        alert('Please select a title');
        return;
    }
    
    const title = selected.textContent;
    document.getElementById('idea_title').value = title;
    closeTitleModal();
    L(`✅ Title set to: ${title}`, 'success');
}


function deleteTopicFromList(button, index) {
    if (confirm('Are you sure you want to remove this topic from the list?')) {
        const topicItem = button.closest('.topic-item');
        if (topicItem) {
            topicItem.remove();
        }
        updateSelectedTopicsCount();
        L(`🗑️ Topic removed from list`, 'info'); 
    }
}
// Helper function to escape HTML
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}


// Force titles to load for the default topic

// Force titles to load for default selections
(function() {
    // Wait for page to fully load
    window.addEventListener('load', function() {
        console.log('Checking for default selections...');
        
        // PART 1: Handle My Idea tab topic selection (your existing code)
        const topicSelect = document.getElementById('idea_topic_select');
        if (topicSelect && topicSelect.value && topicSelect.value !== '' && topicSelect.value !== '__manual__') {
            console.log('Default topic found in My Idea tab:', topicSelect.value);
            
            if (typeof handleTopicSelection === 'function') {
                handleTopicSelection(topicSelect);
            }
            
            try {
                const event = new Event('change', { bubbles: true });
                topicSelect.dispatchEvent(event);
            } catch (e) {}
        }
        
        // PART 2: Handle Database tab - Category, Topic, and Title
        setTimeout(function() {
            // Check if we're in database tab or if database elements exist
            const catSelect = document.getElementById('cat_select');
            const dbTopicSelect = document.getElementById('topic_select');
            const titleSelect = document.getElementById('sm_id_select');
            
            // If category exists and has value but topics are empty, load topics
            if (catSelect && catSelect.value && catSelect.value !== '') {
                console.log('Default category found:', catSelect.value);
                
                // Call loadTopics function
                if (typeof loadTopics === 'function') {
                    loadTopics(catSelect.value);
                }
            }
            
            // After categories load, handle topics
            setTimeout(function() {
                if (dbTopicSelect && dbTopicSelect.value && dbTopicSelect.value !== '') {
                    console.log('Default database topic found:', dbTopicSelect.value);
                    
                    // Call loadTitles function to populate titles
                    if (typeof loadTitles === 'function') {
                        loadTitles(dbTopicSelect.value);
                    }
                    
                    // Dispatch change event
                    try {
                        const event = new Event('change', { bubbles: true });
                        dbTopicSelect.dispatchEvent(event);
                    } catch (e) {}
                }
            }, 500);
            
            // After titles load, select first title
            setTimeout(function() {
                if (titleSelect && titleSelect.options.length > 1 && !titleSelect.value) {
                    for (let i = 0; i < titleSelect.options.length; i++) {
                        if (titleSelect.options[i].value) {
                            titleSelect.value = titleSelect.options[i].value;
                            console.log('Selected default title:', titleSelect.options[i].text);
                            
                            // Call updatePrompt if it exists
                            if (typeof updatePrompt === 'function') {
                                updatePrompt();
                            }
                            break;
                        }
                    }
                }
            }, 1000);
            
        }, 500); // Small delay to ensure DOM is ready
    });
})();


// ========== NICHE MANAGEMENT ==========
function handleNicheSelection(select) {
    if (select.value === '__new__') {
        document.getElementById('new_niche_container').style.display = 'block';
        document.getElementById('new_niche_input').focus();
    } else {
        document.getElementById('new_niche_container').style.display = 'none';
        // Load topics for selected niche
        loadTopicsByNiche(select.value);
    }
}

function loadTopicsByNiche(nicheId) {
    if (!nicheId) {
        console.log('No niche ID provided, resetting topics');
        resetTopicsDropdown();
        return;
    }
    
    console.log('🔄 Loading topics for niche ID:-inam', nicheId);
    
    // Show loading in topic dropdown
    const topicSelect = document.getElementById('idea_topic_select');
    if (!topicSelect) {
        console.error('Topic select element not found');
        return;
    }
    
    // Store current selection
    const currentValue = topicSelect.value;
    
    // Show loading state
    topicSelect.innerHTML = '<option value="">Loading topics...</option>';
    topicSelect.disabled = true;
    
    // Make AJAX call to get topics for this niche
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'get_topics_by_niche');
    formData.append('niche_id', nicheId);
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('Topics response:', data);
        
        topicSelect.disabled = false;
        
        if (data.success && data.topics && data.topics.length > 0) {
            let options = '';
            data.topics.forEach(topic => {
                options += `<option value="${escapeHtml(topic)}">${escapeHtml(topic)}</option>`;
            });
            
            topicSelect.innerHTML = options;
            
            // Try to restore previous selection
            if (currentValue && currentValue !== '__manual__') {
                const optionExists = Array.from(topicSelect.options).some(opt => opt.value === currentValue);
                if (optionExists) topicSelect.value = currentValue;
            }

            // Auto-load titles for the selected topic
            if (topicSelect.value) {
                loadTitlesForTopic();
            }
            
            console.log(`✅ Loaded ${data.topics.length} topics for niche ${nicheId}`);
        } else {
            topicSelect.innerHTML = '<option value="">-- No topics for this niche --</option>';
            console.log('No topics found for this niche');
        }
    })
    .catch(err => {
        console.error('Error loading topics:', err);
        topicSelect.disabled = false;
        topicSelect.innerHTML = '<option value="">-- Error loading topics --</option>';
    });
}

// Helper function to reset topics dropdown
function resetTopicsDropdown() {
    const topicSelect = document.getElementById('idea_topic_select');
    if (!topicSelect) return;
    
    // Reset to original state with all topics grouped by niche
    // This would require the original PHP-generated HTML
    // For now, we'll reload the page or use a simpler approach
    location.reload();
}

// Helper function to escape HTML
function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

async function generateTitleIdeas() {
    const context = window.currentTitleContext;
    if (!context || !context.topicName) {
        alert('Topic information missing');
        closeTitleModal();
        return;
    }

    const topic = context.topicName;
    const btn   = document.getElementById('generateTitlesBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Generating...'; }

    // Generate titles directly from hooks — no AI call needed
    const HOOKS = [
        "Do you know how to {topic}?",
        "Want to {topic}?",
        "Are you making these {topic} mistakes?",
        "What if you could {topic}?",
        "Why is {topic} so hard?",
        "Stop scrolling! {topic}",
        "Wait! Before you {topic}...",
        "This {topic} will change everything",
        "I can't believe {topic}",
        "The truth about {topic}",
        "77% of people struggle with {topic}",
        "Studies show that {topic}",
        "The numbers don't lie about {topic}",
        "Research reveals {topic}",
        "9 out of 10 people get {topic} wrong",
        "I used to struggle with {topic}",
        "How I mastered {topic} in 30 days",
        "The day I discovered {topic}",
        "My {topic} journey",
        "What I learned about {topic}",
        "Think {topic} is hard? Think again",
        "The biggest myth about {topic}",
        "What they don't tell you about {topic}",
        "{topic} myths debunked",
        "Stop believing these {topic} lies",
        "What if I told you {topic}?",
        "The future of {topic}",
        "Here's what {topic} looks like",
        "Imagine if {topic}",
        "The {topic} revolution is here",
        "For anyone who wants {topic}",
        "If you care about {topic}...",
        "To everyone struggling with {topic}",
        "The {topic} mindset",
        "{topic} — you'll understand why",
        "How to {topic} in 5 minutes",
        "The ultimate guide to {topic}",
        "3 steps to master {topic}",
        "{topic} made simple",
        "Quick {topic} tips that actually work",
        "5 ways to {topic}",
        "10 {topic} secrets nobody talks about",
        "7 {topic} hacks that work",
        "Top {topic} strategies",
        "Best {topic} tips for beginners",
        "{topic} vs doing nothing — the comparison",
        "Why {topic} beats everything else",
        "The difference between good and bad {topic}",
        "{topic} alternatives you haven't tried",
        "Which {topic} approach is right for you?"
    ];

    const titles = HOOKS.map(hook => ({
        title: hook.replace(/\{topic\}/gi, topic),
        hook_type: 'hook'
    }));

    window.currentTitleData = {
        nicheId:   context.nicheId,
        nicheName: context.nicheName,
        topicId:   context.topicId,
        topicName: topic,
        titles:    titles
    };

    displayTitleList(titles, topic);

    document.getElementById('titleStep1').style.display = 'none';
    document.getElementById('titleStep2').style.display = 'flex';

    if (btn) { btn.disabled = false; btn.textContent = '🚀 Generate Titles'; }
    updateSelectedTitlesCount();
}

function getCurrentNiche() {
    const select = document.getElementById('niche_select');
    const newInput = document.getElementById('new_niche_input');
    
    if (select && select.value === '__new__') {
        return newInput.value.trim();
    } else if (select && select.value) {
        // Get the selected option text
        const selectedOption = select.options[select.selectedIndex];
        return selectedOption ? selectedOption.text : '';
    }
    return '';
}



// ========== ADD TRASH ICONS TO DROPDOWNS ==========
function addTrashIconsToDropdowns() {
    console.log('Adding trash icons to dropdowns...');
    
    // Add to Niche dropdown
    addTrashIconToDropdown('niche_select', 'niche');
    
    // Add to Topic dropdown (My Idea tab)
    addTrashIconToDropdown('idea_topic_select', 'topic');
    
    // Add to Title dropdown (Database tab)
    addTrashIconToDropdown('sm_id_select', 'title');
}

function addTrashIconToDropdown(selectId, type) {
    const select = document.getElementById(selectId);
    if (!select) return;
    
    // Check if already wrapped
    if (select.parentNode.classList.contains('dropdown-with-delete')) return;
    
    // Create wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'dropdown-with-delete';
    
    // Insert wrapper before select
    select.parentNode.insertBefore(wrapper, select);
    
    // Move select into wrapper
    wrapper.appendChild(select);
    
    // Create delete button (disabled for now - visual only)
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'delete-dropdown-btn';
    deleteBtn.innerHTML = '🗑️';
    deleteBtn.title = `Delete this ${type}`;
    deleteBtn.disabled = true; // Disabled for now - just for visual
    
    wrapper.appendChild(deleteBtn);
}
// ========== LOAD USER SETTINGS ==========
async function loadUserSettings() {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_settings');
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: fd
        });
        
        const data = await response.json();
        
        if (data.success && data.settings) {
            // Store settings globally
            window.userSettings = data.settings;
            
            // Pre-fill settings panels with user's default settings
            if (data.settings.fontfamily) {
                document.getElementById('sceneFontFamily').value = data.settings.fontfamily;
            }
            if (data.settings.fontsize) {
                document.getElementById('sceneFontSize').value = data.settings.fontsize;
            }
            if (data.settings.fontcolor) {
                document.getElementById('sceneFontColor').value = data.settings.fontcolor;
            }
            if (data.settings.fontcolor_bg) {
                document.getElementById('sceneFontBgColor').value = data.settings.fontcolor_bg;
            }
            if (data.settings.fontweight) {
                document.getElementById('sceneFontWeight').value = data.settings.fontweight;
            }
            if (data.settings.caption_style) {
                document.getElementById('sceneCaptionStyle').value = data.settings.caption_style;
            }
            if (data.settings.caption_position) {
                document.getElementById('sceneCaptionPosition').value = data.settings.caption_position;
            }
            if (data.settings.caption_alignment) {
                document.getElementById('sceneCaptionAlignment').value = data.settings.caption_alignment;
            }
            if (data.settings.caption_speed) {
                document.getElementById('sceneCaptionSpeed').value = data.settings.caption_speed;
                document.getElementById('sceneSpeedValue').innerText = data.settings.caption_speed + 'x';
            }
            if (data.settings.logo_size) {
                document.getElementById('sceneLogoSize').value = data.settings.logo_size;
            }
            if (data.settings.logo_position) {
                document.getElementById('sceneLogoPosition').value = data.settings.logo_position;
            }
            if (data.settings.logo_enabled !== undefined) {
                document.getElementById('sceneLogoEnabled').checked = data.settings.logo_enabled == 1;
            }
            
            console.log('✅ User settings loaded:', data.settings);
        }
    } catch (err) {
        console.error('Error loading user settings:', err);
    }
}


// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageSelector();
    }
});

// Click outside modal to close
window.addEventListener('click', function(e) {
    const modal = document.getElementById('imageSelectorModal');
    if (e.target === modal) {
        closeImageSelector();
    }
});

// Simple log preservation - add at the VERY END of your script, right before 
// Add this at the VERY BOTTOM of your JavaScript, right before 
(function() {
    console.log('🔍 Log preservation loaded');
    
    // Restore logs on page load
    const savedLogs = sessionStorage.getItem('realtime_logs');
    if (savedLogs) {
        const logEl = document.getElementById('realtime_log');
        if (logEl) {
            logEl.innerHTML = savedLogs;
            console.log('✅ Logs restored, length:', savedLogs.length);
        }
    }
    
    // Save logs before page unload
    window.addEventListener('beforeunload', function() {
        const logEl = document.getElementById('realtime_log');
        if (logEl) {
            sessionStorage.setItem('realtime_logs', logEl.innerHTML);
            console.log('💾 Logs saved');
        }
    });
    
    // MONITOR for log clearing
    const logEl = document.getElementById('realtime_log');
    if (logEl) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.removedNodes.length > 0) {
                    console.log('⚠️ Log entries were removed!', new Date().toLocaleTimeString());
                    console.log('Removed nodes:', mutation.removedNodes.length);
                }
            });
        });
        
        observer.observe(logEl, { childList: true, subtree: false });
        console.log('👀 Monitoring log for changes');
    }
})();







</script>

<!-- AI Topic Ideas Modal -->
<div class="modal-overlay" id="topicModal" style="display:none;">
    <div style="background:white; border-radius:20px; width:92%; max-width:480px; max-height:88vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#8b5cf6,#6d28d9); padding:16px 20px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <span style="color:white; font-size:16px; font-weight:700;">🤖 Topic Ideas</span>
            <button onclick="closeTopicModal()" style="background:rgba(255,255,255,0.2); border:none; color:white; width:28px; height:28px; border-radius:50%; font-size:16px; cursor:pointer;">×</button>
        </div>

        <!-- Step 1: Select Niche -->
        <div id="topicStep1" style="padding:16px; display:flex; flex-direction:column; gap:12px;">
            <div class="form-group" style="margin:0;">
                <label style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Niche / Profession</label>
                <?php if ($has_user_niches): ?>
                <select id="topicNicheSelect" onchange="handleTopicNicheSelection(this)" style="margin-bottom:0;">
                    <option value="">-- Select niche --</option>
                    <?php foreach ($user_niches as $niche): ?>
                        <option value="<?php echo $niche['id']; ?>" data-name="<?php echo htmlspecialchars($niche['niche_name']); ?>">
                            <?php echo htmlspecialchars($niche['niche_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__new__">➕ New niche</option>
                </select>
                <?php endif; ?>
                <input type="text" id="topicNicheInput" placeholder="e.g., Fitness, Mental Health..." style="<?php echo $has_user_niches ? 'display:none;' : ''; ?> margin-top:8px;">
            </div>

            <div id="topicLoading" style="display:none; text-align:center; padding:16px; background:#f8fafc; border-radius:12px;">
                <div style="font-size:28px; margin-bottom:6px;">⏳</div>
                <p style="color:#0f2a44; font-weight:600; margin:0 0 4px;">Generating topics...</p>
                <p style="color:#64748b; font-size:12px; margin:0;">This takes a few seconds</p>
            </div>

            <button class="btn btn-primary" id="generateTopicsBtn" onclick="generateTopicIdeas()" style="width:100%;">Generate Topics</button>
        </div>

        <!-- Step 2: Pick Topics -->
        <div id="topicStep2" style="display:none; flex-direction:column; overflow:hidden; flex:1;">
            <div style="padding:12px 16px; border-bottom:1px solid #f1f5f9; flex-shrink:0; display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:13px; color:#64748b;">Topics for: <strong id="selectedNicheDisplay" style="color:#0f2a44;"></strong></span>
                <span id="selectedTopicsCount" style="font-size:12px; background:#eff6ff; color:#3b82f6; padding:2px 8px; border-radius:20px;">0 selected</span>
            </div>

            <!-- Select All -->
            <div style="padding:10px 16px; border-bottom:1px solid #f1f5f9; flex-shrink:0; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" id="selectAllTopics" onchange="toggleSelectAllTopics(this)" style="width:18px; height:18px;">
                <label for="selectAllTopics" style="font-weight:600; color:#0f2a44; cursor:pointer; font-size:14px;">Select All</label>
            </div>

            <!-- Topics List -->
            <div id="topicList" style="overflow-y:auto; flex:1; padding:8px 0;">
                <!-- inserted by JS -->
            </div>

            <!-- Actions -->
            <div style="padding:12px 16px; border-top:1px solid #f1f5f9; flex-shrink:0; display:flex; flex-direction:column; gap:8px;">
                <button class="btn btn-success" onclick="saveSelectedTopics()" style="width:100%;">✅ Add Selected Topics</button>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-primary" onclick="generateMoreTopics()" style="flex:1;">🔄 More Ideas</button>
                    <button class="btn" onclick="changeNiche()" style="flex:1; background:#f1f5f9; color:#0f2a44;">🔀 Change Niche</button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- AI Title Ideas Modal -->
<div class="modal-overlay" id="titleModal" style="display:none;">
    <div style="background:white; border-radius:20px; width:92%; max-width:480px; max-height:88vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,0.3);">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#0f2a44,#1e3a5f); padding:16px 20px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
            <span style="color:white; font-size:16px; font-weight:700;">🎯 Title Ideas</span>
            <button onclick="closeTitleModal()" style="background:rgba(255,255,255,0.2); border:none; color:white; width:28px; height:28px; border-radius:50%; font-size:16px; cursor:pointer;">×</button>
        </div>

        <!-- Step 1 -->
        <div id="titleStep1" style="padding:16px; display:flex; flex-direction:column; gap:12px;">
            <div style="background:#f8fafc; border-radius:12px; padding:12px 16px;">
                <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;">Topic</div>
                <div id="titleSelectedTopic" style="font-size:15px; font-weight:600; color:#0f2a44;"></div>
            </div>
            <p style="font-size:13px; color:#64748b; margin:0;">Generates one title per hook template (50+ titles total).</p>
            <button class="btn btn-primary" id="generateTitlesBtn" onclick="generateTitleIdeas()" style="width:100%;">🚀 Generate Titles</button>
        </div>

        <!-- Step 2 -->
        <div id="titleStep2" style="display:none; flex-direction:column; overflow:hidden; flex:1;">
            <div style="padding:10px 16px; border-bottom:1px solid #f1f5f9; flex-shrink:0; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" id="selectAllTitles" onchange="toggleSelectAllTitles(this)" style="width:18px; height:18px; accent-color:#0f2a44;">
                    <label for="selectAllTitles" style="font-weight:600; color:#0f2a44; cursor:pointer; font-size:14px;">Select All</label>
                </div>
                <span id="selectedTitlesCount" style="font-size:12px; background:#eff6ff; color:#3b82f6; padding:2px 8px; border-radius:20px;">0 selected</span>
            </div>

            <div id="titleList" style="overflow-y:auto; flex:1; padding:4px 0;"></div>

            <div style="padding:12px 16px; border-top:1px solid #f1f5f9; flex-shrink:0; display:flex; flex-direction:column; gap:8px;">
                <button class="btn btn-success" onclick="saveSelectedTitles()" style="width:100%;">✅ Save Selected Titles</button>
                <div style="display:flex; gap:8px;">
                    <button class="btn btn-primary" onclick="generateMoreTitles()" style="flex:1;">🔄 Regenerate</button>
                    <button class="btn" onclick="closeTitleModal()" style="flex:1; background:#f1f5f9; color:#0f2a44;">Cancel</button>
                </div>
            </div>
        </div>

    </div>
</div>


<!-- ===== REEL TYPE CUSTOM DROPDOWN ===== -->
<style>
.reel-dd-wrap {
    position: relative;
}
.reel-dd-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    color: #0f2a44;
    transition: border-color 0.2s;
    user-select: none;
}
.reel-dd-trigger:hover { border-color: #3b82f6; }
.reel-dd-trigger.open  { border-color: #0f2a44; border-radius: 10px 10px 0 0; }
.reel-dd-arrow { font-size: 11px; color: #94a3b8; transition: transform 0.2s; }
.reel-dd-arrow.open { transform: rotate(180deg); }
.reel-dd-panel {
    display: none;
    position: absolute;
    left: 0; right: 0;
    background: #fff;
    border: 2px solid #0f2a44;
    border-top: none;
    border-radius: 0 0 10px 10px;
    padding: 10px;
    gap: 8px;
    flex-direction: row;
    z-index: 999;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}
.reel-dd-panel.open { display: flex; }
.reel-dd-btn {
    flex: 1;
    padding: 9px 6px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    font-size: 13px;
    font-weight: 600;
    color: #0f2a44;
    cursor: pointer;
    transition: all 0.15s;
    text-align: center;
}
.reel-dd-btn:hover { border-color: #3b82f6; background: #eff6ff; }
.reel-dd-btn.active { background: #0f2a44; color: #fff; border-color: #0f2a44; }
/* Shared styles for all button dropdowns (language, duration, format) */
.dd-wrap { position: relative; }
.dd-trigger {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; background: #f8fafc; border: 2px solid #e2e8f0;
    border-radius: 10px; cursor: pointer; font-size: 14px; font-weight: 600;
    color: #0f2a44; transition: border-color 0.2s; user-select: none;
}
.dd-trigger:hover { border-color: #3b82f6; }
.dd-trigger.open  { border-color: #0f2a44; border-radius: 10px 10px 0 0; }
.dd-arrow { font-size: 11px; color: #94a3b8; transition: transform 0.2s; }
.dd-arrow.open { transform: rotate(180deg); }
.dd-panel {
    display: none; position: absolute; left: 0; right: 0;
    background: #fff; border: 2px solid #0f2a44; border-top: none;
    border-radius: 0 0 10px 10px; padding: 10px; gap: 8px;
    flex-wrap: wrap; z-index: 999;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}
.dd-panel.open { display: flex; }
</style>

<script>
function toggleReelDD(source) {
    const panel   = document.getElementById(source + '_reel_dd_panel');
    const trigger = document.querySelector('#' + source + '_reel_dd_wrap .reel-dd-trigger');
    const arrow   = document.getElementById(source + '_reel_dd_arrow');
    if (!panel || !trigger || !arrow) return;
    const isOpen  = panel.classList.contains('open');
    closeAllDDs();
    if (!isOpen) {
        panel.classList.add('open');
        trigger.classList.add('open');
        arrow.classList.add('open');
    }
}

function pickReel(source, value, label, btn) {
    document.getElementById(source + '_reel_type').value = value;
    document.getElementById(source + '_reel_type_label').textContent = label;
    btn.closest('.reel-dd-panel').querySelectorAll('.reel-dd-btn')
        .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    closeAllDDs();
    checkPodcastType(source);
}

function closeAllDDs() {
    document.querySelectorAll('.reel-dd-panel, .dd-panel').forEach(p => p.classList.remove('open'));
    document.querySelectorAll('.reel-dd-trigger, .dd-trigger').forEach(t => t.classList.remove('open'));
    document.querySelectorAll('.reel-dd-arrow, .dd-arrow').forEach(a => a.classList.remove('open'));
}

function toggleDD(id) {
    const panel   = document.getElementById(id + '_panel');
    const trigger = document.getElementById(id + '_trigger');
    const arrow   = document.getElementById(id + '_arrow');
    if (!panel || !trigger || !arrow) return;
    const isOpen  = panel.classList.contains('open');
    closeAllDDs();
    if (!isOpen) {
        panel.classList.add('open');
        trigger.classList.add('open');
        arrow.classList.add('open');
    }
}

function pickDD(id, value, label, btn, callback) {
    const input = document.getElementById(id);
    const labelEl = document.getElementById(id + '_label');
    if (input) input.value = value;
    if (labelEl) labelEl.textContent = label;
    btn.closest('.dd-panel').querySelectorAll('.reel-dd-btn')
        .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    closeAllDDs();
    if (callback) callback(value);
}

// Language picker — syncs all tabs + global select + voice loading
function pickLang(code, label, btn) {
    // Update global hidden select (used by all JS)
    const globalSel = document.getElementById('global_lang_select');
    if (globalSel) {
        globalSel.value = code;
        loadVoicesForLanguage(code);
        saveVoiceSettings();
    }
    // Sync all tab labels + active buttons
    syncLangTabs(code, label, btn);
    closeAllDDs();
}

function syncLangTabs(code, label, activeBtn) {
    // If called from select onchange, find label from select option text
    if (!label) {
        const sel = document.getElementById('global_lang_select');
        const opt = sel?.options[sel.selectedIndex];
        label = opt ? opt.text : code;
    }
    // Update all language trigger labels
    ['idea','content','db','voice'].forEach(src => {
        const lbl = document.getElementById(src + '_lang_label');
        if (lbl) lbl.textContent = label;
        // Update active state on all buttons in this panel
        const panel = document.getElementById(src + '_lang_panel');
        if (panel) {
            panel.querySelectorAll('.reel-dd-btn').forEach(b => {
                b.classList.toggle('active', b.getAttribute('onclick')?.includes("'" + code + "'"));
            });
        }
    });
}

// Close all dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.reel-dd-wrap') && !e.target.closest('.dd-wrap')) {
        closeAllDDs();
    }
});

// ===== STEP 2 SETUP =====
let _step2Source = null;

function showStep2Setup(source) {
    _step2Source = source;

    // Hide the entire main script card
    const mainCard = document.getElementById('mainScriptCard');
    if (mainCard) mainCard.style.display = 'none';

    // Show setup panel
    const panel = document.getElementById('step2SetupPanel');
    panel.style.display = 'block';

    // Scroll to top of page
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Load voices into step 2 pickers
    const langCode = document.getElementById('global_lang_select').value || 'en';
    loadStep2Voices(langCode);

    // Show/hide guest based on reel type
    const reelType = document.getElementById(source + '_reel_type')?.value;
    if (reelType) {
        document.getElementById('s2_guestContainer').style.display =
            reelType === 'podcast' ? 'block' : 'none';
    }
}

function hideStep2Setup() {
    document.getElementById('step2SetupPanel').style.display = 'none';

    // Restore the main script card
    const mainCard = document.getElementById('mainScriptCard');
    if (mainCard) mainCard.style.display = '';

    // In wizard mode — restore the correct tab content for the current option
    if (currentWizardOption) {
        const cfg = WIZARD_CONFIG[currentWizardOption];
        switchTab(cfg.tab);
        if (cfg.subtab) {
            switchIdeaSubtab(cfg.subtab);
            const subtabBar = document.querySelector('.idea-subtabs');
            if (subtabBar) subtabBar.style.display = 'none';
        }
    }

    // Scroll back to top
    window.scrollTo({ top: 0, behavior: 'smooth' });

    _step2Source = null;
}

function loadStep2Voices(langCode) {
    // Copy options from global hidden pickers into step2 pickers
    const srcHost  = document.getElementById('hostVoicePicker');
    const srcGuest = document.getElementById('guestVoicePicker');
    const s2Host   = document.getElementById('s2_hostVoice');
    const s2Guest  = document.getElementById('s2_guestVoice');

    if (srcHost && s2Host) {
        s2Host.innerHTML = srcHost.innerHTML;
        // Auto-select first voice
        s2Host.selectedIndex = srcHost.selectedIndex >= 0 ? srcHost.selectedIndex : 0;
    }
    if (srcGuest && s2Guest) {
        s2Guest.innerHTML = srcGuest.innerHTML;
        s2Guest.selectedIndex = srcGuest.selectedIndex >= 0 ? srcGuest.selectedIndex : 0;
    }
}

function playS2VoiceSample(type) {
    const sel = document.getElementById(type === 'host' ? 's2_hostVoice' : 's2_guestVoice');
    if (!sel || !sel.value) { alert('Select a voice first'); return; }
    const opt = sel.options[sel.selectedIndex];
    const sampleUrl = opt?.dataset?.sample;
    if (sampleUrl) {
        new Audio(sampleUrl).play();
    } else {
        alert('No sample available for this voice');
    }
}

async function proceedStep2() {
    if (!_step2Source) return;

    // Sync step2 voice selections back to global hidden pickers
    const hostPicker  = document.getElementById('hostVoicePicker');
    const guestPicker = document.getElementById('guestVoicePicker');
    const ratePicker  = document.getElementById('ratePicker');
    const s2Host  = document.getElementById('s2_hostVoice');
    const s2Guest = document.getElementById('s2_guestVoice');
    const s2Speed = document.getElementById('s2_speedVal');

    if (hostPicker  && s2Host)  hostPicker.value  = s2Host.value;
    if (guestPicker && s2Guest) guestPicker.value = s2Guest.value;
    if (ratePicker  && s2Speed) ratePicker.value  = s2Speed.value;

    // Save to session
    await saveVoiceSettings();

    // Hide setup panel
    document.getElementById('step2SetupPanel').style.display = 'none';

    // Call the correct scenes function
    const source = _step2Source;
    _step2Source = null;

    if (source === 'idea')    createIdeaScenes();
    else if (source === 'content') createContentScenes();
    else if (source === 'db')      createDbScenes();
}

</script>

<!-- ===== IDEA SUB-TABS ===== -->
<style>
.idea-subtabs {
    display: flex;
    gap: 0;
    margin: 16px 0 12px;
    border-radius: 12px;
    overflow: hidden;
    border: 2px solid #e2e8f0;
}
.idea-subtab {
    flex: 1;
    padding: 10px;
    border: none;
    background: #f8fafc;
    color: #64748b;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.idea-subtab:first-child { border-right: 1px solid #e2e8f0; }
.idea-subtab.active {
    background: #0f2a44;
    color: white;
}
.idea-subtab:hover:not(.active) {
    background: #e2e8f0;
    color: #0f2a44;
}
</style>

<script>
function switchIdeaSubtab(tab) {
    document.getElementById('idea_subtab_ai').style.display   = (tab === 'ai')   ? 'block' : 'none';
    document.getElementById('idea_subtab_bank').style.display = (tab === 'bank') ? 'block' : 'none';
    document.getElementById('subtab_ai_btn').classList.toggle('active',   tab === 'ai');
    document.getElementById('subtab_bank_btn').classList.toggle('active', tab === 'bank');
}
</script>

<!-- ===== SETTINGS TOGGLE ===== -->
<style>
.stg-wrap { margin-bottom: 12px; }
.stg-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 11px 16px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 700;
    color: #0f2a44;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
}
.stg-btn:hover { border-color: #3b82f6; background: #eff6ff; }
.stg-btn.open  { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-color: #0f2a44; background: #fff; }
.stg-chevron { font-size: 12px; color: #94a3b8; transition: transform 0.25s; display:inline-block; }
.stg-chevron.open { transform: rotate(180deg); }
.stg-panel {
    display: none;
    flex-direction: column;
    border: 2px solid #0f2a44;
    border-top: none;
    border-radius: 0 0 12px 12px;
    background: #fff;
    padding: 12px;
    gap: 4px;
}
.stg-panel.open { display: flex; }
</style>

<script>
function toggleStg(source) {
    const panel   = document.getElementById(source + '_stg_panel');
    const btn     = document.querySelector('#' + source + '_stg_wrap .stg-btn');
    const chevron = document.getElementById(source + '_stg_chevron');
    if (!panel || !btn || !chevron) return;
    const isOpen  = panel.classList.contains('open');

    // Close all settings panels first
    ['idea','content','db'].forEach(s => {
        document.getElementById(s + '_stg_panel')?.classList.remove('open');
        document.querySelector('#' + s + '_stg_wrap .stg-btn')?.classList.remove('open');
        document.getElementById(s + '_stg_chevron')?.classList.remove('open');
    });

    if (!isOpen) {
        panel.classList.add('open');
        btn.classList.add('open');
        chevron.classList.add('open');
    }
}
</script>

<!-- ===== NICHE ADD/DELETE ===== -->
<style>
.niche-action-btn {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1.5px solid var(--border, #e2e8f0);
    background: white;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}
.niche-add-btn { color: #10b981; }
.niche-add-btn:hover { background: #ecfdf5; border-color: #10b981; }
.niche-del-btn { color: #ef4444; }
.niche-del-btn:hover { background: #fee2e2; border-color: #ef4444; }
</style>

<script>
// Called when niche select changes — save last used + load topics
function onNicheChange(nicheId) {
    if (!nicheId) return;
    // Save last used niche
    const fd = new FormData();
    fd.append('ajax_action', 'save_last_niche');
    fd.append('niche_id', nicheId);
    fetch('', { method: 'POST', body: fd }).catch(() => {});
    // Load topics
    if (typeof loadTopicsByNiche === 'function') loadTopicsByNiche(nicheId);
}

// Show add niche input
function addNiche() {
    document.getElementById('new_niche_container').style.display = 'block';
    document.getElementById('new_niche_input').value = '';
    document.getElementById('new_niche_input').focus();
}

function cancelAddNiche() {
    document.getElementById('new_niche_container').style.display = 'none';
    document.getElementById('new_niche_input').value = '';
}

// Save new niche to DB and add to dropdown
async function saveNewNiche() {
    const input = document.getElementById('new_niche_input');
    const name  = input.value.trim();
    if (!name) { input.focus(); return; }

    const fd = new FormData();
    fd.append('ajax_action', 'add_niche');
    fd.append('niche_name', name);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) { alert(data.message || 'Failed to add niche'); return; }

    // Add to select
    const sel = document.getElementById('niche_select');
    if (sel) {
        const opt = document.createElement('option');
        opt.value = data.id;
        opt.textContent = data.name;
        opt.selected = true;
        sel.appendChild(opt);
        // Trigger load topics for new niche
        onNicheChange(data.id);
    }

    cancelAddNiche();
}

// Delete selected niche with confirmation
async function deleteNiche() {
    const sel = document.getElementById('niche_select');
    if (!sel || !sel.value) { alert('Please select a niche to delete.'); return; }

    const nicheName = sel.options[sel.selectedIndex].text;
    const nicheId   = sel.value;

    const confirmed = confirm(
        `⚠️ Delete "${nicheName}"?\n\n` +
        `This will permanently delete:\n` +
        `• All topics for this niche\n` +
        `• All titles for those topics\n\n` +
        `This cannot be undone.`
    );
    if (!confirmed) return;

    const fd = new FormData();
    fd.append('ajax_action', 'delete_niche');
    fd.append('niche_id', nicheId);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) { alert(data.message || 'Failed to delete niche'); return; }

    // Remove from dropdown
    sel.remove(sel.selectedIndex);

    // Stay on Content Bank tab — do NOT switch tabs
    if (sel.options.length > 0) {
        sel.selectedIndex = 0;
        if (sel.value) onNicheChange(sel.value);
    } else {
        if (typeof resetTopicsDropdown === 'function') resetTopicsDropdown();
    }
}

// Pre-select last used niche on page load and load its topics
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('niche_select');
    if (sel && sel.value) {
        // Already pre-selected server-side — just load topics
        if (typeof loadTopicsByNiche === 'function') loadTopicsByNiche(sel.value);
    }
});
</script>

<!-- ===== NICHE ADD / DELETE ===== -->
<style>
.niche-action-btn {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    padding: 0;
}
.niche-add-btn { color: #10b981; border-color: #10b981; }
.niche-add-btn:hover { background: #ecfdf5; }
.niche-del-btn { color: #ef4444; border-color: #ef4444; }
.niche-del-btn:hover { background: #fef2f2; }
.dropdown-with-delete { display: flex; align-items: center; gap: 6px; }
.dropdown-with-delete select { flex: 1; }
</style>

<script>
// ── Called when niche select changes ──
function onNicheChange(nicheId) {
    loadTopicsByNiche(nicheId);
    if (nicheId) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=save_last_niche&niche_id=${encodeURIComponent(nicheId)}`
        });
    }
}

// ── Show add-niche input ──
function addNiche() {
    document.getElementById('new_niche_container').style.display = 'block';
    const inp = document.getElementById('new_niche_input');
    inp.value = '';
    inp.focus();
}

function cancelAddNiche() {
    document.getElementById('new_niche_container').style.display = 'none';
    document.getElementById('new_niche_input').value = '';
}

// ── Save new niche via AJAX, add to select, auto-select it ──
async function saveNewNiche() {
    const inp = document.getElementById('new_niche_input');
    const name = inp.value.trim();
    if (!name) { alert('Please enter a niche name'); inp.focus(); return; }

    const fd = new FormData();
    fd.append('ajax_action', 'add_niche');
    fd.append('niche_name', name);

    const res  = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (!data.success) { alert(data.message || 'Failed to add niche'); return; }

    // Add option to select and select it
    const sel = document.getElementById('niche_select');
    if (sel) {
        const opt = document.createElement('option');
        opt.value = data.id;
        opt.textContent = data.name;
        sel.appendChild(opt);
        sel.value = data.id;
        onNicheChange(data.id);
    }

    cancelAddNiche();
}

// ── Pre-select last used niche on page load ──
document.addEventListener('DOMContentLoaded', function() {
    const nicheSel = document.getElementById('niche_select');
    if (nicheSel && nicheSel.value) {
        // Niche pre-selected by PHP — triggers topic+title chain
        onNicheChange(nicheSel.value);
    } else {
        // No niche select — topics pre-rendered by PHP, load titles directly
        const topicSel = document.getElementById('idea_topic_select');
        if (topicSel && topicSel.value && topicSel.value !== '__manual__') {
            loadTitlesForTopic();
        }
    }
});
</script>

<!-- ===== TOPIC & TITLE ADD/DELETE ===== -->
<script>
// ── Get currently selected niche id ──
function getCurrentNicheId() {
    const sel = document.getElementById('niche_select');
    return sel ? (parseInt(sel.value) || 0) : 0;
}

// ── TOPIC: Add ──
function addTopic() {
    document.getElementById('new_topic_container').style.display = 'block';
    const inp = document.getElementById('new_topic_input');
    inp.value = ''; inp.focus();
}
function cancelAddTopic() {
    document.getElementById('new_topic_container').style.display = 'none';
    document.getElementById('new_topic_input').value = '';
}
async function saveNewTopic() {
    const name     = document.getElementById('new_topic_input').value.trim();
    const nicheId  = getCurrentNicheId();
    if (!name) { alert('Please enter a topic name'); return; }
    const fd = new FormData();
    fd.append('ajax_action', 'add_topic');
    fd.append('topic_name',  name);
    fd.append('niche_id',    nicheId);
    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Failed to add topic'); return; }
    // Add to select
    const sel = document.getElementById('idea_topic_select');
    if (sel) {
        const opt = document.createElement('option');
        opt.value = name; opt.textContent = name;
        // Add before end of the last optgroup or at end
        sel.appendChild(opt);
        sel.value = name;
        loadTitlesForTopic();
    }
    cancelAddTopic();
}

// ── TOPIC: Delete ──
async function deleteTopic() {
    const sel = document.getElementById('idea_topic_select');
    if (!sel || !sel.value || sel.value === '__manual__') { alert('Please select a topic to delete'); return; }
    const topicName = sel.options[sel.selectedIndex].text;
    if (!confirm(`Delete topic "${topicName}"?\n\nThis will also delete all titles for this topic.`)) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_topic');
    fd.append('topic_name',  topicName);
    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Failed to delete topic'); return; }
    sel.remove(sel.selectedIndex);
    // Clear titles
    const titleSel = document.getElementById('idea_title_select');
    if (titleSel) titleSel.innerHTML = '<option value="">-- Select a title --</option>';
    // Auto-select next topic
    if (sel.options.length > 0 && sel.options[0].value) {
        sel.selectedIndex = 0;
        loadTitlesForTopic();
    }
}

// ── TITLE: Add ──
function addTitle() {
    document.getElementById('new_title_container').style.display = 'block';
    const inp = document.getElementById('new_title_input');
    inp.value = ''; inp.focus();
}
function cancelAddTitle() {
    document.getElementById('new_title_container').style.display = 'none';
    document.getElementById('new_title_input').value = '';
}
async function saveNewTitle() {
    const title     = document.getElementById('new_title_input').value.trim();
    const topicSel  = document.getElementById('idea_topic_select');
    const topicName = topicSel ? topicSel.options[topicSel.selectedIndex]?.text : '';
    const nicheId   = getCurrentNicheId();
    if (!title) { alert('Please enter a title'); return; }
    const fd = new FormData();
    fd.append('ajax_action', 'add_title');
    fd.append('title',       title);
    fd.append('topic_name',  topicName);
    fd.append('niche_id',    nicheId);
    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Failed to add title'); return; }
    // Add to select and select it
    const sel = document.getElementById('idea_title_select');
    if (sel) {
        const opt = document.createElement('option');
        opt.value = title; opt.textContent = title;
        sel.appendChild(opt);
        sel.value = title;
        // Sync hidden input
        const inp = document.getElementById('idea_title_input');
        if (inp) inp.value = title;
    }
    cancelAddTitle();
}

// ── TITLE: Delete ──
async function deleteTitle() {
    const sel = document.getElementById('idea_title_select');
    if (!sel || !sel.value) { alert('Please select a title to delete'); return; }
    const title = sel.options[sel.selectedIndex].text;
    if (!confirm(`Delete title "${title}"?`)) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_title');
    fd.append('title',       title);
    const res  = await fetch('', { method:'POST', body:fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Failed to delete title'); return; }
    sel.remove(sel.selectedIndex);
    // Auto-select first remaining title
    if (sel.options.length > 0 && sel.options[0].value) {
        sel.selectedIndex = 0;
    } else {
        sel.selectedIndex = 0;
    }
    // Sync hidden input
    const inp = document.getElementById('idea_title_input');
    if (inp) inp.value = sel.value || '';
}

// ── Auto-load titles for the pre-selected topic on page load ──
// Handled by onNicheChange → loadTopicsByNiche → loadTitlesForTopic chain

</script>

</body>
</html>