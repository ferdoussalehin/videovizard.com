<?php
// ============================================================
// deduct_credit.php
// Include this file in any page that needs credit deduction.
//
// Provides:
//   deduct_user_credit($conn, $user_id, $amount, $description)
//   → ['success'=>bool, 'new_balance'=>float, 'message'=>string]
//
// Also handles ajax_action === 'deduct_credit' when POSTed.
// ============================================================

// ── Core function ─────────────────────────────────────────────────────────────
function deduct_user_credit($conn, $user_id, $amount, $description = '') {
    $user_id = (int)$user_id;
    $amount  = round((float)$amount, 4);

    if ($user_id <= 0) {
        return ['success' => false, 'message' => 'Invalid user_id', 'new_balance' => 0];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'Amount must be > 0', 'new_balance' => 0];
    }

    // Lock the row so concurrent requests don't double-deduct
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT credit_balance FROM hdb_users WHERE id=$user_id LIMIT 1 FOR UPDATE"
    ));

    if (!$row) {
        return ['success' => false, 'message' => 'User not found', 'new_balance' => 0];
    }

    $balance = (float)$row['credit_balance'];

    if ($balance < $amount) {
        return [
            'success'     => false,
            'message'     => 'Insufficient credits (balance=' . number_format($balance, 2) . ', required=' . number_format($amount, 2) . ')',
            'new_balance' => $balance,
        ];
    }

    $new_balance = round($balance - $amount, 4);
    $desc_esc    = mysqli_real_escape_string($conn, $description);
    $now         = date('Y-m-d H:i:s');

    $ok = mysqli_query($conn,
        "UPDATE hdb_users SET credit_balance=$new_balance WHERE id=$user_id"
    );

    if (!$ok) {
        return ['success' => false, 'message' => 'DB update failed: ' . mysqli_error($conn), 'new_balance' => $balance];
    }

    // Log to hdb_credit_log if the table exists
    $log_check = mysqli_query($conn, "SHOW TABLES LIKE 'hdb_credit_log'");
    if ($log_check && mysqli_num_rows($log_check) > 0) {
        mysqli_query($conn,
            "INSERT INTO hdb_credit_log (user_id, amount, direction, description, balance_after, created_at)
             VALUES ($user_id, $amount, 'debit', '$desc_esc', $new_balance, '$now')"
        );
    }

    return [
        'success'     => true,
        'new_balance' => $new_balance,
        'message'     => 'OK',
    ];
}

// ── AJAX handler — only fires when this file is included and action matches ───
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'deduct_credit') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');

    // $conn and $admin_id must already be set by the including page
    if (!isset($conn) || !isset($admin_id)) {
        echo json_encode(['success' => false, 'message' => 'Server config error: db not initialised']);
        exit;
    }

    $user_id     = (int)($_POST['user_id']     ?? $admin_id);
    $amount      = (float)($_POST['amount']    ?? 0);
    $description = trim($_POST['description']  ?? '');

    $result = deduct_user_credit($conn, $user_id, $amount, $description);
    echo json_encode($result);
    exit;
}
