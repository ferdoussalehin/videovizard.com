<?php
/**
 * vapi_call.php
 * Dummy VAPI endpoint — simulates a real outbound call.
 * Receives lead data, waits a random duration, returns outcome JSON.
 *
 * When your VAPI integration is ready, replace the sleep + random
 * logic below with a real cURL call to VAPI's API. Everything else stays.
 *
 * POST params:
 *   secret      — shared secret to prevent unauthorized calls
 *   lead_id     — int
 *   firstname   — string
 *   lastname    — string
 *   phone       — string (E.164 preferred)
 *   email       — string
 *   city        — string
 *   state       — string
 *   zip         — string
 *   campaign_id — int (for future routing to correct VAPI agent)
 *
 * Response JSON:
 *   { success, outcome, disposition, duration_minutes, notes }
 */

header('Content-Type: application/json');
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/a_errors.log');

// ── Secret check ──────────────────────────────────────────────
define('VAPI_SECRET', 'CM_Vapi_2026!');
if (($_POST['secret'] ?? '') !== VAPI_SECRET) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// ── Receive lead data ─────────────────────────────────────────
$lead_id     = (int)($_POST['lead_id']     ?? 0);
$firstname   = trim($_POST['firstname']    ?? '');
$lastname    = trim($_POST['lastname']     ?? '');
$phone       = trim($_POST['phone']        ?? '');
$email       = trim($_POST['email']        ?? '');
$city        = trim($_POST['city']         ?? '');
$state       = trim($_POST['state']        ?? '');
$zip         = trim($_POST['zip']          ?? '');
$campaign_id = (int)($_POST['campaign_id'] ?? 0);

if (!$lead_id || !$phone) {
    echo json_encode(['success' => false, 'error' => 'lead_id and phone are required']);
    exit;
}

// ── Log incoming call ─────────────────────────────────────────
error_log("[vapi_call] CALL START — lead_id=$lead_id | name=$firstname $lastname | phone=$phone | campaign=$campaign_id");

// ── Simulate call duration (3–7 seconds) ─────────────────────
$call_seconds = rand(3, 7);
sleep($call_seconds);
$duration_minutes = round($call_seconds / 60, 4);

// ── Random outcome (weighted) ─────────────────────────────────
// Weights reflect realistic outbound cold-call distributions
$outcomes = [
    ['outcome' => 'appointment',    'disposition' => 'answered', 'weight' => 8],
    ['outcome' => 'callback',       'disposition' => 'answered', 'weight' => 12],
    ['outcome' => 'not_interested', 'disposition' => 'answered', 'weight' => 18],
    ['outcome' => 'voicemail',      'disposition' => 'no_answer','weight' => 28],
    ['outcome' => 'retry',          'disposition' => 'no_answer','weight' => 24],
    ['outcome' => 'invalid',        'disposition' => 'failed',   'weight' => 7],
    ['outcome' => 'do_not_call',    'disposition' => 'failed',   'weight' => 3],
];

$total  = array_sum(array_column($outcomes, 'weight'));
$rand   = mt_rand(1, $total);
$picked = $outcomes[count($outcomes) - 1]; // fallback
$running = 0;
foreach ($outcomes as $o) {
    $running += $o['weight'];
    if ($rand <= $running) { $picked = $o; break; }
}

$outcome     = $picked['outcome'];
$disposition = $picked['disposition'];

// ── Notes per outcome ─────────────────────────────────────────
$notes_map = [
    'appointment'    => "Lead agreed to a meeting. Call duration: {$call_seconds}s.",
    'callback'       => "Lead asked to be called back. Duration: {$call_seconds}s.",
    'not_interested' => "Lead declined. Duration: {$call_seconds}s.",
    'voicemail'      => "Reached voicemail. Duration: {$call_seconds}s.",
    'retry'          => "No answer or busy. Will retry. Duration: {$call_seconds}s.",
    'invalid'        => "Number disconnected or wrong. Duration: {$call_seconds}s.",
    'do_not_call'    => "Lead requested DNC. Duration: {$call_seconds}s.",
];
$notes = $notes_map[$outcome] ?? "Call completed. Duration: {$call_seconds}s.";

// ── Log result ────────────────────────────────────────────────
error_log("[vapi_call] CALL END — lead_id=$lead_id | outcome=$outcome | duration={$call_seconds}s");

// ── Return response ───────────────────────────────────────────
echo json_encode([
    'success'          => true,
    'lead_id'          => $lead_id,
    'outcome'          => $outcome,
    'disposition'      => $disposition,
    'duration_minutes' => $duration_minutes,
    'call_seconds'     => $call_seconds,
    'notes'            => $notes,

    /*
     * ── REAL VAPI INTEGRATION (replace sleep+random above with this) ──────────
     *
     * $vapi_key = 'your-vapi-api-key';
     * $ch = curl_init('https://api.vapi.ai/call/phone');
     * curl_setopt_array($ch, [
     *     CURLOPT_POST           => true,
     *     CURLOPT_RETURNTRANSFER => true,
     *     CURLOPT_HTTPHEADER     => [
     *         'Authorization: Bearer ' . $vapi_key,
     *         'Content-Type: application/json',
     *     ],
     *     CURLOPT_POSTFIELDS => json_encode([
     *         'assistantId'  => 'your-vapi-assistant-id',
     *         'phoneNumberId'=> 'your-vapi-phone-number-id',
     *         'customer'     => [
     *             'number'   => $phone,
     *             'name'     => $firstname . ' ' . $lastname,
     *         ],
     *         'assistantOverrides' => [
     *             'variableValues' => [
     *                 'lead_firstname' => $firstname,
     *                 'lead_lastname'  => $lastname,
     *                 'lead_city'      => $city,
     *                 'lead_email'     => $email,
     *             ]
     *         ]
     *     ]),
     * ]);
     * $response   = curl_exec($ch);
     * $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
     * curl_close($ch);
     * $vapi_data  = json_decode($response, true);
     * // Then poll VAPI's call status endpoint until call completes,
     * // extract outcome from call analysis, return it here.
     * ─────────────────────────────────────────────────────────────────────────
     */
]);
