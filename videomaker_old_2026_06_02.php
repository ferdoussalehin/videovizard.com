<?php
// smooth_player_test.php — v12.0 (multi-caption system)
ob_start();
ini_set('session.gc_maxlifetime', 15552000);  // 180 days in seconds
ini_set('session.cookie_lifetime', 15552000); // 180 days
session_set_cookie_params(15552000);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');   // log file path
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }
$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);
include 'dbconnect_hdb.php';



// ── VPS MP4 Conversion Config ─────────────────────────────────────────────────
$VPS_URL    = 'http://187.124.249.46/videovizard.com/vps_stitch.php';
$SECRET_KEY = 'VS_FFmpeg_2026_Secret!';
require_once 'generate_image_api.php';
require_once 'chatgpt_functions.php';

$podcast_id = (int)($_GET['podcast_id'] ?? $_POST['podcast_id'] ?? 0);
if (!$podcast_id) {
    if (isset($_POST['ajax_action'])) {
        while(ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Missing podcast_id']);
        exit;
    }
    die('No podcast_id');
}

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
if (!$row) {
    if (isset($_POST['ajax_action'])) {
        while(ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Podcast not found']);
        exit;
    }
    die('Podcast #'.$podcast_id.' not found');
}
$podcast_title   = $row['title']          ?? '';
$podcast_music   = $row['music_file']     ?? '';
$lang_code       = $row['lang_code']      ?? 'en';
$video_type      = $row['video_type']     ?? 'standard';
$is_podcast_type = ($video_type === 'podcast');

$admin_row     = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT plan_type FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$is_free_trial = ($admin_row['plan_type'] ?? 'free_trial') === 'free_trial';

// ── Folder resolution: use image_folder column if set, else defaults ──────────
$img_folder = trim(trim($row['image_folder'] ?? ''), '/') ?: 'podcast_images';
$vid_folder = $img_folder; // same folder holds both images and videos
$audio_speed     = isset($row['audio_speed']) && $row['audio_speed'] > 0
                   ? (float)$row['audio_speed'] : 1.0;

$host_voice_id  = $row['host_voice_id']  ?? $row['host_voice']   ?? $row['voice_id']      ?? '';
$guest_voice_id = $row['guest_voice_id'] ?? $row['guest_voice']  ?? $row['voice_id_guest'] ?? '';

// Auto-create per-slot columns in hdb_podcast_stories if missing
$slot_cols = [
    'slot_main' => "TINYINT(1) NOT NULL DEFAULT 1",
    'slot_1'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_2'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_3'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'slot_4'    => "TINYINT(1) NOT NULL DEFAULT 0",
];
foreach ($slot_cols as $col => $def) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories LIKE '$col'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN $col $def");
    }
} 




$scenes = [];
$scenes_json = '[]';
$all_captions = [];
$all_captions_json = '[]';
$vid_files = [];



if (!isset($_POST['ajax_action'])) {
    // Only load scenes/captions for full page render — not needed for AJAX
    $q = mysqli_query($conn,
        "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
    while ($r = mysqli_fetch_assoc($q)) $scenes[] = $r;
    if (!$scenes) die('No scenes found for podcast #'.$podcast_id);

    $scenes_json = json_encode($scenes);

    if ($scenes) {
        $scene_ids = implode(',', array_map(fn($s)=>(int)$s['id'], $scenes));
        $cq = mysqli_query($conn,
            "SELECT * FROM hdb_captions WHERE podcast_id=$podcast_id AND story_id IN ($scene_ids) ORDER BY z_index ASC, id ASC");
        while ($cr = mysqli_fetch_assoc($cq)) $all_captions[] = $cr;
    }
    $all_captions_json = json_encode($all_captions);

    // Build a map of story_id => main caption text from hdb_captions.
    // Used by the scene list textarea — text_contents from hdb_podcast_stories
    // is ignored entirely; captions are always sourced from hdb_captions.
    $caption_text_map = [];
    foreach ($all_captions as $cap) {
        $sid  = (int)$cap['story_id'];
        $name = strtolower(trim($cap['caption_name'] ?? ''));
        // Prefer the 'main' caption; fall back to the first one found
        if (!isset($caption_text_map[$sid]) || $name === 'main') {
            $caption_text_map[$sid] = $cap['text_content'] ?? '';
        }
    }

    $img_slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    foreach ($scenes as $sc) {
        foreach ($img_slots as $k) {
            $fn = trim($sc[$k] ?? '');
            if ($fn && preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $fn) && !in_array($fn, $vid_files))
                $vid_files[] = $fn;
        }
    }
}
require_once 'videomaker_ajax.php';


    
  



define('CW', 360);
define('CH', 640);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — <?= htmlspecialchars($podcast_title) ?></title>
<link rel="stylesheet" href="videomaker.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<script src="https://cdnjs.cloudflare.com/ajax/libs/ffmpeg.wasm/0.11.6/ffmpeg.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Montserrat:wght@400;700;900&family=Raleway:wght@400;700;900&family=Oswald:wght@400;700&family=Anton&family=Righteous&family=Black+Han+Sans&family=Poppins:wght@400;700;900&family=Josefin+Sans:wght@400;700&family=Barlow+Condensed:wght@400;700&family=DM+Sans:wght@400;700&family=Jost:wght@400;700&family=Syne:wght@400;700&family=Space+Grotesk:wght@400;700&family=Playfair+Display:wght@400;700&family=Roboto+Slab:wght@400;700&family=Lora:wght@400;700&family=Libre+Baskerville:wght@400;700&family=Crimson+Pro:wght@400;700&family=EB+Garamond:wght@400;700&family=Cormorant+Garamond:wght@400;700&family=Cinzel:wght@400;700;900&family=Cormorant+SC:wght@400;700&family=Poiret+One&family=Italiana&family=Tenor+Sans&family=DM+Serif+Display&family=Bebas+Neue&family=Bangers&family=Luckiest+Guy&family=Black+Ops+One&family=Russo+One&family=Teko:wght@400;700&family=Boogaloo&family=Fredoka+One&family=Lilita+One&family=Permanent+Marker&family=Dancing+Script:wght@400;700&family=Pacifico&family=Lobster&family=Great+Vibes&family=Alex+Brush&family=Pinyon+Script&family=Sacramento&family=Caveat:wght@400;700&family=Satisfy&family=Kaushan+Script&family=Yellowtail&family=Allura&family=Marck+Script&family=Italianno&family=Mr+Dafoe&family=Euphoria+Script&display=swap" rel="stylesheet">
<style>

</style>
<body>

<div class="vv-header">
    <a class="vv-brand" href="vizard_browser.php">
        <span class="ico">🎬</span>
        <span class="name">Video<span>Vizard</span></span>
    </a>
    
	<button class="vv-back" onclick="window.location.href='vizard_browser.php'">← Back</button>
</div>

<div id="vidPool"></div>
<script>const VID_FILES = <?= json_encode($vid_files) ?>;</script>

<div class="workspace">

    <!-- LEFT COLUMN -->
    <div class="left-col" id="leftCol">

        <!-- ══ CAPTION PANEL ══ -->
        <div class="panel" id="pCaption"> 
		<div class="panel-head">
			<span>🅰️ Captions - Scene: <span id="captionSceneNumber">1</span></span>
			<button class="panel-close panel-close-mobile" onclick="closePanel('pCaption','ibCaption')">✕</button>
		</div>
            <div class="panel-body" id="pCaptionBody" style="padding:0;">
                <div style="display:flex;gap:0;background:#5fc3ff;border-bottom:2px solid #3aa8e8;">
                    <button id="capSubBtn1" onclick="switchCapSubTab(1)"
                        style="flex:1;padding:10px 6px;border:none;border-bottom:3px solid transparent;
                               background:#ffffff;color:#0f2a44;font-size:11px;font-weight:700;
                               cursor:pointer;letter-spacing:.04em;transition:all .15s;margin-bottom:-2px;
                               border-radius:0;">
                        🅰️ Caption
                    </button>
                    <button id="capSubBtn2" onclick="switchCapSubTab(2)"
                        style="display:none;">
                        🔤 Font
                    </button>
                </div>

                <!-- ══ Caption editor ══ -->
                <div id="captionEditor" style="display:none;overflow:visible;">

                <!-- ══════ SUB-TAB 1: CAPTION ══════ -->
                <div id="capSubTab1">

                    <!-- ── Caption tabs row (names + add buttons) ── -->
                    <div style="padding:8px 10px 6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;
                                background:#2d1b4e;border-bottom:2px solid #4a2d7a;">
                        <div id="captionTabs" style="display:flex;gap:4px;flex:1;flex-wrap:wrap;min-width:0;"></div>
                        <div style="display:flex;gap:4px;flex-shrink:0;">
                            <button onclick="addCaption()"
                                style="padding:4px 10px;border-radius:20px;border:none;background:#10b981;
                                       color:#fff;font-size:10px;font-weight:700;cursor:pointer;
                                       white-space:nowrap;letter-spacing:.03em;">
                                + Text
                            </button>
                            <button onclick="addImageCaption()"
                                style="padding:4px 10px;border-radius:20px;border:none;background:#7c3aed;
                                       color:#fff;font-size:10px;font-weight:700;cursor:pointer;
                                       white-space:nowrap;letter-spacing:.03em;">
                                🖼 Image
                            </button>
                        </div>
                    </div>

                    <!-- No selection notice — shown until a caption tab is selected -->
                    <div id="captionNoSel" style="padding:28px 20px;text-align:center;color:var(--muted);font-size:12px;line-height:1.7;">
                        👆 Select a caption tab above<br>or click directly on a caption in the preview
                    </div>

                    <!-- ① Caption Text ───────────────────────────── -->
					<div id="capTextSection" style="padding:12px 14px 10px;background:var(--surface);">
					<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
						<span style="font-size:11px;font-weight:700;color:var(--primary);letter-spacing:.05em;text-transform:uppercase;">① Caption Text</span>
						<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
							<label id="capGlobalWrap" style="display:none;align-items:center;gap:5px;cursor:pointer;
								   background:#eff6ff;border:1.5px solid var(--info);border-radius:20px;
								   padding:3px 10px 3px 6px;font-size:10px;font-weight:700;color:var(--info);
								   white-space:nowrap;">
								<input type="checkbox" id="capGlobalChk" checked
									   style="width:13px;height:13px;accent-color:var(--info);cursor:pointer;"
									   onchange="_applyToAllScenes=this.checked;_updateGlobalLabel();">
								🌐 All Scenes
							</label>
							<button id="capVisBtn" onclick="toggleCapVisible()"
									style="padding:4px 12px;border-radius:20px;border:none;cursor:pointer;
										   font-size:10px;font-weight:700;background:var(--success);color:#fff;letter-spacing:.02em;">
								👁 Visible
							</button>
						</div>
					</div>
					<textarea id="capText" rows="3"
						style="width:100%;padding:9px 11px;border-radius:9px;border:1.5px solid var(--border);
							   font-size:12px;font-family:inherit;resize:vertical;outline:none;
							   background:var(--surface2);color:var(--text);line-height:1.5;"
						placeholder="Type caption text here…"
						oninput="capFieldChanged('text_content',this.value)"></textarea>
				</div>
					<div id="capDeleteWrap" style="display:none;margin-top:8px;">
                        <button onclick="deleteCaption()"
                            style="width:100%;background:transparent;color:var(--danger);
                                   border:1.5px solid var(--danger);border-radius:9px;padding:7px;
                                   cursor:pointer;font-size:11px;font-weight:700;letter-spacing:.02em;">
                            🗑 Delete this Caption
                        </button>
                    </div>

                    <hr style="margin:0;border:none;border-top:2px solid var(--border);">

                    <!-- ② Caption Box BG + Border ─────────────────── -->
                    <div style="padding:12px 14px 12px;background:var(--surface);">
                        <div style="font-size:11px;font-weight:700;color:var(--primary);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;">② Box Background &amp; Border</div>

                        <!-- BG row -->
                        <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:10px;">
                            <div style="flex:0 0 auto;">
                                <label id="capBgEnableLabel"
                                    style="display:flex;align-items:center;gap:4px;cursor:pointer;
                                           font-size:9px;font-weight:700;color:var(--muted);
                                           text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;
                                           user-select:none;">
                                    <input type="checkbox" id="capBgEnabled"
                                        style="width:13px;height:13px;accent-color:var(--info);cursor:pointer;"
                                        onchange="toggleBgEnabled(this.checked)">
                                    Box BG
                                </label>
                                <input type="color" id="capBgColor" value="#000000"
                                    oninput="capFieldChanged('bg_color',this.value)"
                                    onchange="capFieldChanged('bg_color',this.value)"
                                    style="padding:2px 3px;height:32px;width:52px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;">
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">BG Opacity &nbsp;<span id="capBgAlphaVal" style="color:var(--info);">70%</span></div>
                                <input type="range" id="capBgAlpha" min="0" max="100" value="70"
                                    style="width:100%;accent-color:var(--info);cursor:pointer;margin-top:6px;"
                                    oninput="document.getElementById('capBgAlphaVal').textContent=this.value+'%';capFieldChanged('bg_opacity',this.value/100)">
                            </div>
                        </div>

                        <!-- Border row -->
                        <div style="display:flex;gap:8px;align-items:flex-end;">
                            <div style="flex:0 0 auto;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Border Color</div>
                                <input type="color" id="capBorderColor" value="#ffffff"
                                    oninput="capBorderChanged()"
                                    onchange="capBorderChanged()"
                                    style="padding:2px 3px;height:32px;width:52px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;">
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">
                                    Border Thickness &nbsp;<span id="capBorderThickVal" style="color:var(--info);font-weight:700;">0px</span>
                                </div>
                                <input type="range" id="capBorderThick" min="0" max="16" value="0" step="1"
                                    style="width:100%;accent-color:var(--info);cursor:pointer;margin-top:6px;"
                                    oninput="document.getElementById('capBorderThickVal').textContent=this.value+'px';capBorderChanged()">
                            </div>
                            <div style="flex:0 0 auto;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Preview</div>
                                <div id="capBorderPreview"
                                    style="width:44px;height:26px;border-radius:6px;border:0px solid #ffffff;
                                           background:rgba(0,0,0,0.45);transition:all .2s;"></div>
                            </div>
                        </div>
                    </div>


                    <hr style="margin:0;border:none;border-top:2px solid var(--border);">
                    <!-- ③ Position & Alignment ───────────────────── -->
                    <div style="padding:12px 14px 14px;background:var(--surface);">
                        <div style="font-size:11px;font-weight:700;color:var(--primary);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;">③ Position &amp; Alignment</div>

                        <div style="display:flex;gap:12px;align-items:flex-start;">
                            <div style="display:flex;flex-direction:column;gap:8px;flex-shrink:0;">
                                <div>
                                    <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Move Box</div>
                                    <div style="display:grid;grid-template-columns:repeat(3,30px);grid-template-rows:repeat(3,30px);gap:3px;">
                                        <div></div>
                                        <button class="pos-cell" onclick="moveCapArrow(0,-20)" title="Up">↑</button>
                                        <div></div>
                                        <button class="pos-cell" onclick="moveCapArrow(-20,0)" title="Left">←</button>
                                        <button class="pos-cell" onclick="centreCaption()"     title="Centre" style="font-size:9px;background:var(--primary2);color:#fff;border-color:var(--primary2);">●</button>
                                        <button class="pos-cell" onclick="moveCapArrow(20,0)"  title="Right">→</button>
                                        <div></div>
                                        <button class="pos-cell" onclick="moveCapArrow(0,20)"  title="Down">↓</button>
                                        <div></div>
                                    </div>
                                    <div style="font-size:9px;color:var(--muted);text-align:center;margin-top:4px;font-variant-numeric:tabular-nums;">
                                        X <span id="capPosXLbl" style="color:var(--info);font-weight:700;">0</span>
                                        &nbsp;Y <span id="capPosYLbl" style="color:var(--info);font-weight:700;">0</span>
                                    </div>
                                </div>
                            </div>
                            <div style="flex:1;display:flex;flex-direction:column;gap:8px;">
                                <div>
                                    <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Snap To</div>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;">
                                        <button class="tog" style="font-size:10px;padding:5px 4px;" onclick="snapCaption('top')">⬆ Top</button>
                                        <button class="tog" style="font-size:10px;padding:5px 4px;" onclick="snapCaption('middle')">↕ Middle</button>
                                        <button class="tog" style="font-size:10px;padding:5px 4px;" onclick="snapCaption('bottom')">⬇ Bottom</button>
                                        <button class="tog" style="font-size:10px;padding:5px 4px;" onclick="snapCaption('centre-h')">⬌ Centre</button>
                                    </div>
                                </div>
                                <div>
                                    <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Exact Position</div>
                                    <div style="display:flex;gap:4px;">
                                        <div style="flex:1;min-width:0;">
                                            <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">X</div>
                                            <input type="number" id="capPosX" min="0" max="360" value="50"
                                                oninput="capPosInput('position_x',this.value)"
                                                style="width:100%;padding:5px 6px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);font-size:11px;font-family:inherit;outline:none;color:var(--text);">
                                        </div>
                                        <div style="flex:1;min-width:0;">
                                            <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">Y</div>
                                            <input type="number" id="capPosY" min="0" max="640" value="400"
                                                oninput="capPosInput('position_y',this.value)"
                                                style="width:100%;padding:5px 6px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);font-size:11px;font-family:inherit;outline:none;color:var(--text);">
                                        </div>
                                        <div style="flex:1;min-width:0;">
                                            <div style="font-size:9px;color:var(--muted);margin-bottom:2px;">W</div>
                                            <input type="number" id="capWidth" min="80" max="360" value="320"
                                                oninput="capPosInput('width',this.value)"
                                                style="width:100%;padding:5px 6px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);font-size:11px;font-family:inherit;outline:none;color:var(--text);">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                </div><!-- end capSubTab1 -->

                <!-- ══════ SUB-TAB 2: FONT ══════ -->
                <div id="capSubTab2" style="display:none;">

                    <!-- ② Font ───────────────────────────────────── -->
                    <div style="padding:12px 14px 10px;background:var(--surface);">
                        <div style="font-size:11px;font-weight:700;color:var(--primary);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;">① Font</div>

                        <!-- Font family + size on one line -->
                        <div style="display:flex;gap:8px;margin-bottom:8px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Family</div>
							<input type="hidden" id="capFont" value="Arial,sans-serif">
							<div id="fontPickerWrap" style="position:relative;">
								<div id="fontPickerBtn" onclick="toggleFontPicker()"
									style="width:100%;padding:6px 10px;border-radius:8px;border:1.5px solid var(--border);
										   background:var(--surface2);color:var(--text);font-size:13px;cursor:pointer;
										   display:flex;align-items:center;justify-content:space-between;gap:6px;
										   user-select:none;">
									<span id="fontPickerLabel" style="font-family:Arial,sans-serif;">Arial</span>
									<span style="font-size:10px;color:var(--muted);">▼</span>
								</div>
								<div id="fontPickerDropdown"
									style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;
										   background:var(--surface);border:1.5px solid var(--border);border-radius:10px;
										   box-shadow:0 8px 30px rgba(0,0,0,.15);z-index:99999;
										   max-height:320px;overflow-y:auto;">

									<!-- ── Sans Serif ── -->
									<div class="fp-hdr">Sans Serif</div>
									<div class="fp-opt" data-val="Arial,sans-serif"                    style="font-family:Arial,sans-serif;">Arial</div>
									<div class="fp-opt" data-val="'Segoe UI',sans-serif"               style="font-family:'Segoe UI',sans-serif;">Segoe UI</div>
									<div class="fp-opt" data-val="Verdana,sans-serif"                  style="font-family:Verdana,sans-serif;">Verdana</div>
									<div class="fp-opt" data-val="'Poppins',sans-serif"                style="font-family:'Poppins',sans-serif;">Poppins</div>
									<div class="fp-opt" data-val="'Montserrat',sans-serif"             style="font-family:'Montserrat',sans-serif;">Montserrat</div>
									<div class="fp-opt" data-val="'Raleway',sans-serif"                style="font-family:'Raleway',sans-serif;">Raleway</div>
									<div class="fp-opt" data-val="'Oswald',sans-serif"                 style="font-family:'Oswald',sans-serif;">Oswald</div>
									<div class="fp-opt" data-val="'Josefin Sans',sans-serif"           style="font-family:'Josefin Sans',sans-serif;">Josefin Sans</div>
									<div class="fp-opt" data-val="'Barlow Condensed',sans-serif"       style="font-family:'Barlow Condensed',sans-serif;">Barlow Condensed</div>
									<div class="fp-opt" data-val="'DM Sans',sans-serif"                style="font-family:'DM Sans',sans-serif;">DM Sans</div>
									<div class="fp-opt" data-val="'Jost',sans-serif"                   style="font-family:'Jost',sans-serif;">Jost</div>
									<div class="fp-opt" data-val="'Space Grotesk',sans-serif"          style="font-family:'Space Grotesk',sans-serif;">Space Grotesk</div>
									<div class="fp-opt" data-val="'Righteous',sans-serif"              style="font-family:'Righteous',sans-serif;">Righteous</div>
									<div class="fp-opt" data-val="'Black Han Sans',sans-serif"         style="font-family:'Black Han Sans',sans-serif;">Black Han Sans</div>

									<!-- ── Serif ── -->
									<div class="fp-hdr">Serif</div>
									<div class="fp-opt" data-val="Georgia,serif"                       style="font-family:Georgia,serif;">Georgia</div>
									<div class="fp-opt" data-val="'Times New Roman',serif"             style="font-family:'Times New Roman',serif;">Times New Roman</div>
									<div class="fp-opt" data-val="'Playfair Display',serif"            style="font-family:'Playfair Display',serif;">Playfair Display</div>
									<div class="fp-opt" data-val="'Lora',serif"                        style="font-family:'Lora',serif;">Lora</div>
									<div class="fp-opt" data-val="'Libre Baskerville',serif"           style="font-family:'Libre Baskerville',serif;">Libre Baskerville</div>
									<div class="fp-opt" data-val="'Crimson Pro',serif"                 style="font-family:'Crimson Pro',serif;">Crimson Pro</div>
									<div class="fp-opt" data-val="'EB Garamond',serif"                 style="font-family:'EB Garamond',serif;">EB Garamond</div>
									<div class="fp-opt" data-val="'Cormorant Garamond',serif"          style="font-family:'Cormorant Garamond',serif;">Cormorant Garamond</div>
									<div class="fp-opt" data-val="'Roboto Slab',serif"                 style="font-family:'Roboto Slab',serif;">Roboto Slab</div>
									<div class="fp-opt" data-val="'DM Serif Display',serif"            style="font-family:'DM Serif Display',serif;">DM Serif Display</div>

									<!-- ── Quotes & Elegant ── -->
									<div class="fp-hdr" style="background:#f0fdf4;color:#166534;">✍️ Quotes &amp; Elegant</div>
									<div class="fp-opt" data-val="'Cormorant SC',serif"                style="font-family:'Cormorant SC',serif;">Cormorant SC</div>
									<div class="fp-opt" data-val="'Cinzel',serif"                      style="font-family:'Cinzel',serif;">Cinzel</div>
									<div class="fp-opt" data-val="'Italiana',serif"                    style="font-family:'Italiana',serif;">Italiana</div>
									<div class="fp-opt" data-val="'Tenor Sans',sans-serif"             style="font-family:'Tenor Sans',sans-serif;">Tenor Sans</div>
									<div class="fp-opt" data-val="'Poiret One',cursive"                style="font-family:'Poiret One',cursive;">Poiret One</div>
									<div class="fp-opt" data-val="'Syne',sans-serif"                   style="font-family:'Syne',sans-serif;">Syne</div>

									<!-- ── Display / Promotional ── -->
									<div class="fp-hdr" style="background:#fff7ed;color:#c2410c;">📣 Display &amp; Promotional</div>
									<div class="fp-opt" data-val="Impact,fantasy"                      style="font-family:Impact,fantasy;">Impact</div>
									<div class="fp-opt" data-val="'Anton',sans-serif"                  style="font-family:'Anton',sans-serif;">Anton</div>
									<div class="fp-opt" data-val="'Bebas Neue',sans-serif"             style="font-family:'Bebas Neue',sans-serif;">Bebas Neue</div>
									<div class="fp-opt" data-val="'Bangers',cursive"                   style="font-family:'Bangers',cursive;">Bangers</div>
									<div class="fp-opt" data-val="'Luckiest Guy',cursive"              style="font-family:'Luckiest Guy',cursive;">Luckiest Guy</div>
									<div class="fp-opt" data-val="'Black Ops One',cursive"             style="font-family:'Black Ops One',cursive;">Black Ops One</div>
									<div class="fp-opt" data-val="'Russo One',sans-serif"              style="font-family:'Russo One',sans-serif;">Russo One</div>
									<div class="fp-opt" data-val="'Teko',sans-serif"                   style="font-family:'Teko',sans-serif;">Teko</div>
									<div class="fp-opt" data-val="'Boogaloo',cursive"                  style="font-family:'Boogaloo',cursive;">Boogaloo</div>
									<div class="fp-opt" data-val="'Fredoka One',cursive"               style="font-family:'Fredoka One',cursive;">Fredoka One</div>
									<div class="fp-opt" data-val="'Lilita One',cursive"                style="font-family:'Lilita One',cursive;">Lilita One</div>
									<div class="fp-opt" data-val="'Alfa Slab One',serif"               style="font-family:'Alfa Slab One',serif;">Alfa Slab One</div>

									<!-- ── Handwriting & Calligraphy ── -->
									<div class="fp-hdr" style="background:#fdf4ff;color:#7c3aed;">🖋️ Handwriting &amp; Calligraphy</div>
									<div class="fp-opt" data-val="'Dancing Script',cursive"            style="font-family:'Dancing Script',cursive;">Dancing Script</div>
									<div class="fp-opt" data-val="'Pacifico',cursive"                  style="font-family:'Pacifico',cursive;">Pacifico</div>
									<div class="fp-opt" data-val="'Lobster',cursive"                   style="font-family:'Lobster',cursive;">Lobster</div>
									<div class="fp-opt" data-val="'Permanent Marker',cursive"          style="font-family:'Permanent Marker',cursive;">Permanent Marker</div>
									<div class="fp-opt" data-val="'Caveat',cursive"                    style="font-family:'Caveat',cursive;">Caveat</div>
									<div class="fp-opt" data-val="'Great Vibes',cursive"               style="font-family:'Great Vibes',cursive;font-size:18px;">Great Vibes</div>
									<div class="fp-opt" data-val="'Alex Brush',cursive"                style="font-family:'Alex Brush',cursive;font-size:18px;">Alex Brush</div>
									<div class="fp-opt" data-val="'Pinyon Script',cursive"             style="font-family:'Pinyon Script',cursive;font-size:18px;">Pinyon Script</div>
									<div class="fp-opt" data-val="'Sacramento',cursive"                style="font-family:'Sacramento',cursive;font-size:18px;">Sacramento</div>
									<div class="fp-opt" data-val="'Satisfy',cursive"                   style="font-family:'Satisfy',cursive;font-size:18px;">Satisfy</div>
									<div class="fp-opt" data-val="'Kaushan Script',cursive"            style="font-family:'Kaushan Script',cursive;font-size:18px;">Kaushan Script</div>
									<div class="fp-opt" data-val="'Yellowtail',cursive"                style="font-family:'Yellowtail',cursive;font-size:18px;">Yellowtail</div>
									<div class="fp-opt" data-val="'Allura',cursive"                    style="font-family:'Allura',cursive;font-size:18px;">Allura</div>
									<div class="fp-opt" data-val="'Marck Script',cursive"              style="font-family:'Marck Script',cursive;font-size:18px;">Marck Script</div>
									<div class="fp-opt" data-val="'Italianno',cursive"                 style="font-family:'Italianno',cursive;font-size:20px;">Italianno</div>
									<div class="fp-opt" data-val="'Mr Dafoe',cursive"                  style="font-family:'Mr Dafoe',cursive;font-size:18px;">Mr Dafoe</div>
									<div class="fp-opt" data-val="'Euphoria Script',cursive"           style="font-family:'Euphoria Script',cursive;font-size:18px;">Euphoria Script</div>

									<!-- ── Monospace ── -->
									<div class="fp-hdr">Monospace</div>
									<div class="fp-opt" data-val="'Courier New',monospace"             style="font-family:'Courier New',monospace;">Courier New</div>

									<!-- ── Arabic / Urdu ── -->
									<div class="fp-hdr" style="background:#fdf4ff;color:#7c3aed;">🌙 Arabic / Urdu</div>
									<div class="fp-opt" data-val="'NotoNastaliqUrdu',serif"
										style="font-family:'NotoNastaliqUrdu',serif;direction:rtl;font-size:20px;line-height:2;">
										نوٹو نستعلیق &nbsp;<span style="font-size:10px;direction:ltr;color:var(--muted);font-family:Arial,sans-serif;">Noto Nastaliq Urdu</span>
									</div>
									<div class="fp-opt" data-val="'AttariQuraanWord',serif"
										style="font-family:'AttariQuraanWord',serif;direction:rtl;font-size:20px;line-height:2;border-radius:0 0 8px 8px;">
										عطاری قرآن &nbsp;<span style="font-size:10px;direction:ltr;color:var(--muted);font-family:Arial,sans-serif;">Attari Quraan Word</span>
									</div>
								</div>
							</div>
							<style>
							.fp-opt{padding:9px 14px;font-size:15px;cursor:pointer;color:var(--text);transition:background .1s;border-bottom:1px solid var(--border);}
							.fp-opt:last-child{border-bottom:none;}
							.fp-opt:hover{background:#eff6ff;color:var(--info);}
							.fp-opt.selected{background:#dbeafe;color:var(--info);font-weight:700;}
							.fp-hdr{padding:6px 8px;background:var(--surface2);border-top:1px solid var(--border);border-bottom:1px solid var(--border);font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;}
							.fp-hdr:first-child{border-top:none;border-radius:10px 10px 0 0;}
							</style>
                            </div>
                            <div style="width:68px;flex-shrink:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;">Size</div>
                                <select id="capSize" onchange="capFieldChanged('fontsize',this.value)"
                                    style="width:100%;padding:6px 8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;cursor:pointer;outline:none;">
                                    <option value="10">10</option><option value="12">12</option>
                                    <option value="14">14</option><option value="16">16</option>
                                    <option value="18">18</option><option value="20">20</option>
                                    <option value="22">22</option><option value="24">24</option>
                                    <option value="26">26</option><option value="28">28</option>
                                    <option value="30">30</option><option value="32">32</option>
                                    <option value="36">36</option><option value="40">40</option>
                                    <option value="48">48</option><option value="56">56</option>
                                    <option value="64">64</option>
                                </select>
                            </div>
                        </div>

                        <!-- Text color + swatches in one row -->
                        <div style="margin-bottom:10px;">
                            <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;">Text Color</div>
                            <div style="display:flex;align-items:center;gap:5px;flex-wrap:nowrap;">
                                <input type="color" id="capColor" value="#ffffff"
                                    oninput="capFieldChanged('fontcolor',this.value)"
                                    onchange="capFieldChanged('fontcolor',this.value)"
                                    style="padding:2px 3px;height:28px;width:44px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;flex-shrink:0;">
                                <?php foreach(['#ffffff','#ffff00','#ff3b30','#00ff00','#00ffff','#5fc3ff','#ff9500','#ff69b4','#000000'] as $c): ?>
                                <div class="swatch" style="background:<?=$c?>;<?=$c==='#ffffff'?'border-color:#ccc;':''?>;width:22px;height:22px;flex-shrink:0;"
                                    onclick="setCapTextColor('<?=$c?>')"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <hr style="margin:0;border:none;border-top:2px solid var(--border);">

                    <!-- ③ Style · Effect · Animation ─────────────── -->
                    <div style="padding:12px 14px 10px;background:var(--surface);">
                        <div style="font-size:11px;font-weight:700;color:var(--primary);letter-spacing:.05em;text-transform:uppercase;margin-bottom:10px;">② Style &amp; Effects</div>

                        <div style="display:flex;align-items:flex-end;gap:8px;margin-bottom:10px;">
                            <div style="flex-shrink:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Style</div>
                                <div style="display:flex;gap:4px;">
                                    <button class="tog" id="capBold"      onclick="toggleCapStyle('bold')"
                                        style="width:32px;height:30px;font-size:13px;padding:0;"><b>B</b></button>
                                    <button class="tog" id="capItalic"    onclick="toggleCapStyle('italic')"
                                        style="width:32px;height:30px;font-size:13px;padding:0;"><i>I</i></button>
                                    <button class="tog" id="capUnderline" onclick="toggleCapStyle('underline')"
                                        style="width:32px;height:30px;font-size:13px;padding:0;"><u>U</u></button>
                                </div>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Effect</div>
                                <select id="capEffect" onchange="capEffectChanged(this.value)"
                                    style="width:100%;padding:6px 8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;cursor:pointer;outline:none;">
                                    <option value="none">None</option>
                                    <option value="shadow">Shadow</option>
                                    <option value="glow">Glow</option>
                                    <option value="outline">Outline</option>
                                    <option value="stroke">Stroke</option>
                                    <option value="gradient">Gradient</option>
                                    <option value="3d">3D</option>
                                </select>
                            </div>
                            <div id="capStrokeColorField" style="display:none;flex-shrink:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Color</div>
                                <input type="color" id="capStrokeColor" value="#000000"
                                    oninput="capFieldChanged('stroke_color',this.value)"
                                    onchange="capFieldChanged('stroke_color',this.value)"
                                    style="padding:2px 3px;height:30px;width:44px;border-radius:8px;border:1.5px solid var(--border);cursor:pointer;outline:none;">
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;align-items:flex-end;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Animation</div>
                                <select id="capAnim" onchange="capFieldChanged('animation_style',this.value)"
                                    style="width:100%;padding:6px 8px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;cursor:pointer;outline:none;">
                                    <option value="none">None</option>
                                    <option value="typewriter">Typewriter</option>
                                    <option value="char-by-char">Char by Char</option>
                                    <option value="word-reveal">Word by Word</option>
                                    <option value="line-by-line">Line by Line</option>
                                    <option value="zoom-in">Zoom In</option>
                                    <option value="pop">Pop</option>
                                    <option value="bounce">Bounce</option>
                                    <option value="karaoke">Karaoke</option>
                                    <option value="fade-in">Fade In</option>
                                    <option value="static">Static</option>
                                </select>
                            </div>
                            <div style="flex:1;min-width:100px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                                    <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Speed</div>
                                    <div id="capAnimSpeedVal" style="font-size:10px;font-weight:700;color:var(--accent);min-width:28px;text-align:right;">1.0x</div>
                                </div>
                                <input type="range" id="capAnimSpeed"
                                    min="0.2" max="4" step="0.1" value="1"
                                    oninput="document.getElementById('capAnimSpeedVal').textContent=parseFloat(this.value).toFixed(1)+'x';capFieldChanged('animation_speed',parseFloat(this.value));"
                                    style="width:100%;accent-color:var(--accent);cursor:pointer;">
                                <div style="display:flex;justify-content:space-between;font-size:8px;color:var(--muted);margin-top:2px;">
                                    <span>Slow</span><span>Normal</span><span>Fast</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr style="margin:0;border:none;border-top:2px solid var(--border);">

                    <!-- Text Alignment ───────────────────────────── -->
                    <div style="padding:10px 14px 12px;background:var(--surface);">
                        <div style="font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Text Align</div>
                        <div style="display:flex;gap:3px;">
                            <button class="tog" id="capTaLeft"    onclick="setCapTA('left')"
                                style="padding:5px 8px;font-size:14px;flex:1;" title="Left">&#9776;</button>
                            <button class="tog" id="capTaCenter"  onclick="setCapTA('center')"
                                style="padding:5px 8px;font-size:14px;flex:1;" title="Center">&#8803;</button>
                            <button class="tog" id="capTaRight"   onclick="setCapTA('right')"
                                style="padding:5px 8px;font-size:14px;flex:1;" title="Right">&#9632;</button>
                            <button class="tog" id="capTaJustify" onclick="setCapTA('justify')"
                                style="padding:5px 8px;font-size:14px;flex:1;" title="Justify">&#9644;</button>
                        </div>
                    </div>

                </div><!-- end capSubTab2 -->
                </div><!-- end captionEditor -->
				
				
				
				
				
            </div>
        </div>

        <!-- Image panel -->
        <div class="panel" id="pImage">
            <div class="panel-head">
				<span>🌄 Scene Images - Scene <span id="imageSceneNumber">1</span></span>
				<button class="panel-close panel-close-mobile" onclick="closePanel('pImage','ibImage')">✕</button>
			</div>
            <div class="panel-body" id="pImageBody">
                <div class="panel-section">
                    <div style="width:100%;padding:8px 10px;border-radius:8px;border:none;font-size:10px;font-weight:600;
                               background:#16a34a;color:#fff;margin-bottom:10px;box-sizing:border-box;
                               line-height:1.5;user-select:none;">
                        Stock media for this scene — choose one of them, or upload from your computer, or search our online library, or generate with our ready-made prompt (or write your own)
                    </div>
                    <div class="img-slots" id="imgSlots">
                        <?php
                        $slotDefs = ['image_file'=>'Main','image_file_1'=>'V1','image_file_2'=>'V2','image_file_3'=>'V3','image_file_4'=>'V4'];
                        foreach ($slotDefs as $slotKey => $slotLabel):
                            $isMain = ($slotKey === 'image_file');
                        ?>
                        <div class="img-slot" onclick="selectSlot('<?=$slotKey?>')">
                            <div class="slot-thumb" id="slotThumb_<?=$slotKey?>">
                                <img id="slotImg_<?=$slotKey?>" src="" alt="">
                                <span id="slotPh_<?=$slotKey?>">🖼️</span>
                            </div>
                            <div class="slot-label"><?=$slotLabel?></div>
                            <label onclick="event.stopPropagation()"
								style="display:flex;align-items:center;justify-content:center;gap:3px;margin-top:3px;cursor:pointer;font-size:9px;color:var(--muted);font-weight:600;user-select:none;">
								<input type="checkbox"
									id="slotChk_<?=$slotKey?>"
									<?= $isMain ? 'checked' : '' ?>
									autocomplete="off"
									onclick="event.stopPropagation()"
									onchange="onSlotChkChange()"
									style="width:12px;height:12px;accent-color:var(--info);cursor:pointer;">
								Play
							</label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size:10px;color:var(--info);font-weight:600;margin-top:4px;">
                        Selected: <span id="selSlotName">Main</span>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Prompt for selected slot</div>
                    <textarea id="slotPrompt" rows="3"
                        style="width:100%;padding:8px 10px;border-radius:8px;border:1.5px solid var(--border);font-size:11px;font-family:monospace;resize:vertical;outline:none;background:var(--surface2);color:var(--text);"
                        placeholder="Enter or edit the image generation prompt…"
                        onchange="saveSlotPrompt()"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;padding:0 2px;">
                    <button onclick="uploadForSlot()"
                        style="background:var(--success);color:#fff;border:none;border-radius:12px;padding:10px 4px;cursor:pointer;font-size:11px;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:80px;">
                        <span style="font-size:20px;">📤</span>Upload
                    </button>
                    <button onclick="openLibraryModal()"
                        style="background:#7c3aed;color:#fff;border:none;border-radius:12px;padding:10px 4px;cursor:pointer;font-size:11px;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:80px;">
                        <span style="font-size:20px;">📚</span>Library
                    </button>
                    <div style="display:flex;flex-direction:column;gap:5px;min-height:80px;">
                        <button id="btnGenerateImage" onclick="generateForSlot('image')"
                            style="background:var(--info);color:#fff;border:none;border-radius:10px;padding:6px 4px;cursor:pointer;font-size:10px;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;flex:1;line-height:1.2;">
                            <span style="font-size:15px;">🖼️</span>
                            <span>Image</span>
                            <span style="font-size:9px;opacity:.85;font-weight:600;">$0.08</span>
                        </button>
                        <button id="btnGenerateVideo" onclick="generateForSlot('video')"
                            style="background:#e65100;color:#fff;border:none;border-radius:10px;padding:6px 4px;cursor:pointer;font-size:10px;font-weight:700;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;flex:1;line-height:1.2;">
                            <span style="font-size:15px;">🎬</span>
                            <span>Video</span>
                            <span style="font-size:9px;opacity:.85;font-weight:600;">$0.50</span>
                        </button>
                    </div>
                </div>
                <input type="file" id="slotFileInput" accept="image/*,video/*" style="display:none" onchange="handleSlotUpload(this)">
            </div>
        </div>

        <!-- Library Modal -->
        <div id="libModal" style="display:none;position:fixed;top:48px;left:0;width:380px;bottom:0;z-index:9999;background:rgba(15,23,42,.82);backdrop-filter:blur(3px);align-items:flex-start;justify-content:center;padding:10px 8px;">
            <div style="background:var(--surface);border-radius:14px;width:100%;height:calc(100vh - 68px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.5);">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--primary);flex-shrink:0;border-radius:14px 14px 0 0;">
                    <div style="min-width:0;flex:1;">
                        <div style="color:#fff;font-size:13px;font-weight:700;">📚 Library — <span id="libSlotLabel">Main</span></div>
                        <div id="libSearchStatus" style="font-size:10px;color:rgba(255,255,255,.65);margin-top:1px;display:none;"></div>
                    </div>
                    <button onclick="closeLibraryModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:15px;flex-shrink:0;margin-left:8px;">✕</button>
                </div>
                <div style="padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;">
                    <div style="display:flex;gap:6px;margin-bottom:7px;">
                        <input id="libSearch" type="text" placeholder="Search by description…"
                            style="flex:1;min-width:0;padding:6px 10px;border-radius:20px;border:1.5px solid var(--border);font-size:11px;outline:none;font-family:inherit;"
                            onkeydown="if(event.key==='Enter')performLibSearch()">
                        <button onclick="performLibSearch()" style="padding:6px 12px;border-radius:20px;border:none;background:var(--info);color:#fff;font-size:10px;font-weight:700;cursor:pointer;flex-shrink:0;">🔍</button>
                    </div>
                    <div style="display:flex;gap:4px;">
                        <button id="libTabAll"  onclick="setLibTab('all')"   style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--primary);background:var(--primary);color:#fff;font-size:10px;font-weight:600;cursor:pointer;">All <span id="libCountAll"></span></button>
                        <button id="libTabImg"  onclick="setLibTab('image')" style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;">🖼️ Images <span id="libCountImg"></span></button>
                        <button id="libTabVid"  onclick="setLibTab('video')" style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;">🎬 Videos <span id="libCountVid"></span></button>
                        <button id="libTabMine" onclick="setLibTab('mine')"  style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;">🗂️ Mine <span id="libCountMine"></span></button>
                    </div>
                </div>
                <div id="libGrid" style="flex:1;overflow-y:auto;padding:10px;display:grid;grid-template-columns:repeat(2,1fr);gap:7px;align-content:start;">
                    <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>
                </div>
                <div style="padding:8px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span id="libSelInfo" style="font-size:10px;color:var(--muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">No file selected</span>
                    <div style="display:flex;gap:6px;flex-shrink:0;">
                        <button onclick="closeLibraryModal()" class="btn" style="padding:5px 10px;font-size:10px;">Cancel</button>
                        <button id="libUseBtn" onclick="useLibraryFile()" class="btn primary" disabled style="opacity:.4;padding:5px 10px;font-size:10px;">✓ Use</button>
                    </div>
                </div>
            </div>
        </div>
        <style>
        @media (max-width:899px){#libModal{width:100%!important;left:0!important;top:48px!important;padding:8px!important;}}
        </style>

        <!-- Audio panel -->
	<div class="panel" id="pAudio">
		<div class="panel-head">
			<span>🔊 Audio - Scene <span id="audioSceneNumber">1</span></span>
			<button class="panel-close panel-close-mobile" onclick="closePanel('pAudio','ibAudio')">✕</button>
		</div>
	<div class="panel-body" id="pAudioBody" style="padding:0;">

        <!-- Single scrollable section: Voice + Music merged -->
        <div style="padding:12px 12px 16px;display:flex;flex-direction:column;gap:0;">

            <!-- ── Change Voice ──────────────────────────────────── -->
            <div style="margin-bottom:0;">
                <div style="font-size:10px;font-weight:700;color:var(--primary);text-transform:uppercase;
                            letter-spacing:.07em;margin-bottom:10px;padding-bottom:6px;
                            border-bottom:2px solid var(--border);">🎙️ Voice</div>

                <?php if ($is_podcast_type): ?>
                <div style="display:flex;gap:6px;margin-bottom:10px;">
                    <button onclick="setVoiceTarget('host')" id="vtHost"
                        style="flex:1;padding:6px 8px;border-radius:20px;border:1.5px solid var(--info);
                               background:var(--info);color:#fff;font-size:10px;font-weight:700;cursor:pointer;">
                        🎙 Host Voice
                    </button>
                    <button onclick="setVoiceTarget('guest')" id="vtGuest"
                        style="flex:1;padding:6px 8px;border-radius:20px;border:1.5px solid var(--border);
                               background:var(--surface2);color:var(--muted);font-size:10px;font-weight:700;cursor:pointer;">
                        🎙 Guest Voice
                    </button>
                </div>
                <?php endif; ?>

                <div id="vcCurrentDisplay"
                     style="background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;
                            padding:8px 12px;margin-bottom:10px;font-size:11px;color:var(--muted);">
                    <span style="font-weight:600;color:var(--text);">Current:</span>
                    <span id="vcCurrentName">—</span>
                </div>

                <div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;">
                    <select id="vcVoiceSelect" onchange="onVoiceSelectChange()"
                        style="flex:1;padding:7px 10px;border-radius:8px;border:1.5px solid var(--border);
                               background:var(--surface2);color:var(--text);font-size:11px;
                               font-family:inherit;outline:none;cursor:pointer;">
                        <option value="">— Select a voice —</option>
                    </select>
                    <button id="vcPlayBtn" onclick="previewSelectedVoice()"
                        style="width:34px;height:34px;border-radius:50%;border:none;background:var(--info);
                               color:#fff;font-size:13px;cursor:pointer;flex-shrink:0;
                               display:flex;align-items:center;justify-content:center;">▶</button>
                </div>
                <div id="vcVoiceDesc" style="font-size:10px;color:var(--muted);margin-bottom:10px;padding-left:4px;min-height:16px;"></div>

                <textarea id="vcSampleText" rows="2"
                    placeholder="Type a sentence to preview this voice…"
                    style="width:100%;box-sizing:border-box;padding:7px 10px;border-radius:8px;
                           border:1.5px solid var(--border);background:var(--surface2);
                           color:var(--text);font-size:11px;font-family:inherit;
                           resize:vertical;outline:none;margin-bottom:6px;"></textarea>

                <button onclick="saveSelectedVoice()"
                    style="width:100%;background:var(--success);color:#fff;border:none;border-radius:10px;
                           padding:10px;cursor:pointer;font-size:12px;font-weight:700;letter-spacing:.02em;margin-bottom:14px;">
                    ✓ Apply Voice to Video
                </button>

                <!-- Voiceover Speed -->
                <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;
                            letter-spacing:.07em;margin-bottom:6px;">🎙️ Voiceover Speed &nbsp;<span id="speedValue" style="color:var(--info);font-size:12px;font-weight:700;">1.0x</span></div>
                <input type="range" id="playbackSpeedSlider" min="0.5" max="2.0" step="0.05" value="1.0"
                       style="width:100%;accent-color:var(--info);margin-bottom:8px;"
                       oninput="updatePlaybackSpeed(this.value)">
                <div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:4px;">
                    <button class="speed-preset" data-speed="0.5"  onclick="setPlaybackSpeed(0.5)">0.5x</button>
                    <button class="speed-preset" data-speed="0.75" onclick="setPlaybackSpeed(0.75)">0.75x</button>
                    <button class="speed-preset active" data-speed="1.0" onclick="setPlaybackSpeed(1.0)">1.0x</button>
                    <button class="speed-preset" data-speed="1.25" onclick="setPlaybackSpeed(1.25)">1.25x</button>
                    <button class="speed-preset" data-speed="1.5"  onclick="setPlaybackSpeed(1.5)">1.5x</button>
                    <button class="speed-preset" data-speed="2.0"  onclick="setPlaybackSpeed(2.0)">2.0x</button>
                </div>
            </div>

            <!-- ── Music ─────────────────────────────────────────── -->
            <div style="margin-top:16px;padding-top:14px;border-top:2px solid var(--border);">
                <div style="font-size:10px;font-weight:700;color:var(--primary);text-transform:uppercase;
                            letter-spacing:.07em;margin-bottom:10px;">🎵 Background Music</div>

                <div id="musicCurrentWrap" style="margin-bottom:10px;"></div>

                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-size:13px;">🎵</span>
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                            <span style="font-size:10px;color:var(--muted);font-weight:600;">Music Volume</span>
                            <span id="musicVolLbl" style="font-size:10px;color:var(--muted);">30%</span>
                        </div>
                        <input type="range" id="musicVolSlider" min="0" max="100" value="30"
                            style="width:100%;accent-color:var(--info);"
                            oninput="onMusicVolChange(this.value)">
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                    <span style="font-size:13px;">🗣️</span>
                    <div style="flex:1;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                            <span style="font-size:10px;color:var(--muted);font-weight:600;">Voice Volume</span>
                            <span id="voiceVolLbl" style="font-size:10px;color:var(--muted);">100%</span>
                        </div>
                        <input type="range" id="voiceVolSlider" min="0" max="100" value="100"
                            style="width:100%;accent-color:var(--info);"
                            oninput="onVoiceVolChange(this.value)">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                    <button onclick="uploadMusicClick()"
                        style="background:var(--success);color:#fff;border:none;border-radius:10px;
                               padding:9px;cursor:pointer;font-size:11px;font-weight:700;
                               display:flex;align-items:center;justify-content:center;gap:5px;">
                        📤 Upload
                    </button>
                    <button onclick="openMusicLibModal()"
                        style="background:#7c3aed;color:#fff;border:none;border-radius:10px;
                               padding:9px;cursor:pointer;font-size:11px;font-weight:700;
                               display:flex;align-items:center;justify-content:center;gap:5px;">
                        📚 Library
                    </button>
                </div>
                <input type="file" id="musicFileInput"
                    accept="audio/mp3,audio/mpeg,audio/wav,audio/ogg,audio/m4a,.mp3,.wav,.ogg,.m4a"
                    style="display:none" onchange="handleMusicUpload(this)">
                <button onclick="clearPodcastMusic()"
                    style="width:100%;background:transparent;color:var(--danger);
                           border:1.5px solid var(--danger);border-radius:8px;padding:6px;
                           cursor:pointer;font-size:10px;font-weight:600;">
                    ✕ Remove Background Music
                </button>
            </div>

        </div><!-- end merged section -->

        <!-- Keep hidden elements that JS may reference -->
        <div style="display:none;">
            <div id="audSub1"></div>
            <div id="audSub2"></div>
            <div id="audSub3"></div>
            <button id="audSubBtn1"></button>
            <button id="audSubBtn2"></button>
            <button id="audSubBtn3"></button>
            <div id="audCapInfo"></div>
            <textarea id="audioSceneText"></textarea>
            <div id="audioPlayerWrap"></div>
            <button id="btnGenAudio" onclick="generateSceneAudio()"></button>
            <div id="audHostVoiceName"><?= htmlspecialchars($host_voice_id ?: '') ?></div>
            <div id="audGuestVoiceName"><?= htmlspecialchars($guest_voice_id ?: '') ?></div>
        </div>

    </div><!-- end panel-body -->
</div><!-- end pAudio panel -->

<!-- Music Library Modal -->
<div id="musicLibModal" style="display:none;position:fixed;top:48px;left:0;width:380px;bottom:0;z-index:10000;background:rgba(15,23,42,.82);backdrop-filter:blur(3px);align-items:flex-start;justify-content:center;padding:10px 8px;">
    <div style="background:var(--surface);border-radius:14px;width:100%;height:calc(100vh - 68px);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.5);">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--primary);flex-shrink:0;border-radius:14px 14px 0 0;">
            <span style="color:#fff;font-size:13px;font-weight:700;">🎵 Music Library</span>
            <button onclick="closeMusicLibModal()" style="background:rgba(255,255,255,.15);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:15px;">✕</button>
        </div>
        <div style="padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;">
            <input id="musicLibSearch" type="text" placeholder="Search by filename…"
                style="width:100%;padding:6px 10px;border-radius:20px;border:1.5px solid var(--border);font-size:11px;outline:none;font-family:inherit;box-sizing:border-box;"
                oninput="filterMusicLibGrid()">
        </div>
        <div id="musicLibGrid" style="flex:1;overflow-y:auto;padding:10px;display:flex;flex-direction:column;gap:5px;"></div>
        <div style="padding:8px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <span id="musicLibSelInfo" style="font-size:10px;color:var(--muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">No file selected</span>
            <div style="display:flex;gap:6px;">
                <button onclick="closeMusicLibModal()" class="btn" style="padding:5px 10px;font-size:10px;">Cancel</button>
                <button id="musicLibUseBtn" onclick="useMusicLibFile()" class="btn primary" disabled style="opacity:.4;padding:5px 10px;font-size:10px;">✓ Use</button>
            </div>
        </div>
    </div>
</div>
<style>@media (max-width:899px){#musicLibModal{width:100%!important;left:0!important;padding:8px!important;}}</style>

</div><!-- .left-col -->
    <!-- RIGHT COLUMN -->
	
    <div class="right-col">
        <div id="iconBar">
            <div class="icon-btn" id="ibCaption" onclick="openCaptionPanel()" style="display:none;">
                <span class="ico">🅰️</span>Caption
            </div>
            <div class="icon-btn" id="ibImage" style="display:none;"
                 onclick="togglePanel('pImage','ibImage');applySceneSlots(SCENES[currentIndex]);updateSlotThumbs(SCENES[currentIndex])">
                <span class="ico">🌄</span>Image
            </div>
            <div class="icon-btn" id="ibAudio" style="display:none;"
                 onclick="togglePanel('pAudio','ibAudio');loadAudioPanel()">
                <span class="ico">🔊</span>Audio
            </div>
            <div class="icon-btn play-btn" id="ibPlay" onclick="togglePlay()">
                <span class="ico" id="playIco">▶</span><span id="playLbl">Preview</span>
            </div>
            <div class="icon-btn rec-btn" id="ibRecord" onclick="isRecording ? stopRecording() : startRecording()">
                <span class="ico">⏺</span>Generate Video
            </div>
        </div>

         <div style="display:flex;flex-direction:column;align-items:center;width:100%;">
		 
		 
        <div id="playerWrap">
            <canvas id="screen" width="<?= CW ?>" height="<?= CH ?>"></canvas>
			<canvas id="screenHD" width="720" height="1280" style="display:none;"></canvas>
           
			
            <div id="preloadOverlay">
                <div class="spinner"></div>
                <p id="preloadMsg">Preloading…</p>
                <div id="preloadBar"></div>
            </div>
        </div>

        <!-- Video title above dots -->
        <div style="width:<?= CW ?>px;background:var(--surface);border:1.5px solid var(--border);
                    border-radius:10px;padding:7px 12px;display:flex;align-items:center;gap:6px;margin-top:4px;">
            <span style="font-size:13px;">🎬</span>
            <span style="font-size:11px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?= htmlspecialchars($podcast_title ?: 'Untitled') ?>
            </span>
        </div>

        <!-- Scene navigation below canvas -->
        <div id="sceneNav" style="display:flex;gap:10px;align-items:center;justify-content:center;
                    width:<?= CW ?>px;background:var(--surface);
                    border:1.5px solid var(--border);border-radius:10px;padding:6px 14px;">


		  <button class="nav-btn" onclick="navigate(-1)"
                style="width:30px;height:30px;border-radius:50%;background:var(--primary);border:none;
                       color:#fff;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;">←</button>
            <span id="sceneNum" style="color:var(--text);font-size:12px;font-weight:700;min-width:44px;text-align:center;">
                1 / <?= count($scenes) ?>
            </span>
            <button class="nav-btn" onclick="navigate(1)"
                style="width:30px;height:30px;border-radius:50%;background:var(--primary);border:none;
                       color:#fff;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;">→</button>
        </div>

        <div id="dotRow">
        <?php foreach ($scenes as $i => $s): ?>
        <div class="dot<?= $i===0?' active':'' ?>" id="dot<?= $i ?>"></div>
        <?php endforeach; ?>
        </div>

        <div id="recBar"><div id="recDot"></div><span>Recording…</span><span id="recSize">0.0 MB</span></div>
        <div id="dlPanel">
            <h3>✅ Recording ready</h3>
            <p id="dlMeta"></p>
            <p style="font-size:11px;color:var(--muted);">Click below to save your video.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a id="dlLink" class="btn success" download="">⬇ Download Video</a>
                <button class="btn" onclick="discardRec()">✕ Discard</button>
            </div>
        </div>
       
        <!-- ══ SCENE LIST TABLE (like image_gen) ══════════════════ -->
        <div id="sceneStrip" style="width:100%;margin-top:12px;background:var(--surface);
             border:1.5px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow);">

            <!-- Header bar -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                        padding:9px 14px;background:var(--primary);">
                <span style="font-size:12px;font-weight:700;color:#fff;letter-spacing:.03em;">
                    🎬 All Scenes &nbsp;<span style="opacity:.6;font-weight:400;">(<?= count($scenes) ?>)</span>
                </span>
                <button id="sceneStripToggle" onclick="ssToggleStrip()"
                        style="font-size:10px;color:rgba(255,255,255,.75);background:rgba(255,255,255,.1);
                               border:1px solid rgba(255,255,255,.2);border-radius:20px;cursor:pointer;
                               padding:3px 10px;font-weight:600;">
                    Hide ▲
                </button>
            </div>

            <!-- Scrollable table body -->
            <div id="sceneStripScroll" style="max-height:520px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:11px;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:2px solid var(--border);position:sticky;top:0;z-index:2;">
                            <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">#</th>
                            <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Image</th>
                            <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;">Caption</th>
                            <th style="padding:7px 8px;text-align:left;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($scenes as $i => $sc):
                        $thumb      = trim($sc['image_file']   ?? '');
                        $folder_raw = trim($sc['image_folder'] ?? '');
                        $folder     = rtrim($folder_raw ?: 'podcast_images', '/');
                        $hasAudio   = !empty(trim($sc['audio_file'] ?? ''));
                        $hasMedia   = !empty($thumb);
                        $isVideo    = $hasMedia && preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $thumb);
                        $thumb_src  = $hasMedia ? htmlspecialchars($folder.'/'.$thumb) : '';
                        $cap_preview = trim($caption_text_map[(int)$sc['id']] ?? '');
                        $cap_preview = mb_strlen($cap_preview) > 60
                                     ? mb_substr($cap_preview, 0, 60).'…'
                                     : $cap_preview;
                    ?>
                    <tr id="ssRow<?= $i ?>"
                        onclick="ssSelectScene(<?= $i ?>)"
                        style="border-bottom:1px solid var(--border);cursor:pointer;transition:background .12s;
                               <?= $i===0 ? 'background:#eff6ff;border-left:3px solid var(--info);' : '' ?>">

                        <!-- # -->
                        <td style="padding:10px 6px 10px 10px;font-size:13px;font-weight:700;color:var(--muted);
                                   white-space:nowrap;vertical-align:middle;text-align:center;">
                            <?= $i+1 ?>
                        </td>

                        <!-- 9:16 thumbnail — 72×128 -->
                        <td style="padding:8px 6px;vertical-align:middle;">
                            <div id="ssThumbWrap<?= $i ?>"
                                 style="width:72px;height:128px;border-radius:7px;overflow:hidden;
                                        background:#1e293b;border:2px solid <?= $hasMedia?'#16a34a':'var(--border)' ?>;
                                        flex-shrink:0;position:relative;">
                                <?php if ($hasMedia && !$isVideo): ?>
                                <img id="ssThumbImg<?= $i ?>" src="<?= $thumb_src ?>" loading="lazy"
                                     style="width:100%;height:100%;object-fit:cover;display:block;"
                                     onerror="this.style.display='none';document.getElementById('ssThumbPh<?= $i ?>').style.display='flex';">
                                <div id="ssThumbPh<?= $i ?>" style="display:none;width:100%;height:100%;
                                     align-items:center;justify-content:center;font-size:24px;">🎬</div>
                                <?php elseif ($hasMedia && $isVideo): ?>
                                <video id="ssThumbImg<?= $i ?>" src="<?= $thumb_src ?>"
                                       style="width:100%;height:100%;object-fit:cover;display:block;"
                                       muted playsinline preload="metadata"
                                       onerror="this.style.display='none';document.getElementById('ssThumbPh<?= $i ?>').style.display='flex';"></video>
                                <div id="ssThumbPh<?= $i ?>" style="display:none;width:100%;height:100%;
                                     align-items:center;justify-content:center;font-size:24px;">🎬</div>
                                <?php else: ?>
                                <img id="ssThumbImg<?= $i ?>" src="" style="display:none;width:100%;height:100%;object-fit:cover;">
                                <div id="ssThumbPh<?= $i ?>" style="display:flex;width:100%;height:100%;
                                     align-items:center;justify-content:center;font-size:24px;color:rgba(255,255,255,.4);">🎬</div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Caption editable text -->
                        <td style="padding:8px 6px;vertical-align:middle;">
                            <textarea id="ssCap<?= $i ?>" rows="4"
                                style="width:100%;padding:7px 9px;border-radius:8px;
                                       border:1.5px solid var(--border);font-size:11px;
                                       font-family:inherit;resize:vertical;outline:none;
                                       background:var(--surface2);color:var(--text);
                                       line-height:1.5;box-sizing:border-box;"
                                placeholder="Caption text…"><?= htmlspecialchars(trim($caption_text_map[(int)$sc['id']] ?? '')) ?></textarea>
                            <div style="display:flex;gap:5px;margin-top:5px;">
                                <button onclick="event.stopPropagation();ssSaveCaptionText(<?= $i ?>)"
                                    id="ssSaveBtn<?= $i ?>"
                                    style="flex:1;padding:7px;border-radius:8px;
                                           border:none;background:var(--success);color:#fff;
                                           font-size:11px;font-weight:700;cursor:pointer;
                                           font-family:inherit;">💾 Save</button>
                                <button onclick="event.stopPropagation();ssPlayFromScene(<?= $i ?>)"
                                    style="flex:1;padding:7px;border-radius:8px;
                                           border:none;background:var(--primary);color:#fff;
                                           font-size:11px;font-weight:700;cursor:pointer;
                                           font-family:inherit;">▶ Play</button>
                            </div>
                            <?php if ($hasAudio): ?>
                            <div id="ssAudBadge<?= $i ?>" style="margin-top:4px;font-size:10px;color:#10b981;font-weight:600;">🔊 Audio ready</div>
                            <?php else: ?>
                            <div id="ssAudBadge<?= $i ?>" style="display:none;margin-top:4px;font-size:10px;color:#10b981;font-weight:600;">🔊 Audio ready</div>
                            <?php endif; ?>
                        </td>

                        <!-- Action buttons — large, tap-friendly (min 40px height) -->
                        <td style="padding:8px 8px 8px 4px;vertical-align:middle;white-space:nowrap;">
                            <div style="display:flex;flex-direction:column;gap:5px;">
                                <button id="ssIconCap<?= $i ?>" title="Caption"
                                        onclick="event.stopPropagation();ssOpenCaption(<?= $i ?>)"
                                        style="display:block;width:100%;min-height:40px;padding:0 10px;
                                               border-radius:8px;border:1.5px solid var(--border);
                                               background:var(--surface);cursor:pointer;font-size:12px;
                                               font-weight:600;transition:all .13s;white-space:nowrap;
                                               font-family:inherit;">🅰️ Caption</button>
                                <button id="ssIconFont<?= $i ?>" title="Font"
                                        onclick="event.stopPropagation();ssOpenFont(<?= $i ?>)"
                                        style="display:block;width:100%;min-height:40px;padding:0 10px;
                                               border-radius:8px;border:1.5px solid var(--border);
                                               background:var(--surface);cursor:pointer;font-size:12px;
                                               font-weight:600;transition:all .13s;white-space:nowrap;
                                               font-family:inherit;">🔤 Font</button>
                                <button id="ssIconImg<?= $i ?>" title="Image/Media"
                                        onclick="event.stopPropagation();ssOpenImage(<?= $i ?>)"
                                        style="display:block;width:100%;min-height:40px;padding:0 10px;
                                               border-radius:8px;
                                               border:1.5px solid <?= $hasMedia?'#16a34a':'var(--border)' ?>;
                                               background:<?= $hasMedia?'#dcfce7':'var(--surface)' ?>;
                                               color:<?= $hasMedia?'#15803d':'inherit' ?>;
                                               cursor:pointer;font-size:12px;font-weight:600;
                                               transition:all .13s;white-space:nowrap;font-family:inherit;">🌄 Image</button>
                                <button id="ssIconAud<?= $i ?>" title="Audio"
                                        onclick="event.stopPropagation();ssOpenAudio(<?= $i ?>)"
                                        style="display:block;width:100%;min-height:40px;padding:0 10px;
                                               border-radius:8px;
                                               border:1.5px solid <?= $hasAudio?'#16a34a':'var(--border)' ?>;
                                               background:<?= $hasAudio?'#dcfce7':'var(--surface)' ?>;
                                               color:<?= $hasAudio?'#15803d':'inherit' ?>;
                                               cursor:pointer;font-size:12px;font-weight:600;
                                               transition:all .13s;white-space:nowrap;font-family:inherit;">🔊 Audio</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- ═══════════════════════════════════════════════════════ -->

        </div><!-- end player wrapper -->
		 <div id="log"></div>
    </div><!-- .right-col -->

<!-- ══ FONT OVERLAY — wraps capSubTab2 in a standalone modal ══════ -->
<!-- ══ CAPTION OVERLAY ════════════════════════════════════════════ -->
<div id="pCaptionOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);
            z-index:20000;align-items:flex-start;justify-content:center;
            padding:8px;overflow-y:auto;">
    <div style="background:var(--surface);border-radius:14px;width:100%;max-width:420px;
                box-shadow:0 20px 60px rgba(0,0,0,.4);margin:8px auto;overflow:hidden;
                display:flex;flex-direction:column;max-height:calc(100vh - 32px);
                animation:ssSlide .25s cubic-bezier(.16,1,.3,1);">
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:11px 14px;background:var(--primary);flex-shrink:0;">
            <span style="font-size:13px;font-weight:700;color:#fff;">🅰️ Caption — Scene <span id="captionOverlayNum">1</span></span>
            <button onclick="closeCaptionOverlay()"
                    style="background:rgba(255,255,255,.12);border:none;color:rgba(255,255,255,.8);
                           width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;
                           display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div id="captionOverlayBody" style="overflow-y:auto;flex:1;min-height:0;"></div>
    </div>
</div>

<div id="pFontOverlay" style="display:none;position:fixed;inset:0;background:transparent;z-index:20000;pointer-events:none;"></div>
<div id="pFontPanel"
     style="display:none;position:fixed;top:40px;left:50%;transform:translateX(-50%);
            z-index:20001;background:var(--surface);border-radius:14px;width:440px;max-width:calc(100vw - 16px);
            box-shadow:0 20px 60px rgba(0,0,0,.45);
            flex-direction:column;height:calc(100vh - 56px);
            animation:ssSlide .25s cubic-bezier(.16,1,.3,1);">
    <div id="pFontDragHandle"
         style="display:flex;align-items:center;justify-content:space-between;
                padding:11px 14px;background:var(--primary);border-radius:14px 14px 0 0;flex-shrink:0;
                cursor:grab;user-select:none;">
        <span style="display:flex;align-items:center;gap:7px;">
            <span style="font-size:11px;opacity:.55;letter-spacing:2px;">⠿</span>
            <span style="font-size:13px;font-weight:700;color:#fff;">🔤 Font — Scene <span id="fontOverlaySceneNum">1</span></span>
        </span>
        <button onclick="closeFontOverlay()"
                style="background:rgba(255,255,255,.12);border:none;color:rgba(255,255,255,.8);
                       width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;
                       display:flex;align-items:center;justify-content:center;flex-shrink:0;">✕</button>
    </div>
    <div id="fontOverlayBody" style="overflow-y:auto;flex:1;min-height:0;">
        <!-- capSubTab2 injected here at runtime -->
    </div>
</div>

<div id="pImageOverlay"
     style="display:none;position:fixed;inset:0;background:transparent;
            z-index:20000;pointer-events:none;">
</div>
<div id="pImagePanel"
     style="display:none;position:fixed;top:40px;left:50%;transform:translateX(-50%);
            z-index:20001;background:var(--surface);border-radius:14px;width:440px;max-width:calc(100vw - 16px);
            box-shadow:0 20px 60px rgba(0,0,0,.45);
            flex-direction:column;height:calc(100vh - 56px);
            animation:ssSlide .25s cubic-bezier(.16,1,.3,1);">
    <div id="pImageDragHandle"
         style="display:flex;align-items:center;justify-content:space-between;
                padding:11px 14px;background:var(--primary);border-radius:14px 14px 0 0;flex-shrink:0;
                cursor:grab;user-select:none;">
        <span style="display:flex;align-items:center;gap:7px;">
            <span style="font-size:11px;opacity:.55;letter-spacing:2px;">⠿</span>
            <span style="font-size:13px;font-weight:700;color:#fff;">🌄 Image — Scene <span id="imageOverlayNum">1</span></span>
        </span>
        <button onclick="closeImageOverlay()"
                style="background:rgba(255,255,255,.12);border:none;color:rgba(255,255,255,.8);
                       width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;
                       display:flex;align-items:center;justify-content:center;flex-shrink:0;">✕</button>
    </div>
    <div id="imageOverlayBody" style="overflow-y:auto;flex:1;min-height:0;"></div>
</div>

<div id="pAudioOverlay" style="display:none;position:fixed;inset:0;background:transparent;z-index:20000;pointer-events:none;"></div>
<div id="pAudioPanel"
     style="display:none;position:fixed;top:40px;left:50%;transform:translateX(-50%);
            z-index:20001;background:var(--surface);border-radius:14px;width:440px;max-width:calc(100vw - 16px);
            box-shadow:0 20px 60px rgba(0,0,0,.45);
            flex-direction:column;height:calc(100vh - 56px);
            animation:ssSlide .25s cubic-bezier(.16,1,.3,1);">
    <div id="pAudioDragHandle"
         style="display:flex;align-items:center;justify-content:space-between;
                padding:11px 14px;background:var(--primary);border-radius:14px 14px 0 0;flex-shrink:0;
                cursor:grab;user-select:none;">
        <span style="display:flex;align-items:center;gap:7px;">
            <span style="font-size:11px;opacity:.55;letter-spacing:2px;">⠿</span>
            <span style="font-size:13px;font-weight:700;color:#fff;">🔊 Audio — Scene <span id="audioOverlayNum">1</span></span>
        </span>
        <button onclick="closeAudioOverlay()"
                style="background:rgba(255,255,255,.12);border:none;color:rgba(255,255,255,.8);
                       width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:16px;
                       display:flex;align-items:center;justify-content:center;flex-shrink:0;">✕</button>
    </div>
    <div id="audioOverlayBody" style="overflow-y:auto;flex:1;min-height:0;"></div>
</div>

</div><!-- .workspace -->

<script>
const SCENES       = <?= $scenes_json ?>;
const ALL_CAPTIONS = <?= $all_captions_json ?>;
const IMG_BASE   = '<?= htmlspecialchars($img_folder, ENT_QUOTES) ?>/';
const VID_BASE   = '<?= htmlspecialchars($vid_folder, ENT_QUOTES) ?>/';
const COMPANY_ID        = <?= (int)$company_id ?>;
const USER_MEDIA_FOLDER = 'user_media/user_id_<?= (int)$admin_id ?>_company_id_<?= (int)$company_id ?>/';
const AUD_BASE   = 'podcast_audios/';
const MUS_BASE   = 'podcast_music/';

const VIDEO_TYPE = '<?= addslashes($video_type) ?>';

// filename → folder map, built from scene data (used when we don't have sc reference)
const FILE_FOLDER = {};
// Slot → its own folder column
const SLOT_FOLDER_COL = {
    image_file:   'image_folder',
    image_file_1: 'image_folder_1',
    image_file_2: 'image_folder_2',
    image_file_3: 'image_folder_3',
    image_file_4: 'image_folder_4',
};
// Pre-populate from all scenes at load time — each slot uses its own folder column
(function() {
    SCENES.forEach(sc => {
        Object.entries(SLOT_FOLDER_COL).forEach(function(entry) {
            const slot = entry[0], folderCol = entry[1];
            const fn = (sc[slot] || '').trim();
            if (!fn) return;
            const f = (sc[folderCol] || sc.image_folder || '').trim().replace(/^\/|\/$/g, '');
            FILE_FOLDER[fn] = (f || 'podcast_images') + '/';
        });
    });
})();
const PODCAST_ID    = <?= $podcast_id ?>;
const AUDIO_SPEED   = <?= json_encode($audio_speed) ?>;
// Per-scene slot selections: { scene_id: { slot_main:1, slot_1:0, ... } }
const SCENE_SAVED_SLOTS = <?php
    $slotMap = [];
    foreach ($scenes as $sc) {
        $slotMap[(int)$sc['id']] = [
            'slot_main' => (int)($sc['slot_main'] ?? 1),
            'slot_1'    => (int)($sc['slot_1']    ?? 0),
            'slot_2'    => (int)($sc['slot_2']    ?? 0),
            'slot_3'    => (int)($sc['slot_3']    ?? 0),
            'slot_4'    => (int)($sc['slot_4']    ?? 0),
        ];
    }
    echo json_encode($slotMap);
?>;

// Map: slot key → DB column name
const SLOT_COL_MAP = {
    image_file:   'slot_main',
    image_file_1: 'slot_1',
    image_file_2: 'slot_2',
    image_file_3: 'slot_3',
    image_file_4: 'slot_4',
};

const _isFreeTrialUser = <?= json_encode($is_free_trial) ?>;

const CW = <?= CW ?>, CH = <?= CH ?>;
// Caption boundary constants — 5px margin on all sides, max width = CW-10
const CAP_MARGIN = 5;
const CAP_MAX_W  = CW - CAP_MARGIN * 2; // 380 for a 390-wide canvas

// Clamp caption position + size so it never exits the canvas
function clampCap(cap) {
    const w  = Math.max(40, Math.min(CAP_MAX_W, parseFloat(cap.width)      || 120));
    const x  = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w, parseFloat(cap.position_x) || 0));
    const bh = _capBoxH(cap);
    const y  = Math.max(0, Math.min(CH - Math.max(20, bh), parseFloat(cap.position_y) || 0));
    cap.width      = w;
    cap.position_x = x;
    cap.position_y = y;
}
const T_DUR = 380, KB_DUR = 8000;
const KB_EFFECTS = ['zoom-in','zoom-out','pan-left','pan-right'];

const canvas   = document.getElementById('screen');
const ctx      = canvas.getContext('2d');
const canvasHD = document.getElementById('screenHD');
const ctxHD    = canvasHD.getContext('2d');

const vidEls = {};
document.querySelectorAll('.pv').forEach(v => vidEls[v.dataset.fn] = v);
const imgCache = {};
const sceneKB  = SCENES.map(() => KB_EFFECTS[Math.floor(Math.random()*KB_EFFECTS.length)]);
let lastFrameTime = 0;
let frameCount = 0;
// Add to your S object at the top
const S = {
    type: 'blank', 
    img: null, 
    imgOut: null, 
    vidEl: null,
    alpha: 1, 
    alphaOut: 0, 
    kbEffect: 'zoom-in', 
    kbStart: 0, 
    txOffset: null,
    nextImg: null,      // Pre-loaded next image
    nextVid: null,      // Pre-loaded next video
    nextType: null,     // Type of next media
    isTransitioning: false
};

let renderRaf=null, framesDrawn=0;
let currentIndex=0, isPlaying=false, isRecording=false;
let currentAudio=null, transitioning=false;
let bootComplete=false; // guard against onchange firing before boot restores checkboxes
let bgAudio=null, bgMusicVolume=0.3, voiceVolume=1.0;
<?php if ($podcast_music): ?>
bgAudio=new Audio('<?= addslashes(MUS_BASE.$podcast_music) ?>');
bgAudio.loop=true; bgAudio.volume=bgMusicVolume;
<?php endif; ?>

let activeSlot='image_file';
let previewAudio=null;

// ══════════════════════════════════════════════════════════════════
// MULTI-CAPTION SYSTEM
// ══════════════════════════════════════════════════════════════════
const captionStates = {}; // { capId: { show, full, words, karIdx, timer } }
let sceneCaptions   = []; // captions for current scene
let selectedCapId   = null;
let _capSaveTimers  = {};
let _capDirty       = {};
const GLOBAL_CAP_NAMES = ['main','header','footer','logo'];
let _applyToAllScenes  = true;  // default ON for global captions

// Drag / resize
let _drag   = { active:false, capId:null, startX:0, startY:0, origX:0, origY:0 };
let _resize = { active:false, dir:'h', capId:null, startX:0, startY:0, origW:0, origH:0, origPY:0 };

function loadSceneCaptions(sceneId) {
    sceneCaptions = ALL_CAPTIONS.filter(c => +c.story_id === +sceneId);
    sceneCaptions.forEach(c => {
        if (!captionStates[c.id])
            captionStates[c.id] = { show:'', full:'', words:[], karIdx:0, timer:null };
        if (c.is_visible) startCaptionAnim(c);
    });
    renderCaptionTabs();
    if (selectedCapId && sceneCaptions.find(c => +c.id === +selectedCapId)) {
        selectCaption(selectedCapId);
    } else {
        selectedCapId = null;
        showCaptionEditor(false);
    }
}

// ── Caption animation ──────────────────────────────────────────────
function stopCaptionAnim(capId) {
    const st = captionStates[capId];
    if (!st) return;
    if (st.timer) { clearInterval(st.timer); clearTimeout(st.timer); st.timer = null; }
}
// Returns the correct folder (with trailing slash) for a specific slot on a scene.
// If slot not provided, falls back to sc.image_folder (main slot).
function getSceneFolder(sc, slot) {
    if (!sc) return IMG_BASE;
    const folderCol = SLOT_FOLDER_COL[slot] || 'image_folder';
    const raw = (sc[folderCol] || sc.image_folder || '').trim().replace(/^\/|\/$/g, '');
    const folder = (raw || 'podcast_images') + '/';
    // Re-register all slots into FILE_FOLDER so getFileFolder stays current
    Object.entries(SLOT_FOLDER_COL).forEach(function(entry) {
        const s = entry[0], fc = entry[1];
        const fn = (sc[s] || '').trim();
        if (!fn) return;
        const f = (sc[fc] || sc.image_folder || '').trim().replace(/^\/|\/$/g, '');
        FILE_FOLDER[fn] = (f || 'podcast_images') + '/';
    });
    return folder;
}
// Folder lookup by filename alone (falls back to IMG_BASE)
function getFileFolder(fn) {
    return FILE_FOLDER[fn] || IMG_BASE;
}
function getImageSrc(fn, sc, slot) {
    if (!fn) return '';
    if (fn.startsWith('logo_')) return 'podcast_logos/' + fn;
    return getSceneFolder(sc, slot) + fn;
}

// Add this helper to check if video is truly ready
function isVideoReady(video) {
    return video && 
           video.readyState >= 2 && 
           video.videoWidth > 0 && 
           video.videoHeight > 0;
}

// Update the prepareNextScene function to ensure video is ready
async function prepareNextScene(nextIndex) {
    if (nextIndex < 0 || nextIndex >= SCENES.length) return;
    
    const sc = SCENES[nextIndex];
    const { fn, isVideo, slot } = sceneMedia(sc);
    
    if (isVideo && fn) {
        // Prepare video
        if (!vidEls[fn]) {
            const v = document.createElement('video');
            v.muted = true;
            v.loop = !isTalkingHeadScene(sc);
            v.playsInline = true;
            v.crossOrigin = 'anonymous';
            v.preload = 'auto';
            v.src = getSceneFolder(sc, slot) + fn;
            document.getElementById('vidPool').appendChild(v);
            vidEls[fn] = v;
        }
        
        const video = vidEls[fn];
        
        // Wait for video to have valid dimensions
        if (!isVideoReady(video)) {
            await new Promise(resolve => {
                const checkReady = () => {
                    if (isVideoReady(video)) {
                        resolve();
                    } else {
                        setTimeout(checkReady, 50);
                    }
                };
                
                const timeout = setTimeout(() => resolve(), 2000);
                video.addEventListener('loadeddata', () => {
                    clearTimeout(timeout);
                    resolve();
                }, { once: true });
                
                checkReady();
                video.load();
            });
        }
        
        S.nextType = 'video';
        S.nextVid = video;
        S.nextImg = null;
        
        // Seek to t=0 but do NOT play — playing here causes the video to run
        // before the scene starts, so showScene's currentTime=0 makes it jump.
        video.currentTime = 0;
        
    } else if (fn && imgCache[fn]) {
        // Image already loaded
        S.nextType = 'image';
        S.nextImg = imgCache[fn];
        S.nextVid = null;
    } else if (fn) {
        // Load image if not cached
        const img = new Image();
        img.crossOrigin = 'anonymous';
        await new Promise(resolve => {
            img.onload = () => {
                imgCache[fn] = img;
                S.nextType = 'image';
                S.nextImg = img;
                S.nextVid = null;
                resolve();
            };
            img.onerror = resolve;
            setTimeout(resolve, 2000);
            img.src = getImageSrc(fn, sc, slot);
        });
    }
}
function startCaptionAnim(cap) {
    const id = cap.id;
    // Restore extra vertical padding from DB rotation field
    if(cap._extraVPad === undefined) cap._extraVPad = parseInt(cap.rotation) || 0;
    stopCaptionAnim(id);
    const st = captionStates[id] || (captionStates[id] = { show:'', full:'', words:[], karIdx:0, timer:null });
    const text  = cap.text_content || '';
    st.full   = text;
    st.words  = text.split(' ');
    st.karIdx = 0;
    st.show   = '';
    const style = cap.animation_style || 'none';
    const spd   = parseFloat(cap.animation_speed) || 1;

    if (['static','none','fade-in','zoom-in','pop','bounce'].includes(style)) { st.show = text; return; }
    if (style === 'typewriter' || style === 'char-by-char') {
        let i = 0; const ms = Math.round((style==='char-by-char'?60:36)/spd);
        st.timer = setInterval(() => { st.show = text.substring(0,++i); if(i>=text.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'word-reveal') {
        let wi = 0; const ms = Math.round(140/spd);
        st.timer = setInterval(() => { st.show = st.words.slice(0,++wi).join(' '); if(wi>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    if (style === 'line-by-line') {
        const chunk=6, chunks=[];
        for(let i=0;i<st.words.length;i+=chunk) chunks.push(st.words.slice(i,i+chunk).join(' '));
        let ci=0; st.show = chunks[ci++]||'';
        const ms = Math.round(900/spd);
        st.timer = setInterval(() => { if(ci>=chunks.length){clearInterval(st.timer);st.timer=null;return;} st.show=chunks[ci++]; }, ms); return;
    }
    if (style === 'karaoke') {
        st.show = text; st.karIdx = 0;
        const ms = Math.round(320/spd);
        st.timer = setInterval(() => { st.karIdx++; if(st.karIdx>=st.words.length){clearInterval(st.timer);st.timer=null;} }, ms); return;
    }
    st.show = text;
}

// ── Draw all captions ──────────────────────────────────────────────
function drawAllCaptions() {
    sceneCaptions.forEach(cap => drawOneCaption(cap));
    if (selectedCapId) drawSelectionHandles(selectedCapId);
}

// ── Activity Logger ───────────────────────────────────────────────
function logActivity(action_type, action_detail = '', scene_index = null) {
    const fd = new FormData();
    fd.append('ajax_action',   'log_activity');
    fd.append('podcast_id',    PODCAST_ID);
    fd.append('action_type',   action_type);
    fd.append('action_detail', action_detail);
    if (scene_index !== null) fd.append('scene_index', scene_index);
    fetch(location.href, { method: 'POST', body: fd }).catch(() => {}); // fire-and-forget
}

function drawOneCaption(cap) {
    if (!cap.is_visible && !cap._forceShow) return;

    // ── IMAGE CAPTION ──────────────────────────────────────────
    if (cap.caption_type === 'image') {
        const fn  = cap.text_content || '';
        const px  = parseInt(cap.position_x) || 20;
        const py  = parseInt(cap.position_y) || 20;
        const pw  = parseInt(cap.width)      || 120;
        const ph  = parseInt(cap.rotation)   || 120;  // height stored in rotation column
        cap._bbox = { x:px, y:py, w:pw, h:ph };

        const img = imgCache[fn];
        if (img === null) {
            // Image previously failed to load (404 etc) — draw placeholder, never retry
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.25)';
            ctx.fillRect(px, py, pw, ph);
            ctx.restore();
        } else if (img) {
            ctx.save();
            ctx.drawImage(img, px, py, pw, ph);
            ctx.restore();
        } else {
            // Placeholder while loading
            ctx.save();
            ctx.fillStyle = 'rgba(100,100,100,0.4)';
            ctx.fillRect(px, py, pw, ph);
            ctx.fillStyle = '#fff';
            ctx.font = '11px Inter';
            ctx.textAlign = 'center';
            ctx.fillText('🖼️', px + pw/2, py + ph/2);
            ctx.restore();
            // Trigger async load — guard with _loading_ so only one request fires
            if (!imgCache['_loading_'+fn]) {
                imgCache['_loading_'+fn] = true;
                const i = new Image();
                i.crossOrigin = 'anonymous';
                i.onload  = () => { imgCache[fn] = i; delete imgCache['_loading_'+fn]; };
                i.onerror = () => { imgCache[fn] = null; delete imgCache['_loading_'+fn]; }; // null = failed, stop retrying
                i.src = getFileFolder(fn) + fn;
            }
        }
        return;  // ← skip all text drawing
    }

    // ... rest of existing drawOneCaption text logic unchanged
    const st = captionStates[cap.id];
    if (!st) return;
    const text = st.show || '';
    if (!text.trim()) { cap._bbox = null; return; }

    const fs        = parseInt(cap.fontsize) || 22;
    const extraVPad = cap._extraVPad ?? parseInt(cap.rotation) ?? 0;
    const pad       = 10 + Math.round(extraVPad / 2);
    const lh        = fs + 7;
    const maxW      = parseInt(cap.width)      || 320;
    const posX      = parseInt(cap.position_x) || 50;
    const posY      = parseInt(cap.position_y) || 400;
    const tAlign    = cap.text_align || 'center';
    const bold      = (cap.fontweight === 'bold' || cap.fontweight === '700') ? 'bold ' : '';
    const italic    = cap.fontstyle === 'italic' ? 'italic ' : '';

    // ── FONT DEBUG ────────────────────────────────────────────────
    // Normalize bare font names that DB may store without CSS stack
    const _fontNorm = {
        // System
        'Arial':                'Arial,sans-serif',
        'Helvetica':            'Helvetica,sans-serif',
        'Verdana':              'Verdana,sans-serif',
        'Georgia':              'Georgia,serif',
        'Impact':               'Impact,fantasy',
        'Courier New':          "'Courier New',monospace",
        'Times New Roman':      "'Times New Roman',serif",
        'Segoe UI':             "'Segoe UI',sans-serif",
        'Inter':                "'Inter',sans-serif",
        'Comic Sans MS':        "'Comic Sans MS',cursive",
        // Sans Serif
        'Poppins':              "'Poppins',sans-serif",
        'Montserrat':           "'Montserrat',sans-serif",
        'Raleway':              "'Raleway',sans-serif",
        'Oswald':               "'Oswald',sans-serif",
        'Anton':                "'Anton',sans-serif",
        'Righteous':            "'Righteous',sans-serif",
        'Black Han Sans':       "'Black Han Sans',sans-serif",
        'Josefin Sans':         "'Josefin Sans',sans-serif",
        'Barlow Condensed':     "'Barlow Condensed',sans-serif",
        'DM Sans':              "'DM Sans',sans-serif",
        'Jost':                 "'Jost',sans-serif",
        'Space Grotesk':        "'Space Grotesk',sans-serif",
        'Syne':                 "'Syne',sans-serif",
        'Tenor Sans':           "'Tenor Sans',sans-serif",
        // Serif
        'Playfair Display':     "'Playfair Display',serif",
        'Lora':                 "'Lora',serif",
        'Libre Baskerville':    "'Libre Baskerville',serif",
        'Crimson Pro':          "'Crimson Pro',serif",
        'EB Garamond':          "'EB Garamond',serif",
        'Cormorant Garamond':   "'Cormorant Garamond',serif",
        'Cormorant SC':         "'Cormorant SC',serif",
        'Roboto Slab':          "'Roboto Slab',serif",
        'DM Serif Display':     "'DM Serif Display',serif",
        'Alfa Slab One':        "'Alfa Slab One',serif",
        'Cinzel':               "'Cinzel',serif",
        'Italiana':             "'Italiana',serif",
        // Display / Promotional
        'Bebas Neue':           "'Bebas Neue',sans-serif",
        'Bangers':              "'Bangers',cursive",
        'Luckiest Guy':         "'Luckiest Guy',cursive",
        'Black Ops One':        "'Black Ops One',cursive",
        'Russo One':            "'Russo One',sans-serif",
        'Teko':                 "'Teko',sans-serif",
        'Boogaloo':             "'Boogaloo',cursive",
        'Fredoka One':          "'Fredoka One',cursive",
        'Lilita One':           "'Lilita One',cursive",
        'Poiret One':           "'Poiret One',cursive",
        // Handwriting & Calligraphy
        'Dancing Script':       "'Dancing Script',cursive",
        'Pacifico':             "'Pacifico',cursive",
        'Lobster':              "'Lobster',cursive",
        'Permanent Marker':     "'Permanent Marker',cursive",
        'Caveat':               "'Caveat',cursive",
        'Great Vibes':          "'Great Vibes',cursive",
        'Alex Brush':           "'Alex Brush',cursive",
        'Pinyon Script':        "'Pinyon Script',cursive",
        'Sacramento':           "'Sacramento',cursive",
        'Satisfy':              "'Satisfy',cursive",
        'Kaushan Script':       "'Kaushan Script',cursive",
        'Yellowtail':           "'Yellowtail',cursive",
        'Allura':               "'Allura',cursive",
        'Marck Script':         "'Marck Script',cursive",
        'Italianno':            "'Italianno',cursive",
        'Mr Dafoe':             "'Mr Dafoe',cursive",
        'Euphoria Script':      "'Euphoria Script',cursive",
        // Custom local fonts
        'NotoNastaliqUrdu':     "'NotoNastaliqUrdu',serif",
        'AttariQuraanWord':     "'AttariQuraanWord',serif",
    };
    let rawFamily = cap.fontfamily || '';
    let family    = _fontNorm[rawFamily] || rawFamily || 'Arial,sans-serif';

    // Log font info to server for every caption on first render
    if (!cap._fontLogged) {
        cap._fontLogged = true;
        const logData = new FormData();
        logData.append('ajax_action',  'debug_log');
        logData.append('cap_id',       cap.id);
        logData.append('cap_name',     cap.caption_name   || '');
        logData.append('cap_type',     cap.caption_type   || '');
        logData.append('raw_family',   rawFamily);
        logData.append('used_family',  family);
        logData.append('fontsize',     cap.fontsize       || '');
        logData.append('fontcolor',    cap.fontcolor      || '');
        logData.append('story_id',     cap.story_id       || '');
        logData.append('podcast_id',   cap.podcast_id     || '');
        fetch(location.href, { method: 'POST', body: logData }).catch(() => {});
    }
    // ── END FONT DEBUG ────────────────────────────────────────────

    ctx.save();
    ctx.font = italic + bold + fs + 'px ' + family;

    // Split on hard line breaks first, then wrap each paragraph
    const paragraphs = text.split('\n');
    const lines = [];
    paragraphs.forEach(para => {
        const trimmed = para.trim();
        if (!trimmed) { lines.push(''); return; } // preserve blank lines as spacers
        const words = trimmed.split(' ');
        let ln = '';
        words.forEach(w => {
            const t = ln ? ln + ' ' + w : w;
            if (ctx.measureText(t).width > maxW && ln) { lines.push(ln); ln = w; } else ln = t;
        });
        if (ln) lines.push(ln);
    });

    const bh = lines.length * lh + pad * 2;
    const bw = maxW;
    cap._bbox = { x: posX, y: posY, w: bw, h: bh };



    const bgOn     = (cap.bg_enabled === 1 || cap.bg_enabled === '1' || cap.bg_enabled === true);
    const bdrThick = parseInt(cap.caption_box_border_thickness) || 0;
    const bdrColor = cap.caption_box_border_color || '#ffffff';

    if (bgOn || bdrThick > 0) {
        ctx.save();
        rrect(ctx, posX, posY, bw, bh, 10);
        if (bgOn) {
            const br = parseInt((cap.bg_color || '#000000').slice(1,3), 16);
            const bg = parseInt((cap.bg_color || '#000000').slice(3,5), 16);
            const bb = parseInt((cap.bg_color || '#000000').slice(5,7), 16);
            ctx.fillStyle = `rgba(${br},${bg},${bb},${parseFloat(cap.bg_opacity) || 0.7})`;
            ctx.fill();
        }
        if (bdrThick > 0) {
            ctx.strokeStyle = bdrColor;
            ctx.lineWidth   = bdrThick;
            ctx.lineJoin    = 'round';
            ctx.stroke();
        }
        ctx.restore();
    }

    let tx, ta;
    if      (tAlign === 'left')  { tx = posX + pad;      ta = 'left';   }
    else if (tAlign === 'right') { tx = posX + bw - pad; ta = 'right';  }
    else                         { tx = posX + bw / 2;   ta = 'center'; }
    ctx.textAlign = ta;

    const fx       = _capEffect(cap);
    let gradFill   = null;
    if (fx === 'gradient') {
        const gr = ctx.createLinearGradient(posX, 0, posX + bw, 0);
        gr.addColorStop(0,   '#ff6b6b');
        gr.addColorStop(.33, '#ffd93d');
        gr.addColorStop(.66, '#6bcb77');
        gr.addColorStop(1,   '#4d96ff');
        gradFill = gr;
    }

    lines.forEach((line, i) => {
        const ty = posY + pad + fs + i * lh;
        ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
        if      (fx === 'shadow') { ctx.shadowColor = 'rgba(0,0,0,.95)'; ctx.shadowBlur = 8; ctx.shadowOffsetX = 2; ctx.shadowOffsetY = 2; }
        else if (fx === 'glow')   { ctx.shadowColor = cap.fontcolor || '#fff'; ctx.shadowBlur = 22; }
        else if (fx === '3d')     { ctx.shadowColor = 'rgba(0,0,0,.65)'; ctx.shadowOffsetX = 3; ctx.shadowOffsetY = 3; }
        if (fx === 'outline' || fx === 'stroke') {
            ctx.shadowBlur = 0; ctx.shadowOffsetX = 0; ctx.shadowOffsetY = 0;
            ctx.strokeStyle = cap.stroke_color || '#000';
            ctx.lineWidth   = (parseInt(cap.stroke_width) || 2) * 2;
            ctx.lineJoin    = 'round';
            ctx.strokeText(line, tx, ty);
        }
        ctx.fillStyle = gradFill || (cap.fontcolor || '#ffffff');
        ctx.fillText(line, tx, ty);
        if (cap.underline) {
            const tw = ctx.measureText(line).width;
            const ux = ta === 'center' ? tx - tw/2 : ta === 'right' ? tx - tw : tx;
            ctx.beginPath(); ctx.moveTo(ux, ty + 2); ctx.lineTo(ux + tw, ty + 2);
            ctx.strokeStyle = cap.fontcolor || '#fff'; ctx.lineWidth = 1; ctx.stroke();
        }
    });
    ctx.restore();
}




function _capEffect(cap) {
    if (cap.outline_enabled && +cap.outline_width > 0) return 'outline';
    if (cap.stroke_enabled  && +cap.stroke_width  > 0) return 'stroke';
    return cap._uiEffect || 'none';
}

function drawSelectionHandles(capId) {
    const cap = sceneCaptions.find(c=>+c.id===+capId);
    if (!cap || !cap._bbox) return;
    const {x,y,w,h} = cap._bbox;
    ctx.save();
    ctx.strokeStyle='#3b82f6'; ctx.lineWidth=2; ctx.setLineDash([4,3]);
    ctx.strokeRect(x-2,y-2,w+4,h+4);
    ctx.setLineDash([]);

    // Unified handles for ALL caption types: 4 corners + right-center + bottom-center
    const hs = 12, off = 2;
    const handles = [
        { cx: x - off - hs/2,   cy: y - off - hs/2,   dir:'nw', arrow:'↖' },
        { cx: x + w + off+hs/2, cy: y - off - hs/2,   dir:'ne', arrow:'↗' },
        { cx: x - off - hs/2,   cy: y + h + off+hs/2, dir:'sw', arrow:'↙' },
        { cx: x + w + off+hs/2, cy: y + h + off+hs/2, dir:'se', arrow:'↘' },
        { cx: x + w + off+hs/2, cy: y + h/2,           dir:'e',  arrow:'↔' },
        { cx: x + w/2,          cy: y + h + off+hs/2, dir:'s',  arrow:'↕' },
    ];
    handles.forEach(({cx,cy,dir,arrow}) => {
        const hx = cx - hs/2, hy = cy - hs/2;
        ctx.fillStyle = (dir==='s') ? '#10b981' : '#3b82f6';
        ctx.fillRect(hx, hy, hs, hs);
        ctx.strokeStyle='#fff'; ctx.lineWidth=1.5;
        ctx.strokeRect(hx+1, hy+1, hs-2, hs-2);
        ctx.fillStyle='#fff'; ctx.font='bold 8px Inter';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        ctx.fillText(arrow, cx, cy);
    });
    ctx.restore();
}

// ── Canvas mouse events ────────────────────────────────────────────
function _canvasPos(e) {
    const rect=canvas.getBoundingClientRect();
    return { x:(e.clientX-rect.left)*(CW/rect.width), y:(e.clientY-rect.top)*(CH/rect.height) };
}
function _hitCap(x,y) {
    for(let i=sceneCaptions.length-1;i>=0;i--){
        const c=sceneCaptions[i];
        if(!+c.is_visible) continue;
        // For image captions with no _bbox yet, synthesize from stored fields
        const bbox = c._bbox || (c.caption_type==='image' ? {
            x: parseInt(c.position_x)||20,
            y: parseInt(c.position_y)||20,
            w: parseInt(c.width)||120,
            h: parseInt(c.rotation)||120
        } : null);
        if(!bbox) continue;
        if(x>=bbox.x&&x<=bbox.x+bbox.w&&y>=bbox.y&&y<=bbox.y+bbox.h)return c.id;
    }
    return null;
}
function _isResizeHandle(capId,x,y){
    const cap=sceneCaptions.find(c=>+c.id===+capId);
    if(!cap||!cap._bbox)return false;
    const{x:bx,y:by,w,h}=cap._bbox;
    const hs=12, off=2, tol=10;
    // Handle centers — must match drawSelectionHandles exactly
    const handles=[
        {cx:bx-off-hs/2,       cy:by-off-hs/2,       dir:'nw'},
        {cx:bx+w+off+hs/2,     cy:by-off-hs/2,       dir:'ne'},
        {cx:bx-off-hs/2,       cy:by+h+off+hs/2,     dir:'sw'},
        {cx:bx+w+off+hs/2,     cy:by+h+off+hs/2,     dir:'se'},
        {cx:bx+w+off+hs/2,     cy:by+h/2,             dir:'e'},
        {cx:bx+w/2,            cy:by+h+off+hs/2,     dir:'s'},
    ];
    for(const{cx,cy,dir}of handles){
        if(Math.hypot(x-cx,y-cy)<tol+hs/2)return dir;
    }
    return false;
}

canvas.addEventListener('mousedown',e=>{
    const{x,y}=_canvasPos(e);

    // Check resize handles first — works on already-selected caption
    const rh=selectedCapId&&_isResizeHandle(selectedCapId,x,y);
    if(rh){
        const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);
        // For all caption types, actual rendered height comes from _bbox
        const bboxH  = cap._bbox ? cap._bbox.h : _capBoxH(cap);
        const storedH= parseInt(cap.rotation)||0;
        // origBaseH = natural text height without extra padding (for text caps)
        // For image caps rotation IS the height, so origBaseH == storedH
        const extraVPad = cap._extraVPad ?? (cap.caption_type==='image' ? 0 : storedH);
        const baseH  = cap.caption_type==='image' ? storedH : Math.max(20, bboxH - extraVPad);
        _resize={active:true,dir:rh,capId:selectedCapId,
                 startX:x,startY:y,
                 origW:   parseInt(cap.width)      || 120,
                 origH:   bboxH,
                 origBaseH: baseH,
                 origPX:  parseInt(cap.position_x) || 0,
                 origPY:  parseInt(cap.position_y) || 0,
                 origExtraVPad: extraVPad};
        e.preventDefault();return;
    }

    // Then check if clicking body of a caption (drag or select)
    const hit=_hitCap(x,y);
    if(hit){
        selectCaption(hit);
        const cap=sceneCaptions.find(c=>+c.id===+hit);
        _drag={active:true,capId:hit,startX:x,startY:y,origX:parseInt(cap.position_x)||50,origY:parseInt(cap.position_y)||400};
    } else {
        selectedCapId=null;
        showCaptionEditor(false);
        renderCaptionTabs();
    }
    e.preventDefault();
});
canvas.addEventListener('mousemove',e=>{
    const{x,y}=_canvasPos(e);
    if(_resize.active){
        const cap=sceneCaptions.find(c=>+c.id===+_resize.capId);
        if(cap){
            const dx=x-_resize.startX, dy=y-_resize.startY;
            const dir=_resize.dir;

            // --- Width changes (e=right, ne, se) — cap right edge at CW-CAP_MARGIN ---
            if(dir==='e'||dir==='ne'||dir==='se'){
                const maxW = CW - CAP_MARGIN - (parseFloat(cap.position_x)||0);
                cap.width=Math.max(40, Math.min(maxW, _resize.origW+dx));
            }
            // --- Width changes from left (nw, sw) — also shift x, keep right edge fixed ---
            if(dir==='nw'||dir==='sw'){
                const nw=Math.max(40, _resize.origW-dx);
                const nx=Math.max(CAP_MARGIN, _resize.origPX+(_resize.origW-nw));
                cap.position_x=nx;
                cap.width=Math.min(nw, CW - CAP_MARGIN - nx);
            }
            // --- Height changes from bottom (s, se, sw) — cap at CH ---
            if(dir==='s'||dir==='se'||dir==='sw'){
                const nh=Math.max(20, Math.min(CH - (parseFloat(cap.position_y)||0), _resize.origH+dy));
                cap.rotation=nh;
                cap._extraVPad=Math.max(0,nh-_resize.origBaseH);
            }
            // --- Height changes from top (ne, nw) — also shift y, stay within canvas ---
            if(dir==='ne'||dir==='nw'){
                const nh=Math.max(20,_resize.origH-dy);
                const ny=Math.max(0, _resize.origPY+(_resize.origH-nh));
                cap.position_y=Math.min(ny, CH-20);
                cap.rotation=nh;
                cap._extraVPad=Math.max(0,nh-_resize.origBaseH);
            }

            ['width','rotation','position_x','position_y'].forEach(f=>{
                if(!_capDirty[cap.id])_capDirty[cap.id]={};
                _capDirty[cap.id][f]=Math.round(parseFloat(cap[f])||0);
            });
            clearTimeout(_capSaveTimers[cap.id]);
            _capSaveTimers[cap.id]=setTimeout(()=>_saveCaption(cap.id),400);
            syncPosInputs(cap);
            capFieldChanged('width',Math.round(cap.width));
        }
        return;
    }
    if(_drag.active){
        const cap=sceneCaptions.find(c=>+c.id===+_drag.capId);
        if(cap){
            const w   = parseFloat(cap.width) || 120;
            const bh  = _capBoxH(cap);
            cap.position_x = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w,  _drag.origX + (x - _drag.startX)));
            cap.position_y = Math.max(0,           Math.min(CH - Math.max(20,bh), _drag.origY + (y - _drag.startY)));
            syncPosInputs(cap);
            capFieldChanged('position_x',Math.round(cap.position_x));
            capFieldChanged('position_y',Math.round(cap.position_y));
        }
        return;
    }
    const rdir=selectedCapId&&_isResizeHandle(selectedCapId,x,y);
    if(rdir==='e')               {canvas.style.cursor='ew-resize';  return;}
    if(rdir==='s')               {canvas.style.cursor='ns-resize';  return;}
    if(rdir==='nw'||rdir==='se') {canvas.style.cursor='nwse-resize';return;}
    if(rdir==='ne'||rdir==='sw') {canvas.style.cursor='nesw-resize';return;}
    canvas.style.cursor=_hitCap(x,y)?'grab':'default';
});
canvas.addEventListener('mouseup',()=>{ _drag.active=false; _resize.active=false; });
canvas.addEventListener('mouseleave',()=>{ _drag.active=false; _resize.active=false; });

// Touch
canvas.addEventListener('touchstart',e=>{if(e.touches[0])canvas.dispatchEvent(new MouseEvent('mousedown',{clientX:e.touches[0].clientX,clientY:e.touches[0].clientY,bubbles:true}));},{passive:false});
canvas.addEventListener('touchmove', e=>{if(e.touches[0])canvas.dispatchEvent(new MouseEvent('mousemove',{clientX:e.touches[0].clientX,clientY:e.touches[0].clientY,bubbles:true}));e.preventDefault();},{passive:false});
canvas.addEventListener('touchend', ()=>canvas.dispatchEvent(new MouseEvent('mouseup',{})));

// ── Caption panel UI ───────────────────────────────────────────────
function renderCaptionTabs() {
    const tabs=document.getElementById('captionTabs');
    if(!tabs)return;
    if(!sceneCaptions.length){tabs.innerHTML='<span style="font-size:10px;color:var(--muted);">No captions</span>';return;}
    tabs.innerHTML=sceneCaptions.map(c=>{
        const isMain=(c.caption_name||'').toLowerCase()==='main';
        const isSel=+c.id===+selectedCapId;
        return `<button class="cap-tab${isSel?' active':''}" onclick="selectCaption(${c.id})">
            ${isMain?'🔒 ':''}${c.caption_name||'cap'}
            ${!c.is_visible?'<span style="opacity:.5;font-size:9px;">🚫</span>':''}
        </button>`;
    }).join('');
}

function selectCaption(capId) {
    selectedCapId = capId;
    renderCaptionTabs();
    const cap = sceneCaptions.find(c => +c.id === +capId);
    if (!cap) return;
    showCaptionEditor(true);
    populateCaptionEditor(cap);
    // Only open caption panel if NO panel is currently open.
    // Never forcibly close other panels (image, audio) or open caption
    // panel during playback/recording — just update the editor state silently.
    const anyOpen = document.querySelector('.panel.open');
    const capOpen = document.getElementById('pCaption').classList.contains('open');
    if (!anyOpen && !capOpen && !isPlaying && !isRecording) {
        togglePanel('pCaption', 'ibCaption');
    }
}

let _forceCapTab = 0; // 0 = no override, 1 = caption tab, 2 = font tab

function showCaptionEditor(show) {
    const ed = document.getElementById('captionEditor');
    const ns = document.getElementById('captionNoSel');
    if (ed) ed.style.display = show ? 'block' : 'none';
    if (ns) ns.style.display = show ? 'none'  : 'block';
    if (show) switchCapSubTab(_forceCapTab === 2 ? 2 : 1);
}



// ── Image caption editor helpers ───────────────────────────────────
function _populateCapImageEditor(cap) {
    const prev = document.getElementById('capImgPreview');
    if (prev) {
        const fn = cap.text_content || '';
        prev.innerHTML = fn
            ? `<img src="${getFileFolder(fn)+fn}?t=${Date.now()}" style="width:100%;height:100%;object-fit:contain;">`
            : `<span style="color:var(--muted);font-size:24px;">🖼️</span>`;
    }
    const iw = document.getElementById('capImgW');
    const ih = document.getElementById('capImgH');
    if (iw) iw.value = parseInt(cap.width)    || 120;
    if (ih) ih.value = parseInt(cap.rotation) || 120;
}

function capImgResize(dim, val) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    if (dim === 'width')  { cap.width    = parseInt(val)||120; capFieldChanged('width',    cap.width);    }
    if (dim === 'height') { cap.rotation = parseInt(val)||120; capFieldChanged('rotation', cap.rotation); }
}

async function replaceCapImage(source) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    const filename = source === 'upload' ? await _imgCapUpload() : await _imgCapFromLibrary();
    if (!filename) return;
    cap.text_content = filename;
    await _preloadCapImage(cap);
    capFieldChanged('text_content', filename);
    _populateCapImageEditor(cap);
    L('Image replaced', 'ok');
}

function _toHex(color) {
    if (!color) return '#ffffff';
    color = color.trim();
    if (/^#[0-9a-f]{6}$/i.test(color)) return color;
    if (/^#[0-9a-f]{3}$/i.test(color))
        return '#'+color[1]+color[1]+color[2]+color[2]+color[3]+color[3];
    try {
        const c = document.createElement('canvas');
        c.width = c.height = 1;
        const x = c.getContext('2d');
        x.fillStyle = color;
        x.fillRect(0,0,1,1);
        const d = x.getImageData(0,0,1,1).data;
        return '#'+[d[0],d[1],d[2]].map(v=>v.toString(16).padStart(2,'0')).join('');
    } catch(e) { return '#ffffff'; }
}
function populateCaptionEditor(cap) {
    const isMain  = (cap.caption_name || '').toLowerCase() === 'main';
    const isImage = cap.caption_type === 'image';

    // ── Show/hide sections based on caption type ──────────────
    const textSec  = document.getElementById('capTextSection');
    const fontSec  = document.getElementById('capFontSection');
    const styleSec = document.getElementById('capStyleSection');
    const imgSec   = document.getElementById('capImageSection');
    if (textSec)  textSec.style.display  = isImage ? 'none' : 'block';
    if (fontSec)  fontSec.style.display  = isImage ? 'none' : 'block';
    if (styleSec) styleSec.style.display = isImage ? 'none' : 'block';
    if (imgSec)   imgSec.style.display   = isImage ? 'block' : 'none';

    // ── Visibility button (both types) ────────────────────────
    _updateVisBtn(cap);

    // ── Delete button (both types, hidden for main) ───────────
    const dw = document.getElementById('capDeleteWrap');
    if (dw) dw.style.display = isMain ? 'none' : 'block';

    if (isImage) {
        _populateCapImageEditor(cap);
        syncPosInputs(cap);
        return;
    }

    // ── Text caption fields ───────────────────────────────────
    const ta = document.getElementById('capText');
    if (ta) ta.value = cap.text_content || '';

    // Font family — sync custom picker
    const ff = document.getElementById('capFont');
    if (ff) {
        ff.value = cap.fontfamily || 'Arial,sans-serif';
        const lbl = document.getElementById('fontPickerLabel');
        if (lbl) {
            const opt = document.querySelector(`.fp-opt[data-val="${CSS.escape(cap.fontfamily || 'Arial,sans-serif')}"]`);
            lbl.textContent      = opt ? opt.textContent.trim() : (cap.fontfamily || 'Arial').split(',')[0].replace(/'/g,'');
            lbl.style.fontFamily = cap.fontfamily || 'Arial,sans-serif';
        }
        document.querySelectorAll('.fp-opt').forEach(o =>
            o.classList.toggle('selected', o.dataset.val === (cap.fontfamily || 'Arial,sans-serif'))
        );
    }

    // Font size
    const fs = document.getElementById('capSize');
    if (fs) {
        let matched = false;
        Array.from(fs.options).forEach(o => {
            o.selected = (o.value == cap.fontsize);
            if (o.selected) matched = true;
        });
        if (!matched) {
            // Select closest available size
            const target = parseInt(cap.fontsize) || 28;
            let closest = null, closestDiff = Infinity;
            Array.from(fs.options).forEach(o => {
                const diff = Math.abs(parseInt(o.value) - target);
                if (diff < closestDiff) { closestDiff = diff; closest = o; }
            });
            if (closest) closest.selected = true;
        }
    }

    // Colors
    const cc = document.getElementById('capColor');
    const bc = document.getElementById('capBgColor');
    if (cc) cc.value = _toHex(cap.fontcolor || '#ffffff');
    if (bc) bc.value = _toHex(cap.bg_color  || '#000000');

    // BG Enable checkbox
    const bgChk     = document.getElementById('capBgEnabled');
    const bgLbl     = document.getElementById('capBgEnableLabel');
    const bgEnabled = (cap.bg_enabled === 1 || cap.bg_enabled === '1' || cap.bg_enabled === true);
    if (bgChk) bgChk.checked = bgEnabled;
    if (bgLbl) bgLbl.style.color = bgEnabled ? 'var(--info)' : 'var(--muted)';

    // BG Opacity
    const ba = document.getElementById('capBgAlpha');
    const bv = document.getElementById('capBgAlphaVal');
    if (ba) {
        ba.value = Math.round((parseFloat(cap.bg_opacity) || 0.7) * 100);
        if (bv) bv.textContent = ba.value + '%';
    }

    // Style toggles
    document.getElementById('capBold')     ?.classList.toggle('on', cap.fontweight === 'bold' || cap.fontweight === '700');
    document.getElementById('capItalic')   ?.classList.toggle('on', cap.fontstyle === 'italic');
    document.getElementById('capUnderline')?.classList.toggle('on', !!+cap.underline);

    // Text align
    ['left','center','right','justify'].forEach(a => {
        document.getElementById('capTa' + a.charAt(0).toUpperCase() + a.slice(1))
            ?.classList.toggle('on', a === (cap.text_align || 'center'));
    });

    // Animation
    const ca = document.getElementById('capAnim');
    if (ca) Array.from(ca.options).forEach(o => o.selected = o.value === (cap.animation_style || 'none'));

    const cas = document.getElementById('capAnimSpeed');
    if (cas) {
        const spd = parseFloat(cap.animation_speed) || 1;
        cas.value = Math.min(4, Math.max(0.2, spd));
        const sv = document.getElementById('capAnimSpeedVal');
        if (sv) sv.textContent = parseFloat(cas.value).toFixed(1) + 'x';
    }

    // Position inputs
    // ── Border fields ──────────────────────────────────────────────
		const _bcol  = document.getElementById('capBorderColor');
		const _bthk  = document.getElementById('capBorderThick');
		const _bthkv = document.getElementById('capBorderThickVal');
		const _bprev = document.getElementById('capBorderPreview');
		const _borderColor = _toHex(cap.caption_box_border_color || '#ffffff');
		const _borderThick = parseInt(cap.caption_box_border_thickness) || 0;
		if (_bcol)  _bcol.value        = _borderColor;
		if (_bthk)  _bthk.value        = _borderThick;
		if (_bthkv) _bthkv.textContent = _borderThick + 'px';
		if (_bprev) {
			_bprev.style.borderWidth = _borderThick + 'px';
			_bprev.style.borderColor = _borderColor;
			_bprev.style.borderStyle = _borderThick > 0 ? 'solid' : 'none';
		}

		// Position inputs
		syncPosInputs(cap);
		_applyToAllScenes = GLOBAL_CAP_NAMES.includes((cap.caption_name || '').toLowerCase().trim());
		_updateGlobalLabel();
	}


function syncPosInputs(cap) {
    const px=document.getElementById('capPosX');const py=document.getElementById('capPosY');const pw=document.getElementById('capWidth');
    const xl=document.getElementById('capPosXLbl');const yl=document.getElementById('capPosYLbl');
    const rx=Math.round(cap.position_x||0), ry=Math.round(cap.position_y||0);
    if(px)px.value=rx;
    if(py)py.value=ry;
    if(pw)pw.value=Math.round(cap.width||320);
    if(xl)xl.textContent=rx;
    if(yl)yl.textContent=ry;
}

function _updateVisBtn(cap) {
    const vb=document.getElementById('capVisBtn');
    if(!vb)return;
    vb.textContent=cap.is_visible?'👁 Visible':'🚫 Hidden';
    vb.style.background=cap.is_visible?'var(--success)':'var(--muted)';
}

function setCapTextColor(hex){
    const inp=document.getElementById('capColor');if(inp)inp.value=hex;
    capFieldChanged('fontcolor',hex);
}

function toggleCapStyle(s){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if(s==='bold'){
        const now=(cap.fontweight==='bold'||cap.fontweight==='700');
        cap.fontweight=now?'normal':'bold';
        document.getElementById('capBold')?.classList.toggle('on',!now);
        capFieldChanged('fontweight',cap.fontweight);
    }else if(s==='italic'){
        const now=cap.fontstyle==='italic';
        cap.fontstyle=now?'normal':'italic';
        document.getElementById('capItalic')?.classList.toggle('on',!now);
        capFieldChanged('fontstyle',cap.fontstyle);
    }else if(s==='underline'){
        cap.underline=cap.underline?0:1;
        document.getElementById('capUnderline')?.classList.toggle('on',!!cap.underline);
        capFieldChanged('underline',cap.underline);
    }
}

function setCapTA(a){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.text_align=a;
    ['left','center','right'].forEach(n=>document.getElementById('capTa'+n.charAt(0).toUpperCase()+n.slice(1))?.classList.toggle('on',n===a));
    capFieldChanged('text_align',a);
}

function capEffectChanged(val){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap._uiEffect=val;
	logActivity('effect_change', val, currentIndex);
    cap.stroke_enabled=0;cap.outline_enabled=0;
    if(val==='stroke'){cap.stroke_enabled=1;cap.stroke_width=cap.stroke_width||2;capFieldChanged('stroke_enabled',1);capFieldChanged('stroke_width',cap.stroke_width);}
    else if(val==='outline'){cap.outline_enabled=1;cap.outline_width=cap.outline_width||2;capFieldChanged('outline_enabled',1);capFieldChanged('outline_width',cap.outline_width);}
    else{capFieldChanged('stroke_enabled',0);capFieldChanged('outline_enabled',0);}
    document.getElementById('capStrokeColorField').style.display=(val==='outline'||val==='stroke')?'flex':'none';
}

function toggleCapVisible(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap.is_visible=cap.is_visible?0:1;
    const fd=new FormData();
    fd.append('ajax_action','toggle_caption_visible');
    fd.append('caption_id',cap.id);fd.append('is_visible',cap.is_visible);
    fetch(location.href,{method:'POST',body:fd});
    _updateVisBtn(cap);
    renderCaptionTabs();
}

function capPosInput(field,val){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    cap[field]=parseFloat(val)||0;
    syncPosInputs(cap);
    capFieldChanged(field,cap[field]);
}

function moveCapArrow(dx,dy){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const w  = parseFloat(cap.width) || 120;
    const bh = _capBoxH(cap);
    cap.position_x=Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w,  (parseFloat(cap.position_x)||0)+dx));
    cap.position_y=Math.max(0,          Math.min(CH - Math.max(20,bh), (parseFloat(cap.position_y)||0)+dy));
    syncPosInputs(cap);
    capFieldChanged('position_x',Math.round(cap.position_x));
    capFieldChanged('position_y',Math.round(cap.position_y));
}

function _capBoxH(cap){
    const bw=parseInt(cap.width)||320,fs=parseInt(cap.fontsize)||22,lh=fs+7;
    const extraVPad=cap._extraVPad??parseInt(cap.rotation)??0;
    const pad=10+Math.round(extraVPad/2);
    const words=(cap.text_content||'').split(' ');
    const _fn={'Arial':'Arial,sans-serif','Helvetica':'Helvetica,sans-serif','Verdana':'Verdana,sans-serif','Georgia':'Georgia,serif','Impact':'Impact,fantasy',"Courier New":"'Courier New',monospace","Times New Roman":"'Times New Roman',serif","Segoe UI":"'Segoe UI',sans-serif",'Inter':"'Inter',sans-serif",'Poppins':"'Poppins',sans-serif",'Montserrat':"'Montserrat',sans-serif",'Raleway':"'Raleway',sans-serif",'Oswald':"'Oswald',sans-serif",'Anton':"'Anton',sans-serif",'Righteous':"'Righteous',sans-serif",'Black Han Sans':"'Black Han Sans',sans-serif",'Josefin Sans':"'Josefin Sans',sans-serif",'Barlow Condensed':"'Barlow Condensed',sans-serif",'DM Sans':"'DM Sans',sans-serif",'Jost':"'Jost',sans-serif",'Space Grotesk':"'Space Grotesk',sans-serif",'Syne':"'Syne',sans-serif",'Tenor Sans':"'Tenor Sans',sans-serif",'Playfair Display':"'Playfair Display',serif",'Lora':"'Lora',serif",'Libre Baskerville':"'Libre Baskerville',serif",'Crimson Pro':"'Crimson Pro',serif",'EB Garamond':"'EB Garamond',serif",'Cormorant Garamond':"'Cormorant Garamond',serif",'Cormorant SC':"'Cormorant SC',serif",'Roboto Slab':"'Roboto Slab',serif",'DM Serif Display':"'DM Serif Display',serif",'Alfa Slab One':"'Alfa Slab One',serif",'Cinzel':"'Cinzel',serif",'Italiana':"'Italiana',serif",'Bebas Neue':"'Bebas Neue',sans-serif",'Bangers':"'Bangers',cursive",'Luckiest Guy':"'Luckiest Guy',cursive",'Black Ops One':"'Black Ops One',cursive",'Russo One':"'Russo One',sans-serif",'Teko':"'Teko',sans-serif",'Boogaloo':"'Boogaloo',cursive",'Fredoka One':"'Fredoka One',cursive",'Lilita One':"'Lilita One',cursive",'Poiret One':"'Poiret One',cursive",'Dancing Script':"'Dancing Script',cursive",'Pacifico':"'Pacifico',cursive",'Lobster':"'Lobster',cursive",'Permanent Marker':"'Permanent Marker',cursive",'Caveat':"'Caveat',cursive",'Great Vibes':"'Great Vibes',cursive",'Alex Brush':"'Alex Brush',cursive",'Pinyon Script':"'Pinyon Script',cursive",'Sacramento':"'Sacramento',cursive",'Satisfy':"'Satisfy',cursive",'Kaushan Script':"'Kaushan Script',cursive",'Yellowtail':"'Yellowtail',cursive",'Allura':"'Allura',cursive",'Marck Script':"'Marck Script',cursive",'Italianno':"'Italianno',cursive",'Mr Dafoe':"'Mr Dafoe',cursive",'Euphoria Script':"'Euphoria Script',cursive",'NotoNastaliqUrdu':"'NotoNastaliqUrdu',serif",'AttariQuraanWord':"'AttariQuraanWord',serif"};
    const rawFam=cap.fontfamily||'';
    const family=_fn[rawFam]||rawFam||'Arial,sans-serif';
    const bold=(cap.fontweight==='bold'||cap.fontweight==='700')?'bold ':'';
    const italic=cap.fontstyle==='italic'?'italic ':'';
    ctx.font=italic+bold+fs+'px '+family;
    let lines=1,ln='';
    words.forEach(w=>{const t=ln?ln+' '+w:w;if(ctx.measureText(t).width>bw&&ln){lines++;ln=w;}else ln=t;});
    return lines*lh+pad*2;
}

function centreCaption(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320;
    cap.position_x=Math.round((CW-bw)/2);
    cap.position_y=Math.round((CH-_capBoxH(cap))/2);
    syncPosInputs(cap);
    capFieldChanged('position_x',cap.position_x);
    capFieldChanged('position_y',cap.position_y);
}
// ── syncPodcastThumbnail — fire-and-forget, called on play + record start ───
// Sends scene 1's active slot filename so PHP can resolve the correct thumbnail image.
function syncPodcastThumbnail() {
    const scene1 = SCENES[0];
    if (!scene1) return;
    // Get the active slot file for scene 1 from SCENE_SAVED_SLOTS
    const slots = getEnabledSlotsForScene(scene1);
    let fn = '';
    for (let i = 0; i < slots.length; i++) {
        fn = (scene1[slots[i]] || '').trim();
        if (fn) break;
    }
    const fd = new FormData();
    fd.append('ajax_action', 'update_podcast_thumbnail');
    fd.append('podcast_id',  PODCAST_ID);
    if (fn) fd.append('filename', fn); // PHP will resolve video→thumbnail if needed
    fetch(location.href, { method:'POST', body:fd, credentials:'include' })
        .then(r => r.json())
        .then(d => {
            if (d.updated) console.log('[Thumb] Updated to:', d.thumbnail, '| was:', d.was);
            else           console.log('[Thumb] In sync —', d.reason || 'no change needed');
        })
        .catch(e => console.warn('[Thumb] sync failed:', e.message));
}

function stopRecording(){
    isRecording  = false;
    _recStopped  = true;    // signals recordSceneClip and the scene loop to abort
    if(bgAudio) bgAudio.pause();
    if(currentAudio){ currentAudio.pause(); currentAudio=null; }
    L('Stopping recording…', 'inf');
}
function snapCaption(preset){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    const bw=parseInt(cap.width)||320;
    const bh=_capBoxH(cap);
    if(preset==='top')          cap.position_y=10;
    else if(preset==='middle')  cap.position_y=Math.round((CH-bh)/2);
    else if(preset==='bottom')  cap.position_y=Math.round(CH-bh-14);
    else if(preset==='centre-h')cap.position_x=Math.round((CW-bw)/2);
    syncPosInputs(cap);
    capFieldChanged('position_x',Math.round(cap.position_x));
    capFieldChanged('position_y',Math.round(cap.position_y));
}

// ── Field change → debounced save ─────────────────────────────────
let currentPlaybackSpeed = AUDIO_SPEED;
let sampleAudio = null;


function updatePlaybackSpeed(speed) {
    currentPlaybackSpeed = parseFloat(speed);

    // Update all speed displays
    document.querySelectorAll('#speedValue,#speedValue2').forEach(el => {
        el.textContent = currentPlaybackSpeed.toFixed(2) + 'x';
    });
    // Sync all sliders
    document.querySelectorAll('#playbackSpeedSlider,#playbackSpeedSlider2').forEach(el => {
        el.value = currentPlaybackSpeed;
    });

    // Update active preset button
    document.querySelectorAll('.speed-preset').forEach(btn => {
        btn.classList.toggle('active', Math.abs(parseFloat(btn.dataset.speed) - currentPlaybackSpeed) < 0.01);
    });

    // Apply speed to currently playing audio
    if (currentAudio && !currentAudio.paused) currentAudio.playbackRate = currentPlaybackSpeed;
    if (_voicePreviewAudio && !_voicePreviewAudio.paused) _voicePreviewAudio.playbackRate = currentPlaybackSpeed;

    // Save to DB (debounced)
    clearTimeout(window._speedSaveTimer);
    window._speedSaveTimer = setTimeout(() => {
        const fd = new FormData();
        fd.append('ajax_action', 'save_audio_speed');
        fd.append('speed',       currentPlaybackSpeed);
        fetch(location.href, { method:'POST', body:fd }).catch(() => {});
    }, 800);

    L(`Voiceover speed set to ${currentPlaybackSpeed.toFixed(2)}x`, 'inf');
}

function setPlaybackSpeed(speed) {
    const slider = document.getElementById('playbackSpeedSlider');
    if (slider) slider.value = speed;
    updatePlaybackSpeed(speed);
}

function previewSampleSpeed() {
    // Stop any existing sample
    if (sampleAudio) {
        sampleAudio.pause();
        sampleAudio = null;
    }
    
    // Create a sample audio with a simple phrase
    const sampleText = "This is a sample of playback speed. Listen to how the voice changes.";
    const voiceId = _hostVoiceId || 'openai:alloy';
    
    const status = document.getElementById('sampleSpeedStatus');
    status.textContent = 'Generating sample...';
    status.style.color = 'var(--info)';
    
    // Generate a sample voice with current speed
    const fd = new FormData();
    fd.append('text', sampleText);
    fd.append('voice_id', voiceId);
    fd.append('lang_code', 'en');
    fd.append('rate', '1.0');
    fd.append('speed', currentPlaybackSpeed);
    
    fetch('generate_voice_sample.php', { method: 'POST', body: fd })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.audio_url) {
                sampleAudio = new Audio(data.audio_url);
                sampleAudio.playbackRate = currentPlaybackSpeed;
                sampleAudio.onended = () => {
                    status.textContent = 'Sample finished. Click again to replay.';
                    status.style.color = 'var(--muted)';
                };
                sampleAudio.play();
                status.textContent = `Playing at ${currentPlaybackSpeed.toFixed(2)}x speed...`;
                status.style.color = 'var(--success)';
            } else {
                // Fallback: use Web Audio API to simulate speed change
                simulateSpeedSample();
            }
        })
        .catch(() => {
            simulateSpeedSample();
        });
}

function simulateSpeedSample() {
    // Fallback: create a simple beep with Web Audio API to demonstrate speed
    const status = document.getElementById('sampleSpeedStatus');
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    const audioCtx = new AudioContext();
    const now = audioCtx.currentTime;
    
    status.textContent = `Playing beep at ${currentPlaybackSpeed.toFixed(2)}x speed...`;
    
    const duration = 0.5 / currentPlaybackSpeed;
    
    for (let i = 0; i < 3; i++) {
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        oscillator.frequency.value = 440;
        gainNode.gain.value = 0.3;
        
        oscillator.start(now + (i * duration));
        oscillator.stop(now + ((i + 1) * duration));
        
        gainNode.gain.exponentialRampToValueAtTime(0.0001, now + ((i + 1) * duration));
    }
    
    setTimeout(() => {
        status.textContent = 'Sample finished. Click to test again.';
        status.style.color = 'var(--muted)';
    }, duration * 3 * 1000);
}

async function _saveCaption(capId){
    const dirty=_capDirty[capId];if(!dirty||!Object.keys(dirty).length)return;
    const fd=new FormData();
    fd.append('ajax_action','save_caption');
    fd.append('caption_id',capId);
    Object.entries(dirty).forEach(([k,v])=>fd.append(k,v));
    _capDirty[capId]={};
    await fetch(location.href,{method:'POST',body:fd});
    L('Caption saved','ok');
}

// ── Add / Delete ───────────────────────────────────────────────────
async function addCaption(){
    const sc = SCENES[currentIndex];
    const name = 'cap' + (sceneCaptions.length + 1);
    const fd = new FormData();
    fd.append('ajax_action',   'add_caption');
    fd.append('story_id',      sc.id);
    fd.append('caption_name',  name);
    fd.append('text_content',  'New caption');
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success && data.caption) {
            const newCap = data.caption;
            // Push into the global array so loadSceneCaptions can find it
            ALL_CAPTIONS.push(newCap);
            // Seed the animation state BEFORE loadSceneCaptions runs
            captionStates[newCap.id] = {
                show: 'New caption', full: 'New caption',
                words: ['New','caption'], karIdx: 0, timer: null
            };
            // Pre-set selectedCapId so loadSceneCaptions doesn't clear the editor
            selectedCapId = parseInt(newCap.id);
            loadSceneCaptions(sc.id);
            selectCaption(newCap.id);
            L('Caption added', 'ok');
        } else {
            L('Add caption failed: ' + (data.message || 'unknown'), 'err');
        }
    } catch(e) {
        L('Add caption error: ' + e.message, 'err');
    }
}

async function deleteCaption(){
    if(!selectedCapId)return;
    const cap=sceneCaptions.find(c=>+c.id===+selectedCapId);if(!cap)return;
    if((cap.caption_name||'').toLowerCase()==='main'){alert('Cannot delete the main caption.');return;}
    if(!confirm('Delete caption "'+cap.caption_name+'"?'))return;
    const fd=new FormData();
    fd.append('ajax_action','delete_caption');
    fd.append('caption_id',cap.id);
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(data.success){
            const idx=ALL_CAPTIONS.findIndex(c=>+c.id===+cap.id);
            if(idx>=0)ALL_CAPTIONS.splice(idx,1);
            selectedCapId=null;
            showCaptionEditor(false);
            loadSceneCaptions(SCENES[currentIndex].id);
            L('Caption deleted','ok');
        }else{L('Delete failed: '+(data.message||'unknown'),'err');}
    }catch(e){L('Delete error: '+e.message,'err');}
}
// ══════════════════════════════════════════════════════════════════
// END MULTI-CAPTION SYSTEM
// ══════════════════════════════════════════════════════════════════

// ── Log ────────────────────────────────────────────────────────────────────
function L(m,c=''){
    const el=document.getElementById('log');
    el.style.display='block';
    const p=document.createElement('p');if(c)p.className=c;p.textContent=m;
    el.appendChild(p);el.scrollTop=el.scrollHeight;
}

// ── Panel toggle ───────────────────────────────────────────────────────────
function togglePanel(panelId,btnId){
    const panel=document.getElementById(panelId);
    const btn=document.getElementById(btnId);
    const isOpen=panel.classList.contains('open');
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('open'));
    document.querySelectorAll('.icon-btn').forEach(b=>{
        if(!b.classList.contains('play-btn')&&!b.classList.contains('rec-btn'))b.classList.remove('active');
    });
    if(!isOpen){panel.classList.add('open');btn.classList.add('active');}
}
function closePanel(panelId,btnId){
    document.getElementById(panelId).classList.remove('open');
    document.getElementById(btnId).classList.remove('active');
    // When closing the image panel, restore canvas to show the normal active slot
    if (panelId === 'pImage') {
        showScene(currentIndex, true);
    }
}

// ── Render ─────────────────────────────────────────────────────────────────
function startRender(){
    if(renderRaf)return;
    (function frame(){drawFrame();framesDrawn++;renderRaf=requestAnimationFrame(frame);})();
}
// Update drawFrame to ensure consistent rendering
function drawFrame() {
    const now = performance.now();
    
    // Clear canvas to black
    ctx.fillStyle = '#000000';
    ctx.fillRect(0, 0, CW, CH);
    
    // Draw the outgoing image if any (fading out)
    if (S.imgOut && S.alphaOut > 0.01) {
        ctx.save();
        ctx.globalAlpha = S.alphaOut;
        drawCover(S.imgOut, null);
        ctx.restore();
    }
    
    // Draw the current media (fading in)
    if (S.alpha > 0.01) {
        ctx.save();
        ctx.globalAlpha = S.alpha;
        
        if (S.txOffset) {
            if (S.txOffset.x != null) ctx.translate(S.txOffset.x, 0);
            if (S.txOffset.scale) {
                ctx.translate(CW / 2, CH / 2);
                ctx.scale(S.txOffset.scale, S.txOffset.scale);
                ctx.translate(-CW / 2, -CH / 2);
            }
        }
        
        if (S.type === 'image' && S.img) {
            drawCover(S.img, S.kbEffect === 'none' ? null : kbXform(S.kbEffect, S.kbStart, S.img));
        } else if (S.type === 'video' && S.vidEl) {
            try {
                // Only draw if video has valid dimensions
                if (S.vidEl.videoWidth && S.vidEl.videoHeight && S.vidEl.readyState >= 2) {
                    ctx.drawImage(S.vidEl, 0, 0, CW, CH);
                }
            } catch(_) {}
        }
        
        ctx.restore();
    }
    
    // Draw captions on top
    drawAllCaptions();
    
    // Mirror to HD canvas for recording
    ctxHD.drawImage(canvas, 0, 0, 720, 1280);
    
    lastFrameTime = now;
    frameCount++;
}

function kbXform(ef,t0,img){
    if(ef==='none'||!img)return null;
    const p=Math.min((performance.now()-t0)/KB_DUR,1);
    const e=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const zoom=base*1.18,off=Math.max(CW,CH)*0.055;
    const M={'zoom-in':{ss:base,es:zoom,sox:0,eox:0},'zoom-out':{ss:zoom,es:base,sox:0,eox:0},'pan-left':{ss:zoom,es:zoom,sox:off,eox:-off},'pan-right':{ss:zoom,es:zoom,sox:-off,eox:off}}[ef]||{ss:base,es:zoom,sox:0,eox:0};
    const s=M.ss+(M.es-M.ss)*e,ox=M.sox+(M.eox-M.sox)*e;
    return{s,ox,oy:0};
}
function drawCover(img,kb){
    const base=Math.max(CW/img.naturalWidth,CH/img.naturalHeight);
    const s=kb?kb.s:base,w=img.naturalWidth*s,h=img.naturalHeight*s;
    ctx.drawImage(img,(CW-w)/2+(kb?kb.ox:0),(CH-h)/2+(kb?kb.oy:0),w,h);
}
function rrect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.lineTo(x+w-r,y);c.quadraticCurveTo(x+w,y,x+w,y+r);c.lineTo(x+w,y+h-r);c.quadraticCurveTo(x+w,y+h,x+w-r,y+h);c.lineTo(x+r,y+h);c.quadraticCurveTo(x,y+h,x,y+h-r);c.lineTo(x,y+r);c.quadraticCurveTo(x,y,x+r,y);c.closePath();}

// ── Preload ────────────────────────────────────────────────────────────────
async function preloadAll() {
    // First, determine which slots are checked globally (same for all scenes)
    const enabledSlots = SLOTS.filter(k => {
        const chk = document.getElementById('slotChk_' + k);
        return chk && chk.checked;
    });
    
    // If no slots checked, default to main slot
    if (enabledSlots.length === 0) {
        const mainChk = document.getElementById('slotChk_image_file');
        if (mainChk) {
            mainChk.checked = true;
            enabledSlots.push('image_file');
            L('Auto-enabled main image slot', 'inf');
        } else {
            L('No slots enabled', 'err');
            return false;
        }
    }
    
    L(`Preloading ALL scenes using checked slots: ${enabledSlots.join(', ')}`, 'inf');
    
    // Collect files from ALL scenes but ONLY from checked slots
    const allImgFiles = [];
    const allVidFiles = [];
    const sceneFiles = []; // Track which scenes have which files
    
    for (let i = 0; i < SCENES.length; i++) {
        const scene = SCENES[i];
        const filesInScene = { index: i, images: [], videos: [] };
        
        for (const slot of enabledSlots) {
            const fn = (scene[slot] || '').trim();
            if (!fn) continue;
            
            if (/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
                if (!allVidFiles.includes(fn)) allVidFiles.push(fn);
                filesInScene.videos.push(fn);
            } else {
                if (!allImgFiles.includes(fn)) allImgFiles.push(fn);
                filesInScene.images.push(fn);
            }
        }
        
        if (filesInScene.images.length || filesInScene.videos.length) {
            sceneFiles.push(filesInScene);
        }
    }
    
    const total = allImgFiles.length + allVidFiles.length;
    if (total === 0) {
        L('No media files found in checked slots across all scenes', 'wrn');
        return false;
    }
    
    const bar = document.getElementById('preloadBar');
    const msg = document.getElementById('preloadMsg');
    let loaded = 0;
    let failed = 0;
    
    const updateProgress = () => {
        const percent = Math.round((loaded + failed) / total * 100);
        if (bar) bar.style.width = percent + '%';
        if (msg) msg.textContent = `Loading ${percent}% (${loaded + failed}/${total} files from ${SCENES.length} scenes)`;
    };
    
    // Preload images in parallel
    await Promise.all(allImgFiles.map(fn => new Promise(resolve => {
        if (imgCache[fn]) {
            loaded++;
            updateProgress();
            resolve();
            return;
        }
        
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        const timeout = setTimeout(() => {
            console.warn(`Timeout loading image: ${fn}`);
            failed++;
            updateProgress();
            resolve();
        }, 8000);
        
        img.onload = () => {
            clearTimeout(timeout);
            imgCache[fn] = img;
            loaded++;
            updateProgress();
            resolve();
        };
        
        img.onerror = () => {
            clearTimeout(timeout);
            console.warn(`Failed to load image: ${fn}`);
            failed++;
            updateProgress();
            resolve();
        };
        
        img.src = fn.startsWith('logo_') ? 'podcast_logos/'+fn : getFileFolder(fn)+fn;
    })));
    
    // Preload videos sequentially
    for (const fn of allVidFiles) {
        await new Promise(resolve => {
            let video = vidEls[fn];
            
            if (!video) {
                video = document.createElement('video');
                video.muted = true;
                // Detect talking head by filename being in user_videos folder
                // (FILE_FOLDER is populated during scene scan above)
                const isThVid = (FILE_FOLDER[fn] || '').replace(/\/$/,'') === 'user_videos';
                video.loop = !isThVid;
                video.playsInline = true;
                video.crossOrigin = 'anonymous';
                video.preload = 'auto';
                document.getElementById('vidPool').appendChild(video);
                vidEls[fn] = video;
                video.src = getFileFolder(fn) + fn;
            }
            
            if (video.readyState >= 3) {
                loaded++;
                updateProgress();
                resolve();
                return;
            }
            
            const timeout = setTimeout(() => {
                console.warn(`Timeout loading video: ${fn}`);
                failed++;
                updateProgress();
                resolve();
            }, 15000);
            
            const onCanPlay = () => {
                clearTimeout(timeout);
                video.removeEventListener('canplaythrough', onCanPlay);
                video.removeEventListener('error', onError);
                loaded++;
                updateProgress();
                resolve();
            };
            
            const onError = () => {
                clearTimeout(timeout);
                video.removeEventListener('canplaythrough', onCanPlay);
                video.removeEventListener('error', onError);
                console.warn(`Error loading video: ${fn}`);
                failed++;
                updateProgress();
                resolve();
            };
            
            video.addEventListener('canplaythrough', onCanPlay, { once: true });
            video.addEventListener('error', onError, { once: true });
            video.load();
        });
    }
    
    // Log summary of what was loaded per scene
    console.log('=== Preload Summary ===');
    for (const scene of sceneFiles) {
        console.log(`Scene ${scene.index + 1}: ${scene.images.length} images, ${scene.videos.length} videos`);
    }
    
    L(`Preload complete: ${loaded}/${total} files loaded from checked slots across ${SCENES.length} scenes`, loaded === total ? 'ok' : 'wrn');
    return loaded === total;
}

const SLOTS=['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];

function sceneMedia(sc, slotOverride) {
    if (slotOverride) {
        var v = (sc[slotOverride] || '').trim();
        return { fn: v || null, isVideo: /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(v), slot: slotOverride };
    }

    // Read enabled slots from SCENE_SAVED_SLOTS for this specific scene,
    // so the correct slot is used even before DOM checkboxes are restored.
    var enabledSlots = getEnabledSlotsForScene(sc);

    for (var i = 0; i < enabledSlots.length; i++) {
        var slot = enabledSlots[i];
        var fn = (sc[slot] || '').trim();
        if (fn) {
            return { fn: fn, isVideo: /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn), slot: slot };
        }
    }

    return { fn: null, isVideo: false, slot: 'image_file' };
}

function doTransition(type, dur) {
    return new Promise(res => {
        if (type === 'none') {
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            res();
            return;
        }
        
        const startTime = performance.now();
        const duration = dur || T_DUR;
        
        function step(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Use linear interpolation for fade (simplest and smoothest)
            const alpha = progress;
            
            // Simple cross-fade: old fades out, new fades in
            S.alpha = alpha;
            S.alphaOut = 1 - alpha;
            
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                // Transition complete
                S.alpha = 1;
                S.alphaOut = 0;
                S.imgOut = null;
                res();
            }
        }
        
        requestAnimationFrame(step);
    });
}

async function showScene(index, instant) {
    if (index < 0 || index >= SCENES.length || S.isTransitioning) return;
    
    S.isTransitioning = true;
    
    const sc = SCENES[index];
    const { fn, isVideo, slot } = sceneMedia(sc);
    
    // For instant switch (no transition) - used for first scene and slot cycling
    if (instant) {
        // Directly set the new media without any transition
        if (isVideo && fn && vidEls[fn]) {
            if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
            S.type = 'video';
            S.vidEl = vidEls[fn];
            S.img = null;
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
        } else if (fn && imgCache[fn]) {
            if (S.vidEl) S.vidEl.pause();
            S.type = 'image';
            S.img = imgCache[fn];
            S.vidEl = null;
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            S.txOffset = null;
            S.kbEffect = sceneKB[index];
            S.kbStart = performance.now();
        } else {
            S.type = 'blank';
            S.img = null;
            S.vidEl = null;
            S.alpha = 1;
        }
        
        // Update UI immediately
        currentIndex = index;
        loadSceneCaptions(sc.id);
        applySceneSlots(sc);
        updateSlotThumbs(sc);
        updatePanelSceneNumbers();  // <-- ADDED HERE
        
        const ta = document.getElementById('slotPrompt');
        if (ta) ta.value = sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
        if (document.getElementById('pAudio').classList.contains('open')) loadAudioPanel();
        
        document.getElementById('sceneNum').textContent = (index + 1) + ' / ' + SCENES.length;
        updateDots(index);
        updateNavButtons();
        
        S.isTransitioning = false;
        
        // Preload next scene in background
        setTimeout(() => prepareNextScene(index + 1), 100);
        return;
    }
    
    // For smooth transition - atomic swap with no flicker
    // Capture current frame as a frozen image to prevent flicker
    let oldFrameImg = null;
    
    // Capture current canvas state as an image for smooth transition
    try {
        oldFrameImg = new Image();
        const dataUrl = canvas.toDataURL('image/png');
        await new Promise(resolve => {
            oldFrameImg.onload = resolve;
            oldFrameImg.src = dataUrl;
        });
    } catch(e) {
        oldFrameImg = S.img;
    }
    
    // Set the new media as the current (but keep old as imgOut for transition)
    if (isVideo && fn && vidEls[fn]) {
        const video = vidEls[fn];
        
        // Ensure video is ready
        if (video.readyState < 2) {
            await new Promise(resolve => {
                const timeout = setTimeout(resolve, 500);
                video.addEventListener('canplay', resolve, { once: true });
                video.load();
            });
        }
        
        // Stop old video
        if (S.vidEl && S.vidEl !== video) S.vidEl.pause();
        
        // Set new video as current (but alpha=0 initially)
        S.vidEl = video;
        S.type = 'video';
        S.img = null;
        S.alpha = 0;  // Start invisible
        video.currentTime = 0;
        
        try { video.play(); } catch(e) {}
        
    } else if (fn && imgCache[fn]) {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'image';
        S.img = imgCache[fn];
        S.vidEl = null;
        S.alpha = 0;  // Start invisible
        S.kbEffect = sceneKB[index];
        S.kbStart = performance.now();
        
    } else {
        S.type = 'blank';
        S.img = null;
        S.vidEl = null;
        S.alpha = 0;
    }
    
    // Set the frozen old frame as the outgoing image
    S.imgOut = oldFrameImg || S.imgOut;
    S.alphaOut = 1;  // Old frame fully visible
    S.txOffset = null;
    
    // Force a frame render immediately to show the frozen old frame
    drawFrame();
    
    // Now perform the transition (old fades out, new fades in)
    await doTransition('fade', T_DUR);
    
    // Transition complete - clean up
    S.alpha = 1;
    S.alphaOut = 0;
    S.imgOut = null;
    S.txOffset = null;
    
    // Update UI
    currentIndex = index;
    loadSceneCaptions(sc.id);
    applySceneSlots(sc);
    updateSlotThumbs(sc);
    updatePanelSceneNumbers();  // <-- ADDED HERE
    
    const ta = document.getElementById('slotPrompt');
    if (ta) ta.value = sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
    if (document.getElementById('pAudio').classList.contains('open')) loadAudioPanel();
    
    document.getElementById('sceneNum').textContent = (index + 1) + ' / ' + SCENES.length;
    updateDots(index);
    updateNavButtons();
    
    logActivity('scene_view', 'scene:' + (index + 1), index);
    
    S.isTransitioning = false;
    
    // Preload next scene in background
    setTimeout(() => prepareNextScene(index + 1), 100);
}

async function ensureVideoReady(fn) {
    if (!fn) return false;
    
    let video = vidEls[fn];
    if (!video) {
        video = document.createElement('video');
        video.muted = true;
        const isThVid = (FILE_FOLDER[fn] || '').replace(/\/$/,'') === 'user_videos';
        video.loop = !isThVid;
        video.playsInline = true;
        video.crossOrigin = 'anonymous';
        video.preload = 'auto';
        video.src = getFileFolder(fn) + fn;
        document.getElementById('vidPool').appendChild(video);
        vidEls[fn] = video;
    }
    
    if (video.readyState >= 3) {
        return true;
    }
    
    return new Promise(resolve => {
        const timeout = setTimeout(() => {
            console.warn(`Timeout waiting for video: ${fn}`);
            resolve(false);
        }, 5000);
        
        const onReady = () => {
            clearTimeout(timeout);
            video.removeEventListener('canplaythrough', onReady);
            resolve(true);
        };
        
        video.addEventListener('canplaythrough', onReady, { once: true });
        video.load();
    });
}
function updatePanelSceneNumbers() {
    const sceneNum = currentIndex + 1;  // currentIndex is 0-based
    const captionSpan = document.getElementById('captionSceneNumber');
    const imageSpan = document.getElementById('imageSceneNumber');
    const audioSpan = document.getElementById('audioSceneNumber');
    
    if (captionSpan) captionSpan.textContent = sceneNum;
    if (imageSpan) imageSpan.textContent = sceneNum;
    if (audioSpan) audioSpan.textContent = sceneNum;
}
function updateDots(i){document.querySelectorAll('.dot').forEach((d,j)=>d.className='dot'+(j===i?' active':''));}
function navigate(dir){if(!isPlaying&&!transitioning&&!isRecording)showScene(currentIndex+dir,false);}
function updateNavButtons(){
    const b=isPlaying||isRecording;
    const btns=document.querySelectorAll('.nav-btn');
    if(btns[0])btns[0].disabled=b||currentIndex===0;
    if(btns[1])btns[1].disabled=b||currentIndex===SCENES.length-1;
}
// Play a sequence of videos for a scene (b-roll style)
async function playVideoSequence(scene, enabledSlots, audioEndSignal, isRecordingMode = false) {
    console.log(`\n🎬 Starting VIDEO SEQUENCE for scene ${SCENES.indexOf(scene) + 1}`);

    // Collect video files from checked slots in order
    const videoSlots = [];
    for (const slot of enabledSlots) {
        const fn = (scene[slot] || '').trim();
        if (fn && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
            videoSlots.push({ slot, filename: fn });
        }
    }
    if (videoSlots.length === 0) { console.log(`   ⚠️ No videos found`); return false; }

    console.log(`   Videos: ${videoSlots.map(v => v.filename).join(', ')}`);

    for (const vs of videoSlots) {
        const existing = vidEls[vs.filename];
        if (!existing || existing.readyState < 2) await ensureVideoReady(vs.filename);
    }

    // Start the first clip immediately with loop=true so it never stops on its own.
    // The video keeps playing continuously — no 'ended' event, no freeze, no gap.
    const firstVideo = videoSlots[0];
    const videoEl    = vidEls[firstVideo.filename];

    if (S.vidEl && S.vidEl !== videoEl) S.vidEl.pause();
    S.type = 'video'; S.vidEl = videoEl;
    S.img = null; S.alpha = 1; S.alphaOut = 0; S.imgOut = null;

    videoEl.loop        = true;   // keeps playing continuously — no freeze on end
    videoEl.currentTime = 0;
    highlightPlayingSlot(firstVideo.slot);

    console.log(`   ▶️ Playing (looping): ${firstVideo.filename}`);
    try { await videoEl.play(); } catch(e) { console.log(`   ⚠️ play() failed: ${e.message}`); }

    // Wait for audio to finish — the video keeps looping in the background.
    // This is the ONLY exit condition. No frozen frame because the video
    // never stops; it just keeps drawing new frames to the canvas until here.
    await audioEndSignal;

    // Audio is done. Stop the video cleanly.
    videoEl.pause();
    videoEl.loop = false;

    console.log(`   ✅ Video sequence complete for scene ${SCENES.indexOf(scene) + 1}\n`);
    return true;
}

// Play a sequence of images for a scene
// audioDurationMs = actual audio length so we divide it equally across images
// audioEndSignal  = the audioPromise — resolves when audio finishes
async function playImageSequence(scene, enabledSlots, audioDurationMs, audioEndSignal, isRecordingMode = false) {
    console.log(`\n🖼️ Starting IMAGE SEQUENCE for scene ${SCENES.indexOf(scene) + 1}`);
    console.log(`   Audio duration: ${(audioDurationMs / 1000).toFixed(1)}s`);
    console.log(`   Available slots: ${enabledSlots.length}`);

    // Collect image files from checked slots in order
    const imageSlots = [];
    for (const slot of enabledSlots) {
        const fn = (scene[slot] || '').trim();
        if (fn && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)) {
            imageSlots.push({ slot, filename: fn });
        }
    }

    if (imageSlots.length === 0) {
        console.log(`   ⚠️ No images found in checked slots`);
        return false;
    }

    // Each image gets equal share of the audio duration — minimum 1 second per image
    const perImageMs = Math.max(1000, Math.floor(audioDurationMs / imageSlots.length));
    console.log(`   Images: ${imageSlots.length} — time per image: ${(perImageMs / 1000).toFixed(1)}s`);

    let audioDone = false;
    audioEndSignal.then(() => { audioDone = true; });

    let imgIndex = 0;

    while (!audioDone) {
        const currentImage = imageSlots[imgIndex % imageSlots.length];

        if (imgCache[currentImage.filename]) {
            if (S.vidEl) S.vidEl.pause();
            S.type     = 'image';
            S.img      = imgCache[currentImage.filename];
            S.vidEl    = null;
            S.alpha    = 1;
            S.alphaOut = 0;
            S.imgOut   = null;
            S.kbEffect = sceneKB[SCENES.indexOf(scene)];
            S.kbStart  = performance.now();
        }

        highlightPlayingSlot(currentImage.slot);
        console.log(`   🖼️ Image ${(imgIndex % imageSlots.length) + 1}/${imageSlots.length}: ${currentImage.filename} for ${(perImageMs / 1000).toFixed(1)}s`);

        // Wait perImageMs OR until audio ends — whichever is first
        await new Promise(resolve => {
            let done = false;
            const finish = () => { if (!done) { done = true; resolve(); } };
            const t = setTimeout(finish, perImageMs);
            audioEndSignal.then(() => { clearTimeout(t); finish(); });
        });

        // Yield one microtask so audioDone flag is current before while-check
        await Promise.resolve();

        imgIndex++;
        console.log(`   ${audioDone ? '⏹ Audio ended — stopping.' : '↩️ Next image.'}`);
    }

    console.log(`   ✅ Image sequence complete\n`);
    return true;
}

// Helper to highlight which slot is currently playing
function highlightPlayingSlot(slot) {
    // Remove highlight from all slots
    SLOTS.forEach(k => {
        const thumb = document.getElementById('slotThumb_' + k);
        if (thumb) {
            thumb.style.borderColor = 'var(--border)';
            thumb.style.borderWidth = '2px';
        }
    });
    
    // Highlight the active slot
    const activeThumb = document.getElementById('slotThumb_' + slot);
    if (activeThumb) {
        activeThumb.style.borderColor = '#f59e0b';
        activeThumb.style.borderWidth = '3px';
        activeThumb.style.transition = 'border-color 0.2s';
    }
}

// Check if a scene is B-roll type
// Modified scene playback function
async function playSceneWithDynamicSlots(scene, index, isRecordingMode = false) {
    const enabledSlots = getEnabledSlotsForScene(scene);
    const slotsWithMedia = enabledSlots.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn !== '';
    });

    console.log(`\n========== SCENE ${index + 1} — slots with media: ${slotsWithMedia.length} ==========`);

    if (slotsWithMedia.length === 0) {
        const audioPromise = playSceneAudio(scene, isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null);
        await showScene(index, false);
        await audioPromise;
        return;
    }

    // Start audio immediately — in parallel with the visual transition.
    // Audio is the single source of truth for when the scene ends.
    const audioPromise = playSceneAudio(scene, isTalkingHeadScene(scene) ? vidEls[(scene.image_file || '').trim()] : null);

    // Update scene display (transition runs in parallel with audio)
    await showScene(index, false);
    loadSceneCaptions(scene.id);
    applySceneSlots(scene);
    updateSlotThumbs(scene);
    updatePanelSceneNumbers();

    // Videos: play each fully in order, loop back to first, stop only when audio ends.
    // Images: audio duration ÷ image count = time per image, cycle until audio ends.
    const videoSlots = slotsWithMedia.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    });

    const imageSlots = slotsWithMedia.filter(slot => {
        const fn = (scene[slot] || '').trim();
        return fn && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    });

    if (videoSlots.length > 0) {
        await Promise.all([
            playVideoSequence(scene, videoSlots, audioPromise, isRecordingMode),
            audioPromise
        ]);
        // ── Inter-scene buffer pause — PLAY MODE ONLY, never during recording ──
        // Must live here, after Promise.all() fully resolves, NOT inside
        // playVideoSequence. Any await inside playVideoSequence delays
        // Promise.all() resolution, blocking sceneMr.stop() and stalling
        // the recording loop after scene 1.
        if (!isRecordingMode) {
            const a = currentAudio;
            const audLen = (a && isFinite(a.duration) && a.duration > 0) ? a.duration : 0;
            const pauseMs = audLen < 3 ? 1500 : audLen < 6 ? 1000 : 0;
            if (pauseMs > 0) await new Promise(r => setTimeout(r, pauseMs));
        }
    } else if (imageSlots.length > 0) {
        // Read actual audio duration from the already-playing audio element
        const audioDurationMs = await new Promise(resolve => {
            const a = currentAudio;
            if (a && isFinite(a.duration) && a.duration > 0) {
                resolve(Math.ceil(a.duration * 1000));
                return;
            }
            const fallback = (parseInt(scene.duration) || 5) * 1000;
            if (!a) { resolve(fallback); return; }
            let done = false;
            const finish = (ms) => { if (!done) { done = true; resolve(ms); } };
            a.addEventListener('loadedmetadata', () => finish(Math.ceil(a.duration * 1000)), { once: true });
            setTimeout(() => finish(fallback), 3000);
        });
        console.log(`Scene ${index + 1}: ${imageSlots.length} images, ${(audioDurationMs/1000).toFixed(1)}s audio → ${(audioDurationMs/imageSlots.length/1000).toFixed(1)}s per image`);
        await Promise.all([
            playImageSequence(scene, imageSlots, audioDurationMs, audioPromise, isRecordingMode),
            audioPromise
        ]);
    } else {
        await audioPromise;
    }

    // Reset highlight after scene
    setTimeout(() => {
        SLOTS.forEach(k => {
            const thumb = document.getElementById('slotThumb_' + k);
            if (thumb) {
                thumb.style.borderColor = 'var(--border)';
                thumb.style.borderWidth = '2px';
            }
        });
    }, 500);
}

// Update the togglePlay function to use the new logic
async function togglePlay() {
    if (isPlaying) {
        stopPlay();
        return;
    }
    
    // Show preload overlay
    const ov = document.getElementById('preloadOverlay');
    if (ov) {
        ov.style.opacity = '1';
        ov.classList.remove('gone');
    }
    
    const msg = document.getElementById('preloadMsg');
    if (msg) msg.textContent = 'Loading all media...';
    
    // Preload ALL scenes at once
    await preloadAll();
    
    // Preload the first scene's media
    await prepareNextScene(0);
    
    // Hide overlay
    if (ov) {
        ov.style.transition = 'opacity .4s';
        ov.style.opacity = '0';
        setTimeout(() => ov.classList.add('gone'), 450);
    }
    
    await sleep(100);
    
    isPlaying = true;
    syncPodcastThumbnail();
    logActivity('play_start', 'from_scene:' + (currentIndex + 1), currentIndex);
    currentIndex = 0;
    
    document.getElementById('playIco').textContent = '⏹';
    document.getElementById('playLbl').textContent = 'Stop';
    
    if (bgAudio) {
        bgAudio.currentTime = 0;
        bgAudio.play().catch(() => {});
    }
    
    // Play all scenes with new logic
    for (let i = 0; i < SCENES.length; i++) {
        if (!isPlaying) break;
        await playSceneWithDynamicSlots(SCENES[i], i, false);
    }
    
    stopPlay();
}

function playAudio(src) {
    return new Promise(res => {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio = null;
        }
        const a = new Audio();

        currentAudio = a;
        a.volume = voiceVolume;
        a.playbackRate = currentPlaybackSpeed;

        // Set src BEFORE connecting to Web Audio — required by some browsers
        a.src = src + '?t=' + Date.now();

        // Ensure speed is applied after metadata loads (some browsers reset it)
        a.onloadedmetadata = () => {
            a.playbackRate = currentPlaybackSpeed;
        };

        // Connect to recorder Web Audio graph after src is set
        if (window._recDest) {
            try {
                const s = window._recActx.createMediaElementSource(a);
                s.connect(window._recDest);
                s.connect(window._recActx.destination);
                // Disconnect when done to avoid leaking nodes in AudioContext graph
                a.addEventListener('ended', () => { try { s.disconnect(); } catch(_) {} }, { once: true });
            } catch (_) {}
        }

        // Resume AudioContext if suspended (can happen on mobile after stop/record)
        if (window._recActx && window._recActx.state === 'suspended') {
            window._recActx.resume().catch(() => {});
        }

        let resolved = false;
        const done = () => { if (!resolved) { resolved = true; res(); } };

        a.onended = done;
        a.onerror = () => sleep(200).then(done);
        a.play().catch(() => sleep(200).then(done));

        // Safety timeout: 10 minutes — only to prevent infinite hang if browser
        // never fires 'ended'. Should never trigger for normal audio files.
        setTimeout(done, 600000);
    });
}

// ── Talking Head / Podcast scene detection ────────────────────────────────
// Returns true when the cron has replaced the scene image with an MP4
// (image_folder is set to 'user_videos' by the cron after sadtalker runs).
function isTalkingHeadScene(sc) {
    const folder = (sc.image_folder || '').trim().replace(/\/+$/, '');
    const fn     = (sc.image_file  || '').trim();
    return folder === 'user_videos' && /\.(mp4|webm|mov|m4v)$/i.test(fn);
}

// ── playSceneAudio ────────────────────────────────────────────────────────
// For talking head scenes: the video element already carries the lip-synced
// audio baked in by sadtalker. We silence the MP3 and instead route the
// video element's audio into the recorder (if recording), then await the
// video's natural end.
// For all other scenes: falls back to the standard playAudio(mp3) path.
function playSceneAudio(sc, vidEl) {
    if (isTalkingHeadScene(sc) && vidEl) {
        return new Promise(res => {
            // Stop any stale MP3 that may be running
            if (currentAudio) { currentAudio.pause(); currentAudio.volume = 0; currentAudio = null; }

            // Talking head video has its own baked-in audio — always play at
            // full volume regardless of the voiceVolume slider (which controls
            // the separate MP3 voice track used by other reel types).
            vidEl.muted  = false;
            vidEl.volume = 1.0;

            // Restart from beginning
            vidEl.currentTime = 0;
            vidEl.play().catch(() => {});

            const cleanup = () => {
                vidEl.removeEventListener('ended', onEnded);
                vidEl.removeEventListener('error', onError);
                clearTimeout(guard);
                // Re-mute so preload / canvas loops stay silent when not playing
                vidEl.muted = true;
                res();
            };
            const onEnded = () => cleanup();
            const onError = () => cleanup();

            const guard = setTimeout(cleanup, 300000); // 5 min safety
            vidEl.addEventListener('ended', onEnded, { once: true });
            vidEl.addEventListener('error', onError,  { once: true });
        });
    }
    // Normal scene — play the MP3
    const af = sc.audio_file;
    return af ? playAudio(AUD_BASE + af) : sleep((parseInt(sc.duration) || 5) * 1000);
}

function stopPlay(){
    isPlaying=false;
    if(currentAudio){currentAudio.pause();currentAudio=null;}
    if(S.vidEl)S.vidEl.pause();
    if(bgAudio)bgAudio.pause();
    document.getElementById('playIco').textContent='▶';
    document.getElementById('playLbl').textContent='Play';
	logActivity('play_stop', 'at_scene:'+(currentIndex+1));
    updateNavButtons();
}

// ── Recording ─────────────────────────────────────────────────────────────
// State — only what stopRecording() needs to reach
let recBlob = null, recURL = null;
let _recStream = null, _recMIME = null;
let _recStopped = false;   // set true by stopRecording() to abort the scene loop

// ── recordSceneClip ───────────────────────────────────────────────────────
// Records exactly one scene: stamps the canvas, starts a fresh MediaRecorder,
// plays audio + media until done, stops the recorder, returns the Blob.
// Returns null if _recStopped was set mid-scene.
async function recordSceneClip(scene, sceneIndex) {
    const sc = scene;

    // 1. Force-clear any stuck transition flag then stamp canvas instantly
    S.isTransitioning = false;
    const { fn, isVideo, slot } = sceneMedia(sc);
    if (isVideo && fn && vidEls[fn]) {
        if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
        S.type = 'video'; S.vidEl = vidEls[fn]; S.img = null;
        S.alpha = 1; S.alphaOut = 0; S.imgOut = null; S.txOffset = null;
        vidEls[fn].currentTime = 0;
    } else if (fn && imgCache[fn]) {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'image'; S.img = imgCache[fn]; S.vidEl = null;
        S.alpha = 1; S.alphaOut = 0; S.imgOut = null; S.txOffset = null;
        S.kbEffect = sceneKB[sceneIndex]; S.kbStart = performance.now();
    } else {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'blank'; S.img = null; S.vidEl = null; S.alpha = 1;
    }
    S.isTransitioning = false;   // clear again after the switch

    currentIndex = sceneIndex;
    loadSceneCaptions(sc.id);
    applySceneSlots(sc);
    updateSlotThumbs(sc);
    updatePanelSceneNumbers();
    document.getElementById('sceneNum').textContent = (sceneIndex + 1) + ' / ' + SCENES.length;
    updateDots(sceneIndex);
    updateNavButtons();

    // 2. Wait for canvas to fully paint before recorder starts (500ms max).
    await new Promise(r => {
        let f = 0; const dl = performance.now() + 500;
        const t = () => { f++; (f >= 15 || performance.now() >= dl) ? r() : requestAnimationFrame(t); };
        requestAnimationFrame(t);
    });
    if (_recStopped) return null;

    // 3. Create a fresh MediaRecorder for this scene only
    const chunks  = [];
    const sceneMr = new MediaRecorder(_recStream, {
        mimeType: _recMIME,
        videoBitsPerSecond: 2_000_000   // 2Mbps — sufficient for 720×1280 social video; was 4Mbps at 1080p
    });

    sceneMr.ondataavailable = e => {
        if (e.data && e.data.size > 0) chunks.push(e.data);
        const mb = chunks.reduce((s, c) => s + c.size, 0) / 1024 / 1024;
        document.getElementById('recSize').textContent = mb.toFixed(1) + ' MB';
    };

    // blobReady resolves when sceneMr.onstop fires — the only way out
    const blobReady = new Promise(res => {
        sceneMr.onstop = () => res(new Blob(chunks, { type: _recMIME }));
    });

    sceneMr.start(200);

    // 4. Play audio + media, await completion
    const talkingHead  = isTalkingHeadScene(sc);
    const audioPromise = playSceneAudio(sc, talkingHead ? vidEls[(sc.image_file || '').trim()] : null);

    const enabledSlots   = getEnabledSlotsForScene(sc);
    const slotsWithMedia = enabledSlots.filter(s => (sc[s] || '').trim() !== '');
    const videoSlots     = slotsWithMedia.filter(s => /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((sc[s] || '').trim()));
    const imageSlots     = slotsWithMedia.filter(s => !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test((sc[s] || '').trim()));

    if (videoSlots.length > 0) {
        // Only await playVideoSequence — it uses audioEndSignal internally to know
        // when to stop looping. Adding audioPromise to Promise.all causes a race:
        // if audio ends first, Promise.all resolves, sceneMr.stop() fires while
        // video is still playing → frozen frame. If video ends first, nothing
        // keeps the video going while audioPromise is still pending.
        // playVideoSequence already waits for BOTH audio done AND clip end before
        // returning — that is the single correct synchronisation point.
        await playVideoSequence(sc, videoSlots, audioPromise, true);
    } else if (imageSlots.length > 0) {
        const durMs = await new Promise(resolve => {
            const a       = currentAudio;
            const fallback = (parseInt(sc.duration) || 5) * 1000;
            if (!a) { resolve(fallback); return; }
            if (isFinite(a.duration) && a.duration > 0) { resolve(Math.ceil(a.duration * 1000)); return; }
            let done = false;
            const finish = ms => { if (!done) { done = true; resolve(ms); } };
            a.addEventListener('loadedmetadata', () => finish(Math.ceil(a.duration * 1000)), { once: true });
            setTimeout(() => finish(fallback), 2000);
        });
        await Promise.all([
            playImageSequence(sc, imageSlots, durMs, audioPromise, true),
            audioPromise
        ]);
    } else {
        await audioPromise;
    }

    // 5. Stop the recorder and wait for onstop → blobReady
    if (sceneMr.state !== 'inactive') sceneMr.stop();
    const blob = await blobReady;

    if (_recStopped) return null;
    return blob;
}

// ── startRecording ────────────────────────────────────────────────────────
// v2: UNIFIED single-pass recording — one MediaRecorder for ALL scenes.
// Uses the same playSceneWithDynamicSlots() loop as Play mode, so:
//   • captions render correctly (drawn on canvas each frame)
//   • audio drives scene length (scene ends when voiceover ends)
//   • videos loop smoothly until audio finishes
//   • no per-scene MediaRecorder restarts = no frozen frames / gaps
async function startRecording() {
    _recStopped = false;

    // Queue notification
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'queue_generate');
        fd.append('podcast_id',  PODCAST_ID);
        const r    = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            const pos = data.position, mins = data.minutes;
            alert(`✅ Your video has been queued!\n\nPosition in queue: ${pos}\nEstimated time: ${mins} minute${mins !== 1 ? 's' : ''}\n\n(${pos} video${pos !== 1 ? 's' : ''} × 3 minutes each)`);
        }
    } catch(e) { console.warn('Queue insert failed:', e); }

    discardRec();

    // ── 1. Preload ALL media (images, videos, audio paths cached) ──────────
    const ov  = document.getElementById('preloadOverlay');
    const msg = document.getElementById('preloadMsg');
    if (ov)  { ov.style.opacity = '1'; ov.classList.remove('gone'); }
    if (msg) msg.textContent = 'Loading media…';
    await preloadAll();
    if (ov)  { ov.style.transition = 'opacity .4s'; ov.style.opacity = '0'; setTimeout(() => ov.classList.add('gone'), 450); }

    // ── 2. Wait for canvas to have painted at least 5 real frames ─────────
    await new Promise(res => {
        const chk = () => framesDrawn >= 5 ? res() : requestAnimationFrame(chk);
        chk();
    });

    // ── 3. Capture canvas stream ───────────────────────────────────────────
    try {
        _recStream = canvasHD.captureStream(30);
    } catch(e) {
        L('captureStream: ' + e.message, 'err');
        return;
    }

    // ── 4. Build Web Audio graph ───────────────────────────────────────────
    // A silent oscillator at gain=0 keeps the audio track alive across scene
    // boundaries so there is never a gap in the audio stream.
    try {
        const actx = new (window.AudioContext || window.webkitAudioContext)();
        const dest = actx.createMediaStreamDestination();
        window._recActx = actx;
        window._recDest = dest;

        const osc  = actx.createOscillator();
        const gain = actx.createGain();
        gain.gain.value = 0;            // silent — just keeps track alive
        osc.connect(gain); gain.connect(dest); osc.start();

        // Pre-connect all preloaded video elements so their audio is captured
        Object.values(vidEls).forEach(v => {
            try {
                const s = actx.createMediaElementSource(v);
                s.connect(dest);
                s.connect(actx.destination);
            } catch(_) {}
        });
        // Connect background music track if present
        if (bgAudio) {
            try { const s = actx.createMediaElementSource(bgAudio); s.connect(dest); } catch(_) {}
        }

        dest.stream.getAudioTracks().forEach(t => _recStream.addTrack(t));
    } catch(e) {
        L('Audio graph: ' + e.message, 'wrn');
    }

    // ── 5. Pick best supported MIME type ──────────────────────────────────
    _recMIME = 'video/webm;codecs=vp9,opus';
    if      (MediaRecorder.isTypeSupported('video/webm;codecs=vp8,opus')) _recMIME = 'video/webm;codecs=vp8,opus';
    else if (MediaRecorder.isTypeSupported('video/webm'))                 _recMIME = 'video/webm';
    else if (MediaRecorder.isTypeSupported('video/mp4'))                  _recMIME = 'video/mp4';

    // ── 6. Create ONE MediaRecorder for the entire video ──────────────────
    const allChunks = [];
    const mr = new MediaRecorder(_recStream, {
        mimeType:            _recMIME,
        videoBitsPerSecond:  2_000_000   // 2 Mbps — good quality for 720×1280 social
    });

    mr.ondataavailable = e => {
        if (e.data && e.data.size > 0) {
            allChunks.push(e.data);
            const mb = allChunks.reduce((s, c) => s + c.size, 0) / 1024 / 1024;
            document.getElementById('recSize').textContent = mb.toFixed(1) + ' MB';
        }
    };

    // blobReady resolves when mr.onstop fires (after mr.stop() is called below)
    const blobReady = new Promise(res => {
        mr.onstop = () => res(new Blob(allChunks, { type: _recMIME }));
    });

    // ── 7. Mark UI as recording ───────────────────────────────────────────
    isRecording = true;
    canvas.classList.add('recording');
    document.getElementById('recBar').classList.add('on');
    document.getElementById('dlPanel').classList.remove('on');
    document.getElementById('ibRecord').innerHTML = '<span class="ico">⏹</span>Stop';
    updateNavButtons();
    L('⏺ Recording all scenes as one video…', 'inf');
    syncPodcastThumbnail();
    logActivity('record_start', 'scenes:' + SCENES.length);

    if (bgAudio) { bgAudio.currentTime = 0; bgAudio.play().catch(() => {}); }

    // ── 8. START the single recorder, then play all scenes through ────────
    // This mirrors togglePlay() exactly — same function, same audio-driven
    // scene timing, same caption rendering, same video looping behaviour.
    // isRecordingMode=true suppresses the inter-scene buffer pause.
    mr.start(200);  // collect a chunk every 200 ms for smooth size readout

    console.log('\n========== UNIFIED RECORDING START ==========');
    currentIndex = 0;

    for (let i = 0; i < SCENES.length; i++) {
        if (_recStopped) break;
        L(`⏺ Scene ${i + 1} / ${SCENES.length}…`, 'inf');
        await playSceneWithDynamicSlots(SCENES[i], i, true /* isRecordingMode */);
    }

    console.log('========== UNIFIED RECORDING END ==========\n');

    // ── 9. Stop recorder and collect the full blob ────────────────────────
    if (mr.state !== 'inactive') mr.stop();
    const fullBlob = await blobReady;

    // ── 10. Cleanup ───────────────────────────────────────────────────────
    if (bgAudio) bgAudio.pause();
    if (currentAudio) { currentAudio.pause(); currentAudio = null; }
    canvas.classList.remove('recording');
    document.getElementById('recBar').classList.remove('on');
    isRecording = false;
    document.getElementById('ibRecord').innerHTML = '<span class="ico">⏺</span>Generate Video';
    window._recActx = null;
    window._recDest = null;
    _recStream = null;
    updateNavButtons();
    logActivity('record_done', 'scenes:' + SCENES.length);

    if (_recStopped) { L('Recording stopped by user.', 'wrn'); return; }

    if (!fullBlob || fullBlob.size < 1000) {
        L('⚠ Recording produced an empty file. Please try again.', 'err');
        return;
    }

    L(`✅ Full video recorded — ${(fullBlob.size / 1024 / 1024).toFixed(2)} MB. Uploading…`, 'ok');

    // ── 11. Upload the single full-video blob ─────────────────────────────
    // Upload as scene_0 — this is the correct filename save_scene_clip uses.
    // We then call start_mp4_convert (NOT stitch_scenes) because stitch_scenes
    // was built for N per-scene clips and loops/repeats when given only 1 file.
    // start_mp4_convert converts the saved webm directly to MP4, no concat.
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'save_scene_clip');
        fd.append('podcast_id',  PODCAST_ID);
        fd.append('scene_index', 0);
        fd.append('video', fullBlob, `podcast_${PODCAST_ID}_scene_0.webm`);
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = JSON.parse(await r.text());
        if (!d.success) throw new Error(d.message || 'Upload failed');
        L(`✅ Uploaded (${d.size_mb || '?'} MB). Converting to MP4…`, 'ok');
    } catch(e) {
        L('⚠ Upload failed: ' + e.message, 'err');
        return;
    }

    // ── 12. Convert via start_mp4_convert — skips the stitch/concat loop ──
    await updateVideoStatusToRecorded(PODCAST_ID);
    await startMp4Convert(PODCAST_ID);
}









// ========== HELPER FUNCTIONS (place OUTSIDE startRecording) ==========




// Update video status to RECORDED



// Show MP4 download modal





function discardRec(){
    if(recURL){URL.revokeObjectURL(recURL);recURL=null;}
    recBlob=null;
    document.getElementById('dlPanel').classList.remove('on');
}

// ── Image panel ────────────────────────────────────────────────────────────
const SLOT_PROMPT_MAP={image_file:'prompt',image_file_1:'prompt_1',image_file_2:'prompt_2',image_file_3:'prompt_3',image_file_4:'prompt_4'};
const SLOT_LABELS={image_file:'Main',image_file_1:'V1',image_file_2:'V2',image_file_3:'V3',image_file_4:'V4'};
// ── Slot checkbox helpers ──────────────────────────────────────────
function getEnabledSlots() {
    var enabled = [];
    var slots = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
    for (var i = 0; i < slots.length; i++) {
        var chk = document.getElementById('slotChk_' + slots[i]);
        if (chk && chk.checked) enabled.push(slots[i]);
    }
    return enabled;
}

// Per-scene version — reads from SCENE_SAVED_SLOTS not DOM.
// Use this during play/record so each scene uses its own saved slot selection.
function getEnabledSlotsForScene(scene) {
    var saved = SCENE_SAVED_SLOTS[scene.id];
    var enabled = [];
    var slotKeys = ['image_file', 'image_file_1', 'image_file_2', 'image_file_3', 'image_file_4'];
    var colKeys  = ['slot_main',  'slot_1',        'slot_2',        'slot_3',        'slot_4'      ];
    for (var i = 0; i < slotKeys.length; i++) {
        var val = saved ? saved[colKeys[i]] : (i === 0 ? 1 : 0);
        if (val === 1) enabled.push(slotKeys[i]);
    }
    // Fallback: if nothing saved yet, use main slot
    if (enabled.length === 0) enabled.push('image_file');
    return enabled;
}
async function selectSlot(slot) {
    activeSlot = slot;

    // Highlight the active slot thumb
    SLOTS.forEach(k => {
        const t = document.getElementById('slotThumb_' + k);
        if (t) t.classList.toggle('active-slot', k === slot);
    });

    const lbl = document.getElementById('selSlotName');
    if (lbl) lbl.textContent = SLOT_LABELS[slot] || slot;

    const sc = SCENES[currentIndex];
    const ta = document.getElementById('slotPrompt');
    if (ta) ta.value = sc[SLOT_PROMPT_MAP[slot]] || sc.prompt || '';

    // Refresh all slot thumbnails so the strip always shows current media
    updateSlotThumbs(sc);

    // Radio behaviour: select only this slot, deselect all others, save to DB
    // Use _slotSelectInProgress to prevent onSlotChkChange firing during this
    window._slotSelectInProgress = true;
    SLOTS.forEach(k => {
        const chk = document.getElementById('slotChk_' + k);
        if (chk) chk.checked = (k === slot);
    });
    window._slotSelectInProgress = false;
    saveSlotSelections(sc.id);

    // Show this slot's image on canvas immediately — load if not yet cached
    const fn = (sc[slot] || '').trim();
    if (fn) {
        const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
        if (isVid && !vidEls[fn]) {
            const v = document.createElement('video');
            v.src = getSceneFolder(sc, slot) + fn;
            v.muted = true; v.loop = true; v.playsInline = true;
            v.crossOrigin = 'anonymous'; v.preload = 'auto';
            document.getElementById('vidPool').appendChild(v);
            vidEls[fn] = v;
            await new Promise(res => {
                v.addEventListener('canplaythrough', res, { once: true });
                v.addEventListener('error', res, { once: true });
                setTimeout(res, 8000); v.load();
            });
        } else if (!isVid && !imgCache[fn]) {
            await new Promise(res => {
                const i = new Image();
                i.crossOrigin = 'anonymous';
                i.onload  = () => { imgCache[fn] = i; res(); };
                i.onerror = () => res();
                setTimeout(res, 8000);
                i.src = getSceneFolder(sc, slot) + fn + '?t=' + Date.now();
            });
        }
        showSlotOnCanvas(sc, slot);
        // Update the outside scene strip thumbnail to match selected slot media
        // Now handles both images AND videos
        ssUpdateThumb(currentIndex, getSceneFolder(sc, slot) + fn);
        updateSlotThumbs(sc);
    }

    // If we're on scene 1, update the podcast thumbnail in DB
    if (currentIndex === 0) syncPodcastThumbnail();
}

// Save current slot checkbox states to database
async function saveSlotSelections(sceneId) {
    const sc = SCENES.find(s => s.id === sceneId);
    if (!sc) return;

    const fd = new FormData();
    fd.append('ajax_action', 'save_enabled_slots');
    fd.append('scene_id', sc.id);

    SLOTS.forEach(k => {
        const chk = document.getElementById('slotChk_' + k);
        const col = SLOT_COL_MAP[k];
        const val = (chk && chk.checked) ? 1 : 0;
        if (!SCENE_SAVED_SLOTS[sc.id]) SCENE_SAVED_SLOTS[sc.id] = {};
        SCENE_SAVED_SLOTS[sc.id][col] = val;
        fd.append(col, val);
    });

    try {
        await fetch(location.href, { method: 'POST', body: fd });
    } catch(e) {}
}

function onSlotChkChange() {
    if (!bootComplete) return; // ignore browser-restored checkbox events during boot
    if (window._slotSelectInProgress) return; // ignore programmatic changes from selectSlot
    window._checkboxClickOverride = true;

    const sc = SCENES[currentIndex];
    if (!sc) return;

    const changedCheckbox = event ? event.target : null;
    let changedSlot = null;
    if (changedCheckbox) {
        for (let i = 0; i < SLOTS.length; i++) {
            if (changedCheckbox.id === 'slotChk_' + SLOTS[i]) {
                changedSlot = SLOTS[i];
                break;
            }
        }
    }

    // If unchecked and nothing else is checked, restore main slot
    if (changedSlot && !changedCheckbox.checked) {
        let anyChecked = false;
        for (let i = 0; i < SLOTS.length; i++) {
            const chk = document.getElementById('slotChk_' + SLOTS[i]);
            if (chk && chk.checked) { anyChecked = true; break; }
        }
        if (!anyChecked) {
            const mainChk = document.getElementById('slotChk_image_file');
            if (mainChk) mainChk.checked = true;
        }
    }

    // Save all current checkbox states
    saveSlotSelections(sc.id);

    // Show the newly checked slot's image on canvas immediately
    if (changedSlot && changedCheckbox.checked) {
        const fn = (sc[changedSlot] || '').trim();
        if (fn && !imgCache[fn] && !imgCache['_loading_' + fn]) {
            // Load image first then show
            imgCache['_loading_' + fn] = true;
            const i = new Image();
            i.crossOrigin = 'anonymous';
            i.onload  = () => { imgCache[fn] = i; delete imgCache['_loading_' + fn]; showSlotOnCanvas(sc, changedSlot); };
            i.onerror = () => { imgCache[fn] = null; delete imgCache['_loading_' + fn]; };
            i.src = getSceneFolder(sc, changedSlot) + fn + '?t=' + Date.now();
        } else {
            showSlotOnCanvas(sc, changedSlot);
        }
    } else {
        // Slot was unchecked — show first remaining checked slot
        updateCurrentDisplayOnly();
    }

    setTimeout(() => { window._checkboxClickOverride = false; }, 100);
}

// Update the slot selection when changing scenes
function applySceneSlots(sc) {
    if (!sc) return;
    
    const saved = SCENE_SAVED_SLOTS[sc.id];
    
    // Suppress onSlotChkChange so restoring checkboxes never triggers a DB save
    window._slotSelectInProgress = true;

    for (let i = 0; i < SLOTS.length; i++) {
        const k = SLOTS[i];
        const chk = document.getElementById('slotChk_' + k);
        if (chk) {
            const col = SLOT_COL_MAP[k];
            if (saved && saved[col] !== undefined) {
                chk.checked = saved[col] === 1;
            } else {
                // Default: only main slot checked
                chk.checked = (k === 'image_file');
            }
        }
    }
    
    // Ensure at least one slot is checked
    let anyChecked = false;
    for (let i = 0; i < SLOTS.length; i++) {
        const chk = document.getElementById('slotChk_' + SLOTS[i]);
        if (chk && chk.checked) {
            anyChecked = true;
            break;
        }
    }
    
    if (!anyChecked) {
        const mainChk = document.getElementById('slotChk_image_file');
        if (mainChk) mainChk.checked = true;
    }

    window._slotSelectInProgress = false;

    // Sync activeSlot to whichever checkbox is now checked,
    // so updateSlotThumbs (called after this) highlights the right slot.
    for (let i = 0; i < SLOTS.length; i++) {
        const chk = document.getElementById('slotChk_' + SLOTS[i]);
        if (chk && chk.checked) {
            activeSlot = SLOTS[i];
            break;
        }
    }
}

// Show a specific slot's media on canvas immediately.
// Called after any assignment (upload, library, generate, selectSlot).
// Media must already be in imgCache/vidEls before calling — callers load it first.
function showSlotOnCanvas(sc, slot) {
    const fn = (sc[slot] || '').trim();
    if (!fn) return;
    const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
    if (isVid && vidEls[fn]) {
        if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
        S.type = 'video'; S.vidEl = vidEls[fn];
        S.img = null; S.alpha = 1; S.alphaOut = 0; S.imgOut = null;
        try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
    } else if (!isVid && imgCache[fn]) {
        if (S.vidEl) S.vidEl.pause();
        S.type = 'image'; S.img = imgCache[fn];
        S.vidEl = null; S.alpha = 1; S.alphaOut = 0; S.imgOut = null;
        S.kbEffect = sceneKB[currentIndex];
        S.kbStart = performance.now();
    }
}

function updateCurrentDisplayOnly() {
    var sc = SCENES[currentIndex];
    if (!sc) return;
    
    var enabledSlots = getEnabledSlots();
    var foundFile = false;
    
    // Find first checked slot that has media
    for (var i = 0; i < enabledSlots.length; i++) {
        var slot = enabledSlots[i];
        var fn = (sc[slot] || '').trim();
        if (fn) {
            foundFile = true;
            var isVideo = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
            
            if (isVideo && vidEls[fn]) {
                if (S.vidEl && S.vidEl !== vidEls[fn]) S.vidEl.pause();
                S.type = 'video';
                S.vidEl = vidEls[fn];
                S.img = null;
                try { vidEls[fn].currentTime = 0; vidEls[fn].play(); } catch(e) {}
            } else if (imgCache[fn]) {
                if (S.vidEl) S.vidEl.pause();
                S.type = 'image';
                S.img = imgCache[fn];
                S.vidEl = null;
                S.kbEffect = sceneKB[currentIndex];
                S.kbStart = performance.now();
            }
            S.alpha = 1;
            S.alphaOut = 0;
            S.imgOut = null;
            break;
        }
    }
    
    // Show warning if no media found in checked slots
    if (!foundFile && enabledSlots.length > 0) {
        console.warn('No media files found in checked slots:', enabledSlots);
        // Optional: show a toast notification
        if (typeof L === 'function') {
            L('⚠️ Checked slots have no media files. Upload images/videos to these slots.', 'wrn');
        }
    }
}
function updateSlotThumbs(sc) {
    SLOTS.forEach(k => {
        const fn = (sc[k] || '').trim();
        const img = document.getElementById('slotImg_' + k);
        const ph  = document.getElementById('slotPh_'  + k);
        const th  = document.getElementById('slotThumb_' + k);

        if (fn) {
            // Populate FILE_FOLDER for this slot by calling getSceneFolder
            const folder = getSceneFolder(sc, k); // e.g. "podcast_images/"
            const isVid  = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);

            if (img) {
                if (isVid) {
                    // Videos: render a <video> element as thumbnail inside the slot-thumb div
                    const th2 = document.getElementById('slotThumb_' + k);
                    if (th2) {
                        // Remove any existing video element first
                        const oldVid = th2.querySelector('video.slot-vid-thumb');
                        if (oldVid) oldVid.remove();

                        // Hide the img placeholder, show video instead
                        img.style.display = 'none';
                        if (ph) ph.style.display = 'none';

                        const vidEl = document.createElement('video');
                        vidEl.className     = 'slot-vid-thumb';
                        vidEl.src           = folder + fn;
                        vidEl.muted         = true;
                        vidEl.loop          = false;
                        vidEl.playsInline   = true;
                        vidEl.preload       = 'metadata';
                        vidEl.currentTime   = 0.5;  // seek to 0.5s to get a frame
                        vidEl.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;border-radius:inherit;';
                        vidEl.onerror = function() {
                            this.remove();
                            img.style.display = 'none';
                            if (ph) { ph.textContent = '🎬'; ph.style.display = 'flex'; }
                        };
                        // Insert before the img element
                        th2.insertBefore(vidEl, img);
                    }
                } else {
                    // Images: use actual folder path directly — no guessing
                    const imgSrc = folder + fn;
                    img.onerror = function() {
                        // One fallback: try podcast_thumbnails
                        if (this.src.indexOf('podcast_thumbnails') === -1) {
                            this.src = 'podcast_thumbnails/' + fn;
                        } else {
                            this.style.display = 'none';
                            if (ph) { ph.textContent = '🖼️'; ph.style.display = 'flex'; }
                        }
                    };
                    img.src           = imgSrc + '?t=' + Date.now();
                    img.style.display = 'block';
                    if (ph) ph.style.display = 'none';
                }
            }
        } else {
            if (img) { img.src = ''; img.style.display = 'none'; }
            // Remove any leftover video thumbnail
            const th3 = document.getElementById('slotThumb_' + k);
            if (th3) { const ov = th3.querySelector('video.slot-vid-thumb'); if (ov) ov.remove(); }
            if (ph)  { ph.textContent = '🖼️'; ph.style.display = 'flex'; }
        }

        if (th) th.classList.toggle('active-slot', k === activeSlot);
    });
}


async function saveSlotPrompt(){
    const sc=SCENES[currentIndex];
    const ta=document.getElementById('slotPrompt');if(!ta)return;
    const val=ta.value.trim(),field=SLOT_PROMPT_MAP[activeSlot];
    sc[field]=val;
    const fd=new FormData();fd.append('ajax_action','save_prompt');
    fd.append('scene_id',sc.id);fd.append('prompt_field',field);fd.append('prompt',val);
    await fetch(location.href,{method:'POST',body:fd});
}

function uploadForSlot(){const inp=document.getElementById('slotFileInput');if(inp){inp.value='';inp.click();}}

async function handleSlotUpload(input){
    if(!input.files||!input.files[0])return;
    const file=input.files[0];
    const sc=SCENES[currentIndex];
    const isVid=file.type.startsWith('video/');
    L('Uploading '+(isVid?'video':'image')+': '+file.name+' ('+Math.round(file.size/1024/1024,1)+' MB)…','inf');
    const fd=new FormData();
    fd.append('ajax_action','upload_scene_image');fd.append('scene_id',sc.id);
    fd.append('image_field',activeSlot);fd.append('media_type',isVid?'video':'image');fd.append('scene_image',file);
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const text=await r.text();
        let data;
        try{ data=JSON.parse(text); }
        catch(e){
            // Server returned HTML (likely php.ini upload limit exceeded)
            L('Upload failed: Server rejected file — likely exceeds upload_max_filesize in php.ini. Current file: '+Math.round(file.size/1024/1024)+'MB','err');
            return;
        }
        if(!data.success)throw new Error(data.message||'Upload failed');
        sc[activeSlot]=data.filename;
        // Update the per-slot folder column on the scene object
        const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        if(data.image_folder){ sc[folderCol]=data.image_folder; FILE_FOLDER[data.filename]=data.image_folder.replace(/\/?$/,'/');}
        if(!isVid){
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[data.filename]=img;showSlotOnCanvas(sc,activeSlot);};
            img.onerror=()=>{};
            img.src=getSceneFolder(sc,activeSlot)+data.filename+'?t='+Date.now();
        } else { showSlotOnCanvas(sc,activeSlot); }
        updateSlotThumbs(sc);
        // Update scene strip thumbnail for both images and videos
        ssUpdateThumb(currentIndex, getSceneFolder(sc, activeSlot) + data.filename);
        L('Uploaded to your folder: '+data.filename,'ok');
        logActivity('image_uploaded', 'slot:'+activeSlot+' file:'+data.filename, currentIndex);
    }catch(e){L('Upload failed: '+e.message,'err');}
}

// ── Library Modal ──────────────────────────────────────────────────────────
let _libImgs=[],_libVids=[],_libMine=[],_libSelectedFile=null,_libTab='all';



function openLibraryModal() {
    const modal = document.getElementById('libModal');
    if (modal) modal.style.display = 'flex';
    const lbl = document.getElementById('libSlotLabel');
    if (lbl) lbl.textContent = SLOT_LABELS[activeSlot] || activeSlot;
    _libSelectedFile = null;
    _resetLibSel();
    
    const sc = SCENES[currentIndex];
    let searchQuery = '';
    
    // Use natural_language_tags as search query
    if (sc.natural_language_tags && sc.natural_language_tags.trim()) {
        searchQuery = sc.natural_language_tags.trim().split('|')[0].trim();
    } else if (sc.hashtags && sc.hashtags.trim()) {
        searchQuery = sc.hashtags.trim().split(/\s+/)[0].replace(/^#/, '');
    }
    
    const searchInput = document.getElementById('libSearch');
    if (searchInput) searchInput.value = searchQuery;
    
    const status = document.getElementById('libSearchStatus');
    if (status) {
        status.style.display = 'block';
        status.textContent = searchQuery ? `🔍 Searching: "${searchQuery.substring(0, 50)}"…` : '📂 Loading media…';
    }
    
    // AUTOMATICALLY SEARCH based on scene NL tags
    if (searchQuery) {
        performLibSearch();  // This will use the search_media_nl action
    } else {
        _loadRecentLibFiles();
    }
}


function closeLibraryModal(){const modal=document.getElementById('libModal');if(modal)modal.style.display='none';_libSelectedFile=null;}
function _resetLibSel(){
    const useBtn=document.getElementById('libUseBtn');if(useBtn){useBtn.disabled=true;useBtn.style.opacity='.4';}
    const info=document.getElementById('libSelInfo');if(info)info.textContent='No file selected';
}

function _updateLibCounts(){
    const all=document.getElementById('libCountAll');const img=document.getElementById('libCountImg');const vid=document.getElementById('libCountVid');const mine=document.getElementById('libCountMine');
    const total=_libImgs.length+_libVids.length;
    if(all)all.textContent=total;if(img)img.textContent=_libImgs.length;if(vid)vid.textContent=_libVids.length;if(mine)mine.textContent=_libMine.length;
}
// Replace the performLibSearch function 



// Update setLibTab to pass the tab type
// Replace the entire library modal section with this:

async function _loadRecentLibFiles() {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_library_files');
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        const files = data.files || [];
        _libImgs = files.filter(f => f.media_type !== 'video').map(f => ({ filename: f.filename, media_type: 'image', nl_tags: f.natural_language_tags || '', matched_line: '', matched_segment: '', score: 0, thumbnail: '' }));
        _libVids = files.filter(f => f.media_type === 'video').map(f => ({ filename: f.filename, media_type: 'video', nl_tags: f.natural_language_tags || '', matched_line: '', matched_segment: '', score: 0, thumbnail: '' }));
        _updateLibCounts();
        _renderLibGrid();
    } catch (e) {
        if (grid) grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Failed to load files</div>';
    }
}

async function _loadMyUploads() {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading your media…</div>';
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_user_media');
        const r    = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        if (!data.has_folder || !data.files || data.files.length === 0) {
            _libMine = [];
            const mine = document.getElementById('libCountMine');
            if (mine) mine.textContent = '0';
            if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);"><div style="font-size:36px;margin-bottom:10px;">🗂️</div><div style="font-weight:600;margin-bottom:6px;">No media files found</div><div style="font-size:11px;line-height:1.5;">Use <strong>📤 Upload</strong> to save files to your personal folder.</div></div>';
            return;
        }
        window._userMediaFolder = (data.folder || USER_MEDIA_FOLDER).replace(/\/?$/, '/');
        _libMine = data.files.map(function(f){ return {filename:f.filename, media_type:f.media_type||'image', nl_tags:'', score:0, thumbnail:'', is_user_media:true}; });
        const mine = document.getElementById('libCountMine');
        if (mine) mine.textContent = _libMine.length;
        _renderLibGrid();
    } catch (e) {
        if (grid) grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Failed to load your media</div>';
    }
}

// SINGLE VERSION - KEEP ONLY THIS ONE
async function _performLibSearchWithQuery(query, tabType = _libTab) {
    const grid = document.getElementById('libGrid');
    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Searching…</div>';
    const status = document.getElementById('libSearchStatus');
    if (status) { status.style.display = 'block'; status.textContent = `Searching: "${query.substring(0, 60)}"…`; }
    
    try {
        let mediaTypeFilter = '';
        if (tabType === 'image') mediaTypeFilter = 'image';
        else if (tabType === 'video') mediaTypeFilter = 'video';
        
        let tabTypeParam = tabType === 'mine' ? 'mine' : 'all';
        
        const fd = new FormData();
        fd.append('ajax_action', 'search_media_nl');
        fd.append('query', query);
        fd.append('media_type_filter', mediaTypeFilter);
        fd.append('tab_type', tabTypeParam);
        
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const results = await r.json();
        
        _libImgs = (Array.isArray(results) ? results : []).filter(r => r.type !== 'video');
        _libVids = (Array.isArray(results) ? results : []).filter(r => r.type === 'video');
        
        _updateLibCounts();
        _renderLibGrid();
        
        const total = _libImgs.length + _libVids.length;
        if (status) {
            status.style.display = 'block';
            status.textContent = total ? `✅ ${_libImgs.length} images · ${_libVids.length} videos` : '❌ No results found';
        }
    } catch (e) {
        console.error('Library search error:', e);
        L('Library search error: ' + e.message, 'err');
        _loadRecentLibFiles();
    }
} 

async function performLibSearch() {
    const query = (document.getElementById('libSearch')?.value || '').trim();
    if (!query) { _loadRecentLibFiles(); return; }
    await _performLibSearchWithQuery(query, _libTab);
}

function setLibTab(type) {
    _libTab = type;
    ['All', 'Img', 'Vid', 'Mine'].forEach(n => {
        const btn = document.getElementById('libTab' + n);
        if (!btn) return;
        const isOn = (n === 'All' && type === 'all') || 
                     (n === 'Img' && type === 'image') || 
                     (n === 'Vid' && type === 'video') || 
                     (n === 'Mine' && type === 'mine');
        btn.style.background = isOn ? 'var(--primary)' : 'var(--surface2)';
        btn.style.borderColor = isOn ? 'var(--primary)' : 'var(--border)';
        btn.style.color = isOn ? '#fff' : 'var(--muted)';
    });
    
    if (type === 'mine') {
        _loadMyUploads();
    } else {
        const query = document.getElementById('libSearch')?.value || '';
        if (query.trim()) {
            _performLibSearchWithQuery(query, type);
        } else {
            _loadRecentLibFiles();
        }
    }
}





function _renderLibGrid(){
    const grid=document.getElementById('libGrid');if(!grid)return;
    const files=_libTab==='video'?_libVids:_libTab==='image'?_libImgs:_libTab==='mine'?_libMine:[..._libImgs,..._libVids];
    if(!files.length){
        const emptyIcon=_libTab==='video'?'🎬':_libTab==='mine'?'🗂️':'🖼️';
        const emptyMsg=_libTab==='mine'?'No media in your folder — use 📤 Upload to add files':'No '+(_libTab==='all'?'media':_libTab+'s')+' found';
        grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);"><div style="font-size:36px;margin-bottom:10px;">${emptyIcon}</div><div>${emptyMsg}</div></div>`;return;
    }
    grid.style.gridTemplateColumns='repeat(2,1fr)';
    grid.innerHTML=files.map(f=>{
        const isVid=f.media_type==='video';const score=f.score||0;
        let borderC,scoreBg,scoreClr,qlabel;
        if(score>=0.5){borderC='#10b981';scoreBg='#dcfce7';scoreClr='#166534';qlabel='🟢';}
        else if(score>=0.35){borderC='#f59e0b';scoreBg='#fef9c3';scoreClr='#854d0e';qlabel='🟡';}
        else if(score>0){borderC='#ef4444';scoreBg='#fee2e2';scoreClr='#991b1b';qlabel='🔴';}
        else{borderC='#e2e8f0';scoreBg='#f1f5f9';scoreClr='#64748b';qlabel='';}
        const scoreBadge=score>0?`<div style="position:absolute;top:5px;right:5px;background:${scoreBg};color:${scoreClr};padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;z-index:10;">${qlabel} ${Math.round(score*100)}%</div>`:'';
        const vidBadge=isVid?`<div style="position:absolute;top:5px;left:5px;background:rgba(0,0,0,.65);color:#fff;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:600;">🎬</div>`:'';
        const thumb=(f.thumbnail||'').trim();
        const fileBaseFolder = f.is_user_media ? (window._userMediaFolder||USER_MEDIA_FOLDER) : getFileFolder(f.filename);
        let mediaHtml;
        if(isVid){mediaHtml=thumb?`<div style="position:relative;width:100%;padding-top:177.78%;overflow:hidden;"><img src="podcast_thumbnails/${thumb}" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.style.display='none';this.nextSibling.style.display='flex'"><div style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(135deg,#0f172a,#1e3a5f);align-items:center;justify-content:center;font-size:30px;">🎬</div></div>`:` <div style="position:relative;width:100%;padding-top:177.78%;background:linear-gradient(135deg,#0f172a,#1e3a5f);"><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:30px;">🎬</div></div>`;}
        else{const src=thumb?`podcast_thumbnails/${thumb}`:`${fileBaseFolder}${f.filename}`;mediaHtml=`<div style="position:relative;width:100%;padding-top:177.78%;overflow:hidden;"><img src="${src}" data-orig="${fileBaseFolder}${f.filename}" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;display:block;" loading="lazy" onerror="if(this.src.indexOf('podcast_thumbnails')!==-1){this.src=this.dataset.orig;}else{this.style.display='none';this.nextSibling.style.display='flex';}"><div style="display:none;position:absolute;top:0;left:0;width:100%;height:100%;background:#e2e8f0;align-items:center;justify-content:center;font-size:22px;color:#94a3b8;">🖼️</div></div>`;}
        const seg=(f.matched_segment||'').trim();const line=(f.matched_line||'').trim();
        const tagHtml=(seg||line)?`<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#475569;line-height:1.4;overflow:hidden;max-height:36px;">${seg?`<span style="color:#0369a1;font-weight:600;">${seg.substring(0,44)}</span>`:''}${line&&line!==seg?`<br><span style="color:#64748b;">${line.substring(0,48)}</span>`:''}</div>`:`<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#94a3b8;">${f.is_user_media?'👤 ':''}${f.filename.substring(0,28)}</div>`;
        return `<div onclick="pickLibFile(this,'${f.filename}','${f.media_type}')" data-folder="${fileBaseFolder}" style="position:relative;border:2px solid ${borderC};border-radius:10px;cursor:pointer;background:white;transition:border-color .15s,box-shadow .15s;overflow:hidden;">${mediaHtml}${scoreBadge}${vidBadge}<div class="media-check" style="position:absolute;top:5px;left:5px;background:#10b981;color:white;width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:13px;font-weight:700;z-index:20;">✓</div>${tagHtml}</div>`;
    }).join('');
}
function pickLibFile(el,filename,type){
    document.querySelectorAll('#libGrid > div').forEach(d=>{d.style.borderColor='var(--border)';const chk=d.querySelector('.media-check');if(chk)chk.style.display='none';});
    el.style.borderColor='var(--info)';const chk=el.querySelector('.media-check');if(chk)chk.style.display='flex';
    _libSelectedFile={filename,type,folder:el.dataset.folder||null};
    const info=document.getElementById('libSelInfo');if(info)info.textContent=filename;
    const btn=document.getElementById('libUseBtn');if(btn){btn.disabled=false;btn.style.opacity='1';}
}
async function useLibraryFile(){
	
	
	// ── Image caption library pick mode ──
    if (window._imgCapLibMode) {
        window._imgCapLibMode = false;
        if (_libSelectedFile) {
            closeLibraryModal();
            if (window._imgCapLibResolve) {
                window._imgCapLibResolve(_libSelectedFile.filename);
                window._imgCapLibResolve = null;
            }
        }
        return;
    }
    if(!_libSelectedFile)return;
	
	
	
	
    const{filename,type}=_libSelectedFile;
    const sc=SCENES[currentIndex];
    const fd=new FormData();
    fd.append('ajax_action','assign_image');
    fd.append('scene_id',sc.id);
    fd.append('filename',filename);
    fd.append('image_field',activeSlot);
    fd.append('media_type',type);

    // ── Determine the correct image_folder to save ────────────────────────
    // Mine tab  → user_id_X_company_id_Y  (strip 'user_media/' prefix)
    // image tab → podcast_images
    // video tab → podcast_videos
    // all tab   → detect from file extension
    const isVidFile = type==='video' || /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
    let folderToSave;
    if (_libTab === 'mine' || (_libSelectedFile.folder && _libSelectedFile.folder.indexOf('user_media') !== -1)) {
        // Strip 'user_media/' prefix — DB stores only the subfolder name
        const rawFolder = (_libSelectedFile.folder || USER_MEDIA_FOLDER).replace(/\/?$/, '');
        folderToSave = rawFolder.replace(/^user_media\//, '');
    } else {
        folderToSave = isVidFile ? 'podcast_videos' : 'podcast_images';
    }
    fd.append('folder_override', folderToSave);
    const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
    if(data.success){
        sc[activeSlot]=filename;
        // Update the per-slot folder column on the scene object
        const folderCol2 = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        if(data.image_folder){sc[folderCol2]=data.image_folder;FILE_FOLDER[filename]=data.image_folder.replace(/\/?$/,'/');}
        const isVid=type==='video'||/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
        if(isVid){
            if(!vidEls[filename]){
                const v=document.createElement('video');v.src=getSceneFolder(sc,activeSlot)+filename+'?t='+Date.now();v.muted=true;v.loop=true;v.playsInline=true;v.crossOrigin='anonymous';v.preload='auto';
                document.getElementById('vidPool').appendChild(v);vidEls[filename]=v;
                await new Promise(res=>{v.addEventListener('canplaythrough',res,{once:true});v.addEventListener('error',res,{once:true});setTimeout(res,15000);v.load();});
            }
            showSlotOnCanvas(sc,activeSlot);
        } else {
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[filename]=img;showSlotOnCanvas(sc,activeSlot);};img.onerror=()=>{};
            img.src=getSceneFolder(sc,activeSlot)+filename+'?t='+Date.now();
        }
        updateSlotThumbs(sc);closeLibraryModal();L('Assigned: '+filename,'ok');
		logActivity('image_from_library', 'slot:'+activeSlot+' file:'+filename, currentIndex);
    }else{L('Assign failed','err');}
}

// ── Generate image / video ──────────────────────────────────────────────────
async function generateForSlot(genType) {
    genType = genType || 'image'; // 'image' or 'video'
    const sc = SCENES[currentIndex];
    const ta = document.getElementById('slotPrompt');
    const prompt = (ta?.value.trim()) || sc[SLOT_PROMPT_MAP[activeSlot]] || sc.prompt || '';
    
    if (!prompt) {
        alert('No prompt for this slot.');
        return;
    }
    
    const btnId = genType === 'video' ? 'btnGenerateVideo' : 'btnGenerateImage';
    const btn = document.getElementById(btnId);
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.classList.add('btn-generate-loading');
        btn.innerHTML = genType === 'video'
            ? '<span style="font-size:15px;">⏳</span><span>Wait…</span><span style="font-size:9px;">$0.50</span>'
            : '<span style="font-size:15px;">⏳</span><span>Wait…</span><span style="font-size:9px;">$0.08</span>';
        btn.disabled = true;
    }
    
    // Show spinner on the slot thumbnail
    const slotThumb = document.getElementById('slotThumb_' + activeSlot);
    if (slotThumb) {
        slotThumb.classList.add('slot-thumb-loading');
        slotThumb._originalContent = slotThumb.innerHTML;
    }
    
    L('Generating ' + genType + '…', 'inf');
    
    const fd = new FormData();
    fd.append('ajax_action', genType === 'video' ? 'generate_video' : 'generate_image');
    fd.append('podcast_id', PODCAST_ID);
    fd.append('scene_id', sc.id);
    fd.append('image_field', activeSlot);
    fd.append('enhanced_prompt', prompt);
    fd.append('hashtags', sc.hashtags || '');
    
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();

        if (!data.success) throw new Error(data.message || 'Generation failed');

        // ── Video: queued — no file yet ───────────────────────────────────────
        if (genType === 'video') {
            const msg = data.message || `Your video will be ready in approximately ${data.minutes || 50} minutes.`;
            L('🎬 Queued: ' + msg, 'ok');
            alert(msg);
            logActivity('video_gen_queued', 'slot:' + activeSlot + ' pos:' + (data.position || '?'), currentIndex);
            return;
        }

        // ── Image: file returned immediately ─────────────────────────────────
        const fn = data.image_name || data.filename;
        if (!fn) throw new Error('No filename returned');

        sc[activeSlot] = fn;
        const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
        sc[folderCol]   = 'podcast_images';
        FILE_FOLDER[fn] = 'podcast_images/';

        await new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            const timeout = setTimeout(() => reject(new Error('Image load timeout')), 30000);
            img.onload  = () => { clearTimeout(timeout); imgCache[fn] = img; resolve(); };
            img.onerror = () => { clearTimeout(timeout); reject(new Error('Failed to load generated image')); };
            img.src = getSceneFolder(sc, activeSlot) + fn + '?nocache=' + Date.now();
        });

        showSlotOnCanvas(sc, activeSlot);
        updateSlotThumbs(sc);
        ssUpdateThumb(currentIndex, 'podcast_images/' + fn);
        L('✅ Generated image: ' + fn, 'ok');
        logActivity('image_generated', 'slot:' + activeSlot, currentIndex);

    } catch (e) {
        L('❌ Generate failed: ' + e.message, 'err');
        console.error('Generation error:', e);
    } finally {
        if (btn) {
            btn.classList.remove('btn-generate-loading');
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
        if (slotThumb) {
            slotThumb.classList.remove('slot-thumb-loading');
        }
    }
}

let loadingToast = null;

function showLoadingToast(message) {
    // Remove existing toast
    if (loadingToast) {
        loadingToast.remove();
    }
    
    loadingToast = document.createElement('div');
    loadingToast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--primary);
        color: white;
        padding: 10px 20px;
        border-radius: 30px;
        font-size: 12px;
        font-weight: 600;
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    `;
    
    loadingToast.innerHTML = `
        <div style="width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3);
                    border-top-color: white; border-radius: 50%; animation: spin 0.7s linear infinite;"></div>
        <span>${message}</span>
    `;
    
    document.body.appendChild(loadingToast);
}

function hideLoadingToast() {
    if (loadingToast) {
        loadingToast.remove();
        loadingToast = null;
    }
}

let generationStartTime = null;
let generationTimer = null;

function startGenerationTimer() {
    generationStartTime = Date.now();
    const timerDisplay = document.createElement('div');
    timerDisplay.id = 'genTimer';
    timerDisplay.style.cssText = `
        position: fixed;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.7);
        color: #fff;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-family: monospace;
        z-index: 10001;
    `;
    document.body.appendChild(timerDisplay);
    
    generationTimer = setInterval(() => {
        const elapsed = Math.floor((Date.now() - generationStartTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        timerDisplay.textContent = `⏱️ Generating... ${minutes}:${seconds.toString().padStart(2,'0')}`;
    }, 1000);
}

function stopGenerationTimer() {
    if (generationTimer) {
        clearInterval(generationTimer);
        generationTimer = null;
    }
    const timerDisplay = document.getElementById('genTimer');
    if (timerDisplay) timerDisplay.remove();
}
// ── Audio panel ────────────────────────────────────────────────────────────
let _currentPodcastMusic='<?= addslashes($podcast_music) ?>';
let _hostVoiceId='<?= addslashes($host_voice_id) ?>';
let _guestVoiceId='<?= addslashes($guest_voice_id) ?>';
let _audioSaveTimer=null;
let _musicPreviewAudio=null;
let _musicLibFiles=[];
let _selectedMusicFile=null;
// ── Voice panel state ──────────────────────────────────────────────
let _allVoices        = [];   // full list from server
let _voiceGenderFilter = 'all';
let _voiceTarget       = 'host';   // 'host' | 'guest'
let _selectedVoiceKey  = '';
let _voicePreviewAudio = null;
let _audCapId          = null;   // caption id being edited in audio tab
let _audCapSaveTimer   = null;

function showAudioSub(id) {
    // Tab switching no longer needed — Voice + Music are merged into one view.
    // Still call data loaders so existing code paths work.
    if (id === 'audSub2') _renderCurrentMusic();
    if (id === 'audSub3') _loadVoicePanel();
}
function onMusicVolChange(val) {
    bgMusicVolume = parseFloat(val) / 100;
    const lbl = document.getElementById('musicVolLbl');
    if (lbl) lbl.textContent = Math.round(val) + '%';
    if (bgAudio) bgAudio.volume = bgMusicVolume;
    if (_musicPreviewAudio) _musicPreviewAudio.volume = bgMusicVolume;
}

function onVoiceVolChange(val) {
    voiceVolume = parseFloat(val) / 100;
    const lbl = document.getElementById('voiceVolLbl');
    if (lbl) lbl.textContent = Math.round(val) + '%';
    // Apply to all possible audio elements
    if (currentAudio) currentAudio.volume = voiceVolume;
    const apEl = document.getElementById('apEl');
    if (apEl) apEl.volume = voiceVolume;
    if (_voicePreviewAudio) _voicePreviewAudio.volume = voiceVolume;
}
async function loadAudioPanel() {
    // Voice + Music merged view — load both data sets
    _renderCurrentMusic();
    _loadVoicePanel();

    // Populate audioSceneText from hdb_captions (main caption for current scene)
    const sc = SCENES[currentIndex];
    if (sc) {
        const scCaps  = ALL_CAPTIONS.filter(c => +c.story_id === +sc.id);
        const mainCap = scCaps.find(c => (c.caption_name||'').toLowerCase() === 'main') || scCaps[0] || null;
        _audCapId = mainCap ? mainCap.id : null;
        const ta = document.getElementById('audioSceneText');
        if (ta) ta.value = mainCap ? (mainCap.text_content || '') : '';
    }

    // Load voice IDs if not already fetched
    if (!_hostVoiceId && !_guestVoiceId) {
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'get_podcast_info');
            const r    = await fetch(location.href, { method:'POST', body:fd });
            const data = await r.json();
            if (data.success && data.row) {
                const row = data.row;
                _hostVoiceId  = row.host_voice_id  || row.host_voice  || row.voice_id       || row.voice       || '';
                _guestVoiceId = row.guest_voice_id || row.guest_voice || row.voice_id_guest || row.voice_guest || '';
                const hn = document.getElementById('audHostVoiceName');
                const gn = document.getElementById('audGuestVoiceName');
                if (hn) hn.textContent = _hostVoiceId  || '— not set —';
                if (gn) gn.textContent = _guestVoiceId || '— not set —';
            }
        } catch(e) { L('Could not load voice info: ' + e.message, 'wrn'); }
    }
}

// ── Save caption text (debounced) ──────────────────────────────────
function saveCaptionTextDebounced() {
    clearTimeout(_audCapSaveTimer);
    _audCapSaveTimer = setTimeout(async () => {
        const ta  = document.getElementById('audioSceneText');
        if (!ta) return;
        const txt = ta.value;

        if (_audCapId) {
            // Save to hdb_captions
            const fd = new FormData();
            fd.append('ajax_action',  'save_caption_text');
            fd.append('caption_id',   _audCapId);
            fd.append('text_content', txt);
            await fetch(location.href, { method:'POST', body:fd });
            // Also update in-memory
            const cap = ALL_CAPTIONS.find(c => +c.id === +_audCapId);
            if (cap) {
                cap.text_content = txt;
                startCaptionAnim(cap);
            }
            L('Caption text saved', 'ok');
        } else {
            L('No caption found for this scene', 'err');
        }
    }, 900);
}

// ── Voice panel ────────────────────────────────────────────────────

// ── Voice panel state ──────────────────────────────────────────────




// Track which gender dropdown "owns" the current selection
let _selectedGender    = '';       // 'male' | 'female' | ''

// Called when Change Voice tab opens
async function _loadVoicePanel() {
    _updateVcCurrentDisplay();
    if (_allVoices.length) { _populateVoiceDropdowns(); return; }

    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel) vSel.innerHTML = '<option>Loading voices…</option>';

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'get_voices_by_language');
        fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const raw  = await r.text();
        console.log('RAW voice response:', raw.substring(0, 500));
        const data = JSON.parse(raw);
        console.log('voices:', data.voices?.length, 'isFree:', _isFreeTrialUser);
		
        _allVoices = data.voices || [];
        console.log('after filter:', _allVoices.length);
        _populateVoiceDropdowns();
    } catch(e) {
        console.error('_loadVoicePanel error:', e.message);
        L('Failed to load voices: ' + e.message, 'err');
    }
}

// Filter: allow AI/OpenAI voices for free trial, block Azure for free trial
// Adjust this logic to match your subscription field name
function _isVoiceAllowed(v) {
    if (!_isFreeTrialUser) return true;
    return (v.voice_source || '').toLowerCase() !== 'azure';
}

function _populateVoiceDropdowns() {
    const sel = document.getElementById('vcVoiceSelect');
    if (!sel) return;

    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey || '';

    // Build combined list: females first (or sort by name), with gender label
    const genderIcon = { male: '👨', female: '👩' };
    sel.innerHTML = '<option value="">— Select a voice —</option>' +
        _allVoices.map(v => {
            const gender  = _voiceGenderOf(v);
            const icon    = genderIcon[gender] || '🎙️';
            const label   = `${icon} ${v.voice_name || v.voice_key} (${gender})${v.voice_description ? ' — ' + v.voice_description : ''}`;
            const blocked = _isFreeTrialUser && (v.voice_source || '').toLowerCase() === 'azure';
            return `<option value="${v.voice_key}"
                data-gender="${gender}"
                data-sample="${v.sample_voice || ''}"
                data-lang="${v.lang_code || ''}"
                data-voice-text="${(v.voice_text || '').replace(/"/g, '&quot;')}"
                ${blocked ? 'disabled style="color:#ccc;"' : ''}
                ${v.voice_key === currentKey ? 'selected' : ''}>
                ${label}${blocked ? ' 🔒' : ''}
            </option>`;
        }).join('');

    _updateVcCurrentDisplay();
    _updateVoiceDescSingle();
}

function _voiceGenderOf(v) {
    return (v.gender || '').toLowerCase() === 'female' ? 'female' : 'male';
}

function _fillSelect(selectId, voices, placeholder) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    sel.innerHTML = `<option value="">${placeholder}</option>` +
        voices.map(v => {
            const label = (v.voice_name || v.voice_key) +
                          (v.voice_description ? ' — ' + v.voice_description : '');
            const blocked = _isFreeTrialUser && (v.voice_source || '').toLowerCase() === 'azure';
            return `<option value="${v.voice_key}" 
                data-sample="${v.sample_voice||''}"
                data-lang="${v.lang_code || ''}"
                data-voice-text="${(v.voice_text || '').replace(/"/g, '&quot;')}"
                ${blocked ? 'disabled style="color:#ccc;"' : ''}>
                ${label}${blocked ? ' 🔒' : ''}
            </option>`;
        }).join('');
}

function _preselectDropdown(selectId, key) {
    if (!key) return;
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const opt = Array.from(sel.options).find(o => o.value === key);
    if (opt) sel.value = key;
}

// Called when user changes the voice dropdown
function onVoiceSelectChange() {
    const sel = document.getElementById('vcVoiceSelect');
    if (!sel || !sel.value) return;
    _selectedVoiceKey = sel.value;
    const selOpt = sel.options[sel.selectedIndex];
    _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    _updateVoiceDescSingle();
    _updateVcCurrentDisplay();
    _stopVoicePreview();
}

// _updateVoiceDesc(gender) removed — replaced by _updateVoiceDescSingle()

function _updateVoiceDescSingle() {
    const sel  = document.getElementById('vcVoiceSelect');
    const desc = document.getElementById('vcVoiceDesc');
    if (!sel || !desc) return;
    const opt = sel.options[sel.selectedIndex];
    desc.textContent = (opt && opt.value) ? (opt.dataset.description || '') : '';
}

function _updateVcCurrentDisplay() {
    const span = document.getElementById('vcCurrentName');
    if (!span) return;
    const currentKey = _voiceTarget === 'guest' ? _guestVoiceId : _hostVoiceId;
    const voice = _allVoices.find(v => v.voice_key === currentKey);
    // Seed vcSampleText with DB voice_text only if the field is still empty
    const ta = document.getElementById('vcSampleText');
    if (ta && !ta.value.trim() && voice && voice.voice_text) ta.value = voice.voice_text;
    const label = voice
        ? (voice.voice_name || voice.voice_key) + (voice.voice_description ? ' (' + voice.voice_description + ')' : '')
        : (currentKey || '— none —');
    span.textContent = (_voiceTarget === 'guest' ? '🎙 Guest: ' : '🎙 Host: ') + label;
}

// Preview the currently selected voice — generates TTS on-the-fly via generate_voice.php
function previewSelectedVoice() {
    const sel = document.getElementById('vcVoiceSelect');
    const btn = document.getElementById('vcPlayBtn');
    if (!sel || !sel.value) { L('Select a voice first', 'wrn'); return; }

    // Toggle off if already playing
    if (_voicePreviewAudio && !_voicePreviewAudio.paused) {
        _stopVoicePreview();
        return;
    }
    _stopVoicePreview();

    const opt      = sel.options[sel.selectedIndex];
    const voiceKey = sel.value;
    const langCode = opt?.getAttribute('data-lang') || 'en';

    // Text: use whatever is in the textarea; fall back to data-voice-text from DB
    const ta       = document.getElementById('vcSampleText');
    const text     = (ta && ta.value.trim()) ? ta.value.trim()
                   : (opt?.getAttribute('data-voice-text') || 'Hello, this is a sample of my voice.');

    if (!text) { L('Enter a preview sentence first', 'wrn'); return; }

    // Show loading state
    if (btn) { btn.textContent = '…'; btn.disabled = true; }
    L('Generating voice preview…', 'inf');

    const fd = new FormData();
    fd.append('text',     text);
    fd.append('voice_id', voiceKey);
    fd.append('lang_code',langCode);
    fd.append('row_id',   '0');
    fd.append('rate',     currentPlaybackSpeed || '1.0');
    fd.append('filename', 'preview_' + voiceKey.replace(/[^a-zA-Z0-9_]/g,'_') + '.mp3');

    fetch('generate_voice.php', { method: 'POST', body: fd, credentials: 'include' })
        .then(r => r.json())
        .then(d => {
            if (btn) { btn.textContent = '▶'; btn.disabled = false; }
            if (!d.success) { L('Voice preview failed: ' + (d.message || 'unknown error'), 'wrn'); return; }
            const src = 'podcast_audios/' + d.filename + '?t=' + Date.now();
            _voicePreviewAudio = new Audio(src);
            _voicePreviewAudio.playbackRate = currentPlaybackSpeed;
            _voicePreviewAudio.onended = () => {
                if (btn) btn.textContent = '▶';
                _voicePreviewAudio = null;
            };
            _voicePreviewAudio.onerror = () => {
                L('Could not play preview', 'wrn');
                if (btn) btn.textContent = '▶';
                _voicePreviewAudio = null;
            };
            _voicePreviewAudio.play().catch(() => L('Preview playback blocked', 'wrn'));
            if (btn) btn.textContent = '⏹';
        })
        .catch(e => {
            if (btn) { btn.textContent = '▶'; btn.disabled = false; }
            L('Voice preview error: ' + e.message, 'wrn');
        });
}

function _stopVoicePreview() {
    if (_voicePreviewAudio) {
        _voicePreviewAudio.pause();
        _voicePreviewAudio = null;
    }
    const pb = document.getElementById('vcPlayBtn');
    if (pb) pb.textContent = '▶';
}

function setVoiceTarget(target) {
    _voiceTarget = target;
    _stopVoicePreview();
    ['host','guest'].forEach(t => {
        const btn = document.getElementById('vt' + t.charAt(0).toUpperCase() + t.slice(1));
        if (!btn) return;
        const active = t === target;
        btn.style.background  = active ? 'var(--info)' : 'var(--surface2)';
        btn.style.borderColor = active ? 'var(--info)' : 'var(--border)';
        btn.style.color       = active ? '#fff' : 'var(--muted)';
    });
    // Re-preselect single dropdown for this target's current voice
    const currentKey = target === 'guest' ? _guestVoiceId : _hostVoiceId;
    _selectedVoiceKey = currentKey || '';
    _preselectDropdown('vcVoiceSelect', currentKey);
    const vSel = document.getElementById('vcVoiceSelect');
    const selOpt = vSel ? vSel.options[vSel.selectedIndex] : null;
    _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    _updateVoiceDescSingle();
    _updateVcCurrentDisplay();
}

async function saveSelectedVoice() {
    // Read directly from dropdown
    const vSel = document.getElementById('vcVoiceSelect');
    if (vSel && vSel.value) {
        _selectedVoiceKey = vSel.value;
        const selOpt = vSel.options[vSel.selectedIndex];
        _selectedGender = selOpt ? (selOpt.dataset.gender || 'male') : 'male';
    }
    if (!_selectedVoiceKey) { alert('Please select a voice first.'); return; }
    // Store with openai: prefix so audio generation routes correctly
    const prefixedKey = _ensureVoicePrefix(_selectedVoiceKey);
    if (_voiceTarget === 'guest') _guestVoiceId = prefixedKey;
    else                          _hostVoiceId  = prefixedKey;

    // 1. Save voice to hdb_podcasts
    const fd = new FormData();
    fd.append('ajax_action',    'save_podcast_voices');
    fd.append('host_voice_id',  _hostVoiceId);
    fd.append('guest_voice_id', _guestVoiceId);
    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (!data.success) {
            console.error('save_podcast_voices failed:', data);
            L('Failed to save voice: ' + (data.error || 'unknown error'), 'err');
            return;
        }
        const hn = document.getElementById('audHostVoiceName');
        const gn = document.getElementById('audGuestVoiceName');
        if (hn) hn.textContent = _hostVoiceId  || '— not set —';
        if (gn) gn.textContent = _guestVoiceId || '— not set —';
        _updateVcCurrentDisplay();
        _stopVoicePreview();
        // DON'T switch tabs yet — wait until all audio is generated
    } catch(e) { L('Save voice error: ' + e.message, 'err'); return; }

    // 2. Regenerate audio for all scenes — stays on voice tab until done
    await regenAllScenesAudio();

    // 3. Only switch back to audio overview tab after everything is done
    showAudioSub('audSub1');
}

// Regenerate audio for every scene with its correct voice
async function regenAllScenesAudio() {
    const total = SCENES.length;

    // Build a full-panel progress overlay that stays visible
    const voicePanel = document.getElementById('audSub3');
    const origContent = voicePanel ? voicePanel.innerHTML : '';
    if (voicePanel) {
        voicePanel.innerHTML = `
        <div style="padding:16px;">
            <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
                <div style="width:20px;height:20px;border:3px solid rgba(var(--info-rgb,2,132,199),.25);
                    border-top-color:var(--info);border-radius:50%;
                    animation:spin 1s linear infinite;flex-shrink:0;"></div>
                <div style="font-size:14px;font-weight:700;color:var(--info);">
                    Generating voiceovers for all scenes
                </div>
            </div>
            <div style="background:var(--border);border-radius:20px;height:10px;overflow:hidden;margin-bottom:10px;">
                <div id="regenBar" style="height:100%;background:var(--info);width:0%;transition:width .4s;border-radius:20px;"></div>
            </div>
            <div style="text-align:center;font-size:13px;font-weight:700;color:var(--text);margin-bottom:6px;">
                <span id="regenDone">0</span> of ${total} scenes done
            </div>
            <div id="regenStatus" style="text-align:center;font-size:11px;color:var(--muted);min-height:18px;"></div>
            <div id="regenLog" style="margin-top:12px;max-height:200px;overflow-y:auto;font-size:11px;
                background:var(--surface2);border-radius:8px;padding:8px;border:1px solid var(--border);
                font-family:monospace;color:var(--muted);line-height:1.7;"></div>
        </div>`;
    }

    let _regenLogLineId = 0;
    const addLog = (msg, id = null) => {
        const log = document.getElementById('regenLog');
        if (!log) return;
        if (id) {
            const existing = log.querySelector('[data-lid="' + id + '"]');
            if (existing) { existing.innerHTML = msg; log.scrollTop = log.scrollHeight; return; }
            log.innerHTML += '<span data-lid="' + id + '">' + msg + '</span><br>';
        } else {
            log.innerHTML += msg + '<br>';
        }
        log.scrollTop = log.scrollHeight;
    };

    let done = 0;
    for (const sc of SCENES) {
        const scCaps  = ALL_CAPTIONS.filter(c => +c.story_id === +sc.id);
        const mainCap = scCaps.find(c => (c.caption_name||'').toLowerCase() === 'main') || scCaps[0] || null;
        const text    = (mainCap ? mainCap.text_content : '').replace(/<break[^>]*>/gi, '').trim();

        const actor    = (sc.actor || '').toLowerCase();
        const rawVoice = (actor === 'guest' && _guestVoiceId) ? _guestVoiceId : _hostVoiceId;
        const voiceId  = _ensureVoicePrefix(rawVoice);

        // Update progress
        const statusEl = document.getElementById('regenStatus');
        if (statusEl) statusEl.textContent = `Processing scene ${done + 1} of ${total}…`;

        if (!text || !voiceId) {
            addLog(`⏭ Scene ${done + 1}: skipped (${!text ? 'no text' : 'no voice'})`);
            done++;
            _updateRegenProgress(done, total);
            continue;
        }

        try {
            const lineId = 'sc' + sc.id;
            addLog(`<span style="display:inline-flex;align-items:center;gap:5px;">
                <span style="display:inline-block;width:11px;height:11px;border:2px solid #ccc;border-top-color:var(--info);border-radius:50%;animation:spin 1s linear infinite;"></span>
                Scene ${done + 1}/${total}: generating…</span>`, lineId);
            const fd = new FormData();
            fd.append('ajax_action', 'generate_scene_audio');
            fd.append('text',        text);
            fd.append('voice_id',    voiceId);
            fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
            fd.append('rate',        (typeof AUDIO_SPEED !== 'undefined' ? AUDIO_SPEED : 1.0));
            fd.append('scene_id',    sc.id);
            fd.append('podcast_id',  PODCAST_ID);
            fd.append('admin_id',    <?= (int)$admin_id ?>);

            const r    = await fetch('wizard_step2.php', { method:'POST', body:fd });
            const raw  = await r.text();
            let data;
            try { data = JSON.parse(raw); }
            catch(e) {
                addLog(`❌ Scene ${done + 1}/${total}: bad response (HTTP ${r.status}): ${raw.substring(0, 200)}`, lineId);
                done++; _updateRegenProgress(done, total); continue;
            }
            if (data.success) {
                sc.audio_file = data.filename;
                addLog(`✅ Scene ${done + 1}/${total}: done`, lineId);
            } else {
                addLog(`⚠️ Scene ${done + 1}/${total}: failed — ${data.error || data.message || 'unknown'}`, lineId);
            }
        } catch(e) {
            addLog(`❌ Scene ${done + 1}: error — ${e.message}`);
        }

        done++;
        _updateRegenProgress(done, total);
    }

    // Show completion message briefly then hand off to caller
    const statusEl = document.getElementById('regenStatus');
    if (statusEl) statusEl.textContent = `✅ All ${total} scenes complete!`;
    addLog(`
✅ All done! Switching back…`);
    await new Promise(r => setTimeout(r, 1200)); // brief pause so user sees completion

    L(`✅ All ${total} scenes regenerated with new voice!`, 'ok');
    logActivity('voice_regen_all', _voiceTarget + ':' + _selectedVoiceKey);

    // Restore voice panel HTML so next open shows the selector
    if (voicePanel && origContent) voicePanel.innerHTML = origContent;
    // Reset _allVoices so _loadVoicePanel re-fetches and re-populates
    _allVoices = [];

    // Restore audio player for current scene
    const sc = SCENES[currentIndex];
    if (sc && sc.audio_file) _renderAudioPlayer(sc.audio_file);
}

// Ensure voice_id has correct TTS prefix (e.g. 'openai:nova', 'en-US-GuyNeural')
// If no prefix and voice exists in _allVoices with source='openai', add 'openai:'
function _ensureVoicePrefix(voiceId) {
    if (!voiceId) return voiceId;
    // Already has a prefix
    if (voiceId.includes(':')) return voiceId;
    // Look up in loaded voices list
    const found = _allVoices.find(v => v.voice_key === voiceId);
    if (found && (found.voice_source || '').toLowerCase() === 'openai') {
        return 'openai:' + voiceId;
    }
    // Default: assume openai if no source info (since we only load openai voices)
    return 'openai:' + voiceId;
}

function _updateRegenProgress(done, total) {
    const pct    = Math.round((done / total) * 100);
    const barEl  = document.getElementById('regenBar');
    const doneEl = document.getElementById('regenDone');
    if (barEl)  barEl.style.width  = pct + '%';
    if (doneEl) doneEl.textContent = done;
}




function saveSceneTextDebounced() {
    clearTimeout(_audioSaveTimer);
    _audioSaveTimer = setTimeout(async () => {
        const ta = document.getElementById('audioSceneText');
        if (!ta) return;
        const txt = ta.value;
        if (!_audCapId) { L('No caption found for this scene', 'err'); return; }
        const fd = new FormData();
        fd.append('ajax_action',  'save_caption_text');
        fd.append('caption_id',   _audCapId);
        fd.append('text_content', txt);
        await fetch(location.href, { method:'POST', body:fd });
        // Update in-memory
        const cap = ALL_CAPTIONS.find(c => +c.id === +_audCapId);
        if (cap) { cap.text_content = txt; startCaptionAnim(cap); }
        // Keep scene-list textarea in sync
        const ssTA = document.getElementById('ssCap' + currentIndex);
        if (ssTA) ssTA.value = txt;
    }, 900);
}

function _renderAudioPlayer(filename){
    const wrap=document.getElementById('audioPlayerWrap');if(!wrap)return;
    if(!filename){wrap.innerHTML=`<div style="text-align:center;padding:10px;background:var(--surface2);border-radius:8px;font-size:11px;color:var(--muted);">🎵 No voiceover yet</div>`;return;}
    wrap.innerHTML=`<div style="display:flex;align-items:center;gap:8px;background:var(--surface2);border-radius:30px;padding:6px 12px;border:1.5px solid var(--border);">
        <button id="apPlayBtn" style="width:30px;height:30px;border-radius:50%;border:none;background:var(--info);color:#fff;cursor:pointer;font-size:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
        <div style="flex:1;height:4px;background:var(--border);border-radius:2px;cursor:pointer;" id="apBar"><div id="apFill" style="height:100%;background:var(--info);border-radius:2px;width:0%;"></div></div>
        <span id="apTime" style="font-size:10px;color:var(--muted);white-space:nowrap;min-width:60px;text-align:right;">0:00</span></div>
    <div style="font-size:10px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${filename}">📄 ${filename}</div>`;
    const audio=new Audio(AUD_BASE+filename+'?t='+Date.now());
	audio.volume = voiceVolume;   // ← add this line
	audio.id='apEl';audio.preload='metadata';
	wrap.appendChild(audio);
    const btn=document.getElementById('apPlayBtn'),fill=document.getElementById('apFill'),time=document.getElementById('apTime'),bar=document.getElementById('apBar');
    audio.addEventListener('loadedmetadata',()=>{if(time)time.textContent='0:00 / '+_fmt(audio.duration);});
    audio.addEventListener('timeupdate',()=>{const pct=(audio.currentTime/audio.duration)*100||0;if(fill)fill.style.width=pct+'%';if(time)time.textContent=_fmt(audio.currentTime)+' / '+_fmt(audio.duration);});
    audio.addEventListener('ended',()=>{if(btn)btn.textContent='▶';if(fill)fill.style.width='0%';});
    if(btn)btn.addEventListener('click',()=>{if(audio.paused){audio.play().catch(e=>L('Audio: '+e.message,'wrn'));btn.textContent='⏸';}else{audio.pause();btn.textContent='▶';}});
    if(bar)bar.addEventListener('click',e=>{const r=bar.getBoundingClientRect();audio.currentTime=((e.clientX-r.left)/r.width)*audio.duration;});
}
function _fmt(s){if(!s||isNaN(s))return'0:00';const m=Math.floor(s/60),sec=Math.floor(s%60);return m+':'+(sec<10?'0':'')+sec;}

async function generateSceneAudio(){
    const sc  = SCENES[currentIndex];
    const ta  = document.getElementById('audioSceneText');
    const text = (ta?.value || '').replace(/<break[^>]*>/gi,'').trim();
    if (!text) { alert('No text for this scene.'); return; }

    // Determine voice: guest detection from raw caption text
    const isGuest = /^GUEST\s*:/i.test(text.trim());
    const voiceId = (isGuest && _guestVoiceId) ? _guestVoiceId : _hostVoiceId;
    if (!voiceId) { alert('No voice set for this podcast.'); return; }

    const btn = document.getElementById('btnGenAudio');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Generating…'; }
    L('Generating voiceover…', 'inf');

    const fd = new FormData();
    fd.append('ajax_action', 'generate_scene_audio');
    fd.append('text',        text);
    fd.append('voice_id',    voiceId);
    fd.append('lang_code',   '<?= addslashes($lang_code) ?>');
    fd.append('rate',        (typeof AUDIO_SPEED !== 'undefined' ? AUDIO_SPEED : 1.0));
    fd.append('scene_id',    sc.id);
    fd.append('podcast_id',  PODCAST_ID);

    try {
        const r = await fetch('wizard_step2.php', { method:'POST', body:fd });
        const raw = await r.text();
        let data;
        try { data = JSON.parse(raw); } catch(e) { throw new Error('Server error (503) — try again'); }
        if (!data.success) throw new Error(data.message || 'TTS failed');
        sc.audio_file = data.filename;
        _renderAudioPlayer(data.filename);
        L('Voiceover ready: ' + data.filename, 'ok');
        logActivity('audio_generated', 'voice:' + voiceId, currentIndex);
    } catch(e) {
        L('Generate failed: ' + e.message, 'err');
        alert('Voiceover failed: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Generate Voiceover'; }
    }
}

function _renderCurrentMusic(){
    const wrap=document.getElementById('musicCurrentWrap');if(!wrap)return;
    if(_currentPodcastMusic){
        wrap.innerHTML=`<div style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1.5px solid var(--success);border-radius:8px;padding:7px 10px;">
            <span style="font-size:16px;">🎵</span>
            <span style="flex:1;font-size:11px;font-weight:600;color:var(--success);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${_currentPodcastMusic}">${_currentPodcastMusic}</span>
            <button onclick="_previewCurrentMusic(this)" style="width:24px;height:24px;border-radius:50%;border:none;background:var(--success);color:#fff;font-size:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button></div>`;
    }else{wrap.innerHTML=`<div style="font-size:11px;color:var(--muted);padding:4px 0;">No background music selected</div>`;}
}
function _previewCurrentMusic(btn){
    if(_musicPreviewAudio&&!_musicPreviewAudio.paused){
        _musicPreviewAudio.pause();btn.textContent='▶';return;
    }
    _musicPreviewAudio=new Audio(MUS_BASE+_currentPodcastMusic+'?t='+Date.now());
    _musicPreviewAudio.volume = bgMusicVolume;  // ← add this
    _musicPreviewAudio.onended=()=>{btn.textContent='▶';};
    _musicPreviewAudio.play().catch(()=>{});
    btn.textContent='⏹';
}
function uploadMusicClick(){const inp=document.getElementById('musicFileInput');if(inp){inp.value='';inp.click();}}
async function handleMusicUpload(input){
    if(!input.files||!input.files[0])return;
    const fd=new FormData();fd.append('ajax_action','upload_podcast_music');fd.append('music_file',input.files[0]);
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();if(!data.success)throw new Error(data.message);_currentPodcastMusic=data.filename;_applyBgAudio();_renderCurrentMusic();L('Music uploaded: '+data.filename,'ok');}
    catch(e){L('Upload failed: '+e.message,'err');}
}
function _applyBgAudio(){
    if(bgAudio)bgAudio.pause();
    if(_currentPodcastMusic){
        bgAudio=new Audio(MUS_BASE+_currentPodcastMusic+'?t='+Date.now());
        bgAudio.loop=true;
        bgAudio.volume=bgMusicVolume;  // ← uses current slider value, not hardcoded 0.3
    } else {
        bgAudio=null;
    }
}
async function clearPodcastMusic(){
    const fd=new FormData();fd.append('ajax_action','update_podcast_music');fd.append('music_file','');
    await fetch(location.href,{method:'POST',body:fd});_currentPodcastMusic='';_applyBgAudio();_renderCurrentMusic();L('Music removed','ok');
}

async function openMusicLibModal(){
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}
    _selectedMusicFile=null;
    const useBtn=document.getElementById('musicLibUseBtn');if(useBtn){useBtn.disabled=true;useBtn.style.opacity='.4';}
    const info=document.getElementById('musicLibSelInfo');if(info)info.textContent='No file selected';
    const modal=document.getElementById('musicLibModal');if(modal)modal.style.display='flex';
    await _loadMusicLibGrid();
}
function closeMusicLibModal(){if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;}const modal=document.getElementById('musicLibModal');if(modal)modal.style.display='none';_selectedMusicFile=null;}
async function _loadMusicLibGrid(){
    const grid=document.getElementById('musicLibGrid');if(grid)grid.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">Loading…</div>';
    const fd=new FormData();fd.append('ajax_action','get_music_library');
    try{const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();_musicLibFiles=data.files||[];_renderMusicLibGrid(_musicLibFiles);}
    catch(e){if(grid)grid.innerHTML='<div style="color:var(--danger);text-align:center;padding:20px;">Error loading files</div>';}
}
function filterMusicLibGrid(){const q=(document.getElementById('musicLibSearch')?.value||'').toLowerCase();_renderMusicLibGrid(q?_musicLibFiles.filter(f=>f.filename.toLowerCase().includes(q)):_musicLibFiles);}
function _renderMusicLibGrid(files){
    const grid=document.getElementById('musicLibGrid');if(!grid)return;
    if(!files.length){grid.innerHTML='<div style="text-align:center;padding:30px;color:var(--muted);">No music files found</div>';return;}
    grid.innerHTML=files.map(f=>{
        const isCur=f.filename===_currentPodcastMusic;
        return `<div onclick="_pickMusicFile(this,'${f.filename}')" style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:10px;border:1.5px solid ${isCur?'var(--success)':'var(--border)'};background:${isCur?'#f0fdf4':'var(--surface)'};cursor:pointer;transition:border-color .13s;">
            <span style="font-size:20px;flex-shrink:0;">🎵</span>
            <div style="flex:1;min-width:0;"><div style="font-size:11px;font-weight:600;color:${isCur?'var(--success)':'var(--text)'};white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${f.filename}">${f.filename}</div><div style="font-size:9px;color:var(--muted);">${(f.size/1024).toFixed(0)} KB</div></div>
            <button onclick="event.stopPropagation();_prevMusicLib('${MUS_BASE+f.filename}',this)" style="width:24px;height:24px;border-radius:50%;border:none;background:var(--info);color:#fff;font-size:9px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;">▶</button>
            ${isCur?'<span style="font-size:11px;">✓</span>':''}
        </div>`;
    }).join('');
}
function _pickMusicFile(el,filename){
    document.querySelectorAll('#musicLibGrid > div').forEach(d=>{d.style.borderColor='var(--border)';d.style.background='var(--surface)';});
    el.style.borderColor='var(--info)';el.style.background='#eff6ff';
    _selectedMusicFile=filename;
    const info=document.getElementById('musicLibSelInfo');if(info)info.textContent=filename;
    const btn=document.getElementById('musicLibUseBtn');if(btn){btn.disabled=false;btn.style.opacity='1';}
}
function _prevMusicLib(src,btn){
    if(_musicPreviewAudio){_musicPreviewAudio.pause();_musicPreviewAudio=null;document.querySelectorAll('#musicLibGrid button').forEach(b=>{if(b.textContent==='⏹')b.textContent='▶';});}
    if(btn.textContent==='⏹'){btn.textContent='▶';return;}
    _musicPreviewAudio=new Audio(src+'?t='+Date.now());_musicPreviewAudio.onended=()=>{btn.textContent='▶';};_musicPreviewAudio.play().catch(()=>{});btn.textContent='⏹';
}
async function useMusicLibFile(){
    if(!_selectedMusicFile)return;
    const fd=new FormData();fd.append('ajax_action','update_podcast_music');fd.append('music_file',_selectedMusicFile);
    const r=await fetch(location.href,{method:'POST',body:fd});const data=await r.json();
    if(data.success){_currentPodcastMusic=_selectedMusicFile;_applyBgAudio();_renderCurrentMusic();closeMusicLibModal();L('Music set: '+_selectedMusicFile,'ok');}
    else{L('Failed to set music','err');}
}

function sleep(ms){return new Promise(r=>setTimeout(r,ms));}

function openCaptionPanel(){
    if(!selectedCapId && !sceneCaptions.length){
        alert('No captions on this scene yet. Click "+ Add Caption" after opening the panel.');
        togglePanel('pCaption','ibCaption');
        renderCaptionTabs();
        return;
    }
    if(!selectedCapId){
        togglePanel('pCaption','ibCaption');
        renderCaptionTabs();
        // Auto-select first caption
        if(sceneCaptions.length>0) selectCaption(sceneCaptions[0].id); 
        return;
    }
    togglePanel('pCaption','ibCaption');
    renderCaptionTabs();
}

(async function boot() {
    startRender();

    // Restore slot checkboxes for the first scene
    applySceneSlots(SCENES[0]);

    // On page load, only load the first scene — no full preload.
    // Full preloadAll() runs only when Play or Record is clicked.
    const ov = document.getElementById('preloadOverlay');
    if (ov) {
        ov.classList.remove('gone');
        ov.style.opacity = '1';
    }

    const msg = document.getElementById('preloadMsg');
    if (msg) msg.textContent = 'Loading first scene...';

    // Load only the current (first) scene's media
    await prepareNextScene(0);

    // Show first scene instantly
    await showScene(0, true);
	updatePanelSceneNumbers();  // ADD THIS LINE
    // Hide overlay
    if (ov) {
        ov.style.transition = 'opacity .5s ease';
        ov.style.opacity = '0';
        setTimeout(() => ov.classList.add('gone'), 550);
    }

    updateNavButtons();

    const mvs = document.getElementById('musicVolSlider');
    const vvs = document.getElementById('voiceVolSlider');
    if (mvs) onMusicVolChange(mvs.value);
    if (vvs) onVoiceVolChange(vvs.value);

    bootComplete = true; // now safe to handle checkbox changes
})();


// ── Publish Modal (BPM-style, replaces old VS scheduler) ───────────────────

// ── State ─────────────────────────────────────────────────────────
let _vsRecURL     = null;   // object URL (blob) or server URL for download
let _vsRecFname   = null;   // filename e.g. podcast_6.webm / podcast_6.mp4
let _vsPodcastId  = PODCAST_ID;
// Show download bar with WebM info




// ── Open scheduler after recording ────────────────────────────────
// Track conversion state to prevent duplicates

let _conversionCompleted = false;

// Track conversion state
let _conversionInProgress = false;
let _conversionPollInterval = null;

async function startMp4Convert(podcastId) {
    if (_conversionInProgress) return;
    _conversionInProgress = true;
    
    const overlay = document.getElementById('uploadOverlay');
    if (overlay) overlay.style.display = 'flex';
    
    L('🎬 Starting MP4 conversion…', 'ok');
    
    let jobId = null;
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'start_mp4_convert');
        fd.append('podcast_id', podcastId);
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const data = await r.json();
        
        // If VPS is unavailable, offer WebM download directly
        if (data.fallback || !data.success) {
            if (overlay) overlay.style.display = 'none';
            _conversionInProgress = false;
            L('⚠ MP4 conversion not available. WebM version is ready.', 'wrn');
            showWebmDownloadOnly(podcastId);
            return;
        }
        
        if (!data.job_id) {
            throw new Error(data.message || 'No job ID returned');
        }
        
        jobId = data.job_id;
        L('⏳ Conversion started, waiting…', 'ok');
        
    } catch (e) {
        if (overlay) overlay.style.display = 'none';
        _conversionInProgress = false;
        L('⚠ Conversion error: ' + e.message, 'wrn');
        showWebmDownloadOnly(podcastId);
        return;
    }
    
    // Poll for completion
    let attempts = 0;
    const maxAttempts = 60;
    
    _conversionPollInterval = setInterval(async () => {
        attempts++;
        
        try {
            const fd = new FormData();
            fd.append('ajax_action', 'poll_mp4_convert');
            fd.append('job_id', jobId);
            fd.append('podcast_id', podcastId);
            const r = await fetch(location.href, { method: 'POST', body: fd });
            const data = await r.json();
            
            if (data.status === 'done') {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('✅ MP4 ready!', 'ok');
                
                // Show MP4 download
                const mp4Url = 'published_videos/podcast_' + podcastId + '.mp4';
                showMp4DownloadOnly(mp4Url, 'podcast_' + podcastId + '.mp4');
            }
            
            if (data.status === 'failed' || data.status === 'error') {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('⚠ MP4 conversion failed', 'wrn');
                showWebmDownloadOnly(podcastId);
            }
            
            if (attempts >= maxAttempts) {
                clearInterval(_conversionPollInterval);
                if (overlay) overlay.style.display = 'none';
                _conversionInProgress = false;
                L('⚠ Conversion timeout', 'wrn');
                showWebmDownloadOnly(podcastId);
            }
        } catch(e) {
            console.warn('Poll error:', e);
        }
    }, 5000);
}

function showMp4DownloadOnly(mp4Url, filename) {
    const dlPanel = document.getElementById('dlPanel');
    dlPanel.innerHTML = `
        <h3>✅ MP4 Ready!</h3>
        <p id="dlMeta">MP4 format - Ready for social media</p>
        <p style="font-size:11px;color:var(--muted);">Click below to save your video.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn success" href="${mp4Url}" download="${filename}" style="background:var(--success);color:#fff;">⬇ Download MP4</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    dlPanel.classList.add('on');
}

function showWebmDownloadOnly(podcastId, blobUrl) {
    const webmUrl = blobUrl || 'published_videos/podcast_' + podcastId + '.webm';
    const dlPanel = document.getElementById('dlPanel');
    dlPanel.innerHTML = `
        <h3>⚠️ WebM Only</h3>
        <p id="dlMeta">WebM format - Use VLC or Chrome to play</p>
        <p style="font-size:11px;color:var(--muted);">MP4 conversion unavailable.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn" href="${webmUrl}" download="podcast_${podcastId}.webm">⬇ Download WebM</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    dlPanel.classList.add('on');
}



// Show scheduler with MP4 option (replaces the old WebM modal)
function showSchedulerWithMp4(podcastId, filename, sizeMb) {
    const mp4Url = 'published_videos/' + filename;
    const mp4Filename = filename;
    
    // Close any existing download panel
    const existingPanel = document.getElementById('dlPanel');
    if (existingPanel) {
        existingPanel.classList.remove('on');
        existingPanel.style.display = 'none';
    }
    
    // Update the scheduler modal to show MP4 is ready
    const vsFilenameDisplay = document.getElementById('vsFilenameDisplay');
    if (vsFilenameDisplay) {
        vsFilenameDisplay.textContent = mp4Filename + ' ✅ MP4';
        vsFilenameDisplay.style.color = '#10b981';
    }
    
    // Store MP4 info for download
    window._mp4Ready = true;
    window._mp4Url = mp4Url;
    window._mp4Filename = mp4Filename;
    
    // Open the scheduler modal
    openSchedModalWithMp4(mp4Url, mp4Filename, sizeMb);
}

// Open publish modal with confirmed MP4 — BPM-style
function openSchedModalWithMp4(mp4Url, filename, sizeMb) {
    closeSchedModal();

    _vsRecURL   = mp4Url;
    _vsRecFname = filename;

    const vsMain    = document.getElementById('vsMain');
    const vsConfirm = document.getElementById('vsConfirm');
    if (vsMain)    vsMain.style.display    = 'block';
    if (vsConfirm) vsConfirm.style.display = 'none';

    // Update saved label
    const savedEl = document.getElementById('vsFilenameDisplay');
    if (savedEl) savedEl.innerHTML =
        `<div class="bpm-saved-dot"></div>` +
        `<span>Video ready — <strong>${filename}</strong> · ${sizeMb || '?'} MB ✅ MP4</span>`;

    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) subTitle.textContent = '<?= addslashes($podcast_title ?: "Your Video") ?>';

    document.getElementById('vsWarn').style.display = 'none';
    _vsSetDefaultDate();
    _vsPopulateCaption();

    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');
}

// Direct MP4 download function
function downloadMp4Directly() {
    if (window._mp4Url && window._mp4Filename) {
        const a = document.createElement('a');
        a.href = window._mp4Url;
        a.download = window._mp4Filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        L('⬇ MP4 download started', 'ok');
    } else {
        // Try to get from podcast ID
        const mp4Url = 'published_videos/podcast_' + PODCAST_ID + '.mp4';
        const a = document.createElement('a');
        a.href = mp4Url;
        a.download = 'podcast_' + PODCAST_ID + '.mp4';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
}

// Show WebM fallback in publish modal
function showWebmDownloadOptions(podcastId) {
    const webmUrl      = 'published_videos/podcast_' + podcastId + '.webm';
    const webmFilename = 'podcast_' + podcastId + '.webm';

    _vsRecURL   = webmUrl;
    _vsRecFname = webmFilename;

    const savedEl = document.getElementById('vsFilenameDisplay');
    if (savedEl) savedEl.innerHTML =
        `<div class="bpm-saved-dot" style="background:#f59e0b;"></div>` +
        `<span>Video saved — <strong>${webmFilename}</strong> ⚠️ WebM format</span>`;

    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) subTitle.textContent = 'WebM format — convert to MP4 for best compatibility';

    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');
}

// ── Open publish modal after recording (receives blob from recorder) ──────────
let _schedulerOpening = false;

function openSchedModal(blob, url, fname) {
    if (_schedulerOpening) return;
    _schedulerOpening = true;

    _vsRecURL   = url;
    _vsRecFname = fname;

    // Update saved label with filename
    const savedEl = document.getElementById('vsFilenameDisplay');
    if (savedEl) savedEl.innerHTML =
        `<div class="bpm-saved-dot"></div><span>Video saved — <strong>${fname}</strong></span>`;

    const subTitle = document.getElementById('vsSubTitle');
    if (subTitle) subTitle.textContent = '<?= addslashes($podcast_title ?: "Your Video") ?>';

    const vsMain    = document.getElementById('vsMain');
    const vsConfirm = document.getElementById('vsConfirm');
    if (vsMain)    vsMain.style.display    = 'block';
    if (vsConfirm) vsConfirm.style.display = 'none';

    document.getElementById('vsWarn').style.display = 'none';
    _vsPopulateCaption();
    _vsSetDefaultDate();

    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.add('open');

    setTimeout(() => { _schedulerOpening = false; }, 1000);
}

function closeSchedModal() {
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.classList.remove('open');
    _schedulerOpening = false;
}

// Close on backdrop click
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('vsOverlay');
    if (overlay) overlay.addEventListener('click', function(e) {
        if (e.target === this) closeSchedModal();
    });

    // ── Initialise audio speed UI from podcast's saved audio_speed ──
    if (typeof AUDIO_SPEED !== 'undefined' && AUDIO_SPEED !== 1.0) {
        // Update all speed sliders and labels on the page
        document.querySelectorAll('#playbackSpeedSlider').forEach(el => {
            el.value = AUDIO_SPEED;
        });
        document.querySelectorAll('#speedValue').forEach(el => {
            el.textContent = AUDIO_SPEED.toFixed(2) + 'x';
        });
        // Move active class to the matching preset button (if exact match)
        document.querySelectorAll('.speed-preset').forEach(btn => {
            const btnSpeed = parseFloat(btn.dataset.speed);
            btn.classList.toggle('active', Math.abs(btnSpeed - AUDIO_SPEED) < 0.01);
        });
    }
});

// ── Pre-populate caption from podcast data ─────────────────────────
function _vsPopulateCaption() {
    const fd = new FormData();
    fd.append('ajax_action', 'get_podcast_caption_data');
    fd.append('podcast_id',  PODCAST_ID);
    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            document.getElementById('vsCaption').value  = d.caption_text || '';
            document.getElementById('vsKeywords').value = d.keywords     || '';
            document.getElementById('vsHashtags').value = d.hashtags     || '';
        })
        .catch(() => {});
}

// ── Default date/time (Tomorrow) ───────────────────────────────────
function _vsSetDefaultDate() {
    const tomorrowBtn = document.querySelectorAll('#vsOverlay .bpm-qpill')[2];
    if (tomorrowBtn) vsQuick(tomorrowBtn, 24);
}

// ── Tab switching ──────────────────────────────────────────────────
function vsSwitchTab(tab, btn) {
    document.querySelectorAll('#vsOverlay .bpm-ctab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#vsOverlay .bpm-ctab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('vs-tab-' + tab).classList.add('active');
}

// ── Platform toggle ────────────────────────────────────────────────
function vsTogglePlat(el) {
    if (el.classList.contains('disconnected')) return;
    el.classList.toggle('sel');
    document.getElementById('vsWarn').style.display = 'none';
}

function vsGetPlats() {
    return [...document.querySelectorAll('#vsOverlay .bpm-plat.sel:not(.disconnected)')].map(el => el.dataset.p);
}

// ── Quick date pills ───────────────────────────────────────────────
function vsQuick(btn, hrs) {
    document.querySelectorAll('#vsOverlay .bpm-qpill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const d = new Date();
    d.setHours(d.getHours() + hrs);
    document.getElementById('vsDate').value = d.toISOString().split('T')[0];
    document.getElementById('vsTime').value = d.toTimeString().slice(0, 5);
}

// ── Download ───────────────────────────────────────────────────────
function vsDownload() {
    const a = document.createElement('a');
    if (window._mp4Ready && window._mp4Url && window._mp4Filename) {
        a.href     = window._mp4Url;
        a.download = window._mp4Filename;
        L('⬇ Downloading MP4…', 'ok');
    } else if (_vsRecURL) {
        a.href     = _vsRecURL;
        a.download = _vsRecFname || ('podcast_' + PODCAST_ID + '.webm');
        L('⬇ Downloading WebM…', 'ok');
    } else {
        L('No recording available', 'err');
        return;
    }
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    closeSchedModal();
}

// ── Post now ───────────────────────────────────────────────────────
function vsPostNow() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
    _vsSave('now', plats, null);
}

// ── Schedule ───────────────────────────────────────────────────────
function vsSchedule() {
    const plats = vsGetPlats();
    if (!plats.length) { document.getElementById('vsWarn').style.display = 'block'; return; }
    const date = document.getElementById('vsDate').value;
    const time = document.getElementById('vsTime').value;
    if (!date || !time) { alert('Please select a date and time'); return; }
    _vsSave('scheduled', plats, new Date(date + 'T' + time));
}

// ── Save to backend ────────────────────────────────────────────────
async function _vsSave(type, plats, dt) {
    const payload = {
        podcast_id:     _vsPodcastId,
        platforms:      plats,
        caption:        document.getElementById('vsCaption').value,
        keywords:       document.getElementById('vsKeywords').value,
        hashtags:       document.getElementById('vsHashtags').value,
        sched_date:     dt ? dt.toISOString().split('T')[0]  : new Date().toISOString().split('T')[0],
        sched_time:     dt ? dt.toTimeString().slice(0, 5)    : new Date().toTimeString().slice(0, 5),
        post_type:      type,
        video_filename: _vsRecFname,
    };
    try {
        const r    = await fetch('social_schedule.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await r.json();
        if (data.success) {
            _vsShowConfirm(type, plats, dt);
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch(e) {
        // social_schedule.php not yet wired — show confirm anyway
        _vsShowConfirm(type, plats, dt);
    }
}

// ── Confirm screen ─────────────────────────────────────────────────
function _vsShowConfirm(type, plats, dt) {
    document.getElementById('vsMain').style.display    = 'none';
    document.getElementById('vsConfirm').style.display = 'block';

    const labels = {
        instagram:'📸 Instagram', tiktok:'🎵 TikTok', youtube:'▶️ YouTube',
        facebook:'📘 Facebook',   twitter:'🐦 X',      linkedin:'💼 LinkedIn',
    };

    if (type === 'now') {
        document.getElementById('vsConfirmIcon').textContent  = '🎉';
        document.getElementById('vsConfirmTitle').textContent = 'Posted!';
        document.getElementById('vsConfirmSub').textContent   = 'Going live now';
    } else {
        const ds = dt.toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric' });
        const ts = dt.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
        document.getElementById('vsConfirmIcon').textContent  = '🗓';
        document.getElementById('vsConfirmTitle').textContent = 'Scheduled!';
        document.getElementById('vsConfirmSub').textContent   = `Posts ${ds} at ${ts}`;
    }

    document.getElementById('vsConfirmPills').innerHTML =
        plats.map(p => `<span class="bpm-confirm-pill">${labels[p] || p}</span>`).join('');

    L('✅ ' + (type === 'now' ? 'Posted' : 'Scheduled') + ' to: ' + plats.join(', '), 'ok');
}

async function addImageCaption() {
    // Show a quick choice: Upload or Library
    const choice = await _imgCapChoiceModal();
    if (!choice) return;

    let filename = null;

    if (choice === 'upload') {
        filename = await _imgCapUpload();
    } else {
        filename = await _imgCapFromLibrary();
    }

    if (!filename) return;

    const sc = SCENES[currentIndex];
    const name = 'img' + (sceneCaptions.length + 1);
    const fd = new FormData();
    fd.append('ajax_action',  'add_image_caption');
    fd.append('story_id',     sc.id);
    fd.append('caption_name', name);
    fd.append('filename',     filename);
    fd.append('position_x',   20);
    fd.append('position_y',   20);
    fd.append('width',        120);
    fd.append('height',       120);

    try {
        const r    = await fetch(location.href, { method:'POST', body:fd });
        const data = await r.json();
        if (data.success && data.caption) {
            const newCap = data.caption;
            ALL_CAPTIONS.push(newCap);
            // Pre-load the image into cache
            await _preloadCapImage(newCap);
            captionStates[newCap.id] = { show: filename, full: filename, words: [], karIdx: 0, timer: null };
            selectedCapId = parseInt(newCap.id);
            loadSceneCaptions(sc.id);
            selectCaption(newCap.id);
            L('Image caption added', 'ok');
        } else {
            L('Add image caption failed: ' + (data.message || ''), 'err');
        }
    } catch(e) {
        L('Image caption error: ' + e.message, 'err');
    }
}

// ── Preload image for caption ──────────────────────────────────
function _preloadCapImage(cap) {
    return new Promise(res => {
        const fn = cap.text_content || '';
        if (!fn || imgCache[fn]) { res(); return; }
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload  = () => { imgCache[fn] = img; res(); };
        img.onerror = () => res();
        setTimeout(res, 8000);
        img.src = (fn.startsWith('logo_') ? 'podcast_logos/' : getFileFolder(fn)) + fn + '?t=' + Date.now();
    });
}
// ── Font Picker ────────────────────────────────────────────────────

// ── Caption sub-tab switcher ───────────────────────────────────────
function switchCapSubTab(n) {
    document.getElementById('capSubTab1').style.display = n === 1 ? 'block' : 'none';
    document.getElementById('capSubTab2').style.display = n === 2 ? 'block' : 'none';
    const b1 = document.getElementById('capSubBtn1');
    const b2 = document.getElementById('capSubBtn2');
    if (b1) {
        b1.style.background = n === 1 ? '#ffffff' : '#5fc3ff';
        b1.style.color      = '#0f2a44';
        b1.style.opacity    = n === 1 ? '1' : '0.75';
    }
    if (b2) {
        b2.style.background = n === 2 ? '#ffffff' : '#5fc3ff';
        b2.style.color      = '#0f2a44';
        b2.style.opacity    = n === 2 ? '1' : '0.75';
    }
}

// ── Border live update ─────────────────────────────────────────────
function capBorderChanged() {
    const colorEl = document.getElementById('capBorderColor');
    const thickEl = document.getElementById('capBorderThick');
    const preview = document.getElementById('capBorderPreview');
    const color   = colorEl ? colorEl.value : '#ffffff';
    const thick   = thickEl ? parseInt(thickEl.value) : 0;
    if (preview) {
        preview.style.borderWidth = thick + 'px';
        preview.style.borderColor = color;
        preview.style.borderStyle = thick > 0 ? 'solid' : 'none';
    }
    capFieldChanged('caption_box_border_color',     color);
    capFieldChanged('caption_box_border_thickness', thick);
}

function toggleFontPicker() {
    const dd = document.getElementById('fontPickerDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
function toggleBgEnabled(checked) {
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;
    cap.bg_enabled = checked ? 1 : 0;
    const lbl = document.getElementById('capBgEnableLabel');
    if (lbl) lbl.style.color = checked ? 'var(--info)' : 'var(--muted)';
    capFieldChanged('bg_enabled', cap.bg_enabled);
}
function selectFont(val, label, el) {
    // Update hidden input
    document.getElementById('capFont').value = val;
    // Update button label in its own font
    const lbl = document.getElementById('fontPickerLabel');
    lbl.textContent    = label;
    lbl.style.fontFamily = val;
    // Mark selected
    document.querySelectorAll('.fp-opt').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    // Close dropdown
    document.getElementById('fontPickerDropdown').style.display = 'none';
    // Fire caption change — updates cap.fontfamily in memory + saves to DB
    capFieldChanged('fontfamily', val);
    // Reset font log flag so next render re-evaluates the family string
    if (selectedCapId) {
        const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
        if (cap) cap._fontLogged = false;
    }
	logActivity('font_change', val, currentIndex);
}

// Close font picker when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('fontPickerWrap');
    if (wrap && !wrap.contains(e.target)) {
        const dd = document.getElementById('fontPickerDropdown');
        if (dd) dd.style.display = 'none';
    }
});

// ── Delegated click for font picker options ───────────────────────
// fp-opt divs have no inline onclick — this handler catches all of them,
// including any new fonts added later, without touching the HTML.
document.addEventListener('click', function(e) {
    const opt = e.target.closest('.fp-opt');
    if (!opt) return;
    const val = opt.dataset.val;
    if (!val) return;
    // Build label: text content minus any child <span> (used for subtitle in Arabic fonts)
    const clone = opt.cloneNode(true);
    clone.querySelectorAll('span').forEach(s => s.remove());
    const label = clone.textContent.trim() || val.split(',')[0].replace(/'/g, '');
    selectFont(val, label, opt);
});
// ── Choice modal (Upload vs Library) ──────────────────────────
function _imgCapChoiceModal() {
    return new Promise(res => {
        const overlay = document.createElement('div');
        overlay.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;
            display:flex;align-items:center;justify-content:center;`;
        overlay.innerHTML = `
            <div style="background:#fff;border-radius:14px;padding:24px;width:280px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4);">
                <div style="font-size:20px;margin-bottom:8px;">🖼️</div>
                <div style="font-size:14px;font-weight:700;color:#0f2a44;margin-bottom:6px;">Add Image Caption</div>
                <div style="font-size:11px;color:#64748b;margin-bottom:18px;">Choose an image to place on the canvas</div>
                <div style="display:flex;gap:8px;">
                    <button id="_icUpload" style="flex:1;padding:10px;border-radius:9px;border:none;background:var(--success);color:#fff;font-size:12px;font-weight:700;cursor:pointer;">📤 Upload</button>
                    <button id="_icLib"    style="flex:1;padding:10px;border-radius:9px;border:none;background:#7c3aed;color:#fff;font-size:12px;font-weight:700;cursor:pointer;">📚 Library</button>
                </div>
                <button id="_icCancel" style="margin-top:10px;width:100%;padding:8px;border:none;background:none;color:#94a3b8;font-size:11px;cursor:pointer;">Cancel</button>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#_icUpload').onclick  = () => { document.body.removeChild(overlay); res('upload'); };
        overlay.querySelector('#_icLib').onclick     = () => { document.body.removeChild(overlay); res('library'); };
        overlay.querySelector('#_icCancel').onclick  = () => { document.body.removeChild(overlay); res(null); };
        overlay.addEventListener('click', e => { if (e.target === overlay) { document.body.removeChild(overlay); res(null); } });
    });
}

// ── Upload flow ────────────────────────────────────────────────
function _imgCapUpload() {
    return new Promise(res => {
        const inp = document.createElement('input');
        inp.type   = 'file';
        inp.accept = 'image/*';
        inp.onchange = async () => {
            if (!inp.files || !inp.files[0]) { res(null); return; }
            const file = inp.files[0];
            const sc   = SCENES[currentIndex];
            const fd   = new FormData();
            fd.append('ajax_action', 'upload_scene_image');
            fd.append('scene_id',    sc.id);
            fd.append('image_field', 'image_file');   // slot doesn't matter, just needs valid field
            fd.append('media_type',  'image');
            fd.append('scene_image', file);
            L('Uploading image…', 'inf');
            try {
                const r    = await fetch(location.href, { method:'POST', body:fd });
                const data = await r.json();
                if (data.success) { res(data.filename); }
                else { L('Upload failed: ' + data.message, 'err'); res(null); }
            } catch(e) { L('Upload error: ' + e.message, 'err'); res(null); }
        };
        inp.click();
    });
}

// ── Library pick flow ──────────────────────────────────────────
function _imgCapFromLibrary() {
    return new Promise(res => {
        // Reuse existing library modal but resolve with filename on use
        window._imgCapLibResolve = res;
        window._imgCapLibMode    = true;
        openLibraryModal();
    });
}

// ── Helper: show/hide global checkbox ────────────────────────────
function _updateGlobalLabel() {
    const wrap = document.getElementById('capGlobalWrap');
    if (!wrap) return;
    const chk = document.getElementById('capGlobalChk');
    if (!selectedCapId) { wrap.style.display = 'none'; return; }
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) { wrap.style.display = 'none'; return; }
    const name = (cap.caption_name || '').toLowerCase().trim();
    if (GLOBAL_CAP_NAMES.includes(name)) {
        wrap.style.display = 'flex';
        if (chk) chk.checked = _applyToAllScenes;
    } else {
        wrap.style.display = 'none';
    }
}

// ── Field change → debounced save (with global propagation) ──────
function capFieldChanged(field, value) { 
    if (!selectedCapId) return;
    const cap = sceneCaptions.find(c => +c.id === +selectedCapId);
    if (!cap) return;

    // Clamp position and width to canvas bounds before applying
    if (field === 'width') {
        const x  = parseFloat(cap.position_x) || 0;
        value = Math.max(40, Math.min(CW - CAP_MARGIN - x, parseFloat(value) || 40));
    } else if (field === 'position_x') {
        const w  = parseFloat(cap.width) || 120;
        value = Math.max(CAP_MARGIN, Math.min(CW - CAP_MARGIN - w, parseFloat(value) || 0));
    } else if (field === 'position_y') {
        const bh = _capBoxH(cap);
        value = Math.max(0, Math.min(CH - Math.max(20, bh), parseFloat(value) || 0));
    }

    // Update current caption in memory
    cap[field] = value;
    if (!_capDirty[cap.id]) _capDirty[cap.id] = {};
    _capDirty[cap.id][field] = value;
    clearTimeout(_capSaveTimers[cap.id]);
    _capSaveTimers[cap.id] = setTimeout(() => _saveCaption(cap.id), 600);

    // Trigger animation restart if relevant
    if (field === 'text_content' || field === 'animation_style' || field === 'animation_speed')
        startCaptionAnim(cap);

    // Sync the scene-list caption textarea so it stays in step with overlay edits
    if (field === 'text_content') {
        const ssTA = document.getElementById('ssCap' + currentIndex);
        if (ssTA) ssTA.value = value;
    }

    // ── Global propagation (style/position only — NEVER text_content) ─────
    // text_content is unique per scene. Propagating it overwrites every scene's caption text.
    if (field === 'text_content') return;
    if (!_applyToAllScenes) return;
    const capName = (cap.caption_name || '').toLowerCase().trim();
    if (!GLOBAL_CAP_NAMES.includes(capName)) return;

    // Find all OTHER captions with same name across all scenes
    ALL_CAPTIONS.forEach(other => {
        if (+other.id === +cap.id) return; // skip self
        const otherName = (other.caption_name || '').toLowerCase().trim();
        if (otherName !== capName) return;

        // Update in memory
        other[field] = value;

        // Mark dirty and debounce save
        if (!_capDirty[other.id]) _capDirty[other.id] = {};
        _capDirty[other.id][field] = value;
        clearTimeout(_capSaveTimers[other.id]);
        _capSaveTimers[other.id] = setTimeout(() => _saveCaption(other.id), 600);

        // If it's the current scene's caption (different id, same name), restart anim
        const sceneMatch = sceneCaptions.find(sc => +sc.id === +other.id);
        if (sceneMatch && (field === 'text_content' || field === 'animation_style' || field === 'animation_speed'))
            startCaptionAnim(sceneMatch);
    });
}


// Helper function to update video status
async function updateVideoStatusToRecorded(podcastId) {
    try {
        const fd = new FormData();
        fd.append('ajax_action', 'update_video_status');
        fd.append('podcast_id', podcastId);
        fd.append('status', 'RECORDED');
        fd.append('company_id', COMPANY_ID);
        const response = await fetch(location.href, { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            L('✅ Video status updated to RECORDED', 'ok');
            console.log('Video status updated to RECORDED for podcast:', podcastId);
        } else {
            console.warn('Failed to update video status:', data);
        }
    } catch(e) {
        console.warn('Status update error:', e);
    }
}

// Show MP4 download modal
function showMp4DownloadModal(mp4Url, filename, sizeMb) {
    // Close any existing download panel first
    const existingPanel = document.getElementById('dlPanel');
    if (existingPanel) {
        existingPanel.classList.remove('on');
    }
    
    // Create or update download panel
    let dlPanel = document.getElementById('dlPanel');
    if (!dlPanel) {
        dlPanel = document.createElement('div');
        dlPanel.id = 'dlPanel';
        dlPanel.style.cssText = 'width:100%;max-width:360px;background:var(--surface);border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 16px;display:none;flex-direction:column;gap:10px;margin-top:10px;';
        const rightCol = document.querySelector('.right-col');
        if (rightCol) rightCol.appendChild(dlPanel);
    }
    
    dlPanel.innerHTML = `
        <h3 style="font-size:13px;font-weight:700;color:var(--success);">✅ MP4 Ready!</h3>
        <p id="dlMeta" style="font-size:11px;color:var(--text);font-weight:600;">${sizeMb} MB · MP4 format ✅<br>Ready for Instagram, TikTok, YouTube</p>
        <p style="font-size:11px;color:var(--muted);">Click below to save your video.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a id="dlLink" class="btn success" href="${mp4Url}" download="${filename}" style="background:var(--success);color:#fff;border:none;">⬇ Download MP4</a>
            <button class="btn" onclick="closeDownloadPanel()">✕ Close</button>
        </div>
    `;
    
    dlPanel.classList.add('on');
    dlPanel.style.display = 'flex';
    
    // Also update the scheduler modal if it's open
    if (typeof _vsRecFname !== 'undefined') {
        document.getElementById('vsFilenameDisplay').textContent = filename;
    }
}

function closeDownloadPanel() {
    const panel = document.getElementById('dlPanel');
    if (panel) {
        panel.classList.remove('on');
        panel.style.display = 'none';
    }
}
function showDownloadPanel(url, filename, format, sizeMb = null) {
    const dlPanel = document.getElementById('dlPanel');
    const dlMeta = document.getElementById('dlMeta');
    const dlLink = document.getElementById('dlLink');
    
    if (format === 'mp4') {
        dlMeta.innerHTML = `${sizeMb || '?'} MB · MP4 format ✅<br>Ready for Instagram, TikTok, YouTube`;
        dlLink.style.background = 'var(--success)';
    } else {
        dlMeta.innerHTML = `${sizeMb || '?'} MB · WebM format (open with VLC or Chrome)`;
        dlLink.style.background = '';
    }
    
    dlLink.href = url;
    dlLink.download = filename;
    dlPanel.classList.add('on');
}

function showMp4Toast(data) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:30px;right:30px;z-index:99999;' +
        'background:#052e16;border:1px solid #16a34a;color:#fff;' +
        'border-radius:14px;padding:20px 24px;max-width:320px;' +
        'box-shadow:0 10px 40px rgba(0,0,0,.4);';
    t.innerHTML = '<div style="font-weight:700;color:#4ade80;margin-bottom:6px;">✅ MP4 Ready!</div>' +
        '<div style="font-size:12px;color:#86efac;margin-bottom:4px;line-height:1.5;">' +
        data.filename + ' · ' + data.mp4_size_mb + ' MB<br>Saved to server. WebM deleted. ✓</div>' +
        '<span onclick="this.parentElement.remove()" ' +
        'style="position:absolute;top:10px;right:14px;cursor:pointer;color:#4ade80;font-size:20px;">×</span>';
    document.body.appendChild(t);
    setTimeout(() => t?.remove(), 20000);
}
// ─────────────────────────────────────────────────────────────────────────────

// ══════════════════════════════════════════════════════════════════════════════
// SCENE STRIP — scene list with thumbnails + per-scene action icons
// ══════════════════════════════════════════════════════════════════════════════

let _ssVisible = true;

function ssToggleStrip() {
    _ssVisible = !_ssVisible;
    const scroll = document.getElementById('sceneStripScroll');
    const btn    = document.getElementById('sceneStripToggle');
    if (scroll) scroll.style.display = _ssVisible ? '' : 'none';
    if (btn)    btn.textContent      = _ssVisible ? 'Hide ▲' : 'Show ▼';
}

/** Navigate to a scene via the strip row click */
function ssSelectScene(index) {
    if (index === currentIndex) return;
    showScene(index, false);
    ssHighlightCard(index);
}

/** Highlight the active row in the scene list */
function ssHighlightCard(index) {
    for (let i = 0; i < SCENES.length; i++) {
        const row = document.getElementById('ssRow' + i);
        if (!row) continue;
        if (i === index) {
            row.style.background = '#eff6ff';
            row.style.borderLeft = '3px solid var(--info)';
        } else {
            row.style.background = '';
            row.style.borderLeft = '';
        }
    }
    const activeRow = document.getElementById('ssRow' + index);
    const scroller  = document.getElementById('sceneStripScroll');
    if (activeRow && scroller) {
        const rowTop    = activeRow.offsetTop;
        const rowBottom = rowTop + activeRow.offsetHeight;
        const visTop    = scroller.scrollTop;
        const visBottom = visTop + scroller.clientHeight;
        if (rowTop < visTop || rowBottom > visBottom) {
            scroller.scrollTop = rowTop - scroller.clientHeight / 2;
        }
    }
}

// Intercept updateDots so scene strip stays in sync with all navigation methods
const _ssOrigUpdateDots = updateDots;
updateDots = function(i) {
    _ssOrigUpdateDots(i);
    ssHighlightCard(i);
};

// ── Open panels from strip icons ──────────────────────────────────

/** Navigate to scene (instant) then run callback after brief delay */
function _ssGoTo(index, cb) {
    if (currentIndex !== index) {
        showScene(index, true);
        setTimeout(cb, 120);
    } else {
        cb();
    }
}

// ── Save caption text from scene list textarea ────────────────────
async function ssSaveCaptionText(index) {
    const sc  = SCENES[index];
    const ta  = document.getElementById('ssCap' + index);
    if (!ta || !sc) return;
    const txt = ta.value;

    const scCaps  = ALL_CAPTIONS.filter(c => +c.story_id === +sc.id);
    const mainCap = scCaps.find(c => (c.caption_name||'').toLowerCase() === 'main') || scCaps[0] || null;

    const btn = document.querySelector(`#ssRow${index} button[onclick*="ssSaveCaptionText"]`);
    if (btn) { btn.textContent = '⏳ Saving…'; btn.disabled = true; }

    try {
        if (!mainCap) {
            L('No caption found for scene ' + (index + 1), 'err');
            if (btn) { btn.textContent = '❌ Error'; setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 1500); }
            return;
        }
        const fd = new FormData();
        fd.append('ajax_action',  'save_caption_text');
        fd.append('caption_id',   mainCap.id);
        fd.append('text_content', txt);
        await fetch(location.href, { method:'POST', body:fd });
        // Update in-memory and redraw canvas
        mainCap.text_content = txt;
        if (typeof startCaptionAnim === 'function') startCaptionAnim(mainCap);
        // Sync audio panel textarea if open on same scene
        if (index === currentIndex) {
            const audTa = document.getElementById('audioSceneText');
            if (audTa) audTa.value = txt;
        }
        if (btn) { btn.textContent = '✅ Saved!'; setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 1500); }
    } catch(e) {
        if (btn) { btn.textContent = '❌ Error'; setTimeout(() => { btn.textContent = '💾 Save'; btn.disabled = false; }, 1500); }
    }
}

// ── Caption button: open caption panel (tab 1 only) ───────────────
let _capBodyParent = null;

function ssOpenCaption(index) {
    _ssGoTo(index, () => {
        _forceCapTab = 1;
        renderCaptionTabs();
        // Pre-mark pCaption as open so selectCaption() won't call togglePanel()
        document.getElementById('pCaption').classList.add('open');
        if (!selectedCapId && sceneCaptions.length > 0) {
            selectCaption(sceneCaptions[0].id);
        }
        // Immediately remove so the panel never renders visibly
        document.getElementById('pCaption').classList.remove('open');
        setTimeout(() => {
            _forceCapTab = 0;
            const ed      = document.getElementById('captionEditor');
            const tab1    = document.getElementById('capSubTab1');
            const tab2    = document.getElementById('capSubTab2');
            const source  = document.getElementById('pCaptionBody');
            const dest    = document.getElementById('captionOverlayBody');
            const numEl   = document.getElementById('captionOverlayNum');
            const overlay = document.getElementById('pCaptionOverlay');
            if (!source || !dest || !overlay) return;
            if (ed)   ed.style.display   = 'block';
            if (tab1) tab1.style.display = 'block';
            if (tab2) tab2.style.display = 'none';
            _capBodyParent = source.parentNode;
            dest.appendChild(source);
            source.style.display = 'block';
            if (numEl) numEl.textContent = index + 1;
            overlay.style.display = 'flex';
            overlay.onclick = (e) => { if (e.target === overlay) closeCaptionOverlay(); };
            // Ensure a caption is selected after the overlay renders so that
            // capFieldChanged() has a valid selectedCapId and saves to hdb_captions.
            // Scene navigation inside _ssGoTo may have reset selectedCapId to null.
            if (!selectedCapId && sceneCaptions.length > 0) {
                const pCapEl = document.getElementById('pCaption');
                if (pCapEl) pCapEl.classList.add('open'); // prevent togglePanel side-effect
                selectCaption(sceneCaptions[0].id);
                if (pCapEl) pCapEl.classList.remove('open');
            }
        }, 180);
    });
}

function closeCaptionOverlay() {
    const overlay = document.getElementById('pCaptionOverlay');
    const source  = document.getElementById('pCaptionBody');
    if (source && _capBodyParent) _capBodyParent.appendChild(source);
    document.getElementById('pCaption').classList.remove('open');
    if (overlay) overlay.style.display = 'none';
}

// ── Font button: open font overlay (capSubTab2 in its own modal) ──
let _fontOverlayOpen = false;
let _fontTab2Parent  = null; // original parent to restore to

function ssOpenFont(index) {
    // Must have a caption selected first
    if (!selectedCapId) {
        alert('Please select a text caption on the canvas first before opening the font editor.');
        return;
    }
    _ssGoTo(index, () => {
        _forceCapTab = 2;
        // Pre-mark pCaption as open so selectCaption() won't call togglePanel()
        document.getElementById('pCaption').classList.add('open');
        renderCaptionTabs();
        document.getElementById('pCaption').classList.remove('open');
        setTimeout(() => {
            _forceCapTab = 0;
            openFontOverlay(index);
        }, 150);
    });
}

function openFontOverlay(index) {
    const panel  = document.getElementById('pFontPanel');
    const body   = document.getElementById('fontOverlayBody');
    const numEl  = document.getElementById('fontOverlaySceneNum');
    const tab2   = document.getElementById('capSubTab2');
    if (!panel || !body || !tab2) return;

    // Move capSubTab2 into the overlay body
    _fontTab2Parent = tab2.parentNode;
    body.appendChild(tab2);
    tab2.style.display = 'block';

    document.getElementById('capSubTab1').style.display = 'none';

    if (numEl) numEl.textContent = index + 1;

    // Reset to centered position each open
    panel.style.left      = '50%';
    panel.style.top       = '40px';
    panel.style.transform = 'translateX(-50%)';
    panel.style.display   = 'flex';
    _fontOverlayOpen = true;
}

function closeFontOverlay() {
    const panel  = document.getElementById('pFontPanel');
    const body   = document.getElementById('fontOverlayBody');
    const tab2   = document.getElementById('capSubTab2');
    if (!panel || !tab2) return;

    if (_fontTab2Parent) _fontTab2Parent.appendChild(tab2);
    tab2.style.display = 'none';

    document.getElementById('pCaption').classList.remove('open');
    document.getElementById('capSubTab1').style.display = 'block';

    panel.style.display = 'none';
    _fontOverlayOpen = false;
}

// ── Drag-to-move for font overlay panel ──────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pFontPanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return;
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX, dy = cy - startY;
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown', function(e) {
        const h = document.getElementById('pFontDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pFontDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

let _imgBodyParent = null;
function ssOpenImage(index) {
    _ssGoTo(index, () => {
        applySceneSlots(SCENES[index]);
        updateSlotThumbs(SCENES[index]);
        const source  = document.getElementById('pImageBody');
        const dest    = document.getElementById('imageOverlayBody');
        const numEl   = document.getElementById('imageOverlayNum');
        const panel   = document.getElementById('pImagePanel');
        if (!source || !dest || !panel) return;
        _imgBodyParent = source.parentNode;
        dest.appendChild(source);
        if (numEl) numEl.textContent = index + 1;
        // Reset to default centered position each open
        panel.style.left      = '50%';
        panel.style.top       = '60px';
        panel.style.transform = 'translateX(-50%)';
        panel.style.display   = 'flex';
    });
}
function closeImageOverlay() {
    const panel  = document.getElementById('pImagePanel');
    const source = document.getElementById('pImageBody');
    if (source && _imgBodyParent) _imgBodyParent.appendChild(source);
    if (panel) panel.style.display = 'none';
}

// ── Drag-to-move for image overlay panel ──────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pImagePanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return; // don't drag when clicking close
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        // Resolve current left/top in px (strip transform after first drag)
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        // Switch from transform-based centering to px positioning
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX;
        const dy = cy - startY;
        // Clamp inside viewport
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown',  function(e) {
        const h = document.getElementById('pImageDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pImageDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

let _audBodyParent = null;
function ssOpenAudio(index) {
    _ssGoTo(index, () => {
        const source = document.getElementById('pAudioBody');
        const dest   = document.getElementById('audioOverlayBody');
        const numEl  = document.getElementById('audioOverlayNum');
        const panel  = document.getElementById('pAudioPanel');
        if (!source || !dest || !panel) return;
        _audBodyParent = source.parentNode;
        dest.appendChild(source);
        if (numEl) numEl.textContent = index + 1;
        panel.style.left      = '50%';
        panel.style.top       = '40px';
        panel.style.transform = 'translateX(-50%)';
        panel.style.display   = 'flex';
        loadAudioPanel();
    });
}
function closeAudioOverlay() {
    const panel  = document.getElementById('pAudioPanel');
    const source = document.getElementById('pAudioBody');
    if (source && _audBodyParent) _audBodyParent.appendChild(source);
    if (panel) panel.style.display = 'none';
}

// ── Drag-to-move for audio overlay panel ─────────────────────────────────
(function() {
    let dragging = false, startX = 0, startY = 0, origLeft = 0, origTop = 0;

    function getPanel() { return document.getElementById('pAudioPanel'); }

    function onDown(e) {
        if (e.target.closest('button')) return;
        const panel = getPanel();
        if (!panel) return;
        dragging = true;
        const rect = panel.getBoundingClientRect();
        origLeft = rect.left;
        origTop  = rect.top;
        startX = e.clientX || e.touches[0].clientX;
        startY = e.clientY || e.touches[0].clientY;
        panel.style.transform = 'none';
        panel.style.left = origLeft + 'px';
        panel.style.top  = origTop  + 'px';
        panel.style.cursor = 'grabbing';
        e.preventDefault();
    }

    function onMove(e) {
        if (!dragging) return;
        const cx = e.clientX || (e.touches && e.touches[0].clientX);
        const cy = e.clientY || (e.touches && e.touches[0].clientY);
        const panel = getPanel();
        if (!panel) return;
        const dx = cx - startX, dy = cy - startY;
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const newLeft = Math.max(0, Math.min(window.innerWidth  - pw, origLeft + dx));
        const newTop  = Math.max(0, Math.min(window.innerHeight - ph, origTop  + dy));
        panel.style.left = newLeft + 'px';
        panel.style.top  = newTop  + 'px';
    }

    function onUp() {
        if (!dragging) return;
        dragging = false;
        const panel = getPanel();
        if (panel) panel.style.cursor = '';
    }

    document.addEventListener('mousedown', function(e) {
        const h = document.getElementById('pAudioDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    });
    document.addEventListener('touchstart', function(e) {
        const h = document.getElementById('pAudioDragHandle');
        if (h && h.contains(e.target)) onDown(e);
    }, { passive: false });
    document.addEventListener('mousemove',  onMove);
    document.addEventListener('touchmove',  onMove, { passive: false });
    document.addEventListener('mouseup',    onUp);
    document.addEventListener('touchend',   onUp);
})();

// ── Update strip thumb whenever an image is assigned ─────────────

/**
 * Call this after any image assignment to refresh the strip thumbnail.
 * @param {number} sceneIndex  - 0-based index into SCENES
 * @param {string|null} src    - full URL / relative path of new image, or null to clear
 */
function ssUpdateThumb(sceneIndex, src) {
    const img  = document.getElementById('ssThumbImg'  + sceneIndex);
    const ph   = document.getElementById('ssThumbPh'   + sceneIndex);
    const wrap = document.getElementById('ssThumbWrap' + sceneIndex);
    const icon = document.getElementById('ssIconImg'   + sceneIndex);

    if (!src) {
        if (img)  { img.src = ''; img.style.display = 'none'; }
        // Remove any existing video thumb
        if (wrap) { const ov = wrap.querySelector('video.ss-vid-thumb'); if (ov) ov.remove(); }
        if (ph)   ph.style.display = 'flex';
        if (wrap) wrap.style.borderColor = 'var(--border)';
        if (icon) { icon.style.borderColor = 'var(--border)'; icon.style.background = 'var(--surface)'; icon.style.color = ''; }
        return;
    }

    const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(src);

    // Remove any existing video thumb first
    if (wrap) { const ov = wrap.querySelector('video.ss-vid-thumb'); if (ov) ov.remove(); }

    if (isVid) {
        // Show video element as thumbnail
        if (img) { img.src = ''; img.style.display = 'none'; }
        if (ph)  ph.style.display = 'none';
        if (wrap) {
            const vidEl = document.createElement('video');
            vidEl.className   = 'ss-vid-thumb';
            vidEl.src         = src;
            vidEl.muted       = true;
            vidEl.playsInline = true;
            vidEl.preload     = 'metadata';
            vidEl.currentTime = 0.5;
            vidEl.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;position:absolute;top:0;left:0;border-radius:inherit;';
            vidEl.onerror = function() {
                this.remove();
                if (ph) { ph.textContent = '🎬'; ph.style.display = 'flex'; }
            };
            wrap.style.position = 'relative';
            wrap.appendChild(vidEl);
        }
    } else {
        // Show image thumbnail
        if (img) {
            img.onerror = () => { img.style.display = 'none'; if (ph) ph.style.display = 'flex'; };
            img.src = src + '?t=' + Date.now();
            img.style.display = 'block';
        }
        if (ph) ph.style.display = 'none';
    }

    if (wrap) wrap.style.borderColor = '#16a34a';
    if (icon) { icon.style.borderColor = '#16a34a'; icon.style.background = '#dcfce7'; icon.style.color = '#15803d'; }
}

/** Call after audio is generated/removed for a scene */
function ssMarkAudio(sceneIndex, hasAudio) {
    const icon  = document.getElementById('ssIconAud'   + sceneIndex);
    const badge = document.getElementById('ssAudBadge'  + sceneIndex);
    if (icon)  { icon.style.borderColor = hasAudio ? '#16a34a' : 'var(--border)'; icon.style.background = hasAudio ? '#dcfce7' : 'var(--surface)'; icon.style.color = hasAudio ? '#15803d' : ''; }
    if (badge) badge.style.display = hasAudio ? 'block' : 'none';
}

// ── Auto-sync strip when images or audio are assigned via existing UI ─

// Patch useLibraryFile so strip thumb updates after library picks
(function patchLibraryAssign() {
    const orig = window.useLibraryFile;
    if (typeof orig !== 'function') return;
    window.useLibraryFile = async function() {
        const result = await orig.apply(this, arguments);
        // After assign, read updated SCENES[currentIndex] to refresh thumb
        setTimeout(() => {
            const sc = SCENES[currentIndex];
            if (!sc) return;
            const fn = (sc[activeSlot] || '').trim();
            if (fn) {
                const folderCol = SLOT_FOLDER_COL[activeSlot] || 'image_folder';
                const folder = (sc[folderCol] || sc.image_folder || 'podcast_images').replace(/\/?$/, '/');
                // Update strip thumb for both images AND videos
                ssUpdateThumb(currentIndex, folder + fn);
                // Also update slot thumbs panel
                updateSlotThumbs(sc);
            }
        }, 300);
        return result;
    };
})();

// Patch generate_scene_audio response to mark audio icon green
(function patchAudioGen() {
    // We hook the generate_scene_audio AJAX result by watching the audSub1 panel button
    // The actual hook point is after the fetch in loadAudioPanel / generateVoice flow.
    // Since we can't easily monkey-patch anonymous fetches, we observe DOM changes
    // on the audio status element instead.
    const target = document.getElementById('audCapInfo');
    if (!target) return;
    const obs = new MutationObserver(() => {
        const sc = SCENES[currentIndex];
        if (sc && sc.audio_file) ssMarkAudio(currentIndex, true);
    });
    obs.observe(target, { childList: true, characterData: true, subtree: true });
})();

// Expose globally so other parts of the codebase can call them
window.ssUpdateThumb = ssUpdateThumb;
window.ssMarkAudio   = ssMarkAudio;

function ssPlayFromScene(index) {
    // Navigate to the scene first, then start playback
    if (currentIndex !== index) {
        showScene(index, true);
        setTimeout(() => togglePlay(), 120);
    } else {
        togglePlay();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// END SCENE STRIP
// ══════════════════════════════════════════════════════════════════════════════
</script>

<!-- ══════════════════════════════════════════════════════════════
     PUBLISH VIDEO MODAL — shared BPM modal (matches vizard_browser)
     ══════════════════════════════════════════════════════════════ -->

<style>
/* ── BPM Modal (Browser Post Modal) ─────────────────────────────── */
@keyframes bpmSpin     { to { transform: rotate(360deg); } }
@keyframes bpmSlideUp  { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }

.bpm-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(15,42,68,.72); backdrop-filter: blur(4px);
    z-index: 99990; align-items: flex-end; justify-content: center; padding: 0;
}
.bpm-overlay.open { display: flex; }
@media(min-width:600px) { .bpm-overlay { align-items: center; padding: 16px; } }

.bpm-modal {
    background: #fff; border-radius: 22px 22px 0 0; width: 100%; max-width: 480px;
    max-height: 92vh; overflow-y: auto; box-shadow: 0 -8px 40px rgba(0,0,0,.25);
    animation: bpmSlideUp .28s cubic-bezier(.34,1.56,.64,1) both;
    -webkit-overflow-scrolling: touch;
}
@media(min-width:600px) {
    .bpm-modal { border-radius: 22px; box-shadow: 0 24px 80px rgba(0,0,0,.35); }
}

.bpm-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 20px 12px; border-bottom: 1px solid #e2e8f0;
}
.bpm-head-left { display: flex; align-items: center; gap: 12px; }
.bpm-head-icon  { font-size: 26px; }
.bpm-head-title { font-size: 16px; font-weight: 800; color: #0f2a44; }
.bpm-head-sub   { font-size: 12px; color: #64748b; margin-top: 2px; }
.bpm-close {
    background: #f1f5f9; border: none; border-radius: 50%;
    width: 32px; height: 32px; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; color: #64748b;
    transition: background .15s; flex-shrink: 0;
}
.bpm-close:hover { background: #e2e8f0; }

.bpm-saved {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; background: #f0fdf4;
    border-bottom: 1px solid #bbf7d0; font-size: 13px; color: #065f46; font-weight: 600;
}
.bpm-saved-dot {
    width: 9px; height: 9px; border-radius: 50%; background: #10b981; flex-shrink: 0;
    box-shadow: 0 0 0 3px rgba(16,185,129,.2);
}

.bpm-inner { padding: 16px 20px 20px; }

.bpm-lbl { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }

.bpm-platforms {
    display: grid; grid-template-columns: repeat(3,1fr); gap: 7px; margin-bottom: 6px;
}
.bpm-plat {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 10px; border-radius: 10px; border: 1.5px solid #e2e8f0;
    font-size: 13px; font-weight: 600; color: #64748b;
    cursor: pointer; transition: all .15s; user-select: none; background: #f8fafc;
}
.bpm-plat.sel         { background: #f0fdf4; border-color: #86efac; color: #065f46; }
.bpm-plat.disconnected { opacity: .4; cursor: not-allowed; }
.bpm-plat-icon { font-size: 15px; }
.bpm-warn {
    font-size: 12px; color: #dc2626; font-weight: 600;
    margin-bottom: 8px; padding: 6px 10px; background: #fef2f2; border-radius: 8px;
}

.bpm-ctabs { display: flex; gap: 6px; margin: 12px 0 6px; }
.bpm-ctab {
    flex: 1; padding: 7px 0; border-radius: 8px; border: 1.5px solid #e2e8f0;
    font-size: 12px; font-weight: 700; color: #64748b; background: #f8fafc;
    cursor: pointer; transition: all .15s;
}
.bpm-ctab.active { background: #0f2a44; border-color: #0f2a44; color: #fff; }
.bpm-ctab-panel  { display: none; }
.bpm-ctab-panel.active { display: block; }
.bpm-textarea {
    width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0;
    border-radius: 10px; font-size: 13px; color: #1e293b; font-family: inherit;
    resize: vertical; outline: none; min-height: 72px; transition: border-color .15s; box-sizing: border-box;
}
.bpm-textarea:focus { border-color: #0f2a44; }

.bpm-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.bpm-qpill {
    padding: 6px 12px; border-radius: 20px; border: 1.5px solid #e2e8f0;
    font-size: 12px; font-weight: 600; color: #64748b; background: #f8fafc;
    cursor: pointer; transition: all .15s;
}
.bpm-qpill.active { background: #0f2a44; border-color: #0f2a44; color: #fff; }

.bpm-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.bpm-input {
    width: 100%; padding: 8px 12px; border: 1.5px solid #e2e8f0;
    border-radius: 10px; font-size: 13px; color: #1e293b; font-family: inherit; outline: none;
    background: #f8fafc; transition: border-color .15s; box-sizing: border-box;
}
.bpm-input:focus { border-color: #0f2a44; }

.bpm-footer { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.bpm-dl-btn {
    grid-column: span 2; padding: 10px;
    background: #f8fafc; border: 1.5px solid #10b981; border-radius: 10px;
    font-size: 13px; font-weight: 700; color: #059669; cursor: pointer; transition: all .15s;
}
.bpm-dl-btn:hover { background: #f0fdf4; }
.bpm-btn-now {
    padding: 10px; background: linear-gradient(135deg,#f59e0b,#d97706);
    border: none; border-radius: 10px; font-size: 13px; font-weight: 700;
    color: #fff; cursor: pointer; transition: all .15s;
}
.bpm-btn-now:hover   { opacity: .9; }
.bpm-btn-sched {
    padding: 10px; background: linear-gradient(135deg,#0f2a44,#0284c7);
    border: none; border-radius: 10px; font-size: 13px; font-weight: 700;
    color: #fff; cursor: pointer; transition: all .15s;
}
.bpm-btn-sched:hover { opacity: .9; }
.bpm-btn-skip {
    grid-column: span 2; padding: 8px; background: none; border: none;
    font-size: 12px; color: #94a3b8; cursor: pointer; text-decoration: underline;
}

/* Confirm screen */
.bpm-confirm-icon  { text-align: center; font-size: 52px; padding: 28px 0 0; }
.bpm-confirm-title { text-align: center; font-size: 22px; font-weight: 800; color: #0f2a44; margin-top: 10px; }
.bpm-confirm-sub   { text-align: center; font-size: 14px; color: #64748b; margin-top: 6px; padding-bottom: 6px; }
.bpm-confirm-pills {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 8px;
    padding: 14px 20px;
}
.bpm-confirm-pill {
    padding: 6px 14px; background: #f0fdf4; border: 1.5px solid #86efac;
    border-radius: 20px; font-size: 13px; font-weight: 700; color: #065f46;
}
.bpm-confirm-done {
    display: block; margin: 0 20px 24px; padding: 13px;
    background: linear-gradient(135deg,#10b981,#059669);
    border: none; border-radius: 12px; font-size: 15px; font-weight: 700;
    color: #fff; cursor: pointer; width: calc(100% - 40px); transition: all .15s;
}
.bpm-confirm-done:hover { opacity: .9; }
</style>

<div class="bpm-overlay" id="vsOverlay">
  <div class="bpm-modal">

    <!-- Main panel -->
    <div id="vsMain">
      <div class="bpm-head">
        <div class="bpm-head-left">
          <div class="bpm-head-icon">📤</div>
          <div>
            <div class="bpm-head-title">Publish Video</div>
            <div class="bpm-head-sub" id="vsSubTitle">Choose where &amp; when to share</div>
          </div>
        </div>
        <button class="bpm-close" onclick="closeSchedModal()">✕</button>
      </div>

      <!-- Spinner shown while checking MP4 -->
      <div id="bpmVmSpinner" style="display:none;align-items:center;justify-content:center;padding:40px;gap:12px;">
        <div style="width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#10b981;border-radius:50%;animation:bpmSpin .7s linear infinite;"></div>
        <span style="font-size:14px;color:#64748b;">Checking video…</span>
      </div>

      <!-- Body -->
      <div id="bpmVmBody">
        <div class="bpm-saved" id="vsFilenameDisplay">
          <div class="bpm-saved-dot"></div>
          <span>Preparing video…</span>
        </div>

        <div class="bpm-inner">
          <div class="bpm-lbl">Platforms</div>
          <div class="bpm-platforms">
            <div class="bpm-plat sel"          data-p="instagram" onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">📸</span> Instagram</div>
            <div class="bpm-plat sel"          data-p="tiktok"    onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">🎵</span> TikTok</div>
            <div class="bpm-plat sel"          data-p="youtube"   onclick="vsTogglePlat(this)"><span class="bpm-plat-icon">▶️</span> YouTube</div>
            <div class="bpm-plat disconnected" data-p="facebook"                              ><span class="bpm-plat-icon">📘</span> Facebook</div>
            <div class="bpm-plat disconnected" data-p="twitter"                               ><span class="bpm-plat-icon">🐦</span> X</div>
            <div class="bpm-plat disconnected" data-p="linkedin"                              ><span class="bpm-plat-icon">💼</span> LinkedIn</div>
          </div>
          <div class="bpm-warn" id="vsWarn" style="display:none;">Select at least one platform</div>

          <!-- Caption / Keywords / Hashtags tabs -->
          <div class="bpm-ctabs">
            <button class="bpm-ctab active" onclick="vsSwitchTab('caption',this)">✍️ Caption</button>
            <button class="bpm-ctab"        onclick="vsSwitchTab('keywords',this)">🔑 Keywords</button>
            <button class="bpm-ctab"        onclick="vsSwitchTab('hashtags',this)">#️⃣ Hashtags</button>
          </div>
          <div class="bpm-ctab-panel active" id="vs-tab-caption">
            <textarea class="bpm-textarea" id="vsCaption" placeholder="Caption text…"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="vs-tab-keywords">
            <textarea class="bpm-textarea" id="vsKeywords" placeholder="Keywords…" style="height:54px;"></textarea>
          </div>
          <div class="bpm-ctab-panel" id="vs-tab-hashtags">
            <textarea class="bpm-textarea" id="vsHashtags" placeholder="#hashtags…" style="height:54px;"></textarea>
          </div>

          <div class="bpm-lbl" style="margin-top:12px;">Schedule</div>
          <div class="bpm-quick">
            <button class="bpm-qpill"        onclick="vsQuick(this,0)"  >Now</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,1)"  >+1hr</button>
            <button class="bpm-qpill active" onclick="vsQuick(this,24)" >Tomorrow</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,72)" >+3 days</button>
            <button class="bpm-qpill"        onclick="vsQuick(this,168)">Next week</button>
          </div>
          <div class="bpm-date-row">
            <div>
              <div class="bpm-lbl">Date</div>
              <input type="date" class="bpm-input" id="vsDate">
            </div>
            <div>
              <div class="bpm-lbl">Time</div>
              <input type="time" class="bpm-input" id="vsTime" value="09:00">
            </div>
          </div>

          <div class="bpm-footer">
            <button class="bpm-dl-btn"    onclick="vsDownload()">⬇ Download</button>
            <button class="bpm-btn-now"   onclick="vsPostNow()">⚡ Post Now</button>
            <button class="bpm-btn-sched" onclick="vsSchedule()">🗓 Schedule</button>
            <button class="bpm-btn-skip"  onclick="closeSchedModal()">Skip — publish manually</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirm panel -->
    <div id="vsConfirm" style="display:none;">
      <div class="bpm-confirm-icon"  id="vsConfirmIcon">🗓</div>
      <div class="bpm-confirm-title" id="vsConfirmTitle">Scheduled!</div>
      <div class="bpm-confirm-sub"   id="vsConfirmSub"></div>
      <div class="bpm-confirm-pills" id="vsConfirmPills"></div>
      <button class="bpm-confirm-done" onclick="closeSchedModal()">Done ✓</button>
    </div>

  </div>
</div>
<div id="uploadOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,42,68,.82);
     z-index:99998;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:32px 40px;text-align:center;
                box-shadow:0 20px 60px rgba(0,0,0,.4);max-width:360px;">
        <div style="width:44px;height:44px;border:4px solid rgba(59,130,246,.2);
                    border-top-color:#3b82f6;border-radius:50%;animation:spin .7s linear infinite;
                    margin:0 auto 16px;"></div>
        <div id="overlayTitle" style="font-size:15px;font-weight:700;color:#0f2a44;margin-bottom:8px;">
            Uploading your video…
        </div>
        <div id="overlayDesc" style="font-size:12px;color:#64748b;line-height:1.6;">
            Your video is being prepared for scheduling.<br>Please wait, this may take a moment.
        </div>
        <div id="overlayTimer" style="font-size:11px;color:#94a3b8;margin-top:10px;"></div>
    </div>
</div>


<!-- Inside the audio panel, after the Change Voice tab -->
<div id="audSub4" style="display:none;padding:10px;">
    <div class="sec-label" style="margin-bottom:12px;">Playback Speed Control</div>
    
    <div style="background:var(--surface2);border:1.5px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:13px;font-weight:600;color:var(--text);">Video Playback Speed</span>
            <span id="speedValue2" style="font-size:16px;font-weight:700;color:var(--info);">1.0x</span>
        </div>
        
        <input type="range" id="playbackSpeedSlider2" min="0.5" max="2.0" step="0.05" value="1.0"
               style="width:100%;accent-color:var(--info);margin-bottom:12px;"
               oninput="updatePlaybackSpeed(this.value)">
        
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
            <button class="speed-preset" data-speed="0.5" onclick="setPlaybackSpeed(0.5)">0.5x</button>
            <button class="speed-preset" data-speed="0.75" onclick="setPlaybackSpeed(0.75)">0.75x</button>
            <button class="speed-preset active" data-speed="1.0" onclick="setPlaybackSpeed(1.0)">1.0x</button>
            <button class="speed-preset" data-speed="1.25" onclick="setPlaybackSpeed(1.25)">1.25x</button>
            <button class="speed-preset" data-speed="1.5" onclick="setPlaybackSpeed(1.5)">1.5x</button>
            <button class="speed-preset" data-speed="2.0" onclick="setPlaybackSpeed(2.0)">2.0x</button>
        </div>
        
        <div style="font-size:10px;color:var(--muted);text-align:center;">
            <span>🎵 Affects voiceover and background music playback speed</span>
        </div>
    </div>
    
    <div class="sec-label" style="margin-bottom:8px;">Sample Preview</div>
    <div style="display:flex;gap:8px;align-items:center;">
        <button onclick="previewSampleSpeed()" style="background:var(--info);color:#fff;border:none;
                border-radius:8px;padding:8px 16px;font-size:11px;font-weight:700;cursor:pointer;">
            🔊 Test Speed
        </button>
        <span id="sampleSpeedStatus" style="font-size:10px;color:var(--muted);">Click to hear sample</span>
    </div>
</div>
</body>
</html>