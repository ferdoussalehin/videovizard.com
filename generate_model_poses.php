<?php
// ============================================================
// generate_new_models_web.php  (bucket-specific catalog)
// Browser-runnable generator for NEW promo models — fal.ai pipeline.
//
// Women 22-34: Pakistani / Indian / Canadian / Chinese / African.
// DISTINCT hairstyles per BUCKET (Formal / Casual / Business) so users get
// different faces in each category — plus a Hijab set across all 5 ethnicities.
// Soft-glam makeup, plain pure-white background (ready for Fashn). 6 poses each.
// Outfit stays neutral (white tee + black trousers) so Fashn can dress them.
//
// Access key required. Fully dedup-safe: re-running never duplicates.
// DELETE this file after use.
// ============================================================

set_time_limit(0);
ini_set('memory_limit', '256M');
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
include __DIR__ . '/dbconnect_hdb.php';

$falKey = (!empty($falApiKey) ? $falApiKey : null)
       ?? (!empty($fal_api_key) ? $fal_api_key : null)
       ?? '';

// ── Access gate ────────────────────────────────────────────
define('SECRET_KEY', '140357');
$providedKey = (string)($_POST['key'] ?? $_GET['key'] ?? '');
$AUTHED = hash_equals(SECRET_KEY, $providedKey);

if (!$AUTHED) {
    http_response_code(401);
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"><title>Locked</title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Inter',sans-serif;background:linear-gradient(160deg,#fdf6e3 0%,#f6e7c1 100%);color:#4a3b1a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
    .lock{background:#fffdf7;border:1px solid #e6cf8f;border-radius:14px;padding:28px;max-width:360px;width:100%;text-align:center;box-shadow:0 6px 20px rgba(184,134,11,.12);}
    h1{font-size:18px;color:#b8860b;margin-bottom:6px;}
    p{font-size:13px;color:#8a7a4e;margin-bottom:18px;}
    input{width:100%;background:#fffdf7;border:1.5px solid #e0c673;border-radius:8px;color:#4a3b1a;font-size:16px;letter-spacing:.3em;text-align:center;padding:12px;outline:none;margin-bottom:14px;}
    input:focus{border-color:#d4a017;}
    button{width:100%;padding:12px;background:linear-gradient(135deg,#d4a017,#f0c850);color:#3a2f10;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;}
    .err{background:#fbe3e1;border:1px solid #e07a6f;border-radius:8px;padding:8px;font-size:12px;color:#9c3b30;margin-bottom:14px;}
    </style></head><body>
    <form class="lock" method="POST">
        <h1>🔒 Restricted</h1>
        <p>Enter the access key to continue.</p>
        <?php if ($providedKey !== '') echo '<div class="err">Incorrect key.</div>'; ?>
        <input type="password" name="key" placeholder="Access key" autofocus inputmode="numeric">
        <button type="submit">Unlock</button>
    </form>
    </body></html>
    <?php
    exit;
}

$run      = isset($_POST['run']);
$pick     = trim($_POST['pick'] ?? 'g:formal:pk');

// ── CATALOG: buckets -> ethnicity -> hairstyle variants ────
// kind 'standard' uses 'hair'; kind 'hijab' uses 'look'.
$E = [  // ethnicity labels + db values, reused across buckets
  'pk'=>['Pakistani','pakistani'], 'in'=>['Indian','indian'],
  'cd'=>['Canadian','canadian'],   'ch'=>['Chinese','chinese'],
  'af'=>['African','african'],
];

$GROUPS = [
 'formal' => ['label'=>'Formal','cat'=>'female_formal','kind'=>'standard','eth'=>[
    'pk'=>[['f1',26,'long sleek straight black hair with a glossy blowout'],['f2',30,'dark hair in an elegant low chignon']],
    'in'=>[['f1',25,'long glossy black hair with soft waves'],['f2',31,'dark hair in a sleek elegant updo']],
    'cd'=>[['f1',27,'honey-blonde hair in soft Hollywood waves'],['f2',33,'chestnut hair in a polished low bun']],
    'ch'=>[['f1',24,'sleek straight black hair, centre-parted'],['f2',29,'black hair in a refined twisted updo']],
    'af'=>[['f1',28,'sleek straightened shoulder-length hair'],['f2',31,'an elegant updo with edges laid']],
 ]],
 'casual' => ['label'=>'Casual','cat'=>'female_casual','kind'=>'standard','eth'=>[
    'pk'=>[['c1',23,'dark wavy hair in a relaxed half-up style'],['c2',28,'a messy low bun with loose face-framing strands']],
    'in'=>[['c1',24,'long dark hair in a natural high ponytail'],['c2',30,'shoulder-length wavy hair, tousled']],
    'cd'=>[['c1',22,'blonde hair in a casual messy bun'],['c2',29,'light-brown beachy waves']],
    'ch'=>[['c1',25,'black hair in a cute short bob'],['c2',27,'black hair in a casual ponytail with a fringe']],
    'af'=>[['c1',26,'a natural voluminous afro'],['c2',27,'long box braids in a half-up style']],
 ]],
 'business' => ['label'=>'Business','cat'=>'female_business','kind'=>'standard','eth'=>[
    'pk'=>[['b1',29,'dark hair in a neat sleek ponytail'],['b2',33,'shoulder-length straight hair, professional blowout']],
    'in'=>[['b1',27,'dark hair in a tidy low bun'],['b2',32,'straight shoulder-length hair, centre-parted']],
    'cd'=>[['b1',30,'blonde hair in a sleek straight shoulder-length cut'],['b2',34,'brown hair in a neat professional bob']],
    'ch'=>[['b1',26,'sleek straight black hair in a low ponytail'],['b2',31,'black hair in a sharp chin-length bob']],
    'af'=>[['b1',29,'sleek straightened hair in a neat bun'],['b2',30,'a short natural tapered cut']],
 ]],
 'hijab' => ['label'=>'Hijab','cat'=>'female_hijab','kind'=>'hijab','eth'=>[
    'pk'=>[['h1',25,'a neatly draped plain white hijab'],['h2',31,'a plain dusty-rose hijab in a modern wrap']],
    'in'=>[['h1',24,'a neatly draped plain white hijab'],['h2',33,'a plain navy hijab neatly pinned']],
    'cd'=>[['h1',27,'a neatly draped plain white hijab'],['h2',30,'a soft beige hijab in a modern wrap']],
    'ch'=>[['h1',25,'a neatly draped plain white hijab'],['h2',29,'a soft grey hijab in a modern wrap']],
    'af'=>[['h1',26,'a neatly draped plain white hijab'],['h2',28,'a warm terracotta hijab in a modern wrap']],
 ]],
];

// ── 6 poses (p1 = front) ───────────────────────────────────
$poses = [
  ['code'=>'p1','name'=>'Front Facing',  'desc'=>'Standing straight, facing the camera directly, arms relaxed at the sides, full body visible from head to toe including feet'],
  ['code'=>'p2','name'=>'Slight Turn',   'desc'=>'Standing with a slight 15-degree body turn to the left, face looking directly at camera, hands relaxed, full body visible'],
  ['code'=>'p3','name'=>'Three Quarter', 'desc'=>'Three-quarter angle pose, body turned 30 degrees, face toward camera, one hand on hip, full body visible'],
  ['code'=>'p4','name'=>'Side Profile',  'desc'=>'Side profile pose, body at 45-degree angle, face slightly turned toward camera, arms relaxed, full body visible'],
  ['code'=>'p5','name'=>'Walking',       'desc'=>'Walking forward pose, mid-stride, natural movement, facing camera, full body visible from head to feet'],
  ['code'=>'p6','name'=>'Back Turn',     'desc'=>'Back-facing pose with head turned over the shoulder toward camera, arms relaxed, full body visible including back'],
];

$bg   = 'plain pure white seamless studio background, soft diffused studio lighting, no harsh shadows';
$qual = 'ultra photorealistic, cinematic sharp focus from head to toe including feet, high fashion editorial photography, 8k resolution';
$neg  = 'cropped feet, cropped ankles, pattern background, colored background, floor shadows, jewellery, accessories, watermark, blurry, low quality, cartoon, multiple people, deformed face, bad makeup';

function build_appearance($label, $kind, $age, $bit) {
    if ($kind === 'hijab') {
        return "a beautiful {$label} Muslim woman, {$age} years old, wearing {$bit}, "
             . "a plain white long-sleeve fitted top and plain black wide-leg trousers, "
             . "natural attractive soft-glam makeup with flawless glowing skin, gentle confident smile";
    }
    return "a beautiful {$label} woman, {$age} years old, {$bit}, slim build, "
         . "natural attractive soft-glam makeup with flawless glowing skin, gentle confident smile, "
         . "wearing a plain white fitted t-shirt and plain black slim trousers";
}

// ── Helpers (from your working script) ─────────────────────
// Returns ['url'=>?string, 'http'=>int, 'err'=>string, 'body'=>string]
function gen_fal($key, $prompt, $neg) {
    if (empty($key)) return ['url'=>null,'http'=>0,'err'=>'no fal.ai key in config.php','body'=>''];
    $ch = curl_init('https://fal.run/fal-ai/flux/schnell');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>60,
        CURLOPT_POSTFIELDS=>json_encode(['prompt'=>$prompt,'negative_prompt'=>$neg,'image_size'=>['width'=>768,'height'=>1024],'num_inference_steps'=>4,'num_images'=>1,'output_format'=>'jpeg','sync_mode'=>true]),
        CURLOPT_HTTPHEADER=>['Authorization: Key '.$key,'Content-Type: application/json'],
    ]);
    $res=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $cerr=curl_error($ch); curl_close($ch);
    if ($cerr)        return ['url'=>null,'http'=>$http,'err'=>"cURL: $cerr",'body'=>''];
    if ($http!==200)  return ['url'=>null,'http'=>$http,'err'=>"fal HTTP $http",'body'=>(string)$res];
    $d=json_decode($res,true);
    $url = $d['images'][0]['url'] ?? $d['image']['url'] ?? null;
    return ['url'=>$url,'http'=>$http,'err'=>$url?'':'200 but no image url in response','body'=>(string)$res];
}
function make_thumb_w($src,$dst){
    $i=@getimagesize($src); if(!$i) return false;
    $img=match($i['mime']){'image/jpeg'=>imagecreatefromjpeg($src),'image/png'=>imagecreatefrompng($src),'image/webp'=>imagecreatefromwebp($src),default=>null};
    if(!$img) return false;
    $t=imagecreatetruecolor(200,267); imagecopyresampled($t,$img,0,0,0,0,200,267,imagesx($img),imagesy($img));
    $ok=imagejpeg($t,$dst,88); imagedestroy($img); imagedestroy($t); return $ok;
}
function db_save($conn,$fn,$cat,$eth_db,$pose,$desc){
    if(!$conn) return 'skip';
    $fnE=mysqli_real_escape_string($conn,$fn); $catE=mysqli_real_escape_string($conn,$cat);
    $ethE=mysqli_real_escape_string($conn,$eth_db); $posE=mysqli_real_escape_string($conn,$pose);
    $desE=mysqli_real_escape_string($conn,substr($desc,0,200));
    $chk=mysqli_query($conn,"SELECT id FROM hdb_promo_models WHERE filename='$fnE' LIMIT 1");
    if ($chk && mysqli_num_rows($chk)>0){
        mysqli_query($conn,"UPDATE hdb_promo_models SET pose='$posE', is_active=1 WHERE filename='$fnE'");
        return 'exists';
    }
    mysqli_query($conn,
        "INSERT INTO hdb_promo_models
            (filename,category,gender,ethnicity,age_range,pose,description,is_active,sort_order)
         VALUES ('$fnE','$catE','female','$ethE','22-34','$posE','$desE',1,0)");
    return 'inserted';
}

// Build the run plan. pick: 'all' | 'allcat:<bucket>' | 'g:<bucket>:<eth>'
function build_plan($pick, $GROUPS, $E) {
    $plan=[];
    $push=function($bk,$code) use (&$plan,$GROUPS,$E){
        if(!isset($GROUPS[$bk]['eth'][$code])) return;
        $g=$GROUPS[$bk];
        $plan[]=[
            'cat'=>$g['cat'],'kind'=>$g['kind'],'bucket'=>$bk,'code'=>$code,
            'label'=>$E[$code][0].' — '.$g['label'],'eth_db'=>$E[$code][1],
            'variants'=>$g['eth'][$code],
        ];
    };
    if ($pick==='all'){ foreach($GROUPS as $bk=>$g) foreach(array_keys($g['eth']) as $c) $push($bk,$c); }
    elseif (strpos($pick,'allcat:')===0){ $bk=substr($pick,7); if(isset($GROUPS[$bk])) foreach(array_keys($GROUPS[$bk]['eth']) as $c) $push($bk,$c); }
    elseif (strpos($pick,'g:')===0){ [$_,$bk,$c]=explode(':',$pick)+[null,null,null]; $push($bk,$c); }
    return $plan;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Generate New Models</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:linear-gradient(160deg,#fdf6e3 0%,#f6e7c1 100%);color:#4a3b1a;min-height:100vh;padding:30px 20px;}
.card{background:#fffdf7;border:1px solid #e6cf8f;border-radius:14px;padding:24px;max-width:800px;margin:0 auto 20px;box-shadow:0 6px 20px rgba(184,134,11,.12);}
h1{font-size:22px;font-weight:700;color:#b8860b;margin-bottom:6px;}
p.sub{font-size:13px;color:#8a7a4e;margin-bottom:20px;}
label{display:block;font-size:11px;font-weight:700;color:#8a7a4e;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;}
select{width:100%;background:#fffdf7;border:1.5px solid #e0c673;border-radius:8px;color:#4a3b1a;font-family:inherit;font-size:14px;padding:10px 12px;outline:none;margin-bottom:14px;}
select:focus{border-color:#d4a017;}
.btn{width:100%;padding:13px;background:linear-gradient(135deg,#d4a017,#f0c850);color:#3a2f10;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;}
.btn:hover{box-shadow:0 4px 14px rgba(212,160,23,.45);}
.warn{background:#fbe3e1;border:1px solid #e07a6f;border-radius:8px;padding:10px 14px;font-size:12px;color:#9c3b30;margin-bottom:16px;}
.info{background:#fbf3d8;border:1px solid #e0c673;border-radius:8px;padding:10px 14px;font-size:12px;color:#8a6d12;margin-bottom:16px;}
.result-card{background:#fffdf7;border:1px solid #e6cf8f;border-radius:14px;padding:20px;max-width:800px;margin:0 auto;box-shadow:0 6px 20px rgba(184,134,11,.12);}
.result-title{font-size:16px;font-weight:700;color:#b8860b;margin-bottom:14px;}
.grp-title{font-size:13px;font-weight:700;color:#a06a00;margin:14px 0 6px;}
.pose-row{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid #efe2bf;}
.pose-row:last-child{border-bottom:none;}
.pose-img{width:54px;height:72px;border-radius:6px;object-fit:cover;background:#f6e7c1;flex-shrink:0;}
.pose-info{flex:1;}
.pose-name{font-size:13px;font-weight:700;color:#4a3b1a;}
.pose-file{font-size:11px;color:#a99765;margin-top:2px;font-family:monospace;}
.pose-why{font-size:11px;color:#9c3b30;margin-top:3px;}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;margin-left:8px;}
.badge-ok{background:#d8f0d8;color:#2f7d32;}.badge-skip{background:#fbf3d8;color:#a06a00;}.badge-fail{background:#fbe3e1;color:#9c3b30;}
</style>
</head>
<body>

<div class="card">
  <h1>✨ Generate New Models</h1>
  <p class="sub">Women 22–34 · Pakistani · Indian · Canadian · Chinese · African. Distinct hairstyles per bucket (Formal / Casual / Business) + Hijab across all 5. Soft-glam makeup, plain white background for Fashn. 6 poses each.</p>

  <?php if (!$falKey): ?>
  <div class="warn">⚠ No fal.ai API key found in config.php. Add: <code>$falApiKey = 'your-key';</code></div>
  <?php else: ?>
  <div class="info">✅ fal.ai key found — ready to generate</div>
  <?php endif; ?>

  <?php $np=count($poses); ?>

  <form method="POST">
    <input type="hidden" name="key" value="<?=htmlspecialchars(SECRET_KEY)?>">
    <label>What to generate</label>
    <select name="pick">
      <optgroup label="Batches">
        <option value="all" <?=$pick==='all'?'selected':''?>>🔁 EVERYTHING (40 models × <?=$np?> = 240 imgs)</option>
        <?php foreach ($GROUPS as $bk=>$g):
          $n=count($g['eth']); ?>
        <option value="allcat:<?=$bk?>" <?=$pick==="allcat:$bk"?'selected':''?>>📦 ALL <?=$g['label']?> (<?=$n?> ethnicities × <?=$np?>)</option>
        <?php endforeach; ?>
      </optgroup>
      <?php foreach ($GROUPS as $bk=>$g): ?>
      <optgroup label="<?=$g['label']?>">
        <?php foreach ($g['eth'] as $code=>$vs): ?>
        <option value="g:<?=$bk?>:<?=$code?>" <?=$pick==="g:$bk:$code"?'selected':''?>>
          <?=$E[$code][0]?> — <?=$g['label']?> (<?=count($vs)?> looks × <?=$np?>)
        </option>
        <?php endforeach; ?>
      </optgroup>
      <?php endforeach; ?>
    </select>

    <div class="info" style="margin-bottom:14px;">
      Tip: a single ethnicity-in-bucket run = <?=2*$np?> images (fast & reliable). Re-running is safe — existing files and rows are skipped, never duplicated.
    </div>

    <button type="submit" name="run" value="1" class="btn" <?=!$falKey?'disabled':''?>>🚀 Generate Models &amp; Poses</button>
  </form>
</div>

<?php if ($run && $falKey):
  $plan = build_plan($pick, $GROUPS, $E);
?>
<div class="result-card">
  <div class="result-title">Generating <strong><?=htmlspecialchars($pick)?></strong></div>
  <?php
  $thumb_dir = __DIR__ . '/promo_models/thumbnails/';
  if (!is_dir($thumb_dir)) @mkdir($thumb_dir, 0777, true);
  $done=0; $skipped=0; $failed=0;

  foreach ($plan as $item) {
    $cat=$item['cat']; $kind=$item['kind']; $code=$item['code'];
    $out_dir = __DIR__ . '/promo_models/' . $cat . '/';
    if (!is_dir($out_dir)) @mkdir($out_dir, 0777, true);

    foreach ($item['variants'] as $v) {
      [$slug,$age,$bit] = $v;
      echo '<div class="grp-title">' . htmlspecialchars($item['label']) . ' · ' . $slug
         . ' — ' . htmlspecialchars($bit) . ' (age ' . $age . ')</div>';
      $appearance = build_appearance(explode(' — ',$item['label'])[0], $kind, $age, $bit);

      foreach ($poses as $pose) {
        $filename = $cat.'_'.$code.'_'.$slug.'_pose_'.$pose['code'].'.jpg';
        $filepath = $out_dir.$filename;
        $thumbpath= $thumb_dir.$filename;
        $rel_img  = 'promo_models/'.$cat.'/'.$filename;
        echo '<div class="pose-row">';

        if (file_exists($filepath)) {
          db_save($conn,$filename,$cat,$item['eth_db'],$pose['code'],$appearance);
          echo '<img class="pose-img" src="'.$rel_img.'" alt="">';
          echo '<div class="pose-info"><div class="pose-name">'.$pose['name'].' <span class="badge badge-skip">SKIPPED (exists)</span></div><div class="pose-file">'.$filename.'</div></div></div>';
          $skipped++; if (ob_get_level()) ob_flush(); flush(); continue;
        }

        $prompt  = "Full body photograph of $appearance, {$pose['desc']}, $bg, $qual";
        $g = gen_fal($falKey, $prompt, $neg);
        $ok=false; $reason='';
        if (!$g['url']) {
          $reason = $g['err'] . ($g['body'] !== '' ? ' — ' . substr(strip_tags($g['body']),0,200) : '');
        } else {
          $img_data=@file_get_contents($g['url'],false,stream_context_create(['http'=>['timeout'=>30]]));
          if (!$img_data || strlen($img_data)<5000){ $c2=curl_init($g['url']); curl_setopt_array($c2,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>true]); $img_data=curl_exec($c2); curl_close($c2); }
          if (!$img_data || strlen($img_data)<5000){
            $reason = 'image download failed / empty';
          } else {
            $saved=@file_put_contents($filepath,$img_data);
            if(!$saved){ $tmp=tempnam(sys_get_temp_dir(),'pose_'); file_put_contents($tmp,$img_data); if(@rename($tmp,$filepath)||@copy($tmp,$filepath)){@unlink($tmp); $saved=true;} }
            if ($saved || file_exists($filepath)){
              @chmod($filepath,0644); make_thumb_w($filepath,$thumbpath);
              db_save($conn,$filename,$cat,$item['eth_db'],$pose['code'],$appearance);
              $ok=true; $done++;
            } else {
              $reason = 'could not write file — check permissions on promo_models/'.$cat.'/';
            }
          }
        }
        if ($ok){
          echo '<img class="pose-img" src="'.$rel_img.'" alt="">';
          echo '<div class="pose-info"><div class="pose-name">'.$pose['name'].' <span class="badge badge-ok">✓ GENERATED</span></div><div class="pose-file">'.$filename.'</div></div>';
        } else {
          echo '<div class="pose-img" style="display:flex;align-items:center;justify-content:center;font-size:22px;">❌</div>';
          echo '<div class="pose-info"><div class="pose-name">'.$pose['name'].' <span class="badge badge-fail">FAILED</span></div><div class="pose-file">'.$filename.'</div><div class="pose-why">'.htmlspecialchars($reason).'</div></div>';
          $failed++;
        }
        echo '</div>';
        if (ob_get_level()) ob_flush(); flush();
      }
    }
  }
  ?>
  <div style="margin-top:16px;padding:14px;background:#0d3321;border:1px solid #059669;border-radius:10px;font-size:13px;color:#6ee7b7;">
    ✅ Generated: <?=$done?> &nbsp;·&nbsp; ⏭ Skipped: <?=$skipped?> &nbsp;·&nbsp; ❌ Failed: <?=$failed?><br>
    <span style="color:#94a3b8;font-size:11px;margin-top:4px;display:block;">Re-running is safe — nothing is ever duplicated.</span>
  </div>
</div>
<?php endif; ?>

<div style="text-align:center;margin-top:24px;font-size:11px;color:#334155;">
  ⚠ Delete this file from the server when done: <code>generate_new_models_web.php</code>
</div>

</body>
</html>
