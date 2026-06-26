<?php
declare(strict_types=1);
session_start();

$cfg = require __DIR__ . "/config.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function curl_post(string $url, array $fields): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$resp, (string)$err];
}


$tweet = (string)($_POST['tweet'] ?? '');
$postFacebook  = isset($_POST['post_facebook'])  && $_POST['post_facebook'] === "true";
$postInstagram = isset($_POST['post_instagram']) && $_POST['post_instagram'] === "true";

$selected_page_id = (string)($cfg['meta']['fb_page_id'] ?? '');
$selected_ig_id   = (string)($cfg['meta']['ig_business_id'] ?? '');

$use_generated_image = isset($_POST['use_generated_image']) && $_POST['use_generated_image'] === "1";
$generated_file = (string)($_POST['generated_file'] ?? '');

$base_url = $cfg['meta']['base_url_asset'];

$selectedPage = null;

$output = "";

$image_filename = '';
$public_image_url = '';

if (isset($_FILES['upload_image']) && is_array($_FILES['upload_image']) && ($_FILES['upload_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $tmp = (string)$_FILES['upload_image']['tmp_name'];
    $orig = (string)$_FILES['upload_image']['name'];

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
        $output .= "<div class='alert alert-danger'>Upload Error: Unsupported image type.</div>";
    } else {
        $safeName = 'upload_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destDir = __DIR__ . '/generated';
        if (!is_dir($destDir)) @mkdir($destDir, 0777, true);

        $dest = $destDir . '/' . $safeName;
        if (!move_uploaded_file($tmp, $dest)) {
            $output .= "<div class='alert alert-danger'>Upload Error: Failed to save uploaded file.</div>";
        } else {
            $image_filename = $safeName;
            $public_image_url = $base_url . "generated/" . $safeName;
        }
    }
}

if ($image_filename === '' && $use_generated_image && $generated_file !== '') {
    $image_filename = $generated_file;
    $public_image_url = $base_url . "generated/" . $generated_file;
}

// FACEBOOK POSTING
if ($postFacebook) {
    $pageId = (string)($cfg['meta']['fb_page_id'] ?? '');
    $pageToken = (string)($cfg['meta']['fb_access_token'] ?? '');
    
    if (empty($pageId) || empty($pageToken)) {
        $output .= "<div class='alert alert-warning'>Facebook Error: Missing page ID or access token in config.</div>";
    } else {
        if ($public_image_url !== '') {
            $fb_url = "https://graph.facebook.com/v24.0/{$pageId}/photos";
            [, $response, $err] = curl_post($fb_url, [
                'url' => $public_image_url,
                'caption' => $tweet,
                'access_token' => $pageToken
            ]);

            $res = json_decode($response, true);
            if (is_array($res) && isset($res['id'])) {
                $output .= "<div class='alert alert-success'>Facebook: Posted photo successfully!</div>";
            } else {
                $msg = (is_array($res) && isset($res['error']['message'])) ? $res['error']['message'] : ($err ?: $response);
                $output .= "<div class='alert alert-danger'>Facebook Error: " . h((string)$msg) . "</div>";
            }
        } else {
            $fb_url = "https://graph.facebook.com/v24.0/{$pageId}/feed";
            [$response, $err] = curl_post($fb_url, [
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

// Publish flow: create media container and publish it
if ($postInstagram) {
        $media_type = $_POST['media_type'] ?? 'photo';
        $caption = trim($_POST['tweet'] ?? '');
        // $image_url = trim($_POST['image_url'] ?? '');
        // $video_url = trim($_POST['video_url'] ?? '');
        // $thumb_url = trim($_POST['thumb_url'] ?? '');
        $image_url = $public_image_url;
        $ig_user_id = $cfg['meta']['ig_user_id'];
        $token = $cfg['meta']['ig_access_token'];
        // print_r($media_type); 
        // print_r($image_url); die;
        if (empty($ig_user_id)) {
            $messages[] = 'Instagram user id not found. The token may not have permissions to list Pages or the account is not a Business/Creator account.';
        } else {
            $ch = curl_init();
            if ($media_type === 'photo') {
                if (empty($image_url)) {
                    $messages[] = 'Please provide an image URL to publish.';
                } else {
                    // Use the Instagram Graph API v24.0 to create media containers
                    $create_url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media';
                    $post_fields = [
                        'image_url' => $image_url,
                        'caption' => $caption,
                        'access_token' => $token,
                    ];
                    curl_setopt($ch, CURLOPT_URL, $create_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
                    $create_resp = curl_exec($ch);
                    if ($create_resp === false) {
                        $messages[] = 'Curl error (create image container): ' . curl_error($ch);
                    } else {
                        $create_data = json_decode($create_resp, true);
                        if (isset($create_data['id'])) {
                            $creation_id = $create_data['id'];
                            $messages[] = 'Created image container id: ' . htmlspecialchars($creation_id);
                            
                            // Poll for status until finished (per docs)
                            $status_url = 'https://graph.instagram.com/v24.0/' . urlencode($creation_id) . '?fields=status_code&access_token=' . urlencode($token);
                            $max_tries = 12; // e.g., wait up to ~60s with 5s sleep
                            $tries = 0;
                            $ready = false;
                            while ($tries < $max_tries) {
                                $tries++;
                                $sr = file_get_contents($status_url);
                                if ($sr === false) {
                                    $messages[] = 'Error polling status for container ' . htmlspecialchars($creation_id);
                                    break;
                                }
                                $sj = json_decode($sr, true);
                                $state = $sj['status_code'] ?? null;
                                $messages[] = "Polling status (try {$tries}): " . htmlspecialchars(json_encode($sj));
                                if ($state === 'FINISHED' || $state === 'finished' || $state === 'PROCESSING_COMPLETE') {
                                    $ready = true;
                                    break;
                                }
                                // sleep a bit before retrying
                                sleep(5);
                            }
                            
                            if ($ready) {
                                // publish
                                $publish_url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media_publish';
                                $publish_fields = [
                                    'creation_id' => $creation_id,
                                    'access_token' => $token,
                                ];
                                curl_setopt($ch, CURLOPT_URL, $publish_url);
                                curl_setopt($ch, CURLOPT_POST, true);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, $publish_fields);
                                $pub_resp = curl_exec($ch);
                                if ($pub_resp === false) {
                                    $messages[] = 'Curl error (publish image): ' . curl_error($ch);
                                } else {
                                    $pub_data = json_decode($pub_resp, true);
                                    if (isset($pub_data['id'])) {
                                        $messages[] = 'Published media id: ' . htmlspecialchars($pub_data['id']);
                                        if (isset($pub_data['permalink'])) {
                                            $messages[] = 'Permalink: ' . htmlspecialchars($pub_data['permalink']);
                                        }
                                    } else {
                                        $messages[] = 'Publish response: ' . htmlspecialchars($pub_resp);
                                    }
                                }
                            } else {
                                $messages[] = 'Image container did not become ready within the polling window.';
                            }
                        } else {
                            $messages[] = 'Create image container response: ' . htmlspecialchars($create_resp);
                        }
                    }
                    curl_close($ch);
                    print_r($messages); die;
                }
            } else { // video flow
                if (empty($video_url)) {
                    $messages[] = 'Please provide a video URL to publish.';
                } else {
                    // Create video container
                    // Create video container via Instagram Graph API v24.0
                    $create_url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media';
                    $post_fields = [
                        'video_url' => $video_url,
                        'caption' => $caption,
                        'access_token' => $token,
                    ];
                    if (!empty($thumb_url)) {
                        $post_fields['thumb_offset'] = 0; // optional; placeholder
                        $post_fields['thumbnail_url'] = $thumb_url;
                    }
                    curl_setopt($ch, CURLOPT_URL, $create_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
                    $create_resp = curl_exec($ch);
                    if ($create_resp === false) {
                        $messages[] = 'Curl error (create video container): ' . curl_error($ch);
                    } else {
                        $create_data = json_decode($create_resp, true);
                        if (isset($create_data['id'])) {
                            $creation_id = $create_data['id'];
                            $messages[] = 'Created video container id: ' . htmlspecialchars($creation_id);

                            // Poll for status until finished (per docs)
                            // Poll the container node using Instagram Graph API v24.0 for status
                            $status_url = 'https://graph.instagram.com/v24.0/' . urlencode($creation_id) . '?fields=status_code&access_token=' . urlencode($token);
                            $max_tries = 12; // e.g., wait up to ~60s with 5s sleep
                            $tries = 0;
                            $ready = false;
                            while ($tries < $max_tries) {
                                $tries++;
                                $sr = file_get_contents($status_url);
                                if ($sr === false) {
                                    $messages[] = 'Error polling status for container ' . htmlspecialchars($creation_id);
                                    break;
                                }
                                $sj = json_decode($sr, true);
                                $state = $sj['status_code'] ?? null;
                                $messages[] = "Polling status (try {$tries}): " . htmlspecialchars(json_encode($sj));
                                if ($state === 'FINISHED' || $state === 'finished' || $state === 'PROCESSING_COMPLETE') {
                                    $ready = true;
                                    break;
                                }
                                // sleep a bit before retrying
                                sleep(5);
                            }
                            if ($ready) {
                                // Publish
                                $publish_url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media_publish';
                                $publish_fields = [
                                    'creation_id' => $creation_id,
                                    'access_token' => $token,
                                ];
                                $ch2 = curl_init();
                                curl_setopt($ch2, CURLOPT_URL, $publish_url);
                                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch2, CURLOPT_POST, true);
                                curl_setopt($ch2, CURLOPT_POSTFIELDS, $publish_fields);
                                $pub_resp = curl_exec($ch2);
                                if ($pub_resp === false) {
                                    $messages[] = 'Curl error (publish video): ' . curl_error($ch2);
                                } else {
                                    $pub_data = json_decode($pub_resp, true);
                                    if (isset($pub_data['id'])) {
                                        $messages[] = 'Published video media id: ' . htmlspecialchars($pub_data['id']);
                                        if (isset($pub_data['permalink'])) {
                                            $messages[] = 'Permalink: ' . htmlspecialchars($pub_data['permalink']);
                                        }
                                    } else {
                                        $messages[] = 'Publish video response: ' . htmlspecialchars($pub_resp);
                                    }
                                }
                                curl_close($ch2);
                            } else {
                                $messages[] = 'Video container did not become ready within the polling window.';
                            }
                        } else {
                            $messages[] = 'Create video container response: ' . htmlspecialchars($create_resp);
                        }
                    }
                    curl_close($ch);
                }
            }
        }
    }

echo $output ?: "<div class='alert alert-info'>No platforms selected or post processed.</div>";
