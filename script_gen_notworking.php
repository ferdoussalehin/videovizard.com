<?php

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
    $result = callChatGPT_inam($prompt);
    
    // Check if $result is defined and has the expected structure
    if (isset($result) && is_array($result) && isset($result['success'])) {
        if ($result['success']) {
            $content = trim($result['response']);
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
// Fetch user's niches
$user_niches = [];
$niches_query = mysqli_query($conn, "SELECT * FROM hdb_user_niches WHERE admin_id = '$admin_id' ORDER BY niche_name ASC");
while ($niche_row = mysqli_fetch_assoc($niches_query)) {
    $user_niches[] = $niche_row;
}
$has_user_niches = !empty($user_niches);

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
    
    // DEBUG: Log the first title structure to see CTAs
    if (count($titles) > 0) {
        error_log("FIRST TITLE STRUCTURE: " . print_r($titles[0], true));
    }
    
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
            error_log("Title item structure: " . print_r($item, true));
            
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
                    error_log("✅ Title inserted with ID: $title_id, topic_id: " . ($topic_id === 'NULL' ? 'NULL' : $topic_id));
                    
                    // Insert CTAs
                    $ctas = $item['ctas'] ?? [];
                    error_log("CTAs for title $title_id: " . print_r($ctas, true));
                    
                    $cta_types = ['engagement', 'conversion', 'retention'];
                    $cta_insert_count = 0;
                    
                    foreach ($cta_types as $type) {
                        if (!empty($ctas[$type])) {
                            $cta_text = mysqli_real_escape_string($conn, trim($ctas[$type]));
                            
                            $insert_cta = "INSERT INTO hdb_title_ctas (title_id, cta_text, cta_type, created_date) 
                                          VALUES ('$title_id', '$cta_text', '$type', NOW())";
                            
                            error_log("Insert CTA for $type: $insert_cta");
                            
                            if (mysqli_query($conn, $insert_cta)) {
                                $cta_insert_count++;
                                error_log("✅ CTA inserted for $type");
                            } else {
                                error_log("❌ CTA insert failed for $type: " . mysqli_error($conn));
                            }
                        } else {
                            error_log("⚠️ No CTA text for $type in title: $title");
                        }
                    }
                    
                    error_log("Inserted $cta_insert_count CTAs for title ID $title_id");
                    $success_count++;
                } else {
                    error_log("❌ Title insert failed: " . mysqli_error($conn));
                }
            } else {
                error_log("⚠️ Title already exists, skipping: $title");
                $duplicate_count++;
            }
        }
        
        mysqli_commit($conn);
        error_log("✅ Transaction committed. Success: $success_count, Duplicates: $duplicate_count");
        
        echo json_encode([
            'success' => true,
            'saved_count' => $success_count,
            'duplicate_count' => $duplicate_count,
            'topic_id_used' => ($topic_id === 'NULL' ? 'NULL' : $topic_id),
            'message' => "Saved $success_count titles successfully"
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("❌ Exception: " . $e->getMessage());
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
        
        // Get inputs with proper defaults
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $combined_title = isset($_POST['combined_title']) ? trim($_POST['combined_title']) : $title;
        $target_lang = isset($_POST['target_lang']) ? mysqli_real_escape_string($conn, $_POST['target_lang']) : 'en';
        $reel_type = isset($_POST['reel_type']) ? mysqli_real_escape_string($conn, $_POST['reel_type']) : 'standard';
        
        // IMPORTANT: Get topic from input (this is the "what is in your mind" input box)
        // This should NOT be 'custom' - it should be the actual topic like 'stress relief'
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
        
        error_log("Topic key from input: $topic_key"); // Should be 'stress relief', not 'custom'
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
        $hashtags_array = ['#videovizard']; // Always include brand
        $keywords_array = ['videovizard'];
        
        // Add topic-based hashtags (using the actual topic_key)
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
        
        foreach ($scenes as $scene) {
            // Get scene data with defaults
            $text_contents = isset($scene['text']) ? trim($scene['text']) : '';
            $prompt = isset($scene['prompt']) ? trim($scene['prompt']) : '';
            $scene_hashtags = isset($scene['hashtags']) ? trim($scene['hashtags']) : '';
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
					'$scene_hashtags_esc',  -- Now stored as comma-separated
					'$scene_voice_id_esc',
					$scene_voice_rate
				)";
    
            
            error_log("Inserting scene $seq_no: actor=$actor, voice=$scene_voice_id");
            
            if (mysqli_query($conn, $insert_scene_sql)) {
                $success_count++;
                error_log("Scene $seq_no inserted successfully");
            } else {
                error_log("Scene $seq_no insert failed: " . mysqli_error($conn));
            }
        }
        
        error_log("Successfully inserted $success_count of " . count($scenes) . " scenes");
        
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
    
    $scene_id = (int)$_POST['scene_id'];
    $podcast_id = (int)$_POST['podcast_id'];
    $seq_no = (int)$_POST['seq_no'];
    $lang_code = mysqli_real_escape_string($conn, $_POST['lang_code']);
    $voice_id = mysqli_real_escape_string($conn, $_POST['voice_id']);
    $rate = mysqli_real_escape_string($conn, $_POST['rate']);
    $text = $_POST['text'];
    
    // Generate filename: podcast_id_lang_code_seq_no.mp3
    $filename = $podcast_id . '_' . $lang_code . '_' . str_pad($seq_no, 3, '0', STR_PAD_LEFT) . '.mp3';
    $audio_dir = __DIR__ . '/podcast_audios/';
    if (!is_dir($audio_dir)) {
        mkdir($audio_dir, 0777, true);
    }
    
    $filepath = $audio_dir . $filename;
    
    // Call your voice generation API here
    // This is a placeholder - replace with actual API call
    $api_result = generateVoice($text, $voice_id, $rate, $filepath);
    
    if ($api_result['success']) {
        // Update scene with audio file and voice settings
        $update_sql = "UPDATE hdb_podcast_stories SET 
                      audio_file = '$filename',
                      voice_id = '$voice_id',
                      voice_rate = '$rate'
                      WHERE id = $scene_id";
        
        if (mysqli_query($conn, $update_sql)) {
            echo json_encode([
                'success' => true,
                'filename' => $filename,
                'file_url' => 'podcast_audios/' . $filename
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Database update failed: ' . mysqli_error($conn)
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => $api_result['error'] ?? 'Audio generation failed'
        ]);
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
    <div class="card">
        <div class="card-header">
            <h1>🎬 Enhanced Script Generator</h1>
            <p>Create scripts, generate audio, and assign images in one streamlined workflow</p>
        </div>
		<div class="card-body">

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

			<!-- Voice Selection Row (Global for all tabs) -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-body">
                    <h3 style="margin-bottom: 16px; font-size: 16px;">🎙️ Voice Settings (Global)</h3>
                    
                    <div class="form-group">
                        <label>🌐 Language</label>
                        <select id="global_lang_select" onchange="loadVoicesForLanguage(this.value); saveVoiceSettings();">
                            <?php
                            $lang_query = mysqli_query($conn, "SELECT language_code, language, flag_emoji FROM hdb_languages WHERE status = 'active' ORDER BY sort_order");
                            while ($lang = mysqli_fetch_assoc($lang_query)) {
                                $selected = ($lang['language_code'] == 'en') ? 'selected' : '';
                                echo "<option value='{$lang['language_code']}' $selected>{$lang['flag_emoji']} {$lang['language']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="voice-row">
                        <div class="voice-item">
                            <label>🎤 Host Voice</label>
                            <select id="hostVoicePicker" class="voice-select" onchange="saveVoiceSettings()">
                                <option value="">-- Select Host Voice --</option>
                            </select>
                            <button class="btn-sample" onclick="playVoiceSample('host')">
                                <span>▶️</span> Sample
                            </button>
                        </div>

                        <div class="voice-item" id="guestVoiceContainer" style="display: none;">
                            <label>👤 Guest Voice</label>
                            <select id="guestVoicePicker" class="voice-select" onchange="saveVoiceSettings()">
                                <option value="">-- Select Guest Voice --</option>
                            </select>
                            <button class="btn-sample" onclick="playVoiceSample('guest')">
                                <span>▶️</span> Sample
                            </button>
                        </div>
                        
                        <div class="voice-item">
                            <label>⚡ Speed/Rate</label>
                            <select id="ratePicker" class="voice-select" onchange="saveVoiceSettings()">
                                <option value="0.75">Very Slow (0.75x)</option>
                                <option value="0.85">Calm (0.85x)</option>
                                <option value="1.0" selected>Normal (1.0x)</option>
                                <option value="1.15">Podcast (1.15x)</option>
                                <option value="1.25">Fast (1.25x)</option>
                                <option value="1.5">Very Fast (1.5x)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        <!-- My Idea Tab -->
<!-- My Idea Tab -->
<div id="my-idea-tab" class="tab-content">
    <div class="mode-title-box ai-mode">
        <h2>
            <span>💡</span> My Idea
            <span class="mode-badge">AI-Powered Creation</span>
        </h2>
    </div>
    
    <!-- Reel Type -->
    <div class="form-group">
        <label>🎬 Reel Type</label>
        <select id="idea_reel_type" onchange="checkPodcastType('idea')">
            <option value="standard">Standard</option>
            <option value="broll">B-Roll</option>
            <option value="podcast">Podcast</option>
        </select>
    </div>
    
    <!-- Video Duration -->
    <div class="form-group">
        <label>⏱️ Reel Duration</label>
        <?php if ($is_free_trial): ?>
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
            <select id="idea_duration" onchange="updateIdeaWordCount()">
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
                Approx. <span id="idea_word_count">75</span> words (2.5 words/second)
            </small>
        <?php endif; ?>
    </div>
    
    <!-- Podcast Voice Info -->
    <div id="idea_podcast_info" class="podcast-voice-info" style="display: none;">
        <span class="icon">🎙️</span>
        <span>Podcast format: Lines starting with <strong>Host:</strong> and <strong>Guest:</strong> will be automatically detected</span>
    </div>
    
    <!-- Niche Selection (only if user has niches) -->
    <!-- Niche Selection (only if user has niches) -->
	<?php if ($has_user_niches): ?>
	<div class="form-group">
		<label>📋 Your Niche/Profession</label>
		<select id="niche_select" onchange="loadTopicsByNiche(this.value)">
			<option value="">-- Select a niche --</option>
			<?php 
			$first_niche = true;
			foreach ($user_niches as $niche): 
				$selected = $first_niche ? 'selected' : '';
				$first_niche = false;
			?>
				<option value="<?php echo $niche['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($niche['niche_name']); ?></option>
			<?php endforeach; ?>
			<option value="__new__">➕ Add New Niche</option>
		</select>
	</div>
	<?php endif; ?>
    
    <!-- New Niche Input (hidden by default) -->
    <div id="new_niche_container" style="display: none;" class="form-group">
        <label>➕ New Niche/Profession</label>
        <input type="text" id="new_niche_input" placeholder="e.g., Fitness, Mental Health, Business..." value="">
    </div>
    
    <!-- Topic with AI button -->
    <!-- Topic with AI button -->
	<div class="form-group">
    <div class="field-header">
			<label>📌 Topic of your video?</label>
			<button class="btn-sample" type="button" onclick="openTopicModal()" style="background: #8b5cf6;">
				<span>🤖</span>AI Topic Ideas
			</button>
		</div>
		
		<?php if ($has_user_niches): ?>
			<select id="idea_topic_select" class="voice-select" onchange="handleTopicSelection(this)">
				<option value="">-- Select a topic --</option>
				<?php 
				$first_topic = true;
				foreach ($topics_by_niche as $niche_id => $niche_data): 
				?>
					<optgroup label="<?php echo htmlspecialchars($niche_data['niche_name']); ?>">
						<?php foreach ($niche_data['topics'] as $topic): 
							// Set selected for the first topic
							$selected = $first_topic ? 'selected' : '';
							$first_topic = false;
						?>
							<option value="<?php echo htmlspecialchars($topic); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($topic); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
				<option value="__manual__">➕ Enter custom topic...</option>
			</select>
			<input type="text" id="idea_topic_input" placeholder="Enter custom topic..." style="display: none; margin-top: 10px;">
		<?php else: ?>
			<input type="text" id="idea_topic_input" placeholder="e.g., Stress Relief Techniques, Morning Motivation..." value="Stress Relief">
			<input type="hidden" id="idea_topic_select" value="">
		<?php endif; ?>
	</div>
    
		<!-- Title with AI button -->
		<div class="form-group">
			<div class="field-header">
				<label>📌 Title of your video</label>
				<button class="btn-sample" type="button" onclick="openTitleModal()" style="background: #8b5cf6;">
					<span>🤖</span>AI Title Ideas
				</button>
			</div>
			<select id="idea_title_select" class="voice-select" onchange="handleTitleSelection(this)" style="margin-bottom: 5px;">
				<option value="">-- Select a title --</option>
				<option value="__new__">➕ Enter custom title...</option>
			</select>
			<input type="text" id="idea_title_input" placeholder="Enter custom title..." style="display: none; margin-top: 5px;" value="5 Ways to Reduce Stress">
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
        <button class="btn btn-success" id="ideaCreateScenesBtn" onclick="createIdeaScenes()" style="display:none;">🎬 Step 2: Create All</button>
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
                
                <!-- Reel Type -->
                <div class="form-group">
                    <label>🎬 Reel Type</label>
                    <select id="content_reel_type" onchange="checkPodcastType('content')">
                        <option value="multi-scene">📱 Dynamic Reel (Auto-cut into scenes)</option>
                        <option value="single-scene">📺 Full Video (Single scene)</option>
                        <option value="podcast">🎙️ Podcast (Host & Guest)</option>
                    </select>
                </div>
                
				
				 <!-- Video Duration -->
				<div class="form-group">
					<label>⏱️ Video Duration</label>
					<?php if ($is_free_trial): ?>
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
						<select id="content_duration" onchange="updateContentWordCount()">
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
                        <button class="btn btn-success" id="contentCreateScenesBtn" onclick="createContentScenes()" style="width:100%;">🎬 Step 2: Build Video Scenes</button>
                    </div>
                </div>
                
                <!-- Step 1 Button -->
                <div class="button-group">
                    <button class="btn btn-primary" id="contentProcessBtn" onclick="processContent()" style="width:100%;">📝 Step 1: Process Content</button>
                </div>
            </div>
            
            <!-- Database Tab -->
            <div id="database-tab" class="tab-content hidden">
                <div class="mode-title-box database-mode">
                    <h2>
                        <span>📚</span> From Database
                        <span class="mode-badge">Stored Content Library</span>
                    </h2>
                </div>
                
                <!-- Reel Type -->
                <div class="form-group">
                    <label>🎬 Reel Type</label>
                    <select id="db_reel_type" onchange="checkPodcastType('db')">
                        <option value="standard">Standard</option>
                        <option value="broll">B-Roll</option>
                        <option value="podcast">Podcast</option>
                    </select>
                </div>
                
				
				<!-- Video Duration -->
				<div class="form-group">
					<label>⏱️ Video Duration</label>
					<?php if ($is_free_trial): ?>
						<div style="display: flex; align-items: center; gap: 10px;">
							<select id="db_duration" disabled style="background: #f1f5f9; opacity: 0.7;">
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
						<select id="db_duration" onchange="updateDbWordCount()">
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
							Approx. <span id="db_word_count">75</span> words (2.5 words/second)
						</small>
					<?php endif; ?>
				</div>
				
                <!-- Podcast Voice Info -->
                <div id="db_podcast_info" class="podcast-voice-info" style="display: none;">
                    <span class="icon">🎙️</span>
                    <span>Podcast format: Script will be formatted with Host and Guest voices</span>
                </div>
                
                <!-- Category -->
                <div class="form-group">
                    <label>📁 Category</label>
                    <select id="cat_select" onchange="loadTopics(this.value)">
                        <option value="">-- Select Category --</option>
                        <?php
                        $cats = mysqli_query($conn, "SELECT DISTINCT category_key FROM hdb_social_media WHERE client_id = '$client_id' AND (sm_status = '' OR sm_status IS NULL)");
                        while ($c = mysqli_fetch_assoc($cats)) {
                            echo "<option value='".htmlspecialchars($c['category_key'])."'>".htmlspecialchars($c['category_key'])."</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <!-- Topic -->
                <div class="form-group">
                    <label>📌 Topic</label>
                    <select id="topic_select" onchange="loadTitles(this.value)">
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
                    <button class="btn btn-success" id="createScenesBtn" onclick="createDbScenes()" style="display: none;">🎬 Step 2: Build Video Scenes</button>
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
            <div class="log-entry">[System] Ready to start...</div>
        </div>
    </div>
    
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
<button class="btn-small" onclick="testAudioGeneration()" style="background: #f59e0b; color: white; margin-left: 10px;">
    🎤 Test Audio
</button>

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

<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px; padding: 10px; background: #f1f5f9; border-radius: 8px;">
    <span style="color: var(--dark-blue); font-size: 13px;">
        <span id="logCount">0</span> log entries • <span id="audioStatus">Waiting...</span>
    </span>
    <div>
        <button class="btn-small" onclick="clearLogs()" style="background: #e2e8f0; margin-right: 5px;">🗑️ Clear Logs</button>
        <button class="btn-small" onclick="copyLogs()" style="background: #e2e8f0;">📋 Copy Logs</button>
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
    const count = document.getElementById('realtime_log').children.length;
    document.getElementById('logCount').textContent = count;
}

// Update log count every second
setInterval(updateLogCount, 1000);
</script>
<!-- Debug Panel (expandable) -->
<div class="card" style="margin-top: 20px;">
    <div class="card-header" onclick="toggleDebugPanel()" style="cursor: pointer;">
        <h3 style="display: flex; justify-content: space-between; align-items: center;">
            <span>🐞 Debug Information</span>
            <span id="debugToggleIcon">▼</span>
        </h3>
    </div>
    <div id="debugPanel" class="card-body" style="display: none;">
        <div class="form-group">
            <label>📤 Full Prompt Sent to AI:</label>
            <textarea id="debugPrompt" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:12px; background:#1e293b; color:#e2e8f0;" readonly></textarea>
        </div>
        <div class="form-group">
            <label>📥 Full AI Response:</label>
            <textarea id="debugResponse" rows="10" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:12px; background:#1e293b; color:#e2e8f0;" readonly></textarea>
        </div>
        <div class="form-group">
            <label>📊 Parsed Scenes Data:</label>
            <textarea id="debugScenes" rows="6" style="width:100%; padding:14px; border:2px solid var(--border); border-radius:12px; font-family:monospace; font-size:12px; background:#1e293b; color:#e2e8f0;" readonly></textarea>
        </div>
    </div>
</div>
<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
    <span style="color: var(--muted); font-size: 12px;">Debug logs are preserved until you clear them</span>
    <button class="btn-small" onclick="clearLogs()" style="background: #f1f5f9;">🗑️ Clear Logs</button>
</div>

<script>
function clearLogs() {
    document.getElementById('realtime_log').innerHTML = '<div class="log-entry">[System] Logs cleared</div>';
    document.getElementById('debugPrompt').value = '';
    document.getElementById('debugResponse').value = '';
    document.getElementById('debugScenes').value = '';
    L('Logs cleared', 'info');
}
</script>
<style>
#debugPanel textarea {
    font-family: 'Courier New', monospace;
    line-height: 1.4;
}
#debugPanel label {
    color: var(--accent);
    font-weight: 600;
}
</style>

<script>
function toggleDebugPanel() {
    const panel = document.getElementById('debugPanel');
    const icon = document.getElementById('debugToggleIcon');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        icon.textContent = '▲';
    } else {
        panel.style.display = 'none';
        icon.textContent = '▼';
    }
}
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

For each scene, you MUST provide THREE things separated by [SCENE] markers:

[SCENE]
TEXT: [The spoken text for this scene - one sentence only]
PROMPT: [Detailed visual description for AI image generation - 30-50 words]
HASHTAGS: [space-separated keyword tags for image library search]

EXAMPLE:
[SCENE]
TEXT: Do you ever feel overwhelmed by stress?<break time="250ms"/>
PROMPT: A young woman sits alone in a cozy living room, bathed in soft, warm afternoon light streaming through sheer curtains. Her expression shows visible stress - furrowed brows, hands clasped tightly. The room has comfortable furniture in beige and cream tones. The mood is contemplative and slightly melancholic, captured from a medium shot angle.
HASHTAGS: stressedwoman livingroom contemplation`,

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
3. EVERY scene MUST have TEXT, PROMPT, and HASHTAGS
4. TEXT must include <break time="250ms"/> at the end
5. PROMPT must be detailed (30-50 words) following the image prompt requirements
6. HASHTAGS must follow the patterns above for image matching`
};


function buildGenerationPrompt(params) {
    const {
        title,
        topic,
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
    if (!hookType) {
        const hookKeys = Object.keys(hookExamples);
        hookType = hookKeys[Math.floor(Math.random() * hookKeys.length)];
    }
    
    const selectedHook = hookExamples[hookType] || hookExamples['question'];
    
    // Scene splitting rules (vary by reel type)
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
    let prompt = `You are an expert content writer and visual storyteller. Create a ${duration}-second ${reelType} video script.

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

    return prompt;
}
// ========== INITIALIZATION ==========
// ========== INITIALIZATION ==========
document.addEventListener('DOMContentLoaded', function() {
    // Load voices and settings
    loadVoicesForLanguage('en');
    loadVoiceSettings();
    initTabs();
    
    // Add trash icons to dropdowns after a short delay
    setTimeout(addTrashIconsToDropdowns, 1000);
    
    // Add clear button for logs if it doesn't exist
    if (!document.getElementById('manualClearBtn')) {
        const logContainer = document.getElementById('realtime_log');
        if (logContainer && logContainer.parentNode) {
            const clearDiv = document.createElement('div');
            clearDiv.style.marginTop = '10px';
            clearDiv.innerHTML = '<button onclick="clearLogs()" style="padding:5px 10px;">🗑️ Clear Logs Manually</button>';
            logContainer.parentNode.appendChild(clearDiv);
        }
    }
    
    console.log('✅ DOM fully loaded and initialized');
});

function initTabs() {
    // Ensure only my-idea tab is visible initially
    switchTab('my-idea');
}

function switchTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    document.getElementById(`${tab}-tab`).classList.remove('hidden');
    
    // Update voice containers based on reel type in active tab
    if (tab === 'my-idea') {
        checkPodcastType('idea');
        setTimeout(addTrashIconsToDropdowns, 500);
    } else if (tab === 'my-content') {
        checkPodcastType('content');
    } else if (tab === 'database') {
        checkPodcastType('db');
        setTimeout(addTrashIconsToDropdowns, 500);
    }
}

// ========== VOICE FUNCTIONS ==========
function loadVoicesForLanguage(langCode) {
    const hostSelect = document.getElementById('hostVoicePicker');
    const guestSelect = document.getElementById('guestVoicePicker');
    
    // Clear existing options
    hostSelect.innerHTML = '<option value="">-- Select Host Voice --</option>';
    guestSelect.innerHTML = '<option value="">-- Select Guest Voice --</option>';
    
    // Add voices for selected language
    if (voicesByLang[langCode] && voicesByLang[langCode].length > 0) {
        voicesByLang[langCode].forEach(voice => {
            const displayName = voice.voice_description 
                ? `${voice.voice_name} - ${voice.voice_description}` 
                : voice.voice_name;
            
            const option = document.createElement('option');
            option.value = voice.voice_key;
            option.textContent = displayName;
            option.dataset.sample = voice.sample_voice || '';
            
            hostSelect.appendChild(option.cloneNode(true));
            guestSelect.appendChild(option);
        });
    }
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
function L(message, type = 'info') {
    const log = document.getElementById('realtime_log');
    const ts = new Date().toLocaleTimeString();
    const entry = document.createElement('div');
    entry.className = `log-entry ${type}`;
    entry.innerHTML = `[${ts}] ${message}`;
    log.appendChild(entry);
    log.scrollTop = log.scrollHeight;
    /*
    // Also send to server for PHP error log
    fetch('log_debug.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `message=${encodeURIComponent(`[${ts}] ${message}`)}`
    }).catch(e => console.error('Logging error:', e)); */
}


async function processIdeaContent() {
    const btn = document.getElementById('ideaProcessBtn');
    const contentArea = document.getElementById('idea_processed_content');
    const contentContainer = document.getElementById('idea_processed_container');
    
    // Add null checks for all elements
    if (!btn || !contentArea || !contentContainer) {
        console.error('Required elements not found');
        alert('Page elements not loaded properly. Please refresh.');
        return;
    }
    
    // VALIDATE VOICE SELECTION BEFORE STEP 1
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
    
    // Get duration with null check
    let duration = '30';
    if (!isFreeTrial) {
        const durationElement = document.getElementById('idea_duration');
        if (durationElement) {
            duration = durationElement.value;
        }
    }
    
    // Get topic - THIS IS THE KEY CHANGE
    // In your current HTML, topic comes from either select or input
    const topicSelect = document.getElementById('idea_topic_select');
    const topicInput = document.getElementById('idea_topic_input');
    
    let topic = '';
    if (topicSelect && topicSelect.value && topicSelect.value !== '__manual__') {
        topic = topicSelect.value;
    } else if (topicInput) {
        topic = topicInput.value.trim();
    }
    
    // Get title
  //  const titleInput = document.getElementById('idea_title');
    //const titleInput = document.getElementById('idea_title_input');
	//const title = titleInput.value.trim();
	const title = getCurrentTitle();
	
	
	
    // Get audience
    const audienceSelect = document.getElementById('idea_audience');
    
    // Get CTA
    const ctaInput = document.getElementById('idea_cta');
    
    // Get language
    const langSelect = document.getElementById('global_lang_select');
    
    // Get hook type (optional)
    const hookTypeSelect = document.getElementById('idea_hook_type');
    
    // Validate required fields
    if (!titleInput || !audienceSelect || !ctaInput || !langSelect) {
        console.error('Required form fields not found');
        alert('Form fields not loaded properly. Please refresh.');
        return;
    }
    
    const title = titleInput.value.trim();
    const audience = audienceSelect.value;
    const cta = ctaInput.value.trim();
    const langName = langSelect.options[langSelect.selectedIndex]?.text || 'English';
    const hookType = hookTypeSelect?.value || 'question';
    
    if (!topic) {
        alert('Please enter or select a topic');
        return;
    }
    
    if (!title) {
        alert('Please enter a title');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = 'Processing...';
    contentArea.value = "Generating content...";
    
    if (contentContainer) contentContainer.style.display = 'block';
    
    const progressContainer = document.getElementById('progress_container');
    if (progressContainer) progressContainer.style.display = 'block';
    
    L('Starting content generation...', 'info');
    L(`Topic: ${topic}`, 'info');
    L(`Title: ${title}`, 'info');
    L(`Reel Type: ${reelType}`, 'info');
    L(`Duration: ${duration}s`, 'info');
    
    // Use the centralized prompt builder with hook type
    const prompt = buildGenerationPrompt({
        title: title,
        topic: topic,
        audience: audience,
        cta: cta,
        langName: langName,
        reelType: reelType,
        duration: parseInt(duration),
        hookType: hookType,
        source: 'idea'
    });
    
    // Show FULL prompt in debug panel
    const debugPrompt = document.getElementById('debugPrompt');
    if (debugPrompt) debugPrompt.value = prompt;
    
    L('Prompt sent to AI (first 200 chars): ' + prompt.substring(0, 200) + '...', 'info');
    L(`Using hook type: ${hookType}`, 'info');
    L(`Prompt length: ${prompt.length} characters`, 'info');
    
    // Add timeout to detect hanging requests
    const controller = new AbortController();
    const timeoutId = setTimeout(() => {
        controller.abort();
        L('❌ Request timed out after 30 seconds', 'error');
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Content";
    }, 30000); // 30 second timeout
    
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
        
        clearTimeout(timeoutId); // Clear timeout if request completes
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        L('✅ Request sent, waiting for response...', 'info');
        
        const responseText = await response.text();
        
        // Show FULL response in debug panel
        const debugResponse = document.getElementById('debugResponse');
        if (debugResponse) debugResponse.value = responseText;
        
        L(`Response received: ${responseText.length} characters`, 'info');
        L(`Response preview: ${responseText.substring(0, 200)}...`, 'info');
        
        let data;
        try {
            data = JSON.parse(responseText);
            L('✅ Response parsed as JSON successfully', 'success');
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            L('❌ Server returned non-JSON response', 'error');
            
            // Try to extract error message from HTML
            if (responseText.includes('Fatal error') || responseText.includes('Warning')) {
                const errorMatch = responseText.match(/<b>(?:Fatal error|Warning)<\/b>:\s+([^<]+)/i);
                if (errorMatch) {
                    L(`PHP Error: ${errorMatch[1]}`, 'error');
                }
            }
            
            throw new Error('Invalid JSON response from server');
        }
        
        if (data.success) {
            // Parse the [SCENE] format
            let processedContent = data.content;
            
            // Log the full response for debugging
            const sceneCount = (processedContent.match(/\[SCENE\]/g) || []).length;
            L(`✅ Received response with ${sceneCount} scenes`, 'success');
            
            // Parse all scenes to extract data
            const scenes = parseScenesFromContent(processedContent);
            
            // Show parsed scenes in debug panel
            const debugScenes = document.getElementById('debugScenes');
            if (debugScenes) debugScenes.value = JSON.stringify(scenes, null, 2);
            
            if (scenes.length === 0) {
                L('⚠️ No scenes could be parsed from response', 'warning');
                L('Raw content: ' + processedContent.substring(0, 200), 'warning');
            }
            
            // Create a clean display version (just TEXT lines with pause tags)
            let displayContent = '';
            scenes.forEach(scene => {
                // Add the text with pause tag if not already present
                let textLine = scene.text;
                if (!textLine.includes('<break')) {
                    textLine += '<break time="250ms"/>';
                }
                displayContent += textLine + '\n';
            });
            
            // Show the clean version in the textarea
            contentArea.value = displayContent;
            
            // Store the FULL parsed scenes in a data attribute
            contentArea.dataset.scenes = JSON.stringify(scenes);
            // Also store the original full content as backup
            contentArea.dataset.fullContent = processedContent;
            
            L(`✅ Parsed ${scenes.length} scenes with detailed prompts and hashtags`, 'success');
            
            // Log first scene details for verification
            if (scenes.length > 0) {
                L(`Sample Scene 1:`, 'info');
                L(`  TEXT: ${scenes[0].text.substring(0, 50)}...`, 'info');
                L(`  PROMPT: ${scenes[0].prompt ? scenes[0].prompt.substring(0, 50) + '...' : 'MISSING'}`, 'info');
                L(`  HASHTAGS: ${scenes[0].hashtags || 'MISSING'}`, 'info');
            }
            
            const createScenesBtn = document.getElementById('ideaCreateScenesBtn');
            if (createScenesBtn) createScenesBtn.style.display = 'block';
            
            showStatus(`Content generated with ${sceneCount} detailed scenes!`, 'success');
        } else {
            throw new Error(data.message || 'Generation failed'); 
        }
    } catch (err) {
        if (err.name === 'AbortError') {
            L('❌ Request timed out - server not responding', 'error');
        } else {
            L(`❌ Error: ${err.message}`, 'error');
        }
        console.error('Full error:', err);
        showStatus('Error: ' + err.message, 'error');
        alert('Error: ' + err.message);
    } finally {
        clearTimeout(timeoutId);
        btn.disabled = false;
        btn.innerHTML = "📝 Step 1: Generate Content";
    }
}	

// Add this helper function near the top of your JavaScript
function getCurrentTitle() {
    const select = document.getElementById('idea_title_select');
    const input = document.getElementById('idea_title_input');
    
    if (select && select.value && select.value !== '__new__') {
        return select.value;
    }
    return input ? input.value : '';
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
        const hashtagsMatch = block.match(/HASHTAGS:\s*(.*?)$/s);
        if (hashtagsMatch) {
            scene.hashtags = hashtagsMatch[1].trim();
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
function loadTopics(cat) {
    if (!cat) {
        document.getElementById('topic_select').innerHTML = '<option value="">-- Select Topic --</option>';
        return;
    }
    
    fetch('', { 
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
        body: `get_topics=1&category=${encodeURIComponent(cat)}` 
    })
    .then(r => r.text())
    .then(html => { 
        document.getElementById('topic_select').innerHTML = html; 
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
    .then(html => { 
        document.getElementById('sm_id_select').innerHTML = html; 
    });
}

function updatePrompt() {
    // Not implemented in this version
}

// ========== SCENE CREATION FUNCTIONS ==========


async function createContentScenes() {
    const content = document.getElementById('content_processed_content').value;
    const title = document.getElementById('content_title').value;
    const reelType = document.getElementById('content_reel_type').value;
    const langCode = document.getElementById('global_lang_select').value;
    
    if (!content) {
        alert('Content is empty');
        return;
    }
    
    await createAllScenes(content, title, reelType, langCode, 'content');
}

async function createDbScenes() {
    const content = document.getElementById('generated_content').value;
    const title = document.getElementById('sm_id_select').options[document.getElementById('sm_id_select').selectedIndex]?.text || 'Database Content';
    const reelType = document.getElementById('db_reel_type').value;
    const langCode = document.getElementById('global_lang_select').value;
    
    if (!content) {
        alert('Content is empty');
        return;
    }
    
    await createAllScenes(content, title, reelType, langCode, 'db');
}

async function createAllScenes(scenes, title, reelType, langCode, source) {
    L('🎬 ===== STEP 1: CREATING SCENES =====', 'info');
    L(`🔍 DEBUG - createAllScenes STARTED at ${new Date().toLocaleTimeString()}`, 'info');
    
    // Step 1: Create scenes in database
    L('🔍 DEBUG - About to call createScenesInDB', 'info');
    const podcastId = await createScenesInDB(scenes, title, reelType, langCode, source);
    
    if (!podcastId) {
        L('❌ Failed to create scenes - aborting', 'error');
        L('🔍 DEBUG - createScenesInDB returned null/false', 'error');
        alert('Failed to create scenes');
        return;
    }
    
    currentPodcastId = podcastId;
    L(`✅ Podcast created with ID: ${podcastId}`, 'success');
    L(`🔍 DEBUG - Podcast ID: ${podcastId}`, 'info');
    
    // Verify scenes were created
    L('🔍 Verifying scenes were created...', 'info');
    L('🔍 DEBUG - About to call getScenes', 'info');
    const verifyScenes = await getScenes(podcastId);
    
    if (!verifyScenes || verifyScenes.length === 0) {
        L('❌ Verification failed: No scenes found in database', 'error');
        L('🔍 DEBUG - getScenes returned empty or null', 'error');
        return;
    }
    
    L(`✅ Verified: ${verifyScenes.length} scenes in database`, 'success');
    L(`🔍 DEBUG - First scene hashtags: ${verifyScenes[0]?.hashtags || 'none'}`, 'info');
    
    // Step 2: Generate audio for all scenes
    L('🎤 ===== STEP 2: GENERATING AUDIO =====', 'info');
    L('🔍 DEBUG - About to call generateAllAudio', 'info');
    await generateAllAudio(podcastId, langCode);
    L('🔍 DEBUG - generateAllAudio COMPLETED', 'info');
    
    // Step 3: Assign images to all scenes
    L('🖼️ ===== STEP 3: ASSIGNING IMAGES =====', 'info');
    L('🔍 DEBUG - About to call assignImagesToAllScenes', 'info');
    await assignImagesToAllScenes(podcastId);
    L('🔍 DEBUG - assignImagesToAllScenes COMPLETED', 'info');
    
    // Show completion
    L('🎉 ===== ALL STEPS COMPLETE =====', 'success');
    L(`✅ Podcast #${podcastId} fully processed`, 'success');
    L(`🔍 DEBUG - createAllScenes FINISHED at ${new Date().toLocaleTimeString()}`, 'info');
    
    // Hide progress
    document.getElementById('progress_container').style.display = 'none';
	//inam
	//showSuccessModal(podcastId);
}

async function assignImagesToAllScenes(podcastId) {
    L('🖼️ ===== STARTING MEDIA ASSIGNMENT =====', 'info');
    
    const scenes = await getScenes(podcastId);
    if (!scenes || scenes.length === 0) {
        L('❌ No scenes found', 'error');
        return;
    }
    
    let completed = 0;
    let total = scenes.length;
    let videosAssigned = 0;
    let imagesAssigned = 0;
    let generatedAssigned = 0;
    
    // Track the first scene's image for thumbnail
    let firstSceneImage = null;
    
    for (let i = 0; i < scenes.length; i++) {
        const scene = scenes[i];
        const sceneNumber = i + 1;
        
        L(`\n--- Scene ${sceneNumber}/${total} ---`, 'info');
        L(`ID: ${scene.id}`, 'info');
        L(`Hashtags: ${scene.hashtags || 'None'}`, 'info');
        
        if (!scene.hashtags) {
            L(`⚠️ No hashtags found, skipping`, 'warning');
            completed++;
            continue;
        }
        
        // Update progress
        document.getElementById('progress_text').innerHTML = `Scene ${sceneNumber}/${total}: Searching library...`;
        
        const results = await searchImagesByHashtags(scene.hashtags);
        let assignedImage = null;
        
        if (results && results.length > 0) {
            // Found existing media
            L(`✅ Found ${results.length} matches`, 'success');
            
            const videos = results.filter(r => r.type === 'video');
            const images = results.filter(r => r.type === 'image');
            
            L(`   Videos: ${videos.length}, Images: ${images.length}`, 'info');
            
            // Prefer videos but allow some randomness
            let selectedItem = null;
            
            if (videos.length > 0) {
                // 70% chance to pick video, 30% chance to pick image if available
                const randomValue = Math.random();
                if (randomValue < 0.7 || images.length === 0) {
                    const randomIndex = Math.floor(Math.random() * videos.length);
                    selectedItem = videos[randomIndex];
                    videosAssigned++;
                    L(`🎬 Selected video ${randomIndex + 1}/${videos.length}`, 'success');
                } else if (images.length > 0) {
                    const randomIndex = Math.floor(Math.random() * images.length);
                    selectedItem = images[randomIndex];
                    imagesAssigned++;
                    L(`🖼️ Selected image ${randomIndex + 1}/${images.length}`, 'success');
                }
            } else if (images.length > 0) {
                const randomIndex = Math.floor(Math.random() * images.length);
                selectedItem = images[randomIndex];
                imagesAssigned++;
                L(`🖼️ Selected image ${randomIndex + 1}/${images.length}`, 'success');
            }
            
            if (selectedItem) {
                assignedImage = selectedItem.filename;
                const assigned = await assignImageToScene(scene.id, assignedImage);
                if (assigned) {
                    L(`✅ Assigned to scene ${sceneNumber}`, 'success');
                } else {
                    L(`❌ Assignment failed`, 'error');
                    assignedImage = null;
                }
            }
        } else {
            // No images found - generate one
            L(`⚠️ No matches found in library`, 'warning');
            L(`🎨 Starting AI image generation...`, 'info');
            
            // Update progress
            document.getElementById('progress_text').innerHTML = `Scene ${sceneNumber}/${total}: Generating AI image...`;
            
            const generated = await generateImageForScene(scene, sceneNumber, total);
            
            if (generated && generated.success) {
                L(`✅ AI image generated: ${generated.filename}`, 'success');
                generatedAssigned++;
                assignedImage = generated.filename;
                
                const assigned = await assignImageToScene(scene.id, generated.filename);
                if (assigned) {
                    L(`✅ Generated image assigned to scene ${sceneNumber}`, 'success');
                } else {
                    L(`❌ Failed to assign generated image`, 'error');
                    assignedImage = null;
                }
            } else {
                L(`❌ Image generation failed: ${generated?.message || 'Unknown error'}`, 'error');
            }
        }
        
        // If this is the first scene and we assigned an image, save it for thumbnail
        if (sceneNumber === 1 && assignedImage) {
            firstSceneImage = assignedImage;
            L(`📸 First scene image captured for podcast thumbnail: ${assignedImage}`, 'info');
        }
        
        completed++;
        const percent = Math.round((completed / total) * 100);
        document.getElementById('progress_bar').style.width = percent + '%';
        document.getElementById('progress_text').innerHTML = 
            `Complete: ${completed}/${total} | 🎬${videosAssigned} 🖼️${imagesAssigned} 🎨${generatedAssigned}`;
        
        // Delay between scenes
        await new Promise(r => setTimeout(r, 500));
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
    L(`   🎬 Videos assigned: ${videosAssigned}`, 'info');
    L(`   🖼️ Images assigned: ${imagesAssigned}`, 'info');
    L(`   🎨 AI Generated: ${generatedAssigned}`, 'info');
    L(`   ✅ Total: ${completed}/${total} scenes`, 'success');
    L(`   📸 Thumbnail: ${firstSceneImage || 'Not set'}`, 'info');
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
    // VALIDATE VOICES BEFORE PROCEEDING
    const hostVoice = document.getElementById('hostVoicePicker').value;
    const guestVoice = document.getElementById('guestVoicePicker').value;
    const reelType = document.getElementById('idea_reel_type').value;
    
    if (!hostVoice) {
        alert('Please select a Host Voice before creating scenes');
        document.getElementById('hostVoicePicker').focus();
        return;
    }
    
    if (reelType === 'podcast' && !guestVoice) {
        alert('Please select a Guest Voice for Podcast format');
        document.getElementById('guestVoicePicker').focus();
        return;
    }
    
    const contentArea = document.getElementById('idea_processed_content');
    const title = document.getElementById('idea_title').value;
    const langCode = document.getElementById('global_lang_select').value;
    
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
        return;
    }
    
    // Log what we're sending
    L(`Sending ${scenes.length} scenes to database:`, 'info');
    if (scenes.length > 0) {
        L(`First scene - Text: ${scenes[0].text.substring(0, 30)}...`, 'info');
        L(`First scene - Prompt: ${scenes[0].prompt ? scenes[0].prompt.substring(0, 30) + '...' : 'MISSING'}`, 'info');
        L(`First scene - Hashtags: ${scenes[0].hashtags || 'MISSING'}`, 'info');
    }
    
    await createAllScenes(scenes, title, reelType, langCode, 'idea');
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
        const audioFilename = `voice-${podcastId}_${seqNoPadded}.mp3`;
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
        formData.append('filename', audioFilename); // Send custom filename
        
        L(`📡 Sending to generate_voice.php...`, 'info');
        
        try {
            const response = await fetch('generate_voice.php', { 
                method: 'POST', 
                body: formData 
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
                
                // Update database
                L(`📝 Updating scene ${scene.id} in database...`, 'info');
                
                const updateData = new FormData();
                updateData.append('ajax_action', 'update_scene_audio');
                updateData.append('scene_id', scene.id);
                updateData.append('audio_file', audioFilename); // Use our consistent filename
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
                L(`❌ API Error: ${data.message || 'Unknown'}`, 'error');
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
        const hashtagsMatch = block.match(/HASHTAGS:\s*(.*?)$/s);
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
    if (select.value === '__new__') {
        input.style.display = 'block';
        input.value = '';
        input.focus();
    } else {
        input.style.display = 'none';
        input.value = select.value;
    }
}

// Function to get the current title value (for use in other functions)
function getCurrentTitle() {
    const select = document.getElementById('idea_title_select');
    const input = document.getElementById('idea_title_input');
    
    if (select && select.value && select.value !== '__new__') {
        return select.value;
    }
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
            
            options += '<option value="__new__">➕ Enter custom title...</option>';
            
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
            titleSelect.innerHTML = '<option value="">-- Select a title --</option><option value="__new__">➕ Enter custom title...</option>';
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
            titleSelect.innerHTML = '<option value="">-- Select a title --</option><option value="__new__">➕ Enter custom title...</option>';
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
function showSuccessModal(podcastId) {
    // Just log completion, don't show modal
    L('🎉 ===== PROCESS COMPLETE =====', 'success');
    L(`✅ Podcast #${podcastId} has been fully processed:`, 'success');
    L(`   ✓ Scenes created`, 'success');
    L(`   ✓ Audio generated`, 'success');
    L(`   ✓ Images assigned`, 'success');
    L(`📊 Check the logs above for details`, 'info');
    L(`👉 You can now:`, 'info');
    L(`   - View/Edit Images: image_gen.php?podcast_id=${podcastId}`, 'info');
    L(`   - View/Edit Audio: audiogen.php?podcast_id=${podcastId}`, 'info');
    L(`   - Start another script (refresh page)`, 'info');
    
    // Hide progress but KEEP logs visible
    document.getElementById('progress_container').style.display = 'none';
    
    // Enable any buttons that were disabled
    document.querySelectorAll('.btn').forEach(btn => btn.disabled = false);
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
    const modal = document.getElementById('topicModal');
    if (modal) {
        modal.style.display = 'flex';
        document.getElementById('topicStep1').style.display = 'block';
        document.getElementById('topicStep2').style.display = 'none';
        
        // Reset niche inputs based on whether user has existing niches
        const nicheSelect = document.getElementById('topicNicheSelect');
        const nicheInput = document.getElementById('topicNicheInput');
        
        if (nicheSelect) {
            nicheSelect.value = '';
            nicheSelect.style.display = 'block';
        }
        if (nicheInput) {
            nicheInput.value = '';
            // Show input only if no niche select or if "new" is selected
            nicheInput.style.display = nicheSelect ? 'none' : 'block';
        }
        
        document.getElementById('topicLoading').style.display = 'none';
        document.getElementById('generateTopicsBtn').disabled = false;
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
    if (select.value === '__manual__') {
        input.style.display = 'block';
        input.value = '';
        input.focus();
    } else {
        input.style.display = 'none';
        input.value = select.value;
        
        // Load titles for this topic
        loadTitlesForTopic();
    }
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
    
    L(`🤖 Generating topic ideas for niche: ${nicheName}`, 'info');
    
    const prompt = `Generate 15 engaging video topics for someone in the "${nicheName}" niche. 
    Each topic should be catchy, attention-grabbing, and suitable for short-form videos.
    Make them diverse and interesting.
    Return ONLY a JSON array of strings, no other text.
    Example format: ["Topic 1", "Topic 2", "Topic 3"]`;
    
    try {
        const formData = new URLSearchParams();
        formData.append('ajax_action', 'process_free_content');
        formData.append('prompt', prompt);
        
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        // Check if response is ok
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
            
            // Filter out any empty topics
            topics = topics.filter(topic => topic && topic.trim().length > 0);
            
            if (topics.length === 0) {
                throw new Error('No topics could be extracted from the response');
            }
            
            // Store niche info for later use
            window.currentTopicNiche = {
                id: nicheId,
                name: nicheName
            };
            
            // DIRECTLY DISPLAY TOPICS WITHOUT ANY COMPLEX FUNCTIONS
            const listDiv = document.getElementById('topicList');
            if (!listDiv) {
                console.error('Topic list element not found');
                return;
            }
            
            // Clear the list
            listDiv.innerHTML = '';
            
            // Add each topic as a checkbox
            topics.forEach((topic, index) => {
                if (!topic) return;
                
                const topicId = `topic_${Date.now()}_${index}`;
                const itemDiv = document.createElement('div');
                itemDiv.className = 'topic-item';
                
                // Simple escaping
                const displayText = String(topic).replace(/[&<>"]/g, function(m) {
                    if (m === '&') return '&amp;';
                    if (m === '<') return '&lt;';
                    if (m === '>') return '&gt;';
                    if (m === '"') return '&quot;';
                    return m;
                });
                
                itemDiv.innerHTML = `
                    <input type="checkbox" id="${topicId}" value="${displayText}">
                    <label for="${topicId}">${displayText}</label>
                `;
                listDiv.appendChild(itemDiv);
            });
            
            // Update the niche display
            const nicheDisplay = document.getElementById('selectedNicheDisplay');
            if (nicheDisplay) {
                nicheDisplay.textContent = nicheName;
            }
            
            // Update selected count
            const selectedCount = document.querySelectorAll('#topicList input[type="checkbox"]:checked').length;
            const countSpan = document.getElementById('selectedTopicsCount');
            if (countSpan) {
                countSpan.textContent = `${selectedCount} selected`;
            }
            
            // Reset select all checkbox
            const selectAll = document.getElementById('selectAllTopics');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.disabled = false;
            }
            
            // SWITCH TO STEP 2
            document.getElementById('topicStep1').style.display = 'none';
            document.getElementById('topicStep2').style.display = 'block';
            
            L(`✅ Generated ${topics.length} topic ideas for ${nicheName}`, 'success');
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
        
        const topicId = `topic_${index}`;
        const itemDiv = document.createElement('div');
        itemDiv.className = 'topic-item';
        
        if (item.isDuplicate) {
            itemDiv.style.opacity = '0.7';
            itemDiv.style.background = '#f1f5f9';
        }
        
        itemDiv.innerHTML = `
            <input type="checkbox" id="${topicId}" value="${escapeHtml(item.text)}" 
                   ${item.isDuplicate ? 'disabled' : ''}>
            <label for="${topicId}" ${item.isDuplicate ? 'style="color: #94a3b8;"' : ''}>
                ${escapeHtml(item.text)}
                ${item.isDuplicate ? '<span class="duplicate-warning">(already exists in this niche)</span>' : ''}
            </label>
        `;
        listDiv.appendChild(itemDiv);
    });
    
    document.getElementById('topicStep1').style.display = 'none';
    document.getElementById('topicStep2').style.display = 'block';
    updateSelectedTopicsCount();
}

function changeNiche() {
    document.getElementById('topicStep1').style.display = 'block';
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
    const checkboxes = document.querySelectorAll('#topicList input[type="checkbox"]:checked');
    const selectedTopics = Array.from(checkboxes).map(cb => cb.value);
    
    if (selectedTopics.length === 0) {
        alert('Please select at least one topic');
        return;
    }
    
    const niche = window.currentTopicNiche;
    if (!niche || !niche.name) {
        alert('Niche information missing');
        return;
    }
    
    L(`💾 Saving ${selectedTopics.length} topics for niche "${niche.name}"...`, 'info');
    
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
        
        const data = await response.json();
        
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
    // Reload the page to show new topics in dropdown
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
        topicDisplay.value = topic;
    }
    
    // Show modal
    const modal = document.getElementById('titleModal');
    if (modal) {
        modal.style.display = 'flex';
    }
    
    const step1 = document.getElementById('titleStep1');
    const step2 = document.getElementById('titleStep2');
    const loading = document.getElementById('titleLoading');
    
    if (step1) step1.style.display = 'block';
    if (step2) step2.style.display = 'none';
    if (loading) loading.style.display = 'none';
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
   // Display titles (simplified - just titles with checkboxes)
	const listDiv = document.getElementById('titleList');
	if (listDiv) {
		listDiv.innerHTML = '';
		
		titlesArray.forEach((item, index) => {
			const titleText = typeof item === 'string' ? item : (item.title || '');
			if (!titleText) return;
			
			const titleId = `title_${Date.now()}_${index}`;
			const itemDiv = document.createElement('div');
			itemDiv.className = 'title-item';
			
			itemDiv.innerHTML = `
				<div style="display: flex; align-items: center; gap: 10px; padding: 10px;">
					<input type="checkbox" class="title-checkbox" id="${titleId}" value="${index}" style="width: 20px; height: 20px;">
					<label for="${titleId}" style="flex: 1; cursor: pointer;">${escapeHtml(titleText)}</label>
					<button class="delete-title-btn" onclick="deleteTitleFromList(this, ${index})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px;">🗑️</button>
				</div>
			`;
			
			listDiv.appendChild(itemDiv);
		});
	}
    
    listDiv.innerHTML = '';
    
    const selectedTopicDisplay = document.getElementById('selectedTopicDisplay');
    if (selectedTopicDisplay) {
        selectedTopicDisplay.textContent = topicName;
    }
    
    titles.forEach((item, index) => {
        if (!item || !item.title) return;
        
        const titleId = `title_${Date.now()}_${index}`;
        const itemDiv = document.createElement('div');
        itemDiv.className = 'title-item';
        
        // Title header with checkbox and delete button
        const headerDiv = document.createElement('div');
        headerDiv.className = 'title-header';
        headerDiv.innerHTML = `
            <input type="checkbox" class="title-checkbox" id="${titleId}" value="${index}">
            <span class="title-text">${escapeHtml(item.title)}</span>
            <button class="delete-title-btn" onclick="deleteTitleFromList(this, ${index})" title="Delete this title">🗑️</button>
        `;
        
        // CTAs container
        const ctasDiv = document.createElement('div');
        ctasDiv.className = 'ctas-container';
        ctasDiv.innerHTML = `
            <div class="cta-item">
                <strong>💬 Engagement</strong>
                <div class="cta-text">${escapeHtml(item.ctas.engagement)}</div>
            </div>
            <div class="cta-item">
                <strong>🔗 Conversion</strong>
                <div class="cta-text">${escapeHtml(item.ctas.conversion)}</div>
            </div>
            <div class="cta-item">
                <strong>⏱️ Retention</strong>
                <div class="cta-text">${escapeHtml(item.ctas.retention)}</div>
            </div>
        `;
        
        itemDiv.appendChild(headerDiv);
        itemDiv.appendChild(ctasDiv);
        listDiv.appendChild(itemDiv);
    });
    
    const titleCountEl = document.getElementById('titleCount');
    if (titleCountEl) {
        titleCountEl.textContent = titles.length;
    }
    
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
    const step1 = document.getElementById('titleStep1');
    const step2 = document.getElementById('titleStep2');
    const loading = document.getElementById('titleLoading');
    const generateBtn = document.getElementById('generateTitlesBtn');
    
    if (step1) step1.style.display = 'block';
    if (step2) step2.style.display = 'none';
    if (loading) loading.style.display = 'none';
    if (generateBtn) generateBtn.disabled = false;
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
    
    const selectedIndices = Array.from(selectedCheckboxes).map(cb => parseInt(cb.value));
    console.log('Selected indices:', selectedIndices);
    
    const selectedTitles = selectedIndices.map(index => window.currentTitleData.titles[index]);
    console.log('Selected titles to save (with CTAs):', JSON.stringify(selectedTitles, null, 2));
    
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
    console.log('NicheId:', window.currentTitleData.nicheId);
    console.log('TopicName:', window.currentTitleData.topicName);
    
    L(`💾 Saving ${selectedTitles.length} titles to database...`, 'info');
    L(`Topic ID: ${topicId || 'NOT SET'}`, 'info');
    L(`Topic Name: ${window.currentTitleData.topicName}`, 'info');
    
    const saveBtn = document.getElementById('saveTitlesBtn'); // Make sure this ID exists
    const originalText = saveBtn ? saveBtn.textContent : 'Save';
    if (saveBtn) {
        saveBtn.textContent = 'Saving...';
        saveBtn.disabled = true;
    }
    
    const formData = new FormData();
    formData.append('ajax_action', 'save_titles_with_ctas');
    formData.append('niche_id', window.currentTitleData.nicheId || '');
    formData.append('topic_id', topicId || '');
    formData.append('topic_name', window.currentTitleData.topicName);
    formData.append('titles', JSON.stringify(selectedTitles));
    
    try {
        console.log('Sending fetch request with topic_id:', topicId);
        
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
            
            // Check if CTAs were saved
            console.log('CTAs saved count:', data.saved_count * 3); // Each title has 3 CTAs
            
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
        if (saveBtn) {
            saveBtn.textContent = originalText;
            saveBtn.disabled = false;
        }
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

function closeTitleModal() {
    document.getElementById('titleModal').style.display = 'none';
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
// Add delete button to topic items in the display
function displayTopicList(topicsWithStatus, nicheName) {
    const listDiv = document.getElementById('topicList');
    listDiv.innerHTML = '';
    
    // Display the niche name
    const nicheDisplay = document.getElementById('selectedNicheDisplay');
    if (nicheDisplay) {
        nicheDisplay.textContent = nicheName || 'Selected Niche';
    }
    
    topicsWithStatus.forEach((item, index) => {
        if (!item || !item.text) return;
        
        const topicId = `topic_${Date.now()}_${index}`;
        const itemDiv = document.createElement('div');
        itemDiv.className = 'topic-item';
        
        const displayText = escapeHtml(item.text);
        
        itemDiv.innerHTML = `
            <input type="checkbox" id="${topicId}" value="${displayText}">
            <label for="${topicId}">${displayText}</label>
            <button class="delete-topic-btn" onclick="deleteTopicFromList(this, '${displayText}')" title="Delete this topic">🗑️</button>
        `;
        listDiv.appendChild(itemDiv);
    });
    
    document.getElementById('topicStep1').style.display = 'none';
    document.getElementById('topicStep2').style.display = 'block';
    updateSelectedTopicsCount();
}

function deleteTopicFromList(button, topicText) {
    if (confirm(`Are you sure you want to remove "${topicText}" from the list?`)) {
        const topicItem = button.closest('.topic-item');
        topicItem.remove();
        updateSelectedTopicsCount();
        L(`🗑️ Topic removed from list: ${topicText}`, 'info');
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
    if (!nicheId) return;
    
    // This would be an AJAX call to get topics for the niche
    // For now, we'll rely on the PHP-generated options
    console.log('Loading topics for niche:', nicheId);
}
async function generateTitleIdeas() {
    const context = window.currentTitleContext;
    
    if (!context || !context.topicName) {
        alert('Topic information missing');
        closeTitleModal();
        return;
    }
    
    // Show loading
    const loadingEl = document.getElementById('titleLoading');
    const generateBtn = document.getElementById('generateTitlesBtn');
    const step1 = document.getElementById('titleStep1');
    
    if (loadingEl) loadingEl.style.display = 'block';
    if (generateBtn) generateBtn.disabled = true;
    
    L(`🎯 Generating 50 title ideas for topic: ${context.topicName}`, 'info');
    
    // 50 hooks for title generation
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
        "Comparison: {topic} vs {topic} alternative",
        "Comparison: Why {topic} beats the rest",
        "Comparison: The difference between {topic} methods",
        "Comparison: {topic} alternatives compared",
        "Comparison: Which {topic} strategy is right for you?"
    ];
    
    // Select 10 random hooks to generate 5 titles each (total 50)
    const selectedHooks = HOOKS.sort(() => 0.5 - Math.random()).slice(0, 10);
    
    // UPDATED PROMPT - Stronger instruction to generate 50 titles
    const prompt = `Generate EXACTLY 50 catchy YouTube/TikTok video titles about "${context.topicName}" with 3 different CTAs for each title. This is CRITICAL - you MUST return EXACTLY 50 titles.

Use these hook types (generate 5 titles for EACH hook type to reach 50 total):
${selectedHooks.map((hook, i) => `${i+1}. ${hook}`).join('\n')}

For EACH title, provide 3 CTAs from these categories:
- Engagement CTA (encourage comments, likes, shares)
- Conversion CTA (encourage follows, subscribes, link clicks)
- Retention CTA (encourage watching till end)

Return ONLY a valid JSON object with EXACTLY 50 items in the titles array:
{
  "titles": [
    {
      "title": "Title 1",
      "hook_type": "Hook type used",
      "ctas": {
        "engagement": "Engagement CTA",
        "conversion": "Conversion CTA", 
        "retention": "Retention CTA"
      }
    },
    ... (48 more items)
    {
      "title": "Title 50",
      "hook_type": "Hook type used",
      "ctas": {
        "engagement": "Engagement CTA",
        "conversion": "Conversion CTA", 
        "retention": "Retention CTA"
      }
    }
  ]
}`;
    
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
        if (loadingEl) loadingEl.style.display = 'none';
        if (generateBtn) generateBtn.disabled = false;
        
        if (data.success) {
            let result;
            let titlesArray = [];
            
            try {
                result = JSON.parse(data.content);
                console.log('Raw API response:', result);
                
                // Check if result has titles array
                if (result.titles && Array.isArray(result.titles)) {
                    titlesArray = result.titles;
                    console.log('Titles from API:', titlesArray.length);
                    
                    // Log first title to verify CTAs structure
                    if (titlesArray.length > 0) {
                        console.log('First title structure:', titlesArray[0]);
                        console.log('CTAs in first title:', titlesArray[0].ctas);
                    }
                } else if (Array.isArray(result)) {
                    // If result is directly an array
                    titlesArray = result.map(item => {
                        if (typeof item === 'string') {
                            return {
                                title: item,
                                hook_type: 'mixed',
                                ctas: {
                                    engagement: 'Comment below your thoughts! 💬',
                                    conversion: 'Follow for more tips! ➡️',
                                    retention: 'Watch till the end! ⏳'
                                }
                            };
                        }
                        return item;
                    });
                }
            } catch (e) {
                console.log('Failed to parse JSON, creating from text');
                const lines = data.content.split('\n').filter(l => l.trim());
                
                for (let i = 0; i < lines.length && i < 50; i++) {
                    const line = lines[i].replace(/^\d+\.\s*/, '').trim();
                    if (line) {
                        titlesArray.push({
                            title: line,
                            hook_type: 'mixed',
                            ctas: {
                                engagement: 'Comment below your thoughts! 💬',
                                conversion: 'Follow for more tips! ➡️',
                                retention: 'Watch till the end! ⏳'
                            }
                        });
                    }
                }
            }
            
            // Ensure we have at least some titles
            if (titlesArray.length === 0) {
                throw new Error('No titles generated');
            }
            
            // Limit to 50 titles
            titlesArray = titlesArray.slice(0, 50);
            
            // Validate each title has proper CTAs structure
            titlesArray = titlesArray.map(item => {
                // Ensure item has title
                if (!item.title && typeof item === 'string') {
                    item = { title: item };
                }
                
                // Ensure CTAs object exists
                if (!item.ctas) {
                    item.ctas = {};
                }
                
                // Ensure all three CTAs exist with default fallbacks
                const defaultCTAs = {
                    engagement: 'Comment below your thoughts! 💬',
                    conversion: 'Follow for more tips! ➡️',
                    retention: 'Watch till the end! ⏳'
                };
                
                item.ctas = {
                    engagement: item.ctas.engagement || defaultCTAs.engagement,
                    conversion: item.ctas.conversion || defaultCTAs.conversion,
                    retention: item.ctas.retention || defaultCTAs.retention
                };
                
                // Ensure hook_type exists
                if (!item.hook_type) {
                    item.hook_type = 'mixed';
                }
                
                return item;
            });
            
            // Store for later use
            window.currentTitleData = {
                nicheId: context.nicheId,
                nicheName: context.nicheName,
                topicId: context.topicId,
                topicName: context.topicName,
                titles: titlesArray
            };
            
            console.log('Final titles array with CTAs:', window.currentTitleData);
            
            // Display titles (just titles with checkboxes, no CTAs shown)
            const listDiv = document.getElementById('titleList');
            if (listDiv) {
                listDiv.innerHTML = '';
                
                titlesArray.forEach((item, index) => {
                    const titleText = item.title || '';
                    if (!titleText) return;
                    
                    const titleId = `title_${Date.now()}_${index}`;
                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'title-item';
                    
                    itemDiv.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; margin: 5px 0;">
                            <input type="checkbox" class="title-checkbox" id="${titleId}" value="${index}" style="width: 20px; height: 20px;">
                            <label for="${titleId}" style="flex: 1; cursor: pointer; font-size: 14px;">${escapeHtml(titleText)}</label>
                            <button class="delete-title-btn" onclick="deleteTitleFromList(this, ${index})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; padding: 5px;">🗑️</button>
                        </div>
                    `;
                    
                    listDiv.appendChild(itemDiv);
                });
                
                document.getElementById('selectedTopicDisplay').textContent = context.topicName;
                document.getElementById('titleCount').textContent = titlesArray.length;
            }
            
            if (step1) step1.style.display = 'none';
            const step2 = document.getElementById('titleStep2');
            if (step2) step2.style.display = 'block';
            
            updateSelectedTitlesCount();
            
            L(`✅ Generated ${titlesArray.length} titles with CTAs`, 'success');
            
        } else {
            throw new Error(data.message || 'Generation failed');
        }
    } catch (err) {
        // Hide loading
        if (loadingEl) loadingEl.style.display = 'none';
        if (generateBtn) generateBtn.disabled = false;
        
        console.error('Error in generateTitleIdeas:', err);
        L(`❌ Error generating titles: ${err.message}`, 'error');
        alert('Error generating titles: ' + err.message);
    }
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
<!-- AI Topic Ideas Modal -->
<div class="modal-overlay" id="topicModal" style="display: none;">
    <div class="success-card" style="max-width: 650px;">
        <h2 style="color: var(--dark-blue); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <span>🤖</span> Generate Topic Ideas
        </h2>
        
        <!-- Step 1: Enter Niche -->
        <div id="topicStep1">
            <div class="form-group">
                <label>Select or create a niche/profession:</label>
                <?php if ($has_user_niches): ?>
                <select id="topicNicheSelect" onchange="handleTopicNicheSelection(this)" style="margin-bottom: 10px;">
                    <option value="">-- Select existing niche --</option>
                    <?php foreach ($user_niches as $niche): ?>
                        <option value="<?php echo $niche['id']; ?>" data-name="<?php echo htmlspecialchars($niche['niche_name']); ?>">
                            <?php echo htmlspecialchars($niche['niche_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="__new__">➕ Create new niche</option>
                </select>
                <?php endif; ?>
                
                <input type="text" id="topicNicheInput" placeholder="e.g., Fitness, Mental Health, Business, Parenting..." 
                       style="width: 100%; <?php echo $has_user_niches ? 'display: none;' : ''; ?>">
            </div>
            
            <!-- Loading Message -->
            <div id="topicLoading" style="display: none; text-align: center; padding: 20px; background: #f1f5f9; border-radius: 12px; margin: 15px 0;">
                <div style="font-size: 32px; margin-bottom: 10px;">⏳</div>
                <p style="color: var(--dark-blue); font-weight: 500;">Please wait, AI is creating topic ideas...</p>
                <p style="color: var(--muted); font-size: 13px;">This may take a few seconds</p>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button class="btn btn-secondary" onclick="closeTopicModal()">Cancel</button>
                <button class="btn btn-primary" id="generateTopicsBtn" onclick="generateTopicIdeas()">Generate Topics</button>
            </div>
        </div>
        
        <!-- Step 2: Select Topics -->
        <div id="topicStep2" style="display: none;">
            <p style="margin-bottom: 15px; color: var(--muted);">
                Topics for niche: <strong id="selectedNicheDisplay"></strong>
            </p>
            
            <!-- Select All Bar -->
            <div class="select-all-bar" style="background: #f1f5f9; padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">
                <input type="checkbox" id="selectAllTopics" onchange="toggleSelectAllTopics(this)" style="width: 20px; height: 20px;">
                <label for="selectAllTopics" style="flex: 1; font-weight: 600; color: var(--dark-blue); cursor: pointer;">Select All Topics</label>
                <span id="selectedTopicsCount" style="color: var(--muted); font-size: 13px;">0 selected</span>
            </div>
            
            <!-- Topics List -->
            <div id="topicList" style="max-height: 300px; overflow-y: auto; margin-bottom: 20px; border: 1px solid var(--border); border-radius: 12px; padding: 15px; background: #fafafa;">
                <!-- Topics will be inserted here -->
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap;">
                <button class="btn btn-secondary" onclick="closeTopicModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveSelectedTopics()">Add Selected Topics</button>
                <button class="btn btn-primary" onclick="generateMoreTopics()">Generate More Ideas</button>
                <button class="btn btn-info" onclick="changeNiche()" style="background: #64748b; color: white;">Change Niche</button>
            </div>
        </div>
    </div>
</div>

<!-- AI Title Ideas Modal -->
<!-- AI Title Ideas Modal -->
<div class="modal-overlay" id="titleModal" style="display: none;">
    <div class="success-card" style="max-width: 800px;">
        <h2 style="color: var(--dark-blue); margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <span>🎯</span> Generate Title Ideas
        </h2>
        
        <!-- Step 1: Confirm Topic -->
        <div id="titleStep1">
			<div class="form-group">
				<label>Selected Topic</label>
				<input type="text" id="titleSelectedTopic" readonly style="background: #f1f5f9; font-weight: 500; padding: 12px; width: 100%; border-radius: 8px; border: 1px solid var(--border);">
			</div>
			
			<!-- Enhanced Loading Message with Animation -->
			<div id="titleLoading" style="display: none; text-align: center; padding: 30px 20px; background: #f1f5f9; border-radius: 12px; margin: 15px 0;">
				<!-- Animated Spinner -->
				<div style="margin-bottom: 20px;">
					<div class="loading-spinner" style="width: 50px; height: 50px; border: 5px solid #e2e8f0; border-top: 5px solid #8b5cf6; border-radius: 50%; margin: 0 auto; animation: spin 1s linear infinite;"></div>
				</div>
				
				<p style="color: var(--dark-blue); font-weight: 600; margin-bottom: 8px; font-size: 16px;">Generating 50 Title Ideas</p>
				<p style="color: var(--muted); font-size: 14px; margin-bottom: 15px;">AI is creating engaging titles with CTAs...</p>
				
				<!-- Progress bar -->
				<div style="background: white; border-radius: 20px; height: 8px; width: 100%; margin: 15px 0; overflow: hidden;">
					<div id="loadingProgressBar" style="background: linear-gradient(90deg, #8b5cf6, #6366f1); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
				</div>
				
				<!-- Status messages that update -->
				<div id="loadingStatus" style="color: #64748b; font-size: 13px; margin-top: 10px;">
					<span id="loadingStep">Analyzing topic...</span>
					<span id="loadingDots" style="display: inline-block;">.</span>
				</div>
				
				<!-- Time estimate -->
				<div style="color: #94a3b8; font-size: 12px; margin-top: 15px; display: flex; align-items: center; justify-content: center; gap: 5px;">
					<span>⏱️</span>
					<span id="loadingTime">This may take 30-60 seconds</span>
				</div>
			</div>
			
			<div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
				<button class="btn btn-secondary" onclick="closeTitleModal()">Cancel</button>
				<button class="btn btn-primary" id="generateTitlesBtn" onclick="generateTitleIdeas()">Generate 50 Titles</button>
			</div>
		</div>
        
        <!-- Step 2: Display Titles -->
        <div id="titleStep2" style="display: none;">
            <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--dark-blue); font-weight: 600;">
                    <span id="titleCount">0</span> titles generated for <span id="selectedTopicDisplay"></span>
                </span>
            </div>
            
            <!-- Select All Bar -->
            <div class="select-all-bar" style="background: #f1f5f9; padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">
                <input type="checkbox" id="selectAllTitles" onchange="toggleSelectAllTitles(this)" style="width: 20px; height: 20px;">
                <label for="selectAllTitles" style="flex: 1; font-weight: 600; color: var(--dark-blue); cursor: pointer;">Select All Titles</label>
                <span id="selectedTitlesCount" style="color: var(--muted); font-size: 13px;">0 selected</span>
            </div>
            
            <!-- Titles List -->
            <div id="titleList" style="max-height: 400px; overflow-y: auto; margin-bottom: 20px; border: 1px solid var(--border); border-radius: 12px; padding: 15px; background: #fafafa;">
                <!-- Titles will be inserted here -->
            </div>
            
            <!-- Action Buttons -->
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap;">
                <button class="btn btn-secondary" onclick="closeTitleModal()">Cancel</button>
                <button class="btn btn-success" onclick="saveSelectedTitles()">Save Selected Titles</button>
                <button class="btn btn-primary" onclick="generateMoreTitles()">Generate More</button>
            </div>
        </div>
    </div>
</div>


</body>
</html>