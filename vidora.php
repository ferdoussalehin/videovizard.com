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
//$client_id   = '1';

require_once 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';

// Set MySQL session variables to prevent timeout
mysqli_query($conn, "SET SESSION wait_timeout = 28800");
mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
mysqli_query($conn, "SET SESSION net_read_timeout = 300");
mysqli_query($conn, "SET SESSION net_write_timeout = 300");


// Assuming $conn is your mysqli connection and $client_id is defined

// Fetch hooks
$hooks_free = mysqli_query($conn, "SELECT id, hook_name FROM hdb_social_media_hooks ORDER BY id");

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
	
	//echo "query",$sql;die;
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

    // Check connection before executing
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

    // ===== JSON PROMPT FOR SCENE GENERATION =====
    $scenes_prompt = "You are a JSON generator. Your ONLY task is to output valid JSON.

BREAK THIS SCRIPT INTO SEPARATE SCENES - ONE SCENE PER LINE:

SCRIPT CONTENT:
$content

**CRITICAL INSTRUCTION:**
- Each LINE in the script becomes ONE SEPARATE SCENE
- Look at the script above - every line ending with <break> tag is a separate scene
- DO NOT combine multiple lines into one scene
- PRESERVE the <break> tags exactly as they appear

For each scene, generate a JSON object with these fields:
1. text_contents: THE EXACT LINE FROM THE SCRIPT preserving <break> tags
2. text_display: A powerful caption (3-7 words) different from audio
3. duration: number between 3-7 seconds
4. prompt: DETAILED visual description for AI image generation (30-50 words) - Include:
   - Main subject/scene description
   - Background setting
   - Color palette (warm/cool/soft/vibrant)
   - Lighting (soft light/dramatic/golden hour)
   - Facial expressions/emotions
   - Mood/atmosphere
   - Style (photorealistic/artistic/minimalist)
5. hashtags: space-separated hashtags like '#calm #peace #stressrelief'

**OUTPUT FORMAT:**
Return a JSON array of scene objects:
[
  {
    \"text_contents\": \"Line 1 with <break> tags\",
    \"text_display\": \"Powerful caption 1\",
    \"duration\": 5,
    \"prompt\": \"Detailed image prompt 1...\",
    \"hashtags\": \"#calm #peace\"
  },
  {
    \"text_contents\": \"Line 2 with <break> tags\",
    \"text_display\": \"Powerful caption 2\",
    \"duration\": 4,
    \"prompt\": \"Detailed image prompt 2...\",
    \"hashtags\": \"#stressrelief #calm\"
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
   // $lang_code = 'en';
	$lang_code = $target_lang;
    $actor = 'host';
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
        
        // Set values from scene with proper escaping
        $text_contents = $scene['text_contents'];
        // Check if this line starts with "Guest:" or "Host:"
        if (preg_match('/^Guest:/i', trim($text_contents))) {
            $actor = 'guest';
            // Remove the "Guest:" prefix from the text
            $text_contents = trim(preg_replace('/^Guest:/i', '', $text_contents));
        } elseif (preg_match('/^Host:/i', trim($text_contents))) {
            $actor = 'host';
            // Remove the "Host:" prefix from the text
            $text_contents = trim(preg_replace('/^Host:/i', '', $text_contents));
        }
        
        $text_display = $scene['text_display'] ?? $scene['text_contents'];
        $duration = (int)($scene['duration'] ?? 5);
        $prompt = $scene['prompt'] ?? '';
        $hashtags = $scene['hashtags'] ?? '';
        $seq_no = $index + 1;
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
            $percent = 40 + floor(($success_count / $total_statements) * 50);
            sendProgress($percent, "Inserting scenes...", "✅ Inserted scene " . $success_count, 'success');
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

/// Handler for creating free scenes
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
                'duration' => 15, // Longer duration for single scene
                'prompt' => 'A scene based on: ' . substr($content, 0, 100),
                'hashtags' => '#singlescene #content'
            ]
        ];
        sendProgressFree(40, "Single scene created", "✅ Created 1 scene for single-scene mode", 'success');
    } else {
        // MULTI-SCENE: Use ChatGPT to generate multiple scenes
        sendProgressFree(35, "Generating multiple scenes...", "🎬 Multi-scene mode: breaking into separate scenes", 'info');
        
        $scenes_prompt = "You are a JSON generator. Your ONLY task is to output valid JSON.

BREAK THIS SCRIPT INTO SEPARATE SCENES - ONE SCENE PER LINE:

SCRIPT CONTENT:
$content

**CRITICAL INSTRUCTION:**
- Each LINE in the script becomes ONE SEPARATE SCENE
- Look at the script above - every line ending with <break> tag is a separate scene
- DO NOT combine multiple lines into one scene
- PRESERVE the <break> tags exactly as they appear

For each scene, generate a JSON object with these fields:
1. text_contents: THE EXACT LINE FROM THE SCRIPT preserving <break> tags
2. text_display: A powerful caption (3-7 words) different from audio
3. duration: number between 3-7 seconds
4. prompt: DETAILED visual description for AI image generation (30-50 words)
5. hashtags: space-separated hashtags

**OUTPUT FORMAT:**
Return a JSON array of scene objects:
[
  {
    \"text_contents\": \"Line 1 with <break> tags\",
    \"text_display\": \"Powerful caption 1\",
    \"duration\": 5,
    \"prompt\": \"Detailed image prompt 1...\",
    \"hashtags\": \"#calm #peace\"
  }
]

CRITICAL RULES:
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
    $actor = 'host';
    $visual_type = 'image';
    $status = 'PENDING';
    $logo_flag = 0;

    foreach ($scenes as $index => $scene) {
        $text_contents = $scene['text_contents'] ?? '';
        $text_display = $scene['text_display'] ?? $text_contents;
        $duration = (int)($scene['duration'] ?? 5);
        $prompt = $scene['prompt'] ?? '';
        $hashtags = $scene['hashtags'] ?? '';
        $seq_no = $index + 1;
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
            $percent = 45 + floor(($success_count / $total_statements) * 45);
            sendProgressFree($percent, "Inserting scenes...", "✅ Inserted scene " . $success_count . "/" . $total_statements, 'success');
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
    <title>ContentPilot AI - 2-Step Workflow</title>
    <style>
        :root { --dark-blue: #1e3a8a; --purple: #7c3aed; --orange: #f59e0b; --yellow: #fef3c7; --bg: #f8fafc; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); font-size: 0.85rem; margin:0; padding:0; }
        .container { max-width: 1200px; margin: 20px auto; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 700; color: var(--dark-blue); }
        select, textarea, input[type="text"] { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.9rem; }
        .btn { background: var(--orange); color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 700; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: #0f2a44; }
        .btn-success { background: #059669; }
        .random-box { background: var(--yellow); padding: 8px; border-radius: 5px; margin-top: 5px; display: flex; align-items: center; gap: 8px; font-weight: 600; }
   
        .vidora-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 24px;
            background: linear-gradient(90deg, #0f2a44, #143b63);
            color: #fff;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            font-family: "Segoe UI", sans-serif;
        }

        .brand {
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .brand span { color: #5fd1ff; }
        .brand small { font-size: 12px; color: #cde9ff; font-weight: 400; }

        .vidora-nav { display: flex; gap: 18px; }
        .vidora-nav a {
            text-decoration: none;
            color: #fff;
            font-size: 15px;
            padding: 7px 14px;
            border-radius: 6px;
            transition: all 0.25s ease;
        }
        .vidora-nav a:hover { background: rgba(255,255,255,0.15); }
        .vidora-nav a.active {
            background: #5fd1ff;
            color: #0f2a44;
            font-weight: 600;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .content-preview {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }

        /* Progress styles */
        .progress-container {
            display: none;
            margin-top: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .progress-bar-bg {
            background: #e2e8f0;
            border-radius: 4px;
            height: 20px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .progress-bar-fill {
            background: linear-gradient(90deg, #0f2a44, #5fd1ff);
            height: 100%;
            width: 0%;
            transition: width 0.3s;
        }
        .progress-text {
            font-weight: 600;
            margin-bottom: 10px;
            color: #0f2a44;
        }
        .realtime-log {
            background: #0f172a;
            color: #e2e8f0;
            padding: 10px;
            border-radius: 6px;
            max-height: 200px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
        }
        .log-entry { padding: 2px 0; border-bottom: 1px solid #334155; }
        .log-entry.success { color: #4ade80; }
        .log-entry.error { color: #f87171; }
        .log-entry.info { color: #60a5fa; }
        .log-entry.warning { color: #fbbf24; }
        
        /* Editable prompt styles */
        .prompt-editor {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.5;
            background: #fff;
            width: 100%;
            min-height: 180px;
            resize: vertical;
        }
        .prompt-actions {
            display: flex;
            gap: 10px;
            margin: 10px 0;
        }
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            background: #f1f5f9;
            color: #0f2a44;
            border: 1px solid #cbd5e1;
        }
        .btn-small:hover {
            background: #e2e8f0;
        }
        .prompt-variables {
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #64748b;
            border: 1px solid #e2e8f0;
            margin-bottom: 10px;
        }
        .variable-badge {
            display: inline-block;
            background: #e2e8f0;
            padding: 4px 8px;
            border-radius: 4px;
            margin-right: 5px;
            font-family: monospace;
            font-size: 11px;
        }
        
        .lang-badge {
            background: #10b981;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        /* Tab styles */
        .tab-bar {
            display: flex;
            gap: 5px;
            margin-bottom: 25px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .tab {
            padding: 10px 25px;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
            color: #64748b;
            transition: all 0.2s;
        }
        .tab:hover {
            background: #f1f5f9;
            color: #0f2a44;
        }
        .tab.active {
            background: #0f2a44;
            color: white;
        }
        .tab-content {
            display: block;
        }
        .tab-content.hidden {
            display: none;
        }
        
        .info-box {
            background: #e0f2fe;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #0369a1;
            margin-bottom: 15px;
        }
		
		<style>
/* Icon buttons next to dropdowns */
.field-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
}
.field-header label { margin-bottom: 0; }

.icon-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 3px 6px;
    border-radius: 5px;
    font-size: 14px;
    line-height: 1;
    transition: background 0.15s;
}
.icon-btn:hover { background: #e2e8f0; }
.icon-btn.help-btn { color: #0369a1; font-weight: 700; background: #e0f2fe; }
.icon-btn.help-btn:hover { background: #bae6fd; }
.icon-btn.add-btn { color: #059669; font-weight: 700; background: #d1fae5; }
.icon-btn.add-btn:hover { background: #a7f3d0; }

/* Tooltip */
.tooltip-wrap { position: relative; display: inline-block; }
.tooltip-box {
    display: none;
    position: absolute;
    left: 0;
    top: 28px;
    background: #0f2a44;
    color: #fff;
    font-size: 11px;
    padding: 8px 12px;
    border-radius: 8px;
    width: 240px;
    z-index: 999;
    line-height: 1.5;
    font-weight: 400;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.tooltip-box::before {
    content: '';
    position: absolute;
    top: -6px;
    left: 10px;
    border: 6px solid transparent;
    border-top: none;
    border-bottom-color: #0f2a44;
}
.tooltip-wrap:hover .tooltip-box { display: block; }

/* Add modal */
.add-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9998;
}
.add-modal {
    display: none;
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 14px;
    padding: 28px;
    width: 420px;
    z-index: 9999;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.add-modal h3 { margin: 0 0 18px; color: #0f2a44; font-size: 16px; }
.add-modal input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 14px;
}
.add-modal input:focus { outline: none; border-color: #5fd1ff; }
.modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
.btn-modal-cancel { background: #e2e8f0; color: #475569; border: none; padding: 9px 20px; border-radius: 7px; cursor: pointer; font-weight: 600; }
.btn-modal-save { background: #059669; color: #fff; border: none; padding: 9px 20px; border-radius: 7px; cursor: pointer; font-weight: 600; }
.btn-modal-save:hover { background: #047857; }
.modal-msg { font-size: 12px; margin-bottom: 10px; min-height: 18px; }
.modal-msg.success { color: #059669; }
.modal-msg.error { color: #dc2626; }
/* Add Modal Styles */
.add-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9998;
}
.add-modal {
    display: none;
    position: fixed;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 14px;
    padding: 28px;
    width: 420px;
    z-index: 9999;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.add-modal h3 { margin: 0 0 18px; color: #0f2a44; font-size: 16px; }
.add-modal input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 14px;
}
.add-modal input:focus { outline: none; border-color: #5fd1ff; }
.modal-btns { display: flex; gap: 10px; justify-content: flex-end; }
.btn-modal-cancel { background: #e2e8f0; color: #475569; border: none; padding: 9px 20px; border-radius: 7px; cursor: pointer; font-weight: 600; }
.btn-modal-save { background: #059669; color: #fff; border: none; padding: 9px 20px; border-radius: 7px; cursor: pointer; font-weight: 600; }
.btn-modal-save:hover { background: #047857; }
.modal-msg { font-size: 12px; margin-bottom: 10px; min-height: 18px; }
.modal-msg.success { color: #059669; }
.modal-msg.error { color: #dc2626; }
</style>
    </style>
</head>
<body>

<div class="container">
    <header class="vidora-header">
        <div class="brand">
            🎬 <span>Vidora</span>
            <small>Social Media Automation</small>
        </div>
        <nav class="vidora-nav">
            <a href="vidora.php" class="active">1. Contents</a>
            <a href="image_gen.php">2. Images</a>
			<a href="audio_gen.php">3. Audios</a>
            <a href="videomaker.php">4. Video</a>
            <a href="podcast_translator.php">5. Translate</a>
            <a href="publisher/dashboard.php">6. Schedule</a>
        </nav>
    </header>
    
    <!-- Tab Bar -->
    <div class="tab-bar">
        
        <div class="tab active" onclick="switchTab('free')" id="tab-free">✏️ Free Format</div>
		<div class="tab " onclick="switchTab('database')" id="tab-database">📚 Database Content</div>
    </div>
    
    <!-- Database Content Tab -->
    
	
	<div id="database-tab" class="tab-content">
    <div class="card">
        <h2 style="color:var(--dark-blue); margin-top:0;">🎙️ Database Content Creator</h2>
        <p style="color: #64748b; margin-bottom: 20px;">Step 1: Generate content (edit prompt below) → Review/Edit → Step 2: Create scenes</p>
        
        <div class="form-group">
            <label>🌐 Target Language <span class="lang-badge" id="dbSelectedLangBadge">English</span></label>
            <select id="db_lang_select" onchange="updateDBLanguageBadge(); updatePrompt();">
                <option value="en" selected>English</option>
                <option value="ur">اردو - Urdu</option>
                <option value="ar">العربية - Arabic</option>
                <option value="hi">हिन्दी - Hindi</option>
                <option value="es">Español - Spanish</option>
                <option value="fr">Français - French</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>🎬 Reel Type</label>
            <select id="db_reel_type" onchange="updatePrompt()">
                <option value="standard">Standard</option>
                <option value="broll">B-Roll</option>
                <option value="podcast">Podcast</option>
            </select>
        </div>
        
        <!-- Category & Topic with ? and + icons -->
        <div class="form-group">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <label style="margin-bottom:0; font-weight:700; color:var(--dark-blue);">Category & Topic</label>
                <!-- Help tooltip -->
                <div style="position:relative; display:inline-block;">
                    <button type="button" class="icon-btn help-btn" title="What is this?">?</button>
                    <div class="tooltip-box">
                        <strong>Categories & Topics</strong> organise your content library.<br><br>
                        📁 <strong>Category</strong> = broad subject (e.g. "Stress Relief")<br>
                        📌 <strong>Topic</strong> = specific angle within category<br>
                        📝 <strong>Title</strong> = the exact video title<br><br>
                        Click <strong>+</strong> buttons to add new ones.
                    </div>
                </div>
                <button type="button" class="icon-btn add-btn" onclick="openAddModal('category')" title="Add new category">+ Cat</button>
                <button type="button" class="icon-btn add-btn" onclick="openAddModal('topic')" title="Add new topic">+ Topic</button>
                <button type="button" class="icon-btn add-btn" onclick="openAddModal('title')" title="Add new title">+ Title</button>
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
            <label>Title</label>
            <select id="sm_id_select" onchange="updatePrompt()">
                <option value="">-- Select Title --</option>
            </select>
        </div>

        <!-- Hook -->
        <div class="form-group">
            <label>Hook Strategy (ID - Name)</label>
            <select id="hook_select" onchange="updatePrompt()">
                <?php 
                $hooks_query = mysqli_query($conn, "SELECT id, hook_name FROM hdb_social_media_hooks ORDER BY id");
                $hooks_array = [];
                while ($hook_row = mysqli_fetch_assoc($hooks_query)) {
                    $hooks_array[] = ['id' => $hook_row['id'], 'name' => $hook_row['hook_name']];
                    echo "<option value='{$hook_row['id']}'>{$hook_row['id']} - {$hook_row['hook_name']}</option>";
                }
                ?>
            </select>
            <div class="random-box">
                <input type="checkbox" id="rnd_hook" checked onchange="updatePrompt()">  
                Select Hook randomly
            </div>
        </div>

        <!-- CTA with ? and + icons -->
		<div class="form-group">
			<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
				<label style="margin-bottom:0; font-weight:700; color:var(--dark-blue);">CTA</label>
				<!-- Help tooltip -->
				<div class="tooltip-wrap" style="position:relative; display:inline-block;">
					<button type="button" class="icon-btn help-btn" title="What is a CTA?">?</button>
					<div class="tooltip-box">
						<strong>📢 Call To Action (CTA)</strong><br>
						The closing line telling viewers what to do next.<br><br>
						<strong>Examples:</strong><br>
						• "Follow for daily calm tips"<br>
						• "Save this for later"<br>
						• "Share with someone who needs this"<br>
						• "Comment your thoughts below"<br>
						• "Subscribe for more"<br><br>
						<em>Click <strong>+ CTA</strong> to add your own custom CTAs.</em>
					</div>
				</div>
				<button type="button" class="icon-btn add-btn" onclick="openAddModal('cta')" title="Add new CTA">+ CTA</button>
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
				} else {
					// Default options if no CTAs exist
					echo "<option value='Follow for more daily tips'>Follow for more daily tips</option>";
					echo "<option value='Save this for later'>Save this for later</option>";
					echo "<option value='Share with someone who needs this'>Share with someone who needs this</option>";
				}
				?>
			</select>
			<!-- Add random CTA option - CHECKED BY DEFAULT -->
			<div class="random-box" style="margin-top:8px; display:flex; align-items:center; gap:8px;">
				<input type="checkbox" id="rnd_cta" checked onchange="updatePrompt()">
				<label for="rnd_cta" style="display:inline; font-weight:normal; margin:0;">Select CTA randomly</label>
			</div>
		</div>

        <div class="form-group">
            <label>✏️ Editable Prompt (Customize before generating)</label>
            <div class="prompt-variables">
                <span class="variable-badge">{category}</span>
                <span class="variable-badge">{topic}</span>
                <span class="variable-badge">{title}</span>
                <span class="variable-badge">{hook}</span>
                <span class="variable-badge">{cta}</span>
                <span style="margin-left: 10px;">These variables will be replaced automatically</span>
            </div>
            <textarea id="editable_prompt" class="prompt-editor" placeholder="Customize your prompt here..."></textarea>
            <div class="prompt-actions">
                <button class="btn btn-small" onclick="resetPrompt()">↺ Reset to Default</button>
                <button class="btn btn-small" onclick="previewPrompt()">👁️ Preview with Current Values</button>
            </div>
        </div>

        <div class="form-group">
            <label>📝 Generated Content (Edit if needed)</label>
            <textarea id="generated_content" rows="10" style="width:100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-family: monospace; font-size: 14px;"></textarea>
            <div class="button-group">
                <button class="btn btn-primary" id="generateContentBtn" onclick="generateContentOnly()" style="flex:1;">📝 Step 1: Generate Content</button>
                <button class="btn btn-success" id="createScenesBtn" onclick="createScenesFromContent()" style="flex:1; display: none;">🎬 Step 2: Create Scenes from Content</button>
            </div>
        </div>

        <div class="form-group">
            <label>Prompt Preview (for reference)</label>
            <textarea id="prompt_preview" rows="6" style="background: #f1f5f9;" readonly></textarea>
        </div>
    </div>
</div>
	
	
    <!-- Free Format Tab -->
<div id="free-tab" class="tab-content hidden">
  <div class="card">
    <h2 style="color:var(--dark-blue); margin-top:0;">✏️ Free Format Creator</h2>
    <p style="color: #64748b; margin-bottom: 20px;">Create custom content with AI or your own story</p>

    <!-- Mode Toggle Buttons -->
    <div class="form-group" style="margin-bottom:20px;">
      <label>🎨 Create video from:</label>
      <div style="display:flex; gap:10px; margin-top:6px;">
        <button type="button" id="btn_ai_mode" class="mode-btn active" onclick="setFreeMode('ai')">✨ AI Idea</button>
        <button type="button" id="btn_user_mode" class="mode-btn" onclick="setFreeMode('user')">📝 My Content</button>
      </div>
      <small style="color:#64748b; display:block; margin-top:4px;">
        AI Mode: We'll create content from your topic.<br>
        My Content: Paste your own script and we’ll adapt it for the reel.
      </small>
    </div>

    <!-- Title (Always visible) -->
    <div class="form-group">
      <label>📌 Title</label>
      <input type="text" id="free_title" placeholder="Enter a title for your content..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
      <div style="margin-top:6px;">
        <input type="checkbox" id="free_auto_title" checked>
        <label for="free_auto_title"> Auto-generate title if left blank</label>
      </div>
    </div>

    <!-- AI Mode Fields -->
    <div id="free_ai_fields">
      <div class="form-group">
        <label>🌐 Language</label>
        <select id="free_lang_select">
          <option value="en" selected>English</option>
          <option value="ur">اردو - Urdu</option>
          <option value="ar">العربية - Arabic</option>
          <option value="hi">हिन्दी - Hindi</option>
          <option value="es">Español - Spanish</option>
          <option value="fr">Français - French</option>
        </select>
      </div>

      <div class="form-group">
        <label>🎬 Reel Type</label>
        <select id="free_reel_type">
          <option value="multi-scene" selected>Multi-Scene (Break into lines with pauses)</option>
          <option value="single-scene">Single-Scene (No changes)</option>
        </select>
      </div>

      <div class="form-group">
        <label>📌 What is your video about?</label>
        <input type="text" id="free_topic" placeholder="Enter your topic..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
      </div>

      <div class="form-group">
        <label>👥 Who is this video for?</label>
        <select id="free_audience">
          <option value="general">General Audience</option>
          <option value="students">Students</option>
          <option value="entrepreneurs">Entrepreneurs</option>
          <option value="creators">Content Creators</option>
          <option value="small_business">Small Business Owners</option>
          <option value="professionals">Professionals / Corporate</option>
          <option value="coaches">Coaches & Consultants</option>
          <option value="stressed_people">People Feeling Stressed</option>
          <option value="beginners">Beginners (in the topic)</option>
          <option value="custom">Custom (type below)</option>
        </select>
        <input type="text" id="free_audience_custom" placeholder="Enter custom audience..." style="margin-top:6px; display:none; width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;">
      </div>

      <!-- Hooks (from DB) -->
      <div class="form-group">
        <label>🎣 Hook Strategy</label>
        <div id="free_hook_buttons" style="display:flex; flex-wrap:wrap; gap:8px; margin-top:6px;">
          <?php
          $hooks_free = mysqli_query($conn, "SELECT id, hook_name FROM hdb_social_media_hooks ORDER BY id");
          if ($hooks_free && mysqli_num_rows($hooks_free) > 0) {
            while ($hook = mysqli_fetch_assoc($hooks_free)) {
              $hook_name = htmlspecialchars($hook['hook_name']);
              echo "<button type='button' class='hook-btn' data-id='{$hook['id']}' onclick='selectFreeHook(this)'>{$hook_name}</button>";
            }
          } else {
            echo "<button type='button' class='hook-btn' data-id='1' onclick='selectFreeHook(this)'>Ever feel like...</button>";
            echo "<button type='button' class='hook-btn' data-id='2' onclick='selectFreeHook(this)'>Stop doing this...</button>";
          }
          ?>
        </div>
        <div style="margin-top:6px;">
          <input type="checkbox" id="free_rnd_hook" checked>
          <label for="free_rnd_hook"> Select hook randomly</label>
        </div>
      </div>

      <!-- Goal / CTA -->
      <div class="form-group">
        <label>🎯 Goal</label>
        <select id="free_goal_select">
          <option value="grow">Grow Followers</option>
          <option value="sell">Sell a Product</option>
          <option value="educate">Educate</option>
          <option value="inspire">Inspire / Motivate</option>
          <option value="traffic">Drive Traffic</option>
        </select>
      </div>

      <div class="form-group">
        <label>📢 Key Takeaway / Offer (Optional)</label>
        <input type="text" id="free_cta_text" placeholder="e.g., Try my free hypnosis audio..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
      </div>
    </div>

    <!-- User Content Mode -->
    <div id="free_user_fields" style="display:none;">
      <div class="form-group">
        <label>📝 Paste your script / story</label>
        <textarea id="free_story_text_user" rows="8" placeholder="Paste your content here..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;"></textarea>
      </div>

      <!-- Optional Enhancements -->
      <div class="form-group">
        <label>Optional Enhancements (AI adapts content)</label>
        <small style="color:#64748b; display:block; margin-bottom:6px;">
          Audience, goal, or opening style can help AI make your reel more scroll-stopping.
        </small>

        <label>👥 Audience (Optional)</label>
        <select id="free_user_audience">
          <option value="">-- Skip / Use default --</option>
          <option value="students">Students</option>
          <option value="professionals">Professionals</option>
          <option value="entrepreneurs">Entrepreneurs</option>
          <option value="general">General Audience</option>
        </select>

        <label>🎣 Opening Style (Optional)</label>
        <select id="free_user_hook">
          <option value="">-- Skip / Use default --</option>
          <option value="pattern_interrupt">Scroll-Stopping Surprise</option>
          <option value="myth_busting">Myth Busting</option>
          <option value="emotional_story">Emotional Story</option>
          <option value="bold_statement">Bold Statement</option>
          <option value="question_opening">Question Opening</option>
          <option value="problem_first">Problem First</option>
        </select>

        <label>🎯 Goal (Optional)</label>
        <select id="free_user_goal">
          <option value="">-- Skip / Use default --</option>
          <option value="grow">Grow Followers</option>
          <option value="sell">Sell a Product</option>
          <option value="educate">Educate</option>
          <option value="inspire">Inspire / Motivate</option>
          <option value="traffic">Drive Traffic</option>
        </select>
      </div>
    </div>

    <!-- Additional Instructions -->
    <div class="form-group">
      <label>🤖 Additional Prompt Instructions (Optional)</label>
      <textarea id="free_prompt_text" rows="3" placeholder="Make it inspirational, add emotional depth, storytelling approach..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;"></textarea>
    </div>

    <!-- Processed Content -->
    <div class="form-group">
      <label>📝 Processed Content (Edit if needed)</label>
      <textarea id="free_processed_content" rows="10" style="width:100%; padding:12px; border:2px solid #e2e8f0; border-radius:8px; font-family:monospace; font-size:14px;" readonly></textarea>
      <div class="button-group" style="display:flex; gap:10px; margin-top:6px;">
        <button class="btn btn-primary" id="freeProcessBtn" onclick="processFreeContent()" style="flex:1;">📝 Step 1: Process Content</button>
        <button class="btn btn-success" id="freeCreateScenesBtn" onclick="createFreeScenes()" style="flex:1; display:none;">🎬 Step 2: Create Scenes</button>
      </div>
    </div>
  </div>
</div>
    <!-- Progress Container (shared) -->
    <div class="progress-container" id="progress_container">
        <h4 style="margin:0 0 10px 0; color:var(--dark-blue);">📊 Real-Time Progress</h4>
        
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progress_bar"></div>
        </div>
        
        <div class="progress-text" id="progress_text">Initializing...</div>
        
        <div class="realtime-log" id="realtime_log">
            <div class="log-entry">[System] Waiting to start...</div>
        </div>
    </div>
    
    <div id="status_msg" style="margin-top:15px; display:none; padding:15px; border-radius:8px; font-weight:600;"></div>
</div>
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
// Create hooks array with IDs from PHP
const hooksArr = <?php echo json_encode($hooks_array); ?>;
let generatedPodcastId = null;

// Language script names for the prompt
const languageScripts = {
    'en': 'English',
    'ur': 'Urdu (اردو)',
    'ar': 'Arabic (العربية)',
    'hi': 'Hindi (हिन्दी)',
    'es': 'Spanish (Español)',
    'fr': 'French (Français)'
};

// Language names for display
const languageNames = {
    'en': 'English',
    'ur': 'اردو - Urdu',
    'ar': 'العربية - Arabic',
    'hi': 'हिन्दी - Hindi',
    'es': 'Español - Spanish',
    'fr': 'Français - French'
};

// Default prompt template
const defaultPrompt = `You are an expert content writer. Write a short reel content for social media with duration on 30-40 seconds

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
- Example format:
  Host: Welcome to our show. Today we're discussing {title} <break time="250ms"/>
  Guest: I'm excited to be here. This topic is so important <break time="250ms"/>
  Host: What's the first thing someone should know? <break time="250ms"/>
  Guest: The most important thing is to understand that... <break time="250ms"/>
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

// Tab switching function
function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(`${tab}-tab`).classList.remove('hidden');
}

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
        document.getElementById('topic_select').innerHTML = '<option value="">-- Error loading topics --</option>';
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
        document.getElementById('sm_id_select').innerHTML = '<option value="">-- Error loading titles --</option>';
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
        hookName = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1];
    }
    
    const cta = document.getElementById('cta_select').value || "[cta_en]";

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
        hookName = hookSelect.options[hookSelect.selectedIndex] ? 
                   hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1] : '[hook]';
    }
    
    // Handle CTA preview
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

// STEP 1: Generate only the content using editable prompt
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
    const hookName = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1];
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
    btn.innerText = "⏳ Generating...";
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating your script...";

    try {
        const response = await fetch('generate_content_only.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `prompt=${encodeURIComponent(prompt)}`
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
    } finally {
        btn.disabled = false;
        btn.innerText = "📝 Step 1: Generate Content";
    }
}

// Function to update progress from server-sent events
function updateProgress(data) {
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    const status = document.getElementById('status_msg');
    
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
    
    if (data.final) {
        if (data.success) {
            status.style.background = '#dcfce7';
            status.innerHTML = `✅ Success! Podcast ID: ${data.podcast_id} with ${data.scene_count} scenes created.`;
            
            if (confirm('Open this podcast in the video maker?')) {
                window.location.href = `videomaker.php?podcast_id=${data.podcast_id}`;
            }
        } else {
            status.style.background = '#fee2e2';
            status.innerHTML = '❌ Error: ' + data.message;
        }
        document.getElementById('createScenesBtn').disabled = false;
        document.getElementById('createScenesBtn').innerText = "🎬 Step 2: Create Scenes from Content";
    }
}

// STEP 2: Create scenes from edited content with live progress
async function createScenesFromContent() {
    const btn = document.getElementById('createScenesBtn');
    const contentArea = document.getElementById('generated_content');
    const sm_select = document.getElementById('sm_id_select');
    const status = document.getElementById('status_msg');
    const progressContainer = document.getElementById('progress_container');
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }

    let editedContent = contentArea.value;
    
    // ===== AUTOMATICALLY ADD MORE PAUSES =====
    console.log("Original content length:", editedContent.length);
    
    // Function to add word-based pauses
    function addWordBasedPauses(text) {
        // Split into sentences first
        const sentences = text.split(/(?<=[.!?])\s+/);
        let result = [];
        
        for (let sentence of sentences) {
            // Skip if sentence already has breaks
            if (sentence.includes('<break')) {
                result.push(sentence);
                continue;
            }
            
            // Split into words
            const words = sentence.split(' ');
            const chunks = [];
            
            // Group words into chunks of 3-4 for natural pauses
            for (let i = 0; i < words.length; i += 4) {
                const chunk = words.slice(i, i + 4).join(' ');
                chunks.push(chunk);
            }
            
            // Join chunks with breaks
            result.push(chunks.join('<break time="200ms"/>'));
        }
        
        return result.join('<break time="300ms"/>');
    }
    
    // Apply basic pause insertions
    editedContent = editedContent.replace(/,(?!\s*<break)/g, ',<break time="200ms"/>');
    editedContent = editedContent.replace(/\band\b(?!\s*<break)/gi, 'and<break time="200ms"/>');
    editedContent = editedContent.replace(/\bor\b(?!\s*<break)/gi, 'or<break time="200ms"/>');
    editedContent = editedContent.replace(/\bbut\b(?!\s*<break)/gi, 'but<break time="200ms"/>');
    editedContent = editedContent.replace(/\.(?!\s*<break)/g, '.<break time="300ms"/>');
    editedContent = editedContent.replace(/\?(?!\s*<break)/g, '?<break time="300ms"/>');
    editedContent = editedContent.replace(/!(?!\s*<break)/g, '!<break time="300ms"/>');
    
    // THEN apply word-based pauses for even more rhythm
    editedContent = addWordBasedPauses(editedContent);
    
    // Clean up any duplicate pauses
    editedContent = editedContent.replace(/(<break time="\d+ms"\/>\s*)+/g, '$1');
    
    console.log("Modified content with word-based pauses:", editedContent.substring(0, 200) + "...");
    
    btn.disabled = true;
    btn.innerText = "⏳ Creating Scenes...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Breaking content into scenes...";
    
    // Show progress container
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.innerHTML = 'Starting...';
    realtimeLog.innerHTML = '<div class="log-entry">[System] Starting scene creation...</div>';

    const hookSelect = document.getElementById('hook_select');
    const hookId = hookSelect.value;
    const hookName = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1];
    const originalTitle = sm_select.options[sm_select.selectedIndex].text;
    const category = document.getElementById('cat_select').value;
    const topic = document.getElementById('topic_select').value;
    const cta = document.getElementById('cta_select').value;
    const sm_id = sm_select.value;

    const combinedTitle = `${hookId} -  ${originalTitle}`;

    const formData = new URLSearchParams();
    formData.append('action', 'create_scenes_from_content');
    formData.append('sm_id', sm_id);
    formData.append('category', category);
    formData.append('topic', topic);
    formData.append('title', originalTitle);
    formData.append('combined_title', combinedTitle);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('target_lang', document.getElementById('db_lang_select').value);
    formData.append('content', editedContent);

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
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
                        console.log('Raw:', line);
                    }
                }
            });
        }
    } catch (err) {
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred';
        btn.disabled = false;
        btn.innerText = "🎬 Step 2: Create Scenes from Content";
    }
}

// Process Free Format Content - UPDATED to use hook, title, story, cta, and additional prompt
async function processFreeContent() {
    const btn = document.getElementById('freeProcessBtn');
    const contentArea = document.getElementById('free_processed_content');
    const status = document.getElementById('status_msg');
    
    const title = document.getElementById('free_title').value.trim();
    const storyText = document.getElementById('free_story_text').value.trim();
    const reelType = document.getElementById('free_reel_type').value;
    const additionalPrompt = document.getElementById('free_prompt_text').value.trim();
    
    // Get hook
    let hookText = '';
    const hookSelect = document.getElementById('free_hook_select');
    const rndHook = document.getElementById('free_rnd_hook').checked;
    
    if (rndHook) {
        const options = Array.from(hookSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomHook = options[Math.floor(Math.random() * options.length)];
            hookText = randomHook.text.split(' - ')[1] || randomHook.text;
            document.getElementById('free_hook_id').value = randomHook.value;
        } else {
            hookText = 'Ever feel like...';
            document.getElementById('free_hook_id').value = '1';
        }
    } else {
        if (hookSelect.selectedIndex > 0) {
            hookText = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1] || hookSelect.options[hookSelect.selectedIndex].text;
            document.getElementById('free_hook_id').value = hookSelect.value;
        } else {
            hookText = 'Ever feel like...';
            document.getElementById('free_hook_id').value = '1';
        }
    }
    
    // Get CTA
    let ctaText = '';
    const ctaSelect = document.getElementById('free_cta_select');
    const rndCta = document.getElementById('free_rnd_cta').checked;
    
    if (rndCta) {
        const options = Array.from(ctaSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomCta = options[Math.floor(Math.random() * options.length)];
            ctaText = randomCta.value;
            document.getElementById('free_cta').value = ctaText;
        } else {
            ctaText = 'Follow for more daily tips';
            document.getElementById('free_cta').value = ctaText;
        }
    } else {
        if (ctaSelect.value) {
            ctaText = ctaSelect.value;
            document.getElementById('free_cta').value = ctaText;
        } else {
            ctaText = 'Follow for more daily tips';
            document.getElementById('free_cta').value = ctaText;
        }
    }
    
    if (!title || !storyText) {
        alert("Please enter both title and story content");
        return;
    }
    
    btn.disabled = true;
    btn.innerText = "⏳ Processing...";
    contentArea.value = "Processing...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    
    if (reelType === 'single-scene') {
        // SINGLE-SCENE: Use the story exactly as-is - NO PROCESSING, NO PAUSES
        console.log("Single-scene mode: using text as-is without pauses");
        
        // But we still need to format it with the hook and CTA
        let formattedContent = `${hookText}\n\n${storyText}\n\n${ctaText}`;
        contentArea.value = formattedContent;
        document.getElementById('freeCreateScenesBtn').style.display = 'block';
        status.style.background = '#dcfce7';
        status.innerHTML = "✅ Content ready! You can edit it above, then click Step 2.";
        btn.disabled = false;
        btn.innerText = "📝 Step 1: Process Content";
        return;
    }
    
    // MULTI-SCENE: Process with ChatGPT to add pauses and enhance with hook/cta
    status.innerHTML = "⏳ Breaking content into emotional scenes with pauses...";
    
    const language = document.getElementById('free_lang_select').value;
    const langName = languageScripts[language] || language;
    
    let processingPrompt = `You are an expert content writer. Create an engaging social media reel script using the following elements:

TITLE: "${title}"
HOOK: "${hookText}"
STORY: "${storyText}"
CTA: "${ctaText}"
LANGUAGE: ${langName}
REEL TYPE: Multi-scene (with pauses)

${additionalPrompt ? `ADDITIONAL INSTRUCTIONS: ${additionalPrompt}` : ''}

**CRITICAL INSTRUCTIONS:**
1. Start with the HOOK to grab attention immediately
2. Then incorporate the STORY naturally - enhance it to be more engaging
3. Add strategic pauses using Azure SSML tags: <break time="0.2s"/>, <break time="0.4s"/>, <break time="0.6s"/>, <break time="0.8s"/>
4. End with the CTA as a powerful closing
5. Break into short, emotional sentences (max 10 words per line)
6. Split at every 'and', 'or', comma, and natural pause point
7. Use warm, conversational tone in ${langName}

**LANGUAGE ENFORCEMENT:**
- If LANGUAGE is Urdu (ur): Write entirely in Urdu script, no Roman Urdu
- If LANGUAGE is Arabic (ar): Write entirely in Arabic script
- If LANGUAGE is Hindi (hi): Write entirely in Devanagari script
- If LANGUAGE is Spanish (es): Write entirely in Spanish
- If LANGUAGE is French (fr): Write entirely in French

OUTPUT ONLY the processed text with pause tags - no explanations.`;

    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', processingPrompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Make sure the content has break tags
            let processedContent = data.content;
            
            // If no break tags were added, add some basic ones
            if (!processedContent.includes('<break')) {
                console.log("No break tags found, adding basic ones");
                processedContent = processedContent.replace(/\./g, '.<break time="0.4s"/>')
                                                  .replace(/\?/g, '?<break time="0.4s"/>')
                                                  .replace(/!/g, '!<break time="0.4s"/>')
                                                  .replace(/,/g, ',<break time="0.2s"/>');
            }
            
            contentArea.value = processedContent;
            document.getElementById('freeCreateScenesBtn').style.display = 'block';
            status.style.background = '#dcfce7';
            status.innerHTML = "✅ Content processed with pauses! You can edit it above, then click Step 2.";
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
        btn.innerText = "📝 Step 1: Process Content";
    }
}

// Create Free Scenes - UPDATED to pass hook and CTA
async function createFreeScenes() {
    const btn = document.getElementById('freeCreateScenesBtn');
    const contentArea = document.getElementById('free_processed_content');
    const status = document.getElementById('status_msg');
    const progressContainer = document.getElementById('progress_container');
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }
    
    const language = document.getElementById('free_lang_select').value;
    const reelType = document.getElementById('free_reel_type').value;
    const title = document.getElementById('free_title').value.trim();
    const hookId = document.getElementById('free_hook_id').value || '1';
    const cta = document.getElementById('free_cta').value || 'Follow for more';
    
    let editedContent = contentArea.value;
    
    // For single-scene, we need to format it as ONE SCENE
    if (reelType === 'single-scene') {
        // Combine all text into one continuous scene
        editedContent = editedContent.replace(/\s+/g, ' ').trim();
        
        // Add a single break at the end for natural pause
        if (!editedContent.includes('<break')) {
            editedContent = editedContent + '<break time="0.5s"/>';
        }
        
        console.log("Single-scene mode: combining into one scene");
    }
    
    btn.disabled = true;
    btn.innerText = "⏳ Creating Scenes...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating scenes from your content...";
    
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.innerHTML = 'Starting...';
    realtimeLog.innerHTML = '<div class="log-entry">[System] Starting scene creation...</div>';
    
    const combinedTitle = `${hookId} - ${title}`;
    
    const formData = new URLSearchParams();
    formData.append('action', 'create_free_scenes');
    formData.append('title', title);
    formData.append('combined_title', combinedTitle);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('content', editedContent);
    formData.append('target_lang', document.getElementById('free_lang_select').value);
    formData.append('reel_type', reelType);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
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
                        console.log('Raw:', line);
                    }
                }
            });
        }
    } catch (err) {
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred';
        btn.disabled = false;
        btn.innerText = "🎬 Step 2: Create Scenes from Content";
    }
}
function toggleFreeMode() {
    const mode = document.getElementById('free_mode_select').value;
    document.getElementById('free_ai_fields').style.display = (mode === 'ai') ? 'block' : 'none';
    document.getElementById('free_user_fields').style.display = (mode === 'user') ? 'block' : 'none';
}

// Show custom audience input if "Custom" selected
document.getElementById('free_audience').addEventListener('change', function() {
    document.getElementById('free_audience_custom').style.display = (this.value === 'custom') ? 'block' : 'none';
});
// Add function to add new hooks in free format
function openFreeHookModal() {
    // Create a simple prompt for adding hook
    const hookName = prompt("Enter new hook strategy (e.g., 'Ever wondered why...'):");
    if (!hookName) return;
    
    // Add to database via AJAX
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
            // Add to dropdown
            const select = document.getElementById('free_hook_select');
            const option = document.createElement('option');
            option.value = data.id;
            option.text = data.id + ' - ' + hookName;
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

// Create Free Scenes
async function createFreeScenes() {
    const btn = document.getElementById('freeCreateScenesBtn');
    const contentArea = document.getElementById('free_processed_content');
    const status = document.getElementById('status_msg');
    const progressContainer = document.getElementById('progress_container');
    const progressBar = document.getElementById('progress_bar');
    const progressText = document.getElementById('progress_text');
    const realtimeLog = document.getElementById('realtime_log');
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }
    
    const language = document.getElementById('free_lang_select').value;
    const reelType = document.getElementById('free_reel_type').value;
    const title = document.getElementById('free_title').value.trim();
    const hookId = document.getElementById('free_hook_id').value;
    const cta = document.getElementById('free_cta').value;
    
    let editedContent = contentArea.value;
    
    // For single-scene, we need to format it as ONE SCENE
    if (reelType === 'single-scene') {
        // Combine all text into one continuous scene
        // Remove any existing line breaks and extra spaces
        editedContent = editedContent.replace(/\s+/g, ' ').trim();
        
        // Add a single break at the end for natural pause
        if (!editedContent.includes('<break')) {
            editedContent = editedContent + '<break time="0.5s"/>';
        }
        
        console.log("Single-scene mode: combining into one scene");
    }
    
    btn.disabled = true;
    btn.innerText = "⏳ Creating Scenes...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating scenes from your content...";
    
    progressContainer.style.display = 'block';
    progressBar.style.width = '0%';
    progressText.innerHTML = 'Starting...';
    realtimeLog.innerHTML = '<div class="log-entry">[System] Starting scene creation...</div>';
    
    const combinedTitle = `${hookId} - ${title}`;
    
    const formData = new URLSearchParams();
    formData.append('action', 'create_free_scenes');
    formData.append('title', title);
    formData.append('combined_title', combinedTitle);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('content', editedContent);
    formData.append('target_lang', document.getElementById('free_lang_select').value);
    formData.append('reel_type', reelType);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
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
                        console.log('Raw:', line);
                    }
                }
            });
        }
    } catch (err) {
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred';
        btn.disabled = false;
        btn.innerText = "🎬 Step 2: Create Scenes from Content";
    }
}

// ===== MODAL FUNCTIONS FOR ADDING CATEGORY/TOPIC/TITLE/CTA =====
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
    
    // For topic, check if category is selected first
    if (type === 'topic') {
        const cat = document.getElementById('cat_select').value;
        if (!cat) {
            alert('Please select a category first before adding a topic.');
            return;
        }
    }
    
    // For title, check if category and topic are selected first
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
    const msg = document.getElementById('modalMsg');
    msg.innerHTML = ''; 
    msg.style.color = '';
    document.getElementById('modalOverlay').style.display = 'block';
    document.getElementById('addModal').style.display = 'block';
    setTimeout(() => document.getElementById('modalInput').focus(), 100);
}

function closeAddModal() {
    document.getElementById('modalOverlay').style.display = 'none';
    document.getElementById('addModal').style.display = 'none'; // Fixed: changed from 'block' to 'none'
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
                // Clear topic and title selects
                document.getElementById('topic_select').innerHTML = '<option value="">-- Select Topic --</option>';
                document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
            } else if (currentModalType === 'topic') {
                // Reload topics and clear titles
                loadTopics(document.getElementById('cat_select').value);
                document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
            } else if (currentModalType === 'title') {
                // Reload titles
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

// Mode toggle buttons
function setFreeMode(mode) {
    document.getElementById('free_ai_fields').style.display = (mode === 'ai') ? 'block' : 'none';
    document.getElementById('free_user_fields').style.display = (mode === 'user') ? 'block' : 'none';
    
    // Button active state
    document.getElementById('btn_ai_mode').classList.toggle('active', mode === 'ai');
    document.getElementById('btn_user_mode').classList.toggle('active', mode === 'user');
}

// Show custom audience input if "Custom" selected
document.getElementById('free_audience').addEventListener('change', function() {
    document.getElementById('free_audience_custom').style.display = (this.value === 'custom') ? 'block' : 'none';
});

// Hook button selection
let selectedHookId = null;
function selectFreeHook(btn) {
    // Deselect all buttons
    document.querySelectorAll('#free_hook_buttons .hook-btn').forEach(b => b.classList.remove('selected'));
    // Select clicked button
    btn.classList.add('selected');
    selectedHookId = btn.getAttribute('data-id');
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
        hookName = hookSelect.options[hookSelect.selectedIndex] ? 
                   hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1] : '[hook]';
    }
    
    // Handle CTA - with random selection
    let cta;
    const rndCtaCheckbox = document.getElementById('rnd_cta');
    const ctaSelect = document.getElementById('cta_select');
    
    if (rndCtaCheckbox && rndCtaCheckbox.checked) {
        // Get all non-empty options
        const options = Array.from(ctaSelect.options).filter(opt => opt.value !== '');
        if (options.length > 0) {
            const randomCta = options[Math.floor(Math.random() * options.length)];
            cta = randomCta.value;
        } else {
            cta = 'Follow for more content'; // Default fallback
        }
    } else {
        cta = ctaSelect.value || '[cta]';
    }

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
// Initialize on load
window.onload = function() {
    document.getElementById('editable_prompt').value = defaultPrompt;
    updateDBLanguageBadge();
    updatePrompt();
    
    // Initialize empty dropdowns
    document.getElementById('topic_select').innerHTML = '<option value="">-- Select Topic --</option>';
    document.getElementById('sm_id_select').innerHTML = '<option value="">-- Select Title --</option>';
    
    // Add event listeners
    document.getElementById('cat_select').addEventListener('change', function() {
        loadTopics(this.value);
    });
    
    document.getElementById('topic_select').addEventListener('change', function() {
        loadTitles(this.value);
    });
};

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