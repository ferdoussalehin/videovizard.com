<?php
declare(strict_types=1);
session_start();

// prevent browser caching the "connected" state
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$cfg = require __DIR__ . '/config.php';

$tokensPath = __DIR__ . '/tokens.json';
$isConnected = file_exists($tokensPath);
$tokens = null;

if ($isConnected) {
  $tokens = json_decode((string)file_get_contents($tokensPath), true);
  if (!is_array($tokens)) {
    $isConnected = false;
    $tokens = null;
  }
}

// Build IG choices from pages (only pages that have ig_business_id)
$igChoices = [];
if ($isConnected && !empty($tokens['pages']) && is_array($tokens['pages'])) {
  foreach ($tokens['pages'] as $p) {
    if (!empty($p['ig_business_id'])) {
      $igChoices[] = [
        'ig_business_id' => $p['ig_business_id'],
        'page_id' => $p['id'] ?? '',
        'page_name' => $p['name'] ?? 'IG via Page',
        'picture' => $p['picture'] ?? '',
      ];
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Social Media Poster</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.js"></script>
  <script src="https://kit.fontawesome.com/848cfc15aa.js" crossorigin="anonymous"></script>

  <style>
    .process { display:inline-block; margin-left:10px; }
    .resized-image { max-height:400px; }
    .selector-box { border:1px solid #e5e5e5; border-radius:8px; padding:10px; max-height:230px; overflow:auto; background:#fff; }
    .item-row { display:flex; align-items:center; gap:10px; padding:8px 0; }
    .item-row img { width:28px; height:28px; border-radius:6px; object-fit:cover; }
    .connected-box { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .connected-box img { width:44px; height:44px; border-radius:50%; object-fit:cover; }
    .muted { color:#666; font-size:13px; }
    .section-title { margin-top:12px; margin-bottom:6px; font-weight:600; }
  </style>

  <script>
    //FB SDK init
    window.fbAsyncInit = function() {
      FB.init({
        appId      : '<?= htmlspecialchars((string)$cfg['meta']['app_id'], ENT_QUOTES, 'UTF-8') ?>',
        cookie     : true,
        xfbml      : false,
        version    : 'v24.0'
      });
    };

    function fbLogin() {
      const btn = document.getElementById('btnConnect');
      if (btn) btn.disabled = true;

      FB.login(function(response) {
        if (btn) btn.disabled = false;

        if (response.authResponse) {
          window.location.href = "callback.php?access_token=" + encodeURIComponent(response.authResponse.accessToken);
        } else {
          console.log('User cancelled login or did not fully authorize.');
        }
      }, {
        scope: 'public_profile,pages_show_list,pages_manage_metadata,pages_read_engagement,pages_manage_posts,read_insights,instagram_basic,instagram_content_publish,instagram_manage_insights,business_management',
        return_scopes: true,
        auth_type: 'rerequest'
      });
    }

    //logout:
    function appLogout() {
      const go = () => window.location.href = 'logout.php?t=' + Date.now();


      try {
        if (typeof FB !== 'undefined' && FB && typeof FB.logout === 'function') {
          let done = false;


          setTimeout(() => { if (!done) go(); }, 1200);

          FB.logout(function() {
            done = true;
            go();
          });
          return;
        }
      } catch (e) { /* ignore */ }

      go();
    }

    //preview helpers
    function showPreview(src) {
      $("#previewImg").attr("src", src || "");
      if (src) $("#previewWrap").show();
      else $("#previewWrap").hide();
    }

    function clearImageSelection() {
      showPreview("");

      const uploadEl = document.getElementById("upload_image");
      if (uploadEl) uploadEl.value = "";

      $("#use_generated_image").prop("checked", false);
      $("#generated_filename").val("");
    }

    //image generation
    function generateImage() {
      const background = $("#background").val();
      const home = $("#home_crest").val();
      const away = $("#away_crest").val();

      const xhr = new XMLHttpRequest();
      xhr.open('GET',
        'generate.php?background=' + encodeURIComponent(background) +
        '&home_crest=' + encodeURIComponent(home) +
        '&away_crest=' + encodeURIComponent(away),
        true
      );
      xhr.responseType = 'blob';

      xhr.onload = function() {
        if (this.status === 200) {
          const reader = new FileReader();
          reader.onload = function(event) {
            showPreview(event.target.result);

            const uploadEl = document.getElementById("upload_image");
            if (uploadEl) uploadEl.value = "";

            $.getJSON('get_session.php', function(data) {
              if (data && data.myData) {
                $('#generated_filename').val(data.myData);
              }
            });
          };
          reader.readAsDataURL(this.response);
        }
      };
      xhr.send();
    }

    function updateVisibility() {
      const fbChecked = $('#post_facebook').is(':checked');
      const igChecked = $('#post_instagram').is(':checked');

      $('#fbSelectorWrap').toggle(fbChecked);
      $('#igSelectorWrap').toggle(igChecked);
      $('#imageWrap').toggle(fbChecked || igChecked);

      if (igChecked && $('#igSelectorWrap').data('has-ig') !== 1) $('#igNoChoices').show();
      else $('#igNoChoices').hide();
    }

    $(document).ready(function() {
      $('#post_facebook, #post_instagram').on('change', updateVisibility);
      updateVisibility();

      $('#btnRemoveImage').on('click', function() {
        clearImageSelection();
      });

      $("#use_generated_image").on('change', function() {
        if ($(this).is(':checked')) {
          generateImage();
        } else {
          $("#generated_filename").val("");
          const uploadEl = document.getElementById("upload_image");
          if (!uploadEl || !uploadEl.files || !uploadEl.files[0]) showPreview("");
        }
      });

      $('#upload_image').on('change', function() {
        const f = this.files && this.files[0];
        if (!f) return;

        $("#use_generated_image").prop("checked", false);
        $("#generated_filename").val("");

        const url = URL.createObjectURL(f);
        showPreview(url);
      });

      $(document).on("click", "#btnPublish", function(e) {
        e.preventDefault();
        $('.process').html('<img src="assets/processing.gif" width="40"/>');

        const fdata = new FormData();
        fdata.append("tweet", $('#content').val());

        fdata.append("post_facebook", $('#post_facebook').is(':checked'));
        fdata.append("post_instagram", $('#post_instagram').is(':checked'));

        fdata.append("fb_page_id", $('input[name="fb_page_id"]:checked').val() || '');
        fdata.append("ig_business_id", $('input[name="ig_business_id"]:checked').val() || '');

        fdata.append("use_generated_image", $('#use_generated_image').is(':checked') ? "1" : "0");
        fdata.append("generated_file", $('#generated_filename').val() || '');

        const uploadEl = document.getElementById('upload_video');
        if (uploadEl && uploadEl.files && uploadEl.files[0]) {
          fdata.append("upload_video", uploadEl.files[0]);
        }

        $.ajax({
          url: "tweet.php",
          type: "post",
          data: fdata,
          contentType: false,
          processData: false,
          success: function(resp) {
            $('.result').html(resp);
            $('.process').html('');
          },
          error: function() {
            $('.process').html('Error posting.');
          }
        });
      });
    });
  </script>
</head>

<body>
<div id="fb-root"></div>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>

<div class="container mt-5">
  <div class="row">
    <div class="col-md-12">
      <h3>Post Content</h3>

      <div class="form-group">
        <label>Connect Accounts:</label><br>

        <?php if (!$isConnected): ?>
          <button id="btnConnect" type="button" onclick="fbLogin()" class="btn btn-primary btn-sm">
            <i class="fab fa-facebook"></i> Connect FB/IG
          </button>
          <?php if (isset($_GET['logged_out'])): ?>
            <div class="alert alert-info mt-2 mb-0">Disconnected successfully.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="connected-box">
            <?php if (!empty($tokens['fb_user']['picture'])): ?>
              <img src="<?= htmlspecialchars((string)$tokens['fb_user']['picture'], ENT_QUOTES, 'UTF-8') ?>" alt="Profile">
            <?php else: ?>
              <img src="assets/user.png" alt="Profile">
            <?php endif; ?>
            <div style="flex:1;">
              <div><strong><?= htmlspecialchars((string)($tokens['fb_user']['name'] ?? 'Connected'), ENT_QUOTES, 'UTF-8') ?></strong></div>
              <span class="badge badge-success">Connected</span>
            </div>
          </div>

          <div class="d-flex flex-wrap" style="gap:8px;">
            <button id="btnReconnect" type="button" onclick="fbLogin()" class="btn btn-outline-primary btn-sm">
              Reconnect / Refresh Pages
            </button>

            <button id="btnLogout" type="button" onclick="appLogout()" class="btn btn-outline-danger btn-sm">
              Disconnect / Logout
            </button>
          </div>

          <div class="muted mt-2">
            Disconnect will remove saved <code>tokens.json</code> from this server (and clear session).
            It does not revoke Meta app permissions.
          </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="section-title">Choose Platforms:</label><br>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="post_facebook">
          <label class="form-check-label">Facebook</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="post_instagram">
          <label class="form-check-label">Instagram</label>
        </div>
        <div class="muted">Selectors below will appear based on platform selection.</div>
      </div>

      <div id="fbSelectorWrap" style="display:none;">
        <div class="section-title">Select Facebook Page</div>
        <?php if ($isConnected && !empty($tokens['pages']) && is_array($tokens['pages'])): ?>
          <div class="selector-box">
            <?php
              $selected = $tokens['selected_page_id'] ?? null;
              foreach ($tokens['pages'] as $p):
                $pid = $p['id'] ?? '';
                $pname = $p['name'] ?? '';
                $ppic = $p['picture'] ?? '';
                if (!$pid) continue;
            ?>
              <div class="item-row">
                <input type="radio" name="fb_page_id"
                       value="<?= htmlspecialchars((string)$pid, ENT_QUOTES, 'UTF-8') ?>"
                       <?= ($selected === $pid) ? 'checked' : '' ?>>
                <?php if ($ppic): ?>
                  <img src="<?= htmlspecialchars((string)$ppic, ENT_QUOTES, 'UTF-8') ?>" alt="Page">
                <?php endif; ?>
                <div><?= htmlspecialchars((string)$pname, ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="muted mt-1">Choose one Facebook Page to post to.</div>
        <?php else: ?>
          <div class="alert alert-warning">Connect FB/IG first.</div>
        <?php endif; ?>
      </div>

      <div id="igSelectorWrap" style="display:none;" data-has-ig="<?= count($igChoices) ? 1 : 0 ?>">
        <div class="section-title">Select Instagram Business (linked)</div>

        <div id="igNoChoices" class="alert alert-warning" style="display:none;">
          No Instagram Business accounts were found linked to your Pages.
          Link your IG Professional account to a Page in Meta Business Suite, then Reconnect.
        </div>

        <?php if (count($igChoices)): ?>
          <div class="selector-box">
            <?php foreach ($igChoices as $i => $ig): ?>
              <div class="item-row">
                <input type="radio" name="ig_business_id"
                       value="<?= htmlspecialchars((string)$ig['ig_business_id'], ENT_QUOTES, 'UTF-8') ?>"
                       <?= $i === 0 ? 'checked' : '' ?>>
                <?php if (!empty($ig['picture'])): ?>
                  <img src="<?= htmlspecialchars((string)$ig['picture'], ENT_QUOTES, 'UTF-8') ?>" alt="IG via Page">
                <?php endif; ?>
                <div>
                  <?= htmlspecialchars((string)$ig['page_name'], ENT_QUOTES, 'UTF-8') ?>
                  <div class="muted">IG ID: <?= htmlspecialchars((string)$ig['ig_business_id'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="muted mt-1">This posts to the Instagram Business account linked to that Page.</div>
        <?php endif; ?>
      </div>

      <div class="form-group mt-3">
        <textarea class="form-control" id="content" rows="5" placeholder="Write your post..."></textarea>
      </div>

      <div id="imageWrap" style="display:none;">
        <div class="section-title">Video (optional for Facebook, required for Instagram)</div>

        <div class="form-group">
          <label class="muted">Upload a video</label>
          <input type="file" id="upload_video" class="form-control" accept="video/mp4,video/x-m4v,video/*">
          <small class="muted">If you upload, it will be used for both Facebook + Instagram.</small>
        </div>

        <!-- <div class="form-check mb-2">
          <input type="checkbox" class="form-check-input" id="use_generated_image">
          <label class="form-check-label" for="use_generated_image">Or use generated image</label>
        </div> -->

        <input type="hidden" id="generated_filename" value="">
      </div>

      <button class="btn btn-success" id="btnPublish">Publish Post</button>
      <div class="process"></div>
      <div class="result mt-3"></div>

    </div>

    <div class="col-md-6 d-none">
      <h3>Video Preview</h3>
      <div id="previewWrap" style="position:relative; display:none;">
        <button type="button" id="btnRemoveImage"
                class="btn btn-sm btn-light"
                style="position:absolute; top:8px; right:8px; z-index:10; border-radius:999px;">
          ✕
        </button>
        <video id="previewImg" class="img-fluid border" controls src="" style="width: 100%; max-height: 400px;"></video>
      </div>

      <div class="mt-3">
        <input type="text" id="background" class="form-control mb-1" value="images/twitterbgf.png">
        <input type="text" id="home_crest" class="form-control mb-1" value="images/home.png">
        <input type="text" id="away_crest" class="form-control mb-1" value="images/away.png">
      </div>
    </div>
  </div>
</div>
</body>
</html>
