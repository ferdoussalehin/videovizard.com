<?php
/**
 * VideoVizard General Functions
 * Credit balance management and generation charge calculations
 */

require_once __DIR__ . '/config.php';
// config.php must provide a mysqli connection as $conn
// Note: safe to call require_once multiple times — PHP deduplicates automatically


// ─────────────────────────────────────────────
// FUNCTION 1 : get_credit_balance
// ─────────────────────────────────────────────
/**
 * Retrieve the current credit balance for an admin user.
 *
 * @param  int        $admin_id   ID of the user in hdb_users
 * @return float|null             Credit balance, or null if user not found
 */
function get_credit_balance(int $admin_id): ?float
{
    global $conn;

    $result = mysqli_query($conn, "SELECT credit_balance FROM hdb_users WHERE id = $admin_id LIMIT 1");
    $row    = mysqli_fetch_assoc($result);

    if (!$row) {
        return null;
    }

    return (float) $row['credit_balance'];
}


// ─────────────────────────────────────────────
// FUNCTION 2 : check_deduction_allowed
// ─────────────────────────────────────────────
/**
 * Check whether a given amount can be deducted from the user's balance.
 *
 * @param  int    $admin_id   ID of the user in hdb_users
 * @param  float  $amount     Amount to be deducted
 * @return string             'valid' if deduction is possible, 'invalid' otherwise
 */
function check_deduction_allowed(int $admin_id, float $amount): string
{
    $balance = get_credit_balance($admin_id);

    if ($balance === null) {
        return 'invalid';
    }

    return ($balance >= $amount) ? 'valid' : 'invalid';
}


// ─────────────────────────────────────────────
// FUNCTION 3 : resolve_billing_user
// ─────────────────────────────────────────────
/**
 * Resolve the user who should be billed.
 * Team Members are billed via their Team Lead's balance.
 *
 * @param  int  $admin_id   The logged-in user's ID
 * @return int              The ID of the user whose credit_balance should be deducted
 */
function resolve_billing_user(int $admin_id): int
{
    global $conn;

    $result = mysqli_query($conn, "SELECT role, team_lead_id FROM hdb_users WHERE id = $admin_id LIMIT 1");
    $row    = mysqli_fetch_assoc($result);

    if ($row && $row['role'] === 'Team Member' && (int)$row['team_lead_id'] > 0) {
        return (int) $row['team_lead_id'];
    }

    return $admin_id;
}


// ─────────────────────────────────────────────
// FUNCTION 4 : deduct_credit_balance
// ─────────────────────────────────────────────
/**
 * Deduct amount from user credit balance, update company credit_used,
 * and optionally update podcast credit_used.
 * Team Members are automatically billed via their Team Lead.
 *
 * @param  int        $admin_id    ID of the logged-in user in hdb_users
 * @param  int        $company_id  ID of the company in hdb_companies
 * @param  float      $amount      Amount to deduct
 * @param  int        $podcast_id  ID of the podcast in hdb_podcasts (0 = no podcast)
 * @return float|null              New credit balance after deduction, or null on failure
 * @throws RuntimeException        If deduction is not allowed
 */
function deduct_credit_balance(int $admin_id, int $company_id, float $amount, int $podcast_id): ?float
{
    global $conn;

    // Resolve billing user (team members bill to team lead)
    $billing_id = resolve_billing_user($admin_id);

    if (check_deduction_allowed($billing_id, $amount) !== 'valid') {
        throw new RuntimeException("Deduction of $amount not allowed for admin_id $billing_id (insufficient balance or user not found).");
    }

    // 1. Deduct from hdb_users.credit_balance
    mysqli_query($conn, "UPDATE hdb_users SET credit_balance = credit_balance - $amount WHERE id = $billing_id");

    // 2. Add to hdb_companies.credit_used
    mysqli_query($conn, "UPDATE hdb_companies SET credit_used = credit_used + $amount WHERE id = $company_id");

    // 3. Add to hdb_podcasts.credit_used (only when a valid podcast is given)
    if ($podcast_id > 0) {
        mysqli_query($conn, "UPDATE hdb_podcasts SET credit_used = credit_used + $amount WHERE id = $podcast_id");
    }

    return get_credit_balance($billing_id);
}


// ─────────────────────────────────────────────
// FUNCTION 5 : calculate_generation_charges
// ─────────────────────────────────────────────
/**
 * Calculate generation charges based on mode, media type, and duration.
 * Rates are read from hdb_video_generation_rates (single-row config table).
 *
 * @param  string $gen_mode    Generation mode: 'fal_ai' or 'modal'
 * @param  string $media_type  Media type:      'video' or 'image'
 * @param  float  $duration    Duration in seconds to multiply against the rate
 * @return int                 Calculated charge as an integer (rounded up)
 * @throws RuntimeException         If no rate row found
 * @throws InvalidArgumentException For unsupported gen_mode / media_type combos
 */
function calculate_generation_charges(string $gen_mode, string $media_type, float $duration): int
{
    global $conn;

    $result = mysqli_query($conn, "SELECT video_fal_rate_per_sec, video_modal_rate_per_sec, image_fal_rate_per_sec, image_modal_rate_per_sec FROM hdb_video_generation_rates LIMIT 1");
    $rates  = mysqli_fetch_assoc($result);

    if (!$rates) {
        throw new RuntimeException("No rate configuration found in hdb_video_generation_rates.");
    }

    if ($media_type === 'video' && $gen_mode === 'fal_ai') {
        $rate = (float) $rates['video_fal_rate_per_sec'];
    } elseif ($media_type === 'video' && $gen_mode === 'modal') {
        $rate = (float) $rates['video_modal_rate_per_sec'];
    } elseif ($media_type === 'image' && $gen_mode === 'fal_ai') {
        $rate = (float) $rates['image_fal_rate_per_sec'];
    } elseif ($media_type === 'image' && $gen_mode === 'modal') {
        $rate = (float) $rates['image_modal_rate_per_sec'];
    } else {
        throw new InvalidArgumentException("Unsupported combination: gen_mode='$gen_mode', media_type='$media_type'.");
    }

    return (int) ceil($duration * $rate);
}


// ─────────────────────────────────────────────
// FUNCTION 6 : get_generation_que_time
// ─────────────────────────────────────────────
/**
 * Estimate the queue wait time in minutes for a given generation mode.
 *
 * modal  : counts queued rows (videogen_flag = 1) × 5 minutes + 3 minutes cold-start
 * fal_ai : counts queued rows (videogen_flag = 1) × 2 minutes, no cold-start
 *
 * Examples:
 *   modal  — 5 rows → (5 × 5) + 3 = 28 minutes
 *   fal_ai — 5 rows → (5 × 2)     = 10 minutes
 *
 * @param  string $gen_mode   'modal' or 'fal_ai'
 * @return int                Estimated wait time in minutes (minimum 0)
 * @throws InvalidArgumentException For unsupported gen_mode values
 */
function get_generation_que_time(string $gen_mode): int
{
    global $conn;

    if ($gen_mode === 'modal') {
        $minutes_per_row = 5;
        $cold_start      = 3;
    } elseif ($gen_mode === 'fal_ai') {
        $minutes_per_row = 2;
        $cold_start      = 0;
    } else {
        throw new InvalidArgumentException("Unsupported gen_mode '$gen_mode'. Use 'modal' or 'fal_ai'.");
    }

    $result    = mysqli_query($conn, "SELECT COUNT(*) as row_count FROM hdb_video_gen_que WHERE gen_mode = '$gen_mode' AND videogen_flag = 1");
    $row       = mysqli_fetch_assoc($result);
    $row_count = $row ? (int) $row['row_count'] : 0;

    return ($row_count * $minutes_per_row) + $cold_start;
}
