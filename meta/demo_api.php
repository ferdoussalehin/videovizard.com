<?php

session_start();
$cfg = require __DIR__ . '/config.php';

if (empty($_SESSION['long_lived_token'])) {
    echo '<p>No token in session. Start from <a href="index.php">index.php</a>.</p>';
    exit;
}

$token = $_SESSION['long_lived_token'];


$endpoint = 'https://graph.instagram.com/v24.0/me'
    . '?fields=id,username,account_type'
    . '&access_token=' . urlencode($token);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
if ($resp === false) {
    echo 'Curl error: ' . curl_error($ch);
    exit;
}
curl_close($ch);

$data = json_decode($resp, true);


$ig_user_id = $_SESSION['instagram_user_id'] ?? $data['id'] ?? null;

$messages = [];


function discover_ig_business_account($token) {
    $try = function($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['error' => $err];
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $resp];
    };


    $url = 'https://graph.facebook.com/v17.0/me/accounts?access_token=' . urlencode($token);
    $r = $try($url);
    if (!empty($r['body'])) {
        $j = json_decode($r['body'], true);
        if (!empty($j['data']) && is_array($j['data'])) {
            foreach ($j['data'] as $page) {
                if (isset($page['id'])) {
                    $page_id = $page['id'];
                    $purl = 'https://graph.facebook.com/v17.0/' . urlencode($page_id) . '?fields=instagram_business_account&access_token=' . urlencode($token);
                    $pr = $try($purl);
                    if (!empty($pr['body'])) {
                        $pj = json_decode($pr['body'], true);
                        if (!empty($pj['instagram_business_account']['id'])) {
                            return $pj['instagram_business_account']['id'];
                        }
                    }
                }
            }
        }
    }


    $url2 = 'https://graph.facebook.com/v17.0/me?fields=instagram_business_account&access_token=' . urlencode($token);
    $r2 = $try($url2);
    if (!empty($r2['body'])) {
        $j2 = json_decode($r2['body'], true);
        if (!empty($j2['instagram_business_account']['id'])) {
            return $j2['instagram_business_account']['id'];
        }
    }

    $url3 = 'https://graph.instagram.com/v24.0/me?fields=id&access_token=' . urlencode($token);
    $r3 = $try($url3);
    if (!empty($r3['body'])) {
        $j3 = json_decode($r3['body'], true);
        if (!empty($j3['id'])) {
            return $j3['id'];
        }
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['refresh'])) {
        $refresh_url = 'https://graph.instagram.com/refresh_access_token'
            . '?grant_type=ig_refresh_token&access_token=' . urlencode($token);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $refresh_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $r = curl_exec($ch);
        if ($r === false) {
            $messages[] = 'Curl error: ' . curl_error($ch);
        } else {
            $jr = json_decode($r, true);
            if (isset($jr['access_token'])) {
                $_SESSION['long_lived_token'] = $jr['access_token'];
                $_SESSION['expires_in'] = $jr['expires_in'] ?? null;
                $token = $_SESSION['long_lived_token'];
                $messages[] = 'Token refreshed. New expires_in: ' . htmlspecialchars($_SESSION['expires_in']);
            } else {
                $messages[] = 'Refresh response: ' . htmlspecialchars($r);
            }
        }
        curl_close($ch);
    }

    if (isset($_POST['publish'])) {
        $media_type = $_POST['media_type'] ?? 'image';
        $caption = trim($_POST['caption'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
        $video_url = trim($_POST['video_url'] ?? '');
        $thumb_url = trim($_POST['thumb_url'] ?? '');

        if (empty($ig_user_id)) {
            $found = discover_ig_business_account($token);
            if ($found) {
                $ig_user_id = $found;
                $_SESSION['instagram_user_id'] = $ig_user_id;
                $messages[] = 'Discovered IG Business Account id: ' . htmlspecialchars($ig_user_id);
            }
        }

        if (empty($ig_user_id)) {
            $messages[] = 'Instagram user id not found. The token may not have permissions to list Pages or the account is not a Business/Creator account.';
        } else {
            $ch = curl_init();
            if ($media_type === 'image') {
                if (empty($image_url)) {
                    $messages[] = 'Please provide an image URL to publish.';
                } else {
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
                            $messages[] = 'Create image container response: ' . htmlspecialchars($create_resp);
                        }
                    }
                    curl_close($ch);
                }
            } else {
                if (empty($video_url)) {
                    $messages[] = 'Please provide a video URL to publish.';
                } else {

                    $create_url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media';
                    $post_fields = [
                        'video_url' => $video_url,
                        'caption' => $caption,
                        'access_token' => $token,
                    ];
                    if (!empty($thumb_url)) {
                        $post_fields['thumb_offset'] = 0;
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

                            $status_url = 'https://graph.instagram.com/v24.0/' . urlencode($creation_id) . '?fields=status_code&access_token=' . urlencode($token);
                            $max_tries = 12;
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

    if (isset($_POST['fetch'])) {
        $fetch_type = $_POST['fetch_type'] ?? 'list_media';
        $media_id = trim($_POST['fetch_media_id'] ?? '');

        if (empty($ig_user_id)) {
            $found = discover_ig_business_account($token);
            if ($found) {
                $ig_user_id = $found;
                $_SESSION['instagram_user_id'] = $ig_user_id;
                $messages[] = 'Discovered IG Business Account id: ' . htmlspecialchars($ig_user_id);
            }
        }

        if (empty($ig_user_id)) {
            $messages[] = 'Cannot fetch media/insights: Instagram user id not available.';
        } else {

            $do_get = function($url) use (&$messages) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resp = curl_exec($ch);
                if ($resp === false) {
                    $err = curl_error($ch);
                    curl_close($ch);
                    return ['error' => $err];
                }
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return ['code' => $code, 'body' => $resp];
            };

            if ($fetch_type === 'list_media') {
                $url = 'https://graph.instagram.com/v24.0/' . urlencode($ig_user_id) . '/media'
                    . '?fields=id,caption,media_type,media_url,permalink,timestamp'
                    . '&access_token=' . urlencode($token);
                $res = $do_get($url);
                if (!empty($res['error'])) {
                    $messages[] = 'Curl error (list media): ' . $res['error'];
                } else {
                    $jr = json_decode($res['body'], true);
                    $messages[] = 'List media response:';
                    $messages[] = json_encode($jr, JSON_PRETTY_PRINT);
                }
            } elseif ($fetch_type === 'media_details') {
                if (empty($media_id)) {
                    $messages[] = 'Please provide a media id to fetch details.';
                } else {
                    $url = 'https://graph.instagram.com/v24.0/' . urlencode($media_id)
                        . '?fields=id,caption,media_type,media_url,permalink,timestamp'
                        . '&access_token=' . urlencode($token);
                    $res = $do_get($url);
                    if (!empty($res['error'])) {
                        $messages[] = 'Curl error (media details): ' . $res['error'];
                    } else {
                        $jr = json_decode($res['body'], true);
                        $messages[] = 'Media details response:';
                        $messages[] = json_encode($jr, JSON_PRETTY_PRINT);
                    }
                }
            } elseif ($fetch_type === 'media_insights') {
                if (empty($media_id)) {
                    $messages[] = 'Please provide a media id to fetch insights.';
                } else {
                    // Default metrics for media insights
                    $metrics = 'engagement,impressions,reach,saved';
                    $url = 'https://graph.instagram.com/v24.0/' . urlencode($media_id) . '/insights'
                        . '?metric=' . urlencode($metrics)
                        . '&access_token=' . urlencode($token);
                    $res = $do_get($url);
                    if (!empty($res['error'])) {
                        $messages[] = 'Curl error (media insights): ' . $res['error'];
                    } else {
                        $jr = json_decode($res['body'], true);
                        $messages[] = 'Media insights response:';
                        $messages[] = json_encode($jr, JSON_PRETTY_PRINT);
                    }
                }
            } else {
                $messages[] = 'Unknown fetch type.';
            }
        }
    }
}

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Demo API</title></head>
<body>
  <h1>Instagram Graph API demo</h1>

  <h2>/me response</h2>
  <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) ?></pre>

  <?php if (!empty($messages)): ?>
    <h3>Messages</h3>
    <ul>
      <?php foreach ($messages as $m): ?>
        <li><?= htmlspecialchars($m) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

    <h2>Publish an image</h2>
  <p>To publish content you must have the appropriate permission (instagram_content_publish) approved for your app and the IG account must be a Business or Creator account.</p>
  <form method="post">
    <label>Instagram User ID: <input type="text" name="ig_user" value="<?= htmlspecialchars($ig_user_id) ?>" disabled></label>
    <br>
    <label>Image URL: <input type="text" name="image_url" value="https://via.placeholder.com/1200x1200.png?text=Test+Image" size="80"></label>
    <br>
    <label>Caption: <br><textarea name="caption" rows="4" cols="80">Test publish from API</textarea></label>
    <br>
    <button name="publish">Create + Publish</button>
  </form>

    <h2>Fetch media / insights</h2>
    <p>Use this to list recent media, get media details, or fetch media insights.</p>
    <form method="post">
        <label>Action:
            <select name="fetch_type">
                <option value="list_media">List media</option>
                <option value="media_details">Media details (requires media id)</option>
                <option value="media_insights">Media insights (requires media id)</option>
            </select>
        </label>
        <br>
        <label>Media ID: <input type="text" name="fetch_media_id" placeholder="1234567890123456789"></label>
        <br>
        <button name="fetch">Run fetch</button>
    </form>

  <h2>Refresh long-lived token</h2>
  <p>To refresh the long-lived token for another 60 days, make a GET request to:</p>
  <pre>https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=YOUR_LONG_LIVED_TOKEN</pre>
  <form method="post">
    <button name="refresh">Refresh token now (server-side)</button>
  </form>

</body>
</html>
<?php
// demo_api.php - use the long-lived token to call Graph API and show refresh example
session_start();
$cfg = require __DIR__ . '/config.php';

if (empty($_SESSION['long_lived_token'])) {
    echo '<p>No token in session. Start from <a href="index.php">index.php</a>.</p>';
    exit;
}

$token = $_SESSION['long_lived_token'];

// Example: get the IG user account info
$endpoint = 'https://graph.instagram.com/me'
    . '?fields=id,username,account_type'
    . '&access_token=' . urlencode($token);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
if ($resp === false) {
    echo 'Curl error: ' . curl_error($ch);
    exit;
}
curl_close($ch);

$data = json_decode($resp, true);

?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Demo API</title></head>
<body>
  <h1>Instagram Graph API demo</h1>
  <h2>/me response</h2>
  <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) ?></pre>

  <h2>Refresh long-lived token</h2>
  <p>To refresh the long-lived token for another 60 days, make a GET request to:</p>
  <pre>https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=YOUR_LONG_LIVED_TOKEN</pre>
  <form method="post">
    <button name="refresh">Refresh token now (server-side)</button>
  </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh'])) {
    $refresh_url = 'https://graph.instagram.com/refresh_access_token'
        . '?grant_type=ig_refresh_token&access_token=' . urlencode($token);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $refresh_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch);
    if ($r === false) {
        echo '<p>Curl error: ' . curl_error($ch) . '</p>';
    } else {
        $jr = json_decode($r, true);
        if (isset($jr['access_token'])) {
            $_SESSION['long_lived_token'] = $jr['access_token'];
            $_SESSION['expires_in'] = $jr['expires_in'] ?? null;
            echo '<p>Token refreshed. New expires_in: ' . htmlspecialchars($_SESSION['expires_in']) . '</p>';
        } else {
            echo '<p>Refresh response: <pre>' . htmlspecialchars($r) . '</pre></p>';
        }
    }
    curl_close($ch);
}
?>

</body>
</html>
