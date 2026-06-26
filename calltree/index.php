<?php
$page_title = "CallMind AI — Intelligent Calling Agents for Real Estate & Finance";
$page_desc  = "Automate your outbound prospecting and inbound lead capture with AI calling agents built for real estate agents and financial advisors.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?></title>
<meta name="description" content="<?= htmlspecialchars($page_desc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;0,9..144,700;1,9..144,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ─────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --ink:       #0a0e1a;
  --ink-soft:  #3d4460;
  --ink-mute:  #7a8099;
  --cream:     #f5f0e8;
  --cream-dark:#ede7d9;
  --gold:      #c8973a;
  --gold-lt:   #e8b95a;
  --teal:      #1a7a6e;
  --teal-lt:   #22a090;
  --teal-pale: #d4efec;
  --white:     #ffffff;
  --card-bg:   #ffffff;
  --shadow-sm: 0 2px 8px rgba(10,14,26,.07);
  --shadow-md: 0 8px 32px rgba(10,14,26,.10);
  --shadow-lg: 0 24px 64px rgba(10,14,26,.14);
  --radius:    16px;
  --ff-display: 'Fraunces', Georgia, serif;
  --ff-body:    'DM Sans', sans-serif;
}

html { scroll-behavior: smooth; }

body {
  font-family: var(--ff-body);
  background: var(--cream);
  color: var(--ink);
  line-height: 1.6;
  overflow-x: hidden;
}

/* ── Noise texture overlay ────────────────────────────────── */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 1000;
  opacity: .5;
}

/* ── Typography ───────────────────────────────────────────── */
h1, h2, h3 { font-family: var(--ff-display); font-weight: 600; line-height: 1.15; }
p { color: var(--ink-soft); }
a { text-decoration: none; color: inherit; }

/* ── Layout ───────────────────────────────────────────────── */
.container { max-width: 1140px; margin: 0 auto; padding: 0 24px; }
.section    { padding: 100px 0; }

/* ── NAV ──────────────────────────────────────────────────── */
nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 900;
  padding: 18px 0;
  transition: background .3s, box-shadow .3s;
}
nav.scrolled {
  background: rgba(245,240,232,.92);
  backdrop-filter: blur(12px);
  box-shadow: 0 1px 0 rgba(10,14,26,.08);
}
.nav-inner {
  display: flex; align-items: center; justify-content: space-between;
  max-width: 1140px; margin: 0 auto; padding: 0 24px;
}
.nav-logo {
  display: flex; align-items: center; gap: 10px;
}
.nav-logo-mark {
  width: 36px; height: 36px;
  background: var(--ink);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.nav-logo-mark svg { width: 20px; height: 20px; }
.nav-logo-name {
  font-family: var(--ff-display);
  font-weight: 700; font-size: 20px;
  letter-spacing: -.02em;
}
.nav-logo-name em { color: var(--teal); font-style: normal; }

.nav-links {
  display: flex; align-items: center; gap: 32px;
  list-style: none;
}
.nav-links a {
  font-size: 14px; font-weight: 500; color: var(--ink-soft);
  transition: color .2s;
}
.nav-links a:hover { color: var(--ink); }

.nav-cta {
  display: flex; align-items: center; gap: 12px;
}
.btn-ghost {
  font-size: 14px; font-weight: 600; color: var(--ink);
  padding: 9px 20px; border-radius: 10px;
  border: 1.5px solid rgba(10,14,26,.15);
  transition: all .2s;
}
.btn-ghost:hover { border-color: var(--ink); background: var(--ink); color: var(--white); }
.btn-primary {
  font-size: 14px; font-weight: 600; color: var(--white);
  padding: 10px 22px; border-radius: 10px;
  background: var(--teal);
  border: none; cursor: pointer;
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 6px;
  font-family: var(--ff-body);
}
.btn-primary:hover { background: var(--teal-lt); transform: translateY(-1px); }
.btn-primary.large { font-size: 16px; padding: 14px 30px; border-radius: 12px; }
.btn-gold {
  font-size: 16px; font-weight: 600; color: var(--ink);
  padding: 14px 32px; border-radius: 12px;
  background: var(--gold);
  border: none; cursor: pointer;
  transition: all .2s;
  display: inline-flex; align-items: center; gap: 8px;
  font-family: var(--ff-body);
}
.btn-gold:hover { background: var(--gold-lt); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(200,151,58,.3); }

/* ── HERO ─────────────────────────────────────────────────── */
.hero {
  min-height: 100vh;
  display: flex; align-items: center;
  padding-top: 80px;
  position: relative;
  overflow: hidden;
}
.hero-bg {
  position: absolute; inset: 0; z-index: 0;
  background:
    radial-gradient(ellipse 70% 60% at 80% 20%, rgba(26,122,110,.12) 0%, transparent 60%),
    radial-gradient(ellipse 50% 50% at 10% 80%, rgba(200,151,58,.08) 0%, transparent 60%);
}
.hero-grid-lines {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(10,14,26,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(10,14,26,.04) 1px, transparent 1px);
  background-size: 60px 60px;
}
.hero-inner {
  position: relative; z-index: 1;
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 60px; align-items: center;
  max-width: 1140px; margin: 0 auto; padding: 0 24px;
  width: 100%;
}
.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--teal-pale); color: var(--teal);
  padding: 6px 14px; border-radius: 100px;
  font-size: 12px; font-weight: 600; letter-spacing: .05em;
  text-transform: uppercase; margin-bottom: 24px;
}
.hero-badge span { width: 6px; height: 6px; background: var(--teal); border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }

@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%       { opacity: .5; transform: scale(1.4); }
}

.hero-title {
  font-size: clamp(40px, 5vw, 62px);
  line-height: 1.1;
  letter-spacing: -.03em;
  margin-bottom: 20px;
  color: var(--ink);
}
.hero-title em {
  font-style: italic;
  color: var(--teal);
}
.hero-title .gold { color: var(--gold); }

.hero-sub {
  font-size: 18px; line-height: 1.65;
  color: var(--ink-soft);
  max-width: 480px;
  margin-bottom: 36px;
}

.hero-actions { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.hero-note { font-size: 12px; color: var(--ink-mute); margin-top: 12px; }

.hero-stats {
  display: flex; gap: 32px; margin-top: 48px;
  padding-top: 36px;
  border-top: 1px solid rgba(10,14,26,.08);
}
.hero-stat .num {
  font-family: var(--ff-display);
  font-size: 32px; font-weight: 700;
  color: var(--ink); line-height: 1;
}
.hero-stat .lbl {
  font-size: 12px; color: var(--ink-mute); margin-top: 4px;
  font-weight: 500; letter-spacing: .02em;
}

/* ── Hero visual panel ────────────────────────────────────── */
.hero-visual {
  position: relative;
}
.hero-card-main {
  background: var(--ink);
  border-radius: 20px;
  padding: 28px;
  box-shadow: var(--shadow-lg);
  color: var(--white);
  position: relative;
  overflow: hidden;
}
.hero-card-main::before {
  content: '';
  position: absolute;
  top: -60px; right: -60px;
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(26,122,110,.4), transparent 70%);
  border-radius: 50%;
}
.hc-label {
  font-size: 10px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: var(--teal-lt);
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 6px;
}
.hc-label::before { content: ''; width: 6px; height: 6px; background: var(--teal-lt); border-radius: 50%; display: block; }

.call-row {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid rgba(255,255,255,.07);
}
.call-row:last-child { border-bottom: none; }
.call-avatar {
  width: 36px; height: 36px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.call-info { flex: 1; }
.call-name { font-size: 13px; font-weight: 600; color: var(--white); }
.call-detail { font-size: 11px; color: rgba(255,255,255,.45); margin-top: 2px; }
.call-status {
  font-size: 11px; font-weight: 700; padding: 4px 10px;
  border-radius: 100px; white-space: nowrap;
}
.call-status.live    { background: rgba(26,122,110,.3); color: #4ecdc4; animation: livepulse 1.5s infinite; }
.call-status.booked  { background: rgba(200,151,58,.2); color: var(--gold-lt); }
.call-status.queued  { background: rgba(255,255,255,.08); color: rgba(255,255,255,.5); }

@keyframes livepulse { 0%,100%{opacity:1} 50%{opacity:.6} }

.hc-metric-row {
  display: grid; grid-template-columns: 1fr 1fr 1fr;
  gap: 12px; margin-top: 20px;
}
.hc-metric {
  background: rgba(255,255,255,.06);
  border-radius: 12px; padding: 14px;
  text-align: center;
}
.hc-metric .mv {
  font-family: var(--ff-display);
  font-size: 22px; font-weight: 700; color: var(--white);
}
.hc-metric .ml { font-size: 10px; color: rgba(255,255,255,.4); margin-top: 2px; letter-spacing: .04em; }

/* Floating mini cards */
.float-card {
  position: absolute;
  background: var(--white);
  border-radius: 14px;
  padding: 14px 18px;
  box-shadow: var(--shadow-md);
  display: flex; align-items: center; gap: 10px;
  animation: floatY 4s ease-in-out infinite;
}
.float-card.fc1 { top: -24px; right: -20px; animation-delay: 0s; }
.float-card.fc2 { bottom: -20px; left: -24px; animation-delay: 2s; }

@keyframes floatY {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}
.fc-icon { font-size: 22px; }
.fc-text .fct { font-size: 13px; font-weight: 700; color: var(--ink); }
.fc-text .fcl { font-size: 11px; color: var(--ink-mute); }

/* ── LOGOS / TRUST BAR ────────────────────────────────────── */
.trust-bar {
  padding: 40px 0;
  border-top: 1px solid rgba(10,14,26,.06);
  border-bottom: 1px solid rgba(10,14,26,.06);
}
.trust-label {
  text-align: center;
  font-size: 11px; font-weight: 700;
  letter-spacing: .1em; text-transform: uppercase;
  color: var(--ink-mute); margin-bottom: 28px;
}
.trust-logos {
  display: flex; align-items: center; justify-content: center;
  gap: 48px; flex-wrap: wrap;
}
.trust-logo {
  font-family: var(--ff-display);
  font-size: 18px; font-weight: 700;
  color: var(--ink-mute); opacity: .5;
  letter-spacing: -.02em;
  transition: opacity .2s;
}
.trust-logo:hover { opacity: .8; }

/* ── HOW IT WORKS ─────────────────────────────────────────── */
.how-section { background: var(--ink); color: var(--white); }
.how-section .section-tag {
  color: var(--teal-lt);
}
.how-section h2 { color: var(--white); }
.how-section p { color: rgba(255,255,255,.55); }

.section-tag {
  font-size: 11px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: var(--teal);
  margin-bottom: 12px; display: block;
}
.section-title {
  font-size: clamp(28px, 4vw, 46px);
  margin-bottom: 16px;
  letter-spacing: -.03em;
}
.section-sub {
  font-size: 17px; line-height: 1.6;
  max-width: 520px;
  margin-bottom: 60px;
}

.steps-grid {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 2px;
}
.step-card {
  background: rgba(255,255,255,.04);
  padding: 36px 28px;
  position: relative;
  transition: background .2s;
}
.step-card:hover { background: rgba(255,255,255,.07); }
.step-card:first-child { border-radius: 16px 0 0 16px; }
.step-card:last-child  { border-radius: 0 16px 16px 0; }

.step-num {
  font-family: var(--ff-display);
  font-size: 48px; font-weight: 700;
  color: rgba(255,255,255,.07);
  line-height: 1; margin-bottom: 20px;
}
.step-icon {
  font-size: 28px; margin-bottom: 16px;
  display: block;
}
.step-title {
  font-size: 17px; font-weight: 600;
  color: var(--white); margin-bottom: 10px;
}
.step-desc { font-size: 14px; line-height: 1.65; }

/* connector line */
.step-card::after {
  content: '→';
  position: absolute; right: -14px; top: 50%;
  transform: translateY(-50%);
  font-size: 18px; color: var(--teal);
  z-index: 2;
}
.step-card:last-child::after { display: none; }

/* ── USE CASES ────────────────────────────────────────────── */
.use-cases-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 24px;
}
.use-case-card {
  background: var(--white);
  border-radius: var(--radius);
  padding: 36px;
  box-shadow: var(--shadow-sm);
  border: 1px solid rgba(10,14,26,.06);
  transition: all .3s;
  position: relative;
  overflow: hidden;
}
.use-case-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0;
  height: 3px;
}
.use-case-card.re::before  { background: linear-gradient(90deg, var(--teal), var(--teal-lt)); }
.use-case-card.fin::before { background: linear-gradient(90deg, var(--gold), var(--gold-lt)); }
.use-case-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }

.uc-icon { font-size: 36px; margin-bottom: 20px; display: block; }
.uc-niche {
  font-size: 11px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; margin-bottom: 8px;
}
.uc-niche.re  { color: var(--teal); }
.uc-niche.fin { color: var(--gold); }
.uc-title { font-size: 22px; margin-bottom: 12px; }
.uc-desc  { font-size: 15px; line-height: 1.65; margin-bottom: 24px; }
.uc-list  { list-style: none; display: flex; flex-direction: column; gap: 10px; }
.uc-list li {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 14px; color: var(--ink-soft);
}
.uc-list li::before {
  content: '✓';
  width: 18px; height: 18px;
  background: var(--teal-pale); color: var(--teal);
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; flex-shrink: 0; margin-top: 2px;
}
.uc-list.gold li::before { background: rgba(200,151,58,.12); color: var(--gold); }

/* ── FEATURES ─────────────────────────────────────────────── */
.features-section { background: var(--cream-dark); }
.features-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.feat-card {
  background: var(--white);
  border-radius: var(--radius);
  padding: 30px;
  box-shadow: var(--shadow-sm);
  border: 1px solid rgba(10,14,26,.05);
  transition: all .25s;
}
.feat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.feat-icon {
  width: 48px; height: 48px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; margin-bottom: 18px;
}
.feat-icon.teal { background: var(--teal-pale); }
.feat-icon.gold { background: rgba(200,151,58,.12); }
.feat-icon.ink  { background: rgba(10,14,26,.06); }
.feat-title { font-size: 16px; font-weight: 600; margin-bottom: 8px; color: var(--ink); }
.feat-desc  { font-size: 14px; line-height: 1.6; color: var(--ink-soft); }

/* ── PRICING ──────────────────────────────────────────────── */
.pricing-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px; align-items: start;
}
.price-card {
  background: var(--white);
  border-radius: 20px;
  padding: 36px;
  border: 1.5px solid rgba(10,14,26,.08);
  transition: all .3s;
  position: relative;
}
.price-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.price-card.featured {
  background: var(--ink);
  border-color: var(--ink);
  transform: scale(1.04);
}
.price-card.featured:hover { transform: scale(1.04) translateY(-4px); }
.price-badge {
  position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
  background: var(--gold); color: var(--ink);
  font-size: 11px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; padding: 5px 16px; border-radius: 100px;
}
.price-name {
  font-size: 13px; font-weight: 700; letter-spacing: .08em;
  text-transform: uppercase; margin-bottom: 6px;
}
.price-name.light { color: rgba(255,255,255,.6); }
.price-name.dark  { color: var(--ink-mute); }
.price-amount {
  font-family: var(--ff-display);
  font-size: 52px; font-weight: 700; line-height: 1;
  margin-bottom: 6px;
}
.price-amount.light { color: var(--white); }
.price-period { font-size: 13px; color: var(--ink-mute); margin-bottom: 28px; }
.price-period.light { color: rgba(255,255,255,.4); }
.price-divider { height: 1px; background: rgba(10,14,26,.08); margin-bottom: 24px; }
.price-divider.light { background: rgba(255,255,255,.1); }
.price-list { list-style: none; display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px; }
.price-list li {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 14px;
}
.price-list li.light { color: rgba(255,255,255,.75); }
.price-list li .chk {
  width: 16px; height: 16px; border-radius: 50%;
  background: var(--teal-pale); color: var(--teal);
  display: flex; align-items: center; justify-content: center;
  font-size: 9px; font-weight: 700; flex-shrink: 0; margin-top: 2px;
}
.price-list li.light .chk { background: rgba(26,122,110,.3); color: var(--teal-lt); }

.btn-outline-white {
  display: block; text-align: center;
  padding: 13px; border-radius: 12px;
  border: 1.5px solid rgba(255,255,255,.25);
  color: var(--white); font-weight: 600; font-size: 15px;
  transition: all .2s;
}
.btn-outline-white:hover { border-color: var(--white); background: rgba(255,255,255,.08); }
.btn-outline-ink {
  display: block; text-align: center;
  padding: 13px; border-radius: 12px;
  border: 1.5px solid rgba(10,14,26,.15);
  color: var(--ink); font-weight: 600; font-size: 15px;
  transition: all .2s;
}
.btn-outline-ink:hover { border-color: var(--ink); background: var(--ink); color: var(--white); }
.btn-gold-block {
  display: block; text-align: center;
  padding: 13px; border-radius: 12px;
  background: var(--gold);
  color: var(--ink); font-weight: 700; font-size: 15px;
  transition: all .2s;
}
.btn-gold-block:hover { background: var(--gold-lt); }

/* ── TESTIMONIALS ─────────────────────────────────────────── */
.testimonials-section { background: var(--ink); }
.testimonials-section .section-tag { color: var(--teal-lt); }
.testimonials-section .section-title { color: var(--white); }

.testi-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.testi-card {
  background: rgba(255,255,255,.05);
  border-radius: var(--radius);
  padding: 30px;
  border: 1px solid rgba(255,255,255,.07);
  transition: background .2s;
}
.testi-card:hover { background: rgba(255,255,255,.08); }
.testi-stars { color: var(--gold); font-size: 14px; margin-bottom: 16px; letter-spacing: 2px; }
.testi-quote {
  font-size: 15px; line-height: 1.7;
  color: rgba(255,255,255,.75);
  margin-bottom: 24px;
  font-style: italic;
  font-family: var(--ff-display);
  font-weight: 300;
}
.testi-author { display: flex; align-items: center; gap: 12px; }
.testi-avatar {
  width: 40px; height: 40px; border-radius: 10px;
  background: var(--teal);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; color: var(--white); font-size: 16px;
}
.testi-name { font-size: 14px; font-weight: 600; color: var(--white); }
.testi-role { font-size: 12px; color: rgba(255,255,255,.4); }

/* ── CTA SECTION ──────────────────────────────────────────── */
.cta-section {
  text-align: center;
  background: linear-gradient(135deg, var(--teal) 0%, #0f5a51 100%);
  color: var(--white);
  padding: 100px 0;
  position: relative; overflow: hidden;
}
.cta-section::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 60% 80% at 50% 0%, rgba(255,255,255,.08), transparent 60%);
}
.cta-inner { position: relative; z-index: 1; }
.cta-title {
  font-size: clamp(32px, 4vw, 52px);
  color: var(--white); margin-bottom: 16px;
  letter-spacing: -.03em;
}
.cta-sub {
  font-size: 18px; color: rgba(255,255,255,.7);
  max-width: 480px; margin: 0 auto 40px;
}
.cta-actions { display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; }
.btn-white {
  background: var(--white); color: var(--teal);
  font-size: 16px; font-weight: 700; padding: 15px 34px;
  border-radius: 12px; border: none; cursor: pointer;
  transition: all .2s; display: inline-flex; align-items: center; gap: 8px;
  font-family: var(--ff-body);
}
.btn-white:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,.2); }
.btn-outline-white-lg {
  background: transparent; color: var(--white);
  font-size: 16px; font-weight: 600; padding: 14px 32px;
  border-radius: 12px; border: 1.5px solid rgba(255,255,255,.4);
  transition: all .2s; display: inline-flex; align-items: center; gap: 8px;
  font-family: var(--ff-body); cursor: pointer;
}
.btn-outline-white-lg:hover { border-color: var(--white); background: rgba(255,255,255,.1); }

/* ── FOOTER ───────────────────────────────────────────────── */
footer {
  background: #060910;
  color: rgba(255,255,255,.4);
  padding: 60px 0 32px;
}
.footer-grid {
  display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 40px; margin-bottom: 48px;
}
.footer-brand-name {
  font-family: var(--ff-display);
  font-size: 22px; font-weight: 700;
  color: var(--white); margin-bottom: 12px;
}
.footer-brand-name em { color: var(--teal-lt); font-style: normal; }
.footer-brand-desc { font-size: 14px; line-height: 1.65; max-width: 260px; }
.footer-col h4 {
  font-size: 12px; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.6);
  margin-bottom: 16px;
}
.footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 10px; }
.footer-col ul a { font-size: 14px; color: rgba(255,255,255,.4); transition: color .2s; }
.footer-col ul a:hover { color: var(--white); }
.footer-bottom {
  border-top: 1px solid rgba(255,255,255,.06);
  padding-top: 28px;
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
}
.footer-bottom p { font-size: 13px; }
.footer-links { display: flex; gap: 20px; }
.footer-links a { font-size: 13px; color: rgba(255,255,255,.3); transition: color .2s; }
.footer-links a:hover { color: var(--white); }

/* ── DEMO MODAL ───────────────────────────────────────────── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(6,9,16,.7);
  backdrop-filter: blur(6px);
  z-index: 9000; align-items: center; justify-content: center;
  padding: 20px;
}
.modal-overlay.open { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }

.modal-box {
  background: var(--white);
  border-radius: 24px;
  padding: 48px;
  max-width: 480px; width: 100%;
  box-shadow: 0 40px 100px rgba(0,0,0,.3);
  animation: modalUp .3s ease;
  position: relative;
}
@keyframes modalUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
.modal-close {
  position: absolute; top: 20px; right: 20px;
  width: 32px; height: 32px; border-radius: 8px;
  background: rgba(10,14,26,.06); border: none;
  cursor: pointer; font-size: 16px; color: var(--ink-soft);
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.modal-close:hover { background: rgba(10,14,26,.12); }
.modal-title {
  font-family: var(--ff-display);
  font-size: 28px; font-weight: 600; margin-bottom: 6px;
}
.modal-sub { font-size: 14px; color: var(--ink-mute); margin-bottom: 28px; }

.form-group { margin-bottom: 16px; }
.form-label {
  display: block; font-size: 12px; font-weight: 600;
  color: var(--ink); margin-bottom: 6px; letter-spacing: .03em;
}
.form-input {
  width: 100%; padding: 11px 14px;
  border: 1.5px solid rgba(10,14,26,.12);
  border-radius: 10px; font-size: 14px;
  font-family: var(--ff-body); color: var(--ink);
  outline: none; transition: border-color .15s;
  background: var(--white);
}
.form-input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(26,122,110,.08); }
.form-select {
  width: 100%; padding: 11px 14px;
  border: 1.5px solid rgba(10,14,26,.12);
  border-radius: 10px; font-size: 14px;
  font-family: var(--ff-body); color: var(--ink);
  outline: none; background: var(--white);
  cursor: pointer;
}
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.btn-submit {
  width: 100%; padding: 14px;
  background: var(--teal); color: var(--white);
  border: none; border-radius: 12px;
  font-size: 16px; font-weight: 700;
  font-family: var(--ff-body); cursor: pointer;
  transition: all .2s; margin-top: 8px;
}
.btn-submit:hover { background: var(--teal-lt); }
.form-note { font-size: 11px; color: var(--ink-mute); text-align: center; margin-top: 12px; }

/* ── ANIMATIONS ───────────────────────────────────────────── */
.reveal {
  opacity: 0; transform: translateY(28px);
  transition: opacity .7s ease, transform .7s ease;
}
.reveal.visible { opacity: 1; transform: translateY(0); }

/* ── RESPONSIVE ───────────────────────────────────────────── */
@media (max-width: 900px) {
  .hero-inner { grid-template-columns: 1fr; }
  .hero-visual { display: none; }
  .steps-grid { grid-template-columns: 1fr 1fr; }
  .step-card::after { display: none; }
  .use-cases-grid { grid-template-columns: 1fr; }
  .features-grid { grid-template-columns: 1fr 1fr; }
  .pricing-grid { grid-template-columns: 1fr; }
  .price-card.featured { transform: none; }
  .testi-grid { grid-template-columns: 1fr; }
  .footer-grid { grid-template-columns: 1fr 1fr; }
  .nav-links { display: none; }
}
@media (max-width: 600px) {
  .features-grid { grid-template-columns: 1fr; }
  .footer-grid { grid-template-columns: 1fr; }
  .hero-stats { flex-wrap: wrap; gap: 20px; }
  .modal-box { padding: 32px 24px; }
  .form-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ══ NAV ══════════════════════════════════════════════════ -->
<nav id="mainNav">
  <div class="nav-inner">
    <a href="/" class="nav-logo">
      <div class="nav-logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
          <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 11 19.79 19.79 0 01.91 2.38 2 2 0 012.92.21h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L7.09 7.91A16 16 0 0016 16.91l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>
        </svg>
      </div>
      <span class="nav-logo-name">Call<em>Mind</em> AI</span>
    </a>

    <ul class="nav-links">
      <li><a href="#how-it-works">How It Works</a></li>
      <li><a href="#use-cases">Use Cases</a></li>
      <li><a href="#features">Features</a></li>
      <li><a href="#pricing">Pricing</a></li>
    </ul>

    <div class="nav-cta">
      <a href="login.php" class="btn-ghost">Log In</a>
      <button class="btn-primary" onclick="openModal()">
        Book a Demo
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </div>
  </div>
</nav>

<!-- ══ HERO ═════════════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-bg"></div>
  <div class="hero-grid-lines"></div>

  <div class="hero-inner">
    <div class="hero-content">
      <div class="hero-badge">
        <span></span> Live AI Calling Agents
      </div>

      <h1 class="hero-title">
        Your leads called.<br>
        <em>Automatically.</em><br>
        <span class="gold">24 hours a day.</span>
      </h1>

      <p class="hero-sub">
        AI calling agents for real estate agents and financial advisors.
        Prospect, qualify, and book appointments — while you focus on closing.
      </p>

      <div class="hero-actions">
        <button class="btn-primary large" onclick="openModal()">
          Book a Free Demo
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <a href="#how-it-works" class="btn-ghost" style="font-size:15px;padding:13px 24px;">
          See How It Works
        </a>
      </div>

      <p class="hero-note">No contracts · Cancel anytime · Setup in 24 hours</p>

      <div class="hero-stats">
        <div class="hero-stat">
          <div class="num">94%</div>
          <div class="lbl">Answer rate vs voicemail</div>
        </div>
        <div class="hero-stat">
          <div class="num">3.2×</div>
          <div class="lbl">More appointments set</div>
        </div>
        <div class="hero-stat">
          <div class="num">$0.08</div>
          <div class="lbl">Per minute of calling</div>
        </div>
      </div>
    </div>

    <!-- Hero visual -->
    <div class="hero-visual reveal">
      <!-- Floating card top-right -->
      <div class="float-card fc1">
        <span class="fc-icon">📅</span>
        <div class="fc-text">
          <div class="fct">Appointment Booked</div>
          <div class="fcl">Sarah K. · 2 min ago</div>
        </div>
      </div>

      <div class="hero-card-main">
        <div class="hc-label">Live Campaign — Expired Listings</div>

        <div class="call-row">
          <div class="call-avatar" style="background:rgba(26,122,110,.2);">🏠</div>
          <div class="call-info">
            <div class="call-name">Marcus Johnson</div>
            <div class="call-detail">4 Elm Street · 2:14 mins</div>
          </div>
          <div class="call-status live">● Live</div>
        </div>

        <div class="call-row">
          <div class="call-avatar" style="background:rgba(200,151,58,.15);">👤</div>
          <div class="call-info">
            <div class="call-name">Rebecca Torres</div>
            <div class="call-detail">Appt booked · Thu 10am</div>
          </div>
          <div class="call-status booked">✓ Booked</div>
        </div>

        <div class="call-row">
          <div class="call-avatar" style="background:rgba(255,255,255,.06);">👤</div>
          <div class="call-info">
            <div class="call-name">David Chen</div>
            <div class="call-detail">Queued · 14 in line</div>
          </div>
          <div class="call-status queued">Queued</div>
        </div>

        <div class="call-row">
          <div class="call-avatar" style="background:rgba(255,255,255,.06);">👤</div>
          <div class="call-info">
            <div class="call-name">Linda Harmon</div>
            <div class="call-detail">Queued · 15 in line</div>
          </div>
          <div class="call-status queued">Queued</div>
        </div>

        <div class="hc-metric-row">
          <div class="hc-metric">
            <div class="mv">47</div>
            <div class="ml">Called Today</div>
          </div>
          <div class="hc-metric">
            <div class="mv">8</div>
            <div class="ml">Appointments</div>
          </div>
          <div class="hc-metric">
            <div class="mv">$3.84</div>
            <div class="ml">Cost So Far</div>
          </div>
        </div>
      </div>

      <!-- Floating card bottom-left -->
      <div class="float-card fc2">
        <span class="fc-icon">🤖</span>
        <div class="fc-text">
          <div class="fct">Agent Active</div>
          <div class="fcl">Expired Listings Bot</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ TRUST BAR ════════════════════════════════════════════ -->
<div class="trust-bar">
  <div class="container">
    <div class="trust-label">Trusted by agencies across North America</div>
    <div class="trust-logos">
      <div class="trust-logo">Keller Williams</div>
      <div class="trust-logo">RE/MAX</div>
      <div class="trust-logo">Century 21</div>
      <div class="trust-logo">Edward Jones</div>
      <div class="trust-logo">Fidelity</div>
      <div class="trust-logo">Coldwell Banker</div>
    </div>
  </div>
</div>

<!-- ══ HOW IT WORKS ═════════════════════════════════════════ -->
<section class="section how-section" id="how-it-works">
  <div class="container">
    <span class="section-tag">Simple Process</span>
    <h2 class="section-title">Up and calling in 24 hours</h2>
    <p class="section-sub">No technical setup required. We handle everything — you just upload your leads and watch the appointments roll in.</p>

    <div class="steps-grid reveal">
      <div class="step-card">
        <div class="step-num">01</div>
        <span class="step-icon">📋</span>
        <div class="step-title">Upload Your Leads</div>
        <p class="step-desc">Upload a CSV of any lead list — expired listings, FSBOs, past clients, financial prospects. We validate and clean the data automatically.</p>
      </div>
      <div class="step-card">
        <div class="step-num">02</div>
        <span class="step-icon">🤖</span>
        <div class="step-title">Assign an AI Agent</div>
        <p class="step-desc">Choose from our pre-built agent library or customise one with your name, brokerage, and preferred script. Sounds natural — not robotic.</p>
      </div>
      <div class="step-card">
        <div class="step-num">03</div>
        <span class="step-icon">🗓</span>
        <div class="step-title">Set Your Campaign</div>
        <p class="step-desc">Define calling hours, daily limits, retry logic, and budget cap. Run until a date or a number of calls — total control, no surprises.</p>
      </div>
      <div class="step-card">
        <div class="step-num">04</div>
        <span class="step-icon">📈</span>
        <div class="step-title">Watch Results Come In</div>
        <p class="step-desc">Appointments land in your calendar. Every call is recorded, transcribed, and summarised. Your dashboard shows ROI in real time.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══ USE CASES ════════════════════════════════════════════ -->
<section class="section" id="use-cases">
  <div class="container">
    <span class="section-tag">Who It's For</span>
    <h2 class="section-title">Built for two industries.<br>Perfected for both.</h2>
    <p class="section-sub" style="margin-bottom:48px;">Scripts, voices, and workflows tailored to how real estate agents and financial advisors actually sell.</p>

    <div class="use-cases-grid reveal">
      <!-- Real Estate -->
      <div class="use-case-card re">
        <span class="uc-icon">🏠</span>
        <div class="uc-niche re">Real Estate</div>
        <h3 class="uc-title">Never lose a lead to a missed call again</h3>
        <p class="uc-desc">Our AI agents work expired listings, FSBOs, and inbound leads around the clock — so every opportunity gets followed up before your competition does.</p>
        <ul class="uc-list">
          <li>Expired listing outreach at 8am sharp, every morning</li>
          <li>FSBO follow-up sequences over 7–21 days</li>
          <li>Inbound lead capture after hours and weekends</li>
          <li>Open house visitor follow-up within 2 hours</li>
          <li>Past client re-engagement for referral business</li>
        </ul>
      </div>

      <!-- Financial -->
      <div class="use-case-card fin">
        <span class="uc-icon">📊</span>
        <div class="uc-niche fin">Financial Advisors</div>
        <h3 class="uc-title">Book more review meetings without hiring staff</h3>
        <p class="uc-desc">Automate the time-consuming outreach that keeps your pipeline full — from annual review reminders to rate change notifications and cold prospecting.</p>
        <ul class="uc-list gold">
          <li>Annual review appointment scheduling</li>
          <li>Rate drop and market event notifications</li>
          <li>Newsletter subscriber qualification calls</li>
          <li>Seminar and webinar attendee follow-up</li>
          <li>Referral partner outreach campaigns</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- ══ FEATURES ══════════════════════════════════════════════ -->
<section class="section features-section" id="features">
  <div class="container">
    <span class="section-tag">Platform Features</span>
    <h2 class="section-title">Everything in one dashboard</h2>
    <p class="section-sub">Your command centre for every campaign, agent, lead, and dollar spent.</p>

    <div class="features-grid reveal">
      <div class="feat-card">
        <div class="feat-icon teal">🎙️</div>
        <div class="feat-title">Natural AI Voice</div>
        <p class="feat-desc">Powered by VAPI and Retell — voices that hold natural conversations, handle objections, and know when to hand off to a human.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon gold">📋</div>
        <div class="feat-title">CSV Lead Upload</div>
        <p class="feat-desc">Upload any lead list in seconds. Auto-validation removes bad numbers, flags duplicates, and checks against your DNC list before a single call is made.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon ink">🗓</div>
        <div class="feat-title">Calendar Integration</div>
        <p class="feat-desc">AI books directly into your Google Calendar or Calendly during the call — no back-and-forth, no manual entry. Appointments confirmed on the spot.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon teal">📊</div>
        <div class="feat-title">Real-Time Dashboard</div>
        <p class="feat-desc">Live campaign stats: calls made, pickup rate, appointments set, cost per call, and full recordings with AI-generated summaries for every conversation.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon gold">🔁</div>
        <div class="feat-title">Smart Retry Logic</div>
        <p class="feat-desc">Automatically retries no-answers and voicemails on a schedule you control. Leaves personalised voicemails and stops calling once a lead converts.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon ink">🔌</div>
        <div class="feat-title">CRM Webhooks</div>
        <p class="feat-desc">Push call outcomes, transcripts, and lead status to Follow Up Boss, HubSpot, LionDesk, or any CRM in real time via webhook or Zapier.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon teal">💰</div>
        <div class="feat-title">Budget Controls</div>
        <p class="feat-desc">Set a spending cap per campaign and the system stops automatically when it's hit. Pay only for what you use, down to the minute.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon gold">🛡️</div>
        <div class="feat-title">DNC Compliance</div>
        <p class="feat-desc">Built-in Do Not Call list management. Every number checked before dialling. TCPA-aware scheduling windows. Compliance you can rely on.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon ink">📝</div>
        <div class="feat-title">Call Transcripts</div>
        <p class="feat-desc">Every call transcribed and summarised by AI. Search across conversations, flag hot leads, and share specific calls with your team in one click.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══ PRICING ════════════════════════════════════════════════ -->
<!-- ══ PRICING ════════════════════════════════════════════════ -->
<section class="section" id="pricing">
  <div class="container">
    <span class="section-tag">Simple Pricing</span>
    <h2 class="section-title">Choose your level of control</h2>
    <p class="section-sub">Start self-serve or let us run everything for you. Upgrade anytime.</p>

    <div class="pricing-grid reveal">

      <!-- SELF SERVE -->
      <div class="price-card">
        <div class="price-name dark">Self-Serve</div>
        <div class="price-amount">$297</div>
        <div class="price-period">per month</div>
        <div class="price-divider"></div>
        <ul class="price-list">
          <li><span class="chk">✓</span> Full campaign builder access</li>
          <li><span class="chk">✓</span> Upload your own scripts & leads</li>
          <li><span class="chk">✓</span> 300 minutes included</li>
          <li><span class="chk">✓</span> 3 active campaigns</li>
          <li><span class="chk">✓</span> Call recordings & transcripts</li>
          <li><span class="chk">✓</span> Email support</li>
        </ul>
        <a href="login.php?register=1" class="btn-outline-ink">Start Free Trial</a>
      </div>

      <!-- GUIDED (FEATURED) -->
      <div class="price-card featured">
        <div class="price-badge">Most Popular</div>
        <div class="price-name light">Guided Growth</div>
        <div class="price-amount light">$697</div>
        <div class="price-period light">per month</div>
        <div class="price-divider light"></div>
        <ul class="price-list">
          <li class="light"><span class="chk">✓</span> Everything in Self-Serve</li>
          <li class="light"><span class="chk">✓</span> Proven scripts (real estate / finance)</li>
          <li class="light"><span class="chk">✓</span> Campaign setup assistance</li>
          <li class="light"><span class="chk">✓</span> 1,000 minutes included</li>
          <li class="light"><span class="chk">✓</span> CRM + calendar integration</li>
          <li class="light"><span class="chk">✓</span> Performance optimization</li>
          <li class="light"><span class="chk">✓</span> Priority support</li>
        </ul>
        <a href="login.php?register=1" class="btn-gold-block">Get Started</a>
      </div>

      <!-- DONE FOR YOU -->
      <div class="price-card">
        <div class="price-name dark">Done-For-You</div>
        <div class="price-amount">$1497</div>
        <div class="price-period">per month</div>
        <div class="price-divider"></div>
        <ul class="price-list">
          <li><span class="chk">✓</span> We build & manage campaigns for you</li>
          <li><span class="chk">✓</span> Script writing & optimization</li>
          <li><span class="chk">✓</span> Lead outreach & follow-up sequences</li>
          <li><span class="chk">✓</span> Appointment-ready leads delivered</li>
          <li><span class="chk">✓</span> Unlimited campaigns</li>
          <li><span class="chk">✓</span> Dedicated account manager</li>
          <li><span class="chk">✓</span> Weekly performance reports</li>
        </ul>
        <a href="#" class="btn-outline-ink" onclick="openModal()">Book Strategy Call</a>
      </div>

    </div>

    <p style="text-align:center;font-size:13px;color:var(--ink-mute);margin-top:24px;">
      Overage calls billed at $0.10/min · Cancel anytime · Scale as you grow
    </p>
  </div>
</section>


<!-- ══ ROI CALCULATOR ═══════════════════════════════════════ -->
<section class="section" id="roi">
  <div class="container">
    <span class="section-tag">ROI Calculator</span>
    <h2 class="section-title">See what this is worth to you</h2>
    <p class="section-sub">Even a small increase in conversations can mean thousands in commissions.</p>

    <div style="background:var(--white);border-radius:20px;padding:40px;box-shadow:var(--shadow-md);max-width:800px;margin:0 auto;">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
        
        <div>
          <label class="form-label">Leads per month</label>
          <input type="number" id="leads" class="form-input" value="200">
        </div>

        <div>
          <label class="form-label">Close rate (%)</label>
          <input type="number" id="closeRate" class="form-input" value="3">
        </div>

        <div>
          <label class="form-label">Avg commission ($)</label>
          <input type="number" id="commission" class="form-input" value="8000">
        </div>

        <div>
          <label class="form-label">Extra conversations with AI (%)</label>
          <input type="number" id="lift" class="form-input" value="30">
        </div>

      </div>

      <div style="background:var(--cream-dark);padding:28px;border-radius:16px;text-align:center;">
        <div style="font-size:14px;color:var(--ink-mute);margin-bottom:6px;">Estimated Additional Revenue</div>
        <div id="roiResult" style="font-family:var(--ff-display);font-size:42px;font-weight:700;color:var(--ink);">$0</div>
        <div style="font-size:13px;color:var(--ink-mute);margin-top:6px;">per month</div>
      </div>

      <div style="text-align:center;margin-top:28px;">
        <button class="btn-primary large" onclick="openModal()">See This In Action</button>
      </div>

    </div>
  </div>
</section>


<!-- ══ TESTIMONIALS ══════════════════════════════════════════ -->
<section class="section testimonials-section">
  <div class="container">
    <span class="section-tag">Results</span>
    <h2 class="section-title" style="color:var(--white);margin-bottom:12px;">What agents are saying</h2>
    <p style="color:rgba(255,255,255,.5);margin-bottom:60px;font-size:17px;">Real results from real estate agents and financial advisors using CallMind AI.</p>

    <div class="testi-grid reveal">
      <div class="testi-card">
        <div class="testi-stars">★★★★★</div>
        <p class="testi-quote">"I was skeptical an AI could handle expired listing calls. First week, it booked me 6 listing appointments. That's more than I'd get in a month doing it myself."</p>
        <div class="testi-author">
          <div class="testi-avatar">J</div>
          <div>
            <div class="testi-name">James Whitfield</div>
            <div class="testi-role">RE/MAX Agent · Dallas, TX</div>
          </div>
        </div>
      </div>

      <div class="testi-card">
        <div class="testi-stars">★★★★★</div>
        <p class="testi-quote">"The inbound agent pays for itself. I was missing calls constantly. Now every lead that comes in gets answered immediately, even at 10pm. Closed two deals from after-hours calls last month."</p>
        <div class="testi-author">
          <div class="testi-avatar" style="background:var(--gold);">M</div>
          <div>
            <div class="testi-name">Michelle Okafor</div>
            <div class="testi-role">Coldwell Banker · Atlanta, GA</div>
          </div>
        </div>
      </div>

      <div class="testi-card">
        <div class="testi-stars">★★★★★</div>
        <p class="testi-quote">"As a financial advisor, my biggest challenge is getting clients in for annual reviews. CallMind books them automatically. My review completion rate went from 60% to 94% in 90 days."</p>
        <div class="testi-author">
          <div class="testi-avatar" style="background:#6366f1;">R</div>
          <div>
            <div class="testi-name">Robert Sánchez</div>
            <div class="testi-role">Independent Advisor · Miami, FL</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ══ CTA ═══════════════════════════════════════════════════ -->
<section class="cta-section">
  <div class="container cta-inner">
    <h2 class="cta-title">Your leads are going cold right now</h2>
    <p class="cta-sub">Every hour you wait is an appointment your competitor is booking. Get started today.</p>
    <div class="cta-actions">
      <button class="btn-white" onclick="openModal()">
        📅 Book a Free Demo
      </button>
      <a href="#pricing" class="btn-outline-white-lg">View Pricing</a>
    </div>
    <p style="font-size:13px;color:rgba(255,255,255,.45);margin-top:20px;">
      Free 30-min demo · No credit card required · Live system walkthrough
    </p>
  </div>
</section>

<!-- ══ FOOTER ════════════════════════════════════════════════ -->
<footer>
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-brand-name">Call<em>Mind</em> AI</div>
        <p class="footer-brand-desc">AI calling agents built for real estate agents and financial advisors. Prospect, qualify, and book — on autopilot.</p>
      </div>
      <div class="footer-col">
        <h4>Product</h4>
        <ul>
          <li><a href="#how-it-works">How It Works</a></li>
          <li><a href="#use-cases">Use Cases</a></li>
          <li><a href="#features">Features</a></li>
          <li><a href="#pricing">Pricing</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Company</h4>
        <ul>
          <li><a href="about.php">About Us</a></li>
          <li><a href="blog.php">Blog</a></li>
          <li><a href="contact.php">Contact</a></li>
          <li><a href="careers.php">Careers</a></li>
        </ul>
      </div>
      <div class="footer-col">
        <h4>Legal</h4>
        <ul>
          <li><a href="privacy.php">Privacy Policy</a></li>
          <li><a href="terms.php">Terms of Service</a></li>
          <li><a href="compliance.php">TCPA Compliance</a></li>
          <li><a href="dnc.php">DNC Policy</a></li>
        </ul>
      </div>
    </div>

    <div class="footer-bottom">
      <p>© <?= date('Y') ?> CallMind AI. All rights reserved.</p>
      <div class="footer-links">
        <a href="privacy.php">Privacy</a>
        <a href="terms.php">Terms</a>
        <a href="contact.php">Contact</a>
      </div>
    </div>
  </div>
</footer>

<!-- ══ DEMO MODAL ════════════════════════════════════════════ -->
<div class="modal-overlay" id="demoModal" onclick="handleOverlayClick(event)">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <h2 class="modal-title">Book Your Free Demo</h2>
    <p class="modal-sub">We'll show you a live campaign running in your niche. 30 minutes, no pitch, just results.</p>

    <form id="demoForm" onsubmit="submitDemo(event)">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">First Name</label>
          <input type="text" class="form-input" placeholder="James" required>
        </div>
        <div class="form-group">
          <label class="form-label">Last Name</label>
          <input type="text" class="form-input" placeholder="Wilson" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Work Email</label>
        <input type="email" class="form-input" placeholder="james@brokerage.com" required>
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number</label>
        <input type="tel" class="form-input" placeholder="+1 555 000 0000">
      </div>
      <div class="form-group">
        <label class="form-label">I am a...</label>
        <select class="form-select" required>
          <option value="">Select your role</option>
          <option>Real Estate Agent</option>
          <option>Real Estate Team Lead</option>
          <option>Brokerage Owner</option>
          <option>Financial Advisor</option>
          <option>Insurance Agent</option>
          <option>Other</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">How many leads do you work per month?</label>
        <select class="form-select">
          <option value="">Select a range</option>
          <option>Under 50</option>
          <option>50 – 200</option>
          <option>200 – 500</option>
          <option>500 – 1,000</option>
          <option>1,000+</option>
        </select>
      </div>
      <button type="submit" class="btn-submit" id="demoSubmitBtn">
        Request My Demo →
      </button>
      <p class="form-note">We'll reach out within 2 business hours to confirm your slot.</p>
    </form>
  </div>
</div>

<script>
// ── Nav scroll effect ────────────────────────────────────────
const nav = document.getElementById('mainNav');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 40);
}, { passive: true });

// ── Scroll reveal ────────────────────────────────────────────
const revealEls = document.querySelectorAll('.reveal');
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
}, { threshold: 0.12 });
revealEls.forEach(el => observer.observe(el));

// ── Demo modal ───────────────────────────────────────────────
function openModal() {
  document.getElementById('demoModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal() {
  document.getElementById('demoModal').classList.remove('open');
  document.body.style.overflow = '';
}
function handleOverlayClick(e) {
  if (e.target === document.getElementById('demoModal')) closeModal();
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal();
});

// ── Demo form submit ─────────────────────────────────────────
function submitDemo(e) {
  e.preventDefault();
  const btn = document.getElementById('demoSubmitBtn');
  btn.textContent = '⏳ Sending…';
  btn.disabled = true;

  // POST to demo_request.php — wire this up server-side
  setTimeout(() => {
    btn.textContent = '✅ Request Sent!';
    setTimeout(() => {
      closeModal();
      btn.textContent = 'Request My Demo →';
      btn.disabled = false;
    }, 1800);
  }, 1200);
}

// ── Smooth anchor scroll (offset for sticky nav) ─────────────
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (!target) return;
    e.preventDefault();
    const top = target.getBoundingClientRect().top + window.scrollY - 80;
    window.scrollTo({ top, behavior: 'smooth' });
  });
});
</script>
</body>
</html>
