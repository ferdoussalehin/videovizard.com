<?php
// stripe_checkout.php — Creates a Stripe Checkout Session and redirects the user
// Place in: /home/syjy0p3q5yjb/public_html/videovizard.com/stripe_checkout.php
//
// Prerequisites in config.php:
//   define('STRIPE_SECRET_KEY',      'sk_live_...');
//   define('STRIPE_PRICE_PERSONAL',  'price_xxx');   // $30/mo recurring price ID
//   define('STRIPE_PRICE_AGENCY',    'price_yyy');   // $199/mo recurring price ID
//   define('SITE_URL',               'https://videovizard.com');

session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_id'])) {
    header('Location: /login.php?redirect=/pricing');
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];
$plan     = trim($_GET['plan'] ?? '');

// ── Validate plan ─────────────────────────────────────────────────────────────
$plan_config = [
    'personal' => [
        'price_id' => defined('STRIPE_PRICE_PERSONAL') ? STRIPE_PRICE_PERSONAL : '',
        'label'    => 'Personal — $30/mo',
        'credits'  => 30,
    ],
    'agency' => [
        'price_id' => defined('STRIPE_PRICE_AGENCY') ? STRIPE_PRICE_AGENCY : '',
        'label'    => 'Agency — $199/mo',
        'credits'  => 300,
    ],
];

if (!array_key_exists($plan, $plan_config)) {
    header('Location: /pricing?error=invalid_plan');
    exit;
}

$selected = $plan_config[$plan];

if (empty($selected['price_id'])) {
    die('Stripe price ID not configured. Add STRIPE_PRICE_' . strtoupper($plan) . ' to config.php.');
}

// ── Load user email for Stripe ────────────────────────────────────────────────
$urow  = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT email, stripe_customer_id FROM hdb_users WHERE id=$admin_id LIMIT 1"));
$email = $urow['email']              ?? '';
$stripe_cust = $urow['stripe_customer_id'] ?? '';

// ── Call Stripe API to create Checkout Session ────────────────────────────────
$site_url = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://videovizard.com';

$post_data = [
    'mode'                              => 'subscription',
    'line_items[0][price]'              => $selected['price_id'],
    'line_items[0][quantity]'           => '1',
    'success_url'                       => $site_url . '/payment_success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'                        => $site_url . '/pricing?cancelled=1',
    'metadata[admin_id]'                => $admin_id,
    'metadata[plan]'                    => $plan,
    'allow_promotion_codes'             => 'true',
    'billing_address_collection'        => 'auto',
];

// Pre-fill email if we have it
if (!empty($email)) {
    $post_data['customer_email'] = $email;
}

// Attach existing Stripe customer if available (preserves payment methods)
if (!empty($stripe_cust)) {
    unset($post_data['customer_email']);
    $post_data['customer'] = $stripe_cust;
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($post_data),
    CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err || $http_code !== 200) {
    $err = json_decode($response, true);
    $msg = $err['error']['message'] ?? 'Unknown Stripe error';
    error_log("[stripe_checkout] Error for admin $admin_id plan=$plan: $msg");
    header('Location: /pricing?error=' . urlencode($msg));
    exit;
}

$session = json_decode($response, true);

if (empty($session['url'])) {
    error_log("[stripe_checkout] No redirect URL returned for admin $admin_id");
    header('Location: /pricing?error=no_redirect');
    exit;
}

// Log the attempt
error_log("[stripe_checkout] admin=$admin_id plan=$plan session=" . ($session['id'] ?? '?'));

// Redirect to Stripe-hosted checkout
header('Location: ' . $session['url']);
exit;
