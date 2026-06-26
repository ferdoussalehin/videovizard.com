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

// Base URL for serving video files (adjust to your domain)
define('SRC_URL',  '/podcast_videos/');
define('DEST_URL', '/podcast_new_videos/');

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

function show_video_pair(string $src_filename, string $out_filename, string $status): void {
    global $is_cli;
    if ($is_cli) return;

    $src_url  = SRC_URL  . rawurlencode($src_filename);
    $dest_url = DEST_URL . rawurlencode($out_filename);

    $is_ok = ($status === 'ok');
    $border_color = $is_ok ? '#22c55e' : '#ef4444';
    $status_label = $is_ok ? '✅ Clipped successfully' : '❌ Clipping failed';
    $status_bg    = $is_ok ? '#dcfce7' : '#fee2e2';
    $status_color = $is_ok ? '#166534' : '#991b1b';

    echo '<div style="margin:12px 0;padding:14px 16px;border-radius:10px;border:2px solid '.$border_color.';background:#1e293b;">';

    // Status badge
    echo '<div style="margin-bottom:10px;padding:6px 12px;border-radius:6px;background:'.$status_bg.';color:'.$status_color.';font-size:12px;font-weight:700;display:inline-block;">'
        .$status_label.'</div>';

    echo '<div style="display:flex;gap:16px;flex-wrap:wrap;">';

    // BEFORE
    echo '<div style="flex:1;min-width:220px;">';
    echo '<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Original</div>';
    echo '<div style="font-size:11px;color:#cbd5e1;margin-bottom:6px;word-break:break-all;">'.htmlspecialchars($src_filename).'</div>';
    echo '<video controls preload="metadata" style="width:100%;border-radius:6px;background:#0f172a;max-height:180px;">'
        .'<source src="'.htmlspecialchars($src_url).'" type="video/mp4">'
        .'Your browser does not support video.'
        .'</video>';
    echo '</div>';

    // AFTER
    echo '<div style="flex:1;min-width:220px;">';
    if ($is_ok) {
        echo '<div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Clipped (15s)</div>';
        echo '<div style="font-size:11px;color:#cbd5e1;margin-bottom:6px;word-break:break-all;">'.htmlspecialchars($out_filename).'</div>';
        echo '<video controls preload="metadata" style="width:100%;border-radius:6px;background:#0f172a;max-height:180px;">'
            .'<source src="'.htmlspecialchars($dest_url).'" type="video/mp4">'
            .'Your browser does not support video.'
            .'</video>';
    } else {
        echo '<div style="flex:1;min-width:220px;display:flex;align-items:center;justify-content:center;">';
        echo '<div style="color:#ef4444;font-size:13px;font-weight:600;">No output generated</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>'; // flex row
    echo '</div>'; // card
    ob_flush(); flush();
}

function log_it(string $msg): void {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ').'[video_clip] '.$msg.PHP_EOL, FILE_APPEND|LOCK_EX);
}

if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Video Clip Processor</title>'
        .'<style>body{font-family:monospace;background:#0f172a;padding:20px;}.wrap{max-width:960px;margin:auto;}</style>'
        .'</head><body><div class="wrap">';
    echo '<div style="background:#1e3a5f;color:#fff;padding:14px 18px;border-radius:8px;font-size:16px;font-weight:700;margin-bottom:12px;">Clip Processor</div>';
    ob_flush(); flush();
}

define('VPS_URL',    'http://187.124.249.46/videovizard.com/vps_clip.php');
define('SECRET_KEY', 'VS_FFmpeg_2026_Secret!');
define('CLIP_SECS',  15);
define('BATCH_SIZE', 3);   // ← process this many videos per run
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

// Queue — limited to BATCH_SIZE per run to avoid connection timeouts
out('-- Queue (batch limit: '.BATCH_SIZE.')','section');
$ext_list="'mp4','webm','mov','avi','mkv','m4v'";
$sql="SELECT id,image_name FROM hdb_image_data WHERE resize_flag=0 AND media_type='video' AND LOWER(SUBSTRING_INDEX(image_name,'.',-1)) IN ($ext_list) ORDER BY id ASC LIMIT ".BATCH_SIZE;
$result=mysqli_query($conn,$sql);
if(!$result){out('FATAL: '.(mysqli_error($conn)),'error');if(!$is_cli)echo'</div></body></html>';exit(1);}
$total=mysqli_num_rows($result);

// Also get total pending count (without LIMIT) so the user knows how many remain
$pending_row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_image_data WHERE resize_flag=0 AND media_type='video' AND LOWER(SUBSTRING_INDEX(image_name,'.',-1)) IN ($ext_list)"));
$total_pending=(int)($pending_row['c']??0);

out("Videos in this batch: $total / $total_pending pending total", $total>0?'ok':'warn');
if ($total_pending > BATCH_SIZE) {
    out("  → Run again to process the next batch of up to ".BATCH_SIZE." videos", 'info');
}

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
    $out_filename=$basename.'.mp4';
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

    if($curl_err){
        out("  FAIL cURL: $curl_err",'error');
        log_it("FAIL #$id $filename - cURL: $curl_err");
        show_video_pair($filename, $out_filename, 'fail');
        $failed++;continue;
    }
    if($http_code!==200){
        out("  FAIL HTTP $http_code: ".substr((string)$response,0,300),'error');
        log_it("FAIL #$id $filename - HTTP $http_code");
        show_video_pair($filename, $out_filename, 'fail');
        $failed++;continue;
    }

    $resp=(string)$response;
    $is_err=(strpos((string)($ct??''),'json')!==false)||(strlen($resp)<5000&&strlen($resp)>0&&$resp[0]==='{');
    if($is_err){
        $d=json_decode($resp,true);$msg=$d['message']??$resp;
        out("  FAIL VPS: $msg",'error');
        log_it("FAIL #$id $filename - VPS: $msg");
        show_video_pair($filename, $out_filename, 'fail');
        $failed++;continue;
    }

    $written=file_put_contents($dest_path,$response);
    if(!$written||$written<1000){
        out("  FAIL save ($written bytes)",'error');
        log_it("FAIL #$id $filename - save $written bytes");
        @unlink($dest_path);
        show_video_pair($filename, $out_filename, 'fail');
        $failed++;continue;
    }
    out("  Saved: podcast_new_videos/$out_filename (".round($written/1024/1024,2)." MB)",'ok');

    $safe=mysqli_real_escape_string($conn,$out_filename);
    $ok=mysqli_query($conn,"UPDATE hdb_image_data SET resize_flag=1,clipped_video='$safe',updated_at=NOW() WHERE id=$id");
    out("  DB: ".($ok?"OK resize_flag=1 clipped_video=$out_filename":"WARN ".mysqli_error($conn)),$ok?'ok':'warn');
    log_it("OK #$id $filename -> $out_filename");

    // Show before/after video players
    show_video_pair($filename, $out_filename, 'ok'); 

    $done++;
}

out('================================','head');
out("DONE  processed=$done  skipped=$skipped  failed=$failed  (batch=$total / $total_pending pending)",'head');
if (($total_pending - $done - $skipped) > 0) {
    out("→ ".($total_pending - $done - $skipped)." videos still pending — refresh to process the next batch",'warn');
}
out('================================','head');
mysqli_close($conn);
if(!$is_cli)echo'</div></body></html>';
exit(0);
