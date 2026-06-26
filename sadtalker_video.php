<?php
$API_URL = "https://inaamalvi1--sadtalker-app-fastapi-app.modal.run/generate";

$VIDEO_DIR = __DIR__ . '/generated_videos';
if (!is_dir($VIDEO_DIR)) mkdir($VIDEO_DIR, 0755, true);
foreach (glob("$VIDEO_DIR/*.mp4") as $f) { if (filemtime($f) < time() - 3600) @unlink($f); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
    set_time_limit(800);
    ini_set('max_execution_time', 800);

    try {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw new Exception("Image upload failed");
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) throw new Exception("Audio upload failed");

        $img = sys_get_temp_dir().'/'.uniqid('img_').'.png';
        $aud = sys_get_temp_dir().'/'.uniqid('aud_').'.mp3';
        move_uploaded_file($_FILES['image']['tmp_name'], $img);
        move_uploaded_file($_FILES['audio']['tmp_name'], $aud);

        $quality = $_POST['quality'] ?? 'high';
        $settings = [
            'medium' => ['size'=>'256','enhancer'=>'false','batch'=>'10'],
            'high'   => ['size'=>'256','enhancer'=>'false','batch'=>'8'],
            'ultra'  => ['size'=>'256','enhancer'=>'true', 'batch'=>'6']
        ];
        $cfg = $settings[$quality] ?? $settings['high'];

        $motion_intensity = $_POST['motion_intensity'] ?? '1.0';
        $head_motion = ($_POST['head_motion'] ?? 'true') === 'true' ? 'true' : 'false';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $API_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'source_image'     => new CURLFile($img),
                'driven_audio'     => new CURLFile($aud),
                'preprocess'       => 'full',
                'still_mode'       => 'true',
                'use_enhancer'     => $cfg['enhancer'],
                'size'             => $cfg['size'],
                'batch_size'       => $cfg['batch'],
                'head_motion'      => $head_motion,
                'motion_intensity' => $motion_intensity,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 60,
        ]);

        $video_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        @unlink($img); @unlink($aud);

        if ($curl_error) throw new Exception("Connection: $curl_error");
        if ($http_code !== 200) {
            $err = @json_decode($video_data, true);
            throw new Exception($err['detail'] ?? "HTTP $http_code");
        }
        if (strlen($video_data) < 5000) {
            $err = @json_decode($video_data, true);
            if ($err) throw new Exception($err['detail'] ?? 'Too small');
        }

        $fn = 'video_'.uniqid().'.mp4';
        file_put_contents("$VIDEO_DIR/$fn", $video_data);

        echo json_encode(['success'=>true,'filename'=>$fn,'size_mb'=>round(strlen($video_data)/1048576,2)]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if (isset($_GET['download'])) {
    $fn = basename($_GET['download']);
    $fp = "$VIDEO_DIR/$fn";
    if (file_exists($fp) && pathinfo($fp, PATHINFO_EXTENSION)==='mp4') {
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="talking_video.mp4"');
        header('Content-Length: '.filesize($fp));
        ob_end_clean(); readfile($fp); exit;
    }
    http_response_code(404); die('Not found');
}

if (isset($_GET['preview'])) {
    $fn = basename($_GET['preview']);
    $fp = "$VIDEO_DIR/$fn";
    if (file_exists($fp) && pathinfo($fp, PATHINFO_EXTENSION)==='mp4') {
        header('Content-Type: video/mp4');
        header('Content-Length: '.filesize($fp));
        header('Accept-Ranges: bytes');
        ob_end_clean(); readfile($fp); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talking Video Generator</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
        .container{background:#fff;border-radius:20px;box-shadow:0 20px 60px rgba(0,0,0,.3);padding:40px;max-width:620px;width:100%}
        h1{text-align:center;color:#333;margin-bottom:8px;font-size:26px}
        .sub{text-align:center;color:#888;font-size:13px;margin-bottom:28px}
        .form-group{margin-bottom:22px}
        label{display:block;margin-bottom:6px;color:#333;font-weight:600;font-size:14px}
        input[type="file"]{width:100%;padding:12px;border:2px dashed #ddd;border-radius:8px;cursor:pointer;background:#f9f9f9;transition:.3s}
        input[type="file"]:hover{border-color:#667eea;background:#f0f0ff}
        .grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
        .opt input[type="radio"]{position:absolute;opacity:0}
        .opt label{display:block;padding:13px 8px;text-align:center;border:2px solid #ddd;border-radius:8px;cursor:pointer;transition:.3s}
        .opt input:checked+label{background:#667eea;border-color:#667eea;color:#fff}
        .range-row{display:flex;align-items:center;gap:12px}
        .range-row input[type="range"]{flex:1}
        .range-val{min-width:32px;text-align:center;font-weight:600;color:#667eea}
        .toggle{display:flex;align-items:center;gap:10px;margin-bottom:20px}
        .switch{position:relative;width:48px;height:26px}
        .switch input{opacity:0;width:0;height:0}
        .slider{position:absolute;inset:0;background:#ccc;border-radius:26px;cursor:pointer;transition:.3s}
        .slider:before{content:'';position:absolute;height:20px;width:20px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
        .switch input:checked+.slider{background:#667eea}
        .switch input:checked+.slider:before{transform:translateX(22px)}
        button[type="submit"]{width:100%;padding:15px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;transition:.3s}
        button[type="submit"]:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(102,126,234,.4)}
        .msg{padding:14px;border-radius:8px;margin-bottom:18px;display:none}
        .msg.show{display:block}
        .msg.error{background:#ffebee;border:2px solid #ef5350;color:#c62828}
        .loading{display:none;text-align:center;padding:30px}
        .loading.show{display:block}
        .spinner{border:4px solid #f3f3f3;border-top:4px solid #667eea;border-radius:50%;width:56px;height:56px;animation:spin 1s linear infinite;margin:0 auto 16px}
        @keyframes spin{to{transform:rotate(360deg)}}
        .timer{font-size:22px;font-weight:700;color:#667eea;margin:8px 0}
        .result{display:none;text-align:center;padding:20px}
        .result.show{display:block}
        .video-box{width:100%;max-width:480px;border-radius:12px;margin:12px auto;display:block;box-shadow:0 4px 20px rgba(0,0,0,.2)}
        .btns{display:flex;gap:12px;justify-content:center;margin-top:18px;flex-wrap:wrap}
        .dl{display:inline-block;padding:13px 28px;background:#4CAF50;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;transition:.3s}
        .dl:hover{background:#45a049}
        .again{padding:13px 28px;background:#667eea;color:#fff;border-radius:8px;font-weight:600;cursor:pointer;border:none;font-size:14px}
        .info{background:#e3f2fd;border:2px solid #90caf9;color:#1565c0;padding:14px;border-radius:8px;margin-top:18px;font-size:13px;line-height:1.6}
    </style>
</head>
<body>
<div class="container">
    <h1>🎬 Talking Video Generator</h1>
    <p class="sub">Full image • Head motion • Lip sync</p>

    <div id="msg" class="msg"></div>

    <form id="form">
        <div class="form-group">
            <label>📷 Image</label>
            <input type="file" id="imageFile" accept="image/*" required>
        </div>
        <div class="form-group">
            <label>🎵 Audio</label>
            <input type="file" id="audioFile" accept="audio/*" required>
        </div>

        <div class="form-group">
            <label>⚙️ Quality</label>
            <div class="grid3">
                <div class="opt"><input type="radio" name="quality" id="medium" value="medium"><label for="medium"><strong>Fast</strong><br><small>~90s</small></label></div>
                <div class="opt"><input type="radio" name="quality" id="high" value="high" checked><label for="high"><strong>Good ⭐</strong><br><small>~120s</small></label></div>
                <div class="opt"><input type="radio" name="quality" id="ultra" value="ultra"><label for="ultra"><strong>Ultra</strong><br><small>~180s</small></label></div>
            </div>
        </div>

        <div class="toggle">
            <label class="switch">
                <input type="checkbox" id="headMotion" checked>
                <span class="slider"></span>
            </label>
            <span style="font-weight:600;font-size:14px">🎭 Head Motion</span>
            <span style="color:#888;font-size:12px">(natural nodding & swaying)</span>
        </div>

        <div class="form-group" id="intensityGroup">
            <label>💫 Motion Intensity</label>
            <div class="grid3">
                <div class="opt"><input type="radio" name="intensity" id="subtle" value="0.5"><label for="subtle"><strong>Subtle</strong><br><small>Gentle</small></label></div>
                <div class="opt"><input type="radio" name="intensity" id="normal" value="1.0" checked><label for="normal"><strong>Normal ⭐</strong><br><small>Natural</small></label></div>
                <div class="opt"><input type="radio" name="intensity" id="strong" value="1.5"><label for="strong"><strong>Strong</strong><br><small>Animated</small></label></div>
            </div>
        </div>

        <button type="submit">🚀 Generate Video</button>
    </form>

    <div id="loading" class="loading">
        <div class="spinner"></div>
        <p><strong>Generating Video...</strong></p>
        <div class="timer" id="timer">0:00</div>
        <p style="color:#666;font-size:13px">
            Step 1: Lip sync animation<br>
            Step 2: Head motion<br>
            Step 3: Full image composite<br>
            <em>Do not close this page</em>
        </p>
    </div>

    <div id="result" class="result">
        <h2 style="color:#4CAF50;margin-bottom:5px">✅ Video Ready!</h2>
        <p id="info" style="color:#666;font-size:13px;margin-bottom:10px"></p>
        <video id="preview" class="video-box" controls playsinline></video>
        <div class="btns">
            <a href="#" id="dlLink" class="dl">⬇️ Download MP4</a>
            <button class="again" onclick="resetForm()">🔄 Make Another</button>
        </div>
    </div>

    <div class="info">
        <strong>Pipeline:</strong> SadTalker (lip sync) → LivePortrait/OpenCV (head motion) → Full image composite.
        Your complete image is preserved — only the face area is animated.
    </div>
</div>

<script>
    let timerRef, sec=0;
    const startTimer=()=>{sec=0;document.getElementById('timer').textContent='0:00';timerRef=setInterval(()=>{sec++;const m=Math.floor(sec/60),s=sec%60;document.getElementById('timer').textContent=m+':'+(s<10?'0':'')+s},1000)};
    const stopTimer=()=>{if(timerRef)clearInterval(timerRef)};

    // Toggle intensity options visibility
    document.getElementById('headMotion').addEventListener('change', function() {
        document.getElementById('intensityGroup').style.display = this.checked ? 'block' : 'none';
    });

    function resetForm(){
        document.getElementById('result').classList.remove('show');
        document.getElementById('form').style.display='block';
        document.getElementById('msg').classList.remove('show');
        document.getElementById('imageFile').value='';
        document.getElementById('audioFile').value='';
        const v=document.getElementById('preview');v.pause();v.removeAttribute('src');
    }

    document.getElementById('form').addEventListener('submit', async function(e){
        e.preventDefault();
        const img=document.getElementById('imageFile').files[0];
        const aud=document.getElementById('audioFile').files[0];
        if(!img||!aud){document.getElementById('msg').className='msg show error';document.getElementById('msg').innerHTML='Select both files';return}

        const fd=new FormData();
        fd.append('action','generate');
        fd.append('image',img);
        fd.append('audio',aud);
        fd.append('quality',document.querySelector('input[name="quality"]:checked').value);
        fd.append('head_motion',document.getElementById('headMotion').checked?'true':'false');
        fd.append('motion_intensity',document.querySelector('input[name="intensity"]:checked').value);

        document.getElementById('form').style.display='none';
        document.getElementById('loading').classList.add('show');
        document.getElementById('msg').classList.remove('show');
        startTimer();

        try{
            const resp=await fetch(window.location.pathname,{method:'POST',body:fd});
            const text=await resp.text();
            stopTimer();
            document.getElementById('loading').classList.remove('show');

            let r;
            try{r=JSON.parse(text)}catch(e){
                document.getElementById('msg').className='msg show error';
                document.getElementById('msg').innerHTML='Server error. Check logs.';
                document.getElementById('form').style.display='block';return;
            }

            if(r.success){
                document.getElementById('info').textContent=r.size_mb+' MB • '+Math.floor(sec/60)+'m '+(sec%60)+'s';
                const base=window.location.pathname;
                document.getElementById('preview').src=base+'?preview='+r.filename;
                document.getElementById('dlLink').href=base+'?download='+r.filename;
                document.getElementById('result').classList.add('show');
            }else{
                document.getElementById('msg').className='msg show error';
                document.getElementById('msg').innerHTML='⚠️ '+r.error;
                document.getElementById('form').style.display='block';
            }
        }catch(err){
            stopTimer();
            document.getElementById('loading').classList.remove('show');
            document.getElementById('msg').className='msg show error';
            document.getElementById('msg').innerHTML='Network error: '+err.message;
            document.getElementById('form').style.display='block';
        }
    });
</script>
</body>
</html>