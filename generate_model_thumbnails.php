<?php
require 'config.php';

/* --- HANDLE AJAX --- */
if (isset($_POST['action'])) {

    /* --- GET PENDING COUNT --- */
    if ($_POST['action'] == 'get_pending') {
        $res   = $conn->query("SELECT model_id FROM mdl_models WHERE thumbnail = '' OR thumbnail IS NULL");
        $ids   = [];
        while ($row = $res->fetch_assoc()) {
            $ids[] = $row['model_id'];
        }
        echo json_encode(['ids' => $ids]);
        exit;
    }

    /* --- GENERATE ONE --- */
    if ($_POST['action'] == 'generate') {
        $id    = intval($_POST['model_id']);
        $res   = $conn->query("SELECT * FROM mdl_models WHERE model_id = $id");
        $model = $res->fetch_assoc();

        if (!$model) {
            echo json_encode(['status' => 'error', 'msg' => 'Model not found: ' . $id]);
            exit;
        }

        // Build prompt
        // Build unique seed from model data to vary faces
        $seed = crc32($model['model_name'] . $model['model_id'] . $model['ethnicity']);

        $gender = strtolower(trim($model['gender'] ?? 'female'));

        if ($gender == 'male') {
            $prompt = "Professional portrait of a " . $model['ethnicity']
                    . " male model, age " . $model['age_range']
                    . ", unique facial features, different face from other models, clean shaven, light grooming, plain white crew neck tshirt, pure white seamless studio background, soft even front lighting, no shadows, no jewelry, no accessories, looking straight at camera, neutral confident expression, square crop, 85mm lens, photorealistic";
        } else {
            $prompt = "Professional portrait of a " . $model['ethnicity']
                    . " female model, age " . $model['age_range']
                    . ", unique facial features, different face from other models, hair neatly tied up in a bun, light natural makeup, no jewelry, no earrings, no necklace, no dupatta, no scarf, no headscarf, no head covering, plain white seamless studio background, soft even front lighting, no shadows, bare neck and shoulders, looking straight at camera, neutral pleasant expression, square crop, 85mm lens, photorealistic";
        }

        // Call fal.ai
        $ch = curl_init("https://fal.run/fal-ai/flux/schnell");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Key " . $falApiKey,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "prompt"     => $prompt,
            "image_size" => "square_hd",
            "num_images" => 1
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode != 200 || empty($data['images'][0]['url'])) {
            echo json_encode(['status' => 'error', 'msg' => 'Fal AI failed HTTP ' . $httpCode, 'raw' => $data]);
            exit;
        }

        // Download and save image
        $imageUrl  = $data['images'][0]['url'];
        $imgData   = file_get_contents($imageUrl);
        $savedir   = __DIR__ . "/promo_models/thumbnails/";
        if (!is_dir($savedir)) mkdir($savedir, 0777, true);

        // Meaningful filename: indian_female_42.jpg
        $eth      = preg_replace('/[^a-z0-9]/', '_', strtolower($model['ethnicity']));
        $gen      = preg_replace('/[^a-z0-9]/', '_', strtolower($model['gender'] ?? 'female'));
        $filename = $eth . '_' . $gen . '_' . $id . '.jpg';
        $filepath = $savedir . $filename;
        $dbpath   = 'promo_models/thumbnails/' . $filename;

        file_put_contents($filepath, $imgData);
        $conn->query("UPDATE mdl_models SET thumbnail = '" . $conn->real_escape_string($dbpath) . "' WHERE model_id = $id");

        echo json_encode(['status' => 'success', 'id' => $id, 'path' => $dbpath]);
        exit;
    }
}

/* --- PAGE LOAD STATS --- */
$total     = $conn->query("SELECT COUNT(*) as c FROM mdl_models")->fetch_assoc()['c'];
$done      = $conn->query("SELECT COUNT(*) as c FROM mdl_models WHERE thumbnail != '' AND thumbnail IS NOT NULL")->fetch_assoc()['c'];
$remaining = $total - $done;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Thumbnail Generator</title>
<style>
body { font-family: Arial; background: #fff8e6; padding: 30px; }
.box { max-width: 700px; margin: auto; background: #fff; padding: 30px; border-radius: 12px; border: 2px solid #d4af37; }
h2 { color: #b48a1f; margin-bottom: 20px; }
.stats { margin-bottom: 15px; font-size: 15px; color: #444; }
.progress-wrap { background: #eee; border-radius: 10px; height: 24px; margin-bottom: 5px; overflow: hidden; }
.bar { height: 24px; background: #d4af37; transition: width 0.4s; }
.pct { font-size: 13px; color: #888; margin-bottom: 20px; }
button { padding: 10px 24px; border: none; border-radius: 7px; font-size: 15px; font-weight: bold; cursor: pointer; margin-right: 10px; }
#btn-start { background: #d4af37; color: #fff; }
#btn-pause { background: #c0392b; color: #fff; }
#status { margin-top: 15px; font-size: 15px; color: #333; min-height: 22px; }
#error { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 13px; color: #7a5800; display: none; white-space: pre-wrap; }
#preview { margin-top: 15px; }
#preview img { border-radius: 10px; border: 2px solid #d4af37; width: 220px; display: block; margin-top: 8px; }
</style>
</head>
<body>
<div class="box">
    <h2>📸 AI Model Thumbnail Generator</h2>
    <div class="stats">
        Total: <b><?php echo $total; ?></b> &nbsp;|&nbsp;
        Done: <b id="s-done"><?php echo $done; ?></b> &nbsp;|&nbsp;
        Remaining: <b id="s-remaining"><?php echo $remaining; ?></b>
    </div>
    <div class="progress-wrap">
        <div class="bar" id="bar" style="width:<?php echo $total > 0 ? round($done/$total*100) : 0; ?>%"></div>
    </div>
    <div class="pct" id="pct"><?php echo $total > 0 ? round($done/$total*100) : 0; ?>%</div>

    <button id="btn-start" onclick="startGen()">▶ Start</button>
    <button id="btn-pause" onclick="pauseGen()" disabled>⏸ Pause</button>

    <div id="status">Ready</div>
    <div id="error"></div>
    <div id="preview"></div>
</div>

<script>
var paused = false;
var queue  = [];
var total  = <?php echo intval($total); ?>;
var done   = <?php echo intval($done); ?>;

function startGen() {
    document.getElementById('btn-start').disabled = true;
    document.getElementById('btn-pause').disabled = false;
    document.getElementById('error').style.display = 'none';
    paused = false;

    status('Loading pending models...');

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_pending'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        queue = data.ids;
        if (queue.length == 0) {
            status('All thumbnails already generated!');
            document.getElementById('btn-start').disabled = false;
            document.getElementById('btn-pause').disabled = true;
            return;
        }
        status('Found ' + queue.length + ' pending. Starting...');
        next();
    });
}

function pauseGen() {
    paused = true;
    document.getElementById('btn-start').disabled = false;
    document.getElementById('btn-pause').disabled = true;
    status('Paused. ' + queue.length + ' remaining.');
}

function next() {
    if (paused) return;
    if (queue.length == 0) {
        status('All done!');
        document.getElementById('btn-start').disabled = true;
        document.getElementById('btn-pause').disabled = true;
        return;
    }

    var id = queue.shift();
    status('Generating model ID ' + id + '... (' + queue.length + ' left)');

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=generate&model_id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status == 'success') {
            done++;
            updateProgress();
            status('Done ID ' + data.id + ' | ' + queue.length + ' remaining');
            document.getElementById('preview').innerHTML =
                '<b>Latest:</b> ' + data.path +
                '<br><img src="' + data.path + '?t=' + Date.now() + '">';
            setTimeout(next, 1000);
        } else {
            paused = true;
            document.getElementById('btn-start').disabled = false;
            document.getElementById('btn-pause').disabled = true;
            status('Error on ID ' + id + ' - see below');
            var el = document.getElementById('error');
            el.style.display = 'block';
            el.textContent = 'Error: ' + data.msg + '\n' + (data.raw ? JSON.stringify(data.raw, null, 2) : '');
        }
    })
    .catch(function(err) {
        status('Network error: ' + err.message);
    });
}

function updateProgress() {
    var pct = Math.round(done / total * 100);
    document.getElementById('bar').style.width = pct + '%';
    document.getElementById('pct').textContent = pct + '%';
    document.getElementById('s-done').textContent = done;
    document.getElementById('s-remaining').textContent = (total - done);
}

function status(msg) {
    document.getElementById('status').textContent = msg;
}
</script>
</body>
</html>
