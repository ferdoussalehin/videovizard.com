<?php
session_start();
include 'dbconnect_hdb.php';

header('Content-Type: application/json');
error_reporting(0);
ob_start(); // catch any stray output

$status      = $_GET['status']      ?? 'active';
$page        = (int)($_GET['page']        ?? 1);
$admin_id    = (int)($_GET['admin_id']    ?? 0);
$company_id  = (int)($_GET['company_id']  ?? 0);
$campaign_id = (int)($_GET['campaign_id'] ?? 0);
$niche       = trim($_GET['niche']        ?? '');
$limit       = 12;
$offset      = ($page - 1) * $limit;

switch ($status) {
    case 'active':
        // Active now also includes RECORDED videos — the separate "Completed"
        // tab was removed and merged into Active.
        $status_condition = "video_status NOT IN ('SCHEDULED','POSTED','PUBLISHED','ARCHIVED')
                             AND (archived_flag IS NULL OR archived_flag = 0)
                             AND (is_campaign IS NULL OR is_campaign = 0)";
        break;
    case 'completed':
        // Kept for backward-compat (e.g. Instagram Grid / Approval features
        // that fetch RECORDED videos directly) even though there's no longer
        // a visible "Completed" tab in the UI.
        $status_condition = "video_status = 'RECORDED'
                             AND (archived_flag IS NULL OR archived_flag = 0)";
        break;
    case 'scheduled':
        $status_condition = "video_status = 'SCHEDULED'
                             AND (archived_flag IS NULL OR archived_flag = 0)";
        break;
    case 'posted':
        $status_condition = "video_status IN ('POSTED','PUBLISHED')
                             AND (archived_flag IS NULL OR archived_flag = 0)";
        break;
    case 'archived':
        $status_condition = "(video_status = 'ARCHIVED' OR archived_flag = 1)";
        break;
    default:
        $status_condition = "(archived_flag IS NULL OR archived_flag = 0)";
}

$_urow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT role, team_lead_id FROM hdb_users WHERE id = $admin_id LIMIT 1"));
$_role    = $_urow['role']        ?? '';
$_lead_id = (int)($_urow['team_lead_id'] ?? 0);

if ($_role === 'Team Leader') {
    $admin_scope = "(admin_id = $admin_id OR team_lead_id = $admin_id)";
} elseif ($_role === 'Team Member' && $_lead_id > 0) {
    $admin_scope = "team_lead_id = $_lead_id";
} else {
    $admin_scope = "(admin_id = $admin_id OR team_lead_id = $admin_id)";
}

$base_where = "$admin_scope AND $status_condition";
if ($company_id > 0)  $base_where .= " AND company_id = $company_id";
if ($campaign_id > 0) {
    $base_where .= " AND campaign_id = $campaign_id";
} else {
    $base_where .= " AND (campaign_id IS NULL OR campaign_id = 0)";
}
if ($niche !== '') {
    $safeNiche   = mysqli_real_escape_string($conn, $niche);
    $base_where .= " AND niche = '$safeNiche'";
}

$count_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM hdb_podcasts WHERE $base_where");
if (!$count_result) {
    ob_get_clean();
    echo json_encode(['success'=>false,'error'=>'DB count error: '.mysqli_error($conn),'where'=>$base_where]);
    exit;
}
$total_rows = (int)mysqli_fetch_assoc($count_result)['total'];
$has_more   = ($offset + $limit) < $total_rows;

$result = mysqli_query($conn,
    "SELECT * FROM hdb_podcasts WHERE $base_where ORDER BY updated_at DESC, id DESC LIMIT $limit OFFSET $offset"
);
if (!$result) {
    ob_get_clean();
    echo json_encode(['success'=>false,'error'=>'DB fetch error: '.mysqli_error($conn)]);
    exit;
}
$videos = [];
if ($result && mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) $videos[] = $row;
}

$counts_where = "$admin_scope AND (campaign_id IS NULL OR campaign_id = 0)"
              . ($company_id > 0 ? " AND company_id = $company_id" : '');
$counts_result = mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN
                video_status NOT IN ('SCHEDULED','POSTED','PUBLISHED','ARCHIVED')
                AND (archived_flag IS NULL OR archived_flag = 0)
                AND (is_campaign IS NULL OR is_campaign = 0)
            THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN video_status = 'RECORDED'
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN video_status = 'SCHEDULED'
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as scheduled_count,
        SUM(CASE WHEN video_status IN ('POSTED','PUBLISHED')
                AND (archived_flag IS NULL OR archived_flag = 0) THEN 1 ELSE 0 END) as posted_count,
        SUM(CASE WHEN (video_status = 'ARCHIVED' OR archived_flag = 1) THEN 1 ELSE 0 END) as archived_count
     FROM hdb_podcasts WHERE $counts_where"
);
$counts = $counts_result ? mysqli_fetch_assoc($counts_result) : [];

$html = '';

foreach ($videos as $video) {
    $vid_id        = (int)$video['id'];
    $internal_stat = $video['internal_status'] ?? '';
    $video_status  = $video['video_status']    ?? '';
    $lang_code     = htmlspecialchars($video['lang_code'] ?? 'en');
    $is_draft      = ($internal_stat === 'draft');

    $thumb = getThumbnail($video);
    $date  = date('M j, Y', strtotime($video['updated_at'] ?? $video['created_at'] ?? 'now'));

    if ($status === 'archived' || $video_status === 'ARCHIVED' || ($video['archived_flag'] ?? 0) == 1) {
        $status_class   = 'status-archived';
        $status_text    = 'Archived';
        $archived_class = ' archived';
    } else {
        $archived_class = '';
        switch ($video_status) {
            case 'RECORDED':
                $status_class = 'status-completed';   $status_text = 'Completed';   break;
            case 'SCHEDULED':
                $status_class = 'status-scheduled';   $status_text = 'Scheduled';   break;
            case 'POSTED':
            case 'PUBLISHED':
                $status_class = 'status-posted';      $status_text = 'Posted';      break;
            case 'draft':
            case 'ready':
                $status_class = 'status-in-progress'; $status_text = 'Draft';       break;
            default:
                $status_class = 'status-in-progress'; $status_text = 'In Progress'; break;
        }
    }

    $is_external  = isset($video['podcast_type']) && $video['podcast_type'] === 'external';
    $card_extra   = $is_draft ? ' is-draft' : '';
    $card_onclick = $is_external
        ? "onclick=\"openExternalVideo({$vid_id})\""
        : "onclick=\"openVideoOrDraft({$vid_id}, '" . addslashes($internal_stat) . "', '{$lang_code}')\"";

    $approval_status = $video['approval_status'] ?? '';
    $vid_title_js    = addslashes(htmlspecialchars_decode($video['title'] ?? 'Untitled'));

    $html .= "<div class=\"project-card{$archived_class}{$card_extra}\""
           . " data-id=\"{$vid_id}\""
           . " data-video-status=\"{$video_status}\""
           . " data-approval-status=\"{$approval_status}\""
           . " {$card_onclick}>";
    $html .= "<div class=\"status-badge {$status_class}\">{$status_text}</div>";

    if ($is_draft) {
        $html .= '<div class="draft-badge">🎬 Ready to Build</div>';
    }

    if ($thumb) {
        $html .= '<img src="' . htmlspecialchars($thumb) . '" class="card-thumb" alt="Thumbnail" '
               . 'onerror="if(!this.dataset.retried){'
               .   'this.dataset.retried=1;'
               .   'var f=this.src.split(\'/\').pop();'
               .   'this.src=\'podcast_images/\'+f;'
               . '}else{'
               .   'this.style.display=\'none\';'
               .   'this.nextElementSibling.style.display=\'flex\';'
               . '}">';
        $html .= '<div class="card-thumb-default" style="display:none;">'
               . '<div class="play-icon">🎬</div>'
               . '<div class="no-thumb-text">No Thumbnail</div></div>';
    } else {
        $html .= '<div class="card-thumb-default">'
               . '<div class="play-icon">' . ($is_draft ? '⚡' : '🎬') . '</div>'
               . '<div class="no-thumb-text">' . ($is_draft ? 'Draft — Tap to Build' : 'No Thumbnail') . '</div>'
               . '</div>';
    }

    // ── Action buttons ────────────────────────────────────────
    $html .= '<div class="card-actions">';

    $is_archived = ($status === 'archived' || $video_status === 'ARCHIVED' || ($video['archived_flag'] ?? 0) == 1);
    $is_recorded = ($video_status === 'RECORDED' && !$is_archived);
    $is_ready    = ($video_status === 'ready' && !$is_archived);

    if ($is_archived) {
        $html .= '<button class="action-btn restore" '
               . 'onclick="event.stopPropagation(); restoreVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Reactivate">♻️</button>';
        $html .= '<button class="action-btn delete" '
               . 'onclick="event.stopPropagation(); deleteVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Delete permanently">🗑️</button>';

    } elseif ($is_recorded) {
        // RECORDED: Edit, Post Now, Schedule, Archive, Delete — always 5 icons,
        // including external videos (edit still opens videomaker.php).
        $html .= '<button class="action-btn edit" '
               . 'onclick="event.stopPropagation(); window.location.href=\'videomaker.php?podcast_id=' . $vid_id . '\'" '
               . 'title="Edit">✏️</button>';
        $html .= '<button class="action-btn action-btn-post" '
               . 'onclick="event.stopPropagation(); openBrowserPostModal(' . $vid_id . ', \'' . $vid_title_js . '\', \'now\')" '
               . 'title="Post Now" '
               . 'style="background:#f0fdf4;color:#059669;border:1.5px solid #86efac;">📤</button>';
        $html .= '<button class="action-btn action-btn-schedule" '
               . 'onclick="event.stopPropagation(); openBrowserPostModal(' . $vid_id . ', \'' . $vid_title_js . '\', \'schedule\')" '
               . 'title="Schedule Post" '
               . 'style="background:#eff6ff;color:#2563eb;border:1.5px solid #bfdbfe;">🗓️</button>';
        $html .= '<button class="action-btn archive" '
               . 'onclick="event.stopPropagation(); archiveVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Archive">📦</button>';
        $html .= '<button class="action-btn delete" '
               . 'onclick="event.stopPropagation(); deleteVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Delete">🗑️</button>';

    } elseif ($is_ready) {
        // READY (while active): Edit, Delete only — no archive/post/schedule
        if (!$is_external) {
            $html .= '<button class="action-btn edit" '
                   . 'onclick="event.stopPropagation(); window.location.href=\'videomaker.php?podcast_id=' . $vid_id . '\'" '
                   . 'title="Edit">✏️</button>';
        }
        $html .= '<button class="action-btn delete" '
               . 'onclick="event.stopPropagation(); deleteVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Delete">🗑️</button>';

    } else {
        if ($is_draft) {
            $html .= '<button class="action-btn edit" '
                   . "onclick=\"event.stopPropagation(); openVideoOrDraft({$vid_id}, 'draft', '{$lang_code}')\" "
                   . 'title="Build Video">⚡</button>';
        } elseif (!$is_external) {
            $html .= '<button class="action-btn edit" '
                   . 'onclick="event.stopPropagation(); window.location.href=\'videomaker.php?podcast_id=' . $vid_id . '\'" '
                   . 'title="Edit">✏️</button>';
        }
        $html .= '<button class="action-btn archive" '
               . 'onclick="event.stopPropagation(); archiveVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Archive">📦</button>';
        $html .= '<button class="action-btn delete" '
               . 'onclick="event.stopPropagation(); deleteVideo(' . $vid_id . ', this.closest(\'.project-card\'))" '
               . 'title="Delete">🗑️</button>';
    }

    $html .= '</div>'; // .card-actions

    $title = htmlspecialchars($video['title'] ?? 'Untitled Project');
    $html .= "<div class=\"card-body\">"
           . "<div class=\"card-title\">{$title}</div>"
           . "<div class=\"card-meta\">"
           . "<span class=\"card-date\">📅 {$date}</span>"
           . "<span class=\"card-id\">🌐 {$lang_code}</span>"
           . "</div></div>";

    $html .= '</div>'; // .project-card
}

if (empty($videos)) {
    $label = $campaign_id > 0 ? 'videos in this campaign' : "{$status} videos";
    $html  = "<div class=\"empty-state\">"
           . "<div class=\"empty-icon\">📭</div>"
           . "<p>No {$label} found</p>"
           . "<div class=\"empty-hint\">Create a new project to get started</div>"
           . "</div>";
}

$stray = ob_get_clean();
if ($stray) error_log('[ajax_load_videos] stray output: ' . substr($stray, 0, 200));

echo json_encode([
    'success'  => true,
    'html'     => $html,
    'has_more' => $has_more,
    'counts'   => [
        'active'    => (int)($counts['active_count']    ?? 0),
        'completed' => (int)($counts['completed_count'] ?? 0),
        'scheduled' => (int)($counts['scheduled_count'] ?? 0),
        'posted'    => (int)($counts['posted_count']    ?? 0),
        'archived'  => (int)($counts['archived_count']  ?? 0),
    ]
]);

function getThumbnail($row) {
    $thumb = trim($row['thumbnail'] ?? '');
    if (empty($thumb)) return '';
    $bare = basename($thumb);
    if (empty($bare)) return '';
    return 'podcast_thumbnails/' . $bare;
}
?>