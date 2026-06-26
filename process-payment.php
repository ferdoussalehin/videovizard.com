<?php
//sk_test_51S0ekZ3NpwafsWMFl1VSwhQHgPj9WYqkgGO8uzdPDVkngqBGCfEjCzEkCFNqMnyGzaAVDuBwBC2Xo1J3BikaYlP600bEIG9aZ0

session_start();
require_once 'dbconnect_hdb.php';
require_once 'config.php';
require 'stripe/init.php';

header('Content-Type: application/json');

\Stripe\Stripe::setApiKey('sk_test_51S0ekZ3NpwafsWMFl1VSwhQHgPj9WYqkgGO8uzdPDVkngqBGCfEjCzEkCFNqMnyGzaAVDuBwBC2Xo1J3BikaYlP600bEIG9aZ0'); // 🔴 Replace with your key

try {

    // =========================
    // 1. GET DATA
    // =========================
    $data = json_decode(file_get_contents("php://input"), true);

    $payment_method = $data['payment_method'] ?? '';
    $name  = $data['name'] ?? '';
    $email = $data['email'] ?? '';
    $phone = $data['phone'] ?? '';
    $admin_id = (int)($data['admin_id'] ?? 0);

    $planType = $_GET['plan'] ?? ''; // paygo OR personal/agency

    if (!$payment_method || !$email || !$admin_id) {
        throw new Exception("Missing required data");
    }

    // =========================================================
    // ================= PAY AS YOU GO ==========================
    // =========================================================
    if ($planType === 'paygo') {

        $amount  = (float)($data['amount'] ?? 0);

        if ($amount < 5) {
            throw new Exception("Minimum amount is $5");
        }

        // 🔒 Secure credits calculation (DON’T trust frontend)
        $credits = floor($amount); // customize if needed

        $amount_cents = $amount * 100;

        // =========================
        // CREATE PAYMENT INTENT
        // =========================
        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $amount_cents,
            'currency' => 'usd',
            'payment_method' => $payment_method,
            'confirm' => true,
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never'
            ]
        ]);

        if ($paymentIntent->status !== 'succeeded') {
            throw new Exception("Payment failed or requires authentication");
        }

        // =========================
        // GET USER
        // =========================
        $urow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));

        if (!$urow) {
            throw new Exception("User not found");
        }

        $current_balance = (int)$urow['credit_balance'];
        $updated_balance = $current_balance + $credits;

        // =========================
        // UPDATE USER
        // =========================
        mysqli_query($conn, "
            UPDATE hdb_users 
            SET credit_balance = $updated_balance
            WHERE id = $admin_id
        ");

        // =========================
        // SAVE TRANSACTION
        // =========================
        mysqli_query($conn, "
            INSERT INTO hdb_transaction_history 
            (admin_id, plan, amount, credits, stripe_payment_intent_id) 
            VALUES 
            (
                '$admin_id',
                'paygo',
                '$amount',
                '$credits',
                '{$paymentIntent->id}'
            )
        ");

        echo json_encode([
            'success' => true,
            'message' => 'Payment successful',
            'credits_added' => $credits,
            'new_balance' => $updated_balance
        ]);

        exit;
    }

    // =========================================================
    // ================= SUBSCRIPTION ===========================
    // =========================================================

    $plan = $data['plan'] ?? '';

    // =========================
    // PLAN CONFIG
    // =========================
    $planConfig = [
        'personal' => [
            'name' => 'Personal Plan',
            'amount' => 3000, // $30
            'credits' => 30
        ],
        'agency' => [
            'name' => 'Agency Plan',
            'amount' => 19900, // $199
            'credits' => 200
        ]
    ];

    if (!isset($planConfig[$plan])) {
        throw new Exception("Invalid plan");
    }

    $planData = $planConfig[$plan];

    // =========================
    // CREATE CUSTOMER
    // =========================
    $customer = \Stripe\Customer::create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'payment_method' => $payment_method,
        'invoice_settings' => [
            'default_payment_method' => $payment_method
        ]
    ]);

    // =========================
    // FIND OR CREATE PRODUCT
    // =========================
    $products = \Stripe\Product::search([
        'query' => "metadata['plan_key']:'$plan'"
    ]);

    $product = count($products->data) > 0 
        ? $products->data[0] 
        : \Stripe\Product::create([
            'name' => $planData['name'],
            'metadata' => ['plan_key' => $plan]
        ]);

    // =========================
    // FIND OR CREATE PRICE
    // =========================
    $prices = \Stripe\Price::all([
        'product' => $product->id,
        'limit' => 10
    ]);

    $price = null;

    foreach ($prices->data as $p) {
        if (
            $p->unit_amount == $planData['amount'] &&
            $p->recurring &&
            $p->recurring->interval === 'month'
        ) {
            $price = $p;
            break;
        }
    }

    if (!$price) {
        $price = \Stripe\Price::create([
            'unit_amount' => $planData['amount'],
            'currency' => 'usd',
            'recurring' => ['interval' => 'month'],
            'product' => $product->id,
        ]);
    }

    // =========================
    // CREATE SUBSCRIPTION
    // =========================
    $subscription = \Stripe\Subscription::create([
        'customer' => $customer->id,
        'items' => [['price' => $price->id]],
        'expand' => ['latest_invoice.payment_intent'],
    ]);

    $payment_intent = $subscription->latest_invoice->payment_intent;

    if ($payment_intent && $payment_intent->status !== 'succeeded') {
        throw new Exception("Payment requires authentication");
    }

    // =========================
    // GET USER
    // =========================
    $urow = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT credit_balance FROM hdb_users WHERE id=$admin_id LIMIT 1"));

    if (!$urow) {
        throw new Exception("User not found");
    }

    $current_balance = (int)$urow['credit_balance'];
    $new_credits = $planData['credits'];
    $updated_balance = $current_balance + $new_credits;

    // =========================
    // UPDATE USER
    // =========================
    mysqli_query($conn, "
        UPDATE hdb_users 
        SET credit_balance = $updated_balance,
            plan_type = '$plan'
        WHERE id = $admin_id
    ");

    // =========================
    // SAVE TRANSACTION
    // =========================
    $amount = $planData['amount'] / 100;

    mysqli_query($conn, "
        INSERT INTO hdb_transaction_history 
        (admin_id, plan, amount, credits, stripe_customer_id, stripe_subscription_id) 
        VALUES 
        (
            '$admin_id',
            '$plan',
            '$amount',
            '$new_credits',
            '{$customer->id}',
            '{$subscription->id}'
        )
    ");

    echo json_encode([
        'success' => true,
        'message' => 'Subscription successful',
        'credits_added' => $new_credits,
        'new_balance' => $updated_balance
    ]);

} catch (\Stripe\Exception\CardException $e) {

    echo json_encode([
        'success' => false,
        'error' => $e->getError()->message
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}