<?php
// check_encode.php — standalone diagnostic, no session/login required.
// Runs the SAME Ken Burns ffmpeg command vps_ffmpeg_stitch.php would run
// for one or more scenes, and prints the FULL ffmpeg exit code + output —
// the exact thing that gets thrown away on a "successful" stitch.
//
// Upload into the same folder as vps_ffmpeg_stitch.php, then visit:
//
//   https://videovizard.com/check_encode.php?podcast_id=1014
//
// Optionally restrict to specific seq_no values:
//
//   https://videovizard.com/check_encode.php?podcast_id=1014&seq=1,2,3

include 'dbconnect_hdb.php';

$pid = (int)($_GET['podcast_id'] ?? 0);
if (!$pid) { die('Pass ?podcast_id=1014 in the URL'); }

$only_seq = [];
if (!empty($_GET['seq'])) {
    $only_seq = array_map('intval', explode(',', $_GET['seq']));
}

$ROOT_DIR  = __DIR__;
$FFMPEG    = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '/usr/bin/ffmpeg');
$AUDIO_DIR = $ROOT_DIR . '/podcast_audios';

define('VIDEO_W', 1080);
define('VIDEO_H', 1920);

function clean_folder(string $folder): string {
    $folder = trim($folder, '/');
    if ($folder !== '' && preg_match('#^(user|admin)_id_\d+_company_id_\d+(/|$)#', $folder) && strpos($folder, 'user_media/') !== 0) {
        $folder = 'user_media/' . $folder;
    }
    return $folder;
}

function get_audio_duration(string $ffmpeg, string $audio_path): float {
    $out = shell_exec(escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($audio_path) . ' 2>&1');
    if ($out && preg_match('/Duration:\s*(\d+):(\d+):(\d+\.\d+)/', $out, $m)) {
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (float)$m[3];
    }
    return 5.0;
}

// Same Ken Burns builder as vps_ffmpeg_stitch.php's image_to_video(),
// trimmed down (no captions) so we isolate purely the encode step.
function run_encode(string $ffmpeg, string $image_path, ?string $audio_path, float $duration, string $out_path, int $scene_index): array {
    $has_audio = ($audio_path && file_exists($audio_path));
    $gap       = $has_audio ? 0.25 : 0.0;
    $duration  = $duration + $gap;

    $fps    = 25;
    $frames = (int)ceil($duration * $fps);
    $W = VIDEO_W; $H = VIDEO_H;

    $ZB     = 2400;
    $crop_w = (int)round($ZB * $W / $H);

    $pan_l2r  = "(in_w-{$crop_w})*n/{$frames}";
    $pan_r2l  = "(in_w-{$crop_w})*({$frames}-n)/{$frames}";
    $pan_none = "(in_w-{$crop_w})/2";

    $z_in_slow = "min(zoom+0.0006\\,1.10)";
    $z_in_fast = "min(zoom+0.0010\\,1.12)";
    $z_out     = "1.12-0.0010*on";
    $xy_centre = "x=iw/2-(iw/zoom/2):y=ih/2-(ih/zoom/2)";
    $zp_base   = "s={$W}x{$H}:fps={$fps}:d={$frames}";
    $pre       = "scale=-2:{$ZB}";

    $variants = [
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_l2r}:y=0:eval=frame,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_r2l}:y=0:eval=frame,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_l2r}:y=0:eval=frame,zoompan=z={$z_in_fast}:{$xy_centre}:{$zp_base}",
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_r2l}:y=0:eval=frame,zoompan=z={$z_in_fast}:{$xy_centre}:{$zp_base}",
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_none}:y=0,zoompan=z={$z_in_slow}:{$xy_centre}:{$zp_base}",
        "{$pre},crop=w={$crop_w}:h={$ZB}:x={$pan_none}:y=0,zoompan=z={$z_out}:{$xy_centre}:{$zp_base}",
    ];
    $ken_burns = $variants[$scene_index % count($variants)];
    $vf = $ken_burns . ',setsar=1';

    $script = sys_get_temp_dir() . '/kbchk_' . getmypid() . '_' . $scene_index . '.sh';
    $t      = number_format($duration, 3, '.', '');
    $gap_af = number_format($gap, 3, '.', '');

    if ($has_audio) {
        $body = "#!/bin/sh\n"
            . escapeshellcmd($ffmpeg) . " -y"
            . " -loop 1 -framerate {$fps} -i " . escapeshellarg($image_path)
            . " -i " . escapeshellarg($audio_path)
            . " -vf " . escapeshellarg($vf)
            . " -af " . escapeshellarg("apad=pad_dur={$gap_af}")
            . " -t {$t}"
            . " -c:v libx264 -preset fast -crf 26 -pix_fmt yuv420p"
            . " -c:a aac -b:a 128k -ar 44100"
            . " -movflags +faststart"
            . " " . escapeshellarg($out_path)
            . " 2>&1\n";
    } else {
        $body = "#!/bin/sh\n"
            . escapeshellcmd($ffmpeg) . " -y"
            . " -loop 1 -framerate {$fps} -i " . escapeshellarg($image_path)
            . " -vf " . escapeshellarg($vf)
            . " -t {$t}"
            . " -c:v libx264 -preset fast -crf 26 -pix_fmt yuv420p"
            . " -an"
            . " -movflags +faststart"
            . " " . escapeshellarg($out_path)
            . " 2>&1\n";
    }

    file_put_contents($script, $body);
    chmod($script, 0755);
    $out = []; $rc = 0;
    exec('/bin/sh ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
    @unlink($script);

    return ['exit' => $rc, 'output' => $out, 'vf' => $vf, 'cmd_script' => $body];
}

header('Content-Type: text/plain');

$res = mysqli_query($conn,
    "SELECT id, seq_no, image_file, image_folder, audio_file
     FROM hdb_podcast_stories
     WHERE podcast_id=$pid
     ORDER BY seq_no ASC, id ASC");

$i = 0;
while ($r = mysqli_fetch_assoc($res)) {
    $seq = (int)$r['seq_no'];
    if ($only_seq && !in_array($seq, $only_seq)) { $i++; continue; }

    $folder = clean_folder($r['image_folder'] ?? 'podcast_images');
    $vfile  = trim($r['image_file'] ?? '');
    $vabs   = $ROOT_DIR . '/' . $folder . '/' . $vfile;
    $afile  = trim($r['audio_file'] ?? '');
    $aabs   = $afile ? $AUDIO_DIR . '/' . basename($afile) : null;

    if (!preg_match('/\.(jpg|jpeg|png|webp|gif|bmp|tiff?|heic|avif)$/i', $vfile)) {
        echo "seq $seq — not an image, skipping this diagnostic (handle separately)\n";
        echo str_repeat('-', 70) . "\n";
        $i++;
        continue;
    }

    $duration = ($aabs && file_exists($aabs)) ? get_audio_duration($FFMPEG, $aabs) : 5.0;
    $tmp_out  = sys_get_temp_dir() . '/check_clip_' . $pid . '_' . $i . '.mp4';

    echo "seq_no       : $seq\n";
    echo "scene_index  : $i  (Ken Burns variant " . ($i % 6) . ")\n";
    echo "image        : $vabs\n";
    echo "audio        : " . ($aabs ?: '(none)') . "\n";
    echo "duration     : $duration s\n";

    $result = run_encode($FFMPEG, $vabs, $aabs, $duration, $tmp_out, $i);

    echo "exit code    : " . $result['exit'] . "\n";
    echo "output size  : " . (file_exists($tmp_out) ? round(filesize($tmp_out)/1024,1).' KB' : 'FILE NOT CREATED') . "\n";
    echo "-vf used     : " . $result['vf'] . "\n";
    echo "ffmpeg output:\n" . implode("\n", $result['output']) . "\n";
    @unlink($tmp_out);
    echo str_repeat('=', 70) . "\n\n";
    $i++;
}
