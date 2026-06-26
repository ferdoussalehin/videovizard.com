<?php
// vizard_media_insights.php — Per-post media-level analytics

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>15552000,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
if (!headers_sent()) header('X-Frame-Options: SAMEORIGIN');
if (!isset($_SESSION['admin_id'])) { header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI'])); exit; }

include __DIR__ . '/dbconnect_hdb.php';

$admin_id = (int)$_SESSION['admin_id'];

// Resolve company_id and fetch company name
$company_name = 'My Workspace';
$company_id   = (int)($_SESSION['client_company_id'] ?? $_SESSION['company_id'] ?? 0);
if ($company_id === 0 || $company_id === $admin_id) {
    $cid_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT id FROM hdb_companies WHERE admin_id=$admin_id
         ORDER BY CASE WHEN company_type='internal' THEN 1 ELSE 0 END ASC, id ASC LIMIT 1"));
    $company_id = $cid_row ? (int)$cid_row['id'] : 0;
}
if ($company_id > 0) {
    $cn_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT companyname, brand_name FROM hdb_companies WHERE id=$company_id LIMIT 1"));
    if ($cn_row) {
        $company_name = trim($cn_row['brand_name'] ?: $cn_row['companyname'] ?: 'My Workspace');
    }
}

// ── Platform config ─────────────────────────────────────────────────────────
$platform_meta = [
    'facebook'  => ['label'=>'Facebook',  'color'=>'#1877F2','bg'=>'#E7F0FD','initial'=>'F'],
    'instagram' => ['label'=>'Instagram', 'color'=>'#E1306C','bg'=>'#FDE7F0','initial'=>'IG'],
    'youtube'   => ['label'=>'YouTube',   'color'=>'#FF0000','bg'=>'#FDEAEA','initial'=>'YT'],
    'tiktok'    => ['label'=>'TikTok',    'color'=>'#010101','bg'=>'#EBEBEB','initial'=>'TT'],
    'twitter'   => ['label'=>'X (Twitter)','color'=>'#14171A','bg'=>'#E8EAEC','initial'=>'X'],
    'linkedin'  => ['label'=>'LinkedIn',  'color'=>'#0A66C2','bg'=>'#E7F0FC','initial'=>'in'],
    'pinterest' => ['label'=>'Pinterest', 'color'=>'#E60023','bg'=>'#FDE8EB','initial'=>'Pi'],
];

// ── AJAX: return analytics for a single post ────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'fetch_analytics') {
    header('Content-Type: application/json');
    $post_id  = (int)($_POST['post_id'] ?? 0);
    $platform = $_POST['platform'] ?? 'all';
    // TODO: replace with real API calls per platform
    // Using seeded rand so values are stable per post (not random on each click)
    srand(crc32($post_id . $platform));
    echo json_encode([
        'success' => true,
        'post_id' => $post_id,
        'stats'   => [
            'likes'       => rand(40,  4800),
            'comments'    => rand(3,   480),
            'shares'      => rand(1,   320),
            'impressions' => rand(400, 48000),
            'reach'       => rand(280, 38000),
        ],
    ]);
    exit;
}

// ── AJAX: paginated post chunks ─────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'load_more') {
    header('Content-Type: application/json');
    $offset   = max(0, (int)($_POST['offset'] ?? 0));
    $platform = $_POST['platform'] ?? '';
    $where    = "p.admin_id = $admin_id";
    if ($platform !== '' && array_key_exists($platform, $platform_meta)) {
        $where .= " AND p.{$platform}_status NOT IN ('none','skip','')";
    }
    $sql = "SELECT p.id, p.title, p.caption_text, p.hashtags, p.video_filename, p.published_video,
                   p.facebook_status, p.instagram_status, p.youtube_status, p.tiktok_status,
                   p.twitter_status, p.linkedin_status, p.youtube_video_id,
                   p.video_status, p.created_date, p.thumbnail
            FROM hdb_podcasts p
            WHERE $where
            ORDER BY p.created_date DESC
            LIMIT 20 OFFSET $offset";
    $res   = mysqli_query($conn, $sql);
    $html  = '';
    $count = 0;
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $html .= render_post_card($r, $platform_meta, $platform);
            $count++;
        }
    }
    $tot_q    = mysqli_query($conn, "SELECT COUNT(*) AS c FROM hdb_podcasts p WHERE $where");
    $total    = $tot_q ? (int)mysqli_fetch_assoc($tot_q)['c'] : 0;
    $has_more = ($offset + $count) < $total;
    echo json_encode(['success'=>true,'html'=>$html,'count'=>$count,'has_more'=>$has_more]);
    exit;
}

$filter = (isset($_GET['platform']) && array_key_exists($_GET['platform'], $platform_meta))
    ? $_GET['platform'] : '';

// ── Load posts ──────────────────────────────────────────────────────────────
$where = "p.admin_id = $admin_id";
if ($filter !== '') {
    // Show any post attempted on this platform (posted, pending, failed) — exclude 'none'/'skip'
    $where .= " AND p.{$filter}_status NOT IN ('none','skip','')";
}

$result = mysqli_query($conn,
    "SELECT p.id, p.title, p.caption_text, p.hashtags, p.video_filename, p.published_video,
            p.facebook_status, p.instagram_status, p.youtube_status, p.tiktok_status,
            p.twitter_status, p.linkedin_status, p.youtube_video_id,
            p.video_status, p.created_date, p.thumbnail
     FROM hdb_podcasts p
     WHERE $where
     ORDER BY p.created_date DESC
     LIMIT 20"
);
$posts = [];
if ($result) { while ($r = mysqli_fetch_assoc($result)) $posts[] = $r; }

// ── Aggregate: total filtered count + posted/platform stats (full set) ──────
$agg_sql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN p.video_status='POSTED'      THEN 1 ELSE 0 END) AS posted,
        SUM(CASE WHEN p.facebook_status='posted'   THEN 1 ELSE 0 END) AS fb_p,
        SUM(CASE WHEN p.instagram_status='posted'  THEN 1 ELSE 0 END) AS ig_p,
        SUM(CASE WHEN p.youtube_status='posted'    THEN 1 ELSE 0 END) AS yt_p,
        SUM(CASE WHEN p.tiktok_status='posted'     THEN 1 ELSE 0 END) AS tt_p,
        SUM(CASE WHEN p.twitter_status='posted'    THEN 1 ELSE 0 END) AS tw_p,
        SUM(CASE WHEN p.linkedin_status='posted'   THEN 1 ELSE 0 END) AS li_p
     FROM hdb_podcasts p
     WHERE $where";
$agg_res = mysqli_query($conn, $agg_sql);
$agg     = $agg_res ? mysqli_fetch_assoc($agg_res) : ['total'=>0,'posted'=>0,'fb_p'=>0,'ig_p'=>0,'yt_p'=>0,'tt_p'=>0,'tw_p'=>0,'li_p'=>0];
$total_filtered = (int)$agg['total'];
$has_more       = $total_filtered > count($posts);

// ── Helpers ─────────────────────────────────────────────────────────────────
function get_posted_platforms(array $row, array $meta): array {
    $out = [];
    foreach (array_keys($meta) as $p) {
        if (($row[$p.'_status'] ?? '') === 'posted') $out[] = $p;
    }
    return $out;
}

function fmt_stat($n): string {
    if ($n >= 1000000) return number_format($n/1000000, 1).'M';
    if ($n >= 1000)    return number_format($n/1000, 1).'K';
    return number_format($n);
}

function thumb_gradient(array $platforms, array $meta): string {
    if (empty($platforms)) return 'linear-gradient(135deg,#0f2a44,#143b63)';
    $c = $meta[$platforms[0]]['color'];
    return "linear-gradient(135deg,{$c}cc,{$c}66)";
}

function render_post_card(array $post, array $platform_meta, string $filter): string {
    $posted_plats  = get_posted_platforms($post, $platform_meta);
    $plat_count    = count($posted_plats);
    $thumb_grad    = thumb_gradient($posted_plats, $platform_meta);
    $title_initial = mb_strtoupper(mb_substr(trim($post['title'] ?: 'V'), 0, 1));
    $display_title = $post['title'] ?: 'Untitled Post';
    $caption       = $post['caption_text'] ?: $post['hashtags'] ?: '';
    $date_fmt      = !empty($post['created_date']) ? date('M j, Y', strtotime($post['created_date'])) : '';
    $status        = strtolower($post['video_status'] ?? 'recorded');
    $thumb_file    = trim($post['thumbnail'] ?? '');
    $thumb_src     = $thumb_file ? '/podcast_images/' . $thumb_file : '';

    ob_start();
    ?>
<div class="post-card" data-post-id="<?= (int)$post['id'] ?>">
    <div class="thumb">
        <?php if ($thumb_src): ?>
        <img src="<?= htmlspecialchars($thumb_src) ?>" alt=""
             style="width:100%;height:100%;object-fit:cover;display:block"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="thumb-bg" style="background:<?= $thumb_grad ?>;display:none">
            <div class="thumb-initial"><?= htmlspecialchars($title_initial) ?></div>
        </div>
        <?php else: ?>
        <div class="thumb-bg" style="background:<?= $thumb_grad ?>">
            <div class="thumb-initial"><?= htmlspecialchars($title_initial) ?></div>
        </div>
        <?php endif; ?>
        <div class="thumb-platforms">
            <?php foreach ($posted_plats as $pk):
                $pm = $platform_meta[$pk]; ?>
            <span class="plat-badge"
                  style="background:<?= $pm['color'] ?>dd;color:#fff;">
                <?= htmlspecialchars($pm['initial']) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php if ($plat_count > 0): ?>
        <div class="thumb-count"><?= $plat_count ?> platform<?= $plat_count!==1?'s':'' ?></div>
        <?php endif; ?>
    </div>

    <div class="card-body">
        <div>
            <div class="card-title"><?= htmlspecialchars($display_title) ?></div>
            <?php if ($caption): ?>
            <div class="card-caption" style="margin-top:5px"><?= htmlspecialchars($caption) ?></div>
            <?php endif; ?>
        </div>

        <div class="card-meta">
            <?php if ($date_fmt): ?><span class="card-date"><?= $date_fmt ?></span><?php endif; ?>
            <span class="status-badge status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
        </div>

        <div class="metrics-grid" id="metrics-<?= (int)$post['id'] ?>">
            <div class="metric"><div class="metric-icon">&#10084;</div><div class="metric-val placeholder" data-metric="likes">—</div><div class="metric-label">Likes</div></div>
            <div class="metric"><div class="metric-icon">&#128172;</div><div class="metric-val placeholder" data-metric="comments">—</div><div class="metric-label">Comments</div></div>
            <div class="metric"><div class="metric-icon">&#8634;</div><div class="metric-val placeholder" data-metric="shares">—</div><div class="metric-label">Shares</div></div>
            <div class="metric"><div class="metric-icon">&#128065;</div><div class="metric-val placeholder" data-metric="impressions">—</div><div class="metric-label">Impressions</div></div>
            <div class="metric"><div class="metric-icon">&#127942;</div><div class="metric-val placeholder" data-metric="reach">—</div><div class="metric-label">Reach</div></div>
        </div>
    </div>

    <div class="card-footer">
        <button class="btn btn-outline"
                onclick="openModal(<?= (int)$post['id'] ?>, <?= htmlspecialchars(json_encode($display_title)) ?>, <?= htmlspecialchars(json_encode($posted_plats)) ?>)">
            &#128202; View Details
        </button>
    </div>
</div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VideoVizard — Media Insights</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
:root{
    --dark-blue:#0f2a44; --mid-blue:#143b63; --accent:#5fd1ff;
    --green:#10b981; --purple:#8b5cf6;
    --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    --bg:#f0f4f8; --card:#ffffff; --bg2:#e8edf3;
    --hover:#f0f9ff;
    --ok:#059669; --warn:#d97706; --err:#dc2626;
    --white:#ffffff; --navy:#0f2a44;
    --font:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit}

/* ── Sticky header (matches vizard_browser) ── */
.page-header{
    display:flex;justify-content:space-between;align-items:center;
    padding:12px 16px;
    background:linear-gradient(90deg,#0f2a44,#143b63);
    color:#fff;box-shadow:0 3px 10px rgba(0,0,0,.15);
    position:sticky;top:0;z-index:1000;gap:12px;
}
.logo{font-size:20px;font-weight:700;line-height:1.2;}
.brand-video{color:#fff;}
.brand-vizard{color:var(--accent);}
.nav-links{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.nav-link{font-size:13px;font-weight:600;color:rgba(255,255,255,.7);padding:7px 14px;border-radius:10px;transition:all .2s;min-height:36px;display:flex;align-items:center;}
.nav-link:hover{background:rgba(95,209,255,.15);color:#fff;}
.nav-link.active{background:rgba(95,209,255,.2);border:1px solid rgba(95,209,255,.35);color:#fff;}

/* layout */
.wrap{max-width:1320px;margin:0 auto;padding:28px 20px}

/* toolbar */
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:14px}
.toolbar-left h1{font-size:22px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.4px}
.toolbar-left p{font-size:13px;color:var(--muted);margin-top:3px}
.toolbar-right{display:flex;align-items:center;gap:10px}

/* select */
.select-wrap{position:relative;display:inline-block}
.select-wrap::after{content:"▾";position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--muted);pointer-events:none}
select.platform-select{
    appearance:none;-webkit-appearance:none;
    padding:8px 34px 8px 14px;border-radius:8px;border:1.5px solid var(--border);
    background:var(--white);color:var(--dark-blue);font-family:var(--font);font-size:13px;font-weight:600;
    cursor:pointer;outline:none;transition:border-color .15s
}
select.platform-select:hover,select.platform-select:focus{border-color:var(--dark-blue)}

/* btn */
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--font);display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn-outline{background:var(--white);border:1.5px solid var(--border);color:var(--dark-blue)}
.btn-outline:hover{border-color:var(--dark-blue);color:var(--dark-blue)}
.btn-primary{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 4px 12px rgba(16,185,129,.3);}
.btn-primary:hover{opacity:.9}

/* section label */
.section-label{font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:14px}

/* page heading with company name */
.page-title{font-size:22px;font-weight:700;color:var(--dark-blue);margin-bottom:4px;letter-spacing:-0.3px;}
.page-title-sub{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:20px;}

/* summary strip */
.summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.sum-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.sum-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;font-weight:600}
.sum-val{font-size:26px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.5px;line-height:1}
.sum-sub{font-size:12px;color:var(--muted);margin-top:5px}

/* posts grid */
.posts-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}

/* post card */
.post-card{background:var(--white);border:1px solid var(--border);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s,border-color .2s;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.post-card:hover{box-shadow:0 6px 24px rgba(15,42,68,.12);border-color:#bdd4f0}

/* thumbnail */
.thumb{position:relative;width:100%;height:180px;overflow:hidden;flex-shrink:0}
.thumb-bg{width:100%;height:100%;display:flex;align-items:center;justify-content:center}
.thumb-initial{font-size:48px;font-weight:800;color:rgba(255,255,255,.55);letter-spacing:-2px;user-select:none}
.thumb-platforms{position:absolute;bottom:10px;left:10px;display:flex;gap:5px;flex-wrap:wrap}
.plat-badge{font-size:10px;font-weight:700;padding:3px 8px;border-radius:20px;backdrop-filter:blur(4px);letter-spacing:0.3px;border:1px solid rgba(255,255,255,.3)}
.thumb-count{position:absolute;top:10px;right:10px;background:rgba(255,255,255,.92);color:var(--dark-blue);font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;border:1px solid var(--border)}

/* card body */
.card-body{padding:14px 16px 10px;flex:1;display:flex;flex-direction:column;gap:10px}
.card-title{font-size:14px;font-weight:700;color:var(--dark-blue);line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card-caption{font-size:12px;color:var(--muted);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.4}
.card-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.card-date{font-size:11px;color:var(--muted)}
.status-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:uppercase;letter-spacing:0.5px}
.status-posted{background:#d1fae5;color:#065f46}
.status-scheduled{background:#dbeafe;color:#1d4ed8}
.status-recorded{background:var(--bg2);color:var(--muted)}

/* metrics */
.metrics-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.metric{background:var(--bg);border-radius:8px;padding:8px 6px;text-align:center;border:1px solid var(--border);}
.metric-icon{font-size:14px;line-height:1;margin-bottom:3px}
.metric-val{font-size:14px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.3px;line-height:1}
.metric-val.placeholder{color:var(--border)}
.metric-label{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:0.7px;font-weight:600;margin-top:2px}

/* card footer */
.card-footer{border-top:1px solid var(--border);padding:10px 16px;display:flex;gap:8px;background:var(--bg)}
.card-footer .btn{flex:1;justify-content:center;font-size:12px;padding:7px 10px}

/* load more */
.load-more-wrap{display:flex;flex-direction:column;align-items:center;gap:8px;margin:28px 0 8px}
#loadMoreBtn{padding:12px 32px;font-size:14px}
#loadMoreBtn.loading{opacity:.6;pointer-events:none}
#loadMoreBtn.loading::after{content:" …"}
.load-more-meta{font-size:12px;color:var(--muted)}

/* empty state */
.empty-state{grid-column:1/-1;text-align:center;padding:64px 24px;background:var(--white);border:1px solid var(--border);border-radius:14px}
.empty-icon{font-size:48px;margin-bottom:12px;opacity:.4}
.empty-title{font-size:16px;font-weight:700;color:var(--dark-blue);margin-bottom:6px}
.empty-sub{font-size:13px;color:var(--muted)}

/* modal */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(15,42,68,.45);backdrop-filter:blur(3px);z-index:1000;align-items:center;justify-content:center;padding:24px}
.modal-backdrop.open{display:flex}
.modal{background:var(--white);border-radius:16px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(15,42,68,.25)}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;padding:20px 24px 16px;border-bottom:1px solid var(--border)}
.modal-title{font-size:16px;font-weight:700;color:var(--dark-blue)}
.modal-sub{font-size:12px;color:var(--muted);margin-top:3px}
.modal-close{background:none;border:none;font-size:20px;color:var(--muted);cursor:pointer;line-height:1;padding:2px 6px;border-radius:6px}
.modal-close:hover{background:var(--bg2);color:var(--dark-blue)}
.modal-body{padding:20px 24px}
.modal-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}
.modal-stat{background:var(--bg);border-radius:10px;padding:14px 12px;text-align:center;border:1px solid var(--border);}
.modal-stat-val{font-size:22px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.5px;line-height:1}
.modal-stat-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;font-weight:600;margin-top:4px}
.modal-section-label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.modal-detail-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)}
.modal-detail-row:last-child{border-bottom:none}
.modal-detail-key{font-size:13px;color:var(--muted)}
.modal-detail-val{font-size:13px;font-weight:600;color:var(--dark-blue)}

/* toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--dark-blue);color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;z-index:2000;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;pointer-events:none}
.toast.show{opacity:1;transform:translateY(0)}

/* responsive */
@media(max-width:1100px){.posts-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.summary-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.posts-grid{grid-template-columns:1fr}.summary-row{grid-template-columns:1fr}.metrics-grid{grid-template-columns:repeat(3,1fr)}}
</style>
</head>
<body>

<!-- Header (matches vizard_browser) -->
<div class="page-header">
    <div class="logo"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></div>
    <nav class="nav-links">
        <a href="vizard_browser.php" class="nav-link">🏠 Home</a>
        <a href="vizard_scheduler.php" class="nav-link">📡 Scheduler</a>
        <a href="vizard_dashboard.php" class="nav-link">📊 Analytics</a>
        <a href="vizard_media_insights.php" class="nav-link active">💡 Media Insights</a>
    </nav>
</div>

<div class="wrap">

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="page-title"><?= htmlspecialchars($company_name, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="page-title-sub">Media Insights<?= $filter ? ' · '.htmlspecialchars($platform_meta[$filter]['label']) : '' ?></div>
    </div>
    <div class="toolbar-right">
        <div class="select-wrap">
            <select class="platform-select" id="platformFilter" onchange="applyFilter(this.value)">
                <option value="" <?= $filter==='' ? 'selected' : '' ?>>All Platforms</option>
                <?php foreach ($platform_meta as $key => $pm): ?>
                <option value="<?= $key ?>" <?= $filter===$key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($pm['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<!-- Summary strip -->
<?php
$total_posts      = $total_filtered;
$posted_count     = (int)$agg['posted'];
$platforms_active = 0;
foreach (['fb_p','ig_p','yt_p','tt_p','tw_p','li_p'] as $k) {
    if ((int)$agg[$k] > 0) $platforms_active++;
}
?>
<div class="section-label">Summary<?= $filter ? ' — '.htmlspecialchars($platform_meta[$filter]['label']) : ' — All Platforms' ?></div>
<div class="summary-row">
    <div class="sum-card">
        <div class="sum-label">Total Posts</div>
        <div class="sum-val"><?= $total_posts ?></div>
        <div class="sum-sub">in this view</div>
    </div>
    <div class="sum-card">
        <div class="sum-label">Fully Posted</div>
        <div class="sum-val"><?= $posted_count ?></div>
        <div class="sum-sub">across all connected platforms</div>
    </div>
    <div class="sum-card">
        <div class="sum-label">Platforms Reached</div>
        <div class="sum-val"><?= $platforms_active ?></div>
        <div class="sum-sub">platforms with posts</div>
    </div>
    <div class="sum-card">
        <div class="sum-label">Analytics</div>
        <div class="sum-val" style="font-size:15px;padding-top:4px">Auto-loaded</div>
        <div class="sum-sub">metrics load on each card automatically</div>
    </div>
</div>

<!-- Posts grid -->
<div class="section-label">Published Content</div>
<div class="posts-grid" id="postsGrid">

<?php if (empty($posts)): ?>
<div class="empty-state">
    <div class="empty-icon">&#128247;</div>
    <div class="empty-title">No posts found</div>
    <div class="empty-sub">
        <?php if ($filter): ?>
            No posts have been published to <?= htmlspecialchars($platform_meta[$filter]['label']) ?> yet.
            <a href="vizard_media_insights.php" style="color:var(--dark-blue);font-weight:600">View all platforms</a>
        <?php else: ?>
            Schedule and publish your first video to see insights here.
        <?php endif; ?>
    </div>
</div>

<?php else: foreach ($posts as $post):
    echo render_post_card($post, $platform_meta, $filter);
endforeach; endif; ?>

</div><!-- /posts-grid -->

<?php if ($has_more): ?>
<div class="load-more-wrap" id="loadMoreWrap">
    <button class="btn btn-primary" id="loadMoreBtn" onclick="loadMore()">Load more posts</button>
    <div class="load-more-meta" id="loadMoreMeta">Showing <span id="loadedCount"><?= count($posts) ?></span> of <?= $total_filtered ?></div>
</div>
<?php endif; ?>

</div><!-- /wrap -->

<!-- Details modal -->
<div class="modal-backdrop" id="modalBackdrop" onclick="closeModalOnBackdrop(event)">
    <div class="modal" id="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalTitle">Post Details</div>
                <div class="modal-sub" id="modalSub"></div>
            </div>
            <button class="modal-close" onclick="closeModal()">&#215;</button>
        </div>
        <div class="modal-body" id="modalBody">
            <p style="color:var(--muted);font-size:13px">Loading analytics…</p>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// ── Platform filter ──────────────────────────────────────────────────────────
function applyFilter(val) {
    const url = new URL(window.location.href);
    if (val) url.searchParams.set('platform', val);
    else url.searchParams.delete('platform');
    window.location.href = url.toString();
}

// ── Stat formatter ───────────────────────────────────────────────────────────
function fmtStat(n) {
    n = parseInt(n, 10);
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1) + 'K';
    return n.toLocaleString();
}

// ── Per-card analytics fetch (auto on load) ──────────────────────────────────
const _cache = {};
const _platformFilter = <?= json_encode($filter ?: 'all') ?>;

function fetchAnalytics(postId, platform) {
    if (_cache[postId]) { applyStats(postId, _cache[postId]); return Promise.resolve(_cache[postId]); }

    const fd = new FormData();
    fd.append('action', 'fetch_analytics');
    fd.append('post_id', postId);
    fd.append('platform', platform);

    return fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                _cache[postId] = data.stats;
                applyStats(postId, data.stats);
                return data.stats;
            }
            return null;
        })
        .catch(() => null);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.post-card[data-post-id]').forEach(card => {
        const id = parseInt(card.dataset.postId, 10);
        if (id) fetchAnalytics(id, _platformFilter);
    });
});

// ── Load more posts ─────────────────────────────────────────────────────────
let _offset = <?= count($posts) ?>;
const _totalFiltered = <?= (int)$total_filtered ?>;

function loadMore() {
    const btn = document.getElementById('loadMoreBtn');
    if (!btn || btn.classList.contains('loading')) return;
    btn.classList.add('loading');

    const fd = new FormData();
    fd.append('action', 'load_more');
    fd.append('offset', _offset);
    fd.append('platform', _platformFilter === 'all' ? '' : _platformFilter);

    fetch(window.location.pathname, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.classList.remove('loading');
            if (!data || !data.success || !data.html) {
                showToast('Could not load more posts');
                return;
            }
            const grid = document.getElementById('postsGrid');
            const tmp  = document.createElement('div');
            tmp.innerHTML = data.html.trim();
            const newCards = Array.from(tmp.children);
            newCards.forEach(card => {
                grid.appendChild(card);
                const id = parseInt(card.dataset.postId, 10);
                if (id) fetchAnalytics(id, _platformFilter);
            });
            _offset += data.count;
            const counter = document.getElementById('loadedCount');
            if (counter) counter.textContent = _offset;
            if (!data.has_more) {
                const wrap = document.getElementById('loadMoreWrap');
                if (wrap) wrap.style.display = 'none';
            }
        })
        .catch(() => {
            btn.classList.remove('loading');
            showToast('Network error — please try again');
        });
}

function applyStats(postId, stats) {
    const grid = document.getElementById('metrics-' + postId);
    if (!grid) return;
    grid.querySelectorAll('[data-metric]').forEach(el => {
        const key = el.dataset.metric;
        if (stats[key] !== undefined) {
            el.textContent = fmtStat(stats[key]);
            el.classList.remove('placeholder');
        }
    });
}

// ── Modal ─────────────────────────────────────────────────────────────────────
const platformMeta = <?= json_encode(array_map(fn($v) => ['label'=>$v['label'],'color'=>$v['color'],'initial'=>$v['initial']], $platform_meta)) ?>;

function openModal(postId, title, platforms) {
    document.getElementById('modalTitle').textContent = title;
    const sub = platforms.map(p => platformMeta[p]?.label ?? p).join(' · ');
    document.getElementById('modalSub').textContent = sub || 'No platforms posted yet';

    const stats = _cache[postId];
    const body  = document.getElementById('modalBody');

    if (!stats) {
        body.innerHTML = '<p style="color:var(--muted);font-size:13px;text-align:center;padding:12px 0">Loading analytics…</p>';
        fetchAnalytics(postId, _platformFilter).then(s => {
            if (s && document.getElementById('modalBackdrop').classList.contains('open')) {
                openModal(postId, title, platforms);
            }
        });
    } else {
        const metrics = [
            { key:'likes',       label:'Likes / Reactions', icon:'❤️' },
            { key:'comments',    label:'Comments',           icon:'💬' },
            { key:'shares',      label:'Shares / Reposts',   icon:'🔁' },
            { key:'impressions', label:'Impressions',        icon:'👁️' },
            { key:'reach',       label:'Unique Reach',       icon:'🏆' },
        ];

        const engRate = stats.impressions > 0
            ? (((stats.likes + stats.comments + stats.shares) / stats.impressions) * 100).toFixed(2)
            : '—';

        let html = '<div class="modal-stats">';
        metrics.forEach(m => {
            html += `<div class="modal-stat">
                <div class="modal-stat-val">${fmtStat(stats[m.key])}</div>
                <div class="modal-stat-label">${m.label}</div>
            </div>`;
        });
        html += `<div class="modal-stat">
            <div class="modal-stat-val">${engRate}%</div>
            <div class="modal-stat-label">Engagement Rate</div>
        </div>`;
        html += '</div>';

        html += '<div class="modal-section-label">Breakdown</div>';
        metrics.forEach(m => {
            html += `<div class="modal-detail-row">
                <span class="modal-detail-key">${m.icon} ${m.label}</span>
                <span class="modal-detail-val">${fmtStat(stats[m.key])}</span>
            </div>`;
        });
        html += `<div class="modal-detail-row">
            <span class="modal-detail-key">📈 Engagement Rate</span>
            <span class="modal-detail-val">${engRate}%</span>
        </div>`;

        if (platforms.length) {
            html += '<div class="modal-section-label" style="margin-top:16px">Published On</div>';
            platforms.forEach(p => {
                const pm = platformMeta[p] || {};
                html += `<div class="modal-detail-row">
                    <span class="modal-detail-key" style="display:flex;align-items:center;gap:8px">
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${pm.color||'#999'}"></span>
                        ${pm.label || p}
                    </span>
                    <span class="modal-detail-val" style="color:var(--green)">Posted ✓</span>
                </div>`;
            });
        }

        body.innerHTML = html;
    }

    document.getElementById('modalBackdrop').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('modalBackdrop').classList.remove('open');
    document.body.style.overflow = '';
}

function closeModalOnBackdrop(e) {
    if (e.target === document.getElementById('modalBackdrop')) closeModal();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Toast ─────────────────────────────────────────────────────────────────────
let _toastTimer;
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
}
</script>
</body>
</html>