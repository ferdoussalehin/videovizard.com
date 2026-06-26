<?php
declare(strict_types=1);
session_start();

$cfg = require __DIR__ . "/config.php";

$tokens_file = __DIR__ . '/tokens.json';
$tokens = [];
if (file_exists($tokens_file)) {
    $tokens = json_decode((string)file_get_contents($tokens_file), true);
    if (!is_array($tokens)) $tokens = [];
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function find_selected_page(array $tokens, string $pageId): ?array {
    if (empty($tokens['pages']) || !is_array($tokens['pages'])) return null;
    foreach ($tokens['pages'] as $p) {
        if (($p['id'] ?? '') === $pageId) return $p;
    }
    return null;
}

function curl_post(string $url, array $fields): array {
    $hasFile = false;
    foreach ($fields as $value) {
        if ($value instanceof CURLFile) {
            $hasFile = true;
            break;
        }
    }
    
    $postData = $hasFile ? $fields : http_build_query($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$resp, (string)$err];
}

$tweet = (string)($_POST['tweet'] ?? '');
$postFacebook  = isset($_POST['post_facebook'])  && $_POST['post_facebook'] === "true";
$postInstagram = isset($_POST['post_instagram']) && $_POST['post_instagram'] === "true";

$selected_page_id = (string)($_POST['fb_page_id'] ?? '');
$selected_ig_id   = (string)($_POST['ig_business_id'] ?? '');

$use_generated_image = isset($_POST['use_generated_image']) && $_POST['use_generated_image'] === "1";
$generated_file = (string)($_POST['generated_file'] ?? '');

$base_url = "https://polished-charlette-rational.ngrok-free.dev/facebook-insta-video-post/";

$selectedPage = null;

if (!empty($tokens['fb_page_id']) && !empty($tokens['fb_page_token']) && empty($tokens['pages'])) {
    $selectedPage = [
        'id' => (string)$tokens['fb_page_id'],
        'name' => (string)($tokens['page_name'] ?? 'Selected Page'),
        'access_token' => (string)$tokens['fb_page_token'],
        'ig_business_id' => $tokens['ig_business_id'] ?? null
    ];
    if ($selected_page_id === '') $selected_page_id = (string)$tokens['fb_page_id'];
} else {
    if ($selected_page_id !== '') {
        $selectedPage = find_selected_page($tokens, $selected_page_id);
    } elseif (!empty($tokens['selected_page_id'])) {
        $selectedPage = find_selected_page($tokens, (string)$tokens['selected_page_id']);
        $selected_page_id = (string)($tokens['selected_page_id'] ?? '');
    }
}

$output = "";
$image_filename = '';
$public_video_url = '';
$local_video_path = '';

if (isset($_FILES['upload_video']) && is_array($_FILES['upload_video']) && ($_FILES['upload_video']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp = (string)$_FILES['upload_video']['tmp_name'];
    $orig = (string)$_FILES['upload_video']['name'];

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4','mov','avi','mkv'], true)) {
        $output .= "<div class='alert alert-danger'>Upload Error: Unsupported video type.</div>";
    } else {
        $safeName = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = __DIR__ . '/generated';
        if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

        $dest = $destDir . '/' . $safeName;
        if (!move_uploaded_file($tmp, $dest)) {
            $output .= "<div class='alert alert-danger'>Upload Error: Failed to save uploaded file.</div>";
        } else {
            $image_filename = $safeName;
            $public_video_url = $base_url . "generated/" . $safeName;
            $local_video_path = $dest;
        }
    }
}

if ($image_filename === '' && $use_generated_image && $generated_file !== '') {
    $image_filename = $generated_file;
    $public_video_url = $base_url . "generated/" . $generated_file;
}

// FACEBOOK POSTING
if ($postFacebook) {
    if (!$selectedPage || empty($selectedPage['access_token']) || empty($selectedPage['id'])) {
        $output .= "<div class='alert alert-warning'>Facebook Error: Not connected or no Page selected.</div>";
    } else {
        $pageId = (string)$selectedPage['id'];
        $pageToken = (string)$selectedPage['access_token'];

        if ($local_video_path !== '') {
            $fb_url = "https://graph.facebook.com/v24.0/{$pageId}/videos";
            [, $response, $err] = curl_post($fb_url, [
                'source' => new CURLFile($local_video_path),
                'description' => $tweet,
                'access_token' => $pageToken
            ]);

            $res = json_decode($response, true);
            if (is_array($res) && isset($res['id'])) {
                $output .= "<div class='alert alert-success'>Facebook: Posted video successfully!</div>";
            } else {
                $msg = (is_array($res) && isset($res['error']['message'])) ? $res['error']['message'] : ($err ?: $response);
                $output .= "<div class='alert alert-danger'>Facebook Error: " . h((string)$msg) . "</div>";
            }
        } else {
            $fb_url = "https://graph.facebook.com/v24.0/{$pageId}/feed";
            [, $response, $err] = curl_post($fb_url, [
                'message' => $tweet,
                'access_token' => $pageToken
            ]);

            $res = json_decode($response, true);
            if (is_array($res) && isset($res['id'])) {
                $output .= "<div class='alert alert-success'>Facebook: Posted text successfully!</div>";
            } else {
                $msg = (is_array($res) && isset($res['error']['message'])) ? $res['error']['message'] : ($err ?: $response);
                $output .= "<div class='alert alert-danger'>Facebook Error: " . h((string)$msg) . "</div>";
            }
        }
    }
}

// INSTAGRAM POSTING
if ($postInstagram) {
    if (!$selectedPage || empty($selectedPage['access_token'])) {
        $output .= "<div class='alert alert-warning'>Instagram Error: Not connected or no Page selected.</div>";
    } elseif ($selected_ig_id === '') {
        $output .= "<div class='alert alert-warning'>Instagram Error: No Instagram Business selected.</div>";
    } elseif ($public_video_url === '') {
        $output .= "<div class='alert alert-warning'>Instagram Error: Instagram requires a video. Upload a video.</div>";
    } else {
        $token = (string)$selectedPage['access_token'];
        $ig_id = $selected_ig_id;

        [, $respA, $errA] = curl_post("https://graph.facebook.com/v24.0/{$ig_id}/media", [
            'video_url' => $public_video_url,
            'media_type' => 'REELS',
            'caption' => $tweet,
            'access_token' => $token
        ]);

        $cont_res = json_decode($respA, true);

        if (is_array($cont_res) && isset($cont_res['id'])) {
            $creation_id = (string)$cont_res['id'];

            $status_url = "https://graph.facebook.com/v24.0/{$creation_id}?fields=status_code&access_token=" . urlencode($token);
            $max_tries = 12;
            $tries = 0;
            $ready = false;
            
            while ($tries < $max_tries) {
                $tries++;
                $sr = @file_get_contents($status_url);
                if ($sr !== false) {
                    $sj = json_decode($sr, true);
                    $state = $sj['status_code'] ?? null;
                    if (in_array($state, ['FINISHED', 'finished', 'PROCESSING_COMPLETE'])) {
                        $ready = true;
                        break;
                    }
                }
                sleep(5);
            }

            if ($ready) {
                [, $respB, $errB] = curl_post("https://graph.facebook.com/v24.0/{$ig_id}/media_publish", [
                    'creation_id' => $creation_id,
                    'access_token' => $token
                ]);

                $pub_res = json_decode($respB, true);

                if (is_array($pub_res) && isset($pub_res['id'])) {
                    $output .= "<div class='alert alert-success'>Instagram: Posted video successfully!</div>";
                } else {
                    $msg = (is_array($pub_res) && isset($pub_res['error']['message'])) ? $pub_res['error']['message'] : ($errB ?: $respB);
                    $output .= "<div class='alert alert-danger'>Instagram Publish Error: " . h((string)$msg) . "</div>";
                }
            } else {
                $output .= "<div class='alert alert-danger'>Instagram Error: Video processing timed out.</div>";
            }

        } else {
            $msg = (is_array($cont_res) && isset($cont_res['error']['message'])) ? $cont_res['error']['message'] : ($errA ?: $respA);
            $output .= "<div class='alert alert-danger'>Instagram Container Error: " . h((string)$msg) . "</div>";
        }
    }
}

echo $output ?: "<div class='alert alert-info'>No platforms selected or post processed.</div>";