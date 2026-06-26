<?php
// pricing.php — VideoVizard Pricing Page
// Place in: /home/syjy0p3q5yjb/public_html/videovizard.com/pricing.php
//
// Add these to config.php:
//   define('STRIPE_PRICE_PERSONAL', 'price_...');
//   define('STRIPE_PRICE_AGENCY',   'price_...');
//   define('STRIPE_PORTAL_URL',     'https://billing.stripe.com/p/login/...');
//   define('SITE_URL',              'https://videovizard.com');

session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

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

$plan = $_GET['plan'] ?? 'personal';
$return_url     = isset($_GET['return_url']) ? $_GET['return_url'] : '/vizard_scriptgen_2.php';
$return_url_enc = urlencode($return_url);
// Plan configuration
$plans = [
    'personal' => [
        'name' => 'Personal',
        'price' => 30,
        'credits' => '30 Credits / mo',
        'tagline' => 'For individual content creators',
        'icon' => '👤'
    ],
    'agency' => [
        'name' => 'Agency',
        'price' => 199,
        'credits' => '200 Credits / mo',
        'tagline' => 'For agencies & teams',
        'icon' => '🏢'
    ]
];

// सुरक्षा (validation)
if (!array_key_exists($plan, $plans)) {
    $plan = 'personal';
}

$current = $plans[$plan];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pricing — VideoVizard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

/* Nav */
.topnav{background:var(--navy);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;}
.topnav-logo{color:#fff;font-size:18px;font-weight:800;text-decoration:none;letter-spacing:-.3px;}
.topnav-logo span{color:#5fc3ff;}
.topnav-links{display:flex;gap:16px;align-items:center;}
.topnav-links a{color:rgba(255,255,255,.75);font-size:13px;text-decoration:none;font-weight:500;}
.topnav-links a:hover{color:#fff;}
.btn-nav{background:var(--blue);color:#fff!important;padding:8px 16px;border-radius:8px;font-weight:600!important;}

/* Hero */
.hero{text-align:center;padding:72px 24px 48px;background:linear-gradient(180deg,#e8f0ff 0%,var(--bg) 100%);}
.hero-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid var(--border);border-radius:40px;padding:6px 14px;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:20px;box-shadow:var(--shadow);}
.hero h1{font-size:clamp(28px,5vw,48px);font-weight:900;color:var(--navy);line-height:1.15;margin-bottom:16px;letter-spacing:-.5px;}
.hero h1 span{background:linear-gradient(135deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.hero p{font-size:16px;color:var(--muted);max-width:520px;margin:0 auto 24px;line-height:1.7;}
.credit-explainer{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #c7d2fe;border-radius:12px;padding:10px 18px;font-size:13px;color:var(--navy);box-shadow:var(--shadow);}
.credit-explainer span{font-weight:700;}

/* Plans */
.plans-wrap{max-width:860px;margin:0 auto;padding:0 20px 60px;display:flex;flex-direction:column;gap:20px;}
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

/* Rollover strip */
.rollover-strip{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:12px;padding:13px 16px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;}
.rollover-strip .ico{font-size:20px;line-height:1;flex-shrink:0;}
.rollover-strip .label{font-size:12px;font-weight:800;color:#166534;margin-bottom:3px;letter-spacing:.1px;}
.rollover-strip .desc{font-size:12px;color:#15803d;line-height:1.55;}

/* Divider */
.plan-divider{display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.plan-divider span{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;}
.plan-divider::before,.plan-divider::after{content:'';flex:1;height:1px;background:var(--border);}

/* Buttons */
.btn-plan{display:block;width:100%;text-align:center;padding:16px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;border:none;cursor:pointer;transition:opacity .15s,transform .1s;}
.btn-plan:hover{opacity:.9;transform:translateY(-1px);}
.btn-plan:active{transform:translateY(0);}
.btn-personal{background:linear-gradient(135deg,var(--purple),var(--purple2));color:#fff;box-shadow:0 4px 16px rgba(124,58,237,.3);}
.btn-agency{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;box-shadow:0 4px 16px rgba(15,42,68,.3);}
.btn-current{background:var(--border);color:var(--muted);cursor:default;}
.btn-current:hover{opacity:1;transform:none;}

/* Trust strip */
.trust{max-width:860px;margin:0 auto;padding:0 20px 80px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;}
.trust-item{background:#fff;border-radius:14px;padding:20px;border:1.5px solid var(--border);text-align:center;}
.trust-item .ico{font-size:28px;margin-bottom:10px;}
.trust-item h4{font-size:14px;font-weight:700;color:var(--navy);margin-bottom:6px;}
.trust-item p{font-size:12px;color:var(--muted);line-height:1.6;}

/* Billing bar */
.billing-bar{max-width:860px;margin:0 auto 40px;padding:0 20px;}
.billing-card{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.billing-info{font-size:13px;color:var(--muted);}
.billing-info strong{color:var(--text);}
.billing-link{font-size:13px;font-weight:700;color:var(--blue);text-decoration:none;}
.billing-link:hover{text-decoration:underline;}

/* Current plan pill */
.current-plan-bar{max-width:860px;margin:0 auto 24px;padding:0 20px;}
.current-plan-pill{display:inline-flex;align-items:center;gap:8px;background:var(--success);color:#fff;border-radius:40px;padding:8px 16px;font-size:13px;font-weight:700;}

/* Footer */
footer{text-align:center;padding:32px 20px;font-size:12px;color:var(--muted);border-top:1.5px solid var(--border);background:#fff;}
footer a{color:var(--blue);text-decoration:none;}

@media(max-width:520px){
    .plan-header{flex-wrap:wrap;}
    .plan-price-wrap{text-align:left;width:100%;}
    .plan-body{padding:20px;}
    .credit-explainer{font-size:12px;text-align:center;}
}
</style>
</head>
<body>

<!-- Nav -->
<nav class="topnav">
    <a href="/" class="topnav-logo">Video<span>Vizard</span></a>
    <div class="topnav-links">
        <?php if ($is_logged_in): ?>
            <a href="/vizard_scriptgen.php">Dashboard</a>
            <a href="/logout.php">Log out</a>
        <?php else: ?>
            <a href="/login.php">Log in</a>
            <a href="/pricing" class="btn-nav">Get Started</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="hero-badge">✨ Simple, transparent pricing</div>
    <h1>Unlock the power of<br><span>VideoVizard.</span></h1>
    <p>AI-powered social media videos — scripted, voiced, and ready to post. Pay only for what you create.</p>
    <div class="credit-explainer">
        💡 <span>1 credit</span> = standard 30-sec video &nbsp;&middot;&nbsp; <span>2 credits</span> = podcast / talking-head video
    </div>
</section>

<!-- Current plan banner -->
<?php if ($is_logged_in && $current_plan !== 'free_trial'): ?>
<div class="current-plan-bar">
    <div class="current-plan-pill">
        ✓ You're on the <?= htmlspecialchars(ucfirst($current_plan)) ?> plan
        &nbsp;·&nbsp; <?= $credit_balance ?> credits remaining
    </div>
</div>
<?php endif; ?>

<!-- Billing management -->
<?php if ($is_logged_in && in_array($current_plan, ['personal','agency'])): ?>
<div class="billing-bar">
    <div class="billing-card">
        <div class="billing-info">
            <strong>Manage your subscription</strong> — update payment method, view invoices, or cancel anytime.
        </div>
        <a href="<?= defined('STRIPE_PORTAL_URL') ? STRIPE_PORTAL_URL : '#' ?>" target="_blank" class="billing-link">
            Open Billing Portal →
        </a>
    </div>
</div>
<?php endif; ?>
<?php if(isset($_GET['plan']) && $_GET['plan']!='pay-as-you-go'){ ?>
<!-- Plans -->
<div class="plans-wrap">

    <!-- Personal -->
    <div class="plan-card">
        <div class="plan-header">
            <div class="plan-icon <?= $plan ?>">
                <?= $current['icon'] ?>
            </div>
        
            <div class="plan-meta">
                <div class="plan-name"><?= $current['name'] ?></div>
                <div class="plan-tagline"><?= $current['tagline'] ?></div>
            </div>
        
            <div class="plan-price-wrap">
                <div class="plan-price">
                    <sup>$</sup><?= $current['price'] ?>
                </div>
                <div class="plan-price-period">per month</div>
                <div class="plan-credits"><?= $current['credits'] ?></div>
            </div>
        </div>
        <div class="plan-body">
            <div class="plan-divider"><span><img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSrLjwzcn_doIcFMgZgxFgj6PADajbk3Zr29w&s" style="width:100%;"></span></div>
            <div class="plan-features">
                <div class="payment-form mt-3">

                    <form id="payment-form-personal">
                
                        <div class="mb-3">
                            <input type="text" id="name" class="form-control" placeholder="Full Name" required>
                            <input type="hidden" name="plan" value="<?= $plan ?>">
                        </div>
                
                        <div class="mb-3">
                            <input type="email" id="email" class="form-control" placeholder="Email Address" required>
                        </div>
                
                        <div class="mb-3">
                            <input type="tel" id="phone" class="form-control" placeholder="Phone Number" required>
                        </div>
                
                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">
                        <!-- Stripe Card -->
                        <div class="mb-3">
                            <div id="card-element-personal" class="form-control p-2"></div>
                        </div>
                
                        <!-- Errors -->
                        <div id="card-errors-personal" class="text-danger small mb-2"></div>
                            <button type="submit" class="btn-plan btn-personal " id="payBtnPersonal">
                                Subscribe — $30/mo →
                            </button>
                            <a href="<?php echo htmlspecialchars($return_url); ?>" style="display:block;text-align:center;margin-top:12px;font-size:13px;color:var(--muted);text-decoration:none;font-weight:600;">
                                ← Cancel and go back
                            </a>
                
                        
                
                    </form>
                
                </div>
            </div>
            <div class="rollover-strip">
                <span class="ico">♻️</span>
                <div>
                    <div class="label">CREDITS ROLL OVER — NEVER LOSE YOUR VALUE</div>
                    <div class="desc">Unused credits carry forward automatically each month, capped at <strong>90 credits</strong> (3× your monthly allowance). Your investment is always protected.</div>
                </div>
            </div>
            <?php if ($current_plan === 'personal'): ?>
                <span class="btn-plan btn-current">✓ Your Current Plan</span>
            <?php elseif ($is_logged_in): ?>
                <a href="stripe_checkout.php?plan=personal" class="btn-plan btn-personal">Get Started — $30/mo →</a>
            <?php else: ?>
                <a href="/login.php?redirect=/pricing" class="btn-plan btn-personal">Get Started — $30/mo →</a>
            <?php endif; ?>
        </div>
    </div>

    

</div>


<?php }
if(isset($_GET['plan']) && $_GET['plan']=='pay-as-you-go'){ ?>
<div class="plans-wrap">

    <!-- Pay As You Go -->
    <div class="plan-card paygo-card">

        <div class="plan-header">
            <div class="plan-icon paygo">💳</div>
        
            <div class="plan-meta">
                <div class="plan-name">Pay As You Go</div>
                <div class="plan-tagline">No subscription. Pay only for what you use.</div>
            </div>
        
            <div class="plan-price-wrap">
                <div class="plan-price">
                    <sup>$</sup><span id="paygo-price">10</span>
                </div>
                <div class="plan-price-period">one-time payment</div>
                <div class="plan-credits">
                    <span id="paygo-credits">10 Credits</span>
                </div>
            </div>
        </div>

        <div class="plan-body">

            <div class="plan-divider">
                <span>
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSrLjwzcn_doIcFMgZgxFgj6PADajbk3Zr29w&s" style="width:100%;">
                </span>
            </div>

            <!-- 💡 CUSTOM AMOUNT -->
            <div class="custom-amount-box">
                <label>Enter Amount ($)</label>
                <div class="amount-input-wrap">
                    <span>$</span>
                    <input type="number" id="custom_amount" placeholder="Enter amount" min="5" value="10">
                </div>
                <small class="amount-note">Minimum $5 required</small>
            </div>

            <div class="plan-features">
                <div class="payment-form mt-3">

                    <form id="payment-form-paygo">

                        <input type="hidden" name="plan" value="paygo">
                        <input type="hidden" id="selected_price" name="price" value="10">
                        <input type="hidden" id="selected_credits" name="credits" value="10">

                        <div class="mb-3">
                            <input type="text" class="form-control" placeholder="Full Name" required>
                        </div>

                        <div class="mb-3">
                            <input type="email" class="form-control" placeholder="Email Address" required>
                        </div>

                        <div class="mb-3">
                            <input type="tel" class="form-control" placeholder="Phone Number" required>
                        </div>

                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">
                        <!-- Stripe Card -->
                        <div class="mb-3">
                            <div id="card-element-paygo" class="form-control p-2"></div>
                        </div>

                        <div id="card-errors-paygo" class="text-danger small mb-2"></div>

                        <button type="submit" class="btn-plan btn-paygo" id="payBtnPaygo">
                            Pay Now — $<span id="btn-price">10</span> →
                        </button>
                        <a href="<?php echo htmlspecialchars($return_url); ?>" style="display:block;text-align:center;margin-top:12px;font-size:13px;color:var(--muted);text-decoration:none;font-weight:600;">
                            ← Cancel and go back
                        </a>

                    </form>

                </div>
            </div>

            <!-- Info Strip -->
            <div class="rollover-strip">
                <span class="ico">⚡</span>
                <div>
                    <div class="label">INSTANT CREDIT DELIVERY</div>
                    <div class="desc">
                        Credits are added instantly after payment. No expiry. Use anytime.
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>
<?php } ?>



<!-- Trust strip -->
<div class="trust">
    <div class="trust-item">
        <div class="ico">🔒</div>
        <h4>Secure Payments</h4>
        <p>Powered by Stripe. We never store your card details.</p>
    </div>
    <div class="trust-item">
        <div class="ico">♻️</div>
        <h4>Credits Roll Over</h4>
        <p>Unused credits carry to next month automatically — capped at 3× your allowance.</p>
    </div>
    <div class="trust-item">
        <div class="ico">🔄</div>
        <h4>Cancel Anytime</h4>
        <p>No contracts. Cancel or change plans any time from your billing portal.</p>
    </div>
    <div class="trust-item">
        <div class="ico">⚡</div>
        <h4>Instant Access</h4>
        <p>Credits hit your account the moment payment is confirmed.</p>
    </div>
    <div class="trust-item">
        <div class="ico">💬</div>
        <h4>Real Support</h4>
        <p>Questions? Email us — we respond within 24 hours.</p>
    </div>
</div>

<footer>
    <p>© <?= date('Y') ?> VideoVizard &nbsp;·&nbsp;
       <a href="/privacy">Privacy</a> &nbsp;·&nbsp;
       <a href="/terms">Terms</a> &nbsp;·&nbsp;
       <a href="mailto:support@videovizard.com">support@videovizard.com</a>
       <?php if ($is_logged_in && in_array($current_plan, ['personal','agency'])): ?>
           &nbsp;·&nbsp; <a href="<?= defined('STRIPE_PORTAL_URL') ? STRIPE_PORTAL_URL : '#' ?>" target="_blank">Manage Billing</a>
       <?php endif; ?>
    </p>
</footer>
<script src="https://js.stripe.com/v3/"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const amountInput = document.getElementById("custom_amount");

    const priceText = document.getElementById("paygo-price");
    const creditsText = document.getElementById("paygo-credits");
    const btnPrice = document.getElementById("btn-price");

    const hiddenPrice = document.getElementById("selected_price");
    const hiddenCredits = document.getElementById("selected_credits");

    amountInput.addEventListener("input", function () {
        let amount = parseFloat(this.value);

        // Minimum validation
        if (isNaN(amount) || amount < 5) {
            amount = 5;
        }

        // Example: 1$ = 1 credit (change logic if needed)
        let credits = Math.floor(amount);

        // Update UI
        priceText.textContent = amount;
        creditsText.textContent = credits + " Credits";
        btnPrice.textContent = amount;

        // Update hidden fields
        hiddenPrice.value = amount;
        hiddenCredits.value = credits;
    });

});
const stripe = Stripe("pk_test_51S0ekZ3NpwafsWMFvsw1NjNvalCEtpMy5U3YWp0TwwvdrHIPlIJXZAj7lBNI2lquKUid8SmgmsK0pQq6ojBByzfi00RLB8RNI3");
const elements = stripe.elements();

const card = elements.create("card", {
    style: {
        base: {
            fontSize: "14px"
        }
    }
});


<?php if(isset($_GET['plan']) && $_GET['plan']=='pay-as-you-go'){ ?>
card.mount("#card-element-paygo");

document.getElementById("payment-form-paygo").addEventListener("submit", async function(e) {
    e.preventDefault();

    const btn = document.getElementById("payBtnPaygo");
    btn.innerText = "Processing...";
    btn.disabled = true;

    const name  = this.querySelector('input[type="text"]').value;
    const email = this.querySelector('input[type="email"]').value;
    const phone = this.querySelector('input[type="tel"]').value;

    // ✅ get dynamic amount
    const amount  = document.getElementById("selected_price").value;
    const credits = document.getElementById("selected_credits").value;

    const { paymentMethod, error } = await stripe.createPaymentMethod({
        type: "card",
        card: card,
        billing_details: { name, email, phone }
    });

    if (error) {
        document.getElementById("card-errors-paygo").textContent = error.message;
        btn.innerText = "Pay Now";
        btn.disabled = false;
        return;
    }

    fetch("process-payment.php?plan=paygo", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
            payment_method: paymentMethod.id,
            name: name,
            email: email,
            phone: phone,
            amount: amount,
            credits: credits,
            admin_id: <?= $admin_id ?>,
            return_url: "<?= addslashes($return_url) ?>"
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.return_url || "success.php";
        } else {
            alert(data.error);
            btn.innerText = "Pay Now";
            btn.disabled = false;
        }
    });
});

<?php }else{ ?>

card.mount("#card-element-personal");

document.getElementById("payment-form-personal").addEventListener("submit", async function(e) {
    e.preventDefault();

    const btn = document.getElementById("payBtnPersonal");
    btn.innerText = "Please Wait ......";
    btn.disabled = true;

    const name  = document.getElementById("name").value;
    const email = document.getElementById("email").value;
    const phone = document.getElementById("phone").value;

    const { paymentMethod, error } = await stripe.createPaymentMethod({
        type: "card",
        card: card,
        billing_details: {
            name: name,
            email: email,
            phone: phone
        }
    });

    if (error) {
        document.getElementById("card-errors-personal").textContent = error.message;
        btn.innerText = "Subscribe — $30/mo →";
        btn.disabled = false;
        return;
    }

    fetch("process-payment.php", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
            payment_method: paymentMethod.id,
            name: name,
            email: email,
            phone: phone,
            admin_id: <?= $admin_id ?>,
            plan: "<?= $plan ?>",
            return_url: "<?= addslashes($return_url) ?>"
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.return_url || "success.php";
        } else {
            alert(data.error);
            btn.innerText = "Subscribe — $30/mo →";
            btn.disabled = false;
        }
    });
});
<?php } ?>
</script>
</body>
</html>
