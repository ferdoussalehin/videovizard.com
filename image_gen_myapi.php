<?php
/**
 * AI Image Generator — handles async generation via JS fetch
 * so cPanel timeouts never affect the user.
 *
 * When ?ajax=1 is posted, PHP acts as a proxy to Modal.
 * The browser polls every 2s and shows a live progress bar.
 */

// ── AJAX Proxy Mode ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    ini_set('memory_limit', '-1');

    header('Content-Type: application/json');

    $input   = json_decode(file_get_contents('php://input'), true);
    $prompt  = trim($input['prompt'] ?? '');
    $style   = $input['style']  ?? 'cinematic';
    $width   = (int)($input['width']  ?? 1024);
    $height  = (int)($input['height'] ?? 1024);

    if (!$prompt) {
        echo json_encode(['error' => 'Prompt is required.']);
        exit;
    }

    $apiUrl  = "https://inaamalvi1--applied-ai-api-web-api.modal.run/generate-image";
    $payload = json_encode([
        'prompt' => $prompt,
        'style'  => $style,
        'width'  => $width,
        'height' => $height,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_VERBOSE        => true,
        CURLOPT_TIMEOUT        => 0,        // ← KEY FIX: no cURL timeout
        CURLOPT_CONNECTTIMEOUT => 0,
        CURLOPT_NOSIGNAL       => true,     // Ignore system signals
        CURLOPT_FOLLOWLOCATION => true,        // follow the 303 redirect
        CURLOPT_MAXREDIRS      => 5,           // max 5 hops
        CURLOPT_POSTREDIR      => CURL_REDIR_POST_ALL, // keep POST method after redirect
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Connection failed: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['image'])) {
        echo json_encode([
            'success' => true,
            'image'   => $data['image'],   // already base64
            'seed'    => $data['seed'] ?? null,
        ]);
    } else {
        echo json_encode([
            'error'    => 'API returned an error.',
            'httpCode' => $httpCode,
            'detail'   => $data,
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VISIO — AI Image Studio</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #0c0c0e;
    --surface:  #13131a;
    --border:   #2a2a3a;
    --accent:   #c8a96e;
    --accent2:  #7c6daf;
    --text:     #e8e4dc;
    --muted:    #6b6880;
    --danger:   #c06060;
    --serif:    'Cormorant Garamond', Georgia, serif;
    --mono:     'DM Mono', monospace;
    --radius:   6px;
  }

  html, body {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: var(--mono);
    font-size: 14px;
    line-height: 1.6;
  }

  /* ── Grain overlay ── */
  body::before {
    content: '';
    position: fixed; inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
    pointer-events: none;
    z-index: 9999;
    opacity: .45;
  }

  /* ── Layout ── */
  .shell {
    max-width: 860px;
    margin: 0 auto;
    padding: 56px 24px 80px;
  }

  /* ── Header ── */
  .hdr { margin-bottom: 52px; }
  .hdr h1 {
    font-family: var(--serif);
    font-size: clamp(2.4rem, 6vw, 4rem);
    font-weight: 300;
    letter-spacing: .18em;
    color: var(--text);
    line-height: 1;
  }
  .hdr h1 em {
    font-style: italic;
    color: var(--accent);
  }
  .hdr p {
    margin-top: 10px;
    color: var(--muted);
    font-size: 12px;
    letter-spacing: .12em;
    text-transform: uppercase;
  }
  .divider {
    width: 48px; height: 1px;
    background: var(--accent);
    margin: 18px 0;
    opacity: .6;
  }

  /* ── Form card ── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 32px;
    margin-bottom: 28px;
  }

  .field { margin-bottom: 22px; }
  .field label {
    display: block;
    font-size: 11px;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 8px;
  }

  textarea, select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    padding: 14px 16px;
    transition: border-color .2s;
    outline: none;
    resize: vertical;
  }
  textarea:focus, select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(200,169,110,.08);
  }
  textarea { min-height: 130px; }

  /* ── Row of selects ── */
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 540px) { .row { grid-template-columns: 1fr; } }

  /* ── Submit btn ── */
  .btn-generate {
    width: 100%;
    padding: 16px;
    background: transparent;
    border: 1px solid var(--accent);
    border-radius: var(--radius);
    color: var(--accent);
    font-family: var(--mono);
    font-size: 12px;
    letter-spacing: .2em;
    text-transform: uppercase;
    cursor: pointer;
    transition: background .2s, color .2s;
    position: relative;
    overflow: hidden;
  }
  .btn-generate:hover:not(:disabled) {
    background: var(--accent);
    color: var(--bg);
  }
  .btn-generate:disabled { opacity: .4; cursor: not-allowed; }

  /* ── Progress ── */
  #progress-wrap {
    display: none;
    margin-bottom: 28px;
  }
  .progress-label {
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
  }
  .progress-track {
    height: 2px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
  }
  .progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--accent2), var(--accent));
    width: 0%;
    transition: width .4s ease;
    border-radius: 2px;
  }
  .progress-steps {
    margin-top: 12px;
    color: var(--muted);
    font-size: 11px;
  }
  .step { opacity: .3; transition: opacity .3s; }
  .step.active { opacity: 1; color: var(--accent); }
  .step.done   { opacity: .6; }

  /* ── Result ── */
  #result-wrap { display: none; }

  .result-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 8px;
  }
  .result-meta span {
    font-size: 11px;
    color: var(--muted);
    letter-spacing: .1em;
  }
  .result-meta strong { color: var(--accent); }

  .img-frame {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    background: #000;
    line-height: 0;
  }
  .img-frame img {
    width: 100%;
    height: auto;
    display: block;
    animation: fadeIn .6s ease;
  }
  @keyframes fadeIn { from { opacity:0; transform:scale(.98); } to { opacity:1; transform:scale(1); } }

  /* ── Action bar ── */
  .actions {
    display: flex;
    gap: 12px;
    margin-top: 16px;
    flex-wrap: wrap;
  }
  .btn-action {
    flex: 1;
    min-width: 130px;
    padding: 12px 18px;
    border-radius: var(--radius);
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: .15em;
    text-transform: uppercase;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    transition: background .2s, color .2s, border-color .2s;
  }
  .btn-dl {
    background: var(--accent);
    color: var(--bg);
    border: 1px solid var(--accent);
    font-weight: 600;
  }
  .btn-dl:hover { background: #d4b87a; }
  .btn-again {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--border);
  }
  .btn-again:hover { border-color: var(--text); }

  /* ── Error ── */
  #error-wrap {
    display: none;
    background: rgba(192,96,96,.08);
    border: 1px solid rgba(192,96,96,.3);
    border-radius: var(--radius);
    padding: 16px 20px;
    color: var(--danger);
    font-size: 12px;
    margin-bottom: 20px;
  }

  /* ── Spinner on button ── */
  @keyframes spin { to { transform: rotate(360deg); } }
  .spinner {
    display: inline-block;
    width: 12px; height: 12px;
    border: 1.5px solid var(--accent);
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin .7s linear infinite;
    margin-right: 8px;
    vertical-align: middle;
  }
</style>
</head>
<body>
<div class="shell">

  <!-- Header -->
  <header class="hdr">
    <h1>VIS<em>IO</em></h1>
    <div class="divider"></div>
    <p>Cinematic AI image studio &mdash; powered by FLUX</p>
  </header>

  <!-- Form -->
  <div class="card">
    <div class="field">
      <label>Describe your image</label>
      <textarea id="prompt" placeholder="A solitary figure standing at the edge of a misty cliff, golden hour light, ancient pine forest below…"></textarea>
    </div>

    <div class="row">
      <div class="field">
        <label>Visual style</label>
        <select id="style">
          <option value="cinematic">Cinematic</option>
          <option value="moody">Moody / Dark</option>
          <option value="warm">Warm / Golden</option>
        </select>
      </div>
      <div class="field">
        <label>Aspect ratio</label>
        <select id="size">
          <option value="1024x1024">1:1 — Square (1024×1024)</option>
          <option value="1152x896">4:3 — Landscape (1152×896)</option>
          <option value="896x1152">3:4 — Portrait (896×1152)</option>
          <option value="1344x768">16:9 — Widescreen (1344×768)</option>
          <option value="768x1344">9:16 — Vertical (768×1344)</option>
        </select>
      </div>
    </div>

    <button class="btn-generate" id="btn-gen" onclick="generate()">
      Generate Image
    </button>
  </div>

  <!-- Error -->
  <div id="error-wrap"></div>

  <!-- Progress -->
  <div id="progress-wrap" class="card">
    <div class="progress-label">
      <span id="progress-status">Initialising…</span>
      <span id="progress-pct">0%</span>
    </div>
    <div class="progress-track">
      <div class="progress-bar" id="progress-bar"></div>
    </div>
    <div class="progress-steps" id="progress-steps"></div>
  </div>

  <!-- Result -->
  <div id="result-wrap" class="card">
    <div class="result-meta">
      <span>Generation complete</span>
      <span>Seed: <strong id="seed-val">—</strong></span>
    </div>
    <div class="img-frame">
      <img id="result-img" src="" alt="Generated image">
    </div>
    <div class="actions">
      <a id="btn-download" class="btn-action btn-dl" href="#" download="visio_output.png">
        ↓ Download PNG
      </a>
      <button class="btn-action btn-again" onclick="resetUI()">
        ← Generate another
      </button>
    </div>
  </div>

</div><!-- /shell -->

<script>
const STEPS = [
  { label: "Warming up GPU…",        pct: 8  },
  { label: "Loading FLUX model…",    pct: 22 },
  { label: "Encoding prompt…",       pct: 38 },
  { label: "Running diffusion…",     pct: 60 },
  { label: "Denoising image…",       pct: 80 },
  { label: "Decoding to pixels…",    pct: 92 },
  { label: "Finalising output…",     pct: 98 },
];

let stepTimer  = null;
let currentStep = 0;

function setProgress(pct, label) {
  document.getElementById('progress-bar').style.width  = pct + '%';
  document.getElementById('progress-pct').textContent  = pct + '%';
  document.getElementById('progress-status').textContent = label;
}

function animateSteps() {
  const container = document.getElementById('progress-steps');
  container.innerHTML = STEPS.map((s, i) =>
    `<div class="step" id="step-${i}">◦ ${s.label}</div>`
  ).join('');

  currentStep = 0;
  function tick() {
    if (currentStep >= STEPS.length) return;
    // Mark previous done
    if (currentStep > 0) {
      document.getElementById(`step-${currentStep-1}`).classList.remove('active');
      document.getElementById(`step-${currentStep-1}`).classList.add('done');
    }
    const s = STEPS[currentStep];
    document.getElementById(`step-${currentStep}`).classList.add('active');
    setProgress(s.pct, s.label);
    currentStep++;
    // Spread steps across ~25s (modal cold start can be up to 30s)
    stepTimer = setTimeout(tick, currentStep < 3 ? 3500 : currentStep < 6 ? 4000 : 5000);
  }
  tick();
}

function stopStepAnimation() {
  if (stepTimer) { clearTimeout(stepTimer); stepTimer = null; }
  // Mark all done
  STEPS.forEach((_, i) => {
    const el = document.getElementById(`step-${i}`);
    if (el) { el.classList.remove('active'); el.classList.add('done'); }
  });
  setProgress(100, 'Complete!');
}

async function generate() {
  const prompt = document.getElementById('prompt').value.trim();
  if (!prompt) {
    showError('Please enter a prompt before generating.');
    return;
  }

  const style  = document.getElementById('style').value;
  const [w, h] = document.getElementById('size').value.split('x').map(Number);

  // Reset UI state
  hideError();
  document.getElementById('result-wrap').style.display = 'none';

  const btn = document.getElementById('btn-gen');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Generating…';

  document.getElementById('progress-wrap').style.display = 'block';
  animateSteps();

  try {
    const resp = await fetch('?ajax=1', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ prompt, style, width: w, height: h }),
    });

    const data = await resp.json();
    stopStepAnimation();

    if (data.success && data.image) {
      const dataUrl = 'data:image/png;base64,' + data.image;
      document.getElementById('result-img').src      = dataUrl;
      document.getElementById('btn-download').href   = dataUrl;
      document.getElementById('seed-val').textContent = data.seed ?? '—';
      document.getElementById('result-wrap').style.display = 'block';
      document.getElementById('progress-wrap').style.display = 'none';
    } else {
      const msg = data.error || 'Unknown error from API.';
      const detail = data.detail ? JSON.stringify(data.detail) : '';
      showError(msg + (detail ? '<br><small>' + detail + '</small>' : ''));
      document.getElementById('progress-wrap').style.display = 'none';
    }
  } catch (err) {
    stopStepAnimation();
    showError('Network error: ' + err.message);
    document.getElementById('progress-wrap').style.display = 'none';
  }

  btn.disabled = false;
  btn.innerHTML = 'Generate Image';
}

function resetUI() {
  document.getElementById('result-wrap').style.display   = 'none';
  document.getElementById('progress-wrap').style.display = 'none';
  hideError();
  document.getElementById('prompt').focus();
}

function showError(msg) {
  const el = document.getElementById('error-wrap');
  el.innerHTML = '⚠ ' + msg;
  el.style.display = 'block';
}
function hideError() {
  document.getElementById('error-wrap').style.display = 'none';
}

// Allow Ctrl+Enter to submit
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') generate();
});
</script>
</body>
</html>