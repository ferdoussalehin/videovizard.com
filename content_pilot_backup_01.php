<?php
session_start();
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

// --- MASTER WORKFLOW ENGINE ---
if (isset($_POST['action']) && $_POST['action'] == 'execute_workflow') {
    $sm_id    = mysqli_real_escape_string($conn, $_POST['sm_id']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $topic    = mysqli_real_escape_string($conn, $_POST['topic']);
    $title    = mysqli_real_escape_string($conn, $_POST['title']);
    $hook     = mysqli_real_escape_string($conn, $_POST['hook']);
    $cta      = mysqli_real_escape_string($conn, $_POST['cta']);
    $p2_base  = $_POST['prompt']; 
    $today    = date('Y-m-d H:i:s');

    // PHASE 1: Create Parent Podcast Record
    $p1_prompt = "You are an assistant that generates exactly one single SQL INSERT statement for the table hdb_podcasts.
    Input:
    category: '$category'
    topic_key: '$topic'
    title: '$title'
    lang_code: 'en'
    
    Requirements:
    1. Generate 5–7 relevant hashtags (lowercase, comma-separated, no spaces, starts with #).
    2. Generate 8–12 SEO-friendly keywords (lowercase, comma-separated, no spaces).
    3. Generate 1 short engaging caption_text (max 200 chars, NO single quotes, 8th-grade level).
    
    Output Format:
    INSERT INTO hdb_podcasts (category, topic_key, title, lang_code, hashtags, keywords, caption_text, created_date) VALUES ('$category', '$topic', '$title', 'en', '[hashtags]', '[keywords]', '[caption_text]', NOW());
    
    Output SQL ONLY. No extra text.";
    
    $res1 = callChatGPT_inam($p1_prompt);
    $sql1 = preg_replace(['/^```(?:sql)?/i', '/```$/'], '', trim($res1['response']));

    if (mysqli_query($conn, $sql1)) {
        $podcast_id = mysqli_insert_id($conn);
    } else {
        echo json_encode(['status'=>'error','message'=>'hdb_podcasts Error: ' . mysqli_error($conn) . " SQL: " . $sql1]);
        exit;
    }

    // PHASE 2: Create Child Stories
    $p2 = str_replace('[podcast_id]', $podcast_id, $p2_base);
    $res2 = callChatGPT_inam($p2);
    $sql2 = preg_replace(['/^```(?:sql)?/i', '/```$/'], '', trim($res2['response']));

    if (mysqli_multi_query($conn, $sql2)) {
        do { if ($result = mysqli_store_result($conn)) { mysqli_free_result($result); } } while (mysqli_next_result($conn));
        mysqli_query($conn, "UPDATE hdb_social_media SET sm_status='GENERATED', hook_type='$hook' WHERE id='$sm_id'");
        echo json_encode(["status" => "success", "message" => "✅ Success! Podcast ID: $podcast_id and stories created."]);
    } else {
        echo json_encode(['status'=>'error','message'=>'hdb_podcast_stories Error: ' . mysqli_error($conn)]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ContentPilot AI</title>
    <style>
        :root { --dark-blue: #1e3a8a; --purple: #7c3aed; --orange: #f59e0b; --yellow: #fef3c7; --bg: #f8fafc; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); font-size: 0.85rem; }
        .container { max-width: 850px; margin: 20px auto; padding: 20px; }
        .card { background: #fff; padding: 25px; border-radius: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 700; color: var(--dark-blue); }
        select, textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.9rem; }
        .btn { background: var(--orange); color: white; border: none; padding: 15px; border-radius: 10px; cursor: pointer; font-weight: 800; width: 100%; transition: opacity 0.2s; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .random-box { background: var(--yellow); padding: 8px; border-radius: 5px; margin-top: 5px; display: flex; align-items: center; gap: 8px; font-weight: 600; }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        <h2 style="color:var(--dark-blue); margin-top:0;">🎙️ hdb_podcasts Workflow</h2>
        
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
            <label>Hook Strategy</label>
            <select id="hook_select" onchange="updatePrompt()">
                <?php 
                $hooks = ["Pattern Interrupt Hook", "Call-Out Hook", "Whisper / Secret Hook", "Myth-Busting Hook", "Specific Question Hook", "Symptom Recognition Hook", "Contrarian Hook", "Micro-Story Hook", "Authority Hook", "Identity Hook", "Fear-of-Consequence Hook", "Validation Hook", "Mistake Hook", "Secret/Curiosity Hook", "POV/Relatability Hook", "Step-by-Step Hook", "Controversial Hook"]; 
                foreach($hooks as $h) echo "<option value='$h'>$h</option>"; 
                ?>
            </select>
            <div class="random-box"><input type="checkbox" id="rnd_hook" checked onchange="updatePrompt()"> Select Hook randomly</div>
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

        <div class="form-group">
            <label>Phase 2: Stories Prompt Preview</label>
            <textarea id="prompt_preview" rows="8"></textarea>
        </div>

        <button class="btn" id="genBtn" onclick="executeWorkflow()">🚀 GENERATE PODCAST & STORIES</button>
        <div id="status_msg" style="margin-top:15px; display:none; padding:10px; border-radius:8px;"></div>
    </div>
</div>

<script>
const hooksArr = ["Pattern Interrupt Hook", "Call-Out Hook", "Whisper / Secret Hook", "Myth-Busting Hook", "Specific Question Hook", "Symptom Recognition Hook", "Contrarian Hook", "Micro-Story Hook", "Authority Hook", "Identity Hook", "Fear-of-Consequence Hook", "Validation Hook", "Mistake Hook", "Secret/Curiosity Hook", "POV/Relatability Hook", "Step-by-Step Hook", "Controversial Hook"];

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
    const title = (ts.selectedIndex !== -1 && ts.value !== "") ? ts.options[ts.selectedIndex].text : "[title]";
    const hook = document.getElementById('rnd_hook').checked ? hooksArr[Math.floor(Math.random()*hooksArr.length)] : document.getElementById('hook_select').value;
    const cta = document.getElementById('cta_select').value || "[cta_en]";

    document.getElementById('prompt_preview').value = `Act as a Social Media Content Architect and Hypnotherapy Scriptwriter. Your goal is to generate 10–12 SQL INSERT statements for the table 'hdb_podcast_stories'.

### INPUT DATA:
Category: ${cat}
Topic: ${topic}
Title: ${title}
Hook Strategy: ${hook}
Podcast ID: [podcast_id]
CTA: ${cta}

### SCRIPT REQUIREMENTS:
1. SCRIPT FLOW:
   - Scenes 1–3: Hook and pain identification (${hook} style)
   - Scenes 4–5: Symptom/pain points
   - Scenes 6–7: CBT tips
   - Scenes 8–9: Hypnotherapy solution / empowerment
   - Scenes 10–12: Integration and CTA (${cta})

2. CAPTION VS. AUDIO (Strict Difference):
   - text_display (Captions): Powerful, headline-style on-screen text. Concise and emotionally resonant. NO SSML.
   - text_contents (Audio): Full hypnotherapy-style spoken script for TTS. Use Azure SSML format for pauses. Include <break time="200ms"/> for internal breathing pauses and <break time="300ms"/> at the end of every scene.
   - MANDATORY: text_display and text_contents MUST NOT be identical. Never repeat the same wording between them.

3. VISUAL & SEARCH LOGIC:
   - prompt: Provide a highly detailed visual description for each scene including lighting (soft, warm, natural), camera angles (close-up, medium, soft pan, slow zoom, over-shoulder), subject pose and facial expression (reflective, calm, hopeful), subtle background elements (plants, books, window light, cozy interiors), clothing and props (casual, comforting). Each scene must be visually unique.
   - hashtags: 3–5 per scene; include both scene-specific (#calm-breathing, #parentingstress) and general series tags (#stressrelief, #hypnotherapy).
   - visual_type: Set as 'image', 'video', or 'broll' depending on the scene.

### TECHNICAL DATA MAPPING (Columns a-u):
a) podcast_id = [podcast_id]
b) lang_code = 'en'
c) category = [category]
d) topic_key = [topic]
e) title = [title]
f) actor = 'host'
g) text_contents = (Spoken script with SSML <break time="ms"/> pauses)
h) text_display = (Short on-screen caption)
i) duration = (Calculated seconds per scene, 3–7s)
j) prompt = (Detailed visual description)
k) visual_type = ('image', 'video', or 'broll')
l) status = 'PENDING'
m) audio_file = '', video_file = '', image_file = ''
n) created_date = NOW()
r) seq_no = (Incrementing 1, 2, 3…)
s) logo_flag = 0
t) hashtags = (Scene-specific + general hashtags)

### OUTPUT FORMAT:
- Output SQL INSERT INTO hdb_podcast_stories ONLY.
- No explanations, no comments, no extra text.
- CRITICAL: DO NOT use single quotes (') inside any text fields (text_contents, text_display, prompt). Use double quotes (") or escape them.
- Each scene must have unique visual prompt, unique text_display, and unique text_contents.`;
}

function executeWorkflow() {
    const btn = document.getElementById('genBtn');
    const status = document.getElementById('status_msg');
    const sm_select = document.getElementById('sm_id_select');
    
    if(!sm_select.value) { alert("Please select a title first"); return; }

    btn.disabled = true;
    status.style.display = 'block'; 
    status.style.background = '#fef3c7'; 
    status.innerText = "Processing Phase 1 & Phase 2...";

    const formData = new URLSearchParams();
    formData.append('action', 'execute_workflow');
    formData.append('sm_id', sm_select.value);
    formData.append('category', document.getElementById('cat_select').value);
    formData.append('topic', document.getElementById('topic_select').value);
    formData.append('title', sm_select.options[sm_select.selectedIndex].text);
    formData.append('hook', document.getElementById('hook_select').value);
    formData.append('cta', document.getElementById('cta_select').value);
    formData.append('prompt', document.getElementById('prompt_preview').value);

    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: formData })
    .then(r => r.json()).then(res => {
        status.style.background = res.status === 'success' ? '#dcfce7' : '#fee2e2';
        status.innerText = res.message;
        btn.disabled = false;
    }).catch(err => {
        status.style.background = '#fee2e2';
        status.innerText = "Error processing request."; 
        btn.disabled = false;
    });
}
window.onload = updatePrompt;
</script>
</body>
</html>