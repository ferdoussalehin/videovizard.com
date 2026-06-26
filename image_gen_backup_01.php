<?php
session_start();

if(!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include 'dbconnect_hdb.php';
require_once 'chatgpt_functions.php';
require_once 'generate_image_api.php'; // Your generateAndSaveImage function

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
    // Clean any stray output from includes
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

    // Split hashtags and search for any match
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

    $api_key  ="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";
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'message' => '$api_key not set. Check chatgpt_functions.php', 'step' => 'api_key']);
        exit;
    }

    // Step A: Generate unique 10-digit image name
    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    $image_folder = __DIR__ . '/podcast_images';
    while (file_exists($image_folder . '/' . $image_name_base . '.png')) {
        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    $image_name = $image_name_base . '.png';

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step A: image_name=$image_name | folder=$image_folder\n", 3, __DIR__ . "/a_debug.log");

    // Step B: Call generateAndSaveImage
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B: Calling gpt-image-1 API...\n", 3, __DIR__ . "/a_debug.log");
    
    $result = generateAndSaveImage($enhanced_prompt, $image_name_base, "1024x1536", $image_folder, $api_key);

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B result: " . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");

    if (!$result['success']) {
        echo json_encode(['success' => false, 'message' => $result['message'], 'step' => 'generate_image']);
        exit;
    }

    // Step C: Verify file exists
    $full_path = $result['filepath'];
    $file_exists = file_exists($full_path);
    $file_size = $file_exists ? filesize($full_path) : 0;
    
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step C: file_exists=" . ($file_exists ? 'YES' : 'NO') . " | size={$file_size} bytes | path=$full_path\n", 3, __DIR__ . "/a_debug.log");

    if (!$file_exists || $file_size < 1000) {
        echo json_encode(['success' => false, 'message' => "Image file missing or too small ({$file_size} bytes). Path: $full_path", 'step' => 'verify_file']);
        exit;
    }

    // Step D: Insert into hdb_image_data
    $esc_name = mysqli_real_escape_string($conn, $image_name);
    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);
    $esc_prompt = mysqli_real_escape_string($conn, $enhanced_prompt);
    
    $table_cols = [];
    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");
    if (!$col_check) {
        $db_warning = 'hdb_image_data table not found: ' . mysqli_error($conn);
        error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step D: $db_warning\n", 3, __DIR__ . "/a_debug.log");
        echo json_encode(['success' => true, 'image_name' => $image_name, 'file_size' => $file_size, 'db_warning' => $db_warning, 'step' => 'db_table_missing']);
        exit;
    }
    while ($c = mysqli_fetch_assoc($col_check)) $table_cols[] = $c['Field'];
    
    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step D: table columns = " . implode(', ', $table_cols) . "\n", 3, __DIR__ . "/a_debug.log");
    
    $insert_map = [];
    if (in_array('image_name', $table_cols))     $insert_map['image_name'] = "'$esc_name'";
    if (in_array('image_hashtags', $table_cols))  $insert_map['image_hashtags'] = "'$esc_hashtags'";
    if (in_array('image_prompt', $table_cols))    $insert_map['image_prompt'] = "'$esc_prompt'";
    if (in_array('created_at', $table_cols))      $insert_map['created_at'] = "NOW()";
    if (in_array('name', $table_cols) && !isset($insert_map['image_name']))           $insert_map['name'] = "'$esc_name'";
    if (in_array('hashtags', $table_cols) && !isset($insert_map['image_hashtags']))    $insert_map['hashtags'] = "'$esc_hashtags'";
    if (in_array('prompt', $table_cols) && !isset($insert_map['image_prompt']))        $insert_map['prompt'] = "'$esc_prompt'";
    if (in_array('file_name', $table_cols) && !isset($insert_map['image_name']))       $insert_map['file_name'] = "'$esc_name'";
    if (in_array('filename', $table_cols) && !isset($insert_map['image_name']))        $insert_map['filename'] = "'$esc_name'";
    
    $db_warning = '';
    $db_inserted = false;
    
    if (empty($insert_map)) {
        $db_warning = 'No matching columns in hdb_image_data. Found columns: ' . implode(', ', $table_cols);
    } else {
        $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";
        error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step D SQL: $ins_sql\n", 3, __DIR__ . "/a_debug.log");
        
        if (!mysqli_query($conn, $ins_sql)) {
            $db_warning = 'INSERT failed: ' . mysqli_error($conn) . ' | Columns: ' . implode(', ', $table_cols);
        } else {
            $db_inserted = true;
            error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step D: INSERT OK!\n", 3, __DIR__ . "/a_debug.log");
        }
    }
    
    if ($db_warning) {
        error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step D WARNING: $db_warning\n", 3, __DIR__ . "/a_debug.log");
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

    $sql = "UPDATE hdb_podcast_stories SET image_file='$image_file', prompt='$prompt' WHERE id=$scene_id";
    if (mysqli_query($conn, $sql)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);
    }
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
.btn-all{background:#059669;padding:10px 24px;border-radius:8px;font-size:13px}.btn-all:hover:not(:disabled){background:#047857}
table{width:100%;border-collapse:collapse;margin-top:15px}
th{background:#f8fafc;padding:10px;text-align:left;font-size:11px;color:#64748b;border-bottom:2px solid #e2e8f0;text-transform:uppercase;letter-spacing:.5px}
td{padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:top}
tr:hover{background:#f8fafc}
.img-thumb{width:45px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0}
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
.img-thumb{cursor:pointer;transition:opacity .2s}.img-thumb:hover{opacity:.8}
.modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.85);z-index:9999;justify-content:center;align-items:center;cursor:pointer}
.modal-overlay.open{display:flex}
.modal-content{position:relative;max-height:90vh;max-width:90vw;animation:modalIn .2s ease}
.modal-content img{max-height:85vh;max-width:85vw;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.5)}
.modal-close{position:absolute;top:-12px;right:-12px;width:32px;height:32px;background:#fff;border:none;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);color:#333;font-weight:700}
.modal-close:hover{background:#f1f1f1}
.modal-info{text-align:center;color:#94a3b8;font-size:11px;margin-top:10px;font-family:'Segoe UI',sans-serif}
@keyframes modalIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
</style>
</head>
<body>

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

<!-- Scenes Table Card -->
<div class="card" id="scenesCard" style="display:none">
<div class="top-bar">
    <div>
        <h3 style="margin:0" id="scenesTitle">Scenes</h3>
        <div class="scene-count" id="sceneCount"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
        <button class="btn btn-all" id="genAllBtn" onclick="generateAll()" disabled>🚀 Generate All Images</button>
        <button class="btn" style="background:#dc2626" id="stopBtn" onclick="STOP=true;this.innerText='🛑 Stopping...'" style="display:none">🛑 Stop</button>
        <button class="btn" style="background:#64748b" onclick="document.getElementById('logBox').value=''">🗑️ Clear Log</button>
    </div>
</div>

<!-- Progress bar -->
<div id="progressWrap" style="display:none">
    <div class="pb"><div class="pf" id="pf"></div></div>
    <div style="text-align:center;font-size:12px;color:#64748b" id="pt">0/0</div>
</div>

<!-- PROGRESS / ERROR LOG TEXTAREA -->
<div style="margin-bottom:15px">
    <label style="font-weight:700;font-size:12px;color:#334155;display:block;margin-bottom:5px">📋 Progress & Error Log:</label>
    <textarea id="logBox" readonly style="
        width:100%;height:200px;background:#0f172a;color:#a5f3fc;font-family:'Courier New',monospace;
        font-size:11.5px;padding:12px;border-radius:8px;border:2px solid #334155;resize:vertical;
        line-height:1.7;white-space:pre-wrap;outline:none;
    " placeholder="Waiting for actions... Click 'Gen Video' on any row to begin."></textarea>
</div>

<div style="overflow-x:auto">
<table>
<thead>
<tr>
    <th>#</th>
    <th>ID</th>
    <th>Image</th>
    <th>Prompt</th>
    <th>Text (Preview)</th>
    <th>Status</th>
    <th>Action</th>
</tr>
</thead>
<tbody id="scenesBody"></tbody>
</table>
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
        L(`📥 Loading scenes for podcast ID: ${id}...`);
        const fd = new FormData();
        fd.append('ajax_action', 'get_scenes');
        fd.append('podcast_id', id);
        const {data, raw} = await safeFetch(fd);
        scenes = data;
        L(`✅ Loaded ${scenes.length} scenes`);
        renderTable();
    } catch(e) {
        L(`❌ LOAD FAILED: ${e.message}`);
        document.getElementById('scenesBody').innerHTML = `<tr><td colspan="7" style="text-align:center;color:#dc2626;padding:20px">❌ Error loading scenes. Check log above for details.</td></tr>`;
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
            ? `<img src="podcast_images/${s.image_file}" class="img-thumb" onclick="openModal('podcast_images/${s.image_file}', ${s.id})" onerror="this.src='';this.alt='Missing'" id="img-${s.id}">` 
            : `<span style="color:#94a3b8;font-size:11px" id="img-${s.id}">No image</span>`;
        
        const statusHtml = hasImage 
            ? `<span class="status-badge st-done" id="st-${s.id}">✅ Has Image</span>`
            : `<span class="status-badge st-pending" id="st-${s.id}">⏳ Pending</span>`;
        
        const promptPreview = s.prompt ? s.prompt.substring(0, 100) + (s.prompt.length > 100 ? '...' : '') : '<em style="color:#94a3b8">No prompt</em>';
        const textPreview = s.text_contents ? s.text_contents.substring(0, 120) + (s.text_contents.length > 120 ? '...' : '') : '';
        
        html += `<tr id="row-${s.id}">
            <td>${i + 1}</td>
            <td>${s.id}</td>
            <td>${imgHtml}</td>
            <td><div class="prompt-text" id="prompt-${s.id}">${promptPreview}</div></td>
            <td><div class="prompt-text">${textPreview}</div></td>
            <td>${statusHtml}</td>
            <td><button class="btn btn-gen" id="btn-${s.id}" onclick="genOne(${s.id}, ${i})">🎨 Gen Video</button></td>
        </tr>`;
    });
    body.innerHTML = html;
}

function L(m) {
    const b = document.getElementById('logBox');
    const ts = new Date().toLocaleTimeString();
    b.value += `[${ts}] ${m}\n`;
    b.scrollTop = b.scrollHeight;
}

function updateProgress() {
    const p = totalGen > 0 ? (doneGen / totalGen * 100) : 0;
    document.getElementById('pf').style.width = p + '%';
    document.getElementById('pt').innerText = `${doneGen}/${totalGen} (${Math.round(p)}%)`;
}

// Helper: fetch with full error capture
async function safeFetch(fd) {
    const r = await fetch(location.href, {method:'POST', body:fd});
    const raw = await r.text();
    // Try parse JSON, if fails show raw server output
    try {
        return { data: JSON.parse(raw), raw: raw };
    } catch(e) {
        throw new Error('Server returned non-JSON. Raw response:\n' + raw.substring(0, 800));
    }
}

// ---------- Generate Single Scene ----------
async function genOne(sceneId, index) {
    const scene = scenes.find(s => parseInt(s.id) === sceneId);
    if (!scene) { L('❌ ERROR: Scene ' + sceneId + ' not found in local data'); return; }
    
    const btn = document.getElementById('btn-' + sceneId);
    const st = document.getElementById('st-' + sceneId);
    btn.disabled = true;
    btn.innerHTML = '⏳ Working...';
    st.className = 'status-badge st-working';
    st.innerText = '🔄 Enhancing...';
    
    const originalPrompt = (scene.prompt && scene.prompt.trim() !== '') ? scene.prompt : scene.text_contents;
    
    if (!originalPrompt || originalPrompt.trim() === '') {
        L(`❌ Scene #${sceneId}: No prompt or text_contents to work with`);
        st.className = 'status-badge st-error';
        st.innerText = '❌ No prompt';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen Video';
        return;
    }

    L(`\n━━━ SCENE #${sceneId} ━━━`);
    L(`📝 Original prompt: ${originalPrompt.substring(0, 150)}...`);
    
    // Step 1: Enhance prompt & get hashtags
    let enhanced, hashtags;
    try {
        L('🔄 Step 1: Sending to ChatGPT for prompt enhancement...');
        const fd = new FormData();
        fd.append('ajax_action', 'enhance_prompt');
        fd.append('scene_id', sceneId);
        fd.append('prompt', originalPrompt);
        const {data: d, raw} = await safeFetch(fd);
        if (!d.success) throw new Error(d.message || 'Unknown error. Raw: ' + raw.substring(0, 300));
        enhanced = d.enhanced_prompt;
        hashtags = d.hashtags;
        L(`✅ Enhanced prompt ready (${enhanced.length} chars)`);
        L(`🏷️ Hashtags: ${hashtags}`);
    } catch(e) {
        L(`❌ ENHANCE FAILED: ${e.message}`);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Error';
        btn.disabled = false;
        btn.innerHTML = '🎨 Gen Video';
        return;
    }
    
    // Step 2: Check hdb_image_data for existing image
    st.innerText = '🔍 Checking library...';
    let imageName = null;
    let reused = false;
    
    try {
        L('🔍 Step 2: Checking hdb_image_data for matching hashtags...');
        const fd = new FormData();
        fd.append('ajax_action', 'check_image_data');
        fd.append('hashtags', hashtags);
        const {data: d} = await safeFetch(fd);
        if (d.found) {
            imageName = d.image_name;
            reused = true;
            L(`♻️ MATCH FOUND! Reusing image: ${imageName} (existing tags: ${d.image_hashtags})`);
        } else {
            L(`🆕 No matching image found — will generate new one`);
        }
    } catch(e) {
        L(`⚠️ Image check error (will generate new): ${e.message}`);
    }
    
    // Step 3: Generate new image if not found
    if (!imageName) {
        st.innerText = '🎨 Generating image...';
        try {
            L('🎨 Step 3: Calling gpt-image-1 API to generate image...');
            L('   📝 Prompt: ' + enhanced.substring(0, 200) + '...');
            L('   🏷️ Hashtags: ' + hashtags);
            const fd = new FormData();
            fd.append('ajax_action', 'generate_image');
            fd.append('scene_id', sceneId);
            fd.append('enhanced_prompt', enhanced);
            fd.append('hashtags', hashtags);
            const {data: d, raw} = await safeFetch(fd);
            if (!d.success) throw new Error((d.step ? '[' + d.step + '] ' : '') + (d.message || 'Unknown error. Raw: ' + raw.substring(0, 500)));
            imageName = d.image_name;
            L('   ✅ Image generated: ' + imageName);
            L('   📁 File saved: ' + (d.file_path || 'N/A'));
            L('   📏 File size: ' + (d.file_size ? (d.file_size / 1024).toFixed(1) + ' KB' : 'N/A'));
            
            // Step 3b: DB insert status
            if (d.db_inserted) {
                L('   💾 hdb_image_data: INSERT OK ✅');
            } else if (d.db_warning) {
                L('   ⚠️ hdb_image_data WARNING: ' + d.db_warning);
            }
            if (d.table_columns && d.table_columns.length > 0) {
                L('   📋 Table columns found: ' + d.table_columns.join(', '));
            }
        } catch(e) {
            L('❌ IMAGE GENERATION FAILED: ' + e.message);
            st.className = 'status-badge st-error';
            st.innerText = '❌ Gen Failed';
            btn.disabled = false;
            btn.innerHTML = '🎨 Gen Video';
            return;
        }
    }
    
    // Step 4: Update scene row in DB
    st.innerText = '💾 Saving...';
    try {
        L('💾 Step 4: Updating hdb_podcast_stories row...');
        const fd = new FormData();
        fd.append('ajax_action', 'update_scene');
        fd.append('scene_id', sceneId);
        fd.append('image_file', imageName);
        fd.append('prompt', enhanced);
        const {data: d, raw} = await safeFetch(fd);
        if (!d.success) throw new Error(d.message || 'Unknown error. Raw: ' + raw.substring(0, 300));
        
        // Update UI
        document.getElementById('img-' + sceneId).outerHTML = `<img src="podcast_images/${imageName}?t=${Date.now()}" class="img-thumb" onclick="openModal('podcast_images/${imageName}', ${sceneId})" id="img-${sceneId}">`;
        document.getElementById('prompt-' + sceneId).innerText = enhanced.substring(0, 100) + '...';
        st.className = reused ? 'status-badge st-reused' : 'status-badge st-done';
        st.innerText = reused ? '♻️ Reused' : '✅ Generated';
        L(`✅ Scene #${sceneId} COMPLETE! ${reused ? '(reused existing image)' : '(new image generated)'}`);
        
        scene.image_file = imageName;
        scene.prompt = enhanced;
        
    } catch(e) {
        L(`❌ DB UPDATE FAILED: ${e.message}`);
        st.className = 'status-badge st-error';
        st.innerText = '❌ Update Failed';
    }
    
    btn.disabled = false;
    btn.innerHTML = '🎨 Gen Video';
}

// ---------- Generate All ----------
async function generateAll() {
    const btn = document.getElementById('genAllBtn');
    btn.disabled = true;
    btn.innerHTML = '⏳ Generating All...';
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('stopBtn').style.display = 'inline-flex';
    
    const pending = scenes.filter(s => !s.image_file || s.image_file.trim() === '');
    
    if (pending.length === 0) {
        L('✅ All scenes already have images — nothing to do!');
        btn.disabled = false;
        btn.innerHTML = '🚀 Generate All Images';
        return;
    }
    
    totalGen = pending.length;
    doneGen = 0;
    STOP = false;
    updateProgress();
    
    L(`\n🚀 BATCH START: ${pending.length} scenes without images (${scenes.length} total)`);
    
    for (let i = 0; i < pending.length; i++) {
        if (STOP) { L('🛑 STOPPED BY USER'); break; }
        
        const s = pending[i];
        const idx = scenes.findIndex(sc => sc.id === s.id);
        
        await genOne(parseInt(s.id), idx);
        doneGen++;
        updateProgress();
        
        if (i < pending.length - 1 && !STOP) {
            L('⏳ Waiting 2s before next scene...');
            await new Promise(r => setTimeout(r, 2000));
        }
    }
    
    L(`\n🎉 BATCH COMPLETE: ${doneGen}/${totalGen} processed` + (STOP ? ' (stopped early)' : ''));
    btn.disabled = false;
    btn.innerHTML = '🚀 Generate All Images';
    document.getElementById('stopBtn').style.display = 'none';
}
</script>
<!-- Image Modal -->
<div class="modal-overlay" id="imgModal" onclick="closeModal(event)">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <img id="modalImg" src="" alt="Preview">
        <div class="modal-info" id="modalInfo"></div>
    </div>
</div>

<script>
function openModal(src, sceneId) {
    const modal = document.getElementById('imgModal');
    const img = document.getElementById('modalImg');
    const info = document.getElementById('modalInfo');
    img.src = src + '?t=' + Date.now();
    info.innerText = 'Scene ID: ' + sceneId + ' | ' + src;
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(e) {
    if (e && e.target !== document.getElementById('imgModal')) return;
    document.getElementById('imgModal').classList.remove('open');
    document.getElementById('modalImg').src = '';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>
