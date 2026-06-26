<?php
/**
 * clone_podcast_to_languages.php
 * Creates new podcasts in multiple target languages by cloning an English podcast
 * and translating all text contents using ChatGPT
 */
session_start();

$admin_id    = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id   = $_SESSION['client_id'];

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';
if (!isset($conn) && isset($con)) $conn = $con;

$podcast_id    = $_GET['podcast_id'] ?? 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

// Get podcast_id from URL if present
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$url_lang_filter = isset($_GET['lang_filter']) ? $_GET['lang_filter'] : 'en';

// Get podcast_id from URL
$podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';


if ($url_podcast_id > 0) {
    // First update the updated_at timestamp
    $update_sql = "UPDATE hdb_podcasts SET updated_at = NOW() WHERE id = $url_podcast_id";
    mysqli_query($conn, $update_sql);
    
    // Check for image files in podcast stories (priority)
    $image_check_sql = "SELECT image_file FROM hdb_podcast_stories 
                        WHERE podcast_id = $url_podcast_id 
                        AND image_file IS NOT NULL 
                        AND image_file != '' 
                        LIMIT 1";
    $image_result = mysqli_query($conn, $image_check_sql);
    
    if ($image_result && mysqli_num_rows($image_result) > 0) {
        // Found an image - use the first one as thumbnail
        $image_row = mysqli_fetch_assoc($image_result);
        $thumbnail = $image_row['image_file'];
        
        $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";
        mysqli_query($conn, $thumbnail_sql);
    } else {
        // No image found, check for video files
        $video_check_sql = "SELECT video_file FROM hdb_podcast_stories 
                            WHERE podcast_id = $url_podcast_id 
                            AND video_file IS NOT NULL 
                            AND video_file != '' 
                            LIMIT 1";
        $video_result = mysqli_query($conn, $video_check_sql);
        
        if ($video_result && mysqli_num_rows($video_result) > 0) {
            // Found a video - use video filename as thumbnail reference
            $video_row = mysqli_fetch_assoc($video_result);
            $thumbnail = $video_row['video_file'];
            
            $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";
            mysqli_query($conn, $thumbnail_sql);
        }
        // If no media found, leave thumbnail as is (don't overwrite)
    }
}
// OpenAI Configuration
$OPENAI_API_KEY = "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";
$MODEL = 'gpt-4o-mini';

$GLOBALS['tag_placeholders'] = [];

$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

// Get podcast title and language for display
$podcast_title = '';
$podcast_lang = '';
if ($url_podcast_id > 0) {
    $title_query = mysqli_query($conn, "SELECT title, lang_code FROM hdb_podcasts WHERE id = $url_podcast_id AND client_id = '$client_id'");
    if ($title_query && mysqli_num_rows($title_query) > 0) {
        $title_row = mysqli_fetch_assoc($title_query);
        $podcast_title = $title_row['title'];
        $podcast_lang = $title_row['lang_code'];
    }
}

// Handle AJAX request for cloning — now accepts optional single target_lang
if (isset($_POST['action']) && $_POST['action'] === 'clone_podcast') {
    header('Content-Type: application/json');
    
    $source_podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $single_lang = isset($_POST['target_lang']) ? trim($_POST['target_lang']) : null;
    
    // All supported target languages
    $all_languages = [
        'ur' => 'Urdu',
        'ar' => 'Arabic', 
        'hi' => 'Hindi',
        'fr' => 'French',
        'es' => 'Spanish'
    ];

    // If a single language was requested, only process that one
    $target_languages = $single_lang && isset($all_languages[$single_lang])
        ? [$single_lang => $all_languages[$single_lang]]
        : $all_languages;
    
    if (empty($source_podcast_id)) {
        echo json_encode(['success' => false, 'error' => 'No source podcast ID provided']);
        exit;
    }
    
    // 1. Get source podcast (remove lang_code filter to allow any language as source)
    $podcast_sql = "SELECT * FROM hdb_podcasts WHERE id = $source_podcast_id";
    $podcast_result = mysqli_query($conn, $podcast_sql);
    
    if (!$podcast_result || mysqli_num_rows($podcast_result) === 0) {
        echo json_encode(['success' => false, 'error' => 'Source podcast not found']);
        exit;
    }
    
    $source_podcast = mysqli_fetch_assoc($podcast_result);
    $source_lang = $source_podcast['lang_code'];
    $podcast_title = $source_podcast['title'];
    
    // 2. Get source stories
    $stories_sql = "SELECT * FROM hdb_podcast_stories WHERE podcast_id = $source_podcast_id ORDER BY id ASC";
    $stories_result = mysqli_query($conn, $stories_sql);
    
    if (!$stories_result || mysqli_num_rows($stories_result) === 0) {
        echo json_encode(['success' => false, 'error' => 'No stories found for source podcast']);
        exit;
    }
    
    $source_stories = [];
    while ($story = mysqli_fetch_assoc($stories_result)) {
        $source_stories[] = $story;
    }
    
    $results = [];
    
    foreach ($target_languages as $target_code => $target_name) {
        // Skip if target language is the same as source
        if ($target_code == $source_lang) {
            continue;
        }
        
        $lang_result = [
            'lang' => $target_code,
            'lang_name' => $target_name,
            'status' => '',
            'podcast_id' => null,
            'stories_created' => 0,
            'texts_translated' => 0,
            'deleted_podcast' => false,
            'deleted_stories' => 0,
            'error' => null
        ];
        
        // ===== STEP 1: DELETE EXISTING =====
        $find_sql = "SELECT id FROM hdb_podcasts 
                     WHERE title = '" . mysqli_real_escape_string($conn, $podcast_title) . "' 
                     AND lang_code = '$target_code'";
        $find_result = mysqli_query($conn, $find_sql);
        
        if ($find_result && mysqli_num_rows($find_result) > 0) {
            $existing = mysqli_fetch_assoc($find_result);
            $existing_podcast_id = $existing['id'];
            
            $delete_stories_sql = "DELETE FROM hdb_podcast_stories WHERE podcast_id = $existing_podcast_id";
            if (mysqli_query($conn, $delete_stories_sql)) {
                $lang_result['deleted_stories'] = mysqli_affected_rows($conn);
            }
            
            $delete_podcast_sql = "DELETE FROM hdb_podcasts WHERE id = $existing_podcast_id";
            if (mysqli_query($conn, $delete_podcast_sql)) {
                $lang_result['deleted_podcast'] = true;
            }
        }
        
        // ===== STEP 2: CREATE NEW PODCAST (video_status = blank) =====
        $insert_fields = [];
        $insert_values = [];
        
        foreach ($source_podcast as $field => $value) {
            if ($field == 'id') continue;
            
            if ($field == 'lang_code') {
                $insert_fields[] = $field;
                $insert_values[] = "'$target_code'";
            } elseif ($field == 'video_status') {
                // FIX: Always blank video_status for new translated rows
                $insert_fields[] = $field;
                $insert_values[] = "''";
            } else {
                $escaped_value = mysqli_real_escape_string($conn, $value);
                $insert_fields[] = $field;
                $insert_values[] = "'$escaped_value'";
            }
        }
        
        $insert_sql = "INSERT INTO hdb_podcasts (" . implode(',', $insert_fields) . ") 
                       VALUES (" . implode(',', $insert_values) . ")";
        
        if (!mysqli_query($conn, $insert_sql)) {
            $lang_result['status'] = 'failed';
            $lang_result['error'] = 'Failed to create podcast: ' . mysqli_error($conn);
            $results[] = $lang_result;
            continue;
        }
        
        $new_podcast_id = mysqli_insert_id($conn);
        
        // ===== STEP 3: TRANSLATE TEXTS =====
        $texts_to_translate = [];
        foreach ($source_stories as $story) {
            if (!empty($story['text_contents'])) $texts_to_translate[] = $story['text_contents'];
            if (!empty($story['text_display']))  $texts_to_translate[] = $story['text_display'];
        }
        
        $unique_texts = array_values(array_unique($texts_to_translate));
        $translated_map = [];
        
        if (!empty($unique_texts)) {
            $batch_size = 10;
            for ($i = 0; $i < count($unique_texts); $i += $batch_size) {
                $batch = array_slice($unique_texts, $i, $batch_size);
                
                $original_to_protected = [];
                $protected_to_original = [];
                $tag_placeholders = [];
                $protected_texts = [];
                
                foreach ($batch as $original_text) {
                    $protected = $original_text;
                    $protected = preg_replace_callback(
                        '/(<break[^>]*>)/i',
                        function($matches) use (&$tag_placeholders) {
                            static $counter = 0;
                            $placeholder = "___BREAK_TAG_" . $counter++ . "___";
                            $tag_placeholders[$placeholder] = $matches[1];
                            return $placeholder;
                        },
                        $protected
                    );
                    $protected_texts[] = $protected;
                    $original_to_protected[$original_text] = $protected;
                    $protected_to_original[$protected] = $original_text;
                }
                
                $numbered_texts = [];
                $position_to_original = [];
                $idx = 1;
                
                foreach ($protected_texts as $protected) {
                    $numbered_texts[] = "$idx. " . $protected;
                    $position_to_original[$idx] = $protected_to_original[$protected];
                    $idx++;
                }
                
                $prompt = "TASK: Translate these podcast script texts into {$target_name}.\n\n"
                        . "CRITICAL RULES:\n"
                        . "1. The texts contain placeholders like ___BREAK_TAG_0___ that represent audio pause commands\n"
                        . "2. DO NOT translate these placeholders - keep them EXACTLY as they are\n"
                        . "3. Translate ONLY the human-readable words around them\n"
                        . "4. Return ONLY a numbered list with the translated texts\n"
                        . "5. No introductions, no explanations\n\n"
                        . "TEXTS TO TRANSLATE:\n"
                        . implode("\n", $numbered_texts);
                
                $api_error = '';
                $translation_result = callChatGPT($OPENAI_API_KEY, $MODEL, $prompt, $api_error, $target_name);
                
                if ($translation_result === false) {
                    $lang_result['error'] = 'Translation failed: ' . $api_error;
                    break 2;
                }
                
                $lines = explode("\n", $translation_result);
                foreach ($lines as $line) {
                    if (preg_match('/^(\d+)[\.\-\)]\s*(.+)$/u', trim($line), $matches)) {
                        $num = (int)$matches[1];
                        $translated_with_placeholders = trim($matches[2]);
                        
                        if (isset($position_to_original[$num])) {
                            $original_text = $position_to_original[$num];
                            $restored_text = $translated_with_placeholders;
                            foreach ($tag_placeholders as $placeholder => $tag) {
                                $restored_text = str_replace($placeholder, $tag, $restored_text);
                            }
                            $translated_map[$original_text] = $restored_text;
                        }
                    }
                }
                
                usleep(500000);
            }
        }
        
        // ===== STEP 4: CREATE NEW STORIES =====
        $stories_created = 0;
        foreach ($source_stories as $source_story) {
            $story_fields = [];
            $story_values = [];
            
            foreach ($source_story as $field => $value) {
                if ($field == 'id') continue;
                
                if ($field == 'podcast_id') {
                    $story_fields[] = $field;
                    $story_values[] = $new_podcast_id;
                } elseif ($field == 'lang_code') {
                    $story_fields[] = $field;
                    $story_values[] = "'$target_code'";
                } elseif ($field == 'audio_file') {
                    $story_fields[] = $field;
                    $story_values[] = "''";
                } elseif ($field == 'text_contents' || $field == 'text_display') {
                    if (!empty($value) && isset($translated_map[$value])) {
                        $translated = $translated_map[$value];
                    } else {
                        $translated = $value;
                    }
                    $translated = preg_replace('/(<break time=)(\d+ms)(\/>)/i', '$1"$2"$3', $translated);
                    $escaped_value = mysqli_real_escape_string($conn, $translated);
                    $story_fields[] = $field;
                    $story_values[] = "'$escaped_value'";
                } else {
                    $escaped_value = mysqli_real_escape_string($conn, $value);
                    $story_fields[] = $field;
                    $story_values[] = "'$escaped_value'";
                }
            }
            
            $story_insert_sql = "INSERT INTO hdb_podcast_stories (" . implode(',', $story_fields) . ") 
                                 VALUES (" . implode(',', $story_values) . ")";
            
            if (mysqli_query($conn, $story_insert_sql)) {
                $stories_created++;
            }
        }
        
        $lang_result['status'] = 'success';
        $lang_result['podcast_id'] = $new_podcast_id;
        $lang_result['stories_created'] = $stories_created;
        $lang_result['texts_translated'] = count($translated_map);
        $results[] = $lang_result;
    }
    
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

function callChatGPT($api_key, $model, $prompt, &$error_msg = '', $lang = 'Arabic') {
    $url = 'https://api.openai.com/v1/chat/completions';
    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => "You are an expert $lang translator. The text contains placeholders like ___BREAK_TAG_0___ that represent audio pause commands. NEVER translate these placeholders - keep them exactly as they are. Translate ONLY the human-readable words. Return ONLY the numbered list."],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        $error_msg = 'CURL Error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    $decoded = json_decode($response, true);
    
    if ($http_code !== 200) {
        $error_msg = $decoded['error']['message'] ?? 'HTTP Error: ' . $http_code;
        return false;
    }
    
    if (isset($decoded['choices'][0]['message']['content'])) {
        return trim($decoded['choices'][0]['message']['content']);
    }
    
    $error_msg = 'Unexpected API response structure';
    return false;
}

// Get the specific podcast for the dropdown (if needed)
// We still need to populate the hidden select with the current podcast
if ($url_podcast_id > 0) {
    $podcasts_sql = "SELECT id, title, lang_code FROM hdb_podcasts WHERE client_id = '$client_id' AND id = $url_podcast_id ORDER BY title ASC";
} else {
    $podcasts_sql = "SELECT id, title, lang_code FROM hdb_podcasts WHERE client_id = '$client_id' ORDER BY title ASC";
}
$podcasts_result = mysqli_query($conn, $podcasts_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VideoVizard-From Idea to Video in Minutes.</title>
	<link rel="stylesheet" href="/css/header.css">
    <link rel="stylesheet" href="/css/tooltip.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f8fafc; color: #1e293b; min-height: 100vh; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .vidora-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 24px; background: linear-gradient(90deg, #0f2a44, #143b63);
            color: #fff; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.15);
            margin-bottom: 25px;
        }
        .brand { font-size: 22px; font-weight: 600; display: flex; align-items: baseline; gap: 8px; }
        .brand span { color: #5fd1ff; }
        .brand small { font-size: 12px; color: #cde9ff; font-weight: 400; }
        .vidora-nav { display: flex; gap: 18px; }
        .vidora-nav a { text-decoration: none; color: #fff; font-size: 15px; padding: 7px 14px; border-radius: 6px; transition: all 0.25s ease; }
        .vidora-nav a:hover { background: rgba(255,255,255,0.15); }
        .vidora-nav a.active { background: #5fd1ff; color: #0f2a44; font-weight: 600; }

        .card { background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; margin-bottom: 20px; }
        h1 { font-size: 28px; color: #0f2a44; margin-bottom: 10px; }
        h2 { font-size: 18px; color: #64748b; margin-bottom: 25px; font-weight: 400; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; }
        select { width: 100%; padding: 12px 16px; border-radius: 8px; font-size: 15px; border: 2px solid #e2e8f0; background: white; outline: none; }
        select:focus { border-color: #5fd1ff; }

        .btn-primary {
            width: 100%; padding: 12px 16px; border-radius: 8px; font-size: 15px;
            background: linear-gradient(135deg, #0f2a44, #143b63); color: white; border: none;
            font-weight: 600; cursor: pointer; transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(15,42,68,0.2);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 10px -1px rgba(15,42,68,0.3); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* ===== CLICKABLE LANGUAGE CARDS ===== */
        .language-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }

        .language-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            transition: all 0.25s;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        .language-card:hover {
            border-color: #5fd1ff;
            background: #f0f9ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(95,209,255,0.25);
        }
        .language-card.pending { border-color: #f59e0b; background: #fef3c7; cursor: default; transform: none; }
        .language-card.success { border-color: #10b981; background: #d1fae5; cursor: pointer; }
        .language-card.failed  { border-color: #ef4444; background: #fee2e2; }
        .language-card.warning { border-color: #f59e0b; background: #ffedd5; cursor: pointer; }

        .language-card .click-hint {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 6px;
            font-style: italic;
        }
        .language-card.pending .click-hint,
        .language-card.success .click-hint { display: none; }

        /* Locked state during any translation */
        .language-card.locked {
            opacity: 0.5;
            cursor: not-allowed !important;
            pointer-events: none;
            transform: none !important;
        }
        .language-card.locked:hover {
            border-color: #e2e8f0;
            background: #f8fafc;
            box-shadow: none;
        }

        .language-name { font-size: 18px; font-weight: 700; margin-bottom: 5px; }
        .language-status { font-size: 13px; margin-bottom: 6px; }
        .language-detail { font-size: 12px; color: #475569; }

        .progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin: 20px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #0f2a44, #5fd1ff); width: 0%; transition: width 0.3s; }

        .log-box {
            background: #0f172a; color: #e2e8f0; padding: 20px; border-radius: 8px;
            font-family: 'Courier New', monospace; font-size: 13px; max-height: 300px;
            overflow-y: auto; margin-top: 20px; display: none;
        }
        .log-entry { margin-bottom: 5px; border-bottom: 1px solid #334155; padding-bottom: 5px; }
        .log-success { color: #4ade80; }
        .log-error { color: #f87171; }
        .log-info { color: #60a5fa; }
        .log-warning { color: #fbbf24; }

        .summary-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f8fafc; padding: 15px; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; }
        .stat-number { font-size: 28px; font-weight: 700; color: #0f2a44; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; }
        .hidden { display: none; }

        .translate-all-row { display: flex; gap: 10px; margin-top: 10px; align-items: center; }
        .translate-all-row small { color: #64748b; font-size: 13px; }
        
        .podcast-info {
            background: #e0f2fe;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .lang-badge {
            display: inline-block;
            background: #8b5cf6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
            font-weight: normal;
        }
    </style>
</head>
<body>
<div class="container">
    <header class="vidora-header">
        <div class="brand">🎬 <span>Vidora</span><small>Social Media Automation</small></div>
        <nav class="vidora-nav">
            <a href="vidora_home.php" class="active">Home</a>
            <a href="image_gen.php?podcast_id=<?= $podcast_id ?>">Visuals</a>
            <a href="audio_gen.php?podcast_id=<?= $podcast_id ?>">Audio</a>
            <a href="videomaker.php?podcast_id=<?= $podcast_id ?>">Video</a>
        </nav>
    </header>

    <div class="card" style="margin-bottom: 20px;">
  <h1>🌍 Globalize Your Content</h1>

  <p>
    Once you have finalized your visuals and audio, you can translate your video into multiple languages. 
    <strong>Note:</strong> Translation options are currently available for English-source videos.
  </p>

  <a href="javascript:void(0);" onclick="toggleReadMore()" id="readMoreBtn" style="font-weight:600; color:#2563eb; text-decoration:none; font-size: 13px;">
    Read more
  </a>

  <div id="readMoreContent" style="display:none; margin-top:10px; color:#475569; line-height:1.6; font-size: 13px; border-left: 3px solid #5fd1ff; padding-left: 15px;">
    <p>
      Simply click on a language box below to generate a translated version. The new project will 
      automatically include the same images and videos as your original.
    </p>
    <p style="margin-top:8px;">
      <strong>Next Step:</strong> After translating, visit the <em>Audios</em> page of the new project 
      to generate the voiceover for the new language. 
    </p>
    <p style="margin-top:8px;">
      Translated videos appear on your <strong>Home Page</strong> with the same thumbnail, 
      labeled with language codes like <strong>"fr"</strong> (French) or <strong>"es"</strong> (Spanish).
    </p>
  </div>
</div>


<div class="card">

    <h2>Select a target language below to begin the translation process.</h2>
    
    <?php if ($url_podcast_id > 0 && $podcast_title): ?>
    <div class="podcast-info" style="background: #f1f5f9; padding: 10px; border-radius: 8px; margin: 15px 0;">
        <strong>📋 Current Podcast:</strong> <?= htmlspecialchars($podcast_title) ?> 
        (ID: <?= $url_podcast_id ?>)
        <?php if ($podcast_lang): ?>
            <span class="lang-badge" style="background:#0f2a44; color:#fff; padding:2px 6px; border-radius:4px; font-size:10px;"><?= strtoupper($podcast_lang) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <select id="podcastSelect" style="display: none;">
        <?php if ($url_podcast_id > 0): 
            mysqli_data_seek($podcasts_result, 0);
            while ($pod = mysqli_fetch_assoc($podcasts_result)): ?>
                <option value="<?= $pod['id'] ?>" <?= ($url_podcast_id == $pod['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pod['title']) ?> (<?= strtoupper($pod['lang_code']) ?>)
                </option>
            <?php endwhile; 
        endif; ?>
    </select>

    <div class="language-grid" id="languageGrid">
        <div class="language-card" data-lang="ur" onclick="translateSingle('ur')">
            <div class="language-name">🇵🇰 Urdu</div>
            <div class="language-status" id="status-ur">Ready</div>
            <div class="language-detail" id="detail-ur"></div>
            <div class="click-hint">Click to translate</div>
        </div>
        <div class="language-card" data-lang="ar" onclick="translateSingle('ar')">
            <div class="language-name">🇸🇦 Arabic</div>
            <div class="language-status" id="status-ar">Ready</div>
            <div class="language-detail" id="detail-ar"></div>
            <div class="click-hint">Click to translate</div>
        </div>
        <div class="language-card" data-lang="hi" onclick="translateSingle('hi')">
            <div class="language-name">🇮🇳 Hindi</div>
            <div class="language-status" id="status-hi">Ready</div>
            <div class="language-detail" id="detail-hi"></div>
            <div class="click-hint">Click to translate</div>
        </div>
        <div class="language-card" data-lang="fr" onclick="translateSingle('fr')">
            <div class="language-name">🇫🇷 French</div>
            <div class="language-status" id="status-fr">Ready</div>
            <div class="language-detail" id="detail-fr"></div>
            <div class="click-hint">Click to translate</div>
        </div>
        <div class="language-card" data-lang="es" onclick="translateSingle('es')">
            <div class="language-name">🇪🇸 Spanish</div>
            <div class="language-status" id="status-es">Ready</div>
            <div class="language-detail" id="detail-es"></div>
            <div class="click-hint">Click to translate</div>
        </div>
    </div>

    <div class="progress-bar" id="progressBar" style="display: none;">
        <div class="progress-fill" id="progressFill"></div>
    </div>

    <div class="summary-stats hidden" id="summaryStats">
        <div class="stat-box"><div class="stat-number" id="statTotal">0</div><div class="stat-label">Total</div></div>
        <div class="stat-box"><div class="stat-number" id="statSuccess">0</div><div class="stat-label">Success</div></div>
        <div class="stat-box"><div class="stat-number" id="statDeleted">0</div><div class="stat-label">Replaced</div></div>
        <div class="stat-box"><div class="stat-number" id="statFailed">0</div><div class="stat-label">Failed</div></div>
    </div>

    <div class="log-box" id="logBox"></div>
</div>
</div>

<script>
const ALL_LANGS = ['ur', 'ar', 'hi', 'fr', 'es'];
const LANG_NAMES = { ur: 'Urdu', ar: 'Arabic', hi: 'Hindi', fr: 'French', es: 'Spanish' };
let isTranslating = false;


<script>
function toggleReadMore() {
  const content = document.getElementById("readMoreContent");
  const btn = document.getElementById("readMoreBtn");

  if (content.style.display === "none") {
    content.style.display = "block";
    btn.textContent = "Read less";
  } else {
    content.style.display = "none";
    btn.textContent = "Read more";
  }
}

// ===== AUTO-SELECT PODCAST FROM URL =====
window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const urlPodcastId = urlParams.get('podcast_id');
    if (urlPodcastId) {
        const select = document.getElementById('podcastSelect');
        const match = Array.from(select.options).find(o => o.value == urlPodcastId);
        if (match) {
            select.value = urlPodcastId;
            log('Auto-selected: "' + match.text + '" (ID: ' + urlPodcastId + ')', 'info');
        } else {
            log('Podcast ID ' + urlPodcastId + ' not found in select options', 'warning');
        }
    }
});

// ===== LOCK ALL CONTROLS =====
function lockUI(message) {
    isTranslating = true;
    ALL_LANGS.forEach(lang => {
        const card = document.querySelector('[data-lang="' + lang + '"]');
        if (card) {
            card.style.opacity = '0.45';
            card.style.pointerEvents = 'none';
            card.style.cursor = 'not-allowed';
        }
    });
}

// ===== UNLOCK ALL CONTROLS =====
function unlockUI() {
    isTranslating = false;
    ALL_LANGS.forEach(lang => {
        const card = document.querySelector('[data-lang="' + lang + '"]');
        if (card) {
            card.style.opacity = '';
            card.style.pointerEvents = '';
            card.style.cursor = '';
        }
    });
}

// ===== LOG =====
function log(message, type) {
    type = type || 'info';
    const box = document.getElementById('logBox');
    box.style.display = 'block';
    const entry = document.createElement('div');
    entry.className = 'log-entry log-' + type;
    entry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
    box.appendChild(entry);
    box.scrollTop = box.scrollHeight;
}

// ===== UPDATE CARD STATUS =====
function updateLanguageStatus(lang, status, detail) {
    const card = document.querySelector('[data-lang="' + lang + '"]');
    const statusEl = document.getElementById('status-' + lang);
    const detailEl = document.getElementById('detail-' + lang);
    if (!card) return;

    card.classList.remove('pending', 'success', 'failed', 'warning');
    card.classList.add(status);

    if (status === 'pending')  statusEl.textContent = '⏳ Translating...';
    if (status === 'success')  statusEl.textContent = '✅ Completed';
    if (status === 'failed')   statusEl.textContent = '❌ Failed';
    if (status === 'warning')  statusEl.textContent = '⚠️ Replaced & Created';

    if (detail) detailEl.textContent = detail;
}

function resetCard(lang) {
    const card = document.querySelector('[data-lang="' + lang + '"]');
    if (!card) return;
    card.classList.remove('pending', 'success', 'failed', 'warning');
    document.getElementById('status-' + lang).textContent = 'Ready';
    document.getElementById('detail-' + lang).textContent = '';
}

// ===== TRANSLATE SINGLE LANGUAGE =====
async function translateSingle(lang) {
    if (isTranslating) return;

    const podcastId = document.getElementById('podcastSelect').value;
    if (!podcastId) { 
        alert('No podcast selected. Please check the URL parameter.'); 
        return; 
    }

    if (!confirm('Translate to ' + LANG_NAMES[lang] + '?\nAny existing ' + LANG_NAMES[lang] + ' version will be deleted and replaced.')) return;

    lockUI('Translating ' + LANG_NAMES[lang] + '...');
    updateLanguageStatus(lang, 'pending', '');
    log('Starting: ' + LANG_NAMES[lang], 'info');

    try {
        const formData = new FormData();
        formData.append('action', 'clone_podcast');
        formData.append('podcast_id', podcastId);
        formData.append('target_lang', lang);

        log('Sending request to server...', 'info');
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        
        // First check if response is OK
        if (!response.ok) {
            log('Server returned status: ' + response.status, 'error');
            throw new Error('Server error: ' + response.status);
        }
        
        // Get the raw text first
        const rawText = await response.text();
        log('Raw response length: ' + rawText.length + ' chars', 'info');
        
        // Log first 200 chars for debugging
        if (rawText.length > 0) {
            log('Response preview: ' + rawText.substring(0, 200), 'info');
        }
        
        // Try to parse JSON
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            log('❌ Failed to parse JSON: ' + e.message, 'error');
            log('Raw response: ' + rawText, 'error');
            throw new Error('Invalid JSON response from server');
        }

        if (data.success && data.results && data.results.length > 0) {
            const result = data.results[0];
            if (result.status === 'success') {
                const detail = result.deleted_podcast
                    ? 'Deleted old → Created ' + result.stories_created + ' scenes'
                    : 'Created ' + result.stories_created + ' scenes';
                updateLanguageStatus(lang, result.deleted_podcast ? 'warning' : 'success', detail);
                log(LANG_NAMES[lang] + ': Done — ' + detail, 'success');
            } else {
                updateLanguageStatus(lang, 'failed', result.error || 'Failed');
                log(LANG_NAMES[lang] + ': Failed — ' + (result.error || 'Unknown'), 'error');
            }
        } else {
            updateLanguageStatus(lang, 'failed', data.error || 'Unknown server error');
            log('Error from server: ' + JSON.stringify(data), 'error');
        }
    } catch (err) {
        updateLanguageStatus(lang, 'failed', 'Error: ' + err.message);
        log('❌ Error: ' + err.message, 'error');
    }

    unlockUI();
}
</script>

</body>
</html>