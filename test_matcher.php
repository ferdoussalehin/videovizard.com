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

function findAllMatches(string $sceneTags, array $assets, string $apiKey): array {
    $cleanScene  = cleanTagsForEmbedding($sceneTags);
    $sceneVector = getEmbedding($cleanScene, $apiKey);
    if (!$sceneVector) return [];

    $sceneDims = count($sceneVector);
    $scored    = [];
    $skippedCount = 0;
    $compatibleCount = 0;

    foreach ($assets as $asset) {
        if ($asset['dims'] !== $sceneDims) {
            $skippedCount++;
            continue;  // Skip mismatched dimensions
        }
        
        $compatibleCount++;
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
    
    // Store stats for logging
    $GLOBALS['search_compatible_count'] = $compatibleCount;
    $GLOBALS['search_skipped_count'] = $skippedCount;
    
    return $scored;
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

// Get detailed dimension breakdown
$dimQuery = mysqli_query($conn, "SELECT 
    CASE 
        WHEN embedding IS NULL OR embedding = '' THEN 'no_embedding'
        WHEN LENGTH(embedding) > 37000 THEN '3072_dim'
        WHEN LENGTH(embedding) BETWEEN 20000 AND 37000 THEN '1536_dim'
        ELSE 'unknown'
    END as dim_type,
    COUNT(*) as count
FROM hdb_image_data 
GROUP BY dim_type");

$dimCounts = [];
$totalAssets = 0;
while ($row = mysqli_fetch_assoc($dimQuery)) {
    $dimCounts[$row['dim_type']] = $row['count'];
    $totalAssets += $row['count'];
}

// Also count dimensions from loaded assets
$assetDims = [];
foreach ($assets as $a) {
    $d = $a['dims'];
    $assetDims[$d] = ($assetDims[$d] ?? 0) + 1;
}

// ── Handle POST ───────────────────────────────────────────────
$allResults = [];
$sceneTags = '';
$embedError = '';
$currentPage = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$perPage = 10;
$totalResults = 0;
$totalPages = 0;
$searchStats = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sceneTags = trim($_POST['scene_tags'] ?? '');
    if ($sceneTags) {
        // Log search start
        $searchStats['query'] = $sceneTags;
        $searchStats['clean_query'] = cleanTagsForEmbedding($sceneTags);
        $searchStats['start_time'] = microtime(true);
        
        $allResults = findAllMatches($sceneTags, $assets, $apiKey);
        $totalResults = count($allResults);
        $totalPages = ceil($totalResults / $perPage);
        
        // Get query embedding dimensions
        $cleanQuery = cleanTagsForEmbedding($sceneTags);
        $queryVector = getEmbedding($cleanQuery, $apiKey);
        $searchStats['query_dims'] = $queryVector ? count($queryVector) : 0;
        $searchStats['query_embedding_success'] = $queryVector ? true : false;
        
        // Count assets by dimension compatibility
        $searchStats['total_assets_loaded'] = count($assets);
        $searchStats['assets_by_dim'] = $assetDims;
        $searchStats['query_dim_match_count'] = isset($assetDims[$searchStats['query_dims']]) ? $assetDims[$searchStats['query_dims']] : 0;
        $searchStats['results_found'] = $totalResults;
        $searchStats['top_score'] = $totalResults > 0 ? round($allResults[0]['score'], 4) : 0;
        $searchStats['execution_time'] = round(microtime(true) - $searchStats['start_time'], 2);
        
        // Ensure current page is valid
        if ($currentPage < 1) $currentPage = 1;
        if ($currentPage > $totalPages && $totalPages > 0) $currentPage = $totalPages;
        
        // Get results for current page
        $offset = ($currentPage - 1) * $perPage;
        $results = array_slice($allResults, $offset, $perPage);
        
        if (empty($allResults)) {
            $embedError = 'No results — embedding may have failed or all assets have mismatched dimensions.';
        }
    }
} else {
    $results = [];
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
        
        /* Log section */
        .log-section {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
        }
        .log-section h3 {
            color: #4ec9b0;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .log-line {
            padding: 4px 0;
            border-bottom: 1px solid #333;
            font-family: monospace;
        }
        .log-line.success { color: #4ec9b0; }
        .log-line.warning { color: #ce9178; }
        .log-line.error { color: #f48771; }
        .log-line.info { color: #9cdcfe; }
        .log-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        .log-stat {
            background: #2d2d2d;
            padding: 8px 12px;
            border-radius: 6px;
        }
        .log-stat-label {
            color: #858585;
            font-size: 10px;
            text-transform: uppercase;
        }
        .log-stat-value {
            color: #4ec9b0;
            font-size: 16px;
            font-weight: bold;
        }
        
        /* 9:16 preview container styles */
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
        /* Lightbox modal */
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
        .video-error-message {
            font-size: 10px;
            color: #999;
            text-align: center;
        }
        
        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            padding: 15px 0;
            flex-wrap: wrap;
        }
        .page-btn {
            background: #fff;
            border: 1px solid #ddd;
            color: #333;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .page-btn:hover {
            background: #5c35d4;
            border-color: #5c35d4;
            color: #fff;
        }
        .page-btn.active {
            background: #5c35d4;
            border-color: #5c35d4;
            color: #fff;
        }
        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        .page-info {
            font-size: 13px;
            color: #666;
            margin: 0 10px;
        }
        .results-info {
            margin-bottom: 15px;
            font-size: 13px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>

<h2>🔍 Asset Matcher Tester</h2>

<!-- Debug bar -->
<div class="debug-bar">
    <span>Assets loaded: <strong><?= count($assets) ?></strong></span>
    <span>Memory: <strong><?= $memMB ?> MB</strong></span>
    <span>Model: <strong>text-embedding-3-large (3072 dims)</strong></span>
    <?php foreach ($dimCounts as $dim => $cnt): 
        $badgeClass = $dim == '3072_dim' ? 'dim-3072' : ($dim == '1536_dim' ? 'dim-1536' : 'dim-other');
        $pct = round(($cnt / $totalAssets) * 100, 1);
    ?>
    <span>
        <span class="dim-badge <?= $badgeClass ?>">
            <?= $dim == '3072_dim' ? '3072-dim' : ($dim == '1536_dim' ? '1536-dim' : $dim) ?>
        </span>: <strong><?= $cnt ?></strong> (<?= $pct ?>%)
    </span>
    <?php endforeach; ?>
</div>

<?php if (isset($dimCounts['1536_dim']) && $dimCounts['1536_dim'] > 0): ?>
<div class="warn-box">
    ⚠️ <strong>Mismatched embeddings detected.</strong>
    Assets with dimensions other than 3072 will be skipped during matching.
    <strong><?= $dimCounts['1536_dim'] ?? 0 ?></strong> assets are 1536-dim (skipped),
    <strong><?= $dimCounts['3072_dim'] ?? 0 ?></strong> assets are 3072-dim (searchable).
    Re-run your embedding generator to fix them.
</div>
<?php endif; ?>

<!-- Scene picker -->
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
    <form method="POST" id="searchForm">
        <textarea name="scene_tags" id="scene_tags" placeholder="e.g. real estate agent showing tablet|couple viewing new home|modern office meeting"><?= htmlspecialchars($sceneTags) ?></textarea><br>
        <input type="hidden" name="page" id="pageInput" value="1">
        <button type="submit">🔍 Find Matching Assets</button>
    </form>
</div>

<?php if ($sceneTags): ?>
<!-- Tags used -->
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

<?php if ($sceneTags && !empty($results)): ?>
<!-- Results table -->
<div class="box">
    <div class="results-info">
        <?php 
        // Calculate actual unique results count (can't exceed compatible assets)
        $actualResultCount = min($totalResults, ($dimCounts['3072_dim'] ?? 0));
        $compatibleAssets = $dimCounts['3072_dim'] ?? 0;
        $skippedAssets = $dimCounts['1536_dim'] ?? 0;
        ?>
        <strong>Found <?= $actualResultCount ?> matching assets</strong> 
        (out of <strong><?= $compatibleAssets ?></strong> compatible 3072-dim assets)
        
        <?php if ($skippedAssets > 0): ?>
        <span style="display:block; font-size:11px; color:#b87a00; margin-top:4px;">
            ⚠️ <?= $skippedAssets ?> assets with 1536-dim were skipped (wrong model)
        </span>
        <?php endif; ?>
        
        <?php if ($totalPages > 1): ?>
        <br>Showing page <?= $currentPage ?> of <?= $totalPages ?> (<?= $perPage ?> per page)
        <?php endif; ?>
    </div>
    
    <table>
        <thead>
        32<th>#</th>
            <th>Preview (9:16)</th>
            <th>Score</th>
            <th>File</th>
            <th>Type</th>
            <th>Dims</th>
            <th>Asset NL Tags</th>
        </tr>
        </thead>
        <tbody>
        <?php 
        $startNum = ($currentPage - 1) * $perPage + 1;
        $displayedCount = 0;
        foreach ($results as $idx => $r):
            // Stop if we've displayed all compatible assets
            if ($displayedCount >= $compatibleAssets) break;
            $displayedCount++;
            $displayNum = $startNum + $idx;
            $score    = round($r['score'], 4);
            $pct      = min(100, round($score * 100));
            $cls      = $score >= 0.65 ? 'score-high' : ($score >= 0.45 ? 'score-mid' : 'score-low');
            $barColor = $score >= 0.65 ? '#1a7a3a' : ($score >= 0.45 ? '#b87a00' : '#cc3333');
            $mediaType = strtolower(trim($r['media_type'] ?? ''));
            
            // Set correct path based on media type
            if ($mediaType === 'video') {
                $mediaUrl = 'https://videovizard.com/podcast_videos/' . $r['image_name'];
            } else {
                $mediaUrl = 'https://videovizard.com/podcast_images/' . $r['image_name'];
            }
            
            $safeUrl = htmlspecialchars($mediaUrl);
            $safeName = htmlspecialchars($r['image_name']);
            $safeTags = htmlspecialchars($r['nl_tags'] ?? '');
            
            // Generate preview HTML with 9:16 aspect ratio
            if ($mediaType === 'video') {
                $preview = '<div class="preview-container" data-media-url="' . $safeUrl . '" data-media-type="video" data-filename="' . $safeName . '" data-tags="' . $safeTags . '">
                                <video src="' . $safeUrl . '"
                                       muted
                                       preload="metadata"
                                       playsinline
                                       onmouseenter="this.play().catch(e=>console.log(\'Video play failed\'))"
                                       onmouseleave="this.pause(); this.currentTime=0"
                                       onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<div class=no-preview>🎬 Video file missing<br><span style=font-size:9px;>Check podcast_videos folder</span></div>\'"
                                       onclick="event.stopPropagation(); openLightbox(this.parentElement.dataset.mediaUrl, this.parentElement.dataset.mediaType, this.parentElement.dataset.filename, this.parentElement.dataset.tags)"
                                       style="width:100%; height:100%; object-fit:cover; cursor:pointer;">
                                </video>
                            </div>';
            } else {
                $preview = '<div class="preview-container" data-media-url="' . $safeUrl . '" data-media-type="image" data-filename="' . $safeName . '" data-tags="' . $safeTags . '">
                                <img src="' . $safeUrl . '"
                                     style="width:100%; height:100%; object-fit:cover; cursor:pointer;"
                                     onclick="openLightbox(this.parentElement.dataset.mediaUrl, this.parentElement.dataset.mediaType, this.parentElement.dataset.filename, this.parentElement.dataset.tags)"
                                     onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<div class=no-preview>🖼️ Image missing</div>\'">
                            </div>';
            }
        ?>
        <tr class="<?= $displayNum === 1 ? 'best-row' : '' ?>">
            <td style="white-space:nowrap;">
                <?php if ($displayNum === 1): ?>
                    <span style="background:#1a7a3a;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">Best</span>
                <?php elseif ($displayNum === 2): ?>
                    <span style="background:#b87a00;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">2nd</span>
                <?php elseif ($displayNum === 3): ?>
                    <span style="background:#cc3333;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">3rd</span>
                <?php else: ?>
                    <span style="color:#999;"><?= $displayNum ?></span>
                <?php endif; ?>
            </td>
            <td><?= $preview ?></td>
            <td class="<?= $cls ?>" style="white-space:nowrap;">
                <?= $score ?>
                <div class="score-bar">
                    <div class="score-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                </div>
                <div style="font-size:10px;color:#999;margin-top:2px;"><?= $pct ?>%</div>
            </td>
            <td style="font-size:11px;max-width:140px;word-break:break-all;">
                <?= $safeName ?>
            </td>
            <td>
                <span style="font-size:11px;padding:2px 7px;border-radius:10px;
                    background:<?= $mediaType==='video'?'#ede9fe':'#dbeafe' ?>;
                    color:<?= $mediaType==='video'?'#6d28d9':'#1d4ed8' ?>;">
                    <?= htmlspecialchars($r['media_type'] ?? '—') ?>
                </span>
            </td>
            <td>
                <span class="dim-badge <?= ($r['dims']??0)==3072?'dim-3072':(($r['dims']??0)==1536?'dim-1536':'dim-other') ?>">
                    <?= $r['dims'] ?? '?' ?>
                </span>
            </td>
            <td>
                <?php foreach (explode('|', $r['nl_tags'] ?? '') as $tag): ?>
                    <?php if (trim($tag)): ?>
                    <span class="tag-pill"><?= htmlspecialchars(trim($tag)) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($currentPage > 1): ?>
            <a href="#" class="page-btn" onclick="goToPage(<?= $currentPage - 1 ?>)">← Previous</a>
        <?php else: ?>
            <span class="page-btn disabled">← Previous</span>
        <?php endif; ?>
        
        <?php
        // Show page numbers
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        
        if ($startPage > 1) {
            echo '<a href="#" class="page-btn" onclick="goToPage(1)">1</a>';
            if ($startPage > 2) echo '<span class="page-info">...</span>';
        }
        
        for ($i = $startPage; $i <= $endPage; $i++) {
            $activeClass = ($i == $currentPage) ? 'active' : '';
            echo '<a href="#" class="page-btn ' . $activeClass . '" onclick="goToPage(' . $i . ')">' . $i . '</a>';
        }
        
        if ($endPage < $totalPages) {
            if ($endPage < $totalPages - 1) echo '<span class="page-info">...</span>';
            echo '<a href="#" class="page-btn" onclick="goToPage(' . $totalPages . ')">' . $totalPages . '</a>';
        }
        ?>
        
        <?php if ($currentPage < $totalPages): ?>
            <a href="#" class="page-btn" onclick="goToPage(<?= $currentPage + 1 ?>)">Next →</a>
        <?php else: ?>
            <span class="page-btn disabled">Next →</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $sceneTags && empty($embedError)): ?>
<div class="err-box">No results returned. The OpenAI embedding call may have failed — check your API key in config.php.</div>
<?php endif; ?>

<!-- LOG SECTION -->
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sceneTags): ?>
<div class="log-section">
    <h3>🔍 SEARCH DETAILS LOG</h3>
    
    <div class="log-stats">
        <div class="log-stat">
            <div class="log-stat-label">Search Query</div>
            <div class="log-stat-value"><?= htmlspecialchars(substr($searchStats['query'] ?? '', 0, 100)) ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Clean Query (sent to OpenAI)</div>
            <div class="log-stat-value"><?= htmlspecialchars(substr($searchStats['clean_query'] ?? '', 0, 100)) ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Query Embedding</div>
            <div class="log-stat-value"><?= $searchStats['query_embedding_success'] ? '✅ Success' : '❌ Failed' ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Query Dimensions</div>
            <div class="log-stat-value"><?= $searchStats['query_dims'] ?? 0 ?></div>
        </div>
    </div>
    
    <div class="log-stats">
        <div class="log-stat">
            <div class="log-stat-label">Total Assets in DB</div>
            <div class="log-stat-value"><?= $totalAssets ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Assets with Embeddings</div>
            <div class="log-stat-value"><?= count($assets) ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Compatible Assets (<?= $searchStats['query_dims'] ?? 0 ?> dims)</div>
            <div class="log-stat-value"><?= $searchStats['query_dim_match_count'] ?? 0 ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Results Found</div>
            <div class="log-stat-value"><?= $searchStats['results_found'] ?? 0 ?></div>
        </div>
    </div>
    
    <div class="log-stats">
        <div class="log-stat">
            <div class="log-stat-label">Top Score</div>
            <div class="log-stat-value"><?= $searchStats['top_score'] ?? 0 ?></div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Execution Time</div>
            <div class="log-stat-value"><?= $searchStats['execution_time'] ?? 0 ?> seconds</div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Assets per Dimension</div>
            <div class="log-stat-value" style="font-size: 11px;">
                <?php foreach ($assetDims as $dim => $count): ?>
                    <?= $dim ?>d: <?= $count ?><br>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="log-stat">
            <div class="log-stat-label">Searchable vs Skipped</div>
            <div class="log-stat-value" style="font-size: 11px;">
                ✅ Searchable: <?= $assetDims[$searchStats['query_dims']] ?? 0 ?><br>
                ❌ Skipped: <?= count($assets) - ($assetDims[$searchStats['query_dims']] ?? 0) ?>
            </div>
        </div>
    </div>
    
    <div class="log-line info">
        <strong>📊 Summary:</strong> Out of <?= $totalAssets ?> total assets, only <strong><?= $assetDims[$searchStats['query_dims']] ?? 0 ?></strong> have the correct dimensions (<?= $searchStats['query_dims'] ?? 0 ?>) and were searched. 
        The other <strong><?= count($assets) - ($assetDims[$searchStats['query_dims']] ?? 0) ?></strong> assets were skipped due to dimension mismatch.
    </div>
    
    <?php if (($assetDims[$searchStats['query_dims']] ?? 0) < 100): ?>
    <div class="log-line warning">
        <strong>⚠️ Warning:</strong> Only <?= $assetDims[$searchStats['query_dims']] ?? 0 ?> assets are searchable. Consider regenerating embeddings for all assets to improve search results.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Lightbox Modal -->
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

function goToPage(page) {
    document.getElementById('pageInput').value = page;
    document.getElementById('searchForm').submit();
}

// Lightbox functions
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
        video.style.maxWidth = '90vw';
        video.style.maxHeight = '90vh';
        video.onerror = function() {
            mediaContainer.innerHTML = '<div style="color:#fff; text-align:center; padding:40px;">❌ Video file not found<br><span style="font-size:12px;">' + filename + '</span></div>';
        };
        mediaContainer.appendChild(video);
    } else {
        var img = document.createElement('img');
        img.src = url;
        img.alt = tags || filename;
        img.className = 'lightbox-media';
        img.style.maxWidth = '90vw';
        img.style.maxHeight = '90vh';
        img.onerror = function() {
            mediaContainer.innerHTML = '<div style="color:#fff; text-align:center; padding:40px;">🖼️ Image not found<br><span style="font-size:12px;">' + filename + '</span></div>';
        };
        mediaContainer.appendChild(img);
    }
    
    lightbox.classList.add('active');
}

function closeLightbox() {
    var lightbox = document.getElementById('lightbox');
    var mediaContainer = document.getElementById('lightboxMedia');
    var video = mediaContainer.querySelector('video');
    if (video) {
        video.pause();
    }
    mediaContainer.innerHTML = '';
    lightbox.classList.remove('active');
}

// Close lightbox with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLightbox();
    }
});
</script>
</body>
</html>