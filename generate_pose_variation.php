<?php
/**
 * generate_pose_variations.php
 *
 * Standalone script: takes ONE reference/hero face image and generates
 * 10+ pose variations of the SAME character using fal.ai's character
 * consistency model (ideogram/character).
 *
 * Usage (CLI):
 *   php generate_pose_variations.php
 *
 * Usage (web, optional):
 *   /generate_pose_variations.php?ref=https://yourcdn.com/hero_shot.jpg
 *
 * Requires: config.php with $apiKey or FAL_API_KEY defined (your fal.ai key)
 *
 * NOTE: Verify the exact fal.ai model endpoint string against your fal.ai
 * dashboard / docs before running -- model path strings occasionally change.
 * As of your last integration this was something like:
 *   fal-ai/ideogram/character  or  fal-ai/ideogram/character-edit
 * Update FAL_MODEL_ENDPOINT below to match what's live on your account.
 */

ob_start(); // prevent stray output corrupting JSON, per your existing convention

require_once 'config.php'; // expects $apiKey / FAL_API_KEY, optionally $conn

// ---------------------------------------------------------------------
// CONFIG
// ---------------------------------------------------------------------

// fal.ai key -- adjust variable name to match your config.php pattern
if (!defined('FAL_API_KEY')) {
    if (isset($fal_api_key)) {
        define('FAL_API_KEY', $fal_api_key);
    } elseif (isset($apiKey)) {
        define('FAL_API_KEY', $apiKey); // fallback if you reuse one key var
    } else {
        die("FAL API key not found. Set \$fal_api_key in config.php.\n");
    }
}

// Confirm this against fal.ai docs/dashboard before running
define('FAL_MODEL_ENDPOINT', 'fal-ai/ideogram/character');

// Reference/hero shot of the face you want preserved across poses
// (can be a public URL or local path that you upload first)
$referenceImageUrl = isset($_GET['ref']) ? $_GET['ref'] : 'https://yourcdn.com/path/to/hero_shot.jpg';

if ($referenceImageUrl === 'https://yourcdn.com/path/to/hero_shot.jpg') {
    die("Set \$referenceImageUrl to a real hosted image URL before running (or pass ?ref=...).\n");
}

// Where generated images get saved locally
$outputDir = __DIR__ . '/generated_poses/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Optional: log to your existing logging function if it exists, else fallback
if (!function_exists('wiz_log')) {
    function wiz_log($msg) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        file_put_contents(__DIR__ . '/a_errors.log', $line, FILE_APPEND);
        echo $line;
    }
}

// ---------------------------------------------------------------------
// POSE LIBRARY -- 10 distinct full-body poses, same character each time
// ---------------------------------------------------------------------

$posePrompts = array(
    array(
        'pose_code' => 'STAND_HANDPOCKET_01',
        'prompt'    => 'standing pose, weight shifted to one side, one hand relaxed in pocket, other arm relaxed at side, three-quarter angle toward camera, neutral expression, full body visible',
    ),
    array(
        'pose_code' => 'STAND_FRONT_02',
        'prompt'    => 'standing straight facing camera directly, arms relaxed at sides, neutral expression, full body visible, feet shoulder width apart',
    ),
    array(
        'pose_code' => 'STAND_CROSSARM_03',
        'prompt'    => 'standing pose, arms crossed confidently, slight smile, three-quarter angle, full body visible',
    ),
    array(
        'pose_code' => 'WALK_TOWARD_04',
        'prompt'    => 'walking pose, mid-stride toward camera, natural arm swing, candid catalog style, full body visible',
    ),
    array(
        'pose_code' => 'STAND_HIPHAND_05',
        'prompt'    => 'standing pose, one hand on hip, other arm relaxed, slight head tilt, fashion catalog style, full body visible',
    ),
    array(
        'pose_code' => 'SIDE_PROFILE_06',
        'prompt'    => 'standing side profile pose, looking toward camera over shoulder, relaxed posture, full body visible',
    ),
    array(
        'pose_code' => 'SIT_CASUAL_07',
        'prompt'    => 'seated casual pose on a stool or ledge, relaxed posture, hands resting on lap, full body visible',
    ),
    array(
        'pose_code' => 'LEAN_WALL_08',
        'prompt'    => 'standing pose leaning slightly against a wall, arms relaxed, casual confident expression, full body visible',
    ),
    array(
        'pose_code' => 'STAND_BOTHHANDS_POCKET_09',
        'prompt'    => 'standing pose, both hands in pockets, shoulders relaxed, facing camera, full body visible',
    ),
    array(
        'pose_code' => 'STAND_ARM_RAISED_10',
        'prompt'    => 'standing pose, one arm raised slightly adjusting hair or sleeve, candid fashion catalog style, full body visible',
    ),
);

// Shared fragment appended to every pose for consistent background/lighting
$sharedSuffix = ', plain white seamless studio background, soft even diffused lighting, no harsh shadows, photorealistic, e-commerce catalog style';

// ---------------------------------------------------------------------
// CORE: fal.ai queue submit + poll helpers
// ---------------------------------------------------------------------

function falSubmit($endpoint, $payload, &$errorMsg = null) {
    $url = 'https://queue.fal.run/' . $endpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Key ' . FAL_API_KEY,
        'Content-Type: application/json',
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $errorMsg = "cURL error: " . $curlErr;
        wiz_log("[falSubmit] " . $errorMsg);
        return null;
    }

    if ($httpCode >= 400) {
        $errorMsg = "HTTP $httpCode: " . $response;
        wiz_log("[falSubmit] " . $errorMsg);
        return null;
    }

    return json_decode($response, true);
}

function falPollResult($statusUrl, $maxAttempts = 30, $sleepSeconds = 2) {
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        $ch = curl_init($statusUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Key ' . FAL_API_KEY,
        ));
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['status']) && $data['status'] === 'COMPLETED') {
            return $data;
        } elseif (isset($data['status']) && $data['status'] === 'FAILED') {
            wiz_log("[falPollResult] Generation FAILED: " . $response);
            return null;
        }

        $attempt++;
        sleep($sleepSeconds);
    }

    wiz_log("[falPollResult] Timed out waiting for result at $statusUrl");
    return null;
}

/**
 * Handles both CDN URL responses and base64 data URI responses --
 * same bug class you fixed in vizard_ai_tools.php (sync_mode returning
 * data URIs instead of hosted URLs).
 */
function resolveImageOutput($imageField, $outputDir, $poseCode) {
    if (empty($imageField)) {
        return array('local_path' => null, 'fal_url' => null);
    }

    // fal.ai usually returns an object with a "url" key
    $rawValue = is_array($imageField) ? (isset($imageField['url']) ? $imageField['url'] : null) : $imageField;

    if (empty($rawValue)) {
        return array('local_path' => null, 'fal_url' => null);
    }

    if (strpos($rawValue, 'data:image') === 0) {
        // base64 data URI -- decode and save locally, no CDN url available
        $parts = explode(',', $rawValue, 2);
        if (count($parts) !== 2) {
            wiz_log("[resolveImageOutput] Malformed data URI for $poseCode");
            return array('local_path' => null, 'fal_url' => null);
        }

        $binary = base64_decode($parts[1]);
        $localPath = $outputDir . $poseCode . '.png';
        file_put_contents($localPath, $binary);

        return array('local_path' => $localPath, 'fal_url' => null);
    } else {
        // standard hosted URL -- download a local copy too
        $binary = file_get_contents($rawValue);
        $localPath = $outputDir . $poseCode . '.png';
        if ($binary !== false) {
            file_put_contents($localPath, $binary);
        }

        return array('local_path' => $localPath, 'fal_url' => $rawValue);
    }
}

// ---------------------------------------------------------------------
// MAIN LOOP
// ---------------------------------------------------------------------

$results = array();

wiz_log("=== Starting pose generation batch for reference: $referenceImageUrl ===");

foreach ($posePrompts as $poseDef) {
    $poseCode = $poseDef['pose_code'];
    $fullPrompt = $poseDef['prompt'] . $sharedSuffix;

    wiz_log("[$poseCode] Submitting generation request...");

    $payload = array(
        // fal.ai's ideogram/character endpoint expects an ARRAY of urls,
        // even though we only ever pass one. Sending a plain string here
        // (as the previous version did) causes a silent validation failure
        // on fal.ai's side -- this was the actual bug behind the all-failed batch.
        'reference_image_urls' => array($referenceImageUrl),
        'prompt'                => $fullPrompt,
        // sync_mode left false intentionally -- we WANT a hosted CDN url back,
        // not base64, per your earlier fix. resolveImageOutput() still
        // handles base64 as a fallback in case the model ignores this.
        'sync_mode'              => false,
    );

    $submitResponse = falSubmit(FAL_MODEL_ENDPOINT, $payload, $submitError);

    if (!$submitResponse || !isset($submitResponse['status_url'])) {
        wiz_log("[$poseCode] Submit failed or missing status_url, skipping.");
        $results[$poseCode] = array(
            'success' => false,
            'error'   => $submitError ? $submitError : 'No status_url in response: ' . json_encode($submitResponse),
        );
        continue;
    }

    $finalResult = falPollResult($submitResponse['status_url']);

    if (!$finalResult || !isset($finalResult['payload'])) {
        wiz_log("[$poseCode] No final payload returned, skipping.");
        $results[$poseCode] = array('success' => false);
        continue;
    }

    // Adjust this key path based on actual fal.ai response shape --
    // commonly $finalResult['payload']['images'][0] or ['image']
    $imageField = null;
    if (isset($finalResult['payload']['images'][0])) {
        $imageField = $finalResult['payload']['images'][0];
    } elseif (isset($finalResult['payload']['image'])) {
        $imageField = $finalResult['payload']['image'];
    }

    $resolved = resolveImageOutput($imageField, $outputDir, $poseCode);

    $results[$poseCode] = array(
        'success'    => $resolved['local_path'] ? true : false,
        'local_path' => $resolved['local_path'],
        'fal_url'    => $resolved['fal_url'],
    );

    wiz_log("[$poseCode] Done. local_path=" . $resolved['local_path'] . " fal_url=" . $resolved['fal_url']);

    // Optional: persist to DB if $conn is available (uncomment + adjust table/columns)
    /*
    if (isset($conn)) {
        $stmt = $conn->prepare("INSERT INTO mdl_generated_poses (pose_code, reference_image, local_path, fal_url, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('ssss', $poseCode, $referenceImageUrl, $resolved['local_path'], $resolved['fal_url']);
        $stmt->execute();
    }
    */
}

wiz_log("=== Batch complete ===");

ob_end_clean();

header('Content-Type: application/json');
echo json_encode(array(
    'reference_image' => $referenceImageUrl,
    'results'          => $results,
), JSON_PRETTY_PRINT);
