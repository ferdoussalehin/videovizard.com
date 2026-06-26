<?php
// tag_media.php
// Batch AI tagger for hdb_image_data — adds natural_language_tags to all records
// Images: GPT-4o Vision reads the actual file
// Videos: GPT-4o-mini uses filename + existing hashtags

require_once 'config.php'; // provides $apiKey, $conn

ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/tag_media.log');
ini_set('display_errors', 0);

// $apiKey and $conn are already set by config.php
$imageDir   = __DIR__ . '/podcast_images/';
$imageDirUrl = 'podcast_images/';
$batchSize  = 20;

// ── Ensure column exists ──────────────────────────────────────
mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN IF NOT EXISTS natural_language_tags TEXT NULL");

// ── AJAX: get stats ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    header('Content-Type: application/json');
    $total    = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM hdb_image_data"))[0];
    $tagged   = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM hdb_image_data WHERE natural_language_tags IS NOT NULL AND natural_language_tags != ''"))[0];
    $images   = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM hdb_image_data WHERE media_type = 'image' OR media_type IS NULL OR media_type = ''"))[0];
    $videos   = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM hdb_image_data WHERE media_type = 'video'"))[0];
    echo json_encode(['total'=>(int)$total,'tagged'=>(int)$tagged,'untagged'=>(int)$total-(int)$tagged,'images'=>(int)$images,'videos'=>(int)$videos]);
    exit;
}

// ── AJAX: process one batch ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'batch') {
    header('Content-Type: application/json');
    $apiKey   = $GLOBALS['apiKey'];
    $imageDir = $GLOBALS['imageDir'];
    $conn     = $GLOBALS['conn'];
    $offset    = (int)($_GET['offset'] ?? 0);
    $mediaType = $_GET['media_type'] ?? 'all'; // all | image | video
    $retagAll  = (int)($_GET['retag'] ?? 0);

    $whereType = '';
    if ($mediaType === 'image') $whereType = "AND (media_type = 'image' OR media_type IS NULL OR media_type = '')";
    if ($mediaType === 'video') $whereType = "AND media_type = 'video'";

    $whereTagged = $retagAll ? '' : "AND (natural_language_tags IS NULL OR natural_language_tags = '')";

    $sql = "SELECT id, image_name, image_hashtags, media_type, description
            FROM hdb_image_data
            WHERE 1=1 $whereType $whereTagged
            ORDER BY id ASC
            LIMIT $batchSize OFFSET $offset";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
        exit;
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;

    if (empty($rows)) {
        echo json_encode(['success'=>true,'processed'=>0,'results'=>[],'done'=>true]);
        exit;
    }

    $results = [];
    foreach ($rows as $row) {
        $id        = (int)$row['id'];
        $filename  = $row['image_name'];
        $hashtags  = $row['image_hashtags'] ?? '';
        $mediaType = strtolower(trim($row['media_type'] ?? ''));
        $isVideo   = ($mediaType === 'video');
        $filepath  = $imageDir . $filename;

        error_log("Processing ID $id | $filename | type=$mediaType");

        try {
            if ($isVideo) {
                $tags = generateTagsForVideo($filename, $hashtags, $apiKey);
            } else {
                if (file_exists($filepath)) {
                    $tags = generateTagsForImage($filepath, $filename, $hashtags, $apiKey);
                } else {
                    // File missing — fall back to text-based tagging
                    error_log("  File not found: $filepath — using text fallback");
                    $tags = generateTagsForVideo($filename, $hashtags, $apiKey);
                }
            }

            if ($tags) {
                $tagsEsc = mysqli_real_escape_string($conn, $tags);
                mysqli_query($conn, "UPDATE hdb_image_data SET natural_language_tags = '$tagsEsc' WHERE id = $id");
                $results[] = ['id'=>$id,'file'=>$filename,'tags'=>$tags,'status'=>'ok'];
                error_log("  ✅ Tagged: $tags");
            } else {
                $results[] = ['id'=>$id,'file'=>$filename,'tags'=>'','status'=>'empty'];
                error_log("  ⚠️ Empty tags returned");
            }
        } catch (Throwable $e) {
            error_log("  ❌ Error: " . $e->getMessage());
            $results[] = ['id'=>$id,'file'=>$filename,'tags'=>'','status'=>'error','msg'=>$e->getMessage()];
        }

        // Small delay to stay within rate limits
        usleep(300000); // 0.3s between calls
    }

    echo json_encode([
        'success'   => true,
        'processed' => count($results),
        'results'   => $results,
        'done'      => count($rows) < $batchSize,
        'next_offset' => $offset + count($rows),
    ]);
    exit;
}

// ── Generate tags for IMAGE using GPT-4o Vision ───────────────
function generateTagsForImage($filepath, $filename, $hashtags, $apiKey) {
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeMap  = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
    $mimeType = $mimeMap[$ext] ?? 'image/jpeg';

    $imageData = base64_encode(file_get_contents($filepath));

    $prompt = "You are a stock media search expert. Look at this image carefully.
Generate 6-8 natural language search phrases that someone would type into a stock photo search engine to find this image.
Think about: who is in it, what they're doing, where they are, the mood, and visual style.

Rules:
- Each phrase should be 3-6 words
- Use plain descriptive English
- Separate phrases with a pipe character |
- No hashtags, no punctuation other than pipes
- Focus on what's VISUALLY present

Example output: woman sitting alone at home|stressed person looking worried|person in cozy living room|anxious woman indoors|woman dealing with difficult emotions

Return ONLY the pipe-separated phrases, nothing else.";

    $payload = json_encode([
        'model'      => 'gpt-4o',
        'max_tokens' => 200,
        'messages'   => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => "data:$mimeType;base64,$imageData", 'detail' => 'low']],
                ['type' => 'text',      'text'      => $prompt]
            ]
        ]]
    ]);

    return callOpenAI($payload, $apiKey);
}

// ── Generate tags for VIDEO using filename + hashtags ─────────
function generateTagsForVideo($filename, $hashtags, $apiKey) {
    $name    = pathinfo($filename, PATHINFO_FILENAME);
    $name    = preg_replace('/[_\-]+/', ' ', $name);
    $name    = preg_replace('/\d+/', '', $name);
    $name    = trim($name);
    $context = $name . ($hashtags ? ' ' . $hashtags : '');

    $prompt  = "You are a stock media search expert. Based on this video filename and keywords, generate 6-8 natural language search phrases that someone would type to find this video.

Filename/keywords: $context

Rules:
- Each phrase should be 3-6 words
- Use plain descriptive English
- Separate phrases with a pipe character |
- No hashtags, no punctuation other than pipes
- Think about what scenes this video likely shows

Example output: people walking in city street|busy urban pedestrians daytime|modern city lifestyle footage|commuters in downtown area

Return ONLY the pipe-separated phrases, nothing else.";

    $payload = json_encode([
        'model'      => 'gpt-4o-mini',
        'max_tokens' => 200,
        'messages'   => [['role'=>'user','content'=>$prompt]]
    ]);

    return callOpenAI($payload, $apiKey);
}

// ── OpenAI API call ───────────────────────────────────────────
function callOpenAI($payload, $apiKey) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)        throw new Exception("cURL: $curlErr");
    if ($httpCode !== 200) throw new Exception("HTTP $httpCode: " . substr($response, 0, 200));

    $decoded = json_decode($response, true);
    $content = trim($decoded['choices'][0]['message']['content'] ?? '');

    // Clean up — remove any accidental hashtags or extra formatting
    $content = preg_replace('/#\w+/', '', $content);
    $content = preg_replace('/\s+/', ' ', $content);
    return trim($content, " \t\n\r|");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Media Tagger — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--dark:#0f2a44;--mid:#143b63;--purple:#8b5cf6;--green:#10b981;--red:#ef4444;--amber:#f59e0b;--border:#e2e8f0;--bg:#f8fafc;--muted:#64748b;--text:#1e293b}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
header{background:linear-gradient(90deg,#0f2a44,#143b63);padding:12px 24px;display:flex;align-items:center;gap:10px;color:#fff;font-size:18px;font-weight:700}
header span{color:#5fd1ff}
.wrap{max-width:900px;margin:0 auto;padding:28px 16px}
.card{background:#fff;border-radius:16px;border:1px solid var(--border);box-shadow:0 4px 12px rgba(0,0,0,.07);margin-bottom:20px;overflow:hidden}
.card-head{padding:16px 22px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid var(--border)}
.card-head h2{font-size:17px;font-weight:700;color:var(--dark);margin-bottom:3px}
.card-head p{font-size:13px;color:var(--muted)}
.card-body{padding:22px}
/* Stats grid */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:24px}
.stat{background:#f7f9fc;border:1px solid var(--border);border-radius:12px;padding:14px 16px;text-align:center}
.stat-n{font-size:26px;font-weight:800;color:var(--dark);line-height:1}
.stat-l{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:4px}
.stat-n.green{color:var(--green)}
.stat-n.amber{color:var(--amber)}
/* Progress bar */
.prog-wrap{background:var(--border);border-radius:8px;height:20px;overflow:hidden;margin:12px 0}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--dark),var(--purple));border-radius:8px;transition:width .3s;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;min-width:2%}
/* Controls */
.controls{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;align-items:center}
select,input[type=number]{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;color:var(--text);background:#fff;outline:none}
select:focus,input:focus{border-color:var(--purple)}
.btn{padding:10px 20px;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.btn-primary{background:linear-gradient(135deg,var(--dark),var(--mid));color:#fff}
.btn-primary:hover:not(:disabled){opacity:.9}
.btn-green{background:linear-gradient(135deg,var(--green),#059669);color:#fff}
.btn-green:hover:not(:disabled){opacity:.9}
.btn-red{background:var(--red);color:#fff}
.btn-amber{background:var(--amber);color:#fff}
.btn-ghost{background:#f1f5f9;color:var(--text);border:1.5px solid var(--border)}
.btn-ghost:hover{border-color:var(--purple);color:var(--purple)}
/* Log */
.log-box{background:#0f172a;color:#e2e8f0;border-radius:10px;padding:14px;max-height:320px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.7}
.log-ok{color:#4ade80}.log-err{color:#f87171}.log-info{color:#60a5fa}.log-warn{color:#fbbf24}
/* Results table */
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-weight:600;color:var(--dark);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.tbl tr:hover td{background:#fafbfc}
.badge{display:inline-block;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px}
.badge-ok{background:#dcfce7;color:#166534}
.badge-err{background:#fee2e2;color:#991b1b}
.badge-warn{background:#fef3c7;color:#92400e}
.tag-list{color:var(--muted);font-size:12px;line-height:1.5}
</style>
</head>
<body>
<header>🎬 <span>VideoVizard</span> — AI Media Tagger</header>

<div class="wrap">

  <!-- Stats -->
  <div class="card">
    <div class="card-head">
      <h2>📊 Database Status</h2>
      <p>Current tagging progress across all media</p>
    </div>
    <div class="card-body">
      <div class="stats" id="stats">
        <div class="stat"><div class="stat-n" id="s-total">—</div><div class="stat-l">Total</div></div>
        <div class="stat"><div class="stat-n green" id="s-tagged">—</div><div class="stat-l">Tagged</div></div>
        <div class="stat"><div class="stat-n amber" id="s-untagged">—</div><div class="stat-l">Untagged</div></div>
        <div class="stat"><div class="stat-n" id="s-images">—</div><div class="stat-l">Images</div></div>
        <div class="stat"><div class="stat-n" id="s-videos">—</div><div class="stat-l">Videos</div></div>
      </div>
      <div class="prog-wrap"><div class="prog-fill" id="prog-fill" style="width:0%">0%</div></div>
      <p id="prog-text" style="font-size:12px;color:var(--muted);margin-top:6px;">Loading stats…</p>
    </div>
  </div>

  <!-- Controls -->
  <div class="card">
    <div class="card-head">
      <h2>⚙️ Tagger Controls</h2>
      <p>Configure and run the batch tagging job</p>
    </div>
    <div class="card-body">
      <div class="controls">
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;">Media Type</label>
          <select id="opt-type">
            <option value="all">All media</option>
            <option value="image">Images only</option>
            <option value="video">Videos only</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;">Mode</label>
          <select id="opt-retag">
            <option value="0">Untagged only (skip already tagged)</option>
            <option value="1">Retag everything (overwrite)</option>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--muted);display:block;margin-bottom:4px;">Batch size</label>
          <input type="number" id="opt-batch" value="20" min="1" max="50" style="width:80px">
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-green" id="btn-start" onclick="startTagging()">▶ Start Tagging</button>
        <button class="btn btn-red"   id="btn-stop"  onclick="stopTagging()" disabled>⏹ Stop</button>
        <button class="btn btn-ghost"               onclick="loadStats()">↻ Refresh Stats</button>
      </div>

      <div style="margin-top:16px">
        <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:6px;">Session Progress</div>
        <div class="prog-wrap" style="height:14px"><div class="prog-fill" id="session-prog" style="width:0%"></div></div>
        <p id="session-text" style="font-size:12px;color:var(--muted);margin-top:4px;">Not started</p>
      </div>
    </div>
  </div>

  <!-- Live log -->
  <div class="card">
    <div class="card-head" style="display:flex;align-items:center;justify-content:space-between">
      <div><h2>📋 Live Log</h2><p>Real-time processing output</p></div>
      <button class="btn btn-ghost" style="padding:6px 12px;font-size:12px" onclick="clearLog()">Clear</button>
    </div>
    <div class="card-body" style="padding:16px">
      <div class="log-box" id="log">
        <div class="log-info">Ready. Press Start Tagging to begin.</div>
      </div>
    </div>
  </div>

  <!-- Results table -->
  <div class="card" id="results-card" style="display:none">
    <div class="card-head">
      <h2>✅ Session Results</h2>
      <p id="results-summary">—</p>
    </div>
    <div class="card-body" style="padding:0">
      <div style="overflow-x:auto">
        <table class="tbl">
          <thead><tr><th>ID</th><th>File</th><th>Type</th><th>Generated Tags</th><th>Status</th></tr></thead>
          <tbody id="results-body"></tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
let running     = false;
let stopFlag    = false;
let totalTagged = 0;
let totalErrors = 0;
let sessionDone = 0;
let sessionTotal= 0;
let offset      = 0;

/* ── Stats ── */
async function loadStats() {
  try {
    const r = await fetch('?action=stats');
    const d = await r.json();
    document.getElementById('s-total').textContent   = d.total;
    document.getElementById('s-tagged').textContent  = d.tagged;
    document.getElementById('s-untagged').textContent = d.untagged;
    document.getElementById('s-images').textContent  = d.images;
    document.getElementById('s-videos').textContent  = d.videos;
    const pct = d.total > 0 ? Math.round((d.tagged / d.total) * 100) : 0;
    document.getElementById('prog-fill').style.width = pct + '%';
    document.getElementById('prog-fill').textContent = pct + '%';
    document.getElementById('prog-text').textContent = `${d.tagged} of ${d.total} records tagged (${pct}%)`;
  } catch(e) {
    log('Error loading stats: ' + e.message, 'err');
  }
}

/* ── Start ── */
async function startTagging() {
  if (running) return;
  running   = true;
  stopFlag  = false;
  offset    = 0;
  sessionDone  = 0;
  sessionTotal = 0;
  totalTagged  = 0;
  totalErrors  = 0;

  document.getElementById('btn-start').disabled = true;
  document.getElementById('btn-stop').disabled  = false;
  document.getElementById('results-card').style.display = 'none';
  document.getElementById('results-body').innerHTML = '';

  const mediaType = document.getElementById('opt-type').value;
  const retag     = document.getElementById('opt-retag').value;

  log('━━━━ Starting tagging job ━━━━', 'info');
  log(`Type: ${mediaType} | Mode: ${retag === '1' ? 'retag all' : 'untagged only'}`, 'info');

  // First pass to estimate total
  await loadStats();

  await runBatch(mediaType, retag);
}

async function runBatch(mediaType, retag) {
  if (stopFlag) {
    log('⏹ Stopped by user at offset ' + offset, 'warn');
    finish();
    return;
  }

  try {
    const url = `?action=batch&offset=${offset}&media_type=${mediaType}&retag=${retag}`;
    log(`Fetching batch at offset ${offset}…`, 'info');

    const r = await fetch(url);
    const d = await r.json();

    if (!d.success) {
      log('❌ Server error: ' + (d.error || 'unknown'), 'err');
      finish();
      return;
    }

    if (d.processed === 0 || d.done) {
      log('✅ All records processed!', 'ok');
      loadStats();
      finish();
      return;
    }

    // Process results
    d.results.forEach(item => {
      sessionDone++;
      if (item.status === 'ok') {
        totalTagged++;
        log(`✅ #${item.id} ${truncate(item.file, 30)} → ${truncate(item.tags, 80)}`, 'ok');
        addRow(item);
      } else if (item.status === 'error') {
        totalErrors++;
        log(`❌ #${item.id} ${truncate(item.file, 30)} — ${item.msg}`, 'err');
        addRow(item);
      } else {
        log(`⚠️ #${item.id} ${truncate(item.file, 30)} — empty tags`, 'warn');
        addRow(item);
      }
    });

    offset = d.next_offset;

    // Update session progress
    document.getElementById('session-text').textContent =
      `Processed ${sessionDone} records this session | ✅ ${totalTagged} tagged | ❌ ${totalErrors} errors`;
    document.getElementById('session-prog').style.width =
      Math.min(100, Math.round((sessionDone / Math.max(sessionDone + 10, 1)) * 100)) + '%';

    document.getElementById('results-card').style.display = 'block';
    document.getElementById('results-summary').textContent =
      `${totalTagged} tagged, ${totalErrors} errors in this session`;

    await loadStats();

    // Continue with next batch after short pause
    if (!d.done && !stopFlag) {
      setTimeout(() => runBatch(mediaType, retag), 500);
    } else {
      log(d.done ? '🎉 Job complete!' : '⏹ Stopped.', 'ok');
      finish();
    }

  } catch(e) {
    log('❌ Fetch error: ' + e.message, 'err');
    finish();
  }
}

function stopTagging() {
  stopFlag = true;
  log('⏹ Stop requested — finishing current batch…', 'warn');
  document.getElementById('btn-stop').disabled = true;
}

function finish() {
  running = false;
  document.getElementById('btn-start').disabled = false;
  document.getElementById('btn-stop').disabled  = true;
  loadStats();
}

/* ── Log ── */
function log(msg, type = 'info') {
  const box = document.getElementById('log');
  const ts  = new Date().toLocaleTimeString();
  const cls = {ok:'log-ok', err:'log-err', info:'log-info', warn:'log-warn'}[type] || 'log-info';
  const el  = document.createElement('div');
  el.className = cls;
  el.textContent = `[${ts}] ${msg}`;
  box.appendChild(el);
  box.scrollTop = box.scrollHeight;
}
function clearLog() { document.getElementById('log').innerHTML = ''; }

/* ── Results table ── */
function addRow(item) {
  const tbody = document.getElementById('results-body');
  const ext   = item.file.split('.').pop().toLowerCase();
  const isVid = ['mp4','mov','avi','webm','mkv'].includes(ext);
  const badge = item.status === 'ok'
    ? '<span class="badge badge-ok">Tagged</span>'
    : item.status === 'error'
      ? '<span class="badge badge-err">Error</span>'
      : '<span class="badge badge-warn">Empty</span>';
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>${item.id}</td>
    <td style="max-width:160px;word-break:break-all;font-size:11px">${item.file}</td>
    <td>${isVid ? '🎬 Video' : '🖼 Image'}</td>
    <td class="tag-list">${item.tags ? item.tags.replace(/\|/g,'<br>') : '<span style="color:#ccc">—</span>'}</td>
    <td>${badge}${item.msg ? `<br><span style="font-size:10px;color:#f87171">${item.msg}</span>` : ''}</td>`;
  tbody.insertBefore(tr, tbody.firstChild); // newest on top
}

function truncate(s, n) { return s && s.length > n ? s.substring(0, n) + '…' : (s || ''); }

/* ── Init ── */
loadStats();
</script>
</body>
</html>
