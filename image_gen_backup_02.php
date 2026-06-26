<?php
session_start();

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';
require_once 'generate_image_api.php';

// ---------- AJAX: Get Scenes for Podcast ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scenes') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $pid = (int)$_POST['podcast_id'];
    $scenes = [];
    $r = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$pid ORDER BY id");
    while($row = mysqli_fetch_assoc($r)) $scenes[] = $row;
    echo json_encode($scenes);
    exit;
}

// ---------- AJAX: Enhance Prompt & Get Hashtags ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'enhance_prompt') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $scene_id = (int)$_POST['scene_id'];
    $original_prompt = trim($_POST['prompt'] ?? '');

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

    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | scene=$scene_id | calling callChatGPT_inam\n", 3, __DIR__ . "/a_debug.log");
    
    $result = callChatGPT_inam($gpt_prompt);
    
    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | result=" . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");
    
    if (!$result || !is_array($result)) {
        echo json_encode(['success' => false, 'message' => 'callChatGPT_inam returned invalid result: ' . print_r($result, true)]);
        exit;
    }
    
    if (!$result['success']) {
        $err = isset($result['error']) ? $result['error'] : (isset($result['message']) ? $result['message'] : 'Unknown error');
        echo json_encode(['success' => false, 'message' => 'AI Error: ' . $err]);
        exit;
    }

    $raw = $result['response'];
    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $raw = preg_replace('/\s*```$/i', '', $raw);
    $parsed = json_decode(trim($raw), true);

    if (!$parsed || !isset($parsed['enhanced_prompt']) || !isset($parsed['hashtags'])) {
        error_log("Enhance parse fail scene=$scene_id raw=$raw\n", 3, __DIR__ . "/a_debug.log");
        echo json_encode(['success' => false, 'message' => 'JSON parse fail. Raw: ' . mb_substr($raw, 0, 300)]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'enhanced_prompt' => $parsed['enhanced_prompt'],
        'hashtags' => $parsed['hashtags']
    ]);
    exit;
}

// ---------- AJAX: Check Image Data by Hashtags ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_image_data') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $hashtags = trim($_POST['hashtags'] ?? '');

    if (empty($hashtags)) {
        echo json_encode(['found' => false]);
        exit;
    }

    $tags = array_map('trim', explode(',', $hashtags));
    $conditions = [];
    foreach ($tags as $tag) {
        if (!empty($tag)) {
            $escaped = mysqli_real_escape_string($conn, $tag);
            $conditions[] = "image_hashtags LIKE '%$escaped%'";
        }
    }

    if (empty($conditions)) {
        echo json_encode(['found' => false]);
        exit;
    }

    $where = implode(' OR ', $conditions);
    $r = mysqli_query($conn, "SELECT * FROM hdb_image_data WHERE $where LIMIT 1");

    if ($r && mysqli_num_rows($r) > 0) {
        $row = mysqli_fetch_assoc($r);
        echo json_encode([
            'found' => true,
            'image_name' => $row['image_name'],
            'image_hashtags' => $row['image_hashtags']
        ]);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

// ---------- AJAX: Generate Image via gpt-image-1 ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_image') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $enhanced_prompt = trim($_POST['enhanced_prompt'] ?? '');
    $hashtags = trim($_POST['hashtags'] ?? '');

    if (empty($enhanced_prompt)) {
        echo json_encode(['success' => false, 'message' => 'Empty prompt', 'step' => 'validate']);
        exit;
    }

     $api_key ="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";
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'message' => '$api_key not set. Check chatgpt_functions.php', 'step' => 'api_key']);
        exit;
    }

    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    $image_folder = __DIR__ . '/podcast_images';
    while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    $image_name = $image_name_base . '.png';

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step A: image_name=$image_name | folder=$image_folder\n", 3, __DIR__ . "/a_debug.log");
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B: Calling gpt-image-1 API...\n", 3, __DIR__ . "/a_debug.log");
    
    $result = generateAndSaveImage($enhanced_prompt, $image_name_base, "1024x1536", $image_folder, $api_key);

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B result: " . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");

    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => $result['message'], 'step' => 'generate_image']);
        exit;
    }

    $full_path = $result['filepath'];
    $file_exists = file_exists($full_path);
    $file_size = $file_exists ? filesize($full_path) : 0;
    
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step C: file_exists=" . ($file_exists ? 'YES' : 'NO') . " | size={$file_size} bytes | path=$full_path\n", 3, __DIR__ . "/a_debug.log");

    if (!$file_exists || $file_size < 1000) {
        echo json_encode(['success' => false, 'message' => "Image file missing or too small ({$file_size} bytes). Path: $full_path", 'step' => 'verify_file']);
        exit;
    }

    $esc_name = mysqli_real_escape_string($conn, $image_name);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
    $esc_prompt = mysqli_real_escape_string($conn, $enhanced_prompt);
    
    $table_cols = [];
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
    if (!$col_check) {
        $db_warning = 'hdb_image_data table not found: ' . mysqli_error($conn);
        echo json_encode(['success' => true, 'image_name' => $image_name, 'file_size' => $file_size, 'db_warning' => $db_warning, 'step' => 'db_table_missing']);
        exit;
    }
    while ($c = mysqli_fetch_assoc($col_check)) $table_cols[] = $c['Field'];
    
    $insert_map = [];
    if (in_array('image_name', $table_cols))     $insert_map['image_name'] = "'$esc_name'";
    if (in_array('image_hashtags', $table_cols))  $insert_map['image_hashtags'] = "'$esc_hashtags'";
    if (in_array('image_prompt', $table_cols))    $insert_map['image_prompt'] = "'$esc_prompt'";
    if (in_array('created_at', $table_cols))      $insert_map['created_at'] = "NOW()";
    if (in_array('name', $table_cols) && !isset($insert_map['image_name']))           $insert_map['name'] = "'$esc_name'";
    if (in_array('hashtags', $table_cols) && !isset($insert_map['image_hashtags']))    $insert_map['hashtags'] = "'$esc_hashtags'";
    if (in_array('prompt', $table_cols) && !isset($insert_map['image_prompt']))        $insert_map['prompt'] = "'$esc_prompt'";
    
    $db_warning = '';
    $db_inserted = false;
    
    if (empty($insert_map)) {
        $db_warning = 'No matching columns in hdb_image_data. Found columns: ' . implode(', ', $table_cols);
    } else {
        $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";
        if (!mysqli_query($conn, $ins_sql)) {
            $db_warning = 'INSERT failed: ' . mysqli_error($conn) . ' | Columns: ' . implode(', ', $table_cols);
        } else {
            $db_inserted = true;
        }
    }

    echo json_encode([
        'success' => true,
        'image_name' => $image_name,
        'file_size' => $file_size,
        'file_path' => $full_path,
        'db_inserted' => $db_inserted,
        'db_warning' => $db_warning,
        'table_columns' => $table_cols
    ]);
    exit;
}

// ---------- AJAX: Update Scene Row ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    $scene_id = (int)$_POST['scene_id'];
    $image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? '');
    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');

    $sql = "UPDATE hdb_podcast_stories SET image_file='$image_file'" . ($prompt ? ", prompt='$prompt'" : "") . " WHERE id=$scene_id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);
    }
    exit;
}

// ---------- AJAX: Get Media Library ----------
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_media_library') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    $image_dir = __DIR__ . '/podcast_images/';
    $images = [];
    
    $db_images = [];
    $r = mysqli_query($conn, "SELECT * FROM hdb_image_data ORDER BY id DESC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $db_images[$row['image_name']] = $row;
        }
    }
    
    if (is_dir($image_dir)) {
        $files = scandir($image_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) continue;
            
            $filepath = $image_dir . $file;
            $file_size = file_exists($filepath) ? filesize($filepath) : 0;
            
            $img_data = [
                'image_name' => $file,
                'hashtags' => '',
                'file_size' => $file_size
            ];
            
            if (isset($db_images[$file])) {
                $img_data['hashtags'] = $db_images[$file]['image_hashtags'] ?? $db_images[$file]['hashtags'] ?? '';
            }
            
            $images[] = $img_data;
        }
    }
    
    echo json_encode($images);
    exit;
}

// ---------- PAGE: Get English Podcasts ----------
$podcasts_result = mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE lang_code='en' AND (video_status='' OR video_status='0' OR video_status IS NULL) ORDER BY title"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title>Podcast Video Generator</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;font-size:13px;padding:30px;background:#f0f2f5;color:#333}
.card{background:#fff;padding:25px;border-radius:12px;border:1px solid #e0e0e0;margin:0 auto 20px;max-width:1200px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
h1{margin:0 0 5px;color:#1e293b;font-size:22px}
.sub{color:#64748b;font-size:12px;margin-bottom:20px}
select{padding:8px 12px;border-radius:6px;border:1px solid #ddd;font-size:13px;width:100%;background:#fff}
.btn{border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;color:#fff;display:inline-flex;align-items:center;gap:6px}
.btn:disabled{background:#cbd5e1!important;cursor:not-allowed}
.btn-go{background:#7c3aed;padding:10px 24px;border-radius:8px;font-size:13px}.btn-go:hover:not(:disabled){background:#6d28d9}
.btn-gen{background:#2563eb}.btn-gen:hover:not(:disabled){background:#1d4ed8}
.btn-edit{background:#f59e0b}.btn-edit:hover{background:#d97706}
.btn-all{background:#059669;padding:10px 24px;border-radius:8px;font-size:13px}.btn-all:hover:not(:disabled){background:#047857}
table{width:100%;border-collapse:collapse;margin-top:15px}
th{background:#f8fafc;padding:10px;text-align:left;font-size:11px;color:#64748b;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.5px}
td{padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:top}
tr:hover{background:#f8fafc}
.img-thumb{width:45px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;cursor:pointer;transition:opacity .2s}.img-thumb:hover{opacity:.8}
.status-badge{padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;display:inline-block}
.st-done{background:#ecfdf5;color:#059669}
.st-pending{background:#fef3c7;color:#d97706}
.st-working{background:#eff6ff;color:#2563eb}
.st-error{background:#fef2f2;color:#dc2626}
.st-reused{background:#f0fdf4;color:#15803d}
.prompt-text{max-width:250px;max-height:60px;overflow:hidden;text-overflow:ellipsis;font-size:11px;color:#475569;line-height:1.4}
.pb{width:100%;height:8px;background:#e2e8f0;border-radius:10px;overflow:hidden;margin:15px 0}
.pf{height:100%;background:linear-gradient(90deg,#7c3aed,#10b981);border-radius:10px;transition:width .3s;width:0}
.scene-count{font-size:11px;color:#64748b;margin:10px 0}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px}
/* Image Preview Modal */
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:9999;justify-content:center;align-items:center;cursor:pointer}
.modal-overlay.open{display:flex}
.modal-box{position:relative;max-height:90vh;max-width:90vw;animation:modalIn .2s ease}
.modal-box img{max-height:85vh;max-width:85vw;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.5)}
.modal-close{position:absolute;top:-12px;right:-12px;width:32px;height:32px;background:#fff;border:none;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);color:#333;font-weight:700}
.modal-close:hover{background:#f1f1f1}
.modal-info{text-align:center;color:#94a3b8;font-size:11px;margin-top:10px}
@keyframes modalIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
/* Media Library Modal */
.media-modal{display:none;position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.7);backdrop-filter:blur(5px)}
.media-modal-content{background:#fff;margin:3% auto;width:90%;max-width:1100px;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3);animation:modalIn .2s ease}
.media-modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 25px;border-bottom:2px solid #e2e8f0;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:12px 12px 0 0}
.media-modal-header h2{color:#1e293b;font-size:18px;margin:0}
.media-close-btn{font-size:28px;font-weight:700;color:#64748b;cursor:pointer;line-height:1}.media-close-btn:hover{color:#dc2626}
.media-search-bar{padding:12px 25px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center}
.media-search-box{flex:1;padding:10px 14px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none}.media-search-box:focus{border-color:#2563eb}
.media-grid-container{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:15px;padding:20px 25px;max-height:450px;overflow-y:auto;background:#f8fafc}
.media-item{background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,.05);border:2px solid transparent;cursor:pointer;position:relative;transition:all .2s}
.media-item:hover{transform:translateY(-2px);box-shadow:0 6px 12px rgba(0,0,0,.1);border-color:#2563eb}
.media-item.selected{border-color:#059669;background:#f0fdf4}
.media-preview-img{width:100%;height:130px;object-fit:cover;display:block;background:#f1f5f9}
.media-item-info{padding:8px 10px}
.media-item-name{font-size:10px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-item-tags{font-size:9px;color:#7c3aed;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.media-item-size{font-size:9px;color:#94a3b8;margin-top:2px}
.media-check{position:absolute;top:8px;right:8px;width:22px;height:22px;background:#059669;color:#fff;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:12px;font-weight:700}
.media-item.selected .media-check{display:flex}
.media-modal-footer{display:flex;justify-content:flex-end;gap:12px;padding:15px 25px;border-top:2px solid #e2e8f0;background:#f8fafc;border-radius:0 0 12px 12px;align-items:center}
.media-selection-info{flex:1;font-size:12px;color:#475569}
.media-footer-btn{padding:10px 24px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.media-cancel-btn{background:#e2e8f0;color:#475569}.media-cancel-btn:hover{background:#cbd5e1}
.media-select-btn{background:#059669;color:#fff}.media-select-btn:hover{background:#047857}
.media-select-btn:disabled{opacity:.5;cursor:not-allowed}
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
            <a href="vidora.php ">Contents</a>
			<a href="image_gen.php " class="active">Images</a>
            <a href="videomaker.php">Video</a>
            <a href="podcast_translator.php">Translate</a>
            <a href="publisher/dashboard.php">Schedule</a>
        </nav>
    </header>
<div class="card">
<h1>🎬 Podcast Video Generator</h1>
<p class="sub">Generate AI images for podcast scenes — with smart hashtag reuse</p>
<div style="margin-bottom:15px">
<label style="font-weight:600;display:block;margin-bottom:5px">Select English Podcast:</label>
<select id="pick">
<option value="">-- Choose Podcast --</option>
<?php while($p = mysqli_fetch_assoc($podcasts_result)): ?>
<option value="<?=$p['id']?>"><?=htmlspecialchars($p['title'])?> (ID:<?=$p['id']?>)</option>
<?php endwhile; ?>
</select>
</div>
</div>

<div class="card" id="scenesCard" style="display:none">
<div class="top-bar">
    <div>
        <h3 style="margin:0" id="scenesTitle">Scenes</h3>
        <div class="scene-count" id="sceneCount"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-all" id="genAllBtn" onclick="generateAll()" disabled>🚀 Generate All Images</button>
        <button class="btn" style="background:#dc2626;display:none" id="stopBtn" onclick="STOP=true;this.innerText='🛑 Stopping...'">🛑 Stop</button>
        <button class="btn" style="background:#64748b" onclick="document.getElementById('logBox').value=''">🗑️ Clear Log</button>
    </div>
</div>

<div id="progressWrap" style="display:none">
    <div class="pb"><div class="pf" id="pf"></div></div>
    <div style="text-align:center;font-size:12px;color:#64748b" id="pt">0/0</div>
</div>

<div style="margin-bottom:15px">
    <label style="font-weight:700;font-size:12px;color:#334155;display:block;margin-bottom:5px">📋 Progress & Error Log:</label>
    <textarea id="logBox" readonly style="width:100%;height:200px;background:#0f172a;color:#a5f3fc;font-family:'Courier New',monospace;font-size:11.5px;padding:12px;border-radius:8px;border:2px solid #334155;resize:vertical;line-height:1.7;white-space:pre-wrap;outline:none;" placeholder="Waiting for actions..."></textarea>
</div>

<div style="overflow-x:auto">
<table>
<thead>
<tr><th>#</th><th>ID</th><th>Image</th><th>Prompt</th><th>Text (Preview)</th><th>Status</th><th>Action</th></tr>
</thead>
<tbody id="scenesBody"></tbody>
</table>
</div>
</div>

<!-- Image Preview Modal -->
<div class="modal-overlay" id="imgModal" onclick="closePreview(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closePreview()">&times;</button>
        <img id="modalImg" src="" alt="Preview">
        <div class="modal-info" id="modalInfo"></div>
    </div>
</div>

<!-- Media Library Modal -->
<div id="mediaLibModal" class="media-modal">
    <div class="media-modal-content">
        <div class="media-modal-header">
            <h2>📁 Select Image for Scene <span id="editSceneId"></span></h2>
            <span class="media-close-btn" onclick="closeMediaLib()">&times;</span>
        </div>
        <div class="media-search-bar">
            <input type="text" id="mediaSearchInput" class="media-search-box" placeholder="Search by hashtag or filename..." onkeyup="filterMediaItems()">
            <span id="mediaResultCount" style="font-size:11px;color:#64748b;margin-left:10px"></span>
        </div>
        <div id="mediaGrid" class="media-grid-container">
            <div style="text-align:center;padding:40px;color:#94a3b8">Select a scene to browse images</div>
        </div>
        <div class="media-modal-footer">
            <div class="media-selection-info" id="mediaSelInfo">No image selected</div>
            <button class="media-footer-btn media-cancel-btn" onclick="closeMediaLib()">Cancel</button>
            <button class="media-footer-btn media-select-btn" id="mediaSelectBtn" onclick="confirmMediaSelect()" disabled>✅ Use This Image</button>
        </div>
    </div>
</div>

<script>
let scenes = [], totalGen = 0, doneGen = 0, STOP = false;

document.getElementById('pick').onchange = async function() {
    const id = this.value;
    const card = document.getElementById('scenesCard');
    if (!id) { card.style.display = 'none'; return; }
    
    card.style.display = 'block';
    document.getElementById('scenesTitle').innerText = this.options[this.selectedIndex].text;
    document.getElementById('scenesBody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">⏳ Loading scenes...</td></tr>';
    
    try {
        L('📥 Loading scenes for podcast ID: ' + id + '...');
        const fd = new FormData();
        fd.append('ajax_action', 'get_scenes');
        fd.append('podcast_id', id);
        const {data, raw} = await safeFetch(fd);
        scenes = data;
        L('✅ Loaded ' + scenes.length + ' scenes');
        renderTable();
    } catch(e) {
        L('❌ LOAD FAILED: ' + e.message);
        document.getElementById('scenesBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#dc2626;padding:20px">❌ Error loading scenes.</td></tr>';
    }
};

function renderTable() {
    const body = document.getElementById('scenesBody');
    document.getElementById('sceneCount').innerText = scenes.length + ' scenes found';
    document.getElementById('genAllBtn').disabled = scenes.length === 0;
    
    if (!scenes.length) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748b">No scenes found</td></tr>';
        return;
    }
    
    let html = '';
    scenes.forEach((s, i) => {
        const hasImage = s.image_file && s.image_file.trim() !== '';
        const imgHtml = hasImage 
            ? '<img src="podcast_images/' + s.image_file + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + s.image_file + '\', ' + s.id + ')" onerror="this.src=\'\';this.alt=\'Missing\'" id="img-' + s.id + '">' 
            : '<span style="color:#94a3b8;font-size:11px" id="img-' + s.id + '">No image</span>';
        
        const statusHtml = hasImage 
            ? '<span class="status-badge st-done" id="st-' + s.id + '">✅ Has Image</span>'
            : '<span class="status-badge st-pending" id="st-' + s.id + '">⏳ Pending</span>';
        
        const promptPreview = s.prompt ? s.prompt.substring(0, 100) + (s.prompt.length > 100 ? '...' : '') : '<em style="color:#94a3b8">No prompt</em>';
        const textPreview = s.text_contents ? s.text_contents.substring(0, 120) + (s.text_contents.length > 120 ? '...' : '') : '';
        
        html += '<tr id="row-' + s.id + '">' +
            '<td>' + (i + 1) + '</td>' +
            '<td>' + s.id + '</td>' +
            '<td>' + imgHtml + '</td>' +
            '<td><div class="prompt-text" id="prompt-' + s.id + '">' + promptPreview + '</div></td>' +
            '<td><div class="prompt-text">' + textPreview + '</div></td>' +
            '<td>' + statusHtml + '</td>' +
            '<td style="white-space:nowrap">' +
                '<button class="btn btn-gen" id="btn-' + s.id + '" onclick="genOne(' + s.id + ', ' + i + ')">🎨 Gen</button> ' +
                '<button class="btn btn-edit" onclick="openMediaLib(' + s.id + ')">📁 Edit</button>' +
            '</td>' +
        '</tr>';
    });
    body.innerHTML = html;
}

function L(m) {
    const b = document.getElementById('logBox');
    const ts = new Date().toLocaleTimeString();
    b.value += '[' + ts + '] ' + m + '\n';
    b.scrollTop = b.scrollHeight;
}

function updateProgress() {
    const p = totalGen > 0 ? (doneGen / totalGen * 100) : 0;
    document.getElementById('pf').style.width = p + '%';
    document.getElementById('pt').innerText = doneGen + '/' + totalGen + ' (' + Math.round(p) + '%)';
}

async function safeFetch(fd) {
    const r = await fetch(location.href, {method:'POST', body:fd});
    const raw = await r.text();
    try {
        return { data: JSON.parse(raw), raw: raw };
    } catch(e) {
        throw new Error('Server returned non-JSON. Raw response:\n' + raw.substring(0, 800));
    }
}

// ---- Image Preview Modal ----
function openPreview(src, sceneId) {
    document.getElementById('modalImg').src = src + '?t=' + Date.now();
    document.getElementById('modalInfo').innerText = 'Scene ID: ' + sceneId + ' | ' + src;
    document.getElementById('imgModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closePreview(e) {
    if (e && e.target !== document.getElementById('imgModal')) return;
    document.getElementById('imgModal').classList.remove('open');
    document.getElementById('modalImg').src = '';
    document.body.style.overflow = '';
}

// ---- Media Library Modal ----
let editingSceneId = null, selectedMediaFile = null;

async function openMediaLib(sceneId) {
    editingSceneId = sceneId;
    selectedMediaFile = null;
    document.getElementById('editSceneId').innerText = '#' + sceneId;
    document.getElementById('mediaSelectBtn').disabled = true;
    document.getElementById('mediaSelInfo').innerText = 'No image selected';
    document.getElementById('mediaSearchInput').value = '';
    document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#64748b">⏳ Loading images...</div>';
    document.getElementById('mediaLibModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_media_library');
        fd.append('scene_id', sceneId);
        const {data} = await safeFetch(fd);
        renderMediaGrid(data);
    } catch(e) {
        document.getElementById('mediaGrid').innerHTML = '<div style="text-align:center;padding:40px;color:#dc2626">❌ ' + e.message + '</div>';
    }
}

function renderMediaGrid(images) {
    const grid = document.getElementById('mediaGrid');
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8">No images found</div>';
        document.getElementById('mediaResultCount').innerText = '0 images';
        return;
    }
    document.getElementById('mediaResultCount').innerText = images.length + ' images';
    
    let html = '';
    images.forEach(function(img) {
        const tags = img.hashtags || '';
        const name = img.image_name;
        const size = img.file_size ? (img.file_size / 1024).toFixed(0) + ' KB' : '';
        html += '<div class="media-item" data-file="' + name + '" data-tags="' + tags + '" onclick="selectMediaItem(this, \'' + name + '\')">' +
            '<img src="podcast_images/' + name + '" class="media-preview-img" onerror="this.alt=\'Missing\'" loading="lazy">' +
            '<div class="media-item-info">' +
                '<div class="media-item-name" title="' + name + '">' + name + '</div>' +
                (tags ? '<div class="media-item-tags">🏷️ ' + tags + '</div>' : '') +
                '<div class="media-item-size">' + size + '</div>' +
            '</div>' +
            '<div class="media-check">✓</div>' +
        '</div>';
    });
    grid.innerHTML = html;
}

function selectMediaItem(el, fileName) {
    document.querySelectorAll('#mediaGrid .media-item').forEach(function(i) { i.classList.remove('selected'); });
    el.classList.add('selected');
    selectedMediaFile = fileName;
    document.getElementById('mediaSelInfo').innerHTML = '✅ Selected: <b>' + fileName + '</b>';
    document.getElementById('mediaSelectBtn').disabled = false;
}

function filterMediaItems() {
    const term = document.getElementById('mediaSearchInput').value.toLowerCase();
    let visible = 0;
    document.querySelectorAll('#mediaGrid .media-item').forEach(function(item) {
        const name = (item.dataset.file || '').toLowerCase();
        const tags = (item.dataset.tags || '').toLowerCase();
        if (name.includes(term) || tags.includes(term)) {
            item.style.display = '';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });
    document.getElementById('mediaResultCount').innerText = visible + ' images';
}

async function confirmMediaSelect() {
    if (!selectedMediaFile || !editingSceneId) return;
    
    L('📁 Assigning image ' + selectedMediaFile + ' to scene #' + editingSceneId);
    
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_scene');
        fd.append('scene_id', editingSceneId);
        fd.append('image_file', selectedMediaFile);
        fd.append('prompt', '');
        const {data: d} = await safeFetch(fd);
        if (!d.success) throw new Error(d.message);
        
        document.getElementById('img-' + editingSceneId).outerHTML = '<img src="podcast_images/' + selectedMediaFile + '?t=' + Date.now() + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + selectedMediaFile + '\', ' + editingSceneId + ')" id="img-' + editingSceneId + '">';
        document.getElementById('st-' + editingSceneId).className = 'status-badge st-reused';
        document.getElementById('st-' + editingSceneId).innerText = '📁 Selected';
        
        var scene = scenes.find(function(s) { return parseInt(s.id) === editingSceneId; });
        if (scene) scene.image_file = selectedMediaFile;
        
        L('✅ Scene #' + editingSceneId + ' updated with ' + selectedMediaFile);
    } catch(e) {
        L('❌ Update failed: ' + e.message);
        alert('Failed to update: ' + e.message);
    }
    
    closeMediaLib();
}

function closeMediaLib() {
    document.getElementById('mediaLibModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ---- Generate Single Scene ----
async function genOne(sceneId, index) {
    const scene = scenes.find(function(s) { return parseInt(s.id) === sceneId; });
    if (!scene) { L('❌ Scene ' + sceneId + ' not found'); return; }
    
    const btn = document.getElementById('btn-' + sceneId);
    const st = document.getElementById('st-' + sceneId);
    btn.disabled = true;
    btn.innerHTML = '⏳ Working...';
    st.className = 'status-badge st-working';
    st.innerText = '🔄 Enhancing...';
    
    const originalPrompt = (scene.prompt && scene.prompt.trim() !== '') ? scene.prompt : scene.text_contents;
    
    if (!originalPrompt || originalPrompt.trim() === '') {
        L('❌ Scene #' + sceneId + ': No prompt or text_contents');
        st.className = 'status-badge st-error';
        st.innerText = '❌ No prompt';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen';
        return;
    }

    L('\n━━━ SCENE #' + sceneId + ' ━━━');
    L('📝 Original prompt: ' + originalPrompt.substring(0, 150) + '...');
    
    // Step 1: Enhance prompt
    var enhanced, hashtags;
    try {
        L('🔄 Step 1: Sending to ChatGPT for prompt enhancement...');
        var fd = new FormData();
        fd.append('ajax_action', 'enhance_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', originalPrompt);
        var res = await safeFetch(fd);
        if (!res.data.success) throw new Error(res.data.message || 'Unknown error. Raw: ' + res.raw.substring(0, 300));
        enhanced = res.data.enhanced_prompt;
        hashtags = res.data.hashtags;
        L('✅ Enhanced prompt ready (' + enhanced.length + ' chars)');
        L('🏷️ Hashtags: ' + hashtags);
    } catch(e) {
        L('❌ ENHANCE FAILED: ' + e.message);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Error';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen';
        return;
    }
    
    // Step 2: Check for existing image
    st.innerText = '🔍 Checking library...';
    var imageName = null, reused = false;
    
    try {
        L('🔍 Step 2: Checking hdb_image_data for matching hashtags...');
        var fd2 = new FormData();
        fd2.append('ajax_action', 'check_image_data');
        fd2.append('hashtags', hashtags);
        var res2 = await safeFetch(fd2);
        if (res2.data.found) {
            imageName = res2.data.image_name;
            reused = true;
            L('♻️ MATCH FOUND! Reusing: ' + imageName);
        } else {
            L('🆕 No match — will generate new image');
        }
    } catch(e) {
        L('⚠️ Image check error: ' + e.message);
    }
    
    // Step 3: Generate new image if not found
    if (!imageName) {
        st.innerText = '🎨 Generating image...';
        try {
            L('🎨 Step 3: Calling gpt-image-1 API...');
            L('   📝 Prompt: ' + enhanced.substring(0, 200) + '...');
            L('   🏷️ Hashtags: ' + hashtags);
            var fd3 = new FormData();
            fd3.append('ajax_action', 'generate_image');
            fd3.append('scene_id', sceneId);
            fd3.append('enhanced_prompt', enhanced);
            fd3.append('hashtags', hashtags);
            var res3 = await safeFetch(fd3);
            if (!res3.data.success) throw new Error((res3.data.step ? '[' + res3.data.step + '] ' : '') + (res3.data.message || 'Raw: ' + res3.raw.substring(0, 500)));
            imageName = res3.data.image_name;
            L('   ✅ Image generated: ' + imageName);
            L('   📁 File saved: ' + (res3.data.file_path || 'N/A'));
            L('   📏 File size: ' + (res3.data.file_size ? (res3.data.file_size / 1024).toFixed(1) + ' KB' : 'N/A'));
            if (res3.data.db_inserted) L('   💾 hdb_image_data: INSERT OK ✅');
            else if (res3.data.db_warning) L('   ⚠️ DB WARNING: ' + res3.data.db_warning);
            if (res3.data.table_columns) L('   📋 Columns: ' + res3.data.table_columns.join(', '));
        } catch(e) {
            L('❌ IMAGE GENERATION FAILED: ' + e.message);
            st.className = 'status-badge st-error';
            st.innerText = '❌ Gen Failed';
            btn.disabled = false;
            btn.innerHTML = '🎨 Gen';
            return;
        }
    }
    
    // Step 4: Update scene in DB
    st.innerText = '💾 Saving...';
    try {
        L('💾 Step 4: Updating hdb_podcast_stories...');
        var fd4 = new FormData();
        fd4.append('ajax_action', 'update_scene');
        fd4.append('scene_id', sceneId);
        fd4.append('image_file', imageName);
        fd4.append('prompt', enhanced);
        var res4 = await safeFetch(fd4);
        if (!res4.data.success) throw new Error(res4.data.message || 'Raw: ' + res4.raw.substring(0, 300));
        
        document.getElementById('img-' + sceneId).outerHTML = '<img src="podcast_images/' + imageName + '?t=' + Date.now() + '" class="img-thumb" onclick="openPreview(\'podcast_images/' + imageName + '\', ' + sceneId + ')" id="img-' + sceneId + '">';
        document.getElementById('prompt-' + sceneId).innerText = enhanced.substring(0, 100) + '...';
        st.className = reused ? 'status-badge st-reused' : 'status-badge st-done';
        st.innerText = reused ? '♻️ Reused' : '✅ Generated';
        L('✅ Scene #' + sceneId + ' COMPLETE! ' + (reused ? '(reused)' : '(new image)'));
        
        scene.image_file = imageName;
        scene.prompt = enhanced;
    } catch(e) {
        L('❌ DB UPDATE FAILED: ' + e.message);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Update Failed';
    }
    
    btn.disabled = false;
    btn.innerHTML = '🎨 Gen';
}

// ---- Generate All ----
async function generateAll() {
    const btn = document.getElementById('genAllBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Generating All...';
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('stopBtn').style.display = 'inline-flex';
    
    const pending = scenes.filter(function(s) { return !s.image_file || s.image_file.trim() === ''; });
    
    if (pending.length === 0) {
        L('✅ All scenes already have images!');
        btn.disabled = false;
        btn.innerHTML = '🚀 Generate All Images';
        return;
    }
    
    totalGen = pending.length;
    doneGen = 0;
    STOP = false;
    updateProgress();
    
    L('\n🚀 BATCH START: ' + pending.length + ' scenes without images');
    
    for (var i = 0; i < pending.length; i++) {
        if (STOP) { L('🛑 STOPPED BY USER'); break; }
        var s = pending[i];
        var idx = scenes.findIndex(function(sc) { return sc.id === s.id; });
        await genOne(parseInt(s.id), idx);
        doneGen++;
        updateProgress();
        if (i < pending.length - 1 && !STOP) {
            L('⏳ Waiting 2s...');
            await new Promise(function(r) { setTimeout(r, 2000); });
        }
    }
    
    L('\n🎉 BATCH COMPLETE: ' + doneGen + '/' + totalGen + (STOP ? ' (stopped early)' : ''));
    btn.disabled = false;
    btn.innerHTML = '🚀 Generate All Images';
    document.getElementById('stopBtn').style.display = 'none';
}

// ---- Keyboard shortcuts ----
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePreview();
        closeMediaLib();
    }
});

// Close media modal on backdrop click
document.getElementById('mediaLibModal').addEventListener('click', function(e) {
    if (e.target === this) closeMediaLib();
});
</script>
</body>
</html>
