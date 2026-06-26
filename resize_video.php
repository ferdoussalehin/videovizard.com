<?php
// ============================================================
// resize_video.php — VPS Direct (no upload/download)
// Runs ffmpeg locally: podcast_videos/ → podcast_videos_new/
// 720p H.264, 30fps, trimmed to 10s, same filename, resize_flag = 8
// ============================================================

ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);
session_start();

include 'dbconnect_hdb.php';

// ── Config ────────────────────────────────────────────────────
define('INPUT_FOLDER',     __DIR__ . '/podcast_videos');
define('OUTPUT_FOLDER',    __DIR__ . '/podcast_videos_new');
define('LOG_FILE',         __DIR__ . '/a_errors.log');
define('RESIZE_FLAG_DONE', 8);
define('FFMPEG_BIN',       'ffmpeg');   // change to full path if needed e.g. '/usr/bin/ffmpeg'

// ── Helpers ───────────────────────────────────────────────────
function log_msg(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

function size_human(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    return round($bytes / 1024, 2) . ' KB';
}

function json_out(array $data): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Ensure output folder exists
if (!is_dir(OUTPUT_FOLDER)) {
    mkdir(OUTPUT_FOLDER, 0755, true);
}

// ── AJAX handlers ─────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {

    // ── fetch_stats ───────────────────────────────────────────
    if ($_POST['ajax_action'] === 'fetch_stats') {
        $total   = (int)(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='video'"))['c'] ?? 0);
        $pending = (int)(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM hdb_image_data
              WHERE media_type='video' AND resize_flag != " . RESIZE_FLAG_DONE))['c'] ?? 0);
        $done    = (int)(mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM hdb_image_data WHERE resize_flag = " . RESIZE_FLAG_DONE))['c'] ?? 0);
        json_out(['total' => $total, 'pending' => $pending, 'done' => $done]);
    }

    // ── fetch_queue ───────────────────────────────────────────
    if ($_POST['ajax_action'] === 'fetch_queue') {
        $rows = [];
        $q = mysqli_query($conn,
            "SELECT id, image_name, media_type, file_size, resize_flag, media_format
               FROM hdb_image_data
              WHERE media_type = 'video'
                AND resize_flag != " . RESIZE_FLAG_DONE . "
              ORDER BY id ASC LIMIT 500");
        while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
        json_out($rows);
    }

    // ── process_one: run ffmpeg directly on VPS ───────────────
    if ($_POST['ajax_action'] === 'process_one') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'Missing id']);

        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT * FROM hdb_image_data WHERE id=$id LIMIT 1"));
        if (!$row) json_out(['success' => false, 'message' => 'Row not found']);

        $image_name = trim($row['image_name'] ?? '');
        if (!$image_name) json_out(['success' => false, 'message' => 'Empty image_name']);

        $input_path  = INPUT_FOLDER  . '/' . $image_name;
        $output_path = OUTPUT_FOLDER . '/' . $image_name;

        if (!file_exists($input_path)) {
            log_msg("process_one id=$id — not found: $input_path");
            json_out(['success' => false, 'message' => "File not found: podcast_videos/$image_name"]);
        }

        $input_size = filesize($input_path);
        log_msg("process_one id=$id — input: $image_name (" . size_human($input_size) . ")");

        // ── Build ffmpeg command ───────────────────────────────
        // -y               : overwrite output without asking
        // -i               : input file
        // -t 10            : trim to first 10 seconds
        // -vf scale        : scale to 720p height, keep aspect ratio
        // -r 30            : force 30fps (consistent for stitching/transitions)
        // -c:v libx264     : H.264 video codec
        // -crf 23          : ffmpeg default — good quality/size balance
        // -preset fast     : encoding speed vs compression tradeoff
        // -c:a aac         : AAC audio
        // -b:a 128k        : audio bitrate
        // -movflags +faststart : web-friendly (moov atom at front)
        // 2>&1             : capture stderr (ffmpeg logs there)
        $ffmpeg_cmd = sprintf(
            '%s -y -i %s -t 10 -vf "scale=-2:720" -r 30 -c:v libx264 -crf 23 -preset fast -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            FFMPEG_BIN,
            escapeshellarg($input_path),
            escapeshellarg($output_path)
        );

        log_msg("process_one id=$id — running ffmpeg…");
        $ffmpeg_output = shell_exec($ffmpeg_cmd);

        if (!file_exists($output_path) || filesize($output_path) < 1000) {
            $err_snippet = substr(trim($ffmpeg_output ?? ''), -400);
            log_msg("process_one id=$id — ffmpeg FAILED: $err_snippet");
            json_out(['success' => false, 'message' => 'ffmpeg failed: ' . $err_snippet]);
        }

        $output_size = filesize($output_path);
        log_msg("process_one id=$id — done: " . size_human($input_size) . " → " . size_human($output_size));

        // ── Update DB ─────────────────────────────────────────
        mysqli_query($conn,
            "UPDATE hdb_image_data
                SET resize_flag   = " . RESIZE_FLAG_DONE . ",
                    clipped_video = '" . mysqli_real_escape_string($conn, 'podcast_videos_new/' . $image_name) . "',
                    updated_at    = NOW()
              WHERE id = $id");

        json_out([
            'success'      => true,
            'id'           => $id,
            'filename'     => $image_name,
            'input_size'   => size_human($input_size),
            'output_size'  => size_human($output_size),
            'saved_to'     => 'podcast_videos_new/' . $image_name,
        ]);
    }

    json_out(['success' => false, 'message' => 'Unknown action']);
}

// ── Initial page stats ────────────────────────────────────────
$total   = (int)(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM hdb_image_data WHERE media_type='video'"))['c'] ?? 0);
$pending = (int)(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM hdb_image_data
      WHERE media_type='video' AND resize_flag != " . RESIZE_FLAG_DONE))['c'] ?? 0);
$done    = (int)(mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM hdb_image_data WHERE resize_flag = " . RESIZE_FLAG_DONE))['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Resizer — VPS Direct</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #1e293b; min-height: 100vh; }

.page-header {
    background: #fff; border-bottom: 2px solid #e2e8f0;
    padding: 18px 32px; display: flex; align-items: center;
    justify-content: space-between; gap: 16px; flex-wrap: wrap;
}
.page-header h1 { font-size: 20px; font-weight: 700; color: #0f2a44; display: flex; align-items: center; gap: 10px; }
.header-badge { background: #22c55e; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 20px; letter-spacing: .5px; text-transform: uppercase; }
.header-sub { font-size: 12px; color: #64748b; }
.header-sub code { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; padding: 1px 5px; font-size: 11px; }

.container { max-width: 1120px; margin: 0 auto; padding: 28px 24px; }

.stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
    padding: 20px 22px; display: flex; flex-direction: column; gap: 5px;
    box-shadow: 0 1px 4px rgba(0,0,0,.05); position: relative; overflow: hidden;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:14px 14px 0 0; }
.stat-card.blue::before   { background:#3b82f6; }
.stat-card.orange::before { background:#f97316; }
.stat-card.green::before  { background:#22c55e; }
.stat-card.red::before    { background:#ef4444; }
.stat-card.purple::before { background:#a855f7; }
.stat-label { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.6px; }
.stat-value { font-size:34px; font-weight:800; line-height:1; margin-top:2px; }
.stat-card.blue   .stat-value { color:#3b82f6; }
.stat-card.orange .stat-value { color:#f97316; }
.stat-card.green  .stat-value { color:#22c55e; }
.stat-card.red    .stat-value { color:#ef4444; }
.stat-card.purple .stat-value { color:#a855f7; font-size:20px; padding-top:8px; }
.stat-sub { font-size:11px; color:#cbd5e1; margin-top:3px; }

.controls-bar {
    background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;
    padding:16px 22px; display:flex; align-items:center; gap:12px;
    margin-bottom:22px; flex-wrap:wrap; box-shadow:0 1px 4px rgba(0,0,0,.05);
}
.bar-title { font-size:13px; font-weight:700; color:#0f2a44; white-space:nowrap; }
.progress-wrap { flex:1; min-width:120px; background:#f1f5f9; border-radius:99px; height:10px; overflow:hidden; }
.progress-inner { height:100%; background:linear-gradient(90deg,#3b82f6,#22c55e); border-radius:99px; width:0%; transition:width .5s ease; }
.progress-label { font-size:11px; font-weight:700; color:#64748b; white-space:nowrap; }

.btn { display:inline-flex; align-items:center; gap:7px; padding:9px 20px; border-radius:9px; font-size:13px; font-weight:700; cursor:pointer; border:none; transition:opacity .15s,transform .1s; white-space:nowrap; }
.btn:hover:not(:disabled) { opacity:.9; box-shadow:0 2px 8px rgba(0,0,0,.12); }
.btn:active:not(:disabled) { transform:scale(.97); }
.btn:disabled { opacity:.4; cursor:not-allowed; }
.btn-start   { background:#22c55e; color:#fff; }
.btn-stop    { background:#ef4444; color:#fff; }
.btn-refresh { background:#f8fafc; color:#334155; border:1.5px solid #e2e8f0; }

.workers-label { font-size:12px; color:#64748b; }
.workers-label select { margin-left:6px; border:1.5px solid #e2e8f0; border-radius:7px; padding:4px 8px; font-size:12px; font-weight:700; color:#0f2a44; background:#fff; cursor:pointer; }

.panel { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.panel-head { background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:12px 20px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.panel-head h2 { font-size:13px; font-weight:700; color:#0f2a44; }

.tbl-wrap { overflow-x:auto; max-height:420px; overflow-y:auto; }
table { width:100%; border-collapse:collapse; font-size:12px; }
thead th { position:sticky; top:0; z-index:2; background:#f8fafc; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:10px 14px; border-bottom:1.5px solid #e2e8f0; text-align:left; white-space:nowrap; }
tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#f8fafc; }
td { padding:9px 14px; color:#334155; vertical-align:middle; }
td.fn { max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-family:'Consolas',monospace; font-size:11px; color:#0f2a44; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:99px; font-size:10px; font-weight:700; white-space:nowrap; }
.b-pending  { background:#fef9c3; color:#854d0e; }
.b-running  { background:#dbeafe; color:#1d4ed8; }
.b-done     { background:#dcfce7; color:#166534; }
.b-error    { background:#fee2e2; color:#991b1b; }

.log-box { background:#0f172a; max-height:220px; overflow-y:auto; padding:14px 18px; font-family:'Consolas',monospace; font-size:11px; color:#94a3b8; line-height:1.9; }
.l-ok   { color:#4ade80; }
.l-err  { color:#f87171; }
.l-info { color:#60a5fa; }

.empty-state { text-align:center; padding:50px 20px; color:#94a3b8; }
.empty-state .ei { font-size:40px; margin-bottom:10px; }
.empty-state p { font-size:13px; }

@keyframes spin { to { transform:rotate(360deg); } }
.spin { display:inline-block; width:11px; height:11px; border:2px solid rgba(59,130,246,.25); border-top-color:#1d4ed8; border-radius:50%; animation:spin .7s linear infinite; }
.clear-btn { font-size:11px; background:#1e293b; border:1px solid #334155; padding:4px 10px; border-radius:6px; cursor:pointer; color:#94a3b8; }
.clear-btn:hover { background:#334155; }
</style>
</head>
<body>

<div class="page-header">
    <h1>🎬 Video Resizer <span class="header-badge">VPS Direct</span></h1>
    <div class="header-sub">
        <code>podcast_videos/</code> → ffmpeg 720p · 30fps · 10s trim → <code>podcast_videos_new/</code> → <code>resize_flag = 8</code>
    </div>
</div>

<div class="container">

    <div class="stats-row">
        <div class="stat-card blue">
            <div class="stat-label">Total Videos</div>
            <div class="stat-value" id="statTotal"><?= $total ?></div>
            <div class="stat-sub">in hdb_image_data</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Pending</div>
            <div class="stat-value" id="statPending"><?= $pending ?></div>
            <div class="stat-sub">need processing</div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Done</div>
            <div class="stat-value" id="statDone"><?= $done ?></div>
            <div class="stat-sub">resize_flag = 8</div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">Errors</div>
            <div class="stat-value" id="statErrors">0</div>
            <div class="stat-sub">this session</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-label">Speed</div>
            <div class="stat-value" id="statSpeed">—</div>
            <div class="stat-sub">files / minute</div>
        </div>
    </div>

    <div class="controls-bar">
        <span class="bar-title">⚙️ Controls</span>
        <div class="progress-wrap"><div class="progress-inner" id="progressBar"></div></div>
        <span class="progress-label" id="progressLabel">—</span>
        <label class="workers-label">Workers
            <select id="workerCount">
                <option value="1">1</option>
                <option value="2" selected>2</option>
                <option value="3">3</option>
                <option value="4">4</option>
            </select>
        </label>
        <button class="btn btn-refresh" id="btnRefresh" onclick="loadQueue()">⟳ Refresh</button>
        <button class="btn btn-start"   id="btnStart"   onclick="startProcessing()">▶ Start</button>
        <button class="btn btn-stop"    id="btnStop"    onclick="stopProcessing()" disabled>⏹ Stop</button>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>📋 Video Queue</h2>
            <span style="font-size:11px;color:#94a3b8;" id="queueCount">Loading…</span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Filename</th>
                        <th>DB Size</th>
                        <th>Format</th>
                        <th style="width:150px;">Status</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody id="queueBody">
                    <tr><td colspan="6"><div class="empty-state"><div class="ei">⏳</div><p>Loading…</p></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="panel-head" style="border-top:1.5px solid #e2e8f0;">
            <h2>📄 Activity Log</h2>
            <button class="clear-btn" onclick="clearLog()">Clear</button>
        </div>
        <div class="log-box" id="logBox">
            <span class="l-info">[ready] Page loaded — click ▶ Start to begin.</span>
        </div>
    </div>

</div>

<script>
let queue         = [];
let rowStatus     = {};
let running       = false;
let stopFlag      = false;
let sessionErrors = 0;
let sessionDone   = 0;
let sessionStart  = null;

// ── Utilities ─────────────────────────────────────────────────
function ts() { return new Date().toLocaleTimeString(); }

function logLine(cls, msg) {
    const box = document.getElementById('logBox');
    box.innerHTML += `\n<span class="${cls}">[${ts()}] ${msg}</span>`;
    box.scrollTop = box.scrollHeight;
}
function clearLog() {
    document.getElementById('logBox').innerHTML = '<span class="l-info">[cleared]</span>';
}
function setButtons(proc) {
    document.getElementById('btnStart').disabled      = proc;
    document.getElementById('btnStop').disabled       = !proc;
    document.getElementById('btnRefresh').disabled    = proc;
    document.getElementById('workerCount').disabled   = proc;
}
function updateProgress() {
    const total = queue.length;
    const done  = Object.values(rowStatus).filter(s => ['done','error'].includes(s.status)).length;
    const pct   = total ? Math.round(done / total * 100) : 0;
    document.getElementById('progressBar').style.width    = pct + '%';
    document.getElementById('progressLabel').textContent  = total ? `${done} / ${total} (${pct}%)` : '—';
}
function updateSpeed() {
    if (!sessionStart || !sessionDone) { document.getElementById('statSpeed').textContent = '—'; return; }
    document.getElementById('statSpeed').textContent =
        (sessionDone / ((Date.now() - sessionStart) / 60000)).toFixed(1);
}
async function refreshStats() {
    try {
        const fd = new FormData(); fd.append('ajax_action', 'fetch_stats');
        const d = await (await fetch('', {method:'POST', body:fd})).json();
        document.getElementById('statTotal').textContent   = d.total   ?? '—';
        document.getElementById('statPending').textContent = d.pending ?? '—';
        document.getElementById('statDone').textContent    = d.done    ?? '—';
    } catch(e) {}
}

// ── Queue ─────────────────────────────────────────────────────
async function loadQueue() {
    document.getElementById('queueCount').textContent = 'Loading…';
    try {
        const fd = new FormData(); fd.append('ajax_action', 'fetch_queue');
        queue = await (await fetch('', {method:'POST', body:fd})).json();
        queue.forEach(r => {
            if (!rowStatus[r.id]) rowStatus[r.id] = {status:'pending', result:''};
        });
        renderTable();
        document.getElementById('queueCount').textContent =
            queue.length ? `${queue.length} file(s) queued` : 'Queue empty ✅';
        updateProgress();
        await refreshStats();
        logLine('l-info', `Queue loaded: ${queue.length} file(s).`);
    } catch(e) {
        logLine('l-err', 'Failed to load queue: ' + e.message);
    }
}

function renderTable() {
    const tbody = document.getElementById('queueBody');
    if (!queue.length) {
        tbody.innerHTML = `<tr><td colspan="6"><div class="empty-state"><div class="ei">✅</div><p>All videos processed!</p></div></td></tr>`;
        return;
    }
    const bmap = {
        pending: `<span class="badge b-pending">Pending</span>`,
        running: `<span class="badge b-running"><span class="spin"></span> Running…</span>`,
        done:    `<span class="badge b-done">✓ Done</span>`,
        error:   `<span class="badge b-error">✗ Error</span>`,
    };
    tbody.innerHTML = queue.map(row => {
        const st    = rowStatus[row.id] || {status:'pending', result:''};
        const badge = bmap[st.status] ?? bmap['pending'];
        const res   = st.result
            ? `<span title="${st.result.replace(/"/g,'&quot;')}" style="max-width:220px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px;color:#64748b;">${st.result}</span>`
            : '—';
        return `<tr id="row-${row.id}">
            <td style="color:#94a3b8;font-size:11px;">${row.id}</td>
            <td class="fn" title="${row.image_name}">${row.image_name}</td>
            <td style="white-space:nowrap;">${row.file_size || '—'}</td>
            <td>${row.media_format || '—'}</td>
            <td>${badge}</td>
            <td>${res}</td>
        </tr>`;
    }).join('');
}

function updateRow(id, status, result) {
    if (!rowStatus[id]) rowStatus[id] = {status, result};
    rowStatus[id].status = status;
    rowStatus[id].result = result;
    renderTable();
    updateProgress();
    updateSpeed();
}

// ── Process one file (synchronous ffmpeg call on VPS) ─────────
async function processOne(row) {
    updateRow(row.id, 'running', '');
    logLine('l-info', `#${row.id} → ${row.image_name}`);
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'process_one');
        fd.append('id', row.id);

        // ffmpeg runs on the VPS — may take 10–120s depending on file size.
        // fetch timeout is set high accordingly.
        const ctrl    = new AbortController();
        const timer   = setTimeout(() => ctrl.abort(), 180_000); // 3 min hard timeout
        const resp    = await fetch('', {method:'POST', body:fd, signal:ctrl.signal});
        clearTimeout(timer);
        const d       = await resp.json();

        if (!d.success) {
            sessionErrors++;
            document.getElementById('statErrors').textContent = sessionErrors;
            updateRow(row.id, 'error', d.message);
            logLine('l-err', `#${row.id} ERROR — ${d.message}`);
            return;
        }

        sessionDone++;
        const info = `${d.input_size} → ${d.output_size} | ${d.saved_to}`;
        updateRow(row.id, 'done', info);
        logLine('l-ok', `#${row.id} DONE — ${info}`);

    } catch(e) {
        sessionErrors++;
        document.getElementById('statErrors').textContent = sessionErrors;
        const msg = e.name === 'AbortError' ? 'Timed out (>3 min)' : e.message;
        updateRow(row.id, 'error', msg);
        logLine('l-err', `#${row.id} FETCH ERROR — ${msg}`);
    }
    await refreshStats();
}

// ── Worker pool ───────────────────────────────────────────────
async function startProcessing() {
    if (running) return;

    const pending = queue.filter(r => {
        const s = rowStatus[r.id]?.status;
        return !s || s === 'pending';
    });
    if (!pending.length) {
        logLine('l-info', 'No pending items — refreshing…');
        await loadQueue();
        return;
    }

    running      = true;
    stopFlag     = false;
    sessionStart = Date.now();
    setButtons(true);

    const WORKERS = parseInt(document.getElementById('workerCount').value) || 2;
    logLine('l-info', `▶ Started — ${pending.length} file(s), ${WORKERS} worker(s)`);

    let index = 0;

    async function worker(wid) {
        while (true) {
            if (stopFlag) break;
            const i = index++;
            if (i >= pending.length) break;
            logLine('l-info', `[W${wid}] #${pending[i].id} ${pending[i].image_name}`);
            await processOne(pending[i]);
        }
    }

    await Promise.all(Array.from({length: WORKERS}, (_, i) => worker(i + 1)));

    running = false;
    setButtons(false);
    logLine(sessionErrors ? 'l-err' : 'l-ok',
        `✅ Session done — ${sessionDone} OK, ${sessionErrors} errors`);
    await refreshStats();
}

function stopProcessing() {
    stopFlag = true;
    logLine('l-info', '⏸ Stop requested — current file(s) will finish first…');
}

window.addEventListener('DOMContentLoaded', loadQueue);
</script>
</body>
</html>
