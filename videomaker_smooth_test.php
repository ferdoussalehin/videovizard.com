<?php
// smooth_player_test.php — v11.2
// Library modal: auto-loads matching media using scene natural_language_tags
// + semantic embedding search (OpenAI text-embedding-3-small) with cosine similarity
// + text fallback when no embeddings, + image/video tabs with score badges

session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }
include 'dbconnect_hdb.php';
require_once 'generate_image_api.php';

$podcast_id = (int)($_GET['podcast_id'] ?? 0);
if (!$podcast_id) die('No podcast_id');

$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT title, music_file, lang_code FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
$podcast_title = $row['title']      ?? '';
$podcast_music = $row['music_file'] ?? '';
$lang_code     = $row['lang_code']  ?? 'en';

$scenes = [];
$q = mysqli_query($conn,
    "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no ASC, id ASC");
while ($r = mysqli_fetch_assoc($q)) $scenes[] = $r;
if (!$scenes) die('No scenes found for podcast #'.$podcast_id);

$scenes_json = json_encode($scenes);

$vid_files = [];
$img_slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
foreach ($scenes as $sc) {
    foreach ($img_slots as $k) {
        $fn = trim($sc[$k] ?? '');
        if ($fn && preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $fn) && !in_array($fn, $vid_files))
            $vid_files[] = $fn;
    }
}

// ── AJAX handlers ──────────────────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // ── upload_scene_image ────────────────────────────────────────────────
    if ($action === 'upload_scene_image') {
        $response = ['success'=>false,'message'=>''];
        try {
            $scene_id    = (int)$_POST['scene_id'];
            $image_field = in_array($_POST['image_field']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                           ? $_POST['image_field'] : 'image_file';
            $media_type  = ($_POST['media_type']??'image')==='video' ? 'video' : 'image';
            if (!isset($_FILES['scene_image'])||$_FILES['scene_image']['error']!==UPLOAD_ERR_OK)
                throw new Exception('Upload error: '.($_FILES['scene_image']['error']??'no file'));
            $file = $_FILES['scene_image'];
            if ($file['size'] > 50*1024*1024) throw new Exception('Max 50MB');
            $ext      = strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            $filename = 'scene_'.$scene_id.'_'.time().'_'.mt_rand(1000,9999).'.'.$ext;
            $dir      = $media_type==='video' ? __DIR__.'/podcast_videos/' : __DIR__.'/podcast_images/';
            $db_field = $media_type==='video' ? 'video_file' : $image_field;
            if (!is_dir($dir)) mkdir($dir,0755,true);
            if (!move_uploaded_file($file['tmp_name'],$dir.$filename))
                throw new Exception('Failed to save file');
            $esc = mysqli_real_escape_string($conn,$filename);
            mysqli_query($conn,"UPDATE hdb_podcast_stories SET `$db_field`='$esc' WHERE id=$scene_id");
            $response = ['success'=>true,'filename'=>$filename,'media_type'=>$media_type];
        } catch (Exception $e) { $response['message']=$e->getMessage(); }
        echo json_encode($response); exit;
    }

    // ── assign_image ──────────────────────────────────────────────────────
    if ($action === 'assign_image') {
        $scene_id    = (int)$_POST['scene_id'];
        $filename    = mysqli_real_escape_string($conn,$_POST['filename']??'');
        $image_field = in_array($_POST['image_field']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                       ? $_POST['image_field'] : 'image_file';
        $ok = $scene_id && $filename && mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$image_field`='$filename' WHERE id=$scene_id");
        echo json_encode(['success'=>(bool)$ok,'filename'=>$filename]); exit;
    }

    // ── search_media_nl — embedding-based search ──────────────────────────
    if ($action === 'search_media_nl') {
        ini_set('memory_limit','256M');
        set_time_limit(60);

        $nl_query          = trim($_POST['query']             ?? '');
        $media_type_filter = trim($_POST['media_type_filter'] ?? '');

        if (empty($nl_query)) { echo json_encode([]); exit; }

        // API key — reuse $apiKey if available from generate_image_api.php
        $apiKey_vm = isset($apiKey) ? $apiKey : '';

        // Media-type clause
        $mt_clause = '';
        if ($media_type_filter === 'image')
            $mt_clause = "AND (media_type='image' OR media_type IS NULL OR media_type='')";
        elseif ($media_type_filter === 'video')
            $mt_clause = "AND media_type='video'";

        // Helper: get embedding from OpenAI
        if (!function_exists('getEmbedding_vm')) {
            function getEmbedding_vm($text, $key) {
                if (empty($key)) return null;
                $ch = curl_init('https://api.openai.com/v1/embeddings');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer '.$key
                    ],
                    CURLOPT_POSTFIELDS => json_encode(['model'=>'text-embedding-3-small','input'=>$text]),
                    CURLOPT_TIMEOUT    => 15,
                ]);
                $res  = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code !== 200) return null;
                $data = json_decode($res, true);
                return $data['data'][0]['embedding'] ?? null;
            }
        }

        // Helper: cosine similarity
        if (!function_exists('cosineSimilarity_vm')) {
            function cosineSimilarity_vm($a, $b) {
                $dot = $nA = $nB = 0.0;
                $len = min(count($a), count($b));
                for ($i = 0; $i < $len; $i++) {
                    $dot += $a[$i] * $b[$i];
                    $nA  += $a[$i] * $a[$i];
                    $nB  += $b[$i] * $b[$i];
                }
                return ($nA == 0 || $nB == 0) ? 0.0 : $dot / (sqrt($nA) * sqrt($nB));
            }
        }

        // Split query on pipe-separated segments
        $segments = array_filter(array_map('trim', explode('|', $nl_query)), fn($s)=>strlen($s)>2);
        if (empty($segments)) $segments = [$nl_query];

        // Get embeddings per segment
        $seg_vecs = [];
        foreach ($segments as $seg) {
            $v = getEmbedding_vm($seg, $apiKey_vm);
            if ($v) $seg_vecs[$seg] = $v;
        }

        // Text fallback if no embeddings
        if (empty($seg_vecs)) {
            $conds = [];
            foreach ($segments as $seg) {
                $se = mysqli_real_escape_string($conn, $seg);
                $conds[] = "natural_language_tags LIKE '%$se%'";
            }
            $where = implode(' OR ', $conds);
            $res   = [];
            $fq    = mysqli_query($conn,
                "SELECT id, image_name, natural_language_tags, image_hashtags, media_type, thumbnail
                   FROM hdb_image_data
                  WHERE ($where) $mt_clause
                  ORDER BY RAND() LIMIT 30");
            if ($fq) while ($r = mysqli_fetch_assoc($fq)) {
                $nl_raw   = $r['natural_language_tags'] ?? '';
                $nl_lines = array_filter(array_map('trim', explode('|', $nl_raw)));
                $matched  = '';
                foreach ($segments as $seg) {
                    foreach ($nl_lines as $line) {
                        if (stripos($line,$seg)!==false){ $matched=$line; break 2; }
                    }
                }
                $res[] = [
                    'id'           => $r['id'],
                    'filename'     => $r['image_name'],
                    'type'         => $r['media_type'] ?? 'image',
                    'hashtags'     => $r['image_hashtags'] ?? '',
                    'nl_tags'      => $nl_raw,
                    'matched_line' => $matched,
                    'score'        => 0,
                    'thumbnail'    => $r['thumbnail'] ?? '',
                ];
            }
            echo json_encode($res); exit;
        }

        // Embedding search — score all assets
        $scoreAssets = function($limit, $threshold) use ($conn, $seg_vecs, $mt_clause) {
            $q = mysqli_query($conn,
                "SELECT id, image_name, natural_language_tags, image_hashtags, media_type, embedding, thumbnail
                   FROM hdb_image_data
                  WHERE embedding IS NOT NULL AND embedding != ''
                  $mt_clause
                  ORDER BY RAND() LIMIT $limit");
            $scored = [];
            if (!$q) return $scored;
            while ($asset = mysqli_fetch_assoc($q)) {
                $emb = $asset['embedding'];
                if (strlen($emb) < 100) continue;
                $av = json_decode($emb, true);
                if (!is_array($av)) continue;

                $best_score = 0.0; $best_seg = '';
                foreach ($seg_vecs as $seg => $sv) {
                    if (count($sv) !== count($av)) continue;
                    $s = cosineSimilarity_vm($sv, $av);
                    if ($s > $best_score) { $best_score=$s; $best_seg=$seg; }
                }
                if ($best_score < $threshold) continue;

                $nl_raw   = $asset['natural_language_tags'] ?? '';
                $nl_lines = array_filter(array_map('trim', explode('|', $nl_raw)));
                $best_line = ''; $best_overlap = 0;
                $seg_words = preg_split('/\s+/', strtolower($best_seg));
                foreach ($nl_lines as $line) {
                    $overlap = count(array_intersect($seg_words, preg_split('/\s+/', strtolower($line))));
                    if ($overlap > $best_overlap) { $best_overlap=$overlap; $best_line=$line; }
                }
                if (empty($best_line) && !empty($nl_lines)) $best_line = reset($nl_lines);

                $scored[] = [
                    'id'              => $asset['id'],
                    'filename'        => $asset['image_name'],
                    'type'            => $asset['media_type'] ?? 'image',
                    'hashtags'        => $asset['image_hashtags'] ?? '',
                    'nl_tags'         => $nl_raw,
                    'matched_line'    => $best_line,
                    'matched_segment' => $best_seg,
                    'score'           => round($best_score,4),
                    'thumbnail'       => $asset['thumbnail'] ?? '',
                ];
            }
            usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
            return $scored;
        };

        $results = array_slice($scoreAssets(800, 0.40), 0, 30);
        // Fallback to looser threshold
        if (empty($results)) $results = array_slice($scoreAssets(800, 0.30), 0, 20);

        echo json_encode($results); exit;
    }

    // ── get_library_files — used when no scene tags available (recent media) ──
    if ($action === 'get_library_files') {
        $files = [];
        $q = mysqli_query($conn,
            "SELECT image_name AS filename, image_hashtags AS tags, media_type, natural_language_tags
               FROM hdb_image_data
              ORDER BY id DESC LIMIT 300");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $files[] = $r;
        if (!$files) {
            $dir = __DIR__.'/podcast_images/';
            if (is_dir($dir)) foreach (scandir($dir) as $f) {
                if ($f==='.'||$f==='..') continue;
                if (preg_match('/\.(jpg|jpeg|png|gif|webp|mp4|webm|mov)$/i',$f))
                    $files[] = ['filename'=>$f,'tags'=>'','media_type'=>preg_match('/\.(mp4|webm|mov)$/i',$f)?'video':'image','natural_language_tags'=>''];
            }
        }
        echo json_encode(['success'=>true,'files'=>$files]); exit;
    }

    // ── save_prompt ───────────────────────────────────────────────────────
    if ($action === 'save_prompt') {
        $scene_id = (int)$_POST['scene_id'];
        $field    = in_array($_POST['prompt_field']??'', ['prompt','prompt_1','prompt_2','prompt_3','prompt_4'])
                    ? $_POST['prompt_field'] : 'prompt';
        $prompt   = mysqli_real_escape_string($conn,$_POST['prompt']??'');
        echo json_encode(['success'=>(bool)mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$field`='$prompt' WHERE id=$scene_id")]); exit;
    }

    // ── generate_image ────────────────────────────────────────────────────
    if ($action === 'generate_image') {
        $scene_id        = (int)$_POST['scene_id'];
        $image_field     = in_array($_POST['image_field']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                           ? $_POST['image_field'] : 'image_file';
        $enhanced_prompt = trim($_POST['enhanced_prompt']??'');
        $hashtags        = trim($_POST['hashtags']??'');
        if (!$enhanced_prompt) { echo json_encode(['success'=>false,'message'=>'Empty prompt']); exit; }
        $base   = str_pad(mt_rand(1000000000,9999999999),10,'0',STR_PAD_LEFT);
        $folder = __DIR__.'/podcast_images';
        $result = generateAndSaveImage($enhanced_prompt,$base,'1024x1536',$folder,$apiKey);
        if (!$result['success']) { echo json_encode(['success'=>false,'message'=>$result['message']]); exit; }
        $name = $base.'.png';
        $esc  = mysqli_real_escape_string($conn,$name);
        $eh   = mysqli_real_escape_string($conn,$hashtags);
        $ep   = mysqli_real_escape_string($conn,$enhanced_prompt);
        mysqli_query($conn,"UPDATE hdb_podcast_stories SET `$image_field`='$esc' WHERE id=$scene_id");
        mysqli_query($conn,"INSERT INTO hdb_image_data (image_name,image_hashtags,image_prompt,created_at) VALUES ('$esc','$eh','$ep',NOW())");
        echo json_encode(['success'=>true,'image_name'=>$name]); exit;
    }

    // ── update_scene_slot ─────────────────────────────────────────────────
    if ($action === 'update_scene_slot') {
        $sid  = (int)$_POST['scene_id'];
        $slot = in_array($_POST['slot']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                ? $_POST['slot'] : 'image_file';
        $file = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');
        echo json_encode(['success' => (bool)mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$slot`='$file' WHERE id=$sid")]);
        exit;
    }

    // ── update_scene_audio ────────────────────────────────────────────────
    if ($action === 'update_scene_audio') {
        $sid  = (int)$_POST['scene_id'];
        $file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');
        echo json_encode(['success' => (bool)mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET audio_file='$file' WHERE id=$sid")]);
        exit;
    }

    // ── update_podcast_music ──────────────────────────────────────────────
    if ($action === 'update_podcast_music') {
        $file = mysqli_real_escape_string($conn, $_POST['music_file'] ?? '');
        echo json_encode(['success' => (bool)mysqli_query($conn,
            "UPDATE hdb_podcasts SET music_file='$file' WHERE id=$podcast_id")]);
        exit;
    }

    // ── list_media ────────────────────────────────────────────────────────
    if ($action === 'list_media') {
        $mtype  = $_POST['media_type'] ?? 'image';
        $search = trim($_POST['search'] ?? '');
        $dir    = __DIR__.($mtype==='video' ? '/podcast_videos/' : '/podcast_images/');
        $exts   = $mtype==='video' ? ['mp4','webm','mov','avi','mkv','m4v'] : ['jpg','jpeg','png','gif','webp'];
        $files  = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if ($f==='.'||$f==='..') continue;
                if (!in_array(strtolower(pathinfo($f,PATHINFO_EXTENSION)),$exts)) continue;
                if ($search && stripos($f,$search)===false) continue;
                $files[] = ['name'=>$f,'size'=>(int)@filesize($dir.$f)];
            }
        }
        usort($files, fn($a,$b)=>strcmp($b['name'],$a['name']));
        echo json_encode(array_slice($files,0,200)); exit;
    }

    // ── list_audio ────────────────────────────────────────────────────────
    if ($action === 'list_audio') {
        $dir = __DIR__.'/podcast_audios/';
        $files = [];
        if (is_dir($dir)) foreach (scandir($dir) as $f) {
            if ($f==='.'||$f==='..') continue;
            if (!preg_match('/\.(mp3|wav|ogg|m4a)$/i',$f)) continue;
            $files[] = ['name'=>$f,'size'=>(int)@filesize($dir.$f)];
        }
        echo json_encode($files); exit;
    }

    // ── list_music ────────────────────────────────────────────────────────
    if ($action === 'list_music') {
        $dir = __DIR__.'/podcast_music/';
        $files = [];
        if (is_dir($dir)) foreach (scandir($dir) as $f) {
            if ($f==='.'||$f==='..') continue;
            if (!preg_match('/\.(mp3|wav|ogg)$/i',$f)) continue;
            $files[] = ['name'=>$f,'size'=>(int)@filesize($dir.$f)];
        }
        echo json_encode($files); exit;
    }
}

define('CW', 360);
define('CH', 640);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — <?= htmlspecialchars($podcast_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --primary:#0f2a44; --primary2:#1a3a5c;
    --info:#3b82f6;    --success:#10b981;
    --warning:#f59e0b; --danger:#ef4444;
    --bg:#f0f4f8;      --surface:#ffffff;
    --surface2:#f8fafc;--border:#e2e8f0;
    --text:#0f172a;    --muted:#64748b;
    --radius:14px;
    --shadow:0 4px 20px rgba(15,42,68,.10);
    --shadow-lg:0 8px 40px rgba(15,42,68,.16);
    --error:#ef4444;
}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:stretch;}

.vv-header{background:var(--primary);display:flex;align-items:center;justify-content:space-between;padding:10px 20px;gap:12px;box-shadow:0 2px 12px rgba(0,0,0,.25);position:sticky;top:0;z-index:200;flex-shrink:0;}
.vv-brand{display:flex;align-items:center;gap:10px;text-decoration:none;}
.vv-brand .ico{font-size:22px;}
.vv-brand .name{font-size:16px;font-weight:800;color:#fff;}
.vv-brand .name span{color:#5fc3ff;}
.vv-title{font-size:12px;color:rgba(255,255,255,.65);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px;}
.vv-back{background:rgba(255,255,255,.12);border:none;color:#fff;padding:6px 14px;border-radius:30px;font-size:11px;font-weight:600;cursor:pointer;}
.vv-back:hover{background:rgba(255,255,255,.22);}

.workspace{display:flex;flex:1;align-items:flex-start;gap:0;min-height:0;}

.left-col{width:380px;flex-shrink:0;display:flex;flex-direction:column;height:calc(100vh - 48px);position:sticky;top:48px;overflow-y:auto;background:var(--bg);border-right:1.5px solid var(--border);padding:14px 12px 20px;gap:10px;}
.panel-body{padding:14px;overflow-y:auto;max-height:calc(100vh - 110px);}
.right-col{flex:1;display:flex;flex-direction:column;align-items:center;padding:16px 20px 30px;gap:10px;min-width:0;}

@media (max-width:899px){
    .workspace{flex-direction:column;align-items:center;}
    .left-col{width:100%;max-width:420px;height:auto;position:static;border-right:none;border-top:1.5px solid var(--border);padding:12px;order:2;}
    .right-col{width:100%;max-width:420px;padding:12px 12px 0;order:1;}
}

#iconBar{display:flex;gap:6px;width:100%;max-width:420px;}
.icon-btn{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;padding:8px 4px 6px;cursor:pointer;transition:all .15s;user-select:none;font-size:10px;font-weight:600;color:var(--muted);}
.icon-btn .ico{font-size:18px;line-height:1;}
.icon-btn:hover{border-color:var(--info);color:var(--info);background:#eff6ff;}
.icon-btn.active{border-color:var(--info);color:var(--info);background:#dbeafe;}
.icon-btn.play-btn{background:var(--primary);border-color:var(--primary);color:#fff;}
.icon-btn.play-btn:hover{background:var(--primary2);}
.icon-btn.rec-btn{background:var(--danger);border-color:var(--danger);color:#fff;}

#playerWrap{position:relative;width:<?= CW ?>px;height:<?= CH ?>px;border-radius:18px;overflow:hidden;background:#000;box-shadow:0 0 0 1.5px var(--border),var(--shadow-lg);flex-shrink:0;}
#screen{display:block;border-radius:18px;}
#screen.recording{outline:3px solid var(--danger);outline-offset:3px;}

#sceneNav{position:absolute;bottom:12px;left:50%;transform:translateX(-50%);z-index:20;display:flex;gap:10px;align-items:center;background:rgba(15,42,68,.72);backdrop-filter:blur(6px);padding:5px 14px;border-radius:30px;border:1px solid rgba(255,255,255,.15);}
.nav-btn{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.nav-btn:hover{background:rgba(95,195,255,.6);}
#sceneNum{color:#fff;font-size:12px;font-weight:700;min-width:44px;text-align:center;}

#preloadOverlay{position:absolute;inset:0;z-index:50;background:rgba(15,42,68,.92);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;border-radius:18px;}
#preloadOverlay.gone{display:none;}
.spinner{width:34px;height:34px;border:3px solid rgba(59,130,246,.2);border-top-color:var(--info);border-radius:50%;animation:spin .7s linear infinite;}
#preloadMsg{font-size:11px;color:#90caf9;}
#preloadBar{position:absolute;bottom:0;left:0;height:3px;background:var(--info);width:0%;transition:width .25s;border-radius:0 0 18px 18px;}
@keyframes spin{to{transform:rotate(360deg);}}

#vidPool{position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;overflow:hidden;pointer-events:none;}

#dotRow{display:flex;gap:4px;align-items:center;justify-content:center;}
.dot{width:5px;height:5px;border-radius:50%;background:rgba(100,116,139,.3);transition:all .3s;}
.dot.active{background:var(--info);width:14px;border-radius:3px;}

.panel{display:none;width:100%;background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
.panel.open{display:block;}
@media (min-width:900px){.panel.open{animation:none;}}
@media (max-width:899px){.panel.open{animation:slideDown .18s ease;}}
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}

.panel-head{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;background:var(--primary);font-size:12px;font-weight:700;color:#fff;letter-spacing:.04em;}
@media (min-width:900px){.panel-close-mobile{display:none;}}
.panel-close{background:none;border:none;color:rgba(255,255,255,.7);font-size:18px;cursor:pointer;padding:0 4px;line-height:1;}
.panel-close:hover{color:#fff;}

.panel-body{padding:14px;}
.panel-section{margin-bottom:14px;}
.panel-section:last-child{margin-bottom:0;}
.sec-label{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;}

.row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;}
.field{display:flex;flex-direction:column;gap:4px;flex:1;min-width:72px;}
.field label{font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em;}

select,input[type=number]{padding:6px 9px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-size:11px;font-family:inherit;cursor:pointer;outline:none;width:100%;}
select:hover,select:focus{border-color:var(--info);}
input[type=color]{padding:2px 3px;height:30px;width:100%;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);cursor:pointer;outline:none;}
input[type=range]{width:100%;accent-color:var(--info);cursor:pointer;}

.tog-row{display:flex;gap:5px;flex-wrap:wrap;}
.tog{padding:5px 10px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:11px;font-weight:600;cursor:pointer;transition:all .13s;}
.tog:hover{border-color:var(--info);color:var(--info);}
.tog.on{background:var(--info);border-color:var(--info);color:#fff;}

.pos-grid{display:grid;grid-template-columns:repeat(3,34px);gap:4px;}
.pos-cell{width:34px;height:34px;border-radius:7px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .13s;}
.pos-cell:hover{border-color:var(--info);color:var(--info);}
.pos-cell.on{background:var(--info);border-color:var(--info);color:#fff;}

.swatches{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px;}
.swatch{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:transform .1s;flex-shrink:0;}
.swatch:hover{transform:scale(1.2);}

/* Image panel */
.img-slots{display:flex;gap:6px;justify-content:space-between;margin-bottom:10px;}
.img-slot{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;}
.slot-thumb{width:50px;height:50px;border-radius:9px;overflow:hidden;border:2px solid var(--border);background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--muted);transition:border-color .15s;}
.slot-thumb img{width:100%;height:100%;object-fit:cover;display:none;}
.slot-thumb.active-slot{border-color:var(--info);}
.slot-label{font-size:9px;font-weight:700;color:var(--muted);}

/* Audio panel */
.audio-list{max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;}
.audio-row{display:flex;align-items:center;gap:6px;padding:6px 9px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface2);transition:all .13s;}
.audio-row.current{border-color:var(--success);background:#f0fdf4;}
.audio-row .aname{flex:1;font-size:11px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--text);}
.audio-row .asize{font-size:9px;color:var(--muted);white-space:nowrap;}
.aplay-btn{width:24px;height:24px;border-radius:50%;border:none;background:var(--info);color:#fff;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ause-btn{padding:3px 7px;border-radius:6px;border:none;background:var(--success);color:#fff;font-size:9px;font-weight:700;cursor:pointer;flex-shrink:0;}

/* Rec/download */
#recBar{width:100%;max-width:420px;background:var(--surface);border:1.5px solid #fca5a5;border-radius:10px;padding:8px 12px;font-size:11px;color:var(--muted);display:none;align-items:center;gap:8px;}
#recBar.on{display:flex;}
#recDot{width:8px;height:8px;border-radius:50%;background:var(--danger);flex-shrink:0;animation:pulse 1s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
#recSize{margin-left:auto;font-variant-numeric:tabular-nums;}

#dlPanel{width:100%;max-width:420px;background:var(--surface);border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 16px;display:none;flex-direction:column;gap:10px;}
#dlPanel.on{display:flex;}
#dlPanel h3{font-size:13px;font-weight:700;color:var(--success);}
#dlMeta{font-size:11px;color:var(--text);font-weight:600;}

/* Log */
#log{width:100%;max-width:420px;max-height:70px;overflow-y:auto;background:var(--surface);border:1.5px solid var(--border);border-radius:10px;padding:6px 10px;font-family:monospace;font-size:10px;line-height:1.7;color:var(--muted);}
#log .ok{color:var(--success);}#log .inf{color:var(--info);}#log .wrn{color:var(--warning);}#log .err{color:var(--danger);}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:30px;border:1.5px solid var(--border);background:var(--surface);color:var(--text);font-size:11px;font-weight:600;font-family:inherit;cursor:pointer;transition:all .15s;}
.btn:hover{border-color:var(--info);color:var(--info);}
.btn.primary{background:var(--info);border-color:var(--info);color:#fff;}
.btn.primary:hover{background:#2563eb;}
.btn.success{background:var(--success);border-color:var(--success);color:#fff;}
.btn:disabled{opacity:.35;cursor:not-allowed;pointer-events:none;}

/* ── Library modal — full redesign with tabs + score badges ── */
#libModal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.75);backdrop-filter:blur(4px);align-items:center;justify-content:center;}
#libModal.open{display:flex;}

.media-item{position:relative;border:2px solid var(--border);border-radius:10px;overflow:hidden;cursor:pointer;background:white;transition:border-color .15s,transform .15s;display:flex;flex-direction:column;}
.media-item:hover{transform:translateY(-1px);}
.media-item.selected .media-check{display:flex!important;}

::-webkit-scrollbar{width:4px;height:4px;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
</style>
</head>
<body>

<div class="vv-header">
    <a class="vv-brand" href="vizard_browser.php">
        <span class="ico">🎬</span>
        <span class="name">Video<span>Vizard</span></span>
    </a>
    <span class="vv-title"><?= htmlspecialchars($podcast_title ?: 'Untitled') ?></span>
    <button class="vv-back" onclick="history.back()">← Back</button>
</div>

<div id="vidPool">
<?php foreach ($vid_files as $fn): ?>
<video class="pv" data-fn="<?= htmlspecialchars($fn) ?>"
    src="podcast_videos/<?= htmlspecialchars($fn) ?>"
    muted loop playsinline preload="auto" crossorigin="anonymous"></video>
<?php endforeach; ?>
</div>

<div class="workspace">

    <!-- LEFT COLUMN -->
    <div class="left-col" id="leftCol">

        <!-- Caption panel -->
        <div class="panel" id="pCaption">
            <div class="panel-head">🅰️ Caption
                <button class="panel-close panel-close-mobile" onclick="closePanel('pCaption','ibCaption')">✕</button>
            </div>
            <div class="panel-body">
                <div class="panel-section">
                    <div class="sec-label">Font & Size</div>
                    <div class="row">
                        <div class="field"><label>Family</label>
                            <select id="selFont">
                                <option value="'Inter',sans-serif" selected>Inter</option>
                                <option value="'Segoe UI',sans-serif">Segoe UI</option>
                                <option value="Arial,sans-serif">Arial</option>
                                <option value="Georgia,serif">Georgia</option>
                                <option value="'Courier New',monospace">Courier New</option>
                                <option value="Impact,fantasy">Impact</option>
                                <option value="Verdana,sans-serif">Verdana</option>
                                <option value="'Times New Roman',serif">Times New Roman</option>
                            </select>
                        </div>
                        <div class="field" style="max-width:70px"><label>Size</label>
                            <select id="selSize">
                                <option value="11">11</option><option value="13">13</option>
                                <option value="15" selected>15</option><option value="18">18</option>
                                <option value="22">22</option><option value="26">26</option>
                                <option value="32">32</option><option value="40">40</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Colors</div>
                    <div class="row">
                        <div class="field"><label>Text</label><input type="color" id="selColor" value="#ffffff"></div>
                        <div class="field"><label>BG</label><input type="color" id="selBgColor" value="#000000"></div>
                        <div class="field">
                            <label>Opacity <span id="bgAlphaVal">52%</span></label>
                            <input type="range" id="selBgAlpha" min="0" max="100" value="52"
                                oninput="document.getElementById('bgAlphaVal').textContent=this.value+'%'">
                        </div>
                    </div>
                    <div class="swatches">
                        <?php foreach(['#ffffff','#ffff00','#ff3b30','#00ff00','#00ffff','#5fc3ff','#ff9500','#000000'] as $c): ?>
                        <div class="swatch" style="background:<?=$c?>;<?=$c==='#ffffff'?'border-color:#ccc;':''?>"
                            onclick="setTextColor('<?=$c?>')"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Text Style</div>
                    <div class="tog-row">
                        <button class="tog on" id="togBold"      onclick="toggleStyle('bold')"><b>B</b></button>
                        <button class="tog"    id="togItalic"    onclick="toggleStyle('italic')"><i>I</i></button>
                        <button class="tog"    id="togUnderline" onclick="toggleStyle('underline')"><u>U</u></button>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Text Effect</div>
                    <div class="row">
                        <div class="field">
                            <select id="selEffect">
                                <option value="none">None</option>
                                <option value="shadow">Shadow</option>
                                <option value="glow">Glow</option>
                                <option value="outline">Outline</option>
                                <option value="stroke">Stroke</option>
                                <option value="gradient">Gradient</option>
                                <option value="3d">3D</option>
                            </select>
                        </div>
                        <div class="field" id="strokeColorField" style="display:none">
                            <label>Color</label>
                            <input type="color" id="selStrokeColor" value="#000000">
                        </div>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Position & Alignment</div>
                    <div class="row" style="gap:14px;align-items:flex-start;">
                        <div>
                            <div class="sec-label" style="margin-bottom:6px;">Move Caption</div>
                            <div style="display:grid;grid-template-columns:repeat(3,34px);grid-template-rows:repeat(3,34px);gap:4px;">
                                <div></div>
                                <button class="pos-cell" title="Move up"    onclick="moveCaption(0,-20)">↑</button>
                                <div></div>
                                <button class="pos-cell" title="Move left"  onclick="moveCaption(-20,0)">←</button>
                                <button class="pos-cell on" title="Centre"  onclick="centreCaption()">●</button>
                                <button class="pos-cell" title="Move right" onclick="moveCaption(20,0)">→</button>
                                <div></div>
                                <button class="pos-cell" title="Move down"  onclick="moveCaption(0,20)">↓</button>
                                <div></div>
                            </div>
                            <div style="font-size:9px;color:var(--muted);margin-top:4px;text-align:center;">
                                X: <span id="posX">14</span>  Y: <span id="posY">auto</span>
                            </div>
                        </div>
                        <div>
                            <div class="sec-label" style="margin-bottom:6px">Align</div>
                            <div class="tog-row" style="flex-direction:column;gap:4px;">
                                <button class="tog on" id="taLeft"   onclick="setTA('left')">≡ Left</button>
                                <button class="tog"    id="taCenter" onclick="setTA('center')">≡ Center</button>
                                <button class="tog"    id="taRight"  onclick="setTA('right')">≡ Right</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Animation</div>
                    <div class="row">
                        <div class="field"><label>Style</label>
                            <select id="selAnim">
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
                        <div class="field" style="max-width:88px"><label>Speed</label>
                            <select id="selAnimSpeed">
                                <option value="0.5">Slow</option>
                                <option value="1" selected>Normal</option>
                                <option value="1.8">Fast</option>
                                <option value="3">Very Fast</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Transition & Ken Burns</div>
                    <div class="row">
                        <div class="field"><label>Transition</label>
                            <select id="selTransition">
                                <option value="fade">Fade</option>
                                <option value="slide-left">Slide Left</option>
                                <option value="slide-right">Slide Right</option>
                                <option value="zoom-in">Zoom In</option>
                                <option value="zoom-out">Zoom Out</option>
                                <option value="none">Cut</option>
                            </select>
                        </div>
                        <div class="field"><label>Ken Burns</label>
                            <select id="selKenBurns">
                                <option value="random">Random</option>
                                <option value="zoom-in">Zoom In</option>
                                <option value="zoom-out">Zoom Out</option>
                                <option value="pan-left">Pan Left</option>
                                <option value="pan-right">Pan Right</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Image panel -->
        <div class="panel" id="pImage">
            <div class="panel-head">🌄 Scene Images
                <button class="panel-close panel-close-mobile" onclick="closePanel('pImage','ibImage')">✕</button>
            </div>
            <div class="panel-body">
                <div class="panel-section">
                    <div class="sec-label">Image Slots — click to select</div>
                    <div class="img-slots" id="imgSlots">
                        <?php
                        $slotDefs = ['image_file'=>'Main','image_file_1'=>'V1',
                                     'image_file_2'=>'V2','image_file_3'=>'V3','image_file_4'=>'V4'];
                        foreach ($slotDefs as $slotKey => $slotLabel):
                        ?>
                        <div class="img-slot" onclick="selectSlot('<?=$slotKey?>')">
                            <div class="slot-thumb" id="slotThumb_<?=$slotKey?>">
                                <img id="slotImg_<?=$slotKey?>" src="" alt="">
                                <span id="slotPh_<?=$slotKey?>">🖼️</span>
                            </div>
                            <div class="slot-label"><?=$slotLabel?></div>
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
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:4px;">
                    <button onclick="uploadForSlot()"
                        style="background:var(--success);color:#fff;border:none;border-radius:12px;padding:10px 4px;cursor:pointer;font-size:11px;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:3px;">
                        <span style="font-size:20px;">📤</span>Upload
                    </button>
                    <button onclick="openLibraryModal()"
                        style="background:#7c3aed;color:#fff;border:none;border-radius:12px;padding:10px 4px;cursor:pointer;font-size:11px;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:3px;">
                        <span style="font-size:20px;">📚</span>Library
                    </button>
                    <button id="btnGenerate" onclick="generateForSlot()"
                        style="background:var(--info);color:#fff;border:none;border-radius:12px;padding:10px 4px;cursor:pointer;font-size:11px;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:3px;">
                        <span style="font-size:20px;">🔄</span>Generate
                    </button>
                </div>
                <input type="file" id="slotFileInput" accept="image/*,video/*" style="display:none"
                       onchange="handleSlotUpload(this)">
            </div>
        </div>

        <!-- ══ Library Modal — anchored inside left-col ══ -->
        <div id="libModal" style="display:none;position:fixed;top:48px;left:0;width:380px;
             bottom:0;z-index:9999;background:rgba(15,23,42,.82);backdrop-filter:blur(3px);
             align-items:flex-start;justify-content:center;padding:10px 8px;">
            <div style="background:var(--surface);border-radius:14px;width:100%;
                        height:calc(100vh - 68px);display:flex;flex-direction:column;overflow:hidden;
                        box-shadow:0 8px 40px rgba(0,0,0,.5);">
                <!-- Header -->
                <div style="display:flex;align-items:center;justify-content:space-between;
                            padding:10px 14px;background:var(--primary);flex-shrink:0;border-radius:14px 14px 0 0;">
                    <div style="min-width:0;flex:1;">
                        <div style="color:#fff;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            📚 Library — <span id="libSlotLabel">Main</span>
                        </div>
                        <div id="libSearchStatus" style="font-size:10px;color:rgba(255,255,255,.65);margin-top:1px;display:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"></div>
                    </div>
                    <button onclick="closeLibraryModal()"
                        style="background:rgba(255,255,255,.15);border:none;color:#fff;
                               width:26px;height:26px;border-radius:50%;cursor:pointer;font-size:15px;flex-shrink:0;margin-left:8px;">✕</button>
                </div>
                <!-- Search bar -->
                <div style="padding:8px 10px;border-bottom:1px solid var(--border);flex-shrink:0;">
                    <div style="display:flex;gap:6px;margin-bottom:7px;">
                        <input id="libSearch" type="text" placeholder="Search by description…"
                            style="flex:1;min-width:0;padding:6px 10px;border-radius:20px;
                                   border:1.5px solid var(--border);font-size:11px;outline:none;font-family:inherit;"
                            onkeydown="if(event.key==='Enter')performLibSearch()">
                        <button onclick="performLibSearch()"
                            style="padding:6px 12px;border-radius:20px;border:none;background:var(--info);color:#fff;font-size:10px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;">
                            🔍
                        </button>
                    </div>
                    <!-- Tabs -->
                    <div style="display:flex;gap:4px;">
                        <button id="libTabAll" onclick="setLibTab('all')"
                            style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--primary);background:var(--primary);color:#fff;font-size:10px;font-weight:600;cursor:pointer;">
                            All <span id="libCountAll" style="opacity:.8;"></span>
                        </button>
                        <button id="libTabImg" onclick="setLibTab('image')"
                            style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;">
                            🖼️ <span id="libCountImg"></span>
                        </button>
                        <button id="libTabVid" onclick="setLibTab('video')"
                            style="flex:1;padding:4px 6px;border-radius:20px;border:1.5px solid var(--border);background:var(--surface2);color:var(--muted);font-size:10px;font-weight:600;cursor:pointer;">
                            🎬 <span id="libCountVid"></span>
                        </button>
                    </div>
                </div>
                <!-- Grid -->
                <div id="libGrid"
                     style="flex:1;overflow-y:auto;padding:10px;
                            display:grid;grid-template-columns:repeat(3,1fr);gap:7px;">
                    <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>
                </div>
                <!-- Footer -->
                <div style="padding:8px 12px;border-top:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <span id="libSelInfo" style="font-size:10px;color:var(--muted);flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">No file selected</span>
                    <div style="display:flex;gap:6px;flex-shrink:0;">
                        <button onclick="closeLibraryModal()" class="btn" style="padding:5px 10px;font-size:10px;">Cancel</button>
                        <button id="libUseBtn" onclick="useLibraryFile()" class="btn primary" disabled style="opacity:.4;padding:5px 10px;font-size:10px;">✓ Use</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile: full-screen modal overlay (used when screen < 900px) -->
        <style>
        @media (max-width: 899px) {
            #libModal {
                width: 100% !important;
                left: 0 !important;
                top: 48px !important;
                padding: 8px !important;
            }
        }
        </style>

        <!-- Audio panel -->
        <div class="panel" id="pAudio">
            <div class="panel-head">🔊 Audio
                <button class="panel-close panel-close-mobile" onclick="closePanel('pAudio','ibAudio')">✕</button>
            </div>
            <div class="panel-body">
                <div class="panel-section">
                    <div class="sec-label">Scene Voiceover</div>
                    <div id="sceneAudioInfo" style="font-size:11px;color:var(--muted);margin-bottom:6px;">—</div>
                    <div class="audio-list" id="audioList">
                        <div style="font-size:11px;color:var(--muted);padding:8px;">Loading…</div>
                    </div>
                </div>
                <div class="panel-section">
                    <div class="sec-label">Background Music</div>
                    <div id="currentMusicInfo" style="font-size:11px;color:var(--muted);margin-bottom:6px;">—</div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <span style="font-size:10px;color:var(--muted);font-weight:600;white-space:nowrap;">Volume</span>
                        <input type="range" id="musicVolSlider" min="0" max="100" value="30" style="flex:1"
                            oninput="bgMusicVolume=this.value/100;document.getElementById('musicVolLbl').textContent=this.value+'%';if(bgAudio)bgAudio.volume=bgMusicVolume;">
                        <span id="musicVolLbl" style="font-size:10px;color:var(--muted);min-width:28px;text-align:right;">30%</span>
                    </div>
                    <div class="audio-list" id="musicList">
                        <div style="font-size:11px;color:var(--muted);padding:8px;">Loading…</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- .left-col -->

    <!-- RIGHT COLUMN -->
    <div class="right-col">

        <div id="iconBar">
            <div class="icon-btn" id="ibCaption" onclick="togglePanel('pCaption','ibCaption')">
                <span class="ico">🅰️</span>Caption
            </div>
            <div class="icon-btn" id="ibImage"
                 onclick="togglePanel('pImage','ibImage');updateSlotThumbs(SCENES[currentIndex]);selectSlot(activeSlot)">
                <span class="ico">🌄</span>Image
            </div>
            <div class="icon-btn" id="ibAudio"
                 onclick="togglePanel('pAudio','ibAudio');loadAudioLists()">
                <span class="ico">🔊</span>Audio
            </div>
            <div class="icon-btn play-btn" id="ibPlay" onclick="togglePlay()">
                <span class="ico" id="playIco">▶</span><span id="playLbl">Play</span>
            </div>
            <div class="icon-btn rec-btn" id="ibRecord" onclick="startRecording()">
                <span class="ico">⏺</span>Record
            </div>
        </div>

        <div id="playerWrap">
            <canvas id="screen" width="<?= CW ?>" height="<?= CH ?>"></canvas>
            <div id="sceneNav">
                <button class="nav-btn" onclick="navigate(-1)">←</button>
                <span id="sceneNum">1 / <?= count($scenes) ?></span>
                <button class="nav-btn" onclick="navigate(1)">→</button>
            </div>
            <div id="preloadOverlay">
                <div class="spinner"></div>
                <p id="preloadMsg">Preloading…</p>
                <div id="preloadBar"></div>
            </div>
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

        <div id="log"></div>

    </div><!-- .right-col -->

</div><!-- .workspace -->

<script>
const SCENES     = <?= $scenes_json ?>;
const IMG_BASE   = 'podcast_images/';
const VID_BASE   = 'podcast_videos/';
const AUD_BASE   = 'podcast_audios/';
const MUS_BASE   = 'podcast_music/';
const PODCAST_ID = <?= $podcast_id ?>;
const CW = <?= CW ?>, CH = <?= CH ?>;
const T_DUR = 380, KB_DUR = 8000;
const KB_EFFECTS = ['zoom-in','zoom-out','pan-left','pan-right'];

const canvas = document.getElementById('screen');
const ctx    = canvas.getContext('2d');

const vidEls = {};
document.querySelectorAll('.pv').forEach(v => vidEls[v.dataset.fn] = v);
const imgCache = {};
const sceneKB  = SCENES.map(() => KB_EFFECTS[Math.floor(Math.random()*KB_EFFECTS.length)]);

const C = {
    font:"'Inter',sans-serif", size:15,
    color:'#ffffff', bgColor:'#000000', bgAlpha:0.52,
    bold:true, italic:false, underline:false,
    posX:14, posY:-1,
    textAlign:'left',
    anim:'typewriter', animSpeed:1,
    effect:'none', strokeColor:'#000000', strokeWidth:2,
};

const S = {
    type:'blank', img:null, imgOut:null, vidEl:null,
    alpha:1, alphaOut:0, kbEffect:'zoom-in', kbStart:0, txOffset:null,
};

let captionFull='', captionShow='', captionWords=[], captionKarIdx=0;
let captionTimer=null, renderRaf=null, framesDrawn=0;
let currentIndex=0, isPlaying=false, isRecording=false;
let currentAudio=null, transitioning=false;

let bgAudio=null, bgMusicVolume=0.3;
<?php if ($podcast_music): ?>
bgAudio=new Audio('<?= addslashes(MUS_BASE.$podcast_music) ?>');
bgAudio.loop=true; bgAudio.volume=bgMusicVolume;
<?php endif; ?>

let activeSlot='image_file';
let previewAudio=null;

// ── Log ────────────────────────────────────────────────────────────────────
function L(m,c=''){
    const el=document.getElementById('log');
    const p=document.createElement('p');
    if(c)p.className=c; p.textContent=m;
    el.appendChild(p); el.scrollTop=el.scrollHeight;
}

// ── Panel toggle ───────────────────────────────────────────────────────────
function togglePanel(panelId, btnId){
    const panel=document.getElementById(panelId);
    const btn  =document.getElementById(btnId);
    const isOpen=panel.classList.contains('open');
    document.querySelectorAll('.panel').forEach(p=>p.classList.remove('open'));
    document.querySelectorAll('.icon-btn').forEach(b=>{
        if(!b.classList.contains('play-btn')&&!b.classList.contains('rec-btn'))
            b.classList.remove('active');
    });
    if(!isOpen){ panel.classList.add('open'); btn.classList.add('active'); }
}
function closePanel(panelId, btnId){
    document.getElementById(panelId).classList.remove('open');
    document.getElementById(btnId).classList.remove('active');
}

// ── Settings wiring ────────────────────────────────────────────────────────
document.getElementById('selFont').addEventListener('change',e=>C.font=e.target.value);
document.getElementById('selSize').addEventListener('change',e=>C.size=+e.target.value);
document.getElementById('selColor').addEventListener('input', e=>C.color=e.target.value);
document.getElementById('selColor').addEventListener('change',e=>C.color=e.target.value);
document.getElementById('selBgColor').addEventListener('input', e=>C.bgColor=e.target.value);
document.getElementById('selBgColor').addEventListener('change',e=>C.bgColor=e.target.value);
document.getElementById('selBgAlpha').addEventListener('input',e=>C.bgAlpha=e.target.value/100);
document.getElementById('selAnim').addEventListener('change',e=>{C.anim=e.target.value;startCaption(captionFull);});
document.getElementById('selAnimSpeed').addEventListener('change',e=>C.animSpeed=+e.target.value);
document.getElementById('selEffect').addEventListener('change',e=>{
    C.effect=e.target.value;
    document.getElementById('strokeColorField').style.display=
        (e.target.value==='outline'||e.target.value==='stroke')?'flex':'none';
});
document.getElementById('selStrokeColor').addEventListener('input',e=>C.strokeColor=e.target.value);
document.getElementById('selStrokeColor').addEventListener('change',e=>C.strokeColor=e.target.value);
document.getElementById('selKenBurns').addEventListener('change',e=>{
    const v=e.target.value;
    if(v!=='random'){S.kbEffect=v;S.kbStart=performance.now();}
});

function toggleStyle(s){
    C[s]=!C[s];
    document.getElementById('tog'+s.charAt(0).toUpperCase()+s.slice(1)).classList.toggle('on',C[s]);
}

function _capBoxHeight(){
    const fs=C.size, pad=10, lh=fs+7, maxW=CW-28;
    ctx.font=(C.italic?'italic ':'')+( C.bold?'bold ':'')+fs+'px '+C.font;
    const words=(captionShow||captionFull||'').split(' ');
    let lines=1, ln='';
    words.forEach(w=>{
        const t=ln?ln+' '+w:w;
        if(ctx.measureText(t).width>maxW&&ln){lines++;ln=w;}else ln=t;
    });
    return lines*lh+pad*2;
}
function _updatePosDisplay(){
    const xEl=document.getElementById('posX'),yEl=document.getElementById('posY');
    if(xEl)xEl.textContent=Math.round(C.posX);
    if(yEl)yEl.textContent=C.posY<0?'bottom':Math.round(C.posY);
}
function moveCaption(dx,dy){
    const bh=_capBoxHeight(), bw=CW-28;
    C.posX=Math.max(0,Math.min(CW-bw,C.posX+dx));
    if(C.posY<0) C.posY=CH-14-bh;
    C.posY=Math.max(0,Math.min(CH-bh,C.posY+dy));
    _updatePosDisplay();
}
function centreCaption(){
    const bh=_capBoxHeight(), bw=CW-28;
    C.posX=(CW-bw)/2; C.posY=(CH-bh)/2;
    _updatePosDisplay();
}
function setTA(a){
    C.textAlign=a;
    ['Left','Center','Right'].forEach(n=>document.getElementById('ta'+n).classList.toggle('on',n.toLowerCase()===a));
}
function setTextColor(hex){
    C.color=hex;
    const inp=document.getElementById('selColor');if(inp)inp.value=hex;
}

// ── Render ─────────────────────────────────────────────────────────────────
function startRender(){
    if(renderRaf)return;
    (function frame(){ drawFrame(); framesDrawn++; renderRaf=requestAnimationFrame(frame); })();
}

function drawFrame(){
    ctx.fillStyle='#000'; ctx.fillRect(0,0,CW,CH);
    if(S.imgOut&&S.alphaOut>0.01){ctx.save();ctx.globalAlpha=S.alphaOut;drawCover(S.imgOut,null);ctx.restore();}
    if(S.alpha>0.01){
        ctx.save();ctx.globalAlpha=S.alpha;
        if(S.txOffset){
            if(S.txOffset.x!=null)ctx.translate(S.txOffset.x,0);
            if(S.txOffset.scale){ctx.translate(CW/2,CH/2);ctx.scale(S.txOffset.scale,S.txOffset.scale);ctx.translate(-CW/2,-CH/2);}
        }
        if(S.type==='image'&&S.img)        drawCover(S.img,kbXform(S.kbEffect,S.kbStart,S.img));
        else if(S.type==='video'&&S.vidEl) try{ctx.drawImage(S.vidEl,0,0,CW,CH);}catch(_){}
        ctx.restore();
    }
    drawCaption();
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

function drawCaption(){
    if(!captionShow||!captionShow.trim())return;
    const fs=C.size,pad=10,lh=fs+7,maxW=CW-28;
    ctx.save();
    ctx.font=(C.italic?'italic ':'')+( C.bold?'bold ':'')+fs+'px '+C.font;
    const words=captionShow.split(' ');
    const lines=[];let ln='';
    words.forEach(w=>{
        const t=ln?ln+' '+w:w;
        if(ctx.measureText(t).width>maxW&&ln){lines.push(ln);ln=w;}else ln=t;
    });
    if(ln)lines.push(ln);
    const bh=lines.length*lh+pad*2,bw=CW-28;
    const bx=Math.max(0,Math.min(CW-bw,C.posX));
    const by=C.posY<0?CH-14-bh:Math.max(0,Math.min(CH-bh,C.posY));
    const br=parseInt(C.bgColor.slice(1,3),16),bg2=parseInt(C.bgColor.slice(3,5),16),bb=parseInt(C.bgColor.slice(5,7),16);
    ctx.fillStyle=`rgba(${br},${bg2},${bb},${C.bgAlpha})`;
    rrect(ctx,bx,by,bw,bh,10);ctx.fill();
    let tx,ta;
    if(C.textAlign==='left'){tx=bx+pad;ta='left';}
    else if(C.textAlign==='right'){tx=bx+bw-pad;ta='right';}
    else{tx=bx+bw/2;ta='center';}
    ctx.textAlign=ta;
    const fx=C.effect;
    let gradFill=null;
    if(fx==='gradient'){const gr=ctx.createLinearGradient(bx,0,bx+bw,0);gr.addColorStop(0,'#ff6b6b');gr.addColorStop(.33,'#ffd93d');gr.addColorStop(.66,'#6bcb77');gr.addColorStop(1,'#4d96ff');gradFill=gr;}
    lines.forEach((line,i)=>{
        const ty=by+pad+fs+i*lh;
        ctx.shadowBlur=0;ctx.shadowOffsetX=0;ctx.shadowOffsetY=0;ctx.strokeStyle='transparent';ctx.lineWidth=0;
        if(fx==='shadow'){ctx.shadowColor='rgba(0,0,0,.95)';ctx.shadowBlur=8;ctx.shadowOffsetX=2;ctx.shadowOffsetY=2;}
        else if(fx==='glow'){ctx.shadowColor=C.color;ctx.shadowBlur=22;}
        else if(fx==='3d'){ctx.shadowColor='rgba(0,0,0,.65)';ctx.shadowOffsetX=3;ctx.shadowOffsetY=3;}
        if(fx==='outline'||fx==='stroke'){ctx.shadowBlur=0;ctx.shadowOffsetX=0;ctx.shadowOffsetY=0;ctx.strokeStyle=C.strokeColor;ctx.lineWidth=C.strokeWidth*2;ctx.lineJoin='round';ctx.strokeText(line,tx,ty);}
        if(fx==='karaoke'&&i===0){
            ctx.globalAlpha=0.35;ctx.fillStyle=C.color;ctx.fillText(line,tx,ty);ctx.globalAlpha=1;ctx.shadowBlur=0;ctx.shadowOffsetX=0;ctx.shadowOffsetY=0;
            const kw=captionWords[captionKarIdx]||'';
            const before=captionWords.slice(0,captionKarIdx).join(' ')+(captionKarIdx>0?' ':'');
            let kx=ta==='center'?tx-ctx.measureText(line).width/2+ctx.measureText(before).width:ta==='right'?tx-ctx.measureText(line).width+ctx.measureText(before).width:tx+ctx.measureText(before).width;
            ctx.fillStyle='#FFD700';ctx.fillText(kw,kx+ctx.measureText(kw).width/2,ty);
        } else {ctx.fillStyle=gradFill||C.color;ctx.fillText(line,tx,ty);}
        if(C.underline){const tw=ctx.measureText(line).width,ux=ta==='center'?tx-tw/2:ta==='right'?tx-tw:tx;ctx.beginPath();ctx.moveTo(ux,ty+2);ctx.lineTo(ux+tw,ty+2);ctx.strokeStyle=C.color;ctx.lineWidth=1;ctx.stroke();}
    });
    ctx.restore();
}
function rrect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.lineTo(x+w-r,y);c.quadraticCurveTo(x+w,y,x+w,y+r);c.lineTo(x+w,y+h-r);c.quadraticCurveTo(x+w,y+h,x+w-r,y+h);c.lineTo(x+r,y+h);c.quadraticCurveTo(x,y+h,x,y+h-r);c.lineTo(x,y+r);c.quadraticCurveTo(x,y,x+r,y);c.closePath();}

function stopCaption(){if(captionTimer){clearInterval(captionTimer);clearTimeout(captionTimer);captionTimer=null;}}
function startCaption(text){
    stopCaption();captionShow='';captionFull=text;
    captionWords=text?text.split(' '):[];captionKarIdx=0;
    if(!text)return;
    const spd=C.animSpeed,style=C.anim;
    if(['static','fade-in','zoom-in','pop','bounce'].includes(style)){captionShow=text;return;}
    if(style==='typewriter'||style==='char-by-char'){
        let i=0;const ms=Math.round((style==='char-by-char'?60:36)/spd);
        captionTimer=setInterval(()=>{captionShow=text.substring(0,++i);if(i>=text.length){clearInterval(captionTimer);captionTimer=null;}},ms);return;
    }
    if(style==='word-reveal'){
        let wi=0;const ms=Math.round(140/spd);
        captionTimer=setInterval(()=>{captionShow=captionWords.slice(0,++wi).join(' ');if(wi>=captionWords.length){clearInterval(captionTimer);captionTimer=null;}},ms);return;
    }
    if(style==='line-by-line'){
        const chunk=6,chunks=[];
        for(let i=0;i<captionWords.length;i+=chunk)chunks.push(captionWords.slice(i,i+chunk).join(' '));
        let ci=0;captionShow=chunks[ci++]||'';
        const ms=Math.round(900/spd);
        captionTimer=setInterval(()=>{if(ci>=chunks.length){clearInterval(captionTimer);captionTimer=null;return;}captionShow=chunks[ci++];},ms);return;
    }
    if(style==='karaoke'){
        captionShow=text;captionKarIdx=0;
        const ms=Math.round(320/spd);
        captionTimer=setInterval(()=>{captionKarIdx++;if(captionKarIdx>=captionWords.length){clearInterval(captionTimer);captionTimer=null;}},ms);return;
    }
    captionShow=text;
}

// ── Preload ────────────────────────────────────────────────────────────────
async function preloadAll(){
    const slots=['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
    const imgFiles=[];
    SCENES.forEach(sc=>slots.forEach(k=>{
        const fn=(sc[k]||'').trim();
        if(fn&&!/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn)&&!imgFiles.includes(fn))imgFiles.push(fn);
    }));
    const vids=Object.values(vidEls);
    const total=imgFiles.length+vids.length;
    if(!total){L('Nothing to preload.','wrn');return;}
    L(`Preloading ${imgFiles.length} img + ${vids.length} vid…`,'inf');
    const bar=document.getElementById('preloadBar'),msg=document.getElementById('preloadMsg');
    let done=0;
    const upd=()=>{const p=Math.round(done/total*100);if(bar)bar.style.width=p+'%';if(msg)msg.textContent=`Loading ${p}%`;};
    const imgP=imgFiles.map(fn=>new Promise(res=>{
        const i=new Image();i.crossOrigin='anonymous';
        i.onload=()=>{imgCache[fn]=i;done++;upd();res();};
        i.onerror=()=>{done++;upd();res();};setTimeout(res,8000);i.src=IMG_BASE+fn;
    }));
    const vidP=vids.map(v=>new Promise(res=>{
        if(v.readyState>=3){done++;upd();res();return;}
        const ok=()=>{done++;upd();res();};
        v.addEventListener('canplaythrough',ok,{once:true});
        v.addEventListener('error',ok,{once:true});
        setTimeout(ok,30000);v.load();
    }));
    await Promise.all([...imgP,...vidP]);
    L('Preload done.','ok');
}

const SLOTS=['image_file','image_file_1','image_file_2','image_file_3','image_file_4'];
function sceneMedia(sc){
    for(const k of SLOTS){const v=(sc[k]||'').trim();if(!v)continue;return{fn:v,isVideo:/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(v)};}
    return{fn:null,isVideo:false};
}

function doTransition(type,dur){
    return new Promise(res=>{
        if(type==='none'){S.alpha=1;S.alphaOut=0;S.imgOut=null;S.txOffset=null;res();return;}
        const t0=performance.now();
        (function tick(now){
            const p=Math.min((now-t0)/dur,1),e=p<.5?2*p*p:1-Math.pow(-2*p+2,2)/2;
            S.alpha=e;S.alphaOut=1-e;
            if(type==='slide-left')      S.txOffset={x:CW*(1-e)};
            else if(type==='slide-right')S.txOffset={x:-CW*(1-e)};
            else if(type==='zoom-in')    S.txOffset={scale:0.65+0.35*e};
            else if(type==='zoom-out')   S.txOffset={scale:1.3-0.3*e};
            else                         S.txOffset=null;
            if(p<1)requestAnimationFrame(tick);
            else{S.alpha=1;S.alphaOut=0;S.imgOut=null;S.txOffset=null;res();}
        })(performance.now());
    });
}

async function showScene(index,instant){
    if(index<0||index>=SCENES.length||transitioning)return;
    transitioning=true;currentIndex=index;
    const sc=SCENES[index];
    const{fn,isVideo}=sceneMedia(sc);
    const txType=instant?'none':(document.getElementById('selTransition')?.value||'fade');
    const kbSetting=document.getElementById('selKenBurns')?.value||'random';
    const kbEff=kbSetting==='random'?sceneKB[index]:kbSetting;
    if(S.vidEl&&!(isVideo&&vidEls[fn]===S.vidEl))S.vidEl.pause();
    if(isVideo){
        const vl=vidEls[fn];
        if(!vl){L(`No vid: ${fn}`,'wrn');transitioning=false;return;}
        S.imgOut=S.img;S.alphaOut=instant?0:1;
        S.img=null;S.type='video';S.vidEl=vl;S.alpha=instant?1:0;S.txOffset=null;
        vl.currentTime=0;vl.play().catch(e=>L('vid:'+e.message,'wrn'));
        if(!instant)await doTransition(txType,T_DUR);
    } else {
        if(S.vidEl){S.vidEl.pause();S.vidEl=null;}
        const img=fn?imgCache[fn]:null;
        S.imgOut=S.img;S.alphaOut=instant?0:1;
        S.img=img;S.type=img?'image':'blank';S.vidEl=null;
        S.alpha=instant?1:0;S.txOffset=null;
        S.kbEffect=kbEff;S.kbStart=performance.now();
        if(!instant)await doTransition(txType,T_DUR);
    }
    const raw=(sc.text_contents||sc.text_display||'')
        .replace(/<break[^>]*>/gi,'').replace(/^(HOST|GUEST)\s*:\s*/i,'').trim();
    startCaption(raw);
    updateSlotThumbs(sc);
    const _ta=document.getElementById('slotPrompt');
    if(_ta)_ta.value=sc[SLOT_PROMPT_MAP[activeSlot]]||sc.prompt||'';
    document.getElementById('sceneAudioInfo').textContent=sc.audio_file?`🎵 ${sc.audio_file}`:'No voiceover';
    document.getElementById('sceneNum').textContent=(index+1)+' / '+SCENES.length;
    updateDots(index);updateNavButtons();
    L(`Scene ${index+1} [${kbEff}]: "${raw.substring(0,42)}"`, 'ok');
    transitioning=false;
}

function updateDots(i){document.querySelectorAll('.dot').forEach((d,j)=>d.className='dot'+(j===i?' active':''));}
function navigate(dir){if(!isPlaying&&!transitioning&&!isRecording)showScene(currentIndex+dir,false);}
function updateNavButtons(){
    const b=isPlaying||isRecording;
    const btns=document.querySelectorAll('.nav-btn');
    if(btns[0])btns[0].disabled=b||currentIndex===0;
    if(btns[1])btns[1].disabled=b||currentIndex===SCENES.length-1;
}

async function togglePlay(){
    if(isPlaying){stopPlay();return;}
    isPlaying=true;
    document.getElementById('playIco').textContent='⏹';
    document.getElementById('playLbl').textContent='Stop';
    if(bgAudio){bgAudio.currentTime=0;bgAudio.play().catch(()=>{});}
    for(let i=currentIndex;i<SCENES.length;i++){
        if(!isPlaying)break;
        await showScene(i,false);
        const af=SCENES[i].audio_file;
        if(af)await playAudio(AUD_BASE+af);
        else  await sleep((parseInt(SCENES[i].duration)||3)*1000);
        if(!isPlaying)break;
    }
    stopPlay();
}
function playAudio(src){
    return new Promise(res=>{
        if(currentAudio){currentAudio.pause();currentAudio=null;}
        const a=new Audio();currentAudio=a;
        if(window._recDest){try{const s=window._recActx.createMediaElementSource(a);s.connect(window._recDest);s.connect(window._recActx.destination);}catch(_){}}
        a.src=src+'?t='+Date.now();
        a.onended=res;a.onerror=()=>sleep(200).then(res);
        a.play().catch(()=>sleep(200).then(res));
        setTimeout(res,30000);
    });
}
function stopPlay(){
    isPlaying=false;
    if(currentAudio){currentAudio.pause();currentAudio=null;}
    if(S.vidEl)S.vidEl.pause();
    if(bgAudio)bgAudio.pause();
    stopCaption();
    document.getElementById('playIco').textContent='▶';
    document.getElementById('playLbl').textContent='Play';
    updateNavButtons();
}

// ── Recording ──────────────────────────────────────────────────────────────
let mr=null,recChunks=[],recBlob=null,recURL=null;
async function startRecording(){
    discardRec();
    await new Promise(res=>{const chk=()=>framesDrawn>=5?res():requestAnimationFrame(chk);chk();});
    let stream;
    try{stream=canvas.captureStream(30);}catch(e){L('captureStream: '+e.message,'err');return;}
    try{
        const actx=new(window.AudioContext||window.webkitAudioContext)();
        const dest=actx.createMediaStreamDestination();
        window._recActx=actx;window._recDest=dest;
        Object.values(vidEls).forEach(v=>{try{actx.createMediaElementSource(v).connect(dest);}catch(_){}});
        if(bgAudio){try{actx.createMediaElementSource(bgAudio).connect(dest);}catch(_){}}
        dest.stream.getAudioTracks().forEach(t=>stream.addTrack(t));
    }catch(e){L('Audio: '+e.message,'wrn');}
    const MIME='video/webm;codecs=vp9,opus';
    recChunks=[];
    try{mr=new MediaRecorder(stream,{mimeType:MIME,videoBitsPerSecond:4000000});}catch(e){L('MR: '+e.message,'err');return;}
    mr.ondataavailable=e=>{
        if(e.data&&e.data.size>0)recChunks.push(e.data);
        document.getElementById('recSize').textContent=(recChunks.reduce((s,c)=>s+c.size,0)/1024/1024).toFixed(1)+' MB';
    };
    mr.onstop=()=>{
        recBlob=new Blob(recChunks,{type:MIME});recURL=URL.createObjectURL(recBlob);
        const mb=(recBlob.size/1024/1024).toFixed(2);
        const fname=`podcast_${PODCAST_ID}_${Date.now()}.webm`;
        document.getElementById('dlLink').href=recURL;document.getElementById('dlLink').download=fname;
        document.getElementById('dlMeta').textContent=`WebM VP9 · ${mb} MB`;
        document.getElementById('dlPanel').classList.add('on');
        document.getElementById('recBar').classList.remove('on');
        canvas.classList.remove('recording');
        isRecording=false;
        document.getElementById('ibRecord').innerHTML='<span class="ico">⏺</span>Record';
        window._recActx=null;window._recDest=null;
        updateNavButtons();L(`Done — ${mb} MB`,'ok');
    };
    await new Promise(res=>requestAnimationFrame(res));
    mr.start(500);isRecording=true;
    canvas.classList.add('recording');
    document.getElementById('recBar').classList.add('on');
    document.getElementById('dlPanel').classList.remove('on');
    document.getElementById('ibRecord').innerHTML='<span class="ico">⏹</span>Stop Rec';
    updateNavButtons();L('Recording…','inf');
    currentIndex=0;await showScene(0,true);await togglePlay();
    if(isRecording&&mr&&mr.state!=='inactive')mr.stop();
}
function discardRec(){
    if(recURL){URL.revokeObjectURL(recURL);recURL=null;}
    recBlob=null;recChunks=[];
    document.getElementById('dlPanel').classList.remove('on');
}

// ── Image panel ────────────────────────────────────────────────────────────
const SLOT_PROMPT_MAP = {
    image_file:'prompt', image_file_1:'prompt_1',
    image_file_2:'prompt_2', image_file_3:'prompt_3', image_file_4:'prompt_4'
};
const SLOT_LABELS = {image_file:'Main',image_file_1:'V1',image_file_2:'V2',image_file_3:'V3',image_file_4:'V4'};

function updateSlotThumbs(sc){
    SLOTS.forEach(k=>{
        const fn=(sc[k]||'').trim();
        const img=document.getElementById('slotImg_'+k);
        const ph =document.getElementById('slotPh_'+k);
        const th =document.getElementById('slotThumb_'+k);
        if(fn){
            const isVid=/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);
            if(!isVid&&img){img.src=IMG_BASE+fn+'?t='+Date.now();img.style.display='block';if(ph)ph.style.display='none';}
            else{if(img)img.style.display='none';if(ph){ph.textContent='🎬';ph.style.display='flex';}}
        } else {
            if(img)img.style.display='none';if(ph){ph.textContent='🖼️';ph.style.display='flex';}
        }
        if(th)th.classList.toggle('active-slot',k===activeSlot);
    });
}

function selectSlot(slot){
    activeSlot=slot;
    SLOTS.forEach(k=>{const t=document.getElementById('slotThumb_'+k);if(t)t.classList.toggle('active-slot',k===slot);});
    const lbl=document.getElementById('selSlotName');if(lbl)lbl.textContent=SLOT_LABELS[slot]||slot;
    const sc=SCENES[currentIndex];
    const ta=document.getElementById('slotPrompt');
    if(ta)ta.value=sc[SLOT_PROMPT_MAP[slot]]||sc.prompt||'';
    L(`Slot: ${slot}`,'inf');
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

function uploadForSlot(){
    const inp=document.getElementById('slotFileInput');if(inp){inp.value='';inp.click();}
}

async function handleSlotUpload(input){
    if(!input.files||!input.files[0])return;
    const file=input.files[0];
    const sc=SCENES[currentIndex];
    const isVid=file.type.startsWith('video/');
    L('Uploading…','inf');
    const fd=new FormData();
    fd.append('ajax_action','upload_scene_image');fd.append('scene_id',sc.id);
    fd.append('image_field',activeSlot);fd.append('media_type',isVid?'video':'image');fd.append('scene_image',file);
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(!data.success)throw new Error(data.message||'Upload failed');
        sc[activeSlot]=data.filename;
        if(!isVid){
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[data.filename]=img;showScene(currentIndex,true);};
            img.onerror=()=>showScene(currentIndex,true);
            img.src=IMG_BASE+data.filename+'?t='+Date.now();
        } else { showScene(currentIndex,true); }
        updateSlotThumbs(sc);L('Uploaded: '+data.filename,'ok');
    }catch(e){L('Upload failed: '+e.message,'err');}
}

// ═══════════════════════════════════════════════════════════════════════════
// LIBRARY MODAL — semantic search using scene natural_language_tags + embeddings
// ═══════════════════════════════════════════════════════════════════════════
let _libImgs = [], _libVids = [], _libSelectedFile = null, _libTab = 'all';

function openLibraryModal(){
    const modal = document.getElementById('libModal');
    if(modal) modal.style.display = 'flex';

    const lbl = document.getElementById('libSlotLabel');
    if(lbl) lbl.textContent = SLOT_LABELS[activeSlot] || activeSlot;

    _libSelectedFile = null;
    _resetLibSel();

    // Auto-populate search from scene's natural_language_tags
    const sc = SCENES[currentIndex];
    const sceneTags = (sc.natural_language_tags || sc.hashtags || '').trim();
    const searchInput = document.getElementById('libSearch');
    if(searchInput) searchInput.value = sceneTags ? sceneTags.split('|')[0] : '';

    // Show status
    const status = document.getElementById('libSearchStatus');
    if(status){
        status.style.display = 'block';
        status.textContent = sceneTags
            ? `🔍 Auto-matching: ${sceneTags.split('|').slice(0,3).join(' · ')}…`
            : '📂 Loading recent media…';
    }

    if(sceneTags){
        _performLibSearchWithQuery(sceneTags);
    } else {
        _loadRecentLibFiles();
    }
}

function closeLibraryModal(){
    const modal = document.getElementById('libModal');
    if(modal) modal.style.display = 'none';
    _libSelectedFile = null;
}

function _resetLibSel(){
    const useBtn = document.getElementById('libUseBtn');
    if(useBtn){ useBtn.disabled=true; useBtn.style.opacity='.4'; }
    const info = document.getElementById('libSelInfo');
    if(info) info.textContent = 'No file selected';
}

function setLibTab(type){
    _libTab = type;
    ['All','Img','Vid'].forEach(n=>{
        const btn = document.getElementById('libTab'+n);
        if(!btn) return;
        const isOn = (n==='All'&&type==='all')||(n==='Img'&&type==='image')||(n==='Vid'&&type==='video');
        btn.style.background    = isOn ? 'var(--primary)' : 'var(--surface2)';
        btn.style.borderColor   = isOn ? 'var(--primary)' : 'var(--border)';
        btn.style.color         = isOn ? '#fff' : 'var(--muted)';
    });
    _renderLibGrid();
}

function _updateLibCounts(){
    const all = document.getElementById('libCountAll');
    const img = document.getElementById('libCountImg');
    const vid = document.getElementById('libCountVid');
    const total = _libImgs.length + _libVids.length;
    if(all) all.textContent = total;
    if(img) img.textContent = _libImgs.length;
    if(vid) vid.textContent = _libVids.length;
}

// Perform search from user-typed input
async function performLibSearch(){
    const query = (document.getElementById('libSearch')?.value || '').trim();
    if(!query){ _loadRecentLibFiles(); return; }
    await _performLibSearchWithQuery(query);
}

// Core search: calls search_media_nl for both images and videos
async function _performLibSearchWithQuery(query){
    const grid = document.getElementById('libGrid');
    if(grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Searching with AI…</div>';

    const status = document.getElementById('libSearchStatus');
    if(status){ status.style.display='block'; status.textContent=`Searching: "${query.substring(0,60)}"…`; }

    try {
        // Search images
        const fdI = new FormData();
        fdI.append('ajax_action','search_media_nl');
        fdI.append('query', query);
        fdI.append('media_type_filter','image');
        const rI = await fetch(location.href,{method:'POST',body:fdI});
        const imgResults = await rI.json().catch(()=>[]);

        // Search videos
        const fdV = new FormData();
        fdV.append('ajax_action','search_media_nl');
        fdV.append('query', query);
        fdV.append('media_type_filter','video');
        const rV = await fetch(location.href,{method:'POST',body:fdV});
        const vidResults = await rV.json().catch(()=>[]);

        _libImgs = (Array.isArray(imgResults)?imgResults:[]).map(r=>({
            filename:     r.filename,
            media_type:   'image',
            nl_tags:      r.nl_tags      || '',
            matched_line: r.matched_line  || '',
            matched_segment: r.matched_segment || '',
            score:        r.score         || 0,
            thumbnail:    r.thumbnail     || '',
        }));
        _libVids = (Array.isArray(vidResults)?vidResults:[]).map(r=>({
            filename:     r.filename,
            media_type:   'video',
            nl_tags:      r.nl_tags      || '',
            matched_line: r.matched_line  || '',
            matched_segment: r.matched_segment || '',
            score:        r.score         || 0,
            thumbnail:    r.thumbnail     || '',
        }));

        _updateLibCounts();
        _renderLibGrid();

        const total = _libImgs.length + _libVids.length;
        if(status){
            status.style.display = 'block';
            if(total > 0){
                const allR = [..._libImgs,..._libVids];
                const hi   = allR.filter(f=>f.score>=0.5).length;
                const med  = allR.filter(f=>f.score>=0.35&&f.score<0.5).length;
                const lo   = allR.filter(f=>f.score>0&&f.score<0.35).length;
                const zt   = allR.filter(f=>f.score===0).length;
                status.innerHTML =
                    `✅ ${_libImgs.length} images · ${_libVids.length} videos`
                    +` — "${query.substring(0,40)}"&nbsp;&nbsp;`
                    +(hi  ?`<span style="background:#dcfce7;color:#166534;padding:1px 6px;border-radius:8px;font-size:10px;">🟢 ${hi}</span> ` :'')
                    +(med ?`<span style="background:#fef9c3;color:#854d0e;padding:1px 6px;border-radius:8px;font-size:10px;">🟡 ${med}</span> ` :'')
                    +(lo  ?`<span style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:8px;font-size:10px;">🔴 ${lo}</span> ` :'')
                    +(zt  ?`<span style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:8px;font-size:10px;">⚪ ${zt}</span>` :'');
            } else {
                status.textContent = '❌ No results found';
            }
        }
    } catch(e){
        L('Library search error: '+e.message,'err');
        if(status) status.textContent = '⚠️ Search failed — loading recent files';
        _loadRecentLibFiles();
    }
}

// Fallback: load recent files without embedding search
async function _loadRecentLibFiles(){
    const grid = document.getElementById('libGrid');
    if(grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    try {
        const fd = new FormData();
        fd.append('ajax_action','get_library_files');
        const r   = await fetch(location.href,{method:'POST',body:fd});
        const data= await r.json();
        const files = data.files || [];
        _libImgs = files.filter(f=>f.media_type!=='video').map(f=>({
            filename:   f.filename, media_type:'image',
            nl_tags:    f.natural_language_tags||'', matched_line:'', matched_segment:'', score:0, thumbnail:''
        }));
        _libVids = files.filter(f=>f.media_type==='video').map(f=>({
            filename:   f.filename, media_type:'video',
            nl_tags:    f.natural_language_tags||'', matched_line:'', matched_segment:'', score:0, thumbnail:''
        }));
        _updateLibCounts();
        _renderLibGrid();
        const status = document.getElementById('libSearchStatus');
        if(status){ status.style.display='block'; status.textContent='Showing recent media — type above to search'; }
    } catch(e){
        if(grid) grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Failed to load files</div>';
    }
}

// Render the grid based on active tab
function _renderLibGrid(){
    const grid = document.getElementById('libGrid');
    if(!grid) return;

    const files = _libTab==='video' ? _libVids
                : _libTab==='image' ? _libImgs
                : [..._libImgs, ..._libVids];

    if(!files.length){
        grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);">
            <div style="font-size:36px;margin-bottom:10px;">${_libTab==='video'?'🎬':'🖼️'}</div>
            <div style="font-size:13px;font-weight:600;">No ${_libTab==='all'?'media':_libTab+'s'} found</div>
            <div style="font-size:11px;margin-top:4px;">Try a different search term</div>
        </div>`;
        return;
    }

    grid.innerHTML = files.map(f => {
        const isVid = f.media_type === 'video';
        const score = f.score || 0;

        // Score colors
        let borderC, scoreBg, scoreClr, qlabel;
        if     (score>=0.5)  { borderC='#10b981'; scoreBg='#dcfce7'; scoreClr='#166534'; qlabel='🟢'; }
        else if(score>=0.35) { borderC='#f59e0b'; scoreBg='#fef9c3'; scoreClr='#854d0e'; qlabel='🟡'; }
        else if(score>0)     { borderC='#ef4444'; scoreBg='#fee2e2'; scoreClr='#991b1b'; qlabel='🔴'; }
        else                 { borderC='#e2e8f0'; scoreBg='#f1f5f9'; scoreClr='#64748b'; qlabel=''; }

        const scoreBadge = score>0
            ? `<div style="position:absolute;top:5px;right:5px;background:${scoreBg};color:${scoreClr};padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;z-index:10;">${qlabel} ${Math.round(score*100)}%</div>`
            : '';
        const vidBadge = isVid
            ? `<div style="position:absolute;top:5px;left:5px;background:rgba(0,0,0,.65);color:#fff;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:600;">🎬</div>`
            : '';

        // Thumbnail
        const thumb = (f.thumbnail||'').trim();
        let mediaHtml;
        if(isVid){
            mediaHtml = thumb
                ? `<div style="position:relative;width:100%;height:110px;overflow:hidden;">
                    <img src="podcast_thumbnails/${thumb}" style="width:100%;height:110px;object-fit:cover;" loading="lazy"
                         onerror="this.style.display='none';this.nextSibling.style.display='flex'">
                    <div style="display:none;width:100%;height:110px;background:linear-gradient(135deg,#0f172a,#1e3a5f);align-items:center;justify-content:center;font-size:30px;">🎬</div>
                   </div>`
                : `<div style="width:100%;height:110px;background:linear-gradient(135deg,#0f172a,#1e3a5f);display:flex;align-items:center;justify-content:center;font-size:30px;">🎬</div>`;
        } else {
            const src = thumb ? `podcast_thumbnails/${thumb}` : `podcast_images/${f.filename}`;
            mediaHtml = `<div style="position:relative;width:100%;height:110px;overflow:hidden;">
                <img src="${src}" data-orig="podcast_images/${f.filename}"
                     style="width:100%;height:110px;object-fit:cover;display:block;" loading="lazy"
                     onerror="if(this.src.indexOf('podcast_thumbnails')!==-1){this.src=this.dataset.orig;}else{this.style.display='none';this.nextSibling.style.display='flex';}">
                <div style="display:none;width:100%;height:110px;background:#e2e8f0;align-items:center;justify-content:center;font-size:22px;color:#94a3b8;">🖼️</div>
               </div>`;
        }

        // Matched tag label
        const seg  = (f.matched_segment||'').trim();
        const line = (f.matched_line||'').trim();
        const tagHtml = (seg||line)
            ? `<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#475569;line-height:1.4;overflow:hidden;max-height:36px;">
                ${seg?`<span style="color:#0369a1;font-weight:600;">${seg.substring(0,44)}</span>`:''}
                ${line&&line!==seg?`<br><span style="color:#64748b;">${line.substring(0,48)}</span>`:''}
               </div>`
            : `<div style="padding:4px 6px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:9px;color:#94a3b8;">${f.filename.substring(0,30)}</div>`;

        return `<div onclick="pickLibFile(this,'${f.filename}','${f.media_type}')"
                     style="position:relative;border:2px solid ${borderC};border-radius:10px;overflow:hidden;cursor:pointer;background:white;transition:border-color .15s,box-shadow .15s;display:flex;flex-direction:column;">
                    ${mediaHtml}
                    ${scoreBadge}
                    ${vidBadge}
                    <div class="media-check" style="position:absolute;top:5px;left:5px;background:#10b981;color:white;width:22px;height:22px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:13px;font-weight:700;z-index:20;">✓</div>
                    ${tagHtml}
                </div>`;
    }).join('');
}

function pickLibFile(el, filename, type){
    document.querySelectorAll('#libGrid > div').forEach(d=>{
        d.style.borderColor='var(--border)';
        const chk=d.querySelector('.media-check');if(chk)chk.style.display='none';
    });
    // Restore correct score border
    el.style.borderColor='var(--info)';
    const chk=el.querySelector('.media-check');if(chk)chk.style.display='flex';

    _libSelectedFile={filename,type};
    const info=document.getElementById('libSelInfo');if(info)info.textContent=filename;
    const btn=document.getElementById('libUseBtn');if(btn){btn.disabled=false;btn.style.opacity='1';}
}

async function useLibraryFile(){
    if(!_libSelectedFile)return;
    const {filename,type}=_libSelectedFile;
    const sc=SCENES[currentIndex];
    const fd=new FormData();
    fd.append('ajax_action','assign_image');fd.append('scene_id',sc.id);
    fd.append('filename',filename);fd.append('image_field',activeSlot);
    const r=await fetch(location.href,{method:'POST',body:fd});
    const data=await r.json();
    if(data.success){
        sc[activeSlot]=filename;
        const isVid=type==='video'||/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);
        if(!isVid){
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[filename]=img;showScene(currentIndex,true);};
            img.src=IMG_BASE+filename+'?t='+Date.now();
        } else { showScene(currentIndex,true); }
        updateSlotThumbs(sc);
        closeLibraryModal();
        L('Assigned: '+filename,'ok');
    } else { L('Assign failed','err'); }
}

// ── Generate image ─────────────────────────────────────────────────────────
async function generateForSlot(){
    const sc=SCENES[currentIndex];
    const ta=document.getElementById('slotPrompt');
    const prompt=(ta?.value.trim())||sc[SLOT_PROMPT_MAP[activeSlot]]||sc.prompt||'';
    if(!prompt){alert('No prompt for this slot. Enter a prompt first.');return;}
    const btn=document.getElementById('btnGenerate');
    if(btn)btn.innerHTML='<span style="font-size:20px;">⏳</span>Generating…';
    L('Generating image…','inf');
    const fd=new FormData();
    fd.append('ajax_action','generate_image');fd.append('scene_id',sc.id);
    fd.append('image_field',activeSlot);fd.append('enhanced_prompt',prompt);fd.append('hashtags','');
    try{
        const r=await fetch(location.href,{method:'POST',body:fd});
        const data=await r.json();
        if(!data.success)throw new Error(data.message||'Generation failed');
        const fn=data.image_name||data.filename;
        if(!fn)throw new Error('No filename returned');
        sc[activeSlot]=fn;
        await new Promise(res=>{
            const img=new Image();img.crossOrigin='anonymous';
            img.onload=()=>{imgCache[fn]=img;res();};img.onerror=res;
            setTimeout(res,10000);img.src=IMG_BASE+fn+'?nocache='+Date.now();
        });
        showScene(currentIndex,true);updateSlotThumbs(sc);
        L('Generated: '+fn,'ok');
    }catch(e){L('Generate failed: '+e.message,'err');}
    finally{if(btn)btn.innerHTML='<span style="font-size:20px;">🔄</span>Generate';}
}

// ── Audio panel ────────────────────────────────────────────────────────────
async function loadAudioLists(){
    const sc=SCENES[currentIndex];
    const fd=new FormData();fd.append('ajax_action','list_audio');
    const r=await fetch(location.href,{method:'POST',body:fd});
    const files=await r.json();
    document.getElementById('audioList').innerHTML=files.length
        ? files.map(f=>{
            const isCur=f.name===(sc.audio_file||'');
            return `<div class="audio-row${isCur?' current':''}">
                <span class="aname" title="${f.name}">${f.name}</span>
                <span class="asize">${(f.size/1024).toFixed(0)}k</span>
                <button class="aplay-btn" onclick="previewAudioFile('${AUD_BASE+f.name}',this)">▶</button>
                <button class="ause-btn" onclick="assignSceneAudio('${f.name}')">Use</button>
            </div>`;}).join('')
        : '<p style="font-size:11px;color:var(--muted);padding:8px;">No audio files</p>';
    const fd2=new FormData();fd2.append('ajax_action','list_music');
    const r2=await fetch(location.href,{method:'POST',body:fd2});
    const mfiles=await r2.json();
    const curMusic='<?= addslashes($podcast_music) ?>';
    document.getElementById('musicList').innerHTML=mfiles.length
        ? mfiles.map(f=>{
            const isCur=f.name===curMusic;
            return `<div class="audio-row${isCur?' current':''}">
                <span class="aname" title="${f.name}">${f.name}</span>
                <span class="asize">${(f.size/1024).toFixed(0)}k</span>
                <button class="aplay-btn" onclick="previewAudioFile('${MUS_BASE+f.name}',this)">▶</button>
                <button class="ause-btn" onclick="assignMusic('${f.name}')">Use</button>
            </div>`;}).join('')
        : '<p style="font-size:11px;color:var(--muted);padding:8px;">No music files</p>';
    document.getElementById('currentMusicInfo').textContent=curMusic?`🎵 ${curMusic}`:'No background music';
}
function previewAudioFile(src,btn){
    if(previewAudio){previewAudio.pause();previewAudio=null;document.querySelectorAll('.aplay-btn').forEach(b=>b.textContent='▶');}
    if(btn.textContent==='⏹'){btn.textContent='▶';return;}
    previewAudio=new Audio(src+'?t='+Date.now());
    previewAudio.onended=()=>{btn.textContent='▶';};
    previewAudio.play().catch(()=>{});btn.textContent='⏹';
}
async function assignSceneAudio(fn){
    const sc=SCENES[currentIndex];
    const fd=new FormData();fd.append('ajax_action','update_scene_audio');fd.append('scene_id',sc.id);fd.append('audio_file',fn);
    const r=await fetch(location.href,{method:'POST',body:fd});
    const j=await r.json();
    if(j.success){sc.audio_file=fn;document.getElementById('sceneAudioInfo').textContent='🎵 '+fn;loadAudioLists();L(`Audio → ${fn}`,'ok');}
}
async function assignMusic(fn){
    const fd=new FormData();fd.append('ajax_action','update_podcast_music');fd.append('music_file',fn);
    await fetch(location.href,{method:'POST',body:fd});
    if(bgAudio)bgAudio.pause();
    bgAudio=new Audio(MUS_BASE+fn+'?t='+Date.now());
    bgAudio.loop=true;bgAudio.volume=bgMusicVolume;
    loadAudioLists();L(`Music → ${fn}`,'ok');
}

function sleep(ms){return new Promise(r=>setTimeout(r,ms));}

(async function boot(){
    startRender();
    await preloadAll();
    await showScene(0,true);
    const ov=document.getElementById('preloadOverlay');
    if(ov){ov.style.transition='opacity .5s ease';ov.style.opacity='0';setTimeout(()=>ov.classList.add('gone'),550);}
    updateNavButtons();
    L('Ready ✓','ok');
})();
</script>
</body>
</html>
