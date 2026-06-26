<?php

ini_set('display_errors', 0); // Turn off display errors
ini_set('log_errors', 1); // But log them
ini_set('memory_limit', '512M');


set_time_limit(300); // Also add this to prevent timeout
session_start();

if(!isset($_SESSION['admin_id']))
{
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];

require_once 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';

// Set MySQL session variables to prevent timeout
mysqli_query($conn, "SET SESSION wait_timeout = 28800");
mysqli_query($conn, "SET SESSION max_allowed_packet = 128M");
mysqli_query($conn, "SET SESSION net_read_timeout = 300");
mysqli_query($conn, "SET SESSION net_write_timeout = 300");

// Language options
$languages = [
    'en' => 'English',
    'ur' => 'اردو - Urdu',
    'ar' => 'العربية - Arabic',
    'hi' => 'हिन्दी - Hindi',
    'es' => 'Español - Spanish',
    'fr' => 'Français - French'
];

// Social Media Types
$social_types = [
    'regular' => 'Regular Social Media Post',
    'podcast' => 'Podcast Episode',
    'broll' => 'B-Roll / Background Footage',
    'story' => 'Story / Narrative',
    'educational' => 'Educational Content',
    'promotional' => 'Promotional / Ad'
];

// --- AJAX HANDLERS ---
if (isset($_POST['get_topics'])) {
    $cat = mysqli_real_escape_string($conn, $_POST['category']);
    $res = mysqli_query($conn, "SELECT DISTINCT topic_key FROM hdb_social_media WHERE category_key = '$cat' AND (sm_status = '' OR sm_status IS NULL)");
    echo '<option value="">-- Select Topic --</option>';
    while ($row = mysqli_fetch_assoc($res)) { echo "<option value='{$row['topic_key']}'>{$row['topic_key']}</option>"; }
    exit;
}

if (isset($_POST['get_titles'])) {
    $topic = mysqli_real_escape_string($conn, $_POST['topic']);
    $res = mysqli_query($conn, "SELECT id, title FROM hdb_social_media WHERE topic_key = '$topic' AND (sm_status = '' OR sm_status IS NULL)");
    echo '<option value="">-- Select Title --</option>';
    while ($row = mysqli_fetch_assoc($res)) { echo "<option value='{$row['id']}'>" . htmlspecialchars($row['title']) . "</option>"; }
    exit;
}

// Handler for generating content only (calls generate_content_only.php)
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_content_only') {
    header('Content-Type: application/json');
    
    $prompt = $_POST['prompt'] ?? '';
    
    if (empty($prompt)) {
        echo json_encode(['success' => false, 'message' => 'Prompt is required']);
        exit;
    }
    
    // Forward to generate_content_only.php
    require_once 'generate_content_only.php';
    exit;
}

// Handler for free format content generation
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_free_content') {
    header('Content-Type: application/json');
    
    $language = mysqli_real_escape_string($conn, $_POST['language'] ?? 'en');
    $social_type = mysqli_real_escape_string($conn, $_POST['social_type'] ?? 'regular');
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $user_text = mysqli_real_escape_string($conn, $_POST['user_text'] ?? '');
    $user_prompt = mysqli_real_escape_string($conn, $_POST['user_prompt'] ?? '');
    
    if (empty($title) || empty($user_text)) {
        echo json_encode(['success' => false, 'message' => 'Title and text are required']);
        exit;
    }
    
    $lang_name = $languages[$language] ?? 'English';
    $type_name = $social_types[$social_type] ?? 'Social Media';
    
    $generation_prompt = "You are an expert content writer. Create social media content based on the following:

TITLE: $title
LANGUAGE: $lang_name
CONTENT TYPE: $type_name
USER'S BASE TEXT: $user_text

ADDITIONAL INSTRUCTIONS FROM USER: $user_prompt

IMPORTANT INSTRUCTIONS:
1. Create engaging, natural content in $lang_name
2. If language is not English, write entirely in that language with proper cultural context
3. Structure the content into short scenes (max 10 words per scene)
4. Add <break time=\"250ms\"/> or <break time=\"350ms\"/> between scenes for natural pauses
5. Make it conversational and engaging

OUTPUT ONLY the formatted content with break tags. No explanations.";

    $result = callChatGPT_inam($generation_prompt);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'content' => $result['response']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['error'] ?? 'Generation failed'
        ]);
    }
    exit;
}

// Handler for creating scenes from database content
if (isset($_POST['action']) && $_POST['action'] == 'create_scenes_from_content') {
    // Clear any previous output
    if (ob_get_level()) ob_clean();
    
    // Set headers for streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    
    // Disable error display
    ini_set('display_errors', 0);
    
    // Function to send progress
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
    
    // Send initial progress
    sendProgress(1, "Initializing...", "Handler started", 'info');
    
    try {
        $sm_id = (int)($_POST['sm_id'] ?? 0);
        $category = mysqli_real_escape_string($conn, $_POST['category'] ?? '');
        $topic = mysqli_real_escape_string($conn, $_POST['topic'] ?? '');
        $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
        $combined_title = mysqli_real_escape_string($conn, $_POST['combined_title'] ?? '');
        $hook_id = (int)($_POST['hook_id'] ?? 1);
        $cta = mysqli_real_escape_string($conn, $_POST['cta'] ?? '');
        $content = $_POST['content'] ?? '';
        $target_lang = mysqli_real_escape_string($conn, $_POST['target_lang'] ?? 'en');
        $admin_id = $_SESSION['admin_id'];
        
        // Validate required fields
        if (empty($category) || empty($topic) || empty($title) || empty($content)) {
            throw new Exception("Missing required fields");
        }
        
        sendProgress(5, "Starting...", "Creating podcast record", 'info');
        
        // First create the podcast record
        $p1_prompt = "Generate SQL INSERT for hdb_podcasts:
        category: '$category'
        topic_key: '$topic'
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
        VALUES ('$category', '$topic', '$combined_title', '$target_lang', '$admin_id', $hook_id, '[hashtags]', '[keywords]', '[caption_text]', NOW());
        
        Output SQL ONLY.";

        $res1 = callChatGPT_inam($p1_prompt);
        
        if (!$res1['success']) {
            throw new Exception("Failed to generate podcast SQL: " . ($res1['error'] ?? 'Unknown error'));
        }
        
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
            throw new Exception("Failed to create podcast: " . mysqli_error($conn));
        }

        sendProgress(30, "Generating scenes...", "Calling ChatGPT to create scenes", 'info');

        // JSON PROMPT FOR SCENE GENERATION
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
        
        if (!$res2['success']) {
            throw new Exception("Failed to generate scenes: " . ($res2['error'] ?? 'Unknown error'));
        }
        
        $json_response = trim($res2['response']);

        // Clean up the response - remove any markdown code fences
        $json_response = preg_replace('/^```json\s*/i', '', $json_response);
        $json_response = preg_replace('/^```\s*/i', '', $json_response);
        $json_response = preg_replace('/\s*```$/i', '', $json_response);
        $json_response = trim($json_response);

        // Decode JSON
        $scenes = json_decode($json_response, true);

        if (!$scenes || !is_array($scenes)) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg() . "\nResponse: " . substr($json_response, 0, 200));
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
            throw new Exception("Failed to prepare statement: " . mysqli_error($conn));
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
        $actor = 'host';
        $visual_type = 'image';
        $status = 'PENDING';
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
            $text_contents = $scene['text_contents'] ?? '';
            $text_display = $scene['text_display'] ?? $scene['text_contents'] ?? '';
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
            sendProgress(100, "Complete!", "✅ All $success_count scenes created successfully!", 'success');
            
            echo "data: " . json_encode([
                'success' => true,
                'podcast_id' => $podcast_id,
                'scene_count' => $success_count,
                'final' => true
            ]) . "\n\n";
        } else {
            throw new Exception("Failed to create scenes: " . implode('; ', $errors));
        }
        
    } catch (Exception $e) {
        // Send error as JSON
        echo "data: " . json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'final' => true
        ]) . "\n\n";
    }
    exit;
}

// Handler for creating scenes from free format content
if (isset($_POST['action']) && $_POST['action'] == 'create_free_scenes') {
    // Set headers for streaming
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
    
    $category = mysqli_real_escape_string($conn, $_POST['social_type'] ?? 'free-format');
    $topic = mysqli_real_escape_string($conn, $_POST['topic'] ?? 'custom');
    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
    $combined_title = mysqli_real_escape_string($conn, $_POST['combined_title'] ?? $title);
    $hook_id = (int)($_POST['hook_id'] ?? 1);
    $cta = mysqli_real_escape_string($conn, $_POST['cta'] ?? '');
    $content = $_POST['content'] ?? '';
    $target_lang = mysqli_real_escape_string($conn, $_POST['target_lang'] ?? 'en');
    $social_type = mysqli_real_escape_string($conn, $_POST['social_type'] ?? 'regular');
    $admin_id = $_SESSION['admin_id'];
    
    sendProgressFree(5, "Starting...", "Creating podcast record", 'info');
    
    // First create the podcast record
    $p1_prompt = "Generate SQL INSERT for hdb_podcasts:
    category: '$social_type'
    topic_key: '$topic'
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
    VALUES ('$social_type', '$topic', '$combined_title', '$target_lang', '$admin_id', $hook_id, '[hashtags]', '[keywords]', '[caption_text]', NOW());
    
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
        sendProgressFree(20, "Podcast created", "✅ Podcast ID: $podcast_id", 'success');
    } else {
        sendProgressFree(0, "Error!", "❌ Failed to create podcast: " . mysqli_error($conn), 'error');
        echo "data: " . json_encode(['success' => false, 'message' => 'Failed to create podcast', 'final' => true]) . "\n\n";
        exit;
    }

    sendProgressFree(30, "Generating scenes...", "Calling ChatGPT to create scenes", 'info');

    // JSON PROMPT FOR SCENE GENERATION (same as above)
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
        sendProgressFree(0, "Error!", "❌ Failed to parse JSON response: " . json_last_error_msg(), 'error');
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

    sendProgressFree(40, "Inserting scenes...", "Found " . $total_statements . " scenes to insert", 'info');

    // Prepare the INSERT statement once
    $insert_sql = "INSERT INTO hdb_podcast_stories 
                   (podcast_id, lang_code, category, topic_key, title, actor, 
                    text_contents, text_display, duration, prompt, visual_type, 
                    status, created_date, seq_no, logo_flag, hashtags) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)";

    $stmt = mysqli_prepare($conn, $insert_sql);

    if (!$stmt) {
        sendProgressFree(0, "Error!", "❌ Failed to prepare statement: " . mysqli_error($conn), 'error');
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
    $actor = 'host';
    $visual_type = 'image';
    $status = 'PENDING';
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
            
            sendProgressFree(40, "Reconnecting...", "⚠️ Database reconnected", 'warning');
        }
        
        // Set values from scene with proper escaping
        $text_contents = $scene['text_contents'] ?? '';
        $text_display = $scene['text_display'] ?? $scene['text_contents'] ?? '';
        $duration = (int)($scene['duration'] ?? 5);
        $prompt = $scene['prompt'] ?? '';
        $hashtags = $scene['hashtags'] ?? '';
        $seq_no = $index + 1;
        
        if (mysqli_stmt_execute($stmt)) {
            $success_count++;
            $percent = 40 + floor(($success_count / $total_statements) * 50);
            sendProgressFree($percent, "Inserting scenes...", "✅ Inserted scene " . $success_count, 'success');
        } else {
            $error = mysqli_stmt_error($stmt);
            $errors[] = $error;
            sendProgressFree(40 + floor(($index / $total_statements) * 50), "Inserting scenes...", "❌ Error on scene " . ($index+1) . ": " . $error, 'error');
            error_log("Scene Insert Error: " . $error . " for scene: " . print_r($scene, true));
        }
        
        // Small delay to prevent overwhelming the server
        usleep(50000); // 0.05 seconds
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
        
        echo "data: " . json_encode([
            'success' => false, 
            'message' => 'Failed to create scenes. ' . implode('; ', $errors),
            'debug' => substr($json_response, 0, 500),
            'final' => true
        ]) . "\n\n";
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
            <a href="vidora.php" class="active">Contents</a>
            <a href="image_gen.php">Images</a>
            <a href="videomaker.php">Video</a>
            <a href="podcast_translator.php">Translate</a>
            <a href="publisher/dashboard.php">Schedule</a>
        </nav>
    </header>
    
    <!-- Tab Bar -->
    <div class="tab-bar">
        <div class="tab active" onclick="switchTab('database')" id="tab-database">📚 Database Content</div>
        <div class="tab" onclick="switchTab('free')" id="tab-free">✏️ Free Format</div>
    </div>
    
    <!-- Database Content Tab -->
    <div id="database-tab" class="tab-content">
        <div class="card">
            <h2 style="color:var(--dark-blue); margin-top:0;">🎙️ Database Content Creator</h2>
            <p style="color: #64748b; margin-bottom: 20px;">Use existing categories, topics and titles from database</p>
            
            <!-- Language Dropdown for Database Tab -->
            <div class="form-group">
                <label>🌐 Target Language <span class="lang-badge" id="dbSelectedLangBadge">English</span></label>
                <select id="db_lang_select" onchange="updateDBLanguageBadge(); updateDBPrompt();">
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?= $code ?>" <?= ($code === 'en') ? 'selected' : '' ?>>
                            <?= $name ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Category & Topic</label>
                <select id="cat_select" onchange="loadTopics(this.value)">
                    <option value="">-- Select Category --</option>
                    <?php
                    $cats = mysqli_query($conn, "SELECT DISTINCT category_key FROM hdb_social_media WHERE (sm_status = '' OR sm_status IS NULL)");
                    while ($c = mysqli_fetch_assoc($cats)) echo "<option value='".htmlspecialchars($c['category_key'])."'>".htmlspecialchars($c['category_key'])."</option>";
                    ?>
                </select>
                <select id="topic_select" onchange="loadTitles(this.value)" style="margin-top:10px;"><option value="">-- Select Topic --</option></select>
            </div>

            <div class="form-group">
                <label>Title</label>
                <select id="sm_id_select" onchange="updateDBPrompt()"><option value="">-- Select Title --</option></select>
            </div>

            <div class="form-group">
                <label>Hook Strategy (ID - Name)</label>
                <select id="hook_select" onchange="updateDBPrompt()">
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
                    <input type="checkbox" id="rnd_hook" checked onchange="updateDBPrompt()"> 
                    Select Hook randomly
                </div>
            </div>

            <div class="form-group">
                <label>CTA</label>
                <select id="cta_select" onchange="updateDBPrompt()">
                    <?php 
                    $ctas = mysqli_query($conn, "SELECT cta_en FROM hdb_social_media_cta");
                    while ($ct = mysqli_fetch_assoc($ctas)) echo "<option value='".htmlspecialchars($ct['cta_en'])."'>".htmlspecialchars($ct['cta_en'])."</option>";
                    ?>
                </select>
            </div>

            <!-- Editable Prompt Area -->
            <div class="form-group">
                <label>✏️ Editable Prompt (Customize before generating)</label>
                <div class="prompt-variables">
                    <span class="variable-badge">{category}</span>
                    <span class="variable-badge">{topic}</span>
                    <span class="variable-badge">{title}</span>
                    <span class="variable-badge">{hook}</span>
                    <span class="variable-badge">{cta}</span>
                    <span class="variable-badge">{language}</span>
                    <span style="margin-left: 10px;">These variables will be replaced automatically</span>
                </div>
                <textarea id="editable_prompt" class="prompt-editor" placeholder="Customize your prompt here..."></textarea>
                <div class="prompt-actions">
                    <button class="btn btn-small" onclick="resetDBPrompt()">↺ Reset to Default</button>
                    <button class="btn btn-small" onclick="previewDBPrompt()">👁️ Preview with Current Values</button>
                </div>
            </div>

            <!-- Generated Content Area -->
            <div class="form-group">
                <label>📝 Generated Content (Edit if needed)</label>
                <textarea id="generated_content" rows="10" style="width:100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-family: monospace; font-size: 14px;"></textarea>
                <div class="button-group">
                    <button class="btn btn-primary" id="generateContentBtn" onclick="generateDBContentOnly()" style="flex:1;">📝 Step 1: Generate Content</button>
                    <button class="btn btn-success" id="createScenesBtn" onclick="createDBScenesFromContent()" style="flex:1; display: none;">🎬 Step 2: Create Scenes from Content</button>
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
            <p style="color: #64748b; margin-bottom: 20px;">Create custom content with your own title and text</p>
            
            <div class="form-group">
                <label>🌐 Language</label>
                <select id="free_lang_select">
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?= $code ?>" <?= ($code === 'en') ? 'selected' : '' ?>>
                            <?= $name ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>📱 Content Type</label>
                <select id="free_type_select">
                    <?php foreach ($social_types as $code => $name): ?>
                        <option value="<?= $code ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>📌 Title</label>
                <input type="text" id="free_title" placeholder="Enter a title for your content...">
            </div>
            
            <div class="form-group">
                <label>📝 Your Text / Ideas</label>
                <textarea id="free_text" rows="5" placeholder="Enter your base text, ideas, or bullet points..."></textarea>
            </div>
            
            <div class="form-group">
                <label>🤖 Additional Instructions (Optional)</label>
                <textarea id="free_prompt" rows="3" placeholder="E.g., Make it inspirational, Use a storytelling approach, Focus on stress relief..."></textarea>
            </div>
            
            <div class="info-box">
                <strong>💡 How it works:</strong> The AI will use your title and text as a base to generate engaging, well-structured content with proper scene breaks.
            </div>
            
            <!-- Free Format Generated Content -->
            <div class="form-group">
                <label>📝 Generated Content (Edit if needed)</label>
                <textarea id="free_generated_content" rows="10" style="width:100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-family: monospace; font-size: 14px;"></textarea>
                <div class="button-group">
                    <button class="btn btn-primary" id="freeGenerateBtn" onclick="generateFreeContent()" style="flex:1;">📝 Step 1: Generate Content</button>
                    <button class="btn btn-success" id="freeCreateScenesBtn" onclick="createFreeScenes()" style="flex:1; display: none;">🎬 Step 2: Create Scenes from Content</button>
                </div>
            </div>
            
            <!-- Hidden fields for free format -->
            <input type="hidden" id="free_hook_id" value="1">
            <input type="hidden" id="free_cta" value="Subscribe for more">
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

<script>
// Create hooks array with IDs from PHP
const hooksArr = <?php echo json_encode($hooks_array); ?>;
let generatedPodcastId = null;

// Language names for display
const languageNames = {
    'en': 'English',
    'ur': 'اردو - Urdu',
    'ar': 'العربية - Arabic',
    'hi': 'हिन्दी - Hindi',
    'es': 'Español - Spanish',
    'fr': 'Français - French'
};

// Language script names for the prompt
const languageScripts = {
    'en': 'English',
    'ur': 'Urdu (اردو)',
    'ar': 'Arabic (العربية)',
    'hi': 'Hindi (हिन्दी)',
    'es': 'Spanish (Español)',
    'fr': 'French (Français)'
};

// Tab switching function
function switchTab(tab) {
    // Update tab styles
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    // Show/hide tab content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(`${tab}-tab`).classList.remove('hidden');
}

// Database tab functions
function updateDBLanguageBadge() {
    const langSelect = document.getElementById('db_lang_select');
    const selectedLang = langSelect.value;
    document.getElementById('dbSelectedLangBadge').innerText = languageNames[selectedLang] || selectedLang;
}

// Enhanced multilingual prompt template for database tab
const defaultDBPrompt = `You are an expert content writer and Write a short reel content for social media with duration on 30-40 seconds

TITLE: {title}
CATEGORY: {category}
TOPIC: {topic}
HOOK: {hook}
LANGUAGE: {language}
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

CTA: {cta}

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

function loadTopics(cat) {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `get_topics=1&category=${cat}` })
    .then(r => r.text()).then(d => document.getElementById('topic_select').innerHTML = d);
}

function loadTitles(topic) {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `get_titles=1&topic=${topic}` })
    .then(r => r.text()).then(d => { 
        document.getElementById('sm_id_select').innerHTML = d; 
        setTimeout(updateDBPrompt, 100); 
    });
}

function updateDBPrompt() {
    const cat = document.getElementById('cat_select').value || "[category]";
    const topic = document.getElementById('topic_select').value || "[topic]";
    const ts = document.getElementById('sm_id_select');
    const originalTitle = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[original title]";
    const selectedLang = document.getElementById('db_lang_select').value;
    const langName = languageScripts[selectedLang] || selectedLang;
    
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
    
    const combinedTitle = `${hookId} -  ${originalTitle}`;
    const cta = document.getElementById('cta_select').value || "[cta_en]";

    const editablePrompt = document.getElementById('editable_prompt');
    if (!editablePrompt.value || editablePrompt.value === defaultDBPrompt) {
        editablePrompt.value = defaultDBPrompt;
    }
    
    document.getElementById('prompt_preview').value = `Create a hypnotherapy script for:
Category: ${cat}
Topic: ${topic}
Title: ${originalTitle}
Hook: ${hookName}
Language: ${langName}
CTA: ${cta}

Write in warm, conversational tone with <break> tags for pauses.`;
}

function resetDBPrompt() {
    document.getElementById('editable_prompt').value = defaultDBPrompt;
    previewDBPrompt();
}

function previewDBPrompt() {
    const cat = document.getElementById('cat_select').value || "[category]";
    const topic = document.getElementById('topic_select').value || "[topic]";
    const ts = document.getElementById('sm_id_select');
    const originalTitle = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[original title]";
    const selectedLang = document.getElementById('db_lang_select').value;
    const langName = languageScripts[selectedLang] || selectedLang;
    
    let hookName;
    if (document.getElementById('rnd_hook').checked) {
        const randomHook = hooksArr[Math.floor(Math.random() * hooksArr.length)];
        hookName = randomHook.name;
    } else {
        const hookSelect = document.getElementById('hook_select');
        hookName = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1];
    }
    
    const cta = document.getElementById('cta_select').value || "[cta_en]";
    
    let prompt = document.getElementById('editable_prompt').value;
    
    prompt = prompt.replace(/{category}/g, cat)
                   .replace(/{topic}/g, topic)
                   .replace(/{title}/g, originalTitle)
                   .replace(/{hook}/g, hookName)
                   .replace(/{cta}/g, cta)
                   .replace(/{language}/g, langName);
    
    document.getElementById('prompt_preview').value = prompt;
    alert('Preview updated in the Prompt Preview field below');
}

// STEP 1: Generate only the content using editable prompt
async function generateDBContentOnly() {
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

    let prompt = document.getElementById('editable_prompt').value;
    
    if (!prompt.trim()) {
        prompt = defaultDBPrompt;
    }
    
    prompt = prompt.replace(/{category}/g, category)
                   .replace(/{topic}/g, topic)
                   .replace(/{title}/g, originalTitle)
                   .replace(/{hook}/g, hookName)
                   .replace(/{cta}/g, cta)
                   .replace(/{language}/g, langName);

    btn.disabled = true;
    btn.innerText = "⏳ Generating...";
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating your script...";

    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'generate_content_only');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
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
        btn.innerText = "📝 Step 1: Generate Content";
    }
}

// STEP 2: Create scenes from edited content with live progress (Database)


// Free Format Functions
async function generateFreeContent() {
    const btn = document.getElementById('freeGenerateBtn');
    const contentArea = document.getElementById('free_generated_content');
    const status = document.getElementById('status_msg');
    
    const language = document.getElementById('free_lang_select').value;
    const socialType = document.getElementById('free_type_select').value;
    const title = document.getElementById('free_title').value.trim();
    const userText = document.getElementById('free_text').value.trim();
    const userPrompt = document.getElementById('free_prompt').value.trim();
    
    if (!title || !userText) {
        alert("Please enter both title and text");
        return;
    }
    
    btn.disabled = true;
    btn.innerText = "⏳ Generating...";
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating your free format content...";
    
    const formData = new URLSearchParams();
    formData.append('ajax_action', 'generate_free_content');
    formData.append('language', language);
    formData.append('social_type', socialType);
    formData.append('title', title);
    formData.append('user_text', userText);
    formData.append('user_prompt', userPrompt);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            contentArea.value = data.content;
            document.getElementById('freeCreateScenesBtn').style.display = 'block';
            status.style.background = '#dcfce7';
            status.innerHTML = "✅ Free format content generated! You can edit it above, then click Step 2.";
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

async function createFreeScenes() {
    const btn = document.getElementById('freeCreateScenesBtn');
    const contentArea = document.getElementById('free_generated_content');
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
    const socialType = document.getElementById('free_type_select').value;
    const title = document.getElementById('free_title').value.trim();
    const hookId = document.getElementById('free_hook_id').value;
    const cta = document.getElementById('free_cta').value;
    
    let editedContent = contentArea.value;
    
    // Apply pause insertions
    function addWordBasedPauses(text) {
        const sentences = text.split(/(?<=[.!?])\s+/);
        let result = [];
        
        for (let sentence of sentences) {
            if (sentence.includes('<break')) {
                result.push(sentence);
                continue;
            }
            
            const words = sentence.split(' ');
            const chunks = [];
            
            for (let i = 0; i < words.length; i += 4) {
                const chunk = words.slice(i, i + 4).join(' ');
                chunks.push(chunk);
            }
            
            result.push(chunks.join('<break time="200ms"/>'));
        }
        
        return result.join('<break time="300ms"/>');
    }
    
    editedContent = editedContent.replace(/,(?!\s*<break)/g, ',<break time="200ms"/>');
    editedContent = editedContent.replace(/\band\b(?!\s*<break)/gi, 'and<break time="200ms"/>');
    editedContent = editedContent.replace(/\bor\b(?!\s*<break)/gi, 'or<break time="200ms"/>');
    editedContent = editedContent.replace(/\.(?!\s*<break)/g, '.<break time="300ms"/>');
    editedContent = addWordBasedPauses(editedContent);
    editedContent = editedContent.replace(/(<break time="\d+ms"\/>\s*)+/g, '$1');
    
    btn.disabled = true;
    btn.innerText = "⏳ Creating Scenes...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating scenes from your free format content...";
    
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
    formData.append('target_lang', language);
    formData.append('social_type', socialType);
    
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

// Shared progress update function
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
        
        // Reset buttons in both tabs
        document.getElementById('createScenesBtn').disabled = false;
        document.getElementById('createScenesBtn').innerText = "🎬 Step 2: Create Scenes from Content";
        document.getElementById('freeCreateScenesBtn').disabled = false;
        document.getElementById('freeCreateScenesBtn').innerText = "🎬 Step 2: Create Scenes from Content";
    }
}

// Initialize on load
window.onload = function() {
    document.getElementById('editable_prompt').value = defaultDBPrompt;
    updateDBLanguageBadge();
    updateDBPrompt();
};
</script>
<?
ob_end_flush();
?>
</body>
</html>