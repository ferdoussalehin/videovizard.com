<?php
include 'config.php';
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$pexel_key = isset($pexel_key) ? trim($pexel_key) : '';

define('TARGET_VIDEOS', 150); // target per promo_subgroup
define('IMAGE_FOLDER',  '/var/www/html/videovizard.com/podcast_images/');
define('VIDEO_FOLDER',  '/var/www/html/videovizard.com/podcast_videos/');

// ── Helper: count DB rows whose file actually exists on disk ──────────────────
function countExistingFiles(array $rows, string $folder): int {
    $count = 0;
    foreach ($rows as $r) {
        if (!empty($r['image_name']) && file_exists($folder . $r['image_name'])) {
            $count++;
        }
    }
    return $count;
}

// ── AJAX: row_counts — called after download to refresh a single row ──────────
if (isset($_POST['action']) && $_POST['action'] === 'row_counts') {
    header('Content-Type: application/json');
    $g_e  = mysqli_real_escape_string($conn, trim($_POST['group']   ?? ''));
    $sg_e = mysqli_real_escape_string($conn, trim($_POST['subgroup']?? ''));
    if (!$g_e || !$sg_e) {
        echo json_encode(['success' => false, 'message' => 'Missing group or subgroup']);
        exit;
    }

    // Fetch all rows for this group/subgroup (admin_id=0, company_id=0)
    $res = mysqli_query($conn, "
        SELECT media_type, image_name
        FROM hdb_image_data
        WHERE promo_group='$g_e' AND promo_subgroup='$sg_e'
          AND admin_id=0 AND company_id=0");
    $allRows    = [];
    $imageRows  = [];
    $videoRows  = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $allRows[] = $r;
        if (strtolower($r['media_type']) === 'image') $imageRows[] = $r;
        else                                           $videoRows[] = $r;
    }

    $image_exist = countExistingFiles($imageRows, IMAGE_FOLDER);
    $video_exist = countExistingFiles($videoRows, VIDEO_FOLDER);

    echo json_encode([
        'success'        => true,
        'total_media'    => count($allRows),
        'image_db'       => count($imageRows),
        'video_db'       => count($videoRows),
        'image_exist'    => $image_exist,
        'video_exist'    => $video_exist,
        'video_count'    => $video_exist,
        'videos_to_make' => max(0, TARGET_VIDEOS - $video_exist),
    ]);
    exit;
}

// Pagination
$perPage = 20;
$page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset  = ($page - 1) * $perPage;

$countSql = "
SELECT COUNT(*) AS total_rows FROM (
    SELECT promo_group, promo_subgroup FROM hdb_image_data
    WHERE promo_group IS NOT NULL AND promo_group <> ''
      AND admin_id=0 AND company_id=0
    GROUP BY  promo_group, promo_subgroup
) x";
$totalRows  = $conn->query($countSql)->fetch_assoc()['total_rows'];
$totalPages = ceil($totalRows / $perPage);

$target = TARGET_VIDEOS;
$sql = "
SELECT promo_group, promo_subgroup,
       COUNT(*) AS total_media,
       SUM(CASE WHEN LOWER(media_type)='image' THEN 1 ELSE 0 END) AS image_count,
       SUM(CASE WHEN LOWER(media_type)='video' THEN 1 ELSE 0 END) AS video_count
FROM hdb_image_data
WHERE promo_group IS NOT NULL AND promo_group <> ''
  AND admin_id=0 AND company_id=0
GROUP BY promo_group, promo_subgroup
ORDER BY promo_group, promo_subgroup LIMIT $offset, $perPage";
$result = $conn->query($sql);

// For file-existence counts, fetch image_name for each group on this page
$pageRows = [];
while ($r = $result->fetch_assoc()) $pageRows[] = $r;

foreach ($pageRows as &$r) {
    $g_e  = mysqli_real_escape_string($conn, $r['promo_group']);
    $sg_e = mysqli_real_escape_string($conn, $r['promo_subgroup']);
    $fRes = mysqli_query($conn, "
        SELECT media_type, image_name FROM hdb_image_data
        WHERE promo_group='$g_e' AND promo_subgroup='$sg_e'
          AND admin_id=0 AND company_id=0");
    $imgF = $vidF = [];
    while ($fr = mysqli_fetch_assoc($fRes)) {
        if (strtolower($fr['media_type']) === 'image') $imgF[] = $fr;
        else                                           $vidF[] = $fr;
    }
    $r['image_exist']    = countExistingFiles($imgF, IMAGE_FOLDER);
    $r['video_exist']    = countExistingFiles($vidF, VIDEO_FOLDER);
    $r['videos_to_make'] = max(0, TARGET_VIDEOS - $r['video_exist']);
}
unset($r);

$totalSql = "
SELECT SUM(total_media) total_media, SUM(image_count) image_count,
       SUM(video_count) video_count
FROM (
    SELECT COUNT(*) total_media,
        SUM(CASE WHEN LOWER(media_type)='image' THEN 1 ELSE 0 END) image_count,
        SUM(CASE WHEN LOWER(media_type)='video' THEN 1 ELSE 0 END) video_count
    FROM hdb_image_data
    WHERE promo_group IS NOT NULL AND promo_group <> ''
      AND admin_id=0 AND company_id=0
    GROUP BY promo_group, promo_subgroup
) z";
$totalsRaw = $conn->query($totalSql)->fetch_assoc();

// File-existence totals (across ALL groups, not just this page)
$allFilesRes = mysqli_query($conn, "
    SELECT media_type, image_name FROM hdb_image_data
    WHERE promo_group IS NOT NULL AND promo_group <> ''
      AND admin_id=0 AND company_id=0");
$allImgFiles = $allVidFiles = [];
while ($af = mysqli_fetch_assoc($allFilesRes)) {
    if (strtolower($af['media_type']) === 'image') $allImgFiles[] = $af;
    else                                           $allVidFiles[] = $af;
}
$totals = [
    'total_media'    => $totalsRaw['total_media'],
    'image_count'    => $totalsRaw['image_count'],
    'video_count'    => $totalsRaw['video_count'],
    'image_exist'    => countExistingFiles($allImgFiles, IMAGE_FOLDER),
    'video_exist'    => countExistingFiles($allVidFiles, VIDEO_FOLDER),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Media Summary Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
body { background:#fffdf5; font-family:Segoe UI,Tahoma,sans-serif; }
.header-box { background:#f8e9b5; border:1px solid #e7d389; border-radius:12px; padding:20px; margin-bottom:20px; }
.table thead { background:#e8c86a; }
.table thead th { color:#4b3b00; }
.need-videos { background:#fff4cf; }
.total-row { background:#e8c86a; font-weight:bold; }
.pagination .page-link { color:#8a6d00; }
.pagination .active .page-link { background:#d4af37; border-color:#d4af37; }
.badge-needed { background:#d4af37; color:#fff; padding:3px 8px; border-radius:6px; font-size:.82em; }
.badge-done   { background:#198754; color:#fff; padding:3px 8px; border-radius:6px; font-size:.82em; }
.btn-pexels { background:#05a081; color:#fff; border:none; border-radius:6px; padding:4px 10px; font-size:.8em; cursor:pointer; white-space:nowrap; }
.btn-pexels:hover { background:#038a6f; }
.prog-bar-wrap { background:#e9ecef; border-radius:4px; height:6px; margin-top:3px; }
.prog-bar-fill { height:6px; border-radius:4px; background:linear-gradient(90deg,#05a081,#d4af37); transition:width .4s; }

/* ── Modal ── */
#pexelsModal .modal-dialog { max-width:960px; }
#pexelsModal .modal-header { background:#05a081; color:#fff; }
#pexelsModal .btn-close { filter:invert(1); }

/* ── Video cards ── */
.video-card { border:2px solid #ddd; border-radius:10px; overflow:hidden; background:#fff; display:flex; flex-direction:column; transition:border-color .15s, box-shadow .15s; cursor:pointer; position:relative; }
.video-card.selected { border-color:#05a081; box-shadow:0 0 0 3px rgba(5,160,129,.25); }
.video-card.downloaded { border-color:#198754; }
.video-card .select-check { position:absolute; top:6px; left:6px; z-index:10; width:22px; height:22px; border-radius:50%; background:#fff; border:2px solid #ccc; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; transition:all .15s; }
.video-card.selected .select-check { background:#05a081; border-color:#05a081; color:#fff; }
.video-card.downloaded .select-check { background:#198754; border-color:#198754; color:#fff; }
.video-thumb-wrap { position:relative; aspect-ratio:9/16; background:#111; overflow:hidden; }
.video-thumb-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.play-btn-overlay { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.28); }
.play-btn-overlay i { font-size:2rem; color:#fff; }
.video-card-body { padding:6px 8px; flex:1; display:flex; flex-direction:column; gap:3px; }
.video-author { font-size:10px; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.video-meta   { font-size:10px; color:#888; }
.video-filename { font-size:9px; color:#aaa; font-family:monospace; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dl-status { font-size:10px; font-weight:600; text-align:center; padding:3px 0; border-top:1px solid #eee; margin-top:auto; }
.dl-status.pending  { color:#888; }
.dl-status.queued   { color:#0d6efd; }
.dl-status.running  { color:#fd7e14; }
.dl-status.done     { color:#198754; }
.dl-status.error    { color:#dc3545; }

/* ── File list rows ── */
.dl-file-row { display:flex; align-items:center; gap:10px; padding:10px 18px; border-bottom:1px solid #f0f0f0; font-size:13px; }
.dl-file-row:last-child { border-bottom:none; }
.dl-file-row .fn { flex:1; font-family:monospace; font-size:12px; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dl-file-row .st { font-size:11px; font-weight:700; white-space:nowrap; min-width:130px; text-align:right; }
.st-queued  { color:#6c757d; }
.st-running { color:#fd7e14; }
.st-done    { color:#198754; }
.st-error   { color:#dc3545; }
.st-skip    { color:#0d6efd; }
</style>
</head>
<body>

<div class="container mt-4">
    <div class="header-box d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <h2 class="mb-1">📹 Media Production Dashboard</h2>
            <p class="mb-0">Target: <strong><?= TARGET_VIDEOS ?> videos per subgroup</strong></p>
        </div>
        <div class="text-end small text-muted">
            Videos in DB: <strong><?= number_format($totals['video_count']) ?></strong> &nbsp;|&nbsp;
            Files on disk: <strong><?= number_format($totals['video_exist']) ?></strong> &nbsp;|&nbsp;
            Still needed: <strong><?= number_format(max(0, TARGET_VIDEOS * $totalRows - $totals['video_exist'])) ?></strong>
        </div>
    </div>

    <table class="table table-bordered table-hover align-middle">
        <thead>
            <tr>
                <th>Promo Group</th>
                <th>Promo Subgroup</th>
                <th>Total DB</th>
                <th>Images (DB / Disk)</th>
                <th>Videos (DB / Disk)</th>
                <th style="width:180px">Progress (<?= TARGET_VIDEOS ?> target)</th>
                <th>To Make</th>
                <th>Pexels</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pageRows as $row):
            $pct = min(100, round($row['video_exist'] / TARGET_VIDEOS * 100));
        ?>
            <tr class="<?= $row['videos_to_make'] > 0 ? 'need-videos' : '' ?>"
                id="row-<?= md5($row['promo_group'].$row['promo_subgroup']) ?>">
                <td><?= htmlspecialchars($row['promo_group']) ?></td>
                <td><?= htmlspecialchars($row['promo_subgroup']) ?></td>
                <td><?= number_format($row['total_media']) ?></td>
                <td>
                    <?= number_format($row['image_count']) ?> DB
                    / <strong><?= number_format($row['image_exist']) ?></strong> disk
                </td>
                <td class="vid-count">
                    <?= number_format($row['video_count']) ?> DB
                    / <strong><?= number_format($row['video_exist']) ?></strong> disk
                </td>
                <td>
                    <div class="prog-bar-wrap">
                        <div class="prog-bar-fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div style="font-size:10px;color:#666;margin-top:2px"><?= $pct ?>%</div>
                </td>
                <td class="vtm-count">
                    <?php if ($row['videos_to_make'] > 0): ?>
                        <span class="badge-needed"><?= $row['videos_to_make'] ?></span>
                    <?php else: ?>
                        <span class="badge-done">✅ Done</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn-pexels"
                            data-subgroup="<?= htmlspecialchars($row['promo_subgroup'], ENT_QUOTES) ?>"
                            data-group="<?= htmlspecialchars($row['promo_group'], ENT_QUOTES) ?>"
                            onclick="openPexels(this.dataset.subgroup, this.dataset.group)">
                        <i class="bi bi-camera-video-fill"></i> Find 9×16
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="2">TOTAL</td>
            <td><?= number_format($totals['total_media']) ?></td>
            <td><?= number_format($totals['image_count']) ?> DB / <strong><?= number_format($totals['image_exist']) ?></strong> disk</td>
            <td><?= number_format($totals['video_count']) ?> DB / <strong><?= number_format($totals['video_exist']) ?></strong> disk</td>
            <td colspan="2"><?= number_format(max(0, TARGET_VIDEOS * $totalRows - $totals['video_exist'])) ?> to make</td>
            <td></td>
        </tr>
        </tbody>
    </table>

    <nav>
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i==$page?'active':'' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
</div>

<!-- ═══ PEXELS MODAL ═══ -->
<div class="modal fade" id="pexelsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-camera-video-fill me-1"></i>
                    Pexels 9×16 — <span id="modalSubgroup"></span>
                </h5>
                <div class="d-flex align-items-center gap-2 ms-auto me-2">
                    <span id="selCount" class="badge bg-light text-dark" style="display:none"></span>
                    <button class="btn btn-sm btn-warning fw-bold" id="btnSelectAll" onclick="selectAll()" style="display:none">Select All</button>
                    <button class="btn btn-sm btn-success fw-bold" id="btnDownloadSel" onclick="startMultiDownload()" style="display:none">
                        <i class="bi bi-download me-1"></i> Download Selected
                    </button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" id="pexelsSearchInput" class="form-control" placeholder="Search term…"
                           onkeydown="if(event.key==='Enter') pexelsFetch(1)">
                    <button class="btn btn-success" onclick="pexelsFetch(1)">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>

                <!-- Stats bar -->
                <div id="statsBar" class="d-flex align-items-center gap-3 mb-3 px-1" style="display:none!important">
                    <span id="statSelected" class="small fw-bold text-success"></span>
                    <span id="statEst"      class="small text-muted"></span>
                </div>

                <div id="pexelsStatus"></div>
                <div id="pexelsGrid" class="row row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-xl-5 g-2"></div>

                <div class="d-flex justify-content-center align-items-center gap-3 mt-3" id="pexelsPagination" style="display:none!important">
                    <button class="btn btn-outline-secondary btn-sm" id="pexelsPrevBtn" onclick="pexelsFetch(pexelsPage-1)">
                        <i class="bi bi-chevron-left"></i> Prev
                    </button>
                    <span id="pexelsPageInfo" class="small text-muted"></span>
                    <button class="btn btn-outline-secondary btn-sm" id="pexelsNextBtn" onclick="pexelsFetch(pexelsPage+1)">
                        Next <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ DOWNLOAD PROGRESS MODAL ═══ -->
<div class="modal fade" id="dlModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:#0f2a44;color:#fff;border:none;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-cloud-download-fill me-2"></i>
                    Saving to Server
                </h5>
                <div class="ms-auto d-flex align-items-center gap-3">
                    <span id="dlModalCount" class="badge bg-warning text-dark fw-bold fs-6"></span>
                    <button class="btn btn-sm btn-danger fw-bold" onclick="cancelDownload()">✕ Cancel</button>
                </div>
            </div>
            <div class="modal-body p-0">

                <!-- Overall progress -->
                <div style="background:#1e3a5f;padding:16px 20px;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span id="dlModalLabel" style="color:#fff;font-weight:700;font-size:14px;">Preparing…</span>
                        <span id="dlModalEst"   style="color:rgba(255,255,255,.7);font-size:12px;"></span>
                    </div>
                    <div style="background:rgba(255,255,255,.15);border-radius:20px;height:12px;overflow:hidden;">
                        <div id="dlModalFill" style="height:12px;border-radius:20px;background:linear-gradient(90deg,#05a081,#d4af37);width:0%;transition:width .4s;"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span id="dlModalDone"  style="color:rgba(255,255,255,.6);font-size:11px;"></span>
                        <span id="dlModalSpeed" style="color:rgba(255,255,255,.6);font-size:11px;"></span>
                    </div>
                </div>

                <!-- File list -->
                <div id="dlFileList" style="max-height:360px;overflow-y:auto;padding:0;"></div>

            </div>
            <div class="modal-footer" style="background:#f8f9fa;border:none;padding:10px 20px;">
                <span id="dlModalSummary" class="small text-muted"></span>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="pexels_api_key" value="<?= htmlspecialchars($pexel_key ?? '', ENT_QUOTES) ?>">

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PEXELS_KEY  = document.getElementById('pexels_api_key').value;
const PER_PAGE    = 40;   // 40 cards per page
const AVG_SEC_PER = 25;   // estimated seconds per video download+encode

let pexelsPage  = 1;
let pexelsTotal = 0;
let pexelsModal = null;
let currentGroup    = '';
let currentSubgroup = '';

// Multi-download state
let selectedVideos  = {};   // { videoId: { url, filename, group, subgroup } }
let dlQueue         = [];
let dlRunning       = false;
let dlCancelled     = false;
let dlDone          = 0;
let dlErrors        = 0;
let dlStartTime     = 0;
let currentRowKey   = '';   // md5-like key for the dashboard row to refresh

document.addEventListener('DOMContentLoaded', () => {
    pexelsModal = new bootstrap.Modal(document.getElementById('pexelsModal'));
    document.getElementById('pexelsModal').addEventListener('hidden.bs.modal', () => {
        if (!dlRunning) clearModal();
    });
});

// ── Open modal ────────────────────────────────────────────
function openPexels(subgroup, group) {
    currentSubgroup = subgroup;
    currentGroup    = group;
    currentRowKey   = md5simple(group + subgroup);
    document.getElementById('modalSubgroup').textContent = subgroup + ' · ' + group;
    document.getElementById('pexelsSearchInput').value   = subgroup;
    selectedVideos = {};
    updateSelectionUI();
    clearModal();
    pexelsModal.show();
    pexelsFetch(1);
}

function clearModal() {
    document.getElementById('pexelsGrid').innerHTML   = '';
    document.getElementById('pexelsStatus').innerHTML = '';
    document.getElementById('pexelsPagination').style.display = 'none';
}

// ── Search Pexels ─────────────────────────────────────────
async function pexelsFetch(page) {
    if (!PEXELS_KEY) {
        document.getElementById('pexelsStatus').innerHTML =
            '<div class="alert alert-warning">Pexels API key is empty — set $pexel_key in config.php.</div>';
        return;
    }
    pexelsPage = page;
    const q = document.getElementById('pexelsSearchInput').value.trim() || currentSubgroup;
    document.getElementById('pexelsGrid').innerHTML   = '';
    document.getElementById('pexelsStatus').innerHTML =
        '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Searching Pexels…</div>';
    document.getElementById('pexelsPagination').style.display = 'none';

    try {
        const url = `https://api.pexels.com/videos/search?query=${encodeURIComponent(q)}&orientation=portrait&size=medium&per_page=${PER_PAGE}&page=${page}`;
        const res = await fetch(url, { headers: { Authorization: PEXELS_KEY } });
        if (res.status === 401) throw new Error('Invalid Pexels API key.');
        if (!res.ok) throw new Error('Pexels API error: ' + res.status);
        const data = await res.json();
        pexelsTotal = data.total_results;
        document.getElementById('pexelsStatus').innerHTML = '';
        renderVideos(data.videos);

        const totalPages = Math.ceil(pexelsTotal / PER_PAGE);
        if (totalPages > 1) {
            document.getElementById('pexelsPagination').style.display = 'flex';
            document.getElementById('pexelsPageInfo').textContent =
                `Page ${page} of ${totalPages} (${pexelsTotal.toLocaleString()} results)`;
            document.getElementById('pexelsPrevBtn').disabled = page <= 1;
            document.getElementById('pexelsNextBtn').disabled = page >= totalPages;
        }
    } catch(err) {
        document.getElementById('pexelsStatus').innerHTML =
            `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${err.message}</div>`;
    }
}

// ── Render 40 video cards ─────────────────────────────────
function renderVideos(videos) {
    const grid = document.getElementById('pexelsGrid');
    if (!videos || !videos.length) {
        document.getElementById('pexelsStatus').innerHTML =
            '<div class="text-center text-muted py-4">No vertical videos found. Try a different search term.</div>';
        return;
    }

    // Show select all button
    document.getElementById('btnSelectAll').style.display = '';

    grid.innerHTML = videos.map(v => {
        const file     = v.video_files.find(f => f.width < f.height && f.width >= 720)
                      || v.video_files.find(f => f.width < f.height)
                      || v.video_files[0];
        const filename = 'pexels-' + v.id + '.mp4';
        const isSel    = !!selectedVideos[v.id];
        const isDone   = false; // could check DB but skip for speed

        return `
        <div class="col">
            <div class="video-card${isSel?' selected':''}"
                 id="vcard-${v.id}"
                 onclick="toggleSelect(${v.id}, '${encodeURIComponent(file.link)}', '${filename}')">
                <div class="select-check">${isSel?'✓':''}</div>
                <div class="video-thumb-wrap" ondblclick="previewVideo(this, '${encodeURIComponent(file.link)}'); event.stopPropagation();">
                    <img src="${v.image}" alt="" loading="lazy">
                    <div class="play-btn-overlay" style="pointer-events:none">
                        <i class="bi bi-play-circle-fill" style="font-size:1.4rem"></i>
                    </div>
                </div>
                <div class="video-card-body">
                    <div class="video-author">${v.user.name}</div>
                    <div class="video-meta">${fmtDur(v.duration)} · ${file.width}×${file.height}</div>
                    <div class="video-filename" title="${filename}">${filename}</div>
                </div>
                <div class="dl-status pending" id="dlst-${v.id}">Click to select</div>
            </div>
        </div>`;
    }).join('');
}

// ── Toggle card selection ─────────────────────────────────
function toggleSelect(videoId, encodedUrl, filename) {
    const url = decodeURIComponent(encodedUrl);
    if (selectedVideos[videoId]) {
        delete selectedVideos[videoId];
    } else {
        selectedVideos[videoId] = { url, filename, group: currentGroup, subgroup: currentSubgroup };
    }
    const card  = document.getElementById('vcard-' + videoId);
    const check = card?.querySelector('.select-check');
    const st    = card?.querySelector('.dl-status');
    if (selectedVideos[videoId]) {
        card?.classList.add('selected');
        if (check) check.textContent = '✓';
        if (st)    st.textContent    = 'Queued for download';
        if (st)    st.className      = 'dl-status queued';
    } else {
        card?.classList.remove('selected');
        if (check) check.textContent = '';
        if (st)    st.textContent    = 'Click to select';
        if (st)    st.className      = 'dl-status pending';
    }
    updateSelectionUI();
}

// ── Select all visible cards ──────────────────────────────
function selectAll() {
    document.querySelectorAll('.video-card').forEach(card => {
        const id       = card.id.replace('vcard-', '');
        const urlEl    = card.querySelector('.video-thumb-wrap');
        const statusEl = card.querySelector('.dl-status');
        const fn       = 'pexels-' + id + '.mp4';
        // Get url from onclick attribute of the card
        const match = card.getAttribute('onclick')?.match(/toggleSelect\(\d+,\s*'([^']+)'/);
        const url   = match ? decodeURIComponent(match[1]) : '';
        if (url && !selectedVideos[id]) {
            selectedVideos[id] = { url, filename: fn, group: currentGroup, subgroup: currentSubgroup };
            card.classList.add('selected');
            const check = card.querySelector('.select-check');
            if (check) check.textContent = '✓';
            if (statusEl) { statusEl.textContent = 'Queued for download'; statusEl.className = 'dl-status queued'; }
        }
    });
    updateSelectionUI();
}

// ── Update header selection UI ────────────────────────────
function updateSelectionUI() {
    const count   = Object.keys(selectedVideos).length;
    const selEl   = document.getElementById('selCount');
    const dlBtn   = document.getElementById('btnDownloadSel');
    const statSel = document.getElementById('statSelected');
    const statEst = document.getElementById('statEst');
    const statsBar= document.getElementById('statsBar');

    if (count > 0) {
        selEl.textContent   = count + ' selected';
        selEl.style.display = '';
        dlBtn.style.display = '';
        dlBtn.textContent   = `⬇ Download ${count} video${count>1?'s':''}`;
        const estSec = count * AVG_SEC_PER;
        const estStr = estSec < 60 ? estSec + 's' : Math.ceil(estSec/60) + ' min';
        statsBar.style.display   = 'flex';
        statSel.textContent = count + ' selected';
        statEst.textContent = `Est. download time: ~${estStr}`;
    } else {
        selEl.style.display  = 'none';
        dlBtn.style.display  = 'none';
        statsBar.style.display = 'none';
    }
}

// ── Start multi-download ──────────────────────────────────
let dlModalInstance = null;

async function startMultiDownload() {
    dlQueue     = Object.entries(selectedVideos).map(([id, v]) => ({ id, ...v }));
    if (!dlQueue.length) return;

    dlRunning   = true;
    dlCancelled = false;
    dlDone      = 0;
    dlErrors    = 0;
    dlStartTime = Date.now();

    // Build file list in modal
    const listEl = document.getElementById('dlFileList');
    listEl.innerHTML = dlQueue.map((item, i) => `
        <div class="dl-file-row" id="dlrow-${item.id}">
            <span style="color:#aaa;font-size:11px;min-width:24px;">${i+1}</span>
            <span class="fn" title="${item.filename}">${item.filename}</span>
            <span class="st st-queued" id="dlst2-${item.id}">⏳ Queued</span>
        </div>`).join('');

    document.getElementById('dlModalCount').textContent  = dlQueue.length + ' videos';
    document.getElementById('dlModalLabel').textContent  = 'Starting…';
    document.getElementById('dlModalEst').textContent    = `Est. ~${Math.ceil(dlQueue.length * AVG_SEC_PER / 60)} min`;
    document.getElementById('dlModalFill').style.width   = '0%';
    document.getElementById('dlModalDone').textContent   = '';
    document.getElementById('dlModalSpeed').textContent  = '';
    document.getElementById('dlModalSummary').textContent= '';

    // Show modal
    dlModalInstance = new bootstrap.Modal(document.getElementById('dlModal'));
    dlModalInstance.show();

    document.getElementById('btnDownloadSel').disabled = true;

    // Process one at a time
    for (const item of dlQueue) {
        if (dlCancelled) {
            setFileStatus(item.id, 'skip', '— Cancelled');
            continue;
        }
        await downloadOne(item);
        dlDone++;
        updateDlModal();
    }

    dlRunning = false;
    document.getElementById('btnDownloadSel').disabled = false;

    const ok = dlDone - dlErrors;
    const total = dlQueue.length;
    document.getElementById('dlModalLabel').textContent =
        dlCancelled ? `Cancelled — ${ok} saved` : `✅ All done — ${ok}/${total} saved`;
    document.getElementById('dlModalFill').style.width = '100%';
    document.getElementById('dlModalSummary').textContent =
        `${ok} saved · ${dlErrors} errors · ${dlCancelled ? 'cancelled early' : 'complete'}`;

    if (ok > 0) refreshDashboardRow(currentGroup, currentSubgroup);

    if (!dlCancelled && dlErrors === 0) {
        setTimeout(() => dlModalInstance?.hide(), 2500);
    }
}

// ── Save one video to server ──────────────────────────────
async function downloadOne(item) {
    const card  = document.getElementById('vcard-' + item.id);
    const stEl  = card?.querySelector('.dl-status');
    const check = card?.querySelector('.select-check');

    // Update card in Pexels grid
    if (stEl)  { stEl.textContent = '⏳ Saving…'; stEl.className = 'dl-status running'; }

    // Update modal file row
    setFileStatus(item.id, 'running', '⏳ Saving to server…');

    const fd = new FormData();
    fd.append('url',      item.url);
    fd.append('file',     item.filename);
    fd.append('group',    item.group);
    fd.append('subgroup', item.subgroup);

    try {
        const res  = await fetch('pexels_download.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => null);

        if (!res.ok || (data && !data.success)) {
            throw new Error((data?.message) || 'HTTP ' + res.status);
        }

        const cached = data?.cached ? ' (already existed)' : '';
        setFileStatus(item.id, 'done', '✅ Saved' + cached);
        if (stEl)  { stEl.textContent = '✅ Saved'; stEl.className = 'dl-status done'; }
        if (check) { check.textContent = '✅'; }
        if (card)  { card.classList.remove('selected'); card.classList.add('downloaded'); }

    } catch(e) {
        dlErrors++;
        setFileStatus(item.id, 'error', '❌ ' + e.message.substring(0, 50));
        if (stEl)  { stEl.textContent = '❌ Error'; stEl.className = 'dl-status error'; }
        if (check) { check.textContent = '✗'; }
        console.error('[dl]', item.filename, e.message);
    }
}

// ── Update progress modal ─────────────────────────────────
function updateDlModal() {
    const total   = dlQueue.length;
    const pct     = total ? Math.round(dlDone / total * 100) : 0;
    const elapsed = (Date.now() - dlStartTime) / 1000;
    const rate    = dlDone > 0 ? elapsed / dlDone : AVG_SEC_PER;
    const remain  = Math.round((total - dlDone) * rate);
    const remStr  = remain > 60 ? Math.ceil(remain/60) + ' min' : remain + 's';
    const ok      = dlDone - dlErrors;

    document.getElementById('dlModalFill').style.width   = pct + '%';
    document.getElementById('dlModalLabel').textContent  = `Saving… ${dlDone}/${total}`;
    document.getElementById('dlModalDone').textContent   = `${ok} saved · ${dlErrors} errors`;
    document.getElementById('dlModalEst').textContent    = dlDone > 0 && dlDone < total ? `~${remStr} remaining` : '';
    document.getElementById('dlModalSpeed').textContent  = dlDone > 0 ? `~${Math.round(rate)}s per video` : '';
}

function setFileStatus(videoId, cls, text) {
    const el = document.getElementById('dlst2-' + videoId);
    if (el) { el.className = 'st st-' + cls; el.textContent = text; }
    // Scroll the row into view
    const row = document.getElementById('dlrow-' + videoId);
    if (row) row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function cancelDownload() {
    dlCancelled = true;
    document.getElementById('dlModalLabel').textContent = '⏸ Cancelling — finishing current file…';
}

// ── Preview on double-click ───────────────────────────────
function previewVideo(wrap, encodedUrl) {
    const url = decodeURIComponent(encodedUrl);
    wrap.innerHTML = `<video src="${url}" controls autoplay playsinline style="width:100%;height:100%;object-fit:cover;display:block;"></video>`;
}

// ── Refresh dashboard row after downloads ─────────────────
async function refreshDashboardRow(group, subgroup) {
    try {
        const fd = new FormData();
        fd.append('action',     'row_counts');
        fd.append('group',      group);
        fd.append('subgroup',   subgroup);
        const res  = await fetch('query_stockmedia.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        const rowId = 'row-' + md5simple(group + subgroup);
        const row   = document.getElementById(rowId);
        if (!row) return;

        const pct = Math.min(100, Math.round(data.video_exist / <?= TARGET_VIDEOS ?> * 100));

        // Update video count (show DB / disk)
        const vidEl = row.querySelector('.vid-count');
        if (vidEl) vidEl.innerHTML = data.video_db + ' DB / <strong>' + data.video_exist + '</strong> disk';

        // Update progress bar
        const fill = row.querySelector('.prog-bar-fill');
        if (fill) fill.style.width = pct + '%';
        const pctEl = row.querySelector('.prog-bar-fill + div');
        if (pctEl) pctEl.textContent = pct + '%';

        // Update videos to make badge
        const vtm = row.querySelector('.vtm-count');
        if (vtm) {
            if (data.videos_to_make > 0) {
                vtm.innerHTML = `<span class="badge-needed">${data.videos_to_make}</span>`;
                row.classList.add('need-videos');
            } else {
                vtm.innerHTML = `<span class="badge-done">✅ Done</span>`;
                row.classList.remove('need-videos');
            }
        }
    } catch(e) {
        console.warn('[refresh row]', e.message);
    }
}

// ── Helpers ───────────────────────────────────────────────
function fmtDur(s) {
    return Math.floor(s/60) + ':' + String(s%60).padStart(2,'0');
}

// Simple string hash for row ID matching (mirrors PHP md5)
function md5simple(str) {
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
    }
    return Math.abs(hash).toString(16);
}
</script>
</body>
</html>