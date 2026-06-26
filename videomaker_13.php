<?php
// 1. Database & Config

session_start();


//$_SESSION['admin_id'] = NULL;
$admin_id    = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];
$client_id   = $_SESSION['client_id'];

if(!isset($_SESSION['admin_id']))
{
	//echo "session came  ".$_SESSION['admin_id'];die;
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); // change if your login file is named differently 
    exit;
}


$admin_id = $_SESSION['admin_id'];
$admin_level = $_SESSION['level'];

include 'dbconnect_hdb.php'; 
$podcast_id    = $_GET['podcast_id'] ?? 0;
$lang_code     = $_GET['lang_filter'] ?? 'en';

// Fetch podcast title from DB
if ($podcast_id > 0) {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM hdb_podcasts WHERE id = " . (int)$podcast_id));
    $podcast_title = $row['title'] ?? '';
} else {
    $podcast_title = '';
}
// --- AJAX HANDLER FOR DELETING ROW ---
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_scene_row') {
    $id = (int)$_POST['id'];
    $delete_query = "DELETE FROM hdb_podcast_stories WHERE id = $id";
    
    if (mysqli_query($conn, $delete_query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_image') {
    require_once 'generate_image_api.php'; 
    
    $id = (int)$_POST['id'];
    $prompt = $_POST['prompt'];
    $apiKey = "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";
    
    $imageName = "scene_img_" . $id . "_" . time();
    $result = generateAndSaveImage($prompt, $imageName, "1024x1792", "podcast_images", $apiKey);

    if ($result['success']) {
        $filename = basename($result['filepath']);
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET image_file = '$filename' WHERE id = $id");
        echo json_encode(['success' => true, 'filepath' => 'podcast_images/' . $filename]);
    } else {
        echo json_encode($result);
    }
    exit; 
}

// Full Expanded Voice List
$voices = [
    '🇺🇸 English (US)' => [
        'en-US-GuyNeural' => 'Guy - Calm, Steady Male ⭐ (TOP CHOICE)',
        'en-US-DavisNeural' => 'Davis - Deep, Soothing Male ⭐',
        'en-US-SaraNeural' => 'Sara - Empathetic, Warm Female ⭐',
        'en-US-JennyNeural' => 'Jenny - Soft, Gentle Female',
    ],
    '🇮🇳 Hindi' => [
        'hi-IN-SwaraNeural' => 'Swara - Female, Warm & Calming ⭐',
        'hi-IN-MadhurNeural' => 'Madhur - Male, Soothing & Confident ⭐',
    ],
    '🇵🇰 Urdu' => [
        'ur-PK-UzmaNeural' => 'Uzma - Female, Gentle & Empathetic ⭐',
        'ur-PK-AsadNeural' => 'Asad - Male, Calm & Steady ⭐',
    ],
    '🇸🇦 / 🇪🇬 Arabic' => [
        'ar-SA-ZariyahNeural' => 'Zariyah - Female, Clear & Relaxing ⭐',
        'ar-SA-HamedNeural' => 'Hamed - Male, Calm & Authoritative ⭐',
        'ar-EG-SalmaNeural' => 'Salma - Female, Soothing',
    ],
    '🇪🇸 Spanish' => [
        'es-ES-ElviraNeural' => 'Elvira - Female, Spain ⭐',
        'es-ES-AlvaroNeural' => 'Alvaro - Male, Spain ⭐',
        'es-MX-DaliaNeural' => 'Dalia - Female, Mexico ⭐',
        'es-MX-JorgeNeural' => 'Jorge - Male, Mexico ⭐',
    ],
    '🇫🇷 French' => [
        'fr-FR-DeniseNeural' => 'Denise - Female, France ⭐',
        'fr-FR-HenriNeural' => 'Henri - Male, France ⭐',
        'fr-CA-SylvieNeural' => 'Sylvie - Female, Canada ⭐',
        'fr-CA-JeanNeural' => 'Jean - Male, Canada ⭐',
    ],
];

$lang_codes = [
    'en' => 'English',
    'ur' => 'Urdu',
    'ar' => 'Arabic',
    'hi' => 'Hindi',
    'es' => 'Spanish',
    'fr' => 'French',
];

$selected_lang = isset($_GET['lang_filter']) ? mysqli_real_escape_string($conn, $_GET['lang_filter']) : 'en';

// Assuming you have session started and session_admin_id & admin_level set
$session_admin_id = $_SESSION['admin_id'];
$admin_level      = $_SESSION['level'];

// Base query
$title_query = "SELECT * FROM hdb_podcasts WHERE  client_id = '$client_id' and lang_code = '$selected_lang' AND (video_status = '' OR video_status = '0' OR video_status IS NULL)";

// Add condition for operator
/*
if ($admin_level === 'operator') {
    $title_query .= " AND admin_id = '$session_admin_id'";
}
*/
// Add ordering
$title_query .= " ORDER BY title";

// Execute query
$title_result = mysqli_query($conn, $title_query);


if (isset($_GET['podcast_id']) && $_GET['podcast_id'] != '') {
    $selected_podcast_id = mysqli_real_escape_string($conn, $_GET['podcast_id']);
} else {
    if ($title_result && mysqli_num_rows($title_result) > 0) {
        $first_row = mysqli_fetch_assoc($title_result);
        $selected_podcast_id = $first_row['id'];
        mysqli_data_seek($title_result, 0);
    } else {
        $selected_podcast_id = '';
    }
}

$result = null;
if ($selected_podcast_id != '') {
    $sql = "SELECT * from hdb_podcast_stories WHERE podcast_id = '$selected_podcast_id' order by id";
    $result = mysqli_query($conn, $sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CalmBot Videomaker Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="videomaker_jslib.js?v=<?= time(); ?>"></script>
	<script src="media_library.js"></script>
	<? /*
	js_logo_code.js"></script>
	<script src="js_misc_code.js"></script>
	<script src="js_video_code.js"></script>  
	<script src="js_row_edit.js"></script> */ ?>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; padding: 20px; background: #f0f2f5; color: #333; }
        .dashboard-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e0e0e0; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .flex-row { display: flex; gap: 25px; align-items: flex-start; }
        .controls { flex: 3; }
        .monitor { flex: 2; background: #fafafa; border: 1px solid #eee; padding: 15px; border-radius: 8px; text-align: center; position: sticky; top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #fff; border-radius: 8px; overflow: hidden; }
        th, td { border: 1px solid #f0f0f0; padding: 10px; text-align: left; vertical-align: middle; }
        th { background: #f8f9fa; font-weight: 600; color: #666; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .script-input { width: 100%; min-height: 45px; font-family: inherit; font-size: 11px; border: 1px solid #ddd; padding: 8px; border-radius: 4px; resize: vertical; transition: border 0.2s; }
        .script-input:focus { border-color: #2563eb; outline: none; }
        .btn { border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: 600; color: white; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-blue { background: #2563eb; }
        .btn-green { background: #059669; }
        .btn-red { background: #dc2626; }
        .btn-purple { background: #7c3aed; }
        .btn:disabled { background: #cbd5e1; cursor: not-allowed; }
        .status-badge { font-size: 10px; padding: 3px 8px; border-radius: 12px; background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .ready { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .video-container { background:#000; width:100%; aspect-ratio:16/9; margin-bottom:12px; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; }
        select { padding: 6px; border-radius: 4px; border: 1px solid #ddd; font-size: 11px; background: #fff; }
        .row-active { background-color: #eff6ff !important; border-left: 3px solid #2563eb; }
        #duration-prediction { background: #eef2ff; padding: 5px 10px; border-radius: 5px; border-left: 3px solid #2563eb; }
        
        #typewriter-container { position: absolute; bottom: 15%; left: 5%; width: 90%; height: 100px; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 10; }
        #typewriter-text { color: white; font-family: Arial, sans-serif; font-size: 28px; font-weight: 800; text-align: center; text-shadow: 0px 2px 10px rgba(0,0,0,1), 0px 0px 5px rgba(0,0,0,0.5); line-height: 1.2; display: inline-block; transition: transform 0.1s linear !important; white-space: pre-wrap; width: 100%; opacity: 1; }
        .text-dimmed { opacity: 0 !important; transform: scale(0.95); }
        #videoPreview, #imagePreview { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background: #fff; margin: 5% auto; padding: 25px; border-radius: 12px; width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 5px; color: #475569; }
        .form-group input[type="text"], .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .preview-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; margin-top: 5px; }
        .ui-row { display: flex; gap: 15px; align-items: flex-end; margin-bottom: 15px; flex-wrap: wrap; }
        
        /* Logo Controls Styling */
        .logo-controls-card { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 12px 15px; border-radius: 8px; margin-bottom: 15px; }
        .logo-controls-card label.section-label { font-size: 10px; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; }
    
		.vidora-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 14px 24px;
			background: linear-gradient(90deg, #0f2a44, #143b63);
			color: #fff;
			border-radius: 10px;
			box-shadow: 0 3px 10px rgba(0,0,0,0.15);
			margin-bottom: 25px;
			font-family: "Segoe UI", sans-serif;
		}

		/* Brand */

		.brand {
			font-size: 22px;
			font-weight: 600;
			display: flex;
			align-items: baseline;
			gap: 8px;
		}

		.brand span {
			color: #5fd1ff;
		}

		.brand small {
			font-size: 12px;
			color: #cde9ff;
			font-weight: 400;
		}

		/* Navigation */

		.vidora-nav {
			display: flex;
			gap: 18px;
		}

		.vidora-nav a {
			text-decoration: none;
			color: #fff;
			font-size: 15px;
			padding: 7px 14px;
			border-radius: 6px;
			transition: all 0.25s ease;
		}

		.vidora-nav a:hover {
			background: rgba(255,255,255,0.15);
		}

		.vidora-nav a.active {
			background: #5fd1ff;
			color: #0f2a44;
			font-weight: 600;
		}
		/* Prevent event bubbling issues */
		.media-item {
			cursor: pointer;
			user-select: none;
		}

		.media-item .use-media-btn {
			pointer-events: auto;
			z-index: 10;
		}

		.selection-status {
			pointer-events: auto;
		}

		/* Ensure buttons are clickable */
		.selection-status button {
			cursor: pointer;
			padding: 6px 15px;
			background: #1e3a8a;
			color: white;
			border: none;
			border-radius: 4px;
			font-weight: 600;
		}

		.selection-status button:hover {
			background: #2563eb;
		}
	
		/* panning and zooming */
		/* Ken Burns Effect Styles */
		.canvas-container {
			width: 360px;
			height: 640px;
			overflow: hidden;
			position: relative;
			background: #000;
			border-radius: 12px;
			box-shadow: 0 10px 25px rgba(0,0,0,0.2);
		}

		.ken-burns-active {
			width: 100%;
			height: 100%;
			object-fit: cover;
			animation: kenburns-effect 12s infinite alternate ease-in-out;
		}

		@keyframes kenburns-effect {
			0% {
				transform: scale(1.0) translate(0, 0);
			}
			100% {
				transform: scale(1.2) translate(-2%, -3%);
			}
		}

		/* For video preview area */
		#videomaker-preview-area {
			width: 360px;
			height: 640px;
			overflow: hidden;
			position: relative;
			background: #000;
			margin: 0 auto;
		}

		.ken-burns-preview {
			width: 100%;
			height: 100%;
			object-fit: cover;
		}
		.btn-green {
		background: #10b981;
		color: white;
	}
	.btn-green:hover {
		background: #059669;
}
		
		
	</style>
</head>
<body>

<div class="container">
    <header class="vidora-header">
        <div class="brand">
            🎬 <span>Vidora</span>
            <small>Social Media Automation</small>
        </div>
            <nav class="vidora-nav">
				<a href="vidora.php">1. Contents</a>
				<a href="image_gen.php "  >2. Images</a>
				<a href="audio_gen.php" >3. Audios</a>
				<a href="videomaker.php"  class="active"  >4. Video</a>
				<a href="podcast_translator.php">5. Translate</a>
				<a href="publisher/dashboard.php"  >6. Schedule</a>
			</nav>
    </header>


<div class="dashboard-card"> 



    <div class="flex-row">
        <div class="controls">
			<h1 style="margin: 0; color: #1e293b;">Videomaker</h1>
					
             <form method="GET" id="filterForm">
            <div class="ui-row">
                <div>
                    <label><strong>Language:</strong></label><br>
                    <select name="lang_filter" onchange="document.querySelector('[name=podcast_id]').value=''; this.form.submit()" style="width: 150px;">
                        <?php foreach ($lang_codes as $code => $name): ?>
                            <option value="<?= $code ?>" <?= ($selected_lang == $code) ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
					<label><strong>Podcast Project:</strong></label><br>
					<select name="podcast_id" onchange="this.form.submit()" style="width: 250px;">
						<option value="">-- Select Project --</option>
						<?php if ($title_result): ?>
							<?php while($t_row = mysqli_fetch_assoc($title_result)): ?>
								<option value="<?= $t_row['id'] ?>" <?= ($selected_podcast_id == $t_row['id']) ? 'selected' : '' ?>>
									<?= htmlspecialchars($t_row['title']) ?>
								</option>
							<?php endwhile; ?>
						<?php endif; ?>
					</select>
				</div>
            </div>
            </form>
              

            <div class="ui-row">
                
                <div>
                    <label><strong>Text Style:</strong></label><br>
                    <select id="textStylePicker" onchange="saveSettings()">
                        <option value="typewriter">Typewriter (Wipe)</option>
                        <option value="scroll">Smooth Scroll (Up)</option>
                        <option value="breathe">Breathe (Fade Chunks)</option>
                        <option value="static">Static (All At Once)</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>Text Speed Sync: <span id="offsetVal">0.85</span>x</label><br>
                    <input type="number" id="textSpeedOffset" min="0.1" max="2.0" step="0.05" value="0.85" onchange="saveSettings()" style="width:70px; padding:6px; border:1px solid #ddd; border-radius:4px; font-size:11px;">
                    <br><small>(0.1 = Super Fast | 2.0 = Very Slow)</small>
                </div>
            </div>
			
			<div class="ui-row">
			<div>
				<label><strong>Font Family:</strong></label><br>
				<select id="fontFamilyPicker" onchange="saveSettings()">
					<option value="Arial">Arial</option>
					<option value="Impact">Impact</option>
					<option value="Georgia">Georgia</option>
					<option value="Verdana">Verdana</option>
					<option value="Tahoma">Tahoma</option>
					<option value="Trebuchet MS">Trebuchet MS</option>
					<option value="Courier New">Courier New</option>
					<option value="Comic Sans MS">Comic Sans MS</option>
					<option value="Palatino">Palatino</option>
					<option value="Lucida Console">Lucida Console</option>
					<option value="Segoe UI">Segoe UI</option>
				</select>
			</div>
			<div>
				<label><strong>Font Style:</strong></label><br>
		 		<select id="fontStylePicker" onchange="saveSettings()">
					<option value="normal">Normal</option>
					<option value="bold">Bold</option>
					<option value="italic">Italic</option>
					<option value="bold-italic">Bold + Italic</option>
				</select>
			</div>
			<div>
				<label><strong>Font Size:</strong></label><br>
				<select id="fontSizePicker" onchange="saveSettings()">
					<option value="18">18px - Small</option>
					<option value="22">22px - Medium</option>
					<option value="26">26px - Default</option>
					<option value="30">30px - Large</option>
					<option value="36">36px - XL</option>
					<option value="42">42px - XXL</option>
				</select>
			</div>
			<div>
				<label><strong>Font Color:</strong></label><br>
				<input type="color" id="fontColorPicker" value="#ffffff" onchange="saveSettings()" style="width:60px; height:30px; border:1px solid #ddd; border-radius:4px; cursor:pointer;">
			</div>
			<div>
				<label><strong>Font BG:</strong></label><br>
				<div style="display:flex; gap:5px; align-items:center;">
					<input type="color" id="fontBgColorPicker" value="#000000" onchange="saveSettings()" style="width:60px; height:30px; border:1px solid #ddd; border-radius:4px; cursor:pointer;">
					<label style="font-size:10px;"><input type="checkbox" id="fontBgEnabled" onchange="saveSettings()"> Enable</label>
				</div>
			</div>
			<div>
				<label><strong>BG Opacity:</strong></label><br>
				<input type="range" id="fontBgOpacity" min="0" max="1" step="0.1" value="0.5" oninput="document.getElementById('bgOpacityVal').innerText=this.value; saveSettings()">
				<span id="bgOpacityVal" style="font-size:10px;">0.5</span>
			</div>
		</div>

		<div class="ui-row">
			<div>
				<label><strong>Line Spacing:</strong></label><br>
				<select id="lineSpacingPicker" onchange="saveSettings()">
					<option value="1.0">1.0 - Tight</option>
					<option value="1.2">1.2 - Compact</option>
					<option value="1.4" selected>1.4 - Default</option>
					<option value="1.6">1.6 - Relaxed</option>
					<option value="1.8">1.8 - Spacious</option>
					<option value="2.0">2.0 - Double</option>
				</select>
			</div>
			<div>
				<label><strong>Paragraph Gap:</strong></label><br>
				<select id="paraSpacingPicker" onchange="saveSettings()">
					<option value="0">0px - None</option>
					<option value="4">4px - Small</option>
					<option value="8" selected>8px - Default</option>
					<option value="14">14px - Large</option>
					<option value="20">20px - XL</option>
				</select>
			</div>
			<div>
				<label><strong>Text Position:</strong></label><br>
				<select id="textPositionPicker" onchange="saveSettings()">
					<option value="top">Top</option>
					<option value="center">Center</option>
					<option value="bottom" selected>Bottom</option>
				</select>
			</div>
		</div>

        <!-- ========== LOGO CONTROLS (NEW) ========== -->
        <div class="logo-controls-card">
            <label class="section-label">🛟 Logo Overlay</label>
            <div class="ui-row" style="margin-bottom:0;">
                <div>
                    <label><strong>Upload Logo:</strong></label><br>
                    <input type="file" id="logoUpload" accept="image/png,image/svg+xml,image/jpg,image/jpeg" onchange="handleLogoUpload(this)" style="font-size:10px; width:180px;">
                </div>
                <div>
                    <label><strong>Logo Size:</strong></label><br>
                    <select id="logoSizePicker" onchange="window.logoState.logoSize = parseInt(this.value); saveSettings(); updateLogoPreview()">
                        <option value="30">Tiny (30)</option>
                        <option value="40">Small (40)</option>
                        <option value="60" selected>Medium (60)</option>
                        <option value="80">Large (80)</option>
                        <option value="100">XL (100)</option>
                        <option value="120">XXL (120)</option>
                    </select>
                </div>
                <div>
                    <label><strong>Logo Position:</strong></label><br>
                    <select id="logoPositionPicker" onchange="window.logoState.logoPosition = this.value; saveSettings(); updateLogoPreview()">
                        <option value="top" selected>Top Center</option>
                        <option value="bottom">Bottom Center</option>
                        <option value="top-left">Top Left</option>
                        <option value="top-right">Top Right</option>
                    </select>
                </div>
                <div>
                    <button type="button" class="btn btn-green" onclick="resetToDefaultLogo()" style="padding:6px 12px; font-size:10px;">🛟 Reset to Default</button>
                </div>
				<div class="ui-row" style="margin-bottom:8px;">
					<label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
						<input type="checkbox" id="logoEnabled" checked 
							   onchange="window.logoState.logoEnabled = this.checked; saveSettings(); updateLogoPreview();">
						<strong>Show Logo & Company Name</strong>
					</label>
				</div>
                <div>
                    <span id="logoStatusText" style="font-size:10px; color:#059669; font-style:italic;">✓ StressReleasor Logo Active</span>
                </div>
            </div>
        </div>
        <!-- ========== END LOGO CONTROLS ========== -->

            <div class="ui-row" style="align-items: center; background: #f8fafc; padding: 10px; border-radius: 8px;">
               <!--  <button class="btn btn-green" id="batchBtn" onclick="processAllScenes()">🚀 Batch All</button>
                <button class="btn btn-blue" id="generate-btn" onclick="startGeneration()">🎤 Gen Audio</button>
				<button class="btn btn-blue" id="btn-generate-all" onclick="generateAllImages()">🎨 Gen Images</button>              
			   
			   -->
			   <button class="btn btn-purple" id="playAllBtn" onclick="playFullSequence()">▶️ Play & test</button>
                <button class="btn btn-red" id="recordBtn" onclick="toggleRecording()">⏺ Record Video </button>
          
				
				<button class="btn btn-green" id="updateBtn" onclick="updatePodcast('<?= $selected_podcast_id ?>')">
					🚀 Mark as Recorded
				</button>
              
                <div style="flex-grow: 1; text-align: right;">
                    <div id="duration-prediction">Select a row to see estimate...</div>
                    <p id="globalStatus" style="font-weight:600; color: #2563eb; margin: 5px 0 0 0; font-size: 12px;"></p>
                </div>
            </div>

            <div id="total-runtime-box" style="background: #1e293b; color: #fff; padding: 10px; border-radius: 8px; margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 14px;">Total Project Runtime: <span id="actualTotal" style="color: #4ade80; font-weight: bold;">0.0s</span></div>
                <div style="font-size: 11px; opacity: 0.8;">Predicted Total (Draft): <span id="predictedTotal">0.0s</span></div>
            </div>
        </div>

        <div class="monitor" style="width: 360px; height: 640px; margin: 0 auto; position: relative; background: #000; overflow: hidden; font-family: Arial, sans-serif;">
            <div class="video-container" style="width: 100%; height: 100%; position: relative;">
                <video id="videoPreview" style="width: 100%; height: 100%; object-fit: cover; display: none;" muted loop playsinline></video>
                <img id="imagePreview" src="" style="width: 100%; height: 100%; object-fit: cover; display: none;">
                
                <!-- Logo Preview Overlay -->
                <div id="logoPreviewOverlay" style="position:absolute; z-index:5; pointer-events:none; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.8));">
                    <div id="logoPreviewContent"></div>
                </div>

                <div id="typewriter-container">
                    <span id="typewriter-text"></span>
                </div>

                <div id="videoPlaceholder" style="color:#666; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                    📺 Monitor
                </div>
                <canvas id="exportCanvas" width="720" height="1280" style="display:none;"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="dashboard-card" style="padding: 10px;">
<table id="sceneTable">
    <thead>
        <tr>
            <th width="40">ID</th>
            <th>Script Content</th>
            <th width="100">Image Preview</th> 
            <th width="150">Audio Status</th>
            <th width="160">Media Player</th>
            <th width="60">Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($result && mysqli_num_rows($result) > 0): ?>
            <?php while($row = mysqli_fetch_assoc($result)): ?>
            <tr id="row-<?= $row['id'] ?>" class="scene-row" 
                data-image="podcast_images/<?= $row['image_file'] ?>" 
                data-video="podcast_videos/<?= $row['video_file'] ?>"
                data-actor="<?= htmlspecialchars($row['actor'] ?? 'host') ?>"
				data-logo-flag="<?= $row['logo_flag'] ?>"
				data-text-display="<?= htmlspecialchars($row['text_display'] ?? '') ?>"
                data-prompt="<?= htmlspecialchars($row['prompt'] ?? '') ?>"> 
                <td style="font-weight: bold; color: #94a3b8;"><?= $row['id'] ?></td>
                <td>
                    <textarea class="script-input" id="text-<?= $row['id'] ?>"><?= htmlspecialchars($row['text_contents']) ?></textarea>
                </td>
                
                <td id="img-status-<?= $row['id'] ?>" style="text-align:center;">
                    <?php if($row['image_file']): ?>
                        <img src="podcast_images/<?= $row['image_file'] ?>" class="preview-thumb" style="width:50px; height:50px; margin:0;">
                    <?php else: ?>
                        <span class="status-badge">Empty</span>
                    <?php endif; ?>
                </td>

                <td id="status-<?= $row['id'] ?>">
                    <span class="status-badge <?= $row['audio_file'] ? 'ready' : '' ?>">
                        <?= $row['audio_file'] ? "✅ " . ($row['duration'] ?? '0') . "s" : "⏳ Pending" ?>
                    </span>
                </td>
                <td id="audio-cell-<?= $row['id'] ?>">
                    <?php if($row['audio_file']): ?>
                        <audio id="audio-player-<?= $row['id'] ?>" controls style="height: 24px; width: 150px;">
                            <source src="podcast_audios/<?= $row['audio_file'] ?>" type="audio/mpeg">
                        </audio>
                    <?php endif; ?>
                </td>
                <td>
                
				
                 <!--   <button class="btn btn-blue" onclick="generateSingle(<?= $row['id'] ?>)" style="padding: 4px 8px;">Gen</button> -->
					<button class="btn btn-red" onclick="deleteRow(<?= $row['id'] ?>)" style="padding: 4px 8px;">Delete</button>
			 </td>
            </tr>
            <?php endwhile; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>

<!-- Updated CSS for the wider, split-view modal -->
<style>
    .modal-content {
        width: 90% !important;
        max-width: 1200px !important;
        padding: 0;
        display: flex;
        flex-direction: column;
        height: 80vh;
    }
    .modal-body-wrapper {
        display: flex;
        flex: 1;
        overflow: hidden;
    }
    .modal-left-form {
        flex: 1;
        padding: 20px;
        overflow-y: auto;
        border-right: 1px solid #eee;
    }
    .modal-right-library {
        flex: 1;
        padding: 20px;
        background: #f8fafc;
        display: flex;
        flex-direction: column;
    }
    /* Tabs Styling */
    .tab-container {
        display: flex;
        gap: 5px;
        margin-bottom: 15px;
        border-bottom: 2px solid #e2e8f0;
    }
    .media-tab {
        padding: 10px 20px;
        cursor: pointer;
        border-radius: 6px 6px 0 0;
        background: #e2e8f0;
        font-weight: bold;
    }
    .media-tab.active {
        background: #3b82f6;
        color: white;
    }
    /* Media Grid */
    .media-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 10px;
        overflow-y: auto;
        padding: 10px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        flex: 1;
    }
    .media-item {
        border: 2px solid transparent;
        border-radius: 4px;
        cursor: pointer;
        transition: 0.2s;
        position: relative;
    }
    .media-item img, .media-item video {
        width: 100%;
        height: 80px;
        object-fit: cover;
        border-radius: 2px;
    }
    .media-item.selected {
        border-color: #3b82f6;
        background: #eff6ff;
    }
    .media-item:hover {
        background: #f1f5f9;
    }
    .media-label {
        font-size: 10px;
        word-break: break-all;
        text-align: center;
        display: block;
        padding: 2px;
    }
</style>

<!-- Edit Modal with 3 Tabs -->
<div id="editModal" class="modal">
    <div class="modal-content" style="width: 95%; max-width: 1400px;">
        <div class="modal-header" style="padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0;">📝 Edit Scene <span id="modalSceneId"></span></h3>
            <span style="cursor:pointer; font-size:24px;" onclick="closeModal()">&times;</span>
        </div>

        <!-- Main 3-Tab Container -->
        <div style="display: flex; height: 80vh;">
            <!-- Left Sidebar: Tab Navigation -->
            <div style="width: 120px; background: #f8fafc; border-right: 2px solid #e2e8f0; padding: 20px 0;">
                <div class="main-tab <?php echo (!isset($active_tab) || $active_tab === 'data') ? 'active' : ''; ?>" onclick="switchMainTab('data')" id="tab-data" style="padding: 15px; cursor: pointer; text-align: center; border-left: 4px solid transparent; margin-bottom: 5px;">
                    <div style="font-size: 24px; margin-bottom: 5px;">📝</div>
                    <div style="font-weight: 600;">Data</div>
                </div>
                <div class="main-tab" onclick="switchMainTab('images')" id="tab-images" style="padding: 15px; cursor: pointer; text-align: center; border-left: 4px solid transparent; margin-bottom: 5px;">
                    <div style="font-size: 24px; margin-bottom: 5px;">🖼️</div>
                    <div style="font-weight: 600;">Images</div>
                </div>
                <div class="main-tab" onclick="switchMainTab('videos')" id="tab-videos" style="padding: 15px; cursor: pointer; text-align: center; border-left: 4px solid transparent;">
                    <div style="font-size: 24px; margin-bottom: 5px;">🎥</div>
                    <div style="font-weight: 600;">Videos</div>
                </div>
            </div>

            <!-- Right Content Area -->
            <div style="flex: 1; overflow-y: auto; background: #fff;">
                <!-- DATA TAB CONTENT -->
                <div id="data-tab-content" class="tab-content" style="display: block; padding: 20px;">
                    <form id="editForm" enctype="multipart/form-data" method="POST" action="update_scene.php">
                        <input type="hidden" name="row_id" id="edit_row_id">
                        <input type="hidden" name="selected_server_image" id="selected_server_image">
                        <input type="hidden" name="selected_server_video" id="selected_server_video">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Left Column -->
                            <div>
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">Script Content (Voiceover)</label>
                                    <textarea name="text_contents" id="edit_text" rows="4" style="width:100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px; font-family: inherit;"></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">Text Display (Subtitles)</label>
                                    <textarea name="text_display" id="edit_text_display" rows="3" placeholder="Text to show on screen..." style="width:100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px;"></textarea>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div>
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">AI Image Prompt</label>
                                    <textarea name="image_prompt" id="edit_prompt" rows="3" style="width:100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px;"></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">Show Logo?</label>
                                    <select name="logo_flag" id="edit_logo_flag" style="width:100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 8px;">
                                        <option value="1">✅ Yes (Show Logo)</option>
                                        <option value="0">❌ No (Hide Logo)</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">Upload New Image</label>
                                    <input type="file" name="image_file" accept="image/*" onchange="clearServerSelection('image')" style="width:100%; padding: 8px; border: 2px solid #e2e8f0; border-radius: 8px;">
                                    <div id="image_current" style="margin-top:10px; font-size:12px; color:#666;"></div>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 700; color: #1e3a8a;">Upload New Video</label>
                                    <input type="file" name="video_file" accept="video/*" onchange="clearServerSelection('video')" style="width:100%; padding: 8px; border: 2px solid #e2e8f0; border-radius: 8px;">
                                    <div id="video_current" style="margin-top:10px; font-size:12px; color:#666;"></div>
                                </div>
                            </div>
                        </div>

                        <div style="text-align: right; margin-top: 30px; padding-top: 20px; border-top: 2px solid #e2e8f0;">
                            <button type="button" class="btn" style="background:#64748b; color:white; padding: 12px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; margin-right: 10px;" onclick="closeModal()">Cancel</button>
                            <button type="submit" class="btn btn-blue" style="background:#2563eb; color:white; padding: 12px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600;">💾 Save Changes</button>
                        </div>
                    </form>
                </div>

                <!-- IMAGES TAB CONTENT with Simple Top Button -->
<!-- IMAGES TAB CONTENT with Direct Update Button -->
<div id="images-tab-content" class="tab-content" style="display: none; padding: 20px;">
    <!-- Direct Update Button at Top -->
    <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
        <button id="image-use-top-btn" onclick="updateSceneWithImage()" style="background: #0f2a44; color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; display: none; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); font-size: 14px;">
            <span>✅</span> Update Scene with Selected Image
        </button>
    </div>

    <!-- Search Bar -->
    <div style="margin-bottom: 20px;">
        <h4 style="margin:0 0 15px 0; color:#1e3a8a;">🖼️ Server Image Library</h4>
        <input type="text" id="image-search" placeholder="Search images..." class="search-box" style="width:100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; margin-bottom: 15px;" onkeyup="filterMedia('image')">
    </div>
    
    <!-- Image Grid -->
    <div id="image-grid" class="media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; max-height: 500px; overflow-y: auto; padding: 10px; background: #f8fafc; border-radius: 8px;">
        <!-- Loaded via JS -->
    </div>
</div>

                <!-- VIDEOS TAB CONTENT -->
                <div id="videos-tab-content" class="tab-content" style="display: none; padding: 20px;">
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin:0 0 15px 0; color:#1e3a8a;">🎥 Server Video Library</h4>
                        <input type="text" id="video-search" placeholder="Search videos..." class="search-box" style="width:100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; margin-bottom: 15px;" onkeyup="filterMedia('video')">
                    </div>
                    
                    <div id="video-grid" class="media-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; max-height: 500px; overflow-y: auto; padding: 10px; background: #f8fafc; border-radius: 8px;">
                        <!-- Loaded via JS -->
                        <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #94a3b8;">
                            <div class="spinner" style="border: 3px solid #f3f3f3; border-top: 3px solid #1e3a8a; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
                            Loading videos...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>


    window.podcastData = {
        id: <?php echo $podcast_id ?? 0; ?>,
        title: "<?php echo addslashes($podcast_title ?? ''); ?>",
        lang: "<?php echo $lang_code ?? 'en'; ?>"
    };
</script>

<style>
/* Tab Styles */
.main-tab {
    transition: all 0.3s ease;
    background: transparent;
    color: #64748b;
}

.main-tab:hover {
    background: #e2e8f0;
    color: #1e3a8a;
}

.main-tab.active {
    background: #fff;
    color: #1e3a8a;
    border-left: 4px solid #1e3a8a !important;
    font-weight: 700;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

/* Media Grid Items */
.media-item {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    border: 2px solid transparent;
    transition: all 0.2s;
    cursor: pointer;
    position: relative;
}

.media-item:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-color: #1e3a8a;
}

.media-item.selected {
    border-color: #059669;
    background: #f0fdf4;
}

.media-preview {
    width: 100%;
    height: 120px;
    object-fit: cover;
    display: block;
    background: #f1f5f9;
}

video.media-preview {
    background: #000;
}

.media-info {
    padding: 8px;
    border-top: 1px solid #e2e8f0;
}

.media-name {
    font-size: 11px;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.media-size {
    font-size: 9px;
    color: #64748b;
}

.select-indicator {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 22px;
    height: 22px;
    background: #059669;
    color: white;
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.media-item.selected .select-indicator {
    display: flex;
}

.selection-status {
    margin-top: 15px;
    padding: 10px;
    background: #f0f9ff;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    color: #0369a1;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.selection-status button {
    background: #1e3a8a;
    color: white;
    border: none;
    padding: 6px 15px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    font-size: 12px;
}

.selection-status button:hover {
    background: #2563eb;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Scrollbar styling */
.media-grid::-webkit-scrollbar {
    width: 8px;
}

.media-grid::-webkit-scrollbar-track {
    background: #e2e8f0;
    border-radius: 4px;
}

.media-grid::-webkit-scrollbar-thumb {
    background: #94a3b8;
    border-radius: 4px;
}

.media-grid::-webkit-scrollbar-thumb:hover {
    background: #64748b;
}

// ========== MODAL TAB SWITCHING FUNCTIONS ==========

// Current state for modal tabs
let currentMainTab = 'data';
let selectedImage = null;
let selectedVideo = null;
let imageFiles = [];
let videoFiles = [];

// Switch between main tabs (Data, Images, Videos)
function switchMainTab(tab) {
    currentMainTab = tab;
    
    // Update tab styles
    document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');
    
    // Show/hide tab content
    const tabContents = document.querySelectorAll('.tab-content');
    if (tabContents.length > 0) {
        tabContents.forEach(content => content.style.display = 'none');
        const activeContent = document.getElementById(`${tab}-tab-content`);
        if (activeContent) activeContent.style.display = 'block';
    }
    
    // Load media if needed
    if (tab === 'images') {
        if (imageFiles.length === 0) {
            loadMedia('images');
        } else {
            renderImageGrid(imageFiles);
        }
    } else if (tab === 'videos') {
        if (videoFiles.length === 0) {
            loadMedia('videos');
        } else {
            renderVideoGrid(videoFiles);
        }
    }
}

// Load media by type
async function loadMedia(type) {
    try {
        const grid = document.getElementById(type === 'images' ? 'image-grid' : 'video-grid');
        if (grid) {
            grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px;"><div class="spinner" style="margin:0 auto 15px;"></div>Loading...</div>';
        }
        
        const formData = new FormData();
        formData.append('action', 'get_media');
        formData.append('type', type);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (type === 'images') {
                imageFiles = data.data.images || [];
                if (currentMainTab === 'images') {
                    renderImageGrid(imageFiles);
                }
            } else if (type === 'videos') {
                videoFiles = data.data.videos || [];
                if (currentMainTab === 'videos') {
                    renderVideoGrid(videoFiles);
                }
            }
        }
    } catch (error) {
        console.error(`Error loading ${type}:`, error);
    }
}

// Render image grid
function renderImageGrid(images) {
    const grid = document.getElementById('image-grid');
    if (!grid) return;
    
    if (!images || images.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🖼️</span>No images found</div>';
        return;
    }
    
    let html = '';
    images.forEach(img => {
        const isSelected = selectedImage && selectedImage.name === img.name;
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" onclick="selectMedia('image', '${img.name.replace(/'/g, "\\'")}', '${img.path.replace(/'/g, "\\'")}')">
                <img src="${img.path}" class="media-preview" alt="${img.name}" loading="lazy">
                <div class="media-info">
                    <div class="media-name">${img.name}</div>
                    <div class="media-size">${img.formatted_size || ''}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html + (selectedImage ? getSelectionStatus('image') : '');
}

// Render video grid
function renderVideoGrid(videos) {
    const grid = document.getElementById('video-grid');
    if (!grid) return;
    
    if (!videos || videos.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><span style="font-size:48px; display:block; margin-bottom:15px;">🎥</span>No videos found</div>';
        return;
    }
    
    let html = '';
    videos.forEach(vid => {
        const isSelected = selectedVideo && selectedVideo.name === vid.name;
        html += `
            <div class="media-item ${isSelected ? 'selected' : ''}" onclick="selectMedia('video', '${vid.name.replace(/'/g, "\\'")}', '${vid.path.replace(/'/g, "\\'")}')">
                <video class="media-preview" src="${vid.path}" muted preload="metadata" onmouseover="this.play()" onmouseout="this.pause();this.currentTime=0;"></video>
                <div class="media-info">
                    <div class="media-name">${vid.name}</div>
                    <div class="media-size">${vid.formatted_size || ''}</div>
                </div>
                <div class="select-indicator">✓</div>
            </div>
        `;
    });
    
    grid.innerHTML = html + (selectedVideo ? getSelectionStatus('video') : '');
}

// Get selection status HTML
function getSelectionStatus(type) {
    const selected = type === 'image' ? selectedImage : selectedVideo;
    if (!selected) return '';
    
    return `
        <div class="selection-status" style="grid-column:1/-1; margin-top:15px; padding:10px; background:#f0f9ff; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
            <span>✅ Selected: ${selected.name}</span>
            <button onclick="useSelectedMedia('${type}')" style="background:#1e3a8a; color:white; border:none; padding:6px 15px; border-radius:4px; cursor:pointer; font-weight:600;">Use This File</button>
        </div>
    `;
}

// Select media
function selectMedia(type, name, path) {
    if (type === 'image') {
        selectedImage = { name, path };
        renderImageGrid(imageFiles);
    } else {
        selectedVideo = { name, path };
        renderVideoGrid(videoFiles);
    }
}

// Use selected media in form
function useSelectedMedia(type) {
    if (type === 'image' && selectedImage) {
        const input = document.getElementById('selected_server_image');
        if (input) input.value = selectedImage.name;
        
        const container = document.getElementById('image_current');
        if (container) {
            container.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                    <img src="${selectedImage.path}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;">
                    <span style="color:#059669; font-weight:600;">${selectedImage.name} (selected from library)</span>
                </div>
            `;
        }
        // Switch back to data tab
        switchMainTab('data');
    } else if (type === 'video' && selectedVideo) {
        const input = document.getElementById('selected_server_video');
        if (input) input.value = selectedVideo.name;
        
        const container = document.getElementById('video_current');
        if (container) {
            container.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background:#f0fdf4; padding:8px; border-radius:4px; margin-top:10px;">
                    <video src="${selectedVideo.path}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" muted></video>
                    <span style="color:#059669; font-weight:600;">${selectedVideo.name} (selected from library)</span>
                </div>
            `;
        }
        // Switch back to data tab
        switchMainTab('data');
    }
}

// Filter media by search
function filterMedia(type) {
    const searchInput = document.getElementById(type === 'image' ? 'image-search' : 'video-search');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    
    if (type === 'image') {
        const filtered = imageFiles.filter(img => img.name.toLowerCase().includes(searchTerm));
        renderImageGrid(filtered);
    } else {
        const filtered = videoFiles.filter(vid => vid.name.toLowerCase().includes(searchTerm));
        renderVideoGrid(filtered);
    }
}

// Clear server selection
function clearServerSelection(type) {
    if (type === 'image') {
        const input = document.getElementById('selected_server_image');
        if (input) input.value = '';
        selectedImage = null;
    } else {
        const input = document.getElementById('selected_server_video');
        if (input) input.value = '';
        selectedVideo = null;
    }
}

// Make sure to also include your existing openEditModal function
// and ensure it calls loadMediaLibraries()
async function loadMediaLibraries() {
    await Promise.all([loadMedia('images'), loadMedia('videos')]);
}

// Update your existing openEditModal function to include:
// loadMediaLibraries();
</style>
</body>
</html>
