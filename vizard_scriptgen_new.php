<?php
session_start();
ini_set('session.gc_maxlifetime', 15552000);
ini_set('session.cookie_lifetime', 15552000);
session_set_cookie_params(15552000);
if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit; }


$admin_id   = (int)$_SESSION['admin_id'];
$company_id = (int)($_SESSION['company_id'] ?? 0);

$plan_row      = mysqli_fetch_assoc(mysqli_query($conn, "SELECT plan_type FROM hdb_users WHERE id='$admin_id' LIMIT 1"));
$plan_type     = $plan_row['plan_type'] ?? 'free_trial';
$is_free_trial = ($plan_type === 'free_trial');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard — Choose Your Video Type</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --dark-blue: #0f2a44;  --mid-blue: #143b63;   --accent: #5fd1ff;
  --purple: #8b5cf6;     --purple-lt: #ede9fe;   --green: #10b981;
  --orange: #f59e0b;     --orange-lt: #fef3c7;   --text: #1e293b;
  --muted: #64748b;      --border: #e2e8f0;       --bg: #f8fafc;
  --card: #ffffff;       --shadow: 0 4px 12px rgba(0,0,0,0.08);
}
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* ── Header ─────────────────────────────────────────────────── */
.vidora-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  color: #fff;
  box-shadow: 0 3px 10px rgba(0,0,0,0.15);
  position: sticky;
  top: 0;
  z-index: 1000;
}
.brand-link  { text-decoration: none; display: flex; align-items: center; gap: 8px; }
.brand-icon  { font-size: 24px; }
.brand-name  { font-size: 18px; font-weight: 700; }
.brand-video { color: #fff; }
.brand-vizard{ color: #5fd1ff; }
.back-link { font-size: 13px; font-weight: 600; color: rgba(255,255,255,.75); text-decoration: none; display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1.5px solid rgba(255,255,255,.25); border-radius: 8px; transition: all .15s; }
.back-link:hover { color: #fff; background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.45); }

/* ── Page wrapper ───────────────────────────────────────────── */
.page-wrap {
  flex: 1;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 36px 16px 60px;
}

/* ── Main card ──────────────────────────────────────────────── */
.wiz-card {
  background: var(--card);
  border-radius: 16px;
  border: 1px solid var(--border);
  box-shadow: var(--shadow);
  width: 100%;
  max-width: 780px;
  overflow: hidden;
}
.wiz-card-header {
  padding: 22px 28px 20px;
  background: linear-gradient(90deg, #0f2a44, #143b63);
  border-bottom: 1px solid var(--border);
}
.wiz-card-header h1 { font-size: 21px; font-weight: 700; color: #fff; margin: 0 0 4px; }
.wiz-card-header p  { font-size: 13px; color: rgba(255,255,255,.7); margin: 0; }
.wiz-card-body { padding: 28px 28px 32px; }

/* ── Step label ─────────────────────────────────────────────── */
.step-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.step-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* ── Option cards grid ──────────────────────────────────────── */
.options-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}
@media (max-width: 640px) {
  .options-grid { grid-template-columns: 1fr; }
  .wiz-card-body { padding: 20px 16px 28px; }
}

/* ── Individual option card ─────────────────────────────────── */
.option-card {
  border: 2px solid var(--border);
  border-radius: 16px;
  padding: 24px 20px 20px;
  background: var(--card);
  cursor: pointer;
  transition: all .22s cubic-bezier(.16,1,.3,1);
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0;
  text-decoration: none;
  color: inherit;
}
.option-card:hover {
  border-color: var(--purple);
  box-shadow: 0 8px 28px rgba(139,92,246,0.15);
  transform: translateY(-3px);
}
.option-card:active {
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(139,92,246,0.12);
}

/* Accent line at top of each card */
.option-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  border-radius: 14px 14px 0 0;
  opacity: 0;
  transition: opacity .2s;
}
.option-card:hover::before { opacity: 1; }
.option-card.card-story::before   { background: linear-gradient(90deg, #8b5cf6, #5fd1ff); }
.option-card.card-business::before{ background: linear-gradient(90deg, #f59e0b, #ef4444); }
.option-card.card-product::before { background: linear-gradient(90deg, #10b981, #3b82f6); }
.option-card.card-tools::before   { background: linear-gradient(90deg, #ec4899, #f97316); }

/* Icon */
.option-icon {
  font-size: 36px;
  margin-bottom: 14px;
  display: block;
  line-height: 1;
}

/* Title */
.option-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--dark-blue);
  margin-bottom: 10px;
  line-height: 1.3;
}

/* "Best for" tag */
.option-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 20px;
  margin-bottom: 14px;
  width: fit-content;
}
.tag-story    { background: #ede9fe; color: #6d28d9; }
.tag-business { background: #fef3c7; color: #92400e; }
.tag-product  { background: #d1fae5; color: #065f46; }

/* "Creates" meta line */
.option-creates {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .06em;
  margin-bottom: 6px;
}

/* Pipeline steps */
.option-pipeline {
  font-size: 12px;
  color: var(--muted);
  line-height: 1.6;
  flex: 1;
  margin-bottom: 16px;
}
.option-pipeline .pipe-step {
  display: inline-block;
  background: #f1f5f9;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 2px 7px;
  font-size: 11px;
  font-weight: 500;
  color: var(--dark-blue);
  margin: 2px 2px 2px 0;
}

/* Description */
.option-desc {
  font-size: 13px;
  color: var(--muted);
  line-height: 1.55;
  margin-bottom: 18px;
  flex: 1;
}

/* CTA row */
.option-cta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 14px;
  border-top: 1px solid var(--border);
  margin-top: auto;
}
.option-cta-text {
  font-size: 13px;
  font-weight: 700;
  color: var(--purple);
}
.option-cta-arrow {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: var(--purple-lt);
  color: var(--purple);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  transition: all .18s;
}
.option-card:hover .option-cta-arrow {
  background: var(--purple);
  color: #fff;
  transform: translateX(3px);
}
.option-card.card-business:hover .option-cta-arrow {
  background: var(--orange);
  border-color: var(--orange);
}
.option-card.card-business .option-cta-text { color: var(--orange); }
.option-card.card-business .option-cta-arrow { background: var(--orange-lt); color: #92400e; }
.option-card.card-product:hover  .option-cta-arrow {
  background: var(--green);
}
.option-card.card-product  .option-cta-text { color: var(--green); }
.option-card.card-tools:hover .option-cta-arrow  { background: #ec4899; }
.option-card.card-tools  .option-cta-text  { color: #ec4899; }
.option-card.card-tools  .option-cta-arrow { background: #fdf2f8; color: #be185d; }
.tag-tools { background: #fdf2f8; color: #be185d; }
.option-card.card-product  .option-cta-arrow { background: #d1fae5; color: #065f46; }

/* ── Good for footer note ───────────────────────────────────── */
.good-for {
  font-size: 11px;
  color: #aaa;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed var(--border);
  line-height: 1.5;
}

/* ── Credit cost badge ──────────────────────────────────────── */
.credit-cost {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 16px;
  padding: 9px 12px;
  border-radius: 10px;
  border: 1.5px solid var(--border);
  background: #f8fafc;
}
.credit-coin {
  font-size: 18px;
  line-height: 1;
  flex-shrink: 0;
}
.credit-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.credit-amount {
  font-size: 14px;
  font-weight: 800;
  line-height: 1.2;
}
.credit-sub {
  font-size: 11px;
  color: var(--muted);
  font-weight: 400;
}
/* Per-card credit colour tints */
.card-story   .credit-cost { background: #f5f3ff; border-color: #ddd6fe; }
.card-story   .credit-amount { color: #6d28d9; }
.card-business .credit-cost { background: #fffbeb; border-color: #fde68a; }
.card-business .credit-amount { color: #92400e; }
.card-product  .credit-cost { background: #f0fdf4; border-color: #bbf7d0; }
.card-tools    .credit-cost { background: #fdf2f8; border-color: #fbcfe8; }
.card-tools    .credit-amount { color: #be185d; }
.card-product  .credit-amount { color: #065f46; }

/* ── Divider line between tag and description ───────────────── */
.divider { height: 1px; background: var(--border); margin: 14px 0; }

/* ── Hover press animation ──────────────────────────────────── */
@keyframes vsSlide {
  from { opacity:0; transform:translateY(12px); }
  to   { opacity:1; transform:translateY(0);    }
}
.options-grid .option-card { animation: vsSlide .35s cubic-bezier(.16,1,.3,1) both; }
.options-grid .option-card:nth-child(1) { animation-delay: .04s; }
.options-grid .option-card:nth-child(2) { animation-delay: .10s; }
.options-grid .option-card:nth-child(3) { animation-delay: .16s; }
.options-grid .option-card:nth-child(4) { animation-delay: .22s; }
</style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────── -->
<header class="vidora-header">
  <a class="brand-link" href="videovizard.php">
    <span class="brand-icon">🎬</span>
    <span class="brand-name">
      <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
    </span>
  </a>
  <a class="back-link" href="vizard_browser.php">← Dashboard</a>
</header>

<!-- ── Page ────────────────────────────────────────────────────── -->
<div class="page-wrap">
  <div class="wiz-card">

    <div class="wiz-card-header">
      <h1>🎬 What kind of video do you want to create?</h1>
      <p>Choose a format below — each one is optimised for a different goal</p>
    </div>

    <div class="wiz-card-body">
      <div class="step-label">Step 1 of 1 — Choose your video type</div>

      <div class="options-grid">

        <!-- ── Option 1: Story Video ─────────────────────────── -->
        <a class="option-card card-story" href="vizard_scriptgen_1.php">
          <span class="option-icon">📖</span>
          <div class="option-title">Story Video/Brolls / Podcasts</div>
          <span class="option-tag tag-story">✦ Storytelling &amp; Education</span>

          <div class="option-desc">
            Explainers, educational breakdowns, news stories, and slideshows — built scene by scene with full voiceover.
          </div>

          <div class="credit-cost">
            <span class="credit-coin">💳</span>
            <span class="credit-text">
              <span class="credit-amount">1 credit / 30 sec</span>
              <span class="credit-sub">Most affordable option</span>
            </span>
          </div>

          <div class="good-for">
            🎙 Great for podcasts, long-form content, slideshows &amp; educational series
          </div>

          <div class="option-cta">
            <span class="option-cta-text">Start Story →</span>
            <span class="option-cta-arrow">›</span>
          </div>
        </a>

        <!-- ── Option 2: Cinematic Business Video ────────────── -->
        <a class="option-card card-business" href="vizard_scriptgen_2.php">
          <span class="option-icon">🏢</span>
          <div class="option-title">Cinematic Business Video</div>
          <span class="option-tag tag-business">✦ Services &amp; Local Business</span>

          <div class="option-desc">
            Emotional, cinematic, or promotional ads for spas, gyms, salons, clinics, banquet halls and service brands.
          </div>

          <div class="credit-cost">
            <span class="credit-coin">💳</span>
            <span class="credit-text">
              <span class="credit-amount">5 credits / 30 sec</span>
              <span class="credit-sub">Cinematic AI visuals included</span>
            </span>
          </div>

          <div class="good-for">
            💆 Great for luxury, funny, emotional or promotional brand ads
          </div>

          <div class="option-cta">
            <span class="option-cta-text">Start Business Ad →</span>
            <span class="option-cta-arrow">›</span>
          </div>
        </a>

        <!-- ── Option 3: Product Promotion Video ─────────────── -->
        <a class="option-card card-product" href="vizard_scriptgen_3.php">
          <span class="option-icon">📦</span>
          <div class="option-title">Product Promotion Video</div>
          <span class="option-tag tag-product">✦ Products &amp; Ecommerce</span>

          <div class="option-desc">
            Showcase jewellery, food, fashion, boutique items and ecommerce products with product-focused visuals that sell.
          </div>

          <div class="credit-cost">
            <span class="credit-coin">💳</span>
            <span class="credit-text">
              <span class="credit-amount">6 credits / 30 sec</span>
              <span class="credit-sub">Product hero shots + lifestyle scenes</span>
            </span>
          </div>

          <div class="good-for">
            🛍 Great for restaurants, fashion, jewellery, ecommerce &amp; showcase reels
          </div>

          <div class="option-cta">
            <span class="option-cta-text">Start Product Video →</span>
            <span class="option-cta-arrow">›</span>
          </div>
        </a>


        <!-- ── Option 4: AI Generation Tools ────────────────── -->
        <a class="option-card card-tools" href="vizard_ai_tools.php">
          <span class="option-icon">🛠️</span>
          <div class="option-title">AI Generation Tools</div>
          <span class="option-tag tag-tools">✦ Text · Image · Video</span>

          <div class="option-desc">
            Standalone AI tools — generate images from text, animate images into video, transform existing footage, or create video directly from a prompt.
          </div>

          <div class="credit-cost">
            <span class="credit-coin">💳</span>
            <span class="credit-text">
              <span class="credit-amount">Varies per tool</span>
              <span class="credit-sub">Pay only for what you generate</span>
            </span>
          </div>

          <div class="good-for">
            🖼 Text → Image &nbsp;·&nbsp; 🎞 Text → Video &nbsp;·&nbsp; 🖼→🎞 Image → Video &nbsp;·&nbsp; 🎬 Video → Video
          </div>

          <div class="option-cta">
            <span class="option-cta-text">Open Tools →</span>
            <span class="option-cta-arrow">›</span>
          </div>
        </a>

      </div><!-- /options-grid -->
    </div><!-- /wiz-card-body -->

  </div><!-- /wiz-card -->
</div><!-- /page-wrap -->

</body>
</html>
	