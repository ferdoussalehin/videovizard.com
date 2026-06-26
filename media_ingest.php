<?php
// ============================================================
// media_ingest.php — Shared Media Ingest Library
// ============================================================
if (!defined('MEDIA_INGEST_LOADED')) {
    define('MEDIA_INGEST_LOADED', true);

function mi_ffmpeg(): string {
    static $path = null;
    if ($path !== null) return $path;
    foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $p) {
        if (file_exists($p)) { $path = $p; return $path; }
    }
    $path = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '/usr/bin/ffmpeg');
    return $path;
}

function mi_log(string $msg): void {
    $log = defined('MEDIA_INGEST_LOG') ? MEDIA_INGEST_LOG : __DIR__ . '/a_errors.log';
    file_put_contents($log, date('[Y-m-d H:i:s] ') . "[media_ingest] $msg\n", FILE_APPEND);
}

function mi_download_url(string $url, string $dest, int $timeout = 120): bool {
    $fp = fopen($dest, 'wb');
    if (!$fp) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE            => $fp,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_USERAGENT       => 'VideoVizard-MediaIngest/1.0',
        CURLOPT_SSL_VERIFYPEER  => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($code !== 200 || $err || !file_exists($dest) || filesize($dest) < 1000) {
        @unlink($dest);
        mi_log("download_url failed HTTP $code $err: $url");
        return false;
    }
    return true;
}

function mi_trim_video(string $src, string $dest, int $max_sec = 10): bool {
    $ff  = mi_ffmpeg();
    $cmd = escapeshellcmd($ff)
         . ' -y -i ' . escapeshellarg($src)
         . ' -t ' . $max_sec
         . ' -c copy '
         . escapeshellarg($dest)
         . ' 2>/dev/null';
    exec($cmd, $out, $rc);
    return ($rc === 0 && file_exists($dest) && filesize($dest) > 1000);
}

function mi_thumbnail(string $src_path, string $thumb_path, bool $is_video): bool {
    if ($is_video) {
        $ff = mi_ffmpeg();
        foreach (['00:00:01', '00:00:00'] as $ss) {
            $cmd = escapeshellcmd($ff)
                 . ' -y -i ' . escapeshellarg($src_path)
                 . " -ss $ss -vframes 1 -vf \"scale=360:-1\" "
                 . escapeshellarg($thumb_path)
                 . ' 2>/dev/null';
            exec($cmd, $o, $rc);
            if ($rc === 0 && file_exists($thumb_path) && filesize($thumb_path) > 100) return true;
        }
        return false;
    }

    $info = @getimagesize($src_path);
    if (!$info) return false;
    $src_img = match($info['mime']) {
        'image/jpeg' => @imagecreatefromjpeg($src_path),
        'image/png'  => @imagecreatefrompng($src_path),
        'image/webp' => @imagecreatefromwebp($src_path),
        default      => null,
    };
    if (!$src_img) return false;
    $sw = imagesx($src_img); $sh = imagesy($src_img);
    $tw = 360; $th = (int)($sh * $tw / $sw);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src_img, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    $ok = imagejpeg($dst, $thumb_path, 85);
    imagedestroy($src_img); imagedestroy($dst);
    return $ok;
}

function mi_clean_for_embedding(string $tags): string {
    return trim(preg_replace('/\s+/', ' ', str_replace('|', ',', $tags)));
}

function mi_vision_tag(
    string $thumb_path,
    string $openai_key,
    string $category    = '',
    string $subcategory = '',
    string $context     = ''
): array {
    if (!file_exists($thumb_path) || !$openai_key) return [];
    $img = @file_get_contents($thumb_path);
    if (!$img) return [];

    $b64  = base64_encode($img);
    $cat  = $category    ?: 'Stock Media';
    $sub  = $subcategory ?: 'General';
    $ctx  = $context     ?: "Stock video for '$cat' / '$sub' category.";

    $system = "You are a stock video library tagging expert. $ctx

Analyze this media thumbnail and return JSON with exactly these fields:

nl_tags: A pipe-separated string in this EXACT format:
[One vivid sentence describing the main visual action or scene] | [Top-level category] | [Sub-category] | [Mood or Energy] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag] | [specific visual tag]

Rules:
- Segment 1: one complete sentence — what is literally visible and happening
- Segment 2: top-level category → use: $cat
- Segment 3: sub-category → use: $sub
- Segment 4: mood or energy (e.g. Energetic, Calm, Professional, Dramatic, Luxurious, Playful)
- Segments 5-16: 12 specific 3-6 word visual search tags describing objects, actions, settings, styles
- Separator: pipe | with no extra spaces around it

hashtags: 8-12 relevant hashtags without # symbol, comma-separated
ai_description: 1 short factual sentence describing what is literally visible
ai_tags: array of 10-15 single keyword tags

Return ONLY valid JSON, no markdown backticks.";

    $messages = [
        ["role" => "system", "content" => $system],
        ["role" => "user",   "content" => [
            ["type" => "image_url", "image_url" => ["url" => "data:image/jpeg;base64,{$b64}", "detail" => "low"]],
            ["type" => "text",      "text"      => "Analyze this media. Use '$cat' as category and '$sub' as sub-category. Return JSON only."],
        ]],
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $openai_key"],
        CURLOPT_POSTFIELDS     => json_encode([
            "model"           => "gpt-4o-mini",
            "messages"        => $messages,
            "max_tokens"      => 600,
            "temperature"     => 0.4,
            "response_format" => ["type" => "json_object"],
        ]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        mi_log("vision HTTP $http: " . substr($res, 0, 200));
        return [];
    }

    $vj  = json_decode($res, true);
    $raw = $vj["choices"][0]["message"]["content"] ?? '{}';
    $p   = json_decode($raw, true);
    if (!$p) { mi_log("vision JSON parse failed: $raw"); return []; }

    return [
        'nl_tags'        => trim($p['nl_tags']        ?? ''),
        'hashtags'       => trim($p['hashtags']        ?? ''),
        'ai_description' => trim($p['ai_description']  ?? ''),
        'ai_tags'        => $p['ai_tags'] ?? [],
    ];
}

function mi_embed(string $text, string $openai_key): ?array {
    if (!$text || !$openai_key) return null;
    $clean = mi_clean_for_embedding($text);
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "Authorization: Bearer $openai_key"],
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'text-embedding-3-large', 'input' => $clean]),
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200) { mi_log("embed HTTP $http"); return null; }
    $d = json_decode($res, true);
    return $d['data'][0]['embedding'] ?? null;
}

//
// $options additions:
//   'is_ai_generated' => false   true = produced by an AI model (fal.ai/Modal
//                                 scene image or video) rather than uploaded
//                                 by the user. Stored as
//                                 hdb_image_data.is_ai_generated so callers
//                                 can gate AI-only workflows (auto video
//                                 generation) on it.
//
function mediaIngest(array $options, $conn, string $openai_key = ''): array {

    $root_dir      = $options['root_dir']        ?? __DIR__;
    $admin_id      = (int)($options['admin_id']    ?? 0);
    $company_id    = (int)($options['company_id']  ?? 0);
    $promo_group   = trim($options['promo_group']    ?? '');
    $promo_subgroup= trim($options['promo_subgroup'] ?? '');
    $context       = trim($options['context']        ?? '');
    $max_sec       = (int)($options['max_video_sec'] ?? 10);
    $skip_tagging  = (bool)($options['skip_tagging']  ?? false);
    $skip_if_exists= (bool)($options['skip_if_exists'] ?? true);
    $prefix        = trim($options['filename_prefix'] ?? 'media');
    $is_ai_generated = (bool)($options['is_ai_generated'] ?? false);

    $vid_subfolder = $options['video_folder'] ?? 'podcast_videos';
    $img_subfolder = $options['image_folder'] ?? 'podcast_images';

    if ($admin_id > 0 && $company_id > 0) {
        $user_base    = $root_dir . '/user_media/user_id_' . $admin_id . '_company_id_' . $company_id;
        $video_dir    = $user_base . '/' . $vid_subfolder;
        $image_dir    = $user_base . '/' . $img_subfolder;
        $video_folder = 'user_media/user_id_' . $admin_id . '_company_id_' . $company_id . '/' . $vid_subfolder;
        $image_folder = 'user_media/user_id_' . $admin_id . '_company_id_' . $company_id . '/' . $img_subfolder;

    } elseif ($admin_id > 0 && $company_id === 0) {
        $user_base    = $root_dir . '/user_media/user_id_' . $admin_id;
        $video_dir    = $user_base . '/' . $vid_subfolder;
        $image_dir    = $user_base . '/' . $img_subfolder;
        $video_folder = 'user_media/user_id_' . $admin_id . '/' . $vid_subfolder;
        $image_folder = 'user_media/user_id_' . $admin_id . '/' . $img_subfolder;

    } else {
        $video_dir    = $root_dir . '/' . $vid_subfolder;
        $image_dir    = $root_dir . '/' . $img_subfolder;
        $video_folder = $vid_subfolder;
        $image_folder = $img_subfolder;
    }
    $thumb_dir    = $root_dir . '/' . ($options['thumb_folder'] ?? 'podcast_thumbnails');

    foreach ([$video_dir, $image_dir, $thumb_dir] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }

    $tmp_to_clean = null;
    $src_path     = '';
    $original_name= '';
    $mime         = '';
    $file_size    = 0;

    if (!empty($options['file']) && !empty($options['file']['tmp_name'])) {
        $f    = $options['file'];
        $mime = mime_content_type($f['tmp_name']);
        $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif',
                    'video/mp4','video/webm','video/quicktime','video/x-msvideo','video/x-matroska'];
        if (!in_array($mime, $allowed)) {
            return ['success' => false, 'message' => "Invalid file type: $mime"];
        }
        if (($f['size'] ?? 0) > 200 * 1024 * 1024) {
            return ['success' => false, 'message' => 'File too large (max 200MB)'];
        }
        $src_path      = $f['tmp_name'];
        $original_name = $f['name'] ?? '';
        $file_size     = $f['size'] ?? filesize($src_path);

    } elseif (!empty($options['local_path'])) {
        $src_path  = $options['local_path'];
        if (!file_exists($src_path)) {
            return ['success' => false, 'message' => "Local file not found: $src_path"];
        }
        $mime          = mime_content_type($src_path);
        $original_name = basename($src_path);
        $file_size     = filesize($src_path);

    } elseif (!empty($options['remote_url'])) {
        $tmp = sys_get_temp_dir() . '/mi_' . getmypid() . '_' . time() . '.tmp';
        mi_log("downloading: " . $options['remote_url']);
        if (!mi_download_url($options['remote_url'], $tmp)) {
            return ['success' => false, 'message' => 'Failed to download: ' . $options['remote_url']];
        }
        $mime          = mime_content_type($tmp);
        $original_name = basename(parse_url($options['remote_url'], PHP_URL_PATH));
        $file_size     = filesize($tmp);
        $src_path      = $tmp;
        $tmp_to_clean  = $tmp;

    } else {
        return ['success' => false, 'message' => 'No source provided (file, local_path, or remote_url required)'];
    }

    $is_video = strpos($mime, 'video/') === 0;
    $ext_map  = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'video/webm' => 'webm',
        'video/quicktime' => 'mov', 'video/x-msvideo' => 'avi',
        'video/x-matroska' => 'mkv',
    ];
    $ext = $ext_map[$mime] ?? strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) ?: ($is_video ? 'mp4' : 'jpg');

    $filename  = $prefix . '_' . time() . '_' . substr(md5($original_name . $admin_id), 0, 6) . '.' . $ext;
    $save_dir  = $is_video ? $video_dir  : $image_dir;
    $db_folder = $is_video ? $video_folder : $image_folder;
    $save_path = $save_dir . '/' . $filename;

    if (!empty($options['dedupe_filename'])) {
        $check_fn = basename($options['dedupe_filename']);
        $fn_e     = mysqli_real_escape_string($conn, $check_fn);
        $pg_chk_e = mysqli_real_escape_string($conn, $promo_group);
        $psg_chk_e= mysqli_real_escape_string($conn, $promo_subgroup);
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, thumbnail, natural_language_tags FROM hdb_image_data
             WHERE image_name='$fn_e'
               AND promo_group='$pg_chk_e' AND promo_subgroup='$psg_chk_e'
               AND admin_id=$admin_id AND company_id=$company_id
             LIMIT 1"));
        if ($existing && $skip_if_exists) {
            mi_log("duplicate skipped: $check_fn (id={$existing['id']})");
            if ($tmp_to_clean) @unlink($tmp_to_clean);
            return [
                'success'        => true,
                'image_id'       => (int)$existing['id'],
                'filename'       => $check_fn,
                'folder'         => $db_folder,
                'thumbnail'      => $existing['thumbnail'] ?? '',
                'nl_tags'        => $existing['natural_language_tags'] ?? '',
                'tagged'         => !empty($existing['natural_language_tags']),
                'embedding_dims' => 0,
                'cached'         => true,
                'message'        => 'Already in library',
            ];
        }
        $filename  = $check_fn;
        $save_path = $save_dir . '/' . $filename;
    }

    if ($is_video && $max_sec > 0) {
        $trimmed = sys_get_temp_dir() . '/mi_trim_' . getmypid() . '_' . time() . '.mp4';
        if (mi_trim_video($src_path, $trimmed, $max_sec)) {
            if ($tmp_to_clean) @unlink($tmp_to_clean);
            $src_path     = $trimmed;
            $tmp_to_clean = $trimmed;
            $file_size    = filesize($trimmed);
            mi_log("trimmed to {$max_sec}s: $filename");
        } else {
            mi_log("trim failed (using original): $filename");
        }
    }

    if ($is_video && !empty($options['reencode']) && $options['reencode'] === true) {
        $ff      = mi_ffmpeg();
        $encoded = sys_get_temp_dir() . '/mi_enc_' . getmypid() . '_' . time() . '.mp4';
        $cmd = escapeshellcmd($ff)
             . ' -y -i ' . escapeshellarg($src_path)
             . ' -t ' . max(1, $max_sec)
             . ' -vf "scale=-2:720" -r 30'
             . ' -c:v libx264 -crf 23 -preset fast'
             . ' -c:a aac -b:a 128k -movflags +faststart'
             . ' ' . escapeshellarg($encoded) . ' 2>&1';
        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc === 0 && file_exists($encoded) && filesize($encoded) > 1000) {
            if ($tmp_to_clean) @unlink($tmp_to_clean);
            $src_path     = $encoded;
            $tmp_to_clean = $encoded;
            $file_size    = filesize($encoded);
            $ext           = 'mp4';
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'mp4') {
                $filename  = pathinfo($filename, PATHINFO_FILENAME) . '.mp4';
                $save_path = $save_dir . '/' . $filename;
            }
            mi_log("re-encoded to 720p: $filename");
        } else {
            mi_log("re-encode failed, using source: " . implode(' | ', array_slice($out, -3)));
        }
    }

    if ($src_path !== $save_path) {
        $moved = false;
        if (!empty($options['file']['tmp_name']) && $options['file']['tmp_name'] === $src_path) {
            $moved = @move_uploaded_file($src_path, $save_path);
        }
        if (!$moved) $moved = @copy($src_path, $save_path);
        if (!$moved || !file_exists($save_path)) {
            if ($tmp_to_clean) @unlink($tmp_to_clean);
            return ['success' => false, 'message' => "Could not save file to $save_path — check folder permissions"];
        }
        @chmod($save_path, 0644);
        if ($tmp_to_clean && $tmp_to_clean !== $save_path) @unlink($tmp_to_clean);
        $tmp_to_clean = null;
    }
    $file_size = filesize($save_path);

    $thumb_filename = pathinfo($filename, PATHINFO_FILENAME) . '_thumb.jpg';
    $thumb_path     = $thumb_dir . '/' . $thumb_filename;
    $thumb_ok       = mi_thumbnail($save_path, $thumb_path, $is_video);
    mi_log("thumbnail: " . ($thumb_ok ? $thumb_filename : 'FAILED') . " for $filename");

    $fn_e   = mysqli_real_escape_string($conn, $filename);
    $th_e   = mysqli_real_escape_string($conn, $thumb_ok ? $thumb_filename : '');
    $fo_e   = mysqli_real_escape_string($conn, $db_folder);
    $pg_e   = mysqli_real_escape_string($conn, $promo_group);
    $psg_e  = mysqli_real_escape_string($conn, $promo_subgroup);
    $mt     = $is_video ? 'video' : 'image';
    $sz     = (int)$file_size;
    $iag    = $is_ai_generated ? 1 : 0;

    mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN IF NOT EXISTS image_folder VARCHAR(500) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE hdb_image_data ADD COLUMN IF NOT EXISTS is_ai_generated TINYINT(1) NOT NULL DEFAULT 0");

    mysqli_query($conn, "INSERT INTO hdb_image_data
        (image_name, media_type, status, created_at, updated_at, file_size,
         thumbnail, image_folder,
         admin_id, company_id,
         promo_group, promo_subgroup,
         ai_group, ai_subgroup,
         is_ai_generated,
         skip_embedding, tag_flag, resize_flag)
        VALUES
        ('$fn_e', '$mt', 'active', NOW(), NOW(), $sz,
         '$th_e', '$fo_e',
         $admin_id, $company_id,
         '$pg_e', '$psg_e',
         '$pg_e', '$psg_e',
         $iag,
         0, 0, " . ($is_video ? 8 : 0) . ")");

    $image_id = (int)mysqli_insert_id($conn);
    if (!$image_id) {
        mi_log("DB insert failed for $filename: " . mysqli_error($conn));
        return ['success' => false, 'message' => 'DB insert failed: ' . mysqli_error($conn),
                'filename' => $filename, 'folder' => $db_folder, 'thumbnail' => $thumb_ok ? $thumb_filename : ''];
    }
    mi_log("inserted id=$image_id file=$filename folder=$db_folder group=$promo_group sub=$promo_subgroup ai_generated=$iag");

    $nl_tags = ''; $hashtags = ''; $ai_desc = ''; $ai_tags = [];
    $embedding = null; $embedding_dims = 0;

    if (!$skip_tagging && $openai_key && $thumb_ok) {
        $tags = mi_vision_tag($thumb_path, $openai_key, $promo_group, $promo_subgroup, $context);
        if (!empty($tags['nl_tags'])) {
            $nl_tags  = $tags['nl_tags'];
            $hashtags = $tags['hashtags']        ?? '';
            $ai_desc  = $tags['ai_description']  ?? '';
            $ai_tags  = $tags['ai_tags']          ?? [];
            mi_log("vision OK id=$image_id nl=" . substr($nl_tags, 0, 80));

            $embedding = mi_embed($nl_tags, $openai_key);
            if ($embedding) {
                $embedding_dims = count($embedding);
                $nl_e  = mysqli_real_escape_string($conn, $nl_tags);
                $ht_e  = mysqli_real_escape_string($conn, $hashtags);
                $ad_e  = mysqli_real_escape_string($conn, $ai_desc);
                $at_e  = mysqli_real_escape_string($conn, json_encode($ai_tags));
                $emb_e = mysqli_real_escape_string($conn, json_encode($embedding));
                mysqli_query($conn, "UPDATE hdb_image_data SET
                    natural_language_tags = '$nl_e',
                    image_hashtags        = '$ht_e',
                    description           = '$ad_e',
                    ai_tags               = '$at_e',
                    embedding             = '$emb_e',
                    tag_flag              = 1,
                    updated_at            = NOW()
                    WHERE id = $image_id");
                mi_log("embedded id=$image_id dims=$embedding_dims");
            }
        } else {
            mi_log("vision returned no tags for id=$image_id");
        }
    } elseif ($skip_tagging) {
        mi_log("tagging skipped for id=$image_id");
    } elseif (!$thumb_ok) {
        mi_log("tagging skipped — no thumbnail for id=$image_id");
    }

    return [
        'success'        => true,
        'image_id'       => $image_id,
        'filename'       => $filename,
        'folder'         => $db_folder,
        'thumbnail'      => $thumb_ok ? $thumb_filename : '',
        'nl_tags'        => $nl_tags,
        'hashtags'       => $hashtags,
        'ai_description' => $ai_desc,
        'ai_tags'        => $ai_tags,
        'media_type'     => $mt,
        'file_size'      => $file_size,
        'is_ai_generated'=> $is_ai_generated,
        'tagged'         => !empty($nl_tags),
        'embedding_dims' => $embedding_dims,
        'cached'         => false,
        'message'        => 'OK',
    ];
}

}
