<?php
session_start();
include 'dbconnect_hdb.php';

// Get podcast_id from URL or show list
$podcast_id = (int)($_GET['podcast_id'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Caption Diagnostic</title>
<style>
body { font-family: monospace; padding: 20px; background: #0f172a; color: #e2e8f0; }
h2 { color: #5fd1ff; }
h3 { color: #fbbf24; margin-top: 30px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 30px; font-size: 13px; }
th { background: #1e3a5f; color: #5fd1ff; padding: 8px 12px; text-align: left; }
td { padding: 7px 12px; border-bottom: 1px solid #1e3a5f; }
tr:hover td { background: #1e293b; }
.ok   { color: #10b981; font-weight: bold; }
.fail { color: #ef4444; font-weight: bold; }
.warn { color: #f59e0b; font-weight: bold; }
a { color: #5fd1ff; }
input { background: #1e293b; border: 1px solid #334155; color: #e2e8f0; padding: 6px 12px; border-radius: 6px; }
button { background: #2563eb; color: white; border: none; padding: 8px 18px; border-radius: 6px; cursor: pointer; margin-left: 8px; }
.box { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
</style>
</head>
<body>

<h2>🔍 Caption Diagnostic Tool</h2>

<!-- Podcast selector -->
<div class="box">
<form method="get">
    <label>Podcast ID: </label>
    <input type="number" name="podcast_id" value="<?= $podcast_id ?>" placeholder="Enter podcast_id">
    <button type="submit">Check</button>
</form>

<?php if (!$podcast_id): ?>
<!-- Show recent podcasts to pick from -->
<h3>Recent Podcasts</h3>
<table>
<tr><th>ID</th><th>Title</th><th>Created</th><th>Status</th><th>Action</th></tr>
<?php
$q = mysqli_query($conn, "SELECT id, title, created_date, internal_status FROM hdb_podcasts ORDER BY id DESC LIMIT 20");
while ($r = mysqli_fetch_assoc($q)):
?>
<tr>
    <td><?= $r['id'] ?></td>
    <td><?= htmlspecialchars($r['title'] ?: '(no title)') ?></td>
    <td><?= $r['created_date'] ?></td>
    <td><?= $r['internal_status'] ?></td>
    <td><a href="?podcast_id=<?= $r['id'] ?>">Check this →</a></td>
</tr>
<?php endwhile; ?>
</table>
<?php endif; ?>
</div>

<?php if ($podcast_id): ?>

<!-- Podcast info -->
<?php
$pod = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id=$podcast_id LIMIT 1"));
if (!$pod) { echo "<p class='fail'>Podcast $podcast_id not found.</p>"; exit; }
?>
<div class="box">
<h3>📋 Podcast #<?= $podcast_id ?></h3>
<table>
<tr><th>Field</th><th>Value</th></tr>
<tr><td>Title</td><td><?= htmlspecialchars($pod['title']) ?></td></tr>
<tr><td>Lang</td><td><?= $pod['lang_code'] ?></td></tr>
<tr><td>Status</td><td><?= $pod['internal_status'] ?></td></tr>
<tr><td>Host voice</td><td><?= $pod['host_voice'] ?></td></tr>
<tr><td>script_text exists</td><td><?= !empty($pod['script_text']) ? '<span class="ok">YES ('.strlen($pod['script_text']).' chars)</span>' : '<span class="warn">NO</span>' ?></td></tr>
</table>
</div>

<!-- Scenes + captions -->
<?php
$scenes_q = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$podcast_id ORDER BY seq_no");
$scenes = [];
while ($r = mysqli_fetch_assoc($scenes_q)) $scenes[] = $r;
$total_scenes = count($scenes);

// Count total captions
$cap_count_q = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as n FROM hdb_captions WHERE podcast_id=$podcast_id"));
$total_captions = $cap_count_q['n'];
?>

<div class="box">
<h3>📊 Summary</h3>
<table>
<tr><th>Metric</th><th>Value</th></tr>
<tr><td>Total scenes</td><td class="<?= $total_scenes > 0 ? 'ok' : 'fail' ?>"><?= $total_scenes ?></td></tr>
<tr><td>Total caption rows</td><td class="<?= $total_captions > 0 ? 'ok' : 'fail' ?>"><?= $total_captions ?> <?= $total_captions == 0 ? '← PROBLEM: captions table is empty!' : '' ?></td></tr>
</table>

<?php if ($total_captions == 0): ?>
<p class="fail">⚠️ No caption rows exist for this podcast. The script that creates scenes is not inserting into hdb_captions.</p>
<?php endif; ?>
</div>

<!-- Scene by scene breakdown -->
<h3>🎬 Scene Detail</h3>
<table>
<tr>
    <th>#</th>
    <th>Story ID</th>
    <th>Text (first 50 chars)</th>
    <th>Audio</th>
    <th>Image</th>
    <th>Caption rows</th>
    <th>Caption text</th>
    <th>Font / Size / Color</th>
</tr>
<?php foreach ($scenes as $s):
    $caps_q = mysqli_query($conn, "SELECT * FROM hdb_captions WHERE story_id = {$s['id']}");
    $caps = [];
    while ($c = mysqli_fetch_assoc($caps_q)) $caps[] = $c;
    $cap_n = count($caps);
    $cap_text = $cap_n > 0 ? htmlspecialchars(substr($caps[0]['text_content'] ?? '', 0, 40)) : '—';
    $cap_style = $cap_n > 0 ? ($caps[0]['fontfamily']??'?').' / '.$caps[0]['fontsize'].' / '.$caps[0]['fontcolor'] : '—';
?>
<tr>
    <td><?= $s['seq_no'] ?></td>
    <td><?= $s['id'] ?></td>
    <td><?= htmlspecialchars(substr($s['text_contents'] ?? '', 0, 50)) ?></td>
    <td class="<?= $s['audio_file'] ? 'ok' : 'warn' ?>"><?= $s['audio_file'] ? '✅' : '❌' ?></td>
    <td class="<?= $s['image_file'] ? 'ok' : 'warn' ?>"><?= $s['image_file'] ? '✅' : '❌' ?></td>
    <td class="<?= $cap_n > 0 ? 'ok' : 'fail' ?>"><?= $cap_n ?></td>
    <td><?= $cap_text ?></td>
    <td><?= $cap_style ?></td>
</tr>
<?php endforeach; ?>
</table>

<!-- Fix button - auto-insert missing captions -->
<?php if ($total_captions == 0 && $total_scenes > 0): ?>
<div class="box">
<h3>🔧 Auto-Fix: Insert Missing Captions</h3>
<p>Click below to insert caption rows for all <?= $total_scenes ?> scenes using default styling.</p>
<form method="post">
    <input type="hidden" name="fix_podcast_id" value="<?= $podcast_id ?>">
    <button type="submit" name="do_fix" value="1" style="background:#10b981;">✅ Insert Caption Rows Now</button>
</form>
</div>
<?php endif; ?>

<?php endif; // end if podcast_id ?>

<?php
// ── AUTO-FIX ──────────────────────────────────────────────────
if (isset($_POST['do_fix']) && $_POST['do_fix'] == 1) {
    $fix_pid = (int)$_POST['fix_podcast_id'];
    $fixed = 0;

    // Get user settings for this podcast's admin
    $pod2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hdb_podcasts WHERE id=$fix_pid LIMIT 1"));
    $admin_id = $pod2['admin_id'] ?? 0;
    $us = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hdb_user_settings WHERE admin_id='$admin_id' LIMIT 1"));
    $ff   = mysqli_real_escape_string($conn, $us['fontfamily']   ?? 'Arial');
    $fs   = intval($us['fontsize']    ?? 28);
    $fc   = mysqli_real_escape_string($conn, $us['fontcolor']    ?? '#ffffff');
    $fw   = mysqli_real_escape_string($conn, $us['fontweight']   ?? 'bold');
    $bgc  = mysqli_real_escape_string($conn, $us['fontcolor_bg'] ?? '#000000');
    $bge  = intval($us['fontbg_enable'] ?? 0);
    $cs   = mysqli_real_escape_string($conn, $us['caption_style'] ?? 'none');
    $cspd = floatval($us['caption_speed'] ?? 1.0);
    $px   = intval($us['position_x'] ?? 20);
    $py   = intval($us['position_y'] ?? 300);
    $pw   = intval($us['width']      ?? 380);

    $sc_q = mysqli_query($conn, "SELECT * FROM hdb_podcast_stories WHERE podcast_id=$fix_pid ORDER BY seq_no");
    while ($sc = mysqli_fetch_assoc($sc_q)) {
        // Skip if caption already exists
        $exists = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM hdb_captions WHERE story_id={$sc['id']} LIMIT 1"));
        if ($exists) continue;

        $te = mysqli_real_escape_string($conn, $sc['text_contents'] ?? '');
        mysqli_query($conn, "INSERT INTO hdb_captions
            (podcast_id, story_id, caption_type, caption_name, text_content,
             fontfamily, fontsize, fontcolor, fontweight, fontstyle, text_align,
             bg_color, bg_enabled, position_x, position_y, width, rotation,
             animation_style, animation_speed, is_visible, z_index)
            VALUES
            ($fix_pid, {$sc['id']}, 'text', 'main', '$te',
             '$ff', $fs, '$fc', '$fw', 'normal', 'center',
             '$bgc', $bge, $px, $py, $pw, 0,
             '$cs', $cspd, 1, 1)");
        $fixed++;
    }
    echo "<div class='box'><p class='ok'>✅ Inserted $fixed caption rows. <a href='?podcast_id=$fix_pid'>Reload to verify →</a></p></div>";
}
?>

</body>
</html>
