<?php
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: frame-ancestors \'self\'');

include 'config.php';

$OPENAI_API_KEY  = $apiKey;
$EMBEDDING_MODEL = 'text-embedding-3-large';

session_start();

function removeTags($raw, $tagsToRemove) {
    $parts = explode('|', $raw);
    $filtered = array_filter($parts, function($part) use ($tagsToRemove) {
        $trimmed = trim($part);
        foreach ($tagsToRemove as $tag) {
            if (strcasecmp($trimmed, $tag) === 0) {
                return false;
            }
        }
        return true;
    });
    
    $cleaned = array_values(array_filter(array_map('trim', $filtered), fn($p) => $p !== ''));
    return implode('|', $cleaned);
}

function findMatchingTags($raw, $allTags) {
    $parts = explode('|', $raw);
    $parts = array_map('trim', $parts);
    $matches = [];
    
    foreach ($allTags as $searchTag) {
        foreach ($parts as $rowTag) {
            if (strcasecmp($rowTag, $searchTag) === 0) {
                $matches[] = $searchTag;
                break;
            }
        }
    }
    
    return array_unique($matches);
}

function generateEmbedding($text, $apiKey, $model) {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'input' => $text])
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'message' => 'cURL error: ' . $err];
    $data = json_decode($response, true);
    if (!isset($data['data'][0]['embedding'])) {
        return ['success' => false, 'message' => $data['error']['message'] ?? 'Unknown API error'];
    }
    return ['success' => true, 'embedding' => $data['data'][0]['embedding']];
}

function readTagsFromCSV($filePath) {
    $tags = [];
    
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'CSV file not found.', 'tags' => []];
    }
    
    if (($handle = fopen($filePath, "r")) !== FALSE) {
        $firstRow = true;
        while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
            if ($firstRow) {
                $firstRow = false;
                continue;
            }
            if (!empty($data[0])) {
                $tag = trim($data[0]);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }
        fclose($handle);
        $tags = array_values(array_unique($tags));
        return ['success' => true, 'tags' => $tags];
    }
    
    return ['success' => false, 'message' => 'Cannot open file.', 'tags' => []];
}

function saveTagsToCSV($filePath, $tags) {
    $handle = fopen($filePath, 'w');
    fputcsv($handle, ['Tags']);
    foreach ($tags as $tag) {
        fputcsv($handle, [$tag]);
    }
    fclose($handle);
    return true;
}

function getFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    $video_exts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v'];
    
    if (in_array($ext, $image_exts)) return 'image';
    if (in_array($ext, $video_exts)) return 'video';
    return 'unknown';
}

function getMediaUrl($filename) {
    $fileType = getFileType($filename);
    
    if ($fileType === 'image') {
        $possible_urls = [
            '/podcast_images/' . $filename,
            '/hdb/podcast_images/' . $filename,
        ];
    } else if ($fileType === 'video') {
        $possible_urls = [
            '/podcast_videos/' . $filename,
            '/hdb/podcast_videos/' . $filename,
        ];
    } else {
        $possible_urls = [
            '/podcast_images/' . $filename,
            '/podcast_videos/' . $filename,
        ];
    }
    
    foreach ($possible_urls as $url) {
        $full_path = $_SERVER['DOCUMENT_ROOT'] . $url;
        if (file_exists($full_path)) {
            return $url;
        }
    }
    
    if ($fileType === 'image') {
        return '/podcast_images/' . $filename;
    } else if ($fileType === 'video') {
        return '/podcast_videos/' . $filename;
    }
    
    return '/podcast_images/' . $filename;
}

function getMediaPath($filename) {
    $fileType = getFileType($filename);
    $folder = ($fileType === 'image') ? 'podcast_images' : 'podcast_videos';
    
    $possible_paths = [
        __DIR__ . '/' . $folder . '/' . $filename,
        __DIR__ . '/../' . $folder . '/' . $filename,
        $_SERVER['DOCUMENT_ROOT'] . '/' . $folder . '/' . $filename,
        $_SERVER['DOCUMENT_ROOT'] . '/hdb/' . $folder . '/' . $filename,
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    return null;
}

function deleteMediaFile($filename) {
    $path = getMediaPath($filename);
    if ($path && file_exists($path)) {
        return unlink($path);
    }
    return false;
}

// Initialize session
if (!isset($_SESSION['current_offset'])) {
    $_SESSION['current_offset'] = 0;
    $_SESSION['rows_processed'] = 0;
    $_SESSION['rows_updated'] = 0;
    $_SESSION['rows_deleted'] = 0;
    $_SESSION['total_tags_removed'] = 0;
    $_SESSION['tag_list'] = [];
}

// Load tags from CSV
$csv_file = __DIR__ . '/tagging.csv';
$tag_list = [];
if (file_exists($csv_file)) {
    $result = readTagsFromCSV($csv_file);
    if ($result['success']) {
        $tag_list = $result['tags'];
        $_SESSION['tag_list'] = $tag_list;
    }
} else {
    saveTagsToCSV($csv_file, []);
}

if (!empty($_SESSION['tag_list'])) {
    $tag_list = $_SESSION['tag_list'];
}

// AJAX ENDPOINT
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['ajax'];
    
    if ($action == 'add_tag' && isset($_POST['tag'])) {
        $new_tag = trim($_POST['tag']);
        if ($new_tag !== '' && !in_array($new_tag, $tag_list)) {
            $tag_list[] = $new_tag;
            $_SESSION['tag_list'] = $tag_list;
            saveTagsToCSV($csv_file, $tag_list);
            echo json_encode(['success' => true, 'tags' => $tag_list, 'count' => count($tag_list)]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tag already exists or is empty']);
        }
        exit;
    }
    
    if ($action == 'remove_tag' && isset($_POST['tag'])) {
        $tag_to_remove = trim($_POST['tag']);
        $tag_list = array_filter($tag_list, fn($t) => $t !== $tag_to_remove);
        $tag_list = array_values($tag_list);
        $_SESSION['tag_list'] = $tag_list;
        saveTagsToCSV($csv_file, $tag_list);
        echo json_encode(['success' => true, 'tags' => $tag_list, 'count' => count($tag_list)]);
        exit;
    }
    
    // GLOBAL DELETE - Remove tag from ALL rows and add to CSV
    if ($action == 'global_delete_tag' && isset($_POST['tag'])) {
        $tag_to_delete = trim($_POST['tag']);
        
        // Add to CSV if not already there
        if (!in_array($tag_to_delete, $tag_list)) {
            $tag_list[] = $tag_to_delete;
            $_SESSION['tag_list'] = $tag_list;
            saveTagsToCSV($csv_file, $tag_list);
        }
        
        // Find all rows containing this tag
        $escaped = mysqli_real_escape_string($conn, $tag_to_delete);
        $q = mysqli_query($conn,
            "SELECT id, natural_language_tags 
             FROM hdb_image_data 
             WHERE natural_language_tags LIKE '%$escaped%'"
        );
        
        $rows_updated = 0;
        $rows_failed = 0;
        $total_found = 0;
        
        while ($row = mysqli_fetch_assoc($q)) {
            $total_found++;
            $original = $row['natural_language_tags'];
            $new_tags = removeTags($original, [$tag_to_delete]);
            
            // Check if tag actually existed as exact segment
            $parts = explode('|', $original);
            $has_exact = false;
            foreach ($parts as $part) {
                if (strcasecmp(trim($part), $tag_to_delete) === 0) {
                    $has_exact = true;
                    break;
                }
            }
            
            if ($has_exact && $new_tags !== $original && !empty(trim($new_tags))) {
                $embedding_result = generateEmbedding($new_tags, $OPENAI_API_KEY, $EMBEDDING_MODEL);
                
                if ($embedding_result['success']) {
                    $embeddingJson = json_encode($embedding_result['embedding']);
                    $tagsEsc = mysqli_real_escape_string($conn, $new_tags);
                    $embEsc = mysqli_real_escape_string($conn, $embeddingJson);
                    
                    mysqli_query($conn,
                        "UPDATE hdb_image_data
                         SET natural_language_tags = '$tagsEsc',
                             embedding = '$embEsc',
                             updated_at = NOW()
                         WHERE id = {$row['id']}"
                    );
                    
                    $rows_updated++;
                    $_SESSION['rows_updated'] = ($_SESSION['rows_updated'] ?? 0) + 1;
                    $_SESSION['total_tags_removed'] = ($_SESSION['total_tags_removed'] ?? 0) + 1;
                } else {
                    $rows_failed++;
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'tag' => $tag_to_delete,
            'total_found' => $total_found,
            'rows_updated' => $rows_updated,
            'rows_failed' => $rows_failed,
            'tag_count' => count($tag_list)
        ]);
        exit;
    }
    
    // DELETE ROW AND FILE
    if ($action == 'delete_row' && isset($_POST['row_id'])) {
        $row_id = (int)$_POST['row_id'];
        
        // Get file info before deleting
        $q = mysqli_query($conn, "SELECT image_name FROM hdb_image_data WHERE id = $row_id");
        $row = mysqli_fetch_assoc($q);
        
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Row not found']);
            exit;
        }
        
        $filename = $row['image_name'];
        $file_deleted = false;
        $file_path = null;
        
        if ($filename) {
            $file_path = getMediaPath($filename);
            $file_deleted = deleteMediaFile($filename);
        }
        
        // Delete from database
        mysqli_query($conn, "DELETE FROM hdb_image_data WHERE id = $row_id");
        
        $_SESSION['rows_deleted'] = ($_SESSION['rows_deleted'] ?? 0) + 1;
        
        echo json_encode([
            'success' => true,
            'row_id' => $row_id,
            'filename' => $filename,
            'file_deleted' => $file_deleted,
            'file_path' => $file_path
        ]);
        exit;
    }
    
    if ($action == 'get_row') {
        $offset = $_SESSION['current_offset'];
        
        $q = mysqli_query($conn,
            "SELECT id, image_name, natural_language_tags 
             FROM hdb_image_data 
             WHERE natural_language_tags IS NOT NULL 
             AND natural_language_tags != ''
             ORDER BY id
             LIMIT 1 OFFSET $offset"
        );
        
        if ($row = mysqli_fetch_assoc($q)) {
            $matches = findMatchingTags($row['natural_language_tags'], $tag_list);
            $fileType = getFileType($row['image_name']);
            $mediaUrl = getMediaUrl($row['image_name']);
            $mediaPath = getMediaPath($row['image_name']);
            
            echo json_encode([
                'success' => true,
                'row_id' => $row['id'],
                'image' => $row['image_name'],
                'file_type' => $fileType,
                'media_url' => $mediaUrl,
                'media_path' => $mediaPath,
                'file_exists' => $mediaPath && file_exists($mediaPath),
                'original_tags' => $row['natural_language_tags'],
                'original_parts' => explode('|', $row['natural_language_tags']),
                'matches' => $matches,
                'match_count' => count($matches),
                'offset' => $offset,
                'total_tags' => count($tag_list)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No more rows']);
        }
        exit;
    }
    
    if ($action == 'process_row' && isset($_POST['row_id'])) {
        $row_id = (int)$_POST['row_id'];
        
        $q = mysqli_query($conn,
            "SELECT natural_language_tags FROM hdb_image_data WHERE id = $row_id"
        );
        
        if ($row = mysqli_fetch_assoc($q)) {
            $original = $row['natural_language_tags'];
            $new_tags = removeTags($original, $tag_list);
            $matches = findMatchingTags($original, $tag_list);
            
            if ($new_tags !== $original && !empty(trim($new_tags))) {
                $embedding_result = generateEmbedding($new_tags, $OPENAI_API_KEY, $EMBEDDING_MODEL);
                
                if ($embedding_result['success']) {
                    $embeddingJson = json_encode($embedding_result['embedding']);
                    $tagsEsc = mysqli_real_escape_string($conn, $new_tags);
                    $embEsc = mysqli_real_escape_string($conn, $embeddingJson);
                    
                    mysqli_query($conn,
                        "UPDATE hdb_image_data
                         SET natural_language_tags = '$tagsEsc',
                             embedding = '$embEsc',
                             updated_at = NOW()
                         WHERE id = $row_id"
                    );
                    
                    $_SESSION['rows_updated'] = ($_SESSION['rows_updated'] ?? 0) + 1;
                    $_SESSION['total_tags_removed'] = ($_SESSION['total_tags_removed'] ?? 0) + count($matches);
                    
                    echo json_encode([
                        'success' => true,
                        'updated' => true,
                        'new_tags' => $new_tags,
                        'matches_removed' => $matches,
                        'embedding_dims' => count($embedding_result['embedding'])
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => $embedding_result['message']]);
                }
            } else {
                echo json_encode(['success' => true, 'updated' => false, 'message' => 'No changes needed']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Row not found']);
        }
        exit;
    }
    
    if ($action == 'next_row') {
        $_SESSION['current_offset']++;
        $_SESSION['rows_processed'] = ($_SESSION['rows_processed'] ?? 0) + 1;
        echo json_encode(['success' => true, 'offset' => $_SESSION['current_offset']]);
        exit;
    }
    
    if ($action == 'prev_row' && $_SESSION['current_offset'] > 0) {
        $_SESSION['current_offset']--;
        $_SESSION['rows_processed'] = max(0, ($_SESSION['rows_processed'] ?? 0) - 1);
        echo json_encode(['success' => true, 'offset' => $_SESSION['current_offset']]);
        exit;
    }
    
    if ($action == 'goto_row' && isset($_POST['row_number'])) {
        $row_num = (int)$_POST['row_number'];
        if ($row_num >= 0) {
            $_SESSION['current_offset'] = $row_num;
            echo json_encode(['success' => true, 'offset' => $_SESSION['current_offset']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid row number']);
        }
        exit;
    }
    
    if ($action == 'reset') {
        $_SESSION['current_offset'] = 0;
        $_SESSION['rows_processed'] = 0;
        $_SESSION['rows_updated'] = 0;
        $_SESSION['rows_deleted'] = 0;
        $_SESSION['total_tags_removed'] = 0;
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action == 'stats') {
        echo json_encode([
            'processed' => $_SESSION['rows_processed'] ?? 0,
            'updated' => $_SESSION['rows_updated'] ?? 0,
            'deleted' => $_SESSION['rows_deleted'] ?? 0,
            'tags_removed' => $_SESSION['total_tags_removed'] ?? 0,
            'tag_count' => count($tag_list)
        ]);
        exit;
    }
    
    exit;
}

$total_rows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data WHERE natural_language_tags IS NOT NULL AND natural_language_tags != ''"))['c'];
$current_offset = $_SESSION['current_offset'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tag Manager - Delete Row & File</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { 
    font-family: 'Segoe UI', system-ui, sans-serif; 
    background: #0a0f1a;
    color: #e2e8f0; 
    padding: 20px; 
    min-height:100vh;
}
.container { max-width:1800px; margin:0 auto; display:grid; grid-template-columns:1fr 450px 350px; gap:20px; }

.preview-panel {
    background:#1a2332;
    border:1px solid #2a3647;
    border-radius:16px;
    padding:20px;
    height:fit-content;
    position:sticky;
    top:20px;
}

.main-panel {
    background:#1a2332;
    border:1px solid #2a3647;
    border-radius:16px;
    padding:24px;
}

.side-panel {
    background:#1a2332;
    border:1px solid #2a3647;
    border-radius:16px;
    padding:20px;
    height:fit-content;
    position:sticky;
    top:20px;
}

.stats-grid { 
    display:grid; 
    grid-template-columns:repeat(2,1fr); 
    gap:12px; 
    margin-bottom:20px;
}
.stat-card { 
    background:#0f1622; 
    border:1px solid #2a3647; 
    border-radius:10px; 
    padding:16px; 
}
.stat-value { font-size:28px; font-weight:800; color:#60a5fa; }
.stat-value.green { color:#4ade80; }
.stat-value.yellow { color:#fbbf24; }
.stat-value.red { color:#f87171; }
.stat-label { color:#94a3b8; font-size:11px; margin-top:4px; text-transform:uppercase; }

.btn { 
    padding:10px 20px; 
    border-radius:8px; 
    border:none; 
    font-size:13px; 
    font-weight:600; 
    cursor:pointer; 
    transition:all .2s;
}
.btn-green { background:#10b981; color:#fff; }
.btn-blue { background:#3b82f6; color:#fff; }
.btn-red { background:#ef4444; color:#fff; }
.btn-orange { background:#f59e0b; color:#fff; }
.btn-gray { background:#475569; color:#fff; }
.btn:disabled { opacity:0.5; cursor:not-allowed; }

.media-container {
    background:#0f1622;
    border-radius:12px;
    overflow:hidden;
    margin-bottom:16px;
    min-height:250px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.media-container img, .media-container video {
    max-width:100%;
    max-height:350px;
    border-radius:8px;
}
.media-placeholder {
    color:#64748b;
    text-align:center;
    padding:40px;
}

.row-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
    padding-bottom:16px;
    border-bottom:1px solid #2a3647;
    flex-wrap:wrap;
    gap:10px;
}
.row-id {
    background:#3b82f6;
    color:#fff;
    padding:5px 16px;
    border-radius:30px;
    font-weight:700;
    font-size:16px;
}
.image-name {
    color:#94a3b8;
    font-family:monospace;
    font-size:13px;
    word-break:break-all;
}

.tags-container {
    background:#0f1622;
    border-radius:12px;
    padding:16px;
    margin:16px 0;
    max-height:300px;
    overflow-y:auto;
}
.section-label {
    margin-bottom:12px;
    color:#94a3b8;
    font-weight:600;
    font-size:12px;
    text-transform:uppercase;
}

.tag {
    display:inline-block;
    padding:5px 12px;
    margin:3px;
    border-radius:6px;
    font-size:12px;
    font-weight:500;
    cursor:pointer;
    transition:all 0.2s;
}
.tag:hover {
    transform:scale(1.05);
    box-shadow:0 2px 8px rgba(0,0,0,0.3);
}
.tag-kept { background:#065f46; color:#6ee7b7; }
.tag-kept:hover { background:#047857; }
.tag-match { background:#7f1d1d; color:#fca5a5; text-decoration:line-through; }
.tag-selected { 
    background:#b45309 !important; 
    color:#fde68a !important; 
    box-shadow:0 0 0 2px #f59e0b;
}

.tag-list {
    max-height:350px;
    overflow-y:auto;
    margin:16px 0;
}
.tag-item {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:7px 10px;
    background:#0f1622;
    border-radius:6px;
    margin-bottom:5px;
}
.tag-item-text {
    font-family:monospace;
    font-size:12px;
}
.remove-tag-btn {
    background:none;
    border:none;
    color:#ef4444;
    cursor:pointer;
    font-size:16px;
    padding:0 6px;
}
.remove-tag-btn:hover {
    color:#fff;
    background:#ef4444;
    border-radius:4px;
}

.add-tag-form {
    display:flex;
    gap:6px;
    margin:16px 0;
}
.add-tag-input {
    flex:1;
    padding:8px 12px;
    background:#0f1622;
    border:1px solid #2a3647;
    border-radius:6px;
    color:#e2e8f0;
    font-size:13px;
}
.add-tag-input:focus {
    outline:none;
    border-color:#3b82f6;
}

.goto-form {
    display:flex;
    gap:8px;
    margin:16px 0;
    padding:16px;
    background:#0f1622;
    border-radius:8px;
}
.goto-input {
    flex:1;
    padding:8px 12px;
    background:#1a2332;
    border:1px solid #2a3647;
    border-radius:6px;
    color:#e2e8f0;
    font-size:13px;
}
.goto-input:focus {
    outline:none;
    border-color:#3b82f6;
}

.selected-tag-panel {
    background:#0f1622;
    border:2px solid #f59e0b;
    border-radius:12px;
    padding:16px;
    margin:16px 0;
}
.selected-tag-name {
    font-size:16px;
    font-weight:700;
    color:#fbbf24;
    margin-bottom:12px;
    word-break:break-all;
}

.file-info-box {
    background:#0f1622;
    border-radius:8px;
    padding:12px;
    margin:12px 0;
    font-size:12px;
}
.file-exists { color:#4ade80; }
.file-missing { color:#f87171; }

.match-summary {
    background:#0f1622;
    border-left:4px solid #fbbf24;
    padding:14px;
    border-radius:8px;
    margin:16px 0;
}

.button-group {
    display:flex;
    gap:8px;
    margin-top:16px;
    flex-wrap:wrap;
}

.message {
    padding:10px 14px;
    border-radius:8px;
    margin:12px 0;
    font-size:13px;
}
.message-success { background:rgba(16, 185, 129, 0.2); border:1px solid #10b981; color:#4ade80; }
.message-error { background:rgba(239, 68, 68, 0.2); border:1px solid #ef4444; color:#f87171; }
.message-info { background:rgba(59, 130, 246, 0.2); border:1px solid #3b82f6; color:#60a5fa; }

.file-type-badge {
    display:inline-block;
    padding:3px 10px;
    border-radius:20px;
    font-size:11px;
    font-weight:600;
    text-transform:uppercase;
}
.file-type-image { background:#1e3a8a; color:#60a5fa; }
.file-type-video { background:#7f1d1d; color:#f87171; }

.nav-info {
    color:#94a3b8;
    font-size:13px;
    margin:8px 0;
}

.current-row-highlight {
    color:#fbbf24;
    font-weight:600;
}

.warning-text {
    color:#f87171;
    font-size:12px;
    margin-top:8px;
}
</style>
</head>
<body>
<div class="container">

<!-- PREVIEW PANEL -->
<div class="preview-panel">
    <h3 style="margin-bottom:16px;">🖼️ Media Preview</h3>
    
    <div id="media-container" class="media-container">
        <div class="media-placeholder">
            <p>📂 Load a row to preview</p>
        </div>
    </div>
    
    <div id="file-info" style="margin-top:12px;"></div>
    <div id="file-path-info"></div>
    
    <div style="margin-top:20px;">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" id="stat-processed">0</div>
                <div class="stat-label">Processed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value green" id="stat-updated">0</div>
                <div class="stat-label">Updated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value red" id="stat-deleted">0</div>
                <div class="stat-label">Deleted</div>
            </div>
            <div class="stat-card">
                <div class="stat-value yellow" id="stat-tags-removed">0</div>
                <div class="stat-label">Tags Removed</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" id="stat-search-tags"><?= count($tag_list) ?></div>
                <div class="stat-label">Search Tags</div>
            </div>
        </div>
    </div>
    
    <!-- GOTO ROW CONTROL -->
    <div class="goto-form">
        <input type="number" class="goto-input" id="goto-row-input" placeholder="Row #" min="0" max="<?= $total_rows - 1 ?>" value="<?= $current_offset ?>">
        <button class="btn btn-blue" onclick="gotoRow()" style="padding:8px 16px;">Go</button>
    </div>
    
    <div class="nav-info">
        Current: <span class="current-row-highlight" id="current-offset"><?= $current_offset ?></span> / <?= $total_rows ?> rows<br>
        <small>(Row numbers are 0-based offsets)</small>
    </div>
    
    <!-- Selected Tag Panel -->
    <div class="selected-tag-panel" id="selected-tag-panel" style="display:none;">
        <div class="section-label">🎯 Selected Tag</div>
        <div class="selected-tag-name" id="selected-tag-name"></div>
        <button class="btn btn-orange" onclick="globalDeleteSelectedTag()" style="width:100%; margin-top:8px;">
            🌍 GLOBAL DELETE - Remove from ALL rows
        </button>
        <p class="warning-text">⚠️ This will scan all <?= $total_rows ?> rows, remove this tag everywhere, and regenerate embeddings!</p>
    </div>
</div>

<!-- MAIN PANEL -->
<div class="main-panel">
    <h2 style="margin-bottom:16px;">📋 Tag Management</h2>
    
    <div id="row-display">
        <div style="text-align:center; padding:60px; color:#64748b;">
            <p>Click "Load Row" to start</p>
            <p style="font-size:13px; margin-top:12px;">Current position: Row #<?= $current_offset ?></p>
        </div>
    </div>
    
    <div id="message-area"></div>
    
    <div class="button-group">
        <button class="btn btn-gray" id="btn-prev" onclick="prevRow()" <?= $current_offset == 0 ? 'disabled' : '' ?>>⬅️ Previous</button>
        <button class="btn btn-blue" id="btn-load" onclick="loadRow()">📂 Load Row</button>
        <button class="btn btn-green" id="btn-process" onclick="processRow()" disabled>⚡ Process & Update</button>
        <button class="btn btn-red" id="btn-delete" onclick="deleteCurrentRow()" disabled>🗑️ Delete Row & File</button>
        <button class="btn btn-gray" id="btn-next" onclick="nextRow()" disabled>➡️ Next</button>
    </div>
</div>

<!-- SIDE PANEL -->
<div class="side-panel">
    <h3 style="margin-bottom:16px;">🏷️ Search Tags (<span id="tag-count"><?= count($tag_list) ?></span>)</h3>
    
    <div class="add-tag-form">
        <input type="text" class="add-tag-input" id="new-tag-input" placeholder="Add new tag..." onkeypress="if(event.key==='Enter')addTag()">
        <button class="btn btn-blue" onclick="addTag()" style="padding:8px 14px;">+</button>
    </div>
    
    <div class="tag-list" id="tag-list">
        <?php foreach ($tag_list as $tag): ?>
        <div class="tag-item">
            <span class="tag-item-text"><?= htmlspecialchars($tag) ?></span>
            <button class="remove-tag-btn" onclick="removeTag('<?= htmlspecialchars(addslashes($tag)) ?>')">✕</button>
        </div>
        <?php endforeach; ?>
        <?php if (empty($tag_list)): ?>
        <p style="color:#64748b; text-align:center; padding:20px;">No tags yet</p>
        <?php endif; ?>
    </div>
    
    <button class="btn btn-gray" onclick="resetAll()" style="width:100%; margin-top:16px;">🔄 Reset to Row 0</button>
</div>

</div>

<script>
let currentRowId = null;
let currentOriginalTags = '';
let currentFileName = '';
let totalRows = <?= $total_rows ?>;
let currentOffset = <?= $current_offset ?>;
let selectedTag = null;

function updateStats() {
    fetch('?ajax=stats')
        .then(r => r.json())
        .then(data => {
            document.getElementById('stat-processed').textContent = data.processed;
            document.getElementById('stat-updated').textContent = data.updated;
            document.getElementById('stat-deleted').textContent = data.deleted || 0;
            document.getElementById('stat-tags-removed').textContent = data.tags_removed;
            document.getElementById('stat-search-tags').textContent = data.tag_count;
            document.getElementById('current-offset').textContent = currentOffset;
            document.getElementById('goto-row-input').value = currentOffset;
            document.getElementById('btn-prev').disabled = (currentOffset === 0);
        });
}

function displayMedia(data) {
    let container = document.getElementById('media-container');
    let fileInfo = document.getElementById('file-info');
    let pathInfo = document.getElementById('file-path-info');
    
    currentFileName = data.image;
    
    if (data.file_type === 'image') {
        container.innerHTML = `<img src="${data.media_url}" alt="${data.image}" onerror="this.onerror=null; this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22><rect fill=%22%231a2332%22 width=%22200%22 height=%22200%22/><text fill=%22%2364748b%22 x=%22100%22 y=%22100%22 text-anchor=%22middle%22>Image not found</text></svg>';">`;
        fileInfo.innerHTML = `<span class="file-type-badge file-type-image">📷 IMAGE</span> ${escapeHtml(data.image)}<br><small style="color:#64748b;">/podcast_images/</small>`;
    } else if (data.file_type === 'video') {
        container.innerHTML = `<video controls preload="metadata"><source src="${data.media_url}" type="video/mp4">Your browser does not support video.</video>`;
        fileInfo.innerHTML = `<span class="file-type-badge file-type-video">🎬 VIDEO</span> ${escapeHtml(data.image)}<br><small style="color:#64748b;">/podcast_videos/</small>`;
    } else {
        container.innerHTML = `<div class="media-placeholder"><p>📁 ${escapeHtml(data.image)}</p><p style="font-size:12px; margin-top:8px;">(File type not supported for preview)</p></div>`;
        fileInfo.innerHTML = `<span class="file-type-badge">📄 FILE</span> ${escapeHtml(data.image)}`;
    }
    
    // Show file existence status
    if (data.file_exists) {
        pathInfo.innerHTML = `<div class="file-info-box"><span class="file-exists">✅ File exists</span><br><small style="color:#64748b;">${escapeHtml(data.media_path || '')}</small></div>`;
    } else {
        pathInfo.innerHTML = `<div class="file-info-box"><span class="file-missing">⚠️ File not found on disk</span><br><small style="color:#64748b;">${escapeHtml(data.media_path || '')}</small></div>`;
    }
}

function selectTag(tag) {
    selectedTag = tag;
    document.getElementById('selected-tag-name').textContent = tag;
    document.getElementById('selected-tag-panel').style.display = 'block';
    
    // Highlight selected tag in the list
    document.querySelectorAll('.tag').forEach(el => {
        el.classList.remove('tag-selected');
        if (el.textContent.replace('❌', '').trim() === tag) {
            el.classList.add('tag-selected');
        }
    });
}

function globalDeleteSelectedTag() {
    if (!selectedTag) return;
    
    if (!confirm(`GLOBAL DELETE: Remove "${selectedTag}" from ALL rows?\n\nThis will:\n• Scan all ${totalRows} rows\n• Remove this tag wherever found\n• Regenerate embeddings for affected rows\n• Add tag to CSV list\n\nThis cannot be undone!`)) {
        return;
    }
    
    let formData = new FormData();
    formData.append('tag', selectedTag);
    
    document.getElementById('message-area').innerHTML = '<div class="message message-info">⏳ Processing global delete... Scanning all rows...</div>';
    
    fetch('?ajax=global_delete_tag', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('message-area').innerHTML = `
                    <div class="message message-success">
                        ✅ Global Delete Complete!<br>
                        Tag: "${data.tag}"<br>
                        Rows found: ${data.total_found}<br>
                        Rows updated: ${data.rows_updated}<br>
                        Rows failed: ${data.rows_failed}
                    </div>
                `;
                document.getElementById('selected-tag-panel').style.display = 'none';
                selectedTag = null;
                updateStats();
                
                // Refresh tag list
                refreshTagList(data.tags || []);
                document.getElementById('stat-search-tags').textContent = data.tag_count;
                
                // Reload current row to show updated tags
                if (currentRowId) {
                    loadRow();
                }
            } else {
                document.getElementById('message-area').innerHTML = '<div class="message message-error">❌ Error: ' + (data.message || 'Unknown error') + '</div>';
            }
        });
}

function deleteCurrentRow() {
    if (!currentRowId) return;
    if (!currentFileName) return;
    
    if (!confirm(`DELETE ROW #${currentRowId} AND FILE?\n\nThis will:\n• Delete row from database\n• Delete file: ${currentFileName}\n• From folder: podcast_images/ or podcast_videos/\n\nThis CANNOT be undone!`)) {
        return;
    }
    
    let formData = new FormData();
    formData.append('row_id', currentRowId);
    
    document.getElementById('btn-delete').disabled = true;
    document.getElementById('btn-delete').textContent = '⏳ Deleting...';
    
    fetch('?ajax=delete_row', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                let fileStatus = data.file_deleted ? '✅ File deleted' : '⚠️ File not found or could not be deleted';
                document.getElementById('message-area').innerHTML = `
                    <div class="message message-success">
                        ✅ Row #${data.row_id} deleted!<br>
                        ${fileStatus}<br>
                        <small>${escapeHtml(data.file_path || '')}</small>
                    </div>
                `;
                
                currentRowId = null;
                updateStats();
                
                // Move to next row automatically
                nextRow();
            } else {
                document.getElementById('message-area').innerHTML = '<div class="message message-error">❌ Error: ' + (data.message || 'Unknown error') + '</div>';
                document.getElementById('btn-delete').disabled = false;
                document.getElementById('btn-delete').textContent = '🗑️ Delete Row & File';
            }
        });
}

function gotoRow() {
    let input = document.getElementById('goto-row-input');
    let rowNum = parseInt(input.value);
    
    if (isNaN(rowNum) || rowNum < 0 || rowNum >= totalRows) {
        alert('Please enter a valid row number between 0 and ' + (totalRows - 1));
        return;
    }
    
    let formData = new FormData();
    formData.append('row_number', rowNum);
    
    fetch('?ajax=goto_row', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentOffset = data.offset;
                document.getElementById('btn-load').disabled = false;
                document.getElementById('btn-process').disabled = true;
                document.getElementById('btn-delete').disabled = true;
                document.getElementById('btn-next').disabled = true;
                document.getElementById('message-area').innerHTML = '';
                document.getElementById('row-display').innerHTML = `<div style="text-align:center; padding:60px; color:#64748b;"><p>Moved to row #${currentOffset}</p><p style="margin-top:12px;">Click "Load Row" to load this row</p></div>`;
                document.getElementById('media-container').innerHTML = '<div class="media-placeholder"><p>📂 Load row to preview</p></div>';
                document.getElementById('file-info').innerHTML = '';
                document.getElementById('file-path-info').innerHTML = '';
                document.getElementById('selected-tag-panel').style.display = 'none';
                selectedTag = null;
                updateStats();
            }
        });
}

function loadRow() {
    document.getElementById('row-display').innerHTML = '<div style="text-align:center; padding:40px;"><div class="spinner"></div><p style="margin-top:16px;">Loading row #' + currentOffset + '...</p></div>';
    
    fetch('?ajax=get_row')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentRowId = data.row_id;
                currentOriginalTags = data.original_tags;
                currentFileName = data.image;
                
                displayMedia(data);
                
                let html = `
                    <div class="row-header">
                        <span class="row-id">📊 Row #${data.row_id}</span>
                        <span style="color:#94a3b8;">Tags: ${data.original_parts.length} | Offset: ${data.offset}</span>
                    </div>
                    
                    <div class="tags-container">
                        <div class="section-label">📋 Current Tags (Click to select for global delete)</div>
                        <div>
                `;
                
                data.original_parts.forEach(tag => {
                    let isMatch = data.matches && data.matches.includes(tag);
                    html += `<span class="tag ${isMatch ? 'tag-match' : 'tag-kept'}" onclick="selectTag('${escapeHtml(tag).replace(/'/g, "\\'")}')">${escapeHtml(tag)}${isMatch ? ' ❌' : ''}</span>`;
                });
                
                html += `</div></div>`;
                
                if (data.match_count > 0) {
                    html += `<div class="match-summary">`;
                    html += `<strong style="color:#fbbf24;">🎯 Matches Found (${data.match_count}):</strong> `;
                    data.matches.forEach(tag => {
                        html += `<span class="tag tag-match" onclick="selectTag('${escapeHtml(tag).replace(/'/g, "\\'")}')">${escapeHtml(tag)}</span> `;
                    });
                    html += `</div>`;
                    document.getElementById('btn-process').disabled = false;
                } else {
                    html += `<div style="padding:14px; text-align:center; color:#64748b; background:#0f1622; border-radius:8px;">✅ No matches found</div>`;
                    document.getElementById('btn-process').disabled = true;
                }
                
                document.getElementById('row-display').innerHTML = html;
                document.getElementById('btn-next').disabled = false;
                document.getElementById('btn-delete').disabled = false;
                document.getElementById('btn-load').disabled = true;
                
                // Clear selected tag
                document.getElementById('selected-tag-panel').style.display = 'none';
                selectedTag = null;
                
            } else {
                document.getElementById('row-display').innerHTML = '<div style="text-align:center; padding:60px; color:#f87171;"><h3>No more rows!</h3><p>' + data.message + '</p></div>';
                document.getElementById('btn-load').disabled = true;
                document.getElementById('btn-next').disabled = true;
                document.getElementById('btn-delete').disabled = true;
            }
            
            updateStats();
        });
}

function processRow() {
    if (!currentRowId) return;
    
    document.getElementById('btn-process').disabled = true;
    document.getElementById('btn-process').textContent = '⏳ Processing...';
    
    let formData = new FormData();
    formData.append('row_id', currentRowId);
    
    fetch('?ajax=process_row', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            let msgDiv = document.getElementById('message-area');
            
            if (data.success && data.updated) {
                msgDiv.innerHTML = `<div class="message message-success">✅ Updated! Removed ${data.matches_removed.length} tags. New embedding (${data.embedding_dims} dims).</div>`;
                
                let newParts = data.new_tags.split('|');
                let html = `<div class="tags-container" style="border:2px solid #10b981; margin-top:16px;">`;
                html += `<div class="section-label" style="color:#4ade80;">✅ Updated Tags (${newParts.length})</div><div>`;
                newParts.forEach(tag => {
                    html += `<span class="tag tag-kept" onclick="selectTag('${escapeHtml(tag).replace(/'/g, "\\'")}')">${escapeHtml(tag)}</span>`;
                });
                html += `</div></div>`;
                document.getElementById('row-display').insertAdjacentHTML('beforeend', html);
                
            } else if (data.success) {
                msgDiv.innerHTML = `<div class="message message-success">ℹ️ ${data.message}</div>`;
            } else {
                msgDiv.innerHTML = `<div class="message message-error">❌ ${data.message}</div>`;
            }
            
            updateStats();
            document.getElementById('btn-process').textContent = '⚡ Process & Update';
        });
}

function nextRow() {
    fetch('?ajax=next_row')
        .then(r => r.json())
        .then(data => {
            currentOffset = data.offset;
            document.getElementById('btn-load').disabled = false;
            document.getElementById('btn-process').disabled = true;
            document.getElementById('btn-delete').disabled = true;
            document.getElementById('btn-next').disabled = true;
            document.getElementById('message-area').innerHTML = '';
            document.getElementById('row-display').innerHTML = `<div style="text-align:center; padding:60px; color:#64748b;"><p>Moved to row #${currentOffset}</p><p style="margin-top:12px;">Click "Load Row" to continue</p></div>`;
            document.getElementById('media-container').innerHTML = '<div class="media-placeholder"><p>📂 Load row to preview</p></div>';
            document.getElementById('file-info').innerHTML = '';
            document.getElementById('file-path-info').innerHTML = '';
            document.getElementById('selected-tag-panel').style.display = 'none';
            selectedTag = null;
            updateStats();
        });
}

function prevRow() {
    if (currentOffset === 0) return;
    
    fetch('?ajax=prev_row')
        .then(r => r.json())
        .then(data => {
            currentOffset = data.offset;
            document.getElementById('btn-load').disabled = false;
            document.getElementById('btn-process').disabled = true;
            document.getElementById('btn-delete').disabled = true;
            document.getElementById('btn-next').disabled = true;
            document.getElementById('message-area').innerHTML = '';
            document.getElementById('row-display').innerHTML = `<div style="text-align:center; padding:60px; color:#64748b;"><p>Moved to row #${currentOffset}</p><p style="margin-top:12px;">Click "Load Row" to continue</p></div>`;
            document.getElementById('media-container').innerHTML = '<div class="media-placeholder"><p>📂 Load row to preview</p></div>';
            document.getElementById('file-info').innerHTML = '';
            document.getElementById('file-path-info').innerHTML = '';
            document.getElementById('selected-tag-panel').style.display = 'none';
            selectedTag = null;
            updateStats();
        });
}

function addTag() {
    let input = document.getElementById('new-tag-input');
    let tag = input.value.trim();
    if (!tag) return;
    
    let formData = new FormData();
    formData.append('tag', tag);
    
    fetch('?ajax=add_tag', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                refreshTagList(data.tags);
                input.value = '';
                document.getElementById('stat-search-tags').textContent = data.count;
            } else {
                alert(data.message);
            }
        });
}

function removeTag(tag) {
    if (!confirm('Remove "' + tag + '" from search list?')) return;
    
    let formData = new FormData();
    formData.append('tag', tag);
    
    fetch('?ajax=remove_tag', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                refreshTagList(data.tags);
                document.getElementById('stat-search-tags').textContent = data.count;
            }
        });
}

function refreshTagList(tags) {
    let html = '';
    if (tags.length === 0) {
        html = '<p style="color:#64748b; text-align:center; padding:20px;">No tags yet</p>';
    } else {
        tags.forEach(tag => {
            html += `<div class="tag-item">`;
            html += `<span class="tag-item-text">${escapeHtml(tag)}</span>`;
            html += `<button class="remove-tag-btn" onclick="removeTag('${escapeHtml(tag).replace(/'/g, "\\'")}')">✕</button>`;
            html += `</div>`;
        });
    }
    document.getElementById('tag-list').innerHTML = html;
    document.getElementById('tag-count').textContent = tags.length;
}

function resetAll() {
    if (!confirm('Reset to row 0? This will clear progress counters.')) return;
    
    fetch('?ajax=reset')
        .then(r => r.json())
        .then(data => {
            currentOffset = 0;
            document.getElementById('btn-load').disabled = false;
            document.getElementById('btn-process').disabled = true;
            document.getElementById('btn-delete').disabled = true;
            document.getElementById('btn-next').disabled = true;
            document.getElementById('btn-prev').disabled = true;
            document.getElementById('message-area').innerHTML = '';
            document.getElementById('row-display').innerHTML = '<div style="text-align:center; padding:60px; color:#64748b;"><p>Reset to row 0. Click "Load Row" to start.</p></div>';
            document.getElementById('media-container').innerHTML = '<div class="media-placeholder"><p>📂 Load row to preview</p></div>';
            document.getElementById('file-info').innerHTML = '';
            document.getElementById('file-path-info').innerHTML = '';
            document.getElementById('selected-tag-panel').style.display = 'none';
            selectedTag = null;
            updateStats();
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

updateStats();
</script>

</body>
</html>