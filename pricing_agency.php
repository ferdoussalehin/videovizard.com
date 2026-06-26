<?php
// pricing_agency.php — Shown to Agency plan users: Pay As You Go only
session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

// Capture return URL passed from scriptgen page
$return_url = isset($_GET['return_url']) ? $_GET['return_url'] : '/vizard_scriptgen_2.php';
$return_url_enc = urlencode($return_url);

$is_logged_in   = isset($_SESSION['admin_id']);
$admin_id       = (int)($_SESSION['admin_id'] ?? 0);
$current_plan   = 'agency';
$credit_balance = 0;

if ($is_logged_in && $admin_id) {
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT plan_type, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $current_plan   = $urow['plan_type']      ?? 'agency';
    $credit_balance = (int)($urow['credit_balance'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Top Up Credits — VideoVizard</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --navy:#0f2a44; --navy2:#1a4a7a;
    --blue:#3b82f6; --blue2:#1d4ed8;
    --green:#22c55e; --green2:#16a34a;
    --purple:#7c3aed; --purple2:#5b21b6;
    --success:#10b981;
    --bg:#f0f5ff;
    --text:#0f172a; --muted:#64748b;
    --border:#e2e8f0;
    --radius:20px;
    --shadow:0 8px 40px rgba(15,42,68,.12);
    --shadow-lg:0 20px 60px rgba(15,42,68,.2);
}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
.topnav{background:var(--navy);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;}
.topnav-logo{color:#fff;font-size:18px;font-weight:800;text-decoration:none;letter-spacing:-.3px;}
.topnav-logo span{color:#5fc3ff;}
.topnav-links{display:flex;gap:16px;align-items:center;}
.topnav-links a{color:rgba(255,255,255,.75);font-size:13px;text-decoration:none;font-weight:500;}
.topnav-links a:hover{color:#fff;}
.hero{text-align:center;padding:60px 24px 40px;background:linear-gradient(180deg,#e8f0ff 0%,var(--bg) 100%);}
.hero-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid var(--border);border-radius:40px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:16px;box-shadow:var(--shadow);}
.hero h1{font-size:clamp(24px,4vw,42px);font-weight:900;color:var(--navy);line-height:1.15;margin-bottom:12px;letter-spacing:-.5px;}
.hero h1 span{background:linear-gradient(135deg,var(--green),var(--blue));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hero p{font-size:15px;color:var(--muted);max-width:500px;margin:0 auto 20px;line-height:1.7;}
.credit-explainer{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #c7d2fe;border-radius:12px;padding:10px 18px;font-size:13px;color:var(--navy);box-shadow:var(--shadow);}
.credit-explainer span{font-weight:700;}
/* Current plan pill */
.current-plan-bar{max-width:700px;margin:24px auto 0;padding:0 20px;}
.current-plan-pill{display:inline-flex;align-items:center;gap:8px;background:var(--success);color:#fff;border-radius:40px;padding:8px 16px;font-size:13px;font-weight:700;}
/* Billing bar */
.billing-bar{max-width:700px;margin:16px auto 0;padding:0 20px;}
.billing-card{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.billing-info{font-size:13px;color:var(--muted);}
.billing-info strong{color:var(--text);}
.billing-link{font-size:13px;font-weight:700;color:var(--blue);text-decoration:none;}
.billing-link:hover{text-decoration:underline;}
/* Plans wrap */
.plans-wrap{max-width:700px;margin:28px auto 0;padding:0 20px 60px;display:flex;flex-direction:column;gap:20px;}
.plan-card{background:#fff;border-radius:var(--radius);border:2px solid var(--green);overflow:hidden;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s;}
.plan-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.plan-header{padding:28px 32px 24px;display:flex;align-items:flex-start;gap:16px;border-bottom:1.5px solid var(--border);}
.plan-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;background:linear-gradient(135deg,#d1fae5,#a7f3d0);}
.plan-meta{flex:1;}
.plan-badge{display:inline-block;background:var(--green);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.5px;margin-bottom:8px;}
.plan-name{font-size:22px;font-weight:800;color:var(--navy);margin-bottom:4px;}
.plan-tagline{font-size:13px;color:var(--muted);}
.plan-price-wrap{text-align:right;flex-shrink:0;}
.plan-price{font-size:36px;font-weight:900;color:var(--navy);line-height:1;}
.plan-price sup{font-size:18px;vertical-align:super;font-weight:700;}
.plan-price-sub{font-size:13px;color:var(--muted);font-weight:500;}
.plan-price-period{font-size:12px;color:var(--muted);margin-top:2px;}
.plan-body{padding:24px 32px;}
/* Credit pack grid */
.credit-packs{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;}
.credit-pack{border:2px solid var(--border);border-radius:14px;padding:18px 16px;text-align:center;transition:border-color .2s,box-shadow .2s;background:#fff;}
.credit-pack:hover{border-color:var(--green);box-shadow:0 4px 20px rgba(34,197,94,.15);}
.credit-pack.popular{border-color:var(--green);background:linear-gradient(135deg,#f0fdf4,#dcfce7);}
.credit-pack .pack-badge{display:inline-block;background:var(--green);color:#fff;font-size:10px;font-weight:800;padding:2px 8px;border-radius:20px;margin-bottom:8px;letter-spacing:.3px;}
.credit-pack .pack-credits{font-size:28px;font-weight:900;color:var(--navy);line-height:1;}
.credit-pack .pack-unit{font-size:12px;color:var(--muted);margin-bottom:8px;}
.credit-pack .pack-price{font-size:20px;font-weight:800;color:var(--green2);}
.credit-pack .pack-rate{font-size:11px;color:var(--muted);margin-top:2px;}
.plan-features{list-style:none;display:flex;flex-direction:column;gap:11px;margin-bottom:20px;}
.plan-features li{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:var(--text);line-height:1.5;}
.plan-features li::before{content:'✓';width:20px;height:20px;min-width:20px;background:var(--success);color:#fff;border-radius:50%;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;}
.plan-divider{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.plan-divider span{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.plan-divider::before,.plan-divider::after{content:'';flex:1;height:1px;background:var(--border);}
.btn-plan{display:block;width:100%;text-align:center;padding:16px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;border:none;cursor:pointer;transition:opacity .15s,transform .1s;}
.btn-plan:hover{opacity:.9;transform:translateY(-1px);}
.btn-plan:active{transform:translateY(0);}
.btn-green{background:linear-gradient(135deg,var(--green),var(--green2));color:#fff;box-shadow:0 4px 16px rgba(34,197,94,.3);}
.trust{max-width:700px;margin:0 auto;padding:0 20px 80px;display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;}
.trust-item{background:#fff;border-radius:14px;padding:20px;border:1.5px solid var(--border);text-align:center;}
.trust-item .ico{font-size:28px;margin-bottom:10px;}
.trust-item h4{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:6px;}
.trust-item p{font-size:12px;color:var(--muted);line-height:1.6;}
footer{text-align:center;padding:32px 20px;font-size:12px;color:var(--muted);border-top:1.5px solid var(--border);background:#fff;}
footer a{color:var(--blue);text-decoration:none;}
@media(max-width:520px){
    .plan-header{flex-wrap:wrap;}
    .plan-price-wrap{text-align:left;width:100%;}
    .plan-body{padding:20px;}
    .credit-packs{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<nav class="topnav">
    <a href="/" class="topnav-logo">Video<span>Vizard</span></a>
    <div class="topnav-links">
        <?php if ($is_logged_in) { ?>
            <a href="/vizard_scriptgen.php">Dashboard</a>
            <a href="/logout.php">Log out</a>
        <?php } else { ?>
            <a href="/login.php">Log in</a>
        <?php } ?>
    </div>
</nav>

<section class="hero">
    <div class="hero-badge">⚡ Agency Plan — Top Up Credits</div>
    <h1>Need more credits?<br><span>Top up instantly.</span></h1>
    <p>You're on our highest subscription tier. Buy a Pay As You Go credit pack to top up your balance without changing your plan.</p>
    <div class="credit-explainer">
        💡 <span>1 credit</span> = standard 30-sec video &nbsp;&middot;&nbsp; <span>2 credits</span> = podcast / talking-head video
    </div>
</section>

<div class="current-plan-bar">
    <div class="current-plan-pill">✓ You're on the Agency plan &nbsp;&middot;&nbsp; <?php echo $credit_balance; ?> credits remaining</div>
</div>

<div class="billing-bar">
    <div class="billing-card">
        <div class="billing-info"><strong>Manage your subscription</strong> — update payment method, view invoices, or cancel anytime.</div>
        <a href="<?php echo defined('STRIPE_PORTAL_URL') ? STRIPE_PORTAL_URL : '#'; ?>" target="_blank" class="billing-link">Open Billing Portal →</a>
    </div>
</div>

<div class="plans-wrap">
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon">⚡</div>
            <div class="plan-meta">
                <div class="plan-badge">INSTANT TOP-UP</div>
                <div class="plan-name">Pay As You Go</div>
                <div class="plan-tagline">Buy credits on demand — your Agency subscription stays unchanged</div>
            </div>
            <div class="plan-price-wrap">
                <div class="plan-price-sub">from</div>
                <div class="plan-price"><sup>$</sup>5</div>
                <div class="plan-price-period">per credit pack</div>
            </div>
        </div>
        <div class="plan-body">
            <div class="plan-divider"><span>Choose a pack</span></div>
            <div class="credit-packs">
                <div class="credit-pack">
                    <div class="pack-credits">5</div>
                    <div class="pack-unit">credits</div>
                    <div class="pack-price">$5</div>
                    <div class="pack-rate">$1.00 / credit</div>
                </div>
                <div class="credit-pack popular">
                    <div class="pack-badge">BEST VALUE</div>
                    <div class="pack-credits">15</div>
                    <div class="pack-unit">credits</div>
                    <div class="pack-price">$12</div>
                    <div class="pack-rate">$0.80 / credit</div>
                </div>
                <div class="credit-pack">
                    <div class="pack-credits">30</div>
                    <div class="pack-unit">credits</div>
                    <div class="pack-price">$20</div>
                    <div class="pack-rate">$0.67 / credit</div>
                </div>
                <div class="credit-pack">
                    <div class="pack-credits">60</div>
                    <div class="pack-unit">credits</div>
                    <div class="pack-price">$35</div>
                    <div class="pack-rate">$0.58 / credit</div>
                </div>
            </div>
            <ul class="plan-features">
                <li>Credits added to your account instantly after payment</li>
                <li>No changes to your existing Agency subscription</li>
                <li>Transparent pricing — no hidden fees</li>
                <li>Scalable anytime — buy as many packs as you need</li>
            </ul>
            <a href="stripe_checkout.php?plan=pay-as-you-go&return_url=<?php echo $return_url_enc; ?>" class="btn-plan btn-green">Buy Credits Now →</a>
        </div>
    </div>
</div>

<div class="trust">
    <div class="trust-item"><div class="ico">🔒</div><h4>Secure Payments</h4><p>Powered by Stripe. We never store your card details.</p></div>
    <div class="trust-item"><div class="ico">⚡</div><h4>Instant Access</h4><p>Credits hit your account the moment payment is confirmed.</p></div>
    <div class="trust-item"><div class="ico">🔄</div><h4>No Commitment</h4><p>Buy only what you need, when you need it.</p></div>
    <div class="trust-item"><div class="ico">💬</div><h4>Real Support</h4><p>Questions? Email us — we respond within 24 hours.</p></div>
</div>

<footer>
    <p>&copy; <?php echo date('Y'); ?> VideoVizard &nbsp;&middot;&nbsp;
       <a href="/privacy">Privacy</a> &nbsp;&middot;&nbsp;
       <a href="/terms">Terms</a> &nbsp;&middot;&nbsp;
       <a href="mailto:support@videovizard.com">support@videovizard.com</a> &nbsp;&middot;&nbsp;
       <a href="<?php echo defined('STRIPE_PORTAL_URL') ? STRIPE_PORTAL_URL : '#'; ?>" target="_blank">Manage Billing</a>
    </p>
</footer>

</body>
</html>
