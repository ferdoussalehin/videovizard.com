<?php
// ============================================
// client_approval.php — Client Approval & Social Hub Portal
// ============================================
session_start();
include 'dbconnect_hdb.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: client_approval.php');
    exit;
}

$is_logged_in    = isset($_SESSION['client_company_id']);
$client_company  = $_SESSION['client_company'] ?? '';
$client_username = $_SESSION['client_username'] ?? '';
$client_id       = $_SESSION['client_company_id'] ?? 0;

// ── Change this to wherever your media files are stored ──────
$media_base_url  = 'https://videovizard.com';

// ─── Check existing social OAuth tokens ────────────────────────
$social_connected = [];
if ($is_logged_in && $client_id) {
    $stmt = $conn->prepare("SELECT platform FROM hdb_oauth_tokens WHERE user_id = ? AND client_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $client_id, $client_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $social_connected[$row['platform']] = true;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VideoVizard · Client Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
  --sky-50:   #f0f9ff;
  --sky-100:  #e0f2fe;
  --sky-200:  #bae6fd;
  --sky-400:  #38bdf8;
  --sky-500:  #0ea5e9;
  --sky-600:  #0284c7;
  --sky-700:  #0369a1;
  --sky-900:  #0c4a6e;
  --navy:     #062236;
  --emerald:  #059669;
  --amber:    #f59e0b;
  --white:    #ffffff;
  --card:     rgba(255,255,255,0.95);
  --glass:    rgba(255,255,255,0.72);
  --border:   rgba(2,132,199,0.14);
  --shadow:   0 20px 60px -10px rgba(2,132,199,0.20);
  --text:     #0c4a6e;
  --text-mid: #0369a1;
  --text-muted: #64748b;
  --radius: 16px;
  --fb:   #1877f2; --ig:   #e1306c; --yt:   #ff0000;
  --tk:   #010101; --x:    #000000; --li:   #0077b5;
}
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }
body {
  font-family:'Instrument Sans',sans-serif;
  background: var(--sky-50);
  color: var(--sky-900);
  min-height:100vh;
  overflow-x:hidden;
  line-height:1.6;
}
h1,h2,h3,h4,h5 { font-family:'Bricolage Grotesque',sans-serif; line-height:1.15; }
body::before {
  content:'';
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  opacity:.35;
}
.blobs { position:fixed; inset:0; z-index:0; pointer-events:none; overflow:hidden; }
.blob { position:absolute; border-radius:50%; filter:blur(90px); opacity:0.14; animation:drift 20s ease-in-out infinite alternate; }
.blob-1 { width:700px;height:700px;background:var(--sky-400);top:-220px;left:-100px;animation-duration:22s; }
.blob-2 { width:500px;height:500px;background:var(--sky-600);bottom:-150px;right:-80px;animation-duration:28s;animation-delay:-10s; }
.blob-3 { width:320px;height:320px;background:var(--emerald);top:55%;left:55%;animation-duration:17s;animation-delay:-5s; }
@keyframes drift { from{transform:translate(0,0) scale(1);} to{transform:translate(40px,30px) scale(1.10);} }
@keyframes fadeUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes modalIn{from{opacity:0;transform:scale(.93);}to{opacity:1;transform:scale(1);}}

.login-wrap { min-height:100vh;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;padding:20px; }
.login-card { background:rgba(255,255,255,0.92);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:28px;padding:48px 40px;width:100%;max-width:420px;box-shadow:var(--shadow);animation:fadeUp .5s cubic-bezier(.22,.68,0,1.2) both; }
.login-brand { font-family:'Bricolage Grotesque',sans-serif;font-size:30px;font-weight:800;letter-spacing:-0.5px;margin-bottom:4px; }
.login-brand .lv { color:var(--sky-600); }
.login-brand .lv2 { color:var(--sky-900); }
.login-badge { display:inline-flex;align-items:center;gap:6px;background:rgba(2,132,199,0.08);border:1px solid var(--border);color:var(--sky-700);padding:5px 14px;border-radius:40px;font-size:12px;font-weight:600;letter-spacing:.02em;margin-bottom:28px; }
.dot-green{width:7px;height:7px;background:var(--emerald);border-radius:50%;animation:blink 2s infinite;}
.login-label { font-size:11px;font-weight:700;color:var(--sky-600);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;display:block; }
.login-input { width:100%;padding:14px 16px;background:var(--sky-50);border:1.5px solid var(--sky-200);border-radius:12px;color:var(--sky-900);font-size:15px;font-family:'Instrument Sans',sans-serif;margin-bottom:16px;transition:border-color .2s,box-shadow .2s; }
.login-input:focus { outline:none; border-color:var(--sky-500); box-shadow:0 0 0 4px rgba(14,165,233,.10); }
.login-btn { width:100%;padding:15px;background:var(--sky-600);border:none;border-radius:50px;color:#fff;font-family:'Bricolage Grotesque',sans-serif;font-size:16px;font-weight:700;cursor:pointer;box-shadow:0 8px 24px rgba(2,132,199,0.30);transition:all .2s; }
.login-btn:hover { background:var(--sky-700);transform:translateY(-2px);box-shadow:0 14px 32px rgba(2,132,199,0.35); }
.login-err { background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;font-size:13px;color:#991b1b;margin-bottom:16px;display:none; }
.login-trust { margin-top:24px;display:flex;flex-direction:column;gap:8px; }
.login-trust-item { display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-muted); }
.login-trust-item i { color:var(--emerald);font-size:11px; }

.app { display:flex;flex-direction:column;min-height:100vh;position:relative;z-index:1; }
.topbar { background:rgba(255,255,255,0.88);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:62px;position:sticky;top:0;z-index:200; }
.topbar-brand { font-family:'Bricolage Grotesque',sans-serif;font-size:22px;font-weight:800;letter-spacing:-0.4px;text-decoration:none;display:flex;align-items:center;gap:4px; }
.logo-v  { color:var(--sky-600); }
.logo-v2 { color:var(--sky-900); }
.topbar-right { display:flex;align-items:center;gap:16px; }
.topbar-user { font-size:13px;color:var(--text-muted); }
.topbar-user strong { color:var(--sky-900);font-weight:600; }
.logout-btn { padding:8px 18px;background:var(--sky-50);border:1.5px solid var(--sky-200);border-radius:20px;color:var(--text-muted);font-size:13px;cursor:pointer;text-decoration:none;transition:all .2s;font-weight:500; }
.logout-btn:hover { border-color:#fca5a5;color:#991b1b; }
.main { flex:1;max-width:1440px;margin:0 auto;width:100%;padding:28px 22px 60px; }

.social-hub { background:rgba(255,255,255,0.92);backdrop-filter:blur(24px);border:1px solid var(--border);border-radius:24px;padding:32px 32px 28px;margin-bottom:28px;box-shadow:var(--shadow);animation:fadeUp .45s cubic-bezier(.22,.68,0,1.2) both; }
.social-hub-head { display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px; }
.social-hub-title { font-size:22px;font-weight:800;letter-spacing:-0.4px;color:var(--sky-900);margin-bottom:4px; }
.social-hub-sub { font-size:13px;color:var(--text-muted);max-width:520px;line-height:1.6; }
.hub-badge { display:inline-flex;align-items:center;gap:6px;background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.18);color:var(--emerald);padding:6px 14px;border-radius:40px;font-size:11px;font-weight:700;letter-spacing:.04em;white-space:nowrap;flex-shrink:0; }
.platforms-row { display:grid;grid-template-columns:repeat(3,1fr);gap:12px; }
@media(min-width:600px){.platforms-row{grid-template-columns:repeat(6,1fr);}}
.platform-tile { display:flex;flex-direction:column;align-items:center;gap:10px;padding:18px 12px;border-radius:16px;cursor:pointer;border:1.5px solid var(--sky-200);background:var(--sky-50);transition:all .25s;position:relative;overflow:hidden; }
.platform-tile:hover { transform:translateY(-3px); box-shadow:0 10px 30px rgba(0,0,0,.10); }
.platform-tile.connected { border-color:var(--emerald);background:rgba(5,150,105,.05); }
.platform-tile.connected:hover { box-shadow:0 10px 30px rgba(5,150,105,.14); }
.pt-icon { font-size:28px;transition:transform .2s; }
.platform-tile:hover .pt-icon { transform:scale(1.15); }
.pt-name { font-size:11px;font-weight:700;color:var(--sky-900);letter-spacing:.02em; }
.pt-status { position:absolute;top:8px;right:8px;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800; }
.pt-status.conn  { background:var(--emerald);color:#fff; }
.pt-status.disc  { background:var(--sky-200);color:var(--sky-700); }
.platform-tile[data-platform="facebook"]:hover { border-color:var(--fb); }
.platform-tile[data-platform="instagram"]:hover { border-color:var(--ig); }
.platform-tile[data-platform="youtube"]:hover   { border-color:var(--yt); }
.platform-tile[data-platform="tiktok"]:hover    { border-color:#555; }
.platform-tile[data-platform="x"]:hover         { border-color:#555; }
.platform-tile[data-platform="linkedin"]:hover  { border-color:var(--li); }
.connect-btn { margin-top:4px;padding:4px 12px;border-radius:20px;border:1.5px solid currentColor;background:transparent;font-size:10px;font-weight:700;cursor:pointer;letter-spacing:.03em;transition:all .2s;font-family:'Instrument Sans',sans-serif; }
.connect-btn.connected-btn { color:var(--emerald); }
.connect-btn.disconnected-btn { color:var(--sky-600); }
.connect-btn:hover { background:currentColor;color:#fff; }

.analytics-row { display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:28px; }
@media(min-width:600px){ .analytics-row{grid-template-columns:repeat(4,1fr);} }
.an-card { background:rgba(255,255,255,.9);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:18px;padding:20px;text-align:center;animation:fadeUp .5s ease both;transition:transform .2s,box-shadow .2s; }
.an-card:hover { transform:translateY(-3px);box-shadow:0 14px 36px rgba(2,132,199,.12); }
.an-icon { font-size:26px;margin-bottom:8px; }
.an-num { font-family:'Bricolage Grotesque',sans-serif;font-size:28px;font-weight:800;background:linear-gradient(135deg,var(--sky-500),var(--sky-700));-webkit-background-clip:text;-webkit-text-fill-color:transparent; }
.an-label { font-size:12px;color:var(--text-muted);font-weight:500;margin-top:2px; }
.an-trend { font-size:11px;color:var(--emerald);font-weight:600;margin-top:4px; }

.section-head { margin-bottom:20px; }
.section-title { font-size:22px;font-weight:800;color:var(--sky-900);letter-spacing:-0.3px; }
.section-sub   { font-size:13px;color:var(--text-muted);margin-top:3px; }

.tabs { display:flex;gap:4px;background:var(--sky-100);border:1px solid var(--sky-200);border-radius:14px;padding:5px;margin-bottom:22px;width:fit-content; }
.tab-btn { padding:9px 22px;border-radius:10px;border:none;cursor:pointer;font-family:'Instrument Sans',sans-serif;font-size:13px;font-weight:600;color:var(--sky-700);background:transparent;transition:all .2s; }
.tab-btn.active { background:var(--sky-600);color:#fff;box-shadow:0 4px 14px rgba(2,132,199,.28); }
.tab-btn:hover:not(.active) { background:rgba(2,132,199,.08); }

.cards-grid { display:grid;grid-template-columns:repeat(2,1fr);gap:14px; }
@media(min-width:600px){.cards-grid{grid-template-columns:repeat(3,1fr);}}
@media(min-width:900px){.cards-grid{grid-template-columns:repeat(4,1fr);}}
@media(min-width:1200px){.cards-grid{grid-template-columns:repeat(6,1fr);}}
.ig-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:3px; }
@media(min-width:600px){.ig-grid{grid-template-columns:repeat(4,1fr);}}
@media(min-width:900px){.ig-grid{grid-template-columns:repeat(5,1fr);}}
@media(min-width:1200px){.ig-grid{grid-template-columns:repeat(6,1fr);}}
.ig-cell { aspect-ratio:9/16;position:relative;overflow:hidden;cursor:pointer;background:var(--sky-100);transition:transform .15s; }
.ig-cell:hover { transform:scale(1.02);z-index:2; }
.ig-cell img   { width:100%;height:100%;object-fit:cover;display:block; }
.ig-cell-ph { width:100%;height:100%;background:linear-gradient(135deg,var(--sky-900),var(--sky-700));display:flex;align-items:center;justify-content:center;font-size:32px; }
.ig-cell-overlay { position:absolute;inset:0;background:rgba(0,0,0,0);transition:background .18s;display:flex;align-items:flex-end;justify-content:center;padding-bottom:8px; }
.ig-cell:hover .ig-cell-overlay { background:rgba(0,0,0,0.40); }
.ig-cell-title { color:#fff;font-size:11px;font-weight:600;text-align:center;padding:0 6px;opacity:0;transition:opacity .18s;text-shadow:0 1px 4px rgba(0,0,0,.8);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%; }
.ig-cell:hover .ig-cell-title { opacity:1; }
.v-card { background:rgba(255,255,255,.92);border:1.5px solid var(--sky-200);border-radius:var(--radius);overflow:hidden;cursor:pointer;transition:all .25s;aspect-ratio:9/16;display:flex;flex-direction:column;position:relative; }
.v-card:hover { border-color:var(--sky-500);transform:translateY(-4px);box-shadow:0 14px 36px rgba(2,132,199,.15); }
.v-card-thumb { width:100%;flex:1;object-fit:cover;display:block;background:var(--sky-100); }
.v-card-thumb-ph { width:100%;flex:1;background:linear-gradient(135deg,var(--sky-900),var(--sky-700));display:flex;align-items:center;justify-content:center;font-size:40px; }
.v-card-body { padding:10px 10px 12px; }
.v-card-title { font-size:12px;font-weight:600;color:var(--sky-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px; }
.v-card-meta  { font-size:11px;color:var(--text-muted); }
.v-badge { position:absolute;top:8px;left:8px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;z-index:5; }
.badge-pending  { background:#fff7ed;color:#c2410c; }
.badge-approved { background:#f0fdf4;color:#166534; }
.badge-changes  { background:#fef2f2;color:#991b1b; }
.empty-state { text-align:center;padding:60px 20px;color:var(--text-muted); }
.empty-icon  { font-size:52px;margin-bottom:14px; }
.empty-title { font-size:18px;font-weight:700;color:var(--sky-900);margin-bottom:6px; }
.load-more-wrap { text-align:center;margin-top:24px; }
.load-more-btn { padding:12px 36px;background:var(--sky-50);border:1.5px solid var(--sky-200);border-radius:50px;color:var(--sky-700);font-size:14px;font-weight:600;cursor:pointer;transition:all .2s; }
.load-more-btn:hover { border-color:var(--sky-500);color:var(--sky-600);background:var(--sky-100); }
.spinner-wrap { text-align:center;padding:48px; }
.spinner { width:38px;height:38px;border:3px solid var(--sky-200);border-top-color:var(--sky-600);border-radius:50%;animation:spin .7s linear infinite;margin:0 auto 14px; }
.ig-hint { display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--sky-100);border:1px solid var(--sky-200);border-radius:10px;font-size:13px;color:var(--sky-700);margin-bottom:14px; }
.ig-hint strong { font-weight:700; }

.modal-overlay { display:none;position:fixed;inset:0;background:rgba(6,34,54,0.88);z-index:1000;align-items:center;justify-content:center;padding:16px; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff;border-radius:24px;width:100%;max-width:500px;max-height:92vh;overflow-y:auto;animation:modalIn .3s cubic-bezier(.22,.68,0,1.2) both; }
.modal-header { display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--sky-200); }
.modal-title { font-family:'Bricolage Grotesque',sans-serif;font-size:18px;font-weight:700;color:var(--sky-900); }
.modal-back { padding:8px 16px;border-radius:20px;border:1.5px solid var(--sky-200);background:var(--sky-50);color:var(--sky-900);font-size:13px;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap; }
.modal-back:hover { background:var(--sky-900);color:#fff;border-color:var(--sky-900); }
.modal-close { width:34px;height:34px;border-radius:50%;border:1.5px solid var(--sky-200);background:var(--sky-50);color:var(--text-muted);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s; }
.modal-close:hover { border-color:#fca5a5;color:#991b1b; }
.modal-video-wrap { padding:16px; }
.modal-video { width:100%;border-radius:14px;background:#000;display:block;max-height:55vh;object-fit:contain; }
.no-video-msg { width:100%;aspect-ratio:9/16;max-height:55vh;background:var(--sky-100);border-radius:14px;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:14px;flex-direction:column;gap:8px; }
.modal-info   { padding:0 22px 10px; }
.modal-status { display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;margin-bottom:12px; }
.modal-meta { font-size:13px;color:var(--text-muted);line-height:1.8; }
.modal-meta strong { color:var(--sky-900); }
.modal-actions { padding:16px 22px;border-top:1px solid var(--sky-200);display:flex;flex-direction:column;gap:12px; }
.modal-actions-row { display:flex;gap:10px; }
.action-btn { flex:1;padding:14px;border-radius:12px;border:none;cursor:pointer;font-family:'Instrument Sans',sans-serif;font-size:14px;font-weight:700;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px; }
.btn-approve { background:var(--emerald);color:#fff;box-shadow:0 4px 14px rgba(5,150,105,.30); }
.btn-approve:hover { background:#047857;transform:translateY(-1px); }
.btn-changes { background:var(--sky-50);border:1.5px solid var(--sky-200);color:var(--sky-900); }
.btn-changes:hover { border-color:#fca5a5;color:#991b1b; }
.feedback-section { display:none;animation:fadeUp .25s ease both; }
.feedback-label { font-size:13px;font-weight:600;color:var(--sky-900);margin-bottom:8px; }
.feedback-textarea { width:100%;padding:12px 14px;background:var(--sky-50);border:1.5px solid var(--sky-200);border-radius:10px;color:var(--sky-900);font-family:'Instrument Sans',sans-serif;font-size:14px;resize:vertical;min-height:90px;transition:border-color .2s; }
.feedback-textarea:focus { outline:none;border-color:var(--sky-500); }
.submit-feedback-btn { width:100%;padding:14px;background:var(--amber);border:none;border-radius:12px;color:#fff;font-family:'Instrument Sans',sans-serif;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s; }
.submit-feedback-btn:hover { background:#d97706; }
.result-msg { padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;text-align:center;display:none; }
.result-success { background:#f0fdf4;border:1px solid #86efac;color:#166534; }
.result-error   { background:#fef2f2;border:1px solid #fca5a5;color:#991b1b; }
.actioned-badge { padding:14px;border-radius:12px;text-align:center;font-size:14px;font-weight:600; }
.actioned-approved { background:#f0fdf4;border:1px solid #86efac;color:#166534; }
.actioned-changes  { background:#fef2f2;border:1px solid #fca5a5;color:#991b1b; }
.actioned-feedback { background:var(--sky-50);border:1px solid var(--sky-200);border-radius:10px;padding:12px;font-size:13px;color:var(--text-muted);margin-top:10px;line-height:1.6; }
.actioned-feedback strong { color:var(--sky-900);display:block;margin-bottom:4px; }

.social-modal-overlay { display:none;position:fixed;inset:0;background:rgba(6,34,54,.85);z-index:2000;align-items:center;justify-content:center;padding:20px; }
.social-modal-overlay.open { display:flex; }
.social-modal-box { background:#fff;border-radius:24px;width:100%;max-width:440px;padding:36px 32px;animation:modalIn .3s cubic-bezier(.22,.68,0,1.2) both; }
.smodal-icon   { font-size:48px;text-align:center;margin-bottom:14px; }
.smodal-title  { font-family:'Bricolage Grotesque',sans-serif;font-size:22px;font-weight:800;text-align:center;color:var(--sky-900);margin-bottom:6px; }
.smodal-sub    { font-size:14px;color:var(--text-muted);text-align:center;line-height:1.6;margin-bottom:24px; }
.smodal-btn { display:block;width:100%;padding:14px;border-radius:50px;border:none;cursor:pointer;font-family:'Bricolage Grotesque',sans-serif;font-size:15px;font-weight:700;color:#fff;text-align:center;margin-bottom:10px;transition:all .2s;box-shadow:0 6px 20px rgba(0,0,0,.12); }
.smodal-btn:hover { transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.18); }
.smodal-cancel { display:block;width:100%;padding:12px;border-radius:50px;border:1.5px solid var(--sky-200);background:transparent;font-family:'Instrument Sans',sans-serif;font-size:14px;font-weight:600;color:var(--text-muted);cursor:pointer;text-align:center;transition:all .2s; }
.smodal-cancel:hover { border-color:var(--sky-400);color:var(--sky-700); }
.smodal-note   { font-size:11px;color:var(--text-muted);text-align:center;margin-top:14px;line-height:1.5; }

@media(max-width:640px){
  .social-hub { padding:20px 16px; }
  .analytics-row { grid-template-columns:repeat(2,1fr); }
  .main { padding:18px 14px 50px; }
}
</style>
</head>
<body>

<div class="blobs">
  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>
  <div class="blob blob-3"></div>
</div>

<?php if (!$is_logged_in): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand"><span class="lv">Video</span><span class="lv2">Vizard</span></div>
    <div class="login-badge"><span class="dot-green"></span> Client Portal</div>
    <div class="login-err" id="loginErr"></div>
    <label class="login-label">Username</label>
    <input type="text" class="login-input" id="loginUser" placeholder="Your username" autocomplete="username">
    <label class="login-label">Password</label>
    <input type="password" class="login-input" id="loginPass" placeholder="••••••••" autocomplete="current-password">
    <button class="login-btn" onclick="doLogin()">Sign In →</button>
    <div class="login-trust">
      <div class="login-trust-item"><i class="fas fa-check-circle"></i> Review & approve your videos</div>
      <div class="login-trust-item"><i class="fas fa-check-circle"></i> Connect your social accounts</div>
      <div class="login-trust-item"><i class="fas fa-check-circle"></i> Watch your posts go live</div>
    </div>
  </div>
</div>

<?php else: ?>
<div class="app">
  <div class="topbar">
    <div class="topbar-brand"><span class="logo-v">Video</span><span class="logo-v2">Vizard</span></div>
    <div class="topbar-right">
      <div class="topbar-user">Welcome, <strong><?= htmlspecialchars($client_company) ?></strong></div>
      <a href="?logout=1" class="logout-btn">Sign Out</a>
    </div>
  </div>

  <div class="main">
    <div class="social-hub">
      <div class="social-hub-head">
        <div>
          <div class="social-hub-title">🔗 Your Social Accounts</div>
          <div class="social-hub-sub">Connect your social media accounts below. Once linked, we'll publish your approved videos automatically — and you'll see real results in your analytics.</div>
        </div>
        <div class="hub-badge"><span class="dot-green"></span> FREE Trial — 7 Days of Posting</div>
      </div>
      <div class="platforms-row">
        <?php
        $platforms = [
          'facebook'  => ['Facebook',  'fab fa-facebook',  '#1877f2'],
          'instagram' => ['Instagram', 'fab fa-instagram', '#e1306c'],
          'youtube'   => ['YouTube',   'fab fa-youtube',   '#ff0000'],
          'tiktok'    => ['TikTok',    'fab fa-tiktok',    '#010101'],
          'x'         => ['X / Twitter','fab fa-x-twitter','#000000'],
          'linkedin'  => ['LinkedIn',  'fab fa-linkedin',  '#0077b5'],
        ];
        foreach ($platforms as $key => [$name, $icon, $color]):
          $conn_flag = isset($social_connected[$key]) ? 'connected' : '';
          $btnClass  = $conn_flag ? 'connected-btn' : 'disconnected-btn';
          $btnLabel  = $conn_flag ? '✓ Connected' : 'Connect';
        ?>
        <div class="platform-tile <?= $conn_flag ?>" data-platform="<?= $key ?>"
             onclick="openSocialModal('<?= $key ?>','<?= $name ?>','<?= $color ?>')">
          <div class="pt-status <?= $conn_flag ? 'conn' : 'disc' ?>"><?= $conn_flag ? '✓' : '+' ?></div>
          <i class="pt-icon <?= $icon ?>" style="color:<?= $color ?>"></i>
          <span class="pt-name"><?= $name ?></span>
          <button class="connect-btn <?= $btnClass ?>" style="color:<?= $conn_flag ? 'var(--emerald)' : $color ?>"
                  onclick="event.stopPropagation();openSocialModal('<?= $key ?>','<?= $name ?>','<?= $color ?>')">
            <?= $btnLabel ?>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="analytics-row" id="analyticsRow">
      <div class="an-card" style="animation-delay:.05s">
        <div class="an-icon">📹</div><div class="an-num" id="anVideos">—</div>
        <div class="an-label">Videos Published</div><div class="an-trend">↑ Loading…</div>
      </div>
      <div class="an-card" style="animation-delay:.10s">
        <div class="an-icon">👁️</div><div class="an-num" id="anViews">—</div>
        <div class="an-label">Est. Total Views</div><div class="an-trend" id="anViewsTrend">Calculating…</div>
      </div>
      <div class="an-card" style="animation-delay:.15s">
        <div class="an-icon">✅</div><div class="an-num" id="anApproved">—</div>
        <div class="an-label">Approved Videos</div><div class="an-trend" id="anApprovedTrend">Loading…</div>
      </div>
      <div class="an-card" style="animation-delay:.20s">
        <div class="an-icon">⏳</div><div class="an-num" id="anPending">—</div>
        <div class="an-label">Awaiting Review</div><div class="an-trend">Action needed</div>
      </div>
    </div>

    <div class="section-head">
      <div class="section-title">🎬 Your Videos</div>
      <div class="section-sub">Review, approve, or request changes — then watch them publish live across your connected platforms.</div>
    </div>

    <div class="tabs">
      <button class="tab-btn active" id="tab-pending"  onclick="switchTab('pending')">⏳ Awaiting Approval</button>
      <button class="tab-btn"        id="tab-history"  onclick="switchTab('history')">✅ Previously Reviewed</button>
      <button class="tab-btn"        id="tab-grid"     onclick="switchTab('grid')">📸 Instagram Grid</button>
      <button class="tab-btn"                          onclick="window.location.href='client_analytics.php'">📊 Analytics</button>
    </div>

    <div class="ig-hint" id="igHint" style="display:none;">
      <span>📱</span>
      <span>Your full grid — exactly how it'll look on Instagram. Click any video to review it.</span>
    </div>

    <div id="videosGrid" class="cards-grid">
      <div class="spinner-wrap" style="grid-column:1/-1;">
        <div class="spinner"></div>
        <p style="color:var(--text-muted);font-size:14px;">Loading your videos…</p>
      </div>
    </div>

    <div class="load-more-wrap" id="loadMoreWrap" style="display:none;">
      <button class="load-more-btn" onclick="loadMore()">Load More</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="videoModal" onclick="closeModal(event)">
  <div class="modal-box" id="modalBox">
    <div class="modal-header">
      <button class="modal-back" onclick="closeModalDirect()">← Back</button>
      <div class="modal-title" id="modalTitle">Video</div>
      <button class="modal-close" onclick="closeModalDirect()">✕</button>
    </div>
    <div class="modal-video-wrap">
      <video id="modalVideo" class="modal-video" controls playsinline>Your browser does not support video.</video>
      <div class="no-video-msg" id="noVideoMsg" style="display:none;">
        <span style="font-size:36px;">🎬</span><span>Video not yet available</span>
      </div>
    </div>
    <div class="modal-info">
      <div class="modal-status" id="modalStatus"></div>
      <div class="modal-meta"   id="modalMeta"></div>
    </div>
    <div class="modal-actions" id="modalActions"></div>
  </div>
</div>

<div class="social-modal-overlay" id="socialModal" onclick="closeSocialModal(event)">
  <div class="social-modal-box">
    <div class="smodal-icon"  id="sModalIcon">📱</div>
    <div class="smodal-title" id="sModalTitle">Connect Account</div>
    <div class="smodal-sub"   id="sModalSub">Authorize VideoVizard to post on your behalf.</div>
    <button class="smodal-btn" id="sModalAuthBtn" onclick="doOAuth()">Authorize →</button>
    <button class="smodal-cancel" onclick="closeSocialModalDirect()">Maybe later</button>
    <div class="smodal-note">We only post content you've approved. You can disconnect any time.</div>
  </div>
</div>

<?php endif; ?>

<script>
<?php if ($is_logged_in): ?>
/* ── STATE ───────────────────────────────────────── */
const MEDIA_BASE = '<?= rtrim($media_base_url, '/') ?>';  // e.g. https://videovizard.com
let currentTab    = 'pending';
let currentPage   = 1;
let hasMore       = false;
let isLoading     = false;
let currentPodcast = null;
let activePlatform = null;

/* ── INIT ────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  isLoading = false;
  loadVideos('pending', 1);
  loadAnalytics();
});

/* ── ANALYTICS ───────────────────────────────────── */
function loadAnalytics() {
  fetch('ajax_approval.php?action=get_client_stats')
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      animateNum('anVideos',   d.total    || 0);
      animateNum('anViews',   (d.total    || 0) * 340);
      animateNum('anApproved', d.approved || 0);
      animateNum('anPending',  d.pending  || 0);
      document.getElementById('anViewsTrend').textContent =
        d.approved > 0 ? `↑ ~${(d.approved*340).toLocaleString()} reach` : 'Approve videos to post';
      document.getElementById('anApprovedTrend').textContent =
        d.approved > 0 ? `${Math.round(d.approved/(d.total||1)*100)}% approval rate` : 'None yet';
    })
    .catch(() => {});
}

function animateNum(id, target) {
  const el = document.getElementById(id);
  if (!el) return;
  let start = 0;
  const dur = 900, step = 16;
  const inc = target / (dur / step);
  const t = setInterval(() => {
    start = Math.min(start + inc, target);
    el.textContent = Math.round(start).toLocaleString();
    if (start >= target) clearInterval(t);
  }, step);
}

/* ── TABS ────────────────────────────────────────── */
function switchTab(tab) {
  currentTab = tab; currentPage = 1;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  const tabEl = document.getElementById('tab-' + tab);
  if (tabEl) tabEl.classList.add('active');
  const igHint = document.getElementById('igHint');
  if (tab === 'grid') {
    igHint.style.display = 'flex';
    loadGridView();
  } else {
    igHint.style.display = 'none';
    document.getElementById('videosGrid').className = 'cards-grid';
    loadVideos(tab, 1);
  }
}

/* ── LOAD VIDEOS ─────────────────────────────────── */
function loadVideos(tab, page, append = false) {
  if (isLoading) return;
  isLoading = true;
  document.getElementById('videosGrid').className = 'cards-grid';
  if (!append) {
    document.getElementById('videosGrid').innerHTML =
      `<div class="spinner-wrap" style="grid-column:1/-1;"><div class="spinner"></div><p style="color:var(--text-muted);font-size:14px;">Loading…</p></div>`;
    document.getElementById('loadMoreWrap').style.display = 'none';
  }
  fetch(`ajax_approval.php?action=get_client_videos&tab=${tab}&page=${page}`)
    .then(r => r.json())
    .then(data => {
      isLoading = false;
      if (!data.success || !data.videos.length) {
        if (!append) {
          document.getElementById('videosGrid').innerHTML =
            `<div class="empty-state" style="grid-column:1/-1;">
              <div class="empty-icon">${tab==='pending'?'⏳':'✅'}</div>
              <div class="empty-title">${tab==='pending'?'No videos awaiting approval':'No reviewed videos yet'}</div>
              <p style="font-size:13px;color:var(--text-muted);margin-top:8px;">${tab==='pending'?'Check back soon — your VideoVizard team is creating content!':'Once you review videos, they\'ll appear here.'}</p>
            </div>`;
        }
        return;
      }
      const html = data.videos.map(v => renderCard(v)).join('');
      const grid = document.getElementById('videosGrid');
      grid.innerHTML = append ? grid.innerHTML + html : html;
      hasMore = data.has_more;
      document.getElementById('loadMoreWrap').style.display = hasMore ? 'block' : 'none';
    })
    .catch(() => {
      isLoading = false;
      document.getElementById('videosGrid').innerHTML =
        `<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">⚠️</div><div class="empty-title">Error loading videos</div></div>`;
    });
}

function loadMore() {
  if (currentTab === 'grid') return;
  currentPage++;
  loadVideos(currentTab, currentPage, true);
}

function loadGridView() {
  isLoading = false;
  const grid = document.getElementById('videosGrid');
  grid.className = 'ig-grid';
  grid.innerHTML = `<div class="spinner-wrap" style="grid-column:1/-1;"><div class="spinner"></div><p style="color:var(--text-muted);font-size:14px;">Loading all videos…</p></div>`;
  document.getElementById('loadMoreWrap').style.display = 'none';
  fetch(`ajax_approval.php?action=get_client_videos&tab=all&page=1&per_page=500`)
    .then(r => r.text())
    .then(raw => {
      let data;
      try { data = JSON.parse(raw); } catch(e) {
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">⚠️</div><div class="empty-title">Server error</div></div>`;
        return;
      }
      if (!data.success || !data.videos || !data.videos.length) {
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">📸</div><div class="empty-title">No videos yet</div></div>`;
        return;
      }
      grid.innerHTML = data.videos.map(v => renderGridCell(v)).join('');
    })
    .catch(() => {
      grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">⚠️</div><div class="empty-title">Error loading grid</div></div>`;
    });
}

/* ── RENDER ──────────────────────────────────────── */
function renderCard(v) {
  const thumb = v.thumbnail ? v.thumbnail.replace(/^.*[\\/]/, '') : '';
  const thumbHtml = thumb
    ? `<img class="v-card-thumb" src="${MEDIA_BASE}/podcast_thumbnails/${esc(thumb)}" loading="lazy"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
       <div class="v-card-thumb-ph" style="display:none;">🎬</div>`
    : `<div class="v-card-thumb-ph">🎬</div>`;
  let badge = '';
  if      (v.approval_status==='approval_required') badge=`<div class="v-badge badge-pending">⏳ Pending</div>`;
  else if (v.approval_status==='approved')           badge=`<div class="v-badge badge-approved">✅ Approved</div>`;
  else if (v.approval_status==='rejected')           badge=`<div class="v-badge badge-changes">❌ Changes</div>`;
  const date = v.approval_sent_at ? v.approval_sent_at.substring(0,10) : '';
  return `<div class="v-card" onclick='openModal(${JSON.stringify(v)})'>
    ${badge}${thumbHtml}
    <div class="v-card-body">
      <div class="v-card-title">${escHtml(v.title||'Untitled')}</div>
      <div class="v-card-meta">${date}</div>
    </div>
  </div>`;
}

function renderGridCell(v) {
  const thumb = v.thumbnail ? v.thumbnail.replace(/^.*[\\/]/,'') : '';
  const inner = thumb
    ? `<img src="${MEDIA_BASE}/podcast_thumbnails/${esc(thumb)}" loading="lazy"
           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
       <div class="ig-cell-ph" style="display:none;">🎬</div>`
    : `<div class="ig-cell-ph">🎬</div>`;
  let badge = '';
  if      (v.approval_status==='approval_required') badge=`<div class="v-badge badge-pending"  style="font-size:9px;padding:3px 7px;top:6px;left:6px;">⏳</div>`;
  else if (v.approval_status==='approved')           badge=`<div class="v-badge badge-approved" style="font-size:9px;padding:3px 7px;top:6px;left:6px;">✅</div>`;
  else if (v.approval_status==='rejected')           badge=`<div class="v-badge badge-changes"  style="font-size:9px;padding:3px 7px;top:6px;left:6px;">❌</div>`;
  return `<div class="ig-cell" onclick='openModal(${JSON.stringify(v)})' title="${escHtml(v.title||'Untitled')}">
    ${inner}${badge}
    <div class="ig-cell-overlay"><div class="ig-cell-title">${escHtml(v.title||'Untitled')}</div></div>
  </div>`;
}

/* ── VIDEO MODAL ─────────────────────────────────── */
function openModal(v) {
  currentPodcast = v;
  document.getElementById('modalTitle').textContent = v.title || 'Untitled';
  const videoEl   = document.getElementById('modalVideo');
  const noVideoEl = document.getElementById('noVideoMsg');
  const videoFile = v.video_filename || v.published_video || '';
  const videoSrc  = videoFile
    ? `${MEDIA_BASE}/published_videos/` + videoFile.replace(/^.*[\\/]/,'')
    : '';
  if (videoSrc) {
    videoEl.src = videoSrc; videoEl.style.display='block'; noVideoEl.style.display='none';
  } else {
    videoEl.src=''; videoEl.style.display='none'; noVideoEl.style.display='flex';
  }
  const statusEl = document.getElementById('modalStatus');
  if      (v.approval_status==='approval_required') { statusEl.style.cssText='background:#fff7ed;color:#c2410c;'; statusEl.textContent='⏳ Awaiting Your Approval'; }
  else if (v.approval_status==='approved')           { statusEl.style.cssText='background:#f0fdf4;color:#166534;'; statusEl.textContent='✅ Approved'; }
  else if (v.approval_status==='rejected')           { statusEl.style.cssText='background:#fef2f2;color:#991b1b;'; statusEl.textContent='🔄 Changes Requested'; }
  document.getElementById('modalMeta').innerHTML =
    `<strong>Sent for review:</strong> ${v.approval_sent_at||'N/A'}<br>
     ${v.approval_received_at?`<strong>Reviewed on:</strong> ${v.approval_received_at}<br>`:''}`;
  const actionsEl = document.getElementById('modalActions');
  if (v.approval_status==='approval_required') {
    actionsEl.innerHTML=`
      <div class="modal-actions-row">
        <button class="action-btn btn-approve" onclick="doApprove(${v.id})">✅ Approve</button>
        <button class="action-btn btn-changes" onclick="showFeedback()">🔄 Request Changes</button>
      </div>
      <div class="feedback-section" id="feedbackSection">
        <div class="feedback-label">What needs to change?</div>
        <textarea class="feedback-textarea" id="feedbackText" placeholder="Describe the changes…"></textarea>
        <button class="submit-feedback-btn" onclick="doReject(${v.id})">Send Feedback →</button>
      </div>
      <div class="result-msg result-success" id="resultSuccess">✅ Response recorded. Thank you!</div>
      <div class="result-msg result-error"   id="resultError">Something went wrong. Try again.</div>`;
  } else if (v.approval_status==='approved') {
    actionsEl.innerHTML=`<div class="actioned-badge actioned-approved">✅ You approved this video${v.approval_received_at?'<div style="font-size:12px;opacity:.7;margin-top:4px;">on '+v.approval_received_at+'</div>':''}</div>`;
  } else if (v.approval_status==='rejected') {
    actionsEl.innerHTML=`<div class="actioned-badge actioned-changes">🔄 Changes requested</div>
    ${v.client_feedback?`<div class="actioned-feedback"><strong>Your feedback:</strong>${escHtml(v.client_feedback)}</div>`:''}`;
  }
  document.getElementById('videoModal').classList.add('open');
}

function closeModal(e)    { if(e.target===document.getElementById('videoModal')) closeModalDirect(); }
function closeModalDirect() {
  document.getElementById('videoModal').classList.remove('open');
  document.getElementById('modalVideo').pause();
  document.getElementById('modalVideo').src='';
}
function showFeedback() {
  const fs = document.getElementById('feedbackSection');
  if(fs){ fs.style.display='block'; fs.querySelector('textarea').focus(); }
}

/* ── APPROVE / REJECT ────────────────────────────── */
async function doApprove(podcastId) {
  const fd=new FormData(); fd.append('action','approve'); fd.append('podcast_id',podcastId);
  try {
    const r=await fetch('ajax_approval.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showResult('success');setTimeout(()=>{closeModalDirect();if(currentTab==='grid')loadGridView();else loadVideos(currentTab,1);loadAnalytics();},1500);}
    else showResult('error');
  } catch{showResult('error');}
}
async function doReject(podcastId) {
  const feedback=document.getElementById('feedbackText')?.value?.trim();
  if(!feedback){alert('Please enter feedback first.');return;}
  const fd=new FormData(); fd.append('action','reject'); fd.append('podcast_id',podcastId); fd.append('feedback',feedback);
  try {
    const r=await fetch('ajax_approval.php',{method:'POST',body:fd});
    const d=await r.json();
    if(d.success){showResult('success');setTimeout(()=>{closeModalDirect();if(currentTab==='grid')loadGridView();else loadVideos(currentTab,1);loadAnalytics();},1500);}
    else showResult('error');
  } catch{showResult('error');}
}
function showResult(type) {
  const el=document.getElementById('result'+(type==='success'?'Success':'Error'));
  if(el) el.style.display='block';
}

/* ── SOCIAL MODAL ────────────────────────────────── */
const platformMeta = {
  facebook:  { icon:'📘', label:'Facebook',    color:'#1877f2', oauthUrl:'oauth_facebook.php' },
  instagram: { icon:'📸', label:'Instagram',   color:'#e1306c', oauthUrl:'oauth_instagram.php' },
  youtube:   { icon:'▶️',  label:'YouTube',     color:'#ff0000', oauthUrl:'oauth_youtube.php' },
  tiktok:    { icon:'🎵', label:'TikTok',      color:'#010101', oauthUrl:'oauth_tiktok.php' },
  x:         { icon:'🐦', label:'X / Twitter', color:'#000000', oauthUrl:'oauth_x.php' },
  linkedin:  { icon:'💼', label:'LinkedIn',    color:'#0077b5', oauthUrl:'oauth_linkedin.php' },
};
function openSocialModal(platform, name, color) {
  activePlatform = platform;
  const meta = platformMeta[platform] || {};
  document.getElementById('sModalIcon').textContent  = meta.icon || '📱';
  document.getElementById('sModalTitle').textContent = (meta.label || name) + ' Connection';
  document.getElementById('sModalSub').textContent   =
    `Authorise VideoVizard to post your approved videos to ${meta.label||name}. You stay in full control — we only publish content you've approved.`;
  const btn = document.getElementById('sModalAuthBtn');
  btn.textContent = `Connect ${meta.label||name} →`;
  btn.style.background = meta.color || color;
  document.getElementById('socialModal').classList.add('open');
}
function closeSocialModal(e)  { if(e.target===document.getElementById('socialModal')) closeSocialModalDirect(); }
function closeSocialModalDirect() { document.getElementById('socialModal').classList.remove('open'); activePlatform=null; }
function doOAuth() {
  if (!activePlatform) return;
  const meta = platformMeta[activePlatform];
  window.location.href = (meta?.oauthUrl || `oauth_${activePlatform}.php`) + '?client_id=<?= (int)$client_id ?>&return_to=client_approval.php';
}

/* ── UTILS ───────────────────────────────────────── */
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function esc(s) { return encodeURIComponent(s); }

<?php else: ?>
/* ── LOGIN ───────────────────────────────────────── */
async function doLogin() {
  const username = document.getElementById('loginUser').value.trim();
  const password = document.getElementById('loginPass').value;
  const errEl    = document.getElementById('loginErr');
  if (!username || !password) { showErr('Please enter username and password.'); return; }
  const fd = new FormData();
  fd.append('action','client_login'); fd.append('username',username); fd.append('password',password);
  try {
    const r    = await fetch('ajax_approval.php',{method:'POST',body:fd});
    const data = await r.json();
    if (data.success) { location.reload(); }
    else showErr(data.message || 'Invalid credentials');
  } catch { showErr('Connection error. Please try again.'); }
  function showErr(msg) { errEl.textContent=msg; errEl.style.display='block'; }
}
document.addEventListener('keydown', e => { if(e.key==='Enter') doLogin(); });
<?php endif; ?>
</script>
</body>
</html>
