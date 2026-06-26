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

// OpenAI Configuration
$OPENAI_API_KEY = "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";
$MODEL = 'gpt-4o-mini';

// Store for tag placeholders during translation
$GLOBALS['tag_placeholders'] = [];

// Get podcast_id from URL if present
$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

// Handle AJAX request for cloning
if (isset($_POST['action']) && $_POST['action'] === 'clone_podcast') {
    header('Content-Type: application/json');
    
    $source_podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $source_lang = 'en';
    
    // Target languages to clone to
    $target_languages = [
        'ur' => 'Urdu',
        'ar' => 'Arabic', 
        'hi' => 'Hindi',
        'fr' => 'French',
        'es' => 'Spanish'
    ];
    
    if (empty($source_podcast_id)) {
        echo json_encode(['success' => false, 'error' => 'No source podcast ID provided']);
        exit;
    }
    
    // 1. Get source podcast
    $podcast_sql = "SELECT * FROM hdb_podcasts WHERE id = $source_podcast_id AND lang_code = 'en'";
    $podcast_result = mysqli_query($conn, $podcast_sql);
    
    if (!$podcast_result || mysqli_num_rows($podcast_result) === 0) {
        echo json_encode(['success' => false, 'error' => 'English source podcast not found']);
        exit;
    }
    
    $source_podcast = mysqli_fetch_assoc($podcast_result);
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
    
    // Process each target language
    $results = [];
    
    foreach ($target_languages as $target_code => $target_name) {
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
        
        // ===== STEP 1: DELETE EXISTING PODCAST AND STORIES =====
        
        // Find if a podcast with this title and language already exists
        $find_sql = "SELECT id FROM hdb_podcasts 
                     WHERE title = '" . mysqli_real_escape_string($conn, $podcast_title) . "' 
                     AND lang_code = '$target_code'";
        $find_result = mysqli_query($conn, $find_sql);
        
        $existing_podcast_id = null;
        if ($find_result && mysqli_num_rows($find_result) > 0) {
            $existing = mysqli_fetch_assoc($find_result);
            $existing_podcast_id = $existing['id'];
            
            // Delete all stories for this existing podcast first
            $delete_stories_sql = "DELETE FROM hdb_podcast_stories WHERE podcast_id = $existing_podcast_id";
            if (mysqli_query($conn, $delete_stories_sql)) {
                $deleted_stories = mysqli_affected_rows($conn);
                $lang_result['deleted_stories'] = $deleted_stories;
            }
            
            // Now delete the podcast itself
            $delete_podcast_sql = "DELETE FROM hdb_podcasts WHERE id = $existing_podcast_id";
            if (mysqli_query($conn, $delete_podcast_sql)) {
                $lang_result['deleted_podcast'] = true;
            }
        }
        
        // ===== STEP 2: CREATE NEW PODCAST =====
        
        // Create new podcast row
        $insert_fields = [];
        $insert_values = [];
        
        foreach ($source_podcast as $field => $value) {
            if ($field == 'id') continue;
            
            if ($field == 'lang_code') {
                $insert_fields[] = $field;
                $insert_values[] = "'$target_code'";
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
        
        // Collect texts for translation
        $texts_to_translate = [];
        foreach ($source_stories as $story) {
            if (!empty($story['text_contents'])) {
                $texts_to_translate[] = $story['text_contents'];
            }
            if (!empty($story['text_display'])) {
                $texts_to_translate[] = $story['text_display'];
            }
        }
        
        $unique_texts = array_values(array_unique($texts_to_translate));
        $translated_map = [];
        
        // Translate in batches
        if (!empty($unique_texts)) {
            $batch_size = 10;
            for ($i = 0; $i < count($unique_texts); $i += $batch_size) {
                $batch = array_slice($unique_texts, $i, $batch_size);
                
                // Store original -> protected mapping for this batch
                $original_to_protected = [];
                $protected_to_original = [];
                $tag_placeholders = [];
                
                // First pass: create protected versions with placeholders
                $protected_texts = [];
                foreach ($batch as $original_text) {
                    $protected = $original_text;
                    
                    // Replace break tags with placeholders
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
                
                // Prepare numbered list
                $numbered_texts = [];
                $position_to_original = [];
                $idx = 1;
                
                foreach ($protected_texts as $protected) {
                    $numbered_texts[] = "$idx. " . $protected;
                    $position_to_original[$idx] = $protected_to_original[$protected];
                    $idx++;
                }
                
                // Enhanced prompt
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
                
                // Parse results and restore tags
                $lines = explode("\n", $translation_result);
                foreach ($lines as $line) {
                    if (preg_match('/^(\d+)[\.\-\)]\s*(.+)$/u', trim($line), $matches)) {
                        $num = (int)$matches[1];
                        $translated_with_placeholders = trim($matches[2]);
                        
                        if (isset($position_to_original[$num])) {
                            $original_text = $position_to_original[$num];
                            
                            // Restore original break tags
                            $restored_text = $translated_with_placeholders;
                            foreach ($tag_placeholders as $placeholder => $tag) {
                                $restored_text = str_replace($placeholder, $tag, $restored_text);
                            }
                            
                            // Store using original text as key
                            $translated_map[$original_text] = $restored_text;
                        }
                    }
                }
                
                // Add small delay between batches
                usleep(500000); // 0.5 seconds
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
                    // ALWAYS set audio_file to empty string for translated versions
                    $story_fields[] = $field;
                    $story_values[] = "''";
                } elseif ($field == 'text_contents' || $field == 'text_display') {
                    // Get translated text using original value as key
                    if (!empty($value) && isset($translated_map[$value])) {
                        $translated = $translated_map[$value];
                    } else {
                        // Fallback to original
                        $translated = $value;
                    }
                    
                    // Ensure break tags have quotes
                    $translated = preg_replace(
                        '/(<break time=)(\d+ms)(\/>)/i',
                        '$1"$2"$3',
                        $translated
                    );
                    
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

/**
 * Call ChatGPT API for translation
 */
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

// Get list of English podcasts for dropdown
$podcasts_sql = "SELECT id, title FROM hdb_podcasts WHERE client_id = '$client_id' and video_status  = '' and lang_code = 'en' ORDER BY title ASC";
//echo " sql ".$podcasts_sql;die;
$podcasts_result = mysqli_query($conn, $podcasts_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podcast Translator - Clone to Multiple Languages</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background: #f8fafc; 
            color: #1e293b; 
            min-height: 100vh; 
            padding: 20px; 
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
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

        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }

        h1 { font-size: 28px; color: #0f2a44; margin-bottom: 10px; }
        h2 { font-size: 18px; color: #64748b; margin-bottom: 25px; font-weight: 400; }

        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #1e293b; }
        select, button {
            width: 100%;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 15px;
        }
        select {
            border: 2px solid #e2e8f0;
            background: white;
            outline: none;
        }
        select:focus { border-color: #5fd1ff; }

        .btn-primary {
            background: linear-gradient(135deg, #0f2a44, #143b63);
            color: white;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 6px -1px rgba(15,42,68,0.2);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 10px -1px rgba(15,42,68,0.3); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .language-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .language-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s;
        }
        .language-card.pending { border-color: #f59e0b; background: #fef3c7; }
        .language-card.success { border-color: #10b981; background: #d1fae5; }
        .language-card.failed { border-color: #ef4444; background: #fee2e2; }
        .language-card.warning { border-color: #f59e0b; background: #ffedd5; }

        .language-name { font-size: 18px; font-weight: 700; margin-bottom: 5px; }
        .language-status { font-size: 13px; margin-bottom: 10px; }
        .language-detail { font-size: 12px; color: #475569; }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0f2a44, #5fd1ff);
            width: 0%;
            transition: width 0.3s;
        }

        .log-box {
            background: #0f172a;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 20px;
            display: none;
        }
        .log-entry { margin-bottom: 5px; border-bottom: 1px solid #334155; padding-bottom: 5px; }
        .log-success { color: #4ade80; }
        .log-error { color: #f87171; }
        .log-info { color: #60a5fa; }
        .log-warning { color: #fbbf24; }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .stat-number { font-size: 28px; font-weight: 700; color: #0f2a44; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; }

        .hidden { display: none; }
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
				<a href="vidora.php">1. ontents</a>
				<a href="image_gen.php"   >2. Images</a>
				<a href="audio_gen.php">3. Audios</a>
				<a href="videomaker.php"   >4. Video</a>
				<a href="podcast_translator.php" class="active"  >5. Translate</a>
				<a href="publisher/dashboard.php">6. Schedule</a>
			</nav>
    </header>

    <div class="card">
        <h1>🌐 Podcast Translator</h1>
        <h2>Clone an English podcast to multiple languages with AI translation (Deletes existing versions)</h2>

        <div class="form-group">
            <label>Select English Podcast to Translate</label>
            <select id="podcastSelect">
                <option value="">-- Choose a podcast --</option>
                <?php 
                mysqli_data_seek($podcasts_result, 0); // Reset pointer
                while ($pod = mysqli_fetch_assoc($podcasts_result)): 
                    $selected = ($url_podcast_id == $pod['id']) ? 'selected' : '';
                ?>
                    <option value="<?= $pod['id'] ?>" <?= $selected ?>><?= htmlspecialchars($pod['title']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="language-grid" id="languageGrid">
            <div class="language-card" data-lang="ur">
                <div class="language-name">🇵🇰 Urdu</div>
                <div class="language-status" id="status-ur">Ready</div>
                <div class="language-detail" id="detail-ur"></div>
            </div>
            <div class="language-card" data-lang="ar">
                <div class="language-name">🇸🇦 Arabic</div>
                <div class="language-status" id="status-ar">Ready</div>
                <div class="language-detail" id="detail-ar"></div>
            </div>
            <div class="language-card" data-lang="hi">
                <div class="language-name">🇮🇳 Hindi</div>
                <div class="language-status" id="status-hi">Ready</div>
                <div class="language-detail" id="detail-hi"></div>
            </div>
            <div class="language-card" data-lang="fr">
                <div class="language-name">🇫🇷 French</div>
                <div class="language-status" id="status-fr">Ready</div>
                <div class="language-detail" id="detail-fr"></div>
            </div>
            <div class="language-card" data-lang="es">
                <div class="language-name">🇪🇸 Spanish</div>
                <div class="language-status" id="status-es">Ready</div>
                <div class="language-detail" id="detail-es"></div>
            </div>
        </div>

        <button class="btn-primary" id="startBtn" onclick="startCloning()">🚀 Start Translation to All Languages</button>

        <div class="progress-bar" id="progressBar">
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
// Auto-select podcast from URL if present
const urlParams = new URLSearchParams(window.location.search);
const podcastId = urlParams.get('podcast_id');
if (podcastId) {
    document.getElementById('podcastSelect').value = podcastId;
}

const languageCards = {
    ur: document.getElementById('status-ur'),
    ar: document.getElementById('status-ar'),
    hi: document.getElementById('status-hi'),
    fr: document.getElementById('status-fr'),
    es: document.getElementById('status-es')
};

const detailCards = {
    ur: document.getElementById('detail-ur'),
    ar: document.getElementById('detail-ar'),
    hi: document.getElementById('detail-hi'),
    fr: document.getElementById('detail-fr'),
    es: document.getElementById('detail-es')
};

function log(message, type = 'info') {
    const box = document.getElementById('logBox');
    box.style.display = 'block';
    const entry = document.createElement('div');
    entry.className = 'log-entry log-' + type;
    entry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
    box.appendChild(entry);
    box.scrollTop = box.scrollHeight;
}

function updateLanguageStatus(lang, status, detail = '') {
    const card = document.querySelector(`[data-lang="${lang}"]`);
    const statusEl = languageCards[lang];
    const detailEl = detailCards[lang];
    
    card.classList.remove('pending', 'success', 'failed', 'warning');
    card.classList.add(status);
    
    if (status === 'pending') statusEl.textContent = '⏳ Processing...';
    else if (status === 'success') statusEl.textContent = '✅ Completed';
    else if (status === 'failed') statusEl.textContent = '❌ Failed';
    else if (status === 'warning') statusEl.textContent = '⚠️ Replaced';
    
    if (detail) detailEl.textContent = detail;
}

async function startCloning() {
    const podcastId = document.getElementById('podcastSelect').value;
    if (!podcastId) {
        alert('Please select a podcast first');
        return;
    }

    // Confirm deletion
    if (!confirm('⚠️ This will DELETE any existing versions in other languages and replace them with new translations. Continue?')) {
        return;
    }

    // Reset UI
    document.getElementById('summaryStats').classList.add('hidden');
    document.getElementById('logBox').innerHTML = '';
    document.getElementById('logBox').style.display = 'none';
    document.getElementById('progressFill').style.width = '0%';
    
    // Reset language cards
    ['ur', 'ar', 'hi', 'fr', 'es'].forEach(lang => {
        const card = document.querySelector(`[data-lang="${lang}"]`);
        card.classList.remove('pending', 'success', 'failed', 'warning');
        languageCards[lang].textContent = 'Ready';
        detailCards[lang].textContent = '';
    });

    const btn = document.getElementById('startBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Translating...';

    log(`Starting translation for podcast ID: ${podcastId}`, 'info');
    log(`⚠️ Any existing versions will be DELETED and replaced`, 'warning');
    log(`✅ <break time="250ms"/> tags will be preserved with quotes`, 'info');
    log(`✅ Audio files set to empty string (needs regeneration)`, 'info');

    try {
        const formData = new FormData();
        formData.append('action', 'clone_podcast');
        formData.append('podcast_id', podcastId);

        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        
        if (data.success) {
            let successCount = 0, deletedCount = 0, failedCount = 0;
            
            // Update each language's status
            data.results.forEach(result => {
                let detail = '';
                if (result.status === 'success') {
                    if (result.deleted_podcast) {
                        detail = `🗑️ Deleted old (${result.deleted_stories} stories) → Created ${result.stories_created} new scenes`;
                        deletedCount++;
                    } else {
                        detail = `📊 Created ${result.stories_created} scenes, ${result.texts_translated} texts`;
                        successCount++;
                    }
                } else if (result.status === 'failed') {
                    detail = result.error || 'Failed';
                    failedCount++;
                }
                
                updateLanguageStatus(
                    result.lang, 
                    result.status === 'success' ? (result.deleted_podcast ? 'warning' : 'success') : result.status,
                    detail
                );
                
                if (result.deleted_podcast) {
                    log(`${result.lang_name}: Deleted old version (${result.deleted_stories} stories) and created ${result.stories_created} new scenes`, 'warning');
                } else if (result.status === 'success') {
                    log(`${result.lang_name}: Created ${result.stories_created} new scenes`, 'success');
                } else if (result.status === 'failed') {
                    log(`${result.lang_name}: Failed - ${result.error}`, 'error');
                }
            });

            // Update summary stats
            document.getElementById('statTotal').textContent = data.results.length;
            document.getElementById('statSuccess').textContent = successCount;
            document.getElementById('statDeleted').textContent = deletedCount;
            document.getElementById('statFailed').textContent = failedCount;
            document.getElementById('summaryStats').classList.remove('hidden');
            document.getElementById('progressFill').style.width = '100%';
            
            log('✅ Translation complete! Audio files set to empty string.', 'success');
        } else {
            log('❌ Error: ' + (data.error || 'Unknown error'), 'error');
        }
    } catch (err) {
        log('❌ Network error: ' + err.message, 'error');
    }

    btn.disabled = false;
    btn.textContent = '🚀 Start Translation to All Languages';
}
</script>
</body>
</html>