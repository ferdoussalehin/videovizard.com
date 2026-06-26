<?php
$folder    = __DIR__ . '/podcast_videos';
$min_bytes = 3 * 1024 * 1024; // 3 MB

include __DIR__ . '/dbconnect_hdb.php';

function human_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2)    . ' MB';
    return round($bytes / 1024, 2) . ' KB';
}

// ── AJAX: Delete selected files ────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_selected') {
    header('Content-Type: application/json');

    $filenames = $_POST['filenames'] ?? [];
    if (empty($filenames)) {
        echo json_encode(['success' => false, 'message' => 'No files selected']);
        exit;
    }

    $deleted_files = 0;
    $deleted_rows  = 0;
    $errors        = [];

    foreach ($filenames as $file) {
        $base = basename($file);
        $path = $folder . '/' . $base;

        // Delete file from disk
        if (file_exists($path)) {
            if (unlink($path)) {
                $deleted_files++;
            } else {
                $errors[] = "Could not delete: $base";
                continue;
            }
        }

        // Delete row from hdb_image_data
        $safe1 = mysqli_real_escape_string($conn, $base);
        $safe2 = mysqli_real_escape_string($conn, 'podcast_videos/' . $base);

        $sql = "DELETE FROM hdb_image_data
                WHERE media_type = 'video'
                AND (image_name = '$safe1' OR image_name = '$safe2')";

        mysqli_query($conn, $sql);
        $deleted_rows += mysqli_affected_rows($conn);
    }

    echo json_encode([
        'success'       => true,
        'deleted_files' => $deleted_files,
        'deleted_rows'  => $deleted_rows,
        'errors'        => $errors,
        'message'       => "$deleted_files file(s) deleted, $deleted_rows DB row(s) removed",
    ]);
    exit;
}

// ── AJAX: Delete ALL large files ───────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete_all') {
    header('Content-Type: application/json');

    $filenames = $_POST['filenames'] ?? [];
    if (empty($filenames)) {
        echo json_encode(['success' => false, 'message' => 'No files']);
        exit;
    }

    $deleted_files = 0;
    $deleted_rows  = 0;
    $errors        = [];

    foreach ($filenames as $file) {
        $base = basename($file);
        $path = $folder . '/' . $base;

        if (file_exists($path)) {
            if (unlink($path)) {
                $deleted_files++;
            } else {
                $errors[] = "Could not delete: $base";
                continue;
            }
        }

        $safe1 = mysqli_real_escape_string($conn, $base);
        $safe2 = mysqli_real_escape_string($conn, 'podcast_videos/' . $base);

        mysqli_query($conn, "DELETE FROM hdb_image_data
            WHERE media_type = 'video'
            AND (image_name = '$safe1' OR image_name = '$safe2')");
        $deleted_rows += mysqli_affected_rows($conn);
    }

    echo json_encode([
        'success'       => true,
        'deleted_files' => $deleted_files,
        'deleted_rows'  => $deleted_rows,
        'errors'        => $errors,
        'message'       => "$deleted_files file(s) deleted, $deleted_rows DB row(s) removed",
    ]);
    exit;
}

// ── Scan folder ────────────────────────────────────────────────
$videos = [];
if (is_dir($folder)) {
    foreach (new DirectoryIterator($folder) as $file) {
        if ($file->isDot() || $file->isDir()) continue;
        $ext = strtolower($file->getExtension());
        if ($ext !== 'mp4') continue;
        $size = $file->getSize();
        if ($size > $min_bytes) {
            $videos[] = [
                'name'  => $file->getFilename(),
                'size'  => $size,
                'mtime' => $file->getMTime(),
            ];
        }
    }
}
usort($videos, fn($a, $b) => $b['size'] - $a['size']);

// ── Get DB info for each file ──────────────────────────────────
$db_info = [];
if (!empty($videos)) {
    $in1 = implode(',', array_map(fn($v) => "'" . mysqli_real_escape_string($conn, $v['name']) . "'", $videos));
    $in2 = implode(',', array_map(fn($v) => "'" . mysqli_real_escape_string($conn, 'podcast_videos/' . $v['name']) . "'", $videos));
    $r   = mysqli_query($conn, "SELECT image_name, id FROM hdb_image_data WHERE media_type='video' AND (image_name IN ($in1) OR image_name IN ($in2))");
    while ($row = mysqli_fetch_assoc($r)) {
        $db_info[basename($row['image_name'])] = (int)$row['id'];
    }
}

$total_size  = array_sum(array_column($videos, 'size'));
$max_size    = $videos[0]['size'] ?? 1;
$total_count = count($videos);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delete Large Videos</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4ff;color:#1e293b;min-height:100vh;padding:24px 16px}
.container{max-width:1000px;margin:0 auto}

.hero{background:linear-gradient(135deg,#dc2626,#f97316);border-radius:16px;padding:28px 24px;margin-bottom:22px;color:#fff}
.hero h1{font-size:22px;font-weight:800}
.hero p{font-size:13px;opacity:.82;margin-top:6px}

.warning-box{background:#fff5f5;border:2px solid #fecaca;border-radius:12px;padding:16px 20px;margin-bottom:18px;color:#dc2626;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px}

.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1.5px solid #dde4f5;border-radius:12px;padding:14px 16px;box-shadow:0 2px 8px rgba(29,78,216,.06)}
.stat-label{font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.stat-value{font-size:24px;font-weight:800;line-height:1}
.stat-sub{font-size:10px;color:#94a3b8;margin-top:3px}
.stat.red .stat-value{color:#dc2626}
.stat.orange .stat-value{color:#f97316}
.stat.blue .stat-value{color:#1d4ed8}

.toolbar{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;align-items:center}
.search-input{flex:1;min-width:160px;background:#fff;border:1.5px solid #dde4f5;border-radius:10px;color:#1e293b;font-size:13px;padding:9px 14px;outline:none;transition:border-color .2s}
.search-input:focus{border-color:#dc2626}
::placeholder{color:#c4cfe8}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .15s;white-space:nowrap;font-family:inherit}
.btn:hover:not(:disabled){opacity:.88;transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.14)}
.btn:active{transform:scale(.97)}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none!important}
.btn-red{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff}
.btn-orange{background:linear-gradient(135deg,#ea580c,#f97316);color:#fff}
.btn-gray{background:#f0f4ff;color:#64748b;border:1.5px solid #dde4f5}

.panel{background:#fff;border:1.5px solid #dde4f5;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(29,78,216,.06)}
.panel-head{background:#f8faff;border-bottom:1.5px solid #dde4f5;padding:11px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.panel-title{font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.6px}

.tbl-wrap{max-height:560px;overflow-y:auto}
table{width:100%;border-collapse:collapse;font-size:12px}
thead th{background:#f8faff;padding:9px 12px;text-align:left;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1.5px solid #dde4f5;position:sticky;top:0;z-index:1;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4ff;transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#fff5f5}
tbody tr.sel-row{background:#fee2e2}
tbody tr.deleted-row{opacity:.35;text-decoration:line-through}
td{padding:9px 12px;vertical-align:middle}
.fn{font-family:'Consolas',monospace;font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rnum{font-size:11px;color:#cbd5e1;font-weight:700;text-align:center;width:32px}

.size-bar-wrap{display:flex;align-items:center;gap:7px}
.size-bar{flex:1;height:5px;background:#fee2e2;border-radius:99px;overflow:hidden;min-width:40px}
.size-bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#dc2626,#f97316)}

.badge{display:inline-block;padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700}
.b-huge{background:#fee2e2;color:#dc2626}
.b-large{background:#fff7ed;color:#ea580c}
.b-medium{background:#eff6ff;color:#1d4ed8}
.b-indb{background:#dcfce7;color:#16a34a}
.b-notdb{background:#f1f5f9;color:#64748b}

.cb{width:15px;height:15px;cursor:pointer;accent-color:#dc2626}

.toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateY(80px);opacity:0;transition:all .3s;color:#fff}
.toast.show{transform:translateY(0);opacity:1}
.toast.ok{background:linear-gradient(135deg,#16a34a,#22c55e)}
.toast.err{background:linear-gradient(135deg,#dc2626,#ef4444)}

.empty{text-align:center;padding:50px 20px;color:#94a3b8}
.empty .ei{font-size:40px;margin-bottom:10px}

.result-box{background:#fff;border:1.5px solid #dde4f5;border-radius:12px;padding:18px;margin-top:16px;display:none}
.result-box h3{font-size:12px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
.result-stat{display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:6px 14px;margin:4px;font-size:13px;font-weight:700;color:#15803d}
</style>
</head>
<body>
<div class="container">

<div class="hero">
    <h1>🗑️ Delete Large Videos — podcast_videos/</h1>
    <p>MP4 files above 3 MB — deletes from disk AND removes row from hdb_image_data</p>
</div>

<div class="warning-box">
    ⚠️ WARNING: This permanently deletes files from disk and database rows. This action cannot be undone.
</div>

<?php if (empty($videos)): ?>
<div class="panel"><div class="empty"><div class="ei">✅</div><p>No MP4 files above 3 MB found in <code>podcast_videos/</code></p></div></div>
<?php else: ?>

<div class="stats">
    <div class="stat red">
        <div class="stat-label">Files to Delete</div>
        <div class="stat-value"><?= $total_count ?></div>
        <div class="stat-sub">MP4s above 3 MB</div>
    </div>
    <div class="stat orange">
        <div class="stat-label">Total Size</div>
        <div class="stat-value" style="font-size:18px;padding-top:4px"><?= human_size($total_size) ?></div>
        <div class="stat-sub">will be freed</div>
    </div>
    <div class="stat blue">
        <div class="stat-label">Largest File</div>
        <div class="stat-value" style="font-size:18px;padding-top:4px"><?= human_size($max_size) ?></div>
        <div class="stat-sub"><?= htmlspecialchars(substr($videos[0]['name'],0,16)) ?>…</div>
    </div>
    <div class="stat blue">
        <div class="stat-label">In DB</div>
        <div class="stat-value"><?= count($db_info) ?></div>
        <div class="stat-sub">rows will be removed</div>
    </div>
</div>

<div class="toolbar">
    <input class="search-input" type="text" id="searchInput" placeholder="🔍 Search filename…" oninput="filterTable()">
    <button class="btn btn-orange" onclick="deleteSelected()" id="btnSel" disabled>🗑 Delete Selected (0)</button>
    <button class="btn btn-red"    onclick="deleteAll()"      id="btnAll">⚡ Delete ALL <?= $total_count ?> Files</button>
</div>

<div class="panel">
    <div class="panel-head">
        <span class="panel-title">🎬 Large MP4 Files</span>
        <div style="display:flex;gap:14px;align-items:center">
            <span style="font-size:12px;color:#64748b" id="selInfo">0 selected</span>
            <span style="font-size:11px;color:#94a3b8" id="rowCount"><?= $total_count ?> files</span>
        </div>
    </div>
    <div class="tbl-wrap">
        <table id="vTable">
            <thead>
                <tr>
                    <th><input type="checkbox" class="cb" id="cbAll" onchange="toggleAll(this)"></th>
                    <th>#</th>
                    <th>Filename</th>
                    <th>Size</th>
                    <th>Bar</th>
                    <th>In DB</th>
                    <th>DB ID</th>
                    <th>Modified</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($videos as $i => $v):
                $pct  = round(($v['size'] / $max_size) * 100);
                $mb   = $v['size'] / 1048576;
                $bc   = $mb >= 50 ? 'b-huge' : ($mb >= 10 ? 'b-large' : 'b-medium');
                $bl   = $mb >= 50 ? 'Huge'   : ($mb >= 10 ? 'Large'   : 'Medium');
                $dbid = $db_info[$v['name']] ?? null;
            ?>
                <tr data-size="<?= $v['size'] ?>"
                    data-name="<?= htmlspecialchars(strtolower($v['name'])) ?>"
                    data-mtime="<?= $v['mtime'] ?>"
                    id="row-<?= htmlspecialchars($v['name']) ?>">
                    <td><input type="checkbox" class="cb rcb" value="<?= htmlspecialchars($v['name']) ?>" onchange="onCheck()"></td>
                    <td class="rnum"><?= $i+1 ?></td>
                    <td class="fn" title="<?= htmlspecialchars($v['name']) ?>"><?= htmlspecialchars($v['name']) ?></td>
                    <td style="white-space:nowrap;font-weight:700">
                        <?= human_size($v['size']) ?>
                        <span class="badge <?= $bc ?>" style="margin-left:5px"><?= $bl ?></span>
                    </td>
                    <td>
                        <div class="size-bar-wrap">
                            <div class="size-bar"><div class="size-bar-fill" style="width:<?= $pct ?>%"></div></div>
                            <span style="font-size:10px;color:#94a3b8;width:28px;text-align:right"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td>
                        <?php if ($dbid): ?>
                            <span class="badge b-indb">✓ In DB</span>
                        <?php else: ?>
                            <span class="badge b-notdb">Not in DB</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:#94a3b8"><?= $dbid ? '#'.$dbid : '—' ?></td>
                    <td style="font-size:11px;color:#94a3b8;white-space:nowrap"><?= date('Y-m-d H:i',$v['mtime']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="result-box" id="resultBox">
    <h3>✅ Deletion Complete</h3>
    <div id="resultContent"></div>
</div>

<?php endif; ?>
</div>

<div class="toast" id="toast"></div>

<script>
const ALL_FILES = <?= json_encode(array_column($videos, 'name')) ?>;

function getChecked() {
    return Array.from(document.querySelectorAll('.rcb:checked')).map(c => c.value);
}
function onCheck() {
    const n = getChecked().length;
    document.getElementById('selInfo').textContent = n + ' selected';
    const btn = document.getElementById('btnSel');
    btn.disabled = n === 0;
    btn.textContent = '🗑 Delete Selected (' + n + ')';
    document.querySelectorAll('#vTable tbody tr').forEach(r => {
        r.classList.toggle('sel-row', r.querySelector('.rcb')?.checked ?? false);
    });
}
function toggleAll(cb) {
    document.querySelectorAll('.rcb').forEach(c => {
        if (c.closest('tr').style.display !== 'none') c.checked = cb.checked;
    });
    onCheck();
}

function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 4000);
}

function markDeleted(files) {
    files.forEach(f => {
        const row = document.getElementById('row-' + f);
        if (row) {
            row.classList.add('deleted-row');
            const cb = row.querySelector('.rcb');
            if (cb) cb.disabled = true;
        }
    });
}

function showResult(data) {
    const box = document.getElementById('resultBox');
    const rc  = document.getElementById('resultContent');
    if (!box || !rc) return;
    rc.innerHTML =
        '<span class="result-stat">🗑 ' + data.deleted_files + ' file(s) deleted from disk</span>' +
        '<span class="result-stat">🗄 ' + data.deleted_rows  + ' DB row(s) removed</span>' +
        (data.errors && data.errors.length ? '<div style="color:#dc2626;font-size:12px;margin-top:8px">Errors: ' + data.errors.join(', ') + '</div>' : '');
    box.style.display = 'block';
    box.scrollIntoView({ behavior:'smooth', block:'nearest' });
}

async function doDelete(action, files) {
    const fd = new FormData();
    fd.append('ajax_action', action);
    files.forEach(f => fd.append('filenames[]', f));
    try {
        const data = await fetch('', { method:'POST', body:fd }).then(r => r.json());
        if (data.success) {
            showToast('✅ ' + data.message, 'ok');
            markDeleted(files);
            showResult(data);
            // Update counts
            const remaining = ALL_FILES.length - files.length;
            document.getElementById('rowCount').textContent = remaining + ' files';
        } else {
            showToast('❌ ' + (data.message || 'Error'), 'err');
        }
    } catch(e) {
        showToast('❌ ' + e.message, 'err');
    }
}

function deleteSelected() {
    const files = getChecked();
    if (!files.length) return;
    if (confirm('⚠️ Permanently delete ' + files.length + ' file(s) from disk and remove DB rows?\n\nThis CANNOT be undone!')) {
        doDelete('delete_selected', files);
    }
}

function deleteAll() {
    if (confirm('⚠️ Permanently delete ALL ' + ALL_FILES.length + ' large MP4 file(s) from disk and remove their DB rows?\n\nThis CANNOT be undone!')) {
        doDelete('delete_all', ALL_FILES);
    }
}

function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#vTable tbody tr');
    let n = 0;
    rows.forEach(r => {
        const show = r.dataset.name.includes(q);
        r.style.display = show ? '' : 'none';
        if (show) r.querySelector('.rnum').textContent = ++n;
    });
    document.getElementById('rowCount').textContent = n + ' files';
}
</script>
</body>
</html>
