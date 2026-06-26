<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(0);

$debug_log = dirname(__FILE__) . '/a_errors.log';
function writeLog($msg) {
    global $debug_log;
    file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

writeLog("=== Scene Inspector Started ===");

require 'config.php';
require 'dbconnect_hdb.php';

// ── Fetch all podcasts for dropdown ──────────────────────────────────────────
$podcasts = [];
$pq = mysqli_query($conn,
    "SELECT id, title, lang_code, created_date, video_status, internal_status
     FROM hdb_podcasts
     WHERE archived_flag != 1
     ORDER BY id DESC"
);
if (!$pq) die('DB Error (podcasts): ' . mysqli_error($conn));
while ($row = mysqli_fetch_assoc($pq)) $podcasts[] = $row;

// ── Load scenes for selected podcast ─────────────────────────────────────────
$selected_podcast_id = (int)($_GET['podcast_id'] ?? 0);
$selected_podcast    = null;
$scenes              = [];
$image_data_map      = [];
$match_data_map      = []; // New: store match information per scene+slot

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

    // Collect all image filenames used across all slots
    $allImageNames = [];
    foreach ($scenes as $sc) {
        foreach (['image_file','image_file_1','image_file_2','image_file_3','image_file_4'] as $col) {
            if (!empty(trim($sc[$col] ?? ''))) $allImageNames[] = trim($sc[$col]);
        }
    }
    $allImageNames = array_values(array_unique($allImageNames));
    writeLog("Unique image names: " . count($allImageNames));

    if (!empty($allImageNames)) {
        $escaped_names = array_map(fn($n) => "'" . mysqli_real_escape_string($conn, $n) . "'", $allImageNames);
        $placeholders  = implode(',', $escaped_names);

        $iq = mysqli_query($conn,
            "SELECT image_name, natural_language_tags, thumbnail, media_type
             FROM hdb_image_data
             WHERE image_name IN ($placeholders)"
        );

        if ($iq) {
            while ($irow = mysqli_fetch_assoc($iq)) {
                $nl = mb_convert_encoding($irow['natural_language_tags'] ?? '', 'UTF-8', 'UTF-8');
                $image_data_map[$irow['image_name']] = [
                    'nl_tags'    => $nl,
                    'thumbnail'  => $irow['thumbnail']  ?? '',
                    'media_type' => $irow['media_type']  ?? 'image',
                ];
                writeLog("Mapped: " . $irow['image_name'] . " → nl_tags length=" . strlen($nl));
            }
        } else {
            writeLog("Query failed: " . mysqli_error($conn));
        }
    }
    
    // ── NEW: Fetch match data for all scenes ─────────────────────────────────────
    // Get all scene IDs
    $sceneIds = array_column($scenes, 'id');
    if (!empty($sceneIds)) {
        $escaped_ids = implode(',', array_map('intval', $sceneIds));
        $mq = mysqli_query($conn,
            "SELECT scene_id, slot_number, assigned_filename, search_query, 
                    scene_nl_tags, matched_terms, similarity_score, match_rank,
                    asset_nl_tags
             FROM hdb_media_match_log
             WHERE scene_id IN ($escaped_ids)
             ORDER BY scene_id, slot_number, match_rank"
        );
        
        if ($mq) {
            while ($mrow = mysqli_fetch_assoc($mq)) {
                $sceneId = $mrow['scene_id'];
                $slotNum = $mrow['slot_number'];
                if (!isset($match_data_map[$sceneId])) {
                    $match_data_map[$sceneId] = [];
                }
                $match_data_map[$sceneId][$slotNum] = [
                    'assigned_filename' => $mrow['assigned_filename'],
                    'search_query'      => $mrow['search_query'],
                    'scene_nl_tags'     => $mrow['scene_nl_tags'],
                    'matched_terms'     => $mrow['matched_terms'],
                    'similarity_score'  => $mrow['similarity_score'],
                    'match_rank'        => $mrow['match_rank'],
                    'asset_nl_tags'     => $mrow['asset_nl_tags']
                ];
            }
            writeLog("Fetched match data for " . count($match_data_map) . " scenes");
        } else {
            writeLog("Match query failed: " . mysqli_error($conn));
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$base_url = 'https://videovizard.com/';

function isVideoFile($f) {
    return $f && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['mp4','mov','webm','avi','mkv']);
}
function mediaUrl($f, $base) {
    if (!$f) return '';
    return $base . (isVideoFile($f) ? 'podcast_videos/' : 'podcast_images/') . $f;
}
function thumbUrl($f, $base, $map) {
    if (!$f) return '';
    $t = $map[$f]['thumbnail'] ?? '';
    return $t ? $base . 'podcast_thumbnails/' . $t : mediaUrl($f, $base);
}

// ── JSON encode maps safely ───────────────────────────────────────────────────
$image_data_map_json = json_encode($image_data_map,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
if ($image_data_map_json === false) {
    array_walk_recursive($image_data_map, function(&$v) {
        if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    });
    $image_data_map_json = json_encode($image_data_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}';
}

$match_data_map_json = json_encode($match_data_map,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
if ($match_data_map_json === false) {
    $match_data_map_json = '{}';
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
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:#f0f9ff; --surface:#fff; --border:rgba(2,132,199,.13);
    --accent:#0284c7; --green:#059669; --amber:#d97706; --purple:#7c3aed;
    --muted:#64748b; --text:#0c4a6e; --text-dim:#334155;
}
body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

.site-header {
    position:sticky; top:0; z-index:100;
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 32px;
    background:rgba(255,255,255,.94); backdrop-filter:blur(14px);
    border-bottom:1px solid var(--border);
}
.logo { font-family:'Syne',sans-serif; font-weight:800; font-size:20px; color:var(--accent); text-decoration:none; }
.logo span { color:var(--text); }
.header-tag {
    background:rgba(2,132,199,.08); border:1px solid rgba(2,132,199,.2);
    color:var(--accent); font-size:11px; font-weight:700; padding:4px 13px; border-radius:20px;
}
.wrap { max-width:1400px; margin:0 auto; padding:36px 24px 90px; }
.page-title { margin:0 0 32px; }
.page-title h1 { font-family:'Syne',sans-serif; font-size:clamp(26px,4vw,42px); font-weight:800; }
.page-title h1 em { color:var(--accent); }

.selector-bar {
    background:var(--surface); border:1px solid var(--border);
    border-radius:20px; padding:22px 26px; margin-bottom:32px;
    display:flex; gap:14px; flex-wrap:wrap; align-items:center;
}
.selector-bar select {
    background:#f8fafc; border:1.5px solid var(--border); border-radius:12px;
    padding:11px 40px 11px 16px; font-size:14px; min-width:360px; cursor:pointer;
}
.podcast-info-bar {
    background:linear-gradient(135deg,rgba(2,132,199,.06),rgba(56,189,248,.04));
    border:1px solid rgba(2,132,199,.15); border-radius:16px;
    padding:16px 22px; margin-bottom:28px;
    display:flex; gap:28px; flex-wrap:wrap;
}
.pinfo-item { display:flex; flex-direction:column; gap:2px; }
.pinfo-label { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; }
.pinfo-value { font-size:14px; font-weight:600; color:var(--text); }

/* Thumbnail grid */
.thumbnail-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:12px; margin-bottom:32px;
}
.thumbnail-card {
    background:var(--surface); border:2px solid var(--border);
    border-radius:12px; overflow:hidden; cursor:pointer; transition:all .2s;
}
.thumbnail-card:hover { transform:translateY(-3px); border-color:var(--accent); box-shadow:0 8px 20px rgba(2,132,199,.15); }
.thumbnail-card.selected { border-color:var(--accent); box-shadow:0 0 0 3px rgba(2,132,199,.3); }
.thumbnail-img { aspect-ratio:9/16; object-fit:cover; width:100%; display:block; }
.thumbnail-label { padding:6px 8px; font-size:10px; font-weight:600; text-align:center; border-top:1px solid var(--border); }

/* Detail panel */
.detail-panel { background:var(--surface); border:1px solid var(--border); border-radius:20px; overflow:hidden; margin-top:20px; }
.detail-two-col { display:grid; grid-template-columns:1fr 1fr; gap:0; }
@media(max-width:768px) { .detail-two-col { grid-template-columns:1fr; } }
.detail-media { background:#000; display:flex; align-items:center; justify-content:center; min-height:400px; }
.detail-media img, .detail-media video { max-width:100%; max-height:500px; object-fit:contain; }
.detail-info { padding:20px; overflow-y:auto; max-height:600px; }

/* Info rows */
.info-section { margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid var(--border); }
.info-section:last-child { border-bottom:none; }
.info-label { font-size:10px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; margin-bottom:8px; }
.info-value { font-size:13px; color:var(--text-dim); line-height:1.6; background:#f8fafc; padding:10px 12px; border-radius:10px; border:1px solid var(--border); }
.info-value.mono { font-family:'DM Mono',monospace; font-size:11px; }

/* All 5 slots grid */
.slots-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:8px; margin-bottom:12px; }
.slot-thumb-wrap { border:2px solid var(--border); border-radius:8px; overflow:hidden; background:#f0f9ff; }
.slot-thumb-wrap.active { border-color:var(--accent); }
.slot-thumb-wrap img, .slot-thumb-wrap video { width:100%; aspect-ratio:9/16; object-fit:cover; display:block; }
.slot-label-bar { font-size:9px; font-weight:700; text-align:center; padding:3px; border-top:1px solid var(--border); color:var(--muted); background:#fff; }

/* Match info styling */
.match-info { margin-top:8px; padding:8px; background:#fef3c7; border-radius:6px; border-left:3px solid #d97706; }
.match-info-title { font-size:9px; font-weight:700; color:#92400e; text-transform:uppercase; margin-bottom:4px; }
.match-info-item { font-size:10px; color:#78350f; margin-top:2px; word-break:break-all; }
.match-info-item strong { font-weight:700; color:#451a03; }
.match-badge { display:inline-block; background:#d97706; color:#fff; font-size:8px; padding:2px 6px; border-radius:10px; margin-left:6px; }

/* NL tags textarea */
.nl-textarea {
    width:100%; padding:9px 12px;
    border-radius:8px; border:1.5px solid var(--border);
    font-size:12px; font-family:'DM Mono',monospace; line-height:1.6;
    resize:vertical; background:#f8fafc; color:var(--text-dim);
    transition:border-color .15s;
}
.nl-textarea:focus { outline:none; border-color:var(--accent); background:#fff; }
.save-btn {
    margin-top:8px; padding:7px 16px;
    border-radius:7px; border:none;
    background:var(--accent); color:#fff;
    font-size:11px; font-weight:700; cursor:pointer; transition:all .15s;
}
.save-btn:hover { background:#0369a1; }
.save-btn:disabled { opacity:.5; cursor:not-allowed; }

.tag-pill { display:inline-block; font-size:11px; padding:2px 9px; border-radius:20px; margin:2px; line-height:1.6; }
.no-selection, .loading { text-align:center; padding:60px; color:var(--muted); }
.spinner { width:30px; height:30px; border:3px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin .7s linear infinite; margin:0 auto 12px; }
@keyframes spin { to { transform:rotate(360deg); } }

.toast { position:fixed; bottom:20px; right:20px; padding:12px 20px; border-radius:8px; font-size:13px; font-weight:500; z-index:10000; animation:slideIn .3s ease-out; }
.toast.success { background:#059669; color:#fff; }
.toast.error   { background:#dc2626; color:#fff; }
@keyframes slideIn { from { transform:translateX(100%); opacity:0; } to { transform:translateX(0); opacity:1; } }

/* Accordion styles for match details */
.match-accordion { margin-top:8px; border-top:1px solid var(--border); }
.match-header { cursor:pointer; padding:8px 0; font-size:11px; font-weight:600; color:var(--accent); user-select:none; }
.match-header:hover { color:#0369a1; }
.match-content { display:none; padding:8px; background:#f8fafc; border-radius:6px; margin-top:4px; }
.match-content.show { display:block; }
</style>
</head>
<body>

<header class="site-header">
    <a href="#" class="logo">Video<span>Vizard</span></a>
    <div class="header-tag">Scene Inspector</div>
</header>

<div class="wrap">
    <div class="page-title"><h1>Scene <em>Inspector</em></h1></div>

    <form method="GET" action="">
        <div class="selector-bar">
            <select name="podcast_id" onchange="this.form.submit()">
                <option value="">— Choose a podcast —</option>
                <?php foreach ($podcasts as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $p['id'] == $selected_podcast_id ? 'selected' : '' ?>>
                    [#<?= $p['id'] ?>] <?= htmlspecialchars($p['title'] ?: 'Untitled') ?> · <?= $p['lang_code'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <?php if ($selected_podcast && !empty($scenes)): ?>

    <div class="podcast-info-bar">
        <div class="pinfo-item"><span class="pinfo-label">Title</span><span class="pinfo-value"><?= htmlspecialchars($selected_podcast['title'] ?: 'Untitled') ?></span></div>
        <div class="pinfo-item"><span class="pinfo-label">ID</span><span class="pinfo-value">#<?= $selected_podcast['id'] ?></span></div>
        <div class="pinfo-item"><span class="pinfo-label">Language</span><span class="pinfo-value"><?= strtoupper($selected_podcast['lang_code'] ?? '—') ?></span></div>
        <div class="pinfo-item"><span class="pinfo-label">Scenes</span><span class="pinfo-value"><?= count($scenes) ?></span></div>
        <div class="pinfo-item"><span class="pinfo-label">Status</span><span class="pinfo-value"><?= htmlspecialchars($selected_podcast['internal_status'] ?? '—') ?></span></div>
    </div>

    <!-- Thumbnail strip -->
    <div class="thumbnail-grid" id="thumbnailGrid">
        <?php foreach ($scenes as $i => $sc):
            $mf = trim($sc['image_file'] ?? '');
            $tu = $mf ? thumbUrl($mf, $base_url, $image_data_map) : '';
        ?>
        <div class="thumbnail-card" data-index="<?= $i ?>" onclick="selectScene(<?= $i ?>)">
            <?php if ($tu): ?>
                <img class="thumbnail-img" src="<?= htmlspecialchars($tu) ?>" alt="Scene <?= $i+1 ?>">
            <?php else: ?>
                <div class="thumbnail-img" style="background:linear-gradient(135deg,#e0f2fe,#bae6fd);display:flex;align-items:center;justify-content:center;"><span style="font-size:24px;">🎬</span></div>
            <?php endif; ?>
            <div class="thumbnail-label">Scene <?= $i+1 ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Detail panel -->
    <div id="detailPanel" class="detail-panel" style="display:none;">
        <div class="detail-two-col">
            <div class="detail-media" id="detailMedia"></div>
            <div class="detail-info"  id="detailInfo"></div>
        </div>
    </div>

    <?php elseif ($selected_podcast_id): ?>
    <div class="no-selection">No scenes found for this podcast.</div>
    <?php else: ?>
    <div class="no-selection">Select a podcast above to inspect scenes.</div>
    <?php endif; ?>
</div><!-- /wrap -->

<script>
// ── Data from PHP ─────────────────────────────────────────────────────────────
const SCENES         = <?= json_encode($scenes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const IMAGE_DATA_MAP = <?= $image_data_map_json ?>;
const MATCH_DATA_MAP = <?= $match_data_map_json ?>;
const BASE_URL       = '<?= $base_url ?>';

// ── State ─────────────────────────────────────────────────────────────────────
let currentIndex = -1;
const SLOT_COLS  = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
const SLOT_NAMES = ['Main','V1','V2','V3','V4'];

// ── Select a scene ────────────────────────────────────────────────────────────
function selectScene(idx) {
    currentIndex = idx;
    document.querySelectorAll('.thumbnail-card').forEach((c, i) => c.classList.toggle('selected', i === idx));
    document.getElementById('detailPanel').style.display = 'block';
    renderScene(idx);
}

// ── Render detail panel ───────────────────────────────────────────────────────
function renderScene(idx) {
    const sc = SCENES[idx];
    if (!sc) return;
    
    const sceneId = sc.id;

    // ── Media column: show the main image/video ───────────────────────────────
    const mainFile = (sc.image_file || '').trim();
    const isVid    = /\.(mp4|webm|mov|avi|mkv)$/i.test(mainFile);
    const mUrl     = mainFile ? BASE_URL + (isVid ? 'podcast_videos/' : 'podcast_images/') + mainFile : '';

    const mediaDiv = document.getElementById('detailMedia');
    if (mUrl) {
        mediaDiv.innerHTML = isVid
            ? `<video controls style="max-width:100%;max-height:500px;"><source src="${esc(mUrl)}" type="video/mp4"></video>`
            : `<img src="${esc(mUrl)}" style="max-width:100%;max-height:500px;" alt="">`;
    } else {
        mediaDiv.innerHTML = '<div style="color:#94a3b8;padding:40px;text-align:center;">No media assigned</div>';
    }

    // ── Build 5-slot thumbnail strip with match info ──────────────────────────
    let slotsHtml = '<div class="slots-grid">';
    SLOT_COLS.forEach((col, si) => {
        const fn  = (sc[col] || '').trim();
        const isV = /\.(mp4|webm|mov)$/i.test(fn);
        const td  = fn ? IMAGE_DATA_MAP[fn] : null;
        const tu  = fn ? (td?.thumbnail ? BASE_URL + 'podcast_thumbnails/' + td.thumbnail
                                        : BASE_URL + (isV ? 'podcast_videos/' : 'podcast_images/') + fn)
                       : '';
        const activeCls = fn ? ' active' : '';
        
        // Get match info for this slot (slot_number corresponds to index: 0-based vs 1-based)
        const matchData = MATCH_DATA_MAP[sceneId] ? MATCH_DATA_MAP[sceneId][si + 1] : null;
        const hasMatch = matchData && matchData.assigned_filename === fn;
        
        slotsHtml += `<div class="slot-thumb-wrap${activeCls}" data-slot="${si}">`;
        if (tu) {
            slotsHtml += isV
                ? `<video src="${esc(tu)}" muted style="width:100%;aspect-ratio:9/16;object-fit:cover;display:block;"></video>`
                : `<img src="${esc(tu)}" style="width:100%;aspect-ratio:9/16;object-fit:cover;display:block;" onerror="this.style.display='none'">`;
        } else {
            slotsHtml += `<div style="width:100%;aspect-ratio:9/16;background:#e0f2fe;display:flex;align-items:center;justify-content:center;font-size:16px;">🎬</div>`;
        }
        slotsHtml += `<div class="slot-label-bar">${SLOT_NAMES[si]}${fn ? '' : ' —'}`;
        if (hasMatch) {
            slotsHtml += `<span class="match-badge">Match #${matchData.match_rank}</span>`;
        }
        slotsHtml += `</div>`;
        
        // Add match info expandable section
        if (hasMatch && matchData) {
            slotsHtml += `
                <div class="match-accordion">
                    <div class="match-header" onclick="toggleMatchInfo(this)">📊 View match details ▼</div>
                    <div class="match-content">
                        <div class="match-info-item"><strong>🎯 Matched Terms:</strong> ${esc(matchData.matched_terms || '—')}</div>
                        <div class="match-info-item"><strong>🔍 Search Query:</strong> ${esc(matchData.search_query || '—')}</div>
                        <div class="match-info-item"><strong>⭐ Similarity Score:</strong> ${matchData.similarity_score || '—'}</div>
                        <div class="match-info-item"><strong>📊 Match Rank:</strong> ${matchData.match_rank || '—'}</div>
                        <div class="match-info-item"><strong>🏷️ Scene NL Tags:</strong> ${esc(matchData.scene_nl_tags || '—')}</div>
                        <div class="match-info-item"><strong>🖼️ Asset NL Tags:</strong> ${esc(matchData.asset_nl_tags || '—')}</div>
                    </div>
                </div>
            `;
        }
        
        slotsHtml += `</div>`;
    });
    slotsHtml += '</div>';

    // Get image NL tags
    const imgData  = IMAGE_DATA_MAP[mainFile] || {};
    const nlTags   = imgData.nl_tags  || '';
    const sceneNL  = sc.natural_language_tags || '';
    
    // Get match info for main slot
    const mainMatchData = MATCH_DATA_MAP[sceneId] ? MATCH_DATA_MAP[sceneId][1] : null;
    const hasMainMatch = mainMatchData && mainMatchData.assigned_filename === mainFile;

    const infoDiv  = document.getElementById('detailInfo');
    infoDiv.innerHTML = `

        <div class="info-section">
            <div class="info-label">🎞 All 5 Slots</div>
            ${slotsHtml}
        </div>

        <div class="info-section">
            <div class="info-label">📁 Main Image File</div>
            <div class="info-value mono">${esc(mainFile || '—')}</div>
        </div>
        
        ${hasMainMatch ? `
        <div class="info-section">
            <div class="info-label">🔍 Match Information (from hdb_media_match_log)</div>
            <div class="info-value">
                <div class="match-info-item"><strong>Matched Terms:</strong> ${esc(mainMatchData.matched_terms || '—')}</div>
                <div class="match-info-item"><strong>Search Query:</strong> ${esc(mainMatchData.search_query || '—')}</div>
                <div class="match-info-item"><strong>Similarity Score:</strong> ${mainMatchData.similarity_score || '—'}</div>
                <div class="match-info-item"><strong>Match Rank:</strong> ${mainMatchData.match_rank || '—'}</div>
                <div class="match-info-item"><strong>Scene NL Tags (at match time):</strong> ${esc(mainMatchData.scene_nl_tags || '—')}</div>
                <div class="match-info-item"><strong>Asset NL Tags (at match time):</strong> ${esc(mainMatchData.asset_nl_tags || '—')}</div>
            </div>
        </div>
        ` : ''}

        <div class="info-section">
            <div class="info-label">📝 Scene Text</div>
            <div class="info-value">${esc((sc.text_contents || '').substring(0, 400))}${(sc.text_contents || '').length > 400 ? '…' : ''}</div>
        </div>

        <div class="info-section">
            <div class="info-label">🎬 Scene NL Tags <span style="font-weight:400;color:var(--muted)">(from hdb_podcast_stories)</span></div>
            <div class="info-value">
                ${renderTagPills(sceneNL, '#059669', 'rgba(5,150,105,.10)')}
            </div>
        </div>

        <div class="info-section">
            <div class="info-label">🖼 Image NL Tags <span style="font-weight:400;color:var(--muted)">(hdb_image_data — editable)</span></div>
            <textarea id="nlTagsTextarea" class="nl-textarea" rows="5"
                placeholder="Pipe-separated tags e.g. person walking|urban street|daytime"
            >${esc(nlTags)}</textarea>
            <button class="save-btn" onclick="saveNLTags()">
                💾 Save &amp; Regenerate Embedding
            </button>
            <div id="saveStatus" style="font-size:11px;color:var(--muted);margin-top:6px;min-height:16px;"></div>
        </div>

        <div class="info-section">
            <div class="info-label">🎨 Image Prompt</div>
            <div class="info-value mono" style="max-height:120px;overflow-y:auto;">${esc(sc.prompt || '—')}</div>
        </div>

        <div class="info-section">
            <div class="info-label">#️⃣ Hashtags</div>
            <div class="info-value mono">${esc(sc.hashtags || '—')}</div>
        </div>

    `;
}

// ── Toggle match info accordion ──────────────────────────────────────────────
function toggleMatchInfo(headerElement) {
    const content = headerElement.nextElementSibling;
    content.classList.toggle('show');
    headerElement.textContent = content.classList.contains('show') ? '📊 Hide match details ▲' : '📊 View match details ▼';
}

// ── Save NL Tags ──────────────────────────────────────────────────────────────
async function saveNLTags() {
    const sc = SCENES[currentIndex];
    if (!sc) return;

    const mainFile = (sc.image_file || '').trim();
    if (!mainFile) { showToast('No image file assigned to this scene', 'error'); return; }

    const textarea = document.getElementById('nlTagsTextarea');
    const btn      = document.querySelector('.save-btn');
    const status   = document.getElementById('saveStatus');
    const newTags  = textarea.value.trim();

    btn.disabled    = true;
    btn.textContent = '⏳ Saving…';
    status.textContent = 'Generating 3072-dim embedding…';

    const fd = new FormData();
    fd.append('image_name', mainFile);
    fd.append('nl_tags',    newTags);

    try {
        const r = await fetch('update_image_tags.php', { method:'POST', body:fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();

        if (d.success) {
            if (!IMAGE_DATA_MAP[mainFile]) IMAGE_DATA_MAP[mainFile] = {};
            IMAGE_DATA_MAP[mainFile].nl_tags = newTags;

            btn.textContent    = '✅ Saved';
            status.textContent = '✓ Embedding regenerated (' + (d.dims || 3072) + '-dim)';
            showToast('Tags saved & embedding regenerated', 'success');
            setTimeout(() => { btn.disabled = false; btn.textContent = '💾 Save & Regenerate Embedding'; }, 2500);
        } else {
            throw new Error(d.message || 'Server error');
        }
    } catch (e) {
        btn.disabled    = false;
        btn.textContent = '💾 Save & Regenerate Embedding';
        status.textContent = '❌ ' + e.message;
        showToast('Save failed: ' + e.message, 'error');
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function renderTagPills(tags, color, bg) {
    if (!tags || !tags.trim()) return '<span style="color:#94a3b8;font-style:italic;">—</span>';
    return tags.split('|').map(p => p.trim()).filter(Boolean)
        .map(p => `<span class="tag-pill" style="background:${bg};color:${color};">${esc(p)}</span>`).join('');
}

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type='success') {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className   = `toast ${type}`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}

// Auto-select first scene on load
<?php if (!empty($scenes)): ?>
setTimeout(() => selectScene(0), 80);
<?php endif; ?>
</script>

</body>
</html>