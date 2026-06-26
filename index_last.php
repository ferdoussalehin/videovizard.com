<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>VideoVizard — from idea to video in minutes</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
  <!-- Font Awesome for hamburger & icons (lightweight) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'DM Sans', sans-serif;
    line-height: 1.6;
    overflow-x: hidden;
    background: var(--bg-primary);
    color: var(--text-secondary);
    transition: background-color 0.2s, color 0.2s;
  }
  h1, h2, h3, h4, h5, h6 {
    font-family: 'Syne', sans-serif;
    font-weight: 700;
    line-height: 1.2;
    color: var(--text-primary);
  }

  /* ===== LIGHT BLUE (default & only – as requested) ===== */
  [data-theme="light-blue"] {
    --bg-primary: #F0F9FF;
    --bg-secondary: #E6F3FF;
    --bg-tertiary: #D9EEFF;
    --accent-primary: #0284C7;
    --accent-secondary: #38BDF8;
    --accent-tertiary: #059669;
    --text-primary: #0C4A6E;
    --text-secondary: #1E3A5F;
    --text-tertiary: #334155;
    --border-light: rgba(2, 132, 199, 0.15);
    --border-hover: rgba(2, 132, 199, 0.3);
    --glow-primary: rgba(2, 132, 199, 0.15);
    --card-bg: rgba(255, 255, 255, 0.9);
    --nav-bg: rgba(255, 255, 255, 0.85);
    --nav-bg-solid: rgba(255, 255, 255, 0.96);
  }
  body { background: var(--bg-primary); } /* enforce */

  /* remove other theme dots / switcher – keep only light blue */
  .theme-switcher { display: none; }

  /* Animated Background (soft) */
  #particles-js { display: none; } /* optional disable */
  .gradient-bg {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle at 20% 30%, rgba(2,132,199,0.08) 0%, transparent 40%),
                radial-gradient(circle at 80% 70%, rgba(56,189,248,0.08) 0%, transparent 40%);
    z-index: 0;
    pointer-events: none;
  }

  /* Navigation */
  nav {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    width: 90%;
    max-width: 1200px;
    padding: 12px 28px;
    background: var(--nav-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-light);
    border-radius: 60px;
    z-index: 1000;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.3s ease;
  }
  nav.scrolled {
    top: 0;
    border-radius: 0 0 30px 30px;
    background: var(--nav-bg-solid);
    border-top: none;
    border-left: none;
    border-right: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.05);
  }
  .nav-brand {
    font-family: 'Syne', sans-serif;
    font-size: 26px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-primary);
    text-decoration: none;
    letter-spacing: -0.5px;
  }
  .brand-video { background: linear-gradient(135deg, #0284C7, #0C4A6E); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
  .brand-vizard { color: #0C4A6E; }

  /* desktop menu */
  .nav-links {
    display: flex;
    align-items: center;
    gap: 36px;
  }
  .nav-links a {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
    transition: color 0.2s;
    position: relative;
  }
  .nav-links a::after {
    content: '';
    position: absolute;
    bottom: -6px;
    left: 0;
    width: 0;
    height: 2px;
    background: var(--accent-primary);
    transition: width 0.2s;
  }
  .nav-links a:hover { color: var(--text-primary); }
  .nav-links a:hover::after { width: 100%; }
  .nav-cta {
    background: var(--accent-primary);
    color: white !important;
    padding: 10px 26px !important;
    border-radius: 40px;
    font-weight: 600 !important;
    box-shadow: 0 6px 14px var(--glow-primary);
  }
  .nav-cta::after { display: none !important; }
  .nav-cta:hover { background: #0369a1; transform: translateY(-2px); }

  /* ----- HAMBURGER (mobile only) ----- */
  .hamburger {
    display: none;
    font-size: 28px;
    cursor: pointer;
    color: var(--text-primary);
    transition: color 0.2s;
    z-index: 1100;
  }
  .hamburger.active { color: var(--accent-primary); }

  /* mobile menu overlay */
  .mobile-menu {
    position: fixed;
    top: 0;
    right: -100%;
    width: 280px;
    height: 100vh;
    background: var(--nav-bg-solid);
    backdrop-filter: blur(20px);
    border-left: 1px solid var(--border-light);
    padding: 100px 30px 40px;
    display: flex;
    flex-direction: column;
    gap: 25px;
    transition: right 0.4s cubic-bezier(0.2, 0.9, 0.3, 1);
    z-index: 1050;
    box-shadow: -10px 0 30px rgba(0,0,0,0.05);
  }
  .mobile-menu.open { right: 0; }
  .mobile-menu a {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 20px;
    font-weight: 500;
    font-family: 'Syne', sans-serif;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
  }
  .mobile-menu a:last-child { border-bottom: none; }
  .mobile-menu .mobile-cta {
    background: var(--accent-primary);
    color: white;
    text-align: center;
    padding: 16px;
    border-radius: 50px;
    margin-top: 20px;
    border: none;
    font-weight: 700;
  }

  /* overlay background when menu open */
  .menu-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.2);
    backdrop-filter: blur(2px);
    z-index: 1040;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
  }
  .menu-overlay.active { opacity: 1; pointer-events: all; }

  /* Main content */
  main { position: relative; z-index: 1; }

  /* Hero */
  .hero {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 140px 24px 80px;
    position: relative;
  }
  .hero-content { max-width: 1200px; margin: 0 auto; text-align: center; }
  .hero-badge {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(2,132,199,0.08);
    border: 1px solid var(--border-light);
    color: var(--accent-primary);
    padding: 8px 22px; border-radius: 50px;
    font-size: 13px; font-weight: 600;
    margin-bottom: 30px; backdrop-filter: blur(8px);
  }
  .hero-badge .pulse {
    width: 8px; height: 8px; background: #059669;
    border-radius: 50%; animation: pulse 2s infinite;
  }
  @keyframes pulse { 0%{opacity:1; transform:scale(1)} 50%{opacity:0.5; transform:scale(1.5)} }

  .hero h1 {
    font-size: clamp(44px, 9vw, 86px);
    margin-bottom: 24px;
    letter-spacing: -2px;
  }
  .hero h1 .gradient-text {
    background: linear-gradient(135deg, #0284C7, #0C4A6E);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .hero-subtitle {
    font-size: clamp(16px, 2vw, 20px);
    color: var(--text-secondary);
    max-width: 750px;
    margin: 0 auto 30px;
  }

  /* feature pills */
  .feature-pills {
    display: flex; flex-wrap: wrap; gap: 12px;
    justify-content: center; margin: 40px 0;
  }
  .pill {
    background: var(--card-bg); border: 1px solid var(--border-light);
    padding: 8px 20px; border-radius: 50px; font-size: 14px;
    color: var(--text-secondary); display: flex; align-items: center; gap: 8px;
    backdrop-filter: blur(8px);
  }
  .pill-icon { color: var(--accent-primary); }

  .cta-buttons {
    display: flex; gap: 16px; justify-content: center;
    margin: 40px 0; flex-wrap: wrap;
  }
  .btn-primary {
    background: var(--accent-primary); color: white;
    border: none; padding: 16px 38px; border-radius: 60px;
    font-size: 16px; font-weight: 600; font-family: 'Syne', sans-serif;
    cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 10px;
    transition: all 0.2s; box-shadow: 0 8px 20px var(--glow-primary);
  }
  .btn-primary:hover { background: #0369a1; transform: translateY(-3px); }
  .btn-secondary {
    background: transparent; color: var(--text-primary);
    border: 1px solid var(--border-light); padding: 16px 38px;
    border-radius: 60px; font-weight: 600; text-decoration: none;
    backdrop-filter: blur(8px);
  }
  .btn-secondary:hover { border-color: var(--accent-primary); background: var(--card-bg); }

  /* dashboard mockup (same but colors match) */
  .dashboard-mockup {
    margin-top: 60px; background: white; border: 1px solid #cbd5e1;
    border-radius: 28px; padding: 24px; backdrop-filter: blur(12px);
    box-shadow: 0 30px 50px -20px rgba(2,132,199,0.3);
    animation: float 5s ease-in-out infinite;
  }
  @keyframes float { 0%{transform:translateY(0)} 50%{transform:translateY(-8px)} }

  .mockup-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
  .mockup-dots { display: flex; gap: 8px; }
  .mockup-dot { width: 12px; height: 12px; border-radius: 50%; background: #94a3b8; }
  .mockup-dot.red { background: #EF4444; }
  .mockup-dot.yellow { background: #F59E0B; }
  .mockup-dot.green { background: #10B981; }
  .mockup-title { color: #475569; font-size: 14px; font-family: monospace; }
  .mockup-grid { display: grid; grid-template-columns: 200px 1fr; gap: 20px; }
  .mockup-sidebar { background: #f1f5f9; border-radius: 20px; padding: 16px; }
  .sidebar-item { padding: 12px; border-radius: 12px; color: #1e293b; display: flex; gap: 12px; font-size: 13px; }
  .sidebar-item.active { background: #0284C7; color: white; }
  .mockup-main { display: flex; flex-direction: column; gap: 16px; }
  .reels-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; }
  .reel-card { aspect-ratio: 9/16; border-radius: 16px; padding: 12px; display: flex; flex-direction: column; justify-content: flex-end; position: relative; overflow: hidden; background: linear-gradient(145deg, #0284C7, #38BDF8); }
  .reel-card::before { content: ''; position: absolute; inset:0; background: linear-gradient(to top, rgba(0,0,0,0.3), transparent); }
  .reel-icon { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 28px; z-index:1; }
  .reel-badge { position: absolute; top: 8px; right: 8px; background: #059669; color: white; font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; z-index:1; }
  .reel-label { position: relative; z-index:1; font-size: 12px; font-weight: 600; color: white; }
  .stats-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-top: 16px; }
  .stat-card { background: #f8fafc; border-radius: 16px; padding: 16px; border:1px solid #e2e8f0; }
  .stat-number { font-size: 24px; font-weight: 800; color: #0284C7; }
  .stat-label { font-size: 11px; color: #475569; }

  /* features / etc */
  .features-section, .cta-section { padding: 100px 24px; }
  .section-header { text-align: center; max-width: 700px; margin: 0 auto 60px; }
  .section-label { background: white; border: 1px solid var(--border-light); color: #0284C7; padding: 6px 18px; border-radius: 40px; font-size: 12px; font-weight: 600; margin-bottom: 20px; display: inline-block; }
  .features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap: 30px; max-width: 1200px; margin: 0 auto; }
  .feature-card { background: white; border: 1px solid #e2e8f0; border-radius: 28px; padding: 32px; transition: 0.2s; }
  .feature-icon { width: 60px; height: 60px; background: #f0f9ff; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 20px; color: #0284C7; }
  .feature-card h3 { font-size: 20px; margin-bottom: 12px; color: #0C4A6E; }
  .feature-card p { color: #334155; }
  .languages-strip { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; padding: 0 24px; }
  .language-item { background: white; border: 1px solid #e2e8f0; padding: 8px 22px; border-radius: 50px; display: flex; align-items: center; gap: 10px; font-size: 15px; color: #1e3a5f; }
  .cta-card { background: white; border: 1px solid #cbd5e1; border-radius: 50px; padding: 80px 40px; text-align: center; max-width: 1000px; margin: 0 auto; box-shadow: 0 30px 40px -20px rgba(2,132,199,0.2); }

  /* footer */
  footer { background: white; border-top: 1px solid #e2e8f0; padding: 60px 24px 30px; }
  .footer-content { max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 60px; }
  .footer-brand { font-family: 'Syne', sans-serif; font-size: 24px; font-weight: 800; }
  .footer-desc { color: #334155; font-size: 14px; }
  .footer-column h4 { font-size: 14px; color: #64748b; margin-bottom: 20px; }
  .footer-column a { display: block; color: #1e3a5f; text-decoration: none; font-size: 14px; padding: 6px 0; }
  .footer-bottom { max-width: 1200px; margin: 0 auto; padding-top: 30px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; color: #475569; }

  /* responsive */
  @media (max-width: 968px) {
    .nav-links { display: none; }
    .hamburger { display: block; }
    .mockup-grid { grid-template-columns: 1fr; }
    .mockup-sidebar { display: none; }
    .reels-grid { grid-template-columns: repeat(2,1fr); }
    .footer-content { grid-template-columns: repeat(2,1fr); }
    nav { padding: 12px 24px; }
  }
  @media (max-width: 568px) {
    .hero h1 { font-size: 44px; }
    .reels-grid { grid-template-columns: 1fr; }
    .cta-card { padding: 50px 24px; }
    .footer-content { grid-template-columns: 1fr; }
  }

  /* fade animation */
  .fade-up { animation: fadeUp 0.5s ease forwards; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
  .delay-1 { animation-delay:0.1s; } .delay-2 { animation-delay:0.2s; } .delay-3 { animation-delay:0.3s; } .delay-4 { animation-delay:0.4s; } .delay-5 { animation-delay:0.5s; }
</style>
</head>
<body data-theme="light-blue">   <!-- only light blue stays -->

<div class="gradient-bg"></div>

<!-- Navigation -->
<nav id="navbar">
  <a href="#" class="nav-brand">
    <span class="brand-video">Video</span><span class="brand-vizard">Vizard</span>
  </a>
  <div class="nav-links">
    <a href="#features">Features</a>
    <a href="#how">How It Works</a>
    <a href="#pricing">Pricing</a>
    <a href="#faq">FAQ</a>
    <a href="login.php">Log In</a>
    <a href="register.php" class="nav-cta">Start Free →</a>
  </div>
  <div class="hamburger" id="hamburgerBtn"><i class="fas fa-bars"></i></div>
</nav>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
  <a href="#features">Features</a>
  <a href="#how">How It Works</a>
  <a href="#pricing">Pricing</a>
  <a href="#faq">FAQ</a>
  <a href="login.php">Log In</a>
  <a href="register.php" class="mobile-cta">Start Free →</a>
</div>
<div class="menu-overlay" id="menuOverlay"></div>

<main>
  <!-- Hero Section -->
  <section class="hero">
    <div class="hero-content">
      <div class="hero-badge fade-up">
        <span class="pulse"></span>
        AI · from idea to video in minutes
      </div>
      <h1 class="fade-up delay-1">
        from idea to <span class="gradient-text">create/schedule</span><br>in minutes
      </h1>
      <p class="hero-subtitle fade-up delay-2">
        Generate stunning reels, b-roll, and podcast-style videos with AI.<br>
        Auto-translate into 6+ languages and schedule posts automatically.<br>
        <strong>Let AI generate topics & video titles for you.</strong>
      </p>
      <div class="feature-pills fade-up delay-3">
        <span class="pill"><span class="pill-icon">🤖</span> AI topic generator</span>
        <span class="pill"><span class="pill-icon">🎬</span> Reels & B-roll</span>
        <span class="pill"><span class="pill-icon">🌐</span> 6 languages</span>
        <span class="pill"><span class="pill-icon">📆</span> auto‑schedule</span>
        <span class="pill"><span class="pill-icon">📝</span> video titles</span>
      </div>
      <div class="cta-buttons fade-up delay-4">
        <a href="register.php" class="btn-primary">✨ Start Free — No card</a>
        <a href="#features" class="btn-secondary">▶ See magic</a>
      </div>

      <!-- Dashboard Mockup (same vibe) -->
      <div class="dashboard-mockup fade-up delay-5">
        <div class="mockup-header">
          <div class="mockup-dots"><span class="mockup-dot red"></span><span class="mockup-dot yellow"></span><span class="mockup-dot green"></span></div>
          <span class="mockup-title">VideoVizard / dashboard</span>
        </div>
        <div class="mockup-grid">
          <div class="mockup-sidebar">
            <div class="sidebar-item active">📊 Dashboard</div>
            <div class="sidebar-item">🎞️ Content</div>
            <div class="sidebar-item">🌎 Translate</div>
            <div class="sidebar-item">📅 Queue</div>
          </div>
          <div class="mockup-main">
            <div class="reels-grid">
              <div class="reel-card"><div class="reel-icon">🧠</div><span class="reel-badge">AI</span><span class="reel-label">Topic ideas</span></div>
              <div class="reel-card"><div class="reel-icon">🎥</div><span class="reel-badge">EN</span><span class="reel-label">B-roll pack</span></div>
              <div class="reel-card"><div class="reel-icon">🎙️</div><span class="reel-badge">ES</span><span class="reel-label">Podcast</span></div>
              <div class="reel-card"><div class="reel-icon">📈</div><span class="reel-badge">HI</span><span class="reel-label">Viral reel</span></div>
            </div>
            <div class="stats-grid">
              <div class="stat-card"><div class="stat-number">1.2k</div><div class="stat-label">Ideas generated</div></div>
              <div class="stat-card"><div class="stat-number">6</div><div class="stat-label">languages</div></div>
              <div class="stat-card"><div class="stat-number">24/7</div><div class="stat-label">auto-schedule</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Features (same structure, fresh headings) -->
  <section id="features" class="features-section">
    <div class="section-header">
      <span class="section-label">POWERFUL AI FEATURES</span>
      <h2>Generate topics, titles & videos</h2>
      <p>From a single idea to scheduled posts – fully automated</p>
    </div>
    <div class="features-grid">
      <div class="feature-card"><div class="feature-icon">💡</div><h3>AI topic generator</h3><p>Input a keyword, get dozens of viral-ready topics and video titles in seconds.</p></div>
      <div class="feature-card"><div class="feature-icon">🎞️</div><h3>Reels & B-roll</h3><p>Auto-generate short-form videos with stock footage, transitions and captions.</p></div>
      <div class="feature-card"><div class="feature-icon">🌎</div><h3>6+ languages</h3><p>Translate and dub your content into English, Urdu, Arabic, Hindi, Spanish, French.</p></div>
      <div class="feature-card"><div class="feature-icon">📅</div><h3>Auto-schedule</h3><p>Plan your content calendar and publish automatically to TikTok, Reels, YouTube.</p></div>
      <div class="feature-card"><div class="feature-icon">📝</div><h3>Video titles & hooks</h3><p>Let AI write click‑worthy titles, descriptions and hooks that drive views.</p></div>
      <div class="feature-card"><div class="feature-icon">🎙️</div><h3>Podcast-style videos</h3><p>Turn audio into animated podcasts with waveforms and automated b-roll.</p></div>
    </div>
  </section>

  <!-- Language strip -->
  <section style="padding:20px 0">
    <div class="languages-strip">
      <div class="language-item"><span class="language-flag">🇬🇧</span> English</div>
      <div class="language-item"><span class="language-flag">🇵🇰</span> اردو</div>
      <div class="language-item"><span class="language-flag">🇸🇦</span> العربية</div>
      <div class="language-item"><span class="language-flag">🇮🇳</span> हिन्दी</div>
      <div class="language-item"><span class="language-flag">🇪🇸</span> Español</div>
      <div class="language-item"><span class="language-flag">🇫🇷</span> Français</div>
      <div class="language-item"><span>➕</span> more soon</div>
    </div>
  </section>

  <!-- CTA -->
  <section class="cta-section">
    <div class="cta-card">
      <h2>Start with one idea.<br>Let AI handle the rest.</h2>
      <p>Join 3,200+ creators using VideoVizard to scale content</p>
      <div class="cta-buttons">
        <a href="register.php" class="btn-primary">🚀 Try free — no card</a>
        <a href="login.php" class="btn-secondary">Log in →</a>
      </div>
    </div>
  </section>
</main>

<!-- Footer -->
<footer>
  <div class="footer-content">
    <div><div class="footer-brand"><span class="brand-video">Video</span><span class="brand-vizard">Vizard</span></div><p class="footer-desc">AI video creation: from idea to published in minutes.</p></div>
    <div class="footer-column"><h4>Product</h4><a href="#">Features</a><a href="#">Pricing</a><a href="#">Integrations</a></div>
    <div class="footer-column"><h4>Languages</h4><a href="#">English</a><a href="#">اردو</a><a href="#">العربية</a><a href="#">हिन्दी</a></div>
    <div class="footer-column"><h4>Legal</h4><a href="#">Privacy</a><a href="#">Terms</a></div>
  </div>
  <div class="footer-bottom"><div>© 2025 VideoVizard. All rights reserved.</div><div>⚡ from idea to video</div></div>
</footer>

<script>
  // HAMBURGER MENU LOGIC
  const hamburger = document.getElementById('hamburgerBtn');
  const mobileMenu = document.getElementById('mobileMenu');
  const overlay = document.getElementById('menuOverlay');

  function toggleMenu(force) {
    mobileMenu.classList.toggle('open', force);
    overlay.classList.toggle('active', force);
    hamburger.classList.toggle('active', force);
    // change icon (optional)
    const icon = hamburger.querySelector('i');
    if (mobileMenu.classList.contains('open')) {
      icon.classList.remove('fa-bars');
      icon.classList.add('fa-times');
    } else {
      icon.classList.remove('fa-times');
      icon.classList.add('fa-bars');
    }
  }

  hamburger.addEventListener('click', (e) => {
    e.stopPropagation();
    toggleMenu(!mobileMenu.classList.contains('open'));
  });

  overlay.addEventListener('click', () => toggleMenu(false));

  // close on link click
  document.querySelectorAll('.mobile-menu a').forEach(link => {
    link.addEventListener('click', () => toggleMenu(false));
  });

  // navbar scroll
  window.addEventListener('scroll', () => {
    const nav = document.getElementById('navbar');
    nav.classList.toggle('scrolled', window.scrollY > 50);
  });

  // smooth scroll
  document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
      e.preventDefault();
      const target = document.querySelector(this.getAttribute('href'));
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      toggleMenu(false); // also close menu if open
    });
  });

  // optional: keep light blue only (already default)
  localStorage.setItem('preferred-theme', 'light-blue'); // force

  // intersection observer for fade
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('fade-up'); });
  }, { threshold: 0.1 });
  document.querySelectorAll('.feature-card, .stat-card, .language-item').forEach(el => observer.observe(el));
</script>
</body>
</html>