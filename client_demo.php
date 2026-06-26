<?php
// ═══════════════════════════════════════════════════
// vizard_dashboard.php  —  Client Analytics Dashboard
// Mobile-first. Designed to impress & close contracts.
// Replace dummy arrays with live API data per platform.
// ═══════════════════════════════════════════════════

function fmt($n) {
    if ($n >= 1000000) return number_format($n/1000000,1).'M';
    if ($n >= 1000)    return number_format($n/1000,1).'K';
    return number_format($n);
}

$client_name  = "Acme Brand Co.";
$report_month = "April 2026";

// ── Platform data ──────────────────────────────────
$platforms = [
    'facebook' => [
        'name'         => 'Facebook',
        'icon'         => 'fab fa-facebook',
        'color'        => '#1877F2',
        'bg'           => 'rgba(24,119,242,0.10)',
        'followers'    => 24800,  'follower_delta' => 2.4,
        'reach'        => 184000, 'reach_delta'    => 5.1,
        'impressions'  => 412000,
        'eng_rate'     => 4.2,    'eng_delta'      => 0.8,
        'video_views'  => 98000,  'avg_watch'      => '0:42',
        'completion'   => 54,
        'top_time'     => 'Wed 7 pm',
        'best_format'  => 'Reels',
        'link_clicks'  => 1240,
        'comments'     => 620,    'shares' => 340,  'saves' => 210,
        'cpm'          => 4.80,   'est_revenue' => 470,
        'age_top'      => '25–34','gender' => '58% F','top_country' => 'Canada',
        'sentiment'    => 92,
        'posts_month'  => 18,
        'sparkline'    => [12,18,14,22,20,28,24,34,30,40,36,46,42,52,50,58,54,64,60,70,66,76,72,82,78,88,84,92,90,98],
        'insight'      => 'Reels posted Wed 7 pm get 2.1× more reach than your average post. We\'ve locked that slot for your best content.',
    ],
    'instagram' => [
        'name'         => 'Instagram',
        'icon'         => 'fab fa-instagram',
        'color'        => '#E1306C',
        'bg'           => 'rgba(225,48,108,0.10)',
        'followers'    => 61200,  'follower_delta' => 5.8,
        'reach'        => 520000, 'reach_delta'    => 14.2,
        'impressions'  => 980000,
        'eng_rate'     => 6.7,    'eng_delta'      => 1.2,
        'video_views'  => 310000, 'avg_watch'      => '0:28',
        'completion'   => 61,
        'top_time'     => 'Sat 6 pm',
        'best_format'  => 'Reels',
        'link_clicks'  => 4100,
        'comments'     => 1840,   'shares' => 920,  'saves' => 3400,
        'cpm'          => 6.40,   'est_revenue' => 1980,
        'age_top'      => '18–24','gender' => '64% F','top_country' => 'USA',
        'sentiment'    => 96,
        'posts_month'  => 24,
        'sparkline'    => [30,38,34,48,44,60,56,72,68,84,80,96,92,110,106,124,120,138,134,152,148,166,162,180,176,196,192,210,206,224],
        'insight'      => '3,400 saves this month — people are bookmarking your content to buy later. Saves are a leading purchase-intent signal.',
    ],
    'tiktok' => [
        'name'         => 'TikTok',
        'icon'         => 'fab fa-tiktok',
        'color'        => '#010101',
        'bg'           => 'rgba(0,0,0,0.06)',
        'followers'    => 112400, 'follower_delta' => 12.1,
        'reach'        => 2100000,'reach_delta'    => 38.4,
        'impressions'  => 4800000,
        'eng_rate'     => 9.8,    'eng_delta'      => 2.4,
        'video_views'  => 2100000,'avg_watch'      => '0:19',
        'completion'   => 72,
        'top_time'     => 'Fri 9 pm',
        'best_format'  => 'Short clips',
        'link_clicks'  => 8200,
        'comments'     => 8700,   'shares' => 44000,'saves' => 12000,
        'cpm'          => 2.10,   'est_revenue' => 4410,
        'age_top'      => '18–24','gender' => '52% F','top_country' => 'USA',
        'sentiment'    => 88,
        'posts_month'  => 30,
        'sparkline'    => [80,110,95,140,125,175,160,220,200,280,260,340,320,410,390,480,460,550,530,620,600,690,670,760,740,830,810,900,880,980],
        'insight'      => '44,000 shares is extraordinary — your content is going viral organically. Each share reaches an average of 340 new people at zero extra cost.',
    ],
    'youtube' => [
        'name'         => 'YouTube',
        'icon'         => 'fab fa-youtube',
        'color'        => '#FF0000',
        'bg'           => 'rgba(255,0,0,0.08)',
        'followers'    => 38900,  'follower_delta' => 3.2,
        'reach'        => 620000, 'reach_delta'    => 9.7,
        'impressions'  => 1200000,
        'eng_rate'     => 5.1,    'eng_delta'      => 0.6,
        'video_views'  => 620000, 'avg_watch'      => '4:12',
        'completion'   => 48,
        'top_time'     => 'Sun 2 pm',
        'best_format'  => 'Shorts',
        'link_clicks'  => 3800,
        'comments'     => 2100,   'shares' => 640,  'saves' => 4200,
        'cpm'          => 8.20,   'est_revenue' => 5084,
        'age_top'      => '25–34','gender' => '61% M','top_country' => 'India',
        'sentiment'    => 91,
        'posts_month'  => 8,
        'sparkline'    => [40,52,46,62,56,74,68,88,80,102,94,118,110,136,128,154,146,172,164,190,182,208,200,226,218,244,236,262,254,280],
        'insight'      => '4:12 average watch time is top 5% for your niche. YouTube rewards this with free algorithm distribution worth thousands in ad spend.',
    ],
    'x' => [
        'name'         => 'X (Twitter)',
        'icon'         => 'fab fa-x-twitter',
        'color'        => '#14171A',
        'bg'           => 'rgba(20,23,26,0.06)',
        'followers'    => 19300,  'follower_delta' => 1.1,
        'reach'        => 88000,  'reach_delta'    => 2.8,
        'impressions'  => 240000,
        'eng_rate'     => 2.9,    'eng_delta'      => -0.3,
        'video_views'  => 44000,  'avg_watch'      => '0:22',
        'completion'   => 38,
        'top_time'     => 'Mon 8 am',
        'best_format'  => 'Short clips',
        'link_clicks'  => 980,
        'comments'     => 340,    'shares' => 820,  'saves' => 120,
        'cpm'          => 3.40,   'est_revenue' => 150,
        'age_top'      => '25–34','gender' => '68% M','top_country' => 'USA',
        'sentiment'    => 74,
        'posts_month'  => 22,
        'sparkline'    => [20,22,21,24,23,26,25,28,27,30,29,32,31,34,33,36,35,38,37,40,39,42,41,44,43,46,45,48,47,50],
        'insight'      => 'X drives strong professional conversations. Your Monday morning posts spark the most replies — ideal for thought-leadership positioning.',
    ],
    'linkedin' => [
        'name'         => 'LinkedIn',
        'icon'         => 'fab fa-linkedin',
        'color'        => '#0A66C2',
        'bg'           => 'rgba(10,102,194,0.08)',
        'followers'    => 8700,   'follower_delta' => 4.7,
        'reach'        => 42000,  'reach_delta'    => 11.2,
        'impressions'  => 98000,
        'eng_rate'     => 3.8,    'eng_delta'      => 0.5,
        'video_views'  => 22000,  'avg_watch'      => '1:14',
        'completion'   => 44,
        'top_time'     => 'Tue 9 am',
        'best_format'  => 'Document posts',
        'link_clicks'  => 1860,
        'comments'     => 280,    'shares' => 190,  'saves' => 640,
        'cpm'          => 14.20,  'est_revenue' => 312,
        'age_top'      => '30–44','gender' => '54% M','top_country' => 'Canada',
        'sentiment'    => 94,
        'posts_month'  => 12,
        'sparkline'    => [8,10,9,12,11,14,13,16,15,18,17,20,19,22,21,24,23,26,25,28,27,30,29,32,31,34,33,36,35,38],
        'insight'      => 'LinkedIn CPM of $14.20 means your audience has serious buying power. Decision-makers are watching — this is your best B2B channel.',
    ],
];

// ── Aggregate totals ───────────────────────────────
$total_followers = array_sum(array_column($platforms,'followers'));
$total_views     = array_sum(array_column($platforms,'video_views'));
$total_reach     = array_sum(array_column($platforms,'reach'));
$total_revenue   = array_sum(array_column($platforms,'est_revenue'));
$avg_sentiment   = round(array_sum(array_column($platforms,'sentiment')) / count($platforms));
$total_posts     = array_sum(array_column($platforms,'posts_month'));

// ── 30-day growth chart data (combined reach) ──────
$growth_labels = [];
$growth_data   = [];
for ($i = 29; $i >= 0; $i--) {
    $growth_labels[] = date('M j', strtotime("-$i days"));
    $base = 180000;
    $growth_data[] = round($base + ($base * 0.6 * (29-$i)/29) + rand(-8000, 12000));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>VideoVizard · Analytics for <?= htmlspecialchars($client_name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ══ TOKENS ══════════════════════════════════════ */
:root {
  --sky-50:  #f0f9ff;
  --sky-100: #e0f2fe;
  --sky-200: #bae6fd;
  --sky-400: #38bdf8;
  --sky-500: #0ea5e9;
  --sky-600: #0284c7;
  --sky-700: #0369a1;
  --sky-900: #0c4a6e;
  --navy:    #062236;
  --emerald: #059669;
  --amber:   #f59e0b;
  --red:     #ef4444;
  --white:   #ffffff;
  --border:  rgba(2,132,199,0.13);
  --card-shadow: 0 4px 24px rgba(2,132,199,0.10);
  --radius: 18px;
}

/* ══ RESET ═══════════════════════════════════════ */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html { scroll-behavior:smooth; }
body {
  font-family:'Instrument Sans',sans-serif;
  background: var(--sky-50);
  color: var(--sky-900);
  font-size: 15px;
  line-height: 1.6;
  overflow-x: hidden;
}
h1,h2,h3,h4,h5 { font-family:'Bricolage Grotesque',sans-serif; line-height:1.2; }

/* ══ NOISE TEXTURE ═══════════════════════════════ */
body::before {
  content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.025'/%3E%3C/svg%3E");
  opacity:.5;
}

/* ══ LAYOUT ══════════════════════════════════════ */
.page { position:relative; z-index:1; max-width:480px; margin:0 auto; padding:0 0 60px; }

/* ══ HERO HEADER ═════════════════════════════════ */
.hero-header {
  background: linear-gradient(160deg, var(--sky-900) 0%, var(--navy) 100%);
  padding: 48px 20px 80px;
  position: relative;
  overflow: hidden;
}
.hero-header::before {
  content:'';
  position:absolute; width:400px; height:400px; border-radius:50%;
  background:rgba(56,189,248,0.12); filter:blur(60px);
  top:-100px; right:-80px; pointer-events:none;
}
.hero-header::after {
  content:'';
  position:absolute; width:300px; height:300px; border-radius:50%;
  background:rgba(5,150,105,0.10); filter:blur(50px);
  bottom:-80px; left:-60px; pointer-events:none;
}
.brand {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:20px; font-weight:800; letter-spacing:-0.3px;
  color:#fff; margin-bottom:32px;
}
.brand em { color:var(--sky-400); font-style:normal; }
.hero-label {
  display:inline-flex; align-items:center; gap:6px;
  background:rgba(255,255,255,0.10); border:1px solid rgba(255,255,255,0.18);
  color:rgba(255,255,255,0.80); padding:5px 14px; border-radius:40px;
  font-size:12px; font-weight:600; letter-spacing:.03em; margin-bottom:16px;
}
.dot-live { width:6px; height:6px; background:#4ade80; border-radius:50%; animation:blink 2s infinite; }
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.hero-client { font-size:28px; font-weight:800; color:#fff; letter-spacing:-0.5px; margin-bottom:6px; }
.hero-sub { font-size:14px; color:rgba(255,255,255,0.60); }

/* ══ SUMMARY STRIP (floated cards) ══════════════ */
.summary-strip {
  display:grid; grid-template-columns:1fr 1fr;
  gap:10px; padding:0 14px;
  margin-top:-46px; margin-bottom:20px;
  position:relative; z-index:10;
}
.sum-card {
  background:#fff;
  border:1px solid var(--border);
  border-radius:16px;
  padding:16px 14px;
  box-shadow: var(--card-shadow);
  animation: popUp .5s cubic-bezier(.22,.68,0,1.2) both;
}
.sum-card:nth-child(1){animation-delay:.05s}
.sum-card:nth-child(2){animation-delay:.10s}
.sum-card:nth-child(3){animation-delay:.15s}
.sum-card:nth-child(4){animation-delay:.20s}
@keyframes popUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.sum-icon { font-size:20px; margin-bottom:6px; }
.sum-val {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:22px; font-weight:800; letter-spacing:-0.5px;
  background:linear-gradient(135deg,var(--sky-500),var(--sky-700));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.sum-label { font-size:11px; color:#64748b; font-weight:500; margin-top:2px; }
.sum-delta {
  font-size:11px; font-weight:600; margin-top:4px;
  display:inline-flex; align-items:center; gap:3px;
}
.up   { color:var(--emerald); }
.down { color:var(--red); }

/* ══ SECTION HEADER ══════════════════════════════ */
.sec-head { padding:0 16px; margin-bottom:12px; }
.sec-eyebrow {
  font-size:10px; font-weight:700; color:var(--sky-600);
  text-transform:uppercase; letter-spacing:.12em;
  display:block; margin-bottom:2px;
}
.sec-title { font-size:20px; font-weight:800; color:var(--sky-900); }

/* ══ SECTION WRAPPER ═════════════════════════════ */
.section { margin-bottom:28px; }

/* ══ GROWTH CHART CARD ═══════════════════════════ */
.chart-card {
  margin:0 14px;
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:20px 16px;
  box-shadow:var(--card-shadow);
}
.chart-card-head {
  display:flex; justify-content:space-between; align-items:flex-start;
  margin-bottom:16px;
}
.chart-title { font-size:16px; font-weight:700; color:var(--sky-900); }
.chart-sub   { font-size:12px; color:#64748b; margin-top:2px; }
.chart-badge {
  background:rgba(5,150,105,.09); border:1px solid rgba(5,150,105,.20);
  color:var(--emerald); padding:4px 10px; border-radius:20px;
  font-size:11px; font-weight:700; white-space:nowrap;
}
.chart-wrap { position:relative; height:160px; }

/* ══ PLATFORM TABS ═══════════════════════════════ */
.plat-tabs-wrap {
  padding:0 14px; margin-bottom:16px; overflow-x:auto;
  -webkit-overflow-scrolling:touch; scrollbar-width:none;
}
.plat-tabs-wrap::-webkit-scrollbar { display:none; }
.plat-tabs {
  display:flex; gap:8px; width:max-content;
}
.plat-tab {
  display:flex; align-items:center; gap:7px;
  padding:9px 16px; border-radius:50px;
  border:1.5px solid var(--sky-200);
  background:#fff; cursor:pointer; transition:all .2s;
  font-family:'Instrument Sans',sans-serif;
  font-size:13px; font-weight:600; color:var(--sky-700);
  white-space:nowrap;
}
.plat-tab i { font-size:14px; }
.plat-tab.active {
  border-color:transparent; color:#fff;
}
.plat-tab:active { transform:scale(.97); }

/* ══ PLATFORM PANEL ══════════════════════════════ */
.plat-panel { display:none; padding:0 14px; }
.plat-panel.active { display:block; }

/* Platform hero row */
.plat-hero {
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 16px;
  margin-bottom:10px; box-shadow:var(--card-shadow);
}
.plat-hero-top {
  display:flex; align-items:center; gap:12px; margin-bottom:14px;
}
.plat-avatar {
  width:46px; height:46px; border-radius:14px;
  display:flex; align-items:center; justify-content:center;
  font-size:22px; color:#fff; flex-shrink:0;
}
.plat-name  { font-size:18px; font-weight:800; color:var(--sky-900); }
.plat-conn  {
  margin-left:auto; font-size:11px; font-weight:700;
  background:rgba(5,150,105,.09); border:1px solid rgba(5,150,105,.20);
  color:var(--emerald); padding:4px 10px; border-radius:20px;
}

/* Follower highlight */
.plat-follower {
  display:flex; align-items:baseline; gap:10px; margin-bottom:10px;
}
.plat-followers-num {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:36px; font-weight:800; letter-spacing:-1px;
}
.plat-follower-delta {
  font-size:13px; font-weight:600; padding:3px 10px;
  border-radius:20px; background:rgba(5,150,105,.10); color:var(--emerald);
}
.plat-follower-label { font-size:12px; color:#64748b; margin-top:-4px; }

/* Mini sparkline */
.spark-wrap { position:relative; height:56px; margin-top:8px; }

/* ══ STAT GRID (2-col) ═══════════════════════════ */
.stat-grid {
  display:grid; grid-template-columns:1fr 1fr;
  gap:8px; margin-bottom:10px;
}
.stat-cell {
  background:#fff; border:1px solid var(--border);
  border-radius:14px; padding:14px 12px;
  box-shadow:var(--card-shadow);
}
.stat-label { font-size:10px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
.stat-val   { font-size:20px; font-weight:800; font-family:'Bricolage Grotesque',sans-serif; color:var(--sky-900); letter-spacing:-0.4px; }
.stat-sub   { font-size:11px; color:#64748b; margin-top:2px; }
.stat-delta { font-size:11px; font-weight:600; margin-top:3px; }

/* ══ ENGAGEMENT ROW ══════════════════════════════ */
.eng-row {
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:16px;
  margin-bottom:10px; box-shadow:var(--card-shadow);
}
.eng-row-title { font-size:12px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.eng-items { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.eng-item  { text-align:center; }
.eng-icon  { font-size:18px; margin-bottom:3px; }
.eng-num   { font-size:16px; font-weight:800; font-family:'Bricolage Grotesque',sans-serif; color:var(--sky-900); }
.eng-lbl   { font-size:10px; color:#64748b; font-weight:500; }

/* ══ INSIGHT CARD ════════════════════════════════ */
.insight-card {
  border-radius:var(--radius); padding:16px;
  margin-bottom:10px;
  border:1px solid rgba(2,132,199,0.15);
  background:linear-gradient(135deg,rgba(240,249,255,0.9),rgba(224,242,254,0.7));
}
.insight-head { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.insight-head-icon { font-size:16px; }
.insight-head-label { font-size:11px; font-weight:700; color:var(--sky-600); text-transform:uppercase; letter-spacing:.08em; }
.insight-text { font-size:14px; color:var(--sky-900); line-height:1.6; font-weight:500; }

/* ══ AUDIENCE CARD ════════════════════════════════ */
.audience-card {
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:16px;
  margin-bottom:10px; box-shadow:var(--card-shadow);
}
.audience-title { font-size:12px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.aud-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.aud-label { font-size:13px; color:#64748b; }
.aud-val   { font-size:13px; font-weight:700; color:var(--sky-900); }

/* Gender bar */
.gender-bar { height:8px; border-radius:99px; overflow:hidden; background:var(--sky-100); margin-top:4px; position:relative; }
.gender-f   { position:absolute; left:0; top:0; height:100%; background:var(--ig-color,#E1306C); border-radius:99px; transition:width 1s ease; }

/* Sentiment */
.sent-row { display:flex; align-items:center; gap:10px; margin-top:8px; }
.sent-bar { flex:1; height:8px; background:var(--sky-100); border-radius:99px; overflow:hidden; }
.sent-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--emerald),#34d399); transition:width 1.2s ease; }
.sent-num  { font-size:13px; font-weight:700; color:var(--emerald); min-width:32px; text-align:right; }

/* ══ BEST TIME / FORMAT BADGES ═══════════════════ */
.badges-row { display:flex; gap:6px; flex-wrap:wrap; }
.badge-pill {
  display:inline-flex; align-items:center; gap:5px;
  font-size:12px; font-weight:600; padding:6px 12px;
  border-radius:40px; border:1.5px solid;
}
.badge-time { background:rgba(2,132,199,.08); border-color:rgba(2,132,199,.20); color:var(--sky-700); }
.badge-fmt  { background:rgba(5,150,105,.08); border-color:rgba(5,150,105,.20); color:var(--emerald); }
.badge-cpm  { background:rgba(245,158,11,.08); border-color:rgba(245,158,11,.20); color:#b45309; }

/* ══ VIDEO PERFORMANCE ════════════════════════════ */
.video-perf {
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:16px;
  margin-bottom:10px; box-shadow:var(--card-shadow);
}
.vp-title { font-size:12px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:14px; }
.vp-row   { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
.vp-bar-wrap { flex:1; }
.vp-bar-label { font-size:12px; color:#64748b; margin-bottom:4px; display:flex; justify-content:space-between; }
.vp-bar   { height:10px; background:var(--sky-100); border-radius:99px; overflow:hidden; }
.vp-fill  { height:100%; border-radius:99px; transition:width 1.2s ease; }
.vp-num   { font-size:15px; font-weight:800; color:var(--sky-900); min-width:40px; text-align:right; }

/* ══ CTA CARD ════════════════════════════════════ */
.cta-card {
  margin:0 14px 28px;
  background:linear-gradient(135deg,var(--sky-900),var(--navy));
  border-radius:24px; padding:28px 22px;
  text-align:center; position:relative; overflow:hidden;
}
.cta-card::before {
  content:''; position:absolute; width:280px; height:280px; border-radius:50%;
  background:rgba(56,189,248,.12); filter:blur(40px);
  top:-80px; right:-60px; pointer-events:none;
}
.cta-emoji { font-size:36px; margin-bottom:12px; }
.cta-title { font-size:22px; font-weight:800; color:#fff; letter-spacing:-0.4px; margin-bottom:8px; }
.cta-sub   { font-size:14px; color:rgba(255,255,255,.65); line-height:1.6; margin-bottom:22px; }
.cta-btn {
  display:inline-flex; align-items:center; gap:8px;
  background:var(--sky-400); color:var(--navy);
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:15px; font-weight:800;
  padding:14px 28px; border-radius:50px;
  text-decoration:none; transition:.2s;
  border:none; cursor:pointer; width:100%; justify-content:center;
  box-shadow:0 8px 24px rgba(56,189,248,.30);
}
.cta-btn:active { transform:scale(.97); }
.cta-trust { margin-top:14px; font-size:12px; color:rgba(255,255,255,.50); }

/* ══ REVENUE CARD ════════════════════════════════ */
.revenue-card {
  margin:0 14px 10px;
  background:linear-gradient(135deg,rgba(5,150,105,.06),rgba(5,150,105,.02));
  border:1.5px solid rgba(5,150,105,.18);
  border-radius:var(--radius); padding:20px 16px;
}
.rev-head { display:flex; align-items:center; gap:8px; margin-bottom:14px; }
.rev-icon { font-size:22px; }
.rev-title { font-size:14px; font-weight:700; color:var(--sky-900); }
.rev-total {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:38px; font-weight:800; color:var(--emerald);
  letter-spacing:-1px; margin-bottom:4px;
}
.rev-label { font-size:13px; color:#64748b; margin-bottom:16px; }
.rev-breakdown { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.rev-item { background:rgba(255,255,255,.7); border-radius:12px; padding:10px 12px; }
.rev-item-name  { font-size:11px; color:#64748b; font-weight:600; margin-bottom:3px; }
.rev-item-val   { font-size:15px; font-weight:800; color:var(--sky-900); }

/* ══ PROJECTION CARD ══════════════════════════════ */
.proj-card {
  margin:0 14px 28px;
  background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:20px 16px;
  box-shadow:var(--card-shadow);
}
.proj-title { font-size:16px; font-weight:700; color:var(--sky-900); margin-bottom:4px; }
.proj-sub   { font-size:12px; color:#64748b; margin-bottom:16px; }
.proj-row   { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--sky-100); }
.proj-row:last-child { margin-bottom:0; padding-bottom:0; border-bottom:none; }
.proj-when  { font-size:13px; color:#64748b; }
.proj-nums  { text-align:right; }
.proj-reach { font-size:15px; font-weight:800; color:var(--sky-700); }
.proj-fol   { font-size:11px; color:var(--emerald); font-weight:600; }

/* ══ FOOTER ══════════════════════════════════════ */
.footer { padding:12px 20px; text-align:center; font-size:11px; color:#94a3b8; }

/* ══ ANIMATIONS ══════════════════════════════════ */
.fade-in { animation:fadeIn .4s ease both; }
@keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

/* ══ RESPONSIVE GUARD ════════════════════════════ */
@media(min-width:480px) {
  .page { border-left:1px solid var(--border); border-right:1px solid var(--border); }
}
</style>
</head>
<body>
<div class="page">

  <!-- ══ HERO ════════════════════════════════════ -->
  <div class="hero-header">
    <div class="brand"><em>Video</em>Vizard</div>
    <div class="hero-label"><span class="dot-live"></span> Live Analytics · <?= $report_month ?></div>
    <div class="hero-client"><?= htmlspecialchars($client_name) ?></div>
    <div class="hero-sub">Your social media performance across 6 platforms</div>
  </div>

  <!-- ══ SUMMARY STRIP ══════════════════════════ -->
  <div class="summary-strip">
    <div class="sum-card">
      <div class="sum-icon">👥</div>
      <div class="sum-val" data-count="<?= $total_followers ?>">0</div>
      <div class="sum-label">Total Followers</div>
      <div class="sum-delta up">↑ avg 4.6% growth</div>
    </div>
    <div class="sum-card">
      <div class="sum-icon">▶️</div>
      <div class="sum-val" data-count="<?= $total_views ?>">0</div>
      <div class="sum-label">Video Views</div>
      <div class="sum-delta up">↑ 28.4% vs last month</div>
    </div>
    <div class="sum-card">
      <div class="sum-icon">📡</div>
      <div class="sum-val" data-count="<?= $total_reach ?>">0</div>
      <div class="sum-label">Total Reach</div>
      <div class="sum-delta up">↑ 19.1% growth</div>
    </div>
    <div class="sum-card">
      <div class="sum-icon">😊</div>
      <div class="sum-val"><?= $avg_sentiment ?>%</div>
      <div class="sum-label">Avg Sentiment</div>
      <div class="sum-delta up">↑ Highly positive</div>
    </div>
  </div>

  <!-- ══ REACH CHART ════════════════════════════ -->
  <div class="section">
    <div class="sec-head">
      <span class="sec-eyebrow">30-Day Trend</span>
      <div class="sec-title">Combined Reach</div>
    </div>
    <div class="chart-card">
      <div class="chart-card-head">
        <div>
          <div class="chart-title">Reach is climbing</div>
          <div class="chart-sub">All 6 platforms combined · last 30 days</div>
        </div>
        <div class="chart-badge">↑ 19.1%</div>
      </div>
      <div class="chart-wrap">
        <canvas id="reachChart"></canvas>
      </div>
    </div>
  </div>

  <!-- ══ REVENUE SUMMARY ════════════════════════ -->
  <div class="revenue-card">
    <div class="rev-head">
      <div class="rev-icon">💰</div>
      <div class="rev-title">Estimated Monthly Revenue Value</div>
    </div>
    <div class="rev-total">$<?= fmt($total_revenue) ?></div>
    <div class="rev-label">CPM-based estimate across all platforms · <?= $report_month ?></div>
    <div class="rev-breakdown">
      <?php foreach(array_slice($platforms,0,4,true) as $k=>$p): ?>
      <div class="rev-item">
        <div class="rev-item-name"><i class="<?= $p['icon'] ?>" style="color:<?= $p['color'] ?>"></i> <?= $p['name'] ?></div>
        <div class="rev-item-val">$<?= fmt($p['est_revenue']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ PER-PLATFORM SECTION ══════════════════ -->
  <div class="section">
    <div class="sec-head">
      <span class="sec-eyebrow">Per Platform</span>
      <div class="sec-title">Detailed Breakdown</div>
    </div>

    <!-- Platform tabs -->
    <div class="plat-tabs-wrap">
      <div class="plat-tabs" id="platTabs">
        <?php $first=true; foreach($platforms as $key=>$p): ?>
        <button class="plat-tab <?= $first?'active':'' ?>"
                data-panel="panel-<?= $key ?>"
                style="<?= $first?'background:'.$p['color'].';border-color:'.$p['color']:'' ?>"
                onclick="switchPlatform(this,'<?= $key ?>','<?= $p['color'] ?>')">
          <i class="<?= $p['icon'] ?>"></i><?= $p['name'] ?>
        </button>
        <?php $first=false; endforeach; ?>
      </div>
    </div>

    <!-- Platform panels -->
    <?php $first=true; foreach($platforms as $key=>$p): ?>
    <div class="plat-panel <?= $first?'active':'' ?> fade-in" id="panel-<?= $key ?>">

      <!-- Follower hero -->
      <div class="plat-hero">
        <div class="plat-hero-top">
          <div class="plat-avatar" style="background:<?= $p['color'] ?>">
            <i class="<?= $p['icon'] ?>"></i>
          </div>
          <div>
            <div class="plat-name"><?= $p['name'] ?></div>
          </div>
          <div class="plat-conn">✓ Connected</div>
        </div>
        <div class="plat-follower">
          <div class="plat-followers-num" style="color:<?= $p['color'] ?>"><?= fmt($p['followers']) ?></div>
          <div class="plat-follower-delta">↑ <?= $p['follower_delta'] ?>%</div>
        </div>
        <div class="plat-follower-label">Followers · growing <?= $p['follower_delta'] ?>% this month</div>
        <!-- Sparkline -->
        <div class="spark-wrap">
          <canvas id="spark-<?= $key ?>" data-color="<?= $p['color'] ?>"
                  data-vals="<?= implode(',',$p['sparkline']) ?>"></canvas>
        </div>
      </div>

      <!-- Core stats -->
      <div class="stat-grid">
        <div class="stat-cell">
          <div class="stat-label">Reach</div>
          <div class="stat-val"><?= fmt($p['reach']) ?></div>
          <div class="stat-delta up">↑ <?= $p['reach_delta'] ?>% this month</div>
        </div>
        <div class="stat-cell">
          <div class="stat-label">Impressions</div>
          <div class="stat-val"><?= fmt($p['impressions']) ?></div>
          <div class="stat-sub">Total exposures</div>
        </div>
        <div class="stat-cell">
          <div class="stat-label">Engagement Rate</div>
          <div class="stat-val"><?= $p['eng_rate'] ?>%</div>
          <div class="stat-delta <?= $p['eng_delta']>=0?'up':'down' ?>"><?= $p['eng_delta']>=0?'↑':'↓' ?> <?= abs($p['eng_delta']) ?>% change</div>
        </div>
        <div class="stat-cell">
          <div class="stat-label">Video Views</div>
          <div class="stat-val"><?= fmt($p['video_views']) ?></div>
          <div class="stat-sub">This month</div>
        </div>
      </div>

      <!-- Video performance bars -->
      <div class="video-perf">
        <div class="vp-title">📹 Video Performance</div>
        <div class="vp-row">
          <div class="vp-bar-wrap">
            <div class="vp-bar-label"><span>Watch Completion</span><span><?= $p['completion'] ?>%</span></div>
            <div class="vp-bar">
              <div class="vp-fill" style="width:<?= $p['completion'] ?>%;background:<?= $p['color'] ?>"></div>
            </div>
          </div>
        </div>
        <div class="vp-row">
          <div class="vp-bar-wrap">
            <div class="vp-bar-label"><span>Avg Watch Time</span><span><?= $p['avg_watch'] ?></span></div>
            <div class="vp-bar">
              <div class="vp-fill" style="width:<?= min(100,intval(str_replace(':','',$p['avg_watch']))/60*100) ?>%;background:var(--emerald)"></div>
            </div>
          </div>
        </div>
        <div style="margin-top:12px;">
          <div class="badges-row">
            <span class="badge-pill badge-time"><i class="far fa-clock"></i> Best: <?= $p['top_time'] ?></span>
            <span class="badge-pill badge-fmt"><i class="fas fa-film"></i> <?= $p['best_format'] ?></span>
            <span class="badge-pill badge-cpm">CPM $<?= number_format($p['cpm'],2) ?></span>
          </div>
        </div>
      </div>

      <!-- Engagement breakdown -->
      <div class="eng-row">
        <div class="eng-row-title">Engagement Breakdown</div>
        <div class="eng-items">
          <div class="eng-item">
            <div class="eng-icon">💬</div>
            <div class="eng-num"><?= fmt($p['comments']) ?></div>
            <div class="eng-lbl">Comments</div>
          </div>
          <div class="eng-item">
            <div class="eng-icon">🔁</div>
            <div class="eng-num"><?= fmt($p['shares']) ?></div>
            <div class="eng-lbl">Shares</div>
          </div>
          <div class="eng-item">
            <div class="eng-icon">🔖</div>
            <div class="eng-num"><?= fmt($p['saves']) ?></div>
            <div class="eng-lbl">Saves</div>
          </div>
        </div>
      </div>

      <!-- Audience -->
      <div class="audience-card">
        <div class="audience-title">Your Audience</div>
        <div class="aud-row">
          <div class="aud-label">Top age group</div>
          <div class="aud-val"><?= $p['age_top'] ?></div>
        </div>
        <div class="aud-row" style="margin-bottom:4px">
          <div class="aud-label">Gender split</div>
          <div class="aud-val"><?= $p['gender'] ?></div>
        </div>
        <?php
          preg_match('/(\d+)%/', $p['gender'], $m);
          $fem_pct = $m[1] ?? 50;
          $fem_color = (strpos($p['gender'],'F')!==false) ? $p['color'] : '#0369a1';
        ?>
        <div class="gender-bar">
          <div class="gender-f" style="width:<?= $fem_pct ?>%;background:<?= $fem_color ?>"></div>
        </div>
        <div class="aud-row" style="margin-top:10px">
          <div class="aud-label">Top country</div>
          <div class="aud-val"><?= $p['top_country'] ?></div>
        </div>
        <div style="margin-top:10px">
          <div class="aud-label">Audience sentiment</div>
          <div class="sent-row">
            <div class="sent-bar">
              <div class="sent-fill" style="width:<?= $p['sentiment'] ?>%"></div>
            </div>
            <div class="sent-num"><?= $p['sentiment'] ?>%</div>
          </div>
        </div>
      </div>

      <!-- AI Insight -->
      <div class="insight-card">
        <div class="insight-head">
          <div class="insight-head-icon">🤖</div>
          <div class="insight-head-label">VideoVizard Insight</div>
        </div>
        <div class="insight-text"><?= htmlspecialchars($p['insight']) ?></div>
      </div>

    </div><!-- /plat-panel -->
    <?php $first=false; endforeach; ?>
  </div><!-- /section -->

  <!-- ══ 90-DAY PROJECTION ════════════════════ -->
  <div class="proj-card">
    <div class="proj-title">📈 Growth Projection</div>
    <div class="proj-sub">Based on your current trajectory, here's where you'll be:</div>
    <?php
    $now_reach = $total_reach;
    $projections = [
      ['when'=>'In 30 days',  'mult'=>1.19, 'fol_add'=>'+12K followers'],
      ['when'=>'In 60 days',  'mult'=>1.42, 'fol_add'=>'+28K followers'],
      ['when'=>'In 90 days',  'mult'=>1.71, 'fol_add'=>'+52K followers'],
    ];
    foreach($projections as $proj):
    ?>
    <div class="proj-row">
      <div class="proj-when"><?= $proj['when'] ?></div>
      <div class="proj-nums">
        <div class="proj-reach"><?= fmt(round($now_reach * $proj['mult'])) ?> reach</div>
        <div class="proj-fol">↑ <?= $proj['fol_add'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ══ CLOSING CTA ══════════════════════════ -->
  <div class="cta-card">
    <div class="cta-emoji">🚀</div>
    <div class="cta-title">Ready to make this real?</div>
    <div class="cta-sub">These numbers are from just <?= $total_posts ?> posts this month across 6 platforms — all created and scheduled automatically by VideoVizard. Imagine what a full campaign looks like.</div>
    <a href="mailto:inam@videovizard.com" class="cta-btn">
      <i class="fas fa-calendar-check"></i> Book a Free Strategy Call
    </a>
    <div class="cta-trust">No commitment · 15 minutes · See a live demo</div>
  </div>

  <div class="footer">
    <strong>VideoVizard</strong> · AI-powered social media creation &amp; scheduling<br>
    Analytics data for <?= htmlspecialchars($client_name) ?> · <?= $report_month ?><br>
    <span style="color:#cbd5e1">Revenue estimates are CPM-based projections, not guarantees.</span>
  </div>

</div><!-- /page -->

<script>
/* ══ COUNT-UP ANIMATION ══════════════════════════ */
function countUp(el, target) {
  const dur = 1200, steps = 40, inc = target / steps;
  let cur = 0, i = 0;
  const t = setInterval(() => {
    cur = Math.min(cur + inc, target);
    i++;
    const val = Math.round(cur);
    if (val >= 1000000) el.textContent = (val/1000000).toFixed(1)+'M';
    else if (val >= 1000) el.textContent = (val/1000).toFixed(1)+'K';
    else el.textContent = val.toLocaleString();
    if (i >= steps) clearInterval(t);
  }, dur/steps);
}

document.querySelectorAll('[data-count]').forEach(el => {
  const target = parseInt(el.dataset.count);
  setTimeout(() => countUp(el, target), 300);
});

/* ══ REACH CHART ══════════════════════════════════ */
const reachCtx = document.getElementById('reachChart').getContext('2d');
const labels   = <?= json_encode(array_map(fn($d)=>date('M j',strtotime($d)), array_map(fn($i)=>date('Y-m-d',strtotime("-{$i} days")),array_reverse(range(0,29))))) ?>;
const vals     = <?= json_encode($growth_data) ?>;

const grad = reachCtx.createLinearGradient(0,0,0,160);
grad.addColorStop(0,'rgba(14,165,233,0.28)');
grad.addColorStop(1,'rgba(14,165,233,0)');

new Chart(reachCtx,{
  type:'line',
  data:{
    labels,
    datasets:[{
      data:vals,
      borderColor:'#0ea5e9',
      borderWidth:2.5,
      backgroundColor:grad,
      fill:true,
      tension:0.45,
      pointRadius:0,
      pointHoverRadius:5,
      pointHoverBackgroundColor:'#0ea5e9',
    }]
  },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:{
      mode:'index', intersect:false,
      callbacks:{ label: ctx => {
        const v = ctx.parsed.y;
        if(v>=1000000) return (v/1000000).toFixed(1)+'M reach';
        if(v>=1000)    return (v/1000).toFixed(1)+'K reach';
        return v+' reach';
      }}
    }},
    scales:{
      x:{ grid:{display:false}, ticks:{
        maxRotation:0, maxTicksLimit:5,
        color:'#94a3b8', font:{size:10}
      }},
      y:{ grid:{color:'rgba(148,163,184,0.12)'}, ticks:{
        maxTicksLimit:4, color:'#94a3b8', font:{size:10},
        callback: v => v>=1000000?(v/1000000).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'K':v
      }}
    }
  }
});

/* ══ SPARKLINES ══════════════════════════════════ */
document.querySelectorAll('canvas[data-vals]').forEach(canvas => {
  const vals  = canvas.dataset.vals.split(',').map(Number);
  const color = canvas.dataset.color;
  const ctx   = canvas.getContext('2d');
  const grad  = ctx.createLinearGradient(0,0,0,56);
  grad.addColorStop(0,color+'44');
  grad.addColorStop(1,color+'00');
  new Chart(ctx,{
    type:'line',
    data:{
      labels: vals.map((_,i)=>i),
      datasets:[{
        data:vals,
        borderColor:color,
        borderWidth:2,
        backgroundColor:grad,
        fill:true,
        tension:0.4,
        pointRadius:0,
      }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      animation:{duration:1000},
      plugins:{legend:{display:false},tooltip:{enabled:false}},
      scales:{x:{display:false},y:{display:false}}
    }
  });
});

/* ══ PLATFORM TAB SWITCH ══════════════════════════ */
function switchPlatform(btn, key, color) {
  // Tabs
  document.querySelectorAll('.plat-tab').forEach(b => {
    b.classList.remove('active');
    b.style.background = '';
    b.style.borderColor = '';
    b.style.color = '';
  });
  btn.classList.add('active');
  btn.style.background   = color;
  btn.style.borderColor  = color;
  btn.style.color        = '#fff';

  // Panels
  document.querySelectorAll('.plat-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('panel-' + key);
  panel.classList.add('active');
  panel.classList.remove('fade-in');
  void panel.offsetWidth; // reflow
  panel.classList.add('fade-in');
}
</script>
</body>
</html>
