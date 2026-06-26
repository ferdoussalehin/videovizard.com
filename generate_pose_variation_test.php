<?php
/**
 * generate_pose_variations.php
 *
 * Standalone script: takes ONE reference/hero face image and generates
 * 8 pose variations of the SAME character using fal.ai's character
 * consistency model (ideogram/character).
 *
 * This is a TEST BATCH config for validating model output before running
 * across multiple ethnicity/market sets (Pakistani, Indian, Chinese,
 * Canadian, European, African). Only $modelDescriptor needs to change
 * per market -- pose list and pipeline stay identical so results are
 * comparable across markets.
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
    if (isset($falApiKey)) {
        define('FAL_API_KEY', $falApiKey);
    } elseif (isset($fal_api_key)) {
        define('FAL_API_KEY', $fal_api_key);
    } else {
        die("FAL API key not found. Set \$falApiKey in config.php.\n");
    }
}

// Confirm this against fal.ai docs/dashboard before running
define('FAL_MODEL_ENDPOINT', 'fal-ai/ideogram/character');

// Reference/hero shot of the face you want preserved across poses
// (can be a public URL or local path that you upload first)
$referenceImageUrl = isset($_GET['ref']) ? $_GET['ref'] : 'https://videovizard.com/promo_models/thumbnails/pakistani_female_23.jpg';

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
// MODEL / MARKET CONFIG -- swap this block per ethnicity test batch
// (Pakistani / Indian / Chinese / Canadian / European / African, etc.)
// Keeping it isolated here means the pose list below never has to change.
// ---------------------------------------------------------------------

// Who the AI should render -- age + ethnicity descriptor only, no named/real person
$modelDescriptor = '25 year old Pakistani woman, natural realistic facial features';

// What they're wearing -- kept identical across markets so garment fit is the variable being tested
$clothingDescriptor = 'wearing a plain white fitted t-shirt and black tight leggings/pants';

// Background + lighting -- flat product-shot style, no cast shadow on floor or wall
$backgroundDescriptor = 'pure white seamless background, completely shadowless, flat even studio lighting, no visible shadow on floor or background, photorealistic, e-commerce fashion catalog style';

// ---------------------------------------------------------------------
// POSE LIBRARY -- 8 distinct full-body poses, same character each time
// ---------------------------------------------------------------------

$posePrompts = array(
    array(
        'pose_code' => 'FRONT_01',
        'prompt'    => 'standing straight facing camera directly, front view, arms relaxed at sides, neutral expression, full body visible, feet shoulder width apart',
    ),
    array(
        'pose_code' => 'BACK_02',
        'prompt'    => 'standing straight with back fully turned to camera, rear view, arms relaxed at sides, full body visible',
    ),
    array(
        'pose_code' => 'SEMI_LEFT_03',
        'prompt'    => 'standing pose at a semi/three-quarter angle turned toward the left, face turned slightly toward camera, full body visible',
    ),
    array(
        'pose_code' => 'SEMI_RIGHT_04',
        'prompt'    => 'standing pose at a semi/three-quarter angle turned toward the right, face turned slightly toward camera, full body visible',
    ),
    array(
        'pose_code' => 'LEFT_SIDE_05',
        'prompt'    => 'standing full left side profile view, body turned 90 degrees, looking straight ahead, full body visible',
    ),
    array(
        'pose_code' => 'RIGHT_SIDE_06',
        'prompt'    => 'standing full right side profile view, body turned 90 degrees, looking straight ahead, full body visible',
    ),
    array(
        'pose_code' => 'STAIRS_DOWN_07',
        'prompt'    => 'walking down a few plain white studio steps/stairs, mid-step, natural balance and arm movement, full body visible',
    ),
    array(
        'pose_code' => 'WALK_08',
        'prompt'    => 'walking pose, mid-stride toward camera, natural arm swing, candid catalog style, full body visible',
    ),
);

// Shared fragment appended to every pose -- combines model + clothing + background
$sharedSuffix = ', ' . $modelDescriptor . ', ' . $clothingDescriptor . ', ' . $backgroundDescriptor;

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

function falPollResult($statusUrl, $responseUrl, $maxAttempts = 30, $sleepSeconds = 2) {
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
            // status_url only confirms completion -- the actual output
            // (images etc.) lives at a separate response_url. Fetch it now.
            $ch2 = curl_init($responseUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, array(
                'Authorization: Key ' . FAL_API_KEY,
            ));
            $resultResponse = curl_exec($ch2);
            curl_close($ch2);

            $resultData = json_decode($resultResponse, true);

            if ($resultData === null) {
                wiz_log("[falPollResult] response_url returned non-JSON or empty body: " . substr($resultResponse, 0, 500));
                return null;
            }

            // Wrap it as 'payload' so the rest of the script's existing
            // ['payload']['images'][0] lookup keeps working unchanged.
            return array('payload' => $resultData);
        } elseif (isset($data['status']) && $data['status'] === 'FAILED') {
            wiz_log("[falPollResult] Generation FAILED: " . $response);
            return null;
        } elseif (!isset($data['status'])) {
            wiz_log("[falPollResult] Unexpected status response (no 'status' key): " . substr($response, 0, 500));
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

    if (!isset($submitResponse['response_url'])) {
        wiz_log("[$poseCode] WARNING: no response_url in submit response, full body: " . json_encode($submitResponse));
    }

    $finalResult = falPollResult($submitResponse['status_url'], $submitResponse['response_url']);

    if (!$finalResult || !isset($finalResult['payload'])) {
        wiz_log("[$poseCode] No final payload returned, skipping. Raw finalResult: " . json_encode($finalResult));
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
