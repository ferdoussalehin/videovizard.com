<?php
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
set_time_limit(120);

require 'config.php';
require 'dbconnect_hdb.php';

function cleanTagsForEmbedding(string $tags): string {
    $clean = str_replace('|', ', ', $tags);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

function getEmbedding(string $text, string $apiKey): ?array {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'text-embedding-3-large',
            'input' => $text
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return null;
    $data = json_decode($response, true);
    return $data['data'][0]['embedding'] ?? null;
}

function cosineSimilarity(array $a, array $b): float {
    if (count($a) !== count($b)) return 0.0;
    $dot = 0.0; $normA = 0.0; $normB = 0.0;
    $len = count($a);
    for ($i = 0; $i < $len; $i++) {
        $dot   += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    if ($normA == 0 || $normB == 0) return 0.0;
    return $dot / (sqrt($normA) * sqrt($normB));
}

function loadAssetVectors($conn): array {
    $result = mysqli_query($conn,
        "SELECT id, image_name, natural_language_tags, media_type, embedding
         FROM hdb_image_data
         WHERE embedding IS NOT NULL
         AND embedding != ''
         AND skip_embedding = 0
         AND (admin_id = 0 OR admin_id IS NULL)
         ORDER BY id ASC"
    );
    if (!$result) return [];

    $assets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $vec = json_decode($row['embedding'], true);
        if (!is_array($vec) || count($vec) === 0) {
            continue;
        }
        $assets[] = [
            'id'         => $row['id'],
            'image_name' => $row['image_name'],
            'media_type' => $row['media_type'],
            'nl_tags'    => $row['natural_language_tags'],
            'embedding'  => $vec,
            'dims'       => count($vec),
        ];
    }
    return $assets;
}

function findAllMatches(string $sceneTags, array $assets, string $apiKey, int $limit = 10): array {
    $cleanScene  = cleanTagsForEmbedding($sceneTags);
    $sceneVector = getEmbedding($cleanScene, $apiKey);
    if (!$sceneVector) return [];

    $sceneDims = count($sceneVector);
    $scored    = [];

    foreach ($assets as $asset) {
        if ($asset['dims'] !== $sceneDims) continue;

        $score = cosineSimilarity($sceneVector, $asset['embedding']);
        $scored[] = [
            'id'         => $asset['id'],
            'image_name' => $asset['image_name'],
            'media_type' => $asset['media_type'],
            'nl_tags'    => $asset['nl_tags'],
            'dims'       => $asset['dims'],
            'score'      => $score,
        ];
    }

    usort($scored, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return array_slice($scored, 0, $limit);
}

// ── Load scenes ───────────────────────────────────────────────
$scenes = [];
$r = mysqli_query($conn,
    "SELECT id, scene_order, natural_language_tags, text_contents
     FROM hdb_podcast_stories
     WHERE natural_language_tags IS NOT NULL
     AND natural_language_tags != ''
     ORDER BY id DESC
     LIMIT 50"
);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $scenes[] = $row;
    }
}

// ── Load assets ───────────────────────────────────────────────
$assets = loadAssetVectors($conn);
$memMB = round(memory_get_usage(true) / 1024 / 1024, 1);

$dimCounts = [];
foreach ($assets as $a) {
    $d = $a['dims'];
    $dimCounts[$d] = ($dimCounts[$d] ?? 0) + 1;
}

// ── Handle POST ───────────────────────────────────────────────
$results = [];
$sceneTags = '';
$embedError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sceneTags = trim($_POST['scene_tags'] ?? '');
    if ($sceneTags) {
        $results = findAllMatches($sceneTags, $assets, $apiKey, 10);
        if (empty($results)) {
            $embedError = 'No results — embedding may have failed or all assets have mismatched dimensions.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Matcher Tester</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1400px; margin: 30px auto; padding: 0 20px; background: #f5f5f5; }
        h2 { color: #333; margin-bottom: 6px; }
        .box { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        textarea { width: 100%; height: 80px; font-size: 13px; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        button { background: #5c35d4; color: #fff; border: none; padding: 10px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 8px; }
        button:hover { background: #4a28b8; }
        select { width: 100%; padding: 8px; font-size: 13px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border-radius: 8px; overflow: hidden; }
        th { background: #5c35d4; color: #fff; padding: 12px 12px; text-align: left; }
        td { padding: 12px 12px; border-bottom: 1px solid #eee; vertical-align: middle; }
        tr:hover td { background: #f9f7ff; }
        .score-high { color: #1a7a3a; font-weight: bold; }
        .score-mid  { color: #b87a00; font-weight: bold; }
        .score-low  { color: #cc3333; }
        .tag-pill { display: inline-block; background: #ede9fc; color: #3c2080; font-size: 11px; padding: 2px 7px; border-radius: 10px; margin: 2px 2px 2px 0; }
        .score-bar { background: #eee; border-radius: 4px; height: 6px; width: 100px; margin-top: 4px; }
        .score-fill { height: 6px; border-radius: 4px; }
        .best-row { background: #f0fdf4 !important; border-left: 4px solid #1a7a3a; }
        .debug-bar { background: #e8f4f8; border: 1px solid #b8dce8; border-radius: 6px; padding: 10px 16px; font-family: monospace; font-size: 12px; margin-bottom: 16px; display: flex; gap: 24px; flex-wrap: wrap; }
        .debug-bar span { color: #333; }
        .debug-bar strong { color: #0f6cbd; }
        .warn-box { background: #fef3c7; border: 1px solid #fde68a; border-radius: 6px; padding: 10px 16px; font-size: 13px; margin-bottom: 12px; }
        .err-box  { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px 16px; font-size: 13px; color: #b91c1c; }
        .dim-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .dim-3072 { background: #dcfce7; color: #166534; }
        .dim-1536 { background: #fee2e2; color: #991b1b; }
        .dim-other { background: #fef3c7; color: #92400e; }
        
        .preview-container {
            width: 120px;
            height: 213px;
            background: #f0f0f0;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ddd;
        }
        .preview-container img,
        .preview-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .preview-container video {
            cursor: pointer;
            background: #000;
        }
        .no-preview {
            color: #999;
            font-size: 11px;
            text-align: center;
            padding: 10px;
        }
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            cursor: pointer;
        }
        .lightbox.active {
            display: flex;
        }
        .lightbox-content {
            max-width: 90vw;
            max-height: 90vh;
            position: relative;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
        }
        .lightbox-media {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
        }
        .lightbox-close {
            position: absolute;
            top: -40px;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 28px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-family: monospace;
        }
        .lightbox-close:hover {
            background: #ff4444;
        }
    </style>
</head>
<body>

<h2>🔍 Asset Matcher Tester</h2>

<div class="debug-bar">
    <span>Assets loaded: <strong><?= count($assets) ?></strong></span>
    <span>Memory: <strong><?= $memMB ?> MB</strong></span>
    <span>Model: <strong>text-embedding-3-large (3072 dims)</strong></span>
    <?php foreach ($dimCounts as $dim => $cnt): ?>
    <span>
        <span class="dim-badge <?= $dim == 3072 ? 'dim-3072' : ($dim == 1536 ? 'dim-1536' : 'dim-other') ?>">
            <?= $dim ?>-dim
        </span>: <strong><?= $cnt ?></strong>
    </span>
    <?php endforeach; ?>
</div>

<?php if (isset($dimCounts[1536]) && $dimCounts[1536] > 0): ?>
<div class="warn-box">
    ⚠️ <strong>Mismatched embeddings detected.</strong>
    Assets with dimensions other than 3072 will be skipped during matching.
    Re-run your embedding generator to fix them.
</div>
<?php endif; ?>

<div class="box">
    <b>Pick a scene from hdb_podcast_stories</b><br><br>
    <select id="scene_select" onchange="fillTags()">
        <option value="">-- select a scene --</option>
        <?php foreach ($scenes as $s): ?>
        <option value="<?= htmlspecialchars($s['natural_language_tags']) ?>">
            [ID <?= $s['id'] ?>] Scene <?= $s['scene_order'] ?> — <?= htmlspecialchars(substr($s['natural_language_tags'], 0, 70)) ?>…
        </option>
        <?php endforeach; ?>
    </select>

    <b>Or type/paste NL tags manually:</b><br>
    <form method="POST">
        <textarea name="scene_tags" id="scene_tags" placeholder="e.g. real estate agent showing tablet|couple viewing new home|modern office meeting"><?= htmlspecialchars($sceneTags) ?></textarea><br>
        <button type="submit">🔍 Find Matching Assets</button>
    </form>
</div>

<?php if ($sceneTags): ?>
<div class="box">
    <b>Scene tags used:</b>
    <div style="margin-top:8px;">
        <?php foreach (explode('|', $sceneTags) as $tag): ?>
        <span class="tag-pill"><?= htmlspecialchars(trim($tag)) ?></span>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:8px; font-size:12px; color:#999;">
        Sent to OpenAI as: "<?= htmlspecialchars(cleanTagsForEmbedding($sceneTags)) ?>"
    </div>
</div>
<?php endif; ?>

<?php if ($embedError): ?>
<div class="err-box"><?= htmlspecialchars($embedError) ?></div>
<?php endif; ?>

<?php if ($results): ?>
<div class="box">
    <b>Top <?= count($results) ?> matching assets</b><br><br>
    <table>
        <thead>
            <tr><th>#</th><th>Preview</th><th>Score</th><th>File</th><th>Type</th><th>Dims</th><th>Asset NL Tags</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $i => $r):
            $score = round($r['score'], 4);
            $pct = min(100, round($score * 100));
            $cls = $score >= 0.65 ? 'score-high' : ($score >= 0.45 ? 'score-mid' : 'score-low');
            $barColor = $score >= 0.65 ? '#1a7a3a' : ($score >= 0.45 ? '#b87a00' : '#cc3333');
            $mediaType = strtolower(trim($r['media_type'] ?? ''));
            $mediaUrl = ($mediaType === 'video') ? 'podcast_videos/' . $r['image_name'] : 'podcast_images/' . $r['image_name'];
        ?>
        <tr class="<?= $i === 0 ? 'best-row' : '' ?>">
            <td><?= $i + 1 ?></td>
            <td><div class="preview-container"><img src="<?= htmlspecialchars($mediaUrl) ?>" style="width:100%;height:100%;object-fit:cover;"></div></td>
            <td class="<?= $cls ?>"><?= $score ?><div class="score-bar"><div class="score-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div></div><div style="font-size:10px;"><?= $pct ?>%</div></td>
            <td style="font-size:11px;word-break:break-all;"><?= htmlspecialchars($r['image_name']) ?></td>
            <td><span style="font-size:11px;padding:2px 7px;border-radius:10px;background:<?= $mediaType==='video'?'#ede9fe':'#dbeafe' ?>;"><?= htmlspecialchars($r['media_type'] ?? '—') ?></span></td>
            <td><span class="dim-badge <?= ($r['dims']??0)==3072?'dim-3072':(($r['dims']??0)==1536?'dim-1536':'dim-other') ?>"><?= $r['dims'] ?? '?' ?></span></td>
            <td><?php foreach (explode('|', $r['nl_tags'] ?? '') as $tag): if(trim($tag)): ?><span class="tag-pill"><?= htmlspecialchars(trim($tag)) ?></span><?php endif; endforeach; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $sceneTags && empty($embedError)): ?>
<div class="err-box">No results returned. The OpenAI embedding call may have failed — check your API key in config.php.</div>
<?php endif; ?>

<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
        <div class="lightbox-close" onclick="closeLightbox()">×</div>
        <div id="lightboxMedia"></div>
    </div>
</div>

<script>
function fillTags() {
    var sel = document.getElementById('scene_select');
    var val = sel.options[sel.selectedIndex].value;
    if (val) document.getElementById('scene_tags').value = val;
}

function openLightbox(url, mediaType, filename, tags) {
    var lightbox = document.getElementById('lightbox');
    var mediaContainer = document.getElementById('lightboxMedia');
    mediaContainer.innerHTML = '';
    if (mediaType === 'video') {
        var video = document.createElement('video');
        video.src = url;
        video.controls = true;
        video.autoplay = true;
        video.className = 'lightbox-media';
        video.onerror = function() { mediaContainer.innerHTML = '<div style="color:#fff; text-align:center; padding:40px;">❌ Video not found</div>'; };
        mediaContainer.appendChild(video);
    } else {
        var img = document.createElement('img');
        img.src = url;
        img.className = 'lightbox-media';
        img.onerror = function() { mediaContainer.innerHTML = '<div style="color:#fff; text-align:center; padding:40px;">🖼️ Image not found</div>'; };
        mediaContainer.appendChild(img);
    }
    lightbox.classList.add('active');
}

function closeLightbox() {
    var lightbox = document.getElementById('lightbox');
    var mediaContainer = document.getElementById('lightboxMedia');
    var video = mediaContainer.querySelector('video');
    if (video) video.pause();
    mediaContainer.innerHTML = '';
    lightbox.classList.remove('active');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});
</script>
</body>
</html>