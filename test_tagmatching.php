<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
$admin_id = (int)$_SESSION['admin_id'];
include 'dbconnect_hdb.php';
require_once 'config.php';

// ── Helpers ───────────────────────────────────────────────────
function getEmbeddingDiag(string $text, string $apiKey): ?array {
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'text-embedding-3-large',
            'input' => $text
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $data = json_decode($resp, true);
    return $data['data'][0]['embedding'] ?? null;
}

function cosineDiag(array $a, array $b): float {
    $dot = $na = $nb = 0.0;
    for ($i = 0; $i < count($a); $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na == 0 || $nb == 0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

function jaccardDiag(string $a, string $b): float {
    $ta = array_unique(array_filter(
        explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $a))),
        fn($w) => strlen($w) > 2
    ));
    $tb = array_unique(array_filter(
        explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', $b))),
        fn($w) => strlen($w) > 2
    ));
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    return $union > 0 ? round($inter / $union, 3) : 0;
}

// ── Collect all diagnostics ───────────────────────────────────
$report = [];

// 1. Asset table overview
$r1 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM hdb_image_data"));
$r2 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN natural_language_tags IS NULL OR natural_language_tags='' THEN 1 ELSE 0 END) as no_nl,
        SUM(CASE WHEN embedding IS NULL OR embedding='' THEN 1 ELSE 0 END) as no_embed,
        SUM(CASE WHEN image_hashtags IS NULL OR image_hashtags='' THEN 1 ELSE 0 END) as no_hash,
        SUM(CASE WHEN media_type='video' THEN 1 ELSE 0 END) as videos,
        SUM(CASE WHEN media_type='image' OR media_type IS NULL THEN 1 ELSE 0 END) as images
     FROM hdb_image_data"));

$total_assets   = (int)$r1['total'];
$no_nl          = (int)$r2['no_nl'];
$no_embed       = (int)$r2['no_embed'];
$no_hash        = (int)$r2['no_hash'];
$asset_videos   = (int)$r2['videos'];
$asset_images   = (int)$r2['images'];
$has_nl_pct     = $total_assets > 0 ? round(100 - ($no_nl / $total_assets * 100)) : 0;
$has_embed_pct  = $total_assets > 0 ? round(100 - ($no_embed / $total_assets * 100)) : 0;

// 2. Sample 5 asset NL tags
$sample_assets = [];
$aq = mysqli_query($conn,
    "SELECT image_name, natural_language_tags, image_hashtags, media_type,
            CASE WHEN embedding IS NOT NULL AND embedding != '' THEN 1 ELSE 0 END as has_embed
     FROM hdb_image_data
     WHERE natural_language_tags IS NOT NULL AND natural_language_tags != ''
     ORDER BY RAND() LIMIT 5");
while ($r = mysqli_fetch_assoc($aq)) $sample_assets[] = $r;

// 3. Most recent podcast for this admin
$podcast = null;
$podcast_id = (int)($_GET['podcast_id'] ?? 0);
if (!$podcast_id) {
    $pq = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title FROM hdb_podcasts WHERE admin_id=$admin_id ORDER BY id DESC LIMIT 1"));
    if ($pq) { $podcast = $pq; $podcast_id = (int)$pq['id']; }
} else {
    $pq = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id, title FROM hdb_podcasts WHERE id=$podcast_id AND admin_id=$admin_id LIMIT 1"));
    if ($pq) $podcast = $pq;
}

// All podcasts for switcher
$all_podcasts = [];
$apq = mysqli_query($conn,
    "SELECT id, title FROM hdb_podcasts WHERE admin_id=$admin_id ORDER BY id DESC LIMIT 50");
while ($r = mysqli_fetch_assoc($apq)) $all_podcasts[] = $r;

// 4. Scene data for selected podcast
$scene_diag = [];
if ($podcast_id) {
    $sq = mysqli_query($conn,
        "SELECT s.id, s.seq_no, s.text_contents, s.natural_language_tags, s.hashtags,
                s.image_file, s.prompt,
                d.natural_language_tags AS asset_nl,
                d.image_hashtags AS asset_hash,
                d.embedding AS asset_embed,
                d.media_type AS asset_type
         FROM hdb_podcast_stories s
         LEFT JOIN hdb_image_data d ON d.image_name = s.image_file
         WHERE s.podcast_id = $podcast_id
         ORDER BY s.seq_no ASC
         LIMIT 20");
    while ($r = mysqli_fetch_assoc($sq)) {
        $jaccard = jaccardDiag($r['natural_language_tags'] ?? '', $r['asset_nl'] ?? '');

        // Cosine via embedding if available
        $cosine    = null;
        $embed_ok  = false;
        if (!empty($r['asset_embed'])) {
            $av = json_decode($r['asset_embed'], true);
            if ($av && !empty($r['natural_language_tags'])) {
                $sv = getEmbeddingDiag(
                    str_replace('|', ', ', $r['natural_language_tags']),
                    $apiKey
                );
                if ($sv) {
                    $cosine   = round(cosineDiag($sv, $av), 3);
                    $embed_ok = true;
                }
            }
        }

        $scene_diag[] = [
            'id'         => $r['id'],
            'seq'        => $r['seq_no'],
            'text'       => preg_replace('/<break[^>]*>/i', '', $r['text_contents'] ?? ''),
            'scene_nl'   => $r['natural_language_tags'] ?? '',
            'scene_hash' => $r['hashtags'] ?? '',
            'prompt'     => $r['prompt'] ?? '',
            'image_file' => $r['image_file'] ?? '',
            'asset_nl'   => $r['asset_nl'] ?? '',
            'asset_hash' => $r['asset_hash'] ?? '',
            'asset_type' => $r['asset_type'] ?? '',
            'has_embed'  => !empty($r['asset_embed']),
            'jaccard'    => $jaccard,
            'cosine'     => $cosine,
            'embed_ok'   => $embed_ok,
        ];
    }
}

// 5. Embedding API test
$embed_test_ok  = false;
$embed_test_msg = '';
$tv = getEmbeddingDiag('test financial adviser', $apiKey);
if ($tv) { $embed_test_ok = true; $embed_test_msg = 'API reachable (' . count($tv) . ' dims)'; }
else       { $embed_test_msg = 'API failed — check $apiKey in config.php'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Media Matching Diagnostics</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#f1f5f9;--surf:#fff;--surf2:#f8fafc;--border:#e2e8f0;--text:#1e293b;
      --muted:#64748b;--accent:#0f6cbd;--good:#16a34a;--warn:#d97706;--bad:#dc2626;
      --shadow:0 1px 3px rgba(0,0,0,.08);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);font-size:14px;}
.hdr{background:var(--surf);border-bottom:1px solid var(--border);padding:14px 24px;
     position:sticky;top:0;z-index:100;box-shadow:var(--shadow);
     display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.hdr-title{font-size:16px;font-weight:700;color:#0f2a44;white-space:nowrap;}
.hdr-title span{color:var(--accent);}
.pod-sel{flex:1;min-width:200px;max-width:420px;padding:7px 11px;background:var(--surf2);
         border:1px solid var(--border);border-radius:7px;font-size:13px;outline:none;cursor:pointer;}
.pod-sel:focus{border-color:var(--accent);}
.main{padding:22px;max-width:1400px;margin:0 auto;}
h2{font-size:15px;font-weight:700;color:#0f2a44;margin-bottom:12px;
   display:flex;align-items:center;gap:8px;}
.section{background:var(--surf);border:1px solid var(--border);border-radius:12px;
         padding:18px 20px;margin-bottom:20px;box-shadow:var(--shadow);}

/* stat grid */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:0;}
.stat-box{background:var(--surf2);border:1px solid var(--border);border-radius:9px;
          padding:12px 14px;text-align:center;}
.stat-box .sv{font-size:26px;font-weight:700;line-height:1;}
.stat-box .sl{font-size:11px;color:var(--muted);margin-top:4px;}
.sv.good{color:var(--good);}
.sv.warn{color:var(--warn);}
.sv.bad {color:var(--bad);}

/* status pill */
.pill{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;
      border-radius:20px;font-size:11px;font-weight:600;}
.pill.ok  {background:#dcfce7;color:#166534;}
.pill.warn{background:#fef3c7;color:#92400e;}
.pill.fail{background:#fee2e2;color:#991b1b;}

/* bar */
.bar-wrap{display:flex;align-items:center;gap:8px;font-size:12px;}
.bar{flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;}
.bar-val{min-width:36px;font-weight:700;font-size:12px;text-align:right;}

/* sample assets */
.asset-row{display:flex;align-items:flex-start;gap:12px;padding:10px 0;
           border-bottom:1px solid var(--border);}
.asset-row:last-child{border-bottom:none;}
.asset-thumb{width:60px;height:34px;object-fit:cover;border-radius:5px;
             border:1px solid var(--border);flex-shrink:0;background:var(--surf2);}
.asset-info{flex:1;font-size:12px;}
.asset-name{font-family:'DM Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:3px;}
.nl-preview{color:var(--text);line-height:1.5;}
.hash-preview{color:var(--accent);font-size:11px;margin-top:2px;}

/* scene table */
.tbl-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12px;}
thead th{background:#0f2a44;color:rgba(255,255,255,.85);padding:9px 12px;
         text-align:left;font-size:11px;font-weight:600;white-space:nowrap;}
thead th:first-child{border-radius:8px 0 0 0;}
thead th:last-child {border-radius:0 8px 0 0;}
tbody tr{border-bottom:1px solid var(--border);}
tbody tr:hover{background:#f0f9ff;}
tbody td{padding:10px 12px;vertical-align:top;}
.mono{font-family:'DM Mono',monospace;}
.text-muted{color:var(--muted);}

.score-chip{display:inline-block;padding:2px 8px;border-radius:5px;font-weight:700;font-size:11px;}
.score-chip.good{background:#dcfce7;color:#166534;}
.score-chip.warn{background:#fef3c7;color:#92400e;}
.score-chip.bad {background:#fee2e2;color:#991b1b;}

.tag-list{display:flex;flex-wrap:wrap;gap:3px;margin-top:3px;}
.tg{font-size:10px;padding:1px 6px;border-radius:10px;border:1px solid;}
.tg.s{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
.tg.a{background:#fffbeb;color:#92400e;border-color:#fde68a;}
.tg.empty{background:var(--surf2);color:var(--muted);border-color:var(--border);font-style:italic;}
.img-sm{width:48px;height:27px;object-fit:cover;border-radius:4px;
        border:1px solid var(--border);display:block;}

/* issue list */
.issue{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;
       border-radius:8px;margin-bottom:8px;font-size:13px;}
.issue.warn{background:#fef3c7;border:1px solid #fde68a;}
.issue.fail{background:#fee2e2;border:1px solid #fca5a5;}
.issue.ok  {background:#dcfce7;border:1px solid #86efac;}
.issue-icon{font-size:16px;flex-shrink:0;margin-top:1px;}
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-title">🔍 Matching <span>Diagnostics</span></div>
  <form method="GET" style="display:contents">
    <select class="pod-sel" name="podcast_id" onchange="this.form.submit()">
      <option value="">— Select podcast —</option>
      <?php foreach ($all_podcasts as $p): ?>
      <option value="<?= $p['id'] ?>" <?= $p['id']==$podcast_id?'selected':'' ?>>
        #<?= $p['id'] ?> — <?= htmlspecialchars(substr($p['title'],0,55)) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="main">

<!-- ── 1. Asset Library Health ── -->
<div class="section">
  <h2>📦 Asset Library Health</h2>
  <div class="stat-grid">
    <div class="stat-box">
      <div class="sv"><?= number_format($total_assets) ?></div>
      <div class="sl">Total assets</div>
    </div>
    <div class="stat-box">
      <div class="sv <?= $asset_images>0?'good':'bad' ?>"><?= number_format($asset_images) ?></div>
      <div class="sl">Images</div>
    </div>
    <div class="stat-box">
      <div class="sv <?= $asset_videos>0?'good':'warn' ?>"><?= number_format($asset_videos) ?></div>
      <div class="sl">Videos</div>
    </div>
    <div class="stat-box">
      <div class="sv <?= $has_nl_pct>=80?'good':($has_nl_pct>=40?'warn':'bad') ?>"><?= $has_nl_pct ?>%</div>
      <div class="sl">Have NL tags</div>
    </div>
    <div class="stat-box">
      <div class="sv <?= $has_embed_pct>=80?'good':($has_embed_pct>=40?'warn':'bad') ?>"><?= $has_embed_pct ?>%</div>
      <div class="sl">Have embeddings</div>
    </div>
    <div class="stat-box">
      <div class="sv <?= $embed_test_ok?'good':'bad' ?>"><?= $embed_test_ok?'✓':'✗' ?></div>
      <div class="sl">Embed API</div>
    </div>
  </div>

  <div style="margin-top:16px;">
    <div class="bar-wrap" style="margin-bottom:8px;">
      <span style="min-width:120px;font-size:12px;color:var(--muted);">NL tags coverage</span>
      <div class="bar"><div class="bar-fill" style="width:<?= $has_nl_pct ?>%;background:<?= $has_nl_pct>=80?'#16a34a':($has_nl_pct>=40?'#d97706':'#dc2626') ?>;"></div></div>
      <span class="bar-val" style="color:<?= $has_nl_pct>=80?'#16a34a':($has_nl_pct>=40?'#d97706':'#dc2626') ?>"><?= $has_nl_pct ?>%</span>
    </div>
    <div class="bar-wrap">
      <span style="min-width:120px;font-size:12px;color:var(--muted);">Embedding coverage</span>
      <div class="bar"><div class="bar-fill" style="width:<?= $has_embed_pct ?>%;background:<?= $has_embed_pct>=80?'#16a34a':($has_embed_pct>=40?'#d97706':'#dc2626') ?>;"></div></div>
      <span class="bar-val" style="color:<?= $has_embed_pct>=80?'#16a34a':($has_embed_pct>=40?'#d97706':'#dc2626') ?>"><?= $has_embed_pct ?>%</span>
    </div>
  </div>
</div>

<!-- ── 2. Issues / Recommendations ── -->
<div class="section">
  <h2>⚠️ Issues & Recommendations</h2>

  <?php if (!$embed_test_ok): ?>
  <div class="issue fail">
    <span class="issue-icon">❌</span>
    <div><strong>Embedding API not working</strong> — <?= htmlspecialchars($embed_test_msg) ?>. Semantic search will fall back to keyword LIKE matching which is very weak.</div>
  </div>
  <?php else: ?>
  <div class="issue ok">
    <span class="issue-icon">✅</span>
    <div><strong>Embedding API working</strong> — <?= htmlspecialchars($embed_test_msg) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($no_embed > 0): ?>
  <div class="issue <?= $no_embed > $total_assets * 0.5 ? 'fail' : 'warn' ?>">
    <span class="issue-icon"><?= $no_embed > $total_assets * 0.5 ? '❌' : '⚠️' ?></span>
    <div>
      <strong><?= number_format($no_embed) ?> assets have no embedding</strong>
      (<?= round($no_embed/$total_assets*100) ?>% of library).
      These assets cannot be semantically matched — only keyword search applies.
      <br><strong>Fix:</strong> Run your embedding generation script on all assets in <code>hdb_image_data</code>.
    </div>
  </div>
  <?php endif; ?>

  <?php if ($no_nl > 0): ?>
  <div class="issue <?= $no_nl > $total_assets * 0.5 ? 'fail' : 'warn' ?>">
    <span class="issue-icon"><?= $no_nl > $total_assets * 0.5 ? '❌' : '⚠️' ?></span>
    <div>
      <strong><?= number_format($no_nl) ?> assets have no NL tags</strong>
      (<?= round($no_nl/$total_assets*100) ?>% of library).
      Without NL tags, embeddings cannot be generated and keyword matching has nothing to compare.
      <br><strong>Fix:</strong> Generate NL tags for all assets using AI or manual tagging.
    </div>
  </div>
  <?php endif; ?>

  <?php if ($no_nl === 0 && $no_embed === 0 && $embed_test_ok): ?>
  <div class="issue ok">
    <span class="issue-icon">✅</span>
    <div><strong>Library is fully tagged and embedded</strong> — semantic matching should work well.</div>
  </div>
  <?php endif; ?>

  <?php
  // Check if scene NL tags are populated
  if (!empty($scene_diag)) {
    $no_scene_nl = count(array_filter($scene_diag, fn($s) => empty($s['scene_nl'])));
    if ($no_scene_nl > 0): ?>
  <div class="issue fail">
    <span class="issue-icon">❌</span>
    <div>
      <strong><?= $no_scene_nl ?> scenes have no NL tags</strong> in this podcast.
      These scenes cannot be matched at all.
      <br><strong>Fix:</strong> Re-run Build Video so the AI enhancement step generates and saves NL tags.
    </div>
  </div>
  <?php endif;

    $no_image = count(array_filter($scene_diag, fn($s) => empty($s['image_file'])));
    if ($no_image > 0): ?>
  <div class="issue warn">
    <span class="issue-icon">⚠️</span>
    <div><strong><?= $no_image ?> scenes have no image assigned</strong> — media search returned no results for these scenes.</div>
  </div>
  <?php endif; ?>

  <div class="issue warn">
    <span class="issue-icon">ℹ️</span>
    <div>
      <strong>Why Jaccard scores are low (11-13%)</strong><br>
      Jaccard measures exact word overlap. Scene tags say <em>"financial adviser reviewing documents"</em>
      — asset tags say <em>"businessman laptop office"</em>. They mean the same thing but share no words.
      The <strong>cosine similarity score</strong> (embedding-based) is what actually drives matching —
      it understands semantic meaning. Check the Cosine column below; scores above 0.55 are good matches.
    </div>
  </div>
  <?php } ?>
</div>

<!-- ── 3. Sample Asset NL Tags ── -->
<div class="section">
  <h2>🖼 Sample Asset NL Tags (random 5)</h2>
  <?php if (empty($sample_assets)): ?>
    <p style="color:var(--bad);font-size:13px;">⚠ No assets have NL tags — this is why matching fails.</p>
  <?php else: ?>
    <?php foreach ($sample_assets as $a): ?>
    <div class="asset-row">
      <img class="asset-thumb"
           src="podcast_images/<?= htmlspecialchars($a['image_name']) ?>"
           onerror="this.style.opacity='.2'">
      <div class="asset-info">
        <div class="asset-name"><?= htmlspecialchars($a['image_name']) ?>
          <span class="pill <?= $a['has_embed']?'ok':'fail' ?>" style="margin-left:6px;">
            <?= $a['has_embed']?'✓ embed':'✗ no embed' ?>
          </span>
        </div>
        <div class="nl-preview"><?= htmlspecialchars($a['natural_language_tags']) ?></div>
        <?php if ($a['image_hashtags']): ?>
        <div class="hash-preview"><?= htmlspecialchars($a['image_hashtags']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ── 4. Scene-by-Scene Breakdown ── -->
<?php if (!empty($scene_diag)): ?>
<div class="section">
  <h2>🎬 Scene Matching Breakdown — <?= htmlspecialchars($podcast['title'] ?? '') ?></h2>
  <div class="tbl-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Scene text</th>
        <th>Scene NL tags</th>
        <th>Assigned file</th>
        <th>Asset NL tags</th>
        <th>Jaccard</th>
        <th>Cosine</th>
        <th>Embed?</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($scene_diag as $s):
      $j_color = $s['jaccard'] >= 0.3 ? 'good' : ($s['jaccard'] >= 0.15 ? 'warn' : 'bad');
      $c_color = ($s['cosine'] !== null) ? ($s['cosine'] >= 0.55 ? 'good' : ($s['cosine'] >= 0.35 ? 'warn' : 'bad')) : 'bad';
    ?>
    <tr>
      <td class="mono" style="white-space:nowrap;color:var(--muted);">S<?= $s['seq'] ?></td>
      <td style="max-width:180px;font-size:11px;"><?= htmlspecialchars(substr($s['text'],0,80)) ?></td>
      <td style="max-width:160px;">
        <?php if ($s['scene_nl']): ?>
          <div class="tag-list">
            <?php foreach (array_slice(array_filter(array_map('trim', explode('|',$s['scene_nl']))),0,4) as $t): ?>
            <span class="tg s"><?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span class="tg empty">none</span>
        <?php endif; ?>
      </td>
      <td style="max-width:120px;">
        <?php if ($s['image_file']): ?>
          <?php if (preg_match('/\.(mp4|webm|mov)$/i', $s['image_file'])): ?>
            <div style="font-size:10px;background:#ede9fe;color:#6d28d9;padding:2px 6px;border-radius:3px;display:inline-block;">🎥 <?= htmlspecialchars(substr($s['image_file'],0,20)) ?></div>
          <?php else: ?>
            <img class="img-sm" src="podcast_images/<?= htmlspecialchars($s['image_file']) ?>"
                 onerror="this.style.opacity='.2'">
            <div style="font-size:9px;color:var(--muted);margin-top:2px;font-family:'DM Mono',monospace;word-break:break-all;"><?= htmlspecialchars(substr($s['image_file'],0,24)) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <span style="color:var(--bad);font-size:11px;">none</span>
        <?php endif; ?>
      </td>
      <td style="max-width:160px;">
        <?php if ($s['asset_nl']): ?>
          <div class="tag-list">
            <?php foreach (array_slice(array_filter(array_map('trim', explode('|',$s['asset_nl']))),0,4) as $t): ?>
            <span class="tg a"><?= htmlspecialchars($t) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span class="tg empty"><?= $s['image_file']?'no asset tags':'no asset' ?></span>
        <?php endif; ?>
      </td>
      <td>
        <span class="score-chip <?= $j_color ?>"><?= round($s['jaccard']*100) ?>%</span>
      </td>
      <td>
        <?php if ($s['cosine'] !== null): ?>
          <span class="score-chip <?= $c_color ?>"><?= round($s['cosine']*100) ?>%</span>
        <?php elseif (!$s['has_embed']): ?>
          <span class="score-chip bad">no embed</span>
        <?php else: ?>
          <span class="score-chip bad">API fail</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="pill <?= $s['has_embed']?'ok':'fail' ?>"><?= $s['has_embed']?'✓':'✗' ?></span>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Score summary -->
  <?php
  $cos_scores = array_filter(array_column($scene_diag, 'cosine'), fn($v) => $v !== null);
  $avg_cosine = count($cos_scores) > 0 ? round(array_sum($cos_scores) / count($cos_scores), 3) : null;
  $good_cos   = count(array_filter($cos_scores, fn($v) => $v >= 0.55));
  $warn_cos   = count(array_filter($cos_scores, fn($v) => $v >= 0.35 && $v < 0.55));
  $bad_cos    = count(array_filter($cos_scores, fn($v) => $v < 0.35));
  ?>
  <?php if ($avg_cosine !== null): ?>
  <div style="margin-top:16px;padding:14px 16px;background:var(--surf2);border-radius:8px;
              border:1px solid var(--border);font-size:13px;">
    <strong>Cosine score summary:</strong>
    Avg <strong style="color:<?= $avg_cosine>=0.55?'#16a34a':($avg_cosine>=0.35?'#d97706':'#dc2626') ?>">
      <?= round($avg_cosine*100) ?>%</strong> &nbsp;|&nbsp;
    <span style="color:#16a34a;">✓ Good (≥55%): <?= $good_cos ?></span> &nbsp;|&nbsp;
    <span style="color:#d97706;">~ Okay (35-55%): <?= $warn_cos ?></span> &nbsp;|&nbsp;
    <span style="color:#dc2626;">✗ Poor (&lt;35%): <?= $bad_cos ?></span>
    <?php if ($avg_cosine >= 0.55): ?>
      <div style="margin-top:8px;color:#16a34a;font-weight:600;">✅ Matching quality is good!</div>
    <?php elseif ($avg_cosine >= 0.4): ?>
      <div style="margin-top:8px;color:#d97706;font-weight:600;">⚠ Matching is acceptable but could be improved — add more diverse assets to the library.</div>
    <?php else: ?>
      <div style="margin-top:8px;color:#dc2626;font-weight:600;">❌ Matching quality is poor — most assets are not semantically relevant to your video content. Add more relevant assets or improve NL tag generation.</div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div style="margin-top:12px;padding:12px;background:#fee2e2;border-radius:8px;font-size:13px;color:#991b1b;">
    ❌ Could not compute cosine scores — either embeddings are missing from assets or the OpenAI API is failing.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- /main -->
</body>
</html>
