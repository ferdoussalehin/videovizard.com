<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);

// Force error logging to a specific file - use absolute path
$debug_log = dirname(__FILE__) . '/a_errors.log';
function writeLog($msg) {
    global $debug_log;
    file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

writeLog("=== Scene Inspector Started ===");
writeLog("Debug log path: " . $debug_log);
require 'config.php';
require 'dbconnect_hdb.php';

// ── Fetch all podcasts for dropdown ──────────────────────────
$podcasts = [];
$pq = mysqli_query($conn,
    "SELECT id, title, lang_code, created_date, video_status, internal_status
     FROM hdb_podcasts
     WHERE archived_flag != 1
     ORDER BY id DESC"
);
if (!$pq) die('DB Error (podcasts): ' . mysqli_error($conn));
while ($row = mysqli_fetch_assoc($pq)) $podcasts[] = $row;

// ── Load scenes for selected podcast ─────────────────────────
$selected_podcast_id = (int)($_GET['podcast_id'] ?? 0);
$selected_podcast    = null;
$scenes              = [];
$image_data_map      = [];

writeLog("Selected podcast ID: " . $selected_podcast_id);
 
if ($selected_podcast_id) {
    $prow = mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id = $selected_podcast_id LIMIT 1");
    if ($prow) $selected_podcast = mysqli_fetch_assoc($prow);

    $sq = mysqli_query($conn,
        "SELECT id, seq_no, text_contents, prompt, prompt_1, prompt_2, prompt_3, prompt_4,
                natural_language_tags, image_file, image_file_1, image_file_2, image_file_3, image_file_4,
                video_file, audio_file, hashtags
         FROM hdb_podcast_stories
         WHERE podcast_id = $selected_podcast_id
         ORDER BY seq_no ASC, id ASC"
    );
    if (!$sq) die('DB Error (scenes): ' . mysqli_error($conn));
    while ($row = mysqli_fetch_assoc($sq)) {
        $scenes[] = $row;
    }
    
    writeLog("Found " . count($scenes) . " scenes");
    
    // ── Fetch ALL image data from hdb_image_data for all images used in scenes ──
    $allImageNames = [];
    foreach ($scenes as $sc) {
        if (!empty($sc['image_file'])) {
            $allImageNames[] = $sc['image_file'];
            writeLog("Scene has image_file: '" . $sc['image_file'] . "'");
        }
        if (!empty($sc['image_file_1'])) $allImageNames[] = $sc['image_file_1'];
        if (!empty($sc['image_file_2'])) $allImageNames[] = $sc['image_file_2'];
        if (!empty($sc['image_file_3'])) $allImageNames[] = $sc['image_file_3'];
        if (!empty($sc['image_file_4'])) $allImageNames[] = $sc['image_file_4'];
    }
    $allImageNames = array_unique($allImageNames);
    
    writeLog("Unique image names to fetch: " . print_r($allImageNames, true));
    
    // Replace your existing query section with this:

	if (!empty($allImageNames)) {
		// Build query without prepared statements for simplicity
		$escaped_names = [];
		foreach ($allImageNames as $name) {
			$escaped_names[] = "'" . mysqli_real_escape_string($conn, $name) . "'";
		}
		$placeholders = implode(',', $escaped_names);
		
		$query = "SELECT image_name, natural_language_tags, thumbnail, image_prompt, media_type
				  FROM hdb_image_data
				  WHERE image_name IN ($placeholders)";
		
		// DEBUG: Log the actual query
		error_log("=== DEBUG QUERY ===");
		error_log($query);
		
		$iq = mysqli_query($conn, $query);
		
		// DEBUG: Check if query executed
		error_log("Query result: " . ($iq ? "Success" : "Failed"));
		
		if ($iq) {
			$num_rows = mysqli_num_rows($iq);
			error_log("Number of rows returned: " . $num_rows);
			
			while ($irow = mysqli_fetch_assoc($iq)) {
				error_log("Fetched row: image_name=" . $irow['image_name'] . ", nl_tags=" . $irow['natural_language_tags']);
				
				$image_data_map[$irow['image_name']] = [
					'nl_tags' => $irow['natural_language_tags'],
					'thumbnail' => $irow['thumbnail'],
					'prompt' => $irow['image_prompt'],
					'media_type' => $irow['media_type']
				];
			}
			error_log("Final image_data_map: " . print_r($image_data_map, true));
		} else {
			error_log("Query failed: " . mysqli_error($conn));
		}
	}
}

// Also output the image_data_map as a JavaScript comment for debugging
$image_data_map_debug = json_encode($image_data_map);
writeLog("Final image_data_map JSON length: " . strlen($image_data_map_debug));

// ── Helper functions ─────────────────────────────────────────
$base_url = 'https://videovizard.com/';

function tagPills($tags, $color, $bg) {
    if (!$tags || !trim($tags)) return '<span style="color:#94a3b8;font-size:11px;font-style:italic;">—</span>';
    $parts = array_filter(array_map('trim', explode('|', $tags)));
    $html = '';
    foreach ($parts as $p) {
        $html .= '<span style="display:inline-block;background:' . $bg . ';color:' . $color . ';font-size:11px;padding:2px 9px;border-radius:20px;margin:2px 2px 2px 0;line-height:1.6;">'
               . htmlspecialchars($p) . '</span>';
    }
    return $html;
}

function isVideoFile($filename) {
    if (!$filename) return false;
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['mp4','mov','webm','avi','mkv']);
}

function mediaUrl($filename, $base_url) {
    if (!$filename) return '';
    if (isVideoFile($filename)) {
        return $base_url . 'podcast_videos/' . $filename;
    }
    return $base_url . 'podcast_images/' . $filename;
}

function getThumbnailUrl($filename, $base_url, $image_data_map) {
    if (!$filename) return '';
    if (isset($image_data_map[$filename]['thumbnail']) && !empty($image_data_map[$filename]['thumbnail'])) {
        return $base_url . 'podcast_thumbnails/' . $image_data_map[$filename]['thumbnail'];
    }
    return mediaUrl($filename, $base_url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scene Inspector — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ... (keep all your existing CSS) ... */
</style>
</head>
<body>
<!-- ... (keep your existing HTML) ... -->

<script>
const SCENES = <?= json_encode($scenes) ?>;
const IMAGE_DATA_MAP = <?= json_encode($image_data_map) ?>;
const BASE_URL = '<?= $base_url ?>';

// Display debug info in console and on page
console.log('=== SCENE INSPECTOR DEBUG ===');
console.log('SCENES count:', SCENES.length);
console.log('IMAGE_DATA_MAP:', IMAGE_DATA_MAP);
console.log('IMAGE_DATA_MAP keys:', Object.keys(IMAGE_DATA_MAP));

// Also create a visible debug panel
const debugDiv = document.createElement('div');
debugDiv.style.cssText = 'position:fixed; bottom:10px; right:10px; background:#000; color:#0f0; padding:10px; font-size:10px; font-family:monospace; z-index:9999; max-width:300px; max-height:200px; overflow:auto; border-radius:5px;';
debugDiv.innerHTML = '<strong>Debug Info:</strong><br>IMAGE_DATA_MAP keys: ' + Object.keys(IMAGE_DATA_MAP).join(', ') + '<br>Total images: ' + Object.keys(IMAGE_DATA_MAP).length;
document.body.appendChild(debugDiv);

let currentSceneIndex = -1;

function selectScene(index) {
    currentSceneIndex = index;
    
    document.querySelectorAll('.thumbnail-card').forEach((card, i) => {
        if (i == index) card.classList.add('selected');
        else card.classList.remove('selected');
    });
    
    const panel = document.getElementById('detailPanel');
    panel.style.display = 'block';
    
    loadSceneDetails(index);
}

function loadSceneDetails(index) {
    const scene = SCENES[index];
    if (!scene) return;
    
    const mediaDiv = document.getElementById('detailMedia');
    const infoDiv = document.getElementById('detailInfo');
    
    const mainFile = scene.image_file || '';
    const isVideo = /\.(mp4|webm|mov|avi|mkv)$/i.test(mainFile);
    const mediaUrl = mainFile ? (BASE_URL + (isVideo ? 'podcast_videos/' : 'podcast_images/') + mainFile) : '';
    
    // CRITICAL: Get image data from map
    const imageData = IMAGE_DATA_MAP[mainFile] || { nl_tags: '', prompt: '', thumbnail: '' };
    const imgNL = imageData.nl_tags || '';
    const imgPrompt = imageData.prompt || scene.prompt || '';
    
    // Debug logging
    console.log(`=== Scene ${index} ===`);
    console.log('mainFile:', mainFile);
    console.log('imageData:', imageData);
    console.log('imgNL:', imgNL);
    console.log('mainFile exists in IMAGE_DATA_MAP?', mainFile in IMAGE_DATA_MAP);
    console.log('All IMAGE_DATA_MAP keys:', Object.keys(IMAGE_DATA_MAP));
    
    // Update the floating debug panel
    const debugDiv = document.querySelector('div[style*="position:fixed"]');
    if (debugDiv) {
        debugDiv.innerHTML = `<strong>Debug Info:</strong><br>
        Current Image: ${mainFile}<br>
        Found in map: ${mainFile in IMAGE_DATA_MAP ? 'YES' : 'NO'}<br>
        NL Tags: ${imgNL.substring(0, 50)}<br>
        Total images in map: ${Object.keys(IMAGE_DATA_MAP).length}`;
    }
    
    // Build media HTML
    let mediaHtml = '';
    if (mediaUrl) {
        if (isVideo) {
            mediaHtml = `<video controls style="max-width:100%;max-height:500px;">
                            <source src="${escapeHtml(mediaUrl)}" type="video/mp4">
                        </video>`;
        } else {
            mediaHtml = `<img src="${escapeHtml(mediaUrl)}" alt="Scene ${index+1}" style="max-width:100%;max-height:500px;">`;
        }
    } else {
        mediaHtml = '<div style="color:white;padding:40px;text-align:center;">No media assigned</div>';
    }
    mediaDiv.innerHTML = mediaHtml;
    
    // Build info HTML - The textarea will show the imgNL value
    let infoHtml = `
        <div class="info-section">
            <div class="info-label">📁 Image / Video Name</div>
            <div class="info-value mono">${escapeHtml(mainFile || '—')}</div>
        </div>
        
        <div class="info-section">
            <div class="info-label">🎨 Image Prompt</div>
            <div class="info-value mono">${escapeHtml(imgPrompt || '—')}</div>
        </div>
        
        <div class="info-section">
            <div class="info-label">🎬 Scene NL Tags</div>
            <div class="info-value tags-wrap">
                ${renderTagPills(scene.natural_language_tags || '', '#059669', 'rgba(5,150,105,.10)')}
            </div>
        </div>
        
        <div class="info-section">
            <div class="info-label">🖼 Image NL Tags (from hdb_image_data.natural_language_tags)</div>
            <div class="info-value">
                <textarea id="tags-textarea-${index}" class="editable-tags" data-image-name="${escapeHtml(mainFile)}" 
                    rows="4"
                    placeholder="Enter tags separated by | (pipe)"
                    style="width:100%;padding:8px;border-radius:8px;border:1.5px solid var(--border);
                           font-size:11px;font-family:inherit;resize:vertical;background:#f8fafc;">${escapeHtml(imgNL)}</textarea>
                <button class="save-tags-btn" data-image-name="${escapeHtml(mainFile)}" data-scene-index="${index}"
                    style="margin-top:6px;padding:5px 12px;border-radius:6px;border:none;
                           background:var(--accent);color:#fff;font-size:10px;font-weight:600;cursor:pointer;">
                    💾 Save & Regenerate Embedding (3072-dim)
                </button>
            </div>
        </div>
        
        <div class="info-section">
            <div class="info-label">📝 Scene Text</div>
            <div class="info-value">${escapeHtml((scene.text_contents || '').substring(0, 300))}${(scene.text_contents || '').length > 300 ? '…' : ''}</div>
        </div>
    `;
    
    infoDiv.innerHTML = infoHtml;
    
    // Attach save event handlers
    document.querySelectorAll('.save-tags-btn').forEach(btn => {
        btn.onclick = () => saveImageTags(btn.dataset.imageName, btn.dataset.sceneIndex);
    });
}

// ... (keep your other JavaScript functions)
</script>
</body>
</html>