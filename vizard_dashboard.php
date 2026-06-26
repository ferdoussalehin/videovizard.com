<?php
// VideoVizard Dashboard — Light Blue Theme
// Facebook card pulls live data from Graph API for the page connected by the
// currently logged-in admin; other platforms still mocked.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/meta/fb_analytics.php';
require_once __DIR__ . '/meta/ig_analytics.php';
require_once __DIR__ . '/linkedin/li_analytics.php';

// Per-admin DB lookup if a session is present; otherwise the analytics module
// falls back to meta/tokens.json. Either way, the dashboard is page-agnostic
// — it shows whatever page the current user has connected.
$dash_admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
$dash_conn     = null;
if ($dash_admin_id && file_exists(__DIR__ . '/dbconnect_hdb.php')) {
    include_once __DIR__ . '/dbconnect_hdb.php'; // provides $conn
    if (isset($conn) && $conn instanceof mysqli) $dash_conn = $conn;
}

// Resolve company_id and fetch company name
$dash_company_name = 'My Workspace';
if ($dash_conn && $dash_admin_id) {
    $dash_company_id = (int)($_SESSION['client_company_id'] ?? $_SESSION['company_id'] ?? 0);
    if ($dash_company_id === 0 || $dash_company_id === $dash_admin_id) {
        $dc_row = mysqli_fetch_assoc(mysqli_query($dash_conn,
            "SELECT id FROM hdb_companies WHERE admin_id=$dash_admin_id
             ORDER BY CASE WHEN company_type='internal' THEN 1 ELSE 0 END ASC, id ASC LIMIT 1"));
        $dash_company_id = $dc_row ? (int)$dc_row['id'] : 0;
    }
    if ($dash_company_id > 0) {
        $cn_row = mysqli_fetch_assoc(mysqli_query($dash_conn,
            "SELECT companyname, brand_name FROM hdb_companies WHERE id=$dash_company_id LIMIT 1"));
        if ($cn_row) {
            $dash_company_name = trim($cn_row['brand_name'] ?: $cn_row['companyname'] ?: 'My Workspace');
        }
    }
}

function fmt($n) {
    if ($n >= 1000000) return number_format($n/1000000, 1) . 'M';
    if ($n >= 1000)    return number_format($n/1000, 1) . 'K';
    return number_format($n);
}

$platforms = [
    'facebook' => [
        'name'          => 'Facebook',
        'color'         => '#1877F2',
        'bg'            => '#E7F0FD',
        'initial'       => 'f',
        'followers'     => 24800,
        'follower_delta'=> 2.4,
        'reach'         => 184000,
        'reach_delta'   => 5.1,
        'impressions'   => 412000,
        'imp_delta'     => 8.3,
        'eng_rate'      => 4.2,
        'eng_delta'     => 0.8,
        'video_views'   => 98000,
        'avg_watch'     => '0:42',
        'completion'    => 54,
        'top_time'      => 'Wed 7pm',
        'best_format'   => 'Reels',
        'clicks'        => 3200,
        'page_visits'   => 8700,
        'link_clicks'   => 1240,
        'cpm'           => 4.80,
        'est_revenue'   => 470,
        'age_top'       => '25–34',
        'gender'        => '58% F',
        'top_country'   => 'Canada',
        'sentiment'     => 92,
        'comments'      => 620,
        'shares'        => 340,
        'saves'         => 210,
        'posts_month'   => 18,
        'scheduled'     => 4,
        'status'        => 'connected',
    ],
    'instagram' => [
        'name'          => 'Instagram',
        'color'         => '#E1306C',
        'bg'            => '#FDE7F0',
        'initial'       => 'ig',
        'followers'     => 61200,
        'follower_delta'=> 5.8,
        'reach'         => 520000,
        'reach_delta'   => 14.2,
        'impressions'   => 980000,
        'imp_delta'     => 11.6,
        'eng_rate'      => 6.7,
        'eng_delta'     => 1.2,
        'video_views'   => 310000,
        'avg_watch'     => '0:28',
        'completion'    => 61,
        'top_time'      => 'Sat 6pm',
        'best_format'   => 'Reels',
        'clicks'        => 7800,
        'page_visits'   => 22000,
        'link_clicks'   => 4100,
        'cpm'           => 6.40,
        'est_revenue'   => 1980,
        'age_top'       => '18–24',
        'gender'        => '64% F',
        'top_country'   => 'USA',
        'sentiment'     => 96,
        'comments'      => 1840,
        'shares'        => 920,
        'saves'         => 3400,
        'posts_month'   => 24,
        'scheduled'     => 6,
        'status'        => 'connected',
    ],
    'linkedin' => [
        'name'          => 'LinkedIn',
        'color'         => '#0A66C2',
        'bg'            => '#E7F0FC',
        'initial'       => 'in',
        'followers'     => 8700,
        'follower_delta'=> 4.7,
        'reach'         => 42000,
        'reach_delta'   => 11.2,
        'impressions'   => 98000,
        'imp_delta'     => 9.8,
        'eng_rate'      => 3.8,
        'eng_delta'     => 0.5,
        'video_views'   => 22000,
        'avg_watch'     => '1:14',
        'completion'    => 44,
        'top_time'      => 'Tue 9am',
        'best_format'   => 'Document posts',
        'clicks'        => 2400,
        'page_visits'   => 6800,
        'link_clicks'   => 1860,
        'cpm'           => 14.20,
        'est_revenue'   => 312,
        'age_top'       => '30–44',
        'gender'        => '54% M',
        'top_country'   => 'Canada',
        'sentiment'     => 94,
        'comments'      => 280,
        'shares'        => 190,
        'saves'         => 640,
        'posts_month'   => 12,
        'scheduled'     => 3,
        'status'        => 'connected',
    ],
    'tiktok' => [
        'name'          => 'TikTok',
        'color'         => '#010101',
        'bg'            => '#E8E8E8',
        'initial'       => 'tt',
        'followers'     => 112400,
        'follower_delta'=> 12.1,
        'reach'         => 2100000,
        'reach_delta'   => 38.4,
        'impressions'   => 4800000,
        'imp_delta'     => 42.1,
        'eng_rate'      => 9.8,
        'eng_delta'     => 2.4,
        'video_views'   => 2100000,
        'avg_watch'     => '0:19',
        'completion'    => 72,
        'top_time'      => 'Fri 9pm',
        'best_format'   => 'Short clips',
        'clicks'        => 18400,
        'page_visits'   => 0,
        'link_clicks'   => 8200,
        'cpm'           => 2.10,
        'est_revenue'   => 4410,
        'age_top'       => '18–24',
        'gender'        => '52% F',
        'top_country'   => 'USA',
        'sentiment'     => 88,
        'comments'      => 8700,
        'shares'        => 44000,
        'saves'         => 12000,
        'posts_month'   => 30,
        'scheduled'     => 8,
        'status'        => 'connected',
    ],
    'youtube' => [
        'name'          => 'YouTube',
        'color'         => '#FF0000',
        'bg'            => '#FDEAEA',
        'initial'       => 'yt',
        'followers'     => 38900,
        'follower_delta'=> 3.2,
        'reach'         => 620000,
        'reach_delta'   => 9.7,
        'impressions'   => 1200000,
        'imp_delta'     => 7.3,
        'eng_rate'      => 5.1,
        'eng_delta'     => 0.6,
        'video_views'   => 620000,
        'avg_watch'     => '4:12',
        'completion'    => 48,
        'top_time'      => 'Sun 2pm',
        'best_format'   => 'Long-form',
        'clicks'        => 9200,
        'page_visits'   => 0,
        'link_clicks'   => 3800,
        'cpm'           => 8.20,
        'est_revenue'   => 5084,
        'age_top'       => '25–34',
        'gender'        => '61% M',
        'top_country'   => 'India',
        'sentiment'     => 91,
        'comments'      => 2100,
        'shares'        => 640,
        'saves'         => 4200,
        'posts_month'   => 8,
        'scheduled'     => 2,
        'status'        => 'connected',
    ],
    'x' => [
        'name'          => 'X (Twitter)',
        'color'         => '#14171A',
        'bg'            => '#E8EAEC',
        'initial'       => 'x',
        'followers'     => 19300,
        'follower_delta'=> 1.1,
        'reach'         => 88000,
        'reach_delta'   => 2.8,
        'impressions'   => 240000,
        'imp_delta'     => 3.1,
        'eng_rate'      => 2.9,
        'eng_delta'     => -0.3,
        'video_views'   => 44000,
        'avg_watch'     => '0:22',
        'completion'    => 38,
        'top_time'      => 'Mon 8am',
        'best_format'   => 'Short clips',
        'clicks'        => 1800,
        'page_visits'   => 4200,
        'link_clicks'   => 980,
        'cpm'           => 3.40,
        'est_revenue'   => 150,
        'age_top'       => '25–34',
        'gender'        => '68% M',
        'top_country'   => 'USA',
        'sentiment'     => 74,
        'comments'      => 340,
        'shares'        => 820,
        'saves'         => 120,
        'posts_month'   => 22,
        'scheduled'     => 5,
        'status'        => 'connected',
    ],
    
    'pinterest' => [
        'name'          => 'Pinterest',
        'color'         => '#E60023',
        'bg'            => '#FDE8EB',
        'initial'       => 'pi',
        'followers'     => 15600,
        'follower_delta'=> 2.9,
        'reach'         => 68000,
        'reach_delta'   => 6.4,
        'impressions'   => 184000,
        'imp_delta'     => 5.2,
        'eng_rate'      => 2.1,
        'eng_delta'     => 0.2,
        'video_views'   => 14000,
        'avg_watch'     => '0:16',
        'completion'    => 31,
        'top_time'      => 'Sat 8pm',
        'best_format'   => 'Idea Pins',
        'clicks'        => 4200,
        'page_visits'   => 0,
        'link_clicks'   => 3800,
        'cpm'           => 2.80,
        'est_revenue'   => 39,
        'age_top'       => '25–44',
        'gender'        => '76% F',
        'top_country'   => 'USA',
        'sentiment'     => 89,
        'comments'      => 88,
        'shares'        => 0,
        'saves'         => 8400,
        'posts_month'   => 16,
        'scheduled'     => 4,
        'status'        => 'connected',
    ],
];

// ── Overlay live Facebook data onto the dummy defaults ──────────────────────
// Anything fb_analytics returns as null is left as the dummy fallback. The
// live_fields list tells the UI which metrics are real so it can show a badge.
$fb_live_fields = [];
$fb_meta        = ['fetched_at' => null, 'category' => null, 'page_source' => null];
try {
    $fb = fb_get_dashboard_data($dash_admin_id, $dash_conn);
} catch (Throwable $e) {
    $fb = null;
}
if (is_array($fb)) {
    $fb_live_fields = $fb['live_fields'] ?? [];
    $fb_meta        = [
        'fetched_at'  => $fb['fetched_at']  ?? null,
        'category'    => $fb['category']    ?? null,
        'page_source' => $fb['page_source'] ?? null,
    ];
    if (!empty($fb['name']))   $platforms['facebook']['name']        = $fb['name'];
    foreach ([
        'followers'   => 'followers',
        'reach'       => 'reach',
        'impressions' => 'impressions',
        'video_views' => 'video_views',
        'page_visits' => 'page_visits',
        'comments'    => 'comments',
        'shares'      => 'shares',
        'eng_rate'    => 'eng_rate',
        'clicks'      => 'clicks',
        'link_clicks' => 'link_clicks',
        'avg_watch'   => 'avg_watch',
        'completion'  => 'completion',
        'posts_month' => 'posts_month',
        'scheduled'   => 'scheduled',
        'best_format' => 'best_format',
        'top_time'    => 'top_time',
        'top_country' => 'top_country',
        'age_top'     => 'age_top',
        'gender'      => 'gender',
    ] as $src => $dst) {
        if (isset($fb[$src]) && $fb[$src] !== null && $fb[$src] !== '') {
            $platforms['facebook'][$dst] = $fb[$src];
        }
    }
}

// ── Overlay live Instagram data onto the dummy defaults ─────────────────────
$ig_live_fields = [];
$ig_meta        = ['fetched_at' => null, 'username' => null];
try {
    $ig = ig_get_dashboard_data($dash_admin_id, $dash_conn);
} catch (Throwable $e) {
    $ig = null;
}
if (is_array($ig)) {
    $ig_live_fields = $ig['live_fields'] ?? [];
    $ig_meta        = [
        'fetched_at' => $ig['fetched_at']   ?? null,
        'username'   => $ig['ig_username']  ?? null,
    ];
    foreach ([
        'followers', 'reach', 'impressions', 'video_views', 'page_visits',
        'comments', 'shares', 'saves', 'eng_rate', 'link_clicks',
        'avg_watch', 'completion', 'posts_month', 'scheduled',
        'best_format', 'top_time', 'top_country', 'age_top', 'gender',
    ] as $field) {
        if (isset($ig[$field]) && $ig[$field] !== null && $ig[$field] !== '') {
            $platforms['instagram'][$field] = $ig[$field];
        }
    }
}

// ── Overlay live LinkedIn data onto the dummy defaults ──────────────────────
$li_live_fields = [];
$li_meta        = ['fetched_at' => null, 'name' => null];
try {
    $li = li_get_dashboard_data($dash_admin_id, $dash_conn);
} catch (Throwable $e) {
    $li = null;
}
if (is_array($li)) {
    $li_live_fields = $li['live_fields'] ?? [];
    $li_meta        = [
        'fetched_at' => $li['fetched_at'] ?? null,
        'name'       => $li['li_name']    ?? null,
    ];
    foreach ([
        'followers', 'reach', 'impressions', 'video_views', 'page_visits',
        'comments', 'shares', 'saves', 'eng_rate', 'link_clicks',
        'avg_watch', 'completion', 'posts_month', 'scheduled',
        'best_format', 'top_time', 'top_country', 'age_top', 'gender',
    ] as $field) {
        if (isset($li[$field]) && $li[$field] !== null && $li[$field] !== '') {
            $platforms['linkedin'][$field] = $li[$field];
        }
    }
}

$third_party = [
    ['name'=>'Buffer',        'url'=>'https://buffer.com/developers/api',           'use'=>'Schedule & auto-post to all 7 platforms while you build your own scheduler',    'badge'=>'Scheduling',  'color'=>'#2C4BFF'],
    ['name'=>'Hootsuite',     'url'=>'https://developer.hootsuite.com',              'use'=>'Bulk scheduling, team workflows, approval queues',                               'badge'=>'Scheduling',  'color'=>'#1F2732'],
    ['name'=>'Later',         'url'=>'https://later.com/api',                       'use'=>'Visual calendar, Instagram-first scheduling with link-in-bio',                  'badge'=>'Scheduling',  'color'=>'#5A6BE8'],
    ['name'=>'Publer',        'url'=>'https://publer.io/api',                       'use'=>'Cross-post scheduler with recycling & RSS',                                     'badge'=>'Scheduling',  'color'=>'#FF6C2F'],
    ['name'=>'Sprout Social', 'url'=>'https://developers.sproutsocial.com',         'use'=>'Enterprise scheduling + deep analytics API',                                    'badge'=>'Analytics',   'color'=>'#59CB59'],
    ['name'=>'SocialBee',     'url'=>'https://socialbee.com/api',                   'use'=>'Category-based scheduling + evergreen content recycling',                       'badge'=>'Scheduling',  'color'=>'#FFCF00'],
    ['name'=>'Ayrshare',      'url'=>'https://www.ayrshare.com',                    'use'=>'Single REST API for all 7 platforms — ideal for SaaS builders like you',        'badge'=>'Best for you','color'=>'#7C3AED'],
    ['name'=>'Metricool',     'url'=>'https://metricool.com/api',                   'use'=>'Analytics + scheduling, competitor analysis',                                   'badge'=>'Analytics',   'color'=>'#00C9A7'],
    ['name'=>'Brandwatch',    'url'=>'https://www.brandwatch.com/api',              'use'=>'Social listening, sentiment analysis, audience insights',                       'badge'=>'Listening',   'color'=>'#F94144'],
    ['name'=>'Mention',       'url'=>'https://mention.com/en/api',                  'use'=>'Brand mentions, keyword tracking across web + social',                          'badge'=>'Listening',   'color'=>'#EF476F'],
    ['name'=>'Google Trends', 'url'=>'https://trends.google.com/trends/api',        'use'=>'Topic trending data — tell clients what to post before it peaks',               'badge'=>'Trends',      'color'=>'#4285F4'],
    ['name'=>'Spotify API',   'url'=>'https://developer.spotify.com/documentation', 'use'=>'Trending tracks for Reels/TikToks — suggest audio to clients',                 'badge'=>'Trends',      'color'=>'#1DB954'],
    ['name'=>'OpenAI / Claude','url'=>'https://docs.anthropic.com',                 'use'=>'Caption generation, script writing, hashtag suggestions, virality scoring',    'badge'=>'AI',          'color'=>'#7C5CFC'],
    ['name'=>'Canva API',     'url'=>'https://www.canva.com/developers',            'use'=>'Auto-generate thumbnails and video covers inside your platform',                'badge'=>'Creative',    'color'=>'#00C4CC'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>VideoVizard — Platform Analytics</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
:root{
    --dark-blue:#0f2a44; --mid-blue:#143b63; --accent:#5fd1ff;
    --green:#10b981; --purple:#8b5cf6;
    --text:#1e293b; --muted:#64748b; --border:#e2e8f0;
    --bg:#f0f4f8; --card:#ffffff; --bg2:#e8edf3;
    --hover:#f0f9ff;
    --ok:#059669; --warn:#d97706; --err:#dc2626;
    --sky:#f0f9ff; --sky2:#e0f2fe; --navy:#0f2a44;
    --white:#ffffff; --surface:#f0f4f8;
    --blue:#0284c7; --blue2:#0f2a44;
    --font:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit}

/* ── Header (matches vizard_browser) ── */
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
.topbar-right{display:flex;gap:10px;align-items:center}
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--font);display:inline-flex;align-items:center;gap:6px}
.btn-outline{background:var(--white);border:1.5px solid var(--border);color:var(--dark-blue)}
.btn-outline:hover{border-color:var(--dark-blue);color:var(--dark-blue)}
.btn-primary{background:linear-gradient(135deg,#10b981,#059669);color:#fff;box-shadow:0 4px 12px rgba(16,185,129,.3);}
.btn-primary:hover{opacity:.9}

/* section label */
.section-label{font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:14px}

/* page heading with company name */
.page-title{font-size:22px;font-weight:700;color:var(--dark-blue);margin-bottom:4px;letter-spacing:-0.3px;}
.page-title-sub{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:20px;}

/* summary stats */
.summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.sum-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.sum-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
.sum-val{font-size:26px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.5px;line-height:1}
.sum-delta{font-size:12px;margin-top:6px;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-weight:500}
.up{background:#d1fae5;color:#065f46}
.down{background:#fee2e2;color:#991b1b}
.neu{background:var(--bg2);color:var(--muted)}

/* platform cards */
.platforms-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:32px}
.pcard{background:var(--white);border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.pcard-header{display:flex;align-items:center;gap:12px;padding:16px 20px 14px;border-bottom:1px solid var(--border)}
.picon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;letter-spacing:-0.5px;flex-shrink:0}
.pname{font-size:15px;font-weight:700;color:var(--dark-blue)}
.pstatus{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#d1fae5;color:#065f46;margin-left:auto}
.pstatus.live{background:#065f46;color:#fff;display:inline-flex;align-items:center;gap:5px}
.pstatus.live::before{content:"";width:6px;height:6px;border-radius:50%;background:#6ee7b7;box-shadow:0 0 0 0 rgba(110,231,183,.7);animation:livepulse 1.6s infinite}
@keyframes livepulse{0%{box-shadow:0 0 0 0 rgba(110,231,183,.7)}70%{box-shadow:0 0 0 6px rgba(110,231,183,0)}100%{box-shadow:0 0 0 0 rgba(110,231,183,0)}}
.live-banner{font-size:11px;color:var(--muted);padding:0 20px 8px;display:flex;align-items:center;gap:6px}
.live-banner strong{color:#065f46;font-weight:700}

/* metrics grid inside card */
.pcard-body{padding:14px 20px 16px}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.metric{background:var(--bg);border-radius:8px;padding:10px 12px;border:1px solid var(--border);}
.metric-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;font-weight:600}
.metric-val{font-size:17px;font-weight:700;color:var(--dark-blue);letter-spacing:-0.3px}
.metric-sub{font-size:11px;color:var(--muted);margin-top:2px}
.metric-delta{font-size:11px;font-weight:500}
.metric-delta.up{color:var(--green)}
.metric-delta.down{color:var(--err)}

/* divider row */
.pcard-divider{height:1px;background:var(--border);margin:0 0 14px}

/* detail rows */
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.detail-item{background:var(--bg);border-radius:7px;padding:9px 11px;border:1px solid var(--border);}
.detail-key{font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:3px}
.detail-val{font-size:13px;font-weight:600;color:var(--dark-blue)}

/* sentiment bar */
.sentiment-row{display:flex;align-items:center;gap:10px;margin-top:4px}
.sent-bar{flex:1;height:6px;background:var(--border);border-radius:99px;overflow:hidden}
.sent-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#10b981,#34d399)}
.sent-val{font-size:12px;font-weight:700;color:var(--green);min-width:32px;text-align:right}

/* best time badge */
.badge-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.badge{font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;background:var(--bg2);color:var(--muted)}
.badge.highlight{background:var(--dark-blue);color:#fff}

/* footer action row */
.pcard-footer{border-top:1px solid var(--border);padding:10px 20px;display:flex;align-items:center;gap:8px;background:var(--bg)}
.pcard-footer span{font-size:12px;color:var(--muted)}
.pcard-footer strong{color:var(--dark-blue);font-weight:600}

/* 3rd party section */
.apis-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:32px}
.api-card{background:var(--white);border:1.5px solid var(--border);border-radius:12px;padding:16px 18px;transition:border-color .15s;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.api-card:hover{border-color:var(--dark-blue)}
.api-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.api-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.api-name{font-size:14px;font-weight:700;color:var(--dark-blue)}
.api-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:auto;letter-spacing:0.5px}
.badge-sched{background:#ede9fe;color:#6d28d9}
.badge-anal{background:#d1fae5;color:#065f46}
.badge-list{background:#ffedd5;color:#c2410c}
.badge-trend{background:#fae8ff;color:#7e22ce}
.badge-ai{background:#f5f3ff;color:#5b21b6}
.badge-best{background:var(--dark-blue);color:#fff}
.badge-create{background:#d1fae5;color:#065f46}
.api-use{font-size:12.5px;color:var(--muted);line-height:1.5}

/* responsive */
@media(max-width:900px){
    .platforms-grid{grid-template-columns:1fr}
    .summary-row{grid-template-columns:repeat(2,1fr)}
    .apis-grid{grid-template-columns:repeat(2,1fr)}
    .metrics-row{grid-template-columns:repeat(2,1fr)}
    .detail-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:560px){
    .summary-row{grid-template-columns:1fr}
    .apis-grid{grid-template-columns:1fr}
}

</style>
</head>
<body>

<!-- Header (matches vizard_browser) -->
<div class="page-header">
    <div class="logo"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></div>
    <nav class="nav-links">
        <a href="vizard_browser.php" class="nav-link">🏠 Home</a>
        <a href="vizard_scheduler.php" class="nav-link">📡 Scheduler</a>
        <a href="vizard_dashboard.php" class="nav-link active">📊 Analytics</a>
        <a href="vizard_media_insights.php" class="nav-link">💡 Media Insights</a>
    </nav>
</div>

<div class="wrap">

<!-- Summary strip -->
<div class="page-title"><?= htmlspecialchars($dash_company_name, ENT_QUOTES, 'UTF-8') ?></div>
<div class="page-title-sub">Total across all platforms (last 30 days)</div>
<div class="summary-row">
    <div class="sum-card">
        <div class="sum-label">Total followers</div>
        <div class="sum-val"><?= fmt(array_sum(array_column($platforms,'followers'))) ?></div>
        <span class="sum-delta up">&#8593; avg 4.6% growth</span>
    </div>
    <div class="sum-card">
        <div class="sum-label">Total video views</div>
        <div class="sum-val"><?= fmt(array_sum(array_column($platforms,'video_views'))) ?></div>
        <span class="sum-delta up">&#8593; 28.4% vs last month</span>
    </div>
    <div class="sum-card">
        <div class="sum-label">Total reach</div>
        <div class="sum-val"><?= fmt(array_sum(array_column($platforms,'reach'))) ?></div>
        <span class="sum-delta up">&#8593; 19.1%</span>
    </div>
    <div class="sum-card">
        <div class="sum-label">Est. total revenue</div>
        <?php
        // Facebook revenue is excluded — no Graph API field for it (Marketing API only).
        $rev_platforms = $platforms;
        unset($rev_platforms['facebook']);
        ?>
        <div class="sum-val">$<?= fmt(array_sum(array_column($rev_platforms,'est_revenue'))) ?></div>
        <span class="sum-delta neu">CPM-based estimate (excl. Facebook)</span>
    </div>
</div>

<!-- Platform cards -->
<div class="page-title"><?= htmlspecialchars($dash_company_name, ENT_QUOTES, 'UTF-8') ?></div>
<div class="page-title-sub">Per-platform breakdown</div>
<div class="platforms-grid">
<?php
// Metrics that the Facebook Graph API simply does not expose, so we hide
// their cards rather than show stale dummy values:
//   cpm / est_revenue → Marketing API only (needs ads_read + ad account)
//   sentiment         → needs NLP on comment text
//   saves             → Instagram-only metric, no equivalent on FB Pages
//   *_delta           → require a stored historical baseline we don't keep yet
$fb_unavailable = [
    'follower_delta', 'reach_delta', 'imp_delta', 'eng_delta',
    'saves', 'sentiment', 'cpm', 'est_revenue',
];
$ig_unavailable = [
    'follower_delta', 'reach_delta', 'imp_delta', 'eng_delta',
    'sentiment', 'cpm', 'est_revenue',
];
// LinkedIn Marketing API (restricted tier) exposes reach/impressions/video
// analytics. Standard OAuth only gives connections count + local DB counts.
$li_unavailable = [
    'follower_delta', 'reach_delta', 'imp_delta', 'eng_delta',
    'saves', 'sentiment', 'cpm', 'est_revenue',
];
foreach ($platforms as $key => $p):
    $is_fb   = ($key === 'facebook');
    $is_ig   = ($key === 'instagram');
    $is_li   = ($key === 'linkedin');
    $is_live = ($is_fb && !empty($fb_live_fields))
            || ($is_ig && !empty($ig_live_fields))
            || ($is_li && !empty($li_live_fields));
    if ($is_fb)     $hide = array_flip($fb_unavailable);
    elseif ($is_ig) $hide = array_flip($ig_unavailable);
    elseif ($is_li) $hide = array_flip($li_unavailable);
    else            $hide = [];
?>
<div class="pcard">
    <!-- Header -->
    <div class="pcard-header">
        <div class="picon" style="background:<?= $p['color'] ?>"><?= strtoupper($p['initial']) ?></div>
        <div>
            <div class="pname"><?= htmlspecialchars($p['name']) ?></div>
            <div style="font-size:12px;color:var(--muted)"><?= fmt($p['followers']) ?> followers</div>
        </div>
        <?php if ($is_live): ?>
            <?php
                $apiLabel = $is_fb ? 'Meta Graph API'
                          : ($is_ig ? 'Instagram Graph API' : 'LinkedIn API');
            ?>
            <span class="pstatus live" title="Pulled from <?= $apiLabel ?>">LIVE</span>
        <?php else: ?>
            <span class="pstatus">&#10003; Connected</span>
        <?php endif; ?>
    </div>
    <?php if ($is_live && $is_fb): ?>
        <div class="live-banner">
            <strong>&bull; Live from Meta Graph API</strong>
            <span>&middot; updated <?= htmlspecialchars(date('g:i a', strtotime($fb_meta['fetched_at'] ?? 'now'))) ?></span>
            <span style="margin-left:auto">Real: <?= htmlspecialchars(implode(', ', $fb_live_fields)) ?></span>
        </div>
    <?php elseif ($is_live && $is_ig): ?>
        <div class="live-banner">
            <strong>&bull; Live from Instagram Graph API</strong>
            <span>&middot; @<?= htmlspecialchars($ig_meta['username'] ?? '') ?></span>
            <span>&middot; updated <?= htmlspecialchars(date('g:i a', strtotime($ig_meta['fetched_at'] ?? 'now'))) ?></span>
            <span style="margin-left:auto">Real: <?= htmlspecialchars(implode(', ', $ig_live_fields)) ?></span>
        </div>
    <?php elseif ($is_live && $is_li): ?>
        <div class="live-banner">
            <strong>&bull; Live from LinkedIn API</strong>
            <span>&middot; <?= htmlspecialchars($li_meta['name'] ?? '') ?></span>
            <span>&middot; updated <?= htmlspecialchars(date('g:i a', strtotime($li_meta['fetched_at'] ?? 'now'))) ?></span>
            <span style="margin-left:auto">Real: <?= htmlspecialchars(implode(', ', $li_live_fields)) ?></span>
        </div>
    <?php endif; ?>

    <div class="pcard-body">
        <!-- Core metrics -->
        <div class="metrics-row">
            <div class="metric">
                <div class="metric-label">Followers</div>
                <div class="metric-val"><?= fmt($p['followers']) ?></div>
                <?php if (!isset($hide['follower_delta'])): ?>
                    <div class="metric-delta up">&#8593; <?= $p['follower_delta'] ?>% month</div>
                <?php endif; ?>
            </div>
            <div class="metric">
                <div class="metric-label">Reach</div>
                <div class="metric-val"><?= fmt($p['reach']) ?></div>
                <?php if (!isset($hide['reach_delta'])): ?>
                    <div class="metric-delta up">&#8593; <?= $p['reach_delta'] ?>%</div>
                <?php endif; ?>
            </div>
            <div class="metric">
                <div class="metric-label">Impressions</div>
                <div class="metric-val"><?= fmt($p['impressions']) ?></div>
                <?php if (!isset($hide['imp_delta'])): ?>
                    <div class="metric-delta up">&#8593; <?= $p['imp_delta'] ?>%</div>
                <?php endif; ?>
            </div>
            <div class="metric">
                <div class="metric-label">Eng. rate</div>
                <div class="metric-val"><?= $p['eng_rate'] ?>%</div>
                <?php if (!isset($hide['eng_delta'])): ?>
                    <div class="metric-delta <?= $p['eng_delta'] >= 0 ? 'up' : 'down' ?>"><?= $p['eng_delta'] >= 0 ? '&#8593;' : '&#8595;' ?> <?= abs($p['eng_delta']) ?>%</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="pcard-divider"></div>

        <!-- Video stats -->
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Video performance</div>
        <div class="metrics-row">
            <div class="metric">
                <div class="metric-label">Video views</div>
                <div class="metric-val"><?= fmt($p['video_views']) ?></div>
            </div>
            <div class="metric">
                <div class="metric-label">Avg watch</div>
                <div class="metric-val"><?= $p['avg_watch'] ?></div>
            </div>
            <div class="metric">
                <div class="metric-label">Completion</div>
                <div class="metric-val"><?= $p['completion'] ?>%</div>
            </div>
            <div class="metric">
                <div class="metric-label">Link clicks</div>
                <div class="metric-val"><?= fmt($p['link_clicks']) ?></div>
            </div>
        </div>

        <div class="pcard-divider"></div>

        <!-- Engagement breakdown -->
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Engagement breakdown</div>
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-key">Comments</div>
                <div class="detail-val"><?= fmt($p['comments']) ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-key">Shares</div>
                <div class="detail-val"><?= $p['shares'] > 0 ? fmt($p['shares']) : '—' ?></div>
            </div>
            <?php if (!isset($hide['saves'])): ?>
            <div class="detail-item">
                <div class="detail-key">Saves</div>
                <div class="detail-val"><?= fmt($p['saves']) ?></div>
            </div>
            <?php endif; ?>
            <div class="detail-item">
                <div class="detail-key">Page visits</div>
                <div class="detail-val"><?= $p['page_visits'] > 0 ? fmt($p['page_visits']) : '—' ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-key">Posts / month</div>
                <div class="detail-val"><?= $p['posts_month'] ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-key">Scheduled</div>
                <div class="detail-val"><?= $p['scheduled'] ?> posts</div>
            </div>
        </div>

        <div class="pcard-divider"></div>

        <!-- Audience -->
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Audience</div>
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-key">Top age</div>
                <div class="detail-val"><?= $p['age_top'] ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-key">Gender split</div>
                <div class="detail-val"><?= $p['gender'] ?></div>
            </div>
            <div class="detail-item">
                <div class="detail-key">Top country</div>
                <div class="detail-val"><?= $p['top_country'] ?></div>
            </div>
        </div>

        <?php if (!isset($hide['sentiment'])): ?>
        <!-- Sentiment -->
        <div style="margin-top:12px">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Audience sentiment</div>
            <div class="sentiment-row">
                <div class="sent-bar"><div class="sent-fill" style="width:<?= $p['sentiment'] ?>%"></div></div>
                <div class="sent-val"><?= $p['sentiment'] ?>%</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Badges -->
        <div class="badge-row">
            <span class="badge highlight">Best time: <?= $p['top_time'] ?></span>
            <span class="badge">Best format: <?= $p['best_format'] ?></span>
            <?php if (!isset($hide['cpm'])): ?>
                <span class="badge">Est. CPM: $<?= number_format($p['cpm'],2) ?></span>
            <?php endif; ?>
            <?php if (!isset($hide['est_revenue'])): ?>
                <span class="badge">Est. revenue: $<?= fmt($p['est_revenue']) ?>/mo</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!isset($hide['est_revenue']) && !isset($hide['cpm'])): ?>
    <div class="pcard-footer">
        <span>Est. monthly revenue from this platform:</span>
        <strong>$<?= number_format($p['est_revenue']) ?></strong>
        <span style="margin-left:auto">CPM $<?= number_format($p['cpm'],2) ?> &middot; <?= fmt($p['video_views']) ?> views</span>
    </div>
    <?php elseif ($is_fb): ?>
    <div class="pcard-footer">
        <span>Total video views (last 30 days):</span>
        <strong><?= fmt($p['video_views']) ?></strong>
    </div>
    <?php elseif ($is_ig && !empty($ig_live_fields)): ?>
    <div class="pcard-footer">
        <span>Connected as</span>
        <strong>@<?= htmlspecialchars($ig_meta['username'] ?? '') ?></strong>
        <span style="margin-left:auto"><?= fmt($p['posts_month']) ?> posts &middot; <?= fmt($p['comments']) ?> comments (last 30d)</span>
    </div>
    <?php elseif ($is_li && !empty($li_live_fields)): ?>
    <div class="pcard-footer">
        <span>Connected as</span>
        <strong><?= htmlspecialchars($li_meta['name'] ?? '') ?></strong>
        <?php if (!empty($p['followers'])): ?>
            <span style="margin-left:auto"><?= fmt($p['followers']) ?> connections</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- 3rd Party APIs -->
<div class="section-label">Third-party APIs to use while you build your own scheduler</div>
<div class="apis-grid">
<?php
$badge_map = [
    'Scheduling'  => 'badge-sched',
    'Analytics'   => 'badge-anal',
    'Listening'   => 'badge-list',
    'Trends'      => 'badge-trend',
    'AI'          => 'badge-ai',
    'Best for you'=> 'badge-best',
    'Creative'    => 'badge-create',
];
foreach ($third_party as $api):
    $bc = $badge_map[$api['badge']] ?? 'badge-sched';
?>
<div class="api-card">
    <div class="api-top">
        <div class="api-dot" style="background:<?= $api['color'] ?>"></div>
        <div class="api-name"><?= $api['name'] ?></div>
        <span class="api-badge <?= $bc ?>"><?= $api['badge'] ?></span>
    </div>
    <div class="api-use"><?= $api['use'] ?></div>
</div>
<?php endforeach; ?>
</div>

<div style="text-align:center;font-size:12px;color:var(--muted);padding:24px 0 8px">
    VideoVizard &mdash; Facebook, Instagram, and LinkedIn cards pull real data from their respective APIs. Other platforms still use demo values.
    <?php if (!empty($fb_live_fields)): ?>
        <br>FB live fields: <?= htmlspecialchars(implode(', ', $fb_live_fields)) ?>.
    <?php endif; ?>
    <?php if (!empty($ig_live_fields)): ?>
        <br>IG live fields: <?= htmlspecialchars(implode(', ', $ig_live_fields)) ?>.
    <?php endif; ?>
    <?php if (!empty($li_live_fields)): ?>
        <br>LI live fields: <?= htmlspecialchars(implode(', ', $li_live_fields)) ?>.
    <?php endif; ?>
</div>

</div>
</body>
</html>