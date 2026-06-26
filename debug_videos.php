<?php
session_start();
include 'dbconnect_hdb.php';

$admin_id   = (int)($_SESSION['admin_id'] ?? 0);
$company_id = (int)($_SESSION['company_id'] ?? 0);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<title>Video Debug</title>
<style>
body{font-family:monospace;padding:20px;background:#0f172a;color:#e2e8f0;}
.box{background:#1e293b;border-radius:8px;padding:16px;margin:16px 0;border:1px solid #334155;}
.ok{color:#4ade80;} .err{color:#f87171;} .warn{color:#fbbf24;}
h2{color:#5fc3ff;margin-bottom:8px;}
pre{white-space:pre-wrap;word-break:break-all;font-size:12px;margin-top:8px;}
a{color:#5fc3ff;} button{padding:8px 16px;border-radius:6px;border:none;background:#3b82f6;color:#fff;cursor:pointer;margin:4px;}
</style></head><body>";

echo "<h1 style='color:#fff;'>🔍 Video Debug Panel</h1>";
echo "<div class='box'><b>Session:</b> admin_id=$admin_id | company_id=$company_id</div>";

// ── Test 1: Direct DB query for My Videos ─────────────────────
echo "<div class='box'><h2>Test 1: Direct DB — My Videos (admin_id=$admin_id, company_id=$company_id)</h2>";
$q = mysqli_query($conn,
    "SELECT id, title, video_status, campaign_id, company_id, niche
     FROM hdb_podcasts
     WHERE admin_id=$admin_id
       AND (campaign_id IS NULL OR campaign_id=0)
     ORDER BY id DESC LIMIT 10");
if ($q && mysqli_num_rows($q) > 0) {
    echo "<span class='ok'>✓ Found " . mysqli_num_rows($q) . " rows (showing up to 10)</span><pre>";
    while ($r = mysqli_fetch_assoc($q)) {
        echo "id={$r['id']} | company_id={$r['company_id']} | status=" . ($r['video_status']?:'NULL') . " | campaign_id=" . ($r['campaign_id']?:'0') . " | title=" . substr($r['title'],0,40) . "\n";
    }
    echo "</pre>";
} else {
    echo "<span class='err'>✗ No rows found! Query: SELECT id FROM hdb_podcasts WHERE admin_id=$admin_id AND (campaign_id IS NULL OR campaign_id=0)</span>";
    echo "<br>MySQL error: " . mysqli_error($conn);
}
echo "</div>";

// ── Test 2: With company_id filter ───────────────────────────
echo "<div class='box'><h2>Test 2: With company_id=$company_id filter</h2>";
$q2 = mysqli_query($conn,
    "SELECT COUNT(*) c FROM hdb_podcasts
     WHERE admin_id=$admin_id AND company_id=$company_id
       AND (campaign_id IS NULL OR campaign_id=0)");
$cnt = $q2 ? (int)mysqli_fetch_assoc($q2)['c'] : 0;
echo $cnt > 0
    ? "<span class='ok'>✓ $cnt videos found with company_id=$company_id</span>"
    : "<span class='err'>✗ 0 videos with company_id=$company_id — this is why My Videos is empty!</span>";
echo "</div>";

// ── Test 3: All companies ─────────────────────────────────────
echo "<div class='box'><h2>Test 3: Count by company_id</h2><pre>";
$q3 = mysqli_query($conn,
    "SELECT company_id, COUNT(*) c FROM hdb_podcasts
     WHERE admin_id=$admin_id AND (campaign_id IS NULL OR campaign_id=0)
     GROUP BY company_id ORDER BY c DESC");
if ($q3) {
    while ($r = mysqli_fetch_assoc($q3)) {
        echo "company_id=" . ($r['company_id']?:'NULL') . " → {$r['c']} videos\n";
    }
}
echo "</pre></div>";

// ── Test 4: Template admin_id=32 ─────────────────────────────
echo "<div class='box'><h2>Test 4: Templates (admin_id=32, campaign_id=0)</h2>";
$q4 = mysqli_query($conn,
    "SELECT COUNT(*) c FROM hdb_podcasts WHERE admin_id=32 AND (campaign_id IS NULL OR campaign_id=0)");
$cnt4 = $q4 ? (int)mysqli_fetch_assoc($q4)['c'] : 0;
echo $cnt4 > 0
    ? "<span class='ok'>✓ $cnt4 template videos found for admin_id=32</span>"
    : "<span class='err'>✗ 0 templates for admin_id=32</span>";

// Show niches
$q4b = mysqli_query($conn,
    "SELECT niche, COUNT(*) c FROM hdb_podcasts WHERE admin_id=32 AND (campaign_id IS NULL OR campaign_id=0) GROUP BY niche");
if ($q4b && mysqli_num_rows($q4b) > 0) {
    echo "<pre>";
    while ($r = mysqli_fetch_assoc($q4b)) echo "niche=" . ($r['niche']?:'NULL') . " → {$r['c']} videos\n";
    echo "</pre>";
}
echo "</div>";

// ── Test 5: Fetch ajax_load_videos directly ──────────────────
echo "<div class='box'><h2>Test 5: Live fetch of ajax_load_videos.php</h2>";
echo "<button onclick=\"testFetch('ajax_load_videos.php?status=active&page=1&admin_id=$admin_id&company_id=$company_id')\">Test My Videos AJAX</button>";
echo "<button onclick=\"testFetch('vizard_browser.php?action=load_templates')\">Test Templates AJAX</button>";
echo "<div id='fetchResult' style='margin-top:12px;'></div>";
echo "</div>";

// ── Test 6: Check column names ────────────────────────────────
echo "<div class='box'><h2>Test 6: hdb_podcasts columns</h2><pre>";
$q6 = mysqli_query($conn, "SHOW COLUMNS FROM hdb_podcasts");
$cols = [];
if ($q6) while ($r = mysqli_fetch_assoc($q6)) $cols[] = $r['Field'];
echo implode(', ', $cols);
echo "</pre></div>";

echo "<script>
async function testFetch(url) {
    const el = document.getElementById('fetchResult');
    el.innerHTML = '⏳ Fetching: ' + url + '...';
    try {
        const r = await fetch(url);
        const text = await r.text();
        let display = text.substring(0, 2000);
        try {
            const json = JSON.parse(text);
            display = JSON.stringify(json, null, 2).substring(0, 3000);
            el.innerHTML = '<span class=\"ok\">✓ Valid JSON</span><pre>' + display + '</pre>';
        } catch(e) {
            el.innerHTML = '<span class=\"err\">✗ NOT valid JSON — raw response:</span><pre>' + text.substring(0,1000) + '</pre>';
        }
    } catch(e) {
        el.innerHTML = '<span class=\"err\">✗ Fetch failed: ' + e.message + '</span>';
    }
}
</script>";

echo "</body></html>";
?>
