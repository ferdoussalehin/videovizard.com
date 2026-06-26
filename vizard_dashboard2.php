<?php
// VideoVizard Dashboard — Light Blue Theme
// All data is dummy/mock — replace with real API calls

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
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --sky:    #EBF4FD;
    --sky2:   #D6EAFA;
    --sky3:   #C0DFFA;
    --blue:   #2E8FE8;
    --blue2:  #185FA5;
    --navy:   #0D3560;
    --white:  #FFFFFF;
    --surface:#F4F9FE;
    --border: #D1E4F5;
    --border2:#B0CEE8;
    --text:   #0D3560;
    --muted:  #5A7FA8;
    --green:  #12B76A;
    --red:    #F04438;
    --amber:  #F79009;
    --font:   'Plus Jakarta Sans', sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--surface);color:var(--text);font-size:14px;line-height:1.5}
a{text-decoration:none;color:inherit}

/* layout */
.wrap{max-width:1320px;margin:0 auto;padding:32px 24px}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.logo{font-size:20px;font-weight:700;color:var(--blue2);letter-spacing:-0.5px}
.logo span{color:var(--navy)}
.topbar-right{display:flex;gap:10px;align-items:center}
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--font);display:inline-flex;align-items:center;gap:6px}
.btn-outline{background:var(--white);border:1.5px solid var(--border2);color:var(--blue2)}
.btn-outline:hover{border-color:var(--blue);color:var(--blue)}
.btn-primary{background:var(--blue);color:#fff}
.btn-primary:hover{background:var(--blue2)}

/* section label */
.section-label{font-size:11px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:14px}

/* summary stats */
.summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px}
.sum-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px 20px}
.sum-label{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px}
.sum-val{font-size:26px;font-weight:700;color:var(--navy);letter-spacing:-0.5px;line-height:1}
.sum-delta{font-size:12px;margin-top:6px;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-weight:500}
.up{background:#E6FAF3;color:#0D7A4E}
.down{background:#FEF3F2;color:#B42318}
.neu{background:var(--sky);color:var(--blue2)}

/* platform cards */
.platforms-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;margin-bottom:32px}
.pcard{background:var(--white);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.pcard-header{display:flex;align-items:center;gap:12px;padding:16px 20px 14px;border-bottom:1px solid var(--border)}
.picon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;letter-spacing:-0.5px;flex-shrink:0}
.pname{font-size:15px;font-weight:700;color:var(--navy)}
.pstatus{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#E6FAF3;color:#0D7A4E;margin-left:auto}

/* metrics grid inside card */
.pcard-body{padding:14px 20px 16px}
.metrics-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.metric{background:var(--sky);border-radius:8px;padding:10px 12px}
.metric-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;font-weight:600}
.metric-val{font-size:17px;font-weight:700;color:var(--navy);letter-spacing:-0.3px}
.metric-sub{font-size:11px;color:var(--muted);margin-top:2px}
.metric-delta{font-size:11px;font-weight:500}
.metric-delta.up{color:var(--green)}
.metric-delta.down{color:var(--red)}

/* divider row */
.pcard-divider{height:1px;background:var(--border);margin:0 0 14px}

/* detail rows */
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.detail-item{background:var(--sky);border-radius:7px;padding:9px 11px}
.detail-key{font-size:10px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:3px}
.detail-val{font-size:13px;font-weight:600;color:var(--navy)}

/* sentiment bar */
.sentiment-row{display:flex;align-items:center;gap:10px;margin-top:4px}
.sent-bar{flex:1;height:6px;background:var(--sky2);border-radius:99px;overflow:hidden}
.sent-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#12B76A,#3DE09A)}
.sent-val{font-size:12px;font-weight:700;color:var(--green);min-width:32px;text-align:right}

/* best time badge */
.badge-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.badge{font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;background:var(--sky2);color:var(--blue2)}
.badge.highlight{background:var(--blue);color:#fff}

/* footer action row */
.pcard-footer{border-top:1px solid var(--border);padding:10px 20px;display:flex;align-items:center;gap:8px;background:var(--sky)}
.pcard-footer span{font-size:12px;color:var(--muted)}
.pcard-footer strong{color:var(--navy);font-weight:600}

/* 3rd party section */
.apis-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:32px}
.api-card{background:var(--white);border:1.5px solid var(--border);border-radius:12px;padding:16px 18px;transition:border-color .15s}
.api-card:hover{border-color:var(--blue)}
.api-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.api-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.api-name{font-size:14px;font-weight:700;color:var(--navy)}
.api-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:auto;letter-spacing:0.5px}
.badge-sched{background:#EEF4FF;color:#3538CD}
.badge-anal{background:#F0FDF4;color:#166534}
.badge-list{background:#FFF7ED;color:#C2410C}
.badge-trend{background:#FDF4FF;color:#7E22CE}
.badge-ai{background:#F5F3FF;color:#5B21B6}
.badge-best{background:#0D3560;color:#fff}
.badge-create{background:#ECFDF5;color:#065F46}
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
<div class="wrap">

<!-- Header -->
<div class="page-header">
    <div>
        <div class="logo">Video<span>Vizard</span></div>
        <div style="font-size:13px;color:var(--muted);margin-top:2px">Platform Analytics · April 2026 · All platforms connected</div>
    </div>
    <div class="topbar-right">
        <button class="btn btn-outline">&#8681; Export Report</button>
        <button class="btn btn-primary">+ New Post</button>
    </div>
</div>

<!-- Summary strip -->
<div class="section-label">Total across all platforms (last 30 days)</div>
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
        <div class="sum-val">$<?= fmt(array_sum(array_column($platforms,'est_revenue'))) ?></div>
        <span class="sum-delta neu">CPM-based estimate</span>
    </div>
</div>

<!-- Platform cards -->
<div class="section-label">Per-platform breakdown</div>
<div class="platforms-grid">
<?php foreach ($platforms as $key => $p): ?>
<div class="pcard">
    <!-- Header -->
    <div class="pcard-header">
        <div class="picon" style="background:<?= $p['color'] ?>"><?= strtoupper($p['initial']) ?></div>
        <div>
            <div class="pname"><?= $p['name'] ?></div>
            <div style="font-size:12px;color:var(--muted)"><?= fmt($p['followers']) ?> followers</div>
        </div>
        <span class="pstatus">&#10003; Connected</span>
    </div>

    <div class="pcard-body">
        <!-- Core metrics -->
        <div class="metrics-row">
            <div class="metric">
                <div class="metric-label">Followers</div>
                <div class="metric-val"><?= fmt($p['followers']) ?></div>
                <div class="metric-delta up">&#8593; <?= $p['follower_delta'] ?>% month</div>
            </div>
            <div class="metric">
                <div class="metric-label">Reach</div>
                <div class="metric-val"><?= fmt($p['reach']) ?></div>
                <div class="metric-delta up">&#8593; <?= $p['reach_delta'] ?>%</div>
            </div>
            <div class="metric">
                <div class="metric-label">Impressions</div>
                <div class="metric-val"><?= fmt($p['impressions']) ?></div>
                <div class="metric-delta up">&#8593; <?= $p['imp_delta'] ?>%</div>
            </div>
            <div class="metric">
                <div class="metric-label">Eng. rate</div>
                <div class="metric-val"><?= $p['eng_rate'] ?>%</div>
                <div class="metric-delta <?= $p['eng_delta'] >= 0 ? 'up' : 'down' ?>"><?= $p['eng_delta'] >= 0 ? '&#8593;' : '&#8595;' ?> <?= abs($p['eng_delta']) ?>%</div>
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
            <div class="detail-item">
                <div class="detail-key">Saves</div>
                <div class="detail-val"><?= fmt($p['saves']) ?></div>
            </div>
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

        <!-- Sentiment -->
        <div style="margin-top:12px">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">Audience sentiment</div>
            <div class="sentiment-row">
                <div class="sent-bar"><div class="sent-fill" style="width:<?= $p['sentiment'] ?>%"></div></div>
                <div class="sent-val"><?= $p['sentiment'] ?>%</div>
            </div>
        </div>

        <!-- Badges -->
        <div class="badge-row">
            <span class="badge highlight">Best time: <?= $p['top_time'] ?></span>
            <span class="badge">Best format: <?= $p['best_format'] ?></span>
            <span class="badge">Est. CPM: $<?= number_format($p['cpm'],2) ?></span>
            <span class="badge">Est. revenue: $<?= fmt($p['est_revenue']) ?>/mo</span>
        </div>
    </div>

    <div class="pcard-footer">
        <span>Est. monthly revenue from this platform:</span>
        <strong>$<?= number_format($p['est_revenue']) ?></strong>
        <span style="margin-left:auto">CPM $<?= number_format($p['cpm'],2) ?> &middot; <?= fmt($p['video_views']) ?> views</span>
    </div>
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
    VideoVizard &mdash; All data is dummy/demo. Replace with live API calls per platform.
</div>

</div>
</body>
</html>
