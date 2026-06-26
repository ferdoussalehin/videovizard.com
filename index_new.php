<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>VideoVizard — Launch a Month of Content in Minutes</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
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
  --white:   #ffffff;
  --card:    rgba(255,255,255,0.92);
  --glass:   rgba(255,255,255,0.60);
  --border:  rgba(2,132,199,0.14);
  --shadow:  0 20px 60px -10px rgba(2,132,199,0.20);
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

body {
  font-family: 'Instrument Sans', sans-serif;
  background: var(--sky-50);
  color: var(--sky-900);
  overflow-x: hidden;
  line-height: 1.6;
}

/* Lock scroll while gateway is open */
body.gw-open { overflow: hidden; }

h1,h2,h3,h4 {
  font-family: 'Bricolage Grotesque', sans-serif;
  line-height: 1.15;
}

/* ═══════════════════ NOISE TEXTURE ═════════════════ */
body::before {
  content:'';
  position:fixed; inset:0; z-index:0; pointer-events:none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  opacity:.4;
}

/* ═══════════════════ BLOBS ══════════════════════════ */
.blobs { position:fixed; inset:0; z-index:0; pointer-events:none; overflow:hidden; }
.blob {
  position:absolute; border-radius:50%; filter:blur(80px); opacity:0.18;
  animation: drift 18s ease-in-out infinite alternate;
}
.blob-1 { width:600px; height:600px; background:var(--sky-400); top:-200px; left:-100px; animation-duration:20s; }
.blob-2 { width:500px; height:500px; background:var(--sky-600); bottom:-150px; right:-100px; animation-duration:25s; animation-delay:-8s; }
.blob-3 { width:300px; height:300px; background:var(--emerald); top:50%; left:60%; animation-duration:15s; animation-delay:-4s; }
@keyframes drift {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(40px, 30px) scale(1.08); }
}

/* ═══════════════════ NAV ════════════════════════════ */
nav {
  position: fixed; top:20px; left:50%; transform:translateX(-50%);
  width: min(92%, 1180px);
  padding: 13px 28px;
  background: rgba(255,255,255,0.80);
  backdrop-filter: blur(18px);
  border: 1px solid var(--border);
  border-radius: 60px;
  z-index: 1000;
  display: flex; justify-content: space-between; align-items: center;
  transition: all .3s;
}
nav.scrolled {
  top:0; border-radius:0 0 24px 24px; border-top:none; border-left:none; border-right:none;
  background:rgba(255,255,255,0.96);
  box-shadow: 0 8px 30px rgba(0,0,0,.06);
}
.nav-logo {
  font-family:'Bricolage Grotesque',sans-serif; font-weight:800; font-size:24px;
  text-decoration:none; letter-spacing:-0.5px; display:flex; align-items:center; gap:4px;
}
.logo-v { color: var(--sky-600); }
.logo-v2 { color: var(--sky-900); }
.nav-pill {
  background: var(--sky-100); border:1px solid var(--sky-200);
  color: var(--sky-700); font-size:11px; font-weight:700; letter-spacing:.04em;
  padding:3px 10px; border-radius:20px; margin-left:8px; text-transform:uppercase;
}
.nav-links { display:flex; align-items:center; gap:32px; }
.nav-links a {
  text-decoration:none; color: var(--sky-700); font-size:14px; font-weight:500;
  transition:.2s; position:relative;
}
.nav-links a:hover { color:var(--sky-900); }
.nav-cta {
  background: var(--sky-600); color:var(--white) !important;
  padding:10px 24px; border-radius:40px; font-weight:700 !important;
  box-shadow: 0 6px 20px rgba(2,132,199,.30); font-size:14px !important;
  transition: all .2s !important;
}
.nav-cta:hover { background:var(--sky-700) !important; transform:translateY(-2px); }

/* hamburger */
.hamburger { display:none; font-size:26px; cursor:pointer; color:var(--sky-900); z-index:1100; }
.hamburger.active { color:var(--sky-600); }
.mobile-menu {
  position:fixed; top:0; right:-100%; width:290px; height:100vh;
  background:rgba(255,255,255,.97); backdrop-filter:blur(20px);
  border-left:1px solid var(--border); padding:100px 30px 40px;
  display:flex; flex-direction:column; gap:22px;
  transition:right .4s cubic-bezier(.2,.9,.3,1); z-index:1050;
}
.mobile-menu.open { right:0; }
.mobile-menu a { color:var(--sky-800); text-decoration:none; font-size:19px; font-weight:600; font-family:'Bricolage Grotesque',sans-serif; padding:8px 0; border-bottom:1px solid var(--border); }
.mobile-menu .mob-cta { background:var(--sky-600); color:white; text-align:center; padding:15px; border-radius:50px; border:none; margin-top:16px; }
.mob-overlay { position:fixed; inset:0; background:rgba(0,0,0,.18); backdrop-filter:blur(2px); z-index:1040; opacity:0; pointer-events:none; transition:opacity .3s; }
.mob-overlay.active { opacity:1; pointer-events:all; }

/* ═══════════════════ HERO ═══════════════════════════ */
.hero {
  position:relative; z-index:1; min-height:100vh;
  display:flex; align-items:center; justify-content:center;
  padding: 160px 24px 100px;
}
.hero-inner { max-width:960px; margin:0 auto; text-align:center; }

.hero-kicker {
  display:inline-flex; align-items:center; gap:8px;
  background:rgba(2,132,199,.07); border:1px solid var(--border);
  color:var(--sky-700); padding:7px 18px; border-radius:40px;
  font-size:13px; font-weight:600; letter-spacing:.02em; margin-bottom:28px;
}
.dot-green { width:8px; height:8px; background:var(--emerald); border-radius:50%; animation:blink 2s infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

.hero h1 {
  font-size: clamp(46px, 8.5vw, 88px);
  letter-spacing: -2.5px;
  color: var(--sky-900);
  margin-bottom: 26px;
}
.hero h1 em {
  font-style:normal;
  background: linear-gradient(135deg, var(--sky-500) 0%, var(--sky-700) 60%);
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.hero-sub {
  font-size: clamp(16px, 2.2vw, 20px);
  color: var(--sky-700); max-width:700px; margin:0 auto 36px;
  font-weight:400;
}
.hero-sub strong { color:var(--sky-900); font-weight:600; }

/* time badges */
.time-badges {
  display:flex; justify-content:center; gap:16px; flex-wrap:wrap; margin-bottom:44px;
}
.tbadge {
  background:white; border:1px solid var(--sky-200);
  border-radius:16px; padding:14px 22px;
  display:flex; flex-direction:column; align-items:center; gap:3px;
  box-shadow: 0 4px 14px rgba(2,132,199,.08);
  transition:.2s;
}
.tbadge:hover { transform:translateY(-4px); box-shadow: 0 12px 30px rgba(2,132,199,.14); }
.tbadge-num { font-family:'Bricolage Grotesque',sans-serif; font-size:24px; font-weight:800; color:var(--sky-600); }
.tbadge-txt { font-size:12px; font-weight:500; color:var(--sky-700); }

/* CTA buttons */
.btns { display:flex; gap:14px; justify-content:center; flex-wrap:wrap; margin-bottom:70px; }
.btn-main {
  background: var(--sky-600); color:white; border:none;
  padding:17px 40px; border-radius:60px;
  font-family:'Bricolage Grotesque',sans-serif; font-size:16px; font-weight:700;
  cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:10px;
  box-shadow: 0 10px 30px rgba(2,132,199,.30); transition:.25s;
}
.btn-main:hover { background:var(--sky-700); transform:translateY(-3px); box-shadow:0 18px 40px rgba(2,132,199,.35); }
.btn-ghost {
  background:white; color:var(--sky-700); border:1.5px solid var(--sky-200);
  padding:17px 40px; border-radius:60px;
  font-family:'Bricolage Grotesque',sans-serif; font-size:16px; font-weight:600;
  cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px;
  transition:.2s;
}
.btn-ghost:hover { border-color:var(--sky-400); background:var(--sky-50); transform:translateY(-2px); }

/* ═══════════════════ APP PREVIEW MOCKUP ════════════ */
.app-preview {
  background:white; border:1px solid #dbeafe; border-radius:24px;
  box-shadow: 0 40px 80px -20px rgba(2,132,199,.22);
  overflow:hidden; max-width:900px; margin:0 auto;
  animation:floatY 6s ease-in-out infinite;
}
@keyframes floatY { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }
.db-dots { display:flex; gap:7px; }
.db-dot { width:12px;height:12px;border-radius:50%; }
.db-dot.r{background:#ef4444;} .db-dot.y{background:#f59e0b;} .db-dot.g{background:#10b981;}
.ap-topbar {
  background:#f8fafc; border-bottom:1px solid #e2e8f0;
  padding:12px 20px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;
}
.ap-tabs { display:flex; gap:4px; flex:1; flex-wrap:wrap; }
.ap-tab {
  padding:7px 14px; border-radius:8px; border:none; background:transparent;
  font-size:12px; font-weight:600; color:#64748b; cursor:pointer;
  font-family:'Instrument Sans',sans-serif; display:flex; align-items:center; gap:6px;
  transition:.2s; white-space:nowrap;
}
.ap-tab i { font-size:11px; }
.ap-tab.active { background:var(--sky-600); color:white; }
.ap-tab:not(.active):hover { background:#f1f5f9; color:var(--sky-700); }
.ap-url { font-size:11px; color:#94a3b8; font-family:monospace; white-space:nowrap; }
.ap-screen { display:none; padding:24px; }
.ap-screen.active { display:block; }

/* ── Script Generator ── */
.sg-layout { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.sg-form { display:flex; flex-direction:column; gap:12px; }
.sg-form-title { font-family:'Bricolage Grotesque',sans-serif; font-size:15px; font-weight:700; color:var(--sky-900); margin-bottom:4px; }
.sg-field { display:flex; flex-direction:column; gap:5px; }
.sg-field label { font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.05em; text-transform:uppercase; }
.sg-input { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:9px 13px; font-size:13px; color:var(--sky-900); }
.sg-input-lg { min-height:52px; }
.sg-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
.sg-btn { background:var(--sky-600); color:white; border:none; border-radius:10px; padding:11px; font-size:13px; font-weight:700; cursor:default; margin-top:4px; font-family:'Bricolage Grotesque',sans-serif; }
.sg-output { background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:10px; }
.sg-output-label { font-size:12px; font-weight:700; color:var(--sky-700); display:flex; align-items:center; gap:8px; }
.sg-badge { background:#dcfce7; color:#059669; font-size:10px; padding:2px 8px; border-radius:20px; }
.sg-script-lines { display:flex; flex-direction:column; gap:8px; }
.sg-line { font-size:12px; color:#334155; line-height:1.5; display:flex; gap:8px; align-items:flex-start; padding:8px 10px; background:white; border-radius:8px; border:1px solid #f1f5f9; }
.sg-line.sg-hook { border-left:3px solid var(--sky-500); }
.sg-tag { font-size:9px; font-weight:800; color:var(--sky-600); background:var(--sky-50); padding:2px 6px; border-radius:4px; white-space:nowrap; margin-top:1px; }
.sg-actions { display:flex; gap:8px; margin-top:4px; }
.sg-action-btn { flex:1; padding:8px; border-radius:8px; background:white; border:1px solid #e2e8f0; font-size:11px; font-weight:600; color:var(--sky-700); text-align:center; cursor:default; }
.sg-action-primary { background:var(--sky-600); color:white; border-color:var(--sky-600); }

/* ── Videos Browser ── */
.vb-layout { display:flex; flex-direction:column; gap:14px; }
.vb-toolbar { display:flex; align-items:center; gap:14px; }
.vb-search { flex:1; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:9px 14px; font-size:12px; color:#94a3b8; }
.vb-filters { display:flex; gap:6px; }
.vb-filter { padding:6px 14px; border-radius:20px; font-size:11px; font-weight:600; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0; cursor:default; }
.vb-filter.active { background:var(--sky-600); color:white; border-color:var(--sky-600); }
.vb-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; }
.vb-card { display:flex; flex-direction:column; gap:6px; cursor:default; }
.vb-thumb { aspect-ratio:9/16; border-radius:10px; position:relative; display:flex; align-items:center; justify-content:center; }
.vb-play { color:rgba(255,255,255,.9); font-size:20px; }
.vb-duration { position:absolute; bottom:6px; right:6px; background:rgba(0,0,0,.5); color:white; font-size:9px; font-weight:700; padding:2px 6px; border-radius:4px; }
.vb-lang { position:absolute; top:6px; left:6px; background:rgba(0,0,0,.4); color:white; font-size:9px; font-weight:700; padding:2px 6px; border-radius:4px; }
.vb-info .vb-title { font-size:11px; font-weight:700; color:var(--sky-900); line-height:1.3; }
.vb-info .vb-meta { font-size:10px; color:#94a3b8; margin-top:2px; }
.vb-status { font-size:10px; font-weight:700; }
.vb-status.published { color:var(--emerald); }
.vb-status.scheduled { color:var(--sky-600); }
.vb-status.draft { color:#f59e0b; }

/* ── Video Editor ── */
.ve-layout { display:grid; grid-template-columns:1fr 200px; gap:16px; }
.ve-preview { display:flex; flex-direction:column; gap:12px; }
.ve-screen { background:#0f172a; border-radius:14px; aspect-ratio:16/7; display:flex; flex-direction:column; align-items:center; justify-content:center; position:relative; overflow:hidden; }
.ve-caption-line { font-family:'Bricolage Grotesque',sans-serif; font-size:16px; font-weight:800; color:white; }
.ve-caption-sub { font-size:13px; color:rgba(255,255,255,.7); margin-top:6px; }
.ve-overlay-controls { position:absolute; bottom:14px; left:50%; transform:translateX(-50%); display:flex; gap:16px; }
.ve-ctrl { color:rgba(255,255,255,.6); font-size:16px; cursor:default; }
.ve-play { color:white; font-size:22px; }
.ve-timeline { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:8px; }
.ve-tl-track { display:flex; align-items:center; gap:10px; }
.ve-tl-label { font-size:10px; font-weight:600; color:#94a3b8; width:52px; flex-shrink:0; }
.ve-tl-bar { height:10px; border-radius:6px; }
.ve-panel { display:flex; flex-direction:column; gap:6px; }
.ve-panel-title { font-size:13px; font-weight:700; color:var(--sky-900); margin-bottom:6px; font-family:'Bricolage Grotesque',sans-serif; }
.ve-tool { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:10px; font-size:12px; font-weight:500; color:#475569; border:1px solid #f1f5f9; cursor:default; }
.ve-tool i { color:var(--sky-500); font-size:12px; }
.ve-tool-active { background:var(--sky-50); border-color:var(--sky-200); color:var(--sky-700); }
.ve-divider { height:1px; background:#f1f5f9; margin:4px 0; }
.ve-export-btn { background:var(--sky-600); color:white; border-radius:10px; padding:10px 12px; font-size:12px; font-weight:700; text-align:center; cursor:default; }

/* ── Schedule ── */
.sch-layout { display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; }
.sch-cal { background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px; padding:16px; }
.sch-cal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.sch-cal-month { font-size:14px; font-weight:700; color:var(--sky-900); font-family:'Bricolage Grotesque',sans-serif; }
.sch-cal-nav { color:#94a3b8; cursor:default; font-size:16px; padding:0 6px; }
.sch-cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.sch-day-name { font-size:9px; font-weight:700; color:#94a3b8; text-align:center; padding:4px 0; }
.sch-day { font-size:11px; text-align:center; padding:6px 4px; border-radius:6px; color:#475569; position:relative; cursor:default; }
.sch-day.sch-today { background:var(--sky-600); color:white; border-radius:8px; font-weight:700; }
.sch-day.sch-has-post { font-weight:600; color:var(--sky-700); }
.sch-dot { display:block; width:4px; height:4px; background:var(--sky-400); border-radius:50%; margin:1px auto 0; }
.sch-modal { background:white; border:1px solid #e2e8f0; border-radius:16px; padding:18px; display:flex; flex-direction:column; gap:12px; box-shadow:0 8px 24px rgba(2,132,199,.10); }
.sch-modal-title { font-size:14px; font-weight:700; color:var(--sky-900); font-family:'Bricolage Grotesque',sans-serif; }
.sch-vid-preview { display:flex; gap:10px; align-items:center; background:#f8fafc; border-radius:10px; padding:10px 12px; }
.sch-vid-thumb { width:36px; height:52px; border-radius:6px; display:flex; align-items:center; justify-content:center; color:white; font-size:14px; flex-shrink:0; }
.sch-vid-name { font-size:13px; font-weight:700; color:var(--sky-900); }
.sch-vid-dur { font-size:11px; color:#64748b; margin-top:3px; }
.sch-fields { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.sch-field { display:flex; flex-direction:column; gap:4px; }
.sch-field label { font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.04em; text-transform:uppercase; }
.sch-input { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 11px; font-size:12px; color:var(--sky-900); font-weight:600; }
.sch-plat-label { font-size:10px; font-weight:700; color:#94a3b8; letter-spacing:.04em; text-transform:uppercase; }
.sch-platforms { display:flex; gap:8px; }
.sch-plat { width:36px; height:36px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:center; font-size:15px; cursor:default; }
.sch-plat i { color:#94a3b8; }
.sch-plat.active { background:var(--sky-50); border-color:var(--sky-400); }
.sch-plat.active i { color:var(--sky-600); }
.sch-confirm-btn { background:var(--sky-600); color:white; border-radius:10px; padding:11px; font-size:13px; font-weight:700; text-align:center; cursor:default; margin-top:2px; }

@media(max-width:768px) {
  .sg-layout { grid-template-columns:1fr; }
  .sg-row { grid-template-columns:1fr 1fr; }
  .vb-grid { grid-template-columns:repeat(3,1fr); }
  .ve-layout { grid-template-columns:1fr; }
  .ve-panel { display:none; }
  .sch-layout { grid-template-columns:1fr; }
}

/* ═══════════════════ SECTIONS COMMON ══════════════ */
section { position:relative; z-index:1; }
.container { max-width:1180px; margin:0 auto; padding:0 24px; }
.sec-head { text-align:center; max-width:680px; margin:0 auto 64px; }
.sec-eyebrow {
  display:inline-block; background:white; border:1px solid var(--sky-200);
  color:var(--sky-600); font-size:11px; font-weight:700; letter-spacing:.08em;
  text-transform:uppercase; padding:6px 16px; border-radius:30px; margin-bottom:18px;
}
.sec-head h2 { font-size:clamp(32px,5vw,52px); color:var(--sky-900); margin-bottom:16px; letter-spacing:-1.2px; }
.sec-head p { font-size:17px; color:var(--sky-700); }

/* ═══════════════════ HOW IT WORKS ══════════════════ */
.hiw { padding:110px 0; }
.steps { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:24px; }
.step-card {
  background:white; border:1px solid var(--sky-100); border-radius:24px;
  padding:32px 28px; position:relative; overflow:hidden; transition:.25s;
}
.step-card::before {
  content:''; position:absolute; top:0; left:0; width:4px; height:100%;
  background: linear-gradient(180deg, var(--sky-400), var(--sky-700));
}
.step-card:hover { transform:translateY(-6px); box-shadow:0 20px 50px rgba(2,132,199,.12); }
.step-num {
  font-family:'Bricolage Grotesque',sans-serif; font-size:56px; font-weight:800;
  color:var(--sky-100); line-height:1; margin-bottom:16px; letter-spacing:-2px;
}
.step-icon { font-size:32px; margin-bottom:12px; }
.step-card h3 { font-size:20px; color:var(--sky-900); margin-bottom:10px; }
.step-card p { font-size:15px; color:var(--sky-700); }
.step-time {
  display:inline-flex; align-items:center; gap:6px;
  background:var(--sky-50); border:1px solid var(--sky-200);
  color:var(--sky-600); font-size:12px; font-weight:700;
  padding:5px 12px; border-radius:30px; margin-top:16px;
}

/* ═══════════════════ FEATURES GRID ════════════════ */
.features { padding:60px 0 110px; }
.feat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:24px; }
.feat-card {
  background:white; border:1px solid var(--sky-100); border-radius:24px;
  padding:32px; transition:.25s; position:relative; overflow:hidden;
}
.feat-card::after {
  content:''; position:absolute; bottom:0; left:0; right:0; height:3px;
  background:linear-gradient(90deg, var(--sky-400), var(--sky-700));
  transform:scaleX(0); transform-origin:left; transition:.3s;
}
.feat-card:hover::after { transform:scaleX(1); }
.feat-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(2,132,199,.10); }
.feat-icon {
  width:58px; height:58px; background:var(--sky-50); border:1px solid var(--sky-200);
  border-radius:18px; display:flex; align-items:center; justify-content:center;
  font-size:26px; margin-bottom:20px;
}
.feat-card h3 { font-size:19px; color:var(--sky-900); margin-bottom:10px; }
.feat-card p { font-size:14px; color:var(--sky-700); line-height:1.7; }
.feat-tag {
  display:inline-block; background:var(--sky-50); color:var(--sky-600);
  font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px;
  margin-top:14px; border:1px solid var(--sky-200);
}

/* ═══════════════════ PLATFORMS ════════════════════ */
.platforms { padding:80px 0; }
.plat-grid { display:flex; justify-content:center; gap:24px; flex-wrap:wrap; }
.plat-card {
  background:white; border:1px solid var(--sky-100); border-radius:20px;
  padding:26px 34px; display:flex; flex-direction:column; align-items:center; gap:10px;
  transition:.2s; box-shadow:0 4px 14px rgba(2,132,199,.06);
}
.plat-card:hover { transform:translateY(-5px); box-shadow:0 12px 30px rgba(2,132,199,.12); }
.plat-card i { font-size:32px; }
.plat-card span { font-size:13px; font-weight:600; color:var(--sky-800); }
.fa-tiktok { color:#010101; }
.fa-instagram { color:#e1306c; }
.fa-youtube { color:#ff0000; }
.fa-facebook { color:#1877f2; }

/* ═══════════════════ AUDIENCE TOGGLE ══════════════ */
.aud-toggle {
  display:inline-flex; background:white; border:1px solid var(--sky-200);
  border-radius:50px; padding:5px; gap:4px; margin-bottom:24px;
  box-shadow: 0 4px 14px rgba(2,132,199,.08);
}
.aud-btn {
  padding:10px 24px; border-radius:40px; border:none; cursor:pointer;
  font-family:'Bricolage Grotesque',sans-serif; font-size:14px; font-weight:600;
  color:var(--sky-700); background:transparent; transition:.25s;
}
.aud-btn.active { background:var(--sky-600); color:white; box-shadow:0 4px 12px rgba(2,132,199,.30); }
.aud-btn:not(.active):hover { background:var(--sky-50); }

/* ═══════════════════ SAVE SECTION ═════════════════ */
.save-sec { padding:100px 0 80px; }
.save-top { text-align:center; max-width:680px; margin:0 auto 56px; }
.save-top h2 { font-size:clamp(32px,5vw,52px); color:var(--sky-900); margin-bottom:14px; letter-spacing:-1.2px; }
.save-top p { font-size:17px; color:var(--sky-700); }

.save-cards { display:grid; grid-template-columns:1fr 1fr; gap:24px; align-items:start; }

.save-card {
  border-radius:28px; padding:40px; position:relative; overflow:hidden;
}
.save-card-dark {
  background: linear-gradient(145deg, var(--sky-700), var(--sky-900));
  color:white;
}
.save-card-dark h3 { font-size:clamp(22px,2.4vw,30px); color:white; margin-bottom:16px; letter-spacing:-0.5px; }
.save-card-dark p { color:rgba(255,255,255,.78); font-size:15px; line-height:1.75; }
.sc-icon { font-size:44px; margin-bottom:20px; display:block; }
.sc-points { margin-top:24px; display:flex; flex-direction:column; gap:12px; }
.sc-point { display:flex; align-items:center; gap:10px; font-size:14px; color:rgba(255,255,255,.85); font-weight:500; }
.sc-point i { color:#6ee7b7; font-size:13px; flex-shrink:0; }

.save-card-stack { display:flex; flex-direction:column; gap:16px; }
.save-card-light {
  background:white; border:1px solid var(--sky-100);
  box-shadow: 0 4px 16px rgba(2,132,199,.07);
}
.sc-icon-sm { font-size:22px; margin-bottom:10px; display:block; }
.sc-compare { display:flex; align-items:center; gap:14px; }
.sc-before { flex:1; }
.sc-before-label { font-size:11px; color:#94a3b8; font-weight:600; letter-spacing:.03em; margin-bottom:4px; }
.sc-before-val { font-size:14px; font-weight:700; color:#ef4444; }
.sc-arrow { color:#cbd5e1; font-size:20px; font-weight:300; flex-shrink:0; }
.sc-after { flex:1; }
.sc-after-label { font-size:11px; color:#94a3b8; font-weight:600; letter-spacing:.03em; margin-bottom:4px; }
.sc-after-val { font-size:14px; font-weight:700; color:var(--emerald); }

@media(max-width:968px) {
  .save-cards { grid-template-columns:1fr; }
}

/* ═══════════════════ AGENCY SECTION ═══════════════ */
.agency-sec { padding:100px 0 80px; }
.agency-inner {
  display:grid; grid-template-columns:1fr 1fr; gap:70px; align-items:center;
}
.agency-left .sec-eyebrow { margin-bottom:18px; }
.agency-left h2 { font-size:clamp(30px,3.8vw,46px); color:var(--sky-900); margin-bottom:18px; letter-spacing:-1px; }
.agency-left p { font-size:15px; color:var(--sky-700); line-height:1.75; margin-bottom:24px; }
.agency-list { list-style:none; display:flex; flex-direction:column; gap:14px; }
.agency-list li {
  display:flex; gap:12px; align-items:flex-start;
  font-size:14px; color:var(--sky-700); line-height:1.6;
}
.agency-list li i { color:var(--emerald); font-size:15px; margin-top:2px; flex-shrink:0; }
.agency-list li strong { color:var(--sky-900); }

/* agency mockup */
.agency-mockup {
  background:white; border:1px solid var(--sky-200); border-radius:24px;
  overflow:hidden; box-shadow: 0 24px 60px -10px rgba(2,132,199,.18);
}
.am-header {
  background:#f8fafc; border-bottom:1px solid #e2e8f0;
  padding:16px 20px; display:flex; justify-content:space-between; align-items:center;
}
.am-title { font-family:'Bricolage Grotesque',sans-serif; font-size:15px; font-weight:700; color:var(--sky-900); }
.am-badge { background:var(--sky-100); color:var(--sky-700); font-size:11px; font-weight:700; padding:4px 12px; border-radius:20px; }
.am-clients { padding:12px 16px; display:flex; flex-direction:column; gap:6px; }
.am-client {
  display:flex; align-items:center; gap:12px; padding:12px 14px;
  border-radius:14px; border:1px solid #f1f5f9; transition:.2s;
}
.am-client:hover { background:#f8fafc; border-color:var(--sky-200); }
.am-client-avatar {
  width:40px; height:40px; border-radius:12px;
  display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;
}
.am-client-info { flex:1; }
.am-client-name { font-size:13px; font-weight:700; color:var(--sky-900); }
.am-client-meta { font-size:11px; color:#64748b; margin-top:2px; }
.am-client-status { font-size:11px; font-weight:700; white-space:nowrap; }
.am-client-status.green { color:var(--emerald); }
.am-client-status.yellow { color:var(--amber); }
.am-footer {
  border-top:1px solid #e2e8f0; background:#f8fafc;
  padding:16px 20px; display:grid; grid-template-columns:repeat(3,1fr); gap:8px;
}
.am-stat { text-align:center; }
.am-stat-n { font-family:'Bricolage Grotesque',sans-serif; font-size:20px; font-weight:800; color:var(--sky-600); display:block; }
.am-stat-l { font-size:10px; color:#64748b; display:block; margin-top:2px; }

/* responsive agency */
@media(max-width:968px) {
  .agency-inner { grid-template-columns:1fr; gap:44px; }
  .aud-toggle { flex-wrap:wrap; border-radius:20px; }
}

/* ═══════════════════ LANGUAGES ════════════════════ */
.langs { padding:30px 0 80px; }
.lang-row { display:flex; justify-content:center; gap:12px; flex-wrap:wrap; }
.lang-chip {
  background:white; border:1px solid var(--sky-200); border-radius:50px;
  padding:9px 22px; display:flex; align-items:center; gap:10px;
  font-size:14px; font-weight:500; color:var(--sky-800);
  box-shadow:0 2px 8px rgba(2,132,199,.05); transition:.2s;
}
.lang-chip:hover { border-color:var(--sky-400); transform:translateY(-2px); }

/* ═══════════════════ CTA FINAL ════════════════════ */
.final-cta { padding:100px 24px; text-align:center; }
.final-card {
  background: white;
  border:1px solid var(--sky-200);
  border-radius:40px; padding:90px 50px;
  max-width:900px; margin:0 auto;
  box-shadow: 0 40px 80px -20px rgba(2,132,199,.18);
  position:relative; overflow:hidden;
}
.final-card::before {
  content:''; position:absolute; top:-80px; left:-80px;
  width:300px; height:300px; border-radius:50%;
  background: radial-gradient(circle, rgba(2,132,199,.08) 0%, transparent 70%);
  pointer-events:none;
}
.final-card h2 { font-size:clamp(32px,5vw,56px); color:var(--sky-900); margin-bottom:16px; letter-spacing:-1.5px; }
.final-card p { font-size:18px; color:var(--sky-700); max-width:560px; margin:0 auto 36px; }
.final-trust { display:flex; justify-content:center; gap:30px; flex-wrap:wrap; margin-top:36px; }
.trust-item { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--sky-700); font-weight:500; }
.trust-item i { color:var(--emerald); font-size:14px; }

/* ═══════════════════ FOOTER ════════════════════════ */
footer {
  background:white; border-top:1px solid var(--sky-100);
  padding:70px 24px 30px;
}
.footer-grid { max-width:1180px; margin:0 auto; display:grid; grid-template-columns:2.5fr 1fr 1fr 1fr; gap:60px; }
.footer-brand-name { font-family:'Bricolage Grotesque',sans-serif; font-size:26px; font-weight:800; margin-bottom:12px; }
.footer-desc { font-size:14px; color:var(--sky-700); line-height:1.7; }
.footer-col h4 { font-size:11px; font-weight:700; color:#94a3b8; letter-spacing:.06em; text-transform:uppercase; margin-bottom:18px; }
.footer-col a { display:block; color:var(--sky-800); text-decoration:none; font-size:14px; padding:5px 0; transition:.2s; }
.footer-col a:hover { color:var(--sky-600); }
.footer-bottom { max-width:1180px; margin:40px auto 0; padding-top:28px; border-top:1px solid var(--sky-100); display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#94a3b8; }

/* ═══════════════════ RESPONSIVE ════════════════════ */
@media(max-width:968px) {
  .nav-links { display:none; }
  .hamburger { display:block; }
  .db-body { grid-template-columns:1fr; }
  .db-sidebar { display:none; }
  .db-videos { grid-template-columns:repeat(2,1fr); }
  .save-inner { grid-template-columns:1fr; gap:40px; }
  .footer-grid { grid-template-columns:repeat(2,1fr); }
  .save-banner { padding:50px 30px; margin:0 16px 60px; }
  nav { padding:12px 20px; }
}
@media(max-width:568px) {
  .hero h1 { font-size:42px; letter-spacing:-1.5px; }
  .db-videos { grid-template-columns:repeat(2,1fr); }
  .db-stats { grid-template-columns:repeat(3,1fr); }
  .db-input-row { flex-direction:column; }
  .final-card { padding:60px 24px; }
  .footer-grid { grid-template-columns:1fr; gap:36px; }
}

/* ═══════════════════ PRICING ═══════════════════════ */
.pricing { padding:110px 0 100px; }
.pricing-toggle {
  display:inline-flex; background:white; border:1px solid var(--sky-200);
  border-radius:50px; padding:5px; gap:4px; margin-bottom:56px;
  box-shadow:0 4px 14px rgba(2,132,199,.08);
}
.pt-btn {
  padding:10px 26px; border-radius:40px; border:none; cursor:pointer;
  font-family:'Bricolage Grotesque',sans-serif; font-size:14px; font-weight:600;
  color:var(--sky-700); background:transparent; transition:.25s;
}
.pt-btn.active { background:var(--sky-600); color:white; box-shadow:0 4px 12px rgba(2,132,199,.30); }
.pt-btn:not(.active):hover { background:var(--sky-50); }
.pt-save {
  display:inline-block; background:#dcfce7; color:#059669;
  font-size:11px; font-weight:700; padding:2px 10px; border-radius:20px;
  margin-left:6px; vertical-align:middle;
}
.pricing-grid {
  display:grid; grid-template-columns:repeat(4,1fr); gap:20px; align-items:start;
}
.price-card {
  background:white; border:1.5px solid var(--sky-100);
  border-radius:28px; padding:36px 28px;
  position:relative; transition:.25s; overflow:hidden;
}
.price-card:hover { transform:translateY(-5px); box-shadow:0 20px 50px rgba(2,132,199,.12); }
.price-card.popular {
  border-color:var(--sky-500);
  box-shadow:0 20px 50px rgba(2,132,199,.18);
  transform:scale(1.03);
}
.price-card.popular:hover { transform:scale(1.03) translateY(-5px); }
.popular-badge {
  position:absolute; top:18px; right:18px;
  background:var(--sky-600); color:white;
  font-size:10px; font-weight:700; letter-spacing:.05em;
  padding:4px 12px; border-radius:20px; text-transform:uppercase;
}
.price-name {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:15px; font-weight:700; color:var(--sky-700);
  text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px;
}
.price-amount { display:flex; align-items:flex-end; gap:4px; margin-bottom:6px; }
.price-currency { font-size:20px; font-weight:700; color:var(--sky-900); margin-bottom:8px; }
.price-num {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:52px; font-weight:800; color:var(--sky-900);
  letter-spacing:-2px; line-height:1;
}
.price-period { font-size:14px; color:var(--sky-600); margin-bottom:8px; font-weight:500; }
.price-desc { font-size:13px; color:var(--sky-600); margin-bottom:24px; min-height:36px; line-height:1.5; }
.price-divider { height:1px; background:var(--sky-100); margin-bottom:22px; }
.price-features { list-style:none; display:flex; flex-direction:column; gap:11px; margin-bottom:30px; }
.price-features li {
  display:flex; align-items:flex-start; gap:10px;
  font-size:13px; color:var(--sky-700); line-height:1.5;
}
.price-features li i { color:var(--emerald); font-size:12px; margin-top:2px; flex-shrink:0; }
.price-features li.muted { color:#94a3b8; }
.price-features li.muted i { color:#cbd5e1; }
.price-btn {
  display:block; width:100%; padding:14px;
  border-radius:50px; font-family:'Bricolage Grotesque',sans-serif;
  font-size:15px; font-weight:700; text-align:center;
  text-decoration:none; cursor:pointer; border:none; transition:.2s;
}
.price-btn-main {
  background:var(--sky-600); color:white;
  box-shadow:0 8px 24px rgba(2,132,199,.28);
}
.price-btn-main:hover { background:var(--sky-700); }
.price-btn-ghost {
  background:var(--sky-50); color:var(--sky-700);
  border:1.5px solid var(--sky-200);
}
.price-btn-ghost:hover { border-color:var(--sky-400); background:white; }
.price-credits {
  text-align:center; margin-top:14px;
  font-size:12px; color:var(--sky-600); font-weight:600;
}
.price-credits span { font-weight:800; color:var(--sky-700); }
.pricing-footnote {
  text-align:center; margin-top:48px;
  font-size:13px; color:var(--sky-600);
}
.pricing-footnote strong { color:var(--sky-800); }
@media(max-width:1100px) {
  .pricing-grid { grid-template-columns:repeat(2,1fr); }
  .price-card.popular { transform:none; }
  .price-card.popular:hover { transform:translateY(-5px); }
}
@media(max-width:600px) { .pricing-grid { grid-template-columns:1fr; } }

/* ═══════════════════ ANIMATIONS ════════════════════ */
.reveal { opacity:0; transform:translateY(24px); transition:opacity .55s ease, transform .55s ease; }
.reveal.visible { opacity:1; transform:none; }
.reveal-delay-1 { transition-delay:.1s; }
.reveal-delay-2 { transition-delay:.2s; }
.reveal-delay-3 { transition-delay:.3s; }
.reveal-delay-4 { transition-delay:.4s; }

/* ═══════════════════ GATEWAY SCREEN ════════════════ */
#gateway {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: flex;
  align-items: flex-start; /* ← change from center to flex-start */
  justify-content: center;
  background: linear-gradient(145deg, #062236 0%, #0c4a6e 50%, #0369a1 100%);
  overflow-y: auto; /* ← allow vertical scrolling */
  transition: opacity .6s ease, transform .6s ease;
}
#gateway.hidden {
  opacity:0; pointer-events:none; transform:scale(1.04);
}
.gw-blobs { position:absolute; inset:0; pointer-events:none; overflow:hidden; }
.gw-blob {
  position:absolute; border-radius:50%; filter:blur(100px); opacity:0.25;
  animation: drift 20s ease-in-out infinite alternate;
}
.gw-blob-1 { width:700px; height:700px; background:#38bdf8; top:-300px; left:-200px; }
.gw-blob-2 { width:500px; height:500px; background:#059669; bottom:-200px; right:-100px; animation-delay:-10s; }
.gw-blob-3 { width:300px; height:300px; background:#0ea5e9; top:40%; left:55%; animation-delay:-5s; }

/* ── Gateway top bar items ── */
.gw-topleft {
  position: absolute;
  top: 28px;
  left: 36px;
  z-index: 10;
}
.gw-logo-fixed {
  font-family: 'Bricolage Grotesque', sans-serif;
  font-weight: 800;
  font-size: 26px;
  color: white;
  letter-spacing: -0.5px;
  text-decoration: none;
  display: block;
}
.gw-logo-fixed span { color: #38bdf8; }

.gw-topbar {
  position: absolute;
  top: 28px;
  right: 36px;
  z-index: 10;
}
.gw-topbar-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: white;
  color: var(--sky-600);
  border: none;
  border-radius: 50px;
  padding: 11px 26px;
  font-family: 'Bricolage Grotesque', sans-serif;
  font-size: 14px;
  font-weight: 700;
  text-decoration: none;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(0,0,0,0.25);
  transition: all 0.2s;
  letter-spacing: -0.2px;
}
.gw-topbar-btn:hover {
  background: var(--sky-50);
  transform: translateY(-2px);
  box-shadow: 0 14px 32px rgba(0,0,0,0.3);
}
.gw-topbar-btn i { font-size: 11px; }

@media (max-width: 568px) {
  .gw-choice {
    width: 100%;       /* ← full width on mobile */
    max-width: 340px;
  }
  .gw-choices {
    flex-direction: column;
    align-items: center;
  }
  .gw-inner {
    padding: 100px 16px 80px; /* ← extra bottom on small screens */
  }
}

.gw-inner {
  position: relative;
  z-index: 1;
  text-align: center;
  padding: 170px 20px 60px; /* ← 60px bottom gives breathing room */
  max-width: 820px;
  width: 100%;
  min-height: 100%; /* ← ensures content stretches full height */
}

.gw-question {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:clamp(26px, 5vw, 52px);
  font-weight:800; color:white;
  letter-spacing:-1.5px; line-height:1.12;
  margin-bottom:16px;
  opacity:0; animation: gw-fade-in .6s ease .1s forwards;
}
.gw-question em { color:#38bdf8; font-style:normal; }

.gw-sub {
  font-size:clamp(15px,2vw,18px); color:rgba(255,255,255,.65);
  margin-bottom:52px; font-weight:400;
  opacity:0; animation: gw-fade-in .6s ease .25s forwards;
}

.gw-choices {
  display: flex;
  gap: 16px;
  justify-content: center;
  flex-wrap: wrap;
  opacity: 0;
  animation: gw-fade-in .6s ease .4s forwards;
}


.gw-choice {
  background: rgba(255,255,255,.06);
  border: 1.5px solid rgba(255,255,255,.18);
  border-radius: 24px;
  padding: 28px 24px 24px; /* ← slightly tighter on mobile */
  cursor: pointer;
  transition: all .3s cubic-bezier(.2,.9,.3,1);
  text-align: left;
  width: clamp(260px, 36vw, 330px);
  position: relative;
  overflow: hidden;
  backdrop-filter: blur(10px);
}
.gw-choice::before {
  content:''; position:absolute; inset:0; border-radius:24px;
  background:linear-gradient(135deg,rgba(56,189,248,.12) 0%, transparent 60%);
  opacity:0; transition:opacity .3s;
}
.gw-choice:hover::before { opacity:1; }
.gw-choice:hover {
  border-color:rgba(56,189,248,.6);
  transform:translateY(-6px);
  box-shadow:0 30px 60px -10px rgba(0,0,0,.4), 0 0 0 1px rgba(56,189,248,.2);
}

.gw-choice-icon {
  font-size:44px; margin-bottom:20px; display:block;
  line-height:1;
}
.gw-choice-title {
  font-family:'Bricolage Grotesque',sans-serif;
  font-size:20px; font-weight:700; color:white;
  margin-bottom:10px; line-height:1.2;
}
.gw-choice-desc {
  font-size:14px; color:rgba(255,255,255,.60);
  line-height:1.65;
}
.gw-choice-cta {
  margin-top:24px; display:inline-flex; align-items:center; gap:8px;
  color:#38bdf8; font-size:13px; font-weight:700; letter-spacing:.02em;
  font-family:'Bricolage Grotesque',sans-serif;
}
.gw-choice-cta i { font-size:11px; transition:transform .2s; }
.gw-choice:hover .gw-choice-cta i { transform:translateX(4px); }

@keyframes gw-fade-in {
  from { opacity:0; transform:translateY(16px); }
  to   { opacity:1; transform:none; }
}

/* ═══════════════════ AUDIENCE BENEFIT STRIPS ═══════ */
.benefit-strip {
  display:none; z-index:1; position:relative;
  padding:110px 0 80px;
}
.benefit-strip.visible { display:block; }
.benefit-strip-owner { background:linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); }
.benefit-strip-agency { background:linear-gradient(135deg, #062236 0%, #0c4a6e 100%); }

.bs-inner { max-width:1180px; margin:0 auto; padding:0 24px; }
.bs-head { text-align:center; margin-bottom:52px; }
.bs-eyebrow {
  display:inline-flex; align-items:center; gap:8px;
  background:rgba(56,189,248,.12); border:1px solid rgba(56,189,248,.25);
  color:#38bdf8; font-size:11px; font-weight:700; letter-spacing:.08em;
  text-transform:uppercase; padding:6px 18px; border-radius:30px; margin-bottom:16px;
}
.benefit-strip-owner .bs-eyebrow {
  background:rgba(2,132,199,.08); border:1px solid rgba(2,132,199,.2);
  color:var(--sky-600);
}
.bs-head h2 {
  font-size:clamp(28px,4vw,44px); letter-spacing:-1px; line-height:1.12;
  margin-bottom:12px;
}
.benefit-strip-owner .bs-head h2 { color:var(--sky-900); }
.benefit-strip-agency .bs-head h2 { color:white; }
.bs-head p { font-size:16px; max-width:620px; margin:0 auto; }
.benefit-strip-owner .bs-head p { color:var(--sky-700); }
.benefit-strip-agency .bs-head p { color:rgba(255,255,255,.65); }

.bs-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:20px; }

/* Owner benefit cards */
.bs-card-owner {
  background:white; border:1px solid var(--sky-100); border-radius:22px;
  padding:28px 24px; transition:.25s;
}
.bs-card-owner:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(2,132,199,.12); }
.bs-card-icon { font-size:36px; margin-bottom:14px; display:block; }
.bs-card-owner h3 { font-size:17px; color:var(--sky-900); margin-bottom:8px; }
.bs-card-owner p { font-size:13px; color:var(--sky-700); line-height:1.65; }
.bs-card-tag {
  display:inline-block; background:var(--sky-50); color:var(--sky-600);
  font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px;
  margin-top:12px; border:1px solid var(--sky-200);
}

/* Agency benefit cards */
.bs-card-agency {
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
  border-radius:22px; padding:28px 24px; transition:.25s;
  backdrop-filter:blur(8px);
}
.bs-card-agency:hover { transform:translateY(-5px); background:rgba(255,255,255,.10); border-color:rgba(56,189,248,.3); }
.bs-card-agency h3 { font-size:17px; color:white; margin-bottom:8px; }
.bs-card-agency p { font-size:13px; color:rgba(255,255,255,.62); line-height:1.65; }
.bs-card-tag-agency {
  display:inline-block; background:rgba(56,189,248,.12); color:#38bdf8;
  font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px;
  margin-top:12px; border:1px solid rgba(56,189,248,.25);
}

/* Switch audience bar */
.bs-switch {
  text-align:center; margin-top:44px;
}
.bs-switch-btn {
  background:transparent; border:1.5px solid var(--sky-300); color:var(--sky-600);
  padding:10px 28px; border-radius:40px; cursor:pointer;
  font-family:'Bricolage Grotesque',sans-serif; font-size:14px; font-weight:600;
  transition:.2s; display:inline-flex; align-items:center; gap:8px;
}
.bs-switch-btn:hover { background:var(--sky-50); }
.benefit-strip-agency .bs-switch-btn {
  border-color:rgba(255,255,255,.25); color:rgba(255,255,255,.7);
}
.benefit-strip-agency .bs-switch-btn:hover { background:rgba(255,255,255,.08); }

@media(max-width:600px) {
  .gw-choices { flex-direction:column; align-items:center; }
  .gw-choice { width:100%; max-width:360px; }
}
</style>
</head>
<body class="gw-open">

<!-- ═════════════ GATEWAY ════════════════════════════ -->
<div id="gateway">

  <!-- Blobs FIRST so they sit behind everything -->
  <div class="gw-blobs">
    <div class="gw-blob gw-blob-1"></div>
    <div class="gw-blob gw-blob-2"></div>
    <div class="gw-blob gw-blob-3"></div>
  </div>

  <!-- Top-left logo — comes AFTER blobs so it renders on top -->
  <div class="gw-topleft">
    <span class="gw-logo-fixed"><span>Video</span>Vizard</span>
  </div>

  <!-- Top-right CTA button -->
  <div class="gw-topbar">
    <a href="login.php" class="gw-topbar-btn">
      Start Here <i class="fas fa-arrow-right"></i>
    </a>
  </div>

  <!-- Centred content -->
  <div class="gw-inner">
    <h1 class="gw-question">
      Choose your path
    </h1>
    <p class="gw-sub">Tell us how you work — we’ll tailor everything instantly.</p>
    <div class="gw-choices">

      <div class="gw-choice" onclick="selectAudience('owner')">
        <span class="gw-choice-icon">🏢</span>
        <div class="gw-choice-title">I run my own business or brand</div>
        <div class="gw-choice-desc">
          I want to grow my social media presence without spending hours creating content or hiring a manager.
        </div>
        <div class="gw-choice-cta">Show me what I can do <i class="fas fa-arrow-right"></i></div>
      </div>

      <div class="gw-choice" onclick="selectAudience('agency')">
        <span class="gw-choice-icon">🎯</span>
        <div class="gw-choice-title">I manage social media for clients</div>
        <div class="gw-choice-desc">
          I handle multiple brands and need to create, schedule, and report — faster and more efficiently.
        </div>
        <div class="gw-choice-cta">Show me what I can do <i class="fas fa-arrow-right"></i></div>
      </div>

    </div>
  </div>
</div>

<div class="blobs">
  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>
  <div class="blob blob-3"></div>
</div>

<!-- NAV -->
<nav id="navbar">
  <a href="#" class="nav-logo" style="flex-direction:column;align-items:flex-start;gap:0;">
    <span><span class="logo-v">Video</span><span class="logo-v2">Vizard</span></span>
    <span style="font-size:10px;font-weight:500;color:#64748b;letter-spacing:.01em;font-family:'Instrument Sans',sans-serif;line-height:1;">By IT Top Talent Inc</span>
    <span class="nav-pill">AI</span>
    <span style="display:block;font-size:9px;font-weight:500;color:#64748b;letter-spacing:.03em;margin-top:1px;line-height:1;">by IT Top Talent Inc</span>
  </a>
  <div class="nav-links">
    <a href="#how">How It Works</a>
    <a href="#features">Features</a>
    <a href="#platforms">Platforms</a>
    <a href="#pricing">Pricing</a>
    <a href="login.php" class="nav-cta">Start Here →</a>
  </div>
  <div class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <a href="#how">How It Works</a>
  <a href="#features">Features</a>
  <a href="#platforms">Platforms</a>
  <a href="#pricing">Pricing</a>
  <a href="login.php" class="mob-cta">Start Free →</a>
</div>
<div class="mob-overlay" id="mobOverlay"></div>

<main>

<!-- ═════════════ OWNER BENEFIT STRIP ════════════════ -->
<div class="benefit-strip benefit-strip-owner" id="strip-owner">
  <div class="bs-inner">
    <div class="bs-head">
      <div class="bs-eyebrow">✦ Made for Business Owners</div>
      <h2>Your social media,<br>on autopilot — finally.</h2>
      <p>You didn't start your business to become a content creator. VideoVizard handles everything — ideas, scripts, videos, scheduling — so you can focus on what you do best.</p>
    </div>
    <div class="btns reveal reveal-delay-3">
      <a href="login.php" class="btn-main">✨ Start your first video here</a>
    </div>
    <div class="bs-grid">
      <div class="bs-card-owner">
        <span class="bs-card-icon">💡</span>
        <h3>Just bring an idea</h3>
        <p>Tell us your niche and what you want to promote. VideoVizard generates video ideas, hooks, scripts, and visuals — all tailored to your business.</p>
        <span class="bs-card-tag">⏱ 2 minutes</span>
      </div>
      <div class="bs-card-owner">
        <span class="bs-card-icon">🎬</span>
        <h3>Let VideoVizard build the video for you</h3>
        <p>AI assembles complete videos with voiceover, captions, B-roll visuals, and your branding — all done automatically. You just watch it come to life.</p>
        <span class="bs-card-tag">✨ AI-powered</span>
      </div>
      <div class="bs-card-owner">
        <span class="bs-card-icon">🚀</span>
        <h3>Post directly to your social platforms</h3>
        <p>Publish straight to TikTok, Instagram, YouTube, and Facebook — on your schedule or fully automatically. No copy-pasting, no logging in to five apps.</p>
        <span class="bs-card-tag">🔁 Set &amp; forget</span>
      </div>
      <div class="bs-card-owner">
        <span class="bs-card-icon">📊</span>
        <h3>Understand Your Growth</h3>
        <p>Track followers, views, engagement, and top-performing content across your connected platforms. Make smarter decisions with real insights.</p>
        <span class="bs-card-tag">📈 Live analytics</span>
      </div>
    </div>
    <div style="text-align:center; margin-top:36px;">
      <div style="display:inline-flex; align-items:center; gap:12px; background:white; border:2px solid var(--sky-200); border-radius:50px; padding:16px 36px; box-shadow:0 8px 24px rgba(2,132,199,.12);">
        <span style="font-size:26px;">⏱</span>
        <span style="font-family:'Bricolage Grotesque',sans-serif; font-size:clamp(17px,2.2vw,22px); font-weight:800; color:var(--sky-900); letter-spacing:-0.5px;">
          Total time you spent: <span style="color:var(--sky-600);">2.5 minutes.</span>
        </span>
      </div>
    </div>
    <div class="bs-switch">
      <button class="bs-switch-btn" onclick="selectAudience('agency')">
        <i class="fas fa-exchange-alt"></i> I'm a social media manager instead
      </button>
    </div>
  </div>
</div>

<!-- ═════════════ AGENCY BENEFIT STRIP ════════════════ -->
<div class="benefit-strip benefit-strip-agency" id="strip-agency">
  <div class="bs-inner">
    <div class="bs-head">
      <div class="bs-eyebrow">✦ Built for Social Media Managers</div>
      <h2>Deliver more for every client.<br><span style="color:#38bdf8;">In less time.</span></h2>
      <p>Stop spending your hours on repetitive content creation. VideoVizard gives you a command centre to manage every client's content, campaigns, and publishing — from one place.</p>
    </div>
    <div class="btns reveal reveal-delay-3">
      <a href="login.php" class="btn-main">✨ Start your first video here</a>
    </div>
    <div class="bs-grid">
      <div class="bs-card-agency">
        <span class="bs-card-icon">🗂️</span>
        <h3>One workspace per client</h3>
        <p>Add unlimited clients, each with their own brand settings, campaigns, voice, and content calendar. No more juggling spreadsheets.</p>
        <span class="bs-card-tag-agency">∞ Clients</span>
      </div>
      <div class="bs-card-agency">
        <span class="bs-card-icon">⚡</span>
        <h3>10× your content output</h3>
        <p>Create a full month of videos for any client in under 15 minutes. AI writes the scripts, builds the scenes, and queues the posts.</p>
        <span class="bs-card-tag-agency">⏱ 15 min / month</span>
      </div>
      <div class="bs-card-agency">
        <span class="bs-card-icon">✅</span>
        <h3>Client approval built in</h3>
        <p>Share a preview link with your client so they can review and approve content before it goes live. Clean, professional, zero back-and-forth.</p>
        <span class="bs-card-tag-agency">Approval flow</span>
      </div>
      <div class="bs-card-agency">
        <span class="bs-card-icon">📊</span>
        <h3>Analytics across all clients</h3>
        <p>Monitor followers, views, and engagement for every client from a single dashboard. Prove your value with real numbers, not guesswork.</p>
        <span class="bs-card-tag-agency">Live analytics</span>
      </div>
    </div>
    <div class="bs-switch">
      <button class="bs-switch-btn" onclick="selectAudience('owner')">
        <i class="fas fa-exchange-alt"></i> I'm a business owner instead
      </button>
    </div>
  </div>
</div>

<!-- ═════════════ HERO ════════════════════════════ -->
<section class="hero">
  <div class="hero-inner">

    <!-- Audience toggle -->
    <div class="aud-toggle reveal">
      <button class="aud-btn active" data-aud="owner">For Business Owners</button>
      <button class="aud-btn" data-aud="agency">For SM Managers</button>
    </div>

    <div class="hero-kicker reveal reveal-delay-1" id="aud-kicker-owner">
      <span class="dot-green"></span>
      No video skills needed &bull; No SM manager needed
    </div>
    <div class="hero-kicker reveal reveal-delay-1" id="aud-kicker-agency" style="display:none">
      <span class="dot-green"></span>
      Manage all your clients &bull; One powerful dashboard
    </div>

    <h1 class="reveal reveal-delay-1">
      <span id="aud-h1-owner">One idea.<br><em>A month of content.</em><br>In minutes.</span>
      <span id="aud-h1-agency" style="display:none">Run every client.<br><em>10x faster.</em><br>From one place.</span>
    </h1>

    <p class="hero-sub reveal reveal-delay-2" id="aud-sub-owner">
      Tell VideoVizard your idea, your goal and how long to run it.<br>
      <strong>It writes the hooks, picks the visuals, builds the videos and posts for you — automatically.</strong>
    </p>
    <p class="hero-sub reveal reveal-delay-2" id="aud-sub-agency" style="display:none">
      Add unlimited clients, create separate campaigns for each, and manage everything from one dashboard.<br>
      <strong>VideoVizard does the content work — you keep the client relationship.</strong>
    </p>

    <div class="btns reveal reveal-delay-3">
      <a href="login.php" class="btn-main">✨ Start your first video here</a>
    </div>

    <!-- App Preview Mockup -->
    <div class="app-preview reveal reveal-delay-4">
      <div class="ap-topbar">
        <div class="db-dots">
          <div class="db-dot r"></div><div class="db-dot y"></div><div class="db-dot g"></div>
        </div>
        <div class="ap-tabs">
          <button class="ap-tab active" data-tab="script"><i class="fas fa-magic"></i> Script Generator</button>
          <button class="ap-tab" data-tab="videos"><i class="fas fa-film"></i> My Videos</button>
          <button class="ap-tab" data-tab="editor"><i class="fas fa-cut"></i> Video Editor</button>
          <button class="ap-tab" data-tab="schedule"><i class="fas fa-calendar-alt"></i> Schedule</button>
        </div>
        <div class="ap-url" id="ap-url-text">app.videovizard.com / script</div>
      </div>

      <!-- TAB: Script Generator -->
      <div class="ap-screen active" id="tab-script">
        <div class="sg-layout">
          <div class="sg-form">
            <div class="sg-form-title">✨ Generate Video Script</div>
            <div class="sg-field">
              <label>Your idea or topic</label>
              <div class="sg-input sg-input-lg">Best morning routine for busy entrepreneurs</div>
            </div>
            <div class="sg-row">
              <div class="sg-field">
                <label>Platform</label>
                <div class="sg-input">TikTok / Reels</div>
              </div>
              <div class="sg-field">
                <label>Duration</label>
                <div class="sg-input">30–60 seconds</div>
              </div>
              <div class="sg-field">
                <label>Language</label>
                <div class="sg-input">English</div>
              </div>
            </div>
            <div class="sg-field">
              <label>Tone / Style</label>
              <div class="sg-input">Energetic &amp; Motivational</div>
            </div>
            <button class="sg-btn">🤖 Generate Script</button>
          </div>
          <div class="sg-output">
            <div class="sg-output-label">AI Generated Script <span class="sg-badge">Ready</span></div>
            <div class="sg-script-lines">
              <div class="sg-line sg-hook"><span class="sg-tag">HOOK</span> You're losing 2 hours every morning — and you don't even know it.</div>
              <div class="sg-line"><span class="sg-tag">SCENE</span> Show your messy morning routine flashing on screen.</div>
              <div class="sg-line"><span class="sg-tag">SCRIPT</span> Here's the 5-step routine that changed everything for me...</div>
              <div class="sg-line"><span class="sg-tag">CTA</span> Save this. You'll thank me tomorrow. 🔥</div>
            </div>
            <div class="sg-actions">
              <div class="sg-action-btn">📋 Copy</div>
              <div class="sg-action-btn sg-action-primary">▶ Open in Editor</div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: My Videos -->
      <div class="ap-screen" id="tab-videos">
        <div class="vb-layout">
          <div class="vb-toolbar">
            <div class="vb-search">🔍 Search videos...</div>
            <div class="vb-filters">
              <span class="vb-filter active">All</span>
              <span class="vb-filter">Published</span>
              <span class="vb-filter">Drafts</span>
              <span class="vb-filter">Scheduled</span>
            </div>
          </div>
          <div class="vb-grid">
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#0284c7,#38bdf8)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">0:42</div>
                <div class="vb-lang">EN</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">Morning Routine Hook</div>
                <div class="vb-meta">TikTok · Today</div>
              </div>
              <div class="vb-status published">● Published</div>
            </div>
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#059669,#34d399)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">0:58</div>
                <div class="vb-lang">UR</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">Bakery Special Reel</div>
                <div class="vb-meta">Instagram · Yesterday</div>
              </div>
              <div class="vb-status scheduled">● Scheduled</div>
            </div>
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#7c3aed,#a78bfa)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">1:02</div>
                <div class="vb-lang">AR</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">Product Launch Video</div>
                <div class="vb-meta">YouTube · 2 days ago</div>
              </div>
              <div class="vb-status draft">● Draft</div>
            </div>
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#ea580c,#fb923c)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">0:30</div>
                <div class="vb-lang">HI</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">5 Tips for Beginners</div>
                <div class="vb-meta">Facebook · 3 days ago</div>
              </div>
              <div class="vb-status published">● Published</div>
            </div>
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#0369a1,#7dd3fc)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">0:45</div>
                <div class="vb-lang">EN</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">Behind the Scenes</div>
                <div class="vb-meta">TikTok · 4 days ago</div>
              </div>
              <div class="vb-status scheduled">● Scheduled</div>
            </div>
            <div class="vb-card">
              <div class="vb-thumb" style="background:linear-gradient(145deg,#be185d,#fb7185)">
                <div class="vb-play">▶</div>
                <div class="vb-duration">0:55</div>
                <div class="vb-lang">ES</div>
              </div>
              <div class="vb-info">
                <div class="vb-title">Customer Story Reel</div>
                <div class="vb-meta">Instagram · 5 days ago</div>
              </div>
              <div class="vb-status draft">● Draft</div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB: Video Editor -->
      <div class="ap-screen" id="tab-editor">
        <div class="ve-layout">
          <div class="ve-preview">
            <div class="ve-screen">
              <div class="ve-screen-content">
                <div class="ve-caption-line">You're losing 2 hours every morning</div>
                <div class="ve-caption-sub">— and you don't even know it.</div>
              </div>
              <div class="ve-overlay-controls">
                <div class="ve-ctrl">⏮</div>
                <div class="ve-ctrl ve-play">▶</div>
                <div class="ve-ctrl">⏭</div>
              </div>
            </div>
            <div class="ve-timeline">
              <div class="ve-tl-track ve-tl-video">
                <div class="ve-tl-label">Video</div>
                <div class="ve-tl-bar" style="width:80%;background:linear-gradient(90deg,#0284c7,#38bdf8)"></div>
              </div>
              <div class="ve-tl-track ve-tl-audio">
                <div class="ve-tl-label">Audio</div>
                <div class="ve-tl-bar" style="width:75%;background:linear-gradient(90deg,#059669,#34d399)"></div>
              </div>
              <div class="ve-tl-track ve-tl-caption">
                <div class="ve-tl-label">Captions</div>
                <div class="ve-tl-bar" style="width:80%;background:linear-gradient(90deg,#7c3aed,#a78bfa)"></div>
              </div>
            </div>
          </div>
          <div class="ve-panel">
            <div class="ve-panel-title">Edit Video</div>
            <div class="ve-tool"><i class="fas fa-font"></i> Edit Captions</div>
            <div class="ve-tool"><i class="fas fa-music"></i> Background Music</div>
            <div class="ve-tool"><i class="fas fa-image"></i> Change Thumbnail</div>
            <div class="ve-tool"><i class="fas fa-crop"></i> Trim / Crop</div>
            <div class="ve-tool ve-tool-active"><i class="fas fa-closed-captioning"></i> Subtitles</div>
            <div class="ve-divider"></div>
            <div class="ve-export-btn">📤 Export &amp; Schedule</div>
          </div>
        </div>
      </div>

      <!-- TAB: Schedule Modal -->
      <div class="ap-screen" id="tab-schedule">
        <div class="sch-layout">
          <div class="sch-cal">
            <div class="sch-cal-header">
              <span class="sch-cal-nav">‹</span>
              <span class="sch-cal-month">March 2025</span>
              <span class="sch-cal-nav">›</span>
            </div>
            <div class="sch-cal-grid">
              <div class="sch-day-name">Mon</div><div class="sch-day-name">Tue</div><div class="sch-day-name">Wed</div><div class="sch-day-name">Thu</div><div class="sch-day-name">Fri</div><div class="sch-day-name">Sat</div><div class="sch-day-name">Sun</div>
              <div class="sch-day"></div><div class="sch-day"></div><div class="sch-day"></div><div class="sch-day"></div><div class="sch-day">1</div><div class="sch-day">2</div><div class="sch-day">3</div>
              <div class="sch-day">4</div><div class="sch-day">5</div><div class="sch-day sch-has-post">6<span class="sch-dot"></span></div><div class="sch-day">7</div><div class="sch-day sch-has-post">8<span class="sch-dot"></span></div><div class="sch-day">9</div><div class="sch-day">10</div>
              <div class="sch-day sch-has-post">11<span class="sch-dot"></span></div><div class="sch-day">12</div><div class="sch-day sch-has-post">13<span class="sch-dot"></span></div><div class="sch-day">14</div><div class="sch-day sch-today">15</div><div class="sch-day sch-has-post">16<span class="sch-dot"></span></div><div class="sch-day">17</div>
            </div>
          </div>
          <div class="sch-modal">
            <div class="sch-modal-title">📅 Schedule This Video</div>
            <div class="sch-vid-preview">
              <div class="sch-vid-thumb" style="background:linear-gradient(135deg,#0284c7,#38bdf8)">▶</div>
              <div>
                <div class="sch-vid-name">Morning Routine Hook</div>
                <div class="sch-vid-dur">0:42 · Ready to post</div>
              </div>
            </div>
            <div class="sch-fields">
              <div class="sch-field">
                <label>Post Date</label>
                <div class="sch-input">Friday, March 15</div>
              </div>
              <div class="sch-field">
                <label>Time</label>
                <div class="sch-input">9:00 AM</div>
              </div>
            </div>
            <div class="sch-plat-label">Post to platforms</div>
            <div class="sch-platforms">
              <div class="sch-plat active"><i class="fab fa-tiktok"></i></div>
              <div class="sch-plat active"><i class="fab fa-instagram"></i></div>
              <div class="sch-plat"><i class="fab fa-youtube"></i></div>
              <div class="sch-plat"><i class="fab fa-facebook"></i></div>
            </div>
            <div class="sch-confirm-btn">✅ Confirm Schedule</div>
          </div>
        </div>
      </div>

    </div>
    <div class="btns reveal reveal-delay-3">
      <a href="login.php" class="btn-main">✨ Start your first video here</a>
    </div>
  </div>
</section>

<!-- ═════════════ HOW IT WORKS ═══════════════════ -->
<section id="how" class="hiw">
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-eyebrow">How It Works</span>
      <h2>From idea to 30-day campaign<br>in under 5 minutes</h2>
      <p>No design skills. No writing skills. No scheduling headaches. Just results.</p>
    </div>
    <div class="steps">
      <div class="step-card reveal reveal-delay-1">
        <div class="step-num">01</div>
        <div class="step-icon">💬</div>
        <h3>Tell us your idea</h3>
        <p>Type your topic, campaign goal and how many posts per day you want (1, 2 or 3). That's it. No brief. No creative direction needed.</p>
        <div class="step-time">⏱ 30 seconds</div>
      </div>
      <div class="step-card reveal reveal-delay-2">
        <div class="step-num">02</div>
        <div class="step-icon">🤖</div>
        <h3>AI builds everything</h3>
        <p>VideoVizard writes hooks, finds the best images and video clips, adds captions and assembles ready-to-post videos — automatically.</p>
        <div class="step-time">⏱ Under 2 minutes</div>
      </div>
      <div class="step-card reveal reveal-delay-4">
        <div class="step-num">03</div>
        <div class="step-icon">🚀</div>
        <h3>Sit back &amp; watch it post</h3>
        <p>Your campaign runs on autopilot. Videos go out to TikTok, Instagram, YouTube and Facebook on schedule — every single day.</p>
        <div class="step-time">⏱ 0 effort from you</div>
      </div>
    </div>
  </div>
</section>

<!-- ═════════════ FEATURES ═══════════════════════ -->
<section id="features" class="features">
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-eyebrow">Everything Included</span>
      <h2>Every tool you need,<br>zero expertise required</h2>
      <p>VideoVizard replaces a full content team — writer, editor, translator and scheduler.</p>
    </div>
    <div class="feat-grid">
      <div class="feat-card reveal reveal-delay-1">
        <div class="feat-icon">🧠</div>
        <h3>AI Topic &amp; Hook Generator</h3>
        <p>Can't think of what to post? Just give a keyword. VideoVizard generates dozens of viral-ready topics, hooks and video titles in seconds.</p>
        <span class="feat-tag">No blank page ever again</span>
      </div>
      <div class="feat-card reveal reveal-delay-2">
        <div class="feat-icon">🎬</div>
        <h3>Auto Video Creation</h3>
        <p>AI picks the best stock footage, b-roll and images, adds captions and transitions and delivers a scroll-stopping video — with zero editing from you.</p>
        <span class="feat-tag">Ready to post in minutes</span>
      </div>
      <div class="feat-card reveal reveal-delay-3">
        <div class="feat-icon">📅</div>
        <h3>Full Campaign Scheduling</h3>
        <p>Tell it how long to run (days, weeks, a month) and how many posts a day. VideoVizard queues and publishes everything on the perfect schedule.</p>
        <span class="feat-tag">One setup, weeks of content</span>
      </div>
      <div class="feat-card reveal reveal-delay-1">
        <div class="feat-icon">🌐</div>
        <h3>Multi-Language Content</h3>
        <p>Create once in English, get the exact same video re-created in Urdu, Arabic, Hindi, Spanish and French — reaching a far wider audience.</p>
        <span class="feat-tag">6+ languages supported</span>
      </div>
      <div class="feat-card reveal reveal-delay-2">
        <div class="feat-icon">📲</div>
        <h3>All Platforms, One Place</h3>
        <p>TikTok, Instagram Reels, YouTube Shorts and Facebook — VideoVizard formats and posts to all of them simultaneously from one dashboard.</p>
        <span class="feat-tag">Post everywhere at once</span>
      </div>
      <div class="feat-card reveal reveal-delay-3">
        <div class="feat-icon">🎙️</div>
        <h3>Podcast-Style Videos</h3>
        <p>Turn your audio or ideas into animated podcast-style videos with waveforms and automated b-roll. Great for thought-leadership content.</p>
        <span class="feat-tag">Audio → video instantly</span>
      </div>
    </div>
  </div>
</section>

<!-- ═════════════ SAVE MONEY SECTION ════════════ -->
<section class="save-sec">
  <div class="container">
    <div class="save-top reveal">
      <span class="sec-eyebrow">Built for Everyone</span>
      <h2>Anyone can do it.<br>No fortune needed.</h2>
      <p>You don't need design skills, video editing experience, or a big budget. Just your idea — VideoVizard handles the rest in minutes.</p>
    </div>
    <div class="save-cards reveal">

      <div class="save-card save-card-dark">
        <div class="sc-icon">💸</div>
        <h3>Run a full month of social media for the cost of a coffee</h3>
        <p>Growing a business shouldn't mean spending a fortune on content creation. With VideoVizard, one idea becomes weeks of professional videos — automatically, affordably, effortlessly.</p>
        <div class="sc-points">
          <div class="sc-point"><i class="fas fa-check"></i> No expensive tools or subscriptions</div>
          <div class="sc-point"><i class="fas fa-check"></i> No agency fees or retainers</div>
          <div class="sc-point"><i class="fas fa-check"></i> No waiting days for content to be ready</div>
        </div>
        <a href="register.php" class="btn-main" style="margin-top:28px;display:inline-flex;">Start Free →</a>
      </div>

      <div class="save-card-stack">
        <div class="save-card save-card-light">
          <div class="sc-icon-sm">⏱️</div>
          <div class="sc-compare">
            <div class="sc-before">
              <div class="sc-before-label">Creating content manually</div>
              <div class="sc-before-val">Days of work</div>
            </div>
            <div class="sc-arrow">→</div>
            <div class="sc-after">
              <div class="sc-after-label">With VideoVizard</div>
              <div class="sc-after-val">Under 5 minutes</div>
            </div>
          </div>
        </div>
        <div class="save-card save-card-light">
          <div class="sc-icon-sm">🧠</div>
          <div class="sc-compare">
            <div class="sc-before">
              <div class="sc-before-label">Thinking of what to post</div>
              <div class="sc-before-val">Hours every week</div>
            </div>
            <div class="sc-arrow">→</div>
            <div class="sc-after">
              <div class="sc-after-label">With VideoVizard</div>
              <div class="sc-after-val">AI handles it all</div>
            </div>
          </div>
        </div>
        <div class="save-card save-card-light">
          <div class="sc-icon-sm">🎬</div>
          <div class="sc-compare">
            <div class="sc-before">
              <div class="sc-before-label">Video editing skills needed</div>
              <div class="sc-before-val">Steep learning curve</div>
            </div>
            <div class="sc-arrow">→</div>
            <div class="sc-after">
              <div class="sc-after-label">With VideoVizard</div>
              <div class="sc-after-val">Zero. None. Nada.</div>
            </div>
          </div>
        </div>
        <div class="save-card save-card-light">
          <div class="sc-icon-sm">📅</div>
          <div class="sc-compare">
            <div class="sc-before">
              <div class="sc-before-label">Posting consistently</div>
              <div class="sc-before-val">Easy to forget &amp; skip</div>
            </div>
            <div class="sc-arrow">→</div>
            <div class="sc-after">
              <div class="sc-after-label">With VideoVizard</div>
              <div class="sc-after-val">Autopilot, every day</div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═════════════ MULTI-CAMPAIGN SECTION ════════════ -->
<section id="agency" class="agency-sec">
  <div class="container">
    <div class="agency-inner reveal">
      <div class="agency-left">
        <span class="sec-eyebrow">Unlimited Campaigns</span>
        <h2>Run multiple campaigns.<br>Multiple clients.<br>Zero extra effort.</h2>
        <p>VideoVizard lets you create and manage as many separate campaigns as you need — each with its own idea, schedule, language, and platform settings. Switch between them instantly from one clean dashboard.</p>
        <ul class="agency-list">
          <li><i class="fas fa-check-circle"></i> <span><strong>Completely separate campaigns</strong> — each runs independently on its own schedule</span></li>
          <li><i class="fas fa-check-circle"></i> <span><strong>Different settings per campaign</strong> — language, platforms, post frequency, duration</span></li>
          <li><i class="fas fa-check-circle"></i> <span><strong>Scale without the stress</strong> — add more campaigns without adding more work</span></li>
          <li><i class="fas fa-check-circle"></i> <span><strong>All posting automatically</strong> — every campaign runs on autopilot simultaneously</span></li>
        </ul>
        <a href="register.php" class="btn-main" style="margin-top:32px;display:inline-flex;">🚀 Create Your First Campaign</a>
      </div>
      <div class="agency-right">
        <div class="agency-mockup">
          <div class="am-header">
            <span class="am-title">All Campaigns</span>
            <span class="am-badge">4 running</span>
          </div>
          <div class="am-clients">
            <div class="am-client">
              <div class="am-client-avatar" style="background:linear-gradient(135deg,#0284c7,#38bdf8)">🍞</div>
              <div class="am-client-info">
                <div class="am-client-name">Lahore Bakery</div>
                <div class="am-client-meta">30-day · 2 posts/day · EN + UR</div>
              </div>
              <div class="am-client-status green">● Live</div>
            </div>
            <div class="am-client">
              <div class="am-client-avatar" style="background:linear-gradient(135deg,#059669,#6ee7b7)">💄</div>
              <div class="am-client-info">
                <div class="am-client-name">GlowUp Cosmetics</div>
                <div class="am-client-meta">14-day · 3 posts/day · EN + AR</div>
              </div>
              <div class="am-client-status green">● Live</div>
            </div>
            <div class="am-client">
              <div class="am-client-avatar" style="background:linear-gradient(135deg,#7c3aed,#c4b5fd)">🏋️</div>
              <div class="am-client-info">
                <div class="am-client-name">FitZone Gym</div>
                <div class="am-client-meta">60-day · 1 post/day · EN + HI</div>
              </div>
              <div class="am-client-status yellow">● Drafting</div>
            </div>
            <div class="am-client">
              <div class="am-client-avatar" style="background:linear-gradient(135deg,#ea580c,#fca5a5)">🏠</div>
              <div class="am-client-info">
                <div class="am-client-name">Karachi Realty</div>
                <div class="am-client-meta">30-day · 2 posts/day · EN + UR</div>
              </div>
              <div class="am-client-status green">● Live</div>
            </div>
          </div>
          <div class="am-footer">
            <div class="am-stat"><span class="am-stat-n">247</span><span class="am-stat-l">Videos queued</span></div>
            <div class="am-stat"><span class="am-stat-n">4</span><span class="am-stat-l">Platforms each</span></div>
            <div class="am-stat"><span class="am-stat-n">100%</span><span class="am-stat-l">Autopilot</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═════════════ PLATFORMS ══════════════════════ -->
<section id="platforms" class="platforms">
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-eyebrow">Platforms</span>
      <h2>Post everywhere.<br>Manage from one place.</h2>
    </div>
    <div class="plat-grid reveal">
      <div class="plat-card">
        <i class="fab fa-tiktok" style="color:#010101"></i>
        <span>TikTok</span>
      </div>
      <div class="plat-card">
        <i class="fab fa-instagram" style="color:#e1306c"></i>
        <span>Instagram</span>
      </div>
      <div class="plat-card">
        <i class="fab fa-youtube" style="color:#ff0000"></i>
        <span>YouTube</span>
      </div>
      <div class="plat-card">
        <i class="fab fa-facebook" style="color:#1877f2"></i>
        <span>Facebook</span>
      </div>
    </div>
  </div>
</section>

<!-- ═════════════ LANGUAGES ══════════════════════ -->
<section class="langs">
  <div class="container">
    <div class="sec-head reveal" style="margin-bottom:36px">
      <span class="sec-eyebrow">Languages</span>
      <h2>Reach your audience<br>in their language</h2>
      <p style="font-size:15px;color:var(--sky-700);margin-top:10px;">17 languages supported — and growing.</p>
    </div>
    <div class="lang-row reveal">
      <div class="lang-chip">🇬🇧 English</div>
      <div class="lang-chip">🇸🇦 العربية Arabic</div>
      <div class="lang-chip">🇪🇸 Español Spanish</div>
      <div class="lang-chip">🇫🇷 Français French</div>
      <div class="lang-chip">🇵🇰 اردو Urdu</div>
      <div class="lang-chip">🇮🇳 हिन्दी Hindi</div>
      <div class="lang-chip">🇮🇳 ગુજરાતી Gujarati</div>
      <div class="lang-chip">🇮🇳 ਪੰਜਾਬੀ Punjabi</div>
      <div class="lang-chip">🇮🇳 தமிழ் Tamil</div>
      <div class="lang-chip">🇨🇳 中文 Mandarin</div>
      <div class="lang-chip">🇮🇷 فارسی Farsi</div>
      <div class="lang-chip">🇧🇩 বাংলা Bengali</div>
      <div class="lang-chip">🇵🇹 Português Portuguese</div>
      <div class="lang-chip">🇷🇺 Русский Russian</div>
      <div class="lang-chip">🇯🇵 日本語 Japanese</div>
      <div class="lang-chip">🇰🇷 한국어 Korean</div>
      <div class="lang-chip">🇹🇷 Türkçe Turkish</div>
    </div>
  </div>
</section>

<!-- ═════════════ PRICING ════════════════════════════ -->
<section id="pricing" class="pricing">
  <div class="container">
    <div class="sec-head reveal">
      <span class="sec-eyebrow">Pricing</span>
      <h2>Start free. Scale when<br>you're ready.</h2>
      <p>No hidden fees. No credit card needed to get started. Upgrade any time as your content needs grow.</p>
    </div>

    <div style="text-align:center;">
      <div class="pricing-toggle reveal">
        <button class="pt-btn active" id="pt-monthly" onclick="setPricingPeriod('monthly')">Monthly</button>
        <button class="pt-btn" id="pt-annual" onclick="setPricingPeriod('annual')">Annual <span class="pt-save">Save 30%</span></button>
      </div>
    </div>

    <div class="pricing-grid reveal">

      <!-- FREE -->
      <div class="price-card">
        <div class="price-name">Free</div>
        <div class="price-amount">
          <span class="price-currency">$</span>
          <span class="price-num">0</span>
        </div>
        <div class="price-period">forever</div>
        <div class="price-desc">Get started with no commitment. Perfect to try VideoVizard.</div>
        <div class="price-divider"></div>
        <ul class="price-features">
          <li><i class="fas fa-check-circle"></i> 30 credits to get started</li>
          <li><i class="fas fa-check-circle"></i> AI script generation</li>
          <li><i class="fas fa-check-circle"></i> Up to 3 scenes per video</li>
          <li><i class="fas fa-check-circle"></i> 1 social platform</li>
          <li><i class="fas fa-check-circle"></i> English only</li>
          <li class="muted"><i class="fas fa-check-circle"></i> Campaigns (locked)</li>
          <li class="muted"><i class="fas fa-check-circle"></i> Scheduling (locked)</li>
          <li class="muted"><i class="fas fa-check-circle"></i> Analytics (locked)</li>
        </ul>
        <a href="register.php" class="price-btn price-btn-ghost">Start Free — No card</a>
        <div class="price-credits">Includes <span>30 credits</span></div>
      </div>

      <!-- STARTER -->
      <div class="price-card">
        <div class="price-name">Starter</div>
        <div class="price-amount">
          <span class="price-currency">$</span>
          <span class="price-num" data-monthly="29" data-annual="20">29</span>
        </div>
        <div class="price-period"><span class="price-period-label">per month</span></div>
        <div class="price-desc">For individuals and small businesses growing their presence.</div>
        <div class="price-divider"></div>
        <ul class="price-features">
          <li><i class="fas fa-check-circle"></i> 200 credits / month</li>
          <li><i class="fas fa-check-circle"></i> AI script + video generation</li>
          <li><i class="fas fa-check-circle"></i> Up to 8 scenes per video</li>
          <li><i class="fas fa-check-circle"></i> 2 social platforms</li>
          <li><i class="fas fa-check-circle"></i> 6 languages</li>
          <li><i class="fas fa-check-circle"></i> Post scheduling</li>
          <li><i class="fas fa-check-circle"></i> Basic analytics</li>
          <li class="muted"><i class="fas fa-check-circle"></i> Multi-client workspace (locked)</li>
        </ul>
        <a href="register.php" class="price-btn price-btn-ghost">Get Started</a>
        <div class="price-credits"><span>200 credits</span> per month</div>
      </div>

      <!-- GROWTH — POPULAR -->
      <div class="price-card popular">
        <div class="popular-badge">Most Popular</div>
        <div class="price-name">Growth</div>
        <div class="price-amount">
          <span class="price-currency">$</span>
          <span class="price-num" data-monthly="59" data-annual="41">59</span>
        </div>
        <div class="price-period"><span class="price-period-label">per month</span></div>
        <div class="price-desc">For creators and businesses posting across multiple platforms daily.</div>
        <div class="price-divider"></div>
        <ul class="price-features">
          <li><i class="fas fa-check-circle"></i> 600 credits / month</li>
          <li><i class="fas fa-check-circle"></i> AI script + video generation</li>
          <li><i class="fas fa-check-circle"></i> Up to 8 scenes per video</li>
          <li><i class="fas fa-check-circle"></i> All 4 platforms</li>
          <li><i class="fas fa-check-circle"></i> 16 languages</li>
          <li><i class="fas fa-check-circle"></i> 30-day campaign builder</li>
          <li><i class="fas fa-check-circle"></i> Full analytics dashboard</li>
          <li><i class="fas fa-check-circle"></i> AI image generation</li>
        </ul>
        <a href="register.php" class="price-btn price-btn-main">Get Growth →</a>
        <div class="price-credits"><span>600 credits</span> per month</div>
      </div>

      <!-- AGENCY -->
      <div class="price-card">
        <div class="price-name">Agency</div>
        <div class="price-amount">
          <span class="price-currency">$</span>
          <span class="price-num" data-monthly="99" data-annual="69">99</span>
        </div>
        <div class="price-period"><span class="price-period-label">per month</span></div>
        <div class="price-desc">For social media managers running multiple client accounts.</div>
        <div class="price-divider"></div>
        <ul class="price-features">
          <li><i class="fas fa-check-circle"></i> 1,500 credits / month</li>
          <li><i class="fas fa-check-circle"></i> Everything in Growth</li>
          <li><i class="fas fa-check-circle"></i> Unlimited client workspaces</li>
          <li><i class="fas fa-check-circle"></i> Client approval portal</li>
          <li><i class="fas fa-check-circle"></i> Team member access</li>
          <li><i class="fas fa-check-circle"></i> White-label ready</li>
          <li><i class="fas fa-check-circle"></i> Priority support</li>
          <li><i class="fas fa-check-circle"></i> All 4 platforms + analytics</li>
        </ul>
        <a href="register.php" class="price-btn price-btn-ghost">Get Agency</a>
        <div class="price-credits"><span>1,500 credits</span> per month</div>
      </div>

    </div>

    <div class="pricing-footnote reveal">
      <strong>1 credit = 1 AI video scene generated.</strong> A typical 30-second video uses 7–8 credits. Credits reset every month. Unused credits do not roll over.
    </div>
  </div>
</section>

<!-- ═════════════ FINAL CTA ═══════════════════════ -->
<section class="final-cta">
  <div class="final-card reveal">
    <h2>Your next 30 days of content<br>is two minutes away.</h2>
    <p>Whether you're a business owner or a social media manager — VideoVizard handles the content so you can focus on what matters.</p>
    <div class="btns">
      <a href="register.php" class="btn-main">🚀 Start Free — No card needed</a>
      <a href="login.php" class="btn-ghost">Already have an account →</a>
    </div>
    <div class="final-trust">
      <div class="trust-item"><i class="fas fa-check-circle"></i> No credit card required</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> Works for businesses &amp; agencies</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> Cancel any time</div>
      <div class="trust-item"><i class="fas fa-check-circle"></i> Posts in 6+ languages</div>
    </div>
  </div>
</section>
</main>

<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-brand-name"><span class="logo-v">Video</span><span class="logo-v2">Vizard</span></div>
      <div style="font-size:12px;color:#64748b;margin-top:-8px;margin-bottom:10px;font-weight:500;">by IT Top Talent Inc</div>
      <p class="footer-desc">VideoVizard is a project of IT Top Talent Inc — delivering AI-powered social media video creation and scheduling. From one idea to a full month of content, in minutes.</p>
    </div>
    <div class="footer-col">
      <h4>Product</h4>
      <a href="#">Features</a>
      <a href="#">Pricing</a>
      <a href="#">Platforms</a>
      <a href="#">Languages</a>
    </div>
    <div class="footer-col">
      <h4>Languages</h4>
      <a href="#">English</a>
      <a href="#">اردو Urdu</a>
      <a href="#">العربية Arabic</a>
      <a href="#">हिन्दी Hindi</a>
    </div>
    <div class="footer-col">
      <h4>Legal</h4>
      <a href="privacy.php">Privacy Policy</a>
      <a href="terms.php">Terms of Service</a>
      <a href="#">Contact Us</a>
    </div>
  </div>
  <div class="footer-bottom">
    <div>© 2025 VideoVizard by IT Top Talent Inc. All rights reserved.</div>
    <div>⚡ One idea → a month of content</div>
  </div>
</footer>

<script>
// ═══════════════════ SHARED HERO TOGGLE MAP ══════════════
const heroToggleMap = {
  owner:  ['aud-kicker-owner','aud-h1-owner','aud-sub-owner'],
  agency: ['aud-kicker-agency','aud-h1-agency','aud-sub-agency'],
};

function applyHeroAudience(aud) {
  document.querySelectorAll('.aud-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.aud === aud);
  });
  Object.values(heroToggleMap).flat().forEach(id => {
    const el = document.getElementById(id);
    if(el) el.style.display = 'none';
  });
  heroToggleMap[aud].forEach(id => {
    const el = document.getElementById(id);
    if(el) el.style.display = '';
  });
}

// ═══════════════════ GATEWAY ════════════════════════
const gateway = document.getElementById('gateway');

function selectAudience(aud) {
  gateway.classList.add('hidden');
  document.body.classList.remove('gw-open'); // restore scrolling
  document.getElementById('strip-owner').classList.toggle('visible', aud === 'owner');
  document.getElementById('strip-agency').classList.toggle('visible', aud === 'agency');
  applyHeroAudience(aud);
}

// ═══════════════════ APP PREVIEW TABS ═══════════════
const apTabs = document.querySelectorAll('.ap-tab');
const apScreens = document.querySelectorAll('.ap-screen');
const apUrl = document.getElementById('ap-url-text');
const urlMap = {
  script:   'app.videovizard.com / script-generator',
  videos:   'app.videovizard.com / my-videos',
  editor:   'app.videovizard.com / editor',
  schedule: 'app.videovizard.com / schedule'
};
apTabs.forEach(tab => {
  tab.addEventListener('click', () => {
    const t = tab.dataset.tab;
    apTabs.forEach(b => b.classList.toggle('active', b.dataset.tab === t));
    apScreens.forEach(s => s.classList.toggle('active', s.id === 'tab-'+t));
    if(apUrl) apUrl.textContent = urlMap[t] || '';
  });
});

// ═══════════════════ HERO AUDIENCE TOGGLE (buttons) ══
document.querySelectorAll('.aud-btn').forEach(btn => {
  btn.addEventListener('click', () => applyHeroAudience(btn.dataset.aud));
});

// NAV SCROLL
const nav = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  nav.classList.toggle('scrolled', window.scrollY > 50);
});

// HAMBURGER
const hbBtn   = document.getElementById('hamburgerBtn');
const mobMenu = document.getElementById('mobileMenu');
const mobOvl  = document.getElementById('mobOverlay');

function toggleMob(open) {
  mobMenu.classList.toggle('open', open);
  mobOvl.classList.toggle('active', open);
  hbBtn.classList.toggle('active', open);
  const icon = hbBtn.querySelector('i');
  icon.className = open ? 'fas fa-times' : 'fas fa-bars';
}
hbBtn.addEventListener('click', e => { e.stopPropagation(); toggleMob(!mobMenu.classList.contains('open')); });
mobOvl.addEventListener('click', () => toggleMob(false));
document.querySelectorAll('.mobile-menu a').forEach(a => a.addEventListener('click', () => toggleMob(false)));

// SMOOTH SCROLL
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    e.preventDefault();
    const t = document.querySelector(a.getAttribute('href'));
    if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
    toggleMob(false);
  });
});

// REVEAL ON SCROLL
const revealObs = new IntersectionObserver((entries) => {
  entries.forEach(en => { if(en.isIntersecting) en.target.classList.add('visible'); });
}, { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach(el => revealObs.observe(el));

// HERO IMMEDIATE REVEAL
setTimeout(() => {
  document.querySelectorAll('.hero .reveal').forEach(el => el.classList.add('visible'));
}, 100);

// PRICING TOGGLE
function setPricingPeriod(period) {
  document.getElementById('pt-monthly').classList.toggle('active', period === 'monthly');
  document.getElementById('pt-annual').classList.toggle('active', period === 'annual');
  document.querySelectorAll('.price-num[data-monthly]').forEach(el => {
    el.textContent = period === 'annual' ? el.dataset.annual : el.dataset.monthly;
  });
  document.querySelectorAll('.price-period-label').forEach(el => {
    el.textContent = period === 'annual' ? 'per month, billed annually' : 'per month';
  });
}
</script>
</body>
</html>
