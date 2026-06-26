<?php
// video_generation.php — AI Video Generation via Google Veo

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
/*echo shell_exec('which node 2>/dev/null');
echo "<br>";
echo shell_exec('whereis node 2>/dev/null');
echo "<br>";
echo shell_exec('find /usr /opt -name "node" -type f 2>/dev/null');
die;*/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>15552000,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

if (!headers_sent()) header('X-Frame-Options: SAMEORIGIN');

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Directories
$video_ai_dir = __DIR__ . '/video_ai';
$jobs_dir     = $video_ai_dir . '/jobs';
if (!is_dir($video_ai_dir)) mkdir($video_ai_dir, 0755, true);
if (!is_dir($jobs_dir))     mkdir($jobs_dir, 0755, true);

// Protect jobs directory from web access
$htaccess = $jobs_dir . '/.htaccess';
if (!file_exists($htaccess)) file_put_contents($htaccess, "Deny from all\n");

// Config (stores Gemini API key)
$config_file = __DIR__ . '/veo_config.php';
$gemini_api_key = '';
if (file_exists($config_file)) {
    @include $config_file;
    $gemini_api_key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if (!empty($_POST['action'])) {
    header('Content-Type: application/json');

    switch ($_POST['action']) {

        case 'save_config': {
            $key = trim($_POST['api_key'] ?? '');
            $key = preg_replace('/[^A-Za-z0-9_\-]/', '', $key);
            $php = "<?php\ndefine('GEMINI_API_KEY', " . var_export($key, true) . ");\n";
            file_put_contents($config_file, $php);
            echo json_encode(['success' => true]);
            exit;
        }

        case 'generate': {
            $prompt = trim($_POST['prompt'] ?? '');
            if (!$prompt) { echo json_encode(['success'=>false,'error'=>'Prompt is required']); exit; }
            if (!$gemini_api_key) { echo json_encode(['success'=>false,'error'=>'Gemini API key not configured — click the key icon to add it']); exit; }

            $job_id   = 'veo_' . time() . '_' . bin2hex(random_bytes(4));
            $job_file = $jobs_dir . '/' . $job_id . '.json';
            $log_file = $jobs_dir . '/' . $job_id . '.log';

            file_put_contents($job_file, json_encode([
                'status'     => 'queued',
                'prompt'     => $prompt,
                'job_id'     => $job_id,
                'api_key'    => $gemini_api_key,
                'created_at' => time(),
            ]));

            $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

            // Resolve node binary — config override takes priority
            if (defined('NODE_EXE') && file_exists(NODE_EXE)) {
                $node_exe = NODE_EXE;
            } else {
                $node_exe = '';
                if ($is_windows) {
                    $found = trim((string)@shell_exec('where node 2>nul'));
                    $node_exe = explode("\n", $found)[0];
                } else {
                    $found = trim((string)@shell_exec('which node 2>/dev/null'));
                    $node_exe = $found;
                }
                $node_exe = trim($node_exe);
                if (!$node_exe || !file_exists($node_exe)) {
                    $candidates = $is_windows ? [
                        'C:\\laragon\\bin\\nodejs\\node-v22\\node.exe',
                        'C:\\laragon\\bin\\nodejs\\node-v20\\node.exe',
                        'C:\\Program Files\\nodejs\\node.exe',
                    ] : [
                        '/usr/bin/node',
                        '/usr/local/bin/node',
                        '/opt/cpanel/ea-nodejs22/root/usr/bin/node',
                        '/opt/cpanel/ea-nodejs20/root/usr/bin/node',
                        '/opt/cpanel/ea-nodejs18/root/usr/bin/node',
                        '/opt/alt/alt-nodejs24/root/usr/bin/node',
                        '/opt/alt/alt-nodejs22/root/usr/bin/node',
                    ];
                    foreach ($candidates as $p) { if (file_exists($p)) { $node_exe = $p; break; } }
                }
            }

            if (!$node_exe || !file_exists($node_exe)) {
                $err = 'node not found. Set NODE_EXE in veo_config.php (e.g. define(\'NODE_EXE\', \'/usr/bin/node\');)';
                file_put_contents($job_file, json_encode([
                    'status'=>'error','error'=>$err,'prompt'=>$prompt,'job_id'=>$job_id,'created_at'=>time(),
                ]));
                echo json_encode(['success'=>false,'error'=>$err]);
                exit;
            }

            $script = __DIR__ . '/generate_veo.mjs';

            if ($is_windows) {
                $bat = $jobs_dir . '/' . $job_id . '.bat';
                $s   = str_replace('/', '\\', $script);
                $l   = str_replace('/', '\\', $log_file);
                $bat_content = "@echo off\r\n"
                    . "cd /d \"" . str_replace('/', '\\', __DIR__) . "\"\r\n"
                    . "\"$node_exe\" \"$s\" \"$job_id\" > \"$l\" 2>&1\r\n";
                file_put_contents($bat, $bat_content);
                $launched = false;
                if (class_exists('COM')) {
                    try {
                        $sh = new COM('WScript.Shell');
                        $sh->Run('"' . str_replace('/', '\\', $bat) . '"', 0, false);
                        $launched = true;
                    } catch (Throwable $e) {}
                }
                if (!$launched) pclose(popen('start /B "" "' . str_replace('/', '\\', $bat) . '"', 'r'));
            } else {
                // Linux/cPanel: nohup detaches from Apache process group
                $cmd = 'nohup ' . escapeshellarg($node_exe)
                    . ' ' . escapeshellarg($script)
                    . ' ' . escapeshellarg($job_id)
                    . ' > ' . escapeshellarg($log_file) . ' 2>&1 &';
                shell_exec($cmd);
            }

            echo json_encode(['success'=>true, 'job_id'=>$job_id, 'node'=>$node_exe]);
            exit;
        }

        case 'job_log': {
            $job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['job_id'] ?? '');
            $log_file = $jobs_dir . '/' . $job_id . '.log';
            $log = file_exists($log_file) ? file_get_contents($log_file) : '(no log yet)';
            echo json_encode(['log'=>$log]);
            exit;
        }

        case 'check_job': {
            $job_id = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['job_id'] ?? '');
            $job_file = $jobs_dir . '/' . $job_id . '.json';
            if (!file_exists($job_file)) { echo json_encode(['status'=>'not_found']); exit; }
            $data = json_decode(file_get_contents($job_file), true);
            unset($data['api_key']); // never expose key to frontend
            echo json_encode($data);
            exit;
        }

        case 'list_videos': {
            $videos = [];
            foreach (glob($video_ai_dir . '/*.mp4') ?: [] as $f) {
                $basename = basename($f);
                $job_id   = substr($basename, 0, -4); // strip .mp4
                $job_file = $jobs_dir . '/' . $job_id . '.json';
                $prompt   = '';
                $ts       = filemtime($f);
                if (file_exists($job_file)) {
                    $job    = json_decode(file_get_contents($job_file), true);
                    $prompt = $job['prompt'] ?? '';
                    $ts     = $job['created_at'] ?? $ts;
                }
                $videos[] = ['filename'=>$basename, 'url'=>'video_ai/'.$basename, 'prompt'=>$prompt, 'ts'=>$ts];
            }
            usort($videos, fn($a,$b) => $b['ts'] - $a['ts']);
            echo json_encode(['success'=>true, 'videos'=>$videos]);
            exit;
        }
    }

    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Video Generation — VideoVizard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg:#f5f6fa; --bg2:#eef0f5; --card:#fff; --hover:#f0f2ff;
    --border:#dfe2ea; --text:#1e293b; --text2:#64748b; --muted:#94a3b8;
    --accent:#6c5ce7; --accent-glow:rgba(108,92,231,.15);
    --ok:#059669; --warn:#d97706; --err:#dc2626;
    --green:#12B76A; --r:12px; --rs:8px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Instrument Sans',sans-serif;background:var(--bg);color:var(--text);padding:20px;font-size:13px;}
a{text-decoration:none;color:inherit;}
.container{max-width:900px;margin:0 auto;}

/* Header */
.xpage-header{display:flex;align-items:center;gap:20px;margin-bottom:25px;flex-wrap:wrap;}
.back-button{display:inline-flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 18px;color:var(--text);font-weight:600;font-size:14px;transition:all .2s;}
.back-button:hover{background:var(--hover);border-color:var(--accent);transform:translateX(-3px);}
.header-title h1{font-size:24px;font-family:'Bricolage Grotesque',sans-serif;}
.header-title p{color:var(--text2);font-size:13px;margin-top:4px;}
.header-right{margin-left:auto;}
.btn-icon{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 14px;color:var(--text2);cursor:pointer;font-size:14px;transition:all .2s;}
.btn-icon:hover{border-color:var(--accent);color:var(--accent);}

/* Cards */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 1px 3px rgba(0,0,0,.04);padding:28px;}
.card + .card{margin-top:24px;}

/* Section title */
.section-title{font-size:18px;font-weight:700;font-family:'Bricolage Grotesque',sans-serif;color:var(--text);text-align:center;margin-bottom:24px;}

/* Prompt area */
.prompt-label{font-size:12px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;}
.prompt-textarea{width:100%;min-height:120px;padding:14px 16px;border:1.5px solid var(--border);border-radius:var(--rs);font-family:inherit;font-size:13px;color:var(--text);background:#fff;resize:vertical;transition:border-color .2s;outline:none;}
.prompt-textarea:focus{border-color:var(--accent);}
.prompt-textarea::placeholder{color:var(--muted);}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border:none;border-radius:var(--rs);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-generate{background:var(--ok);color:#fff;margin-top:16px;}
.btn-generate:hover:not(:disabled){filter:brightness(1.08);}
.btn-generate:disabled{opacity:.55;cursor:not-allowed;}

/* Status bar */
.gen-status{margin-top:12px;font-size:12px;color:var(--text2);display:none;align-items:center;gap:8px;}
.gen-status.visible{display:flex;}
.spin{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--ok);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0;}
@keyframes spin{to{transform:rotate(360deg);}}
.status-err{color:var(--err);}
.status-ok{color:var(--ok);}

/* Gallery */
.gallery-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.video-card{border:1.5px solid var(--border);border-radius:var(--rs);overflow:hidden;background:var(--bg);transition:box-shadow .2s;}
.video-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.video-card video{width:100%;height:180px;object-fit:cover;display:block;background:#0d1117;}
.video-card-body{padding:10px 12px;}
.video-card-title{font-size:11px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.video-card-meta{font-size:10px;color:var(--muted);margin-top:3px;}

/* Placeholder cards (empty state) */
.video-card-placeholder{border:1.5px solid var(--border);border-radius:var(--rs);background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:220px;gap:8px;color:var(--muted);}
.video-card-placeholder i{font-size:24px;opacity:.4;}
.video-card-placeholder span{font-size:11px;}

/* Empty state */
.gallery-empty{text-align:center;padding:60px 20px;color:var(--muted);}
.gallery-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.4;}
.gallery-empty p{font-size:13px;}

/* API key modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:16px;padding:28px;width:440px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-title{font-size:16px;font-weight:700;font-family:'Bricolage Grotesque',sans-serif;margin-bottom:6px;}
.modal-sub{font-size:12px;color:var(--text2);margin-bottom:20px;}
.modal-input{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--rs);font-family:inherit;font-size:13px;outline:none;transition:border-color .2s;}
.modal-input:focus{border-color:var(--accent);}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;}
.btn-cancel{background:var(--bg2);border:1px solid var(--border);color:var(--text2);padding:8px 16px;border-radius:var(--rs);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;}
.btn-save{background:var(--accent);color:#fff;padding:8px 18px;border:none;border-radius:var(--rs);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;}
.btn-save:hover{filter:brightness(1.1);}
.key-configured{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--ok);background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);border-radius:20px;padding:4px 10px;}
.key-not-configured{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:var(--err);background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.2);border-radius:20px;padding:4px 10px;}

@media(max-width:640px){.gallery-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:400px){.gallery-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="xpage-header">
        <a href="vizard_scheduler.php" class="back-button"><i class="fas fa-arrow-left"></i> Back</a>
        <div class="header-title">
            <h1>AI Video Generation</h1>
            <p>Generate videos using Google Veo AI from a text prompt</p>
        </div>
        <div class="header-right" style="display:flex;align-items:center;gap:10px;">
            <span id="key-badge" class="<?= $gemini_api_key ? 'key-configured' : 'key-not-configured' ?>">
                <i class="fas fa-key"></i>
                <?= $gemini_api_key ? 'API Key Set' : 'No API Key' ?>
            </span>
            <button class="btn-icon" onclick="openKeyModal()" title="Configure Gemini API Key">
                <i class="fas fa-cog"></i>
            </button>
        </div>
    </div>

    <!-- Prompt card -->
    <div class="card">
        <div class="section-title">Video Generation</div>
        <div class="prompt-label">Enter Prompt</div>
        <textarea
            id="promptInput"
            class="prompt-textarea"
            placeholder="Example, A child age of about 5 years having wings and flying in the sky"
        ></textarea>
        <br>
        <button id="generateBtn" class="btn btn-generate" onclick="startGeneration()">
            <i class="fas fa-wand-magic-sparkles"></i> Generate Video
        </button>
        <div id="genStatus" class="gen-status">
            <span class="spin" id="statusSpinner"></span>
            <span id="statusText">Starting generation...</span>
        </div>
    </div>

    <!-- Generated videos gallery -->
    <div class="card" style="margin-top:24px;">
        <div class="section-title">Generated Videos</div>
        <div id="videoGallery" class="gallery-grid">
            <!-- Placeholder cards shown before videos load -->
            <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
            <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
            <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
            <div class="video-card-placeholder"></div>
            <div class="video-card-placeholder"></div>
            <div class="video-card-placeholder"></div>
        </div>
    </div>

</div>

<!-- API Key Modal -->
<div id="keyModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-key" style="color:var(--accent);margin-right:8px;"></i>Gemini API Key</div>
        <div class="modal-sub">Enter your Google AI Studio API key to enable video generation. Get one at <strong>aistudio.google.com</strong>.</div>
        <input type="password" id="apiKeyInput" class="modal-input" placeholder="AIza..." value="<?= $gemini_api_key ? str_repeat('•', 20) : '' ?>">
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeKeyModal()">Cancel</button>
            <button class="btn-save" onclick="saveApiKey()">Save Key</button>
        </div>
    </div>
</div>

<script>
let activeJobId = null;
let pollInterval = null;
let pollCount = 0;

// ── Generation ────────────────────────────────────────────────────────────────
function startGeneration() {
    const prompt = document.getElementById('promptInput').value.trim();
    if (!prompt) { showStatus('Please enter a prompt first', 'err'); return; }

    const btn = document.getElementById('generateBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span> Generating...';
    showStatus('Submitting to Veo API...', 'spin');

    fetch('video_generation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=generate&prompt=' + encodeURIComponent(prompt)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            resetBtn();
            showStatus(data.error || 'Generation failed', 'err');
            return;
        }
        activeJobId = data.job_id;
        pollCount = 0;
        showStatus('Video generation started — this may take 2–5 minutes...', 'spin');
        pollInterval = setInterval(pollJobStatus, 5000);
        pollJobStatus();
    })
    .catch(() => {
        resetBtn();
        showStatus('Request failed — check error log', 'err');
    });
}

function pollJobStatus() {
    if (!activeJobId) return;
    fetch('video_generation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=check_job&job_id=' + encodeURIComponent(activeJobId)
    })
    .then(r => r.json())
    .then(data => {
        const st = data.status;
        pollCount++;
        if (st === 'queued') {
            // If still queued after ~25s, node likely never started — fetch log
            if (pollCount >= 5) {
                clearInterval(pollInterval);
                fetchLogAndShow('Job stuck in queued — node process likely failed. Log:');
                resetBtn();
                activeJobId = null;
                return;
            }
            showStatus('Queued — waiting for worker to start... (' + pollCount + ')', 'spin');
        }
        if (st === 'processing') showStatus('Submitting prompt to Veo API...', 'spin');
        if (st === 'generating') showStatus('AI is generating your video...', 'spin');
        if (st === 'done') {
            clearInterval(pollInterval);
            activeJobId = null;
            resetBtn();
            showStatus('Video ready!', 'ok');
            loadGallery();
            setTimeout(() => hideStatus(), 4000);
        }
        if (st === 'error') {
            clearInterval(pollInterval);
            const failedJob = activeJobId;
            activeJobId = null;
            resetBtn();
            showStatus('Error: ' + (data.error || 'Unknown error'), 'err');
            fetchLogAndShow('Worker log:', failedJob);
        }
    })
    .catch(() => {/* silent — will retry */});
}

function fetchLogAndShow(prefix, jobId) {
    jobId = jobId || activeJobId;
    if (!jobId) return;
    fetch('video_generation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=job_log&job_id=' + encodeURIComponent(jobId)
    })
    .then(r => r.json())
    .then(d => {
        const log = (d.log || '').trim() || '(empty — node never wrote to log)';
        const el = document.getElementById('genStatus');
        el.className = 'gen-status visible';
        el.innerHTML = '<div style="color:var(--err);font-weight:600;margin-bottom:6px;">' + prefix + '</div>'
            + '<pre style="background:#1e293b;color:#fbbf24;padding:12px;border-radius:6px;font-size:11px;max-height:240px;overflow:auto;white-space:pre-wrap;width:100%;">' + escHtml(log) + '</pre>';
    });
}

function resetBtn() {
    const btn = document.getElementById('generateBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate Video';
}

function showStatus(msg, type) {
    const el = document.getElementById('genStatus');
    const txt = document.getElementById('statusText');
    const spin = document.getElementById('statusSpinner');
    el.className = 'gen-status visible';
    txt.className = type === 'err' ? 'status-err' : (type === 'ok' ? 'status-ok' : '');
    txt.textContent = msg;
    spin.style.display = type === 'spin' ? 'inline-block' : 'none';
}
function hideStatus() { document.getElementById('genStatus').className = 'gen-status'; }

// ── Gallery ───────────────────────────────────────────────────────────────────
function loadGallery() {
    fetch('video_generation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=list_videos'
    })
    .then(r => r.json())
    .then(data => {
        const gallery = document.getElementById('videoGallery');
        if (!data.success || !data.videos.length) {
            gallery.innerHTML = `
                <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
                <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
                <div class="video-card-placeholder"><i class="fas fa-video"></i><span>Video</span></div>
                <div class="video-card-placeholder"></div>
                <div class="video-card-placeholder"></div>
                <div class="video-card-placeholder"></div>`;
            return;
        }
        const cards = data.videos.map(v => {
            const title = v.prompt ? v.prompt.substring(0, 60) + (v.prompt.length > 60 ? '…' : '') : v.filename;
            const date  = new Date(v.ts * 1000).toLocaleDateString();
            return `<div class="video-card">
                <video src="${escHtml(v.url)}" controls muted playsinline preload="metadata"></video>
                <div class="video-card-body">
                    <div class="video-card-title" title="${escHtml(v.prompt)}">${escHtml(title)}</div>
                    <div class="video-card-meta">${date}</div>
                </div>
            </div>`;
        });
        // Pad with empty placeholders to fill remaining cells up to a multiple of 3
        while (cards.length % 3 !== 0) {
            cards.push('<div class="video-card-placeholder"></div>');
        }
        gallery.innerHTML = cards.join('');
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── API Key modal ─────────────────────────────────────────────────────────────
function openKeyModal() {
    document.getElementById('apiKeyInput').value = '';
    document.getElementById('keyModal').classList.add('open');
    document.getElementById('apiKeyInput').focus();
}
function closeKeyModal() {
    document.getElementById('keyModal').classList.remove('open');
}
function saveApiKey() {
    const key = document.getElementById('apiKeyInput').value.trim();
    fetch('video_generation.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=save_config&api_key=' + encodeURIComponent(key)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeKeyModal();
            const badge = document.getElementById('key-badge');
            if (key) {
                badge.className = 'key-configured';
                badge.innerHTML = '<i class="fas fa-key"></i> API Key Set';
            } else {
                badge.className = 'key-not-configured';
                badge.innerHTML = '<i class="fas fa-key"></i> No API Key';
            }
        }
    });
}

// Close modal on overlay click
document.getElementById('keyModal').addEventListener('click', function(e) {
    if (e.target === this) closeKeyModal();
});

// Load gallery on page load
loadGallery();
</script>
</body>
</html>
