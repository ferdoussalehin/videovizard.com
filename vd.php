<?php
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

// New handler for creating scenes from content
if (isset($_POST['action']) && $_POST['action'] == 'create_scenes_from_content') {
    $sm_id = mysqli_real_escape_string($conn, $_POST['sm_id']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $topic = mysqli_real_escape_string($conn, $_POST['topic']);
    $title = mysqli_real_escape_string($conn, $_POST['title']);
    $combined_title = mysqli_real_escape_string($conn, $_POST['combined_title']);
    $hook_id = (int)$_POST['hook_id'];
    $cta = mysqli_real_escape_string($conn, $_POST['cta']);
    $content = $_POST['content'];
    $scenes_prompt = $_POST['prompt'];
    $admin_id = $_SESSION['admin_id'];
    
    // First create the podcast record
    $p1_prompt = "Generate SQL INSERT for hdb_podcasts:
    category: '$category'
    topic_key: '$topic'
    title: '$combined_title'
    lang_code: 'en'
    admin_id: '$admin_id'
    hook_id: $hook_id
    
    Requirements:
    1. Generate 5-7 hashtags (lowercase, comma-separated, no spaces, starts with #)
    2. Generate 8-12 keywords (lowercase, comma-separated, no spaces)
    3. Generate short caption_text (max 200 chars)
    
    Output Format:
    INSERT INTO hdb_podcasts (category, topic_key, title, lang_code, admin_id, hook_id, hashtags, keywords, caption_text, created_date)
    VALUES ('$category', '$topic', '$combined_title', 'en', '$admin_id', $hook_id, '[hashtags]', '[keywords]', '[caption_text]', NOW());
    
    Output SQL ONLY.";

    $res1 = callChatGPT_inam($p1_prompt);
    $sql1 = trim($res1['response']);
    $sql1 = preg_replace('/^```(?:sql)?/i', '', $sql1);
    $sql1 = preg_replace('/```$/i', '', $sql1);
    $sql1 = trim($sql1);

    if (mysqli_query($conn, $sql1)) {
        $podcast_id = mysqli_insert_id($conn);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create podcast: ' . mysqli_error($conn)]);
        exit;
    }

    // Replace [podcast_id] in the scenes prompt
    $scenes_prompt = str_replace('[podcast_id]', $podcast_id, $scenes_prompt);
    
    // Call ChatGPT to generate scenes SQL
    $res2 = callChatGPT_inam($scenes_prompt);
    $sql2 = trim($res2['response']);
    $sql2 = preg_replace('/^```(?:sql)?/i', '', $sql2);
    $sql2 = preg_replace('/```$/i', '', $sql2);
    $sql2 = trim($sql2);

    // Execute the SQL
    if (mysqli_multi_query($conn, $sql2)) {
        $scene_count = 0;
        do {
            if ($result = mysqli_store_result($conn)) mysqli_free_result($result);
            $scene_count++;
        } while (mysqli_next_result($conn));
        
        // Clear remaining results
        while (mysqli_more_results($conn)) mysqli_next_result($conn);
        
        // Update social media status
        mysqli_query($conn, "UPDATE hdb_social_media SET sm_status='GENERATED', hook_id='$hook_id' WHERE id='$sm_id'");
        
        echo json_encode([
            'success' => true,
            'podcast_id' => $podcast_id,
            'scene_count' => $scene_count - 1
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create scenes: ' . mysqli_error($conn)]);
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
        .container { max-width: 1000px; margin: 20px auto; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 700; color: var(--dark-blue); }
        select, textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.9rem; }
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
            <a href="vidora.php">Contents</a>
            <a href="videomaker.php">Video</a>
            <a href="podcast_translator.php">Translate</a>
            <a href="publisher/dashboard.php">Schedule</a>
        </nav>
    </header>
    
    <div class="card">
        <h2 style="color:var(--dark-blue); margin-top:0;">🎙️ 2-Step Content Creator</h2>
        <p style="color: #64748b; margin-bottom: 20px;">Step 1: Generate content → Review/Edit → Step 2: Create scenes</p>
        
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
            <select id="sm_id_select" onchange="updatePrompt()"><option value="">-- Select Title --</option></select>
        </div>

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

        <div class="form-group">
            <label>CTA</label>
            <select id="cta_select" onchange="updatePrompt()">
                <?php 
                $ctas = mysqli_query($conn, "SELECT cta_en FROM hdb_social_media_cta");
                while ($ct = mysqli_fetch_assoc($ctas)) echo "<option value='".htmlspecialchars($ct['cta_en'])."'>".htmlspecialchars($ct['cta_en'])."</option>";
                ?>
            </select>
        </div>

        <!-- NEW: Generated Content Area -->
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
        
        <div id="status_msg" style="margin-top:15px; display:none; padding:15px; border-radius:8px; font-weight:600;"></div>
    </div>
</div>

<script>
// Create hooks array with IDs from PHP
const hooksArr = <?php echo json_encode($hooks_array); ?>;
let generatedPodcastId = null;

function loadTopics(cat) {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `get_topics=1&category=${cat}` })
    .then(r => r.text()).then(d => document.getElementById('topic_select').innerHTML = d);
}

function loadTitles(topic) {
    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `get_titles=1&topic=${topic}` })
    .then(r => r.text()).then(d => { 
        document.getElementById('sm_id_select').innerHTML = d; 
        setTimeout(updatePrompt, 100); 
    });
}

function updatePrompt() {
    const cat = document.getElementById('cat_select').value || "[category]";
    const topic = document.getElementById('topic_select').value || "[topic]";
    const ts = document.getElementById('sm_id_select');
    const originalTitle = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[original title]";
    
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
    
    const combinedTitle = `${hookId} - ${hookName}: ${originalTitle}`;
    const cta = document.getElementById('cta_select').value || "[cta_en]";

    document.getElementById('prompt_preview').value = `Create a hypnotherapy script for:
Category: ${cat}
Topic: ${topic}
Title: ${originalTitle}
Hook: ${hookName}
CTA: ${cta}

Write in warm, conversational tone with <break> tags for pauses.`;
}

// STEP 1: Generate only the content
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

    btn.disabled = true;
    btn.innerText = "⏳ Generating...";
    contentArea.value = "Generating content...";
    status.style.display = 'block';
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Creating your script...";

    const prompt = `Write a hypnotherapy script for a podcast episode.

TITLE: ${originalTitle}
CATEGORY: ${category}
TOPIC: ${topic}
HOOK: ${hookName}
CTA: ${cta}

REQUIREMENTS:
1. Write in a warm, conversational, calming tone
2. Use <break time="200ms"/> for brief pauses between sentences
3. Use <break time="300ms"/> for longer pauses between sections
4. Length: 600-800 words total
5. Structure:
   - Opening hook that grabs attention
   - Identify the problem/pain points
   - Offer CBT tips and insights
   - Provide hypnotherapy solution
   - Strong closing with CTA

OUTPUT ONLY the script text with <break> tags. No explanations.`;

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

// STEP 2: Create scenes from edited content
async function createScenesFromContent() {
    const btn = document.getElementById('createScenesBtn');
    const contentArea = document.getElementById('generated_content');
    const sm_select = document.getElementById('sm_id_select');
    const status = document.getElementById('status_msg');
    
    if (!contentArea.value.trim()) {
        alert('Content is empty!');
        return;
    }

    const editedContent = contentArea.value;
    
    btn.disabled = true;
    btn.innerText = "⏳ Creating Scenes...";
    status.style.background = '#fef3c7';
    status.innerHTML = "⏳ Breaking content into scenes...";

    const hookSelect = document.getElementById('hook_select');
    const hookId = hookSelect.value;
    const hookName = hookSelect.options[hookSelect.selectedIndex].text.split(' - ')[1];
    const originalTitle = sm_select.options[sm_select.selectedIndex].text;
    const category = document.getElementById('cat_select').value;
    const topic = document.getElementById('topic_select').value;
    const cta = document.getElementById('cta_select').value;
    const sm_id = sm_select.value;

    const combinedTitle = `${hookId} - ${hookName}: ${originalTitle}`;

    const scenesPrompt = `Break this hypnotherapy script into 10-12 scenes and generate SQL INSERT statements:

SCRIPT CONTENT:
${editedContent}

For each scene, create:
1. text_contents: A portion of the script (preserve <break> tags)
2. text_display: A short, powerful caption (different from audio)
3. prompt: Detailed visual description for image generation
4. hashtags: 3-5 relevant hashtags
5. duration: 3-7 seconds per scene

OUTPUT FORMAT:
Generate SQL INSERT for hdb_podcast_stories:
podcast_id = [podcast_id]
lang_code = 'en'
category = '${category}'
topic_key = '${topic}'
title = '${combinedTitle}'
actor = 'host'
text_contents, text_display, duration, prompt, visual_type = 'image', status = 'PENDING', audio_file = '', video_file = '', image_file = '', created_date = NOW(), seq_no (1,2,3...), logo_flag = 0, hashtags

CRITICAL: Each INSERT on one line. Escape single quotes by doubling them.`;

    const formData = new URLSearchParams();
    formData.append('action', 'create_scenes_from_content');
    formData.append('sm_id', sm_id);
    formData.append('category', category);
    formData.append('topic', topic);
    formData.append('title', originalTitle);
    formData.append('combined_title', combinedTitle);
    formData.append('hook_id', hookId);
    formData.append('cta', cta);
    formData.append('content', editedContent);
    formData.append('prompt', scenesPrompt);

    try {
        const response = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData
        });
        
        const data = await response.json();
        
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
    } catch (err) {
        status.style.background = '#fee2e2';
        status.innerHTML = '❌ Network error occurred';
    } finally {
        btn.disabled = false;
        btn.innerText = "🎬 Step 2: Create Scenes from Content";
    }
}

window.onload = updatePrompt;
</script>
</body>
</html>