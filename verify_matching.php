<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$admin_id = (int)$_SESSION['admin_id'];
include 'dbconnect_hdb.php';

// ── AJAX: save rating ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $scene_id = (int)($_POST['scene_id'] ?? 0);
    $rating   = in_array($_POST['rating'] ?? '', ['good','bad','']) ? $_POST['rating'] : '';
    $note     = mysqli_real_escape_string($conn, $_POST['note'] ?? '');
    if ($scene_id) {
        $ok = mysqli_query($conn,
            "UPDATE hdb_podcast_stories SET prompt_5='$rating|$note'
             WHERE id=$scene_id AND podcast_id IN
               (SELECT id FROM hdb_podcasts WHERE admin_id=$admin_id)");
        echo json_encode(['success' => (bool)$ok]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ── Helpers (defined once outside loop) ──────────────────────
function tokeniseNL(string $s): array {
    $s = strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $s));
    return array_unique(array_filter(explode(' ', $s), fn($w) => strlen($w) > 2));
}
function tagIsMatchedNL(string $phrase, array $matched): bool {
    $tokens = array_filter(
        explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $phrase))),
        fn($w) => strlen($w) > 2
    );
    return !empty(array_intersect($tokens, $matched));
}
function highlightMatchesNL(string $phrase, array $matched): string {
    $parts = preg_split('/(\b)/', $phrase, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out   = '';
    foreach ($parts as $w) {
        $wl = strtolower(preg_replace('/[^a-z0-9]/i', '', $w));
        if (strlen($wl) > 2 && in_array($wl, $matched)) {
            $out .= '<strong style="color:#92400e;background:#fef3c7;border-radius:2px;padding:0 2px;">'
                  . htmlspecialchars($w) . '</strong>';
        } else {
            $out .= htmlspecialchars($w);
        }
    }
    return $out;
}

// ── Load podcasts ─────────────────────────────────────────────
$podcasts = [];
$pq = mysqli_query($conn,
    "SELECT id, title, created_date, lang_code FROM hdb_podcasts
     WHERE admin_id=$admin_id ORDER BY id DESC LIMIT 100");
while ($r = mysqli_fetch_assoc($pq)) $podcasts[] = $r;

// ── Load scenes + asset data ──────────────────────────────────
$scenes     = [];
$podcast    = null;
$podcast_id = (int)($_GET['podcast_id'] ?? 0);

if ($podcast_id) {
    $podq = mysqli_query($conn,
        "SELECT * FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1");
    if ($podq && mysqli_num_rows($podq) > 0) {
        $podcast = mysqli_fetch_assoc($podq);
        $sq = mysqli_query($conn,
            "SELECT s.*,
                    d.natural_language_tags AS asset_nl_tags,
                    d.image_hashtags        AS asset_hashtags,
                    d.media_type            AS asset_media_type
             FROM hdb_podcast_stories s
             LEFT JOIN hdb_image_data d ON d.image_name = s.image_file
             WHERE s.podcast_id=$podcast_id
             ORDER BY s.seq_no ASC");
        while ($r = mysqli_fetch_assoc($sq)) $scenes[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scene QA — VideoVizard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #f1f5f9;
  --surf:     #ffffff;
  --surf2:    #f8fafc;
  --border:   #e2e8f0;
  --border2:  #cbd5e1;
  --text:     #1e293b;
  --muted:    #64748b;
  --accent:   #0f6cbd;
  --good:     #16a34a;
  --bad:      #dc2626;
  --match-bg: #fef3c7;
  --match-txt:#92400e;
  --scene-bg: #eff6ff;
  --scene-bdr:#bfdbfe;
  --asset-bg: #fffbeb;
  --asset-bdr:#fde68a;
  --shadow:   0 1px 3px rgba(0,0,0,.08);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── Header ── */
.hdr{background:var(--surf);border-bottom:1px solid var(--border);padding:13px 22px;
     display:flex;align-items:center;gap:12px;flex-wrap:wrap;
     position:sticky;top:0;z-index:100;box-shadow:var(--shadow);}
.hdr-title{font-size:16px;font-weight:700;color:#0f2a44;white-space:nowrap;}
.hdr-title span{color:var(--accent);}
.pod-sel{flex:1;min-width:220px;max-width:480px;padding:8px 12px;
         background:var(--surf2);border:1px solid var(--border2);border-radius:8px;
         color:var(--text);font-size:13px;outline:none;cursor:pointer;}
.pod-sel:focus{border-color:var(--accent);}
.hdr-stats{display:flex;gap:12px;margin-left:auto;font-size:12px;}
.hdr-stat{color:var(--muted);}
.hdr-stat strong{color:var(--text);}
.hdr-stat.good strong{color:var(--good);}
.hdr-stat.bad  strong{color:var(--bad);}

/* ── Legend ── */
.legend{background:#fff;border-bottom:1px solid var(--border);
        padding:7px 22px;display:flex;gap:18px;flex-wrap:wrap;font-size:11px;color:var(--muted);}
.leg{display:flex;align-items:center;gap:5px;}
.leg-dot{width:11px;height:11px;border-radius:3px;flex-shrink:0;}

/* ── Main ── */
.main{padding:20px;max-width:1600px;margin:0 auto;}
.empty{text-align:center;padding:70px 20px;color:var(--muted);}
.empty h2{font-size:20px;font-weight:700;color:var(--text);margin-bottom:8px;}

/* ── Grid — narrower columns so 9:16 doesn't get too tall ── */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;}

/* ── Card ── */
.card{background:var(--surf);border:1.5px solid var(--border);border-radius:14px;
      overflow:hidden;transition:border-color .2s,box-shadow .2s;position:relative;
      box-shadow:var(--shadow);}
.card:hover{border-color:var(--accent);box-shadow:0 4px 14px rgba(15,108,189,.1);}
.card.rated-good{border-color:var(--good);}
.card.rated-bad {border-color:var(--bad);}

.scene-num{position:absolute;top:8px;left:8px;background:rgba(0,0,0,.6);
           color:#fff;font-size:10px;font-weight:600;padding:2px 7px;
           border-radius:4px;z-index:10;}
.rate-badge{position:absolute;top:8px;right:8px;width:24px;height:24px;
            border-radius:50%;display:none;align-items:center;justify-content:center;
            font-size:12px;font-weight:700;z-index:10;}
.rate-badge.good{display:flex;background:var(--good);color:#fff;}
.rate-badge.bad {display:flex;background:var(--bad); color:#fff;}

/* ── Media area — 9:16 portrait ── */
.media-row{display:flex;gap:5px;padding:8px 8px 0;background:var(--surf2);
           border-bottom:1px solid var(--border);}

/* primary 9:16 box */
.media-primary{flex-shrink:0;width:90px;position:relative;}
.media-9x16{width:90px;aspect-ratio:9/16;background:#e2e8f0;border-radius:7px;
            overflow:hidden;position:relative;border:1px solid var(--border);}
.media-9x16 img{width:100%;height:100%;object-fit:cover;display:block;}
.media-9x16 video{width:100%;height:100%;object-fit:cover;display:block;}
.no-media-sm{width:100%;height:100%;display:flex;flex-direction:column;
             align-items:center;justify-content:center;gap:4px;
             color:var(--muted);font-size:9px;text-align:center;padding:4px;}
.no-media-sm .nm-icon{font-size:22px;opacity:.4;}

/* video play overlay */
.vid-overlay{position:absolute;inset:0;display:flex;align-items:center;
             justify-content:center;background:rgba(0,0,0,.3);cursor:pointer;
             transition:background .2s;border-radius:7px;}
.vid-overlay:hover{background:rgba(0,0,0,.5);}
.play-btn{width:28px;height:28px;background:rgba(255,255,255,.9);border-radius:50%;
          display:flex;align-items:center;justify-content:center;pointer-events:none;}
.play-btn::after{content:'';display:block;width:0;height:0;
                 border-top:7px solid transparent;border-bottom:7px solid transparent;
                 border-left:11px solid #1e293b;margin-left:2px;}
.vid-type-badge{position:absolute;bottom:4px;left:4px;background:rgba(109,40,217,.85);
                color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;}

/* extra thumbs column */
.extra-col{flex:1;display:flex;flex-direction:column;gap:4px;padding-bottom:8px;overflow:hidden;}
.extra-col-label{font-size:9px;font-weight:600;color:var(--muted);text-transform:uppercase;
                 letter-spacing:.05em;margin-bottom:2px;}
.extras-wrap{display:flex;flex-wrap:wrap;gap:4px;}
.ext-thumb{width:48px;height:27px;object-fit:cover;border-radius:4px;
           border:1px solid var(--border);cursor:pointer;transition:border-color .15s;}
.ext-thumb:hover{border-color:var(--accent);}
.ext-ph{width:48px;height:27px;background:var(--bg);border-radius:4px;
        border:1px dashed var(--border2);display:flex;align-items:center;
        justify-content:center;font-size:12px;color:var(--muted);}
.ext-vid-ph{width:48px;height:27px;background:#ede9fe;border-radius:4px;
            border:1px solid #c4b5fd;display:flex;align-items:center;
            justify-content:center;font-size:11px;cursor:pointer;}

/* ── Card body ── */
.cbody{padding:12px 14px;}

.scene-txt{font-size:11px;line-height:1.6;color:var(--text);margin-bottom:10px;
           font-family:'DM Mono',monospace;background:var(--surf2);
           padding:7px 9px;border-radius:6px;border-left:3px solid var(--accent);}

/* file info */
.file-info{font-size:10px;color:var(--muted);margin-bottom:9px;
           display:flex;align-items:center;gap:5px;font-family:'DM Mono',monospace;flex-wrap:wrap;}
.fbadge{padding:1px 6px;border-radius:3px;font-size:9px;font-weight:700;text-transform:uppercase;}
.fbadge.img{background:#dbeafe;color:#1d4ed8;}
.fbadge.vid{background:#ede9fe;color:#6d28d9;}
.fbadge.none{background:#fee2e2;color:#b91c1c;}

/* match score */
.match-score{display:flex;align-items:center;gap:7px;margin-bottom:10px;font-size:11px;}
.score-label{color:var(--muted);white-space:nowrap;min-width:65px;}
.score-bar{flex:1;height:5px;background:var(--border);border-radius:3px;overflow:hidden;}
.score-fill{height:100%;border-radius:3px;}
.score-val{font-size:11px;font-weight:700;min-width:32px;text-align:right;}

/* tag grid */
.tag-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
.tag-col-lbl{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;
             letter-spacing:.06em;margin-bottom:5px;}
.tag-col-lbl.asset-lbl{color:#92400e;}
.tags{display:flex;flex-wrap:wrap;gap:3px;}
.tag{font-size:10px;padding:2px 7px;border-radius:15px;border:1px solid transparent;line-height:1.5;}
.tag.scene-tag{background:var(--scene-bg);color:#1d4ed8;border-color:var(--scene-bdr);}
.tag.scene-tag.matched{background:var(--match-bg);color:var(--match-txt);border-color:#fbbf24;font-weight:600;}
.tag.asset-tag{background:var(--asset-bg);color:#92400e;border-color:var(--asset-bdr);}
.tag.asset-tag.matched{background:var(--match-bg);color:var(--match-txt);border-color:#fbbf24;font-weight:600;}
.tag.empty-tag{color:var(--muted);background:var(--surf2);border-color:var(--border);font-style:italic;}

/* hashtags */
.ht-row{font-size:10px;color:var(--muted);margin-bottom:10px;font-family:'DM Mono',monospace;}
.ht-row span{color:var(--accent);margin-right:3px;}

.divider{height:1px;background:var(--border);margin-bottom:10px;}

/* rating */
.rate-row{display:flex;gap:7px;}
.rbtn{flex:1;padding:8px;border:1.5px solid var(--border2);border-radius:7px;
      background:var(--surf2);color:var(--muted);font-size:12px;font-weight:600;
      cursor:pointer;transition:all .15s;display:flex;align-items:center;
      justify-content:center;gap:4px;}
.rbtn.good:hover,.rbtn.good.active{border-color:var(--good);color:var(--good);background:#f0fdf4;}
.rbtn.bad:hover, .rbtn.bad.active {border-color:var(--bad); color:var(--bad); background:#fef2f2;}
.note-ta{width:100%;margin-top:7px;padding:7px 9px;background:var(--surf2);
         border:1px solid var(--border2);border-radius:6px;color:var(--text);
         font-family:'DM Mono',monospace;font-size:10px;outline:none;resize:none;display:none;}
.note-ta:focus{border-color:var(--accent);}
.note-ta.show{display:block;}
.save-btn{margin-top:5px;padding:5px 13px;background:var(--accent);color:#fff;
          border:none;border-radius:5px;font-size:11px;font-weight:600;
          cursor:pointer;display:none;transition:opacity .15s;}
.save-btn.show{display:inline-flex;}
.save-btn:hover{opacity:.85;}

/* video modal */
.vid-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);
           z-index:999;align-items:center;justify-content:center;padding:20px;}
.vid-modal.open{display:flex;}
.vid-modal-inner{position:relative;width:100%;max-width:360px;}
.vid-modal video{width:100%;border-radius:12px;display:block;max-height:80vh;}
.vid-modal-close{position:absolute;top:-14px;right:-14px;width:32px;height:32px;
                 background:#fff;border-radius:50%;border:none;cursor:pointer;
                 font-size:18px;line-height:1;display:flex;align-items:center;
                 justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.3);}

.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
       background:#1e293b;color:#fff;padding:8px 18px;border-radius:8px;
       font-size:12px;font-weight:600;z-index:9999;transition:opacity .3s;pointer-events:none;}

@media(max-width:600px){.main{padding:10px;}.grid{grid-template-columns:1fr;}
  .tag-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- Video modal -->
<div class="vid-modal" id="vidModal" onclick="closeVidModal(event)">
  <div class="vid-modal-inner">
    <button class="vid-modal-close" onclick="closeVidModal()">✕</button>
    <video id="modalVideo" controls playsinline></video>
  </div>
</div>

<div class="hdr">
  <div class="hdr-title">🎬 Scene <span>QA</span></div>
  <form method="GET" style="display:contents">
    <select class="pod-sel" name="podcast_id" onchange="this.form.submit()">
      <option value="">— Select a podcast —</option>
      <?php foreach ($podcasts as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $p['id']==$podcast_id?'selected':'' ?>>
        #<?= $p['id'] ?> — <?= htmlspecialchars(substr($p['title'],0,55)) ?>
        (<?= $p['lang_code'] ?> · <?= $p['created_date'] ?>)
      </option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php if ($podcast): ?>
  <div class="hdr-stats">
    <div class="hdr-stat good">✓ Good: <strong id="gc">0</strong></div>
    <div class="hdr-stat bad">✗ Bad: <strong id="bc">0</strong></div>
    <div class="hdr-stat">Unrated: <strong id="uc"><?= count($scenes) ?></strong></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($podcast): ?>
<div class="legend">
  <div class="leg"><div class="leg-dot" style="background:var(--scene-bg);border:1px solid var(--scene-bdr);"></div>Scene NL tag</div>
  <div class="leg"><div class="leg-dot" style="background:var(--asset-bg);border:1px solid var(--asset-bdr);"></div>Asset NL tag</div>
  <div class="leg"><div class="leg-dot" style="background:var(--match-bg);border:1px solid #fbbf24;"></div>Matched word</div>
  <div class="leg">🎥 Click video thumbnail to play</div>
</div>
<?php endif; ?>

<div class="main">
<?php if (!$podcast_id): ?>
  <div class="empty">
    <div style="font-size:56px;margin-bottom:16px;">🎬</div>
    <h2>Select a podcast to review</h2>
    <p>Choose from the dropdown to inspect scene ↔ media tag matching</p>
  </div>

<?php elseif (empty($scenes)): ?>
  <div class="empty">
    <div style="font-size:56px;margin-bottom:16px;">📭</div>
    <h2>No scenes found</h2>
  </div>

<?php else: ?>
<div class="grid">
<?php foreach ($scenes as $idx => $scene):
  $sid      = $scene['id'];
  $seq      = $scene['seq_no'] ?? ($idx+1);
  $text     = trim(preg_replace('/<break[^>]*>/i', '', $scene['text_contents'] ?? ''));
  $img_file = $scene['image_file'] ?? '';
  $extras   = [
    $scene['image_file_1'] ?? '',
    $scene['image_file_2'] ?? '',
    $scene['image_file_3'] ?? '',
    $scene['image_file_4'] ?? '',
  ];

  $scene_nl_raw  = $scene['natural_language_tags'] ?? '';
  $asset_nl_raw  = $scene['asset_nl_tags'] ?? '';
  $scene_nl_tags = array_filter(array_map('trim', explode('|', $scene_nl_raw)));
  $asset_nl_tags = array_filter(array_map('trim', explode('|', $asset_nl_raw)));

  $scene_tokens  = tokeniseNL($scene_nl_raw);
  $asset_tokens  = tokeniseNL($asset_nl_raw);
  $matched_words = array_intersect($scene_tokens, $asset_tokens);
  $union         = array_unique(array_merge($scene_tokens, $asset_tokens));
  $match_score   = count($union) > 0 ? round(count($matched_words) / count($union), 2) : 0;
  $score_pct     = (int)($match_score * 100);
  $score_color   = $score_pct >= 40 ? '#16a34a' : ($score_pct >= 20 ? '#d97706' : '#dc2626');

  $is_video = $img_file && preg_match('/\.(mp4|webm|mov)$/i', $img_file);
  $is_image = $img_file && !$is_video;

  $rating_raw = $scene['prompt_5'] ?? '';
  $rating = $note = '';
  if ($rating_raw && strpos($rating_raw, '|') !== false) {
    [$rating, $note] = explode('|', $rating_raw, 2);
  }
  $card_cls = $rating ? "card rated-$rating" : "card";
?>
<div class="<?= $card_cls ?>" id="card-<?= $sid ?>">

  <div class="scene-num">Scene <?= $seq ?></div>
  <div class="rate-badge <?= $rating ?>" id="rb-<?= $sid ?>"><?= $rating==='good'?'✓':($rating==='bad'?'✗':'') ?></div>

  <!-- Media row: 9:16 primary + extra thumbs -->
  <div class="media-row">

    <!-- Primary media — 9:16 -->
    <div class="media-primary">
      <div class="media-9x16" id="mw-<?= $sid ?>">
        <?php if ($is_video): ?>
          <video src="podcast_images/<?= htmlspecialchars($img_file) ?>"
                 id="vid-<?= $sid ?>" preload="metadata"
                 style="width:100%;height:100%;object-fit:cover;display:block;"></video>
          <div class="vid-overlay" onclick="openVidModal('podcast_images/<?= htmlspecialchars($img_file) ?>')">
            <div class="play-btn"></div>
          </div>
          <div class="vid-type-badge">VIDEO</div>
        <?php elseif ($is_image): ?>
          <img src="podcast_images/<?= htmlspecialchars($img_file) ?>"
               alt="Scene <?= $seq ?>"
               onerror="this.parentNode.innerHTML='<div class=no-media-sm><div class=nm-icon>🖼️</div><span>Not found</span></div>'">
        <?php else: ?>
          <div class="no-media-sm">
            <div class="nm-icon">📷</div>
            <span>No media</span>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Extra thumbnails -->
    <div class="extra-col">
      <div class="extra-col-label">Extra images</div>
      <div class="extras-wrap">
        <?php foreach ($extras as $ef):
          if (empty($ef)) {
            echo '<div class="ext-ph">+</div>';
            continue;
          }
          $ev = preg_match('/\.(mp4|webm|mov)$/i', $ef);
          if ($ev):
        ?>
          <div class="ext-vid-ph" title="<?= htmlspecialchars($ef) ?>"
               onclick="openVidModal('podcast_images/<?= htmlspecialchars($ef) ?>')">▶</div>
        <?php else: ?>
          <img class="ext-thumb"
               src="podcast_images/<?= htmlspecialchars($ef) ?>"
               title="<?= htmlspecialchars($ef) ?>"
               onclick="openVidModal('podcast_images/<?= htmlspecialchars($ef) ?>')"
               onerror="this.style.opacity='.2'">
        <?php endif; endforeach; ?>
      </div>
    </div>

  </div>

  <!-- Card body -->
  <div class="cbody">

    <div class="scene-txt"><?= htmlspecialchars($text) ?></div>

    <!-- File info -->
    <div class="file-info">
      <?php if ($img_file): ?>
        <span class="fbadge <?= $is_video?'vid':'img' ?>"><?= $is_video?'🎥 video':'🖼 image' ?></span>
        <span style="word-break:break-all;"><?= htmlspecialchars($img_file) ?></span>
      <?php else: ?>
        <span class="fbadge none">no file</span><span>Not assigned</span>
      <?php endif; ?>
    </div>

    <!-- Match score -->
    <div class="match-score">
      <span class="score-label">Tag match</span>
      <div class="score-bar">
        <div class="score-fill" style="width:<?= $score_pct ?>%;background:<?= $score_color ?>;"></div>
      </div>
      <span class="score-val" style="color:<?= $score_color ?>"><?= $score_pct ?>%</span>
    </div>

    <!-- Side-by-side NL tags -->
    <div class="tag-grid">
      <div>
        <div class="tag-col-lbl">📄 Scene NL tags</div>
        <div class="tags">
          <?php if (!empty($scene_nl_tags)):
            foreach ($scene_nl_tags as $tag):
              $m = tagIsMatchedNL($tag, $matched_words); ?>
            <span class="tag scene-tag <?= $m?'matched':'' ?>"><?= highlightMatchesNL($tag, $matched_words) ?></span>
          <?php endforeach; else: ?>
            <span class="tag empty-tag">no tags</span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <div class="tag-col-lbl asset-lbl">🖼 Asset NL tags</div>
        <div class="tags">
          <?php if (!empty($asset_nl_tags)):
            foreach ($asset_nl_tags as $tag):
              $m = tagIsMatchedNL($tag, $matched_words); ?>
            <span class="tag asset-tag <?= $m?'matched':'' ?>"><?= highlightMatchesNL($tag, $matched_words) ?></span>
          <?php endforeach; else: ?>
            <span class="tag empty-tag"><?= $img_file?'no asset tags':'no asset' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Hashtags -->
    <?php $ht = trim($scene['hashtags'] ?? ''); if ($ht): ?>
    <div class="ht-row">
      <?php foreach (preg_split('/\s+/', $ht) as $h): if(trim($h)): ?>
        <span>#<?= htmlspecialchars(ltrim($h,'#')) ?></span>
      <?php endif; endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Rating -->
    <div class="rate-row">
      <button class="rbtn good <?= $rating==='good'?'active':'' ?>"
              onclick="rate(<?= $sid ?>,'good',this)">✓ Good</button>
      <button class="rbtn bad <?= $rating==='bad'?'active':'' ?>"
              onclick="rate(<?= $sid ?>,'bad',this)">✗ Bad</button>
    </div>
    <textarea class="note-ta <?= $note?'show':'' ?>" id="nt-<?= $sid ?>"
              placeholder="Why is this a bad match?…" rows="2"><?= htmlspecialchars($note) ?></textarea>
    <button class="save-btn <?= $note?'show':'' ?>" onclick="saveNote(<?= $sid ?>)">Save note</button>

  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<div class="toast" id="toast" style="opacity:0"></div>

<script>
document.addEventListener('DOMContentLoaded', updateCounts);

function updateCounts(){
  const good = document.querySelectorAll('.card.rated-good').length;
  const bad  = document.querySelectorAll('.card.rated-bad').length;
  const tot  = document.querySelectorAll('.card').length;
  const gc=document.getElementById('gc'),bc=document.getElementById('bc'),uc=document.getElementById('uc');
  if(gc) gc.textContent=good;
  if(bc) bc.textContent=bad;
  if(uc) uc.textContent=tot-good-bad;
}

// ── Video modal ───────────────────────────────────────────────
function openVidModal(src){
  const mv = document.getElementById('modalVideo');
  mv.src = src;
  mv.load();
  mv.play().catch(()=>{});
  document.getElementById('vidModal').classList.add('open');
}
function closeVidModal(e){
  if(e && e.target !== document.getElementById('vidModal') && !e.target.classList.contains('vid-modal-close')) return;
  const mv = document.getElementById('modalVideo');
  mv.pause(); mv.src = '';
  document.getElementById('vidModal').classList.remove('open');
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeVidModal({target:document.getElementById('vidModal')}); });

// ── Rating ────────────────────────────────────────────────────
function rate(id, rating, btn){
  const card=document.getElementById('card-'+id);
  const rb=document.getElementById('rb-'+id);
  const note=document.getElementById('nt-'+id);
  const saveBtn=note?.nextElementSibling;
  const isActive=btn.classList.contains('active');
  const nr=isActive?'':rating;
  card.querySelectorAll('.rbtn').forEach(b=>b.classList.remove('active'));
  if(nr){
    btn.classList.add('active');
    card.className='card rated-'+nr;
    rb.className='rate-badge '+nr;
    rb.textContent=nr==='good'?'✓':'✗';
    if(note) note.classList.add('show');
    if(saveBtn) saveBtn.classList.add('show');
  } else {
    card.className='card';
    rb.className='rate-badge';
    rb.textContent='';
    if(note) note.classList.remove('show');
    if(saveBtn) saveBtn.classList.remove('show');
  }
  persist(id,nr,note?.value||'');
  updateCounts();
}

function saveNote(id){
  const note=document.getElementById('nt-'+id);
  const card=document.getElementById('card-'+id);
  const r=card.classList.contains('rated-good')?'good':card.classList.contains('rated-bad')?'bad':'';
  persist(id,r,note?.value||'');
}

function persist(id,rating,note){
  const fd=new FormData();
  fd.append('ajax','1');fd.append('scene_id',id);
  fd.append('rating',rating);fd.append('note',note);
  fetch(window.location.href,{method:'POST',body:fd})
    .then(r=>r.json())
    .then(d=>showToast(d.success?(rating?'✓ Saved — '+rating:'Cleared'):'⚠ Save failed'))
    .catch(()=>showToast('⚠ Network error'));
}

function showToast(msg){
  const t=document.getElementById('toast');
  t.textContent=msg;t.style.opacity='1';
  setTimeout(()=>t.style.opacity='0',2000);
}
</script>
</body>
</html>
