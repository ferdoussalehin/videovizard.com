<?
require_once __DIR__ . '/media_ingest.php';
if (isset($_POST['ajax_action'])) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
	
try {

    // ── queue_generate ────────────────────────────────────────────────────────
    if ($action === 'queue_generate') {
        $pid = (int)($_POST['podcast_id'] ?? 0);
        if (!$pid) { echo json_encode(['success'=>false,'message'=>'Missing podcast_id']); ob_end_flush(); exit; }
        $created = date('Y-m-d H:i:s');
        // Count pending rows BEFORE inserting (those ahead in queue)
        $res = $conn->query("SELECT COUNT(*) as cnt FROM hdb_general_que WHERE status = 0");
        $pending_before = (int)($res->fetch_assoc()['cnt'] ?? 0);
        $stmt = $conn->prepare("INSERT INTO hdb_general_que (podcast_id, que_type, created_at, status, next_action) VALUES (?, 'video', ?, 0, 'RECORD')");
        $stmt->bind_param('is', $pid, $created);
        $stmt->execute();
        $insert_id = $conn->insert_id;
        $stmt->close();
        // Total rows including current = pending_before + 1
        $total = $pending_before + 1;
        echo json_encode(['success'=>true,'que_id'=>$insert_id,'position'=>$total,'minutes'=>$total * 3]);
        ob_end_flush(); exit;
    }

    // ── upload_scene_image ────────────────────────────────────────────────
    // Saves through the shared mediaIngest() library — same pipeline as
    // vizard_scriptgen_2.php's upload_user_media/FAL handlers. Lands in
    // user_media/admin_id_{id}_company_id_{id}/podcast_images|podcast_videos,
    // gets a GPT-4o vision tag pass + embedding, and is inserted into
    // hdb_image_data so it's searchable/reusable later (not just a bare file).
    if ($action === 'upload_scene_image') {
        $response = ['success'=>false,'message'=>''];
        try {
            $scene_id    = (int)$_POST['scene_id'];
            $image_field = in_array($_POST['image_field']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                           ? $_POST['image_field'] : 'image_file';
            $media_type  = ($_POST['media_type']??'image')==='video' ? 'video' : 'image';
            if (!isset($_FILES['scene_image'])) {
                $post_max = ini_get('post_max_size');
                throw new Exception("File too large for server (post_max_size=$post_max). Increase in php.ini.");
            }
            $upload_err = $_FILES['scene_image']['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($upload_err === UPLOAD_ERR_INI_SIZE || $upload_err === UPLOAD_ERR_FORM_SIZE)
                throw new Exception('File exceeds server upload limit ('.ini_get('upload_max_filesize').'). Increase upload_max_filesize in php.ini.');
            if ($upload_err !== UPLOAD_ERR_OK)
                throw new Exception('Upload error code: '.$upload_err);
            $file    = $_FILES['scene_image'];
            $maxSize = $media_type === 'video' ? 500*1024*1024 : 50*1024*1024;
            if ($file['size'] > $maxSize) throw new Exception('Max '.($media_type==='video'?'500MB':'50MB').' allowed');

            // Map slot → its own image_folder column
            $folder_col_map = [
                'image_file'   => 'image_folder',
                'image_file_1' => 'image_folder_1',
                'image_file_2' => 'image_folder_2',
                'image_file_3' => 'image_folder_3',
                'image_file_4' => 'image_folder_4',
            ];
            $folder_col = $folder_col_map[$image_field] ?? 'image_folder';
            $db_field   = $image_field;

            if (!isset($apiKey) || empty($apiKey)) { @require_once __DIR__ . '/config.php'; }
            $mi_context = trim(($ai_group ?? '') . ' ' . ($ai_subgroup ?? '')) ?: 'Promotional video scene';

            $ingest = mediaIngest([
                'file'            => $file,
                'admin_id'        => $admin_id,
                'company_id'      => $company_id,
                'promo_group'     => $ai_group    ?? '',
                'promo_subgroup'  => $ai_subgroup ?? '',
                'image_folder'    => 'podcast_images',
                'video_folder'    => 'podcast_videos',
                'thumb_folder'    => 'podcast_thumbnails',
                'filename_prefix' => 'scene',
                'context'         => $mi_context,
                // Without these, a user-uploaded video (e.g. a raw 4K/60fps
                // download) is only keyframe-trimmed and kept at its original
                // resolution/fps — unlike pexels_download.php, which already
                // sets these. vps_ffmpeg_stitch.php re-encodes every scene to
                // 1080x1920/25fps right before concat regardless, so this
                // doesn't fix stitching by itself, but it keeps the media
                // library from storing oversized raw files and matches the
                // size/format every other ingested video gets.
                'max_video_sec'   => 10,
                'reencode'        => ($media_type === 'video'),
            ], $conn, $apiKey ?? '');

            if (empty($ingest['success'])) {
                throw new Exception($ingest['message'] ?? 'Could not save file');
            }

            // Use the real saved asset — not the thumbnail — for the scene's
            // own media slot, so the final stitched video uses full quality.
            $filename  = $ingest['filename'];
            $db_folder = $ingest['folder']; // e.g. user_media/admin_id_34_company_id_29/podcast_images

            $esc_file   = mysqli_real_escape_string($conn, $filename);
            $esc_folder = mysqli_real_escape_string($conn, $db_folder);
            mysqli_query($conn, "UPDATE hdb_podcast_stories SET `$db_field`='$esc_file', `$folder_col`='$esc_folder' WHERE id=$scene_id");

            $log_msg = date('Y-m-d H:i:s')." | upload_scene_image | admin_id=$admin_id company_id=$company_id | slot=$image_field folder_col=$folder_col | folder=$db_folder | file=$filename | tagged=".(!empty($ingest['tagged'])?'1':'0')."\n";
            file_put_contents(__DIR__.'/a_errors.log', $log_msg, FILE_APPEND);

            $response = [
                'success'      => true,
                'filename'     => $filename,
                'media_type'   => $media_type,
                'image_folder' => $db_folder,
                'folder_col'   => $folder_col,
                'thumbnail'    => $ingest['thumbnail'] ?? '',
                'tagged'       => !empty($ingest['tagged']),
            ];
        } catch (Exception $e) { $response['message']=$e->getMessage(); }
        echo json_encode($response); exit;
    }

    // ── assign_image ──────────────────────────────────────────────────────
    if ($action === 'assign_image') {
        $scene_id    = (int)$_POST['scene_id'];
        $filename    = mysqli_real_escape_string($conn,$_POST['filename']??'');
        $image_field = in_array($_POST['image_field']??'', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                       ? $_POST['image_field'] : 'image_file';
        $picked_type = $_POST['media_type'] ?? 'image';
        $is_vid      = ($picked_type === 'video' || preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $filename));
        // folder_override is always sent from JS with the correct folder:
        //   'podcast_images'                                        — standard image from library
        //   'podcast_videos'                                        — standard video from library
        //   'user_media/admin_id_X_company_id_Y/podcast_images'      — personal image (User Images tab)
        //   'user_media/admin_id_X_company_id_Y/podcast_videos'      — personal video (User Videos tab)
        // If not sent, fall back to type-based default.
        $folder_raw  = trim($_POST['folder_override'] ?? '');
        if ($folder_raw !== '') {
            $folder_for_db = preg_replace('/[^a-zA-Z0-9_\/]/', '', $folder_raw);
        } else {
            $folder_for_db = $is_vid ? 'podcast_videos' : 'podcast_images';
        }
        // Map slot → its own image_folder column
        $folder_col_map = [
            'image_file'   => 'image_folder',
            'image_file_1' => 'image_folder_1',
            'image_file_2' => 'image_folder_2',
            'image_file_3' => 'image_folder_3',
            'image_file_4' => 'image_folder_4',
        ];
        $folder_col = $folder_col_map[$image_field] ?? 'image_folder';
        $esc_folder = mysqli_real_escape_string($conn, $folder_for_db);
        $ok = $scene_id && $filename && mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET `$image_field`='$filename', `$folder_col`='$esc_folder' WHERE id=$scene_id");
        echo json_encode(['success'=>(bool)$ok,'filename'=>$filename,'image_folder'=>$folder_for_db,'folder_col'=>$folder_col]); exit;
    }
	if ($action === 'save_published_video') {
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);

		if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
			echo json_encode(['success'=>false,'message'=>'No file received']);
			exit;
		}

		$dir = __DIR__ . '/published_videos/';
		if (!is_dir($dir)) mkdir($dir, 0755, true);

		// Delete any existing video for this podcast (no duplicates)
		foreach (glob($dir . 'podcast_' . $podcast_id . '.*') as $old) {
			@unlink($old);
		}

		$ext      = strtolower(pathinfo($_FILES['video']['name'], PATHINFO_EXTENSION));
		$ext      = in_array($ext, ['mp4','webm']) ? $ext : 'webm';
		$filename = 'podcast_' . $podcast_id . '.' . $ext;
		$dest     = $dir . $filename;

		if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
			echo json_encode(['success'=>false,'message'=>'Failed to save file']);
			exit;
		}

		// Update hdb_podcasts with published video path and mark as RECORDED
		$esc = mysqli_real_escape_string($conn, $filename);
		mysqli_query($conn,
			"UPDATE hdb_podcasts
			 SET video_filename='$esc', published_video='$esc', video_status='RECORDED', updated_at=NOW()
			 WHERE id=$podcast_id AND admin_id={$_SESSION['admin_id']}");

		echo json_encode(['success'=>true, 'filename'=>$filename]);
		exit;
	}

    // ── save_scene_clip — save one per-scene WebM clip ───────────────────────
    if ($action === 'save_scene_clip') {
        $scene_index = (int)($_POST['scene_index'] ?? -1);
        if ($scene_index < 0) {
            echo json_encode(['success'=>false,'message'=>'Missing scene_index']); exit;
        }
        if (empty($_FILES['video']['tmp_name']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            $err = $_FILES['video']['error'] ?? 'no file';
            echo json_encode(['success'=>false,'message'=>'No video received (err='.$err.')']); exit;
        }
        $dir = __DIR__ . '/published_videos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'podcast_' . $podcast_id . '_scene_' . $scene_index . '.webm';
        $dest     = $dir . $filename;
        if (!move_uploaded_file($_FILES['video']['tmp_name'], $dest)) {
            echo json_encode(['success'=>false,'message'=>'Failed to save to '.$dest]); exit;
        }
        $size_mb = round(filesize($dest) / 1024 / 1024, 2);
        file_put_contents(__DIR__.'/a_errors.log',
            date('[Y-m-d H:i:s] ')."save_scene_clip: saved $filename ({$size_mb}MB)\n", FILE_APPEND);
        echo json_encode(['success'=>true,'filename'=>$filename,'scene_index'=>$scene_index,'size_mb'=>$size_mb]);
        exit;
    }

    // ── stitch_scenes — tell VPS to download all clips and concat to MP4 ────
    if ($action === 'stitch_scenes') {
        $scene_count = (int)($_POST['scene_count'] ?? 0);
        $warmup_ms   = (int)($_POST['warmup_ms']   ?? 500);
        $log_file    = __DIR__ . '/a_errors.log';
        $log = function($msg) use ($log_file) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ').$msg."\n", FILE_APPEND);
        };

        if ($scene_count <= 0) {
            echo json_encode(['success'=>false,'message'=>'scene_count must be > 0']); exit;
        }

        // Verify all clips exist locally
        $dir = __DIR__ . '/published_videos/';
        $missing = [];
        $clip_urls = [];
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base_url  = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/published_videos/';

        for ($i = 0; $i < $scene_count; $i++) {
            $fname = 'podcast_' . $podcast_id . '_scene_' . $i . '.webm';
            $fpath = $dir . $fname;
            if (!file_exists($fpath) || filesize($fpath) < 100) {
                $missing[] = $i;
            } else {
                $clip_urls[] = $base_url . $fname;
            }
        }

        if (!empty($missing)) {
            $log("stitch_scenes: missing clips for scenes: ".implode(',',$missing));
            echo json_encode(['success'=>false,'message'=>'Missing clips for scenes: '.implode(', ',$missing)]);
            exit;
        }

        // Send clip URLs to VPS — VPS downloads each, trims warmup_ms, stitches
        $log("stitch_scenes: sending ".count($clip_urls)." clip URLs to VPS");
        $log("stitch_scenes clip_urls: ".json_encode($clip_urls));
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $VPS_URL,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS     => [
                'secret'         => $SECRET_KEY,
                'podcast_id'     => $podcast_id,
                'scene_count'    => $scene_count,
                'base_url'       => $protocol . '://' . $_SERVER['HTTP_HOST'] . '/',
                'clip_dir'       => 'published_videos/',
                'output_dir'     => 'published_videos/',
                'clip_urls'      => json_encode($clip_urls),
                // Speed hints for FFmpeg on VPS:
                // - vp8 webm input → h264 mp4 output (fast decode + encode)
                // - preset=fast cuts encode time ~60% vs default 'medium'
                // - crf=28 is visually fine for social (was default ~23 = slower/larger)
                // - scale=720:1280 matches what we record (no upscale waste)
                'ffmpeg_preset'  => 'fast',
                'ffmpeg_crf'     => '28',
                'output_width'   => '720',
                'output_height'  => '1280',
            ]
        ]);
        $response  = curl_exec($curl);
        $curl_err  = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $log("stitch_scenes VPS response (HTTP $http_code): $response");

        if ($curl_err) {
            echo json_encode(['success'=>false,'message'=>'VPS connection error: '.$curl_err]); exit;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['success'])) {
            $msg = $data['message'] ?? ('VPS error: '.substr($response,0,200));
            echo json_encode(['success'=>false,'message'=>$msg]); exit;
        }

        echo json_encode(['success'=>true,'job_id'=>$data['job_id']??null]);
        exit;
    }

    // ── start_mp4_convert — tell VPS to pull WebM from GoDaddy and convert ─────
    // ── start_mp4_convert — tell VPS to pull WebM from GoDaddy and convert ─────
	if ($action === 'start_mp4_convert') {
		header('Content-Type: application/json');
		
		$log_file = __DIR__ . '/a_errors.log';
		$podcast_id = (int)($_POST['podcast_id'] ?? 0);
		$webm_path = __DIR__ . '/published_videos/podcast_' . $podcast_id . '.webm';
		
		// Public URL of WebM
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$webm_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/published_videos/podcast_' . $podcast_id . '.webm';
		
		$log = function($msg) use ($log_file) {
			file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
		};
		
		$log("start_mp4_convert called — podcast_id=$podcast_id");
		$log("WebM path: $webm_path");
		$log("WebM exists: " . (file_exists($webm_path) ? 'YES (' . filesize($webm_path) . ' bytes)' : 'NO'));
		$log("WebM public URL: $webm_url");
		
		if (!file_exists($webm_path)) {
			$log("ERROR: WebM not found");
			echo json_encode(['success' => false, 'message' => 'WebM not found']);
			exit;
		}
		
		// Send URL to VPS
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $VPS_URL,
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_POSTFIELDS => [
				'secret'     => $SECRET_KEY,  // BUG 2 FIX: was 'secret_key', must match stitch_scenes + poll
				'action'     => 'convert',
				'podcast_id' => $podcast_id,
				'webm_url'   => $webm_url
			]
		]);
		
		$log("Sending URL to VPS: $webm_url");
		$response = curl_exec($curl);
		$curl_err = curl_error($curl);
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		
		$log("HTTP code: $http_code");
		$log("cURL error: " . ($curl_err ?: 'none'));
		$log("VPS response: $response");
		
		if ($curl_err) {
			$log("FAILED: " . $curl_err);
			echo json_encode(['success' => false, 'message' => 'cURL error: ' . $curl_err]);
			exit;
		}
		
		$data = json_decode($response, true);
		$log("Job started: " . json_encode($data));
		
		// If VPS fails, create a direct download link for WebM
		if (!$data || !$data['success']) {
			$log("VPS conversion failed or not available - offering WebM download");
			echo json_encode([
				'success' => false, 
				'message' => 'VPS conversion unavailable',
				'webm_url' => $webm_url,
				'fallback' => true
			]);
			exit;
		}
		
		echo json_encode($data);
		exit;
	}

    // ── poll_mp4_convert — check job status and download MP4 when ready ───────
    if ($action === 'poll_mp4_convert') {
        header('Content-Type: application/json');

        $log_file = __DIR__ . '/a_errors.log';
        $log = function($msg) use ($log_file) {
            file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
        };

        // Download a URL to disk via curl with a hard timeout.
        // Replaces @file_get_contents() which had NO timeout and hung PHP for
        // up to 60s per call — stacking concurrent requests until GoDaddy
        // killed them all and the browser saw nothing for minutes.
        $curlDownload = function($url, $dest, $timeout = 120) use ($log) {
            $fp = fopen($dest, 'wb');
            if (!$fp) { $log("curlDownload: cannot open $dest"); return false; }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FAILONERROR    => true,
            ]);
            $ok  = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (!$ok || $err) { $log("curlDownload failed: $err url=$url"); @unlink($dest); return false; }
            return true;
        };

        $job_id       = $_POST['job_id'] ?? '';
        $podcast_id   = (int)($_POST['podcast_id'] ?? 0);
        $sync_mode    = (int)($_POST['sync_mode'] ?? 0);
        $mp4_path     = __DIR__ . '/published_videos/podcast_' . $podcast_id . '.mp4';
        $webm_path    = __DIR__ . '/published_videos/podcast_' . $podcast_id . '.webm';
        $mp4_filename = 'podcast_' . $podcast_id . '.mp4';

        // Shortcut: if MP4 already on disk (previous download succeeded but
        // response was lost), return done immediately without hitting VPS.
        if (file_exists($mp4_path) && filesize($mp4_path) > 10000) {
            $log("poll_mp4_convert: MP4 already on disk — returning done (podcast_id=$podcast_id)");
            echo json_encode(['status'=>'done','success'=>true,'filename'=>$mp4_filename,
                'mp4_size_mb'=>round(filesize($mp4_path)/1024/1024,2)]);
            exit;
        }

        // Sync mode: VPS converted synchronously, just download the MP4
        if ($sync_mode) {
            $mp4_url = $_POST['mp4_url'] ?? '';
            $log("poll_mp4_convert SYNC — podcast_id=$podcast_id url=$mp4_url");
            $ok = $curlDownload($mp4_url, $mp4_path, 180);
            if ($ok && filesize($mp4_path) > 1000) {
                @unlink($webm_path);
                $ch = curl_init($VPS_URL.'?action=cleanup&secret='.urlencode($SECRET_KEY).'&job_id='.urlencode($job_id).'&podcast_id='.$podcast_id);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]); @curl_exec($ch); curl_close($ch);
                $esc = mysqli_real_escape_string($conn, $mp4_filename);
                mysqli_query($conn,"UPDATE hdb_podcasts SET video_filename='$esc',published_video='$esc',video_status='PUBLISHED',updated_at=NOW() WHERE id=$podcast_id");
                $log("Sync done — MP4 saved");
                echo json_encode(['success'=>true,'status'=>'done','filename'=>$mp4_filename,'mp4_size_mb'=>round(filesize($mp4_path)/1024/1024,2)]);
            } else {
                $log("Sync FAILED — download failed from $mp4_url");
                echo json_encode(['success'=>false,'status'=>'error','message'=>'Failed to download MP4']);
            }
            exit;
        }

        // Async mode: poll VPS with curl + hard 8s timeout so PHP never hangs the browser
        $log("poll_mp4_convert ASYNC — job_id=$job_id podcast_id=$podcast_id");
        $status_url = $VPS_URL.'?action=status&secret='.urlencode($SECRET_KEY).'&job_id='.urlencode($job_id);
        $ch = curl_init($status_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err || !$response) {
            $log("poll VPS unreachable: $curl_err");
            echo json_encode(['status'=>'processing','message'=>'VPS not yet responding — retrying…']);
            exit;
        }

        $data = json_decode($response, true);
        $log("VPS status response: $response");

        if (!$data) {
            echo json_encode(['status'=>'processing','message'=>'Waiting for VPS…']);
            exit;
        }

        if (isset($data['status']) && $data['status'] === 'done') {
            $vps_base = 'http://187.124.249.46/videovizard.com/';
            $mp4_url  = $vps_base . ltrim($data['mp4_url'] ?? '', '/');
            $log("VPS done — downloading MP4 from $mp4_url");

            $ok = $curlDownload($mp4_url, $mp4_path, 180);
            if ($ok && filesize($mp4_path) > 1000) {
                @unlink($webm_path);
                // Cleanup VPS (fire-and-forget, 5s max)
                $ch = curl_init($VPS_URL.'?action=cleanup&secret='.urlencode($SECRET_KEY).'&job_id='.urlencode($job_id).'&podcast_id='.$podcast_id);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]); @curl_exec($ch); curl_close($ch);
                // Clean per-scene clips
                for ($ci = 0; $ci < 200; $ci++) {
                    $cp = __DIR__.'/published_videos/podcast_'.$podcast_id.'_scene_'.$ci.'.webm';
                    if (!file_exists($cp)) break;
                    @unlink($cp);
                }
                $esc_mp4 = mysqli_real_escape_string($conn, $mp4_filename);
                mysqli_query($conn,"UPDATE hdb_podcasts SET video_filename='$esc_mp4',published_video='$esc_mp4',video_status='PUBLISHED',updated_at=NOW() WHERE id=$podcast_id");
                $log("Async done — MP4 saved ($mp4_filename)");
                echo json_encode(['status'=>'done','success'=>true,'filename'=>$mp4_filename,
                    'mp4_size_mb'=>round(filesize($mp4_path)/1024/1024,2),'message'=>'MP4 ready!']);
            } else {
                $log("Async FAILED — could not download MP4 from $mp4_url");
                echo json_encode(['status'=>'error','message'=>'Failed to download MP4 from VPS']);
            }
            exit;
        }

        // Still processing — pass VPS response through so JS keeps polling
        echo json_encode($data);
        exit;
    }

    // ── save_enabled_slots — saves per scene as individual slot columns ─────
		
	// ── update_video_status ───────────────────────────────────────────
// ── update_video_status ───────────────────────────────────────────
if ($action === 'update_video_status') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    $status     = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    // BUG 4 FIX: removed company_id guard — it was silently blocking status updates
    // when company_id was 0 or not sent, leaving video stuck in wrong state
    if ($podcast_id && $status) {
        $result = mysqli_query($conn,
            "UPDATE hdb_podcasts SET video_status='$status', updated_at=NOW() WHERE id=$podcast_id");
        echo json_encode(['success' => (bool)$result]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing podcast_id or status']);
    }
    exit;
}

// ── update_podcast_thumbnail ─────────────────────────────────────────────
// Called when play/record starts OR when user selects a slot in scene 1.
// Always saves an image filename — if the file is a video, resolves its thumbnail.
if ($action === 'update_podcast_thumbnail') {
    $podcast_id = (int)($_POST['podcast_id'] ?? 0);
    if (!$podcast_id) { echo json_encode(['success'=>false,'error'=>'Missing podcast_id']); exit; }

    // If JS sent a specific filename (from slot selection), use it; otherwise read from DB
    $forced_img = trim($_POST['filename'] ?? '');

    if ($forced_img) {
        $scene1_img = $forced_img;
    } else {
        $sql_scene = "SELECT image_file, image_folder FROM hdb_podcast_stories
                      WHERE podcast_id=$podcast_id
                      ORDER BY seq_no ASC, id ASC LIMIT 1";
        $scene_row  = mysqli_fetch_assoc(mysqli_query($conn, $sql_scene));
        $scene1_img = trim($scene_row['image_file'] ?? '');
    }

    if (!$scene1_img) {
        echo json_encode(['success'=>true,'updated'=>false,'reason'=>'scene1 has no image_file']);
        exit;
    }

    // If the file is a video, resolve its thumbnail image instead
    if (preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $scene1_img)) {
        $esc_vid = mysqli_real_escape_string($conn, $scene1_img);
        $thumb_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT thumbnail FROM hdb_image_data WHERE image_name='$esc_vid' AND thumbnail != '' LIMIT 1"));
        if ($thumb_row && !empty($thumb_row['thumbnail'])) {
            $scene1_img = trim($thumb_row['thumbnail']);
        } else {
            // Derive: replace extension with _thumb.jpg
            $scene1_img = preg_replace('/\.[^.]+$/', '_thumb.jpg', $scene1_img);
        }
    }

    // Get current podcast thumbnail
    $pod_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT thumbnail FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
    $current_thumb = trim($pod_row['thumbnail'] ?? '');

    // Skip if already in sync (only when not forced by JS)
    if (!$forced_img && basename($scene1_img) === basename($current_thumb)) {
        echo json_encode(['success'=>true,'updated'=>false,'thumbnail'=>$current_thumb,'reason'=>'already in sync']);
        exit;
    }

    $esc_img = mysqli_real_escape_string($conn, $scene1_img);
    $ok = mysqli_query($conn, "UPDATE hdb_podcasts SET thumbnail='$esc_img', updated_at=NOW() WHERE id=$podcast_id");
    echo json_encode(['success'=>(bool)$ok,'updated'=>(bool)$ok,'thumbnail'=>$scene1_img,'was'=>$current_thumb]);
    exit;
}

// ── search_media_nl ───────────────────────────────────────────────────
if ($action === 'search_media_nl') {
   // error_log('[videomaker] ========== search_media_nl START ==========');
    
    $query = trim($_POST['query'] ?? '');
    $media_type_filter = trim($_POST['media_type_filter'] ?? '');
    $tab_type = trim($_POST['tab_type'] ?? 'all');
    
 //   error_log("[videomaker] media_type_filter: $media_type_filter");
 //   error_log("[videomaker] tab_type: $tab_type");
    
    if (empty($query)) {
        error_log("[videomaker] Empty query, returning empty");
        echo json_encode([]);
        exit;
    }
    
    // Load config to get apiKey - MUST be before image_search_functions
    require_once __DIR__ . '/config.php';
    
    // Make sure apiKey is available globally
    global $apiKey;
    
    // Debug: Check if apiKey is set
    if (!isset($apiKey) || empty($apiKey)) {
        error_log('[videomaker] ERROR: apiKey is not set in config.php');
        echo json_encode(['error' => 'API key not configured']);
        exit;
    }
  //  error_log('[videomaker] apiKey is set (length: ' . strlen($apiKey) . ')');
    
    // Check if image_search_functions.php exists
    $funcFile = __DIR__ . '/image_search_functions.php';
    if (!file_exists($funcFile)) {
        error_log("[videomaker] ERROR: image_search_functions.php not found at $funcFile");
        echo json_encode(['error' => 'Search functions not found']);
        exit;
    }
    
    require_once $funcFile;
 //   error_log('[videomaker] image_search_functions.php loaded');
    
    // Check if searchAssets function exists
    if (!function_exists('searchAssets')) {
        error_log('[videomaker] ERROR: searchAssets function does not exist');
        echo json_encode(['error' => 'searchAssets function not found']);
        exit;
    }
    
    // Determine include_mine based on tab_type
    $include_mine = ($tab_type === 'mine');
    
    // Map media_type_filter
    $media_type = '';
    if ($media_type_filter === 'image') {
        $media_type = 'image';
    } elseif ($media_type_filter === 'video') {
        $media_type = 'video';
    }
    
    error_log("[videomaker] calling searchAssets with: query=$query, media_type=$media_type, include_mine=$include_mine");
    
    // Use searchAssets function - pass apiKey as parameter
    $results = searchAssets($conn, $query, $apiKey, 0, $media_type, $include_mine, $admin_id, 30, 0.25);
    
 //   error_log("[videomaker] searchAssets returned " . count($results) . " results");
    
    // Format for frontend
    $formatted = [];
    foreach ($results as $r) {
        $formatted[] = [
            'id'            => $r['id'],
            'filename'      => $r['filename'],
            'type'          => $r['media_type'] ?? 'image',
            'nl_tags'       => $r['nl_tags'] ?? '',
            'score'         => $r['score'],
            'score_pct'     => $r['score_pct'] ?? round($r['score'] * 100, 1),
            'thumbnail'     => $r['thumbnail'] ?? '',
            'matched_terms' => $r['matched_terms'] ?? []
        ];
    }
    
    error_log('[videomaker] ========== search_media_nl END ==========');
    echo json_encode($formatted);
    exit;
}

    // ── get_library_files ─────────────────────────────────────────────────
    if ($action === 'get_library_files') {
		$files = [];
		$q = mysqli_query($conn,
			"SELECT image_name AS filename, image_hashtags AS tags, media_type, natural_language_tags, thumbnail
			   FROM hdb_image_data
			   WHERE (admin_id = 0 OR admin_id IS NULL)
			   ORDER BY id DESC LIMIT 300");
		if ($q) while ($r = mysqli_fetch_assoc($q)) $files[] = $r;
		if (!$files) {
			$dir = __DIR__.'/'.$img_folder.'/';
			if (is_dir($dir)) foreach (scandir($dir) as $f) {
				if ($f==='.'||$f==='..') continue;
				if (preg_match('/\.(jpg|jpeg|png|gif|webp|mp4|webm|mov)$/i',$f))
					$files[] = ['filename'=>$f,'tags'=>'','media_type'=>preg_match('/\.(mp4|webm|mov)$/i',$f)?'video':'image','natural_language_tags'=>'','thumbnail'=>''];
			}
		}
		echo json_encode(['success'=>true,'files'=>$files]); exit;
	}

    // ── get_my_uploads ────────────────────────────────────────────────────
    if ($action === 'get_my_uploads') {
        $files = [];
        $q = mysqli_query($conn,
            "SELECT image_name AS filename, image_hashtags AS tags, media_type, natural_language_tags, thumbnail
               FROM hdb_image_data
               WHERE admin_id = $admin_id
               ORDER BY id DESC LIMIT 300");
        if ($q) while ($r = mysqli_fetch_assoc($q)) $files[] = $r;
        echo json_encode(['success'=>true,'files'=>$files]); exit;
    }

    // ── get_user_media ────────────────────────────────────────────────────
    // Lists this admin+company's personal media, split by type, sourced
    // from hdb_image_data (populated by mediaIngest()) rather than a raw
    // disk scan — so each item carries its real thumbnail + NL tags.
    // Folder layout mirrors mediaIngest(): user_media/admin_id_{id}_company_id_{id}/podcast_images|podcast_videos
    if ($action === 'get_user_media') {
        $media_type = ($_POST['media_type'] ?? 'image') === 'video' ? 'video' : 'image';
        $subfolder  = $media_type === 'video' ? 'podcast_videos' : 'podcast_images';

        if ($admin_id > 0 && $company_id > 0) {
            $base_folder = 'user_media/admin_id_' . $admin_id . '_company_id_' . $company_id;
        } elseif ($admin_id > 0) {
            $base_folder = 'user_media/admin_id_' . $admin_id;
        } else {
            $base_folder = '';
        }
        $db_folder = $base_folder !== '' ? ($base_folder . '/' . $subfolder) : '';

        $files = [];
        if ($db_folder !== '') {
            $fo_e = mysqli_real_escape_string($conn, $db_folder);
            $mt_e = mysqli_real_escape_string($conn, $media_type);
            $res = mysqli_query($conn,
                "SELECT image_name, thumbnail, natural_language_tags
                 FROM hdb_image_data
                 WHERE admin_id=$admin_id AND company_id=$company_id
                   AND media_type='$mt_e' AND image_folder='$fo_e'
                   AND status='active'
                 ORDER BY id DESC LIMIT 300");
            if ($res) {
                while ($r = mysqli_fetch_assoc($res)) {
                    $files[] = [
                        'filename'   => $r['image_name'],
                        'media_type' => $media_type,
                        'thumbnail'  => $r['thumbnail'] ?? '',
                        'nl_tags'    => $r['natural_language_tags'] ?? '',
                    ];
                }
            }
        }
        $dir = $db_folder !== '' ? (__DIR__ . '/' . $db_folder) : '';
        echo json_encode(['success'=>true, 'files'=>$files, 'folder'=>$db_folder, 'has_folder'=>($dir !== '' && is_dir($dir))]); exit;
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
		$folder = __DIR__.'/'.$img_folder;
		ob_start();
		$result = generateAndSaveImage($enhanced_prompt,$base,'1024x1536',$folder,$apiKey);
		ob_end_clean();
		if (!$result['success']) { echo json_encode(['success'=>false,'message'=>$result['message']]); exit; }
		$name = $base.'.png';
		$esc  = mysqli_real_escape_string($conn,$name);
		$eh   = mysqli_real_escape_string($conn,$hashtags);
		$ep   = mysqli_real_escape_string($conn,$enhanced_prompt);
		
		// UPDATE THE IMAGE SLOT AND ITS CORRESPONDING FOLDER COLUMN
		$folder_col_map = [
			'image_file'   => 'image_folder',
			'image_file_1' => 'image_folder_1',
			'image_file_2' => 'image_folder_2',
			'image_file_3' => 'image_folder_3',
			'image_file_4' => 'image_folder_4',
		];
		$folder_col = $folder_col_map[$image_field] ?? 'image_folder';
		$folder_val = mysqli_real_escape_string($conn, $img_folder); // 'podcast_images'
		
		mysqli_query($conn,
			"UPDATE hdb_podcast_stories 
			 SET `$image_field`='$esc', `$folder_col`='$folder_val' 
			 WHERE id=$scene_id"
		);
		
		mysqli_query($conn,
			"INSERT INTO hdb_image_data (image_name,image_hashtags,image_prompt,created_at,media_type,admin_id,skip_embedding) 
			 VALUES ('$esc','$eh','$ep',NOW(),'image',$admin_id,1)"
		);
		echo json_encode(['success'=>true,'image_name'=>$name]); exit;
	}

    // ── generate_video ────────────────────────────────────────────────────
    if ($action === 'generate_video') {
        $scene_id        = (int)($_POST['scene_id']   ?? 0);
        $podcast_id_vg   = (int)($_POST['podcast_id'] ?? $podcast_id ?? 0);
        $image_field     = in_array($_POST['image_field'] ?? '', ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'])
                           ? $_POST['image_field'] : 'image_file';
        $enhanced_prompt = trim($_POST['enhanced_prompt'] ?? '');

        if (!$scene_id)        { while(ob_get_level())ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Missing scene_id']); exit; }
        if (!$enhanced_prompt) { while(ob_get_level())ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Empty prompt']);     exit; }

        // Resolve team_lead_id for this admin
        $tl_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT role, team_lead_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
        $tl_id  = (!empty($tl_row) && trim((string)$tl_row['role']) === 'Team Member' && (int)$tl_row['team_lead_id'] > 0)
                  ? (int)$tl_row['team_lead_id'] : $admin_id;

        // Auto-create video_gen_flag column if missing
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcast_stories LIKE 'video_gen_flag'");
        if ($chk && mysqli_num_rows($chk) === 0)
            mysqli_query($conn, "ALTER TABLE hdb_podcast_stories ADD COLUMN video_gen_flag TINYINT(1) NOT NULL DEFAULT 0");

        // Fetch story row
        $s_row      = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT `$image_field` AS img, image_folder FROM hdb_podcast_stories WHERE id=$scene_id LIMIT 1"));
        $img_file   = mysqli_real_escape_string($conn, $s_row['img']           ?? '');
        $img_folder = mysqli_real_escape_string($conn, $s_row['image_folder']  ?? 'podcast_images');
        // Read caption text from hdb_captions (main caption), not text_contents
        $cap_row    = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT text_content FROM hdb_captions
              WHERE podcast_id=$podcast_id_vg AND story_id=$scene_id
              ORDER BY FIELD(caption_name,'main') DESC, id ASC LIMIT 1"));
        $script_txt = mysqli_real_escape_string($conn, $cap_row['text_content'] ?? '');
        $ep         = mysqli_real_escape_string($conn, $enhanced_prompt);

        // Count pending jobs for queue estimate (including this new job)
        $q_res    = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM hdb_video_gen WHERE status = 'pending'");
        $pending  = (int)(mysqli_fetch_assoc($q_res)['cnt'] ?? 0);
        $position = $pending + 1;  // +1 for this new job
        $minutes  = $position * 5; // 5 minutes per job

        // Insert
        $ins_ok = mysqli_query($conn,
            "INSERT INTO hdb_video_gen
             (podcast_id, story_id, admin_id, team_lead_id,
              image_file, image_folder, image_prompt, script_text,
              video_type, status, phase, created_at)
             VALUES
             ($podcast_id_vg, $scene_id, $admin_id, $tl_id,
              '$img_file', '$img_folder', '$ep', '$script_txt',
              'text2video', 'pending', 'sadtalker', NOW())"
        );

        while(ob_get_level()) ob_end_clean();
        if (!$ins_ok) {
            echo json_encode(['success'=>false,'message'=>'Failed to queue video: '.mysqli_error($conn)]);
            exit;
        }

        $video_gen_id = mysqli_insert_id($conn);
        mysqli_query($conn, "UPDATE hdb_podcast_stories SET video_gen_flag = 1 WHERE id = $scene_id");

        echo json_encode([
            'success'      => true,
            'video_gen_id' => $video_gen_id,
            'position'     => $position,
            'minutes'      => $minutes,
            'message'      => "Your video will be ready in approximately {$minutes} minutes (position #{$position} in queue).",
        ]);
        exit;
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
	if ($action === 'get_podcast_caption_data') {
		$pid = (int)($_POST['podcast_id'] ?? 0);
		$row = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT caption_text, keywords, hashtags, title, niche
			 FROM hdb_podcasts
			 WHERE id=$pid AND admin_id={$_SESSION['admin_id']} LIMIT 1"));
		if (!$row) { echo json_encode(['success'=>false]); exit; }

		$caption_text = trim($row['caption_text'] ?? '');
		$keywords     = trim($row['keywords']     ?? '');
		$hashtags     = trim($row['hashtags']     ?? '');

		// If caption_text empty, build from first 3 scenes' captions in hdb_captions
		if (empty($caption_text)) {
			$scenes_q = mysqli_query($conn,
				"SELECT hc.text_content
				   FROM hdb_captions hc
				   JOIN hdb_podcast_stories hps ON hps.id = hc.story_id
				  WHERE hc.podcast_id=$pid
				    AND (hc.caption_name='main' OR hc.id IN (
				            SELECT MIN(id) FROM hdb_captions
				             WHERE podcast_id=$pid GROUP BY story_id
				        ))
				  ORDER BY hps.seq_no ASC LIMIT 3");
			$lines = [];
			while ($sr = mysqli_fetch_assoc($scenes_q)) {
				$t = trim(preg_replace('/<break[^>]*>/i', '', $sr['text_content'] ?? ''));
				if ($t) $lines[] = $t;
			}
			if (!empty($lines)) {
				$caption_text = implode(' ', $lines);
				if (!empty($row['title'])) $caption_text = $row['title'] . "\n\n" . $caption_text;
			}
		}

		// If keywords empty, use niche
		if (empty($keywords) && !empty($row['niche'])) {
			$keywords = $row['niche'];
		}

		// If hashtags empty, pull from first scene with hashtags
		if (empty($hashtags)) {
			$ht_row = mysqli_fetch_assoc(mysqli_query($conn,
				"SELECT hashtags FROM hdb_podcast_stories
				 WHERE podcast_id=$pid AND hashtags != '' ORDER BY seq_no ASC LIMIT 1"));
			if ($ht_row) $hashtags = $ht_row['hashtags'];
		}

		// Save back to hdb_podcasts for next time
		if ($caption_text || $keywords || $hashtags) {
			$ec = mysqli_real_escape_string($conn, $caption_text);
			$ek = mysqli_real_escape_string($conn, $keywords);
			$eh = mysqli_real_escape_string($conn, $hashtags);
			mysqli_query($conn,
				"UPDATE hdb_podcasts SET caption_text='$ec', keywords='$ek', hashtags='$eh'
				 WHERE id=$pid AND admin_id={$_SESSION['admin_id']}");
		}

		echo json_encode([
			'success'      => true,
			'caption_text' => $caption_text,
			'keywords'     => $keywords,
			'hashtags'     => $hashtags,
		]);
		exit;
	}

    // ── save_scene_text ───────────────────────────────────────────────────
    // -- save_scene_text: OBSOLETE -- captions are now exclusively in hdb_captions
    if ($action === 'save_scene_text') {
        echo json_encode(['success'=>false,'message'=>'Obsolete: use save_caption_text']); exit;
    }






	// ── save_caption_text ─────────────────────────────────────────────
	if ($action === 'save_caption_text') {
		$cap_id = (int)$_POST['caption_id'];
		$text   = mysqli_real_escape_string($conn, $_POST['text_content'] ?? '');
		$ok = mysqli_query($conn,
			"UPDATE hdb_captions SET text_content='$text' WHERE id=$cap_id AND podcast_id=$podcast_id");
		echo json_encode(['success'=>(bool)$ok]); exit;
	}

    // ── get_voices_by_language ─────────────────────────────────────────────
    if ($action === 'get_voices_by_language') {
        $lc = preg_replace('/[^a-z\-]/', '', strtolower($_POST['lang_code'] ?? 'en'));
        $voices = [];
        $tc = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_voices'");
        if (mysqli_num_rows($tc) > 0) {
            // Check if voice_description column exists
            $has_desc = false;
            $cols = mysqli_query($conn, "SHOW COLUMNS FROM hdb_voices LIKE 'voice_description'");
            if ($cols && mysqli_num_rows($cols) > 0) $has_desc = true;
            $desc_col = $has_desc ? ', voice_description' : ", '' AS voice_description";
            $q = mysqli_query($conn,
                "SELECT voice_key, voice_name, gender, sample_voice, voice_source, lang_code, voice_text $desc_col
                 FROM hdb_voices
                 WHERE lang_code='$lc'
                   AND (LOWER(voice_source) = 'openai' OR voice_source IS NULL OR voice_source = '')
                 ORDER BY gender ASC, voice_name ASC");
            if ($q) while ($r = mysqli_fetch_assoc($q)) $voices[] = $r;
        }
        echo json_encode(['success'=>true,'voices'=>$voices]); exit;
    }

    // ── get_music_library ─────────────────────────────────────────────────
    if ($action === 'get_music_library') {
        $dir = __DIR__.'/podcast_music/';
        $files = [];
        if (is_dir($dir)) foreach (scandir($dir) as $f) {
            if ($f==='.'||$f==='..') continue;
            if (!preg_match('/\.(mp3|wav|ogg|m4a)$/i',$f)) continue;
            $files[] = ['filename'=>$f,'size'=>(int)@filesize($dir.$f)];
        }
        usort($files, fn($a,$b)=>strcmp($a['filename'],$b['filename']));
        echo json_encode(['success'=>true,'files'=>$files]); exit;
    }


    // ── generate_scene_audio ──────────────────────────────────────────────

    // ── generate_scene_audio ──────────────────────────────────────────────
    if ($action === 'generate_scene_audio') {
        $scene_id  = (int)$_POST['scene_id'];
        $text      = trim($_POST['text'] ?? '');
        $voice_id  = trim($_POST['voice_id'] ?? '');
        $lc        = preg_replace('/[^a-z\-]/', '', strtolower($_POST['lang_code'] ?? $lang_code));
        $rate      = floatval($_POST['rate'] ?? 1.0);
        if (!$scene_id || !$text || !$voice_id) {
            ob_clean();
            echo json_encode(['success'=>false,'message'=>'Missing scene_id, text, or voice_id']); exit;
        }
        $audio_dir = __DIR__.'/podcast_audios/';
        if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);
        $filename  = 'voice_'.$podcast_id.'_'.$scene_id.'_'.$lc.'.mp3';
        $filepath  = $audio_dir.$filename;
        if (file_exists($filepath)) @unlink($filepath);

        // Route to correct TTS engine based on voice_id prefix
        if (strpos($voice_id, 'openai:') === 0) {
            // OpenAI TTS
            $voice_name = substr($voice_id, 7); // strip 'openai:'
            $openai_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
            if (!$openai_key && file_exists(__DIR__.'/config.php')) {
                require_once __DIR__.'/config.php';
                $openai_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
            }
            $use_speed  = ($rate != 1.0 && $rate != '1.0');
            $model      = $use_speed ? 'tts-1' : 'gpt-4o-mini-tts';
            $payload    = json_encode(['model'=>$model,'input'=>$text,'voice'=>$voice_name,'response_format'=>'mp3'] +
                          ($use_speed ? ['speed'=>(float)$rate] : []));
            $ch = curl_init('https://api.openai.com/v1/audio/speech');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$openai_key],
                CURLOPT_TIMEOUT        => 60,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 200 && $body) {
                file_put_contents($filepath, $body);
                $result = ['success' => true];
            } else {
                $result = ['success' => false, 'error' => 'OpenAI TTS error HTTP '.$status];
            }
        } else {
            // Azure TTS (fallback)
            ob_start();
            $result = generateVoice($text, $voice_id, $rate, $filepath);
            ob_end_clean();
        }

        if ($result['success'] ?? false) {
            $esc = mysqli_real_escape_string($conn, $filename);
            $ve  = mysqli_real_escape_string($conn, $voice_id);
            $re  = mysqli_real_escape_string($conn, $rate);
            mysqli_query($conn, "UPDATE hdb_podcast_stories SET audio_file='$esc', voice_id='$ve', voice_rate='$re' WHERE id=$scene_id");
            ob_clean();
            echo json_encode(['success'=>true,'filename'=>$filename]);
        } else {
            ob_clean();
            echo json_encode(['success'=>false,'message'=>$result['error'] ?? $result['message'] ?? 'TTS failed']);
        }
        exit;
    }

    // ── upload_podcast_music ──────────────────────────────────────────────
    if ($action === 'upload_podcast_music') {
        $resp = ['success'=>false,'message'=>''];
        if (!isset($_FILES['music_file']) || $_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {
            $resp['message'] = 'Upload error: '.($_FILES['music_file']['error'] ?? 'no file');
            echo json_encode($resp); exit;
        }
        $file = $_FILES['music_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3','wav','ogg','m4a'])) {
            $resp['message'] = 'Only MP3/WAV/OGG/M4A allowed'; echo json_encode($resp); exit;
        }
        if ($file['size'] > 20*1024*1024) {
            $resp['message'] = 'Max 20MB'; echo json_encode($resp); exit;
        }
        $dir = __DIR__.'/podcast_music/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $filename = 'music_'.$podcast_id.'_'.time().'.'.$ext;
        if (move_uploaded_file($file['tmp_name'], $dir.$filename)) {
            $esc = mysqli_real_escape_string($conn, $filename);
            mysqli_query($conn, "UPDATE hdb_podcasts SET music_file='$esc' WHERE id=$podcast_id");
            echo json_encode(['success'=>true,'filename'=>$filename]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Failed to save file']);
        }
        exit;
    }

    // ── get_podcast_info ──────────────────────────────────────────────────
    if ($action === 'get_podcast_info') {
        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
        if (!$r) { echo json_encode(['success'=>false,'message'=>'Podcast not found']); exit; }
        echo json_encode(['success'=>true,'row'=>$r]); exit;
    }

    // ── save_podcast_voices ────────────────────────────────────────────────
    // ── save_audio_speed ──────────────────────────────────────────────────────
    if ($action === 'save_audio_speed') {
        $spd = (float)($_POST['speed'] ?? 1.0);
        $spd = max(0.5, min(2.0, $spd)); // clamp to valid range
        mysqli_query($conn, "UPDATE hdb_podcasts SET audio_speed=$spd WHERE id=$podcast_id");
        echo json_encode(['success' => true, 'speed' => $spd]); exit;
    }

    if ($action === 'save_podcast_voices') {
        $hv_raw = trim($_POST['host_voice_id']  ?? '');
        $gv_raw = trim($_POST['guest_voice_id'] ?? '');
        // Ensure openai: prefix is stored
        $hv = mysqli_real_escape_string($conn, (strpos($hv_raw,':')===false && $hv_raw) ? 'openai:'.$hv_raw : $hv_raw);
        $gv = mysqli_real_escape_string($conn, (strpos($gv_raw,':')===false && $gv_raw) ? 'openai:'.$gv_raw : $gv_raw);
        $ok  = mysqli_query($conn,
            "UPDATE hdb_podcasts SET host_voice='$hv', guest_voice='$gv' WHERE id=$podcast_id");
        $err = $ok ? '' : mysqli_error($conn);
        echo json_encode(['success'=>(bool)$ok, 'error'=>$err, 'rows'=>mysqli_affected_rows($conn)]); exit;
    }

    // ── get_scene_captions ────────────────────────────────────────────────
    if ($action === 'get_scene_captions') {
        $sid = (int)$_POST['story_id'];
        $caps = [];
        $q = mysqli_query($conn,
            "SELECT * FROM hdb_captions WHERE podcast_id=$podcast_id AND story_id=$sid ORDER BY z_index ASC, id ASC");
        while ($r = mysqli_fetch_assoc($q)) $caps[] = $r;
        echo json_encode(['success'=>true,'captions'=>$caps]); exit;
    }
	if ($action === 'add_image_caption') {
		$sid      = (int)$_POST['story_id'];
		$name     = mysqli_real_escape_string($conn, $_POST['caption_name'] ?? 'img_cap');
		$filename = mysqli_real_escape_string($conn, $_POST['filename']     ?? '');
		$px       = (int)($_POST['position_x'] ?? 20);
		$py       = (int)($_POST['position_y'] ?? 20);
		$pw       = (int)($_POST['width']      ?? 120);
		$ph       = (int)($_POST['height']     ?? 120);

		mysqli_query($conn,
			"INSERT INTO hdb_captions
			 (podcast_id, story_id, caption_type, caption_name, text_content,
			  fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline, text_align,
			  bg_color, bg_opacity, bg_enabled,
			  position_x, position_y, width, rotation,
			  animation_style, animation_speed,
			  is_visible, z_index)
			 VALUES
			 ($podcast_id, $sid, 'image', '$name', '$filename',
			  '', 0, '', 'normal', 'normal', 0, 'left',
			  '', 0, 0,
			  $px, $py, $pw, $ph,
			  'none', 1.0,
			  1, 3)");

		$new_id = mysqli_insert_id($conn);
		$cap    = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT * FROM hdb_captions WHERE id=$new_id LIMIT 1"));
		echo json_encode(['success'=>true,'caption'=>$cap]);
		exit;
	}
    // ── add_caption ───────────────────────────────────────────────────────
     if ($action === 'add_caption') {
		$sid  = (int)$_POST['story_id'];
		$name = mysqli_real_escape_string($conn, $_POST['caption_name'] ?? 'caption');
		$text = mysqli_real_escape_string($conn, $_POST['text_content'] ?? 'New caption');

		// Use safe defaults (no getUserSettingsWiz dependency)
		$ff   = 'Arial,sans-serif';
		$fs   = 28;
		$fc   = '#ffff00';
		$fw   = 'bold';
		$bc   = '#000000';
		$be   = 0;
		$bop  = 0.7;
		$px   = 20;
		$py   = 400;
		$pw   = 320;
		$anim = 'none';
		$aspd = 1.0;

		// Try to load from hdb_user_settings if the table exists
		$ust = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_user_settings'");
		if (mysqli_num_rows($ust) > 0) {
			$uq = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id={$_SESSION['admin_id']} LIMIT 1");
			if ($uq && $ur = mysqli_fetch_assoc($uq)) {
				$ff   = mysqli_real_escape_string($conn, $ur['fontfamily']    ?? $ff);
				$fs   = (int)($ur['fontsize']                                 ?? $fs);
				$fc   = mysqli_real_escape_string($conn, $ur['fontcolor']     ?? $fc);
				$fw   = mysqli_real_escape_string($conn, $ur['fontweight']    ?? $fw);
				$bc   = mysqli_real_escape_string($conn, $ur['fontcolor_bg']  ?? $bc);
				$be   = (int)($ur['fontbg_enable']                            ?? $be);
				$px   = (int)($ur['position_x']                               ?? $px);
				$py   = (int)($ur['position_y']                               ?? $py);
				$pw   = (int)($ur['width']                                    ?? $pw);
				$anim = mysqli_real_escape_string($conn, $ur['caption_style'] ?? $anim);
				$aspd = (float)($ur['caption_speed']                          ?? $aspd);
			}
		}

		mysqli_query($conn,
			"INSERT INTO hdb_captions
			 (podcast_id, story_id, caption_type, caption_name, text_content,
			  fontfamily, fontsize, fontcolor, fontweight, fontstyle, underline, text_align,
			  bg_color, bg_opacity, bg_enabled,
			  position_x, position_y, width,
			  animation_style, animation_speed,
			  is_visible, z_index)
			 VALUES
			 ($podcast_id, $sid, 'custom', '$name', '$text',
			  '$ff', $fs, '$fc', '$fw', 'normal', 0, 'center',
			  '$bc', $bop, $be,
			  $px, $py, $pw,
			  '$anim', $aspd,
			  1, 2)");

		$new_id = mysqli_insert_id($conn);
		$cap    = mysqli_fetch_assoc(mysqli_query($conn,
			"SELECT * FROM hdb_captions WHERE id=$new_id LIMIT 1"));
		echo json_encode(['success'=>true,'caption'=>$cap]);
		exit;
	}

    // ── delete_caption ────────────────────────────────────────────────────
    if ($action === 'delete_caption') {
        $cap_id = (int)$_POST['caption_id'];
        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT caption_type, caption_name FROM hdb_captions WHERE id=$cap_id LIMIT 1"));
        if ($chk && strtolower($chk['caption_name']) === 'main') {
            echo json_encode(['success'=>false,'message'=>'Cannot delete main caption']); exit;
        }
        $ok = mysqli_query($conn, "DELETE FROM hdb_captions WHERE id=$cap_id AND podcast_id=$podcast_id");
        echo json_encode(['success'=>(bool)$ok]); exit;
    }

    // ── save_caption ──────────────────────────────────────────────────────
	if ($action === 'save_enabled_slots') {
		$scene_id = (int)$_POST['scene_id'];
		$updates = [];
		foreach (['slot_main','slot_1','slot_2','slot_3','slot_4'] as $col) {
			if (isset($_POST[$col])) {
				$val = (int)$_POST[$col];
				$updates[] = "`$col`=$val";
			}
		}
		if ($updates) {
			$sql = "UPDATE hdb_podcast_stories SET " . implode(',', $updates) . " WHERE id=$scene_id";
			mysqli_query($conn, $sql);
		}
		echo json_encode(['success' => true]);
		exit;
	}
	
	
    if ($action === 'save_caption') {
        $cap_id = (int)$_POST['caption_id'];
        $fields = ['text_content','fontfamily','fontsize','fontcolor','fontweight','fontstyle',
                   'underline','linethrough','text_align','bg_color','bg_opacity','bg_enabled',
                   'outline_color','outline_width','outline_enabled','stroke_color','stroke_width',
                   'stroke_enabled','position_x','position_y','width','rotation',
                   'animation_style','animation_speed','is_visible','z_index',
                   'caption_box_border_thickness','caption_box_border_color'];
        $sets = [];
        foreach ($fields as $f) {
            if (!isset($_POST[$f])) continue;
            $v = mysqli_real_escape_string($conn, $_POST[$f]);
            $sets[] = "`$f`='$v'";
        }
        if ($sets) {
            mysqli_query($conn,
                "UPDATE hdb_captions SET ".implode(',',$sets)." WHERE id=$cap_id AND podcast_id=$podcast_id");
        }

        // ── Auto-save to hdb_user_settings for header/footer/logo captions ──
        // Fetch the caption's type and name to decide whether to persist settings
        $cap_meta = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT caption_type, caption_name FROM hdb_captions WHERE id=$cap_id LIMIT 1"));
        if ($cap_meta) {
            $cap_type_lc = strtolower(trim($cap_meta['caption_type'] ?? ''));
            $cap_name_lc = strtolower(trim($cap_meta['caption_name'] ?? ''));

            // Save to user_settings when caption is header, footer, or logo
            // (caption_type OR caption_name matches those keywords).
            // Exception: never save text_content when caption_type='text' AND caption_name='main'
            $is_main_text = ($cap_name_lc === 'main');
            $is_persistent_caption = in_array($cap_name_lc, ['main','header','footer','logo'])
                                  || in_array($cap_type_lc, ['header','footer','logo']);

            if ($is_persistent_caption) {
                // Ensure the two new columns exist
                foreach (['animation_style VARCHAR(60) NOT NULL DEFAULT "none"',
                          'animation_speed FLOAT NOT NULL DEFAULT 1.0'] as $col_def) {
                    $col_name = explode(' ', $col_def)[0];
                    $chk = mysqli_query($conn, "SHOW COLUMNS FROM hdb_user_settings LIKE '$col_name'");
                    if ($chk && mysqli_num_rows($chk) === 0) {
                        mysqli_query($conn, "ALTER TABLE hdb_user_settings ADD COLUMN $col_def");
                    }
                }

                // Map caption fields → user_settings columns
                $us_map = [
                    'fontfamily'     => 'fontfamily',
                    'fontsize'       => 'fontsize',
                    'fontcolor'      => 'fontcolor',
                    'fontweight'     => 'fontweight',
                    'bg_color'       => 'fontcolor_bg',
                    'bg_enabled'     => 'fontbg_enable',
                    'position_x'     => 'position_x',
                    'position_y'     => 'position_y',
                    'width'          => 'width',
                    'animation_style'=> 'animation_style',
                    'animation_speed'=> 'animation_speed',
                    // also keep legacy aliases in sync
                    // (caption_style / caption_speed kept for add_caption backward compat)
                ];

                $us_sets = [];
                foreach ($us_map as $cap_field => $us_col) {
                    // Skip text_content for main captions
                    if ($cap_field === 'text_content' && $is_main_text) continue;
                    if (!isset($_POST[$cap_field])) continue;
                    $v = mysqli_real_escape_string($conn, $_POST[$cap_field]);
                    $us_sets[] = "`$us_col`='$v'";
                    // Keep legacy aliases in sync
                    if ($cap_field === 'animation_style') $us_sets[] = "`caption_style`='$v'";
                    if ($cap_field === 'animation_speed') $us_sets[] = "`caption_speed`='$v'";
                }

                if ($us_sets) {
                    $us_sets_sql = implode(',', $us_sets);
                    // Ensure a row exists for this admin, then UPDATE
                    mysqli_query($conn,
                        "INSERT IGNORE INTO hdb_user_settings (admin_id) VALUES ($admin_id)");
                    mysqli_query($conn,
                        "UPDATE hdb_user_settings SET $us_sets_sql WHERE admin_id=$admin_id");
                }
            }
        }
        // ── end auto-save to user_settings ──────────────────────────────────

        echo json_encode(['success'=>true]); exit;
    }

    // ── toggle_caption_visible ─────────────────────────────────────────────
    if ($action === 'toggle_caption_visible') {
        $cap_id = (int)$_POST['caption_id'];
        $vis    = (int)$_POST['is_visible'];
        mysqli_query($conn, "UPDATE hdb_captions SET is_visible=$vis WHERE id=$cap_id AND podcast_id=$podcast_id");
        echo json_encode(['success'=>true]); exit;
    }

    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['success'=>false,'message'=>'PHP error: '.$e->getMessage().' in '.$e->getFile().' line '.$e->getLine()]);
    }
	  exit;
}
	?> 