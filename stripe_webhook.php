<?php
// stripe_webhook.php — Stripe Webhook Endpoint
// Place in: /home/syjy0p3q5yjb/public_html/videovizard.com/stripe_webhook.php
//
// In your Stripe Dashboard → Webhooks → Add endpoint:
//   URL: https://videovizard.com/stripe_webhook.php
//   Events to listen for:
//     - checkout.session.completed
//     - customer.subscription.deleted   (optional: handle cancellations)
//     - invoice.payment_failed          (optional: handle failed renewals)
//
// In config.php add:
//   define('STRIPE_WEBHOOK_SECRET', 'whsec_...');  // Signing secret from Stripe
//   define('STRIPE_SECRET_KEY',     'sk_live_...');

require_once 'dbconnect_hdb.php';
require_once 'config.php';

// ── Logging helper ────────────────────────────────────────────────────────────
function wh_log($msg) {
    error_log('[stripe_webhook] ' . $msg . PHP_EOL, 3, __DIR__ . '/a_errors.log');
}

// ── Read raw POST body BEFORE any output ─────────────────────────────────────
$payload   = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';

if (empty($payload)) {
    http_response_code(400);
    wh_log('Empty payload received');
    exit('Empty payload');
}

// ── Verify Stripe signature ───────────────────────────────────────────────────
function stripe_verify_signature($payload, $sig_header, $secret) {
    if (empty($secret) || empty($sig_header)) return false;

    $parts = [];
    foreach (explode(',', $sig_header) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k][] = $v;
    }

    $timestamp = (int)($parts['t'][0] ?? 0);
    if (abs(time() - $timestamp) > 300) return false; // 5 min tolerance

    $signed_payload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signed_payload, $secret);

    foreach (($parts['v1'] ?? []) as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!stripe_verify_signature($payload, $sig_header, $secret)) {
    http_response_code(403);
    wh_log('Signature verification failed');
    exit('Invalid signature');
}

// ── Parse event ───────────────────────────────────────────────────────────────
$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    wh_log('Malformed event JSON');
    exit('Bad JSON');
}

$event_type = $event['type'];
wh_log("Event received: $event_type | id=" . ($event['id'] ?? '?'));

// ── Credits per plan ──────────────────────────────────────────────────────────
// Keep this as the single source of truth — matches pricing.php
const PLAN_CREDITS = [
    'personal' => 30,
    'agency'   => 300,
];

const PLAN_MAX_VIDEOS = [
    'personal' => 999999,  // unlimited
    'agency'   => 999999,
];

// ── Event handlers ────────────────────────────────────────────────────────────
switch ($event_type) {

    // ── New subscription / one-time payment completed ─────────────────────────
    case 'checkout.session.completed':
        $session    = $event['data']['object'];
        $session_id = $session['id'] ?? '';
        $admin_id   = (int)($session['metadata']['admin_id'] ?? 0);
        $plan       = strtolower(trim($session['metadata']['plan'] ?? ''));
        $cust_id    = $session['customer'] ?? '';
        $sub_id     = $session['subscription'] ?? '';

        wh_log("checkout.session.completed: session=$session_id admin=$admin_id plan=$plan cust=$cust_id");

        if (!$admin_id || !array_key_exists($plan, PLAN_CREDITS)) {
            wh_log("ERROR: Missing admin_id ($admin_id) or unknown plan ($plan)");
            http_response_code(200); // Still 200 so Stripe doesn't retry
            exit('Skipped — missing metadata');
        }

        $credits       = PLAN_CREDITS[$plan];
        $max_videos    = PLAN_MAX_VIDEOS[$plan];
        $esc_plan      = mysqli_real_escape_string($conn, $plan);
        $esc_cust      = mysqli_real_escape_string($conn, $cust_id);
        $esc_sub       = mysqli_real_escape_string($conn, $sub_id);
        $esc_session   = mysqli_real_escape_string($conn, $session_id);

        // Update user: set plan, add credits, save Stripe IDs
        $ok = mysqli_query($conn,
            "UPDATE hdb_users SET
                plan_type             = '$esc_plan',
                subscription_status   = 'active',
                credit_balance        = credit_balance + $credits,
                max_videos_allowed    = $max_videos,
                stripe_customer_id    = IF('$esc_cust'!='', '$esc_cust', stripe_customer_id),
                stripe_subscription_id= IF('$esc_sub'!='', '$esc_sub', stripe_subscription_id),
                stripe_session_id     = '$esc_session',
                updated_at            = NOW()
             WHERE id=$admin_id");

        if ($ok) {
            wh_log("✓ admin=$admin_id upgraded to $plan, +$credits credits");
        } else {
            wh_log("✗ DB update FAILED for admin=$admin_id: " . mysqli_error($conn));
        }
        break;

    // ── Subscription cancelled (user cancelled or payment failed repeatedly) ──
    case 'customer.subscription.deleted':
        $sub     = $event['data']['object'];
        $cust_id = $sub['customer'] ?? '';

        if (empty($cust_id)) {
            wh_log("subscription.deleted: no customer ID");
            break;
        }

        $esc_cust = mysqli_real_escape_string($conn, $cust_id);

        // Downgrade to free_trial, keep remaining credits
        $ok = mysqli_query($conn,
            "UPDATE hdb_users SET
                plan_type           = 'free_trial',
                subscription_status = 'cancelled',
                max_videos_allowed  = 30,
                updated_at          = NOW()
             WHERE stripe_customer_id = '$esc_cust'");

        wh_log("subscription.deleted: cust=$cust_id downgraded=" . ($ok ? 'yes' : 'FAILED'));
        break;

    // ── Renewal — add monthly credits again ───────────────────────────────────
    case 'invoice.paid':
        $invoice = $event['data']['object'];
        // Only process recurring subscription renewals (not the first invoice)
        if (($invoice['billing_reason'] ?? '') !== 'subscription_cycle') break;

        $cust_id = $invoice['customer'] ?? '';
        if (empty($cust_id)) break;

        $esc_cust = mysqli_real_escape_string($conn, $cust_id);

        // Look up the user's current plan
        $urow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id, plan_type FROM hdb_users WHERE stripe_customer_id='$esc_cust' LIMIT 1"));

        if (!$urow) { wh_log("invoice.paid: no user found for cust=$cust_id"); break; }

        $plan    = $urow['plan_type'];
        $uid     = (int)$urow['id'];
        $credits = PLAN_CREDITS[$plan] ?? 0;

        if ($credits > 0) {
            mysqli_query($conn,
                "UPDATE hdb_users SET credit_balance = credit_balance + $credits, updated_at = NOW()
                 WHERE id=$uid");
            wh_log("invoice.paid renewal: admin=$uid plan=$plan +$credits credits");
        }
        break;

    // ── Payment failed ────────────────────────────────────────────────────────
    case 'invoice.payment_failed':
        $invoice = $event['data']['object'];
        $cust_id = $invoice['customer'] ?? '';
        if (!empty($cust_id)) {
            $esc_cust = mysqli_real_escape_string($conn, $cust_id);
            mysqli_query($conn,
                "UPDATE hdb_users SET subscription_status='past_due', updated_at=NOW()
                 WHERE stripe_customer_id='$esc_cust'");
            wh_log("invoice.payment_failed: cust=$cust_id marked past_due");
        }
        break;

    default:
        wh_log("Unhandled event type: $event_type");
        break;
}

// Always return 200 so Stripe doesn't retry
http_response_code(200);
echo json_encode(['received' => true]);
exit;
