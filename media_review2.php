<?php
// media_review.php
ob_start(); // MUST be first line
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(300);

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        if (isset($_GET['action'])) {
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'success' => false,
                'message' => 'FATAL PHP ERROR: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line']
            ));
        }
    }
});

require_once 'dbconnect_hdb.php';

if (empty($chatgpt_api_key) && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// ── Ensure columns exist ──────────────────────────────────────────────────────
function addColIfMissing($conn, $col, $def) {
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `hdb_image_data` LIKE '$col'");
    if ($res && mysqli_num_rows($res) === 0) {
        mysqli_query($conn, "ALTER TABLE `hdb_image_data` ADD COLUMN `$col` $def");
    }
}
addColIfMissing($conn, 'natural_language_tags', 'TEXT NULL');
addColIfMissing($conn, 'embedding',             'MEDIUMTEXT NULL');
addColIfMissing($conn, 'status',                "VARCHAR(50) NULL DEFAULT ''");
addColIfMissing($conn, 'media_type',            "VARCHAR(20) NULL DEFAULT 'image'");
addColIfMissing($conn, 'media_type_format',     "VARCHAR(10) NULL DEFAULT ''");
addColIfMissing($conn, 'created_at',            'DATETIME NULL');
addColIfMissing($conn, 'updated_at',            'DATETIME NULL');
addColIfMissing($conn, 'thumbnail',             "VARCHAR(100) NULL DEFAULT ''");
addColIfMissing($conn, 'skip_embedding',        "TINYINT(1) NOT NULL DEFAULT 0");
addColIfMissing($conn, 'admin_id',              "INT(11) NOT NULL DEFAULT 0");
addColIfMissing($conn, 'niches',                'TEXT NULL DEFAULT NULL');

// ── Thumbnail folder ──────────────────────────────────────────────────────────
$thumbDir = __DIR__ . '/podcast_thumbnails/';
if (!is_dir($thumbDir)) mkdir($thumbDir, 0777, true);

// ── Helper: send JSON and exit cleanly ────────────────────────────────────────
function jsonOut($data) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// =============================================================================
// ALL AJAX HANDLERS
// =============================================================================
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // ─────────────────────────────────────────────────────────────────────────
    // 1. save_thumbnail - Save captured thumbnail for images
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'save_thumbnail') {
        $name      = basename(isset($_POST['name'])      ? $_POST['name']      : '');
        $imageData = isset($_POST['image_data'])         ? $_POST['image_data'] : '';

        if (empty($name) || empty($imageData)) {
            jsonOut(array('success' => false, 'message' => 'Missing name or image data'));
        }

        if (strpos($imageData, ',') !== false) {
            $imageData = explode(',', $imageData, 2)[1];
        }
        $decoded = base64_decode($imageData);
        if (!$decoded) {
            jsonOut(array('success' => false, 'message' => 'Invalid base64 image data'));
        }

        $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.png';
        $thumbPath = __DIR__ . '/podcast_thumbnails/' . $thumbName;
        file_put_contents($thumbPath, $decoded);

        if (!file_exists($thumbPath)) {
            jsonOut(array('success' => false, 'message' => 'Failed to save thumbnail file'));
        }

        $safe      = mysqli_real_escape_string($conn, $name);
        $safethumb = mysqli_real_escape_string($conn, $thumbName);
        
        $q = mysqli_query($conn, "UPDATE hdb_image_data SET thumbnail='$safethumb', updated_at=NOW() WHERE image_name='$safe'");

        if ($q) {
            error_log("[media_review] Thumbnail saved: $thumbName for $name\n", 3, __DIR__ . '/media_review.log');
            jsonOut(array('success' => true, 'thumbnail' => $thumbName, 'url' => 'podcast_thumbnails/' . $thumbName));
        } else {
            jsonOut(array('success' => false, 'message' => 'DB update failed: ' . mysqli_error($conn)));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. update_detail - Update NL tags, format, embedding
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'update_detail') {
        $name         = basename(isset($_POST['name'])        ? $_POST['name']        : '');
        $nlTags       = isset($_POST['nl_tags'])              ? trim($_POST['nl_tags'])       : '';
        $format       = isset($_POST['format'])               ? trim($_POST['format'])        : '';
        $nlChanged    = isset($_POST['nl_changed'])           ? (bool)$_POST['nl_changed']   : false;
        $skipEmbedding = isset($_POST['skip_embedding'])      ? (int)$_POST['skip_embedding'] : 0;

        if (empty($name)) {
            jsonOut(array('success' => false, 'message' => 'Missing filename'));
        }

        $safe    = mysqli_real_escape_string($conn, $name);
        $safeNl  = mysqli_real_escape_string($conn, $nlTags);
        $safeFmt = mysqli_real_escape_string($conn, $format);

        $allowedFormats = array('9x16', '16x9', '');
        if (!in_array($safeFmt, $allowedFormats)) {
            jsonOut(array('success' => false, 'message' => 'Invalid format value'));
        }

        $res = mysqli_query($conn, "SELECT id, natural_language_tags, skip_embedding FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row = ($res) ? mysqli_fetch_assoc($res) : null;
        if (!$row) {
            jsonOut(array('success' => false, 'message' => 'File not found in DB. Add to DB first.'));
        }

        $messages  = array();
        $embVal    = '';
        $embUpdate = '';

        if ($skipEmbedding) {
            $embUpdate  = ", embedding='', skip_embedding=1";
            $messages[] = 'Embedding skipped — excluded from AI matching';
        } else {
            $oldNl = trim($row['natural_language_tags'] ?? '');
            if (!empty($nlTags) && ($nlChanged || $nlTags !== $oldNl)) {
                $emb = generateEmbedding($nlTags);
                if ($emb['success']) {
                    $embVal    = $emb['embedding'];
                    $safeEmb   = mysqli_real_escape_string($conn, $embVal);
                    $embUpdate = ", embedding='$safeEmb', skip_embedding=0";
                    $messages[] = 'Embedding regenerated from new NL tags';
                } else {
                    $messages[] = 'Embedding failed: ' . $emb['error'];
                    $embUpdate  = ", embedding='', skip_embedding=0";
                }
            } elseif (empty($nlTags)) {
                $embUpdate  = ", embedding='', skip_embedding=0";
                $messages[] = 'NL tags cleared — embedding also cleared';
            } else {
                $embUpdate  = ", skip_embedding=0";
                $messages[] = 'NL tags unchanged — embedding kept';
            }
        }

        $q = mysqli_query($conn,
            "UPDATE hdb_image_data
             SET natural_language_tags='$safeNl', media_type_format='$safeFmt'
                 $embUpdate, updated_at=NOW()
             WHERE image_name='$safe'");

        if ($q) {
            $messages[] = 'Saved successfully';
            jsonOut(array(
                'success'        => true,
                'message'        => implode(' | ', $messages),
                'has_emb'        => !empty($embVal),
                'embedding'      => $embVal,
                'skip_embedding' => $skipEmbedding,
            ));
        } else {
            jsonOut(array('success' => false, 'message' => 'DB update failed: ' . mysqli_error($conn)));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. get_db_stats - Get statistics
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'get_db_stats') {
        $q = mysqli_query($conn,
            "SELECT
                media_type,
                COUNT(*) AS total,
                SUM(status = 'verified') AS verified,
                SUM(status != 'verified'
                    AND (natural_language_tags IS NOT NULL AND natural_language_tags != '')
                    AND (embedding IS NOT NULL AND embedding != '')
                    AND (skip_embedding = 0 OR skip_embedding IS NULL)) AS unverified,
                SUM((skip_embedding = 0 OR skip_embedding IS NULL)
                    AND (natural_language_tags IS NULL OR natural_language_tags = ''
                         OR embedding IS NULL OR embedding = '')) AS needs_tags,
                SUM(thumbnail IS NULL OR thumbnail = '') AS no_thumb
            FROM hdb_image_data
            WHERE (admin_id = 0 OR admin_id IS NULL)
            GROUP BY media_type");
        $img = array('total'=>0,'verified'=>0,'unverified'=>0,'needs_tags'=>0,'no_thumb'=>0);
        $vid = array('total'=>0,'verified'=>0,'unverified'=>0,'needs_tags'=>0,'no_thumb'=>0);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $mt = ($r['media_type'] === 'video') ? 'video' : 'image';
                foreach (array('total','verified','unverified','needs_tags','no_thumb') as $k) {
                    if ($mt === 'video') $vid[$k] += (int)$r[$k];
                    else                 $img[$k] += (int)$r[$k];
                }
            }
        }
        jsonOut(array(
            'success'    => true,
            'total'      => $img['total']      + $vid['total'],
            'verified'   => $img['verified']   + $vid['verified'],
            'unverified' => $img['unverified'] + $vid['unverified'],
            'needs_tags' => $img['needs_tags'] + $vid['needs_tags'],
            'no_thumb'   => $img['no_thumb']   + $vid['no_thumb'],
            'img'        => $img,
            'vid'        => $vid,
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. debug - Debug information
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'debug') {
        $dbTest = 'fail';
        if ($conn) {
            $tr = mysqli_query($conn, "SELECT 1 as t");
            if ($tr && mysqli_fetch_assoc($tr)) $dbTest = 'ok';
        }
        jsonOut(array(
            'php_version'         => PHP_VERSION,
            'chatgpt_key_set'     => !empty($chatgpt_api_key),
            'chatgpt_key_preview' => !empty($chatgpt_api_key) ? substr($chatgpt_api_key,0,10).'...' : 'EMPTY',
            'apiKey_set'          => !empty($apiKey),
            'conn_ok'             => ($conn ? true : false),
            'db_query_test'       => $dbTest,
            'image_dir_exists'    => is_dir('podcast_images/'),
            'video_dir_exists'    => is_dir('podcast_videos/'),
            'thumb_dir_exists'    => is_dir('podcast_thumbnails/'),
            'curl_available'      => function_exists('curl_init'),
            'ob_level'            => ob_get_level(),
            'cwd'                 => getcwd(),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. sync_files - Sync files from disk to database
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'sync_files') {
        $imgExts = array('jpg','jpeg','png','webp','gif');
        $vidExts = array('mp4','webm','mov');
        $allExts = array_merge($imgExts, $vidExts);
        $folders = array('image' => 'podcast_images/', 'video' => 'podcast_videos/');

        $added = 0;
        $skipped = 0;
        
        foreach ($folders as $mediaKind => $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, $allExts)) continue;
                
                $safeName = mysqli_real_escape_string($conn, $f);
                $type = in_array($ext, array('mp4','webm','mov')) ? 'video' : 'image';
                
                $check = mysqli_query($conn, "SELECT id, skip_embedding FROM hdb_image_data WHERE image_name = '$safeName' LIMIT 1");
                
                if (!$check || mysqli_num_rows($check) == 0) {
                    $sql = "INSERT INTO hdb_image_data (image_name, media_type, status, admin_id, skip_embedding, created_at, updated_at)
                            VALUES ('$safeName', '$type', '', 0, 0, NOW(), NOW())";
                    if (mysqli_query($conn, $sql)) {
                        $added++;
                    }
                } else {
                    $skipped++;
                }
            }
        }
        
        jsonOut(array('success' => true, 'message' => "Added $added new files, $skipped already exist"));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. get_files - Paginated file list with filters
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'get_files') {
        $page    = max(1, (int)($_GET['page']   ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $tab    = in_array($_GET['tab']    ?? 'image', ['image','video']) ? ($_GET['tab'] ?? 'image') : 'image';
        $filter = $_GET['filter']  ?? 'all';
        $search = trim($_GET['search'] ?? '');
        $fmt    = in_array($_GET['fmt'] ?? '', ['9x16','16x9','']) ? ($_GET['fmt'] ?? '') : '';

        $where = ["skip_embedding = 0", "media_type = '" . mysqli_real_escape_string($conn, $tab) . "'"];

        if ($search !== '') {
            $s = mysqli_real_escape_string($conn, $search);
            $where[] = "image_name LIKE '%$s%'";
        }
        if ($fmt !== '') {
            $f = mysqli_real_escape_string($conn, $fmt);
            $where[] = "media_type_format = '$f'";
        }
        switch ($filter) {
            case 'verified':
                $where[] = "status = 'verified'"; break;
            case 'unverified':
                $where[] = "status != 'verified'";
                $where[] = "natural_language_tags IS NOT NULL AND natural_language_tags != ''";
                $where[] = "embedding IS NOT NULL AND embedding != ''"; break;
            case 'untagged':
                $where[] = "(natural_language_tags IS NULL OR natural_language_tags = '' OR embedding IS NULL OR embedding = '')"; break;
            case 'no-thumb':
                $where[] = "(thumbnail IS NULL OR thumbnail = '')"; break;
            case 'mine':
                $aid = (int)($_GET['admin_id'] ?? 0);
                $where[] = "admin_id = $aid"; break;
            case 'not-in-db':
                jsonOut(['success'=>true,'files'=>[],'total'=>0,'page'=>$page,'per_page'=>$perPage,'total_pages'=>0]);
        }

        $whereSQL  = 'WHERE ' . implode(' AND ', $where);
        $cntRes    = mysqli_query($conn, "SELECT COUNT(*) c FROM hdb_image_data $whereSQL");
        $total     = $cntRes ? (int)mysqli_fetch_assoc($cntRes)['c'] : 0;
        $totalPages= max(1, (int)ceil($total / $perPage));

        $res   = mysqli_query($conn,
            "SELECT image_name, id, status, media_type_format, thumbnail,
             IF(natural_language_tags IS NOT NULL AND natural_language_tags != '', 1, 0) AS has_nl,
             IF(embedding IS NOT NULL AND embedding != '', 1, 0) AS has_emb,
             media_type, admin_id, skip_embedding, niches
             FROM hdb_image_data $whereSQL
             ORDER BY id DESC LIMIT $perPage OFFSET $offset");
        $files = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $folder  = ($row['media_type'] === 'video') ? 'podcast_videos/' : 'podcast_images/';
                $files[] = [
                    'name'           => $row['image_name'],
                    'kind'           => $row['media_type'],
                    'folder'         => $folder,
                    'in_db'          => true,
                    'status'         => $row['status'] ?? '',
                    'has_nl'         => (int)$row['has_nl'] === 1,
                    'has_emb'        => (int)$row['has_emb'] === 1,
                    'db_id'          => $row['id'],
                    'format'         => $row['media_type_format'] ?? '',
                    'thumbnail'      => $row['thumbnail'] ?? '',
                    'admin_id'       => (int)$row['admin_id'],
                    'skip_embedding' => (int)$row['skip_embedding'],
                    'niches'         => $row['niches'] ?? '',
                ];
            }
        }
        $encoded = json_encode([
            'success'     => true,
            'files'       => $files,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo $encoded;
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. search_assets - Semantic search using embeddings
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'search_assets') {
        $query = trim($_POST['query'] ?? '');
        $includeMine = isset($_POST['include_mine']) ? (bool)$_POST['include_mine'] : false;
        
        if (empty($query)) {
            jsonOut(array('success' => false, 'message' => 'Empty query', 'results' => []));
        }
        
        $admin_filter = $includeMine ? "" : "AND (admin_id = 0 OR admin_id IS NULL)";
        
        function loadAssetVectorsForSearch($conn, $admin_filter) {
            $sql = "SELECT id, image_name, natural_language_tags, media_type, embedding, thumbnail
                    FROM hdb_image_data 
                    WHERE embedding IS NOT NULL 
                    AND embedding != '' 
                    AND (skip_embedding = 0 OR skip_embedding IS NULL)
                    $admin_filter
                    ORDER BY id ASC";
            
            $result = mysqli_query($conn, $sql);
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
                    'thumbnail'  => $row['thumbnail'],
                    'embedding'  => $vec,
                    'dims'       => count($vec),
                ];
            }
            return $assets;
        }
        
        function cleanTagsForSearch($tags) {
            $clean = str_replace('|', ', ', $tags);
            $clean = preg_replace('/\s+/', ' ', $clean);
            return trim($clean);
        }
        
        function getEmbeddingForSearch($text, $apiKey) {
            if (empty($apiKey)) return null;
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
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 200) return null;
            $data = json_decode($response, true);
            return $data['data'][0]['embedding'] ?? null;
        }
        
        function cosineSimilarityForSearch($a, $b) {
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
        
        global $chatgpt_api_key;
        $assets = loadAssetVectorsForSearch($conn, $admin_filter);
        
        if (empty($assets)) {
            jsonOut(array('success' => true, 'results' => [], 'message' => 'No assets with embeddings found'));
        }
        
        $cleanQuery = cleanTagsForSearch($query);
        $queryVector = getEmbeddingForSearch($cleanQuery, $chatgpt_api_key);
        
        if (!$queryVector) {
            jsonOut(array('success' => false, 'message' => 'Failed to get embedding for query', 'results' => []));
        }
        
        $queryDims = count($queryVector);
        $scored = [];
        
        foreach ($assets as $asset) {
            if ($asset['dims'] !== $queryDims) continue;
            $score = cosineSimilarityForSearch($queryVector, $asset['embedding']);
            $scored[] = [
                'id'         => $asset['id'],
                'image_name' => $asset['image_name'],
                'media_type' => $asset['media_type'],
                'nl_tags'    => $asset['nl_tags'],
                'thumbnail'  => $asset['thumbnail'],
                'dims'       => $asset['dims'],
                'score'      => $score,
            ];
        }
        
        usort($scored, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $results = array_slice($scored, 0, 30);
        
        $formatted = [];
        foreach ($results as $r) {
            $formatted[] = [
                'id'         => $r['id'],
                'image_name' => $r['image_name'],
                'media_type' => $r['media_type'],
                'nl_tags'    => $r['nl_tags'],
                'thumbnail'  => $r['thumbnail'],
                'score'      => round($r['score'], 4),
                'score_pct'  => round($r['score'] * 100, 1),
            ];
        }
        
        jsonOut(array(
            'success' => true,
            'results' => $formatted,
            'total_assets' => count($assets),
            'query_dims' => $queryDims,
            'query_clean' => $cleanQuery,
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. batch_process - Auto-process files (generate thumbs, tags, embeddings)
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'batch_process') {
        try {
            $name   = basename(isset($_POST['name'])   ? $_POST['name']   : '');
            $folder = isset($_POST['folder']) ? $_POST['folder'] : '';
            $safe   = mysqli_real_escape_string($conn, $name);
            $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isVid  = in_array($ext, array('mp4','webm','mov'));
            $type   = $isVid ? 'video' : 'image';

            if (empty($name)) { jsonOut(array('success' => false, 'message' => 'No filename')); }

            $lowerName = strtolower($name);
            if (strpos($lowerName, 'host') === 0 || strpos($lowerName, 'guest') === 0) {
                jsonOut(array('success' => true, 'message' => 'Skipped — host/guest image (not indexed for search)', 'db_id' => null));
            }

            $messages   = array();
            $needsThumb = false;

            $res    = mysqli_query($conn, "SELECT id, thumbnail, natural_language_tags, embedding, skip_embedding, admin_id FROM hdb_image_data WHERE image_name='$safe' AND (skip_embedding = 0 OR skip_embedding IS NULL) LIMIT 1");
            $exists = ($res) ? mysqli_fetch_assoc($res) : null;
            if (!$exists) {
                $sql = "INSERT INTO hdb_image_data (image_name, media_type, status, admin_id, skip_embedding, created_at, updated_at)
                        VALUES ('$safe', '$type', '', 0, 0, NOW(), NOW())";
                if (mysqli_query($conn, $sql)) {
                    $dbId   = mysqli_insert_id($conn);
                    $exists = array('id' => $dbId, 'thumbnail' => '', 'natural_language_tags' => '', 'embedding' => '', 'admin_id' => 0, 'skip_embedding' => 0);
                    $messages[] = 'Added to DB';
                } else {
                    jsonOut(array('success' => false, 'message' => 'DB insert failed: ' . mysqli_error($conn)));
                }
            }

            $dbId     = $exists['id'];
            $adminId  = (int)($exists['admin_id'] ?? 0);
            $skipEmb  = (int)($exists['skip_embedding'] ?? 0);
            $hasThumb = !empty(trim($exists['thumbnail'] ?? ''));
            $hasNL    = !empty(trim($exists['natural_language_tags'] ?? ''));
            $hasEmb   = !empty(trim($exists['embedding'] ?? ''));
            $nlVal    = $exists['natural_language_tags'] ?? '';

            // Thumbnail generation
            if (!$hasThumb) {
                if ($isVid) {
                    $needsThumb = true;
                    $messages[] = 'Video thumbnail needed (JS will capture)';
                } else {
                    $imgPath = __DIR__ . '/podcast_images/' . $name;
                    if (file_exists($imgPath)) {
                        $src = @imagecreatefromstring(file_get_contents($imgPath));
                        if ($src) {
                            $origW = imagesx($src);
                            $origH = imagesy($src);
                            $ratio = min(320 / $origW, 320 / $origH, 1);
                            $newW  = (int)round($origW * $ratio);
                            $newH  = (int)round($origH * $ratio);
                            $dst   = imagecreatetruecolor($newW, $newH);
                            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                            $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.jpg';
                            $thumbPath = $thumbDir . $thumbName;
                            imagejpeg($dst, $thumbPath, 82);
                            imagedestroy($src);
                            imagedestroy($dst);
                            if (file_exists($thumbPath)) {
                                $safeThumb = mysqli_real_escape_string($conn, $thumbName);
                                mysqli_query($conn, "UPDATE hdb_image_data SET thumbnail='$safeThumb', updated_at=NOW() WHERE id=$dbId");
                                $exists['thumbnail'] = $thumbName;
                                $hasThumb = true;
                                $messages[] = 'Thumbnail generated';
                            } else {
                                $messages[] = 'Thumbnail write failed';
                            }
                        } else {
                            $messages[] = 'GD could not open image';
                        }
                    } else {
                        $messages[] = 'Image file not found on disk';
                    }
                }
            } else {
                $messages[] = 'Thumbnail exists';
            }

            // NL tags generation
            if (!$hasNL) {
                if ($isVid && $needsThumb) {
                    $messages[] = 'NL tags deferred — thumbnail needed first';
                } else {
                    $nl = generateNLTags($name);
                    if ($nl['success']) {
                        $nlVal  = $nl['tags'];
                        $hasNL  = true;
                        $safeNL = mysqli_real_escape_string($conn, $nlVal);
                        mysqli_query($conn, "UPDATE hdb_image_data SET natural_language_tags='$safeNL', updated_at=NOW() WHERE id=$dbId");
                        $messages[] = 'NL tags generated';
                    } else {
                        $messages[] = 'NL tags failed: ' . $nl['error'];
                    }
                }
            } else {
                $messages[] = 'NL tags exist';
            }

            // Embedding generation
            if ($skipEmb == 1) {
                $messages[] = 'Embedding skipped — excluded from AI matching (skip_embedding=1)';
            } elseif ($adminId != 0) {
                $messages[] = 'Skipped — user upload (admin_id = ' . $adminId . '), not indexed for public search';
            } elseif (!$hasEmb && $hasNL) {
                $emb = generateEmbedding($nlVal);
                if ($emb['success']) {
                    $safeEmb = mysqli_real_escape_string($conn, $emb['embedding']);
                    mysqli_query($conn, "UPDATE hdb_image_data SET embedding='$safeEmb', updated_at=NOW() WHERE id=$dbId");
                    $hasEmb = true;
                    $messages[] = 'Embedding generated (public asset)';
                } else {
                    $messages[] = 'Embedding failed: ' . $emb['error'];
                }
            } elseif ($hasEmb) {
                $messages[] = 'Embedding exists';
            }

            jsonOut(array(
                'success'     => true,
                'db_id'       => $dbId,
                'needs_thumb' => $needsThumb,
                'has_thumb'   => $hasThumb,
                'has_nl'      => $hasNL,
                'has_emb'     => $hasEmb,
                'admin_id'    => $adminId,
                'skip_embedding' => $skipEmb,
                'is_public'   => ($adminId == 0 && $skipEmb == 0),
                'message'     => implode(' | ', $messages),
            ));

        } catch (Exception $e) {
            jsonOut(array('success' => false, 'message' => 'Exception: '.$e->getMessage().' line '.$e->getLine()));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. save_video_thumbnail - Save thumbnail for videos
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'save_video_thumbnail') {
        $name      = basename(isset($_POST['name'])       ? $_POST['name']       : '');
        $imageData = isset($_POST['image_data'])           ? $_POST['image_data']  : '';
        $safe      = mysqli_real_escape_string($conn, $name);

        if (empty($name) || empty($imageData)) {
            jsonOut(array('success' => false, 'message' => 'Missing name or image data'));
        }
        if (strpos($imageData, ',') !== false) {
            $imageData = explode(',', $imageData, 2)[1];
        }
        $decoded = base64_decode($imageData);
        if (!$decoded) {
            jsonOut(array('success' => false, 'message' => 'Invalid base64'));
        }
        $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.jpg';
        $thumbPath = $thumbDir . $thumbName;
        file_put_contents($thumbPath, $decoded);
        if (!file_exists($thumbPath)) {
            jsonOut(array('success' => false, 'message' => 'Thumbnail write failed'));
        }
        $safeThumb = mysqli_real_escape_string($conn, $thumbName);
        mysqli_query($conn, "UPDATE hdb_image_data SET thumbnail='$safeThumb', updated_at=NOW() WHERE image_name='$safe'");

        $messages = array('Thumbnail saved');
        $nlVal    = '';
        $hasNL    = false;
        $hasEmb   = false;

        $chk  = mysqli_query($conn, "SELECT id, natural_language_tags, embedding FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row  = $chk ? mysqli_fetch_assoc($chk) : null;
        $dbId = $row ? $row['id'] : 0;

        if ($row && !empty(trim($row['natural_language_tags'] ?? ''))) {
            $nlVal  = $row['natural_language_tags'];
            $hasNL  = true;
            $messages[] = 'NL tags already exist';
        } else {
            $nl = generateNLTags($name);
            if ($nl['success']) {
                $nlVal  = $nl['tags'];
                $hasNL  = true;
                $safeNL = mysqli_real_escape_string($conn, $nlVal);
                mysqli_query($conn, "UPDATE hdb_image_data SET natural_language_tags='$safeNL', updated_at=NOW() WHERE image_name='$safe'");
                $messages[] = 'NL tags generated from thumbnail';
            } else {
                $messages[] = 'NL tags failed: ' . $nl['error'];
            }
        }

        if ($hasNL) {
            if ($row && !empty(trim($row['embedding'] ?? ''))) {
                $hasEmb     = true;
                $messages[] = 'Embedding already exists';
            } else {
                $emb = generateEmbedding($nlVal);
                if ($emb['success']) {
                    $safeEmb = mysqli_real_escape_string($conn, $emb['embedding']);
                    mysqli_query($conn, "UPDATE hdb_image_data SET embedding='$safeEmb', updated_at=NOW() WHERE image_name='$safe'");
                    $hasEmb     = true;
                    $messages[] = 'Embedding generated';
                } else {
                    $messages[] = 'Embedding failed: ' . $emb['error'];
                }
            }
        }

        jsonOut(array(
            'success'   => true,
            'thumbnail' => $thumbName,
            'has_nl'    => $hasNL,
            'has_emb'   => $hasEmb,
            'message'   => implode(' | ', $messages),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. get_detail - Get single file details
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'get_detail') {
        $name = basename(isset($_GET['name']) ? $_GET['name'] : '');
        $safe = mysqli_real_escape_string($conn, $name);
        $res  = mysqli_query($conn, "SELECT * FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row  = ($res) ? mysqli_fetch_assoc($res) : null;
        jsonOut(array('success' => true, 'row' => $row));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. verify - Mark file as verified
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'verify') {
        $name = basename(isset($_POST['name']) ? $_POST['name'] : '');
        $safe = mysqli_real_escape_string($conn, $name);
        $res  = mysqli_query($conn, "SELECT id FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row  = ($res) ? mysqli_fetch_assoc($res) : null;
        if (!$row) {
            jsonOut(array('success' => false, 'message' => 'File not in DB — let auto-batch process it first.'));
        }
        mysqli_query($conn, "UPDATE hdb_image_data SET status='verified', updated_at=NOW() WHERE image_name='$safe'");
        jsonOut(array('success' => true, 'message' => '✅ Marked as verified by staff.'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. add_to_db - Add file to database with tags and embedding
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'add_to_db') {
        $name = basename(isset($_POST['name']) ? $_POST['name'] : '');
        $safe = mysqli_real_escape_string($conn, $name);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $type = in_array($ext, array('mp4','webm','mov')) ? 'video' : 'image';
        $res  = mysqli_query($conn, "SELECT id FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        if ($res && mysqli_fetch_assoc($res)) { jsonOut(array('success' => false, 'message' => 'Already in database.')); }

        $messages = array(); $nlVal = ''; $embVal = '';
        $nl = generateNLTags($name);
        if ($nl['success']) { $nlVal = $nl['tags']; $messages[] = 'NL tags generated'; }
        else { $messages[] = 'NL tags failed: '.$nl['error']; }

        if (!empty($nlVal)) {
            $emb = generateEmbedding($nlVal);
            if ($emb['success']) { $embVal = $emb['embedding']; $messages[] = 'Embedding generated'; }
            else { $messages[] = 'Embedding failed: '.$emb['error']; }
        }

        $sql = "INSERT INTO hdb_image_data (image_name,media_type,natural_language_tags,embedding,status,created_at,updated_at)
                VALUES ('$safe','".mysqli_real_escape_string($conn,$type)."','".mysqli_real_escape_string($conn,$nlVal)."','".mysqli_real_escape_string($conn,$embVal)."','',NOW(),NOW())";
        if (mysqli_query($conn, $sql)) {
            jsonOut(array('success' => true, 'message' => implode(' | ',$messages).' | Added to DB (awaiting verification)', 'new_id' => mysqli_insert_id($conn)));
        } else {
            jsonOut(array('success' => false, 'message' => 'DB insert failed: '.mysqli_error($conn)));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. delete - Delete file from disk and database
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $name   = basename(isset($_POST['name'])   ? $_POST['name']   : '');
        $folder = isset($_POST['folder']) ? $_POST['folder'] : '';
        $safe   = mysqli_real_escape_string($conn, $name);
        $ext    = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (empty($folder)) {
            $folder = in_array($ext, array('mp4','webm','mov')) ? 'podcast_videos/' : 'podcast_images/';
        }
        $path = $folder . $name;

        $tr = mysqli_query($conn, "SELECT thumbnail FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        if ($tr && $td = mysqli_fetch_assoc($tr)) {
            if (!empty($td['thumbnail'])) {
                $tp = __DIR__ . '/podcast_thumbnails/' . $td['thumbnail'];
                if (file_exists($tp)) unlink($tp);
            }
        }

        mysqli_query($conn, "DELETE FROM hdb_image_data WHERE image_name='$safe'");
        $fileOk = true;
        if (file_exists($path)) { $fileOk = unlink($path); }
        if ($fileOk) {
            jsonOut(array('success' => true, 'message' => 'Deleted from folder and database.'));
        } else {
            jsonOut(array('success' => false, 'message' => 'File delete failed. DB record removed.'));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. delete_selected_tags - Delete specific tags from NL tags and regenerate embedding
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'delete_selected_tags') {
        $name = basename(isset($_POST['name']) ? $_POST['name'] : '');
        $tagsToDelete = isset($_POST['tags_to_delete']) ? $_POST['tags_to_delete'] : '';
        
        if (empty($name) || empty($tagsToDelete)) {
            jsonOut(array('success' => false, 'message' => 'Missing name or tags to delete'));
        }
        
        $safe = mysqli_real_escape_string($conn, $name);
        
        $res = mysqli_query($conn, "SELECT natural_language_tags, id FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row = mysqli_fetch_assoc($res);
        
        if (!$row) {
            jsonOut(array('success' => false, 'message' => 'File not found in database'));
        }
        
        $currentTags = $row['natural_language_tags'] ?? '';
        $tagsArray = array_map('trim', explode('|', $currentTags));
        $toDeleteArray = array_map('trim', explode('|', $tagsToDelete));
        
        $newTagsArray = array_diff($tagsArray, $toDeleteArray);
        $newTags = implode('|', $newTagsArray);
        
        $embedding = '';
        $embeddingSuccess = false;
        
        if (!empty(trim($newTags))) {
            $embResult = generateEmbedding($newTags);
            if ($embResult['success']) {
                $embedding = $embResult['embedding'];
                $embeddingSuccess = true;
            }
        }
        
        $safeNewTags = mysqli_real_escape_string($conn, $newTags);
        $safeEmb = mysqli_real_escape_string($conn, $embedding);
        
        $updateSql = "UPDATE hdb_image_data 
                      SET natural_language_tags='$safeNewTags', 
                          embedding='$safeEmb',
                          updated_at=NOW() 
                      WHERE id=" . (int)$row['id'];
        
        if (mysqli_query($conn, $updateSql)) {
            jsonOut(array(
                'success' => true,
                'nl_tags' => $newTags,
                'embedding' => $embedding,
                'deleted_count' => count($toDeleteArray),
                'remaining_count' => count($newTagsArray),
                'embedding_regenerated' => $embeddingSuccess,
                'message' => 'Deleted ' . count($toDeleteArray) . ' tags. ' . ($embeddingSuccess ? 'Embedding regenerated.' : 'No tags remaining, embedding cleared.')
            ));
        } else {
            jsonOut(array('success' => false, 'message' => 'DB update failed: ' . mysqli_error($conn)));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 15b. delete_tags_globally - Remove selected tags from ALL rows in DB
    //      that contain them as exact pipe-separated segments, regenerate
    //      embeddings, and append each tag to uselesstags.txt
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'delete_tags_globally') {
        $tagsToDelete = isset($_POST['tags_to_delete']) ? trim($_POST['tags_to_delete']) : '';

        if (empty($tagsToDelete)) {
            jsonOut(array('success' => false, 'message' => 'No tags provided'));
        }

        $toDeleteArray = array_values(array_filter(array_map('trim', explode('|', $tagsToDelete))));
        if (empty($toDeleteArray)) {
            jsonOut(array('success' => false, 'message' => 'No valid tags to delete'));
        }

        $uselessFile = __DIR__ . '/uselesstags.txt';
        $results     = array();
        $totalRows   = 0;
        $totalFail   = 0;

        foreach ($toDeleteArray as $tag) {
            $escaped = mysqli_real_escape_string($conn, $tag);

            // Find all rows containing this tag as an exact pipe-separated segment
            $q = mysqli_query($conn,
                "SELECT id, natural_language_tags FROM hdb_image_data
                 WHERE natural_language_tags LIKE '%$escaped%'");

            $affected = 0;
            $failed   = 0;

            while ($row = mysqli_fetch_assoc($q)) {
                $id      = (int)$row['id'];
                $oldTags = $row['natural_language_tags'] ?? '';

                // Remove the tag as an exact segment (case-insensitive)
                $parts    = explode('|', $oldTags);
                $filtered = array_values(array_filter(
                    array_map('trim', $parts),
                    function($p) use ($tag) { return strcasecmp($p, $tag) !== 0; }
                ));
                $newTags = implode('|', $filtered);

                // Skip if nothing actually changed
                if ($newTags === implode('|', array_map('trim', $parts))) continue;

                $embedding    = '';
                $embGenerated = false;

                if (!empty(trim($newTags))) {
                    $embResult = generateEmbedding($newTags);
                    if ($embResult['success']) {
                        $embedding    = $embResult['embedding'];
                        $embGenerated = true;
                    } else {
                        $failed++;
                    }
                }

                $safeNewTags = mysqli_real_escape_string($conn, $newTags);
                $safeEmb     = mysqli_real_escape_string($conn, $embedding);

                mysqli_query($conn,
                    "UPDATE hdb_image_data
                     SET natural_language_tags='$safeNewTags',
                         embedding='$safeEmb',
                         updated_at=NOW()
                     WHERE id=$id");

                $affected++;
            }

            // Append tag to uselesstags.txt (one per line, no duplicates)
            $existing = file_exists($uselessFile) ? file_get_contents($uselessFile) : '';
            $existingLines = array_map('trim', explode("\n", $existing));
            if (!in_array($tag, $existingLines)) {
                file_put_contents($uselessFile, $tag . "\n", FILE_APPEND | LOCK_EX);
            }

            $results[] = array(
                'tag'      => $tag,
                'rows'     => $affected,
                'failed'   => $failed,
            );
            $totalRows += $affected;
            $totalFail += $failed;
        }

        jsonOut(array(
            'success'     => true,
            'total_rows'  => $totalRows,
            'total_fail'  => $totalFail,
            'details'     => $results,
            'saved_to'    => 'uselesstags.txt',
            'message'     => "Deleted " . count($toDeleteArray) . " tag(s) from $totalRows row(s) globally."
                           . ($totalFail > 0 ? " $totalFail embedding(s) failed." : "")
                           . " Tags saved to uselesstags.txt.",
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 15. generate_tags - Force generate NL tags and embedding from media
    // ─────────────────────────────────────────────────────────────────────────
    if ($action === 'generate_tags') {
        $name   = basename(isset($_POST['name'])   ? $_POST['name']   : '');
        $folder = isset($_POST['folder']) ? $_POST['folder'] : '';
        
        if (empty($name)) {
            jsonOut(array('success' => false, 'message' => 'No filename provided'));
        }
        
        $safe = mysqli_real_escape_string($conn, $name);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $isVid = in_array($ext, array('mp4', 'webm', 'mov'));
        
        $checkRes = mysqli_query($conn, "SELECT id, thumbnail FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $existing = mysqli_fetch_assoc($checkRes);
        $dbId = $existing ? $existing['id'] : null;
        
        if (!$dbId) {
            $type = $isVid ? 'video' : 'image';
            $insertSql = "INSERT INTO hdb_image_data (image_name, media_type, status, admin_id, skip_embedding, created_at, updated_at) 
                          VALUES ('$safe', '$type', '', 0, 0, NOW(), NOW())";
            if (mysqli_query($conn, $insertSql)) {
                $dbId = mysqli_insert_id($conn);
            } else {
                jsonOut(array('success' => false, 'message' => 'Failed to create DB record: ' . mysqli_error($conn)));
            }
        }
        
        $nlResult = generateNLTags($name);
        
        if (!$nlResult['success']) {
            jsonOut(array('success' => false, 'message' => 'NL tag generation failed: ' . ($nlResult['error'] ?? 'Unknown error')));
        }
        
        $nlTags = $nlResult['tags'];
        
        $embResult = generateEmbedding($nlTags);
        $embedding = $embResult['success'] ? $embResult['embedding'] : '';
        
        $safeNl = mysqli_real_escape_string($conn, $nlTags);
        $safeEmb = mysqli_real_escape_string($conn, $embedding);
        
        $updateSql = "UPDATE hdb_image_data 
                      SET natural_language_tags='$safeNl', 
                          embedding='$safeEmb',
                          skip_embedding=0,
                          updated_at=NOW() 
                      WHERE id=$dbId";
        
        if (mysqli_query($conn, $updateSql)) {
            jsonOut(array(
                'success' => true,
                'nl_tags' => $nlTags,
                'embedding' => $embedding,
                'message' => 'Tags generated and saved successfully',
                'saved' => true
            ));
        } else {
            jsonOut(array(
                'success' => false,
                'message' => 'DB update failed: ' . mysqli_error($conn),
                'nl_tags' => $nlTags
            ));
        }
    }

    if ($action === 'scan_orphans') {
        $orphans = array();
        $res = mysqli_query($conn, "SELECT id, image_name, media_type FROM hdb_image_data WHERE admin_id = 0 OR admin_id IS NULL");
        if ($res) { while ($row = mysqli_fetch_assoc($res)) {
            $mt = $row['media_type']==='video'?'video':'image';
            $p  = __DIR__.'/'.(($mt==='video')?'podcast_videos/':'podcast_images/').$row['image_name'];
            if (!file_exists($p)) $orphans[]=array('id'=>(int)$row['id'],'name'=>$row['image_name'],'type'=>$mt);
        }}
        jsonOut(array('success'=>true,'orphans'=>$orphans,'count'=>count($orphans)));
    }
    if ($action === 'delete_orphans') {
        $ids=$_POST['ids']??''; $idArr=array_filter(array_map('intval',explode(',',$ids)));
        if (empty($idArr)) jsonOut(array('success'=>false,'message'=>'No IDs'));
        $del=0; foreach ($idArr as $id) {
            $tr=mysqli_query($conn,"SELECT thumbnail FROM hdb_image_data WHERE id=$id LIMIT 1");
            if ($tr&&($td=mysqli_fetch_assoc($tr))&&!empty($td['thumbnail'])) { $tp=__DIR__.'/podcast_thumbnails/'.$td['thumbnail']; if(file_exists($tp))@unlink($tp); }
            if (mysqli_query($conn,"DELETE FROM hdb_image_data WHERE id=$id")) $del++;
        }
        jsonOut(array('success'=>true,'deleted'=>$del,'message'=>"Deleted $del orphaned DB records"));
    }
    if ($action === 'generate_niches') {
        $name=basename($_POST['name']??''); if(empty($name)) jsonOut(array('success'=>false,'message'=>'No filename'));
        $safe=mysqli_real_escape_string($conn,$name);
        $chk=mysqli_query($conn,"SELECT id,niches,thumbnail,media_type FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $row=$chk?mysqli_fetch_assoc($chk):null;
        if (!$row) jsonOut(array('success'=>false,'message'=>'File not in DB'));
        if (!empty(trim($row['niches']??''))) jsonOut(array('success'=>true,'niches'=>$row['niches'],'skipped'=>true,'message'=>'Niches already set'));
        $r=generateNiches($name);
        if (!$r['success']) jsonOut(array('success'=>false,'message'=>$r['error']));
        $n=mysqli_real_escape_string($conn,$r['niches']);
        $dbId2=(int)$row['id'];
        mysqli_query($conn,"UPDATE hdb_image_data SET niches='$n',updated_at=NOW() WHERE id=$dbId2 AND (niches IS NULL OR niches='')");
        jsonOut(array('success'=>true,'niches'=>$r['niches'],'message'=>'Niches saved'));
    }
    if ($action === 'save_niches') {
        $name=basename($_POST['name']??''); $niches=trim($_POST['niches']??'');
        if(empty($name)) jsonOut(array('success'=>false,'message'=>'No filename'));
        $safe=mysqli_real_escape_string($conn,$name); $sn=mysqli_real_escape_string($conn,$niches);
        $ok=mysqli_query($conn,"UPDATE hdb_image_data SET niches='$sn',updated_at=NOW() WHERE image_name='$safe'");
        if ($ok) jsonOut(array('success'=>true,'message'=>'Niches saved'));
        else     jsonOut(array('success'=>false,'message'=>'DB error: '.mysqli_error($conn)));
    }
    // If no action matched
    jsonOut(array('success' => false, 'message' => 'Unknown action: '.$action));
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

// ── Generate NL tags ──────────────────────────────────────────────────────────
function generateNLTags($filename) {
    global $chatgpt_api_key, $conn, $thumbDir;
    $key = $chatgpt_api_key;
    if (empty($key)) {
        return array('success' => false, 'error' => 'chatgpt_api_key is empty in config.php');
    }

    $ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isVid = in_array($ext, array('mp4','webm','mov'));

    $systemPrompt = "You are a precise visual content tagger. "
                  . "Your ONLY job is to describe exactly and literally what is visible in the image — specific people, actions, objects, and setting. "
                  . "RULES: "
                  . "(1) If people are present, they MUST be the first and primary focus — describe their number, gender, apparent age, what they are doing, and any objects they are interacting with. "
                  . "(2) Describe only what is concretely visible. Never infer, assume, or generalize. "
                  . "(3) Do NOT use vague ambient phrases like 'modern interior', 'bright room', 'office setting', 'cozy atmosphere', 'well-lit space', or 'stylish environment'. "
                  . "(4) Never mention: resolution, fps, HD, 4K, codec, 'stock footage', 'royalty free', 'high quality', 'cinematic', or any technical/marketing term. "
                  . "Output ONLY pipe-separated phrases. No preamble, no explanation, no bullet points.";

    $userPrompt = "Describe EXACTLY what you literally see in this image.\n\n"
                . "Step 1 — People (required if any are visible): How many? Men/women? What are they doing? What are they wearing or holding? What objects are they interacting with?\n"
                . "Step 2 — Setting: What specific type of room or location is this? Name it precisely (e.g. 'nail salon', 'kitchen counter', 'outdoor park bench') — not vaguely ('interior space', 'room').\n"
                . "Step 3 — Key visible objects: List only the most prominent specific items.\n\n"
                . "Return 6-8 pipe-separated phrases based on what you literally see.\n\n"
                . "Return ONLY the pipe-separated phrases. Be specific. Be literal.";

    if ($isVid) {
        $safe      = mysqli_real_escape_string($conn, $filename);
        $tq        = mysqli_query($conn, "SELECT thumbnail FROM hdb_image_data WHERE image_name='$safe' LIMIT 1");
        $frameB64  = null;
        $frameMime = 'image/jpeg';

        if ($tq && $tr = mysqli_fetch_assoc($tq)) {
            $thumbName = trim($tr['thumbnail'] ?? '');
            if (!empty($thumbName)) {
                $thumbPath = __DIR__ . '/podcast_thumbnails/' . $thumbName;
                if (file_exists($thumbPath) && filesize($thumbPath) > 100) {
                    $tExt      = strtolower(pathinfo($thumbName, PATHINFO_EXTENSION));
                    $mimeMap   = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp');
                    $frameMime = isset($mimeMap[$tExt]) ? $mimeMap[$tExt] : 'image/jpeg';
                    $frameB64  = base64_encode(file_get_contents($thumbPath));
                }
            }
        }

        if ($frameB64) {
            $payload = array(
                'model'    => 'gpt-4o',
                'messages' => array(
                    array('role' => 'system', 'content' => $systemPrompt),
                    array('role' => 'user', 'content' => array(
                        array('type' => 'image_url', 'image_url' => array('url' => 'data:'.$frameMime.';base64,'.$frameB64, 'detail' => 'auto')),
                        array('type' => 'text', 'text' => $userPrompt),
                    )),
                ),
                'max_tokens' => 200,
            );
        } else {
            $nameClean = pathinfo($filename, PATHINFO_FILENAME);
            $nameClean = preg_replace('/[_\-]+/', ' ', $nameClean);
            $nameClean = preg_replace('/\b(stock|footage|clip|video|hd|4k|bg|background|loop|free|HD|FHD|UHD|\d+)\b/i', '', $nameClean);
            $nameClean = trim(preg_replace('/\s+/', ' ', $nameClean));
            $payload = array(
                'model'    => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => $systemPrompt),
                    array('role' => 'user', 'content' =>
                        "Video filename hint: \"$nameClean\"\n\n$userPrompt"
                    ),
                ),
                'max_tokens' => 200,
            );
        }
    } else {
        $imgPath = __DIR__ . '/podcast_images/' . $filename;
        if (!file_exists($imgPath)) {
            return array('success' => false, 'error' => 'File not found: ' . $imgPath);
        }
        $mimeMap = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif');
        $mime    = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'image/jpeg';
        $b64     = base64_encode(file_get_contents($imgPath));
        $payload = array(
            'model'    => 'gpt-4o',
            'messages' => array(
                array('role' => 'system', 'content' => $systemPrompt),
                array('role' => 'user', 'content' => array(
                    array('type' => 'image_url', 'image_url' => array('url' => "data:{$mime};base64,{$b64}", 'detail' => 'high')),
                    array('type' => 'text', 'text' => $userPrompt),
                )),
            ),
            'max_tokens' => 250,
        );
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $key));
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)         return array('success' => false, 'error' => 'cURL: ' . $err);
    if ($code != 200) return array('success' => false, 'error' => "API $code: " . $res);

    $data = json_decode($res, true);
    $tags = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : '';
    if (empty($tags)) return array('success' => false, 'error' => 'Empty API response');
    $tags = mb_convert_encoding($tags, 'UTF-8', 'UTF-8');

    $technicalPatterns = array(
        '/\b\d{3,4}p\b/i', '/\b(4k|8k|hd|fhd|uhd)\b/i', '/\b\d+\s*fps\b/i',
        '/\bframe\s*rate\b/i', '/\b(high[- ]?definition|high[- ]?resolution)\b/i',
        '/\b(resolution|bitrate|codec|pixel)\b/i', '/\b(royalt[yi][- ]?free)\b/i',
        '/\bstock[- ]?(footage|video|clip|image)\b/i',
    );

    $parts = array_map('trim', explode('|', $tags));
    $filtered = array_filter($parts, function($phrase) use ($technicalPatterns) {
        foreach ($technicalPatterns as $pattern) {
            if (preg_match($pattern, $phrase)) return false;
        }
        return strlen(trim($phrase)) > 3;
    });

    $tags = implode('|', array_values($filtered));
    if (empty(trim($tags))) {
        return array('success' => false, 'error' => 'All tags were technical — GPT ignored instructions. Will retry on next batch run.');
    }

    return array('success' => true, 'tags' => $tags);
}

// ── Generate embedding ───────────────────────────────────────────────────────
function generateNiches($filename) {
    global $chatgpt_api_key, $conn, $thumbDir;
    $key = $chatgpt_api_key;
    if (empty($key)) return array('success'=>false,'error'=>'chatgpt_api_key empty');
    $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
    $isVid=in_array($ext,array('mp4','webm','mov'));
    $sys="You are a marketing asset categorization expert. Analyze the image and determine the most relevant "
        ."business niches it can be used for in marketing or advertising. Base your analysis on the people, "
        ."setting, clothing, mood, objects, and environment shown. Return ONLY lowercase comma-separated niche "
        ."keywords. Include 3 to 8 of the most relevant niches. "
        ."Examples: finance,insurance,real estate,business,lawyer,healthcare,dental,fitness,education,"
        ."ecommerce,travel,beauty,construction,restaurant,technology,automotive,mortgage,accounting.";
    $usr="What business niches can this image be used for in marketing? "
        ."Return ONLY comma-separated lowercase niche keywords (3-8 niches). No explanation.";
    $b64=null; $mime='image/jpeg';
    $mm=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif');
    $safe2=mysqli_real_escape_string($conn,$filename);
    $tq=mysqli_query($conn,"SELECT thumbnail FROM hdb_image_data WHERE image_name='$safe2' LIMIT 1");
    if ($tq&&$tr=mysqli_fetch_assoc($tq)) {
        $tn=trim($tr['thumbnail']??'');
        if (!empty($tn)) { $tp=$thumbDir.$tn; if(file_exists($tp)&&filesize($tp)>100) {
            $te=strtolower(pathinfo($tn,PATHINFO_EXTENSION));
            $mime=isset($mm[$te])?$mm[$te]:'image/jpeg';
            $b64=base64_encode(file_get_contents($tp));
        }}
    }
    if (!$b64&&$isVid) return array('success'=>false,'error'=>'No thumbnail for video - capture one first');
    if (!$b64) {
        $imgPath=__DIR__.'/podcast_images/'.$filename;
        if (!file_exists($imgPath)) return array('success'=>false,'error'=>'Image not found');
        $mime=isset($mm[$ext])?$mm[$ext]:'image/jpeg';
        $b64=base64_encode(file_get_contents($imgPath));
    }
    $payload=array('model'=>'gpt-4o','max_tokens'=>80,'messages'=>array(
        array('role'=>'system','content'=>$sys),
        array('role'=>'user','content'=>array(
            array('type'=>'image_url','image_url'=>array('url'=>'data:'.$mime.';base64,'.$b64,'detail'=>'low')),
            array('type'=>'text','text'=>$usr),
        ))
    ));
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true); curl_setopt($ch,CURLOPT_POST,true);
    curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
    curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json','Authorization: Bearer '.$key));
    curl_setopt($ch,CURLOPT_TIMEOUT,30);
    $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if ($err) return array('success'=>false,'error'=>'cURL: '.$err);
    if ($code!=200) return array('success'=>false,'error'=>"API $code: ".substr($res,0,200));
    $data=json_decode($res,true);
    $niches=isset($data['choices'][0]['message']['content'])?trim($data['choices'][0]['message']['content']):'';
    if (empty($niches)) return array('success'=>false,'error'=>'Empty API response');
    $niches=strtolower($niches);
    $niches=preg_replace('/[^a-z ,]/','',$niches);
    $parts=array_values(array_filter(array_map('trim',explode(',',$niches))));
    $parts=array_slice($parts,0,8);
    $niches=implode(',',$parts);
    if (empty($niches)) return array('success'=>false,'error'=>'No valid niches returned');
    return array('success'=>true,'niches'=>$niches);
}

function generateEmbedding($text) {
    global $chatgpt_api_key;
    $key = $chatgpt_api_key;
    
    if (empty($key)) {
        return array('success' => false, 'error' => 'chatgpt_api_key is empty in config.php');
    }

    $clean = trim(str_replace('|', ' ', $text));
    if (empty($clean)) return array('success' => false, 'error' => 'Empty text');

    $payload = array('model' => 'text-embedding-3-large', 'input' => $clean);

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $key));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)         return array('success' => false, 'error' => 'cURL: ' . $err);
    if ($code != 200) return array('success' => false, 'error' => "API $code");

    $data   = json_decode($res, true);
    $vector = isset($data['data'][0]['embedding']) ? $data['data'][0]['embedding'] : null;
    if (!$vector) return array('success' => false, 'error' => 'No embedding returned');

    $dimensions = count($vector);
    error_log("EMBEDDING DEBUG: Model returned " . $dimensions . " dimensions for text: " . substr($clean, 0, 50));
    
    if ($dimensions != 3072) {
        error_log("WARNING: Expected 3072 dimensions but got " . $dimensions);
    }

    return array('success' => true, 'embedding' => json_encode($vector));
}

// =============================================================================
// HTML OUTPUT STARTS HERE
// =============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Media Review</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#eef4fb;--s1:#ffffff;--s2:#f0f6ff;--s3:#ddeaf8;
    --bdr:#c5d8ef;--acc:#2563eb;--grn:#16a34a;--org:#d97706;--red:#dc2626;--purple:#7c3aed;
    --txt:#1e3a5f;--mut:#64869e;--r:10px;
    font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;
}
body{background:var(--bg);color:var(--txt);min-height:100vh;padding:20px}

.page-header{padding:16px 20px;background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r);margin-bottom:14px;box-shadow:0 2px 8px rgba(37,99,235,.08)}
.page-header h1{font-size:18px;font-weight:700;color:var(--acc);margin-bottom:14px}
.stats-grid{display:grid;grid-template-columns:100px repeat(5,1fr);gap:6px;align-items:center}
.stats-row-label{font-size:12px;font-weight:700;color:var(--mut);padding:6px 8px;background:var(--bg);border-radius:6px;text-align:center}
.stat-hdr{text-align:center;padding:4px 6px;border-radius:6px;font-size:11px;font-weight:700}
.stat-hdr.c-total    {background:#eff6ff;color:var(--acc)}
.stat-hdr.c-verified {background:#f0fdf4;color:#15803d}
.stat-hdr.c-unverified{background:#f5f3ff;color:#6d28d9}
.stat-hdr.c-untagged {background:#fffbeb;color:#b45309}
.stat-hdr.c-notindb  {background:#fef2f2;color:#dc2626}
.stat-cell{background:var(--bg);border:1px solid var(--bdr);border-radius:8px;padding:8px 6px;text-align:center}
.stat-cell strong{display:block;font-size:22px;font-weight:700;line-height:1}
.stat-cell span{font-size:10px;color:var(--mut);margin-top:2px;display:block}
.stat-cell.c-total     strong{color:var(--acc)}
.stat-cell.c-verified  strong{color:var(--grn)}
.stat-cell.c-unverified strong{color:#7c3aed}
.stat-cell.c-untagged  strong{color:var(--org)}
.stat-cell.c-notindb   strong{color:var(--red)}

.tabs{display:flex;background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r);margin-bottom:10px;overflow:hidden;box-shadow:0 1px 4px rgba(37,99,235,.06)}
.tab-btn{flex:1;padding:13px 20px;border:none;background:transparent;color:var(--mut);font-size:14px;font-weight:600;cursor:pointer;border-bottom:3px solid transparent;transition:.15s}
.tab-btn:hover{color:var(--acc);background:var(--bg)}
.tab-btn.active{color:var(--acc);border-bottom-color:var(--acc);background:var(--s2)}

.filter-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;background:var(--s1);border:1px solid var(--bdr);border-radius:var(--r);margin-bottom:12px;box-shadow:0 1px 4px rgba(37,99,235,.06)}
.filter-btn{padding:5px 13px;border-radius:20px;border:1px solid var(--bdr);background:transparent;color:var(--mut);cursor:pointer;font-size:12px;font-weight:600;transition:.15s}
.filter-btn:hover{border-color:var(--acc);color:var(--acc);background:#eff6ff}
.filter-btn.active{background:var(--acc);border-color:var(--acc);color:#fff}
.search-in{flex:1;min-width:150px;background:var(--bg);border:1px solid var(--bdr);color:var(--txt);padding:7px 12px;border-radius:8px;font-size:12px;outline:none}
.search-in:focus{border-color:var(--acc);background:#fff}
.search-in::placeholder{color:var(--mut)}
.format-sel{background:var(--bg);border:1px solid var(--bdr);color:var(--txt);padding:7px 10px;border-radius:8px;font-size:12px;outline:none;cursor:pointer}
.format-sel:focus{border-color:var(--acc)}
.filter-count{font-size:12px;color:var(--mut);white-space:nowrap}

#grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;display:grid}
.card{background:var(--s1);border:2px solid var(--bdr);border-radius:var(--r);overflow:hidden;cursor:pointer;transition:.18s;position:relative;box-shadow:0 1px 4px rgba(37,99,235,.07)}
.card:hover{border-color:var(--acc);transform:translateY(-2px);box-shadow:0 6px 20px rgba(37,99,235,.15)}
.card.status-verified {border-color:#86efac}
.card.status-untagged {border-color:#fcd34d}
.card.status-not-in-db{border-color:#fca5a5}
.card-thumb{width:100%;aspect-ratio:9/16;background:#dde8f5;overflow:hidden;position:relative}
.card-thumb.fmt-16x9{aspect-ratio:16/9}
.card-thumb img,.card-thumb video{width:100%;height:100%;object-fit:cover;display:block}
.vid-badge{position:absolute;inset:0;display:grid;place-items:center;background:rgba(0,0,0,.22);font-size:28px;pointer-events:none}
.card-label{padding:6px 8px;font-size:11px;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-top:1px solid var(--bdr);background:var(--s1)}
.status-pill{position:absolute;top:6px;left:6px;padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;pointer-events:none}
.pill-verified {background:rgba(22,163,74,.15);color:#15803d;border:1px solid rgba(22,163,74,.4)}
.pill-untagged {background:rgba(217,119,6,.12);color:#b45309;border:1px solid rgba(217,119,6,.35)}
.pill-not-in-db{background:rgba(220,38,38,.1); color:#dc2626;border:1px solid rgba(220,38,38,.3)}
.thumb-indicator{position:absolute;bottom:6px;left:6px;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:700;background:rgba(124,58,237,.15);color:var(--purple);border:1px solid rgba(124,58,237,.3);pointer-events:none}
.fmt-badge{position:absolute;bottom:6px;right:6px;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:700;pointer-events:none;background:rgba(37,99,235,.12);color:var(--acc);border:1px solid rgba(37,99,235,.25)}

/* Search results styling */
.score-high { color: #1a7a3a; font-weight: bold; }
.score-mid  { color: #b87a00; font-weight: bold; }
.score-low  { color: #cc3333; }
.score-bar { background: #eee; border-radius: 4px; height: 4px; overflow: hidden; }
.score-fill { height: 100%; border-radius: 4px; transition: width .2s; }

/* ── Lightbox ── */
#lightbox{display:none;position:fixed;inset:0;z-index:900;background:rgba(30,58,95,.6);backdrop-filter:blur(6px)}
.lb-inner{background:var(--s1);margin:2vh auto;width:97%;max-width:1300px;height:96vh;border-radius:14px;border:1px solid var(--bdr);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(30,58,95,.2)}
.lb-header{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--bg);border-bottom:1px solid var(--bdr);flex-shrink:0}
.lb-header h2{font-size:14px;color:var(--acc);word-break:break-all;max-width:70%;font-weight:600}
.lb-close{background:none;border:none;color:var(--mut);font-size:22px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:.15s}
.lb-close:hover{color:var(--red);background:rgba(220,38,38,.08)}
.lb-body{display:flex;flex:1;overflow:hidden}

.lb-media{width:min(260px,30%);min-width:160px;background:#dde8f5;display:flex;align-items:stretch;justify-content:center;flex-shrink:0;flex-direction:column;gap:0;overflow:hidden}
#lbPreview{flex:1;width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden}
#lbPreview img,#lbPreview video{width:100%;height:100%;object-fit:contain}

.lb-thumb-strip{width:100%;padding:8px 10px;background:rgba(0,0,0,.06);border-top:1px solid var(--bdr);flex-shrink:0}
.lb-thumb-strip-label{font-size:10px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
.lb-thumb-img{
    width:100%;
    aspect-ratio:9/16;
    object-fit:cover;
    border-radius:6px;
    border:2px solid var(--grn);
    display:block;
}
.lb-thumb-none{font-size:11px;color:var(--mut);font-style:italic}

.lb-info{flex:1;padding:18px 22px;overflow-y:auto;display:flex;flex-direction:column;gap:14px;background:var(--s1)}
.info-row{display:flex;flex-direction:column;gap:5px}
.info-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--mut);display:flex;align-items:center;gap:6px}
.info-val{background:var(--bg);border:1px solid var(--bdr);border-radius:8px;padding:10px 12px;font-size:14px;color:var(--txt);line-height:1.7;min-height:40px}

.info-textarea{
    background:var(--bg);
    border:1px solid var(--bdr);
    border-radius:8px;
    padding:10px 12px;
    font-size:13px;
    color:var(--txt);
    line-height:1.6;
    width:100%;
    resize:vertical;
    font-family:inherit;
    outline:none;
    transition:border-color .15s, background .15s;
    min-height:70px;
}
.info-textarea:focus{border-color:var(--acc);background:#fff;}
.info-textarea.embedding-ta{min-height:80px;font-size:11px;font-family:'Consolas','Courier New',monospace;color:var(--mut);}
.info-select{
    background:var(--bg);
    border:1px solid var(--bdr);
    border-radius:8px;
    padding:9px 12px;
    font-size:14px;
    color:var(--txt);
    width:100%;
    outline:none;
    cursor:pointer;
    font-family:inherit;
    transition:border-color .15s, background .15s;
    appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2364869e' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:right 12px center;
    padding-right:32px;
}
.info-select:focus{border-color:var(--acc);background-color:#fff;}
.edit-hint{font-size:10px;color:var(--mut);font-style:italic;margin-left:auto;}

.status-badge{display:inline-block;padding:7px 18px;border-radius:20px;font-weight:700;font-size:15px}
.sb-verified {background:rgba(22,163,74,.1); color:#15803d;border:1px solid rgba(22,163,74,.35)}
.sb-untagged {background:rgba(217,119,6,.1); color:#b45309;border:1px solid rgba(217,119,6,.3)}
.sb-not-in-db{background:rgba(220,38,38,.08);color:#dc2626;border:1px solid rgba(220,38,38,.25)}

.lb-footer{padding:12px 18px;background:var(--bg);border-top:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-shrink:0;flex-wrap:wrap}
.lb-status{font-size:13px;color:var(--mut);flex:1}
.lb-status.ok{color:var(--grn)}.lb-status.err{color:var(--red)}
.btn{padding:9px 20px;border-radius:8px;border:none;font-weight:700;font-size:13px;cursor:pointer;transition:.15s}
.btn:hover{opacity:.88;transform:translateY(-1px)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none}
.btn-verify{background:var(--grn);color:#fff}
.btn-add   {background:var(--acc);color:#fff}
.btn-delete{background:var(--red);color:#fff}
.btn-ghost {background:#fff;color:var(--txt);border:1px solid var(--bdr)}
.btn-capture{background:var(--purple);color:#fff}
.btn-update{background:#0e7490;color:#fff}
.btn-search{background:#7c3aed;color:#fff}

/* ── Capture modal ── */
#captureModal{display:none;position:fixed;inset:0;z-index:1100;background:rgba(30,58,95,.7);backdrop-filter:blur(6px);align-items:center;justify-content:center}
#captureModal.open{display:flex}
.capture-panel{background:#fff;border-radius:16px;width:96%;max-width:700px;max-height:92vh;overflow:hidden;box-shadow:0 20px 60px rgba(30,58,95,.25);display:flex;flex-direction:column}
.capture-header{padding:14px 18px;background:var(--bg);border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between}
.capture-header h3{font-size:15px;font-weight:700;color:var(--purple)}
.capture-header button{background:none;border:none;font-size:20px;color:var(--mut);cursor:pointer}
.capture-header button:hover{color:var(--red)}
.capture-body{display:flex;gap:0;flex:1;overflow:hidden}
.capture-left{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#111;padding:12px;gap:10px}
.capture-right{width:200px;flex-shrink:0;padding:14px;display:flex;flex-direction:column;gap:12px;border-left:1px solid var(--bdr);overflow-y:auto}
#captureVideo{width:100%;max-height:380px;border-radius:8px;background:#000;display:block}
#captureCanvas{display:none}
.scrub-wrap{width:100%;display:flex;flex-direction:column;gap:5px}
.scrub-wrap label{font-size:11px;color:rgba(255,255,255,.6);font-weight:600}
#scrubber{width:100%;accent-color:var(--purple)}
.capture-preview-label{font-size:11px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.5px}
#capturePreviewImg{width:100%;border-radius:8px;border:2px solid var(--bdr);display:none}
#capturePreviewNone{font-size:12px;color:var(--mut);font-style:italic}
.capture-footer{padding:12px 18px;background:var(--bg);border-top:1px solid var(--bdr);display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}

/* ── Confirm ── */
#confirmOvl{display:none;position:fixed;inset:0;z-index:1900;background:rgba(30,58,95,.5);backdrop-filter:blur(4px)}
.confirm-box{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2000;background:#fff;border:1px solid #fca5a5;border-radius:14px;padding:28px 32px;text-align:center;min-width:320px;box-shadow:0 20px 60px rgba(30,58,95,.2)}
.confirm-box h3{font-size:18px;color:var(--red);margin-bottom:8px}
.confirm-box p{color:var(--mut);font-size:13px;margin-bottom:22px;line-height:1.5}
.confirm-box .btn-row{display:flex;gap:10px;justify-content:center}

.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;display:inline-block;animation:spin .6s linear infinite;vertical-align:middle;margin-right:5px}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--mut)}
.empty span{font-size:48px;display:block;margin-bottom:10px}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:3px}::-webkit-scrollbar-thumb:hover{background:var(--mut)}

#batchBanner{display:none;background:#fff;border:1px solid var(--bdr);border-radius:var(--r);padding:14px 18px;margin-bottom:12px;box-shadow:0 1px 4px rgba(37,99,235,.08)}

/* ── Search Panel ── */
#searchPanel { margin-top:10px; width:100%; }
#searchPanel textarea { flex:3; min-width:250px; padding:12px 14px; border-radius:12px; border:1.5px solid #c4b5fd; background:#fff; font-size:14px; resize:vertical; font-family:inherit; }
#searchPanel textarea:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.1); }
#searchBtn { background:#7c3aed; color:#fff; border:none; padding:12px 32px; border-radius:12px; font-weight:700; cursor:pointer; font-size:15px; min-width:120px; transition:all .2s ease; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
#searchBtn:hover:not(:disabled) { background:#6d28d9; transform:translateY(-2px); box-shadow:0 4px 12px rgba(124,58,237,.3); }
#searchBtn:disabled { opacity:.6; cursor:not-allowed; transform:none; }
#searchIncludeMine { width:18px; height:18px; margin-right:6px; cursor:pointer; }
#searchStats { margin-bottom:12px; font-size:13px; color:#6d28d9; padding:8px 12px; background:#f5f3ff; border-radius:10px; }
#searchGrid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-top:8px; }
.pill-user-upload {
    background: rgba(124, 58, 237, 0.15);
    color: #6d28d9;
    border: 1px solid rgba(124, 58, 237, 0.4);
}
/* ── Pagination ── */
#paginationBar{display:flex;align-items:center;justify-content:center;gap:6px;padding:14px 0;flex-wrap:wrap;}
.pg-btn{min-width:34px;height:34px;padding:0 10px;border-radius:8px;border:1.5px solid var(--bdr);
        background:var(--s1);color:var(--txt);font-size:13px;font-weight:600;cursor:pointer;transition:.15s;
        display:flex;align-items:center;justify-content:center;}
.pg-btn:hover:not(:disabled){border-color:var(--acc);color:var(--acc);background:#eff6ff;}
.pg-btn.active{background:var(--acc);border-color:var(--acc);color:#fff;}
.pg-btn:disabled{opacity:.35;cursor:not-allowed;}
.pg-info{font-size:12px;color:var(--mut);padding:0 8px;white-space:nowrap;}
.btn-generate {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #fff;
    border: none;
    box-shadow: 0 1px 3px rgba(124, 58, 237, 0.3);
}
.btn-generate:hover:not(:disabled) {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
}
.btn-generate:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Force Generate Tags button to be visible when in DB */
#btnGenerateTags {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #fff;
    border: none;
    padding: 9px 20px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    transition: all .15s;
}

#btnGenerateTags:hover:not(:disabled) {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
}

#btnGenerateTags:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

/* Tag button styles */
.tag-btn {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .2s ease;
    border: 1.5px solid var(--bdr);
    background: white;
    color: var(--txt);
    user-select: none;
}

.tag-btn:hover {
    background: var(--bg);
    transform: translateY(-1px);
    border-color: var(--acc);
}

.tag-btn.selected {
    background: var(--acc);
    border-color: var(--acc);
    color: white;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
}

.btn-delete-tags:hover:not(:disabled) {
    background: #b91c1c !important;
    transform: translateY(-1px);
}

.btn-delete-tags:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-generate {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #fff;
    border: none;
    box-shadow: 0 1px 3px rgba(124, 58, 237, 0.3);
}
.btn-generate:hover:not(:disabled) {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
}
.btn-generate:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}
/* Tag button styles - ADD THIS */
.tag-btn {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .2s ease;
    border: 1.5px solid var(--bdr);
    background: white;
    color: var(--txt);
    user-select: none;
    margin: 3px;
}

.tag-btn:hover {
    background: var(--bg);
    transform: translateY(-1px);
    border-color: var(--acc);
}

.tag-btn.selected {
    background: var(--acc);
    border-color: var(--acc);
    color: white;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.3);
}

.btn-delete-tags {
    background: #dc2626 !important;
    color: #fff !important;
}
.btn-delete-tags:hover:not(:disabled) {
    background: #b91c1c !important;
    transform: translateY(-1px);
}
.btn-delete-tags:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-clear-selection {
    background: #6b7280 !important;
    color: #fff !important;
}
.btn-clear-selection:hover:not(:disabled) {
    background: #4b5563 !important;
}

.btn-generate {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: #fff;
    border: none;
}
.btn-generate:hover:not(:disabled) {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    transform: translateY(-1px);
}

.tag-buttons-container {
    background: var(--bg);
    border: 1px solid var(--bdr);
    border-radius: 8px;
    padding: 10px;
    min-height: 80px;
    margin-bottom: 8px;
}

.tag-buttons-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.tag-action-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
    align-items: center;
}


 
</style>
</head>
<body>

<div class="page-header">
    <h1>🎬 Media Review — Verify, Tag &amp; Capture Thumbnails</h1>
    <div class="stats-grid">
        <div></div>
        <div class="stat-hdr c-total">TOTAL</div>
        <div class="stat-hdr c-verified">✅ VERIFIED</div>
        <div class="stat-hdr c-unverified">🕐 UNVERIFIED</div>
        <div class="stat-hdr c-untagged">⚠️ NEEDS TAGS</div>
        <div class="stat-hdr c-notindb">📷 NO THUMB</div>
        <div class="stats-row-label">🖼️ Images</div>
        <div class="stat-cell c-total"     ><strong id="si-total">—</strong><span>files</span></div>
        <div class="stat-cell c-verified"  ><strong id="si-ver">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-unverified"><strong id="si-unv">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-untagged"  ><strong id="si-unt">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-notindb"   ><strong id="si-ndb">—</strong><span>&nbsp;</span></div>
        <div class="stats-row-label">🎬 Videos</div>
        <div class="stat-cell c-total"     ><strong id="sv-total">—</strong><span>files</span></div>
        <div class="stat-cell c-verified"  ><strong id="sv-ver">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-unverified"><strong id="sv-unv">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-untagged"  ><strong id="sv-unt">—</strong><span>&nbsp;</span></div>
        <div class="stat-cell c-notindb"   ><strong id="sv-ndb">—</strong><span>&nbsp;</span></div>
    </div>
    <div id="dbStatsBar" style="margin-top:12px;padding:8px 12px;background:#f0f6ff;border:1px solid var(--bdr);border-radius:8px;font-size:12px;color:var(--mut);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <span id="dbStatsText">📊 Loading DB totals…</span>
        <div style="display:flex;gap:8px;">
            <button id="btnUpdateNiches" onclick="startBatchNiches()" style="padding:5px 14px;border-radius:7px;background:#0ea5e9;color:#fff;border:none;font-size:11px;font-weight:700;cursor:pointer;">🎯 Update Niches</button>
            <button id="btnStopNiches" onclick="stopBatchNiches()" style="padding:5px 14px;border-radius:7px;background:#dc2626;color:#fff;border:none;font-size:11px;font-weight:700;cursor:pointer;display:none;">⏹ Stop</button>
            <button onclick="scanAndShowOrphans(this)" style="padding:5px 14px;border-radius:7px;background:#f97316;color:#fff;border:none;font-size:11px;font-weight:700;cursor:pointer;">🗂️ Scan Orphans</button>
        </div>
    </div>
</div>

<div class="tabs">
    <button class="tab-btn active" id="tabImage" onclick="switchTab('image',this)">🖼️ Images</button>
    <button class="tab-btn" id="tabVideo" onclick="switchTab('video',this)">🎬 Videos</button>
    <button class="tab-btn" id="tabSearch" onclick="(function(btn){document.querySelectorAll('.tab-btn').forEach(function(b){b.classList.remove('active')});btn.classList.add('active');document.getElementById('filterBar').style.cssText='display:none';document.getElementById('grid').style.cssText='display:none';document.getElementById('searchPanel').removeAttribute('style');document.getElementById('searchPanel').style.cssText='display:block;margin-top:10px';})(this)">🔍 AI Search</button>
</div>

<div class="filter-bar" id="filterBar">
    <button class="filter-btn active" onclick="setFilter('all',this)">All</button>
    <button class="filter-btn" onclick="setFilter('verified',this)">✅ Verified</button>
    <button class="filter-btn" onclick="setFilter('unverified',this)">🕐 Unverified</button>
    <button class="filter-btn" onclick="setFilter('untagged',this)">⚠️ Needs Tagging</button>
    <button class="filter-btn" onclick="setFilter('not-in-db',this)">❌ Not in DB</button>
    <button class="filter-btn" onclick="setFilter('no-thumb',this)">📷 No Thumbnail</button>
	<button class="filter-btn" onclick="setFilter('mine',this)">👤 Mine</button>
    <select class="format-sel" id="formatSel" onchange="currentPage=1;loadPage(1)">
        <option value="">All Formats</option>
        <option value="9x16">9×16 Portrait</option>
        <option value="16x9">16×9 Landscape</option>
    </select>
    <input class="search-in" id="searchIn" placeholder="🔍 Search filename…" oninput="clearTimeout(window._srchTimer);window._srchTimer=setTimeout(function(){currentPage=1;loadPage(1);},400)">
    <span class="filter-count" id="filterCount"></span>
</div>

<div id="searchPanel" style="display:none;margin-top:10px;">
    <div style="background:linear-gradient(135deg,#f5f3ff,#ede9fe);border:1px solid #c4b5fd;border-radius:10px;padding:16px 18px;margin-bottom:12px;">
        <div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:8px;">
            <textarea id="searchQuery" rows="2"
                placeholder="Describe what you&#39;re looking for... e.g. girl walking in park | business meeting | sunset beach"
                style="flex:1;min-width:250px;padding:10px 14px;border-radius:10px;border:1.5px solid #c4b5fd;font-size:14px;resize:vertical;font-family:inherit;outline:none;"></textarea>
            <button onclick="performSearch()" id="searchBtn"
                style="background:#7c3aed;color:#fff;border:none;padding:12px 28px;border-radius:10px;font-weight:700;font-size:15px;cursor:pointer;white-space:nowrap;flex-shrink:0;">
                &#128269; SEARCH
            </button>
        </div>
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:#6d28d9;cursor:pointer;">
            <input type="checkbox" id="searchIncludeMine" style="width:16px;height:16px;"> Include my uploads
        </label>
    </div>
    <div id="searchStats" style="font-size:13px;color:#6d28d9;padding:4px 2px;margin-bottom:10px;"></div>
    <div id="searchGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">
        <div class="empty"><span>&#128269;</span>Enter a description above and click SEARCH</div>
    </div>
</div>

<div id="grid"><div class="empty"><span>⏳</span>Loading…</div></div>
<div id="paginationBar" style="display:none;"></div>

<div id="batchBanner">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:10px;flex-wrap:wrap;">
        <span style="font-weight:700;font-size:14px;color:var(--acc)">⚙️ Auto-processing new files…</span>
        <div style="display:flex;align-items:center;gap:8px;">
            <span id="batchCount" style="font-size:12px;color:var(--mut)">0 / 0</span>
            <button id="batchPauseBtn" onclick="toggleBatchPause()"
                style="padding:4px 14px;border-radius:20px;border:1.5px solid var(--amber);background:var(--amber);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;">
                ⏸ Pause
            </button>
        </div>
    </div>
    <div style="background:var(--bg);border-radius:20px;height:10px;overflow:hidden;border:1px solid var(--bdr)">
        <div id="batchBar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--acc),var(--grn));border-radius:20px;transition:width .3s"></div>
    </div>
    <div id="batchLog" style="margin-top:8px;font-size:12px;color:var(--mut);max-height:180px;overflow-y:auto;line-height:1.7;word-break:break-word;white-space:pre-wrap;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;padding:8px 10px"></div>
    <div id="batchDone" style="display:none;margin-top:8px;font-size:13px;font-weight:600;color:var(--grn)">✅ All files processed.</div>
</div>

<!-- ══ ORPHAN BANNER ══ -->
<div id="orphanBanner" style="display:none;background:#fff8f0;border:1px solid #fed7aa;border-radius:var(--r);padding:14px 18px;margin-bottom:12px;">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
    <span style="font-weight:700;font-size:14px;color:#c2410c;">🗂️ Orphaned Records — files missing from disk</span>
    <div style="display:flex;gap:8px;align-items:center;">
      <span id="orphanCount" style="font-size:12px;color:#92400e;"></span>
      <button id="btnDeleteOrphans" onclick="doDeleteOrphans()" style="padding:5px 14px;border-radius:8px;background:#dc2626;color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">🗑️ Delete All Orphans</button>
      <button onclick="document.getElementById('orphanBanner').style.display='none'" style="padding:5px 10px;border-radius:8px;background:transparent;border:1px solid #fed7aa;font-size:12px;cursor:pointer;color:#92400e;">✕ Dismiss</button>
    </div>
  </div>
  <div id="orphanList" style="font-size:12px;color:#92400e;max-height:120px;overflow-y:auto;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 10px;line-height:1.9;word-break:break-all;"></div>
</div>

<!-- ══ NICHES BATCH BANNER ══ -->
<div id="nichesBatchBanner" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:var(--r);padding:14px 18px;margin-bottom:12px;">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
    <span style="font-weight:700;font-size:14px;color:#0369a1;">🎯 Updating niches…</span>
    <span id="nichesBatchCount" style="font-size:12px;color:var(--mut);">0 / ?</span>
  </div>
  <div style="background:var(--bg);border-radius:20px;height:8px;overflow:hidden;border:1px solid var(--bdr);">
    <div id="nichesBatchBar" style="height:100%;width:0%;background:linear-gradient(90deg,#0ea5e9,#0284c7);border-radius:20px;transition:width .3s;"></div>
  </div>
  <div id="nichesBatchLog" style="margin-top:8px;font-size:12px;color:var(--mut);max-height:140px;overflow-y:auto;line-height:1.7;word-break:break-word;white-space:pre-wrap;background:var(--bg);border:1px solid var(--bdr);border-radius:6px;padding:8px 10px;"></div>
  <div id="nichesBatchDone" style="display:none;margin-top:6px;font-size:13px;font-weight:600;color:#0369a1;"></div>
</div>

<!-- ══ DELETE CONFIRM ══ -->
<div id="confirmOvl"></div>
<div class="confirm-box" id="confirmBox" style="display:none">
    <h3>⚠️ Delete File?</h3>
    <p>Permanently delete <strong id="delName" style="color:var(--txt)"></strong> from the folder and database?</p>
    <div class="btn-row">
        <button class="btn btn-ghost"  onclick="hideConfirm()">Cancel</button>
        <button class="btn btn-delete" onclick="doDelete()">Delete Permanently</button>
    </div>
</div>

<!-- ══ CAPTURE MODAL ══ -->
<div id="captureModal">
  <div class="capture-panel">
    <div class="capture-header">
      <h3>📷 Capture Thumbnail — <span id="captureFileName" style="font-weight:400;font-size:13px;color:var(--mut)"></span></h3>
      <button onclick="closeCapture()">✕</button>
    </div>
    <div class="capture-body">
      <div class="capture-left">
        <video id="captureVideo" controls muted playsinline></video>
        <canvas id="captureCanvas"></canvas>
        <div class="scrub-wrap" id="scrubWrap" style="display:none">
          <label>Scrub to frame: <span id="scrubTime">0.0s</span></label>
          <input type="range" id="scrubber" min="0" max="100" value="0" step="0.1" oninput="scrubVideo(this.value)">
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">
          <button class="btn btn-capture" onclick="captureFrame()">📷 Capture Frame</button>
          <button class="btn btn-ghost"   onclick="recaptureFrame()" id="btnRecapture" style="display:none">🔄 Re-capture</button>
        </div>
      </div>
      <div class="capture-right">
        <div class="capture-preview-label">Captured Thumbnail</div>
        <div id="capturePreviewNone">No frame captured yet. Scrub the video and click Capture Frame.</div>
        <img id="capturePreviewImg" src="" alt="Captured thumbnail">
        <div id="capturePreviewSize" style="font-size:11px;color:var(--mut);margin-top:4px;"></div>
      </div>
    </div>
    <div class="capture-footer">
      <span id="captureStatus" style="font-size:12px;color:var(--mut);flex:1;"></span>
      <button class="btn btn-ghost"   onclick="closeCapture()">Cancel</button>
      <button class="btn btn-capture" id="btnSaveThumb" onclick="saveThumbnail()" disabled>💾 Save Thumbnail</button>
    </div>
  </div>
</div>

<!-- ══ LIGHTBOX ══ -->
<div id="lightbox">
  <div class="lb-inner">
    <div class="lb-header">
        <h2 id="lbTitle">filename.jpg</h2>
        <button class="lb-close" onclick="closeLightbox()">✕</button>
    </div>
    <div class="lb-body">
        <div class="lb-media">
          <div id="lbPreview" style="flex:1;width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;"></div>
          <div class="lb-thumb-strip">
            <div class="lb-thumb-strip-label">📷 Thumbnail</div>
            <div id="lbThumbContent"><div class="lb-thumb-none">No thumbnail captured yet</div></div>
          </div>
        </div>
        <div class="lb-info">
            <div class="info-row">
                <div class="info-label">Status</div>
                <div><span class="status-badge" id="lbStatusBadge">—</span></div>
            </div>
            <div class="info-row" id="notInDbMsg" style="display:none">
                <div class="info-val" style="border-color:var(--red);background:rgba(220,38,38,.05);color:var(--red);font-size:14px">
                    ❌ This file is <strong>not in the database</strong>. Click <strong>Add to DB</strong> to insert, generate NL tags, embedding and mark as verified.
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Thumbnail</div>
                <div class="info-val" id="lbThumbnailStatus">—</div>
            </div>

            <div class="info-row">
                <div class="info-label">
                    Format
                    <span class="edit-hint">✏️ editable</span>
                </div>
                <select class="info-select" id="lbFormatSelect">
                    <option value="">— Select format —</option>
                    <option value="9x16">9×16 Portrait</option>
                    <option value="16x9">16×9 Landscape</option>
                </select>
            </div>

            <div class="info-row">
					<div class="info-label">
						Natural Language Tags
						<span class="edit-hint">✏️ Click tags to select, then Delete Selected</span>
					</div>
					
					<!-- Tag Buttons Container -->
					<div class="tag-buttons-container">
						<div class="tag-buttons-list" id="tagButtonsList">
							<span style="color:var(--mut);font-size:12px;">No tags loaded</span>
						</div>
					</div>
					
					<!-- Tag Action Bar -->
					<div class="tag-action-bar">
						<button type="button" class="btn btn-delete-tags" id="btnDeleteSelectedTags" onclick="deleteSelectedTags()" style="padding:6px 12px;font-size:11px;" disabled>🗑️ Delete Selected Tags</button>
						<button type="button" class="btn btn-delete-global" id="btnDeleteGlobalTags" onclick="deleteTagsGlobally()" style="padding:6px 12px;font-size:11px;background:#7c3aed;color:#fff;border:none;border-radius:6px;cursor:pointer;" disabled>🌐 Delete Globally</button>
						<button type="button" class="btn btn-clear-selection" id="btnClearSelection" onclick="clearTagSelection()" style="padding:6px 12px;font-size:11px;display:none;">✕ Clear Selection</button>
						<span id="selectedCount" style="font-size:11px;color:var(--mut);"></span>
					</div>
					
					<!-- Textarea (kept for editing) -->
					<textarea class="info-textarea" id="lbNlTagsEdit" rows="3" placeholder="tag one|tag two|tag three…" style="font-family:monospace;font-size:11px;"></textarea>
				</div>

            <div class="info-row">
                <div class="info-label">
                    Embedding
                    <span class="edit-hint">✏️ editable — JSON array</span>
                </div>
                <textarea class="info-textarea embedding-ta" id="lbEmbeddingEdit" rows="3" placeholder="[0.123, -0.456, …]"></textarea>
            </div>

            <div class="info-row">
                <div class="info-label">AI Matching</div>
                <label style="display:flex;align-items:center;gap:10px;padding:10px 13px;background:var(--bg);border:1px solid var(--bdr);border-radius:8px;cursor:pointer;user-select:none;">
                    <input type="checkbox" id="lbSkipEmbedding"
                        style="width:16px;height:16px;accent-color:var(--red);cursor:pointer;flex-shrink:0;">
                    <span style="font-size:13px;color:var(--txt);">
                        <strong style="color:var(--red);">Exclude from AI matching</strong>
                        <span style="display:block;font-size:11px;color:var(--mut);margin-top:2px;">
                            Embedding will be cleared and not regenerated. Image stays in DB for manual selection only.
                        </span>
                    </span>
                </label>
            </div>

            <div class="info-row">
                <div class="info-label">DB ID</div>
                <div class="info-val" id="lbDbId">—</div>
            </div>

            <div class="info-row">
                <div class="info-label">Niches <span style="font-size:10px;color:var(--mut);font-style:italic;margin-left:auto;">✏️ editable</span></div>
                <div id="nichesPillsDisplay" style="display:flex;flex-wrap:wrap;gap:4px;min-height:22px;margin-bottom:5px;"></div>
                <input type="text" id="lbNichesEdit"
                    style="width:100%;padding:7px 12px;border:1px solid var(--bdr);border-radius:8px;font-size:12px;background:var(--bg);color:var(--txt);outline:none;"
                    placeholder="finance,insurance,real estate…">
                <div style="display:flex;gap:6px;margin-top:6px;align-items:center;flex-wrap:wrap;">
                    <button type="button" id="btnGenerateNiches" onclick="doGenerateNiches()"
                        style="padding:7px 16px;border-radius:8px;background:linear-gradient(135deg,#0ea5e9,#0284c7);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">
                        🎯 Generate Niches
                    </button>
                    <button type="button" id="btnSaveNiches" onclick="doSaveNiches()"
                        style="padding:7px 14px;border-radius:8px;background:#0e7490;color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">
                        💾 Save Niches
                    </button>
                    <span id="nichesStatus" style="font-size:12px;color:var(--mut);"></span>
                </div>
            </div>
        </div>
    </div>
		<div class="lb-footer">
			<div class="lb-status" id="lbStatus"></div>
			<div style="display:flex;gap:8px;flex-wrap:wrap">
				<button class="btn btn-update" id="btnUpdate" style="display:none" onclick="doUpdate()">💾 Save Changes</button>
				<button class="btn btn-generate" id="btnGenerateTags" style="display:none" onclick="generateTags()">✨ Generate Tags</button>
				<button class="btn btn-capture" id="btnCapture" onclick="openCapture(activeName, activeFolder)" style="display:none">📷 Capture Thumbnail</button>
				<button class="btn btn-add"    id="btnAdd"    style="display:none" onclick="doAdd()">➕ Add to DB</button>
				<button class="btn btn-verify" id="btnVerify" style="display:none" onclick="doVerify()">✅ Verify</button>
				<button class="btn btn-delete" onclick="showConfirm()">🗑️ Delete</button>
				<button class="btn btn-ghost"  onclick="closeLightbox()">Close</button>
			</div>
		</div>
  </div>
</div>

<script>
var allFiles     = [];
var activeFilter = 'all';
var activeTab    = 'image';
var activeName   = '';
var activeFolder = '';

// Capture state
var capturedDataUrl = null;
var captureFileName = '';
var captureFolder   = '';

// Search state
var searchResults = [];

function safeJSON(txt) {
    try { return JSON.parse(txt); }
    catch(e) { return { success: false, message: 'PHP error: ' + String(txt) }; }
}

var allFiles     = [];
var activeFilter = 'all';
var activeTab    = 'image';
var activeName   = '';
var activeFolder = '';
var currentPage  = 1;
var totalPages   = 1;
var totalFiles   = 0;
var currentAdminId = <?php echo (int)($_SESSION['admin_id'] ?? 0); ?>;

// Capture state
var capturedDataUrl = null;
var captureFileName = '';
var captureFolder   = '';

// Search state
var searchResults = [];

function safeJSON(txt) {
    try { return JSON.parse(txt); }
    catch(e) { return { success: false, message: 'PHP error: ' + String(txt) }; }
}

/* ── Build fetch URL from current filter state ── */
function buildFileUrl(page) {
    var params = new URLSearchParams({
        action:   'get_files',
        page:     page || currentPage,
        tab:      activeTab,
        filter:   activeFilter,
        search:   document.getElementById('searchIn').value.trim(),
        fmt:      document.getElementById('formatSel').value,
        admin_id: currentAdminId,
    });
    return '?' + params.toString();
}

/* ── Load one page from server ── */
function loadPage(page) {
    page = page || 1;
    currentPage = page;
    var grid = document.getElementById('grid');
    grid.innerHTML = '<div class="empty"><span>⏳</span>Loading page ' + page + '…</div>';
    document.getElementById('paginationBar').style.display = 'none';

    fetch(buildFileUrl(page))
        .then(function(r){ return r.text(); })
        .then(function(txt){
            var d = safeJSON(txt);
            if (!d.success) {
                grid.innerHTML = '<div class="empty"><span>❌</span>' + (d.message || 'Load failed') + '</div>';
                return;
            }
            allFiles    = d.files;
            totalPages  = d.total_pages || 1;
            totalFiles  = d.total       || 0;
            currentPage = d.page        || 1;
            document.getElementById('filterCount').textContent =
                totalFiles + ' file' + (totalFiles !== 1 ? 's' : '') +
                ' · page ' + currentPage + ' of ' + totalPages;
            renderGrid();
            renderPagination();
            // autoBatch disabled
        })
        .catch(function(e){
            grid.innerHTML = '<div class="empty"><span>❌</span>' + e.message + '</div>';
        });
}

/* ── Initial load: sync then load page 1 ── */
function loadFiles() {
    fetch('?action=sync_files')
        .then(function(r){ return r.json(); })
        .then(function(d){
            console.log('Sync:', d.message);
            updateStats();
            loadPage(1);
        })
        .catch(function(e){
            document.getElementById('grid').innerHTML =
                '<div class="empty"><span>❌</span>' + e.message + '</div>';
        });
}

// ── SEARCH FUNCTIONS ──
var isSearching = false;

async function performSearch() {
    // Prevent multiple simultaneous searches
    if (isSearching) return;
    
    var query = document.getElementById('searchQuery').value.trim();
    if (!query) {
        document.getElementById('searchGrid').innerHTML = '<div class="empty"><span>🔍</span>Enter a search query above</div>';
        document.getElementById('searchStats').innerHTML = '';
        return;
    }
    
    var includeMine = document.getElementById('searchIncludeMine').checked;
    var searchGrid = document.getElementById('searchGrid');
    var statsDiv = document.getElementById('searchStats');
    var searchBtn = document.getElementById('searchBtn');
    
    // Disable button and show loading
    isSearching = true;
    searchBtn.disabled = true;
    searchBtn.innerHTML = '<span class="spinner"></span> Searching...';
    
    searchGrid.innerHTML = '<div class="empty"><span>⏳</span>Converting "' + esc(query) + '" to embedding and finding matches...</div>';
    statsDiv.innerHTML = '<span class="spinner"></span> Getting embedding from OpenAI...';
    
    var fd = new FormData();
    fd.append('query', query);
    fd.append('include_mine', includeMine ? '1' : '0');
    
    try {
        var response = await fetch('?action=search_assets', { method: 'POST', body: fd });
        var text = await response.text();
        
        // Debug: log raw response
        console.log('Search response received, length:', text.length);
        
        var data;
        try { 
            data = JSON.parse(text); 
        } catch(e) { 
            console.error('Parse error:', e);
            console.error('Raw response (first 500 chars):', text.substring(0, 500));
            data = { success: false, message: 'Parse error: ' + e.message }; 
        }
        
        if (!data.success) {
            searchGrid.innerHTML = '<div class="empty"><span>❌</span>' + esc(data.message || 'Search failed') + '</div>';
            statsDiv.innerHTML = '';
            return;
        }
        
        searchResults = data.results || [];
        
        if (searchResults.length === 0) {
            searchGrid.innerHTML = '<div class="empty"><span>🔍</span>No matching assets found for: "' + esc(query) + '"<br><br>' +
                '<small style="color:#999;">📊 ' + (data.total_assets || 0) + ' assets checked<br>' +
                '💡 Try different words or check if assets have embeddings</small></div>';
            statsDiv.innerHTML = '❌ No matches found';
            return;
        }
        
        // Show results
        var bestScore = searchResults[0].score_pct;
        statsDiv.innerHTML = '✅ Found <strong>' + searchResults.length + '</strong> matches (out of ' + (data.total_assets || 0) + ' assets) | Best match: <strong>' + bestScore + '%</strong>';
        
        renderSearchResults();
        
    } catch(e) {
        console.error('Search error:', e);
        searchGrid.innerHTML = '<div class="empty"><span>❌</span>Error: ' + esc(e.message) + '</div>';
        statsDiv.innerHTML = '';
    } finally {
        // Re-enable button
        isSearching = false;
        searchBtn.disabled = false;
        searchBtn.innerHTML = '🔍 Search';
    }
}

function renderSearchResults() {
    var grid = document.getElementById('searchGrid');
    if (!searchResults.length) {
        grid.innerHTML = '<div class="empty"><span>🔍</span>No results</div>';
        return;
    }
    
    grid.innerHTML = searchResults.map(function(r, idx) {
        var score = r.score_pct || (r.score * 100).toFixed(1);
        var scoreClass = score >= 65 ? 'score-high' : (score >= 45 ? 'score-mid' : 'score-low');
        var barColor = score >= 65 ? '#1a7a3a' : (score >= 45 ? '#b87a00' : '#cc3333');
        var isVid = r.media_type === 'video';
        var src = isVid ? 'podcast_videos/' + r.image_name : 'podcast_images/' + r.image_name;
        var thumbUrl = r.thumbnail ? 'podcast_thumbnails/' + r.thumbnail : src;
        
        // Best match gets special styling
        var bestRowClass = idx === 0 ? 'style="border:2px solid #1a7a3a;background:#f0fdf4;"' : '';
        var bestBadge = idx === 0 ? '<div style="position:absolute;top:6px;right:6px;background:#1a7a3a;color:#fff;padding:2px 8px;border-radius:10px;font-size:9px;font-weight:700;">🏆 BEST</div>' : '';
        
        return '<div class="card" onclick="openLightboxFromSearch(\'' + esc(r.image_name) + '\')" ' + bestRowClass + '>' +
            '<div class="status-pill" style="background:rgba(124,58,237,.15);color:#6d28d9;border-color:#c4b5fd;">🎯 ' + score + '% match</div>' +
            bestBadge +
            '<div class="card-thumb">' +
                (isVid ? 
                    '<video src="' + src + '" muted preload="metadata" style="width:100%;height:100%;object-fit:cover;"></video><div class="vid-badge">▶️</div>' :
                    '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.src=\'' + src + '\'">') +
            '</div>' +
            '<div class="card-label" title="' + esc(r.image_name) + '">' + esc(r.image_name.substring(0, 30)) + '</div>' +
            '<div style="padding:2px 6px 4px;font-size:10px;background:var(--bg);border-top:1px solid var(--bdr);">' +
                '<div class="score-bar" style="background:#eee;height:4px;border-radius:2px;"><div class="score-fill" style="width:' + Math.min(100, score) + '%;background:' + barColor + ';height:4px;border-radius:2px;"></div></div>' +
            '</div>' +
        '</div>';
    }).join('');
}





function openLightboxFromSearch(name) {
    activeName = name;
    var fo = allFiles.find(function(f) { return f.name === name; });
    activeFolder = fo ? fo.folder : '';
    
    if (!fo) {
        var ext = name.split('.').pop().toLowerCase();
        var isVid = ['mp4','webm','mov'].includes(ext);
        fo = {
            name: name,
            kind: isVid ? 'video' : 'image',
            folder: isVid ? 'podcast_videos/' : 'podcast_images/',
            in_db: true,
            format: '',
            thumbnail: '',
            status: ''
        };
        allFiles.push(fo);
    }
    
    openLightbox(name);
}

function captureVideoThumbnailForBatch(name, folder, callback) {
    var src = (folder || 'podcast_videos/') + encodeURIComponent(name);
    var vid = document.createElement('video');
    vid.muted   = true;
    vid.preload = 'metadata';
    vid.crossOrigin = 'anonymous';

    var done = false;
    var timer = setTimeout(function() {
        if (!done) { done = true; vid.src = ''; callback(false); }
    }, 20000);

    vid.onloadedmetadata = function() {
        vid.currentTime = Math.max(0.5, (vid.duration || 10) * 0.1);
    };

    vid.onseeked = function() {
        if (done) return;
        clearTimeout(timer);
        done = true;
        var canvas = document.createElement('canvas');
        var w = vid.videoWidth || 640;
        var h = vid.videoHeight || 360;
        var ratio = Math.min(320/w, 320/h, 1);
        canvas.width  = Math.round(w * ratio);
        canvas.height = Math.round(h * ratio);
        var ctx = canvas.getContext('2d');
        ctx.drawImage(vid, 0, 0, canvas.width, canvas.height);
        var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
        vid.src = '';

        var fd = new FormData();
        fd.append('name',       name);
        fd.append('image_data', dataUrl);
        fetch('?action=save_video_thumbnail', { method:'POST', body:fd })
            .then(function(r){ return r.json(); })
            .then(function(d){ callback(d.success || false); })
            .catch(function()  { callback(false); });
    };

    vid.onerror = function() {
        if (!done) { done = true; clearTimeout(timer); vid.src = ''; callback(false); }
    };

    vid.src = src;
}

var batchPaused = false;

function toggleBatchPause() {
    batchPaused = !batchPaused;
    var btn = document.getElementById('batchPauseBtn');
    if (!btn) return;
    if (batchPaused) {
        btn.textContent      = '▶ Resume';
        btn.style.background = 'var(--grn)';
        btn.style.borderColor= 'var(--grn)';
    } else {
        btn.textContent      = '⏸ Pause';
        btn.style.background = 'var(--amber)';
        btn.style.borderColor= 'var(--amber)';
    }
}

function autoBatch() {
    var queue = allFiles.filter(function(f){
        return !f.in_db || !f.has_nl || !f.has_emb;
    });
    if (queue.length === 0) return;

    batchPaused = false;
    var btn = document.getElementById('batchPauseBtn');
    if (btn) {
        btn.textContent      = '⏸ Pause';
        btn.style.background = 'var(--amber)';
        btn.style.borderColor= 'var(--amber)';
        btn.style.display    = 'inline-block';
    }

    var banner  = document.getElementById('batchBanner');
    var bar     = document.getElementById('batchBar');
    var countEl = document.getElementById('batchCount');
    var logEl   = document.getElementById('batchLog');
    var doneEl  = document.getElementById('batchDone');
    var total   = queue.length;
    var done    = 0;

    banner.style.display = 'block';
    doneEl.style.display = 'none';
    countEl.textContent  = '0 / ' + total;
    bar.style.width      = '0%';
    logEl.innerHTML      = '';

    function processNext() {
        if (batchPaused) {
            setTimeout(processNext, 300);
            return;
        }
        if (done >= total) {
            bar.style.width      = '100%';
            doneEl.style.display = 'block';
            countEl.textContent  = total + ' / ' + total;
            batchPaused = false;
            var btn = document.getElementById('batchPauseBtn');
            if (btn) btn.style.display = 'none';
            setTimeout(function(){
                updateStats();
                loadPage(currentPage);
            }, 800);
            return;
        }
        var f  = queue[done];
        var fd = new FormData();
        fd.append('name',   f.name);
        fd.append('folder', f.folder);
        var logLine = document.createElement('div');
        logLine.textContent = '⏳ ' + f.name;
        logEl.appendChild(logLine);
        logEl.scrollTop = logEl.scrollHeight;
        fetch('?action=batch_process', { method:'POST', body:fd })
            .then(function(r){ return r.text(); })
            .then(function(txt){
                var d; try { d = JSON.parse(txt); } catch(e2) { d = { success: false, message: 'PHP error: ' + txt }; }
                if (d.success && d.needs_thumb) {
                    logLine.textContent = '🎬 ' + f.name + ' — capturing thumbnail…';
                    logEl.scrollTop = logEl.scrollHeight;
                    captureVideoThumbnailForBatch(f.name, f.folder, function(thumbOk) {
                        done++;
                        bar.style.width     = Math.round((done/total)*100) + '%';
                        countEl.textContent = done + ' / ' + total;
                        logLine.textContent = (thumbOk ? '✅ ' : '⚠️ ') + f.name + ' — ' + (thumbOk ? d.message + ' | Thumbnail captured' : d.message + ' | Thumbnail failed');
                        logEl.scrollTop = logEl.scrollHeight;
                        var lf = allFiles.find(function(x){ return x.name === f.name; });
                        if (lf && d.success) { lf.in_db=true; if(d.db_id) lf.db_id=d.db_id; lf.has_nl=thumbOk; lf.has_emb=thumbOk; }
                        updateStats();
                        setTimeout(processNext, 400);
                    });
                } else {
                    done++;
                    bar.style.width     = Math.round((done/total)*100) + '%';
                    countEl.textContent = done + ' / ' + total;
                    logLine.textContent = (d.success ? '✅ ' : '❌ ') + f.name + ' — ' + (d.message || '');
                    logEl.scrollTop = logEl.scrollHeight;
                    var lf = allFiles.find(function(x){ return x.name === f.name; });
                    if (lf && d.success) { lf.in_db=true; lf.has_nl=d.has_nl||false; lf.has_emb=d.has_emb||false; lf.has_thumb=d.has_thumb||false; if(d.db_id) lf.db_id=d.db_id; }
                    updateStats();
                    setTimeout(processNext, 400);
                }
            })
            .catch(function(e){
                done++;
                logLine.textContent = '❌ ' + f.name + ' — ' + e.message;
                logEl.scrollTop = logEl.scrollHeight;
                setTimeout(processNext, 400);
            });
    }
    processNext();
}

/* ── Stats ── */
function updateStats() {
    fetch('?action=get_db_stats').then(function(r){ return r.json(); }).then(function(d){
        if (!d.success) return;
        var img = d.img || {};
        var vid = d.vid || {};
        document.getElementById('si-total').textContent = img.total      || 0;
        document.getElementById('si-ver').textContent   = img.verified   || 0;
        document.getElementById('si-unv').textContent   = img.unverified || 0;
        document.getElementById('si-unt').textContent   = img.needs_tags || 0;
        document.getElementById('si-ndb').textContent   = img.no_thumb   || 0;
        document.getElementById('sv-total').textContent = vid.total      || 0;
        document.getElementById('sv-ver').textContent   = vid.verified   || 0;
        document.getElementById('sv-unv').textContent   = vid.unverified || 0;
        document.getElementById('sv-unt').textContent   = vid.needs_tags || 0;
        document.getElementById('sv-ndb').textContent   = vid.no_thumb   || 0;
        var el = document.getElementById('dbStatsText');
        if (el) {
            el.innerHTML = '📊 DB totals &nbsp;|&nbsp; '
                + '<strong>' + d.total + '</strong> total &nbsp;|&nbsp; '
                + '<strong style="color:var(--grn);">' + d.verified + '</strong> verified &nbsp;|&nbsp; '
                + '<strong style="color:#7c3aed;">' + d.unverified + '</strong> unverified &nbsp;|&nbsp; '
                + '<strong style="color:var(--org);">' + d.needs_tags + '</strong> need tags &nbsp;|&nbsp; '
                + '<strong style="color:var(--red);">' + d.no_thumb + '</strong> no thumbnail';
        }
    }).catch(function(){});
}

/* ── Tab ── */
function doSearchTab() {
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tabSearch').classList.add('active');
    document.getElementById('filterBar').style.cssText      = 'display:none';
    document.getElementById('grid').style.cssText           = 'display:none';
    document.getElementById('paginationBar').style.display  = 'none';
    document.getElementById('searchPanel').style.cssText    = 'display:block;margin-top:10px;';
}

function switchTab(tab, el) {
    activeTab    = tab;
    activeFilter = 'all';
    currentPage  = 1;
    document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
    el.classList.add('active');
    var isSearch = (tab === 'search');
    document.getElementById('filterBar').style.display          = isSearch ? 'none' : 'flex';
    document.getElementById('grid').style.display               = isSearch ? 'none' : 'grid';
    document.getElementById('paginationBar').style.display      = isSearch ? 'none' : 'none';
    document.getElementById('searchPanel').style.display        = isSearch ? 'block' : 'none';
    var bb = document.getElementById('batchBanner');
    if (bb) bb.style.display = 'none';
    if (!isSearch) {
        document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
        var first = document.querySelector('.filter-btn');
        if (first) first.classList.add('active');
        document.getElementById('formatSel').value = '';
        loadPage(1);
    }
}
// ========== TAG BUTTON FUNCTIONS ==========
var selectedTags = new Set();
var currentTagsArray = [];

function renderTagButtons(tagsString) {
    const container = document.getElementById('tagButtonsList');
    const deleteBtn = document.getElementById('btnDeleteSelectedTags');
    const clearBtn = document.getElementById('btnClearSelection');
    const selectedSpan = document.getElementById('selectedCount');
    
    if (!tagsString || tagsString.trim() === '') {
        container.innerHTML = '<span style="color:var(--mut);font-size:12px;">No tags available. Click "Generate Tags" to create them.</span>';
        if(deleteBtn) deleteBtn.disabled = true;
        if(clearBtn) clearBtn.style.display = 'none';
        if(selectedSpan) selectedSpan.textContent = '';
        currentTagsArray = [];
        return;
    }
    
    currentTagsArray = tagsString.split('|').map(t => t.trim()).filter(t => t.length > 0);
    
    if (currentTagsArray.length === 0) {
        container.innerHTML = '<span style="color:var(--mut);font-size:12px;">No tags available.</span>';
        if(deleteBtn) deleteBtn.disabled = true;
        if(clearBtn) clearBtn.style.display = 'none';
        if(selectedSpan) selectedSpan.textContent = '';
        return;
    }
    
    // Render buttons
    container.innerHTML = currentTagsArray.map(tag => 
        `<span class="tag-btn" data-tag="${escapeHtml(tag)}" onclick="toggleTagSelection(this, '${escapeHtml(tag)}')">${escapeHtml(tag)}</span>`
    ).join('');
    
    // Clear selection when tags change
    selectedTags.clear();
    updateTagSelectionUI();
}

function toggleTagSelection(element, tag) {
    if (selectedTags.has(tag)) {
        selectedTags.delete(tag);
        element.classList.remove('selected');
    } else {
        selectedTags.add(tag);
        element.classList.add('selected');
    }
    updateTagSelectionUI();
}

function updateTagSelectionUI() {
    const deleteBtn = document.getElementById('btnDeleteSelectedTags');
    const deleteGlobalBtn = document.getElementById('btnDeleteGlobalTags');
    const clearBtn = document.getElementById('btnClearSelection');
    const selectedSpan = document.getElementById('selectedCount');
    const count = selectedTags.size;
    
    if (count > 0) {
        if(deleteBtn) deleteBtn.disabled = false;
        if(deleteGlobalBtn) deleteGlobalBtn.disabled = false;
        if(clearBtn) clearBtn.style.display = 'inline-block';
        if(selectedSpan) selectedSpan.textContent = `${count} tag${count !== 1 ? 's' : ''} selected`;
    } else {
        if(deleteBtn) deleteBtn.disabled = true;
        if(deleteGlobalBtn) deleteGlobalBtn.disabled = true;
        if(clearBtn) clearBtn.style.display = 'none';
        if(selectedSpan) selectedSpan.textContent = '';
    }
}

async function deleteTagsGlobally() {
    if (selectedTags.size === 0) {
        setLbStatus('⚠️ No tags selected', 'err');
        return;
    }

    const tagList = Array.from(selectedTags);
    const tagPreview = tagList.map(t => `"${t}"`).join(', ');
    const confirmed = confirm(
        `🌐 DELETE GLOBALLY\n\nThis will remove the following tag(s) from EVERY image/video in the database that contains them:\n\n${tagPreview}\n\nEmbeddings will be regenerated for all affected rows.\nTags will be saved to uselesstags.txt.\n\nThis cannot be undone. Continue?`
    );
    if (!confirmed) return;

    const btn = document.getElementById('btnDeleteGlobalTags');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Working…';
    setLbStatus(`🌐 Deleting ${selectedTags.size} tag(s) globally — this may take a moment…`, '');

    const tagsToDelete = tagList.join('|');
    const fd = new FormData();
    fd.append('tags_to_delete', tagsToDelete);

    try {
        const response = await fetch('?action=delete_tags_globally', { method: 'POST', body: fd });
        const text = await response.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            data = { success: false, message: 'Parse error: ' + e.message };
        }

        btn.disabled = false;
        btn.innerHTML = originalText;

        if (data.success) {
            // Also apply the deletion to the current row's displayed tags\nlet currentTags = document.getElementById('lbNlTagsEdit').value;
            tagList.forEach(tag => {
                currentTags = currentTags.split('|').map(t => t.trim()).filter(t => t.toLowerCase() !== tag.toLowerCase()).join('|');
            });
            document.getElementById('lbNlTagsEdit').value = currentTags;
            _originalNlTags = currentTags;
            renderTagButtons(currentTags);
            selectedTags.clear();
            updateTagSelectionUI();

            // Build detail summary for the status message
            let detail = '';
            if (data.details && data.details.length > 0) {
                detail = ' — ' + data.details.map(d => `"${d.tag}": ${d.rows} row(s)`).join(', ');
            }
            setLbStatus(`✅ ${data.message}${detail}`, 'ok');
            updateStats();
            renderGrid();
        } else {
            setLbStatus(`❌ ${data.message}`, 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        setLbStatus(`❌ Error: ${e.message}`, 'err');
    }
}

function clearTagSelection() {
    selectedTags.clear();
    document.querySelectorAll('.tag-btn').forEach(btn => {
        btn.classList.remove('selected');
    });
    updateTagSelectionUI();
}

async function deleteSelectedTags() {
    if (selectedTags.size === 0) {
        setLbStatus('⚠️ No tags selected', 'err');
        return;
    }
    
    const btn = document.getElementById('btnDeleteSelectedTags');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Deleting...';
    setLbStatus(`🗑️ Deleting ${selectedTags.size} selected tags...`, '');
    
    const tagsToDelete = Array.from(selectedTags).join('|');
    const fd = new FormData();
    fd.append('name', activeName);
    fd.append('tags_to_delete', tagsToDelete);
    
    try {
        const response = await fetch('?action=delete_selected_tags', { method: 'POST', body: fd });
        const text = await response.text();
        
        let data;
        try { 
            data = JSON.parse(text); 
        } catch(e) { 
            console.error('Parse error:', text);
            data = { success: false, message: 'Parse error: ' + e.message }; 
        }
        
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            // Update textarea
            document.getElementById('lbNlTagsEdit').value = data.nl_tags;
            _originalNlTags = data.nl_tags;
            
            // Update embedding
            if (data.embedding) {
                document.getElementById('lbEmbeddingEdit').value = data.embedding;
            }
            
            // Re-render tag buttons
            renderTagButtons(data.nl_tags);
            
            // Clear selection
            selectedTags.clear();
            updateTagSelectionUI();
            
            // Update local file data
            const lf = allFiles.find(x => x.name === activeName);
            if (lf) {
                lf.has_nl = data.nl_tags ? true : false;
                lf.has_emb = data.embedding ? true : false;
            }
            
            setLbStatus(`✅ ${data.message}`, 'ok');
            updateStats();
            renderGrid();
        } else {
            setLbStatus(`❌ ${data.message}`, 'err');
        }
    } catch(e) {
        console.error('Delete tags error:', e);
        btn.disabled = false;
        btn.innerHTML = originalText;
        setLbStatus(`❌ Error: ${e.message}`, 'err');
    }
}

async function generateTags() {
    const btn = document.getElementById('btnGenerateTags');
    if (!btn) return;
    
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating...';
    setLbStatus('🧠 Generating NL tags and embedding from image/video...', '');
    
    const fd = new FormData();
    fd.append('name', activeName);
    fd.append('folder', activeFolder);
    fd.append('force', '1');
    
    try {
        const response = await fetch('?action=generate_tags', { method: 'POST', body: fd });
        const text = await response.text();
        
        let data;
        try { 
            data = JSON.parse(text); 
        } catch(e) { 
            data = { success: false, message: 'Parse error: ' + e.message }; 
        }
        
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            if (data.nl_tags) {
                document.getElementById('lbNlTagsEdit').value = data.nl_tags;
                _originalNlTags = data.nl_tags;
                // Render as buttons
                renderTagButtons(data.nl_tags);
            }
            if (data.embedding) {
                document.getElementById('lbEmbeddingEdit').value = data.embedding;
            }
            
            const lf = allFiles.find(x => x.name === activeName);
            if (lf) {
                lf.has_nl = true;
                lf.has_emb = true;
            }
            
            setLbStatus('✅ ' + (data.message || 'Tags generated and saved!'), 'ok');
            updateStats();
            renderGrid();
        } else {
            setLbStatus('❌ ' + (data.message || 'Generation failed'), 'err');
        }
    } catch(e) {
        console.error('Generate tags error:', e);
        btn.disabled = false;
        btn.innerHTML = originalText;
        setLbStatus('❌ Error: ' + e.message, 'err');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function getCS(f) {
    if (!f.in_db) return 'not-in-db';
    if (f.admin_id !== 0 && f.admin_id !== currentAdminId) return 'user-upload';
    if (!f.has_nl || !f.has_emb) return 'untagged';
    if (f.status === 'verified') return 'verified';
    return 'untagged';
}

/* ── Render current page ── */
function renderGrid() {
    var grid = document.getElementById('grid');
    if (!allFiles.length) {
        grid.innerHTML = '<div class="empty"><span>🔍</span>No files match.</div>';
        return;
    }
    grid.innerHTML = allFiles.map(function(f){
        var cs       = getCS(f);
        var src      = f.folder + encodeURIComponent(f.name);
        var pc       = cs === 'verified' ? 'pill-verified' : (cs === 'untagged' ? 'pill-untagged' : 'pill-not-in-db');
        var pt       = cs === 'verified' ? '✅ Verified'   : (cs === 'untagged' ? '⚠️ Needs tags'  : '❌ Not in DB');
        var fmtCls   = f.format === '16x9' ? ' fmt-16x9' : '';
        var fmtBadge = f.format ? '<div class="fmt-badge">' + esc(f.format) + '</div>' : '';
        var thumbBadge = f.thumbnail ? '<div class="thumb-indicator">📷 Thumb</div>' : '';
        var ownerBadge = '';
        if (f.admin_id === 0)
            ownerBadge = '<div class="owner-badge" style="position:absolute;top:6px;right:6px;background:rgba(22,163,74,.15);color:#15803d;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:600;">🌍 Public</div>';
        else if (f.admin_id === currentAdminId)
            ownerBadge = '<div class="owner-badge" style="position:absolute;top:6px;right:6px;background:rgba(124,58,237,.15);color:#6d28d9;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:600;">👤 Mine</div>';
        var isMissing = _missingFiles.has(f.name);
        var PLACEHOLDER = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="90"><rect width="160" height="90" fill="#fef2f2" rx="6"/><text x="80" y="38" text-anchor="middle" fill="#dc2626" font-size="18" font-family="sans-serif">&#9888;</text><text x="80" y="56" text-anchor="middle" fill="#dc2626" font-size="11" font-family="sans-serif">File missing</text></svg>');
        var OE = ' onerror="imgMissing(this)"';
        var thumb;
        if (isMissing) {
            thumb = '<img src="' + PLACEHOLDER + '" style="opacity:.5;">';
        } else if (f.kind === 'video') {
            thumb = f.thumbnail ? '<img src="podcast_thumbnails/' + encodeURIComponent(f.thumbnail) + '"' + OE + '>' : '<video src="' + src + '" muted preload="metadata"></video><div class="vid-badge">▶️</div>';
        } else {
            thumb = f.thumbnail ? '<img src="podcast_thumbnails/' + encodeURIComponent(f.thumbnail) + '"' + OE + '>' : '<img src="' + src + '" loading="lazy"' + OE + '>';
        }
        var sn = f.name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        return '<div class="card status-' + cs + '" onclick="openLightbox(\'' + sn + '\')">' +
               '<div class="status-pill ' + pc + '">' + pt + '</div>' +
               ownerBadge +
               '<div class="card-thumb' + fmtCls + '">' + thumb + fmtBadge + thumbBadge + '</div>' +
               '<div class="card-label" title="' + esc(f.name) + '">' + esc(f.name) + '</div>' +
               '</div>';
    }).join('');
}

/* ── Pagination bar ── */
function renderPagination() {
    var bar = document.getElementById('paginationBar');
    if (totalPages <= 1) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';
    var html = '';
    html += '<button class="pg-btn" onclick="loadPage(' + (currentPage-1) + ')" ' + (currentPage<=1?'disabled':'') + '>‹ Prev</button>';
    var start = Math.max(1, currentPage-3), end = Math.min(totalPages, currentPage+3);
    if (start > 1) { html += '<button class="pg-btn" onclick="loadPage(1)">1</button>'; if (start>2) html += '<span class="pg-info">…</span>'; }
    for (var p = start; p <= end; p++)
        html += '<button class="pg-btn' + (p===currentPage?' active':'') + '" onclick="loadPage(' + p + ')">' + p + '</button>';
    if (end < totalPages) { if (end<totalPages-1) html += '<span class="pg-info">…</span>'; html += '<button class="pg-btn" onclick="loadPage(' + totalPages + ')">' + totalPages + '</button>'; }
    html += '<button class="pg-btn" onclick="loadPage(' + (currentPage+1) + ')" ' + (currentPage>=totalPages?'disabled':'') + '>Next ›</button>';
    html += '<span class="pg-info">' + totalFiles + ' total · ' + totalPages + ' pages</span>';
    bar.innerHTML = html;
}

function setFilter(f, el) {
    activeFilter = f;
    currentPage  = 1;
    document.querySelectorAll('.filter-btn').forEach(function(b){ b.classList.remove('active'); });
    el.classList.add('active');
    loadPage(1);
}

/* ── Lightbox ── */


function openLightbox(name) {
    console.log('=== Opening lightbox for:', name);
    
    activeName = name;
    var fo = null;
    for (var i = 0; i < allFiles.length; i++) {
        if (allFiles[i].name === name) {
            fo = allFiles[i];
            break;
        }
    }
    activeFolder = fo ? fo.folder : '';

    document.getElementById('lightbox').style.display = 'block';
    document.getElementById('lbTitle').textContent = name;
    setLbStatus('', '');

    document.getElementById('lbFormatSelect').value = '';
    document.getElementById('lbNlTagsEdit').value = '';
    document.getElementById('lbEmbeddingEdit').value = '';
    document.getElementById('lbSkipEmbedding').checked = false;
    document.getElementById('lbNichesEdit').value = '';
    if(document.getElementById('nichesPillsDisplay')) renderNichesPills('');
    if(document.getElementById('nichesStatus')) document.getElementById('nichesStatus').textContent = '';

    var fmt = fo ? fo.format : '';
    var isVid = fo && fo.kind === 'video';
    var src = (fo ? fo.folder : '') + encodeURIComponent(name);
    var prev = document.getElementById('lbPreview');
    prev.className = '';
    prev.innerHTML = isVid
        ? '<video src="' + src + '" controls autoplay muted loop style="width:100%;height:100%;object-fit:contain;"></video>'
        : '<img src="' + src + '?v=' + Date.now() + '" style="width:100%;height:100%;object-fit:contain;">';

    var thumbContent = document.getElementById('lbThumbContent');
    if (fo && fo.thumbnail) {
        thumbContent.innerHTML = '<img class="lb-thumb-img" src="podcast_thumbnails/' + encodeURIComponent(fo.thumbnail) + '?v=' + Date.now() + '" alt="thumbnail">';
    } else {
        thumbContent.innerHTML = '<div class="lb-thumb-none">No thumbnail — click Capture Thumbnail below</div>';
    }

    fetch('?action=get_detail&name=' + encodeURIComponent(name))
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            var row = d.row;
            var inDb = !!row;
            
            console.log('File in DB:', inDb, 'Row data:', row);
            
            var sb = document.getElementById('lbStatusBadge');
            if (!inDb) {
                sb.className = 'status-badge sb-not-in-db';
                sb.textContent = '❌ Not in database';
            } else if (row.status === 'verified') {
                sb.className = 'status-badge sb-verified';
                sb.textContent = '✅ Verified';
            } else {
                sb.className = 'status-badge sb-untagged';
                sb.textContent = '⚠️ In DB — not verified';
            }

            document.getElementById('notInDbMsg').style.display = inDb ? 'none' : 'flex';

            var fmtVal = (inDb && row.media_type_format) ? row.media_type_format : (fo && fo.format ? fo.format : '');
            document.getElementById('lbFormatSelect').value = fmtVal;

            var nlVal = inDb && row.natural_language_tags ? row.natural_language_tags : '';
            document.getElementById('lbNlTagsEdit').value = nlVal;
			renderTagButtons(nlVal);
			
            document.getElementById('lbNlTagsEdit').placeholder = inDb ? 'tag one|tag two|tag three…' : 'Add to DB first to edit tags';
            document.getElementById('lbNlTagsEdit').disabled = !inDb;
            _originalNlTags = nlVal;

            var embDisplay = '';
            if (inDb && row.embedding) {
                try {
                    var v = JSON.parse(row.embedding);
                    embDisplay = row.embedding;
                } catch(e2) { embDisplay = row.embedding; }
            }
            document.getElementById('lbEmbeddingEdit').value = embDisplay;
            document.getElementById('lbEmbeddingEdit').placeholder = inDb ? '[0.123, -0.456, …] JSON array' : 'Add to DB first to edit embedding';
            document.getElementById('lbEmbeddingEdit').disabled = !inDb;
            document.getElementById('lbFormatSelect').disabled = !inDb;
            
            var skipEl = document.getElementById('lbSkipEmbedding');
            skipEl.checked = inDb && parseInt(row.skip_embedding || 0) === 1;
            skipEl.disabled = !inDb;

            document.getElementById('lbDbId').textContent = inDb ? ('#' + row.id) : '—';
            var _nv = (inDb && row.niches) ? row.niches : '';
            document.getElementById('lbNichesEdit').value = _nv;
            document.getElementById('lbNichesEdit').disabled = !inDb;
            renderNichesPills(_nv);
            document.getElementById('nichesStatus').textContent = '';
            document.getElementById('btnGenerateNiches').disabled = !inDb;
            document.getElementById('btnSaveNiches').style.display = inDb ? '' : 'none';

            var hasThumb = inDb && row.thumbnail && row.thumbnail !== '';
            document.getElementById('lbThumbnailStatus').innerHTML = hasThumb
                ? '✅ <strong>' + esc(row.thumbnail) + '</strong>'
                : '❌ No thumbnail — click <strong>Capture Thumbnail</strong>';

            if (hasThumb) {
                document.getElementById('lbThumbContent').innerHTML =
                    '<img class="lb-thumb-img" src="podcast_thumbnails/' + encodeURIComponent(row.thumbnail) + '?v=' + Date.now() + '" alt="thumbnail">';
                if (fo) fo.thumbnail = row.thumbnail;
            }

            var btnCapture = document.getElementById('btnCapture');
            btnCapture.style.display = 'inline-block';
            if (hasThumb) {
                btnCapture.disabled = true;
                btnCapture.title = 'Thumbnail already captured. Delete file to re-capture.';
                btnCapture.innerHTML = '📷 Thumbnail Captured';
            } else {
                btnCapture.disabled = false;
                btnCapture.title = '';
                btnCapture.innerHTML = '📷 Capture Thumbnail';
            }

            // ===== CRITICAL: Button visibility settings =====
            var btnUpdate = document.getElementById('btnUpdate');
            var btnGenerate = document.getElementById('btnGenerateTags');
            var btnAdd = document.getElementById('btnAdd');
            var btnVerify = document.getElementById('btnVerify');
            
            if (btnUpdate) btnUpdate.style.display = inDb ? 'inline-block' : 'none';
            if (btnGenerate) {
                btnGenerate.style.display = inDb ? 'inline-block' : 'none';
                console.log('Generate Tags button display set to:', btnGenerate.style.display, 'inDb:', inDb);
            } else {
                console.error('btnGenerateTags element not found in DOM!');
            }
            if (btnAdd) btnAdd.style.display = inDb ? 'none' : 'inline-block';
            
            if (btnVerify) {
                btnVerify.disabled = false;
                btnVerify.innerHTML = '✅ Verify';
                btnVerify.style.display = (!inDb || row.status === 'verified') ? 'none' : 'inline-block';
            }
            
            // Force a check
            if (btnGenerate && inDb) {
                console.log('Button should be visible now');
                btnGenerate.style.visibility = 'visible';
                btnGenerate.style.opacity = '1';
            }
        })
        .catch(function(e) {
            console.error('Error in openLightbox fetch:', e);
        });
}

function closeLightbox(){
    var v=document.querySelector('#lbPreview video');
    if(v){v.pause();v.src='';}
    document.getElementById('lbPreview').innerHTML='';
    document.getElementById('lightbox').style.display='none';
}

var _originalNlTags = '';

function doUpdate() {
    var btn    = document.getElementById('btnUpdate');
    var nlTags = document.getElementById('lbNlTagsEdit').value.trim();
    var format = document.getElementById('lbFormatSelect').value;

    var nlChanged = (nlTags !== _originalNlTags);
    var skipEmbChecked = document.getElementById('lbSkipEmbedding').checked;
    var actionLabel = skipEmbChecked ? 'Saving — excluded from matching…'
                    : nlChanged      ? 'Saving + regenerating embedding…'
                    :                  'Saving…';
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner"></span>' + actionLabel;
    setLbStatus(actionLabel, '');

    var skipEmbedding = document.getElementById('lbSkipEmbedding').checked ? '1' : '0';

    var fd = new FormData();
    fd.append('name',          activeName);
    fd.append('nl_tags',       nlTags);
    fd.append('format',        format);
    fd.append('nl_changed',    nlChanged ? '1' : '0');
    fd.append('skip_embedding', skipEmbedding);

    fetch('?action=update_detail', { method:'POST', body:fd })
        .then(function(r){ return r.text(); })
        .then(function(txt){
            var d = safeJSON(txt);
            btn.disabled  = false;
            btn.innerHTML = '💾 Save Changes';
            if (d.success) {
                setLbStatus('✅ ' + d.message, 'ok');
                var embEl = document.getElementById('lbEmbeddingEdit');
                if (embEl) {
                    embEl.value = d.embedding || '';
                }
                _originalNlTags = nlTags;
                var lf = allFiles.find(function(x){ return x.name === activeName; });
                if (lf) {
                    lf.has_nl       = nlTags !== '';
                    lf.has_emb      = d.has_emb || false;
                    lf.format       = format;
                    lf.skip_emb     = d.skip_embedding === 1 || d.skip_embedding === '1';
                }
                
                // ========== ADD THIS: Re-render tag buttons ==========
                if (typeof renderTagButtons === 'function') {
                    renderTagButtons(nlTags);
                }
                // ====================================================
                
                updateStats();
                renderGrid();
            } else {
                setLbStatus('❌ ' + (d.message || 'Update failed'), 'err');
            }
        })
        .catch(function(e){
            btn.disabled = false;
            btn.innerHTML = '💾 Save Changes';
            setLbStatus('❌ ' + e.message, 'err');
        });
}

/* ══════════════════════════════════════════════════════════
   THUMBNAIL CAPTURE
══════════════════════════════════════════════════════════ */
function openCapture(name, folder) {
    captureFileName = name;
    captureFolder   = folder || activeFolder;
    capturedDataUrl = null;

    document.getElementById('captureModal').classList.add('open');
    document.getElementById('captureFileName').textContent = name;
    document.getElementById('capturePreviewImg').style.display  = 'none';
    document.getElementById('capturePreviewNone').style.display = 'block';
    document.getElementById('capturePreviewSize').textContent   = '';
    document.getElementById('btnSaveThumb').disabled = true;
    document.getElementById('btnRecapture').style.display = 'none';
    document.getElementById('captureStatus').textContent = '';

    var fo     = allFiles.find(function(x){ return x.name === name; });
    var isVid  = fo && fo.kind === 'video';
    var src    = (fo ? fo.folder : folder) + encodeURIComponent(name);
    var video  = document.getElementById('captureVideo');
    var scrub  = document.getElementById('scrubWrap');

    if (isVid) {
        video.style.display = 'block';
        video.src = src;
        video.load();
        scrub.style.display = 'block';
        video.addEventListener('loadedmetadata', function onMeta(){
            var dur = video.duration || 0;
            document.getElementById('scrubber').max   = dur;
            document.getElementById('scrubber').value = Math.min(1, dur * 0.1);
            scrubVideo(Math.min(1, dur * 0.1));
            video.removeEventListener('loadedmetadata', onMeta);
        });
        document.getElementById('capturePreviewNone').textContent = 'Scrub to your frame and click Capture Frame.';
    } else {
        video.style.display = 'none';
        scrub.style.display = 'none';
        document.getElementById('capturePreviewNone').textContent = 'Click Capture Frame to use this image as thumbnail.';
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            var canvas = document.getElementById('captureCanvas');
            canvas.width  = img.naturalWidth  || 400;
            canvas.height = img.naturalHeight || 600;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            capturedDataUrl = canvas.toDataURL('image/png');
            document.getElementById('capturePreviewImg').src = capturedDataUrl;
            document.getElementById('capturePreviewImg').style.display  = 'block';
            document.getElementById('capturePreviewNone').style.display = 'none';
            document.getElementById('capturePreviewSize').textContent   = canvas.width + '×' + canvas.height + 'px';
            document.getElementById('btnSaveThumb').disabled = false;
            document.getElementById('btnRecapture').style.display = 'inline-block';
        };
        img.onerror = function() {
            document.getElementById('capturePreviewNone').textContent = 'Could not load image. Try Capture Frame manually.';
        };
        img.src = src + '?v=' + Date.now();
    }
}

function scrubVideo(val) {
    var video = document.getElementById('captureVideo');
    video.currentTime = parseFloat(val);
    document.getElementById('scrubTime').textContent = parseFloat(val).toFixed(1) + 's';
}

function captureFrame() {
    var fo    = allFiles.find(function(x){ return x.name === captureFileName; });
    var isVid = fo && fo.kind === 'video';
    var canvas = document.getElementById('captureCanvas');
    var ctx    = canvas.getContext('2d');

    if (isVid) {
        var video = document.getElementById('captureVideo');
        canvas.width  = video.videoWidth  || 640;
        canvas.height = video.videoHeight || 360;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    }

    capturedDataUrl = canvas.toDataURL('image/png');
    document.getElementById('capturePreviewImg').src = capturedDataUrl;
    document.getElementById('capturePreviewImg').style.display  = 'block';
    document.getElementById('capturePreviewNone').style.display = 'none';
    document.getElementById('capturePreviewSize').textContent   = canvas.width + '×' + canvas.height + 'px';
    document.getElementById('btnSaveThumb').disabled = false;
    document.getElementById('btnRecapture').style.display = 'inline-block';
    document.getElementById('captureStatus').textContent = 'Frame captured — click Save Thumbnail to store it.';
}

function recaptureFrame() {
    capturedDataUrl = null;
    document.getElementById('capturePreviewImg').style.display  = 'none';
    document.getElementById('capturePreviewNone').style.display = 'block';
    document.getElementById('capturePreviewNone').textContent   = 'Scrub to a new frame and click Capture Frame.';
    document.getElementById('capturePreviewSize').textContent   = '';
    document.getElementById('btnSaveThumb').disabled = true;
    document.getElementById('btnRecapture').style.display = 'none';
    document.getElementById('captureStatus').textContent = '';
}

function saveThumbnail() {
    if (!capturedDataUrl) { alert('No frame captured yet.'); return; }

    var btn = document.getElementById('btnSaveThumb');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Saving…';
    document.getElementById('captureStatus').textContent = 'Saving thumbnail…';

    var fd = new FormData();
    fd.append('name',       captureFileName);
    fd.append('image_data', capturedDataUrl);

    fetch('?action=save_thumbnail', { method:'POST', body:fd })
        .then(function(r){ return r.text(); })
        .then(function(txt){
            var d = safeJSON(txt);
            btn.disabled  = false;
            btn.innerHTML = '💾 Save Thumbnail';
            if (d.success) {
                document.getElementById('captureStatus').textContent = '✅ Saved: ' + d.thumbnail;
                document.getElementById('captureStatus').style.color = 'var(--grn)';
                var lf = allFiles.find(function(x){ return x.name === captureFileName; });
                if (lf) lf.thumbnail = d.thumbnail;
                updateStats();
                renderGrid();
                var bc = document.getElementById('btnCapture');
                if (bc) {
                    bc.disabled  = true;
                    bc.innerHTML = '📷 Thumbnail Captured';
                }
                document.getElementById('lbThumbContent').innerHTML =
                    '<img class="lb-thumb-img" src="' + d.url + '?v=' + Date.now() + '" alt="thumbnail">';
                document.getElementById('lbThumbnailStatus').innerHTML = '✅ <strong>' + esc(d.thumbnail) + '</strong>';
                setTimeout(closeCapture, 1200);
            } else {
                document.getElementById('captureStatus').textContent = '❌ ' + (d.message || 'Save failed');
                document.getElementById('captureStatus').style.color = 'var(--red)';
            }
        })
        .catch(function(e){
            btn.disabled = false;
            btn.innerHTML = '💾 Save Thumbnail';
            document.getElementById('captureStatus').textContent = '❌ ' + e.message;
            document.getElementById('captureStatus').style.color = 'var(--red)';
        });
}

function closeCapture() {
    var video = document.getElementById('captureVideo');
    video.pause();
    video.src = '';
    capturedDataUrl = null;
    document.getElementById('captureModal').classList.remove('open');
    document.getElementById('captureStatus').textContent = '';
    document.getElementById('captureStatus').style.color = '';
}

function doVerify(){
    var btn=document.getElementById('btnVerify');
    btn.disabled=true; btn.innerHTML='<span class="spinner"></span>Saving…';
    setLbStatus('Marking as verified…','');
    var fd=new FormData(); fd.append('name',activeName);
    fetch('?action=verify',{method:'POST',body:fd})
        .then(function(r){return r.text();})
        .then(function(txt){
            var d=safeJSON(txt);
            if(d.success){
                btn.innerHTML='✅ Verified ✓';
                btn.disabled=true;
                btn.style.display='none';
                setLbStatus(d.message,'ok');
                updLocal(activeName,'verified');
                renderGrid();
            } else {
                btn.disabled=false;
                btn.innerHTML='✅ Verify';
                setLbStatus(d.message,'err');
            }
        })
        .catch(function(e){ btn.disabled=false; btn.innerHTML='✅ Verify'; setLbStatus(e.message,'err'); });
}

function doAdd(){
    var btn=document.getElementById('btnAdd');
    btn.disabled=true; btn.innerHTML='<span class="spinner"></span>Adding…';
    setLbStatus('Inserting into DB, generating NL tags and embedding…','');
    var fd=new FormData(); fd.append('name',activeName); fd.append('folder',activeFolder);
    fetch('?action=add_to_db',{method:'POST',body:fd})
        .then(function(r){return r.text();})
        .then(function(txt){
            var d=safeJSON(txt);
            btn.disabled=false; btn.innerHTML='➕ Add to DB';
            if(d.success){
                setLbStatus(d.message,'ok');
                var f=allFiles.find(function(x){return x.name===activeName;});
                if(f){f.in_db=true;f.status='';f.has_nl=true;f.has_emb=true;f.db_id=d.new_id;}
                updateStats(); renderGrid(); openLightbox(activeName);
            } else { setLbStatus(d.message,'err'); }
        })
        .catch(function(e){ btn.disabled=false; btn.innerHTML='➕ Add to DB'; setLbStatus(e.message,'err'); });
}

function showConfirm(){ document.getElementById('delName').textContent=activeName; document.getElementById('confirmOvl').style.display='block'; document.getElementById('confirmBox').style.display='block'; }
function hideConfirm(){ document.getElementById('confirmOvl').style.display='none'; document.getElementById('confirmBox').style.display='none'; }
function doDelete(){
    hideConfirm(); setLbStatus('Deleting…','');
    var fd=new FormData(); fd.append('name',activeName); fd.append('folder',activeFolder);
    fetch('?action=delete',{method:'POST',body:fd})
        .then(function(r){return r.text();})
        .then(function(txt){
            var d=safeJSON(txt);
            if(d.success){ allFiles=allFiles.filter(function(f){return f.name!==activeName;}); updateStats(); renderGrid(); closeLightbox(); }
            else { setLbStatus(d.message,'err'); }
        });
}

function updLocal(name,status){
    var f=allFiles.find(function(x){return x.name===name;});
    if(f){f.status=status;f.has_nl=true;f.has_emb=true;}
    updateStats(); renderGrid();
}
function setLbStatus(msg,cls){
    var el=document.getElementById('lbStatus');
    el.textContent=msg; el.className='lb-status'+(cls?' '+cls:'');
    if(cls==='ok') setTimeout(function(){el.textContent='';el.className='lb-status';},4000);
}
// ── Generate Tags (force regenerate regardless of existing) ──
async function generateTags() {
    const btn = document.getElementById('btnGenerateTags');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating...';
    setLbStatus('🧠 Generating NL tags and embedding from image/video...', '');
    
    const name = activeName;
    const folder = activeFolder;
    
    try {
        // First, check if we have a thumbnail to use (for videos)
        // For images, we can use the original file
        const isVideo = folder.includes('video');
        
        let fd = new FormData();
        fd.append('name', name);
        fd.append('folder', folder);
        fd.append('force', '1'); // Force regenerate flag
        
        const response = await fetch('?action=generate_tags', { method: 'POST', body: fd });
        const text = await response.text();
        
        let data;
        try { 
            data = JSON.parse(text); 
        } catch(e) { 
            console.error('Parse error:', text.substring(0, 500));
            data = { success: false, message: 'Parse error: ' + e.message }; 
        }
        
        btn.disabled = false;
        btn.innerHTML = originalText;
        
        if (data.success) {
            // Update the textareas with new values
            if (data.nl_tags) {
                document.getElementById('lbNlTagsEdit').value = data.nl_tags;
                _originalNlTags = data.nl_tags;
            }
            if (data.embedding) {
                document.getElementById('lbEmbeddingEdit').value = data.embedding;
            }
            
            // Update local file data
            const lf = allFiles.find(x => x.name === activeName);
            if (lf) {
                lf.has_nl = true;
                lf.has_emb = true;
            }
            
            setLbStatus('✅ ' + (data.message || 'Tags generated and saved!'), 'ok');
            
            // Refresh stats and grid
            updateStats();
            renderGrid();
            
            // If auto-save is enabled, we already saved, so just show success
            if (data.saved) {
                setLbStatus('✅ ' + (data.message || 'Tags generated and saved!'), 'ok');
            } else {
                // If not auto-saved, prompt user to save
                setLbStatus('⚠️ Tags generated but not saved. Click Save Changes to store them.', 'err');
            }
        } else {
            setLbStatus('❌ ' + (data.message || 'Generation failed'), 'err');
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        setLbStatus('❌ Error: ' + e.message, 'err');
        console.error('Generate tags error:', e);
    }
}


function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Wire up Enter key in search textarea
document.getElementById('searchQuery').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); performSearch(); }
});

fetch('?action=debug')
    .then(function(r){ return r.text(); })
    .then(function(txt){
        var d = safeJSON(txt);
        if (!d.curl_available)  alert('ERROR: cURL not available. NL tag generation will fail.');
        if (!d.chatgpt_key_set) alert('ERROR: chatgpt_api_key is empty. Check config.php');
    });

loadFiles();


// Track known-missing filenames so renderGrid never requests them again
var _missingFiles = new Set();

// imgMissing - fires once on 404, records filename, stops all future requests
function imgMissing(el) {
    el.onerror = null;  // stop retries immediately
    // Record the filename so renderGrid skips the HTTP request next time
    var src = el.getAttribute('src') || '';
    var filename = src.split('/').pop().split('?')[0];
    try { filename = decodeURIComponent(filename); } catch(e) {}
    if (filename) _missingFiles.add(filename);
    // Also mark by the card's data if available
    var card = el.closest ? el.closest('.card') : null;
    if (card) {
        var label = card.querySelector('.card-label');
        if (label) _missingFiles.add(label.getAttribute('title') || label.textContent.trim());
        card.style.opacity = '0.5';
        card.title = 'File missing on disk - click Scan Orphans to clean up';
    }
    el.src = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="90">'
        + '<rect width="160" height="90" fill="#fef2f2" rx="6"/>'
        + '<text x="80" y="38" text-anchor="middle" fill="#dc2626" font-size="18" font-family="sans-serif">&#9888;</text>'
        + '<text x="80" y="56" text-anchor="middle" fill="#dc2626" font-size="11" font-family="sans-serif">File missing</text>'
        + '<text x="80" y="70" text-anchor="middle" fill="#dc2626" font-size="9" font-family="sans-serif">on disk</text>'
        + '</svg>'
    );
}

// Orphan scanner
var _orphanIds = [];

function scanAndShowOrphans(btn) {
    if (btn) { btn.disabled = true; btn.textContent = 'Scanning...'; }
    fetch('?action=scan_orphans')
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            if (btn) { btn.disabled = false; btn.textContent = 'ðï¸ Scan Orphans'; }
            if (!d.success) { alert('Scan failed: ' + (d.message || 'error')); return; }
            if (!d.orphans || d.orphans.length === 0) {
                alert('✅ No orphans found! All DB records have files on disk.');
                return;
            }
            _orphanIds = d.orphans.map(function(o) { return o.id; });
            // Pre-populate missing files cache so cards show placeholder immediately
            d.orphans.forEach(function(o) { _missingFiles.add(o.name); });
            renderGrid();  // re-render with placeholders, no HTTP requests
            document.getElementById('orphanCount').textContent =
                d.count + ' record' + (d.count !== 1 ? 's' : '') + ' with no file on disk';
            document.getElementById('orphanList').innerHTML = d.orphans.map(function(o) {
                return '<span style="display:inline-block;margin:1px 12px 1px 0;">'
                     + (o.type === 'video' ? '&#127916;' : '&#128444;&#65039;') + ' ' + esc(o.name) + '</span>';
            }).join('');
            var banner = document.getElementById('orphanBanner');
            banner.style.display = 'block';
            banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function(e) {
            if (btn) { btn.disabled = false; btn.textContent = 'ðï¸ Scan Orphans'; }
            alert('Error: ' + e.message);
        });
}

function doDeleteOrphans() {
    if (!_orphanIds || _orphanIds.length === 0) return;
    if (!confirm('Permanently delete ' + _orphanIds.length + ' orphaned DB record(s)?\nFiles already gone. Removes DB rows only.\n\nCannot be undone.')) return;
    var btn = document.getElementById('btnDeleteOrphans');
    if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
    var fd = new FormData();
    fd.append('ids', _orphanIds.join(','));
    fetch('?action=delete_orphans', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            if (btn) { btn.disabled = false; btn.textContent = 'ðï¸ Delete All Orphans'; }
            if (d.success) {
                document.getElementById('orphanBanner').style.display = 'none';
                _orphanIds = [];
                _missingFiles.clear();
                updateStats();
                loadPage(currentPage);
            } else { alert('Error: ' + (d.message || 'Delete failed')); }
        })
        .catch(function(e) {
            if (btn) { btn.disabled = false; btn.textContent = 'ðï¸ Delete All Orphans'; }
            alert('Error: ' + e.message);
        });
}

// Niches
function renderNichesPills(s) {
    var c = document.getElementById('nichesPillsDisplay');
    if (!c) return;
    if (!s || !s.trim()) {
        c.innerHTML = '<span style="color:var(--mut);font-size:11px;font-style:italic;">No niches set</span>';
        return;
    }
    var parts = s.split(','), html = '';
    for (var i = 0; i < parts.length; i++) {
        var n = parts[i].trim();
        if (n) html += '<span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">' + esc(n) + '</span> ';
    }
    c.innerHTML = html || '<span style="color:var(--mut);font-size:11px;font-style:italic;">No niches set</span>';
}

function doGenerateNiches() {
    var btn = document.getElementById('btnGenerateNiches');
    var statusEl = document.getElementById('nichesStatus');
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating...';
    statusEl.textContent = '';
    var fd = new FormData();
    fd.append('name', activeName);
    fetch('?action=generate_niches', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            btn.disabled = false;
            btn.innerHTML = orig;
            if (d.success) {
                document.getElementById('lbNichesEdit').value = d.niches;
                renderNichesPills(d.niches);
                statusEl.style.color = 'var(--grn)';
                statusEl.textContent = d.skipped ? 'Already set' : 'Saved!';
                setTimeout(function() { statusEl.textContent = ''; }, 3000);
            } else {
                statusEl.style.color = 'var(--red)';
                statusEl.textContent = 'Error: ' + (d.message || 'Failed');
            }
        })
        .catch(function(e) {
            btn.disabled = false; btn.innerHTML = orig;
            statusEl.style.color = 'var(--red)';
            statusEl.textContent = 'Error: ' + e.message;
        });
}

function doSaveNiches() {
    var btn = document.getElementById('btnSaveNiches');
    var statusEl = document.getElementById('nichesStatus');
    var niches = document.getElementById('lbNichesEdit').value.trim();
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving...';
    statusEl.textContent = '';
    var fd = new FormData();
    fd.append('name', activeName);
    fd.append('niches', niches);
    fetch('?action=save_niches', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            btn.disabled = false; btn.innerHTML = orig;
            if (d.success) {
                renderNichesPills(niches);
                statusEl.style.color = 'var(--grn)';
                statusEl.textContent = 'Saved!';
                setTimeout(function() { statusEl.textContent = ''; }, 3000);
            } else {
                statusEl.style.color = 'var(--red)';
                statusEl.textContent = 'Error: ' + (d.message || 'Save failed');
            }
        })
        .catch(function(e) {
            btn.disabled = false; btn.innerHTML = orig;
            statusEl.style.color = 'var(--red)';
            statusEl.textContent = 'Error: ' + e.message;
        });
}

// Batch niches
var _nStop = false, _nRun = false, _nTabIdx = 0, _nPage = 1;
var _nQueue = [], _nDone = 0, _nFail = 0, _nTotal = 0;

function startBatchNiches() {
    if (_nRun) return;
    _nStop = false; _nRun = true;
    _nTabIdx = 0; _nPage = 1;
    _nQueue = []; _nDone = 0; _nFail = 0; _nTotal = 0;
    var banner = document.getElementById('nichesBatchBanner');
    banner.style.display = 'block';
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    document.getElementById('nichesBatchLog').textContent = 'Scanning for assets with empty niches...\n';
    document.getElementById('nichesBatchDone').style.display = 'none';
    document.getElementById('nichesBatchBar').style.width = '0%';
    document.getElementById('nichesBatchCount').textContent = '0 / ?';
    document.getElementById('btnUpdateNiches').disabled = true;
    document.getElementById('btnStopNiches').style.display = '';
    _nCollect();
}

function stopBatchNiches() {
    _nStop = true;
    var btn = document.getElementById('btnStopNiches');
    btn.disabled = true;
    btn.textContent = 'Stopping...';
}

function _nCollect() {
    if (_nStop) { _nFinish(true); return; }
    var tabs = ['image', 'video'];
    if (_nTabIdx >= tabs.length) {
        _nTotal = _nQueue.length;
        document.getElementById('nichesBatchCount').textContent = '0 / ' + _nTotal;
        document.getElementById('nichesBatchLog').textContent += 'Found ' + _nTotal + ' assets needing niches.\n';
        if (_nTotal === 0) {
            var doneEl = document.getElementById('nichesBatchDone');
            doneEl.textContent = 'All assets already have niches!';
            doneEl.style.display = 'block';
            _nFinish(false);
        } else {
            _nNext();
        }
        return;
    }
    var url = '?action=get_files&tab=' + tabs[_nTabIdx] + '&filter=all&page=' + _nPage + '&per_page=100';
    fetch(url)
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            if (d.success && d.files && d.files.length > 0) {
                for (var i = 0; i < d.files.length; i++) {
                    var f = d.files[i];
                    if (!f.niches || f.niches.trim() === '') _nQueue.push(f.name);
                }
                if (_nPage < (d.total_pages || 1)) { _nPage++; }
                else { _nTabIdx++; _nPage = 1; }
            } else { _nTabIdx++; _nPage = 1; }
            _nCollect();
        })
        .catch(function(e) {
            document.getElementById('nichesBatchLog').textContent += 'Error: ' + e.message + '\n';
            _nFinish(true);
        });
}

function _nNext() {
    if (_nStop) { _nFinish(true); return; }
    var idx = _nDone + _nFail;
    if (idx >= _nQueue.length) { _nFinish(false); return; }
    var name = _nQueue[idx];
    var log  = document.getElementById('nichesBatchLog');
    var ct   = document.getElementById('nichesBatchCount');
    var bar  = document.getElementById('nichesBatchBar');
    ct.textContent  = (idx + 1) + ' / ' + _nTotal;
    bar.style.width = (((idx + 1) / _nTotal) * 100).toFixed(1) + '%';
    var fd = new FormData();
    fd.append('name', name);
    fetch('?action=generate_niches', { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(txt) {
            var d = safeJSON(txt);
            if (d.success && !d.skipped) {
                log.textContent += 'OK: ' + name + ' -> ' + d.niches + '\n';
                _nDone++;
            } else if (d.skipped) {
                _nDone++;
            } else {
                log.textContent += 'FAIL: ' + name + ': ' + (d.message || 'error') + '\n';
                _nFail++;
            }
            log.scrollTop = log.scrollHeight;
            var done = _nDone + _nFail;
            ct.textContent  = done + ' / ' + _nTotal;
            bar.style.width = ((done / _nTotal) * 100).toFixed(1) + '%';
            _nNext();
        })
        .catch(function(ex) {
            log.textContent += 'FAIL: ' + name + ': ' + ex.message + '\n';
            _nFail++;
            log.scrollTop = log.scrollHeight;
            _nNext();
        });
}

function _nFinish(stopped) {
    _nRun = false;
    document.getElementById('btnUpdateNiches').disabled = false;
    var sb = document.getElementById('btnStopNiches');
    sb.style.display = 'none';
    sb.disabled = false;
    sb.textContent = 'Stop';
    if (!stopped && _nTotal > 0) {
        var doneEl = document.getElementById('nichesBatchDone');
        doneEl.textContent  = 'Done! ' + _nDone + ' updated, ' + _nFail + ' failed.';
        doneEl.style.display = 'block';
        document.getElementById('nichesBatchBar').style.width = '100%';
    }
}

</script>  

</body>
</html>