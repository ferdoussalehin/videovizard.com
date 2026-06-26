<?php
// ============================================
// GoDaddy Standalone Converter — convert_video2mp4.php
// VPS pulls WebM from GoDaddy URL — no file upload needed
// ============================================

$VPS_URL       = 'http://187.124.249.46/videovizard.com/vps_convert.php';
$SECRET_KEY    = 'VS_FFmpeg_2026_Secret!';
$PUBLISHED_DIR = '/home/syjy0p3q5yjb/public_html/videovizard.com/published_videos/';
$PUBLISHED_URL = 'https://videovizard.com/published_videos/';

// --- MANUAL DOWNLOAD: triggered when polling times out ---
if (isset($_GET['manual_download'])) {
    header('Content-Type: application/json');
    $podcast_id = (int)($_GET['podcast_id'] ?? 0);
    $job_id     = $_GET['job_id'] ?? 'manual';
    $mp4_url    = 'http://187.124.249.46/videovizard.com/published_videos/podcast_' . $podcast_id . '.mp4';
    $mp4_path   = $PUBLISHED_DIR . 'podcast_' . $podcast_id . '.mp4';
    $webm_path  = $PUBLISHED_DIR . 'podcast_' . $podcast_id . '.webm';

    $mp4_data = @file_get_contents($mp4_url);

    if ($mp4_data && strlen($mp4_data) > 1000) {
        file_put_contents($mp4_path, $mp4_data);
        @unlink($webm_path);
        @file_get_contents($VPS_URL . '?action=cleanup&secret_key=' . urlencode($SECRET_KEY) .
            '&job_id=' . urlencode($job_id) . '&podcast_id=' . $podcast_id);

        echo json_encode([
            'success'      => true,
            'status'       => 'done',
            'filename'     => 'podcast_' . $podcast_id . '.mp4',
            'mp4_local_url'=> $PUBLISHED_URL . 'podcast_' . $podcast_id . '.mp4',
            'mp4_size_mb'  => round(filesize($mp4_path) / 1024 / 1024, 2),
            'message'      => 'MP4 downloaded. WebM deleted from both servers.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'MP4 not ready on VPS yet — still converting. Try again in 1 minute.'
        ]);
    }
    exit;
}

// --- AJAX: POLL JOB STATUS ---
if (isset($_GET['poll_job'])) {
    header('Content-Type: application/json');
    $job_id     = $_GET['job_id'] ?? '';
    $podcast_id = (int)($_GET['podcast_id'] ?? 0);

    $url      = $VPS_URL . '?action=status&secret_key=' . urlencode($SECRET_KEY) . '&job_id=' . urlencode($job_id);
    $response = @file_get_contents($url);

    if (!$response) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Could not reach VPS']);
        exit;
    }

    $data = json_decode($response, true);

    // Add phase info based on job log
    if ($data && $data['status'] === 'processing') {
        // Check VPS log for current phase
        $log_url = 'http://187.124.249.46/videovizard.com/vps_convert.php?action=log&secret_key=' . urlencode($SECRET_KEY) . '&job_id=' . urlencode($job_id);
        $log = @file_get_contents($log_url);
        if ($log) $data['phase_log'] = $log;
    }

    // If done — download MP4 from VPS to GoDaddy
    if ($data && $data['status'] === 'done') {
        $mp4_url      = $data['mp4_url'];
        $mp4_filename = $data['filename'];
        $mp4_path     = $PUBLISHED_DIR . $mp4_filename;

        // Download MP4 from VPS to GoDaddy
        $mp4_data = @file_get_contents($mp4_url);

        if ($mp4_data && strlen($mp4_data) > 1000) {
            file_put_contents($mp4_path, $mp4_data);

            // Delete WebM from GoDaddy
            $webm_path = $PUBLISHED_DIR . 'podcast_' . $podcast_id . '.webm';
            @unlink($webm_path);

            // Cleanup MP4 from VPS (delete mp4 and job files)
            @file_get_contents($VPS_URL . '?action=cleanup&secret_key=' . urlencode($SECRET_KEY) .
                '&job_id=' . urlencode($job_id) . '&podcast_id=' . $podcast_id);

            $data['mp4_local_url'] = $PUBLISHED_URL . $mp4_filename;
            $data['mp4_size_mb']   = round(filesize($mp4_path) / 1024 / 1024, 2);
            $data['message']       = 'MP4 saved. WebM deleted from GoDaddy and VPS.';
        } else {
            $data['status']  = 'error';
            $data['message'] = 'Failed to download MP4 from VPS';
        }
    }

    echo json_encode($data);
    exit;
}

// --- START CONVERSION (POST) ---
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['webm_filename'])) {
    $webm_filename = basename($_POST['webm_filename']);
    $webm_path     = $PUBLISHED_DIR . $webm_filename;
    $podcast_id    = (int)preg_replace('/[^0-9]/', '', $webm_filename);

    if (!file_exists($webm_path)) {
        $error = "File not found: $webm_filename";
    } else {
        // Send WebM public URL to VPS — VPS downloads it itself
        $webm_url = $PUBLISHED_URL . $webm_filename;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $VPS_URL,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15, // Just sending URL — instant
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => [
                'secret_key' => $SECRET_KEY,
                'action'     => 'convert',
                'podcast_id' => $podcast_id,
                'webm_url'   => $webm_url  // VPS pulls from this URL
            ]
        ]);

        $response  = curl_exec($curl);
        $curl_err  = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($curl_err) {
            $error = "cURL error: $curl_err";
        } elseif ($http_code !== 200) {
            $error = "VPS returned HTTP $http_code — Response: $response";
        } else {
            $data = json_decode($response, true);
            if ($data && $data['success'] && isset($data['job_id'])) {
                $result = [
                    'job_id'     => $data['job_id'],
                    'podcast_id' => $podcast_id,
                    'webm_file'  => $webm_filename,
                    'webm_url'   => $webm_url
                ];
            } else {
                $error = $data['message'] ?? 'Failed to start conversion. Response: ' . $response;
            }
        }
    }
}

// --- GET WEBM FILES LIST ---
$webm_files = [];
if (is_dir($PUBLISHED_DIR)) {
    foreach (glob($PUBLISHED_DIR . '*.webm') as $f) {
        $webm_files[] = [
            'name'    => basename($f),
            'size_mb' => round(filesize($f) / 1024 / 1024, 2),
            'date'    => date('Y-m-d H:i', filemtime($f))
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WebM → MP4 Converter</title>
<style>
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
           background:#0f172a; color:#e2e8f0; min-height:100vh; padding:40px 20px; }
    .container { max-width:780px; margin:0 auto; }
    h1 { font-size:26px; font-weight:700; margin-bottom:6px; color:#f8fafc; }
    .subtitle { color:#64748b; font-size:14px; margin-bottom:32px; }
    .card { background:#1e293b; border:1px solid #334155; border-radius:16px; padding:28px; margin-bottom:24px; }
    .card h2 { font-size:13px; font-weight:600; margin-bottom:16px; color:#64748b;
               text-transform:uppercase; letter-spacing:.06em; }
    table { width:100%; border-collapse:collapse; }
    th { text-align:left; font-size:12px; color:#475569; padding:8px 12px; border-bottom:1px solid #334155; }
    td { padding:12px; border-bottom:1px solid #263347; font-size:14px; }
    tr:last-child td { border:none; }
    tr:hover td { background:#263347; }
    .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    .badge-webm { background:#1e3a5f; color:#60a5fa; }
    .btn { display:inline-block; padding:8px 18px; border-radius:8px; border:none;
           font-size:13px; font-weight:600; cursor:pointer; transition:opacity .2s; }
    .btn:hover { opacity:.8; }
    .btn-primary { background:#3b82f6; color:#fff; }
    .empty { color:#475569; font-size:14px; text-align:center; padding:24px; }
    .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.85);
               z-index:9999; align-items:center; justify-content:center; flex-direction:column; }
    .overlay.active { display:flex; }
    .overlay-box { background:#1e293b; border:1px solid #334155; border-radius:20px;
                   padding:40px 48px; text-align:center; max-width:400px; width:90%; }
    .spinner { width:52px; height:52px; border:5px solid #334155; border-top-color:#3b82f6;
               border-radius:50%; animation:spin .8s linear infinite; margin:0 auto 24px; }
    @keyframes spin { to { transform:rotate(360deg); } }
    .overlay-title { font-size:18px; font-weight:700; color:#f8fafc; margin-bottom:10px; }
    .overlay-status { font-size:13px; color:#94a3b8; line-height:1.7; }
    .overlay-file { color:#60a5fa; font-weight:600; margin-top:6px; font-size:13px; }
    .progress-dots span { display:inline-block; width:8px; height:8px; border-radius:50%;
                           background:#3b82f6; margin:0 3px; animation:bounce 1.2s infinite; }
    .progress-dots span:nth-child(2) { animation-delay:.2s; }
    .progress-dots span:nth-child(3) { animation-delay:.4s; }
    @keyframes bounce { 0%,80%,100%{transform:scale(0.6);opacity:.4} 40%{transform:scale(1);opacity:1} }
    .toast { position:fixed; bottom:30px; right:30px; z-index:99999;
             background:#052e16; border:1px solid #16a34a; color:#fff;
             border-radius:14px; padding:20px 24px; max-width:340px;
             box-shadow:0 10px 40px rgba(0,0,0,.4); animation:slideUp .3s ease; }
    @keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
    .toast h3 { font-size:15px; color:#4ade80; margin-bottom:8px; }
    .toast p { font-size:12px; color:#86efac; margin-bottom:12px; line-height:1.5; }
    .toast a { display:inline-block; background:#16a34a; color:#fff; padding:8px 16px;
               border-radius:8px; text-decoration:none; font-size:12px; font-weight:600; }
    .result-box { border-radius:12px; padding:20px; margin-bottom:24px; }
    .result-error { background:#2d0a0a; border:1px solid #dc2626; }
    .result-error h3 { color:#f87171; font-size:15px; margin-bottom:8px; }
    .result-error p { color:#fca5a5; font-size:13px; }
</style>
</head>
<body>

<div class="overlay" id="convertOverlay">
    <div class="overlay-box">
        <div class="spinner"></div>
        <div class="overlay-title">🎬 Converting to MP4…</div>
        <div class="overlay-status" id="overlayStatus">VPS is downloading and converting…</div>
        <div class="overlay-file" id="overlayFile"></div>
        <div class="progress-dots" style="margin-top:20px;">
            <span></span><span></span><span></span>
        </div>
    </div>
</div>

<div class="container">
    <h1>🎬 WebM → MP4 Converter</h1>
    <p class="subtitle">
        VPS downloads WebM from GoDaddy → FFmpeg converts → MP4 saved back → WebM deleted from both
    </p>

    <?php if ($error): ?>
    <div class="result-box result-error">
        <h3>❌ Error</h3>
        <p><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>📁 WebM Files in /published_videos/</h2>
        <?php if (empty($webm_files)): ?>
            <p class="empty">No WebM files found in published_videos/</p>
        <?php else: ?>
        <table>
            <thead>
                <tr><th>Filename</th><th>Size</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($webm_files as $f): ?>
                <tr>
                    <td><span class="badge badge-webm">WEBM</span> &nbsp;<?= htmlspecialchars($f['name']) ?></td>
                    <td><?= $f['size_mb'] ?> MB</td>
                    <td style="color:#64748b;"><?= $f['date'] ?></td>
                    <td>
                        <form method="POST" onsubmit="return startConvert('<?= htmlspecialchars($f['name']) ?>')">
                            <input type="hidden" name="webm_filename" value="<?= htmlspecialchars($f['name']) ?>">
                            <button type="submit" class="btn btn-primary">🎬 Convert</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div style="font-size:12px;color:#334155;margin-top:8px;">
        VPS: <?= $VPS_URL ?> &nbsp;|&nbsp; WebM URL base: <?= $PUBLISHED_URL ?>
    </div>
</div>

<script>
<?php if ($result): ?>
window.addEventListener('DOMContentLoaded', () => {
    startPolling(
        '<?= $result['job_id'] ?>',
        <?= $result['podcast_id'] ?>,
        '<?= $result['webm_file'] ?>'
    );
});
<?php endif; ?>

function startConvert(filename) {
    document.getElementById('overlayFile').textContent = filename;
    document.getElementById('overlayStatus').textContent = 'Sending request to VPS…';
    document.getElementById('convertOverlay').classList.add('active');
    return true;
}

function startPolling(jobId, podcastId, filename) {
    document.getElementById('overlayFile').textContent = filename;
    document.getElementById('overlayStatus').textContent = 'VPS downloading and converting…';
    document.getElementById('convertOverlay').classList.add('active');

    let attempts = 0;
    const maxAttempts = 60; // 5 min then switch to manual download

    const poll = setInterval(async () => {
        attempts++;
        try {
            const url  = `?poll_job=1&job_id=${encodeURIComponent(jobId)}&podcast_id=${podcastId}`;
            const r    = await fetch(url);
            const data = await r.json();

            const elapsed = attempts * 5;
            let statusMsg = '';

            if (data.status === 'processing') {
                // Parse phase from log
                const log = data.phase_log || '';
                if (log.includes('FFmpeg')) {
                    statusMsg = `🎬 FFmpeg converting… (${elapsed}s elapsed)`;
                } else if (log.includes('Downloading')) {
                    statusMsg = `⬇ VPS downloading WebM from GoDaddy… (${elapsed}s elapsed)`;
                } else {
                    statusMsg = `⏳ Starting up… (${elapsed}s elapsed)`;
                }
            } else if (data.status === 'done') {
                statusMsg = '✅ Done! Downloading MP4 to GoDaddy…';
            } else if (data.status === 'failed') {
                statusMsg = '❌ Conversion failed: ' + (data.message || '');
            } else if (data.status === 'error') {
                statusMsg = '❌ Error: ' + (data.message || '');
            } else {
                statusMsg = data.status + ` (${elapsed}s)`;
            }

            document.getElementById('overlayStatus').textContent = statusMsg;

            if (data.status === 'done') {
                clearInterval(poll);
                document.getElementById('convertOverlay').classList.remove('active');
                showSuccessToast(data);
                setTimeout(() => location.reload(), 3000);
            }

            if (data.status === 'failed' || data.status === 'error') {
                clearInterval(poll);
                document.getElementById('convertOverlay').classList.remove('active');
                alert('❌ Failed: ' + (data.message || 'Unknown error'));
            }

            if (attempts >= maxAttempts) {
                clearInterval(poll);
                // Don't give up — try manual download
                document.getElementById('overlayStatus').textContent = '⏳ Conversion taking longer… trying to download MP4…';
                tryManualDownload(jobId, podcastId);
            }
        } catch (e) {
            console.warn('Poll error:', e.message);
        }
    }, 5000);
}

async function tryManualDownload(jobId, podcastId) {
    // Keep trying every 30 seconds until MP4 is ready on VPS
    const overlay = document.getElementById('convertOverlay');
    let tries = 0;
    const maxTries = 20; // 20 × 30s = 10 more minutes

    const retry = setInterval(async () => {
        tries++;
        document.getElementById('overlayStatus').textContent =
            `⏳ Still converting on VPS… checking (attempt ${tries})`;
        try {
            const url  = `?manual_download=1&podcast_id=${podcastId}&job_id=${encodeURIComponent(jobId)}`;
            const r    = await fetch(url);
            const data = await r.json();

            if (data.success) {
                clearInterval(retry);
                if (overlay) overlay.classList.remove('active');
                showSuccessToast(data);
                setTimeout(() => location.reload(), 3000);
            } else if (tries >= maxTries) {
                clearInterval(retry);
                if (overlay) overlay.classList.remove('active');
                alert('Conversion is taking very long. Please check back in a few minutes and refresh the page.');
            }
        } catch (e) {
            console.warn('Manual download error:', e.message);
        }
    }, 30000); // Check every 30 seconds
}

function showSuccessToast(data) {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.innerHTML = `
        <h3>✅ MP4 Ready!</h3>
        <p>
            File: <strong>${data.filename}</strong><br>
            Size: ${data.mp4_size_mb} MB<br>
            WebM deleted from GoDaddy ✓<br>
            WebM deleted from VPS ✓
        </p>
        <a href="${data.mp4_local_url}" download="${data.filename}">⬇ Download MP4</a>
    `;
    document.body.appendChild(toast);
    setTimeout(() => toast?.remove(), 20000);
}
</script>
</body>
</html>
