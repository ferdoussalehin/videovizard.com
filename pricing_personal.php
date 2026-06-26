<?php
// pricing_free_trial.php — Shown to free trial users: Personal + Agency subscriptions only
session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

// Capture return URL passed from scriptgen page
$return_url = isset($_GET['return_url']) ? $_GET['return_url'] : '/vizard_scriptgen_2.php';
$return_url_enc = urlencode($return_url);

$is_logged_in   = isset($_SESSION['admin_id']);
$admin_id       = (int)($_SESSION['admin_id'] ?? 0);
$current_plan   = 'free_trial';
$credit_balance = 0;

if ($is_logged_in && $admin_id) {
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT plan_type, credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));
    $current_plan   = $urow['plan_type']      ?? 'free_trial';
    $credit_balance = (int)($urow['credit_balance'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upgrade Your Plan — VideoVizard</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --navy:#0f2a44; --navy2:#1a4a7a;
    --blue:#3b82f6; --blue2:#1d4ed8;
    --gold:#f59e0b;
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
.btn-nav{background:var(--blue);color:#fff!important;padding:8px 16px;border-radius:8px;font-weight:600!important;}
.hero{text-align:center;padding:60px 24px 40px;background:linear-gradient(180deg,#e8f0ff 0%,var(--bg) 100%);}
.hero-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid var(--border);border-radius:40px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:16px;box-shadow:var(--shadow);}
.hero h1{font-size:clamp(24px,4vw,42px);font-weight:900;color:var(--navy);line-height:1.15;margin-bottom:12px;letter-spacing:-.5px;}
.hero h1 span{background:linear-gradient(135deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hero p{font-size:15px;color:var(--muted);max-width:500px;margin:0 auto 20px;line-height:1.7;}
.credit-explainer{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #c7d2fe;border-radius:12px;padding:10px 18px;font-size:13px;color:var(--navy);box-shadow:var(--shadow);}
.credit-explainer span{font-weight:700;}
/* Trial callout */
.trial-notice{max-width:860px;margin:28px auto 0;padding:0 20px;}
.trial-notice-inner{background:linear-gradient(135deg,#fef3c7,#fde68a);border:1.5px solid #fbbf24;border-radius:14px;padding:16px 22px;display:flex;align-items:center;gap:14px;}
.trial-notice-inner .ico{font-size:24px;flex-shrink:0;}
.trial-notice-inner p{font-size:13px;color:#78350f;line-height:1.6;}
.trial-notice-inner strong{color:#92400e;}
/* Plans */
.plans-wrap{max-width:860px;margin:28px auto 0;padding:0 20px 60px;display:flex;flex-direction:column;gap:20px;}
.plan-card{background:#fff;border-radius:var(--radius);border:2px solid var(--border);overflow:hidden;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s;}
.plan-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.plan-card.featured{border:2px solid var(--navy);background:linear-gradient(160deg,#f0f5ff 0%,#e8f0ff 100%);}
.plan-header{padding:28px 32px 24px;display:flex;align-items:flex-start;gap:16px;border-bottom:1.5px solid var(--border);}
.plan-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;}
.plan-icon.personal{background:#f3f4f6;}
.plan-icon.agency{background:linear-gradient(135deg,#e0e7ff,#c7d2fe);}
.plan-meta{flex:1;}
.plan-badge{display:inline-block;background:var(--gold);color:#fff;font-size:10px;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.5px;margin-bottom:8px;}
.plan-name{font-size:22px;font-weight:800;color:var(--navy);margin-bottom:4px;}
.plan-tagline{font-size:13px;color:var(--muted);}
.plan-price-wrap{text-align:right;flex-shrink:0;}
.plan-price{font-size:36px;font-weight:900;color:var(--navy);line-height:1;}
.plan-price sup{font-size:18px;vertical-align:super;font-weight:700;}
.plan-price-period{font-size:12px;color:var(--muted);margin-top:2px;}
.plan-credits{display:inline-block;background:var(--success);color:#fff;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;margin-top:6px;}
.plan-body{padding:24px 32px;}
.plan-features{list-style:none;display:flex;flex-direction:column;gap:11px;margin-bottom:20px;}
.plan-features li{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:var(--text);line-height:1.5;}
.plan-features li::before{content:'✓';width:20px;height:20px;min-width:20px;background:var(--success);color:#fff;border-radius:50%;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;}
.rollover-strip{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:12px;padding:13px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;}
.rollover-strip .ico{font-size:20px;line-height:1;flex-shrink:0;}
.rollover-strip .label{font-size:12px;font-weight:800;color:#166534;margin-bottom:3px;letter-spacing:.1px;}
.rollover-strip .desc{font-size:12px;color:#15803d;line-height:1.55;}
.plan-divider{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.plan-divider span{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.plan-divider::before,.plan-divider::after{content:'';flex:1;height:1px;background:var(--border);}
.btn-plan{display:block;width:100%;text-align:center;padding:16px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;border:none;cursor:pointer;transition:opacity .15s,transform .1s;}
.btn-plan:hover{opacity:.9;transform:translateY(-1px);}
.btn-plan:active{transform:translateY(0);}
.btn-personal{background:linear-gradient(135deg,var(--purple),var(--purple2));color:#fff;box-shadow:0 4px 16px rgba(124,58,237,.3);}
.btn-agency{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;box-shadow:0 4px 16px rgba(15,42,68,.3);}
.trust{max-width:860px;margin:0 auto;padding:0 20px 80px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;}
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
            <a href="/pricing" class="btn-nav">Get Started</a>
        <?php } ?>
    </div>
</nav>

<section class="hero">
    <div class="hero-badge">🚀 You've reached your free trial limit</div>
    <h1>Keep creating with<br><span>VideoVizard.</span></h1>
    <p>You've seen what's possible. Choose a plan and keep the momentum going — unlimited generations, no watermarks, full workspace features.</p>
    <div class="credit-explainer">
        💡 <span>1 credit</span> = standard 30-sec video &nbsp;&middot;&nbsp; <span>2 credits</span> = podcast / talking-head video
    </div>
</section>

<div class="trial-notice">
    <div class="trial-notice-inner">
        <div class="ico">🎬</div>
        <p>Your <strong>Free Trial</strong> has run out of credits. Pick a subscription below to continue creating — credits are added instantly after payment.</p>
    </div>
</div>

<div class="plans-wrap">

    <!-- Personal -->
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon personal">👤</div>
            <div class="plan-meta">
                <div class="plan-name">Personal</div>
                <div class="plan-tagline">For individual content creators</div>
            </div>
            <div class="plan-price-wrap">
                <div class="plan-price"><sup>$</sup>30</div>
                <div class="plan-price-period">per month</div>
                <div class="plan-credits">30 Credits / mo</div>
            </div>
        </div>
        <div class="plan-body">
            <div class="plan-divider"><span>What you get</span></div>
            <ul class="plan-features">
                <li><strong>30 credits/mo</strong> — 1 credit per standard video, 2 credits per podcast or talking-head video</li>
                <li>6 languages — English, Spanish, French, Arabic, Hindi &amp; Urdu</li>
                <li>Generate single videos <em>or</em> run full multi-week campaign schedules</li>
                <li>Download HD videos or auto-post to Facebook, Instagram, TikTok &amp; YouTube</li>
                <li>2 workspaces — keep your brand content organised</li>
                <li>Full AI script generation, scene prompts &amp; voice-over included</li>
            </ul>
            <div class="rollover-strip">
                <span class="ico">♻️</span>
                <div>
                    <div class="label">CREDITS ROLL OVER — NEVER LOSE YOUR VALUE</div>
                    <div class="desc">Unused credits carry forward automatically each month, capped at <strong>90 credits</strong> (3× your monthly allowance). Your investment is always protected.</div>
                </div>
            </div>
            <?php if ($is_logged_in) { ?>
                <a href="stripe_checkout.php?plan=personal&return_url=<?php echo $return_url_enc; ?>" class="btn-plan btn-personal">Get Started — $30/mo →</a>
            <?php } else { ?>
                <a href="/login.php?redirect=/pricing_free_trial.php" class="btn-plan btn-personal">Get Started — $30/mo →</a>
            <?php } ?>
        </div>
    </div>

    <!-- Agency -->
    <div class="plan-card featured">
        <div class="plan-header">
            <div class="plan-icon agency">🏢</div>
            <div class="plan-meta">
                <div class="plan-badge">MOST POPULAR</div>
                <div class="plan-name">Agency</div>
                <div class="plan-tagline">For social media managers &amp; agencies</div>
            </div>
            <div class="plan-price-wrap">
                <div class="plan-price"><sup>$</sup>199</div>
                <div class="plan-price-period">per month</div>
                <div class="plan-credits">300 Credits / mo</div>
            </div>
        </div>
        <div class="plan-body">
            <div class="plan-divider"><span>Everything in Personal, plus</span></div>
            <ul class="plan-features">
                <li><strong>300 credits/mo</strong> — 10× the volume to handle multiple clients at once</li>
                <li>Unlimited workspaces — switch between client accounts instantly</li>
                <li>All 6 languages &amp; all AI voices, no restrictions</li>
                <li>Generate, schedule &amp; auto-post to Facebook, Instagram, TikTok &amp; YouTube</li>
                <li>Priority support — direct access to our team</li>
                <li>White-label ready — your brand on every video, your clients never know</li>
            </ul>
            <div class="rollover-strip">
                <span class="ico">♻️</span>
                <div>
                    <div class="label">CREDITS ROLL OVER — NEVER LOSE YOUR VALUE</div>
                    <div class="desc">Unused credits carry forward automatically each month, capped at <strong>900 credits</strong> (3× your monthly allowance). Your investment is always protected.</div>
                </div>
            </div>
            <?php if ($is_logged_in) { ?>
                <a href="stripe_checkout.php?plan=agency&return_url=<?php echo $return_url_enc; ?>" class="btn-plan btn-agency">Get Started — $199/mo →</a>
            <?php } else { ?>
                <a href="/login.php?redirect=/pricing_free_trial.php" class="btn-plan btn-agency">Get Started — $199/mo →</a>
            <?php } ?>
        </div>
    </div>

</div>

<div class="trust">
    <div class="trust-item"><div class="ico">🔒</div><h4>Secure Payments</h4><p>Powered by Stripe. We never store your card details.</p></div>
    <div class="trust-item"><div class="ico">♻️</div><h4>Credits Roll Over</h4><p>Unused credits carry to next month automatically — capped at 3× your allowance.</p></div>
    <div class="trust-item"><div class="ico">🔄</div><h4>Cancel Anytime</h4><p>No contracts. Cancel or change plans any time from your billing portal.</p></div>
    <div class="trust-item"><div class="ico">⚡</div><h4>Instant Access</h4><p>Credits hit your account the moment payment is confirmed.</p></div>
    <div class="trust-item"><div class="ico">💬</div><h4>Real Support</h4><p>Questions? Email us — we respond within 24 hours.</p></div>
</div>

<footer>
    <p>&copy; <?php echo date('Y'); ?> VideoVizard &nbsp;&middot;&nbsp;
       <a href="/privacy">Privacy</a> &nbsp;&middot;&nbsp;
       <a href="/terms">Terms</a> &nbsp;&middot;&nbsp;
       <a href="mailto:support@videovizard.com">support@videovizard.com</a>
    </p>
</footer>

</body>
</html>
