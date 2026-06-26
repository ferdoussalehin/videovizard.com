<?php
// video_clip_processor.php
// Run via browser: https://yourdomain.com/video_clip_processor.php
// Run via CLI:     php video_clip_processor.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);
ini_set('memory_limit', '512M');

$is_cli = (php_sapi_name() === 'cli');

function out(string $msg, string $type = 'info'): void {
    global $is_cli;
    $ts = date('H:i:s');
    if ($is_cli) {
        echo "[$ts] $msg\n"; flush();
    } else {
        $colors = ['info'=>'#e0f2fe','ok'=>'#dcfce7','warn'=>'#fef9c3','error'=>'#fee2e2','head'=>'#1e3a5f','section'=>'#dbeafe'];
        $bg = $colors[$type] ?? '#f8fafc';
        $bold = in_array($type,['head','ok','error','section']) ? 'font-weight:700;' : '';
        $color = $type === 'head' ? '#fff' : '#1e293b';
        echo '<div style="margin:2px 0;padding:4px 10px;border-radius:5px;font-size:13px;background:'.$bg.';color:'.$color.';'.$bold.'">'
           .'<span style="color:#94a3b8;font-size:11px;margin-right:8px;">['.$ts.']</span>'
           .htmlspecialchars($msg).'</div>'."\n";
        ob_flush(); flush();
    }
}

function log_it(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ').'[video_clip] '.$msg.PHP_EOL, FILE_APPEND|LOCK_EX);
}

if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Video Clip Processor</title>'
        .'<style>body{font-family:monospace;background:#0f172a;padding:20px;}.wrap{max-width:900px;margin:auto;}</style>'
        .'</head><body><div class="wrap">';
    echo '<div style="background:#1e3a5f;color:#fff;padding:14px 18px;border-radius:8px;font-size:16px;font-weight:700;margin-bottom:12px;">Clip Processor</div>';
    ob_flush(); flush();
}

define('VPS_URL',    'http://187.124.249.46/videovizard.com/vps_clip.php');
define('SECRET_KEY', 'VS_FFmpeg_2026_Secret!');
define('CLIP_SECS',  15);
define('SRC_DIR',    __DIR__ . '/podcast_videos/');
define('DEST_DIR',   __DIR__ . '/podcast_new_videos/');
define('LOG_FILE',   __DIR__ . '/a_errors.log');

// Environment
out('-- Environment', 'section');
out('PHP: '.PHP_VERSION.'  SAPI: '.php_sapi_name());
out('SRC_DIR : '.SRC_DIR.' -> '.(is_dir(SRC_DIR) ? 'OK' : 'MISSING'));
out('DEST_DIR: '.DEST_DIR.' -> '.(is_dir(DEST_DIR) ? 'OK' : 'will create'));
out('cURL    : '.(function_exists('curl_init') ? 'OK' : 'NOT AVAILABLE'));
if (!function_exists('curl_init')) { out('FATAL: cURL required','error'); if(!$is_cli)echo'</div></body></html>'; exit(1); }

// VPS ping
out('-- VPS ping', 'section');
$ping = curl_init();
curl_setopt_array($ping,[CURLOPT_URL=>VPS_URL,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_POSTFIELDS=>['secret_key'=>SECRET_KEY,'action'=>'ping']]);
$pr = curl_exec($ping); $pe = curl_error($ping); $pc = curl_getinfo($ping,CURLINFO_HTTP_CODE); curl_close($ping);
if ($pe)       out("VPS unreachable: $pe",'error');
elseif($pc!=200) out("VPS HTTP $pc - ".substr((string)$pr,0,200),'warn');
else { $pd=json_decode((string)$pr,true); out('VPS OK - ffmpeg: '.($pd['ffmpeg']??'?').' version: '.($pd['ffmpeg_version']??'?'),'ok'); }

// DB
out('-- Database','section');
if (!file_exists(__DIR__.'/dbconnect_hdb.php')) { out('FATAL: dbconnect_hdb.php not found','error'); if(!$is_cli)echo'</div></body></html>'; exit(1); }
require_once __DIR__.'/dbconnect_hdb.php';
if (empty($conn)||mysqli_connect_errno()) { out('FATAL: DB failed - '.mysqli_connect_error(),'error'); if(!$is_cli)echo'</div></body></html>'; exit(1); }
out('DB connected','ok');

// Ensure clipped_video column
$chk=mysqli_query($conn,"SHOW COLUMNS FROM `hdb_image_data` LIKE 'clipped_video'");
if ($chk&&mysqli_num_rows($chk)===0) { mysqli_query($conn,"ALTER TABLE `hdb_image_data` ADD COLUMN `clipped_video` VARCHAR(255) NULL DEFAULT NULL"); out('Added column: clipped_video'); }
else out('Column clipped_video: OK');

// DB snapshot
out('-- DB snapshot','section');
$mt=mysqli_query($conn,"SELECT media_type,COUNT(*) c FROM hdb_image_data GROUP BY media_type");
if($mt) while($r=mysqli_fetch_assoc($mt)) out("  media_type='".$r['media_type']."' -> ".$r['c']." rows");
$rf=mysqli_query($conn,"SELECT resize_flag,COUNT(*) c FROM hdb_image_data GROUP BY resize_flag ORDER BY resize_flag");
if($rf) while($r=mysqli_fetch_assoc($rf)) out("  resize_flag=".$r['resize_flag']." -> ".$r['c']." rows");

// Sample recent video rows
$samp=mysqli_query($conn,"SELECT id,image_name,media_type,resize_flag,clipped_video FROM hdb_image_data WHERE media_type='video' ORDER BY id DESC LIMIT 5");
if($samp&&mysqli_num_rows($samp)>0) {
    out('  Latest video rows:');
    while($r=mysqli_fetch_assoc($samp)) out("    id={$r['id']} name={$r['image_name']} resize_flag={$r['resize_flag']} clipped=".($r['clipped_video']?:'none'));
} else {
    out('  No rows with media_type=video','warn');
    $byext=mysqli_query($conn,"SELECT id,image_name,media_type,resize_flag FROM hdb_image_data WHERE LOWER(image_name) REGEXP '\\\\.(mp4|webm|mov)$' LIMIT 10");
    if($byext&&mysqli_num_rows($byext)>0) {
        out('  Found video-extension files (but media_type is wrong):','warn');
        while($r=mysqli_fetch_assoc($byext)) out("    id={$r['id']} name={$r['image_name']} media_type={$r['media_type']} resize_flag={$r['resize_flag']}");
    } else out('  No video files found at all','warn');
}

// Queue
out('-- Queue','section');
$ext_list="'mp4','webm','mov','avi','mkv','m4v'";
$sql="SELECT id,image_name FROM hdb_image_data WHERE resize_flag=0 AND media_type='video' AND LOWER(SUBSTRING_INDEX(image_name,'.',-1)) IN ($ext_list) ORDER BY id ASC";
$result=mysqli_query($conn,$sql);
if(!$result){out('FATAL: '.(mysqli_error($conn)),'error');if(!$is_cli)echo'</div></body></html>';exit(1);}
$total=mysqli_num_rows($result);
out("Videos to clip: $total",$total>0?'ok':'warn');

if($total===0){
    out('Nothing to process. Reasons:','warn');
    out('  1. media_type not exactly "video" (see snapshot above)','warn');
    out('  2. resize_flag already=1 for all videos','warn');
    out('  3. No videos in hdb_image_data yet','warn');
    if(!$is_cli)echo'</div></body></html>';
    mysqli_close($conn);exit(0);
}

if(!is_dir(DEST_DIR)){mkdir(DEST_DIR,0755,true);out('Created: '.DEST_DIR);}
$done=$skipped=$failed=0;

while($row=mysqli_fetch_assoc($result)){
    $id=(int)$row['id']; $filename=$row['image_name']; $src_path=SRC_DIR.$filename;
    out("-- #$id: $filename",'section');

    if(!file_exists($src_path)){
        out("  SKIP - not on disk: $src_path",'warn');
        log_it("SKIP #$id $filename - not on disk");
        mysqli_query($conn,"UPDATE hdb_image_data SET resize_flag=1,updated_at=NOW() WHERE id=$id");
        $skipped++;continue;
    }
    out("  Size: ".round(filesize($src_path)/1024/1024,2)." MB");

    $basename=pathinfo($filename,PATHINFO_FILENAME);
    $out_filename=$basename.'_clip15.mp4';
    $dest_path=DEST_DIR.$out_filename;

    out("  Uploading to VPS...");
    $ch=curl_init();
    curl_setopt_array($ch,[
        CURLOPT_URL=>VPS_URL,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>300,CURLOPT_CONNECTTIMEOUT=>20,
        CURLOPT_POSTFIELDS=>['secret_key'=>SECRET_KEY,'action'=>'clip','filename'=>$filename,'clip_seconds'=>CLIP_SECS,'video_file'=>new CURLFile($src_path,'video/mp4',$filename)],
    ]);
    $response=curl_exec($ch);$curl_err=curl_error($ch);$http_code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$ct=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$tt=curl_getinfo($ch,CURLINFO_TOTAL_TIME);curl_close($ch);
    out("  VPS response: HTTP=$http_code  time={$tt}s  CT=$ct  bytes=".strlen((string)$response));

    if($curl_err){out("  FAIL cURL: $curl_err",'error');log_it("FAIL #$id $filename - cURL: $curl_err");$failed++;continue;}
    if($http_code!==200){out("  FAIL HTTP $http_code: ".substr((string)$response,0,300),'error');log_it("FAIL #$id $filename - HTTP $http_code");$failed++;continue;}

    $resp=(string)$response;
    $is_err=(strpos((string)($ct??''),'json')!==false)||(strlen($resp)<5000&&strlen($resp)>0&&$resp[0]==='{');
    if($is_err){$d=json_decode($resp,true);$msg=$d['message']??$resp;out("  FAIL VPS: $msg",'error');log_it("FAIL #$id $filename - VPS: $msg");$failed++;continue;}

    $written=file_put_contents($dest_path,$response);
    if(!$written||$written<1000){out("  FAIL save ($written bytes)",'error');log_it("FAIL #$id $filename - save $written bytes");@unlink($dest_path);$failed++;continue;}
    out("  Saved: podcast_new_videos/$out_filename (".round($written/1024/1024,2)." MB)",'ok');

    $safe=mysqli_real_escape_string($conn,$out_filename);
    $ok=mysqli_query($conn,"UPDATE hdb_image_data SET resize_flag=1,clipped_video='$safe',updated_at=NOW() WHERE id=$id");
    out("  DB: ".($ok?"OK resize_flag=1 clipped_video=$out_filename":"WARN ".mysqli_error($conn)),$ok?'ok':'warn');
    log_it("OK #$id $filename -> $out_filename");
    $done++;
}

out('================================','head');
out("DONE  processed=$done  skipped=$skipped  failed=$failed  total=$total",'head');
out('================================','head');
mysqli_close($conn);
if(!$is_cli)echo'</div></body></html>';
exit(0);
