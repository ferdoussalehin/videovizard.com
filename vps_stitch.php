<?php
define('SECRET',   'VS_FFmpeg_2026_Secret!');
define('WORK_DIR', '/var/www/html/videovizard.com/stitch_jobs/');
define('FFMPEG',   'ffmpeg');

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'start';

if ($action === 'status') {
    $job_id  = $_GET['job_id'] ?? '';
    $job_dir = WORK_DIR . $job_id . '/';
    if (!$job_id || !is_dir($job_dir)) {
        echo json_encode(['success'=>false,'status'=>'not_found']); exit;
    }
    $meta = json_decode(file_get_contents($job_dir.'meta.json'), true);
    echo json_encode([
        'success'     => true,
        'job_id'      => $job_id,
        'status'      => $meta['status']      ?? 'unknown',
        'message'     => $meta['message']     ?? '',
        'mp4_size_mb' => $meta['mp4_size_mb'] ?? null,
        'mp4_url'     => $meta['mp4_url']     ?? null,
    ]);
    exit;
}

// CLI worker (forked background process)
if (php_sapi_name() === 'cli') {
    $opts   = getopt('', ['job:']);
    $job_id = $opts['job'] ?? '';
    if (!$job_id) { echo "No job\n"; exit(1); }
    $job_dir = WORK_DIR . $job_id . '/';
    $meta    = json_decode(file_get_contents($job_dir.'meta.json'), true);
    runJob($meta, $job_dir);
    exit(0);
}

// HTTP entry — validate secret and queue job
$secret = $_POST['secret'] ?? '';
if ($secret !== SECRET) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$podcast_id  = (int)($_POST['podcast_id']  ?? 0);
$scene_count = (int)($_POST['scene_count'] ?? 0);
$base_url    = rtrim($_POST['base_url']    ?? '', '/') . '/';
$clip_dir    = trim($_POST['clip_dir']     ?? 'published_videos/');
$output_dir  = trim($_POST['output_dir']   ?? 'published_videos/');

if (!$podcast_id || $scene_count < 1) {
    echo json_encode(['success'=>false,'message'=>'Missing podcast_id or scene_count']);
    exit;
}

if (!is_dir(WORK_DIR)) mkdir(WORK_DIR, 0755, true);

$job_id  = 'stitch_' . $podcast_id . '_' . time();
$job_dir = WORK_DIR . $job_id . '/';
mkdir($job_dir, 0755, true);

$meta = [
    'job_id'      => $job_id,
    'podcast_id'  => $podcast_id,
    'scene_count' => $scene_count,
    'base_url'    => $base_url,
    'clip_dir'    => $clip_dir,
    'output_dir'  => $output_dir,
    'status'      => 'queued',
    'created_at'  => date('Y-m-d H:i:s'),
    'log'         => [],
];
file_put_contents($job_dir.'meta.json', json_encode($meta));

$php = '/usr/bin/php8.3';
$cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__)
     . ' --job=' . escapeshellarg($job_id)
     . ' > ' . escapeshellarg($job_dir.'output.log') . ' 2>&1 &';
exec($cmd);

echo json_encode(['success'=>true,'job_id'=>$job_id,'status'=>'queued']);
exit;

// ── Background worker ─────────────────────────────────────────────────────
function runJob(array $meta, string $job_dir): void {
    $log = function(string $msg) use ($job_dir, &$meta) {
        $meta['log'][] = date('H:i:s').' '.$msg;
        file_put_contents($job_dir.'meta.json', json_encode($meta));
        echo $msg."\n";
    };
    $setStatus = function(string $s, string $msg='') use ($job_dir, &$meta) {
        $meta['status']  = $s;
        $meta['message'] = $msg;
        file_put_contents($job_dir.'meta.json', json_encode($meta));
    };

    $setStatus('running','Downloading clips...');
    $log('Job: '.$meta['job_id']);

    $podcast_id  = (int)$meta['podcast_id'];
    $scene_count = (int)$meta['scene_count'];
    $base_url    = $meta['base_url'];
    $clip_dir    = $meta['clip_dir'];

    // 1. Download clips
    $clips = [];
    for ($i = 0; $i < $scene_count; $i++) {
        $fname = "podcast_{$podcast_id}_scene_{$i}.webm";
        $url   = $base_url . ltrim($clip_dir,'/') . $fname;
        $dest  = $job_dir . "scene_{$i}.webm";
        $log("Downloading scene $i: $url");
        $ctx  = stream_context_create(['http'=>['timeout'=>120]]);
        $data = @file_get_contents($url, false, $ctx);
        if (!$data || strlen($data) < 100) { $log("WARNING: scene $i empty, skipping"); continue; }
        file_put_contents($dest, $data);
        $clips[] = $dest;
        $log('  -> '.round(filesize($dest)/1024/1024,2).' MB');
    }

    if (empty($clips)) { $setStatus('error','No clips downloaded'); return; }

    // 2. Concat list
    $list = $job_dir.'concat.txt';
    file_put_contents($list, implode("\n", array_map(fn($p)=>"file '".addslashes($p)."'", $clips)));

    // 3. Stitch
    $stitched = $job_dir."stitched_{$podcast_id}.webm";
    $cmd = FFMPEG." -y -f concat -safe 0 -i ".escapeshellarg($list)." -c copy ".escapeshellarg($stitched)." 2>&1";
    $log("Stitching ".count($clips)." clips...");
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !file_exists($stitched)) {
        $setStatus('error','FFmpeg stitch failed: '.implode(' ',$out));
        return;
    }
    $log('Stitched: '.round(filesize($stitched)/1024/1024,2).' MB');

    // 4. Convert to MP4
    $mp4_name  = "podcast_{$podcast_id}.mp4";
    $mp4_local = $job_dir.$mp4_name;
    $cmd2 = FFMPEG." -y -i ".escapeshellarg($stitched)
          . " -vf \"scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black\""
          . " -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p"
          . " -c:a aac -b:a 128k -ar 44100 -movflags +faststart"
          . " ".escapeshellarg($mp4_local)." 2>&1";
    $log("Converting to MP4...");
    exec($cmd2, $out2, $rc2);
    if ($rc2 !== 0 || !file_exists($mp4_local)) {
        $setStatus('error','FFmpeg MP4 failed: '.implode(' ',$out2));
        return;
    }
    $mp4_size = round(filesize($mp4_local)/1024/1024, 2);
    $log("MP4 ready: {$mp4_size} MB");

    // 5. Copy MP4 to published_videos/
    $out_dir  = rtrim($meta['output_dir'],'/').'/';
    $out_path = __DIR__.'/'.ltrim($out_dir,'/').$mp4_name;
    if (!is_dir(dirname($out_path))) mkdir(dirname($out_path), 0755, true);
    copy($mp4_local, $out_path);
    chown($out_path, 'www-data');
    $log("Copied to: $out_path");

    // 6. Done
    $meta['status']      = 'done';
    $meta['message']     = "MP4 ready ({$mp4_size} MB)";
    $meta['mp4_size_mb'] = $mp4_size;
    $meta['mp4_url']     = $out_dir.$mp4_name;
    $meta['finished_at'] = date('Y-m-d H:i:s');
    file_put_contents($job_dir.'meta.json', json_encode($meta));

    foreach ($clips as $c) @unlink($c);
    @unlink($stitched);
    $log('Done.');
}
