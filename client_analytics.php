<?php
// ================================================================
// vizard_analytics.php  —  VideoVizard Analytics Dashboard
// Standalone:  vizard_analytics.php?client_id=5
// Embedded:    client_approval.php loads it in an iframe (?embed=1)
//
// client_id is used to scope data. All numbers below are DUMMY —
// replace each $platforms block with your live API response.
// ================================================================

$client_id    = (int)($_GET['client_id'] ?? 0);
$embed        = isset($_GET['embed']);          // true = inside iframe, hide topbar
$report_month = date('F Y');

// ── Helper ─────────────────────────────────────────────────
function f($n) {
    if ($n >= 1000000) return number_format($n/1000000,1).'M';
    if ($n >= 1000)    return number_format($n/1000,1).'K';
    return number_format($n);
}

// ── Which platforms this client has connected ───────────────
// Replace with a real DB query:
//   SELECT platform FROM hdb_oauth_tokens WHERE client_id=? AND access_token IS NOT NULL
// For demo we assume all 6 are connected.
$connected_platforms = ['facebook','instagram','tiktok','youtube','x','linkedin'];

// ── Platform analytics data (DUMMY — replace with API calls) ─
$all_data = [
    'facebook' => [
        'name'          => 'Facebook',
        'icon'          => 'fab fa-facebook',
        'color'         => '#1877F2',
        'bg'            => 'rgba(24,119,242,0.08)',
        'followers'     => 24800,   'follower_delta' => 2.4,
        'reach'         => 184000,  'reach_delta'    => 5.1,
        'impressions'   => 412000,
        'eng_rate'      => 4.2,     'eng_delta'      => 0.8,
        'video_views'   => 98000,   'avg_watch'      => '0:42',
        'completion'    => 54,
        'top_time'      => 'Wed 7 pm',
        'best_format'   => 'Reels',
        'link_clicks'   => 1240,
        'comments'      => 620,     'shares'         => 340,    'saves' => 210,
        'age_top'       => '25–34', 'gender'         => '58% F','top_country' => 'Canada',
        'sentiment'     => 92,
        'posts_month'   => 18,
        'sparkline'     => [12,18,14,22,20,28,24,34,30,40,36,46,42,52,50,60,56,66,62,70,66,76,72,82,78,88,84,92,90,98],
        'insight'       => 'Reels posted Wed 7 pm get 2.1× more reach than your average post. We\'ve locked that window for your strongest content.',
    ],
    'instagram' => [
        'name'          => 'Instagram',
        'icon'          => 'fab fa-instagram',
        'color'         => '#E1306C',
        'bg'            => 'rgba(225,48,108,0.08)',
        'followers'     => 61200,   'follower_delta' => 5.8,
        'reach'         => 520000,  'reach_delta'    => 14.2,
        'impressions'   => 980000,
        'eng_rate'      => 6.7,     'eng_delta'      => 1.2,
        'video_views'   => 310000,  'avg_watch'      => '0:28',
        'completion'    => 61,
        'top_time'      => 'Sat 6 pm',
        'best_format'   => 'Reels',
        'link_clicks'   => 4100,
        'comments'      => 1840,    'shares'         => 920,    'saves' => 3400,
        'age_top'       => '18–24', 'gender'         => '64% F','top_country' => 'USA',
        'sentiment'     => 96,
        'posts_month'   => 24,
        'sparkline'     => [30,38,34,48,44,60,56,72,68,84,80,96,92,110,106,124,120,138,134,152,148,166,162,180,176,196,192,210,206,224],
        'insight'       => '3,400 saves this month — people are bookmarking your content to buy later. Saves are the strongest purchase-intent signal on Instagram.',
    ],
    'tiktok' => [
        'name'          => 'TikTok',
        'icon'          => 'fab fa-tiktok',
        'color'         => '#010101',
        'bg'            => 'rgba(0,0,0,0.05)',
        'followers'     => 112400,  'follower_delta' => 12.1,
        'reach'         => 2100000, 'reach_delta'    => 38.4,
        'impressions'   => 4800000,
        'eng_rate'      => 9.8,     'eng_delta'      => 2.4,
        'video_views'   => 2100000, 'avg_watch'      => '0:19',
        'completion'    => 72,
        'top_time'      => 'Fri 9 pm',
        'best_format'   => 'Short clips',
        'link_clicks'   => 8200,
        'comments'      => 8700,    'shares'         => 44000,  'saves' => 12000,
        'age_top'       => '18–24', 'gender'         => '52% F','top_country' => 'USA',
        'sentiment'     => 88,
        'posts_month'   => 30,
        'sparkline'     => [80,110,95,140,125,175,160,220,200,280,260,340,320,410,390,480,460,550,530,620,600,690,670,760,740,830,810,900,880,980],
        'insight'       => '44,000 shares is extraordinary. Your content is going viral organically — each share reaches ~340 new people at zero extra cost to you.',
    ],
    'youtube' => [
        'name'          => 'YouTube',
        'icon'          => 'fab fa-youtube',
        'color'         => '#FF0000',
        'bg'            => 'rgba(255,0,0,0.06)',
        'followers'     => 38900,   'follower_delta' => 3.2,
        'reach'         => 620000,  'reach_delta'    => 9.7,
        'impressions'   => 1200000,
        'eng_rate'      => 5.1,     'eng_delta'      => 0.6,
        'video_views'   => 620000,  'avg_watch'      => '4:12',
        'completion'    => 48,
        'top_time'      => 'Sun 2 pm',
        'best_format'   => 'Shorts',
        'link_clicks'   => 3800,
        'comments'      => 2100,    'shares'         => 640,    'saves' => 4200,
        'age_top'       => '25–34', 'gender'         => '61% M','top_country' => 'India',
        'sentiment'     => 91,
        'posts_month'   => 8,
        'sparkline'     => [40,52,46,62,56,74,68,88,80,102,94,118,110,136,128,154,146,172,164,190,182,208,200,226,218,244,236,262,254,280],
        'insight'       => '4:12 average watch time puts you in the top 5% for your niche. YouTube rewards long watch time with free algorithm distribution worth thousands in ad spend.',
    ],
    'x' => [
        'name'          => 'X (Twitter)',
        'icon'          => 'fab fa-x-twitter',
        'color'         => '#14171A',
        'bg'            => 'rgba(20,23,26,0.05)',
        'followers'     => 19300,   'follower_delta' => 1.1,
        'reach'         => 88000,   'reach_delta'    => 2.8,
        'impressions'   => 240000,
        'eng_rate'      => 2.9,     'eng_delta'      => -0.3,
        'video_views'   => 44000,   'avg_watch'      => '0:22',
        'completion'    => 38,
        'top_time'      => 'Mon 8 am',
        'best_format'   => 'Short clips',
        'link_clicks'   => 980,
        'comments'      => 340,     'shares'         => 820,    'saves' => 120,
        'age_top'       => '25–34', 'gender'         => '68% M','top_country' => 'USA',
        'sentiment'     => 74,
        'posts_month'   => 22,
        'sparkline'     => [20,22,21,24,23,26,25,28,27,30,29,32,31,34,33,36,35,38,37,40,39,42,41,44,43,46,45,48,47,50],
        'insight'       => 'X drives strong professional conversations. Monday morning posts spark the most replies — ideal for thought-leadership positioning in your niche.',
    ],
    'linkedin' => [
        'name'          => 'LinkedIn',
        'icon'          => 'fab fa-linkedin',
        'color'         => '#0A66C2',
        'bg'            => 'rgba(10,102,194,0.07)',
        'followers'     => 8700,    'follower_delta' => 4.7,
        'reach'         => 42000,   'reach_delta'    => 11.2,
        'impressions'   => 98000,
        'eng_rate'      => 3.8,     'eng_delta'      => 0.5,
        'video_views'   => 22000,   'avg_watch'      => '1:14',
        'completion'    => 44,
        'top_time'      => 'Tue 9 am',
        'best_format'   => 'Document posts',
        'link_clicks'   => 1860,
        'comments'      => 280,     'shares'         => 190,    'saves' => 640,
        'age_top'       => '30–44', 'gender'         => '54% M','top_country' => 'Canada',
        'sentiment'     => 94,
        'posts_month'   => 12,
        'sparkline'     => [8,10,9,12,11,14,13,16,15,18,17,20,19,22,21,24,23,26,25,28,27,30,29,32,31,34,33,36,35,38],
        'insight'       => 'LinkedIn CPM of $14.20 means your audience has serious buying power. Decision-makers are watching — this is your strongest B2B channel.',
    ],
];

// ── Filter to only this client's connected platforms ────────
$platforms = array_intersect_key($all_data, array_flip($connected_platforms));

// ── Totals ──────────────────────────────────────────────────
$tot_followers = array_sum(array_column($platforms,'followers'));
$tot_views     = array_sum(array_column($platforms,'video_views'));
$tot_reach     = array_sum(array_column($platforms,'reach'));
$tot_posts     = array_sum(array_column($platforms,'posts_month'));
$avg_sentiment = count($platforms) ? round(array_sum(array_column($platforms,'sentiment'))/count($platforms)) : 0;

// ── 30-day reach growth (dummy trend) ──────────────────────
$growth_labels = [];
$growth_vals   = [];
for ($i = 29; $i >= 0; $i--) {
    $growth_labels[] = date('M j', strtotime("-{$i} days"));
    $base = 160000;
    $growth_vals[] = round($base * (1 + 0.55*((29-$i)/29)) + rand(-6000,10000));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>VideoVizard · Analytics<?= $client_id ? " · Client #{$client_id}" : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── TOKENS ─────────────────────────────────────────────── */
:root {
  --sky-50:  #f0f9ff; --sky-100:#e0f2fe; --sky-200:#bae6fd;
  --sky-400: #38bdf8; --sky-500:#0ea5e9; --sky-600:#0284c7;
  --sky-700: #0369a1; --sky-900:#0c4a6e; --navy:#062236;
  --emerald: #059669; --amber:#f59e0b;
  --border:  rgba(2,132,199,.13);
  --shadow:  0 4px 24px rgba(2,132,199,.09);
  --muted:   #64748b;
  --radius:  16px;
}
*,*::before,*::after { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html { scroll-behavior:smooth; }
body {
  font-family:'Instrument Sans',sans-serif;
  background:var(--sky-50); color:var(--sky-900);
  font-size:15px; line-height:1.6; overflow-x:hidden;
  /* When embedded in iframe, transparent bg looks cleaner */
  <?= $embed ? 'background:transparent;' : '' ?>
}
h1,h2,h3 { font-family:'Bricolage Grotesque',sans-serif; line-height:1.15; }

/* ── PAGE WRAPPER ───────────────────────────────────────── */
.page { max-width:480px; margin:0 auto; padding:0 0 60px; }
/* On desktop standalone, cap width nicely */
@media(min-width:500px){ .page{ border-left:1px solid var(--border); border-right:1px solid var(--border); } }

/* ── HERO HEADER ────────────────────────────────────────── */
.hero {
  background:linear-gradient(160deg,var(--sky-900) 0%,var(--navy) 100%);
  padding:<?= $embed ? '28px' : '44px' ?> 20px 80px;
  position:relative; overflow:hidden;
}
.hero::before {
  content:''; position:absolute; width:380px; height:380px; border-radius:50%;
  background:rgba(56,189,248,.12); filter:blur(60px);
  top:-80px; right:-60px; pointer-events:none;
}
.hero::after {
  content:''; position:absolute; width:260px; height:260px; border-radius:50%;
  background:rgba(5,150,105,.10); filter:blur(50px);
  bottom:-60px; left:-40px; pointer-events:none;
}
<?php if (!$embed): ?>
.hero-brand { font-family:'Bricolage Grotesque',sans-serif; font-size:19px; font-weight:800; color:#fff; margin-bottom:28px; }
.hero-brand em { color:#38bdf8; font-style:normal; }
<?php endif; ?>
.hero-badge {
  display:inline-flex; align-items:center; gap:6px;
  background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.18);
  color:rgba(255,255,255,.80); padding:4px 13px; border-radius:40px;
  font-size:11px; font-weight:600; letter-spacing:.03em; margin-bottom:14px;
}
.dot-live { width:6px; height:6px; background:#4ade80; border-radius:50%; animation:blink 2s infinite; }
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.hero-title { font-size:26px; font-weight:800; color:#fff; letter-spacing:-.5px; margin-bottom:4px; }
.hero-sub   { font-size:13px; color:rgba(255,255,255,.58); }

/* ── SUMMARY STRIP ──────────────────────────────────────── */
.sum-strip {
  display:grid; grid-template-columns:1fr 1fr; gap:10px;
  padding:0 14px; margin-top:-46px; margin-bottom:20px; position:relative; z-index:10;
}
.scard {
  background:#fff; border:1px solid var(--border); border-radius:16px;
  padding:14px 13px; box-shadow:var(--shadow);
  animation:popUp .5s cubic-bezier(.22,.68,0,1.2) both;
}
.scard:nth-child(1){animation-delay:.05s} .scard:nth-child(2){animation-delay:.10s}
.scard:nth-child(3){animation-delay:.15s} .scard:nth-child(4){animation-delay:.20s}
@keyframes popUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.sc-icon  { font-size:19px; margin-bottom:5px; }
.sc-val   {
  font-family:'Bricolage Grotesque',sans-serif; font-size:22px; font-weight:800; letter-spacing:-.5px;
  background:linear-gradient(135deg,var(--sky-500),var(--sky-700));
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.sc-label { font-size:10px; color:var(--muted); font-weight:500; margin-top:1px; }
.sc-trend { font-size:10px; font-weight:600; color:var(--emerald); margin-top:3px; }

/* ── SECTION LABEL ──────────────────────────────────────── */
.sec { padding:0 14px; margin-bottom:12px; }
.sec-eye   { font-size:10px; font-weight:700; color:var(--sky-600); text-transform:uppercase; letter-spacing:.12em; display:block; margin-bottom:2px; }
.sec-title { font-size:19px; font-weight:800; color:var(--sky-900); }

/* ── CHART CARD ─────────────────────────────────────────── */
.chart-card {
  margin:0 14px 24px; background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 14px; box-shadow:var(--shadow);
}
.chart-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; }
.chart-title{ font-size:15px; font-weight:700; color:var(--sky-900); }
.chart-sub  { font-size:11px; color:var(--muted); margin-top:2px; }
.chart-pill { background:rgba(5,150,105,.09); border:1px solid rgba(5,150,105,.20); color:var(--emerald); padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
.chart-wrap { position:relative; height:150px; }

/* ── CONNECTED PLATFORMS ────────────────────────────────── */
.conn-card {
  margin:0 14px 24px; background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:16px; box-shadow:var(--shadow);
}
.conn-title { font-size:13px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.conn-list  { display:flex; flex-direction:column; gap:10px; }
.conn-row   { display:flex; align-items:center; gap:12px; }
.conn-icon  { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; flex-shrink:0; }
.conn-name  { font-size:13px; font-weight:700; color:var(--sky-900); }
.conn-fol   { font-size:11px; color:var(--muted); }
.conn-grow  { margin-left:auto; font-size:11px; font-weight:700; color:var(--emerald); background:rgba(5,150,105,.09); border:1px solid rgba(5,150,105,.18); padding:3px 9px; border-radius:20px; white-space:nowrap; }

/* ── PLATFORM TABS ──────────────────────────────────────── */
.ptabs-wrap { padding:0 14px; margin-bottom:14px; overflow-x:auto; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.ptabs-wrap::-webkit-scrollbar { display:none; }
.ptabs { display:flex; gap:8px; width:max-content; }
.ptab  {
  display:flex; align-items:center; gap:6px; padding:8px 14px; border-radius:50px;
  border:1.5px solid var(--sky-200); background:#fff; cursor:pointer; transition:all .2s;
  font-family:'Instrument Sans',sans-serif; font-size:12px; font-weight:600; color:var(--sky-700); white-space:nowrap;
}
.ptab.active { border-color:transparent; color:#fff; }
.ptab:active { transform:scale(.96); }

/* ── PLATFORM PANEL ─────────────────────────────────────── */
.ppanel { display:none; padding:0 14px; }
.ppanel.active { display:block; animation:fadeIn .35s ease both; }
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* Follower hero */
.phero {
  background:#fff; border:1px solid var(--border); border-radius:var(--radius);
  padding:16px; margin-bottom:10px; box-shadow:var(--shadow);
}
.phero-top { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.phero-av  { width:44px; height:44px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
.phero-name{ font-size:17px; font-weight:800; color:var(--sky-900); }
.phero-conn{ margin-left:auto; font-size:10px; font-weight:700; background:rgba(5,150,105,.09); border:1px solid rgba(5,150,105,.18); color:var(--emerald); padding:3px 9px; border-radius:20px; }
.phero-fnum{ font-family:'Bricolage Grotesque',sans-serif; font-size:34px; font-weight:800; letter-spacing:-1px; }
.phero-grow{ font-size:12px; font-weight:700; background:rgba(5,150,105,.10); color:var(--emerald); padding:3px 9px; border-radius:20px; margin-left:10px; }
.phero-lbl { font-size:11px; color:var(--muted); margin-top:-2px; margin-bottom:10px; }
.spark-wrap{ position:relative; height:52px; }

/* Stat 2-col grid */
.sgrid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px; }
.scell {
  background:#fff; border:1px solid var(--border); border-radius:14px;
  padding:13px 11px; box-shadow:var(--shadow);
}
.scell-lbl { font-size:10px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
.scell-val { font-size:19px; font-weight:800; font-family:'Bricolage Grotesque',sans-serif; color:var(--sky-900); letter-spacing:-.4px; }
.scell-sub { font-size:10px; color:var(--muted); margin-top:2px; }
.up        { color:var(--emerald); font-size:11px; font-weight:600; margin-top:2px; }
.dn        { color:#ef4444;        font-size:11px; font-weight:600; margin-top:2px; }

/* Video performance */
.vperf {
  background:#fff; border:1px solid var(--border); border-radius:var(--radius);
  padding:14px; margin-bottom:10px; box-shadow:var(--shadow);
}
.vperf-title { font-size:11px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.bar-row    { margin-bottom:12px; }
.bar-lbl    { display:flex; justify-content:space-between; font-size:11px; color:var(--muted); margin-bottom:4px; }
.bar-track  { height:9px; background:var(--sky-100); border-radius:99px; overflow:hidden; }
.bar-fill   { height:100%; border-radius:99px; transition:width 1.2s ease; }

/* Engagement row */
.eng-card {
  background:#fff; border:1px solid var(--border); border-radius:var(--radius);
  padding:14px; margin-bottom:10px; box-shadow:var(--shadow);
}
.eng-title { font-size:11px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.eng-row   { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
.eng-item  { text-align:center; }
.eng-emoji { font-size:18px; margin-bottom:3px; }
.eng-num   { font-size:16px; font-weight:800; font-family:'Bricolage Grotesque',sans-serif; color:var(--sky-900); }
.eng-lbl   { font-size:10px; color:var(--muted); }

/* Audience card */
.aud-card {
  background:#fff; border:1px solid var(--border); border-radius:var(--radius);
  padding:14px; margin-bottom:10px; box-shadow:var(--shadow);
}
.aud-title { font-size:11px; font-weight:700; color:var(--sky-700); text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
.aud-row   { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
.aud-key   { font-size:12px; color:var(--muted); }
.aud-val   { font-size:12px; font-weight:700; color:var(--sky-900); }
.gender-bar{ height:8px; background:var(--sky-100); border-radius:99px; overflow:hidden; margin-top:4px; position:relative; }
.gender-fill{ position:absolute; left:0; top:0; height:100%; border-radius:99px; }
.sent-row  { display:flex; align-items:center; gap:10px; margin-top:6px; }
.sent-track{ flex:1; height:8px; background:var(--sky-100); border-radius:99px; overflow:hidden; }
.sent-fill { height:100%; background:linear-gradient(90deg,var(--emerald),#34d399); border-radius:99px; }
.sent-val  { font-size:12px; font-weight:700; color:var(--emerald); min-width:32px; text-align:right; }

/* Badges */
.badge-row { display:flex; gap:6px; flex-wrap:wrap; margin-top:4px; }
.bdg       { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:5px 11px; border-radius:40px; border:1.5px solid; }
.bdg-blue  { background:rgba(2,132,199,.07); border-color:rgba(2,132,199,.18); color:var(--sky-700); }
.bdg-green { background:rgba(5,150,105,.07); border-color:rgba(5,150,105,.18); color:var(--emerald); }

/* Insight card */
.insight {
  border-radius:var(--radius); padding:14px; margin-bottom:10px;
  background:linear-gradient(135deg,rgba(240,249,255,.95),rgba(224,242,254,.7));
  border:1px solid rgba(2,132,199,.14);
}
.insight-head  { display:flex; align-items:center; gap:7px; margin-bottom:7px; }
.insight-emoji { font-size:15px; }
.insight-label { font-size:10px; font-weight:700; color:var(--sky-600); text-transform:uppercase; letter-spacing:.08em; }
.insight-text  { font-size:13px; color:var(--sky-900); line-height:1.65; font-weight:500; }

/* ── PROJECTION ─────────────────────────────────────────── */
.proj-card {
  margin:0 14px 24px; background:#fff; border:1px solid var(--border);
  border-radius:var(--radius); padding:18px 14px; box-shadow:var(--shadow);
}
.proj-title{ font-size:15px; font-weight:700; color:var(--sky-900); margin-bottom:3px; }
.proj-sub  { font-size:11px; color:var(--muted); margin-bottom:14px; }
.proj-row  { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--sky-100); }
.proj-row:last-child{ border-bottom:none; }
.proj-when { font-size:13px; color:var(--muted); }
.proj-reach{ font-size:14px; font-weight:800; color:var(--sky-700); }
.proj-fol  { font-size:10px; font-weight:600; color:var(--emerald); }

/* ── CTA ────────────────────────────────────────────────── */
.cta-card {
  margin:0 14px 28px;
  background:linear-gradient(135deg,var(--sky-900),var(--navy));
  border-radius:22px; padding:26px 20px; text-align:center; position:relative; overflow:hidden;
}
.cta-card::before {
  content:''; position:absolute; width:260px; height:260px; border-radius:50%;
  background:rgba(56,189,248,.12); filter:blur(40px);
  top:-70px; right:-50px; pointer-events:none;
}
.cta-emoji { font-size:32px; margin-bottom:10px; }
.cta-title { font-size:20px; font-weight:800; color:#fff; letter-spacing:-.4px; margin-bottom:7px; }
.cta-sub   { font-size:13px; color:rgba(255,255,255,.62); line-height:1.6; margin-bottom:20px; }
.cta-btn   {
  display:inline-flex; align-items:center; justify-content:center; gap:7px; width:100%;
  background:var(--sky-400); color:var(--navy);
  font-family:'Bricolage Grotesque',sans-serif; font-size:15px; font-weight:800;
  padding:13px 24px; border-radius:50px; border:none; cursor:pointer;
  box-shadow:0 8px 24px rgba(56,189,248,.28); transition:.2s; text-decoration:none;
}
.cta-btn:active { transform:scale(.97); }
.cta-trust { margin-top:12px; font-size:11px; color:rgba(255,255,255,.45); }

/* ── FOOTER ─────────────────────────────────────────────── */
.footer { text-align:center; font-size:11px; color:#94a3b8; padding:10px 20px 4px; }
.demo-note {
  margin:0 14px 20px; padding:10px 14px;
  background:rgba(245,158,11,.07); border:1px solid rgba(245,158,11,.22);
  border-radius:10px; font-size:11px; color:#92400e; text-align:center; line-height:1.5;
}
</style>
</head>
<body>
<div class="page">

  <!-- HERO -->
  <div class="hero">
    <?php if (!$embed): ?>
    <div class="hero-brand"><em>Video</em>Vizard</div>
    <?php endif; ?>
    <div class="hero-badge"><span class="dot-live"></span> Live Analytics · <?= $report_month ?></div>
    <div class="hero-title">Your Performance</div>
    <div class="hero-sub"><?= count($platforms) ?> platform<?= count($platforms)>1?'s':'' ?> connected · last 30 days</div>
  </div>

  <!-- SUMMARY STRIP -->
  <div class="sum-strip">
    <div class="scard">
      <div class="sc-icon">👥</div>
      <div class="sc-val" data-count="<?= $tot_followers ?>"><?= f($tot_followers) ?></div>
      <div class="sc-label">Total Followers</div>
      <div class="sc-trend">↑ Avg 4.9% growth</div>
    </div>
    <div class="scard">
      <div class="sc-icon">▶️</div>
      <div class="sc-val" data-count="<?= $tot_views ?>"><?= f($tot_views) ?></div>
      <div class="sc-label">Video Views</div>
      <div class="sc-trend">↑ 28.4% vs last month</div>
    </div>
    <div class="scard">
      <div class="sc-icon">📡</div>
      <div class="sc-val" data-count="<?= $tot_reach ?>"><?= f($tot_reach) ?></div>
      <div class="sc-label">Combined Reach</div>
      <div class="sc-trend">↑ 19.1% growth</div>
    </div>
    <div class="scard">
      <div class="sc-icon">😊</div>
      <div class="sc-val"><?= $avg_sentiment ?>%</div>
      <div class="sc-label">Avg Sentiment</div>
      <div class="sc-trend">Highly positive</div>
    </div>
  </div>

  <!-- 30-DAY CHART -->
  <div class="sec"><span class="sec-eye">30-Day Trend</span><div class="sec-title">Reach is climbing</div></div>
  <div class="chart-card">
    <div class="chart-head">
      <div>
        <div class="chart-title">Combined reach across all platforms</div>
        <div class="chart-sub">Last 30 days · all <?= count($platforms) ?> connected platforms</div>
      </div>
      <div class="chart-pill">↑ 19.1%</div>
    </div>
    <div class="chart-wrap"><canvas id="reachChart"></canvas></div>
  </div>

  <!-- CONNECTED PLATFORMS LIST -->
  <div class="sec"><span class="sec-eye">Connected Platforms</span><div class="sec-title">Where you're posting</div></div>
  <div class="conn-card">
    <div class="conn-title">Your <?= count($platforms) ?> Active Platforms</div>
    <div class="conn-list">
      <?php foreach ($platforms as $key => $p): ?>
      <div class="conn-row">
        <div class="conn-icon" style="background:<?= $p['color'] ?>"><i class="<?= $p['icon'] ?>"></i></div>
        <div>
          <div class="conn-name"><?= $p['name'] ?></div>
          <div class="conn-fol"><?= f($p['followers']) ?> followers · <?= $p['posts_month'] ?> posts this month</div>
        </div>
        <div class="conn-grow">↑ <?= $p['follower_delta'] ?>%</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PER-PLATFORM DETAIL -->
  <div class="sec"><span class="sec-eye">Per Platform</span><div class="sec-title">Detailed Breakdown</div></div>

  <!-- Platform tab buttons -->
  <div class="ptabs-wrap">
    <div class="ptabs" id="ptabs">
      <?php $first = true; foreach ($platforms as $key => $p): ?>
      <button class="ptab <?= $first?'active':'' ?>"
              data-key="<?= $key ?>"
              style="<?= $first?'background:'.$p['color'].';border-color:'.$p['color']:'' ?>"
              onclick="swPlat(this,'<?= $key ?>','<?= $p['color'] ?>')">
        <i class="<?= $p['icon'] ?>"></i><?= $p['name'] ?>
      </button>
      <?php $first = false; endforeach; ?>
    </div>
  </div>

  <!-- Platform panels -->
  <?php $first = true; foreach ($platforms as $key => $p):
    preg_match('/(\d+)%/', $p['gender'], $gm);
    $fem_pct = isset($gm[1]) ? (int)$gm[1] : 50;
    $is_female = strpos($p['gender'], 'F') !== false;
  ?>
  <div class="ppanel <?= $first?'active':'' ?>" id="pp-<?= $key ?>">

    <!-- Follower hero -->
    <div class="phero">
      <div class="phero-top">
        <div class="phero-av" style="background:<?= $p['color'] ?>"><i class="<?= $p['icon'] ?>"></i></div>
        <div>
          <div class="phero-name"><?= $p['name'] ?></div>
        </div>
        <div class="phero-conn">✓ Connected</div>
      </div>
      <div style="display:flex;align-items:baseline;gap:10px">
        <div class="phero-fnum" style="color:<?= $p['color'] ?>"><?= f($p['followers']) ?></div>
        <div class="phero-grow">↑ <?= $p['follower_delta'] ?>%</div>
      </div>
      <div class="phero-lbl">Followers · growing <?= $p['follower_delta'] ?>% this month</div>
      <!-- Sparkline -->
      <div class="spark-wrap">
        <canvas id="sp-<?= $key ?>"
                data-color="<?= $p['color'] ?>"
                data-vals="<?= implode(',', $p['sparkline']) ?>"></canvas>
      </div>
    </div>

    <!-- Core stats -->
    <div class="sgrid">
      <div class="scell">
        <div class="scell-lbl">Reach</div>
        <div class="scell-val"><?= f($p['reach']) ?></div>
        <div class="up">↑ <?= $p['reach_delta'] ?>% this month</div>
      </div>
      <div class="scell">
        <div class="scell-lbl">Impressions</div>
        <div class="scell-val"><?= f($p['impressions']) ?></div>
        <div class="scell-sub">Total exposures</div>
      </div>
      <div class="scell">
        <div class="scell-lbl">Engagement Rate</div>
        <div class="scell-val"><?= $p['eng_rate'] ?>%</div>
        <div class="<?= $p['eng_delta']>=0?'up':'dn' ?>"><?= $p['eng_delta']>=0?'↑':'↓' ?> <?= abs($p['eng_delta']) ?>% change</div>
      </div>
      <div class="scell">
        <div class="scell-lbl">Video Views</div>
        <div class="scell-val"><?= f($p['video_views']) ?></div>
        <div class="scell-sub">This month</div>
      </div>
    </div>

    <!-- Video performance -->
    <div class="vperf">
      <div class="vperf-title">📹 Video Performance</div>
      <div class="bar-row">
        <div class="bar-lbl"><span>Watch Completion</span><span><?= $p['completion'] ?>%</span></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= $p['completion'] ?>%;background:<?= $p['color'] ?>"></div></div>
      </div>
      <div class="bar-row">
        <div class="bar-lbl"><span>Avg Watch Time</span><span><?= $p['avg_watch'] ?></span></div>
        <div class="bar-track"><div class="bar-fill" style="width:<?= min(100, intval(str_replace(':','',$p['avg_watch']))/400*100) ?>%;background:var(--emerald)"></div></div>
      </div>
      <div style="margin-top:12px">
        <div class="badge-row">
          <span class="bdg bdg-blue"><i class="far fa-clock"></i> Best: <?= $p['top_time'] ?></span>
          <span class="bdg bdg-green"><i class="fas fa-film"></i> <?= $p['best_format'] ?></span>
        </div>
      </div>
    </div>

    <!-- Engagement -->
    <div class="eng-card">
      <div class="eng-title">Engagement Breakdown</div>
      <div class="eng-row">
        <div class="eng-item"><div class="eng-emoji">💬</div><div class="eng-num"><?= f($p['comments']) ?></div><div class="eng-lbl">Comments</div></div>
        <div class="eng-item"><div class="eng-emoji">🔁</div><div class="eng-num"><?= f($p['shares']) ?></div><div class="eng-lbl">Shares</div></div>
        <div class="eng-item"><div class="eng-emoji">🔖</div><div class="eng-num"><?= f($p['saves']) ?></div><div class="eng-lbl">Saves</div></div>
      </div>
    </div>

    <!-- Audience -->
    <div class="aud-card">
      <div class="aud-title">Your Audience</div>
      <div class="aud-row"><div class="aud-key">Top age group</div><div class="aud-val"><?= $p['age_top'] ?></div></div>
      <div class="aud-row" style="margin-bottom:3px"><div class="aud-key">Gender split</div><div class="aud-val"><?= $p['gender'] ?></div></div>
      <div class="gender-bar">
        <div class="gender-fill" style="width:<?= $fem_pct ?>%;background:<?= $p['color'] ?>"></div>
      </div>
      <div class="aud-row" style="margin-top:10px"><div class="aud-key">Top country</div><div class="aud-val"><?= $p['top_country'] ?></div></div>
      <div class="aud-row" style="margin-top:6px"><div class="aud-key">Link clicks this month</div><div class="aud-val"><?= f($p['link_clicks']) ?></div></div>
      <div style="margin-top:10px">
        <div class="aud-key" style="margin-bottom:5px">Audience sentiment</div>
        <div class="sent-row">
          <div class="sent-track"><div class="sent-fill" style="width:<?= $p['sentiment'] ?>%"></div></div>
          <div class="sent-val"><?= $p['sentiment'] ?>%</div>
        </div>
      </div>
    </div>

    <!-- Insight -->
    <div class="insight">
      <div class="insight-head"><div class="insight-emoji">🤖</div><div class="insight-label">VideoVizard Insight</div></div>
      <div class="insight-text"><?= htmlspecialchars($p['insight']) ?></div>
    </div>

  </div><!-- /ppanel -->
  <?php $first = false; endforeach; ?>

  <!-- 90-DAY PROJECTION -->
  <div class="sec" style="margin-top:10px"><span class="sec-eye">Growth Projection</span><div class="sec-title">Where you're headed</div></div>
  <div class="proj-card">
    <div class="proj-title">📈 Based on your current trajectory</div>
    <div class="proj-sub">Projections assume current posting frequency continues</div>
    <?php
    $proj = [
      ['In 30 days', 1.19, '+12K followers'],
      ['In 60 days', 1.42, '+28K followers'],
      ['In 90 days', 1.71, '+52K followers'],
    ];
    foreach ($proj as [$when,$mult,$fol]):
    ?>
    <div class="proj-row">
      <div class="proj-when"><?= $when ?></div>
      <div style="text-align:right">
        <div class="proj-reach"><?= f(round($tot_reach*$mult)) ?> reach</div>
        <div class="proj-fol">↑ <?= $fol ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- DEMO NOTE -->
  <div class="demo-note">
    ⚠️ All analytics numbers above are <strong>demo data</strong> for illustration. Once your social accounts are connected, this page shows your live stats.
  </div>

  <!-- CTA -->
  <div class="cta-card">
    <div class="cta-emoji">🚀</div>
    <div class="cta-title">Ready to make this real?</div>
    <div class="cta-sub">These results came from just <?= $tot_posts ?> posts this month — all created and scheduled automatically by VideoVizard. Imagine a full campaign.</div>
    <a href="mailto:inam@videovizard.com" class="cta-btn"><i class="fas fa-calendar-check"></i> Book a Free Strategy Call</a>
    <div class="cta-trust">No commitment · 15 min · Live demo included</div>
  </div>

  <div class="footer">
    <strong>VideoVizard</strong> · AI-powered social media creation &amp; scheduling<br>
    <?= $report_month ?> · <?= count($platforms) ?> platform<?= count($platforms)>1?'s':'' ?> active<?= $client_id?" · Client #{$client_id}":'' ?>
  </div>

</div><!-- /page -->

<script>
/* ── Count-up ─── */
document.querySelectorAll('[data-count]').forEach(el => {
  const target = parseInt(el.dataset.count);
  let cur = 0, steps = 40;
  const inc = target / steps;
  const fmt = n => n>=1e6?(n/1e6).toFixed(1)+'M':n>=1000?(n/1000).toFixed(1)+'K':Math.round(n).toLocaleString();
  const t = setInterval(() => {
    cur = Math.min(cur + inc, target);
    el.textContent = fmt(cur);
    if (cur >= target) clearInterval(t);
  }, 1000/40);
});

/* ── Reach chart ─── */
const rc  = document.getElementById('reachChart').getContext('2d');
const lbs = <?= json_encode($growth_labels) ?>;
const vs  = <?= json_encode($growth_vals) ?>;
const rg  = rc.createLinearGradient(0,0,0,150);
rg.addColorStop(0,'rgba(14,165,233,.26)'); rg.addColorStop(1,'rgba(14,165,233,0)');
new Chart(rc, {
  type:'line',
  data:{ labels:lbs, datasets:[{data:vs, borderColor:'#0ea5e9', borderWidth:2.5, backgroundColor:rg, fill:true, tension:.45, pointRadius:0, pointHoverRadius:5, pointHoverBackgroundColor:'#0ea5e9'}] },
  options:{
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false}, tooltip:{mode:'index',intersect:false,callbacks:{label:c=>{const v=c.parsed.y;return v>=1e6?(v/1e6).toFixed(1)+'M reach':v>=1000?(v/1000).toFixed(1)+'K reach':v+' reach';}}} },
    scales:{
      x:{ grid:{display:false}, ticks:{maxRotation:0,maxTicksLimit:5,color:'#94a3b8',font:{size:9}} },
      y:{ grid:{color:'rgba(148,163,184,.10)'}, ticks:{maxTicksLimit:4,color:'#94a3b8',font:{size:9},callback:v=>v>=1e6?(v/1e6).toFixed(1)+'M':v>=1000?(v/1000).toFixed(0)+'K':v} }
    }
  }
});

/* ── Sparklines ─── */
document.querySelectorAll('canvas[data-vals]').forEach(canvas => {
  const vals  = canvas.dataset.vals.split(',').map(Number);
  const color = canvas.dataset.color;
  const ctx   = canvas.getContext('2d');
  const gr    = ctx.createLinearGradient(0,0,0,52);
  gr.addColorStop(0, color+'40'); gr.addColorStop(1, color+'00');
  new Chart(ctx, {
    type:'line',
    data:{ labels:vals.map((_,i)=>i), datasets:[{data:vals,borderColor:color,borderWidth:2,backgroundColor:gr,fill:true,tension:.42,pointRadius:0}] },
    options:{ responsive:true, maintainAspectRatio:false, animation:{duration:900}, plugins:{legend:{display:false},tooltip:{enabled:false}}, scales:{x:{display:false},y:{display:false}} }
  });
});

/* ── Platform tab switch ─── */
function swPlat(btn, key, color) {
  document.querySelectorAll('.ptab').forEach(b => {
    b.classList.remove('active'); b.style.background=''; b.style.borderColor=''; b.style.color='';
  });
  btn.classList.add('active');
  btn.style.background = color; btn.style.borderColor = color; btn.style.color = '#fff';
  document.querySelectorAll('.ppanel').forEach(p => p.classList.remove('active'));
  document.getElementById('pp-' + key).classList.add('active');
}
</script>
</body>
</html>
