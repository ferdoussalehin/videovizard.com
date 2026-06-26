<?php
require_once 'config.php';
set_time_limit(120);
ini_set('display_errors', 1);

$apiKey    = $apiKey ?? $myApiKey ?? $api_Key ?? $openai_key ?? null;
$poses_dir = __DIR__ . '/promo_models/';
$valid     = ['front','back','left_side','right_side','upper_front','upper_back'];

// ── AJAX: analyze + rename single image ─────────────────────
if (isset($_GET['analyze'])) {
    header('Content-Type: application/json');

    $cat  = basename(trim($_GET['cat']  ?? ''));
    $file = basename(trim($_GET['file'] ?? ''));
    $path = $poses_dir . $cat . '/' . $file;

    if (!$cat || !$file)     { echo json_encode(['success'=>false,'error'=>'Missing params']); exit; }
    if (!file_exists($path)) { echo json_encode(['success'=>false,'error'=>'Not found: '.$path]); exit; }
    if (!$apiKey)            { echo json_encode(['success'=>false,'error'=>'No OpenAI API key']); exit; }

    // Skip if already has a pose suffix
    $name_no_ext = pathinfo($file, PATHINFO_FILENAME);
    $ext         = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    foreach ($valid as $v) {
        if (str_ends_with($name_no_ext, '_'.$v)) {
            echo json_encode(['success'=>true,'pose'=>$v,'skipped'=>true,'file'=>$file]);
            exit;
        }
    }

    // Call GPT-4o-mini vision
    $mime = mime_content_type($path);
    $b64  = base64_encode(file_get_contents($path));
    $ch   = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$apiKey],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'=>'gpt-4o-mini','max_tokens'=>10,
            'messages'=>[['role'=>'user','content'=>[
                ['type'=>'image_url','image_url'=>['url'=>"data:{$mime};base64,{$b64}",'detail'=>'low']],
                ['type'=>'text','text'=>'Fashion model photo. Reply ONE word only from: front, back, left_side, right_side, upper_front, upper_back. Only the word.'],
            ]]],
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) { echo json_encode(['success'=>false,'error'=>"OpenAI HTTP $http: ".substr($res,0,200)]); exit; }

    $j    = json_decode($res, true);
    $raw  = strtolower(trim($j['choices'][0]['message']['content'] ?? ''));
    $pose = 'unknown';
    foreach ($valid as $v) { if (strpos($raw,$v) !== false) { $pose = $v; break; } }

    // Rename file: add _pose suffix before extension
    $new_file = $name_no_ext . '_' . $pose . '.' . $ext;
    $new_path = $poses_dir . $cat . '/' . $new_file;

    if (rename($path, $new_path)) {
        echo json_encode(['success'=>true,'pose'=>$pose,'old'=>$file,'new'=>$new_file]);
    } else {
        echo json_encode(['success'=>false,'error'=>'Rename failed: '.$path.' → '.$new_path]);
    }
    exit;
}

// ── Scan all images ──────────────────────────────────────────
$all = [];
if (is_dir($poses_dir)) {
    foreach (glob($poses_dir . '*', GLOB_ONLYDIR) as $dir) {
        $cat = basename($dir);
        foreach (glob($dir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $fp) {
            $file        = basename($fp);
            $name_no_ext = pathinfo($file, PATHINFO_FILENAME);
            // Check if already has pose suffix
            $pose = null;
            foreach ($valid as $v) {
                if (str_ends_with($name_no_ext, '_'.$v)) { $pose = $v; break; }
            }
            $all[] = ['cat'=>$cat,'file'=>$file,'pose'=>$pose,'done'=>$pose!==null];
        }
    }
}

$total = count($all);
$done  = count(array_filter($all, fn($i) => $i['done']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analyze & Rename Model Poses</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f3f4f6;padding:20px}
.wrap{max-width:960px;margin:0 auto}
h1{font-size:20px;font-weight:700;margin-bottom:4px}
.sub{font-size:13px;color:#6b7280;margin-bottom:20px}
.stats{display:flex;gap:12px;margin-bottom:16px}
.stat{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px 16px;flex:1;text-align:center}
.stat b{display:block;font-size:26px;font-weight:800;color:#0f2a44}
.stat span{font-size:11px;color:#6b7280}
.pbar{background:#e5e7eb;border-radius:99px;height:8px;margin-bottom:16px;overflow:hidden}
.pfill{background:#10b981;height:100%;border-radius:99px;transition:width .4s}
.btns{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.btn{padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit}
.btn-start{background:#0f2a44;color:#fff}
.btn-start:disabled{background:#9ca3af;cursor:not-allowed}
#status{font-size:13px;color:#6b7280}
.log{background:#1e293b;color:#e2e8f0;border-radius:8px;padding:14px;height:200px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;margin-bottom:16px}
.log .ok{color:#10b981}.log .err{color:#f87171}.log .info{color:#60a5fa}.log .skip{color:#6b7280}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.card{background:#fff;border:2px solid #e5e7eb;border-radius:8px;overflow:hidden}
.card.done{border-color:#10b981}
.card img{width:100%;aspect-ratio:2/3;object-fit:cover;object-position:top;display:block;background:#f9fafb}
.info{padding:5px 6px;font-size:10px}
.fname{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#374151}
.cat{color:#9ca3af;font-size:9px;margin-top:1px}
.badge{display:inline-block;margin-top:3px;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:#e5e7eb;color:#374151}
.front{background:#d1fae5;color:#065f46}
.back{background:#fce7f3;color:#9d174d}
.left_side{background:#fef3c7;color:#92400e}
.right_side{background:#ede9fe;color:#5b21b6}
.upper_front{background:#dbeafe;color:#1e40af}
.upper_back{background:#fee2e2;color:#991b1b}
</style>
</head>
<body>
<div class="wrap">
  <h1>🎭 Analyze & Rename Model Poses</h1>
  <p class="sub">Classifies each image with GPT-4o-mini and renames it — e.g. <code>pose_p1_front.jpg</code>. Run once, done forever.</p>

  <?php if (!$apiKey): ?>
  <div style="background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;">
    ⚠ No OpenAI API key found in config.php
  </div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><b id="s-total"><?= $total ?></b><span>Total</span></div>
    <div class="stat"><b id="s-done"><?= $done ?></b><span>Renamed</span></div>
    <div class="stat"><b id="s-rem"><?= $total-$done ?></b><span>Remaining</span></div>
    <div class="stat"><b id="s-pct"><?= $total ? round($done/$total*100) : 0 ?>%</b><span>Complete</span></div>
  </div>

  <div class="pbar"><div class="pfill" id="pfill" style="width:<?= $total ? round($done/$total*100) : 0 ?>%"></div></div>

  <div class="btns">
    <button class="btn btn-start" id="btn" onclick="toggle()" <?= !$apiKey?'disabled':'' ?>>▶ Start</button>
    <span id="status"><?= $done > 0 ? "$done of $total already done" : '' ?></span>
  </div>

  <div class="log" id="log"><span class="info">Ready — click Start to analyze and rename all model images</span></div>

  <div class="grid" id="grid">
    <?php foreach ($all as $img): ?>
      <div class="card <?= $img['done']?'done':'' ?>" data-cat="<?= htmlspecialchars($img['cat']) ?>" data-file="<?= htmlspecialchars($img['file']) ?>">
        <img src="promo_models/<?= htmlspecialchars($img['cat'].'/'. $img['file']) ?>" loading="lazy">
        <div class="info">
          <div class="fname"><?= htmlspecialchars($img['file']) ?></div>
          <div class="cat"><?= htmlspecialchars($img['cat']) ?></div>
          <?php if ($img['pose']): ?>
            <span class="badge <?= $img['pose'] ?>"><?= $img['pose'] ?></span>
          <?php else: ?>
            <span class="badge" style="color:#d1d5db">pending</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
var all     = <?= json_encode($all) ?>;
var running = false;
var stop    = false;

function log(msg, cls) {
  var el  = document.getElementById('log');
  var div = document.createElement('div');
  if (cls) div.className = cls;
  div.textContent = new Date().toLocaleTimeString() + ' — ' + msg;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}

function setStats(done) {
  var t = all.length;
  document.getElementById('s-done').textContent = done;
  document.getElementById('s-rem').textContent  = t - done;
  document.getElementById('s-pct').textContent  = t ? Math.round(done/t*100)+'%' : '0%';
  document.getElementById('pfill').style.width  = t ? Math.round(done/t*100)+'%' : '0%';
}

function updateCard(cat, file, newFile, pose) {
  var cards = document.querySelectorAll('.card');
  cards.forEach(function(c) {
    if (c.dataset.cat === cat && c.dataset.file === file) {
      c.className    = 'card done';
      c.dataset.file = newFile;
      var img        = c.querySelector('img');
      if (img) img.src = 'promo_models/' + cat + '/' + newFile + '?t=' + Date.now();
      var fn = c.querySelector('.fname');
      if (fn) fn.textContent = newFile;
      var old = c.querySelector('.badge');
      if (old) { old.className = 'badge ' + pose; old.textContent = pose; }
    }
  });
}

function toggle() {
  if (running) {
    stop = true;
    document.getElementById('btn').textContent = '▶ Resume';
    return;
  }
  stop    = false;
  running = true;
  document.getElementById('btn').textContent = '⏸ Pause';
  run();
}

async function run() {
  var pending = all.filter(function(i){ return !i.done; });
  var done    = all.filter(function(i){ return  i.done; }).length;

  log('Starting — ' + pending.length + ' images to process', 'info');

  for (var i = 0; i < pending.length; i++) {
    if (stop) { log('Paused.', 'info'); running = false; return; }

    var img = pending[i];
    document.getElementById('status').textContent = (i+1) + '/' + pending.length + ': ' + img.file;

    try {
      var url = '?analyze=1&cat=' + encodeURIComponent(img.cat) + '&file=' + encodeURIComponent(img.file);
      var r   = await fetch(url);
      var txt = await r.text();
      var d;
      try { d = JSON.parse(txt); } catch(e) { throw new Error('Bad response: ' + txt.substr(0,100)); }

      if (d.success) {
        if (d.skipped) {
          log('⊘ ' + img.file + ' already has pose: ' + d.pose, 'skip');
        } else {
          img.done = true; img.file = d.new; done++;
          updateCard(img.cat, d.old, d.new, d.pose);
          setStats(done);
          log('✓ ' + d.old + ' → ' + d.new, 'ok');
        }
      } else {
        log('✗ ' + img.file + ': ' + (d.error||'failed'), 'err');
      }
    } catch(e) {
      log('✗ ' + img.file + ': ' + e.message, 'err');
    }

    await new Promise(function(r){ setTimeout(r, 400); });
  }

  running = false;
  document.getElementById('btn').textContent    = done < all.length ? '▶ Resume' : '✅ All Done';
  document.getElementById('status').textContent = '✅ Complete — ' + done + '/' + all.length + ' renamed';
  log('Done! ' + done + '/' + all.length + ' images renamed.', 'ok');
}
</script>
</body>
</html>
