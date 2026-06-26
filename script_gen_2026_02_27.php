<?php
session_start();

if(!isset($_SESSION['admin_id']))
{
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id = $_SESSION['client_id'];

require_once 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';

// Set MySQL session variables to prevent timeout
mysqli_query($conn, "SET SESSION wait_timeout = 28800");
mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
mysqli_query($conn, "SET SESSION net_read_timeout = 300");
mysqli_query($conn, "SET SESSION net_write_timeout = 300");

// Get admin name and initial for profile display
$admin_name = '';
$admin_initial = '?';
//echo "user................   not found".$admin_id;die; 
if (isset($admin_id) && $admin_id > 0) {
    $admin_query = mysqli_query($conn, "SELECT * FROM hdb_users WHERE id = '$admin_id' LIMIT 1");
  //  echo "uery".$admin_query;die;
	if ($admin_query && mysqli_num_rows($admin_query) > 0) {
        $admin_row     = mysqli_fetch_assoc($admin_query);
        $admin_name    = $admin_row['firstname'];
        $admin_initial = !empty($admin_name) ? strtoupper(substr($admin_name, 0, 1)) : '?';
		$plan_type     = $admin_row['plan_type'] ?? 'free_trial'; // Default to free_trial if not set
		$is_free_trial = ($plan_type === 'free_trial');

		//echo "user  found";die; 
		
    }
	else
	{
		//echo "user not found";die; 
	}
}
else
{
		echo "user not found";die;
}
// Fetch active languages from hdb_languages table
$languages_query = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order ASC");
$languages = [];
if ($languages_query) {
    while ($lang = mysqli_fetch_assoc($languages_query)) {
        $languages[] = $lang;
    }
}
// Fetch hooks
$hooks_free = mysqli_query($conn, "SELECT id, hook_name, hook_label, hook_description FROM hdb_social_media_hooks ORDER BY id");

// Fetch CTAs
$ctas_free = mysqli_query($conn, "SELECT cta_en FROM hdb_social_media_cta WHERE client_id = '$client_id' ORDER BY cta_en");

// Add hook
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_hook') {
    header('Content-Type: application/json');
    $hook_name = mysqli_real_escape_string($conn, trim($_POST['hook_name'] ?? ''));
    if (empty($hook_name)) { 
        echo json_encode(['success'=>false,'message'=>'Hook name required']); 
        exit; 
    }
    $check = mysqli_query($conn, "SELECT id FROM hdb_social_media_hooks WHERE hook_name='$hook_name' LIMIT 1");
    if (mysqli_num_rows($check) > 0) { 
        echo json_encode(['success'=>false,'message'=>'Hook already exists']); 
        exit; 
    }
    $sql = "INSERT INTO hdb_social_media_hooks (hook_name) VALUES ('$hook_name')";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success'=>true, 'message'=>'Hook added', 'id'=>mysqli_insert_id($conn)]);
    } else {
        echo json_encode(['success'=>false, 'message'=>mysqli_error($conn)]);
    }
    exit;
}

// Add this handler for generating content only
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_content_only') {
    header('Content-Type: application/json');
    
    $prompt = $_POST['prompt'] ?? '';
    
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'Prompt is required']);
        exit;
    }
    
    $result = callChatGPT_inam($prompt);
    
    if ($result['success']) {
        $content = trim($result['response']);
        $content = preg_replace('/^```.*?\n/', '', $content);
        $content = preg_replace('/```$/', '', $content);
        $content = trim($content);
        
        echo json_encode(['success' => true, 'content' => $content]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Generation failed']);
    }
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_category') {
    header('Content-Type: application/json');
    $category = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    if (empty($category)) { echo json_encode(['success'=>false,'message'=>'Category name required']); exit; }
    $check = mysqli_query($conn, "SELECT id FROM hdb_social_media WHERE client_id='$client_id' AND category_key='$category' LIMIT 1");
    if (mysqli_num_rows($check) > 0) { echo json_encode(['success'=>false,'message'=>'Category already exists']); exit; }
    $sql = "INSERT INTO hdb_social_media (client_id, category_key, topic_key, title, sm_status) VALUES ('$client_id','$category','general','New Topic','')";
    echo mysqli_query($conn, $sql) ? json_encode(['success'=>true,'message'=>'Category added']) : json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}
// Add topic
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_topic') {
    header('Content-Type: application/json');
    $category = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    $topic    = mysqli_real_escape_string($conn, trim($_POST['topic'] ?? ''));
    if (empty($topic)||empty($category)) { echo json_encode(['success'=>false,'message'=>'Both required']); exit; }
    $check = mysqli_query($conn, "SELECT id FROM hdb_social_media WHERE client_id='$client_id' AND category_key='$category' AND topic_key='$topic' LIMIT 1");
    if (mysqli_num_rows($check) > 0) { echo json_encode(['success'=>false,'message'=>'Topic already exists']); exit; }
    $sql = "INSERT INTO hdb_social_media (client_id, category_key, topic_key, title, sm_status) VALUES ('$client_id','$category','$topic','New Title','')";
    echo mysqli_query($conn, $sql) ? json_encode(['success'=>true,'message'=>'Topic added']) : json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}
// Add title
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_title') {
    header('Content-Type: application/json');
    $category = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    $topic    = mysqli_real_escape_string($conn, trim($_POST['topic'] ?? ''));
    $title    = mysqli_real_escape_string($conn, trim($_POST['title'] ?? ''));
    if (empty($title)||empty($topic)||empty($category)) { echo json_encode(['success'=>false,'message'=>'All fields required']); exit; }
    $sql = "INSERT INTO hdb_social_media (client_id, category_key, topic_key, title, sm_status) VALUES ('$client_id','$category','$topic','$title','')";
    echo mysqli_query($conn, $sql) ? json_encode(['success'=>true,'message'=>'Title added','id'=>mysqli_insert_id($conn)]) : json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}
// Add CTA
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'add_cta') {
    header('Content-Type: application/json');
    $cta = mysqli_real_escape_string($conn, trim($_POST['cta'] ?? ''));
    if (empty($cta)) { echo json_encode(['success'=>false,'message'=>'CTA text required']); exit; }
    $check = mysqli_query($conn, "SELECT id FROM hdb_social_media_cta WHERE client_id='$client_id' AND cta_en='$cta' LIMIT 1");
    if (mysqli_num_rows($check) > 0) { echo json_encode(['success'=>false,'message'=>'CTA already exists']); exit; }
    $sql = "INSERT INTO hdb_social_media_cta (client_id, cta_en) VALUES ('$client_id','$cta')";
    echo mysqli_query($conn, $sql) ? json_encode(['success'=>true,'message'=>'CTA added']) : json_encode(['success'=>false,'message'=>mysqli_error($conn)]);
    exit;
}

// --- AJAX HANDLERS ---
if (isset($_POST['get_topics'])) {
    $cat = mysqli_real_escape_string($conn, $_POST['category']);
    
    $sql="SELECT DISTINCT topic_key FROM hdb_social_media WHERE client_id = '$client_id' and category_key = '$cat' AND (sm_status = '' OR sm_status IS NULL)";
    
    $res = mysqli_query($conn, "SELECT DISTINCT topic_key FROM hdb_social_media WHERE client_id = '$client_id' and category_key = '$cat' AND (sm_status = '' OR sm_status IS NULL)");
    echo '<option value="">-- Select Topic --</option>';
    while ($row = mysqli_fetch_assoc($res)) { echo "<option value='{$row['topic_key']}'>{$row['topic_key']}</option>"; }
    exit;
}

if (isset($_POST['get_titles'])) {
    $topic = mysqli_real_escape_string($conn, $_POST['topic']);
    $res = mysqli_query($conn, "SELECT id, title FROM hdb_social_media WHERE client_id = '$client_id' and  topic_key = '$topic' AND (sm_status = '' OR sm_status IS NULL)");
    echo '<option value="">-- Select Title --</option>';
    while ($row = mysqli_fetch_assoc($res)) { echo "<option value='{$row['id']}'>" . htmlspecialchars($row['title']) . "</option>"; }
    exit;
}

// New handler for creating scenes from content
if (isset($_POST['action']) && $_POST['action'] == 'create_scenes_from_content') {
    // Set headers for streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    
    ob_implicit_flush(true);
    ob_end_flush();
    
    function sendProgress($percent, $message, $log = null, $log_type = 'info') {
        echo "data: " . json_encode([
            'percent' => $percent,
            'message' => $message,
            'log' => $log,
            'log_type' => $log_type
        ]) . "\n\n";
        ob_flush();
        flush();
    }
    
    $sm_id = mysqli_real_escape_string($conn, $_POST['sm_id']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $topic = mysqli_real_escape_string($conn, $_POST['topic']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $combined_title = mysqli_real_escape_string($conn, $_POST['combined_title']);
    $hook_id = (int)$_POST['hook_id'];
    $cta = mysqli_real_escape_string($conn, $_POST['cta']);
    $target_lang = mysqli_real_escape_string($conn, $_POST['target_lang'] ?? 'en');
    $content = $_POST['content'];
    $admin_id = $_SESSION['admin_id'];
    
    sendProgress(5, "Starting...", "Creating podcast record", 'info');
    
    // First create the podcast record
    $p1_prompt = "Generate SQL INSERT for hdb_podcasts:
    category: '$category'
    topic_key: '$topic'
    title: '$combined_title'
    lang_code: '$target_lang'
    admin_id: '$admin_id'
    client_id: '$client_id'
    hook_id: $hook_id
    
    Requirements:
    1. Generate 5-7 hashtags (lowercase, comma-separated, no spaces, starts with #)
    2. Generate 8-12 keywords (lowercase, comma-separated, no spaces)
    3. Generate short caption_text (max 200 chars)
    
    Output Format:
    INSERT INTO hdb_podcasts (category, topic_key, title, client_id, lang_code, admin_id, hook_id, hashtags, keywords, caption_text, created_date)
    VALUES ('$category', '$topic', '$combined_title', '$client_id', '$target_lang', '$admin_id', $hook_id, '[hashtags]', '[keywords]', '[caption_text]', NOW());
    
    Output SQL ONLY.";

    $res1 = callChatGPT_inam($p1_prompt);
    $sql1 = trim($res1['response']);
    $sql1 = preg_replace('/^```(?:sql)?/i', '', $sql1);
    $sql1 = preg_replace('/```$/i', '', $sql1);
    $sql1 = trim($sql1);

    if (!mysqli_ping($conn)) {
        mysqli_close($conn);
        require_once 'dbconnect_hdb.php';
        mysqli_query($conn, "SET SESSION wait_timeout = 28800");
        mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
    }

    if (mysqli_query($conn, $sql1)) {
        $podcast_id = mysqli_insert_id($conn);
        sendProgress(20, "Podcast created", "✅ Podcast ID: $podcast_id", 'success');
    } else {
        sendProgress(0, "Error!", "❌ Failed to create podcast: " . mysqli_error($conn), 'error');
        echo "data: " . json_encode(['success' => false, 'message' => 'Failed to create podcast', 'final' => true]) . "\n\n";
        exit;
    }

    sendProgress(30, "Generating scenes...", "Calling ChatGPT to create scenes", 'info');

    // Get reel type from content or default to standard
    $reel_type = $_POST['reel_type'] ?? 'standard';
    
    // ===== JSON PROMPT FOR SCENE GENERATION =====
    // ===== JSON PROMPT FOR SCENE GENERATION =====
$scenes_prompt = "You are a JSON generator. Your ONLY task is to output valid JSON.

The contents must be for only 30 seconds. If contents are too long, reduce it.
BREAK THIS SCRIPT INTO SEPARATE SCENES - ONE SCENE PER LINE:

SCRIPT CONTENT:
$content

**CRITICAL INSTRUCTION FOR PODCAST FORMAT:**
IF REEL TYPE IS PODCAST:
- You MUST identify if each line starts with \"Host:\" or \"Guest:\"
- Set actor field to \"host\" for Host lines, \"guest\" for Guest lines
- Remove the \"Host:\" or \"Guest:\" prefix from text_contents

FOR STANDARD AND B-ROLL:
- Set actor field to \"host\" for all lines
- Keep text_contents as is

For each scene, generate a JSON object with these fields:
1. text_contents: THE EXACT LINE FROM THE SCRIPT (with Host:/Guest: prefixes removed if podcast)
2. text_display: A powerful caption (3-7 words) different from audio
3. actor: \"host\" or \"guest\" (based on podcast format)
4. duration: number between 3-7 seconds
5. prompt: DETAILED visual description for AI image generation (30-50 words) - Include:
   - Main subject/scene description
   - Background setting
   - Color palette (warm/cool/soft/vibrant)
   - Lighting (soft light/dramatic/golden hour)
   - Facial expressions/emotions
   - Mood/atmosphere
   - Style (photorealistic/artistic/minimalist)
6. hashtags: space-separated KEYWORD TAGS that describe the MAIN SUBJECT and EMOTION - these will be used to search the image library. USE THESE EXACT FORMATS:

   **IMAGE LIBRARY HASHTAG FORMATS (USE THESE PATTERNS):**
   - For people emotions: [emotion][person] e.g., 'sadwoman', 'worriedman', 'happycouple', 'calmwoman', 'stressedman', 'peacefulwoman', 'anxiousman', 'hopefulwoman'
   - For actions: 'peoplecheering', 'peoplecelebrating', 'peopletalking', 'peoplewalking', 'peoplemeditating'
   - For scenes: 'nature', 'beach', 'mountains', 'sunset', 'cityscape', 'office', 'home'
   - For objects: 'books', 'laptop', 'coffee', 'phone', 'camera'
   
   **IMPORTANT:**
   - DO NOT use generic hashtags like '#happy' or '#sad' - use the combined format like 'happyman', 'sadwoman'
   - Generate 2-3 keyword tags WITHOUT the # symbol, separated by spaces
   - Example: 'peoplecheering happycrowd celebration'
   - Example: 'calmwoman nature peaceful'
   - Example: 'stressedman office worried'

**OUTPUT FORMAT:**
Return a JSON array of scene objects:
[
  {
    \"text_contents\": \"Line 1 text\",
    \"text_display\": \"Powerful caption 1\",
    \"actor\": \"host\",
    \"duration\": 5,
    \"prompt\": \"Detailed image prompt 1...\",
    \"hashtags\": \"peoplecheering happycrowd celebration\"
  }
]

CRITICAL RULES:
1. OUTPUT ONLY JSON - No explanations, no comments, no markdown
2. VALID JSON ONLY - Check for proper commas and brackets
3. ESCAPE double quotes inside strings with backslash: \"
4. DO NOT include any text before or after the JSON";

    // Call ChatGPT
    $res2 = callChatGPT_inam($scenes_prompt);
    $json_response = trim($res2['response']);

    // Clean up the response - remove any markdown code fences
    $json_response = preg_replace('/^```json\s*/i', '', $json_response);
    $json_response = preg_replace('/^```\s*/i', '', $json_response);
    $json_response = preg_replace('/\s*```$/i', '', $json_response);
    $json_response = trim($json_response);

    // Decode JSON
    $scenes = json_decode($json_response, true);

    if (!$scenes || !is_array($scenes)) {
        sendProgress(0, "Error!", "❌ Failed to parse JSON response: " . json_last_error_msg(), 'error');
        echo "data: " . json_encode([
            'success' => false, 
            'message' => 'Invalid JSON response. ' . json_last_error_msg(),
            'debug' => substr($json_response, 0, 500),
            'final' => true
        ]) . "\n\n";
        exit;
    }

    $total_statements = count($scenes);
    $success_count = 0;
    $errors = [];

    sendProgress(40, "Inserting scenes...", "Found " . $total_statements . " scenes to insert", 'info');

    // Prepare the INSERT statement once
    $insert_sql = "INSERT INTO hdb_podcast_stories 
                   (podcast_id, lang_code, category, topic_key, title, actor, 
                    text_contents, text_display, duration, prompt, visual_type, 
                    status, created_date, seq_no, logo_flag, hashtags) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $insert_sql);

    if (!$stmt) {
        sendProgress(0, "Error!", "❌ Failed to prepare statement: " . mysqli_error($conn), 'error');
        exit;
    }

    // Bind parameters
    mysqli_stmt_bind_param($stmt, "isssssssisssiis", 
        $podcast_id,          // i - podcast_id
        $lang_code,           // s - lang_code
        $category,            // s - category
        $topic,               // s - topic_key
        $combined_title,      // s - title
        $actor,               // s - actor
        $text_contents,       // s - text_contents
        $text_display,        // s - text_display
        $duration,            // i - duration
        $prompt,              // s - prompt
        $visual_type,         // s - visual_type
        $status,              // s - status
        $seq_no,              // i - seq_no
        $logo_flag,           // i - logo_flag
        $hashtags             // s - hashtags
    );

    // Set constant values
    $lang_code = $target_lang;
    $visual_type = 'image';
    $status = '';
    $logo_flag = 0;

    // Loop through scenes and insert
    foreach ($scenes as $index => $scene) {
        
        // Check connection and reconnect if needed
        if (!mysqli_ping($conn)) {
            mysqli_close($conn);
            require_once 'dbconnect_hdb.php';
            mysqli_query($conn, "SET SESSION wait_timeout = 28800");
            mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
            
            // Re-prepare statement after reconnect
            $stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt, "isssssssisssiis", 
                $podcast_id, $lang_code, $category, $topic, $combined_title, 
                $actor, $text_contents, $text_display, $duration, $prompt, 
                $visual_type, $status, $seq_no, $logo_flag, $hashtags);
            
            sendProgress(40, "Reconnecting...", "⚠️ Database reconnected", 'warning');
        }
        
        // Set values from scene
        $text_contents = $scene['text_contents'];
        $actor = $scene['actor'] ?? 'host'; // Use actor from scene, default to host
        
        $text_display = $scene['text_display'] ?? $scene['text_contents'];
        $duration = (int)($scene['duration'] ?? 5);
        $prompt = $scene['prompt'] ?? '';
        $hashtags = $scene['hashtags'] ?? '';
        $seq_no = $index + 1;
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
            $percent = 40 + floor(($success_count / $total_statements) * 50);
            sendProgress($percent, "Inserting scenes...", "✅ Inserted scene " . $success_count . " (Actor: " . $actor . ")", 'success');
        } else {
            $error = mysqli_stmt_error($stmt);
            $errors[] = $error;
            sendProgress(40 + floor(($index / $total_statements) * 50), "Inserting scenes...", "❌ Error on scene " . ($index+1) . ": " . $error, 'error');
            error_log("Scene Insert Error: " . $error . " for scene: " . print_r($scene, true));
        }
        
        // Small delay to prevent overwhelming the server
        usleep(50000); // 0.05 seconds 
    }

    mysqli_stmt_close($stmt);

    if ($success_count > 0) {
        // Update social media status
        mysqli_query($conn, "UPDATE hdb_social_media SET sm_status='GENERATED', hook_id='$hook_id' WHERE id='$sm_id'");
        
        sendProgress(100, "Complete!", "✅ All $success_count scenes created successfully!", 'success');
        
        echo "data: " . json_encode([
            'success' => true,
            'podcast_id' => $podcast_id,
            'scene_count' => $success_count,
            'final' => true
        ]) . "\n\n";
    } else {
        sendProgress(0, "Error!", "❌ Failed to create scenes: " . implode('; ', $errors), 'error');
        
        echo "data: " . json_encode([
            'success' => false, 
            'message' => 'Failed to create scenes. ' . implode('; ', $errors),
            'debug' => substr($json_response, 0, 500),
            'final' => true
        ]) . "\n\n";
    }
    exit;
}

// Handler for processing free format content
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'process_free_content') {
    header('Content-Type: application/json');
    
    $prompt = $_POST['prompt'] ?? '';
    
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'Prompt is required']);
        exit;
    }
    
    $result = callChatGPT_inam($prompt);
    
    if ($result['success']) {
        $content = trim($result['response']);
        $content = preg_replace('/^```.*?\n/', '', $content);
        $content = preg_replace('/```$/', '', $content);
        $content = trim($content);
        
        echo json_encode(['success' => true, 'content' => $content]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Processing failed']);
    }
    exit;
}

// Handler for creating free scenes
if (isset($_POST['action']) && $_POST['action'] == 'create_free_scenes') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    
    ob_implicit_flush(true);
    ob_end_flush();
    
    function sendProgressFree($percent, $message, $log = null, $log_type = 'info') {
        echo "data: " . json_encode([
            'percent' => $percent,
            'message' => $message,
            'log' => $log,
            'log_type' => $log_type
        ]) . "\n\n";
        ob_flush();
        flush();
    }
    
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $combined_title = mysqli_real_escape_string($conn, $_POST['combined_title'] ?? $title);
    $hook_id = (int)($_POST['hook_id'] ?? 1);
    $cta = mysqli_real_escape_string($conn, $_POST['cta'] ?? '');
    $content = $_POST['content'] ?? '';
    $target_lang = mysqli_real_escape_string($conn, $_POST['target_lang'] ?? 'en');
    $reel_type = mysqli_real_escape_string($conn, $_POST['reel_type'] ?? 'multi-scene');
    $admin_id = $_SESSION['admin_id'];
    
    sendProgressFree(5, "Starting...", "Creating podcast record", 'info');
    
    $p1_prompt = "Generate SQL INSERT for hdb_podcasts:
    category: 'free-format'
    topic_key: 'custom'
    title: '$combined_title'
    lang_code: '$target_lang'
    admin_id: '$admin_id'
	client_id: '$client_id'    // MAKE SURE THIS IS USING THE GLOBAL $client_id
    hook_id: $hook_id
    cta: '$cta'
    
    Requirements:
    1. Generate 5-7 hashtags (lowercase, comma-separated, no spaces, starts with #)
    2. Generate 8-12 keywords (lowercase, comma-separated, no spaces)
    3. Generate short caption_text (max 200 chars)
    
    Output Format:
    INSERT INTO hdb_podcasts (category, topic_key, title, lang_code, admin_id, hook_id, hashtags, keywords, caption_text, created_date)
    VALUES ('free-format', 'custom', '$combined_title', '$target_lang', '$admin_id', $hook_id, '[hashtags]', '[keywords]', '[caption_text]', NOW());
    
    Output SQL ONLY.";

    $res1 = callChatGPT_inam($p1_prompt);
    $sql1 = trim($res1['response']);
    $sql1 = preg_replace('/^```(?:sql)?/i', '', $sql1);
    $sql1 = preg_replace('/```$/i', '', $sql1);
    $sql1 = trim($sql1);

    if (!mysqli_ping($conn)) {
        mysqli_close($conn);
        require_once 'dbconnect_hdb.php';
        mysqli_query($conn, "SET SESSION wait_timeout = 28800");
        mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
    }

    if (mysqli_query($conn, $sql1)) {
        $podcast_id = mysqli_insert_id($conn);
        sendProgressFree(20, "Podcast created", "✅ Podcast ID: $podcast_id", 'success');
    } else {
        sendProgressFree(0, "Error!", "❌ Failed to create podcast: " . mysqli_error($conn), 'error');
        echo "data: " . json_encode(['success' => false, 'message' => 'Failed to create podcast', 'final' => true]) . "\n\n";
        exit;
    }

    sendProgressFree(30, "Generating scenes...", "Preparing scenes", 'info');

    // Handle based on reel type
    if ($reel_type === 'single-scene') {
        // SINGLE-SCENE: Create one scene with the entire content
        sendProgressFree(35, "Creating single scene...", "📌 Single-scene mode: using entire text as one scene", 'info');
        
        $scenes = [
            [
                'text_contents' => $content,
                'text_display' => substr($content, 0, 50) . '...',
                'actor' => 'host',
                'duration' => 15,
                'prompt' => 'A scene based on: ' . substr($content, 0, 100),
                'hashtags' => '#singlescene #content'
            ]
        ];
        sendProgressFree(40, "Single scene created", "✅ Created 1 scene for single-scene mode", 'success');
    } else {
        // MULTI-SCENE: Use ChatGPT to generate multiple scenes
        sendProgressFree(35, "Generating multiple scenes...", "🎬 Multi-scene mode: breaking into separate scenes", 'info');
        
    // ===== JSON PROMPT FOR SCENE GENERATION =====
$scenes_prompt = "You are a JSON generator. Your ONLY task is to output valid JSON.

The contents must be for only 30 seconds. If contents are too long, reduce it.
BREAK THIS SCRIPT INTO SEPARATE SCENES - ONE SCENE PER LINE:

SCRIPT CONTENT:
$content

**CRITICAL INSTRUCTION FOR PODCAST FORMAT:**
IF REEL TYPE IS PODCAST:
- You MUST identify if each line starts with \"Host:\" or \"Guest:\"
- Set actor field to \"host\" for Host lines, \"guest\" for Guest lines
- Remove the \"Host:\" or \"Guest:\" prefix from text_contents

FOR STANDARD AND B-ROLL:
- Set actor field to \"host\" for all lines
- Keep text_contents as is

For each scene, generate a JSON object with these fields:
1. text_contents: THE EXACT LINE FROM THE SCRIPT (with Host:/Guest: prefixes removed if podcast)
2. text_display: A powerful caption (3-7 words) different from audio
3. actor: \"host\" or \"guest\" (based on podcast format)
4. duration: number between 3-7 seconds
5. prompt: DETAILED visual description for AI image generation (30-50 words) - Include:
   - Main subject/scene description
   - Background setting
   - Color palette (warm/cool/soft/vibrant)
   - Lighting (soft light/dramatic/golden hour)
   - Facial expressions/emotions
   - Mood/atmosphere
   - Style (photorealistic/artistic/minimalist)
6. hashtags: space-separated KEYWORD TAGS that describe the MAIN SUBJECT and EMOTION - these will be used to search the image library. USE THESE EXACT FORMATS:

   **IMAGE LIBRARY HASHTAG FORMATS (USE THESE PATTERNS):**
   - For people emotions: [emotion][person] e.g., 'sadwoman', 'worriedman', 'happycouple', 'calmwoman', 'stressedman', 'peacefulwoman', 'anxiousman', 'hopefulwoman'
   - For actions: 'peoplecheering', 'peoplecelebrating', 'peopletalking', 'peoplewalking', 'peoplemeditating'
   - For scenes: 'nature', 'beach', 'mountains', 'sunset', 'cityscape', 'office', 'home'
   - For objects: 'books', 'laptop', 'coffee', 'phone', 'camera'
   
   **IMPORTANT:**
   - DO NOT use generic hashtags like '#happy' or '#sad' - use the combined format like 'happyman', 'sadwoman'
   - Generate 2-3 keyword tags WITHOUT the # symbol, separated by spaces
   - Example: 'peoplecheering happycrowd celebration'
   - Example: 'calmwoman nature peaceful'
   - Example: 'stressedman office worried'

**OUTPUT FORMAT:**
Return a JSON array of scene objects:
[
  {
    \"text_contents\": \"Line 1 text\",
    \"text_display\": \"Powerful caption 1\",
    \"actor\": \"host\",
    \"duration\": 5,
    \"prompt\": \"Detailed image prompt 1...\",
    \"hashtags\": \"peoplecheering happycrowd celebration\"
  }
]

**CRITICAL RULES:**
1. OUTPUT ONLY JSON - No explanations, no comments, no markdown
2. VALID JSON ONLY - Check for proper commas and brackets
3. ESCAPE double quotes inside strings with backslash: \"
4. DO NOT include any text before or after the JSON";

        $res2 = callChatGPT_inam($scenes_prompt);
        $json_response = trim($res2['response']);
        $json_response = preg_replace('/^```json\s*/i', '', $json_response);
        $json_response = preg_replace('/^```\s*/i', '', $json_response);
        $json_response = preg_replace('/\s*```$/i', '', $json_response);
        $json_response = trim($json_response);

        $scenes = json_decode($json_response, true);

        if (!$scenes || !is_array($scenes)) {
            sendProgressFree(0, "Error!", "❌ Failed to parse JSON response", 'error');
            echo "data: " . json_encode(['success' => false, 'message' => 'Invalid JSON response', 'final' => true]) . "\n\n";
            exit;
        }
        
        sendProgressFree(40, "Scenes generated", "✅ Found " . count($scenes) . " scenes to insert", 'success');
    }

    $total_statements = count($scenes);
    $success_count = 0;
    $errors = [];

    sendProgressFree(45, "Inserting scenes...", "Starting database insertion", 'info');

    $insert_sql = "INSERT INTO hdb_podcast_stories 
                   (podcast_id, lang_code, category, topic_key, title, actor, 
                    text_contents, text_display, duration, prompt, visual_type, 
                    status, created_date, seq_no, logo_flag, hashtags) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($stmt, "isssssssisssiis", 
        $podcast_id, $lang_code, $category, $topic, $combined_title, 
        $actor, $text_contents, $text_display, $duration, $prompt, 
        $visual_type, $status, $seq_no, $logo_flag, $hashtags);

    $lang_code = $target_lang;
    $category = 'free-format';
    $topic = 'custom';
    $visual_type = 'image';
    $status = 'PENDING';
    $logo_flag = 0;

    foreach ($scenes as $index => $scene) {
        $text_contents = $scene['text_contents'] ?? '';
        $text_display = $scene['text_display'] ?? $text_contents;
        $actor = $scene['actor'] ?? 'host';
        $duration = (int)($scene['duration'] ?? 5);
        $prompt = $scene['prompt'] ?? '';
        $hashtags = $scene['hashtags'] ?? '';
        $seq_no = $index + 1;
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
            $percent = 45 + floor(($success_count / $total_statements) * 45);
            sendProgressFree($percent, "Inserting scenes...", "✅ Inserted scene " . $success_count . "/" . $total_statements . " (Actor: " . $actor . ")", 'success');
        } else {
            $error = mysqli_stmt_error($stmt);
            $errors[] = $error;
            sendProgressFree(45 + floor(($index / $total_statements) * 45), "Inserting scenes...", "❌ Error on scene " . ($index+1) . ": " . $error, 'error');
        }
        usleep(50000);
    }

    mysqli_stmt_close($stmt);

    if ($success_count > 0) {
        sendProgressFree(100, "Complete!", "✅ All $success_count scenes created successfully!", 'success');
        echo "data: " . json_encode([
            'success' => true,
            'podcast_id' => $podcast_id,
            'scene_count' => $success_count,
            'final' => true
        ]) . "\n\n";
    } else {
        sendProgressFree(0, "Error!", "❌ Failed to create scenes: " . implode('; ', $errors), 'error');
        echo "data: " . json_encode(['success' => false, 'message' => 'Failed to create scenes', 'final' => true]) . "\n\n";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>VideoVizard - Create Script</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.5;
        }

        /* Header - Mobile First */
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

        .tagline { 
            font-size: 9px; 
            color: rgba(255,255,255,0.6); 
            letter-spacing: 0.3px; 
            display: none;
        }

        /* Profile Dropdown */
        .profile-wrap { 
            position: relative; 
        }

        .profile-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            color: #fff;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            min-height: 44px;
        }

        .profile-btn .avatar {
            width: 28px;
            height: 28px;
            background: #5fd1ff;
            color: #0f2a44;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            flex-shrink: 0;
        }

        .profile-btn .username {
            display: none;
        }

        .profile-btn .chevron { 
            font-size: 12px; 
            transition: transform 0.2s; 
        }

        .profile-btn.open .chevron { 
            transform: rotate(180deg); 
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18);
            min-width: 220px;
            overflow: hidden;
            z-index: 9999;
            border: 1px solid var(--border);
        }

        .dropdown-menu.open { 
            display: block; 
            animation: slideDown 0.2s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dropdown-user {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .dropdown-user .d-name { 
            font-size: 14px; 
            font-weight: 700; 
            color: var(--dark-blue); 
        }

        .dropdown-user .d-role { 
            font-size: 12px; 
            color: var(--muted); 
            margin-top: 2px; 
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            font-size: 14px;
            color: var(--text);
            text-decoration: none;
            transition: background 0.2s;
            min-height: 48px;
        }

        .dropdown-item:hover { 
            background: #f1f5f9; 
        }

        .dropdown-item .d-icon { 
            font-size: 16px; 
            width: 20px; 
        }

        .dropdown-divider { 
            height: 1px; 
            background: var(--border); 
        }

        .dropdown-item.logout { 
            color: var(--error); 
        }

        .dropdown-item.logout:hover { 
            background: #fef2f2; 
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

        /* Tab Bar - Mobile Scrollable */
        .tab-bar {
            display: flex;
            gap: 6px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 8px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }

        .tab-bar::-webkit-scrollbar {
            height: 3px;
        }

        .tab-bar::-webkit-scrollbar-track {
            background: var(--border);
            border-radius: 4px;
        }

        .tab-bar::-webkit-scrollbar-thumb {
            background: var(--accent);
            border-radius: 4px;
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

        .tab:hover {
            background: rgba(95, 209, 255, 0.1);
            border-color: var(--accent);
            color: var(--dark-blue);
        }

        .tab.active {
            background: var(--dark-blue);
            color: white;
            border-color: var(--dark-blue);
            box-shadow: 0 4px 12px rgba(15, 42, 68, 0.2);
        }

        .tab-content {
            display: block;
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
            letter-spacing: 0.3px;
        }

        .field-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .field-header label { 
            margin-bottom: 0; 
        }

        select, textarea, input[type="text"], input[type="email"], input[type="password"] {
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
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(95, 209, 255, 0.2);
        }

        /* Language Badge */
        .lang-badge {
            display: inline-block;
            background: var(--green);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* Icon Buttons */
        .icon-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            transition: all 0.2s;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .icon-btn.help-btn { 
            color: var(--info); 
            background: #dbeafe; 
        }

        .icon-btn.help-btn:hover { 
            background: #bfdbfe; 
        }

        .icon-btn.add-btn { 
            color: var(--success); 
            background: #d1fae5; 
        }

        .icon-btn.add-btn:hover { 
            background: #a7f3d0; 
        }

        /* Tooltip */
        .tooltip-wrap { 
            position: relative; 
            display: inline-block; 
        }

        .tooltip-box {
            display: none;
            position: absolute;
            left: 0;
            top: 36px;
            background: var(--dark-blue);
            color: #fff;
            font-size: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            width: 260px;
            z-index: 999;
            line-height: 1.6;
            font-weight: 400;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .tooltip-box::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 16px;
            border: 8px solid transparent;
            border-top: none;
            border-bottom-color: var(--dark-blue);
        }

        .tooltip-wrap:hover .tooltip-box { 
            display: block; 
        }

        /* Random Box */
        .random-box {
            background: #fef3c7;
            padding: 10px 14px;
            border-radius: 10px;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            font-size: 13px;
        }

        .random-box input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Prompt Editor */
        .prompt-editor {
            width: 100%;
            min-height: 160px;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-family: 'Inter', monospace;
            font-size: 13px;
            line-height: 1.6;
            resize: vertical;
            background: #fff;
        }

        .prompt-variables {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 10px;
            font-size: 12px;
            color: var(--muted);
            border: 1px solid var(--border);
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .variable-badge {
            display: inline-block;
            background: var(--dark-blue);
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }

        .prompt-actions {
            display: flex;
            gap: 10px;
            margin: 12px 0;
            flex-wrap: wrap;
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

        .btn:active {
            transform: scale(0.98);
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

        .btn-small {
            padding: 10px 16px;
            font-size: 13px;
            background: #f1f5f9;
            color: var(--dark-blue);
            border: 1px solid var(--border);
            min-height: 40px;
        }

        .btn-small:hover {
            background: #e2e8f0;
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

        /* Mode Buttons */
        .mode-btn {
            flex: 1;
            padding: 16px;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            min-height: 56px;
            color: white;
            opacity: 0.8;
        }

        .mode-btn.active {
            opacity: 1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: scale(1.02);
        }

        #btn_ai_mode {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
        }

        #btn_user_mode {
            background: linear-gradient(135deg, #10b981, #34d399);
        }

        /* Mode Title Box */
        .mode-title-box {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .mode-title-box.ai-mode {
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: white;
        }

        .mode-title-box.user-mode {
            background: linear-gradient(135deg, #10b981, #059669);
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
            font-weight: normal;
        }

        /* Podcast Voice Indicator */
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

        .podcast-voice-info .icon {
            font-size: 20px;
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
            background: linear-gradient(90deg, var(--dark-blue), var(--accent));
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
            font-family: 'Inter', monospace;
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

        /* Processing Spinner */
        .processing-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Modal */
        .add-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
            backdrop-filter: blur(4px);
        }

        .add-modal {
            display: none;
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 20px;
            padding: 28px;
            width: 90%;
            max-width: 400px;
            z-index: 9999;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .add-modal h3 { 
            margin: 0 0 20px; 
            color: var(--dark-blue); 
            font-size: 18px; 
        }

        .add-modal input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            margin-bottom: 16px;
        }

        .add-modal input:focus { 
            outline: none; 
            border-color: var(--accent); 
        }

        .modal-btns { 
            display: flex; 
            gap: 12px; 
            justify-content: flex-end; 
        }

        .btn-modal-cancel { 
            background: var(--border); 
            color: var(--text); 
            border: none; 
            padding: 12px 20px; 
            border-radius: 10px; 
            cursor: pointer; 
            font-weight: 600; 
            min-height: 44px;
        }

        .btn-modal-save { 
            background: var(--success); 
            color: #fff; 
            border: none; 
            padding: 12px 24px; 
            border-radius: 10px; 
            cursor: pointer; 
            font-weight: 600; 
            min-height: 44px;
        }

        .modal-msg { 
            font-size: 13px; 
            margin-bottom: 16px; 
            min-height: 20px; 
        }

        .modal-msg.success { 
            color: var(--success); 
        }

        .modal-msg.error { 
            color: var(--error); 
        }

        /* Success Modal */
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

        /* Status Message */
        #status_msg {
            padding: 16px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
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
            font-size: 13px; 
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
            min-height: 44px;
            display: inline-block;
        }

        .footer-links a:active { 
            color: var(--accent); 
        }

        /* Responsive Breakpoints */
        @media (min-width: 768px) {
            .vidora-header {
                padding: 14px 24px;
            }
            
            .container {
                padding: 24px;
            }
            
            .profile-btn .username {
                display: inline;
            }
            
            .tagline {
                display: block;
            }
            
            .mode-title-box h2 {
                font-size: 20px;
            }
            
            .site-footer {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 16px 30px;
            }
        }

        @media (min-width: 1024px) {
            .container {
                padding: 30px;
            }
        }

        @media (max-width: 480px) {
            .button-group {
                flex-direction: column;
            }
            
            .button-group .btn {
                width: 100%;
            }
            
            .field-header {
                justify-content: space-between;
            }
            
            .random-box {
                flex-wrap: wrap;
            }
        }
		/* Add to your existing CSS */
		#prompt_error_log {
			margin-top: 20px;
			padding: 15px;
			background: #1e293b;
			color: #e2e8f0;
			border-radius: 8px;
			font-family: monospace;
			font-size: 12px;
			max-height: 400px;
			overflow-y: auto;
			border: 1px solid #334155;
			transition: all 0.3s ease;
		}

		#prompt_error_log button {
			background: #334155;
			color: white;
			border: none;
			padding: 4px 12px;
			border-radius: 4px;
			cursor: pointer;
			font-size: 11px;
			transition: background 0.2s;
		}

		#prompt_error_log button:hover {
			background: #475569;
		}

		#prompt_log_content {
			white-space: pre-wrap;
			word-break: break-word;
			line-height: 1.6;
		}

		/* Optional: Add a toggle button in the UI */
		.debug-toggle {
			background: #334155;
			color: white;
			border: none;
			padding: 8px 16px;
			border-radius: 6px;
			cursor: pointer;
			font-size: 12px;
			margin-bottom: 10px;
		}

		.debug-toggle:hover {
			background: #475569;
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
                <div class="tagline">Social Media Automation</div>
            </div>
        </a>
    </div>

    <div class="profile-wrap">
        <button class="profile-btn" id="profileBtn" onclick="toggleDropdown()">
            <div class="avatar"><?= htmlspecialchars($admin_initial) ?></div>
            <span class="username"><?= htmlspecialchars($admin_name) ?></span>
            <span class="chevron">▼</span>
        </button>
        <div class="dropdown-menu" id="dropdownMenu">
            <div class="dropdown-user">
                <div class="d-name"><?= htmlspecialchars($admin_name) ?></div>
                <div class="d-role">Client Account</div>
            </div>
            <a href="profile.php" class="dropdown-item">
                <span class="d-icon">👤</span> My Profile
            </a>
            <a href="settings.php" class="dropdown-item">
                <span class="d-icon">⚙️</span> Settings
            </a>
            <div class="dropdown-divider"></div>
            <a href="logout.php" class="dropdown-item logout">
                <span class="d-icon">🚪</span> Logout
            </a>
        </div>
    </div>
</div>

<div class="container">
    <!-- Main Card -->
    <div class="card">
        <div class="card-header">
            <h1>🎬 Create Your Script</h1>
            <p>Generate a compelling video script in seconds using AI — or start from scratch and customize it your way.</p>
        </div>
        
        <div class="card-body">
            <!-- Three Tabs -->
            <div class="tab-bar">
                <div class="tab active" onclick="switchTab('my-idea')" id="tab-my-idea">
                    <span>💡</span> My Idea
                </div>
                <div class="tab" onclick="switchTab('my-content')" id="tab-my-content">
                    <span>📝</span> My Content
                </div>
                <div class="tab" onclick="switchTab('database')" id="tab-database">
                    <span>📚</span> From Database
                </div>
            </div>
            
            <!-- My Idea Tab -->
            <div id="my-idea-tab" class="tab-content">
                <div class="mode-title-box ai-mode">
                    <h2>
                        <span>💡</span> My Idea
                        <span class="mode-badge">AI-Powered Creation</span>
                    </h2>
                </div>
                
                <!-- Language Selector -->
                <!-- Language Selector -->
				<div class="form-group">
					<label>🌐 Language</label>
					<select id="idea_lang_select">
						<?php foreach ($languages as $lang): ?>
							<option value="<?= $lang['language_code'] ?>" <?= $lang['language_code'] == 'en' ? 'selected' : '' ?>>
								<?= $lang['flag_emoji'] ?> <?= $lang['language'] ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
                
                <!-- Reel Type -->
                <div class="form-group">
                    <label>🎬 Reel Type -myidea</label>
                    <select id="idea_reel_type" onchange="checkPodcastType('idea')">
                        <option value="standard">Standard</option>
                        <option value="broll">B-Roll</option>
                        <option value="podcast">Podcast</option>
                    </select>
                </div>
				
				<!-- Video Duration -->
				<div class="form-group">
					<label>⏱️ Video Duration</label>
					<?php if ($is_free_trial): ?>
						<!-- Free trial users: fixed at 30 seconds, disabled dropdown -->
						<div style="display: flex; align-items: center; gap: 10px;">
							<select id="idea_duration" disabled style="background: #f1f5f9; opacity: 0.7;">
								<option value="30" selected>30 seconds</option>
							</select>
							<span class="free-trial-badge" style="background: #f59e0b; color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
								⭐ Free Trial - 30s only
							</span>
						</div>
						<small style="display: block; color: #64748b; margin-top: 4px; font-size: 12px;">
							Upgrade to Pro for longer videos (40s, 50s, 60s, etc.)
						</small>
					<?php else: ?>
						<!-- Paid users: full selection -->
						<select id="idea_duration"> 
							<option value="10">10 seconds</option>
							<option value="20">20 seconds</option>
							<option value="30" selected>30 seconds</option>
							<option value="40">40 seconds</option>
							<option value="50">50 seconds</option>
							<option value="60">60 seconds</option>
							<option value="70">70 seconds</option>
							<option value="80">80 seconds</option>
							<option value="90">90 seconds</option>
						</select>
						
					<?php endif; ?>
				</div>
				<script>
				
					.free-trial-badge {
						background: #f59e0b;
						color: white;
						padding: 4px 12px;
						border-radius: 30px;
						font-size: 12px;
						font-weight: 600;
						display: inline-flex;
						align-items: center;
						gap: 4px;
						white-space: nowrap;
					}

					.free-trial-badge::before {
						content: "⭐";
						margin-right: 4px;
					}
				</script>
                
                <!-- Podcast Voice Info (shows only when podcast selected) -->
                <div id="idea_podcast_info" class="podcast-voice-info" style="display: none;">
                    <span class="icon">🎙️</span>
                    <span>Podcast format: Lines starting with <strong>Host:</strong> and <strong>Guest:</strong> will be automatically detected</span>
                </div>
                
                <!-- Topic -->
                <div class="form-group">
                    <label>📌 What is your video about?</label>
                    <input type="text" id="idea_topic" placeholder="e.g., Stress Relief Techniques, Morning Motivation..." value="Stress Relief">
                </div>
                
                <!-- Title with Auto-generate -->
                <!-- Title -->
				<div class="form-group">
					<label>📌 Title</label>
					<input type="text" id="idea_title" placeholder="Enter a title for your video..." value="5 Ways to Reduce Stress" required>
				</div>
                
                <!-- Audience -->
                <div class="form-group">
                    <label>👥 Target Audience</label>
                    <select id="idea_audience">
                        <option value="general">General Audience</option>
                        <option value="students">Students</option>
                        <option value="professionals">Professionals</option>
                        <option value="stressed_people" selected>People Feeling Stressed</option>
                        <option value="beginners">Beginners</option>
                        <option value="custom">Custom...</option>
                    </select>
                    <input type="text" id="idea_audience_custom" placeholder="Enter custom audience..." style="margin-top:8px; display:none;">
                </div>
                
                <!-- Hook with Random Checkbox -->
                <div class="form-group">
					<div class="field-header">
						<label>🎣 Opening Style</label>
						<div style="display: flex; align-items: center; gap: 8px;">
							<input type="checkbox" id="idea_rnd_hook" checked>
							<label for="idea_rnd_hook" style="margin:0; font-weight:normal;">Select randomly</label>
						</div>
						<button type="button" class="icon-btn add-btn" onclick="openFreeHookModal()" style="margin-left: auto;">+ Add Hook</button>
					</div>
					<select id="idea_hook_select" onchange="handleHookSelection()">
						<option value="">-- Select Opening Style --</option>
						<?php
						mysqli_data_seek($hooks_free, 0);
						while ($hook = mysqli_fetch_assoc($hooks_free)) {
							$hook_label = htmlspecialchars($hook['hook_label'] ?? $hook['hook_name']);
							echo "<option value='{$hook['id']}' data-name='{$hook['hook_name']}'>{$hook_label}</option>";
						}
						?>
					</select>
				</div>

				<script>
				function handleHookSelection() {
					const hookSelect = document.getElementById('idea_hook_select');
					const randomCheckbox = document.getElementById('idea_rnd_hook');
					
					if (hookSelect.value !== '') {
						randomCheckbox.checked = false;
					}
				}

				document.addEventListener('DOMContentLoaded', function() {
					const randomCheckbox = document.getElementById('idea_rnd_hook');
					const hookSelect = document.getElementById('idea_hook_select');
					
					if (randomCheckbox) {
						randomCheckbox.addEventListener('change', function() {
							if (this.checked) {
								hookSelect.value = '';
								hookSelect.disabled = true; // Disable dropdown when random is checked
							} else {
								hookSelect.disabled = false; // Enable dropdown when random is unchecked
							}
						});
						
						// Initial state - if random is checked by default, disable dropdown
						if (randomCheckbox.checked) {
							hookSelect.disabled = true;
						}
					}
				});
				</script>
                
                <!-- Goal -->
                <div class="form-group">
                    <label>🎯 Goal</label>
                    <select id="idea_goal">
                        <option value="grow">Grow Followers</option>
                        <option value="educate" selected>Educate</option>
                        <option value="inspire">Inspire / Motivate</option>
                        <option value="sell">Sell a Product</option>
                    </select>
                </div>
                
                <!-- CTA -->
                <div class="form-group">
                    <label>📢 Call to Action (CTA)</label>
                    <input type="text" id="idea_cta" placeholder="e.g., Follow for more daily tips..." value="Follow for more stress relief tips">
                </div>
                
                
                
                <!-- Buttons -->
                <div class="button-group">
                    <button class="btn btn-primary" id="ideaProcessBtn" onclick="processIdeaContent()">📝 Step 1: Generate Content</button>
                   
                </div>
				  <!-- Processed Content Container -->
                <div id="idea_processed_container" style="display: none;">
                    <div class="form-group">
                        <label>📝 Generated Content (Edit if needed)</label>
                        <textarea id="idea_processed_content" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
                    </div>
                </div>
				<div class="button-group">
                
                    <button class="btn btn-success" id="ideaCreateScenesBtn" onclick="createIdeaScenes()" style="display:none;">🎬 Step 2: Create Scenes</button>
                </div>
				
				
            </div>
            
            <!-- My Content Tab -->
            <div id="my-content-tab" class="tab-content hidden">
			<div class="mode-title-box user-mode">
				<h2>
					<span>📝</span> My Content
					<span class="mode-badge">Paste Your Own Script</span>
				</h2>
			</div>
			
			<!-- Language Selector -->
		<!-- Language Selector -->
			<div class="form-group">
				<label>🌐 Language</label>
				<select id="content_lang_select">
					<?php foreach ($languages as $lang): ?>
						<option value="<?= $lang['language_code'] ?>" <?= $lang['language_code'] == 'en' ? 'selected' : '' ?>>
							<?= $lang['flag_emoji'] ?> <?= $lang['language'] ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<!-- Reel Type -->
			<div class="form-group">
				<label>🎬 Reel Type</label>
				<select id="content_reel_type" onchange="checkPodcastType('content')">
					<option value="multi-scene">📱 Dynamic Reel (Auto-cut into scenes)</option>
					<option value="single-scene">📺 Full Video (Single scene)</option>
					<option value="podcast">🎙️ Podcast (Host & Guest)</option>
				</select>
			</div>
			
			<!-- Podcast Voice Info -->
			<div id="content_podcast_info" class="podcast-voice-info" style="display: none;">
				<span class="icon">🎙️</span>
				<span>Make sure your script has lines starting with <strong>Host:</strong> and <strong>Guest:</strong></span>
			</div>
			
			<!-- Video Duration -->
			<div class="form-group">
				<label>⏱️ Video Duration</label>
				<?php if ($is_free_trial): ?>
					<!-- Free trial users: fixed at 30 seconds, disabled dropdown -->
					<div style="display: flex; align-items: center; gap: 10px;">
						<select id="content_duration" disabled style="background: #f1f5f9; opacity: 0.7;">
							<option value="30" selected>30 seconds</option>
						</select>
						<span class="free-trial-badge" style="background: #f59e0b; color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;">
							⭐ Free Trial - 30s only
						</span>
					</div>
					<small style="display: block; color: #64748b; margin-top: 4px; font-size: 12px;">
						Upgrade to Pro for longer videos (40s, 50s, 60s, etc.)
					</small>
				<?php else: ?>
					<!-- Paid users: full selection -->
					<select id="content_duration">
						<option value="10">10 seconds</option>
						<option value="20">20 seconds</option>
						<option value="30" selected>30 seconds</option>
						<option value="40">40 seconds</option>
						<option value="50">50 seconds</option>
						<option value="60">60 seconds</option>
						<option value="70">70 seconds</option>
						<option value="80">80 seconds</option>
						<option value="90">90 seconds</option>
					</select>
					<small style="display: block; color: #64748b; margin-top: 4px; font-size: 12px;">
						Approx. <span id="content_word_count">75</span> words (2.5 words/second)
					</small>
				<?php endif; ?>
			</div>
			
			<!-- Title -->
			<div class="form-group">
				<label>📌 Title</label>
				<input type="text" id="content_title" placeholder="Enter a title for your video..." value="My Custom Video">
			</div>
			
			<!-- Content Input -->
			<div class="form-group">
				<label>📝 Your Script / Story</label>
				<textarea id="content_story" rows="8" placeholder="Paste your script here... For podcast format, start lines with 'Host:' and 'Guest:'" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
			</div>
			
			<!-- Processed Content Container -->
			<div id="content_processed_container" style="display: none;">
				<div class="form-group">
					<label>📝 Processed Content (Edit if needed)</label>
					<textarea id="content_processed_content" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
				</div>
				
				<!-- CTA - Now appears AFTER generated content -->
				<div class="form-group" style="margin-top: 20px;">
					<label>📢 Call to Action (CTA)</label>
					<input type="text" id="content_cta" placeholder="e.g., Follow for more daily tips..." value="Follow for more stress relief tips">
				</div>
				
				<!-- Step 2 Button - Appears after CTA -->
				<div class="button-group" style="margin-top: 10px;">
					<button class="btn btn-success" id="contentCreateScenesBtn" onclick="createContentScenes()" style="display:none; width:100%;">🎬 Step 2: Create Scenes</button>
				</div>
			</div>
			
			<!-- Step 1 Button - Always visible at bottom -->
			<div class="button-group">
				<button class="btn btn-primary" id="contentProcessBtn" onclick="processContent()" style="width:100%;">📝 Step 1: Process Content</button>
			</div>
		</div>

		<script>
		// Add this to your existing JavaScript for dynamic word count hint
		document.addEventListener('DOMContentLoaded', function() {
			const durationSelect = document.getElementById('content_duration');
			const wordCountSpan = document.getElementById('content_word_count');
			
			if (durationSelect && wordCountSpan) {
				durationSelect.addEventListener('change', function() {
					const duration = parseInt(this.value);
					const wordCount = Math.round(duration * 2.5);
					wordCountSpan.textContent = wordCount;
				});
			}
		});
		</script>
            
            <!-- Database Tab -->
            <div id="database-tab" class="tab-content hidden">
                <div class="mode-title-box" style="background: linear-gradient(135deg, #0f2a44, #143b63); color:white;">
                    <h2>
                        <span>📚</span> From Database
                        <span class="mode-badge">Stored Content Library</span>
                    </h2>
                </div>
                
                <!-- Language Selector -->
                <!-- Language Selector -->
				<div class="form-group">
					<label>🌐 Target Language <span class="lang-badge" id="dbSelectedLangBadge">English</span></label>
					<select id="db_lang_select" onchange="updateDBLanguageBadge(); updatePrompt();">
						<?php foreach ($languages as $lang): ?>
							<option value="<?= $lang['language_code'] ?>" <?= $lang['language_code'] == 'en' ? 'selected' : '' ?>>
								<?= $lang['flag_emoji'] ?> <?= $lang['language'] ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
                
                <!-- Reel Type -->
                <div class="form-group">
                    <label>🎬 Reel Type11</label> 
                    <select id="db_reel_type" onchange="checkPodcastType('db'); updatePrompt()">
                        <option value="standard">Standard</option>
                        <option value="broll">B-Roll</option>
                        <option value="podcast">Podcast</option>
                    </select>
                </div>
				<!-- Video Duration -->
				<div class="form-group">
					<label>⏱️ Reel Duration</label>
					<select id="idea_duration">
						<option value="10">10 seconds</option>
						<option value="20">20 seconds</option>
						<option value="30" selected>30 seconds</option> 
						<option value="40">40 seconds</option>
						<option value="50">50 seconds</option>
						<option value="60">60 seconds</option>
						<option value="70">70 seconds</option>
						<option value="80">80 seconds</option>
						<option value="90">90 seconds</option>
					</select>
					<small style="display: block; color: #64748b; margin-top: 4px; font-size: 12px;">
						Approx. {{duration}} seconds = ~{{Math.round(duration*2.5)}} words
					</small>
				</div>
                
                <!-- Podcast Info -->
                <div id="db_podcast_info" class="podcast-voice-info" style="display: none;">
                    <span class="icon">🎙️</span>
                    <span>Podcast format: Script will be formatted with Host and Guest voices</span>
                </div>
                
                <!-- Category with Add Buttons -->
                <div class="form-group">
                    <div class="field-header">
                        <label>📁 Category & Topic</label>
                        <button type="button" class="icon-btn help-btn" onclick="showTooltipHelp('category')">?</button>
                        <button type="button" class="icon-btn add-btn" onclick="openAddModal('category')">+ Category</button>
                        <button type="button" class="icon-btn add-btn" onclick="openAddModal('topic')">+ Topic</button>
                        <button type="button" class="icon-btn add-btn" onclick="openAddModal('title')">+ Title</button>
                    </div>
                    <select id="cat_select" onchange="loadTopics(this.value)">
                        <option value="">-- Select Category --</option>
                        <?php
                        $cats = mysqli_query($conn, "SELECT DISTINCT category_key FROM hdb_social_media WHERE client_id = '$client_id' AND (sm_status = '' OR sm_status IS NULL)");
                        while ($c = mysqli_fetch_assoc($cats)) echo "<option value='".htmlspecialchars($c['category_key'])."'>".htmlspecialchars($c['category_key'])."</option>";
                        ?>
                    </select>
                    <select id="topic_select" onchange="loadTitles(this.value)" style="margin-top:10px;">
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
                
                <!-- Hook -->
                <div class="form-group">
                    <label>🎣 Hook Strategy</label>
                    <select id="hook_select" onchange="updatePrompt()">
                        <?php 
                        mysqli_data_seek($hooks_free, 0);
                        while ($hook_row = mysqli_fetch_assoc($hooks_free)) {
                            echo "<option value='{$hook_row['id']}'>{$hook_row['id']} - {$hook_row['hook_name']}</option>";
                        }
                        ?>
                    </select>
                    <div class="random-box">
                        <input type="checkbox" id="rnd_hook" checked onchange="updatePrompt()">  
                        <label for="rnd_hook">Select Hook randomly</label>
                    </div>
                </div>
                
                <!-- CTA -->
                <div class="form-group">
                    <div class="field-header">
                        <label>📢 CTA</label>
                        <button type="button" class="icon-btn help-btn" onclick="showTooltipHelp('cta')">?</button>
                        <button type="button" class="icon-btn add-btn" onclick="openAddModal('cta')">+ CTA</button>
                    </div>
                    <select id="cta_select" onchange="updatePrompt()">
                        <option value="">-- Select a CTA --</option>
                        <?php 
                        $ctas = mysqli_query($conn, "SELECT cta_en FROM hdb_social_media_cta WHERE client_id = '$client_id' ORDER BY cta_en");
                        if ($ctas && mysqli_num_rows($ctas) > 0) {
                            while ($ct = mysqli_fetch_assoc($ctas)) {
                                $cta_text = htmlspecialchars($ct['cta_en']);
                                echo "<option value='$cta_text'>$cta_text</option>";
                            }
                        }
                        ?>
                    </select>
                    <div class="random-box">
                        <input type="checkbox" id="rnd_cta" checked onchange="updatePrompt()">
                        <label for="rnd_cta">Select CTA randomly</label>
                    </div>
                </div>
                
                <!-- Editable Prompt -->
                <div class="form-group">
                    <label>✏️ Editable Prompt</label>
                    <div class="prompt-variables">
                        <span class="variable-badge">{category}</span>
                        <span class="variable-badge">{topic}</span>
                        <span class="variable-badge">{title}</span>
                        <span class="variable-badge">{hook}</span>
                        <span class="variable-badge">{cta}</span>
                        <span style="margin-left: auto;">Variables auto-replaced</span>
                    </div>
                    <textarea id="editable_prompt" class="prompt-editor" placeholder="Customize your prompt here..."></textarea>
                    <div class="prompt-actions">
                        <button class="btn btn-small" onclick="resetPrompt()">↺ Reset</button>
                        <button class="btn btn-small" onclick="previewPrompt()">👁️ Preview</button>
                    </div>
                </div>
                
                <!-- Generated Content -->
                <div class="form-group">
                    <label>📝 Generated Content</label>
                    <textarea id="generated_content" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:14px;"></textarea>
                </div>
                
                <!-- Buttons -->
                <div class="button-group">
                    <button class="btn btn-primary" id="generateContentBtn" onclick="generateContentOnly()">📝 Step 1: Generate Content</button>
                    <button class="btn btn-success" id="createScenesBtn" onclick="createScenesFromContent()" style="display: none;">🎬 Step 2: Create Scenes</button>
                </div>
                
                <!-- Prompt Preview -->
                <div class="form-group">
                    <label>🔍 Prompt Preview</label>
                    <textarea id="prompt_preview" rows="6" style="background: #f1f5f9;" readonly></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Progress Container -->
    <div class="progress-container" id="progress_container">
        <h4 style="margin:0 0 12px; color:var(--dark-blue);">📊 Real-Time Progress</h4>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progress_bar"></div>
        </div>
        <div class="progress-text" id="progress_text">Initializing...</div>
        <div class="realtime-log" id="realtime_log">
            <div class="log-entry">[System] Waiting to start...</div>
        </div>
    </div>
	<button class="debug-toggle" onclick="toggleDebugLog()">🐞 Show/Hide Debug Log</button>

	<script>
	function toggleDebugLog() {
		const log = document.getElementById('prompt_error_log');
		if (log) {
			if (log.style.display === 'none') {
				log.style.display = 'block';
			} else {
				log.style.display = 'none';
			}
		}
	}
	</script>
    
    <!-- Status Message -->
    <div id="status_msg" style="display:none;"></div>
</div>

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

<!-- Add Modal Overlay -->
<div class="add-modal-overlay" id="modalOverlay" onclick="closeAddModal()"></div>

<!-- Add Modal -->
<div class="add-modal" id="addModal">
    <h3 id="modalTitle">➕ Add New</h3>
    <input type="text" id="modalInput" placeholder="Enter value...">
    <div id="modalMsg" class="modal-msg"></div>
    <div class="modal-btns">
        <button class="btn-modal-cancel" onclick="closeAddModal()">Cancel</button>
        <button class="btn-modal-save" onclick="saveModalItem()">Save</button>
    </div>
</div>

<script>
// Language names and scripts
// Language mappings from database
const languageScripts = {
    <?php 
    foreach ($languages as $lang) {
        echo "'" . $lang['language_code'] . "': '" . $lang['language'] . "',\n";
    }
    ?>
};

const languageNames = {
    <?php 
    foreach ($languages as $lang) {
        echo "'" . $lang['language_code'] . "': '" . $lang['flag_emoji'] . " " . $lang['language'] . "',\n";
    }
    ?>
};
// Default prompt template
const defaultPrompt = `You are an expert content writer. Write a short reel content for social media with duration on 30 seconds

TITLE: {title}
CATEGORY: {category}
TOPIC: {topic}
HOOK: {hook}
LANGUAGE: {language}
REEL TYPE: {reel_type}
CTA: {cta}

PHILOSOPHY (use one of these my philosophy rules:
1. There is light at the end of every tunnel
2. You was born with everything you need to thrive
3. There are always multiple solutions exist for every problem
4. I am the author and hero of my story
5. Every adversity has one or more opportunities
6. Love is the best medicine (if not effective increase the dose)
7. Change your thoughts - change your life - you are what you think
8. Past is not for pain, it is like backview mirror to drive forward
9. Failures are stepping stones for success
10. There is nothing called failure, it is just experience
11. Luck is spelled "Hard work"
12. You must give in order to receive
13. Speak what you want - You get what you speak often
14. Imagination is power - imagine and you will have it
15. Repetition is the power
16. Try means 50-50 failure and success
17. Every issue can be resolved with discussion
18. Every big issue can be relieved by making small challenges
19. We are visitor to this place called earth - behave like a visitor
20. Failing to plan means planning to fail
21. Written goals/plans are more powerful than having them in mind
22. What is your next year plan, 5 year plan
23. What is your plan A, Plan B
24. You can't change the past, you can only change the present to create a better future
25. We live in a world of possibilities)

**CRITICAL REEL TYPE INSTRUCTION - READ CAREFULLY:**

IF REEL TYPE IS "Podcast":
- FORMAT MUST BE A CONVERSATION between HOST and GUEST
- EVERY line MUST start with either "Host:" or "Guest:"
- Alternate between Host asking questions and Guest giving answers
- DO NOT write continuous text
- DO NOT forget the "Host:" and "Guest:" prefixes

IF REEL TYPE IS "Standard" or "B-Roll":
- Write as normal continuous script
- Do NOT add Host: or Guest: prefixes
- Keep the standard format

IMPORTANT TRANSLATION INSTRUCTIONS:
1. The TITLE above is in English. You MUST translate it completely into {language}
2. The translated title should be natural and culturally appropriate for {language} speakers
3. EVERY word in the final output must be in {language} - no English words at all
4. If a concept doesn't translate directly, find the closest natural equivalent in {language}
5. Use cultural phrases, idioms, and expressions that native speakers would naturally use
6. Do NOT just translate word-for-word - adapt the message to sound natural in {language}
7. Make sure the content feels like it was originally written in {language}, not translated

REQUIREMENTS:
1. Write in a warm, conversational, calming tone in {language}
2. Structure:
   - Opening hook that includes the TRANSLATED title naturally
   - Identify the problem/pain points (using culturally relevant examples)
   - Assure listener they are powerful and have inner resources
   - Provide hypnotherapy solution
   - Strong closing with CTA (also translated naturally)

3. Divide the text into short scenes
4. **ABSOLUTE RULES:**
- **MAXIMUM 10 WORDS per scene** (count words in {language})
- **Split at EVERY 'and' - even mid-sentence** (use the {language} equivalent of 'and')
- **Split at EVERY 'or' - even mid-sentence** (use the {language} equivalent of 'or')
- **Split at EVERY comma (,)**
- **Split at EVERY <break> tag**
- **Each scene = ONE image = ONE visual moment**
- **Count words carefully - NEVER exceed 10 words**

5. **LANGUAGE ENFORCEMENT:**
   - If LANGUAGE is Urdu (ur): Write entirely in Urdu script, no Roman Urdu
   - If LANGUAGE is Arabic (ar): Write entirely in Arabic script
   - If LANGUAGE is Hindi (hi): Write entirely in Devanagari script
   - If LANGUAGE is Spanish (es): Write entirely in Spanish
   - If LANGUAGE is French (fr): Write entirely in French

OUTPUT ONLY the script text with azure pauses commands like <break time="250ms"/> or <break time="350ms"/> No explanations. do not insert scene numbers. Just the text`;

// Global variables
let hooksArr = <?php 
    $hooks_array = [];
    mysqli_data_seek($hooks_free, 0);
    while ($hook = mysqli_fetch_assoc($hooks_free)) {
        $hooks_array[] = ['id' => $hook['id'], 'name' => $hook['hook_name']];
    }
    echo json_encode($hooks_array); 
?>;
// Add this near the top of your JavaScript
const isFreeTrial = <?php echo $is_free_trial ? 'true' : 'false'; ?>;

// Then in processIdeaContent function, modify the duration retrieval:
const durationElement = document.getElementById('idea_duration');
let duration = '30';

if (isFreeTrial) {
    // Free trial users always get 30 seconds
    duration = '30';
} else {
    // Paid users can select
    duration = durationElement?.value || '30';
}

// For paid users, also add the dynamic word count hint
if (!isFreeTrial) {
    const durationSelect = document.getElementById('idea_duration');
    const wordCountHint = document.querySelector('small');
    
    if (durationSelect && wordCountHint) {
        durationSelect.addEventListener('change', function() {
            const dur = parseInt(this.value);
            const wordCount = Math.round(dur * 2.5);
            wordCountHint.innerHTML = `Approx. ${dur} seconds = ~${wordCount} words`;
        });
    }
}
// ========== HELPER FUNCTIONS ==========
// Tab switching
function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    if (tab === 'my-idea') {
        document.getElementById('my-idea-tab').classList.remove('hidden');
    } else if (tab === 'my-content') {
        document.getElementById('my-content-tab').classList.remove('hidden');
    } else if (tab === 'database') {
        document.getElementById('database-tab').classList.remove('hidden');
    }
}

// Check if podcast type selected and show info
function checkPodcastType(source) {
    let reelType, infoDiv;
    
    if (source === 'idea') {
        reelType = document.getElementById('idea_reel_type').value;
        infoDiv = document.getElementById('idea_podcast_info');
    } else if (source === 'content') {
        reelType = document.getElementById('content_reel_type').value;
        infoDiv = document.getElementById('content_podcast_info');
    } else if (source === 'db') {
        reelType = document.getElementById('db_reel_type').value;
        infoDiv = document.getElementById('db_podcast_info');
    }
    
    if (reelType === 'podcast') {
        infoDiv.style.display = 'flex';
    } else {
        infoDiv.style.display = 'none';
    }
}

// Toggle dropdown
function toggleDropdown() {
    document.getElementById('dropdownMenu').classList.toggle('open');
}

// Close dropdown on outside click
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('dropdownMenu');
    const profileBtn = document.getElementById('profileBtn');
    
    if (dropdown && profileBtn && !profileBtn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.classList.remove('open');
    }
});

// ========== DATABASE TAB FUNCTIONS ==========
function updateDBLanguageBadge() {
    const langSelect = document.getElementById('db_lang_select');
    const selectedLang = langSelect.value;
    document.getElementById('dbSelectedLangBadge').innerText = languageNames[selectedLang] || selectedLang;
}

function loadTopics(cat) {
    if (!cat) {
        document.getElementById('topic_select').innerHTML = '<option value="">-- Select Topic --</option>';
        document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
        return;
    }
    
    fetch('', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
        body: `get_topics=1&category=${encodeURIComponent(cat)}` 
    })
    .then(r => r.text())
    .then(d => { 
        document.getElementById('topic_select').innerHTML = d; 
        document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
        setTimeout(updatePrompt, 100); 
    })
    .catch(error => {
        console.error('Error loading topics:', error);
    });
}

function loadTitles(topic) {
    if (!topic) {
        document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
        return;
    }
    
    fetch('', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
        body: `get_titles=1&topic=${encodeURIComponent(topic)}` 
    })
    .then(r => r.text())
    .then(d => { 
        document.getElementById('sm_id_select').innerHTML = d; 
        setTimeout(updatePrompt, 100); 
    })
    .catch(error => {
        console.error('Error loading titles:', error);
    });
}

function updatePrompt() {
    const cat = document.getElementById('cat_select').value || "[category]";
    const topic = document.getElementById('topic_select').value || "[topic]";
    const ts = document.getElementById('sm_id_select');
    const originalTitle = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[original title]";
    
    const selectedLang = document.getElementById('db_lang_select').value;
    const langName = languageScripts[selectedLang] || selectedLang;
    
    const reelType = document.getElementById('db_reel_type').value;
    const reelTypeDisplay = reelType === 'standard' ? 'Standard' : reelType === 'broll' ? 'B-Roll' : 'Podcast';
    
    let hookId, hookName;
    if (document.getElementById('rnd_hook').checked) {
        const randomHook = hooksArr[Math.floor(Math.random() * hooksArr.length)];
        hookId = randomHook.id;
        hookName = randomHook.name;
    } else {
        const hookSelect = document.getElementById('hook_select');
        hookId = hookSelect.value;
        hookName = hookSelect.options[hookSelect.selectedIndex]?.text.split(' - ')[1] || '[hook]';
    }
    
    const cta = document.getElementById('cta_select').value || "[cta]";

    const editablePrompt = document.getElementById('editable_prompt');
    if (!editablePrompt.value || editablePrompt.value === defaultPrompt) {
        editablePrompt.value = defaultPrompt;
    }
    
    document.getElementById('prompt_preview').value = `Create a ${reelTypeDisplay} script for:
Category: ${cat}
Topic: ${topic}
Title: ${originalTitle}
Hook: ${hookName}
Language: ${langName}
Reel Type: ${reelTypeDisplay}
CTA: ${cta}

Write in warm, conversational tone with <break> tags for pauses.`;
}

function resetPrompt() {
    document.getElementById('editable_prompt').value = defaultPrompt;
    previewPrompt();
}

function previewPrompt() {  
    const cat = document.getElementById('cat_select').value || "[category]";
    const topic = document.getElementById('topic_select').value || "[topic]";
    const ts = document.getElementById('sm_id_select');
    const originalTitle = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[original title]";
    const selectedLang = document.getElementById('db_lang_select').value;
    const langName = languageScripts[selectedLang] || selectedLang;
    const reelType = document.getElementById('db_reel_type').value;
    const reelTypeDisplay = reelType === 'standard' ? 'Standard' : reelType === 'broll' ? 'B-Roll' : 'Podcast';
    
    let hookName;
    if (document.getElementById('rnd_hook').checked) {
        const randomHook = hooksArr[Math.floor(Math.random() * hooksArr.length)];
        hookName = randomHook.name;
    } else {
        const hookSelect = document.getElementById('hook_select');
        hookName = hookSelect.options[hookSelect.selectedIndex]?.text.split(' - ')[1] || '[hook]';
    }
    
    let cta;
    const rndCtaCheckbox = document.getElementById('rnd_cta');
    const ctaSelect = document.getElementById('cta_select');
    
    if (rndCtaCheckbox && rndCtaCheckbox.checked) {
        const options = Array.from(ctaSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomCta = options[Math.floor(Math.random() * options.length)];
            cta = randomCta.value;
        } else {
            cta = '[cta]';
        }
    } else {
        cta = ctaSelect.value || '[cta]';
    }
    
    let prompt = document.getElementById('editable_prompt').value;
    
    prompt = prompt.replace(/{category}/g, cat)
                   .replace(/{topic}/g, topic)
                   .replace(/{title}/g, originalTitle)
                   .replace(/{hook}/g, hookName)
                   .replace(/{cta}/g, cta)
                   .replace(/{language}/g, langName)
                   .replace(/{reel_type}/g, reelTypeDisplay);
    
    document.getElementById('prompt_preview').value = prompt;
    alert('Preview updated in the Prompt Preview field below');
}

// ========== DATABASE GENERATE FUNCTIONS ==========
async function generateContentOnly() {
    const btn = document.getElementById('generateContentBtn');
    const sm_select = document.getElementById('sm_id_select');
    const contentArea = document.getElementById('generated_content');
    const status = document.getElementById('status_msg');
    
    if(!sm_select.value) { 
        alert("Please select a title first"); 
        return; 
    }

    const hookSelect = document.getElementById('hook_select');
    const hookName = hookSelect.options[hookSelect.selectedIndex]?.text.split(' - ')[1] || 'hook';
    const originalTitle = sm_select.options[sm_select.selectedIndex].text;
    const category = document.getElementById('cat_select').value;
    const topic = document.getElementById('topic_select').value;
    const cta = document.getElementById('cta_select').value;
    
    const selectedLang = document.getElementById('db_lang_select').value;
    const langName = languageScripts[selectedLang] || selectedLang;
    const reelType = document.getElementById('db_reel_type').value;
    const reelTypeDisplay = reelType === 'standard' ? 'Standard' : reelType === 'broll' ? 'B-Roll' : 'Podcast';

    let prompt = document.getElementById('editable_prompt').value;
    
    if (!prompt.trim()) {
        prompt = defaultPrompt;
    }
    
    prompt = prompt.replace(/{category}/g, category)
                   .replace(/{topic}/g, topic)
                   .replace(/{title}/g, originalTitle)
                   .replace(/{hook}/g, hookName)
                   .replace(/{cta}/g, cta)
                   .replace(/{language}/g, langName)
                   .replace(/{reel_type}/g, reelTypeDisplay);

    btn.disabled = true;
    btn.innerHTML = 'Generating...';
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = 'Creating your script...';

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `ajax_action=generate_content_only&prompt=${encodeURIComponent(prompt)}`
        });
        
        const data = await response.json();
        
        if (data.success) {
            contentArea.value = data.content;
            document.getElementById('createScenesBtn').style.display = 'block';
            status.style.background = '#dcfce7';
            status.innerHTML = "✅ Content generated! You can edit it above, then click Step 2.";
        } else {
            status.style.background = '#fee2e2';
            status.innerHTML = '❌ Error: ' + data.message;
        }
    } catch (err) {
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred';
        console.error(err);
    } finally {
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Content";
    }
}



// ========== MY IDEA FUNCTIONS ==========
async function processIdeaContent() {
    const btn = document.getElementById('ideaProcessBtn');
    const contentArea = document.getElementById('idea_processed_content');
    const contentContainer = document.getElementById('idea_processed_container');
    const status = document.getElementById('status_msg');
    
    if (!btn || !contentArea || !status) {
        console.error('Required elements not found');
        alert('System error: Required elements missing');
        return;
    }
    
    const language = document.getElementById('idea_lang_select')?.value || 'en';
    const langName = languageScripts[language] || language;
    const reelType = document.getElementById('idea_reel_type')?.value || 'standard';
    const topic = document.getElementById('idea_topic')?.value?.trim() || '';
    const title = document.getElementById('idea_title')?.value?.trim() || '';
    
    // Get duration - check if free trial or paid
    let duration = '30';
    
    if (!isFreeTrial) {
        // Paid users can select duration
        duration = document.getElementById('idea_duration')?.value || '30';
    }
    
    // Calculate word count target (2.5 words per second)
    const targetWordCount = Math.round(parseInt(duration) * 2.5);
    const minWords = targetWordCount - 5;
    const maxWords = targetWordCount + 5;
    
    // Validate inputs
    if (!topic) {
        alert("Please enter a topic");
        return;
    }
    
    if (!title) {
        alert("Please enter a title for your video");
        return;
    }
    
    // Get audience safely
    const audienceSelect = document.getElementById('idea_audience');
    let audience = '';
    if (audienceSelect) {
        if (audienceSelect.value === 'custom') {
            const customField = document.getElementById('idea_audience_custom');
            audience = customField ? customField.value.trim() : '';
            if (!audience) {
                alert("Please enter custom audience");
                return;
            }
        } else {
            audience = audienceSelect.options[audienceSelect.selectedIndex]?.text || 'General Audience';
        }
    }
    
    // Get goal safely
    const goalSelect = document.getElementById('idea_goal');
    const goal = goalSelect ? goalSelect.options[goalSelect.selectedIndex]?.text || 'Educate' : 'Educate';
    
    // Hook selection safely
    const rndHookCheckbox = document.getElementById('idea_rnd_hook');
    const rndHook = rndHookCheckbox ? rndHookCheckbox.checked : true;
    
    const hookSelect = document.getElementById('idea_hook_select');
    let hookId, hookText;
    
    if (rndHook && hookSelect) {
        const options = Array.from(hookSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomHook = options[Math.floor(Math.random() * options.length)];
            hookId = randomHook.value;
            hookText = randomHook.text;
        } else {
            hookId = '1';
            hookText = 'Identity Hook';
        }
    } else if (hookSelect) {
        hookId = hookSelect.value || '1';
        hookText = hookSelect.options[hookSelect.selectedIndex]?.text || 'Identity Hook';
    } else {
        hookId = '1';
        hookText = 'Identity Hook';
    }
    
    const ctaInput = document.getElementById('idea_cta');
    const cta = ctaInput ? ctaInput.value.trim() || 'Follow for more daily tips' : 'Follow for more daily tips';
    
    // Disable button
    btn.disabled = true;
    btn.innerHTML = 'Processing...';
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = `Processing your idea for ${duration}s video...`;
    
    if (contentContainer) {
        contentContainer.style.display = 'block';
    }
    
    // Use buildPrompt function to create the prompt
    // Use buildPrompt function to create the prompt
	// Use buildPrompt function to create the prompt
	const processingPrompt = buildPrompt({
		duration: duration,
		title: title,
		langName: langName,
		reelType: reelType,
		cta: cta,
		hookText: hookText,
		audience: audience,
		goal: goal,
		topic: topic,
		originalContent: null
	}) + "\n\nIMPORTANT FORMATTING RULES:\n" +
	  "1. OUTPUT ONLY the script text - NO scene descriptions, NO brackets [ ], NO asterisks * or **\n" +
	  "2. NO labels like 'Narrator:' - just the text itself\n" +
	  "3. NO scene directions like '[Scene opens...]' or '[Scene fades...]'\n" +
	  "4. Start directly with the first line of spoken content\n" +
	  "5. Each line should be the spoken words only, followed by <break time=\"250ms\"/>\n" +
	  "6. NO markdown formatting of any kind\n\n" +
	  "EXAMPLE OF CORRECT OUTPUT:\n" +
	  "Make a bold prediction.<break time=\"250ms\"/>\n" +
	  "What if I told you that sleeping can significantly reduce your stress?<break time=\"250ms\"/>\n" +
	  "Follow for more stress relief tips!";

    // Create or get error log container
    let errorLogContainer = document.getElementById('prompt_error_log');
    if (!errorLogContainer) {
        errorLogContainer = document.createElement('div');
        errorLogContainer.id = 'prompt_error_log';
        errorLogContainer.style.cssText = 'margin-top: 20px; padding: 15px; background: #1e293b; color: #e2e8f0; border-radius: 8px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; border: 1px solid #334155; display: none;';
        
        const logHeader = document.createElement('div');
        logHeader.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #334155;';
        logHeader.innerHTML = '<span style="color: #fbbf24; font-weight: bold;">📋 Prompt Debug Log</span><button onclick="toggleErrorLog()" style="background: #334155; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer;">Toggle</button>';
        
        const logContent = document.createElement('div');
        logContent.id = 'prompt_log_content';
        logContent.style.whiteSpace = 'pre-wrap';
        logContent.style.wordBreak = 'break-word';
        
        errorLogContainer.appendChild(logHeader);
        errorLogContainer.appendChild(logContent);
        
        // Add toggle function
        window.toggleErrorLog = function() {
            const content = document.getElementById('prompt_log_content');
            if (content) {
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                } else {
                    content.style.display = 'none';
                }
            }
        };
        
        // Insert after progress container or at bottom of container
        const progressContainer = document.getElementById('progress_container');
        if (progressContainer && progressContainer.parentNode) {
            progressContainer.parentNode.insertBefore(errorLogContainer, progressContainer.nextSibling);
        } else {
            document.querySelector('.container')?.appendChild(errorLogContainer);
        }
    }
    
    // Update the log content
    const logContent = document.getElementById('prompt_log_content');
    if (logContent) {
        const timestamp = new Date().toLocaleTimeString();
        logContent.innerHTML = `
🕐 <strong>Time:</strong> ${timestamp}<br><br>
⏱️ <strong>Duration Target:</strong> ${duration} seconds (${minWords}-${maxWords} words)<br><br>
📝 <strong>Assembled Prompt:</strong><br><br>
<div style="background: #0f172a; padding: 15px; border-radius: 6px; border-left: 4px solid #fbbf24;">
${processingPrompt.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}
</div>
<br>
📊 <strong>Parameters:</strong><br>
• Duration: ${duration}s (Target words: ${targetWordCount})<br>
• Topic: ${topic}<br>
• Title: ${title}<br>
• Audience: ${audience}<br>
• Goal: ${goal}<br>
• Hook: ${hookText}<br>
• Language: ${langName}<br>
• Reel Type: ${reelType}<br>
• CTA: ${cta}<br>
`;
        errorLogContainer.style.display = 'block';
    }

    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', processingPrompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const data = await response.json();
        
        if (data.success) {
            let processedContent = data.content;
            
            // Count words to verify duration
            const wordCount = processedContent.replace(/<[^>]*>/g, '').split(/\s+/).length;
            const estimatedSeconds = Math.round(wordCount / 2.5);
            
            // Ensure pause tags have newlines
            if (!processedContent.includes('<break')) {
                processedContent = processedContent
                    .replace(/\./g, '.<break time="0.4s"/>\n')
                    .replace(/\?/g, '?<break time="0.4s"/>\n')
                    .replace(/!/g, '!<break time="0.4s"/>\n')
                    .replace(/,/g, ',<break time="0.2s"/>\n');
            } else {
                // Make sure existing pause tags have newlines
                processedContent = processedContent.replace(/<break[^>]*>/g, match => match + '\n');
            }
            
            contentArea.value = processedContent;
            
            const createBtn = document.getElementById('ideaCreateScenesBtn');
            if (createBtn) createBtn.style.display = 'block';
            
            status.style.background = '#dcfce7';
            status.innerHTML = `✅ Content generated! (~${wordCount} words, ~${estimatedSeconds}s). You can edit above, then click Step 2.`;
            
            // Add success to error log
            if (logContent) {
                logContent.innerHTML += `<br><span style="color: #4ade80;">✅ API call successful at ${new Date().toLocaleTimeString()} (Generated: ${wordCount} words, ~${estimatedSeconds}s)</span>`;
            }
        } else {
            throw new Error(data.message || 'Generation failed');
        }
    } catch (err) { 
        console.error('Error:', err);
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Error: ' + err.message;
        
        // Add error to error log
        if (logContent) {
            logContent.innerHTML += `<br><span style="color: #f87171;">❌ Error at ${new Date().toLocaleTimeString()}: ${err.message}</span>`;
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Content";
    }
}

// ========== MY CONTENT FUNCTIONS ==========
async function processContent() {
    const btn = document.getElementById('contentProcessBtn');
    const contentArea = document.getElementById('content_processed_content');
    const contentContainer = document.getElementById('content_processed_container');
    const status = document.getElementById('status_msg');
    
    if (!btn || !contentArea || !status) {
        console.error('Required elements not found');
        alert('System error: Required elements missing');
        return;
    }
    
    const language = document.getElementById('content_lang_select')?.value || 'en';
    const langName = languageScripts[language] || language;
    const reelType = document.getElementById('content_reel_type')?.value || 'multi-scene';
    const title = document.getElementById('content_title')?.value?.trim() || '';
    const story = document.getElementById('content_story')?.value?.trim() || '';
    
    // Get duration - check if free trial or paid
    let duration = '30';
    const isFreeTrial = typeof window.isFreeTrial !== 'undefined' ? window.isFreeTrial : false;
    
    if (!isFreeTrial) {
        // Paid users can select duration
        duration = document.getElementById('content_duration')?.value || '30';
    }
    
    // Calculate word count target (2.5 words per second)
    const targetWordCount = Math.round(parseInt(duration) * 2.5);
    const minWords = targetWordCount - 10;
    const maxWords = targetWordCount + 10;
    
    if (!title) {
        alert("Please enter a title");
        return;
    }
    
    if (!story) {
        alert("Please paste your content");
        return;
    }
    
    // Get CTA if it exists (will be shown after processing)
    const ctaInput = document.getElementById('content_cta');
    const cta = ctaInput ? ctaInput.value.trim() || 'Follow for more daily tips' : 'Follow for more daily tips';
    
    btn.disabled = true;
    btn.innerHTML = 'Processing...';
    contentArea.value = "Processing...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = `Processing your content for ${duration}s video...`;
    
    if (contentContainer) {
        contentContainer.style.display = 'block';
    }
    
    // Construct prompt with strict duration instructions
    const processingPrompt = `You are a content formatter. Format this script to be EXACTLY ${duration} SECONDS LONG when spoken at a normal pace.

**CRITICAL DURATION REQUIREMENTS:**
- The FINAL script MUST be exactly ${duration} seconds long - THIS IS MANDATORY
- Speaking rate: ~2.5 words per second
- Total word count MUST be between ${minWords} and ${maxWords} words
- CURRENT SCRIPT LENGTH: approximately ${Math.round(story.split(/\s+/).length)} words
- You MUST add or remove content to achieve the target duration
- If the script is too long, condense it
- If too short, expand it naturally

TITLE: ${title}
LANGUAGE: ${langName}
REEL TYPE: ${reelType === 'podcast' ? 'Podcast (preserve Host:/Guest: prefixes)' : 'Standard'}
TARGET DURATION: ${duration} seconds (${targetWordCount} words)

ORIGINAL CONTENT:
${story}

**FORMATTING INSTRUCTIONS:**
1. Add SSML pause tags at natural breaks: <break time="0.2s"/>, <break time="0.4s"/>, <break time="0.6s"/>
2. After EVERY pause tag, add a newline character (\n)
3. For podcast format, preserve Host: and Guest: prefixes exactly
4. Break into short, impactful sentences (max 10-12 words per line)

Include this CTA naturally at the end: "${cta}"

OUTPUT ONLY the formatted content with pause tags. DO NOT include any explanations or notes.`;

    // Create or get error log container (reuse from previous)
    let errorLogContainer = document.getElementById('prompt_error_log');
    if (!errorLogContainer) {
        // Create it if it doesn't exist (same code as in processIdeaContent)
        errorLogContainer = document.createElement('div');
        errorLogContainer.id = 'prompt_error_log';
        errorLogContainer.style.cssText = 'margin-top: 20px; padding: 15px; background: #1e293b; color: #e2e8f0; border-radius: 8px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; border: 1px solid #334155; display: none;';
        
        const logHeader = document.createElement('div');
        logHeader.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #334155;';
        logHeader.innerHTML = '<span style="color: #fbbf24; font-weight: bold;">📋 Prompt Debug Log</span><button onclick="toggleErrorLog()" style="background: #334155; color: white; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer;">Toggle</button>';
        
        const logContent = document.createElement('div');
        logContent.id = 'prompt_log_content';
        logContent.style.whiteSpace = 'pre-wrap';
        logContent.style.wordBreak = 'break-word';
        
        errorLogContainer.appendChild(logHeader);
        errorLogContainer.appendChild(logContent);
        
        window.toggleErrorLog = function() {
            const content = document.getElementById('prompt_log_content');
            if (content) {
                content.style.display = content.style.display === 'none' ? 'block' : 'none';
            }
        };
        
        const progressContainer = document.getElementById('progress_container');
        if (progressContainer && progressContainer.parentNode) {
            progressContainer.parentNode.insertBefore(errorLogContainer, progressContainer.nextSibling);
        } else {
            document.querySelector('.container')?.appendChild(errorLogContainer);
        }
    }
    
    // Update log content
    const logContent = document.getElementById('prompt_log_content');
    if (logContent) {
        const timestamp = new Date().toLocaleTimeString();
        const originalWordCount = story.split(/\s+/).length;
        logContent.innerHTML = `
🕐 <strong>Time:</strong> ${timestamp}<br><br>
⏱️ <strong>Duration Target:</strong> ${duration} seconds (${targetWordCount} words)<br>
📊 <strong>Original Script:</strong> ${originalWordCount} words<br>
🔄 <strong>Adjustment Needed:</strong> ${originalWordCount > maxWords ? '✂️ Need to shorten' : (originalWordCount < minWords ? '➕ Need to expand' : '✅ Good length')}<br><br>
📝 <strong>Assembled Prompt:</strong><br><br>
<div style="background: #0f172a; padding: 15px; border-radius: 6px; border-left: 4px solid #fbbf24;">
${processingPrompt.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')}
</div>
<br>
📊 <strong>Parameters:</strong><br>
• Duration: ${duration}s (Target words: ${targetWordCount})<br>
• Title: ${title}<br>
• Language: ${langName}<br>
• Reel Type: ${reelType}<br>
• CTA: ${cta}<br>
`;
        errorLogContainer.style.display = 'block';
    }

    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', processingPrompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const data = await response.json();
        
        if (data.success) {
            let processedContent = data.content;
            
            // Ensure pause tags are followed by newlines
            if (!processedContent.includes('<break')) {
                processedContent = processedContent
                    .replace(/\./g, '.<break time="0.4s"/>\n')
                    .replace(/\?/g, '?<break time="0.4s"/>\n')
                    .replace(/!/g, '!<break time="0.4s"/>\n')
                    .replace(/,/g, ',<break time="0.2s"/>\n');
            } else {
                processedContent = processedContent.replace(/<break[^>]*>/g, match => match + '\n');
            }
            
            // Count words to verify duration
            const wordCount = processedContent.replace(/<[^>]*>/g, '').split(/\s+/).length;
            const estimatedSeconds = Math.round(wordCount / 2.5);
            
            contentArea.value = processedContent;
            
            const createBtn = document.getElementById('contentCreateScenesBtn');
            if (createBtn) createBtn.style.display = 'block';
            
            status.style.background = '#dcfce7';
            status.innerHTML = `✅ Content processed! (~${wordCount} words, ~${estimatedSeconds}s). You can edit above, then click Step 2.`;
            
            if (logContent) {
                logContent.innerHTML += `<br><span style="color: #4ade80;">✅ API call successful at ${new Date().toLocaleTimeString()} (Generated: ${wordCount} words, ~${estimatedSeconds}s)</span>`;
            }
        } else {
            throw new Error(data.message || 'Processing failed');
        }
    } catch (err) {
        console.error('Error:', err);
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Error: ' + err.message;
        
        if (logContent) {
            logContent.innerHTML += `<br><span style="color: #f87171;">❌ Error at ${new Date().toLocaleTimeString()}: ${err.message}</span>`;
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Process Content";
    }
}
// ========== SCENE CREATION FUNCTIONS ==========
async function createIdeaScenes() {
    const contentArea = document.getElementById('idea_processed_content');
    const title = document.getElementById('idea_title').value.trim();
    const language = document.getElementById('idea_lang_select').value;
    const reelType = document.getElementById('idea_reel_type').value;
    
    // Get hook
    const rndHook = document.getElementById('idea_rnd_hook').checked;
    const hookSelect = document.getElementById('idea_hook_select');
    let hookId = '1';
    
    if (rndHook) {
        const options = Array.from(hookSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomHook = options[Math.floor(Math.random() * options.length)];
            hookId = randomHook.value;
        }
    } else {
        hookId = hookSelect.value || '1';
    }
    
    const cta = document.getElementById('idea_cta').value.trim() || 'Follow for more daily tips';
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }
    
    if (!title) {
        alert('Title is missing!');
        return;
    }
    
    const formData = new URLSearchParams();
    formData.append('action', 'create_free_scenes');
    formData.append('title', title);
    formData.append('combined_title', title);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('content', contentArea.value);
    formData.append('target_lang', language);
    formData.append('reel_type', reelType === 'podcast' ? 'podcast' : (reelType === 'broll' ? 'broll' : 'standard'));
    
    await createScenes(formData);
}

async function createContentScenes() {
    const contentArea = document.getElementById('content_processed_content');
    const title = document.getElementById('content_title').value.trim();
    const language = document.getElementById('content_lang_select').value;
    const reelTypeSelect = document.getElementById('content_reel_type');
    const reelType = reelTypeSelect.value;
    
    // Map reel type
    let mappedReelType = 'standard';
    if (reelType === 'podcast') {
        mappedReelType = 'podcast';
    } else if (reelType === 'single-scene') {
        mappedReelType = 'single-scene';
    } else {
        mappedReelType = 'standard';
    }
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }
    
    if (!title) {
        alert('Title is missing!');
        return;
    }
    
    const formData = new URLSearchParams();
    formData.append('action', 'create_free_scenes');
    formData.append('title', title);
    formData.append('combined_title', title);
    formData.append('hook_id', '1');
    formData.append('cta', '');
    formData.append('content', contentArea.value);
    formData.append('target_lang', language);
    formData.append('reel_type', mappedReelType);
    
    await createScenes(formData);
}

async function createScenesFromContent() {
    const sm_select = document.getElementById('sm_id_select');
    const contentArea = document.getElementById('generated_content');
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }
    
    const category = document.getElementById('cat_select').value;
    const topic = document.getElementById('topic_select').value;
    const sm_id = sm_select.value;
    const originalTitle = sm_select.options[sm_select.selectedIndex].text;
    const language = document.getElementById('db_lang_select').value;
    const reelType = document.getElementById('db_reel_type').value;
    
    // Get hook
    let hookId;
    if (document.getElementById('rnd_hook').checked) {
        const randomHook = hooksArr[Math.floor(Math.random() * hooksArr.length)];
        hookId = randomHook.id;
    } else {
        hookId = document.getElementById('hook_select').value;
    }
    
    // Get CTA
    let cta;
    if (document.getElementById('rnd_cta').checked) {
        const ctaSelect = document.getElementById('cta_select');
        const options = Array.from(ctaSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomCta = options[Math.floor(Math.random() * options.length)];
            cta = randomCta.value;
        } else {
            cta = '';
        }
    } else {
        cta = document.getElementById('cta_select').value;
    }
    
    const combined_title = `${hookId} - ${originalTitle}`;
    
    const formData = new URLSearchParams();
    formData.append('action', 'create_scenes_from_content');
    formData.append('sm_id', sm_id);
    formData.append('category', category);
    formData.append('topic', topic);
    formData.append('title', originalTitle);
    formData.append('combined_title', combined_title);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('content', contentArea.value);
    formData.append('target_lang', language);
    formData.append('reel_type', reelType);
    
    await createScenes(formData);
}

async function createScenes(formData) {
    const btn = document.getElementById('ideaCreateScenesBtn') || 
                document.getElementById('contentCreateScenesBtn') || 
                document.getElementById('createScenesBtn');
    
    const processBtn = document.getElementById('ideaProcessBtn') || 
                       document.getElementById('contentProcessBtn') || 
                       document.getElementById('generateContentBtn');
    
    const status = document.getElementById('status_msg');
    const progressContainer = document.getElementById('progress_container');
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    
    // Check if progress elements exist
    if (!progressContainer || !progressBar || !progressText || !realtimeLog) {
        console.error('Progress elements not found');
        alert('Error: Progress display elements missing');
        return;
    }
    
    // Disable buttons
    if (btn) btn.disabled = true;
    if (processBtn) processBtn.disabled = true;
    
    if (btn) btn.innerHTML = 'Creating Scenes...';
    
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = 'Creating scenes from your content...';
    
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.innerHTML = 'Starting...';
    realtimeLog.innerHTML = '<div class="log-entry">[System] Starting scene creation...</div>';
    
    progressContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            
            const chunk = decoder.decode(value);
            const lines = chunk.split('\n');
            
            lines.forEach(line => {
                if (line.startsWith('data: ')) {
                    try {
                        const data = JSON.parse(line.substring(6));
                        updateProgress(data);
                    } catch (e) {
                        console.log('Raw line:', line);
                    }
                }
            });
        }
    } catch (err) {
        console.error('Error:', err);
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred: ' + err.message;
        
        // Re-enable buttons on error
        if (btn) btn.disabled = false;
        if (processBtn) processBtn.disabled = false;
        if (btn) btn.innerHTML = "🎬 Step 2: Create Scenes";
    }
}

function updateProgress(data) {
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    
    if (!progressBar || !progressText || !realtimeLog) {
        console.error('Progress elements missing in updateProgress');
        return;
    }
    
    if (data.percent !== undefined) {
        progressBar.style.width = data.percent + '%';
    }
    
    if (data.message) {
        progressText.innerHTML = data.message;
    }
    
    if (data.log) {
        const logEntry = document.createElement('div');
        logEntry.className = 'log-entry ' + (data.log_type || 'info');
        logEntry.innerHTML = `[${new Date().toLocaleTimeString()}] ${data.log}`;
        realtimeLog.appendChild(logEntry);
        realtimeLog.scrollTop = realtimeLog.scrollHeight;
    }
    
    // Check if this is the final message
    if (data.final && data.success) {
        // Re-enable buttons
        const btns = document.querySelectorAll('.btn');
        btns.forEach(btn => btn.disabled = false);
        
        // Construct the URL
        const imageGenUrl = `image_gen.php?lang_filter=en&podcast_id=${data.podcast_id}`;

        // Create the Modal element
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="success-card">
                <h2>✅ Script Ready!</h2>
                <p style="color: #64748b; margin-bottom: 20px;">Your script for Podcast #${data.podcast_id} was generated successfully. What's the next step?</p>
                
                <button class="btn-primary" onclick="window.location.href='${imageGenUrl}'">
                    🖼️ Add Images / Create Video
                </button>
                
                <button class="btn-secondary" onclick="window.location.reload()">
                    🔄 Start Another Script
                </button>
            </div>
        `;

        document.body.appendChild(modal);
        
        // Hide the progress container
        setTimeout(() => {
            document.getElementById('progress_container').style.display = 'none';
        }, 500);
    }
}

// ========== MODAL FUNCTIONS ==========
const modalConfig = {
    category: { title: '➕ Add New Category', placeholder: 'e.g. Stress Relief, Anxiety, Sleep',    action: 'add_category' },
    topic:    { title: '➕ Add New Topic',    placeholder: 'e.g. Breathing Techniques, Work Stress', action: 'add_topic'    },
    title:    { title: '➕ Add New Title',    placeholder: 'e.g. 5 Ways to Beat Monday Stress',      action: 'add_title'    },
    cta:      { title: '➕ Add New CTA',      placeholder: 'e.g. Follow for daily calm tips',         action: 'add_cta'      }
};

let currentModalType = '';

function openAddModal(type) {
    currentModalType = type;
    const cfg = modalConfig[type];
    
    if (type === 'topic') {
        const cat = document.getElementById('cat_select').value;
        if (!cat) {
            alert('Please select a category first before adding a topic.');
            return;
        }
    }
    
    if (type === 'title') {
        const cat = document.getElementById('cat_select').value;
        const topic = document.getElementById('topic_select').value;
        
        if (!cat) {
            alert('Please select a category first before adding a title.');
            return;
        }
        if (!topic) {
            alert('Please select a topic first before adding a title.');
            return;
        }
    }
    
    document.getElementById('modalTitle').innerText = cfg.title;
    document.getElementById('modalInput').placeholder = cfg.placeholder;
    document.getElementById('modalInput').value = '';
    document.getElementById('modalMsg').innerHTML = ''; 
    document.getElementById('modalOverlay').style.display = 'block';
    document.getElementById('addModal').style.display = 'block';
    setTimeout(() => document.getElementById('modalInput').focus(), 100);
}

function closeAddModal() {
    document.getElementById('modalOverlay').style.display = 'none';
    document.getElementById('addModal').style.display = 'none';
}

async function saveModalItem() {
    const value = document.getElementById('modalInput').value.trim();
    const msgEl = document.getElementById('modalMsg');
    
    if (!value) { 
        msgEl.style.color = '#dc2626'; 
        msgEl.innerText = 'Please enter a value.'; 
        return; 
    }

    const cfg = modalConfig[currentModalType];
    const formData = new URLSearchParams();
    formData.append('ajax_action', cfg.action);

    if (currentModalType === 'category') {
        formData.append('category', value);
    } else if (currentModalType === 'topic') {
        const cat = document.getElementById('cat_select').value;
        if (!cat) { 
            msgEl.style.color='#dc2626'; 
            msgEl.innerText='Select a category first.'; 
            return; 
        }
        formData.append('category', cat);
        formData.append('topic', value);
    } else if (currentModalType === 'title') {
        const cat = document.getElementById('cat_select').value;
        const topic = document.getElementById('topic_select').value;
        if (!cat || !topic) { 
            msgEl.style.color='#dc2626'; 
            msgEl.innerText='Select a category and topic first.'; 
            return; 
        }
        formData.append('category', cat);
        formData.append('topic', topic);
        formData.append('title', value);
    } else if (currentModalType === 'cta') {
        formData.append('cta', value);
    }

    try {
        const res = await fetch('', { 
            method:'POST', 
            headers:{'Content-Type':'application/x-www-form-urlencoded'}, 
            body: formData 
        });
        const data = await res.json();
        
        if (data.success) {
            msgEl.style.color = '#059669';
            msgEl.innerText = '✅ ' + data.message;
            
            if (currentModalType === 'category') {
                const sel = document.getElementById('cat_select');
                sel.appendChild(new Option(value, value));
                sel.value = value;
                document.getElementById('topic_select').innerHTML = '<option value="">-- Select Topic --</option>';
                document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
            } else if (currentModalType === 'topic') {
                loadTopics(document.getElementById('cat_select').value);
                document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
            } else if (currentModalType === 'title') {
                loadTitles(document.getElementById('topic_select').value);
            } else if (currentModalType === 'cta') {
                const sel = document.getElementById('cta_select');
                sel.appendChild(new Option(value, value));
                sel.value = value;
            }
            
            setTimeout(closeAddModal, 1200);
        } else {
            msgEl.style.color = '#dc2626';
            msgEl.innerText = '❌ ' + data.message;
        }
    } catch(err) {
        msgEl.style.color = '#dc2626';
        msgEl.innerText = '❌ Network error';
        console.error(err);
    }
}

function openFreeHookModal() {
    const hookName = prompt("Enter new hook strategy (e.g., 'Ever wondered why...'):");
    if (!hookName) return;
    
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'add_hook');
    formData.append('hook_name', hookName);
    
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('idea_hook_select');
            const option = document.createElement('option');
            option.value = data.id;
            option.text = hookName;
            select.appendChild(option);
            select.value = data.id;
            alert('Hook added successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        alert('Network error occurred');
        console.error(err);
    });
}

// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    // Initialize elements
    const editablePrompt = document.getElementById('editable_prompt');
    if (editablePrompt) {
        editablePrompt.value = defaultPrompt;
    }
    
    updateDBLanguageBadge();
    updatePrompt();
    
    // Audience change handler
    const audienceSelect = document.getElementById('idea_audience');
    if (audienceSelect) {
        audienceSelect.addEventListener('change', function() {
            const customField = document.getElementById('idea_audience_custom');
            if (customField) {
                customField.style.display = (this.value === 'custom') ? 'block' : 'none';
            }
        });
    }
    
    // Category change handler
    const catSelect = document.getElementById('cat_select');
    if (catSelect) {
        catSelect.addEventListener('change', function() {
            loadTopics(this.value);
        });
    }
    
    // Topic change handler
    const topicSelect = document.getElementById('topic_select');
    if (topicSelect) {
        topicSelect.addEventListener('change', function() {
            loadTitles(this.value);
        });
    }
});
// Unified prompt builder function
// Unified prompt builder function
function buildPrompt(params) {
    const {
        duration,
        title,
        langName,
        reelType,
        cta,
        hookText = null,
        audience = null,
        goal = null,
        originalContent = null,
        topic = null,
        category = null
    } = params;
    
    const targetWordCount = Math.round(parseInt(duration) * 2.5);
    const minWords = targetWordCount - 5;
    const maxWords = targetWordCount + 5;
    
    let prompt = `Create a ${duration}-second video script with EXACT formatting rules.`;

    // Add hook-based opening if provided
    if (hookText) {
        prompt += `\nOpen with this style: "${hookText}"`;
    }
    
    // Add context for the content
    if (originalContent) {
        const originalWordCount = originalContent.split(/\s+/).length;
        prompt += `\n\nBased on this content:\n${originalContent}\n`;
        
        if (originalWordCount > maxWords) {
            prompt += `\nCondense to ${targetWordCount} words.`;
        } else if (originalWordCount < minWords) {
            prompt += `\nExpand to ${targetWordCount} words naturally.`;
        }
    } else {
        // For new scripts, provide the creative direction
        let context = [];
        if (topic) context.push(topic);
        if (title) context.push(`"${title}"`);
        if (audience) context.push(`for ${audience}`);
        if (goal) context.push(`with goal: ${goal}`);
        
        if (context.length > 0) {
            prompt += `\n\nTopic: ${context.join(' ')}`;
        }
    }
    
    // Add category for database tab
    if (category) {
        prompt += `\nCategory: ${category}`;
    }
    
    // Add podcast format instruction if needed
    if (reelType === 'podcast') {
        prompt += `\n\nFormat as conversation with Host: and Guest: prefixes.`;
    }
    
    // Add pause and formatting instructions
    prompt += `\n\nAdd SSML pauses <break time="250ms"/> after each line.`;
    prompt += `\nAfter each pause, add a new line.`;
    prompt += `\nEnd with: "${cta}"`;
    
    return prompt;
}
// Keyboard events for modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && document.getElementById('addModal').style.display === 'block') {
        e.preventDefault();
        saveModalItem();
    }
    if (e.key === 'Escape') closeAddModal();
});
</script>
</body>
</html>