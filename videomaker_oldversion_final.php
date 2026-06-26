<?php

// 1. Database & Config and making

require_once 'check_session.php';

session_start();



$admin_id    = $_SESSION['admin_id'];

$admin_level = $_SESSION['level'];

$client_id   = $_SESSION['client_id'];



if(!isset($_SESSION['admin_id']))

{

    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));

    exit; 

} 

  

include 'dbconnect_hdb.php'; 

require_once 'chatgpt_functions.php';

require_once 'generate_image_api.php';



ini_set('error_log', __DIR__ . '/a_errors.log'); // Server errors

$podcast_id    = $_GET['podcast_id'] ?? 0;

$lang_code     = $_GET['lang_filter'] ?? 'en';



// Get user info for header

$firstname = '';

$admin_initial = 'U';

if (isset($_SESSION['admin_id'])) {

    $user_query = mysqli_query($conn, "SELECT firstname, email FROM hdb_users WHERE id = '".$_SESSION['admin_id']."'");

    if ($user_query && mysqli_num_rows($user_query) > 0) {

        $user_data = mysqli_fetch_assoc($user_query);

        $firstname = $user_data['firstname'] ?? 'User';

        $admin_initial = strtoupper(substr($firstname, 0, 1));

	

			

		

			

		

		

    }

}





// Get user plan for free trial check

$user_query = mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id = '$admin_id' LIMIT 1");

$user_row = mysqli_fetch_assoc($user_query);

$plan_type = $user_row['plan_type'] ?? 'free_trial';

$is_free_trial = ($plan_type === 'free_trial');



// Get podcast_id from URL if present

$url_podcast_id = isset($_GET['podcast_id']) ? (int)$_GET['podcast_id'] : 0;

$url_lang_filter = isset($_GET['lang_filter']) ? $_GET['lang_filter'] : 'en';

$video_type = 'standard'; // default

if ($url_podcast_id > 0) {

    $type_query = mysqli_query($conn, "SELECT video_type, lang_code FROM hdb_podcasts WHERE id = $url_podcast_id");

    if ($type_query && mysqli_num_rows($type_query) > 0) {

        $type_row = mysqli_fetch_assoc($type_query);

        $podcast_lang_code  = $type_row['lang_code'] ?? 'en';

        $lang_code =  $podcast_lang_code;

		$video_type = $type_row['video_type'] ?? 'standard';

    }

}











if ($url_podcast_id > 0) {

    // First update the updated_at timestamp

    $update_sql = "UPDATE hdb_podcasts SET updated_at = NOW() WHERE id = $url_podcast_id";

    mysqli_query($conn, $update_sql);

    

    // Check for image files in podcast stories (priority)

    $image_check_sql = "SELECT image_file FROM hdb_podcast_stories 

                        WHERE podcast_id = $url_podcast_id 

                        AND image_file IS NOT NULL 

                        AND image_file != '' 

                        LIMIT 1";

    $image_result = mysqli_query($conn, $image_check_sql);

    

    if ($image_result && mysqli_num_rows($image_result) > 0) {

        // Found an image - use the first one as thumbnail

        $image_row = mysqli_fetch_assoc($image_result);

        $thumbnail = $image_row['image_file'];

        

        $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";

        mysqli_query($conn, $thumbnail_sql);

    } else {

        // No image found, check for video files

        $video_check_sql = "SELECT video_file FROM hdb_podcast_stories 

                            WHERE podcast_id = $url_podcast_id 

                            AND video_file IS NOT NULL 

                            AND video_file != '' 

                            LIMIT 1";

        $video_result = mysqli_query($conn, $video_check_sql);

        

        if ($video_result && mysqli_num_rows($video_result) > 0) {

            // Found a video - use video filename as thumbnail reference

            $video_row = mysqli_fetch_assoc($video_result);

            $thumbnail = $video_row['video_file'];

            

            $thumbnail_sql = "UPDATE hdb_podcasts SET thumbnail = '$thumbnail' WHERE id = $url_podcast_id";

            mysqli_query($conn, $thumbnail_sql);

        }

        // If no media found, leave thumbnail as is (don't overwrite)

    }

}



// Ensure podcast_lang_code is always defined

if (!isset($podcast_lang_code)) $podcast_lang_code = 'en';



// Fetch podcast title from DB — use url_podcast_id (same as $podcast_id)

$podcast_title = '';

$podcast_music = '';

if ($url_podcast_id > 0) {

    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title, music_file FROM hdb_podcasts WHERE id = " . (int)$url_podcast_id));

    $podcast_title = $row['title']      ?? '';

    $podcast_music = $row['music_file'] ?? '';

}



// Fetch all scenes for this podcast

// Fetch all scenes for this podcast - INCLUDING HASHTAGS

$scenes = [];

if ($url_podcast_id > 0) {

    $scenes_query = mysqli_query($conn, "SELECT *, hashtags FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id ORDER BY id");

    if ($scenes_query) {

        while ($row = mysqli_fetch_assoc($scenes_query)) {

            $scenes[] = $row;

        }

    }

}

// ---------- AJAX: Upload Image ----------





// Get all audio files for this podcast

$audio_files = [];

if ($url_podcast_id > 0) {

    $audio_query = mysqli_query($conn, "SELECT id, audio_file FROM hdb_podcast_stories WHERE podcast_id = $url_podcast_id AND audio_file IS NOT NULL AND audio_file != ''");

    if ($audio_query) {

        while ($row = mysqli_fetch_assoc($audio_query)) {

            $audio_files[$row['id']] = $row['audio_file'];

        }

    } 

}



// ---------- AJAX: Upload Podcast Music ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_podcast_music') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $podcast_id = (int)$_POST['podcast_id'];

    $response = ['success' => false, 'message' => ''];

    

    // Check if file was uploaded

    if (!isset($_FILES['music_file']) || $_FILES['music_file']['error'] !== UPLOAD_ERR_OK) {

        $error_msg = isset($_FILES['music_file']) ? $_FILES['music_file']['error'] : 'No file uploaded';

        $response['message'] = 'Upload error: ' . $error_msg;

        echo json_encode($response);

        exit;

    }

    

    $file = $_FILES['music_file'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    

    // Validate file type (only MP3 for music)

    if ($ext !== 'mp3') {

        $response['message'] = 'Only MP3 files are allowed for background music';

        echo json_encode($response);

        exit;

    }

    

    // Validate file size (max 20MB for music)

    if ($file['size'] > 20 * 1024 * 1024) {

        $response['message'] = 'File too large. Max 20MB';

        echo json_encode($response);

        exit;

    }

    

    // Use podcast_music folder

    $music_dir = __DIR__ . '/podcast_music/';

    if (!is_dir($music_dir)) {

        mkdir($music_dir, 0777, true);

    }

    

    // Generate unique filename

    $filename = 'music_' . $podcast_id . '_' . time() . '.mp3';

    $destination = $music_dir . $filename;

    

    if (move_uploaded_file($file['tmp_name'], $destination)) {

        // Update the podcast with the music file

        $update_sql = "UPDATE hdb_podcasts SET music_file='$filename' WHERE id=$podcast_id";

        if (mysqli_query($conn, $update_sql)) {

            $response['success'] = true;

            $response['message'] = 'Music uploaded successfully';

            $response['filename'] = $filename;

            $response['file_url'] = 'podcast_music/' . $filename;

        } else {

            $response['message'] = 'Database update failed: ' . mysqli_error($conn);

        }

    } else {

        $response['message'] = 'Failed to move uploaded file';

    }

    

    echo json_encode($response);

    exit;

}



// ---------- AJAX: Search Media by NL Tags ----------

// ---------- AJAX: Search Media by NL Tags ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'search_media_nl') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    ini_set('memory_limit', '256M');

    set_time_limit(60);

    

    $nl_query          = trim($_POST['query']             ?? '');

    $media_type_filter = trim($_POST['media_type_filter'] ?? '');

    $podcast_id        = (int)($_POST['podcast_id']       ?? 0);

    

    if (empty($nl_query)) { echo json_encode([]); exit; }

    

    // Get API key

    $apiKey_vm = '';

    if (isset($apiKey) && !empty($apiKey)) {

        $apiKey_vm = $apiKey;

    }

    

    // Media type clause

    $media_type_clause = '';

    if ($media_type_filter === 'image') {

        $media_type_clause = "AND (media_type = 'image' OR media_type IS NULL OR media_type = '')";

    } elseif ($media_type_filter === 'video') {

        $media_type_clause = "AND media_type = 'video'";

    }

    

    if (!function_exists('getEmbedding_vm')) {

        function getEmbedding_vm($text, $key) {

            if (empty($key)) return null;

            $ch = curl_init('https://api.openai.com/v1/embeddings');

            curl_setopt_array($ch, array(

                CURLOPT_RETURNTRANSFER => true,

                CURLOPT_POST           => true,

                CURLOPT_HTTPHEADER     => array(

                    'Content-Type: application/json',

                    'Authorization: Bearer ' . $key

                ),

                CURLOPT_POSTFIELDS => json_encode(array(

                    'model' => 'text-embedding-3-small',

                    'input' => $text

                )),

                CURLOPT_TIMEOUT => 15,

            ));

            $response = curl_exec($ch);

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($httpCode !== 200) return null;

            $data = json_decode($response, true);

            return isset($data['data'][0]['embedding']) ? $data['data'][0]['embedding'] : null;

        }

    }

    

    if (!function_exists('cosineSimilarity_vm')) {

        function cosineSimilarity_vm($a, $b) {

            $dot = 0.0; $normA = 0.0; $normB = 0.0;

            $len = min(count($a), count($b));

            for ($i = 0; $i < $len; $i++) {

                $dot   += $a[$i] * $b[$i];

                $normA += $a[$i] * $a[$i];

                $normB += $b[$i] * $b[$i];

            }

            if ($normA == 0 || $normB == 0) return 0.0;

            return $dot / (sqrt($normA) * sqrt($normB));

        }

    }



    // ── KEY FIX: Split NL query into segments and search each one ──

    // "hypnotherapy|public speaking|fear of public speaking" 

    // becomes 3 separate searches

    $segments = array_filter(

        array_map('trim', explode('|', $nl_query)),

        function($s) { return strlen($s) > 2; }

    );

    

    // If no pipe-separated segments, treat whole query as one segment

    if (empty($segments)) {

        $segments = array($nl_query);

    }

    

    error_log("search_media_nl: segments=" . json_encode($segments) . " filter=$media_type_filter");

    

    // Get embeddings for each segment

    $segment_vectors = array();

    foreach ($segments as $seg) {

        $vec = getEmbedding_vm($seg, $apiKey_vm);

        if ($vec) {

            $segment_vectors[$seg] = $vec;

        }

    }

    

    error_log("search_media_nl: got " . count($segment_vectors) . " embeddings out of " . count($segments));

    

    // If no embeddings — text fallback using FULL PHRASES not individual words

    if (empty($segment_vectors)) {

        error_log("search_media_nl: no embeddings — phrase text fallback");

        $conds = array();

        foreach ($segments as $seg) {

            $se = mysqli_real_escape_string($conn, $seg);

            // Match the full phrase, not individual words

            $conds[] = "natural_language_tags LIKE '%$se%'";

        }

        $where = implode(' OR ', $conds);

        $results = array();

        $fq = mysqli_query($conn,

			"SELECT * 

             FROM hdb_image_data

             WHERE ($where) $media_type_clause

             ORDER BY RAND() LIMIT 30");

        if ($fq) {

            while ($r = mysqli_fetch_assoc($fq)) {

                $nl_raw   = $r['natural_language_tags'] ?? '';

                $nl_lines = array_filter(array_map('trim', explode('|', $nl_raw)));

                // Find which segment matched

                $matched_seg = '';

                foreach ($segments as $seg) {

                    foreach ($nl_lines as $line) {

                        if (stripos($line, $seg) !== false) {

                            $matched_seg = $line;

                            break 2;

                        }

                    }

                }

				//'thumbnail' => $asset['thumbnail'] ?? '',

                $results[] = array(

                    'id'           => $r['id'],

                    'filename'     => $r['image_name'],

                    'type'         => $r['media_type'] ?? 'image',

                    'hashtags'     => $r['image_hashtags'] ?? '',

                    'nl_tags'      => $nl_raw,

                    'matched_line' => $matched_seg,

                    'score'        => 0,

					'thumbnail'    => $r['thumbnail'],

					

                );

            }

        }

        echo json_encode($results);

        exit;

    }

    

    // Fetch assets with embeddings

    $asset_res = mysqli_query($conn,

       "SELECT id, image_name, natural_language_tags, image_hashtags, media_type, embedding, thumbnail

         FROM hdb_image_data

         WHERE embedding IS NOT NULL AND embedding != ''

         $media_type_clause

         ORDER BY RAND()

         LIMIT 800");

    

    $scored = array();

    

    if ($asset_res) {

        while ($asset = mysqli_fetch_assoc($asset_res)) {

            $emb = $asset['embedding'];

            if (strlen($emb) < 100) continue;

            $asset_vector = json_decode($emb, true);

            if (!is_array($asset_vector)) continue;

            

            // ── KEY FIX: Score against EACH segment, take the BEST score ──

            // This means "therapeutic journey" matches therapy images

            // but NOT "scenic train journey" because the full phrase 

            // embedding is different

            $best_score   = 0.0;

            $best_segment = '';

            

            foreach ($segment_vectors as $seg => $seg_vector) {

                if (count($seg_vector) !== count($asset_vector)) continue;

                $score = cosineSimilarity_vm($seg_vector, $asset_vector);

                if ($score > $best_score) {

                    $best_score   = $score;

                    $best_segment = $seg;

                }

            }

            

            // Only include if best score is good enough

            // Higher threshold (0.40) to avoid false matches like journey/journey

            if ($best_score < 0.40) continue;

            

            // Find the matching NL tag line from the asset

            $nl_raw   = $asset['natural_language_tags'] ?? '';

            $nl_lines = array_filter(array_map('trim', explode('|', $nl_raw)));

            

            // Find which line best matches the winning segment

            $best_line   = '';

            $best_overlap = 0;

            $seg_words   = preg_split('/\s+/', strtolower($best_segment));

            

            foreach ($nl_lines as $line) {

                $line_words = preg_split('/\s+/', strtolower($line));

                // Count MULTI-WORD overlap — more words matching = better

                $overlap = count(array_intersect($seg_words, $line_words));

                if ($overlap > $best_overlap) {

                    $best_overlap = $overlap;

                    $best_line    = $line;

                }

            }

            

            if (empty($best_line) && !empty($nl_lines)) {

                $best_line = reset($nl_lines);

            }

            

            $scored[] = array(

				'id'              => $asset['id'],

				'filename'        => $asset['image_name'],

				'type'            => $asset['media_type'] ?? 'image',

				'hashtags'        => $asset['image_hashtags'] ?? '',

				'nl_tags'         => $nl_raw,

				'matched_line'    => $best_line,

				'matched_segment' => $best_segment,

				'score'           => round($best_score, 4),

				'thumbnail'       => $asset['thumbnail'] ?? '',

			);

        }

    }

    

    // Sort by score

    usort($scored, function($a, $b) {

        if ($b['score'] == $a['score']) return 0;

        return $b['score'] > $a['score'] ? 1 : -1;

    });

    

    $results = array_slice($scored, 0, 30);

    

    // Fallback if nothing found with high threshold

    // Try lower threshold 0.30 before giving up

    if (empty($results)) {

        error_log("search_media_nl: no results at 0.40 — retrying at 0.30");

        $asset_res2 = mysqli_query($conn,

            "SELECT id, image_name, natural_language_tags, image_hashtags, media_type, embedding

             FROM hdb_image_data

             WHERE embedding IS NOT NULL AND embedding != ''

             $media_type_clause

             ORDER BY RAND()

             LIMIT 800");

        

        $scored2 = array();

        if ($asset_res2) {

            while ($asset = mysqli_fetch_assoc($asset_res2)) {

                $emb = $asset['embedding'];

                if (strlen($emb) < 100) continue;

                $asset_vector = json_decode($emb, true);

                if (!is_array($asset_vector)) continue;

                

                $best_score   = 0.0;

                $best_segment = '';

                foreach ($segment_vectors as $seg => $seg_vector) {

                    if (count($seg_vector) !== count($asset_vector)) continue;

                    $score = cosineSimilarity_vm($seg_vector, $asset_vector);

                    if ($score > $best_score) {

                        $best_score   = $score;

                        $best_segment = $seg;

                    }

                }

                if ($best_score < 0.30) continue;

                

                $nl_raw   = $asset['natural_language_tags'] ?? '';

                $nl_lines = array_filter(array_map('trim', explode('|', $nl_raw)));

                $best_line = !empty($nl_lines) ? reset($nl_lines) : '';

                

                $scored2[] = array(

					'id'              => $asset['id'],

					'filename'        => $asset['image_name'],

					'type'            => $asset['media_type'] ?? 'image',

					'hashtags'        => $asset['image_hashtags'] ?? '',

					'nl_tags'         => $nl_raw,

					'matched_line'    => $best_line,

					'matched_segment' => $best_segment,

					'score'           => round($best_score, 4),

					'thumbnail'       => $asset['thumbnail'] ?? '',

				);

            }

        }

        usort($scored2, function($a, $b) {

            if ($b['score'] == $a['score']) return 0;

            return $b['score'] > $a['score'] ? 1 : -1;

        });

        $results = array_slice($scored2, 0, 20);

    }

    

    error_log("search_media_nl: returning " . count($results) . " results for filter=$media_type_filter");

    echo json_encode($results);

    exit;

}



// ---------- AJAX: Debug Media ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'debug_media') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');



    $debug = array();

    $debug['query_received'] = $_POST['query'] ?? 'none';

    $debug['apiKey_set']     = isset($apiKey) ? 'YES len=' . strlen($apiKey) : 'NOT SET';



    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data"));

    $debug['total'] = $r['c'];



    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data WHERE embedding IS NOT NULL AND embedding != ''"));

    $debug['with_embedding'] = $r['c'];



    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM hdb_image_data WHERE media_type='video'"));

    $debug['db_videos'] = $r['c'];



    $cols = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");

    $debug['columns'] = array();

    while ($c = mysqli_fetch_assoc($cols)) $debug['columns'][] = $c['Field'];



    $s = mysqli_query($conn, "SELECT id, image_name, media_type, LEFT(natural_language_tags,80) as nl, LEFT(image_hashtags,80) as ht FROM hdb_image_data LIMIT 2");

    $debug['samples'] = array();

    while ($row = mysqli_fetch_assoc($s)) $debug['samples'][] = $row;



    $vdir = __DIR__ . '/podcast_videos/';

    $debug['video_folder'] = is_dir($vdir) ? 'EXISTS' : 'MISSING';

    if (is_dir($vdir)) {

        $vf = array_filter(scandir($vdir), function($f) { 

            return preg_match('/\.(mp4|webm|mov)$/i', $f); 

        });

        $debug['videos_on_disk'] = count($vf);

        $debug['video_samples']  = array_slice(array_values($vf), 0, 3);

    }



    echo json_encode($debug, JSON_PRETTY_PRINT);

    exit;

}

// Create new caption

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'create_caption') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    // Log the request for debugging

    error_log("create_caption called with: " . print_r($_POST, true));

    

    $story_id = (int)$_POST['story_id'];

    $podcast_id = (int)$_POST['podcast_id']; // ADD THIS LINE - get podcast_id from POST

    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']); // 'text' or 'image'

    $caption_name = mysqli_real_escape_string($conn, $_POST['caption_name'] ?? '');

    

    // If caption_name is empty, generate one with datetime

    if (empty($caption_name)) {

        $caption_name = $caption_type . '_' . date('Ymd_His');

    }

    

    // Validate caption_type - only allow 'text' or 'image'

    if (!in_array($caption_type, ['text', 'image'])) {

        $caption_type = 'text'; // Default to text if invalid

    }

    $media_type = ($caption_type === 'image') ? 'image' : $caption_type;

    

    // Load defaults from hdb_user_settings for this admin

    $us = null;

    $us_query = mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id = $admin_id LIMIT 1");

    if ($us_query && mysqli_num_rows($us_query) > 0) {

        $us = mysqli_fetch_assoc($us_query);

    }



    $default_fontfamily    = !empty($us['fontfamily'])    ? mysqli_real_escape_string($conn, $us['fontfamily'])    : 'Arial';

    $default_fontsize      = !empty($us['fontsize'])      ? (int)$us['fontsize']                                   : 30;

    $default_fontcolor     = !empty($us['fontcolor'])     ? mysqli_real_escape_string($conn, $us['fontcolor'])     : '#ffff00';

    $default_fontcolor_bg  = !empty($us['fontcolor_bg'])  ? mysqli_real_escape_string($conn, $us['fontcolor_bg'])  : '#000000';

    $default_fontweight    = !empty($us['fontweight'])    ? mysqli_real_escape_string($conn, $us['fontweight'])    : 'bold';

    $default_fontbg_enable = isset($us['fontbg_enable'])  ? (int)$us['fontbg_enable']                             : 0;

    $default_caption_style = !empty($us['caption_style']) ? mysqli_real_escape_string($conn, $us['caption_style']) : 'none';

    $default_caption_speed = !empty($us['caption_speed']) ? (float)$us['caption_speed']                           : 1.0;

    $default_text_align    = !empty($us['caption_alignment']) ? mysqli_real_escape_string($conn, $us['caption_alignment']) : 'center';

    $default_position_x    = !empty($us['position_x'])        ? (int)$us['position_x']                                     : 50;

    $default_position_y    = !empty($us['position_y'])        ? (int)$us['position_y']                                     : 200;

    $default_width         = !empty($us['width'])              ? (int)$us['width']                                          : 500;

    // caption_position is always 'center' regardless of user setting



    // Set default text based on caption type

    $default_text = ($caption_type === 'text') ? 'Enter new text here' : '';

    

    // UPDATED SQL to include podcast_id

    $sql = "INSERT INTO hdb_captions 

            (story_id, podcast_id, caption_type, caption_name, text_content, media_type, fontfamily, fontsize, fontcolor, 

             fontweight, fontstyle, text_align, bg_color, bg_enabled, position_x, position_y, width, 

             animation_style, animation_speed, is_visible, z_index)

            VALUES 

            ($story_id, $podcast_id, '$caption_type', '$caption_name', '$default_text', '$caption_type',

             '$default_fontfamily', $default_fontsize, '$default_fontcolor',

             '$default_fontweight', 'normal', '$default_text_align', '$default_fontcolor_bg', $default_fontbg_enable,

             $default_position_x, $default_position_y, $default_width,

             '$default_caption_style', $default_caption_speed, 1, 1)";

    

    error_log("Create caption SQL: " . $sql);

    

    if (mysqli_query($conn, $sql)) {

        $new_id = mysqli_insert_id($conn);

        error_log("Caption created with ID: " . $new_id);

        

        // Get the newly created caption to return

        $new_caption_query = mysqli_query($conn, "SELECT * FROM hdb_captions WHERE id = $new_id");

        $new_caption = mysqli_fetch_assoc($new_caption_query);

        

        echo json_encode([

            'success' => true, 

            'caption_id' => $new_id,

            'caption_type' => $caption_type,

            'caption_name' => $caption_name,

            'caption_data' => $new_caption,

            'message' => 'Caption created successfully'

        ]);

    } else {

        error_log("Caption creation failed: " . mysqli_error($conn));

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// ---------- AJAX: Save Caption Global Status ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_caption_global') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $caption_id = (int)$_POST['caption_id'];

    $is_global = (int)$_POST['is_global'];

    

    $sql = "UPDATE hdb_captions SET is_global = $is_global WHERE id = $caption_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}



// ---------- AJAX: Delete Caption By Type ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_caption_by_type') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $story_id = (int)$_POST['story_id'];

    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']);

    

    $sql = "DELETE FROM hdb_captions WHERE story_id = $story_id AND caption_type = '$caption_type'";

    

    if (mysqli_query($conn, $sql)) {

        $deleted = mysqli_affected_rows($conn);

        echo json_encode(['success' => true, 'deleted' => $deleted]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// ---------- AJAX: Save All Scene Settings ----------

// In your save_scene_settings AJAX handler, add text_contents to the fields

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_scene_settings') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $scene_id = (int)$_POST['scene_id'];

    

    // Sanitize and prepare all fields

    $fontfamily = mysqli_real_escape_string($conn, $_POST['fontfamily'] ?? 'Arial');

    $fontsize = (int)($_POST['fontsize'] ?? 16);

    $fontcolor = mysqli_real_escape_string($conn, $_POST['fontcolor'] ?? '#ffffff');

    $fontweight = mysqli_real_escape_string($conn, $_POST['fontweight'] ?? 'normal');

    $fontcolor_bg = mysqli_real_escape_string($conn, $_POST['fontcolor_bg'] ?? '#000000');

    $fontbg_enable = (int)($_POST['fontbg_enable'] ?? 1);

    $caption_style = mysqli_real_escape_string($conn, $_POST['caption_style'] ?? 'typewriter');

    $caption_position = mysqli_real_escape_string($conn, $_POST['caption_position'] ?? 'bottom');

    $caption_alignment = mysqli_real_escape_string($conn, $_POST['caption_alignment'] ?? 'center');

    $caption_speed = (float)($_POST['caption_speed'] ?? 0.85);

    $logo_name = mysqli_real_escape_string($conn, $_POST['logo_name'] ?? '');

    $logo_size = mysqli_real_escape_string($conn, $_POST['logo_size'] ?? '60');

    $logo_position = mysqli_real_escape_string($conn, $_POST['logo_position'] ?? 'top-right');

    $logo_enabled = (int)($_POST['logo_enabled'] ?? 0);

    $audio_file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');

    $audio_volume = (int)($_POST['audio_volume'] ?? 100);

    $voice_id = mysqli_real_escape_string($conn, $_POST['voice_id'] ?? '');

    $text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? ''); // ADD THIS LINE

    

    // Build the SQL query

    $sql = "UPDATE hdb_podcast_stories SET 

            fontfamily = '$fontfamily',

            fontsize = $fontsize,

            fontcolor = '$fontcolor',

            fontweight = '$fontweight',

            fontcolor_bg = '$fontcolor_bg',

            fontbg_enable = $fontbg_enable,

            caption_style = '$caption_style',

            caption_position = '$caption_position',

            caption_alignment = '$caption_alignment',

            caption_speed = $caption_speed,

            logo_name = '$logo_name',

            logo_size = '$logo_size',

            logo_position = '$logo_position',

            logo_enabled = $logo_enabled,

            audio_file = '$audio_file',

            audio_volume = $audio_volume";

            

    

    // Add text_contents to update if provided

    if (!empty($text_contents)) {

        $sql .= ", text_contents = '$text_contents'";

    }

    

    $sql .= " WHERE id = $scene_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}							



// Get all captions for a scene

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_scene_captions') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    // JS sends 'story_id', some callers send 'scene_id' — accept both

    $story_id = (int)($_POST['story_id'] ?? $_POST['scene_id'] ?? 0);

    $captions = [];

    if ($story_id > 0) {

        $result = mysqli_query($conn, "SELECT * FROM hdb_captions WHERE story_id = $story_id");

        if ($result) while ($row = mysqli_fetch_assoc($result)) $captions[] = $row;

    }

    echo json_encode(['success' => true, 'captions' => $captions]);

    exit;

}

// ---------- AJAX: Update Main Caption Styling Only (NO TEXT) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_main_caption_styling') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $caption_id = (int)$_POST['caption_id'];

    

    // ALLOWED FIELDS FOR MAIN CAPTION - NO text_content!

    $allowed_fields = [

        'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 'fontstyle',

        'underline', 'linethrough', 'text_align', 'bg_color', 'bg_enabled',

        'outline_color', 'outline_width', 'outline_enabled',

        'stroke_color', 'stroke_width', 'stroke_enabled',

        'position_x', 'position_y', 'width', 'rotation',

        'animation_style', 'animation_speed', 'is_visible', 'z_index'

    ];

    

    $updates = [];

    foreach ($allowed_fields as $field) {

        if (isset($_POST[$field])) {

            $value = $_POST[$field];

            if ($value === '') {

                $value = 'NULL';

            } else {

                $value = "'" . mysqli_real_escape_string($conn, $value) . "'";

            }

            $updates[] = "$field = $value";

        }

    }

    

    if (empty($updates)) {

        echo json_encode(['success' => false, 'message' => 'No fields to update']);

        exit;

    }

    

    $sql = "UPDATE hdb_captions SET " . implode(', ', $updates) . " WHERE id = $caption_id";

    

    error_log("Main caption styling update: $sql");

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// Save a caption

// Update caption by ID

// Save a caption

// Update caption by ID

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_caption') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $caption_id = (int)$_POST['caption_id'];

    

    $allowed_fields = [

        'text_content', 'fontfamily', 'fontsize', 'fontcolor', 'fontweight', 'fontstyle',

        'underline', 'linethrough', 'text_align', 'bg_color', 'bg_enabled',

        'outline_color', 'outline_width', 'outline_enabled',

        'stroke_color', 'stroke_width', 'stroke_enabled',

        'position_x', 'position_y', 'width', 'rotation',

        'animation_style', 'animation_speed', 'is_visible', 'z_index',

        'image_file', 'media_type'  // MAKE SURE THESE ARE INCLUDED

    ];

    

    $updates = [];

    foreach ($allowed_fields as $field) {

        if (isset($_POST[$field])) {

            $value = $_POST[$field];

            if ($value === '') {

                $value = 'NULL';

            } else {

                $value = "'" . mysqli_real_escape_string($conn, $value) . "'";

            }

            $updates[] = "$field = $value";

        }

    }

    

    if (empty($updates)) {

        echo json_encode(['success' => false, 'message' => 'No fields to update']);

        exit;

    }

    

    $sql = "UPDATE hdb_captions SET " . implode(', ', $updates) . " WHERE id = $caption_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}



if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $response = ['success' => false, 'message' => ''];

    

    try {

        // Check if file was uploaded

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {

            throw new Exception('No file uploaded or upload error');

        }

        

        $file = $_FILES['image'];

        

        // Validate file type

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($file['type'], $allowed_types)) {

            throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WEBP');

        }

        

        // Validate file size (max 10MB)

        if ($file['size'] > 10 * 1024 * 1024) {

            throw new Exception('File too large. Max 10MB');

        }

        

        // Generate unique filename

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);

        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

        $image_name = $image_name_base . '.' . $extension;

        

        $upload_dir = __DIR__ . '/podcast_images/';

        

        // Create directory if it doesn't exist

        if (!is_dir($upload_dir)) {

            mkdir($upload_dir, 0755, true);

        }

        

        $destination = $upload_dir . $image_name;

        

        // Move uploaded file

        if (!move_uploaded_file($file['tmp_name'], $destination)) {

            throw new Exception('Failed to save uploaded file');

        }

        

        // Insert into database

        $esc_name = mysqli_real_escape_string($conn, $image_name);

        $esc_prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');

        

        // Check table structure

        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");

        $table_cols = [];

        while ($c = mysqli_fetch_assoc($col_check)) {

            $table_cols[] = $c['Field'];

        }

        

        $insert_map = [];

        if (in_array('image_name', $table_cols)) {

            $insert_map['image_name'] = "'$esc_name'";

        }

        if (in_array('created_at', $table_cols)) {

            $insert_map['created_at'] = "NOW()";

        }

        if (in_array('image_prompt', $table_cols) && !empty($esc_prompt)) {

            $insert_map['image_prompt'] = "'$esc_prompt'";

        }

        

        if (!empty($insert_map)) {

            $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";

            mysqli_query($conn, $ins_sql);

        }

        

        $response['success'] = true;

        $response['image_name'] = $image_name;

        $response['message'] = 'File uploaded successfully';

        

    } catch (Exception $e) {

        $response['message'] = $e->getMessage();

    }

    

    echo json_encode($response);

    exit;

}

// ---------- AJAX: Upload Scene Image (from imageSelectorPanel Upload button) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_scene_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];

    try {

        $scene_id    = (int)$_POST['scene_id'];

        $image_field = mysqli_real_escape_string($conn, $_POST['image_field'] ?? 'image_file');

        $media_type  = ($_POST['media_type'] ?? 'image') === 'video' ? 'video' : 'image';



        if (!isset($_FILES['scene_image']) || $_FILES['scene_image']['error'] !== UPLOAD_ERR_OK) {

            throw new Exception('No file uploaded or upload error: ' . ($_FILES['scene_image']['error'] ?? 'missing'));

        }

        $file = $_FILES['scene_image'];

        if ($file['size'] > 50 * 1024 * 1024) throw new Exception('File too large. Max 50MB');



        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $filename = 'scene_' . $scene_id . '_' . time() . '_' . mt_rand(1000,9999) . '.' . $ext;



        if ($media_type === 'video') {

            $upload_dir = __DIR__ . '/podcast_videos/';

            $db_field   = 'video_file';

        } else {

            $upload_dir = __DIR__ . '/podcast_images/';

            $db_field   = $image_field;

        }

        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);



        if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {

            throw new Exception('Failed to save file to server');

        }



        $esc_filename = mysqli_real_escape_string($conn, $filename);

        $sql = "UPDATE hdb_podcast_stories SET `$db_field` = '$esc_filename' WHERE id = $scene_id";

        if (!mysqli_query($conn, $sql)) throw new Exception('DB error: ' . mysqli_error($conn));



        $response['success']  = true;

        $response['filename'] = $filename;

        $response['media_type'] = $media_type;

        $response['message']  = 'Uploaded successfully';

    } catch (Exception $e) {

        $response['message'] = $e->getMessage();

    }

    echo json_encode($response);

    exit;

}

// ---------- AJAX: Get Library Files ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_library_files') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $files = [];

    // Try hdb_image_data table first

    $q = mysqli_query($conn, "SELECT image_name as filename, image_hashtags as tags FROM hdb_image_data ORDER BY id DESC LIMIT 500");

    if ($q && mysqli_num_rows($q) > 0) {

        while ($r = mysqli_fetch_assoc($q)) $files[] = $r;

    } else {

        // Fallback: scan podcast_images folder

        $dir = __DIR__ . '/podcast_images/';

        if (is_dir($dir)) {

            foreach (scandir($dir) as $f) {

                if ($f === '.' || $f === '..') continue;

                if (preg_match('/\.(jpg|jpeg|png|gif|webp|mp4|webm|mov)$/i', $f)) {

                    $files[] = ['filename' => $f, 'tags' => ''];

                }

            }

        }

    }

    echo json_encode(['success' => true, 'files' => $files]);

    exit;

}



// ---------- AJAX: Assign Image (pick from library) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'assign_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $scene_id    = (int)$_POST['scene_id'];

    $filename    = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');

    $image_field = mysqli_real_escape_string($conn, $_POST['image_field'] ?? 'image_file');

    if (!$scene_id || !$filename) {

        echo json_encode(['success'=>false,'message'=>'Missing scene_id or filename']); exit;

    }

    $ok = mysqli_query($conn, "UPDATE hdb_podcast_stories SET `$image_field`='$filename' WHERE id=$scene_id");

    echo json_encode(['success'=>(bool)$ok, 'filename'=>$filename]);

    exit;

}



if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_scene_text') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $scene_id = (int)$_POST['scene_id'];

    $text_contents = mysqli_real_escape_string($conn, $_POST['text_contents'] ?? '');

    

    $sql = "UPDATE hdb_podcast_stories SET text_contents = '$text_contents' WHERE id = $scene_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// ---------- AJAX: Get Translate Languages ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_translate_languages') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $exclude = mysqli_real_escape_string($conn, $_POST['exclude_lang'] ?? '');

    

    // Check if hdb_languages table exists

    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_languages'");

    if (mysqli_num_rows($table_check) == 0) {

        // Table doesn't exist - return default languages

        $default_languages = [

            ['lang_code' => 'ur', 'lang_name' => 'Urdu'],

            ['lang_code' => 'ar', 'lang_name' => 'Arabic'],

            ['lang_code' => 'hi', 'lang_name' => 'Hindi'],

            ['lang_code' => 'fr', 'lang_name' => 'French'],

            ['lang_code' => 'es', 'lang_name' => 'Spanish'],

            ['lang_code' => 'de', 'lang_name' => 'German'],

            ['lang_code' => 'zh', 'lang_name' => 'Chinese'],

            ['lang_code' => 'ja', 'lang_name' => 'Japanese'],

            ['lang_code' => 'ko', 'lang_name' => 'Korean'],

            ['lang_code' => 'ru', 'lang_name' => 'Russian'],

            ['lang_code' => 'pt', 'lang_name' => 'Portuguese'],

            ['lang_code' => 'it', 'lang_name' => 'Italian'],

            ['lang_code' => 'nl', 'lang_name' => 'Dutch'],

            ['lang_code' => 'tr', 'lang_name' => 'Turkish'],

            ['lang_code' => 'pl', 'lang_name' => 'Polish']

        ];

        

        // Filter out excluded language

        if ($exclude) {

            $default_languages = array_filter($default_languages, function($lang) use ($exclude) {

                return $lang['lang_code'] !== $exclude;

            });

            $default_languages = array_values($default_languages);

        }

        

        echo json_encode([

            'success' => true,

            'languages' => $default_languages

        ]);

        exit;

    }

    

    $sql = "SELECT lang_code, lang_name FROM hdb_languages WHERE is_active = 1";

    if ($exclude) {

        $sql .= " AND lang_code != '$exclude'";

    }

    $sql .= " ORDER BY lang_name";

    

    $result = mysqli_query($conn, $sql);

    $languages = [];

    

    if ($result && mysqli_num_rows($result) > 0) {

        while ($row = mysqli_fetch_assoc($result)) {

            $languages[] = $row;

        }

    } else {

        // Fallback languages if table is empty

        $languages = [

            ['lang_code' => 'ur', 'lang_name' => 'Urdu'],

            ['lang_code' => 'ar', 'lang_name' => 'Arabic'],

            ['lang_code' => 'hi', 'lang_name' => 'Hindi'],

            ['lang_code' => 'fr', 'lang_name' => 'French'],

            ['lang_code' => 'es', 'lang_name' => 'Spanish'],

            ['lang_code' => 'de', 'lang_name' => 'German'],

            ['lang_code' => 'zh', 'lang_name' => 'Chinese']

        ];

        

        // Filter out excluded language

        if ($exclude) {

            $languages = array_filter($languages, function($lang) use ($exclude) {

                return $lang['lang_code'] !== $exclude;

            });

            $languages = array_values($languages);

        }

    }

    

    echo json_encode([

        'success' => true,

        'languages' => $languages

    ]);

    exit;

}



// ---------- AJAX: Save Rendered Video ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_rendered_video') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $response = ['success' => false, 'message' => ''];

    

    try {

        $podcast_id = (int)($_POST['podcast_id'] ?? 0);

        $lang_code  = preg_replace('/[^a-z]/', '', $_POST['lang_code'] ?? 'en');

        $filename   = 'video_' . $podcast_id . '_' . $lang_code . '.webm';



        if ($podcast_id === 0) {

            throw new Exception('Missing podcast_id');

        }



        // Check binary file upload

        if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {

            $err = $_FILES['video_file']['error'] ?? 'no file';

            throw new Exception('File upload error: ' . $err);

        }



        // Create published_videos directory if needed

        $video_dir = __DIR__ . '/published_videos/';

        if (!is_dir($video_dir)) mkdir($video_dir, 0777, true);



        $filepath = $video_dir . $filename;



        // Delete any existing video for this podcast+lang

        if (file_exists($filepath)) {

            unlink($filepath);

            error_log("Deleted previous render: $filename");

        }



        if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $filepath)) {

            throw new Exception('Failed to save video file');

        }



        $filesize = filesize($filepath);

        $response['success']  = true;

        $response['message']  = 'Video saved successfully';

        $response['filename'] = $filename;

        $response['filepath'] = 'published_videos/' . $filename;

        $response['filesize'] = $filesize;

        error_log("Video saved: $filename ($filesize bytes)");



    } catch (Exception $e) {

        $response['message'] = $e->getMessage();

        error_log("Video save error: " . $e->getMessage());

    }

    

    echo json_encode($response);

    exit;

}



// ---------- AJAX: Delete Previous Render ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_previous_render') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $filename = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');

    $response = ['success' => false, 'deleted' => false];

    

    if ($filename) {

        $video_dir = __DIR__ . '/published_videos/';

        $filepath = $video_dir . $filename;

        

        if (file_exists($filepath)) {

            if (unlink($filepath)) {

                $response['success'] = true;

                $response['deleted'] = true;

                error_log("Deleted previous render: $filename");

            } else {

                error_log("Failed to delete: $filename");

            }

        } else {

            $response['success'] = true; // File doesn't exist, consider it success

            $response['deleted'] = false;

        }

    }

    

    echo json_encode($response);

    exit;

}









// ---------- AJAX: Delete Video File ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_video_file') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $filename = mysqli_real_escape_string($conn, $_POST['filename'] ?? '');

    $response = ['success' => false];

    

    if ($filename) {

        $video_dir = __DIR__ . '/podcast_videos/';

        $filepath = $video_dir . $filename;

        

        if (file_exists($filepath)) {

            if (unlink($filepath)) {

                $response['success'] = true;

                error_log("Deleted previous render file: $filename");

            } else {

                error_log("Failed to delete: $filename");

            }

        } else {

            $response['success'] = true; // File doesn't exist, consider it success

        }

    }

    

    echo json_encode($response);

    exit;

}



// ---------- AJAX: Delete Caption ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_caption') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $caption_id = (int)$_POST['caption_id'];

    

    $sql = "DELETE FROM hdb_captions WHERE id = $caption_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// ---------- AJAX: Get Voices by Language ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_voices_by_language') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $lang_code = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? '');

    

    if (!$lang_code) {

        echo json_encode(['success' => false, 'message' => 'No language code provided']);

        exit;

    }

    

    // Check if hdb_voices table exists

    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_voices'");

    if (mysqli_num_rows($table_check) == 0) {

        // Return default voices based on language

        $default_voices = getDefaultVoices($lang_code);

        echo json_encode([

            'success' => true,

            'voices' => $default_voices

        ]);

        exit;

    }

    

    $sql = "SELECT voice_key, voice_name, voice_description, sample_voice 

            FROM hdb_voices 

            WHERE lang_code = '$lang_code' 

            ORDER BY voice_name";

    

    $result = mysqli_query($conn, $sql);

    $voices = [];

    

    if ($result && mysqli_num_rows($result) > 0) {

        while ($row = mysqli_fetch_assoc($result)) {

            $voices[] = $row;

        }

    } else {

        // Return default voices if none found

        $voices = getDefaultVoices($lang_code);

    }

    

    echo json_encode([

        'success' => true,

        'voices' => $voices

    ]);

    exit;

}



// ---------- AJAX: Check if Language Exists ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_language_exists') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');

    $lang_code = mysqli_real_escape_string($conn, $_POST['lang_code'] ?? '');

    

    $sql = "SELECT id FROM hdb_podcasts 

            WHERE title = '$title' 

            AND lang_code = '$lang_code' 

            AND client_id = '$client_id'";

    

    $result = mysqli_query($conn, $sql);

    

    echo json_encode([

        'success' => true,

        'exists' => ($result && mysqli_num_rows($result) > 0)

    ]);

    exit;

}



// ---------- AJAX: Get Podcast Scenes ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_podcast_scenes') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $podcast_id = (int)$_POST['podcast_id'];

    

    $sql = "SELECT id, text_contents FROM hdb_podcast_stories WHERE podcast_id = $podcast_id ORDER BY id";

    $result = mysqli_query($conn, $sql);

    $scenes = [];

    

    if ($result) {

        while ($row = mysqli_fetch_assoc($result)) {

            $scenes[] = $row;

        }

    }

    

    echo json_encode([

        'success' => true,

        'scenes' => $scenes

    ]);

    exit;

}



// Helper function to get default voices

function getDefaultVoices($lang_code) {

    $default_voices = [

        'ur' => [

            ['voice_key' => 'ur-PK-AsadNeural', 'voice_name' => 'Asad', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'ur-PK-UzmaNeural', 'voice_name' => 'Uzma', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'ar' => [

            ['voice_key' => 'ar-SA-HamedNeural', 'voice_name' => 'Hamed', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'ar-SA-ZariyahNeural', 'voice_name' => 'Zariyah', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'hi' => [

            ['voice_key' => 'hi-IN-MadhurNeural', 'voice_name' => 'Madhur', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'hi-IN-SwaraNeural', 'voice_name' => 'Swara', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'fr' => [

            ['voice_key' => 'fr-FR-HenriNeural', 'voice_name' => 'Henri', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'fr-FR-DeniseNeural', 'voice_name' => 'Denise', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'es' => [

            ['voice_key' => 'es-ES-AlvaroNeural', 'voice_name' => 'Alvaro', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'es-ES-ElviraNeural', 'voice_name' => 'Elvira', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'de' => [

            ['voice_key' => 'de-DE-ConradNeural', 'voice_name' => 'Conrad', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'de-DE-KatjaNeural', 'voice_name' => 'Katja', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'zh' => [

            ['voice_key' => 'zh-CN-YunxiNeural', 'voice_name' => 'Yunxi', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'zh-CN-XiaoxiaoNeural', 'voice_name' => 'Xiaoxiao', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'ja' => [

            ['voice_key' => 'ja-JP-KeitaNeural', 'voice_name' => 'Keita', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'ja-JP-NanamiNeural', 'voice_name' => 'Nanami', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'ko' => [

            ['voice_key' => 'ko-KR-InJoonNeural', 'voice_name' => 'InJoon', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'ko-KR-SunHiNeural', 'voice_name' => 'SunHi', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ],

        'ru' => [

            ['voice_key' => 'ru-RU-DmitryNeural', 'voice_name' => 'Dmitry', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

            ['voice_key' => 'ru-RU-DariyaNeural', 'voice_name' => 'Dariya', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

        ]

    ];

    

    return $default_voices[$lang_code] ?? [

        ['voice_key' => 'en-US-GuyNeural', 'voice_name' => 'Guy', 'voice_description' => 'Male, Natural', 'sample_voice' => ''],

        ['voice_key' => 'en-US-JennyNeural', 'voice_name' => 'Jenny', 'voice_description' => 'Female, Natural', 'sample_voice' => '']

    ];

}





// ---------- AJAX: Get Music Library ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_music_library') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $music_dir = __DIR__ . '/podcast_music/';

    $music_files = [];

    

    if (is_dir($music_dir)) {

        $files = scandir($music_dir);

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') continue;

            

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if ($ext === 'mp3') {

                $filepath = $music_dir . $file;

                $fsize = filesize($filepath);

                

                // Try to extract ID3 tags

                $title = pathinfo($file, PATHINFO_FILENAME);

                $artist = 'Unknown';

                $duration = 0;

                

                // Use getID3 if available (recommended)

                if (file_exists(__DIR__ . '/getID3/getid3.php')) {

                    require_once __DIR__ . '/getID3/getid3.php';

                    $getID3 = new getID3;

                    $fileInfo = $getID3->analyze($filepath);

                    

                    if (isset($fileInfo['tags']['id3v2']['title'][0])) {

                        $title = $fileInfo['tags']['id3v2']['title'][0];

                    }

                    if (isset($fileInfo['tags']['id3v2']['artist'][0])) {

                        $artist = $fileInfo['tags']['id3v2']['artist'][0];

                    }

                    if (isset($fileInfo['playtime_seconds'])) {

                        $duration = $fileInfo['playtime_seconds'];

                    }

                } else {

                    // Fallback: try to get duration using ffprobe if available

                    $ffprobe_path = 'ffprobe'; // or full path to ffprobe

                    $cmd = "$ffprobe_path -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filepath) . " 2>&1";

                    $output = shell_exec($cmd);

                    if ($output && is_numeric(trim($output))) {

                        $duration = floatval(trim($output));

                    }

                }

                

                $music_files[] = [

                    'filename' => $file,

                    'title' => $title,

                    'artist' => $artist,

                    'duration' => $duration,

                    'filesize' => $fsize,

                    'file_url' => 'podcast_music/' . $file

                ];

            }

        }

        

        // Sort by filename

        usort($music_files, function($a, $b) {

            return strcmp($a['filename'], $b['filename']);

        });

    }

    

    echo json_encode([

        'success' => true,

        'music_files' => $music_files

    ]);

    exit;

}

// ---------- AJAX: Check Caption Type Exists ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_caption_type_exists') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $story_id = (int)$_POST['story_id'];

    $caption_type = mysqli_real_escape_string($conn, $_POST['caption_type']);

    

    $check = mysqli_query($conn, "SELECT id FROM hdb_captions WHERE story_id = $story_id AND caption_type = '$caption_type'");

    

    echo json_encode([

        'success' => true,

        'exists' => (mysqli_num_rows($check) > 0)

    ]);

    exit;

}

// ---------- AJAX: Update Podcast Music ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_podcast_music') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $podcast_id = (int)$_POST['podcast_id'];

    $music_file = mysqli_real_escape_string($conn, $_POST['music_file'] ?? '');

    

    $sql = "UPDATE hdb_podcasts SET music_file='$music_file' WHERE id=$podcast_id";

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}

// ---------- AJAX: Get Media Library ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_media_library') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $log_file = __DIR__ . '/a_inam_debug.log';

    error_log(date('Y-m-d H:i:s') . " - GET_MEDIA_LIBRARY STARTED\n", 3, $log_file);

    

    $image_dir = __DIR__ . '/podcast_images/';

    $images = [];

    

    // Check if directory exists

    if (!is_dir($image_dir)) {

        error_log(date('Y-m-d H:i:s') . " - ERROR: Directory not found: $image_dir\n", 3, $log_file);

        echo json_encode(['error' => 'Image directory not found']);

        exit;

    }

    

    // Get image data from database

    $db_images = [];

    $r = mysqli_query($conn, "SELECT image_name, image_hashtags FROM hdb_image_data ORDER BY id DESC");

    

    if (!$r) {

        error_log(date('Y-m-d H:i:s') . " - DB Query failed: " . mysqli_error($conn) . "\n", 3, $log_file);

    } else {

        $db_count = mysqli_num_rows($r);

        error_log(date('Y-m-d H:i:s') . " - DB query returned $db_count rows\n", 3, $log_file);

        

        while ($row = mysqli_fetch_assoc($r)) {

            $db_images[$row['image_name']] = $row['image_hashtags'] ?? '';

        }

        

        error_log(date('Y-m-d H:i:s') . " - DB images loaded: " . count($db_images) . "\n", 3, $log_file);

        

        // Log first 5 DB entries as sample

        $sample = array_slice($db_images, 0, 5, true);

        foreach ($sample as $name => $tags) {

            error_log(date('Y-m-d H:i:s') . " - DB Sample: $name -> '$tags'\n", 3, $log_file);

        }

    }

    

    // Scan directory

    $files = scandir($image_dir);

    error_log(date('Y-m-d H:i:s') . " - Directory scanned, found " . count($files) . " files\n", 3, $log_file);

    

    $image_count = 0;

    foreach ($files as $file) {

        if ($file === '.' || $file === '..') continue;

        

        // Check if it's an image file

        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {

            continue;

        }

        

        $image_count++;

        $filepath = $image_dir . $file;

        $file_size = file_exists($filepath) ? filesize($filepath) : 0;

        

        $hashtags = $db_images[$file] ?? '';

        

        // Log first 5 images with their hashtags

        if ($image_count <= 5) {

            error_log(date('Y-m-d H:i:s') . " - Image $image_count: $file - hashtags: '$hashtags'\n", 3, $log_file);

        }

        

        $img_data = [

            'image_name' => $file,

            'hashtags' => $hashtags,

            'file_size' => $file_size

        ];

        

        $images[] = $img_data;

    }

    

    error_log(date('Y-m-d H:i:s') . " - Total images found: $image_count\n", 3, $log_file);

    error_log(date('Y-m-d H:i:s') . " - Returning " . count($images) . " images in JSON\n", 3, $log_file);

    

    // Log the first few images being returned

    if (count($images) > 0) {

        $json_sample = array_slice($images, 0, 3);

        error_log(date('Y-m-d H:i:s') . " - Sample JSON data: " . json_encode($json_sample) . "\n", 3, $log_file);

    }

    

    echo json_encode($images);

    exit;

}



// ---------- AJAX: Save scene audio filename after generate_voice.php ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_scene_audio_file') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $scene_id   = (int)$_POST['scene_id'];

    $audio_file = mysqli_real_escape_string($conn, $_POST['audio_file'] ?? '');

    if ($scene_id && $audio_file) {

        mysqli_query($conn, "UPDATE hdb_podcast_stories SET audio_file='$audio_file' WHERE id=$scene_id");

    }

    echo json_encode(['success' => true]);

    exit;

}



// ---------- AJAX: Generate Scene Audio (TTS) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_scene_audio') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $scene_id   = (int)$_POST['scene_id'];

    $text       = trim($_POST['text'] ?? '');

    $voice_id   = trim($_POST['voice_id'] ?? '');

    $lang_code  = preg_replace('/[^a-z]/', '', strtolower($_POST['lang_code'] ?? 'en'));

    $rate       = floatval($_POST['rate'] ?? 1.0);

    $podcast_id = (int)$_POST['podcast_id'];



    if (!$scene_id || !$text || !$voice_id) {

        echo json_encode(['success'=>false,'message'=>'Missing scene_id, text, or voice_id']); exit;

    }



    $audio_dir = __DIR__ . '/podcast_audios/';

    if (!is_dir($audio_dir)) mkdir($audio_dir, 0777, true);

    $filename = 'voice_' . $podcast_id . '_' . $scene_id . '_' . $lang_code . '.mp3';

    $filepath = $audio_dir . $filename;



    // Delete old file so it regenerates fresh

    if (file_exists($filepath)) @unlink($filepath);



    // Use Azure TTS via chatgpt_functions.php

    $result = generateVoice($text, $voice_id, $rate, $filepath);

    if ($result['success'] ?? false) {

        mysqli_query($conn, "UPDATE hdb_podcast_stories SET audio_file='$filename' WHERE id=$scene_id");

        echo json_encode(['success'=>true, 'filename'=>$filename, 'file_url'=>'podcast_audios/'.$filename]);

    } else {

        echo json_encode(['success'=>false, 'message'=>$result['error'] ?? 'TTS failed']);

    }

    exit;

}





// ---------- AJAX: Get Video Library with Thumbnails ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_library_with_thumbs') {

    while (ob_get_level()) ob_end_clean();

    ob_start();

    header('Content-Type: application/json');



    $videos    = array();

    $video_dir = __DIR__ . '/podcast_videos/';



    if (is_dir($video_dir)) {

        $files = scandir($video_dir);

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') continue;

            if (!preg_match('/\.(mp4|webm|mov|avi|mkv|m4v)$/i', $file)) continue;



            $fsize    = @filesize($video_dir . $file);

            $esc_file = mysqli_real_escape_string($conn, $file);



            $db_row = mysqli_fetch_assoc(mysqli_query($conn,

                "SELECT thumbnail, natural_language_tags, image_hashtags

                 FROM hdb_image_data

                 WHERE image_name = '$esc_file'

                 LIMIT 1"));



            $thumbnail = $db_row ? ($db_row['thumbnail']             ?? '') : '';

            $nl_tags   = $db_row ? ($db_row['natural_language_tags'] ?? '') : '';

            $hashtags  = $db_row ? ($db_row['image_hashtags']        ?? '') : '';



            $videos[] = array(

                'video_name' => $file,

                'filename'   => $file,

                'file_size'  => $fsize ? $fsize : 0,

                'thumbnail'  => $thumbnail,

                'nl_tags'    => $nl_tags,

                'hashtags'   => $hashtags

            );

        }

    }



    echo json_encode($videos);

    exit;

}





// ---------- AJAX: Upload Audio File ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_audio') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $scene_id = (int)$_POST['scene_id'];

    $response = ['success' => false, 'message' => ''];

    

    if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {

        $error_msg = isset($_FILES['audio_file']) ? $_FILES['audio_file']['error'] : 'No file uploaded';

        $response['message'] = 'Upload error: ' . $error_msg;

        echo json_encode($response);

        exit;

    }

    

    $file = $_FILES['audio_file'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    

    if ($ext !== 'mp3') {

        $response['message'] = 'Only MP3 files are allowed';

        echo json_encode($response);

        exit;

    }

    

    // Use podcast_audios folder

    $audio_dir = __DIR__ . '/podcast_audios/';

    if (!is_dir($audio_dir)) {

        mkdir($audio_dir, 0777, true);

    }

    

    $filename = 'audio_' . $scene_id . '_' . time() . '.mp3';

    $destination = $audio_dir . $filename;

    

    if (move_uploaded_file($file['tmp_name'], $destination)) {

        // Update the scene with the audio file

        $update_sql = "UPDATE hdb_podcast_stories SET audio_file='$filename' WHERE id=$scene_id";

        if (mysqli_query($conn, $update_sql)) {

            $response['success'] = true;

            $response['message'] = 'Audio uploaded successfully';

            $response['filename'] = $filename;

            $response['file_url'] = 'podcast_audios/' . $filename;

        } else {

            $response['message'] = 'Database update failed: ' . mysqli_error($conn);

        }

    } else {

        $response['message'] = 'Failed to move uploaded file';

    }

    

    echo json_encode($response);

    exit;

}

// ---------- AJAX: Debug Media Search ----------



	

	

// ---------- AJAX: Get Video Library ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_video_library') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $video_dir = __DIR__ . '/podcast_videos/';

    $videos = [];

    

    if (is_dir($video_dir)) {

        $files = scandir($video_dir);

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (in_array($ext, ['mp4','webm','mov','avi','mkv','m4v'])) {

                $fsize = @filesize($video_dir . $file);

                $videos[] = [

                    'video_name' => $file,

                    'file_size' => $fsize ? $fsize : 0

                ];

            }

        }

    }

    

    echo json_encode($videos);

    exit;

}

// ---------- AJAX: Enhance Prompt & Get Hashtags ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'enhance_prompt') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $scene_id = (int)$_POST['scene_id'];

    $original_prompt = trim($_POST['prompt'] ?? '');



    if (empty($original_prompt)) {

        echo json_encode(['success' => false, 'message' => "Scene $scene_id has empty prompt"]);

        exit;

    }



    $gpt_prompt = "Enhance this image generation prompt for OpenAI gpt-image-1 model. Make it more detailed and vivid while keeping the same meaning. Add visual details like lighting, camera angle, expression, clothing, and setting that match the mood. Keep it under 150 words. The image should look like a real photograph.



Also generate 2 hashtags that describe the emotion and person type shown (examples: sadwoman, worriedman, happycouple, calmwoman, stressedman, peacefulwoman, anxiousman, hopefulwoman).



Original prompt:

" . $original_prompt . "



Return ONLY valid JSON, no markdown, no code fences:

{\"enhanced_prompt\": \"...\", \"hashtags\": \"emotionperson1,emotionperson2\"}";



    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | scene=$scene_id | calling callChatGPT_inam\n", 3, __DIR__ . "/a_debug.log");

    

    $result = callChatGPT_inam($gpt_prompt);

    

    error_log(date('Y-m-d H:i:s') . " | enhance_prompt | result=" . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");

    

    if (!$result || !is_array($result)) {

        echo json_encode(['success' => false, 'message' => 'callChatGPT_inam returned invalid result: ' . print_r($result, true)]);

        exit;

    }

    

    if (!$result['success']) {

        $err = isset($result['error']) ? $result['error'] : (isset($result['message']) ? $result['message'] : 'Unknown error');

        echo json_encode(['success' => false, 'message' => 'AI Error: ' . $err]);

        exit;

    }



    $raw = $result['response'];

    $raw = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));

    $raw = preg_replace('/\s*```$/i', '', $raw);

    $parsed = json_decode(trim($raw), true);



    if (!$parsed || !isset($parsed['enhanced_prompt']) || !isset($parsed['hashtags'])) {

        error_log("Enhance parse fail scene=$scene_id raw=$raw\n", 3, __DIR__ . "/a_debug.log");

        echo json_encode(['success' => false, 'message' => 'JSON parse fail. Raw: ' . mb_substr($raw, 0, 300)]);

        exit;

    }



    echo json_encode([

        'success' => true,

        'enhanced_prompt' => $parsed['enhanced_prompt'],

        'hashtags' => $parsed['hashtags']

    ]);

    exit;

}

// ---------- AJAX: Upload Image for Stickers (Visual Boxes) ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload_sticker_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $response = ['success' => false, 'message' => '', 'debug' => []];

    $response['debug']['time'] = date('Y-m-d H:i:s');

    

    try {

        // Get podcast_id and scene_id

        $podcast_id = $url_podcast_id; // From your existing PHP variable

        $scene_id = isset($_POST['scene_id']) ? (int)$_POST['scene_id'] : 0;

        

        if (!$scene_id) {

            throw new Exception('Scene ID is required');

        }

        

        // Check if file was uploaded

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {

            $error_msg = isset($_FILES['image']) ? $_FILES['image']['error'] : 'No file uploaded';

            $response['debug']['error'] = $error_msg;

            throw new Exception('Upload error: ' . $error_msg);

        }

        

        $file = $_FILES['image'];

        $response['debug']['original_name'] = $file['name'];

        $response['debug']['file_size'] = $file['size'];

        $response['debug']['file_type'] = $file['type'];

        

        // Validate file type

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (!in_array($file['type'], $allowed_types)) {

            throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, WEBP');

        }

        

        // Validate file size (max 10MB)

        if ($file['size'] > 10 * 1024 * 1024) {

            throw new Exception('File too large. Max 10MB');

        }

        

        // Get file extension

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);

        

        // Generate filename in format: image_podcast_id_scene_id_timestamp.extension

        $timestamp = time();

        $image_name = "image_{$podcast_id}_{$scene_id}_{$timestamp}.{$extension}";

        

        // Use podcast_stickers folder

        $upload_dir = __DIR__ . '/podcast_stickers/';

        $response['debug']['upload_dir'] = $upload_dir;

        

        // Create directory if it doesn't exist

        if (!is_dir($upload_dir)) {

            if (!mkdir($upload_dir, 0755, true)) {

                throw new Exception('Failed to create podcast_stickers directory');

            }

        }

        

        // Check if directory is writable

        if (!is_writable($upload_dir)) {

            throw new Exception('podcast_stickers directory is not writable');

        }

        

        $destination = $upload_dir . $image_name;

        $response['debug']['destination'] = $destination;

        

        // Move uploaded file

        if (!move_uploaded_file($file['tmp_name'], $destination)) {

            throw new Exception('Failed to move uploaded file');

        }

        

        $response['debug']['file_saved'] = true;

        $response['debug']['file_exists'] = file_exists($destination);

        $response['debug']['file_size_saved'] = filesize($destination);

        

        // Insert into database (using hdb_image_data table)

        $esc_name = mysqli_real_escape_string($conn, $image_name);

        

        // Check table structure

        $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");

        if (!$col_check) {

            $response['debug']['db_error'] = 'Could not check table structure';

        } else {

            $table_cols = [];

            while ($c = mysqli_fetch_assoc($col_check)) {

                $table_cols[] = $c['Field'];

            }

            $response['debug']['table_columns'] = $table_cols;

            

            $insert_map = [];

            if (in_array('image_name', $table_cols)) {

                $insert_map['image_name'] = "'$esc_name'";

            }

            if (in_array('created_at', $table_cols)) {

                $insert_map['created_at'] = "NOW()";

            }

            if (in_array('podcast_id', $table_cols)) {

                $insert_map['podcast_id'] = $podcast_id;

            }

            if (in_array('scene_id', $table_cols)) {

                $insert_map['scene_id'] = $scene_id;

            }

            

            if (!empty($insert_map)) {

                $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";

                $response['debug']['insert_sql'] = $ins_sql;

                

                if (mysqli_query($conn, $ins_sql)) {

                    $response['debug']['db_inserted'] = true;

                    $response['debug']['insert_id'] = mysqli_insert_id($conn);

                } else {

                    $response['debug']['db_insert_error'] = mysqli_error($conn);

                }

            }

        }

        

        $response['success'] = true;

        $response['image_name'] = $image_name;

        $response['message'] = 'Sticker uploaded successfully to podcast_stickers/';

        

        // Log success

        error_log(date('Y-m-d H:i:s') . " - Sticker uploaded: $image_name to podcast_stickers/\n", 3, __DIR__ . '/sticker_upload.log');

        

    } catch (Exception $e) {

        $response['message'] = $e->getMessage();

        error_log(date('Y-m-d H:i:s') . " - Sticker upload error: " . $e->getMessage() . "\n", 3, __DIR__ . '/sticker_upload.log');

    }

    

    echo json_encode($response);

    exit;

}



// ---------- AJAX: Get Stickers from podcast_stickers folder ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'get_stickers') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $sticker_dir = __DIR__ . '/podcast_stickers/';

    $stickers = [];

    

    // Check if directory exists

    if (!is_dir($sticker_dir)) {

        echo json_encode([]);

        exit;

    }

    

    // Get image data from database

    $db_images = [];

    $r = mysqli_query($conn, "SELECT image_name, image_hashtags FROM hdb_image_data ORDER BY id DESC");

    if ($r) {

        while ($row = mysqli_fetch_assoc($r)) {

            $db_images[$row['image_name']] = $row['image_hashtags'] ?? '';

        }

    }

    

    // Scan directory

    $files = scandir($sticker_dir);

    

    foreach ($files as $file) {

        if ($file === '.' || $file === '..') continue;

        

        // Check if it's an image file

        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $file)) {

            continue;

        }

        

        $filepath = $sticker_dir . $file;

        $file_size = file_exists($filepath) ? filesize($filepath) : 0;

        

        // Try to extract podcast_id and scene_id from filename if it matches pattern

        $podcast_id = null;

        $scene_id = null;

        if (preg_match('/image_(\d+)_(\d+)_/', $file, $matches)) {

            $podcast_id = $matches[1];

            $scene_id = $matches[2];

        }

        

        $hashtags = $db_images[$file] ?? '';

        

        $img_data = [

            'image_name' => $file,

            'hashtags' => $hashtags,

            'file_size' => $file_size,

            'podcast_id' => $podcast_id,

            'scene_id' => $scene_id,

            'folder' => 'podcast_stickers'

        ];

        

        $stickers[] = $img_data;

    }

    

    // Sort by newest first (assuming timestamp at end of filename)

    usort($stickers, function($a, $b) {

        return strcmp($b['image_name'], $a['image_name']);

    });

    

    echo json_encode($stickers);

    exit;

}

// ---------- AJAX: Generate Image via gpt-image-1 ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_image') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    $scene_id = (int)$_POST['scene_id'];

    $enhanced_prompt = trim($_POST['enhanced_prompt'] ?? '');

    $hashtags = trim($_POST['hashtags'] ?? '');



    if (empty($enhanced_prompt)) {

        echo json_encode(['success' => false, 'message' => 'Empty prompt', 'step' => 'validate']);

        exit;

    }



    $api_key ="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";

    if (empty($api_key)) {

        echo json_encode(['success' => false, 'message' => '$api_key not set. Check chatgpt_functions.php', 'step' => 'api_key']);

        exit;

    }



    $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

    $image_folder = __DIR__ . '/podcast_images';

    while (file_exists($image_folder . '/' . $image_name_base . '.png')) {

        $image_name_base = str_pad(mt_rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

    }

    $image_name = $image_name_base . '.png';



    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step A: image_name=$image_name | folder=$image_folder\n", 3, __DIR__ . "/a_debug.log");

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B: Calling gpt-image-1 API...\n", 3, __DIR__ . "/a_debug.log");

    

    $result = generateAndSaveImage($enhanced_prompt, $image_name_base, "1024x1536", $image_folder, $api_key);

	error_log("generateAndSaveImage result: " . json_encode($result), 3, __DIR__ . "/a_debug.log");

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step B result: " . json_encode($result) . "\n", 3, __DIR__ . "/a_debug.log");



    if (!$result['success']) {

        echo json_encode(['success' => false, 'message' => $result['message'], 'step' => 'generate_image']);

        exit;

    }



    $full_path = $result['filepath'];

    $file_exists = file_exists($full_path);

    $file_size = $file_exists ? filesize($full_path) : 0;

    

    error_log(date('Y-m-d H:i:s') . " | Scene=$scene_id | Step C: file_exists=" . ($file_exists ? 'YES' : 'NO') . " | size={$file_size} bytes | path=$full_path\n", 3, __DIR__ . "/a_debug.log");



    if (!$file_exists || $file_size < 1000) {

        echo json_encode(['success' => false, 'message' => "Image file missing or too small ({$file_size} bytes). Path: $full_path", 'step' => 'verify_file']);

        exit;

    }



    $esc_name = mysqli_real_escape_string($conn, $image_name);

    $esc_hashtags = mysqli_real_escape_string($conn, $hashtags);

    $esc_prompt = mysqli_real_escape_string($conn, $enhanced_prompt);

    

    $table_cols = [];

    $col_check = mysqli_query($conn, "SHOW COLUMNS FROM hdb_image_data");

    if (!$col_check) {

        $db_warning = 'hdb_image_data table not found: ' . mysqli_error($conn);

        echo json_encode(['success' => true, 'image_name' => $image_name, 'file_size' => $file_size, 'db_warning' => $db_warning, 'step' => 'db_table_missing']);

        exit;

    }

    while ($c = mysqli_fetch_assoc($col_check)) $table_cols[] = $c['Field'];

    

    $insert_map = [];

    if (in_array('image_name', $table_cols))     $insert_map['image_name'] = "'$esc_name'";

    if (in_array('image_hashtags', $table_cols))  $insert_map['image_hashtags'] = "'$esc_hashtags'";

    if (in_array('image_prompt', $table_cols))    $insert_map['image_prompt'] = "'$esc_prompt'";

    if (in_array('created_at', $table_cols))      $insert_map['created_at'] = "NOW()";

    if (in_array('media_type', $table_cols))      $insert_map['media_type'] = "'image'";

    if (in_array('media_format', $table_cols))    $insert_map['media_format'] = "'png'";

    if (in_array('name', $table_cols) && !isset($insert_map['image_name']))           $insert_map['name'] = "'$esc_name'";

    if (in_array('hashtags', $table_cols) && !isset($insert_map['image_hashtags']))    $insert_map['hashtags'] = "'$esc_hashtags'";

    if (in_array('prompt', $table_cols) && !isset($insert_map['image_prompt']))        $insert_map['prompt'] = "'$esc_prompt'";

    

    $db_warning = '';

    $db_inserted = false;

    

    if (empty($insert_map)) {

        $db_warning = 'No matching columns in hdb_image_data. Found columns: ' . implode(', ', $table_cols);

    } else {

        $ins_sql = "INSERT INTO hdb_image_data (" . implode(',', array_keys($insert_map)) . ") VALUES (" . implode(',', array_values($insert_map)) . ")";

        if (!mysqli_query($conn, $ins_sql)) {

            $db_warning = 'INSERT failed: ' . mysqli_error($conn) . ' | Columns: ' . implode(', ', $table_cols);

        } else {

            $db_inserted = true;

        }

    }



    echo json_encode([

        'success' => true,

        'image_name' => $image_name,

        'file_size' => $file_size,

        'file_path' => $full_path,

        'db_inserted' => $db_inserted,

        'db_warning' => $db_warning,

        'table_columns' => $table_cols

    ]);

    exit;

}



// ---------- AJAX: Update Scene Row ----------

// ---------- AJAX: Update Scene Row ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'update_scene') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');  

    $scene_id = (int)$_POST['scene_id'];

    $image_field = $_POST['image_field'] ?? 'image_file'; // Get which field to update

    $image_file = mysqli_real_escape_string($conn, $_POST['image_file'] ?? '');

    $video_file = mysqli_real_escape_string($conn, $_POST['video_file'] ?? '');

    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');

    $media_type = $_POST['media_type'] ?? 'image';

    

    // Map image field to corresponding prompt field

    $prompt_field = 'prompt';

    if ($image_field === 'image_file_1') $prompt_field = 'prompt_1';

    else if ($image_field === 'image_file_2') $prompt_field = 'prompt_2';

    else if ($image_field === 'image_file_3') $prompt_field = 'prompt_3';

    else if ($image_field === 'image_file_4') $prompt_field = 'prompt_4';

    else if ($image_field === 'image_file_5') $prompt_field = 'prompt_5';

    

    if ($media_type === 'video' && !empty($video_file)) {

        $sql = "UPDATE hdb_podcast_stories SET video_file='$video_file' WHERE id=$scene_id";

    } else {

        // Update the specific image field and its corresponding prompt

        $sql = "UPDATE hdb_podcast_stories SET 

                $image_field = '$image_file'";

        

        // Add prompt update if provided

        if (!empty($prompt)) {

            $sql .= ", $prompt_field = '$prompt'";

        }

        

        $sql .= " WHERE id = $scene_id";

    }

    

    error_log("Update Scene SQL: $sql");

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => 'DB: ' . mysqli_error($conn)]);

    }

    exit;

}



// ---------- AJAX: Save Prompt Only ----------

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'save_prompt') {

    if (ob_get_length()) ob_clean();

    header('Content-Type: application/json');

    

    $scene_id = (int)$_POST['scene_id'];

    $prompt = mysqli_real_escape_string($conn, $_POST['prompt'] ?? '');

    $prompt_field = $_POST['prompt_field'] ?? 'prompt'; // Get which prompt field to update

    

    // Validate prompt field to prevent SQL injection

    $allowed_prompt_fields = ['prompt', 'prompt_1', 'prompt_2', 'prompt_3', 'prompt_4', 'prompt_5'];

    if (!in_array($prompt_field, $allowed_prompt_fields)) {

        $prompt_field = 'prompt';

    }

    

    $sql = "UPDATE hdb_podcast_stories SET $prompt_field='$prompt' WHERE id=$scene_id";

    

    error_log("Save Prompt SQL: $sql");

    

    if (mysqli_query($conn, $sql)) {

        echo json_encode(['success' => true]);

    } else {

        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);

    }

    exit;

}







?>

<!DOCTYPE html>

<html lang="en"> 

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">

    <title>VideoVizard - Render Your Masterpiece</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">

	<script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="videomaker-styles.css">

	

	<style>

    /* ── Canvas wrapper ── */

    .canvas-wrapper {

        padding: 0 !important;

        border-radius: 16px;

        overflow: hidden !important;

    }

    #canvasContainer {

        position: relative;

        width: 100%;

        max-width: 420px;

        margin: 0 auto;

        aspect-ratio: 9 / 16;

        background: #000;

        overflow: hidden;

        display: block;

    }

    /* Fabric injects .canvas-container div — keep it in flow */

    #canvasContainer > .canvas-container {

        position: absolute !important;

        top: 0; left: 0;

        width: 100% !important;

        height: 100% !important;

    }

    #canvasContainer canvas {

        display: block !important;

    }

    /* z-index stack inside canvas */

    #canvasContainer canvas.lower-canvas { z-index: 2 !important; }

    #canvasContainer canvas.upper-canvas { z-index: 3 !important; pointer-events: auto !important; }



    /* Nav arrows */

    .nav-arrows {

        position: absolute;

        bottom: 14px; left: 50%;

        transform: translateX(-50%);

        z-index: 500 !important;

        pointer-events: auto !important;

        display: flex; gap: 12px; align-items: center;

        background: rgba(0,0,0,0.6);

        backdrop-filter: blur(6px);

        padding: 6px 16px; border-radius: 30px;

        border: 1px solid rgba(255,255,255,0.2);

    }

    .nav-arrow {

        width: 36px; height: 36px;

        background: rgba(255,255,255,0.15);

        border: none; border-radius: 50%;

        color: white; font-size: 20px;

        cursor: pointer !important;

        pointer-events: auto !important;

        display: flex; align-items: center; justify-content: center;

        transition: all 0.2s;

    }

    .nav-arrow:hover { background: rgba(95,209,255,0.7); }

    .scene-indicator { color: white; font-weight: 600; font-size: 14px; min-width: 50px; text-align: center; }



    /* Icon container — above everything, pass-through for non-icon areas */

    .icon-container {

        position: absolute;

        top: 14px; left: 14px;

        z-index: 9000 !important;

        pointer-events: none; /* container transparent */

    }

    /* The pill bars and individual icons ARE clickable */

    .primary-icons, .secondary-icons {

        pointer-events: auto !important;

    }

    .overlay-icon {

        pointer-events: auto !important;

        cursor: pointer !important;

    }



   



    /* Overlay panels float above canvas */

    .overlay-panel { z-index: 10000 !important; }



    /* Apply-to-all strip */

    .apply-all-strip { padding: 7px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }

    .apply-all-strip label { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; margin:0; }

    .apply-all-strip input[type="checkbox"] { width:15px; height:15px; accent-color:var(--info,#3b82f6); cursor:pointer; }

 



#floatingStopBtn {

    position: absolute !important;

    top: 12px !important;

    left: 50% !important;

    transform: translateX(-50%) !important;

    z-index: 99999 !important;

    background: #dc2626 !important;

    color: white !important;

    border-radius: 30px !important;

    padding: 10px 24px !important;

    font-size: 15px !important;

    font-weight: 700 !important;

    cursor: pointer !important;

    box-shadow: 0 4px 16px rgba(0,0,0,.35) !important;

    width: fit-content !important;

    height: auto !important;

    max-height: 50px !important;        /* ← ADD THIS */

    min-height: unset !important;

    min-width: unset !important;

    line-height: normal !important;

    box-sizing: content-box !important;

    align-self: auto !important;

    flex: none !important;

    overflow: hidden !important;        /* ← ADD THIS */

    white-space: nowrap !important;     /* ← ADD THIS */

}

#canvasContainer #floatingStopBtn {

    width: fit-content !important;

    height: fit-content !important;

    min-height: unset !important;

    align-self: unset !important;

    flex: none !important;

}



 </style>

</head>

<body>



<div class="vidora-header">

    <div class="brand-container">

        <a href="vizard_browser.php">

            <span class="main-icon">🎬</span>

            <div class="brand-text">

                <div class="logo">

                    <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>

                </div>

                <div class="tagline">Social Media Automation</div>

            </div>

        </a>

    </div>

</div>







<!-- DEBUG BANNER — remove after confirming data loads -->

<div id="debugBanner" style="background:#fef3c7;border:1px solid #f59e0b;padding:8px 16px;font-size:12px;font-family:monospace;display:flex;gap:20px;flex-wrap:wrap;">

    <span>🔍 podcast_id=<b><?= $url_podcast_id ?></b></span>

    <span>scenes=<b><?= count($scenes) ?></b></span>

    <span>lang=<b><?= $podcast_lang_code ?></b></span>

    <span>title=<b><?= htmlspecialchars($podcast_title) ?></b></span>

    <span>URL: <b><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?></b></span>

    <button onclick="this.parentElement.remove()" style="margin-left:auto;border:none;background:none;cursor:pointer;">✕</button>

</div>

    <!-- Info Card -->

    <div class="info-card">

        <h1>✨ Render Your Masterpiece 1</h1>

        <p>Fine-tune your captions, typography, and branding. Click the icons on the canvas for scene-specific or global settings.</p>

    </div>





	

   

    <div class="info-card" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">

     

			

		

		<!-- Floating Action Menu - Above Canvas -->

		<div class="floating-action-menu">

		<h4 style="margin: 0;">Project: <?= htmlspecialchars($podcast_title ?: 'Untitled Project') ?></h4>

    <!-- Left Section 

			<div class="menu-left">

			<button class="menu-btn" onclick="toggleSceneSettings('typography')" title="Typography Settings">

				<span>🅰️</span>

				<span class="btn-label">Text</span>

			</button>

			<button class="menu-btn" onclick="toggleSceneSettings('images')" title="Image Settings">

				<span>🌄</span>

				<span class="btn-label">Images</span>

			</button>

			<button class="menu-btn" onclick="toggleSceneSettings('audio')" title="Audio Settings">

				<span>🔊</span>

				<span class="btn-label">Audio</span>

			</button>

			</div>

			-->

		</div>



</div>

 <!-- Main Canvas Card -->

		<div class="info-card canvas-wrapper">

    

       



        <!-- Scene-specific Settings Panel (appears when overlay icon clicked) -->

				<!-- Scene-specific Settings Panel (appears when overlay icon clicked) -->

		<div id="sceneSettingsPanel" class="settings-panel"> 

			

			

			<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REPLACES BOTH INDIVIDUAL PANELS -->

		<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REDESIGNED -->

			<!-- MERGED TYPOGRAPHY & CAPTIONS PANEL - REDESIGNED -->

			

			

			

			

			

			

			

			



			<!-- Hidden audio element for voice samples -->

			<audio id="sampleAudioPlayer" style="display:none;"></audio>



			<!-- Hidden file input for music upload -->

			<input type="file" id="musicUploadInput" accept=".mp3,audio/mpeg" style="display:none;">



			<!-- Save/Cancel Buttons - ALWAYS VISIBLE 

			<div class="panel-actions">

				<button class="panel-btn save" onclick="saveSceneSettings()">💾 Save to This Scene</button>

				<button class="panel-btn save" onclick="saveToAllScenes()" style="background: var(--info);">💾 Save to All Scenes</button>

			</div>

			-->

		</div>

		<div class="canvas-container" id="canvasContainer" style="position:relative;">



			<!-- Action Menu -->

			<div class="action-menu" id="actionMenu">

				<div class="action-menu-item" onclick="playPreview()"><span>▶️</span> Preview</div>

				<div class="action-menu-item" onclick="startRecording()"><span>⏺️</span> Record</div>

				<?php if ($podcast_lang_code === 'en'): ?>

				<div class="action-menu-item" onclick="TranslateVideo()"><span>🌐</span> Translate</div>

				<div class="action-menu-item" onclick="archiveProject()"><span>📦</span> Archive</div>

				<div class="action-menu-item" onclick="deleteProject()" style="color:#dc2626;"><span>🗑️</span> Delete</div>

				<?php endif; ?>

				<div class="action-menu-item" onclick="gobackProjects()"><span>🏠</span> Go Back</div>

			</div>



			<!-- Fabric.js Canvas -->

			<canvas id="fabricCanvas" style="display:block;"></canvas>

			<div id="floatingStopBtn" onclick="handleStopBtn()" style="display:none;">⏹ Stop</div>

			<!-- Icons — top-left, above canvas -->

			<div class="icon-container">

				<div class="primary-icons" id="primaryIcons">

					<div class="overlay-icon" onclick="showTypographyIcons()" title="Text">🅰️</div>

					<div class="overlay-icon" onclick="showImageSelectorOverlay(event)" title="Images">🌄</div>

					<div class="overlay-icon" onclick="showAudioIcons()" title="Audio">🔊</div>

					<div class="overlay-icon" onclick="playPreview()" title="Preview">▶️</div>

					<div class="overlay-icon" onclick="startRecording()" title="Record">⏺️</div>

					<div class="overlay-icon" onclick="toggleActionMenu(event)" title="More">⋮</div>

				</div>

				<div class="secondary-icons" id="textIcons" style="display:none;">

					<div class="overlay-icon back-icon" onclick="hideTextIcons()">←</div>

					<div class="overlay-icon" onclick="showFontFamilyPanel()">🔤</div>

					<div class="overlay-icon" onclick="showFontSizeOptions()">📏</div>

					<div class="overlay-icon" onclick="showTextColorOptions()">🎨</div>

					<div class="overlay-icon" onclick="showBgColorOptions(event)">🖌️</div>

					<div class="overlay-icon" onclick="showStyleOptions()">✒️</div>

					<div class="overlay-icon" onclick="showAlignment()">⬅️</div>

					<div class="overlay-icon" onclick="showEffects()">✨</div>

					<div class="overlay-icon" onclick="showPosition()">📍</div>

					<div class="overlay-icon" onclick="showAnimation()">⚡</div>

					<div class="overlay-icon" onclick="addNewCaption()" style="background:var(--success);color:white;" title="Add Text Caption">➕</div>

					<div class="overlay-icon" onclick="addImageCaption(event)" style="background:var(--info);color:white;" title="Add Image Caption">🖼️</div>

					<div class="overlay-icon" id="deleteIconSecondary" onclick="deleteSelectedCaption()" style="background:#dc2626;color:white;">🗑️</div>

				</div>

			</div>



			<!-- Nav arrows — bottom-center inside canvas -->

			<div class="nav-arrows">

				<div class="nav-arrow" onclick="navigateScene('prev')">←</div>

				<div class="scene-indicator" id="sceneIndicator">1 / <?= count($scenes) ?></div>

				<div class="nav-arrow" onclick="navigateScene('next')">→</div>

			</div>





<div class="overlay-panel" id="imageSelectorPanel" style="display: none; width: 360px; z-index: 10000;">

    <div class="overlay-header">

        <span>🌄 Scene Images</span>

        <button class="overlay-close" onclick="closeOverlay('imageSelectorPanel')">✕</button>

    </div>

    <div class="overlay-content" style="padding: 16px;">

        

        <!-- 5 Image Slots in a row -->

        <div style="display: flex; gap: 8px; justify-content: space-between; margin-bottom: 16px;">

            

            <!-- Image Slot 1 (Main) -->

            <div class="image-slot" data-slot="image_file" onclick="selectImageSlot('image_file')" style="flex: 1; text-align: center; cursor: pointer;">

                <div id="slot_image_file" style="width: 58px; height: 58px; border-radius: 8px; border: 2px solid var(--info); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">

                    <img id="slot_img_image_file" src="" style="width:100%; height:100%; object-fit: cover; display: none;">

                    <span id="slot_placeholder_image_file" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>

                </div>

                <div style="font-size: 10px; color: var(--info); font-weight: 600;">Main</div>

            </div>

            

            <!-- Image Slot 2 -->

            <div class="image-slot" data-slot="image_file_1" onclick="selectImageSlot('image_file_1')" style="flex: 1; text-align: center; cursor: pointer;">

                <div id="slot_image_file_1" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">

                    <img id="slot_img_image_file_1" src="" style="width:100%; height:100%; object-fit: cover; display: none;">

                    <span id="slot_placeholder_image_file_1" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>

                </div>

                <div style="font-size: 10px; color: var(--muted);">V1</div>

            </div>

            

            <!-- Image Slot 3 -->

            <div class="image-slot" data-slot="image_file_2" onclick="selectImageSlot('image_file_2')" style="flex: 1; text-align: center; cursor: pointer;">

                <div id="slot_image_file_2" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">

                    <img id="slot_img_image_file_2" src="" style="width:100%; height:100%; object-fit: cover; display: none;">

                    <span id="slot_placeholder_image_file_2" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>

                </div>

                <div style="font-size: 10px; color: var(--muted);">V2</div>

            </div>

            

            <!-- Image Slot 4 -->

            <div class="image-slot" data-slot="image_file_3" onclick="selectImageSlot('image_file_3')" style="flex: 1; text-align: center; cursor: pointer;">

                <div id="slot_image_file_3" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">

                    <img id="slot_img_image_file_3" src="" style="width:100%; height:100%; object-fit: cover; display: none;">

                    <span id="slot_placeholder_image_file_3" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>

                </div>

                <div style="font-size: 10px; color: var(--muted);">V3</div>

            </div>

            

            <!-- Image Slot 5 -->

            <div class="image-slot" data-slot="image_file_4" onclick="selectImageSlot('image_file_4')" style="flex: 1; text-align: center; cursor: pointer;">

                <div id="slot_image_file_4" style="width: 58px; height: 58px; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin: 0 auto 4px; background: #f1f5f9; position: relative;">

                    <img id="slot_img_image_file_4" src="" style="width:100%; height:100%; object-fit: cover; display: none;">

                    <span id="slot_placeholder_image_file_4" style="font-size: 22px; color: #94a3b8; display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">🖼️</span>

                </div>

                <div style="font-size: 10px; color: var(--muted);">V4</div>

            </div>

            

        </div>

        

        <!-- Prompt Textarea - Moved up -->

        <div style="margin-bottom: 16px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px;">Prompt for selected slot</div>

            <textarea id="slotPrompt" rows="3" class="panel-input" placeholder="Enter prompt for image generation..." style="resize: vertical; width: 100%; font-family: monospace; font-size: 12px; padding: 10px; border: 2px solid var(--border); border-radius: 12px;"></textarea>

        </div>

        

        <!-- Action Buttons -->

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 12px;">

            <button class="panel-btn" onclick="uploadImageForSelectedSlot()" style="background: var(--success); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">

                <span style="font-size: 18px;">📤</span>

                <span>Upload</span>

            </button>

            <button class="panel-btn" onclick="openLibraryForSelectedSlot()" style="background: var(--purple); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">

                <span style="font-size: 18px;">📚</span>

                <span>Library</span>

            </button>

            <button class="panel-btn" onclick="generateImageForSelectedSlot()" style="background: var(--info); color: white; border: none; padding: 10px 5px; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 2px;">

                <span style="font-size: 18px;">🔄</span>

                <span>Generate</span>

            </button>

        </div>

        

        <!-- Hidden file input for uploads -->

        <input type="file" id="slotImageUpload" accept="image/*,video/*" style="display: none;" onchange="handleSlotFileUpload(this)">

        

        <!-- Current Slot Indicator - Compact -->

        <div style="padding: 8px 12px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; justify-content: space-between;">

            <span>Selected:</span>

            <span id="selectedSlotName" style="font-weight: 600; color: var(--info);">Main</span>

        </div>

        

    </div>

</div>

<div class="overlay-panel" id="currentSceneAudioOverlay" style="display: none; width: 330px; max-height: 500px; overflow-y: auto; z-index: 10000;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('currentSceneAudioOverlay')">←</button>

        <span>🔊 Scene Audio</span>

    </div>

    <div class="overlay-content" style="padding: 14px;">

        

        <!-- Scene Text -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Scene Text</div>

            <div id="currentSceneAudioTextDisplay" style="background: #f1f5f9; padding: 10px; border-radius: 8px; font-size: 13px; border: 1px solid var(--border); max-height: 80px; overflow-y: auto;">

                Loading...

            </div>

        </div>

        

        <!-- Audio Player -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Audio Player</div>

            <div id="currentSceneAudioPlayerContainer">

                <div style="color:var(--muted); text-align:center; padding:15px;">No audio for this scene</div>

            </div>

        </div>

        

        <!-- Generate Button -->

        <div>

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Action</div>

            <button class="panel-btn" onclick="generateCurrentAudioFromOverlay()" style="background: var(--purple); color: white; border: none; padding: 10px; border-radius: 30px; cursor: pointer; font-size: 14px; font-weight:600; width:100%;">

                🔄 Generate Audio

            </button>

        </div>

    </div>

</div>



<div class="overlay-panel" id="podcastAudioOverlay" style="display: none; width: 330px; max-height: 500px; overflow-y: auto; z-index: 10000;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('podcastAudioOverlay')">←</button>

        <span>🎤 Podcast Audio</span>

    </div>

    <div class="overlay-content" style="padding: 14px;">

        

        <!-- Voice Dropdown -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Voice for All Scenes</div>

            <select id="podcastAudioOverlayVoiceSelect" class="panel-select" style="width:100%; margin-bottom:6px; padding: 10px;" onchange="previewSelectedVoiceOverlay()">

                <option value="" disabled selected>-- Select Voice --</option>

                <?php 

                $voice_lang = $podcast_lang_code ?? 'en';

                $voice_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$voice_lang' ORDER BY voice_name");

                if ($voice_query && mysqli_num_rows($voice_query) > 0) {

                    while ($voice = mysqli_fetch_assoc($voice_query)) {

                        $display = $voice['voice_name'];

                        if (!empty($voice['voice_description'])) {

                            $display .= " - " . $voice['voice_description'];

                        }

                        echo '<option value="' . htmlspecialchars($voice['voice_key']) . '" data-sample="' . htmlspecialchars($voice['sample_voice'] ?? '') . '">' . htmlspecialchars($display) . '</option>';

                    }

                } else {

                    echo '<option value="en-US-GuyNeural">Guy - Male</option>';

                    echo '<option value="en-US-DavisNeural">Davis - Male</option>';

                    echo '<option value="en-US-SaraNeural">Sara - Female</option>';

                }

                ?>

            </select>

            <button class="panel-btn" onclick="playPodcastVoiceSampleOverlay()" style="background: var(--purple); color: white; border: none; padding: 8px 12px; border-radius: 30px; cursor: pointer; font-size: 12px; width:100%;">

                🔊 Play Sample

            </button>

        </div>

        

        <!-- Background Music Section -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Background Music</div>

            

            <div id="podcastAudioMusicInfo" style="background: #f1f5f9; padding: 8px; border-radius: 8px; font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">

                <span id="podcastAudioMusicFileName" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis;">No music selected</span>

                <button class="panel-btn" onclick="clearBackgroundMusicOverlay()" style="background: #f1f5f9; color: var(--error); border: 1px solid var(--border); padding: 4px 8px; border-radius: 20px; font-size: 11px;">✕</button>

            </div>

            

            <div id="podcastAudioMusicPlayerContainer" style="margin-bottom: 8px; display: none;">

                <audio id="podcastAudioMusicPlayer" controls style="width:100%; height:36px;">

                    <source src="" type="audio/mpeg">

                </audio>

            </div>

            

            <div style="display: flex; gap: 6px;">

                <button class="panel-btn" onclick="openMusicLibraryFromOverlay()" style="background: var(--info); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">

                    📚 Library

                </button>

                <button class="panel-btn" onclick="uploadBackgroundMusicFromOverlay()" style="background: var(--success); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">

                    📤 Upload

                </button>

            </div>

        </div>

        

        <!-- Generate All Button -->

        <div style="margin-top: 15px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Batch Action</div>

            <button class="panel-btn" onclick="generateAllAudioWithSelectedVoiceOverlay()" style="background: var(--dark-blue); color: white; border: none; padding: 12px; border-radius: 30px; cursor: pointer; font-size: 13px; font-weight:600; width:100%;">

                🎤 Generate All Scenes

            </button>

        </div>

    </div>

</div>

<div class="overlay-panel" id="podcastAudioOverlay" style="display: none; width: 330px; max-height: 500px; overflow-y: auto; z-index: 10000;">

    <div class="overlay-header" style="position: sticky; top: 0; background: white; z-index: 10; border-bottom: 1px solid var(--border);">

        <span><span style="background: var(--info); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 8px;">2</span> Podcast-Wide Audio</span>

        <button class="overlay-close" onclick="closeOverlay('podcastAudioOverlay')">✕</button>

    </div>

    <div class="overlay-content" style="padding: 14px;">

        

        <!-- Voice Dropdown -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Voice for All Scenes</div>

            <select id="podcastAudioOverlayVoiceSelect" class="panel-select" style="width:100%; margin-bottom:6px; padding: 10px;" onchange="previewSelectedVoiceOverlay()">

                <option value="" disabled selected>-- Select Voice --</option>

                <?php 

                $voice_lang = $podcast_lang_code ?? 'en';

                $voice_query = mysqli_query($conn, "SELECT * FROM hdb_voices WHERE lang_code = '$voice_lang' ORDER BY voice_name");

                if ($voice_query && mysqli_num_rows($voice_query) > 0) {

                    while ($voice = mysqli_fetch_assoc($voice_query)) {

                        $display = $voice['voice_name'];

                        if (!empty($voice['voice_description'])) {

                            $display .= " - " . $voice['voice_description'];

                        }

                        echo '<option value="' . htmlspecialchars($voice['voice_key']) . '" data-sample="' . htmlspecialchars($voice['sample_voice'] ?? '') . '">' . htmlspecialchars($display) . '</option>';

                    }

                } else {

                    echo '<option value="en-US-GuyNeural">Guy - Male</option>';

                    echo '<option value="en-US-DavisNeural">Davis - Male</option>';

                    echo '<option value="en-US-SaraNeural">Sara - Female</option>';

                }

                ?>

            </select>

            <button class="panel-btn" onclick="playPodcastVoiceSampleOverlay()" style="background: var(--purple); color: white; border: none; padding: 8px 12px; border-radius: 30px; cursor: pointer; font-size: 12px; width:100%;">

                <span>🔊</span> Play Sample

            </button>

        </div>

        

        <!-- Background Music Section -->

        <div style="margin-bottom: 12px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Background Music</div>

            

            <div id="podcastAudioMusicInfo" style="background: #f1f5f9; padding: 8px; border-radius: 8px; font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between;">

                <span id="podcastAudioMusicFileName" style="max-width: 180px; overflow: hidden; text-overflow: ellipsis;">No music selected</span>

                <button class="panel-btn" onclick="clearBackgroundMusicOverlay()" style="background: #f1f5f9; color: var(--error); border: 1px solid var(--border); padding: 4px 8px; border-radius: 20px; font-size: 11px;">✕</button>

            </div>

            

            <div id="podcastAudioMusicPlayerContainer" style="margin-bottom: 8px; display: none;">

                <audio id="podcastAudioMusicPlayer" controls style="width:100%; height:36px;">

                    <source src="" type="audio/mpeg">

                </audio>

            </div>

            

            <div style="display: flex; gap: 6px;">

                <button class="panel-btn" onclick="openMusicLibraryFromOverlay()" style="background: var(--info); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">

                    📚 Library

                </button>

                <button class="panel-btn" onclick="uploadBackgroundMusicFromOverlay()" style="background: var(--success); color: white; border: none; padding: 8px; border-radius: 30px; cursor: pointer; font-size: 12px; flex:1;">

                    📤 Upload

                </button>

            </div>

        </div>

        

        <!-- Generate All Button -->

        <div style="margin-top: 15px;">

            <div style="font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 4px;">Batch Action</div>

            <button class="panel-btn" onclick="generateAllAudioWithSelectedVoiceOverlay()" style="background: var(--dark-blue); color: white; border: none; padding: 12px; border-radius: 30px; cursor: pointer; font-size: 13px; font-weight:600; width:100%;">

                <span>🎤</span> Generate All Scenes

            </button>

        </div>

    </div>

</div>

<div class="secondary-icons" id="audioIcons" style="display: none; position: absolute; top: 20px; left: 20px; z-index: 1000; pointer-events: auto;">

    <!-- Back button -->

    <div class="overlay-icon back-icon" onclick="hideAudioIcons()" title="Back to main">←</div>

    

    <!-- Audio tools -->

    <div class="overlay-icon" onclick="showCurrentSceneAudioOverlay(event)" title="Current Scene Audio" style="background: var(--purple);">🔊</div>

    <div class="overlay-icon" onclick="showPodcastAudioOverlay(event)" title="Podcast-Wide Audio" style="background: var(--info);">🎤</div>

    

   

</div>

<div class="overlay-panel" id="fontFamilyPanel" style="display: none; width: 280px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('fontFamilyPanel')">←</button>

        <span>🔤 Font Family</span>

    </div>

    <div class="apply-all-strip" id="applyAll_fontFamilyPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontFamilyPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 12px;">

        <div class="font-option" onclick="setFontFamily('Inter')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; margin-bottom: 2px;">Inter</div>

        <div class="font-option" onclick="setFontFamily('Arial')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: Arial, sans-serif; margin-bottom: 2px;">Arial</div>

        <div class="font-option" onclick="setFontFamily('Helvetica')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: Helvetica, sans-serif; margin-bottom: 2px;">Helvetica</div>

        <div class="font-option" onclick="setFontFamily('Times New Roman')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Times New Roman', serif; margin-bottom: 2px;">Times New Roman</div>

        <div class="font-option" onclick="setFontFamily('Roboto')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Roboto', sans-serif; margin-bottom: 2px;">Roboto</div>

        <div class="font-option" onclick="setFontFamily('Montserrat')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Montserrat', sans-serif; margin-bottom: 2px;">Montserrat</div>

        <div class="font-option" onclick="setFontFamily('Poppins')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Poppins', sans-serif; margin-bottom: 2px;">Poppins</div>

        <div class="font-option" onclick="setFontFamily('Open Sans')" style="padding: 12px 16px; border-radius: 30px; cursor: pointer; transition: all 0.2s; font-family: 'Open Sans', sans-serif; margin-bottom: 2px;">Open Sans</div>

    </div>

</div>

<div class="overlay-panel" id="fontSizePanel" style="display: none; width: 280px; max-height: 90vh; overflow-y: auto;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('fontSizePanel')">←</button>

        <span>📏 Font Size</span>

    </div>

    <div class="apply-all-strip" id="applyAll_fontSizePanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontSizePanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 20px; max-height: 440px; overflow-y: auto;">

        <!-- Size display and stepper -->

        <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border-radius: 60px; padding: 8px; border: 2px solid #e2e8f0; margin-bottom: 20px;">

            <button class="step-btn" onclick="decreaseFontSize()" style="width: 48px; height: 48px; border-radius: 50%; border: none; background: white; color: #0f2a44; font-size: 24px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">−</button>

            <div style="display: flex; align-items: baseline; gap: 4px; background: white; padding: 8px 24px; border-radius: 40px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">

                <span id="fontSizeNumber" style="font-size: 36px; font-weight: 700; color: #0f2a44; min-width: 50px; text-align: center;">36</span>

                <span style="font-size: 16px; color: #64748b;">px</span>

            </div>

            <button class="step-btn" onclick="increaseFontSize()" style="width: 48px; height: 48px; border-radius: 50%; border: none; background: white; color: #0f2a44; font-size: 24px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">+</button>

        </div>

        

        <!-- Slider -->

        <input type="range" id="fontSizeSlider" min="8" max="120" value="36" style="width: 100%; height: 6px; -webkit-appearance: none; background: linear-gradient(90deg, #8b5cf6, #ec4899); border-radius: 3px; margin-bottom: 20px;" oninput="handleFontSizeSlider(this.value)">

        

        <!-- Size presets -->

        <div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px;">

            <button class="size-preset" onclick="setFontSizeValue(8)">8</button>

            <button class="size-preset" onclick="setFontSizeValue(10)">10</button>

            <button class="size-preset" onclick="setFontSizeValue(12)">12</button>

            <button class="size-preset" onclick="setFontSizeValue(14)">14</button>

            <button class="size-preset" onclick="setFontSizeValue(16)">16</button>

            <button class="size-preset" onclick="setFontSizeValue(18)">18</button>

            <button class="size-preset" onclick="setFontSizeValue(20)">20</button>

            <button class="size-preset" onclick="setFontSizeValue(24)">24</button>

            <button class="size-preset" onclick="setFontSizeValue(28)">28</button>

            <button class="size-preset" onclick="setFontSizeValue(32)">32</button>

            <button class="size-preset" onclick="setFontSizeValue(36)">36</button>

            <button class="size-preset" onclick="setFontSizeValue(42)">42</button>

            <button class="size-preset" onclick="setFontSizeValue(48)">48</button>

            <button class="size-preset" onclick="setFontSizeValue(56)">56</button>

            <button class="size-preset" onclick="setFontSizeValue(64)">64</button>

            <button class="size-preset" onclick="setFontSizeValue(72)">72</button>

            <button class="size-preset" onclick="setFontSizeValue(96)">96</button>

            <button class="size-preset" onclick="setFontSizeValue(120)">120</button>

        </div>

    </div>

</div>

<div class="overlay-panel" id="animationPanel" style="display: none; width: 320px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('animationPanel')">←</button>

        <span>⚡ Animation & Display</span>

    </div>

    <div class="apply-all-strip" id="applyAll_animationPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_animationPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px; max-height: 500px; overflow-y: auto;">

        

        <!-- DISPLAY MODE SECTION -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">

                <span style="font-size: 20px;">📄</span>

                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Text Display Mode</span>

            </div>

            

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">

                <div class="animation-option" onclick="setDisplayMode('full')" id="displayFull" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 20px; margin-bottom: 4px;">📃</div>

                    <div style="font-size: 11px; font-weight: 600;">Full Text</div>

                    <div style="font-size: 9px; color: #64748b;">All at once</div>

                </div>

                

                <div class="animation-option" onclick="setDisplayMode('word')" id="displayWord" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 20px; margin-bottom: 4px;">🔤</div>

                    <div style="font-size: 11px; font-weight: 600;">Word by Word</div>

                    <div style="font-size: 9px; color: #64748b;">Reveal words</div>

                </div>

                

                <div class="animation-option" onclick="setDisplayMode('line')" id="displayLine" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 20px; margin-bottom: 4px;">📏</div>

                    <div style="font-size: 11px; font-weight: 600;">Line by Line</div>

                    <div style="font-size: 9px; color: #64748b;">Split at \n</div>

                </div>

                

                <div class="animation-option" onclick="setDisplayMode('character')" id="displayChar" style="padding: 10px; background: #f8fafc; border-radius: 12px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 20px; margin-bottom: 4px;">🔡</div>

                    <div style="font-size: 11px; font-weight: 600;">Character</div>

                    <div style="font-size: 9px; color: #64748b;">Letter by letter</div>

                </div>

            </div>

            

            <div style="margin-top: 10px; padding: 8px; background: #f1f5f9; border-radius: 12px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 6px;">

                <span style="background: #0f2a44; color: white; width: 18px; height: 18px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px;">i</span>

                <span>Use \n in text to create line breaks</span>

            </div>

        </div>

        

        <!-- ANIMATION STYLE SECTION -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">

                <span style="font-size: 20px;">🎬</span>

                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Animation Style</span>

            </div>

            

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">

                <!-- Row 1 -->

                <div class="anim-btn" onclick="setAnimationStyle('typewriter')" id="animTypewriter" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">📝</div>

                    <div style="font-size: 10px; font-weight: 600;">Typewriter</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('wordReveal')" id="animWordReveal" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">🔤</div>

                    <div style="font-size: 10px; font-weight: 600;">Word Reveal</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('fade')" id="animFade" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">🌫️</div>

                    <div style="font-size: 10px; font-weight: 600;">Fade In</div>

                </div>

                

                <!-- Row 2 -->

                <div class="anim-btn" onclick="setAnimationStyle('slideUp')" id="animSlideUp" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">⬆️</div>

                    <div style="font-size: 10px; font-weight: 600;">Slide Up</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('zoom')" id="animZoom" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">🔍</div>

                    <div style="font-size: 10px; font-weight: 600;">Zoom In</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('pop')" id="animPop" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">💥</div>

                    <div style="font-size: 10px; font-weight: 600;">Pop</div>

                </div>

                

                <!-- Row 3 -->

                <div class="anim-btn" onclick="setAnimationStyle('bounce')" id="animBounce" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">🏀</div>

                    <div style="font-size: 10px; font-weight: 600;">Bounce</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('karaoke')" id="animKaraoke" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">🎤</div>

                    <div style="font-size: 10px; font-weight: 600;">Karaoke</div>

                </div>

                

                <div class="anim-btn" onclick="setAnimationStyle('static')" id="animStatic" style="display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 5px; background: #f8fafc; border-radius: 12px; cursor: pointer; border: 2px solid transparent;">

                    <div style="font-size: 22px;">⏸️</div>

                    <div style="font-size: 10px; font-weight: 600;">Static</div>

                </div>

            </div>

        </div>

        

        <!-- ANIMATION SPEED CONTROL -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">

                <span style="font-size: 20px;">⏱️</span>

                <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Animation Speed</span>

            </div>

            

            <div style="display: flex; align-items: center; gap: 15px;">

                <span style="color: #4CAF50; font-size: 11px;">Slow</span>

                <input type="range" id="animSpeedSlider" min="0.3" max="3" step="0.1" value="1" style="flex: 1; height: 6px; -webkit-appearance: none; background: linear-gradient(90deg, #4CAF50, #f44336); border-radius: 3px;" oninput="previewAnimSpeed(this.value)">

                <span style="color: #f44336; font-size: 11px;">Fast</span>

            </div>

            

            <div style="display: flex; justify-content: center; margin-top: 10px;">

                <span id="animSpeedDisplay" style="background: #0f2a44; color: white; padding: 5px 20px; border-radius: 30px; font-size: 14px; font-weight: 700;">1.0x</span>

            </div>

        </div>

        

        <!-- PREVIEW NOTE -->

        <div style="padding: 8px; background: #f1f5f9; border-radius: 30px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">

            <span style="background: var(--info); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>

            <span>Animation plays when previewing/rendering video</span>

        </div>

        

    </div>

</div>

<div class="overlay-panel" id="fontColorOverlay" style="display: none; width: 280px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('fontColorOverlay')">←</button>

        <span>🎨 Font Color</span>

    </div>

    <div class="apply-all-strip" id="applyAll_fontColorOverlay">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_fontColorOverlay"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 20px;">

        <!-- Color picker -->

        <input type="color" id="textColorPicker" value="#ffffff" style="width: 100%; height: 50px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; margin-bottom: 15px;" oninput="previewTextColor(this.value)">

        

        <!-- Color presets grid -->

        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px;">

            <div style="background-color: #ffffff; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#ffffff')" title="White"></div>

            <div style="background-color: #ffff00; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#ffff00')" title="Yellow"></div>

            <div style="background-color: #ff0000; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#ff0000')" title="Red"></div>

            <div style="background-color: #00ff00; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#00ff00')" title="Green"></div>

            <div style="background-color: #00ffff; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#00ffff')" title="Cyan"></div>

            

            <div style="background-color: #0000ff; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#0000ff')" title="Blue"></div>

            <div style="background-color: #ff00ff; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#ff00ff')" title="Magenta"></div>

            <div style="background-color: #ffa500; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#ffa500')" title="Orange"></div>

            <div style="background-color: #800080; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#800080')" title="Purple"></div>

            <div style="background-color: #000000; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#000000')" title="Black"></div>

            

            <div style="background-color: #FF3B30; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#FF3B30')" title="Coral Red"></div>

            <div style="background-color: #FF9500; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#FF9500')" title="Amber"></div>

            <div style="background-color: #34C759; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#34C759')" title="Mint Green"></div>

            <div style="background-color: #5856D6; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#5856D6')" title="Indigo"></div>

            <div style="background-color: #AF52DE; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setTextColor('#AF52DE')" title="Lavender"></div>

        </div>

    </div>

</div>

<div class="overlay-panel" id="bgColorPanel" style="display: none; width: 280px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('bgColorPanel')">←</button>

        <span>🖌️ Background Color</span>

    </div>

    <div class="apply-all-strip" id="applyAll_bgColorPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_bgColorPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 20px;">

        <!-- Color picker -->

        <input type="color" id="bgColorPicker" value="#000000" style="width: 100%; height: 50px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; margin-bottom: 15px;" oninput="previewBgColor(this.value)">

        

        <!-- Color presets grid -->

        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 20px;">

            <div style="background-color: #FF3B30; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF3B30')" title="Red"></div>

            <div style="background-color: #FF9500; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF9500')" title="Orange"></div>

            <div style="background-color: #FFCC00; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FFCC00')" title="Yellow"></div>

            <div style="background-color: #34C759; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#34C759')" title="Green"></div>

            <div style="background-color: #00C7BE; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#00C7BE')" title="Teal"></div>

            

            <div style="background-color: #007AFF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#007AFF')" title="Blue"></div>

            <div style="background-color: #5856D6; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#5856D6')" title="Purple"></div>

            <div style="background-color: #AF52DE; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#AF52DE')" title="Lavender"></div>

            <div style="background-color: #FF2D55; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF2D55')" title="Pink"></div>

            <div style="background-color: #FF6B6B; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FF6B6B')" title="Coral"></div>

            

            <div style="background-color: #A8E6CF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#A8E6CF')" title="Mint"></div>

            <div style="background-color: #3D5A80; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#3D5A80')" title="Navy"></div>

            <div style="background-color: #E07A5F; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#E07A5F')" title="Terracotta"></div>

            <div style="background-color: #000000; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#000000')" title="Black"></div>

            <div style="background-color: #FFFFFF; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; border: 2px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onclick="setBgColor('#FFFFFF')" title="White"></div>

        </div>

        

        <!-- Background Enable Checkbox - hidden but kept for JS functionality -->

        <input type="checkbox" id="enableBgCheckbox" onchange="toggleBgEnable(this.checked)" style="display:none;">

    </div>

</div>

<div class="overlay-panel" id="stylePanel" style="display: none;">

   <div class="overlay-header">

		<button class="overlay-close" onclick="closeOverlay('stylePanel')">←</button>

		<span>✒️ Text Style</span> 

	</div>

    <div class="apply-all-strip" id="applyAll_stylePanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_stylePanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px;">

        

        <!-- Style options in a row - icons only -->

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">

            

            <!-- Bold -->

            <div class="style-button" onclick="toggleTextStyle('bold')" id="styleBoldBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝐁</div>

                <div id="styleBoldIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

            <!-- Italic -->

            <div class="style-button" onclick="toggleTextStyle('italic')" id="styleItalicBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; font-style: italic; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝐼</div>

                <div id="styleItalicIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

            <!-- Underline -->

            <div class="style-button" onclick="toggleTextStyle('underline')" id="styleUnderlineBtn" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; text-decoration: underline; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">𝑈</div>

                <div id="styleUnderlineIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

        </div>

        

        <!-- Compact indicator -->

        <div style="margin-top: 12px; padding: 6px; background: #f1f5f9; border-radius: 20px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">

            <span style="background: var(--success); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>

            <span>Active style</span>

        </div>

        

    </div>

</div>

<div class="overlay-panel" id="alignmentPanel" style="display: none;">

    <div class="overlay-header">

		<button class="overlay-close" onclick="closeOverlay('alignmentPanel')">←</button>

		<span>⬅️ Text Alignment</span>

	</div>

    <div class="apply-all-strip" id="applyAll_alignmentPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_alignmentPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px;">

        

        <!-- Alignment options in a 2x2 grid - icons only, more compact -->

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">

            

            <!-- Left Align -->

            <div class="align-button" onclick="setTextAlignment('left')" id="alignLeft" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                    <div style="width: 30px; display: flex; flex-direction: column; align-items: flex-start; gap: 5px;">

                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>

                    </div>

                </div>

                <div class="align-indicator" id="alignLeftIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

            <!-- Center Align -->

            <div class="align-button" onclick="setTextAlignment('center')" id="alignCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                    <div style="width: 30px; display: flex; flex-direction: column; align-items: center; gap: 5px;">

                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>

                    </div>

                </div>

                <div class="align-indicator" id="alignCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

            <!-- Right Align -->

            <div class="align-button" onclick="setTextAlignment('right')" id="alignRight" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                    <div style="width: 30px; display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">

                        <div style="width: 26px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 18px; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 30px; height: 4px; background: #334155; border-radius: 4px;"></div>

                    </div>

                </div>

                <div class="align-indicator" id="alignRightIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

            <!-- Justify -->

            <div class="align-button" onclick="setTextAlignment('justify')" id="alignJustify" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                <div style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                    <div style="width: 30px; display: flex; flex-direction: column; align-items: stretch; gap: 5px;">

                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>

                        <div style="width: 100%; height: 4px; background: #334155; border-radius: 4px;"></div>

                    </div>

                </div>

                <div class="align-indicator" id="alignJustifyIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

            </div>

            

        </div>

        

        <!-- Compact indicator -->

        <div style="margin-top: 12px; padding: 6px; background: #f1f5f9; border-radius: 20px; font-size: 10px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">

            <span style="background: var(--success); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>

            <span>Active alignment</span>

        </div>

        

    </div>

</div>

<div class="overlay-panel" id="effectsPanel" style="display: none; width: 320px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('effectsPanel')">←</button>

        <span>✨ Text Effects</span>

    </div>

    <div class="apply-all-strip" id="applyAll_effectsPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_effectsPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px; max-height: 500px; overflow-y: auto;">

        

        <!-- SHADOW EFFECT -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">🌑</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Shadow</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="shadowEnable" onchange="toggleEffect('shadow')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- Shadow Controls (hidden by default) -->

            <div id="shadowControls" style="display: none;">

                <!-- Shadow Color -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>

                    <input type="color" id="shadowColor" value="#000000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateShadow()">

                </div>

                

                <!-- Shadow Blur -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">BLUR</div>

                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                        <input type="range" id="shadowBlur" min="0" max="50" value="10" style="flex: 1;" oninput="updateShadow()">

                        <span id="shadowBlurValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">10</span>

                    </div>

                </div>

                

                <!-- Shadow Offset X -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">OFFSET X</div>

                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                        <input type="range" id="shadowOffsetX" min="-20" max="20" value="5" style="flex: 1;" oninput="updateShadow()">

                        <span id="shadowOffsetXValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">5</span>

                    </div>

                </div>

                

                <!-- Shadow Offset Y -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">OFFSET Y</div>

                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                        <input type="range" id="shadowOffsetY" min="-20" max="20" value="5" style="flex: 1;" oninput="updateShadow()">

                        <span id="shadowOffsetYValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">5</span>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- GLOW EFFECT (Outer Glow) -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">✨</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Glow</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="glowEnable" onchange="toggleEffect('glow')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- Glow Controls (hidden by default) -->

            <div id="glowControls" style="display: none;">

                <!-- Glow Color -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>

                    <input type="color" id="glowColor" value="#ffff00" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGlow()">

                </div>

                

                <!-- Glow Blur -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">INTENSITY</div>

                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                        <input type="range" id="glowBlur" min="1" max="30" value="15" style="flex: 1;" oninput="updateGlow()">

                        <span id="glowBlurValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">15</span>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- OUTLINE EFFECT -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">🔲</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Outline</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="outlineEnable" onchange="toggleEffect('outline')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- Outline Controls -->

            <div id="outlineControls" style="display: none;">

                <div style="display: flex; gap: 15px; align-items: center; margin-top: 10px;">

                    <!-- Color Picker -->

                    <div style="flex: 1;">

                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>

                        <input type="color" id="outlineColor" value="#000000" style="width: 100%; height: 45px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateOutline()">

                    </div>

                    

                    <!-- Width Slider -->

                    <div style="flex: 1;">

                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">WIDTH</div>

                        <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                            <input type="range" id="outlineWidth" min="1" max="10" value="2" style="flex: 1;" oninput="updateOutline()">

                            <span id="outlineWidthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">2</span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- STROKE EFFECT (Inner/Outer with different paint order) -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">✏️</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Stroke</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="strokeEnable" onchange="toggleEffect('stroke')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- Stroke Controls -->

            <div id="strokeControls" style="display: none;">

                <!-- Stroke Position -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">POSITION</div>

                    <select id="strokePosition" onchange="updateStroke()" style="width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 30px; background: white;">

                        <option value="fill">Outside (paint fill first)</option>

                        <option value="stroke">Inside (paint stroke first)</option>

                    </select>

                </div>

                

                <div style="display: flex; gap: 15px; align-items: center;">

                    <!-- Color Picker -->

                    <div style="flex: 1;">

                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR</div>

                        <input type="color" id="strokeColor" value="#ffffff" style="width: 100%; height: 45px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateStroke()">

                    </div>

                    

                    <!-- Width Slider -->

                    <div style="flex: 1;">

                        <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">WIDTH</div>

                        <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                            <input type="range" id="strokeWidth" min="1" max="10" value="2" style="flex: 1;" oninput="updateStroke()">

                            <span id="strokeWidthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">2</span>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- GRADIENT EFFECT -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">🌈</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">Gradient</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="gradientEnable" onchange="toggleEffect('gradient')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- Gradient Controls -->

            <div id="gradientControls" style="display: none;">

                <!-- Color 1 -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR 1</div>

                    <input type="color" id="gradientColor1" value="#ff0000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGradient()">

                </div>

                

                <!-- Color 2 -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">COLOR 2</div>

                    <input type="color" id="gradientColor2" value="#0000ff" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateGradient()">

                </div>

                

                <!-- Gradient Direction -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DIRECTION</div>

                    <select id="gradientDirection" onchange="updateGradient()" style="width: 100%; padding: 10px; border: 2px solid #e2e8f0; border-radius: 30px; background: white;">

                        <option value="left-to-right">→ Left to Right</option>

                        <option value="right-to-left">← Right to Left</option>

                        <option value="top-to-bottom">↓ Top to Bottom</option>

                        <option value="bottom-to-top">↑ Bottom to Top</option>

                        <option value="diagonal">↘ Diagonal</option>

                    </select>

                </div>

            </div>

        </div>

        

        <!-- 3D EFFECT (using multiple shadows) -->

        <div style="background: #f8fafc; border-radius: 20px; padding: 15px; margin-bottom: 15px; border: 1px solid #e2e8f0;">

            <!-- Header with icon and checkbox -->

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">

                <div style="display: flex; align-items: center; gap: 8px;">

                    <span style="font-size: 20px;">🎲</span>

                    <span style="font-weight: 700; font-size: 15px; color: #0f2a44;">3D Effect</span>

                </div>

                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; background: white; padding: 5px 12px; border-radius: 30px; border: 1px solid #cbd5e1;">

                    <input type="checkbox" id="effect3DEnable" onchange="toggleEffect('3d')" style="width: 16px; height: 16px; cursor: pointer;">

                    <span style="font-size: 12px; font-weight: 500;">Enable</span>

                </label>

            </div>

            

            <!-- 3D Controls -->

            <div id="effect3DControls" style="display: none;">

                <!-- 3D Color -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DEPTH COLOR</div>

                    <input type="color" id="effect3DColor" value="#000000" style="width: 100%; height: 40px; border: 2px solid #e2e8f0; border-radius: 30px; cursor: pointer; padding: 2px;" oninput="updateEffect3D()">

                </div>

                

                <!-- 3D Depth -->

                <div style="margin-bottom: 12px;">

                    <div style="font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">DEPTH</div>

                    <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 5px 10px; border-radius: 30px; border: 1px solid #e2e8f0;">

                        <input type="range" id="effect3DDepth" min="1" max="20" value="8" style="flex: 1;" oninput="updateEffect3D()">

                        <span id="effect3DDepthValue" style="font-size: 14px; font-weight: 700; min-width: 25px; color: #0f2a44;">8</span>

                    </div>

                </div>

            </div>

        </div>

        

        <!-- Active Effects Status -->

        <div id="activeEffects" style="margin-top: 10px; padding: 10px; background: #f1f5f9; border-radius: 30px; font-size: 12px; color: #475569; display: flex; align-items: center; gap: 8px; justify-content: center; min-height: 40px;">

            <span>✨ No effects active</span>

        </div>

        

    </div>

</div>

<div class="overlay-panel" id="positionPanel" style="display: none; max-height: 90vh; overflow-y: auto;">

    <div class="overlay-header">

		<button class="overlay-close" onclick="closeOverlay('positionPanel')">←</button>

		<span>📍 Position</span>

	</div>

    <div class="apply-all-strip" id="applyAll_positionPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_positionPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content" style="padding: 15px; max-height: 480px; overflow-y: auto;">

        <div style="margin-bottom: 20px;">

            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">

                <span>⬆️⬇️</span> VERTICAL POSITION

            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">

                

                <!-- Top -->

                <div class="position-button" onclick="setVerticalPosition('top')" id="posTop" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">⬆️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Top</span>

                    <div class="pos-indicator" id="posTopIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

                <!-- Center (Vertical) -->

                <div class="position-button" onclick="setVerticalPosition('center')" id="posVCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">⬆️⬇️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Center</span>

                    <div class="pos-indicator" id="posVCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

                <!-- Bottom -->

                <div class="position-button" onclick="setVerticalPosition('bottom')" id="posBottom" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">⬇️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Bottom</span>

                    <div class="pos-indicator" id="posBottomIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

            </div>

        </div>

        

        <!-- Horizontal Positioning (Left, Center, Right) -->

        <div style="margin-bottom: 20px;">

            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">

                <span>⬅️➡️</span> HORIZONTAL POSITION

            </div>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">

                

                <!-- Left -->

                <div class="position-button" onclick="setHorizontalPosition('left')" id="posLeft" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">⬅️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Left</span>

                    <div class="pos-indicator" id="posLeftIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

                <!-- Center (Horizontal) -->

                <div class="position-button" onclick="setHorizontalPosition('center')" id="posHCenter" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">⬅️➡️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Center</span>

                    <div class="pos-indicator" id="posHCenterIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

                <!-- Right -->

                <div class="position-button" onclick="setHorizontalPosition('right')" id="posRight" style="display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 12px 5px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">➡️</span>

                    </div>

                    <span style="font-size: 11px; font-weight: 600; color: #334155;">Right</span>

                    <div class="pos-indicator" id="posRightIndicator" style="width: 16px; height: 16px; border-radius: 50%; background: transparent;"></div>

                </div>

                

            </div>

        </div>

        

        <!-- Combined Center (both vertical and horizontal) -->

        <div>

            <div style="font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 10px; display: flex; align-items: center; gap: 5px;">

                <span>🎯</span> CENTER ON CANVAS

            </div>

            <div style="display: grid; grid-template-columns: 1fr; gap: 8px;">

                

                <!-- Center Both -->

                <div class="position-button" onclick="setCenterPosition()" id="posCenter" style="display: flex; align-items: center; gap: 15px; padding: 12px 20px; background: #f8fafc; border-radius: 16px; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;">

                    <div style="width: 44px; height: 44px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">

                        <span style="font-size: 24px;">🎯</span>

                    </div>

                    <span style="flex: 1; font-size: 14px; font-weight: 600; color: #0f2a44;">Center on Canvas</span>

                    <span style="font-size: 11px; color: #64748b;">(both axes)</span>

                </div>

                

            </div>

        </div>

        

        <!-- Current Position Status -->

        <div id="currentPosition" style="margin-top: 15px; padding: 8px 12px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 8px; justify-content: center;">

            <span>📍 Current: </span>

            <span id="positionCoordinates">--</span>

        </div>

        

    </div>

</div>

<!-- Image Source Overlay - For adding image captions -->

<div class="overlay-panel" id="imageSourcePanel" style="display: none; width: 280px;">

    <div class="overlay-header">

        <span>🖼️ Add Image Caption</span>

        <button class="overlay-close" onclick="closeOverlay('imageSourcePanel')">✕</button>

    </div>

    <div class="overlay-content" style="padding: 20px;">

        

        <!-- Library Button -->

        <button onclick="openStickerLibraryForNewBox(); closeOverlay('imageSourcePanel');" 

                style="background: var(--purple); color: white; border: none; padding: 18px 16px; border-radius: 50px; cursor: pointer; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); transition: all 0.2s;">

            <span style="font-size: 24px;">📚</span>

            <span>Choose from Library</span>

        </button>

        

        <!-- Upload Button -->

        <button onclick="uploadImageForNewBox(); closeOverlay('imageSourcePanel');" 

                style="background: var(--success); color: white; border: none; padding: 18px 16px; border-radius: 50px; cursor: pointer; font-size: 16px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 12px; width: 100%; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); transition: all 0.2s;">

            <span style="font-size: 24px;">📤</span>

            <span>Upload from Computer</span>

        </button>

        

        <div style="text-align: center; margin-top: 10px; color: var(--muted); font-size: 11px;">

            Supported: JPG, PNG, GIF, WEBP (max 10MB)

        </div>

        

    </div>

</div>

		</div><!-- /canvas-container -->

		

		

			

<!-- Secondary Icons - Typography (Hidden by default) -->





<!-- Overlay Panels - Appear when secondary icons are clicked -->

<!-- Font Family Panel - Updated to match other overlays -->





<!-- Font Size Panel - Matching font family style -->





<div class="overlay-panel" id="textColorPanel" style="display: none;">

    <div class="apply-all-strip" id="applyAll_textColorPanel">

        <label><input type="checkbox" class="apply-all-chk" id="applyAllChk_textColorPanel"> Apply to all scenes</label>

    </div>

    <div class="overlay-content">

        <input type="color" id="textColorPicker" value="#ffffff" class="color-picker" oninput="previewTextColor(this.value)">

        <div class="color-presets">

            <div class="color-swatch" style="background: #ffffff;" onclick="setTextColor('#ffffff')"></div>

            <div class="color-swatch" style="background: #ffff00;" onclick="setTextColor('#ffff00')"></div>

            <div class="color-swatch" style="background: #ff0000;" onclick="setTextColor('#ff0000')"></div>

            <div class="color-swatch" style="background: #00ff00;" onclick="setTextColor('#00ff00')"></div>

            <div class="color-swatch" style="background: #0000ff;" onclick="setTextColor('#0000ff')"></div>

            <div class="color-swatch" style="background: #ff00ff;" onclick="setTextColor('#ff00ff')"></div>

            <div class="color-swatch" style="background: #00ffff;" onclick="setTextColor('#00ffff')"></div>

            <div class="color-swatch" style="background: #000000;" onclick="setTextColor('#000000')"></div>

            <div class="color-swatch" style="background: #ffa500;" onclick="setTextColor('#ffa500')"></div>

            <div class="color-swatch" style="background: #800080;" onclick="setTextColor('#800080')"></div>

        </div>

    </div>

</div>













<!-- Animation Panel - Enhanced with more options -->







<!-- Font Family Selector Overlay (hidden by default) -->



<div class="font-selector-overlay" id="fontSelectorOverlay" style="display: none;">

    <div class="font-selector-panel">

        <!-- Just the picker card, no preview -->

        <div class="picker-card">

            <div class="search-wrap">

                <div class="search-inner">

                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="2.5" stroke-linecap="round">

                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>

                    </svg>

                    <input class="search-input" id="fontSearchInput" placeholder="Search fonts…" autocomplete="off">

                    <button class="search-clear" id="fontSearchClear">×</button>

                </div>

            </div>



            <div class="font-list" id="fontListContainer"></div>

            <div class="no-results" id="noResults">No fonts found</div>



            <div class="picker-footer">

                <span class="footer-count" id="footerCount">20 fonts</span>

                <span class="footer-selected" id="footerSelected">Bebas Neue</span>

            </div>

        </div>

    </div>

</div>



<!-- Font Size Picker Overlay -->

<div class="font-size-overlay" id="fontSizeOverlay" style="display: none;">

    <div class="picker-card">

        <div class="card-header">

            <span class="back-icon-small" onclick="closeFontSizePicker()">←</span>

            Font Size

        </div>

        <div class="card-body">

            <div class="size-stepper">

                <button class="step-btn" id="sizeDown">−</button>

                <div class="size-display">

                    <span class="size-number" id="sizeNumber">36</span>

                    <span class="size-unit">px</span>

                </div>

                <button class="step-btn" id="sizeUp">+</button>

            </div>

            <input class="size-slider" type="range" id="sizeSlider" min="8" max="120" value="36">

            <div class="size-presets" id="sizePresets"></div>

        </div>

    </div>

</div>



<!-- Font Color Picker Overlay -->









<!-- Background Color Panel - Matching font family style -->





<!-- Style Overlay - Compact with icons only -->





<!-- Alignment Overlay - Compact with 4 icons in one row -->

<!-- Alignment Overlay - Matching the style overlay design -->

<!-- Alignment Overlay - Compact with icons only -->





<!-- Effects Overlay - Enhanced with Shadow, Glow, Gradient, Outline, Stroke, and 3D -->





<!-- Position Overlay - For vertical and horizontal positioning -->











<!-- Image Selector Overlay - Shows 5 image slots from the scene - FITS INSIDE CANVAS -->





<!-- Audio Overlay 1: Current Scene Audio - REDUCED WIDTH -->





<!-- Audio Overlay 2: Podcast-Wide Audio - Also reduce width for consistency -->





<!-- Audio Overlay 2: Podcast-Wide Audio -->





<!-- Secondary Icons - Audio Settings (hidden by default) -->





<!-- Music Library Modal -->

<div id="musicLibraryModal" class="modal-overlay" style="z-index: 10002; display: none;">

    <div class="modal-content" style="max-width: 800px; width: 90%;">

        <div class="modal-header">

            <h3><span style="margin-right: 8px;">🎵</span> Music Library</h3>

            <button class="modal-close" onclick="closeMusicLibrary()">✕</button>

        </div>

        

        <div class="modal-search">

            <div style="display: flex; gap: 8px;">

                <input type="text" id="musicSearchInput" placeholder="Search by filename..." onkeyup="filterMusicFiles()" style="flex: 1; padding: 12px; border: 2px solid var(--border); border-radius: 30px; font-size: 14px;">

                <span id="musicCount" style="padding: 12px; color: var(--muted);">0 files</span>

            </div>

        </div>

        

        <div class="modal-body" style="padding: 16px; max-height: 60vh; overflow-y: auto;">

            <div id="musicGrid" class="music-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">

                <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted)">Loading music library...</div>

            </div>

        </div>

        

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px;">

            <div id="musicSelInfo" style="font-size: 13px; color: var(--muted);">No file selected</div>

            <div style="display: flex; gap: 12px;">

                <button class="panel-btn" onclick="closeMusicLibrary()" style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px;">Cancel</button>

                <button id="selectMusicBtn" class="panel-btn" onclick="selectMusicForPodcast()" style="background: var(--success); color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;" disabled>Use Selected Music</button>

            </div>

        </div>

    </div>

</div>	

<!-- ========== MEDIA LIBRARY MODAL (Images & Videos) ========== -->

<!-- ========== MEDIA LIBRARY MODAL (Images & Videos) ========== -->

<div id="mediaLibModal" class="modal-overlay" style="z-index: 10003; display: none;">

    <div class="modal-content" style="max-width: 900px; width: 95%;">

        <div class="modal-header">

            <h3><span style="margin-right: 8px;">🖼️</span> Media Library

                <span style="font-size: 13px; font-weight: 400; color: var(--muted); margin-left: 12px;">

                    Slot: <span id="editSlotName"></span>

                </span>

            </h3>

            <button class="modal-close" onclick="closeMediaLib()">✕</button>

        </div>



        <!-- Search Bar -->

        <div style="padding: 14px 24px; border-bottom: 1px solid var(--border); background: #f8fafc;">

            <div style="display: flex; gap: 8px; align-items: center;">

                <input type="text" id="mediaSearchInput"

                       placeholder="Describe what you need e.g. boy playing in the garden..."

                       style="flex:1; padding: 12px 16px; border: 2px solid var(--border); border-radius: 30px; font-size: 14px; box-sizing: border-box;"

                       onkeyup="if(event.key==='Enter') performMediaSearch()">

                <button onclick="performMediaSearch()"

                        style="background: var(--info); color: white; border: none; padding: 12px 24px; border-radius: 30px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;">

                    🔍 Search

                </button>

                <button onclick="loadAllMediaFiles()"

                        style="background: #f1f5f9; color: #475569; border: 1px solid var(--border); padding: 12px 16px; border-radius: 30px; font-size: 13px; cursor: pointer; white-space: nowrap;">

                    ✕ All

                </button>

            </div>

            <!-- Search status -->

            <div id="mediaSearchStatus" style="margin-top: 8px; font-size: 12px; color: var(--muted); display: none;"></div>

        </div>



        <!-- Tabs -->

        <div style="display: flex; gap: 0; border-bottom: 2px solid var(--border); padding: 0 24px; background: white;">

            <button id="tabImages" 

                    onclick="switchMediaTab('images')"

                    style="padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; font-size: 14px; border-bottom: 3px solid var(--info); color: var(--info); margin-bottom: -2px;">

                🖼️ Images <span id="tabImagesCount" style="background:#e2e8f0; border-radius:30px; padding:2px 8px; font-size:12px; margin-left:4px;">0</span>

            </button>

            <button id="tabVideos"

                    onclick="switchMediaTab('videos')"

                    style="padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 600; font-size: 14px; color: var(--muted); border-bottom: 3px solid transparent; margin-bottom: -2px;">

                🎬 Videos <span id="tabVideosCount" style="background:#e2e8f0; border-radius:30px; padding:2px 8px; font-size:12px; margin-left:4px;">0</span>

            </button>

        </div>



        <!-- Grid -->

        <div class="modal-body" style="padding: 16px 24px; max-height: 55vh; overflow-y: auto;">

            <div id="mediaGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px;">

                <div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--muted);">⏳ Loading media...</div>

            </div>

        </div>



        <!-- Lightbox -->

        <div id="mediaLightbox" onclick="closeMediaLightbox()"

             style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:20000; align-items:center; justify-content:center; cursor:zoom-out;">

            <img id="mediaLightboxImg" src="" style="max-width:90vw; max-height:90vh; border-radius:8px; box-shadow:0 8px 40px rgba(0,0,0,0.6);">

            <div id="mediaLightboxName" style="position:fixed; bottom:24px; left:50%; transform:translateX(-50%); color:white; font-size:13px; background:rgba(0,0,0,0.6); padding:6px 16px; border-radius:20px;"></div>

        </div>



        <!-- Footer -->

        <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-top: 1px solid var(--border);">

            <div id="mediaSelInfo" style="font-size: 13px; color: var(--muted);">No file selected</div>

            <div style="display: flex; gap: 12px;">

                <button class="panel-btn" onclick="closeMediaLib()"

                        style="background: #f1f5f9; color: #1e293b; border: 1px solid #cbd5e1; padding: 10px 24px; border-radius: 30px; cursor: pointer;">

                    Cancel

                </button>

                <button id="mediaSelectBtn" class="panel-btn" disabled onclick="confirmMediaSelection()"

                        style="background: var(--success); color: white; border: none; padding: 10px 30px; border-radius: 30px; font-weight: 700; cursor: pointer; opacity: 0.5;">

                    ✓ Use Selected

                </button>

            </div>

        </div>

    </div>

</div>

<style>



.media-item {

    position: relative;

    border-radius: 10px;

    overflow: hidden;

    border: 3px solid transparent;

    cursor: pointer;

    background: #f1f5f9;

    transition: border-color 0.15s, transform 0.15s;

    aspect-ratio: 1;

}

.media-item:hover { border-color: var(--info); transform: scale(1.02); }

.media-item.selected { border-color: var(--success) !important; box-shadow: 0 0 0 2px var(--success); }

.media-item .media-preview { width: 100%; height: 100%; object-fit: cover; display: block; }

.media-item .media-overlay {

    position: absolute; inset: 0;

    background: linear-gradient(to top, rgba(0,0,0,0.65) 0%, transparent 50%);

    opacity: 0; transition: opacity 0.15s;

    display: flex; align-items: flex-end; padding: 8px;

}

.media-item:hover .media-overlay { opacity: 1; }

.media-item .media-name { color: white; font-size: 10px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; }

.media-item .media-check {

    position: absolute; top: 6px; right: 6px;

    background: var(--success); color: white;

    width: 24px; height: 24px; border-radius: 50%;

    display: none; align-items: center; justify-content: center;

    font-size: 14px; font-weight: 700;

}

.media-item.selected .media-check { display: flex; }

.media-item .media-score {

    position: absolute; top: 6px; left: 6px;

    background: rgba(0,0,0,0.6); color: white;

    padding: 2px 6px; border-radius: 10px;

    font-size: 10px; font-weight: 600;

}

.media-item .zoom-btn {

    position: absolute; bottom: 30px; right: 6px;

    background: rgba(0,0,0,0.5); color: white;

    width: 26px; height: 26px; border-radius: 50%;

    display: none; align-items: center; justify-content: center;

    font-size: 14px; cursor: zoom-in; border: none;

}

.media-item:hover .zoom-btn { display: flex; }

.media-item .media-type-badge {

    position: absolute; bottom: 6px; left: 6px;

    background: rgba(0,0,0,0.6); color: white;

    padding: 2px 6px; border-radius: 8px; font-size: 10px;

}

</style>



<div class="overlay-panel" id="kenBurnsPanel" style="display: none; width: 280px;">

    <div class="overlay-header">

        <button class="overlay-close" onclick="closeOverlay('kenBurnsPanel')">←</button>

        <span>🎬 Ken Burns Effect</span>

    </div>

    <div class="overlay-content" style="padding: 15px;">

        

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">

            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-in')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">🔍</div>

                <div style="font-size: 12px; font-weight: 600;">Zoom In</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-out')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">🔎</div>

                <div style="font-size: 12px; font-weight: 600;">Zoom Out</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('pan-left')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">⬅️</div>

                <div style="font-size: 12px; font-weight: 600;">Pan Left</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('pan-right')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">➡️</div>

                <div style="font-size: 12px; font-weight: 600;">Pan Right</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('pan-up')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">⬆️</div>

                <div style="font-size: 12px; font-weight: 600;">Pan Up</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('pan-down')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent;">

                <div style="font-size: 24px;">⬇️</div>

                <div style="font-size: 12px; font-weight: 600;">Pan Down</div>

            </div>

            

            <div class="ken-btn" onclick="setKenBurnsEffect('zoom-pan')" style="padding: 15px; background: #f8fafc; border-radius: 16px; text-align: center; cursor: pointer; border: 2px solid transparent; grid-column: span 2;">

                <div style="font-size: 24px;">🎯</div>

                <div style="font-size: 12px; font-weight: 600;">Zoom & Pan</div>

            </div>

        </div>

        

        <div style="margin-top: 15px; padding: 10px; background: #f1f5f9; border-radius: 30px; font-size: 11px; color: #475569; display: flex; align-items: center; gap: 6px; justify-content: center;">

            <span style="background: var(--info); width: 6px; height: 6px; border-radius: 50%; display: inline-block;"></span>

            <span>Effect plays during preview/recording</span>

        </div>

    </div>

</div>

			<!-- Video Play Button (for video backgrounds) -->

			<!-- inam - -->

			

			<!-- Overlay Icons -->

			<!-- Overlay Icons -->

			<!-- Overlay Icons -->

			

		</div>

		

		

		<!-- Zoom Controls -->

<!-- Zoom Controls - REPLACE THIS -->

		<!-- Pan/Scroll Controls for Mobile -->

		<div class="canvas-pan-controls">

			<button class="pan-btn" id="panModeBtn" onclick="togglePanMode()" title="Toggle Pan/Scroll Mode (Hold to drag canvas)">

				<span class="pan-icon">🖐️</span>

			</button>

			

		</div>

		

		</div>

		 <!-- Navigation Arrows -->

     

        <!-- Logo Status -->

       

    </div>



    <!-- Log Box -->

    <textarea id="logBox" class="log-box" readonly placeholder="Activity log..."></textarea>

</div>

<div style="display:flex; align-items:center; gap:10px; padding:8px 16px; background:#f8fafc; border-radius:30px; margin:8px 0;">

    <span style="font-size:13px; font-weight:600; color:#475569;">🎬 Transition:</span>

    <select id="transitionStyleSelect" 

            onchange="currentTransitionStyle = this.value"

            style="padding:6px 12px; border:1.5px solid #e2e8f0; border-radius:20px; font-size:13px; background:white; cursor:pointer;">

        <option value="fade">⬛ Fade</option>

        <option value="slide-left">⬅️ Slide Left</option>

        <option value="slide-right">➡️ Slide Right</option>

        <option value="zoom-out">🔍 Zoom Out</option>

        <option value="blur">💫 Blur</option>

        <option value="wipe">🪟 Wipe</option>

    </select>

</div>

<!-- Footer -->

<footer class="site-footer">

    <div class="footer-brand">🎬 VideoVizard</div>

    <div class="footer-links">

        <a href="vizard_browser.php">Home</a>

        <a href="profile.php">Profile</a>

        <a href="settings.php">Settings</a>

        <a href="logout.php">Logout</a>

    </div>

    <div>© <?= date('Y') ?> VideoVizard</div>

</footer>



<!-- Media Library Modal -->

<!-- Media Library Modal -->











<script>

// ============================================================

// VIDEOMAKER — Clean JS rewrite

// Key fixes:

//   1. Captions added to fabricCanvas.add() go on lowerCanvasEl

//      BUT the composite recorder needs BOTH lowerCanvasEl AND

//      upperCanvasEl drawn in order for captions to appear.

//   2. startCaptionAnimation called for ALL captions regardless

//      of caption_name (old code only called it for 'main').

//   3. Zero duplicate function definitions.

// ============================================================



// ── Global state ─────────────────────────────────────────────

let scenes           = <?= json_encode($scenes) ?>;

let audio_files      = <?= json_encode($audio_files) ?>;

let currentSceneIndex = 0;

let currentSceneId    = scenes.length > 0 ? scenes[0].id : null;

let currentImageField = 'image_file';

let sceneCaptions     = {};



let fabricCanvas          = null;

let currentBackgroundImage = null;

let currentBackgroundVideo = null;

let captionText = null;   // legacy ref — kept for saveCanvasStateToScene

let logoImage   = null;

let isSelectionMode = false;

let autoSaveTimeout = null;



// Playback

let isPlayingSequence = false;

let currentScenePlayIndex = 0;

let currentAudioPlayer    = null;

let podcastMusicFile      = <?= json_encode($podcast_music ?? '') ?>;



// Animation

let isPlayingAnimation = false;

let animationFrame     = null;

let animationInterval  = null;



// Recording

let isRecording       = false;

let mediaRecorder     = null;

let recordedChunks    = [];

let renderAudioElements = [];



// UI

let currentOpenPanel = null;



// ── Utility ──────────────────────────────────────────────────

function L(m) {

    const el = document.getElementById('logBox');

    if (el) { el.value += m + '\n'; el.scrollTop = el.scrollHeight; }

    console.log(m);

}



async function safeFetch(fd) {

    const r   = await fetch(location.href, { method: 'POST', body: fd });

    const raw = await r.text();

    try { return { data: JSON.parse(raw), raw }; }

    catch(e) { throw new Error('Server non-JSON:\n' + raw.substring(0, 800)); }

}



function updateSceneIndicator() {

    const el = document.getElementById('sceneIndicator');

    if (el) el.textContent = (currentSceneIndex + 1) + ' / ' + scenes.length;

}



// ── Canvas init ───────────────────────────────────────────────

function initFabricCanvas() {

    if (fabricCanvas) return;

    const container = document.getElementById('canvasContainer');

    if (!container) { console.error('canvasContainer not found'); return; }



    // Read the container's rendered size

    const rect = container.getBoundingClientRect();

    let cW = Math.floor(rect.width);

    let cH = Math.floor(rect.height);



    // Fallback: compute from max-width and aspect-ratio

    if (!cW || cW < 50) {

        cW = Math.min(container.offsetWidth || 320, 420);

        cH = Math.round(cW * 16 / 9);

    }

    if (!cH || cH < 50) cH = Math.round(cW * 16 / 9);



    console.log('📐 Canvas init:', cW, 'x', cH);



    fabricCanvas = new fabric.Canvas('fabricCanvas', {

        width: cW, height: cH,

        backgroundColor: '#000000',

        preserveObjectStacking: true,

        allowTouchScrolling: false,

        selection: false

    });



    // Make the Fabric-injected .canvas-container fill #canvasContainer

    const fabricWrapper = container.querySelector('.canvas-container');

    if (fabricWrapper) {

        fabricWrapper.style.cssText += ';position:absolute!important;top:0;left:0;width:100%!important;height:100%!important;';

    }



    fabricCanvas.on('mouse:down', () => fabricCanvas.calcOffset());

    fabricCanvas.on('touch:start', () => fabricCanvas.calcOffset());

    fabricCanvas.selection = false;



    setupCaptionSelection();



    if (scenes.length > 0) {

        loadCurrentSceneToFabric();

    } else {

        console.warn('⚠️ No scenes loaded');

        fabricCanvas.setBackgroundColor('#111', fabricCanvas.renderAll.bind(fabricCanvas));

    }

    console.log('✅ Canvas ready', cW, 'x', cH);

}



async function loadCurrentSceneToFabric() {

    if (!fabricCanvas || !currentSceneId) return;

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;



    // Stop ongoing animations/loops

    stopCaptionAnimation();

    if (window.kenBurnsFrame)    { cancelAnimationFrame(window.kenBurnsFrame); window.kenBurnsFrame = null; }

    if (window._slideshowTimer)  { clearInterval(window._slideshowTimer);  window._slideshowTimer = null; }

    if (window._videoFrameLoop)  { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }



    // Clear canvas

    fabricCanvas.clear();

    fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));

    currentBackgroundImage = null;

    currentBackgroundVideo = null;

    captionText = null;



    // Remove old DOM video

    const oldVideo = document.getElementById('backgroundVideo');

    if (oldVideo) { oldVideo.pause(); oldVideo.remove(); }

    const playBtn = document.getElementById('videoPlayButton');

    if (playBtn) playBtn.style.display = 'none';



    // Load captions from DB

    sceneCaptions = {};

    await loadSceneCaptions(currentSceneId);



    // ── Background media ──────────────────────────────────────

    const imgSlots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4','image_file_5'];

    const isImg = f => f && f.trim() && !/\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);

    const isVid = f => f && f.trim() && /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(f);



    // Always bust preload cache for current slot so regenerated images load fresh

    const currentSlotFile = (scene[currentImageField] || '').trim();

    if (currentSlotFile && window.preloadCache) {

        delete window.preloadCache[currentSlotFile];

    }



    // Separate images and videos from slots

    const slideshowFiles = imgSlots.map(s => scene[s]).filter(isImg);

    const videoFiles     = imgSlots.map(s => scene[s]).filter(isVid);

    let mediaLoaded = false;



    // Always check the currently selected slot FIRST

    const selectedFile = currentSlotFile;

    if (isVid(selectedFile)) {

        await setVideoBackground('podcast_videos/' + selectedFile);

        mediaLoaded = true;

    } else if (isImg(selectedFile)) {

        // Load selected slot image fresh — bypass any browser cache with timestamp

        await setImageBackground('podcast_images/' + selectedFile + '?t=' + Date.now());

        mediaLoaded = true;

    } else if (videoFiles.length > 0 && slideshowFiles.length === 0) {

        // Only fall back to video if there are no images at all

        await setVideoBackground('podcast_videos/' + videoFiles[0]);

        mediaLoaded = true;

    } else if (slideshowFiles.length > 1) {

        await setImageBackground('podcast_images/' + slideshowFiles[0] + '?t=' + Date.now());

        mediaLoaded = true;

        let idx = 1;

        window._slideshowTimer = setInterval(async () => {

            if (!currentSceneId) { clearInterval(window._slideshowTimer); return; }

            await setImageBackground('podcast_images/' + slideshowFiles[idx % slideshowFiles.length] + '?t=' + Date.now());

            idx++;

        }, 2500);

    } else if (slideshowFiles.length === 1) {

        await setImageBackground('podcast_images/' + slideshowFiles[0] + '?t=' + Date.now());

        mediaLoaded = true;

    }



    if (!mediaLoaded) {

        // Fallback: scan all slots for any media file

        const slots = ['image_file','image_file_1','image_file_2','image_file_3','image_file_4','image_file_5'];

        let fn = (scene[currentImageField] || '').trim();

        if (!fn) {

            for (const slot of slots) {

                if (scene[slot] && scene[slot].trim()) { fn = scene[slot]; break; }

            }

        }

        if (fn) {

            // Skip preload cache entirely — always load fresh from server

            if (isVid(fn)) {

                await setVideoBackground('podcast_videos/' + fn);

            } else {

                await setImageBackground('podcast_images/' + fn + '?t=' + Date.now());

            }

        } else {

            fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas));

        }

    }



    // ── Add captions ──────────────────────────────────────────

    let captionsAdded = 0;

    for (const key in sceneCaptions) {

        if (isNaN(key)) continue;

        const cap = sceneCaptions[key];

        if (cap.image_file && cap.image_file.trim()) {

            await addImageBoxToCanvas(cap.id, cap.image_file, false);

            captionsAdded++;

        } else if (cap.text_content) {

            await addMainCaptionToFabric(scene, cap);

            captionsAdded++;

        }

    }

    console.log(`✅ ${captionsAdded} caption(s) added`);



    // ── Logo ──────────────────────────────────────────────────

    if (scene.logo_enabled && scene.logo_name) await addLogoToFabric(scene);



    fabricCanvas.renderAll();



    // Lock non-caption objects

    fabricCanvas.discardActiveObject();

    fabricCanvas.forEachObject(obj => {

        if (!obj.captionId) {

            obj.selectable  = false;

            obj.hasControls = false;

            obj.hasBorders  = false;

            obj.evented     = false;

        }

    });

}





// ── Load captions from DB ─────────────────────────────────────

async function loadSceneCaptions(sceneId) {

    const fd = new FormData();

    fd.append('ajax_action', 'get_scene_captions');

    fd.append('story_id', sceneId);   // PHP reads story_id

    fd.append('scene_id', sceneId);   // fallback

    fd.append('podcast_id', <?= (int)$url_podcast_id ?>);

    try {

        const { data } = await safeFetch(fd);

        if (data.success && data.captions && data.captions.length > 0) {

            data.captions.forEach(cap => {

                sceneCaptions[cap.id] = cap;

                if (!sceneCaptions[cap.caption_type]) sceneCaptions[cap.caption_type] = cap;

            });

            console.log(`✅ Loaded ${data.captions.length} caption(s) for scene ${sceneId}`);

        } else {

            console.warn(`⚠️ No captions for scene ${sceneId}`, data);

        }

    } catch(e) { console.error('loadSceneCaptions error:', e); }

    return sceneCaptions;

}



// ── Add main text caption to Fabric ──────────────────────────

async function addMainCaptionToFabric(scene, captionData) {

    if (!captionData || !captionData.text_content || !fabricCanvas) return;



    const cW = fabricCanvas.width;

    const cH = fabricCanvas.height;



    // Clamp position and width to canvas bounds

    const left     = Math.min(parseInt(captionData.position_x) || 20, cW - 50);

    const top      = Math.min(parseInt(captionData.position_y) || Math.round(cH * 0.6), cH - 60);

    const rawWidth = parseInt(captionData.width) || cW - 40;

    const width    = Math.min(rawWidth, cW - left - 10); // never wider than canvas

    const fontSize = parseInt(captionData.fontsize) || 28;



    let bgColor = 'transparent';

    if (captionData.bg_enabled == 1) {

        bgColor = captionData.bg_color || '#000000';

    }



    let fontWeight = 'normal';

    if (captionData.fontweight === 'bold' || captionData.fontweight === '700') fontWeight = 'bold';

    else if (captionData.fontweight) fontWeight = String(captionData.fontweight);



    const textObj = new fabric.Textbox(captionData.text_content, {

        left, top, width, fontSize,

        fontFamily:      captionData.fontfamily  || 'Arial',

        fill:            captionData.fontcolor   || '#ffffff',

        fontWeight,

        fontStyle:       captionData.fontstyle   || 'normal',

        underline:       captionData.underline   == 1,

        linethrough:     captionData.linethrough == 1,

        textAlign:       captionData.text_align  || 'center',

        backgroundColor: bgColor,

        stroke:      captionData.outline_enabled ? captionData.outline_color :

                     (captionData.stroke_enabled  ? captionData.stroke_color  : null),

        strokeWidth: captionData.outline_enabled ? parseInt(captionData.outline_width || 2) :

                     (captionData.stroke_enabled  ? parseInt(captionData.stroke_width  || 2) : 0),

        paintFirst:  captionData.outline_enabled ? 'stroke' : 'fill',

        lineHeight:  1.2,

        padding:     15,

        opacity:     1,

        // Controls (respect selection mode)

        hasControls: isSelectionMode,

        hasBorders:  isSelectionMode,

        selectable:  isSelectionMode,

        editable:    true,

        evented:     true,

        // Metadata

        captionId:      captionData.id,

        captionType:    captionData.caption_type || 'text',

        animationStyle: captionData.animation_style || 'none',

        animationSpeed: parseFloat(captionData.animation_speed) || 1.0,

        originalText:   captionData.text_content

    });



    // Remove any existing object with same captionId

    const existing = fabricCanvas.getObjects().find(o => o.captionId == captionData.id);

    if (existing) fabricCanvas.remove(existing);



    fabricCanvas.add(textObj);

    textObj.bringToFront();

    fabricCanvas.renderAll();



    // ── FIX: start animation for ALL captions, not just 'main' ──

    const style = captionData.animation_style || 'none';

    if (style && style !== 'none' && style !== 'static') {

        startCaptionAnimation(textObj);

    }



    // Position / size auto-save

    textObj.on('modified', function() {

        autoSaveCaptionProperty(this, 'position_x', Math.round(this.left));

        autoSaveCaptionProperty(this, 'position_y', Math.round(this.top));

        autoSaveCaptionProperty(this, 'width',       Math.round(this.width));

    });

    textObj.on('editing:exited', function() {

        if (this.text !== captionData.text_content) {

            autoSaveCaptionProperty(this, 'text_content', this.text);

        }

    });



    // Keep reference for legacy code

    if (!captionText) captionText = textObj;

    return textObj;

}



// ── Image box captions ────────────────────────────────────────

async function addImageBoxToCanvas(captionId, imageFile, makeSelectable) {

    if (!fabricCanvas || !imageFile) return;

    const cap = sceneCaptions[captionId];

    const src = 'podcast_images/' + imageFile;

    return new Promise(resolve => {

        fabric.Image.fromURL(src, img => {

            if (!img) { resolve(); return; }

            const left  = cap ? (parseInt(cap.position_x) || 50)  : 50;

            const top   = cap ? (parseInt(cap.position_y) || 200) : 200;

            const w     = cap ? (parseInt(cap.width)       || 100) : 100;

            const scale = w / img.width;

            img.set({

                left, top, scaleX: scale, scaleY: scale,

                selectable:  makeSelectable,

                hasControls: makeSelectable,

                hasBorders:  makeSelectable,

                evented:     true,

                captionId, captionType: 'image'

            });

            const ex = fabricCanvas.getObjects().find(o => o.captionId == captionId);

            if (ex) fabricCanvas.remove(ex);

            fabricCanvas.add(img);

            img.bringToFront();

            fabricCanvas.renderAll();

            if (makeSelectable) img.on('modified', function() {

                autoSaveCaptionProperty(this, 'position_x', Math.round(this.left));

                autoSaveCaptionProperty(this, 'position_y', Math.round(this.top));

                autoSaveCaptionProperty(this, 'width', Math.round(this.width * this.scaleX));

            });

            resolve(img);

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Logo ──────────────────────────────────────────────────────

async function addLogoToFabric(scene) {

    if (!scene.logo_name || !fabricCanvas) return;

    return new Promise(resolve => {

        fabric.Image.fromURL('podcast_images/' + scene.logo_name, img => {

            if (!img) { resolve(); return; }

            const size  = parseInt(scene.logo_size || 60);

            const scale = size / Math.max(img.width, img.height);

            const pos   = scene.logo_position || 'top-right';

            const pad   = 20;

            const cW = fabricCanvas.width, cH = fabricCanvas.height;

            const w = img.width * scale, h = img.height * scale;

            const positions = {

                'top-left':     { left: pad,      top: pad },

                'top-right':    { left: cW-w-pad, top: pad },

                'bottom-left':  { left: pad,      top: cH-h-pad },

                'bottom-right': { left: cW-w-pad, top: cH-h-pad },

                'center':       { left: (cW-w)/2, top: (cH-h)/2 }

            };

            const p = positions[pos] || positions['top-right'];

            img.set({ ...p, scaleX: scale, scaleY: scale, selectable: false, hasControls: false, hasBorders: false, evented: false });

            fabricCanvas.add(img);

            img.bringToFront();

            fabricCanvas.renderAll();

            logoImage = img;

            resolve(img);

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Background: image ─────────────────────────────────────────

function setImageBackground(fullPath) {

    return new Promise(resolve => {

        // Stop DOM video

        const vid = document.getElementById('backgroundVideo');

        if (vid) { vid.pause(); vid.remove(); }

        if (currentBackgroundVideo) { fabricCanvas.remove(currentBackgroundVideo); currentBackgroundVideo = null; }

        if (currentBackgroundImage) { fabricCanvas.remove(currentBackgroundImage); currentBackgroundImage = null; }



        fabric.Image.fromURL(fullPath, img => {

            if (!img) { fabricCanvas.setBackgroundColor('#000000', fabricCanvas.renderAll.bind(fabricCanvas)); resolve(); return; }

            const cW = fabricCanvas.width, cH = fabricCanvas.height;

            const scale = Math.max(cW / img.width, cH / img.height);

            const left  = (cW - img.width  * scale) / 2;

            const top   = (cH - img.height * scale) / 2;

            img.set({ left, top, scaleX: scale, scaleY: scale, selectable: false, evented: false, hasControls: false, hasBorders: false, originX: 'left', originY: 'top' });

            fabricCanvas.add(img);

            fabricCanvas.sendToBack(img);

            currentBackgroundImage = img;

            fabricCanvas.renderAll();

            const scene = scenes.find(s => s.id == currentSceneId);

            applyKenBurnsToCanvas(img, scene && scene.ken_burns_effect ? scene.ken_burns_effect : 'zoom-in');

            resolve();

        }, { crossOrigin: 'anonymous' });

    });

}



// ── Background: video ─────────────────────────────────────────

function setVideoBackground(fullPath) {

    return new Promise(resolve => {

        if (window._videoFrameLoop) { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }

        const old = document.getElementById('backgroundVideo');

        if (old) { old.pause(); old.remove(); }



        const container = document.getElementById('canvasContainer');

        if (!container) { resolve(); return; }



        const video = document.createElement('video');

        video.id = 'backgroundVideo';

        video.muted = true; video.loop = true; video.playsInline = true;

        video.setAttribute('playsinline', '');

        video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:1;pointer-events:none;';

        container.appendChild(video);



        // Canvas transparent so DOM video shows through

        const canvasEl = document.getElementById('fabricCanvas');

        if (canvasEl) canvasEl.style.backgroundColor = 'transparent';

        fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));



        video.src = fullPath + '?t=' + Date.now();

        video.load();

        video.onloadedmetadata = () => {

            video.play()

                .then(() => { currentBackgroundVideo = video; resolve(); })

                .catch(() => resolve());

        };

        video.onerror = () => resolve();

    });

}



function setVideoBackgroundFromCache(cached) {

    return new Promise(resolve => {

        const old = document.getElementById('backgroundVideo');

        if (old) { old.pause(); old.remove(); }

        const container = document.getElementById('canvasContainer');

        if (!container) { resolve(); return; }

        const video = document.createElement('video');

        video.id = 'backgroundVideo'; video.muted = true; video.loop = true; video.playsInline = true;

        video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:1;pointer-events:none;';

        video.src = cached.src;

        container.appendChild(video);

        const canvasEl = document.getElementById('fabricCanvas');

        if (canvasEl) canvasEl.style.backgroundColor = 'transparent';

        fabricCanvas.setBackgroundColor('rgba(0,0,0,0)', fabricCanvas.renderAll.bind(fabricCanvas));

        video.play().then(() => { currentBackgroundVideo = video; resolve(); }).catch(() => resolve());

    });

}



function setImageBackgroundFromCache(cached) {

    return new Promise(resolve => {

        const vid = document.getElementById('backgroundVideo');

        if (vid) { vid.pause(); vid.remove(); }

        if (currentBackgroundVideo) { fabricCanvas.remove(currentBackgroundVideo); currentBackgroundVideo = null; }

        if (currentBackgroundImage) { fabricCanvas.remove(currentBackgroundImage); currentBackgroundImage = null; }

        const cloned   = cached.cloneNode();

        const fabricImg = new fabric.Image(cloned);

        const cW = fabricCanvas.width, cH = fabricCanvas.height;

        const scale = Math.max(cW / fabricImg.width, cH / fabricImg.height);

        fabricImg.set({ left: (cW - fabricImg.width * scale) / 2, top: (cH - fabricImg.height * scale) / 2, scaleX: scale, scaleY: scale, selectable: false, evented: false, hasControls: false, hasBorders: false, originX: 'left', originY: 'top' });

        fabricCanvas.add(fabricImg); fabricCanvas.sendToBack(fabricImg);

        currentBackgroundImage = fabricImg; fabricCanvas.renderAll();

        const scene = scenes.find(s => s.id == currentSceneId);

        applyKenBurnsToCanvas(fabricImg, scene && scene.ken_burns_effect ? scene.ken_burns_effect : 'zoom-in');

        resolve();

    });

}



function cleanupVideo() {

    if (window._slideshowTimer)  { clearInterval(window._slideshowTimer);  window._slideshowTimer = null; }

    if (window._videoFrameLoop)  { cancelAnimationFrame(window._videoFrameLoop); window._videoFrameLoop = null; }

    const old = document.getElementById('backgroundVideo');

    if (old) { old.pause(); old.remove(); }

    if (currentBackgroundVideo) {

        if (fabricCanvas) fabricCanvas.remove(currentBackgroundVideo);

        currentBackgroundVideo = null;

    }

}



// ── Ken Burns ─────────────────────────────────────────────────

function applyKenBurnsToCanvas(img, effect) {

    if (window.kenBurnsFrame) { cancelAnimationFrame(window.kenBurnsFrame); window.kenBurnsFrame = null; }

    if (!img || !fabricCanvas) return;

    const cW = fabricCanvas.width, cH = fabricCanvas.height;

    const base = Math.max(cW / img.width, cH / img.height);

    const zoom = base * 1.2;

    const dur  = 6000;

    const t0   = performance.now();

    const states = {

        'zoom-in':   { ss: base, es: zoom, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },

        'zoom-out':  { ss: zoom, es: base, sl: cW/2, el: cW/2, st: cH/2, et: cH/2 },

        'pan-left':  { ss: zoom, es: zoom, sl: cW/2+(img.width*zoom-cW)/2,  el: cW/2-(img.width*zoom-cW)/2,  st: cH/2, et: cH/2 },

        'pan-right': { ss: zoom, es: zoom, sl: cW/2-(img.width*zoom-cW)/2,  el: cW/2+(img.width*zoom-cW)/2,  st: cH/2, et: cH/2 },

        'pan-up':    { ss: zoom, es: zoom, sl: cW/2, el: cW/2, st: cH/2+(img.height*zoom-cH)/2, et: cH/2-(img.height*zoom-cH)/2 },

        'pan-down':  { ss: zoom, es: zoom, sl: cW/2, el: cW/2, st: cH/2-(img.height*zoom-cH)/2, et: cH/2+(img.height*zoom-cH)/2 },

        'zoom-pan':  { ss: base, es: zoom, sl: cW/2-40, el: cW/2+40, st: cH/2+30, et: cH/2-30 }

    };

    const s = states[effect] || states['zoom-in'];

    img.set({ scaleX: s.ss, scaleY: s.ss, left: s.sl, top: s.st, originX: 'center', originY: 'center' });

    img.setCoords(); fabricCanvas.renderAll();

    function step(now) {

        if (!currentBackgroundImage || currentBackgroundImage !== img) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = p < 0.5 ? 2*p*p : 1 - Math.pow(-2*p+2, 2)/2;

        img.set({ scaleX: s.ss+(s.es-s.ss)*e, scaleY: s.ss+(s.es-s.ss)*e, left: s.sl+(s.el-s.sl)*e, top: s.st+(s.et-s.st)*e });

        img.setCoords(); fabricCanvas.renderAll();

        if (p < 1) window.kenBurnsFrame = requestAnimationFrame(step);

    }

    window.kenBurnsFrame = requestAnimationFrame(step);

}



// ── Scene navigation ──────────────────────────────────────────

async function navigateScene(direction) {

    if (!scenes.length) return;

    const newIndex = direction === 'prev'

        ? (currentSceneIndex - 1 + scenes.length) % scenes.length

        : (currentSceneIndex + 1) % scenes.length;

    currentSceneIndex = newIndex;

    currentSceneId    = scenes[newIndex].id;

    currentImageField = 'image_file';

    updateSceneIndicator();

    if (fabricCanvas) await loadCurrentSceneToFabric();

}



// ── Caption selection ─────────────────────────────────────────

function setupCaptionSelection() {

    if (!fabricCanvas) return;

    fabricCanvas.on('selection:created', e => {

        const sel = e.selected && e.selected[0];

        if (!sel || !sel.captionId || !isSelectionMode) {

            fabricCanvas.discardActiveObject(); fabricCanvas.renderAll(); return;

        }

        selectCaption(sel.captionId, sel.captionType);

    });

    fabricCanvas.on('selection:updated', e => {

        const sel = e.selected && e.selected[0];

        if (!sel || !sel.captionId || !isSelectionMode) {

            fabricCanvas.discardActiveObject(); fabricCanvas.renderAll(); return;

        }

        selectCaption(sel.captionId, sel.captionType);

    });

    fabricCanvas.on('selection:cleared', () => {

        // optional: close panels

    });

}



function selectCaption(captionId, captionType) {

    // open the typography panel and populate it

    const panel = document.getElementById('scene-typography-captions');

    if (panel) {

        panel.style.display = 'block';

        loadCaptionIntoPanel(captionId);

    }

}



function setSelectionMode(enabled) {

    isSelectionMode = enabled;

    if (!fabricCanvas) return;

    fabricCanvas.skipTargetFind = !enabled;

    fabricCanvas.forEachObject(obj => {

        if (obj.captionId) {

            obj.selectable  = enabled;

            obj.hasControls = enabled;

            obj.hasBorders  = enabled;

            obj.evented     = true;

        }

    });

    fabricCanvas.renderAll();

}



// ── Caption save helpers ──────────────────────────────────────

function findCaptionById(id) {

    for (const key in sceneCaptions) {

        if (sceneCaptions[key] && sceneCaptions[key].id == id) return sceneCaptions[key];

    }

    return null;

}



function autoSaveCaptionProperty(captionObj, property, value) {

    if (!captionObj || !captionObj.captionId) return;

    if (!captionObj._pending) captionObj._pending = {};

    captionObj._pending[property] = value;

    clearTimeout(autoSaveTimeout);

    autoSaveTimeout = setTimeout(async () => {

        const updates = Object.assign({}, captionObj._pending);

        captionObj._pending = {};

        await saveCaptionToDatabase(captionObj.captionId, updates);

    }, 500);

}



async function saveCaptionToDatabase(captionId, updates) {

    if (!captionId) return false;

    const fd = new FormData();

    fd.append('ajax_action', 'save_caption');

    fd.append('caption_id', captionId);

    Object.keys(updates).forEach(k => fd.append(k, updates[k] == null ? '' : updates[k]));

    try {

        const { data } = await safeFetch(fd);

        if (data.success) {

            if (sceneCaptions[captionId]) Object.assign(sceneCaptions[captionId], updates);

            return true;

        }

        return false;

    } catch(e) { console.error('saveCaptionToDatabase:', e); return false; }

}



// ── Caption animations ────────────────────────────────────────

function stopCaptionAnimation() {

    isPlayingAnimation = false;

    if (animationInterval) { clearInterval(animationInterval);  animationInterval = null; }

    if (animationFrame)    { cancelAnimationFrame(animationFrame); animationFrame = null; }

    if (fabricCanvas) {

        fabricCanvas.getObjects().forEach(obj => {

            if (obj.captionId) {

                if (obj.originalText) obj.set('text', obj.originalText);

                obj.set({ opacity: 1, scaleX: 1, scaleY: 1 });

            }

        });

        fabricCanvas.renderAll();

    }

}



function startCaptionAnimation(captionObj) {

    if (!captionObj) return;

    stopCaptionAnimation();

    const styleMap = { wordReveal:'word-reveal', fade:'fade-in', slideUp:'slide-up', slideDown:'slide-down', zoom:'zoom-in', static:'none' };

    const raw   = captionObj.animationStyle || 'none';

    const style = styleMap[raw] || raw;

    const speed = captionObj.animationSpeed || 1.0;

    const full  = captionObj.originalText   || captionObj.text;

    isPlayingAnimation = true;

    console.log('🎬 Caption animation:', style);



    switch (style) {

        case 'typewriter':

            captionObj.originalText = full;

            captionObj.set({ text: '', opacity: 1 });

            fabricCanvas.renderAll();

            _typewriterAnim(captionObj, speed);

            break;

        case 'word-reveal':

            captionObj.originalText = full;

            captionObj.set({ text: '', opacity: 1 });

            fabricCanvas.renderAll();

            _wordRevealAnim(captionObj, speed);

            break;

        case 'fade-in':

            captionObj.set('opacity', 0); fabricCanvas.renderAll();

            _fadeInAnim(captionObj, speed);

            break;

        case 'slide-up':    _slideAnim(captionObj, 'up',   speed); break;

        case 'slide-down':  _slideAnim(captionObj, 'down', speed); break;

        case 'zoom-in':     captionObj.set({ scaleX: 0.1, scaleY: 0.1, opacity: 0 }); fabricCanvas.renderAll(); _zoomInAnim(captionObj, speed); break;

        case 'pop':         _popAnim(captionObj, speed);    break;

        case 'bounce':      _bounceAnim(captionObj, speed); break;

        case 'karaoke':     _karaokeAnim(captionObj, speed); break;

        default:

            captionObj.set('opacity', 1); fabricCanvas.renderAll(); break;

    }

}



function _typewriterAnim(obj, speed) {

    const full = obj.originalText || obj.text;

    let i = 0;

    const delay = 50 / speed;

    animationInterval = setInterval(() => {

        if (!isPlayingAnimation) { clearInterval(animationInterval); return; }

        obj.set('text', full.substring(0, i));

        fabricCanvas.renderAll();

        i++;

        if (i > full.length) clearInterval(animationInterval);

    }, delay);

}



function _wordRevealAnim(obj, speed) {

    const words = (obj.originalText || obj.text).split(' ');

    let i = 0;

    const delay = 150 / speed;

    animationInterval = setInterval(() => {

        if (!isPlayingAnimation) { clearInterval(animationInterval); return; }

        obj.set('text', words.slice(0, i).join(' '));

        fabricCanvas.renderAll();

        i++;

        if (i > words.length) clearInterval(animationInterval);

    }, delay);

}



function _fadeInAnim(obj, speed) {

    const dur = 1000 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        obj.set('opacity', p); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _slideAnim(obj, dir, speed) {

    const origTop = obj.top;

    const offset  = dir === 'up' ? 80 : -80;

    obj.set({ top: origTop + offset, opacity: 0 }); fabricCanvas.renderAll();

    const dur = 600 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = 1 - Math.pow(1 - p, 3);

        obj.set({ top: origTop + offset * (1 - e), opacity: p }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _zoomInAnim(obj, speed) {

    const dur = 600 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const e = 1 - Math.pow(1 - p, 3);

        obj.set({ scaleX: 0.1 + 0.9*e, scaleY: 0.1 + 0.9*e, opacity: p }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _popAnim(obj, speed) {

    const dur = 400 / speed;

    const t0  = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const scale = p < 0.5 ? 1 + p * 0.4 : 1.2 - (p - 0.5) * 0.4;

        obj.set({ scaleX: scale, scaleY: scale, opacity: Math.min(p * 2, 1) }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

    }

    animationFrame = requestAnimationFrame(step);

}



function _bounceAnim(obj, speed) {

    const origTop = obj.top;

    const dur  = 800 / speed;

    const t0   = performance.now();

    function step(now) {

        if (!isPlayingAnimation) return;

        const p = Math.min((now - t0) / dur, 1);

        const bounce = Math.abs(Math.sin(p * Math.PI * 3)) * (1 - p) * 30;

        obj.set({ top: origTop - bounce, opacity: Math.min(p * 2, 1) }); fabricCanvas.renderAll();

        if (p < 1) animationFrame = requestAnimationFrame(step);

        else { obj.set('top', origTop); fabricCanvas.renderAll(); }

    }

    animationFrame = requestAnimationFrame(step);

}



function _karaokeAnim(obj, speed) {

    // Simplified: just typewriter at word level

    _wordRevealAnim(obj, speed);

}



// ── Playback (preview) ────────────────────────────────────────

async function playFullSequence() {

    if (!scenes || !scenes.length) { alert('No scenes to play'); return; }

    if (isPlayingSequence) { stopPlayback(); return; }

    if (isRecording) return;

    hideAllOverlays();

    await preloadAllScenes();

    isPlayingSequence = true;

    showStopBtn();

    playSceneInSequence(0);

}



async function preloadAllScenes() {

    window.preloadCache = window.preloadCache || {};

    const stopBtn = document.getElementById('floatingStopBtn');

    if (stopBtn) { stopBtn.style.display = 'block'; stopBtn.style.background = '#f59e0b'; stopBtn.innerText = '⏳ Loading...'; }

    await Promise.all(scenes.map(scene => new Promise(resolve => {

        const fn = scene.image_file;

        if (!fn || window.preloadCache[fn]) { resolve(); return; }

        const isV = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);

        const el  = isV ? document.createElement('video') : new Image();

        if (isV) { el.preload = 'auto'; el.muted = true; }

        el.onload = el.oncanplaythrough = () => { window.preloadCache[fn] = el; resolve(); };

        el.onerror = resolve;

        setTimeout(resolve, isV ? 8000 : 5000);

        el.src = (isV ? 'podcast_videos/' : 'podcast_images/') + fn;

        if (isV) el.load();

    })));

    if (stopBtn) { stopBtn.style.background = '#dc2626'; stopBtn.innerText = '⏹ Stop'; stopBtn.style.display = 'none'; }

}



async function playSceneInSequence(index) {

    if (!isPlayingSequence || index >= scenes.length) { stopPlayback(); return; }

    const scene = scenes[index];

    if (!scene) { stopPlayback(); return; }



    let audioFile = scene.audio_file || (audio_files && audio_files[scene.id]);

    if (!audioFile) { setTimeout(() => playSceneInSequence(index + 1), 500); return; }



    currentSceneIndex = index;

    currentSceneId    = scene.id;

    updateSceneIndicator();



    // Start background music on first scene

    if (index === 0 && podcastMusicFile) _startBgMusic('preview-bg-music');



    // ── Play transition before loading new scene (skip first scene) ──

    if (index > 0) {

        await playTransition(currentTransitionStyle);

    }



    await new Promise(r => setTimeout(r, 0));



    if (fabricCanvas) {

        await loadCurrentSceneToFabric();

        // Start caption animations for this scene

        setTimeout(() => {

            fabricCanvas.getObjects().filter(o => o.captionId).forEach(o => startCaptionAnimation(o));

        }, 200);

    }



    if (podcastMusicFile) {

        // Music mode: use audio duration as timer

        const dur = new Audio();

        let done = false;

        dur.preload = 'metadata';

        dur.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

        dur.onloadedmetadata = () => {

            done = true;

            const ms = dur.duration * 1000;

            dur.src = ''; dur.load();

            setTimeout(() => { 

                if (isPlayingSequence) { 

                    stopCaptionAnimation(); 

                    playSceneInSequence(index + 1); 

                } 

            }, ms);

        };

        dur.onerror = () => {

            if (done) return;

            dur.src = ''; dur.load();

            setTimeout(() => { 

                if (isPlayingSequence) playSceneInSequence(index + 1); 

            }, 2000);

        };

        dur.load();

        return;

    }



    // No music — play scene audio

    let player = document.getElementById('seq-audio-' + scene.id);

    if (!player) { 

        player = document.createElement('audio'); 

        player.id = 'seq-audio-' + scene.id; 

        document.body.appendChild(player); 

    }

    player.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    player.load();

    currentAudioPlayer = player;

    player.onended = () => { 

        stopCaptionAnimation(); 

        playSceneInSequence(index + 1); 

    };

    player.onerror = () => { 

        stopCaptionAnimation(); 

        setTimeout(() => playSceneInSequence(index + 1), 500); 

    };

    try { 

        await player.play(); 

    } catch(e) { 

        stopCaptionAnimation(); 

        setTimeout(() => playSceneInSequence(index + 1), 500); 

    }

}



function _startBgMusic(id) {

    const old = document.getElementById(id); if (old) { old.pause(); old.remove(); }

    const el  = document.createElement('audio');

    el.id = id; el.loop = false; el.volume = 0.4; el.preload = 'auto';

    document.body.appendChild(el);

    el.oncanplaythrough = () => { el.oncanplaythrough = null; el.play().catch(() => {}); };

    el.src = '/podcast_music/' + podcastMusicFile; el.load();

}



function stopPlayback() {

    isPlayingSequence = false;

    if (currentAudioPlayer) { currentAudioPlayer.pause(); currentAudioPlayer = null; }

    ['preview-bg-music','render-bg-music'].forEach(id => {

        const el = document.getElementById(id); if (el) { el.pause(); el.remove(); }

    });

    stopCaptionAnimation();

    hideStopBtn();

}



function showStopBtn() { const b = document.getElementById('floatingStopBtn'); if (b) b.style.display = 'block'; }

function hideStopBtn()  { const b = document.getElementById('floatingStopBtn'); if (b) b.style.display = 'none';  }

function handleStopBtn() { isRecording ? stopRecording() : stopPlayback(); hideStopBtn(); }



// ── Recording ─────────────────────────────────────────────────

async function startRecording() {

    if (isRecording) { stopRecording(); return; }

    if (!scenes || !scenes.length) { alert('No scenes to record'); return; }

    isRecording = true;

    recordedChunks = [];

    renderAudioElements = [];

    hideAllOverlays();

    await preloadAllScenes();

    showStopBtn();

    await renderScene(0);

}



// Render scene for recording (same as play but triggers MediaRecorder)

let _renderLock = -1;

async function renderScene(index) {

    if (!isRecording || index >= scenes.length) { finishRecording(); return; }

    if (_renderLock === index) { console.warn('renderScene duplicate call ignored'); return; }

    _renderLock = index;



    const scene = scenes[index];

    if (!scene) { _renderLock = -1; renderScene(index + 1); return; }

    L(`🎥 Rendering scene ${index+1}/${scenes.length}`);



    if (index === 0 && podcastMusicFile) _startBgMusic('render-bg-music');



    currentSceneIndex = index;

    currentSceneId    = scene.id;

    updateSceneIndicator();



    // ── Play transition before loading new scene (skip first scene) ──

    if (index > 0) {

        await playTransition(currentTransitionStyle);

    }



    await new Promise(r => setTimeout(r, 0));



    if (fabricCanvas) {

        await loadCurrentSceneToFabric();

        // Start animations during recording

        setTimeout(() => {

            fabricCanvas.getObjects().filter(o => o.captionId).forEach(o => startCaptionAnimation(o));

        }, 200);

        await new Promise(r => setTimeout(r, scene.video_file ? 500 : 100));

    }



    let audioFile = scene.audio_file || (audio_files && audio_files[scene.id]);

    if (!audioFile) { 

        L(`⚠️ Scene ${index+1} no audio — skipping`); 

        _renderLock = -1;

        setTimeout(() => renderScene(index + 1), 500); 

        return; 

    }



    if (podcastMusicFile) {

        // Start recorder on scene 0

        if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) startMediaRecorder();

        const dur = new Audio();

        let done = false;

        dur.preload = 'metadata';

        dur.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

        dur.onloadedmetadata = () => {

            done = true;

            const ms = dur.duration * 1000;

            dur.src = ''; dur.load();

            L(`⏱️ Scene ${index+1}: ${(ms/1000).toFixed(1)}s`);

            _renderLock = -1;

            setTimeout(() => { if (isRecording) renderScene(index + 1); }, ms);

        };

        dur.onerror = () => { 

            if (done) return; 

            _renderLock = -1;

            setTimeout(() => { if (isRecording) renderScene(index + 1); }, 2000); 

        };

        dur.load();

        return;

    }



    // No music — play audio

    const audio = new Audio();

    audio.src = 'podcast_audios/' + audioFile + '?t=' + Date.now();

    renderAudioElements.push(audio);

    audio.onloadedmetadata = () => {

        if (index === 0 && (!mediaRecorder || mediaRecorder.state === 'inactive')) startMediaRecorder();

        _renderLock = -1;

        audio.play()

            .then(() => setTimeout(() => renderScene(index + 1), audio.duration * 1000))

            .catch(() => setTimeout(() => renderScene(index + 1), 500));

    };

    audio.onerror = () => { 

        _renderLock = -1;

        setTimeout(() => renderScene(index + 1), 500); 

    };

    audio.load();

}



function startMediaRecorder() {

    L('🎥 Starting MediaRecorder');

    const fabricEl = document.getElementById('fabricCanvas');

    if (!fabricEl) { console.error('Canvas not found'); return; }

    const cW = fabricEl.width, cH = fabricEl.height;



    // ── FIX: composite = video BG + lower canvas + UPPER canvas (captions) ──

    let recordingCanvas = document.getElementById('_recordingCanvas');

    if (!recordingCanvas) {

        recordingCanvas = document.createElement('canvas');

        recordingCanvas.id = '_recordingCanvas';

        recordingCanvas.width  = cW;

        recordingCanvas.height = cH;

        recordingCanvas.style.display = 'none';

        document.body.appendChild(recordingCanvas);

    }

    const rCtx = recordingCanvas.getContext('2d');

    const lowerEl = fabricCanvas.lowerCanvasEl || fabricEl;

    const upperEl = fabricCanvas.upperCanvasEl  || null;



    let compositeActive = true;

    function compositeFrame() {

        if (!compositeActive) return;

        rCtx.clearRect(0, 0, cW, cH);

        // 1. Background video (DOM element)

        const vid = document.getElementById('backgroundVideo');

        if (vid && !vid.paused && !vid.ended) {

            rCtx.drawImage(vid, 0, 0, cW, cH);

        } else {

            rCtx.fillStyle = '#000';

            rCtx.fillRect(0, 0, cW, cH);

        }

        // 2. Fabric lower canvas (images, shapes)

        rCtx.drawImage(lowerEl, 0, 0, cW, cH);

        // 3. Fabric upper canvas (captions, text)

        if (upperEl) rCtx.drawImage(upperEl, 0, 0, cW, cH);

        requestAnimationFrame(compositeFrame);

    }

    compositeFrame();

    window._compositeActive = () => { compositeActive = false; };



    const stream = recordingCanvas.captureStream(30);

    const mimeTypes = ['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'];

    let mimeType = '';

    for (const m of mimeTypes) { if (MediaRecorder.isTypeSupported(m)) { mimeType = m; break; } }

    const opts = mimeType ? { mimeType, videoBitsPerSecond: 5000000 } : {};



    try {

        mediaRecorder = new MediaRecorder(stream, opts);

        mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) recordedChunks.push(e.data); };

        mediaRecorder.onstop = () => {

            L('⏹️ MediaRecorder stopped — saving');

            saveRecordedVideo();

        };

        mediaRecorder.onerror = e => { console.error('MediaRecorder error:', e); stopRecording(); };

        mediaRecorder.start(1000);

        L('✅ Recording started');

    } catch(e) { console.error('MediaRecorder failed:', e); stopRecording(); }

}



function stopRecording() {

    L('⏹️ stopRecording');

    isRecording = false;

    if (window._compositeActive) { window._compositeActive(); window._compositeActive = null; }

    renderAudioElements.forEach(a => { try { a.pause(); } catch(e) {} });

    renderAudioElements = [];

    if (mediaRecorder && mediaRecorder.state !== 'inactive') {

        mediaRecorder.stop();

    } else {

        saveRecordedVideo();

    }

    const bgMusic = document.getElementById('render-bg-music');

    if (bgMusic) { bgMusic.pause(); bgMusic.remove(); }

    hideStopBtn();

}



function finishRecording() {

    L('🏁 All scenes rendered — stopping recorder');

    if (mediaRecorder && mediaRecorder.state !== 'inactive') {

        mediaRecorder.stop();

    } else {

        saveRecordedVideo();

    }

    const bgMusic = document.getElementById('render-bg-music');

    if (bgMusic) { bgMusic.pause(); bgMusic.remove(); }

    hideStopBtn();

    isRecording = false;

}



function saveRecordedVideo() {

    if (!recordedChunks.length) { L('⚠️ No recorded data'); return; }

    const blob = new Blob(recordedChunks, { type: recordedChunks[0].type || 'video/webm' });

    const url  = URL.createObjectURL(blob);

    const ext  = (recordedChunks[0].type || 'webm').includes('mp4') ? 'mp4' : 'webm';

    const a    = document.createElement('a');

    a.href = url; a.download = 'video_' + Date.now() + '.' + ext;

    a.click();

    URL.revokeObjectURL(url);

    L('✅ Video downloaded');



    // Also upload to server

    const fd = new FormData();

    fd.append('ajax_action', 'save_rendered_video');

    fd.append('podcast_id', <?= (int)$url_podcast_id ?>);

    fd.append('video_file', blob, 'rendered.' + ext);

    safeFetch(fd).then(({ data }) => {

        if (data.success) L('✅ Saved to server: ' + data.filename);

        else L('⚠️ Server save failed: ' + data.message);

    }).catch(e => L('⚠️ Upload error: ' + e.message));

}



// ── UI helpers ────────────────────────────────────────────────

function hideAllOverlays() {

    document.querySelectorAll('.overlay-panel, #scene-typography-captions, #scene-images, #scene-audio, #fontSizeOverlay, #fontColorOverlay, #fontFamilyPanel, #fontSizePanel, #bgColorPanel, #stylePanel, #effectsPanel, #alignmentPanel, #positionPanel, #animationPanel, #imageSourcePanel, #currentSceneAudioOverlay, #podcastAudioOverlay, #imageSelectorPanel, .action-menu, .font-selector-overlay').forEach(el => {

        el.style.display = 'none';

    });

}



function toggleActionMenu(e) {

    const m = document.getElementById('actionMenu');

    if (!m) return;

    m.classList.toggle('active');

    if (e) e.stopPropagation();

}



function openSceneSettings(type) {

    if (!fabricCanvas) initFabricCanvas();

    hideAllOverlays();

    const panel = document.getElementById('scene-' + type) || document.getElementById('sceneSettingsPanel');

    if (panel) { panel.style.display = 'block'; }

}



function closeScenePanel() {

    hideAllOverlays();

    setSelectionMode(false);

}



// ── Panel population helper ───────────────────────────────────

function loadCaptionIntoPanel(captionId) {

    const caption = findCaptionById(captionId);

    if (!caption) return;

    // Populate whichever UI elements exist

    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };

    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val || ''; };

    setVal('captionFontFamily',  caption.fontfamily);

    setVal('captionFontSize',    caption.fontsize);

    setVal('captionFontColor',   caption.fontcolor);

    setVal('captionTextAlign',   caption.text_align);

    setVal('captionAnimStyle',   caption.animation_style);

    setVal('captionAnimSpeed',   caption.animation_speed);

}



// ── Image slot management ─────────────────────────────────────

let selectedImageSlot = 'image_file';

function selectImageSlot(slotName) {

    selectedImageSlot = slotName;

    currentImageField = slotName;



    // Update slot border highlight

    document.querySelectorAll('.image-slot > div:first-child').forEach(el => {

        el.style.border = '1px solid var(--border)';

    });

    const activeEl = document.getElementById('slot_' + slotName);

    if (activeEl) activeEl.style.border = '2px solid var(--info)';



    // Update label

    const label = document.getElementById('selectedSlotName');

    if (label) {

        const names = {image_file:'Main', image_file_1:'V1', image_file_2:'V2', image_file_3:'V3', image_file_4:'V4'};

        label.textContent = names[slotName] || slotName;

    }



    // Load prompt for this slot

    loadSlotPrompt(slotName);

}



async function saveCurrentImageField() {

    // no-op for now — positions saved via autoSaveCaptionProperty

}



// ── Upload helpers ────────────────────────────────────────────

async function uploadSceneImage(input) {

    if (!input.files || !input.files[0]) return;

    const fd = new FormData();

    fd.append('ajax_action', 'upload_scene_image');

    fd.append('story_id', currentSceneId);

    fd.append('image_field', selectedImageSlot);

    fd.append('image', input.files[0]);

    const { data } = await safeFetch(fd);

    if (data.success) {

        const scene = scenes.find(s => s.id == currentSceneId);

        if (scene) scene[selectedImageSlot] = data.filename;

        await loadCurrentSceneToFabric();

    }

}



// ── Init ──────────────────────────────────────────────────────

// ── Utility helpers ───────────────────────────────────────────

function escapeHtml(text) {

    const div = document.createElement('div');

    div.textContent = text;

    return div.innerHTML;

}

function formatTime(seconds) {

    if (isNaN(seconds)) return '0:00';

    const mins = Math.floor(seconds / 60);

    const secs = Math.floor(seconds % 60);

    return mins + ':' + (secs < 10 ? '0' : '') + secs;

}

function closeAllOverlays() {

    ['fontFamilyPanel','fontSizePanel','fontColorOverlay','bgColorPanel','stylePanel',

     'effectsPanel','alignmentPanel','positionPanel','animationPanel','imageSourcePanel',

     'currentSceneAudioOverlay','podcastAudioOverlay','imageSelectorPanel'].forEach(id => {

        const el = document.getElementById(id);

        if (el) el.style.display = 'none';

    });

    document.querySelectorAll('.overlay-panel').forEach(el => el.style.display = 'none');

}

function positionOverlayNearIcon(overlay, icon, width, height) {

    if (!overlay) return;

    const container  = document.getElementById('canvasContainer');

    const canvasRect = container ? container.getBoundingClientRect() : null;



    // Default: top-left inside canvas with some offset

    let left = 60, top = 60;



    if (icon && canvasRect) {

        const iconRect = icon.getBoundingClientRect();

        // Position relative to canvasContainer

        left = iconRect.left - canvasRect.left + iconRect.width + 8;

        top  = iconRect.top  - canvasRect.top;

        // Keep inside canvas bounds

        if (left + width  > canvasRect.width)  left = canvasRect.width  - width  - 8;

        if (top  + height > canvasRect.height) top  = canvasRect.height - height - 8;

        if (left < 8) left = 8;

        if (top  < 8) top  = 8;

    }



    overlay.style.position = 'absolute';

    overlay.style.left     = left + 'px';

    overlay.style.top      = top  + 'px';

    overlay.style.display  = 'block';

    overlay.style.zIndex   = '10000';

}



// ── Image slots ───────────────────────────────────────────────

function loadImageSlots() {

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;

    ['image_file','image_file_1','image_file_2','image_file_3','image_file_4'].forEach(slot => {

        const imgEl = document.getElementById('slot_img_' + slot);

        const phEl  = document.getElementById('slot_placeholder_' + slot);

        if (!imgEl || !phEl) return;

        const fn = scene[slot];

        if (fn && fn.trim()) {

            const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(fn);

            if (isVid) { imgEl.style.display='none'; phEl.style.display='flex'; phEl.innerHTML='🎬'; }

            else       { imgEl.src='podcast_images/'+fn+'?t='+Date.now(); imgEl.style.display='block'; phEl.style.display='none'; }

        } else {

            imgEl.style.display = 'none'; phEl.style.display = 'flex';

            phEl.innerHTML = slot === 'image_file' ? 'M' : slot.slice(-1);

        }

    });

}



function loadSlotPrompt(slotName) {

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;

    const map = {image_file:'prompt',image_file_1:'prompt_1',image_file_2:'prompt_2',image_file_3:'prompt_3',image_file_4:'prompt_4'};

    const ta = document.getElementById('slotPrompt');

    if (ta) ta.value = scene[map[slotName]] || '';

}



function showSlotPreview(slotName) { /* canvas updates automatically via selectImageSlot */ }



function uploadImageForSelectedSlot() {

    const inp = document.getElementById('slotImageUpload');

    if (inp) { inp.value = ''; inp.click(); }

}



async function handleSlotFileUpload(input) {

    if (!input.files || !input.files[0]) return;

    const file  = input.files[0];

    const slot  = selectedImageSlot || 'image_file';

    const isVid = file.type.startsWith('video/');

    const btn   = document.querySelector('#imageSelectorPanel button[onclick="uploadImageForSelectedSlot()"]');

    if (btn) btn.innerHTML = '<span style="font-size:18px;">⏳</span><span>Uploading…</span>';



    const fd = new FormData();

    fd.append('ajax_action', 'upload_scene_image');

    fd.append('scene_id',    currentSceneId);

    fd.append('image_field', slot);

    fd.append('media_type',  isVid ? 'video' : 'image');

    fd.append('scene_image', file);   // matches $_FILES['scene_image'] in PHP



    try {

        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const text = await resp.text();

        let data;

        try { data = JSON.parse(text); } catch(e) { throw new Error('Bad server response: ' + text.substring(0,100)); }

        if (!data.success) throw new Error(data.message || 'Upload failed');



        // Update local scene object

        const scene = scenes.find(s => s.id == currentSceneId);

        if (scene) { if (isVid) scene.video_file = data.filename; else scene[slot] = data.filename; }



        // Refresh slot thumbnail

        const imgEl = document.getElementById('slot_img_' + slot);

        const phEl  = document.getElementById('slot_placeholder_' + slot);

        if (imgEl && !isVid) {

            imgEl.src = 'podcast_images/' + data.filename + '?t=' + Date.now();

            imgEl.style.display = 'block';

            if (phEl) phEl.style.display = 'none';

        } else if (isVid && phEl) {

            phEl.innerHTML = '🎬'; phEl.style.display = 'flex';

        }



        // Reload canvas background

        await loadCurrentSceneToFabric();

        L('✅ ' + (isVid ? 'Video' : 'Image') + ' uploaded to ' + slot);



    } catch(e) {

        console.error('Upload error:', e);

        alert('Upload failed: ' + e.message);

    } finally {

        if (btn) btn.innerHTML = '<span style="font-size:18px;">📤</span><span>Upload</span>';

    }

}



function openLibraryForSelectedSlot() {

    openMediaLibForSlot(selectedImageSlot || 'image_file');

}



window._allLibFiles = [];

window._libSelectedFile = null;



async function loadLibraryFiles() {

    const grid = document.getElementById('libGrid');

    if (!grid) return;

    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8;">Loading…</div>';



    const fd = new FormData();

    fd.append('ajax_action', 'get_library_files');

    try {

        const resp = await fetch(location.href, { method:'POST', body:fd });

        const data = await resp.json();

        window._allLibFiles = data.files || [];

        const countEl = document.getElementById('libCount');

        if (countEl) countEl.textContent = window._allLibFiles.length + ' files';

        renderLibraryGrid(window._allLibFiles);

    } catch(e) {

        grid.innerHTML = '<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Error loading files: ' + e.message + '</div>';

    }

}



function renderLibraryGrid(files) {

    const grid = document.getElementById('libGrid');

    if (!grid) return;

    if (!files.length) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8;">No files found</div>'; return; }

    grid.innerHTML = files.map(f => {

        const isVid = /\.(mp4|webm|mov)$/i.test(f.filename);

        const thumb = isVid

            ? `<div style="width:100%;height:90px;background:#0f172a;display:flex;align-items:center;justify-content:center;font-size:32px;">🎬</div>`

            : `<img src="podcast_images/${f.filename}?t=1" style="width:100%;height:90px;object-fit:cover;display:block;" onerror="this.src='';this.parentElement.innerHTML='<div style=\'width:100%;height:90px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;\'>?</div>'">`;

        return `<div class="lib-item" data-filename="${f.filename}" onclick="selectLibItem(this,'${f.filename}')"

            style="border:2px solid #e2e8f0;border-radius:8px;overflow:hidden;cursor:pointer;transition:border-color 0.15s;">

            ${thumb}

            <div style="padding:4px 6px;font-size:10px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${f.filename}">${f.filename}</div>

        </div>`;

    }).join('');

}



function filterLibrary() {

    const q = (document.getElementById('libSearch')?.value || '').toLowerCase();

    const filtered = q ? window._allLibFiles.filter(f => f.filename.toLowerCase().includes(q) || (f.tags||'').toLowerCase().includes(q)) : window._allLibFiles;

    const countEl = document.getElementById('libCount');

    if (countEl) countEl.textContent = filtered.length + ' files';

    renderLibraryGrid(filtered);

}



function selectLibItem(el, filename) {

    document.querySelectorAll('.lib-item').forEach(x => x.style.borderColor = '#e2e8f0');

    el.style.borderColor = '#5fd1ff';

    window._libSelectedFile = filename;

    const selInfo = document.getElementById('libSelInfo');

    if (selInfo) selInfo.textContent = filename;

    const useBtn = document.getElementById('libUseBtn');

    if (useBtn) { useBtn.disabled = false; useBtn.style.opacity = '1'; }

}



async function useLibrarySelection() {

    if (!window._libSelectedFile || !currentSceneId) return;

    const slot = selectedImageSlot || 'image_file';

    const filename = window._libSelectedFile;



    const fd = new FormData();

    fd.append('ajax_action', 'assign_image');

    fd.append('scene_id',    currentSceneId);

    fd.append('filename',    filename);

    fd.append('image_field', slot);

    try {

        const resp = await fetch(location.href, { method:'POST', body:fd });

        const data = await resp.json();

        if (!data.success) throw new Error(data.message || 'Assign failed');



        // Update local scene

        const scene = scenes.find(s => s.id == currentSceneId);

        if (scene) scene[slot] = filename;



        // Refresh slot thumbnail in panel

        const imgEl = document.getElementById('slot_img_' + slot);

        const phEl  = document.getElementById('slot_placeholder_' + slot);

        if (imgEl) { imgEl.src = 'podcast_images/' + filename + '?t=' + Date.now(); imgEl.style.display = 'block'; if (phEl) phEl.style.display = 'none'; }



        await loadCurrentSceneToFabric();

        closeImageLibrary();

        L('✅ Image assigned: ' + filename);

    } catch(e) { alert('Failed: ' + e.message); }

}



function closeImageLibrary() {

    const modal = document.getElementById('imageLibraryModal');

    if (modal) modal.style.display = 'none';

    window._libSelectedFile = null;

}





async function generateImageForSelectedSlot() {

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;

    const slot = selectedImageSlot || 'image_file';

    const map  = {image_file:'prompt', image_file_1:'prompt_1', image_file_2:'prompt_2', image_file_3:'prompt_3', image_file_4:'prompt_4'};

    const ta   = document.getElementById('slotPrompt');

    const prompt = ta?.value.trim() || scene[map[slot]] || scene.prompt || '';

    if (!prompt) { alert('No prompt for this slot'); return; }



    const btn = document.querySelector('#imageSelectorPanel button[onclick="generateImageForSelectedSlot()"]');

    if (btn) btn.innerHTML = '<span style="font-size:18px;">⏳</span><span>Generating...</span>';



    const fd = new FormData();

    fd.append('ajax_action',     'generate_image');

    fd.append('scene_id',        currentSceneId);

    fd.append('image_field',     slot);

    fd.append('enhanced_prompt', prompt);

    fd.append('hashtags',        '');



    try {

        const {data} = await safeFetch(fd);



        if (data.success) {

            const filename = data.image_name || data.filename;

            if (!filename) { alert('No filename returned from server'); return; }



            // 1. Update scene object immediately

            scene[slot] = filename;



            // 2. Bust any preload cache for this file

            if (window.preloadCache) {

                delete window.preloadCache[filename];

            }



            // 3. Update the slot placeholder thumbnail directly

            const imgEl = document.getElementById('slot_img_' + slot);

            const phEl  = document.getElementById('slot_placeholder_' + slot);

            if (imgEl) {

                imgEl.src = '';  // clear first to force reload

                imgEl.style.display = 'none';

                // short delay then set new src

                setTimeout(() => {

                    imgEl.onload = function() {

                        imgEl.style.display = 'block';

                        if (phEl) phEl.style.display = 'none';

                    };

                    imgEl.onerror = function() {

                        console.warn('Thumbnail load failed for:', filename);

                        if (phEl) { phEl.style.display = 'flex'; phEl.innerHTML = '🖼️'; }

                    };

                    imgEl.src = 'podcast_images/' + filename + '?nocache=' + Date.now();

                }, 100);

            }



            // 4. Pre-load image into browser before putting on canvas

            await new Promise((resolve) => {

                const testImg = new Image();

                testImg.crossOrigin = 'anonymous';

                testImg.onload  = resolve;

                testImg.onerror = resolve; // don't block on error

                testImg.src = 'podcast_images/' + filename + '?nocache=' + Date.now();

                // safety timeout — 10 seconds max

                setTimeout(resolve, 10000);

            });



            // 5. Now reload canvas — image is in browser cache, use plain URL (no query string for Fabric)

            await setImageBackground('podcast_images/' + filename);

            

            // 6. Re-add captions on top

            fabricCanvas.getObjects().filter(o => o.captionId).forEach(o => fabricCanvas.remove(o));

            for (const key in sceneCaptions) {

                if (isNaN(key)) continue;

                const cap = sceneCaptions[key];

                if (cap.image_file && cap.image_file.trim()) {

                    await addImageBoxToCanvas(cap.id, cap.image_file, false);

                } else if (cap.text_content) {

                    await addMainCaptionToFabric(scene, cap);

                }

            }

            fabricCanvas.renderAll();



            L('✅ Image generated and displayed: ' + filename);



        } else {

            alert('Error: ' + (data.message || 'Generation failed'));

        }

    } catch(e) {

        console.error('generateImageForSelectedSlot error:', e);

        alert('Error: ' + e.message);

    } finally {

        if (btn) btn.innerHTML = '<span style="font-size:18px;">🔄</span><span>Generate</span>';

    }

}



// Music library stubs (full implementation in loadMusicLibrary / renderMusicGrid)

function filterMusicFiles() {

    const q = document.getElementById('musicSearchInput')?.value.toLowerCase() || '';

    document.querySelectorAll('#musicGrid .music-item').forEach(el => {

        el.style.display = el.dataset.filename?.toLowerCase().includes(q) ? '' : 'none';

    });

}

function selectMusicForPodcast() {

    if (!selectedMusicFile) return;

    podcastMusicFile = selectedMusicFile;

    updatePodcastAudioMusicDisplay();

    const fd = new FormData();

    fd.append('ajax_action', 'update_podcast_music');

    fd.append('podcast_id', <?= (int)$url_podcast_id ?>);

    fd.append('music_file', podcastMusicFile);

    safeFetch(fd).then(() => L('✅ Music updated: ' + podcastMusicFile)).catch(() => {});

    closeMusicLibrary();

}

async function loadMusicLibrary() {

    const grid = document.getElementById('musicGrid');

    const countEl = document.getElementById('musicCount');

    if (!grid) return;

    

    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">⏳ Loading music library...</div>';

    

    const fd = new FormData();

    fd.append('ajax_action', 'get_music_library');

    

    try {

        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const text = await resp.text();

        let data;

        try { data = JSON.parse(text); } 

        catch(e) { throw new Error('Server error: ' + text.substring(0, 200)); }

        

        const files = data.music_files || [];

        if (countEl) countEl.textContent = files.length + ' files';

        

        if (!files.length) {

            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">No music files found in podcast_music folder</div>';

            return;

        }

        

        // Store for filtering

        window._allMusicFiles = files;

        renderMusicGrid(files);

        

    } catch(e) {

        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:#ef4444;">Error: ' + e.message + '</div>';

    }

}



function renderMusicGrid(files) {

    const grid = document.getElementById('musicGrid');

    if (!grid) return;

    

    grid.innerHTML = files.map(f => {

        const mins = Math.floor((f.duration || 0) / 60);

        const secs = Math.floor((f.duration || 0) % 60);

        const durStr = f.duration ? `${mins}:${secs.toString().padStart(2,'0')}` : '--:--';

        const sizeStr = f.filesize ? (f.filesize / (1024*1024)).toFixed(1) + ' MB' : '';

        

        return `<div class="music-item" 

                     data-filename="${f.filename}"

                     onclick="selectMusicItem(this, '${f.filename}')"

                     style="background:white; border:2px solid #e2e8f0; border-radius:16px; padding:16px; cursor:pointer; transition:all 0.2s; display:flex; flex-direction:column; gap:8px;">

            <div style="display:flex; align-items:center; gap:10px;">

                <div style="width:44px; height:44px; background:linear-gradient(135deg,var(--sky-600),var(--sky-400)); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">🎵</div>

                <div style="flex:1; overflow:hidden;">

                    <div style="font-size:13px; font-weight:700; color:var(--sky-900); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${f.title || f.filename}</div>

                    <div style="font-size:11px; color:#64748b;">${f.artist || 'Unknown'} · ${durStr} ${sizeStr ? '· ' + sizeStr : ''}</div>

                </div>

            </div>

            <div style="display:flex; gap:8px;">

                <button onclick="previewMusicFile(event, '${f.file_url}')" 

                        style="flex:1; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:20px; padding:6px; font-size:12px; cursor:pointer;">

                    ▶ Preview

                </button>

            </div>

        </div>`;

    }).join('');

}



let selectedMusicFile = null;



function selectMusicItem(el, filename) {

    // Deselect all

    document.querySelectorAll('.music-item').forEach(x => {

        x.style.borderColor = '#e2e8f0';

        x.style.background  = 'white';

    });

    // Select this one

    el.style.borderColor = 'var(--sky-500)';

    el.style.background  = 'var(--sky-50)';

    

    selectedMusicFile = filename;

    

    const selInfo = document.getElementById('musicSelInfo');

    if (selInfo) selInfo.textContent = filename;

    

    const selBtn = document.getElementById('selectMusicBtn');

    if (selBtn) { selBtn.disabled = false; selBtn.style.opacity = '1'; }

}



function previewMusicFile(event, fileUrl) {

    event.stopPropagation(); // don't trigger selectMusicItem

    let player = document.getElementById('musicPreviewPlayer');

    if (!player) {

        player = document.createElement('audio');

        player.id = 'musicPreviewPlayer';

        player.style.display = 'none';

        document.body.appendChild(player);

    }

    if (player.src.includes(fileUrl) && !player.paused) {

        player.pause();

        return;

    }

    player.src = fileUrl;

    player.play().catch(e => console.warn('Preview failed:', e));

}



function closeMusicLibrary() {

    const modal = document.getElementById('musicLibraryModal');

    if (modal) modal.style.display = 'none';

    // Stop any preview

    const player = document.getElementById('musicPreviewPlayer');

    if (player) { player.pause(); }

    selectedMusicFile = null;

}



function filterMusicFiles() {

    const q = document.getElementById('musicSearchInput')?.value.toLowerCase() || '';

    const files = (window._allMusicFiles || []).filter(f => 

        (f.filename || '').toLowerCase().includes(q) || 

        (f.title    || '').toLowerCase().includes(q) ||

        (f.artist   || '').toLowerCase().includes(q)

    );

    const countEl = document.getElementById('musicCount');

    if (countEl) countEl.textContent = files.length + ' files';

    renderMusicGrid(files);

}

document.addEventListener('DOMContentLoaded', function() {

    updateSceneIndicator();

    // Give aspect-ratio layout time to settle before reading container size

    setTimeout(initFabricCanvas, 250);

});



// Close any named overlay panel

function closeOverlay(id) {

    const el = document.getElementById(id);

    if (el) el.style.display = 'none';

}

function autoSelectFirstCaption() {

    if (!fabricCanvas) return;

    

    // Try to find 'main' caption first, then any text caption

    const allObjs = fabricCanvas.getObjects();

    

    // First priority: caption with type 'text' or named 'main'

    let target = allObjs.find(o => o.captionId && o.captionType === 'text');

    

    // Fallback: any object with a captionId

    if (!target) target = allObjs.find(o => o.captionId);

    

    if (target) {

        // Enable selection mode so it can be selected

        setSelectionMode(true);

        fabricCanvas.setActiveObject(target);

        fabricCanvas.renderAll();

        console.log('✅ Auto-selected caption:', target.captionId, target.captionType);

    } else {

        console.warn('⚠️ No captions found on canvas to auto-select');

    }

}

// These are placeholders — full implementations to be added next

function showTypographyIcons() {

    const el = document.getElementById('textIcons');

    const pri = document.getElementById('primaryIcons');

    if (el) { el.style.display = el.style.display === 'none' ? 'flex' : 'none'; }

    if (pri) pri.style.display = 'none';

    

    // Auto-select first caption on canvas

    autoSelectFirstCaption();

}

function hideTextIcons() {

    const el = document.getElementById('textIcons');

    const pri = document.getElementById('primaryIcons');

    if (el) el.style.display = 'none';

    if (pri) pri.style.display = 'flex';

}

function showAudioIcons() {

    const pri = document.getElementById('primaryIcons');

    const aud = document.getElementById('audioIcons');

    if (pri) pri.style.display = 'none';

    if (aud) aud.style.display = 'flex';

    closeAllOverlays();

}



function hideAudioIcons() {

    const pri = document.getElementById('primaryIcons');

    const aud = document.getElementById('audioIcons');

    if (aud) aud.style.display = 'none';

    if (pri) pri.style.display = 'flex';

    closeAllOverlays();

}



function showCurrentSceneAudioOverlay(event) {

    if (!currentSceneId) { alert('No scene selected'); return; }

    closeAllOverlays();

    const overlay = document.getElementById('currentSceneAudioOverlay');

    positionOverlayNearIcon(overlay, event?.currentTarget, 330, 420);

    loadCurrentSceneAudioData(currentSceneId);

}



function showPodcastAudioOverlay(event) {

    closeAllOverlays();

    const overlay = document.getElementById('podcastAudioOverlay');

    positionOverlayNearIcon(overlay, event?.currentTarget, 330, 480);

    updatePodcastAudioMusicDisplay();

}

function showImageSelectorOverlay(event) {

    if (!currentSceneId) { alert('No scene selected'); return; }

    closeAllOverlays();



    const overlay = document.getElementById('imageSelectorPanel');

    if (!overlay) { console.error('imageSelectorPanel not found'); return; }



    loadImageSlots();

    positionOverlayNearIcon(overlay, event?.currentTarget, 360, 420);

    selectImageSlot(currentImageField || 'image_file');

}



// ── Current scene audio ───────────────────────────────────────

function loadCurrentSceneAudioData(sceneId) {

    const scene = scenes.find(s => s.id == sceneId);

    if (!scene) return;

    const textDisplay = document.getElementById('currentSceneAudioTextDisplay');

    if (textDisplay) {

        const t = scene.text_contents || '';

        textDisplay.innerHTML = `

            <textarea id="currentSceneAudioText"

                style="width:100%;min-height:70px;padding:8px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:12px;resize:vertical;background:white;"

                placeholder="Scene text...">${escapeHtml(t)}</textarea>

            <div style="display:flex;justify-content:space-between;margin-top:4px;">

                <span style="font-size:11px;color:var(--info);background:#e0f2fe;padding:2px 8px;border-radius:12px;">🎤 ${scene.voice_id||'No voice set'}</span>

                <span id="currentSceneTextCharCount" style="font-size:11px;color:var(--muted);">${t.length} chars</span>

            </div>`;

        const ta = document.getElementById('currentSceneAudioText');

        if (ta) ta.addEventListener('input', function() {

            document.getElementById('currentSceneTextCharCount').textContent = this.value.length + ' chars';

            clearTimeout(window._sceneTextTimeout);

            window._sceneTextTimeout = setTimeout(() => saveCurrentSceneAudioText(sceneId, this.value), 1000);

        });

    }

    const ac = document.getElementById('currentSceneAudioPlayerContainer');

    if (ac) {

        if (scene.audio_file) createCurrentSceneAudioPlayer(sceneId, scene.audio_file);

        else ac.innerHTML = '<div style="color:var(--muted);text-align:center;padding:16px;background:#f8fafc;border-radius:8px;">🎵 No audio yet</div>';

    }

}



function createCurrentSceneAudioPlayer(sceneId, filename) {

    const container = document.getElementById('currentSceneAudioPlayerContainer');

    if (!container) return;

    container.innerHTML = '';

    const wrap = document.createElement('div');

    wrap.style.cssText = 'display:flex;align-items:center;gap:10px;background:#f8fafc;border-radius:60px;padding:8px 14px;border:1px solid var(--border);';

    const playBtn = document.createElement('button');

    playBtn.style.cssText = 'width:40px;height:40px;background:var(--purple);color:white;border:none;border-radius:50%;cursor:pointer;font-size:15px;flex-shrink:0;display:flex;align-items:center;justify-content:center;';

    playBtn.innerHTML = '▶';

    const timeEl = document.createElement('span');

    timeEl.style.cssText = 'font-size:11px;color:var(--muted);min-width:70px;text-align:center;';

    timeEl.textContent = '0:00 / 0:00';

    const progWrap = document.createElement('div');

    progWrap.style.cssText = 'flex-grow:1;height:5px;background:var(--border);border-radius:3px;cursor:pointer;';

    const progFill = document.createElement('div');

    progFill.style.cssText = 'height:100%;background:var(--purple);border-radius:3px;width:0%;';

    progWrap.appendChild(progFill);

    const audio = document.createElement('audio');

    audio.src = 'podcast_audios/' + filename + '?t=' + Date.now();

    audio.preload = 'metadata';

    audio.onloadedmetadata = () => timeEl.textContent = '0:00 / ' + formatTime(audio.duration);

    audio.ontimeupdate = () => {

        const pct = (audio.currentTime / audio.duration) * 100 || 0;

        progFill.style.width = pct + '%';

        timeEl.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(audio.duration);

    };

    audio.onended = () => { playBtn.innerHTML = '▶'; progFill.style.width = '0%'; };

    playBtn.onclick = () => {

        if (audio.paused) { audio.play(); playBtn.innerHTML = '⏸'; }

        else              { audio.pause(); playBtn.innerHTML = '▶'; }

    };

    progWrap.onclick = e => {

        const r = progWrap.getBoundingClientRect();

        audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;

    };

    wrap.append(playBtn, timeEl, progWrap, audio);

    container.appendChild(wrap);

}



async function saveCurrentSceneAudioText(sceneId, text) {

    const scene = scenes.find(s => s.id == sceneId);

    if (!scene || scene.text_contents === text) return;

    const fd = new FormData();

    fd.append('ajax_action', 'save_scene_text');

    fd.append('scene_id',    sceneId);

    fd.append('text_contents', text);

    try { const {data} = await safeFetch(fd); if (data.success) scene.text_contents = text; } catch(e) {}

}



async function generateCurrentAudioFromOverlay() {

    if (!currentSceneId) { alert('No scene selected'); return; }

    const scene = scenes.find(s => s.id == currentSceneId);

    if (!scene) return;

    const ta = document.getElementById('currentSceneAudioText');

    const text = (ta ? ta.value : scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();

    if (!text) { alert('No text for this scene'); return; }

    const voiceSel = document.getElementById('podcastAudioOverlayVoiceSelect');

    const voiceId  = (voiceSel && voiceSel.value) ? voiceSel.value : (scene.voice_id || '');

    if (!voiceId) { alert('No voice selected. Click 🔊 → 🎤 and pick a voice first.'); return; }

    const btn = document.querySelector('#currentSceneAudioOverlay .panel-btn');

    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Generating…'; }



    const podcastId = <?= (int)$url_podcast_id ?>;

    const langCode  = '<?= $podcast_lang_code ?>';

    const filename  = `voice_${podcastId}_${currentSceneId}_${langCode}.mp3`;



    const fd = new FormData();

    fd.append('row_id',    currentSceneId);

    fd.append('text',      text);

    fd.append('lang_code', langCode);

    fd.append('voice_id',  voiceId);

    fd.append('rate',      '1.0');

    fd.append('filename',  filename);

    try {

        const resp = await fetch('generate_voice.php', { method:'POST', body:fd });

        const data = await resp.json();

        if (data.success) {

            const fn = data.filename || filename;

            scene.audio_file = fn;

            if (typeof audio_files !== 'undefined') audio_files[currentSceneId] = fn;

            // Save to DB

            const fd2 = new FormData();

            fd2.append('ajax_action', 'save_scene_audio_file');

            fd2.append('scene_id',    currentSceneId);

            fd2.append('audio_file',  fn);

            safeFetch(fd2).catch(()=>{});

            createCurrentSceneAudioPlayer(currentSceneId, fn);

            L('✅ Audio generated: ' + fn);

        } else { alert('Error: ' + (data.message || data.error || 'Unknown')); }

    } catch(e) { alert('Error: ' + e.message); }

    finally { if (btn) { btn.disabled = false; btn.innerHTML = '🔄 Generate Audio'; } }

}



// ── Podcast-wide audio helpers ────────────────────────────────

function updatePodcastAudioMusicDisplay() {

    const musicInfo   = document.getElementById('podcastAudioMusicFileName');

    const musicPlayer = document.getElementById('podcastAudioMusicPlayerContainer');

    const audioPlayer = document.getElementById('podcastAudioMusicPlayer');

    if (!musicInfo) return;

    if (podcastMusicFile && podcastMusicFile.trim()) {

        musicInfo.textContent = podcastMusicFile;

        if (musicPlayer) musicPlayer.style.display = 'block';

        if (audioPlayer) { audioPlayer.src = 'podcast_music/' + podcastMusicFile + '?t=' + Date.now(); audioPlayer.load(); }

    } else {

        musicInfo.textContent = 'No music selected';

        if (musicPlayer) musicPlayer.style.display = 'none';

    }

}



function playPodcastVoiceSampleOverlay() {

    const sel = document.getElementById('podcastAudioOverlayVoiceSelect');

    if (!sel || !sel.value) { alert('Select a voice first'); return; }

    const opt = sel.options[sel.selectedIndex];

    const sampleUrl = opt?.getAttribute('data-sample');

    if (!sampleUrl) { alert('No sample available for this voice'); return; }

    let a = document.getElementById('sampleAudioPlayer');

    if (!a) { a = document.createElement('audio'); a.id = 'sampleAudioPlayer'; a.style.display='none'; document.body.appendChild(a); }

    a.src = sampleUrl;

    a.play().catch(() => alert('Could not play sample'));

}



function clearBackgroundMusicOverlay() {

    if (!confirm('Remove background music?')) return;

    podcastMusicFile = '';

    updatePodcastAudioMusicDisplay();

    const fd = new FormData();

    fd.append('ajax_action', 'update_podcast_music');

    fd.append('podcast_id',  <?= (int)$url_podcast_id ?>);

    fd.append('music_file',  '');

    safeFetch(fd).catch(() => {});

}



function openMusicLibraryFromOverlay() {

    closeOverlay('podcastAudioOverlay');

    const modal = document.getElementById('musicLibraryModal');

    if (modal) { modal.style.display = 'flex'; loadMusicLibrary(); }

    else L('🎵 Music library modal not found');

}



function uploadBackgroundMusicFromOverlay() {

    closeOverlay('podcastAudioOverlay');

    const inp = document.getElementById('musicUploadInput');

    if (inp) inp.click();

}



async function generateAllAudioWithSelectedVoiceOverlay() {

    const sel = document.getElementById('podcastAudioOverlayVoiceSelect');

    if (!sel || !sel.value) { alert('Select a voice first'); return; }

    if (!confirm('Generate audio for ALL ' + scenes.length + ' scenes with this voice?')) return;

    closeAllOverlays();

    const voiceId   = sel.value;

    const podcastId = <?= (int)$url_podcast_id ?>;

    const langCode  = '<?= $podcast_lang_code ?>';

    let done = 0, fail = 0;

    for (const scene of scenes) {

        const text = (scene.text_contents || '').replace(/<break[^>]*>/gi,'').trim();

        if (!text) continue;

        const filename = `voice_${podcastId}_${scene.id}_${langCode}.mp3`;

        const fd = new FormData();

        fd.append('row_id',    scene.id);

        fd.append('text',      text);

        fd.append('lang_code', langCode);

        fd.append('voice_id',  voiceId);

        fd.append('rate',      '1.0');

        fd.append('filename',  filename);

        try {

            const resp = await fetch('generate_voice.php', { method:'POST', body:fd });

            const data = await resp.json();

            if (data.success) {

                const fn = data.filename || filename;

                scene.audio_file = fn;

                if (typeof audio_files !== 'undefined') audio_files[scene.id] = fn;

                // Save to DB

                const fd2 = new FormData();

                fd2.append('ajax_action', 'save_scene_audio_file');

                fd2.append('scene_id',    scene.id);

                fd2.append('audio_file',  fn);

                safeFetch(fd2).catch(()=>{});

                done++; L('✅ Scene ' + scene.seq_no + ': ' + fn);

            } else { fail++; L('❌ Scene ' + scene.seq_no + ': ' + (data.message||data.error||'failed')); }

        } catch(e) { fail++; L('❌ Scene ' + scene.seq_no + ': ' + e.message); }

    }

    alert('Done! ' + done + ' audio files generated' + (fail ? ', ' + fail + ' failed.' : '.'));

}



// ── Typography helper: get active caption from canvas ─────────

function getActiveCaptionObj() {

    if (!fabricCanvas) return null;

    

    // 1. Check if something is already actively selected

    const active = fabricCanvas.getActiveObject();

    if (active && active.captionId) return active;

    

    // 2. Fall back to first text caption on canvas

    const objs = fabricCanvas.getObjects().filter(o => o.captionId && o.type === 'textbox');

    if (objs.length) {

        // Auto-set it as active so future operations work

        fabricCanvas.setActiveObject(objs[0]);

        fabricCanvas.renderAll();

        return objs[0];

    }

    

    // 3. Any caption at all (image captions etc)

    const any = fabricCanvas.getObjects().find(o => o.captionId);

    if (any) {

        fabricCanvas.setActiveObject(any);

        fabricCanvas.renderAll();

        return any;

    }

    

    return null;

}



function applyToCaption(property, value) {

    const obj = getActiveCaptionObj();

    if (!obj) { L('⚠️ No caption selected'); return; }

    

    // Map Fabric property names to DB column names

    const dbMap = {

        'fill':            'fontcolor',

        'fontFamily':      'fontfamily',

        'fontSize':        'fontsize',

        'fontWeight':      'fontweight',

        'fontStyle':       'fontstyle',

        'textAlign':       'text_align',

        'backgroundColor': 'bg_color',

        'underline':       'underline',

        'linethrough':     'linethrough',

    };

    

    obj.set(property, value);

    fabricCanvas.renderAll();

    

    const dbColumn = dbMap[property] || property;

    autoSaveCaptionProperty(obj, dbColumn, value);

}

// ── Scene Transition Effects ──────────────────────────────────

let currentTransitionStyle = 'fade'; // default transition



const transitionStyles = ['fade', 'slide-left', 'slide-right', 'zoom-out', 'blur', 'wipe'];



async function playTransition(type) {

    if (!type || type === 'none') { return; }

    

    return new Promise(resolve => {

        const container = document.getElementById('canvasContainer');

        if (!container) { resolve(); return; }



        const old = document.getElementById('_transitionOverlay');

        if (old) old.remove();



        const overlay = document.createElement('div');

        overlay.id = '_transitionOverlay';

        overlay.style.cssText = `

            position: absolute;

            top: 0; left: 0;

            width: 100%; height: 100%;

            z-index: 5000;

            pointer-events: none;

            background: black;

            opacity: 0;

        `;

        container.appendChild(overlay);



        switch (type) {



            case 'fade': {

                overlay.style.transition = 'opacity 0.4s ease';

                overlay.style.background = 'black';

                requestAnimationFrame(() => {

                    overlay.style.opacity = '1';

                    setTimeout(() => {

                        overlay.style.opacity = '0';

                        setTimeout(() => { overlay.remove(); resolve(); }, 400);

                    }, 300);

                });

                break;

            }



            case 'slide-left': {

                overlay.style.background = 'black';

                overlay.style.transform = 'translateX(100%)';

                overlay.style.opacity = '1';

                overlay.style.transition = 'transform 0.4s ease';

                requestAnimationFrame(() => {

                    overlay.style.transform = 'translateX(0%)';

                    setTimeout(() => {

                        overlay.style.transform = 'translateX(-100%)';

                        setTimeout(() => { overlay.remove(); resolve(); }, 400);

                    }, 300);

                });

                break;

            }



            case 'slide-right': {

                overlay.style.background = 'black';

                overlay.style.transform = 'translateX(-100%)';

                overlay.style.opacity = '1';

                overlay.style.transition = 'transform 0.4s ease';

                requestAnimationFrame(() => {

                    overlay.style.transform = 'translateX(0%)';

                    setTimeout(() => {

                        overlay.style.transform = 'translateX(100%)';

                        setTimeout(() => { overlay.remove(); resolve(); }, 400);

                    }, 300);

                });

                break;

            }



            case 'zoom-out': {

                overlay.style.background = 'black';

                overlay.style.opacity = '0';

                overlay.style.transform = 'scale(1.5)';

                overlay.style.transition = 'opacity 0.3s ease, transform 0.4s ease';

                requestAnimationFrame(() => {

                    overlay.style.opacity = '1';

                    overlay.style.transform = 'scale(1)';

                    setTimeout(() => {

                        overlay.style.opacity = '0';

                        setTimeout(() => { overlay.remove(); resolve(); }, 300);

                    }, 350);

                });

                break;

            }



            case 'blur': {

                const canvasEl = document.getElementById('fabricCanvas');

                const vid = document.getElementById('backgroundVideo');

                if (canvasEl) canvasEl.style.transition = 'filter 0.3s ease';

                if (vid) vid.style.transition = 'filter 0.3s ease';

                requestAnimationFrame(() => {

                    if (canvasEl) canvasEl.style.filter = 'blur(10px)';

                    if (vid) vid.style.filter = 'blur(10px)';

                    setTimeout(() => {

                        if (canvasEl) canvasEl.style.filter = 'blur(0px)';

                        if (vid) vid.style.filter = 'blur(0px)';

                        setTimeout(() => { overlay.remove(); resolve(); }, 300);

                    }, 350);

                });

                break;

            }



            case 'wipe': {

                overlay.style.background = 'linear-gradient(90deg, black 50%, transparent 50%)';

                overlay.style.opacity = '1';

                overlay.style.transform = 'translateX(-100%)';

                overlay.style.transition = 'transform 0.4s ease';

                requestAnimationFrame(() => {

                    overlay.style.transform = 'translateX(0%)';

                    setTimeout(() => {

                        overlay.style.transform = 'translateX(100%)';

                        setTimeout(() => { overlay.remove(); resolve(); }, 400);

                    }, 300);

                });

                break;

            }



            case 'none':

            default: {

                overlay.remove();

                resolve();

                break;

            }

        }

    });

}

// Apply to ALL caption objects on current scene (and optionally all scenes)

function applyToAllCaptions(property, value, allScenes) {

    fabricCanvas.getObjects().filter(o => o.captionId && o.type === 'textbox').forEach(obj => {

        obj.set(property, value);

        autoSaveCaptionProperty(obj, property, value);

    });

    fabricCanvas.renderAll();

    if (allScenes) {

        // save to DB for every caption in every scene

        const fd = new FormData();

        fd.append('ajax_action', 'update_main_caption_styling');

        // Will be called per-scene next time they load — handled by autoSave

    }

}



function shouldApplyToAll(panelId) {

    const chk = document.getElementById('applyAllChk_' + panelId);

    return chk && chk.checked;

}



// ── Panel open helpers ─────────────────────────────────────────

function openPanel(panelId, width) {

    closeAllOverlays();

    const panel = document.getElementById(panelId);

    if (!panel) { L('Panel not found: ' + panelId); return; }

    // Position inside canvas near icon container

    panel.style.position = 'absolute';

    panel.style.top      = '70px';

    panel.style.left     = '60px';

    panel.style.width    = (width || 280) + 'px';

    panel.style.display  = 'block';

    panel.style.zIndex   = '10000';

}



// ── Font Family ───────────────────────────────────────────────

function showFontFamilyPanel() {

    openPanel('fontFamilyPanel', 280);

    // Highlight current font

    const obj = getActiveCaptionObj();

    if (!obj) return;

    document.querySelectorAll('#fontFamilyPanel .font-option').forEach(el => {

        el.style.background = el.innerText.trim() === obj.fontFamily ? 'var(--purple-lt, #ede9fe)' : '';

        el.style.fontWeight = el.innerText.trim() === obj.fontFamily ? '700' : '';

    });

}



function setFontFamily(family) {

    applyToCaption('fontFamily', family);

    if (shouldApplyToAll('fontFamilyPanel')) {

        applyToAllCaptions('fontFamily', family);

        // persist to all captions in DB for this podcast

        sceneCaptions && Object.values(sceneCaptions).forEach(cap => {

            if (cap && cap.id) {

                const fd = new FormData(); fd.append('ajax_action','save_caption'); fd.append('caption_id',cap.id); fd.append('fontfamily',family);

                safeFetch(fd).catch(()=>{});

            }

        });

    }

    document.querySelectorAll('#fontFamilyPanel .font-option').forEach(el => {

        el.style.background = el.innerText.trim() === family ? 'var(--purple-lt, #ede9fe)' : '';

        el.style.fontWeight = el.innerText.trim() === family ? '700' : '';

    });

}



// ── Font Size ─────────────────────────────────────────────────

function showFontSizeOptions() {

    openPanel('fontSizePanel', 280);

    const obj = getActiveCaptionObj();

    if (!obj) return;

    const sz = obj.fontSize || 28;

    const numEl = document.getElementById('fontSizeNumber');

    const slider = document.getElementById('fontSizeSlider');

    if (numEl) numEl.textContent = sz;

    if (slider) slider.value = sz;

}



function setFontSizeValue(size) {

    applyToCaption('fontSize', size);

    const numEl = document.getElementById('fontSizeNumber');

    const slider = document.getElementById('fontSizeSlider');

    if (numEl) numEl.textContent = size;

    if (slider) slider.value = size;

}



function increaseFontSize() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    setFontSizeValue(Math.min((obj.fontSize || 28) + 2, 120));

}

function decreaseFontSize() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    setFontSizeValue(Math.max((obj.fontSize || 28) - 2, 8));

}

function handleFontSizeSlider(val) { setFontSizeValue(parseInt(val)); }



// ── Text Color ────────────────────────────────────────────────

function showTextColorOptions() {

    openPanel('fontColorOverlay', 280);

    const obj = getActiveCaptionObj();

    const picker = document.getElementById('textColorPicker');

    if (picker && obj) picker.value = obj.fill || '#ffffff';

}



function setTextColor(color) {

    const obj = getActiveCaptionObj();

    if (!obj) return;

    obj.set('fill', color);

    fabricCanvas.renderAll();

    // Save with correct DB column name 'fontcolor' not 'fill'

    autoSaveCaptionProperty(obj, 'fontcolor', color);

    const picker = document.getElementById('textColorPicker');

    if (picker) picker.value = color;

}



function previewTextColor(color) { 

    setTextColor(color); 

}



// ── Background Color ──────────────────────────────────────────

function showBgColorOptions(e) {

    openPanel('bgColorPanel', 280);

    const obj = getActiveCaptionObj();

    const picker = document.getElementById('bgColorPicker');

    const chk = document.getElementById('enableBgCheckbox');

    if (obj) {

        const bg = obj.backgroundColor || '#000000';

        if (picker) picker.value = bg === 'transparent' ? '#000000' : bg;

        if (chk) chk.checked = (obj.backgroundColor && obj.backgroundColor !== 'transparent');

    }

}



function setBgColor(color) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    obj.set('backgroundColor', color);

    fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'bg_color', color);

    autoSaveCaptionProperty(obj, 'bg_enabled', 1);

    const chk = document.getElementById('enableBgCheckbox');

    if (chk) chk.checked = true;

    const picker = document.getElementById('bgColorPicker');

    if (picker) picker.value = color;

}

function previewBgColor(color) { setBgColor(color); }

function toggleBgEnable(enabled) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    obj.set('backgroundColor', enabled ? (document.getElementById('bgColorPicker')?.value || '#000000') : 'transparent');

    fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'bg_enabled', enabled ? 1 : 0);

}



// ── Style (Bold / Italic / Underline) ─────────────────────────

function showStyleOptions() { openPanel('stylePanel', 240); syncStyleIndicators(); }



function syncStyleIndicators() {

    const obj = getActiveCaptionObj();

    if (!obj) return;

    const isBold      = obj.fontWeight === 'bold' || obj.fontWeight === '700';

    const isItalic    = obj.fontStyle  === 'italic';

    const isUnderline = !!obj.underline;

    const set = (id, active) => {

        const el = document.getElementById(id);

        if (el) { el.style.border = active ? '2px solid var(--purple)' : '2px solid transparent'; el.style.background = active ? 'var(--purple-lt,#ede9fe)' : '#f8fafc'; }

        const ind = document.getElementById(id.replace('Btn','Indicator'));

        if (ind) ind.style.background = active ? 'var(--success,#10b981)' : 'transparent';

    };

    set('styleBoldBtn', isBold);

    set('styleItalicBtn', isItalic);

    set('styleUnderlineBtn', isUnderline);

}



function toggleTextStyle(style) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    if (style === 'bold') {

        const newVal = (obj.fontWeight === 'bold' || obj.fontWeight === '700') ? 'normal' : 'bold';

        obj.set('fontWeight', newVal);

        autoSaveCaptionProperty(obj, 'fontweight', newVal);

    } else if (style === 'italic') {

        const newVal = obj.fontStyle === 'italic' ? 'normal' : 'italic';

        obj.set('fontStyle', newVal);

        autoSaveCaptionProperty(obj, 'fontstyle', newVal);

    } else if (style === 'underline') {

        const newVal = !obj.underline;

        obj.set('underline', newVal);

        autoSaveCaptionProperty(obj, 'underline', newVal ? 1 : 0);

    }

    fabricCanvas.renderAll();

    syncStyleIndicators();

}



// ── Alignment ─────────────────────────────────────────────────

function showAlignment() { openPanel('alignmentPanel', 240); }



function setTextAlignment(align) {

    applyToCaption('textAlign', align);

    autoSaveCaptionProperty(getActiveCaptionObj(), 'text_align', align);

    // highlight active button

    ['left','center','right','justify'].forEach(a => {

        const el = document.getElementById('align' + a.charAt(0).toUpperCase() + a.slice(1));

        if (el) el.style.border = a === align ? '2px solid var(--purple)' : '2px solid transparent';

    });

}



// ── Effects ───────────────────────────────────────────────────

function showEffects() { openPanel('effectsPanel', 320); }



function toggleEffect(type) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    if (type === 'shadow') {

        const en = document.getElementById('shadowEnable')?.checked;

        document.getElementById('shadowControls').style.display = en ? 'block' : 'none';

        if (!en) { obj.set('shadow', null); fabricCanvas.renderAll(); autoSaveCaptionProperty(obj, 'shadow', ''); }

        else updateShadow();

    } else if (type === 'outline') {

        const en = document.getElementById('outlineEnable')?.checked;

        document.getElementById('outlineControls').style.display = en ? 'block' : 'none';

        if (!en) { obj.set({stroke:null,strokeWidth:0}); fabricCanvas.renderAll(); autoSaveCaptionProperty(obj,'outline_enabled',0); }

        else updateOutline();

    } else if (type === 'stroke') {

        const en = document.getElementById('strokeEnable')?.checked;

        document.getElementById('strokeControls').style.display = en ? 'block' : 'none';

        if (!en) { obj.set({stroke:null,strokeWidth:0}); fabricCanvas.renderAll(); autoSaveCaptionProperty(obj,'stroke_enabled',0); }

        else updateStroke();

    } else if (type === 'glow') {

        const en = document.getElementById('glowEnable')?.checked;

        document.getElementById('glowControls').style.display = en ? 'block' : 'none';

        if (!en) { obj.set('shadow', null); fabricCanvas.renderAll(); }

        else updateGlow();

    }

}



function updateShadow() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    const color = document.getElementById('shadowColor')?.value || '#000000';

    const blur  = document.getElementById('shadowBlur')?.value  || 10;

    const offX  = document.getElementById('shadowOffsetX')?.value || 5;

    const offY  = document.getElementById('shadowOffsetY')?.value || 5;

    document.getElementById('shadowBlurValue').textContent   = blur;

    document.getElementById('shadowOffsetXValue').textContent = offX;

    document.getElementById('shadowOffsetYValue').textContent = offY;

    obj.set('shadow', new fabric.Shadow({ color, blur:+blur, offsetX:+offX, offsetY:+offY }));

    fabricCanvas.renderAll();

}



function updateGlow() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    const color = document.getElementById('glowColor')?.value || '#ffff00';

    const blur  = parseInt(document.getElementById('glowBlur')?.value || 15);

    document.getElementById('glowBlurValue').textContent = blur;

    obj.set('shadow', new fabric.Shadow({ color, blur, offsetX:0, offsetY:0 }));

    fabricCanvas.renderAll();

}



function updateOutline() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    const color = document.getElementById('outlineColor')?.value || '#000000';

    const width = parseInt(document.getElementById('outlineWidth')?.value || 2);

    document.getElementById('outlineWidthValue').textContent = width;

    obj.set({ stroke:color, strokeWidth:width, paintFirst:'stroke' });

    fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'outline_enabled', 1);

    autoSaveCaptionProperty(obj, 'outline_color', color);

    autoSaveCaptionProperty(obj, 'outline_width', width);

}



function updateStroke() {

    const obj = getActiveCaptionObj(); if (!obj) return;

    const color = document.getElementById('strokeColor')?.value || '#ffffff';

    const width = parseInt(document.getElementById('strokeWidth')?.value || 2);

    const pos   = document.getElementById('strokePosition')?.value || 'fill';

    document.getElementById('strokeWidthValue').textContent = width;

    obj.set({ stroke:color, strokeWidth:width, paintFirst:pos });

    fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'stroke_enabled', 1);

    autoSaveCaptionProperty(obj, 'stroke_color', color);

    autoSaveCaptionProperty(obj, 'stroke_width', width);

}



function updateGradient() { /* fabric.js gradient on text is complex — skip for now */ }

function updateEffect3D() { /* stub */ }



// ── Position ──────────────────────────────────────────────────

function showPosition() { openPanel('positionPanel', 260); }



function setVerticalPosition(pos) {

    const obj = getActiveCaptionObj(); if (!obj || !fabricCanvas) return;

    const cH = fabricCanvas.height;

    const h  = obj.height || 50;

    const newTop = pos === 'top' ? 20 : pos === 'bottom' ? cH - h - 20 : (cH - h) / 2;

    obj.set('top', newTop); obj.setCoords(); fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'position_y', Math.round(newTop));

}



function setHorizontalPosition(pos) {

    const obj = getActiveCaptionObj(); if (!obj || !fabricCanvas) return;

    const cW = fabricCanvas.width;

    const w  = obj.width || 200;

    const newLeft = pos === 'left' ? 20 : pos === 'right' ? cW - w - 20 : (cW - w) / 2;

    obj.set('left', newLeft); obj.setCoords(); fabricCanvas.renderAll();

    autoSaveCaptionProperty(obj, 'position_x', Math.round(newLeft));

}



function setCenterPosition() { setVerticalPosition('center'); setHorizontalPosition('center'); }



// ── Animation ─────────────────────────────────────────────────

function showAnimation() { openPanel('animationPanel', 320); }



function setAnimationStyle(style) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    obj.animationStyle = style;

    autoSaveCaptionProperty(obj, 'animation_style', style);

    document.querySelectorAll('.anim-btn').forEach(el => {

        el.style.border = el.id === 'anim' + style.charAt(0).toUpperCase() + style.slice(1) ? '2px solid var(--purple)' : '2px solid transparent';

    });

}



function previewAnimSpeed(val) {

    const obj = getActiveCaptionObj(); if (!obj) return;

    obj.animationSpeed = parseFloat(val);

    autoSaveCaptionProperty(obj, 'animation_speed', parseFloat(val));

    const disp = document.getElementById('animSpeedDisplay');

    if (disp) disp.textContent = parseFloat(val).toFixed(1) + 'x';

}



// ── Add / Delete Caption ──────────────────────────────────────

async function addNewCaption() {

    if (!currentSceneId) return;

    const fd = new FormData();

    fd.append('ajax_action', 'create_caption');

    fd.append('story_id',    currentSceneId);

    fd.append('podcast_id',  <?= (int)$url_podcast_id ?>);

    fd.append('caption_type','text');

    try {

        const {data} = await safeFetch(fd);

        if (data.success && data.caption_data) {

            const cap = data.caption_data;

            sceneCaptions[cap.id] = cap;

            await addMainCaptionToFabric(scenes.find(s=>s.id==currentSceneId), cap);

            setSelectionMode(true);

            L('✅ Caption added');

        } else { alert('Error: ' + (data.message||'Failed')); }

    } catch(e) { alert('Error: ' + e.message); }

}



async function deleteSelectedCaption() {

    const obj = getActiveCaptionObj();

    if (!obj || !obj.captionId) { alert('No caption selected'); return; }

    if (!confirm('Delete this caption?')) return;

    const fd = new FormData();

    fd.append('ajax_action', 'delete_caption');

    fd.append('caption_id',  obj.captionId);

    try {

        const {data} = await safeFetch(fd);

        if (data.success) {

            fabricCanvas.remove(obj);

            delete sceneCaptions[obj.captionId];

            fabricCanvas.renderAll();

            L('✅ Caption deleted');

        }

    } catch(e) { alert('Error: ' + e.message); }

}



// ── Image caption ─────────────────────────────────────────────

function addImageCaption(event) {

    // Show the imageSourcePanel (choose library or upload)

    closeAllOverlays();

    const overlay = document.getElementById('imageSourcePanel');

    positionOverlayNearIcon(overlay, event?.currentTarget, 280, 220);

}



async function openStickerLibraryForNewBox() {

    // Reuse the image library modal, but on selection create an image caption

    window._imageCaptionMode = true;

    openLibraryForSelectedSlot(); // opens existing library modal

    // Override the "Use This" button to create a caption instead of assigning to slot

    const useBtn = document.getElementById('libUseBtn');

    if (useBtn) {

        useBtn.onclick = async function() {

            if (!window._libSelectedFile) return;

            await createImageCaptionFromFile(window._libSelectedFile);

            closeImageLibrary();

        };

        useBtn.textContent = 'Insert as Caption';

    }

}



function uploadImageForNewBox() {

    // Create a temp file input for image caption upload

    let inp = document.getElementById('_captionImageUpload');

    if (!inp) {

        inp = document.createElement('input');

        inp.type = 'file'; inp.id = '_captionImageUpload';

        inp.accept = 'image/*'; inp.style.display = 'none';

        inp.onchange = async function() {

            if (!this.files || !this.files[0]) return;

            const file = this.files[0];

            // Upload to podcast_images

            const fd = new FormData();

            fd.append('ajax_action', 'upload_scene_image');

            fd.append('scene_id',    currentSceneId);

            fd.append('image_field', 'caption_upload'); // won't update DB field

            fd.append('media_type',  'image');

            fd.append('scene_image', file);

            try {

                const resp = await fetch(location.href, { method:'POST', body:fd });

                const data = await resp.json();

                if (data.success) {

                    await createImageCaptionFromFile(data.filename);

                } else { alert('Upload failed: ' + data.message); }

            } catch(e) { alert('Upload error: ' + e.message); }

        };

        document.body.appendChild(inp);

    }

    inp.value = '';

    inp.click();

}

//******************************  modal functions ***********************

// ── Media Library State ───────────────────────────────────────

let _mediaAllFiles    = [];

let _mediaFilteredImages = [];

let _mediaFilteredVideos = [];

let _mediaSelectedFile = null;

let _mediaActiveTab   = 'images';

let _mediaCurrentSlot = 'image_file';



// ── Open Media Library ────────────────────────────────────────

function openMediaLibForSlot(slotName) {

    _mediaCurrentSlot  = slotName || 'image_file';

    _mediaSelectedFile = null;



    const names = {image_file:'Main', image_file_1:'V1', image_file_2:'V2', image_file_3:'V3', image_file_4:'V4'};

    const slotLabel = document.getElementById('editSlotName');

    if (slotLabel) slotLabel.textContent = names[_mediaCurrentSlot] || _mediaCurrentSlot;



    const selBtn = document.getElementById('mediaSelectBtn');

    if (selBtn) { selBtn.disabled = true; selBtn.style.opacity = '0.5'; }

    const selInfo = document.getElementById('mediaSelInfo');

    if (selInfo) selInfo.textContent = 'No file selected';

    const searchInput = document.getElementById('mediaSearchInput');

    if (searchInput) searchInput.value = '';

    const status = document.getElementById('mediaSearchStatus');

    if (status) status.style.display = 'none';



    const modal = document.getElementById('mediaLibModal');

    if (modal) modal.style.display = 'flex';



    loadAllMediaFiles();

}

// ── Load All Media (no search) ────────────────────────────────

async function loadAllMediaFiles() {

    const grid = document.getElementById('mediaGrid');

    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Finding matching media...</div>';



    _mediaFilteredImages = [];

    _mediaFilteredVideos = [];



    const scene    = scenes.find(s => s.id == currentSceneId);

    const sceneTags = scene

        ? (scene.natural_language_tags || scene.hashtags || scene.text_contents || '')

        : '';



    const status = document.getElementById('mediaSearchStatus');

    if (status) {

        status.style.display = 'block';

        status.style.color   = 'var(--muted)';

        status.innerHTML     = sceneTags

            ? `🔍 Matching scene tags: <em>${sceneTags.split('|').slice(0, 3).join(' · ')}</em>...`

            : '📂 Loading media library...';

    }



    if (sceneTags) {

        const searchInput = document.getElementById('mediaSearchInput');

        if (searchInput && !searchInput.value) {

            searchInput.value = sceneTags.split('|')[0];

        }

        await performMediaSearchWithQuery(sceneTags);

    } else {

        await loadRecentMedia();

    }

}



async function loadRecentMedia() {

    const grid = document.getElementById('mediaGrid');

    try {

        // Load images

        const fd = new FormData();

        fd.append('ajax_action', 'get_media_library');

        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const text = await resp.text();

        let images = [];

        try {

            const data = JSON.parse(text);

            images = Array.isArray(data) ? data : [];

        } catch(e) { console.error('Images parse error:', text.substring(0, 200)); }



        // Load videos with thumbnails

        const fdv = new FormData();

        fdv.append('ajax_action', 'get_video_library_with_thumbs');

        const respv = await fetch(location.href, { method: 'POST', body: fdv });

        const textv = await respv.text();

        let videos = [];

        try {

            const datav = JSON.parse(textv);

            videos = Array.isArray(datav) ? datav.map(v => ({

                image_name:      v.video_name || v.filename,

                hashtags:        v.hashtags   || '',

                nl_tags:         v.nl_tags    || '',

                matched_line:    '',

                matched_segment: '',

                thumbnail:       v.thumbnail  || '',

                file_size:       v.file_size  || 0,

                media_type:      'video',

                score:           0

            })) : [];

        } catch(e) { console.error('Videos parse error:', textv.substring(0, 200)); }



        _mediaFilteredImages = images.slice(0, 50).map(f => ({

            image_name:      f.image_name  || f.filename,

            hashtags:        f.hashtags    || f.image_hashtags || '',

            nl_tags:         f.natural_language_tags || f.nl_tags || '',

            matched_line:    '',

            matched_segment: '',

            thumbnail:       f.thumbnail   || '',

            file_size:       f.file_size   || 0,

            media_type:      'image',

            score:           0

        })).filter(f => f.image_name);



        _mediaFilteredVideos = videos;



        console.log(`✅ Loaded ${_mediaFilteredImages.length} images, ${_mediaFilteredVideos.length} videos`);

        updateTabCounts();

        renderMediaGrid();



        const status = document.getElementById('mediaSearchStatus');

        if (status) {

            status.innerHTML   = `Showing recent media — type above to search`;

            status.style.color = 'var(--muted)';

        }

    } catch(e) {

        if (grid) grid.innerHTML = `<div style="grid-column:1/-1;color:#ef4444;text-align:center;padding:20px;">Error: ${e.message}</div>`;

    }

}









async function searchMediaType(query, mediaType) {

    if (mediaType === 'video') {

        return await searchVideosFromFolder(query);

    }



    // Images only

    const fd = new FormData();

    fd.append('ajax_action',       'search_media_nl');

    fd.append('query',             query);

    fd.append('media_type_filter', 'image');

    fd.append('podcast_id',        <?= (int)$url_podcast_id ?>);



    try {

        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const text = await resp.text();

        if (!text || !text.trim()) return [];

        try {

            const data = JSON.parse(text);

            return Array.isArray(data) ? data : [];

        } catch(e) {

            console.error('Image JSON parse error:', text.substring(0, 300));

            return [];

        }

    } catch(e) {

        console.error('Image search error:', e);

        return [];

    }

}



async function searchVideosFromFolder(query) {

    try {

        // Try DB search first

        const fd = new FormData();

        fd.append('ajax_action',       'search_media_nl');

        fd.append('query',             query);

        fd.append('media_type_filter', 'video');

        fd.append('podcast_id',        <?= (int)$url_podcast_id ?>);



        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const text = await resp.text();

        let dbVideos = [];

        try {

            const data = JSON.parse(text);

            dbVideos = Array.isArray(data) ? data : [];

        } catch(e) {}



        if (dbVideos.length > 0) {

            console.log(`✅ Found ${dbVideos.length} videos from DB`);

            return dbVideos;

        }



        // Fallback: folder scan

        console.log('⚠️ No videos in DB — loading from folder');

        const fdv = new FormData();

        fdv.append('ajax_action', 'get_video_library_with_thumbs');

        const respv = await fetch(location.href, { method: 'POST', body: fdv });

        const textv = await respv.text();

        let allVideos = [];

        try {

            const datav = JSON.parse(textv.trim());

            allVideos = Array.isArray(datav) ? datav : [];

        } catch(e) {

            console.error('Video folder parse error:', textv.substring(0, 300));

            return [];

        }



        if (!allVideos.length) return [];



        const queryWords = query.toLowerCase()

            .replace(/[|,]/g, ' ')

            .split(/\s+/)

            .filter(w => w.length > 2);



        const scored = allVideos.map(v => {

            const fname        = (v.video_name || v.filename || '').toLowerCase();

            const nlTags       = (v.nl_tags    || '').toLowerCase();

            const searchable   = fname + ' ' + nlTags;

            const matchedWords = queryWords.filter(w => searchable.includes(w));

            const matchCount   = matchedWords.length;



            return {

                filename:        v.video_name || v.filename,

                type:            'video',

                hashtags:        v.hashtags  || '',

                nl_tags:         v.nl_tags   || '',

                thumbnail:       v.thumbnail || '',

                matched_line:    matchCount > 0

                                    ? matchedWords.join(', ')

                                    : (v.nl_tags ? v.nl_tags.split('|')[0] : 'Video file'),

                matched_segment: '',

                score:           matchCount > 0

                                    ? (matchCount / queryWords.length) * 0.5

                                    : 0

            };

        });



        scored.sort((a, b) => b.score - a.score);

        const matched   = scored.filter(v => v.score > 0).slice(0, 10);

        const unmatched = scored.filter(v => v.score === 0)

                                .sort(() => Math.random() - 0.5)

                                .slice(0, 10);

        return [...matched, ...unmatched];



    } catch(e) {

        console.error('searchVideosFromFolder error:', e);

        return [];

    }

}













function extractMatchedTags(nlTags, query) {

    if (!nlTags || !query) return [];

    const queryWords = query.toLowerCase().split(/\s+/).filter(w => w.length > 2);

    const tagWords   = nlTags.toLowerCase().split(/[|,\n\s]+/).filter(w => w.length > 2);

    return tagWords.filter(t => queryWords.some(q => t.includes(q) || q.includes(t))).slice(0, 3);

}

// ── Local Fallback Search (filename/hashtag matching) ─────────

async function performLocalSearch(query) {

    const q = query.toLowerCase().split(/[\s|,]+/).filter(w => w.length > 2);



    // Search images from already-loaded files or fetch fresh

    let allImages = _mediaAllFiles.filter(f => f.media_type !== 'video');

    let allVideos = _mediaAllFiles.filter(f => f.media_type === 'video');



    // If no cached files, fetch them

    if (allImages.length === 0) {

        try {

            const fd = new FormData();

            fd.append('ajax_action', 'get_media_library');

            const resp = await fetch(location.href, { method: 'POST', body: fd });

            const text = await resp.text();

            const data = JSON.parse(text);

            allImages = Array.isArray(data) ? data.map(f => ({ ...f, media_type: 'image' })) : [];

        } catch(e) {}

    }



    if (allVideos.length === 0) {

        try {

            const fdv = new FormData();

            fdv.append('ajax_action', 'get_video_library');

            const respv = await fetch(location.href, { method: 'POST', body: fdv });

            const textv = await respv.text();

            const datav = JSON.parse(textv);

            allVideos = Array.isArray(datav) ? datav.map(v => ({

                image_name: v.video_name || v.filename,

                media_type: 'video',

                hashtags: '', nl_tags: '', matched_line: '', score: 0

            })) : [];

        } catch(e) {}

    }



    const matchFile = (f) => {

        const searchable = [

            f.image_name || '',

            f.hashtags || '',

            f.image_hashtags || '',

            f.natural_language_tags || f.nl_tags || ''

        ].join(' ').toLowerCase();

        return q.some(word => searchable.includes(word));

    };



    _mediaFilteredImages = allImages.filter(matchFile).slice(0, 20).map(f => ({

        image_name:   f.image_name || f.filename,

        hashtags:     f.hashtags || f.image_hashtags || '',

        nl_tags:      f.natural_language_tags || f.nl_tags || '',

        matched_line: (f.natural_language_tags || '').split('|').find(l => 

                        q.some(w => l.toLowerCase().includes(w))) || '',

        file_size:    f.file_size || 0,

        media_type:   'image',

        score:        0

    }));



    _mediaFilteredVideos = allVideos.filter(matchFile).slice(0, 20).map(f => ({

        image_name:   f.image_name,

        hashtags:     '',

        nl_tags:      '',

        matched_line: '',

        file_size:    f.file_size || 0,

        media_type:   'video',

        score:        0

    }));



    updateTabCounts();

    renderMediaGrid();



    const status = document.getElementById('mediaSearchStatus');

    const total = _mediaFilteredImages.length + _mediaFilteredVideos.length;

    if (status) {

        status.style.display = 'block';

        status.innerHTML = total > 0

            ? `<span style="color:#f59e0b;">📂 Local search: ${_mediaFilteredImages.length} images · ${_mediaFilteredVideos.length} videos</span>`

            : `<span style="color:var(--error);">❌ No results found for "${query}"</span>`;

    }

}



// ── Tab switching ─────────────────────────────────────────────

function switchMediaTab(tab) {

    _mediaActiveTab = tab;



    const imgBtn = document.getElementById('tabImages');

    const vidBtn = document.getElementById('tabVideos');



    if (imgBtn) {

        imgBtn.style.borderBottom = tab === 'images' ? '3px solid var(--info)' : '3px solid transparent';

        imgBtn.style.color        = tab === 'images' ? 'var(--info)' : 'var(--muted)';

    }

    if (vidBtn) {

        vidBtn.style.borderBottom = tab === 'videos' ? '3px solid var(--info)' : '3px solid transparent';

        vidBtn.style.color        = tab === 'videos' ? 'var(--info)' : 'var(--muted)';

    }

    renderMediaGrid();

}



// ── Update tab counts ─────────────────────────────────────────

function updateTabCounts() {

    const ic = document.getElementById('tabImagesCount');

    const vc = document.getElementById('tabVideosCount');

    if (ic) ic.textContent = _mediaFilteredImages.length;

    if (vc) vc.textContent = _mediaFilteredVideos.length;

}

// ── Semantic Search ───────────────────────────────────────────

async function performMediaSearchWithQuery(query) {

    const grid = document.getElementById('mediaGrid');

    if (grid) grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">🔍 Searching with AI...</div>';



    const status = document.getElementById('mediaSearchStatus');

    if (status) {

        status.style.display = 'block';

        status.style.color   = 'var(--muted)';

        status.innerHTML     = `Searching: "<em>${query.substring(0, 80)}</em>"...`;

    }



    try {

        const imgResults = await searchMediaType(query, 'image');

		const vidResults = await searchMediaType(query, 'video');



        console.log(`Search results: ${imgResults.length} images, ${vidResults.length} videos`);



        _mediaFilteredImages = imgResults.map(r => ({

            image_name:      r.filename,

            hashtags:        r.hashtags        || '',

            nl_tags:         r.nl_tags         || '',

            matched_line:    r.matched_line    || '',

            matched_segment: r.matched_segment || '',

            thumbnail:       r.thumbnail       || '',

            file_size:       0,

            media_type:      'image',

            score:           r.score           || 0

        }));



        _mediaFilteredVideos = vidResults.map(r => ({

            image_name:      r.filename,

            hashtags:        r.hashtags        || '',

            nl_tags:         r.nl_tags         || '',

            matched_line:    r.matched_line    || '',

            matched_segment: r.matched_segment || '',

            thumbnail:       r.thumbnail       || '',

            file_size:       0,

            media_type:      'video',

            score:           r.score           || 0

        }));



        updateTabCounts();

        renderMediaGrid();



        const totalImages = _mediaFilteredImages.length;

        const totalVideos = _mediaFilteredVideos.length;

        const total       = totalImages + totalVideos;



        if (status) {

            if (total > 0) {

                const allResults  = [..._mediaFilteredImages, ..._mediaFilteredVideos];

                const highQuality = allResults.filter(f => f.score >= 0.5).length;

                const medQuality  = allResults.filter(f => f.score >= 0.35 && f.score < 0.5).length;

                const lowQuality  = allResults.filter(f => f.score > 0  && f.score < 0.35).length;

                const zeroScore   = allResults.filter(f => f.score === 0).length;



                status.innerHTML = `

                    <div style="font-weight:600;margin-bottom:6px;color:var(--success);">

                        ✅ <b>${totalImages}</b> images · <b>${totalVideos}</b> videos

                        for: "<em>${query.substring(0, 50)}</em>"

                    </div>

                    <div style="display:flex;gap:6px;flex-wrap:wrap;font-size:11px;margin-bottom:4px;">

                        ${highQuality > 0 ? `<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;">🟢 ${highQuality} strong</span>`      : ''}

                        ${medQuality  > 0 ? `<span style="background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:10px;">🟡 ${medQuality} partial</span>`     : ''}

                        ${lowQuality  > 0 ? `<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;">🔴 ${lowQuality} weak</span>`        : ''}

                        ${zeroScore   > 0 ? `<span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:10px;">⚪ ${zeroScore} text match</span>`   : ''}

                    </div>

                    <div style="font-size:11px;color:#64748b;">

                        Match % on each image — 🟢 strong · 🟡 partial · 🔴 weak

                    </div>`;

            } else {

                status.innerHTML = `<span style="color:var(--error);">❌ No results — trying local search...</span>`;

                await performLocalSearch(query);

            }

        }



    } catch(e) {

        console.error('Search error:', e);

        if (status) status.innerHTML = `<span style="color:#f59e0b;">⚠️ Error: ${e.message} — trying local search</span>`;

        await performLocalSearch(query);

    }

}





async function performMediaSearch() {

    const query = document.getElementById('mediaSearchInput')?.value.trim();

    if (!query) { loadAllMediaFiles(); return; }

    await performMediaSearchWithQuery(query);

}

// Show/hide NL tag on hover

function showNlTag(el) {

    const tag = el.querySelector('.nl-tag-preview');

    if (tag) tag.style.display = 'block';

}

function hideNlTag(el) {

    const tag = el.querySelector('.nl-tag-preview');

    if (tag) tag.style.display = 'none';

}



// ── Select item ───────────────────────────────────────────────

function selectMediaItem(el, filename, type) {

    // Deselect all

    document.querySelectorAll('.media-item').forEach(x => x.classList.remove('selected'));

    el.classList.add('selected');



    _mediaSelectedFile = { filename, type };



    const selInfo = document.getElementById('mediaSelInfo');

    if (selInfo) selInfo.textContent = filename;



    const selBtn = document.getElementById('mediaSelectBtn');

    if (selBtn) { selBtn.disabled = false; selBtn.style.opacity = '1'; }

}



// ── Confirm selection ─────────────────────────────────────────

async function confirmMediaSelection() {

    if (!_mediaSelectedFile || !currentSceneId) return;



    const { filename, type } = _mediaSelectedFile;

    const slot = _mediaCurrentSlot || 'image_file';



    const fd = new FormData();

    fd.append('ajax_action',  'assign_image');

    fd.append('scene_id',     currentSceneId);

    fd.append('filename',     filename);

    fd.append('image_field',  slot);



    try {

        const resp = await fetch(location.href, { method: 'POST', body: fd });

        const data = await resp.json();

        if (!data.success) throw new Error(data.message || 'Assign failed');



        // Update local scene object

        const scene = scenes.find(s => s.id == currentSceneId);

        if (scene) scene[slot] = filename;



        // Refresh slot thumbnail in image panel

        const imgEl = document.getElementById('slot_img_' + slot);

        const phEl  = document.getElementById('slot_placeholder_' + slot);

        if (imgEl) {

            const isVid = /\.(mp4|webm|mov|avi|mkv|m4v)$/i.test(filename);

            if (isVid) {

                if (phEl) { phEl.innerHTML = '🎬'; phEl.style.display = 'flex'; }

                imgEl.style.display = 'none';

            } else {

                imgEl.src = 'podcast_images/' + filename + '?t=' + Date.now();

                imgEl.style.display = 'block';

                if (phEl) phEl.style.display = 'none';

            }

        }



        await loadCurrentSceneToFabric();

        closeMediaLib();

        L('✅ Media assigned: ' + filename + ' → ' + slot);



    } catch(e) { alert('Failed: ' + e.message); }

}



// ── Close ─────────────────────────────────────────────────────

function closeMediaLib() {

    const modal = document.getElementById('mediaLibModal');

    if (modal) modal.style.display = 'none';

    _mediaSelectedFile = null;

}



// ── Lightbox ──────────────────────────────────────────────────

function openMediaLightbox(event, src, name) {

    event.stopPropagation();

    const lb = document.getElementById('mediaLightbox');

    const img = document.getElementById('mediaLightboxImg');

    const nm  = document.getElementById('mediaLightboxName');

    if (lb)  { lb.style.display = 'flex'; }

    if (img) img.src = src;

    if (nm)  nm.textContent = name;

}



function closeMediaLightbox() {

    const lb = document.getElementById('mediaLightbox');

    if (lb) lb.style.display = 'none';

}

// ── Render Grid ───────────────────────────────────────────────

function renderMediaGrid() {

    const grid = document.getElementById('mediaGrid');

    if (!grid) return;



    const files = _mediaActiveTab === 'videos' ? _mediaFilteredVideos : _mediaFilteredImages;



    if (!files.length) {

        grid.innerHTML = `

            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--muted);">

                <div style="font-size:40px;margin-bottom:12px;">

                    ${_mediaActiveTab === 'images' ? '🖼️' : '🎬'}

                </div>

                <div style="font-size:14px;font-weight:600;margin-bottom:8px;">

                    No ${_mediaActiveTab} found

                </div>

                <div style="font-size:12px;">

                    Type a description above to search

                </div>

            </div>`;

        return;

    }



    grid.innerHTML = files.map((f) => {

        const isVideo = f.media_type === 'video';

        const filename = f.image_name;

        const score    = f.score || 0;



        // Border and badge color

        let borderColor, scoreBg, scoreColor, qualityLabel;

        if (score >= 0.5) {

            borderColor = '#10b981'; scoreBg = '#dcfce7';

            scoreColor  = '#166534'; qualityLabel = '🟢';

        } else if (score >= 0.35) {

            borderColor = '#f59e0b'; scoreBg = '#fef9c3';

            scoreColor  = '#854d0e'; qualityLabel = '🟡';

        } else if (score > 0) {

            borderColor = '#ef4444'; scoreBg = '#fee2e2';

            scoreColor  = '#991b1b'; qualityLabel = '🔴';

        } else {

            borderColor = '#e2e8f0'; scoreBg = '#f1f5f9';

            scoreColor  = '#64748b'; qualityLabel = '⚪';

        }



        const scoreHtml = score > 0

            ? `<div style="position:absolute;top:6px;right:6px;background:${scoreBg};color:${scoreColor};padding:2px 7px;border-radius:10px;font-size:10px;font-weight:700;z-index:10;">

                ${qualityLabel} ${Math.round(score * 100)}%

               </div>`

            : '';



        const matchedSegment = (f.matched_segment || '').trim();

        const matchedLine    = (f.matched_line    || '').trim();



        const matchedHtml = (matchedSegment || matchedLine)

    ? `<div style="padding:5px 8px;background:#f1f5f9;border-top:1px solid #e2e8f0;font-size:10px;color:#475569;line-height:1.4;min-height:34px;max-height:48px;overflow:hidden;">

        ${matchedSegment

            ? `<span style="color:#0369a1;font-weight:600;">🔍 ${matchedSegment.substring(0, 45)}</span>`

            : ''}

        ${matchedLine && matchedLine !== matchedSegment

            ? `<br><span style="color:#64748b;">↳ ${matchedLine.substring(0, 50)}</span>`

            : ''}

        ${isVideo && (f.thumbnail || '').trim()

            ? `<br><span style="color:#10b981;font-size:9px;">🖼️ ${f.thumbnail}</span>`

            : isVideo 

            ? `<br><span style="color:#ef4444;font-size:9px;">🖼️ no thumbnail</span>`

            : ''}

       </div>`

            : `<div style="padding:5px 8px;background:#f1f5f9;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8;min-height:34px;display:flex;align-items:center;">

                No tag matched

               </div>`;



        const typeBadge = isVideo

            ? `<div style="position:absolute;top:6px;left:6px;background:rgba(0,0,0,0.65);color:white;padding:2px 7px;border-radius:8px;font-size:10px;font-weight:600;">🎬 Video</div>`

            : '';



        // Media content — images and videos both use thumbnails

        const thumb     = (f.thumbnail || '').trim();

        const thumbSrc = thumb ? `podcast_thumbnails/${thumb}` : `podcast_images/${filename}`;

		

		

        const origSrc   = isVideo

            ? `podcast_videos/${filename}`

            : `podcast_images/${filename}`;



        const mediaContent = isVideo

            ? (() => {

                if (thumb) {

                    return `<div style="position:relative;width:100%;height:120px;overflow:hidden;">

                        <img src="podcast_thumbnails/${thumb}"

                             style="width:100%;height:120px;object-fit:cover;display:block;"

                             loading="lazy"

                             alt="${filename}"

                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">

                        <div style="display:none;width:100%;height:120px;background:linear-gradient(135deg,#0f172a,#1e3a5f);align-items:center;justify-content:center;font-size:32px;">🎬</div>

                        <div style="position:absolute;bottom:4px;right:4px;background:rgba(0,0,0,0.7);color:white;padding:2px 6px;border-radius:6px;font-size:10px;">▶ Video</div>

                    </div>`;

                } else {

                    return `<div style="width:100%;height:120px;background:linear-gradient(135deg,#0f172a,#1e3a5f);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:8px;">

                        <span style="font-size:36px;">🎬</span>

                        <span style="color:#94a3b8;font-size:9px;text-align:center;padding:0 6px;overflow:hidden;max-width:100%;word-break:break-all;line-height:1.3;max-height:30px;">

                            ${filename.substring(0, 35)}

                        </span>

                    </div>`;

                }

            })()

            : `<div style="position:relative;width:100%;height:120px;overflow:hidden;">

                <img

                    src="${thumbSrc}"

                    data-original="podcast_images/${filename}"

                    style="width:100%;height:120px;object-fit:cover;display:block;"

                    loading="lazy"

                    alt="${filename}"

                    onerror="

                        if(this.src.indexOf('podcast_thumbnails') !== -1) {

                            this.src = this.getAttribute('data-original');

                        } else {

                            this.style.display='none';

                            this.nextElementSibling.style.display='flex';

                        }

                    ">

                <div style="display:none;width:100%;height:120px;background:#e2e8f0;align-items:center;justify-content:center;font-size:24px;">🖼️</div>

               </div>`;



        const zoomBtn = !isVideo

            ? `<button

                onclick="openMediaLightbox(event,'podcast_images/${filename}','${filename}')"

                title="Preview full size"

                style="position:absolute;bottom:38px;right:6px;background:rgba(0,0,0,0.55);color:white;width:26px;height:26px;border-radius:50%;border:none;cursor:zoom-in;display:flex;align-items:center;justify-content:center;font-size:13px;z-index:10;">🔍</button>`

            : '';



        return `

            <div class="media-item"

                 data-filename="${filename}"

                 data-type="${f.media_type || 'image'}"

                 onclick="selectMediaItem(this, '${filename}', '${f.media_type || 'image'}')"

                 style="

                    position:relative;

                    border:2px solid ${borderColor};

                    border-radius:10px;

                    overflow:hidden;

                    cursor:pointer;

                    background:white;

                    transition:border-color 0.15s, transform 0.15s;

                    display:flex;

                    flex-direction:column;">

                ${mediaContent}

                ${scoreHtml}

                ${typeBadge}

                ${zoomBtn}

                <div class="media-check" style="position:absolute;top:6px;left:6px;background:#10b981;color:white;width:24px;height:24px;border-radius:50%;display:none;align-items:center;justify-content:center;font-size:14px;font-weight:700;z-index:20;">✓</div>

                ${matchedHtml}

            </div>`;

    }).join('');

}



//************************************************************************

async function createImageCaptionFromFile(filename) {

    if (!currentSceneId || !filename) return;

    // Create caption row in DB with type=image

    const fd = new FormData();

    fd.append('ajax_action',   'create_caption');

    fd.append('story_id',      currentSceneId);

    fd.append('podcast_id',    <?= (int)$url_podcast_id ?>);

    fd.append('caption_type',  'image');

    fd.append('caption_name',  'img_' + Date.now());

    try {

        const {data} = await safeFetch(fd);

        if (!data.success) { alert('Failed to create caption: ' + data.message); return; }

        const capId = data.caption_id;

        // Save image file to the caption

        const fd2 = new FormData();

        fd2.append('ajax_action', 'save_caption');

        fd2.append('caption_id',  capId);

        fd2.append('image_file',  filename);

        fd2.append('media_type',  'image');

        await safeFetch(fd2);

        // Add to canvas

        sceneCaptions[capId] = Object.assign(data.caption_data || {}, { id:capId, image_file:filename, caption_type:'image' });

        await addImageBoxToCanvas(capId, filename, true);

        setSelectionMode(true);

        L('✅ Image caption added: ' + filename);

    } catch(e) { alert('Error: ' + e.message); }

}

function playPreview()               { playFullSequence(); }

function scheduleVideo()             { L('📅 Schedule — coming next'); }

function TranslateVideo()            { L('🌐 Translate — coming next'); }

function archiveProject()            { L('📦 Archive — coming next'); }

function deleteProject()             { if(confirm('Delete this project?')) L('🗑️ Delete — coming next'); }

function gobackProjects()            { window.location.href = 'vizard_browser.php'; }

function saveSceneSettings()         { L('💾 Save settings — coming next'); }

function saveToAllScenes()           { L('💾 Save to all — coming next'); }



// Close action menu when clicking outside

document.addEventListener('click', function(e) {

    const m = document.getElementById('actionMenu');

    if (m && m.classList.contains('active') && !m.contains(e.target)) {

        m.classList.remove('active');

    }

});



</script>



</body>

</html>

