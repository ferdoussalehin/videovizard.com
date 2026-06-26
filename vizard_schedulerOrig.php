<?php
// vizard_scheduler.php — Schedule & Publish Dashboard
// Called from vizard_browser.php — session carries admin_id, client_id, client_company_id

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<pre style="background:#fee;padding:20px;font-size:14px;">';
        echo "FATAL ERROR:\n";
        echo "Message: " . $err['message'] . "\n";
        echo "File: "    . $err['file']    . "\n";
        echo "Line: "    . $err['line']    . "\n";
        echo '</pre>';
    }
});

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>15552000,'path'=>'/','httponly'=>true,'samesite'=>'Strict']);
    session_start();
}

// Ensure this page is never embedded in a cross-origin frame
if (!headers_sent()) header('X-Frame-Options: SAMEORIGIN');

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

include __DIR__ . '/dbconnect_hdb.php';
require_once __DIR__ . '/youtube_config.php';

$admin_id   = (int)$_SESSION['admin_id'];
$client_id  = (int)($_SESSION['client_id']          ?? $admin_id);
$company_id = (int)($_SESSION['client_company_id']  ?? $client_id);

// ── Build ordered list of scopes ──────────────────────────────────────────────
// Every user has exactly one internal company (company_type='internal') —
// that is their primary workspace. Additional companies follow after.
function get_admin_scopes(mysqli $conn, int $admin_id): array {
    $scopes = [];
    $q = mysqli_query($conn,
        "SELECT id, companyname, company_type
         FROM hdb_companies
         WHERE admin_id = $admin_id
         ORDER BY CASE WHEN company_type='internal' THEN 0 ELSE 1 END ASC, id ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $is_internal = ($row['company_type'] === 'internal');
            $label = $row['companyname'] ?: ($is_internal ? 'My Workspace' : 'Company '.$row['id']);
            $scopes[] = [
                'label'      => $label . ($is_internal ? ' (My Workspace)' : ''),
                'type'       => $is_internal ? 'internal' : 'company',
                'admin_id'   => $admin_id,
                'company_id' => (int)$row['id'],
            ];
        }
    }
    // Fallback if no companies found at all
    if (empty($scopes))
        $scopes[] = ['label'=>'My Workspace','type'=>'personal','admin_id'=>$admin_id,'company_id'=>0];
    return $scopes;
}

// ── WHERE clause per scope ────────────────────────────────────────────────────
function scope_where(array $scope): string {
    if ($scope['type'] === 'personal') {
        $aid = $scope['admin_id'];
        return "p.admin_id = $aid";
    }
    return "p.company_id = " . $scope['company_id'];
}

// ── Resolve video filename from row ───────────────────────────────────────────
function resolve_video_filename(array $row): string {
    // video_filename is the primary column used by the wizard
    return trim($row['video_filename'] ?? $row['published_video'] ?? '');
}

function resolve_video_path(array $row): string {
    $fname = resolve_video_filename($row);
    if (!$fname) return '';
    // Primary folder is published_videos/
    $p = __DIR__ . '/published_videos/' . $fname;
    if (file_exists($p)) return $p;
    // Fallback
    $p2 = __DIR__ . '/podcast_videos/' . $fname;
    if (file_exists($p2)) return $p2;
    return '';
}

// ── Real OAuth check ──────────────────────────────────────────────────────────
function get_connected_platforms(mysqli $conn, int $admin_id): array {
    $out = [];
    $q = mysqli_query($conn,
        "SELECT platform FROM hdb_oauth_tokens
         WHERE admin_id = $admin_id AND token_expiry > NOW()");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $plat = $r['platform'];
            // Normalise page-level keys back to 'facebook'
            if ($plat === 'facebook' || strpos($plat, 'facebook_page_') === 0)
                $out['facebook'] = true;
            elseif ($plat === 'instagram')
                $out['instagram'] = true;
            elseif ($plat === 'youtube')
                $out['youtube'] = true;
        }
    }
    return $out;
}

// ── Mark video POSTED only when all connected platforms have posted ────────────
// All 6 platform status columns that exist on hdb_podcasts:
//   facebook_status, instagram_status, youtube_status, tiktok_status, twitter_status, linkedin_status
// Logic:
//   1. Fetch connected platforms for this admin (non-expired OAuth tokens only)
//   2. For each connected platform, check its _status column on this podcast
//   3. Only if ALL connected platforms = 'posted' → set video_status = 'POSTED'
function maybe_mark_all_posted(mysqli $conn, int $podcast_id, int $admin_id): void {
    // All platform status columns
    $all_platforms = ['facebook', 'instagram', 'youtube', 'tiktok', 'twitter', 'linkedin'];

    // Get connected platforms for this admin (has a valid OAuth token)
    $connected = get_connected_platforms($conn, $admin_id);
    if (empty($connected)) return; // no connected platforms — nothing to check

    // Fetch current platform statuses for this podcast
    $cols   = implode(',', array_map(fn($p) => "{$p}_status", $all_platforms));
    $row    = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT $cols FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if (!$row) return;

    // Check every connected platform — all must be 'posted'
    foreach (array_keys($connected) as $platform) {
        $col = $platform . '_status';
        $status = $row[$col] ?? '';
        if ($status !== 'posted') {
            return; // at least one connected platform not yet posted — do not mark POSTED
        }
    }

    // All connected platforms have posted — mark the video as fully POSTED
    mysqli_query($conn,
        "UPDATE hdb_podcasts SET video_status='POSTED', updated_at=NOW() WHERE id=$podcast_id AND admin_id=$admin_id");
}

// ── YouTube upload (embedded from youtube_upload_ajax.php) ────────────────────
function yt_upload_podcast(mysqli $conn, int $podcast_id, int $admin_id): array {
    $podcast = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id,admin_id,title,caption_text,hashtags,keywords,published_video,video_filename,youtube_video_id
         FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    if (!$podcast) return ['success'=>false,'error'=>"Podcast #$podcast_id not found"];

    $title = $podcast['title'] ?: 'VideoVizard Video #'.$podcast_id;
    $desc  = $podcast['caption_text'] ?: '';
    if ($podcast['hashtags']) $desc .= "\n\n".$podcast['hashtags'];
    if ($podcast['keywords']) $desc .= "\n\nKeywords: ".$podcast['keywords'];

    $video_path = resolve_video_path($podcast);
    if (!$video_path) {
        $fname_dbg = resolve_video_filename($podcast);
        $tried = __DIR__ . '/published_videos/' . $fname_dbg . ' | ' . __DIR__ . '/podcast_videos/' . $fname_dbg;
        return ['success'=>false,'error'=>"Video file not found. DB field='published_video' value='$fname_dbg'. Looked in: $tried"];
    }

    $file_size = filesize($video_path);
    $fname     = resolve_video_filename($podcast);
    $mime_type = preg_match('/\.mp4$/i',$fname) ? 'video/mp4' : 'video/webm';

    // Token
    $tok = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT access_token,refresh_token,token_expiry FROM hdb_oauth_tokens WHERE admin_id=$admin_id AND platform='youtube' LIMIT 1"));
    if (!$tok) return ['success'=>false,'error'=>'YouTube not connected — connect in Social Accounts above'];

    $access_token = $tok['access_token'];
    if (strtotime($tok['token_expiry']) < time()+60) {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>YT_CLIENT_ID,'client_secret'=>YT_CLIENT_SECRET,
                'refresh_token'=>$tok['refresh_token'],'grant_type'=>'refresh_token']),
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>false]);
        $res=curl_exec($ch); curl_close($ch);
        $data=json_decode($res,true);
        if (empty($data['access_token'])) return ['success'=>false,'error'=>'Token refresh failed — reconnect YouTube'];
        $access_token = $data['access_token'];
        $new_exp = date('Y-m-d H:i:s',time()+(int)($data['expires_in']??3600));
        $et=mysqli_real_escape_string($conn,$access_token); $ee=mysqli_real_escape_string($conn,$new_exp);
        mysqli_query($conn,"UPDATE hdb_oauth_tokens SET access_token='$et',token_expiry='$ee',updated_at=NOW() WHERE admin_id=$admin_id AND platform='youtube'");
    }

    // Start resumable session
    $metadata = json_encode(['snippet'=>['title'=>$title,'description'=>$desc,
        'tags'=>array_filter(array_map('trim',explode(',',$podcast['keywords']??''))),'categoryId'=>'22'],
        'status'=>['privacyStatus'=>'public','selfDeclaredMadeForKids'=>false]]);
    $ch = curl_init('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$metadata,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$access_token,'Content-Type: application/json',
            'X-Upload-Content-Type: '.$mime_type,'X-Upload-Content-Length: '.$file_size],
        CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false]);
    $resp=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); $hs=curl_getinfo($ch,CURLINFO_HEADER_SIZE); curl_close($ch);
    if ($http!==200) return ['success'=>false,'error'=>"Upload session failed (HTTP $http): ".substr($resp,$hs)];

    $upload_url='';
    foreach (explode("\r\n",substr($resp,0,$hs)) as $h)
        if (stripos($h,'Location:')===0){$upload_url=trim(substr($h,9));break;}
    if (!$upload_url) return ['success'=>false,'error'=>'No upload URL returned'];

    // Chunked upload
    $chunk_size=5*1024*1024; $uploaded=0; $video_id=null;
    $fh=fopen($video_path,'rb');
    if (!$fh) return ['success'=>false,'error'=>'Cannot open video file'];
    while (!feof($fh)) {
        $chunk=fread($fh,$chunk_size); $clen=strlen($chunk); $re=$uploaded+$clen-1;
        $ch=curl_init($upload_url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>$chunk,
            CURLOPT_HTTPHEADER=>['Content-Length: '.$clen,'Content-Range: bytes '.$uploaded.'-'.$re.'/'.$file_size,'Content-Type: '.$mime_type],
            CURLOPT_TIMEOUT=>300,CURLOPT_SSL_VERIFYPEER=>false]);
        $res=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $uploaded+=$clen;
        if ($http===308) continue;
        if ($http===200||$http===201){$vd=json_decode($res,true);$video_id=$vd['id']??null;break;}
        fclose($fh);
        return ['success'=>false,'error'=>"Upload failed at chunk (HTTP $http): $res"];
    }
    fclose($fh);
    if (!$video_id) return ['success'=>false,'error'=>'Upload complete but no video ID returned'];

    $yt_url='https://www.youtube.com/watch?v='.$video_id;
    $ev=mysqli_real_escape_string($conn,$video_id); $eu=mysqli_real_escape_string($conn,$yt_url);
    mysqli_query($conn,"UPDATE hdb_podcasts SET youtube_video_id='$ev',youtube_url='$eu',youtube_status='posted',updated_at=NOW() WHERE id=$podcast_id");
    maybe_mark_all_posted($conn, $podcast_id, $admin_id);
    return ['success'=>true,'video_id'=>$video_id,'url'=>$yt_url];
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $scopes = get_admin_scopes($conn, $admin_id);

    switch ($_POST['ajax_action']) {

        case 'get_stats':
            $total     = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_podcasts p WHERE p.admin_id=$admin_id AND video_status IN('RECORDED','SCHEDULED','POSTED')"))['c'];
            $recorded  = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_podcasts p WHERE p.admin_id=$admin_id AND video_status='RECORDED'"))['c'];
            $scheduled = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_podcasts p WHERE p.admin_id=$admin_id AND video_status='SCHEDULED'"))['c'];
            $posted    = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_podcasts p WHERE p.admin_id=$admin_id AND video_status='POSTED'"))['c'];
            $partial   = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM hdb_podcasts p WHERE p.admin_id=$admin_id AND video_status!='POSTED' AND (youtube_status='posted' OR facebook_status='posted' OR tiktok_status='posted' OR instagram_status='posted')"))['c'];
            echo json_encode(['success'=>true,'stats'=>compact('total','recorded','scheduled','posted','partial')]);
            exit;

        case 'get_rows':
            $filter = mysqli_real_escape_string($conn, $_POST['filter'] ?? 'recorded');

            if ($filter === 'recorded')
                $sc = "AND p.video_status='RECORDED'";
            elseif ($filter === 'scheduled')
                $sc = "AND p.video_status='SCHEDULED'";
            elseif ($filter === 'posted')
                $sc = "AND p.video_status='POSTED'";
            elseif ($filter === 'partial')
                $sc = "AND p.video_status!='POSTED' AND (p.youtube_status='posted' OR p.facebook_status='posted' OR p.tiktok_status='posted' OR p.instagram_status='posted')";
            else
                $sc = "AND p.video_status='RECORDED'";

            // Load company names for scope labels
            $company_names = [];
            $cq = mysqli_query($conn, "SELECT id, companyname FROM hdb_companies WHERE admin_id=$admin_id");
            if ($cq) while ($cr = mysqli_fetch_assoc($cq))
                $company_names[(int)$cr['id']] = $cr['companyname'] ?: 'Company '.$cr['id'];

            $rows = [];
            $q = mysqli_query($conn,
                "SELECT p.*, u.user_name as admin_name
                 FROM hdb_podcasts p
                 LEFT JOIN hdb_users u ON p.admin_id = u.id
                 WHERE p.admin_id = $admin_id $sc
                 ORDER BY p.company_id ASC, p.id DESC
                 LIMIT 200");
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $cid = (int)$r['company_id'];
                    $r['_scope_label'] = $company_names[$cid] ?? 'My Workspace';
                    $fname = resolve_video_filename($r);
                    $r['video_url']     = $fname ? '/published_videos/' . $fname : '';
                    $r['_video_exists'] = $fname && (file_exists(__DIR__.'/published_videos/'.$fname) || file_exists(__DIR__.'/podcast_videos/'.$fname));
                    $base  = $fname ? pathinfo($fname, PATHINFO_FILENAME) : '';
                    $thumb = '';
                    if ($base) {
                        foreach (['/published_videos/','/podcast_images/'] as $td) {
                            foreach (['.jpg','.png','.jpeg'] as $ext) {
                                if (file_exists(__DIR__.$td.$base.$ext)) { $thumb = $td.$base.$ext; break 2; }
                            }
                        }
                    }
                    $r['thumbnail_url'] = $thumb;
                    $rows[] = $r;
                }
            }
            echo json_encode(['success'=>true,'rows'=>$rows]);
            exit;

        case 'get_row':
            $id  = (int)($_POST['id'] ?? 0);
            $row = null;
            foreach ($scopes as $scope) {
                $sw  = scope_where($scope);
                $row = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM hdb_podcasts p WHERE $sw AND p.id=$id LIMIT 1"));
                if ($row) break;
            }
            echo json_encode(['success'=>!!$row,'row'=>$row]);
            exit;

        case 'update_row':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
            $fields = ['title','schedule_date','schedule_time','video_status','hashtags','keywords','caption_text'];
            $sets = [];
            foreach ($fields as $f) if (isset($_POST[$f])) { $v=mysqli_real_escape_string($conn,$_POST[$f]); $sets[]="$f='$v'"; }
            if (empty($sets)) { echo json_encode(['success'=>false,'error'=>'Nothing to update']); exit; }
            $ok = mysqli_query($conn,"UPDATE hdb_podcasts SET ".implode(',',$sets).",updated_at=NOW() WHERE id=$id AND admin_id=$admin_id");
            echo json_encode(['success'=>$ok,'error'=>$ok?null:mysqli_error($conn)]);
            exit;

        case 'schedule_one':
            $id    = (int)($_POST['id'] ?? 0);
            $date  = mysqli_real_escape_string($conn, $_POST['schedule_date'] ?? '');
            $time  = mysqli_real_escape_string($conn, $_POST['schedule_time'] ?? '09:00');
            $plats = array_filter(array_map('trim', explode(',', $_POST['platforms'] ?? '')));
            if (!$id || !$date) { echo json_encode(['success'=>false,'error'=>'Missing required data']); exit; }
            $sets  = ["schedule_date='$date'","schedule_time='$time'","video_status='SCHEDULED'","updated_at=NOW()"];
            foreach (['facebook','instagram','youtube','tiktok','twitter','linkedin'] as $p)
                $sets[] = "{$p}_status='".(in_array($p,$plats)?'pending':'skip')."'";
            // Save caption/hashtags if provided
            foreach (['caption_text','hashtags'] as $f)
                if (!empty($_POST[$f])) { $v=mysqli_real_escape_string($conn,$_POST[$f]); $sets[]="$f='$v'"; }
            $ok = mysqli_query($conn,"UPDATE hdb_podcasts SET ".implode(',',$sets)." WHERE id=$id AND admin_id=$admin_id");
            echo json_encode(['success'=>$ok,'error'=>$ok?null:mysqli_error($conn)]);
            exit;

        case 'bulk_schedule':
            $ids  = json_decode($_POST['ids'] ?? '[]', true);
            $date = mysqli_real_escape_string($conn, trim($_POST['schedule_date'] ?? ''));
            $time = mysqli_real_escape_string($conn, trim($_POST['schedule_time'] ?? ''));
            $intv = (int)($_POST['interval_hours'] ?? 3);
            if (empty($ids)||!$date||!$time){ echo json_encode(['success'=>false,'error'=>'Select rows and set date/time']); exit; }
            $dt = new DateTime("$date $time"); $count = 0;
            foreach ($ids as $id) {
                $id=(int)$id; $d=$dt->format('Y-m-d'); $t=$dt->format('H:i');
                mysqli_query($conn,"UPDATE hdb_podcasts SET schedule_date='$d',schedule_time='$t',video_status='SCHEDULED',updated_at=NOW() WHERE id=$id AND admin_id=$admin_id");
                if (mysqli_affected_rows($conn)>0) $count++;
                $dt->modify("+{$intv} hours");
            }
            echo json_encode(['success'=>true,'scheduled'=>$count]);
            exit;

        case 'post_now_facebook':
        case 'post_now_instagram': {
            $podcast_id = (int)($_POST['podcast_id'] ?? 0);
            $platform   = $_POST['ajax_action'] === 'post_now_facebook' ? 'facebook' : 'instagram';
            if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }

            // Load podcast
            $podcast = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT id,title,caption_text,hashtags,keywords,published_video,video_filename FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
            if (!$podcast) { echo json_encode(['success'=>false,'error'=>"Podcast #$podcast_id not found"]); exit; }

            $caption = trim(($podcast['caption_text']??'') . "\n\n" . ($podcast['hashtags']??''));
            if (!$caption) $caption = $podcast['title'] ?: 'VideoVizard #'.$podcast_id;

            $video_path = resolve_video_path($podcast);
            if (!$video_path) {
                $dbg_fname = resolve_video_filename($podcast);
                echo json_encode(['success'=>false,'error'=>"Video file not found. video_filename='{$dbg_fname}'. Looked in: " . __DIR__ . "/published_videos/{$dbg_fname}"]);
                exit;
            }

            // Load tokens from DB
            $fb_rows = [];
            $tq = mysqli_query($conn,
                "SELECT platform, access_token, channel_id, channel_name
                 FROM hdb_oauth_tokens
                 WHERE admin_id=$admin_id AND platform LIKE 'facebook%' AND token_expiry > NOW()");
            if ($tq) while ($tr = mysqli_fetch_assoc($tq)) $fb_rows[] = $tr;
            if (empty($fb_rows)) { echo json_encode(['success'=>false,'error'=>'Facebook not connected — click Connect Facebook in Social Accounts']); exit; }

            // Build pages list from DB rows
            $pages_db = [];
            $ig_token = ''; $ig_id = '';
            foreach ($fb_rows as $row) {
                if (strpos($row['platform'], 'facebook_page_') === 0) {
                    $pages_db[] = [
                        'id'           => $row['channel_id'],
                        'name'         => $row['channel_name'],
                        'access_token' => $row['access_token'],
                    ];
                }
            }
            // Get Instagram from DB
            $iq = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT access_token, channel_id FROM hdb_oauth_tokens
                 WHERE admin_id=$admin_id AND platform='instagram' AND token_expiry > NOW() LIMIT 1"));
            if ($iq) { $ig_token = $iq['access_token']; $ig_id = $iq['channel_id']; }

            if (empty($pages_db)) { echo json_encode(['success'=>false,'error'=>'No Facebook pages found — reconnect Facebook']); exit; }
            $selected_page = $pages_db[0]; // use first page

            $page_token = $selected_page['access_token'];
            $page_id    = $selected_page['id'];

            if ($platform === 'facebook') {
                // Post video to Facebook page
                $ch = curl_init("https://graph.facebook.com/v24.0/{$page_id}/videos");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => [
                        'source'       => new CURLFile($video_path),
                        'description'  => $caption,
                        'access_token' => $page_token,
                    ],
                    CURLOPT_TIMEOUT        => 300,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp = curl_exec($ch);
                $err  = curl_error($ch);
                curl_close($ch);
                $res = json_decode($resp, true);
                if (!empty($res['id'])) {
                    $vid = mysqli_real_escape_string($conn, $res['id']);
                    mysqli_query($conn,"UPDATE hdb_podcasts SET facebook_status='posted',updated_at=NOW() WHERE id=$podcast_id");
                    maybe_mark_all_posted($conn, $podcast_id, $admin_id);
                    echo json_encode(['success'=>true,'post_id'=>$res['id'],'platform'=>'facebook']);
                } else {
                    $msg = $res['error']['message'] ?? $err ?? $resp;
                    echo json_encode(['success'=>false,'error'=>"Facebook: $msg"]);
                }

            } else {
                // Instagram Reels
                if (!$ig_id || !$ig_token) { echo json_encode(['success'=>false,'error'=>'Instagram not connected — reconnect Facebook/Instagram']); exit; }
                $fname      = resolve_video_filename($podcast);
                $public_url = 'https://videovizard.com/published_videos/' . urlencode($fname);
                $token      = $ig_token;
                $selected_ig_id = $ig_id;

                // Step 1: Create container
                $ch = curl_init("https://graph.facebook.com/v24.0/{$selected_ig_id}/media");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'video_url'    => $public_url,
                        'media_type'   => 'REELS',
                        'caption'      => $caption,
                        'access_token' => $token,
                    ]),
                    CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp = curl_exec($ch); curl_close($ch);
                $res  = json_decode($resp, true);
                if (empty($res['id'])) {
                    $msg = $res['error']['message'] ?? $resp;
                    echo json_encode(['success'=>false,'error'=>"Instagram container error: $msg"]);
                    exit;
                }
                $creation_id = $res['id'];

                // Step 2: Wait for processing
                $ready = false;
                for ($i = 0; $i < 12; $i++) {
                    sleep(5);
                    $sr  = @file_get_contents("https://graph.facebook.com/v24.0/{$creation_id}?fields=status_code&access_token=" . urlencode($token));
                    $sj  = json_decode((string)$sr, true);
                    if (in_array($sj['status_code'] ?? '', ['FINISHED','PROCESSING_COMPLETE'])) { $ready = true; break; }
                }
                if (!$ready) { echo json_encode(['success'=>false,'error'=>'Instagram video processing timed out']); exit; }

                // Step 3: Publish
                $ch = curl_init("https://graph.facebook.com/v24.0/{$selected_ig_id}/media_publish");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS     => http_build_query(['creation_id'=>$creation_id,'access_token'=>$token]),
                    CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $resp = curl_exec($ch); curl_close($ch);
                $res  = json_decode($resp, true);
                if (!empty($res['id'])) {
                    mysqli_query($conn,"UPDATE hdb_podcasts SET instagram_status='posted',updated_at=NOW() WHERE id=$podcast_id");
                    maybe_mark_all_posted($conn, $podcast_id, $admin_id);
                    echo json_encode(['success'=>true,'post_id'=>$res['id'],'platform'=>'instagram']);
                } else {
                    $msg = $res['error']['message'] ?? $resp;
                    echo json_encode(['success'=>false,'error'=>"Instagram publish error: $msg"]);
                }
            }
            exit;
        }
    }
    echo json_encode(['success'=>false,'error'=>'Unknown action']);
    exit;
}

// ── Page setup ────────────────────────────────────────────────────────────────
$scopes              = get_admin_scopes($conn, $admin_id);
$connected_platforms = get_connected_platforms($conn, $admin_id);

// YouTube OAuth URL — store return page in session so callback knows where to go
$_SESSION['yt_oauth_return'] = 'vizard_scheduler.php';
$yt_state = bin2hex(random_bytes(16)).'|'.$admin_id;
$_SESSION['yt_oauth_state'] = $yt_state;
$yt_auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
    'client_id'     => YT_CLIENT_ID,   'redirect_uri'  => YT_REDIRECT_URI,
    'response_type' => 'code',          'scope'         => YT_SCOPE,
    'access_type'   => 'offline',       'prompt'        => 'consent',
    'state'         => $yt_state,
]);

$platform_oauth_urls = [
    'youtube'   => $yt_auth_url,
    'facebook'  => 'meta/fb_connect_vizard.php',
    'instagram' => 'meta/fb_connect_vizard.php',
    'tiktok'    => 'oauth_tiktok.php',
    'x'         => 'oauth_x.php',
    'linkedin'  => 'oauth_linkedin.php',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schedule & Publish — VideoVizard</title>
<script>
// If loaded inside an iframe, break out to full window
if (window.self !== window.top) {
    window.top.location.href = window.self.location.href;
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --bg:#f5f6fa;--bg2:#eef0f5;--card:#fff;--hover:#f0f2ff;
    --border:#dfe2ea;--text:#1e293b;--text2:#64748b;--muted:#94a3b8;
    --accent:#6c5ce7;--accent-glow:rgba(108,92,231,.15);
    --ok:#059669;--warn:#d97706;--err:#dc2626;--r:12px;--rs:8px;
    --sky-50:#f0f9ff;--sky-200:#bae6fd;--sky-600:#0284c7;--sky-900:#0c4a6e;
    --emerald:#059669;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Instrument Sans',sans-serif;background:var(--bg);color:var(--text);padding:20px;font-size:13px;}
.container{max-width:1400px;margin:0 auto;}

.page-header{display:flex;align-items:center;gap:20px;margin-bottom:25px;flex-wrap:wrap;}
.back-button{display:inline-flex;align-items:center;gap:8px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 18px;color:var(--text);text-decoration:none;font-weight:600;font-size:14px;transition:all .2s;}
.back-button:hover{background:var(--hover);border-color:var(--accent);transform:translateX(-3px);}
.header-title h1{font-size:24px;font-family:'Bricolage Grotesque',sans-serif;}
.header-title p{color:var(--text2);font-size:13px;margin-top:4px;}

/* Social Hub */
.social-hub{background:#fff;border:1px solid var(--border);border-radius:20px;padding:24px 28px;margin-bottom:28px;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.social-hub-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:20px;}
.social-hub-title{font-size:18px;font-weight:700;color:var(--sky-900);font-family:'Bricolage Grotesque',sans-serif;}
.social-hub-sub{font-size:12px;color:var(--text2);margin-top:4px;}
.hub-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);color:var(--emerald);padding:6px 14px;border-radius:40px;font-size:11px;font-weight:700;white-space:nowrap;}
.dot-green{width:7px;height:7px;background:var(--emerald);border-radius:50%;animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.platforms-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
@media(min-width:600px){.platforms-row{grid-template-columns:repeat(6,1fr);}}
.platform-tile{display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 10px;border-radius:14px;cursor:pointer;border:1.5px solid var(--sky-200);background:var(--sky-50);transition:all .25s;position:relative;}
.platform-tile:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.08);}
.platform-tile.connected{border-color:var(--emerald);background:rgba(5,150,105,.05);}
.pt-icon{font-size:24px;}
.pt-name{font-size:10px;font-weight:600;color:var(--sky-900);}
.pt-status{position:absolute;top:6px;right:6px;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;}
.pt-status.conn{background:var(--emerald);color:#fff;}
.pt-status.disc{background:var(--sky-200);color:var(--sky-600);}
.connect-btn{margin-top:2px;padding:4px 10px;border-radius:20px;border:1.5px solid currentColor;background:transparent;font-size:9px;font-weight:700;cursor:pointer;transition:all .2s;}
.connect-btn.connected-btn{color:var(--emerald);}
.connect-btn.disconnected-btn{color:var(--sky-600);}
.connect-btn:hover{background:currentColor;color:#fff;}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:20px;}
.stat{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:16px;text-align:center;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);cursor:pointer;transition:box-shadow .2s;}
.stat:hover{box-shadow:0 4px 12px rgba(0,0,0,.08);}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat.s1::before{background:var(--accent);}
.stat.s2::before{background:#3b82f6;}
.stat.s3::before{background:var(--warn);}
.stat.s4::before{background:var(--ok);}
.stat.s5::before{background:var(--err);}
.stat-val{font-size:28px;font-weight:700;}
.stat-lbl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:4px;}

/* Toolbar */
.toolbar{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;}
.tabs{display:flex;gap:5px;flex-wrap:wrap;}
.tab{padding:7px 16px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text2);transition:all .2s;}
.tab:hover{background:var(--hover);}
.tab.active{background:var(--accent-glow);color:var(--accent);border-color:var(--accent);}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border:none;border-radius:var(--rs);font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-primary{background:var(--accent);color:#fff;}
.btn-sec{background:var(--bg2);color:var(--text);border:1px solid var(--border);}
.btn-sm{padding:5px 10px;font-size:11px;}
.btn-ok{background:var(--ok);color:#fff;}
.btn-orange{background:var(--warn);color:#fff;}
.btn-blue{background:#3b82f6;color:#fff;}
.btn-dl{background:#0369a1;color:#fff;font-size:13px;padding:6px 13px;font-weight:700;border-radius:8px;text-decoration:none;}
.btn:disabled{opacity:.4;cursor:not-allowed;}
.btn:hover:not(:disabled){filter:brightness(1.08);}

/* Table */
.scope-divider{padding:8px 12px;background:var(--bg2);font-size:10px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--border);}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;padding:10px 12px;font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:var(--bg2);position:sticky;top:0;z-index:1;}
td{padding:9px 12px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:middle;}
tr:hover td{background:var(--hover);}
.badge{display:inline-flex;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;}
.b-posted{background:rgba(5,150,105,.1);color:var(--ok);}
.b-pending{background:rgba(148,163,184,.12);color:var(--muted);}
.b-scheduled{background:rgba(108,92,231,.1);color:var(--accent);}
.plat-row{display:flex;gap:5px;flex-wrap:wrap;}
.plat-dot{display:flex;flex-direction:column;align-items:center;gap:2px;min-width:36px;}
.plat-dot .pd-icon{width:26px;height:26px;border-radius:50%;font-size:11px;display:flex;align-items:center;justify-content:center;border:2px solid var(--border);background:var(--bg2);transition:all .2s;}
.plat-dot .pd-label{font-size:9px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--muted);}
.plat-dot.posted  .pd-icon{border-color:var(--ok);background:rgba(5,150,105,.12);}
.plat-dot.posted  .pd-label{color:var(--ok);}
.plat-dot.failed  .pd-icon{border-color:var(--err);background:rgba(220,38,38,.10);}
.plat-dot.failed  .pd-label{color:var(--err);}
.plat-dot.pending .pd-icon{border-color:#f59e0b;background:rgba(245,158,11,.10);}
.plat-dot.pending .pd-label{color:#f59e0b;}
.plat-dot.skip    .pd-icon{opacity:.3;}
.plat-dot.skip    .pd-label{opacity:.3;}
.cb{width:16px;height:16px;cursor:pointer;accent-color:var(--accent);}
.action-buttons{display:flex;gap:5px;flex-wrap:wrap;align-items:center;}
.admin-badge{display:inline-block;background:rgba(108,92,231,.1);color:var(--accent);padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600;}

/* Thumbnail */
.thumb-wrap{position:relative;display:inline-block;width:64px;height:64px;cursor:pointer;}
.video-thumbnail{width:64px;height:64px;object-fit:cover;border-radius:8px;border:2px solid var(--border);display:block;transition:filter .2s;}
.thumb-wrap:hover .video-thumbnail{filter:brightness(.7);}
.thumb-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:22px;opacity:0;transition:opacity .2s;}
.thumb-wrap:hover .thumb-play{opacity:1;}
.no-thumb{width:64px;height:64px;border-radius:8px;border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:22px;cursor:pointer;}

/* Modals */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:5000;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
.modal-bg.show{display:flex;}
.modal{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:24px;width:90%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);position:relative;}
.modal h3{font-size:16px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid var(--border);}
.modal-close{position:absolute;top:12px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--muted);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-field label{display:block;font-size:11px;font-weight:600;color:var(--text2);margin-bottom:4px;}
.form-field input,.form-field select,.form-field textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:var(--rs);font-family:inherit;font-size:12px;color:var(--text);background:var(--bg);}
.form-field textarea{min-height:60px;resize:vertical;}
.form-field.full{grid-column:1/-1;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;padding-top:12px;border-top:1px solid var(--border);}
.modal-status{font-size:12px;margin-top:8px;min-height:18px;}

.plat-checks{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.plat-check-item{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;padding:5px 10px;border-radius:20px;border:1px solid var(--border);}
.plat-check-item:has(input:checked){border-color:var(--accent);background:var(--accent-glow);color:var(--accent);}

/* Post Now */
.postnow-platforms{display:flex;flex-direction:column;gap:10px;margin:14px 0;}
.pn-platform{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;border:1.5px solid var(--border);transition:all .2s;}
.pn-platform.connected{border-color:var(--emerald);background:rgba(5,150,105,.04);}
.pn-platform.not-connected{opacity:.65;}
.pn-platform-icon{font-size:24px;min-width:30px;text-align:center;}
.pn-platform-info{flex:1;}
.pn-platform-name{font-weight:700;font-size:13px;}
.pn-platform-status{font-size:11px;color:var(--text2);margin-top:2px;}
.pn-platform-btn{padding:6px 16px;border-radius:20px;border:none;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;}
.pn-platform-btn.post{background:var(--accent);color:#fff;}
.pn-platform-btn.connect{background:var(--warn);color:#fff;}
.pn-platform-btn:disabled{opacity:.5;cursor:not-allowed;}
.pn-result{padding:10px 14px;border-radius:8px;font-size:12px;margin-top:8px;}
.pn-result.ok{background:#f0fdf4;color:var(--ok);border:1px solid #86efac;}
.pn-result.err{background:#fef2f2;color:var(--err);border:1px solid #fca5a5;}

/* Social modal */
.social-modal-overlay{display:none;position:fixed;inset:0;background:rgba(6,34,54,.85);z-index:6000;align-items:center;justify-content:center;padding:20px;}
.social-modal-overlay.open{display:flex;}
.social-modal-box{background:#fff;border-radius:24px;width:100%;max-width:440px;padding:36px 32px;animation:modalIn .3s cubic-bezier(.22,.68,0,1.2) both;}
@keyframes modalIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}
.smodal-icon{font-size:48px;text-align:center;margin-bottom:14px;}
.smodal-title{font-family:'Bricolage Grotesque',sans-serif;font-size:22px;font-weight:800;text-align:center;color:var(--sky-900);margin-bottom:6px;}
.smodal-sub{font-size:14px;color:var(--text2);text-align:center;line-height:1.6;margin-bottom:24px;}
.smodal-btn{display:block;width:100%;padding:14px;border-radius:50px;border:none;cursor:pointer;font-family:'Bricolage Grotesque',sans-serif;font-size:15px;font-weight:700;color:#fff;text-align:center;margin-bottom:10px;transition:all .2s;}
.smodal-cancel{display:block;width:100%;padding:12px;border-radius:50px;border:1.5px solid var(--sky-200);background:transparent;font-size:14px;font-weight:600;color:var(--text2);cursor:pointer;text-align:center;}
.smodal-cancel:hover{border-color:var(--sky-600);}
.smodal-note{font-size:11px;color:var(--text2);text-align:center;margin-top:14px;}

/* Toasts */
.toast-wrap{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast{padding:12px 18px;border-radius:var(--rs);font-size:12px;font-weight:500;animation:slideIn .3s ease;box-shadow:0 4px 12px rgba(0,0,0,.1);}
.t-ok{background:#ecfdf5;color:var(--ok);border:1px solid rgba(5,150,105,.2);}
.t-err{background:#fef2f2;color:var(--err);border:1px solid rgba(220,38,38,.2);}
.t-inf{background:#eff6ff;color:#1d4ed8;border:1px solid rgba(59,130,246,.2);}
@keyframes slideIn{from{transform:translateX(80px);opacity:0}to{transform:translateX(0);opacity:1}}
.empty{text-align:center;padding:48px;color:var(--muted);}
.spinner{display:inline-block;width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .5s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:768px){.stats-row{grid-template-columns:1fr 1fr;}.form-grid{grid-column:1fr;}}
</style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="page-header">
        <a href="vizard_browser.php" class="back-button"><i class="fas fa-arrow-left"></i> Back to VideoVizard</a>
        <div class="header-title">
            <h1>📡 Schedule & Publish</h1>
            <p>Manage recorded videos — post immediately or schedule across platforms.</p>
        </div>
    </div>

    <!-- Social Accounts -->
    <div class="social-hub">
        <div class="social-hub-head">
            <div>
                <div class="social-hub-title">🔗 Your Social Accounts</div>
                <div class="social-hub-sub">Connect accounts below. Click any platform to connect or manage. Green = connected and ready to post.</div>
            </div>
            <div class="hub-badge"><span class="dot-green"></span> Publish Ready</div>
        </div>
        <div class="platforms-row">
        <?php
        $platform_defs = [
            'facebook'  => ['Facebook',   'fab fa-facebook',   '#1877f2', '📘'],
            'instagram' => ['Instagram',  'fab fa-instagram',  '#e1306c', '📸'],
            'youtube'   => ['YouTube',    'fab fa-youtube',    '#ff0000', '▶️'],
            'tiktok'    => ['TikTok',     'fab fa-tiktok',     '#010101', '🎵'],
            'x'         => ['X / Twitter','fab fa-x-twitter',  '#000000', '🐦'],
            'linkedin'  => ['LinkedIn',   'fab fa-linkedin',   '#0077b5', '💼'],
        ];
        foreach ($platform_defs as $key => [$name, $icon, $color, $emoji]):
            $isc = isset($connected_platforms[$key]);
        ?>
        <div class="platform-tile <?= $isc?'connected':'' ?>" data-platform="<?= $key ?>"
             onclick="openSocialModal('<?= $key ?>','<?= $name ?>','<?= $color ?>','<?= $emoji ?>')">
            <div class="pt-status <?= $isc?'conn':'disc' ?>"><?= $isc?'✓':'+' ?></div>
            <i class="pt-icon <?= $icon ?>" style="color:<?= $color ?>"></i>
            <span class="pt-name"><?= $name ?></span>
            <button class="connect-btn <?= $isc?'connected-btn':'disconnected-btn' ?>"
                    style="color:<?= $isc?'var(--emerald)':$color ?>"
                    onclick="event.stopPropagation();openSocialModal('<?= $key ?>','<?= $name ?>','<?= $color ?>','<?= $emoji ?>')">
                <?= $isc?'✓ Connected':'Connect' ?>
            </button>
        </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Stats (clickable) -->
    <div class="stats-row">
        <div class="stat s1" onclick="loadRows('all')"><div class="stat-val" id="st-total">-</div><div class="stat-lbl">Total Active</div></div>
        <div class="stat s2" onclick="loadRows('recorded',document.querySelector('.tab'))"><div class="stat-val" id="st-recorded">-</div><div class="stat-lbl">Recorded</div></div>
        <div class="stat s3" onclick="loadRows('scheduled')"><div class="stat-val" id="st-scheduled">-</div><div class="stat-lbl">Scheduled</div></div>
        <div class="stat s4" onclick="loadRows('posted')"><div class="stat-val" id="st-posted">-</div><div class="stat-lbl">Fully Posted</div></div>
        <div class="stat s5" onclick="loadRows('partial')"><div class="stat-val" id="st-partial">-</div><div class="stat-lbl">Partial</div></div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="tabs">
            <button class="tab active" onclick="loadRows('recorded',this)">📼 Recorded</button>
            <button class="tab" onclick="loadRows('scheduled',this)">📅 Scheduled</button>
            <button class="tab" onclick="loadRows('partial',this)">⚡ Partially Posted</button>
            <button class="tab" onclick="loadRows('posted',this)">✅ Fully Posted</button>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button class="btn btn-primary" onclick="openBulkSchedule()">📅 Bulk Schedule</button>
        </div>
    </div>

    <!-- Table -->
    <div class="card"><div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" class="cb" onchange="toggleAll(this)"></th>
                    <th>ID</th>
                    <th>Thumbnail</th>
                    <th>Title / Creator</th>
                    <th>Space</th>
                    <th>Schedule</th>
                    <th>Platforms</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="rowList">
                <tr><td colspan="9" class="empty"><div class="spinner"></div> Loading…</td></tr>
            </tbody>
        </table>
    </div></div>
</div>

<!-- Schedule Modal -->
<div class="modal-bg" id="editModal" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal">
        <button class="modal-close" onclick="closeEdit()">&times;</button>
        <h3>📅 Schedule Video #<span id="edit-id-label"></span></h3>
        <input type="hidden" id="edit-id">
        <div class="form-grid">
            <div class="form-field"><label>Schedule Date</label><input type="date" id="edit-schedule_date"></div>
            <div class="form-field"><label>Schedule Time</label><input type="time" id="edit-schedule_time" value="09:00"></div>
            <div class="form-field full">
                <label>Post to Platforms</label>
                <div class="plat-checks">
                    <?php foreach (['youtube','facebook','instagram','tiktok','x','linkedin'] as $p): ?>
                    <label class="plat-check-item">
                        <input type="checkbox" name="plat_<?= $p ?>" value="<?= $p ?>"
                            <?= isset($connected_platforms[$p]) ? 'checked' : '' ?>>
                        <?= ucfirst($p === 'x' ? 'X / Twitter' : $p) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-field full"><label>Caption Text</label><textarea id="edit-caption_text" rows="3"></textarea></div>
            <div class="form-field full"><label>Hashtags</label><textarea id="edit-hashtags" rows="2"></textarea></div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-sec" onclick="closeEdit()">Cancel</button>
            <button class="btn btn-ok" id="edit-save-btn" onclick="saveSchedule()">📅 Save Schedule</button>
        </div>
        <div class="modal-status" id="edit-status"></div>
    </div>
</div>

<!-- Post Now Modal -->
<div class="modal-bg" id="postNowModal" onclick="if(event.target===this)closePostNow()">
    <div class="modal" style="max-width:460px;">
        <button class="modal-close" onclick="closePostNow()">&times;</button>
        <h3>🚀 Post Now</h3>
        <div style="background:var(--bg2);border-radius:8px;padding:10px 14px;margin-bottom:4px;" id="pn-title"></div>
        <div class="postnow-platforms" id="pn-platforms"></div>
        <div id="pn-result"></div>
        <div class="modal-actions">
            <button class="btn btn-sec" onclick="closePostNow()">Close</button>
        </div>
    </div>
</div>

<!-- Video Preview Modal -->
<div class="modal-bg" id="videoModal" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal" style="max-width:800px;">
        <button class="modal-close" onclick="document.getElementById('videoModal').classList.remove('show')">&times;</button>
        <h3 id="videoModalTitle">Video Preview</h3>
        <div style="text-align:center;margin:20px 0;">
            <video id="videoPlayer" controls style="max-width:100%;max-height:400px;border-radius:8px;">
                <source src="" type="video/mp4">
            </video>
        </div>
        <div class="modal-actions">
            <button class="btn btn-sec" onclick="document.getElementById('videoModal').classList.remove('show')">Close</button>
            <a id="downloadVideoLink" href="" download class="btn btn-dl">⬇ Download MP4</a>
        </div>
    </div>
</div>

<!-- Bulk Schedule Modal -->
<div class="modal-bg" id="bulkModal" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal">
        <button class="modal-close" onclick="document.getElementById('bulkModal').classList.remove('show')">&times;</button>
        <h3>📅 Bulk Schedule Selected</h3>
        <div class="form-grid">
            <div class="form-field"><label>Start Date</label><input type="date" id="bulkDate"></div>
            <div class="form-field"><label>Start Time</label><input type="time" id="bulkTime" value="09:00"></div>
            <div class="form-field full"><label>Interval Between Posts</label>
                <select id="bulkInterval">
                    <option value="1">1 hour</option><option value="2">2 hours</option>
                    <option value="3" selected>3 hours</option><option value="6">6 hours</option>
                    <option value="12">12 hours</option><option value="24">24 hours</option>
                </select>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-sec" onclick="document.getElementById('bulkModal').classList.remove('show')">Cancel</button>
            <button class="btn btn-primary" onclick="submitBulk()">✓ Schedule All Selected</button>
        </div>
    </div>
</div>

<!-- Social Connect Modal -->
<div class="social-modal-overlay" id="socialModal" onclick="closeSocialModal(event)">
    <div class="social-modal-box">
        <div class="smodal-icon" id="sModalIcon">📱</div>
        <div class="smodal-title" id="sModalTitle">Connect Account</div>
        <div class="smodal-sub" id="sModalSub"></div>
        <button class="smodal-btn" id="sModalAuthBtn" onclick="doOAuth()">Connect →</button>
        <button class="smodal-cancel" onclick="closeSocialModalDirect()">Maybe later</button>
        <div class="smodal-note">We only post content you approve. Disconnect any time.</div>
    </div>
</div>

<div class="toast-wrap" id="toasts"></div>

<script>
const PLAT_ICONS = {facebook:'📘',tiktok:'🎵',instagram:'📸',youtube:'▶️',twitter:'✖️',linkedin:'💼',x:'🐦'};
const PLATS      = ['facebook','tiktok','instagram','youtube','twitter','linkedin'];
const CONNECTED  = <?= json_encode(array_keys($connected_platforms)) ?>;
const OAUTH_URLS = <?= json_encode($platform_oauth_urls) ?>;
const platformMeta = {
    facebook:  {icon:'📘',label:'Facebook',    color:'#1877f2'},
    instagram: {icon:'📸',label:'Instagram',   color:'#e1306c'},
    youtube:   {icon:'▶️', label:'YouTube',    color:'#ff0000'},
    tiktok:    {icon:'🎵',label:'TikTok',      color:'#010101'},
    x:         {icon:'🐦',label:'X / Twitter', color:'#000000'},
    linkedin:  {icon:'💼',label:'LinkedIn',    color:'#0077b5'},
};

let currentFilter    = 'recorded';
let activePlatform   = null;
let postNowPodcastId = null;

// ── API ───────────────────────────────────────────────────────────────────────
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('ajax_action', action);
    for (const [k, v] of Object.entries(data))
        fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
    const r = await fetch(window.location.href, {method:'POST', body:fd});
    return r.json();
}

// ── Stats ─────────────────────────────────────────────────────────────────────
async function loadStats() {
    const d = await api('get_stats');
    if (!d.success) return;
    ['total','recorded','scheduled','posted','partial'].forEach(k =>
        document.getElementById('st-'+k).textContent = d.stats[k] ?? 0);
}

// ── Rows ──────────────────────────────────────────────────────────────────────
async function loadRows(filter, btn) {
    currentFilter = filter;
    if (btn) { document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active')); btn.classList.add('active'); }
    const tbody = document.getElementById('rowList');
    tbody.innerHTML = '<tr><td colspan="9" class="empty"><div class="spinner"></div> Loading…</td></tr>';
    const d = await api('get_rows', {filter});
    if (!d.success || !d.rows?.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="empty">📭 No videos found</td></tr>';
        return;
    }

    let lastScope = null, html = '';
    for (const r of d.rows) {
        if (r._scope_label !== lastScope) {
            lastScope = r._scope_label;
            html += `<tr><td colspan="9" class="scope-divider">📁 ${esc(r._scope_label)}</td></tr>`;
        }

        const dots = PLATS
            .filter(p => CONNECTED.includes(p))   // only show connected platforms
            .map(p => {
                const s   = (r[p+'_status']||'').toLowerCase();
                const cls = s==='posted'  ? 'posted'
                          : s==='failed'  ? 'failed'
                          : s==='skip'    ? 'skip'
                          : s==='pending' ? 'pending'
                          : 'skip';
                const lbl = s==='posted'  ? 'Posted'
                          : s==='failed'  ? 'Failed'
                          : s==='pending' ? 'Pending'
                          : '—';
                const title = `${p}: ${s||'not set'}`;
                return `<span class="plat-dot ${cls}" title="${title}">
                    <span class="pd-icon">${PLAT_ICONS[p]||p[0].toUpperCase()}</span>
                    <span class="pd-label">${lbl}</span>
                </span>`;
            }).join('');

        const badge = r.video_status==='POSTED'   ? '<span class="badge b-posted">posted</span>'
                    : r.video_status==='SCHEDULED' ? '<span class="badge b-scheduled">scheduled</span>'
                    : r.video_status==='RECORDED'  ? '<span class="badge b-pending">recorded</span>'
                    : '<span class="badge b-pending">—</span>';

        const sched = r.schedule_date
            ? `<span style="font-weight:600">${r.schedule_date}</span> ${r.schedule_time||''}`
            : '<span style="color:var(--muted)">—</span>';

        const tShort = esc(r.title||'').substring(0,42)+((r.title||'').length>42?'…':'');
        const admin  = r.admin_name ? `<span class="admin-badge">👤 ${esc(r.admin_name)}</span>` : '';

        // Thumbnail
        let thumbHtml;
        if (r.thumbnail_url) {
            thumbHtml = `<div class="thumb-wrap" onclick="playVideo('${esc(r.video_url)}','${esc(r.title||'')}')">
                <img src="${r.thumbnail_url}" class="video-thumbnail" alt=""
                     onerror="this.parentElement.innerHTML='<div class=\\'no-thumb\\'>🎬</div>'">
                <span class="thumb-play">▶️</span>
            </div>`;
        } else {
            thumbHtml = `<div class="no-thumb" onclick="playVideo('${esc(r.video_url)}','${esc(r.title||'')}')">🎬</div>`;
        }

        const isRecorded = r.video_status === 'RECORDED';
        const hasVideo   = !!r.video_url && r._video_exists;

        // ⬇ Download — prominent, only on RECORDED with existing file
        const dlBtn = (isRecorded && hasVideo)
            ? `<a href="${esc(r.video_url)}" download="${esc(r.video_url.split('/').pop())}" class="btn-dl" title="Download MP4">⬇</a>`
            : '';

        // 🗓 Schedule (always shown)
        const schedBtn = `<button class="btn btn-sm btn-orange" onclick="openSchedule(${r.id})" title="Schedule for later">🗓 Schedule</button>`;

        // 🚀 Post Now — only RECORDED videos
        const postBtn = isRecorded
            ? `<button class="btn btn-sm btn-ok" onclick="openPostNow(${r.id},'${esc(r.title||'Video #'+r.id)}','${esc(r.video_url ? r.video_url.split('/').pop() : '')}')">🚀 Post Now</button>`
            : '';

        html += `<tr>
            <td><input type="checkbox" class="cb row-cb" value="${r.id}"></td>
            <td style="color:var(--muted);font-weight:600">${r.id}</td>
            <td>${thumbHtml}</td>
            <td>
                <div style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.title)}">${tShort}</div>
                <div style="font-size:10px;margin-top:3px">${admin}</div>
            </td>
            <td style="font-size:10px;color:var(--text2)">${esc(r._scope_label)}</td>
            <td style="font-size:11px">${sched}</td>
            <td><div class="plat-row">${dots}</div></td>
            <td>${badge}</td>
            <td><div class="action-buttons">${dlBtn} ${schedBtn} ${postBtn}</div></td>
        </tr>`;
    }
    tbody.innerHTML = html;
}

// ── Video preview ─────────────────────────────────────────────────────────────
function playVideo(url, title) {
    if (!url) { toast('No video file', 'err'); return; }
    const vp = document.getElementById('videoPlayer');
    vp.querySelector('source').src = url;
    vp.load();
    document.getElementById('videoModalTitle').textContent = title || 'Preview';
    const dl = document.getElementById('downloadVideoLink');
    dl.href = url; dl.download = url.split('/').pop();
    document.getElementById('videoModal').classList.add('show');
}

// ── Schedule modal ────────────────────────────────────────────────────────────
async function openSchedule(id) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-id-label').textContent = id;
    document.getElementById('edit-status').innerHTML = '';
    document.getElementById('edit-schedule_date').value = new Date().toISOString().split('T')[0];
    document.getElementById('edit-schedule_time').value = '09:00';
    document.getElementById('edit-caption_text').value = '';
    document.getElementById('edit-hashtags').value = '';
    // Pre-check connected platforms only
    document.querySelectorAll('.plat-check-item input').forEach(cb => {
        cb.checked = CONNECTED.includes(cb.value);
    });
    // Load existing data
    try {
        const d = await api('get_row', {id});
        if (d.success && d.row) {
            const r = d.row;
            if (r.schedule_date) document.getElementById('edit-schedule_date').value = r.schedule_date;
            if (r.schedule_time) document.getElementById('edit-schedule_time').value = r.schedule_time;
            if (r.caption_text) document.getElementById('edit-caption_text').value = r.caption_text;
            if (r.hashtags)     document.getElementById('edit-hashtags').value = r.hashtags;
        }
    } catch(e) {}
    document.getElementById('editModal').classList.add('show');
}

async function saveSchedule() {
    const btn  = document.getElementById('edit-save-btn');
    const st   = document.getElementById('edit-status');
    const date = document.getElementById('edit-schedule_date').value;
    const time = document.getElementById('edit-schedule_time').value;
    if (!date) { st.innerHTML = '<span style="color:var(--err)">❌ Date required</span>'; return; }
    const plats = [...document.querySelectorAll('.plat-check-item input:checked')].map(c=>c.value);
    btn.disabled = true;
    st.innerHTML = '<span style="color:var(--accent)">Saving…</span>';
    const d = await api('schedule_one', {
        id:           document.getElementById('edit-id').value,
        schedule_date: date, schedule_time: time,
        platforms:    plats.join(','),
        caption_text: document.getElementById('edit-caption_text').value,
        hashtags:     document.getElementById('edit-hashtags').value,
    });
    btn.disabled = false;
    if (d.success) {
        st.innerHTML = '<span style="color:var(--ok)">✅ Scheduled!</span>';
        toast('Video scheduled successfully', 'ok');
        setTimeout(() => { closeEdit(); loadRows(currentFilter); loadStats(); }, 700);
    } else {
        st.innerHTML = `<span style="color:var(--err)">❌ ${d.error||'Failed'}</span>`;
    }
}

function closeEdit() { document.getElementById('editModal').classList.remove('show'); }

// ── Post Now ──────────────────────────────────────────────────────────────────
function openPostNow(podcastId, title, videoFile) {
    postNowPodcastId = podcastId;
    document.getElementById('pn-title').innerHTML =
        `<div style="font-weight:700;font-size:13px;">${esc(title||'Video #'+podcastId)}</div>
         <div style="font-size:11px;color:var(--muted);margin-top:2px;">📹 ${esc(videoFile||'—')}</div>`;
    document.getElementById('pn-result').innerHTML   = '';

    const platformOrder = [
        ['youtube',   '▶️', 'YouTube',   '#ff0000', true],
        ['facebook',  '📘', 'Facebook',  '#1877f2', true],
        ['instagram', '📸', 'Instagram', '#e1306c', true],
        ['tiktok',    '🎵', 'TikTok',    '#010101', false],
    ];

    let html = '';
    for (const [key, icon, name, color, implemented] of platformOrder) {
        const isConn = CONNECTED.includes(key);
        html += `<div class="pn-platform ${isConn?'connected':'not-connected'}" id="pn-row-${key}">
            <span class="pn-platform-icon">${icon}</span>
            <div class="pn-platform-info">
                <div class="pn-platform-name" style="color:${color}">${name}</div>
                <div class="pn-platform-status" id="pn-status-${key}">
                    ${isConn ? (implemented ? 'Connected — select to post' : 'Connected — coming soon') : '⚠ Not connected'}
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">`;

        if (!isConn) {
            html += `<button class="pn-platform-btn connect" onclick="window.location.href=OAUTH_URLS['${key}']||'#'">Connect</button>`;
        } else if (implemented) {
            html += `<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;">
                <input type="checkbox" id="pn-chk-${key}" value="${key}" checked
                    style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;">
                Post here
            </label>`;
        } else {
            html += `<span style="font-size:10px;color:var(--muted);font-weight:600;">SOON</span>`;
        }
        html += `</div></div>`;
    }

    html += `<div style="margin-top:14px;">
        <button class="btn btn-ok" style="width:100%;padding:12px;font-size:13px;" onclick="postNowSubmit(${podcastId})">
            🚀 Post Now to Selected
        </button>
    </div>`;

    document.getElementById('pn-platforms').innerHTML = html;
    document.getElementById('postNowModal').classList.add('show');
}

async function postNowSubmit(podcastId) {
    const selected = [...document.querySelectorAll('#pn-platforms input[type=checkbox]:checked')].map(c => c.value);
    if (!selected.length) { toast('Select at least one platform', 'err'); return; }

    const result = document.getElementById('pn-result');
    result.innerHTML = '';

    for (const platform of selected) {
        const status = document.getElementById('pn-status-'+platform);
        const chk    = document.getElementById('pn-chk-'+platform);
        if (chk) chk.disabled = true;
        if (status) status.textContent = '⏳ Uploading…';

        if (platform === 'youtube') {
            try {
                const d = await api('post_now_youtube', {podcast_id: podcastId});
                if (d.success) {
                    if (status) status.textContent = '✅ Posted!';
                    if (chk) chk.disabled = true;
                    result.innerHTML += `<div class="pn-result ok" style="margin-top:6px;">
                        ✅ YouTube uploaded!
                        <a href="${d.url}" target="_blank" style="color:var(--ok);font-weight:700;margin-left:6px;">View ↗</a>
                    </div>`;
                    toast('✅ Posted to YouTube!', 'ok');
                } else {
                    if (status) status.textContent = '❌ Failed';
                    if (chk) chk.disabled = false;
                    result.innerHTML += `<div class="pn-result err" style="margin-top:6px;">❌ YouTube: ${d.error||'Failed'}</div>`;
                }
            } catch(e) {
                if (status) status.textContent = '❌ Error';
                if (chk) chk.disabled = false;
                result.innerHTML += `<div class="pn-result err" style="margin-top:6px;">❌ YouTube: ${e.message}</div>`;
            }

        } else if (platform === 'facebook' || platform === 'instagram') {
            const action = platform === 'facebook' ? 'post_now_facebook' : 'post_now_instagram';
            if (status) status.textContent = platform === 'instagram' ? '⏳ Processing video…' : '⏳ Uploading…';
            try {
                const d = await api(action, {podcast_id: podcastId});
                if (d.success) {
                    if (status) status.textContent = '✅ Posted!';
                    if (chk) chk.disabled = true;
                    const name = platform === 'facebook' ? 'Facebook' : 'Instagram';
                    result.innerHTML += `<div class="pn-result ok" style="margin-top:6px;">✅ ${name} posted! Post ID: ${d.post_id}</div>`;
                    toast(`✅ Posted to ${name}!`, 'ok');
                } else {
                    if (status) status.textContent = '❌ Failed';
                    if (chk) chk.disabled = false;
                    result.innerHTML += `<div class="pn-result err" style="margin-top:6px;">❌ ${d.error||'Failed'}</div>`;
                }
            } catch(e) {
                if (status) status.textContent = '❌ Error';
                if (chk) chk.disabled = false;
                result.innerHTML += `<div class="pn-result err" style="margin-top:6px;">❌ ${e.message}</div>`;
            }
        }
    }
    loadRows(currentFilter);
    loadStats();
}

function closePostNow() {
    document.getElementById('postNowModal').classList.remove('show');
    postNowPodcastId = null;
}

// ── Bulk schedule ─────────────────────────────────────────────────────────────
function openBulkSchedule() {
    const checked = [...document.querySelectorAll('.row-cb:checked')];
    if (!checked.length) { toast('Select rows first', 'err'); return; }
    document.getElementById('bulkDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('bulkModal').classList.add('show');
}

async function submitBulk() {
    const ids = [...document.querySelectorAll('.row-cb:checked')].map(c=>c.value);
    const d   = await api('bulk_schedule', {
        ids,
        schedule_date:  document.getElementById('bulkDate').value,
        schedule_time:  document.getElementById('bulkTime').value,
        interval_hours: document.getElementById('bulkInterval').value,
    });
    document.getElementById('bulkModal').classList.remove('show');
    if (d.success) { toast(`✓ ${d.scheduled} video(s) scheduled`, 'ok'); loadRows(currentFilter); loadStats(); }
    else toast('Error: '+(d.error||'Failed'), 'err');
}

function toggleAll(m) { document.querySelectorAll('.row-cb').forEach(c=>c.checked=m.checked); }

// ── Social modal ──────────────────────────────────────────────────────────────
function openSocialModal(platform, name, color, emoji) {
    activePlatform = platform;
    const meta  = platformMeta[platform] || {};
    const isConn = CONNECTED.includes(platform);
    document.getElementById('sModalIcon').textContent  = emoji || meta.icon || '📱';
    document.getElementById('sModalTitle').textContent = (meta.label||name) + ' Connection';
    document.getElementById('sModalSub').textContent   = isConn
        ? `✅ Your ${meta.label||name} account is connected. You can reconnect or switch account below.`
        : `Connect your ${meta.label||name} account so VideoVizard can post your approved videos automatically.`;
    const btn = document.getElementById('sModalAuthBtn');
    btn.textContent     = isConn ? `🔄 Reconnect ${meta.label||name}` : `Connect ${meta.label||name} →`;
    btn.style.background = meta.color || color;
    document.getElementById('socialModal').classList.add('open');
}
function closeSocialModal(e) { if (e.target===document.getElementById('socialModal')) closeSocialModalDirect(); }
function closeSocialModalDirect() { document.getElementById('socialModal').classList.remove('open'); activePlatform=null; }
function doOAuth() {
    if (!activePlatform) return;
    const url = OAUTH_URLS[activePlatform];
    if (url) window.location.href = url;
    else toast('OAuth not configured for '+activePlatform, 'err');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(t) {
    if (!t) return '';
    const d = document.createElement('div'); d.textContent = t; return d.innerHTML;
}
function toast(msg, type='ok') {
    const el = document.createElement('div');
    el.className = 'toast t-'+type; el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(()=>{ el.style.opacity='0'; setTimeout(()=>el.remove(),300); }, 4000);
}

// Init
const _params = new URLSearchParams(window.location.search);
if (_params.get('yt_connected') === '1') {
    toast('✅ YouTube connected successfully!', 'ok');
    history.replaceState({}, '', window.location.pathname);
}
if (_params.get('fb_connected') === '1') {
    toast('✅ Facebook & Instagram connected successfully!', 'ok');
    history.replaceState({}, '', window.location.pathname);
}
if (_params.get('fb_error') === 'cancelled') {
    toast('Facebook login was cancelled.', 'inf');
    history.replaceState({}, '', window.location.pathname);
}
if (_params.get('fb_error') === 'no_tokens' || _params.get('fb_error') === 'invalid_tokens') {
    toast('❌ Facebook connection failed — please try again.', 'err');
    history.replaceState({}, '', window.location.pathname);
}
loadRows('recorded');
loadStats();
</script>
</body>
</html>
