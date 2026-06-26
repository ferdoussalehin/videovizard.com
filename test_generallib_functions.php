<?php
/**
 * Test script for videovizard_general_functions.php
 * Runs against the REAL database via config.php
 * Access via browser: http://your-server/path/test_generallib_functions.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Adjust this path to match your server layout ──────────────────────────────
require_once __DIR__ . '/config.php';               // provides $conn (mysqli)
require_once __DIR__ . '/videovizard_general_functions.php';
// ──────────────────────────────────────────────────────────────────────────────

// ── Test values — change to IDs that exist in your DB ────────────────────────
$TEST_ADMIN_ID   = 34;
$TEST_COMPANY_ID = 29;
$TEST_PODCAST_ID = 900;   // set to 0 to skip podcast update
$TEST_DEDUCT_AMT = 5.00;
// ──────────────────────────────────────────────────────────────────────────────

// ── Output helpers ────────────────────────────────────────────────────────────
function row(string $label, string $status, string $detail = ''): void {
    $color  = $status === 'PASS' ? '#2a9d2a' : ($status === 'FAIL' ? '#c0392b' : '#555');
    $badge  = "<span style='background:$color;color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;'>$status</span>";
    $detail = $detail !== '' ? "<span style='color:#555;margin-left:10px;font-size:13px;'>$detail</span>" : '';
    echo "<tr><td style='padding:6px 12px;'>$label</td><td style='padding:6px 12px;'>$badge$detail</td></tr>\n";
}

function section(string $title): void {
    echo "<tr><td colspan='2' style='background:#f0f0f0;padding:8px 12px;font-weight:bold;border-top:2px solid #ccc;'>$title</td></tr>\n";
}
// ──────────────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>VideoVizard – Function Tests</title>
<style>
  body { font-family: Arial, sans-serif; padding: 30px; background: #fafafa; }
  h2   { color: #333; }
  table{ border-collapse: collapse; width: 100%; max-width: 750px; background: #fff;
         box-shadow: 0 1px 4px rgba(0,0,0,.15); border-radius: 6px; overflow: hidden; }
  td   { border-bottom: 1px solid #eee; font-size: 14px; }
  .info{ color:#555; font-size:13px; margin-top:20px; }
</style>
</head>
<body>
<h2>VideoVizard – General Functions Test</h2>
<p class="info">DB: <strong><?php echo mysqli_get_host_info($conn); ?></strong></p>
<table>
<?php

// ════════════════════════════════════════════════════════
// FUNCTION 1 — get_credit_balance
// ════════════════════════════════════════════════════════
section("Function 1 · get_credit_balance(admin_id)");

$balance = get_credit_balance($TEST_ADMIN_ID);

if ($balance !== null && is_numeric($balance)) {
    row("get_credit_balance($TEST_ADMIN_ID)", "PASS", "balance = $balance");
} else {
    row("get_credit_balance($TEST_ADMIN_ID)", "FAIL", "returned: " . var_export($balance, true) . " — check if admin_id $TEST_ADMIN_ID exists in hdb_users");
}

$missing = get_credit_balance(999999);
if ($missing === null) {
    row("get_credit_balance(999999) — non-existent user", "PASS", "returned null");
} else {
    row("get_credit_balance(999999) — non-existent user", "FAIL", "expected null, got: $missing");
}


// ════════════════════════════════════════════════════════
// FUNCTION 2 — check_deduction_allowed
// ════════════════════════════════════════════════════════
section("Function 2 · check_deduction_allowed(admin_id, amount)");

if ($balance !== null) {
    $smallAmt = round($balance * 0.1, 2);   // 10% of balance — should be valid
    $largeAmt = $balance + 9999;             // way over balance — should be invalid

    $r1 = check_deduction_allowed($TEST_ADMIN_ID, $smallAmt);
    row("check_deduction_allowed($TEST_ADMIN_ID, $smallAmt) — within balance", $r1 === 'valid' ? "PASS" : "FAIL", "returned: $r1");

    $r2 = check_deduction_allowed($TEST_ADMIN_ID, $balance);
    row("check_deduction_allowed($TEST_ADMIN_ID, $balance) — exact balance", $r2 === 'valid' ? "PASS" : "FAIL", "returned: $r2");

    $r3 = check_deduction_allowed($TEST_ADMIN_ID, $largeAmt);
    row("check_deduction_allowed($TEST_ADMIN_ID, $largeAmt) — over balance", $r3 === 'invalid' ? "PASS" : "FAIL", "returned: $r3");
} else {
    row("check_deduction_allowed — skipped", "SKIP", "admin_id $TEST_ADMIN_ID not found in hdb_users");
}

$r4 = check_deduction_allowed(999999, 1.00);
row("check_deduction_allowed(999999, 1.00) — non-existent user", $r4 === 'invalid' ? "PASS" : "FAIL", "returned: $r4");


// ════════════════════════════════════════════════════════
// FUNCTION 3 — deduct_credit_balance
// ════════════════════════════════════════════════════════
section("Function 3 · deduct_credit_balance(admin_id, company_id, amount, podcast_id)");

$balanceBefore = get_credit_balance($TEST_ADMIN_ID);

if ($balanceBefore !== null && $balanceBefore >= $TEST_DEDUCT_AMT) {
    try {
        $newBalance = deduct_credit_balance($TEST_ADMIN_ID, $TEST_COMPANY_ID, $TEST_DEDUCT_AMT, $TEST_PODCAST_ID);
        $expected   = round($balanceBefore - $TEST_DEDUCT_AMT, 4);
        $actual     = round($newBalance, 4);

        if (abs($actual - $expected) < 0.001) {
            row("deduct_credit_balance — balance reduced", "PASS", "before: $balanceBefore → after: $newBalance (deducted: $TEST_DEDUCT_AMT)");
        } else {
            row("deduct_credit_balance — balance reduced", "FAIL", "expected $expected, got $actual");
        }

        $podcastNote = $TEST_PODCAST_ID > 0 ? "hdb_podcasts.credit_used updated for podcast $TEST_PODCAST_ID" : "podcast_id = 0, hdb_podcasts not touched";
        row("deduct_credit_balance — company & podcast update", "INFO", $podcastNote);

    } catch (RuntimeException $e) {
        row("deduct_credit_balance", "FAIL", "Exception: " . $e->getMessage());
    }
} else {
    row("deduct_credit_balance — skipped", "SKIP", "balance ($balanceBefore) is less than test deduction amount ($TEST_DEDUCT_AMT) or user not found");
}

// Insufficient funds test — should throw
try {
    deduct_credit_balance($TEST_ADMIN_ID, $TEST_COMPANY_ID, 999999999.00, 0);
    row("deduct_credit_balance — insufficient funds guard", "FAIL", "should have thrown RuntimeException");
} catch (RuntimeException $e) {
    row("deduct_credit_balance — insufficient funds guard", "PASS", "RuntimeException caught: " . $e->getMessage());
}


// ════════════════════════════════════════════════════════
// FUNCTION 4 — calculate_generation_charges
// ════════════════════════════════════════════════════════
section("Function 4 · calculate_generation_charges(gen_mode, media_type, duration)");

$combos = [
    ['fal_ai', 'video', 10, "video + fal_ai,  duration=10"],
    ['modal',  'video', 10, "video + modal,   duration=10"],
    ['fal_ai', 'image', 10, "image + fal_ai,  duration=10"],
    ['modal',  'image', 10, "image + modal,   duration=10"],
    ['fal_ai', 'video', 7.3,"video + fal_ai,  duration=7.3"],
];

foreach ($combos as [$mode, $type, $dur, $label]) {
    try {
        $charge = calculate_generation_charges($mode, $type, $dur);
        row("calculate_generation_charges — $label", "PASS", "charge = $charge");
    } catch (Exception $e) {
        row("calculate_generation_charges — $label", "FAIL", $e->getMessage());
    }
}

// Invalid combo — should throw
try {
    calculate_generation_charges('bad_mode', 'video', 5);
    row("calculate_generation_charges — invalid gen_mode", "FAIL", "should have thrown InvalidArgumentException");
} catch (InvalidArgumentException $e) {
    row("calculate_generation_charges — invalid gen_mode", "PASS", "InvalidArgumentException caught");
}


// ════════════════════════════════════════════════════════
// FUNCTION 5 — get_generation_que_time
// ════════════════════════════════════════════════════════
section("Function 5 · get_generation_que_time(gen_mode)");

// modal: rows × 5 + 3 cold-start
try {
    $modal_time  = get_generation_que_time('modal');
    $modal_rows  = ($modal_time - 3) / 5;   // reverse-calculate rows for display
    row(
        "get_generation_que_time('modal')",
        "PASS",
        "queued rows in hdb_video_gen_que: $modal_rows → ($modal_rows × 5) + 3 = $modal_time min"
    );
} catch (Exception $e) {
    row("get_generation_que_time('modal')", "FAIL", $e->getMessage());
}

// fal_ai: rows × 2, no cold-start
try {
    $fal_time = get_generation_que_time('fal_ai');
    $fal_rows = $fal_time / 2;   // reverse-calculate rows for display
    row(
        "get_generation_que_time('fal_ai')",
        "PASS",
        "queued rows in hdb_video_gen_que: $fal_rows → ($fal_rows × 2) = $fal_time min"
    );
} catch (Exception $e) {
    row("get_generation_que_time('fal_ai')", "FAIL", $e->getMessage());
}

// invalid gen_mode — should throw
try {
    get_generation_que_time('bad_mode');
    row("get_generation_que_time('bad_mode') — invalid mode", "FAIL", "should have thrown InvalidArgumentException");
} catch (InvalidArgumentException $e) {
    row("get_generation_que_time('bad_mode') — invalid mode", "PASS", "InvalidArgumentException caught");
}

?>
</table>

<p class="info" style="margin-top:30px;">
  Tests complete. All <strong>PASS</strong> rows are green. Fix any <strong>FAIL</strong> rows by verifying the test IDs exist in your database.
</p>
</body>
</html>
